<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wpc_divi {

  public function __construct() {

  }


  public function runIntegration($html) {
    // Only the delay-v3 loader removes .wpc-delay-divi (insertJS() is dead code, never called),
    // and checkCache() skips the rewriter for every logged-in request — so a logged-in admin got
    // the deferral with nothing to undo it. content-visibility keeps the content REACHABLE, but
    // the page still opens short and grows while scrolling. Defer only when it will be undone.
    // Receipted on Elementor as hawkeye.design; same shape here.
    if (!(function_exists('is_user_logged_in') && is_user_logged_in())) {
      $html = $this->hideSections($html);
      $html = $this->delayBackgrounds($html);
    }
    // dead reveal swap removed: 'optimize.js' never matches optimizer.* filenames and the
    // divi/ dist dir is gone — with display:none that left sections hidden FOREVER
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


	  // content-visibility:auto = same below-fold render/bg-fetch deferral as the old
	  // display:none, but needs NO reveal JS: browsers paint on approach, old browsers
	  // ignore the property and render normally (fail-open by construction)
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