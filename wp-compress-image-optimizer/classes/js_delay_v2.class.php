<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/js_delay_v2.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */





class wps_ic_js_delay_v2
{


    protected $script_registry;
    protected $script_id;
    protected $excludes;
    protected $priority_run;

    protected $userExcludes;
    protected $deferPatterns;
    protected $userDeferScripts;
    protected $userForceDelay;
    public static $settings;
    public function __construct()
    {
		self::$settings = get_option(WPS_IC_SETTINGS);
        $this->script_registry = array();
        $this->script_id = 0;
        $this->excludes = ['dark-mode',
          'wpcJqDef47', 
          'n489D_var', 
          'ngf298gh738qwbdh0s87v_vars', 
          'wpcRunningCritical',
          'wpc-presc-reserve', 
          'wpc-icon-belt', 
          'trustLogo',
          'turnstile',


          'captcha',
          'grecaptcha',
          'Captcha',


          'jetformbuilder',
          'jet-form-builder',
          'JetFormBuilder',


          'wp-includes/js/dist/hooks',
          'wp-includes/js/dist/i18n',
          
          
          
          
          'js/dist/hooks',
          'js/dist/i18n',
          'wp-polyfill',
          'document.write',
          'wpc-ga-bot-shield',
          'sourcebuster',
          'SR7.', 
          
          'gdpr-cookie-consent', 
          'cookie-law-info',
          'cookieyes', 
          'complianz', 
          'cmplz', 
          'cookie-notice', 
          'cookie-consent', 
          'moove_gdpr', 
          'osano', 
          'termly', 
          'iubenda',
          'wpl_cookie_consent', 
          'wpl_viewed_cookie', 
          'CookieConsent', 
          'cookiebot', 
          'tarteaucitron',
          'onetrust', 
          'quantcast', 
          'usercentrics', 
          'consently',
          'didomi', 
          'trustarc', 
          'truste.com', 
          'sourcepoint', 
          'axeptio', 
          'klaro', 
          'securiti.ai', 
          'real-cookie-banner', 
          'devowl',
          'realCookieBanner',  


          'form_embed',       
          'msgsndr',          
          'leadconnectorhq',  
          'hsforms',          
          'hbspt',            
          'calendly',         
          'typeform',         
          'jotform',          
        ];


        $cookiePlugins = [
            'gdpr-cookie-consent/gdpr-cookie-consent.php',
            'cookie-law-info/cookie-law-info.php',
            'cookie-notice/cookie-notice.php',
            'complianz-gdpr/complianz-gdpr.php',
            'complianz-gdpr-premium/complianz-gdpr.php',
            'complianz-gdpr/complianz-gpdr.php',
            'complianz-gdpr-premium/complianz-gpdr.php',
            'iubenda-cookie-law-solution/iubenda_cookie_solution.php',
            'moove-gdpr-cookie-compliance/moove-gdpr-cookie-compliance.php',
            'real-cookie-banner/index.php',
            'real-cookie-banner-pro/index.php',
        ];
        foreach ($cookiePlugins as $plugin) {
            if (is_plugin_active($plugin)) {


                $cookieEcosystem = [
                    'jquery.min.js',
                    'jquery.js',
                    'jquery-migrate',
                    'jquery-ui',
                    'jquery.blockUI',
                    'js-cookie',
                    'js.cookie',
                    'woocommerce.min.js',
                    'wc-cart-fragments',
                    'wc-add-to-cart',
                    'wc-checkout',
                ];
                
                foreach ($cookieEcosystem as $script) {
                    $this->excludes[] = $script;
                }


                foreach ($cookieEcosystem as $script) {
                    
                    
                    
                    
                    
                    
                    
                    if ($script === 'jquery.min.js' || $script === 'jquery.js') { continue; }
                    $this->deferPatterns[] = $script;
                }

                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('COOKIE_PLUGIN_DETECTED', basename($plugin) . ' → jQuery/WooCommerce ecosystem excluded-from-delay AND deferred (non-blocking)');
                }
                break;
            }
        }
        
        
        
