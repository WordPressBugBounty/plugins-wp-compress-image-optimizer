<?php


class wps_ic_bgLazy {


  public function __construct() {
    
  }


  public function Elementor_addBgLazy( $element ) {
    $element->add_render_attribute(
      '_wrapper',
      [
        'class' => 'wpc-bgLazy',
      ]
    );

  }

}