<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_litespeed extends wps_ic_integrations {

	public function is_active() {
		// Detect LiteSpeed plugin OR LiteSpeed server
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if (is_plugin_active('litespeed-cache/litespeed-cache.php')) {
			return true;
		}

		// Native detection: LiteSpeed server without plugin
		return self::is_litespeed_server();
	}

	/**
	 * Detect LiteSpeed web server via SERVER_SOFTWARE or LSWS environment.
	 */
	public static function is_litespeed_server() {
		$software = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
		if (!empty($software) && strpos(strtolower($software), 'litespeed') !== false) {
			return true;
		}
		if (!empty($_SERVER['LSWS_EDITION'])) {
			return true;
		}
		return false;
	}

	public function do_checks() {
		// Only configure JS excludes if the LiteSpeed plugin is active
		if (!defined('LSCWP_V')) {
			return;
		}

		//JS Excludes
		//this should be the format in db: ["jquery.js","jquery.min.js","wp-compress-image-optimizer"]
		$ls_js_excludes_option = 'litespeed.conf.optm-js_exc';
		$ls_js_excludes_string = get_option($ls_js_excludes_option);
		if (is_string($ls_js_excludes_string)) {
			$ls_js_excludes = json_decode( $ls_js_excludes_string, true );
		}
		// If decoding fails or isn't an array, initialize as an empty array
		if (!is_array($ls_js_excludes)) {
			$ls_js_excludes = [];
		}

		if (!in_array('wp-compress-image-optimizer', $ls_js_excludes)) {
			$ls_js_excludes[] = 'wp-compress-image-optimizer';
			update_option($ls_js_excludes_option, json_encode($ls_js_excludes));
		}


		//JS Deferred/Delayed Excludes
		$ls_js_delay_option = 'litespeed.conf.optm-js_defer_exc';
		$ls_js_delay_excludes_string = get_option($ls_js_delay_option);
		if (is_string($ls_js_delay_excludes_string)) {
			$ls_js_delay_excludes = json_decode( $ls_js_delay_excludes_string, true );
		}
		// If decoding fails or isn't an array, initialize as an empty array
		if (!is_array($ls_js_delay_excludes)) {
			$ls_js_delay_excludes = [];
		}

		if (!in_array('wp-compress-image-optimizer', $ls_js_delay_excludes)) {
			$ls_js_delay_excludes[] = 'wp-compress-image-optimizer';
			update_option($ls_js_delay_option, json_encode($ls_js_delay_excludes));
		}

	}

	public function fix_setting( $setting ) {

	}

	public function add_admin_hooks() {
		return [
			'wps_ic_purge_all_cache' => [
				'callback' => 'purge_cache',
				'priority' => 10,
				'args' => 1
			]
		];
	}

	public function purge_cache($url_key = false) {
		// Primary: use LiteSpeed Cache plugin if available
		if (defined('LSCWP_V')) {
			do_action('litespeed_purge_all');
			if (is_callable(['LiteSpeed_Cache_Tags', 'add_purge_tag'])) {
				LiteSpeed_Cache_Tags::add_purge_tag('*');
			}
			return;
		}

		// Fallback: native HTTP header purge (works without plugin)
		if (self::is_litespeed_server()) {
			self::native_purge($url_key);
		}
	}

	/**
	 * Purge LiteSpeed cache via native X-LiteSpeed-Purge header.
	 * Works directly with LiteSpeed/OpenLiteSpeed server without any plugin.
	 */
	private static function native_purge($url_key = false) {
		$url = home_url('/');
		$parsed = parse_url($url);

		if (empty($parsed['host'])) {
			return;
		}

		// Purge all public cache
		$purge_header = '*';

		wp_remote_get($url, [
			'timeout'     => 5,
			'blocking'    => false,
			'redirection' => 0,
			'sslverify'   => false,
			'headers'     => [
				'Host'              => $parsed['host'],
				'X-LiteSpeed-Purge' => $purge_header,
			],
		]);
	}

}
