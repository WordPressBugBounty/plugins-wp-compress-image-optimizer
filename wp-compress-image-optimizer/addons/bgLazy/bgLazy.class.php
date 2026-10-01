<?php


class wps_ic_bgLazy {


  public function __construct() {
    #add_action( 'elementor/frontend/section/before_render', array($this, 'Elementor_addBgLazy') );
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