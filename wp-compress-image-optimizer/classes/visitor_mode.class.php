<?php

class wps_ic_visitor_mode {

	public function __construct() {

		add_filter( 'wp_headers', function ( $headers ) {
			$headers['wpc_visitor_mode'] = 'true';

			return $headers;
		} );

		//wp_enqueue_script('wpc_visitor_mode_js', WPS_IC_URI . 'assets/js/admin/visitor_mode.min.js', array('jquery'));

	}
}

//override the default WP function to simulate a logged-out user
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id, $name = '' ) {
		global $current_user;

		$current_user = new WP_User( 0, $name );
		setup_userdata( $current_user->ID );
		do_action( 'set_current_user' );

		return $current_user;
	}

}
