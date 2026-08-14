<?php





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

        
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);
        
        
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok())) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname)) {
            self::$zone_name = $custom_cname;
        }

        if (!empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['tatsu']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['PageSpeed']) || !empty($_GET['et_fb']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['tve']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['ct_builder']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php" || strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false)) {
            
        } else {

            if ($this->isAgencyPortal()) {

                
                
                
                
                if ($this->extractApiKey() || preg_match('#^/bulk-settings/?#', $_SERVER['REQUEST_URI'] ?? '')) {
                    add_action('wp_enqueue_scripts', [$this, 'agencyScripts']);
                }

                if (!function_exists('get_current_screen')) {
                    require_once ABSPATH . 'wp-admin/includes/screen.php';
                }

                return;
            }

            add_action('wp_enqueue_scripts', [$this, 'enqueueFrontend'], 1);


            add_action('wp_enqueue_scripts', [$this, 'gfNonceRefresh'], 20);

            if (is_admin()) {

                if (!empty($_GET['page']) && ($_GET['page'] == 'wpcompress-mu')) {
                    
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
                    




                    if (!is_user_logged_in() && !$this->isAgencyPortal()) {
                        if (empty($_GET['disableDelay2'])) {
                            if ((!empty(self::$settings['delay-js']) && self::$settings['delay-js'] == '1' && !self::$delay_js_override && !self::$preloaderAPI) || (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '1')) {
                                
                            } elseif (!empty(self::$settings['defer-js']) && self::$settings['defer-js'] == '1' && !self::$preloaderAPI) {
                                add_filter('script_loader_tag', [$this, 'deferJS'], 10, 3);
                            }
                        }
                    }
                }
            }
        }

        
        if ($this->isPluginActive('woocommerce/woocommerce.php') && !empty(self::$settings['disable-cart-fragments']) && self::$settings['disable-cart-fragments'] == 1) {
            add_action('wp_enqueue_scripts', [$this, 'disableCartFragments'], 999);
        }

    }


    public function gfNonceRefresh()
    {
        if (!apply_filters('wpc_gf_nonce_refresh', true)) {
            return;
        }
        
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
        if (!empty(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1'
            || (class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings))) {
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
            
            if (strpos($src, '.css')) {
                if (strpos($src, '?ver=')) {
                    $src = remove_query_arg('ver', $src);
                }
            }
        }

        if (!empty(self::$settings['js']) && self::$settings['js'] == '1') {
            
            if (strpos($src, '.js')) {
                $verPosition = strpos($src, '?ver=');
                if ($verPosition !== false) {

                    $src = substr($src, 0, $verPosition);
                }
            }
        }

        return $src;
    }


    public function inlineFrontend()
    {

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


        $wpc_v3_engine = (!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0')
            && (isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1'
                || (class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)));
        if (empty($optimizeRemove) && !$wpc_v3_engine) {
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
        }

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
            
            wp_enqueue_style($this::$slug . '-admin-bar', WPS_IC_URI . 'assets/css/admin-bar.css', [], $this::$version);
            wp_enqueue_script($this::$slug . '-admin-bar-js', WPS_IC_URI . 'assets/js/admin/admin-bar' . WPS_IC_MIN . '.js', ['jquery'], $this::$version, true);
            wp_localize_script($this::$slug . '-admin-bar-js', 'wpc_ajaxVar', $this->get_ajax_var_data());
            
            
        }


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

                
                $force_retina = 'false';
                if (!empty($_GET['force_retina'])) {
                    $retina = 'true';
                    $force_retina = 'true';
                }

                wp_localize_script($this::$slug . '-aio', 'ngf298gh738qwbdh0s87v_vars', ['zoneName' => get_option('ic_cdn_zone_name'), 'siteurl' => site_url(), 'ajaxurl' => admin_url('admin-ajax.php'), 'spinner' => WPS_IC_URI . 'assets/images/spinner.svg', 'lazy_enabled' => $lazy, 'background_sizing' => $background_sizing, 'webp_enabled' => $webp, 'retina_enabled' => $retina, 'force_retina' => $force_retina, 'exif_enabled' => $exif, 'adaptive_enabled' => $adaptive, 'js_debug' => self::$js_debug, 'slider_compatibility' => self::$slider_compatibility, 'triggerDomEvent' => self::$settings['disable-trigger-dom-event']]);
            }
        }


        if ($wpc_cdn_off) {
            wp_dequeue_script($this::$slug . '-aio');
            wp_deregister_script($this::$slug . '-aio');


            $this->wpc_inline_vars_shim(true);
        }


        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            wp_dequeue_script($this::$slug . '-aio');
            wp_deregister_script($this::$slug . '-aio');


            $this->wpc_inline_vars_shim(false);
        }

        
        add_action('wp_footer', [$this, 'enqueueIntegration'], 9999);
    }

    private function get_ajax_var_data()
    {
        $data = ['ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wps_ic_nonce_action'), 'deselectAll' => __('Deselect All', WPS_IC_TEXTDOMAIN), 'selectAll' => __('Select All', WPS_IC_TEXTDOMAIN), 'scanning' => __('Scanning…', WPS_IC_TEXTDOMAIN), 'statusQueued' => __('Queued', WPS_IC_TEXTDOMAIN), 'statusOptimizing' => __('Optimizing', WPS_IC_TEXTDOMAIN), 'statusRestoring' => __('Restoring', WPS_IC_TEXTDOMAIN), 'statusTakingLonger' => __('Taking longer than expected', WPS_IC_TEXTDOMAIN), 'statusTimedOut' => __('Timed out', WPS_IC_TEXTDOMAIN), 'statusRetry' => __('Retry', WPS_IC_TEXTDOMAIN), 'noFontsDetected' => __('No Google Fonts detected on this page.', WPS_IC_TEXTDOMAIN), 'scanFailed' => __('Scan failed. Please try again.', WPS_IC_TEXTDOMAIN), 'active' => __('Active', WPS_IC_TEXTDOMAIN), 'disabled' => __('Disabled', WPS_IC_TEXTDOMAIN), 'updating' => __('Updating...', WPS_IC_TEXTDOMAIN), 'errorLoadingResults' => __('Error loading test results.', WPS_IC_TEXTDOMAIN), 'preparingOptimization' => __('Preparing optimization...', WPS_IC_TEXTDOMAIN), 'statusScanning' => __('Scanning for optimization opportunities...', WPS_IC_TEXTDOMAIN), 'statusWarmingCache' => __('Warming up the Cache...', WPS_IC_TEXTDOMAIN), 'statusFinalizing' => __('Finalizing Performance Optimizations...', WPS_IC_TEXTDOMAIN), 'statusVerifyingImages' => __('Verifying Real-Time Image Optimization...', WPS_IC_TEXTDOMAIN), 'statusAdaptive' => __('Analyzing Adaptive Performance...', WPS_IC_TEXTDOMAIN), 'statusMinifyHtml' => __('Minifying HTML...', WPS_IC_TEXTDOMAIN), 'statusServerResponse' => __('Enhancing Server Response time...', WPS_IC_TEXTDOMAIN), 'statusOptimizeCss' => __('Optimizing CSS Files...', WPS_IC_TEXTDOMAIN), 'statusOptimizeJs' => __('Optimizing JavaScript Files...', WPS_IC_TEXTDOMAIN), 'saving' => __('Saving...', WPS_IC_TEXTDOMAIN), 'saved' => __('Saved', WPS_IC_TEXTDOMAIN), 'refreshRequired' => __('Refresh required', WPS_IC_TEXTDOMAIN), 'purging_html' => __('Purging HTML Cache...', WPS_IC_TEXTDOMAIN), 'purging_critical' => __('Purging Critical CSS...', WPS_IC_TEXTDOMAIN), 'purging_cdn' => __('Purging CDN Cache...', WPS_IC_TEXTDOMAIN),


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

        $theme = wp_get_theme(); 

        
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

        
        $page_array = ['upload', 'toplevel_page_' . $this::$slug,
                'toplevel_page_' . $this::$slug . '-mu-network',
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

        
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        
        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

        
        $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
        $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

        $this->asset_script('admin-select-mode', 'js/admin/select-modes.min.js');
        wp_localize_script($this::$slug . '-admin-select-mode', 'wpc_ic_modes', ['showModes' => $this::$showModes]);

        $this->script('admin-settings-live', 'admin/live-settings.admin' . WPS_IC_MIN . '.js?ver=' . $this::$version);
        wp_localize_script($this::$slug . '-admin-settings-live', 'wpc_ajaxVar', $this->get_ajax_var_data());

        
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
        
        $ui = $GLOBALS['wpc_agency_ui'] ?? '';
        if ($ui !== '' && $ui !== 'new') {
            return;
        }

        wp_enqueue_style($this::$slug . '-tooltip-bundle-wcio', WPS_IC_URI . 'assets/tooltip/css/tooltipster.bundle.min.css', [], $this::$version);
        wp_enqueue_script($this::$slug . '-admin-tooltip-wcio', WPS_IC_URI . 'assets/tooltip/js/tooltipster.bundle.min.js', ['jquery'], $this::$version);

        
        $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
        $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

        
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

        $page_array = ['upload', 'toplevel_page_' . $this::$slug,
                'toplevel_page_' . $this::$slug . '-mu-network',
                'media_page_' . $this::$slug . '_optimize', 'media_page_' . $this::$slug . '_restore', 'media_page_' . $this::$slug . '_restore', 'plugins'];

        if (is_admin() || $this->isAgencyPortal()) {
            if (in_array($screen->base, $page_array)) {
                $screen = get_current_screen();
                wp_enqueue_script($this::$slug . '-circle', WPS_IC_URI . 'assets/js/circle-progress/circle-progress.min.js', ['jquery'], '1.0.0');

                if ($screen->base == 'toplevel_page_' . $this::$slug . '-mu-network') {
                    $this->script('admin-mu-connect', 'mu.connect' . WPS_IC_MIN . '.js');

                    
                    $this->style('admin', 'admin.styles.css');
                    $this->style('admin-media-library', 'admin.media-library.css');
                    $this->style('admin-settings-page', 'settings_page.css');
                    $this->style('admin-checkboxes', 'checkbox.css');

                    
                    $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

                    
                    $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
                    $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                    
                    $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
                    $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');

                    
                    $this->style('admin-mu', 'multisite.style.css');

                    
                    wp_localize_script($this::$slug . '-admin-mu-connect', 'wpc_ajaxVar', $this->get_ajax_var_data());
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'settings_page_' . $this::$slug) {

                    
                    $this->script('admin-select-modes', 'admin/select-modes' . WPS_IC_MIN . '.js');

                    
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
                    if (($screen->base == 'settings_page_' . $this::$slug || $screen->base == 'toplevel_page_' . $this::$slug) && (!empty($_GET['view']) && $_GET['view'] == 'bulk')) {
                        
                        
                        $this->script('bulk-ui', 'admin/bulk-ui' . WPS_IC_MIN . '.js');
                        $this->script('media-library-bulk', 'admin/media-library-bulk' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library-bulk', 'ajaxVar', $this->get_ajax_var_data());
                        $this->script('check-bulk-running', 'admin/check-bulk-running' . WPS_IC_MIN . '.js');

                        
                        if ((function_exists('wpc_v2_head_poll_enabled') && wpc_v2_head_poll_enabled())
                            || (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled())) {
                            wp_enqueue_script($this::$slug . '-v2-head-poll', WPS_IC_URI . 'addons/v2/v2-head-poll.js', ['jquery'], $this::$version, true);
                            wp_localize_script($this::$slug . '-v2-head-poll', 'wpc_ajaxVar', $this->get_ajax_var_data());
                        }
                    }

                    
                    if ($screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk') {
                        
                        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

                        
                        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.css');
                        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                        $this->script('media-library', 'admin/media-library-actions' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library', 'wpc_ajaxVar', $this->get_ajax_var_data());
                    }

                    if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk' || $screen->base == 'settings_page_' . $this::$slug) {
                        
                        
                    }
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
                    $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                    
                    $this->asset_style('admin-fontello', 'icons/css/fontello.css');
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk' || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->style('admin', 'admin.styles.css');
                    $this->style('admin-media-library', 'admin.media-library.css');
                    $this->style('admin-settings-page', 'settings_page.css');
                    $this->style('admin-checkboxes', 'checkbox.css');
                    $this->asset_script('admin-settings-page-charts', 'js/admin/charts/chartsjs.min.js');

                    
                    $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
                    $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');
                }

                
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

        
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        
        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

        
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

        
        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

        
        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

        
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
                        
                        
                        $this->script('bulk-ui', 'admin/bulk-ui' . WPS_IC_MIN . '.js');
                        $this->script('media-library-bulk', 'admin/media-library-bulk' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library-bulk', 'ajaxVar', $this->get_ajax_var_data());
                        $this->script('check-bulk-running', 'admin/check-bulk-running' . WPS_IC_MIN . '.js');

                        
                        if ((function_exists('wpc_v2_head_poll_enabled') && wpc_v2_head_poll_enabled())
                            || (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled())) {
                            wp_enqueue_script($this::$slug . '-v2-head-poll', WPS_IC_URI . 'addons/v2/v2-head-poll.js', ['jquery'], $this::$version, true);
                            wp_localize_script($this::$slug . '-v2-head-poll', 'wpc_ajaxVar', $this->get_ajax_var_data());
                        }
                    }

                    
                    if ($screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk') {
                        
                        $this->asset_style('admin-fontello', 'icons/css/fontello.min.css');

                        
                        $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.css');
                        $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                        $this->script('media-library', 'admin/media-library-actions' . WPS_IC_MIN . '.js');
                        wp_localize_script($this::$slug . '-media-library', 'wpc_ajaxVar', $this->get_ajax_var_data());
                    }


                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->asset_style('admin-tooltip-bundle-wcio', 'tooltip/css/tooltipster.bundle.min.css');
                    $this->asset_script('admin-tooltip', 'tooltip/js/tooltipster.bundle.min.js');

                    
                    $this->asset_style('admin-fontello', 'icons/css/fontello.css');
                }

                if ($screen->base == 'toplevel_page_' . $this::$slug || $screen->base == 'upload' || $screen->base == 'media_page_' . $this::$slug . '_optimize' || $screen->base == 'plugins' || $screen->base == 'media_page_' . $this::$slug . '_restore' || $screen->base == 'media_page_wp_hard_restore_bulk' || $screen->base == 'settings_page_' . $this::$slug) {
                    $this->style('admin', 'admin.styles.css');
                    $this->style('admin-media-library', 'admin.media-library.css');
                    $this->style('admin-settings-page', 'settings_page.css');
                    $this->style('admin-checkboxes', 'checkbox.css');
                    $this->asset_script('admin-settings-page-charts', 'js/admin/charts/chartsjs.min.js');

                    
                    $this->asset_style('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.min.css');
                    $this->asset_script('admin-sweetalert', 'js/admin/sweetalert/sweetalert2.all.min.js');
                }

                
                wp_localize_script('wps_ic-admin', 'wps_ic', ['uri' => WPS_IC_URI]);
            }
        }
    }


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


            'adaptive_enabled'   => 'false',
            'js_debug'           => self::$js_debug,
            'slider_compatibility' => self::$slider_compatibility,
            'triggerDomEvent'    => isset($s['disable-trigger-dom-event']) ? $s['disable-trigger-dom-event'] : '0',
        ]);
    }

}