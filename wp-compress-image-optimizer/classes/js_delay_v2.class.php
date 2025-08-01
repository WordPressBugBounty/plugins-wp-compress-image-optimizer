<?php

class wps_ic_js_delay_v2
{

    private $script_registry;
    private $script_id;
    private $excludes;
    private $priority_run;

    private $userExcludes;

    public function __construct()
    {
        $this->script_registry = array();
        $this->script_id = 0;
        $this->excludes = ['dark-mode', // dark mode switcher
          'n489D_var',
          'wpcRunningCritical',
          'trustLogo', // css safety service, uses document.write
          'turnstile', // had delayed loading detection, throws error
          'document.write'
        ];

        $this->priority_run = ['document.addEventListener("DOMContentLoaded",()=>(document.body.style.visibility="inherit"));'];

        $this->userExcludes = new wps_ic_excludes();
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

        $delay_script .= '<script>var wpcScriptRegistry=' . json_encode($this->script_registry) . ';</script>';
        if (empty(get_option('wps_ic_delay_v2_debug'))) {
            $delay_script .= '<script src="https://optimize-v2.b-cdn.net/loader.min.js?icv='.WPS_IC_HASH.'"></script>';
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
            return $full_script;
        }

        $script_data = array('id' => 'delayed-script-' . $this->script_id++, 'src' => isset($attributes['src']) ? base64_encode(html_entity_decode($attributes['src'])) : '', 'content' => !empty($attributes['src']) ? '' : base64_encode($script_content), 'type' => isset($attributes['type']) ? $attributes['type'] : 'text/javascript', 'encoded' => true, 'attributes' => array());

        foreach ($attributes as $attr => $value) {
            if (!in_array($attr, array('src', 'type'))) {
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
                    $value = isset($attr_match[3]) ? $attr_match[3] : (isset($attr_match[4]) ? $attr_match[4] : true);
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

        if (!empty($attributes['type']) && in_array($attributes['type'], ['application/ld+json', 'text/template', 'wpc-delay-placeholder'])) {
            return true;
        }

        if (!empty($attributes['src'])) {
            if ($this->checkKeyword($attributes['src'], $this->excludes)) {
                return true;
            }

            // User excludes
            if ($this->userExcludes->excludedFromDelayV2($attributes['src'])) {
                return true;
            }
        }

        if (!empty($content) && $this->checkKeyword($content, $this->excludes)) {
            return true;
        }

        // User excludes
        if ($this->userExcludes->excludedFromDelayV2($content)) {
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