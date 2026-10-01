<?php

class wps_ic_excludes extends wps_ic
{

    private static $defaultDelayJSExcludes;
    private static $defaultCombineJSExcludes;
    private static $defaultCombineCSSExcludes;
    private static $defaultCriticalCSSExcludes;
    private static $defaultLazyExcludes;
    private static $defaultWebpExcludes;
    private static $defaultAdaptiveExcludes;
    private static $excludesDelayJSOption;
    private static $excludesDelayJSOptionV3;
    private static $defaultDelayJSExcludesV3;
    private static $excludesCombineJSOption;
    private static $excludesCombineCSSOption;
    private static $excludesToFooterOption;
    private static $excludesLazyOption;
    private static $excludesWebpOption;
    private static $excludesAdaptiveOption;
    private static $pageExcludesFiles;
    private static $userLastLoadScript;
    private static $userDeferScript;


    // New
    private static $excludesCriticalCSSOption;
    private static $excludesOption;

    public function __construct()
    {
        global $post;
        self::$excludesOption = get_option('wpc-excludes');
        self::$settings = wps_ic::$settings;

        if (empty($post->ID)) {
            $home_url = home_url();
            $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            if ($home_url === $current_url) {
                $id = 'home';
            } else {
                $id = '';
            }
        } else {
            $id = $post->ID;
        }

        if (!empty($id)) {
            self::$pageExcludesFiles = !empty(self::$excludesOption['page_excludes_files'][$id]) ? self::$excludesOption['page_excludes_files'][$id] : [];
            self::$excludesDelayJSOption = !empty(self::$excludesOption['delay_js']) ? self::$excludesOption['delay_js'] : [];
            // The one Delay JS exclude list. Read through the fold so a request that runs before
            // the stored fold (wpc_delay_excludes_migrate, init:4) already serves the one list.
            $delayExcludes = function_exists('wpc_delay_excludes_fold') ? wpc_delay_excludes_fold(self::$excludesOption) : self::$excludesOption;
            self::$excludesDelayJSOptionV3 = !empty($delayExcludes['delay_js_v3']) ? $delayExcludes['delay_js_v3'] : [];
            self::$excludesCombineJSOption = !empty(self::$excludesOption['combine_js']) ? self::$excludesOption['combine_js'] : [];
            self::$excludesCombineCSSOption = !empty(self::$excludesOption['css_combine']) ? self::$excludesOption['css_combine'] : [];
            self::$excludesCriticalCSSOption = !empty(self::$excludesOption['critical_css']) ? self::$excludesOption['critical_css'] : [];
            self::$excludesToFooterOption = !empty(self::$excludesOption['exclude-scripts-to-footer']) ? self::$excludesOption['exclude-scripts-to-footer'] : [];
            self::$excludesLazyOption = !empty(self::$excludesOption['lazy']) ? self::$excludesOption['lazy'] : [];
            self::$excludesAdaptiveOption = !empty(self::$excludesOption['adaptive']) ? self::$excludesOption['adaptive'] : [];
            self::$excludesWebpOption = !empty(self::$excludesOption['webp']) ? self::$excludesOption['webp'] : [];
            self::$userLastLoadScript = !empty(self::$excludesOption['lastLoadScript']) ? self::$excludesOption['lastLoadScript'] : [];
            self::$userDeferScript = !empty(self::$excludesOption['deferScript']) ? self::$excludesOption['deferScript'] : [];
        } else {
            self::$excludesDelayJSOption = [];
            self::$excludesCombineJSOption = [];
            self::$excludesCombineCSSOption = [];
            self::$excludesCriticalCSSOption = [];
            self::$excludesToFooterOption = [];
            self::$excludesLazyOption = [];
            self::$excludesAdaptiveOption = [];
            self::$excludesWebpOption = [];
        }

        self::$defaultLazyExcludes = ['show-on-hover'];

        self::$defaultAdaptiveExcludes = [

        ];

        self::$defaultWebpExcludes = [

        ];

        self::$defaultDelayJSExcludes = ['gtranslate', 'gformRedirect()', 'wpgb_settings', 'latepoint_helper', 'wc-order-attribution-js-extra', 'mailchimp_public_data', 'porto-theme-js-extra', 'porto-live-search-js-extra', 'yith-wcan-shortcodes-js-extra', 'jqueryParams', '/plugins/elementor-pro/assets/js/page-transitions', 'hbspt.forms', 'js.hsforms', 'var directorist', 'g5plus_variable', 'mhcookie', 'must-have-cookie/assets/js/script.js', 'application/ld+json', 'wpforms_settings', 'var jnewsoption', 'var VPData', 'onecdn.static.microsoft'];


        self::$defaultCombineJSExcludes = ['visitor_mode.min.js', 'jquery.min.js', 'jquery.js', 'jquery-migrate', 'lazy.min.js', 'wp-i18', 'wp.i18', 'dashicon', 'i18', 'hooks', 'lazy', 'all', 'optimizer', 'delay-js', 'application/ld+json'];

        self::$defaultCombineCSSExcludes = [#'responsive', //responsive stuff
            'dashicons', 'wps-inline',
            'wpc-critical-css',
            'wpc-critical-css-mobile',
            'rs-plugin',
            'rs-plugin-settings-inline-css',
            'media="print"', 'media=\'print\''
        ];


        // 94 → 83 bracketed to the install (FCP +750ms, LCP +1.4s, reproduced ×3) — the crit-styled


        self::$defaultCriticalCSSExcludes = ['frontend-layer', 'xlink=css'
        ];


        if (apply_filters('wpc_defer_frontend_layer', get_option('wpc_defer_frontend_layer', '1') === '1')) {
            self::$defaultCriticalCSSExcludes = ['xlink=css'];
        }

        //Check if default excludes are disabled
        if (!empty(self::$excludesOption['delay_js_default_excludes_disabled']) && self::$excludesOption['delay_js_default_excludes_disabled'] == '1') {
            self::$defaultDelayJSExcludes = [];
        }

        if (!empty(self::$excludesOption['js_combine_default_excludes_disabled']) && self::$excludesOption['js_combine_default_excludes_disabled'] == '1') {
            self::$defaultCombineJSExcludes = [];
        }
        if (!empty(self::$excludesOption['css_combine_default_excludes_disabled']) && self::$excludesOption['css_combine_default_excludes_disabled'] == '1') {
            self::$defaultCombineCSSExcludes = [];
        }

        if (!empty(self::$excludesOption['critical_css_default_excludes_disabled']) && self::$excludesOption['critical_css_default_excludes_disabled'] == '1') {
            self::$defaultCriticalCSSExcludes = [];
        }
    }

