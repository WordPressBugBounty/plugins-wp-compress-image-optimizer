<?php

class wps_ic_autoptimize extends wps_ic_integrations
{

  public function __construct()
  {
    parent::__construct();
    $this->settings_img = get_option( 'autoptimize_imgopt_settings' );
    $this->settings_css = get_option( 'autoptimize_css_defer' ); //doesn't break anything, but used without critical flashes no css site on load
    $this->plugin = 'Autoptimize';
  }

  public function do_checks()
  {

    if ( isset($this->wps_settings['lazy']) && $this->wps_settings['lazy'] == '1' ) {
      if (  $this->settings_img['autoptimize_imgopt_checkbox_field_3'] ) {
        $this->report_conflict($this->plugin, 'Lazy Load');
      }
    }

  }

}