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
    /**
     * Normalize a URL to use the public-facing hostname from home_url().
     * On reverse proxy / Kinsta sites, HTTP_HOST and get_permalink() return the
     * origin hostname. This rewrites it to the public domain so keys match.
     * On normal sites (HTTP_HOST === home_url host), returns URL unchanged.
     */
    private function normalizeUrl($url) {
        $homeUrl = rtrim(home_url(), '/');
        $homeHost = parse_url($homeUrl, PHP_URL_HOST);
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';

        if (!$homeHost || !$httpHost || $httpHost === $homeHost) {
            // Same host — no proxy, return as-is (99% of sites)
            if (strpos($url, 'http') !== 0 && strpos($url, '/') === 0) {
                return $homeUrl . $url;
            }
            return $url;
        }

        // Proxy detected: HTTP_HOST differs from home_url host
        $parsed = parse_url($url);

        if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
            // Full URL with scheme
            if ($parsed['host'] === $homeHost) {
                // Already correct host — return as-is
                return $url;
            }
            // Wrong host → replace with home_url
            return $homeUrl . ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
        }

        // No scheme (e.g. "origin.host.com/path") → strip origin hostname, prepend home_url
        $path = $url;
        if (strpos($path, $httpHost) === 0) {
            $path = substr($path, strlen($httpHost));
        }
        return $homeUrl . '/' . ltrim($path, '/');
    }

    public function __construct($url = '')
    {
        if (empty($url)) {
            $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        $url = $this->normalizeUrl($url);

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


    public function criticalRunning($id = false)
    {
		if ($id === false){
			$url = self::$url;
		} else {
			if ($id === 'home' || $id == 0) {
				$homePage = get_option('page_on_front');

				if (!$homePage) {
					$url = home_url();
				} else {
					$url = get_permalink($homePage);
				}
			} else {
				$url = get_permalink($id);
			}
		}

        $running = get_transient('wpc_critical_key_' . $this->url_key_class->setup($url));
        if (empty($running) || !$running) {
            return false;
        } else {
            return true;
        }
    }

    public function generateCriticalCSS($postID = 0, $skipCap = false)
    {

        if (!empty($postID)) {
            if ($postID === 'home' || !$postID || $postID == 0) {
                $homePage = get_option('page_on_front');
                $blogPage = get_option('page_for_posts');

                if (!$homePage) {
                    $url = home_url();
                } else {
                    $url = get_permalink($homePage);
                }
            } else {
                $url = get_permalink($postID);
            }

            $url_key = $this->url_key_class->setup($url);

            if ($this->criticalExists()) {
                // Nothing
            } else {
                $url = rtrim($url, '?');
                $this->initCritical($postID, $url, $url_key, $type = 'meta','', $skipCap);
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
            $isHtml = preg_match('/<body\b[^>]*>/', $content); // basic HTML tag detection

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
            $isHtml = preg_match('/<body\b[^>]*>/', $content); // basic HTML tag detection

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

    public function initCritical($postID, $url, $url_key, $type, $timeout = 120, $skipCap = false)
    {
        $requests = new wps_ic_requests();

        $url = trim($url);
        if (empty($url) || empty(get_option(WPS_IC_OPTIONS)['api_key'])) {
            return false;
        }

        // Normalize URL + recompute key so ALL downstream code uses the public domain
        $url = $this->normalizeUrl($url);
        $url_key = $this->url_key_class->setup($url);

        // Poll /status for any in-flight request for this URL
        $needsPush = true;
        $uuid_key    = 'wpc_critical_uuid_' . $url_key;
        $pendingUuid = get_transient($uuid_key);

        // Fallback: if object cache (Redis) lost the transient, read directly from DB
        if (!$pendingUuid) {
            global $wpdb;
            $dbVal = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_' . $uuid_key
            ));
            if ($dbVal) {
                $pendingUuid = maybe_unserialize($dbVal);
            }
        }


        if ($pendingUuid) {
            $statusUrl = str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=' . urlencode($pendingUuid);
            $response  = wp_remote_get($statusUrl, ['timeout' => 3]);

            if (!is_wp_error($response)) {
                if (wp_remote_retrieve_response_code($response) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($response), true);

                    if (!empty($data['status']) && $data['status'] === 'success') {
                        $criticalCSS = new wps_criticalCss();
                        $saveResult = $criticalCSS->saveCriticalCss($url_key, [
                            'url' => [
                                'desktop' => $data['desktop_url'],
                                'mobile'  => $data['mobile_url'],
                            ],
                            // (v7.03.85) /status carries lcp_url (v3.25.1) — pass it so saveCriticalCss stashes
                            // it for the render-side healer (the .lcp.json itself lands ~28s later).
                            'lcp_url' => !empty($data['lcp_url']) ? $data['lcp_url'] : '',
                            'lcp_src' => 'poll',  // (v7.03.87) for the LCP health endpoint
                        ]);

                        // Purge caches so next visit gets fresh HTML with critical CSS injected
                        // 1. WPC HTML cache — per-page only (preserves other pages + critical CSS files)
                        $pageUrlKey = (new wps_ic_url_key())->setup($url);
                        wps_ic_cache_integrations::purgeCacheFiles($pageUrlKey);

                        // 2. Kinsta edge cache — per-URL if available, full if not
                        if (isset($GLOBALS['kinsta_cache']) && !empty($GLOBALS['kinsta_cache']->kinsta_cache_purge)) {
                            if (method_exists($GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_url')) {
                                $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_url($url);
                            } else {
                                $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
                            }
                        }

                        // 3. Other hosts (WP Engine, SiteGround, Cloudflare, Varnish, etc.)
                        do_action('wps_ic_purge_all_cache', $pageUrlKey);

                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                        return false;
                    }

                    if (!empty($data['status']) && $data['status'] === 'not_found') {
                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                    }

                    if (!empty($data['status']) && $data['status'] === 'failed') {
                        if (!empty($data['error_type']) && $data['error_type'] === 'fetch_blocked') {
                            $needsPush = true;
                            $domain = parse_url($url, PHP_URL_HOST);
                            if ($domain) {
                                set_transient('wpc_push_domain_' . $domain, true, 86400 * 7);
                            }
                        }
                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                    }
                }
            }
        }

        $transient_name = 'wpc_critical_key_' . $url_key; // Safe, short, unique.
        $critTransient = get_transient($transient_name);

        if (!empty($critTransient) && empty($_GET['forceCritical'])) {
            // Die, already running!
            return true;
        }

        // Make transient expire after 30 mins
        set_transient($transient_name, true, 60 * 5);

        $uuid     = wp_generate_uuid4();
        $uuid_key = 'wpc_critical_uuid_' . $url_key;
        set_transient($uuid_key, $uuid, 60 * 5);

        $options = get_option(WPS_IC_OPTIONS);
        $apikey  = $options['api_key'] ?? '';
        $forcePull = isset($_GET['pushMode']) && sanitize_key($_GET['pushMode']) === 'false';

        // Build args — matches existing flow (cdn-rewrite.php:2968, ajax.class.php:464)
        $args = [
            'url'     => $url . '?criticalCombine=true&testCompliant=true',
            'uuid'    => $uuid,
            'version' => (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : (class_exists('wps_ic') ? wps_ic::$version : '')), // (v7.03.120) class-independent — crit can run class-less via cron/loopback ("Class wps_ic not found" guard)
            'async'   => 'false',
            'dbg'     => 'true',
            'hash'    => time() . mt_rand(100, 9999),
            'apikey'  => $apikey,
        ];

        // Push only when needed: API proved it can't reach the site (fetch_blocked),
        // or admin clicked the button (AJAX always tries push).
        // Nope transient guards constrained hosts: blocked + slow loopback = one 1.5s
        // attempt, then 24h of zero overhead. Admin button ignores nope. Self-heals.

        // TODO: NeedsPush is set to TRUE on #178
//        $domain = parse_url($url, PHP_URL_HOST);
//        if ($domain && get_transient('wpc_push_domain_' . $domain)) {
//            $needsPush = true;
//        }

        if (!$forcePull && ($needsPush || wp_doing_ajax())) {
            $pushFailedKey = 'wpc_push_nope_' . $url_key;
            if (wp_doing_ajax() || !get_transient($pushFailedKey)) {
                $html = $this->fetchCriticalCombineHtml($url);
                if ($html) {
                    $args['html'] = $html;
                } elseif (!wp_doing_ajax()) {
                    // Push recovery failed — don't penalize visitors for 24h
                    set_transient($pushFailedKey, true, 86400);
                }
            }
        }

        // POST to API — fire-and-forget
        $requests = new wps_ic_requests();
        $call = $requests->POST(self::$API_URL, $args, [
            'timeout'  => 2,
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
        ]);

        return;
    }

    // --- v2.85 Push Model (fetch_blocked recovery) ---

    private function fetchCriticalCombineHtml($url) {
        // Adaptive timeout: AJAX 5s (admin click), auto 1.5s (visitor page load)
        $timeout = wp_doing_ajax() ? 5 : 1.5;

        $response = wp_remote_get($url . '?criticalCombine=true', [
            'timeout'    => $timeout,
            'cookies'    => [],
            'user-agent' => 'WP-Compress/CriticalCSS-Push',
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }

        // Strip scripts and blank images — not needed for CSS generation
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/(<img[^>]+)\bsrc=["\'][^"\']*["\']/i', '$1src=""', $html);

        return $html;
    }

    // --- End v2.85 Push Model ---

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
                    $url = home_url();
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

        // (v7.03.76) LCP fetchpriority hint — rides the SAME crit pull. The crit service emits a per-URL
        // sibling carrying the per-viewport {stem,width} the .75 consumer needs (wpc-lcp-fetchpriority-
        // contract.md). Stash it next to the crit so wpc_lcp_hint_pass reads it locally at render — per-URL,
        // no per-request fetch. Locator: lcp_url (crit-push v3.25.1 FLAT field, the agreed locator), else the
        // nested url.lcp, else derive <crit-dir>/<uuid>.lcp.json from the crit uuid. Gated on the producer
        // emitting one of these => fully inert on every site until the crit side ships.
        $wpc_lcp_url = '';
        if (!empty($json['lcp_url'])) {                                          // crit-push v3.25.1 flat locator (agreed)
            $wpc_lcp_url = (string) $json['lcp_url'];
        } elseif (!empty($json['url']['lcp'])) {                                 // nested-form fallback
            $wpc_lcp_url = (string) $json['url']['lcp'];
        } elseif (!empty($json['uuid']) && !empty($json['url']['desktop'])) {    // last resort: derive sibling from the crit uuid
            $wpc_lcp_url = dirname((string) $json['url']['desktop']) . '/' . (string) $json['uuid'] . '.lcp.json';
        }
        if ($wpc_lcp_url !== '') {
            $wpc_lcp_resp = wp_remote_get($wpc_lcp_url, ['headers' => ['user-agent' => WPS_IC_API_USERAGENT], 'timeout' => 5]);
            if (!is_wp_error($wpc_lcp_resp) && (int) wp_remote_retrieve_response_code($wpc_lcp_resp) === 200) {
                $wpc_lcp_body = wp_remote_retrieve_body($wpc_lcp_resp);
                if (is_string($wpc_lcp_body) && $wpc_lcp_body !== '' && json_decode($wpc_lcp_body) !== null) {
                    if (!is_dir($critical_path)) { wp_mkdir_p($critical_path); }
                    file_put_contents($critical_path . 'lcp.json', $wpc_lcp_body);
                    $jobStatus['lcp-hint-saved'] = true;
                }
            }
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
                $url = home_url();
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
        $args = ['url' => urldecode($this->serverRequest), 'version' => (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : (class_exists('wps_ic') ? wps_ic::$version : ''))]; // (v7.03.120) class-independent

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
        if (is_wp_error($desktop)) {
            // Get the error message
            $error_message = $desktop->get_error_message();

            // Optional: Get the error code
            $error_code = $desktop->get_error_code();

            // Send a JSON response with the error message and code
            //wp_send_json_error(['msg' => 'Error downloading css file: ' . $error_message, 'code' => $error_code, 'url' => $json['desktop']]);

            return ['critical-failed' => array('desktop' => is_wp_error($desktop), 'mobile' => is_wp_error($mobile))];
        }

	    if (is_wp_error($mobile)) {
		    // Get the error message
		    $error_message = $mobile->get_error_message();

		    // Optional: Get the error code
		    $error_code = $mobile->get_error_code();

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

        // (v7.03.85) Clear the stale per-uuid LCP hint + its stashed URL on regen. Each regen mints a NEW
        // uuid, so a leftover lcp.json / lcp_url.txt would point the render-side healer at the OLD uuid. The
        // poll/callback re-stashes the new lcp_url below (on success); the healer then re-fetches the new hint.
        if (file_exists($critical_path . 'lcp.json'))      { @unlink($critical_path . 'lcp.json'); }
        if (file_exists($critical_path . 'lcp_url.txt'))   { @unlink($critical_path . 'lcp_url.txt'); }
        if (file_exists($critical_path . 'lcp_src.txt'))   { @unlink($critical_path . 'lcp_src.txt'); }   // (v7.03.87) debug
        if (file_exists($critical_path . 'lcp_heal.json')) { @unlink($critical_path . 'lcp_heal.json'); } // (v7.03.87) debug

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
                $cache::purgeAll($urlKey, false, true, false);
                // (v7.03.85) Stash the per-URL lcp_url so the render-side healer can re-fetch the .lcp.json —
                // the producer writes it to CDN storage ~28s LATER (per-uuid, fire-and-forget, no second
                // callback), so it isn't here yet. lcp_url rides the crit /status (v3.25.1) and can ride the
                // callback too (gated). See the healer in rewriteLogic::addCriticalCSS.
                $wpc_lcp_url_stash = '';
                if (!empty($json['lcp_url']))        { $wpc_lcp_url_stash = (string) $json['lcp_url']; }
                elseif (!empty($json['url']['lcp']))  { $wpc_lcp_url_stash = (string) $json['url']['lcp']; }
                if ($wpc_lcp_url_stash !== '') {
                    @file_put_contents($critical_path . 'lcp_url.txt', $wpc_lcp_url_stash);
                    // (v7.03.87) record which path stashed it, for the LCP health endpoint (crit joint debug).
                    @file_put_contents($critical_path . 'lcp_src.txt', !empty($json['lcp_src']) ? (string) $json['lcp_src'] : 'unknown');
                }
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