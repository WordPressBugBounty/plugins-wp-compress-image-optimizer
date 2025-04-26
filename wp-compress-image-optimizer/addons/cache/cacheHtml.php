<?php

class wps_cacheHtml
{

    private $siteUrl;
    private $urlKey;
    private $cacheExists = false;
    private $cachedHtml = '';

    private $host;
    private $cachePath;
    private $options;
    private $url_key_class;

    public function __construct()
    {
        //options[cache][advanced]
        $this->options = get_option(WPS_IC_SETTINGS);

        if (!file_exists(WPS_IC_CACHE)) {
            mkdir(rtrim(WPS_IC_CACHE, '/'));
        }

        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();

        // Append user cookie hash to the cache path if user is logged in
        $user_hash = '';
        if (defined('WPC_CACHE_LOGGED_IN') && WPC_CACHE_LOGGED_IN) {
            foreach ($_COOKIE as $key => $value) {
                if (strpos($key, 'wordpress_logged_in_') === 0) {
                    $user_hash = md5($key . substr($value, 0, 10)) . '/';
                    break;
                }
            }

        }

        $this->cachePath = WPS_IC_CACHE . $user_hash . $this->urlKey . '/';
    }

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilder()
    {
        $page_builders = ['run_compress', //wpc
            'run_restore', //wpc
            'elementor-preview', //elementor
            'fl_builder', //beaver builder
            'et_fb', //divi
            'preview', //WP Preview
            'builder', //builder
            'brizy', //brizy
            'fb-edit', //avada
            'bricks', //bricks
            'ct_template', //ct_template
            'ct_builder', //ct_builder
            'cs-render', //cornerstone
            'tatsu', //tatsu
            'trp-edit-translation', //thrive
            'brizy-edit-iframe', //brizy
            'ct_builder', //oxygen
            'livecomposer_editor', //livecomposer
            'tatsu', //tatsu
            'tatsu-header', //tatsu-header
            'tatsu-footer', //tatsu-footer
            'is-editor-iframe', //tatsu-footer
            'tve' //thrive
        ];

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {
            //brizyFrontend fix
            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {
            //optimizePress builder fix
            return true;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if (!empty($page_builders)) {
            foreach ($page_builders as $page_builder) {
                if (isset($_GET[$page_builder])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilderFE()
    {
        if (class_exists('BT_BB_Root')) {
            if (is_user_logged_in() && !is_admin()) {
                return true;
            }
        }

        return false;
    }

    public static function isFEBuilder()
    {
        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['trp-edit-translation']) || !empty($_GET['elementor-preview']) || !empty($_GET['tatsu']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['tve']) || !empty($_GET['et_fb']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['brizy-edit-iframe']) || !empty($_GET['brizy-edit']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return true;
        } else {
            return false;
        }
    }

    public function init()
    {
        return '';
    }

    public function cacheEnabled()
    {

        if (!empty($_GET['test_cache'])) {
            return true;
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            return false;
        }


        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return false;
        }

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return false;
        }

        if (empty($this->options['cache']['advanced']) || $this->options['cache']['advanced'] == '0') {
            return false;
        }

        return true;
    }

    public function cacheValid($prefix = '')
    {
        $cacheFile = $this->cachePath . $prefix . 'index.html';

        if ((!file_exists($cacheFile) || filesize($cacheFile) <= 0) && (!file_exists($cacheFile . '_gzip') || filesize($cacheFile . '_gzip') <= 0)) {
            return false;
        }

        return true;
    }


    public function cacheExpired($prefix = '')
    {
        // Does not work on nginx, kill it
        return false;

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        $cacheFile = $this->cachePath . $prefix . 'index.html';

        if (!file_exists($cacheFile . '_gzip') && !file_exists($cacheFile)) {
            return true;
        }

        // Hours into minutes into seconds
        $expireInterval = $this->options['cache']['expire'] * 60 * 60;
        $fileModifiedTime = filemtime($cacheFile);

        if ($fileModifiedTime + $expireInterval < time()) {
            unlink($cacheFile);
            return true;
        } else {
            return false;
        }
    }


    public function cacheExists($prefix = '')
    {
        if (!empty($_GET['dbgCache']) && $_GET['dbgCache'] == 'exists-1') {
            die($_GET['dbgCache']);
        }

        if (!empty($_GET['disable_cache'])) {
            return false;
        }

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        if (function_exists('gzencode')) {

            if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && filesize($this->cachePath . $prefix . 'index.html' . '_gzip') > 0) {

                if (!empty($_GET['dbgCache']) && $_GET['dbgCache'] == 'exists-3') {
                    die($_GET['dbgCache']);
                }

                return true;
            }
        }


        if (file_exists($this->cachePath . $prefix . 'index.html') && filesize($this->cachePath . $prefix . 'index.html') > 0) {

            if (!empty($_GET['cacheExistsDebug']) && $_GET['cacheExistsDebug'] == '4') {
                die($_GET['cacheExistsDebug']);
            }

            return true;
        } else {
            return false;
        }
    }


    /**
     * Just verify it's not some page test as we don't want those to cache HTML
     * @return void
     */
    public function pageTest()
    {
        return false;
    }

    public function saveCache($buffer, $prefix = '')
    {

        if (!empty($_GET['disable_cache'])) {
            return $buffer;
        }

        if (empty($buffer) || strlen($buffer) < 100 || strpos($buffer, '</body>') === false) {
            return $buffer;
        }

        if (empty($this->options['cache']['ignore-server-control']) || $this->options['cache']['ignore-server-control'] == '0') {
            $cacheControl = strtolower($_SERVER['HTTP_CACHE_CONTROL']);
            if (strpos($cacheControl, 'no-cache') !== false ||
                strpos($cacheControl, 'no-store') !== false ||
                strpos($cacheControl, 'private') !== false) {
                return $buffer;
            }
        }

        $excludes = get_option('wpc-excludes');
        $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        if (!empty($excludes) && !empty($excludes['cache'])) {
            if (in_array($url, $excludes['cache'])) {
                return $buffer;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $buffer;
        }

        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            global $post;
            if (!empty($post->ID)) {
                $preload_warmup = new wps_ic_preload_warmup();
                $preload_warmup->addError($post->ID, 'DONOTCACHEPAGE');
            }
            return $buffer;
        }

        if (is_user_logged_in()) {
            if (!$this->cacheLoggedIn()) {
                return $buffer;
            }
        }

        //page type checks for cache
        $purge_rules = get_option('wps_ic_purge_rules');
		    if (!isset($purge_rules['post-publish'])){
					$options = new wps_ic_options();
			    $purge_rules = $options->get_preset('purge_rules');
		    }
        $type_lists = [];
        if (!empty($purge_rules['type-lists'])) {
            $type_lists = $purge_rules['type-lists'];
        }

        if (is_archive() || is_category() || is_tag() || is_author() || is_date() || is_post_type_archive() || is_tax()) {
            if (!isset($type_lists['archive-pages'])) {
                $type_lists['archive-pages'] = [];
            }
            if (!in_array($this->urlKey, $type_lists['archive-pages'])) {
                $type_lists['archive-pages'][] = $this->urlKey;
            }
        }

        if ($this->hasRecentPostsWidget($buffer)) {
            if (!isset($type_lists['recent-posts-widget'])) {
                $type_lists['recent-posts-widget'] = [];
            }
            if (!in_array($this->urlKey, $type_lists['recent-posts-widget'])) {
                $type_lists['recent-posts-widget'][] = $this->urlKey;
            }
        }

        if ($this->is_mobile()) {
            $prefix = 'mobile';
        }

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        if (!file_exists($this->cachePath)) {
            mkdir(rtrim($this->cachePath, '/'), 0777, true);
        }

				if (!empty($this->options['cache']['headers']) && $this->options['cache']['headers'] == '1'){
					$headers = array();

					foreach (headers_list() as $header) {
						$parts = explode(':', $header, 2);
						if (count($parts) == 2) {
							$headers[trim($parts[0])] = trim($parts[1]);
						}
					}

					$headersJson = json_encode($headers);
					file_put_contents($this->cachePath . 'headers.json', $headersJson);
				}

        if (function_exists('gzencode')) {
            $this->saveGzCache($buffer, $prefix);
        }

        $purge_rules['type-lists'] = $type_lists;
        update_option('wps_ic_purge_rules', $purge_rules);

        return $buffer;
    }

    public function cacheLoggedIn()
    {

        if (!empty($this->options['cache']['cache-logged-in']) && $this->options['cache']['cache-logged-in'] == '1') {
            return true;
        }

        return false;
    }

    public function hasRecentPostsWidget($buffer)
    {
        if (empty($buffer)) {
            return false;
        }

        // Primary WordPress recent posts widget identifiers
        $primary_markers = [
            'widget_recent_entries',
            'wp-block-latest-posts',
            'class="recent-posts'
        ];

        // Check for definitive recent posts markers first
        foreach ($primary_markers as $marker) {
            if (strpos($buffer, $marker) !== false) {
                return true;
            }
        }

        // Check for specific shortcodes that display recent posts
        if (
            strpos($buffer, '[recent_posts') !== false ||
            strpos($buffer, '[display-posts') !== false
        ) {
            return true;
        }

        return false;
    }

    public function is_mobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
            if (preg_match('#^.*(2.0\ MMP|240x320|400X240|mobile|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $agent) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($agent, 0, 4))) {
                return true;
            }
        }

        return false;
    }

    public function saveGzCache($buffer, $prefix)
    {
        if (!empty($_GET['disable_cache'])) {
            return true;
        }

        $fp = fopen($this->cachePath . $prefix . 'index.html' . '_gzip', 'w+');
        fwrite($fp, gzencode($buffer, 8));
        fclose($fp);

        return $buffer;
    }

    public function getCacheFilePath($prefix = '')
    {
        if (function_exists('readgzfile')) {
            return $this->cachePath . $prefix . '/index.html' . '_gzip';
        }

        return $this->cachePath . $prefix . '/index.html';
    }

    public function getCache($prefix = '')
    {
        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        if (function_exists('readgzfile')) {
            if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && is_readable($this->cachePath . $prefix . 'index.html' . '_gzip')) {
                $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html' . '_gzip');
                // Nginx instantly echoes readgzfile instead of saving it to variable.
                readgzfile($this->cachePath . $prefix . 'index.html' . '_gzip');
                die();
            }
        }

        if (file_exists($this->cachePath . $prefix . 'index.html') && is_readable($this->cachePath . $prefix . 'index.html')) {
            $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html');
            readfile($this->cachePath . $prefix . 'index.html');
            die();
        }
    }

