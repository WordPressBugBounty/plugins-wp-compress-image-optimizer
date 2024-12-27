<?php

/**
 * Class - Notices
 */
class wps_ic_notices extends wps_ic {

  public static $slug;
  public static $options;
  public $templates;
  private static $custom_notices = array();

  public function __construct() {
    $this::$slug = parent::$slug;
    $this->templates = new wps_ic_templates();
    add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
  }

  public function show_notice(
    string $title,
    string $message,
    string $type = 'warning',
    bool $global = true,
    string $dismiss_tag = '',
           $info = [],
           $support = false
  ) {
    self::$custom_notices[] = array(
      'title' => $title,
      'message' => $message,
      'type' => $type,
      'global' => $global,
      'dismiss_tag' => $dismiss_tag,
      'info' => $info,
      'support' => $support
    );

    new wps_ic_admin_notice($title, $message, $type, $global, $dismiss_tag, $info, $support);
  }

  public function enqueue_scripts() {
    wp_enqueue_script('wps_admin_notices_script', WPS_IC_URI . 'assets/js/admin/admin-notices.min.js');
    wp_enqueue_style('wps_admin_notices_style', WPS_IC_URI . 'assets/css/admin_notices.min.css');
  }

  public function connect_api_notice() {
    if (get_option('wps_ic_allow_local') == 'true') {
      add_action('admin_notices', array($this, 'activate_list_mode'));
    }

    if (empty(parent::$api_key)) {
      add_action('all_admin_notices', array($this, 'connect_api_message'));
    }
  }

  public function activate_list_mode() {
    $this->templates->get_notice('activate_list_mode');
  }

  public function connect_api_message() {
    $screen = get_current_screen();
    if ($screen->id == 'upload' || $screen->id == 'plugins') {
      $this->templates->get_notice('connect_api_message');
    }
  }

  /**
   * @return void
   * Render all notices, can use wherever we want
   */
  public function render_plugin_notices() {
    foreach (self::$custom_notices as $notice) {
      if ($notice['dismiss_tag'] != '') {
        $notice_dismiss_info = get_option('wps_ic_notice_info');
        if (isset($notice_dismiss_info[$notice['dismiss_tag']]) && $notice_dismiss_info[$notice['dismiss_tag']] == '0') {
          continue;
        }
      }
      wps_ic_admin_notice::render_notice_html(
        $notice['title'],
        $notice['message'],
        $notice['type'],
        $notice['dismiss_tag'],
        $notice['info'],
        $notice['support']
      );
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

  public function __construct($title, $message, $type, $global, $dismiss_tag, $info, $support) {
    $this->title = $title;
    $this->message = $message;
    $this->type = $type;
    $this->tag = $dismiss_tag;
    $this->global = $global;
    $this->info = $info;
    $this->support = $support;

    if ($dismiss_tag != '') {
      $notice_dismiss_info = get_option('wps_ic_notice_info');
      if (isset($notice_dismiss_info[$dismiss_tag]) && $notice_dismiss_info[$dismiss_tag] == '0') {
        return true;
      }
    }

    //Uncomment this to show default wp notices, if you do that, check render_notice() to not show double notices on our page
    //add_action('admin_notices', [$this, 'render_notice']);
  }

  public function render_notice() {
    $screen = get_current_screen();
    if (!$this->global && $screen->id != 'settings_page_wpcompress') {
      return true;
    }

    self::render_notice_html($this->title, $this->message, $this->type, $this->tag, $this->info, $this->support);
  }

  public static function render_notice_html($title, $message, $type, $tag, $info, $support) {
    ?>
      <div class="notice notice-<?php echo $type; ?> wpc-ic-notice wps-ic-tag-<?php echo $tag; ?>">
          <div class="wps-ic-notice-header">
              <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wps-ic-notice-icon"/>
              <h4><?php echo $title; ?></h4>
          </div>
          <div class="wps-ic-notice-content">
              <p><?php echo $message; ?></p>

              <div style="margin-left:auto">
                <?php
                if (!empty($support)) {
                  echo '<a href="https://wpcompress.com/support" target="_blank" class="button-secondary wpc-notice-link" style="margin-right:5px;">Contact Support</a>';
                }
                if (!empty($info)) {
                  echo '<a href="#" class="wps-ic-fix-notice button-secondary" style="margin-right:5px;" data-tag="' .
                    $tag .
                    '" data-plugin="' .
                    $info['plugin'] . '" data-setting="' . $info['setting'] .
                    '">Fix</a>';
                }
                if ($tag) {
                  echo '<a href="#" class="wps-ic-dismiss-notice button-secondary" data-tag="' . $tag .
                    '">Dismiss</a>';
                }
                ?>
              </div>
          </div>
      </div>
    <?php
  }
}