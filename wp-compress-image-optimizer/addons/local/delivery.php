<?php

ini_set('log_errors', 1);
ini_set('error_log', WPS_IC_DIR . 'delivery-log.txt');
error_reporting(E_ALL);

class wpc_ic_delivery
{

    private static $siteApiKey;
    private static $apiVersion = 'v4';
    private static $apiURL = '';
    private static $local;
    public $pathToDir;
    public $sizes;
    public $type;
    public $didImages;
    private $apiKey;
    private $images;
    private $imagesToRequest;

    public function __construct($type = 'multi')
    {
        if (empty($_POST['apikey']) && empty($_GET['apikey'])) {
            wp_send_json_error('apikey-empty');
        }

        if (empty($_GET['imageID']) && empty($_POST['imageID']) && empty($_POST['images']) && empty($_GET['images']) && empty($_POST['getImageByID']) && empty($_GET['getImageByID']) && empty($_GET['getAllImages'])) {
            wp_send_json_error('images-array-empty');
        }

        self::$local = new wps_local_compress();

        $this->getAPIUrl();

        $this->enabledLog = 'true';
        $this->logFilePath = WPS_IC_DIR . 'delivery-log.txt';
        $this->logFile = fopen($this->logFilePath, 'a');

        $this->type = $type;

        if (!empty($_POST['apikey'])) {
            $this->apiKey = sanitize_text_field($_POST['apikey']);
        } elseif (!empty($_GET['apikey'])) {
            $this->apiKey = sanitize_text_field($_GET['apikey']);
        }

        $this->checkApiKey();

        /**
         * Fetch Image data by Image ID
         */
        $this->sizes = $this->getAllThumbSizes();

        if (!empty($_POST['imageID'])) {
            $this->images[$_POST['imageID']] = $_POST['imageID'];
        } elseif (!empty($_GET['imageID'])) {
            $this->images[$_GET['imageID']] = $_GET['imageID'];
        }

    }


    public function getAPIUrl()
    {
        $location = get_option('wps_ic_geo_locate_v2');
        if (empty($location)) {
            $location = $this->geoLocate();
        }

        if (is_object($location)) {
            $location = (array)$location;
        }
        if (isset($location) && !empty($location)) {
            if (is_array($location) && !empty($location['server'])) {
                if ($location['continent'] == 'CUSTOM') {
                    self::$apiURL = 'https://' . $location['custom_server'] . '.zapwp.net/local/' . self::$apiVersion . '/';
                } elseif ($location['continent'] == 'AS' || $location['continent'] == 'IN') {
                    self::$apiURL = 'https://singapore.zapwp.net/local/' . self::$apiVersion . '/';
                } elseif ($location['continent'] == 'EU') {
                    self::$apiURL = 'https://germany.zapwp.net/local/' . self::$apiVersion . '/';
                } elseif ($location['continent'] == 'OC') {
                    self::$apiURL = 'https://sydney.zapwp.net/local/' . self::$apiVersion . '/';
                } elseif ($location['continent'] == 'US' || $location['continent'] == 'NA' || $location['continent'] == 'SA') {
                    self::$apiURL = 'https://nyc.zapwp.net/local/' . self::$apiVersion . '/';
                } else {
                    self::$apiURL = 'https://germany.zapwp.net/local/' . self::$apiVersion . '/';
                }
            } else {
                self::$apiURL = 'https://' . $location->server . '/local/' . self::$apiVersion . '/';
            }
        } else {
            self::$apiURL = 'https://germany.zapwp.net/local/' . self::$apiVersion . '/';
        }

        $local_server = get_option('wps_ic_force_local_server');
        if ($local_server !== false && $local_server !== 'auto') {
            self::$apiURL = 'https://' . $local_server . '/local/' . self::$apiVersion . '/';
        }

    }


    public function checkApiKey()
    {
        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
            $apikey = get_option(WPS_IC_OPTIONS)['api_key'];
        } else {
            $apikey = get_option(WPS_IC_OPTIONS)['api_key'];
        }

        self::$siteApiKey = $apikey;

