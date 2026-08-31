<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/fingerprint.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



if (!defined('ABSPATH') && !defined('WPC_FP_CLI')) { exit; } 

if (!function_exists('wpc_fp_struct_tokens')) {

	function wpc_fp_struct_tokens($html) {
		$tokens = array();

		if (preg_match_all('/(?<![\w-])class=["\']([^"\']*)["\']/i', $html, $cm)) {
			foreach ($cm[1] as $v) {
				foreach (preg_split('/\s+/', trim($v)) as $t) {
					if ($t !== '') { $tokens['.' . $t] = 1; }
				}
			}
		}

		if (preg_match_all('/<([a-z0-9]+)\b[^>]*?(?<![\w-])id=["\']([^"\']+)["\']/i', $html, $im, PREG_SET_ORDER)) {
			foreach ($im as $m) {
				$tag = strtolower($m[1]);
				if ($tag === 'style' || $tag === 'link' || $tag === 'script') { continue; }
				$id = trim($m[2]);
				if ($id !== '') { $tokens['#' . $id] = 1; }
			}
		}

		return array_keys($tokens);
	}
}

if (!function_exists('wpc_fp_inline_rules')) {

	function wpc_fp_inline_rules($html) {
		$rules = array();
		if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $sm)) {
			foreach ($sm[1] as $css) {
				$css = preg_replace('/\/\*.*?\*\//s', '', $css);
				foreach (explode('}', $css) as $unit) {
					$u = trim(preg_replace('/\s+/', ' ', $unit));
					if ($u !== '') { $rules[] = $u . '}'; }
				}
			}
		}
		return $rules;
	}
}

if (!function_exists('wpc_fp_calibrate')) {

	function wpc_fp_calibrate($htmlA, $htmlB) {
		$ta = array_flip(wpc_fp_struct_tokens($htmlA));
		$tb = array_flip(wpc_fp_struct_tokens($htmlB));
		$exTok = array();
		foreach ($ta as $t => $_) { if (!isset($tb[$t])) { $exTok[$t] = 1; } }
		foreach ($tb as $t => $_) { if (!isset($ta[$t])) { $exTok[$t] = 1; } }

		$ra = array_flip(wpc_fp_inline_rules($htmlA));
		$rb = array_flip(wpc_fp_inline_rules($htmlB));
		$exRule = array();
		foreach ($ra as $r => $_) { if (!isset($rb[$r])) { $exRule[$r] = 1; } }
		foreach ($rb as $r => $_) { if (!isset($ra[$r])) { $exRule[$r] = 1; } }

		return array('tokens' => array_keys($exTok), 'rules' => array_keys($exRule));
	}
}

if (!function_exists('wpc_fp_struct_sig')) {
	
	function wpc_fp_struct_sig($html, $exclTokens = array()) {
		$ex = array_flip($exclTokens);
		$keep = array();
		foreach (wpc_fp_struct_tokens($html) as $t) { if (!isset($ex[$t])) { $keep[] = $t; } }
		sort($keep);
		return substr(sha1(implode("\n", $keep)), 0, 16);
	}
}

if (!function_exists('wpc_fp_css_sig')) {
	




	function wpc_fp_css_sig($enqueued, $html, $exclRules = array()) {
		$ex = array_flip($exclRules);
		$keepSet = array();
		
		
		foreach (wpc_fp_inline_rules($html) as $r) { if (!isset($ex[$r])) { $keepSet[$r] = 1; } }
		$keep = array_keys($keepSet);
		sort($keep);
		$enq = is_array($enqueued) ? $enqueued : array();
		sort($enq);
		return substr(sha1(implode("\n", $enq) . "\x1e" . sha1(implode("\n", $keep))), 0, 16);
	}
}

if (!function_exists('wpc_fp_page_key')) {
	
	function wpc_fp_page_key($html, $enqueued, $calib = array()) {
		$s = wpc_fp_struct_sig($html, isset($calib['tokens']) ? $calib['tokens'] : array());
		$c = wpc_fp_css_sig($enqueued, $html, isset($calib['rules']) ? $calib['rules'] : array());
		return $s . '-' . $c;
	}
}
