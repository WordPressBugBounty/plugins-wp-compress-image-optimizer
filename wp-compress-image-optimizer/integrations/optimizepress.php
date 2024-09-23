<?php

class wps_ic_optimizepress extends wps_ic_integrations {

	public function is_active() {
		return defined( 'OP3_VERSION' );
	}

	public function do_checks() {

	}

	public function fix_setting( $setting ) {

	}

	public function do_frontend_filters() {
		return [
			'op3_script_is_allowed_in_blank_template' => [
				'callback' => 'allowWPCScripts',
				'priority' => 10,
				'args'     => 2
			]
			// ... add other frontend hooks if any
		];
	}

	public function add_admin_hooks(){
		return [
			'op3_clear_all_cache' => [
				'callback' => 'clear_cache',
				'priority' => '',
				'args'     => ''
			]
// ... add other admin hooks if any
		];
	}

	public function allowWPCScripts( $value, $handle ) {
		if ( $handle == 'wpcompress-aio' ) {
			return true;
		}

		return $value;
	}

	public function clear_cache(){
		$cache = new wps_ic_cache_integrations();
		$cache::purgeAll();
	}

}