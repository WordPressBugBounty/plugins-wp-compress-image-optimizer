<?php
/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */
class wps_ic_js_delay_v2
{


    protected $script_registry;
    protected $script_id;
    protected $excludes;
    protected $priority_run;

    // WooCommerce's cart fragments script, as the keep list can see it: the keep list is matched
    // against the script's src only (never its id), and the file is
    // woocommerce/assets/js/frontend/cart-fragments(.min).js. Written as the handle name
    // wc-cart-fragments it matched nothing, so the script meant to stay eager was delayed until
    // a gesture and a shopper's header cart showed 0 until the first click (ticket 12239).
    const WOO_CART_FRAGMENTS = '/cart-fragments';

    // The jQuery/WooCommerce chain kept out of delay on a WooCommerce site or beside a consent
    // manager (the cart, the variation form and the checkout must work before a gesture). One
    // list, read by both branches here and by the v3 engine's closure.
    const WOO_EAGER_CHAIN = ['jquery.min.js', 'jquery.js', 'jquery-migrate', 'jquery-ui', 'jquery.blockUI',
        'js-cookie', 'js.cookie', 'woocommerce.min.js', self::WOO_CART_FRAGMENTS,
        'wc-add-to-cart', 'wc-checkout'];

    protected $userExcludes;
    protected $deferPatterns;
    protected $userDeferScripts;
    protected $userForceDelay;
    public static $settings;
    public function __construct()
    {
		self::$settings = function_exists('wpc_levered_settings') ? wpc_levered_settings(get_option(WPS_IC_SETTINGS)) : get_option(WPS_IC_SETTINGS);
        $this->script_registry = array();
        $this->script_id = 0;
        $this->excludes = ['dark-mode',
          'woocommerce-no-js',
          'wpcJqueryDeferMarker', // .52: the jquery-defer replay-gate marker must run at parse — a delay pass eating it disarms the gate
          'n489D_var', // WPC js_delay config vars (inline block must run at parse time)
          'ngf298gh738qwbdh0s87v_vars', // WPC adaptive optimizer config vars (inline wp_localize_script output)
          'wpcRunningCritical',
          'wpc-presc-reserve', // A3 uniqueness guard must run at parse (apply-time count)
          'wpc-icon-belt', // icon-guard settle belt: must observe fonts as they load, not on gesture
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
          // Rename-proof twins: security plugins that rewrite /wp-includes/ (Hide My WP)
          // serve these as /lib/js/dist/hooks.min.js, where the forms above match nothing
          // and the keep fails silently — the dependency gets delayed while its eager
          // dependants (Elementor, WPForms) call wp.hooks and half-render.
          'js/dist/hooks',
          'js/dist/i18n',
          'wp-polyfill',
          'document.write',
          'wpc-ga-bot-shield',
          'sourcebuster',
          'SR7.', // Slider Revolution inline scripts, load ugly if delayed
          // GDPR / Cookie consent plugins — must run immediately to show/hide banners
          'gdpr-cookie-consent', // WP Cookie Consent (GDPR Cookie Consent)
          'cookie-law-info',
          'cookieyes', // CookieYes
          'complianz', // Complianz GDPR
          'cmplz', // Complianz shorthand
          'cookie-notice', // Cookie Notice by dFactory
          'cookie-consent', // Generic cookie consent
          'moove_gdpr', // Moove GDPR Cookie Compliance
          'osano', // Osano Cookie Consent
          'termly', // Termly Consent
          'iubenda',
          'wpl_cookie_consent', // WP Legal Pages cookie consent
          'wpl_viewed_cookie', // WP Cookie Consent inline check
          'CookieConsent', // Cookiebot / CookieConsent
          'cookiebot', // Cookiebot
          'tarteaucitron',
          'onetrust', // OneTrust
          'quantcast', // Quantcast Choice
          'usercentrics', // Usercentrics
          'consently',
          'didomi', // Didomi CMP
          'trustarc', // TrustArc
          'truste.com', // TrustArc legacy host (anchored: 'truste' substring-hits trustedform/trustedshops)
          'sourcepoint', // Sourcepoint CMP
          'axeptio', // Axeptio
          'klaro', // Klaro
          'securiti.ai', // Securiti CMP (anchored: 'securiti' substring-hits 'securities')
          'real-cookie-banner', // Real Cookie Banner (devowl)
          'devowl',
          'realCookieBanner',  // JS global — this list is case-sensitive


          'form_embed',       // GoHighLevel / LeadConnector form_embed.js (sizes + reveals the form iframe)
          'msgsndr',          // GoHighLevel / LeadConnector (link.msgsndr.com)
          'leadconnectorhq',  // LeadConnector widget host
          'hsforms',          // HubSpot Forms loader (js.hsforms.net)
          'hbspt',            // HubSpot inline embed (hbspt.forms.create)
          'calendly',         // Calendly embed (reveals/sizes the booking iframe)
          'typeform',         // Typeform embed
          'jotform',          // JotForm embed
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


                $cookieEcosystem = self::WOO_EAGER_CHAIN;
                // Exclude from delay (must load eagerly for Complianz / consent gates)
                foreach ($cookieEcosystem as $script) {
                    $this->excludes[] = $script;
                }


                foreach ($cookieEcosystem as $script) {
                    // v7.21.136 — jQuery CORE never rides the naive defer list: a bare defer on
                    // jquery flips plugin-off ordering for every EAGER dependent outside this
                    // hardcoded list (optica-suiza: eager wp-util + inline callers -> "jQuery is
                    // not defined"). Only the jq-defer lane may defer jquery — it converts every
                    // subsequent dependent (external->defer, inline->data:URI rider) and arms the
                    // replay gate. Chain leaves (wc-*, blockUI, migrate) stay defer-listed:
                    // deferred order still puts them after an eager OR lane-deferred jquery.
                    if ($script === 'jquery.min.js' || $script === 'jquery.js') { continue; }
                    $this->deferPatterns[] = $script;
                }

                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('COOKIE_PLUGIN_DETECTED', basename($plugin) . ' → jQuery/WooCommerce ecosystem excluded-from-delay AND deferred (non-blocking)');
                }
                break;
            }
        }
        // WooCommerce WITHOUT a listed consent plugin: the cart/variation chain
        // still must run eagerly — a delayed wc-cart-fragments lies about the
        // mini-cart and a delayed variation form deadens the money path.
        if (class_exists('WooCommerce') && !in_array(self::WOO_CART_FRAGMENTS, (array) $this->excludes, true)) {
            foreach (self::WOO_EAGER_CHAIN as $woo_script) {
                $this->excludes[] = $woo_script;
                // v7.21.136 — same rule as the cookie chain above: jquery core is excluded-eager
                // here; ONLY the jq-defer lane may defer it (full dependent closure + gate).
                if ($woo_script === 'jquery.min.js' || $woo_script === 'jquery.js') { continue; }
                $this->deferPatterns[] = $woo_script;
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

        // Auto-defer WPC's own scripts — no inline dependencies, safe on all sites.
        // MERGE, never reassign: the cookie/Woo eager-chain appends above must
        // survive or their scripts run parse-blocking instead of deferred.
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


            foreach ((array) apply_filters('wpc_revslider_instant_deps', ['sr7', 'frontend-modules']) as $revslider_dep) {
                $this->userDeferScripts[] = $revslider_dep;
            }
        }


        $this->userForceDelay = $this->userExcludes->lastLoadScripts();


        foreach ((array) apply_filters('wpc_builtin_force_delay', ['sr7-elementor-preview']) as $forced_delay_script) {
            if (!is_array($this->userForceDelay)) { $this->userForceDelay = []; }
            $this->userForceDelay[] = $forced_delay_script;
        }
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

    protected function elementor_integration($html)
    {
        $elementor_result = $this->process_elementor_animations($html);
        if (!$elementor_result) {
            return $html;
        }

        $head_end = strpos($elementor_result['html'], '</head>');
        if ($head_end === false) {
            return $html;
        }

        return substr($elementor_result['html'], 0, $head_end) . $elementor_result['script'] . substr($elementor_result['html'], $head_end);
    }

    protected function wpc_ela_plan($tag)
    {
        if (strpos($tag, 'data-wpc-ela') !== false
            || !preg_match('/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $class_match)) {
            return null;
        }
        $classes = preg_split('/\s+/', trim($class_match[1] !== '' ? $class_match[1] : (isset($class_match[2]) ? $class_match[2] : '')));
        if (!in_array('elementor-invisible', $classes, true)
            || !preg_match('/\sdata-settings\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $settings_match)) {
            return null;
        }

        $settings = json_decode(html_entity_decode($settings_match[1] !== '' ? $settings_match[1] : (isset($settings_match[2]) ? $settings_match[2] : ''), ENT_QUOTES, 'UTF-8'), true);
        if (!is_array($settings)) {
            return null;
        }

        $names = array();
        foreach (array('', '_tablet', '_mobile') as $suffix) {
            $name = '';
            foreach (array('animation', '_animation') as $base) {
                if (isset($settings[$base . $suffix]) && is_string($settings[$base . $suffix]) && $settings[$base . $suffix] !== '') {
                    $name = $settings[$base . $suffix];
                    break;
                }
            }
            if ($name !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9]{0,40}$/', $name)) {
                return null;
            }
            $names[] = $name;
        }
        if ($names[0] === '' && $names[1] === '' && $names[2] === '') {
            return null;
        }

        $delay = 0;
        foreach (array('_animation_delay', 'animation_delay') as $key) {
            if (isset($settings[$key]) && (is_int($settings[$key]) || is_float($settings[$key]) || (is_string($settings[$key]) && is_numeric($settings[$key]))) && (int) $settings[$key] > 0) {
                $delay = min(20000, (int) $settings[$key]);
                break;
            }
        }

        $speed = '';
        if (in_array('animated-slow', $classes, true)) {
            $speed = 'slow';
        } elseif (in_array('animated-fast', $classes, true)) {
            $speed = 'fast';
        } elseif (isset($settings['animation_duration']) && is_string($settings['animation_duration']) && in_array($settings['animation_duration'], array('slow', 'fast'), true)) {
            $speed = $settings['animation_duration'];
        }

        return array('names' => $names, 'delay' => $delay, 'speed' => $speed);
    }

    protected function wpc_ela_file($relative)
    {
        $root = defined('ELEMENTOR_PATH') ? (string) ELEMENTOR_PATH : (defined('WP_PLUGIN_DIR') ? rtrim((string) WP_PLUGIN_DIR, '/\\') . '/elementor/' : '');
        if ($root === '') {
            return '';
        }
        $file = rtrim($root, '/\\') . '/' . $relative;
        if (!is_file($file) || filesize($file) > 262144) {
            return '';
        }
        $content = @file_get_contents($file);

        return is_string($content) ? $content : '';
    }

    protected function wpc_ela_duration_rules()
    {
        $rules = '';
        $frontend = $this->wpc_ela_file('assets/css/frontend.min.css');
        if ($frontend !== '') {
            $reduced_rule = preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.animated\{[^{}]*animation:\s*none/', $frontend)
                ? '@media (prefers-reduced-motion:reduce){.animated{animation:none!important}}' : '';
            $top_level = preg_replace('/@[a-z-]+[^{};]*(\{(?:[^{}]++|(?1))*\})/i', '', $frontend);
            if (is_string($top_level) && preg_match_all('/(?<![\w.-])\.animated(?:\.animated-(?:slow|fast)|\.infinite|\.reverse)?\{[^{}]*\}/', $top_level, $found)) {
                $rules = implode('', $found[0]);
            }
            $rules .= $reduced_rule;
        }
        if (strpos($rules, '.animated-slow') === false || strpos($rules, '.animated-fast') === false) {
            $rules = '.animated{animation-duration:1.25s}.animated.animated-slow{animation-duration:2s}.animated.animated-fast{animation-duration:.75s}.animated.infinite{animation-iteration-count:infinite}.animated.reverse{animation-direction:reverse;animation-fill-mode:forwards}@media (prefers-reduced-motion:reduce){.animated{animation:none!important}}';
        }

        return $rules;
    }

    protected function wpc_ela_breakpoints()
    {
        $mobile = 767;
        $tablet = 1024;
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance) && is_object(\Elementor\Plugin::$instance)
            && isset(\Elementor\Plugin::$instance->breakpoints) && is_object(\Elementor\Plugin::$instance->breakpoints)
            && method_exists(\Elementor\Plugin::$instance->breakpoints, 'get_breakpoints_config')) {
            $config = \Elementor\Plugin::$instance->breakpoints->get_breakpoints_config();
            if (is_array($config)) {
                if (isset($config['mobile']['value']) && (int) $config['mobile']['value'] > 0) {
                    $mobile = (int) $config['mobile']['value'];
                }
                if (isset($config['tablet']['value']) && (int) $config['tablet']['value'] > $mobile) {
                    $tablet = (int) $config['tablet']['value'];
                }
            }
        }

        return array($mobile, $tablet);
    }

    protected function process_elementor_animations($html)
    {
        if (!is_string($html) || stripos($html, 'elementor-invisible') === false || strpos($html, '</head>') === false) {
            return null;
        }
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_csp_blocks_inline_scripts')
            && wps_cdn_rewrite::wpc_csp_blocks_inline_scripts($html)) {
            return null;
        }

        $parts = preg_split('#(<script\b.*?</script>|<style\b.*?</style>|<template\b.*?</template>|<!--.*?-->)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return null;
        }

        $tag_pattern = '/<[a-z][a-z0-9]*\b[^>]*(?<![\w-])elementor-invisible(?![\w-])[^>]*>/i';
        $plans = array();
        $wanted = array();
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1 || stripos($part, 'elementor-invisible') === false
                || !preg_match_all($tag_pattern, $part, $tags)) {
                continue;
            }
            foreach ($tags[0] as $tag) {
                $plan = $this->wpc_ela_plan($tag);
                if ($plan === null) {
                    continue;
                }
                $plans[$tag] = $plan;
                foreach ($plan['names'] as $name) {
                    if ($name !== '' && $name !== 'none') {
                        $wanted[$name] = true;
                    }
                }
            }
        }
        if (empty($plans)) {
            return null;
        }

        $sheets = array();
        $modified_html = $html;
        $sheet_reader = null;
        $url_key = null;
        foreach (array_keys($wanted) as $name) {
            $content = $this->wpc_ela_file('assets/lib/animations/styles/' . $name . '.min.css');
            $original_link = '';
            if ($content === '' && preg_match('/<link[^>]*href=["\']([^"\']*' . preg_quote($name, '/') . '\.min\.css[^"\']*)["\'][^>]*>/i', $modified_html, $css_match)) {
                $original_link = $css_match[0];
                if ($sheet_reader === null) {
                    $sheet_reader = new wps_ic_crit_corpus();
                    $sheet_reader->remote_deadline = microtime(true) + 4.0;
                    $url_key = new wps_ic_url_key();
                }
                $content = $url_key->is_external($css_match[1]) ? $sheet_reader->getRemoteContent($css_match[1]) : $sheet_reader->getLocalContent($css_match[1]);
                $content = is_string($content) ? $content : '';
            }
            if ($content === '' || stripos($content, 'keyframes') === false || stripos($content, '</style') !== false) {
                continue;
            }
            $sheets[$name] = $content;
            if ($original_link !== '') {
                $modified_html = str_replace($original_link, '', $modified_html);
            }
        }

        $owned = 0;
        $parts = preg_split('#(<script\b.*?</script>|<style\b.*?</style>|<template\b.*?</template>|<!--.*?-->)#si', $modified_html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return null;
        }
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1 || stripos($part, 'elementor-invisible') === false) {
                continue;
            }
            $parts[$index] = preg_replace_callback($tag_pattern, function ($found) use ($plans, $sheets, &$owned) {
                $tag = $found[0];
                if (!isset($plans[$tag])) {
                    return $tag;
                }
                foreach ($plans[$tag]['names'] as $name) {
                    if ($name !== '' && $name !== 'none' && !isset($sheets[$name])) {
                        return $tag;
                    }
                }
                $owned++;
                $value = implode('|', array($plans[$tag]['names'][0], $plans[$tag]['names'][1], $plans[$tag]['names'][2], $plans[$tag]['delay'] > 0 ? $plans[$tag]['delay'] : '', $plans[$tag]['speed']));

                return preg_replace('/^<([a-z][a-z0-9]*)/i', '<${1} data-wpc-ela="' . $value . '"', $tag, 1);
            }, $part);
            if (!is_string($parts[$index])) {
                return null;
            }
        }
        if ($owned === 0) {
            return null;
        }

        $breakpoints = $this->wpc_ela_breakpoints();
        $style = '<style id="wpc-ela-css">' . $this->wpc_ela_duration_rules() . implode('', $sheets) . '</style>';

        return array('script' => $style . $this->generate_animation_script($breakpoints[0], $breakpoints[1]), 'html' => implode('', $parts));
    }

    protected function generate_animation_script($mobile_max = 767, $tablet_max = 1024)
    {
        return '<script id="wpc-ela" data-nodefer="1">(function(){var d=document,w=window,M=' . (int) $mobile_max . ',T=' . (int) $tablet_max . ',Q=[],io=null,moved=0,K=/^_?animation(?:_(?:tablet|mobile|laptop|widescreen|tablet_extra|mobile_extra|delay))?$/;'
            . 'function mv(){moved=1}'
            . '["scroll","wheel","touchmove","keydown","mousedown"].forEach(function(t){w.addEventListener(t,mv,{passive:true,capture:true})});'
            . 'function dev(){var x=w.innerWidth||d.documentElement.clientWidth||0;return x<=M?2:x<=T?1:0}'
            . 'function plan(e){var p=(e.getAttribute("data-wpc-ela")||"").split("|");return{n:[p[0]||"",p[1]||"",p[2]||""],y:parseInt(p[3],10)||0,u:p[4]||""}}'
            . 'function pick(p){var v=dev(),n=p.n;return v===2?(n[2]||n[1]||n[0]):v===1?(n[1]||n[0]):n[0]}'
            . 'function scrub(e){var s=e.getAttribute("data-settings"),o,k,j=w.jQuery;if(s){try{o=JSON.parse(s);for(k in o){if(K.test(k))delete o[k]}e.setAttribute("data-settings",JSON.stringify(o))}catch(x){e.removeAttribute("data-settings")}}'
            . 'if(j&&j.data){try{o=j.data(e,"settings");if(o&&typeof o==="object"){for(k in o){if(K.test(k))delete o[k]}}}catch(x){}}}'
            . 'function run(e,anim){if(e.__wpcEla)return;e.__wpcEla=1;if(!e.classList.contains("elementor-invisible"))return;var p=plan(e),n=anim?pick(p):"",c=e.classList,'
            . 'go=function(){e.removeAttribute("data-wpc-ela-q");c.remove("elementor-invisible");if(n&&n!=="none"){c.add("animated");c.add(n);if(p.u)c.add("animated-"+p.u)}};'
            . 'if(anim&&p.y>0&&n&&n!=="none"){e.setAttribute("data-wpc-ela-q","1");setTimeout(go,p.y)}else go()}'
            . 'function seen(list){var i,en,e,r;for(i=0;i<list.length;i++){en=list[i];e=en.target;if(!en.isIntersecting)continue;io.unobserve(e);r=en.boundingClientRect||e.getBoundingClientRect();run(e,r.width>0&&r.height>0?moved:0)}}'
            . 'function own(e){if(e.__wpcOwn)return;e.__wpcOwn=1;scrub(e);Q.push(e);if(io)io.observe(e);else run(e,0)}'
            . 'function added(R){var i,j,k,N,n,L;for(i=0;i<R.length;i++){N=R[i].addedNodes;for(j=0;j<N.length;j++){n=N[j];if(n.nodeType!==1)continue;if(n.hasAttribute("data-wpc-ela"))own(n);L=n.querySelectorAll("[data-wpc-ela]");for(k=0;k<L.length;k++)own(L[k])}}}'
            . 'function init(){var L=d.querySelectorAll("[data-wpc-ela]"),i;if(!L.length)return;if(w.IntersectionObserver)io=new IntersectionObserver(seen,{threshold:[0,1]});'
            . 'for(i=0;i<L.length;i++)own(L[i]);'
            . 'if(!Q.length)return;if(w.MutationObserver)new MutationObserver(added).observe(d.documentElement,{childList:true,subtree:true});'
            . 'w.addEventListener("load",function(){for(var k=0;k<Q.length;k++)scrub(Q[k])})}'
            . 'if(d.readyState==="loading")d.addEventListener("DOMContentLoaded",init);else init()})();</script>';
    }

    protected function process_script_tag($matches)
    {
        $full_script = $matches[0];
        $script_content = isset($matches[1]) ? $matches[1] : '';

        $attributes = $this->parse_script_attributes($full_script);

        // This keep-path defer bypasses the paired/jQuery guards downstream (it returns before
        // they run), so it must consult them itself: a script whose handle has an inline -after
        // companion, or a jQuery tag on a page whose companions call jQuery at parse, must stay
        // truly eager — deferring it here re-creates the order inversion the guards exist for.
        $may_defer = true;
        $script_id = isset($attributes['id']) ? strtolower(trim((string) $attributes['id'])) : '';
        if ($script_id !== '') {
            if (!empty($this->wpc_inline_pair_ids[$script_id])) {
                $may_defer = false;
            }
            if ($may_defer && !empty($this->wpc_jquery_parse_needed)
                && method_exists($this, 'wpc_is_jquery_script_id') && static::wpc_is_jquery_script_id($script_id)) {
                $may_defer = false;
            }
        }

        if ($this->should_exclude_script($attributes, $script_content)) {
            // Excluded from delay — but still add defer if user opted in via "Scripts to Defer"
            if ($may_defer && $this->should_defer_script($attributes)) {
                if (strpos($full_script, 'defer') === false && strpos($full_script, 'async') === false) {
                    return str_replace('<script ', '<script defer ', $full_script);
                }
            }
            return $full_script;
        }

        if ($this->should_defer_script($attributes)) {
            if (!$may_defer) {
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
            // v7.10.495 — MATCH THE KEEP LIST ON id AS WELL AS src. Our own JS combine rewrites a
            // script's src to /wp-content/<hash>/dist/<n>.js, which erases every vendor name the
            // keep list is written in terms of — so on any combine-enabled site the whole 28-entry
            // consent keep list was silently inert. The id survives combining
            // (real-cookie-banner-pro-banner_tcf-js), so it is the durable identifier.
            $keep_subject = (string) $attributes['src']
                . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id'])
                    ? ' ' . (string) $attributes['id'] : '');
            if ($this->checkKeyword($keep_subject, $this->excludes)) {
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
            if ($this->wpc_user_delay_excluded($attributes['src'])) {
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
        if ($this->wpc_user_delay_excluded($content)) {
            return true;
        }

        return false;
    }

    // The user's Delay JS exclude list. Both engines read the one list (`delay_js_v3`, see
    // wpc_delay_excludes_fold in defines.php); v3 overrides this only to ask its own method name.
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
                    return true; // Match found
                }
            }
        }

        return false; // No match found
    }

    protected function is_priority_run($attributes = [], $content = '')
    {
        if (!empty($content) && $this->checkKeyword($content, $this->priority_run)) {
            return true;
        }

        return false;
    }

}