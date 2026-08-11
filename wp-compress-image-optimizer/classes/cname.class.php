<?php

class wps_ic_cname
{
	public function __construct()
	{
		
	}

	public function add($cname_input = null)
	{
		$zone_name = get_option('ic_cdn_zone_name');

		delete_option('ic_cname_retry_count');

		if (!empty($cname_input)) {
			$error = '';
			$options = get_option(WPS_IC_OPTIONS);
			$apikey = $options['api_key'];

			
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
					
					$error = 'This domain is invalid, please link a new domain...';
					delete_option('ic_custom_cname');
					$settings = get_option(WPS_IC_SETTINGS);
					unset($settings['cname']);
					update_option(WPS_IC_SETTINGS, $settings);
					wp_send_json_error('invalid-domain');
				} else {
					
					
					
					
					$cfa = $this->cf_link_cname($cname, true);
					if ($cfa['code'] !== 'cf-off') {
						if (empty($cfa['ok'])) {
							wp_send_json_error($cfa['code']);
						}
						
						
						
						
						
						$requests = new wps_ic_requests();
						$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey,
							'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);
						wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
						wps_ic_cache_integrations::purgeCombinedFiles();
						wp_send_json_success([
							'image' => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
							'configured' => 'Connected Domain: <strong>' . esc_html($cname) . '</strong>',
						]);
					}

					
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
								wpc_diag_sleep(2, 'cname-add');

								$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey, 'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);

								
								wpc_diag_sleep(2, 'cname-add');

								wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
								wps_ic_cache_integrations::purgeCombinedFiles();


								if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead()) {
									wps_ic_cache_integrations::purgeCriticalFiles();
								}

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

	





	




	private function managed_cname()
	{
		$cf = get_option(WPS_IC_CF);
		if (is_array($cf) && !empty($cf['token']) && !empty($cf['zone'])) {
			$h = trim((string) get_option(WPS_IC_CF_CNAME));
			if ($h !== '') {
				return $h;
			}
		}
		return trim((string) get_option('ic_custom_cname'));
	}

	






	private function zone_ns_is_cloudflare($host)
	{
		if (!function_exists('dns_get_record')) {
			return ['known' => false, 'cf' => false, 'ns' => []];
		}
		$labels = explode('.', trim((string) $host, '.'));
		
		for ($i = 0; $i < count($labels) - 1; $i++) {
			$candidate = implode('.', array_slice($labels, $i));
			$rec = @dns_get_record($candidate, DNS_NS);
			if (is_array($rec) && $rec) {
				$ns = [];
				foreach ($rec as $r) {
					if (!empty($r['target'])) { $ns[] = strtolower(rtrim((string) $r['target'], '.')); }
				}
				if (!$ns) { continue; }
				$cf = false;
				foreach ($ns as $n) {
					if (strpos($n, 'ns.cloudflare.com') !== false || substr($n, -14) === '.cloudflare.com') {
						$cf = true;
						break;
					}
				}
				return ['known' => true, 'cf' => $cf, 'ns' => $ns];
			}
		}
		return ['known' => false, 'cf' => false, 'ns' => []];
	}

	private function cf_link_cname($cname, $write = true)
	{
		$target = (string) apply_filters('wpc_cf_cname_target', 'cdn-mc.zapwp.net');
		$cf = get_option(WPS_IC_CF);
		if (!is_array($cf) || empty($cf['token']) || empty($cf['zone'])
			|| !apply_filters('wpc_cname_cf_autolink', true)) {
			return ['ok' => false, 'code' => 'cf-off', 'msg' => '', 'proxied' => false, 'target' => $target];
		}
		if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
			@include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
		}
		if (!class_exists('WPC_CloudflareAPI')) {
			return ['ok' => false, 'code' => 'cf-sdk-missing', 'proxied' => false, 'target' => $target,
				'msg' => 'Could not load the Cloudflare client. Nothing was changed — try again.'];
		}

		
		$ns = $this->zone_ns_is_cloudflare($cname);
		if (!empty($ns['known']) && empty($ns['cf'])) {
			return ['ok' => false, 'code' => 'cf-not-authoritative', 'proxied' => false, 'target' => $target,
				'msg' => 'Cloudflare is not the DNS provider for this domain — it is delegated to <strong>'
					. esc_html(implode(', ', array_slice($ns['ns'], 0, 2)))
					. '</strong>. Your token is valid, but a record created in Cloudflare would never resolve, so it '
					. 'cannot be proxied and no certificate can be issued. Either point the domain\'s nameservers at '
					. 'Cloudflare, or use the CDN hostname without a custom domain.'];
		}

		$cfsdk = new WPC_CloudflareAPI($cf['token']);

		
		
		
		$prev = trim((string) get_option(WPS_IC_CF_CNAME));
		if ($write && $prev !== '' && strcasecmp($prev, $cname) !== 0) {
			$old = $cfsdk->findDNSRecord($cf['zone'], $prev, 'CNAME');
			if (is_array($old) && !empty($old['id']) && isset($old['content'])
				&& strcasecmp(rtrim((string) $old['content'], '.'), $target) === 0) {
				$cfsdk->deleteDNSRecord($cf['zone'], $old['id']);
			}
		}

		if ($write) {
			$res = $cfsdk->addCfCname($cf['zone'], $cname);
			if (is_wp_error($res)) {
				return ['ok' => false, 'code' => 'cf-api-error', 'proxied' => false, 'target' => $target,
					'msg' => 'Cloudflare rejected the change: ' . esc_html($res->get_error_message())
						. ' Check the API token has DNS:Edit and Zone:Read on this zone. Your CNAME was left in place.'];
			}
		}

		
		$rec     = $cfsdk->findDNSRecord($cf['zone'], $cname, 'CNAME');
		$content = is_array($rec) && isset($rec['content']) ? rtrim((string) $rec['content'], '.') : '';
		$proxied = is_array($rec) && !empty($rec['proxied']);

		if (!is_array($rec)) {
			return ['ok' => false, 'code' => 'cf-record-missing', 'proxied' => false, 'target' => $target,
				'msg' => 'No CNAME for <strong>' . esc_html($cname) . '</strong> exists in this Cloudflare zone yet. If the token lacks DNS:Edit it cannot be created — check the token, then press Refresh.'];
		}
		if (strcasecmp($content, $target) !== 0) {
			return ['ok' => false, 'code' => 'cf-wrong-target', 'proxied' => $proxied, 'target' => $target,
				'msg' => '<strong>' . esc_html($cname) . '</strong> points at <strong>' . esc_html($content !== '' ? $content : 'nothing')
					. '</strong> in Cloudflare, but it must point at <strong>' . esc_html($target) . '</strong>.'];
		}
		if (!$proxied) {
			$fix = $cfsdk->updateDNSRecord($cf['zone'], $rec['id'],
				['type' => 'CNAME', 'name' => $cname, 'content' => $target, 'ttl' => 1, 'proxied' => true]);
			if (is_wp_error($fix)) {
				return ['ok' => false, 'code' => 'cf-not-proxied', 'proxied' => false, 'target' => $target,
					'msg' => '<strong>' . esc_html($cname) . '</strong> is DNS-only (grey cloud) and could not be switched to proxied: '
						. esc_html($fix->get_error_message()) . ' Without the orange cloud there is no HTTPS for this host.'];
			}
			$rec     = $cfsdk->findDNSRecord($cf['zone'], $cname, 'CNAME');
			$proxied = is_array($rec) && !empty($rec['proxied']);
		}

		
		
		if (function_exists('dns_get_record')) {
			$pub = @dns_get_record($cname, DNS_CNAME);
			$seen = '';
			if (is_array($pub)) {
				foreach ($pub as $r) {
					if (!empty($r['target'])) { $seen = strtolower(rtrim((string) $r['target'], '.')); break; }
				}
			}
			if ($seen !== '' && strcasecmp($seen, $target) !== 0) {
				return ['ok' => false, 'code' => 'cf-public-dns-mismatch', 'proxied' => $proxied, 'target' => $target,
					'msg' => 'Cloudflare now holds the correct record, but public DNS still returns <strong>'
						. esc_html($seen) . '</strong> for <strong>' . esc_html($cname) . '</strong>. If the domain is '
						. 'not delegated to Cloudflare this will never change; otherwise wait for propagation and press Refresh again.'];
			}
		}

		update_option(WPS_IC_CF_CNAME, $cname);
		return ['ok' => true, 'code' => 'cf-ok', 'msg' => '', 'proxied' => $proxied, 'target' => $target];
	}

	
	private function cf_unlink_cname()
	{
		$target = (string) apply_filters('wpc_cf_cname_target', 'cdn-mc.zapwp.net');
		$cf = get_option(WPS_IC_CF);
		$host = trim((string) get_option(WPS_IC_CF_CNAME));
		if ($host === '' || !is_array($cf) || empty($cf['token']) || empty($cf['zone'])
			|| !apply_filters('wpc_cname_cf_autolink', true)) {
			delete_option(WPS_IC_CF_CNAME);
			return;
		}
		if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
			@include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
		}
		if (class_exists('WPC_CloudflareAPI')) {
			$cfsdk = new WPC_CloudflareAPI($cf['token']);
			$rec = $cfsdk->findDNSRecord($cf['zone'], $host, 'CNAME');
			if (is_array($rec) && !empty($rec['id']) && isset($rec['content'])
				&& strcasecmp(rtrim((string) $rec['content'], '.'), $target) === 0) {
				$cfsdk->deleteDNSRecord($cf['zone'], $rec['id']);
			}
		}
		
