<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/amp.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */







class wps_ic_amp
{

  public static $isAmp;

  public function __construct()
  {
    self::$isAmp = false;

    
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


	public function isAmp($html = '') {

		if (!empty($html)){
			if (preg_match('/<html[^>]*\samp[^>]*>/i', $html)) {
				self::$isAmp = true;
			}
		}

    return self::$isAmp;
  }

}