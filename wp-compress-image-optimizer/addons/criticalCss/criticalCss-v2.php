<?php

if (!class_exists('wps_ic_url_key')) {
    include_once WPS_IC_DIR . 'traits/url_key.php';
}

class wps_criticalCss
{

    static public $API_URL = WPS_IC_CRITICAL_API_URL;
    static public $API_URL_PING = WPS_IC_CRITICAL_API_URL_PING;
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


    public function criticalExists($returnDir = false)
    {
        if (!empty($_GET['debugCritical_replace'])) {
            return [WPS_IC_CRITICAL, $this->urlKey, 'file' => WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css', 'exists' => file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')];
        }

        $return = [];

        if (file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')) {
            if ($returnDir) {
                $return['desktop'] = WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css';
            } else {
                $return['desktop'] = WPS_IC_CRITICAL_URL . $this->urlKey . '/critical_desktop.css';
            }
        }

        if (file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_mobile.css')) {
            if ($returnDir) {
                $return['mobile'] = WPS_IC_CRITICAL . $this->urlKey . '/critical_mobile.css';
            } else {
                $return['mobile'] = WPS_IC_CRITICAL_URL . $this->urlKey . '/critical_mobile.css';
            }
        }

        if (!isset($return['mobile']) || !isset($return['desktop'])) {
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

        if (!empty($critTransient)) {
            // Die, already running!
            return true;
        }

        // Make transient expire after 30 mins
        set_transient($transient_name, true, 60*30);


        $args = ['url' => $url.'?criticalCombine=true&testCompliant=true', 'async' => 'false', 'version' => '2.3', 'dbg' => 'false', 'hash' => time().mt_rand(100,9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
        #$args = ['url' => $url.'?disableWPC=true', 'async' => 'false', 'dbg' => 'false', 'hash' => time().mt_rand(100,9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];

        $call = $requests->POST(self::$API_URL, $args, ['timeout' => 0.1, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json')]);
        $code = $requests->getResponseCode($call);

        if ($code == 200) {
            $body = $requests->getResponseBody($call);
            $json = json_decode($body, true);

            if (!empty($json) && isset($json['url']['desktop']) && isset($json['url']['mobile'])) {
                $this->saveCriticalCss($url_key, $body, $type);
            }

        } else if (is_wp_error($call)) {
            $error_message = $requests->getErrorMessage($call);

            if (strpos($error_message, 'cURL error 28') !== false) {
                #wp_send_json_error(['msg' => 'Request timeout, API is likely blocked.', 'error' => $error_message]);
            }

            #wp_send_json_error(['msg' => 'Error code ' . $code, 'error' => $error_message]);
        } else {
            #wp_send_json_error(['code' => $code]);
        }
    }


    public function saveCriticalCss($urlKey, $CSS, $type = 'meta')
    {
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

        $desktop = wp_remote_get($json['url']['desktop'], [
            'headers' => [
                'user-agent' => WPS_IC_API_USERAGENT
            ]
        ]);

        $mobile = wp_remote_get($json['url']['mobile'], [
            'headers' => [
                'user-agent' => WPS_IC_API_USERAGENT
            ]
        ]);

        if (is_wp_error($desktop) || is_wp_error($mobile)) {
            // Get the error message
            $error_message = $desktop->get_error_message();

            // Optional: Get the error code
            $error_code = $desktop->get_error_code();

            // Send a JSON response with the error message and code
            wp_send_json_error([
                'msg' => 'Error downloading css file: ' . $error_message,
                'code' => $error_code, // Optional
                'url' => $json['desktop']
            ]);
        }

        unlink($critical_path . 'critical_desktop.css');
        unlink($critical_path . 'critical_mobile.css');

        mkdir($critical_path, 0777, true);

        $fp = fopen($critical_path . 'critical_desktop.css', 'w+');
        fwrite($fp, wp_remote_retrieve_body($desktop));
        fclose($fp);

        if (is_wp_error($mobile)) {
            wp_send_json_error(['msg' => 'Error downloading css file.', 'url' => $json['mobile']]);
        }

        $fp = fopen($critical_path . 'critical_mobile.css', 'w+');
        fwrite($fp, wp_remote_retrieve_body($mobile));
        fclose($fp);

        //remove criticalCombine temp folder
        $files = scandir(WPS_IC_COMBINE . $urlKey);
        foreach ($files as $file) {
            if ($file != "." && $file != "..") {
                $subdir = WPS_IC_COMBINE . $urlKey . "/" . $file;
                if (is_dir($subdir) && strpos($file, "criticalCombine") !== false) {
                    $this->removeDirectory($subdir);
                }
            }
        }

        if (file_exists($critical_path . 'critical_desktop.css') && filesize($critical_path . 'critical_desktop.css') > 5) {
            if ($type == 'meta') {
                update_post_meta(sanitize_title($urlKey), 'wpc_critical_css', $critical_path . 'critical.css');
            } else {
                update_option('wps_critical_css_' . sanitize_title($urlKey), $critical_path . 'critical.css');
            }
        }

        $cache::purgeAll($urlKey);
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

}