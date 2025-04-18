<?php


/**
 * Class - Stats
 */
class wps_ic_stats
{

    public static $api_key;
    public static $options;

    public function __construct()
    {

        if (is_admin()) {
            $options = new wps_ic_options();
            $options = $options->get_option();

            self::$api_key = '';
            if (!empty($options['api_key'])) {
                self::$api_key = $options['api_key'];
            }
        }
    }


    public function getAPIStats()
    {
        // Check privileges
        $url = 'https://apiv3.wpcompress.com/api/site/credits';
        $call = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => WPS_IC_API_USERAGENT,
            'headers' => [
                'apikey' => self::$api_key,
            ]
        ]);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);
            return $body;
        } else if (wp_remote_retrieve_response_code($call) == 401) {
		        $cache = new wps_ic_cache_integrations();
						$cache->remove_key();
		        return false;
        }

	    return false;
    }


    public function getLiteStatsBox($title, $arrow, $after, $percentage, $before)
    {
        $initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);
        $initialTestRunning = get_transient('wpc_initial_test');

        if ($arrow == 'down') {
            $arrow = '<img src="' . WPS_IC_ASSETS . '/lite/images/arrow-down.svg"/>';
        } else {
            $arrow = '<img src="' . WPS_IC_ASSETS . '/lite/images/arrow-up.svg"/>';
        }

        if (!empty($initialPageSpeedScore['failed']) && $initialPageSpeedScore['failed'] == 'true') {
            $html = '<div class="wpc-optimization-stats-box">
                                            <h3>' . $title . '</h3>
                                            <div class="wpc-stats-info">
                                                <span class="wpc-stats-info-text">
                                                </span>
                                            </div>
                                            <div style="padding: 20px 0px;">
                                                <div class="wpc-ic-small-thick-placeholder" style="width:80px;"></div>
                                            </div>
                                            <div class="wpc-stats-before" style="padding: 10px 0px;">
                                                <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                            </div>
                                        </div>';
        } elseif (!empty($initialTestRunning) || empty($after) || $after == '0' || $after == '0.0 B' || $after == '0 ms') {
            $html = '<div class="wpc-optimization-stats-box">
                                            <h3>' . $title . '</h3>
                                            <div class="wpc-stats-info">
                                                <span class="wpc-stats-info-text">
                                                <div class="loading-icon">
                                    <div class="inner"></div>
                                </div>
                                                </span>
                                            </div>
                                            <div style="padding: 20px 0px;">
                                                <div class="wpc-ic-small-thick-placeholder" style="width:80px;"></div>
                                            </div>
                                            <div class="wpc-stats-before" style="padding: 10px 0px;">
                                                <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                            </div>
                                        </div>';

        } else {

            $html = '<div class="wpc-optimization-stats-box">
                                            <h3>' . $title . '</h3>
                                            <div class="wpc-stats-info">
                                                <span class="wpc-stats-info-icon">
                                                    <img src="' . WPS_IC_ASSETS . '/lite/images/stats-speed.svg"/>
                                                </span>
                                                <span class="wpc-stats-info-text">' . $after . '</span>
                                            </div>
                                            <div class="wpc-stats-improvement">
                                                <span class="wpc-stats-improvement-icon">
                                                    ' . $arrow . '
                                                </span>
                                                <span class="wpc-stats-improvement-text">' . $percentage . '</span>
                                            </div>
                                            <div class="wpc-stats-before">
                                                <span class="wpc-stats-improvement-icon">
                                                    <img src="' . WPS_IC_ASSETS . '/lite/images/turtle.svg"/>
                                                </span>
                                                <span class="wpc-stats-improvement-text">
                                                was ' . $before . '
                                                </span>
                                            </div>
                                        </div>';

        }
        return $html;
    }


    public function getLiteOptimizationStatus($optimizedStats)
    {
        $initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);
        $initialTestRunning = get_transient('wpc_initial_test');

        $option = get_option(WPS_IC_OPTIONS);
        if (!empty($option['version']) && $option['version'] == 'lite' && !get_option('hide_wpcompress_plugin')) {

            $html = '<div class="wpc-stats-unlock"><a href="#" class="wpc-custom-btn wpc-custom-btn-locked"><span>Unlock 24/7 Monitoring</span> <img src="' . WPS_IC_URI . 'assets/lite/images/unlock-24-7.svg" alt="Unlock 24/7 Monitoring"/></a></div>';

        } else {

            $html = '<div class="wpc-stats-monitoring"><span><img src="' . WPS_IC_URI . 'assets/lite/images/checkbox-link.svg" alt="24/7 Monitoring Active"/> 24/7 Monitoring Active</span></div>';

        }

        return $html;
    }


    public function getOptimizedStats()
    {
        $stats = [];

        $stats['pageSizeSavings'] = 0;
        $stats['totalPageSizeBefore'] = 0;
        $stats['totalPageSizeAfter'] = 0;
        $stats['totalRequestsSavings'] = 0;
        $stats['totalRequestsBefore'] = 0;
        $stats['totalRequestsAfter'] = 0;
        $stats['totalTtfbSavings'] = 0;
        $stats['totalTtfbBefore'] = 0;
        $stats['totalTtfbAfter'] = 0;
        $stats['ttfbLess'] = 0;
        $stats['pageSizeSavingsPercentage'] = 0;

        // Cache
        $cacheDir = WPS_IC_CACHE;
        if (file_exists($cacheDir)) {
            $stats['cachedPages'] = $this->countFiles($cacheDir);
        }

        // return empty stats if test is running
        $initialTestRunning = get_transient('wpc_initial_test');
        if (!empty($initialTestRunning)) {
            return $stats;
        }

        // pageSizeSavings
        $tests = get_option(WPS_IC_TESTS);
        if (!empty($tests['home'])) {
            $tests = $tests['home'];

            $beforePageSize = $tests['desktop']['before']['pageSize'];
            $afterPageSize = $tests['desktop']['after']['pageSize'];

            if ($afterPageSize > $beforePageSize) {
                $afterPageSize = $beforePageSize;
            }

            $stats['totalPageSizeAfter'] += $afterPageSize;
            $stats['totalPageSizeBefore'] += $beforePageSize;
            $stats['pageSizeSavings'] += $beforePageSize - $afterPageSize;


            $stats['pageSizeSavingsPercentage'] = 0;

            if ($stats['totalPageSizeBefore'] > 0) {
                $stats['pageSizeSavingsPercentage'] = round(($stats['pageSizeSavings'] / $stats['totalPageSizeBefore']) * 100, 0) . '%';
            }

            $stats['pageSizeSavings'] = wps_ic_format_bytes($stats['pageSizeSavings']);
            $stats['totalPageSizeAfter'] = wps_ic_format_bytes($stats['totalPageSizeAfter'], null, '%01.1f %s');
            $stats['totalPageSizeBefore'] = wps_ic_format_bytes($stats['totalPageSizeBefore'], null, '%01.1f %s');

            // Requests
            $before = $tests['desktop']['before']['requests'];
            $after = $tests['desktop']['after']['requests'];

            if ($after > $before) {
                $after = $before;
            }

            $stats['totalRequestsBefore'] += $before;
            $stats['totalRequestsAfter'] += $after;
            $stats['totalRequestsSavings'] += $before - $after;

            // TTFB
            $beforeTtfb = $tests['desktop']['before']['ttfb'];
            $afterTtfb = $tests['desktop']['after']['ttfb'];

            if ($afterTtfb > $beforeTtfb) {
                $afterTtfb = $beforeTtfb;
            }

            $stats['totalTtfbBefore'] += $beforeTtfb;
            $stats['totalTtfbAfter'] += $afterTtfb;
            $stats['totalTtfbSavings'] += $beforeTtfb - $afterTtfb;

            if ($stats['totalTtfbAfter'] > 0) {
                $ratio = $stats['totalTtfbBefore'] / $stats['totalTtfbAfter'];

                if ($ratio < 1) {
                    // Under 1x faster, show as a percentage
                    $stats['ttfbLess'] = round($ratio * 100, 2) . '%';
                } elseif ($ratio < 10) {
                    // Under 10x faster, show 1 decimal point
                    $stats['ttfbLess'] = round($ratio, 1) . 'x';
                } else {
                    // 10x or more, show as integer
                    $stats['ttfbLess'] = floor($ratio) . 'x';
                }
            }

            if ($stats['totalTtfbAfter'] < 999) {
                $stats['totalTtfbAfter'] = $stats['totalTtfbAfter'] . ' ms';
            } else {
                $stats['totalTtfbAfter'] = round($stats['totalTtfbAfter'] / 1000, 1) . ' sec';
            }

            if ($stats['totalTtfbBefore'] > 100 && $stats['totalTtfbBefore'] < 999) {
                $stats['totalTtfbBefore'] = $stats['totalTtfbBefore'] . ' ms';
            } else {
                $stats['totalTtfbBefore'] = round($stats['totalTtfbBefore'] / 1000, 1) . ' sec';
            }
        }

        return $stats;
    }

    public
    function countFiles($dir)
    {
        $fileCount = 0;

        // Ensure the directory exists
        if (is_dir($dir)) {
            // Create a recursive directory iterator
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::LEAVES_ONLY);

            // Iterate through the directory and count files
            foreach ($iterator as $file) {
                // Skip directories (RecursiveDirectoryIterator includes directories by default)
                if ($file->isFile()) {
                    $fileName = $file->getFilename();
                    if (strtolower($file->getExtension()) === 'html' && stripos($fileName, 'mobile') === false) {
                        $fileCount++;
                    }
                }
            }
        } else {
            return 0;
        }

        return $fileCount;
    }

    public
    function fetch_local_sum_stats()
    {
        delete_transient('wps_ic_live_stats_v2');
        $transient = get_transient('wps_ic_live_stats_v2');

        if (!$transient || empty($transient)) {
            if (!empty(self::$api_key)) {
                $uri = WPS_IC_KEYSURL . '?action=get_chart_local_stats_sum_v6&apikey=' . self::$api_key;
                $call = wp_remote_get($uri, ['sslverify' => false, 'timeout' => '50']);
                $body = wp_remote_retrieve_body($call);
                if (wp_remote_retrieve_response_code($call) == 200) {

                    $body = json_decode($body);

                    if (!empty($body) && $body->success == 'true') {
                        set_transient('wps_ic_local_sum_stats', $body, 60);
                        return $body;
                    }
                }

            }

        }
    }


    public
    function fetch_local_stats()
    {
        delete_transient('wps_ic_live_stats_v2');
        $transient = get_transient('wps_ic_live_stats_v2');

        if (!$transient || empty($transient)) {
            if (!empty(self::$api_key)) {
                $uri = WPS_IC_KEYSURL . '?action=get_chart_local_stats_v6&apikey=' . self::$api_key;
                $call = wp_remote_get($uri, ['sslverify' => false, 'timeout' => '50']);
                $body = wp_remote_retrieve_body($call);
                if (wp_remote_retrieve_response_code($call) == 200) {

                    $body = json_decode($body);

                    if (!empty($body) && $body->success == 'true') {
                        set_transient('wps_ic_local_stats', $body, 60);
                        return $body;
                    }
                }

            }

        }
    }


    public
    function fetch_sample_stats()
    {
        set_transient('ic_sample_data_live', 'true', 60);
        $sample = file_get_contents(WPS_IC_DIR . 'sample-data-live.json');
        $sample = json_decode($sample);
        $currentYear = date('Y');
        $updated = new stdClass();
        $updated->data = [];
        foreach ($sample->data as $date => $val) {
            $newDate = str_replace('2022', $currentYear, $date);
            $updated->data[$newDate] = $val;
        }
        return $updated->data;
    }


    public
    function fetch_live_stats()
    {
        delete_transient('wps_ic_live_stats');
        $transient = get_transient('wps_ic_live_stats');

        if (!$transient || empty($transient)) {
            if (!empty(self::$api_key)) {
                $url = 'https://apiv3.wpcompress.com/api/site/stats?action=chart';
                $call = wp_remote_get($url, [
                    'timeout' => 30,
                    'sslverify' => false,
                    'user-agent' => WPS_IC_API_USERAGENT,
                    'headers' => [
                        'apikey' => self::$api_key,
                    ]
                ]);
                $body = wp_remote_retrieve_body($call);
                if (wp_remote_retrieve_response_code($call) == 200) {

                    $body = json_decode($body);
                    if (!empty($body)) {
                        set_transient('wps_ic_live_stats', $body, 60);
                        return $body;
                    }
                }

            }

        }

        return false;
    }

    public
    function getWarmupStats($id = false)
    {
        $stats = get_option('wpc_warmup_stats', []);
        $count = 0;
        $assetsCount = 0;

        if (!empty($stats)) {
            if (!empty($id)) {
                if (isset($stats[$id]['images'])) {
                    $assetsCount += $stats[$id]['images'];
                }
                if (isset($stats[$id]['js'])) {
                    $assetsCount += $stats[$id]['js'];
                }
                if (isset($stats[$id]['css'])) {
                    $assetsCount += $stats[$id]['css'];
                }
                if (isset($stats[$id]['fonts'])) {
                    $assetsCount += $stats[$id]['fonts'];
                }
            }
            foreach ($stats as $id => $stat) {
                if (isset($stat['images'])) {
                    $assetsCount += $stat['images'];
                }
                if (isset($stat['js'])) {
                    $assetsCount += $stat['js'];
                }
                if (isset($stat['css'])) {
                    $assetsCount += $stat['css'];
                }
                if (isset($stat['fonts'])) {
                    $assetsCount += $stat['fonts'];
                }
            }
            $count = count($stats);
        }

        $return = ['optimizedPages' => $count, 'assets' => $assetsCount];

        return $return;
    }

    public
    function saveWarmupStats($html)
    {
        global $post;

        $home_url = rtrim(home_url(), '/');
        $current_url = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], '/');
        if ($home_url === $current_url) {
            $id = 'home';
        } else if (!empty($post->ID)) {
            $id = $post->ID;
        } else {
            return;
        }

        $stats = get_option('wpc_warmup_stats', []);

        if (isset($existingStats[$id])) {
            return;
        }

        $stat = [
            'images' => 0,
            'js' => 0,
            'css' => 0,
            'fonts' => 0
        ];

        preg_match_all('/\.(jpg|jpeg|png|gif|webp|svg|avif)[\s\'"]/i', $html, $matches);
        $stat['images'] = !empty($matches[0]) ? count($matches[0]) : 0;

        preg_match_all('/\.js[\s\'"]|type=[\'"]text\/javascript[\'"]/i', $html, $matches);
        $stat['js'] = !empty($matches[0]) ? count($matches[0]) : 0;

        preg_match_all('/\.css[\s\'"]|type=[\'"]text\/css[\'"]/i', $html, $matches);
        $stat['css'] = !empty($matches[0]) ? count($matches[0]) : 0;

        preg_match_all('/\.(woff2?|eot|ttf|otf)[\s\'"]|font-family:/i', $html, $matches);
        $stat['fonts'] = !empty($matches[0]) ? count($matches[0]) : 0;

        $stat['timestamp'] = time();

        $stats[$id] = $stat;

        update_option('wpc_warmup_stats', $stats);
    }

}