    public function setupCacheHeaders($cache_filepath)
    {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');

        if (!empty($this->settings['cache_refresh_time']) && $this->settings['cache_refresh_time'] > 0) {
            $cacheSeconds = $this->settings['cache_refresh_time'] * 60; // Convert minutes to seconds
            header('Cache-Control: max-age=' . $cacheSeconds . ', public');
            $expiresTime = time() + $cacheSeconds;
            header('Expires: ' . gmdate('D, d M Y H:i:s', $expiresTime) . ' GMT');
        } else {
            header('Cache-Control: public, max-age=' . 60 * 60); // Ensures that the file is not cached
            header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        }

        header('X-Cache-By: WP Compress - Gzip');
    }

    public function removeCacheFiles($post_id)
    {
        if ($post_id == 'home') {
            $post_id = 0;
        }

        if ($post_id == 'all') {
            self::removeDirectory(WPS_IC_CACHE);
            self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/');

        } else {
            if ($post_id != 0) {
                $url = get_permalink($post_id);
            } else {
                $url = home_url();
            }

            $urlKey = $this->url_key_class->setup($url);
            self::removeDirectory(WPS_IC_CACHE . $urlKey);
            self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/' . $urlKey);
        }
    }

    public static function removeDirectory($path)
    {

        $path = rtrim($path, '/');
        $files = glob($path . '/*');

        if (!empty($files)) {
            foreach ($files as $file) {
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }

        $files = glob($path . '/*');

        if (is_dir($path) && empty($files)) {
            rmdir($path);
        }
    }

    public function removeCacheFilesByKey($urlKey)
    {
        self::removeDirectory(WPS_IC_CACHE . $urlKey);
        self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/' . $urlKey);
    }

    public function removeCombinedFiles($post_id)
    {
        if ($post_id == 'all') {
            self::removeDirectory(WPS_IC_COMBINE);
            return;
        }

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        $urlKey = $this->url_key_class->setup($url);
        self::removeDirectory(WPS_IC_COMBINE . $urlKey);
    }

    public function removeCriticalFiles($post_id)
    {
        if ($post_id == 'all') {
            self::removeDirectory(WPS_IC_CRITICAL);
            return;
        }

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        $urlKey = $this->url_key_class->setup($url);
        self::removeDirectory(WPS_IC_CRITICAL . $urlKey);
    }

    public function recursiveDelete($folder)
    {
        // Delete all the files in the folder
        $files = glob($folder . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } else {
                $this->recursiveDelete($file);
            }
        }

        // Delete the folder itself
        if (is_dir($folder)) rmdir($folder);
    }

		private function getAllHeaders() {
				$headers = array();
				foreach ($_SERVER as $name => $value) {
						if (substr($name, 0, 5) == 'HTTP_') {
								$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
								$headers[$name] = $value;
						} elseif ($name == 'CONTENT_TYPE' || $name == 'CONTENT_LENGTH') {
								$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
								$headers[$name] = $value;
						}
				}
				return $headers;
		}

}