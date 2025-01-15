<?php

class wps_ic_perfmatters extends wps_ic_integrations {

	public function is_active() {
		return function_exists( 'perfmatters_version_check' );
	}

  public function getConflictList()
  {
    $perfmatters_options = get_option( 'perfmatters_options' );
    $conflict = [];

    if ( $this->wps_settings['delay-js'] && ! empty( $perfmatters_options['assets']['delay_js'] ) && $perfmatters_options['assets']['delay_js'] ) {
      $conflict[] ='delay-js';
    }

    if ( $this->wps_settings['lazy'] && ! empty( $perfmatters_options['lazyload']['lazy_loading'] ) &&
      $perfmatters_options['lazyload']['lazy_loading'] ) {
      $conflict[] ='lazy';
    }

    return $conflict;
  }

	public function do_checks() {
		// Logic to check for conflicts
		$perfmatters_options = get_option( 'perfmatters_options' );

		if ( $this->wps_settings['delay-js'] && ! empty( $perfmatters_options['assets']['delay_js'] ) && $perfmatters_options['assets']['delay_js'] ) {
			$this->notices_class->show_notice( 'WPCompress - Delay JS conflict detected',
				'Click "Fix" to use WPCompress and disable Perfmatters setting, or "Dismiss" to continue.',
				'warning', true, 'wpc_perfmatters_delay_js_dismiss_tag', [
					'plugin'  => 'perfmatters',
					'setting' => [
						'delay_js'
					]
				] );

		}

		if ( $this->wps_settings['lazy'] && ! empty( $perfmatters_options['lazyload']['lazy_loading'] ) &&
		     $perfmatters_options['lazyload']['lazy_loading'] ) {
			$this->notices_class->show_notice( 'WPCompress - Lazy Load conflict detected',
				'Click "Fix" to use WPCompress and disable Perfmatters setting, or "Dismiss" to continue.',
				'warning', true, 'wpc_perfmatters_lazyload_dismiss_tag', [
					'plugin'  => 'perfmatters',
					'setting' => [
						'lazy_loading'
					]
				] );

		}

	}

	public function fix_setting( $setting ) {
		$perfmatters_options = get_option( 'perfmatters_options' );

		if ( $setting == 'delay_js' ) {
			$perfmatters_options['assets']['delay_js'] = 0;
		} else if ( $setting == 'lazyload' ) {
			$perfmatters_options['lazyload']['lazy_loading'] = 0;
		}

		return update_option( 'perfmatters_options', $perfmatters_options );
	}

}