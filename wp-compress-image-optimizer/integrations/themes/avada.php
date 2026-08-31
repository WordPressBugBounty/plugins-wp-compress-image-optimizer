<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/themes/avada.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wpc_avada {

  public function __construct() {

  }


  public function runIntegration($html) {

    
    
    
    
    if (!(function_exists('is_user_logged_in') && is_user_logged_in())) {
      $html = $this->hideSections($html);
    }
    
    
    
    return $html;
  }


  public function hideSections($html) {

	  $pattern = '/<div\s+class="([^"]*\bfusion-builder-row-(?:[3-9]\d*|\d{3,})\b[^"]*)"[^>]*>/';

	  $html = preg_replace_callback($pattern, function ($matches){
			  return str_replace($matches[1], $matches[1] . ' wpc-delay-avada', $matches[0]);
	  }, $html);


	  
	  
	  $html = str_replace('</head>', '<style>.wpc-delay-avada{content-visibility:auto;contain-intrinsic-size:auto 900px;}</style></head>', $html);

	  return $html;
  }


  public function delayBackgrounds($html) {

    return $html;
  }


	public function insertJS($html){
		$js_file_path = WPS_IC_DIR . 'integrations/js/avada.js';

		if (file_exists($js_file_path)){
			$js_content = file_get_contents($js_file_path);
			$script_tag = "<script type='text/javascript'>\n" . $js_content . "\n</script>\n</head>";
			$html       = str_replace('</head>', $script_tag, $html);
		}
		return $html;
	}


}