    public function scriptsToFooterExcludes()
    {

        if (!empty(self::$excludesToFooterOption) && is_array(self::$excludesToFooterOption)) {

            self::$excludesToFooterOption[] = 'jquery';
            return self::$excludesToFooterOption;
        }

        return [];

    }

    public function lastLoadScripts()
    {
        return isset(self::$userLastLoadScript) ? self::$userLastLoadScript : [];
    }

    public function deferScripts()
    {
        return isset(self::$userDeferScript) ? self::$userDeferScript : [];
    }

    public function combineCSSExcludes()
    {
        if (is_array(self::$excludesCombineCSSOption)) {
            self::$defaultCombineCSSExcludes = array_merge(self::$defaultCombineCSSExcludes, self::$excludesCombineCSSOption);
        }

        if (!empty(self::$excludesOption['combine_css_exclude_themes']) && self::$excludesOption['combine_css_exclude_themes'] == '1') {
            self::$defaultCombineCSSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['combine_css_exclude_plugins']) && self::$excludesOption['combine_css_exclude_plugins'] == '1') {
            self::$defaultCombineCSSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['combine_css_exclude_wp']) && self::$excludesOption['combine_css_exclude_wp'] == '1') {
            self::$defaultCombineCSSExcludes[] = 'wp-includes';
        }

        if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {

            self::$defaultCombineCSSExcludes = array_merge(self::$defaultCombineCSSExcludes, $this->criticalCSSExcludes());
        }

        if (function_exists('wpc_font_localizer_present') && wpc_font_localizer_present() !== false) {
            $localizerTokens = apply_filters('wpc_font_localizer_sheet_tokens',
                ['omgf', 'local-google-fonts', 'embed-google-fonts']);
            foreach ((array) $localizerTokens as $token) {
                if ($token !== '' && !in_array($token, self::$defaultCombineCSSExcludes, true)) {
                    self::$defaultCombineCSSExcludes[] = $token;
                }
            }
        }

        return self::$defaultCombineCSSExcludes;
    }

    public function criticalCSSExcludes()
    {

        self::$defaultCriticalCSSExcludes = array_merge(isset(self::$defaultCriticalCSSExcludes) ? self::$defaultCriticalCSSExcludes : [], isset(self::$excludesCriticalCSSOption) ? self::$excludesCriticalCSSOption : [], isset(self::$pageExcludesFiles['critical_css']) ? self::$pageExcludesFiles['critical_css'] : []);


        if (!empty(self::$excludesOption['critical_css_exclude_themes']) && self::$excludesOption['critical_css_exclude_themes'] == '1') {
            self::$defaultCriticalCSSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['critical_css_exclude_plugins']) && self::$excludesOption['critical_css_exclude_plugins'] == '1') {
            self::$defaultCriticalCSSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['critical_css_exclude_wp']) && self::$excludesOption['critical_css_exclude_wp'] == '1') {
            self::$defaultCriticalCSSExcludes[] = 'wp-includes';
        }

        return self::$defaultCriticalCSSExcludes;
    }

    public function combineJSExcludes()
    {
        if (is_array(self::$excludesCombineJSOption)) {
            self::$defaultCombineJSExcludes = array_merge(self::$defaultCombineJSExcludes, self::$excludesCombineJSOption);
        }

        if (!empty(self::$excludesOption['combine_js_exclude_themes']) && self::$excludesOption['combine_js_exclude_themes'] == '1') {
            self::$defaultCombineJSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['combine_js_exclude_plugins']) && self::$excludesOption['combine_js_exclude_plugins'] == '1') {
            self::$defaultCombineJSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['combine_js_exclude_wp']) && self::$excludesOption['combine_js_exclude_wp'] == '1') {
            self::$defaultCombineJSExcludes[] = 'wp-includes';
        }

        return self::$defaultCombineJSExcludes;
    }

    public function isAdaptiveExcluded($image_src, $class)
    {

        if ($this->strInArray($image_src, self::$excludesAdaptiveOption)) {

            return true;
        }


        if ($this->strInArray($class, self::$defaultAdaptiveExcludes)) {

            return true;
        }


        if ($this->strInArray($class, self::$defaultAdaptiveExcludes)) {

            return true;
        }

        if (isset(self::$pageExcludesFiles['adaptive']) && $this->strInArray($class, self::$pageExcludesFiles['adaptive'])) {

            return true;
        }


        if (!empty(self::$excludesAdaptiveOption)) {
            foreach (self::$excludesAdaptiveOption as $exclude) {
                if (strpos($exclude, '#') === 0 && strpos($class, str_replace('#', '', $exclude)) !== false) {

                    return true;
                }
            }
        }
        return false;
    }

    public function strInArray($haystack, $needles = [])
    {

        if (empty($needles)) {
            return false;
        }

        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            $needle = strtolower(trim($needle));

            if (empty($needle)) continue;

            $res = strpos($haystack, $needle);
            if ($res !== false) {
                return true;
            }
        }

        return false;
    }

    public function isWebpExcluded($image_src, $class)
    {

        if ($this->strInArray($image_src, self::$excludesWebpOption)) {

            return true;
        }


        if ($this->strInArray($class, self::$defaultWebpExcludes)) {

            return true;
        }

        if (!empty(self::$excludesWebpOption)) {
            foreach (self::$excludesWebpOption as $exclude) {
                if (strpos($exclude, '#') === 0 && strpos($class, str_replace('#', '', $exclude)) !== false) {

                    return true;
                }
            }
        }

        return false;
    }


    public function isLazyExcluded($image_src, $class)
    {

        if ($this->strInArray($image_src, self::$excludesLazyOption)) {

            return true;
        }


        if ($this->strInArray($class, self::$defaultLazyExcludes)) {

            return true;
        }


        if (!empty(self::$excludesLazyOption)) {
            foreach (self::$excludesLazyOption as $exclude) {
                if (strpos($exclude, '#') === 0 && strpos($class, str_replace('#', '', $exclude)) !== false) {

                    return true;
                }
            }
        }
        return false;
    }

    public function excludedFromDelay($tag)
    {
        if ($this->strInArray($tag, $this->delayJSExcludes())) {
            return true;
        }

        if (!empty(self::$excludesOption['delay_js_exclude_third']) && self::$excludesOption['delay_js_exclude_third'] == '1' && $this->is_external($tag) === true) {
            return true;
        }

        return false;
    }

    public function delayJSExcludes()
    {
        self::$defaultDelayJSExcludes = array_merge(isset(self::$defaultDelayJSExcludes) ? self::$defaultDelayJSExcludes : [], isset(self::$excludesDelayJSOption) ? self::$excludesDelayJSOption : [], isset(self::$pageExcludesFiles['delay_js']) ? self::$pageExcludesFiles['delay_js'] : []);

        if (!empty(self::$excludesOption['delay_js_exclude_themes']) && self::$excludesOption['delay_js_exclude_themes'] == '1') {
            self::$defaultDelayJSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['delay_js_exclude_plugins']) && self::$excludesOption['delay_js_exclude_plugins'] == '1') {
            self::$defaultDelayJSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['delay_js_exclude_wp']) && self::$excludesOption['delay_js_exclude_wp'] == '1') {
            self::$defaultDelayJSExcludes[] = 'wp-includes';
        }

        return self::$defaultDelayJSExcludes;
    }

    public function is_external($tag)
    {

        if (preg_match('/<script[^>]*>/i', $tag, $matches) && strpos($matches[0], 'src=') !== false) {
            if (preg_match('/src=["\']([^"\']+)["\']/', $matches[0], $urlMatches)) {
                $url = $urlMatches[1];
            } else {
                return false;
            }
        } else {
            return false;
        }

        $site_url = home_url();
        $url = str_replace(['https://', 'http://'], '', $url);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($url, '/') === 0 && strpos($url, '//') === false) {
            // Image on site
            return false;
        } else if ((strpos($url, $site_url) === false || strpos($url, '//') === 0) || (strpos($url, $site_url) !== false && strpos($url, $site_url) >= strpos($url, '?'))) {
            // Image not on site
            return true;
        } else {
            // Image on site
            return false;
        }
    }

    // The legacy v2 engine (selected only when delay-js-v3 is '0') reads the same one list.
    public function excludedFromDelayV2($tag)
    {
        return $this->strInArray($tag, $this->delayJSExcludesV3());
    }

    public function excludedFromDelayV3($tag)
    {
        return $this->strInArray($tag, $this->delayJSExcludesV3());
    }

    // THE ONE READER of the Delay JS exclude list: the global list (`delay_js_v3`, see
    // wpc_delay_excludes_fold in defines.php) plus this page's exclude files under either key.
    // It no longer unions a stored `delay_js_v2`: that union is what made a removal made in one
    // box come back from the other (acrystalglass.com, ticket 12056). Per-page files are still
    // read under both keys, since the per-page editor has written both.
    public function delayJSExcludesV3()
    {
        self::$defaultDelayJSExcludesV3 = array_merge(
            isset(self::$excludesDelayJSOptionV3) && is_array(self::$excludesDelayJSOptionV3) ? self::$excludesDelayJSOptionV3 : [],
            isset(self::$pageExcludesFiles['delay_js_v3']) && is_array(self::$pageExcludesFiles['delay_js_v3']) ? self::$pageExcludesFiles['delay_js_v3'] : [],
            isset(self::$pageExcludesFiles['delay_js_v2']) && is_array(self::$pageExcludesFiles['delay_js_v2']) ? self::$pageExcludesFiles['delay_js_v2'] : []
        );
        return self::$defaultDelayJSExcludesV3;
    }

    public function delayJSExcludesV2()
    {
        return $this->delayJSExcludesV3();
    }
}