		delete_option(WPS_IC_CF_CNAME);
	}

	public function retry()
	{
		
		
		
		
		$cname     = $this->managed_cname();
		$zone_name = trim((string) get_option('ic_cdn_zone_name'));
		$options   = get_option(WPS_IC_OPTIONS);
		$apikey    = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';

		if ($cname === '') {
			wp_send_json_error(['code' => 'no-cname', 'retry' => 0,
				'msg' => 'No linked domain is stored. Add the CNAME again to start over.']);
		}
		
		if ($apikey === '') {
			wp_send_json_error(['code' => 'no-apikey', 'retry' => 1,
				'msg' => 'Your API key is missing, so the CDN cannot be reconfigured. Reconnect the key, then press Refresh again. Your CNAME has been left in place.']);
		}
		if ($zone_name === '') {
			wp_send_json_error(['code' => 'no-zone', 'retry' => 1,
				'msg' => 'This site has no CDN zone assigned yet — usually a key that has not finished connecting. Reconnect the key, then press Refresh again.']);
		}

		
		
		
		$last = (int) get_option('ic_cname_retry_at', 0);
		$fresh = (time() - $last) >= (int) apply_filters('wpc_cname_retry_min_interval', 10);
		update_option('ic_cname_retry_at', time(), false);
		$retry_count = (int) get_option('ic_cname_retry_count', 0);
		update_option('ic_cname_retry_count', $retry_count + 1);

		
		$cfr = $this->cf_link_cname($cname, $fresh);
		if ($cfr['code'] !== 'cf-off') {
			if (empty($cfr['ok'])) {
				wp_send_json_error(['code' => $cfr['code'], 'retry' => 1, 'msg' => $cfr['msg']]);
			}
			if ($fresh) {
				$requests = new wps_ic_requests();
				$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey,
					'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);
			}
			$ssl_ok = false; $ssl_err = '';
			$probe = wp_remote_head('https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
				['timeout' => 10, 'sslverify' => true, 'redirection' => 2]);
			if (is_wp_error($probe)) { $ssl_err = $probe->get_error_message(); }
			else { $ssl_ok = (int) wp_remote_retrieve_response_code($probe) < 400; }
			if ($ssl_ok) { delete_option('ic_cname_retry_count'); }
			wp_send_json_success([
				'image'      => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
				'configured' => 'Connected Domain: <strong>' . esc_html($cname) . '</strong>',
				'path'       => 'cloudflare',
				'dns'        => 'ok',
				'proxied'    => !empty($cfr['proxied']) ? 'yes' : 'no',
				'ssl'        => $ssl_ok ? 'ok' : 'pending',
				'msg'        => $ssl_ok ? '' : 'Linked in Cloudflare: <strong>' . esc_html($cname) . '</strong> &rarr; <strong>'
					. esc_html($cfr['target']) . '</strong> (proxied). Cloudflare is still issuing the certificate — press Refresh again shortly.'
					. ($ssl_err !== '' ? ' (' . esc_html($ssl_err) . ')' : ''),
			]);
		}

		
		$requests = new wps_ic_requests();

		
		$body = $requests->GET('https://frankfurt.zapwp.net/', ['dnsCheck' => 'true', 'host' => $cname,
			'zoneName' => $zone_name, 'hash' => microtime(true)], ['timeout' => 30]);
		if (empty($body) || empty($body->data)) {
			wp_send_json_error(['code' => 'dns-api-not-working', 'retry' => 1,
				'msg' => 'Could not reach the DNS checker just now. Nothing was changed — press Refresh again in a moment.']);
		}
		$data   = (array) $body->data;
		$rec    = isset($data['records']) ? $data['records'] : null;
		$type   = is_object($rec) && isset($rec->type) ? strtoupper((string) $rec->type) : '';
		$target = is_object($rec) && isset($rec->target) ? rtrim((string) $rec->target, '.') : '';
		$expect = rtrim($zone_name, '.');

		if ($type === '') {
			wp_send_json_error(['code' => 'dns-not-propagated', 'retry' => 1,
				'msg' => 'No DNS record found yet for <strong>' . esc_html($cname) . '</strong>. Add a CNAME pointing to <strong>' . esc_html($expect) . '</strong>, then press Refresh. Propagation can take up to an hour.']);
		}
		if ($type !== 'CNAME') {
			wp_send_json_error(['code' => 'wrong-record-type', 'retry' => 1,
				'msg' => '<strong>' . esc_html($cname) . '</strong> is a ' . esc_html($type) . ' record. It must be a CNAME pointing to <strong>' . esc_html($expect) . '</strong>.']);
		}
		if (strcasecmp($target, $expect) !== 0) {
			wp_send_json_error(['code' => 'wrong-target', 'retry' => 1,
				'msg' => '<strong>' . esc_html($cname) . '</strong> points to <strong>' . esc_html($target !== '' ? $target : 'nothing') . '</strong>, but it must point to <strong>' . esc_html($expect) . '</strong>.']);
		}

		
		if ($fresh) {
			$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_setcname', 'apikey' => $apikey,
				'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)]);
			$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey,
				'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);
		}

		
		$ssl_ok  = false;
		$ssl_err = '';
		$probe = wp_remote_head('https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
			['timeout' => 10, 'sslverify' => true, 'redirection' => 2]);
		if (is_wp_error($probe)) {
			$ssl_err = $probe->get_error_message();
		} else {
			$ssl_ok = (int) wp_remote_retrieve_response_code($probe) < 400;
		}

		$settings = get_option(WPS_IC_SETTINGS);
		if (is_array($settings) && (empty($settings['cname']) || $settings['cname'] !== $cname)) {
			$settings['cname'] = $cname;
			update_option(WPS_IC_SETTINGS, $settings);
		}

		if ($ssl_ok) {
			delete_option('ic_cname_retry_count');
		}

		
		
		wp_send_json_success([
			'image'      => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
			'configured' => 'Connected Domain: <strong>' . esc_html($cname) . '</strong>',
			'dns'        => 'ok',
			'ssl'        => $ssl_ok ? 'ok' : 'pending',
			'msg'        => $ssl_ok ? '' : 'DNS is correct and the CDN was reconfigured. The HTTPS certificate is still being issued for '
				. esc_html($cname) . ' — this usually takes a few minutes. Press Refresh again shortly.'
				. ($ssl_err !== '' ? ' (' . esc_html($ssl_err) . ')' : ''),
		]);
	}


	public function remove($respond = true)
	{
		$cname = get_option('ic_custom_cname');
		$zone_name = get_option('ic_cdn_zone_name');
		$options = get_option(WPS_IC_OPTIONS);
		$apikey = is_array($options) && !empty($options['api_key']) ? $options['api_key'] : '';

		delete_option('ic_cname_retry_count');
		delete_option('ic_cname_retry_at');

		
		
		
		$this->cf_unlink_cname();

		$requests = new wps_ic_requests();
		$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_removecname', 'apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => time(), 'no_cache' => md5(time())]);

		$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_removecname_v6', 'apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => time(), 'no_cache' => md5(time())]);

		$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'domain' => site_url(), 'apikey' => $options['api_key']]);

		delete_option('ic_custom_cname');

		$settings = get_option(WPS_IC_SETTINGS);
		$settings['cname'] = '';
		$settings['fonts'] = '';
		update_option(WPS_IC_SETTINGS, $settings);

		
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}

		
		if (defined('LSCWP_V')) {
			do_action('litespeed_purge_all');
		}

		
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
				if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
			}
		}

		if ($respond) {
			wp_send_json_success();
		}
		return true;
	}
}