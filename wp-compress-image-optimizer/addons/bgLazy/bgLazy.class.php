<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/bgLazy/bgLazy.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



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