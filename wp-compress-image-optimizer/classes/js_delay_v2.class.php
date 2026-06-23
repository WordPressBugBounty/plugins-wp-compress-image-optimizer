<?php
/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */
class wps_ic_js_delay_v2
{

    private $script_registry;
    private $script_id;
    private $excludes;
    private $priority_run;

    private $userExcludes;
    private $deferPatterns;
    private $userDeferScripts;
    public static $settings;
    public function __construct()
    {
		self::$settings = get_option(WPS_IC_SETTINGS);
        $this->script_registry = array();
        $this->script_id = 0;
        $this->excludes = ['dark-mode', // dark mode switcher
          'n489D_var', // WPC js_delay config vars (inline block must run at parse time)
          'ngf298gh738qwbdh0s87v_vars', // WPC adaptive optimizer config vars (inline wp_localize_script output)
          'wpcRunningCritical',
          'trustLogo', // css safety service, uses document.write
          'turnstile', // had delayed loading detection, throws error
          'document.write',
          'wpc-ga-bot-shield',
          'sourcebuster', //woo script, incompatible with delay
          'SR7.', // Slider Revolution inline scripts, load ugly if delayed
          // GDPR / Cookie consent plugins — must run immediately to show/hide banners
          'gdpr-cookie-consent', // WP Cookie Consent (GDPR Cookie Consent)
          'cookie-law-info', // CookieYes / Cookie Law Info
          'cookieyes', // CookieYes
          'complianz', // Complianz GDPR
          'cmplz', // Complianz shorthand
          'cookie-notice', // Cookie Notice by dFactory
          'cookie-consent', // Generic cookie consent
          'moove_gdpr', // Moove GDPR Cookie Compliance
          'osano', // Osano Cookie Consent
          'termly', // Termly Consent
          'iubenda', // iubenda Cookie Solution
          'wpl_cookie_consent', // WP Legal Pages cookie consent
          'wpl_viewed_cookie', // WP Cookie Consent inline check
          'CookieConsent', // Cookiebot / CookieConsent
          'cookiebot', // Cookiebot
          'tarteaucitron', // tarteaucitron.js
          'onetrust', // OneTrust
          'quantcast', // Quantcast Choice
          'usercentrics', // Usercentrics
          'consently', // Consently — manages script (un)blocking + redefines document.readyState, so it
                       // MUST run immediately. Delaying it collides with WPC's own delay loader (both
                       // redefine readyState) → Consently falls back to "unblock all" → scripts re-run →
                       // SmartMenus re-inits (5x duplicate sub-arrow chevrons) + "Cannot redefine readyState".
        ];

        // If a cookie consent plugin is active, also exclude jQuery (their dependency).
        // Only done conditionally to avoid impacting sites without cookie plugins.
        // NOTE: historical typos (complianz-gpdr.php) caused Complianz sites to silently skip
        // the jQuery exclusion block for years. Both the correct AND typo'd paths are listed
        // so sites that upgraded before the typo fix still work.
        $cookiePlugins = [
            'gdpr-cookie-consent/gdpr-cookie-consent.php',
            'cookie-law-info/cookie-law-info.php',
            'cookie-notice/cookie-notice.php',
            'complianz-gdpr/complianz-gdpr.php',            // correct path
            'complianz-gdpr-premium/complianz-gdpr.php',    // correct path (premium)
            'complianz-gdpr/complianz-gpdr.php',            // legacy typo fallback
            'complianz-gdpr-premium/complianz-gpdr.php',    // legacy typo fallback (premium)
            'iubenda-cookie-law-solution/iubenda_cookie_solution.php',
            'moove-gdpr-cookie-compliance/moove-gdpr-cookie-compliance.php',
        ];
        foreach ($cookiePlugins as $plugin) {
            if (is_plugin_active($plugin)) {
                // Full jQuery + WooCommerce dependency chain — all must load eagerly when
                // a cookie-consent plugin is active, otherwise we get cascading
                // "X is not defined" ReferenceErrors as each script references the next.
                //
                // Order matters: jQuery → js-cookie → jquery.blockUI → woocommerce.min.js
                // → wc-add-to-cart → wc-checkout. Any one missing kills the chain.
                $cookieEcosystem = [
                    'jquery.min.js',
                    'jquery.js',
                    'jquery-migrate',
                    'jquery-ui',
                    'jquery.blockUI',
                    'js-cookie',        // enqueue handle
                    'js.cookie',        // filename (js.cookie.min.js — provides window.Cookies)
                    'woocommerce.min.js',
                    'wc-cart-fragments',
                    'wc-add-to-cart',
                    'wc-checkout',
                ];
                // Exclude from delay (must load eagerly for Complianz / consent gates)
                foreach ($cookieEcosystem as $script) {
                    $this->excludes[] = $script;
                }
                // ALSO defer them — otherwise they're render-blocking in the critical path.
                // `defer` lets them download in parallel with HTML parsing, executing in document
                // order after parse completes but before DOMContentLoaded. Complianz, WC, etc.
                // only reference each other via DOMContentLoaded handlers, so load order is
                // preserved. Gets these scripts OUT of the LCP critical path (PageSpeed wins).
                foreach ($cookieEcosystem as $script) {
                    $this->deferPatterns[] = $script;
                }

                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('COOKIE_PLUGIN_DETECTED', basename($plugin) . ' → jQuery/WooCommerce ecosystem excluded-from-delay AND deferred (non-blocking)');
                }
                break;
            }
        }

