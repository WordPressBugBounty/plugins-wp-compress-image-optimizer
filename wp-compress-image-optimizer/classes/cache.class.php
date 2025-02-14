<?php


/**
 * Class - Cache
 * Handles CSS Caching
 */
class wps_ic_cache
{

    public static $cache_option = 'wps_ic_modified_css_cache';
    public static $cache;
    public static $options;
    public static $Requests;


    public function __construct()
    {
        self::$Requests = new wps_ic_requests();
    }


    public static function init()
    {
        self::$cache = get_option(self::$cache_option);
        self::$options = get_option(WPS_IC_OPTIONS);

        if (!empty($_GET['wpc_action'])) {
            self::purge_actions();
        }
    }

    public static function purge_actions()
    {
        if (!empty($_GET['wpc_action']) && empty($_GET['apikey'])) {
            wp_send_json_error();
        }

        if (!empty($_GET['wpc_action'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
            if ($apikey !== self::$options['api_key']) {
                wp_send_json_error('Bad API Key');
            }

            if ($_GET['wpc_action'] == 'purge_other_cache') {
                $options = get_option(WPS_IC_OPTIONS);
                $options['css_hash'] = mt_rand(1000, 9999);
                update_option(WPS_IC_OPTIONS, $options);

                self::purgeOtherCache();
            }
        }
    }

    public static function purgeOtherCache($json = true)
    {

        // Rocket - Clear cache
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        // Breeze
        self::purgeBreeze();

        // Others
        self::purgeSuperCache();
        self::purgeFastestCache();
        self::purge_cache_files();

        if ($json) {
            wp_send_json_success('Purged Other Cache');
        }
    }

    public static function purgeBreeze()
    {
        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
    }

    public static function purgeSuperCache()
    {
        if (defined('WPCACHEHOME')) {
            global $file_prefix;
            wp_cache_clean_cache($file_prefix, !empty($params['all']));
        }
    }

    public static function purgeFastestCache()
    {
        if (defined('WPFC_WP_CONTENT_BASENAME')) {
            global $file_prefix;
            wp_cache_clean_cache($file_prefix, !empty($params['all']));
        }
    }

    public static function purge_cache_files()
    {
        $cache_dir = WPS_IC_CACHE;

        self::removeDirectory($cache_dir);

        return true;
    }

    public static function removeDirectory($path)
    {
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? self::removeDirectory($file) : unlink($file);
        }
    }

    public static function purgeHooks()
    {
        // Elementor Purge Integration
        self::purgeHook('save_post', 1, 1, 1, 1);
        self::purgeHook('wp_insert_post', 1, 1, 1, 1);
        self::purgeHook('switch_theme', 1, 1, 1, 1);
        self::purgeHook('add_link');
        self::purgeHook('edit_link');
        self::purgeHook('delete_link');
        self::purgeHook('update_option_sidebars_widgets');
        self::purgeHook('update_option_category_base');
        self::purgeHook('update_option_tag_base');
        self::purgeHook('wp_update_nav_menu', 1, 1, 1, 1);
        self::purgeHook('permalink_structure_changed');
        self::purgeHook('customize_save');
        self::purgeHook('update_option_theme_mods_' . get_option('stylesheet'));

        //per page settings
        add_action('publish_post', ['wps_ic_cache', 'purgeCachePerPage'], 10, 1);
        add_action('wp_trash_post', ['wps_ic_cache', 'purgeCachePerPage'], 10, 1);
        add_action('delete_post', ['wps_ic_cache', 'purgeCachePerPage'], 10, 1);
    }

    public static function purgeCachePerPage() {
      // Get the excludes option
      $wpc_excludes = get_option('wpc-excludes', []);

      // Check if per_page_settings exists
      if (!isset($wpc_excludes['per_page_settings']) || empty($wpc_excludes['per_page_settings'])) {
        return;
      }

      // Loop through each page settings
      foreach ($wpc_excludes['per_page_settings'] as $page_id => $settings) {
        // Check if purge_on_new_post is enabled
        if (isset($settings['purge_on_new_post']) && $settings['purge_on_new_post'] !== 'false') {
          self::removeHtmlCacheFiles($page_id);
        }
      }
    }

    public static function purgeHook($hook, $cache = 1, $combined = 0, $critical = 0, $hash = 0)
    {
        if ($hash) {
            add_action($hook, ['wps_ic_cache', 'resetHashes'], 10, 0);
        }

        if ($cache) {
            add_action($hook, ['wps_ic_cache', 'removeHtmlCacheFiles'], 10, 1);
        }

        if ($combined) {
            add_action($hook, ['wps_ic_cache', 'removeCombinedFiles'], 10, 1);
        }

        if ($critical) {
            add_action($hook, ['wps_ic_cache', 'removeCriticalFiles'], 10, 1);
        }
    }

    public static function resetHashes()
    {
        if (!function_exists('get_option')) {
            require_once ABSPATH . 'wp-admin/includes/option.php';
        }

        $hash = substr(md5(microtime(true)), 0, 6);

        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
            $options = get_option(WPS_IC_OPTIONS);
            $options['css_hash'] = $hash;
            $options['js_hash'] = substr(md5(microtime(true)), 0, 6);
            update_option(WPS_IC_OPTIONS, $options);
        } else {
            $options = get_option(WPS_IC_OPTIONS);
            $options['css_hash'] = $hash;
            $options['js_hash'] = substr(md5(microtime(true)), 0, 6);
            update_option(WPS_IC_OPTIONS, $options);
        }
    }

    public static function purgeAllCache()
    {
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCacheFiles('all');
    }

    public static function purgeElementorCache($document)
    {
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCacheFiles($document->get_post()->ID);
    }

