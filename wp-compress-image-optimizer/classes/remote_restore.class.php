<?php


/**
 * Class - Remote Restore
 */
class wps_ic_remote_restore extends wps_ic {


  public function __construct() {
    $this->api_url = WPS_IC_APIURL;

    $this->unlock();
    $this->regenerate();
  }


  public static function regenerate_button($id, $action = '') {
    $output = '';

    if (isset($_COOKIE['remote_restore']) && $_COOKIE['remote_restore'] == 'true') {
      $output .= '<button type="button" class="btn btn-success wps-ic-remote-restore" data-image_id="' . $id . '">Remote Restore</button>';
    }

    return $output;
  }


  public static function remote_restore_button($id, $action = '') {
    $output = '';

    if (isset($_COOKIE['regenerate_thumb']) && $_COOKIE['regenerate_thumb'] == 'true') {
      $paged = 1;
      if ( ! empty($_GET['paged'])) {
        $paged = $_GET['paged'];
      }
      $output .= '<a href="' . admin_url('upload.php?paged=' . $paged . '&regenerate=' . $id) . '" target="_blank" class="btn btn-success wps-ic-regenerate" data-image_id="' . $id . '">Regenerate</a>';
    }

    return $output;
  }


  public function regenerate() {
    if ( ! empty($_GET['regenerate_thumb'])) {
      setcookie('regenerate_thumb', 'true', time() + 60 * 60 * 3);
    }
    if ( ! empty($_GET['revoke'])) {
      setcookie('regenerate_thumb', 'false', time() + 60 * 60 * 3);
      unset($_COOKIE['regenerate_thumb']);
    }
  }


  public function unlock() {
    if ( ! empty($_GET['remote_restore'])) {
      setcookie('remote_restore', 'true', time() + 60 * 60 * 3);
    }

    if ( ! empty($_GET['revoke'])) {
      setcookie('remote_restore', 'false', time() + 60 * 60 * 3);
      unset($_COOKIE['remote_restore']);
    }
  }


}