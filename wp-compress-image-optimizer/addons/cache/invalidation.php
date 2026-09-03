<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/invalidation.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



if (!defined('ABSPATH') && !defined('WPC_INV2_CLI')) { exit; }

require_once __DIR__ . '/fingerprint.php';


if (!function_exists('wpc_inv2_enabled')) {
	function wpc_inv2_enabled()
	{
		static $on = null;
		if ($on !== null) { return $on; }
		if (defined('WPC_INV2_DISABLE') && WPC_INV2_DISABLE) { return $on = false; }
		$opt = function_exists('get_option') ? get_option('wpc_invalidation_v2', '0') : '0';
		$on  = function_exists('apply_filters') ? (bool) apply_filters('wpc_invalidation_v2', $opt === '1') : ($opt === '1');
		return $on;
	}
}


if (!function_exists('wpc_inv2_dir')) {
	function wpc_inv2_dir()
	{
		if (!defined('WPS_IC_CRITICAL')) { return ''; }
		$d = rtrim(WPS_IC_CRITICAL, '/') . '/inv2/';
		if (!is_dir($d)) { @mkdir($d, 0777, true); }
		return $d;
	}

	function wpc_inv2_read_json($file, $capBytes = 2097152)
	{
		if ($file === '' || !@is_file($file)) { return null; }
		$sz = @filesize($file);
		if (!$sz || $sz > $capBytes) { return null; }
		$j = json_decode((string) @file_get_contents($file), true);
		return is_array($j) ? $j : null;
	}

	
	function wpc_inv2_put($file, $content)
	{
		if ($file === '') { return false; }
		$d = dirname($file);
		if (!is_dir($d)) { @mkdir($d, 0777, true); }
		$tmp = $file . '.tmp' . getmypid();
		if (@file_put_contents($tmp, $content) === false) { @unlink($tmp); return false; }
		return @rename($tmp, $file);
	}

	function wpc_inv2_write_json($file, $data)
	{
		return wpc_inv2_put($file, json_encode($data));
	}

	function wpc_inv2_ev_file($urlKey, $device, $kind = 'ev')
	{
		if (!defined('WPS_IC_CRITICAL') || (string) $urlKey === '') { return ''; }
		return rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/inv2_' . $kind . '_' . ($device === 'm' ? 'm' : 'd') . '.json';
	}
}


if (!function_exists('wpc_inv2_calibration')) {
	function wpc_inv2_calibration()
	{
		$j = wpc_inv2_read_json(wpc_inv2_dir() . 'calibration.json', 262144);
		return [
			'tokens' => is_array($j) && !empty($j['tokens']) && is_array($j['tokens']) ? array_flip($j['tokens']) : [],
			'sels'   => is_array($j) && !empty($j['sels']) && is_array($j['sels']) ? array_flip($j['sels']) : [],
			'frozen' => is_array($j) && !empty($j['frozen']),
		];
	}

	function wpc_inv2_calibration_save($cal, $why = '')
	{
		$out = [
			'tokens' => array_slice(array_keys($cal['tokens']), 0, 400),
			'sels'   => array_slice(array_keys($cal['sels']), 0, 400),
			'frozen' => !empty($cal['frozen']),
			'why'    => substr((string) $why, 0, 120),
			't'      => time(),
		];
		return wpc_inv2_write_json(wpc_inv2_dir() . 'calibration.json', $out);
	}
}


if (!function_exists('wpc_inv2_evidence')) {

	function wpc_inv2_evidence($html)
	{
		$tokens = [];
		foreach (wpc_fp_struct_tokens($html) as $t) { $tokens[substr(md5($t), 0, 8)] = 1; }
		$bySel = [];
		foreach (wpc_fp_inline_rules($html) as $r) {
			$brace = strrpos($r, '{');
			$sel   = $brace !== false ? trim(substr($r, 0, $brace)) : $r;
			$body  = $brace !== false ? substr($r, $brace) : '';
			$bySel[substr(md5($sel), 0, 8)][substr(md5($body), 0, 8)] = 1;
		}
		$sels = [];
		foreach ($bySel as $s => $bodies) {
			$b = array_keys($bodies);
			sort($b);
			$sels[$s] = substr(md5(implode(',', $b)), 0, 8);
		}
		$epoch = function_exists('get_option') ? (string) get_option('wpc_inv2_epoch', '0') : '0';
		return ['tokens' => $tokens, 'sels' => $sels, 'epoch' => $epoch];
	}

	



	function wpc_inv2_residual($a, $b, $cal)
	{
		$dTok = [];
		foreach ($a['tokens'] as $t => $_) { if (!isset($b['tokens'][$t]) && !isset($cal['tokens'][$t])) { $dTok[$t] = 1; } }
		foreach ($b['tokens'] as $t => $_) { if (!isset($a['tokens'][$t]) && !isset($cal['tokens'][$t])) { $dTok[$t] = 1; } }
		$dSel = [];
		foreach ($a['sels'] as $s => $body) {
			if (isset($cal['sels'][$s])) { continue; }
			if (!isset($b['sels'][$s]) || $b['sels'][$s] !== $body) { $dSel[$s] = 1; }
		}
		foreach ($b['sels'] as $s => $body) {
			if (!isset($a['sels'][$s]) && !isset($cal['sels'][$s])) { $dSel[$s] = 1; }
		}
		return ['tokens' => array_keys($dTok), 'sels' => array_keys($dSel)];
	}
}


