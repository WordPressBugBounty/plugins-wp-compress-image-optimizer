<?php


/**
 * Class - Templates
 */
class wps_ic_templates extends wps_ic {


  public function __construct() {
  }


  public function get_notice($template) {
    include_once WPS_IC_TEMPLATES . 'notices/' . $template . '.php';
  }

  public function get_admin_page($template) {
    include_once WPS_IC_TEMPLATES . 'admin/' . $template . '.php';
  }

}