<?php
/*
 * Local Compression
 */

class wps_ic_local
{

  private static $uncompressedImages;
  private static $compressedImages;
  private static $allowed_types;

  private static $apiUrl;
  private static $apikey;
  private static $siteUrl;
  private static $parameters;

  private static $defaultParameters;
  private static $imageSizes;

  public function __construct()
  {
    self::$imageSizes = [];
    self::$allowed_types = ['jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'png' => 'png'];

    $location = get_option('wps_ic_geo_locate_v2');
    if (empty($location)) {
      $location = $this->geoLocate();
    }

    if (is_object($location)) {
      $location = (array)$location;
    }

    $apiVersion = 'v4';

    if (isset($location) && !empty($location)) {
      if (is_array($location) && !empty($location['server'])) {
        if ($location['continent'] == 'CUSTOM') {
          self::$apiUrl = 'https://' . $location['custom_server'] . '.zapwp.net/local/' . $apiVersion. '/';
        } elseif ($location['continent'] == 'AS' || $location['continent'] == 'IN') {
          self::$apiUrl = 'https://singapore.zapwp.net/local/' . $apiVersion . '/';
        } elseif ($location['continent'] == 'EU') {
          self::$apiUrl = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
        } elseif ($location['continent'] == 'OC') {
          self::$apiUrl = 'https://sydney.zapwp.net/local/' . $apiVersion . '/';
        } elseif ($location['continent'] == 'US' || $location['continent'] == 'NA' || $location['continent'] == 'SA') {
          self::$apiUrl = 'https://nyc.zapwp.net/local/' . $apiVersion . '/';
        } else {
          self::$apiUrl = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
        }
      } else {
        self::$apiUrl = 'https://' . $location->server . '/local/' . $apiVersion . '/';
      }
    } else {
      self::$apiUrl = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
    }

	  $local_server = get_option('wps_ic_force_local_server');
	  if ($local_server !== false && $local_server !== 'auto'){
		  self::$apiUrl = 'https://' . $local_server . '/local/' . $apiVersion . '/';
	  }

    // Define default parameters and their values
    self::$defaultParameters = ['webp' => '0', 'quality' => '2', 'retina' => '0', 'exif' => '0'];

    // Get All Image Sizes
    self::$imageSizes = $this->getAllThumbSizes();

    /**
     * Is it a multisite?
     */
    if (is_multisite()) {
      $current_blog_id = get_current_blog_id();
      switch_to_blog($current_blog_id);
      self::$apikey = get_option(WPS_IC_OPTIONS)['api_key'];
      self::$siteUrl = site_url();
      self::$parameters = get_option(WPS_IC_SETTINGS);
    } else {
      self::$siteUrl = site_url();
      self::$apikey = get_option(WPS_IC_OPTIONS)['api_key'];
      self::$parameters = get_option(WPS_IC_SETTINGS);
    }

    /**
     * Tranlate Parameters to Latest API
     */
    self::$parameters = $this->translateParameters(self::$parameters);

  }


  public function geoLocate()
  {
    $force_location = get_option('wpc-ic-force-location');
    if (!empty($force_location)) {
      return $force_location;
    }

    $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode(site_url()), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    if (wp_remote_retrieve_response_code($call) == 200) {
      $body = wp_remote_retrieve_body($call);
      $body = json_decode($body);

      if ($body->success) {
        update_option('wps_ic_geo_locate_v2', $body->data);

        return $body->data;
      } else {
        update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);

        return ['country' => 'EU', 'server' => 'frankfurt.zapwp.net'];
      }
    } else {
      update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);

      return ['country' => 'EU', 'server' => 'frankfurt.zapwp.net'];
    }
  }


  public function getAllThumbSizes()
  {
    global $_wp_additional_image_sizes;

    $default_image_sizes = get_intermediate_image_sizes();

    foreach ($default_image_sizes as $size) {
      $image_sizes[$size]['width'] = intval(get_option("{$size}_size_w"));
      $image_sizes[$size]['height'] = intval(get_option("{$size}_size_h"));
      $image_sizes[$size]['crop'] = get_option("{$size}_crop") ? get_option("{$size}_crop") : false;
    }

    if (isset($_wp_additional_image_sizes) && count($_wp_additional_image_sizes)) {
      $image_sizes = array_merge($image_sizes, $_wp_additional_image_sizes);
    }

    $AdditionalSizes = ['full'];
    foreach ($AdditionalSizes as $size) {
      $image_sizes[$size]['width'] = 'full';
    }

    $image_sizes['original']['width'] = 'original';

    return $image_sizes;
  }

  /**
   * Used to translate parameters from old version to new version of API
   * Example: generate_webp gets translated to webp, preserve_exif gets translated to
   * exif...
   * @param $parameters
   * @return void
   */
  public function translateParameters($parameters)
  {
    // Get defaults
    $translatedParameters = $this->getDefaultParameters();

    if (isset($parameters['generate_webp'])) {
      $translatedParameters['webp'] = $parameters['generate_webp'];
    }

    if (isset($parameters['retina'])) {
      $translatedParameters['retina'] = $parameters['retina'];
    }

    if (isset($parameters['qualityLevel'])) {
      $translatedParameters['quality'] = $parameters['qualityLevel'];
    }

    if (isset($parameters['preserve_exif'])) {
      $translatedParameters['exif'] = $parameters['preserve_exif'];
    }

    if (isset($parameters['max_width'])) {
      $translatedParameters['max_width'] = $parameters['max_width'];
    } else {
      $translatedParameters['max_width'] = WPS_IC_MAXWIDTH;
    }

    return $translatedParameters;
  }

  public function getDefaultParameters($override = [])
  {
    foreach (self::$defaultParameters as $index => $value) {
      if (isset($override[$index])) {
        self::$defaultParameters[$index] = $override[$index];
      }
    }

    return self::$defaultParameters;
  }


  public function isBulkRunning()
  {
    $transient = get_transient('wps_ic_bulk_running');
    if (!$transient) return false;

    return true;
  }


  public function sendBulkToApi($imageArray, $action = 'createQueue') {
    $body = [];
    $body['apikey'] = self::$apikey;
    $body['siteUrl'] = self::$siteUrl;

    if (!empty($action)) {
      $body['action'] = $action;
    } else {
      $body['action'] = 'createQueue';
    }

    if (empty($parameters)) {
      $body['parameters'] = json_encode(self::$parameters);
    } else {
      $body['parameters'] = json_encode($parameters);
    }

    $body['images'] = json_encode($imageArray);
    $call = wp_remote_post(self::$apiUrl, ['timeout' => 90, 'blocking' => true, 'body' => $body, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

    if (wp_remote_retrieve_response_code($call) == 200) {
      $responseBody = wp_remote_retrieve_body($call);
      $responseBody = json_decode($responseBody);

      if (!$responseBody || $responseBody->success == 'false') {
        if ($responseBody->data->msg == 'invalid-apikey') {
          return ['status' => 'failed', 'status_code' => 200, 'reason' => 'bad-apikey', 'call' => print_r($call,true), 'body' => print_r($body,true)];
        } else {
          return ['status' => 'failed', 'status_code' => 200, 'reason' => $responseBody->data->msg, 'call' => print_r($call,true), 'body' => print_r($body,true)];
        }
      } else {
        return ['status' => 'success', 'apiUrl' => self::$apiUrl, 'body' => wp_remote_retrieve_body($call)];
      }
    } else {
      return ['status' => 'failed', 'status_code' => wp_remote_retrieve_response_code($call), 'postBody' => $body, 'apiUrl' => self::$apiUrl, 'body' => wp_remote_retrieve_body($call)];
    }
  }


  /**
   * Send a stream to API
   * @param $imageArray Array of images
   * @param $parameters Array of parameters from Settings
   * @return void
   */
  public function sendToAPI($imageArray = [], $parameters = '', $action = '')
  {
    $body = [];
    $body['apikey'] = self::$apikey;
    $body['siteurl'] = self::$siteUrl;

    // Way to track if old items in queue should be removed
    $body['batch'] = sha1(time());

    // NOTE TO SELF!!
    // NO ACTION IS REQUIRED FOR BULK!!! NEEDS TO CREATE QUEUE!!
    if (!empty($action)) {
      $body['action'] = $action;
    }

    if (empty($parameters)) {
      $body['parameters'] = json_encode(self::$parameters);
    } else {
      $body['parameters'] = json_encode($parameters);
    }

    $body['images'] = json_encode($imageArray);
    $call = wp_remote_post(self::$apiUrl, ['timeout' => 90, 'blocking' => true, 'body' => $body, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

    if (wp_remote_retrieve_response_code($call) == 200) {
      $responseBody = wp_remote_retrieve_body($call);
      $responseBody = json_decode($responseBody);

      if (!$responseBody || $responseBody->success == 'false') {
        if ($responseBody->data->msg == 'invalid-apikey') {
          return ['status' => 'failed', 'status_code' => 200, 'reason' => 'bad-apikey', 'call' => print_r($call,true), 'body' => print_r($body,true)];
        } else {
          return ['status' => 'failed', 'status_code' => 200, 'reason' => $responseBody->data->msg, 'call' => print_r($call,true), 'body' => print_r($body,true)];
        }
      } else {
        return ['status' => 'success', 'apiUrl' => self::$apiUrl, 'body' => wp_remote_retrieve_body($call)];
      }
    } else {
      return ['status' => 'failed', 'status_code' => wp_remote_retrieve_response_code($call), 'postBody' => $body, 'apiUrl' => self::$apiUrl, 'body' => wp_remote_retrieve_body($call)];
    }
  }

  /**
   * Preparing images for restore to send to API
   * @return Array Array of images
   */
  public function prepareRestoreImages()
  {
    global $wpdb;

    self::$uncompressedImages = [];
    self::$compressedImages = [];

    delete_option('wps_ic_parsed_images');
    delete_option('wps_ic_BulkStatus');

    $bulkStatus = get_option('wps_ic_BulkStatus');
    if (!$bulkStatus) $bulkStatus = [];

    $queryUncompressed = $wpdb->get_results("SELECT * FROM " . $wpdb->posts . " posts WHERE posts.post_type='attachment' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') AND NOT EXISTS (SELECT meta_value FROM " . $wpdb->postmeta . " meta WHERE meta.post_id=posts.ID and meta.meta_key='ic_stats')");

    $queryCompressed = $wpdb->get_results("SELECT * FROM " . $wpdb->posts . " posts WHERE posts.post_type='attachment' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') AND EXISTS (SELECT meta_value FROM " . $wpdb->postmeta . " meta WHERE meta.post_id=posts.ID and meta.meta_key='ic_stats')");


    $bulkStatus['foundImageCount'] = 0;
    $bulkStatus['foundThumbCount'] = 0;

    if ( $queryUncompressed ) {
      foreach ($queryUncompressed as $image) {
        $imageID = $image->ID;
        self::$uncompressedImages[$imageID] = $imageID;
      }
    }

    if ( $queryCompressed ) {
      foreach ($queryCompressed as $image) {
        $bulkStatus['foundImageCount'] += 1;
        $imageID = $image->ID;
        $fileUrl = wp_get_original_image_url($image->ID);
        self::$compressedImages[$imageID] = $fileUrl;
        set_transient('wps_ic_compress_' . $image->ID, 'compressing', 60);
        update_post_meta($image->ID, 'ic_bulk_running', 'true');
      }
    }

    update_option('wps_ic_BulkStatus', $bulkStatus);
    return ['compressed' => self::$compressedImages, 'uncompressed' => self::$uncompressedImages];
  }


  public function getUncompressedImages($action = 'compressing', $process = 'count') {
    // Raise the memory and time limit
    ini_set('memory_limit', '2024M');
    ini_set('max_execution_time', '180');

    global $wpdb;

    $uncompressedImages = [];
    $bulkStatus['foundImageCount'] = 0;
    $bulkStatus['foundThumbCount'] = 0;

    $queryUncompressed = $wpdb->get_results("SELECT ID FROM " . $wpdb->posts . " posts WHERE posts.post_type='attachment' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') AND NOT EXISTS (SELECT meta_value FROM " . $wpdb->postmeta . " meta WHERE meta.post_id=posts.ID and meta.meta_key='ic_stats') ORDER BY ID DESC");

    if ( $queryUncompressed ) {
      foreach ($queryUncompressed as $image) {
        $imageID = $image->ID;
        $bulkStatus['foundImageCount'] += 1;

        foreach (self::$imageSizes as $sizeName => $sizeData) {
          self::$uncompressedImages[$imageID] = $imageID;
          $bulkStatus['foundThumbCount'] += 1;
        }
      }
    }

    if ($action == 'compressing' && $process != 'count') {
      update_option('wps_ic_BulkStatus', $bulkStatus);
    }

    return ['uncompressed' => self::$uncompressedImages];
  }


  /**
   * Preparing images to send to API
   * @return Array Array of images
   */
  public function prepareImages($action = 'compressing', $process = 'count', $limit = '-1')
  {
    // Raise the memory and time limit
    ini_set('memory_limit', '2024M');
    ini_set('max_execution_time', '180');

    global $wpdb;

    self::$uncompressedImages = [];
    self::$compressedImages = [];

    if (!empty($_GET['dbgBulk'])) {
      ini_set('display_errors', 1);
      error_reporting(E_ALL);
    }

    $queryUncompressed = $wpdb->get_results("SELECT ID FROM " . $wpdb->posts . " posts WHERE posts.post_type='attachment' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') AND NOT EXISTS (SELECT meta_value FROM " . $wpdb->postmeta . " meta WHERE meta.post_id=posts.ID and meta.meta_key='ic_stats')");

    $queryCompressed = $wpdb->get_results("SELECT ID FROM " . $wpdb->posts . " posts WHERE posts.post_type='attachment' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') AND EXISTS (SELECT meta_value FROM " . $wpdb->postmeta . " meta WHERE meta.post_id=posts.ID and meta.meta_key='ic_stats')");


    $bulkStatus['foundImageCount'] = 0;
    $bulkStatus['foundThumbCount'] = 0;

    if ( $queryUncompressed ) {
      foreach ($queryUncompressed as $image) {
        $imageID = $image->ID;
        $bulkStatus['foundImageCount'] += 1;

        foreach (self::$imageSizes as $sizeName => $sizeData) {
          self::$uncompressedImages[$imageID][$sizeName] = 'unknown';
          $bulkStatus['foundThumbCount'] += 1;
        }
      }
    }

    if ( $queryCompressed ) {
      foreach ($queryCompressed as $image) {
        $imageID = $image->ID;
        self::$compressedImages[$imageID] = $imageID;
      }
    }

    if ($action == 'compressing' && $process != 'count') {
      update_option('wps_ic_BulkStatus', $bulkStatus);
    }

    return ['compressed' => self::$compressedImages, 'uncompressed' => self::$uncompressedImages];
  }

}