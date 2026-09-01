<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/cache_warmup.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */


class wps_ic_cache_warmup{


	public function run_precache_cron_job() {
		error_reporting(E_ERROR);
		ini_set('log_errors', 'On');
		ini_set('error_log', WPS_IC_LOG . 'precache.txt');
		ini_set('display_errors', 'Off');

		$ids = get_option('wps_ic_precache_list', []);
		$url_key_class = new wps_ic_url_key();
		$this->log_precache_action("Starting cache warmup...");

		$failed_pages = 0; 

		if (empty($ids)) {
			$this->log_precache_action("No pages selected for cache warmup.");
			return true;
		}

		foreach ($ids as $id) {
			if ($id === 'home') {
				$url = home_url();
			} else {
				$url = get_permalink($id);
			}

			if (empty($url)) {
				continue;
			}

			$urlKey = $url_key_class->setup($url);
			if (file_exists(WPS_IC_CACHE . $urlKey . '/index.html')) {
				unlink(WPS_IC_CACHE . $urlKey . '/index.html');
			}
			if (file_exists(WPS_IC_CACHE . $urlKey . '/index.html_gzip')) {
				unlink(WPS_IC_CACHE . $urlKey . '/index.html_gzip');
			}

			


			$response = wp_remote_get($url, ['timeout' => 10]);

			if (is_wp_error($response)) {
				$failed_pages++;
				continue;
			}

			$http_code = wp_remote_retrieve_response_code($response);
			if ($http_code !== 200) {
				$failed_pages++;
			}
		}

		$this->log_precache_action("Cache warmup finished. Number of pages that did not return 200 code: {$failed_pages}");
	}

	public function add_hooks() {


	}

	public function schedule_precache_cron_job() {
		$timestamp = wp_next_scheduled('run_precache_cron_job');

		
		$interval = get_option('wps_ic_cache_interval', 360);
		$interval_seconds = $interval * 60;

		if ($timestamp) {
			$crons = _get_cron_array();
			if (isset($crons[$timestamp]['run_precache_cron_job'])) {
				$current_cron = reset($crons[$timestamp]['run_precache_cron_job']); 
				if ($current_cron['interval'] !== $interval_seconds) {
					
					wp_unschedule_event($timestamp, 'run_precache_cron_job');

					
					wp_schedule_event(time(), 'wps_ic_cache_cron_interval', 'run_precache_cron_job');
					$this->log_precache_action("reschedule");
				}
			}
		} else {

			wp_schedule_event(time(), 'wps_ic_cache_cron_interval', 'run_precache_cron_job');
		}
	}


	public function add_custom_cron_interval($schedules) {
		$interval = get_option('wps_ic_cache_interval', 360);
		$interval_seconds = $interval * 60;

		$schedules[ 'wps_ic_cache_cron_interval' ] = [
			'interval' => $interval_seconds,
			'display'  => "Every {$interval} minutes"
		];

		return $schedules;
	}

	public function log_precache_action($message) {
		$log_file = WPS_IC_LOG . 'precache.txt';
		$current_time = current_time('mysql');
		$log_message = $current_time . ': ' . $message . "\n";
		error_log($log_message, 3, $log_file);
	}

}
