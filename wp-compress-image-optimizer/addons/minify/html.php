<?php


class wps_minifyHtml
{

  public function __construct() {
  }


  public function minifyCSS($css) {
    
    $css = str_replace(': ', ':', $css);

    
    $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);

    $css = preg_replace('/\/\*(.*?)\*\//s', '', $css); 
    $css = preg_replace('/\s+/', ' ', $css); 
    $css = preg_replace('/\s?([,:;{}])\s?/', '$1', $css); 
    $css = preg_replace('/;}/', '}', $css); 

    return $css;
  }


  public function minify($buffer)
  {
    $search = [
        '/\>[^\S ]+/s',
        '/[^\S ]+\</s',
    ];

    $replace = [
        '>',
        '<',

        ''
    ];

    $buffer = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $buffer);

    $buffer = preg_replace($search, $replace, $buffer);

    

    return $buffer;
  }


}