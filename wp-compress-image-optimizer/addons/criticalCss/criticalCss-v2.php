<?php

if (!class_exists('wps_ic_url_key')) {
    include_once WPS_IC_DIR . 'traits/url_key.php';
}

class wps_criticalCss
{

    static public $API_URL = WPS_IC_CRITICAL_API_URL;
    static public $API_ASSETS_URL = WPS_IC_CRITICAL_API_ASSETS_URL;
    public static $url;
    private static $maxRetries = 5;
    public $urlKey;
    public $serverRequest;
    public $url_key_class;

    public function __construct($url = '')
    {
        if (empty($url)) {
            $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        self::$url = $url;

        if (!empty($_GET['debugCritical_replace'])) {
            $url = explode('?', $url);
            $url = $url[0];
        }

        $this->serverRequest = $url;

        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup($url);
        $this->urlKey = ltrim($this->urlKey, '/');
        $this->createDirectory();

    }

    public function createDirectory()
    {
        if (!file_exists(WPS_IC_CRITICAL)) {
            mkdir(WPS_IC_CRITICAL);
        }
    }


    public function criticalRunning()
    {
        $running = get_transient('wpc_critical_ajax_' . md5(self::$url));
        if (empty($running) || !$running) {
            return false;
        } else {
            return true;
        }
    }

    public function generateCriticalCSS($postID = 0)
    {
        global $post;
        $postID = false;

        if ($this->isHomeURL()) {
            $postID = 'home';
        } else if (!empty($post->ID)) {
            $postID = $post->ID;
        } else if (!empty(get_queried_object_id())) {
            $postID = get_queried_object_id();
        }

        if (!empty($postID)) {

            if ($postID === 'home' || !$postID || $postID == 0) {
                $homePage = get_option('page_on_front');
                $blogPage = get_option('page_for_posts');

                if (!$homePage) {
                    $url = site_url();
                } else {
                    $url = get_permalink($homePage);
                }

                $pages[$postID] = urldecode($url);

                if ($blogPage !== 0 && $blogPage !== '0' && $blogPage !== $homePage) {
                    $url = get_permalink($blogPage);
                }

                $pages[$postID] = urldecode($url);
            } else {
                $url = get_permalink($postID);
                $pages[$postID] = urldecode($url);
            }

            $url_key = $this->url_key_class->setup($url);

            if ($this->criticalExists()) {
                // Nothing
            } else {
                $url = rtrim($url, '?');
                $this->initCritical($postID, $url, $url_key, $type = 'meta');
            }
        }
    }

    public function isHomeURL()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');
        $current_url = explode('?', $current_url);
        $current_url = $current_url[0];
        $home_url = rtrim($home_url, '/');
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    public function criticalExists($returnDir = false)
    {
        if (!empty($_GET['debugCritical_replace'])) {
            return [WPS_IC_CRITICAL, $this->urlKey, 'file' => WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css', 'exists' => file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')];
        }

        $return = [];

        $desktopFilePath = WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css';
        $mobileFilePath = WPS_IC_CRITICAL . $this->urlKey . '/critical_mobile.css';

        $desktopFileUrl = WPS_IC_CRITICAL_URL . $this->urlKey . '/critical_desktop.css';
        $mobileFileUrl = WPS_IC_CRITICAL_URL . $this->urlKey . '/critical_mobile.css';

        if (file_exists($desktopFilePath) && filesize($desktopFilePath) > 0) {
            $content = file_get_contents($desktopFilePath);
            $isHtml = preg_match('/<[^>]+>/', $content); // basic HTML tag detection

            if ($isHtml) {
                return false;
            }

            if ($returnDir) {
                $return['desktop'] = $desktopFilePath;
            } else {
                $return['desktop'] = $desktopFileUrl;
            }
        }

        if (file_exists($mobileFilePath) && filesize($mobileFilePath) > 0) {
            $content = file_get_contents($mobileFilePath);
            $isHtml = preg_match('/<[^>]+>/', $content); // basic HTML tag detection

            if ($isHtml) {
                return false;
            }

            if ($returnDir) {
                $return['mobile'] = $mobileFilePath;
            } else {
                $return['mobile'] = $mobileFileUrl;
            }
        }

        if (empty($return['desktop']) || empty($return['mobile'])) {
            return false;
        }

        return $return;
    }

