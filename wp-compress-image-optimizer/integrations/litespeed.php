<?php

class wps_ic_litespeed extends wps_ic_integrations {

	public function is_active() {
		return is_plugin_active('litespeed-cache/litespeed-cache.php');
	}

	public function do_checks() {
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
		if (is_string($ls_js_excludes_string)) {
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

}