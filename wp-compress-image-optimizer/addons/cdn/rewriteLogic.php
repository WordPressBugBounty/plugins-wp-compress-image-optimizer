<?php

/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */
class wps_rewriteLogic
{

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
    public static $pictureWebpEnabled = false;
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
    public static $isAmp;

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
        self::$isAmp = new wps_ic_amp();

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

        self::$isAmp = new wps_ic_amp();

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
        $required = ['css', 'css_image_urls', 'css_minify', 'js', 'js_minify', 'emoji-remove', 'preserve_exit', 'fonts'];
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
        $current_url = wpc_request_scheme() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"; // proxy-aware scheme
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
        // Verified-gate on the primary emit surface. self::$zoneName feeds every rewritten CDN URL
        // (images, srcset, picture, fonts, CSS/JS, backgrounds), so it must honor the same fail-open
        // gate as the rest of the emit path: during a mid-change ('0') window the new cname is still
        // unprovisioned, so fall back to the working host. never-set/'legacy' => emit; '0' => suppress;
        // 1 => verified emit.
        $cfVerified = (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok());
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && $cfVerified) ? $cfCname : get_option('ic_custom_cname');
        if (empty($custom_cname) || !$custom_cname) {
            self::$zoneName = get_option('ic_cdn_zone_name');
        } else {
            self::$zoneName = $custom_cname;
        }

        // orch cdn_disabled master kill: blank the zone so every zone-keyed emitter bails on its
        // empty-zone guard (zero CDN URLs).
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            self::$zoneName = '';
        }
        // (v7.03.49) Zone host == ORIGIN host (misconfigured cname / a clone storing its own domain) →
        // every transform would resolve to the origin → 404. Treat as no-zone; the empty-zone guards
        // throughout this class then keep assets on their natural URLs. Mirrors cdn-rewrite mainInit.
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
        self::$defaultExcludedList = ['redditstatic', 'ai-uncode', 'gtm', 'instagram.com', 'fbcdn.net', 'twitter', 'google', 'coinbase', 'cookie', 'schema', 'recaptcha', 'data:image', 'stats.jpg'];

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
        $conditions = ['css_image_urls', 'css_minify', 'js_minify', 'preserve_exif', 'emoji-remove', 'css', 'js'];
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
        self::$cssMinify = self::$settings['css_minify'];
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
            if (!empty($_GET['custom_server'])) {
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
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

            // Define an array of mobile device keywords to check against
            $mobileKeywords = ['android', 'iphone', 'ipad', 'windows phone', 'blackberry', 'tablet', 'mobile'];

            // Check if the user agent contains any of the mobile device keywords
            foreach ($mobileKeywords as $keyword) {
                if (strpos($userAgent, $keyword) !== false) {
                    return true; // Found a match, so it's a mobile device
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

    public function removeEmoji()
    {
        if (!empty(self::$emojiRemove) && self::$emojiRemove == '1') {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
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

        // Local-Fonts cache (wp-cio-fonts): never subset/zoneify — must stay natural origin to match the
        // inline @font-face + preload (see cdn_rewrite_url font branch + rewrite_fontface_css:3838).
        if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
            return $url;
        }

        if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
            if (strpos($url, self::$zoneName) === false) {
                // PATH SCOPE: a same-site custom store path like /storage/ that the CDN 302s cross-origin
                // gets CORS-blocked (fonts have no onerror net). Leave such same-site, non-/wp-content/
                // fonts on the origin. Third-party fonts (different host) keep their subsetting behaviour.
                $f_host = wp_parse_url($url, PHP_URL_HOST);
                $f_site = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                if (!empty($f_host) && !empty($f_site) && strcasecmp((string) $f_host, (string) $f_site) === 0
                    && stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) {
                    return $url;
                }
                // EXTERNAL-ORIGIN fonts stay DIRECT (v7.03.47) — never route a third-party font through the
                // zone. Fonts require CORS (Access-Control-Allow-Origin); proxying them via m:0/a: risks a
                // CORS failure + 404s (the cdnjs Font Awesome case) for ~zero gain (the source is already a
                // CDN). Only same-site fonts are eligible (matches cdn_rewrite_url's /wp-content/ scope).
                if (empty($f_host) || empty($f_site) || strcasecmp((string) $f_host, (string) $f_site) !== 0) {
                    return $url;
                }
                if (strpos($url, '.woff') !== false || strpos($url, '.woff2') !== false || strpos($url, '.eot') !== false || strpos($url, '.ttf') !== false) {
                    // HOST GUARD (v7.03.47) — never build a transform on an empty/origin host. An empty zone
                    // yields "https:///m:0/a:…", which the browser resolves against the ORIGIN → 404 (the
                    // reported earthworkstreeservice.com/m:0/a:… case; also the suppressed-zone state). When
                    // the zone host isn't safely available, stay natural.
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

    /**
     * Format a URL for the `u:` param of a CDN transform URL, swapping its host to the cdn-zone when
     * it belongs to this site.
     *
     * Why: orch pods can't always reach the customer origin directly (WAF, geo CDN-block, security
     * rules). A `u:` with the cdn-zone host lets the CDN fetch via its own pull-zone — the only path
     * that reliably succeeds across all pods.
     *
     * Host swap is conservative: only when the cdn-zone is configured AND the URL's host matches
     * site_url's host. External and already-cdn-zone URLs pass through.
     *
     * DO NOT use for `/m:0/a:` (asset passthrough) or `/font:true` URLs — those resolve to files
     * inside the cdn-zone itself and would create a fetch loop. Use reformatUrl() for those.
     */
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
     * Meta-independent recovery for adaptive next-gen variant URLs. lazy_cdn writes the variant as
     * `<base>-{W}x{H}.{ext}` (the callback has $meta to resolve the height), but a front-end render may
     * lack $meta and degrade to `<base>-{W}w.{ext}` → the file_exists READ misses the real on-disk file
     * → the rewriter sticks on the `wp:N` trigger URL forever and the disk variant is never requested.
     * Recover the true filename by width-globbing the image dir (cached per base+ext per request) so a
     * meta-less render still emits the DIRECT URL.
     *
     * Additive + safe: only runs after the primary file_exists missed; on no match returns the original
     * URL/path so the caller falls through to the trigger URL. No-op if glob() is disabled on the host.
     *
     * @return array [resolved_url, resolved_abs_path]
     */
    /**
     * Build a natural "-WxH.<ext>" URL for a ladder width — but if the base is ALREADY a sized sub-image
     * (e.g. a hard-cropped Woo thumbnail ".../IMG_8568-300x300"), return its OWN "<base>.<ext>" instead
     * of appending a second -WxH. Appending would produce "IMG_8568-300x300-200x200.avif" which 404s
     * (the edge OTF-by-suffix can't make a sub-size-of-a-sub-size), and re-deriving -WxH from the FULL
     * image aspect would be the wrong crop for a hard-cropped thumb. The base's own "-WxH.<ext>" is
     * OTF-safe and correct. Also safe for legitimately -WxH-named originals (a hand-named
     * "photo-1920x1080" has no other valid -WxH variant). Callers still run recoverAdaptiveVariant()
     * + the never-404 -WxH-form gate on the result.
     */
    private static function natural_ladder_url($base_no_ext, $width, $aspect_meta, $ext)
    {
        if (preg_match('/-\d{1,5}x\d{1,5}$/', (string) $base_no_ext)) {
            return $base_no_ext . '.' . $ext; // already a sized sub-image → use its own name
        }
        $suffix = function_exists('wpc_v2_adaptive_variant_suffix')
            ? wpc_v2_adaptive_variant_suffix($width, $aspect_meta)
            : '-' . (int) $width . 'w';
        return $base_no_ext . $suffix . '.' . $ext;
    }

    // PUBLIC (v7.03.38): the single shared Source-Hints gate for ALL delivery modes. negotiated-delivery
    // (build_natural_url) and modern-delivery (build_srcset_for_format) call it too, so the "Source Hints"
    // toggle + the orch's per-zone emit_src_hints echo + the wpc_src_hint_enabled filter govern ?src
    // uniformly everywhere — not just the legacy <img> / <picture>-ladder paths in this file.
    public static function src_hint_enabled()
    {
        // Per-ZONE orch toggle, mirrored from emit_src_hints by the /v2/config sync. Resolved via
        // wpc_v2_zone_src_hints() (dual-key: primary PZ → CF-CNAME fallback, same as cdn_disabled).
        // NULL = the orch hasn't echoed for this zone → fall back to the legacy global option. The
        // wpc_src_hint_enabled filter (the Other-Optimizations "Source Hints" override) is applied last.
        // v7.02.50 — BAKED ON: the global baseline flipped OFF→ON. The ?src= hint is non-keying +
        // self-healing + best-effort (never a wrong hint) and lets the edge skip the slow origin
        // format-probe (the failure mode behind the mariannestein 302→origin incident).
        // v7.03.39 — fully baked ON in ALL modes: a per-zone orch emit_src_hints=FALSE no longer disables it.
        // That value is usually just the orch's unset default, and the silent OFF it produced is exactly what
        // caused the origin-probe / Fast-404 STORMS. ?src is always-safe, so ON is the safe default and OFF is
        // the dangerous state. We still honor an explicit orch TRUE (redundant under the baked-on baseline);
        // the UI "Source Hints" toggle (wpc_src_hint_enabled filter, applied last) remains the authoritative
        // opt-out, and the global wpc_src_hint_enabled option (default 1) is the fleet kill-switch.
        return self::src_hint_mode() !== 'off';
    }

    /**
     * (v7.03.61) 3-STATE source-hint mode — the single source of truth for ?src across ALL delivery paths.
     * The UI "Source Hints" checkbox (emit-src-hints) is the master on/off; "Always Emit Source Hints"
     * (emit-src-hints-always) flips until-landed → always. Reading the UI option HERE also fixes a real
     * disconnect: the old gate read wpc_src_hint_enabled, an option the UI never set, so the toggle did
     * nothing. Returns:
     *   'off'    — never emit ?src.
     *   'until'  — emit ?src only while the variant is NOT on disk (self-healing; on-disk arm stays clean). DEFAULT.
     *   'always' — emit ?src on every natural arm, even after the variant lands on disk.
     * Overrides: the wpc_src_hint_mode filter is authoritative; the legacy wpc_src_hint_enabled=>false filter
     * still forces OFF (fleet kill-switch). The per-zone orch echo is no longer consulted — v7.03.39 baked it
     * ON in all modes, making the UI toggle the authoritative control.
     */
    public static function src_hint_mode()
    {
        $set  = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $raw  = (is_array($set) && isset($set['emit-src-hints'])) ? (string) $set['emit-src-hints'] : '1';
        $on   = ($raw !== '0' && $raw !== '' && strtolower($raw) !== 'off');
        $mode = !$on ? 'off' : ((is_array($set) && !empty($set['emit-src-hints-always'])) ? 'always' : 'until');
        if (function_exists('apply_filters')) {
            $mode = (string) apply_filters('wpc_src_hint_mode', $mode);
            if (apply_filters('wpc_src_hint_enabled', true) === false) {
                $mode = 'off';
            }
        }
        return in_array($mode, array('off', 'until', 'always'), true) ? $mode : 'until';
    }

    /**
     * Optional transient "?src=<ext>" appended to a NOT-on-disk natural <source> URL, telling the CDN
     * the on-disk source format so it skips the slow-origin probe storm. Self-healing: once the variant
     * lands on disk the on-disk arm emits the CLEAN URL (no ?src=). Gated + best-effort: empty when the
     * toggle is OFF or the source format wasn't resolvable (never a wrong hint). The CDN treats `src` as
     * non-keying, so hinted + clean URLs share one cached object. Emitted ONLY on the not-on-disk natural
     * arms (the wp:2/wp:1 transforms already carry the source in u:).
     */
    private static function src_hint_qs($src_ext, $on_disk = false)
    {
        if ($src_ext === '') return '';
        $mode = self::src_hint_mode();
        if ($mode === 'off') return '';
        if ($mode === 'until' && $on_disk) return ''; // self-healing: variant landed on disk → emit the CLEAN URL
        return '?src=' . $src_ext;                     // 'until' (not-on-disk) or 'always'
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
        $base_name = basename($base_path); // stem only — the match MUST anchor to it.
        $key = $base_path . '|' . $ext;
        if (!isset(self::$variantGlobCache[$key])) {
            // Escape glob metacharacters in the literal base ([ ] ? * { } are legal in WP filenames);
            // the trailing "-*" is the intentional wildcard.
            $pattern = preg_replace('/([*?\[\]{}])/', '[$1]', $base_path) . '-*.' . $ext;
            $g = @glob($pattern);
            self::$variantGlobCache[$key] = is_array($g) ? $g : [];
        }
        // Anchor the suffix to the base STEM (^stem-...), not just end-anchored: glob "<base>-*" also
        // lists a different attachment's siblings (foo.jpg / foo-2.jpg in one dir), and "-{W}x{H}$"
        // would match foo-2-800x600 for base foo → wrong image. Prefer canonical -{W}x{H}; accept the
        // legacy -{W}w form only if no -{W}x{H} sibling exists.
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
            $formatted_url = explode('?', $formatted_url);
            $formatted_url = $formatted_url[0];
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
                $formatted_url .= '?icv=' . WPS_IC_HASH;
            }

            if (self::$randomHash == 0 && strpos($formatted_url, '.js') !== false) {
                $formatted_url .= '?js_icv=' . WPS_IC_JS_HASH;
            }
        }

        return $formatted_url;
    }

    /**
     * Config-authoritative Cloudflare-zone detection. Elsewhere CF is detected from the ORIGIN request
     * header (HTTP_CF_RAY/HTTP_CF_VISITOR) + the sticky wpc_v2_cf_assets_seen option — but a CF-direct
     * CNAME zone (emit host is Cloudflare-fronted while the WP origin is a plain non-orange host) never
     * carries a CF header on an origin render, so request-header detection is blind to it. That blind
     * spot let the slideshow / CSS-bg webp swap + the asset fast-path emit vary-blind natural URLs on a
     * CF edge with no fallback. So treat the zone as CF when a header is present OR cf_assets_seen OR the
     * CF integration is configured WITH cdn on AND a CF cname set.
     */
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

    /**
     * Read the durable CSS/JS asset-MIME proof. The positive verdict is a permanent option (a stable
     * per-zone fact, never expires); a per-site filter (wpc_natural_assets_on_cf) can force-allow.
     * Single source of truth for every reader.
     */
    private static function asset_mime_proven()
    {
        if ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') return true;
        return (bool) apply_filters('wpc_natural_assets_on_cf', false);
    }

    /**
     * Invalidate the durable CSS/JS asset-MIME proof so the next render re-verifies the live edge. Call
     * on events that can change the edge's MIME behavior: CF (re)connect/save/disconnect, plugin update,
     * the Re-check button, zone-config change/purge. The self-correction path for a CF zone that later
     * regresses; without it a once-proven zone would stay natural forever with no re-check. Clears the
     * verdict option + the 2h negative-retry transient + the in-flight lock.
     */
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
        // Durable proof: the verdict lives in a PERMANENT option, not a transient. Once a zone's emit
        // host is proven to serve text/css that's a stable per-zone fact, so it stays natural regardless
        // of traffic (an expiring transient made low-traffic sites flip natural<->origin). Re-checks are
        // event-driven (update/reconnect/Re-check/zone-config all delete the option) + the onerror->origin
        // self-heal. A negative verdict keeps a short 2h retry transient so an un-converged edge re-checks
        // soon.
        $verdict = get_option('wpc_v2_cf_asset_mime_ok', false);
        if ($verdict === '1') return true;                       // durable PROVEN → natural forever
        if (get_transient('wpc_v2_cf_asset_mime_retry') !== false) return false; // recent '0' → in retry cooldown

        // Probe context: admin/cron OR a cold live-CDN front-end render (instant proof) on a CF zone
        // (Bunny is proven-by-construction and short-circuits before here). Never probe on a warm verdict,
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
        // Probe the EMIT HOST, not the underlying pod. We emit against self::$zoneName, which on a
        // CF-direct CNAME zone is the proxied Cloudflare cname — a different host from ic_cdn_zone_name
        // (the non-CF Bunny pod). Probing the pod would certify the pod serves text/css while the browser
        // fetches the CF edge — a false-positive that could re-open the unstyled-page incident if CF
        // diverges from the pod. So derive the probe host from the emit chain: prefer self::$zoneName
        // (strip any custom-CNAME '/key:<apikey>' path → bare host), else CF cname / custom cname / pod.
        // On a non-CF Bunny zone self::$zoneName == ic_cdn_zone_name, so that lane is byte-identical.
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
        // Admin/cron run OFF any visitor render, so the ≤3s proof GET is free to run inline. The probe
        // itself lives in the shared global wpc_v2_asset_mime_probe_run() (v2-config-sync.php) so the
        // non-blocking loopback handler runs the IDENTICAL probe — one source of truth for the proof.
        if ($is_admin_cron) {
            if (function_exists('wpc_v2_asset_mime_probe_run')) {
                return wpc_v2_asset_mime_probe_run($probe_zone);
            }
            delete_transient('wpc_v2_asset_probe_inflight');
            return false;
        }
        // COLD FRONT-END visitor render: NEVER block the render with the 3s GET. Fire a non-blocking
        // loopback (the same fire-and-forget transport the CDN-liveness probe uses) so the admin-ajax
        // handler does the GET off the render thread and writes the durable verdict. THIS render serves
        // the safe transform floor; the next render picks up the proven '1'. The handler clears the
        // in-flight lock (or it expires in 15s and admin/cron / the bg-callback earns the proof). This
        // is exactly what removes the 3s GET from cold-miss TTFB.
        self::fire_asset_mime_probe_loopback();
        return false;
    }

    /**
     * Fire the asset-MIME proof as a NON-BLOCKING loopback (same fire-and-forget transport as the
     * CDN-liveness probe): connect-only socket to local admin-ajax, write, close — no read. The handler
     * (wpc_v2_asset_mime_probe_handler) runs the ≤3s GET in its OWN request, off the visitor render
     * thread, so the cold-render TTFB stays free while the proof is still earned on first front-end
     * traffic. The handler derives the zone from config server-side (no client input is trusted).
     */
    private static function fire_asset_mime_probe_loopback()
    {
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
        // function of the request's CDN/zone/verdict state, which is stable across one request — so
        // compute it once. Not memoized until the negotiated-delivery class is loaded (the answer can
        // still change before then), so an early pre-init read is never pinned.
        static $na_cache = null;
        if ($na_cache !== null) return $na_cache;
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'emission_ready')) {
            return false;
        }
        $na_cache = (bool) self::natural_assets_on_uncached();
        return $na_cache;
    }

    private static function natural_assets_on_uncached()
    {
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'emission_ready')) {
            return false;
        }
        // Kill switch: WPC_NEGOTIATED_KILL is the single off-ramp for the whole next-gen system, so it
        // must cut the css/js/font naturalization path too (not just image negotiation).
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        // BUNNY (non-CF) = natural by construction, first-load, no probe. The unstyled incident is a
        // Cloudflare-only failure mode (vary-blind / mis-MIME edge); a Bunny pull-zone honors Vary and
        // serves correct text/css structurally, so it needs no per-zone MIME proof. Gate on the same
        // mode-independent "CDN live + zone configured + not suppressed + zone!=origin" facts the emit
        // path uses. Only a CF zone falls through to the proof-gated path below.
        if (function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $na_bunny_s    = get_option(WPS_IC_SETTINGS);
            $na_bunny_zone = is_string(self::$zoneName) ? trim(self::$zoneName) : '';
            $na_bunny_live = is_array($na_bunny_s) && !empty($na_bunny_s['live-cdn']) && (string) $na_bunny_s['live-cdn'] === '1';
            if ($na_bunny_live && $na_bunny_zone !== ''
                && !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())
                && !self::zone_is_cf()) {
                $na_bunny_origin = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                $na_bunny_zh     = preg_replace('#/.*$#', '', $na_bunny_zone);
                if ($na_bunny_origin && strcasecmp($na_bunny_zh, (string) $na_bunny_origin) !== 0) {
                    return (bool) apply_filters('wpc_natural_assets_enabled', true); // Bunny → natural, no probe
                }
            }
        }

        // On a Cloudflare-fronted origin, natural css/js zone URLs can come back as MIME image/css /
        // image/js → strict MIME checking refuses every stylesheet and script (unstyled page, "wp is
        // not defined"). The zone's asset passthrough is only proven for non-CF origins, so keep CF on
        // the fleet-proven /m:N/a: transform form. Sticky flag so cron/CLI renders (no CF header) stay
        // consistent with visitor renders. Working-CF sites can opt back in via the filter.
        $cf_now_na = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']);
        if ($cf_now_na && !get_option('wpc_v2_cf_assets_seen', 0)) {
            update_option('wpc_v2_cf_assets_seen', time(), true);
        }
        if (($cf_now_na || get_option('wpc_v2_cf_assets_seen', 0))
            && !apply_filters('wpc_natural_assets_on_cf', false)) {
            // Promote-on-proof for the asset lane on CF chains. Rather than couple to a pod version,
            // PROVE it per-zone: a throttled off-render probe fetches one real zone CSS URL through the
            // chain browsers use; text/css → natural assets unlock automatically at cutover (no plugin
            // release), anything else → stay on the transform form. Frontend renders only READ the
            // cached verdict, never probe.
            if (self::asset_mime_proven()) {
                // proven good (durable) — fall through to the zone_ok gate below
            } else {
                // probe extracted to maybe_probe_asset_mime() so the SAME proof can be earned on a
                // CF-direct-CNAME zone whose origin carries no CF header (where this CF block never runs).
                if (self::maybe_probe_asset_mime()) {
                    return self::natural_assets_on(); // re-enter with the fresh durable '1' verdict
                }
                return false;
            }
        }
        // Natural assets in ALL verified-CDN modes (clean URLs everywhere). The old gate made asset URL
        // shape depend on the IMAGE mode, so Next-Gen-OFF / Images-tile-OFF sites kept the /m:N/a: proxy
        // form even though the zone serves the natural origin paths identically (and the transform form
        // 302s while natural is CDN-cached 200). Assets are mode-independent passthrough; the right gate
        // is "is the zone proven". emission_ready stays the fast-path so GA sites are unaffected.
        $zone_ok = false;
        if (WPC_Negotiated_Delivery::emission_ready()) {
            $zone_ok = true;
        } elseif (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
            $rv_na = WPC_Delivery_Resolver::resolve_verbose(); // cached read
            $zone_ok = !empty($rv_na['verify']['cdn']['ok']);
        }
        // Never-unstyled on CF, independent of next-gen mode. The two fast-path sources above prove the
        // IMAGE lane, NOT that the zone serves text/css for CSS/JS/fonts. So on a CF zone with Next-Gen
        // ON, $zone_ok=true would SKIP the MIME-proof block below and strip /m:0/a: → clean CSS on the CF
        // cname with zero text/css proof → the unstyled-page incident if that edge serves image/css/403.
        // Fix: on a CF zone (config-authoritative — a CF-direct origin sends no CF header), the asset lane
        // ALWAYS requires the per-zone CSS-MIME proof. So demote a CF fast-path $zone_ok unless proven;
        // the (!$zone_ok) block below re-grants only on a real text/css verdict. Bunny is untouched.
        if ($zone_ok && self::zone_is_cf()) {
            $na_css_proven = self::asset_mime_proven();
            if (!$na_css_proven) {
                $zone_ok = false; // fall into the MIME-proof block below; natural only once text/css is proven
            }
        }
        // Mode-independent zone signal (clean natural CSS/JS/fonts in ALL modes). The two gates above
        // only fire when the IMAGE next-gen system is engaged, so a CDN-on but Next-Gen-off/un-promoted
        // site left css/js/font assets stuck on the /m:N/a: transform form even though the zone serves
        // the clean natural paths identically. Assets are a mode-independent passthrough (no Accept-
        // negotiation, no per-format decision), so the right gate is "CDN live + zone configured + not
        // master-killed". We deliberately do NOT require cdn_images_enabled() here (that's the IMAGE
        // lane): an Images-tile-OFF site must still get clean natural assets. The never-unstyled
        // guarantee is enforced inside this 3rd source: it requires the per-zone asset-MIME proof before
        // setting $zone_ok. live-cdn=0 / no zone / suppressed all still return false.
        if (!$zone_ok) {
            // Never-unstyled hole fix. The CF block above detects Cloudflare only via the ORIGIN request
            // header + the sticky wpc_v2_cf_assets_seen — but on a CF-direct zone (zone host is
            // CF-fronted, origin is a plain non-orange host) HTTP_CF_RAY is never present on any origin
            // render, so that whole MIME block is SKIPPED. Unlocking natural assets here from live-cdn+
            // zone alone would strip /m:N/a: → clean URLs on a CF-fronted zone with zero proof it serves
            // text/css — re-opening the unstyled-page incident. So this branch carries the same proof the
            // CF path enforces: the per-zone asset-MIME proof must be '1' OR the site opts in via the
            // filter. (When the image-lane fast-paths are true the zone is already proven, so this is moot
            // for them.) If the proof is unset, arm the probe (admin/cron only) — on a CF-direct origin
            // this is the only place it's reached. On a fresh '1' verdict, re-enter.
            if (!self::asset_mime_proven() && self::maybe_probe_asset_mime()) {
                return self::natural_assets_on();
            }
            $na_mime_proven = self::asset_mime_proven();
            if ($na_mime_proven) {
                $na_s    = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
                $na_zone = is_string(self::$zoneName) ? trim(self::$zoneName) : '';
                if ($na_zone !== ''                                                            // zone configured (blanked when suppressed)
                    && is_array($na_s) && !empty($na_s['live-cdn']) && (string) $na_s['live-cdn'] === '1' // CDN is live
                    && !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())) { // per-zone master-kill / auto-disable
                    $na_origin = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                    if ($na_origin && strcasecmp($na_zone, (string) $na_origin) !== 0) {       // zone != origin host → no self-loop
                        $zone_ok = true;
                    }
                }
            }
        }
        if (!$zone_ok) return false;
        return (bool) apply_filters('wpc_natural_assets_enabled', true);
    }

    /**
     * Gate for the picture AVIF <source>: emit natural -WxH.avif URLs (edge serves avif / self-heals)
     * instead of wp:2 transforms (which serve webp on a vary-blind edge). Master toggle =
     * Other-Optimizations 'avif-natural-source' (default on). Bunny Accept-upgrades a not-yet-landed
     * natural .avif → safe immediately. Cloudflare is vary-blind: a not-yet-landed .avif would pin its
     * webp interim for the full TTL, so on CF this stays on the (no-store) wp:2 form until the edge's
     * .avif live-transform is live — gated on wpc_v2_cf_avif_live. Filterable; KILL hard-off.
     */
    public static function avif_natural_source_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        // Force-natural master override (default OFF), above the per-format setting gate so it's a true
        // master override — symmetric with wpc_webp_immediate_ok() (no per-format gate). Otherwise a CF
        // zone with avif-natural-source='0' + force on would emit a natural .webp beside a wp:2 .avif
        // (asymmetric). Stays below WPC_NEGOTIATED_KILL. Width-safe: the picture builder's per-rung
        // file_exists + recover + native-width collapse keep a wp:2 transform wherever the sized .avif
        // isn't on disk, so forcing true never strands a rung on over-fetch. CAVEAT: bypasses the orch
        // vary witness — only enable wpc_force_natural() on a confirmed vary-correct + OTF-live zone.
        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return (bool) apply_filters('wpc_avif_natural_source_ok', true);
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['avif-natural-source']) || (string) $s['avif-natural-source'] !== '1') {
            return false;
        }
        // Promote-on-provisioning: gate on the orch's per-zone native_accept_vary confirmation (the same
        // signal that flips the zone's EnableAvifVary). When the orch confirms vary-correct Accept
        // buckets a natural .avif is both served AND safe, so the AVIF <source> fills every rung with
        // natural -WxH.avif instead of wp:2 (which the edge degrades to webp). nav=false → stay on the
        // no-store wp:2 form. Until the orch reports (nav=null), keep the conservative default (non-CF
        // optimistic; CF on wpc_v2_cf_avif_live). So a sync provisions a zone with no plugin release.
        $nav = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::orch_nav_signal() : null;
        if ($nav === true) {
            // CF caveat: native_accept_vary reflects the BUNNY PZ, not a CF-direct edge. So a Bunny
            // nav=true must NOT blind-promote natural AVIF on a CF zone (CF may not be AVIF-OTF-live →
            // a typed .avif <source> that 404s). On a CF zone require the CF-specific flag; Bunny /
            // Vary-honoring zones trust the nav witness.
            $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
            $ok = $cf ? (bool) get_option('wpc_v2_cf_avif_live', 1) : true;
        } elseif ($nav === false) {
            $ok = false;
        } else {
            // Un-provisioned (nav=null) fallback. Goal: natural AVIF out of the box, without betting the
            // never-404 contract on something the plugin can't verify per-zone. AVIF is a typed <source>
            // with no <img onerror> net, so a 404 on a not-yet-OTF-live zone is a terminal broken image
            // → the CF path must ride a real, flippable signal, not a blind true.
            //   * Non-CF (Bunny / Vary-honored): optimistic true. Bunny auto-downgrades a not-yet-landed
            //     natural .avif to the served format → no poison, missing sized .avif is OTF'd or safely
            //     degraded.
            //   * CF (vary-blind): gate on wpc_v2_cf_avif_live (defaults ON) — a verifiable per-zone
            //     flag, not a blind true. Fresh CF install IS natural out of the box, but a region that
            //     hasn't converged on AVIF-OTF can flip it to '0' → falls back to the never-404 wp:2
            //     transform. Symmetric with the WebP twin. AVIF is picture-only, so a flag-true CF zone
            //     can only ever cache real AVIF bytes — no poison.
            $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
            $ok = $cf ? (bool) get_option('wpc_v2_cf_avif_live', 1) : true; // CF default-on but flippable; Bunny optimistic
        }
        return (bool) apply_filters('wpc_avif_natural_source_ok', $ok);
    }

    /**
     * PICTURE-NATURAL FLEET FLAG (now default ON). Load-bearing: <picture> <source> selection is
     * TYPE-based, not load-based — an avif-capable browser commits to the avif <source> by
     * type="image/avif", and if that URL 404s it shows a BROKEN IMAGE; it does NOT fall through to the
     * webp <source> (only an <img onerror> could catch it, and that net isn't universal). So a 404 on
     * ANY avif rung (sized or bare) is broken, not a graceful downgrade. The OTF happy path is proven,
     * but the unhappy paths 404 → broken:
     *   (1) a region not yet converged on LIVE_AVIF_ENABLED (per-pod/region global env, not per-zone);
     *   (2) Sharp-gate saturation under load (OTF skipped → 404);
     *   (3) a missing origin source (origin_404).
     * Default flipped ON now that the CDN runs LIVE_AVIF_ENABLED as a global env (arbitrary natural
     * -WxH.{avif,webp} OTF-resizes from a jpg/png origin → 200 fleet-wide) and the landed-variant CF
     * purge keeps an OTF interim fresh. Revertible escape hatch: option/filter wpc_picture_natural_fleet
     * = 0 → predicates fall back to the proven per-zone vary-witness. The transcodable floor, the
     * per-rung -WxH gate, the stricter bare-full witness (CDN bare-OTF bug), and the single-URL CF gate
     * all stay authoritative.
     */
    public static function picture_natural_fleet_enabled()
    {
        $opt = function_exists('get_option') ? get_option('wpc_picture_natural_fleet', 1) : 1;
        return (bool) apply_filters('wpc_picture_natural_fleet', !empty($opt));
    }

    /**
     * SIZED (-WxH) AVIF picture <source> rung. When the fleet flag is OFF, rides the proven per-zone
     * vary-witness (avif_natural_source_ok = the master toggle ANDed with the orch native_accept_vary
     * witness / wpc_force_natural override): an un-converged region / Sharp-gate saturation /
     * missing-source 404 must fall back to the never-404 wp:2 transform. Also makes the user-facing
     * 'Natural AVIF Sources' toggle authoritative.
     */
    public static function picture_avif_natural_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        // Fleet flag wins when on; otherwise the witness is the real gate.
        $ok = self::picture_natural_fleet_enabled() ? true : self::avif_natural_source_ok();
        return (bool) apply_filters('wpc_picture_avif_natural_ok', $ok);
    }

    /**
     * Should the AVIF <source> srcset be eager (`srcset`) or lazy (`data-srcset`)?
     *
     * When the natural fleet is ON, emit a real `srcset`: the browser's <picture> selection NEVER reads
     * `data-srcset` (a lazy-lib convention), so a data-srcset <source> is invisible and the browser
     * falls through to the <img> — you never get the natural avif/webp. This is NOT "eager image": the
     * <img loading="lazy"> still defers the whole <picture> and sizes="auto" still works, so lazy +
     * auto-sizing are preserved and the image still loads once (the <img> fallback is naturalized too →
     * runLazy no-ops it). Fleet OFF falls back to the lazy-data-srcset double-fetch fix below, which
     * makes the optimizer JS promote source[data-srcset]→srcset in the same runLazy pass so a lazy
     * <picture> loads exactly once. Eager/above-fold imgs stay eager regardless.
     */
    public static function picture_source_srcset_attr($build_image_tag)
    {
        if (self::picture_natural_fleet_enabled() || self::wpc_natural_nw()) return 'srcset';
        // fleet OFF: lazy data-srcset so the optimizer JS promotes it → image loads once
        $img_is_lazy = is_string($build_image_tag)
            && (strpos($build_image_tag, 'data-src=') !== false || strpos($build_image_tag, 'data-srcset=') !== false);
        if (!$img_is_lazy) return 'srcset';
        $on = (bool) apply_filters('wpc_picture_avif_lazy_source',
            (bool) (function_exists('get_option') ? get_option('wpc_picture_avif_lazy_source', 1) : 1));
        return $on ? 'data-srcset' : 'srcset';
    }

    /**
     * Picture-scoped SIZED-AVIF emit gate.
     *
     * A natural -WxH.avif <source> inside a <picture> is safe on ALL CDN-on zones (CF + Bunny): the
     * browser only fetches the .avif <source> if it advertises image/avif support (type-self-selection),
     * so vary-blindness does NOT apply — a non-AVIF browser picks the WebP/JPEG <source> and never
     * requests the .avif. The edge OTF-encodes by the -WxH suffix (verified by curl: an arbitrary
     * not-on-disk -WxH.avif returns 200 image/avif under both AVIF and legacy Accept). The per-zone
     * vary-witness was designed for the non-picture single-URL emitters (bare <img src>, CSS-bg,
     * slideshow) where one URL must serve every browser — those keep the witness. So in <picture>, the
     * sized -WxH off-disk arms emit natural gated ONLY by the never-404 URL-form gates (the caller's
     * per-rung -WxH regex), not this per-zone witness.
     *
     * Scope: the three off-disk SIZED -WxH arms (main / extra-widths / universal ladder) + OTF#3, all
     * of which AND it with their per-rung -WxH classification. The -Nw form and bare-full are decided
     * elsewhere (bare-full on picture_avif_natural_full_ok — the CDN bare-OTF 404 bug — and -Nw → wp:2),
     * so the never-404 floor is preserved by the unchanged classification lines. NOT wired into any
     * single-URL / CSS-bg / slideshow path.
     *
     * Override: $all_zones (default TRUE, option/filter wpc_picture_avif_all_zones) delivers the rule
     * out of the box on every CDN-on zone with the AVIF ceiling on; set to '0' on a zone with a
     * known-broken edge → reverts to the proven per-zone witness. WPC_NEGOTIATED_KILL is the absolute
     * off-ramp above the override.
     */
    public static function picture_avif_emit_natural()
    {
        // KILL reverts everything — every arm that ANDs this falls to wp:2/witness-floor.
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        // Fleet flag (default-ON): trust the global edge OTF and skip the orch per-zone NEGATIVE witness.
        // orch_nav_signal() reflects the Bunny PZ's EnableAvifVary, NOT whether the edge OTF-resizes
        // -WxH.avif from a jpg/png origin — which is global (LIVE_AVIF_ENABLED), so the hard-off was
        // forcing wp:2 transforms on OTF-capable zones whose nav is '0'. A typed -WxH.avif <source> is
        // self-selected (vary-blindness N/A even on CF), so this is safe; the transcodable floor, the
        // per-rung -WxH gate, and the stricter bare-full witness all remain.
        if (self::picture_natural_fleet_enabled()) {
            return (self::$pictureAvifEnabled === true) && (self::$zoneName !== '');
        }
        // Operator override (default ON). A zone with a known-broken edge flips this off.
        $all_zones = (bool) apply_filters('wpc_picture_avif_all_zones',
            (bool) (function_exists('get_option') ? get_option('wpc_picture_avif_all_zones', 1) : 1));
        if ($all_zones) {
            // Never-404 guard: the orch's per-zone NEGATIVE witness is authoritative even under operator
            // optimism. nav=false = explicit "this region's edge is NOT AVIF-OTF-converged"; a typed
            // image/avif <source> that 404s is terminal (no <img onerror>), so fall to the never-404 wp:2
            // transform. Symmetric with the WebP-sized twin and the AVIF bare-full path. Un-reported
            // (null) zones stay optimistic — the operator opted in via the default-ON override.
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

    /**
     * Host-safe path extraction for the single-src natural .avif <source>.
     *
     * The single-src AVIF branches once turned an origin-hosted .avif into a zone-relative path by
     * str_replace-ing the origin host. After the wrap-gate widening admitted already-zone-hosted
     * single-src <img>s (CDN-aware themes/builders, origin→zone migrations, page-cache re-buffer),
     * $avifUrl can already be the zone URL — so the str_replace stripped nothing and prepending the
     * zone base produced a DOUBLED host (https://<zone>https://<zone>/…/logo.avif) → an unresolvable
     * <source> with no fall-through → broken logo. This normalizes any input to a single leading-slash
     * path, prepended to the zone base exactly once.
     *
     * Order matters for the custom-CNAME `/key:<apikey>` zone form, where $avifZoneBase carries a path
     * segment: strip the known zone base first (so an already-zone URL drops the whole base incl. /key:),
     * then the known origin host, then any generic scheme+host as a floor.
     *
     * @return string a leading-slash path (e.g. "/wp-content/uploads/2024/01/logo.avif"); never a host.
     */
    public static function avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost)
    {
        $avifUrl = (string) $avifUrl;
        // (1) Already zone-hosted (incl. the /key: CNAME form) → strip the exact known zone base.
        if ($avifZoneBase !== '' && strpos($avifUrl, $avifZoneBase) === 0) {
            return substr($avifUrl, strlen($avifZoneBase));
        }
        // (2) Origin-hosted (the canonical theme-emitted case) → strip the known origin host.
        if ($avifSiteHost !== '' && strpos($avifUrl, $avifSiteHost) === 0) {
            return substr($avifUrl, strlen($avifSiteHost));
        }
        // (3) Floor: any other absolute scheme+host → strip generically (matches the immune
        //     $optimistic_avif branch's preg_replace form). A schemeless/relative input is returned
        //     unchanged (already a path).
        return preg_replace('#^https?://[^/]+#', '', $avifUrl);
    }

    /**
     * BARE FULL-SIZE (no -WxH) natural .avif. Even with the fleet flag ON, the bare path stays on the
     * proven witness: the CDN has a confirmed bug on the bare full-size object (404 / corrupt /
     * alpha-dropped) independent of OTF convergence. So bare-full never rides the fleet flag until the
     * CDN bare-OTF object path is fixed. Sized rungs ride the flag; bare never does.
     */
    public static function picture_avif_natural_full_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        return (bool) apply_filters('wpc_picture_avif_natural_full_ok', self::avif_natural_source_ok());
    }

    public static function picture_webp_natural_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        // SIZED (-WxH) WebP picture <source> rung. Same fleet-flag gate as avif (a 404 on a webp
        // <source> is equally non-fall-through for a webp-only browser); fleet OFF → proven witness.
        $ok = self::picture_natural_fleet_enabled() ? true : (class_exists('wps_cdn_rewrite') && wps_cdn_rewrite::wpc_webp_immediate_ok());
        return (bool) apply_filters('wpc_picture_webp_natural_ok', $ok);
    }

    // WEBP-OTF-READY. When the edge reliably OTF-resizes AND transcodes a webp-only source to
    // -WxH.{webp,avif} on demand (cold-miss warms with no first-load 302→404), flip this ON to treat
    // webp sources like jpg/png: natural -WxH srcset + a typed avif <source>. OFF (default) → webp
    // stays on /q: transforms (no cold-miss broken images). The only gate that distinguishes webp from
    // raster in the picture builder.
    public static function wpc_webp_otf_ready()
    {
        $opt = function_exists('get_option') ? get_option('wpc_webp_otf_ready', 0) : 0;
        return (bool) apply_filters('wpc_webp_otf_ready', !empty($opt));
    }

    // NATURAL -Nw convergence. When ON, CF-direct picture-tier images use a synthetic width
    // ladder of clean natural -Nw URLs (base-Nw.{avif,webp}) instead of /q: transforms — the edge OTF-
    // resizes+transcodes each rung (E2), the base-interim floor catches a cold rung (E3, never-404). Works
    // with no attachment metadata (page-builder /storage sites), and supersedes both the wpc_webp_otf_ready
    // -WxH path and the wpc_webp_origin_natural interim. Default OFF until verified per tier.
    public static function wpc_natural_nw()
    {
        // Default ON (the converged path; E1–E6 proven on a live CF/Laravel zone). Set option/filter
        // wpc_natural_nw=0 to revert a zone to the legacy /q: transforms.
        $opt = function_exists('get_option') ? get_option('wpc_natural_nw', 1) : 1;
        return (bool) apply_filters('wpc_natural_nw', !empty($opt));
    }

    // Build the natural zone URL for a rung, using the SAME suffix the backfill/local writers land
    // (wpc_v2_adaptive_variant_suffix): -WxH when the aspect is known, -Nw when it isn't. So the URL matches
    // a landed WPC-optimized variant when one exists → the edge serves THAT file (best compression, static,
    // no OTF) and OTF-resizes from the base only when it hasn't landed yet (never-404 via the floor). Width
    // is already native-capped by wpc_nw_widths, so the -WxH height never upscales. Callers append
    // self::src_hint_qs($ext) for the ?src hint.
    public static function wpc_nw_url($src_url, $width, $fmt, $aspect_meta = null)
    {
        $base = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', (string) $src_url); // strip -WxH
        $base = preg_replace('/-\d+w(\.[a-z0-9]+)$/i', '$1', $base);                // strip -Nw
        $base = preg_replace('/\?.*$/', '', $base);                                 // strip query
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $base);                         // strip ext
        $base = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $base);
        if (function_exists('wpc_v2_adaptive_variant_suffix') && is_array($aspect_meta)
            && !empty($aspect_meta['width']) && !empty($aspect_meta['height'])) {
            $suffix = wpc_v2_adaptive_variant_suffix((int) $width, $aspect_meta);   // -WxH (matches landed) or -Nw
        } else {
            $suffix = '-' . (int) $width . 'w';                                     // no aspect → -Nw
        }
        return $base . $suffix . '.' . $fmt;
    }

    // Full-size natural zone URL (no -Nw suffix) — the edge transcodes the origin at its native width, so it
    // NEVER upscales (no 302→404). The safe fallback the bypass emits when the source's native width is unknown.
    public static function wpc_natural_full_url($src_url, $fmt)
    {
        $base = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', (string) $src_url);
        $base = preg_replace('/-\d+w(\.[a-z0-9]+)$/i', '$1', $base);
        $base = preg_replace('/\?.*$/', '', $base);
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $base);
        $base = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $base);
        return $base . '.' . $fmt;
    }

    // Width ladder for the -Nw convergence: WP srcset descriptors, else the <img>'s intrinsic width (+retina),
    // else a synthetic responsive ladder. STRICTLY capped at the source's native width — never emits a rung
    // above native (the edge 302s→404s on upscale). Returns [] when native is unknown AND no width is given,
    // so the caller emits the full-size natural instead. Small icon/cap widths are kept.
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
                // No srcset AND no intrinsic width: a synthetic ladder is safe ONLY when a native cap bounds
                // it — the edge 302s (→ origin 404 → BROKEN image) on a width above native. With no cap we
                // can't know native, so return [] and let the caller emit the full-size natural (no upscale).
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
            return $w >= 16; // keep small icon/cap widths (the synthetic ladder is >=320 anyway)
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

    /**
     * REGIME B (non-picture SINGLE-URL) format policy. A single image URL with no <picture> type=
     * self-selection and no fallback (slideshow CSS-bg, CSS background:url(), JS/lazy data-src, bare
     * unwrapped <img>) must pick an extension UNIVERSALLY servable on the edge: same-ext is always-200
     * everywhere; a single .webp/.avif URL is safe ONLY where the edge Accept-negotiates (Bunny: Vary
     * honored; Cloudflare: ONLY with a true per-zone vary witness — orch native_accept_vary===true OR
     * wpc_force_natural()). Intentionally STRICTER than the picture-typed gates: a typed <source> is safe
     * optimistically on CF (a legacy UA never fetches it) but a no-fallback single URL on a vary-blind
     * edge is not — so this must NOT ride wpc_webp_immediate_ok()'s optimistic un-witnessed-CF branch nor
     * the cf_avif_live default-on. Operator control 'single-url-image-format' (auto|same-ext|webp|avif),
     * default 'auto'. Returns the extension to emit, or FALSE = keep the caller's transform/origin.
     * Regime A (<picture>) is untouched. KILL/gif/empty stay safe inside.
     */
    public static function wpc_single_url_format($origin_ext, $zone_is_cf = null, $witness_ok = null)
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false; // absolute off-ramp
        $origin_ext = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $origin_ext));
        if ($origin_ext === '')    return false;   // unknown ext → caller keeps its transform
        if ($origin_ext === 'gif') return 'gif';    // never up-convert (animation loss / .webp-404)

        if ($zone_is_cf === null) $zone_is_cf = self::zone_is_cf();
        if ($witness_ok === null) {
            $witness_ok = (function_exists('wpc_force_natural') && wpc_force_natural())
                || self::avif_natural_source_ok()
                || (class_exists('wps_cdn_rewrite') && wps_cdn_rewrite::wpc_webp_immediate_ok());
        }

        // jpeg-only ceiling (Next-Gen OFF + proven edge) → same-ext floor everywhere.
        $jpeg_ceiling = class_exists('WPC_Negotiated_Delivery')
            && WPC_Negotiated_Delivery::is_active_jpeg()
            && !WPC_Negotiated_Delivery::is_active();

        $mode = self::single_url_format_mode(); // auto|same-ext|webp|avif

        // FORCE modes (operator asserts their edge negotiates). KILL handled above; gif/jpeg-ceiling win.
        if ($mode === 'same-ext') return $origin_ext;
        if ($jpeg_ceiling)        return $origin_ext;
        if ($mode === 'webp')     return 'webp';
        if ($mode === 'avif')     return 'avif';

        // AUTO: same-ext safe floor; promote to NEGOTIATED .webp ONLY where negotiation is PROVEN.
        // (Mirrors the slideshow live precedent `!$cf_zone && $webp_witness`.) The cf_avif_live default-on
        // flag is EXCLUDED here — it would pin a vary-blind .webp on every default CF install.
        if ($witness_ok) {
            if (!$zone_is_cf) return 'webp';        // Bunny / Vary-honored → promote
            $force = function_exists('wpc_force_natural') && wpc_force_natural();
            $nav   = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::orch_nav_signal() : null;
            if ($force || $nav === true) return 'webp'; // witnessed CF → promote
            return $origin_ext;                          // un-witnessed CF → SAFE FLOOR
        }
        // AUTO never emits a single-URL .avif: .avif is not in Bunny's Accept-vary-eligible set, so a
        // single .avif URL is URL-keyed (no vary) and would PIN on both edges. AVIF stays a typed
        // <picture> <source> (Regime A). Operator may still FORCE 'avif' above.
        return $origin_ext; // un-witnessed (incl. un-witnessed CF) → same-ext floor
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
        // Default ON. Safe because the naturalizer is never-404 by construction (it only host-swaps an
        // ALREADY-EXISTING -WxH.webp/.avif file to the zone — no conversion, no edge-OTF dependency) and
        // is still gated by the witness + base allowlist. Revert per-site via the option or KILL.
        $opt = function_exists('get_option') ? get_option('wpc_single_url_natural_prefer', 1) : 1;
        return (bool) apply_filters('wpc_single_url_natural_prefer', !empty($opt));
    }

    /**
     * Drop-wp single-<img> src. A `/wp:N/…/w:W/u:…` transform URL is stamped no-store by the pod, so
     * Cloudflare BYPASSes it (a pod hit on every visit). The equivalent natural uploads URL is
     * `public, max-age` → CF MISS→HIT, and the edge Accept-negotiates the format. This rewrites the
     * single <img> src / data-src transform → natural, but ONLY when provably safe — never-404 +
     * never-over-fetch by construction:
     *
     *   • flag wpc_single_url_natural_prefer OFF → tag unchanged (true no-op);
     *   • only on a PROVEN natural-negotiating edge (the witness);
     *   • only a SUB-SIZE source (the /u: URL already carries a -WxH suffix = the exact slot size →
     *     emitting it natural cannot over-fetch). Full/un-sized sources keep the transform;
     *   • targets the explicit `.webp` natural URL — the only natural form the proven edge CACHES (a
     *     same-ext `.jpg` natural 302s to origin no-store → CF BYPASS). Respects wp:0 (webp excluded);
     *   • host-validated: only a /u: URL already on OUR zone host (never a foreign host whose path
     *     merely looks like an uploads path);
     *   • the never-404 guarantee is the WITNESS, not a local stat(): on offloaded / edge-fs sites
     *     (/storage) the variant lives on the edge and is absent from local disk, so is_file() would
     *     both miss real files and can't see the edge. The witness is the edge-capability proof the rest
     *     of the natural machinery already trusts. Unproven → keep the /wp:N/ transform.
     *
     * Only src= / data-src= are touched (srcset keeps its path). Runs once on the finished <img> string
     * just before the <picture>-wrap natural detection. Witness + uploads base are memoised per request.
     *
     * MODERN-DELIVERY NOTE: emits a bare .webp with no inline JPEG fallback for browsers that can't
     * decode WebP (~3%, pre-2020). Correct on the target audience (webp-only / offloaded sites, where no
     * JPEG exists on disk anyway). DO NOT flip the flag on a site that keeps JPEG originals AND serves
     * legacy browsers without <picture> WebP — leave it OFF and use <picture> mode there.
     */
    /**
     * Is it SAFE to add sizes="auto" to this image — i.e. will it NOT distort the aspect ratio?
     *
     * sizes="auto" makes the browser size the layout box from the image's DECLARED width/height aspect.
     * That only renders correctly when the declared aspect matches the image's REAL aspect. On a page that
     * hard-codes mismatched dimensions (e.g. width=480 height=320 on a portrait image) auto squishes the
     * image into the wrong box — the 7.03.27 regression. So gate auto on this check.
     *
     * @return bool true when (a) there is NO declared box (browser uses the natural aspect → cannot squish),
     *              or (b) the declared aspect ≈ the real aspect (within 5%, tolerating WP sub-size rounding).
     *              false on a clear mismatch, or when a declared box exists but the real aspect is unknown.
     */
    public static function lazy_auto_aspect_safe($dw, $dh, $rw, $rh)
    {
        $dw = (int) $dw; $dh = (int) $dh; $rw = (int) $rw; $rh = (int) $rh;
        if ($dw <= 0 || $dh <= 0) return true;   // no declared box → natural aspect → cannot squish
        if ($rw <= 0 || $rh <= 0) return false;  // declared box present but real aspect unknown → don't risk it
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

    /**
     * Prepend sizes="auto" to a finished NATIVE-lazy <img> string so the browser sizes the srcset pick from
     * the image's ACTUAL rendered box instead of the inserted-width value — killing the over-fetch where a
     * full-size image is shown small (gallery/grid cell, narrow column): up to ~90% wasted bytes. Legacy/
     * local (CDN-off + non-negotiated) counterpart of the negotiated <img> auto.
     *
     * SAFE BY CONSTRUCTION (every guard must pass):
     *   - TOGGLE: off unless 'wpc_auto_sizes_lazy' is enabled (default false) — opt-in until validated.
     *   - loading="lazy" ONLY (spec): sliders/carousels + the LCP/hero are emitted EAGER → auto-excluded
     *     (no blurry-slide footgun, no LCP-discovery regression).
     *   - ASPECT-MATCH guarded (lazy_auto_aspect_safe): added only when the declared width/height aspect
     *     matches the image's real aspect (from the srcset dims) → can NEVER squish a mismatched-attr image
     *     (the 7.03.27 regression cannot recur).
     *   - REAL w-descriptor srcset required; PREPENDED to the existing sizes (non-supporting browsers keep
     *     the real fallback, never bare 100vw); no-op on no-srcset / no-sizes / already-auto.
     */
    public static function auto_sizes_for_lazy_img($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '') return $build_image_tag;
        // Toggle: "Right-size Lazy Images" (Other Optimizations). Setting drives the default; filter overrides.
        // Default OFF ⇒ this is a pure no-op (byte-identical to <=7.03.26).
        // (v7.03.67) Resolve the toggle the SAME way the buffer-net's outer gate does (get_option), not just
        // self::$settings — the in-memory cache isn't hydrated on every render path that runs the buffer-net
        // (e.g. the crit-gen fetch), so an inner self::$settings-only gate bailed there → the srcset shipped
        // INERT non-deterministically (buffer-net fired per the marker, but promotion didn't). Falling back to
        // get_option makes the inner gate always agree with the outer one → deterministic srcset.
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
        return str_replace($m[0], ' sizes=' . $m[1] . 'auto, ' . $m[2] . $m[1], $build_image_tag);
    }

    /**
     * (v7.03.55) ACTIVATE an inert lazy ladder so the BROWSER can right-size it — the missing half of
     * auto_sizes_for_lazy_img(). With "Resize by Incoming Device" (adaptive) or WPC-lazy on, rewriteLogic
     * emits the natural -WxH ladder into data-srcset (deferred for the JS resizer, ~line 4865). On a
     * NATIVE-lazy <img> (loading="lazy") that JS frequently never runs before the browser native-lazy-loads
     * the big `src` — especially below the fold — so the perfect ladder (incl. -300x169) sits UNUSED and the
     * browser over-fetches the full src (e.g. -800x450 into a 291px box; ~430 KiB on a news grid). This
     * promotes that inert data-srcset → an ACTIVE srcset so the very next pipeline step
     * (auto_sizes_for_lazy_img) adds sizes="auto" and the browser self-selects the right rung — no JS, no
     * race, below-the-fold included. Also drops data-wpc-loaded so the adaptive JS leaves it alone (it would
     * otherwise overwrite sizes="auto" with a fixed px on its near-viewport pass).
     *
     * SAME safety contract as auto_sizes_for_lazy_img (its inert-ladder twin) — every guard must pass:
     *   - TOGGLE 'wpc_auto_sizes_lazy' (Right-size Lazy Images), default OFF ⇒ pure no-op (byte-identical).
     *   - loading="lazy" ONLY (spec) — eager LCP/hero + no-loading sliders auto-excluded.
     *   - REAL w-descriptor data-srcset; SKIP if an active srcset already exists (never clobber).
     *   - SKIP JS-lazy/placeholder imgs (data-src present) and known carousels — promote only PURE native-lazy.
     *   - ASPECT-MATCH guarded (declared box vs the ladder's real aspect) → the 7.03.27 squish cannot recur.
     */
    public static function activate_lazy_srcset_auto($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '') return $build_image_tag;
        // Toggle: "Right-size Lazy Images" (Other Optimizations). Default OFF ⇒ pure no-op.
        // (v7.03.67) Resolve the toggle the SAME way the buffer-net's outer gate does (get_option), not just
        // self::$settings — the in-memory cache isn't hydrated on every render path that runs the buffer-net
        // (e.g. the crit-gen fetch), so an inner self::$settings-only gate bailed there → the srcset shipped
        // INERT non-deterministically (buffer-net fired per the marker, but promotion didn't). Falling back to
        // get_option makes the inner gate always agree with the outer one → deterministic srcset.
        $la_set = (is_array(self::$settings) && isset(self::$settings['lazy-auto-sizes']))
            ? self::$settings
            : ((function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array());
        $la_on = (is_array($la_set) && !empty($la_set['lazy-auto-sizes']));
        if (!apply_filters('wpc_auto_sizes_lazy', $la_on, $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $build_image_tag)) return $build_image_tag;
        // Inert ladder present, no active srcset, not a JS-lazy/placeholder img, not a carousel.
        if (!preg_match('/\sdata-srcset\s*=\s*(["\'])(.*?)\1/is', $build_image_tag, $ds)) return $build_image_tag;
        if (!preg_match('/\d+w(?=[\s,"\'])/', $ds[2])) return $build_image_tag;
        if (preg_match('/(?<![-\w])srcset\s*=/i', $build_image_tag)) return $build_image_tag;  // already active
        if (preg_match('/\sdata-src\s*=/i', $build_image_tag)) return $build_image_tag;         // JS-lazy placeholder
        if (preg_match('/\sclass\s*=\s*["\'][^"\']*(swiper|slick|owl|carousel|flickity|splide|attachment-slider|size-slider)/i', $build_image_tag)) return $build_image_tag;
        // ASPECT-MATCH guard — never distort a mismatched-attr image (declared box vs the ladder's real aspect).
        $aw = preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $build_image_tag, $mw) ? (int) $mw[1] : 0;
        $ah = preg_match('/\sheight\s*=\s*["\']?(\d+)/i', $build_image_tag, $mh) ? (int) $mh[1] : 0;
        list($rw, $rh) = self::srcset_real_dims(' srcset="' . $ds[2] . '"'); // reuse via a srcset-shaped string
        if (!self::lazy_auto_aspect_safe($aw, $ah, $rw, $rh)) return $build_image_tag;
        // Promote: inert data-srcset → ACTIVE srcset, and stop the adaptive JS from re-touching it.
        $build_image_tag = preg_replace('/\sdata-srcset(\s*=)/i', ' srcset$1', $build_image_tag, 1);
        $build_image_tag = preg_replace('/\sdata-wpc-loaded\s*=\s*(["\'])true\1/i', '', $build_image_tag);
        return $build_image_tag;
    }

    /**
     * (v7.03.52) Collapse a same-site SVG image-transform to its NATURAL zone URL. SVGs are vectors — the
     * /q:/r:/wp:/w: transform is a no-op pass-through (nothing to resize/transcode), so the markup carries a
     * needless transform URL, e.g. {zone}/q:u/r:0/wp:0/w:1/u:https://site/.../icon.svg. Host-swap it to the
     * clean {zone}/.../icon.svg: identical bytes, shorter + more cacheable, and never-404 by construction
     * (the /u: URL is the exact file already in the markup — only its host changes to the zone). Same-site
     * ONLY: a foreign /u: host is left untouched (external assets never go on the CDN, v7.03.49). Always on,
     * no witness/flag: unlike the webp/avif lane in maybe_naturalize_single_src (which trades on disk
     * presence + skips wp:0, so SVGs never reach it), an SVG host-swap is unconditionally safe. src=/data-src=.
     */
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
            '#((?:src|data-src)=")https://' . $zone . '(?:/q:[a-z0-9]+)?(?:/e:\d+)?/r:\d+/wp:\d+/w:\d+/u:(https?://[^"?]+?\.svg(?:\?[^"]*)?)(")#i',
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

    /**
     * (v7.03.53) SRCSET WIDTH CORRECTOR — guarantees every srcset rung's FILE matches its width DESCRIPTOR,
     * so the browser can pick a genuinely-smaller variant (no over-fetch). Builder-agnostic final pass that
     * fixes the two degenerate shapes seen in the wild:
     *   • one source at many descriptors  (e.g. -800x450.webp at 400w/480w/640w/660w — the LCP ladder whose
     *     /w: transform got naturalized away → collapsed onto one file), and
     *   • the bare FULL image injected at small descriptors (…name.webp at 480w/600w/960w — rewriteSrcset's
     *     480/960 + retina doublers naturalized onto the full file).
     * For each w-descriptor D on OUR zone it emits {zone}{base}-{D}x{round(D*aspect)}.{ext} (aspect from any
     * -WxH rung in the same srcset), deduped, and NEVER above the largest descriptor present (no upscale →
     * the edge OTF-downscales each from the base → never-404). Already-correct rungs round-trip unchanged;
     * x-descriptors, non-zone URLs, and aspect-less srcsets are left untouched.
     */
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
                // aspect from the LARGEST -WxH rung (truest source aspect; a small cropped thumb's rounding
                // shouldn't skew the synthesized heights).
                if (preg_match('#-(\d+)x(\d+)\.[a-z0-9]+#i', $p[0], $a) && (int) $a[1] > $aspectW) {
                    $aspectW = (int) $a[1]; $aspect = (int) $a[2] / (int) $a[1];
                }
            }
            if ($aspect <= 0 || $maxW <= 0) return $mm[0]; // no basis → leave untouched
            $out = []; $seen = [];
            foreach ($raw as $e) {
                $p = preg_split('/\s+/', $e);
                if (count($p) < 2 || !preg_match('/^(\d+)w$/', $p[1], $dm) || strpos($p[0], '//' . $zone . '/') === false) {
                    $out[] = $e; continue; // x-descriptors / off-zone → as-is
                }
                $D = (int) $dm[1];
                if (isset($seen[$D])) continue; // dedupe descriptor (kills the dup 300w etc.)
                $seen[$D] = true;
                $url = $p[0];
                $isTransform = (strpos($url, '/u:') !== false);
                // Resolve to the underlying natural path (a /w: transform → its u: source; natural → itself).
                $probe = ($isTransform && preg_match('#/u:(https?://\S+)$#i', $url, $um)) ? $um[1] : $url;
                $ppath = (string) wp_parse_url(preg_replace('/\?.*$/', '', $probe), PHP_URL_PATH);
                if ($ppath === '') { $out[] = $e; continue; }
                $noext = preg_replace('/\.[a-z0-9]+$/i', '', $ppath);
                $ext   = strtolower((string) pathinfo($ppath, PATHINFO_EXTENSION)); if ($ext === '') $ext = 'webp';
                $fw    = preg_match('#-(\d+)x(\d+)$#', $noext, $wx) ? (int) $wx[1] : 0; // underlying file width (0 = full/base)
                // PREFER the static landed file: when the underlying file's width already == the descriptor
                // (a real WP sub-size), or it's the full at the ceiling rung, emit its CLEAN natural zone URL
                // as-is — no needless edge OTF, no 1px rounding drift. Works whether the rung arrived natural
                // or as a /w: transform (we resolved u: above).
                if (($fw === $D) || ($fw === 0 && $D >= $maxW)) {
                    $out[] = 'https://' . $zone . $ppath . ' ' . $D . 'w';
                    continue;
                }
                if ($D > $maxW) { $out[] = $e; continue; } // never synthesize above the source ceiling (no upscale)
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
                    // Allowed media bases: the WP upload base + any operator-added path. Config-derived
                    // (not disk) so it's reliable on offloaded sites. This allowlist is the guard that
                    // keeps the host-relaxation below from rewriting a non-media same-site path that
                    // merely looks -WxH.
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
                if ((int) $m[3] === 0) return $m[0];                  // wp:0 = webp excluded for this image → respect
                $origin = $m[4];                                      // /u: URL (zone host for uploads, ORIGIN host for /storage)
                // SECURITY: only rewrite a /u: URL on OUR zone host OR the SAME-SITE origin host (the
                // /storage case carries the origin host). A foreign host whose path merely looks like a
                // media path must NOT be rewritten — the base allowlist below is the second guard.
                $ohost = (string) wp_parse_url($origin, PHP_URL_HOST);
                if ($ohost === ''
                    || (strcasecmp($ohost, (string) $zone_host) !== 0 && ($site_host === '' || strcasecmp($ohost, $site_host) !== 0))) {
                    return $m[0];
                }
                $clean = preg_replace('/\?.*$/', '', $origin);
                // Never-404 by construction: only naturalize a source already a -WxH .webp/.avif sub-size,
                // then emit that SAME file on the zone host (host-swap only, no format conversion) — the
                // object provably exists (the exact file in the markup) and can't 404, independent of
                // edge-OTF. A jpg/png origin keeps its transform: converting to .webp would depend on the
                // edge having it, and a same-ext .jpg natural 302s to origin no-store (no cache win).
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

    /**
     * PATH-B variant sanity (mirrors the path-A read-side gate in wp-compress-core.php).
     * An on-disk next-gen variant can be DIMENSIONALLY CORRUPT (observed: a 337x60 logo whose
     * logo.avif is 337x2560, and a 114x100 icon whose .avif is 2560x2560 — service mis-encodes).
     * file_exists() passes, the <source> is type-pinned with no <img> fallback, so the browser
     * commits to the corrupt AVIF and renders a black bar / wrong image. This validates the FILE's
     * real dimensions against the attachment's native size before emitting it as a <source> rung.
     * Returns FALSE only when the file is provably corrupt (a TRUE positive); on anything it cannot
     * decode it returns TRUE (FAIL-SAFE KEEP) — the plugin downloads AVIF and never encodes it, so
     * getimagesize() is false for a VALID .avif on a no-libavif host; a blind drop there would strip
     * valid AVIF fleet-wide. Default-on; disable via WPC_SKIP_PICTURE_VARIANT_VALIDATION.
     *
     * @param string $disk_path absolute path to the on-disk variant
     * @param int    $native_w  attachment native width (0 = unknown → cannot judge → KEEP)
     * @param int    $native_h  attachment native height (0 = unknown)
     * @return bool  true = safe to emit, false = corrupt, drop the rung
     */
    public static function picture_variant_dims_ok($disk_path, $native_w, $native_h)
    {
        if (defined('WPC_SKIP_PICTURE_VARIANT_VALIDATION') && WPC_SKIP_PICTURE_VARIANT_VALIDATION) return true;
        if (!is_string($disk_path) || $disk_path === '' || !@file_exists($disk_path)) return true; // not our call here
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
        if (!is_array($vd) || empty($vd[0]) || empty($vd[1])) return true; // undecodable here → FAIL-SAFE KEEP
        $rw = (int) $vd[0];
        $rh = (int) $vd[1];
        if ($rw <= 2 || $rh <= 2) return false; // degenerate
        // NATIVE-INDEPENDENT corruption gate: fires ONLY when BOTH native dims are unknown
        // (missing/external meta — the fail-open hole the native-relative checks below leave). A
        // mis-encode signature at native=0 is a side PEGGED at the registered max sub-size while the
        // shape is impossible for a real rung. PEG AT the max, not above (mis-encodes sit exactly there).
        $maxDim = (int) apply_filters('big_image_size_threshold', 2560);
        if ($maxDim > 0 && $native_w <= 0 && $native_h <= 0) {
            $ar = ($rw > 0 && $rh > 0) ? max($rw / $rh, $rh / $rw) : 99;
            // (a) BOTH sides peg the ceiling → square/near-square mis-encode (proicon2 2560x2560).
            if ($rw >= $maxDim && $rh >= $maxDim) return false;
            // (b) extreme aspect AND the long side pegs the ceiling → strip mis-encode (logo
            //     337x2560 = 1:7.6 with the long side at 2560). A legit ultra-wide banner WITH meta
            //     passes via the native branches below; an ultra-wide/strip WITHOUT meta is itself
            //     the corruption signature → drop the AVIF rung (WebP/JPEG carries; never broken).
            if ($ar >= 5.0 && max($rw, $rh) >= $maxDim) return false;
        }
        // Native-relative (stricter when native known). Tolerance max(8px,10%) absorbs sub-size
        // rounding; the logo case (real 2560 vs native 60) blows past it.
        if ($native_w > 0 && $rw > $native_w + max(8, (int) ($native_w * 0.10))) return false;
        if ($native_h > 0 && $rh > $native_h + max(8, (int) ($native_h * 0.10))) return false;
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // VARIANT SERVABILITY ORACLE (natural-only delivery).
    //
    // Deciding "does this -WxH.{fmt} variant exist?" via raw LOCAL-disk file_exists fails on OFFLOADED
    // media (variants on edge/pod-fs, not the WP origin disk — e.g. Cloudways /storage): every stat
    // returns false, so the plugin emits a 404ing optimistic .avif, skips avif/webp that DO exist, or
    // falls to a /q:…/wp:N/ transform CF marks no-store → BYPASS. This oracle returns the STRONGEST tier
    // proving a natural -WxH variant servable WITHOUT assuming local disk, so call-sites emit natural-only
    // and never-404. Memoised per request. DEFAULT OFF → call-sites keep their original file_exists branch.
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    const VARIANT_NONE     = 0;
    const VARIANT_WITNESS  = 1;  // edge OTF proves -WxH.{fmt} servable (the only signal for /storage)
    const VARIANT_RECORDED = 2;  // ic_local_variants record (WP attachments, survives offload)
    const VARIANT_ON_DISK  = 3;  // local file + dims-ok (today's behaviour)

    /**
     * Strongest tier proving a natural -WxH.{fmt} variant is servable. Cascade T1 disk → T2 record →
     * T3 edge-OTF witness. See block comment above.
     *
     * @param int    $attachment_id 0 for page-builder/no-meta (/storage) → T2 skipped.
     * @param string $url           natural candidate URL (…/img-WxH.fmt) — drives the T3 -WxH gate.
     * @param string $fmt           'avif'|'webp'|'jpg'|'png'|… the format we want to emit.
     * @param string $size_label    ic_local_variants key (caller-supplied, authoritative); '' to skip.
     * @param string $disk_path     absolute path for T1; '' → derive from $url (site_url→ABSPATH map).
     * @param int    $width         optional width → T2 modern-alias keys (wpc_{W}, {W}w).
     * @return int   one of self::VARIANT_*.
     */
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
            // corrupt on disk → do NOT claim disk; fall through (chooser will try the next format).
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
                $sfx  = in_array($fmt, ['jpg', 'jpeg'], true) ? '' : '-' . $fmt; // write-side suffix
                $keys = [];
                if ($size_label !== '') $keys[] = $size_label . $sfx;          // caller label (authoritative)
                if ((int) $width > 0)   { $keys[] = 'wpc_' . (int) $width . $sfx; $keys[] = (int) $width . 'w' . $sfx; }
                foreach ($keys as $kk) {
                    if (isset($lv[$kk]) && is_array($lv[$kk])) {
                        $e = $lv[$kk];
                        $skipped = !empty($e['skipped'])
                            || (!empty($e['skipped_formats']) && is_array($e['skipped_formats']) && in_array($fmt, $e['skipped_formats'], true));
                        if ($skipped) return $c[$k] = self::VARIANT_NONE;       // explicit skip is authoritative
                        return $c[$k] = self::VARIANT_RECORDED;                 // present + not skipped → servable
                    }
                }
            }
        }

        // T3 — EDGE-OTF WITNESS. Requires a -WxH suffix (edge resize-by-suffix is proven only for -WxH;
        // a bare-full URL has no -WxH form so it stays on the stricter *_full_ok witness elsewhere). The
        // avif/webp arms DELEGATE to the existing witnesses so orch nav===false hard-off, the CF/Bunny
        // split, wpc_v2_cf_avif_live and WPC_NEGOTIATED_KILL all stay authoritative — the oracle never
        // weakens the never-404 contract.
        $clean  = (string) preg_replace('/\?.*$/', '', (string) $url);
        $is_wxh = (bool) preg_match('/-\d+x\d+\.[a-z0-9]+$/i', $clean);
        if ($is_wxh) {
            if ($fmt === 'avif') {
                $w = self::picture_avif_emit_natural();
            } elseif ($fmt === 'webp') {
                $w = self::picture_webp_natural_ok();
            } else {
                $w = ($fmt === 'jpg' || $fmt === 'jpeg' || $fmt === 'png'); // same-ext is always edge-200 by extension
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
            $chain = array_values(array_diff($chain, ['avif'])); // avif_src_transcodable: no avif from a webp-native source
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

    /**
     * Convert CDN ASSET transform URLs (passthrough/minify) to clean NATURAL URLs on the same
     * cname — drop the /m:N/a: (or /font:true/a:) wrapper, keep the origin path + query:
     *   https://{cname}/m:0/a:https://origin/wp-content/x.css?icv=H → https://{cname}/wp-content/x.css?icv=H
     *
     * Touches ONLY the m: / font: asset forms. It deliberately does NOT match image transforms
     * (/q:i/r:0/wp:N/w:N/u:…) — those do real per-request resize + format work a natural URL
     * cannot reproduce. Same host throughout (the cname); we only strip the transform prefix.
     * Verified safe on staging: the container serves these natural paths (CDN-cached, 200), and
     * the old transform form now 302-redirects to origin — so this is strictly better than today.
     */
    public static function naturalize_asset_urls($html)
    {
        $cname = self::$zoneName;
        if (empty($cname) || !is_string($html) || $html === '' || strpos($html, '/a:') === false) {
            return $html;
        }
        $zq = preg_quote((string) $cname, '#');
        // Escape-tolerant: match BOTH the plain form (…/m:0/a:…) and the JSON-escaped form (…\/m:0\/a:…).
        // Content printed after the WPC buffer flushes (import-maps, script-module data, prefetch JSON)
        // escapes its slashes. $s = optional backslash + slash; escaped matches are re-escaped on output.
        $s = '\\\\?/';
        $re = '#https:' . $s . $s . $zq . $s . '(?:m:[01]|font:true)' . $s . 'a:((?:https?:)?(?:' . $s . $s . ')?[^"\'\s)>]+)#i';
        $out = preg_replace_callback(
            $re,
            function ($m) use ($cname) {
                $raw = $m[1];
                $escaped = (strpos($raw, '\\/') !== false);
                $unesc = $escaped ? str_replace('\\/', '/', $raw) : $raw;
                if (!self::imageUrlMatchingSiteUrl($unesc)) {
                    return $m[0];
                }
                $p = @parse_url($unesc);
                if (empty($p['path'])) return $m[0]; // unparseable → leave the transform form untouched
                $q = (isset($p['query']) && $p['query'] !== '') ? '?' . $p['query'] : '';
                $natural = 'https://' . $cname . $p['path'] . $q;
                if ($escaped) $natural = str_replace('/', '\\/', $natural); // keep JSON valid
                return $natural;
            },
            $html
        );
        return ($out === null) ? $html : $out; // preg failure (e.g. backtrack limit) → original
    }

    public function allLinks($html)
    {
        $html = preg_replace_callback('/https?:(\/\/[^"\']*\.(?:svg|css|js|ico|icon))/i', [__CLASS__, 'cdnAllLinks'], $html);

        return $html;
    }

    public function cdnAllLinks($image)
    {
        $src_url = $image[0];

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
                // ORIGIN FLOOR for css/js: unproven zone → leave the origin href (real text/css·js,
                // bypasses edge + CF rule, never wrong-MIME). Proven zone → the m:N/a: build below runs.
                // svg/ico else-arm untouched (SVG has its own zoneify lane).
                if ((strpos($src_url, '.css') !== false || strpos($src_url, '.js') !== false) && !self::natural_assets_on()) {
                    return $src_url;
                }
                // Local-Fonts cache stylesheet (wp-cio-fonts/{hash}.css): keep natural origin so its @font-face
                // URLs match the inline/preload set. Reorder-proof (latent today: replaceFrontend injects it
                // after the sweep that reaches here).
                if (stripos($src_url, '/cache/wp-cio-fonts/') !== false) {
                    return $src_url;
                }
                if (strpos($src_url, '.css') !== false) {
                    if (self::$css == "1") {
                        $fileMinify = self::$cssMinify;
                        if (self::isExcluded('css_minify', $src_url)) {
                            $fileMinify = '0';
                        }

                        if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
                            $fileMinify = '1';
                        }

                        $newSrc = 'https://' . self::$zoneName . '/m:' . $fileMinify . '/a:' . self::reformatUrl($src_url);
                    }
                } elseif (strpos($src_url, '.js') !== false) {
                    if (self::$js == "1") {
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

    /**
     * Is link matching the site url?
     *
     * @param $image
     *
     * @return bool
     */
    public static function imageUrlMatchingSiteUrl($image)
    {
        $site_url = self::$siteUrl;
        $image = str_replace(['https://', 'http://'], '', $image);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($image, '.css') !== false || strpos($image, '.js') !== false) {
            foreach (self::$defaultExcludedList as $i => $excluded_string) {
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
        if (!empty(self::$lazyExcludeList) && !empty(self::$lazyEnabled) && self::$lazyEnabled == '1') {
            //return 'asd';
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

    public function externalUrls($html)
    {
        $html = preg_replace_callback('/https?:[^)\s]+\.(jpg|jpeg|png|gif|svg|css|js|ico|icon)(?![^.\w]*\.[^.\w]*)/i', [__CLASS__, 'cdnExternalUrls'], $html);

        return $html;
    }

    public function cdnExternalUrls($image)
    {
        $src_url = $image[0];
        $width = 1;

        if (self::$isAmp->isAmp()) {
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

        // HOST GUARD (v7.03.47) — never build an external-asset transform without a real CDN zone host, or
        // on the origin host. An empty zone yields "https:///m:…/a:…" which the browser resolves against the
        // ORIGIN → 404 (the reported origin-host transform), and a zone accidentally equal to the origin host
        // 404s the same way (also the suppressed-zone state). Stay on the natural URL when the zone isn't safely usable.
        $wpc_z  = (string) self::$zoneName;
        $wpc_oh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if ($wpc_z === '' || ($wpc_oh !== '' && strcasecmp($wpc_z, $wpc_oh) === 0)) {
            return $src_url;
        }

        // (v7.03.49) EXTERNAL-origin assets are never routed through the CDN — leave them direct (no
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
                    if (self::$js == "1") {
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


    /**
     * Build a device-independent multi-candidate srcset for LCP (lazy-skipped) images.
     *
     * Unlike rewriteSrcset() — which bails on mobile to support device-split page caches —
     * this function emits the SAME multi-width ladder regardless of the requesting device.
     * The browser picks the appropriate candidate from `srcset` at render time based on
     * actual viewport and DPR, so output is safe under any cache-split strategy.
     *
     * Capped at the user's `maxWidth` setting (default 2560). Ladder: 400, 800, 1200, 1600, 2048, 2560.
     *
     * CDN-mode only — local/non-CDN sites should preserve WordPress's native `srcset` instead.
     */
    public static function buildLcpSrcset($imageUrl, $srcWidthHint = 0)
    {
        $maxW = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
        if ($maxW < 100) $maxW = 2560; // guard against bad setting values

        // Finer-grained ladder to minimize over-fetching.
        // Common breakpoints from real-world use: 400 (small mobile), 480 (mobile), 640 (large
        // mobile / small container), 720 (tablet portrait), 800 (tablet / small desktop col),
        // 960 (medium col), 1200 (standard content), 1600 (wide), 2048 (retina content), 2560 (hero max).
        // Browsers pick the smallest candidate ≥ the required width, so tighter spacing = less waste.
        $widths = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];

        // Honor `wpc-min-mobile-width` (admin "Minimum mobile image width", effectively a MAX) on the
        // LCP srcset: on mobile UA + adaptive on, filter the ladder to widths ≤ the setting so mobile
        // never picks a bigger variant. Gated on adaptive=1; page cache already varies by UA; leaves ≥1
        // entry. Filter `wpc_mobile_srcset_cap` overrides per-image (return 0 to disable).
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

        // Treat $maxW as a MAX-DIMENSION cap (any side), not strictly max-width: for PORTRAIT, capping
        // width=$maxW leaves height > $maxW (bandwidth waste). Effective cap = min($maxW, $maxW × aspect)
        // where aspect = source_w/source_h, so portrait caps width to keep encoded height ≤ $maxW.
        $effective_max = $maxW;
        // SOURCE-WIDTH CAP. The portrait branch below caps only encoded HEIGHT, not a width above the
        // source. An above-source -WxH upscales → the edge serves a no-store image/webp, not a cacheable
        // avif. Cap at the source width: attachment meta width when resolvable, else the <img> width hint.
        $src_w_for_cap = (int) $srcWidthHint;
        $attachment_id = function_exists('attachment_url_to_postid')
            ? (int) attachment_url_to_postid(preg_replace('/\?.*$/', '', $imageUrl))
            : 0;
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
        if ($src_w_for_cap > 0) $effective_max = min($effective_max, $src_w_for_cap);

        $widths = array_unique(array_map(function ($w) use ($effective_max) {
            return min($w, $effective_max);
        }, $widths));
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

        // (v7.10.04) SECURITY: admin-gate this debug branch + esc_html() its output. It reflected
        // $_SERVER['REQUEST_URI'] (attacker-influenced) straight into the page HTML = reflected XSS.
        if (!empty($_GET['test_adding_critical_ajax']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            $script  = esc_html(print_r($post, true));
            $script .= esc_html((string) ($_SERVER['HTTP_HOST'] ?? '') . (string) ($_SERVER['REQUEST_URI'] ?? ''));
            return $script;
        }

        if ($this->isWooCartOrCheckout()) {
            return '</body>';
        }

        $script = '';
        if (isset($post) && !empty($post->ID)) {

            // (v7.10.04) SECURITY: $_SERVER['REQUEST_URI'] is attacker-influenced and is interpolated
            // into a JS string + a form body below. rawurlencode() makes it inert in the JS-string
            // context (no quotes/backslashes/newlines survive) AND correct as the realUrl form value
            // (a raw ?/& in the URI would otherwise corrupt the POST body). The handler urldecodes it
            // back, so behaviour is unchanged. Closes the reflected-XSS sink.
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
        
        xhr.send("action=wpc_send_critical_remote&postID={$post->ID}&realUrl={$realUrl}");

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

    public function addCritical($html)
    {
        $criticalCss = $this->addCriticalCSS($html);
        $criticalCss = $this->filterCriticalFontFaces($criticalCss);

        // Inline above-the-fold Google-Fonts @font-face so the preloader below finds + preloads them. A
        // gstatic Google-Fonts site (Elementor default) has NO inline @font-face — the faces live only in
        // the deferred googleapis stylesheet — so without this the preloader has nothing to scan -> heading FOUT.
        $gfFaces = $this->maybeInlineGoogleFontFaces($html, $criticalCss);
        if ($gfFaces !== '') {
            $criticalCss = $gfFaces . $criticalCss;

            // De-dup gstatic vs local. Penthouse captures the page's ORIGINAL @font-face (gstatic URLs) into
            // the critical CSS, and filterCriticalFontFaces only removes user-blocked fonts — so on a Local-
            // Fonts site the crit still carries the gstatic copies of families we now serve locally (the ATF
            // faces just prepended + the deferred local cache). Those gstatic copies (a) double-load against
            // the local woff2 and (b) — cross-origin and slow — can blow past font-display:block's timeout,
            // producing a fallback flash even though every face says block. Strip the gstatic copies for the
            // families we localized; leave gstatic @font-face for any family we did NOT localize so an
            // un-self-hosted font keeps its only source.
            if (stripos($criticalCss, 'fonts.gstatic.com') !== false
                && preg_match_all('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $gfFaces, $fm)) {
                $localFams = array_unique(array_map(function ($s) { return strtolower(trim($s)); }, $fm[1]));
                $criticalCss = preg_replace_callback('/@font-face\s*\{.*?\}/is', function ($m) use ($localFams) {
                    if (stripos($m[0], 'fonts.gstatic.com') === false) return $m[0];
                    if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $m[0], $mf)
                        && in_array(strtolower(trim($mf[1])), $localFams, true)) {
                        return ''; // localized family → drop the gstatic duplicate (local face serves it)
                    }
                    return $m[0]; // un-localized family → keep (don't remove its only source)
                }, $criticalCss);
            }
        }

        // Extract font preloads AFTER filtering — only preload fonts that survive
        if (!empty(self::$settings['preload-crit-fonts']) && self::$settings['preload-crit-fonts'] == '1') {
            $preloadLinks = $this->extractCriticalFontPreloads($criticalCss);
            $criticalCss = $preloadLinks . $criticalCss;
        }

        if (!empty($_GET['extractCrit'])) {
            return print_r([$criticalCss], true);
        }

        // Authoritative entrance-animation START-STATE. Elementor hides animated elements with
        // `.elementor-invisible{visibility:hidden}` and reveals + animates them on init. Under critical CSS
        // that rule can be absent or overridden per-page (Penthouse capture varies; legacy reveal overrides),
        // so animated elements paint in their FINAL position and then snap-to-hidden + animate when the
        // deferred CSS/JS lands — the flicker + "animate up", and the heading font-swap (it was shown before
        // its preloaded face won the cascade). Re-assert the rule universally, AFTER the crit rules so it wins
        // on equal specificity, and WITHOUT !important so Elementor's `.animated` class still reveals it. It
        // matches Elementor's own rule, so it adds no new stuck-invisible risk — Elementor's init un-hides it,
        // and the JS-delay 10ms fallback guarantees init fires. Filterable kill-switch, default on. NOTE: pairs
        // with the crit team retiring their per-element reveal override (which uses !important and would still
        // win over this on the elements it covers); this injection is the universal guarantee for every
        // above-fold animated element on every site, independent of per-page Penthouse capture.
        if (apply_filters('wpc_elementor_anim_start_state', true) && stripos($html, 'elementor-invisible') !== false) {
            $criticalCss .= "\r\n" . '<style id="wpc-elementor-anim-start">.elementor-invisible{visibility:hidden}</style>';
        }

        // (v7.03.71) Lazy-thumbnail black-flash guard. Some themes (Sahifa/TieLabs, etc.) paint a solid dark
        // background on the post-thumbnail link — `.post-thumbnail a{background:#000}` — as a reveal-from-black
        // lazy effect. Penthouse captures that into the crit, so at first paint, before each lazy thumbnail has
        // loaded, the dark anchor shows through the not-yet-loaded image as black boxes until it loads. Only
        // inject when the crit actually carries such a near-black anchor background (no-op on every other theme).
        // The override uses `a[href]` — one notch more specific than the theme's `.post-thumbnail a` — so it also
        // wins after the deferred full stylesheet re-applies the dark rule, with no !important; the loaded image
        // covers the anchor in steady state, so this is invisible except for killing the load-time black flash.
        // Filterable kill-switch, default on.
        if (apply_filters('wpc_lazy_thumb_blackflash_guard', true)
            && preg_match('/\.post-thumbnail\s+a\s*\{[^}]*background[^;}]*(?:#0{3,6}\b|\bblack\b)/i', $criticalCss)) {
            $criticalCss .= "\r\n" . '<style type="text/css" id="wpc-lazy-thumb-bgfix">.post-thumbnail a[href]{background:transparent}</style>';
        }

        $html = str_replace('<!--WPC_INSERT_CRITICAL-->', $criticalCss, $html);
        return $html;
    }

    public function addCriticalCSS($html)
    {
        $output = '';

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists(true);


        if (!empty($criticalCSSExists) && empty($_GET['removeCritical'])) {
            // (v7.03.76) Feed the LCP fetchpriority hint to wpc_lcp_hint_pass (.75). The crit pull stashed the
            // per-URL {stem,width} as lcp.json next to the crit (saveCriticalCss). Read it here — this page's
            // own crit dir, so it's per-URL — and expose it through the wpc_lcp_hint filter the .75 consumer
            // reads. Local read, no fetch; inert when the file is absent. See wpc-lcp-fetchpriority-contract.md.
            if (!empty($criticalCSSExists['desktop'])) {
                $wpc_lcp_file = dirname($criticalCSSExists['desktop']) . '/lcp.json';
                // (v7.03.88) INLINE late-hint capture — REPLACES the .85 deferred shutdown fetch, which on
                // FPM hosts that recycle the worker at fastcgi_finish_request never completed (the joint crit
                // debug proved it: give_up_count climbed but lcp.json never landed, last_fetch stayed null).
                // The .lcp.json lands ~28s post-regen; once crit is older than that, a visit fetches it INLINE
                // here (≤3s, ≤1/URL/min, give-up after 15) so the reader just below applies it in THIS render.
                // Paired with no-cache-while-pending (addons/v2/v2-lcp-nocache.php) so the hint-less page can't
                // get cached before the file lands — which is what keeps renders flowing so this capture fires.
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
                            @unlink($wpc_heal_uf);   // producer never wrote it after ~15 tries — stop probing
                        } elseif ($wpc_heal_url !== '' && filter_var($wpc_heal_url, FILTER_VALIDATE_URL)) {
                            set_transient($wpc_heal_nkey, (int) get_transient($wpc_heal_nkey) + 1, HOUR_IN_SECONDS);
                            set_transient($wpc_heal_lock, 1, MINUTE_IN_SECONDS);   // ≤1 heal-fetch / URL / min
                            $wpc_heal_ua = defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'WPCompress';
                            // INLINE direct GET to CDN storage — always reachable; ≤3s so the render stays bounded.
                            $wpc_hr = wp_remote_get($wpc_heal_url, ['timeout' => 3, 'headers' => ['user-agent' => $wpc_heal_ua]]);
                            $wpc_h_status = is_wp_error($wpc_hr) ? 0 : (int) wp_remote_retrieve_response_code($wpc_hr);
                            $wpc_h_wrote  = false;
                            if ($wpc_h_status === 200) {
                                $wpc_hb = wp_remote_retrieve_body($wpc_hr);
                                if (is_string($wpc_hb) && $wpc_hb !== '' && json_decode($wpc_hb) !== null) {
                                    $wpc_h_wrote = (bool) @file_put_contents($wpc_lcp_file, $wpc_hb);
                                    if ($wpc_h_wrote && class_exists('wps_ic_cache_integrations')) {
                                        $wpc_heal_pageurl = (is_ssl() ? 'https://' : 'http://')
                                            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
                                            . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
                                        $wpc_heal_key = class_exists('wps_ic_url_key')
                                            ? (new wps_ic_url_key())->setup($wpc_heal_pageurl)
                                            : basename(rtrim($wpc_heal_dir, '/'));
                                        if ($wpc_heal_key !== '') {
                                            wps_ic_cache_integrations::purgeCacheFiles($wpc_heal_key);   // clear any pre-.88 hint-less render
                                            do_action('wps_ic_purge_all_cache', $wpc_heal_key);
                                        }
                                    }
                                }
                            }
                            // record for the LCP health endpoint (crit joint debug)
                            @file_put_contents($wpc_heal_dir . 'lcp_heal.json', wp_json_encode([
                                'at' => gmdate('c'), 'http_status' => $wpc_h_status, 'wrote' => $wpc_h_wrote, 'mode' => 'inline',
                            ]));
                        }
                    }
                }
                if (is_readable($wpc_lcp_file)) {
                    $wpc_lcp_json = json_decode((string) file_get_contents($wpc_lcp_file), true);
                    $wpc_lcp_hint = (is_array($wpc_lcp_json) && isset($wpc_lcp_json['lcp']) && is_array($wpc_lcp_json['lcp']))
                        ? $wpc_lcp_json['lcp']
                        : (is_array($wpc_lcp_json) ? $wpc_lcp_json : null);
                    if (is_array($wpc_lcp_hint) && !empty($wpc_lcp_hint)) {
                        add_filter('wpc_lcp_hint', function () use ($wpc_lcp_hint) { return $wpc_lcp_hint; }, 5);
                    }
                    // (v7.03.84) Above-the-fold RIGHT-SIZING hints — crit producer v3.25.8. The same render that
                    // finds the LCP measures the first ≤12 ATF images PER DEVICE, emitted as a sibling atf_images
                    // object; each entry {stem, css_w, css_h, nat_w, nat_h, top}. css_w = CSS LAYOUT px,
                    // DPR-INDEPENDENT — the plugin's generator builds the ×1/1.75/2 device-pixel ladder from it
                    // (rung = css_w × real DPR). Build a stem→[m,d] map of mobile/desktop css_w for the
                    // negotiated-delivery sizes override, which feeds BOTH the rung generator AND the output
                    // sizes. Primary shape mirrors the lcp field ({mobile:[…], desktop:[…]}); falls back to a flat
                    // array (applied to both viewports). Inert when absent. Contract: crit-team-atf-image-sizing-contract.md.
                    $wpc_atf = (isset($wpc_lcp_json['atf_images']) && is_array($wpc_lcp_json['atf_images']))
                        ? $wpc_lcp_json['atf_images'] : null;
                    if ($wpc_atf !== null) {
                        $wpc_afold_map = [];
                        $wpc_atf_m = (isset($wpc_atf['mobile'])  && is_array($wpc_atf['mobile']))  ? $wpc_atf['mobile']  : [];
                        $wpc_atf_d = (isset($wpc_atf['desktop']) && is_array($wpc_atf['desktop'])) ? $wpc_atf['desktop'] : [];
                        // Flat fallback: no mobile/desktop keys → one list, applied to both viewports.
                        if (empty($wpc_atf_m) && empty($wpc_atf_d)) { $wpc_atf_m = $wpc_atf; $wpc_atf_d = $wpc_atf; }
                        foreach (['m' => $wpc_atf_m, 'd' => $wpc_atf_d] as $wpc_atf_slot => $wpc_atf_list) {
                            foreach ($wpc_atf_list as $wpc_atf_im) {
                                if (!is_array($wpc_atf_im) || empty($wpc_atf_im['stem']) || empty($wpc_atf_im['css_w'])) continue;
                                $wpc_atf_st = strtolower((string) $wpc_atf_im['stem']);
                                if ($wpc_atf_st === '') continue;
                                if (!isset($wpc_afold_map[$wpc_atf_st])) $wpc_afold_map[$wpc_atf_st] = ['m' => 0, 'd' => 0];
                                if ($wpc_afold_map[$wpc_atf_st][$wpc_atf_slot] === 0) {        // first = topmost (DOM order)
                                    $wpc_afold_map[$wpc_atf_st][$wpc_atf_slot] = (int) round((float) $wpc_atf_im['css_w']);
                                }
                            }
                        }
                        if (!empty($wpc_afold_map)) {
                            add_filter('wpc_afold_image_hints', function () use ($wpc_afold_map) { return $wpc_afold_map; }, 5);
                        }
                    }
                }
            }
            if (file_exists($criticalCSSExists['desktop']) && file_exists($criticalCSSExists['mobile'])) {
                $criticalCSSContent_Desktop = file_get_contents($criticalCSSExists['desktop']);
                $criticalCSSContent_Mobile = file_get_contents($criticalCSSExists['mobile']);

                if (str_contains($criticalCSSContent_Desktop, '<body>') || str_contains($criticalCSSContent_Mobile, '<body>')) {
                    // Do Nothing, it's html
                } else {

                    // Strip content before "/* Preload Fonts */" marker if present (legacy separator)
                    $getCSSContent = function ($cssContent) {
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');
                        return $commentPos !== false ? substr($cssContent, $commentPos + strlen('/* Preload Fonts */')) : $cssContent;
                    };

                    $criticalCSSContent_Desktop = $getCSSContent($criticalCSSContent_Desktop);
                    $criticalCSSContent_Mobile = $getCSSContent($criticalCSSContent_Mobile);

                    // Output critical CSS — preload links are now added by addCritical() after filtering
                    if ($this->isMobile() && !empty($criticalCSSContent_Mobile)) {
                        $output .= "\r\n" . '<style type="text/css" id="wpc-critical-css" class="wpc-critical-css-mobile">' . $criticalCSSContent_Mobile . '</style>';
                    } elseif (!empty($criticalCSSContent_Desktop)) {
                        $output .= "\r\n" . '<style type="text/css" id="wpc-critical-css" class="wpc-critical-css-desktop">' . $criticalCSSContent_Desktop . '</style>';
                    }

                }
            }
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

        return preg_replace_callback($pattern, function ($match) use ($blockedFonts) {
            $fontFaceBlock = $match[0];

            foreach ($blockedFonts as $blocked) {
                if (stripos($fontFaceBlock, $blocked) !== false) {
                    // Remove this @font-face block
                    return '';
                }
            }

            // Keep this @font-face block
            return $fontFaceBlock;
        }, $critical);
    }

    /**
     * Extract font URLs from critical CSS @font-face blocks and generate preload links.
     * Runs AFTER filterCriticalFontFaces() so we only preload fonts with surviving declarations.
     * The critical CSS API already runs trimUnusedFontFaces() — only above-fold fonts are included.
     *
     * Safeguards:
     * - Max 4 preloads to prevent preload storms
     * - woff2 prioritized (smallest, widest support)
     * - Icon fonts excluded by comprehensive pattern
     * - Data URIs skipped
     * - URLs escaped with esc_url()
     * - Correct MIME type per extension
     * - Deduplication by base URL
     */
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

            $preloadLinks .= '<link rel="preload" href="' . esc_url($fontUrl) . '" as="font" type="' . esc_attr($type) . '" crossorigin="anonymous">' . "\n";
            $loadedFonts[] = $baseUrl;
        }

        return $preloadLinks;
    }

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

    /**
     * Above-the-fold Google-Fonts FOUT fix. A site that leaves its Google Fonts on gstatic (Elementor's
     * default) has its @font-face declarations ONLY in the deferred googleapis stylesheet — never inline —
     * so the critical-CSS preloader (extractCriticalFontPreloads) finds nothing and the heading paints in a
     * fallback, then swaps. This resolves the googleapis CSS server-side, keeps only the ATF families/weights
     * the critical CSS actually uses (latin subset, woff2, font-display:swap), and returns them as an inline
     * <style>. addCritical() prepends it to the critical CSS BEFORE the preloader, so the existing preloader
     * emits the preloads unchanged and the browser has the face at first paint.
     *
     * FPM-safe: the render NEVER fetches googleapis synchronously — it reads a durable transient cache; a cold
     * URL is warmed once POST-response (shutdown, after fastcgi_finish_request), so the render is never blocked.
     * Gated by the existing preload-crit-fonts opt-in. The googleapis <link> is left in place (deferred), so
     * full coverage still loads; we only add the ATF faces + their preloads.
     */
    /**
     * The exact (family, weight) pairs the critical CSS uses above the fold — literal, AND pairs referenced
     * through Elementor Global-Font vars (font-family:var(--e-global-…-font-family) + the matching
     * --…-font-weight), resolved from their definitions (critical CSS + page). Pairing is per rule block, so we
     * cover every above-fold family at the weight it actually uses — never the cross-product of families ×
     * weights (which would preload weights the page doesn't use). Returns [familiesMap, pairsMap("family|weight")].
     */
    private static function atfFontsFromCss($criticalCss, $html = '')
    {
        $cc  = (string) $criticalCss;
        $src = $cc . (is_string($html) ? $html : '');
        // var -> value maps for Elementor Global Fonts (--…-font-family / --…-font-weight), keyed by var name
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
        // Walk each flat rule block; pair its font-family with its font-weight (resolving vars). Elementor pairs
        // family+weight under the same --…-{id}-… key, so a block that sets only the family var still resolves
        // its weight via the matching --…-{id}-font-weight. Default 400 (CSS default) when a block has no weight.
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
                        if (isset($famVar[$vn])) { $fam = $famVar[$vn]; $idWeightVar = preg_replace('/-font-family$/', '-font-weight', $vn); }
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
                // font-style for this block (literal or var; pair via the matching --…-{id}-font-style). Default
                // normal — so we never inline/preload the italic cut of a variable font the page renders upright
                // (that italic cut was both wasted bandwidth AND it crowded the real heading face out of the cap).
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
        // 1) the exact (family,weight) pairs the page uses ATF — the precise set, no cross-product
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

    public function maybeInlineGoogleFontFaces($html, $criticalCss)
    {
        if (empty(self::$settings['preload-crit-fonts']) || self::$settings['preload-crit-fonts'] != '1') return '';
        // Route by how fonts are served. local (self-host): inline the ATF faces from the on-disk cache
        // stylesheets (their woff2 are same-origin + valid). bunny: skip — the end-of-buffer gstatic->bunny.net
        // swap would rewrite inlined gstatic URLs into 404s. Otherwise: the gstatic resolve below.
        $rf = isset(self::$settings['replace-fonts']) ? (string) self::$settings['replace-fonts'] : '';
        if ($rf === 'local') return $this->inlineLocalAtfFaces($criticalCss, $html);
        if ($rf !== '') return '';
        if (!is_string($html) || stripos($html, 'fonts.googleapis.com/css') === false) return '';
        if (!preg_match_all('/<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com\/css[^"\']*)["\']/i', $html, $lm)) return '';
        $urls = array_values(array_unique($lm[1]));

        // ATF families + weights the critical CSS uses above the fold — literal AND families/weights referenced
        // via Elementor Global-Font vars (font-family:var(--e-global-…)), resolved from their --…-font-family /
        // --…-font-weight definitions in the page. Covers every above-fold family, not just the literal primary.
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

        // INLINE every above-the-fold face the critical CSS actually uses — not just 4. font-display:block
        // only holds a weight invisible (instead of flashing a fallback) if THAT weight's @font-face is active
        // at first paint; capping the inline at 4 meant any 5th+ above-the-fold weight fell back then swapped
        // under crit. The set is self-bounding (crit only references visible weights), and PRELOADING is
        // separately capped at 4 in extractCriticalFontPreloads — so we inline-all (block works on every
        // visible weight) but still only preload the top few (no network flood). Generous bound, filterable.
        $atf_inline_cap = (int) apply_filters('wpc_atf_inline_faces_cap', 24);
        $keep = self::pickAtfFaces($faces, $atfFamilies, $atfPairs, $atf_inline_cap);
        if (empty($keep)) return '';

        return '<style id="wpc-gfont-atf">' . implode('', $keep) . '</style>';
    }

    /**
     * Post-response warmer (FPM-safe): fetch each googleapis CSS with a modern-Chrome UA (so Google returns
     * woff2), parse the @font-face blocks, cache them. Runs at shutdown so it never delays the render.
     */
    public static function gfontWarm($urls)
    {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        foreach ((array) $urls as $u) {
            $key = 'wpc_gff_' . md5($u);
            if (get_transient($key) !== false) continue;
            $css = '';
            // Decode entity-encoded ampersands (WP prints hrefs as &#038;), and upgrade a v1 /css? URL that
            // uses wght@ axis syntax to /css2? — the legacy /css endpoint ignores wght@ and would serve the
            // wrong (400) weight. The cache key stays the original $u so the render-side lookup still matches.
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
            if (stripos($raw, '.woff2') === false) continue; // woff2 only
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
            $latin = true; // no unicode-range = covers everything
            if (preg_match('/unicode-range\s*:\s*([^;}]+)/i', $raw, $um)) {
                $latin = (stripos($um[1], 'U+0000') !== false || stripos($um[1], 'U+00-') !== false || stripos($um[1], 'U+0-') !== false);
            }
            // font-display per caller. gstatic warmer → 'swap' (its googleapis faces are display=auto = invisible
            // text, and they're not preloaded). LOCAL faces (inlineLocalAtfFaces) → 'block': they ARE preloaded,
            // so block paints NO fallback — the heading text stays invisible the brief moment until the preloaded
            // face lands, then paints in the real font. swap/auto both paint a fallback first (the shift James kept
            // seeing under crit, because crit paints fast before the face is ready; crit-OFF avoids it only because
            // its render-blocking .css delays paint until the face loads). block makes crit behave like crit-OFF.
            $clean = trim($raw);
            if ($display !== '') {
                if (preg_match('/font-display\s*:/i', $clean)) {
                    $clean = preg_replace('/font-display\s*:\s*[^;]+;?/i', 'font-display:' . $display . ';', $clean, 1);
                } else {
                    $clean = preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $display . ';', $clean, 1);
                }
            }
            $out[] = ['family' => $family, 'weight' => $weight, 'style' => $style, 'latin' => $latin, 'raw' => $clean];
        }
        return $out;
    }

    /**
     * Local self-host FOUT fix (replace-fonts=local). The localized @font-face lives in the deferred cache
     * stylesheet, so under crit the heading FOUTs. Inline the ATF families/weights the critical CSS uses —
     * read from the on-disk cache stylesheets (woff2-only, font-display forced swap) — so the existing
     * preloader (extractCriticalFontPreloads) finds + preloads them and the face is present at first paint.
     * Same-origin cache files: a cheap local read on render (no network); only faces whose woff2 exists on
     * disk are inlined.
     */
    public function inlineLocalAtfFaces($criticalCss, $html = '')
    {
        if (!defined('WPS_IC_FONTS_MAP') || !defined('WPS_IC_FONTS_DIR') || !defined('WPS_IC_FONTS_URL')) return '';
        if (!function_exists('get_option')) return '';

        // ATF families + weights the critical CSS uses above the fold — literal AND families/weights referenced
        // via Elementor Global-Font vars, resolved from their --…-font-family / --…-font-weight definitions in
        // the page. Covers every above-fold family (e.g. a heading font), not just the literal primary.
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
            foreach (self::gfontParseFaces($css, 'block') as $f) {
                if (!self::localFaceWoff2Exists($f['raw'])) continue; // reliability: woff2 must be on disk
                $faces[] = $f;
            }
        }
        if (empty($faces)) return '';

        // INLINE every above-the-fold face the critical CSS actually uses — not just 4. font-display:block
        // only holds a weight invisible (instead of flashing a fallback) if THAT weight's @font-face is active
        // at first paint; capping the inline at 4 meant any 5th+ above-the-fold weight fell back then swapped
        // under crit. The set is self-bounding (crit only references visible weights), and PRELOADING is
        // separately capped at 4 in extractCriticalFontPreloads — so we inline-all (block works on every
        // visible weight) but still only preload the top few (no network flood). Generous bound, filterable.
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
        if (strpos($url, WPS_IC_FONTS_URL) === false) return false; // not in our cache → can't verify → skip
        $path = strtok(str_replace(WPS_IC_FONTS_URL, WPS_IC_FONTS_DIR, $url), '?');
        return is_string($path) && $path !== '' && file_exists($path);
    }

    public function lazyCSS($html)
    {
        // Run only if the marker exists (handles " or ')
        if (!preg_match('/id=(["\'])wpc-critical-css\1/si', $html)) {
            return $html;
        }

        $html = preg_replace_callback('/<link(.*?)>/si', [__CLASS__, 'cssLinkLazy'], $html);
        $html = preg_replace_callback('/(?<!<defs>)<style\b(.*?)<\/style>/si', [__CLASS__, 'cssStyleLazy'], $html);

        return $html;
    }

    public function cssStyleLazy($html)
    {
        $fullTag = $html[0];

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();
        if (empty($criticalCSSExists)) {
            return $fullTag;
        }

        // Not Mobile
        $lazyCss = 'wpc-stylesheet';

        // Keep WPC's own CRITICAL inline styles ACTIVE at first paint — never defer them to wpc-stylesheet
        // (an unknown <style> type the browser ignores until JS "lands" it). Deferring the ATF @font-face
        // block made every above-the-fold weight INERT at first paint → no active Jost face → the browser
        // painted the fallback then swapped to Jost on landing = FOUT, even though every face declared
        // font-display:block. The Elementor entrance-animation start-state must likewise hold at first paint
        // or animated elements flash visible before the reveal. (wpc-gfont-atf covers -atf and -atf-local.)
        if (strpos($fullTag, 'wpc-critical-css') !== false
            || strpos($fullTag, 'wpc-gfont-atf') !== false
            || strpos($fullTag, 'wpc-elementor-anim-start') !== false
            || strpos($fullTag, 'wpc-lazy-thumb-bgfix') !== false) {
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
            return $fullTag;
        }

        // Decide the branch from the OPENING tag only — not the whole block. CSS bodies routinely
        // contain "type=" (e.g. input[type=checkbox]), which would false-positive a strpos($fullTag,...)
        // and route a type-less <style> into the text/css regex below, which then matches nothing and
        // leaves the block undeferred.
        if (preg_match('/<style\b[^>]*\btype\s*=/i', $fullTag)) {
            // Define the regular expression pattern
            $pattern = '/<style(\s*[^>]*)\s+type=("|\')text\/css("|\')([^>]*)>/i';

            // Replace the type attribute in style tags
            $fullTag = preg_replace($pattern, '<style$1 type=\'' . $lazyCss . '\'$4>', $fullTag);
        } else {
            $fullTag = preg_replace('/<style\b/i', '<style type="' . $lazyCss . '"', $fullTag, 1);
        }

        return $fullTag;
    }

    public function cssLinkLazy($html)
    {

        $fullTag = $html[0];

        if (strpos($fullTag, 'preload') !== false || strpos($fullTag, 'prefetch') !== false) {
            return $fullTag;
        }

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();

        if (!empty($_GET['dbgLazyCss0'])) {
            return print_r([$criticalCSSExists], true);
        }

        if (empty($criticalCSSExists)) {
            return $fullTag;
        }

        // Not Mobile
        $lazyCss = 'wpc-stylesheet';

        if (!empty($_GET['dbgLazyCss'])) {
            return print_r([$html], true);
        }

        if (strpos($fullTag, 'wpc-critical-css') !== false) {
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

        if (!empty($_GET['dbgLazyCss2'])) {
            return print_r([$fullTag, self::$excludes_class->criticalCSSExcludes()], true);
        }

        if (self::$excludes_class->strInArray($fullTag, self::$excludes_class->criticalCSSExcludes())) {
            return $fullTag;
        }

        preg_match('/(href)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $fullTag, $href);

        if (!empty($_GET['dbgLazyCss3'])) {
            return print_r([$fullTag, $href], true);
        }

        if (!empty($href[2])) {

            // Lazy load google fonts?
            if (strpos($href[2], 'fonts.googleapis.com/css') !== false) {
                // Google Fonts Hack?
                if (strpos($href[2], 'display=swap') === false) {
                    $newHref = $href[2] . '&display=swap';
                } else {
                    $newHref = $href[2];
                }

                $gfonts = '<link rel="wpc-mobile-stylesheet" href="' . $newHref . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"/>';
                return $gfonts;
            } elseif (strpos($href[2], self::$siteUrl) === false) {
                //Removed in 6.60.39
                //return $fullTag;
                $lazyCss = 'wpc-mobile-stylesheet';
            } else {
                $lazyCss = 'wpc-mobile-stylesheet';
            }
        }

        preg_match('/(rel)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $fullTag, $linkRel);

        if (!empty($_GET['dbgLazyCss4'])) {
            return print_r([$fullTag, $linkRel], true);
        }

        if (!empty($linkRel)) {
            if (!empty($linkRel[2])) {
                $relTag = $linkRel[0]; // rel="stylesheet"
                $relKey = $linkRel[1]; // rel
                $relValue = $linkRel[2]; // stylesheet

                if ($relValue == 'stylesheet') {
                    $newTag = str_replace($relValue, $lazyCss, $relTag);
                    $fullTag = str_replace($relTag, $newTag, $fullTag);
                }
            }
        }

        preg_match('/(type)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $fullTag, $linkType);

        if (!empty($_GET['dbgLazyCss5'])) {
            return print_r([$fullTag, $linkType], true);
        }

        if (!empty($linkType)) {
            if (!empty($linkType[2])) {
                $relTag = $linkType[0]; // type="text/css"
                $relKey = $linkType[1]; // type
                $relValue = $linkType[2]; // text/css

                if ($relValue == 'text/css') {
                    $newTag = str_replace($relValue, 'wpc-text/css', $relTag);
                    $fullTag = str_replace($relTag, $newTag, $fullTag);
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

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_encode') {
            return print_r([$html], true);
        }

        return '[iframe-wpc]' . $html . '[/iframe-wpc]';
    }

    public function iframeDecode($html)
    {
        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_decode') {
            return print_r([$html], true);
        }

        $html = base64_decode($html[1]);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'after_base64_decode') {
            return $html;
        }

        return $html;
    }

    public function scriptContent($html)
    {
        $html = preg_replace_callback('/<script\s[^>]*(?<=type=\"text\/template\")*>.*?<\/script>/is', [__CLASS__, 'scriptContentTag'], $html);

        return $html;
    }

    public function scriptContentTag($html)
    {
        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'script') {
            return print_r([$html], true);
        }

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

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'image_asset_array') {
            return print_r([str_replace('<img', 'sad', $image[0])], true);
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
                // is JPEG enabled
                if (empty(self::$settings['serve']['jpg']) || self::$settings['serve']['jpg'] == '0') {
                    return false;
                }
            }

            // Serve-GIF gate. A GIF NEVER rides the Bunny zone (no next-gen conversion → pure WPC egress
            // on an often-huge file); allowed ONLY on a true CF-direct zone via cf_is_delivery() — NOT
            // zone_is_cf(), which trips on the CF-RAY header for a Bunny-behind-CF origin.
            if (strpos($image, '.gif') !== false) {
                if (empty(self::$settings['serve']['gif']) || self::$settings['serve']['gif'] == '0'
                    || !self::cf_is_delivery()) {
                    return false;
                }
            }

            // Serve PNG Enabled?
            if (strpos($image, '.png') !== false) {
                // is PNG enabled
                if (empty(self::$settings['serve']['png']) || self::$settings['serve']['png'] == '0') {
                    return false;
                }
            }

            // Serve SVG Enabled?
            if (strpos($image, '.svg') !== false) {
                // is SVG enabled
                if (empty(self::$settings['serve']['svg']) || self::$settings['serve']['svg'] == '0') {
                    return false;
                }
            }

            // Images-master gate for .webp/.ico (the only formats with no per-serve-key check above):
            // when the "Images" tile is OFF, stand down image CDN delivery. jpg/gif/png/svg are
            // already gated by their serve keys.
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

            //fix for empty tags
            preg_match_all('/([a-zA-Z_-]+(?:--[a-zA-Z_-]+)*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+)))?/', $image, $matches, PREG_SET_ORDER);

            if (!empty($_GET['dbg_img1'])) {
                return [$image, $matches];
            }

            $attributes = [];
            unset ($matches[0]);

            foreach ($matches as $match) {
                $attrName = $match[1]; // The attribute name
                // Determine the attribute value based on the capturing group that caught it
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

            if (!empty($_GET['dbg_img2'])) {
                return [$image, $attributes];
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

        if (!empty($_GET['dbg_img3'])) {
            return [$image, $image_tags];
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

        if (!empty($_GET['dbgExtract'])) {
            return [$image, $image_tags];
        }

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

        $webp = '/wp:' . self::$webp;
        if (self::isExcludedFrom('webp', $url)) {
            $webp = '';
        }

        $newUrl = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::uForCdn($url);
        $return_tag = str_replace($original_url, $newUrl, $tag);

        if (self::$lazy_enabled) {
            $return_tag .= 'display:none;';
        }

        if (!empty($_GET['dbgBgRep'])) {
            return print_r([$newUrl, self::$apiUrl], true);
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
                        // CSS-bg slideshow has NO fallback (unlike <picture>/<img onerror>), so the emitted
                        // URL must be CDN-served AND universally servable:
                        //   * CF (vary-blind) zone → CLEAN NATURAL SAME-EXT URL (jpeg-only but never-404).
                        //       NEVER a single .webp/.avif (vary-blind webp-only → breaks non-webp browsers).
                        //   * non-CF (Bunny, Vary honored) → NEGOTIATED .webp natural URL (edge negotiates).
                        // NEVER-404 FLOOR: if no clean natural URL can be formed, fall back to the m:0/a:
                        // passthrough (always 200). zone_is_cf() is config-authoritative because request-header
                        // CF detection is blind to a CF-direct CNAME over a non-orange origin.
                        $cf_zone = self::zone_is_cf();
                        foreach ($slides as $i => $slide) {
                            $origin = isset($slide->url) ? (string) $slide->url : '';
                            // m:0/a: passthrough is the always-200 floor.
                            $newSlideUrl = 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($origin);

                            // Build the natural zone URL via the PROVEN host-swap pattern (the <picture>
                            // builder's), NOT uForCdn() (which leaves the origin unrewritten here): strip the
                            // site host and prepend the zone once. WPC_NEGOTIATED_KILL must fully revert this
                            // path to the m:0/a: floor, so skip the whole natural branch under KILL.
                            if ($origin !== '' && self::imageUrlMatchingSiteUrl($origin) && self::$zoneName !== ''
                                && !(defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL)) {
                                $slideSiteHost = rtrim(trailingslashit(site_url()), '/');
                                $cleanOrigin   = preg_replace('/\?.*$/', '', $origin);
                                $natural = (strpos($cleanOrigin, $slideSiteHost) === 0)
                                    ? 'https://' . self::$zoneName . substr($cleanOrigin, strlen($slideSiteHost))
                                    : '';
                                if (is_string($natural) && $natural !== '' && strpos($natural, '/m:') === false) {
                                    // SAME-EXT natural is the format-safe, never-404 default on every zone.
                                    // Route the format decision through the central resolver: promote to a
                                    // negotiated .webp ONLY where proven (Bunny / witnessed-CF), never a
                                    // vary-blind .webp on un-witnessed CF; gif never up-converted (encoder
                                    // often emits no .webp → 404, static webp loses animation); jpeg-ceiling honored.
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
                    $webP = str_replace(['.jpeg', '.jpg', '.png'], '.webp', $image_path);

                    if (!file_exists($webP)) {
                        return $tag;
                    } else {
                        return str_replace(['.jpeg', '.jpg', '.png'], '.webp', $tag);
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

    public function replaceImageTags($html)
    {
        $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [__CLASS__, 'replaceImageTagsDo'], $html);
        return $html;
    }

    public function replaceImageTagsDoSlash($image)
    {
        // leave negotiated-delivery output (data-wpc-nd) untouched (no double-rewrite).
        if (isset($image[0]) && strpos($image[0], 'data-wpc-nd') !== false) {
            return $image[0];
        }

        if (strpos($_SERVER['REQUEST_URI'], 'embed') !== false) {
            return $image[0];
        }

        if (!empty($_GET['dbgAjax']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            // admin-gated: this dumps $_SERVER; it must never answer an anonymous visitor.
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

            // it's placeholder or blank file change something
            foreach ($original_img_tag['original_tags'] as $tag => $value) {
                if ($tag == 'src') {
                    $src = ($preferredSrc) ? $preferredSrc : $value;

                    // per-format/Images-master gate (isImage handles serve[ext] + webp/ico master check):
                    // when the format is off, keep the origin src.
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
            // <source>s is ignored (HTML spec) → browser uses viewport-vw math → ~3-5× over-fetch.
            // loading="lazy" + fetchpriority="high" is supported (Chrome Aug 2023); lazy makes sizes="auto"
            // measure actual CSS width and pick the pixel-perfect rung. Gated on optimize-lcp + lazy-skip
            // range + no existing loading attr; filterable via 'wpc_lcp_lazy'.
            $is_lcp_candidate = (!empty(self::$settings['optimize-lcp'])
                && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages
                && strpos($newImageElement, 'loading=') === false
                && apply_filters('wpc_lcp_lazy', false, isset($original_img_tag['original_tags']['src']) ? $original_img_tag['original_tags']['src'] : ''));
            if ($is_lcp_candidate) {
                $newImageElement .= 'loading="lazy" ';
            }
            $newImageElement .= '/>';
        }

        // Naturalize the single <img> src: this bare-<img>/slash emit path doesn't call the naturalizer
        // the <picture> path does, so single-src transforms here were never naturalized. Runs on the
        // finished (unslashed) tag, before addslashes. No-op when wpc_single_url_natural_prefer is OFF.
        $newImageElement = self::maybe_naturalize_single_src($newImageElement);
        $newImageElement = self::naturalize_svg_src($newImageElement); // same-site SVG → natural zone URL
        $newImageElement = self::activate_lazy_srcset_auto($newImageElement); // native-lazy: inert data-srcset → active srcset (browser self-sizes via auto)
        $newImageElement = self::naturalize_srcset_widths($newImageElement); // each srcset file ↔ its descriptor (no over-fetch)
        // Bare-<img> emit path counterpart of the negotiated/modern auto-sizes — native-lazy only, additive,
        // no-op unless a w-srcset + sizes are present and not already auto. Fixes the small-display over-fetch.
        $newImageElement = self::auto_sizes_for_lazy_img($newImageElement);

        if ($this->checkIsSlashed($image[0])) {
            $newImageElement = addslashes($newImageElement);
        }

        return $newImageElement;
    }

    public function checkIsSlashed($string)
    {
        $pattern = "/\\\\[\"'\\\\]/"; // matches \", \', and \\
        return preg_match($pattern, $string) > 0;
    }

    public function replaceSourceTags($html)
    {
        // Get just the inside of <picture> tag
        //$insideElements = $html[1];

        if (self::$isMobile) {
            // On mobile it can break layouts since we force an image size.
            // w: in cdn url has to match w in srcset attribute if this is removed.
            //$html[0] = preg_replace('/(<(?:source|img)[^>]*)\s+srcset="[^"]*"([^>]*>)/i', '$1$2', $html[0]);

            //todo: above was breaking images without src, only srcset
            // Only remove srcset if src attribute exists.
            // LCP BETA: exempt images with `wpc-lcp-optimized` class. Those carry an
            // intentionally device-INDEPENDENT multi-candidate srcset (buildLcpSrcset)
            // — stripping it on mobile would force a single size and defeat the whole
            // purpose of the feature (cache-poisoning-safe responsive delivery).
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

    public function replaceImageTagsDo($image)
    {
        // leave negotiated-delivery output untouched: when WPC_Negotiated_Delivery is active it has already
        // emitted the final native .webp <img> (marked data-wpc-nd); re-rewriting would double-process.
        if (isset($image[0]) && strpos($image[0], 'data-wpc-nd') !== false) {
            return $image[0];
        }

        // Set up local variables at the beginning - don't modify self:: directly
        $lazyEnabled = self::$lazyEnabled;
        $adaptiveEnabled = self::$adaptiveEnabled;

        //check if relative src and replace with full (may not work for folder installs)
        if (preg_match('/<img[^>]+src="([^"]+)"[^>]*>/i', $image[0], $matches)) {
            $url = $matches[1];

            if (!empty($_GET['dbg_relative'])) {
                $debug = [];
                $debug['step1_extracted_url'] = $url;
                $debug['step2_original_image'] = $image[0];
            }

            if (strpos($url, '/') === 0) {
                $absolute_url = site_url($url);

                if (!empty($_GET['dbg_relative'])) {
                    $debug['step3_absolute_url'] = $absolute_url;
                    $debug['step4_site_url'] = site_url();
                }

                $image_path = ABSPATH . $url;

                if (!empty($_GET['dbg_relative'])) {
                    $debug['step5_image_path'] = $image_path;
                    $debug['step6_file_exists'] = file_exists($image_path) ? 'YES' : 'NO';
                }

                if (file_exists($image_path)) {
                    if (!empty($_GET['dbg_relative'])) {
                        $debug['step7_before_replacement'] = $image[0];
                    }

                    // Replace src attribute specifically
                    $image[0] = preg_replace('/src="' . preg_quote($url, '/') . '"/', 'src="' . $absolute_url . '"', $image[0]);

                    if (!empty($_GET['dbg_relative'])) {
                        $debug['step8_after_src_replacement'] = $image[0];
                    }

                    // Only process srcset if it actually contains relative URLs
                    if (preg_match('/srcset="[^"]*?' . preg_quote($url, '/') . '/', $image[0]) && !preg_match('/srcset="[^"]*?https?:\/\/[^"]*?' . preg_quote($url, '/') . '/', $image[0])) {
                        $image[0] = preg_replace('/srcset="([^"]*?)' . preg_quote($url, '/') . '/', 'srcset="$1' . $absolute_url, $image[0]);
                    }

                    if (!empty($_GET['dbg_relative'])) {
                        $debug['step9_after_srcset_replacement'] = $image[0];
                        return print_r($debug, true);
                    }
                }
            }
        }

        if (strpos($_SERVER['REQUEST_URI'], 'embed') !== false) {
            return $image[0];
        }

        if (!empty($_GET['dbgAjax']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            // admin-gated: this dumps $_SERVER; it must never answer an anonymous visitor.
            return print_r([$_SERVER, wp_doing_ajax(), self::$isAjax, $image[0]], true);
        }

        // Woocommerce ajax load more?
        if (strpos($image[0], 'attachment-woocommerce') !== false) {
            //todo: Images not loaded via ajax also have this class, have to check something else
            //return $image[0];
        }

        if (self::$isAjax) {
            $AjaxImage = $this->ajaxImage($image[0]);
            return $AjaxImage;
        }

        //fixes images not loading in shop pagination on some woo themes
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

        if (strpos($image[0], 'data:image') !== false || strpos($image[0], 'blank') !== false || strpos($image[0], 'gform_ajax_spinner') !== false || strpos($image[0], 'spinner.svg') !== false) {
            return $image[0];
        }

        self::$lazyLoadedImages++;

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

            if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::imageUrlMatchingSiteUrl($image[0])) {
                return $image[0];
            }

        } else {
            // Already has zapwp url, if minify:false/true then it's something
            if (strpos($image[0], 'm:') !== false) {
                return $image[0];
            }
        }

        // Something for cookie??
        if (strpos($image[0], 'cookie') !== false) {
            $image[0] = stripslashes($image[0]);
            return $image[0];
        }


        // Remove fetchpriority attribute
        $image[0] = preg_replace('/\bfetchpriority="[^"]*"\s*/si', '', $image[0]);
        // Remove decoding attribute
        $image[0] = preg_replace('/\bdecoding="[^"]*"\s*/si', '', $image[0]);

        if (!empty(self::$settings['remove-srcset']) && self::$settings['remove-srcset'] == '1') {
            $image[0] = preg_replace('/\bsrcset="[^"]*"\s*/si', '', $image[0]);
            $image[0] = preg_replace('/\bsizes="[^"]*"\s*/si', '', $image[0]);
        }

        // Original URL was
        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);

        if (!empty($_GET['dbg_img'])) {
            return print_r([$image[0], $original_img_tag['original_tags']], true);
        }

        if (!empty($_GET['dbg_src_first'])) {
            return print_r([$original_img_tag['original_tags']['src'], 'empty_space' => strpos($original_img_tag['original_tags']['src'], ' '), 'encoded_space' => strpos($original_img_tag['original_tags']['src'], '%20')], true);
        }

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


        /*
         * Patch for Image Src in JSON
         * data-mk-image-src-set
         */
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

        if (!empty($_GET['dbg_img_src'])) {
            return print_r(['src_is_empty' => empty($original_img_tag['original_tags']['src']), 'data-src_is_empty' => empty($original_img_tag['original_tags']['data-src']), 'data-cp-src_is_empty' => empty($original_img_tag['original_tags']['data-cp-src']), 'src' => $image_source, 'porto-lazy-src' => $original_img_tag['original_tags']['data-oi'], 'tags' => $original_img_tag], true);
        }

        if (!empty($original_img_tag['original_tags']['data-interchange'])) {
            // if this is set then JS parses it and finds the correct url to use, but if we put it on cdn we break the parsing, have to exclude
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
        $source_svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="' . $size[0] . '" height="' . $size[1] . '"><path d="M2 2h' . $size[0] . 'v' . $size[1] . 'H2z" fill="#fff" opacity="0"/></svg>');

        $image_source = $this->specialChars($image_source);

        if (self::$isAmp->isAmp()) {
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
            //fix for Responsive Lightbox & Gallery
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
        if (strpos($lowerClass, 'lgx_app') !== false || strpos($lowerClass, 'dynamic-image') !== false || strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'breakdance') !== false) {
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

        if (!empty($_GET['dbg_logo'])) {
            return print_r([$image_source], true);
        }

        if (!empty($_GET['dbg_tags'])) {
            return print_r([$original_img_tag], true);
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
            // if image is logo, then force image url - no lazy loading
            if ($isLogo) {
                // TODO: This is a fix for logo not being on CDN
                $logoWidth = $this::getCurrentMaxWidth('logo');
                #$logoWidth = 100;

                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $logoWidth . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wps-ic-logo wpc-excluded-adaptive';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else if (self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                // Don't lazy load LCP Fix !!
                // If we loaded less images than skip first variable
                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth('logo') . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wpc-excluded-adaptive wpc-lazy-skipped1';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else {
                if (self::$lazyLoadedImages > self::$lazyLoadedImagesLimit) {
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
        if (self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
            $skipLazy = true;
            $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
            $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
            $original_img_tag['data-count'] = self::$lazyLoadedImages;

            if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
                $original_img_tag['additional_tags']['fetchpriority'] = 'high';
            }

            // BETA: Optimize LCP Images — replace exclude-adaptive behavior with a real
            // device-independent multi-candidate srcset so hero images download at
            // appropriate size per viewport (instead of full original for every device).
            // Gated behind 'optimize-lcp' setting, default off. Safe because:
            //   - Only affects lazy-skipped images (first N)
            //   - Output is identical across devices → works under device-split page caches
            //   - CDN-mode stamps buildLcpSrcset(); local-mode preserves WP-generated srcset
            if (!empty(self::$settings['optimize-lcp'])) {
                $mode = !empty(self::$zoneName) ? 'cdn' : 'local';
                if ($mode === 'cdn') {
                    // CDN mode — device-independent, cache-poisoning-safe LCP image:
                    //  • Multi-candidate srcset on <img> (6-ladder: 400..2560)
                    //  • `sizes="auto, 100vw"` — modern browsers (Chrome 117+, FF 123+, Safari 18.4+)
                    //    auto-calculate the rendered size from actual layout; older browsers
                    //    fall back to `100vw`. This is the true container-aware signal.
                    //  • Fallback `src` at user's configured maxWidth — highest quality for the
                    //    rare browsers that can't use srcset. Value is settings-derived, so
                    //    output is identical across devices → safe under device-split page caches.
                    $fallbackWidth = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                    if ($fallbackWidth < 400) $fallbackWidth = 400; // sanity guard against nonsense settings
                    // cap the legacy <img> fallback src at the source width too (no upscale).
                    $fb_src_w = !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0;
                    if ($fb_src_w > 0 && $fb_src_w < $fallbackWidth) $fallbackWidth = $fb_src_w;
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $fallbackWidth . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['original_tags']['srcset'] = self::buildLcpSrcset($image_source, !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0); // pass <img> intrinsic width so the ladder caps at source (no upscale) even with no attachment meta
                    // Preserve any existing `sizes` attribute (WP-generated ones are typically
                    // more specific and should win). Only add our default if absent.
                    //
                    // For images with a declared `width` attribute smaller than viewport-ish width,
                    // use the width as a sizes hint. This prevents small-container images (logos,
                    // thumbnails) from being picked at 100vw on mobile browsers that don't yet
                    // support `sizes="auto"` (Chrome <117, Safari <18.4).
                    //
                    // `auto` is still listed first so modern browsers use actual layout measurement
                    // (most accurate); the width-hint is a fallback that prevents "logo downloads
                    // at 1600w on retina phone" waste on older browsers.
                    // Override sizes on LCP-optimized IMGs unconditionally (don't gate on empty(sizes)):
                    // WP's emitted "(max-width: <intrinsic>) 100vw, <intrinsic>px" resolves to 100vw on
                    // mobile for wide heroes → 4-5× over-fetch. Use a viewport-aware ladder instead; filter
                    // `wpc_picture_lcp_sizes` lets full-bleed themes restore the conservative 100vw fallback.
                    $imgWidth = !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0;
                    // `auto` is ONLY valid in sizes on loading="lazy" images (HTML spec). The eager LCP hero
                    // is non-lazy, so an `auto,` prefix POISONS it: Chrome treats `sizes="auto, …"` as invalid
                    // and falls back to 100vw → over-fetch. Only prepend `auto,` when the image is actually lazy.
                    $lcp_is_lazy = (bool) apply_filters('wpc_lcp_lazy', false, $image_source);
                    $auto_prefix = $lcp_is_lazy ? 'auto, ' : '';
                    // full-bleed guard: a size-full/alignfull/cover/builder hero's slot is the VIEWPORT, so
                    // the content-width desktop cap would UNDER-fetch (soft LCP at DPR≥2). Keep the tag's own
                    // sizes for those; the override still fixes the 100vw over-fetch for column-constrained LCP.
                    $cls_lcp_rl = !empty($original_img_tag['original_tags']['class']) ? (string) $original_img_tag['original_tags']['class'] : '';
                    if (preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $cls_lcp_rl)) {
                        $new_sizes = '';
                        // baked-ladder scrub: stored content can carry our LEGACY ladder saved into the post;
                        // keeping "the tag's own sizes" would preserve the under-fetch. Width-based swap.
                        if (!empty($original_img_tag['original_tags']['sizes'])
                            && preg_match('/^(?:auto, *)?\(max-width: *600px\) *50vw, *\(max-width: *1024px\) *40vw, *(\d+)px$/i', trim((string) $original_img_tag['original_tags']['sizes']), $m_baked_rl)) {
                            $original_img_tag['original_tags']['sizes'] = ($imgWidth > (int) $m_baked_rl[1])
                                ? '(max-width: ' . $imgWidth . 'px) 100vw, ' . $imgWidth . 'px'
                                : '';
                        }
                    } elseif ($imgWidth > 0 && $imgWidth < 1200) {
                        // Small hero (logos, narrow cards): width-hint fallback
                        $new_sizes = $auto_prefix . '(max-width: ' . $imgWidth . 'px) 100vw, ' . $imgWidth . 'px';
                    } else {
                        // Wide hero: viewport-aware ladder. Desktop tier = the
                        // theme's REAL content width (theme.json / $content_width)
                        // when known — PSI measures the hero's displayed width as
                        // this (TT4 620 / TT5 645), so using it makes desktop pick
                        // ~640w instead of 960w/1200w and clears the image-delivery
                        // diagnostic. Falls back to the 1200 cap when unknown.
                        // Mobile (50vw) + tablet (40vw) tiers left UNCHANGED — they drive the mobile LCP.
                        $maxW_lcp  = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                        $content_w = function_exists('wpc_get_theme_content_width') ? wpc_get_theme_content_width() : 0;
                        $cap_lcp   = $content_w > 0 ? $content_w : min(1200, max(400, $maxW_lcp));
                        // 50vw/40vw tiers (was 80vw) because 80vw overshot on DPR-4 emulation profiles;
                        // overridable via wpc_picture_lcp_sizes filter.
                        $new_sizes = $auto_prefix . '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $cap_lcp . 'px';
                    }
                    $new_sizes = (string) apply_filters(
                        'wpc_picture_lcp_sizes',
                        $new_sizes,
                        $original_img_tag['original_tags'],
                        self::$settings
                    );
                    if ($new_sizes !== '') {
                        $original_img_tag['original_tags']['sizes'] = $new_sizes;
                    }
                    if (function_exists('wpc_diagnostic_log')) {
                        wpc_diagnostic_log('LCP_BETA', 'cdn-mode img#' . self::$lazyLoadedImages . ' src=' . basename(parse_url($image_source, PHP_URL_PATH) ?: $image_source) . ' fallback-w=' . $fallbackWidth);
                    }
                } else {
                    // local mode: leave original_tags['srcset'] untouched — WP's native srcset persists
                    // src is the raw local URL already, not device-dependent, no override needed
                    // but the SIZES ladder is mode-independent: plain mode kept core's 100vw default → the
                    // hero over-fetched. Same computation/filter as the cdn branch; only the sizes attribute
                    // changes — WP's native srcset still does the serving.
                    $imgWidth_l = !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0;
                    $auto_l     = ((bool) apply_filters('wpc_lcp_lazy', false, $image_source)) ? 'auto, ' : '';
                    // full-bleed guard, same as the cdn branch above.
                    $cls_lcp_l = !empty($original_img_tag['original_tags']['class']) ? (string) $original_img_tag['original_tags']['class'] : '';
                    if (preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $cls_lcp_l)) {
                        $new_sizes_l = '';
                        // baked-ladder scrub, same as the cdn branch above.
                        if (!empty($original_img_tag['original_tags']['sizes'])
                            && preg_match('/^(?:auto, *)?\(max-width: *600px\) *50vw, *\(max-width: *1024px\) *40vw, *(\d+)px$/i', trim((string) $original_img_tag['original_tags']['sizes']), $m_baked_l)) {
                            $original_img_tag['original_tags']['sizes'] = ($imgWidth_l > (int) $m_baked_l[1])
                                ? '(max-width: ' . $imgWidth_l . 'px) 100vw, ' . $imgWidth_l . 'px'
                                : '';
                        }
                    } elseif ($imgWidth_l > 0 && $imgWidth_l < 1200) {
                        $new_sizes_l = $auto_l . '(max-width: ' . $imgWidth_l . 'px) 100vw, ' . $imgWidth_l . 'px';
                    } else {
                        $maxW_l2 = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                        $cw_l2   = function_exists('wpc_get_theme_content_width') ? wpc_get_theme_content_width() : 0;
                        $cap_l2  = $cw_l2 > 0 ? $cw_l2 : min(1200, max(400, $maxW_l2));
                        $new_sizes_l = $auto_l . '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $cap_l2 . 'px';
                    }
                    $new_sizes_l = (string) apply_filters('wpc_picture_lcp_sizes', $new_sizes_l, $original_img_tag['original_tags'], self::$settings);
                    if ($new_sizes_l !== '') {
                        $original_img_tag['original_tags']['sizes'] = $new_sizes_l;
                    }
                    if (function_exists('wpc_diagnostic_log')) {
                        wpc_diagnostic_log('LCP_BETA', 'local-mode img#' . self::$lazyLoadedImages . ' src=' . basename(parse_url($image_source, PHP_URL_PATH) ?: $image_source));
                    }
                }
                $original_img_tag['original_tags']['class'] .= ' wpc-lcp-optimized wpc-lazy-skipped3';
                // Don't stamp wpc-data: excluded-adaptive — this image IS adaptive now

                // loading="lazy" alongside fetchpriority="high" so sizes="auto" fires (HTML spec): without
                // lazy, browsers ignore sizes="auto" → viewport-vw math → over-fetch. The combo is supported
                // (Chrome Aug 2023). Off by default; filterable via 'wpc_lcp_lazy' for sites that want it.
                if (apply_filters('wpc_lcp_lazy', false, $image_source)) {
                    $original_img_tag['additional_tags']['loading'] = 'lazy';
                }
            } else {
                #$original_img_tag['original_tags']['srcset'] = $this->rewriteSrcset($original_img_tag, $original_img_tag['original_tags']['srcset']);
                $original_img_tag['original_tags']['class'] .= ' wpc-excluded-adaptive wpc-lazy-skipped3';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
            }
            unset($original_img_tag['additional_tags']['data-wpc-loaded'], $original_img_tag['original_tags']['data-src'], $original_img_tag['data-src']);
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


        if (!empty($_GET['dbg_tag'])) {
            return print_r(['$isLogo' => $isLogo, 'skipLazy' => $skipLazy, 'adaptiveEnabled' => $adaptiveEnabled, '$lazyLoadedImages' => self::$lazyLoadedImages, '$lazyLoadedImagesLimit' => self::$lazyLoadedImagesLimit, '$lazyEnabled' => $lazyEnabled, '$nativeLazyEnabled' => self::$nativeLazyEnabled, '$isSlider' => $isSlider, '$original_img_tag' => $original_img_tag], true);
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

        // LCP BETA: if this image was just stamped with wpc-lcp-optimized, our
        // buildLcpSrcset() output is already in original_tags['srcset']. Do NOT run it
        // through rewriteSrcset() because that function bails to empty on mobile (device-
        // split cache safety) — and that would erase our device-independent multi-candidate
        // ladder right after we built it. Skip to preserve our output.
        $isLcpOptimized = !empty(self::$settings['optimize-lcp'])
            && strpos($original_img_tag['original_tags']['class'], 'wpc-lcp-optimized') !== false;

        if (!$isLcpOptimized && !self::$excludes_class->isAdaptiveExcluded($image_source, $original_img_tag['original_tags']['class'])) {
            $original_img_tag['original_tags']['srcset'] = $this->rewriteSrcset($original_img_tag, $original_img_tag['original_tags']['srcset']);
            //here
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
            // TODO: Added 23.11.2025 - mozda sjebe lazy load?
            // TODO: Maknuto, bilo je problema
            // unset($original_img_tag['additional_tags']['data-wpc-loaded']);

            $original_img_tag['src'] = $image_source;

            // PICTURE-TIER next-gen for excluded-adaptive images: adaptive-exclusion means "no responsive
            // RESIZING", it must NOT also kill avif/webp. This else-branch parked src at the ORIGIN host with
            // no /wp: token, so the <picture> wrap gate (needs '/wp:1/' OR a zone-hosted natural src) failed →
            // bare <img>, zero next-gen. Fix: on the PICTURE tier only, host-swap src to its clean NATURAL
            // same-ext ZONE URL (no width, NO responsive srcset) so the single-src <source> arms run. The
            // builders self-gate on a transcodable source + never-404 witness; <img> keeps its onerror→origin
            // net. Any miss leaves the origin src unchanged. Kill switch: wpc_excluded_adaptive_nextgen (default on).
            if ((self::$pictureWebpEnabled || self::$pictureAvifEnabled)
                && self::$zoneName !== ''
                && (bool) apply_filters('wpc_excluded_adaptive_nextgen',
                        (bool) (function_exists('get_option') ? get_option('wpc_excluded_adaptive_nextgen', 1) : 1))) {
                $ea_clean = preg_replace('/\?.*$/', '', (string) $image_source);
                $ea_path  = (string) wp_parse_url($ea_clean, PHP_URL_PATH);
                $ea_ohost = (string) wp_parse_url($ea_clean, PHP_URL_HOST);
                // PATH-AGNOSTIC base + host match: a hardcoded uploads base missed offloaded /storage
                // page-builder images (real media, not under /wp-content/uploads). Reuse the media-base
                // catalog the single-src naturalizer trusts (wpc_v2_upload_base_paths()) and accept the site
                // host OR an already-zone-hosted src. Host-swap stays SAME-EXT, NO width (never-404).
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
        // without lazy, sizes="auto" on the picture <source>s is ignored (HTML spec) → over-fetch.
        // lazy + fetchpriority="high" is supported (Chrome Aug 2023). Filterable via 'wpc_lcp_lazy'.
        if (!empty(self::$settings['optimize-lcp'])
            && strpos((string) $original_img_tag['original_tags']['class'], 'wpc-lcp-optimized') !== false
            && apply_filters('wpc_lcp_lazy', false, $image_source)) {
            // Prevent duplicate loading= when the inline branch above already added it
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
            unset($original_img_tag['original_tags']['srcset'], $original_img_tag['original_tags']['data-srcset']);
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

        // add data-src
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
            /*
                 * if data-src is not empty then we have src as SVG
                 */
            if (!empty($lazyEnabled) && $lazyEnabled == '1') {
                $build_image_tag .= 'src="' . $original_img_tag['src'] . '" ';

                if (!empty($original_img_tag['data-src'])) {
                    $build_image_tag .= 'data-src="' . $original_img_tag['data-src'] . '" ';
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
                        // skip 'alt' here; it's emitted once explicitly below (avoids a duplicate alt attr).
                        continue;
                    } elseif (!empty($value)) {
                        $build_image_tag .= $tag . '="' . esc_attr($value) . '" '; // escape (was raw → broken-markup/XSS on a quote in data-*/title)
                    } else {
                        $build_image_tag .= $tag . ' ';
                    }
                }
            }
        }

        if (strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'lgx_app') !== false || strpos($lowerClass, 'dynamic-image') !== false || strpos($lowerClass, 'rs') !== false) {
            unset($original_img_tag['additional_tags']['data-wpc-loaded']);
        }

        // foreach additional image tag
        foreach ($original_img_tag['additional_tags'] as $tag => $value) {
            if ($tag == 'class') {
                $tag = 'class';

                if (strpos($lowerClass, 'rs-lazyload') !== false || strpos($lowerClass, 'rs') !== false || (strpos($lowerClass, 'lazy') !== false && strpos($lowerClass, 'skip-lazy') === false)) {
                    // Leave as is
                    $value = $original_img_tag['original_tags']['class'];
                } else {
                    $value .= ' ' . $original_img_tag['original_tags']['class'];
                }
            }

            if ($tag == 'src' || $tag == 'data-src' || $tag == 'data-mk-image-src-set' || empty($value) || $tag == 'data-prehidden') {
                continue;
            }

            // Check if tag already exists, if so - replace it
            $value = trim($value);
            if (!empty($value)) {
                $build_image_tag .= $tag . '="' . esc_attr($value) . '" '; // escape (was raw)
            }
        }

        if (empty($original_img_tag['original_tags']['alt'])) {
            $original_img_tag['original_tags']['alt'] = '';
        }

        $build_image_tag .= 'alt="' . esc_attr($original_img_tag['original_tags']['alt']) . '" '; // escape

        $build_image_tag .= '/>';

        // drop-wp single-<img> src (flag wpc_single_url_natural_prefer, DEFAULT OFF): rewrite a no-store
        // /wp:N/ transform src → its CF-cacheable NATURAL uploads URL when provably safe (witness + sub-size
        // + on-disk). Runs BEFORE the natural detection below so an already-natural src flows into the wrap.
        $build_image_tag = self::maybe_naturalize_single_src($build_image_tag);
        $build_image_tag = self::naturalize_svg_src($build_image_tag); // same-site SVG → natural zone URL
        $build_image_tag = self::activate_lazy_srcset_auto($build_image_tag); // native-lazy: inert data-srcset → active srcset (browser self-sizes via auto)
        $build_image_tag = self::naturalize_srcset_widths($build_image_tag); // each srcset file ↔ its descriptor (no over-fetch)

        // Legacy/local (CDN-off + non-negotiated) sizes="auto" — same treatment as the negotiated <img> and
        // modern <picture> paths, so over-fetch is fixed on EVERY config. Native-lazy only (sliders/LCP are
        // eager → excluded); additive; no-op without a w-srcset/sizes or if already auto. A <picture> wrapped
        // downstream derives its <source> sizes from this <img>'s sizes, so the auto propagates to sources.
        $build_image_tag = self::auto_sizes_for_lazy_img($build_image_tag);

        // Wrap in <picture> for bulletproof WebP delivery
        // LOGO / natural reachability. The wrap gate keyed on the /wp:1/ webp-token, which a NATURALIZED
        // single-src <img> (logo whose src is already the clean zone .webp URL) no longer carries → bare
        // <img>, no <picture>. Also admit a single-src <img> whose src is an already-natural same-zone
        // uploads URL: the single-src webp/avif branches below self-gate on the bare-full witness +
        // dims-ok, so a non-witnessed zone emits NO bad <source> and the natural <img> fallback carries —
        // never broken. Narrow/additive: NO /wp: token AND same-zone host AND under a media root. Honor ALL
        // upload bases (config, not disk) so offloaded /storage natural srcs wrap too. Memoised per request.
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
        // NEVER admit a LAZY <img> (data-src / data-wpc-loaded='true') into the <picture> wrap via EITHER
        // branch: a lazy <img> carries src="data:…svg" placeholder + data-src=real; inside a <picture> the
        // eager <source>s win and the runtime src<-data-src swap is stranded → placeholder images served.
        // (Must guard BOTH branches: a lazy tag also carries a /wp:1/ URL in data-wpc-fb.) The wrap is for
        // NON-lazy single-src images (logos / preserve-size); lazy imgs stay plain with onerror as fallback.
        $wpc_img_is_lazy = (strpos($build_image_tag, 'data-src=') !== false)
            || (strpos($build_image_tag, 'data-wpc-loaded="true"') !== false)
            || (strpos($build_image_tag, "data-wpc-loaded='true'") !== false);
        // RASTER-GATED lazy <picture> re-enable. All lazy <img> were unwrapped because a webp-origin lazy
        // image's -WxH sources 404 (a CF-direct edge can't OTF a webp -WxH → hard broken, no <img>
        // fall-through between <source>s). But jpg/png/jpeg sources DO OTF-resize natural -WxH (incl .avif),
        // so a RASTER lazy <img> can wrap safely: both its <source>s are never-404 and the lazy JS swaps
        // data-srcset on view. A webp-origin lazy <img> now wraps too, under wpc_natural_nw (default ON) or
        // wpc_webp_otf_ready — the edge OTF-transcodes webp → -Nw.{webp,avif} and the floor never-404s, so
        // the old -WxH-webp-404 reason is gone. Filter wpc_lazy_raster_picture off → revert to unwrapped.
        $wpc_img_otf_source = ((bool) preg_match('/\.(jpe?g|png)(\?|#|$)/i', (string) $image_source)
                || ((self::wpc_webp_otf_ready() || self::wpc_natural_nw()) && (bool) preg_match('/\.webp(\?|#|$)/i', (string) $image_source)))
            && (bool) apply_filters('wpc_lazy_raster_picture', true);
        $wpc_lazy_blocks_wrap = $wpc_img_is_lazy && !$wpc_img_otf_source;
        $wpc_natural_img_src = (strpos($build_image_tag, '/wp:') === false)
            && (self::$zoneName !== '' && strpos($build_image_tag, 'https://' . self::$zoneName . '/') !== false)
            && $wpc_nat_in_base
            && !$wpc_lazy_blocks_wrap;
        if (self::$pictureWebpEnabled && !$wpc_lazy_blocks_wrap && (strpos($build_image_tag, '/wp:1/') !== false || $wpc_natural_img_src)) {
            $lowerSrc = strtolower($image_source);
            $skipFormats = (strpos($lowerSrc, '.svg') !== false
                         || strpos($lowerSrc, '.gif') !== false
                         || strpos($lowerSrc, '.ico') !== false);

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
                        // single-src (no srcset: logo/icons, w:1 preserve). The webp rebuilders below run
                        // only WITH a srcset, so a single-src webp <source> kept the raw /wp:1/ transform —
                        // which the edge does NOT transcode on these single-src PNG icons (serves PNG to a
                        // webp Accept). When the BARE-FULL webp witness holds, swap to the clean natural .webp
                        // zone URL (CF-cacheable); on a non-witnessed zone keep the wp:1 transform (never 404s).
                        $singleWebpSrc = $srcMatch[2];
                        if (self::picture_webp_natural_full_ok()
                            && !empty($image_source)
                            && strpos($singleWebpSrc, '/wp:') !== false) {
                            $cleanWebpSingle = preg_replace('/\?.*$/', '', $image_source);
                            $natWebpSingle   = preg_replace('/\.(jpe?g|png|avif)$/i', '.webp', $cleanWebpSingle);
                            $webpSiteHostS   = rtrim(trailingslashit(site_url()), '/');
                            if (strpos($natWebpSingle, $webpSiteHostS) === 0) {
                                $singleWebpSrc = 'https://' . self::$zoneName . str_replace($webpSiteHostS, '', $natWebpSingle);
                            }
                        }
                        $sourceSrcset = ' ' . $srcAttrName . '="' . $singleWebpSrc . '"';
                    }
                }

                // Extract sizes (required by spec when srcset uses width descriptors)
                $sourceSizes = '';
                if (preg_match('/sizes="([^"]*)"/', $build_image_tag, $sizesMatch)) {
                    $sizes_value = $sizesMatch[1];
                    // Prepend `auto,` so modern browsers measure the actual rendered width instead of WP's
                    // intrinsic-width sizes (which over-fetch on retina). Non-supporting browsers ignore it;
                    // filter-gated. But NOT for the eager LCP hero: `auto` is only valid on loading="lazy"
                    // images, and on the eager LCP <img> it poisons the <source> sizes (Chrome → 100vw →
                    // over-fetch). The <source> sizes must mirror the auto-free <img> sizes. Lazy/regular keep auto.
                    $is_eager_lcp = (stripos($build_image_tag, 'wpc-lcp-optimized') !== false)
                        && (stripos($build_image_tag, 'loading="lazy"') === false);
                    $auto_enabled = (bool) apply_filters('wpc_v2_sizes_auto_prefix', true);
                    if ($auto_enabled && !$is_eager_lcp && stripos($sizes_value, 'auto') === false) {
                        $sizes_value = 'auto, ' . $sizes_value;
                    }
                    $sourceSizes = ' sizes="' . $sizes_value . '"';
                }

                // AVIF source — locally generated, served as asset via CDN (no MC processing).
                // Optimistic emission when lazy_cdn is enabled: gating purely on file_exists is a
                // chicken-and-egg (no AVIF <source> → browser never requests it → CDN never cache-misses →
                // lazy_cdn never fires → file never lands). So emit unconditionally; on a 404 the CDN falls
                // back to WebP (no broken image), worst case a one-time WebP serve while it encodes.
                $avifSource = '';
                if (self::$pictureAvifEnabled && !empty($image_source)) {
                    $cleanSource = preg_replace('/\?.*$/', '', $image_source);
                    $avifUrl = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $cleanSource);
                    $avifSiteUrl = trailingslashit(site_url());
                    $avifPath = str_replace($avifSiteUrl, trailingslashit(ABSPATH), $avifUrl);

                    // WEBP-NATIVE FLOOR (never-404). The edge OTF derives a typed .avif from a JPG/PNG source;
                    // a webp-native (or gif) upload has NO jpg/png base, so a typed -WxH.avif <source> 404s —
                    // and a <picture> <source> 404 is a BROKEN IMAGE (no <img onerror> fall-through between
                    // sources). So unless a REAL .avif is already on disk (file_exists below, legacy-legit),
                    // only OPEN the AVIF block when the source is actually transcodable. Discriminator is O(1):
                    // the attachment mime, or the src extension when there's no wp-image-N class. A webp/gif/
                    // unknown source SUPPRESSES the AVIF <source> so the .webp ladder + <img> carry. Default ON.
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
                        if ($avif_tc_mime !== '') {
                            // image/jpg is non-canonical but real in the wild (some importers / older WP);
                            // accept it so a legitimate JPEG isn't silently downgraded off AVIF.
                            $avif_src_transcodable = in_array($avif_tc_mime, ['image/jpeg', 'image/jpg', 'image/png'], true)
                                || ((self::wpc_webp_otf_ready() || self::wpc_natural_nw()) && $avif_tc_mime === 'image/webp'); // edge OTF-transcodes webp→avif (E1)
                        } else {
                            // No resolvable attachment mime → trust the source extension (jpg/png only).
                            $avif_src_transcodable = (bool) preg_match('/\.(jpe?g|png)$/i', $cleanSource)
                                || ((self::wpc_webp_otf_ready() || self::wpc_natural_nw()) && (bool) preg_match('/\.webp$/i', $cleanSource)); // edge OTF-transcodes webp→avif (E1)
                        }
                    }

                    // source ext for the optional ?src= hint (best-effort, from the attachment mime; computed
                    // only when the toggle is on). Empty for a no-attachment <img> → no hint → CDN probes.
                    $src_hint_ext = '';
                    if (self::src_hint_enabled()) {
                        $sh_att = 0;
                        if (!empty($original_img_tag['original_tags']['class'])
                            && preg_match('/\bwp-image-(\d+)\b/', (string) $original_img_tag['original_tags']['class'], $sh_m)) {
                            $sh_att = (int) $sh_m[1];
                        }
                        $sh_mime = ($sh_att > 0 && function_exists('get_post_mime_type')) ? (string) get_post_mime_type($sh_att) : '';
                        if ($sh_mime === 'image/png') $src_hint_ext = 'png';
                        elseif ($sh_mime === 'image/jpeg' || $sh_mime === 'image/jpg') $src_hint_ext = 'jpg';
                        // No attachment mime (page-builder /storage + offloaded media — the BULK of the probe
                        // storm on these sites): fall back to the ORIGINAL source URL's extension. The plugin
                        // built this natural URL FROM that source, so its ext is the authoritative format the
                        // edge should fetch+transcode — this is what extends ?src from wp-image-N attachments
                        // to EVERY image, so the edge stops probing jpg/jpeg/png/webp and issues ~0 misses.
                        // 'avif' is excluded (never a transcode source) so a stray output URL can't mis-hint.
                        if ($src_hint_ext === '') {
                            $sh_src = !empty($image_source) ? (string) $image_source
                                : (!empty($original_img_tag['src']) ? (string) $original_img_tag['src'] : '');
                            if ($sh_src !== '') {
                                $sh_ux = strtolower((string) pathinfo((string) parse_url($sh_src, PHP_URL_PATH), PATHINFO_EXTENSION));
                                if ($sh_ux === 'jpeg') $sh_ux = 'jpg';
                                if (in_array($sh_ux, ['jpg', 'png', 'webp', 'gif'], true)) $src_hint_ext = $sh_ux;
                            }
                        }
                    }

                    // Optimistic gate when lazy_cdn is enabled for this zone.
                    $optimistic_avif = function_exists('wpc_v2_get_lazy_enabled')
                                       && wpc_v2_get_lazy_enabled();

                    // OTF-NATURAL REACHABILITY. The outer gate required a local-disk .avif OR lazy_cdn. On a
                    // CDN-on zone where the edge OTF-encodes a natural .avif by Accept (witness-proven via
                    // picture_avif_natural_ok()) neither holds, so the whole AVIF block was skipped despite the
                    // edge serving a clean natural .avif. Hoist that witness here and widen the gate to it; on a
                    // non-OTF/un-witnessed zone the witness is false → byte-identical to before.
                    $avif_otf_live = self::picture_avif_natural_ok();

                    // WEBP-NATIVE FLOOR (inner per-rung). The outer gate can still OPEN this block via the
                    // ungated on-disk file_exists. For a NON-transcodable (webp-native) source the edge can OTF
                    // neither a natural -WxH nor a wp:2 .avif for a missing rung, so collapse all three per-rung
                    // OTF signals — leaving only genuinely on-disk rungs to emit. Makes the floor STRUCTURAL;
                    // narrowing-only (transcodable source byte-identical; worst case drops an avif rung).
                    if (!$avif_src_transcodable) {
                        $optimistic_avif = false;
                        $avif_otf_live   = false;
                    }
                    $avif_emit_natural = self::picture_avif_emit_natural() && $avif_src_transcodable;

                    // AVIF <source> must be PRESENT whenever AVIF is the active ceiling on a CDN-on zone, even
                    // with Smart Delivery OFF + Safe preset + no on-disk .avif (all three terms above false →
                    // block was dropped). $pictureAvifEnabled already encodes ceiling==='avif' && cdn_images_on,
                    // so this 4th term only REACHES the block where AVIF is contractually expected; the inner
                    // arms emit natural -WxH where edge-servable (never-404 preserved by the -WxH classification:
                    // -Nw/non-WxH falls to wp:2, bare-full stays on the witness). KILL-guard it so
                    // WPC_NEGOTIATED_KILL fully reverts to legacy. file_exists left ungated (on-disk = legacy-legit).
                    $avif_ceiling_on = (self::$pictureAvifEnabled === true) && (self::$zoneName !== '')
                        && !(defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL);
                    // The OTF/optimistic/ceiling terms (all leaning on the edge OTF making a typed .avif) are
                    // ANDed with $avif_src_transcodable: on a webp-native origin the edge can't make avif, so
                    // without a real on-disk .avif the block stays CLOSED and the WebP ladder carries (never-404).
                    if (file_exists($avifPath) || (($optimistic_avif || $avif_otf_live || $avif_ceiling_on) && $avif_src_transcodable)) {
                        // Hybrid emission per srcset entry: on-disk → natural URL via CDN passthrough
                        // (edge-cacheable static asset); missing → CDN wp:2 transform (webp placeholder now,
                        // encodes AVIF async, real AVIF on later loads).
                        $avifZoneBase = 'https://' . self::$zoneName;
                        $avifSiteHost = rtrim($avifSiteUrl, '/');

                        // When wpc_v2_lazy_cdn_use_original is ON, emit the un-scaled original as the CDN `u:`
                        // param so the orchestrator re-encodes from highest quality (avoids WP's q82 -scaled.jpg
                        // double-compression). Default ON; fallback chain handles a deleted original.
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

                        // Over-fetch guard inputs. A size-full <img> can carry a srcset entry pointing at the
                        // FULL-SIZE original (not a registered sub-size) with a small width descriptor → emitting
                        // the full-size .avif at that descriptor ships a huge AVIF for a small slot. The per-rung
                        // handler below replaces it with a correctly-sized natural .avif or drops the rung.
                        $avif_meta_nw  = ($avif_attachment_id > 0 && function_exists('wp_get_attachment_metadata'))
                            ? wp_get_attachment_metadata($avif_attachment_id)
                            : false;
                        $avif_native_w = (is_array($avif_meta_nw) && !empty($avif_meta_nw['width']))
                            ? (int) $avif_meta_nw['width']
                            : 0;
                        $avif_native_h = (is_array($avif_meta_nw) && !empty($avif_meta_nw['height'])) // height for the dims-validity gate
                            ? (int) $avif_meta_nw['height']
                            : 0;

                        // ASPECT FALLBACK for no-meta images (no wp-image-N class → native dims 0). The adaptive
                        // ladder needs a W:H ratio for the suffix helper to form a real -WxH (edge OTF-resizable)
                        // instead of degrading to the DEAD -Nw form. Derive the ratio from: attachment meta →
                        // native dims → the first -WxH entry in the WP srcset (the edge resizes by WIDTH; height
                        // is a ±1px-tolerant identifier).
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
                        }
                        // CEILING CAP source width: the largest width the ladder may emit WITHOUT upscaling the
                        // OTF base. An above-source -WxH upscales → the edge serves a no-store image/webp (not a
                        // cacheable avif). Native dims when known, else the derived aspect width. Shared by avif + webp.
                        $avif_src_w_cap = ($avif_native_w > 0) ? $avif_native_w
                            : ((is_array($avif_aspect_meta) && !empty($avif_aspect_meta['width'])) ? (int) $avif_aspect_meta['width'] : 0);

                        // Picture-builder LAND parity. The nd builder queues every sized AVIF width it emits so
                        // a real -WxH.avif lands behind it; this builder emitted the same sized URLs but never
                        // queued them → rungs stayed edge interims. Queue them (additive, markup byte-identical).
                        // wpc_v2_sized_trigger_queue is fully self-guarded (can't over-generate). Confidence-gated
                        // (skip full-bleed/builder layouts where the slot width is unknowable); kill: wpc_picture_land_widths.
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
                            if ($avif_native_w > 0 && $w >= $avif_native_w) return; // helper also caps; cheap pre-skip
                            wpc_v2_sized_trigger_queue($avif_attachment_id, $w, $w);
                        };

                        // AVIF OTF-live witness: when true, the edge OTF-creates a natural -WxH.avif at EVERY
                        // width by filename suffix (resize-by-suffix + encode-by-Accept), so a clean natural
                        // .avif is deliverable at every rung regardless of $optimistic_avif; the per-rung natural
                        // branches widen to ($optimistic_avif || $avif_otf_live). Picture-scoped: inside
                        // <picture> the source is format-pinned (no vary poison) and the edge OTF-creates any
                        // -WxH on the fly (no 404/strand), so natural is safe on EVERY zone. When false, each
                        // rung keeps its conservative fallback (wp:2 / drop). ($avif_otf_live hoisted above the gate.)

                        if (self::wpc_natural_nw()) {
                            // CONVERGED -Nw AVIF <source>. E2: the edge OTF-resizes a clean -Nw.avif at
                            // every width from any jpg/png/jpeg/webp base; E3's base-interim floor never-404s
                            // a cold rung. No metadata/aspect/on-disk/wp:2 machinery — wpc_nw_widths covers
                            // both the WP srcset (descriptors) and a no-srcset page-builder <img> (synthetic
                            // ladder; sizes=auto lets the browser pick). Supersedes the -WxH/wp:2 block below.
                            $avif_nw_entries = [];
                            foreach (self::wpc_nw_widths($original_img_tag, $avif_src_w_cap) as $nw_w) {
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $nw_w . 'w';
                                $avif_queue_w($nw_w);
                            }
                            if (empty($avif_nw_entries)) {
                                // unknown native → full-size natural only (edge serves at native; no upscale → no 302/404)
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
                                    $srcUrl = preg_replace('/\?.*$/', '', $m[1]);
                                    $descriptor = $m[2];
                                    $avifSrcUrl = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $srcUrl);
                                    $avifSizePath = str_replace($avifSiteUrl, trailingslashit(ABSPATH), $avifSrcUrl);

                                    // DIMS-VALIDITY: a full-size on-disk .avif can be dimensionally corrupt
                                    // (service mis-encode). The over-fetch guard below is WIDTH-only, so it
                                    // misses a height-corrupt file whose width descriptor matches native. Drop
                                    // the rung if provably corrupt (fail-safe KEEP on undecodable hosts).
                                    if (@file_exists($avifSizePath)
                                        && !self::picture_variant_dims_ok($avifSizePath, $avif_native_w, $avif_native_h)) {
                                        continue;
                                    }

                                    if (@file_exists($avifSizePath)) {
                                        // descriptor-truth + over-fetch handling. A srcset entry whose source
                                        // file is the FULL-SIZE original but carries a sub-native WIDTH descriptor
                                        // would emit the intrinsic-size .avif at a small slot. Handle that lie:
                                        //  - WIDTH-only descriptor: density "2x"/"1.5x" + bare
                                        //    descriptors must pass through untouched (not over-fetch).
                                        $is_width_desc = (bool) preg_match('/^(\d+)w$/', trim((string) $descriptor), $avif_dm);
                                        $desc_w_of     = $is_width_desc ? (int) $avif_dm[1] : 0;
                                        //  - FULL-SIZE = the source file is NOT a registered sub-size.
                                        //    A -WxH filename alone is insufficient (design-tool exports
                                        //    like hero-1920x1080.png are full-size yet match it), so
                                        //    cross-check the attachment's sizes[] metadata by basename.
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
                                                ? wpc_v2_adaptive_variant_suffix($desc_w_of, $avif_aspect_meta) // aspect fallback (no-meta → -WxH not -Nw)
                                                : '';
                                            // NEVER-404: require the -WxH suffix form, not merely a non-empty
                                            // suffix. The suffix helper DEGRADES to the legacy "-{W}w" form when
                                            // the meta has no usable aspect, and -{W}w is the plugin's own
                                            // write-side DEAD/404 scheme the edge does NOT OTF-resize → a typed
                                            // -Nw.avif <source> would 404. So gate on -WxH; a degraded -Nw drops to #2.
                                            $avif_sized_wxh = (bool) preg_match('/-\d+x\d+$/', $avif_sized_suffix);
                                            if (($avif_emit_natural || $avif_otf_live) && $avif_sized_wxh) {
                                                // #3 — emit a natural SIZED .avif at the descriptor width: the
                                                // edge OTF-resizes by the -WxH suffix + self-heals to the real
                                                // sized file once it lands (CF-cacheable). Replaces the oversized
                                                // full. emit-natural lets an un-witnessed ceiling-on zone reach
                                                // this; $avif_sized_wxh is the -WxH never-404 gate (a degraded
                                                // -Nw would 404 — the edge OTF is proven for -WxH only → #2 drop).
                                                $avif_base_no_ext = preg_replace('/\.avif$/i', '', $avifSrcUrl);
                                                $avif_sized_url   = $avif_base_no_ext . $avif_sized_suffix . '.avif';
                                                // name-stable handoff: reconcile the -WxH name to the actual
                                                // on-disk file so the SAME URL flips from edge interim to 200 once
                                                // it lands; input unchanged on no match.
                                                list($avif_sized_url, ) = self::recoverAdaptiveVariant($avif_sized_url, $avif_base_no_ext, $desc_w_of, 'avif');
                                                $avifEntries[]    = $avifZoneBase . str_replace($avifSiteHost, '', $avif_sized_url) . ' ' . $descriptor;
                                                $avif_queue_w($desc_w_of); // land this #3 sized width
                                            } else {
                                                // #2 — natural sized not available on this zone (CF
                                                // without wpc_v2_cf_avif_live, or no meta): DROP just
                                                // this oversized rung. The remaining sized rungs + the
                                                // universal ladder still build a correctly-sized AVIF
                                                // source; if this was the only entry, $avifEntries
                                                // stays empty and the deep-enough guard below suppresses
                                                // the source so the WebP ladder carries.
                                                continue;
                                            }
                                        } else {
                                            // Registered sub-size, a genuine native-width full rung, or
                                            // a density/bare descriptor → emit the natural URL as-is.
                                            // re-assert dims validity at the emit site (self-protects under
                                            // refactors). No OTF-swap here: swapping a valid native rung to a
                                            // sized-OTF URL risks the bare-OTF 404 on an unconverged zone.
                                            if (@file_exists($avifSizePath)
                                                && !self::picture_variant_dims_ok($avifSizePath, $avif_native_w, $avif_native_h)) {
                                                continue;
                                            }
                                            $pathPart = str_replace($avifSiteHost, '', $avifSrcUrl);
                                            $avifEntries[] = $avifZoneBase . $pathPart . ' ' . $descriptor;
                                        }
                                    } elseif ($optimistic_avif || $avif_otf_live || $avif_emit_natural) {
                                        // widened ENTRY: emit-natural lets a ceiling-on un-witnessed zone REACH
                                        // the inner -WxH decision (else NO AVIF source at all); the inner gate
                                        // still confines natural to -WxH, optimistic-but-not-OTF-live falls to the
                                        // wp:2 transform (never 404s). Use the un-scaled original as `u:` when
                                        // wpc_v2_lazy_cdn_use_original is ON, and rewrite the `u:` host to the
                                        // cdn-zone so CDN fetches via its own passthrough (avoids origin WAF block).
                                        $width = (int) preg_replace('/[^\d]/', '', (string) $descriptor);
                                        if ($width <= 0) $width = 1;
                                        $u_src = $avif_original_u_url !== '' ? $avif_original_u_url : $srcUrl;
                                        $u_src_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src);
                                        // NEVER-404: emit a natural .avif <source> only when safe. -WxH → edge
                                        // OTF resize-by-suffix (rides emit-natural on all ceiling-on zones; a
                                        // typed <source> is self-selected so vary-blindness doesn't apply).
                                        // bare-full → the STRICTER _full_ok witness (CDN bare-OTF 404 bug). The
                                        // legacy -Nw form is the write-side DEAD/404 scheme → falls to wp:2.
                                        $main_is_wxh       = (bool) preg_match('/-\d+x\d+\.avif$/i', $avifSrcUrl);
                                        $main_is_nw        = (bool) preg_match('/-\d+w\.avif$/i', $avifSrcUrl);
                                        $main_is_bare_full = !$main_is_wxh && !$main_is_nw;
                                        $main_emit_natural = ($main_is_wxh && $avif_emit_natural)
                                            || ($main_is_bare_full && self::picture_avif_natural_full_ok());
                                        if ($main_emit_natural) {
                                            // natural .avif (edge serves avif / self-heals; CF-cacheable)
                                            $avifEntries[] = $avifZoneBase . str_replace($avifSiteHost, '', $avifSrcUrl) . self::src_hint_qs($src_hint_ext) . ' ' . $descriptor;
                                        } else {
                                            // -Nw (no meta), or a bare-full/-WxH on a non-witnessed zone → never-404 wp:2 transform.
                                            $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $width . '/u:' . self::uForCdn($u_src_via_cdn) . ' ' . $descriptor;
                                        }
                                    }
                                }
                            }

                            // Extra widths from the FINAL img srcset (retina + adaptive expansions) not in
                            // original_srcset: without these slots in the AVIF source the browser never requests
                            // those widths → CDN never encodes them → never land on disk. Hybrid emission matches
                            // the loop above: sub-size match → natural; else <base>-{N}w.avif on disk → natural;
                            // else → wp:2 transform (never 404s).
                            $final_srcset_avif = isset($original_img_tag['original_tags']['srcset'])
                                ? (string) $original_img_tag['original_tags']['srcset']
                                : '';
                            if ($final_srcset_avif !== '') {
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

                                $extra_seen_avif = [];
                                foreach (explode(',', $final_srcset_avif) as $entry) {
                                    $entry = trim($entry);
                                    if (!preg_match('/^(\S+)\s+(\d+)w$/', $entry, $em)) continue;
                                    $extra_width = (int) $em[2];
                                    if ($extra_width <= 0) continue;
                                    if ($avif_src_w_cap > 0 && $extra_width > $avif_src_w_cap) continue; // ceiling cap (no above-source upscale → no-store webp)
                                    if (isset($existing_widths_in_avif[$extra_width])) continue;
                                    if (isset($extra_seen_avif[$extra_width])) continue;
                                    $extra_seen_avif[$extra_width] = true;

                                    // (1) Sub-size match (rare for extra widths but
                                    //     catches thumbnail-like sizes excluded from
                                    //     WP's srcset but present in adaptive output).
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
                                        $natural_url_avif = self::natural_ladder_url($base_no_ext_for_avif, $extra_width, $avif_aspect_meta, 'avif'); // sized-base safe (no -WxH-WxH double-suffix 404)
                                    }
                                    list($natural_url_avif, $natural_path_avif) = self::recoverAdaptiveVariant($natural_url_avif, $base_no_ext_for_avif, $extra_width, 'avif');

                                    // NEVER-404: a natural .avif <source> is OTF-safe ONLY in the -WxH form
                                    // (edge resize-by-suffix is proven for -WxH; the legacy -{N}w is the
                                    // write-side DEAD/404 scheme). So natural-emit requires a recovered on-disk
                                    // file OR a -WxH-form URL; otherwise fall to the never-404 wp:2.
                                    $extra_is_wxh = (bool) preg_match('/-\d+x\d+\.avif$/i', $natural_url_avif);
                                    if (@file_exists($natural_path_avif)) {
                                        $pathPart_extra = str_replace($avifSiteHost, '', $natural_url_avif);
                                        $avifEntries[] = $avifZoneBase . $pathPart_extra . self::src_hint_qs($src_hint_ext, true) . ' ' . $extra_width . 'w'; // (v7.03.61) on_disk=true: clean in 'until', ?src in 'always'
                                    } elseif ($optimistic_avif || $avif_otf_live || $avif_emit_natural) { // emit-natural reaches the -WxH decision on a ceiling-on un-witnessed zone; else wp:2 for optimistic-only
                                        $u_src_extra = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                        $u_src_extra_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_extra);
                                        if ($avif_emit_natural && $extra_is_wxh) { // natural -WxH on ceiling-on zones; degraded -Nw still wp:2
                                            // natural -WxH.avif (edge serves avif / self-heals; CF-cacheable)
                                            $avifEntries[] = $avifZoneBase . str_replace($avifSiteHost, '', $natural_url_avif) . self::src_hint_qs($src_hint_ext) . ' ' . $extra_width . 'w';
                                        } else {
                                            // -{N}w (no meta) or optimistic-only → never-404 wp:2 transform.
                                            $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $extra_width . '/u:' . self::uForCdn($u_src_extra_via_cdn) . ' ' . $extra_width . 'w';
                                        }
                                    }
                                    $avif_queue_w($extra_width); // land the extra/retina width
                                }
                            }

                            // Universal fine-grained ladder for picture sources: closes the 4-10× over-fetch gap
                            // on high-DPR devices picking from a sparse srcset. Adds LCP-style widths + retina
                            // doubles to ALL images so srcset selection is fine-grained. Same maxdim cap as
                            // buildLcpSrcset (portrait → cap at maxWidth × aspect so encoded height ≤ maxWidth).
                            if (($optimistic_avif || $avif_otf_live || $avif_emit_natural) && !empty($avifEntries)) { // densify on a ceiling-on un-witnessed zone too
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
                                    $natural_url_uni = self::natural_ladder_url($base_no_ext_uni, $w_uni, $avif_aspect_meta, 'avif'); // sized-base safe (no -WxH-WxH double-suffix 404)
                                    list($natural_url_uni, $natural_path_uni) = self::recoverAdaptiveVariant($natural_url_uni, $base_no_ext_uni, $w_uni, 'avif');
                                    // NEVER-404: natural only for a recovered on-disk file OR a -WxH-form URL
                                    // (OTF-proven); a degraded -{N}w → wp:2.
                                    $uni_is_wxh = (bool) preg_match('/-\d+x\d+\.avif$/i', $natural_url_uni);
                                    if (@file_exists($natural_path_uni)) {
                                        $pathPart_uni = str_replace($avifSiteHost, '', $natural_url_uni);
                                        $avifEntries[] = $avifZoneBase . $pathPart_uni . self::src_hint_qs($src_hint_ext, true) . ' ' . $w_uni . 'w'; // (v7.03.61) on_disk: clean in 'until', ?src in 'always'
                                    } else {
                                        $u_src_uni = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                        $u_src_uni_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_uni);
                                        if ($avif_emit_natural && $uni_is_wxh) { // natural -WxH on ceiling-on zones; degraded -Nw still wp:2
                                            // natural -WxH.avif (edge serves avif / self-heals; CF-cacheable)
                                            $avifEntries[] = $avifZoneBase . str_replace($avifSiteHost, '', $natural_url_uni) . self::src_hint_qs($src_hint_ext) . ' ' . $w_uni . 'w';
                                        } else {
                                            // -{N}w (no meta) or witness-off → never-404 wp:2 transform.
                                            $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $w_uni . '/u:' . self::uForCdn($u_src_uni_via_cdn) . ' ' . $w_uni . 'w';
                                        }
                                    }
                                    $avif_queue_w($w_uni); // land the universal-ladder width
                                }
                            }

                            // Native-width ceiling: collapse wider-than-native rungs into the clean natural
                            // full-size .avif URL. No .avif exists past native, so those rungs were no-store wp:2
                            // transforms (CF can't cache → pod hit every visit); folding them into the on-disk
                            // full-size natural URL = identical bytes, CF-cacheable, no upscale (mirrors WP core).
                            // Gated on attachment meta + the full-size .avif being reachable (on disk or witness).
                            if (!empty($avifEntries) && $avif_attachment_id > 0
                                && function_exists('wp_get_attachment_metadata')
                                && function_exists('wp_get_attachment_image_url')) {
                                $avif_native_w  = 0;
                                $avif_full_nat  = '';
                                $avif_meta_ceil = wp_get_attachment_metadata($avif_attachment_id);
                                if (is_array($avif_meta_ceil) && !empty($avif_meta_ceil['width'])) {
                                    $avif_native_w = (int) $avif_meta_ceil['width'];
                                    $avif_full_src = wp_get_attachment_image_url($avif_attachment_id, 'full');
                                    // Same-host guard: only host-swap a clean same-site uploads URL.
                                    // If a filter (e.g. an offloading addon) rewrote the attachment URL
                                    // to an already-CDN/transform URL, str_replace would corrupt a
                                    // mid-URL host — skip the collapse and leave entries untouched.
                                    if ($avif_full_src && strpos((string) $avif_full_src, $avifSiteHost) === 0) {
                                        $avif_full_url  = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', preg_replace('/\?.*$/', '', $avif_full_src));
                                        $avif_full_disk = str_replace($avifSiteUrl, trailingslashit(ABSPATH), $avif_full_url);
                                        // Arm the collapse when the full-size .avif is REACHABLE, not only when
                                        // already on disk (the witness covers a not-yet-landed natural .avif; on a
                                        // CF file-miss the rungs stay on no-store wp:2, preventing webp-interim
                                        // pinning; @file_exists wins once it lands). DIMS-VALIDITY: a corrupt
                                        // on-disk full-size .avif must not satisfy the on-disk half → fall to the witness.
                                        $avif_full_reach = (@file_exists($avif_full_disk)
                                                && self::picture_variant_dims_ok($avif_full_disk, $avif_native_w, $avif_native_h))
                                            || (self::picture_avif_natural_full_ok() && $avif_src_transcodable); // BARE full-size: proven witness (CDN bare-OTF bug); AND transcodable — don't fold to a bare natural .avif the edge can't OTF from a webp-native base
                                        if ($avif_full_reach) {
                                            $avif_full_nat = $avifZoneBase . str_replace($avifSiteHost, '', $avif_full_url);
                                        }
                                    }
                                }
                                if ($avif_native_w > 0 && $avif_full_nat !== '') {
                                    // Drop every at/above-native rung (upscale, or a no-store native
                                    // transform) and re-emit the native rung as the clean natural
                                    // full-size URL exactly once → CF-cacheable, no duplicate.
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

                            // Thin-AVIF guard (mixed-depth contract). Browsers do NOT fall back between
                            // <source>s at the width level: once an AVIF-capable browser picks the AVIF <source>
                            // by type it's locked to that srcset, so a single-width AVIF source makes mobile
                            // over-fetch — worse than no AVIF source (then the deep WebP <source> below carries).
                            // So require ≥2 entries (or the optimistic/witnessed ladder, always deep) before
                            // emitting; a lone entry is the over-fetch signature → suppressed so WebP carries.
                            $avif_deep_enough = $optimistic_avif || $avif_otf_live || $avif_emit_natural || count($avifEntries) >= 2;
                            if (!empty($avifEntries) && $avif_deep_enough) {
                                $avifSource = '<source ' . self::picture_source_srcset_attr($build_image_tag) . '="' . implode(', ', $avifEntries) . '"' . $sourceSizes . ' type="image/avif">';
                            }
                        } else {
                            // Single-src fallback (no srcset on the img tag)
                            $avifCdnUrl = '';
                            // DIMS-VALIDITY: a dimensionally-corrupt on-disk full-size .avif must not be emitted
                            // as the single AVIF <source> (type-pinned, no fallback). Treat a corrupt file as
                            // "not on disk" so the OTF/transform fallbacks below decide.
                            $avif_single_ok = @file_exists($avifPath)
                                && self::picture_variant_dims_ok($avifPath, $avif_native_w, $avif_native_h);
                            if ($avif_single_ok) {
                                $pathPart   = self::avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost);
                                $avifCdnUrl = $avifZoneBase . $pathPart;
                            } elseif (self::picture_avif_natural_full_ok() && $avif_src_transcodable) { // AND transcodable: a webp-native single-src has no jpg/png base, so a bare natural .avif <source> would 404
                                // OTF-live single-src: emit the clean natural full-size .avif (edge OTF-encodes
                                // by Accept; no width descriptor → full natural URL is correct, self-heals,
                                // CF-cacheable). Gated on the BARE-FULL witness (the CDN bare-OTF path has an
                                // open bug); optimistic-but-not-OTF-live falls to the wp:2 transform below.
                                $pathPart   = self::avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost);
                                $avifCdnUrl = $avifZoneBase . $pathPart;
                            } elseif ($optimistic_avif) {
                                // Same `u:`-via-cdn-zone treatment as the srcset branch. GATED on
                                // $optimistic_avif: never emit an AVIF transform URL with no on-disk file unless
                                // optimistic is on (browsers don't fall back between <source>s → a wp:2 non-image
                                // response = permanently broken). NATURAL single-src: a -WxH form is edge
                                // OTF-resizable by suffix → emit the clean natural URL instead of the wp:2/w:1
                                // FULL transform (which double-loads); bare-full stays on the witness above → wp:2/w:1.
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
                    // CONVERGED -Nw WebP <source> (mirror of the AVIF bypass): a clean -Nw.webp ladder — the
                    // edge OTF-resizes each width from any source (E1/E2), the base-interim floor never-404s a
                    // cold rung (E3). Covers both the WP srcset and a no-srcset page-builder <img> (synthetic
                    // ladder), with none of the -WxH/wp:1 machinery below.
                    $webp_nw_cap  = isset($avif_src_w_cap) ? (int) $avif_src_w_cap : 0;
                    $webp_nw_hint = isset($src_hint_ext) ? $src_hint_ext : '';
                    $webp_aspect  = isset($avif_aspect_meta) ? $avif_aspect_meta : null;
                    $webp_nw_entries = [];
                    foreach (self::wpc_nw_widths($original_img_tag, $webp_nw_cap) as $nw_w) {
                        $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $nw_w . 'w';
                    }
                    if (empty($webp_nw_entries)) {
                        // unknown native → full-size natural only (edge serves at native; no upscale → no 302/404)
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
                    // natural-everywhere: eager `srcset` so the browser sees the webp <source> (it ignores
                    // data-srcset). Img stays loading="lazy" → lazy + sizes=auto preserved.
                    $webpSrcsetAttr = self::picture_natural_fleet_enabled()
                        ? 'srcset'
                        : ((strpos($build_image_tag, 'data-srcset=') !== false) ? 'data-srcset' : 'srcset');

                    // use_original support (same as AVIF source above).
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
                        $jpgUrl = preg_replace('/\?.*$/', '', $wm[1]);
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
                            // NEVER-404 (symmetric with the AVIF leg): a webp-only browser locks to this typed
                            // webp <source> with no <img> fall-through, so it must not 404. The edge png/jpg→webp
                            // transforms a natural .webp (CF-cacheable). Natural ONLY for -WxH (OTF resize-by-suffix
                            // proven) or bare-full (the stricter witness); the legacy -Nw form is the write-side
                            // DEAD/404 scheme → wp:1.
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
                    if ($final_srcset_webp !== '') {
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

                        $extra_seen_webp = [];
                        foreach (explode(',', $final_srcset_webp) as $entry) {
                            $entry = trim($entry);
                            if (!preg_match('/^(\S+)\s+(\d+)w$/', $entry, $em_wp)) continue;
                            $extra_width_wp = (int) $em_wp[2];
                            if ($extra_width_wp <= 0) continue;
                            if ($avif_src_w_cap > 0 && $extra_width_wp > $avif_src_w_cap) continue; // ceiling cap (shared source width)
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
                                $natural_url_webp = self::natural_ladder_url($base_no_ext_for_webp, $extra_width_wp, $avif_aspect_meta, 'webp'); // sized-base safe (no -WxH-WxH double-suffix 404)
                            }
                            list($natural_url_webp, $natural_path_webp) = self::recoverAdaptiveVariant($natural_url_webp, $base_no_ext_for_webp, $extra_width_wp, 'webp');
                            // NEVER-404 (symmetric with AVIF extra-widths): natural only for a recovered on-disk
                            // file OR the proven -WxH form; a degraded -{N}w → wp:1.
                            $extra_wp_is_wxh = (bool) preg_match('/-\d+x\d+\.webp$/i', $natural_url_webp);

                            if (@file_exists($natural_path_webp)) {
                                $pathPart_extra_wp = str_replace($webpSiteHost, '', $natural_url_webp);
                                $webpEntries[] = $webpZoneBase . $pathPart_extra_wp . self::src_hint_qs($src_hint_ext, true) . ' ' . $extra_width_wp . 'w'; // (v7.03.61) on_disk: clean in 'until', ?src in 'always'
                            } else {
                                // when the edge will safely serve a natural .webp, emit the clean natural URL
                                // (edge png/jpg→webp transforms it, CF-cacheable, symmetric with the avif source).
                                // wp:1 stays the below-floor fallback.
                                if (self::picture_webp_natural_ok() && $extra_wp_is_wxh) { // natural only for the proven -WxH form
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

                    // Universal fine-grained ladder for WebP source (symmetric to the AVIF one above):
                    // LCP-style widths + retina-doubles for fine srcset granularity. Encoding stays on-demand
                    // via lazy_cdn (a bigger srcset only encodes widths visitors actually request).
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
                            $natural_url_uni_wp = self::natural_ladder_url($base_no_ext_uni_wp, $w_uni_wp, $avif_aspect_meta, 'webp'); // sized-base safe (no -WxH-WxH double-suffix 404)
                            list($natural_url_uni_wp, $natural_path_uni_wp) = self::recoverAdaptiveVariant($natural_url_uni_wp, $base_no_ext_uni_wp, $w_uni_wp, 'webp');
                            // NEVER-404 (symmetric with AVIF universal ladder): natural only for a recovered
                            // on-disk file OR the proven -WxH form; a degraded -{N}w → wp:1.
                            $uni_wp_is_wxh = (bool) preg_match('/-\d+x\d+\.webp$/i', $natural_url_uni_wp);
                            if (@file_exists($natural_path_uni_wp)) {
                                $pathPart_uni_wp = str_replace($webpSiteHost, '', $natural_url_uni_wp);
                                $webpEntries[] = $webpZoneBase . $pathPart_uni_wp . self::src_hint_qs($src_hint_ext, true) . ' ' . $w_uni_wp . 'w'; // (v7.03.61) on_disk: clean in 'until', ?src in 'always'
                            } else {
                                // when the edge will safely serve a natural .webp, emit the clean natural URL
                                // (edge png/jpg→webp transforms it, CF-cacheable, symmetric with the avif source).
                                // wp:1 stays the below-floor fallback.
                                if (self::picture_webp_natural_ok() && $uni_wp_is_wxh) { // natural only for the proven -WxH form
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

                    // Native-width ceiling (mirror of the AVIF collapse above): fold every wider-than-native
                    // WebP rung into the clean natural full-size .webp URL (identical bytes, CF-cacheable, no
                    // upscale). Gated on attachment meta + the full-size .webp being reachable (on disk or witness).
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

                // Single-authority delivery: when the resolver picks CDN-edge, negotiated owns image delivery
                // as a clean <img> + edge Accept-negotiation, so skip the <picture> wrap entirely (zero
                // theme-compat risk). Inert until the resolver has verified CDN-edge for the site.
                if (!(class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active())) {
                    // onerror→origin net on the <picture> <img>. The CF/picture path has NO edge
                    // sibling-fallback (that net lives on the Bunny pod), so a typed <source> 404 would break
                    // with no recovery. The <img> loads the selected <source>'s URL, so its onerror fires on a
                    // source 404; recover ONCE: strip the <source>s + img srcset and load the origin same-ext file.
                    if (!empty($image_source) && strpos($fallbackTag, 'data-wpc-fb=') === false && stripos($fallbackTag, '<img') !== false) {
                        $wpc_fb_origin  = esc_attr(preg_replace('/\?.*$/', '', (string) $image_source));
                        $wpc_fb_handler = "this.onerror=null;var p=this.parentNode;if(p&&p.tagName==='PICTURE'){var s;while(s=p.getElementsByTagName('source')[0])s.parentNode.removeChild(s);}this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
                        $fallbackTag = preg_replace('/<img\b/i', '<img data-wpc-fb="' . $wpc_fb_origin . '" onerror="' . $wpc_fb_handler . '"', $fallbackTag, 1);
                    }
                    // NATURAL-EVERYWHERE on the <img> fallback: the <source>s are natural but the fallback <img>
                    // still carried /q:i/.../u: transforms (so no-avif/webp browsers + the onerror recovery got a
                    // transform). Convert every transform URL in the fallback to its natural zone URL, preserving
                    // descriptors + the onerror JS. Gated on the natural fleet master (revert via wpc_picture_natural_fleet=0).
                    if ((self::picture_natural_fleet_enabled() || self::wpc_natural_nw()) && self::$zoneName !== '') {
                        // (v7.03.53) Rebuild each rung as a DISTINCT natural -WxH instead of host-swapping the
                        // transform's single u: source verbatim. buildLcpSrcset points every ladder rung at ONE
                        // source + relies on the /w:W/ transform to resize; naively stripping /w: collapsed all
                        // rungs onto that one file (e.g. -800x450 at 400w/480w/640w/660w → the browser has no
                        // smaller candidate → over-fetch). Derive -WxH from the /w:W/ width + the source's own
                        // aspect (downscale only → never-404; the edge OTF-resizes each). No /w: or no -WxH
                        // aspect basis → host-swap the source as-is (unchanged behaviour for those).
                        $fallbackTag = preg_replace_callback(
                            '#https?://' . preg_quote(self::$zoneName, '#') . '/[^"\x27\s,>]*?(?:/w:(\d+))?/u:(https?://[^"\x27\s,>]+?\.(?:webp|avif|jpe?g|png|gif))(?:\?[^"\x27\s,>]*)?#i',
                            function ($m) {
                                $w    = (isset($m[1]) && $m[1] !== '') ? (int) $m[1] : 0;
                                $path = (string) wp_parse_url($m[2], PHP_URL_PATH);
                                if ($path === '') return $m[0];
                                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                                if ($ext === '') $ext = 'webp';
                                $noext = preg_replace('/\.[a-z0-9]+$/i', '', $path);
                                if ($w > 0 && preg_match('#^(.*)-(\d+)x(\d+)$#', $noext, $d) && (int) $d[2] > 0) {
                                    $sw = (int) $d[2]; $sh = (int) $d[3];
                                    $h  = (int) round($w * $sh / $sw);
                                    if ($h > 0) {
                                        return 'https://' . self::$zoneName . $d[1] . '-' . $w . 'x' . $h . '.' . $ext;
                                    }
                                }
                                return 'https://' . self::$zoneName . $noext . '.' . $ext;
                            },
                            $fallbackTag
                        );
                    }

                    // (v7.10.04) HARD GUARANTEE — the fallback <img>'s OWN src/data-src is NEVER a
                    // transform URL. The naturalize pass above is GATED on the picture-natural fleet
                    // flag; if that's off (or a rung slips through) the <img> could keep a
                    // /q:i.../u: transform that needs the proxy + Accept-negotiation to resolve a
                    // decodable format. The <img> is the TERMINAL fallback for browsers that support
                    // neither AVIF nor WebP (and the no-JS path the onerror can't cover), so it must be
                    // a clean raster the browser decodes by EXTENSION — no proxy, no Accept dependency,
                    // no JS. Rewrite the <img>'s src AND data-src to the clean original ($image_source,
                    // the same verified URL already used for data-wpc-fb) whenever they're still a
                    // transform. This also neutralises the lazy loader: optimizer.js SetupNewApiURL only
                    // mutates wp:/w:/r:/e: tokens, so a clean raster URL passes through it untouched.
                    // Scoped to the <img>'s own src/data-src ONLY — the typed <source>s (AVIF/WebP for
                    // capable browsers) are separate vars and untouched; a webp-origin keeps its own
                    // extension (no raster exists → accepted). A WPC transform URL always embeds the
                    // origin as ".../u:<origin>", so that substring is the reliable detector.
                    if (!empty($image_source) && stripos((string) $image_source, '/u:') === false) {
                        $wpc_fb_clean = preg_replace('/\?.*$/', '', (string) $image_source);
                        $fallbackTag  = preg_replace_callback(
                            '/\s(src|data-src)="([^"]*)"/i',
                            function ($m) use ($wpc_fb_clean) {
                                if (stripos($m[2], '/u:') !== false) { // transform/proxy URL → clean raster
                                    return ' ' . $m[1] . '="' . esc_url($wpc_fb_clean) . '"';
                                }
                                return $m[0]; // already clean → leave as-is
                            },
                            $fallbackTag
                        );
                    }

                    $build_image_tag = '<picture class="wpc-picture">'
                        . $avifSource
                        . '<source' . $sourceSrcset . $sourceSizes . ' type="image/webp">'
                        . $fallbackTag
                        . '</picture>';
                }
            }
        }

        // (v7.10.04) SECURITY — same class as CVE-2026-9066: these debug branches dumped raw
        // $_GET/$_POST (and internal markup) straight into the HTML buffer, so ?dbgAjaxEnd=1&x=<script>
        // reflected unescaped = a reflected XSS. Gate behind an admin session (debug only, never a
        // public query string) AND esc_html() the dump so it's inert even for an admin who clicks a
        // crafted link. Output is meant to be READ as text, so escaping is the correct behaviour.
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

        if (!empty($_GET['ajaxImage'])) {
            return print_r([$original_img_tag, $imageElement], true);
        }

        if (strpos($original_img_tag['original_tags']['src'], 'data:image') !== false || strpos($original_img_tag['original_tags']['src'], 'blank') !== false) {

            $newImageElement = '<img ';
            // it's placeholder or blank file change something
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
        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $url, $matches); //the filename suffix way
        if (isset($matches[1]) && isset($matches[2])) {
            return [$matches[1], $matches[2]];
        } else { //the file
            return [1024, 1024];
        }
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

        // ---------------------------------------------------------------------
        // Pick canonical "full" image source:
        // Prefer original_src ONLY if it is not a WP resized (-400x70) file,
        // otherwise use the largest srcset candidate.
        // ---------------------------------------------------------------------
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

        // native-width ceiling for the retina doubler + 480/960 injector below. WP core never emits an
        // srcset rung wider than native, so the LARGEST 'w' descriptor already in the source srcset IS the
        // native ceiling (no DB lookup). A synthesized rung wider than this would force an edge UPSCALE
        // (wasted bytes, and on a CF zone a no-store transform), so rungs that exceed it are skipped.
        // Unknown ceiling (0 — e.g. x-descriptor-only srcset) → emit as before.
        $retina_native_w = (int) $largestWidth;
        // safety valve: an operator can disable the clamp via this filter if a pathological srcset ever
        // clips a wanted rung. Default ON.
        if (!apply_filters('wpc_retina_clamp_enabled', true)) {
            $retina_native_w = 0;
        }

        // ---------------------------------------------------------------------
        // Rewrite srcset
        // ---------------------------------------------------------------------
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

                // ---------------------------------------------------------
                // x-descriptor: density maps to r: flag, width is always 1
                // (full size). Use fullSrc as canonical source.
                // No retina injection needed — density is explicit.
                // ---------------------------------------------------------
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

                // ---------------------------------------------------------
                // w-descriptor: standard width-based rewriting
                // ---------------------------------------------------------
                $width_url = $width_val;
                $srcsetWidthExtension = $width_val . 'w';

                // Non-retina URL (use the actual candidate URL)
                $newSrcSet .= self::$apiUrl . '/r:0' . $webp . '/w:' . self::getCurrentMaxWidth($width_url, self::isExcludedFrom('adaptive', $srcset_url)) . '/u:' . self::uForCdn($srcset_url) . ' ' . $srcsetWidthExtension . ', ';

                // Retina URL (use canonical fullSrc)
                if (self::$settings['retina-in-srcset'] == '1' && !empty($fullSrc)) {
                    $retinaWidth = (int)$width_url * 2;

                    // skip the retina rung when its doubled width exceeds native (pure upscale). Unknown = emit.
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

                // native-width ceiling (see note above the rewrite loop): emit each injected rung only when
                // it fits within the source's native width (or unknown).
                if ($retina_native_w <= 0 || 480 <= $retina_native_w) {
                    $newSrcSet .= self::$apiUrl . '/r:0' . $webp . '/w:480/u:' . self::uForCdn($fullSrc) . ' 480w, ';
                }

                if (self::$settings['retina-in-srcset'] == '1' && ($retina_native_w <= 0 || 960 <= $retina_native_w)) {
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
        // First check if 480w already exists in the srcset
        if (preg_match('/\s480w/', $srcset)) {
            return $srcset;
        }

        // Extract both w: values and srcset widths (for URLs) using regex
        preg_match_all('/w:(\d+)/si', $srcset, $w_matches); // Matches the "w:" pattern widths
        preg_match_all('/(\S+)\s(\d+)w/si', $srcset, $srcset_matches); // Matches srcset widths

        $w_widths = array_map('intval', $w_matches[1]); // w: values
        $srcset_widths = array_map('intval', $srcset_matches[2]); // srcset widths

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