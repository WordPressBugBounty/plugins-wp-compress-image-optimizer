<?php


class wps_ic_remote_actions extends wps_ic {


  public function __construct() {
  }


  public function restore_all() {
    global $wps_ic, $wpdb;


    if ( ! defined('ABSPATH')) {
      /** Set up WordPress environment */
      require_once(dirname(__FILE__) . '/wp-load.php');
    }

    if ( ! function_exists('download_url')) {
      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');
    }

    if ( ! function_exists('update_option')) {
      require_once(ABSPATH . "wp-includes" . '/option.php');
    }

    // Get COMPRESSED attachment
    $compressed_attachments = $wpdb->get_results("SELECT ID FROM " . $wpdb->prefix . "posts p LEFT JOIN " . $wpdb->prefix . "postmeta pm ON ( pm.post_id = p.ID) WHERE p.post_type='attachment' AND p.post_status='inherit' AND ((pm.post_id = p.ID AND pm.meta_key='wps_ic_compressed' AND pm.meta_value='true')) ORDER BY post_date DESC");

    if ($compressed_attachments) {
      foreach ($compressed_attachments as $attachment) {
        $attachment_id = (int)$attachment->ID;

        $wps_ic->log->write_log($attachment_id, 'Restore Started');

        $compress_data  = get_post_meta($attachment_id, 'wps_ic_data', true);
        $original_image = get_post_meta($attachment_id, 'wps_ic_remote_img', true);

        if ($compress_data == 'not_able') {
          // Already Optimized

          // Generate thumbnails
          $thumbnails = wp_remote_get(site_url('?secret_key=' . $wps_ic::$api_key . '&thumbnails=true&attachment_ID=' . $attachment_id), array('sslverify' => false, 'timeout' => 0.1, 'sslverify' => false));

          // Delete compress data
          delete_post_meta($attachment_id, 'wps_ic_reset');
          delete_post_meta($attachment_id, 'wps_ic_times');
          delete_post_meta($attachment_id, 'wps_ic_compressed');
          delete_post_meta($attachment_id, 'wps_ic_data');
          delete_post_meta($attachment_id, 'wps_ic_cdn');
          delete_post_meta($attachment_id, 'wps_ic_in_bulk');
          delete_post_meta($attachment_id, 'wps_ic_compressing');
          delete_post_meta($attachment_id, 'wps_ic_restoring');

          /// Delete file from compressed table for stats
          $wpdb->update($wpdb->prefix . 'ic_compressed', array('restored' => '1'), array('attachment_ID' => $attachment_id));

          // Remove Queue
          $wps_ic->queue->remove_queue($attachment_id);

          // Add generate thumbnail to queue
          $uploadfile  = get_attached_file($attachment_id);
          $attach_data = wp_generate_attachment_metadata($attachment_id, $uploadfile);
          wp_update_attachment_metadata($attachment_id, $attach_data);

        } else if ($original_image != '') {

          // Fix for old API
          if ( ! preg_match('/https\:/', $original_image)) {
            // Setup URL
            $original_image = WPS_IC_APIURL . '?find_restore=' . $original_image;

            // Fetch the URL
            $call = wp_remote_get($original_image, array('timeout' => 25, 'sslverify' => false));

            if (wp_remote_retrieve_response_code($call) == 200) {
              $body           = wp_remote_retrieve_body($call);
              $body           = json_decode($body);
              $original_image = $body->data;
            } else {
              // Remove Queue
              $wps_ic->queue->remove_queue($attachment_id, false);
              $wps_ic->log->write_log($attachment_id, 'S3 not file');

              // Add generate thumbnail to queue
              $uploadfile  = get_attached_file($attachment_id);
              $attach_data = wp_generate_attachment_metadata($attachment_id, $uploadfile);
              wp_update_attachment_metadata($attachment_id, $attach_data);

              continue;
            }
          }

          // Get file path/name
          $file_data = get_attached_file($attachment_id);
          $file_name = basename($file_data);
          $file_path = str_replace($file_name, '', $file_data);

          // Clear server cache
          clearstatcache();

          $tempfile = download_url($original_image, 60);

          if ($tempfile) {

            $wps_ic->log->write_log($attachment_id, 'Restore Unlink and Copy - Have original image #1507');
            $wps_ic->log->write_log($attachment_id, $original_image);
            $wps_ic->log->write_log($attachment_id, $file_path . $file_name);

            // Copy new file
            if ( ! copy($tempfile, $file_path . $file_name)) {
              $wps_ic->log->write_log($attachment_id, 'Restore Copy Failed');
            } else {
              // Delete the old file
              #unlink($file_path . $file_name);
            }

            // Add generate thumbnail to queue
            $uploadfile  = get_attached_file($attachment_id);
            $attach_data = wp_generate_attachment_metadata($attachment_id, $uploadfile);
            wp_update_attachment_metadata($attachment_id, $attach_data);

          } else {
            // Remove Queue
            $wps_ic->queue->remove_queue($attachment_id, false);
            $wps_ic->log->write_log($attachment_id, 'S3 could not download ' . $original_image);
          }

          /// Delete file from compressed table for stats
          $wpdb->update($wpdb->prefix . 'ic_compressed', array('restored' => '1'), array('attachment_ID' => $attachment_id));

          // Delete compress data
          delete_post_meta($attachment_id, 'wps_ic_reset');
          delete_post_meta($attachment_id, 'wps_ic_times');
          delete_post_meta($attachment_id, 'wps_ic_compressed');
          delete_post_meta($attachment_id, 'wps_ic_data');
          delete_post_meta($attachment_id, 'wps_ic_cdn');
          delete_post_meta($attachment_id, 'wps_ic_in_bulk');
          delete_post_meta($attachment_id, 'wps_ic_compressing');
          delete_post_meta($attachment_id, 'wps_ic_restoring');
          delete_post_meta($attachment_id, 'wps_ic_started');

          // Remove Queue
          $wps_ic->queue->remove_queue($attachment_id, false);

          $wps_ic->log->write_log($attachment_id, 'Restore Add Queue');

          // Get img src
          $image_src = wp_get_attachment_image_src($attachment_id, 'full');

        } else if (empty($original_image)) {
          // Delete compress data
          delete_post_meta($attachment_id, 'wps_ic_reset');
          delete_post_meta($attachment_id, 'wps_ic_times');
          delete_post_meta($attachment_id, 'wps_ic_compressed');
          delete_post_meta($attachment_id, 'wps_ic_data');
          delete_post_meta($attachment_id, 'wps_ic_cdn');
          delete_post_meta($attachment_id, 'wps_ic_in_bulk');
          delete_post_meta($attachment_id, 'wps_ic_compressing');
          delete_post_meta($attachment_id, 'wps_ic_restoring');

          /// Delete file from compressed table for stats
          $wpdb->update($wpdb->prefix . 'ic_compressed', array('restored' => '1'), array('attachment_ID' => $attachment_id));

          // Remove Queue
          $wps_ic->queue->remove_queue($attachment_id);

          // Add generate thumbnail to queue
          $uploadfile  = get_attached_file($attachment_id);
          $attach_data = wp_generate_attachment_metadata($attachment_id, $uploadfile);
          wp_update_attachment_metadata($attachment_id, $attach_data);
        }
      }
    }

    wp_send_json_success('remote-restore-done');
  }


}