if (!function_exists('wpc_inv2_stash')) {
	function wpc_inv2_stash($html)
	{
		try {
			if (!wpc_inv2_enabled() || !is_string($html) || $html === '') { return; }
			if (function_exists('is_admin') && is_admin()) { return; }
			if (function_exists('is_user_logged_in') && is_user_logged_in()) { return; }

			if (defined('DOING_AJAX') && DOING_AJAX) { return; }
			if (defined('DOING_CRON') && DOING_CRON) { return; }
			if (stripos($html, '<body') === false || strlen($html) > 3145728) { return; }
			$GLOBALS['wpc_inv2_ev']     = wpc_inv2_evidence($html);
			$GLOBALS['wpc_inv2_device'] = (function_exists('wp_is_mobile') && wp_is_mobile()) ? 'm' : 'd';
		} catch (\Throwable $e) {
			unset($GLOBALS['wpc_inv2_ev']);
		}
	}
}


if (!function_exists('wpc_inv2_gate_serve')) {
	
	function wpc_inv2_gate_serve($urlKey)
	{
		try {
			if (!wpc_inv2_enabled() || (string) $urlKey === '') { return false; }
			if (empty($GLOBALS['wpc_inv2_ev'])) { return false; }
			static $verdicts = [];
			if (isset($verdicts[$urlKey])) { return $verdicts[$urlKey]; }
			return $verdicts[$urlKey] = (wpc_inv2_verdict($urlKey) === 'stale');
		} catch (\Throwable $e) {
			return false;
		}
	}

	function wpc_inv2_verdict($urlKey)
	{
		$cal = wpc_inv2_calibration();
		if (!empty($cal['frozen'])) { return 'fresh'; }                    
		$ev     = $GLOBALS['wpc_inv2_ev'];
		$device = isset($GLOBALS['wpc_inv2_device']) ? $GLOBALS['wpc_inv2_device'] : 'd';
		$evf    = wpc_inv2_ev_file($urlKey, $device, 'ev');
		if ($evf === '') { return 'none'; }
		$stored = wpc_inv2_read_json($evf);

		
		if (!is_array($stored) || !isset($stored['tokens'], $stored['sels'])) {
			wpc_inv2_write_json($evf, $ev);
			wpc_inv2_log('inv2-adopt', $urlKey, $device . ' t=' . count($ev['tokens']) . ' s=' . count($ev['sels']));
			return 'fresh';
		}

		
		if ((string) $stored['epoch'] !== (string) $ev['epoch']) {
			if (function_exists('wpc_repull_kick_now')) { wpc_repull_kick_now($urlKey); }
			wpc_inv2_log('inv2-stale', $urlKey, $device . ' epoch ' . $stored['epoch'] . '→' . $ev['epoch']);
			wpc_inv2_churn('stale', $urlKey);
			return 'stale';
		}

		$res = wpc_inv2_residual($ev, $stored, $cal);
		if (empty($res['tokens']) && empty($res['sels'])) {
			@touch(wpc_inv2_ev_file($urlKey, $device, 'fresh'));
			return 'fresh';
		}


		$pf      = wpc_inv2_ev_file($urlKey, $device, 'pending');
		$pending = wpc_inv2_read_json($pf);
		$lastFresh = (int) @filemtime(wpc_inv2_ev_file($urlKey, $device, 'fresh'));
		if (is_array($pending) && isset($pending['t'], $pending['ev'])
			&& (time() - (int) $pending['t']) < 86400) {
			$between = wpc_inv2_residual($ev, $pending['ev'], $cal);


			if (empty($between['tokens']) && empty($between['sels'])
				&& $lastFresh > (int) $pending['t']) {
				$nT = count($res['tokens']); $nS = count($res['sels']);
				if ($nT <= 80 && $nS <= 100) {
					foreach ($res['tokens'] as $t) { $cal['tokens'][$t] = 1; }
					foreach ($res['sels'] as $s)   { $cal['sels'][$s] = 1; }
					wpc_inv2_calibration_save($cal, 'oscillation: +' . $nT . 'tok +' . $nS . 'sels');
					@unlink($pf);
					wpc_inv2_log('inv2-selfcal', $urlKey, $device . ' +' . $nT . 't+' . $nS . 's');
					wpc_inv2_churn('cal');
					return 'fresh';
				}
			}


			if (!empty($between['sels']) && empty($between['tokens'])
				&& count($between['sels']) <= 20
				&& !array_diff($between['sels'], $res['sels'])) {
				foreach ($between['sels'] as $s) { $cal['sels'][$s] = 1; }
				wpc_inv2_calibration_save($cal, 'rotation: +' . count($between['sels']) . 'sels');
				wpc_inv2_log('inv2-selfcal', $urlKey, $device . ' rot+' . count($between['sels']) . 's');
				wpc_inv2_churn('cal');
				$res = wpc_inv2_residual($ev, $stored, $cal);
				if (empty($res['tokens']) && empty($res['sels'])) {
					@unlink($pf);
					return 'fresh';
				}

			}
		}
		wpc_inv2_write_json($pf, ['t' => time(), 'ev' => $ev]);
		
		

		wpc_inv2_put(wpc_inv2_ev_file($urlKey, $device, 'want'), (string) time());
		if (function_exists('wpc_repull_kick_now')) { wpc_repull_kick_now($urlKey); }
		wpc_inv2_log('inv2-stale', $urlKey, $device . ' dt=' . count($res['tokens']) . ' ds=' . count($res['sels']));
		wpc_inv2_churn('stale', $urlKey);
		return 'stale';
	}
}


