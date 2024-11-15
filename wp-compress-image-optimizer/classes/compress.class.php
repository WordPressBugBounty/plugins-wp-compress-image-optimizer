<?php


/**
 * Class - Compress
 */
class wps_ic_compress {


  public function __construct() {
    if (!is_admin()) return;
  }


  /**
   * @since 3.3.0
   */
  public static function get_queue() {
    global $wpdb;

    $queue_transient = get_option('wps_ic_compress_info');

    if ($queue_transient['action'] == 'Regenerating') {
      $compress_queue = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) as files FROM " . $wpdb->prefix . "ic_queue WHERE type=%s", 'hidden_regenerate'));

      $done = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) as files FROM " . $wpdb->prefix . "ic_queue WHERE type=%s AND (status=%s)", 'hidden_regenerate', 'regenerated'));
    } else if ($queue_transient['action'] == 'Compressing') {
      // Get files in compress queue
      $compress_queue = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) as files FROM " . $wpdb->prefix . "ic_queue WHERE type=%s", 'hidden_compress_bulk'));

      $done = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) as files FROM " . $wpdb->prefix . "ic_queue WHERE type=%s AND (status=%s OR status=%s)", 'hidden_compress_bulk', 'done', 'compressed'));
    } else {
      // Get files in compress queue
      $compress_queue = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) as files FROM " . $wpdb->prefix . "ic_queue WHERE type=%s", 'hidden_restore_bulk'));

      $done = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) as files FROM " . $wpdb->prefix . "ic_queue WHERE type=%s AND (status=%s OR status=%s)", 'hidden_restore_bulk', 'done', 'restored'));
    }

    // Get files done

    return ['queue' => $compress_queue, 'done' => $done, 'total' => $queue_transient['total'], 'action' => $queue_transient['action']];
  }


  /**
   * @since 3.3.0
   */
  public function hidden_restore($attachment_id) {
    global $wps_ic, $wpdb;
    $attachment_id = (int)$attachment_id;

    // Remove Queue
    $wps_ic->queue->change_queue_status($attachment_id, 'restoring', ['code' => 'restoring']);
    delete_option('wps_ic_restore_queue_status');

    $reset = get_post_meta($attachment_id, 'wps_ic_reset', true);
    update_post_meta($attachment_id, 'wps_ic_restored', 'true');

    if ( ! empty($reset) && $reset == 'true') {
      $this->reset($attachment_id, '');
    }

    if ( ! function_exists('download_url')) {
      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');
    }

    if ( ! function_exists('update_option')) {
      require_once(ABSPATH . "wp-includes" . '/option.php');
    }

    // Find image source on site
    $image     = wp_get_attachment_image_src($attachment_id, 'full');
    $file_name = basename($image[0]);

    $call = wp_remote_get(WPS_IC_APIURL . '?get_restore=true&site=' . site_url('/') . '&attachment_id=' . $attachment_id . '&filename=' . $file_name, ['timeout' => 25, 'sslverify' => false]);

    $original_image = wp_remote_retrieve_body($call);
    $original_image = json_decode($original_image, true);
    $original_image = $original_image['data'];

    $compress_data = get_post_meta($attachment_id, 'wps_ic_data', true);
    $file_data     = get_attached_file($attachment_id);

    if ($compress_data == 'not_able') {

      // Delete compress data
      delete_post_meta($attachment_id, 'wps_ic_compressed_size');
      delete_post_meta($attachment_id, 'wps_ic_reset');
      delete_post_meta($attachment_id, 'wps_ic_times');
      delete_post_meta($attachment_id, 'wps_ic_compressed');
      delete_post_meta($attachment_id, 'wps_ic_data');
      delete_post_meta($attachment_id, 'wps_ic_cdn');
      delete_post_meta($attachment_id, 'wps_ic_in_bulk');
      delete_post_meta($attachment_id, 'wps_ic_compressing');
      delete_post_meta($attachment_id, 'wps_ic_restoring');
      delete_post_meta($attachment_id, 'wps_ic_dimmensions');

      $this->restore_wpml_image($attachment_id);

      // Remove Queue
      $wps_ic->queue->change_queue_status($attachment_id, 'restored', ['code' => 'not_able']);
    } else if ( ! empty($file_data) && ! empty($original_image)) {

      // Fix for old API

      // Verify we can get to the original Image
      $call = wp_remote_get($original_image, ['timeout' => 60, 'sslverify' => false]);

      if (wp_remote_retrieve_response_code($call) == 200) {
        // Original Image is accessible
      } else {
        if (empty($original_image) || $original_image == '') {
          // Setup URL
          $original_image = WPS_IC_APIURL . '?find_restore=true&site=' . site_url('/') . '&filename=' . $file_name;

          $call = wp_remote_get($original_image, ['timeout' => 60, 'sslverify' => false]);

          if (wp_remote_retrieve_response_code($call) == 200) {
            $body           = wp_remote_retrieve_body($call);
            $body           = json_decode($body);
            $original_image = $body->data;
          }
        }
      }

      // File path
      $file_name = basename($file_data);
      $file_path = str_replace($file_name, '', $file_data);

      clearstatcache();

      // Copy backup file to dest
      $tempfile = download_url($original_image, 60);

      if ($tempfile) {
        // Delete the old file
        unlink($file_path . $file_name);

        // Copy file from Amazon
        copy($tempfile, $file_path . $file_name);
        unlink($tempfile);
      } else {

        // Remove from Bulk Compress Background
        $wps_ic->queue->change_queue_status($attachment_id, 'restored', ['code' => 'remote_404']);
      }

      $this->restore_wpml_image($attachment_id);

      /// Delete file from compressed table for stats
      $wpdb->update($wpdb->prefix . 'ic_compressed', ['restored' => '1'], ['attachment_ID' => $attachment_id]);

      // Delete compress data
      delete_post_meta($attachment_id, 'wps_ic_started');
      delete_post_meta($attachment_id, 'wps_ic_reset');
      delete_post_meta($attachment_id, 'wps_ic_times');
      delete_post_meta($attachment_id, 'wps_ic_compressed');
      delete_post_meta($attachment_id, 'wps_ic_data');
      delete_post_meta($attachment_id, 'wps_ic_cdn');
      delete_post_meta($attachment_id, 'wps_ic_in_bulk');
      delete_post_meta($attachment_id, 'wps_ic_compressing');
      delete_post_meta($attachment_id, 'wps_ic_restoring');
      delete_post_meta($attachment_id, 'wps_ic_dimmensions');

      $uploadfile         = get_attached_file($attachment_id);
      $metadata           = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
      $imagesize          = getimagesize($uploadfile);
      $metadata['width']  = $imagesize[0];
      $metadata['height'] = $imagesize[1];
      update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
      update_post_meta($attachment_id, 'wps_ic_dimmensions', $metadata);

      // Remove Queue
      $wps_ic->queue->change_queue_status($attachment_id, 'restored', ['code' => 'restored']);
      $wps_ic->queue->add_queue($attachment_id, 'regenerate_thumbnail');

    } else {

      // Delete compress data
      delete_post_meta($attachment_id, 'wps_ic_started');
      delete_post_meta($attachment_id, 'wps_ic_reset');
      delete_post_meta($attachment_id, 'wps_ic_times');
      delete_post_meta($attachment_id, 'wps_ic_compressed');
      delete_post_meta($attachment_id, 'wps_ic_data');
      delete_post_meta($attachment_id, 'wps_ic_cdn');
      delete_post_meta($attachment_id, 'wps_ic_in_bulk');
      delete_post_meta($attachment_id, 'wps_ic_compressing');
      delete_post_meta($attachment_id, 'wps_ic_restoring');
      delete_post_meta($attachment_id, 'wps_ic_dimmensions');

      $uploadfile         = get_attached_file($attachment_id);
      $metadata           = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
      $imagesize          = getimagesize($uploadfile);
      $metadata['width']  = $imagesize[0];
      $metadata['height'] = $imagesize[1];
      update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
      update_post_meta($attachment_id, 'wps_ic_dimmensions', $metadata);

      $this->restore_wpml_image($attachment_id);

      // Remove Queue
      $wps_ic->queue->change_queue_status($attachment_id, 'restored', ['code' => 'backup_empty']);
    }
  }


  /**
   * @since 3.3.0
   */
  public function reset($attachment_id, $response = 'json') {
    global $wps_ic;

    $logo_uncompressed = WPS_IC_URI . 'assets/images/not-compressed.png';

    // Delete compress data
    delete_post_meta($attachment_id, 'wps_ic_compressed_size');
    delete_post_meta($attachment_id, 'wps_ic_started');
    delete_post_meta($attachment_id, 'wps_ic_reset');
    delete_post_meta($attachment_id, 'wps_ic_times');
    delete_post_meta($attachment_id, 'wps_ic_compressed');
    delete_post_meta($attachment_id, 'wps_ic_data');
    delete_post_meta($attachment_id, 'wps_ic_cdn');
    delete_post_meta($attachment_id, 'wps_ic_in_bulk');
    delete_post_meta($attachment_id, 'wps_ic_compressing');
    delete_post_meta($attachment_id, 'wps_ic_restoring');
    delete_post_meta($attachment_id, 'wps_ic_dimmensions');

    // Add generate thumbnail to queue
    $uploadfile         = get_attached_file($attachment_id);
    $metadata           = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
    $imagesize          = getimagesize($uploadfile);
    $metadata['width']  = $imagesize[0];
    $metadata['height'] = $imagesize[1];
    update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);

    // Remove Queue
    $wps_ic->queue->change_queue_status($attachment_id, 'reset', ['code' => 'reset']);

    if ($response == 'json') {
      wp_send_json_success();
    } else {
      if ($response == 'table') {

        $image     = wp_get_attachment_image_src($attachment_id, 'full');
        $file_name = basename($image[0]);

        // Not compressed
        $file_data          = get_attached_file($attachment_id);
        $uncompressed_value = filesize($file_data);

        $uncompressed_value = size_format($uncompressed_value, 2);

        $dimensions = getimagesize($file_data);

        $output = '<div class="wps-ic-uncompressed">';
        $output .= '<img src="' . $logo_uncompressed . '" />';
        $output .= '<h5>Not Viewed</h5>';
        $output .= '</div>';

        $actions = '<div class="wps-ic-media-actions">';
        $actions .= '<ul class="wps-ic-noncompressed-icon">';
        $actions .= '<li class="wps-ic-weight"><span>' . $uncompressed_value . '</span></li>';
        $actions .= '<li class="wps-ic-size"><span>' . $dimensions[0] . 'x' . $dimensions[1] . '</span></li>';
        $actions .= '<li class="wps-ic-li-no-padding">';
        $actions .= '<div class="wps-ic-media-actions-toolbox">';
        $actions .= '<ul class="wps-ic-noncompressed-icon">';
        $actions .= '<li class="wps-ic-action">';

        $actions .= '<div class="btn-group">';
        $actions .= '<button type="button" class="btn btn-success wps-ic-compress-single" data-image="' . $image[0] . '" data-filename="' . $file_name . '" data-image_id="' . $attachment_id . '">Compress</button>';
        $actions .= '<button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
        $actions .= '<span class="caret"></span>';
        $actions .= '<span class="sr-only">Toggle Dropdown</span>';
        $actions .= '</button>';
        $actions .= '<ul class="dropdown-menu">';
        $actions .= apply_filters('wps_ic_pro_exclude', $attachment_id);
        $actions .= '</ul>';
        $actions .= '</div>';
        $actions .= '</li>';
        $actions .= '</ul>';
        $actions .= '</div>';

        $actions .= '<div id="wps-ic-compare' . $attachment_id . '" class="lightbox wp-ic-compare"></div>';

        wp_send_json_success(['info' => $output, 'actions' => $actions, 'attachment_id' => $attachment_id]);
      } else if ($response == 'complete') {

        $last_image = wp_get_attachment_image_src($attachment_id, 'full');

        $html = '<div class="wps-ic-sample-data" id="wps-ic-sample-data-' . $attachment_id . '">';
        //
        $html .= '<h3>Restore Complete</h3>';
        $html .= '<p>As long as you keep the backup original images setting on, you can restore your images back to original at any point if you want to recompress on different settings.</p>';

        // Image
        $html .= '<img src="' . $last_image[0] . '" id="wps-ic-sample-image" />';

        // wps_ic_sample_data
        $html .= '<a href="#"></a>';
        $html .= '</div>';

        wp_send_json_success(['html' => $html]);
      }
    }

  }


  public function delete_local_backup($attach_id) {
    $file_path = get_attached_file($attach_id);

    if ($file_path && file_exists($file_path)) {
      $filename     = basename($file_path);
      $new_filename = 'icbackup_' . $filename;
      $new_path     = str_replace($filename, $new_filename, $file_path);
      if (file_exists($new_path)) {
        wp_delete_file($new_path);
      }
    }
  }


  public function create_local_backup($attach_id) {
    $file_path = get_attached_file($attach_id);

    if ($file_path && file_exists($file_path)) {
      $filename     = basename($file_path);
      $new_filename = 'icbackup_' . $filename;
      $new_path     = str_replace($filename, $new_filename, $file_path);
      $copy         = copy($file_path, $new_path);
    }
  }


  /**
   * @since 4.0.0
   */
  public function bulk_restore($attachments, $apikey = '') {
    global $wps_ic, $wpdb;

    if (empty($apikey)) {
      $apikey = $wps_ic::$api_key;
    }

    setcookie('ic_varnish_clear', microtime(true), time() + 600, '/');
    ini_set('memory_limit', '2024M');
    ini_set('max_execution_time', '180');

    if ( ! function_exists('download_url')) {
      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');
    }

    if ( ! function_exists('update_option')) {
      require_once(ABSPATH . "wp-includes" . '/option.php');
    }

    foreach ($attachments['attachments'] as $key => $attachment_ID) {
      // Get attachment ID
      $attach_id     = sanitize_text_field($attachment_ID);
      $attachment_id = (int)$attach_id;

      // Remove Queue
      #$wps_ic->queue->change_queue_status($attachment_id, 'restoring', array('code' => 'restoring'));

      $reset = get_post_meta($attachment_id, 'wps_ic_reset', true);

      // Find image source on site
      $image     = wp_get_attachment_image_src($attachment_id, 'full');
      $file_name = basename($image[0]);

      // Call Parameters
      $request_params                  = [];
      $request_params['apiv3']         = 'true';
      $request_params['action']        = 'restore';
      $request_params['filename']      = $file_name;
      $request_params['apikey']        = $apikey;
      $request_params['attachment_id'] = $attachment_id;
      $request_params['site']          = site_url();

      $params = [
        'method'      => 'POST',
        'timeout'     => 120,
        'redirection' => 3,
        'sslverify'   => false,
        'httpversion' => '1.0',
        'blocking'    => true, // TODO: Mozda true?
        'headers'     => ['user-agent' => WPS_IC_API_USERAGENT],
        'body'        => $request_params,
        'cookies'     => [],
        'user-agent'  => WPS_IC_API_USERAGENT
      ];

      $call = wp_remote_post(WPS_IC_APIURL, $params);
      $body = wp_remote_retrieve_body($call);

      $body           = json_decode($body, true);
      $original_image = $body['data'];

      if ($original_image == 'no-backup') {
        //return false;
      }

      $file_data = get_attached_file($attachment_id);

      if ($body['success'] == 'true') {

        // Verify we can get to the original Image
        $call = wp_remote_get($original_image, ['timeout' => 60, 'sslverify' => false]);

        if (wp_remote_retrieve_response_code($call) != 200) {
          return false;
        }

        // File path
        $file_name = basename($file_data);
        $file_path = str_replace($file_name, '', $file_data);

        // Update Compressed Table
        $query = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_compressed SET restored='1' WHERE attachment_ID=%s", $attachment_id);
        $wpdb->query($query);

        clearstatcache();

        // Copy backup file to dest
        $tempfile = download_url($original_image, 60);
        if ($tempfile) {
//	        $file_info = finfo_open( FILEINFO_MIME_TYPE );
//	        $mime_type = finfo_file( $file_info, $tempfile );
//	        finfo_close( $file_info );

            //$mime_type = mime_content_type($tempfile);

          // Verify if the downloaded file is an image
          if (function_exists('mime_content_type')){
            $mime_type = mime_content_type($tempfile);
          } else if (function_exists('finfo_open')) {
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $tempfile);
            finfo_close($file_info);
          } else {
            $mime_type = wp_get_image_mime($tempfile);
          }

	        if ( in_array( $mime_type, [ 'image/jpeg', 'image/png', 'image/gif' ] ) ) {
		        $imageSize = getimagesize( $tempfile );
		        if ( $imageSize !== false ) {
			        // Copy file from Amazon
			        if ( copy( $tempfile, $file_path . $file_name ) ) {
				        unlink( $tempfile );

				        /// Delete file from compressed table for stats
				        $wpdb->update( $wpdb->prefix . 'ic_compressed', ['restored' => '1'], ['attachment_ID' => $attachment_id]);

				        // Delete compress data
				        delete_post_meta( $attachment_id, 'wps_ic_started' );
				        delete_post_meta( $attachment_id, 'wps_ic_reset' );
				        delete_post_meta( $attachment_id, 'wps_ic_times' );
				        delete_post_meta( $attachment_id, 'wps_ic_compressed' );
				        delete_post_meta( $attachment_id, 'wps_ic_data' );
				        delete_post_meta( $attachment_id, 'wps_ic_cdn' );
				        delete_post_meta( $attachment_id, 'wps_ic_in_bulk' );
				        delete_post_meta( $attachment_id, 'wps_ic_compressing' );
				        delete_post_meta( $attachment_id, 'wps_ic_restoring' );
				        delete_post_meta( $attachment_id, 'wps_ic_dimmensions' );

				        $uploadfile                  = get_attached_file( $attachment_id );
				        $metadata                    = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
				        $imagesize                   = getimagesize( $uploadfile );
				        $metadata['width']           = $imagesize[0];
				        $metadata['height']          = $imagesize[1];
				        $dimensions                  = [];
				        $dimensions['old']['width']  = $imagesize[0];
				        $dimensions['old']['height'] = $imagesize[1];
				        update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
				        update_post_meta( $attachment_id, 'wps_ic_dimmensions', $dimensions );
				        update_post_meta( $attachment_id, 'wps_ic_noncompressed_size', filesize( $uploadfile ) );

				        $this->restore_wpml_image( $attachment_id );
				        $this->restore_thumbnails( $attachment_id );

				        // Remove Queue
				        $wps_ic->queue->change_queue_status( $attachment_id, 'restored', ['code' => 'restored']);
			        }
		        }
	        }
        } else {
          // Set compressing
          // Delete compress data
          delete_post_meta($attachment_id, 'wps_ic_started');
          delete_post_meta($attachment_id, 'wps_ic_reset');
          delete_post_meta($attachment_id, 'wps_ic_times');
          delete_post_meta($attachment_id, 'wps_ic_compressed');
          delete_post_meta($attachment_id, 'wps_ic_data');
          delete_post_meta($attachment_id, 'wps_ic_cdn');
          delete_post_meta($attachment_id, 'wps_ic_in_bulk');
          delete_post_meta($attachment_id, 'wps_ic_compressing');
          delete_post_meta($attachment_id, 'wps_ic_restoring');
          delete_post_meta($attachment_id, 'wps_ic_dimmensions');

          $this->restore_wpml_image($attachment_id);
          $this->restore_thumbnails($attachment_id);

          // Remove from Bulk Compress Background
          $wps_ic->queue->change_queue_status($attachment_id, 'restored', ['code' => 'remote_404']);
        }

      } else {
        //if ($original_image == 'no-backup') {
        // Set compressing
        // Delete compress data
        delete_post_meta($attachment_id, 'wps_ic_started');
        delete_post_meta($attachment_id, 'wps_ic_reset');
        delete_post_meta($attachment_id, 'wps_ic_times');
        delete_post_meta($attachment_id, 'wps_ic_compressed');
        delete_post_meta($attachment_id, 'wps_ic_data');
        delete_post_meta($attachment_id, 'wps_ic_cdn');
        delete_post_meta($attachment_id, 'wps_ic_in_bulk');
        delete_post_meta($attachment_id, 'wps_ic_compressing');
        delete_post_meta($attachment_id, 'wps_ic_restoring');
        delete_post_meta($attachment_id, 'wps_ic_dimmensions');

        $this->restore_wpml_image($attachment_id);
        $this->restore_thumbnails($attachment_id);

        // Remove Queue
        $wps_ic->queue->change_queue_status($attachment_id, 'restored', ['code' => 'no_backup']);
        //}
      }
    }
  }


  public function restore_thumbnails($attachmentID) {
    return false;
    $fullsizepath = get_attached_file($attachmentID);
    if (wp_update_attachment_metadata($attachmentID, wp_generate_attachment_metadata($attachmentID, $fullsizepath))) {

    }
  }


  public function log($action = '', $message = '') {
    $run = get_option('ic_log_run');
    if ( ! $run) {
      $run = 1;
    } #else $run++;

    $log_file = WPS_IC_DIR . 'run_' . $run . '.txt';

    if ( ! file_exists($log_file)) {
      fopen($log_file, 'a');
    }

    $log = '[' . date('d-m-Y H:i:s') . '] - ' . $action . ' - ' . $message . "\r\n";
    $log .= file_get_contents($log_file);
    file_put_contents($log_file, $log);
  }





  /**
   * @since 4.0.0
   */
  public function bulk($attachments, $apikey = '', $return = false, $params = '', $debug_tool = false) {
    global $wps_ic;

    $site_url = site_url();

    $call_response = [];
    $response      = [];

    if (empty($apikey)) {
      $apikey = $wps_ic::$api_key;
    }

    $settings = $wps_ic->options->get_settings();

    if ( ! function_exists('download_url')) {
      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');
    }

    if ( ! function_exists('update_option')) {
      require_once(ABSPATH . "wp-includes" . '/option.php');
    }

    $send_to_compress = [];

    foreach ($attachments['attachments'] as $key => $attachment_ID) {

      $compressed = get_post_meta($attachment_ID, 'wps_ic_data', true);

      if ( ! empty($compressed['new']['size'])) {
        $wps_ic->queue->change_queue_status($attachment_ID, 'compressed', ['code' => 'not_able']);
        $wps_ic->queue->remove_queue($attachment_ID);

        // Delete in Queue Meta
        delete_post_meta($attachment_ID, 'wps_ic_started');
        delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
        delete_post_meta($attachment_ID, 'wps_ic_compressing');
        continue;
      }

      $wps_ic->queue->change_queue_status($attachment_ID, 'compressing', ['code' => 'compressing']);

      // Create local backup if selected
      if ( ! empty($settings['backup-location']) && $settings['backup-location'] == 'local') {
        // Do Local Backup Before Anything
        $this->create_local_backup($attachment_ID);
      } else {
      }

      // Get attachment ID
      $attach_id = sanitize_text_field($attachment_ID);
      $attach_id = (int)$attach_id;

      // Get all image sizes
      $thumbs         = [];
      $thumbs['full'] = wp_get_attachment_image_src($attach_id, 'full');
      $uri            = explode('?', $thumbs['full'][0]);
      $thumbs['full'] = $uri[0];

      $sizesa = get_intermediate_image_sizes();

      if (is_array($sizesa)) {
        foreach ($sizesa as $size => $value) {

          // Is thumbnail size set to active in settings
          if ( ! isset($settings['thumbnails'][ $value ])) {
            continue;
          }

          $thumbs[ $value ] = wp_get_attachment_image_src($attach_id, $value);
          $uri              = explode('?', $thumbs[ $value ][0]);
          #$uri[0] = str_replace($site_url, '', $uri[0]);

          if ($uri[0] == $thumbs['full']) {
            unset($thumbs[ $value ]);
          } else {
            $thumbs[ $value ] = $uri[0];
          }
        }
      }

      if (class_exists('WooCommerce')) {
        $thumbs['woocommerce_thumbnail']         = wp_get_attachment_image_src($attach_id, 'woocommerce_thumbnail');
        $thumbs['woocommerce_thumbnail']         = $thumbs['woocommerce_thumbnail'][0];
        $thumbs['woocommerce_single']            = wp_get_attachment_image_src($attach_id, 'woocommerce_single');
        $thumbs['woocommerce_single']            = $thumbs['woocommerce_single'][0];
        $thumbs['woocommerce_gallery_thumbnail'] = wp_get_attachment_image_src($attach_id, 'woocommerce_gallery_thumbnail');
        $thumbs['woocommerce_gallery_thumbnail'] = $thumbs['woocommerce_gallery_thumbnail'][0];
      } else {
      }

      $call_response = count($thumbs);
			$send_to_compress[ $attach_id ] = $thumbs;
    }

    $request_params                  = [];
    $request_params['apiv3']         = 'true';
    $request_params['resize_max']    = '0';
    $request_params['preserve_exif'] = '0';
    $request_params['q']             = 'maximum';
    $request_params['bulk']          = 'true';
    $request_params['apikey']        = $apikey;
    $request_params['force']         = 'true';
    $request_params['url']           = $site_url;
    $request_params['images']        = json_encode($send_to_compress);

    if ( ! empty($settings['resize_larger_images']) && $settings['resize_larger_images'] == '1') {
      $request_params['resize_max'] = $settings['resize_larger_images_width'];
    }

    if ( ! empty($settings['preserve_exif']) && $settings['preserve_exif'] == '1') {
      $request_params['preserve_exif'] = '1';
    }

    if (empty($params)) {
      if ( ! empty($settings['optimization'])) {
        $request_params['q'] = $settings['optimization'];
      }
    } else {
      $request_params['q']                    = $params['quality'];
      $request_params['resize_larger_images'] = $params['resize'];
      $request_params['resize_max']           = $params['size'];
    }

    $params = [
      'method'      => 'POST',
      'timeout'     => 120,
      'redirection' => 3,
      'sslverify'   => false,
      'httpversion' => '1.0',
      'blocking'    => true, // TODO: Mozda true?
      'headers'     => [],
      'body'        => $request_params,
      'cookies'     => [],
      'user-agent'  => WPS_IC_API_USERAGENT
    ];

    $start = microtime(true);

    // Send call to API
    $call     = wp_remote_post(WPS_IC_APIURL, $params);
    $response = wp_remote_retrieve_body($call);

    if ( ! empty($params['debug_tool'])) {
      update_post_meta($attach_id, 'request_params', $request_params);
      update_post_meta($attach_id, 'request_response_code', wp_remote_retrieve_response_code($call));
      update_post_meta($attach_id, 'request_response_body', wp_remote_retrieve_body($call));
    }

    $end = microtime(true);
    $body = json_decode($response, true);
    if ($body['success'] == 'true') {
      $images = $body['data'];

      if (is_array($images)) {
        foreach ($images as $attachment_ID => $image_data) {
          // Update Image

          if ( ! empty($image_data['compressed']['full']['no-credits']) && $image_data['compressed']['full']['no-credits'] == 'true') {
            $this->not_enough_credits($attachment_ID);
            $wps_ic->queue->change_queue_status($attachment_ID, 'compressed', ['code' => 'not_enough_credits']);

            if ( ! empty($params['debug_tool'])) {
              return 'Not enough credits';
            }

          } else {

            if (empty($image_data['compressed']['full']['size']) || !$image_data['compressed']['full']['size']) {
              update_post_meta($attachment_ID, 'wps_ic_data', 'not_able');
            } else {
              if ($image_data['compressed']['full']['original'] < $image_data['compressed']['full']['size']) {
                update_post_meta($attachment_ID, 'wps_ic_data', 'not_able');
              } else {
                $this->update_image($attachment_ID, $image_data['compressed'], true, $request_params['q']);
              }
            }

          }

        }

        if ($return) {
          return $call_response;
        }
      }
    } else {
      $wps_ic->queue->remove_queue($attachment_ID);
      update_post_meta($attach_id, 'wps_ic_data', 'not_able');
      if ( ! empty($params['debug_tool'])) {
        return $response;
      }

    }

  }


  public function not_enough_credits($attachment_ID) {
    // Update compress data
    update_post_meta($attachment_ID, 'wps_ic_data', 'no_credits');

    delete_post_meta($attachment_ID, 'wps_ic_cdn');
    delete_post_meta($attachment_ID, 'wps_ic_index');
    delete_post_meta($attachment_ID, 'wps_ic_hash');
    delete_post_meta($attachment_ID, 'wps_ic_compressed_size');
    delete_post_meta($attachment_ID, 'wps_ic_remote_img');
    delete_post_meta($attachment_ID, 'wps_ic_restored');
    delete_post_meta($attachment_ID, 'wps_ic_compressed');
    delete_post_meta($attachment_ID, 'wps_ic_webp_path');
    delete_post_meta($attachment_ID, 'wps_ic_webp_uri');

    // Delete in Queue Meta
    delete_post_meta($attachment_ID, 'wps_ic_started');
    delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
    delete_post_meta($attachment_ID, 'wps_ic_compressing');
  }


  public function not_able_to_optimize($attachment_ID) {
    // Update compress data
    update_post_meta($attachment_ID, 'wps_ic_data', 'not_able');

    delete_post_meta($attachment_ID, 'wps_ic_cdn');
    delete_post_meta($attachment_ID, 'wps_ic_index');
    delete_post_meta($attachment_ID, 'wps_ic_hash');
    delete_post_meta($attachment_ID, 'wps_ic_compressed_size');
    delete_post_meta($attachment_ID, 'wps_ic_remote_img');
    delete_post_meta($attachment_ID, 'wps_ic_restored');
    delete_post_meta($attachment_ID, 'wps_ic_compressed');
    delete_post_meta($attachment_ID, 'wps_ic_webp_path');
    delete_post_meta($attachment_ID, 'wps_ic_webp_uri');

    // Delete in Queue Meta
    delete_post_meta($attachment_ID, 'wps_ic_started');
    delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
    delete_post_meta($attachment_ID, 'wps_ic_compressing');
  }


  public function pull_thumbnails($attachments) {
    global $wps_ic;
    $apikey = $wps_ic::$api_key;

    $request_params                = [];
    $request_params['apiv3']       = 'true';
    $request_params['apikey']      = $apikey;
    $request_params['action']      = 'fetch_thumbnails';
    $request_params['attachments'] = json_encode($attachments);

    $params = [
      'method'      => 'POST',
      'timeout'     => 60,
      'redirection' => 3,
      'sslverify'   => false,
      'httpversion' => '1.0',
      'blocking'    => true, // TODO: Mozda true?
      'headers'     => ['user-agent' => WPS_IC_API_USERAGENT],
      'body'        => $request_params,
      'cookies'     => [],
      'user-agent'  => WPS_IC_API_USERAGENT
    ];

    // Send call to API
    $call     = wp_remote_post(WPS_IC_APIURL, $params);
    $code     = wp_remote_retrieve_response_code($call);
    $response = wp_remote_retrieve_body($call);
  }


  /**
   * Update Thumbnails for specified image
   *
   * @param $attachment_ID
   * @param $images_array
   */
  public function update_thumbnail($attachment_ID, $images_array) {
    $fullsizepath   = get_attached_file($attachment_ID);
    $path_to_thumb  = str_replace(basename($fullsizepath), '', $fullsizepath);
    $thumbnail_meta = wp_get_attachment_metadata($attachment_ID);
    $sizes          = $thumbnail_meta['sizes'];

    $webps = get_post_meta($attachment_ID, 'webp_list', true);
    if ( ! $webps) {
      $webps = [];
    }

    foreach ($images_array as $image_size => $image_data) {

      $current_size           = $sizes[ $image_size ];
      $current_thumbnail      = $current_size['file'];
      $current_thumbnail_path = $path_to_thumb . $current_thumbnail;

      if ( ! $current_size || empty($current_size)) {
        continue;
      }

      // Thumbnails
      $temp_file = download_url($image_data['uri'], 60);

      if ($temp_file) {
        clearstatcache();
      }

      if ( ! empty($image_data['webp'])) {
        $current_thumbnail_path = str_replace(['.png', '.jpg', '.jpeg'], '.webp', $current_thumbnail_path);
        $temp_file              = download_url($image_data['webp'], 60);

        if ($temp_file) {
          clearstatcache();

          $webps[] = $current_thumbnail_path;

        }
      }

    }

    update_post_meta($attachment_ID, 'webp_list', $webps);
  }


  public function update_wpml_image($attachmentID, $metadata = []) {
    global $wpdb;

    if ( ! function_exists('icl_object_id')) {
      return;
    }
    if (empty($metadata)) {
      return;
    }

    $guid = $wpdb->get_var("SELECT posts.guid FROM {$wpdb->posts} posts WHERE posts.ID='" . $attachmentID . "'");

		// Find Children
    $children = $wpdb->get_results("SELECT posts.ID, posts.guid FROM {$wpdb->posts} posts WHERE posts.guid='" . $guid . "' AND posts.post_status='inherit' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif', 'image/jpg') ORDER BY posts.post_date DESC");

    if ($children) {
      foreach ($children as $child) {
        if ($child->ID == $attachmentID) {
          continue;
        }

        // Child
        foreach ($metadata as $meta_key => $meta_value) {
          update_post_meta($child->ID, $meta_key, $meta_value);
        }

      }
    }
  }


  public function restore_wpml_image($attachmentID) {
    global $wpdb;

    if ( ! function_exists('icl_object_id')) {
      return;
    }

    $guid = $wpdb->get_var("SELECT posts.guid FROM {$wpdb->posts} posts WHERE posts.ID='" . $attachmentID . "'");

		// Find Children
    $children = $wpdb->get_results("SELECT posts.ID, posts.guid FROM {$wpdb->posts} posts WHERE posts.guid='" . $guid . "' AND posts.post_status='inherit' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif', 'image/jpg') ORDER BY posts.post_date DESC");

    if ($children) {
      foreach ($children as $child) {
        if ($child->ID == $attachmentID) {
          continue;
        }

        // Child
        // Delete compress data
        delete_post_meta($child->ID, 'wps_ic_started');
        delete_post_meta($child->ID, 'wps_ic_reset');
        delete_post_meta($child->ID, 'wps_ic_times');
        delete_post_meta($child->ID, 'wps_ic_compressed');
        delete_post_meta($child->ID, 'wps_ic_data');
        delete_post_meta($child->ID, 'wps_ic_cdn');
        delete_post_meta($child->ID, 'wps_ic_in_bulk');
        delete_post_meta($child->ID, 'wps_ic_compressing');
        delete_post_meta($child->ID, 'wps_ic_restoring');
        delete_post_meta($child->ID, 'wps_ic_dimmensions');

        $uploadfile                  = get_attached_file($child->ID);
        $metadata                    = get_post_meta($child->ID, '_wp_attachment_metadata', true);
        $imagesize                   = getimagesize($uploadfile);
        $metadata['width']           = $imagesize[0];
        $metadata['height']          = $imagesize[1];
        $dimensions                  = [];
        $dimensions['old']['width']  = $imagesize[0];
        $dimensions['old']['height'] = $imagesize[1];
        update_post_meta($child->ID, '_wp_attachment_metadata', $metadata);
        update_post_meta($child->ID, 'wps_ic_dimmensions', $dimensions);
        update_post_meta($child->ID, 'wps_ic_noncompressed_size', filesize($uploadfile));

      }
    }
  }


  public function update_image($attachment_ID, $images_array, $retry = true, $quality = '') {
    global $wps_ic, $wpdb;

    $child_metadata        = [];
    $settings              = get_option(WPS_IC_SETTINGS);
    $image_data            = $images_array['full'];
    $image_url             = $image_data['uri'];
    $compressed_image_size = $image_data['size'];
    $original_image_size   = $image_data['original'];

    $file_data = get_attached_file($attachment_ID);

    $uncompressed_file_size  = $original_image_size;
    $uncompressed_dimensions = getimagesize($file_data);

    // Update Compressed Table
    $query = $wpdb->prepare("INSERT INTO " . $wpdb->prefix . "ic_compressed (created, attachment_ID, saved, original, percent_saved, count) VALUES (%s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE saved=saved, count=count+1, restored=0",
                            current_time('mysql'), $attachment_ID, ($original_image_size - $compressed_image_size), $original_image_size,
                            round((($original_image_size - $compressed_image_size)
                                   / $original_image_size) * 100, 2), '1');
    $wpdb->query($query);

    // First delete the current file
    clearstatcache();

    $dimensions                  = [];
    $dimensions['old']['width']  = $uncompressed_dimensions[0];
    $dimensions['old']['height'] = $uncompressed_dimensions[1];

    update_post_meta($attachment_ID, 'wps_ic_noncompressed_size', $uncompressed_file_size);
    $child_metadata['wps_ic_noncompressed_size'] = $uncompressed_file_size;

    // Change Queue to Compressing
    $wps_ic->queue->change_queue_status($attachment_ID, 'compressing', ['code' => 'compressing']);

    if (empty($image_url)) {
      // Update compress data
      update_post_meta($attachment_ID, 'wps_ic_data', 'not_able');
      $child_metadata['wps_ic_data'] = 'not_able';

      delete_post_meta($attachment_ID, 'wps_ic_cdn');
      delete_post_meta($attachment_ID, 'wps_ic_index');
      delete_post_meta($attachment_ID, 'wps_ic_hash');
      delete_post_meta($attachment_ID, 'wps_ic_compressed_size');
      delete_post_meta($attachment_ID, 'wps_ic_remote_img');
      delete_post_meta($attachment_ID, 'wps_ic_restored');
      delete_post_meta($attachment_ID, 'wps_ic_compressed');
      delete_post_meta($attachment_ID, 'wps_ic_webp_path');
      delete_post_meta($attachment_ID, 'wps_ic_webp_uri');

      // Delete in Queue Meta
      delete_post_meta($attachment_ID, 'wps_ic_started');
      delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
      delete_post_meta($attachment_ID, 'wps_ic_compressing');

      // Remove From Queue
      $wps_ic->queue->change_queue_status($attachment_ID, 'compressed', ['code' => 'not_able']);
      $this->delete_local_backup($attachment_ID);
    } else {

      // First delete the current file
      clearstatcache();

      $start = microtime(true);
      // Download remote file to temp
      $temp_file = download_url($image_data['uri'], 120);
      $end = microtime(true);

      if ($temp_file) {

        // First delete the current file
        clearstatcache();

        #$compressed_file_size  = filesize($file_data . '_tmp');
        $compressed_file_size = $compressed_image_size;

        $start = microtime(true);
        /*if ($compressed_file_size < $uncompressed_file_size) {*/
        #unlink($file_data);

        if (copy($temp_file, $file_data)) {
          unlink($temp_file);
          $compressed_dimensions = getimagesize($file_data);
          update_post_meta($attachment_ID, 'wps_ic_compressed_size', $compressed_file_size);
          $child_metadata['wps_ic_compressed_size'] = $compressed_file_size;

          $metadata           = get_post_meta($attachment_ID, '_wp_attachment_metadata', true);
          $metadata['width']  = $compressed_dimensions[0];
          $metadata['height'] = $compressed_dimensions[1];
          update_post_meta($attachment_ID, '_wp_attachment_metadata', $metadata);
          $child_metadata['_wp_attachment_metadata'] = $metadata;

          $dimensions['new']['width']  = $compressed_dimensions[0];
          $dimensions['new']['height'] = $compressed_dimensions[1];
          update_post_meta($attachment_ID, 'wps_ic_dimmensions', $dimensions);
          $child_metadata['wps_ic_dimmensions'] = $dimensions;

          $compress_data['old']['size'] = $uncompressed_file_size;
          $compress_data['new']['size'] = $compressed_file_size;

          if (empty($quality)) {
            $compress_data['quality'] = $settings['optimization'];
          } else {
            $compress_data['quality'] = $quality;
          }

          // Update compress data
          update_post_meta($attachment_ID, 'wps_ic_compressed', 'true');
          update_post_meta($attachment_ID, 'wps_ic_data', $compress_data);
          update_post_meta($attachment_ID, 'wps_ic_cdn', 'true');

          $child_metadata['wps_ic_compressed'] = true;
          $child_metadata['wps_ic_data']       = $compress_data;
          $child_metadata['wps_ic_cdn']        = true;

          // Delete in queue meta
          delete_post_meta($attachment_ID, 'wps_ic_started');
          delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
          delete_post_meta($attachment_ID, 'wps_ic_compressing');

          $wps_ic->queue->change_queue_status($attachment_ID, 'compressed', ['code' => 'compressed']);
          if ( ! empty($image_data['webp'])) {

            // First delete the current file
            clearstatcache();

            $temp_file = download_url($image_data['webp'], 60);

            // Is image downloaded into temporary file and is larger than 0?
            if ( ! is_wp_error($temp_file) && $temp_file) {

              // First delete the current file
              clearstatcache();

              // Rename image into webp
              $permfile = str_replace(['.jpg', '.jpeg', '.png'], '.webp', $file_data);

              // Copy image from temporary file into real file
              if (file_exists($permfile)) {
                unlink($permfile);
              }

              if (copy($temp_file, $permfile)) {
                unlink($temp_file);
                $uri = explode('wp-content', $permfile);
                $uri = site_url('wp-content') . $uri[1];

                // Update backup URI
                update_post_meta($attachment_ID, 'wps_ic_webp_path', $permfile);
                update_post_meta($attachment_ID, 'wps_ic_webp_uri', $uri);

                $child_metadata['wps_ic_webp_path'] = $permfile;
                $child_metadata['wps_ic_webp_uri']  = $uri;
              }
            }

          }
        } else {
          // Update compress data
          update_post_meta($attachment_ID, 'wps_ic_data', 'not_able');
          $child_metadata['wps_ic_data'] = 'not_able';

          delete_post_meta($attachment_ID, 'wps_ic_cdn');
          delete_post_meta($attachment_ID, 'wps_ic_index');
          delete_post_meta($attachment_ID, 'wps_ic_hash');
          delete_post_meta($attachment_ID, 'wps_ic_compressed_size');
          delete_post_meta($attachment_ID, 'wps_ic_remote_img');
          delete_post_meta($attachment_ID, 'wps_ic_restored');
          delete_post_meta($attachment_ID, 'wps_ic_compressed');
          delete_post_meta($attachment_ID, 'wps_ic_webp_path');
          delete_post_meta($attachment_ID, 'wps_ic_webp_uri');

          // Delete in Queue Meta
          delete_post_meta($attachment_ID, 'wps_ic_started');
          delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
          delete_post_meta($attachment_ID, 'wps_ic_compressing');

          // Remove From Queue
          $wps_ic->queue->change_queue_status($attachment_ID, 'compressed', ['code' => 'unable to copy']);
          $this->delete_local_backup($attachment_ID);
        }

        $end = microtime(true);
      }

    }

    $this->update_wpml_image($attachment_ID, $child_metadata);
  }


  public function update_image_meta($attachment_ID) {
    // Get upload directory path
    $file_data = get_attached_file($attachment_ID);
    $filesize  = filesize($file_data);
    $imagesize = getimagesize($file_data);

    // Update size
    update_post_meta($attachment_ID, 'wps_ic_compressed_size', $filesize);

    // Delete in queue meta
    delete_post_meta($attachment_ID, 'wps_ic_started');
    delete_post_meta($attachment_ID, 'wps_ic_compressing');
    delete_post_meta($attachment_ID, 'wps_ic_in_bulk');

    $metadata           = get_post_meta($attachment_ID, '_wp_attachment_metadata', true);
    $metadata['width']  = $imagesize[0];
    $metadata['height'] = $imagesize[1];
    update_post_meta($attachment_ID, '_wp_attachment_metadata', $metadata);

    $compress_data                          = get_post_meta($attachment_ID, 'wps_ic_data', true);
    $compress_data['new']['size']           = $filesize;
    $compress_data['new']['data']['width']  = $imagesize[0];
    $compress_data['new']['data']['height'] = $imagesize[1];

    // Update compress data
    update_post_meta($attachment_ID, 'wps_ic_compressed', 'true');
    update_post_meta($attachment_ID, 'wps_ic_data', $compress_data);
    update_post_meta($attachment_ID, 'wps_ic_cdn', 'true');

    // Delete in queue meta
    delete_post_meta($attachment_ID, 'wps_ic_started');
    delete_post_meta($attachment_ID, 'wps_ic_in_bulk');
    delete_post_meta($attachment_ID, 'wps_ic_compressing');
  }


  /**
   * Is the image excluded?
   *
   * @param $attachment_id
   *
   * @return bool
   * @since 3.3.0
   */
  public function is_excluded($attachment_id) {
    $excluded = get_post_meta($attachment_id, 'wps_ic_exclude', true);
    if ( ! empty($excluded)) {
      return true;
    }

    return false;
  }


  /**
   * Is image allowed type?
   *
   * @param $attachment_id
   *
   * @return bool
   * @since 3.3.0
   */
  public function is_allowed_type($attachment_id) {
    $file_data = get_attached_file($attachment_id);
    $type      = wp_check_filetype($file_data);
    $exif      = exif_imagetype($file_data);

    $allowed_types         = [];
    $allowed_types['jpg']  = 'jpg';
    $allowed_types['jpeg'] = 'jpeg';
    $allowed_types['gif']  = 'gif';
    $allowed_types['png']  = 'png';

    if ( ! in_array(strtolower($type['ext']), $allowed_types) || ! $exif) {
      return true;
    }

    return false;
  }


  public function simple_exclude($atts, $response = 'html') {
    global $wps_ic, $wpdb;

    $attachment_id = sanitize_text_field($atts['attachment_id']);
    $exclude       = get_post_meta($attachment_id, 'wps_ic_exclude', true);

    $logo_uncompressed = WPS_IC_URI . 'assets/images/not-compressed.png';
    $logo_excluded     = WPS_IC_URI . 'assets/images/excluded.png';

    if (empty($exclude)) {
      update_post_meta($attachment_id, 'wps_ic_exclude', 'true');
      update_post_meta($attachment_id, 'wps_ic_data', 'excluded');

      $image      = wp_get_attachment_image_src($attachment_id, 'full');
      $file_name  = basename($image[0]);

      $excluded_list = get_option('wps_ic_excluded_list');
      $excluded_list[$file_name] = $file_name;
      update_option('wps_ic_excluded_list', $excluded_list);

      $output = '<div class="wps-ic-excluded">';
      $output .= '<img src="' . $logo_excluded . '" />';
      $output .= '<h5>Excluded</h5>';
      $output .= '</div>';

      $actions = '<div class="wps-ic-media-actions-toolbox">';
      $actions .= '<ul class="wps-ic-include">';
      $actions .= '<li class="no-padding">';
      $actions .= '<a href="#" class="wps-ic-pro-include" data-attachment_id="' . $attachment_id . '">Exclude</a>';
      $actions .= '</li>';
      $actions .= '</ul>';
      $actions .= '</div>';

    } else {
      delete_post_meta($attachment_id, 'wps_ic_exclude');
      delete_post_meta($attachment_id, 'wps_ic_data');

      // Not compressed
      $file_data          = get_attached_file($attachment_id);
      $uncompressed_value = filesize($file_data);
      $uncompressed_value = size_format($uncompressed_value, 2);

      $dimensions = getimagesize($file_data);
      $image      = wp_get_attachment_image_src($attachment_id, 'full');
      $file_name  = basename($image[0]);

      $excluded_list = get_option('wps_ic_excluded_list');
      unset($excluded_list[$file_name]);
      update_option('wps_ic_excluded_list', $excluded_list);

      $output = '<div class="wps-ic-uncompressed">';
      $output .= '<img src="' . $logo_uncompressed . '" />';
      $output .= '<h5>Not Viewed</h5>';
      $output .= '</div>';

      $actions = '<div class="wps-ic-media-actions">';
      $actions .= '<ul class="wps-ic-noncompressed-icon">';
      $actions .= '<li class="wps-ic-weight"><span>' . $uncompressed_value . '</span></li>';
      $actions .= '<li class="wps-ic-size"><span>' . $dimensions[0] . 'x' . $dimensions[1] . '</span></li>';
      $actions .= '<li class="wps-ic-li-no-padding">';
      $actions .= '<div class="wps-ic-media-actions-toolbox">';
      $actions .= '<ul class="wps-ic-noncompressed-icon">';
      $actions .= '<li class="wps-ic-action">';

      $actions .= '<a href="#" class="wps-ic-pro-exclude" data-attachment_id="' . $attachment_id . '">Exclude</a>';
      $actions .= '</li>';
      $actions .= '</ul>';
      $actions .= '</div>';

      $actions .= '<div id="wps-ic-compare' . $attachment_id . '" class="lightbox wp-ic-compare"></div>';

    }

    wp_send_json_success(['info' => $output, 'actions' => $actions]);
  }


  public function exclude($atts, $response = 'html') {
    global $wps_ic, $wpdb;

    $attachment_id = sanitize_text_field($atts['attachment_id']);
    $exclude       = get_post_meta($attachment_id, 'wps_ic_exclude', true);

    $logo_uncompressed = WPS_IC_URI . 'assets/images/not-compressed.png';
    $logo_excluded     = WPS_IC_URI . 'assets/images/excluded.png';

    if (empty($exclude)) {
      update_post_meta($attachment_id, 'wps_ic_exclude', 'true');
      update_post_meta($attachment_id, 'wps_ic_data', 'excluded');

      $image      = wp_get_attachment_image_src($attachment_id, 'full');
      $file_name  = basename($image[0]);

      $excluded_list = get_option('wps_ic_excluded_list');
      $excluded_list[$file_name] = $file_name;
      update_option('wps_ic_excluded_list', $excluded_list);

      $output = '<div class="wps-ic-excluded">';
      $output .= '<img src="' . $logo_excluded . '" />';
      $output .= '<h5>Excluded</h5>';
      $output .= '</div>';

      $actions = '<div class="wps-ic-media-actions-toolbox">';
      $actions .= '<ul class="wps-ic-include">';
      $actions .= '<li class="no-padding">';
      $actions .= apply_filters('wps_ic_pro_include', $attachment_id);
      $actions .= '</li>';
      $actions .= '</ul>';
      $actions .= '</div>';

    } else {
      delete_post_meta($attachment_id, 'wps_ic_exclude');
      delete_post_meta($attachment_id, 'wps_ic_data');

      // Not compressed
      $file_data          = get_attached_file($attachment_id);
      $uncompressed_value = filesize($file_data);
      $uncompressed_value = size_format($uncompressed_value, 2);

      $dimensions = getimagesize($file_data);
      $image      = wp_get_attachment_image_src($attachment_id, 'full');
      $file_name  = basename($image[0]);

      $excluded_list = get_option('wps_ic_excluded_list');
      unset($excluded_list[$file_name]);
      update_option('wps_ic_excluded_list', $excluded_list);

      $output = '<div class="wps-ic-uncompressed">';
      $output .= '<img src="' . $logo_uncompressed . '" />';
      $output .= '<h5>Not Viewed</h5>';
      $output .= '</div>';

      $actions = '<div class="wps-ic-media-actions">';
      $actions .= '<ul class="wps-ic-noncompressed-icon">';
      $actions .= '<li class="wps-ic-weight"><span>' . $uncompressed_value . '</span></li>';
      $actions .= '<li class="wps-ic-size"><span>' . $dimensions[0] . 'x' . $dimensions[1] . '</span></li>';
      $actions .= '<li class="wps-ic-li-no-padding">';
      $actions .= '<div class="wps-ic-media-actions-toolbox">';
      $actions .= '<ul class="wps-ic-noncompressed-icon">';
      $actions .= '<li class="wps-ic-action">';

      $actions .= '<div class="btn-group">';
      $actions .= '<button type="button" class="btn btn-success wps-ic-compress-single" data-image="' . $image[0] . '" data-filename="' . $file_name . '" data-image_id="' . $attachment_id . '">Compress</button>';
      $actions .= '<button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
      $actions .= '<span class="caret"></span>';
      $actions .= '<span class="sr-only">Toggle Dropdown</span>';
      $actions .= '</button>';
      $actions .= '<ul class="dropdown-menu">';
      $actions .= apply_filters('wps_ic_pro_exclude', $attachment_id);
      $actions .= '</ul>';
      $actions .= '</div>';
      $actions .= '</li>';
      $actions .= '</ul>';
      $actions .= '</div>';

      $actions .= '<div id="wps-ic-compare' . $attachment_id . '" class="lightbox wp-ic-compare"></div>';

    }

    wp_send_json_success(['info' => $output, 'actions' => $actions]);
  }


  public function regenerate_thumbnails($attID) {
    if ( ! function_exists('wp_generate_attachment_metadata')) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    if ( ! function_exists('download_url')) {
      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');
    }

    $fullsizepath = get_attached_file($attID);

    if (wp_update_attachment_metadata($attID, wp_generate_attachment_metadata($attID, $fullsizepath))) {
      wp_send_json_success();
    } else {
      wp_send_json_error(['ID' => $attID, 'path' => $fullsizepath]);
    }
  }

}