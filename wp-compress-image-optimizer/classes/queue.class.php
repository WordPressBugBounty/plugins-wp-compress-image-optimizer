<?php


/**
 * Class - Queue
 */
class wps_ic_queue extends wps_ic {

  public static $options;
  public $queue_table;


  public function __construct() {
    if (!is_admin()) return;
  }


  public function remove_queue_type($type) {
    global $wpdb;

    return;
    $type = sanitize_text_field($type);

    $queue = $wpdb->prepare("SELECT attachment_ID FROM " . $wpdb->prefix . "ic_queue WHERE type=%s", $type);
    $queue = $wpdb->get_results($queue);

    if ($queue) {

      foreach ($queue as $row) {
        delete_post_meta($row->attachment_ID, 'wps_ic_data');
        delete_post_meta($row->attachment_ID, 'wps_ic_cdn');
        delete_post_meta($row->attachment_ID, 'wps_ic_times');

        $query = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_queue SET status='-1' WHERE attachment_ID='" . $row->attachment_ID . "'");
        $wpdb->query($query);
      }

      return true;
    }

    return false;
  }


  public function clear_queue($type, $meta = false) {
    global $wpdb, $wps_ic;

    $type = sanitize_text_field($type);

    $delete = $wpdb->prepare("DELETE FROM " . $wpdb->prefix . "ic_queue WHERE type=%s", $type);
    $delete = $wpdb->query($delete);

    if ($delete) {
      return true;
    }

    return false;
  }


  public function add_queue($attachment_id, $type, $status = 'queued') {
    global $wpdb;

    $attachment_id = (int)$attachment_id;
    $type          = sanitize_text_field($type);

    $insert = $wpdb->prepare("INSERT INTO " . $wpdb->prefix . "ic_queue (attachment_ID, type, status, created) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE created=%s, type=%s, status=%s",
                             $attachment_id,
                             $type,
                             $status,
                             current_time('mysql'),
                             current_time('mysql'),
                             $type,
                             $status);


    $insert = $wpdb->query($insert);

    if ($insert) {
      return true;
    } else {
      return false;
    }
  }


  public function update_compress_queue($attachment_id, $status = '1', $new_meta = '') {
    global $wpdb;

    $status        = (int)$status;
    $attachment_id = (int)$attachment_id;

    $update = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_queue SET status=%s, new_meta=%s WHERE attachment_ID=%s", $status, $new_meta, $attachment_id);

    $update = $wpdb->query($update);

    if ($update) {
      return true;
    }

    return false;
  }


  public function remove_restore_queue($attachment_id) {
    $this->remove_queue($attachment_id);
  }


  /**
   * @param $attachment_id
   * @param $status (1 = Success, 0 = In Queue, -1 = Failed, 2 = , 3 = Restoring)
   * @param $message (json)
   */
  public function change_queue_status($attachment_id, $status, $message, $type = '', $queue_id = 0) {
    global $wpdb;
    $attachment_id = (int)$attachment_id;
    $message       = json_encode($message);


    if ($status == 'restored' || $status == 'compressed') {
      $queues_running = get_option('wps_ic_queues_running');
      $queues_running = $queues_running - 1;
      update_option('wps_ic_queues_running', $queues_running);
    }

    if ($type == '') {
      $update = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_queue SET status=%s, message=%s, created=%s WHERE attachment_ID=%s", $status, $message, current_time('mysql'), $attachment_id);
      $update = $wpdb->query($update);
    } else {
      $update = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_queue SET status=%s, message=%s, created=%s WHERE attachment_ID=%s AND type=%s", $status, $message, current_time('mysql'), $attachment_id, $type);
      $update = $wpdb->query($update);
    }

    return true;
  }


  public function remove_queue($attachment_id, $return = true, $type = '') {
    global $wpdb;

    $attachment_id = (int)$attachment_id;

    if ($type == '') {
      $delete = $wpdb->prepare("DELETE FROM " . $wpdb->prefix . "ic_queue WHERE attachment_ID=%s", $attachment_id);
      $delete = $wpdb->query($delete);
    } else {
      $delete = $wpdb->prepare("DELETE FROM " . $wpdb->prefix . "ic_queue WHERE attachment_ID=%s AND type=%s", $attachment_id, $type);
      $delete = $wpdb->query($delete);
    }

    // Delete compress data
    delete_post_meta($attachment_id, 'wps_ic_started');
    delete_post_meta($attachment_id, 'wps_ic_reset');
    delete_post_meta($attachment_id, 'wps_ic_times');
    delete_post_meta($attachment_id, 'wps_ic_in_bulk');
    delete_post_meta($attachment_id, 'wps_ic_compressing');
    delete_post_meta($attachment_id, 'wps_ic_restoring');

    if ($return) {
      if ($delete) {
        return true;
      }
    }

    return false;
  }


  public function remove_compress_queue($attachment_id) {
    $this->remove_queue($attachment_id);
  }


  public function remove_compress_preview_queue($attachment_id) {
    $this->remove_queue($attachment_id);
  }


  public function get_restore_queue($attachment_id = '') {
    return $this->get_queue('restore', $attachment_id);
  }


