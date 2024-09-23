<?php

class wps_ic_compatibility {

	public function check( $settings ) {
		$this->settings = $settings;
		$current_theme  = wp_get_theme();

		//Avada theme
		if ( 'avada' === strtolower( $current_theme->get( 'Name' ) ) || 'avada' === strtolower( $current_theme->get_template() ) ) {
			$this->checkAvada();
		}

		//Amelia booking
		if (defined('AMELIA_PATH')){
			//test this if amelia is not working
			//$this->settings['serve']['js'] = 0;
			//$this->settings['js'] = 0;
		}

		return $this->settings;
	}

	public function checkAvada() {

		$avada_options = get_option( 'fusion_options' );
		if ( !empty($avada_options['js_compiler']) && $avada_options['js_compiler'] == 1 ) {
			$this->settings['js_combine'] = 0;
		}
		
		if ( !empty($avada_options['critical_css']) && $avada_options['critical_css'] == 1 ) {
			$this->settings['critical']['css'] = 0;
		}

		if ( !empty($avada_options['css_cache_method']) && $avada_options['css_cache_method'] != 'off' ) {
			$this->settings['css_combine'] = 0;
		}

		if ( !empty($avada_options['lazy_load']) && $avada_options['lazy_load'] != 'none' ) {
			$this->settings['lazy'] = 0;
		}

	}
}