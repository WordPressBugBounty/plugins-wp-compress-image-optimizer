<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wpc_avada {

  public function __construct() {

  }


  public function runIntegration($html) {

    // Only the delay-v3 loader removes .wpc-delay-avada (insertJS() is dead code, never called),
    // and checkCache() skips the rewriter for every logged-in request — so a logged-in admin got
    // the deferral with nothing to undo it. content-visibility keeps the content REACHABLE, but
    // the page still opens short and grows while scrolling. Defer only when it will be undone.
    if (!(function_exists('is_user_logged_in') && is_user_logged_in())) {
      $html = $this->hideSections($html);
    }
    #$html = $this->delayBackgrounds($html);
    // dead reveal swap removed: 'optimize.js' never matches optimizer.* filenames and the
    // avada/ dist dir is gone — with display:none that left rows hidden FOREVER
    return $html;
  }


  public function hideSections($html) {

	  $pattern = '/<div\s+class="([^"]*\bfusion-builder-row-(?:[3-9]\d*|\d{3,})\b[^"]*)"[^>]*>/';

	  $html = preg_replace_callback($pattern, function ($matches){
			  return str_replace($matches[1], $matches[1] . ' wpc-delay-avada', $matches[0]);
	  }, $html);


	  // content-visibility:auto = same below-fold render deferral, NO reveal JS needed;
	  // old browsers ignore the property and render normally (fail-open by construction)
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