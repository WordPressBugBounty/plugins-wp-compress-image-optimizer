<?php


/**
 * Class - AMP
 * Handles AMP
 */
class wps_ic_amp
{

  public static $isAmp;

  public function __construct()
  {
    self::$isAmp = false;

    // Is AMP?
    if (function_exists('amp_is_request')) {
      self::$isAmp = $_GET['wpc_is_amp'] = amp_is_request();
    }

    if (defined('AMPFORWP_VERSION')) {
      if (function_exists('ampforwp_is_amp_endpoint')) {
        self::$isAmp = ampforwp_is_amp_endpoint();
      }
    }

    if (!empty($_GET['simulateAmp'])) {
      self::$isAmp = true;
    }
  }


	public function isAmp() {
    return self::$isAmp;
  }

}