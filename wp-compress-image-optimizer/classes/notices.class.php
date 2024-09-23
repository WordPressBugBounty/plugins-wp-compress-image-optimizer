<?php


/**
 * Class - Notices
 */
class wps_ic_notices extends wps_ic {

	public static $slug;
	public static $options;
	public $templates;

	public function __construct() {

		$this::$slug     = parent::$slug;
		$this->templates = new wps_ic_templates();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		#$this->show_notice( 'Testing Notices', 'Hello, this is a test!', 'error', true, 'test-notice12' );
	}

	/**
	 * Show admin notice
	 *
	 * @param $title string Notice title
	 * @param $message string Notice message
	 * @param $type string Type of notice (warning,error,success,info)
	 * @param $global bool Show in all admin or just our page
	 * @param $dismiss_tag string Name of the option to save dismiss info to
	 */
	public function show_notice(
		string $title, string $message, string $type = 'warning', bool $global = true,
		string $dismiss_tag = '', $info = [], $support = false
	) {
		new wps_ic_admin_notice( $title, $message, $type, $global, $dismiss_tag, $info, $support );
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'wps_admin_notices_script', WPS_IC_URI . 'assets/js/admin/admin-notices.min.js' );
		wp_enqueue_style( 'wps_admin_notices_style', WPS_IC_URI . 'assets/css/admin_notices.min.css' );
	}

	public function connect_api_notice() {
		if ( get_option( 'wps_ic_allow_local' ) == 'true' ) {
			add_action( 'admin_notices', array( $this, 'activate_list_mode' ) );
		}

		if ( empty( parent::$response_key ) ) {
			add_action( 'all_admin_notices', array( $this, 'connect_api_message' ) );
		}

	}


	/**
	 * Render List Media Library Notice
	 */
	public function activate_list_mode() {
		$this->templates->get_notice( 'activate_list_mode' );
	}

	/**
	 * Render Api Connect Notice
	 */
	public function connect_api_message() {

		$screen = get_current_screen();
		if ( $screen->id == 'upload' || $screen->id == 'plugins' ) {
			$this->templates->get_notice( 'connect_api_message' );
		}
	}


}

class wps_ic_admin_notice {

	private $title;
	private $message;
	private $type;
	private $tag;
	private $global;
	private $info;
    private $support;

	function __construct( $title, $message, $type, $global, $dismiss_tag, $info, $support) {
		$this->title   = $title;
		$this->message = $message;
		$this->type    = $type;
		$this->tag     = $dismiss_tag;
		$this->global  = $global;
		$this->info    = $info;
        $this->support = $support;

		if ( $dismiss_tag != '' ) {
			$notice_dismiss_info = get_option( 'wps_ic_notice_info' );
			if ( isset( $notice_dismiss_info[ $dismiss_tag ] ) && $notice_dismiss_info[ $dismiss_tag ] == '0' ) {
				return true;
			}
		}

		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	function render_notice() {
		$screen = get_current_screen();
		if ( ! $this->global && $screen->id != 'settings_page_wpcompress' ) {
			return true;
		}
		?>
      <div class="notice notice-<?php echo $this->type; ?> wpc-ic-notice wps-ic-tag-<?php echo $this->tag; ?>">
          <div class="wps-ic-notice-header">
              <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wps-ic-notice-icon"/>
              <h4><?php echo $this->title; ?></h4>
          </div>
          <div class="wps-ic-notice-content">
              <p><?php echo $this->message; ?></p>

              <div style="margin-left:auto">
								<?php
                                if (!empty($this->support)){
	                                echo '<a href="https://wpcompress.com/support" target="_blank" class="button-secondary wpc-notice-link" style="margin-right:5px;">Contact Support</a>';
                                }
			                    if ( !empty($this->info) ) {
                                    echo '<a href="#" class="wps-ic-fix-notice button-secondary" style="margin-right:5px;" data-tag="' .
                                         $this->tag .
                                         '" data-plugin="' .
                                         $this->info['plugin'] . '" data-setting="' . $this->info['setting'] .
                                         '">Fix</a>';
                                }
								if ( $this->tag ) {
									echo '<a href="#" class="wps-ic-dismiss-notice button-secondary" data-tag="' . $this->tag .
									     '">Dismiss</a>';
								} ?>
              </div>
          </div>
      </div>
		<?php

	}
}