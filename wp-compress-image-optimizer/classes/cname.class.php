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
					// v7.10.500 — CF-LINKED SITES DO NOT USE THE ZONE TARGET. add() validated
					// recordsTarget == $zone_name (the Bunny pull zone), so entering
					// media.yoursite.com on a CF site was rejected before it could ever be linked.
					// When CF is connected we create the record ourselves instead of demanding one.
					$cfa = $this->cf_link_cname($cname, true);
					if ($cfa['code'] !== 'cf-off') {
						if (empty($cfa['ok'])) {
							wp_send_json_error($cfa['code']);
						}
						// v7.10.501 — CF hostnames live in WPS_IC_CF_CNAME ONLY (written by the helper).
						// Writing ic_custom_cname here made a CF host survive a CF disconnect as the
						// emit target via the zone fallback, bypassing the cf.settings.cdn gate — the
						// exact "custom cname they never linked" state. On disconnect the site must
						// fall back to the plain zone host instead.
						$requests = new wps_ic_requests();
						// v7.21.06 — the CF lane never fired the service registration, only the zone
						// lane did: a proxied hostname the customer's Cloudflare answers for is
						// invisible to the DNS audit, so the pull zone never learned it and every
						// cache miss died as a 522 (ridgeway). Registration is idempotent; the
						// service-side weekly heal is the backstop, this is the at-setup guarantee.
						// The host is already stored here (cf_link_cname proved the Cloudflare record), and
						// it is emitted only after the orchestrator's witness, so a cdn_setcname refusal
						// is reported, not undone: the Cloudflare doors and /v2/config own this row.
						$legacyAnswer = $requests->keys('cdn_setcname', ['apikey' => $apikey,
							'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)], 30);
						$v6Answer = $requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_setcname_v6', 'apikey' => $apikey,
							'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)]);
						self::log_twin_registration($cname, $legacyAnswer, $v6Answer, 'cf-add');
						$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey,
							'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);
						wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
						wps_ic_cache_integrations::purgeCombinedFiles();
						wp_send_json_success([
							'image' => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
							'configured' => 'Connected Domain: <strong>' . esc_html($cname) . '</strong>',
							'keys_step' => self::setcname_refused_step($legacyAnswer),
						]);
					}

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
								// Rule: keys is asked before the host is stored, and a refused host is not
								// stored. keys d9b24cde (hub asks 011/044) writes agencySites.cname only
								// when the host is verified on this site's pull zone and otherwise answers
								// success:false with data.step; this lane stored the host first and ignored
								// the answer, so the site emitted a host the CDN row did not name.
								$legacyAnswer = $requests->keys('cdn_setcname', ['apikey' => $apikey, 'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)], 30);
								self::log_twin_registration($cname, $legacyAnswer, null, 'zone-add');
								$refusedStep = self::setcname_refused_step($legacyAnswer);
								if ($refusedStep !== '') {
									wp_send_json_error(['code' => 'keys-refused', 'step' => $refusedStep, 'msg' => self::setcname_refusal_msg($refusedStep, $cname, $zone_name)]);
								}
								update_option('ic_custom_cname', sanitize_text_field($cname));
								wpc_diag_sleep(2, 'cname-add');

								$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey, 'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);

								// Wait for SSL?
								wpc_diag_sleep(2, 'cname-add');

								wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
								wps_ic_cache_integrations::purgeCombinedFiles();

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

	/**
	 * v7.10.500 — ONE CF implementation, shared by add() and retry(). Links $cname to
	 * cdn-mc.zapwp.net, proxied, and retires a previously-managed hostname when the user changes it.
	 * Returns ['ok'=>bool, 'code'=>string, 'msg'=>string, 'proxied'=>bool, 'target'=>string].
	 * 'cf-off' means Cloudflare is not connected — callers fall through to the zone path.
	 */
	/**
	 * v7.10.501 — the hostname we currently manage. CF owns it when CF is connected; otherwise the
	 * zone custom cname. Mirrors the EMIT precedence in enqueues/combine_css so the UI, Refresh and
	 * the served host can never disagree.
	 */
	/**
	 * cname-register {host, via, legacy, v6}: one hostname is registered with keys through two
	 * actions, cdn_setcname and cdn_setcname_v6, because it is not known which one live keys acts
	 * on. Both answers are logged side by side so the journal settles which is the working one.
	 * An answer is ok, refused (success false), text (a non-JSON 200 body), fail (no 200) or
	 * http:<code> for a raw wp_remote_get() response.
	 */
	public static function log_twin_registration($host, $legacyAnswer, $v6Answer, $via)
	{
		if (!function_exists('wpc_belt_receipt')) {
			return;
		}
		$outcome = function ($answer) {
			if ($answer === null) {
				return 'not-sent';
			}
			if ($answer === false) {
				return 'fail';
			}
			if (is_array($answer) && array_key_exists('ok', $answer) && array_key_exists('why', $answer)) {
				return $answer['ok'] ? 'ok' : (string) $answer['why'];
			}
			if (is_wp_error($answer)) {
				return 'fail';
			}
			if (is_array($answer) && isset($answer['response'])) {
				return 'http:' . (int) wp_remote_retrieve_response_code($answer);
			}
			if (is_object($answer)) {
				return (isset($answer->success) && $answer->success === false) ? 'refused' : 'ok';
			}
			return 'text';
		};
		wpc_belt_receipt('cname-register', ['host' => (string) $host, 'via' => (string) $via,
			'legacy' => $outcome($legacyAnswer), 'v6' => $outcome($v6Answer),
			'step' => self::setcname_refused_step($legacyAnswer)], false, '');
	}

	/**
	 * The step of a cdn_setcname refusal, or ''. keys d9b24cde (hub asks 011/044) answers
	 * success:false with data.step (zone_lookup, cname_invalid, registered_on_other_zone,
	 * verify_failed) and writes nothing. Takes a wps_ic_requests::keys() answer, a decoded GET()
	 * body or a raw wp_remote_get() response (mu.class.php). A refusal with no step reads as
	 * 'refused'; timeouts and broken answers are not refusals and read ''.
	 */
	public static function setcname_refused_step($answer)
	{
		if (is_array($answer) && array_key_exists('ok', $answer) && array_key_exists('why', $answer)) {
			if ($answer['ok'] || strpos((string) $answer['why'], 'refused:') !== 0) {
				return '';
			}
			return $answer['step'] !== '' ? $answer['step'] : ($answer['code'] !== '' ? $answer['code'] : 'refused');
		}
		if (is_array($answer) && isset($answer['response'])) {
			if ((int) wp_remote_retrieve_response_code($answer) !== 200) {
				return '';
			}
			$answer = json_decode((string) wp_remote_retrieve_body($answer));
		}
		if (!is_object($answer) || !isset($answer->success) || $answer->success !== false) {
			return '';
		}
		$step = (isset($answer->data) && is_object($answer->data) && isset($answer->data->step) && is_scalar($answer->data->step))
			? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $answer->data->step) : '';
		return $step !== '' ? substr($step, 0, 60) : 'refused';
	}

	/** What a refused step means to the admin; the step is always named for support. */
	public static function setcname_refusal_msg($step, $cname, $zone_name)
	{
		$host = '<strong>' . esc_html($cname) . '</strong>';
		switch ($step) {
			case 'zone_lookup':
				$why = 'could not find the CDN zone of this site';
				break;
			case 'cname_invalid':
				$why = 'did not accept ' . $host . ' as a hostname';
				break;
			case 'registered_on_other_zone':
				$why = 'found ' . $host . ' already registered on another CDN zone';
				break;
			case 'verify_failed':
				$why = 'could not verify ' . $host . ' on the CDN zone of this site (<strong>' . esc_html((string) $zone_name) . '</strong>) yet';
				break;
			default:
				$why = 'refused ' . $host;
		}
		return 'The CDN provisioning server ' . $why . ' (' . esc_html((string) $step) . '). Nothing was registered for this host; press Refresh to try again, or contact support with this code.';
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

	/**
	 * v7.10.502 — is Cloudflare AUTHORITATIVE for this host's zone? A valid token proves permissions,
	 * NOT delegation: a zone can sit in Cloudflare as "Pending Nameserver Update" forever, in which
	 * case API writes succeed, a read-back from the API confirms them, and public DNS never changes —
	 * so no proxying and no certificate. Verifying via the CF API alone reports false success.
	 * Returns ['known'=>bool, 'cf'=>bool, 'ns'=>string[]]; known=false means we could not tell (fail open).
	 */
	private function zone_ns_is_cloudflare($host)
	{
		if (!function_exists('dns_get_record')) {
			return ['known' => false, 'cf' => false, 'ns' => []];
		}
		$labels = explode('.', trim((string) $host, '.'));
		// Walk up to the zone apex — correct for multi-label TLDs (example.co.uk) too.
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

		// Delegation gate — before any write, so a pending zone never gets a phantom record.
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

		// Retire a hostname we previously managed, so changing cdn.x -> media.x does not orphan the
		// old record. SAFETY: only ever delete a CNAME whose content is OUR target — a record the
		// customer created for anything else is never touched.
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

		// Verify by RE-READ, never from the write.
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

		// The API read-back only proves CF stored it. Confirm a public resolver agrees before
		// calling this linked — otherwise a pending zone reports success on an invisible record.
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

		wpc_cf_cname_persist($cname, 'cname-link');
		return ['ok' => true, 'code' => 'cf-ok', 'msg' => '', 'proxied' => $proxied, 'target' => $target];
	}

	// v7.21.11 — THE POD'S 200 IS THE ONLY PROOF. A green Refresh proved DNS, rule write and
	// SSL; none of that proves our optimization servers can fetch this origin — a free-plan
	// Bot Fight Mode site passes every local check and still 403s every pod, silently
	// unoptimized. The keys server's test_origin_fetch has a real pod fetch a nonce file we
	// mint under uploads (uncacheable by construction — an arbitrary asset can false-green
	// off the customer's CF cache), and the verdict becomes words in the UI instead of a
	// silent degrade. Fail-open on every edge: endpoint absent/rate-limited/unreachable ->
	// state 'unknown', message unchanged. Kill filter wpc_origin_fetch_verdict.
	protected static function wpc_origin_fetch_verdict($apikey, $fresh)
	{
		$unknown_verdict = ['state' => 'unknown', 'msg' => ''];
		if (!$fresh || $apikey === '' || !apply_filters('wpc_origin_fetch_verdict', true)
			|| !function_exists('wp_upload_dir') || !function_exists('wp_remote_get')) {
			return $unknown_verdict;
		}
		$uploads = wp_upload_dir();
		if (empty($uploads['basedir']) || empty($uploads['baseurl']) || !is_writable($uploads['basedir'])) {
			return $unknown_verdict;
		}
		$nonce = function_exists('wp_generate_password')
			? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(32, false)))
			: md5(microtime(true) . rand());
		$probe_file = $uploads['basedir'] . '/wpc-verify-' . $nonce . '.txt';
		if (wpc_fs_put($probe_file, $nonce) === false) {
			return $unknown_verdict;
		}
		$r = wp_remote_get(WPS_IC_KEYSURL . '?action=test_origin_fetch&apikey=' . urlencode((string) $apikey)
			. '&nonce=' . urlencode($nonce) . '&time=' . time(),
			['timeout' => (int) apply_filters('wpc_origin_fetch_timeout', 25), 'sslverify' => false,
			 'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'wpc']);
		@unlink($probe_file);
		if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
			return $unknown_verdict;
		}
		$b = json_decode((string) wp_remote_retrieve_body($r), true);
		$d = (is_array($b) && isset($b['data']) && is_array($b['data'])) ? $b['data'] : (is_array($b) ? $b : []);
		$fetch_status = isset($d['status']) ? (int) $d['status'] : 0;
		if ($fetch_status === 200 && !empty($d['body_match'])) {
			return ['state' => 'ok', 'msg' => ' Optimization servers verified — a real fetch from our network reached this site directly.'];
		}
		if ($fetch_status === 403 || !empty($d['cf_mitigated'])) {
			return ['state' => 'challenged', 'msg' => ' <strong>Cloudflare is challenging our optimization servers'
				. (!empty($d['cf_mitigated']) ? ' (managed challenge)' : '') . '.</strong> '
				. 'If Bot Fight Mode is ON under Security &rarr; Bots, note the free plan honors no exceptions — '
				. 'disable it (or upgrade to Super Bot Fight Mode) for full optimization. Until then the site runs '
				. 'in origin-fallback mode: pages stay correct, images stay unoptimized.'];
		}
		return $unknown_verdict;
	}

	/** Retire the CF record we manage. Only deletes a CNAME whose content is our target. */
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
		// Emit host must not survive removal — enqueues/combine_css/comms read this option.
		delete_option(WPS_IC_CF_CNAME);
	}

	public function retry()
	{
		// v7.10.498 — refresh used to verify NOTHING: it slept 2s and returned unconditional success,
		// and its only possible error (retry_count >= 3) made the UI DELETE the CNAME. It now re-runs
		// the same DNS check add() performs, re-fires provisioning so the cert can be issued, probes
		// SSL for real, and never returns an error that means "give up" rather than "not ready yet".
		$cname     = $this->managed_cname();
		$zone_name = trim((string) get_option('ic_cdn_zone_name'));
		$options   = get_option(WPS_IC_OPTIONS);
		$apikey    = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';

		if ($cname === '') {
			wp_send_json_error(['code' => 'no-cname', 'retry' => 0,
				'msg' => 'No linked domain is stored. Add the CNAME again to start over.']);
		}
		// Key/zone problems are reported as such — they are not DNS faults and must not read as one.
		if ($apikey === '') {
			wp_send_json_error(['code' => 'no-apikey', 'retry' => 1,
				'msg' => 'Your API key is missing, so the CDN cannot be reconfigured. Reconnect the key, then press Refresh again. Your CNAME has been left in place.']);
		}
		if ($zone_name === '') {
			wp_send_json_error(['code' => 'no-zone', 'retry' => 1,
				'msg' => 'This site has no CDN zone assigned yet — usually a key that has not finished connecting. Reconnect the key, then press Refresh again.']);
		}

		// Rate limit, NOT a lockout: each press re-fires provisioning + a purge upstream. A user
		// waiting on certificate issuance may legitimately need many checks, so this never fails
		// permanently and never removes anything.
		$last = (int) get_option('ic_cname_retry_at', 0);
		$fresh = (time() - $last) >= (int) apply_filters('wpc_cname_retry_min_interval', 10);
		update_option('ic_cname_retry_at', time(), false);
		$retry_count = (int) get_option('ic_cname_retry_count', 0);
		update_option('ic_cname_retry_count', $retry_count + 1);

		// ── CLOUDFLARE PATH (shared helper — one implementation for add() and retry()) ────────
		$cfr = $this->cf_link_cname($cname, $fresh);
		if ($cfr['code'] !== 'cf-off') {
			if (empty($cfr['ok'])) {
				wp_send_json_error(['code' => $cfr['code'], 'retry' => 1, 'msg' => $cfr['msg']]);
			}
			if ($fresh) {
				$requests = new wps_ic_requests();
				// v7.21.06 — Refresh on a CF-linked host now re-fires the service registration the
				// same way the zone lane always has, so a hostname the pull zone lost (or never
				// learned) heals on the next press instead of waiting for the weekly sweep.
				$legacyAnswer = $requests->keys('cdn_setcname', ['apikey' => $apikey,
					'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)], 30);
				$v6Answer = $requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_setcname_v6', 'apikey' => $apikey,
					'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)]);
				self::log_twin_registration($cname, $legacyAnswer, $v6Answer, 'cf-refresh');
				$cfRefusedStep = self::setcname_refused_step($legacyAnswer);
				$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey,
					'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);
			}
			$ssl_ok = false; $ssl_err = '';
			$probe = wp_remote_head('https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
				['timeout' => 10, 'sslverify' => true, 'redirection' => 2]);
			if (is_wp_error($probe)) { $ssl_err = $probe->get_error_message(); }
			else { $ssl_ok = (int) wp_remote_retrieve_response_code($probe) < 400; }
			if ($ssl_ok) { delete_option('ic_cname_retry_count'); }
			$origin_verdict = self::wpc_origin_fetch_verdict($apikey, $fresh);
			wp_send_json_success([
				'image'      => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg',
				'configured' => 'Connected Domain: <strong>' . esc_html($cname) . '</strong>',
				'path'       => 'cloudflare',
				'dns'        => 'ok',
				'proxied'    => !empty($cfr['proxied']) ? 'yes' : 'no',
				'ssl'        => $ssl_ok ? 'ok' : 'pending',
				'origin_fetch' => $origin_verdict['state'],
				'keys_step'  => isset($cfRefusedStep) ? $cfRefusedStep : '',
				'msg'        => (isset($cfRefusedStep) && $cfRefusedStep !== '' ? self::setcname_refusal_msg($cfRefusedStep, $cname, $zone_name) . ' ' : '')
					. ($ssl_ok ? '' : 'Linked in Cloudflare: <strong>' . esc_html($cname) . '</strong> &rarr; <strong>'
					. esc_html($cfr['target']) . '</strong> (proxied). Cloudflare is still issuing the certificate — press Refresh again shortly.'
					. ($ssl_err !== '' ? ' (' . esc_html($ssl_err) . ')' : ''))
					. $origin_verdict['msg'],
			]);
		}

		// ── ZONE (non-Cloudflare) PATH ───────────────────────────────────────────────────────
		$requests = new wps_ic_requests();

		// 1) RE-VERIFY DNS for the domain the user actually entered — same checker add() uses.
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

		// 2) DNS is correct — re-run provisioning so the zone (re)issues the certificate for this host.
		if ($fresh) {
			// A refusal (keys d9b24cde: the host is not verified on this pull zone; keys wrote nothing)
			// is said, and the stored state is left as it is: the old handler ignored the answer and
			// reported the host as reconfigured.
			$legacyAnswer = $requests->keys('cdn_setcname', ['apikey' => $apikey,
				'cname' => $cname, 'zone_name' => $zone_name, 'time' => microtime(true)], 30);
			self::log_twin_registration($cname, $legacyAnswer, null, 'zone-refresh');
			$refusedStep = self::setcname_refused_step($legacyAnswer);
			if ($refusedStep !== '') {
				wp_send_json_error(['code' => 'keys-refused', 'step' => $refusedStep, 'retry' => 1,
					'msg' => self::setcname_refusal_msg($refusedStep, $cname, $zone_name)]);
			}
			$requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $apikey,
				'domain' => site_url(), 'zone_name' => $zone_name, 'time' => microtime(true)]);
		}

		// 3) Probe SSL for real — sslverify is on, so a hostname/cert mismatch fails here.
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

		// DNS verified + provisioning re-fired = success, even while the cert is still issuing.
		// Report which half is outstanding rather than claiming a blanket success.
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

		// v7.10.500 — retire the CF record too. remove() left it live AND left
		// WPS_IC_CF_CNAME set, which enqueues/combine_css/comms read as the emit host:
		// the site kept emitting from a hostname the UI reported as removed.
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
			wpc_fs_remove_tree(breeze_get_cache_base_path(is_network_admin(), true), true);

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