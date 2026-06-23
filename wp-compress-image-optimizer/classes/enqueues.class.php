<?php


/**
 * Class - Enqueues
 */
class wps_ic_enqueues extends wps_ic
{

    public static $version;
    public static $showModes;
    public static $slug;
    public static $css_combine;
    public static $settings;
    public static $quality;
    public static $zone_name;
    public static $js_debug;
    public static $api_key;
    public static $response_key;
    public static $site_url;
    public static $delay_js_override;
    public static $defer_js_override;
    public static $lazy_override;
    public static $preloaderAPI;
    public static $slider_compatibility;
    public static $page_excludes;
    private static $isAmp;
    public $js_delay;

    public function __construct()
    {
        $this::$slug = parent::$slug;
        $this::$version = parent::$version;
        self::$settings = parent::$settings;
        self::$zone_name = parent::$zone_name;
        self::$quality = parent::$quality;
        self::$js_debug = parent::$js_debug;
        self::$api_key = parent::$api_key;
        self::$response_key = parent::$response_key;
        self::$site_url = site_url();
        self::$preloaderAPI = 0;
        self::$isAmp = new wps_ic_amp();
        $users = new wps_ic_users();

        if (self::$isAmp->isAmp()) {
            self::$settings['delay-js'] = '0';
            self::$settings['inline-js'] = '0';
        }

        $this::$showModes = false;
        if (empty(self::$settings)) {
            $this::$showModes = true;
        }

        if (!empty($_GET['override_version'])) {
            $this::$version = mt_rand(100, 999);
        }

        if (function_exists('perfmatters_version_check')) {
            $perfmatters_options = get_option('perfmatters_options');

            if (!empty($perfmatters_options['assets']['delay_js']) && $perfmatters_options['assets']['delay_js']) {
                self::$delay_js_override = 1;
            }

            if (!empty($perfmatters_options['assets']['defer_js']) && $perfmatters_options['assets']['defer_js']) {
                self::$defer_js_override = 1;
            }

            if (!empty($perfmatters_options['lazyload']['lazy_loading']) && $perfmatters_options['lazyload']['lazy_loading']) {
                self::$lazy_override = 1;
            }
        }

        //Rocket settings check
        if (function_exists('get_rocket_option')) {
            $rocket_settings = get_option('wp_rocket_settings');

            if ($rocket_settings['delay_js']) {
                self::$delay_js_override = 1;
            }

            if ($rocket_settings['defer_all_js']) {
                self::$defer_js_override = 1;
            }

            if ($rocket_settings['lazyload']) {
                self::$lazy_override = 1;
            }
        }

        if (strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false || !empty($_GET['dbg_preload'])) {
            self::$preloaderAPI = 1;
        }

        $this->js_delay = new wps_ic_js_delay();

        // Setup CF CNAME
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);
        // Honor the fail-open verified-gate (consistent with the cdn-rewrite resolver) so
        // CSS/JS + the localized zoneName don't emit a cname that's mid-change-unverified ('0').
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok())) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname)) {
            self::$zone_name = $custom_cname;
        }

        if (!empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['tatsu']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['PageSpeed']) || !empty($_GET['et_fb']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['tve']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['ct_builder']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php" || strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false)) {
            // Do nothing
        } else {

            if ($this->isAgencyPortal()) {
                // Only enqueue plugin UI assets on the view-site page, not dashboard/other pages
                if ($this->extractApiKey()) {
                    add_action('wp_enqueue_scripts', [$this, 'agencyScripts']);
                }

                if (!function_exists('get_current_screen')) {
                    require_once ABSPATH . 'wp-admin/includes/screen.php';
                }

                return;
            }

            add_action('wp_enqueue_scripts', [$this, 'enqueueFrontend'], 1);

            // Gravity Forms cached-nonce fix: lazily refresh the GF nonce on front-end pages (logged-in and
            // logged-out) so the page can stay fully cached — at WPC and at the CDN/CF edge — without
            // "Nonce expired" on submit. No-op unless Gravity Forms is active and a gform_* form is rendered.
            add_action('wp_enqueue_scripts', [$this, 'gfNonceRefresh'], 20);

            if (is_admin()) {

                if (!empty($_GET['page']) && ($_GET['page'] == 'wpcompress-mu')) {
                    // Multisite
                    add_action('admin_enqueue_scripts', [$this, 'enqueue_all_scripts']);
                    add_action('admin_enqueue_scripts', [$this, 'enqueue_v4']);
                } elseif (!empty($_GET['view']) && ($_GET['view'] == 'advanced_settings_v4')) {
                    add_action('admin_enqueue_scripts', [$this, 'enqueue_v4']);
                } elseif (!empty($_GET['view']) && $_GET['view'] == 'advanced_settings_v3') {
                    add_action('admin_enqueue_scripts', [$this, 'enqueue_v3']);
                } elseif (!empty($_GET['view']) && ($_GET['view'] == 'advanced_settings_v2' || $_GET['view'] == 'critical' || $_GET['view'] == 'exclude')) {
                    add_action('admin_enqueue_scripts', [$this, 'enqueue_v2']);
                } else {

                    add_action('admin_enqueue_scripts', [$this, 'enqueue_all_scripts']);

                    if (!empty($_GET['view']) && $_GET['view'] == 'bulk') {
                        add_action('admin_enqueue_scripts', [$this, 'enqueue_bulk']);
                    }

                    if (!empty($_GET['view']) && $_GET['view'] == 'preload') {
                        add_action('admin_enqueue_scripts', [$this, 'enqueue_bulk']);
                    }

                }
            } else {
                add_action('wp_print_scripts', [$this, 'inlineFrontend'], 1);
                add_action('wp_footer', [$this, 'inline_delay_v2_placeholder'], PHP_INT_MAX);
                if (!self::$isAmp->isAmp()) {
                    /**
                     * Remove CSS/JS Versioning - required for CDN
                     */ //this is now done in adjust_src_url() and rewrite_script_tag() AFTER excludes are checked
                    //add_filter('style_loader_src', [$this, 'removeVersion'], 9999);
                    //add_filter('script_loader_src', [$this, 'removeVersion'], 9999);

                    if (!is_user_logged_in() && !$this->isAgencyPortal()) {
                        if (empty($_GET['disableDelay2'])) {
                            if ((!empty(self::$settings['delay-js']) && self::$settings['delay-js'] == '1' && !self::$delay_js_override && !self::$preloaderAPI) || (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '1')) {
                                #add_filter('script_loader_tag', [$this->js_delay, 'delay_script_replace'], 10, 3);
                            } elseif (!empty(self::$settings['defer-js']) && self::$settings['defer-js'] == '1' && !self::$preloaderAPI) {
                                add_filter('script_loader_tag', [$this, 'deferJS'], 10, 3);
                            }
                        }
                    }
                }
            }
        }

        // Disable cart fragments for WooCommerce
        if ($this->isPluginActive('woocommerce/woocommerce.php') && !empty(self::$settings['disable-cart-fragments']) && self::$settings['disable-cart-fragments'] == 1) {
            add_action('wp_enqueue_scripts', [$this, 'disableCartFragments'], 999);
        }

    }

    /**
     * Inject a lazy Gravity Forms nonce-refresh on front-end pages. GF mints a per-session nonce into the
     * form; on a cached page that nonce freezes, so a different session gets "Nonce expired" on submit. This
     * fetches a fresh, current-session nonce (via admin-ajax, which is never cached) the FIRST time the
     * visitor interacts with the form, then writes it into the form's nonce field (creating the hidden field
     * if a cached page omitted it). Lazy-by-interaction = zero added requests for visitors who never touch a
     * form. Defensive: no-op if fetch/forms are absent or the request fails (form is no worse than today).
     */
    public function gfNonceRefresh()
    {
        if (!apply_filters('wpc_gf_nonce_refresh', true)) {
            return;
        }
        // Only when Gravity Forms is actually present — no cost on non-GF sites.
        if (!class_exists('GFForms') && !class_exists('GFCommon') && !function_exists('gravity_form')) {
            return;
        }

        $handle = $this::$slug . '-gf-nonce';
        wp_register_script($handle, '', [], $this::$version, true);
        wp_enqueue_script($handle);

        $js = <<<'JS'
(function(){
  if(!window.fetch||!document.querySelectorAll){return;}
  var AJAX="__WPC_AJAX__";
  var forms=document.querySelectorAll('form[id^="gform_"]');
  if(!forms.length){return;}
  Array.prototype.forEach.call(forms,function(form){
    var m=(form.getAttribute('id')||'').match(/gform_(\d+)/);
    if(!m){return;}
    var fid=m[1],pending=false,done=false;
    function refresh(){
      if(done||pending){return;}
      pending=true;
      fetch(AJAX,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=wpc_gf_refresh_nonce&form_id='+encodeURIComponent(fid)})
        .then(function(r){return r.json();})
        .then(function(res){
          pending=false;
          if(!res||!res.success||!res.data||!res.data.nonce||!res.data.field){return;}
          var name=res.data.field;
          var input=form.querySelector('input[name="'+name+'"]');
          if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.appendChild(input);}
          input.value=res.data.nonce;
          done=true;
        })
        .catch(function(){pending=false;});
    }
    form.addEventListener('focusin',refresh);
    form.addEventListener('pointerdown',refresh);
  });
})();
JS;
        $js = str_replace('__WPC_AJAX__', esc_url_raw(admin_url('admin-ajax.php')), $js);
        wp_add_inline_script($handle, $js);
    }

    public function isPluginActive($plugin)
    {
        $is_plugin_active_for_network = false;

        $plugins = get_site_option('active_sitewide_plugins');
        if (isset($plugins[$plugin])) {
            $is_plugin_active_for_network = true;
        }

        return in_array($plugin, (array)get_option('active_plugins', []), true) || $is_plugin_active_for_network;
    }

    public function inline_delay_v2_placeholder()
    {
        if (!empty(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1') {
            echo '<script type="wpc-delay-placeholder"></script>';
        }
    }

    public function disableCartFragments()
    {
        global $wp_scripts;

        $slug = 'wc-cart-fragments';
        if (isset($wp_scripts->registered[$slug]) && $wp_scripts->registered[$slug]) {
            $load_cart_fragments_path = $wp_scripts->registered[$slug]->src;
            $wp_scripts->registered[$slug]->src = null;
            wp_add_inline_script('jquery', 'function wpc_getCookie(name) {
					var c = document.cookie.match("(^|;) ?" + name + "=([^;]*)(;|$)");
					return c ? c[2] : null;
				}

				function wpc_check_cart_script() {
				
					if( document.getElementById("wpc_cart_fragments") !== null ) {
						return false;
					}

					if( wpc_getCookie("woocommerce_cart_hash") ) {
						var s = document.createElement("script");
						s.id = "wpc_cart_fragments";
						s.src = "' . $load_cart_fragments_path . '";
						document.head.appendChild(s);
					}
				}

				wpc_check_cart_script();
				document.addEventListener("click", function(){setTimeout(wpc_check_cart_script,1000);});');
        }
    }

    public function removeVersion($src)
    {
        if (!empty(self::$settings['css']) && self::$settings['css'] == '1') {
            // Remove for CSS Files
            if (strpos($src, '.css')) {
                if (strpos($src, '?ver=')) {
                    $src = remove_query_arg('ver', $src);
                }
            }
        }

        if (!empty(self::$settings['js']) && self::$settings['js'] == '1') {
            // Check for JS Files
            if (strpos($src, '.js')) {
                $verPosition = strpos($src, '?ver=');
                if ($verPosition !== false) {
                    // Truncate the src to remove '?ver=' and everything after it
                    $src = substr($src, 0, $verPosition);
                }
            }
        }

        return $src;
    }


    public function inlineFrontend()
    {
        //cannot be called before wp hook, we dont have $post then
        global $post;
        $excludes = get_option('wpc-excludes');
        if ($this->is_home_url()) {
            $page_excludes = isset($excludes['page_excludes']['home']) ? $excludes['page_excludes']['home'] : [];
        } else if (!empty(get_queried_object_id())) {
            $page_excludes = isset($excludes['page_excludes'][get_queried_object_id()]) ? $excludes['page_excludes'][get_queried_object_id()] : [];
        } elseif (!empty($post->ID)) {
            $page_excludes = isset($excludes['page_excludes'][$post->ID]) ? $excludes['page_excludes'][$post->ID] : [];
        } else {
            $page_excludes = [];
        }


        $delayActive = !(isset($page_excludes['delay_js']) && $page_excludes['delay_js'] == '0') && ((isset(self::$settings['delay-js']) && self::$settings['delay-js'] == '1') || (isset($page_excludes['delay_js']) && $page_excludes['delay_js'] == '1'));

        if (!$delayActive) {
            echo '<script type="text/javascript">';
        } else {
            echo '<script type="text/javascript">';
        }

        $triggerDom = "true";
        if (!empty(self::$settings['disable-trigger-dom-event']) && self::$settings['disable-trigger-dom-event'] == '1') {
            $triggerDom = "false";
        }

        $triggerElementor = "true";
        if (!empty(self::$settings['disable-elementor-triggers']) && self::$settings['disable-elementor-triggers'] == '1') {
            $triggerElementor = "false";
        }

        $delayOn = "false";
        if ($delayActive) {
            $delayOn = "true";
        }

        // Preload links on hover, hardcoded!
        $linkPreload = "false";
        if (is_user_logged_in()) {
            $linkPreload = "false";
        }

        $excludeLink = ['add-to-cart'];

        echo 'var n489D_vars={"triggerDomEvent":"' . $triggerDom . '", "delayOn":"' . $delayOn . '", "triggerElementor":"' . $triggerElementor . '", "linkPreload":"' . $linkPreload . '", "excludeLink":' . json_encode($excludeLink) . '};';
        echo '</script>';

        $optimizeRemove = get_option('wps_optimizejs_remove');
        $debugOptimize = get_option('wps_optimizejs_debug');

        $pullzone = 'optimizerwpc';
        if (!empty(self::$settings['eu-routing']) && self::$settings['eu-routing'] == '1') {
            $pullzone = 'eu-static';
        }

        if (empty($optimizeRemove)) {
            if (empty($debugOptimize) || $debugOptimize == 'false') {
                echo '<script type="text/javascript" src="https://' . $pullzone . '.b-cdn.net/optimize.js?ic_ver=' . WPS_IC_HASH . '" defer></script>';
            } else {
                echo '<script type="text/javascript" src="https://' . $pullzone . '.b-cdn.net/optimize.dev.js?ic_ver=' . WPS_IC_HASH . '" defer></script>';
            }
        }

        if (!empty(self::$settings['lazy']) && self::$settings['lazy'] == '1') {
            echo '<style type="text/css">';
            echo '.wpc-bgLazy,.wpc-bgLazy>*{background-image:none!important;}';
            echo '</style>';
        }
    }


    public function deferJS($tag, $handle, $src)
    {
        if (is_admin()) {
            return $tag;
        } //don't break WP Admin

        if (false === strpos($src, '.js')) {
            return $tag;
        }

        if (strpos($tag, 'hooks') !== false || strpos($tag, 'i18n') !== false || strpos($tag, 'jquery.js') !== false || strpos($tag, 'jquery.min.js') !== false || strpos($tag, 'jquery-migrate') !== false) {
            return $tag;
        }

        $tag = str_replace(' src=', ' defer src=', $tag);

        return $tag;
    }


    public function enqueueFrontend()
    {
        $options = self::$settings;

        // The `picture.wpc-picture{display:contents}` neutralization already ships from
        // cdn-rewrite.php:injectPreloadImages — no second emitter needed here. The audit's
        // "H2: no display:contents" was a false positive; existing code is correct.

        $lazy = 'false';
        if (!empty($options['lazy']) && $options['lazy'] == '1') {
            $lazy = 'true';
        }

        $webp = 'false';
        if (!empty($options['generate_webp']) && $options['generate_webp'] == '1') {
            $webp = 'true';
        }

        $adaptive = 'false';
        if (!empty($options['generate_adaptive']) && $options['generate_adaptive'] == '1') {
            $adaptive = 'true';
        }

        $background_sizing = 'false';
        if (!empty($options['background-sizing']) && $options['background-sizing'] == '1') {
            $background_sizing = 'true';
        }

        $retina = 'false';
        if (!empty($options['retina']) && $options['retina'] == '1') {
            $retina = 'true';
        }

        $exif = 'false';
        if (!empty($options['preserve_exif']) && $options['preserve_exif'] == '1') {
            $exif = 'true';
        }

        $retinaJS = '';
        if ($retina == 'true') {
            $retinaJS = '.pixel';
        }

        if (is_user_logged_in() && (current_user_can('manage_wpc_settings') || current_user_can('manage_wpc_purge'))) {
            // Required for Admin Bar
            wp_enqueue_style($this::$slug . '-admin-bar', WPS_IC_URI . 'assets/css/admin-bar.css', [], $this::$version);
            wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js', ['jquery'], $this::$version, true);
            wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());

            // Customer-activity HEAD poller. Default-off; enqueued only when the flag is
            // on so the splash count stays fresh as a belt-and-suspenders complement to
            // the updated_post_meta hook.
            //
            // Also enqueue when wpc_v2_pull_enabled is on. The pull long-poll loop lives
            // in the same file (v2-head-poll.js). Without this, pull is configured
            // server-side but the JS that drives it never loads, and Phase B variants sit
            // in BunnyCDN staging unconsumed. Hit on staging-2 image 16/17/18: orch
            // finished encoding, plugin never polled, chip stuck on Phase A's 2 inline
            // variants. This OR-gate fixes it without touching head-poll's own semantics.
            $needs_v2_poll_js = (
                (function_exists('wpc_v2_head_poll_enabled') && wpc_v2_head_poll_enabled())
                || (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled())
            );
            if ($needs_v2_poll_js) {
                wp_enqueue_script(
                    $this::$slug . '-v2-head-poll',
                    WPS_IC_URI . 'addons/v2/v2-head-poll.js',
                    [$this::$slug . '-admin-bar-js'],
                    $this::$version,
                    true
                );
            }
        }

        // Rendered-width measurement beacon (telemetry; DEFAULT OFF). Enqueued for ALL
        // visitors (unlike the admin-only head-poll above) so it can sample real rendered widths
        // across the audience. Standalone addon script — NOT in the optimizer bundle — with its own
        // localized config (no admin-bar-js dependency). Inert unless wpc_v2_rendered_width_beacon_enabled().
        if (function_exists('wpc_v2_rendered_width_beacon_enabled') && wpc_v2_rendered_width_beacon_enabled()) {
            wp_enqueue_script(
                $this::$slug . '-v2-rw-beacon',
                WPS_IC_URI . 'addons/v2/v2-rendered-width-beacon.js',
                [],
                $this::$version,
                true
            );
            wp_localize_script($this::$slug . '-v2-rw-beacon', 'wpcRWBeacon', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('wpc_rw_beacon'),
                'sample'  => (float) apply_filters('wpc_v2_rw_beacon_sample_rate', 0.1),
            ]);
        }

        $optimizeRemove = get_option('wps_optimizejs_remove');
        // The orch cdn_disabled master kill: compute the flag here; AFTER the optimizer-JS
        // enqueue/localize block below we DEQUEUE the -aio handle entirely. Both branches localize
        // zoneName straight from ic_cdn_zone_name (the one CDN-URL leak the static zone-suppression
        // can't reach) and the script would build CDN srcsets client-side. Dequeuing drops the
        // script AND its localized var from the output — covering every branch. Inert by default.
        $wpc_cdn_off = (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed());
        if (empty($optimizeRemove)) {
            if ((!empty($options['lazy']) && $options['lazy'] == '1')) {

                $cf = get_option(WPS_IC_CF);
                $cfLive = true;
                if ($cf && isset($cf['settings'])) {
                    $cfLive = ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0');
                }
                $allowLive = get_option('wps_ic_allow_live') && $cfLive;

                if ((self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) || !$allowLive) {

                    if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                        wp_register_script($this::$slug . '-aio', '');
                        wp_enqueue_script($this::$slug . '-aio');
                        $scriptContent = file_get_contents(WPS_IC_DIR . 'assets/js/dist/optimizer.local-lazy' . $retinaJS . WPS_IC_MIN . '.js');
                        wp_add_inline_script($this::$slug . '-aio', $scriptContent);
                    } else {
                        wp_enqueue_script($this::$slug . '-aio', WPS_IC_URI . 'assets/js/dist/optimizer.local-lazy' . $retinaJS . WPS_IC_MIN . '.js', [], $this::$version);
                    }

                } else {

                    if ((!empty($options['generate_adaptive']) && $options['generate_adaptive'] == '1')) {
                        if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                            wp_register_script($this::$slug . '-aio', '');
                            wp_enqueue_script($this::$slug . '-aio');
                            $scriptContent = file_get_contents(WPS_IC_DIR . 'assets/js/dist/optimizer.adaptive' . $retinaJS . WPS_IC_MIN . '.js');
                            wp_add_inline_script($this::$slug . '-aio', $scriptContent);
                        } else {
                            wp_enqueue_script($this::$slug . '-aio', WPS_IC_URI . 'assets/js/dist/optimizer.adaptive' . $retinaJS . WPS_IC_MIN . '.js', [], $this::$version);
                        }
                    } else {
                        if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                            wp_register_script($this::$slug . '-aio', '');
                            wp_enqueue_script($this::$slug . '-aio');
                            $scriptContent = file_get_contents(WPS_IC_DIR . 'assets/js/dist/optimizer' . $retinaJS . WPS_IC_MIN . '.js');
                            wp_add_inline_script($this::$slug . '-aio', $scriptContent);
                        } else {
                            wp_enqueue_script($this::$slug . '-aio', WPS_IC_URI . 'assets/js/dist/optimizer' . $retinaJS . WPS_IC_MIN . '.js', [], $this::$version);
                        }
                    }
                }

                if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
                    if (!empty($_GET['webp']) && $_GET['webp'] == 'true') {
                        $webp = 'true';
                    } else {
                        $webp = 'false';
                    }

                    if (!empty($_GET['retina']) && $_GET['retina'] == 'true') {
                        $retina = 'true';
                    } else {
                        $retina = 'false';
                    }
                }

                // Force retina
                $force_retina = '0';
                if (!empty($_GET['force_retina'])) {
                    $retina = 'true';
                    $force_retina = 'true';
                }


                wp_localize_script($this::$slug . '-aio', 'ngf298gh738qwbdh0s87v_vars', ['zoneName' => get_option('ic_cdn_zone_name'), 'siteurl' => site_url(), 'api_url' => 'https://' . self::$zone_name . '/', 'quality' => self::$quality, 'ajaxurl' => admin_url('admin-ajax.php'), 'spinner' => WPS_IC_URI . 'assets/images/spinner.svg', 'background_sizing' => $background_sizing, 'lazy_enabled' => $lazy, 'webp_enabled' => $webp, 'retina_enabled' => $retina, 'force_retina' => $force_retina, 'exif_enabled' => $exif, 'adaptive_enabled' => $adaptive, 'js_debug' => self::$js_debug, 'slider_compatibility' => self::$slider_compatibility, 'triggerDomEvent' => self::$settings['disable-trigger-dom-event']]);
            } else {

                if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {

                    if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                        wp_register_script($this::$slug . '-aio', '');
                        wp_enqueue_script($this::$slug . '-aio');
                        $scriptContent = file_get_contents(WPS_IC_DIR . 'assets/js/dist/optimizer.local' . $retinaJS . WPS_IC_MIN . '.js');
                        wp_add_inline_script($this::$slug . '-aio', $scriptContent);
                    } else {
                        // Live CDN Disabled
                        wp_enqueue_script($this::$slug . '-aio', WPS_IC_URI . 'assets/js/dist/optimizer.local' . $retinaJS . WPS_IC_MIN . '.js', [], $this::$version);
                    }

                } else {

                    if ((!empty($options['generate_adaptive']) && $options['generate_adaptive'] == '1')) {
                        if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                            wp_register_script($this::$slug . '-aio', '');
                            wp_enqueue_script($this::$slug . '-aio');
                            $scriptContent = file_get_contents(WPS_IC_DIR . 'assets/js/dist/optimizer.adaptive' . $retinaJS . WPS_IC_MIN . '.js');
                            wp_add_inline_script($this::$slug . '-aio', $scriptContent);
                        } else {
                            // Live CDN Enabled
                            wp_enqueue_script($this::$slug . '-aio', WPS_IC_URI . 'assets/js/dist/optimizer.adaptive' . $retinaJS . WPS_IC_MIN . '.js', [], $this::$version);
                        }
                    } else {
                        if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                            wp_register_script($this::$slug . '-aio', '');
                            wp_enqueue_script($this::$slug . '-aio');
                            $scriptContent = file_get_contents(WPS_IC_DIR . 'assets/js/dist/optimizer' . $retinaJS . WPS_IC_MIN . '.js');
                            wp_add_inline_script($this::$slug . '-aio', $scriptContent);
                        } else {
                            wp_enqueue_script($this::$slug . '-aio', WPS_IC_URI . 'assets/js/dist/optimizer' . $retinaJS . WPS_IC_MIN . '.js', [], $this::$version);
                        }
                    }
                }

                // Force retina
                $force_retina = 'false';
                if (!empty($_GET['force_retina'])) {
                    $retina = 'true';
                    $force_retina = 'true';
                }

                wp_localize_script($this::$slug . '-aio', 'ngf298gh738qwbdh0s87v_vars', ['zoneName' => get_option('ic_cdn_zone_name'), 'siteurl' => site_url(), 'ajaxurl' => admin_url('admin-ajax.php'), 'spinner' => WPS_IC_URI . 'assets/images/spinner.svg', 'lazy_enabled' => $lazy, 'background_sizing' => $background_sizing, 'webp_enabled' => $webp, 'retina_enabled' => $retina, 'force_retina' => $force_retina, 'exif_enabled' => $exif, 'adaptive_enabled' => $adaptive, 'js_debug' => self::$js_debug, 'slider_compatibility' => self::$slider_compatibility, 'triggerDomEvent' => self::$settings['disable-trigger-dom-event']]);
            }
        }

        // On orch cdn_disabled, drop the front-end optimizer JS (-aio) + its localized
        // zoneName var entirely, so no CDN URL is emitted or built client-side. Both enqueue
        // branches above use the same handle, so one dequeue covers every path.
        if ($wpc_cdn_off) {
            wp_dequeue_script($this::$slug . '-aio');
            wp_deregister_script($this::$slug . '-aio');
            // Denis's review caught that the vars global rode the -aio handle, so the
            // dequeue silently dropped it for EVERY consumer (bg-lazy, slider compat,
            // third-party readers). Re-home it on a dummy handle so the global always
            // exists — with the zone fields EMPTY here, because "no CDN URL emitted or
            // built client-side" is this branch's whole point.
            $this->wpc_inline_vars_shim(true);
        }

        // When the resolver has promoted this site to negotiated/CDN-edge delivery, the
        // buffer emits clean native <img data-wpc-nd> (real src+srcset, native loading).
        // The optimizer (-aio) JS has nothing to act on there (no data-src placeholders)
        // — at best dead weight + a leaked zoneName var, at worst (legacy-adaptive that just
        // promoted mid-request) it re-fetches images already loaded natively. Drop it so enqueue
        // and the negotiated rewriter agree on one delivery mechanism per request.
        // is_active_jpeg() (the Next-Gen-off clean .jpg path) is equally optimizer-free:
        // it emits final natural .jpg URLs the edge serves verbatim, so the adaptive/lazy -aio JS
        // (which would re-point src to /wp:N/ transform URLs) must be dropped there too.
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            wp_dequeue_script($this::$slug . '-aio');
            wp_deregister_script($this::$slug . '-aio');
            // Per Denis's review, keep the vars global alive for non-optimizer
            // consumers; zone fields allowed here (the CDN is live — nd just owns <img>
            // delivery), so bg-lazy/slider-compat readers keep full context.
            $this->wpc_inline_vars_shim(false);
        }

        // Integration for Javascript in Themes/Plugins
        add_action('wp_footer', [$this, 'enqueueIntegration'], 9999);
    }

    private function get_ajax_var_data()
    {
        $data = ['ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wps_ic_nonce_action'), 'deselectAll' => __('Deselect All', WPS_IC_TEXTDOMAIN), 'selectAll' => __('Select All', WPS_IC_TEXTDOMAIN), 'scanning' => __('Scanning…', WPS_IC_TEXTDOMAIN), 'statusQueued' => __('Queued', WPS_IC_TEXTDOMAIN), 'statusOptimizing' => __('Optimizing', WPS_IC_TEXTDOMAIN), 'statusRestoring' => __('Restoring', WPS_IC_TEXTDOMAIN), 'statusTakingLonger' => __('Taking longer than expected', WPS_IC_TEXTDOMAIN), 'statusTimedOut' => __('Timed out', WPS_IC_TEXTDOMAIN), 'statusRetry' => __('Retry', WPS_IC_TEXTDOMAIN), 'noFontsDetected' => __('No Google Fonts detected on this page.', WPS_IC_TEXTDOMAIN), 'scanFailed' => __('Scan failed. Please try again.', WPS_IC_TEXTDOMAIN), 'active' => __('Active', WPS_IC_TEXTDOMAIN), 'disabled' => __('Disabled', WPS_IC_TEXTDOMAIN), 'updating' => __('Updating...', WPS_IC_TEXTDOMAIN), 'errorLoadingResults' => __('Error loading test results.', WPS_IC_TEXTDOMAIN), 'preparingOptimization' => __('Preparing optimization...', WPS_IC_TEXTDOMAIN), 'statusScanning' => __('Scanning for optimization opportunities...', WPS_IC_TEXTDOMAIN), 'statusWarmingCache' => __('Warming up the Cache...', WPS_IC_TEXTDOMAIN), 'statusFinalizing' => __('Finalizing Performance Optimizations...', WPS_IC_TEXTDOMAIN), 'statusVerifyingImages' => __('Verifying Real-Time Image Optimization...', WPS_IC_TEXTDOMAIN), 'statusAdaptive' => __('Analyzing Adaptive Performance...', WPS_IC_TEXTDOMAIN), 'statusMinifyHtml' => __('Minifying HTML...', WPS_IC_TEXTDOMAIN), 'statusServerResponse' => __('Enhancing Server Response time...', WPS_IC_TEXTDOMAIN), 'statusOptimizeCss' => __('Optimizing CSS Files...', WPS_IC_TEXTDOMAIN), 'statusOptimizeJs' => __('Optimizing JavaScript Files...', WPS_IC_TEXTDOMAIN), 'saving' => __('Saving...', WPS_IC_TEXTDOMAIN), 'saved' => __('Saved', WPS_IC_TEXTDOMAIN), 'refreshRequired' => __('Refresh required', WPS_IC_TEXTDOMAIN), 'purging_html' => __('Purging HTML Cache...', WPS_IC_TEXTDOMAIN), 'purging_critical' => __('Purging Critical CSS...', WPS_IC_TEXTDOMAIN), 'purging_cdn' => __('Purging CDN Cache...', WPS_IC_TEXTDOMAIN),
            // Pull-architecture flag exposed to JS so the bulk variant-count
            // poller can extend its ceiling from 15s to 30s when pull is active
            // (manifest fetch + journal drain adds 1-10s wake-to-disk latency
            // under FPM contention).
            'pullEnabled' => function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled(),
        ];

        if ($this->isAgencyPortal()) {
            $data['apikey']     = self::$api_key;
            $data['mode_nonce'] = wp_create_nonce('wpc_save_mode');
        }

        return $data;
    }

    public function enqueueIntegration()
    {

        $theme = wp_get_theme(); // Get the current theme object

        // Check if the theme name or template matches BuddyBoss
        if ($theme->get('Name') === 'BuddyBoss Theme' || $theme->get('Template') === 'buddyboss-theme') {
            if (!empty(self::$settings['delay-js']) && self::$settings['delay-js'] == '1') { ?>
                <script type="wpc-delay-last-script">
                    setTimeout(function(){
                    BuddyBossTheme.init();
                    },1000);
                </script>
                <?php
            }
        }
    }

    public function enqueue_all_scripts()
    {
        $screen = get_current_screen();
        $page_array = ['upload', #'settings_page_' . $this::$slug,
                'toplevel_page_' . $this::$slug . '-mu-network', #'toplevel_page_' . $this::$slug,
                'media_page_' . $this::$slug . '_optimize', 'media_page_' . $this::$slug . '_restore', 'media_page_' . $this::$slug . '_restore', 'settings_page_' . $this::$slug, 'plugins'];

        $this->asset_style('menu-icon', 'css/menu.wp.css');
        wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js', ['jquery'], $this::$version, true);

        wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());

        if (in_array($screen->base, $page_array)) {
            $this->enqueue_v4();
            $this->enqueue_all();
        }
    }

    public function asset_style($name, $filename)
    {
        wp_enqueue_style($this::$slug . '-' . $name, WPS_IC_URI . 'assets/' . $filename, [], $this::$version);
    }

    public function enqueue_v4()
    {
        $this->asset_script('admin-settings-page-charts', 'js/admin/charts/chartsjs.min.js?ver=' . $this::$version);
        $this->asset_style('menu-icon', 'css/menu.wp.css?ver=' . $this::$version);
        wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js?ver=' . $this::$version, ['jquery'], $this::$version, true);
        wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());

        wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js?ver=' . $this::$version, ['jquery'], '1.0.0');

        // Icons
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        // Tooltipster
        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

        // Sweetalert
        $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
        $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

        $this->asset_script('admin-select-mode', 'js/admin/select-modes.min.js');
        wp_localize_script($this::$slug . '-admin-select-mode', 'wpc_ic_modes', ['showModes' => $this::$showModes]);

        $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js?ver=' . $this::$version);
        wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());

        #$this->bootstrap();
        $gui = get_option(WPS_IC_GUI);
        if (!empty($gui) && $gui == 'lite') {
            $this->lite();
            wp_enqueue_style($this::$slug . '-v4-style-css', WPS_IC_URI . 'assets/v4/css/style.css', [], $this::$version);
            wp_enqueue_script($this::$slug . '-scripts-v4-js', WPS_IC_URI . 'assets/v4/js/scripts.js', ['jquery'], $this::$version);
            $this->inject_brand_colors();
        } else {
            $this->lite();
            $this->v4();
        }
    }

    public function asset_script($name, $filename)
    {
        wp_enqueue_script($this::$slug . '-' . $name, WPS_IC_URI . 'assets/' . $filename, ['jquery'], $this::$version, true);
        if (strpos($name, 'admin-sweetalert') !== false) {
            wp_add_inline_script($this::$slug . '-' . $name, 'window.WPCSwal = window.Swal;');
        }
    }

    public function script($name, $filename, $footer = false)
    {
        wp_enqueue_script($this::$slug . '-' . $name, WPS_IC_URI . 'assets/js/' . $filename, ['jquery'], $this::$version, $footer);
    }

    public function lite()
    {
        wp_enqueue_script($this::$slug . '-lite-js', WPS_IC_URI . 'assets/v4/js/lite.js', ['jquery'], $this::$version, true);
        wp_localize_script($this::$slug . '-lite-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
    }

    private function inject_brand_colors()
    {
        if (!defined('WPC_BRAND_COLOR')) return;

        $color = sanitize_hex_color(WPC_BRAND_COLOR);
        if (empty($color)) return;

        // Derive tint/light/dark variants from the brand color
        $r = hexdec(substr($color, 1, 2));
        $g = hexdec(substr($color, 3, 2));
        $b = hexdec(substr($color, 5, 2));

        $css = ":root, body.wp-admin {
            --wpc-brand-primary: {$color};
            --wpc-brand-primary-light: rgba({$r},{$g},{$b},0.7);
            --wpc-brand-primary-dark: {$color};
            --wpc-brand-accent: {$color};
            --wpc-brand-accent-light: rgba({$r},{$g},{$b},0.3);
            --wpc-brand-tint: rgba({$r},{$g},{$b},0.08);
            --wpc-brand-shadow: rgba({$r},{$g},{$b},0.4);
        }";

        wp_add_inline_style($this::$slug . '-v4-style-css', $css);
    }


    public function agencyScripts()
    {
        // v4 UI assets always needed in agency mode (view-site renders the settings page)
        $ui = $GLOBALS['wpc_agency_ui'] ?? '';
        if ($ui !== '' && $ui !== 'new') {
            return;
        }

        wp_enqueue_style($this::$slug . '-tooltip-bundle-wcio', WPS_IC_URI . 'assets/tooltip/css/tooltipster.bundle.min.css', [], $this::$version);
        wp_enqueue_script($this::$slug . '-admin-tooltip-wcio', WPS_IC_URI . 'assets/tooltip/js/tooltipster.bundle.min.js', ['jquery'], $this::$version);

        // Sweetalert
        $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
        $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

        // Icons
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        wp_enqueue_style($this::$slug . '-v4-style-css', WPS_IC_URI . 'assets/v4/css/style.css', [], $this::$version);
        wp_enqueue_script($this::$slug . '-tabs-v4-js', WPS_IC_URI . 'assets/v4/js/tabs.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-popups-js', WPS_IC_URI . 'assets/v4/js/popups.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-tooltip-js', WPS_IC_URI . 'assets/v4/js/tooltip.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-scripts-v4-js', WPS_IC_URI . 'assets/v4/js/scripts.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-lite-js', WPS_IC_URI . 'assets/v4/js/lite.js', ['jquery'], $this::$version, true);

        wp_localize_script($this::$slug . '-scripts-v4-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        wp_localize_script($this::$slug . '-popups-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        wp_localize_script($this::$slug . '-tabs-v4-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        wp_localize_script($this::$slug . '-lite-js', 'wpc_ajaxVar', $this->get_ajax_var_data());

        if (!empty(self::$api_key)) {
            $this->asset_script('admin-settings-page-charts', 'js/admin/charts/chartsjs.min.js?ver=' . $this::$version);
            $this->asset_style('menu-icon', 'css/menu.wp.css?ver=' . $this::$version);
            wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js?ver=' . $this::$version, ['jquery'], $this::$version, true);
            wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());

            wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js?ver=' . $this::$version, ['jquery'], '1.0.0');

            // Tooltipster
            $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
            $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

            $this->asset_script('admin-select-mode', 'js/admin/select-modes.min.js');
            wp_localize_script($this::$slug . '-admin-select-mode', 'wpc_ic_modes', ['showModes' => $this::$showModes]);

            $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js?ver=' . $this::$version);
            wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());
        }
    }


    public function v4()
    {
        wp_enqueue_style($this::$slug . '-tooltip-bundle-wcio', WPS_IC_URI . 'assets/tooltip/css/tooltipster.bundle.min.css', [], $this::$version);
        wp_enqueue_script($this::$slug . '-admin-tooltip-wcio', WPS_IC_URI . 'assets/tooltip/js/tooltipster.bundle.min.js', ['jquery'], $this::$version);

        wp_enqueue_style($this::$slug . '-v4-style-css', WPS_IC_URI . 'assets/v4/css/style.css', [], $this::$version);
        wp_enqueue_script($this::$slug . '-tabs-v4-js', WPS_IC_URI . 'assets/v4/js/tabs.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-popups-js', WPS_IC_URI . 'assets/v4/js/popups.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-tooltip-js', WPS_IC_URI . 'assets/v4/js/tooltip.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-tooltip-js', WPS_IC_URI . 'assets/v4/js/tooltip.js', ['jquery'], $this::$version);
        wp_enqueue_script($this::$slug . '-scripts-v4-js', WPS_IC_URI . 'assets/v4/js/scripts.js', ['jquery'], $this::$version);

        wp_localize_script($this::$slug . '-scripts-v4-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        wp_localize_script($this::$slug . '-popups-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        wp_localize_script($this::$slug . '-tabs-v4-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        $this->inject_brand_colors();
    }

    public function enqueue_all()
    {
        $apikey = self::$api_key;
        $settings = self::$settings;

        $screen = get_current_screen();

        $this->asset_style('menu-icon', 'css/menu.wp.css');
        wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js', ['jquery'], $this::$version, true);
        wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());

        $page_array = ['upload', #'settings_page_' . $this::$slug,
                'toplevel_page_' . $this::$slug . '-mu-network', #'toplevel_page_' . $this::$slug,
                'media_page_' . $this::$slug . '_optimize', 'media_page_' . $this::$slug . '_restore', 'media_page_' . $this::$slug . '_restore', 'plugins'];

        if (is_admin() || $this->isAgencyPortal()) {
            if (in_array($screen->base, $page_array)) {
                $screen = get_current_screen();
                wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js', ['jquery'], '1.0.0');

                if ($screen->base == 'toplevel_page_' . $this::$slug . '-mu-network') {
                    $this->script('admin-mu-connect', 'mu.connect' . WPS_IC_MIN . '.js');

                    // CSS
                    $this->style('admin', 'admin.styles.css');
                    $this->style('admin-media-library', 'admin.media-library.css');
                    $this->style('admin-settings-page', 'settings_page.css');
                    $this->style('admin-checkboxes', 'checkbox.css');

                    // Icons
                    $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

                    // Tooltipster
                    $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
                    $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                    // Sweetalert
                    $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
                    $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

                    // Mu style
                    $this->style('admin-mu', 'multisite.style.css');

                    // Vars
                    wp_localize_script($this::$slug . '-admin-mu-connect', 'wpc_ajaxVar', $this->get_ajax_var_data());
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'settings_page_' . $this::$slug) {

                    // Select Modes
                    $this->script('admin-select-modes', 'admin/select-modes' . WPS_IC_MIN . '.js');

                    // Settings Area
                    $this->script('admin-settings', 'admin/settings.admin' . WPS_IC_MIN . '.js');
                    $this->script('admin-lottie-player', 'admin/lottie/lottie-player.min.js');
                    $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js');
                    wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());
                    wp_localize_script($this::$slug . '-admin-settings', 'wpc_ajaxVar', $this->get_ajax_var_data());

                    if (is_multisite()) {
                        $this->script('admin-mu-settings', 'admin/mu-settings.admin' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-admin-mu-settings', 'wpc_ajaxVar', $this->get_ajax_var_data());
                    }
                }

                if (!empty($apikey)) {
                    if ($screen->base == 'settings_page_' . $this::$slug && (!empty($_GET['view']) && $_GET['view'] == 'bulk')) {
                        // Shared bulk UI module. Must enqueue BEFORE both consumers
                        // so window.WPCBulk is defined when their poll callbacks fire.
                        $this->script('bulk-ui', 'admin/bulk-ui' . WPS_IC_MIN . '.js');
                        $this->script('media-library-bulk', 'admin/media-library-bulk' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library-bulk', 'ajaxVar', $this->get_ajax_var_data());
                        $this->script('check-bulk-running', 'admin/check-bulk-running' . WPS_IC_MIN . '.js');
                    }

                    // Media Library Area
                    if ($screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk') {
                        // Icons
                        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

                        // Tooltips
                        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.css');
                        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                        $this->script('media-library', 'admin/media-library-actions' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library', 'wpc_ajaxVar', $this->get_ajax_var_data());
                    }

                    if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk' || $screen->base == 'settings_page_' . $this::$slug) {
                        #$this->script('admin', 'admin' . WPS_IC_MIN . '.js');
                        #$this->script('popups', 'popups' . WPS_IC_MIN . '.js');
                    }
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
                    $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                    // Fontello
                    $this->asset_style('admin-fontello', 'icons/css/fontello.css');
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk' || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->style('admin', 'admin.styles.css');
                    $this->style('admin-media-library', 'admin.media-library.css');
                    $this->style('admin-settings-page', 'settings_page.css');
                    $this->style('admin-checkboxes', 'checkbox.css');
                    $this->asset_script('admin-settings-page-charts', 'js/admin/charts/chartsjs.min.js');

                    // Sweetalert
                    $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
                    $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');
                }

                // Print footer script
                wp_localize_script('wps_ic-admin', 'wps_ic', ['uri' => WPS_IC_URI]);
            }
        }
    }

    public function style($name, $filename)
    {
        wp_enqueue_style($this::$slug . '-' . $name, WPS_IC_URI . 'assets/css/' . $filename, [], $this::$version);
    }

    public function enqueue_v3()
    {
        $this->asset_style('menu-icon', 'css/menu.wp.css');
        wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js', ['jquery'], $this::$version, true);
        wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
        wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js', ['jquery'], '1.0.0');

        // Icons
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        // Tooltipster
        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

        // Sweetalert
        $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
        $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');


        $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js');
        wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());

        $this->bootstrap();
        $this->v3();
    }

    public function bootstrap()
    {
        wp_enqueue_script($this::$slug . '-bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js', ['jquery'], '1.0.0');
        wp_enqueue_style($this::$slug . '-bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css', []);
    }

    public function v3()
    {
        wp_enqueue_style($this::$slug . '-v2-css', WPS_IC_URI . 'assets/v2/css/custom.min.css', []);
        wp_enqueue_style($this::$slug . '-v2-style-css', WPS_IC_URI . 'assets/v3/css/style.css', []);
        wp_enqueue_script($this::$slug . '-scripts-v2-js', WPS_IC_URI . 'assets/v2/js/scripts.js', ['jquery'], '1.0.0');
        wp_enqueue_script($this::$slug . '-popups-js', WPS_IC_URI . 'assets/v2/js/popups.js', ['jquery'], '1.0.0');
        wp_enqueue_script($this::$slug . '-tooltip-js', WPS_IC_URI . 'assets/v2/js/tooltip.js', ['jquery'], '1.0.0');
        wp_localize_script($this::$slug . '-scripts-v2-js', 'wpsIcVars', ['nonce' => wp_create_nonce('wps_ic_nonce_action')]);
    }

    public function enqueue_v2()
    {
        $this->bootstrap();
        $this->v2();
        $this->asset_style('menu-icon', 'css/menu.wp.css');

        wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js', ['jquery'], '1.0.0');

        // Icons
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        // Tooltipster
        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

        // Sweetalert
        $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
        $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

        $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js');
        wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());
    }

    public function v2()
    {
        wp_enqueue_style($this::$slug . '-v2-css', WPS_IC_URI . 'assets/v2/css/custom.min.css', []);
        wp_enqueue_style($this::$slug . '-v2-style-css', WPS_IC_URI . 'assets/v2/css/style.css', []);
        wp_enqueue_script($this::$slug . '-scripts-v2-js', WPS_IC_URI . 'assets/v2/js/scripts.js', ['jquery'], '1.0.0');
        wp_enqueue_script($this::$slug . '-popups-js', WPS_IC_URI . 'assets/v2/js/popups.js', ['jquery'], '1.0.0');
    }

    public function enqueue_bulk()
    {
        $apikey = self::$api_key;
        $settings = self::$settings;

        $screen = get_current_screen();

        $page_array = ['settings_page_' . $this::$slug,];
        $page_array = apply_filters('add_whitelabel_page_to_enqueue', $page_array);

        if (is_admin() || $this->isAgencyPortal()) {
            if (in_array($screen->base, $page_array)) {
                $screen = get_current_screen();

                wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js', ['jquery'], '1.0.0');

                if ($screen->base == 'toplevel_page_' . $this::$slug || in_array($screen->base, $page_array)) {
                    // Settings Area
                    $this->script('admin-settings', 'admin/settings.admin' . WPS_IC_MIN . '.js');
                    $this->script('admin-lottie-player', 'admin/lottie/lottie-player.min.js');
                    $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js');
                    wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());

                    if (is_multisite()) {
                        $this->script('admin-mu-settings', 'admin/mu-settings.admin' . WPS_IC_MIN . '.js');
                    }
                }

                if (!empty($apikey)) {
                    if (in_array($screen->base, $page_array) && (!empty($_GET['view']) && $_GET['view'] == 'bulk')) {
                        // Shared bulk UI module. Must enqueue BEFORE both consumers
                        // so window.WPCBulk is defined when their poll callbacks fire.
                        $this->script('bulk-ui', 'admin/bulk-ui' . WPS_IC_MIN . '.js');
                        $this->script('media-library-bulk', 'admin/media-library-bulk' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library-bulk', 'ajaxVar', $this->get_ajax_var_data());
                        $this->script('check-bulk-running', 'admin/check-bulk-running' . WPS_IC_MIN . '.js');
                    }

                    // Media Library Area
                    if ($screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk') {
                        // Icons
                        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

                        // Tooltips
                        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.css');
                        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                        $this->script('media-library', 'admin/media-library-actions' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library', 'wpc_ajaxVar', $this->get_ajax_var_data());
                    }


                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
                    $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                    // Fontello
                    $this->asset_style('admin-fontello', 'icons/css/fontello.css');
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk' || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->style('admin', 'admin.styles.css');
                    $this->style('admin-media-library', 'admin.media-library.css');
                    $this->style('admin-settings-page', 'settings_page.css');
                    $this->style('admin-checkboxes', 'checkbox.css');
                    $this->asset_script('admin-settings-page-charts', 'js/admin/charts/chartsjs.min.js');

                    // Sweetalert
                    $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
                    $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');
                }

                // Print footer script
                wp_localize_script('wps_ic-admin', 'wps_ic', ['uri' => WPS_IC_URI]);
            }
        }
    }

    /**
     * The ngf…_vars global was localized onto the -aio handle, so the deliberate dequeues
     * above (one delivery mechanism per request, zero client-side CDN URL building on
     * cdn_disabled) silently dropped it for every OTHER consumer — background-image lazy,
     * slider compatibility, third-party readers. This shim re-homes the SAME global on a
     * dummy handle whenever -aio is dropped, so reads never explode and secondary features
     * keep their config. $strip_zone empties the zoneName/api_url fields on the cdn_disabled
     * path, where not leaking the zone is the entire point of the dequeue.
     */
    private function wpc_inline_vars_shim($strip_zone)
    {
        $handle = $this::$slug . '-vars-shim';
        if (wp_script_is($handle, 'enqueued')) return;
        wp_register_script($handle, '');
        wp_enqueue_script($handle);
        $s = is_array(self::$settings) ? self::$settings : [];
        $flag = function ($k) use ($s) { return (!empty($s[$k]) && $s[$k] == '1') ? 'true' : 'false'; };
        wp_localize_script($handle, 'ngf298gh738qwbdh0s87v_vars', [
            'zoneName'           => $strip_zone ? '' : (string) get_option('ic_cdn_zone_name'),
            'siteurl'            => site_url(),
            'api_url'            => $strip_zone ? '' : ('https://' . self::$zone_name . '/'),
            'quality'            => self::$quality,
            'ajaxurl'            => admin_url('admin-ajax.php'),
            'spinner'            => WPS_IC_URI . 'assets/images/spinner.svg',
            'background_sizing'  => $flag('background-sizing'),
            'lazy_enabled'       => $flag('lazy'),
            'webp_enabled'       => $flag('generate_webp'),
            'retina_enabled'     => $flag('retina'),
            'force_retina'       => '0',
            'exif_enabled'       => $flag('preserve-exif'),
            // adaptive must stay OFF in the shim: the optimizer that would act on it is the
            // very thing these paths dequeue (re-pointing src = the v7.01.19 regression).
            'adaptive_enabled'   => 'false',
            'js_debug'           => self::$js_debug,
            'slider_compatibility' => self::$slider_compatibility,
            'triggerDomEvent'    => isset($s['disable-trigger-dom-event']) ? $s['disable-trigger-dom-event'] : '0',
        ]);
    }

}