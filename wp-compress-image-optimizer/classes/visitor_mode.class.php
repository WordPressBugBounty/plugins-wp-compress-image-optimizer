<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/visitor_mode.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */


class wps_ic_visitor_mode {

	public function __construct() {

		add_filter( 'wp_headers', function ( $headers ) {
			$headers['wpc_visitor_mode'] = 'true';

			return $headers;
		} );


	}
}


if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id, $name = '' ) {
		global $current_user;

		$current_user = new WP_User( 0, $name );
		setup_userdata( $current_user->ID );
		do_action( 'set_current_user' );

		return $current_user;
	}

}
