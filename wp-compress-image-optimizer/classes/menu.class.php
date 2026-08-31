<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/menu.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */






class wps_ic_menu extends wps_ic
{

    public static $slug;
    public static $connected;
    public static $options;
    public $templates;


    public function __construct()
    {

        self::$options = parent::$options;
        $this::$slug = parent::$slug;
        $option = get_option(WPS_IC_SETTINGS);
        if (is_admin()) {

            self::$connected = get_option(WPS_IC_OPTIONS);

            $this->templates = new wps_ic_templates();

            
            if (empty(self::$connected['api_key']) || empty(self::$connected['response_key'])) {
                $option['hide_compress'] = '0';
                update_option(WPS_IC_SETTINGS, $option);
            }

            if (!empty($option['hide_compress']) && $option['hide_compress'] == '1') {
                add_action('admin_print_scripts', [$this, 'hide_wpc_menu']);
                add_action('pre_current_active_plugins', [$this, 'hide_compress_plugin_list']);
            } else {
                add_action('admin_menu', [$this, 'menu_init']);
                if (is_multisite()) {
                    add_action('network_admin_menu', [$this, 'mu_menu_init']);
                    add_action('admin_bar_menu', [$this, 'addCustomMUMenuItem'], 100);
                }
            }

            add_action('plugin_action_links_wp-compress-image-optimizer/wp-compress.php', [$this, 'plugin_list_link']);
            add_action('admin_bar_menu', [$this, 'add_toolbar_items'], 100);
        } else {
            add_action('admin_bar_menu', [$this, 'add_toolbar_items'], 100);
        }
    }


    public static function hide_compress_plugin_list()
    {
        global $wp_list_table;
        $hidearr = ['wp-compress-image-optimizer/wp-compress.php'];
        $myplugins = $wp_list_table->items;
        foreach ($myplugins as $key => $val) {
            if (in_array($key, $hidearr)) {
                unset($wp_list_table->items[$key]);
            }
        }
    }


