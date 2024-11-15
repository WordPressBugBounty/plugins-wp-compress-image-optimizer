<?php

class wps_ic_cache_warmup{
//not used

	public function run_precache_cron_job() {
		error_reporting(E_ERROR);
		ini_set('log_errors', 'On');
		ini_set('error_log', WPS_IC_LOG . 'precache.txt');
		ini_set('display_errors', 'Off');

		$ids = get_option('wps_ic_precache_list', []);
		$url_key_class = new wps_ic_url_key();
		$this->log_precache_action("Starting cache warmup...");

		$failed_pages = 0; // Counter for pages that do not return a 200 status code

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

			// "Call" the site by making an HTTP request
			$response = wp_remote_get($url, ['timeout' => 0.01]); // set a reasonable timeout

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
		//add_action('init', [$this, 'schedule_precache_cron_job']);
		//add_action('run_precache_cron_job', [$this, 'run_precache_cron_job']);
		//add_filter('cron_schedules', [$this, 'add_custom_cron_interval']);
	}

	public function schedule_precache_cron_job() {
		$timestamp = wp_next_scheduled('run_precache_cron_job');

		// Get the current interval in seconds from the option
		$interval = get_option('wps_ic_cache_interval', 360);
		$interval_seconds = $interval * 60;

		if ($timestamp) {
			$crons = _get_cron_array();
			if (isset($crons[$timestamp]['run_precache_cron_job'])) {
				$current_cron = reset($crons[$timestamp]['run_precache_cron_job']); // Get the first (and probably only) item
				if ($current_cron['interval'] !== $interval_seconds) {
					// Unschedule the current job
					wp_unschedule_event($timestamp, 'run_precache_cron_job');

					// Schedule a new job with the correct interval
					wp_schedule_event(time(), 'wps_ic_cache_cron_interval', 'run_precache_cron_job');
					$this->log_precache_action("reschedule");
				}
			}
		} else {
			// If the job isn't scheduled, schedule it
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
