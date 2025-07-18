<?php

class ThemeIntegrations extends wps_cdn_rewrite
{

  public static $theme;
  public static $settings;
  public static $page_excludes;

  public function __construct() {
    self::$theme = $this->getThemeName();
  }


  public function getThemeName() {
    $current_theme = wp_get_theme();
    return strtolower($current_theme->get('Name'));
  }


  public function getIntegration($html) {

    self::$settings = parent::$settings;
    self::$page_excludes = parent::$page_excludes;

    if (file_exists(WPS_IC_DIR . 'integrations/themes/' . self::$theme . '.php')) {

      require_once WPS_IC_DIR . 'integrations/themes/' . self::$theme . '.php';
      $className = 'wpc_'.self::$theme;

      if (class_exists($className)) {
        $class = new $className();
        return $class->runIntegration($html);
      }

    } else if (defined('ELEMENTOR_VERSION')) {
      //todo: elementor class is used in multiple places, can't inherit settings from this class
	    $elementor = new wps_ic_elementor();
			return $elementor->runIntegration($html);
    } else {
      return $html;
    }
  }


}