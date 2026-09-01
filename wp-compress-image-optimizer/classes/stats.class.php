<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/stats.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */


include_once WPS_IC_DIR . 'traits/agency.php';




class wps_ic_stats
{
    use wps_ic_agency_trait;

    public static $api_key;
    public static $options;

    public function __construct()
    {

        if (is_admin() || $this->isAgencyPortal()) {
            $options = new wps_ic_options();
            $options = $options->get_option();

            self::$api_key = '';
            if (!empty($options['api_key'])) {
                self::$api_key = $options['api_key'];
            }

            $this->isAgencyPortal();
        }
    }


    public function getAPIStats()
    {

	      $status = get_transient('wps_ic_account_status_call');

				if (!empty($status)){
					return $status;
				}

        
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
                                                <span class="wpc-stats-info-text">' . preg_replace('/([0-9.]+)\s*([a-zA-Z%]+)/', '$1<span class="wpc-stats-unit">$2</span>', $after) . '</span>
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

            $html = '<div class="wpc-stats-unlock"><a href="#" class="wpc-custom-btn wpc-custom-btn-locked"><span>' . esc_html__('Unlock 24/7 Monitoring', WPS_IC_TEXTDOMAIN) . '</span> <img src="' . WPS_IC_URI . 'assets/lite/images/unlock-24-7.svg" alt="'.esc_html__('Unlock 24/7 Monitoring', WPS_IC_TEXTDOMAIN).'"/></a></div>';

        } else {

            $html = '<div class="wpc-stats-monitoring"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 512 512" style="vertical-align:-1px;margin-right:6px"><path fill="currentColor" d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg> '.esc_html__('24/7 Monitoring Active', WPS_IC_TEXTDOMAIN).'</span></div>';

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

        
        $cacheDir = WPS_IC_CACHE;
        if (file_exists($cacheDir)) {
            $stats['cachedPages'] = $this->countFiles($cacheDir);
        }


        $initialTestRunning = get_transient('wpc_initial_test');
        if (!empty($initialTestRunning)) {
            return $stats;
        }


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

            
            $before = $tests['desktop']['before']['requests'];
            $after = $tests['desktop']['after']['requests'];

            if ($after > $before) {
                $after = $before;
            }

            $stats['totalRequestsBefore'] += $before;
            $stats['totalRequestsAfter'] += $after;
            $stats['totalRequestsSavings'] += $before - $after;

            
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
                    
                    $stats['ttfbLess'] = round($ratio * 100, 2) . '%';
                } elseif ($ratio < 10) {
                    
                    $stats['ttfbLess'] = round($ratio, 1) . 'x';
                } else {
                    
                    $stats['ttfbLess'] = floor($ratio) . 'x';
                }
            }

            if ($stats['totalTtfbAfter'] < 999) {
                $stats['totalTtfbAfter'] = $stats['totalTtfbAfter'] . ' ms';
            } else {
                $stats['totalTtfbAfter'] = round($stats['totalTtfbAfter'] / 1000, 1) . ' sec';
            }

            if ($stats['totalTtfbBefore'] < 999) {
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
        $wpc_cfk = 'wpc_countfiles_' . md5((string) $dir);
        $wpc_cfc = get_transient($wpc_cfk);
        if ($wpc_cfc !== false) {
            return (int) $wpc_cfc;
        }
        $fileCount = 0;

        
        if (is_dir($dir)) {
            try {
                $wpc_rdi887 = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
                $wpc_flt887 = new RecursiveCallbackFilterIterator($wpc_rdi887, function ($file) {
                    return strpos($file->getFilename(), '.purging-') !== 0;
                });
                $iterator = new RecursiveIteratorIterator($wpc_flt887, RecursiveIteratorIterator::LEAVES_ONLY);

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $fileName = $file->getFilename();
                        if (strtolower($file->getExtension()) === 'html' && stripos($fileName, 'mobile') === false) {
                            $fileCount++;
                        }
                    }
                }
            } catch (Throwable $wpc_ste887) {
                
            }
        } else {
            return 0;
        }

        set_transient($wpc_cfk, (int) $fileCount, 900);
        return $fileCount;
    }

    public
    function fetch_local_sum_stats()
    {
        
        
        $transient = get_transient('wps_ic_local_sum_stats');
        if (!empty($transient)) {
            return $transient;
        }

        if (!empty(self::$api_key)) {
            $wpc_lss60 = (int) get_option('wpc_local_sum_stats_at');
            if (time() - $wpc_lss60 < 60) {
                return false;
            }
            update_option('wpc_local_sum_stats_at', time(), false);
            $uri = WPS_IC_KEYSURL . '?action=get_chart_local_stats_sum_v6&apikey=' . self::$api_key;
            $call = wp_remote_get($uri, ['sslverify' => false, 'timeout' => 10]);
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


    public
    function fetch_local_stats()
    {
        
        $transient = get_transient('wps_ic_local_stats');
        if (!empty($transient)) {
            return $transient;
        }

        if (!empty(self::$api_key)) {
            $wpc_lst60 = (int) get_option('wpc_local_stats_at');
            if (time() - $wpc_lst60 < 60) {
                return false;
            }
            update_option('wpc_local_stats_at', time(), false);
            $uri = WPS_IC_KEYSURL . '?action=get_chart_local_stats_v6&apikey=' . self::$api_key;
            $call = wp_remote_get($uri, ['sslverify' => false, 'timeout' => 10]);
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


    public
    function fetch_sample_stats()
    {
        set_transient('ic_sample_data_live', 'true', 60);
        $sample = file_get_contents(WPS_IC_DIR . 'sample-data-live.json');
        $sample = json_decode($sample);

        
        $values = array_values((array)$sample->data);
        $updated = new stdClass();
        $updated->data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('d-m-Y', strtotime("-{$i} days"));
            $val = isset($values[6 - $i]) ? $values[6 - $i] : $values[0];
            $updated->data[$date] = $val;
        }
        return $updated->data;
    }


    public
    function fetch_live_stats()
    {

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
                        set_transient('wps_ic_live_stats', $body, HOUR_IN_SECONDS);
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

	





	public function fetch_cloudflare_stats($days = 7) {
		$transient = get_transient('wps_ic_cf_stats');

		if (!$transient || empty($transient)) {
			$cf = get_option(WPS_IC_CF);

			if (!$cf || empty($cf['token'])) {
				return false;
			}

			
			
			$wpc_cfs60 = (int) get_option('wpc_cf_stats_at');
			if (time() - $wpc_cfs60 < 300) {
				return false;
			}
			update_option('wpc_cf_stats_at', time(), false);

			
			$cloudflare = new WPC_CloudflareAPI($cf['token']);

			
			$to = date('Y-m-d');
			$from = date('Y-m-d', strtotime("-{$days} days"));

			
			$stats = $cloudflare->getZoneAnalyticsUnfiltered($from, $to);

			if (is_wp_error($stats)) {
				return false;
			}

			
			$formatted = new stdClass();
			foreach ($stats as $date => $data) {
				$formatted->$date = (object)[
					'original' => $data['bytes'],              
					'compressed' => $data['cached_bytes'],     
					'requests' => $data['requests'],           
					'cached_requests' => $data['cached_requests'] 
				];
			}

			
			set_transient('wps_ic_cf_stats', $formatted, 300);
			return $formatted;
		}

		return $transient;
	}

}