<?php

class wps_ic_wpoptimize extends wps_ic_integrations
{

  public function __construct()
  {
    parent::__construct();
    $this->cache_settings = WPO_Cache_Config::instance()->get();
    $this->plugin = 'Smush';
  }

  public function do_checks()
  {
/* Maybe we dont care about cache
    if ( isset($this->wps_settings['cache']['html']) && $this->wps_settings['cache']['html'] == '1' ) {
      if ( $this->cache_settings['enable_page_caching'] ) {
        $this->report_conflict($this->plugin, 'Cache');
      }
    }
*/
  }

}