        if (class_exists('WooCommerce') && !in_array('wc-cart-fragments', (array) $this->excludes, true)) {
            foreach (['jquery.min.js', 'jquery.js', 'jquery-migrate', 'jquery-ui', 'jquery.blockUI',
                         'js-cookie', 'js.cookie', 'woocommerce.min.js', 'wc-cart-fragments',
                         'wc-add-to-cart', 'wc-checkout'] as $wpc_ws360) {
                $this->excludes[] = $wpc_ws360;
                
                
                if ($wpc_ws360 === 'jquery.min.js' || $wpc_ws360 === 'jquery.js') { continue; }
                $this->deferPatterns[] = $wpc_ws360;
            }
        }

	    if (isset(self::$settings['gtag-lazy']) && self::$settings['gtag-lazy'] == '0') {
		    $this->excludes[] = 'gtag';
		    $this->excludes[] = 'googletag';
	    }


        if (function_exists('is_plugin_active') && is_plugin_active('jetformbuilder/jet-form-builder.php')
            && apply_filters('wpc_delay_exclude_jquery_for_jfb', true)) {
            foreach (['jquery.min.js', 'jquery.js', 'jquery-migrate'] as $wpc_jq_dep) {
                $this->excludes[] = $wpc_jq_dep;
            }
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('JFB_DETECTED', 'JetFormBuilder active → jQuery chain excluded-from-delay (dependency closure for the parse-time JFB/captcha excludes)');
            }
        }

        $this->priority_run = ['document.addEventListener("DOMContentLoaded",()=>(document.body.style.visibility="inherit"));'];

        $this->userExcludes = new wps_ic_excludes();

        
        
        
        $this->deferPatterns = array_values(array_unique(array_merge((array) $this->deferPatterns, [
            'optimizer.adaptive',
            'optimizer.pixel',
            'optimizer.local',
            'optimizer.min',
            'wpcompress-aio',
        ])));
        $this->userDeferScripts = $this->userExcludes->deferScripts();


        if (is_array(self::$settings) && !empty(self::$settings['revslider-instant']) && self::$settings['revslider-instant'] == '1'
            && apply_filters('wpc_revslider_instant', true)) {
            $this->userDeferScripts[] = 'revslider';


            foreach ((array) apply_filters('wpc_revslider_instant_deps', ['sr7', 'frontend-modules']) as $wpc_rsd47) {
                $this->userDeferScripts[] = $wpc_rsd47;
            }
        }


        $this->userForceDelay = $this->userExcludes->lastLoadScripts();


        foreach ((array) apply_filters('wpc_builtin_force_delay', ['sr7-elementor-preview']) as $wpc_bfd48) {
            if (!is_array($this->userForceDelay)) { $this->userForceDelay = []; }
            $this->userForceDelay[] = $wpc_bfd48;
        }
    }


    public function removeNoDelay($tag)
    {
        if (is_array($tag)) {
            $tag = $tag[0];
        }

        $tagLower = strtolower($tag);

        
        if (strpos($tagLower, 'text/javascript-no-delay') !== false) {
            $tag = str_replace('type="text/javascript-no-delay"', 'type="text/javascript"', $tag);
        }

        return $tag;
    }


    public function process_html($html)
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return $html;
        }


        if (strpos($html, 'recaptcha/api.js?render=') !== false
            && apply_filters('wpc_delay_bail_on_recaptcha_v3', false)) {
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('DELAY_BAIL_RECAPTCHA_V3', 'v3 site key on page — JS delay skipped (per-site opt-in)');
            }
            return $html;
        }

        $this->script_registry = array();
        $this->script_id = 0;

        $pattern = '/<script\b[^>]*>(.*?)<\/script>/si';

        $html = preg_replace_callback($pattern, array($this, 'process_script_tag'), $html);

        
        $html = $this->elementor_integration($html);

        $delay_script = '';
        if (!empty(get_option('wps_ic_delay_v2_debug'))) {
            $delay_script .= '<script>var DEBUG = true;</script>';
        }

        $pullzone = 'optimize-v2';
        if (!empty(self::$settings['eu-routing']) && self::$settings['eu-routing'] == '1'){
            $pullzone = 'static-eu';
        }

        $delay_script .= '<script id="wpc-script-registry">var wpcScriptRegistry=' . json_encode($this->script_registry) . ';</script>';
        if (empty(get_option('wps_ic_delay_v2_debug'))) {
            $delay_script .= '<script src="https://' . $pullzone . '.b-cdn.net/loader.min.js?icv='.WPS_IC_HASH.'" async></script>';
        } else {
            $delay_script .= '<script src="https://frankfurt.zapwp.net/delay-js-v2/loader.dev.js"></script>';
        }

        $html = str_replace('<script type="wpc-delay-placeholder"></script>', $delay_script, $html);

        return $html;
    }

    protected function elementor_integration($html)
    {
        $elementor_result = $this->process_elementor_animations($html);
        if ($elementor_result) {
            $elementor_script = $elementor_result['script'];
            $html = $elementor_result['html'];
        } else {
            return $html;
        }

        if ($elementor_script) {
            $html = str_replace('</head>', $elementor_script . '</head>', $html);
        }

        return $html;
    }

    protected function process_elementor_animations($html)
    {
        
        if (!preg_match_all('/<div[^>]*\belementor-invisible\b[^>]*>/i', $html, $matches)) {
            return null;
        }

        $animations = array();
        $modified_html = $html;
        $matches[0] = array_slice($matches[0], 0, 5);

        foreach ($matches[0] as $match) {
            if (preg_match('/data-settings=["\']([^"\']*)["\']/', $match, $settings_match)) {
                $data_settings = html_entity_decode($settings_match[1], ENT_QUOTES, 'UTF-8');

                $settings = json_decode($data_settings, true);
                if ($settings && isset($settings['animation'])) {
                    $animation = $settings['animation'];
                    $animations[$animation] = true;

                    $new_match = $match;

                    
                    if (strpos($new_match, 'wpc-lazyload') === false) {
                        if (preg_match('/class=["\']([^"\']*)["\']/', $new_match, $class_match)) {
                            $existing_classes = $class_match[1];
                            $new_classes = $existing_classes . ' wpc-lazyload';
                            $new_match = str_replace($class_match[0], 'class="' . $new_classes . '"', $new_match);
                        } else {
                            $new_match = str_replace('>', ' class="wpc-lazyload">', $new_match);
                        }
                    }

                    
                    $animation_attr = ' wpc-elementor-animation="animated ' . esc_attr($animation) . '"';

                    if (substr($new_match, -1) === '>') {
                        $new_match = substr($new_match, 0, -1) . $animation_attr . '>';
                    }

                    $modified_html = str_replace($match, $new_match, $modified_html);
                }
            }
        }

        if (empty($animations)) {
            return null;
        }

        $combine = new wps_ic_combine_css();
        $url_key = new wps_ic_url_key();

        foreach (array_keys($animations) as $animation_name) {
            $css_pattern = '/<link[^>]*href=["\']([^"\']*' . preg_quote($animation_name, '/') . '\.min\.css[^"\']*)["\'][^>]*>/i';

            if (preg_match($css_pattern, $modified_html, $css_match)) {
                $original_link = $css_match[0];
                $src = $css_match[1];

                if ($url_key->is_external($src)) {
                    $content = $combine->getRemoteContent($src);
                } else {
                    $content = $combine->getLocalContent($src);
                }

                if (!empty($content)) {
                    $inline_style = '<style type="text/css">' . $content . '</style>';
                    $modified_html = str_replace($original_link, $inline_style, $modified_html);
                }
            }
        }


        $animation_script = $this->generate_animation_script();

        return array('script' => $animation_script, 'html' => $modified_html);
    }

    




    protected function generate_animation_script()
    {
        return '<script>
(function() {
    // Flag to track if our handler is active
    let isHandlerActive = true;

    // Listen for a custom event that signals all scripts are loaded
    window.addEventListener("wpc-scripts-loaded", function() {
        // Disable our handler when all scripts are loaded
        isHandlerActive = false;
        console.log("[WPC Elementor] Disabling custom animation handler - all scripts loaded");
    });

    // Elementor animation handler with visibility check
    function handleElementorAnimations() {
        // Exit if handler is no longer active
        if (!isHandlerActive) return;

        const elements = document.querySelectorAll(".wpc-lazyload[wpc-elementor-animation]");

        // Process each element with wpc-elementor-animation attribute
        elements.forEach(element => {
            // Check if element is already processed
            if (element.classList.contains("wpc-animation-processed")) {
                return;
            }

            // Check if element is visible - apply immediately if it is
            if (isElementInViewport(element)) {
                applyAnimation(element);
            }
        });
    }

    // Apply animation to an element
    function applyAnimation(element) {
        // Remove data-settings attribute to prevent Elementor from triggering the animation again
        element.removeAttribute("data-settings");

        // Get animation classes from attribute
        const animationClasses = element.getAttribute("wpc-elementor-animation").split(" ");

        // Remove elementor-invisible class
        element.classList.remove("elementor-invisible");

        // Add animation classes
        animationClasses.forEach(cls => {
            element.classList.add(cls);
        });

        // Mark as processed
        element.classList.add("wpc-animation-processed");
    }

    // Check if element is in viewport
    function isElementInViewport(el) {
        const rect = el.getBoundingClientRect();
        return (
            rect.top <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.left <= (window.innerWidth || document.documentElement.clientWidth) &&
            rect.bottom >= 0 &&
            rect.right >= 0
        );
    }


    // Run on DOMContentLoaded
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", handleElementorAnimations);
    } else {
        // If DOMContentLoaded already fired, run immediately
        handleElementorAnimations();
    }

})();</script>';
    }

    protected function process_script_tag($matches)
    {
        $full_script = $matches[0];
        $script_content = isset($matches[1]) ? $matches[1] : '';

        $attributes = $this->parse_script_attributes($full_script);

        
        
        
        
        $wpc_dg805 = true;
        $wpc_id805 = isset($attributes['id']) ? strtolower(trim((string) $attributes['id'])) : '';
        if ($wpc_id805 !== '') {
            if (!empty($this->wpc_inline_pairs494[$wpc_id805])) {
                $wpc_dg805 = false;
            }
            if ($wpc_dg805 && !empty($this->wpc_jq_parse_need803)
                && method_exists($this, 'wpc_is_jquery_id803') && static::wpc_is_jquery_id803($wpc_id805)) {
                $wpc_dg805 = false;
            }
        }

        if ($this->should_exclude_script($attributes, $script_content)) {
            
            if ($wpc_dg805 && $this->should_defer_script($attributes)) {
                if (strpos($full_script, 'defer') === false && strpos($full_script, 'async') === false) {
                    return str_replace('<script ', '<script defer ', $full_script);
                }
            }
            return $full_script;
        }

        if ($this->should_defer_script($attributes)) {
            if (!$wpc_dg805) {
                return $full_script;
            }
            if (strpos($full_script, 'defer') === false && strpos($full_script, 'async') === false) {
                return str_replace('<script ', '<script defer ', $full_script);
            }
            return $full_script;
        }


        $do_nat = class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'naturalize_asset_urls')
            && (
                (method_exists('wps_rewriteLogic', 'natural_assets_on') && wps_rewriteLogic::natural_assets_on())
                || apply_filters('wpc_asset_naturalize_enabled', true)
            );
        $reg_src = '';
        if (isset($attributes['src'])) {
            $reg_src = html_entity_decode($attributes['src']);
            if ($do_nat) {
                $nat = wps_rewriteLogic::naturalize_asset_urls($reg_src);
                if (is_string($nat) && $nat !== '') $reg_src = $nat;
            }
            $reg_src = base64_encode($reg_src);
        }
        $reg_content = '';
        if (empty($attributes['src'])) {
            $reg_content = (string) $script_content;
            if ($do_nat && $reg_content !== '') {
                $nat = wps_rewriteLogic::naturalize_asset_urls($reg_content);
                if (is_string($nat) && $nat !== '') $reg_content = $nat;
            }
            $reg_content = base64_encode($reg_content);
        }
        $script_data = array('id' => 'delayed-script-' . $this->script_id++, 'src' => $reg_src, 'content' => $reg_content, 'type' => isset($attributes['type']) ? $attributes['type'] : 'text/javascript', 'encoded' => true, 'attributes' => array());

        foreach ($attributes as $attr => $value) {
            if (!in_array($attr, array('src', 'type'))) {

                
                
                if ($do_nat && is_string($value) && $value !== '' && strpos($value, '/a:') !== false) {
                    $nv = wps_rewriteLogic::naturalize_asset_urls($value);
                    if (is_string($nv) && $nv !== '') $value = $nv;
                }
                $script_data['attributes'][$attr] = $value;
            }
        }

        if (isset($attributes['async'])) {
            $script_data['async'] = true;
        }
        if (isset($attributes['defer'])) {
            $script_data['defer'] = true;
        }

        if ($this->is_priority_run($attributes, $script_content)) {
            $script_data['attributes']['priorityRun'] = 'true';
        }

        $this->script_registry[] = $script_data;

        return '<script type="text/placeholder" data-script-id="' . $script_data['id'] . '"></script>';
    }

    protected function parse_script_attributes($script_tag)
    {
        $attributes = array();

        if (preg_match('/<script\b([^>]*)>/i', $script_tag, $matches)) {
            $attr_string = $matches[1];

            if (preg_match_all('/([\w-]+)(?:=(["\'])(.*?)\2|=([^\s>]+))?/i', $attr_string, $attr_matches, PREG_SET_ORDER)) {
                foreach ($attr_matches as $attr_match) {
                    $name = strtolower($attr_match[1]);
                    $value = !empty($attr_match[3]) ? $attr_match[3] : (!empty($attr_match[4]) ? $attr_match[4] : true);
                    $attributes[$name] = $value;
                }
            }
        }

        return $attributes;
    }

    protected function should_exclude_script($attributes, $content = '')
    {
        if (!empty($attributes['data-priority']) && $attributes['data-priority'] === 'high') {
            return true;
        }

        if (!empty($attributes['data-nodefer'])) {
            return true;
        }

        if (!empty($attributes['type']) && in_array($attributes['type'], ['text/mf', 'application/ld+json', 'application/json', 'speculationrules', 'importmap', 'text/template', 'wpc-delay-placeholder'])) {
            return true;
        }

        if ($content !== ''
            && (strpos($content, 'jqueryParams') !== false || strpos($content, 'customHeadScripts') !== false)
            && apply_filters('wpc_keep_jquery_stub', true)) {
            return true;
        }

        if (!empty($this->userForceDelay)) {
            if (!empty($attributes['src']) && $this->checkKeyword($attributes['src'], $this->userForceDelay)) {
                return false;
            }
            if (!empty($content) && $this->checkKeyword($content, $this->userForceDelay)) {
                return false;
            }
        }

        if (!empty($attributes['src'])) {
            
            
            
            
            
            $wpc_keep495 = (string) $attributes['src']
                . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id'])
                    ? ' ' . (string) $attributes['id'] : '');
            if ($this->checkKeyword($wpc_keep495, $this->excludes)) {
                
                if (function_exists('wpc_diagnostic_log')) {
                    $src = $attributes['src'];
                    if (stripos($src, 'jquery') !== false || stripos($src, 'blockui') !== false || stripos($src, 'woocommerce') !== false || stripos($src, 'wc-') !== false) {
                        wpc_diagnostic_log('DELAY_EXCLUDE_JQ', basename($src));
                    }
                }
                return true;
            }

            
            if ($this->wpc_user_delay_excluded($attributes['src'])) {
                return true;
            }
        }

        if (!empty($content) && $this->checkKeyword($content, $this->excludes)) {
            
            if (function_exists('wpc_diagnostic_log')) {
                if (stripos($content, 'ngf298gh738qwbdh0s87v_vars') !== false) {
                    wpc_diagnostic_log('VARS_PRESERVED', 'ngf298gh738qwbdh0s87v_vars (adaptive optimizer)');
                } elseif (stripos($content, 'n489D_var') !== false) {
                    wpc_diagnostic_log('VARS_PRESERVED', 'n489D_var (js_delay config)');
                }
            }
            return true;
        }

        
        if ($this->wpc_user_delay_excluded($content)) {
            return true;
        }

        return false;
    }

    
    
    
    protected function wpc_user_delay_excluded($x)
    {
        return is_object($this->userExcludes)
            && method_exists($this->userExcludes, 'excludedFromDelayV2')
            && $this->userExcludes->excludedFromDelayV2((string) $x);
    }

    protected function should_defer_script($attributes)
    {
        if (empty($attributes['src'])) {
            return false;
        }

        $src = $attributes['src'];

        if ($this->checkKeyword($src, $this->deferPatterns)) {
            return true;
        }

        if (!empty($this->userDeferScripts) && $this->checkKeyword($src, $this->userDeferScripts)) {
            return true;
        }

        return false;
    }

    public function checkKeyword($tag, $keywordArray)
    {
        if (!empty($keywordArray)) {
            foreach ($keywordArray as $needle) {
                if (strpos($tag, $needle) !== false) {
                    return true; 
                }
            }
        }

        return false; 
    }

    protected function is_priority_run($attributes = [], $content = '')
    {
        if (!empty($content) && $this->checkKeyword($content, $this->priority_run)) {
            return true;
        }

        return false;
    }

}