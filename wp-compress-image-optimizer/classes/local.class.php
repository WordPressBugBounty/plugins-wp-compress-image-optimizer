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
                    self::$apiUrl = 'https://' . $location['custom_server'] . '.zapwp.net/local/' . $apiVersion . '/';
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
        if ($local_server !== false && $local_server !== 'auto') {
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


    public function sendBulkRestoreToApi()
    {
        // Build full API URL
        $request_url = add_query_arg(array('imageSite' => self::$siteUrl, 'apikey' => self::$apikey), WPC_IC_LOCAL_BULK_RESTORE_START);

        // Make the GET request
        $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);

            if ($body == 'queue-prepared') {
                // all ok! call to run!
                $request_url = add_query_arg(array('imageSite' => self::$siteUrl, 'apikey' => self::$apikey), WPC_IC_LOCAL_BULK_RESTORE_RUN);

                // Make the GET request
                $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    return ['status' => 'success', 'apiUrl' => WPC_IC_LOCAL_BULK_RESTORE_RUN, 'body' => wp_remote_retrieve_body($response)];
                } else {
                    return ['status' => 'failed', 'step' => 'processing', 'status_code' => 200, 'reason' => $body, 'call' => print_r($response, true), 'body' => print_r($body, true)];
                }

            } else {
                return ['status' => 'failed', 'step' => 'bulk-start', 'status_code' => 200, 'reason' => $body, 'call' => print_r($response, true), 'body' => print_r($body, true)];
            }
        }

        return ['status' => 'success', 'apiUrl' => WPC_IC_LOCAL_BULK_RESTORE_RUN, 'body' => wp_remote_retrieve_body($response)];
    }


    public function sendBulkToApi()
    {
        // Build full API URL
        $request_url = add_query_arg(array('imageSite' => self::$siteUrl, 'apikey' => self::$apikey), WPC_IC_LOCAL_BULK_START);

        // Make the GET request
        $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);

            if ($body == 'queue-prepared') {
                // all ok! call to run!
                $request_url = add_query_arg(array('imageSite' => self::$siteUrl, 'apikey' => self::$apikey), WPC_IC_LOCAL_BULK_RUN);

                // Make the GET request
                $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    return ['status' => 'success', 'apiUrl' => WPC_IC_LOCAL_BULK_START, 'body' => wp_remote_retrieve_body($response)];
                } else {
                    return ['status' => 'failed', 'step' => 'processing', 'status_code' => 200, 'reason' => $body, 'call' => print_r($response, true), 'body' => print_r($body, true)];
                }

            } else {
                return ['status' => 'failed', 'step' => 'bulk-start', 'status_code' => 200, 'reason' => $body, 'call' => print_r($response, true), 'body' => print_r($body, true)];
            }
        }

        return ['status' => 'success', 'apiUrl' => WPC_IC_LOCAL_BULK_START, 'body' => wp_remote_retrieve_body($response)];
    }


    /**
     * Send a stream to API
     * @param $imageArray Array of images
     * @param $parameters Array of parameters from Settings
     * @return void
     */
    public function sendToAPI($action = '')
    {
        // Build full API URL
        $request_url = add_query_arg(array('imageSite' => self::$siteUrl, 'apikey' => self::$apikey), WPC_IC_LOCAL_BULK_STOP);

        // Make the GET request
        $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            return ['status' => 'success', 'apiUrl' => self::$apiUrl, 'body' => wp_remote_retrieve_body($response)];
        }

        return ['status' => 'success', 'apiUrl' => self::$apiUrl, 'body' => wp_remote_retrieve_body($response)];
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

        if ($queryUncompressed) {
            foreach ($queryUncompressed as $image) {
                $imageID = $image->ID;
                self::$uncompressedImages[$imageID] = $imageID;
            }
        }

        if ($queryCompressed) {
            foreach ($queryCompressed as $image) {
                $bulkStatus['foundImageCount'] += 1;
            }
        }

        update_option('wps_ic_BulkStatus', $bulkStatus);
        return ['compressed' => self::$compressedImages, 'uncompressed' => self::$uncompressedImages];
    }



    /**
     * Preparing images to send to API
     * @return Array Array of images
     */
    public function prepareImages($action = 'compressing', $process = 'count', $limit = '-1')
    {
        // Raise resource limits
        ini_set('memory_limit', '2024M');
        ini_set('max_execution_time', '300');

        global $wpdb;

        self::$uncompressedImages = [];
        self::$compressedImages = [];

        if (!empty($_GET['dbgBulk'])) {
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
        }

        $batch_size = 1000;
        $offset = 0;
        $bulkStatus = ['foundImageCount' => 0, 'foundThumbCount' => 0,];

        // --- Process UNCOMPRESSED images in batches
        while (true) {
            $uncompressed_ids = $wpdb->get_col($wpdb->prepare("
            SELECT posts.ID
            FROM {$wpdb->posts} posts
            WHERE posts.post_type = 'attachment'
              AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif')
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} meta
                  WHERE meta.post_id = posts.ID AND meta.meta_key = 'ic_stats'
              )
            LIMIT %d OFFSET %d", $batch_size, $offset));

            if (empty($uncompressed_ids)) break;

            foreach ($uncompressed_ids as $imageID) {
                $bulkStatus['foundImageCount']++;

                foreach (self::$imageSizes as $sizeName => $sizeData) {
                    self::$uncompressedImages[$imageID][$sizeName] = 'unknown';
                    $bulkStatus['foundThumbCount']++;
                }
            }

            $offset += $batch_size;

            if ($limit !== '-1' && $offset >= intval($limit)) {
                break;
            }
        }

        // --- Process COMPRESSED images in a single pass (still batched if needed)
        $offset = 0;
        while (true) {
            $compressed_ids = $wpdb->get_col($wpdb->prepare("
            SELECT posts.ID
            FROM {$wpdb->posts} posts
            WHERE posts.post_type = 'attachment'
              AND posts.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif')
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} meta
                  WHERE meta.post_id = posts.ID AND meta.meta_key = 'ic_stats'
              )
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

            if (empty($compressed_ids)) break;

            foreach ($compressed_ids as $imageID) {
                self::$compressedImages[$imageID] = $imageID;
            }

            $offset += $batch_size;

            if ($limit !== '-1' && $offset >= intval($limit)) {
                break;
            }
        }

        // Save to option if requested
        if ($action === 'compressing' && $process !== 'count') {
            update_option('wps_ic_BulkStatus', $bulkStatus);
        }

        return ['compressed' => self::$compressedImages, 'uncompressed' => self::$uncompressedImages,];
    }

}