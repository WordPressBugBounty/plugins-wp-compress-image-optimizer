<?php

class wps_ic_preload_warmup
{

    public static $standaloneWarmup;
    public static $apiUrl;
    public static $warmupVersion;

    public function __construct()
    {
        self::$warmupVersion = 'v4/';
        $this->getApiUrl();
        $this->logFilePath = WPS_IC_DIR . 'warmup-log.txt';
        // TODO: Bug, can't update plugin because of it because file gets created before the plugin is disabled!
        $this->logFile = fopen($this->logFilePath, 'a');
        $this->get_filesystem();
    }


    public function getApiUrl()
    {

        $location = get_option('wps_ic_geo_locate_v2');
        if (empty($location)) {
            $location = $this->geoLocate();
        }

        if (is_object($location)) {
            $location = (array)$location;
        }


        if (isset($location) && !empty($location)) {
            if (is_array($location) && !empty($location['server'])) {
                if (empty($location['continent'])) {
                    self::$apiUrl = 'https://germany.zapwp.net/warmup/';
                } elseif ($location['continent'] == 'CUSTOM') {
                    self::$apiUrl = 'https://' . $location['custom_server'] . '.zapwp.net/warmup/';
                } elseif ($location['continent'] == 'AS' || $location['continent'] == 'IN') {
                    self::$apiUrl = 'https://singapore.zapwp.net/warmup/';
                } elseif ($location['continent'] == 'EU') {
                    self::$apiUrl = 'https://germany.zapwp.net/warmup/';
                } elseif ($location['continent'] == 'OC') {
                    self::$apiUrl = 'https://sydney.zapwp.net/warmup/';
                } elseif ($location['continent'] == 'US' || $location['continent'] == 'NA' || $location['continent'] == 'SA') {
                    self::$apiUrl = 'https://nyc.zapwp.net/warmup/';
                } else {
                    self::$apiUrl = 'https://germany.zapwp.net/warmup/';
                }
            } else {
                self::$apiUrl = 'https://' . $location->server . '/warmup/';
            }
        } else {
            self::$apiUrl = 'https://germany.zapwp.net/warmup/';
        }

        self::$standaloneWarmup = str_replace('warmup', 'standalone_warmup', self::$apiUrl);
        self::$apiUrl .= self::$warmupVersion;

    }


    public function geoLocate()
    {
        $force_location = get_option('wpc-ic-force-location');
        if (!empty($force_location)) {
            return $force_location;
        }

        $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode(site_url()), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);

            if ($body->success) {
                update_option('wps_ic_geo_locate_v2', $body->data);

                return $body->data;
            } else {
                update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);