    public function initCritical($postID, $url, $url_key, $type, $timeout = 120)
    {
        $requests = new wps_ic_requests();

        $url = trim($url);
        if (empty($url) || empty(get_option(WPS_IC_OPTIONS)['api_key'])) {
            return false;
        }

        // Use md5() or sha1() for a predictable short hash.
        $url_key = md5($url);

        $transient_name = 'wpc_critical_key_' . $url_key; // Safe, short, unique.
        $critTransient = get_transient($transient_name);

        if (!empty($critTransient) && empty($_GET['forceCritical'])) {
            // Die, already running!
            return true;
        }

        // Make transient expire after 30 mins
        set_transient($transient_name, true, 60 * 30);

        $args = ['url' => $url . '?criticalCombine=true&testCompliant=true', 'version' => '2.3', 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
        #$args = ['url' => $url.'?disableWPC=true', 'async' => 'false', 'dbg' => 'false', 'hash' => time().mt_rand(100,9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];

        $call = $requests->POST(self::$API_URL, $args, ['timeout' => 0.1, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json')]);

    }

    public function sendCriticalUrl($realUrl = '', $postID = 0, $timeout = 120)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        ob_start();
        $type = 'meta';

        if (empty($realUrl)) {
            if ($postID === 'home' || !$postID || $postID == 0) {

                $homePage = get_option('page_on_front');
                $blogPage = get_option('page_for_posts');

                if (!$homePage) {
                    $url = site_url();
                } else {
                    $url = get_permalink($homePage);
                }

                $pages[$postID] = urldecode($url);

                if ($blogPage !== 0 && $blogPage !== '0' && $blogPage !== $homePage) {
                    $url = get_permalink($blogPage);
                }

                $pages[$postID] = urldecode($url);
            } else {
                $url = get_permalink($postID);
                $pages[$postID] = urldecode($url);
            }

            $url_key = $this->url_key_class->setup($url);
        } else {
            $pages[$postID] = urldecode($realUrl);
            $url_key = $this->url_key_class->setup($realUrl);
            $url = $realUrl;
        }

        if ($this->criticalExists()) {
            wp_send_json_success('Exists');
        }

        $url = rtrim($url, '?');
        $this->initCritical($postID, $url, $url_key, $type, $pages);
    }


    public function saveBenchmark($urlKey, $uuid)
    {

        $this->debugPageSpeed('start benchmark inside');

        $parsedData = [];
        $jobStatus = [];
        $critical_path = WPS_IC_CRITICAL . $urlKey . '/';
        $cache = new wps_ic_cache_integrations();

        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $stats = get_option(WPS_IC_TESTS);
        $attempt = 0;

        $this->debugPageSpeed(WPS_IC_PAGESPEED_RESULTS_HOME . $uuid);

        do {
            $results = wp_remote_get(WPS_IC_PAGESPEED_RESULTS_HOME . $uuid, [
                'headers' => ['user-agent' => WPS_IC_API_USERAGENT]
            ]);

            $this->debugPageSpeed(print_r($results,true));

            if (is_wp_error($results)) {
                $jobStatus['benchmark-failed'] = true;
                break;
            }

            $body = wp_remote_retrieve_body($results);
            $data = json_decode($body, true);

            $this->debugPageSpeed('----');
            $this->debugPageSpeed(print_r($data,true));

            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
                $jobStatus['benchmark-failed'] = true;
                break;
            }

            // Parse Desktop
            $parsedData['desktop']['before']['performanceScore'] = $data['desktop']['beforeScore'];
            $parsedData['desktop']['after']['performanceScore'] = $data['desktop']['afterScore'];

            $parsedData['desktop']['before']['pageSize'] = $data['desktop']['beforePageSize'];
            $parsedData['desktop']['after']['pageSize'] = $data['desktop']['afterPageSize'];

            $parsedData['desktop']['before']['requests'] = $data['desktop']['beforeRequests'];
            $parsedData['desktop']['after']['requests'] = $data['desktop']['afterRequests'];

            $parsedData['desktop']['before']['ttfb'] = $data['desktop']['beforeTTFB'];
            $parsedData['desktop']['after']['ttfb'] = $data['desktop']['afterTTFB'];

            // Parse Mobile
            $parsedData['mobile']['before']['performanceScore'] = $data['mobile']['beforeScore'];
            $parsedData['mobile']['after']['performanceScore'] = $data['mobile']['afterScore'];

            $parsedData['mobile']['before']['pageSize'] = $data['mobile']['beforePageSize'];
            $parsedData['mobile']['after']['pageSize'] = $data['mobile']['afterPageSize'];

            $parsedData['mobile']['before']['requests'] = $data['mobile']['beforeRequests'];
            $parsedData['mobile']['after']['requests'] = $data['mobile']['afterRequests'];

            $parsedData['mobile']['before']['ttfb'] = $data['mobile']['beforeTTFB'];
            $parsedData['mobile']['after']['ttfb'] = $data['mobile']['afterTTFB'];

            $this->debugPageSpeed(print_r($parsedData,true));

            // Check if parsedData was populated
            if (!empty($parsedData)) {
                $stats['home'] = $parsedData;
                update_option(WPS_IC_TESTS, $stats);
                $jobStatus['benchmark-success'] = true;
                delete_transient('wpc_initial_test');
                break;
            }

            // If parsedData is empty, wait and retry
            if ($attempt === 0) {
                sleep(30);
                $attempt++;
            } else {
                $jobStatus['benchmark-failed'] = true;
                break;
            }

        } while ($attempt <= 3);

        update_option(WPS_IC_LITE_GPS, ['result' => $parsedData, 'failed' => empty($parsedData), 'lastRun' => time()]);
        return $jobStatus;
    }


    public function debugPageSpeed($message)
    {
        if (get_option('wps_ps_debug') == 'true') {
            $log_file = WPS_IC_LOG . 'pagespeed-log-' . date('d-m-Y') . '.txt';
            $time = current_time('mysql');

            if (!touch($log_file)) {
                error_log("Failed to create log file: $log_file");
            }

            $log = file_get_contents($log_file);
            $log .= '[' . $time . '] - ' . $message . "\r\n";
            file_put_contents($log_file, $log);
        }
    }

    public function saveLCP($urlKey, $LCP = array())
    {
        $jobStatus = [];
        $critical_path = WPS_IC_CRITICAL . $urlKey . '/';
        $cache = new wps_ic_cache_integrations();

        if (is_array($LCP)) {
            $json = $LCP;
        } else {
            $json = json_decode($LCP, true);
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        if (!empty($json['server'])) {
            echo $json['server'];
        }

        if (!empty($json['hostname'])) {
            echo $json['hostname'];
        }

        $desktop = wp_remote_get($json['url']['desktop'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);
        $mobile = wp_remote_get($json['url']['mobile'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);

        // If fetching remote files is ERROR stop process
        if (is_wp_error($desktop)) {
            // No Desktop LCP
            $preloadsLcp = get_option('wps_ic_preloads');
            $preloadsLcp['lcp'] = '';
            update_option('wps_ic_preloads', $preloadsLcp);
            $jobStatus['lcp-mobile-fail'] = true;
        } else {
            $body = wp_remote_retrieve_body($desktop);
            $data = json_decode($body, true);
            $lcp = isset($data['lcp']) ? $data['lcp'] : [];
            $preloadsLcp = get_option('wps_ic_preloads');
            $preloadsLcp['lcp'] = $lcp;
            update_option('wps_ic_preloads', $preloadsLcp);
            $jobStatus['lcp-desktop-success'] = true;
        }

        // If fetching remote files is ERROR stop process
        if (is_wp_error($mobile)) {
            // No Mobile LCP
            $preloadsLcp = get_option('wps_ic_preloadsMobile');
            $preloadsLcp['lcp'] = '';
            update_option('wps_ic_preloadsMobile', $preloadsLcp);
            $jobStatus['lcp-mobile-fail'] = true;
        } else {
            $body = wp_remote_retrieve_body($mobile);
            $data = json_decode($body, true);
            $lcp = isset($data['lcp']) ? $data['lcp'] : [];
            $preloadsLcp = get_option('wps_ic_preloadsMobile');
            $preloadsLcp['lcp'] = $lcp;
            update_option('wps_ic_preloadsMobile', $preloadsLcp);
            $jobStatus['lcp-mobile-success'] = true;
        }

        return $jobStatus;
    }

    public function criticalExistsAjax($url = '')
    {

        if (!empty($url)) {
            $this->urlKey = $this->url_key_class->setup($url);
        }

        if (file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')) {
            return WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css';
        } else {
            return false;
        }
    }

    public function sendCriticalUrlGetAssets($url = '', $postID = 0)
    {
        global $post;
        $type = 'post_meta';

        if ($postID === 'home') {
            $url = home_url();
            $type = 'option';
        } elseif (!$postID || $postID == 0) {

            $homePage = get_option('page_on_front');
            $blogPage = get_option('page_for_posts');

            if (!$homePage) {
                $post['post_name'] = 'Home';
                $post = (object)$post;
                $url = site_url();
            } else {
                $post = get_post($homePage);
                $url = get_permalink($homePage);
            }

            if ($blogPage !== 0 && $blogPage !== '0' && $blogPage !== $homePage) {
                $post = get_post($blogPage);
                $url = get_permalink($blogPage);
            }
        } else {
            $post = get_post($postID);
            $url = get_permalink($postID);
        }


        $args = ['url' => $url];
        $call = wp_remote_post(self::$API_ASSETS_URL, ['timeout' => 300, 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        $body = wp_remote_retrieve_body($call);
        if (!empty($body)) {

            if ($type == 'post_meta') {
                update_post_meta($post->ID, 'wpc_critical_assets', $body);
            } else {
                update_option('wpc_critical_assets_home', $body);
            }

            return $body;
        } else {

            if ($type == 'post_meta') {
                update_post_meta($post->ID, 'wpc_critical_assets', 'unable');
            } else {
                update_option('wpc_critical_assets_home', 'unable');
            }

            return json_encode(['img' => 0, 'js' => 0, 'css' => 0]);
        }
    }

    public function generateCriticalAjax()
    {
        $args = ['url' => urldecode($this->serverRequest)];

        $call = wp_remote_post(self::$API_URL, ['timeout' => 300, 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        $body = wp_remote_retrieve_body($call);

        if (!empty($body) && strlen($body) > 128) {
            $this->saveCriticalCss($this->urlKey, $body);
        }
    }

    public function saveCriticalCss($urlKey, $CSS, $type = 'meta')
    {
        $jobStatus = [];
        $critical_path = WPS_IC_CRITICAL . $urlKey . '/';
        $cache = new wps_ic_cache_integrations();

        if (is_array($CSS)) {
            $json = $CSS;
        } else {
            $json = json_decode($CSS, true);
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        if (!empty($json['server'])) {
            echo $json['server'];
        }

        if (!empty($json['hostname'])) {
            echo $json['hostname'];
        }

        $desktop = wp_remote_get($json['url']['desktop'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);

        $mobile = wp_remote_get($json['url']['mobile'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);

        // If fetching remote files is ERROR stop process
        if (is_wp_error($desktop) || is_wp_error($mobile)) {
            // Get the error message
            $error_message = $desktop->get_error_message();

            // Optional: Get the error code
            $error_code = $desktop->get_error_code();

            // Send a JSON response with the error message and code
            //wp_send_json_error(['msg' => 'Error downloading css file: ' . $error_message, 'code' => $error_code, 'url' => $json['desktop']]);

            return ['critical-failed' => array('desktop' => is_wp_error($desktop), 'mobile' => is_wp_error($mobile))];
        }

        $response_code = wp_remote_retrieve_response_code($desktop);
        if ($response_code !== 200) {
            return ['critical-failed' => array('desktop' => '404')];
        }

        $response_code = wp_remote_retrieve_response_code($mobile);
        if ($response_code !== 200) {
            return ['critical-failed' => array('mobile' => '404')];
        }

        $content_type = wp_remote_retrieve_header( $desktop, 'content-type' );
        if ( strpos( $content_type, 'text/css' ) === false ) {
            return ['critical-failed' => array('desktop' => 'not-css')];
        }

        $content_type = wp_remote_retrieve_header( $mobile, 'content-type' );
        if ( strpos( $content_type, 'text/css' ) === false ) {
            return ['critical-failed' => array('desktop' => 'not-css')];
        }

        // Delete any old files
        if (file_exists($critical_path . 'critical_desktop.css')) {
            unlink($critical_path . 'critical_desktop.css');
        }

        if (file_exists($critical_path . 'critical_mobile.css')) {
            unlink($critical_path . 'critical_mobile.css');
        }

        // Create path if not exists
        if (!file_exists($critical_path)) {
            mkdir($critical_path, 0777, true);
        }

        sleep(2);

        // Create New Files & Save data
        $fp = fopen($critical_path . 'critical_desktop.css', 'w+');
        fwrite($fp, wp_remote_retrieve_body($desktop));
        fclose($fp);

        // Create New Files & Save data
        $fp = fopen($critical_path . 'critical_mobile.css', 'w+');
        fwrite($fp, wp_remote_retrieve_body($mobile));
        fclose($fp);

        //remove criticalCombine temp folder
        if (file_exists(WPS_IC_COMBINE . $urlKey)) {
            $files = scandir(WPS_IC_COMBINE . $urlKey);
            if (!empty($files)) {
                foreach ($files as $file) {
                    if ($file != "." && $file != "..") {
                        $subdir = WPS_IC_COMBINE . $urlKey . "/" . $file;
                        if (is_dir($subdir) && strpos($file, "criticalCombine") !== false) {
                            $this->removeDirectory($subdir);
                        }
                    }
                }
            }
        }

        // Check if file really exists and file size is bigger than 5
        if (file_exists($critical_path . 'critical_desktop.css') && filesize($critical_path . 'critical_desktop.css') > 5) {
            if (file_exists($critical_path . 'critical_mobile.css') && filesize($critical_path . 'critical_mobile.css') > 5) {
                if ($type == 'meta') {
                    update_post_meta(sanitize_title($urlKey), 'wpc_critical_css', $critical_path . 'critical.css');
                } else {
                    update_option('wps_critical_css_' . sanitize_title($urlKey), $critical_path . 'critical.css');
                }

                $jobStatus['critical-css'] = 'success';
                $cache::purgeAll($urlKey);
                $cache::purgeCacheFiles($urlKey);
            }
        }

        return $jobStatus;
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

        if (is_dir($path)) {
            rmdir($path);
        }
    }

}