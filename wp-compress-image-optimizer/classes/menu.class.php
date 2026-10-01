<?php


/**
 * Class - Menu
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

            // API Key is removed!
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

        $page_context = $this->wpc_current_page_context($options);

        if (!is_admin() && current_user_can('manage_wpc_purge')) {
            // Show options in frontend

            if ($page_context !== null) {
                $this->wpc_add_admin_bar_page_items($admin_bar, $options, $page_context);
            }
            $this->wpc_add_admin_bar_purge_menu($admin_bar, $options);

            $admin_bar->add_menu(['id' => 'wp-compress-view-as-visitor', 'parent' => 'wp-compress', 'title' => __('View as Visitor', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('View as Visitor', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-view-as-visitor'],]);

        } elseif (current_user_can('manage_wpc_settings') ||current_user_can('manage_wpc_purge')) {
            // Shows if user is logged in!

            if ($page_context !== null) {
                $this->wpc_add_admin_bar_page_items($admin_bar, $options, $page_context);
            }
            $this->wpc_add_admin_bar_purge_menu($admin_bar, $options);

        }

        if (current_user_can('manage_wpc_settings')) {
            $admin_bar->add_menu(['id' => 'wp-compress-settings', 'parent' => 'wp-compress', 'title' => __('Settings', WPS_IC_TEXTDOMAIN), 'href' => wpc_settings_page_url(), 'meta' => ['title' => __('Settings', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-settings'],]);
        }

    }

    // v7.10.725 — resolve "this page" for the admin bar. Front end: the URL being viewed.
    // wp-admin: ONLY the post editor (post.php) resolves, to the edited post's permalink —
    // every other admin screen has no page context, and a per-page button that silently
    // targets some other URL is worse than no button. Returns null when there is no page.
    private function wpc_current_page_context($options)
    {
        $page_url = '';
        if (!is_admin()) {
            if (!empty($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
                $page_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            }
        } else {
            global $pagenow;
            if ($pagenow === 'post.php' && !empty($_GET['post'])) {
                $edited_post = get_post((int) $_GET['post']);
                if ($edited_post && $edited_post->post_status === 'publish'
                    && is_post_type_viewable(get_post_type_object($edited_post->post_type))) {
                    $page_url = (string) get_permalink($edited_post);
                }
            }
        }
        if ($page_url === '') {
            return null;
        }
        if (!class_exists('wps_ic_url_key') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'traits/url_key.php';
        }
        if (!class_exists('wps_ic_url_key')) {
            return null;
        }
        $clean_url = wps_ic_url_key::sanitizeSameHostUrl($page_url);
        if ($clean_url === '' || !wps_ic_url_key::isPageUrl($clean_url)) {
            return null;
        }
        $url_key = ltrim((string) (new wps_ic_url_key())->setup($clean_url), '/');
        if ($url_key === '') {
            return null;
        }
        $crit_on = !empty($options['critical']['css']) && $options['critical']['css'] == '1';
        $crit_dir = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/' : '';
        $has_crit = $crit_dir !== ''
            && (@is_file($crit_dir . 'critical_desktop.css') || @is_file($crit_dir . 'critical_mobile.css'));
        $dispatched_at = $crit_dir !== '' ? (int) @filemtime($crit_dir . 'dispatch_ts.txt') : 0;
        $landed_at = $crit_dir !== '' ? (int) @filemtime($crit_dir . 'land_ts.txt') : 0;
        return [
            'url'      => $clean_url,
            'key'      => $url_key,
            'crit_on'  => $crit_on,
            'has'      => $has_crit,
            'stale'    => $crit_dir !== '' && @is_file($crit_dir . 'stale.txt'),
            'inflight' => $dispatched_at > 0 && $dispatched_at > $landed_at && (time() - $dispatched_at) < 180,
        ];
    }

    // v7.10.725 — the per-page group: one honest status line + the two per-page actions.
    // Refresh = the free lane (HTML layers only). Rebuild = the paid lane (one service
    // generation for THIS url; its HTML purge rides the land, never the click, so the
    // page keeps serving until the new version actually exists).
    private function wpc_add_admin_bar_page_items($admin_bar, $options, $page_context)
    {
        $page_url_attr = esc_attr(esc_url($page_context['url']));
        if ($page_context['crit_on']) {
            if ($page_context['inflight']) {
                $status_dot = 'busy';
                $status_text = __('Optimizing this page…', WPS_IC_TEXTDOMAIN);
                $status_tip = __('A fresh optimization is being generated — it applies automatically when it lands.', WPS_IC_TEXTDOMAIN);
            } elseif ($page_context['has'] && !$page_context['stale']) {
                $status_dot = 'ok';
                $status_text = __('This page is optimized', WPS_IC_TEXTDOMAIN);
                $status_tip = __('Served with optimized CSS and cached HTML.', WPS_IC_TEXTDOMAIN);
            } elseif ($page_context['has']) {
                $status_dot = 'busy';
                $status_text = __('Optimized — update on the way', WPS_IC_TEXTDOMAIN);
                $status_tip = __('The current version keeps serving until the refreshed one lands automatically.', WPS_IC_TEXTDOMAIN);
            } else {
                $status_dot = 'off';
                $status_text = __('Not optimized yet', WPS_IC_TEXTDOMAIN);
                $status_tip = __('This page optimizes automatically on its next visits — or use Rebuild This Page.', WPS_IC_TEXTDOMAIN);
            }
            $admin_bar->add_menu(['id' => 'wp-compress-status', 'parent' => 'wp-compress',
                'title' => '<span class="wpc-bar-dot wpc-bar-dot-' . $status_dot . '"></span>' . esc_html($status_text),
                'href' => '#', 'meta' => ['title' => $status_tip, 'target' => '_self', 'class' => 'wp-compress-bar-status'],]);
        }

        $admin_bar->add_menu(['id' => 'wp-compress-refresh-page', 'parent' => 'wp-compress',
            'title' => '<span class="wpc-bar-label" data-wpc-url="' . $page_url_attr . '">' . esc_html__('Refresh This Page', WPS_IC_TEXTDOMAIN) . '</span><span class="wpc-bar-sub">' . esc_html__('Serve the newest version — instant, nothing re-optimizes', WPS_IC_TEXTDOMAIN) . '</span>',
            'href' => '#', 'meta' => ['title' => __('Drops this page\'s cached copy and prepares a fresh one. Use after an edit that isn\'t showing.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-refresh-page wpc-bar-2line'],]);

        if ($page_context['crit_on']) {
            $rebuild_sub = $page_context['inflight']
                ? __('Already rebuilding — the new version lands automatically', WPS_IC_TEXTDOMAIN)
                : __('Regenerate this page\'s optimized CSS — about a minute', WPS_IC_TEXTDOMAIN);
            $admin_bar->add_menu(['id' => 'wp-compress-rebuild-page', 'parent' => 'wp-compress',
                'title' => '<span class="wpc-bar-label" data-wpc-url="' . $page_url_attr . '">' . esc_html__('Rebuild This Page', WPS_IC_TEXTDOMAIN) . '</span><span class="wpc-bar-sub">' . esc_html($rebuild_sub) . '</span>',
                'href' => '#', 'meta' => ['title' => __('Only needed when this page looks wrong. The page switches to plain theme styling right away, and the optimized version returns automatically once rebuilt (about a minute).', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-rebuild-page wpc-bar-2line' . ($page_context['inflight'] ? ' wpc-bar-inflight' : ''),]]);
        }
    }

    // v7.10.725 — site-wide controls ALL live under Advanced (kept builder name for suite
    // continuity). The primary surface is per-page (wpc_add_admin_bar_page_items): every
    // legitimate site-wide trigger is a hookable event we already automate, so site-wide
    // purging is the escape hatch, not the front door — cache hit rate and service gens
    // are the resources the menu shape protects. Handler ids/classes are unchanged, so
    // existing JS and support flows keep working.
    private function wpc_add_admin_bar_purge_menu($admin_bar, $options)
    {
        $crit_on = !empty($options['critical']['css']) && $options['critical']['css'] == '1';
        $cdn_on = false;
        foreach (array('css', 'js', ['serve', 'jpg'], ['serve', 'png'], ['serve', 'gif'], ['serve', 'svg']) as $setting_path) {
            $setting_value = is_array($setting_path)
                ? (isset($options[$setting_path[0]][$setting_path[1]]) ? $options[$setting_path[0]][$setting_path[1]] : '')
                : (isset($options[$setting_path]) ? $options[$setting_path] : '');
            if ($setting_value == '1') {
                $cdn_on = true;
                break;
            }
        }

        $admin_bar->add_menu(['id' => 'wp-compress-advanced', 'parent' => 'wp-compress', 'title' => __('Advanced', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Site-wide controls. Rarely needed — updates, edits and plugin changes already refresh things automatically.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-advanced'],]);

        $admin_bar->add_menu(['id' => 'wp-compress-purge-html-cache', 'parent' => 'wp-compress-advanced', 'title' => __('Purge & Preload All Pages', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Drop every cached page and re-warm them. Critical CSS and the image CDN are not touched.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-purge-html-cache'],]);

        // v7.10.516 — ONE primary intent. Rebuild does the whole correct sequence and is
        // situational: it purges only layers that are actually stale, and never the
        // image CDN (re-optimization + origin bandwidth is not collateral we can spend).
        if ($crit_on) {
            $admin_bar->add_menu(['id' => 'wp-compress-pull-latest', 'parent' => 'wp-compress-advanced', 'title' => __('Pull Latest Optimizations', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Re-fetch the newest cloud artifacts (critical, fonts, used-CSS) without purging. Automation does this on its own — use it to skip the wait.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-pull-latest'],]);
            $admin_bar->add_menu(['id' => 'wp-compress-rebuild', 'parent' => 'wp-compress-advanced', 'title' => __('Rebuild All Optimizations', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Fetch fresh optimizations for the whole site and drop stale cached pages. Images and the CDN are not touched.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-rebuild'],]);
            $admin_bar->add_menu(['id' => 'wp-compress-purge-critical-css', 'parent' => 'wp-compress-advanced', 'title' => __('Purge Critical CSS', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Mark every page\'s Critical CSS for regeneration. The current version keeps serving until each fresh one lands — pages never render unstyled.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-purge-critical-css'],]);
            $admin_bar->add_menu(['id' => 'wp-compress-remove-critical-css', 'parent' => 'wp-compress-advanced', 'title' => __('Remove Critical CSS', WPS_IC_TEXTDOMAIN), 'href' => '#', 'meta' => ['title' => __('Remove Critical CSS from every page now. Pages render with full theme CSS (correct but slower) until fresh versions land automatically.', WPS_IC_TEXTDOMAIN), 'target' => '_self', 'class' => 'wp-compress-bar-remove-critical-css'],]);
        }
        if ($cdn_on) {
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


        $plugin_options = get_option(WPS_IC_OPTIONS);
        if (!empty($plugin_options['status']['top_level_menu']) && $plugin_options['status']['top_level_menu'] == '1') {
            $menu_icon = 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="#a7aaad" d="M349.4 44.6c5.9-13.7 1.5-29.7-10.6-38.5s-28.6-8-39.9 1.8l-256 224c-10 8.8-13.6 22.9-8.9 35.3S50.7 288 64 288H175.5L98.6 467.4c-5.9 13.7-1.5 29.7 10.6 38.5s28.6 8 39.9-1.8l256-224c10-8.8 13.6-22.9 8.9-35.3s-16.6-20.7-30-20.7H272.5L349.4 44.6z"/></svg>'
            );


            $menu_name = get_option('wpc_wl_menu_name');
            if (!is_string($menu_name) || $menu_name === '') {
                $menu_name = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', WPS_IC_TEXTDOMAIN);
            }
            $hook = add_menu_page($menu_name, $menu_name, 'manage_wpc_settings', $this::$slug, [$this, 'render_admin_page_v4'], $menu_icon, 80);
            add_action('admin_init', [$this, 'top_menu_redirect_shim']);
        } else {
            $menu_name = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', WPS_IC_TEXTDOMAIN);
            $hook = add_submenu_page('options-general.php', $menu_name, $menu_name, 'manage_wpc_settings', $this::$slug, [$this, 'render_admin_page_v4']);

            // AFTER registration; persist the final label so top-level mode shows the same brand.
            $plugin_slug = $this::$slug;
            add_action('admin_menu', function () use ($plugin_slug) {
                global $submenu;
                if (isset($submenu['options-general.php'])) {
                    foreach ($submenu['options-general.php'] as $submenu_item) {
                        if (isset($submenu_item[2]) && $submenu_item[2] === $plugin_slug && !empty($submenu_item[0])) {
                            $item_label = wp_strip_all_tags($submenu_item[0]);
                            if ($item_label !== '' && get_option('wpc_wl_menu_name') !== $item_label) {
                                update_option('wpc_wl_menu_name', $item_label, false);
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
        $query_args = [];
        foreach ((array) $_GET as $arg_name => $arg_value) {
            if (is_scalar($arg_value)) {
                $query_args[sanitize_key($arg_name)] = sanitize_text_field((string) $arg_value);
            }
        }
        wp_safe_redirect(add_query_arg($query_args, admin_url('admin.php')));
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


    // Add custom menu items under 'My Sites -> Network Admin'
    public function addCustomMUMenuItem($wp_admin_bar)
    {
        // Check if the current user has the capability to manage the network
        if (!is_user_logged_in() || !is_multisite() || !current_user_can('manage_network')) {
            return;
        }


        // Add the custom menu item
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

        /**
         * Reset Debug Log
         */
        if (!empty($_GET['reset_debug_log']) && isset($wps_ic->log) && is_object($wps_ic->log) && method_exists($wps_ic->log, 'reset')) {
            $wps_ic->log->reset();
        }

        /**
         * View Debug Log
         */
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
                // Lite Version
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
                    case 'missing-files':
                        $this->templates->get_admin_page('missing_files');
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