	    if (isset(self::$settings['gtag-lazy']) && self::$settings['gtag-lazy'] == '0') {
		    $this->excludes[] = 'gtag';
		    $this->excludes[] = 'googletag';
	    }

        $this->priority_run = ['document.addEventListener("DOMContentLoaded",()=>(document.body.style.visibility="inherit"));'];

        $this->userExcludes = new wps_ic_excludes();

        // Auto-defer WPC's own scripts — no inline dependencies, safe on all sites
        $this->deferPatterns = [
            'optimizer.adaptive',
            'optimizer.pixel',
            'optimizer.local',
            'optimizer.min',
            'wpcompress-aio',
        ];
        $this->userDeferScripts = $this->userExcludes->deferScripts();
    }


    public function removeNoDelay($tag)
    {
        if (is_array($tag)) {
            $tag = $tag[0];
        }

        $tagLower = strtolower($tag);

        // It's excluded
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

        $this->script_registry = array();
        $this->script_id = 0;

        $pattern = '/<script\b[^>]*>(.*?)<\/script>/si';

        $html = preg_replace_callback($pattern, array($this, 'process_script_tag'), $html);

        //Integrations
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

    private function elementor_integration($html)
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

    private function process_elementor_animations($html)
    {
        // Check if there are hidden elements
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

                    // Add wpc-lazyload class
                    if (strpos($new_match, 'wpc-lazyload') === false) {
                        if (preg_match('/class=["\']([^"\']*)["\']/', $new_match, $class_match)) {
                            $existing_classes = $class_match[1];
                            $new_classes = $existing_classes . ' wpc-lazyload';
                            $new_match = str_replace($class_match[0], 'class="' . $new_classes . '"', $new_match);
                        } else {
                            $new_match = str_replace('>', ' class="wpc-lazyload">', $new_match);
                        }
                    }

                    // Add wpc-elementor-animation attribute
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

    /**
     * Generate JavaScript for handling Elementor animations
     *
     * @return string JavaScript code
     */
    private function generate_animation_script()
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

    private function process_script_tag($matches)
    {
        $full_script = $matches[0];
        $script_content = isset($matches[1]) ? $matches[1] : '';

        $attributes = $this->parse_script_attributes($full_script);

        if ($this->should_exclude_script($attributes, $script_content)) {
            // Excluded from delay — but still add defer if user opted in via "Scripts to Defer"
            if ($this->should_defer_script($attributes)) {
                if (strpos($full_script, 'defer') === false && strpos($full_script, 'async') === false) {
                    return str_replace('<script ', '<script defer ', $full_script);
                }
            }
            return $full_script;
        }

        if ($this->should_defer_script($attributes)) {
            if (strpos($full_script, 'defer') === false && strpos($full_script, 'async') === false) {
                return str_replace('<script ', '<script defer ', $full_script);
            }
            return $full_script;
        }

        // v7.08.45/46 — Naturalize the delayed-script src AND inline content BEFORE base64-encoding
        // into the registry. loader.min.js decodes these and preloads/loads/injects them; storing the
        // /m:N/a: transform form makes the browser 302→native per delayed asset. The HTML naturalize
        // passes can't reach these — they're base64-encoded. The src field covers external scripts;
        // the content body covers inline scripts that REFERENCE module/asset URLs in /m:N/a: form
        // (e.g. WP's script-module preload/import inline script → interactivity/index, wp-emoji).
        // v7.02.45 — The delayed-script registry was the ONLY asset surface still on the m:0/a: transform
        // form while the page's CSS/JS/fonts all went natural. Those collapse via the UNGATED
        // wpc_asset_naturalize() buffer pass (filter wpc_asset_naturalize_enabled, default on);
        // natural_assets_on() reads false here because it's evaluated at script_loader_tag, BEFORE the
        // negotiated-delivery verdict settles — so the delayed JS fell out of lockstep with every other
        // asset. Gate on natural_assets_on() OR that same single off-ramp so the delayed-JS registry and
        // the page assets always naturalize together (one filter disables both). naturalize_asset_urls()
        // still no-ops safely if the zone is empty, and m:0 is a pass-through, so delivery is byte-identical.
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
                // v7.08.49 — belt-and-suspenders: a data-* attribute could carry a /m:N/a: asset
                // URL (loader.min.js replays these onto the injected tag). Naturalize it too, same
                // gate as src/content. No-op unless natural assets are on AND the value contains /a:.
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

    private function parse_script_attributes($script_tag)
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

    private function should_exclude_script($attributes, $content = '')
    {
        if (!empty($attributes['data-priority']) && $attributes['data-priority'] === 'high') {
            return true;
        }

        if (!empty($attributes['data-nodefer'])) {
            return true;
        }

        if (!empty($attributes['type']) && in_array($attributes['type'], ['text/mf', 'application/ld+json', 'text/template', 'wpc-delay-placeholder'])) {
            return true;
        }

        if (!empty($attributes['src'])) {
            if ($this->checkKeyword($attributes['src'], $this->excludes)) {
                // Diagnostic: capture when jQuery-ecosystem or WPC vars fire on the exclude path
                if (function_exists('wpc_diagnostic_log')) {
                    $src = $attributes['src'];
                    if (stripos($src, 'jquery') !== false || stripos($src, 'blockui') !== false || stripos($src, 'woocommerce') !== false || stripos($src, 'wc-') !== false) {
                        wpc_diagnostic_log('DELAY_EXCLUDE_JQ', basename($src));
                    }
                }
                return true;
            }

            // User excludes
            if ($this->userExcludes->excludedFromDelayV2($attributes['src'])) {
                return true;
            }
        }

        if (!empty($content) && $this->checkKeyword($content, $this->excludes)) {
            // Diagnostic: capture when WPC inline vars are preserved (not delayed)
            if (function_exists('wpc_diagnostic_log')) {
                if (stripos($content, 'ngf298gh738qwbdh0s87v_vars') !== false) {
                    wpc_diagnostic_log('VARS_PRESERVED', 'ngf298gh738qwbdh0s87v_vars (adaptive optimizer)');
                } elseif (stripos($content, 'n489D_var') !== false) {
                    wpc_diagnostic_log('VARS_PRESERVED', 'n489D_var (js_delay config)');
                }
            }
            return true;
        }

        // User excludes
        if ($this->userExcludes->excludedFromDelayV2($content)) {
            return true;
        }

        return false;
    }

    private function should_defer_script($attributes)
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
                    return true; // Match found
                }
            }
        }

        return false; // No match found
    }

    private function is_priority_run($attributes = [], $content = '')
    {
        if (!empty($content) && $this->checkKeyword($content, $this->priority_run)) {
            return true;
        }

        return false;
    }

}