    public function add_toolbar_items($admin_bar)
    {
        $options = parent::$settings;
        if (isset($options['hide_compress']) && @$options['hide_compress'] == '1') {
            return;
        }

        if (!empty($options['status']['hide_in_admin_bar']) && $options['status']['hide_in_admin_bar'] == '1') {
            return;
        }

        if (current_user_can('manage_wpc_settings') || current_user_can('manage_wpc_purge')) {
            $title_html = '<div id="wpc-ic-icon-admin-menu" class="ab-item wpc-ic-logo svg"><span class="screen-reader-text"></span></div>';

            if (!empty($options['status']['show_admin_bar_title']) && $options['status']['show_admin_bar_title'] == '1') {
                $menu_title = __('WP Compress', WPS_IC_TEXTDOMAIN);
                global $submenu;
                if (isset($submenu['options-general.php'])) {
                    foreach ($submenu['options-general.php'] as $item) {
                        if ($item[2] === $this::$slug) {
                            $menu_title = $item[0];
                            break;
                        }
                    }
                }
                $title_html .= '<span class="wpc-admin-bar-title" style="margin-left:0;padding-right:6px;font-size:13px;font-weight:400;display:inline-flex;align-items:center;height:100%;">' . esc_html($menu_title) . '</span>';
            }

            $admin_bar->add_menu(['id' => 'wp-compress', 'title' => $title_html, 'href' => wpc_settings_page_url(), 'meta' => ['title' => __(''), 'html' => '<div class="wp-compress-admin-bar-icon"></div>'],]);
        }

        $wpc_page725 = $this->wpc_page_context_725($options);

        if (!is_admin() && current_user_can('manage_wpc_purge')) {
            

            if ($wpc_page725 !== null) {
                $this->wpc_add_page_items_725($admin_bar, $options, $wpc_page725);
            }
            $this->wpc_add_purge_menu_641($admin_bar, $options);

            $admin_bar->add_menu(['id' => 'wp-compress-view-as-visitor', 'parent' => 'wp-compress', 'title' => __('View as Visitor', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('View as Visitor', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-view-as-visitor'],]);

        } elseif (current_user_can('manage_wpc_settings') ||current_user_can('manage_wpc_purge')) {
            

            if ($wpc_page725 !== null) {
                $this->wpc_add_page_items_725($admin_bar, $options, $wpc_page725);
            }
            $this->wpc_add_purge_menu_641($admin_bar, $options);

        }

        if (current_user_can('manage_wpc_settings')) {
            $admin_bar->add_menu(['id' => 'wp-compress-settings', 'parent' => 'wp-compress', 'title' => __('Settings', WPS_IC_TEXTDOMAIN), 'href' => wpc_settings_page_url(), 'meta' => ['title' => __('Settings', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-settings'],]);
        }

    }

    
    
    
    
    private function wpc_page_context_725($options)
    {
        $wpc_url725 = '';
        if (!is_admin()) {
            if (!empty($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
                $wpc_url725 = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            }
        } else {
            global $pagenow;
            if ($pagenow === 'post.php' && !empty($_GET['post'])) {
                $wpc_post725 = get_post((int) $_GET['post']);
                if ($wpc_post725 && $wpc_post725->post_status === 'publish'
                    && is_post_type_viewable(get_post_type_object($wpc_post725->post_type))) {
                    $wpc_url725 = (string) get_permalink($wpc_post725);
                }
            }
        }
        if ($wpc_url725 === '') {
            return null;
        }
        if (!class_exists('wps_ic_url_key') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'traits/url_key.php';
        }
        if (!class_exists('wps_ic_url_key')) {
            return null;
        }
        $wpc_clean725 = wps_ic_url_key::sanitizeSameHostUrl($wpc_url725);
        if ($wpc_clean725 === '' || !wps_ic_url_key::isPageUrl($wpc_clean725)) {
            return null;
        }
        $wpc_key725 = ltrim((string) (new wps_ic_url_key())->setup($wpc_clean725), '/');
        if ($wpc_key725 === '') {
            return null;
        }
        $wpc_crit_on725 = !empty($options['critical']['css']) && $options['critical']['css'] == '1';
        $wpc_dir725 = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_key725 . '/' : '';
        $wpc_has725 = $wpc_dir725 !== ''
            && (@is_file($wpc_dir725 . 'critical_desktop.css') || @is_file($wpc_dir725 . 'critical_mobile.css'));
        $wpc_dsp725 = $wpc_dir725 !== '' ? (int) @filemtime($wpc_dir725 . 'dispatch_ts.txt') : 0;
        $wpc_lnd725 = $wpc_dir725 !== '' ? (int) @filemtime($wpc_dir725 . 'land_ts.txt') : 0;
        return [
            'url'      => $wpc_clean725,
            'key'      => $wpc_key725,
            'crit_on'  => $wpc_crit_on725,
            'has'      => $wpc_has725,
            'stale'    => $wpc_dir725 !== '' && @is_file($wpc_dir725 . 'stale.txt'),
            'inflight' => $wpc_dsp725 > 0 && $wpc_dsp725 > $wpc_lnd725 && (time() - $wpc_dsp725) < 180,
        ];
    }

    
    
    
    
    private function wpc_add_page_items_725($admin_bar, $options, $wpc_page725)
    {
        $wpc_u725 = esc_attr(esc_url($wpc_page725['url']));
        if ($wpc_page725['crit_on']) {
            if ($wpc_page725['inflight']) {
                $wpc_dot725 = 'busy';
                $wpc_txt725 = __('Optimizing this page…', WPS_IC_TEXTDOMAIN);
                $wpc_tip725 = __('A fresh optimization is being generated — it applies automatically when it lands.', WPS_IC_TEXTDOMAIN);
            } elseif ($wpc_page725['has'] && !$wpc_page725['stale']) {
                $wpc_dot725 = 'ok';
                $wpc_txt725 = __('This page is optimized', WPS_IC_TEXTDOMAIN);
                $wpc_tip725 = __('Served with optimized CSS and cached HTML.', WPS_IC_TEXTDOMAIN);
            } elseif ($wpc_page725['has']) {
                $wpc_dot725 = 'busy';
                $wpc_txt725 = __('Optimized — update on the way', WPS_IC_TEXTDOMAIN);
                $wpc_tip725 = __('The current version keeps serving until the refreshed one lands automatically.', WPS_IC_TEXTDOMAIN);
            } else {
                $wpc_dot725 = 'off';
                $wpc_txt725 = __('Not optimized yet', WPS_IC_TEXTDOMAIN);
                $wpc_tip725 = __('This page optimizes automatically on its next visits — or use Rebuild This Page.', WPS_IC_TEXTDOMAIN);
            }
            $admin_bar->add_menu(['id' => 'wp-compress-status', 'parent' => 'wp-compress',
                'title' => '<span class="wpc-bar-dot wpc-bar-dot-' . $wpc_dot725 . '"></span>' . esc_html($wpc_txt725),
                'href' => '#', 'meta' => ['title' => $wpc_tip725, 'target' => '_self', 'class' => 'wp-compress-bar-status'],]);
        }

        $admin_bar->add_menu(['id' => 'wp-compress-refresh-page', 'parent' => 'wp-compress',
            'title' => '<span class="wpc-bar-label" data-wpc-url="' . $wpc_u725 . '">' . esc_html__('Refresh This Page', WPS_IC_TEXTDOMAIN) . '</span><span class="wpc-bar-sub">' . esc_html__('Serve the newest version — instant, nothing re-optimizes', WPS_IC_TEXTDOMAIN) . '</span>',
            'href' => '#', 'meta' => ['title' => __('Drops this page\'s cached copy and prepares a fresh one. Use after an edit that isn\'t showing.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-refresh-page wpc-bar-2line'],]);

        if ($wpc_page725['crit_on']) {
            $wpc_rsub725 = $wpc_page725['inflight']
                ? __('Already rebuilding — the new version lands automatically', WPS_IC_TEXTDOMAIN)
                : __('Regenerate this page\'s optimized CSS — about a minute', WPS_IC_TEXTDOMAIN);
            $admin_bar->add_menu(['id' => 'wp-compress-rebuild-page', 'parent' => 'wp-compress',
                'title' => '<span class="wpc-bar-label" data-wpc-url="' . $wpc_u725 . '">' . esc_html__('Rebuild This Page', WPS_IC_TEXTDOMAIN) . '</span><span class="wpc-bar-sub">' . esc_html($wpc_rsub725) . '</span>',
                'href' => '#', 'meta' => ['title' => __('Only needed when this page looks wrong. The page switches to plain theme styling right away, and the optimized version returns automatically once rebuilt (about a minute).', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-rebuild-page wpc-bar-2line' . ($wpc_page725['inflight'] ? ' wpc-bar-inflight' : ''),]]);
        }
    }

    
    
    
    
    
    
    private function wpc_add_purge_menu_641($admin_bar, $options)
    {
        $wpc_crit641 = !empty($options['critical']['css']) && $options['critical']['css'] == '1';
        $wpc_cdn641 = false;
        foreach (array('css', 'js', ['serve', 'jpg'], ['serve', 'png'], ['serve', 'gif'], ['serve', 'svg']) as $wpc_cond641) {
            $wpc_opt641 = is_array($wpc_cond641)
                ? (isset($options[$wpc_cond641[0]][$wpc_cond641[1]]) ? $options[$wpc_cond641[0]][$wpc_cond641[1]] : '')
                : (isset($options[$wpc_cond641]) ? $options[$wpc_cond641] : '');
            if ($wpc_opt641 == '1') {
                $wpc_cdn641 = true;
                break;
            }
        }

        $admin_bar->add_menu(['id' => 'wp-compress-advanced', 'parent' => 'wp-compress', 'title' => __('Advanced', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Site-wide controls. Rarely needed — updates, edits and plugin changes already refresh things automatically.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-advanced'],]);

        $admin_bar->add_menu(['id' => 'wp-compress-purge-html-cache', 'parent' => 'wp-compress-advanced', 'title' => __('Purge & Preload All Pages', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Drop every cached page and re-warm them. Critical CSS and the image CDN are not touched.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-purge-html-cache'],]);

        
        
        
        if ($wpc_crit641) {
            $admin_bar->add_menu(['id' => 'wp-compress-pull-latest', 'parent' => 'wp-compress-advanced', 'title' => __('Pull Latest Optimizations', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Re-fetch the newest cloud artifacts (critical, fonts, used-CSS) without purging. Automation does this on its own — use it to skip the wait.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-pull-latest'],]);
            $admin_bar->add_menu(['id' => 'wp-compress-rebuild', 'parent' => 'wp-compress-advanced', 'title' => __('Rebuild All Optimizations', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Fetch fresh optimizations for the whole site and drop stale cached pages. Images and the CDN are not touched.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-rebuild'],]);
            $admin_bar->add_menu(['id' => 'wp-compress-purge-critical-css', 'parent' => 'wp-compress-advanced', 'title' => __('Purge Critical CSS', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Mark every page\'s Critical CSS for regeneration. The current version keeps serving until each fresh one lands — pages never render unstyled.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-purge-critical-css'],]);
            $admin_bar->add_menu(['id' => 'wp-compress-remove-critical-css', 'parent' => 'wp-compress-advanced', 'title' => __('Remove Critical CSS', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Remove Critical CSS from every page now. Pages render with full theme CSS (correct but slower) until fresh versions land automatically.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-remove-critical-css'],]);
        }
        if ($wpc_cdn641) {
            $admin_bar->add_menu(['id' => 'wp-compress-clear-cache', 'parent' => 'wp-compress-advanced', 'title' => __('Purge CDN Images', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Rarely needed. Re-fetches every optimized image from origin — only use this if IMAGES are wrong, not CSS or HTML', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-clear-cache'],]);
        }
    }


    public function plugin_list_link($links)
    {
        $options = get_option(WPS_IC_OPTIONS);

        if (!empty($options['api_key'])) {
            $links = array_merge(['<a href="' . wpc_settings_page_url() . '">' . __('Settings', WPS_IC_TEXTDOMAIN) . '</a>'], $links);
            $links['wps-ic-reconnect'] = '<a href="#" class="reconnect-wp-compress-image-optimizer">' . __('Reconnect', WPS_IC_TEXTDOMAIN) . '</a>';
        } else {
            $links = array_merge(['<a href="' . wpc_settings_page_url() . '">' . __('Get Started', WPS_IC_TEXTDOMAIN) . '</a>'], $links);
        }

        return $links;
    }


    public function hide_wpc_menu()
    {
        echo '<style type="text/css">';
        echo 'li.toplevel_page_wpcompress { display:none; }';
        echo 'li#wp-admin-bar-wp-compress { display:none; }';
        echo '</style>';
    }


    public function mu_menu_init()
    {
        add_menu_page('WP Compress', 'WP Compress', 'manage_wpc_settings', $this::$slug . '-mu', [$this, 'render_mu_admin_page']);
    }


    public function menu_init()
    {


        $wpc_menu_opts107 = get_option(WPS_IC_OPTIONS);
        if (!empty($wpc_menu_opts107['status']['top_level_menu']) && $wpc_menu_opts107['status']['top_level_menu'] == '1') {
            $wpc_menu_icon107 = 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="#a7aaad" d="M349.4 44.6c5.9-13.7 1.5-29.7-10.6-38.5s-28.6-8-39.9 1.8l-256 224c-10 8.8-13.6 22.9-8.9 35.3S50.7 288 64 288H175.5L98.6 467.4c-5.9 13.7-1.5 29.7 10.6 38.5s28.6 8 39.9-1.8l256-224c10-8.8 13.6-22.9 8.9-35.3s-16.6-20.7-30-20.7H272.5L349.4 44.6z"/></svg>'
            );


            $wpc_menu_name113 = get_option('wpc_wl_menu_name');
            if (!is_string($wpc_menu_name113) || $wpc_menu_name113 === '') {
                $wpc_menu_name113 = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', WPS_IC_TEXTDOMAIN);
            }
            $hook = add_menu_page($wpc_menu_name113, $wpc_menu_name113, 'manage_wpc_settings', $this::$slug, [$this, 'render_admin_page_v4'], $wpc_menu_icon107, 80);
            add_action('admin_init', [$this, 'top_menu_redirect_shim']);
        } else {
            $wpc_menu_name113 = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', WPS_IC_TEXTDOMAIN);
            $hook = add_submenu_page('options-general.php', $wpc_menu_name113, $wpc_menu_name113, 'manage_wpc_settings', $this::$slug, [$this, 'render_admin_page_v4']);

            
            $wpc_slug113 = $this::$slug;
            add_action('admin_menu', function () use ($wpc_slug113) {
                global $submenu;
                if (isset($submenu['options-general.php'])) {
                    foreach ($submenu['options-general.php'] as $wpc_it113) {
                        if (isset($wpc_it113[2]) && $wpc_it113[2] === $wpc_slug113 && !empty($wpc_it113[0])) {
                            $wpc_nm113 = wp_strip_all_tags($wpc_it113[0]);
                            if ($wpc_nm113 !== '' && get_option('wpc_wl_menu_name') !== $wpc_nm113) {
                                update_option('wpc_wl_menu_name', $wpc_nm113, false);
                            }
                            break;
                        }
                    }
                }
            }, 9999);
        }


        if ($hook) {
            add_action('load-' . $hook, function () {
                add_action('in_admin_header', [$this, 'suppress_foreign_notices'], 1);
            });
        }
    }


    public function top_menu_redirect_shim()
    {
        if (empty($GLOBALS['pagenow']) || $GLOBALS['pagenow'] !== 'options-general.php') {
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== $this::$slug) {
            return;
        }
        $wpc_args107 = [];
        foreach ((array) $_GET as $wpc_k107 => $wpc_v107) {
            if (is_scalar($wpc_v107)) {
                $wpc_args107[sanitize_key($wpc_k107)] = sanitize_text_field((string) $wpc_v107);
            }
        }
        wp_safe_redirect(add_query_arg($wpc_args107, admin_url('admin.php')));
        exit;
    }


    public function suppress_foreign_notices()
    {
        global $wp_filter;
        foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'] as $tag) {
            if (empty($wp_filter[$tag]) || empty($wp_filter[$tag]->callbacks)) {
                continue;
            }
            foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                foreach ($cbs as $key => $cb) {
                    $fn = isset($cb['function']) ? $cb['function'] : null;
                    $file = '';
                    try {
                        if (is_array($fn) && count($fn) === 2) {
                            $ref = new \ReflectionMethod(is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0], (string) $fn[1]);
                            $file = (string) $ref->getFileName();
                        } elseif ($fn instanceof \Closure) {
                            $ref = new \ReflectionFunction($fn);
                            $file = (string) $ref->getFileName();
                        } elseif (is_string($fn)) {
                            if (strpos($fn, '::') !== false) {
                                $ref = new \ReflectionMethod($fn);
                            } elseif (function_exists($fn)) {
                                $ref = new \ReflectionFunction($fn);
                            } else {
                                continue;
                            }
                            $file = (string) $ref->getFileName();
                        } else {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if ($file === '' || strpos($file, WPS_IC_DIR) !== 0) {
                        unset($wp_filter[$tag]->callbacks[$prio][$key]);
                    }
                }
            }
        }
    }


    
    public function addCustomMUMenuItem($wp_admin_bar)
    {
        
        if (!is_user_logged_in() || !is_multisite() || !current_user_can('manage_network')) {
            return;
        }


        
        $wp_admin_bar->add_menu(array(
            'parent' => 'network-admin',
            'id' => 'network-admin-child',
            'title' => 'WP Compress - Network',
            'href' => network_admin_url('admin.php?page=' . $this::$slug . '-mu'),
        ));
    }

    public function render_mu_admin_page()
    {
        global $wps_ic;
        $connected_to_api = false;
        $settings = get_option(WPS_IC_MU_SETTINGS);

        if (!empty($settings['token'])) {
            $connected_to_api = true;
        }

        if (!$connected_to_api) {
            $this->templates->get_admin_page('mu-getting-started');
        } else {
            $this->templates->get_admin_page('multisite-setup');
        }
    }


    public function render_admin_page_v4()
    {
        global $wps_ic;

        


        if (!empty($_GET['reset_debug_log']) && isset($wps_ic->log) && is_object($wps_ic->log) && method_exists($wps_ic->log, 'reset')) {
            $wps_ic->log->reset();
        }

        


        if (!empty($_GET['view_debug_log']) && isset($wps_ic->log) && is_object($wps_ic->log) && method_exists($wps_ic->log, 'view')) {
            $wps_ic->log->view();
            die();
        }

        $apikey = '';
        if (!empty(self::$options['api_key'])) {
            $apikey = self::$options['api_key'];
        }

        if (empty($apikey) || !$apikey) {

            if (!empty($_GET['showAdvanced'])) {
                $this->templates->get_admin_page('advanced_settings_v4');
            } else {
                
                if(get_option('wps_ic_url_changed')){
                    $this->templates->get_admin_page('connect/lite-url-changed');
                } else {
                    $this->templates->get_admin_page('connect/lite-api-connect');
                }

                $this->templates->get_admin_page('lite_settings');
            }

        } else {


            if (!empty($_GET['view'])) {
                switch ($_GET['view']) {
                    case 'preload':
                        $this->templates->get_admin_page('preload');
                        break;
                    case 'bulk':
                        $this->templates->get_admin_page('bulk');
                        break;
                    default:
                        $this->templates->get_admin_page('advanced_settings_v4');
                        break;
                }
            } else {
                $gui = get_option(WPS_IC_GUI);

                if (!empty($_GET['showAdvanced'])) {
                    update_option(WPS_IC_GUI, 'pro');
                }

                if (empty($gui) || (!empty($gui) && $gui == 'lite')) {
                    $this->templates->get_admin_page('lite_settings');
                } else {
                    $this->templates->get_admin_page('advanced_settings_v4');
                }
            }

        }
    }


}