                return ['country' => 'EU', 'server' => 'frankfurt.zapwp.net'];
            }
        } else {
            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);

            return ['country' => 'EU', 'server' => 'frankfurt.zapwp.net'];
        }
    }

    public function get_filesystem()
    {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php');
        global $wpc_filesystem;

        if (!defined('FS_CHMOD_DIR')) {
            define('FS_CHMOD_DIR', (fileperms(ABSPATH) & 0777 | 0755));
        }

        if (!defined('FS_CHMOD_FILE')) {
            define('FS_CHMOD_FILE', (fileperms(ABSPATH . 'index.php') & 0777 | 0644));
        }

        if (!isset($wpc_filesystem) || !is_object($wpc_filesystem)) {
            $wpc_filesystem = new WP_Filesystem_Direct('');
        }
    }

    public static function isFeatureEnabled($featureName)
    {
        $feature = get_transient($featureName . 'Enabled');
        if (!$feature || $feature == '0') {
            return false;
        }

        return true;
    }

    public function preloadPage($url)
    {
        $call = wp_remote_post(self::$apiUrl, ['method' => 'POST', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'body' => ['action' => 'preloadPage', 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'single_url' => $url], 'timeout' => 10]);
    }

    // Filter function to modify search to only search post titles

    public function getPagesForFiltering($post_type, $post_status, $page_number, $offset, $search = '')
    {
        $pages = $this->getPages($post_type, 1, 0, -1, $search);
        $wpc_excludes = get_option('wpc-excludes');
        $url_key_class = new wps_ic_url_key();
        $settings = get_option(WPS_IC_SETTINGS);
        $total_count = 0;
        $filtered_pages = [];
        $start_index = ($page_number - 1) * 10;
        $end_index = $start_index + 10;

        //local addition
        $local = get_option('wpc-connectivity-status');
        //end local addition
        foreach ($pages as $key => $page) {
            $url = $page['link'];
            $urlKey = $url_key_class->setup($url);
            $cachePath = WPS_IC_CACHE . $urlKey . '/';
            $critPath = WPS_IC_CRITICAL . $urlKey . '/critical_desktop.css';
            $page_excludes = isset($wpc_excludes['page_excludes'][$page['id']]) ? $wpc_excludes['page_excludes'][$page['id']] : [];
            $cacheActive = (!empty($settings['cache']['advanced']) && $settings['cache']['advanced'] == '1' && !isset($page_excludes['advanced_cache'])) || (isset($page_excludes['advanced_cache']) && $page_excludes['advanced_cache'] == '1');

            $hasErrorCode = false;
            if (!empty($page['errors'])) {
                foreach ($page['errors'] as $errorCode) {
                    if (is_numeric($errorCode) && (int)$errorCode >= 300 && (int)$errorCode <= 600) {
                        $hasErrorCode = true;
                        break;
                    }
                }
            }

            $cacheGenerated = '0';
            $critGenerated = '1';
            if ($cacheActive) {
                if (function_exists('gzencode')) {
                    if (file_exists($cachePath . 'index.html' . '_gzip') && filesize($cachePath . 'index.html' . '_gzip') > 0) {
                        $cacheGenerated = '1';
                    }
                } else {
                    if (file_exists($cachePath . 'index.html') && filesize($cachePath . 'index.html') > 0) {
                        $cacheGenerated = '1';
                    }
                }
            }

            $doNotCache = false;
            if (!empty($page['errors']['notice'])) {
                $doNotCache = in_array('DONOTCACHEPAGE', $page['errors']['notice']);
            }

            //local addition
            if (!empty($local) && $local == 'failed' && ($cacheGenerated == '1' || $doNotCache)) {
                $preloaded = '1';
            } else {
                $preloaded = '1';

                //check critical
                if ((isset($page_excludes['critical_css']) && $page_excludes['critical_css'] == '0')) {
                    // Excluded from Smart Optimizations
                } else if (!empty($settings['critical']['css']) && $settings['critical']['css'] == '1') {
                    if (!file_exists($critPath)) {
                        $critGenerated = '0';
                        //$preloaded = '0';
                    }
                }

                if (($cacheGenerated == '0' && !$doNotCache) && $cacheActive) {
                    $preloaded = '0';
                }
            }

            $page['preloaded'] = $preloaded;
            $page['critGenerated'] = $critGenerated;
            $page['cacheGenerated'] = $cacheGenerated;

            $optimized = $preloaded === '1';
            $skipped = isset($hasErrorCode) && $hasErrorCode;

            $current_status = $skipped ? 'skipped' : ($optimized ? 'optimized' : 'unoptimized');

            if (in_array($current_status, $post_status)) {
                $total_count++;

                if ($total_count > $start_index && $total_count <= $end_index) {
                    $filtered_pages[] = $page;
                }
            }
        }

        // Return the total count and the filtered subset of pages
        $return = ['total' => $total_count, 'pages' => $filtered_pages];

        return $return;
    }

    public function getPages($post_type, $page, $offset, $limit, $search = '')
    {
        $available_pages = [];
        $exclude_id = [];

        $post_info = get_transient('wpc-post-info');
        $warmup_errors = get_option('wpc-warmup-errors');

        if ($post_type == 'any' || empty($post_type)) {
            $post_type = ['post', 'page', 'product'];
        }


        if (!empty($homePage)) {
            if ($page == 1 && (in_array('page', $post_type)) && (empty($search) || stripos('Home Page', $search) !== false)) {
                if (!isset($post_info[$homePage])) {
                    $post_info[$homePage] = $this->fetchPostInfo($homePage, ' (Home Page)');
                    $post_info[$homePage]['home'] = true;
                }

                if (!empty($search)) {
                    if (stripos($post_info[$homePage]['title'], $search) !== false) {
                        $available_pages[] = $post_info[$homePage];
                    }
                } else {
                    $available_pages[] = $post_info[$homePage];
                }
            }
            $exclude_id[] = $homePage;
        } elseif ($page == 1 && (in_array('page', $post_type)) && (empty($search) || stripos('Home Page', $search) !== false)) {
            $available_pages[] = ['title' => 'Home Page', 'type' => '', 'id' => 'home', 'link' => home_url(), 'home' => true];
            $id = url_to_postid(home_url());
            if ($id > 0) {
                $exclude_id[] = $id;
            }
        }

        if (!empty($postsPage)) {
            if ($page == 1 && (in_array('page', $post_type)) && (empty($search) || stripos('Blog', $search) !== false)) {
                if (!isset($post_info[$postsPage])) {
                    $post_info[$postsPage] = $this->fetchPostInfo($postsPage, ' (Blog)');
                }
                if (!empty($search)) {
                    if (stripos($post_info[$postsPage]['title'], $search) !== false) {
                        $available_pages[] = $post_info[$postsPage];
                    }
                } else {
                    $available_pages[] = $post_info[$postsPage];
                }
            }
            $exclude_id[] = $postsPage;
        }


        $args = ['post_type' => $post_type, 'posts_per_page' => $limit, 'paged' => $page, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'DESC', 'post_status' => 'publish', 'post__not_in' => $exclude_id, 'fields' => 'ids'];

        if (!empty($search)) {
            $args = array_merge($args, ['s' => $search]);

            //remove this filter is search gets too slow
            add_filter('posts_search', [$this, 'search_filter_by_title_only'], 10, 2);
        }


        $query = new WP_Query($args);

        // Remove the filter after the query has executed to prevent affecting other queries
        if (!empty($search)) {
            remove_filter('posts_search', [$this, 'search_filter_by_title_only'], 10);
        }

        $post_ids = $query->posts;

        $post_counter = 0;
        $update_transient = false;
        foreach ($post_ids as $post_id) {
            if (!isset($post_info[$post_id])) {
                $post_info[$post_id] = $this->fetchPostInfo($post_id);
                $update_transient = true;
            }

            $available_pages[] = $post_info[$post_id];

            // Add errors to the current page, if any
            if (isset($warmup_errors[$post_id])) {
                $available_pages[count($available_pages) - 1]['errors'] = $warmup_errors[$post_id];
            }

            $post_counter++;

            // Update the transient every 2000 posts in case the process dies
            if ($post_counter >= 2000 && $update_transient) {
                set_transient('wpc-post-info', $post_info, 3600);
                $post_counter = 0; // Reset counter
            }
        }

        if ($update_transient) {
            set_transient('wpc-post-info', $post_info, 3600);
        }

        return $available_pages;
    }

    private function fetchPostInfo($post_id, $suffix = '')
    {
        return ['title' => get_the_title($post_id) . $suffix, 'type' => get_post_type($post_id), 'id' => $post_id, 'link' => get_permalink($post_id)];
    }

    public function search_filter_by_title_only($search, $wp_query)
    {
        global $wpdb;
        if (!empty($search) && !empty($wp_query->query_vars['search_terms'])) {
            $q = $wp_query->query_vars;
            $n = !empty($q['exact']) ? '' : '%';
            $search = [];
            $searchand = '';

            foreach ((array)$q['search_terms'] as $term) {
                $term = esc_sql($wpdb->esc_like($term));
                $search[] = "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
                $searchand = ' AND ';
            }
            if (!is_array($q['search_terms']) || count($q['search_terms']) > 1) {
                $search = ' AND (' . implode('', $search) . ')';
            } else {
                $search = ' AND ' . $search[0];
            }
        }
        return $search;
    }

    public function getOptimizationsStatus($post_type = ['page', 'post'], $page = 1, $offset = 0, $limit = 10, $search
    = '',                                  $id = 'false')
    {

        $runningOther = '0';

        if ($id != 'false') {
            //get a single page
            $pages[] = $this->fetchPostInfo($id);

        } else {
            $pages = $this->getPages($post_type, $page, $offset, $limit, $search);
        }
        $url_key_class = new wps_ic_url_key();
        $wpc_excludes = get_option('wpc-excludes', []);
        $settings = get_option(WPS_IC_SETTINGS);

        //local addition
        $local = get_option('wpc-connectivity-status');
        //end local addition

        foreach ($pages as &$page) {
            if ($page['id'] == 'home') {
                $url = home_url();
            } else {
                $url = get_permalink($page['id']);
            }
            $urlKey = $url_key_class->setup($url);
            $cachePath = WPS_IC_CACHE . $urlKey . '/';
            $critPath = WPS_IC_CRITICAL . $urlKey . '/critical_desktop.css';
            $page_excludes = isset($wpc_excludes['page_excludes'][$page['id']]) ? $wpc_excludes['page_excludes'][$page['id']] : [];
            $cacheActive = (!empty($settings['cache']['advanced']) && $settings['cache']['advanced'] == '1' && !isset($page_excludes['advanced_cache'])) || (isset($page_excludes['advanced_cache']) && $page_excludes['advanced_cache'] == '1');

            $cacheGenerated = '0';
            $critGenerated = '1';
            if ($cacheActive) {
                if (function_exists('gzencode')) {
                    if (file_exists($cachePath . 'index.html' . '_gzip') && filesize($cachePath . 'index.html' . '_gzip') > 0) {
                        $cacheGenerated = '1';
                    }
                } else {
                    if (file_exists($cachePath . 'index.html') && filesize($cachePath . 'index.html') > 0) {
                        $cacheGenerated = '1';
                    }
                }
            }

            $doNotCache = false;
            if (!empty($page['errors']['notice'])) {
                $doNotCache = in_array('DONOTCACHEPAGE', $page['errors']['notice']);
            }

            //local addition
            if (!empty($local) && $local == 'failed' && ($cacheGenerated == '1' || $doNotCache)) {
                $preloaded = '1';
            } else {
                $preloaded = '1';

                //check critical
                if ((isset($page_excludes['critical_css']) && $page_excludes['critical_css'] == '0')) {
                    // Excluded from Smart Optimizations
                } else if (!empty($settings['critical']['css']) && $settings['critical']['css'] == '1') {
                    if (!file_exists($critPath)) {
                        $critGenerated = '0';
                        //$preloaded = '0';
                    }
                }

                if (($cacheGenerated == '0' && !$doNotCache) && $cacheActive) {
                    $preloaded = '0';
                }
            }

            $exclude_array = $page_excludes;

            $tests = get_option(WPS_IC_TESTS);

            if (!empty($tests[$urlKey])) {
                $test = $tests[$urlKey];
            } else {
                $test = 'false';
            }

            $running = '0';
            $runningBulk_t = get_transient('wpc-page-optimizations-status');
            if (empty($runningBulk_t)) {
                $runningOther = '0';
            } else if ($runningBulk_t['id'] == $page['id']) {
                $running = '1';
            } else {
                $runningOther = '1';
            }

            global $wpdb;
            $transient_prefix = 'wpc_test_';
            $sql = $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_' . $transient_prefix) . '%');
            $transient_keys = $wpdb->get_col($sql);
            foreach ($transient_keys as $transient_key) {
                $key = str_replace('_transient_', '', $transient_key);
                $value = get_transient($key);
            }


            if (!empty($transient_keys)) {
                if ($key === 'wpc_test_' . $page['id']) {
                    $running = '1';
                } else {
                    $runningOther = '1';
                }
            }


            $page = array_merge($page, ['cacheGenerated' => $cacheGenerated, 'critGenerated' => $critGenerated, 'preloaded' => $preloaded, 'test' => $test, 'running' => $running, 'runningOther' => $runningOther,], $exclude_array);
        }

        return $pages;
    }

    public function getPagesJSON()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');

        if (!empty($_GET['apikey'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
        } else {
            echo json_encode('no-apikey');
        }

        if (!empty($apikey) && get_option(WPS_IC_OPTIONS)['api_key'] == $apikey) {

            $pages = $this->getPagesToOptimize();

            if (empty($pages)) {
                echo json_encode('no-pages');
            }

            $page_links = [];
            foreach ($pages['pages'] as $page) {
                $generateCrit = 'false';
                if ($page['critGenerated'] == '0') {
                    $generateCrit = 'true';
                }
                $page_links[$page['id']] = ['url' => $page['link'], 'critical' => $generateCrit, 'test' => 'false'];

                if (!empty($page['home'])) {
                    $page_links[$page['id']]['home'] = 'true';
                }
            }

            echo json_encode(['pages' => $page_links]);
            die();
        }

        echo json_encode('no-apikey');
    }

    public function getPagesToOptimize()
    {
        $optimize = get_option('wpc-warmup-selector');
        if ($optimize === false) {
            $optimize = ['page', 'post'];
            update_option('wpc-warmup-selector', $optimize);
        } elseif ($optimize === 'do-not-optimize') {
            return [];
        }
        $pages = $this->getPages($optimize, 1, 0, -1);
        $wpc_excludes = get_option('wpc-excludes');
        $url_key_class = new wps_ic_url_key();
        $settings = get_option(WPS_IC_SETTINGS);

        //local addition
        $local = get_option('wpc-connectivity-status');
        //end local addition
        $return = [];
        $return['total'] = count($pages);
        foreach ($pages as $key => $page) {
            $url = $page['link'];
            $urlKey = $url_key_class->setup($url);
            $cachePath = WPS_IC_CACHE . $urlKey . '/';
            $critPath = WPS_IC_CRITICAL . $urlKey . '/critical_desktop.css';
            $page_excludes = isset($wpc_excludes['page_excludes'][$page['id']]) ? $wpc_excludes['page_excludes'][$page['id']] : [];
            $cacheActive = (!empty($settings['cache']['advanced']) && $settings['cache']['advanced'] == '1' && !isset($page_excludes['advanced_cache'])) || (isset($page_excludes['advanced_cache']) && $page_excludes['advanced_cache'] == '1');

            if (!empty($page['errors'])) {
                $hasErrorCode = false;
                foreach ($page['errors'] as $errorCode) {
                    if (is_numeric($errorCode) && (int)$errorCode >= 300 && (int)$errorCode <= 600) {
                        $hasErrorCode = true;
                        break;
                    }
                }

                if ($hasErrorCode) {
                    $return['total'] = $return['total'] - 1;
                    unset($pages[$key]);
                    continue;
                }
            }

            $cacheGenerated = '0';
            $critGenerated = '1';
            if ($cacheActive) {
                if (function_exists('gzencode')) {
                    if (file_exists($cachePath . 'index.html' . '_gzip') && filesize($cachePath . 'index.html' . '_gzip') > 0) {
                        $cacheGenerated = '1';
                    }
                } else {
                    if (file_exists($cachePath . 'index.html') && filesize($cachePath . 'index.html') > 0) {
                        $cacheGenerated = '1';
                    }
                }
            }

            $doNotCache = false;
            if (!empty($page['errors']['notice'])) {
                $doNotCache = in_array('DONOTCACHEPAGE', $page['errors']['notice']);
            }

            //local addition
            if (!empty($local) && $local == 'failed' && ($cacheGenerated == '1' || $doNotCache)) {
                $preloaded = '1';
            } else {
                $preloaded = '1';

                //check critical
                $criticalActive = !(isset($page_excludes['critical_css']) && $page_excludes['critical_css'] == '0') &&
                    ((isset($settings['critical']['css']) && $settings['critical']['css'] == '1') ||
                        (isset($page_excludes['critical_css']) && $page_excludes['critical_css'] == '1'));

                if ($criticalActive && !file_exists($critPath)) {
                    $critGenerated = '0';
                    //$preloaded     = '0';
                }


                if (($cacheGenerated == '0' && !$doNotCache) && $cacheActive) {
                    $preloaded = '0';
                }
            }


            $pages[$key]['critGenerated'] = $critGenerated;

            if (!empty($page['home'])) {
                $pages[$key]['home'] = true;
            }


            if ($preloaded == '1') {
                unset($pages[$key]);
            }
        }

        $return['unoptimized'] = count($pages);
        $return['pages'] = $pages;


        return $return;
    }

    public function downloadDesktopCrit()
    {

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');

        if (!empty($_GET['apikey'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
        } else {
            echo json_encode('no-apikey');
            die();
        }

        if (!empty($_GET['id'])) {
            $id = sanitize_text_field($_GET['id']);
        } else {
            echo json_encode('no-id');
            die();
        }

        if (!empty($_GET['desktopCritUrl'])) {
            $desktopCritUrl = esc_url($_GET['desktopCritUrl']);
        } else {
            echo json_encode('no-url');
            die();
        }

        $options = get_option(WPS_IC_OPTIONS);
        $enteredApiKey = $options['api_key'];
        if (!empty($enteredApiKey) && $enteredApiKey == $apikey) {

            if ($id == 'home') {
                $url = home_url();
            } else {
                $url = get_permalink($id);
            }

            $criticalCSS = new wps_criticalCss($url);
            $criticalCSS->saveCriticalWarmup($id, $url, $desktopCritUrl, 'desktop');

            echo json_encode(['done']);
            die();
        } else {
            echo json_encode('wrong-apikey');
        }

    }

    public function downloadMobileCrit()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');

        if (!empty($_GET['apikey'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
        } else {
            echo json_encode('no-apikey');
            die();
        }

        if (!empty($_GET['id'])) {
            $id = sanitize_text_field($_GET['id']);
        } else {
            echo json_encode('no-id');
            die();
        }

        if (!empty($_GET['mobileCritUrl'])) {
            $desktopCritUrl = esc_url($_GET['mobileCritUrl']);
        } else {
            echo json_encode('no-url');
            die();
        }

        $options = get_option(WPS_IC_OPTIONS);
        $enteredApiKey = $options['api_key'];
        if (!empty($enteredApiKey) && $enteredApiKey == $apikey) {

            if ($id == 'home') {
                $url = home_url();
            } else {
                $url = get_permalink($id);
            }

            $criticalCSS = new wps_criticalCss($url);
            $criticalCSS->saveCriticalWarmup($id, $url, $desktopCritUrl, 'mobile');

            echo json_encode(['done']);
            die();
        } else {
            echo json_encode('wrong-apikey');
        }

    }

    public function updateStatus()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');

        if (!empty($_GET['apikey'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
        } else {
            echo json_encode('no-apikey');
            die();
        }

        if (!empty($_GET['id'])) {
            $id = sanitize_text_field($_GET['id']);
        } else {
            echo json_encode('no-id');
            die();
        }


        $options = get_option(WPS_IC_OPTIONS);
        $enteredApiKey = $options['api_key'];
        if (!empty($enteredApiKey) && $enteredApiKey == $apikey) {

            //Check for cache or crit errors/blocking
            $oldStatus = get_transient('wpc-page-optimizations-status');

            if (!empty($oldStatus['id'])) {
                $oldPageStatus = $this->isOptimized($oldStatus['id'], true);
                if ($oldPageStatus['preloaded'] == '0' && empty($oldPageStatus['error_code'])) {
                    if ($oldPageStatus['cacheGenerated'] == 0) {
                        //generate cache locally
                        $localCacheResponse = $this->cacheLocally($oldStatus['id']);
                    }
                    if ($oldPageStatus['critGenerated'] == 0) {
                        //What to do with crit?
                    }
                }
            }


            set_transient('wpc-page-optimizations-status', ['id' => $id, 'status' => 'warmup'], 60 * 5);


            //Initialize the return
            $status = $this->isOptimized($id, true);
            $status = array_merge($status, ['oldPageIsOptimized' => $oldPageStatus, 'oldStatus' => $oldStatus, 'cacheLocal' => $localCacheResponse]);
            echo json_encode($status);
            die();
        } else {
            echo json_encode('wrong-apikey');
        }
    }

    public function isOptimized($id, $fullStatus = false)
    {
        $url_key_class = new wps_ic_url_key();
        $wpc_excludes = get_option('wpc-excludes', []);
        $settings = get_option(WPS_IC_SETTINGS);
        $errors = get_option('wpc-warmup-errors', []);

        //local addition
        $local = get_option('wpc-connectivity-status');
        //end local addition

        if ($id == 'home') {
            $url = home_url();
        } else {
            $url = get_permalink($id);
        }
        $urlKey = $url_key_class->setup($url);
        $cachePath = WPS_IC_CACHE . $urlKey . '/';
        $critPath = WPS_IC_CRITICAL . $urlKey . '/critical_desktop.css';
        $page_excludes = isset($wpc_excludes['page_excludes'][$id]) ? $wpc_excludes['page_excludes'][$id] : [];
        $cacheActive = (!empty($settings['cache']['advanced']) && $settings['cache']['advanced'] == '1' && !isset($page_excludes['advanced_cache'])) || (isset($page_excludes['advanced_cache']) && $page_excludes['advanced_cache'] == '1');

        $cacheGenerated = '1';
        $critGenerated = '1';
        if ($cacheActive) {
            if (function_exists('gzencode')) {
                if (!(file_exists($cachePath . 'index.html' . '_gzip') && filesize($cachePath . 'index.html' . '_gzip') > 0)) {
                    $cacheGenerated = '0';
                }
            } else {
                if (!(file_exists($cachePath . 'index.html') && filesize($cachePath . 'index.html') > 0)) {
                    $cacheGenerated = '0';
                }
            }
        }


        $doNotCache = false;
        if (!empty($page['errors']['notice'])) {
            $doNotCache = in_array('DONOTCACHEPAGE', $page['errors']['notice']);
        }

        $hasErrorCode = '0';
        if (!empty($page['errors'])) {
            foreach ($page['errors'] as $errorCode) {
                if (is_numeric($errorCode) && (int)$errorCode >= 300 && (int)$errorCode <= 600) {
                    $hasErrorCode = '1';
                }
            }
        }

        //local addition
        if (!empty($local) && $local == 'failed' && ($cacheGenerated == '1' || $doNotCache || !$cacheActive)) {
            $preloaded = '1';
        } else {
            $preloaded = '1';


            //check critical
            if (isset($page_excludes['critical_css']) && $page_excludes['critical_css'] == '0') {
                // Exclude from Smart Optimizations
            } else if (!empty($settings['critical']['css']) && $settings['critical']['css'] == '1') {
                if (!file_exists($critPath)) {
                    $critGenerated = '0';
                    $preloaded = '0';
                }
            }

            if (($cacheGenerated == '0' && !$doNotCache) && $cacheActive) {
                $preloaded = '0';
            }

        }

        if (($cacheGenerated == '0' && !$doNotCache) && $cacheActive) {
            $preloaded = '0';
        }

        if ($fullStatus) {
            return ['critGenerated' => $critGenerated, 'preloaded' => $preloaded, 'error_code' => $hasErrorCode, 'cacheGenerated' => $cacheGenerated, 'doNotCache' => $doNotCache];
        }

        return $preloaded;
    }

    public function cacheLocally($id)
    {
        if ($id == 'home') {
            $url = home_url();
        } else {
            $url = get_permalink($id);
        }

        $args = [
            'redirection' => 0,
        ];
        $get = wp_remote_get($url, $args);

        if (is_wp_error($get)) {
            return $get->get_error_message();
        } else {
            if (wp_remote_retrieve_response_code($get) == 200) {
                $body = wp_remote_retrieve_body($get);

                if (!empty($body) && strlen($body) > 100) {
                    $url_key_class = new wps_ic_url_key();
                    $urlKey = $url_key_class->setup($url);
                    $this->saveCacheLocal($urlKey, $body);
                }
            } else if (wp_remote_retrieve_response_code($get) >= 300 && wp_remote_retrieve_response_code($get) < 400) {
                if (substr($url, -1) == '/') {
                    //if slash remove it
                    $url = rtrim($url, '/');
                } else {
                    //if no slash add it
                    $url .= '/';
                }

                //try again with/without the slash
                $get = wp_remote_get($url, $args);
                if (is_wp_error($get)) {
                    return $get->get_error_message();
                } else {
                    if (wp_remote_retrieve_response_code($get) == 200) {
                        $body = wp_remote_retrieve_body($get);

                        if (!empty($body) && strlen($body) > 100) {
                            $url_key_class = new wps_ic_url_key();
                            $urlKey = $url_key_class->setup($url);
                            $this->saveCacheLocal($urlKey, $body);
                        }
                    } else {
                        $this->addError($id, wp_remote_retrieve_response_code($get), 'skip');
                        return wp_remote_retrieve_response_code($get);
                    }
                }
            }
        }
        return 'did local cache';
    }

    public function saveCacheLocal($urlKey, $body)
    {
        if (!file_exists(WPS_IC_CACHE)) {
            mkdir(rtrim(WPS_IC_CACHE, '/'));
        }

        $cachePath = WPS_IC_CACHE . $urlKey . '/';

        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            global $post;
            if (!empty($post->ID)) {
                $this->addError($post->ID, 'DONOTCACHEPAGE');
            }
            return 'donotcache';
        }

		    if (empty($this->options['cache']['ignore-server-control']) ||  $this->options['cache']['ignore-server-control'] == '0') {
			    $cacheControl = strtolower( $_SERVER['HTTP_CACHE_CONTROL'] );
			    if ( strpos( $cacheControl, 'no-cache' ) !== false ||
			         strpos( $cacheControl, 'no-store' ) !== false ||
			         strpos( $cacheControl, 'private' ) !== false ) {
				    return 'donotcache';
			    }
		    }

        $excludes = get_option('wpc-excludes');
        $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        if (!empty($excludes) && !empty($excludes['cache'])) {
            if (in_array($url, $excludes['cache'])) {
                return 'excluded';
            }
        }

        if (!file_exists($cachePath)) {
            mkdir(rtrim($cachePath, '/'), 0777, true);
        }

//        $fp = fopen($cachePath . 'index.html', 'w+');
//        fwrite($fp, $body);
//        fclose($fp);
//
//        $stats = new wps_ic_stats();
//        $stats->saveWarmupStats($body);

        if (function_exists('gzencode')) {
            $this->saveGzCacheLocal($cachePath, $body);
        }

        return 'saved';

    }

    public function addError($id, $error, $type = 'notice')
    {
        $option_key = 'wpc-warmup-errors';
        $new_value = $error;

        $current_errors = get_option($option_key, []);

        if ($type == 'skip') {
            $current_errors[$id][$type] = $error;
        } else {
            if (isset($current_errors[$id][$type])) {
                if (!in_array($new_value, $current_errors[$id][$type])) {
                    $current_errors[$id][$type][] = $new_value;
                }
            } else {
                $current_errors[$id][$type] = [];
                $current_errors[$id][$type][] = $new_value;
            }
        }

        update_option($option_key, $current_errors);
    }

    public function saveGzCacheLocal($cachePath, $body)
    {
        $fp = fopen($cachePath . 'index.html' . '_gzip', 'w+');
        fwrite($fp, gzencode($body, 8));
        fclose($fp);
    }

    public function stopOptimizations()
    {
        $call = wp_remote_post(self::$apiUrl, ['method' => 'POST', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'body' => ['action' => 'stopOptimization', 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']], 'timeout' => 10]);


        if (is_wp_error($call)) {
            wp_send_json_error($call->get_error_message());
        }

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response_body = wp_remote_retrieve_body($call);
            $response_body = json_decode($response_body, true);
            if ($response_body['success'] == 'true') {
                delete_transient('wpc-page-optimizations-status');
                wp_send_json_success($call);
            } else {
                wp_send_json_error($call);
            }
        } else {
            wp_send_json_error(print_r($call, true));
        }
    }

    public function deliverError()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');

        if (!empty($_GET['apikey'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
        } else {
            echo json_encode('no-apikey');
            die();
        }

        if (!empty($_GET['id'])) {
            $id = sanitize_text_field($_GET['id']);
        } else {
            echo json_encode('no-id');
            die();
        }

        if (!empty($_GET['errorCode'])) {
            $errorCode = sanitize_text_field($_GET['errorCode']);
        } else {
            echo json_encode('no-error');
            die();
        }


        $options = get_option(WPS_IC_OPTIONS);
        $enteredApiKey = $options['api_key'];
        if (!empty($enteredApiKey) && $enteredApiKey == $apikey) {

            $this->addError($id, $errorCode, 'skip');

            echo json_encode(['done']);
            die();
        } else {
            echo json_encode('wrong-apikey');
        }
    }

    public function optimizeSingleQuick($id, $test = true, $dash = false)
    {
        if ($id == 'home') {
            $url = home_url();
            $is_home = 'true';
        } else {
            $url = get_permalink($id);

            $normalized_url = preg_replace('#^https?://(www\.)?#', '', rtrim($url, '/'));
            $normalized_home_url = preg_replace('#^https?://(www\.)?#', '', rtrim(home_url(), '/'));

            if ($normalized_url == $normalized_home_url) {
                $is_home = 'true';
            } else {
                $is_home = 'false';
            }
        }

        $action = 'runQuickTest';

        $call = wp_remote_post(self::$apiUrl, ['method' => 'POST', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'body' => ['action' => $action, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']], 'timeout' => 30]);


        var_dump($call);
        die();

        if (is_wp_error($call)) {
            wp_send_json_error($call->get_error_message());
        }

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response_body = wp_remote_retrieve_body($call);
            $response_body = json_decode($response_body, true);
            if ($response_body['success'] == 'true') {
                //added
                set_transient('wpc_initial_test', 'running', 5 * 60);
            } else {
                wp_send_json_error(print_r($response_body['data'], true));
            }
        } else {
            wp_send_json_error(print_r($call, true));
        }

        wp_send_json_success(true);
    }

    public function optimizeSingle($id, $test = true, $dash = false)
    {

        if ($id == 'home') {
            $url = home_url();
            $is_home = 'true';
        } else {
            $url = get_permalink($id);

            $normalized_url = preg_replace('#^https?://(www\.)?#', '', rtrim($url, '/'));
            $normalized_home_url = preg_replace('#^https?://(www\.)?#', '', rtrim(home_url(), '/'));

            if ($normalized_url == $normalized_home_url) {
                $is_home = 'true';
            } else {
                $is_home = 'false';
            }
        }

        $page_links[$id] = ['url' => $url, 'test' => $test, 'home' => $is_home, 'dash' => $dash];

        $action = 'createQueue';
        if ($id == 'home') {
            $action = 'createQueue';
        }

        $warmup = new wps_ic_preload_warmup();
        if ($dash) {
            $warmup->writeLog('Starting warmup.');
            $call = wp_remote_post(self::$standaloneWarmup, ['method' => 'POST', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'body' => ['action' => $action, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'id' => $id, 'critical' => 'true', 'test' => 'true'], 'timeout' => 120]);
        } else {

            $errors = get_option('wpc-warmup-errors', []);

            //delete previous errors for the page
            if (isset($errors[$id])) {
                unset($errors[$id]);
                update_option('wpc-warmup-errors', $errors);
            }
            $call = wp_remote_post(self::$apiUrl, ['method' => 'POST', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'body' => ['action' => $action, 'pages' => json_encode($page_links), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']], 'timeout' => 30]);

        }

        if (is_wp_error($call)) {
            $warmup->writeLog('Got ' . $call->get_error_message());
            wp_send_json_error($call->get_error_message());
        }

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response_body = wp_remote_retrieve_body($call);
            $warmup->writeLog('Got ' . $response_body);
            $response_body = json_decode($response_body, true);
            if ($response_body['success'] == 'true') {
                $transient = set_transient('wpc-page-optimizations-status', ['id' => $id, 'status' => 'warmup'], 60 * 5);

                //added
                set_transient('wpc_test_' . $id, 'started', 60);
                set_transient('wpc_initial_test', 'running', 5 * 60);
                //

            } else {
                //wp_send_json_error(print_r($response_body['data'], true));
            }

            if ($dash && !empty($response_body['testID'])) {
                $warmupLog = get_option(WPC_WARMUP_LOG_SETTING, []);
                $warmupLog[$response_body['testID']] = ['started' => date('Y-m-d H:i:s')];
                update_option(WPC_WARMUP_LOG_SETTING, $warmupLog);
            }
        } else {
            $warmup->writeLog('Got ' . wp_remote_retrieve_response_code($call) . ': ' . wp_remote_retrieve_body($call));
            wp_send_json_error(print_r($call, true));
        }

        wp_send_json_success($transient);
    }

    public function writeLog($message)
    {
        fwrite($this->logFile, "[" . date('d.m.Y H:i:s') . "] " . $message . "\r\n");
    }

    public function resetTest($id, $retest = false, $return = true)
    {
        $call = wp_remote_post(self::$apiUrl, ['timeout' => 5, 'blocking' => true, 'body' => ['id' => 'home', 'url' => home_url(), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'action' => 'resetTest'], 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        #var_dump($call);
    }

    public function doTestRemote($id, $retest = false, $return = true)
    {
        if ($id == 'home') {
            $url = home_url();
        } else {
            $url = get_permalink($id);
        }

        $url_key_class = new wps_ic_url_key();
        $urlKey = $url_key_class->setup($url);
        $urlKey = sanitize_title($urlKey);

        set_transient('wpc_test_' . $id, 'started', 60);
        set_transient('wpc_initial_test', 'running', 5 * 60);

        $results = get_option(WPS_IC_TESTS, []);
        if ($retest === false) {
            if (!empty($results[$urlKey])) {
                wp_send_json_success($results[$urlKey]);
            }
        }

        $call = wp_remote_post(self::$apiUrl, ['timeout' => 5, 'blocking' => true, 'body' => ['id' => $id, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'action' => 'doTestRemote'], 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        wp_send_json_success('waiting');
    }

    public function doTest($id, $retest = false, $return = true)
    {
        if ($id == 'home') {
            $url = home_url();
        } else {
            $url = get_permalink($id);
        }

        $url_key_class = new wps_ic_url_key();
        $urlKey = $url_key_class->setup($url);

        set_transient('wpc_test_' . $id, 'started', 60);

        $results = get_option(WPS_IC_TESTS, []);
        if ($retest === false) {
            if (!empty($results[$urlKey])) {
                wp_send_json_success($results[$urlKey]);
            }
        } else {
            $results[$urlKey] = [];
            update_option(WPS_IC_TESTS, $results);
        }

        $call = wp_remote_post(self::$apiUrl, ['timeout' => 100, 'blocking' => true, 'body' => ['id' => $id, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'action' => 'doTest'], 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        delete_transient('wpc_test_' . $id);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $decodedBody = json_decode($body, true);

            if (!empty($decodedBody['success']) && $decodedBody['success'] == 'true') {
                // Update the option with new results
                $results = get_option(WPS_IC_TESTS, []);
                $results[$urlKey] = $decodedBody['data'];
                update_option(WPS_IC_TESTS, $results);
                if ($return) {
                    wp_send_json_success($decodedBody['data']);
                } else {
                    return $urlKey;
                }
            }
        }

        if ($return) {
            if (is_wp_error($call)) {
                $call = $call->get_error_messages();
            }
            wp_send_json_error([self::$apiUrl, ['id' => $id, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'action' => 'doTest']], print_r($call, true));
        }

        return false;
    }

    public function doTestLCP($id, $retest = false)
    {
        if ($id == 'home' || empty($id)) {
            $url = home_url();
        } else {
            $url = get_permalink($id);
        }
        $url_key_class = new wps_ic_url_key();
        $urlKey = $url_key_class->setup($url);

        set_transient('wpc_test_' . $id, 'started', 60);

        $results = get_option(WPS_IC_TESTS, []);
        if ($retest === false) {
            if (!empty($results[$urlKey])) {
                wp_send_json_success($results[$urlKey]);
            }
        } else {
            $results[$urlKey] = [];
            update_option(WPS_IC_TESTS, $results);
        }

        $call = wp_remote_post(self::$apiUrl, ['timeout' => 100, 'blocking' => true, 'body' => ['id' => $id, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'action' => 'doTestLCP'], 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        delete_transient('wpc_test_' . $id);
        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $decodedBody = json_decode($body, true);
            if (!empty($decodedBody['success']) && $decodedBody['success'] == 'true') {
                // Update the option with new results
                $results = get_option(WPS_IC_TESTS, []);
                $results[$urlKey] = $decodedBody['data'];

                if (!empty($results[$urlKey]['preloads'])) {
                    $cache = new wps_ic_cache_integrations();
                    $cache::purgeAll($urlKey);

                    // Process desktop preloads
                    $preloads = get_option('wps_ic_preloads', []);
                    unset($preloads['lcp']);

                    $desktopPreload = stripslashes($results[$urlKey]['preloads']['desktop']);

                    #$preloads = array_map('stripslashes', $preloads);
                    #if (!in_array($desktopPreload, $preloads)) {
                    if (!empty($desktopPreload) && $desktopPreload !== 'none') {
                        $preloads['lcp'] = $desktopPreload;
                    }
                    #}

                    #$preloadsArray = array_map('trim', $preloads);
                    // Apply trim using a foreach loop to avoid losing the 'lcp' key
                    foreach ($preloads as $key => $value) {
                        if (empty($value)) unset($preloads[$key]);
                        $preloads[$key] = trim($value); // Trimming values while keeping associative keys
                    }
                    update_option('wps_ic_preloads', $preloads);

                    // Process mobile preloads
                    $preloadsMobile = get_option('wps_ic_preloadsMobile', []);
                    unset($preloadsMobile['lcp']);

                    $mobilePreload = stripslashes($results[$urlKey]['preloads']['mobile']);

                    $preloadsMobile = array_map('stripslashes', $preloadsMobile);
                    #if (!in_array($mobilePreload, $preloadsMobile)) {
                    if (!empty($mobilePreload) && $mobilePreload !== 'none') {
                        $preloadsMobile['lcp'] = $mobilePreload;
                    }
                    #}

                    #$preloadsArray = array_map('trim', $preloadsMobile);
                    // Apply trim using a foreach loop to avoid losing the 'lcp' key
                    foreach ($preloadsMobile as $key => $value) {
                        if (empty($value)) unset($preloadsMobile[$key]);
                        $preloadsMobile[$key] = trim($value); // Trimming values while keeping associative keys
                    }
                    update_option('wps_ic_preloadsMobile', $preloadsMobile);

                }

                update_option(WPS_IC_TESTS, $results);
                $this->localCacheWarmup($url);

                sleep(10);

                $status = get_transient('wpc-page-optimizations-status');
                if ($status !== false && $status['id'] !== $id) {
                    //it is bulk and we are still doing it
                } else {
                    delete_transient('wpc-page-optimizations-status');
                }

                wp_send_json_success($decodedBody['data']);
            }
        }

        wp_send_json_error([self::$apiUrl, ['id' => $id, 'url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'], 'action' => 'doTestLCP']], $call);
    }

    public function localCacheWarmup($link)
    {
        $args = [
            'timeout' => 0.01,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/605.1.15',
            ],
        ];

        wp_remote_get($link, $args);

        $args = [
            'timeout' => 0.01,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            ],
        ];

        wp_remote_get($link, $args);
    }

    public function get_optimization_status()
    {

        $status = get_transient('wpc-page-optimizations-status');
        return $status;
        $connectivity = get_transient('wpc-connectivity-status');
        if ($status !== false) {
            $status['pageTitle'] = ($status['id'] === 'home') ? 'Home Page' : get_the_title($status['id']);
            if (!empty($connectivity) && $connectivity == 'failed') {
                $status['mode'] = 'local';
            }
        }
        return $status;

    }

    public function connectivityTest()
    {
        $url = home_url();
        $api_key = get_option(WPS_IC_OPTIONS)['api_key'];
        $results = [];

        // Function to process the response
        $processResponse = function ($response) {
            if (is_wp_error($response)) {
                return ['success' => false, 'error' => $response->get_error_message()];
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if ($code != 200) {
                return ['success' => false, 'error' => "HTTP status code: $code"];
            }

            return ['success' => true, 'response' => json_decode($body, true)];
        };

        // Test POST request without params
        try {
            $postNoParamsResponse = wp_remote_post(self::$apiUrl);
            $results['post_no_params'] = $processResponse($postNoParamsResponse);
        } catch (Exception $e) {
            $results['post_no_params'] = ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }

        // Test POST request with params
        try {
            $postWithParamsResponse = wp_remote_post(self::$apiUrl, ['body' => ['apikey' => $api_key, 'url' => $url]]);
            $results['post_with_params'] = $processResponse($postWithParamsResponse);
        } catch (Exception $e) {
            $results['post_with_params'] = ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }

        // Test GET request without params
        try {
            $getNoParamsResponse = wp_remote_get(self::$apiUrl);
            $results['get_no_params'] = $processResponse($getNoParamsResponse);
        } catch (Exception $e) {
            $results['get_no_params'] = ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }

        // Test GET request with params
        try {
            $getWithParamsResponse = wp_remote_get(self::$apiUrl . '?apikey=' . urlencode($api_key) . '&url=' . urlencode($url));
            $results['get_with_params'] = $processResponse($getWithParamsResponse);
        } catch (Exception $e) {
            $results['get_with_params'] = ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }

        // Test GET request with params in headers
        try {
            $getWithHeadersResponse = wp_remote_get(self::$apiUrl, ['headers' => ['apikey' => $api_key, 'url' => $url]]);
            $results['get_with_headers'] = $processResponse($getWithHeadersResponse);
        } catch (Exception $e) {
            $results['get_with_headers'] = ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }

        // Determine the best working method
        $methodToUse = null;
        if ($results['get_with_params']['success']) {
            $methodToUse = 'get_with_params';
        } elseif ($results['post_with_params']['success']) {
            $methodToUse = 'post_with_params';
        } elseif ($results['get_with_headers']['success']) {
            $methodToUse = 'get_with_headers';
        }

        // If a method worked, send the connectivity test request
        if ($methodToUse) {
            $actionParams = ['apikey' => $api_key, 'url' => $url, 'action' => 'connectivityTest'];

            switch ($methodToUse) {
                case 'get_with_params':
                    $timeout = 30;
                    $args = [
                        'timeout' => $timeout
                    ];
                    $finalTestResponse = wp_remote_get(self::$apiUrl . '?' . http_build_query($actionParams), $args);
                    break;
                case 'post_with_params':
                    $timeout = 30;
                    $args = [
                        'timeout' => $timeout,
                        'body' => $actionParams
                    ];
                    $finalTestResponse = wp_remote_post(self::$apiUrl, $args);
                    break;
                case 'get_with_headers':
                    $timeout = 30;
                    $args = [
                        'timeout' => $timeout,
                        'headers' => $actionParams
                    ];
                    $finalTestResponse = wp_remote_get(self::$apiUrl, $args);
                    break;
            }

            $results['final_test'] = $processResponse($finalTestResponse);
        }

        // Save the result to a transient
        set_transient('api_test_results', $results, 60 * 5); // 5 minutes expiration

        return $results;
    }

    public function setupCronPreload()
    {
        if (!empty(get_option(WPS_IC_OPTIONS)['api_key'])) {
            add_action('init', [$this, 'scheduleCronWarmup']);
            add_action('runCronPreload', [$this, 'startOptimizationsCron']);
        }
    }

    public function scheduleCronWarmup()
    {
        $timestamp = wp_next_scheduled('runCronPreload');
        if ($timestamp) {
        } else {
            wp_schedule_event(time(), 'twicedaily', 'runCronPreload');
        }

    }

    public function startOptimizationsCron()
    {

        $this->startOptimizations();

    }

    public function startOptimizations()
    {

        /*
         * smart is always enabled?
          $smartEnabled = $this-> isFeatureEnabled('smart');
          if (!$smartEnabled) {
              wp_send_json_error('Locked');
          }
        */

        $this->simpleConnectivityTest();
        $connectivity = get_option('wpc-connectivity-status');
        if (!empty($connectivity) && $connectivity == 'failed') {
            //engage local mode
            set_transient('wpc-page-optimizations-status', ['id' => '', 'status' => 'started', 'mode' => 'local'], 60 * 3);
            wp_send_json_success('failed-connectivity');
        } else {
            update_option('wpc-connectivity-status', 'passed');
        }

        $call = wp_remote_post(self::$apiUrl, ['method' => 'POST', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'body' => ['action' => 'getPagesJSON', 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']], 'timeout' => 10]);


        if (is_wp_error($call)) {
            wp_send_json_error($call->get_error_message());
        }

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response_body = wp_remote_retrieve_body($call);
            $response_body = json_decode($response_body, true);
            if ($response_body['success'] == 'true') {
                set_transient('wpc-page-optimizations-status', ['id' => '', 'status' => 'started'], 60 * 3);
                wp_send_json_success($response_body['data']);
            } else {
                wp_send_json_error(print_r($response_body['data'], true));
            }
        } else {
            wp_send_json_error(print_r($call, true));
        }
    }

    public function simpleConnectivityTest()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();
        //only test post with params outbound and get with params inbound, what we are using
        $url = home_url();
        $api_key = get_option(WPS_IC_OPTIONS)['api_key'];

        if (empty($api_key)) return;

        $results = [];

        // Function to process the response
        $processResponse = function ($response) {
            if (is_wp_error($response)) {
                return ['success' => false, 'error' => $response->get_error_message()];
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if ($code != 200) {
                return ['success' => false, 'error' => "HTTP status code: $code"];
            }

            return ['success' => true, 'response' => json_decode($body, true)];
        };

        // Test POST request with params
        try {
            $postWithParamsResponse = wp_remote_post(self::$apiUrl, ['body' => ['apikey' => $api_key, 'url' => $url]]);
            $results['post_with_params'] = $processResponse($postWithParamsResponse);
        } catch (Exception $e) {
            $results['post_with_params'] = ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }


        // Determine the best working method
        $methodToUse = null;
        if ($results['post_with_params']['success']) {
            $methodToUse = 'post_with_params';
        }
        // If a method worked, send the connectivity test request
        if ($methodToUse) {
            $actionParams = ['apikey' => $api_key, 'url' => $url, 'action' => 'simpleConnectivityTest'];

            $timeout = 30;

            $args = [
                'timeout' => $timeout,
                'body' => $actionParams
            ];

            $finalTestResponse = wp_remote_post(self::$apiUrl, $args);
            $results['final_test'] = $processResponse($finalTestResponse);
        }

        $outbound = !empty($results['post_with_params']['response']['data']) && $results['post_with_params']['response']['data'] == 'missing-action';
        $inbound = !empty($results['final_test']['response']['data']['get_with_params']['response']) && (strpos($results['final_test']['response']['data']['get_with_params']['response'], 'passed') !== false);
        if (!$inbound || !$outbound) {
            update_option('wpc-connectivity-status', 'failed');
        } else {
            update_option('wpc-connectivity-status', 'passed');
        }
    }

    public function isRedirected($url)
    {
        $args = ['method' => 'HEAD', 'redirection' => 0, 'timeout' => 5];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // Check if the response code is a 3xx (redirection)
        if ($response_code >= 300 && $response_code < 400) {
            return true;
        }

        return false;
    }

    public function getWarmupLog()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');

        if (!empty($_GET['apikey'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
        } else {
            echo json_encode('no-apikey');
            die();
        }

        $options = get_option(WPS_IC_OPTIONS);
        $enteredApiKey = $options['api_key'];

        if (!empty($apikey) && $enteredApiKey == $apikey) {
            if (file_exists($this->logFilePath)) {
                $logContents = file_get_contents($this->logFilePath);
                echo $logContents;
            } else {
                echo json_encode('Log file does not exist');
            }
            die();
        }

        echo json_encode('wrong-apikey');
        die();
    }

    public function isWarmupFailing()
    {
        //Check for 2 consecutiove fails older than 5 minutes with no successful tests done after
        $warmupFailing = false;
        $warmupLog = get_option(WPC_WARMUP_LOG_SETTING, []);
        $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));

        if (!empty($_GET['test_warmup_fail'])) {
            return true;
        }

        for ($i = 0; $i < count($warmupLog) - 1; $i++) {
            $current = $warmupLog[$i];
            $next = $warmupLog[$i + 1];

            if (!isset($current['ended']) && !isset($next['ended'])
                && $current['started'] < $fiveMinutesAgo
                && $next['started'] < $fiveMinutesAgo) {

                // Check if there are any successful tests after these two
                $hasSuccessAfter = false;
                for ($j = $i + 2; $j < count($warmupLog); $j++) {
                    if (isset($warmupLog[$j]['ended'])) {
                        $hasSuccessAfter = true;
                        break;
                    }
                }

                if (!$hasSuccessAfter) {
                    $warmupFailing = true;
                    break;
                }
            }
        }

        return $warmupFailing;
    }

}