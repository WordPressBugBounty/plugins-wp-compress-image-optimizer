<?php

class wpc_avada {

  public function __construct() {

  }


  public function runIntegration($html) {

    $html = $this->hideSections($html);
    #$html = $this->delayBackgrounds($html);
	  #$html = $this->insertJS($html);

      return str_replace('optimize.js', 'avada/optimize.js', $html);
  }


  public function hideSections($html) {

	  $pattern = '/<div\s+class="([^"]*\bfusion-builder-row-(?:[3-9]\d*|\d{3,})\b[^"]*)"[^>]*>/';

	  $html = preg_replace_callback($pattern, function ($matches){
			  return str_replace($matches[1], $matches[1] . ' wpc-delay-avada', $matches[0]);
	  }, $html);

		
	  $html = str_replace('</head>', '<style>.wpc-delay-avada{display:none!important;}</style></head>', $html);

	  return $html;
  }


  public function delayBackgrounds($html) {
    // Define the class you want to find and the class you want to replace it with
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