        if ($apikey !== $this->apiKey) {
            wp_send_json_error('apikey-not-matching');
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

    public function getImageList()
    {
        // Raise the memory and time limit
        ini_set('memory_limit', '2024M');
        ini_set('max_execution_time', '180');

        global $wpdb;
        $uncompressedImages = [];
        $compressedImages = [];

        $offset = 0;
        $debug = (!empty($_GET['debug'])) ? $_GET['debug'] : $_POST['debug'];
        $count = (!empty($_GET['getCount'])) ? $_GET['getCount'] : $_POST['getCount'];
        $imageCount = sanitize_text_field($count);

        if (empty($imageCount) || $imageCount == '0') {
            $imageCount = -1;
            $offset = 1;
        }

        $imageSizes = $this->getAllThumbSizes();

        $queryUncompressed = $wpdb->get_results("SELECT * FROM " . $wpdb->posts . " posts WHERE posts.post_type='attachment' AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') AND NOT EXISTS (SELECT meta_value FROM " . $wpdb->postmeta . " meta WHERE meta.post_id=posts.ID and meta.meta_key='ic_stats')");

        $bulkStatus['foundImageCount'] = 0;
        $bulkStatus['foundThumbCount'] = 0;

        if ($queryUncompressed) {
            foreach ($queryUncompressed as $image) {
                $originalFileUrl = wp_get_original_image_url($image->ID);
                $originalFileName = basename($originalFileUrl);
                $originalFileUrlClean = str_replace($originalFileName, '', $originalFileUrl);
                $bulkStatus['foundImageCount'] += 1;

                $alreadyListed = [];
                if (!empty($imageSizes)) {

                    $imageMeta = [];
                    foreach ($imageSizes as $sizeName => $sizeData) {
                        if ($sizeName == 'original') {
                            $fileUrl = $originalFileUrl;
                        } else {
                            if (empty($imageMeta)) {
                                $imageMeta = wp_get_attachment_metadata($image->ID);
                            }

                            $fileUrl = $originalFileUrlClean . $imageMeta['sizes'][$sizeName]['file'];
                        }

                        if (!empty($fileUrl) && $originalFileUrlClean !== $fileUrl) {
                            if (!in_array($fileUrl, $alreadyListed)) {
                                $uncompressedImages[$image->ID][$sizeName] = $fileUrl;
                            }
                        }
                        $bulkStatus['foundThumbCount'] += 1;
                    }
                }
            }
        }

        return $uncompressedImages;
    }

    public function getImageByID()
    {
        $getId = (!empty($_POST['getImageByID'])) ? $_POST['getImageByID'] : $_GET['getImageByID'];
        $this->getImageUrls($getId);
    }

    public function getImageUrls($imageID)
    {
        while (ob_get_level()) {
          ob_end_clean();
        }
        ob_start();

        $status = get_post_meta($imageID, 'ic_status', true);

        if (!empty($status) && $status == 'compressed') {
            wp_send_json_error('image-already-compressed-plugin');
        }

        $filePath = get_attached_file($imageID);
        $cleanPath = str_replace(basename($filePath), '', $filePath);

        $alreadyStored = [];

        if ($imageID) {
            foreach ($this->sizes as $size => $data) {

                if ($size == 'original') {
                    $image = wp_get_original_image_url($imageID);

                    if (!empty($image)) {
                        if (!in_array($image, $alreadyStored)) {
                            $imagesToCompress[$imageID][$size] = $image;
                        }
                    }
                } elseif ($size == 'full') {
                    $image = wp_get_attachment_image_src($imageID, $size);
                    if (!empty($image[0])) {
                        if (!in_array($image[0], $alreadyStored)) {
                            $imagesToCompress[$imageID][$size] = $image[0];
                        }
                    }
                } else {
                    $image = wp_get_attachment_image_url($imageID, $size);
                    if (!empty($image)) {
                        if (!in_array($image, $alreadyStored)) {
                            $imagesToCompress[$imageID][$size] = $image;
                        }
                    }
                }

            }

            wp_send_json_success($imagesToCompress);
        }

        wp_send_json_error('image-404');
    }


    public function compress()
    {
        if ($this->type == 'multi') {
            /**
             * Bulk Mode
             */
            $this->parseImages();
        } else {
            /**
             * Single Image Delivery
             */
            $this->parseImages('single');
        }
    }

    public function parseImages($type = 'multi')
    {
        if (empty($this->images) || !$this->images) wp_send_json_error('parsing-of-images-failed');

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
            echo 'Pre loop pre ' . print_r($this->images, true);
        }

        if (empty($this->images)) return;

        $this->imagesToRequest = [];
        foreach ($this->images as $imageID => $image) {
            $args = ['apikey' => self::$siteApiKey, 'imageID' => $imageID, 'action' => 'getCompressed'];
            $getCompressedImages = wp_remote_post(self::$apiURL, ['timeout' => 60, 'blocking' => true, 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($getCompressedImages) == 200) {

                if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                    echo 'response ' . print_r(wp_remote_retrieve_body($getCompressedImages), true);
                }

                // All is ok
                $returnedImages = wp_remote_retrieve_body($getCompressedImages);
                $returnedImages = json_decode($returnedImages);

                if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                    echo 'response-json ' . print_r($returnedImages, true);
                }

                if (!empty($returnedImages->data)) {
                    $this->images = $returnedImages->data;
                }
            } else {
                // We failed to get the images, try again or error!?
            }
        }

