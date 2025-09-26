<?php

class wps_ic_cname
{
	public function __construct()
	{
		// Constructor can be empty or add initialization if needed
	}

	public function add($cname_input = null)
	{
		$zone_name = get_option('ic_cdn_zone_name');

		delete_option('ic_cname_retry_count');

		if (!empty($cname_input)) {
			$error = '';
			$options = get_option(WPS_IC_OPTIONS);
			$apikey = $options['api_key'];

			// TODO is cname valid?
			$cname = sanitize_text_field($cname_input);
			$cname = str_replace(['http://', 'https://'], '', $cname);
			$cname = rtrim($cname, '/');

			if ($zone_name == $cname) {
				$error = 'This domain is invalid, please link a new domain...';
				wp_send_json_error('invalid-domain');
			}

			if (strpos($cname, 'zapwp.com') !== false || strpos($cname, 'zapwp.net') !== false) {
				$error = 'This domain is invalid, please link a new domain...';
				wp_send_json_error('invalid-domain');
			}

			if (empty($error)) {
				if (!preg_match('/^([a-zA-Z0-9\_\-]+)\.([a-zA-Z0-9\_\-]+)\.([a-zA-Z0-9\_\-]+)$/', $cname, $matches) && !preg_match('/^([a-zA-Z0-9\_\-]+)\.([a-zA-Z0-9\_\-]+)\.([a-zA-Z0-9\_\-]+)\.([a-zA-Z0-9\_\-]+)$/', $cname, $matches)) {
					// Subdomain is not valid
					$error = 'This domain is invalid, please link a new domain...';
					delete_option('ic_custom_cname');
					$settings = get_option(WPS_IC_SETTINGS);
					unset($settings['cname']);
					update_option(WPS_IC_SETTINGS, $settings);
					wp_send_json_error('invalid-domain');
				} else {
					// Verify CNAME DNS
					$requests = new wps_ic_requests();
					$body = $requests->GET('https://frankfurt.zapwp.net/', ['dnsCheck' => 'true', 'host' => $cname, 'zoneName' => $zone_name, 'hash' => microtime(true)], ['timeout' => 60]);

					if (!empty($body)) {
						$data = (array)$body->data;

						if (empty($data)) {
							wp_send_json_error('invalid-dns-prop');
						}

						$recordsType = $data['records']->type;
						$recordsTarget = $data['records']->target;

						if ($recordsType == 'CNAME') {
							if ($recordsTarget == $zone_name) {
								update_option('ic_custom_cname', sanitize_text_field($cname));

								$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_setcname', 'apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)]);
								sleep(10);

								//v6 call:
								#$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_setcname_v6', 'apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)]);
								#sleep(5);

								$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey, 'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);

								// Wait for SSL?
								sleep(6);

								wp_send_json_success(['image' => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg', 'configured' => 'Connected Domain: <strong>' . $cname . '</strong>']);
							}
						}

						wp_send_json_error('invalid-dns-prop');
					} else {
						wp_send_json_error('dns-api-not-working');
					}
				}
			}

			$custom_cname = get_option('ic_custom_cname');
			if (!$custom_cname) {
				$custom_cname = '';
			}

			wp_send_json_success($custom_cname);
		} else {
			$custom_cname = delete_option('ic_custom_cname');

			wp_send_json_success();
		}
	}

	public function retry()
	{
		$cname = get_option('ic_custom_cname');
		$retry_count = get_option('ic_cname_retry_count');

		if (!$retry_count) {
			update_option('ic_cname_retry_count', 1);
		} else {
			update_option('ic_cname_retry_count', $retry_count + 1);
		}

		if ($retry_count >= 3) {
			wp_send_json_error();
		}

		// Wait for SSL?
		sleep(10);

		wp_send_json_success(['image' => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg', 'configured' => 'Connected Domain: <strong>' . $cname . '</strong>']);
	}

	public function remove()
	{
		$cname = get_option('ic_custom_cname');
		$zone_name = get_option('ic_cdn_zone_name');
		$options = get_option(WPS_IC_OPTIONS);
		$apikey = $options['api_key'];

		delete_option('ic_cname_retry_count');

		$requests = new wps_ic_requests();
		$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_removecname', 'apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => time(), 'no_cache' => md5(time())]);

		$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_removecname_v6', 'apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => time(), 'no_cache' => md5(time())]);

		$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'domain' => site_url(), 'apikey' => $options['api_key']]);

		delete_option('ic_custom_cname');

		$settings = get_option(WPS_IC_SETTINGS);
		$settings['cname'] = '';
		$settings['fonts'] = '';
		update_option(WPS_IC_SETTINGS, $settings);

		// Clear cache.
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}

		// Lite Speed
		if (defined('LSCWP_V')) {
			do_action('litespeed_purge_all');
		}

		// HummingBird
		if (defined('WPHB_VERSION')) {
			do_action('wphb_clear_page_cache');
		}

		if (defined('BREEZE_VERSION')) {
			global $wp_filesystem;
			require_once(ABSPATH . 'wp-admin/includes/file.php');

			WP_Filesystem();

			$cache_path = breeze_get_cache_base_path(is_network_admin(), true);
			$wp_filesystem->rmdir(untrailingslashit($cache_path), true);

			if (function_exists('wp_cache_flush')) {
				wp_cache_flush();
			}
		}

		wp_send_json_success();
	}
}