<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/litespeed.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_litespeed extends wps_ic_integrations {

	public function is_active() {
		
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if (is_plugin_active('litespeed-cache/litespeed-cache.php')) {
			return true;
		}

		
		return self::is_litespeed_server();
	}

	


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
		
		if (!defined('LSCWP_V')) {
			return;
		}

		
		
		$ls_js_excludes_option = 'litespeed.conf.optm-js_exc';
		$ls_js_excludes_string = get_option($ls_js_excludes_option);
		if (is_string($ls_js_excludes_string)) {
			$ls_js_excludes = json_decode( $ls_js_excludes_string, true );
		}
		
		if (!is_array($ls_js_excludes)) {
			$ls_js_excludes = [];
		}

		if (!in_array('wp-compress-image-optimizer', $ls_js_excludes)) {
			$ls_js_excludes[] = 'wp-compress-image-optimizer';
			update_option($ls_js_excludes_option, json_encode($ls_js_excludes));
		}


		
		$ls_js_delay_option = 'litespeed.conf.optm-js_defer_exc';
		$ls_js_delay_excludes_string = get_option($ls_js_delay_option);
		if (is_string($ls_js_delay_excludes_string)) {
			$ls_js_delay_excludes = json_decode( $ls_js_delay_excludes_string, true );
		}
		
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
		
		if (defined('LSCWP_V')) {
			do_action('litespeed_purge_all');
			if (is_callable(['LiteSpeed_Cache_Tags', 'add_purge_tag'])) {
				LiteSpeed_Cache_Tags::add_purge_tag('*');
			}
			return;
		}

		
		if (self::is_litespeed_server()) {
			self::native_purge($url_key);
		}
	}

	



	private static function native_purge($url_key = false) {
		$url = home_url('/');
		$parsed = parse_url($url);

		if (empty($parsed['host'])) {
			return;
		}

		
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
