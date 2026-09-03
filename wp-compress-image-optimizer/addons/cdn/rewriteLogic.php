<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cdn/rewriteLogic.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
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

    
    public static $fonts;
    public static $css;
    public static $cssMinify;
    public static $cssImgUrl;
    public static $js;
    public static $jsMinify;

    
    public static $perfMattersActive;
    public static $brizyActive;
    public static $brizyCache;
    public static $revSlider;

    
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
        if (!empty($custom_cname) && function_exists('wpc_cdn_cname_reachable117') && !wpc_cdn_cname_reachable117($custom_cname)) { $custom_cname = ''; }
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
        
        
        if (function_exists('wpc_ua_is_mobile')) {
            return wpc_ua_is_mobile();
        }

        
        
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
        
        
        
        
        
        
        
        
        $s = is_array($settings_override) ? $settings_override
            : (function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings') : []);
        $cc = (is_array($s) && isset($s['combined-crit'])) ? (string) $s['combined-crit'] : '';
        if ($cc === '1') { return true; }   
        if ($cc === '0') { return false; }  
        if (apply_filters('wpc_split_default_on', true)) { return false; } 
        $cf = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
        return is_array($cf) && !empty($cf['token']) && !empty($cf['zone'])
            && !(is_array($s) && !empty($s['minimal-mobile-css']) && $s['minimal-mobile-css'] == '1');
    }

    
    
    
    
    
    
    
    
    
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
                
                
                
                
                
                self::$wpc_scoped704 = true;
                return $css;
            }
            $s = strpos($css, '*/', $i);
            if ($s === false) {
                return $css;
            }
            $s += 2;
            $tail = substr($css, $s);
            
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

    
    
    
    
    
    
    
    
    
    public static function wpc_face_display_sweep21($css)
    {
        
        
        
        
        
        
        
        
        try {
            if (is_string($css) && $css !== ''
                && class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_host_twin_css42')) {
                $css = wps_cdn_rewrite::wpc_host_twin_css42($css);
            }
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
        
        
        
        if (method_exists(get_called_class(), 'wpc_css_requote_urls194')) {
            $payload = self::wpc_css_requote_urls194($payload);
        }
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
        
        
        
        if (trim($payload) === '') {
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_crit_empty22')) {
                set_transient('wpc_crit_empty22', 1, 600);
                wpc_cache_first_log('crit-tag-empty', '', '', ['attrs' => substr((string) $attrs, 0, 80)]);
            }
            return $carrier;
        }
        return $carrier . '<style type="text/css" ' . $attrs . '>' . $payload . '</style>';
    }

    
    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_unbacked_fams23($html, $output = '', $extra = [])
    {
        try {
            if (!apply_filters('wpc_unbacked_family_gate', true)) {
                return [];
            }
            $hay = (string) $html . (string) $output;
            
            
            
            
            
            
            
            $cands = isset($GLOBALS['wpc_fc_tp_fams22']) && is_array($GLOBALS['wpc_fc_tp_fams22']) ? $GLOBALS['wpc_fc_tp_fams22'] : [];
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
            $linked = self::wpc_linked_face_fams25($html);
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
                        if (stripos($u, 'data:') === 0 || !function_exists('wpc_font_carrier_host_ok22') || wpc_font_carrier_host_ok22($u)) {
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

    
    
    
    private static $wpc_lf25 = null;
    public static function wpc_linked_face_fams25($html)
    {
        if (self::$wpc_lf25 !== null) {
            return self::$wpc_lf25;
        }
        $fams = [];
        try {
            if (!defined('ABSPATH') || !is_string($html)
                || !preg_match_all('/<link\b[^>]*href=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1[^>]*>/i', $html, $lm)) {
                return self::$wpc_lf25 = $fams;
            }
            $n = 0;
            foreach ($lm[2] as $href) {
                if ($n++ >= 64) {
                    break;
                }
                $pu = parse_url(html_entity_decode($href));
                $h = isset($pu['host']) ? strtolower((string) $pu['host']) : '';
                if ($h !== '' && function_exists('wpc_font_carrier_host_ok22') && !wpc_font_carrier_host_ok22('https://' . $h . '/x.css')) {
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
        return self::$wpc_lf25 = $fams;
    }

    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_bgl255_arm_tag34()
    {
        return '<script id="wpc-bgl255-arm" data-nodefer="1">(function(){var d=document.documentElement,f=0,L=["pointerdown","keydown","touchstart","wheel","scroll","mousemove"],a=function(){if(f)return;f=1;try{d.classList.add("wpc-bgl255")}catch(x){}L.forEach(function(e){try{window.removeEventListener(e,a,!0)}catch(x){}})};'
            . 'window.addEventListener("click",function(e){try{if(window.wpcHamState365)return;var t=e.target&&e.target.closest?e.target.closest(".elementor-menu-toggle"):null;if(!t)return;if(d.hasAttribute("data-wpc-tap34")){d.removeAttribute("data-wpc-tap34");return}var i=Array.prototype.indexOf.call(document.querySelectorAll(".elementor-menu-toggle"),t);d.setAttribute("data-wpc-tap34",Math.round(performance.now())+":"+(i<0?0:i))}catch(x){}},{capture:!0,passive:!0});'
            . 'if((window.pageYOffset||d.scrollTop||0)>0){a();return}L.forEach(function(e){window.addEventListener(e,a,{passive:!0,capture:!0})});window.addEventListener("pageshow",function(e){e&&e.persisted&&a()},{once:!0})})();</script>';
    }

    public static function wpc_devmode_extract33($css)
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

    public static function wpc_devmode_sheet33($href)
    {
        try {
            if (!defined('ABSPATH')) {
                return '';
            }
            $pu = parse_url(html_entity_decode((string) $href));
            $h = isset($pu['host']) ? strtolower((string) $pu['host']) : '';
            if ($h !== '' && function_exists('wpc_font_carrier_host_ok22') && !wpc_font_carrier_host_ok22('https://' . $h . '/x.css')) {
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
                $c = self::wpc_devmode_extract33((string) @file_get_contents($fp));
                if (function_exists('set_transient')) {
                    set_transient($key, $c, 86400);
                }
            }
            return $c;
        } catch (\Throwable $e) {
            return '';
        }
    }

    
    
    
    
    
    
    
    
    
    
    public static function wpc_sheet_root_vars35($css)
    {
        $out = [];
        $css = (string) $css;
        if ($css === '' || strpos($css, '--') === false) {
            return $out;
        }
        
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

    public static function wpc_sheet_vars_cached35($href)
    {
        try {
            if (!defined('ABSPATH')) {
                return [];
            }
            $pu = parse_url(html_entity_decode((string) $href));
            $h = isset($pu['host']) ? strtolower((string) $pu['host']) : '';
            if ($h !== '' && function_exists('wpc_font_carrier_host_ok22') && !wpc_font_carrier_host_ok22('https://' . $h . '/x.css')) {
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
                $c = self::wpc_sheet_root_vars35((string) @file_get_contents($fp));
                if (function_exists('set_transient')) {
                    set_transient($key, $c, 86400);
                }
            }
            return $c;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function wpc_crit_vars_belt35($html)
    {
        try {
            if (!is_string($html) || $html === '' || !apply_filters('wpc_crit_vars', true)
                || !preg_match('/<style\b[^>]*id=(["\'])wpc-critical-css\1[^>]*>(.*?)<\/style>/is', $html, $cm)
                || trim($cm[2]) === '' || strpos($cm[2], 'var(--') === false) {
                return $html;
            }
            
            $inline = '';
            if (preg_match_all('/<style\b[^>]*id=(["\'])wpc-[^"\']*\1[^>]*>(.*?)<\/style>/is', $html, $sm)) {
                $inline = implode("\n", $sm[2]);
            }
            $used = [];
            if (preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $cm[2], $um)) {
                $used = array_values(array_unique($um[1]));
            }
            $defined = [];
            if (preg_match_all('/(--[A-Za-z0-9_-]+)\s*:/', $inline, $dm)) {
                $defined = array_flip($dm[1]);
            }
            $missing = [];
            foreach ($used as $name) {
                if (!isset($defined[$name])) {
                    $missing[$name] = 1;
                }
            }
            if (empty($missing) || !preg_match_all('/<link\b[^>]*>/i', $html, $lm)) {
                return $html;
            }
            $deferred = [];
            foreach ($lm[0] as $tag) {
                if (preg_match('/\bhref=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1/i', $tag, $hm)) {
                    if (!preg_match('/\brel=(["\'])stylesheet\1/i', $tag) || preg_match('/\bmedia=(["\'])print\1/i', $tag)) {
                        $deferred[] = $hm[2];
                    }
                } elseif (preg_match('/\bdata-wpc-rest=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1/i', $tag, $rm)) {
                    $deferred[] = $rm[2];
                }
            }
            if (empty($deferred)) {
                return $html;
            }
            $rules = '';
            $found = 0;
            $n = 0;
            foreach ($deferred as $href) {
                if ($n++ >= 64 || empty($missing)) {
                    break;
                }
                $vars = self::wpc_sheet_vars_cached35($href);
                foreach ($missing as $name => $one) {
                    if (empty($vars[$name])) {
                        continue;
                    }
                    foreach ($vars[$name] as $d) {
                        $decl = $d[1] . '{' . $name . ':' . $d[2] . '}';
                        $rules .= $d[0] !== '' ? '@media ' . $d[0] . '{' . $decl . '}' : $decl;
                    }
                    unset($missing[$name]);
                    $found++;
                }
            }
            if ($rules === '' || strlen($rules) > 8192) {
                return $html;
            }
            $tag = '<style id="wpc-crit-vars">' . $rules . '</style>';
            $out = preg_replace('/(<style\b[^>]*id=(["\'])wpc-critical-css\2[^>]*>.*?<\/style>)/is', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $tag), $html, 1);
            if (!is_string($out) || $out === $html) {
                return $html;
            }
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_cv35_log')) {
                set_transient('wpc_cv35_log', 1, 600);
                wpc_cache_first_log('crit-vars-injected', '', '', ['vars' => $found, 'bytes' => strlen($rules), 'still_missing' => count($missing)]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_devmode_belt33($html)
    {
        try {
            if (!is_string($html) || $html === '' || !apply_filters('wpc_crit_devmode', true)
                || !preg_match('/<style\b[^>]*id=(["\'])wpc-critical-css\1[^>]*>\s*(?!<\/style)\S/i', $html)
                || preg_match('/(?:#elementor-device-mode|body)\s*::?after\s*\{[^{}]*\bcontent\s*:/i', $html)
                || !preg_match_all('/<link\b[^>]*>/i', $html, $lm)) {
                return $html;
            }
            $blocking = [];
            $deferred = [];
            foreach ($lm[0] as $tag) {
                if (!preg_match('/\bhref=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1/i', $tag, $hm)) {
                    if (preg_match('/\bdata-wpc-rest=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1/i', $tag, $rm)) {
                        $deferred[] = $rm[2];
                    }
                    continue;
                }
                $isBlocking = preg_match('/\brel=(["\'])stylesheet\1/i', $tag)
                    && !preg_match('/\bmedia=(["\'])print\1/i', $tag);
                if ($isBlocking) {
                    $blocking[] = $hm[2];
                } else {
                    $deferred[] = $hm[2];
                }
            }
            if (empty($deferred)) {
                return $html;
            }
            $n = 0;
            foreach ($blocking as $href) {
                if ($n++ >= 64) {
                    break;
                }
                if (self::wpc_devmode_sheet33($href) !== '') {
                    return $html;
                }
            }
            $rules = '';
            foreach ($deferred as $href) {
                if ($n++ >= 96) {
                    break;
                }
                $rules = self::wpc_devmode_sheet33($href);
                if ($rules !== '') {
                    break;
                }
            }
            if ($rules === '') {
                return $html;
            }
            $tag = '<style id="wpc-crit-devmode">' . $rules . '</style>';
            $out = preg_replace('/(<style\b[^>]*id=(["\'])wpc-critical-css\2[^>]*>.*?<\/style>)/is', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $tag), $html, 1);
            if (!is_string($out) || $out === $html) {
                return $html;
            }
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_dm33_log')) {
                set_transient('wpc_dm33_log', 1, 600);
                wpc_cache_first_log('crit-devmode-injected', '', '', ['bytes' => strlen($rules)]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    
    
    
    
    
    public static function wpc_unbacked_sweep23($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, '@font-face') === false) {
                return $html;
            }
            
            
            
            $blocks = '/(<style\b[^>]*\bid=(["\'])(?:wpc-font-carrier|wpc-crit-faces|wpc-font-subsets|wpc-font-fallbacks|wpc-late-faces|wpc-icon-faces|wpc-fonts-css-faces)\2[^>]*>)(.*?)(<\/style>)/is';
            $gf = (bool) preg_match('/<link\b[^>]*href=["\'][^"\']*(?:fonts\.googleapis\.com|fonts\.bunny\.net)\/css[^"\']*["\']/i', $html);
            if (!$gf && function_exists('wpc_font_carrier_filter22')) {
                $r1 = preg_replace_callback($blocks, function ($m) {
                    return $m[1] . wpc_font_carrier_filter22($m[3]) . $m[4];
                }, $html);
                if (is_string($r1)) {
                    $html = $r1;
                }
            }
            $fams = self::wpc_unbacked_fams23($html, '');
            if (!$fams) {
                return $html;
            }
            $out = preg_replace_callback($blocks, function ($m) use ($fams) {
                return $m[1] . self::wpc_strip_family_faces23($m[3], $fams) . $m[4];
            }, $html);
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_strip_family_faces23($css, $fams)
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
        if ($n > 0 && function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_unbacked23')) {
            set_transient('wpc_unbacked23', 1, 600);
            wpc_cache_first_log('font-family-unbacked', '', '', ['fams' => implode(',', array_keys($fams)), 'faces' => $n]);
        }
        return $out;
    }

    
    
    
    
    
    
    
    
    public static function wpc_cmb_selectors26($css)
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

    public static function wpc_cmb_coverage26($cmb, $cmbFile, $exists)
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
            $sc = self::wpc_cmb_selectors26($cmb);
            $sd = self::wpc_cmb_selectors26((string) @file_get_contents($d));
            $sm = self::wpc_cmb_selectors26((string) @file_get_contents($mo));
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

    public static function wpc_no_crit_no_defer22($html)
    {
        try {
            if (!is_string($html) || $html === '' || !apply_filters('wpc_no_crit_no_defer', true)) {
                return $html;
            }
            if (preg_match('/<style\b[^>]*id=(["\'])wpc-critical-css\1[^>]*>\s*(?!<\/style)\S/i', $html)) {
                return $html;
            }
            if (stripos($html, 'wpc-late-stylesheet') === false && stripos($html, 'wpc-mobile-stylesheet') === false
                && stripos($html, 'data-wpc-rest=') === false) {
                return $html;
            }
            $n = 0;
            $out = preg_replace_callback('/<link\b[^>]*>/i', function ($m) use (&$n) {
                $t = $m[0];
                if (preg_match('/\b(rel|type)=(["\'])wpc-(?:late|mobile)-stylesheet\2/i', $t)) {
                    if (preg_match('/\brel=(["\'])stylesheet\1/i', $t)) {
                        $t = preg_replace('/\s*\btype=(["\'])wpc-(?:late|mobile)-stylesheet\1/i', '', $t, 1);
                    } else {
                        $t = preg_replace('/\b(?:rel|type)=(["\'])wpc-(?:late|mobile)-stylesheet\1/i', 'rel=$1stylesheet$1', $t, 1);
                    }
                    $n++;
                }
                if (stripos($t, 'data-wpc-rest=') !== false && !preg_match('/\bhref=/i', $t)
                    && preg_match('/\bdata-wpc-rest=(["\'])([^"\']+)\1/i', $t, $r)) {
                    $md = preg_match('/\bdata-wpc-ucss-rest=(["\'])([^"\']*)\1/i', $t, $mm) ? $mm[2] : 'all';
                    $t = preg_replace('/\bmedia=(["\'])[^"\']*\1/i', 'media="' . ($md === '' ? 'all' : $md) . '"', $t, 1);
                    $t = preg_replace('/^<link\b/i', '<link href="' . $r[2] . '"', $t, 1);
                    $n++;
                }
                return $t;
            }, $html);
            if (!is_string($out)) {
                return $html;
            }
            if ($n > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('no-crit-no-defer', '', (string) ($_SERVER['REQUEST_URI'] ?? ''), ['restored' => $n]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    
    
    
    
    
    public static function wpc_ucss_conceal_ms()
    {
        if (apply_filters('wpc_conceal_guard', true) === false) {
            return 0;
        }
        
        
        
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
        return '<script id="wpc-ucss-boot">/*wpc-arm-sentinel*/(function(){var g=function(){try{document.documentElement.classList.add("wpc-css-live")}catch(x){}},g2=function(){try{if(document.querySelector(\'link[rel^="wpc-"]\'))return;g()}catch(x){g()}};function a(){var r=document.querySelectorAll(\'link[data-wpc-rest]:not([href])\'),armed=0;for(var j=0;j<r.length;j++){(function(e){var ru=e.getAttribute("data-wpc-rest"),rm=e.getAttribute("data-wpc-ucss-rest")||"all",rg=true;try{rg=!window.matchMedia||window.matchMedia(rm).matches}catch(x){rg=true}if(!rg||!ru)return;armed++;e.media="print";e.onload=function(){this.onload=null;this.media=rm;g2()};e.onerror=g;e.setAttribute("href",ru)})(r[j])}if(!armed){g2()}}function q(){(window.requestIdleCallback||function(f){setTimeout(f,1200)})(a,{timeout:2500})}setTimeout(g,' . (int) $ms . ');if(document.readyState==="complete"){q()}else{window.addEventListener("load",q)}})();</script>';
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
            
            
            
            
            
            if (apply_filters('wpc_split_default_on', true)) {
                $on = false;
            } else {
                $cf = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
                $on = is_array($cf) && !empty($cf['token']) && !empty($cf['zone'])
                    && !(is_array($s) && !empty($s['minimal-mobile-css']) && $s['minimal-mobile-css'] == '1');
            }
        }
        
        
        
        
        
        
        
        
        
        
        
        
        
        if (!$on && apply_filters('wpc_combined_crit_devkey_floor', true)) {
            $cfx = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
            if (is_array($cfx) && !empty($cfx['token']) && !empty($cfx['zone'])) {
                $dk = function_exists('get_option') ? get_option('wpc_cf_devkey_verified') : false;
                
                
                
                
                
                if (!is_array($dk) || empty($dk['devkey']) || (isset($dk['src']) ? (string) $dk['src'] : '') !== 'readback') {
                    $on = true; 
                }
            }
        }
        
        
        
        
        
        
        if (!$on && apply_filters('wpc_combined_crit_cf_fronted_floor', true)) {
            if (function_exists('wpc_devblind_edge') && wpc_devblind_edge()) {
                $on = true;
            }
        }
        
        
        
        
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
            
            
            if (strpos($link, '.wp.com') !== false && preg_match('/\bi[0-9a-zA-Z]{1,3}\.wp\.com\b/', $link)) {
                return true;
            }
        }

        return false;
    }

    public static function isExcludedLink($link)
    {
        


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

        
        
        
        
        if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
            return self::wpc_cio_font_zone_swap291($url);
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


    public static function wpc_cio_font_zone_swap291($url)
    {
        $wpc_z291 = (string) self::$zoneName;
        if ($wpc_z291 === '' || strpos($wpc_z291, '/') !== false) {
            return $url;
        }
        if (!function_exists('home_url') || !function_exists('apply_filters')) {
            return $url;
        }
        $wpc_fh291 = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $wpc_sh291 = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($wpc_fh291 === '' || $wpc_sh291 === '' || $wpc_fh291 !== $wpc_sh291) {
            return $url;
        }
        if (!apply_filters('wpc_fonts_zone_serve', true)) {
            return $url;
        }
        return preg_replace('#^https?://[^/]+#', 'https://' . $wpc_z291, $url);
    }

    public static function wpc_cio_fonts_pass291($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '/cache/wp-cio-fonts/') === false) {
            return $html;
        }
        $wpc_out291 = preg_replace_callback('~https?://[^"\'\\)\s>]+/cache/wp-cio-fonts/[^"\'\\)\s>]+\.(?:woff2|woff|ttf)~i', function ($m) {
            return self::wpc_cio_font_zone_swap291($m[0]);
        }, $html);
        return is_string($wpc_out291) ? $wpc_out291 : $html;
    }

    










    public static function wpc_srcset_honesty298($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'srcset="') === false
            || !apply_filters('wpc_srcset_honesty', true)) {
            return $html;
        }
        $wpc_out298 = preg_replace_callback('/<(img|source)\b[^>]*\ssrcset="([^"]+)"[^>]*>/i', static function ($m) {
            $tag = (string) $m[0];
            $raw = (string) $m[2];
            if (strpos($raw, ',') === false || strpos($raw, ' ') === false) { return $tag; }
            $urls = [];
            $entries = [];
            foreach (explode(',', $raw) as $e) {
                $e = trim($e);
                if ($e === '' || !preg_match('/^(\S+)\s+(\d+)w$/', $e, $pm)) { return $tag; }
                $u = (string) preg_replace('/[?#].*$/', '', $pm[1]);
                $urls[$u] = 1;
                $entries[] = [$pm[1], (int) $pm[2]];
            }
            if (count($entries) < 2) { return $tag; }
            if (count($urls) === 1) {
                $t = (string) preg_replace('/\ssrcset="[^"]*"/i', '', $tag, 1);
                $t = (string) preg_replace('/\ssizes="[^"]*"/i', '', $t, 1);
                return $t !== '' ? $t : $tag;
            }
            $keep = [];
            foreach ($entries as $en) {
                $wpc_mu298 = $en[0];
                if (preg_match('#/u:(https?://.+)$#i', $wpc_mu298, $wpc_um298)) {
                    $wpc_mu298 = $wpc_um298[1];
                }
                $wpc_d298 = self::wpc_true_aspect934($wpc_mu298);
                $wpc_w298 = is_array($wpc_d298) ? (int) ($wpc_d298['width'] ?? $wpc_d298[0] ?? 0) : 0;
                if ($wpc_w298 < 1) { return $tag; }
                if ($wpc_w298 * 10 >= $en[1] * 9) { $keep[] = $en[0] . ' ' . $en[1] . 'w'; }
            }
            if (!count($keep) || count($keep) === count($entries)) {
                return count($keep) ? $tag : (string) preg_replace('/\ssizes="[^"]*"/i', '', (string) preg_replace('/\ssrcset="[^"]*"/i', '', $tag, 1), 1);
            }
            return str_replace('srcset="' . $raw . '"', 'srcset="' . implode(', ', $keep) . '"', $tag);
        }, $html);
        return is_string($wpc_out298) ? $wpc_out298 : $html;
    }

    







    public static function wpc_dims_belt305($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false
            || !apply_filters('wpc_dims_belt', true)) {
            return $html;
        }
        $wpc_out305 = preg_replace_callback('/<img\b[^>]*>/i', static function ($m) {
            $t = (string) $m[0];
            if (preg_match('/\swidth\s*=/i', $t) || preg_match('/\sheight\s*=/i', $t)) { return $t; }
            if (!preg_match('/\ssrc="(https?:[^"]+)"/i', $t, $sm) || strpos($sm[1], 'data:') === 0) { return $t; }
            $wpc_d305 = self::wpc_true_aspect934($sm[1]);
            $wpc_w305 = is_array($wpc_d305) ? (int) ($wpc_d305['width'] ?? $wpc_d305[0] ?? 0) : 0;
            $wpc_h305 = is_array($wpc_d305) ? (int) ($wpc_d305['height'] ?? $wpc_d305[1] ?? 0) : 0;
            if ($wpc_w305 < 1 || $wpc_h305 < 1) { return $t; }
            return substr($t, 0, 4) . ' width="' . $wpc_w305 . '" height="' . $wpc_h305 . '" data-wpc-bf="305"' . substr($t, 4);
        }, $html);
        return is_string($wpc_out305) ? $wpc_out305 : $html;
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

    
    private static $variantGlobCache = [];


    




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


    


    public static function src_hint_enabled()
    {


        return self::src_hint_mode() !== 'off';
    }


    public static function src_hint_mode()
    {
        $set  = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $raw  = (is_array($set) && isset($set['emit-src-hints'])) ? (string) $set['emit-src-hints'] : '1';
        $on   = ($raw !== '0' && $raw !== '' && strtolower($raw) !== 'off');
        
        
        
        
        $mode = !$on ? 'off' : ((is_array($set) && !empty($set['emit-src-hints-until'])) ? 'until' : 'always');
        if (function_exists('apply_filters')) {
            $mode = (string) apply_filters('wpc_src_hint_mode', $mode);
            if (apply_filters('wpc_src_hint_enabled', true) === false) {
                $mode = 'off';
            }
        }
        return in_array($mode, array('off', 'until', 'always'), true) ? $mode : 'until';
    }


    












    
    
    
    
    
    
    
    
    
    public static function wpc_css_requote_urls194($css)
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
        return '?src=' . $src_ext;                     
    }

    public static function wpc_att_recorded18($url)
    {
        
        
        
        
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

        
        if (strpos($url, 'http') === false) {
            
            if (strpos($url, '//') === 0) {
                
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
        
        
        
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'emission_ready')) {
            self::$wpc_natwhy808 = 'no-negotiated-class';
            return false;
        }
        
        
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
                        return true; 
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
                    && is_array($na_s) && !empty($na_s['live-cdn']) && (string) $na_s['live-cdn'] === '1' 
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
            $ok = $cf ? (bool) get_option('wpc_v2_cf_avif_live', 1) : true; 
        }
        return (bool) apply_filters('wpc_avif_natural_source_ok', $ok);
    }


    public static function picture_natural_fleet_enabled()
    {
        $opt = function_exists('get_option') ? get_option('wpc_picture_natural_fleet', 1) : 1;
        
        
        
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
        
        
        
        
        
        $img_is_lazy = is_string($build_image_tag)
            && (strpos($build_image_tag, 'data-src=') !== false || strpos($build_image_tag, 'data-srcset=') !== false);
        if (!$img_is_lazy) return 'srcset';
        $on = (bool) apply_filters('wpc_picture_avif_lazy_source',
            (bool) (function_exists('get_option') ? get_option('wpc_picture_avif_lazy_source', 1) : 1));
        return $on ? 'data-srcset' : 'srcset';
    }


    public static function picture_avif_emit_natural()
    {
        
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;


        if (self::picture_natural_fleet_enabled()) {
            return (self::$pictureAvifEnabled === true) && (self::$zoneName !== '');
        }
        
        $all_zones = (bool) apply_filters('wpc_picture_avif_all_zones',
            (bool) (function_exists('get_option') ? get_option('wpc_picture_avif_all_zones', 1) : 1));
        if ($all_zones) {


            if (class_exists('WPC_Delivery_Resolver')
                && WPC_Delivery_Resolver::orch_nav_signal() === false) {
                return false;
            }
            
            
            return (self::$pictureAvifEnabled === true) && (self::$zoneName !== '');
        }
        
        return self::picture_avif_natural_ok();
    }


    public static function avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost)
    {
        $avifUrl = (string) $avifUrl;

        if ($avifZoneBase !== '' && strpos($avifUrl, $avifZoneBase) === 0) {
            return substr($avifUrl, strlen($avifZoneBase));
        }
        
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
            $suffix = wpc_v2_adaptive_variant_suffix((int) $width, $aspect_meta);   
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

        
        if ($mode === 'same-ext') return $origin_ext;
        if ($jpeg_ceiling)        return $origin_ext;
        if ($mode === 'webp')     return 'webp';
        if ($mode === 'avif')     return 'avif';


        if ($witness_ok) {
            if (!$zone_is_cf) return 'webp';        
            $force = function_exists('wpc_force_natural') && wpc_force_natural();
            $nav   = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::orch_nav_signal() : null;
            if ($force || $nav === true) return 'webp';
            return $origin_ext;
        }


        return $origin_ext;
    }

    


    private static function single_url_format_mode()
    {
        $s = self::$settings;
        $m = (is_array($s) && !empty($s['single-url-image-format'])) ? (string) $s['single-url-image-format'] : 'auto';
        if (!in_array($m, ['auto', 'same-ext', 'webp', 'avif'], true)) $m = 'auto';
        return (string) apply_filters('wpc_single_url_image_format', $m);
    }

    



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
        
        
        if (stripos($build_image_tag, 'data-wpc-nd') !== false || stripos($build_image_tag, 'data-wpc-md') !== false) return $build_image_tag;
        
        


        $la_set = (is_array(self::$settings) && isset(self::$settings['lazy-auto-sizes']))
            ? self::$settings
            : ((function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array());
        $la_on = (is_array($la_set) && !empty($la_set['lazy-auto-sizes']));
        if (!apply_filters('wpc_auto_sizes_lazy', $la_on, $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\ssrcset\s*=\s*["\'][^"\']*?\d+w(?=[\s,"\'])/i', $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/i', $build_image_tag, $m)) return $build_image_tag;
        if (stripos($m[2], 'auto') !== false) return $build_image_tag;
        
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
        


        $la_set = (is_array(self::$settings) && isset(self::$settings['lazy-auto-sizes']))
            ? self::$settings
            : ((function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array());
        $la_on = (is_array($la_set) && !empty($la_set['lazy-auto-sizes']));
        if (!apply_filters('wpc_auto_sizes_lazy', $la_on, $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $build_image_tag)) return $build_image_tag;
        
        if (!preg_match('/\sdata-srcset\s*=\s*(["\'])(.*?)\1/is', $build_image_tag, $ds)) return $build_image_tag;
        if (!preg_match('/\d+w(?=[\s,"\'])/', $ds[2])) return $build_image_tag;
        if (preg_match('/(?<![-\w])srcset\s*=/i', $build_image_tag)) return $build_image_tag;
        if (preg_match('/\sdata-src\s*=/i', $build_image_tag)) return $build_image_tag;         
        if (preg_match('/\sclass\s*=\s*["\'][^"\']*(swiper|slick|owl|carousel|flickity|splide|attachment-slider|size-slider)/i', $build_image_tag)) return $build_image_tag;
        
        $aw = preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $build_image_tag, $mw) ? (int) $mw[1] : 0;
        $ah = preg_match('/\sheight\s*=\s*["\']?(\d+)/i', $build_image_tag, $mh) ? (int) $mh[1] : 0;
        list($rw, $rh) = self::srcset_real_dims(' srcset="' . $ds[2] . '"');
        if (!self::lazy_auto_aspect_safe($aw, $ah, $rw, $rh)) return $build_image_tag;
        
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
                
                
                if ($ohost === ''
                    || (strcasecmp($ohost, $zone_host) !== 0 && ($site_host === '' || strcasecmp($ohost, $site_host) !== 0))) {
                    return $m[0];
                }
                
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
                
                $in_base = false;
                foreach ($base_paths as $bp) {
                    if ($p === $bp || strpos($p, $bp . '/') === 0) { $in_base = true; break; }
                }
                if (!$in_base) return $m[0];
                
                
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
            
            if ($rw >= $maxDim && $rh >= $maxDim) return false;


            if ($ar >= 5.0 && max($rw, $rh) >= $maxDim) return false;
        }
        
        
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
        
        
        
        
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'isExcludedFrom')
            && wps_cdn_rewrite::isExcludedFrom('cdn', $wpc_abs804)) {
            return $url;
        }
        $wpc_p804 = @parse_url($wpc_abs804);
        if (empty($wpc_p804['path']) || !preg_match('/\.m?js$/i', $wpc_p804['path'])) {
            return $url;
        }
        
        
        
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
            
            return false;
        } else {
            
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
            
            $basename = $basename_original;
        } else {
            
            $basename = str_replace('-' . $matches[1] . 'x' . $matches[2], '', $basename_original);
        }

        


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

        
        if (strpos($src_url, self::$zoneName) !== false) {
            return $src_url;
        }


        $wpc_z  = (string) self::$zoneName;
        $wpc_oh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if ($wpc_z === '' || ($wpc_oh !== '' && strcasecmp($wpc_z, $wpc_oh) === 0)) {
            return $src_url;
        }


        
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
                
                foreach (self::$defaultExcludedList as $i => $excluded_string) {
                    if (strpos($src_url, $excluded_string) !== false) {
                        return $src_url;
                    }
                }

                $newSrc = $src_url;
                
                
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
                    
                    
                    
                    
                    if (self::$js == "1" && !apply_filters('wpc_scripts_same_origin', true)) {
                        $newSrc = 'https://' . self::$zoneName . '/m:' . self::$jsMinify . '/a:' . self::reformatUrl($src_url);
                    }
                } else {
                    if (strpos($src_url, '.svg') !== false) {
                        $newSrc = 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($src_url);
                    } elseif (preg_match('/\.gif(\?|#|$)/i', $src_url) && !self::cf_is_delivery()) {
                        
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
                    
                    $effective_max = (int) floor($maxW * ($sw / $sh));
                }
                if ($sw > 0) $src_w_for_cap = ($src_w_for_cap > 0) ? min($src_w_for_cap, $sw) : $sw;
            }
        }
        
        
        
        
        if (preg_match('#-(\d{2,5})x(\d{2,5})(?:-scaled)?\.(?:jpe?g|png|gif|webp|avif)(?:[?\#]|$)#i', (string) $imageUrl, $wpc_iw791)) {
            $wpc_fw791 = (int) $wpc_iw791[1];
            if ($wpc_fw791 >= 100) {
                $src_w_for_cap = ($src_w_for_cap > 0) ? min($src_w_for_cap, $wpc_fw791) : $wpc_fw791;
            }
        }
        
        
        
        if ($src_w_for_cap === 0) {
            $wpc_ta298 = self::wpc_true_aspect934($imageUrl);
            if (is_array($wpc_ta298)) {
                $wpc_tw298 = (int) ($wpc_ta298['width'] ?? $wpc_ta298[0] ?? 0);
                if ($wpc_tw298 > 0) { $src_w_for_cap = $wpc_tw298; }
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
        
        if (class_exists('WooCommerce')) {
            
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
        $wpc_y151 = self::wpc_fonts_lane_yield151([(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop']);
        if ($gfFaces !== ''
            && ($wpc_y151 === true
                || ($wpc_y151 === null && self::wpc_crit_fonts_delivered150($criticalCss) > 0))) {
            
            $gfFaces = '';
        }
        if ($gfFaces !== '') {
            $gfFaces = $this->filterCriticalFontFaces($gfFaces);
        }
        if ($gfFaces !== '') {
            
            $gfFaces = self::wpc_face_dedupe($gfFaces, $criticalCss);
        }
        if ($gfFaces !== '' && apply_filters('wpc_gfaces_latin_only', self::wpc_gfaces_latin_default())) {
            $gfFaces = self::wpc_gfaces_prune_ranges($gfFaces);
        }
        if ($gfFaces !== '') {
            $criticalCss = $gfFaces . $criticalCss;


            if (stripos($criticalCss, 'fonts.gstatic.com') !== false) {
                
                
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
                            
                            $criticalCss = wpc_font_preload_postpaint_tag(array_filter(explode("\n", $wpc_pl_out))) . $criticalCss;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        
        
        
        
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


        
        
        if (apply_filters('wpc_sr_guard', true)
            && (stripos($html, 'skip-link') !== false || stripos($html, 'screen-reader-text') !== false)) {
            $criticalCss .= "\r\n" . '<style id="wpc-sr-guard">.screen-reader-text,.skip-link{border:0;clip:rect(1px,1px,1px,1px);clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;width:1px;word-wrap:normal}.screen-reader-text:focus,.skip-link:focus{clip:auto;clip-path:none;height:auto;width:auto;overflow:visible;position:absolute;left:6px;top:6px;z-index:100000;padding:8px 16px;background:#fff}</style>';
        }
        
        if (apply_filters('wpc_emoji_guard', true)
            && (strpos($html, 'class="emoji"') !== false || strpos($html, "class='emoji'") !== false || strpos($html, 'wp-smiley') !== false)) {
            $criticalCss .= "\r\n" . '<style id="wpc-emoji-guard">img.wp-smiley,img.emoji{display:inline!important;border:none!important;box-shadow:none!important;height:1em!important;width:1em!important;margin:0 .07em!important;vertical-align:-0.1em!important;background:none!important;padding:0!important}</style>';
        }
        
        
        
        
        if (apply_filters('wpc_header_img_guard', true)
            && preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,3000}?<img/is', $html)) {
            
            $criticalCss .= "\r\n" . '<style id="wpc-header-img-guard">:where(header) img,:where(.elementor-location-header) img{max-width:100%;height:auto}</style>';
        }
        
        
        
        if (apply_filters('wpc_header_logo_preload', true)
            && !self::wpc_lcp_has_hero_preload()
            && preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,3000}?<img[^>]*src="([^"]+\.(?:svg|png|webp|avif|jpe?g))(?:\?[^"]*)?"/is', $html, $wpc_hl199)
            && stripos($criticalCss, esc_url($wpc_hl199[1])) === false
            && self::wpc_lcp_bg_url_allowed($wpc_hl199[1])
            
            
            && !(function_exists('wpc_svg_inline_data718') && wpc_svg_inline_data718($wpc_hl199[1]) !== '')) {
            $criticalCss .= "\r\n" . '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_hl199[1]) . '" id="wpc-header-logo-preload">';
        }
        
        
        
        
        
        
        
        
        
        
        
        
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
        
        
        
        
        
        
        
        
        
        $wpc_spec130 = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $wpc_spec130on = is_array($wpc_spec130) && !empty($wpc_spec130['speculation-rules']) && $wpc_spec130['speculation-rules'] == '1';
        if (!$wpc_spec130on && apply_filters('wpc_speculation_rules', true) && stripos($html, 'speculationrules') === false) {
            $criticalCss .= "\r\n" . '<script type="speculationrules" id="wpc-speculation">'
                . '{"prefetch":[{"source":"document","eagerness":"conservative","where":{"and":['
                . '{"href_matches":"/*","relative_to":"document"},'
                . '{"not":{"href_matches":["/wp-admin/*","/wp-login.php*","/cart/*","/checkout/*","/my-account/*","/feed/*"]}},'
                . '{"not":{"href_matches":"*add-to-cart=*"}},'
                . '{"not":{"href_matches":"*logout*"}},'
                . '{"not":{"selector_matches":"a[rel~=nofollow]"}}'
                . ']}}]}</script>';
        }
        
        
        
        
        
        
        
        
        
        
        
        
        
        if (apply_filters('wpc_shape_fill_guard', true) && stripos($html, 'elementor-shape') !== false) {
            
            
            
            
            
            $criticalCss .= "\r\n" . '<style id="wpc-shape-fill-guard">.elementor-shape-fill{fill:transparent}</style>';
        }
        
        
        
        
        
        
        
        
        
        if (apply_filters('wpc_ux_shape_divider_guard', true) && stripos($html, 'ux-shape-divider') !== false) {
            $criticalCss .= "\r\n" . '<style id="wpc-ux-divider-guard">.ux-shape-divider{--divider-top-width:100%;--divider-width:100%;left:0;line-height:0;overflow:hidden;position:absolute;width:100%}.ux-shape-divider svg{display:block;height:150px;left:50%;position:relative;transform:translateX(-50%)}.ux-shape-divider--top{top:-1px;transform:rotate(180deg)}.ux-shape-divider--top svg{width:calc(var(--divider-top-width) + 2px)}.ux-shape-divider--bottom{bottom:-1px}.ux-shape-divider--bottom svg{width:calc(var(--divider-width) + 2px)}.ux-shape-divider--flip svg{transform:translateX(-50%) rotateY(180deg)}.ux-shape-divider--to-front{z-index:2}.ux-shape-divider .ux-shape-fill{fill:#fff}</style>';
        }
        
        
        
        
        
        
        
        
        
        
        if (apply_filters('wpc_flatsome_grid_guard', true) && stripos($html, 'themes/flatsome') !== false) {
            $wpc_w12_61 = ['8.3333333333%', '16.6666666667%', '25%', '33.3333333333%', '41.6666666667%', '50%',
                '58.3333333333%', '66.6666666667%', '75%', '83.3333333333%', '91.6666666667%', '100%'];
            $wpc_wc_61 = ['100%', '50%', '33.3333333333%', '25%', '20%', '16.6666666667%', '14.2857142857%', '12.5%'];
            $wpc_fg61 = '.row{display:flex;flex-flow:row wrap;width:100%}';
            foreach (['small' => '', 'medium' => '@media screen and (min-width:550px){', 'large' => '@media screen and (min-width:850px){'] as $wpc_bp61 => $wpc_mq61) {
                $wpc_fg61 .= $wpc_mq61;
                foreach ($wpc_w12_61 as $wpc_i61 => $wpc_v61) {
                    $wpc_fg61 .= '.' . $wpc_bp61 . '-' . ($wpc_i61 + 1) . '{flex-basis:' . $wpc_v61 . ';max-width:' . $wpc_v61 . '}';
                }
                foreach ($wpc_wc_61 as $wpc_j61 => $wpc_c61) {
                    $wpc_fg61 .= '.' . $wpc_bp61 . '-columns-' . ($wpc_j61 + 1) . ' .flickity-slider>.col,.'
                        . $wpc_bp61 . '-columns-' . ($wpc_j61 + 1) . '>.col{flex-basis:' . $wpc_c61 . ';max-width:' . $wpc_c61 . '}';
                }
                $wpc_fg61 .= $wpc_mq61 !== '' ? '}' : '';
            }
            
            
            
            
            $wpc_fg61 .= '.row-slider,.slider{position:relative;scrollbar-width:none}'
                . '.row.row-slider:not(.flickity-enabled){display:block}'
                . '.slider:not(.flickity-enabled){-ms-overflow-style:-ms-autohiding-scrollbar;overflow-x:scroll;overflow-y:hidden;white-space:nowrap;width:auto}'
                . '.slider:not(.flickity-enabled)>*{display:inline-block!important;vertical-align:top;white-space:normal!important}'
                . '.slider:not(.flickity-enabled)>*{position:relative!important}'
                . '.slider-load-first:not(.flickity-enabled){max-height:500px}'
                . '.slider-load-first:not(.flickity-enabled)>div{opacity:0}';
            $criticalCss .= "\r\n" . '<style id="wpc-flatsome-grid-guard">' . $wpc_fg61 . '</style>';
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
        
        
        
        
        
        
        if (apply_filters('wpc_nav_hover_guard', true) && stripos($html, 'elementor-nav-menu') !== false) {
            $criticalCss .= "\r\n" . '<style id="wpc-nav-hover-guard">'
                . 'html:not(.wpc-js-live) .elementor-nav-menu li.menu-item-has-children:hover>ul.sub-menu{display:block;position:absolute;top:100%;left:0;z-index:99999;width:max-content;min-width:100%;max-width:90vw}'
                . 'html:not(.wpc-js-live) .elementor-nav-menu li.menu-item-has-children:hover>ul.sub-menu>li>a{white-space:nowrap}'
                . 'html:not(.wpc-js-live) .elementor-nav-menu li li.menu-item-has-children:hover>ul.sub-menu{top:0;left:100%}'
                . '</style>';
        }
        
        
        
        
        
        
        
        
        
        
        
        if (apply_filters('wpc_swiper_preinit_guard', true)
            && (stripos($html, 'elementor-image-carousel-wrapper') !== false
                || stripos($html, 'elementor-widget-n-carousel') !== false)) {
            $wpc_cw293 = [0 => []];
            $wpc_sel293map = [];
            if (preg_match_all('~<div[^>]+data-widget_type="image-carousel\.default"[^>]*>~i', $html, $wpc_ic293) > 0) {
                foreach ($wpc_ic293[0] as $wpc_t) {
                    $wpc_cw293[0][] = $wpc_t;
                    $wpc_sel293map[] = '.elementor-image-carousel-wrapper:not(.swiper-initialized) .swiper-slide';
                }
            }
            if (preg_match_all('~<div[^>]+class="[^"]*elementor-widget-n-carousel[^"]*"[^>]*>~i', $html, $wpc_nc293) > 0) {
                foreach ($wpc_nc293[0] as $wpc_t) {
                    $wpc_cw293[0][] = $wpc_t;
                    $wpc_sel293map[] = ' .e-n-carousel:not(.swiper-initialized) .swiper-slide';
                }
            }
            $wpc_pg293 = '';
            $wpc_ncused293 = false;
            foreach (array_slice($wpc_cw293[0], 0, 12) as $wpc_ci293 => $wpc_tag293) {
                if (!preg_match('~data-id="([a-f0-9]{4,10})"~i', $wpc_tag293, $wpc_idm293)) { continue; }
                $wpc_id293 = $wpc_idm293[1];
                if (!preg_match('~data-settings="([^"]*)"~i', $wpc_tag293, $wpc_ds293)) { continue; }
                $wpc_set293 = json_decode(html_entity_decode($wpc_ds293[1], ENT_QUOTES), true);
                if (!is_array($wpc_set293)) { continue; }
                $wpc_nD293 = max(1, (int) (isset($wpc_set293['slides_to_show']) ? $wpc_set293['slides_to_show'] : 3));
                $wpc_nT293 = max(1, (int) (isset($wpc_set293['slides_to_show_tablet']) ? $wpc_set293['slides_to_show_tablet'] : $wpc_nD293));
                $wpc_nM293 = max(1, (int) (isset($wpc_set293['slides_to_show_mobile']) ? $wpc_set293['slides_to_show_mobile'] : $wpc_nT293));
                $wpc_sp293 = 0;
                if (isset($wpc_set293['image_spacing_custom']['size']) && is_numeric($wpc_set293['image_spacing_custom']['size'])) {
                    $wpc_sp293 = (int) $wpc_set293['image_spacing_custom']['size'];
                }
                
                
                
                
                
                
                
                
                
                
                $wpc_nL340 = isset($wpc_set293['slides_to_show_laptop']) && (int) $wpc_set293['slides_to_show_laptop'] > 0
                    ? (int) $wpc_set293['slides_to_show_laptop'] : 0;
                $wpc_spW340 = -1;
                if (isset($wpc_set293['image_spacing_custom_widescreen']['size'])
                    && is_numeric($wpc_set293['image_spacing_custom_widescreen']['size'])) {
                    $wpc_spW340 = (int) $wpc_set293['image_spacing_custom_widescreen']['size'];
                }
                $wpc_inner293 = isset($wpc_sel293map[$wpc_ci293]) ? $wpc_sel293map[$wpc_ci293]
                    : '.elementor-image-carousel-wrapper:not(.swiper-initialized) .swiper-slide';
                if (strpos($wpc_inner293, '.e-n-carousel') !== false) { $wpc_ncused293 = true; }
                $wpc_sel293 = '.elementor-element-' . $wpc_id293 . ' ' . ltrim($wpc_inner293);
                
                
                
                
                
                
                
                $wpc_gap343 = strpos($wpc_inner293, '.e-n-carousel') !== false;
                $wpc_w293 = function ($n, $sp = null) use ($wpc_sp293, $wpc_gap343) {
                    $sp = $sp === null ? $wpc_sp293 : (int) $sp;
                    if ($sp > 0 && $wpc_gap343) {
                        return 'width:calc((100% - ' . (($n - 1) * $sp) . 'px)/' . $n . ')';
                    }
                    return $sp > 0
                        ? 'width:calc((100% - ' . (($n - 1) * $sp) . 'px)/' . $n . ');margin-right:' . $sp . 'px'
                        : 'width:calc(100%/' . $n . ')';
                };
                $wpc_pg293 .= $wpc_sel293 . '{' . $wpc_w293($wpc_nM293) . '}'
                    . '@media(min-width:768px){' . $wpc_sel293 . '{' . $wpc_w293($wpc_nT293) . '}}'
                    . '@media(min-width:1025px){' . $wpc_sel293 . '{' . $wpc_w293($wpc_nL340 > 0 ? $wpc_nL340 : $wpc_nD293) . '}}'
                    . ($wpc_nL340 > 0 ? '@media(min-width:1367px){' . $wpc_sel293 . '{' . $wpc_w293($wpc_nD293) . '}}' : '')
                    . ($wpc_spW340 >= 0 && $wpc_spW340 !== $wpc_sp293 ? '@media(min-width:2400px){' . $wpc_sel293 . '{' . $wpc_w293($wpc_nD293, $wpc_spW340) . '}}' : '');
                
                
                
                if (!$wpc_gap343 && ($wpc_sp293 > 0 || $wpc_spW340 > 0)) {
                    $wpc_pg293 .= $wpc_sel293 . ':last-child{margin-right:0}';
                }
            }
            if ($wpc_pg293 !== '') {
                $wpc_pg293 = '.elementor-image-carousel-wrapper:not(.swiper-initialized){overflow:hidden}'
                    . '.elementor-image-carousel-wrapper:not(.swiper-initialized) .swiper-wrapper{display:flex;flex-wrap:nowrap;align-items:center}'
                    . ($wpc_ncused293
                        
                        
                        
                        ? '.e-n-carousel:not(.swiper-initialized){overflow:hidden}'
                          . '.e-n-carousel:not(.swiper-initialized) .swiper-wrapper{display:flex;flex-wrap:nowrap;align-items:stretch}'
                          . '.e-n-carousel:not(.swiper-initialized) .swiper-slide{height:auto}'
                        : '') . $wpc_pg293;
                $criticalCss .= "\r\n" . '<style id="wpc-swiper-preinit-guard">' . $wpc_pg293 . '</style>';
            }
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
        
        
        
        $html = self::wpc_dedupe_dead_subsets676($html);
        
        
        
        $html = self::wpc_defer_wire_dropfaces680($html);
        
        
        
        $html = self::wpc_inline_wire_lcp681($html);
        return $html;
    }


    public function wpc_arm_sentinel_tag($html)
    {
        try {


            
            
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
                return $html; 
            }


            if (function_exists('wpc_repull_kick_now') && !get_transient('wpc_kick_fire_' . md5($wpc_sk))
                && !(function_exists('wpc_is_low_value_page') && wpc_is_low_value_page())
                && !(function_exists('wpc_kick_is_dead') && wpc_kick_is_dead($wpc_sk))
                && !(function_exists('wpc_kick_budget_ok') && !wpc_kick_budget_ok())
                && apply_filters('wpc_render_kick', true)) {
                wpc_repull_kick_now($wpc_sk); 
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


        
        
        
        
        if (!empty($criticalCSSExists['desktop']) && function_exists('wpc_atf_section_ids617')) {
            $wpc_ids617 = wpc_atf_section_ids617($html);
            
            
            
            
            if (count($wpc_ids617) < 2 && function_exists('wpc_loop_grid_tokens16')
                && apply_filters('wpc_loop_sanity_gate', true)) {
                $wpc_lt16 = wpc_loop_grid_tokens16($html);
                if ($wpc_lt16) {
                    $wpc_critb16 = (string) @file_get_contents($criticalCSSExists['desktop']);
                    if ($wpc_critb16 !== '' && function_exists('wpc_artifact_covers_loop16')
                        && !wpc_artifact_covers_loop16($wpc_critb16, $wpc_lt16)) {
                        $wpc_ids617 = array_map(function ($t) { return 'loop:' . $t; }, $wpc_lt16);
                        $wpc_ids617[] = 'loop:pad';           
                    }
                }
            }
            if (count($wpc_ids617) >= 2) {
                $wpc_critb617 = (string) @file_get_contents($criticalCSSExists['desktop']);
                if ($wpc_critb617 !== '' && !wpc_artifact_covers_atf617($wpc_critb617, $wpc_ids617)) {
                    $wpc_cd617 = dirname($criticalCSSExists['desktop']);
                    
                    
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
                    
                    
                    
                    
                    
                    
                    
                    if ($wpc_stall621 >= 2 && apply_filters('wpc_crit_serve_after_stall', true)) {
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('crit-blind-served', $wpc_sk617, '', ['stall' => $wpc_stall621]);
                        }
                    } else {
                        $criticalCSSExists = false;
                    }
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
                        && $wpc_crit_age >= 30                       
                        && !get_transient($wpc_heal_lock)) {
                        $wpc_heal_url  = trim((string) file_get_contents($wpc_heal_uf));
                        $wpc_heal_nkey = ($wpc_heal_url !== '') ? 'wpc_lcp_healn_' . md5($wpc_heal_url) : '';
                        if ($wpc_heal_nkey !== '' && (int) get_transient($wpc_heal_nkey) >= 15) {
                            @unlink($wpc_heal_uf);
                        } elseif ($wpc_heal_url !== '' && filter_var($wpc_heal_url, FILTER_VALIDATE_URL)
                                  && self::wpc_lcp_heal_budget_ok()) {
                            set_transient($wpc_heal_nkey, (int) get_transient($wpc_heal_nkey) + 1, HOUR_IN_SECONDS);
                            set_transient($wpc_heal_lock, 1, MINUTE_IN_SECONDS);   
                            $wpc_heal_ua = defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'WPCompress';
                            
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
                        
                        
                        
                        if (!empty($wpc_hints357['fetchpriority']) && is_array($wpc_hints357['fetchpriority'])) {
                            $wpc_fp357 = array_slice(array_values(array_filter($wpc_hints357['fetchpriority'], function ($e) {
                                return is_array($e) && !empty($e['selector']) && in_array((string) ($e['device'] ?? 'both'), ['mobile', 'desktop', 'both'], true);
                            })), 0, 4);
                            if (!empty($wpc_fp357)) {
                                add_filter('wpc_lcp_fetchpriority_hints', function () use ($wpc_fp357) { return $wpc_fp357; }, 5);
                            }
                        }
                        
                        
                        
                        
                        
                        
                        
                        
                        
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
                                
                                
                                
                                
                                
                                
                                
                                
                                $wpc_lpauth357 = array_key_exists('url_is_authoritative', $wpc_lp357)
                                    ? (bool) $wpc_lp357['url_is_authoritative'] : true;
                                
                                
                                
                                
                                
                                
                                
                                
                                $wpc_lpcssw357 = (isset($wpc_lp357['css_w']) && is_numeric($wpc_lp357['css_w']) && (int) $wpc_lp357['css_w'] > 0)
                                    ? (int) $wpc_lp357['css_w'] : 0;
                                if ($wpc_lpcssw357 > 0 && $wpc_lpd357 === 'both') {
                                    $wpc_lpmedia357 = ' media="(max-width: 767.98px)"';
                                }
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
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
                                    
                                    
                                    if (!empty($wpc_parts444) && $wpc_kind444 !== 'bare') {
                                        $wpc_lprg444 = implode(', ', $wpc_parts444);
                                    }
                                }
                                $wpc_lpiss357  = apply_filters('wpc_lcp_preload_imagesrcset',
                                    ($wpc_lprg444 !== '' ? $wpc_lprg444 : self::wpc_lcp_img_responsive($html, $wpc_lpu357, 'srcset')),
                                    $wpc_lpu357, $wpc_lpd357);
                                
                                
                                
                                
                                
                                
                                
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
                                
                                
                                $wpc_lpsa444 = (!empty($wpc_lp357['sizes_attr']) && is_string($wpc_lp357['sizes_attr'])
                                                && strlen($wpc_lp357['sizes_attr']) <= 400)
                                    ? trim($wpc_lp357['sizes_attr']) : '';
                                if (!$wpc_lpauth357 && !$wpc_lphasiss357) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-responsive', '', '', ['dev' => $wpc_lpd357]);
                                    }
                                    continue; 
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
                                
                                
                                
                                
                                
                                
                                
                                $wpc_lptag839 = '<link rel="preload" as="image" fetchpriority="high" href="'
                                    . esc_url($wpc_lpu357) . '"' . $wpc_lpattr357 . $wpc_lpmedia357 . ' id="wpc-lcp-hero-preload">';
                                
                                
                                
                                
                                
                                
                                $wpc_lppath2109 = (string) parse_url((string) $wpc_lpu357, PHP_URL_PATH);
                                $wpc_lpmiss2109 = false;
                                if (preg_match('/\.avif$/i', $wpc_lppath2109)
                                    && ($wpc_lpup2109 = strpos($wpc_lppath2109, '/wp-content/uploads/')) !== false
                                    && defined('ABSPATH')
                                    && apply_filters('wpc_lcp_preload_exists_gate', true)) {
                                    $wpc_lpmiss2109 = !@file_exists(rtrim(ABSPATH, '/') . rawurldecode(substr($wpc_lppath2109, $wpc_lpup2109)));
                                }
                                if ($wpc_lpmiss2109) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-missing-avif', '', '', ['dev' => $wpc_lpd357]);
                                    }
                                } elseif (preg_match('/\.(?:mp4|webm|ogv|ogg|mov|m3u8)(?:\?|$)/i', (string) $wpc_lpu357)) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-video', '', '', ['dev' => $wpc_lpd357]);
                                    }
                                } elseif (!self::wpc_lcp_preload_in_doc70($html, (string) $wpc_lpu357)) {
                                    
                                    
                                    
                                    
                                    
                                    
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-stale-hero', '', '', ['dev' => $wpc_lpd357]);
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


                        $wpc_y151 = self::wpc_fonts_lane_yield151(['mobile', 'desktop']);
                        if ($wpc_y151 === false
                            || ($wpc_y151 === null
                                && self::wpc_crit_fonts_delivered150($criticalCSSContent_Desktop) === 0
                                && (!is_string($criticalCSSContent_Mobile)
                                    || self::wpc_crit_fonts_delivered150($criticalCSSContent_Mobile) === 0))) {


                            $wpc_v2_69 = (strncmp($wpc_sub_c, '/*wpc-subsets-v2*/', 18) === 0) ? ' data-wpc-v2="1"' : '';
                            $wpc_sub_c = (string) self::wpc_strip_family_faces23($wpc_sub_c, self::wpc_unbacked_fams23($html, $output));
                            if (stripos($wpc_sub_c, '@font-face') !== false) {
                                $output .= "\r\n" . '<style type="text/css" id="wpc-font-subsets"' . $wpc_v2_69 . '>' . $wpc_sub_c . '</style>';
                            }
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
                    
                } else {

                    
                    $getCSSContent = function ($cssContent) {
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');
                        return $commentPos !== false ? substr($cssContent, $commentPos + strlen('/* Preload Fonts */')) : $cssContent;
                    };

                    $criticalCSSContent_Desktop = self::wpc_dupe_rule_prune17(self::wpc_css_requote_urls194($getCSSContent($criticalCSSContent_Desktop)));
                    $criticalCSSContent_Mobile = self::wpc_dupe_rule_prune17(self::wpc_css_requote_urls194($getCSSContent($criticalCSSContent_Mobile)));

                    
                    
                    
                    
                    if ((empty(self::$cdnEnabled) || self::$cdnEnabled != '1') && function_exists('wpc_unzone_css')) {
                        $criticalCSSContent_Desktop = wpc_unzone_css($criticalCSSContent_Desktop);
                        $criticalCSSContent_Mobile  = wpc_unzone_css($criticalCSSContent_Mobile);
                    }


                    
                    
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


                            
                            
                            
                            
                            if ($wpc_auth_ok && $wpc_sib === '' && $wpc_need_w >= 64 && $wpc_need_h >= 64
                                && $wpc_need_w <= 2560 && $wpc_need_h <= 2560
                                && !empty(self::$cdnEnabled) && self::$cdnEnabled == '1'
                                && apply_filters('wpc_lcp_rung_synth', true)
                                && preg_match('#^(https?://[^\s"\')]+)\.(webp|avif|jpe?g|png)$#i', $wpc_css_url, $wpc_rs_m)
                                && !preg_match('#-\d+x\d+$#', $wpc_rs_m[1])) {
                                $wpc_sib = $wpc_rs_m[1] . '-' . (int) $wpc_need_w . 'x' . (int) $wpc_need_h . '.' . $wpc_rs_m[2];
                            }
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            if ($wpc_dev_key !== 'mobile'
                                && !apply_filters('wpc_lcp_bg_small_desktop_pin', false)) {
                                
                                
                                
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
                                $wpc_pin101a = self::wpc_bg_pin_value101($wpc_auth_sel, $wpc_pre_url, $output . $html);
                                if ($wpc_pin101a !== '') {
                                    $output .= '<style id="wpc-lcp-bg-authority' . $wpc_id_sfx57 . '">@media ' . $wpc_auth_media . '{' . $wpc_auth_sel . '{' . $wpc_pin101a . '}}</style>';
                                }
                            }
                        }
                    }


                    
                    
                    
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
                                $wpc_pin101b = self::wpc_bg_pin_value101($wpc_ad_sel, $wpc_ad['url'], $output . $html);
                                if ($wpc_pin101b !== '') {
                                    $output .= '<style id="wpc-lcp-bg-authority' . $wpc_id_sfx57 . '">@media ' . $wpc_ad_media . '{' . $wpc_ad_sel . '{' . $wpc_pin101b . '}}</style>';
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


                    if (!empty(self::$settings['used-css']) && self::$settings['used-css'] == '1'
                        && function_exists('wpc_used_css_path') && defined('WPS_IC_CRITICAL_URL')) {
                        $wpc_cd50 = dirname($criticalCSSExists['desktop']) . '/';


                        
                        
                        $wpc_tk50 = @is_file($wpc_cd50 . 'tpl.txt')
                            ? trim((string) @file_get_contents($wpc_cd50 . 'tpl.txt')) : '';


                        if ($wpc_tk50 === '' && function_exists('wpc_compute_tpl_key')) {
                            $wpc_tk137 = (string) wpc_compute_tpl_key();
                            if ($wpc_tk137 !== '') {
                                @file_put_contents($wpc_cd50 . 'tpl.txt', $wpc_tk137);
                                $wpc_tk50 = $wpc_tk137;
                            }
                        }


                        
                        
                        
                        
                        if ($wpc_tk50 !== '' && function_exists('wpc_used_css_provenance_ok')
                            && !wpc_used_css_provenance_ok($wpc_cd50, $wpc_tk50)) {
                            $wpc_tk50 = '';
                        }

                        
                        
                        
                        
                        
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

                            
                            
                            
                            
                            
                            
                            
                            
                            if (!empty(self::$settings['preload-crit-fonts']) && self::$settings['preload-crit-fonts'] == '1'
                                && apply_filters('wpc_ucss_font_preload', true)
                                && strpos($output, 'data-wpc-ucssfp336') === false) {
                                $wpc_ucb336 = (string) @file_get_contents($wpc_up50, false, null, 0, 131072);
                                if ($wpc_ucb336 !== '' && stripos($wpc_ucb336, '@font-face') !== false) {
                                    $wpc_cf336 = [];
                                    $wpc_ct336 = (string) (isset($criticalCSSContent_Desktop) ? $criticalCSSContent_Desktop : '')
                                        . (string) (isset($criticalCSSContent_Mobile) ? $criticalCSSContent_Mobile : '');
                                    if ($wpc_ct336 !== '' && preg_match_all('/font-family\s*:\s*["\']?([^;,"\'}<]+)/i', $wpc_ct336, $wpc_cm336)) {
                                        foreach ($wpc_cm336[1] as $wpc_c336) {
                                            $wpc_c336 = strtolower(trim($wpc_c336));
                                            if ($wpc_c336 !== '' && strlen($wpc_c336) <= 40 && stripos($wpc_c336, 'fallback') === false
                                                && strpos($wpc_c336, 'var(') === false && strpos($wpc_c336, '--') === false) {
                                                $wpc_cf336[$wpc_c336] = 1;
                                            }
                                        }
                                    }
                                    $wpc_fcss336 = '';
                                    if (!empty($wpc_cf336) && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $wpc_ucb336, $wpc_fb336)) {
                                        foreach ($wpc_fb336[0] as $wpc_blk336) {
                                            if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $wpc_blk336, $wpc_fn336)
                                                && !empty($wpc_cf336[strtolower(trim($wpc_fn336[1]))])) {
                                                $wpc_fcss336 .= $wpc_blk336;
                                            }
                                        }
                                    }
                                    if ($wpc_fcss336 !== '' && stripos($wpc_fcss336, 'data:font') === false) {
                                        $wpc_pl336 = $this->extractCriticalFontPreloads($wpc_fcss336);
                                        if ($wpc_pl336 !== '') {
                                            $output .= "\r\n" . '<span data-wpc-ucssfp336="1" hidden></span>' . $wpc_pl336;
                                        }
                                    }
                                }
                            }


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
                            
                            
                            
                            
                            
                            
                            
                            $wpc_bmark828 = (strpos((string) $html, 'et-waypoint') !== false
                                    || strpos((string) $html, 'et_animated') !== false
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
                                
                                
                                
                                
                                
                                
                                if (apply_filters('wpc_used_css_split', true) && @filesize($wpc_restp57) > 32) {
                                    $wpc_restu57 = $wpc_ubase57 . rawurlencode(basename($wpc_restp57)) . '?uv=' . (int) @filemtime($wpc_restp57);
                                    $output .= "\r\n" . '<link rel="stylesheet" id="wpc-used-css-rest' . $idSfx . '" data-wpc-rest="' . esc_url($wpc_restu57) . '" data-wpc-ucss-rest="' . $mediaTgt . '" media="print">';
                                    if (strpos($output, 'wpc-bgl255-arm') === false) {
                                        $output .= self::wpc_bgl255_arm_tag34();
                                    }
                                    return;
                                }
                                $wpc_uu57 = $wpc_ubase57 . rawurlencode(basename($path)) . '?uv=' . (int) @filemtime($path);
                                
                                
                                
                                
                                $output .= "\r\n" . '<link rel="stylesheet" id="wpc-used-css' . $idSfx . '" data-wpc-ucss="' . $mediaTgt . '" data-wpc-endbody="1" media="' . $mediaTgt . '" onload="this.onload=null;try{document.documentElement.classList.add(\'wpc-css-live\')}catch(x){}" onerror="try{document.documentElement.classList.add(\'wpc-css-live\')}catch(x){}" href="' . esc_url($wpc_uu57) . '">';
                                
                                
                                
                                
                                
                                if (strpos($output, 'wpc-bgl255-arm') === false) {
                                    $output .= self::wpc_bgl255_arm_tag34();
                                }
                            };
                            $wpc_upu245 = $wpc_tk50 !== '' ? wpc_used_css_path($wpc_tk50) : '';
                            if (self::wpc_combined_crit_on() && $wpc_upu245 !== '' && @filesize($wpc_upu245) > 64
                                && apply_filters('wpc_combined_union_usedcss', true)) {
                                
                                
                                
                                $wpc_emit_used57($wpc_upu245, 'all', '');
                            } elseif (self::wpc_combined_crit_on() && $wpc_up_m57 !== '' && $wpc_up_d57 !== ''
                                && @filesize($wpc_up_m57) > 64 && @filesize($wpc_up_d57) > 64
                                && basename($wpc_up_m57) !== basename($wpc_up_d57)) {


                                $wpc_emit_used57($wpc_up_m57, '(max-width: 767.98px)', '');
                                $wpc_emit_used57($wpc_up_d57, '(min-width: 768px)', '-d');
                                if (strpos($output, 'data-wpc-rest') !== false) {
                                    
                                    
                                    
                                    $output .= "\r\n" . self::wpc_ucss_boot_js();
                                }
                                
                                
                                
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
                            
                            
                            
                            if (strpos($output, 'data-wpc-rest') !== false && strpos($output, 'wpc-ucss-boot') === false) {
                                $output .= "\r\n" . self::wpc_ucss_boot_js();
                            }


                        }
                    }


                    $wpc_minmob122 = $this->isMobile()
                        && !empty(self::$settings['minimal-mobile-css']) && self::$settings['minimal-mobile-css'] == '1'
                        && apply_filters('wpc_minimal_mobile_css', true);


                    $wpc_fpre114_raw = trim((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/font-preload.txt'));
                    
                    if ($wpc_fpre114_raw !== '' && (empty(self::$cdnEnabled) || self::$cdnEnabled != '1') && function_exists('wpc_unzone_url')) {
                        $wpc_fpre114_raw = implode("\n", array_map('wpc_unzone_url', preg_split('/\r?\n/', $wpc_fpre114_raw)));
                    }
                    
                    
                    
                    
                    $wpc_sub186 = '';
                    if (apply_filters('wpc_subset_covers_preloads', true)) {
                        $wpc_sub186 = (string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/font-subsets.css');
                        if (strlen($wpc_sub186) < 1024 || stripos($wpc_sub186, 'data:font') === false) {
                            $wpc_sub186 = '';
                        }
                        
                        
                        
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
                            
                            $wpc_fpre_bn116 = strtolower((string) basename((string) parse_url($wpc_fpre114, PHP_URL_PATH)));
                            if ($wpc_fpre_bn116 !== ''
                                && preg_match('#url\(\s*(["\']?)(https?://[^"\')]*?/' . preg_quote($wpc_fpre_bn116, '#') . ')\1?\s*\)#i', $wpc_fpre_crit116, $wpc_fm116)) {
                                $wpc_fpre114 = $wpc_fm116[2];
                            }
                            $wpc_fpre_n116++;
                            $wpc_fpre_list689[] = esc_url($wpc_fpre114);
                        }

                        
                        
                        if ($wpc_fpre_n116 < 3 && apply_filters('wpc_crit_face_preload', true)
                            && preg_match_all('/@font-face\s*\{[^}]*\}/is', $wpc_fpre_crit116, $wpc_cfm116)) {
                            $wpc_seen116 = [];
                            
                            
                            
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
                        
                        if (!empty($wpc_fpre_list689) && class_exists('wps_cdn_rewrite')
                            && method_exists('wps_cdn_rewrite', 'wpc_font_preload_url_form796')) {
                            $wpc_fpre_list689 = array_values(array_unique(array_map(
                                ['wps_cdn_rewrite', 'wpc_font_preload_url_form796'], $wpc_fpre_list689)));
                        }
                        
                        
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
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Mobile, $html);
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Desktop, $html);
                    } elseif ($this->isMobile()) {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Mobile, $html);
                    } else {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Desktop, $html);
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


                    
                    if (($this->isMobile() || self::wpc_combined_crit_on()) && !empty($criticalCSSContent_Mobile) && $wpc_minmob122) {
                        $criticalCSSContent_Mobile = self::wpc_strip_covered_fullface($criticalCSSContent_Mobile);
                    }
                    if (!empty($criticalCSSContent_Mobile)) {
                        $criticalCSSContent_Mobile = self::wpc_face_self_dedupe($criticalCSSContent_Mobile);
                    }
                    if (!empty($criticalCSSContent_Desktop)) {
                        $criticalCSSContent_Desktop = self::wpc_face_self_dedupe($criticalCSSContent_Desktop);
                    }
                    
                    
                    
                    if (function_exists('wpc_css_insert_fallbacks')) {
                        if (!empty($criticalCSSContent_Mobile)) {
                            $criticalCSSContent_Mobile = wpc_css_insert_fallbacks($criticalCSSContent_Mobile);
                        }
                        if (!empty($criticalCSSContent_Desktop)) {
                            $criticalCSSContent_Desktop = wpc_css_insert_fallbacks($criticalCSSContent_Desktop);
                        }
                    }
                    if (!empty($criticalCSSContent_Mobile)) {
                        $criticalCSSContent_Mobile = self::wpc_crit_url_align225($criticalCSSContent_Mobile);
                    }
                    if (!empty($criticalCSSContent_Desktop)) {
                        $criticalCSSContent_Desktop = self::wpc_crit_url_align225($criticalCSSContent_Desktop);
                    }
                    
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
                        
                        
                        
                        
                        
                        
                        if ($wpc_lf210 !== '' && class_exists('wps_cdn_rewrite')
                            && method_exists('wps_cdn_rewrite', 'wpc_font_remote_ranges')) {
                            $wpc_lfm210 = wps_cdn_rewrite::wpc_font_remote_ranges();
                            
                            
                            
                            
                            
                            
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
                                    
                                    
                                    
                                    
                                    if (function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($fam480)) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('font-gate-iconfont', '', '', [
                                                'family' => substr($fam480, 0, 28), 'src' => 'crit-artifact',
                                            ]);
                                        }
                                        $blk = (string) preg_replace('/\s*;?\s*unicode-range\s*:\s*[^;}]+;?/i', '', $blk);
                                        return (string) preg_replace('/;\s*\}/', '}', $blk);
                                    }
                                    
                                    
                                    if (empty($wpc_sfam480[$fam480])) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('font-gate-unpaired', '', '', [
                                                'family'   => substr($fam480, 0, 28), 'src' => 'crit-artifact',
                                                'stripped' => preg_match('/unicode-range\s*:/i', $blk) ? 1 : 0,
                                            ]);
                                        }
                                        
                                        
                                        
                                        
                                        
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
                    


                    
                    
                    
                    $wpc_cmb154 = '';
                    if (self::wpc_combined_crit_on() && apply_filters('wpc_crit_combined_artifact', true)
                        && !empty($criticalCSSExists['desktop'])) {
                        $wpc_cmd154 = dirname($criticalCSSExists['desktop']);
                        $wpc_cmf154 = $wpc_cmd154 . '/critical_combined.css';
                        
                        
                        
                        
                        $wpc_cmstale338 = @is_file($wpc_cmf154)
                            && (int) @filemtime($wpc_cmf154) < (int) @filemtime($criticalCSSExists['desktop']);
                        if ($wpc_cmstale338 && function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_rej_log')) {
                            set_transient('wpc_cmb_rej_log', 1, 3600);
                            wpc_cache_first_log('cmb-rejected', basename($wpc_cmd154), '', ['why' => 'stale-mtime']);
                        }
                        if (!$wpc_cmstale338 && @is_readable($wpc_cmf154) && @filesize($wpc_cmf154) > 1024) {
                            $wpc_cmb154 = (string) @file_get_contents($wpc_cmf154);
                            $wpc_cmr154 = '';
                            
                            
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
                            } elseif (($wpc_cov26 = self::wpc_cmb_coverage26($wpc_cmb154, $wpc_cmf154, $criticalCSSExists)) !== '') {
                                $wpc_cmr154 = 'coverage-' . $wpc_cov26;
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
                            $wpc_cmb154 = self::wpc_crit_url_align225($wpc_cmb154);
                        }
                        if (self::wpc_faces_covered601($output, $html, $wpc_cmb154)
                            && apply_filters('wpc_late_faces', true)) {
                            $wpc_cmb154 = self::wpc_demote_url_faces($wpc_cmb154, $wpc_lf210, $output);
                        }
                        $wpc_gf154 = '';
                        $wpc_y151b = self::wpc_fonts_lane_yield151(['mobile', 'desktop']);
                        if ($wpc_y151b === false
                            || ($wpc_y151b === null && self::wpc_crit_fonts_delivered150($wpc_cmb154) === 0)) {
                            $wpc_gf154 = $this->maybeInlineGoogleFontFaces($html, $wpc_cmb154);
                            
                            
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
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    if (apply_filters('wpc_anim_reveal_belt', true)) {
                        $output .= "\r\n" . '<style id="wpc-anim-reveal">.et-waypoint.et-animated{opacity:1!important}</style>';
                    }
                    
                    
                    if (function_exists('wpc_body_scope_guard606')) {
                        $output .= wpc_body_scope_guard606(
                            (string) $criticalCSSContent_Desktop . (string) $criticalCSSContent_Mobile,
                            (strpos($output, 'wpc-used-css') !== false || strpos((string) $html, 'wpc-used-css') !== false)
                        );
                    }
                    
                    if (!empty($wpc_lf210) && strpos($output, 'wpc-late-faces') === false) {
                        
                        if (function_exists('wpc_strip_livefaces_fams329')) {
                            $wpc_lf210 = wpc_strip_livefaces_fams329($wpc_lf210, $output . (string) $html);
                        }
                        
                        $wpc_lf210 = self::wpc_face_self_dedupe($wpc_lf210);
                        
                        
                        
                        $wpc_lf210 = self::wpc_face_tuple_dedupe2113($wpc_lf210);
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        
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
                            $output .= "\r\n" . '<style id="wpc-late-faces" type="wpc/late-faces" media="not all">' . self::wpc_face_display_sweep21($wpc_lf210) . '</style>';
                            
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

        
        
        
        
        
        
        static $wpc_cl_subs561 = false;
        if (!$wpc_cl_subs561) {
            $wpc_cls561 = self::wpc_critless_subsets561((string) $html, $output);
            if ($wpc_cls561 !== '') {
                $output .= $wpc_cls561;
                $wpc_cl_subs561 = true;
            }
        }

        
        
        
        if (function_exists('wpc_font_carrier_record602')
            && !(function_exists('is_user_logged_in') && is_user_logged_in())
            && stripos((string) $output, '@font-face') !== false) {
            wpc_font_carrier_record602((string) $output);
        }

        return $output;
    }

    






    private static function wpc_critless_subsets561($html, $output)
    {
        if (!apply_filters('wpc_subset_inline_critless', true)
            || !apply_filters('wpc_atf_subset_inline', true)
            || strpos($output, 'id="wpc-font-subsets"') !== false
            || strpos($html, 'id="wpc-font-subsets"') !== false) {
            return '';
        }
        $wpc_y151 = self::wpc_fonts_lane_yield151([(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop']);
        
        if ($wpc_y151 === true
            || ($wpc_y151 === null && self::wpc_crit_fonts_delivered150($output) > 0)) {
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
            $wpc_c561 = (string) self::wpc_strip_family_faces23($wpc_c561, self::wpc_unbacked_fams23($html, $output));
            if (stripos($wpc_c561, '@font-face') === false) {
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

        
        $pattern = '/@font-face\s*\{.*?\}/is';

        $wpc_out152 = preg_replace_callback($pattern, function ($match) use ($blockedFonts) {
            $fontFaceBlock = $match[0];

            
            
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

        
        if (!preg_match_all('/@font-face\s*\{[^}]+\}/is', $criticalCss, $fontFaceBlocks)) {
            return '';
        }
        $fontFaceCss = implode(' ', $fontFaceBlocks[0]);

        
        $fontPattern = '/url\((\'|")?([^\'")\s]+\.(woff2|woff|ttf|otf|eot))\1?\)/i';
        if (!preg_match_all($fontPattern, $fontFaceCss, $matches, PREG_SET_ORDER)) {
            return '';
        }
        
        $wpc_famByUrl32 = [];
        foreach ($fontFaceBlocks[0] as $wpc_blk32) {
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $wpc_blk32, $wpc_fm32)) { continue; }
            if (preg_match_all($fontPattern, $wpc_blk32, $wpc_bu32, PREG_SET_ORDER)) {
                foreach ($wpc_bu32 as $wpc_one32) { $wpc_famByUrl32[$wpc_one32[2]] = trim($wpc_fm32[1]); }
            }
        }

        
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

            
            if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $fontUrl)) {
                continue;
            }

            
            if (strpos($fontUrl, 'data:') === 0) continue;

            
            $baseUrl = strtok($fontUrl, '?');
            if (in_array($baseUrl, $loadedFonts)) continue;

            
            $ext = strtolower($match[3]);
            $typeMap = [
                'woff2' => 'font/woff2',
                'woff'  => 'font/woff',
                'ttf'   => 'font/ttf',
                'otf'   => 'font/otf',
                'eot'   => 'application/vnd.ms-fontobject',
            ];
            $type = $typeMap[$ext] ?? 'font/woff2';


            $wpc_fpb_list689[] = isset($wpc_famByUrl32[$fontUrl]) ? [$fontUrl, $type, $wpc_famByUrl32[$fontUrl]] : [$fontUrl, $type];
            $loadedFonts[] = $baseUrl;
        }
        self::$wpc_font_preloads_emitted = $loadedFonts;

        
        
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
                } else { 
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

    
    public static function wpc_face_range_latin($block)
    {
        if (!preg_match('/unicode-range\s*:\s*([^;}]+)/i', $block, $ur)) {
            return true;
        }
        $r = strtoupper($ur[1]);
        return strpos($r, 'U+0000') !== false || strpos($r, 'U+00-') !== false || strpos($r, 'U+0-') !== false;
    }

    





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

    
    public static function wpc_face_key($block)
    {
        if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $block, $ff)) {
            return '';
        }
        $sp = self::wpc_face_weight_span($block);
        $s = preg_match('/font-style\s*:\s*(italic|oblique)/i', $block) ? 'i' : 'n';
        return strtolower(trim($ff[1])) . '|' . $sp[0] . '-' . $sp[1] . '|' . $s;
    }

    














    public static function wpc_dedupe_dead_subsets676($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_dedupe_dead_subsets', true)) {
            return $html;
        }
        if (strpos($html, 'id="wpc-font-subsets"') === false) {
            return $html;
        }
        try {
            
            $twin = [];
            if (preg_match_all('/@font-face\s*\{[^{}]*\}/is', $html, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $f) {
                    $blk = $f[0];
                    if (preg_match('/unicode-range\s*:/i', $blk)) { continue; }               
                    if (!preg_match('/src\s*:[^;}]*url\(\s*["\']?https?:/i', $blk)) { continue; } 
                    $k = self::wpc_face_key($blk);
                    if ($k === '') { continue; }
                    if (!isset($twin[$k]) || $f[1] > $twin[$k]) { $twin[$k] = $f[1]; }
                }
            }
            if (!$twin) { return $html; }
            
            
            
            
            
            
            
            
            
            
            
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
                
                
                
                
                
                
                
                $wpc_targets684 = [];
                if ($decl) {
                    if (strtolower((string) ($decl['vehicle'] ?? '')) !== 'inline-style' || empty($decl['id'])) {
                        continue;                                               
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
                        
                        
                        
                        if (stripos($m[0], 'data:') !== false
                            || preg_match('/src\s*:[^;}]*\blocal\s*\(/i', $m[0])) {
                            return $m[0];
                        }
                        if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $m[0], $ff)
                            && strtolower(trim($ff[1])) === $fam) {
                            $moved .= $m[0];
                            return '';                                             
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
            if ($late !== '' && function_exists('wpc_strip_livefaces_fams329')) {
                
                $late = wpc_strip_livefaces_fams329($late, $html);
            }
            if ($late === '') { return $html; }
            
            if (preg_match('/<style\b[^>]*\bid=(["\'])wpc-late-faces\1[^>]*>/i', $html, $m2, PREG_OFFSET_CAPTURE)) {
                $at = $m2[0][1] + strlen($m2[0][0]);
                $html = substr($html, 0, $at) . $late . substr($html, $at);
            } else {
                $lane = "\r\n" . '<style id="wpc-late-faces" type="wpc/late-faces" media="not all">' . $late . '</style>';
                
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
            if (!$lcp || ($lcp['verdict'] ?? '') !== 'inline-data-uri') { return $html; }  
            
            
            
            
            
            
            $url = (string) ($lcp['url'] ?? '');
            $wpc_fb700 = function ($h, $why) use ($url, $dev, $urlKey, $lcp) {
                $GLOBALS['wpc_lcp_cover700'] = 'fallback';
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wire-lcp-cover-fallback', $urlKey, '', ['dev' => $dev, 'why' => $why]);
                }
                if ($url === '' || !preg_match('#^https?://#i', $url)) {
                    $GLOBALS['wpc_lcp_cover700'] = 'none'; 
                    return $h;
                }
                if (strpos($h, 'wpc-lcp-bg-preload') !== false || strpos($h, 'wpc-lcp-bg-authority') !== false) {
                    return $h; 
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
                    $wpc_pin101c = self::wpc_bg_pin_value101($wpc_sel700, $url, $h);
                    if ($wpc_pin101c !== '') {
                        $wpc_inj700 .= '<style id="wpc-lcp-bg-authority700">@media ' . $wpc_med700 . '{' . $wpc_sel700
                            . '{' . $wpc_pin101c . '}}</style>';
                    }
                }
                $wpc_hp700 = strripos($h, '</head>');
                return ($wpc_hp700 !== false)
                    ? substr($h, 0, $wpc_hp700) . $wpc_inj700 . substr($h, $wpc_hp700)
                    : $h . $wpc_inj700;
            };
            if (strtolower((string) ($lcp['asset_type'] ?? '')) !== 'svg') { return $wpc_fb700($html, 'not-svg'); }  
            if ($url === '' || !preg_match('#(/wp-content/uploads/[^"\'()\s<>?]+\.svg)#i', $url, $mm)) { return $wpc_fb700($html, 'no-uploads-url'); }
            $uploadsPath = $mm[1];
            $local = (defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/') : '') . substr($uploadsPath, strlen('/wp-content'));
            if (!@is_readable($local)) { return $wpc_fb700($html, 'local-unreadable'); }
            $bytes = (int) @filesize($local);
            if ($bytes <= 0 || $bytes > (int) apply_filters('wpc_wire_lcp_max_bytes', 12288)) { return $wpc_fb700($html, 'size'); }  
            $svg = (string) @file_get_contents($local);
            if ($svg === '' || stripos($svg, '<svg') === false || stripos($svg, '</script') !== false) { return $wpc_fb700($html, 'svg-invalid'); }
            $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);  
            $count = 0;
            
            $html = preg_replace('#url\(\s*([\'"]?)[^"\'()\s]*' . preg_quote($uploadsPath, '#') . '(?:\?[^"\'()\s]*)?\1\s*\)#i',
                'url(' . $dataUri . ')', $html, -1, $c1); $count += (int) $c1;
            if (strtolower((string) ($lcp['vehicle'] ?? '')) === 'img') {
                $html = preg_replace('#(\bsrc=)([\'"])[^"\']*' . preg_quote($uploadsPath, '#') . '(?:\?[^"\']*)?\2#i',
                    '$1$2' . $dataUri . '$2', $html, -1, $c2); $count += (int) $c2;
            }
            if ($count === 0) { return $wpc_fb700($html, 'not-on-page'); }  
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
            
            $keep = strpos($r, 'U+0000') !== false || strpos($r, 'U+00-') !== false
                || strpos($r, 'U+0-') !== false || strpos($r, 'U+0100') !== false;
            return $keep ? $m[0] : '';
        }, $faces);
        if (!is_string($out) || stripos($out, '@font-face') === false) {
            return $faces;
        }
        return trim($out);
    }

    




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
            
            
            
            if (is_string($ctx) && $ctx !== '' && stripos($ctx, 'data:font') !== false
                && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $ctx, $cm)) {
                foreach ($cm[0] as $cblk) {
                    if (stripos($cblk, 'data:') === false) {
                        continue;
                    }
                    $cfam = $wpc_cov726($cblk, $cvr);
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
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
                
                
                
                if ($fam === ''
                    || (function_exists('wpc_css_is_icon_font')
                        && wpc_css_is_icon_font($fam)
                        && apply_filters('wpc_icon_faces_live', true))) {
                    return $m[0];
                }
                
                
                
                
                
                
                
                
                
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

    




    
    
    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_fonts_owner151($device = '')
    {
        static $wpc_wire151 = null;
        static $wpc_mirror151 = null;
        if ($wpc_wire151 === null) {
            $wpc_wire151 = [];
            $wpc_mirror151 = [];
            try {
                if (defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key')) {
                    $wpc_k151 = ltrim((string) (new wps_ic_url_key())->setup(), '/');
                    $wpc_d151 = $wpc_k151 !== '' ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k151 . '/' : '';
                    if ($wpc_d151 !== '' && @is_readable($wpc_d151 . 'wire.json')) {
                        $wpc_j151 = json_decode((string) @file_get_contents($wpc_d151 . 'wire.json'), true);
                        if (is_array($wpc_j151) && !empty($wpc_j151['fonts_owner']) && is_array($wpc_j151['fonts_owner'])) {
                            $wpc_wire151 = $wpc_j151['fonts_owner'];
                        }
                    }
                    if ($wpc_d151 !== '' && @is_readable($wpc_d151 . 'fonts-owner.json')) {
                        $wpc_m151 = json_decode((string) @file_get_contents($wpc_d151 . 'fonts-owner.json'), true);
                        if (is_array($wpc_m151)) {
                            $wpc_mirror151 = $wpc_m151;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $wpc_wire151 = [];
                $wpc_mirror151 = [];
            }
        }
        if ($device !== 'mobile' && $device !== 'desktop') {
            $device = (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
        }
        $row = (isset($wpc_wire151[$device]) && is_array($wpc_wire151[$device])) ? $wpc_wire151[$device]
            : (isset($wpc_wire151['owner']) ? $wpc_wire151
                : ((isset($wpc_mirror151[$device]) && is_array($wpc_mirror151[$device])) ? $wpc_mirror151[$device]
                    : (isset($wpc_mirror151['owner']) ? $wpc_mirror151 : [])));
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

    
    
    
    
    public static function wpc_fonts_lane_yield151($devices)
    {
        try {
            $owners = [];
            foreach ((array) $devices as $d) {
                $o = self::wpc_fonts_owner151((string) $d);
                if ($o['owner'] === '') {
                    return null;
                }
                $owners[] = $o;
            }
            if (empty($owners)) {
                return null;
            }
            foreach ($owners as $o) {
                if ($o['owner'] === 'plugin-subsets') {
                    return false;
                }
                if ($o['owner'] === 'crit-inline' && empty($o['complete'])) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return null;
        }
    }

    
    
    
    
    
    public static function wpc_crit_fonts_delivered150($crit)
    {
        if (!is_string($crit) || $crit === '' || stripos($crit, 'data:font') === false) {
            return 0;
        }
        $n = preg_match_all('/@font-face\s*\{[^}]*?src\s*:[^}]*?data:font[^}]*\}/is', $crit, $m);
        return is_int($n) ? $n : 0;
    }

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
        
        
        
        
        if (self::wpc_subsets_carry_faces798((string) $output) || self::wpc_subsets_carry_faces798((string) $html)) {
            return true;
        }
        return self::wpc_crit_fonts_delivered150((string) $crit) > 0;
    }

    





    
    
    
    
    
    
    
    
    
    
    public static function wpc_face_tuple_dedupe2113($css)
    {
        try {
            if (!is_string($css) || $css === '' || substr_count(strtolower($css), '@font-face') < 2
                || !apply_filters('wpc_face_tuple_dedupe', true)) {
                return $css;
            }
            $wpc_best2113 = [];
            $wpc_order2113 = [];
            if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $wpc_fm2113, PREG_OFFSET_CAPTURE)) {
                return $css;
            }
            $wpc_prop2113 = function ($blk, $name) {
                return preg_match('/' . $name . '\s*:\s*([^;}]+)/i', $blk, $m)
                    ? strtolower(trim(preg_replace('/\s+/', '', str_replace(['"', "'"], '', $m[1])))) : '';
            };
            foreach ($wpc_fm2113[0] as $wpc_f2113) {
                $blk = $wpc_f2113[0];
                $fam = $wpc_prop2113($blk, 'font-family');
                if ($fam === '') {
                    continue; 
                }
                $key = $fam . '|' . ($wpc_prop2113($blk, 'font-weight') ?: '400')
                    . '|' . ($wpc_prop2113($blk, 'font-style') ?: 'normal')
                    . '|' . ($wpc_prop2113($blk, 'font-stretch') ?: 'normal')
                    . '|' . $wpc_prop2113($blk, 'unicode-range');
                $rank = (stripos($blk, 'woff2') !== false) ? 2 : ((stripos($blk, 'data:') !== false) ? 1 : 0);
                if (!isset($wpc_best2113[$key]) || $rank > $wpc_best2113[$key]['rank']) {
                    $wpc_best2113[$key] = ['rank' => $rank, 'off' => $wpc_f2113[1]];
                    if (!isset($wpc_order2113[$key])) {
                        $wpc_order2113[$key] = $wpc_f2113[1]; 
                    }
                }
            }
            $wpc_keep2113 = [];
            foreach ($wpc_best2113 as $k => $v) {
                $wpc_keep2113[$v['off']] = 1;
            }
            
            $out = '';
            $pos = 0;
            foreach ($wpc_fm2113[0] as $wpc_f2113) {
                $out .= substr($css, $pos, $wpc_f2113[1] - $pos);
                $fam = $wpc_prop2113($wpc_f2113[0], 'font-family');
                if ($fam === '' || isset($wpc_keep2113[$wpc_f2113[1]])) {
                    $out .= $wpc_f2113[0];
                }
                $pos = $wpc_f2113[1] + strlen($wpc_f2113[0]);
            }
            $out .= substr($css, $pos);
            return $out;
        } catch (\Throwable $e) {
            return $css;
        }
    }

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
            
            
            
            
            
            
            
            $wpc_fkey504 = function ($bl) {
                
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

            
            $covered = [];
            if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $crit, $blocks)) {
                foreach ($blocks[0] as $bl) {
                    if (stripos($bl, 'base64') === false) { continue; }
                    
                    
                    
                    
                    
                    
                    if (preg_match('/unicode-range\s*:/i', $bl)) { continue; }
                    list($fam, $lo, $hi, $st) = $wpc_fkey504($bl);
                    if ($fam === '') { continue; }
                    
                    for ($w = $lo; $w <= $hi; $w += 100) {
                        $covered[$fam . '|' . $w . '|' . $st] = 1;
                    }
                }
            }
            if (empty($covered)) { return $crit; }
            
            return preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($covered, $wpc_fkey504) {
                if (stripos($m[0], 'base64') !== false) { return $m[0]; }
                if (!preg_match('/src\s*:\s*[^;}]*url\(\s*["\']?(?!data:)/i', $m[0])) { return $m[0]; }
                list($fam, $lo, $hi, $st) = $wpc_fkey504($m[0]);
                if ($fam === '') { return $m[0]; }
                
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

    
    
    
    
    
    public static function wpc_fb_src148($row, $lf)
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

    public static function wpc_font_metric_overrides(&$critBlob, $wpc_page58 = '')
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
        
        
        
        
        
        
        
        
        $wpc_ev58 = $critBlob . (string) $wpc_page58;
        foreach ($cands as $lc => $fam) {
            if (apply_filters('wpc_fallback_requires_face', true)) {
                $wpc_q58 = preg_quote($fam, '/');
                $wpc_slug58 = preg_quote(str_replace(' ', '-', strtolower($fam)), '/');
                $wpc_plus58 = preg_quote(str_replace(' ', '+', $fam), '/');
                if (!preg_match('/@font-face\s*\{[^}]*font-family\s*:\s*["\']?\s*' . $wpc_q58 . '\b/is', $wpc_ev58)
                    && !preg_match('/href=["\'][^"\']*family=[^"\']*(?:' . $wpc_plus58 . '|' . str_replace(' ', '%20', $wpc_q58) . ')/i', $wpc_ev58)
                    && !preg_match('/[\/-]' . $wpc_slug58 . '[^"\'()\s]*\.(?:woff2?|ttf|otf)/i', $wpc_ev58)) {
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
                
                
                
                
                
                
                $wpc_plf356 = (isset($wpc_tv356['local']) && is_string($wpc_tv356['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $wpc_tv356['local']))
                    ? $wpc_tv356['local']
                    : ((is_array($m) && isset($m['local']) && is_string($m['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $m['local']))
                        ? $m['local'] : 'Arial');
                $wpc_faces_emitted95[$wpc_tk356] = 1;
                $wpc_pws148 = self::wpc_fb_src148(is_array($wpc_tv356) && !empty($wpc_tv356['fallback_stack']) ? $wpc_tv356 : $m, $wpc_plf356);
                $wpc_pwf356 .= '@font-face{font-family:"' . $fb . '";src:' . $wpc_pws148 . ';font-weight:' . $wpc_w356 . ';font-style:' . $wpc_s356 . ';' . $wpc_pd356 . '}';
            }

            if ($decl === '' && $wpc_pwf356 === '') { continue; }

            
            
            if ($decl !== '') {
                $wpc_lf = (isset($m['local']) && is_string($m['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $m['local']))
                    ? $m['local'] : 'Arial';
                if (!isset($wpc_faces_emitted95[$lc])) {
                    $wpc_faces_emitted95[$lc] = 1;
                    $faces .= '@font-face{font-family:"' . $fb . '";src:' . self::wpc_fb_src148($m, $wpc_lf) . ';' . $decl . '}';
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
        try {
            $wpc_wg146 = self::wpc_font_weight_gaps146($wpc_ev58, $tlc);
            if (!empty($wpc_wg146) && function_exists('wpc_cache_first_log') && function_exists('get_transient')) {
                $wpc_wgk146 = 'wpc_fmwg146_' . substr(md5(implode(';', $wpc_wg146)), 0, 12);
                if (!get_transient($wpc_wgk146)) {
                    set_transient($wpc_wgk146, 1, 86400);
                    wpc_cache_first_log('font-metrics-weight-gap', implode(';', $wpc_wg146), '', []);
                }
            }
        } catch (\Throwable $e) {
        }
        if ($faces !== '') {
            $faces = (string) self::wpc_strip_family_faces23($faces, self::wpc_unbacked_fams23($wpc_page58, ''));
        }
        return $faces !== '' ? '<style id="wpc-font-fallbacks">' . $faces . '</style>' : '';
    }

    







    
    
    
    
    
    
    
    public static function wpc_gfonts_link_prune184($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, 'fonts.googleapis.com') === false
                || strpos($html, '@font-face') === false
                || !function_exists('apply_filters') || !apply_filters('wpc_gfonts_link_prune', true)) {
                return $html;
            }
            $wpc_fams184 = [];
            if (preg_match_all('/@font-face\s*\{[^}]*?font-family\s*:\s*["\']?([^;"\'}]+)/is', $html, $wpc_fm184)) {
                foreach ($wpc_fm184[1] as $wpc_f184) {
                    $wpc_fams184[strtolower(trim($wpc_f184))] = 1;
                }
            }
            if (!$wpc_fams184) {
                return $html;
            }
            $wpc_drop184 = 0;
            $out = preg_replace_callback(
                '/<link\b[^>]*rel=(["\'])(?:stylesheet|wpc-(?:mobile|desktop)-stylesheet)\1[^>]*>/i',
                function ($lm) use ($wpc_fams184, &$wpc_drop184) {
                    if (stripos($lm[0], 'fonts.googleapis.com') === false
                        || !preg_match('/[?&]family=([^"\'&]+)/i', $lm[0], $qm)) {
                        return $lm[0];
                    }
                    foreach (explode('|', urldecode($qm[1])) as $wpc_seg184) {
                        $wpc_name184 = strtolower(trim(str_replace('+', ' ', (string) strtok($wpc_seg184, ':'))));
                        if ($wpc_name184 === '' || !isset($wpc_fams184[$wpc_name184])) {
                            return $lm[0];
                        }
                    }
                    $wpc_drop184++;
                    return '';
                },
                $html
            );
            if (is_string($out) && $out !== '' && $wpc_drop184 > 0) {
                if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_gflp184_logged')) {
                    set_transient('wpc_gflp184_logged', 1, 3600);
                    wpc_cache_first_log('gfonts-link-pruned', '', '', ['n' => $wpc_drop184]);
                }
                return $out;
            }
            return is_string($out) && $out !== '' ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    
    
    
    
    
    
    
    
    public static function wpc_font_face_dedupe180($html)
    {
        try {
            if (!is_string($html) || $html === '' || substr_count($html, '@font-face') < 2
                || !function_exists('apply_filters') || !apply_filters('wpc_font_face_dedupe', true)) {
                return $html;
            }
            $wpc_bre180 = '/<style[^>]*\bid=(["\'])((?:wpc-font-fallbacks|wpc-crit-faces|wpc-font-carrier|wpc-font-subsets|wpc-live-faces76[^"\']*|wpc-late-faces))\1[^>]*>(.*?)<\/style>/s';
            $wpc_fre180 = '/@font-face\s*\{[^}]*\}/s';
            $wpc_key180 = function ($face) {
                $fam = preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $face, $m) ? strtolower(trim($m[1])) : '';
                $w = preg_match('/font-weight\s*:\s*([^;}]+)/i', $face, $m) ? strtolower(trim($m[1])) : '400';
                $st = preg_match('/font-style\s*:\s*([^;}]+)/i', $face, $m) ? strtolower(trim($m[1])) : 'normal';
                return $fam . '|' . $w . '|' . $st;
            };
            
            $wpc_subset180 = [];
            if (preg_match_all($wpc_bre180, $html, $wpc_bl180)) {
                foreach ($wpc_bl180[3] as $wpc_bb180) {
                    if (preg_match_all($wpc_fre180, $wpc_bb180, $wpc_fl180)) {
                        foreach ($wpc_fl180[0] as $wpc_f180) {
                            if (stripos($wpc_f180, 'unicode-range') !== false && stripos($wpc_f180, 'data:') !== false) {
                                $wpc_subset180[$wpc_key180($wpc_f180)] = 1;
                            }
                        }
                    }
                }
            }
            $wpc_id180 = function ($face) use ($wpc_key180) {
                $srcs = [];
                if (preg_match_all('/url\(\s*["\']?([^"\')\s]+)/i', $face, $mm)) {
                    foreach ($mm[1] as $u) {
                        $u = strtolower($u);
                        if (preg_match('#/(wp-content/.+)$#', $u, $wpc_pm205)) {
                            $u = $wpc_pm205[1];
                        }
                        $srcs[] = $u;
                    }
                }
                sort($srcs);
                $rng = preg_match('/unicode-range\s*:\s*([^;}]+)/i', $face, $m) ? strtolower(preg_replace('/\s+/', '', $m[1])) : '';
                return md5($wpc_key180($face) . '|' . implode(',', $srcs) . '|' . $rng);
            };
            $wpc_seen180 = [];
            $wpc_sem180 = [];
            $wpc_dropped180 = 0;
            $out = preg_replace_callback($wpc_bre180, function ($bm) use ($wpc_fre180, $wpc_key180, $wpc_id180, $wpc_subset180, &$wpc_seen180, &$wpc_sem180, &$wpc_dropped180) {
                $body = preg_replace_callback($wpc_fre180, function ($fm) use ($wpc_key180, $wpc_id180, $wpc_subset180, &$wpc_seen180, &$wpc_sem180, &$wpc_dropped180) {
                    $face = $fm[0];
                    $norm = md5(preg_replace('/\s+/', ' ', $face));
                    if (isset($wpc_seen180[$norm])) {
                        $wpc_dropped180++;
                        return '';
                    }
                    $wpc_seen180[$norm] = 1;
                    
                    
                    
                    
                    
                    if (stripos($face, 'src') !== false) {
                        $wpc_sid180 = $wpc_id180($face);
                        if (isset($wpc_sem180[$wpc_sid180])) {
                            $wpc_dropped180++;
                            return '';
                        }
                        $wpc_sem180[$wpc_sid180] = 1;
                    }
                    
                    
                    
                    if (stripos($face, 'unicode-range') === false && stripos($face, 'data:') === false
                        && isset($wpc_subset180[$wpc_key180($face)])) {
                        $wpc_dropped180++;
                        return '';
                    }
                    return $face;
                }, $bm[3]);
                if (!is_string($body)) {
                    return $bm[0];
                }
                
                
                
                
                
                
                
                
                
                
                
                static $wpc_frm193 = null;
                if ($wpc_frm193 === null) {
                    $wpc_frm193 = function_exists('get_option') ? get_option('wpc_font_file_ranges193', []) : [];
                    if (!is_array($wpc_frm193)) { $wpc_frm193 = []; }
                }
                if (!empty($wpc_frm193)) {
                    $body = (string) preg_replace_callback($wpc_fre180, function ($fm) use ($wpc_frm193) {
                        $f = $fm[0];
                        if (stripos($f, 'unicode-range') !== false || stripos($f, 'data:') !== false
                            || !preg_match('/src\s*:[^;}]*url\(\s*["\']?([^"\')\s]+)/i', $f, $sm)) { return $f; }
                        $fb = basename((string) parse_url($sm[1], PHP_URL_PATH));
                        if ($fb === '' || empty($wpc_frm193[$fb])) { return $f; }
                        if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $f, $fa)
                            && function_exists('wpc_css_is_icon_font')
                            && wpc_css_is_icon_font(strtolower(trim($fa[1], " \t\"'")))) { return $f; }
                        return (string) preg_replace('/\}\s*$/', 'unicode-range:' . $wpc_frm193[$fb] . ';}', $f, 1);
                    }, $body);
                }
                if (preg_match_all($wpc_fre180, $body, $wpc_ml191)) {
                    $wpc_grp191 = [];
                    foreach ($wpc_ml191[0] as $wpc_f191) {
                        if (!preg_match('/font-weight\s*:\s*([0-9]{1,4}(?:\s+[0-9]{1,4})?)\s*[;}]/i', $wpc_f191, $wpc_wm191)) { continue; }
                        $wpc_sig191 = md5(preg_replace('/\s+/', ' ',
                            preg_replace('/font-weight\s*:\s*[0-9]{1,4}(?:\s+[0-9]{1,4})?\s*;?/i', '', $wpc_f191)));
                        $wpc_grp191[$wpc_sig191][] = [trim($wpc_wm191[1]), $wpc_f191];
                    }
                    foreach ($wpc_grp191 as $wpc_g191) {
                        if (count($wpc_g191) < 2) { continue; }
                        $wpc_lo191 = 10000; $wpc_hi191 = 0;
                        foreach ($wpc_g191 as $wpc_it191) {
                            foreach (preg_split('/\s+/', $wpc_it191[0]) as $wpc_wv191) {
                                $wpc_wv191 = (int) $wpc_wv191;
                                if ($wpc_wv191 >= 1 && $wpc_wv191 <= 1000) {
                                    $wpc_lo191 = min($wpc_lo191, $wpc_wv191);
                                    $wpc_hi191 = max($wpc_hi191, $wpc_wv191);
                                }
                            }
                        }
                        if ($wpc_hi191 <= $wpc_lo191) { continue; }
                        $wpc_car191 = preg_replace('/(font-weight\s*:\s*)[0-9]{1,4}(?:\s+[0-9]{1,4})?/i',
                            '${1}' . $wpc_lo191 . ' ' . $wpc_hi191, $wpc_g191[0][1], 1);
                        if (!is_string($wpc_car191) || $wpc_car191 === '') { continue; }
                        $wpc_nb191 = str_replace($wpc_g191[0][1], $wpc_car191, $body);
                        for ($wpc_x191 = 1; $wpc_x191 < count($wpc_g191); $wpc_x191++) {
                            $wpc_nb191 = str_replace($wpc_g191[$wpc_x191][1], '', $wpc_nb191);
                        }
                        if (is_string($wpc_nb191) && $wpc_nb191 !== '') {
                            $body = $wpc_nb191;
                            $wpc_dropped180 += count($wpc_g191) - 1;
                        }
                    }
                }
                if (trim(preg_replace('#/\\*.*?\\*/#s', '', $body)) === '') {
                    return '';
                }
                return str_replace($bm[3], $body, $bm[0]);
            }, $html);
            if (is_string($out) && $out !== '' && $wpc_dropped180 > 0) {
                if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_ffd180_logged')) {
                    set_transient('wpc_ffd180_logged', 1, 3600);
                    wpc_cache_first_log('font-face-dedupe', '', '', ['dropped' => $wpc_dropped180]);
                }
                return $out;
            }
            return is_string($out) && $out !== '' ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_font_weight_gaps146($evidence, $tableLc)
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

    








    public static function wpc_lcp_img_responsive($html, $url, $want = 'srcset')
    {
        if (!is_string($html) || $html === '' || !is_string($url) || $url === '') { return ''; }
        $file = basename((string) preg_replace('/\?.*$/', '', $url));
        if ($file === '') { return ''; }
        
        $stem = (string) preg_replace('/\.[a-z0-9]+$/i', '', $file);
        $stem = (string) preg_replace('/-\d+x\d+$/', '', $stem);
        if (strlen($stem) < 3) { return ''; }
        
        
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

    
    
    
    
    
    
    
    protected static function wpc_bg_pin_value101($wpc_sel101, $wpc_url101, $wpc_hay101)
    {
        try {
            $wpc_def101 = 'background-image:url("' . esc_url($wpc_url101) . '") !important';
            if (!is_string($wpc_sel101) || !is_string($wpc_hay101) || $wpc_hay101 === '') {
                return $wpc_def101;
            }
            $wpc_tok101 = '';
            if (preg_match('/#([A-Za-z_][\w-]*)/', $wpc_sel101, $wpc_tm101)) {
                $wpc_tok101 = '#' . $wpc_tm101[1];
            } elseif (preg_match_all('/\.([A-Za-z_][\w-]*)/', $wpc_sel101, $wpc_cm101) && !empty($wpc_cm101[1])) {
                $wpc_tok101 = '.' . end($wpc_cm101[1]);
            }
            if ($wpc_tok101 === '') {
                return $wpc_def101;
            }
            $wpc_seen101 = false;
            $wpc_layer101 = '';
            $wpc_short101 = false;
            $wpc_off101 = 0;
            $wpc_q101 = preg_quote(substr($wpc_tok101, 1), '/');
            $wpc_pfx101 = $wpc_tok101[0] === '#' ? '#' : '\.';
            while (preg_match('/[^{}]*' . $wpc_pfx101 . $wpc_q101 . '(?![\w-])[^{}]*\{([^{}]*)\}/', $wpc_hay101, $wpc_rm101, PREG_OFFSET_CAPTURE, $wpc_off101)) {
                $wpc_off101 = $wpc_rm101[0][1] + strlen($wpc_rm101[0][0]);
                $wpc_decl101 = $wpc_rm101[1][0];
                if (preg_match_all('/background-image\s*:\s*([^;}]*)/i', $wpc_decl101, $wpc_bm101)) {
                    foreach ($wpc_bm101[1] as $wpc_v101) {
                        $wpc_seen101 = true;
                        if (stripos($wpc_v101, 'gradient(') !== false && stripos($wpc_v101, 'url(') !== false) {
                            $wpc_layer101 = trim($wpc_v101);
                        }
                    }
                } elseif (preg_match('/background\s*:\s*[^;}]*gradient\([^;}]*/i', $wpc_decl101)) {
                    $wpc_seen101 = true;
                    $wpc_short101 = true;
                }
            }
            if ($wpc_layer101 !== '') {
                if (substr_count(strtolower($wpc_layer101), 'url(') !== 1) {
                    return '';
                }
                $wpc_new101 = preg_replace('/url\(\s*["\']?[^"\')]*["\']?\s*\)/i', 'url("' . esc_url($wpc_url101) . '")', $wpc_layer101, 1);
                if (!is_string($wpc_new101) || $wpc_new101 === '' || strpos($wpc_new101, '}') !== false || strpos($wpc_new101, '<') !== false) {
                    return '';
                }
                return 'background-image:' . rtrim($wpc_new101, '; ') . ' !important';
            }
            if ($wpc_short101) {
                return '';
            }
            
            
            
            
            
            if (!$wpc_seen101 && function_exists('get_transient')) {
                $wpc_ck104 = 'wpc_bgpin104_' . md5($wpc_sel101 . '|' . $wpc_url101);
                $wpc_cv104 = get_transient($wpc_ck104);
                if (is_string($wpc_cv104)) {
                    return $wpc_cv104 === '-' ? '' : $wpc_cv104;
                }
                $wpc_files104 = [];
                if (defined('WP_CONTENT_DIR')
                    && preg_match_all('/<link\b[^>]*href=["\']([^"\']+\.css[^"\']*)["\']/i', $wpc_hay101, $wpc_lm104)) {
                    foreach (array_unique($wpc_lm104[1]) as $wpc_lu104) {
                        if (count($wpc_files104) >= 4) { break; }
                        $wpc_lp104 = (string) parse_url(html_entity_decode($wpc_lu104), PHP_URL_PATH);
                        if (($wpc_pc104 = strpos($wpc_lp104, '/wp-content/')) === false) { continue; }
                        $wpc_lf104 = rtrim(WP_CONTENT_DIR, '/') . rawurldecode(substr($wpc_lp104, $wpc_pc104 + 11));
                        if (strpos($wpc_lf104, '..') === false && @is_readable($wpc_lf104)
                            && (int) @filesize($wpc_lf104) <= 524288) {
                            $wpc_files104[] = $wpc_lf104;
                        }
                    }
                }
                foreach ($wpc_files104 as $wpc_ff104) {
                    $wpc_css104 = (string) @file_get_contents($wpc_ff104);
                    if ($wpc_css104 === '' || strpos($wpc_css104, substr($wpc_tok101, 1)) === false) { continue; }
                    $wpc_sub104 = self::wpc_bg_pin_value101($wpc_sel101, $wpc_url101, '{}' . $wpc_css104);
                    if ($wpc_sub104 !== '' && stripos($wpc_sub104, 'gradient(') !== false) {
                        set_transient($wpc_ck104, $wpc_sub104, 21600);
                        return $wpc_sub104;
                    }
                }
                set_transient($wpc_ck104, '-', 21600);
            }
            if (!$wpc_seen101
                && preg_match('/(?:^|[\s.])(?:et_pb_section|et_pb_row|et_pb_column|elementor-section|elementor-element|wp-block-cover|fl-row|brxe-)/', $wpc_sel101)) {
                if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_bgpin101_log')) {
                    set_transient('wpc_bgpin101_log', 1, 3600);
                    wpc_cache_first_log('lcp-bg-pin-standdown', '', '', ['sel' => substr($wpc_sel101, 0, 80)]);
                }
                return '';
            }
            return $wpc_def101;
        } catch (\Throwable $wpc_e101) {
            return '';
        }
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

    
    
    
    
    
    
    public static function wpc_lcp_preload_in_doc70($html, $url)
    {
        if (!apply_filters('wpc_lcp_preload_doc_gate', true) || !is_string($html) || $html === '') {
            return true; 
        }
        $u = (string) $url;
        
        $p = strrpos($u, '/u:http');
        if ($p !== false) {
            $u = substr($u, $p + 3);
        }
        $path = (string) parse_url($u, PHP_URL_PATH);
        $base = rawurldecode(basename($path));
        
        
        $stem = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base);
        $stem = preg_replace('/-\d{2,4}x\d{2,4}$/', '', (string) $stem);
        if (!is_string($stem) || strlen($stem) < 8) {
            return true; 
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

    


















    public static function wpc_sel_addresses_one($sel, $meta = null, $leg = '', $verdicts = null)
    {
        if (is_array($meta) && array_key_exists('sel_unique', $meta)) {
            $wpc_v729 = (bool) $meta['sel_unique'];
            if (!$wpc_v729 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('reserve-sel-refused', $leg, '', ['why' => 'service:false', 'sel' => substr((string) $sel, 0, 80)]);
            }
            return $wpc_v729;
        }
        
        
        
        
        
        
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
        
        
        
        
        
        
        
        
        if (preg_match('/(?:(?<![\w-])elementor-element-[a-f0-9]{6,8}(?![\w-])|(?<![\w-])et_pb_[a-z]+_\d+(?![\w-]))/i', (string) $sel)) {
            return true;
        }
        
        
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
        
        

        
        
        
        
        
        $wpc_vmap730 = [];
        $wpc_harvest730 = function ($node) use (&$wpc_harvest730, &$wpc_vmap730) {
            if (is_array($node)) {
                if (isset($node['sel']) && is_string($node['sel']) && $node['sel'] !== ''
                    && array_key_exists('sel_unique', $node)) {
                    $wpc_k730 = $node['sel'];
                    
                    
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
                
                
                
                if (!self::wpc_sel_addresses_one($csel, $ce, 'atf_conceal', $wpc_vmap730)) {
                    continue;
                }
                $ch = (int) round($ch);
                if ($ch < 10 || $ch > 2000) {
                    continue;
                }
                
                
                
                
                
                
                
                if (preg_match('/\belementor-(\d{2,10})\b/', $csel, $wpc_tm281) && function_exists('get_post_meta')) {
                    $wpc_tt281 = (string) get_post_meta((int) $wpc_tm281[1], '_elementor_template_type', true);
                    if ($wpc_tt281 === 'header' || $wpc_tt281 === 'popup') {
                        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_clsrh281')) {
                            set_transient('wpc_clsrh281', 1, 3600);
                            wpc_cache_first_log('cls-reserve-header-skip', '', '', ['sel' => $csel, 'type' => $wpc_tt281]);
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

        
        
        
        $wpc_pr356 = [];
        $wpc_praw356 = @is_readable($dir . 'prescriptions.json') ? (string) @file_get_contents($dir . 'prescriptions.json') : '';
        
        
        
        $wpc_av356rl = $wpc_praw356 !== '' ? substr(md5($wpc_praw356), 0, 12) : '';
        if ($wpc_praw356 !== '' && apply_filters('wpc_prescriptions_reserve', true)) {
            $wpc_pj356 = json_decode($wpc_praw356, true);
            if (is_array($wpc_pj356) && isset($wpc_pj356['prescriptions']) && is_array($wpc_pj356['prescriptions'])) {
                $wpc_seen356 = [];
                foreach ($rules as $wpc_r356) {
                    $wpc_seen356[strtolower((string) substr($wpc_r356, 0, (int) strpos($wpc_r356, '{')))] = 1;
                }
                $wpc_known356 = function_exists('wpc_presc_known_classes') ? wpc_presc_known_classes() : [];
                
                
                
                
                
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
                        continue; 
                        
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
                    
                    if (isset($wpc_pp356['width']) && is_numeric($wpc_pp356['width'])
                        && ($isMobile ? (int) $wpc_pp356['width'] >= 768 : (int) $wpc_pp356['width'] < 768)) {
                        continue;
                    }
                    if (isset($wpc_seen356[strtolower($wpc_psel356)])) {
                        continue; 
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
            if (strpos($f, 'data:font/woff2;base64') !== false) { return $m[0]; } 
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
            
            if (preg_match('/menu|navbar|nav-|site-header|elementor-location-header|sticky-header/i', $m[0][0])) {
                continue;
            }
            
            
            $before   = substr($html, 0, $m[0][1]);
            $navOpen  = max((int) strripos($before, '<header'), (int) strripos($before, '<nav'));
            $navClose = max((int) strripos($before, '</header'), (int) strripos($before, '</nav'));
            if ($navOpen > 0 && $navOpen > $navClose) {
                continue;
            }
            
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
                
                $wpc_kf = preg_replace('/font-display\s*:\s*[^;}]+;?/i', '', $wpc_kf);
                $keep[$wpc_ki] = preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $wpc_fd43 . ';', $wpc_kf, 1);
            }
        }

        return '<style id="wpc-gfont-atf">' . implode('', $keep) . '</style>';
    }

    



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
            
            set_transient($key, $faces, !empty($faces) ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
        }
        delete_transient('wpc_gff_warming');
    }

    
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

    
    private static function localFaceWoff2Exists($rawFace)
    {
        if (!preg_match('/url\(\s*[\'"]?([^)\'"]+?\.woff2)/i', $rawFace, $m)) return false;
        $url = $m[1];
        if (strpos($url, WPS_IC_FONTS_URL) === false) return false;
        $path = strtok(str_replace(WPS_IC_FONTS_URL, WPS_IC_FONTS_DIR, $url), '?');
        return is_string($path) && $path !== '' && file_exists($path);
    }


    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_crit_url_align225($css)
    {
        try {
            if (!is_string($css) || $css === '' || empty(self::$zoneName)
                || !apply_filters('wpc_crit_url_align', true) || stripos($css, 'url(') === false) {
                return $css;
            }
            $out = preg_replace_callback('/url\(\s*([\'"]?)(https?:\/\/[^\'")\s]+\.(?:png|jpe?g|gif|webp|avif|svg))(\1)\s*\)/i', function ($m) {
                $u = self::uForCdn($m[2]);
                return is_string($u) && $u !== '' ? 'url(' . $m[1] . $u . $m[3] . ')' : $m[0];
            }, $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    public function wpc_parked_css_combine225($html)
    {
        try {
            if (!apply_filters('wpc_parked_css_combine', true)
                || !defined('WPS_IC_CRITICAL') || !defined('WPS_IC_CRITICAL_URL')
                || !function_exists('home_url') || !defined('ABSPATH')) {
                return $html;
            }
            if (!preg_match_all('/<link\b[^>]*(?:rel|type)=["\']wpc-(mobile|late)-stylesheet["\'][^>]*>/i', $html, $wpc_cm225)) {
                $wpc_cm225 = [[], []];
            }
            $wpc_min225 = (int) apply_filters('wpc_parked_css_combine_min', 4);
            $wpc_home225 = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            
            
            
            
            
            $wpc_zh279 = [];
            if (!empty(self::$zoneName)) { $wpc_zh279[strtolower((string) self::$zoneName)] = 1; }
            foreach (['ic_custom_cname', 'ic_cdn_zone_name'] as $wpc_zk279) {
                $wpc_zv279 = function_exists('get_option') ? strtolower(trim((string) get_option($wpc_zk279))) : '';
                if ($wpc_zv279 !== '') { $wpc_zh279[$wpc_zv279] = 1; }
            }
            
            
            
            
            $wpc_tags230 = [];
            foreach ($wpc_cm225[0] as $wpc_ti225 => $wpc_t225) {
                $wpc_tags230[] = [$wpc_t225, strtolower((string) $wpc_cm225[1][$wpc_ti225])];
            }
            if (preg_match_all('/<link\b[^>]*rel=["\'](?:preload|prefetch)["\'][^>]*>/i', $html, $wpc_pl230)) {
                foreach ($wpc_pl230[0] as $wpc_pt230) {
                    if (preg_match('/as=["\']style["\']/i', $wpc_pt230)
                        && (preg_match('/fetchpriority=["\']low["\']/i', $wpc_pt230) || preg_match('/rel=["\']prefetch["\']/i', $wpc_pt230))
                        && stripos($wpc_pt230, 'onload=') === false) {
                        $wpc_tags230[] = [$wpc_pt230, 'late'];
                    }
                }
            }
            $wpc_parts225 = [];
            foreach ($wpc_tags230 as $wpc_tl230) {
                $wpc_t225 = $wpc_tl230[0];
                $wpc_lane225 = $wpc_tl230[1];
                if (stripos($wpc_t225, 'fonts.googleapis') !== false || stripos($wpc_t225, 'fonts.bunny') !== false) {
                    continue;
                }
                if (preg_match('/media=["\'](?!all["\'])/i', $wpc_t225)) {
                    continue;
                }
                if (!preg_match('/href=["\']([^"\']+)["\']/i', $wpc_t225, $wpc_h225)) {
                    continue;
                }
                $wpc_pu225 = parse_url((string) $wpc_h225[1]);
                $wpc_ho225 = isset($wpc_pu225['host']) ? strtolower((string) $wpc_pu225['host']) : '';
                if ($wpc_ho225 !== '' && $wpc_ho225 !== $wpc_home225
                    && !isset($wpc_zh279[$wpc_ho225]) && substr($wpc_ho225, -10) !== '.zapwp.com') {
                    continue;
                }
                $wpc_pa225 = isset($wpc_pu225['path']) ? (string) $wpc_pu225['path'] : '';
                if (!preg_match('#^/(?:wp-content|wp-includes)/#', $wpc_pa225)
                    || strpos($wpc_pa225, '..') !== false || substr($wpc_pa225, -4) !== '.css') {
                    continue;
                }
                $wpc_fp225 = rtrim(ABSPATH, '/') . $wpc_pa225;
                $wpc_sz225 = @is_readable($wpc_fp225) ? (int) @filesize($wpc_fp225) : 0;
                if ($wpc_sz225 <= 0 || $wpc_sz225 > 524288) {
                    continue;
                }
                $wpc_parts225[$wpc_lane225][] = ['tag' => $wpc_t225, 'fp' => $wpc_fp225, 'web' => $wpc_pa225,
                    'mt' => (int) @filemtime($wpc_fp225), 'u' => (string) $wpc_h225[1]];
            }
            foreach ($wpc_parts225 as $wpc_lane225 => $wpc_lparts225) {
                foreach (self::wpc_combine_runs21($html, $wpc_lparts225) as $wpc_run21) {
                    $html = self::wpc_combine_lane225($html, $wpc_lane225, $wpc_run21, $wpc_min225);
                }
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    
    
    
    
    
    
    
    
    private static function wpc_combine_runs21($html, $parts)
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

    private static function wpc_combine_lane225($html, $wpc_lane225, $wpc_parts225, $wpc_min225)
    {
        try {
            if (count($wpc_parts225) < $wpc_min225) {
                return $html;
            }
            $wpc_sig225 = [];
            foreach ($wpc_parts225 as $wpc_p225) {
                $wpc_sig225[] = $wpc_p225['web'] . ':' . $wpc_p225['mt'] . ':' . $wpc_p225['u'];
            }
            
            
            
            $wpc_key225 = md5($wpc_lane225 . '|bgp261|' . implode('|', $wpc_sig225));
            $wpc_dir225 = rtrim(WPS_IC_CRITICAL, '/') . '/combined/';
            $wpc_file225 = $wpc_dir225 . 'cmb-' . $wpc_key225 . '.css';
            $wpc_keep225 = [];
            if (!@is_readable($wpc_file225)) {
                $wpc_buf225 = '';
                $wpc_tot225 = 0;
                foreach ($wpc_parts225 as $wpc_i225 => $wpc_p225) {
                    $wpc_css225 = (string) @file_get_contents($wpc_p225['fp']);
                    if ($wpc_css225 === '' || stripos($wpc_css225, '@import') !== false) {
                        $wpc_keep225[$wpc_i225] = 1;
                        continue;
                    }
                    $wpc_tot225 += strlen($wpc_css225);
                    if ($wpc_tot225 > 2097152) {
                        return $html;
                    }
                    $wpc_css225 = preg_replace('/@charset[^;]*;/i', '', $wpc_css225);
                    $wpc_base225 = dirname($wpc_p225['web']);
                    $wpc_css225 = preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($mm) use ($wpc_base225) {
                        $v = trim($mm[2]);
                        if ($v === '' || $v[0] === '/' || $v[0] === '#' || preg_match('#^(?:data:|https?:|//)#i', $v)) {
                            return $mm[0];
                        }
                        $full = $wpc_base225 . '/' . $v;
                        $g = 0;
                        while (strpos($full, '/../') !== false && $g++ < 12) {
                            $full = preg_replace('#/[^/]+/\.\./#', '/', $full, 1);
                        }
                        return 'url("' . $full . '")';
                    }, $wpc_css225);
                    if (!is_string($wpc_css225)) {
                        return $html;
                    }
                    $wpc_buf225 .= $wpc_css225 . "\n";
                }
                if (count($wpc_parts225) - count($wpc_keep225) < $wpc_min225) {
                    return $html;
                }
                if (!is_dir($wpc_dir225) && function_exists('wp_mkdir_p')) {
                    @wp_mkdir_p($wpc_dir225);
                }
                
                
                
                
                
                if (function_exists('wpc_used_css_bg_park255')) {
                    $wpc_buf225 = (string) wpc_used_css_bg_park255($wpc_buf225);
                }
                if (@file_put_contents($wpc_file225 . '.tmp', $wpc_buf225, LOCK_EX) === false) {
                    return $html;
                }
                @rename($wpc_file225 . '.tmp', $wpc_file225);
                if (!empty($wpc_keep225)) {
                    @file_put_contents($wpc_file225 . '.keep', wp_json_encode(array_keys($wpc_keep225)), LOCK_EX);
                }
            } else {
                $wpc_kj225 = json_decode((string) @file_get_contents($wpc_file225 . '.keep'), true);
                if (is_array($wpc_kj225)) {
                    $wpc_keep225 = array_fill_keys(array_map('intval', $wpc_kj225), 1);
                }
            }
            $wpc_url225 = rtrim(WPS_IC_CRITICAL_URL, '/') . '/combined/cmb-' . $wpc_key225 . '.css';
            $wpc_n225 = count($wpc_parts225) - count($wpc_keep225);
            $wpc_link225 = '<link rel="wpc-' . $wpc_lane225 . '-stylesheet" id="wpc-cmb225-' . $wpc_lane225 . '" href="' . esc_url($wpc_url225) . '" media="all" data-wpc-n="' . (int) $wpc_n225 . '" />';
            
            
            
            
            
            
            
            
            $wpc_last225 = -1;
            foreach ($wpc_parts225 as $wpc_i225 => $wpc_p225) {
                if (!isset($wpc_keep225[$wpc_i225])) {
                    $wpc_last225 = $wpc_i225;
                }
            }
            foreach ($wpc_parts225 as $wpc_i225 => $wpc_p225) {
                if (isset($wpc_keep225[$wpc_i225])) {
                    continue;
                }
                $html = str_replace($wpc_p225['tag'], $wpc_i225 === $wpc_last225 ? $wpc_link225 : '', $html);
            }
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb225log')) {
                set_transient('wpc_cmb225log', 1, 600);
                wpc_cache_first_log('parked-css-combined', '', '', ['n' => $wpc_n225, 'key' => substr($wpc_key225, 0, 12)]);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function wpc_used_css_droplist_pass($html)
    {
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $wpc_t543 = microtime(true);
        $GLOBALS['wpc_dlbudget543'] = $wpc_t543
            + ((float) apply_filters('wpc_used_css_droplist_budget_ms', 400) / 1000);
        try {
            if (empty(self::$settings['used-css']) || self::$settings['used-css'] != '1'
                || !function_exists('wpc_used_css_path') || !defined('WPS_IC_CRITICAL_URL')) {
                return $html;
            }
            
            
            
            
            
            $wpc_crit112 = (new wps_criticalCss())->criticalExists(true);
            if (empty($wpc_crit112['desktop'])) {
                return $html;
            }
            $wpc_cd112 = dirname($wpc_crit112['desktop']) . '/';
            
            
            
            
            
            if (stripos($wpc_cd112, 'http:') === 0 || stripos($wpc_cd112, 'https:') === 0
                || strpos($wpc_cd112, '://') !== false) {
                return $html;
            }
            
            
            
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


                
                $wpc_disp73 = isset($wpc_sh73['disposition']) ? strtolower(trim((string) $wpc_sh73['disposition'])) : '';
                if ($wpc_disp73 === 'keep' || ($wpc_disp73 === '' && !empty($wpc_sh73['skip']))) {
                    continue;
                }
                if (!empty($wpc_sh73['url'])) {
                    $wpc_bn73 = strtok(basename((string) parse_url((string) $wpc_sh73['url'], PHP_URL_PATH)), '?');
                    if ($wpc_bn73 !== '' && $wpc_bn73 !== false) {
                        
                        
                        $wpc_smap73[strtolower($wpc_bn73)] = 0;
                    }
                }
            }
            
            
            if (empty($wpc_smap73) && function_exists('wpc_used_css_sheets_path')
                && ((function_exists('wp_doing_ajax') && wp_doing_ajax())
                    || (defined('DOING_CRON') && DOING_CRON)
                    || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']))) {
                $wpc_su232 = trim((string) @file_get_contents($wpc_cd112 . 'used_css_sheets_url.txt'));
                if ($wpc_su232 === '' || strpos($wpc_su232, 'http') !== 0) {
                    
                    
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
                        
                        if ($wpc_critfresh112 && !empty($wpc_smap73) && strpos($hm[1], 'wp-content/') !== false && apply_filters('wpc_unlisted_css_late', true)) {
                            $wpc_pt = preg_replace('/(rel)=(["\'])preload\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                            $wpc_pt = preg_replace('/\s+onload=("[^"]*"|\'[^\']*\')/i', '', $wpc_pt);
                            return preg_replace('/\s+as=(["\'])style\1/i', '', $wpc_pt);
                        }
                        return $m[0];
                    }
                    if ($wpc_smap73[$wpc_pbn] === 0) {
                        return ''; 
                    }
                    
                    $wpc_pt = preg_replace('/(rel)=(["\'])preload\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                    $wpc_pt = preg_replace('/\s+onload=("[^"]*"|\'[^\']*\')/i', '', $wpc_pt);
                    $wpc_pt = preg_replace('/\s+as=(["\'])style\1/i', '', $wpc_pt);
                    return $wpc_pt;
                }, $html);
                $html = is_string($wpc_out50) ? $wpc_out50 : $html;
            }
            
            
            
            
            
            
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
                            
                            if ($wpc_critfresh112 && !empty($wpc_smap73) && strpos($hm[1], 'wp-content/') !== false && apply_filters('wpc_unlisted_css_late', true)) {
                                return preg_replace('/(rel|type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                            }
                            return $m[0];
                        }
                        if ($wpc_smap73[$wpc_lbn73] === 0) {
                            return ''; 
                        }
                    } else {
                        return $m[0];
                    }
                }
                
                
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
                    
                    if (strpos($wpc_sid114, 'wp-emoji') !== false || strpos($wpc_sid114, 'global-styles') !== false || self::wpc_consent_family($wpc_sid114)) {
                        return $m[0];
                    }
                    if ($wpc_sid114 === '' || !isset($wpc_smap73[$wpc_sid114])) {
                        return $m[0]; 
                    }
                    if ($wpc_smap73[$wpc_sid114] === 0) {
                        return ''; 
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
        
        
        
        
        
        if (!preg_match('/id=(["\'])wpc-critical-css\1/si', $html)) {
            return $html;
        }


        
        
        
        
        
        
        
        
        
        
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
        $html = $this->wpc_parked_css_combine225($html);
        try {
            $wpc_mvfaces248 = [];
            $wpc_al226 = preg_replace_callback('/(<style\b[^>]*id="wpc-critical-css"[^>]*>)(.*?)(<\/style>)/is', function ($m) use (&$wpc_mvfaces248) {
                $wpc_cb248 = self::wpc_crit_url_align225($m[2]);
                
                
                
                
                
                
                if (apply_filters('wpc_crit_faces_relocate', true) && stripos($wpc_cb248, '@font-face') !== false) {
                    $wpc_cb248 = (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($f) use (&$wpc_mvfaces248) {
                        if (stripos($f[0], 'url(') === false || stripos($f[0], 'url(data:') !== false
                            || stripos($f[0], "url('data:") !== false || stripos($f[0], 'url("data:') !== false) {
                            return $f[0];
                        }
                        $wpc_mvfaces248[] = $f[0];
                        return '';
                    }, $wpc_cb248);
                }
                return $m[1] . $wpc_cb248 . $m[3];
            }, $html);
            $html = is_string($wpc_al226) ? $wpc_al226 : $html;
            
            
            
            
            if (stripos($html, 'data-wpc-endbody="1"') !== false && function_exists('wpc_body_inject809')) {
                $wpc_mv252 = [];
                $wpc_rel252 = preg_replace_callback('/<(?:link|style)\b[^>]*data-wpc-endbody="1"[^>]*?(?:\/>|><\/style>|>)/is', function ($m) use (&$wpc_mv252) {
                    $wpc_mv252[] = $m[0];
                    return '';
                }, $html);
                if (is_string($wpc_rel252) && !empty($wpc_mv252)) {
                    $html = wpc_body_inject809($wpc_rel252, implode('', $wpc_mv252));
                }
            }
            if (!empty($wpc_mvfaces248)) {
                $wpc_pay248 = implode('', $wpc_mvfaces248);
                if (preg_match('/(<style\b[^>]*id="wpc-late-faces"[^>]*>)/i', $html, $wpc_lfm248)) {
                    $html = str_replace($wpc_lfm248[1], $wpc_lfm248[1] . $wpc_pay248, $html);
                } elseif (function_exists('wpc_body_inject809')) {
                    $html = wpc_body_inject809($html, '<style id="wpc-late-faces" type="wpc/late-faces" media="not all">' . $wpc_pay248 . '</style>');
                } else {
                    
                    $html = preg_replace('/(<style\b[^>]*id="wpc-critical-css"[^>]*>)/i', '$1' . $wpc_pay248, $html, 1);
                }
            }
        } catch (\Throwable $e) {
        }


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
                if ($wpc_done >= 20) { 
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

        if (apply_filters('wpc_etcache_live', true) && stripos($fullTag, '/et-cache/') !== false) {
            return $fullTag;
        }

        
        $lazyCss = 'wpc-stylesheet';


        if (strpos($fullTag, 'wpc-critical-css') !== false
            || strpos($fullTag, 'wpc-gfont-atf') !== false
            || strpos($fullTag, 'wpc-elementor-anim-start') !== false
            || strpos($fullTag, 'wpc-atf-reveal') !== false
            || strpos($fullTag, 'wpc-font-fallbacks') !== false
            
            
            || strpos($fullTag, 'wpc-font-carrier') !== false
            
            
            || strpos($fullTag, 'wpc-body-guard') !== false
            || strpos($fullTag, 'wpc-lazy-thumb-bgfix') !== false


            || strpos($fullTag, 'wpc-lcp-bg-authority') !== false
            || strpos($fullTag, 'wpc-bgvideo-contain') !== false


            || strpos($fullTag, 'wpc-cls-reserve') !== false
            || strpos($fullTag, 'wpc-presc-reserve') !== false
            
            
            
            || strpos($fullTag, 'wpc-cv-guard') !== false
            
            || strpos($fullTag, 'wpc-icon-guard') !== false


            || strpos($fullTag, 'wpc-late-faces') !== false
            
            
            
            
            
            
            || strpos($fullTag, 'wpc-fonts-css') !== false
            
            
            || strpos($fullTag, 'wpc-icon-faces') !== false
            
            
            || strpos($fullTag, 'wpc-anim-reveal') !== false
            || strpos($fullTag, 'wpc-emoji-guard') !== false
            || strpos($fullTag, 'wp-emoji') !== false
            || strpos($fullTag, 'global-styles') !== false
            || strpos($fullTag, 'wpc-vars-guard') !== false

            || self::wpc_own_guard_style800($fullTag)

            || self::wpc_consent_family($fullTag)
            || strpos($fullTag, 'wpc-font-subsets') !== false


            
            
            || strpos($fullTag, 'wpc-used-css') !== false
            || strpos($fullTag, 'data-wpc-ucss') !== false
            || (function_exists('wpc_font_localizer_sheet') && wpc_font_localizer_sheet($fullTag))) {
            return $fullTag;
        }

        if (strpos($fullTag, 'rs6') !== false) {
            
            
        }

        
        
        
        
        
        
        
        
        if (apply_filters('wpc_theme_critical_live', true)
            && preg_match('/\b(?:id|class)=["\'][^"\']*(?:critical|et-divi-userfonts|divi-style-parent-inline|off-canvas-hide-on-load|et-vb-global-data)[^"\']*["\']/i', $fullTag)) {
            return $fullTag;
        }

        
        
        
        
        
        if (apply_filters('wpc_etcache_live', true)
            && stripos($fullTag, '/et-cache/') !== false
            && stripos($fullTag, '<link') !== false) {
            return $fullTag;
        }

        
        
        
        
        
        
        
        
        
        if (apply_filters('wpc_inline_geometry_live', true)
            && strlen($fullTag) <= 3072
            && preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $fullTag, $wpc_gb60)
            && preg_match('/(?:^|[,{}\s])#(?:section|row|col|text|image|banner|gap|btn|video|title|divider)[_-]\d/i', (string) $wpc_gb60[1])
            && preg_match('/\b(?:padding|margin|min-height|height|width|top|bottom|left|right|flex-basis|line-height|font-size)(?:-[a-z]+)?\s*:/i', (string) $wpc_gb60[1])) {
            return $fullTag;
        }

        
        
        
        
        
        
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
            
            $pattern = '/<style(\s*[^>]*)\s+type=("|\')text\/css("|\')([^>]*)>/i';

            
            $fullTag = preg_replace($pattern, '<style$1 type=\'' . $lazyCss . '\'$4>', $fullTag);
        } else {
            $fullTag = preg_replace('/<style\b/i', '<style type="' . $lazyCss . '"', $fullTag, 1);
        }

        
        
        
        
        
        
        
        
        if (strpos($fullTag, $lazyCss) !== false
            && apply_filters('wpc_theme_state_eager', true)
            && preg_match('/\[data-(?:[a-z]+-)?theme[=\]]|\[data-dark|\.dark-mode/i', $fullTag)
            && preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $fullTag, $wpc_tb2123)) {
            $wpc_keep2123 = '';
            
            
            $wpc_flat2123 = preg_replace('/@[^{}]*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', '', (string) $wpc_tb2123[1]);
            if (preg_match_all('/([^{}@]+)(\{[^{}]*\})/s', (string) $wpc_flat2123, $wpc_tr2123, PREG_SET_ORDER)) {
                foreach ($wpc_tr2123 as $wpc_r2123) {
                    if (preg_match('/\[data-(?:[a-z]+-)?theme[=\]]|\[data-dark|\.dark-mode/i', $wpc_r2123[1])
                        && strlen($wpc_keep2123) + strlen($wpc_r2123[1]) + strlen($wpc_r2123[2]) <= 32768) {
                        $wpc_keep2123 .= trim($wpc_r2123[1]) . $wpc_r2123[2];
                    }
                }
            }
            if ($wpc_keep2123 !== '' && strpos($wpc_keep2123, '</') === false) {
                $fullTag .= '<style data-wpc-tsv="1">' . $wpc_keep2123 . '</style>';
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

        if (strpos($fullTag, 'preload') !== false || strpos($fullTag, 'prefetch') !== false) {
            return $fullTag;
        }

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();

        if (empty($criticalCSSExists)) {
            return $fullTag;
        }

        
        
        
        if (apply_filters('wpc_etcache_live', true) && stripos($fullTag, '/et-cache/') !== false) {
            return $fullTag;
        }

        
        $lazyCss = 'wpc-stylesheet';

        if (strpos($fullTag, 'wpc-critical-css') !== false || strpos($fullTag, 'wpc-atf-reveal') !== false
            || strpos($fullTag, 'wpc-font-fallbacks') !== false


            || self::wpc_consent_family($fullTag)


            
            
            || strpos($fullTag, 'wpc-used-css') !== false
            || strpos($fullTag, 'data-wpc-ucss') !== false
            || (function_exists('wpc_font_localizer_sheet') && wpc_font_localizer_sheet($fullTag))) {
            return $fullTag;
        }

        if (strpos($fullTag, 'rs6') !== false) {
            
            
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

            
            if (strpos($href[2], 'fonts.googleapis.com/css') !== false) {
                
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
                
                
                $lazyCss = 'wpc-mobile-stylesheet';
            } else {
                $lazyCss = 'wpc-mobile-stylesheet';
            }
        }


        
        


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
                    
                    $fullTag = preg_replace('/(?<![\w.$])' . preg_quote($relTag, '/') . '/', addcslashes($newTag, '\\$'), $fullTag, 1);
                    static $wpc_pl898 = [];
                    if (!empty($href[2]) && count($wpc_pl898) < 20 && !isset($wpc_pl898[$href[2]])
                        && apply_filters('wpc_defer_css_preload', true)) {
                        $wpc_pl898[$href[2]] = 1;
                        $wpc_plco898 = preg_match('/\bcrossorigin(?:\s*=\s*(["\'])[^"\']*\1)?/i', $fullTag, $wpc_plcm898) ? ' ' . $wpc_plcm898[0] : '';
                        $wpc_plmd898 = preg_match('/\bmedia\s*=\s*(["\'])([^"\']+)\1/i', $fullTag, $wpc_plmm898) ? ' media="' . esc_attr($wpc_plmm898[2]) . '"' : '';
                        $fullTag .= '<link rel="prefetch" as="style" href="' . esc_attr($href[2]) . '"' . $wpc_plco898 . $wpc_plmd898 . '>';
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
            
            
            $image[0] = preg_replace('/(["\']|\s|=)\/\/([a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\/[^"\'\s>]*)/', '$1https://$2', $image[0]);
        }

        if (strpos($_SERVER['REQUEST_URI'], 'embed') !== false) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        
        if ($this->defaultExcluded($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        
        if (!self::isImage($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::imageUrlMatchingSiteUrl($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        
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

            
            if (strpos($image, '.png') !== false) {

                if (empty(self::$settings['serve']['png']) || self::$settings['serve']['png'] == '0') {
                    return false;
                }
            }

            
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
            
            preg_match_all('/\s*([a-zA-Z-:]+)\s*=\s*("|\')(.*?)\2/is', $image, $image_tags);

            if (!empty($image_tags[1])) {
                $image_tags[2] = $image_tags[3];
            }

        } else {
            $image = html_entity_decode($image, ENT_NOQUOTES);
            

            


            preg_match_all('/([a-zA-Z_-]+(?:--[a-zA-Z_-]+)*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+)))?/', $image, $matches, PREG_SET_ORDER);

            $attributes = [];
            unset ($matches[0]);

            foreach ($matches as $match) {
                $attrName = $match[1];

                $attrValue = null;
                
                foreach ([2, 3, 4] as $index) {
                    if (!empty($match[$index])) {
                        $attrValue = $match[$index];
                        break; 
                    }
                }

                
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

        
        preg_match_all('/<source[^>]*srcset="([^"]+)"/is', $image, $image_tags);

        
        

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


    

    public function defferFontAwesome($html)
    {
        

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
        
        return $html;
    }

    public function backgroundSizing($html)
    {
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>?/is', [__CLASS__, 'replaceBackgroundImagesInCSS'], $html);
        $html = preg_replace_callback('/data-settings=(["\'])(.*?)\1/i', [__CLASS__, 'replaceBackgroundDataSetting'], $html);
        return $html;
    }

    




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
            
            if ($this->defaultExcluded($url)) {
                return $tag;
            }

            
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
                    
                    if ($this->defaultExcluded($url)) {
                        return $tag;
                    }

                    
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

    
    
    public static function maskMediaScripts($html, &$mask)
    {
        static $gen = 0;
        $gen++;
        $pfx  = '<!--WPC_SCRMASK_' . $gen . '_';   
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

            
            $preferredSrc = '';
            if (isset($original_img_tag['original_tags']['src']) && isset($original_img_tag['original_tags']['data-src'])) {
                
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
                    
                    continue;
                } else if (!is_null($value)) {
                    $newImageElement .= $tag . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" ';
                } else {
                    $newImageElement .= $tag . ' ';
                }
            }
            


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
            
            return $url;
        }

        if ($this->defaultExcluded($url)) {
            return $url;
        }

        
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

            
            if (!empty($tags['class']) && preg_match('/\bwp-image-(\d+)\b/', (string) $tags['class'], $wpc_bfm)
                && function_exists('wp_get_attachment_metadata')) {
                $wpc_bfmeta = wp_get_attachment_metadata((int) $wpc_bfm[1]);
                if (is_array($wpc_bfmeta) && !empty($wpc_bfmeta['width']) && !empty($wpc_bfmeta['height'])) {
                    $tags['width']  = (string) (int) $wpc_bfmeta['width'];
                    $tags['height'] = (string) (int) $wpc_bfmeta['height'];
                    $tags['data-wpc-bf'] = '1';
                    return $tags;
                }
            }

            
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
                $tags['data-wpc-bf'] = '1';
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

        
        $lazyEnabled = self::$lazyEnabled;
        $adaptiveEnabled = self::$adaptiveEnabled;


        if (preg_match('/<img[^>]+src="([^"]+)"[^>]*>/i', $image[0], $matches)) {
            $url = $matches[1];

            if (strpos($url, '/') === 0) {
                $absolute_url = site_url($url);

                $image_path = ABSPATH . $url;

                if (file_exists($image_path)) {
                    
                    $image[0] = preg_replace('/src="' . preg_quote($url, '/') . '"/', 'src="' . $absolute_url . '"', $image[0]);

                    
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
            
            if ($this->defaultExcluded($image[0])) {
                return $image[0];
            }

            
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

        
        if (strpos($image[0], 'cookie') !== false) {
            $image[0] = stripslashes($image[0]);
            return $image[0];
        }


        
        
        $image[0] = preg_replace('/\bfetchpriority=(?:"[^"]*"|\'[^\']*\')\s*/si', '', $image[0]);
        
        $image[0] = preg_replace('/\bdecoding="[^"]*"\s*/si', '', $image[0]);

        if (!empty(self::$settings['remove-srcset']) && self::$settings['remove-srcset'] == '1') {
            $image[0] = preg_replace('/\bsrcset="[^"]*"\s*/si', '', $image[0]);
            $image[0] = preg_replace('/\bsizes="[^"]*"\s*/si', '', $image[0]);
        }


        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);


        $original_img_tag['original_tags'] = self::wpc_backfill_img_dimensions($original_img_tag['original_tags']);

        if (!empty($original_img_tag['original_tags']['src'])) {
            
            if (strpos($original_img_tag['original_tags']['src'], ' ') !== false || strpos($original_img_tag['original_tags']['src'], '%20') !== false) {
                return $image[0];
            }
        }

        


        $image_source = '';
        if (!empty($original_img_tag['original_tags']['src'])) {
            $image_source = $original_img_tag['original_tags']['src'];
        } else {
            if (!empty($original_img_tag['original_tags']['data-src'])) {
                $image_source = $original_img_tag['original_tags']['data-src'];
            } elseif (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                $image_source = $original_img_tag['original_tags']['data-cp-src'];
            } elseif (!empty($original_img_tag['original_tags']['data-oi'])) {
                
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

        


        $originalSizeTags = false;
        if (!empty($original_img_tag['original_tags']['width'])) {
            $size = [];
            $size[0] = $original_img_tag['original_tags']['width'];
            $size[1] = $original_img_tag['original_tags']['height'];
            $originalSizeTags = true;
        } else {
            $size = self::get_image_size($image_source);
        }

        
        $source_svg = 'data:image/svg+xml;base64,' . base64_encode(((int) $size[0] > 0 && (int) $size[1] > 0)
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size[0] . '" height="' . $size[1] . '"><path d="M2 2h' . $size[0] . 'v' . $size[1] . 'H2z" fill="#fff" opacity="0"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

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
        
        
        
        
        
        
        
        
        
        $wpc_frg264 = (bool) preg_match('/(?:^|[^a-z0-9])rs[-_]|revslider|rev_slider|lgx_app|dynamic-image|breakdance/', $lowerClass);
        $wpc_sw264 = strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'swiper') !== false;
        if ($wpc_frg264 || ($wpc_sw264 && !apply_filters('wpc_park_slider_imgs', true))) {
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


        
        if (!$isSlider && !empty($lazyEnabled) && $lazyEnabled == '1' && !self::$lazyOverride) {

            if ($isLogo) {
                
                $logoWidth = $this::getCurrentMaxWidth('logo');
                

                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $logoWidth . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wps-ic-logo wpc-excluded-adaptive';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else if (!$wpcHidden717 && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                
                
                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth('logo') . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wpc-excluded-adaptive wpc-lazy-skipped1';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else {
                if ($wpcHidden717 || self::$lazyLoadedImages > self::$lazyLoadedImagesLimit) {
                    
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = $source_svg;
                    $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wps-ic-lazy-image';
                    $original_img_tag['additional_tags']['loading'] = 'lazy';
                } else {
                    
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, true) . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, true) . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wpc-excluded-adaptive wpc-lazy-skipped2';
                    $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                    unset($original_img_tag['additional_tags']['data-wpc-loaded']);
                }

                
                if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                    $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['data-src'];
                }
            }
        } else {
            
            if (!$isSlider && !empty($adaptiveEnabled) && $adaptiveEnabled == '1') {
                $original_img_tag['src'] = $source_svg;
                $original_img_tag['additional_tags']['class'] = 'wps-ic-cdn';

                


                if ($isLogo || strpos($lowerImageUrl, 'logo') !== false) {
                    
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                } else {
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = $source_svg;
                    $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);

                    
                    if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                        $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['data-src'];
                    }
                }
            } else {
                
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

                    
                    if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                        $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['data-src'];
                    }
                } else {
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);

                    
                    if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                        $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['src'];
                    }
                }
            }
        }


        
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
                    
                    
                    
                    
                    
                    
                    
                    $wpc_ct337 = self::wpc_census_rung_targets($image_source);
                    if (!empty($wpc_ct337) && apply_filters('wpc_lcp_measured_width', true)) {
                        $wpc_cap337 = (int) max($wpc_ct337);
                        if ($wpc_cap337 >= 100 && $wpc_cap337 < $fallbackWidth) {
                            $fallbackWidth = $wpc_cap337;
                        }
                    }
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $fallbackWidth . '/u:' . self::uForCdn($image_source);
                    $wpc_cr337 = '';
                    if (!empty($wpc_ct337) && apply_filters('wpc_lcp_measured_width', true)) {
                        $wpc_cl337 = [];
                        foreach ($wpc_ct337 as $wpc_w337) {
                            $wpc_w337 = (int) $wpc_w337;
                            if ($wpc_w337 >= 100) {
                                $wpc_cl337[$wpc_w337] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $wpc_w337
                                    . '/u:' . self::uForCdn($image_source) . ' ' . $wpc_w337 . 'w';
                            }
                        }
                        ksort($wpc_cl337);
                        $wpc_cr337 = implode(', ', $wpc_cl337);
                    }
                    $original_img_tag['original_tags']['srcset'] = $wpc_cr337 !== ''
                        ? $wpc_cr337
                        : self::buildLcpSrcset($image_source, !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0);
                    
                    if ($wpc_cr337 !== '') {
                        $wpc_ms337 = self::wpc_census_slot_sizes($image_source, '');
                        if (is_string($wpc_ms337) && $wpc_ms337 !== '') {
                            $original_img_tag['original_tags']['sizes'] = $wpc_ms337;
                        }
                    }


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
                        
                        $new_sizes = $auto_prefix . '(max-width: ' . $imgWidth . 'px) 100vw, ' . $imgWidth . 'px';
                    } else {
                        
                        


                        $maxW_lcp  = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                        $content_w = function_exists('wpc_get_theme_content_width') ? wpc_get_theme_content_width() : 0;
                        $cap_lcp   = $content_w > 0 ? $content_w : min(1200, max(400, $maxW_lcp));
                        
                        
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
                


                if (apply_filters('wpc_lcp_lazy', false, $image_source)) {
                    $original_img_tag['additional_tags']['loading'] = 'lazy';
                } else {
                    
                    
                    if (!empty($original_img_tag['original_tags']['loading'])) {
                        $original_img_tag['original_tags']['loading'] = 'eager';
                    } else {
                        $original_img_tag['additional_tags']['loading'] = 'eager';
                    }
                    
                    
                    
                    
                    
                    if (apply_filters('wpc_lcp_fetchpriority', true)) {
                        $original_img_tag['additional_tags']['fetchpriority'] = 'high';
                    }
                }
            } else {
                
                $original_img_tag['original_tags']['class'] .= ' wpc-excluded-adaptive wpc-lazy-skipped3';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
            }
            unset($original_img_tag['additional_tags']['data-wpc-loaded'], $original_img_tag['original_tags']['data-src'], $original_img_tag['data-src']);
        }


        
        
        
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

        
        if (empty($originalSizeTags)) {
            if (isset($maxWidth) && $maxWidth > 1 && !empty($original_img_tag['original_tags']['width']) && !empty($original_img_tag['original_tags']['height'])) {
                $originalWidth = $original_img_tag['original_tags']['width'];
                $originalHeight = $original_img_tag['original_tags']['height'];
                $original_img_tag['original_tags']['width'] = $maxWidth;
                $original_img_tag['original_tags']['height'] = round(($originalHeight / $originalWidth) * $maxWidth);
            }
        }

        
        if (empty($originalSizeTags)) {
            if (empty(self::$settings['add-image-sizes']) || self::$settings['add-image-sizes'] == '0') {
                unset($original_img_tag['original_tags']['width'], $original_img_tag['original_tags']['height']);
            }
        } else {
            
            $original_img_tag['original_tags']['wpc-size'] = 'preserve';
        }


        if ($adaptiveEnabled == '0') {
            $original_img_tag['original_tags']['class'] .= ' wpc-excluded-adaptive';
            $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
        }


        
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
            
            $original_img_tag['original_tags']['data-srcset'] = $this->cdnSrcsetOnly($original_img_tag['original_tags']['data-srcset']);
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('LCP_SRCSET_PRESERVED', 'bypassed rewriteSrcset mobile-bail for ' . basename(parse_url($image_source, PHP_URL_PATH) ?: $image_source));
            }
        } else {
            
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
                    
                    $original_img_tag['src'] = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $ea_clean);
                }
            }
        }

        $build_image_tag = '<img ';

        
        unset($original_img_tag['original_tags']['fetchpriority'], $original_img_tag['original_tags']['decoding']);
        
        unset($original_img_tag['original_tags']['data-bricks-logo']);


        
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
            
            if (!empty($original_img_tag['original_src'])) {
                $original_img_tag['src'] = $original_img_tag['original_src'];
            } elseif (!empty($original_img_tag['data-src'])) {
                $original_img_tag['src'] = $original_img_tag['data-src'];
            }
        }

        



        if (!empty($lazyEnabled) && $lazyEnabled == '1') {
            if (self::$excludes_class->isLazyExcluded($image_source, $original_img_tag['original_tags']['class'])) {
                
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

        


        if (strpos(strtolower($original_img_tag['original_tags']['class']), 'rs-lazyload') !== false || strpos(strtolower($original_img_tag['original_tags']['class']), 'rs') !== false || strpos(strtolower($image_source), 'logo') !== false || strpos(strtolower($original_img_tag['class']), 'logo') !== false) {
            $logoSrc = $original_img_tag['original_tags']['src'];

            
            if (strpos($logoSrc, '//') === 0 && strpos($logoSrc, 'https://') !== 0 && strpos($logoSrc, 'http://') !== 0) {
                $logoSrc = 'https:' . $logoSrc;
            }

            $build_image_tag .= 'src="' . $logoSrc . '" ';
        } else {

            if (!empty($lazyEnabled) && $lazyEnabled == '1') {
                
                
                
                
                
                
                
                
                
                
                
                
                $wpc_frag262 = (bool) preg_match('/(?:^|[^a-z0-9])rs[-_]|rs-lazyload|revslider|rev_slider|lgx_app|dynamic-image|breakdance/', $lowerClass);
                $wpc_slid262 = (strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'swiper') !== false)
                    && !apply_filters('wpc_park_slider_imgs', true);
                $wpc_vp227 = (!$skipLazy && !$isLogo
                    && self::$lazyLoadedImages > self::$lazyLoadSkipFirstImages
                    && !self::$lazyOverride && !self::isExcludedFrom('lazy', $image_source)
                    && !$wpc_frag262 && !$wpc_slid262
                    && strpos((string) $original_img_tag['original_tags']['class'], 'wpc-lcp-optimized') === false
                    && !empty($source_svg) && strpos((string) $source_svg, 'data:image/svg+xml') === 0
                    && empty($original_img_tag['data-src'])
                    && apply_filters('wpc_viewport_true_park', true, $image_source));
                if ($wpc_vp227) {
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
                
                $fallbackTag = preg_replace('#(://[^"\'>\s]*/wp):1/#', '$1:0/', $build_image_tag);

                
                $sourceSrcset = '';
                if (preg_match('/(data-)?srcset="([^"]*)"/', $build_image_tag, $srcsetMatch)) {
                    $srcsetAttr = $srcsetMatch[1] ? 'data-srcset' : 'srcset';
                    $sourceSrcset = ' ' . $srcsetAttr . '="' . $srcsetMatch[2] . '"';
                }

                
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
                                
                                $wpc_se15 = strtolower((string) pathinfo($cleanWebpSingle, PATHINFO_EXTENSION));
                                $singleWebpSrc = 'https://' . self::$zoneName . str_replace($webpSiteHostS, '', $natWebpSingle)
                                    . ($wpc_se15 !== '' && $wpc_se15 !== 'webp' ? self::src_hint_qs($wpc_se15) : '');
                            }
                        }
                        $sourceSrcset = ' ' . $srcAttrName . '="' . $singleWebpSrc . '"';
                    }
                }


                


                $wpc_cinv352 = false;
                if (!empty($image_source)) {
                    $wpc_msz131 = self::wpc_census_slot_sizes($image_source, $build_image_tag);
                    if ($wpc_msz131 !== '') {
                        $wpc_cinv352 = true; 
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
                            
                            $avif_src_transcodable = (bool) preg_match('/\.(jpe?g|png)$/i', $cleanSource)
                                || ($wpc_webp_ok129 && (bool) preg_match('/\.webp$/i', $cleanSource));
                        }
                    }


                    $src_hint_ext = '';
                    if (self::src_hint_enabled()) {
                        
                        
                        
                        $sh_src = !empty($image_source) ? (string) $image_source
                            : (!empty($original_img_tag['src']) ? (string) $original_img_tag['src'] : '');
                        if ($sh_src !== '') {
                            $sh_ux = strtolower((string) pathinfo((string) parse_url($sh_src, PHP_URL_PATH), PATHINFO_EXTENSION));
                            if (in_array($sh_ux, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $src_hint_ext = $sh_ux;
                        }
                        
                        
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
                            
                            
                            if ($asp_w <= 0 || $asp_h <= 0) {
                                foreach (array('original_tags', 'additional_tags') as $asp_bag) {
                                    if (!empty($original_img_tag[$asp_bag]['width']) && !empty($original_img_tag[$asp_bag]['height'])) {
                                        $asp_w = (int) $original_img_tag[$asp_bag]['width'];
                                        $asp_h = (int) $original_img_tag[$asp_bag]['height'];
                                        break;
                                    }
                                }
                            }
                            
                            if ($asp_w <= 0 || $asp_h <= 0) {
                                foreach (explode(',', (string) $original_img_tag['original_srcset']) as $asp_sp) {
                                    if (preg_match('#-(\d+)x(\d+)\.(?:jpe?g|png|webp|avif)#i', trim($asp_sp), $asp_m)) {
                                        $asp_w = (int) $asp_m[1]; $asp_h = (int) $asp_m[2]; break;
                                    }
                                }
                            }
                            if ($asp_w > 0 && $asp_h > 0) $avif_aspect_meta = ['width' => $asp_w, 'height' => $asp_h];
                            
                            
                            
                            
                            
                            
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


                        
                        

                        if (self::wpc_natural_nw()) {


                            $avif_nw_entries = [];
                            $wpc_nw_have130 = [];
                            foreach (self::wpc_nw_widths($original_img_tag, $avif_src_w_cap) as $nw_w) {
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $nw_w . 'w';
                                $avif_queue_w($nw_w);
                                $wpc_nw_have130[(int) $nw_w] = 1;
                            }

                            


                            foreach (self::wpc_census_rung_targets($cleanSource) as $wpc_ct130) {
                                $wpc_ct130 = (int) $wpc_ct130;
                                if ($wpc_ct130 < 48 || isset($wpc_nw_have130[$wpc_ct130])) { continue; }
                                if ($avif_src_w_cap > 0 && $wpc_ct130 > $avif_src_w_cap) { continue; }
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $wpc_ct130, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $wpc_ct130 . 'w';
                                $avif_queue_w($wpc_ct130);
                                $wpc_nw_have130[$wpc_ct130] = 1;
                            }


                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            if ($avif_src_w_cap > 0 && !self::wpc_census_rung_targets($cleanSource)) {
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
                                
                                
                                if ($avif_src_w_cap > 0) $effective_max_uni = min($effective_max_uni, $avif_src_w_cap);
                                
                                $ladder_uni = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];
                                
                                foreach ($existing_widths_in_avif as $ww => $_) {
                                    $ladder_uni[] = (int) $ww * 2;
                                }


                                foreach (self::wpc_census_rung_targets($cleanSource) as $wpc_ct125) {
                                    if ((int) $wpc_ct125 >= 48) { $ladder_uni[] = (int) $wpc_ct125; }
                                }
                                
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
                                
                                $ladder_uni = array_values(array_unique(array_map(function ($w) use ($effective_max_uni) {
                                    return min($w, $effective_max_uni);
                                }, $ladder_uni)));
                                sort($ladder_uni);
                                
                                foreach ($ladder_uni as $w_uni) {
                                    if ($w_uni <= 0) continue;
                                    if (isset($existing_widths_in_avif[$w_uni])) continue;
                                    $existing_widths_in_avif[$w_uni] = true;
                                    
                                    
                                    $base_url_uni = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                    $base_no_ext_uni = preg_replace('/\.(jpe?g|png|webp)$/i', '', $base_url_uni);
                                    $natural_url_uni = self::natural_ladder_url($base_no_ext_uni, $w_uni, $avif_aspect_meta, 'avif');
                                    list($natural_url_uni, $natural_path_uni) = self::recoverAdaptiveVariant($natural_url_uni, $base_no_ext_uni, $w_uni, 'avif');
                                    
                                    
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
                                            || (self::picture_avif_natural_full_ok() && $avif_src_transcodable); 
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

                    if ($webp_nw_cap > 0 && !self::wpc_census_rung_targets($cleanSource)) {
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
                                
                                
                                $width = (int) preg_replace('/[^\d]/', '', (string) $descriptor);
                                if ($width <= 0) $width = 1;
                                $u_src = $webp_original_u_url !== '' ? $webp_original_u_url : $jpgUrl;
                                $u_src_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src);
                                $webpEntries[] = $webpZoneBase . '/q:i/r:0/wp:1/w:' . $width . '/u:' . self::uForCdn($u_src_via_cdn) . ' ' . $descriptor;
                            }
                        }
                    }

                    
                    
                    $final_srcset_webp = isset($original_img_tag['original_tags']['srcset'])
                        ? (string) $original_img_tag['original_tags']['srcset']
                        : '';

                    
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
                            
                            
                            $extra_wp_is_wxh = (bool) preg_match('/-\d+x\d+\.webp$/i', $natural_url_webp);

                            if (@file_exists($natural_path_webp)) {
                                $pathPart_extra_wp = str_replace($webpSiteHost, '', $natural_url_webp);
                                $webpEntries[] = $webpZoneBase . $pathPart_extra_wp . self::src_hint_qs($src_hint_ext, true) . ' ' . $extra_width_wp . 'w';
                            } else {


                                if (self::picture_webp_natural_ok() && $extra_wp_is_wxh) {
                                    $pathPart_extra_wp = str_replace($webpSiteHost, '', $natural_url_webp);
                                    $webpEntries[] = $webpZoneBase . $pathPart_extra_wp . self::src_hint_qs($src_hint_ext) . ' ' . $extra_width_wp . 'w';
                                } else {
                                    
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
                        
                        if ($avif_src_w_cap > 0) $effective_max_uni_wp = min($effective_max_uni_wp, $avif_src_w_cap);
                        $ladder_uni_wp = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];
                        foreach ($existing_widths_in_webp as $ww_wp => $_) {
                            $ladder_uni_wp[] = (int) $ww_wp * 2;
                        }
                        
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
                            
                            
                            $uni_wp_is_wxh = (bool) preg_match('/-\d+x\d+\.webp$/i', $natural_url_uni_wp);
                            if (@file_exists($natural_path_uni_wp)) {
                                $pathPart_uni_wp = str_replace($webpSiteHost, '', $natural_url_uni_wp);
                                $webpEntries[] = $webpZoneBase . $pathPart_uni_wp . self::src_hint_qs($src_hint_ext, true) . ' ' . $w_uni_wp . 'w';
                            } else {


                                if (self::picture_webp_natural_ok() && $uni_wp_is_wxh) {
                                    $pathPart_uni_wp = str_replace($webpSiteHost, '', $natural_url_uni_wp);
                                    $webpEntries[] = $webpZoneBase . $pathPart_uni_wp . self::src_hint_qs($src_hint_ext) . ' ' . $w_uni_wp . 'w';
                                } else {
                                    
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
                            
                            
                            if ($webp_full_src && strpos((string) $webp_full_src, $webpSiteHost) === 0) {
                                $webp_full_url  = preg_replace('/\.(jpe?g|png|avif)$/i', '.webp', preg_replace('/\?.*$/', '', $webp_full_src));
                                $webp_full_disk = str_replace($webpSiteUrl, trailingslashit(ABSPATH), $webp_full_url);
                                
                                
                                $webp_native_h_ceil = (is_array($webp_meta_ceil) && !empty($webp_meta_ceil['height'])) ? (int) $webp_meta_ceil['height'] : 0;
                                $webp_full_reach = (@file_exists($webp_full_disk)
                                        && self::picture_variant_dims_ok($webp_full_disk, $webp_native_w, $webp_native_h_ceil))
                                    || self::picture_webp_natural_full_ok(); 
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
                                
                                if ($w > 5 && preg_match('#^(.*)-(\d+)x(\d+)$#', $noext, $d) && (int) $d[2] > 0) {
                                    $sw = (int) $d[2]; $sh = (int) $d[3];
                                    $h  = (int) round($w * $sh / $sw);
                                    if ($h > 0) {
                                        return 'https://' . self::$zoneName . $d[1] . '-' . $w . 'x' . $h . '.' . $ext;
                                    }
                                }

                                
                                


                                
                                
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
                    
                } elseif ($tag == 'data-src') {
                    $src = $value;

                    $webp = '/wp:' . self::$webp;
                    if (self::isExcludedFrom('webp', $src)) {
                        $webp = '/wp:0';
                    }

                    
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
        
        
        
        
        
        
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_true_aspect934')) {
            $wpc_ta229 = wps_rewriteLogic::wpc_true_aspect934($url);
            if (is_array($wpc_ta229) && !empty($wpc_ta229['width']) && !empty($wpc_ta229['height'])) {
                return [(int) $wpc_ta229['width'], (int) $wpc_ta229['height']];
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
            
            
            return '';
        }

        $newSrcSet = '';

        preg_match_all('/((https?\:\/\/|\/\/)[^\s]+\S+\.(jpg|jpeg|png|gif|svg|webp))\s(\d{1,5}+[wx])/si', $srcset, $srcset_links);

        
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

        
        $originalLooksResized = false;
        $originalWidthFromName = 0;

        if (!empty($originalSrc)) {
            if (preg_match('/-(\d{1,5})x(\d{1,5})\.(jpg|jpeg|png|gif|webp)$/i', $originalSrc, $m)) {
                $originalLooksResized = true;
                $originalWidthFromName = (int)$m[1];
            }
        }

        
        $fullSrc = $originalSrc;

        
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

                
                $isXDescriptor = (strpos($srcset_width, 'x') !== false);

                if ($isXDescriptor) {
                    $hasXDescriptor = true;
                    $width_val = (int)str_replace('x', '', $srcset_width);
                    $extension = 'x';
                } else {
                    $width_val = (int)str_replace('w', '', $srcset_width);
                    $extension = 'w';
                }

                
                if (strpos($srcset_url, self::$zoneName) !== false) {
                    $newSrcSet .= $srcset_url . ' ' . $width_val . $extension . ', ';
                    continue;
                }

                
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

                
                $newSrcSet .= self::$apiUrl . '/r:0' . $webp . '/w:' . self::getCurrentMaxWidth($width_url, self::isExcludedFrom('adaptive', $srcset_url)) . '/u:' . self::uForCdn($srcset_url) . ' ' . $srcsetWidthExtension . ', ';

                
                if (self::$settings['retina-in-srcset'] == '1' && !empty($fullSrc)) {
                    $retinaWidth = (int)$width_url * 2;


                    if ($retina_native_w <= 0 || $retinaWidth <= $retina_native_w) {
                        $newSrcSet .= self::$apiUrl . '/r:1' . $webp . '/w:' . self::getCurrentMaxWidth($retinaWidth, self::isExcludedFrom('adaptive', $fullSrc)) . '/u:' . self::uForCdn($fullSrc) . ' ' . ($retinaWidth . 'w') . ', ';
                    }
                }
            }

            


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
        
        if (preg_match('/\s480w/', $srcset)) {
            return $srcset;
        }

        
        preg_match_all('/w:(\d+)/si', $srcset, $w_matches); 
        preg_match_all('/(\S+)\s(\d+)w/si', $srcset, $srcset_matches); 

        $w_widths = array_map('intval', $w_matches[1]); 
        $srcset_widths = array_map('intval', $srcset_matches[2]);

        
        $nearest = null;
        foreach ($srcset_widths as $width) {
            if ($width > 480 && ($nearest === null || $width < $nearest)) {
                $nearest = $width;
            }
        }

        
        $nearest_w = null;
        foreach ($w_widths as $w_width) {
            if ($w_width > 480 && ($nearest_w === null || $w_width < $nearest_w)) {
                $nearest_w = $w_width;
            }
        }

        
        if ($nearest !== null) {
            preg_match('/(.*\s)' . $nearest . 'w/', $srcset, $matches);
            if (!empty($matches)) {
                $url_pattern = $matches[1];
                
                $new_480w_entry = $url_pattern . '480w';

                
                $srcset = str_replace($url_pattern . $nearest . 'w', $new_480w_entry . ', ' . $url_pattern . $nearest . 'w', $srcset);
            }
        }

        
        if ($nearest_w !== null) {
            
            preg_match('/(.*w:)' . $nearest_w . '(.*)/', $srcset, $url_matches);
            if (!empty($url_matches)) {
                $before_w = $url_matches[1];
                $after_w = $url_matches[2];

                
                $new_url = str_replace('w:' . $nearest_w, 'w:480', $url_matches[0]);

                
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

            
            if (!preg_match('/^\s*(\S+)(?:\s+(.+))?\s*$/', $candidate, $m)) {
                $rebuilt[] = $candidate;
                continue;
            }

            $url = trim($m[1]);
            $descriptor = !empty($m[2]) ? trim($m[2]) : '';

            
            if (strpos($url, self::$zoneName) !== false) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            
            if ($this->defaultExcluded($url) || self::isExcluded($url) || self::isExcludedFrom('cdn', $url)) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            
            if (!self::isImage($url)) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            
            if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::imageUrlMatchingSiteUrl($url)) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            
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