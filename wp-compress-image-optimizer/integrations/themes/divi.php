<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/themes/divi.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wpc_divi {

  public function __construct() {

  }


  public function runIntegration($html) {
    
    
    
    
    
    if (!(function_exists('is_user_logged_in') && is_user_logged_in())) {
      $html = $this->hideSections($html);
      $html = $this->delayBackgrounds($html);
    }
    
    
    return $html;
  }


  public function hideSections($html) {
	  $pattern = '/<div\s+class="([^"]*\bet_pb_section_(?!0)\d+[^"]*)"[^>]*>/';

    $count = 0;
    $html = preg_replace_callback(
      $pattern,
      function ($matches) use (&$count) {
        $count++;
        if ($count > 3) {
          return str_replace($matches[1], $matches[1] . ' wpc-delay-divi', $matches[0]);
        } else {
          return $matches[0];
        }
      },
      $html
    );


	  
	  
	  
	  $html = str_replace('</head>', '<style>.wpc-delay-divi{content-visibility:auto;contain-intrinsic-size:auto 900px;}</style></head>', $html);

    return $html;
  }


	public function insertJS($html){
		$js_file_path = WPS_IC_DIR . 'integrations/js/divi.js';

		if (file_exists($js_file_path)){
			$js_content = file_get_contents($js_file_path);
			$script_tag = "<script type='text/javascript'>\n" . $js_content . "\n</script>\n</head>";
			$html       = str_replace('</head>', $script_tag, $html);
		}
		return $html;
	}


  public function delayBackgrounds($html) {
    $pattern = '/class="((?:(?!et_pb_section_0|et_pb_section_1|)[^"])*?)et_pb_with_background([^"]*?)"/i';
    $html = preg_replace($pattern, 'class="wpc-delay-divi $1et_pb_with_background$2"', $html);
    return $html;
  }


}