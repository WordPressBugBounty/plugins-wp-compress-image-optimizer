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

        if (current_user_can('manage_options')) {
            $admin_bar->add_menu(['id' => 'wp-compress', 'title' => '<div id="wpc-ic-icon-admin-menu" class="ab-item wpc-ic-logo svg"><span class="screen-reader-text"></span></div>', 'href' => admin_url('options-general.php?page=' . $this::$slug), 'meta' => ['title' => __(''), 'html' => '<div class="wp-compress-admin-bar-icon"></div>'],]);
        }

        if (!is_admin() && current_user_can('manage_options')) {
            // Show options in frontend

            $admin_bar->add_menu(['id' => 'wp-compress-purge-html-cache', 'parent' => 'wp-compress', 'title' => 'Purge & Preload HTML Cache', 'href' => '#', 'meta' => ['title' => __('Purge & Preload HTML Cache'), 'target' => '_self', 'class' => 'wp-compress-bar-purge-html-cache'],]);

            if (!empty($options['critical']['css']) && $options['critical']['css'] == '1') {
                $admin_bar->add_menu(['id' => 'wp-compress-purge-critical-css', 'parent' => 'wp-compress', 'title' => 'Purge Critical CSS', 'href' => '#', 'meta' => ['title' => __('Purge Critical CSS'), 'target' => '_self', 'class' => 'wp-compress-bar-purge-critical-css'],]);
            }

            $conditions = array('css', 'js', ['serve', 'jpg'], ['serve', 'png'], ['serve', 'gif'], ['serve', 'svg']);
            foreach ($conditions as $key => $condition) {
                if (is_array($condition)) {
                    if (!empty($options[$condition[0]][$condition[1]])) {
                        $option = $options[$condition[0]][$condition[1]];
                        if (isset($option) && $option == '1') {
                            $admin_bar->add_menu(['id' => 'wp-compress-clear-cache', 'parent' => 'wp-compress', 'title' => 'Purge CDN Cache', 'href' => '#', 'meta' => ['title' => __('Purge CDN Cache'), 'target' => '_self', 'class' => 'wp-compress-bar-clear-cache'],]);
                            break;
                        }
                    }
                } else {
                    if (!empty($options[$condition])) {
                        $option = $options[$condition];
                        if (isset($option) && $option == '1') {
                            $admin_bar->add_menu(['id' => 'wp-compress-clear-cache', 'parent' => 'wp-compress', 'title' => 'Purge CDN Cache', 'href' => '#', 'meta' => ['title' => __('Purge CDN Cache'), 'target' => '_self', 'class' => 'wp-compress-bar-clear-cache'],]);
                            break;
                        }
                    }
                }
            }

            $admin_bar->add_menu(['id' => 'wp-compress-preload-page', 'parent' => 'wp-compress', 'title' => 'Preload Page', 'href' => '#', 'meta' => ['title' => __('Preload Page'), 'target' => '_self', 'class' => 'wp-compress-bar-preload-cache'],]);

            if (!empty($options['critical']['css']) && $options['critical']['css'] == '1') {
                $admin_bar->add_menu(['id' => 'wp-compress-generate-critical-css', 'parent' => 'wp-compress', 'title' => 'Generate Critical CSS', 'href' => '#', 'meta' => ['title' => __('Generate Critical CSS'), 'target' => '_self', 'class' => 'wp-compress-bar-generate-critical-css'],]);
            }

            $admin_bar->add_menu(['id' => 'wp-compress-view-as-visitor', 'parent' => 'wp-compress', 'title' => 'View as Visitor', 'href' => '#', 'meta' => ['title' => __('View as Visitor'), 'target' => '_self', 'class' => 'wp-compress-view-as-visitor'],]);

        } elseif (current_user_can('manage_options')) {
            // Shows if user is logged in!

            $admin_bar->add_menu(['id' => 'wp-compress-purge-html-cache', 'parent' => 'wp-compress', 'title' => 'Purge & Preload HTML Cache', 'href' => '#', 'meta' => ['title' => __('Purge & Preload HTML Cache'), 'target' => '_self', 'class' => 'wp-compress-bar-purge-html-cache'],]);

            if (!empty($options['critical']['css']) && $options['critical']['css'] == '1') {
                $admin_bar->add_menu(['id' => 'wp-compress-purge-critical-css', 'parent' => 'wp-compress', 'title' => 'Purge Critical CSS', 'href' => '#', 'meta' => ['title' => __('Purge Critical CSS'), 'target' => '_self', 'class' => 'wp-compress-bar-purge-critical-css'],]);
            }

            $conditions = array('css', 'js', ['serve', 'jpg'], ['serve', 'png'], ['serve', 'gif'], ['serve', 'svg']);
            foreach ($conditions as $key => $condition) {
                if (is_array($condition)) {
                    if (!empty($options[$condition[0]][$condition[1]])) {
                        $option = $options[$condition[0]][$condition[1]];
                        if ($option == '1') {
                            // If in admin area it shows
                            $admin_bar->add_menu(['id' => 'wp-compress-clear-cache', 'parent' => 'wp-compress', 'title' => 'Purge CDN Cache', 'href' => '#', 'meta' => ['title' => __('Purge CDN Cache'), 'target' => '_self', 'class' => 'wp-compress-bar-clear-cache'],]);
                            break;
                        }
                    }
                } else {
                    if (!empty($options[$condition])) {
                        $option = $options[$condition];
                        if ($option == '1') {
                            // If in admin area it shows
                            $admin_bar->add_menu(['id' => 'wp-compress-clear-cache', 'parent' => 'wp-compress', 'title' => 'Purge CDN Cache', 'href' => '#', 'meta' => ['title' => __('Purge CDN Cache'), 'target' => '_self', 'class' => 'wp-compress-bar-clear-cache'],]);
                            break;
                        }
                    }
                }
            }

        }

        if (current_user_can('manage_options')) {
            $admin_bar->add_menu(['id' => 'wp-compress-settings', 'parent' => 'wp-compress', 'title' => 'Settings', 'href' => admin_url('options-general.php?page=' . $this::$slug), 'meta' => ['title' => __('Settings'), 'target' => '_self', 'class' => 'wp-compress-bar-settings'],]);
        }

        //Status for critical, cache and preload
        if (is_admin_bar_showing() && !is_admin()) {

            if (empty($options['status']['hide_critical_css_status']) || $options['status']['hide_critical_css_status'] == '0') {
                if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {
                    $critical = new wps_criticalCss();
                    if ($critical->criticalExists() !== false) {
                        //$status = 'Generated';
                        $status = '<span class="wp-compress-admin-bar-success"></span>';
                    } else {
                        //$status = 'Not Generated';
                        $status = '<span class="wp-compress-admin-bar-fail"></span>';
                    }
                    $admin_bar->add_menu(['id' => 'wp-compress-critical-status', 'title' => '<div class="wp-compress-critical-status">Critical Css: ' . $status . '</div>', 'href' => '', 'meta' => ['title' => __(''), 'html' => ''],]);
                }
            }

            if (empty($options['status']['hide_cache_status']) || $options['status']['hide_cache_status'] == '0') {
                if (!empty(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') {
                    $cache = new wps_cacheHtml();
                    if ($cache->cacheExists()) {
                        $status = '<span class="wp-compress-admin-bar-success"></span>';
                    } else {
                        $status = '<span class="wp-compress-admin-bar-fail"></span>';
                    }
                    $admin_bar->add_menu(['id' => 'wp-compress-cache-status', 'title' => '<div class="wp-compress-cache-status">Cache: ' . $status . '</div>', 'href' => '', 'meta' => ['title' => __(''), 'html' => ''],]);
                }
            }

        }

    }


    public function plugin_list_link($links)
    {
        $options = get_option(WPS_IC_OPTIONS);
        if (!empty($options['api_key'])) {
            $links = array_merge(['<a href="' . admin_url('options-general.php?page=' . $this::$slug) . '">' . 'Settings' . '</a>'], $links);
            $links['wps-ic-reconnect'] = '<a href="#" class="reconnect-wp-compress-image-optimizer">Reconnect</a>';
        } else {
            $links = array_merge(['<a href="' . admin_url('options-general.php?page=' . $this::$slug) . '">' . 'Get Started' . '</a>'], $links);
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
        add_menu_page('WP Compress', 'WP Compress', 'manage_options', $this::$slug . '-mu', [$this, 'render_mu_admin_page']);
    }


    public function menu_init()
    {
        add_submenu_page('options-general.php', 'WP Compress', 'WP Compress', 'manage_options', $this::$slug, [$this, 'render_admin_page_v4']);
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
        if (!empty($_GET['reset_debug_log'])) {
            $wps_ic->log->reset();
        }

        /**
         * View Debug Log
         */
        if (!empty($_GET['view_debug_log'])) {
            $wps_ic->log->view();
            die();
        }

        $apikey = '';
        if (!empty(self::$options['api_key'])) {
            $apikey = self::$options['api_key'];
        }

        if (empty($apikey) || !$apikey) {
            // Old Version
            #$this->templates->get_admin_page('connect/api-connect');
            #$this->templates->get_admin_page('advanced_settings_v4');

            if (!empty($_GET['showAdvanced'])) {
                $this->templates->get_admin_page('advanced_settings_v4');
            } else {
                // Lite Version
                $this->templates->get_admin_page('connect/lite-api-connect');
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