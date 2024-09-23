<?php


/**
 * Class - Controller
 */
class wps_ic_controller {

	public $options;


	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		// check for activated conflict plugins
		#add_action('admin_notices', array(__CLASS__, 'wps_display_admin_notices'));

		// display errors, if any
		if ( get_option( 'wpc_errors' ) ) {
			#add_action('admin_notices', array(__CLASS__, 'wps_display_admin_errors'));
		}

	}

	public static function wps_display_admin_notices() {
		$extraStyle = "style='border-left: 4px solid#ff0000;'";
		$plugins    = self::get_conflicts();
		if ( count( $plugins ) ) {
			?>
            <div class='notice notice-warning' id='wp-compress-notice-conflict' <?php echo( $extraStyle ); ?>>
                <h3>WP Compress - Conflicts found
                </h3>Using WP Compress while other image optimization plugins are active can lead to corrupt images or
                unpredictable results. We recommend to deactivate the following plugin(s):
				<?php
				echo( '<ul>' );
				foreach ( $plugins as $plugin ) {
					echo( '<li class="wps-ic-conflict-plugin"><strong>' . $plugin['name'] . '</strong>' );
					echo( '<a href="' . admin_url( 'plugins.php' ) . '" class="button">Deactivate</a>' );
				}
				echo( "</ul>" );
				?>
            </div>
			<?php
		}
	}

	/**
	 * Find other similar plugins for notice
	 * @return array
	 */
	public static function get_conflicts() {

		$conflict_plugins = array(
			'WP Smush Pro - Image Optimization'    => 'wp-smush-pro/wp-smush.php',
			'WP Smush - Image Optimization'        => 'wp-smushit/wp-smush.php',
			'Imagify Image Optimizer'              => 'imagify/imagify.php',
			'Compress JPEG & PNG images (TinyPNG)' => 'tiny-compress-images/tiny-compress-images.php',
			'Kraken.io Image Optimizer'            => 'kraken-image-optimizer/kraken.php',
			'Optimus - WordPress Image Optimizer'  => 'optimus/optimus.php',
			'EWWW Image Optimizer'                 => 'ewww-image-optimizer/ewww-image-optimizer.php',
			'ImageRecycle pdf & image compression' => 'imagerecycle-pdf-image-compression/wp-image-recycle.php',
			'CheetahO Image Optimizer'             => 'cheetaho-image-optimizer/cheetaho.php',
			'Zara 4 Image Compression'             => 'zara-4/zara-4.php',
			'Prizm Image'                          => 'prizm-image/wp-prizmimage.php',
			'CW Image Optimizer'                   => 'cw-image-optimizer/cw-image-optimizer.php',
			'ShortPixel'                           => 'shortpixel-image-optimiser/wp-shortpixel.php'
		);

		$found = array();

		// Go through plugin lists
		foreach ( $conflict_plugins as $name => $path ) {
			if ( is_plugin_active( $path ) ) {
				$found[] = array( 'name' => $name, 'path' => $path );
			}
		}

		return $found;
	}

	public function wps_display_admin_errors() {
		$extraStyle = "style='border-left: 4px solid#ff0000;'";
		$errors     = self::get_errors();
		if ( $errors !== false ) {
			?>
            <div class='notice notice-warning' id='wp-compress-notice-conflict' <?php echo( $extraStyle ); ?>>
                <h3>WP Compress - Encountered Errors</h3>
                We have found some critical errors with your server setup. We were unable to create a backup directory
                in location:
				<?php echo $errors['unable-to-create-backup-dir']; ?>
            </div>
			<?php
		}
	}

	public static function get_errors() {
		$errors = get_option( 'wpc_errors' );
		if ( ! empty( $errors ) ) {
			return $errors;
		} else {
			return false;
		}
	}


}