if (!function_exists('wpc_inv2_on_land')) {
	function wpc_inv2_on_land($urlKey)
	{
		try {
			if (!wpc_inv2_enabled() || (string) $urlKey === '') { return; }
			foreach (['d', 'm'] as $dev) {
				@unlink(wpc_inv2_ev_file($urlKey, $dev, 'ev'));
				@unlink(wpc_inv2_ev_file($urlKey, $dev, 'pending'));
				@unlink(wpc_inv2_ev_file($urlKey, $dev, 'fresh'));
				@unlink(wpc_inv2_ev_file($urlKey, $dev, 'want'));
			}
			wpc_inv2_log('inv2-land-reset', $urlKey, '');
		} catch (\Throwable $e) {
		}
	}
}


if (!function_exists('wpc_inv2_crit_soft_purge')) {
	
	function wpc_inv2_crit_soft_purge($ctx, $urlKey = '')
	{
		try {
			if (!wpc_inv2_enabled()) { return false; }


			if (function_exists('wpc_repull_kick_now')) { wpc_repull_kick_now((string) $urlKey); }
			wpc_inv2_log('inv2-softpurge', (string) $urlKey, (string) $ctx);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}
}


if (!function_exists('wpc_inv2_churn')) {
	function wpc_inv2_churn($kind, $urlKey = '')
	{
		try {
			$f = wpc_inv2_dir() . 'churn.json';
			$j = wpc_inv2_read_json($f, 262144);
			$e = is_array($j) && !empty($j['events']) && is_array($j['events']) ? $j['events'] : [];
			$e[] = ['t' => time(), 'k' => (string) $kind, 'u' => substr(md5((string) $urlKey), 0, 8)];
			$e = array_slice($e, -100);
			wpc_inv2_write_json($f, ['events' => $e]);
			$hourAgo = time() - 3600; $dayAgo = time() - 86400;
			$perKey = []; $calDay = 0;
			foreach ($e as $ev) {
				if ($ev['k'] === 'stale' && $ev['t'] >= $hourAgo) {
					$ku = isset($ev['u']) ? (string) $ev['u'] : '';
					$perKey[$ku] = isset($perKey[$ku]) ? $perKey[$ku] + 1 : 1;
				}
				if ($ev['k'] === 'cal' && $ev['t'] >= $dayAgo) { $calDay++; }
			}


			$maxKeyHr = empty($perKey) ? 0 : max($perKey);
			if ($maxKeyHr >= 4 || $calDay >= 4) {
				$cal = wpc_inv2_calibration();
				if (empty($cal['frozen'])) {
					$cal['frozen'] = true;
					wpc_inv2_calibration_save($cal, 'CB: max-key-stale/hr=' . $maxKeyHr . ' cal/day=' . $calDay);
					wpc_inv2_log('inv2-frozen', '', 'max-key-stale/hr=' . $maxKeyHr . ' cal/day=' . $calDay);
				}
			}
		} catch (\Throwable $e) {
		}
	}

	
	function wpc_inv2_reset($why = '')
	{
		try {
			if (!defined('WPS_IC_CRITICAL')) { return; }
			@unlink(wpc_inv2_dir() . 'calibration.json');
			@unlink(wpc_inv2_dir() . 'churn.json');
			foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/inv2_ev_*.json') as $f) { @unlink($f); }
			foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/inv2_pending_*.json') as $f) { @unlink($f); }
			foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/inv2_fresh_*.json') as $f) { @unlink($f); }
			foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/inv2_want_*.json') as $f) { @unlink($f); }
			wpc_inv2_log('inv2-reset', '', (string) $why);
		} catch (\Throwable $e) {
		}
	}
}

if (!function_exists('wpc_inv2_log')) {
	function wpc_inv2_log($event, $key = '', $detail = '')
	{
		if (function_exists('wpc_cache_first_log')) {
			wpc_cache_first_log($event, (string) $key, '', ['d' => substr((string) $detail, 0, 100)]);
		}
	}
}