        $this->writeLog('Delivered Images:');
        $this->writeLog(print_r($this->images, true));


        $bulkStatus = [];
        $imagesParsed = [];

        if ($type == 'multi') {
            $bulkStatus = get_option('wps_ic_BulkStatus');
            $imagesParsed = get_option('wps_ic_parsed_images');
        }

        $this->writeLog('Started parsing');
        $this->writeLog(print_r($this->images, true));

        if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
            echo 'Pre loop ' . print_r($this->images, true);
        }

        if (empty($this->images)) {
            return false;
        }

        foreach ($this->images as $imageID => $image) {

            $this->writeLog('In loop ' . $imageID);

            if (get_post_meta($imageID, 'ic_status', true) == 'compressed' && empty($_POST['force'])) {
                $this->writeLog('Already compressed');
                if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                    echo 'continue its compressed';
                }
                continue;
            }

            $this->writeLog('Not Compressed ' . $imageID);


            if (!get_post_meta($imageID, 'wpc_old_meta', true)) {
                $this->writeLog('No old Meta ' . $imageID);
                $oldMeta = wp_get_attachment_metadata($imageID);
                update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
            }

            $this->writeLog('After no old meta ' . $imageID);

            $size_stats = [];

            // Get the clean path without filename
            $originalFilePath = wp_get_original_image_path($imageID);
            $originalFilename = wp_basename($originalFilePath);
            $this->pathToDir = str_replace($originalFilename, '', $originalFilePath);

            if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                echo 'Image Array ' . print_r($image, true);
            }

            if (empty($image)) {
                return false;
            }

            // TODO: You are assuming that the original will be the largest?
            // TODO: What if the original was optimized but thumbs were not (by some other app)?
            if (empty($image->original)) {
                $largest = 0;
                foreach ($image as $imageSize => $imageData) {
                    $imageData = (array)$imageData;
                    $currentSize = $imageData['original'];

                    if ($currentSize > $largest) {
                        $largest = $currentSize;
                        $largestSize = $imageSize;
                    }
                }
                $image->original = $image->$largestSize;
                $image->original->size = 'original';
            }


            foreach ($image as $imageSize => $imageData) {

                $imageData = (array)$imageData;

                if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                    echo 'Image Data ' . print_r($imageData, true);
                }

                $returnStats = $this->writeImage($imageID, $imageSize, $imageData);
                $this->writeLog('Writing image ' . $imageID . ' ' . $imageSize);

                if ($returnStats != 'compressed-bigger' && $returnStats != 'file-not-exists') {
                    $this->writeLog('Return: ' . print_r($returnStats, true));
                    $imagesParsed[$imageID][$imageSize]['original'] = $returnStats[$imageSize]['original']['size'];
                    $imagesParsed[$imageID][$imageSize]['compressed'] = $returnStats[$imageSize]['compressed']['size'];
                    $imagesParsed[$imageID]['total']['original'] = $returnStats['total']['original']['size'];
                    $imagesParsed[$imageID]['total']['compressed'] = $returnStats['total']['compressed']['size'];
                    $imagesParsed['total']['images_with_thumbs'] += 1;
                    $imagesParsed['total']['original'] += $imagesParsed[$imageID][$imageSize]['original'];
                    $imagesParsed['total']['compressed'] += $imagesParsed[$imageID][$imageSize]['compressed'];

                    // Merge stats
                    $size_stats = array_merge($size_stats, $returnStats);

                    if ($size_stats !== false) {
                        $bulkStatus['compressedImageCount'] += 1;
                        $bulkStatus['total']['original']['size'] += $returnStats['total']['original']['size'];
                        $bulkStatus['total']['compressed']['size'] += $returnStats['total']['compressed']['size'];
                    }
                }

                unset($imageData);
            }

            $imagesParsed['total']['images_pure'][] = $image;
            $stats = $size_stats;

            //if original == full sort out stats
            //      if (wp_get_original_image_url($imageID) == wp_get_attachment_image_src($imageID, 'full')[0]) {
            //        $stats['original']['original']['size'] = $stats['full']['original']['size'];
            //      }

            if (empty($stats['original']['original']['size'])) {
                $stats['original']['original']['size'] = $stats['full']['original']['size'];
            }

            $this->writeLog('Updating Image Stats ' . $imageID . ' compressed status');
            $this->writeLog('Updating Image Stats ' . $imageID . ' ' . print_r($stats, true));

            //update_post_meta($imageID, 'ic_compress_stats', $stats);
            update_post_meta($imageID, 'ic_status', 'compressed');
            update_post_meta($imageID, 'ic_stats', $stats);

            // Delete queue
            delete_post_meta($imageID, 'ic_bulk_running');
            delete_transient('wps_ic_compress_' . $imageID);

            // Add for heartbeat to pickup
            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

            // Edit meta for media gallery
            $meta = get_post_meta($imageID, '_wp_attachment_metadata', true);

            if (!empty($meta)) {
                $meta['filesize'] = $imagesParsed[$imageID]['full']['compressed']; // This is -scaled version of image
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size => $data) {
                        $meta['sizes'][$size]['filesize'] = $imagesParsed[$imageID][$size]['compressed'];
                    }
                }

                update_post_meta($imageID, '_wp_attachment_metadata', $meta);
            } else {
                // Something is broken, please rebuild meta tags
                $originalFilePath = wp_get_original_image_path($imageID);
                $meta = wp_generate_attachment_metadata($imageID, $originalFilePath);
                wp_update_attachment_metadata($imageID, $meta);
            }

            // TODO: Intentionally set here, maybe if it breaks on any specific image we still get the others parsed
            if ($type == 'multi') {
                update_option('wps_ic_parsed_images', $imagesParsed);
                update_option('wps_ic_BulkStatus', $bulkStatus);

                $imageSizes = count($this->getAllThumbSizes());
                $counter = get_option('wps_ic_bulk_counter');
                if ($counter !== false) {
                    $counter['images']++;
                    $counter['imagesAndThumbs'] += $imageSizes;
                    update_option('wps_ic_bulk_counter', $counter);
                } else {
                    $counter = [];
                    $counter['images'] = 1;
                    $counter['imagesAndThumbs'] = $imageSizes;
                    update_option('wps_ic_bulk_counter', $counter);
                }

            }

            unset($stats);
        }

        if ($type == 'multi') {
            set_transient('wps_ic_bulk_running', date('y-m-d H:i:s'), 60 * 5);
        }
    }

    public function writeLog($message)
    {
        if ($this->enabledLog == 'true') {
            fwrite($this->logFile, "[" . date('d.m.Y H:i:s') . "] " . $message . "\r\n");
        }
    }

    public function writeImage($imageID, $imageSize, $imageData)
  {
    $fileTypeError = false;
    $this->writeLog('Write Image Started ' . $imageID);
    if (!function_exists('download_url')) {
      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');
    }

    $stats = array();

    // Get Image Path
    if ($imageSize == 'original') {
      $fileName = wp_get_original_image_path($imageID);
      $fileName = basename($fileName);
    } else {
      $fileName = wp_get_attachment_image_src($imageID, $imageSize);
      $fileName = basename($fileName[0]);
    }

    $this->writeLog('Write Image #398 ' . $imageID);

    // Path to the imagesize
    $imagePath = $this->pathToDir . $fileName;

    // TODO: Remove
    //delete_post_meta($imageID,'wpc_images_compressed');

    $imagesCompressed = get_post_meta($imageID, 'wpc_images_compressed', true);
    $sanitizedURL = sanitize_title($imageData['url']);

    $this->writeLog('Write Image #409 ' . $imageID);
    $this->writeLog('Write Image #410 ' . print_r($this->didImages, true));

    if (!$imagesCompressed) $imagesCompressed = array();

    // TODO: Singapore je vraćao 0 savings zbog ovoga?

    if (!empty($this->didImages[$imageID][$sanitizedURL])) {

      // Is original smaller than compressed?
      if ($imagesCompressed[$sanitizedURL]['original'] < $imagesCompressed[$sanitizedURL]['compressed']) {
        // Compressed bigger than original
        $stats[$imageSize]['original']['size'] = $imagesCompressed[$sanitizedURL]['original'];
        $stats[$imageSize]['compressed']['size'] = $imagesCompressed[$sanitizedURL]['original'];
        $stats['total']['original']['size'] += $stats[$imageSize]['original']['size'];
        $stats['total']['compressed']['size'] += $stats[$imageSize]['original']['size'];
      } else {
        // Original is bigger than compressed
        // Get Original Size
        $stats[$imageSize]['original']['size'] = $imagesCompressed[$sanitizedURL]['original'];
        $stats[$imageSize]['compressed']['size'] = $imagesCompressed[$sanitizedURL]['compressed'];
        $stats['total']['original']['size'] += $stats[$imageSize]['original']['size'];
        $stats['total']['compressed']['size'] += $stats[$imageSize]['compressed']['size'];
      }

      $this->writeLog('Already done this image: ' . $imageSize);
      $this->writeLog('Sanitized url: ' . $sanitizedURL);
      $this->writeLog('Array: ' . print_r($imagesCompressed, true));
      $this->writeLog('$imagesCompressed[$sanitizedURL][url]: ' . print_r($imagesCompressed[$sanitizedURL]['url'], true));
      $this->writeLog('$imageData[url]: ' . print_r($imageData['url'], true));
    } else {
      // Did we already do the image?
      $this->didImages[$imageID][$sanitizedURL] = $imagePath;

      if (!file_exists($imagePath)) {
        $this->writeLog('File not found: ' . $imagePath);
        return 'file-not-exists';
      }

      $this->writeLog('Write Image #437 ' . $imageID);

      // Get Original Size
      $stats[$imageSize]['original']['size'] = filesize($imagePath);
      $stats['total']['original']['size'] += $stats[$imageSize]['original']['size'];

      // Compare to compressed - FAILSAFE
      if ($imageData['original'] <= $imageData['compressed']) {
        $this->writeLog('Compressed is bigger than original.');
        //return 'compressed-bigger';
      } else {
        $this->writeLog('Write Image Before Download ' . $imageID);
        $this->writeLog($imageData['url']);

        // It's an URL
        $imageDownload = download_url($imageData['url']);

        $this->writeLog('Write Image After Download ' . $imageID);
        $this->writeLog(print_r($imageDownload, true));

        if (!$imageDownload) {
          $this->writeLog('Failed to download.');
          $this->writeLog(print_r($imageDownload, true));
        } else {

          // Verify if the downloaded file is an image
          if (function_exists('mime_content_type')){
            $mime_type = mime_content_type($imageDownload);
          } else if (function_exists('finfo_open')) {
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $imageDownload);
            finfo_close($file_info);
          } else {
            $mime_type = wp_get_image_mime($imageDownload);
          }

          if (in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif'])) {
            $image_size = getimagesize($imageDownload);
            if ($image_size !== false) {
              // File is an image, proceed with your logic
            } else {
              // File is not a valid image, handle the error
              $this->writeLog('Downloaded file is not a valid image.');
              return false;
            }
          } else {
            // File MIME type is not an image, handle the error
            $this->writeLog('Downloaded file MIME type is not an image.');
            return false;
          }

          $exif = 'image/jpeg';
          $mime = 'image/jpeg';

          // Allowed
          $allowed_file_types = array('image/png', 'image/jpeg', 'image/jpg', 'image/webp');
          $this->writeLog('Allowed types');
          $this->writeLog(print_r($allowed_file_types, true));
          $this->writeLog('Mime');
          $this->writeLog(print_r($mime, true));
          $this->writeLog('In array');
          $this->writeLog(print_r(in_array($mime, $allowed_file_types), true));

          if (!in_array($mime, $allowed_file_types)) {
            $fileTypeError = true;
            $this->writeLog('Not allowed file type.');
            $this->writeLog($imageData['url']);
            $this->writeLog(print_r($imageDownload, true));
            $this->writeLog(print_r($exif, true));
            $this->writeLog(print_r($mime, true));
            $this->writeLog(in_array($mime, $allowed_file_types));
          } else {

            // Check if original is bigger than compressed, failsafe
            if ($stats[$imageSize]['original']['size'] > filesize($imageDownload)) {
              if (file_exists($imagePath)) {
                unlink($imagePath);
              }

              $c = copy($imageDownload, $imagePath);
              $this->writeLog(print_r($c, true));
            } else {
              $this->writeLog('Filesize bad?.');
              $this->writeLog($stats[$imageSize]['original']['size']);
              $this->writeLog(filesize($imageDownload));
            }

          }

        }
      }

      $this->writeLog('Copied image ' . $imagePath);

      $settings = get_option(WPS_IC_SETTINGS);

      if (!empty($settings['generate_webp']) && $settings['generate_webp'] == '1'  && !empty($imageData['url_webp'])) {
        $webpPath = str_replace(['.png', '.jpg', '.jpeg'], '.webp', $imagePath);

        // It's an URL
        $imageWebpDownload = download_url($imageData['url_webp']);

        // Verify if the downloaded file is an image
        //	      $file_info = finfo_open(FILEINFO_MIME_TYPE);
        //	      $mime_type = finfo_file($file_info, $imageWebpDownload);
        //	      finfo_close($file_info);

        if (function_exists('mime_content_type')){
          $mime_type = mime_content_type($imageWebpDownload);
        } else if (function_exists('finfo_open')) {
          $file_info = finfo_open(FILEINFO_MIME_TYPE);
          $mime_type = finfo_file($file_info, $imageWebpDownload);
          finfo_close($file_info);
        } else {
          $mime_type = wp_get_image_mime($imageWebpDownload);
        }

        if (in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
          $image_size = getimagesize($imageWebpDownload);
          if ($image_size !== false) {
            // File is an image, proceed with your logic
          } else {
            // File is not a valid image, handle the error
            $this->writeLog('Downloaded file is not a valid image.');
            return false;
          }
        } else {
          // File MIME type is not an image, handle the error
          $this->writeLog('Downloaded file MIME type is not an image.');
          return false;
        }

        $mime = 'image/jpeg';
        $stats[$imageSize]['webp_path'] = '';
        // Allowed
        $allowed_file_types = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
        if (!in_array($mime, $allowed_file_types)) {
          $fileTypeError = true;
        } else {
          // Check if original is bigger than compressed, failsafe
          if ($stats[$imageSize]['original']['size'] > filesize($imageWebpDownload)) {
            if (file_exists($webpPath)) {
              unlink($webpPath);
            }
            copy($imageWebpDownload, $webpPath);
            $stats[$imageSize]['webp_path'] = $webpPath;
          }

        }


      }


      if (!$fileTypeError) {
        // Get Compressed Size
        $stats[$imageSize]['compressed']['size'] = filesize($imagePath);
        $stats['total']['compressed']['size'] += $stats[$imageSize]['compressed']['size'];

        $imagesCompressed[$sanitizedURL] = array('url' => $imageData['url'], 'original' => $stats[$imageSize]['original']['size'], 'compressed' => $stats[$imageSize]['compressed']['size'], 'webp_path' => $stats[$imageSize]['webp_path']);

        update_post_meta($imageID, 'wpc_images_compressed', $imagesCompressed);
      } else {
        $this->writeLog('File Type error');
        $this->writeLog(print_r($fileTypeError, true));
        update_post_meta($imageID, 'ic_compressing', array('status' => 'no-further'));
      }

      unset($imageDownload, $imagePath, $imagesCompressed, $imagesCompressed);
    }
    $this->writeLog('Write Image Ended ' . $imageID);
    $this->writeLog('Write Image Stats ' . print_r($stats, true));
    return $stats;
  }

    public function disable_scaling()
    {
        return false;
    }


    public function restoreImage()
    {
        $imageID = $_GET['imageID'];
        $imageURL = $_GET['imageURL'];

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        if (!function_exists('update_option')) {
            require_once(ABSPATH . "wp-includes" . '/option.php');
        }

        $output = [];

        wp_raise_memory_limit('image');
        ini_set('memory_limit', '1024M');

        $olderVersionBackup = self::$local->olderBackup($imageID);
        if ($olderVersionBackup) {
            return true;
        }

        // Is the image in process
        $inProcess = get_post_meta($imageID, 'ic_bulk_running', true);
        if ($inProcess && $inProcess == 'true') {
        }

        $this->writeLog('Started Image ID ' . $imageID);

        $imagePath = wp_get_original_image_path($imageID);

        $downloadImage = download_url($imageURL);

	  if (is_wp_error($downloadImage)) {
		  // Log and handle the error
		  $this->writeLog('Unable to download Image');
		  $this->writeLog($imageURL);
		  $this->writeLog(print_r($downloadImage, true));
		  $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);

            if ($output == 'json') {
                wp_send_json_error([
                    'msg' => 'failed-to-get-backup',
                    'apiUrl' => self::$apiURL,
                    'imageID' => $imageID,
                    'url' => $downloadImage
                ]);
            }

            return false;
        } else {
            // Verify if the downloaded file is an image
//		  $file_info = finfo_open(FILEINFO_MIME_TYPE);
//		  $mime_type = finfo_file($file_info, $downloadImage);
//		  finfo_close($file_info);

      // Verify if the downloaded file is an image
      if (function_exists('mime_content_type')){
        $mime_type = mime_content_type($downloadImage);
      } else if (function_exists('finfo_open')) {
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $downloadImage);
        finfo_close($file_info);
      } else {
        $mime_type = wp_get_image_mime($downloadImage);
      }

		  if (in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif'])) {
			  $imageSize = getimagesize($downloadImage);
			  if ($imageSize !== false) {
				  // File is an image, proceed with your logic
			  } else {
				  // File is not a valid image, handle the error
				  $this->writeLog('Downloaded file is not a valid image.');
				  return false;
			  }
		  } else {
			  // File MIME type is not an image, handle the error
			  $this->writeLog('Downloaded file MIME type is not an image.');
			  return false;
		  }
	  }


	  if (file_exists($imagePath)) {
      unlink($imagePath);
    }


        if (file_exists($imagePath)) {
            unlink($imagePath);
        }

        copy($downloadImage, $imagePath);
        unset($downloadImage);

        // Delete WebP
        $filename = basename($imagePath);
        $path = str_replace($filename, '', $imagePath);
        $path = rtrim($path, '/');
        $dir = new DirectoryIterator($path);
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                /* Stupid fix, try to figure out what is the original.. find scaled filename and remove scaled (lol) if not
                exists then use scaled as original */

                if (strpos($fileinfo->getFilename(), '.webp') !== false) {
                    unlink($path . '/' . $fileinfo->getFilename());
                }
            }
        }

        $bulkStatus = get_option('wps_ic_BulkStatus');
        $imagesParsed = get_option('wps_ic_parsed_images');


        if (!$bulkStatus) {
            $bulkStatus['restoredImageCount'] = 0;
        }

        if (!$imagesParsed) {
            $imagesParsed['total']['images'] = 0;
        }

        $imagesParsed[$imageID]['restored'] = 'true';
        $bulkStatus['restoredImageCount'] += 1;

        update_option('wps_ic_BulkStatus', $bulkStatus);
        update_option('wps_ic_parsed_images', $imagesParsed);


        // Remove meta tags
        delete_post_meta($imageID, 'wpc_images_compressed');
        delete_post_meta($imageID, 'ic_stats');
        delete_post_meta($imageID, 'ic_compressed_images');
        delete_post_meta($imageID, 'ic_compressed_thumbs');
        delete_post_meta($imageID, 'ic_backup_images');
        update_post_meta($imageID, 'ic_status', 'restored');
        delete_transient('wps_ic_compress_' . $imageID);


        // Add for heartbeat to pickup
        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

        //Moved this to the end because on large images wp_generate_attachment_metadata giver error, even tho it does
        // regenerate thumbs
        $originalFilePath = wp_get_original_image_path($imageID);
        $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
        wp_update_attachment_metadata($imageID, $oldMeta);

        $this->writeLog('Ended Image ID - restored ' . $imageID);

        if ($output == 'json') {
            wp_send_json_success(['msg' => 'backup-restored']);
        }

    }


    public function restore()
    {
        @set_time_limit(900);

        if (empty($this->images) || !$this->images) wp_send_json_error('parsing-of-images-failed');

        if (!function_exists('wp_generate_attachment_metadata')) {
            include(ABSPATH . 'wp-admin/includes/image.php');
        }

        if (!function_exists('download_url')) {
            include(ABSPATH . 'wp-admin/includes/file.php');
        }

        $bulkStatus = get_option('wps_ic_BulkStatus');
        $imagesParsed = get_option('wps_ic_parsed_images');
        $restoreStats = get_option('wps_ic_restoreStats');

        if (!$bulkStatus) {
            $bulkStatus['restoredImageCount'] = 0;
        }

        if (!$restoreStats) {
            $restoreStats['compressed']['size'] = 0;
            $restoreStats['original']['size'] = 0;
        }

        if (!$imagesParsed) {
            $imagesParsed['total']['images'] = 0;
        }

        $this->imagesToRequest = [];
        foreach ($this->images as $imageID => $image) {
            $args = ['apikey' => self::$siteApiKey, 'imageID' => $imageID, 'action' => 'getRestore'];
            $getCompressedImages = wp_remote_post(self::$apiURL, ['timeout' => 30, 'blocking' => true, 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($getCompressedImages) == 200) {

                if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                    echo 'response ' . print_r(wp_remote_retrieve_body($getCompressedImages), true);
                }

                // All is ok
                $returnedImages = wp_remote_retrieve_body($getCompressedImages);
                $returnedImages = json_decode($returnedImages);

                if (!empty($_POST['debug']) || !empty($_GET['debug'])) {
                    echo 'response-json ' . print_r($returnedImages, true);
                }

                if (!empty($returnedImages->data)) {
                    $this->images = $returnedImages->data;
                }
            } else {
                // We failed to get the images, try again or error!?
            }
        }


        foreach ($this->images as $imageID => $image) {

            self::$local->restore($imageID, false);
            $imagesParsed[$imageID]['restored'] = 'true';

            // Remove meta tags
            delete_post_meta($imageID, 'wpc_images_compressed');
            delete_post_meta($imageID, 'ic_compressing');
            delete_post_meta($imageID, 'ic_stats');
            delete_post_meta($imageID, 'ic_compressed_images');
            delete_post_meta($imageID, 'ic_compressed_thumbs');
            delete_post_meta($imageID, 'ic_backup_images');
            update_post_meta($imageID, 'ic_status', 'restored');
            delete_post_meta($imageID, 'ic_bulk_running');
            delete_transient('wps_ic_compress_' . $imageID);

            $bulkStatus['restoredImageCount'] += 1;
        }

        update_option('wps_ic_restoreStats', $restoreStats);
        update_option('wps_ic_BulkStatus', $bulkStatus);
        update_option('wps_ic_parsed_images', $imagesParsed);

        wp_send_json_success();
    }

}