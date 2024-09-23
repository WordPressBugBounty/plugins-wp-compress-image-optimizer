<?php

use Smush\Core\Settings;

class wps_ic_smush extends wps_ic_integrations
{

  public function __construct()
  {
    parent::__construct();
    $this->settings = Settings::get_instance();
    $this->plugin = 'Smush';
  }

  public function do_checks()
  {
    if ( ! method_exists( $this->settings, 'get' ) ){
      return;
    }

    if ( isset($this->wps_settings['lazy']) && $this->wps_settings['lazy'] == '1' ) {
      if ( $this->settings->get('lazy_load') ) {
        $this->report_conflict($this->plugin, 'Lazy Load');
      }
    }

  }

}