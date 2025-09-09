<?php


class wps_minifyHtml
{
  
  public function __construct() {
  }


  public function minifyCSS($css) {
    // Remove spaces after colons
    $css = str_replace(': ', ':', $css);

    // Remove whitespace
    $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);

    $css = preg_replace('/\/\*(.*?)\*\//s', '', $css); // Remove comments
    $css = preg_replace('/\s+/', ' ', $css); // Remove multiple whitespaces
    $css = preg_replace('/\s?([,:;{}])\s?/', '$1', $css); // Remove spaces around selectors and declarations
    $css = preg_replace('/;}/', '}', $css); // Remove trailing semicolons before closing brace

    return $css;
  }

  
  public function minify($buffer)
  {
    $search = [
        '/\>[^\S ]+/s',     // strip whitespaces after tags, except space
        '/[^\S ]+\</s',     // strip whitespaces before tags, except space
    ];
    
    $replace = [
        '>',
        '<',
        //'\\1',
        ''
    ];

    $buffer = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $buffer);

    $buffer = preg_replace($search, $replace, $buffer);

    #$buffer = $this->minifyCSS($buffer);

    return $buffer;
  }

  
  
}