    public static function removeCombinedFiles($post_id = 'all', $post = '', $update = '')
    {
        if (!is_int($post_id) && $post_id !== 'all') {
            $post_id = 'all';
        }
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCombinedFiles($post_id);
    }

    public static function removeCriticalFiles($post_id = 'all', $post = '', $update = '')
    {
        if (!is_int($post_id) && $post_id !== 'all') {
            $post_id = 'all';
        }
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCriticalFiles($post_id);
    }

    public static function purgeCDNUpdate()
    {
        $cacheLogic = new wps_ic_cache();
        $cache = new wps_ic_cache_integrations();

        $options = get_option(WPS_IC_OPTIONS);

        $cache::purgeAll(false, true);
        $cache::purgeCombinedFiles();

        // Purge HTML Cache
        $cacheLogic::removeHtmlCacheFiles(0); // Purge & Preload
        $cacheLogic::preloadPage(0); // Purge & Preload

        $hash = time();
        $options['css_hash'] = $hash;
        $options['js_hash'] = $hash;
        $options['updated_hash'] = time();
        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        set_transient('wps_ic_purging_cdn', 'true', 30);

        self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $options['api_key']]);

        // Clear cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        delete_transient('wps_ic_purging_cdn');
    }

    public static function removeHtmlCacheFiles($post_id = 'all', $post = '', $update = '')
    {

        if (!is_int($post_id) && $post_id !== 'all' && $post_id !== 'home') {
            $post_id = 'all';
        }
        $cacheHtml = new wps_cacheHtml();

        $cacheHtml->removeCacheFiles($post_id);
    }

    public static function preloadPage($post_id, $post = '', $update = '')
    {

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        #$call = wp_remote_post(WPS_IC_PRELOADER_API_URL, ['body' => ['single_url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']]]);
        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->preloadPage($url);
    }

    public static function update_css_hash($post_id = 0)
    {
        // Fix issues with Options
        //'preload-scripts' => '1',
        //'fetchpriority-high' => '1',
        $options = get_option(WPS_IC_SETTINGS);
        $options['preload-scripts'] = '1';
        $options['fetchpriority-high'] = '1';
        update_option(WPS_IC_SETTINGS, $options);


        // TODO: Sometimes $post_id is ObjectClass, does this fix it? (occurs on plugin manual zip update)
        if (!is_int($post_id) && !is_string($post_id)) {
            $post_id = 0;
        }

        if (!function_exists('get_option')) {
            require_once ABSPATH . 'wp-admin/includes/option.php';
        }

        $hash = substr(md5(microtime(true)), 0, 6);

        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
            $options = get_option(WPS_IC_OPTIONS);
            $options['css_hash'] = $hash;
            update_option(WPS_IC_OPTIONS, $options);
        } else {
            // Reset Cache
            $cacheLogic = new wps_ic_cache();
            $cacheLogic::removeHtmlCacheFiles($post_id); // Purge & Preload

            // Preload on plugin update, just 1 time in 3 minutes
            if (!get_transient('wpc_update_css_preload')) {
                set_transient('wpc_update_css_preload', 'true', 60 * 3);
                $cacheLogic::preloadPage($post_id); // Purge & Preload => Causing Issue with memory
            }

            $options = get_option(WPS_IC_OPTIONS);
            $options['css_hash'] = $hash;

            //update js hash
            $options['js_hash'] = substr(md5(microtime(true)), 0, 6);
            update_option(WPS_IC_OPTIONS, $options);
        }
    }

    public static function deleteFolder($folderPath)
    {
        if (is_dir($folderPath)) {
            $contents = scandir($folderPath);
            foreach ($contents as $item) {
                if ($item != "." && $item != "..") {
                    $itemPath = $folderPath . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($itemPath)) {
                        // Recursively delete subdirectories and their contents
                        self::deleteFolder($itemPath);
                    } else {
                        // Delete files
                        unlink($itemPath);
                    }
                }
            }

            // Delete the empty folder
            if (is_dir($folderPath)) {
                rmdir($folderPath);
            }

        }

        return true;
    }

    public static function purgeCache()
    {
        self::$options = get_option(WPS_IC_OPTIONS);
        set_transient('wps_ic_purging_cdn', 'true', 10);

        #$url = WPS_IC_KEYSURL . '?action=cdn_purge&apikey=' . self::$options['api_key'] . '&callback=' . site_url() . '&hash=' . md5(microtime());
        #$call = wp_remote_get($url, array('timeout' => 4, 'blocking' => false));

        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => self::$options['api_key'], 'callback' => site_url(), 'hash' => md5(microtime())]);

        // Purge Cached Files
        $cache_dir = WPS_IC_CACHE;
        if (file_exists($cache_dir)) {
            self::removeDirectory($cache_dir);
        }
    }

    public function purgeCDN()
    {
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        $hash = time();
        $options['css_hash'] = $hash;
        $options['js_hash'] = $hash;
        $options['updated_hash'] = time();
        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        set_transient('wps_ic_purging_cdn', 'true', 30);

        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $options['api_key']], ['timeout' => 10]);

        // Clear cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        delete_transient('wps_ic_purging_cdn');
    }


    // Store cached file

    public function is_page_cached($pageID)
    {
        if (isset(self::$cache[$pageID]) && !empty(self::$cache[$pageID])) {
            return true;
        } else {
            return false;
        }
    }

    public function is_post_cached($postID)
    {
        if (isset(self::$cache[$postID]) && !empty(self::$cache[$postID])) {
            return true;
        } else {
            return false;
        }
    }

    public function get_cache($ID)
    {
        if (isset(self::$cache[$ID]) && !empty(self::$cache[$ID])) {
            return self::$cache[$ID];
        } else {
            return [];
        }
    }


}