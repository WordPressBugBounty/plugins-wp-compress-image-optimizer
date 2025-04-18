<?php

class wps_ic_excludes extends wps_ic
{

    private static $defaultDelayJSExcludes;
    private static $defaultCombineJSExcludes;
    private static $defaultCombineCSSExcludes;
    private static $defaultCriticalCSSExcludes;
    private static $defaultInlineCSSExcludes;
    private static $defaultLazyExcludes;
    private static $defaultWebpExcludes;
    private static $defaultAdaptiveExcludes;
    private static $excludesDelayJSOption;
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
    private static $excludesInlineCSSOption;
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
            self::$excludesCombineJSOption = !empty(self::$excludesOption['combine_js']) ? self::$excludesOption['combine_js'] : [];
            self::$excludesCombineCSSOption = !empty(self::$excludesOption['css_combine']) ? self::$excludesOption['css_combine'] : [];
            self::$excludesCriticalCSSOption = !empty(self::$excludesOption['critical_css']) ? self::$excludesOption['critical_css'] : [];
            self::$excludesInlineCSSOption = !empty(self::$excludesOption['inline_css']) ? self::$excludesOption['inline_css'] : [];
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
            self::$excludesInlineCSSOption = [];
            self::$excludesToFooterOption = [];
            self::$excludesLazyOption = [];
            self::$excludesAdaptiveOption = [];
            self::$excludesWebpOption = [];
        }

        self::$defaultLazyExcludes = [
            'show-on-hover'
        ];

        self::$defaultAdaptiveExcludes = [

        ];

        self::$defaultWebpExcludes = [

        ];

        self::$defaultDelayJSExcludes = [
            'gtranslate',
            'gformRedirect()',
            'wpgb_settings',
            'latepoint_helper',
            'wc-order-attribution-js-extra',
            'mailchimp_public_data',
            'porto-theme-js-extra',
            'porto-live-search-js-extra',
            'yith-wcan-shortcodes-js-extra',
            'jqueryParams',
            '/plugins/elementor-pro/assets/js/page-transitions',
            'hbspt.forms',
            'js.hsforms',
            'var directorist',
            'g5plus_variable',
            'mhcookie',
            'must-have-cookie/assets/js/script.js',
            'application/ld+json',
            'wpforms_settings',
            'var jnewsoption',
            'var VPData'
        ];

        self::$defaultCombineJSExcludes = [
            'visitor_mode.min.js',
            'jquery.min.js',
            'jquery.js',
            'jquery-migrate',
            'lazy.min.js',
            'wp-i18',
            'wp.i18',
            'dashicon',
            'i18',
            'hooks',
            'lazy',
            'all',
            'optimizer',
            'delay-js',
            'application/ld+json'
        ];

        self::$defaultCombineCSSExcludes = [
            #'responsive', //responsive stuff
            'dashicons',
            'wps-inline', //our inline CSS option
            'wpc-critical-css', //our critical
            'wpc-critical-css-mobile', //our mobile critical
            'rs-plugin', // revolution slider causing JS errors if inline is missing
            'rs-plugin-settings-inline-css', // revolution slider causing JS errors if inline is missing
            'media="print"', 'media=\'print\'' //styles only for printing
        ];

        self::$defaultCriticalCSSExcludes = [
        ];

        self::$defaultInlineCSSExcludes = [
        ];

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

        if (!empty(self::$excludesOption['inline_css_default_excludes_disabled']) && self::$excludesOption['inline_css_default_excludes_disabled'] == '1') {
            self::$defaultInlineCSSExcludes = [];
        }
    }

    public function scriptsToFooterExcludes()
    {

        if (!empty(self::$excludesToFooterOption) && is_array(self::$excludesToFooterOption)) {
            //something is excluded, so exclude jquery too
            self::$excludesToFooterOption[] = 'jquery';
            return self::$excludesToFooterOption;
        }

        return [];

    }

    public function inlineCSSExcludes()
    {

        self::$defaultInlineCSSExcludes = array_merge(
            isset(self::$defaultInlineCSSExcludes) ? self::$defaultInlineCSSExcludes : [],
            isset(self::$excludesInlineCSSOption) ? self::$excludesInlineCSSOption : [],
            isset(self::$pageExcludesFiles['inline_css']) ? self::$pageExcludesFiles['inline_css'] : []
        );


        if (!empty(self::$excludesOption['inline_css_exclude_themes']) &&
            self::$excludesOption['inline_css_exclude_themes'] == '1') {
            self::$defaultInlineCSSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['inline_css_exclude_plugins']) &&
            self::$excludesOption['inline_css_exclude_plugins'] == '1') {
            self::$defaultInlineCSSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['inline_css_exclude_wp']) &&
            self::$excludesOption['inline_css_exclude_wp'] == '1') {
            self::$defaultInlineCSSExcludes[] = 'wp-includes';
        }

        return self::$defaultInlineCSSExcludes;
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

        if (!empty(self::$excludesOption['combine_css_exclude_themes']) &&
            self::$excludesOption['combine_css_exclude_themes'] == '1') {
            self::$defaultCombineCSSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['combine_css_exclude_plugins']) &&
            self::$excludesOption['combine_css_exclude_plugins'] == '1') {
            self::$defaultCombineCSSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['combine_css_exclude_wp']) &&
            self::$excludesOption['combine_css_exclude_wp'] == '1') {
            self::$defaultCombineCSSExcludes[] = 'wp-includes';
        }

        if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {
            //if excluded from crit, it should not be delayed, so it should not be combined either
            self::$defaultCombineCSSExcludes = array_merge(self::$defaultCombineCSSExcludes, $this->criticalCSSExcludes());
        }

        return self::$defaultCombineCSSExcludes;
    }

    public function criticalCSSExcludes()
    {

        self::$defaultCriticalCSSExcludes = array_merge(
            isset(self::$defaultCriticalCSSExcludes) ? self::$defaultCriticalCSSExcludes : [],
            isset(self::$excludesCriticalCSSOption) ? self::$excludesCriticalCSSOption : [],
            isset(self::$pageExcludesFiles['critical_css']) ? self::$pageExcludesFiles['critical_css'] : []
        );


        if (!empty(self::$excludesOption['critical_css_exclude_themes']) &&
            self::$excludesOption['critical_css_exclude_themes'] == '1') {
            self::$defaultCriticalCSSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['critical_css_exclude_plugins']) &&
            self::$excludesOption['critical_css_exclude_plugins'] == '1') {
            self::$defaultCriticalCSSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['critical_css_exclude_wp']) &&
            self::$excludesOption['critical_css_exclude_wp'] == '1') {
            self::$defaultCriticalCSSExcludes[] = 'wp-includes';
        }

        return self::$defaultCriticalCSSExcludes;
    }

    public function combineJSExcludes()
    {
        if (is_array(self::$excludesCombineJSOption)) {
            self::$defaultCombineJSExcludes = array_merge(self::$defaultCombineJSExcludes, self::$excludesCombineJSOption);
        }

        if (!empty(self::$excludesOption['combine_js_exclude_themes']) &&
            self::$excludesOption['combine_js_exclude_themes'] == '1') {
            self::$defaultCombineJSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['combine_js_exclude_plugins']) &&
            self::$excludesOption['combine_js_exclude_plugins'] == '1') {
            self::$defaultCombineJSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['combine_js_exclude_wp']) &&
            self::$excludesOption['combine_js_exclude_wp'] == '1') {
            self::$defaultCombineJSExcludes[] = 'wp-includes';
        }

        return self::$defaultCombineJSExcludes;
    }

    public function renderBlockingCSSExcludes()
    {
        $excludes = ['wps_inline'];
        $combine_css_excludes = get_option('wpc-excludes');
        $combine_css_excludes = $combine_css_excludes['css_render_blocking'];

        if (is_array($combine_css_excludes)) {
            $excludes = array_merge($excludes, $combine_css_excludes);
        }

        return $excludes;
    }

    public function isAdaptiveExcluded($image_src, $class)
    {

        if ($this->strInArray($image_src, self::$excludesAdaptiveOption)) {
            //user exclude for url
            return true;
        }


        if ($this->strInArray($class, self::$defaultAdaptiveExcludes)) {
            //our default exclude for class
            return true;
        }


        if ($this->strInArray($class, self::$defaultAdaptiveExcludes)) {
            //our default exclude for class
            return true;
        }

        if (isset(self::$pageExcludesFiles['adaptive']) && $this->strInArray($class,
                self::$pageExcludesFiles['adaptive'])) {
            //per page excludes
            return true;
        }


        if (!empty(self::$excludesAdaptiveOption)) {
            foreach (self::$excludesAdaptiveOption as $exclude) {
                if (strpos($exclude, '#') === 0 && strpos($class, str_replace('#', '', $exclude)) !== false) {
                    //user exclude for class
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
            //user exclude for url
            return true;
        }


        if ($this->strInArray($class, self::$defaultWebpExcludes)) {
            //our default exclude for class
            return true;
        }

        if (!empty(self::$excludesWebpOption)) {
            foreach (self::$excludesWebpOption as $exclude) {
                if (strpos($exclude, '#') === 0 && strpos($class, str_replace('#', '', $exclude)) !== false) {
                    //user exclude for class
                    return true;
                }
            }
        }

        return false;
    }


    public function isLazyExcluded($image_src, $class)
    {

        if ($this->strInArray($image_src, self::$excludesLazyOption)) {
            //user exclude for url
            return true;
        }


        if ($this->strInArray($class, self::$defaultLazyExcludes)) {
            //our default exclude for class
            return true;
        }


        if (!empty(self::$excludesLazyOption)) {
            foreach (self::$excludesLazyOption as $exclude) {
                if (strpos($exclude, '#') === 0 && strpos($class, str_replace('#', '', $exclude)) !== false) {
                    //user exclude for class
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
        self::$defaultDelayJSExcludes = array_merge(
            isset(self::$defaultDelayJSExcludes) ? self::$defaultDelayJSExcludes : [],
            isset(self::$excludesDelayJSOption) ? self::$excludesDelayJSOption : [],
            isset(self::$pageExcludesFiles['delay_js']) ? self::$pageExcludesFiles['delay_js'] : []
        );

        if (!empty(self::$excludesOption['delay_js_exclude_themes']) &&
            self::$excludesOption['delay_js_exclude_themes'] == '1') {
            self::$defaultDelayJSExcludes[] = 'wp-content/themes';
        }

        if (!empty(self::$excludesOption['delay_js_exclude_plugins']) &&
            self::$excludesOption['delay_js_exclude_plugins'] == '1') {
            self::$defaultDelayJSExcludes[] = 'wp-content/plugins';
        }

        if (!empty(self::$excludesOption['delay_js_exclude_wp']) &&
            self::$excludesOption['delay_js_exclude_wp'] == '1') {
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
        } else if ((strpos($url, $site_url) === false || strpos($url, '//') === 0) || (strpos($url, $site_url) !== false
                && strpos($url, $site_url) >=
                strpos($url, '?'))) {
            // Image not on site
            return true;
        } else {
            // Image on site
            return false;
        }
    }
}
