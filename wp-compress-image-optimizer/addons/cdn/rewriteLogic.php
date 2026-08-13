<?php

/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */
class wps_rewriteLogic
{

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
    public static $deviceHiddenSet717 = [];
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

        $wpc_swapper_gone852 = (class_exists('WPC_Negotiated_Delivery')
            && method_exists('WPC_Negotiated_Delivery', 'is_active')
            && (WPC_Negotiated_Delivery::is_active()
                || (method_exists('WPC_Negotiated_Delivery', 'is_active_jpeg') && WPC_Negotiated_Delivery::is_active_jpeg())))
            || (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed());
        if ($wpc_swapper_gone852 && apply_filters('wpc_nd_stands_down_swap_lanes', true)) {
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
                && function_exists('wpc_cdn_debug_allowed649') && wpc_cdn_debug_allowed649()) {
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

    // v7.10.704 — the service crit artifact can carry a "/* wpc conceal-guard */" block that
    // display:none's containers to stop pre-CSS flashes. For elements whose visibility the
    // theme manages with JS-toggled classes and no display declaration of its own (live root
    // cause: nav.elementor-nav-menu--dropdown and #checkout on wpcompress.com), NOTHING in the
    // cascade ever overrides the guard — the menu/checkout stay hidden forever. A deferral is
    // a promise something will undo it: scope every guard rule under html:not(.wpc-css-live)
    // and have the used-css boot add that class the moment the full stylesheet stack applies
    // (rest onload, onerror fail-open, no-rest fallback, 15s ultimate belt). Guard behaviour
    // through the unstyled window is byte-identical; afterwards the guard is inert.
    public static $wpc_scoped704 = false;

    public static function wpc_conceal_scope704($css)
    {
        try {
            $css = (string) $css;
            $i = strpos($css, 'conceal-guard');
            if ($i === false) {
                return $css;
            }
            if (strpos($css, 'wpc-css-live') !== false) {
                // v7.20.06 — natively-scoped artifact (service v3.189.3+ ships the guard already
                // under html:not(.wpc-css-live)). The RELEASER IS STILL OWED: this static is what
                // arms the boot emission, and keying it on "did WE scope it" instead of "is a
                // scoped guard present" shipped guard-without-boot pages (ridgeway /events/,
                // div.brx-popup hidden forever) whenever the used-css rest links were absent too.
                self::$wpc_scoped704 = true;
                return $css;
            }
            $s = strpos($css, '*/', $i);
            if ($s === false) {
                return $css;
            }
            $s += 2;
            $tail = substr($css, $s);
            // only flat rule runs — an @-block or nested comment falls back to today's bytes
            if (strpos($tail, '@') !== false || strpos($tail, '/*') !== false) {
                return $css;
            }
            $out = '';
            $off = 0;
            $n = 0;
            while (preg_match('/\s*([^{}]{1,400}?)\s*\{([^{}]*)\}/A', $tail, $m, 0, $off)) {
                $sels = [];
                foreach (explode(',', $m[1]) as $one) {
                    $one = trim($one);
                    if ($one !== '') {
                        $sels[] = 'html:not(.wpc-css-live) ' . $one;
                    }
                }
                if (empty($sels)) {
                    return $css;
                }
                $out .= implode(',', $sels) . '{' . $m[2] . '}';
                $off += strlen($m[0]);
                $n++;
            }
            // the parse must consume the whole tail — a partial parse ships original bytes
            if ($n === 0 || trim(substr($tail, $off)) !== '') {
                return $css;
            }
            self::$wpc_scoped704 = true;
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_conceal_scope_log')) {
                set_transient('wpc_conceal_scope_log', 1, 3600);
                wpc_cache_first_log('conceal-scoped', '', '', ['rules' => $n]);
            }
            return substr($css, 0, $s) . $out;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    // v7.20.04 — FONTS ARE A SITE CONSTANT: the crit artifact's embedded @font-face blocks
    // are routinely the document's ONLY letters-coverage for the theme's real faces (dalton:
    // Poppins w600/700/800 letter subsets lived only in crit; the late lane carried residual
    // faces whose unicode-range EXCLUDES letters). Any runtime crit remover — the service's
    // optimize.js does a naked #wpc-critical-css remove at ~3.5s, no hoist, no gate — then
    // deletes the faces with it and every headline falls to the metric fallback (Arial)
    // permanently: "starts as Poppins, goes to Arial". Split the faces OUT of the crit
    // payload at emission into a sibling carrier no remover targets; #wpc-crit-faces is the
    // id the v3 sweep already creates for its own hoist, so both belts converge on one node.
    public static function wpc_face_display_sweep21($css)
    {
        // v7.21.02 — ONE display policy per family, applied at EVERY face emitter. The
        // smart->optional upgrade (a validated metric fallback makes optional zero-CLS for
        // free) was reaching some lanes and not others: borderlessmoves shipped 13 optional
        // + 13 swap Fredoka faces across three carriers, and any swap face keeps the reflow
        // the upgrade exists to kill (mobile CLS 0.868, H1 re-wrap at font arrival). Only
        // ever rewrites an explicit font-display:swap, per family, through the same
        // wpc_font_display_effective resolver every other lane uses; fallback faces and
        // undeclared-display faces pass through untouched.
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'font-display') === false
                || !function_exists('wpc_font_display_effective')
                || apply_filters('wpc_face_display_sweep', true) === false) {
                return $css;
            }
            $out = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) {
                $blk = $m[0];
                if (!preg_match('/font-display\s*:\s*swap\b/i', $blk)) { return $blk; }
                if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $fa)) { return $blk; }
                $fam = trim($fa[1]);
                if ($fam === '' || stripos($fam, ' fallback') !== false) { return $blk; }
                $eff = wpc_font_display_effective('swap', $fam);
                if (!is_string($eff) || $eff === 'swap'
                    || !in_array($eff, ['optional', 'fallback', 'block', 'auto'], true)) { return $blk; }
                return preg_replace('/font-display\s*:\s*swap\b/i', 'font-display:' . $eff, $blk);
            }, $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    public static function wpc_crit_tag931($attrs, $payload)
    {
        $payload = (string) $payload;
        $carrier = '';
        if (stripos($payload, '@font-face') !== false && apply_filters('wpc_crit_faces_split', true)) {
            $faces = [];
            $rest = preg_replace_callback('#@font-face\s*\{[^{}]*\}#i', function ($m) use (&$faces) {
                $faces[] = $m[0];
                return '';
            }, $payload);
            if (is_string($rest) && !empty($faces)) {
                $carrier = '<style type="text/css" id="wpc-crit-faces">' . self::wpc_face_display_sweep21(implode('', $faces)) . '</style>';
                $payload = $rest;
            }
        }
        return $carrier . '<style type="text/css" ' . $attrs . '>' . $payload . '</style>';
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

    public static function wpc_ucss_boot_js()
    {
        $ms = self::wpc_ucss_conceal_ms();
        return '<script id="wpc-ucss-boot">/*wpc-arm-sentinel*/(function(){var g=function(){try{document.documentElement.classList.add("wpc-css-live")}catch(x){}};function a(){var r=document.querySelectorAll(\'link[data-wpc-rest]:not([href])\'),armed=0;for(var j=0;j<r.length;j++){(function(e){var ru=e.getAttribute("data-wpc-rest"),rm=e.getAttribute("data-wpc-ucss-rest")||"all",rg=true;try{rg=!window.matchMedia||window.matchMedia(rm).matches}catch(x){rg=true}if(!rg||!ru)return;armed++;e.media="print";e.onload=function(){this.onload=null;this.media=rm;g()};e.onerror=g;e.setAttribute("href",ru)})(r[j])}if(!armed){g()}}function q(){(window.requestIdleCallback||function(f){setTimeout(f,1200)})(a,{timeout:2500})}setTimeout(g,' . (int) $ms . ');if(document.readyState==="complete"){q()}else{window.addEventListener("load",q)}})();</script>';
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
            }
        }
        // v7.10.664 — NON-CF SAFETY FLOOR (twin of the CF device-key floor above). A device-blind
        // FOREIGN page cache in front of device-split HTML is the same first-device-wins hazard as a
        // device-blind edge. When CF is not the front cache and we cannot prove the foreign cache
        // varies by device, stay device-universal. WPC's own cache is device-keyed, so it is exempt.
        if (!$on && apply_filters('wpc_combined_crit_devblind_floor', true)) {
            $wpc_cf3 = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
            if (!(is_array($wpc_cf3) && !empty($wpc_cf3['token']) && !empty($wpc_cf3['zone']))
                && function_exists('wpc_foreign_device_blind_cache') && wpc_foreign_device_blind_cache()) {
                $on = true;
            }
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

        // Local-Fonts cache (wp-cio-fonts): never subset/zoneify — must stay natural origin to match the
        // inline @font-face + preload (see cdn_rewrite_url font branch + rewrite_fontface_css:3838).
        if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
            return $url;
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
     * Measured dimensions of a same-site local image, or null when unreadable. Static
     * per-request cache; 10MB ceiling keeps getimagesize off pathological files. Query
     * strings stripped; only http(s) URLs on this site's host map to ABSPATH.
     */
    public static function wpc_true_aspect934($url)
    {
        static $wpc_tc934 = [];
        if (!is_string($url) || $url === '' || !function_exists('getimagesize')) {
            return null;
        }
        $u = (string) preg_replace('/[?#].*$/', '', $url);
        if (isset($wpc_tc934[$u])) {
            return $wpc_tc934[$u];
        }
        $wpc_tc934[$u] = null;
        if (function_exists('site_url')) {
            $site = (string) preg_replace('/[?#].*$/', '', trailingslashit(site_url()));
            if ($site !== '' && stripos($u, $site) === 0 && defined('ABSPATH')) {
                $p = trailingslashit(ABSPATH) . ltrim(substr($u, strlen($site)), '/');
                if (strpos($p, '..') === false && @is_file($p) && (int) @filesize($p) <= 10485760) {
                    $gi = @getimagesize($p);
                    if (is_array($gi) && !empty($gi[0]) && !empty($gi[1])) {
                        $wpc_tc934[$u] = ['width' => (int) $gi[0], 'height' => (int) $gi[1]];
                    }
                }
            }
        }
        return $wpc_tc934[$u];
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
    public static function wpc_dupe_rule_prune17($css)
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
                    $wpc_at17 = strtolower((string) preg_replace('/\s+/', '', $head));
                    // block-less at-rules (@import/@charset ...;) never reach here with '{' first
                    $out .= substr($css, $i, $ob - $i + 1);
                    $ctx[] = $wpc_at17;
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

    public static function wpc_att_recorded18($url)
    {
        // v7.20.18 — a bare-full next-gen guess (name.avif / name.webp with no width rung)
        // is only emitted for an attachment whose optimization record proves the twin exists:
        // ic_status=compressed, or the twin bytes already on local disk. Unrecorded falls to
        // the <picture>'s <img> original natural URL — one 200, zero redirects, no cold mint.
        static $wpc_rec18 = [];
        if (apply_filters('wpc_twin_record_gate', true) === false) {
            return true;
        }
        $u = preg_replace('/[?#].*$/', '', (string) $url);
        $u = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $u);
        $u = preg_replace('/-\d+w(\.[a-z0-9]+)$/i', '$1', $u);
        if ($u === '' || $u === null) {
            return false;
        }
        if (array_key_exists($u, $wpc_rec18)) {
            return $wpc_rec18[$u];
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
        $wpc_rec18[$u] = $ok;
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
            $formatted_url = str_replace(self::$siteUrl, '', $formatted_url);
            $formatted_url = str_replace(str_replace(['https://', 'http://'], '', self::$siteUrl), '', $formatted_url);
            $formatted_url = str_replace(addcslashes(self::$siteUrl, '/'), '', $formatted_url);
            $formatted_url = ltrim($formatted_url, '\/');
            $formatted_url = ltrim($formatted_url, '/');
        }

        if (!empty(self::$cdnEnabled) && self::$cdnEnabled == '1') {
            if (self::$randomHash == 0 && (strpos($formatted_url, '.css') !== false)) {
                $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'icv=' . WPS_IC_HASH;
            }

            if (self::$randomHash == 0 && strpos($formatted_url, '.js') !== false) {
                $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'js_icv=' . WPS_IC_JS_HASH;
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
        $wpc_ts735  = (int) get_option('wpc_v2_cf_asset_mime_ts', 0);
        $wpc_ttl735 = (int) apply_filters('wpc_natural_proof_ttl', 12 * HOUR_IN_SECONDS);
        if ($wpc_ttl735 > 0 && (time() - $wpc_ts735) > $wpc_ttl735) {
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

    public static $wpc_natwhy808 = '';

    public static function wpc_nat_why808()
    {
        $wpc_on808 = self::natural_assets_on();
        if ($wpc_on808) {
            return 'natural';
        }
        return self::$wpc_natwhy808 !== '' ? self::$wpc_natwhy808 : 'off';
    }

    private static function natural_assets_on_uncached()
    {
        // Every refusal names its gate (read back through wpc_nat_why808 -> the wpc-nat
        // Server-Timing header): "why is this site still on transform URLs" must be one
        // DevTools look, never a support back-and-forth over invisible option state.
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'emission_ready')) {
            self::$wpc_natwhy808 = 'no-negotiated-class';
            return false;
        }
        // Kill switch: WPC_NEGOTIATED_KILL is the single off-ramp for the whole next-gen system, so it
        // must cut the css/js/font naturalization path too (not just image negotiation).
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            self::$wpc_natwhy808 = 'kill-switch';
            return false;
        }

        $wpc_hint808 = '';
        if (function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $na_bunny_s    = get_option(WPS_IC_SETTINGS);
            $na_bunny_zone = is_string(self::$zoneName) ? trim(self::$zoneName) : '';
            $na_bunny_live = is_array($na_bunny_s) && !empty($na_bunny_s['live-cdn']) && (string) $na_bunny_s['live-cdn'] === '1';
            if (!$na_bunny_live) {
                $wpc_hint808 = 'live-cdn-off';
            } elseif ($na_bunny_zone === '') {
                $wpc_hint808 = 'no-zone';
            } elseif (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
                $wpc_hint808 = 'zone-suppressed';
            } elseif (self::zone_is_cf()) {
                $wpc_hint808 = 'zone-is-cf';
            } else {
                $na_bunny_origin = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                $na_bunny_zh     = preg_replace('#/.*$#', '', $na_bunny_zone);
                if ($na_bunny_origin && strcasecmp($na_bunny_zh, (string) $na_bunny_origin) !== 0) {
                    if (apply_filters('wpc_natural_assets_enabled', true)) {
                        return true; // Bunny → natural, no probe
                    }
                    self::$wpc_natwhy808 = 'filter-off';
                    return false;
                }
                $wpc_hint808 = 'zone-equals-origin';
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
                self::$wpc_natwhy808 = 'cf-mime-unproven';
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
            self::$wpc_natwhy808 = $wpc_hint808 !== '' ? $wpc_hint808 : 'zone-unverified';
            return false;
        }
        if (!apply_filters('wpc_natural_assets_enabled', true)) {
            self::$wpc_natwhy808 = 'filter-off';
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


    public static function picture_natural_fleet_enabled()
    {
        $opt = function_exists('get_option') ? get_option('wpc_picture_natural_fleet', 1) : 1;
        // Fleet default assumed every pod serves natural paths; a legacy pod disproved it (every
        // avif?src= source 404'd). The fleet flag now needs the same per-zone witness the CSS lane
        // has always had; wpc_force_natural stays the operator override.
        $wpc_wit750 = (function_exists('wpc_force_natural') && wpc_force_natural())
            || self::natural_assets_on();
        return (bool) apply_filters('wpc_picture_natural_fleet', !empty($opt) && $wpc_wit750);
    }


    public static function picture_avif_natural_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        $ok = self::picture_natural_fleet_enabled() ? true : self::avif_natural_source_ok();
        return (bool) apply_filters('wpc_picture_avif_natural_ok', $ok);
    }


    public static function picture_source_srcset_attr($build_image_tag)
    {
        if (self::picture_natural_fleet_enabled() || self::wpc_natural_nw()) return 'srcset';

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
        return preg_replace('/\.(jpe?g|png)(?=[?#]|$)/', '.' . $fmt, (string) $url);
    }


    public static function swap_ext_in_tag($tag, $fmt)
    {
        return preg_replace('/\.(jpe?g|png)(?=["\'\s?#)>])/', '.' . $fmt, (string) $tag);
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


    public static function wpc_census_belowfold_map793()
    {
        static $wpc_map793 = null;
        if ($wpc_map793 !== null) { return $wpc_map793; }
        $wpc_map793 = [];
        try {
            if (!apply_filters('wpc_census_belowfold_veto', true)
                || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return $wpc_map793;
            }
            $wpc_u793 = (function_exists('is_ssl') && is_ssl() ? 'https://' : 'http://')
                . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
                . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
            $wpc_k793 = (new wps_ic_url_key())->setup($wpc_u793);
            if ($wpc_k793 === '') { return $wpc_map793; }
            $wpc_f793 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k793 . '/lcp.json';
            if (!@is_readable($wpc_f793)) { return $wpc_map793; }
            $wpc_j793 = json_decode((string) @file_get_contents($wpc_f793), true);
            if (!is_array($wpc_j793) || empty($wpc_j793['atf_images']) || !is_array($wpc_j793['atf_images'])) {
                return $wpc_map793;
            }
            // STRICT device leg — no cross-leg fallback: a desktop top on a mobile render
            // (or vice versa) is a measurement of a DIFFERENT layout and must never veto.
            $wpc_m793 = (!empty($_GET['simulate_mobile']) || (function_exists('wp_is_mobile') && wp_is_mobile()));
            $wpc_l793 = $wpc_j793['atf_images'][$wpc_m793 ? 'mobile' : 'desktop'];
            if (!is_array($wpc_l793)) { return $wpc_map793; }
            foreach ($wpc_l793 as $wpc_e793) {
                if (!is_array($wpc_e793) || empty($wpc_e793['stem']) || !is_string($wpc_e793['stem'])
                    || !isset($wpc_e793['top'])
                    || !preg_match('/^[A-Za-z0-9._@-]{3,}$/', $wpc_e793['stem'])) {
                    continue;
                }
                $wpc_s793 = strtolower($wpc_e793['stem']);
                $wpc_t793 = (int) $wpc_e793['top'];
                // Same stem seen twice (header + footer instance): keep the SMALLEST top, so
                // any above-fold sighting wins and the stem is never vetoed.
                if (!isset($wpc_map793[$wpc_s793]) || $wpc_t793 < $wpc_map793[$wpc_s793]) {
                    $wpc_map793[$wpc_s793] = $wpc_t793;
                }
            }
        } catch (\Throwable $e) {
            $wpc_map793 = [];
        }
        return $wpc_map793;
    }

    public static function wpc_census_below_fold793($tag)
    {
        // POSITIVE measurement only: an image the census never saw yields no veto — the
        // positional window keeps its slot exactly as today. Fail-open on every miss.
        try {
            if (!is_string($tag) || $tag === '') { return false; }
            $wpc_bm793 = self::wpc_census_belowfold_map793();
            if (empty($wpc_bm793)) { return false; }
            if (!preg_match('/\b(?:src|data-src|data-cp-src)\s*=\s*["\']([^"\']+)/i', $tag, $wpc_sm793)) {
                return false;
            }
            $wpc_fn793 = strtolower((string) basename((string) parse_url($wpc_sm793[1], PHP_URL_PATH)));
            if ($wpc_fn793 === '') { return false; }
            $wpc_fold793 = (int) apply_filters('wpc_lcp_census_fold', 1200);
            foreach ($wpc_bm793 as $wpc_s793 => $wpc_t793) {
                if ($wpc_t793 <= $wpc_fold793) { continue; }
                if (preg_match('/^' . preg_quote($wpc_s793, '/') . '(?:-scaled)?(?:-\d+x\d+)?\.[a-z0-9]+$/i', $wpc_fn793)) {
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
            $wpc_nat848 = 0;
            foreach ($raw as $wpc_e848) {
                $wpc_p848 = preg_split('/\s+/', $wpc_e848);
                if (count($wpc_p848) >= 2 && strpos($wpc_p848[0], '/u:') === false) { $wpc_nat848++; }
            }
            $wpc_drop848 = ($wpc_nat848 >= 2) && apply_filters('wpc_drop_plain_transform_rungs', true);
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
                    if ($wpc_drop848 && $isTransform && $wpc_plainT) { continue; }
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
                if (empty($p['path'])) return $m[0];
                $q = (isset($p['query']) && $p['query'] !== '') ? '?' . $p['query'] : '';
                $natural = 'https://' . $cname . $p['path'] . $q;
                if ($escaped) $natural = str_replace('/', '\\/', $natural);
                return $natural;
            },
            $html
        );
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


    public static function wpc_zone_delayed_js804($url)
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
        $wpc_live811 = null;
        $wpc_jso811  = null;
        if (class_exists('wps_cdn_rewrite')) {
            if (isset(wps_cdn_rewrite::$cdnEnabled)) { $wpc_live811 = wps_cdn_rewrite::$cdnEnabled; }
            if (isset(wps_cdn_rewrite::$js))         { $wpc_jso811  = wps_cdn_rewrite::$js; }
        }
        if ($wpc_live811 === null || $wpc_jso811 === null) {
            $wpc_s811 = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
            if (!is_array($wpc_s811)) {
                return $url;
            }
            if ($wpc_live811 === null) { $wpc_live811 = isset($wpc_s811['live-cdn']) ? $wpc_s811['live-cdn'] : null; }
            if ($wpc_jso811 === null)  { $wpc_jso811  = isset($wpc_s811['js']) ? $wpc_s811['js'] : null; }
        }
        if ((string) $wpc_live811 !== '1' || (string) $wpc_jso811 !== '1') {
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
        $wpc_abs804 = self::reformatUrl($url);
        if (!is_string($wpc_abs804) || strpos($wpc_abs804, 'http') !== 0) {
            return $url;
        }
        if (!self::imageUrlMatchingSiteUrl($wpc_abs804)) {
            return $url;
        }
        if (self::isExcludedLink($wpc_abs804) || self::isExcludedFrom('cdn', $wpc_abs804)) {
            return $url;
        }
        // The exclude list is applied ONCE, by its owner. Re-running wpc_cdn_excludes here over a
        // different default array meant a filter that computes from its input (array_diff,
        // array_slice, index-based) saw two different inputs and produced two different exclusion
        // sets on the same request.
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'isExcludedFrom')
            && wps_cdn_rewrite::isExcludedFrom('cdn', $wpc_abs804)) {
            return $url;
        }
        $wpc_p804 = @parse_url($wpc_abs804);
        if (empty($wpc_p804['path']) || !preg_match('/\.m?js$/i', $wpc_p804['path'])) {
            return $url;
        }
        // reformatUrl() strips the caller's query before appending its own buster, so the original
        // must be carried across explicitly: without it a script reading its own currentScript.src
        // query loses every parameter, and per-file ?ver identity collapses into one global hash.
        $wpc_q804 = (isset($wpc_p804['query']) && $wpc_p804['query'] !== '') ? $wpc_p804['query'] : '';
        $wpc_oq804 = (string) @parse_url($url, PHP_URL_QUERY);
        if ($wpc_oq804 !== '') {
            $wpc_q804 = ($wpc_q804 !== '') ? $wpc_oq804 . '&' . $wpc_q804 : $wpc_oq804;
        }
        return 'https://' . self::$zoneName . $wpc_p804['path'] . ($wpc_q804 !== '' ? '?' . $wpc_q804 : '');
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


    public static $wpc_census_slots = [];


    public static function wpc_census_slot_sizes($imageUrl, $imgTag = '')
    {
        try {
            if (!apply_filters('wpc_nd_measured_sizes', true)) { return ''; }
            self::wpc_census_rung_targets($imageUrl);
            $wpc_b131 = strtolower(basename((string) preg_replace('/[?#].*$/', '', (string) $imageUrl)));
            $wpc_b131 = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif)$/i', '', $wpc_b131);
            $wpc_b131 = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $wpc_b131), 1);
            if ($wpc_b131 === '' || !isset(self::$wpc_census_slots[$wpc_b131])) { return ''; }
            $wpc_s131 = self::$wpc_census_slots[$wpc_b131];
            $wpc_m131 = (int) $wpc_s131['m'];
            $wpc_d131 = (int) $wpc_s131['d'];
            // A desktop slot far below the mobile slot for the same element is a shrunken-
            // state measurement (Divi sticky header halves its logo after scroll — sppf baked
            // 103px against a ~200px paint and the browser picked a 206w rung: visible blur).
            // Clamp only below 75% of the mobile leg: a genuine desktop-column layout (300px
            // column vs 390px mobile full-width) sits above that ratio and keeps its honest
            // measurement; the shrink states (~50-62%) sit below it. The max is paint-safe —
            // a rung one step large is invisible, one step small is not.
            if ($wpc_m131 >= 24 && $wpc_d131 >= 24 && $wpc_d131 * 4 < $wpc_m131 * 3) {
                $wpc_d131 = $wpc_m131;
            }
            if ($wpc_m131 < 24 && $wpc_d131 < 24) { return ''; }
            $wpc_aw131 = preg_match('/\swidth\s*=\s*["\']?(\d{2,4})/', (string) $imgTag, $wpc_awm131) ? (int) $wpc_awm131[1] : 0;
            $wpc_fb131 = ($wpc_aw131 >= 100 && $wpc_aw131 <= 4000) ? ($wpc_aw131 . 'px') : '100vw';
            if ($wpc_m131 >= 24 && $wpc_d131 >= 24) {
                return ($wpc_m131 !== $wpc_d131)
                    ? '(max-width: 767.98px) ' . $wpc_m131 . 'px, ' . $wpc_d131 . 'px'
                    : $wpc_d131 . 'px';
            }
            if ($wpc_m131 >= 24) { return '(max-width: 767.98px) ' . $wpc_m131 . 'px, ' . $wpc_fb131; }
            return '(max-width: 767.98px) 100vw, ' . $wpc_d131 . 'px';
        } catch (\Throwable $e) {
            return '';
        }
    }


    public static function wpc_lcp_json_file()
    {
        static $wpc_f131 = null;
        if ($wpc_f131 !== null) {
            return $wpc_f131;
        }
        $wpc_f131 = '';
        try {
            if (defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                $wpc_keys131 = [];
                try {
                    $wpc_req131 = isset($_SERVER['REQUEST_URI'])
                        ? home_url(strtok((string) $_SERVER['REQUEST_URI'], '?')) : '';
                    if ($wpc_req131 !== '') { $wpc_keys131[] = (new wps_ic_url_key())->setup($wpc_req131); }
                } catch (\Throwable $e) {
                }
                try {
                    $wpc_keys131[] = (new wps_ic_url_key())->setup(home_url('/'));
                } catch (\Throwable $e) {
                }
                foreach (array_unique(array_filter($wpc_keys131)) as $wpc_k131) {
                    if (@is_readable(WPS_IC_CRITICAL . $wpc_k131 . '/lcp.json')) {
                        $wpc_f131 = WPS_IC_CRITICAL . $wpc_k131 . '/lcp.json';
                        break;
                    }
                }
            }
            if ($wpc_f131 === '' && class_exists('wps_criticalCss')) {
                $wpc_ex131 = (new wps_criticalCss())->criticalExists(true);
                if (!empty($wpc_ex131['desktop']) && @is_readable(dirname($wpc_ex131['desktop']) . '/lcp.json')) {
                    $wpc_f131 = dirname($wpc_ex131['desktop']) . '/lcp.json';
                }
            }
        } catch (\Throwable $e) {
            $wpc_f131 = '';
        }
        return $wpc_f131;
    }

    // True when the service §14 lcp.json carries a real hero preload directive.
    // The crit head then preloads the measured hero (fires by URL), so the
    // atf-fallback header-logo preload stands down — a tiny logo at High
    // priority was contending with the real LCP. The logo's CLS is still held
    // by the header-img-guard box clamp; only its priority-stealing preload goes.
    public static function wpc_lcp_has_hero_preload()
    {
        static $wpc_hp358 = null;
        if ($wpc_hp358 !== null) {
            return $wpc_hp358;
        }
        $wpc_hp358 = false;
        try {
            $wpc_f358 = self::wpc_lcp_json_file();
            if ($wpc_f358 !== '' && @is_readable($wpc_f358)) {
                $wpc_j358 = json_decode((string) @file_get_contents($wpc_f358), true);
                if (is_array($wpc_j358) && !empty($wpc_j358['hints']['lcp_preload'])
                    && is_array($wpc_j358['hints']['lcp_preload'])) {
                    foreach ($wpc_j358['hints']['lcp_preload'] as $wpc_e358) {
                        if (is_array($wpc_e358) && !empty($wpc_e358['url']) && is_string($wpc_e358['url'])) {
                            $wpc_hp358 = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $wpc_hp358 = false;
        }
        return $wpc_hp358;
    }

    public static function wpc_census_rung_targets($imageUrl)
    {
        static $wpc_map95 = null;
        if ($wpc_map95 === null) {
            $wpc_map95 = [];
            try {


                $wpc_f95 = self::wpc_lcp_json_file();
                {
                    if ($wpc_f95 !== '' && @is_readable($wpc_f95)) {
                        $wpc_j95 = json_decode((string) @file_get_contents($wpc_f95), true);


                        foreach (['atf_images', 'oversized_images'] as $wpc_field131) {
                        if (is_array($wpc_j95) && !empty($wpc_j95[$wpc_field131]) && is_array($wpc_j95[$wpc_field131])) {
                            foreach (['mobile', 'desktop'] as $wpc_dev95) {
                                if (empty($wpc_j95[$wpc_field131][$wpc_dev95]) || !is_array($wpc_j95[$wpc_field131][$wpc_dev95])) { continue; }
                                foreach ($wpc_j95[$wpc_field131][$wpc_dev95] as $wpc_e95) {
                                    if (!is_array($wpc_e95) || empty($wpc_e95['stem']) || !is_string($wpc_e95['stem']) || empty($wpc_e95['css_w'])) { continue; }
                                    $wpc_w95 = (int) $wpc_e95['css_w'];
                                    if ($wpc_w95 < 24 || $wpc_w95 > 2000 || strlen($wpc_e95['stem']) < 3
                                        || !preg_match('/^[A-Za-z0-9._@-]+$/', $wpc_e95['stem'])) { continue; }
                                    $wpc_k95 = strtolower($wpc_e95['stem']);
                                    if (!isset($wpc_map95[$wpc_k95])) { $wpc_map95[$wpc_k95] = []; }


                                    if (!isset(self::$wpc_census_slots[$wpc_k95])) { self::$wpc_census_slots[$wpc_k95] = ['m' => 0, 'd' => 0]; }
                                    $wpc_dk131 = ($wpc_dev95 === 'mobile') ? 'm' : 'd';
                                    if (empty(self::$wpc_census_slots[$wpc_k95][$wpc_dk131])) { self::$wpc_census_slots[$wpc_k95][$wpc_dk131] = $wpc_w95; }


                                    // 340×519 slot). Filter wpc_census_rung_dpr to tune.


                                    // 595w. 2× covers the iPhone class. Slight overshoot beats the
                                    // skip-to-full-size undershoot every time.


                                    $wpc_dprs118 = apply_filters('wpc_census_rung_dprs', [1.0, 1.75, 2.0]);
                                    foreach ((array) $wpc_dprs118 as $wpc_dpr95) {
                                        $wpc_dpr95 = (float) $wpc_dpr95;
                                        if ($wpc_dpr95 < 1 || $wpc_dpr95 > 4) { continue; }
                                        $wpc_map95[$wpc_k95][(int) ceil($wpc_w95 * $wpc_dpr95)] = true;
                                    }
                                }
                            }
                        }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $wpc_map95 = [];
            }
        }
        $wpc_b95 = strtolower(basename((string) preg_replace('/[?#].*$/', '', (string) $imageUrl)));
        $wpc_b95 = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif)$/i', '', $wpc_b95);
        $wpc_b95 = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $wpc_b95), 1);
        $wpc_out95 = (!empty($wpc_map95) && $wpc_b95 !== '' && isset($wpc_map95[$wpc_b95])) ? array_keys($wpc_map95[$wpc_b95]) : [];
        if (isset($_GET['wpc_census_dbg']) && count(self::$wpc_census_dbg) < 60) {
            self::$wpc_census_dbg[] = ['stem' => $wpc_b95, 'map' => array_keys((array) $wpc_map95), 'targets' => $wpc_out95];
        }
        return $wpc_out95;
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
        if (preg_match('#-(\d{2,5})x(\d{2,5})(?:-scaled)?\.(?:jpe?g|png|gif|webp|avif)(?:[?\#]|$)#i', (string) $imageUrl, $wpc_iw791)) {
            $wpc_fw791 = (int) $wpc_iw791[1];
            if ($wpc_fw791 >= 100) {
                $src_w_for_cap = ($src_w_for_cap > 0) ? min($src_w_for_cap, $wpc_fw791) : $wpc_fw791;
            }
        }
        if ($src_w_for_cap > 0) $effective_max = min($effective_max, $src_w_for_cap);

        $widths = array_unique(array_map(function ($w) use ($effective_max) {
            return min($w, $effective_max);
        }, $widths));


        foreach (self::wpc_census_rung_targets($imageUrl) as $wpc_ct107) {
            $wpc_ct107 = (int) $wpc_ct107;
            if ($wpc_ct107 >= 100) {
                $widths[] = min($wpc_ct107, $effective_max);
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

    public function addCritical($html)
    {
        $criticalCss = $this->addCriticalCSS($html);
        $criticalCss = $this->filterCriticalFontFaces($criticalCss);


        $gfFaces = $this->maybeInlineGoogleFontFaces($html, $criticalCss);
        if ($gfFaces !== '' && strpos($criticalCss, 'wpc-fonts-embedded') !== false) {
            // The artifact already embeds its faces.
            $gfFaces = '';
        }
        if ($gfFaces !== '') {
            $gfFaces = $this->filterCriticalFontFaces($gfFaces);
        }
        if ($gfFaces !== '') {
            // Skip faces the critical CSS already declares with a real file source.
            $gfFaces = self::wpc_face_dedupe($gfFaces, $criticalCss);
        }
        if ($gfFaces !== '' && apply_filters('wpc_gfaces_latin_only', self::wpc_gfaces_latin_default())) {
            $gfFaces = self::wpc_gfaces_prune_ranges($gfFaces);
        }
        if ($gfFaces !== '') {
            $criticalCss = $gfFaces . $criticalCss;


            if (stripos($criticalCss, 'fonts.gstatic.com') !== false) {
                // Remove only the exact faces the inlined set replaces: same family+weight+style,
                // latin-covering range. Other subsets and weights keep their original faces.
                $wpc_repl151 = [];
                if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $gfFaces, $wpc_gm151)) {
                    foreach ($wpc_gm151[0] as $wpc_gf151) {
                        $k = self::wpc_face_key($wpc_gf151);
                        if ($k !== '') {
                            $wpc_repl151[$k] = 1;
                        }
                    }
                }
                if ($wpc_repl151) {
                    $criticalCss = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($wpc_repl151) {
                        if (stripos($m[0], 'fonts.gstatic.com') === false) {
                            return $m[0];
                        }
                        if (!self::wpc_face_range_latin($m[0])) {
                            return $m[0];
                        }
                        $k = self::wpc_face_key($m[0]);
                        return ($k !== '' && isset($wpc_repl151[$k])) ? '' : $m[0];
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

                        if (function_exists('wpc_fonts_htaccess_ensure')) {
                            wpc_fonts_htaccess_ensure($wpc_fd);
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
        $wpc_ss207b = (int) get_option('wpc_subsets_seen', 0);
        if (!empty(self::$settings['preload-crit-fonts']) && self::$settings['preload-crit-fonts'] == '1'
            && (stripos($criticalCss, 'data:font/woff2;base64') === false
                || !apply_filters('wpc_subset_covers_preloads', true))
            && !($wpc_ss207b && (time() - $wpc_ss207b) < 7 * DAY_IN_SECONDS && apply_filters('wpc_subset_covers_preloads', true))) {
            $preloadLinks = $this->extractCriticalFontPreloads($criticalCss);
            $criticalCss = $preloadLinks . $criticalCss;


            if (function_exists('wpc_perf_debug_allowed741') && wpc_perf_debug_allowed741()
                && !empty(self::$wpc_font_preloads_emitted)) {
                $wpc_pl43  = (array) self::$wpc_font_preloads_emitted;
                $wpc_hay43 = $html . $criticalCss;
                $wpc_ok43  = 0; $wpc_miss43 = [];
                foreach ($wpc_pl43 as $wpc_p43) {
                    if (strpos($wpc_hay43, (string) $wpc_p43) !== false) { $wpc_ok43++; }
                    else { $wpc_miss43[] = basename((string) $wpc_p43); }
                }
                $criticalCss .= "\r\n<!-- WPC-FONT-PRELOAD-PARITY " . $wpc_ok43 . '/' . count($wpc_pl43)
                    . ($wpc_miss43 ? ' MISS:' . implode(',', array_slice($wpc_miss43, 0, 4)) : ' OK') . " -->";
            }
        }

        if (!empty($_GET['extractCrit'])) {
            return print_r([$criticalCss], true);
        }


        // Canonical WordPress screen-reader/skip-link hiding — always present with crit so a
        // pruned theme rule can never surface these links visibly.
        if (apply_filters('wpc_sr_guard', true)
            && (stripos($html, 'skip-link') !== false || stripos($html, 'screen-reader-text') !== false)) {
            $criticalCss .= "\r\n" . '<style id="wpc-sr-guard">.screen-reader-text,.skip-link{border:0;clip:rect(1px,1px,1px,1px);clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;width:1px;word-wrap:normal}.screen-reader-text:focus,.skip-link:focus{clip:auto;clip-path:none;height:auto;width:auto;overflow:visible;position:absolute;left:6px;top:6px;z-index:100000;padding:8px 16px;background:#fff}</style>';
        }
        // Content-embedded <img class="emoji"> needs core's 1em rule at first paint.
        if (apply_filters('wpc_emoji_guard', true)
            && (strpos($html, 'class="emoji"') !== false || strpos($html, "class='emoji'") !== false || strpos($html, 'wp-smiley') !== false)) {
            $criticalCss .= "\r\n" . '<style id="wpc-emoji-guard">img.wp-smiley,img.emoji{display:inline!important;border:none!important;box-shadow:none!important;height:1em!important;width:1em!important;margin:0 .07em!important;vertical-align:-0.1em!important;background:none!important;padding:0!important}</style>';
        }
        // A header image's displayed box must be fully determined at first paint: attribute
        // dims give the ratio, but without the container clamp (which normally arrives with
        // the deferred sheets) the image can paint at its full attribute width and re-lay the
        // header when the real CSS lands. The clamp makes the pre-CSS box = final box.
        if (apply_filters('wpc_header_img_guard', true)
            && preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,3000}?<img/is', $html)) {
            // :where() keeps the guard at (0,0,1) so a theme's own header sizing wins ties (.354 class).
            $criticalCss .= "\r\n" . '<style id="wpc-header-img-guard">:where(header) img,:where(.elementor-location-header) img{max-width:100%;height:auto}</style>';
        }
        // The header logo paints in every viewport's first frame — if the file lands after
        // paint, its arrival re-lays the header row (the visible menu drop). Preload the
        // first header image so it exists before the frame it appears in.
        if (apply_filters('wpc_header_logo_preload', true)
            && !self::wpc_lcp_has_hero_preload()
            && preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,3000}?<img[^>]*src="([^"]+\.(?:svg|png|webp|avif|jpe?g))(?:\?[^"]*)?"/is', $html, $wpc_hl199)
            && stripos($criticalCss, esc_url($wpc_hl199[1])) === false
            && self::wpc_lcp_bg_url_allowed($wpc_hl199[1])
            // v7.10.718 - an svg the eager lane will inline as data: needs no preload; the
            // preload would fetch a URL the page never uses.
            && !(function_exists('wpc_svg_inline_data718') && wpc_svg_inline_data718($wpc_hl199[1]) !== '')) {
            $criticalCss .= "\r\n" . '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_hl199[1]) . '" id="wpc-header-logo-preload">';
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
            $wpc_fa848 = (stripos($html, 'fontawesome') !== false
                || stripos($html, 'font-awesome') !== false
                || preg_match('/<link[^>]+href="[^"]*\/fa-[^"]*\.css[^"]*"/i', $html)
                || preg_match('/font-family\s*:\s*["\']?\s*font.?awesome/i', $html . $criticalCss));
            if (apply_filters('wpc_icon_guard_witness', $wpc_fa848, $html)) {
                $criticalCss .= "\r\n" . '<style id="wpc-icon-guard">i[class^="fa-"],i[class*=" fa-"],span[class^="fa-"],span[class*=" fa-"]{display:inline-block;min-width:1em;height:1em;line-height:1}</style>';
                $criticalCss .= "\r\n" . '<script id="wpc-icon-belt">(function(){var g=null;function fa(){var ok=false;try{document.fonts.forEach(function(f){if(f.status===\'loaded\'&&/font.?awesome|fa-(?:brands|solid|regular|light|duotone|sharp)/i.test(f.family)){ok=true}})}catch(e){ok=true}return ok}function set(off){g=g||document.getElementById(\'wpc-icon-guard\');if(g){g.media=off?\'not all\':\'all\'}}function check(){set(!fa())}if(document.fonts&&document.fonts.ready&&document.fonts.forEach){document.fonts.ready.then(function(){setTimeout(check,3500)});if(document.fonts.addEventListener){document.fonts.addEventListener(\'loadingdone\',function(){if(fa()){set(false)}})}}})();</script>';
            }
        }
        // Native next-page prefetch (Speculation Rules): pointer-down prefetch of same-site
        // links so the next navigation paints near-instantly. Conservative eagerness fires
        // ~100ms before the click would anyway; state-changing and session URLs excluded.
        // Non-supporting browsers ignore the block entirely.
        if (apply_filters('wpc_speculation_rules', true) && stripos($html, 'speculationrules') === false) {
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
            $criticalCss .= "\r\n" . '<style id="wpc-shape-fill-guard">.elementor-shape-fill{fill:transparent}</style>';
        }
        if (apply_filters('wpc_bg_video_guard', true) && stripos($html, 'elementor-background-video') !== false) {
            $criticalCss .= "\r\n" . '<style id="wpc-bg-video-guard">.elementor-background-video-container{position:absolute;top:0;left:0;width:100%;height:100%;overflow:hidden;pointer-events:none}.elementor-background-video-hosted,.elementor-background-video-embed{position:absolute;max-width:none}</style>';
        }
        if (apply_filters('wpc_below_fold_cv', true) && stripos($html, 'elementor-top-section') !== false) {
            $criticalCss .= "\r\n" . '<style id="wpc-cv-guard">.elementor > section.elementor-top-section:nth-of-type(n+4),.elementor > main.elementor-top-section ~ section.elementor-top-section:nth-of-type(n+4),[data-wpc-cv]{content-visibility:auto;contain-intrinsic-size:auto 600px}@media print{[data-wpc-cv],.elementor .elementor-top-section{content-visibility:visible}}</style>';
        }
        if (apply_filters('wpc_elementor_anim_start_state', true) && stripos($html, 'elementor-invisible') !== false) {
            $criticalCss .= "\r\n" . '<style id="wpc-elementor-anim-start">.elementor-invisible{visibility:hidden}</style>';
        }


        if (apply_filters('wpc_cky_reveal_neutralize', true) && (stripos($html, 'cky-') !== false || stripos($html, 'cookieyes') !== false)) {


            $criticalCss .= "\r\n" . '<style id="wpc-cky-reveal">.cky-consent-container,.cky-consent-bar{animation:none!important;transform:none!important;transition:opacity .18s ease!important;}</style>';
        }


        $wpc_fa_s = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $wpc_fa_on = is_array($wpc_fa_s) && !empty($wpc_fa_s['fontawesome-optimize']) && $wpc_fa_s['fontawesome-optimize'] == '1';
        if (apply_filters('wpc_fa_optimize', $wpc_fa_on) && (stripos($html, 'font-awesome') !== false || stripos($html, 'fontawesome') !== false)) {
            $criticalCss .= "\r\n" . '<style id="wpc-fa-reserve">.fa,.fas,.far,.fab,.fal,.fad,.fak,.fass,.fasr,i[class^="fa-"],i[class*=" fa-"]{display:inline-block;min-width:1em;font-style:normal}</style>';
        }


        if (apply_filters('wpc_lazy_thumb_blackflash_guard', true)
            && preg_match('/\.post-thumbnail\s+a\s*\{[^}]*background[^;}]*(?:#0{3,6}\b|\bblack\b)/i', $criticalCss)) {
            $criticalCss .= "\r\n" . '<style type="text/css" id="wpc-lazy-thumb-bgfix">.post-thumbnail a[href]{background:transparent}</style>';
        }

        $html = str_replace('<!--WPC_INSERT_CRITICAL-->', $criticalCss, $html);
        // §3b (v7.10.676) — both vehicles are now in the document (the subset just injected, the
        // carrier already echoed by wp_head): drop any inline data: subset face a later range-free
        // URL twin already overrides. No-op when there is no twin (safe by construction).
        $html = self::wpc_dedupe_dead_subsets676($html);
        // §8(c) (v7.10.680) — act on the wire manifest's font-family drop[] entries: defer a
        // census-proven below-fold family (e.g. Roboto) from its inline vehicle to #wpc-late-faces.
        // No-op until a gen carries a font-family drop (needs the wire consumed by §2, on disk).
        $html = self::wpc_defer_wire_dropfaces680($html);
        // §8.1 (v7.10.681) — act on the wire's LCP-asset decision: inline an SVG hero (local-read,
        // ≤12KB) as a data: URI + drop its preload, per the service verdict. No-op until a gen
        // carries an lcp entry with verdict:inline-data-uri.
        $html = self::wpc_inline_wire_lcp681($html);
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


            if (function_exists('wpc_repull_kick_now') && !get_transient('wpc_kick_fire_' . md5($wpc_sk))
                && !(function_exists('wpc_is_low_value_page') && wpc_is_low_value_page())
                && !(function_exists('wpc_kick_is_dead') && wpc_kick_is_dead($wpc_sk))
                && !(function_exists('wpc_kick_budget_ok') && !wpc_kick_budget_ok())
                && apply_filters('wpc_render_kick', true)) {
                wpc_repull_kick_now($wpc_sk); // HTTP-primary since .69; own-site generate on empty store
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('render-kick', $wpc_sk, '', []);
                }
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
            return wpc_body_inject809($html, $wpc_tag);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function addCriticalCSS($html)
    {
        $output = '';

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists(true);


        // v7.10.617 — ARTIFACT SANITY GATE. A crit blob blind to the page's own content
        // sections (measured 2026-07-30: 121KB crit, zero rules for a 731px ATF hero)
        // must not paint. Mark content-keyed so criticalExists() resolves it ABSENT on
        // every later render; a regenerated artifact has different bytes and self-clears.
        if (!empty($criticalCSSExists['desktop']) && function_exists('wpc_atf_section_ids617')) {
            $wpc_ids617 = wpc_atf_section_ids617($html);
            // v7.20.16 — archive-loop blindness rides the same gate: when the Elementor
            // discriminator finds nothing, a repeated ATF loop container whose class
            // tokens appear NOWHERE in the crit condemns the artifact the same way
            // (mark content-keyed -> critless fail-open; .621 stall stops the kick loop).
            if (count($wpc_ids617) < 2 && function_exists('wpc_loop_grid_tokens16')
                && apply_filters('wpc_loop_sanity_gate', true)) {
                $wpc_lt16 = wpc_loop_grid_tokens16($html);
                if ($wpc_lt16) {
                    $wpc_critb16 = (string) @file_get_contents($criticalCSSExists['desktop']);
                    if ($wpc_critb16 !== '' && function_exists('wpc_artifact_covers_loop16')
                        && !wpc_artifact_covers_loop16($wpc_critb16, $wpc_lt16)) {
                        $wpc_ids617 = array_map(function ($t) { return 'loop:' . $t; }, $wpc_lt16);
                        $wpc_ids617[] = 'loop:pad';           // reach the >=2 gate below with the loop identity
                    }
                }
            }
            if (count($wpc_ids617) >= 2) {
                $wpc_critb617 = (string) @file_get_contents($criticalCSSExists['desktop']);
                if ($wpc_critb617 !== '' && !wpc_artifact_covers_atf617($wpc_critb617, $wpc_ids617)) {
                    $wpc_cd617 = dirname($criticalCSSExists['desktop']);
                    // v7.10.621 — same bytes re-detected = the generator is reproducing the
                    // blind artifact deterministically; kicking again only loops.
                    $wpc_stall621 = function_exists('wpc_crit_sanity_stall621')
                        ? wpc_crit_sanity_stall621($wpc_cd617, $wpc_critb617) : 0;
                    wpc_crit_sanity_mark617($wpc_cd617, $wpc_critb617, $wpc_ids617);
                    $wpc_sk617 = basename($wpc_cd617);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log($wpc_stall621 >= 2 ? 'crit-sanity-stall' : 'crit-sanity-blind',
                            $wpc_sk617, (string) ($_SERVER['REQUEST_URI'] ?? ''), [
                            'ids'   => implode(',', $wpc_ids617),
                            'stall' => $wpc_stall621,
                            'head'  => substr($wpc_critb617, 0, 120),
                        ]);
                    }
                    if (function_exists('wpc_repull_kick_now') && $wpc_sk617 !== ''
                        && (!function_exists('wpc_crit_sanity_kick_ok621')
                            || wpc_crit_sanity_kick_ok621($wpc_cd617, $wpc_stall621))
                        && !get_transient('wpc_kick_fire_' . md5($wpc_sk617))) {
                        wpc_repull_kick_now($wpc_sk617);
                    }
                    $criticalCSSExists = false;
                }
            }
        }

        if (!empty($criticalCSSExists) && empty($_GET['removeCritical'])) {


            if (!empty($criticalCSSExists['desktop']) && function_exists('wpc_repull_kick_now')) {
                $wpc_stale_d73 = dirname($criticalCSSExists['desktop']);
                if (@is_file($wpc_stale_d73 . '/stale.txt')) {
                    $wpc_stale_k73 = basename($wpc_stale_d73);
                    if ($wpc_stale_k73 !== '' && !get_transient('wpc_kick_fire_' . md5($wpc_stale_k73))) {
                        wpc_repull_kick_now($wpc_stale_k73);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('render-kick-stale', $wpc_stale_k73, '', []);
                        }
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
                                            wps_ic_cache_integrations::purgeCacheFiles($wpc_heal_key);
                                            wpc_foreign_purge610($wpc_heal_key, 'self-heal');
                                        }
                                    }
                                }
                            }

                            @file_put_contents($wpc_heal_dir . 'lcp_heal.json', wp_json_encode([
                                'at' => gmdate('c'), 'http_status' => $wpc_h_status, 'wrote' => $wpc_h_wrote, 'mode' => 'inline',
                            ]));
                        }
                    }
                }
                $wpc_lcp_el   = null;
                $wpc_lcp_meta = [];
                if (is_readable($wpc_lcp_file)) {
                    $wpc_lcp_json = json_decode((string) file_get_contents($wpc_lcp_file), true);


                    if (is_array($wpc_lcp_json) && isset($wpc_lcp_json['lcp_element']) && is_array($wpc_lcp_json['lcp_element'])) {
                        $wpc_lcp_el = $wpc_lcp_json['lcp_element'];
                    }
                    if (is_array($wpc_lcp_json) && isset($wpc_lcp_json['meta']) && is_array($wpc_lcp_json['meta'])) {
                        $wpc_lcp_meta = $wpc_lcp_json['meta'];
                    }
                    // v7.10.383 — hint from lcp_element's per-device measured geometry (stem +
                    // css_w), NOT the sparse legacy 'lcp' key: a flat one-width hint dressed the
                    // mobile bucket with the desktop slot (sizes=510px on a phone = +47KiB rung).
                    $wpc_lcp_hint = null;
                    if (is_array($wpc_lcp_el)) {
                        $wpc_hint383 = [];
                        foreach (['mobile', 'desktop'] as $wpc_hd383) {
                            if (!empty($wpc_lcp_el[$wpc_hd383]['stem']) && is_string($wpc_lcp_el[$wpc_hd383]['stem'])) {
                                $wpc_hint383[$wpc_hd383] = [
                                    'stem'  => (string) $wpc_lcp_el[$wpc_hd383]['stem'],
                                    'width' => (int) ($wpc_lcp_el[$wpc_hd383]['css_w'] ?? 0),
                                ];
                            }
                        }
                        if (!empty($wpc_hint383)) { $wpc_lcp_hint = $wpc_hint383; }
                    }
                    if ($wpc_lcp_hint === null) {
                        $wpc_lcp_hint = (is_array($wpc_lcp_json) && isset($wpc_lcp_json['lcp']) && is_array($wpc_lcp_json['lcp']))
                            ? $wpc_lcp_json['lcp']
                            : (is_array($wpc_lcp_json) ? $wpc_lcp_json : null);
                    }
                    if (is_array($wpc_lcp_hint) && !empty($wpc_lcp_hint)) {
                        add_filter('wpc_lcp_hint', function () use ($wpc_lcp_hint) { return $wpc_lcp_hint; }, 5);
                    }


                    // §14 resource hints (v3.74.1 LIVE): preconnect the MEASURED hero/font/3p
                    // hosts so the LCP connection is warm — the ceiling model credits this, so
                    // without it a site can't reach its own predicted ceiling. ≤4, https-only,
                    // host-deduped, additive (worst case of a wrong preconnect = one idle socket).
                    // Device-tagged fetchpriority rides the existing media-scoped image-preload
                    // mechanism below (the img rewriter honors wpc_lcp_fetchpriority_hints).
                    if (is_array($wpc_lcp_json) && !empty($wpc_lcp_json['hints']) && is_array($wpc_lcp_json['hints'])
                        && apply_filters('wpc_lcp_resource_hints', true)) {
                        $wpc_hints357 = $wpc_lcp_json['hints'];
                        if (!empty($wpc_hints357['preconnect']) && is_array($wpc_hints357['preconnect'])) {
                            $wpc_pc_seen357 = [];
                            $wpc_pc_out357  = '';
                            foreach ($wpc_hints357['preconnect'] as $wpc_pc357) {
                                if (count($wpc_pc_seen357) >= 4) { break; }
                                if (!is_string($wpc_pc357)) { continue; }
                                $wpc_pcu357 = rtrim(trim($wpc_pc357), '/');
                                if (!preg_match('#^https://[a-z0-9.\-]+(?::\d+)?$#i', $wpc_pcu357)) { continue; }
                                $wpc_pch357 = strtolower((string) parse_url($wpc_pcu357, PHP_URL_HOST));
                                if ($wpc_pch357 === '' || isset($wpc_pc_seen357[$wpc_pch357])) { continue; }
                                $wpc_pc_seen357[$wpc_pch357] = 1;
                                $wpc_pc_out357 .= '<link rel="preconnect" href="' . esc_url($wpc_pcu357) . '">';
                            }
                            if ($wpc_pc_out357 !== '') {
                                $output .= "\r\n" . $wpc_pc_out357;
                            }
                        }
                        // Expose the device-tagged fetchpriority hints (selector/value/device,
                        // sel_unique-only per service) for the image-rewriter to apply per
                        // viewport — never both on one device (off-device <img> contends at High).
                        if (!empty($wpc_hints357['fetchpriority']) && is_array($wpc_hints357['fetchpriority'])) {
                            $wpc_fp357 = array_slice(array_values(array_filter($wpc_hints357['fetchpriority'], function ($e) {
                                return is_array($e) && !empty($e['selector']) && in_array((string) ($e['device'] ?? 'both'), ['mobile', 'desktop', 'both'], true);
                            })), 0, 4);
                            if (!empty($wpc_fp357)) {
                                add_filter('wpc_lcp_fetchpriority_hints', function () use ($wpc_fp357) { return $wpc_fp357; }, 5);
                            }
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
                        if (!empty($wpc_hints357['lcp_preload']) && is_array($wpc_hints357['lcp_preload'])) {
                            $wpc_lp_seen357 = [];
                            foreach ($wpc_hints357['lcp_preload'] as $wpc_lp357) {
                                if (count($wpc_lp_seen357) >= 2) { break; }
                                if (!is_array($wpc_lp357) || empty($wpc_lp357['url']) || !is_string($wpc_lp357['url'])) { continue; }
                                $wpc_lpu357 = trim($wpc_lp357['url']);
                                if (!preg_match('#^https?://#i', $wpc_lpu357) || !self::wpc_lcp_bg_url_allowed($wpc_lpu357)) { continue; }
                                $wpc_lpk357 = strtolower($wpc_lpu357);
                                if (isset($wpc_lp_seen357[$wpc_lpk357])) { continue; }
                                $wpc_lpd357 = in_array((string) ($wpc_lp357['device'] ?? 'both'), ['mobile', 'desktop', 'both'], true)
                                    ? (string) ($wpc_lp357['device'] ?? 'both') : 'both';
                                $wpc_lpmedia357 = ($wpc_lpd357 === 'mobile') ? ' media="(max-width: 767.98px)"'
                                    : (($wpc_lpd357 === 'desktop') ? ' media="(min-width: 768px)"' : '');
                                // url_is_authoritative (service 3.79.0): true = non-responsive <img> /
                                // CSS-bg → preload the url VERBATIM (query intact). false = responsive
                                // <img srcset> → the browser picks from the LIVE srcset at the real
                                // device, so a bare-href preload prefetches a candidate it never uses
                                // (busyprosai: 73KB wasted + the hero fetched twice). Only preload a
                                // responsive hero WITH a matching imagesrcset (supplied by the img lane
                                // via the filter); otherwise SKIP and let the <img>'s own srcset load
                                // it. Absent (pre-3.79 gens) = authoritative = prior verbatim behavior.
                                $wpc_lpauth357 = array_key_exists('url_is_authoritative', $wpc_lp357)
                                    ? (bool) $wpc_lp357['url_is_authoritative'] : true;
                                // css_w (service §14) = the MEASURED rendered slot in CSS px. It is the
                                // authoritative sizes value; markup can only be read pre-bake here, which
                                // yielded WP's default 100vw instead of the real 328px. Absent when the LCP
                                // is text/eager — then fall back to the markup read.
                                // A device:"both" entry carries the MOBILE measurement, and "both" emits no
                                // media attr — applying a mobile slot to desktop mis-picks the rung and the
                                // hero downloads twice. Scope such an entry to mobile; desktop then loads
                                // from the <img>'s own srcset (no preload, but never a wasted one).
                                $wpc_lpcssw357 = (isset($wpc_lp357['css_w']) && is_numeric($wpc_lp357['css_w']) && (int) $wpc_lp357['css_w'] > 0)
                                    ? (int) $wpc_lp357['css_w'] : 0;
                                if ($wpc_lpcssw357 > 0 && $wpc_lpd357 === 'both') {
                                    $wpc_lpmedia357 = ' media="(max-width: 767.98px)"';
                                }
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
                                $wpc_lprg444 = '';
                                if (!empty($wpc_lp357['rungs_complete'])
                                    && !empty($wpc_lp357['rungs']) && is_array($wpc_lp357['rungs'])) {
                                    $wpc_parts444 = [];
                                    $wpc_kind444  = null;
                                    foreach ($wpc_lp357['rungs'] as $wpc_e444) {
                                        if (!is_array($wpc_e444) || empty($wpc_e444['url']) || !is_string($wpc_e444['url'])
                                            || !preg_match('#^https?://#i', trim($wpc_e444['url']))) {
                                            $wpc_parts444 = []; break;
                                        }
                                        $wpc_u444 = trim($wpc_e444['url']);
                                        if (isset($wpc_e444['w']) && (int) $wpc_e444['w'] > 0) {
                                            $wpc_k444 = 'w';
                                            $wpc_d444 = ' ' . (int) $wpc_e444['w'] . 'w';
                                        } elseif (isset($wpc_e444['x']) && (float) $wpc_e444['x'] > 0) {
                                            $wpc_k444 = 'x';
                                            $wpc_d444 = ' ' . rtrim(rtrim(sprintf('%.3F', (float) $wpc_e444['x']), '0'), '.') . 'x';
                                        } else {
                                            $wpc_k444 = 'bare';
                                            $wpc_d444 = '';
                                        }
                                        if ($wpc_kind444 !== null && $wpc_kind444 !== $wpc_k444) {
                                            $wpc_parts444 = []; break;
                                        }
                                        $wpc_kind444    = $wpc_k444;
                                        $wpc_parts444[] = $wpc_u444 . $wpc_d444;
                                        if (count($wpc_parts444) >= 16) { break; }
                                    }
                                    // A bare-only ladder carries no candidate information a preload can
                                    // act on (one implicit 1x) — let the markup path handle it instead.
                                    if (!empty($wpc_parts444) && $wpc_kind444 !== 'bare') {
                                        $wpc_lprg444 = implode(', ', $wpc_parts444);
                                    }
                                }
                                $wpc_lpiss357  = apply_filters('wpc_lcp_preload_imagesrcset',
                                    ($wpc_lprg444 !== '' ? $wpc_lprg444 : self::wpc_lcp_img_responsive($html, $wpc_lpu357, 'srcset')),
                                    $wpc_lpu357, $wpc_lpd357);
                                // The 2000-char ceiling was sized for a SCRAPED srcset of short
                                // relative URLs. A service ladder is 14 ABSOLUTE zone URLs at ~150
                                // chars each = 2,109 on busy — it failed by 109 characters, silently,
                                // and because url_is_authoritative was true the fallback emitted a
                                // BARE-HREF preload on a responsive image: the exact double-fetch this
                                // path exists to prevent. ~2KB of attribute in a 320KB document is
                                // 0.65% to avoid a duplicate image fetch.
                                $wpc_isscap444 = (int) apply_filters('wpc_lcp_preload_imagesrcset_max', 8192);
                                $wpc_lphasiss357 = is_string($wpc_lpiss357) && $wpc_lpiss357 !== ''
                                    && strlen($wpc_lpiss357) <= max(2000, $wpc_isscap444);
                                if (is_string($wpc_lpiss357) && strlen($wpc_lpiss357) > max(2000, $wpc_isscap444)
                                    && function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('lcp-preload-srcset-oversize', '', '', [
                                        'dev' => $wpc_lpd357, 'len' => strlen($wpc_lpiss357), 'cap' => max(2000, $wpc_isscap444),
                                    ]);
                                }
                                if ($wpc_lprg444 !== '' && function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('lcp-preload-ladder', '', '', ['dev' => $wpc_lpd357, 'n' => count(explode(',', $wpc_lprg444)), 'len' => strlen($wpc_lprg444)]);
                                }
                                // sizes_attr is the service's measured sizes for THIS device — it beats
                                // both the css_w single value and any markup read.
                                $wpc_lpsa444 = (!empty($wpc_lp357['sizes_attr']) && is_string($wpc_lp357['sizes_attr'])
                                                && strlen($wpc_lp357['sizes_attr']) <= 400)
                                    ? trim($wpc_lp357['sizes_attr']) : '';
                                if (!$wpc_lpauth357 && !$wpc_lphasiss357) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-responsive', '', '', ['dev' => $wpc_lpd357]);
                                    }
                                    continue; // no bare-href preload on a responsive hero — it duplicates
                                }
                                $wpc_lp_seen357[$wpc_lpk357] = 1;
                                $wpc_lpattr357 = '';
                                if ($wpc_lphasiss357) {
                                    $wpc_lpattr357 .= ' imagesrcset="' . esc_attr($wpc_lpiss357) . '"';
                                    $wpc_lpisz357 = apply_filters('wpc_lcp_preload_imagesizes',
                                        ($wpc_lpsa444 !== '' ? $wpc_lpsa444
                                            : ($wpc_lpcssw357 > 0 ? $wpc_lpcssw357 . 'px'
                                               : self::wpc_lcp_img_responsive($html, $wpc_lpu357, 'sizes'))),
                                        $wpc_lpu357, $wpc_lpd357);
                                    if (is_string($wpc_lpisz357) && $wpc_lpisz357 !== '' && strlen($wpc_lpisz357) <= 400) {
                                        $wpc_lpattr357 .= ' imagesizes="' . esc_attr($wpc_lpisz357) . '"';
                                    }
                                }
                                // Preserve any query (?src=png) on the href — esc_url keeps it; the byte
                                // form must byte-match the <img> the browser loads or it double-fetches.
                                // A VIDEO can be the measured LCP element (Divi hero bg video) — its file
                                // must never ride an as="image" fetchpriority=high preload: that forces the
                                // full container down the LCP window while the <video> itself is deferred
                                // (bestofmargaretriver: 6.7MB mp4 preloaded twice, LCP 5.7s). And the same
                                // link must never be emitted twice into one document.
                                $wpc_lptag839 = '<link rel="preload" as="image" fetchpriority="high" href="'
                                    . esc_url($wpc_lpu357) . '"' . $wpc_lpattr357 . $wpc_lpmedia357 . ' id="wpc-lcp-hero-preload">';
                                if (preg_match('/\.(?:mp4|webm|ogv|ogg|mov|m3u8)(?:\?|$)/i', (string) $wpc_lpu357)) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-video', '', '', ['dev' => $wpc_lpd357]);
                                    }
                                } elseif (strpos($output, $wpc_lptag839) === false) {
                                    $output .= "\r\n" . $wpc_lptag839;
                                }
                            }
                        }
                    }


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
                                if ($wpc_afold_map[$wpc_atf_st][$wpc_atf_slot] === 0) {
                                    $wpc_afold_map[$wpc_atf_st][$wpc_atf_slot] = (int) round((float) $wpc_atf_im['css_w']);
                                }
                            }
                        }
                        if (!empty($wpc_afold_map)) {
                            add_filter('wpc_afold_image_hints', function () use ($wpc_afold_map) { return $wpc_afold_map; }, 5);
                        }
                    }
                }


                if (self::wpc_combined_crit_on()) {
                    $output .= self::wpc_cls_reserve_style(dirname($criticalCSSExists['desktop']), true);
                    $output .= str_replace('id="wpc-cls-reserve"', 'id="wpc-cls-reserve-d"',
                        self::wpc_cls_reserve_style(dirname($criticalCSSExists['desktop']), false));
                } else {
                    $output .= self::wpc_cls_reserve_style(dirname($criticalCSSExists['desktop']), $this->isMobile());
                }
            }
            if (file_exists($criticalCSSExists['desktop']) && file_exists($criticalCSSExists['mobile'])) {
                $criticalCSSContent_Desktop = file_get_contents($criticalCSSExists['desktop']);
                $criticalCSSContent_Mobile = file_get_contents($criticalCSSExists['mobile']);


                // (two #wpc-font-subsets tags in one document). One emission per request, ever.
                static $wpc_sub_emitted94 = false;
                if (!$wpc_sub_emitted94
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


                        if (strpos($criticalCSSContent_Desktop, 'wpc-fonts-embedded') === false
                            && strpos($criticalCSSContent_Desktop, 'data:font/woff2;base64') === false
                            && (!is_string($criticalCSSContent_Mobile)
                                || (strpos($criticalCSSContent_Mobile, 'wpc-fonts-embedded') === false
                                    && strpos($criticalCSSContent_Mobile, 'data:font/woff2;base64') === false))) {


                            $wpc_v2_69 = (strncmp($wpc_sub_c, '/*wpc-subsets-v2*/', 18) === 0) ? ' data-wpc-v2="1"' : '';
                            $output .= "\r\n" . '<style type="text/css" id="wpc-font-subsets"' . $wpc_v2_69 . '>' . $wpc_sub_c . '</style>';
                            $wpc_sub_emitted94 = true;
                        }
                    }
                }


                if ($criticalCSSExists['desktop']) {
                    $wpc_trim_dir = dirname($criticalCSSExists['desktop']) . '/';
                    $criticalCSSContent_Desktop = self::wpc_trim_crit_fontface($criticalCSSContent_Desktop, $wpc_trim_dir);
                    $criticalCSSContent_Mobile  = self::wpc_trim_crit_fontface($criticalCSSContent_Mobile, $wpc_trim_dir);
                }


                if (is_string($criticalCSSContent_Desktop) || is_string($criticalCSSContent_Mobile)) {
                    $wpc_var_hay107 = $html . ' ' . (string) $criticalCSSContent_Desktop . ' ' . (string) $criticalCSSContent_Mobile;
                    $criticalCSSContent_Desktop = self::wpc_trim_preset_vars($criticalCSSContent_Desktop, $wpc_var_hay107);
                    $criticalCSSContent_Mobile  = self::wpc_trim_preset_vars($criticalCSSContent_Mobile, $wpc_var_hay107);
                    unset($wpc_var_hay107);
                }

                if (str_contains($criticalCSSContent_Desktop, '<body>') || str_contains($criticalCSSContent_Mobile, '<body>')) {
                    // Do Nothing, it's html
                } else {

                    // Strip content before "/* Preload Fonts */" marker if present (legacy separator)
                    $getCSSContent = function ($cssContent) {
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');
                        return $commentPos !== false ? substr($cssContent, $commentPos + strlen('/* Preload Fonts */')) : $cssContent;
                    };

                    $criticalCSSContent_Desktop = self::wpc_dupe_rule_prune17($getCSSContent($criticalCSSContent_Desktop));
                    $criticalCSSContent_Mobile = self::wpc_dupe_rule_prune17($getCSSContent($criticalCSSContent_Mobile));

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
                    $wpc_devloop57 = self::wpc_combined_crit_on()
                        ? ['mobile', 'desktop']
                        : [$this->isMobile() ? 'mobile' : 'desktop'];
                    foreach ($wpc_devloop57 as $wpc_dev_key) {
                    $wpc_pin_media57 = self::wpc_combined_crit_on()
                        ? ' media="' . (($wpc_dev_key === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)') . '"'
                        : '';
                    $wpc_id_sfx57 = (self::wpc_combined_crit_on() && $wpc_dev_key === 'desktop') ? '-d' : '';
                    $wpc_el = (is_array($wpc_lcp_el) && !empty($wpc_lcp_el[$wpc_dev_key]) && is_array($wpc_lcp_el[$wpc_dev_key]))
                        ? $wpc_lcp_el[$wpc_dev_key] : null;
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
                        $wpc_dpr = ($wpc_dev_key === 'mobile')
                            ? (float) (isset($wpc_lcp_meta['mobile_dpr']) ? $wpc_lcp_meta['mobile_dpr'] : 2)
                            : (float) (isset($wpc_lcp_meta['desktop_dpr']) ? $wpc_lcp_meta['desktop_dpr'] : 1);
                        if ($wpc_dpr <= 0 || $wpc_dpr > 4) { $wpc_dpr = ($wpc_dev_key === 'mobile') ? 2 : 1; }
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


                            // (unique-or-empty). Honor it as the primary signal — true means the
                            // service PROVED one-element match; false means never pin; absent

                            $wpc_sel_unique_flag = isset($wpc_el['sel_unique']) ? $wpc_el['sel_unique'] : null;
                            $wpc_auth_unique = ($wpc_sel_unique_flag === true || $wpc_sel_unique_flag === 1 || $wpc_sel_unique_flag === '1')
                                || ($wpc_sel_unique_flag === null && (bool) preg_match(
                                    '/#[A-Za-z_][\w-]*|\.elementor-element-[0-9a-f]{6,8}(?![\w-])|\[data-id=/i',
                                    $wpc_auth_sel
                                ));
                            $wpc_auth_ok  = ($wpc_auth_sel !== '' && strlen($wpc_auth_sel) <= 240
                                && preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $wpc_auth_sel)
                                && $wpc_auth_unique
                                && apply_filters('wpc_lcp_bg_authority', true));
                            if ($wpc_auth_sel !== '' && !$wpc_auth_unique && function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('lcp-pin-generic-skip', '', '', ['sel' => substr($wpc_auth_sel, 0, 120)]);
                            }
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
                                $wpc_cw_of14 = function ($u) {
                                    if (preg_match('/-(\d+)x\d+\.(?:webp|avif|jpe?g|png)(?:[?#]|$)/i', (string) $u, $m)) { return (int) $m[1]; }
                                    if (preg_match('/\/w:(\d+)\//i', (string) $u, $m)) { return (int) $m[1]; }
                                    return 0;
                                };
                                $wpc_sw14 = $wpc_cw_of14($wpc_sib);
                                if ($wpc_sib !== '' && $wpc_sw14 > 1 && $wpc_sw14 < 1000) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-candidate-undersized-drop', $wpc_dev_key, substr((string) $wpc_sib, -80), ['w' => $wpc_sw14, 'form' => 'sibling']);
                                    }
                                    $wpc_sib = '';
                                }
                                $wpc_pw14 = $wpc_cw_of14($wpc_painted);
                                if ($wpc_painted !== '' && $wpc_pw14 > 1 && $wpc_pw14 < 1000) {
                                    // The painted form IS what the blob carries: dropping it alone
                                    // would leave the rung in the CSS while the preload reverts to
                                    // the full URL (byte-mismatch = double fetch). UN-RUNG the blob
                                    // to the full css_url form, then fall through to it.
                                    $wpc_blob = str_replace($wpc_painted, $wpc_css_url, (string) $wpc_blob);
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-candidate-undersized-drop', $wpc_dev_key, substr((string) $wpc_painted, -80), ['w' => $wpc_pw14, 'form' => 'painted-unrung']);
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
                            if (!self::wpc_lcp_preload_dupe588($output, $wpc_pre_url, $wpc_pin_media57)) {
                                $output .= "\r\n" . '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_pre_url) . '" id="wpc-lcp-bg-preload' . $wpc_id_sfx57 . '"' . $wpc_pin_media57 . '>';
                            }


                            // v7.20.13 — AN UNDERSIZED RUNG MUST NEVER BE PINNED IMPORTANT ON THE
                            // DESKTOP LEG. min-width:768px means the viewport is AT LEAST 768 —
                            // a rung narrower than ~1000px cannot cover it, and !important means
                            // the theme's full-size rule never reclaims the box (vincire: crit
                            // carried the 768x388 painted form; the pin held a 768px bitmap
                            // centered in a 1256px row, grey bands forever). Without the pin the
                            // crit's transient small paint self-heals at sheet arrival.
                            $wpc_auth_wok13 = true;
                            if ($wpc_dev_key !== 'mobile'
                                && preg_match('/-(\d+)x\d+\.(?:webp|avif|jpe?g|png)(?:[?#]|$)/i', (string) $wpc_pre_url, $wpc_rw13)
                                && (int) $wpc_rw13[1] < 1000
                                && !apply_filters('wpc_lcp_bg_small_desktop_pin', false)) {
                                $wpc_auth_wok13 = false;
                                if (function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('lcp-pin-undersized-skip', '', '', ['w' => (int) $wpc_rw13[1], 'url' => substr((string) $wpc_pre_url, -80)]);
                                }
                            }
                            if ($wpc_auth_ok && $wpc_auth_wok13) {
                                $wpc_auth_media = ($wpc_dev_key === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)';
                                $output .= '<style id="wpc-lcp-bg-authority' . $wpc_id_sfx57 . '">@media ' . $wpc_auth_media . '{' . $wpc_auth_sel . '{background-image:url("' . esc_url($wpc_pre_url) . '") !important}}</style>';
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
                            if (!self::wpc_lcp_preload_dupe588($output, $wpc_ad['url'], $wpc_pin_media57)) {
                                $output .= "\r\n" . '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_ad['url']) . '" id="wpc-lcp-bg-preload' . $wpc_id_sfx57 . '"' . $wpc_pin_media57 . '>';
                            }
                            $wpc_ad_sel = isset($wpc_ad['sel']) ? trim((string) $wpc_ad['sel']) : '';
                            if ($wpc_ad_sel !== '' && strlen($wpc_ad_sel) <= 240
                                && preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $wpc_ad_sel)


                                && preg_match('/#[A-Za-z_][\w-]*|\.elementor-element-[0-9a-f]{6,8}(?![\w-])|\[data-id=/i', $wpc_ad_sel)
                                && apply_filters('wpc_lcp_autoderive_authority', false)) {
                                $wpc_ad_media = ($wpc_dev_key === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)';
                                $output .= '<style id="wpc-lcp-bg-authority' . $wpc_id_sfx57 . '">@media ' . $wpc_ad_media . '{' . $wpc_ad_sel . '{background-image:url("' . esc_url($wpc_ad['url']) . '") !important}}</style>';
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


                    if (!empty(self::$settings['used-css']) && self::$settings['used-css'] == '1'
                        && function_exists('wpc_used_css_path') && defined('WPS_IC_CRITICAL_URL')) {
                        $wpc_cd50 = dirname($criticalCSSExists['desktop']) . '/';


                        // v7.10.550 — used_tpl.txt has no producer on either side (service confirmed:
                        // never written, never read, no schema, no upload). tpl.txt is the real key.
                        $wpc_tk50 = @is_file($wpc_cd50 . 'tpl.txt')
                            ? trim((string) @file_get_contents($wpc_cd50 . 'tpl.txt')) : '';


                        if ($wpc_tk50 === '' && function_exists('wpc_compute_tpl_key')) {
                            $wpc_tk137 = (string) wpc_compute_tpl_key();
                            if ($wpc_tk137 !== '') {
                                @file_put_contents($wpc_cd50 . 'tpl.txt', $wpc_tk137);
                                $wpc_tk50 = $wpc_tk137;
                            }
                        }


                        // v7.10.605 — provenance, not just filename: used_tpl.txt names the template
                        // the service GENERATED this content from. Mismatched, the bytes describe
                        // another template and strip rules the live one needs. Skip used-css for
                        // this render and serve the full stylesheet instead.
                        if ($wpc_tk50 !== '' && function_exists('wpc_used_css_provenance_ok')
                            && !wpc_used_css_provenance_ok($wpc_cd50, $wpc_tk50)) {
                            $wpc_tk50 = '';
                        }

                        // v7.10.617 — content sanity twin: an ATF bundle blind to the page's own
                        // content sections strips the design it exists to preserve (measured: the
                        // hero's :not() gradient rule dropped while its overlay sibling survived).
                        // Mark keyed on size:mtime so known-bad short-circuits without a read and a
                        // regenerated file self-clears.
                        if ($wpc_tk50 !== '' && function_exists('wpc_artifact_covers_atf617')) {
                            $wpc_ids617u = wpc_atf_section_ids617($html);
                            if (count($wpc_ids617u) >= 2) {
                                $wpc_chk617 = wpc_used_css_path($wpc_tk50, $this->isMobile() ? 'mobile' : 'desktop');
                                if ($wpc_chk617 === '' || !(@filesize($wpc_chk617) > 64)) {
                                    $wpc_chk617 = wpc_used_css_path($wpc_tk50);
                                }
                                $wpc_atfp617 = (string) preg_replace('/\.css$/', '.atf.css', (string) $wpc_chk617);
                                $wpc_probe617 = @is_file($wpc_atfp617) ? $wpc_atfp617
                                    : (@is_file((string) $wpc_chk617) ? (string) $wpc_chk617 : '');
                                if ($wpc_probe617 !== '') {
                                    if (wpc_ucss_sanity_bad617($wpc_cd50, $wpc_probe617)) {
                                        $wpc_tk50 = '';
                                    } else {
                                        $wpc_atfb617 = (string) @file_get_contents($wpc_probe617);
                                        if ($wpc_atfb617 !== '' && !wpc_artifact_covers_atf617($wpc_atfb617, $wpc_ids617u)) {
                                            wpc_ucss_sanity_mark617($wpc_cd50, $wpc_probe617);
                                            if (function_exists('wpc_cache_first_log')) {
                                                wpc_cache_first_log('ucss-sanity-blind', basename(rtrim($wpc_cd50, '/')), (string) ($_SERVER['REQUEST_URI'] ?? ''), [
                                                    'f' => basename($wpc_probe617),
                                                ]);
                                            }
                                            $wpc_tk50 = '';
                                        }
                                    }
                                }
                            }
                        }

                        $wpc_dev50 = $this->isMobile() ? 'mobile' : 'desktop';


                        $wpc_up_m57 = '';
                        $wpc_up_d57 = '';
                        if (self::wpc_combined_crit_on() && $wpc_tk50 !== '') {
                            $wpc_up_m57 = wpc_used_css_path($wpc_tk50, 'mobile');
                            if ($wpc_up_m57 === '' || !(@filesize($wpc_up_m57) > 64)) { $wpc_up_m57 = wpc_used_css_path($wpc_tk50); }
                            $wpc_up_d57 = wpc_used_css_path($wpc_tk50, 'desktop');
                            if ($wpc_up_d57 === '' || !(@filesize($wpc_up_d57) > 64)) { $wpc_up_d57 = wpc_used_css_path($wpc_tk50); }
                        }
                        $wpc_up50  = $wpc_tk50 !== '' ? wpc_used_css_path($wpc_tk50, $wpc_dev50) : '';
                        if ($wpc_up50 === '' || !(@filesize($wpc_up50) > 64)) {
                            $wpc_up50 = $wpc_tk50 !== '' ? wpc_used_css_path($wpc_tk50) : '';
                        }
                        if ($wpc_up50 !== '' && @filesize($wpc_up50) > 64) {
                            $wpc_uurl50 = rtrim(WPS_IC_CRITICAL_URL, '/') . '/used-css/' . rawurlencode(basename($wpc_up50)) . '?uv=' . (int) @filemtime($wpc_up50);


                            $wpc_ucss_rel121 = 'wpc-stylesheet';


                            $wpc_dv3_121 = is_array(self::$settings)
                                && !empty(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1'
                                && (!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0');
                            if ($wpc_dv3_121 && apply_filters('wpc_used_css_late', true)) {
                                $wpc_ucss_rel121 = 'wpc-late-stylesheet';
                            } elseif ($this->isMobile()
                                && !empty(self::$settings['minimal-mobile-css']) && self::$settings['minimal-mobile-css'] == '1'
                                && apply_filters('wpc_minimal_mobile_css', true)) {
                                $wpc_ucss_rel121 = 'wpc-late-stylesheet';
                            }
                            // Eager-async: used.css exists to make below-fold safe WITHOUT interaction —
                            // it must never ride the interaction gate. media-swap + loader belt.
                            // Split artifacts (ATF eager + REST href-less until human evidence) arm
                            // per-device only when BOTH siblings exist; else the legacy union link.
                            // v7.10.828 — pages with builder conceal markers only consume bundles that
                            // DECLARE the dynamic-state safelist (service stamp wpc-safelist:v1). An
                            // unstamped bundle here is the keep-the-hide/drop-the-undo artifact class.
                            $wpc_bmark828 = (strpos((string) $html, 'et-waypoint') !== false
                                    || strpos((string) $html, 'elementor-invisible') !== false)
                                && apply_filters('wpc_used_safelist_gate', true);
                            $wpc_emit_used57 = function ($path, $mediaTgt, $idSfx) use (&$output, $wpc_bmark828) {
                                $wpc_ubase57 = rtrim(WPS_IC_CRITICAL_URL, '/') . '/used-css/';
                                $wpc_atfp57  = (string) preg_replace('/\.css$/', '.atf.css', $path);
                                $wpc_restp57 = (string) preg_replace('/\.css$/', '.rest.css', $path);
                                if ($wpc_bmark828 && function_exists('wpc_used_css_stamped828')) {
                                    $wpc_gpick828 = (apply_filters('wpc_used_css_split', true) && @filesize($wpc_restp57) > 32)
                                        ? $wpc_restp57 : $path;
                                    if (!wpc_used_css_stamped828($wpc_gpick828)) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('used-unsafelisted-skip', '', '', ['f' => basename($wpc_gpick828)]);
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
                                if (apply_filters('wpc_used_css_split', true) && @filesize($wpc_restp57) > 32) {
                                    $wpc_restu57 = $wpc_ubase57 . rawurlencode(basename($wpc_restp57)) . '?uv=' . (int) @filemtime($wpc_restp57);
                                    $output .= "\r\n" . '<link rel="stylesheet" id="wpc-used-css-rest' . $idSfx . '" data-wpc-rest="' . esc_url($wpc_restu57) . '" data-wpc-ucss-rest="' . $mediaTgt . '" media="print">';
                                    return;
                                }
                                $wpc_uu57 = $wpc_ubase57 . rawurlencode(basename($path)) . '?uv=' . (int) @filemtime($path);
                                $output .= "\r\n" . '<link rel="stylesheet" id="wpc-used-css' . $idSfx . '" data-wpc-ucss="' . $mediaTgt . '" media="print" onload="this.onload=null;this.media=this.getAttribute(\'data-wpc-ucss\');try{document.documentElement.classList.add(\'wpc-css-live\')}catch(x){}" onerror="try{document.documentElement.classList.add(\'wpc-css-live\')}catch(x){}" href="' . esc_url($wpc_uu57) . '">';
                            };
                            if (self::wpc_combined_crit_on() && $wpc_up_m57 !== '' && $wpc_up_d57 !== ''
                                && @filesize($wpc_up_m57) > 64 && @filesize($wpc_up_d57) > 64
                                && basename($wpc_up_m57) !== basename($wpc_up_d57)) {


                                $wpc_emit_used57($wpc_up_m57, '(max-width: 767.98px)', '');
                                $wpc_emit_used57($wpc_up_d57, '(min-width: 768px)', '-d');
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
                                    $wpc_htok205 = self::wpc_header_markup_tokens($html);
                                    $wpc_hsm203 = wpc_header_css_slice($wpc_up_m57, $wpc_htok205);
                                    $wpc_hsd203 = wpc_header_css_slice($wpc_up_d57, $wpc_htok205);
                                    $wpc_hs203  = ($wpc_hsm203 !== '' ? '@media (max-width: 767.98px){' . $wpc_hsm203 . '}' : '')
                                        . ($wpc_hsd203 !== '' ? '@media (min-width: 768px){' . $wpc_hsd203 . '}' : '');
                                    if ($wpc_hs203 !== '') {
                                        $output .= "\r\n" . '<style id="wpc-header-css-slice">' . $wpc_hs203 . '</style>';
                                    }
                                }
                            } else {
                                $wpc_emit_used57($wpc_up50, 'all', '');
                                if (apply_filters('wpc_header_css_slice', true) && function_exists('wpc_header_css_slice')
                                    && strpos($output, 'wpc-header-css-slice') === false && !empty($wpc_up50)) {
                                    $wpc_hs203 = wpc_header_css_slice($wpc_up50, self::wpc_header_markup_tokens($html));
                                    if ($wpc_hs203 !== '') {
                                        $output .= "\r\n" . '<style id="wpc-header-css-slice">' . $wpc_hs203 . '</style>';
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


                    $wpc_minmob122 = $this->isMobile()
                        && !empty(self::$settings['minimal-mobile-css']) && self::$settings['minimal-mobile-css'] == '1'
                        && apply_filters('wpc_minimal_mobile_css', true);


                    $wpc_fpre114_raw = trim((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/font-preload.txt'));
                    // v7.10.718 - stored preload URLs are generation-baked; translate when CDN off.
                    if ($wpc_fpre114_raw !== '' && (empty(self::$cdnEnabled) || self::$cdnEnabled != '1') && function_exists('wpc_unzone_url')) {
                        $wpc_fpre114_raw = implode("\n", array_map('wpc_unzone_url', preg_split('/\r?\n/', $wpc_fpre114_raw)));
                    }
                    // Inline ATF subsets already guarantee first paint in the real face —
                    // full-file preloads then only occupy pre-paint bandwidth (~82KB on the
                    // flagship). With covering subsets present, all font preloads stand down;
                    // the full files swap in naturally off the critical path.
                    $wpc_sub186 = '';
                    if (apply_filters('wpc_subset_covers_preloads', true)) {
                        $wpc_sub186 = (string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/font-subsets.css');
                        if (strlen($wpc_sub186) < 1024 || stripos($wpc_sub186, 'data:font') === false) {
                            $wpc_sub186 = '';
                        }
                        // Sticky: a land is mid-rewrite of the subsets file — the standdown
                        // holds if subsets were seen recently, so no render can flap back to
                        // emitting the preload freight and mint a bad edge copy.
                        if ($wpc_sub186 === '') {
                            $wpc_ss207 = (int) get_option('wpc_subsets_seen', 0);
                            if ($wpc_ss207 && (time() - $wpc_ss207) < 7 * DAY_IN_SECONDS) {
                                $wpc_sub186 = 'sticky';
                            }
                        }
                    }
                    if (!$wpc_minmob122
                        && $wpc_sub186 === ''
                        && $wpc_fpre114_raw !== ''
                        && strpos($output, 'wpc-dominant-font-preload') === false) {
                        $wpc_fpre_n116 = 0;
                        $wpc_fpre_list689 = [];
                        $wpc_fpre_crit116 = (string) $criticalCSSContent_Mobile . (string) $criticalCSSContent_Desktop;
                        foreach (array_slice(preg_split('/\r?\n/', $wpc_fpre114_raw), 0, 3) as $wpc_fpre114) {
                            $wpc_fpre114 = trim((string) $wpc_fpre114);
                            if ($wpc_fpre114 === ''
                                || substr((string) parse_url($wpc_fpre114, PHP_URL_PATH), -6) !== '.woff2'


                                || preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|fontello|themify|elegant|feather/i', $wpc_fpre114)
                                || !self::wpc_lcp_bg_url_allowed($wpc_fpre114)) {
                                continue;
                            }
                            // Preload the exact URL form the critical CSS requests for this file.
                            $wpc_fpre_bn116 = strtolower((string) basename((string) parse_url($wpc_fpre114, PHP_URL_PATH)));
                            if ($wpc_fpre_bn116 !== ''
                                && preg_match('#url\(\s*(["\']?)(https?://[^"\')]*?/' . preg_quote($wpc_fpre_bn116, '#') . ')\1?\s*\)#i', $wpc_fpre_crit116, $wpc_fm116)) {
                                $wpc_fpre114 = $wpc_fm116[2];
                            }
                            $wpc_fpre_n116++;
                            $wpc_fpre_list689[] = esc_url($wpc_fpre114);
                        }

                        // Artifact list short? Crit-declared first-party faces (regular/bold, latin)
                        // otherwise start late and become the longest critical-chain items.
                        if ($wpc_fpre_n116 < 3 && apply_filters('wpc_crit_face_preload', true)
                            && preg_match_all('/@font-face\s*\{[^}]*\}/is', $wpc_fpre_crit116, $wpc_cfm116)) {
                            $wpc_seen116 = [];
                            // A family that never paints above the fold has no business on the
                            // critical chain — when the artifact names the ATF families, preload
                            // only those. No artifact → previous behavior.
                            $wpc_atffam116 = [];
                            if (!empty($criticalCSSExists['desktop'])) {
                                $wpc_djr116 = json_decode((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/delay.json'), true);
                                $wpc_djg116 = (is_array($wpc_djr116) && isset($wpc_djr116['atf_glyphs']) && is_array($wpc_djr116['atf_glyphs'])) ? $wpc_djr116['atf_glyphs'] : [];
                                if (!$wpc_djg116 && is_array($wpc_djr116)) {
                                    foreach ($wpc_djr116 as $wpc_dje116) {
                                        if (is_array($wpc_dje116) && isset($wpc_dje116['atf_glyphs']) && is_array($wpc_dje116['atf_glyphs']) && $wpc_dje116['atf_glyphs']) {
                                            $wpc_djg116 = $wpc_dje116['atf_glyphs'];
                                            break;
                                        }
                                    }
                                }
                                foreach (array_keys((array) $wpc_djg116) as $wpc_djk116) {
                                    $wpc_atffam116[strtolower(trim((string) strtok((string) $wpc_djk116, '|')))] = 1;
                                }
                            }
                            foreach ($wpc_cfm116[0] as $wpc_cff116) {
                                if ($wpc_fpre_n116 >= 3) {
                                    break;
                                }
                                if (preg_match('/font-style\s*:\s*(italic|oblique)/i', $wpc_cff116)
                                    || !preg_match('/font-weight\s*:\s*(400|700|normal|bold)\b/i', $wpc_cff116)
                                    || !self::wpc_face_range_latin($wpc_cff116)
                                    || !preg_match('#url\(\s*(["\']?)(https?://[^"\')]+\.woff2)\1?\s*\)#i', $wpc_cff116, $wpc_cfu116)) {
                                    continue;
                                }
                                if ($wpc_atffam116
                                    && preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $wpc_cff116, $wpc_ffm116)
                                    && !isset($wpc_atffam116[strtolower(trim((string) $wpc_ffm116[1]))])) {
                                    continue;
                                }
                                $wpc_cu116 = $wpc_cfu116[2];
                                if (isset($wpc_seen116[$wpc_cu116]) || stripos($wpc_cu116, 'fonts.gstatic') !== false
                                    || preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|themify|elegant|feather/i', $wpc_cu116)
                                    || !self::wpc_lcp_bg_url_allowed($wpc_cu116)
                                    || stripos($output, esc_url($wpc_cu116)) !== false) {
                                    continue;
                                }
                                $wpc_seen116[$wpc_cu116] = 1;
                                $wpc_fpre_n116++;
                                $wpc_fpre_list689[] = esc_url($wpc_cu116);
                            }
                        }
                        // v7.10.796 — same url form the @font-face requests, or the preload is freight.
                        if (!empty($wpc_fpre_list689) && class_exists('wps_cdn_rewrite')
                            && method_exists('wps_cdn_rewrite', 'wpc_font_preload_url_form796')) {
                            $wpc_fpre_list689 = array_values(array_unique(array_map(
                                ['wps_cdn_rewrite', 'wpc_font_preload_url_form796'], $wpc_fpre_list689)));
                        }
                        // v7.10.689 — one post-paint injector for the whole lane; a static as=font
                        // tag render-holds Chrome 150. The marker keeps the 3703 re-entry guard true.
                        if (!empty($wpc_fpre_list689) && function_exists('wpc_font_preload_postpaint_tag')) {
                            $wpc_fpb689 = wpc_font_preload_postpaint_tag($wpc_fpre_list689, 'wpc-dominant-font-preload');
                            if ($wpc_fpb689 !== '') {
                                $output .= "\r\n" . $wpc_fpb689;
                            }
                        }
                    }


                    $wpc_atfbg121 = $this->isMobile() ? (string) $criticalCSSContent_Mobile : (string) $criticalCSSContent_Desktop;
                    if ($wpc_atfbg121 === '' || self::wpc_combined_crit_on()) {
                        $wpc_atfbg121 .= (string) $criticalCSSContent_Mobile . (string) $criticalCSSContent_Desktop;
                    }
                    if (apply_filters('wpc_atf_bg_preload', true)
                        && strpos($output, 'wpc-atf-bg-preload') === false
                        && $wpc_atfbg121 !== ''
                        && preg_match('/(?:elementor-element-|brxe-|et_pb_|fusion-|#)[A-Za-z0-9_-]{4,}[^{}]{0,200}\{[^{}]*?background-image\s*:\s*url\(\s*["\']?([^"\')]+\.(?:avif|webp|jpe?g|png|svg))(?:\?[^"\')]*)?["\']?\s*\)[^{}]*\}/i', $wpc_atfbg121, $wpc_bgm121)) {
                        $wpc_bgu121 = html_entity_decode((string) $wpc_bgm121[1], ENT_QUOTES);
                        $wpc_bgavif121 = '';
                        if (preg_match('/image-set\(\s*url\(\s*["\']?([^"\')]+\.avif)(?:\?[^"\')]*)?["\']?\s*\)/i', (string) $wpc_bgm121[0], $wpc_bgs121)) {
                            $wpc_bgavif121 = html_entity_decode((string) $wpc_bgs121[1], ENT_QUOTES);
                        }
                        if (self::wpc_lcp_bg_url_allowed($wpc_bgu121)
                            && stripos($output, esc_url($wpc_bgu121)) === false) {
                            $output .= "\r\n" . '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_bgu121) . '"'
                                . (($wpc_bgavif121 !== '' && self::wpc_lcp_bg_url_allowed($wpc_bgavif121)) ? ' imagesrcset="' . esc_url($wpc_bgavif121) . '"' : '')
                                . ' id="wpc-atf-bg-preload">';
                        }
                    }


                    if (self::wpc_combined_crit_on()) {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Mobile);
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Desktop);
                    } elseif ($this->isMobile()) {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Mobile);
                    } else {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Desktop);
                    }


                    if (function_exists('wpc_perf_debug_allowed741') && wpc_perf_debug_allowed741()) {
                        $wpc_dbg_blob = ($wpc_dev_key === 'mobile') ? $criticalCSSContent_Mobile : $criticalCSSContent_Desktop;
                        $wpc_dbg_ad   = self::wpc_lcp_autoderive_bg($html, (string) $wpc_dbg_blob);
                        $wpc_dbg_sf   = dirname($criticalCSSExists['desktop']) . '/font-subsets.css';
                        $output .= "\r\n<!-- WPC-PERF-DEBUG dev=" . $wpc_dev_key
                            . " | census_lcp_json=" . (is_array($wpc_lcp_el) ? 'present' : 'absent')
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
                    if (($this->isMobile() || self::wpc_combined_crit_on()) && !empty($criticalCSSContent_Mobile) && $wpc_minmob122) {
                        $criticalCSSContent_Mobile = self::wpc_strip_covered_fullface($criticalCSSContent_Mobile);
                    }
                    if (!empty($criticalCSSContent_Mobile)) {
                        $criticalCSSContent_Mobile = self::wpc_face_self_dedupe($criticalCSSContent_Mobile);
                    }
                    if (!empty($criticalCSSContent_Desktop)) {
                        $criticalCSSContent_Desktop = self::wpc_face_self_dedupe($criticalCSSContent_Desktop);
                    }
                    // First paint obeys the CRIT stacks — fallback definitions on the page pin
                    // nothing unless these stacks reference them. Splice at emission (idempotent,
                    // @font-face masked) so the pin holds regardless of artifact generation.
                    if (function_exists('wpc_css_insert_fallbacks')) {
                        if (!empty($criticalCSSContent_Mobile)) {
                            $criticalCSSContent_Mobile = wpc_css_insert_fallbacks($criticalCSSContent_Mobile);
                        }
                        if (!empty($criticalCSSContent_Desktop)) {
                            $criticalCSSContent_Desktop = wpc_css_insert_fallbacks($criticalCSSContent_Desktop);
                        }
                    }
                    // url() faces re-emit below in #wpc-late-faces; data: faces stay.
                    $wpc_lf210 = '';
                    $wpc_cov601 = self::wpc_faces_covered601(
                        $output,
                        $html,
                        (string) $criticalCSSContent_Desktop . (string) $criticalCSSContent_Mobile
                    );
                    if (!$wpc_cov601 && function_exists('wpc_cache_first_log')
                        && !get_transient('wpc_lf_nocov_log')) {
                        set_transient('wpc_lf_nocov_log', 1, 3600);
                        wpc_cache_first_log('late-faces-no-coverage', '', '', ['sub186' => $wpc_sub186]);
                    }
                    if ($wpc_cov601 && apply_filters('wpc_late_faces', true)) {
                        if (!empty($criticalCSSContent_Mobile)) {
                            $criticalCSSContent_Mobile = self::wpc_demote_url_faces($criticalCSSContent_Mobile, $wpc_lf210, $output);
                        }
                        if (!empty($criticalCSSContent_Desktop)) {
                            $criticalCSSContent_Desktop = self::wpc_demote_url_faces($criticalCSSContent_Desktop, $wpc_lf210, $output);
                        }
                        // These faces are harvested from the CRIT artifact, which bakes its own
                        // unicode-range at CRIT time — while fonts.json carries the authoritative
                        // remote_range from the OBS leg, minutes later. They disagree, and the crit
                        // copy wins the wire because it never passes the CSS-file consumer. busy kept
                        // U+0-34 (covering U+33) while the map correctly held U+0-32,U+34,… so the
                        // 91 KiB icon font stayed on the critical path. Normalise against the map.
                        if ($wpc_lf210 !== '' && class_exists('wps_cdn_rewrite')
                            && method_exists('wps_cdn_rewrite', 'wpc_font_remote_ranges')) {
                            $wpc_lfm210 = wps_cdn_rewrite::wpc_font_remote_ranges();
                            // v7.10.480 — THE SECOND WRITER. .478 enforced the pairing invariant at
                            // the CSS-file consumer (cdn-rewrite.php) and this crit-artifact path was
                            // left applying the same map unconditionally, so the gate survived a full
                            // rebuild on zinsenvergleich: new icv, new filenames, identical ranges,
                            // and U+F017/U+F09D/U+F3D1 still supplied by nothing. Two writers, one
                            // guarded. Exactly the exhaustive-grep-every-writer rule I already hold.
                            $wpc_sfam480 = (class_exists('wps_cdn_rewrite')
                                && method_exists('wps_cdn_rewrite', 'wpc_font_subset_families'))
                                ? wps_cdn_rewrite::wpc_font_subset_families() : [];
                            if (!empty($wpc_lfm210)) {
                                $wpc_lf210 = (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($fm) use ($wpc_lfm210, $wpc_sfam480) {
                                    $blk = $fm[0];
                                    if (stripos($blk, 'data:') !== false) { return $blk; }
                                    if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $fa)) { return $blk; }
                                    $wt = preg_match('/font-weight\s*:\s*(\d{2,4})/i', $blk, $wm) ? (int) $wm[1] : 400;
                                    $st = preg_match('/font-style\s*:\s*italic/i', $blk) ? 'italic' : 'normal';
                                    $fam480 = strtolower(trim($fa[1], " \t\"'"));
                                    $k  = $fam480 . '|' . $wt . '|' . $st;
                                    if (empty($wpc_lfm210[$k])) { return $blk; }
                                    // v7.10.759 — icon-font families are never range-gated (their
                                    // glyphs come from content:"" rules no census sees; the
                                    // complement forbids exactly those codepoints). Family is in
                                    // OUR map, so any baked range here is our gate: strip it.
                                    if (function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($fam480)) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('font-gate-iconfont', '', '', [
                                                'family' => substr($fam480, 0, 28), 'src' => 'crit-artifact',
                                            ]);
                                        }
                                        $blk = (string) preg_replace('/\s*;?\s*unicode-range\s*:\s*[^;}]+;?/i', '', $blk);
                                        return (string) preg_replace('/;\s*\}/', '}', $blk);
                                    }
                                    // PAIRING INVARIANT: a range without its inline subset leaves
                                    // glyphs supplied by nothing. Withholding is the safe failure.
                                    if (empty($wpc_sfam480[$fam480])) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('font-gate-unpaired', '', '', [
                                                'family'   => substr($fam480, 0, 28), 'src' => 'crit-artifact',
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
                                    $want = 'unicode-range:' . $wpc_lfm210[$k];
                                    if (preg_match('/unicode-range\s*:\s*[^;}]+/i', $blk)) {
                                        return (string) preg_replace('/unicode-range\s*:\s*[^;}]+/i', $want, $blk, 1);
                                    }
                                    return (string) preg_replace('/\}\s*$/', ';' . $want . '}', $blk, 1);
                                }, $wpc_lf210);
                            }
                        }
                    }
                    // Output critical CSS — preload links are now added by addCritical() after filtering


                    // A service-built combined artifact replaces the two-blob wrap when fresh:
                    // it carries both devices already deduped. Absent, stale, or carrying any
                    // remote font reference → the wrap below serves exactly as before.
                    $wpc_cmb154 = '';
                    if (self::wpc_combined_crit_on() && apply_filters('wpc_crit_combined_artifact', true)
                        && !empty($criticalCSSExists['desktop'])) {
                        $wpc_cmd154 = dirname($criticalCSSExists['desktop']);
                        $wpc_cmf154 = $wpc_cmd154 . '/critical_combined.css';
                        // Combined must never shadow NEWER device files: pull lanes land
                        // devices, and the same-uuid law skips re-pulls — without this stat
                        // compare a stale combined serves forever (hawkeye receipt: fresh
                        // 118KB device files on disk, page inlining the old combined).
                        $wpc_cmstale338 = @is_file($wpc_cmf154)
                            && (int) @filemtime($wpc_cmf154) < (int) @filemtime($criticalCSSExists['desktop']);
                        if ($wpc_cmstale338 && function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_rej_log')) {
                            set_transient('wpc_cmb_rej_log', 1, 3600);
                            wpc_cache_first_log('cmb-rejected', basename($wpc_cmd154), '', ['why' => 'stale-mtime']);
                        }
                        if (!$wpc_cmstale338 && @is_readable($wpc_cmf154) && @filesize($wpc_cmf154) > 1024) {
                            $wpc_cmb154 = (string) @file_get_contents($wpc_cmf154);
                            $wpc_cmr154 = '';
                            // The service's combined embeds raw gstatic faces; we already hold the
                            // gstatic→local map, so localize before judging. Unmapped refs still reject.
                            if ($wpc_cmb154 !== '' && stripos($wpc_cmb154, 'fonts.gstatic.com') !== false
                                && defined('WPS_IC_FONTS_URL') && function_exists('get_option')) {
                                $wpc_gmap179 = get_option('wps_ic_fonts_inline_map');
                                if (is_array($wpc_gmap179) && $wpc_gmap179) {
                                    foreach ($wpc_gmap179 as $wpc_gk179 => $wpc_gf179) {
                                        if (!is_string($wpc_gk179) || !is_string($wpc_gf179) || $wpc_gf179 === '') {
                                            continue;
                                        }
                                        $wpc_cmb154 = str_replace($wpc_gk179, WPS_IC_FONTS_URL . 'inline/' . $wpc_gf179, $wpc_cmb154);
                                    }
                                }
                            }
                            if ($wpc_cmb154 === '' || stripos($wpc_cmb154, '@media') === false) {
                                $wpc_cmr154 = 'shape';
                            } elseif (stripos($wpc_cmb154, 'fonts.gstatic.com') !== false) {
                                $wpc_cmr154 = 'gstatic';
                            } elseif (stripos($wpc_cmb154, '</style') !== false) {
                                $wpc_cmr154 = 'breakout';
                            }
                            if ($wpc_cmr154 !== '') {
                                $wpc_cmb154 = '';
                                if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_rej_log')) {
                                    set_transient('wpc_cmb_rej_log', 1, 3600);
                                    wpc_cache_first_log('cmb-rejected', basename($wpc_cmd154), '', ['why' => $wpc_cmr154]);
                                }
                            }
                        }
                    }
                    if ($wpc_cmb154 !== '') {
                        $wpc_cmb154 = self::wpc_dupe_rule_prune17($wpc_cmb154);
                        $wpc_cmb154 = self::wpc_face_self_dedupe($wpc_cmb154);
                        if (function_exists('wpc_css_insert_fallbacks')) {
                            $wpc_cmb154 = wpc_css_insert_fallbacks($wpc_cmb154);
                        }
                        if (self::wpc_faces_covered601($output, $html, $wpc_cmb154)
                            && apply_filters('wpc_late_faces', true)) {
                            $wpc_cmb154 = self::wpc_demote_url_faces($wpc_cmb154, $wpc_lf210, $output);
                        }
                        $wpc_gf154 = '';
                        if (strpos($wpc_cmb154, 'wpc-fonts-embedded') === false) {
                            $wpc_gf154 = $this->maybeInlineGoogleFontFaces($html, $wpc_cmb154);
                            // element-shaped return (standalone lanes); this lane embeds INSIDE a
                            // style tag — a nested <style> ends the outer block at its close (breakout)
                            if ($wpc_gf154 !== '') {
                                $wpc_gf154 = preg_replace('#</?style[^>]*>#i', '', $wpc_gf154);
                            }
                            if ($wpc_gf154 !== '') {
                                $wpc_gf154 = $this->filterCriticalFontFaces($wpc_gf154);
                            }
                            if ($wpc_gf154 !== '') {
                                $wpc_gf154 = self::wpc_face_dedupe($wpc_gf154, $wpc_cmb154);
                            }
                            if ($wpc_gf154 !== '' && apply_filters('wpc_gfaces_latin_only', self::wpc_gfaces_latin_default())) {
                                $wpc_gf154 = self::wpc_gfaces_prune_ranges($wpc_gf154);
                            }
                            if ($wpc_gf154 !== ''
                                && self::wpc_faces_covered601($output, $html, $wpc_cmb154)
                                && apply_filters('wpc_late_faces', true)) {
                                $wpc_gf154 = self::wpc_demote_url_faces($wpc_gf154, $wpc_lf210, $output . $wpc_cmb154);
                            }
                        }
                        $wpc_pay154 = self::wpc_conceal_scope704($wpc_gf154 . $wpc_cmb154);
                        if (stripos($wpc_pay154, '</style') !== false) {
                            $wpc_pay154 = preg_replace('#</?style[^>]*>#i', '', $wpc_pay154);
                            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_nest_log')) {
                                set_transient('wpc_cmb_nest_log', 1, 3600);
                                wpc_cache_first_log('cmb-nested-style-stripped', '', '', []);
                            }
                        }
                        $output .= "\r\n" . self::wpc_crit_tag931('id="wpc-critical-css" class="wpc-critical-css-combined" data-wpc-cmb="1"', $wpc_pay154);
                    } elseif (self::wpc_combined_crit_on()
                        && !empty($criticalCSSContent_Mobile) && !empty($criticalCSSContent_Desktop)
                        && self::wpc_combined_both_blobs_required()) {


                        $wpc_mb = self::wpc_conceal_scope704($criticalCSSContent_Mobile);
                        $wpc_db = self::wpc_conceal_scope704($criticalCSSContent_Desktop);
                        $wpc_mo = substr_count($wpc_mb, '{') - substr_count($wpc_mb, '}');
                        $wpc_do = substr_count($wpc_db, '{') - substr_count($wpc_db, '}');


                        if (substr_count($wpc_mb, '/*') > substr_count($wpc_mb, '*/')) { $wpc_mb .= '*/'; }
                        if (substr_count($wpc_db, '/*') > substr_count($wpc_db, '*/')) { $wpc_db .= '*/'; }
                        if ($wpc_mo >= 0 && $wpc_mo <= 64 && $wpc_do >= 0 && $wpc_do <= 64) {
                            if ($wpc_mo > 0) { $wpc_mb .= str_repeat('}', $wpc_mo); }
                            if ($wpc_do > 0) { $wpc_db .= str_repeat('}', $wpc_do); }
                            $output .= "\r\n" . self::wpc_crit_tag931('id="wpc-critical-css" class="wpc-critical-css-combined"',
                                '@media (max-width: 767.98px){' . $wpc_mb . '}'
                                . '@media (min-width: 768px){' . $wpc_db . '}');
                        } else {


                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('combined-brace-fallback', $this->isMobile() ? 'mobile' : 'desktop', '', ['mo' => $wpc_mo, 'do' => $wpc_do]);
                            }
                            if ($this->isMobile()) {
                                $output .= "\r\n" . self::wpc_crit_tag931('id="wpc-critical-css" class="wpc-critical-css-mobile"', self::wpc_conceal_scope704($criticalCSSContent_Mobile));
                            } else {
                                $output .= "\r\n" . self::wpc_crit_tag931('id="wpc-critical-css" class="wpc-critical-css-desktop"', self::wpc_conceal_scope704($criticalCSSContent_Desktop));
                            }
                        }
                    } elseif ($this->isMobile() && !empty($criticalCSSContent_Mobile)) {
                        $output .= "\r\n" . self::wpc_crit_tag931('id="wpc-critical-css" class="wpc-critical-css-mobile"', self::wpc_conceal_scope704($criticalCSSContent_Mobile));
                    } elseif (!$this->isMobile() && !empty($criticalCSSContent_Desktop)) {
                        $output .= "\r\n" . self::wpc_crit_tag931('id="wpc-critical-css" class="wpc-critical-css-desktop"', self::wpc_conceal_scope704($criticalCSSContent_Desktop));
                    }


                    // v7.10.704 — a scoped conceal-guard NEEDS its releaser on the page even when
                    // no used-css rest link exists (the boot's no-rest branch adds the class at
                    // load+idle). Invariant, not ordering: the crit emission above may run after
                    // both boot sites, so the guard-scoped page gets its own late emission here.
                    if (self::$wpc_scoped704 && strpos($output, 'wpc-ucss-boot') === false) {
                        $output .= "\r\n" . self::wpc_ucss_boot_js();
                    }
                    $wpc_critf147 = !empty($criticalCSSExists['desktop']) ? $criticalCSSExists['desktop']
                        : (!empty($criticalCSSExists['mobile']) ? $criticalCSSExists['mobile'] : '');
                    if ($wpc_critf147 !== '') {
                        $wpc_rvl147 = self::wpc_atf_reveal_css(dirname($wpc_critf147));
                        if ($wpc_rvl147 !== '') {
                            $output .= "\r\n" . '<style type="text/css" id="wpc-atf-reveal">' . $wpc_rvl147 . '</style>';
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
                    }
                    // v7.10.606 — body-scope guard: a nested-document reset in the page-level
                    // used-css bundle turned staging black and absolutely positioned its body.
                    if (function_exists('wpc_body_scope_guard606')) {
                        $output .= wpc_body_scope_guard606(
                            (string) $criticalCSSContent_Desktop . (string) $criticalCSSContent_Mobile,
                            (strpos($output, 'wpc-used-css') !== false || strpos((string) $html, 'wpc-used-css') !== false)
                        );
                    }
                    // Inert until the loader flips media.
                    if (!empty($wpc_lf210) && strpos($output, 'wpc-late-faces') === false) {
                        // Collected from BOTH device blobs — combined mode guarantees cross-blob dupes.
                        $wpc_lf210 = self::wpc_face_self_dedupe($wpc_lf210);
                        // ICON faces ride the loader's human signal, not the load barrier: the inlined
                        // subset covers the above-fold glyphs, every remaining one is below the fold.
                        // window.wpcIconFaces() flips this block off human() — no gate script here,
                        // and no listeners of its own. Text faces keep the barrier untouched.
                        // DEFAULT OFF. The premise — "every remaining glyph is below the fold, and
                        // scroll is a signal, so the file arrives before they are seen" — is false:
                        // Divi blurb icons sit below the fold and are visible the instant a visitor
                        // scrolls, long before 91 KB lands. Receipted on busyprosai as tofu squares.
                        // And it never bought a point: .432 with the font = 99, .435 gated = 99.
                        // Correctness over a byte win that never converted.
                        $wpc_ifg210 = '';
                        if (apply_filters('wpc_icon_faces_interaction', false) && function_exists('wpc_css_is_icon_font')) {
                            $wpc_lf210 = (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($fm) use (&$wpc_ifg210) {
                                $blk = $fm[0];
                                if (stripos($blk, 'data:') !== false) { return $blk; }
                                if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $fa)) { return $blk; }
                                if (!wpc_css_is_icon_font($fa[1])) { return $blk; }
                                $wpc_ifg210 .= $blk;
                                return '';
                            }, $wpc_lf210);
                        }
                        if (trim($wpc_lf210) !== '') {
                            $output .= "\r\n" . '<style id="wpc-late-faces" media="not all">' . self::wpc_face_display_sweep21($wpc_lf210) . '</style>';
                            // v7.10.924 — the lane carries its own remover (dalton: no delay loader = no flip, ever)
                            if (function_exists('wpc_lf_flip_js924') && strpos($output, 'wpc-lf-flip924') === false
                                && strpos((string) $html, 'wpc-lf-flip924') === false) {
                                $output .= wpc_lf_flip_js924();
                            }
                        }
                        if (trim($wpc_ifg210) !== '') {
                            $output .= "\r\n" . '<style id="wpc-icon-faces" media="not all">' . $wpc_ifg210 . '</style>';
                        }
                    }

                }
            }
        }

        // v7.10.561 — CRIT-LESS FONT CARRIER. On Elementor-class sites the inline crit is the ONLY
        // thing that declares the theme's real @font-face, so a crit-less render paints a fallback
        // typeface, not merely a slower page — worse than running no plugin at all. font-subsets.css
        // is a SIBLING artifact in the same crit dir, independent of critical_*.css, so it can be
        // emitted on its own. Keyed on FAMILY: a family already declared anywhere in the document
        // is never re-declared, which makes this a no-op on every crit-present render.
        static $wpc_cl_subs561 = false;
        if (!$wpc_cl_subs561) {
            $wpc_cls561 = self::wpc_critless_subsets561((string) $html, $output);
            if ($wpc_cls561 !== '') {
                $output .= $wpc_cls561;
                $wpc_cl_subs561 = true;
            }
        }

        // v7.10.602 — record the declarations this healthy render produced, so a render the crit
        // cannot reach (logged-in) can replay them. .561's carrier above cannot cover that case:
        // it lives inside this method, and those renders never call it.
        if (function_exists('wpc_font_carrier_record602')
            && !(function_exists('is_user_logged_in') && is_user_logged_in())
            && stripos((string) $output, '@font-face') !== false) {
            wpc_font_carrier_record602((string) $output);
        }

        return $output;
    }

    /**
     * Emit font-subsets.css on its own when the crit did not carry the faces (v7.10.561).
     *
     * Returns '' whenever the document already declares every family the artifact carries, so
     * this is a no-op on every crit-present render. FAMILY is the key: the failure being fixed
     * is a family with ZERO declarations, not a missing weight.
     */
    private static function wpc_critless_subsets561($html, $output)
    {
        if (!apply_filters('wpc_subset_inline_critless', true)
            || !apply_filters('wpc_atf_subset_inline', true)
            || strpos($output, 'id="wpc-font-subsets"') !== false
            || strpos($html, 'id="wpc-font-subsets"') !== false
            // Twin of the in-crit guard: an artifact that says it embeds its faces needs nothing here.
            || strpos($output, 'wpc-fonts-embedded') !== false) {
            return '';
        }
        try {
            if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                return '';
            }
            $wpc_k561 = (string) (new wps_ic_url_key())->setup('');
            if ($wpc_k561 === '') {
                return '';
            }
            $wpc_d561 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k561 . '/';
            if (!@is_readable($wpc_d561 . 'font-subsets.css')) {
                return '';
            }
            $wpc_c561 = (string) @file_get_contents($wpc_d561 . 'font-subsets.css');
            if ($wpc_c561 !== '' && apply_filters('wpc_atf_icon_subset_inline', true)
                && @is_readable($wpc_d561 . 'icon-subsets.css')) {
                $wpc_c561 .= (string) @file_get_contents($wpc_d561 . 'icon-subsets.css');
            }
            if ($wpc_c561 === ''
                || strlen($wpc_c561) > (int) apply_filters('wpc_subset_inline_max', 262144)
                || stripos($wpc_c561, '</style') !== false || stripos($wpc_c561, '<script') !== false) {
                return '';
            }
            $wpc_fam561 = function ($css) {
                $out = [];
                if (preg_match_all('/@font-face\s*\{[^}]*\}/i', $css, $m)) {
                    foreach ($m[0] as $blk) {
                        if (preg_match('/font-family\s*:\s*([^;}]+)/i', $blk, $f)) {
                            $out[strtolower(trim($f[1], " \t\n\r\0\x0B\"'"))] = 1;
                        }
                    }
                }
                return $out;
            };
            $wpc_have561 = $wpc_fam561($html . $output);
            $wpc_need561 = false;
            foreach (array_keys($wpc_fam561($wpc_c561)) as $wpc_f561) {
                if (!isset($wpc_have561[$wpc_f561])) {
                    $wpc_need561 = true;
                    break;
                }
            }
            if (!$wpc_need561) {
                return '';
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('subsets-critless-inline', $wpc_k561, '', ['b' => strlen($wpc_c561)]);
            }
            return "\r\n" . '<style type="text/css" id="wpc-font-subsets"'
                . ((strncmp($wpc_c561, '/*wpc-subsets-v2*/', 18) === 0) ? ' data-wpc-v2="1"' : '')
                . ' data-wpc-critless="1">' . $wpc_c561 . '</style>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    function filterCriticalFontFaces(string $critical): string
    {
        $blockedFonts = get_option('wps_ic_remove_fonts');
        if (empty($blockedFonts)) {
            return $critical;
        }

        // Match @font-face { ... } blocks (multiline, non-greedy)
        $pattern = '/@font-face\s*\{.*?\}/is';

        $wpc_out152 = preg_replace_callback($pattern, function ($match) use ($blockedFonts) {
            $fontFaceBlock = $match[0];

            // Compare against the declared family name only — a substring test over the
            // whole block also matches URLs and sibling families ("Roboto" vs "Roboto Slab").
            if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $fontFaceBlock, $wpc_ff152)) {
                return $fontFaceBlock;
            }
            $wpc_fam152 = strtolower(trim($wpc_ff152[1]));
            foreach ($blockedFonts as $blocked) {
                if ($wpc_fam152 === strtolower(trim((string) $blocked))) {
                    return '';
                }
            }

            return $fontFaceBlock;
        }, $critical);
        return is_string($wpc_out152) ? $wpc_out152 : $critical;
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


            $wpc_fpb_list689[] = [$fontUrl, $type];
            $loadedFonts[] = $baseUrl;
        }
        self::$wpc_font_preloads_emitted = $loadedFonts;

        // v7.10.689 — post-paint injected; a static as=font tag render-holds Chrome 150.
        // Raw URLs ride the JSON, so the .43 parity probe's strpos($hay, $baseUrl) still hits.
        return (!empty($wpc_fpb_list689) && function_exists('wpc_font_preload_postpaint_tag'))
            ? wpc_font_preload_postpaint_tag($wpc_fpb_list689) . "\n"
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
     * §3b (v7.10.676) — reclaim DEAD inline font subsets.
     *
     * #wpc-font-subsets inlines Latin @font-face subsets as data: URIs at the very front of the
     * render-blocking document. When the same family/weight/style is ALSO declared later in the
     * document as a range-free (full-coverage) URL face — the font-carrier — the later declaration
     * wins the cascade for every character, so the earlier data: face is DEAD: parsed, decoded and
     * never used. Live wpcompress.com carried 24KB of such faces at byte 352.
     *
     * Drops a data: face ONLY when a same-identity, full-coverage (no unicode-range) URL twin exists
     * strictly AFTER the whole subset block — so it can never orphan a family (no twin => keep, which
     * is the critless-subset case) and never demote a face that is itself the cascade winner.
     * src-less metric fallbacks (local()+size-adjust) are never data: faces and are never touched.
     * Finished-document sweep: run after the crit fragment is injected, when both vehicles are present.
     */
    public static function wpc_dedupe_dead_subsets676($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_dedupe_dead_subsets', true)) {
            return $html;
        }
        if (strpos($html, 'id="wpc-font-subsets"') === false) {
            return $html;
        }
        try {
            // Latest byte offset at which a full-coverage URL face of each identity appears.
            $twin = [];
            if (preg_match_all('/@font-face\s*\{[^{}]*\}/is', $html, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $f) {
                    $blk = $f[0];
                    if (preg_match('/unicode-range\s*:/i', $blk)) { continue; }               // full coverage only
                    if (!preg_match('/src\s*:[^;}]*url\(\s*["\']?https?:/i', $blk)) { continue; } // must be a URL face
                    $k = self::wpc_face_key($blk);
                    if ($k === '') { continue; }
                    if (!isset($twin[$k]) || $f[1] > $twin[$k]) { $twin[$k] = $f[1]; }
                }
            }
            if (!$twin) { return $html; }
            // v7.10.693 — MOVE, NEVER DROP. A subset losing to a later full-coverage twin is not
            // a dead subset, it is a MISPLACED one: the .619 design says the glyph-scoped data:
            // faces sit LATER than the carrier and win their unicode ranges (that is their whole
            // job — hero glyphs painting in the real face at first byte). At some point the emit
            // order inverted (crit fragment above the wp_head carrier) and this sweep then
            // honestly deleted the exact three faces the census demands (live receipt 18:24:24:
            // dead-subsets-dropped{faces:3} beside atf_glyphs listing those same three keys).
            // Restore the invariant instead of enforcing the symptom: when any same-identity
            // full URL twin sits after the block, relocate the WHOLE block to just before
            // </head> — still parsed pre-paint, now cascade-winning. No twin => leave in place
            // (the critless-subset case, unchanged). No </head> => leave in place (fail-open).
            if (!preg_match('/<style\b[^>]*id="wpc-font-subsets"[^>]*>(.*?)<\/style>/is', $html, $sm)) {
                return $html;
            }
            $at = strpos($html, $sm[0]);
            $blockEnd = ($at === false) ? 0 : $at + strlen($sm[0]);
            $wpc_shadowed693 = 0;
            if (preg_match_all('/@font-face\s*\{[^{}]*\}/is', $sm[1], $bf)) {
                foreach ($bf[0] as $blk) {
                    if (!preg_match('/src\s*:[^;}]*url\(\s*["\']?data:/i', $blk)) { continue; }
                    $k = self::wpc_face_key($blk);
                    if ($k !== '' && isset($twin[$k]) && $twin[$k] >= $blockEnd) { $wpc_shadowed693++; }
                }
            }
            if ($wpc_shadowed693 === 0) { return $html; }
            $hp = stripos($html, '</head>');
            if ($hp === false || $hp <= $blockEnd) { return $html; }
            $out = str_replace($sm[0], '', $html);
            $hp = stripos($out, '</head>');
            if ($hp === false) { return $html; }
            $out = substr($out, 0, $hp) . $sm[0] . substr($out, $hp);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('subsets-relocated', '', '', ['faces' => $wpc_shadowed693]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * §8(c) / §3 (v7.10.680) — act on the wire manifest's font-family DROP entries: defer a named
     * family to the post-load lane, NEVER delete it.
     *
     * The service's wire.json carries, per device, drop[] entries of class 'font-family' with action
     * 'defer-to-post-load' and declared_in {vehicle,id} — a family the RENDERED census proved is used
     * only BELOW the fold (e.g. Elementor's site-wide Roboto kit, overridden to Circular Std on every
     * visible ATF element). NOTE the list: font-family drops live in drop[], NOT defer[] (defer[] is
     * class:stylesheet, the atf/rest lanes) — iterating defer[] for font-family is a conforming no-op.
     * This moves the family's @font-face out of its declared inline-style vehicle into #wpc-late-faces
     * (the loader flips that lane media=all after load), so the face still lands at settle for
     * below-fold text but is off the critical chain. Defer, never delete.
     *
     * Reads the §2-cached wire (disk, no network). declared_in absent => do nothing (spec §3). A
     * non-inline-style vehicle (a link is a whole sheet, not one family) => conservative no-op. Fast
     * no-op when the manifest carries no font-family drops (today's default). Finished-document sweep,
     * after the crit is injected, so the declared_in block is present.
     */
    public static function wpc_defer_wire_dropfaces680($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_wire_font_defer', true)
            || !defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
            return $html;
        }
        try {
            $urlKey = (string) (new wps_ic_url_key())->setup('');
            if ($urlKey === '') { return $html; }
            $wf = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/wire.json';
            if (!@is_readable($wf)) { return $html; }
            $wire = json_decode((string) @file_get_contents($wf), true);
            if (!is_array($wire) || empty($wire['wire']) || !is_array($wire['wire'])) { return $html; }
            $dev   = (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
            $node  = (isset($wire['wire'][$dev]) && is_array($wire['wire'][$dev])) ? $wire['wire'][$dev] : [];
            $drops = (isset($node['drop']) && is_array($node['drop'])) ? $node['drop'] : [];
            if (!$drops) { return $html; }

            $late = '';
            foreach ($drops as $d) {
                if (!is_array($d) || ($d['class'] ?? '') !== 'font-family'
                    || ($d['action'] ?? '') !== 'defer-to-post-load') { continue; }
                $fam  = strtolower(trim((string) ($d['family'] ?? '')));
                if ($fam === '') { continue; }
                $decl = (isset($d['declared_in']) && is_array($d['declared_in'])) ? $d['declared_in'] : null;
                // v7.10.684 — declared_in is a HINT, not a requirement. The wire the service
                // actually ships (v3.178+, seen live on wpcompress: drop[]={class,family,basis,
                // why,action,evidence}) carries NO declared_in — the old hard requirement made
                // §8(c) a conforming no-op on every real manifest while Roboto sat in the
                // critical chain. With the hint: target that one <style>. Without it: sweep every
                // inline <style> EXCEPT our own first-paint blocks (crit / late-faces / header
                // slice) — moving a face OUT of those would be the §8 inversion.
                $wpc_targets684 = [];
                if ($decl) {
                    if (strtolower((string) ($decl['vehicle'] ?? '')) !== 'inline-style' || empty($decl['id'])) {
                        continue;                                               // link/other vehicle => no-op
                    }
                    if (preg_match('/<style\b[^>]*\bid=(["\'])' . preg_quote((string) $decl['id'], '/') . '\1[^>]*>(.*?)<\/style>/is', $html, $sm)) {
                        $wpc_targets684[] = [$sm[0], $sm[2]];
                    }
                } elseif (preg_match_all('/<style\b([^>]*)>(.*?)<\/style>/is', $html, $am, PREG_SET_ORDER)) {
                    foreach ($am as $wpc_sb684) {
                        if (preg_match('/\bid=(["\'])(wpc-critical-css[^"\']*|wpc-crit-faces|wpc-late-faces|wpc-header-css-slice)\1/i', $wpc_sb684[1])) {
                            continue;
                        }
                        if (stripos($wpc_sb684[2], '@font-face') === false) { continue; }
                        $wpc_targets684[] = [$wpc_sb684[0], $wpc_sb684[2]];
                    }
                }
                // v7.10.798 — A DEFERRAL MUST LEAVE SOMETHING PAINTING. This lane moves EVERY face
                // of a named family out of the vehicle the service points at, and with declared_in
                // present that vehicle may be the crit itself — no coverage test anywhere on the
                // path. If nothing else in the document declares the family with a face that paints
                // at first paint (a data: or local() src), the move strands it in the inert lane and
                // the family renders fallback until the loader flips media, permanently on any
                // render where that flip never comes. The sibling lane (wpc_demote_url_faces) has
                // carried this discipline since .603/.726; the wire lane never did.
                if (!self::wpc_family_survives798($html, $fam)) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('wire-font-defer-refused', $urlKey, '', [
                            'fam' => $fam, 'dev' => $dev, 'why' => 'no first-paint face would remain',
                        ]);
                    }
                    continue;
                }
                foreach ($wpc_targets684 as $wpc_t684) {
                    $block = $wpc_t684[0]; $css = $wpc_t684[1]; $moved = '';
                    $newCss = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use ($fam, &$moved) {
                        // v7.20.05 — a data: face is not a fetch: deferring it buys nothing and
                        // strands its glyphs behind the media flip (dalton: the 7 embedded Poppins
                        // subsets swept out of the .04 carrier = Arial until load). local() same.
                        if (stripos($m[0], 'data:') !== false
                            || preg_match('/src\s*:[^;}]*\blocal\s*\(/i', $m[0])) {
                            return $m[0];
                        }
                        if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $m[0], $ff)
                            && strtolower(trim($ff[1])) === $fam) {
                            $moved .= $m[0];
                            return '';                                             // pull it off the critical path
                        }
                        return $m[0];
                    }, $css);
                    if ($moved !== '' && is_string($newCss)) {
                        $late .= $moved;
                        $html  = str_replace($block, str_replace($css, $newCss, $block), $html);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('wire-font-deferred', $urlKey, '', ['fam' => $fam, 'dev' => $dev]);
                        }
                    }
                }
            }
            if ($late === '') { return $html; }
            // Land the moved faces in #wpc-late-faces (loader flips media=all after load); create if absent.
            if (preg_match('/<style\b[^>]*\bid=(["\'])wpc-late-faces\1[^>]*>/i', $html, $m2, PREG_OFFSET_CAPTURE)) {
                $at = $m2[0][1] + strlen($m2[0][0]);
                $html = substr($html, 0, $at) . $late . substr($html, $at);
            } else {
                $lane = "\r\n" . '<style id="wpc-late-faces" media="not all">' . $late . '</style>';
                // v7.10.924 — the lane carries its own remover
                if (function_exists('wpc_lf_flip_js924') && strpos($html, 'wpc-lf-flip924') === false) {
                    $lane .= wpc_lf_flip_js924();
                }
                $html = (strpos($html, '</head>') !== false)
                    ? preg_replace('/<\/head>/i', $lane . '</head>', $html, 1)
                    : wpc_body_inject809($html, $lane);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
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
    public static function wpc_inline_wire_lcp681($html)
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
            $wpc_fb700 = function ($h, $why) use ($url, $dev, $urlKey, $lcp) {
                $GLOBALS['wpc_lcp_cover700'] = 'fallback';
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wire-lcp-cover-fallback', $urlKey, '', ['dev' => $dev, 'why' => $why]);
                }
                if ($url === '' || !preg_match('#^https?://#i', $url)) {
                    $GLOBALS['wpc_lcp_cover700'] = 'none'; // nothing to cover WITH — admission holds the copy
                    return $h;
                }
                if (strpos($h, 'wpc-lcp-bg-preload') !== false || strpos($h, 'wpc-lcp-bg-authority') !== false) {
                    return $h; // an earlier lane already covered it
                }
                $wpc_med700 = ($dev === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)';
                $wpc_inj700 = '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($url)
                    . '" id="wpc-lcp-bg-preload700" media="' . $wpc_med700 . '">';
                $wpc_sel700 = '';
                if (defined('WPS_IC_CRITICAL')) {
                    $wpc_lj700 = json_decode((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/lcp.json'), true);
                    $wpc_eln700 = null;
                    if (is_array($wpc_lj700)) {
                        if (isset($wpc_lj700[$dev]['lcp_element']) && is_array($wpc_lj700[$dev]['lcp_element'])) { $wpc_eln700 = $wpc_lj700[$dev]['lcp_element']; }
                        elseif (isset($wpc_lj700['lcp_element'][$dev]) && is_array($wpc_lj700['lcp_element'][$dev])) { $wpc_eln700 = $wpc_lj700['lcp_element'][$dev]; }
                        elseif (isset($wpc_lj700['lcp_element']) && is_array($wpc_lj700['lcp_element'])) { $wpc_eln700 = $wpc_lj700['lcp_element']; }
                    }
                    $wpc_sel700 = (is_array($wpc_eln700) && isset($wpc_eln700['sel']) && is_string($wpc_eln700['sel'])) ? trim($wpc_eln700['sel']) : '';
                    if ($wpc_sel700 !== '' && (strlen($wpc_sel700) > 240
                        || !preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $wpc_sel700))) {
                        $wpc_sel700 = '';
                    }
                }
                if ($wpc_sel700 !== '' && apply_filters('wpc_lcp_bg_authority', true)) {
                    $wpc_inj700 .= '<style id="wpc-lcp-bg-authority700">@media ' . $wpc_med700 . '{' . $wpc_sel700
                        . '{background-image:url("' . esc_url($url) . '") !important}}</style>';
                }
                $wpc_hp700 = strripos($h, '</head>');
                return ($wpc_hp700 !== false)
                    ? substr($h, 0, $wpc_hp700) . $wpc_inj700 . substr($h, $wpc_hp700)
                    : $h . $wpc_inj700;
            };
            if (strtolower((string) ($lcp['asset_type'] ?? '')) !== 'svg') { return $wpc_fb700($html, 'not-svg'); }  // belt: SVG only
            if ($url === '' || !preg_match('#(/wp-content/uploads/[^"\'()\s<>?]+\.svg)#i', $url, $mm)) { return $wpc_fb700($html, 'no-uploads-url'); }
            $uploadsPath = $mm[1];
            $local = (defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/') : '') . substr($uploadsPath, strlen('/wp-content'));
            if (!@is_readable($local)) { return $wpc_fb700($html, 'local-unreadable'); }
            $bytes = (int) @filesize($local);
            if ($bytes <= 0 || $bytes > (int) apply_filters('wpc_wire_lcp_max_bytes', 12288)) { return $wpc_fb700($html, 'size'); }  // ≤12KB belt
            $svg = (string) @file_get_contents($local);
            if ($svg === '' || stripos($svg, '<svg') === false || stripos($svg, '</script') !== false) { return $wpc_fb700($html, 'svg-invalid'); }
            $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);  // base64 alphabet is preg-replace-safe
            $count = 0;
            // background url(...) — host-agnostic match on the uploads path, optional query, any quotes
            $html = preg_replace('#url\(\s*([\'"]?)[^"\'()\s]*' . preg_quote($uploadsPath, '#') . '(?:\?[^"\'()\s]*)?\1\s*\)#i',
                'url(' . $dataUri . ')', $html, -1, $c1); $count += (int) $c1;
            if (strtolower((string) ($lcp['vehicle'] ?? '')) === 'img') {
                $html = preg_replace('#(\bsrc=)([\'"])[^"\']*' . preg_quote($uploadsPath, '#') . '(?:\?[^"\']*)?\2#i',
                    '$1$2' . $dataUri . '$2', $html, -1, $c2); $count += (int) $c2;
            }
            if ($count === 0) { return $wpc_fb700($html, 'not-on-page'); }  // rule lives in a deferred sheet (lean crit) — cover via fallback
            $GLOBALS['wpc_lcp_cover700'] = 'inline';
            if (($lcp['preload_action'] ?? '') === 'remove') {
                $html = preg_replace('#<link\b[^>]*\bid=(["\'])wpc-lcp-bg-preload[^"\']*\1[^>]*>#i', '', $html);
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

    public static function wpc_gfaces_prune_ranges($faces)
    {
        $out = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) {
            if (!preg_match('/unicode-range\s*:\s*([^;}]+)/i', $m[0], $ur)) {
                return $m[0];
            }
            $r = strtoupper($ur[1]);
            // latin + latin-ext stay; other script subsets drop (locale-guarded at the call site).
            $keep = strpos($r, 'U+0000') !== false || strpos($r, 'U+00-') !== false
                || strpos($r, 'U+0-') !== false || strpos($r, 'U+0100') !== false;
            return $keep ? $m[0] : '';
        }, $faces);
        if (!is_string($out) || stripos($out, '@font-face') === false) {
            return $faces;
        }
        return trim($out);
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
                || !preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,20000}?(?:<\/header>|<main\b|<div[^>]*data-elementor-type="wp-page")/is', $html, $wpc_hm205)) {
                return [];
            }
            $region = $wpc_hm205[0];
            $tokens = [];
            if (preg_match_all('/elementor-element-([a-f0-9]{6,8})/i', $region, $wpc_tm205)) {
                foreach (array_unique($wpc_tm205[1]) as $t) {
                    $tokens[] = 'elementor-element-' . $t;
                }
            }
            if (preg_match_all('/\bid="([A-Za-z][\w-]{3,40})"/', $region, $wpc_im205)) {
                foreach (array_unique($wpc_im205[1]) as $t) {
                    $tokens[] = '#' . $t;
                }
            }
            // Block themes carry no builder hashes or ids — their header layout lives on
            // wp-block-*/layout classes.
            if (preg_match_all('/\b(wp-block-[a-z][a-z0-9-]{2,40}|is-layout-[a-z-]{2,24}|items-justified-[a-z]{2,12}|has-global-padding|wp-container-[\w-]{2,40}|is-responsive)\b/', $region, $wpc_bm224)) {
                foreach (array_unique($wpc_bm224[1]) as $t) {
                    $tokens[] = $t;
                }
            }
            return array_slice(array_values(array_unique($tokens)), 0, 40);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Move url() @font-face rules out of $css into $bag; data: faces untouched. */
    /**
     * A family left with no live face is not deferred, it is GONE: matching skips the family
     * and lands on the next stack entry, which for an icon family has no glyph at the
     * private-use codepoint, so the browser paints .notdef at an advance width that is not
     * the real glyph's. Keep one face live per family that would otherwise be emptied.
     */
    // v7.10.798 — would this family still have a face that PAINTS if we deferred its url() faces?
    // Only a data: src (bytes already in the document) or a local() src (bytes already on the
    // machine) paints at first paint; a url() face is a fetch, which is the very thing the wire
    // lane defers. The family match is EXACT, so "Playfair Display Fallback" — a metric-override
    // block carrying src:local("Arial") and zero font bytes — can never be read as coverage for
    // "Playfair Display". That conflation is the whole reason this guard exists.
    public static function wpc_family_survives798($html, $fam)
    {
        if (!is_string($html) || $html === '' || !is_string($fam) || $fam === '') {
            return false;
        }
        if (!apply_filters('wpc_wire_font_defer_guard', true)) {
            return true;
        }
        if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $html, $fm)) {
            return false;
        }
        foreach ($fm[0] as $blk) {
            if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $blk, $ff)) {
                continue;
            }
            if (strtolower(trim($ff[1], " \t\"'")) !== strtolower(trim($fam))) {
                continue;
            }
            if (stripos($blk, 'data:') !== false || preg_match('/src\s*:[^;}]*\blocal\s*\(/i', $blk)) {
                return true;
            }
        }
        return false;
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
            $wpc_cov726 = function ($blk, &$sink) {
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
                    $cfam = $wpc_cov726($cblk, $cvr);
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
                        $wpc_cov726($blk, $cvr);
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
                $wpc_fk726 = $flc . '|' . (preg_match('/font-style\s*:\s*(italic|oblique)/i', $m[0]) ? 'i' : 'n');
                if ($flc !== '' && !empty($cvr[$wpc_fk726]) && apply_filters('wpc_drop_covered_faces', true)) {
                    $wpc_cs726 = self::wpc_face_range_set($m[0]);
                    $wpc_sp726 = self::wpc_face_weight_span($m[0]);
                    foreach ($cvr[$wpc_fk726] as $wpc_c726) {
                        if ($wpc_c726['sp'][0] <= $wpc_sp726[0] && $wpc_c726['sp'][1] >= $wpc_sp726[1]
                            && self::wpc_range_covers($wpc_c726['r'], $wpc_cs726)) {
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
    public static function wpc_subsets_carry_faces798($doc)
    {
        if (!is_string($doc) || $doc === '' || strpos($doc, 'id="wpc-font-subsets"') === false) {
            return false;
        }
        if (!preg_match('/<style\b[^>]*\bid="wpc-font-subsets"[^>]*>(.*?)<\/style>/is', $doc, $m)) {
            return false;
        }
        return stripos($m[1], 'data:font') !== false && stripos($m[1], '@font-face') !== false;
    }

    public static function wpc_faces_covered601($output, $html, $crit = '')
    {
        // v7.10.798 — PRESENCE IS NOT SERVICE. This arm read the subsets ELEMENT, so an empty or
        // face-less #wpc-font-subsets licensed the demotion on its own existence. The per-family
        // pass downstream keeps a face for every uncovered family, so it was never destructive —
        // but a vehicle is not a delivery, and this is the same conflation twice in one lane.
        if (self::wpc_subsets_carry_faces798((string) $output) || self::wpc_subsets_carry_faces798((string) $html)) {
            return true;
        }
        return stripos((string) $crit, 'data:font/woff2;base64') !== false
            || stripos((string) $crit, 'wpc-fonts-embedded') !== false;
    }

    /**
     * Collapse duplicate @font-face blocks WITHIN one CSS blob (first occurrence wins).
     * The generator captures the served page, so any face the page carries N times comes
     * back N+1 times in the next artifact — without this, faces compound without bound.
     * Only whitespace-normalized byte-identical blocks are dropped; distinct faces pass.
     */
    public static function wpc_face_self_dedupe($css)
    {
        try {
            if (!is_string($css) || $css === '' || substr_count(strtolower($css), '@font-face') < 2) {
                return $css;
            }
            $seen = [];
            $out  = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use (&$seen) {
                $k = md5(strtolower(preg_replace('/\s+/', ' ', $m[0])));
                if (isset($seen[$k])) {
                    return '';
                }
                $seen[$k] = 1;
                return $m[0];
            }, $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    public static function wpc_face_dedupe($faces, $crit)
    {
        if (!is_string($faces) || $faces === '' || !is_string($crit) || stripos($crit, '@font-face') === false) {
            return $faces;
        }
        // Coverage spans per family|style from crit faces with a real local file source
        // and base-latin range; a span only covers when it contains the face's weight.
        $covers = [];
        if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $crit, $cm)) {
            foreach ($cm[0] as $cf) {
                if (stripos($cf, 'url(') === false || stripos($cf, 'data:') !== false
                    || stripos($cf, 'fonts.gstatic') !== false || stripos($cf, 'fonts.bunny') !== false) {
                    continue;
                }
                if (!self::wpc_face_range_latin($cf)) {
                    continue;
                }
                if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $cf, $ff)) {
                    continue;
                }
                $s = preg_match('/font-style\s*:\s*(italic|oblique)/i', $cf) ? 'i' : 'n';
                $covers[strtolower(trim($ff[1])) . '|' . $s][] = self::wpc_face_weight_span($cf);
            }
        }
        if (!$covers) {
            return $faces;
        }
        // Combined crit carries both device blobs: a face must appear in each to truly
        // cover every viewport, so require two covering spans in that mode.
        $need = self::wpc_combined_crit_on() ? 2 : 1;
        $out = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($covers, $need) {
            if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $m[0], $ff)) {
                return $m[0];
            }
            $s = preg_match('/font-style\s*:\s*(italic|oblique)/i', $m[0]) ? 'i' : 'n';
            $fk = strtolower(trim($ff[1])) . '|' . $s;
            if (empty($covers[$fk])) {
                return $m[0];
            }
            $sp = self::wpc_face_weight_span($m[0]);
            $n = 0;
            foreach ($covers[$fk] as $cs) {
                if ($cs[0] <= $sp[0] && $cs[1] >= $sp[1]) {
                    $n++;
                }
            }
            return ($n >= $need) ? '' : $m[0];
        }, $faces);
        return is_string($out) ? trim($out) : $faces;
    }

    public static function wpc_atf_reveal_css($dir)
    {
        $jf = rtrim((string) $dir, '/') . '/lcp.json';
        if (!@is_readable($jf)) {
            return '';
        }
        $cf = rtrim((string) $dir, '/') . '/atf_reveal.css';
        $wpc_cc713 = @is_readable($cf) ? (string) @file_get_contents($cf) : '';
        if ($wpc_cc713 !== '' && (int) @filemtime($cf) >= (int) @filemtime($jf)
            && strpos($wpc_cc713, '/*wpc-ar713*/') !== false) {
            return $wpc_cc713 === '/*wpc-ar713*/' ? '' : $wpc_cc713;
        }
        $j = json_decode((string) @file_get_contents($jf), true);
        $ar = (is_array($j) && !empty($j['atf_reveal']) && is_array($j['atf_reveal'])) ? $j['atf_reveal'] : [];
        $css = '/*wpc-ar713*/';
        foreach (['mobile' => '@media (max-width: 767.98px){', 'desktop' => '@media (min-width: 768px){'] as $wpc_dev => $wpc_wrap) {
            // v7.10.713 — a device with NO observed reveal items keeps its LCP element hostage to
            // the loader's reveal sweep: IO fires ~300ms on live-frame runs, the 1200ms timer on
            // starved ones, and that second commit pays the ~1s presentation quantum (live PSI:
            // render delay 320ms on the 99 vs 1,480ms on the 94, same bytes). lcp_element.sel is
            // observed on every gen — when items are absent, the LCP element itself becomes the
            // reveal set, the same instant-visibility decision the desktop arm already ships.
            if (empty($ar[$wpc_dev]['items']) || !is_array($ar[$wpc_dev]['items'])) {
                $wpc_ls713 = isset($j['lcp_element'][$wpc_dev]['sel']) ? trim((string) $j['lcp_element'][$wpc_dev]['sel']) : '';
                if ($wpc_ls713 !== '' && strlen($wpc_ls713) <= 512 && !preg_match('/[{}<>@\\\\]/', $wpc_ls713)) {
                    $css .= $wpc_wrap . $wpc_ls713 . '{visibility:visible !important;}}';
                }
                continue;
            }
            $rules = '';
            $n = 0;
            foreach ($ar[$wpc_dev]['items'] as $it) {
                if (!is_array($it) || empty($it['sel']) || empty($it['props']) || !is_array($it['props'])) {
                    continue;
                }
                $sel = trim((string) $it['sel']);
                if ($sel === '' || strlen($sel) > 512 || preg_match('/[{}<>@\\\\]/', $sel)) {
                    continue;
                }
                $body = '';
                foreach ($it['props'] as $p => $v) {
                    $p = strtolower(trim((string) $p));
                    if (!in_array($p, ['opacity', 'visibility', 'display'], true)) {
                        continue;
                    }
                    $v = trim((string) $v);
                    if ($v === '' || strlen($v) > 64 || !preg_match('/^[a-z0-9 .%-]+$/i', $v)) {
                        continue;
                    }
                    $body .= $p . ':' . $v . ' !important;';
                }
                if ($body === '') {
                    continue;
                }
                $rules .= $sel . '{' . $body . '}';
                if (++$n >= 24) {
                    break;
                }
            }
            if ($rules !== '') {
                $css .= $wpc_wrap . $rules . '}';
            }
        }
        if (strlen($css) > 8192) {
            $css = '/*wpc-ar713*/';
        }
        @file_put_contents($cf, $css);
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
            $wpc_fkey504 = function ($bl) {
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
                    list($fam, $lo, $hi, $st) = $wpc_fkey504($bl);
                    if ($fam === '') { continue; }
                    // A range (font-weight: 400 600) covers every 100-step inside it.
                    for ($w = $lo; $w <= $hi; $w += 100) {
                        $covered[$fam . '|' . $w . '|' . $st] = 1;
                    }
                }
            }
            if (empty($covered)) { return $crit; }
            // 2) strip url()-src faces for covered families; keep everything else verbatim
            return preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($covered, $wpc_fkey504) {
                if (stripos($m[0], 'base64') !== false) { return $m[0]; }
                if (!preg_match('/src\s*:\s*[^;}]*url\(\s*["\']?(?!data:)/i', $m[0])) { return $m[0]; }
                list($fam, $lo, $hi, $st) = $wpc_fkey504($m[0]);
                if ($fam === '') { return $m[0]; }
                // Strip ONLY when every weight this face serves is genuinely covered by a subset.
                for ($w = $lo; $w <= $hi; $w += 100) {
                    if (empty($covered[$fam . '|' . $w . '|' . $st])) {
                        return $m[0];
                    }
                }
                return '';
            }, $crit);
        } catch (\Throwable $e) {
            return $crit;
        }
    }

    public static function wpc_font_metric_overrides(&$critBlob)
    {
        if (!is_string($critBlob) || $critBlob === '' || stripos($critBlob, 'font-family') === false) {
            return '';
        }
        $table = apply_filters('wpc_font_fallback_metrics', []);
        if (!is_array($table)) {
            $table = [];
        }


        $wpc_skip45 = ['sans-serif' => 1, 'serif' => 1, 'monospace' => 1, 'cursive' => 1, 'fantasy' => 1,
            'system-ui' => 1, '-apple-system' => 1, 'blinkmacsystemfont' => 1, 'inherit' => 1, 'initial' => 1,
            'unset' => 1, 'arial' => 1, 'helvetica' => 1, 'helvetica neue' => 1, 'georgia' => 1, 'times' => 1,
            'times new roman' => 1, 'courier' => 1, 'courier new' => 1, 'verdana' => 1, 'tahoma' => 1, 'segoe ui' => 1];
        $cands = [];
        if (preg_match_all('/font-family\s*:\s*([^;}{]+)/i', $critBlob, $wpc_fm45)) {
            foreach ($wpc_fm45[1] as $wpc_v45) {
                $tok = trim(trim(explode(',', trim($wpc_v45))[0]), " \t\"'");
                if ($tok === '' || stripos($tok, 'var(') !== false) { continue; }
                $lc = strtolower($tok);
                if (isset($wpc_skip45[$lc]) || substr($lc, -9) === ' fallback') { continue; }
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


        static $wpc_faces_emitted95 = [];
        $faces = '';
        foreach ($cands as $lc => $fam) {
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
            $wpc_pwf356 = '';
            foreach ($tlc as $wpc_tk356 => $wpc_tv356) {
                if (count($wpc_faces_emitted95) >= 24) { break; }
                if (!is_array($wpc_tv356) || strpos((string) $wpc_tk356, $lc . '|') !== 0) { continue; }
                if (isset($wpc_faces_emitted95[$wpc_tk356])) { continue; }
                $wpc_pp356 = explode('|', (string) $wpc_tk356);
                $wpc_w356 = isset($wpc_pp356[1]) ? trim((string) $wpc_pp356[1]) : '';
                $wpc_s356 = (isset($wpc_pp356[2]) && strtolower((string) $wpc_pp356[2]) === 'italic') ? 'italic' : 'normal';
                if ($wpc_w356 === '' || !preg_match('/^\d{1,4}( \d{1,4})?$/', $wpc_w356)) { continue; }
                $wpc_pd356 = '';
                foreach (['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'] as $k2) {
                    if (!empty($wpc_tv356[$k2]) && preg_match('/^[0-9.]+%$/', (string) $wpc_tv356[$k2])) { $wpc_pd356 .= $k2 . ':' . $wpc_tv356[$k2] . ';'; }
                }
                if ($wpc_pd356 === '') { continue; }
                // v7.10.802 — inherit the FAMILY's measured local before falling back to Arial.
                // Per-weight rows routinely carry metrics and no 'local', and a hardcoded default
                // here shadows a serif with a sans for exactly the weights a page uses: justmsp's
                // H1 matched the weight-400/500 faces and painted Arial until the real Playfair
                // landed, while the descriptor-less face correctly said Times New Roman. Every twin
                // face for a family must shadow the same real font.
                $wpc_plf356 = (isset($wpc_tv356['local']) && is_string($wpc_tv356['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $wpc_tv356['local']))
                    ? $wpc_tv356['local']
                    : ((is_array($m) && isset($m['local']) && is_string($m['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $m['local']))
                        ? $m['local'] : 'Arial');
                $wpc_faces_emitted95[$wpc_tk356] = 1;
                $wpc_pwf356 .= '@font-face{font-family:"' . $fb . '";src:local("' . $wpc_plf356 . '");font-weight:' . $wpc_w356 . ';font-style:' . $wpc_s356 . ';' . $wpc_pd356 . '}';
            }

            if ($decl === '' && $wpc_pwf356 === '') { continue; }

            // (matching a serif's box to Arial geometry is the wrong frame). Whitelisted names only;
            // absent/invalid → Arial, exactly the old behavior (census entries carry no 'local').
            if ($decl !== '') {
                $wpc_lf = (isset($m['local']) && is_string($m['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $m['local']))
                    ? $m['local'] : 'Arial';
                if (!isset($wpc_faces_emitted95[$lc])) {
                    $wpc_faces_emitted95[$lc] = 1;
                    $faces .= '@font-face{font-family:"' . $fb . '";src:local("' . $wpc_lf . '");' . $decl . '}';
                }
            }
            $faces .= $wpc_pwf356;


            $wpc_cb50 = preg_replace(
                '/font-family\s*:\s*([\'"]?)' . preg_quote($fam, '/') . '\1\s*,/i',
                'font-family:$1' . $fam . '$1,"' . $fb . '",',
                $critBlob
            );
            if (is_string($wpc_cb50)) {
                $critBlob = $wpc_cb50;
            }
            // Single-family declarations (no chain) get the fallback appended too —
            // with @font-face blocks masked: their font-family descriptor takes one name.
            $wpc_mask51 = [];
            $wpc_cb51 = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($mm) use (&$wpc_mask51) {
                $wpc_mask51[] = $mm[0];
                return "\x02WPCFF" . (count($wpc_mask51) - 1) . "\x02";
            }, $critBlob);
            if (is_string($wpc_cb51)) {
                $wpc_cb51 = preg_replace(
                    '/font-family\s*:\s*([\'"]?)' . preg_quote($fam, '/') . '\1\s*([;}!])/i',
                    'font-family:$1' . $fam . '$1,"' . $fb . '"$2',
                    $wpc_cb51
                );
                if (is_string($wpc_cb51)) {
                    $wpc_cb51 = preg_replace_callback('/\x02WPCFF(\d+)\x02/', function ($mm) use ($wpc_mask51) {
                        return $wpc_mask51[(int) $mm[1]];
                    }, $wpc_cb51);
                }
                if (is_string($wpc_cb51) && strpos($wpc_cb51, "\x02WPCFF") === false) {
                    $critBlob = $wpc_cb51;
                }
            }
        }
        if ($faces !== '' && function_exists('wpc_font_twin_locals802')) {
            $faces = wpc_font_twin_locals802($faces);
        }
        return $faces !== '' ? '<style id="wpc-font-fallbacks">' . $faces . '</style>' : '';
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

    // Key is the (href, media) TUPLE: the desktop bg-preload legitimately repeats the same
    // href under a different media, so href alone would drop it.
    private static function wpc_lcp_preload_media588($wpc_s588)
    {
        return preg_match('/\bmedia\s*=\s*(["\'])(.*?)\1/i', (string) $wpc_s588, $wpc_m588)
            ? trim($wpc_m588[2]) : '';
    }

    private static function wpc_lcp_canon746($wpc_u746)
    {
        $wpc_u746 = html_entity_decode((string) $wpc_u746, ENT_QUOTES);
        if (preg_match('#^https?://[^/]+/m:0/a:(https?://.+)$#i', $wpc_u746, $wpc_m746)) {
            $wpc_u746 = $wpc_m746[1];
        }
        $wpc_p746 = parse_url($wpc_u746);
        if (empty($wpc_p746['path'])) { return strtolower($wpc_u746); }
        return strtolower($wpc_p746['path'] . (isset($wpc_p746['query']) ? '?' . $wpc_p746['query'] : ''));
    }

    private static function wpc_lcp_preload_dupe588($wpc_out588, $wpc_url588, $wpc_media588)
    {
        if (!is_string($wpc_url588) || $wpc_url588 === ''
            || !is_string($wpc_out588) || $wpc_out588 === '') {
            return false;
        }
        $wpc_need588 = self::wpc_lcp_canon746($wpc_url588);
        $wpc_want588 = self::wpc_lcp_preload_media588($wpc_media588);
        foreach (preg_split('/(?=<link\b)/i', $wpc_out588) as $wpc_tag588) {
            if (stripos($wpc_tag588, 'rel="preload"') === false) { continue; }
            if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $wpc_tag588, $wpc_h588)) { continue; }
            if (self::wpc_lcp_canon746($wpc_h588[2]) !== $wpc_need588) { continue; }
            if (self::wpc_lcp_preload_media588($wpc_tag588) === $wpc_want588) { return true; }
        }
        return false;
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
        $wpc_toks344 = ['cmplz', 'complianz', 'cookieyes', 'cky-consent', 'cky-style', 'cookie-law-info',
            'cookiebot', 'borlabs', 'iubenda', 'onetrust', 'usercentrics', 'surecookie',
            'cookie-notice', 'cookie-consent', 'moove_gdpr', 'moove-gdpr', 'osano', 'termly',
            'tarteaucitron', 'quantcast', 'consently', 'didomi', 'wpl_cookie_consent'];
        if (function_exists('apply_filters')) {
            $wpc_toks344 = (array) apply_filters('wpc_consent_tokens', $wpc_toks344);
        }
        foreach ($wpc_toks344 as $t) {
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
            $wpc_v729 = (bool) $meta['sel_unique'];
            if (!$wpc_v729 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('reserve-sel-refused', $leg, '', ['why' => 'service:false', 'sel' => substr((string) $sel, 0, 80)]);
            }
            return $wpc_v729;
        }
        // v7.10.730 — the SAME artifact often carries a verdict for this exact selector on a
        // different node. Receipted on a live site: atf_images[] ships `img.wp-image-127` with
        // no sel_unique, while lcp_element ships the identical selector with sel_unique:true.
        // The service measured it against the rendered DOM; only the field placement differs.
        // Honour that measurement rather than re-deriving it from the selector's shape —
        // without this, .729 took the whole atf_images leg dark on that site.
        if (is_array($verdicts) && isset($verdicts[(string) $sel])) {
            $wpc_v730 = (bool) $verdicts[(string) $sel];
            if (!$wpc_v730 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('reserve-sel-refused', $leg, '', ['why' => 'artifact:false', 'sel' => substr((string) $sel, 0, 80)]);
            }
            return $wpc_v730;
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

    public static function wpc_cls_reserve_style($critDir, $isMobile)
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
        // No early return on empty shifts — the atf_images/atf_conceal legs below must
        // run without it (empty-shifts guillotine kept the conceal pin from ever emitting).

        // v7.10.730 — harvest every sel_unique verdict the artifacts DO carry, keyed by
        // selector, so a node missing the field can inherit the measurement the service
        // already made for that exact selector elsewhere in the same file. Built from the
        // whole decoded tree because the field's placement is not consistent across node
        // types (lcp_element has it; atf_images does not).
        $wpc_vmap730 = [];
        $wpc_harvest730 = function ($node) use (&$wpc_harvest730, &$wpc_vmap730) {
            if (is_array($node)) {
                if (isset($node['sel']) && is_string($node['sel']) && $node['sel'] !== ''
                    && array_key_exists('sel_unique', $node)) {
                    $wpc_k730 = $node['sel'];
                    // A false anywhere is sticky: one node proving it non-unique outranks
                    // another node's true, because non-uniqueness is the dangerous direction.
                    if (!isset($wpc_vmap730[$wpc_k730]) || $wpc_vmap730[$wpc_k730]) {
                        $wpc_vmap730[$wpc_k730] = (bool) $node['sel_unique'];
                    }
                }
                foreach ($node as $wpc_c730) {
                    if (is_array($wpc_c730)) { $wpc_harvest730($wpc_c730); }
                }
            }
        };
        $wpc_harvest730($j);
        $wpc_lraw730 = @is_readable($dir . 'lcp.json') ? json_decode((string) @file_get_contents($dir . 'lcp.json'), true) : null;
        if (is_array($wpc_lraw730)) { $wpc_harvest730($wpc_lraw730); }

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
            if (!self::wpc_sel_addresses_one($sel, $s, 'shifts', $wpc_vmap730)) {
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

        // The v3.47.1 census also addresses ATF images (sel + settled box) via lcp.json —
        // reserve each image's final height so loader-managed swaps can't re-lay their
        // region (the header-logo class: 0x0/undersized until swap, grows on arrival).
        if (count($rules) < 12 && @is_readable($dir . 'lcp.json')) {
            $lj = json_decode((string) @file_get_contents($dir . 'lcp.json'), true);
            $imgs = [];
            if (is_array($lj)) {
                if (isset($lj['atf_images'][$device]) && is_array($lj['atf_images'][$device])) {
                    $imgs = $lj['atf_images'][$device];
                } elseif (isset($lj['atf_images']) && is_array($lj['atf_images']) && !isset($lj['atf_images']['desktop'])) {
                    $imgs = $lj['atf_images'];
                }
            }
            if (isset($imgs['items']) && is_array($imgs['items'])) {
                $imgs = $imgs['items'];
            }
            foreach ($imgs as $im) {
                if (!is_array($im)) {
                    continue;
                }
                $isel = isset($im['sel']) ? trim((string) $im['sel']) : '';
                $ih   = null;
                foreach (['h', 'height', 'box_h'] as $hk) {
                    if (isset($im[$hk]) && is_numeric($im[$hk])) { $ih = (float) $im[$hk]; break; }
                }
                if ($ih === null && isset($im['box']) && is_array($im['box'])) {
                    if (isset($im['box'][1]) && is_numeric($im['box'][1])) { $ih = (float) $im['box'][1]; }
                    elseif (isset($im['box']['h']) && is_numeric($im['box']['h'])) { $ih = (float) $im['box']['h']; }
                }
                if ($isel === '' || strlen($isel) > 200 || !preg_match($selRx, $isel) || $ih === null) {
                    continue;
                }
                // v7.10.729 — this leg demanded NO uniqueness proof whatsoever before now.
                if (!self::wpc_sel_addresses_one($isel, $im, 'atf_images', $wpc_vmap730)) {
                    continue;
                }
                $ih = (int) round($ih);
                if ($ih < 10 || $ih > 2000) {
                    continue;
                }
                $rules[] = $isel . '{min-height:' . $ih . 'px}';
                if (count($rules) >= 12) {
                    break;
                }
            }
        }

        // atf_conceal (v3.48.1): top-of-page elements whose height SHRINKS between paint and
        // settle (script-collapsed headers) — pin the final height so below-content never leaps.
        if (count($rules) < 12 && @is_readable($dir . 'lcp.json')) {
            $lj2 = isset($lj) && is_array($lj) ? $lj : json_decode((string) @file_get_contents($dir . 'lcp.json'), true);
            $cons = [];
            if (is_array($lj2)) {
                if (isset($lj2['atf_conceal'][$device]) && is_array($lj2['atf_conceal'][$device])) {
                    $cons = $lj2['atf_conceal'][$device];
                } elseif (isset($lj2['atf_conceal']) && is_array($lj2['atf_conceal']) && !isset($lj2['atf_conceal']['desktop'])) {
                    $cons = $lj2['atf_conceal'];
                }
            }
            // v3.48.3 wraps entries as {items:[...],snapshot,no_addr} per device.
            if (isset($cons['items']) && is_array($cons['items'])) {
                $cons = $cons['items'];
            }
            foreach ($cons as $ce) {
                if (!is_array($ce)) {
                    continue;
                }
                $csel = isset($ce['sel']) ? trim((string) $ce['sel']) : '';
                $ch   = null;
                foreach (['final_h', 'h', 'height', 'box_h'] as $hk2) {
                    if (isset($ce[$hk2]) && is_numeric($ce[$hk2])) { $ch = (float) $ce[$hk2]; break; }
                }
                if ($ch === null && isset($ce['box']) && is_array($ce['box'])) {
                    if (isset($ce['box'][1]) && is_numeric($ce['box'][1])) { $ch = (float) $ce['box'][1]; }
                    elseif (isset($ce['box']['h']) && is_numeric($ce['box']['h'])) { $ch = (float) $ce['box']['h']; }
                }
                if ($csel === '' || strlen($csel) > 200 || !preg_match($selRx, $csel) || $ch === null) {
                    continue;
                }
                // v7.10.729 — this leg honoured an explicit false but had NO fallback, and it
                // emits `height:` rather than `min-height:` — a fixed box, so an unproven
                // address here is the most damaging of the four.
                if (!self::wpc_sel_addresses_one($csel, $ce, 'atf_conceal', $wpc_vmap730)) {
                    continue;
                }
                $ch = (int) round($ch);
                if ($ch < 10 || $ch > 2000) {
                    continue;
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
        $wpc_pr356 = [];
        $wpc_praw356 = @is_readable($dir . 'prescriptions.json') ? (string) @file_get_contents($dir . 'prescriptions.json') : '';
        // Artifact tag: the runtime not-unique gate is honored only when the mark's av
        // matches THIS file's av (a purge-and-refetch of identical bytes keeps the same
        // av; a genuine republish changes it). Mirrors wpc_presc_av_tag() server-side.
        $wpc_av356rl = $wpc_praw356 !== '' ? substr(md5($wpc_praw356), 0, 12) : '';
        if ($wpc_praw356 !== '' && apply_filters('wpc_prescriptions_reserve', true)) {
            $wpc_pj356 = json_decode($wpc_praw356, true);
            if (is_array($wpc_pj356) && isset($wpc_pj356['prescriptions']) && is_array($wpc_pj356['prescriptions'])) {
                $wpc_seen356 = [];
                foreach ($rules as $wpc_r356) {
                    $wpc_seen356[strtolower((string) substr($wpc_r356, 0, (int) strpos($wpc_r356, '{')))] = 1;
                }
                $wpc_known356 = function_exists('wpc_presc_known_classes') ? wpc_presc_known_classes() : [];
                // A3 gate 2 persistence: ids the runtime re-count found non-unique stop
                // emitting (else every pageload re-emits, re-strips, re-beacons). Reads
                // the av-stamped sticky store — eviction-proof, forced-re-apply-proof,
                // and version-precise (a stale-artifact verdict does not suppress a
                // freshly-republished id).
                static $wpc_ntu356 = null;
                if ($wpc_ntu356 === null) {
                    $wpc_ntu356 = (function_exists('wpc_presc_notuniq_get')) ? wpc_presc_notuniq_get()
                        : (function ($j) {
                            $o = [];
                            if (is_array($j)) {
                                foreach ($j as $k => $v) {
                                    if (is_array($v) && (string) ($v['skipped'] ?? '') === 'not-unique') { $o[strtolower((string) $k)] = 1; }
                                }
                            }
                            return $o;
                        })(function_exists('get_option') ? get_option('wpc_presc_journal') : null);
                }
                foreach ($wpc_pj356['prescriptions'] as $wpc_pp356) {
                    if (count($wpc_pr356) >= 8) {
                        break;
                    }
                    if (!is_array($wpc_pp356) || !empty($wpc_pp356['applied_by_service'])) {
                        continue;
                    }
                    if (!isset($wpc_pp356['fix']['type']) || strtolower((string) $wpc_pp356['fix']['type']) !== 'reserve-rect') {
                        continue;
                    }
                    if (!isset($wpc_pp356['verified_unique']) || $wpc_pp356['verified_unique'] !== true) {
                        continue;
                    }
                    $wpc_pid356 = isset($wpc_pp356['id']) ? strtolower((string) $wpc_pp356['id']) : '';
                    $wpc_pcl356 = isset($wpc_pp356['class']) ? strtolower((string) $wpc_pp356['class']) : '';
                    if (!preg_match('/^[a-f0-9]{6,40}$/', $wpc_pid356)
                        || (!empty($wpc_known356) && !in_array($wpc_pcl356, $wpc_known356, true))) {
                        continue;
                    }
                    if (isset($wpc_ntu356[$wpc_pid356])
                        && ($wpc_ntu356[$wpc_pid356] === 1 || (string) $wpc_ntu356[$wpc_pid356] === $wpc_av356rl)) {
                        continue; // runtime-recount verdict for THIS artifact: not unique
                        // (===1 is the journal-fallback sentinel when warm.php is absent)
                    }
                    $wpc_psel356 = isset($wpc_pp356['fix']['payload']['sel']) ? trim((string) $wpc_pp356['fix']['payload']['sel']) : '';
                    $wpc_ppx356  = isset($wpc_pp356['fix']['payload']['min_height_px']) ? $wpc_pp356['fix']['payload']['min_height_px'] : null;
                    if ($wpc_psel356 === '' || strlen($wpc_psel356) > 400 || !preg_match($selRx, $wpc_psel356) || !is_numeric($wpc_ppx356)) {
                        continue;
                    }
                    $wpc_ppx356 = (int) round((float) $wpc_ppx356);
                    if ($wpc_ppx356 < 24 || $wpc_ppx356 > 2000) {
                        continue;
                    }
                    // device split on the prescription's measured width; no width = both
                    if (isset($wpc_pp356['width']) && is_numeric($wpc_pp356['width'])
                        && ($isMobile ? (int) $wpc_pp356['width'] >= 768 : (int) $wpc_pp356['width'] < 768)) {
                        continue;
                    }
                    if (isset($wpc_seen356[strtolower($wpc_psel356)])) {
                        continue; // first-leg-wins across all four legs
                    }
                    $wpc_seen356[strtolower($wpc_psel356)] = 1;
                    $wpc_pr356[] = ['i' => $wpc_pid356, 's' => $wpc_psel356, 'r' => $wpc_psel356 . '{min-height:' . $wpc_ppx356 . 'px}'];
                }
            }
        }

        if (empty($rules) && empty($wpc_pr356)) {
            return '';
        }
        $wpc_out356 = '';
        if (!empty($rules)) {
            $wpc_out356 .= "\r\n" . '<style id="wpc-cls-reserve">@media ' . $media . '{' . implode('', $rules) . '}</style>';
        }
        if (!empty($wpc_pr356)) {
            $wpc_sid356 = 'wpc-presc-reserve-' . ($isMobile ? 'm' : 'd');
            $wpc_css356 = '';
            foreach ($wpc_pr356 as $wpc_pe356) {
                $wpc_css356 .= $wpc_pe356['r'];
            }
            $wpc_ajx356 = function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php';
            $wpc_out356 .= "\r\n" . '<style id="' . $wpc_sid356 . '">@media ' . $media . '{' . $wpc_css356 . '}</style>'
                // A3 count #2. The loader traps load/readyState/DOMContentLoaded, so
                // parse-completion = a MutationObserver quiet window (2.5s floor, 600ms
                // quiet, 12s hard cap → do nothing = fail open). Count semantics: >1 =
                // strip + beacon (true non-unique); 0 = strip quietly (variant page —
                // never demote the prescription); selector throw = leave the rule (the
                // CSS parser drops it anyway).
                . '<script id="wpc-presc-guard-' . ($isMobile ? 'm' : 'd') . '">(function(){var T=Date.now(),L=T,O=null;'
                . 'try{O=new MutationObserver(function(){L=Date.now()});O.observe(document.documentElement,{childList:!0,subtree:!0})}catch(e){}'
                . 'var F=function(){try{'
                . 'if(Date.now()-T>12e3){O&&O.disconnect();return}'
                . 'if(!document.body||Date.now()-L<600){setTimeout(F,700);return}'
                . 'O&&O.disconnect();'
                . 'var s=document.getElementById(' . wp_json_encode($wpc_sid356) . ');if(!s)return;'
                . 'if(window.matchMedia&&!window.matchMedia(' . wp_json_encode($media) . ').matches)return;'
                . 'var m=' . wp_json_encode(array_values($wpc_pr356)) . ',keep=[],bad=[],i,c;'
                . 'for(i=0;i<m.length;i++){try{c=document.querySelectorAll(m[i].s).length}catch(e){c=1}'
                . 'if(c>1)bad.push(m[i]);else if(c!==0)keep.push(m[i])}'
                . 'if(keep.length===m.length)return;'
                . 'var css="",j;for(j=0;j<keep.length;j++)css+=keep[j].r;'
                . 's.textContent=css?"@media "+' . wp_json_encode($media) . '+"{"+css+"}":"";'
                . 'if(bad.length){var bi=[],k;for(k=0;k<bad.length&&k<4;k++)bi.push(bad[k].i);'
                . 'try{navigator.sendBeacon&&navigator.sendBeacon(' . wp_json_encode($wpc_ajx356) . ',new URLSearchParams({action:"wpc_presc_seen",id:bi.join(","),skipped:"not-unique",av:' . wp_json_encode($wpc_av356rl) . '}))}catch(e){}}'
                . '}catch(e){}};'
                . 'setTimeout(F,2500)})();</script>';
        }
        return $wpc_out356;
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
        $out = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($usedFam, $usedWt, $keptFam) {
            $f = $m[0];
            if (strpos($f, 'data:font/woff2;base64') !== false) { return $m[0]; } // A1 subset — always keep
            if (!preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $f, $fm)) { return $m[0]; }
            $fam = strtolower(trim($fm[1]));
            if ($fam === '' || !isset($usedFam[$fam])) { return $m[0]; }
            if (empty($usedWt[$fam]) || empty($keptFam[$fam])) { return $m[0]; }
            if (preg_match('/font-weight\s*:\s*\d+\s+\d+/i', $f)) { return $m[0]; }
            $wt = preg_match('/font-weight\s*:\s*(\d+)/i', $f, $wm) ? $wm[1] : '400';
            return isset($usedWt[$fam][$wt]) ? $m[0] : '';
        }, $crit);
        return is_string($out) ? $out : $crit;
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


    public static function wpc_logo_rightsize($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'logo') === false
            || !apply_filters('wpc_logo_rightsize', true)) {
            return $html;
        }
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) {
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
                    list($wpc_qw118, $wpc_qh118) = self::srcset_real_dims($tag);
                    if ($wpc_qw118 > 0 && $wpc_qh118 > 0) {
                        $wpc_q118 = preg_replace('/<img\b/i', '<img width="' . $wpc_qw118 . '" height="' . $wpc_qh118 . '"', $tag, 1);
                        if (is_string($wpc_q118)) { return $wpc_q118; }
                    }
                }
                return $tag;
            }
            if (stripos($tag, 'id="wpc-lcp') !== false) {
                return $tag;
            }


            $wpc_lw = preg_match('/\bwidth\s*=\s*["\']?(\d+)/i', $tag, $wm43) ? (int) $wm43[1] : 0;
            $wpc_lh = preg_match('/\bheight\s*=\s*["\']?(\d+)/i', $tag, $hm43) ? (int) $hm43[1] : 0;
            $wpc_inject_dims = '';
            if ($wpc_lw <= 0 || $wpc_lh <= 0) {
                list($wpc_rw, $wpc_rh) = self::srcset_real_dims($tag);
                if ($wpc_rw > 0 && $wpc_rh > 0) {
                    $wpc_inject_dims = ' width="' . $wpc_rw . '" height="' . $wpc_rh . '"';
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
            if (preg_match('/\bsizes="([^"]*)"/i', $o, $sm43)) {

                $wpc_fb43 = (stripos($sm43[1], 'auto') === false && trim($sm43[1]) !== '')
                    ? 'auto, ' . $sm43[1] : 'auto';
                $o = preg_replace('/\bsizes="[^"]*"/i', 'sizes="' . str_replace('$', '\\$', $wpc_fb43) . '"', $o, 1);
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
                if (@file_put_contents($tmp, $r) !== false && @rename($tmp, $f)) {
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


        $wpc_pcf54 = isset(self::$settings['preload-crit-fonts']) ? (string) self::$settings['preload-crit-fonts'] : '';
        if ($wpc_pcf54 === '' && isset(self::$settings['replace-fonts']) && self::$settings['replace-fonts'] === 'local'
            && apply_filters('wpc_atf_faces_auto', true)) {
            $wpc_pcf54 = '1';
        }
        if ($wpc_pcf54 !== '1') return '';


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


        $wpc_fd43 = (string) apply_filters('wpc_atf_face_display', 'swap');
        if ($wpc_fd43 !== '' && preg_match('/^[a-z-]+$/', $wpc_fd43)) {
            foreach ($keep as $wpc_ki => $wpc_kf) {
                // A face carrying two font-display decls resolves to the LAST — strip all, set once.
                $wpc_kf = preg_replace('/font-display\s*:\s*[^;}]+;?/i', '', $wpc_kf);
                $keep[$wpc_ki] = preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $wpc_fd43 . ';', $wpc_kf, 1);
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
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
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
        return is_string($path) && $path !== '' && file_exists($path);
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
        $wpc_t543 = microtime(true);
        $GLOBALS['wpc_dlbudget543'] = $wpc_t543
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
            $wpc_crit112 = (new wps_criticalCss())->criticalExists(true);
            if (empty($wpc_crit112['desktop'])) {
                return $html;
            }
            $wpc_cd112 = dirname($wpc_crit112['desktop']) . '/';
            // A render must NEVER open a network stream here: file_get_contents() on a URL uses
            // default_socket_timeout (60s by default) and bypasses the WP HTTP API, so it is
            // invisible to our http_n counter AND to the FPM slowlog. Local paths only.
            // Belt: with criticalExists(true) this can no longer be a URL. Kept so a future
            // caller change can never silently reintroduce a network read here.
            if (stripos($wpc_cd112, 'http:') === 0 || stripos($wpc_cd112, 'https:') === 0
                || strpos($wpc_cd112, '://') !== false) {
                return $html;
            }
            // v7.10.550 — used_tpl.txt has NO PRODUCER: never written by the plugin, and the
            // service confirms it is never written, never read, has no schema and no upload path.
            // The read always missed and always fell through to tpl.txt, which is the real key.
            $wpc_tk112 = @is_file($wpc_cd112 . 'tpl.txt')
                ? trim((string) @file_get_contents($wpc_cd112 . 'tpl.txt')) : '';
            if (microtime(true) > $GLOBALS['wpc_dlbudget543']) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('droplist-budget-spent', '', '', ['at' => 'tplRead']);
                }
                return $html;
            }


            $wpc_up112 = $wpc_tk112 !== '' ? wpc_used_css_path($wpc_tk112, $this->isMobile() ? 'mobile' : 'desktop') : '';
            if ($wpc_up112 === '' || !(@filesize($wpc_up112) > 64)) {
                $wpc_up112 = $wpc_tk112 !== '' ? wpc_used_css_path($wpc_tk112) : '';
            }
            if ($wpc_up112 === '' || !(@filesize($wpc_up112) > 64)) {
                return $html;
            }
            // Liveness SIDECAR, not the artifact: rewriteLogic derives the bundle's
            // ?uv= cache-buster from its mtime (line ~3518), so touching the artifact
            // itself would rotate that URL daily — the exact disease .642 killed on
            // combine. The sidecar shares the family stem, so the retention sweep's
            // family rule reads it as liveness.
            if (function_exists('wpc_store_touch642')) {
                $wpc_lv642 = $wpc_up112 . '.live';
                if (!@is_file($wpc_lv642)) {
                    @file_put_contents($wpc_lv642, '1');
                } else {
                    wpc_store_touch642($wpc_lv642);
                }
            }


            if (stripos($html, 'id="wpc-used-css"') === false && stripos($html, "id='wpc-used-css'") === false) {
                return $html;
            }
            if (microtime(true) > $GLOBALS['wpc_dlbudget543']) { return $html; }
            $wpc_sheets73 = function_exists('wpc_used_css_load_sheets') ? wpc_used_css_load_sheets($wpc_tk112) : [];
            $wpc_smap73 = [];
            foreach ($wpc_sheets73 as $wpc_sh73) {


                // disposition: 'keep' = leave serving, 'absorb' = defer/drop; legacy 'skip' honored when absent.
                $wpc_disp73 = isset($wpc_sh73['disposition']) ? strtolower(trim((string) $wpc_sh73['disposition'])) : '';
                if ($wpc_disp73 === 'keep' || ($wpc_disp73 === '' && !empty($wpc_sh73['skip']))) {
                    continue;
                }
                if (!empty($wpc_sh73['url'])) {
                    $wpc_bn73 = strtok(basename((string) parse_url((string) $wpc_sh73['url'], PHP_URL_PATH)), '?');
                    if ($wpc_bn73 !== '' && $wpc_bn73 !== false) {
                        // Listed = absorbed: used.css carries its rules and the pair loads
                        // eagerly — the original never loads.
                        $wpc_smap73[strtolower($wpc_bn73)] = 0;
                    }
                }
            }
            // Self-heal: the sheets manifest is a STATIC artifact whose URL we stored at the
            // last good land — a missing local copy re-fetches without any service compute.
            if (empty($wpc_smap73) && function_exists('wpc_used_css_sheets_path')
                && ((function_exists('wp_doing_ajax') && wp_doing_ajax())
                    || (defined('DOING_CRON') && DOING_CRON)
                    || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']))) {
                $wpc_su232 = trim((string) @file_get_contents($wpc_cd112 . 'used_css_sheets_url.txt'));
                if ($wpc_su232 === '' || strpos($wpc_su232, 'http') !== 0) {
                    // Failed lands can empty the pointer file — the manifest sits beside the
                    // used.css artifact whose URL we also store; derive its sibling name.
                    foreach (['used_css_desktop_url.txt', 'used_css_mobile_url.txt'] as $wpc_uf233) {
                        $wpc_uu233 = trim((string) @file_get_contents($wpc_cd112 . $wpc_uf233));
                        if ($wpc_uu233 !== '' && strpos($wpc_uu233, 'http') === 0
                            && preg_match('/^(.*tpl-[a-f0-9]{8,24})\.(?:desktop|mobile)\.css/i', $wpc_uu233, $wpc_um233)) {
                            $wpc_su232 = $wpc_um233[1] . '.sheets.json';
                            break;
                        }
                    }
                }
                if ($wpc_su232 !== '' && strpos($wpc_su232, 'http') === 0
                    && !get_transient('wpc_sheets_heal_' . md5($wpc_tk112))) {
                    set_transient('wpc_sheets_heal_' . md5($wpc_tk112), 1, 600);
                    $wpc_sr232 = wp_remote_get($wpc_su232, ['timeout' => 6]);
                    $wpc_sb232 = !is_wp_error($wpc_sr232) && (int) wp_remote_retrieve_response_code($wpc_sr232) === 200
                        ? (string) wp_remote_retrieve_body($wpc_sr232) : '';
                    $wpc_sj232 = json_decode($wpc_sb232, true);
                    if (is_array($wpc_sj232) && isset($wpc_sj232[0]['url'])) {
                        @file_put_contents(wpc_used_css_sheets_path($wpc_tk112), $wpc_sb232, LOCK_EX);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('sheets-manifest-healed', (string) $this->urlKey, '', ['n' => count($wpc_sj232)]);
                        }
                        foreach ($wpc_sj232 as $wpc_sh232) {
                            if (!is_array($wpc_sh232) || !empty($wpc_sh232['skip'])) {
                                continue;
                            }
                            $wpc_d232 = isset($wpc_sh232['disposition']) ? strtolower(trim((string) $wpc_sh232['disposition'])) : '';
                            if ($wpc_d232 === 'keep') {
                                continue;
                            }
                            if (!empty($wpc_sh232['url'])) {
                                $wpc_bn232 = strtok(basename((string) parse_url((string) $wpc_sh232['url'], PHP_URL_PATH)), '?');
                                if ($wpc_bn232 !== '' && $wpc_bn232 !== false) {
                                    $wpc_smap73[strtolower($wpc_bn232)] = 0;
                                }
                            }
                        }
                    }
                }
            }
            $wpc_have_list73 = !empty($wpc_smap73) && apply_filters('wpc_used_css_droplist', true);

            // Unlisted sheets demote only against crit regenerated AFTER the last stale mark.
            $wpc_stale112 = 0;
            foreach (['upgrade', 'all'] as $wpc_sk112) {
                $wpc_sv112 = (int) get_transient('wpc_crit_stale_' . md5($wpc_sk112));
                if ($wpc_sv112 > $wpc_stale112) {
                    $wpc_stale112 = $wpc_sv112;
                }
            }
            $wpc_critfresh112 = !$wpc_stale112 || ((int) @filemtime($wpc_crit112['desktop']) > $wpc_stale112);


            if ($wpc_have_list73) {


                if (microtime(true) > $GLOBALS['wpc_dlbudget543']) { return $html; }
                $wpc_out50 = preg_replace_callback('/<link\b[^>]*rel=["\']preload["\'][^>]*>/i', function ($m) use ($wpc_smap73, $wpc_critfresh112) {
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
                    if ($wpc_pbn !== '' && !isset($wpc_smap73[$wpc_pbn])) {
                        $wpc_psrc = self::wpc_used_css_source_basename($m[0], $hm[1]);
                        if ($wpc_psrc !== '' && isset($wpc_smap73[$wpc_psrc])) { $wpc_pbn = $wpc_psrc; }
                    }
                    if ($wpc_pbn === '' || !isset($wpc_smap73[$wpc_pbn])) {
                        // UNLISTED — local sheets still demote; remote/3p keep the early tick.
                        if ($wpc_critfresh112 && !empty($wpc_smap73) && strpos($hm[1], 'wp-content/') !== false && apply_filters('wpc_unlisted_css_late', true)) {
                            $wpc_pt = preg_replace('/(rel)=(["\'])preload\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                            $wpc_pt = preg_replace('/\s+onload=("[^"]*"|\'[^\']*\')/i', '', $wpc_pt);
                            return preg_replace('/\s+as=(["\'])style\1/i', '', $wpc_pt);
                        }
                        return $m[0];
                    }
                    if ($wpc_smap73[$wpc_pbn] === 0) {
                        return ''; // DEAD — covered and nothing kept; drop the download entirely
                    }
                    // REPLACED — demote to the late safety net; strip the self-activating bits
                    $wpc_pt = preg_replace('/(rel)=(["\'])preload\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                    $wpc_pt = preg_replace('/\s+onload=("[^"]*"|\'[^\']*\')/i', '', $wpc_pt);
                    $wpc_pt = preg_replace('/\s+as=(["\'])style\1/i', '', $wpc_pt);
                    return $wpc_pt;
                }, $html);
                $html = is_string($wpc_out50) ? $wpc_out50 : $html;
            }
            // v7.10.402: also re-examine wpc-late-stylesheet links. A sheet an earlier pass
            // demoted to late (e.g. divi-style) escaped this drop entirely, so an ABSORBED
            // sheet (manifest value 0 = used-css carries its rules) kept loading late and
            // its reset overrode the used-css (the lost pill border). Matching 'late-' lets
            // the droplist drop it — ONLY when the manifest marks it absorbed; unlisted/kept
            // late sheets fall through the demote branches unchanged (already late = no-op).
            $wpc_out50 = preg_replace_callback('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:mobile-|late-)?stylesheet["\'][^>]*>/i', function ($m) use ($wpc_smap73, $wpc_have_list73, $wpc_critfresh112) {
                if (stripos($m[0], 'fonts.googleapis') !== false || stripos($m[0], 'fonts.bunny') !== false) {
                    return $m[0];
                }
                if (stripos($m[0], 'wpc-used-css') !== false) {
                    return $m[0];
                }
                if ($wpc_have_list73) {
                    if (preg_match('/href=["\']([^"\']+)["\']/i', $m[0], $hm)) {
                        $wpc_lbn73 = strtolower((string) strtok(basename((string) parse_url($hm[1], PHP_URL_PATH)), '?'));
                        if (!isset($wpc_smap73[$wpc_lbn73])) {
                            $wpc_lsrc73 = self::wpc_used_css_source_basename($m[0], $hm[1]);
                            if ($wpc_lsrc73 !== '' && isset($wpc_smap73[$wpc_lsrc73])) { $wpc_lbn73 = $wpc_lsrc73; }
                        }
                        if (!isset($wpc_smap73[$wpc_lbn73])) {
                            // UNLISTED — local sheets still demote; remote/3p keep the early tick.
                            if ($wpc_critfresh112 && !empty($wpc_smap73) && strpos($hm[1], 'wp-content/') !== false && apply_filters('wpc_unlisted_css_late', true)) {
                                return preg_replace('/(rel|type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                            }
                            return $m[0];
                        }
                        if ($wpc_smap73[$wpc_lbn73] === 0) {
                            return ''; // DEAD — drop entirely (kills the sheet + its font downloads)
                        }
                    } else {
                        return $m[0];
                    }
                }
                // REPLACED (out>0) demotes; with NO manifest sheets keep the early tick —
                // a blanket late-flip applies dozens of sheets in one style recalc.
                if (!$wpc_have_list73) {
                    return $m[0];
                }

                return preg_replace('/(rel|type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
            }, $html);
            $html = is_string($wpc_out50) ? $wpc_out50 : $html;


            if ($wpc_have_list73) {
                $wpc_out50 = preg_replace_callback('/<style\b[^>]*\btype=["\']wpc-(?:mobile-)?stylesheet["\'][^>]*>.*?<\/style>/is', function ($m) use ($wpc_smap73) {
                    if (!preg_match('/\bid=["\']([^"\']+)["\']/i', $m[0], $im)) {
                        return $m[0];
                    }
                    $wpc_sid114 = strtolower(trim((string) $im[1]));
                    // wp-emoji sizing and the theme.json variable set are never droppable or late.
                    if (strpos($wpc_sid114, 'wp-emoji') !== false || strpos($wpc_sid114, 'global-styles') !== false || self::wpc_consent_family($wpc_sid114)) {
                        return $m[0];
                    }
                    if ($wpc_sid114 === '' || !isset($wpc_smap73[$wpc_sid114])) {
                        return $m[0]; // UNLISTED — leave on the normal tick
                    }
                    if ($wpc_smap73[$wpc_sid114] === 0) {
                        return ''; // DEAD — remove the block entirely
                    }
                    return preg_replace('/(type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0], 1);
                }, $html);
                $html = is_string($wpc_out50) ? $wpc_out50 : $html;
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function lazyCSS($html)
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
            static $wpc_ica597 = false;
            if (!$wpc_ica597 && function_exists('wpc_cache_first_log')) {
                $wpc_ica597 = true;
                $wpc_icn597 = (int) preg_match_all('/content\s*:\s*(["\'])\\\\[ef][0-9a-f]{3}\1/i', $html);
                if ($wpc_icn597 > 0
                    && preg_match('/<link\b[^>]*(?:fontawesome|font-awesome|eicons|dashicons)[^>]*>/i', $html, $wpc_ilm597)
                    && preg_match('/(?:rel|type)\s*=\s*["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $wpc_ilm597[0])) {
                    wpc_cache_first_log('crit-icon-content-deferred-face', '', '', [
                        'icon_content_rules' => $wpc_icn597,
                        'deferred_sheet' => substr(preg_replace('/\s+/', ' ', $wpc_ilm597[0]), 0, 120),
                    ]);
                }
            }
        }
        $wpc_out50 = preg_replace_callback('/<link(.*?)>/si', [__CLASS__, 'cssLinkLazy'], $html);
        $html = is_string($wpc_out50) ? $wpc_out50 : $html;
        $wpc_out50 = preg_replace_callback('/(?<!<defs>)<style\b(.*?)<\/style>/si', [__CLASS__, 'cssStyleLazy'], $html);
        $html = is_string($wpc_out50) ? $wpc_out50 : $html;


        $html = $this->wpc_used_css_droplist_pass($html);


        try {
            $wpc_defer_hrefs95 = [];
            if (preg_match_all('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:mobile-|late-)?stylesheet["\'][^>]*>/i', $html, $wpc_dl95)) {
                foreach ($wpc_dl95[0] as $wpc_dt95) {
                    if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $wpc_dt95, $wpc_dh95)) {
                        $wpc_defer_hrefs95[strtolower((string) preg_replace('/[?#].*$/', '', $wpc_dh95[1]))] = 1;
                    }
                }
            }
            if (!empty($wpc_defer_hrefs95)) {
                $wpc_out50 = preg_replace_callback('/<link\b[^>]*\brel=["\']preload["\'][^>]*>/i', function ($m) use ($wpc_defer_hrefs95) {
                    if (stripos($m[0], 'as="style"') === false && stripos($m[0], "as='style'") === false) { return $m[0]; }
                    if (stripos($m[0], 'onload') !== false) { return $m[0]; }
                    if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $m[0], $pm)) { return $m[0]; }
                    $wpc_ph95 = strtolower((string) preg_replace('/[?#].*$/', '', $pm[1]));
                    return isset($wpc_defer_hrefs95[$wpc_ph95]) ? '' : $m[0];
                }, $html);
                $html = is_string($wpc_out50) ? $wpc_out50 : $html;
            }
        } catch (\Throwable $e) {

        }


        $html = $this->wpc_atf_sizes_pass($html);

        return $html;
    }


    public function wpc_atf_sizes_pass($html)
    {
        try {
            if (!apply_filters('wpc_nd_measured_sizes', true)) {
                return $html;
            }
            static $wpc_atf_entries = null, $wpc_ov_entries = null, $wpc_atf_loaded = false;
            if (!$wpc_atf_loaded) {
                $wpc_atf_loaded = true;


                {
                    $wpc_lcp_f = self::wpc_lcp_json_file();
                    if (@is_readable($wpc_lcp_f)) {
                        $wpc_lcp_j = json_decode((string) @file_get_contents($wpc_lcp_f), true);
                        if (is_array($wpc_lcp_j) && !empty($wpc_lcp_j['atf_images']) && is_array($wpc_lcp_j['atf_images'])) {
                            $wpc_atf_entries = $wpc_lcp_j['atf_images'];
                        }


                        if (is_array($wpc_lcp_j) && !empty($wpc_lcp_j['oversized_images']) && is_array($wpc_lcp_j['oversized_images'])) {
                            $wpc_ov_entries = $wpc_lcp_j['oversized_images'];
                        }
                    }
                }
            }
            if (empty($wpc_atf_entries) && empty($wpc_ov_entries)) {
                return $html;
            }
            $wpc_dev  = $this->isMobile() ? 'mobile' : 'desktop';
            $wpc_list = (isset($wpc_atf_entries[$wpc_dev]) && is_array($wpc_atf_entries[$wpc_dev])) ? $wpc_atf_entries[$wpc_dev] : [];
            if (is_array($wpc_ov_entries) && isset($wpc_ov_entries[$wpc_dev]) && is_array($wpc_ov_entries[$wpc_dev])) {
                $wpc_list = array_merge($wpc_list, array_slice($wpc_ov_entries[$wpc_dev], 0, 12));
            }


            if (self::wpc_combined_crit_on()) {
                $wpc_map57 = [];
                foreach (['mobile', 'desktop'] as $wpc_mdev57) {
                    $wpc_dl57 = (isset($wpc_atf_entries[$wpc_mdev57]) && is_array($wpc_atf_entries[$wpc_mdev57])) ? $wpc_atf_entries[$wpc_mdev57] : [];
                    if (is_array($wpc_ov_entries) && isset($wpc_ov_entries[$wpc_mdev57]) && is_array($wpc_ov_entries[$wpc_mdev57])) {
                        $wpc_dl57 = array_merge($wpc_dl57, array_slice($wpc_ov_entries[$wpc_mdev57], 0, 12));
                    }
                    foreach ($wpc_dl57 as $wpc_de57) {
                        if (!is_array($wpc_de57) || empty($wpc_de57['stem']) || !is_string($wpc_de57['stem']) || empty($wpc_de57['css_w'])) { continue; }
                        $wpc_k57 = strtolower($wpc_de57['stem']);
                        if (!isset($wpc_map57[$wpc_k57])) {
                            $wpc_map57[$wpc_k57] = ['stem' => $wpc_de57['stem'], 'css_w' => (int) $wpc_de57['css_w'], 'css_w_m' => 0, 'css_w_d' => 0];
                        }
                        $wpc_wk57 = ($wpc_mdev57 === 'mobile') ? 'css_w_m' : 'css_w_d';
                        if (empty($wpc_map57[$wpc_k57][$wpc_wk57])) {
                            $wpc_map57[$wpc_k57][$wpc_wk57] = (int) $wpc_de57['css_w'];
                            $wpc_map57[$wpc_k57]['css_w'] = max((int) $wpc_map57[$wpc_k57]['css_w'], (int) $wpc_de57['css_w']);
                        }
                    }
                }
                $wpc_list = array_values($wpc_map57);
            }
            $wpc_done = 0;
            $wpc_seen_stems = [];
            foreach ($wpc_list as $wpc_e) {
                if ($wpc_done >= 20) { // 8 ATF + up to 12 oversized
                    break;
                }
                if (!is_array($wpc_e) || empty($wpc_e['stem']) || !is_string($wpc_e['stem']) || empty($wpc_e['css_w'])) {
                    continue;
                }
                $wpc_w = (int) $wpc_e['css_w'];
                if ($wpc_w < 24 || $wpc_w > 2000 || strlen($wpc_e['stem']) < 3
                    || !preg_match('/^[A-Za-z0-9._@-]+$/', $wpc_e['stem'])) {
                    continue;
                }


                if (isset($wpc_seen_stems[strtolower($wpc_e['stem'])])) {
                    continue;
                }
                $wpc_seen_stems[strtolower($wpc_e['stem'])] = 1;
                $wpc_stem_q = preg_quote($wpc_e['stem'], '#');
                $wpc_last_sz96 = null;


                $wpc_out50 = preg_replace_callback(
                    '#<img\b[^>]*(?:src|srcset)="[^"]*/' . $wpc_stem_q . '(?:-scaled)?(?:-\d+x\d+)?\.(?:png|jpe?g|webp|avif|gif)[^"]*"[^>]*>#i',
                    function ($wpc_m) use ($wpc_w, $wpc_e, &$wpc_done, &$wpc_last_sz96) {
                        $wpc_tag = $wpc_m[0];
                        if (strpos($wpc_tag, 'srcset=') === false) {
                            return $wpc_tag;
                        }
                        $wpc_done++;


                        $wpc_wm57 = isset($wpc_e['css_w_m']) ? (int) $wpc_e['css_w_m'] : 0;
                        $wpc_wd57 = isset($wpc_e['css_w_d']) ? (int) $wpc_e['css_w_d'] : 0;
                        if ($wpc_wm57 >= 24 && $wpc_wd57 >= 24 && $wpc_wd57 * 4 < $wpc_wm57 * 3) {
                            $wpc_wd57 = $wpc_wm57;
                        }


                        $wpc_attr_w93 = preg_match('/\swidth\s*=\s*["\']?(\d{2,4})/', $wpc_tag, $wpc_aw93) ? (int) $wpc_aw93[1] : 0;
                        $wpc_fb93 = ($wpc_attr_w93 >= 100 && $wpc_attr_w93 <= 4000) ? ($wpc_attr_w93 . 'px') : '100vw';
                        if ($wpc_wm57 >= 24 && $wpc_wd57 >= 24) {
                            $wpc_sz = ($wpc_wm57 !== $wpc_wd57)
                                ? 'sizes="(max-width: 767.98px) ' . $wpc_wm57 . 'px, ' . $wpc_wd57 . 'px"'
                                : 'sizes="' . $wpc_wd57 . 'px"';
                        } elseif ($wpc_wm57 >= 24) {
                            $wpc_sz = 'sizes="(max-width: 767.98px) ' . $wpc_wm57 . 'px, ' . $wpc_fb93 . '"';
                        } elseif ($wpc_wd57 >= 24) {
                            $wpc_sz = 'sizes="(max-width: 767.98px) 100vw, ' . $wpc_wd57 . 'px"';
                        } else {

                            $wpc_sz = 'sizes="(max-width: 767.98px) ' . $wpc_w . 'px, ' . $wpc_fb93 . '"';
                        }
                        $wpc_last_sz96 = $wpc_sz;
                        if (preg_match('/\bsizes\s*=\s*"[^"]*"/i', $wpc_tag)) {
                            return preg_replace('/\bsizes\s*=\s*"[^"]*"/i', $wpc_sz, $wpc_tag, 1);
                        }
                        return preg_replace('/<img\b/i', '<img ' . $wpc_sz . ' ', $wpc_tag, 1);
                    },
                    $html, 1);
                if (is_string($wpc_out50)) {
                    $html = $wpc_out50;
                }


                if ($wpc_last_sz96 !== null) {
                    $wpc_done_src96 = 0;
                    $wpc_out50 = preg_replace_callback(
                        '#<source\b[^>]*srcset="[^"]*/' . $wpc_stem_q . '(?:-scaled)?(?:-\d+x\d+)?\.(?:png|jpe?g|webp|avif|gif)[^"]*"[^>]*>#i',
                        function ($wpc_sm) use ($wpc_last_sz96, &$wpc_done_src96) {
                            if ($wpc_done_src96 >= 4) { return $wpc_sm[0]; }
                            $wpc_done_src96++;
                            if (preg_match('/\bsizes\s*=\s*"[^"]*"/i', $wpc_sm[0])) {
                                return preg_replace('/\bsizes\s*=\s*"[^"]*"/i', $wpc_last_sz96, $wpc_sm[0], 1);
                            }
                            return preg_replace('/<source\b/i', '<source ' . $wpc_last_sz96 . ' ', $wpc_sm[0], 1);
                        },
                        $html);
                    if (is_string($wpc_out50)) {
                        $html = $wpc_out50;
                    }
                }
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_own_guard_style_id800($fullTag)
    {
        if (!is_string($fullTag) || $fullTag === '') {
            return '';
        }
        if (!preg_match('/<style\b[^>]*?\sid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $fullTag, $m)) {
            return '';
        }
        $wpc_id800 = '';
        for ($i = 1; $i <= 3; $i++) {
            if (isset($m[$i]) && $m[$i] !== '') {
                $wpc_id800 = trim($m[$i]);
                break;
            }
        }
        return preg_match('/^wpc-[A-Za-z0-9_-]+$/i', $wpc_id800) === 1 ? $wpc_id800 : '';
    }

    public static function wpc_own_guard_style800($fullTag)
    {
        $wpc_id800 = self::wpc_own_guard_style_id800($fullTag);
        if ($wpc_id800 === '') {
            return false;
        }
        return (bool) apply_filters('wpc_own_guard_style_live', true, $wpc_id800);
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
            // matching falls through to sans-serif. Today the fonts pass runs AFTER this one
            // (cdn-rewrite: lazyCSS -> replaceFrontend, both in buffer_local_callback), so the
            // type-swap never sees them — but that is statement order in another file, not an
            // invariant, and a second buffer pass over the same HTML would undo .589 silently.
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
            || strpos($fullTag, 'wpc-vars-guard') !== false

            || self::wpc_own_guard_style800($fullTag)

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

        if (strpos($fullTag, 'preload') !== false || strpos($fullTag, 'prefetch') !== false) {
            return $fullTag;
        }

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();

        if (empty($criticalCSSExists)) {
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
                    && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $fullTag, $wpc_jh132)) {
                    $wpc_jk132 = 'wpc_exj_' . md5($wpc_jh132[1]);
                    if (!get_transient($wpc_jk132)) {
                        set_transient($wpc_jk132, 1, DAY_IN_SECONDS);
                        wpc_auto_journal('css-exclude-suppressed', ['href' => substr((string) preg_replace('/\?.*$/', '', $wpc_jh132[1]), -120)]);
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
                $wpc_gf_id151 = preg_match('/\bid=(["\'])([^"\']+)\1/i', $fullTag, $wpc_gi151) ? ' id="' . esc_attr($wpc_gi151[2]) . '"' : '';
                $wpc_gf_md151 = preg_match('/\bmedia=(["\'])([^"\']+)\1/i', $fullTag, $wpc_gm151b) ? ' media="' . esc_attr($wpc_gm151b[2]) . '"' : '';
                $gfonts = '<link rel="wpc-mobile-stylesheet"' . $wpc_gf_id151 . $wpc_gf_md151 . ' href="' . esc_url($newHref) . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"/>';
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
            $wpc_fao3 = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            if (apply_filters('wpc_fa_optimize', is_array($wpc_fao3) && !empty($wpc_fao3['fontawesome-optimize']) && $wpc_fao3['fontawesome-optimize'] == '1')) {
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
                    static $wpc_pl898 = [];
                    if (!empty($href[2]) && count($wpc_pl898) < 20 && !isset($wpc_pl898[$href[2]])
                        && apply_filters('wpc_defer_css_preload', true)) {
                        $wpc_pl898[$href[2]] = 1;
                        $wpc_plco898 = preg_match('/\bcrossorigin(?:\s*=\s*(["\'])[^"\']*\1)?/i', $fullTag, $wpc_plcm898) ? ' ' . $wpc_plcm898[0] : '';
                        $wpc_plmd898 = preg_match('/\bmedia\s*=\s*(["\'])([^"\']+)\1/i', $fullTag, $wpc_plmm898) ? ' media="' . esc_attr($wpc_plmm898[2]) . '"' : '';
                        $fullTag .= '<link rel="preload" as="style" fetchpriority="low" href="' . esc_attr($href[2]) . '"' . $wpc_plco898 . $wpc_plmd898 . '>';
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
                                if (is_string($natural) && $natural !== '' && strpos($natural, '/m:') === false) {


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

    public function replaceImageTags($html)
    {
        if (function_exists('wpc_device_hidden_image_set')) {
            self::$deviceHiddenSet717 = wpc_device_hidden_image_set($html, (bool) $this->isMobile());
        }
        $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [__CLASS__, 'replaceImageTagsDo'], $html);
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


    public static function wpc_backfill_img_dimensions($tags)
    {
        try {
            if (!is_array($tags) || !apply_filters('wpc_backfill_img_dimensions', true)) {
                return $tags;
            }
            if (!empty($tags['width']) && !empty($tags['height'])) {
                return $tags;
            }
            $src = !empty($tags['src']) ? (string) $tags['src'] : '';
            if ($src === '') {
                return $tags;
            }

            // 1. wp-image-{ID} class → attachment metadata (no filesystem read).
            if (!empty($tags['class']) && preg_match('/\bwp-image-(\d+)\b/', (string) $tags['class'], $wpc_bfm)
                && function_exists('wp_get_attachment_metadata')) {
                $wpc_bfmeta = wp_get_attachment_metadata((int) $wpc_bfm[1]);
                if (is_array($wpc_bfmeta) && !empty($wpc_bfmeta['width']) && !empty($wpc_bfmeta['height'])) {
                    $tags['width']  = (string) (int) $wpc_bfmeta['width'];
                    $tags['height'] = (string) (int) $wpc_bfmeta['height'];
                    return $tags;
                }
            }

            // 2. Local-file fallback (hand-rolled theme markup, no wp-image- class).
            if (!defined('ABSPATH')) {
                return $tags;
            }
            $wpc_bfpath = (string) parse_url($src, PHP_URL_PATH);
            if ($wpc_bfpath === '' || strpos($wpc_bfpath, '..') !== false) {
                return $tags;
            }
            $wpc_bflocal = rtrim(ABSPATH, '/') . $wpc_bfpath;
            if (!@is_file($wpc_bflocal) || !@is_readable($wpc_bflocal)) {
                return $tags;
            }
            $wpc_bfsize = @getimagesize($wpc_bflocal);
            if (is_array($wpc_bfsize) && !empty($wpc_bfsize[0]) && !empty($wpc_bfsize[1])) {
                $tags['width']  = (string) (int) $wpc_bfsize[0];
                $tags['height'] = (string) (int) $wpc_bfsize[1];
            }
            return $tags;
        } catch (\Throwable $e) {
            return $tags;
        }
    }

    public function replaceImageTagsDo($image)
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

        if (strpos($image[0], 'data:image') !== false || strpos($image[0], 'blank') !== false || strpos($image[0], 'gform_ajax_spinner') !== false || strpos($image[0], 'spinner.svg') !== false) {
            return $image[0];
        }

        // v7.10.717 - an image the markup hides on THIS device must not consume an
        // eager-window slot, must not be promoted, and must end up lazy.
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
        if (!$wpcHidden717 && self::wpc_census_below_fold793($image[0])) {
            $wpcHidden717 = true;
        }

        if (!$wpcHidden717) {
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


        // Remove fetchpriority attribute
        $image[0] = preg_replace('/\bfetchpriority="[^"]*"\s*/si', '', $image[0]);
        // Remove decoding attribute
        $image[0] = preg_replace('/\bdecoding="[^"]*"\s*/si', '', $image[0]);

        if (!empty(self::$settings['remove-srcset']) && self::$settings['remove-srcset'] == '1') {
            $image[0] = preg_replace('/\bsrcset="[^"]*"\s*/si', '', $image[0]);
            $image[0] = preg_replace('/\bsizes="[^"]*"\s*/si', '', $image[0]);
        }


        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);


        $original_img_tag['original_tags'] = self::wpc_backfill_img_dimensions($original_img_tag['original_tags']);

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
            } else if (!$wpcHidden717 && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                // Don't lazy load LCP Fix !!
                // If we loaded less images than skip first variable
                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth('logo') . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wpc-excluded-adaptive wpc-lazy-skipped1';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else {
                if ($wpcHidden717 || self::$lazyLoadedImages > self::$lazyLoadedImagesLimit) {
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
        if (!$wpcHidden717 && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
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
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $fallbackWidth . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['original_tags']['srcset'] = self::buildLcpSrcset($image_source, !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0);


                    $imgWidth = !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0;


                    $lcp_is_lazy = (bool) apply_filters('wpc_lcp_lazy', false, $image_source);
                    $auto_prefix = $lcp_is_lazy ? 'auto, ' : '';


                    $cls_lcp_rl = !empty($original_img_tag['original_tags']['class']) ? (string) $original_img_tag['original_tags']['class'] : '';
                    if (preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $cls_lcp_rl)) {
                        $new_sizes = '';


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


                    $imgWidth_l = !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0;
                    $auto_l     = ((bool) apply_filters('wpc_lcp_lazy', false, $image_source)) ? 'auto, ' : '';

                    $cls_lcp_l = !empty($original_img_tag['original_tags']['class']) ? (string) $original_img_tag['original_tags']['class'] : '';
                    if (preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $cls_lcp_l)) {
                        $new_sizes_l = '';

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
        if ($wpcHidden717) {
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
        if (!$wpcHidden717 && function_exists('wpc_svg_inline_data718') && empty($original_img_tag['data-src'])) {
            $wpc_ld718 = !empty($original_img_tag['additional_tags']['loading'])
                ? $original_img_tag['additional_tags']['loading']
                : (!empty($original_img_tag['original_tags']['loading']) ? $original_img_tag['original_tags']['loading'] : '');
            $wpc_src718 = !empty($original_img_tag['original_tags']['src'])
                ? (string) $original_img_tag['original_tags']['src']
                : (!empty($original_img_tag['src']) ? (string) $original_img_tag['src'] : '');
            if ($wpc_ld718 !== 'lazy' && $wpc_src718 !== '' && strpos($wpc_src718, 'data:') !== 0) {
                $wpc_di718 = wpc_svg_inline_data718($wpc_src718);
                if ($wpc_di718 !== '') {
                    $original_img_tag['src'] = $wpc_di718;
                    $original_img_tag['original_tags']['src'] = $wpc_di718;
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


            if ((self::$pictureWebpEnabled || self::$pictureAvifEnabled)
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
            && preg_match('/\bwidth="(\d+)"/', $build_image_tag, $wpc_pw201)
            && preg_match('/\bheight="(\d+)"/', $build_image_tag, $wpc_ph201)
            && apply_filters('wpc_img_placeholder_reserve', true)) {
            $wpc_pl201 = 'data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20width=%27'
                . (int) $wpc_pw201[1] . '%27%20height=%27' . (int) $wpc_ph201[1] . '%27/%3E';
            if (strpos($build_image_tag, 'src=""') !== false) {
                $build_image_tag = str_replace('src=""', 'src="' . $wpc_pl201 . '"', $build_image_tag);
            } else {
                $build_image_tag .= 'src="' . $wpc_pl201 . '" ';
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
        if (self::$pictureWebpEnabled && !$wpc_lazy_blocks_wrap && (strpos($build_image_tag, '/wp:1/') !== false || $wpc_natural_img_src)) {
            $lowerSrc = strtolower($image_source);
            $skipFormats = (strpos($lowerSrc, '.svg') !== false
                         || strpos($lowerSrc, '.gif') !== false
                         || strpos($lowerSrc, '.ico') !== false);


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
                                $wpc_se15 = strtolower((string) pathinfo($cleanWebpSingle, PATHINFO_EXTENSION));
                                $singleWebpSrc = 'https://' . self::$zoneName . str_replace($webpSiteHostS, '', $natWebpSingle)
                                    . ($wpc_se15 !== '' && $wpc_se15 !== 'webp' ? self::src_hint_qs($wpc_se15) : '');
                            }
                        }
                        $sourceSrcset = ' ' . $srcAttrName . '="' . $singleWebpSrc . '"';
                    }
                }


                // BEFORE the extraction below, so the <source>s (whose OWN sizes governs picture


                $wpc_cinv352 = false;
                if (!empty($image_source)) {
                    $wpc_msz131 = self::wpc_census_slot_sizes($image_source, $build_image_tag);
                    if ($wpc_msz131 !== '') {
                        $wpc_cinv352 = true; // census-injected = INVENTED sizes, never auto-prefixed
                        $wpc_mszattr131 = 'sizes="' . $wpc_msz131 . '"';
                        foreach (['build_image_tag', 'fallbackTag'] as $wpc_tv131) {
                            $wpc_new131 = preg_match('/\bsizes\s*=\s*"[^"]*"/i', $$wpc_tv131)
                                ? preg_replace('/\bsizes\s*=\s*"[^"]*"/i', $wpc_mszattr131, $$wpc_tv131, 1)
                                : preg_replace('/<img\b/i', '<img ' . $wpc_mszattr131 . ' ', $$wpc_tv131, 1);
                            if (is_string($wpc_new131)) { $$wpc_tv131 = $wpc_new131; }
                        }
                    }
                }


                $sourceSizes = '';
                if (preg_match('/sizes="([^"]*)"/', $build_image_tag, $sizesMatch)) {
                    $sizes_value = $sizesMatch[1];


                    $is_eager_lcp = (stripos($build_image_tag, 'wpc-lcp-optimized') !== false)
                        && (stripos($build_image_tag, 'loading="lazy"') === false);
                    $auto_enabled = (bool) apply_filters('wpc_v2_sizes_auto_prefix', true);
                    if ($auto_enabled && !$is_eager_lcp && !$wpc_cinv352 && stripos($sizes_value, 'auto') === false) {
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


                        $wpc_webp_ok129 = self::wpc_webp_otf_ready() || self::wpc_natural_nw()
                            || apply_filters('wpc_avif_from_webp', get_option('wpc_avif_from_webp') === '1');
                        if ($avif_tc_mime !== '') {


                            $avif_src_transcodable = in_array($avif_tc_mime, ['image/jpeg', 'image/jpg', 'image/png'], true)
                                || ($wpc_webp_ok129 && $avif_tc_mime === 'image/webp');
                        } else {
                            // No resolvable attachment mime → trust the source extension (jpg/png only).
                            $avif_src_transcodable = (bool) preg_match('/\.(jpe?g|png)$/i', $cleanSource)
                                || ($wpc_webp_ok129 && (bool) preg_match('/\.webp$/i', $cleanSource));
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
                                $wpc_true934 = self::wpc_true_aspect934($image_source);
                                if (is_array($wpc_true934)
                                    && (int) $wpc_true934['width'] * (int) $avif_aspect_meta['height']
                                       !== (int) $wpc_true934['height'] * (int) $avif_aspect_meta['width']) {
                                    $avif_aspect_meta = $wpc_true934;
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
                            $wpc_nw_have130 = [];
                            foreach (self::wpc_nw_widths($original_img_tag, $avif_src_w_cap) as $nw_w) {
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $nw_w . 'w';
                                $avif_queue_w($nw_w);
                                $wpc_nw_have130[(int) $nw_w] = 1;
                            }

                            // -WxH/wp:2 block below, so every census fix landed there (.125 rescue, .127


                            foreach (self::wpc_census_rung_targets($cleanSource) as $wpc_ct130) {
                                $wpc_ct130 = (int) $wpc_ct130;
                                if ($wpc_ct130 < 48 || isset($wpc_nw_have130[$wpc_ct130])) { continue; }
                                if ($avif_src_w_cap > 0 && $wpc_ct130 > $avif_src_w_cap) { continue; }
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $wpc_ct130, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $wpc_ct130 . 'w';
                                $avif_queue_w($wpc_ct130);
                                $wpc_nw_have130[$wpc_ct130] = 1;
                            }


                            if (!self::wpc_census_rung_targets($cleanSource)) {
                                foreach ((array) apply_filters('wpc_nw_default_rungs', [360, 480, 640, 750, 828, 1080]) as $wpc_dr133) {
                                    $wpc_dr133 = (int) $wpc_dr133;
                                    if ($wpc_dr133 < 48 || isset($wpc_nw_have130[$wpc_dr133])) { continue; }
                                    if ($avif_src_w_cap > 0 && $wpc_dr133 > $avif_src_w_cap) { continue; }
                                    $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $wpc_dr133, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $wpc_dr133 . 'w';
                                    $avif_queue_w($wpc_dr133);
                                    $wpc_nw_have130[$wpc_dr133] = 1;
                                }
                            }
                            if (empty($avif_nw_entries) && self::wpc_att_recorded18($cleanSource)) {

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


                            $wpc_census_gate126 = !empty(self::wpc_census_rung_targets($cleanSource));
                            if ($final_srcset_avif !== '' || $wpc_census_gate126) {
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
                                $wpc_census_syn95 = '';
                                foreach (self::wpc_census_rung_targets($cleanSource) as $wpc_t95) {
                                    if ($wpc_t95 < 48) { continue; }
                                    if ($avif_src_w_cap > 0 && $wpc_t95 > $avif_src_w_cap) { continue; }
                                    $wpc_close95 = false;
                                    foreach ($existing_widths_in_avif as $wpc_ew95 => $wpc_u95) {
                                        if ($wpc_ew95 >= $wpc_t95 && $wpc_ew95 <= (int) ($wpc_t95 * 1.1)) { $wpc_close95 = true; break; }
                                    }
                                    if (!$wpc_close95 && preg_match_all('/\s(\d+)w\s*(?:,|$)/', ' ' . $final_srcset_avif, $wpc_sw95)) {
                                        foreach ($wpc_sw95[1] as $wpc_swv95) {
                                            $wpc_swv95 = (int) $wpc_swv95;
                                            if ($wpc_swv95 >= $wpc_t95 && $wpc_swv95 <= (int) ($wpc_t95 * 1.1)) { $wpc_close95 = true; break; }
                                        }
                                    }
                                    if ($wpc_close95) { continue; }
                                    $wpc_census_syn95 .= ($wpc_census_syn95 === '' ? '' : ',') . 'wpc-census ' . $wpc_t95 . 'w';
                                }
                                $extra_seen_avif = [];
                                foreach (explode(',', ($wpc_census_syn95 !== '' ? $wpc_census_syn95 . ',' : '') . $final_srcset_avif) as $entry) {
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


                                        $u_src_extra125 = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                        $u_src_extra125_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_extra125);
                                        $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $extra_width . '/u:' . self::uForCdn($u_src_extra125_via_cdn) . ' ' . $extra_width . 'w';
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


                                foreach (self::wpc_census_rung_targets($cleanSource) as $wpc_ct125) {
                                    if ((int) $wpc_ct125 >= 48) { $ladder_uni[] = (int) $wpc_ct125; }
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
                    $wpc_nw_haveW130 = [];
                    foreach (self::wpc_nw_widths($original_img_tag, $webp_nw_cap) as $nw_w) {
                        $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $nw_w . 'w';
                        $wpc_nw_haveW130[(int) $nw_w] = 1;
                    }

                    foreach (self::wpc_census_rung_targets($cleanSource) as $wpc_ctW130) {
                        $wpc_ctW130 = (int) $wpc_ctW130;
                        if ($wpc_ctW130 < 48 || isset($wpc_nw_haveW130[$wpc_ctW130])) { continue; }
                        if ($webp_nw_cap > 0 && $wpc_ctW130 > $webp_nw_cap) { continue; }
                        $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $wpc_ctW130, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $wpc_ctW130 . 'w';
                        $wpc_nw_haveW130[$wpc_ctW130] = 1;
                    }

                    if (!self::wpc_census_rung_targets($cleanSource)) {
                        foreach ((array) apply_filters('wpc_nw_default_rungs', [360, 480, 640, 750, 828, 1080]) as $wpc_drW133) {
                            $wpc_drW133 = (int) $wpc_drW133;
                            if ($wpc_drW133 < 48 || isset($wpc_nw_haveW130[$wpc_drW133])) { continue; }
                            if ($webp_nw_cap > 0 && $wpc_drW133 > $webp_nw_cap) { continue; }
                            $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $wpc_drW133, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $wpc_drW133 . 'w';
                            $wpc_nw_haveW130[$wpc_drW133] = 1;
                        }
                    }
                    if (empty($webp_nw_entries) && self::wpc_att_recorded18($cleanSource)) {

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


                    $webpSrcsetAttr = self::picture_natural_fleet_enabled()
                        ? 'srcset'
                        : ((strpos($build_image_tag, 'data-srcset=') !== false) ? 'data-srcset' : 'srcset');


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
                    $wpc_census_gate126w = !empty(self::wpc_census_rung_targets($image_source));
                    if ($final_srcset_webp !== '' || $wpc_census_gate126w) {
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


                        $wpc_census_syn95w = '';
                        foreach (self::wpc_census_rung_targets($image_source) as $wpc_t95w) {
                            if ($wpc_t95w < 48) { continue; }
                            if ($avif_src_w_cap > 0 && $wpc_t95w > $avif_src_w_cap) { continue; }
                            $wpc_close95w = false;
                            foreach ($existing_widths_in_webp as $wpc_ew95w => $wpc_u95w) {
                                if ($wpc_ew95w >= $wpc_t95w && $wpc_ew95w <= (int) ($wpc_t95w * 1.1)) { $wpc_close95w = true; break; }
                            }
                            if (!$wpc_close95w && preg_match_all('/\s(\d+)w\s*(?:,|$)/', ' ' . $final_srcset_webp, $wpc_sw95w)) {
                                foreach ($wpc_sw95w[1] as $wpc_swv95w) {
                                    $wpc_swv95w = (int) $wpc_swv95w;
                                    if ($wpc_swv95w >= $wpc_t95w && $wpc_swv95w <= (int) ($wpc_t95w * 1.1)) { $wpc_close95w = true; break; }
                                }
                            }
                            if ($wpc_close95w) { continue; }
                            $wpc_census_syn95w .= ($wpc_census_syn95w === '' ? '' : ',') . 'wpc-census ' . $wpc_t95w . 'w';
                        }
                        $extra_seen_webp = [];
                        foreach (explode(',', ($wpc_census_syn95w !== '' ? $wpc_census_syn95w . ',' : '') . $final_srcset_webp) as $entry) {
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


                    $wpc_fb_src132 = (string) $image_source;
                    if ($wpc_fb_src132 !== '' && stripos($wpc_fb_src132, '/u:') !== false
                        && preg_match('#/u:(https?://[^"\'\s]+)$#i', $wpc_fb_src132, $wpc_um132)) {
                        $wpc_uh132 = strtolower((string) parse_url($wpc_um132[1], PHP_URL_HOST));
                        $wpc_sh132 = strtolower((string) parse_url(site_url(), PHP_URL_HOST));
                        if ($wpc_uh132 !== '' && ($wpc_uh132 === $wpc_sh132
                            || $wpc_uh132 === 'www.' . $wpc_sh132 || 'www.' . $wpc_uh132 === $wpc_sh132)) {
                            $wpc_fb_src132 = $wpc_um132[1];
                        }
                    }
                    if ($wpc_fb_src132 !== '' && stripos($wpc_fb_src132, '/u:') === false) {
                        $wpc_fb_clean = preg_replace('/\?.*$/', '', $wpc_fb_src132);
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
                        && preg_match('/="([^"]+)"\s*$/', $sourceSrcset, $wpc_nsrc132)
                        && strpos($wpc_nsrc132[1], ',') !== false) {
                        $fallbackTag = (string) preg_replace_callback(
                            '/\s(?:data-)?srcset\s*=\s*"[^"]*"/i',
                            function ($mm) use ($wpc_nsrc132) {
                                return (stripos($mm[0], 'data-') === 1)
                                    ? ' data-srcset="' . $wpc_nsrc132[1] . '"'
                                    : ' srcset="' . $wpc_nsrc132[1] . '"';
                            },
                            $fallbackTag, 1);
                    }


                    // Tradeoff (deliberate, user-decided): retina phones render the DPR-1 file.
                    // Kill: filter wpc_precise_slot_arm → false restores DPR-true rungs.
                    $wpc_msrc133 = '';
                    try {
                        if (apply_filters('wpc_precise_slot_arm', true) && !empty($image_source)) {
                            self::wpc_census_rung_targets($image_source);
                            $wpc_b133 = strtolower(basename((string) preg_replace('/[?#].*$/', '', (string) $image_source)));
                            $wpc_b133 = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif)$/i', '', $wpc_b133);
                            $wpc_b133 = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $wpc_b133), 1);
                            $wpc_m133 = ($wpc_b133 !== '' && isset(self::$wpc_census_slots[$wpc_b133]))
                                ? (int) self::$wpc_census_slots[$wpc_b133]['m'] : 0;
                            if ($wpc_m133 >= 24) {


                                $wpc_m2_133 = $wpc_m133 * 2;
                                foreach ([['tag' => $avifSource, 'type' => 'avif'], ['tag' => '<source' . $sourceSrcset . $sourceSizes . '>', 'type' => 'webp']] as $wpc_lane133) {
                                    if ($wpc_lane133['tag'] === '' || strpos($wpc_lane133['tag'], (string) $wpc_m133 . 'w') === false) { continue; }
                                    if (preg_match('#(?:srcset|data-srcset)="(?:[^"]*?,\s*)?([^"\s,]+)\s+' . $wpc_m133 . 'w#', $wpc_lane133['tag'], $wpc_mu133)
                                        && stripos($wpc_mu133[1], '/u:') === false) {
                                        $wpc_msrc133 .= '<source media="(max-width: 767.98px) and (max-resolution: 1.9dppx)" srcset="' . $wpc_mu133[1] . ' ' . $wpc_m133 . 'w"'
                                            . ' sizes="' . $wpc_m133 . 'px" type="image/' . $wpc_lane133['type'] . '">';

                                        if (preg_match('#(?:srcset|data-srcset)="(?:[^"]*?,\s*)?([^"\s,]+)\s+' . $wpc_m2_133 . 'w#', $wpc_lane133['tag'], $wpc_mu2_133)
                                            && stripos($wpc_mu2_133[1], '/u:') === false) {
                                            $wpc_msrc133 .= '<source media="(max-width: 767.98px) and (min-resolution: 1.91dppx)" srcset="' . $wpc_mu2_133[1] . ' ' . $wpc_m2_133 . 'w"'
                                                . ' sizes="' . $wpc_m133 . 'px" type="image/' . $wpc_lane133['type'] . '">';
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $wpc_msrc133 = '';
                    }

                    // v7.20.12 — A SINGLE 1x RUNG MAY ONLY SERVE 1x SCREENS. A source whose
                    // srcset offers exactly one sized candidate forces every DPR>=2 display to
                    // upscale it (maisonpro: 75w icon soft on retina). Guard it to
                    // max-resolution:1.9dppx so high-DPR falls through to the fallback <img>
                    // (the zone-proxied original — full bytes, crisp downscale). Multi-rung
                    // srcsets keep letting the browser pick by DPR, unchanged.
                    $wpc_g1x934 = function ($tag) {
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
                        . $wpc_msrc133
                        . $wpc_g1x934($avifSource)
                        . $wpc_g1x934('<source' . $sourceSrcset . $sourceSizes . ' type="image/webp">')
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
        } else {
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