  public function get_last_queue($type, $attachment_id = '') {
    global $wpdb;

    $type          = $type;
    $attachment_id = (int)$attachment_id;

    $output          = array();
    $args            = array();
    $where_attach_id = '';

    $args[] = $type;

    // Query WHERE clause if param not empty
    if ( ! empty($attachment_id) && $attachment_id != 0) {
      $where_attach_id = ' AND attachment_ID=%s';
      $args[]          = $attachment_id;
    }

    // Prepare query
    $fetch = $wpdb->prepare("SELECT attachment_ID, type, status FROM " . $wpdb->prefix . "ic_queue WHERE type=%s" . $where_attach_id, $args);
    $fetch = $wpdb->get_results($fetch);

    if ($fetch) {
      foreach ($fetch as $row) {
        if ($row->status == 'done') {
          break;
        }
        $output = array('ID' => $row->attachment_ID, 'type' => $row->type, 'status' => $row->status);
        break;
      }
    }

    if ( ! empty($output)) {
      return $output['ID'];
    } else {
      return false;
    }
  }


  public function get_next_queue($type, $attachment_id = '') {
    global $wpdb;

    $type          = $type;
    $attachment_id = (int)$attachment_id;

    $output          = array();
    $args            = array();
    $where_attach_id = '';

    $args[] = $type;

    // Query WHERE clause if param not empty
    if ( ! empty($attachment_id) && $attachment_id != 0) {
      $where_attach_id = ' AND attachment_ID=%s';
      $args[]          = $attachment_id;
    }

    // Prepare query
    $fetch = $wpdb->prepare("SELECT attachment_ID, type, status FROM " . $wpdb->prefix . "ic_queue WHERE type=%s" . $where_attach_id, $args);
    $fetch = $wpdb->get_results($fetch);

    if ($fetch) {
      foreach ($fetch as $row) {
        if ($row->status == 'done') {
          break;
        }
        $output = array('ID' => $row->attachment_ID, 'type' => $row->type, 'status' => $row->status);
        break;
      }
    }

    if ( ! empty($output)) {
      return $output['ID'];
    } else {
      return false;
    }
  }


  public function get_done_queue($type) {
    global $wpdb, $wps_ic;

    if ($type == 'hidden_regenerate') {
      $status      = 'regenerated';
      $statusa     = 'regenerated';
      $queue_typea = 'regenerate';
      $queue_type  = 'hidden_regenerate';
    } else if ($type == 'hidden_restore_bulk') {
      $status      = 'restored';
      $statusa     = 'done';
      $queue_typea = 'restore';
      $queue_type  = 'hidden_restore_bulk';
    } else if ($type == 'hidden_compress_bulk') {
      $status      = 'compressed';
      $statusa     = 'done';
      $queue_typea = 'compress';
      $queue_type  = 'hidden_compress_bulk';
    }

    $queue = $wpdb->prepare("SELECT COUNT(ID) FROM " . $wpdb->prefix . "ic_queue WHERE (status=%s OR status=%s) AND (type=%s OR type=%s)", $status, $statusa, $queue_typea, $queue_type);
    $queue = $wpdb->get_var($queue);

    return $queue;
  }


  public function get_queue($type, $attachment_id = '', $status = 'queued') {
    global $wpdb;

    $type          = $type;
    $attachment_id = (int)$attachment_id;

    $output          = array();
    $args            = array();
    $where_attach_id = '';

    $args[] = $type;
    $args[] = $status;

    // Query WHERE clause if param not empty
    if ( ! empty($attachment_id) && $attachment_id != 0) {
      $where_attach_id = ' AND attachment_ID=%s';
      $args[]          = $attachment_id;
    }

    // Prepare query
    $fetch = $wpdb->prepare("SELECT attachment_ID, type, status, hash FROM " . $wpdb->prefix . "ic_queue WHERE type=%s AND status=%s" . $where_attach_id . " ORDER BY attachment_ID DESC", $args);
    $fetch = $wpdb->get_results($fetch);

    if ($fetch) {
      foreach ($fetch as $row) {
        $output[ $row->attachment_ID ] = array('ID' => $row->attachment_ID, 'type' => $row->type, 'hash' => $row->hash, 'status' => $row->status);
      }
    }

    if ( ! empty($output)) {
      return $output;
    } else {
      return false;
    }
  }


  public function get_hidden_restore_bulk_queue($attachment_id = '') {
    return $this->get_queue('hidden_restore_bulk', $attachment_id);
  }


  public function get_restore_bulk_queue($attachment_id = '') {
    return $this->get_queue('restore_bulk', $attachment_id);
  }


  public function get_hidden_regenerate_bulk_queue($attachment_id = '') {
    return $this->get_queue('hidden_regenerate', $attachment_id);
  }


  public function get_hidden_compress_bulk_queue($attachment_id = '') {
    return $this->get_queue('hidden_compress_bulk', $attachment_id);
  }


  public
  function get_compress_bulk_queue(
    $attachment_id = ''
  ) {
    return $this->get_queue('compress_bulk', $attachment_id);
  }


  public
  function get_compress_queue(
    $attachment_id = ''
  ) {
    return $this->get_queue('compress', $attachment_id);
  }


  public
  function get_compress_preview_queue(
    $attachment_id = ''
  ) {
    return $this->get_queue('compress_preview', $attachment_id);
  }

}