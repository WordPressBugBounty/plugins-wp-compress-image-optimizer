<?php
/**
 * Local Compress
 * @since 5.00.59
 */


class wps_local_compress
{

    private static $allowed_types;
    private static $apiURL;
    private static $siteUrl;
    private static $apiParams;
    private static $settings;
    private static $options;
    private static $zone_name;
    private static $backup_directory;
    public $webp_sizes;
    public $sizes;
    public $total_sizes;
    public $compressed_list;

    public $enabledLog;
    public $logFile;
    public $logFilePath;
    public $pathToDir;


    public function __construct()
    {
        global $wps_ic;
        global $wpc_filesystem;

        $this->enabledLog = 'true';

        $this->logFilePath = WPS_IC_LOG . 'compress-log.txt';
        $this->logFile = fopen($this->logFilePath, 'a');

        $this->get_filesystem();

        $this->total_sizes = count(get_intermediate_image_sizes());
        $this->sizes = $this->getAllThumbSizes();
        $this->webp_sizes = get_intermediate_image_sizes();
        $uploads_dir = wp_upload_dir();

        self::$allowed_types = ['jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'png' => 'png'];
        self::$backup_directory = $uploads_dir['basedir'] . '/wp-compress-backups';
        self::$settings = get_option(WPS_IC_SETTINGS);
        self::$options = get_option(WPS_IC_OPTIONS);
        self::$siteUrl = site_url();

        /**
         * If backup directories don't exist, create them
         */
        if (!file_exists(self::$backup_directory)) {
            $made_dir = mkdir(self::$backup_directory, 0755);
            if (!$made_dir) {
                update_option('wpc_errors', ['unable-to-create-backup-dir' => self::$backup_directory]);
            } else {
                delete_option('wpc_errors');
            }
        }


        add_action('delete_attachment', [$this, 'on_delete']);

        if (!empty(self::$settings['on-upload']) && self::$settings['on-upload'] == '1' && empty($_GET['restoreImage'])) {
            /*
             * This works but uploads a full sized image to storage for every size variation
             */

            add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);
            // TODO: Causing problems with showing 0% saved, while actually compressed
        }

        if (empty(self::$settings['cname']) || !self::$settings['cname']) {
            self::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            self::$zone_name = get_option('ic_custom_cname');
        }

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

                if (empty($location['continent'])) {
                    $location['continent'] = '';
                }

                if ($location['continent'] == 'CUSTOM') {
                    self::$apiURL = 'https://' . $location['custom_server'] . '.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'AS' || $location['continent'] == 'IN') {
                    self::$apiURL = 'https://singapore.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'EU') {
                    self::$apiURL = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'OC') {
                    self::$apiURL = 'https://sydney.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'US' || $location['continent'] == 'NA' || $location['continent'] == 'SA') {
                    self::$apiURL = 'https://nyc.zapwp.net/local/' . $apiVersion . '/';
                } else {
                    self::$apiURL = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
                }
            } else {
                self::$apiURL = 'https://' . $location->server . '/local/' . $apiVersion . '/';
            }
        } else {
            self::$apiURL = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
        }

        $local_server = get_option('wps_ic_force_local_server');
        if ($local_server !== false && $local_server !== 'auto') {
            self::$apiURL = 'https://' . $local_server . '/local/' . $apiVersion . '/';
        }

        if (!isset(self::$options['api_key'])) {
            self::$options['api_key'] = '';
        }

        if (empty(self::$settings)) {
            $options = new wps_ic_options();
            $settings = $options->get_preset('lite');
            self::$settings = $settings;
        }

        if (!isset(self::$settings['optimization'])) {
            self::$settings['optimization'] = '';
        }

        // Setup paraams for POST to API
        self::$apiParams = [];
        self::$apiParams['apikey'] = self::$options['api_key'];
        self::$apiParams['quality'] = self::$settings['optimization'];
        self::$apiParams['retina'] = 'false';
        self::$apiParams['webp'] = 'false';
        self::$apiParams['width'] = 'false';
        self::$apiParams['url'] = '';
    }

    public function get_filesystem()
    {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php');
        global $wpc_filesystem;

        if (!defined('FS_CHMOD_DIR')) {
            define('FS_CHMOD_DIR', (fileperms(ABSPATH) & 0777 | 0755));
        }

        if (!defined('FS_CHMOD_FILE')) {
            define('FS_CHMOD_FILE', (fileperms(ABSPATH . 'index.php') & 0777 | 0644));
        }

        if (!isset($wpc_filesystem) || !is_object($wpc_filesystem)) {
            $wpc_filesystem = new WP_Filesystem_Direct('');
        }
    }

    public function getAllThumbSizes()
    {
        $cache_key = 'wps_ic_image_sizes';

        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        global $_wp_additional_image_sizes;

        $default_image_sizes = get_intermediate_image_sizes();
        $image_sizes = [];

        foreach ($default_image_sizes as $size) {
            $crop = get_option("{$size}_crop");

            $image_sizes[$size]['width']  = intval(get_option("{$size}_size_w"));
            $image_sizes[$size]['height'] = intval(get_option("{$size}_size_h"));
            $image_sizes[$size]['crop']   = $crop ? $crop : false;
        }

        if (isset($_wp_additional_image_sizes) && count($_wp_additional_image_sizes)) {
            $image_sizes = array_merge($image_sizes, $_wp_additional_image_sizes);
        }

        $AdditionalSizes = ['full'];
        foreach ($AdditionalSizes as $size) {
            $image_sizes[$size]['width'] = 'full';
        }

        $image_sizes['original']['width'] = 'original';

        set_transient($cache_key, $image_sizes, 1 * HOUR_IN_SECONDS);

        return $image_sizes;
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

    public function routes()
    {

        $this->fetchImages();
        $this->restoreImage();
        $this->downloadImages();
        $this->initBulk();
    }





    public function registerEndpoints() {
        register_rest_route('wpc/v1', '/fetch', [
            'methods'             => [\WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE],
            'callback'            => [$this, 'wpc_handle_fetch_image'],
            'permission_callback' => [$this, 'wpc_permission_api_key'],
        ]);
    }

    public function wpc_permission_api_key(\WP_REST_Request $request) {
        // Read header-based key (preferred)
        $provided = $request->get_header('x-api-key');

        // Fallback: Authorization: Bearer <key>
        if (!$provided) {
            $auth = $request->get_header('authorization');
            if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
                $provided = trim($m[1]);
            }
        }

        $expected = $this->wpc_get_expected_api_key($provided);
        if (!$expected) {
            return new \WP_Error('wpc_no_api_key', 'API key not configured on server', ['status' => 500]);
        }

        if (!$provided || !hash_equals((string) $expected, (string) $provided)) {
            return new \WP_Error('wpc_forbidden', 'Invalid API key', ['status' => 403]);
        }

        return true;
    }

    /**
     * Prefer defining the key in wp-config.php:
     *   define('WPC_API_KEY', 'your-long-random-secret');
     * Or set an option 'wpc_api_key'.
     */
    public function wpc_get_expected_api_key($apikey) {
        $options = get_option(WPS_IC_OPTIONS);
         $expected_token = $options['api_key'];

        if (empty($apikey) || $apikey !== $expected_token) {
            wp_send_json_error('Unauthorized: apikey ' . $apikey, 403);
        }

        // if API Key is Valid Setup the PHP Limits
        $this->raiseLimits();
        return $expected_token;
    }

    /**
     * Main handler: returns original, thumb, filesizes (and unscaled if present).
     */
    public function wpc_handle_fetch_image(\WP_REST_Request $request) {
        $image_id = (int) $request->get_param('image_id');

        if ( ! $image_id ) {
            $image_id = $request->get_header('x-image-id');
        }

        if (!$image_id) {
            return new \WP_Error('wpc_bad_request', 'Invalid image ID', ['status' => 401]);
        }

        $post = get_post($image_id);
        if (!$post || get_post_type($image_id) !== 'attachment') {
            return new \WP_Error('wpc_bad_request', 'Invalid image ID', ['status' => 402]);
        }

        // Save OLD post meta for restore usage (once)
        if (!get_post_meta($image_id, 'wpc_old_meta', true)) {
            $oldMeta = wp_get_attachment_metadata($image_id);
            if (!empty($oldMeta)) {
                update_post_meta($image_id, 'wpc_old_meta', $oldMeta);
            }
        }

        // Top-level fields
        $original = wp_get_attachment_url($image_id);
        $thumbArr = wp_get_attachment_image_src($image_id, 'thumbnail');
        $thumb    = is_array($thumbArr) && !empty($thumbArr[0]) ? $thumbArr[0] : '';

        // Build filesizes from attachment metadata (includes all custom sizes)
        $filesizes  = [];
        $meta       = wp_get_attachment_metadata($image_id);
        $uploads    = wp_upload_dir();

        if (!empty($meta) && !empty($meta['file'])) {
            // Base directory like "2025/08"
            $subdir   = ltrim(dirname($meta['file']), './\\');
            $baseUrl  = trailingslashit($uploads['baseurl']) . ($subdir ? trailingslashit($subdir) : '');

            // Every generated intermediate size that exists on disk
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sizeName => $info) {
                    if (!empty($info['file'])) {
                        // Preserve the size key EXACTLY as stored in metadata (even if it has spaces)
                        $filesizes[$sizeName] = $baseUrl . $info['file'];
                    }
                }
            }

            // Add "unscaled" if a non -scaled original exists
            if (!empty($original)) {
                $origRelPath = $meta['file']; // e.g. 2025/08/file-scaled.jpeg
                if (strpos($origRelPath, '-scaled.') !== false) {
                    $unscaledRel = str_replace('-scaled.', '.', $origRelPath);
                    $unscaledAbs = path_join($uploads['basedir'], $unscaledRel);
                    if (file_exists($unscaledAbs)) {
                        $filesizes['unscaled'] = trailingslashit($uploads['baseurl']) . $unscaledRel;
                    }
                }
            }
        }

        // Ensure "thumbnail" key is present in filesizes (nice to have)
        if ($thumb && !isset($filesizes['thumbnail'])) {
            $filesizes['thumbnail'] = $thumb;
        }

        // Final payload in the exact shape requested
        $payload = [
            'original'  => $original ?: '',
            'thumb'     => $thumb ?: '',
            'filesizes' => $filesizes,
        ];

        $response = new \WP_REST_Response($payload, 200);
        $response->set_headers([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
            'Content-Type'  => 'application/json; charset=' . get_option('blog_charset'),
        ]);
        return $response;
    }

    /**
     * Function to verify if API Key is set and valid
     * @return void
     */
    public function checkAPIKey()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = sanitize_text_field($_GET['apikey']) ?? '';
        $expected_token = $options['api_key'];

        if (empty($apikey) || $apikey !== $expected_token) {
            wp_send_json_error('Unauthorized', 403);
        }

        // if API Key is Valid Setup the PHP Limits
        $this->raiseLimits();
        return $expected_token;
    }


    /**
     * Raise PHP / Server Limits
     * @return void
     */
    public function raiseLimits() {
        wp_raise_memory_limit('image');
        ini_set('memory_limit', '1024M');
    }


    public function restoreImage()
    {
        if (isset($_GET['restoreImage'])) {

            // Check if API Key is valid
            $this->checkAPIKey();

            if (!function_exists('download_url')) {
                require_once(ABSPATH . "wp-admin" . '/includes/image.php');
                require_once(ABSPATH . "wp-admin" . '/includes/file.php');
                require_once(ABSPATH . "wp-admin" . '/includes/media.php');
            }

            if (!function_exists('update_option')) {
                require_once(ABSPATH . "wp-includes" . '/option.php');
            }

            $imageID = absint($_GET['restoreImage']);
            if (!$imageID) {
                wp_send_json_error('Invalid image ID', 400);
            }

            $parsedImages = get_option('wps_ic_parsed_images');

            if (!$parsedImages) {
                $parsedImages = [];
                $parsedImages['total']['original'] = 0;
                $parsedImages['total']['compressed'] = 0;
            }

            // Site URL where this plugin runs
            $site_url = get_site_url();

            // Build full API URL
            $request_url = add_query_arg(array('imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'],), WPC_IC_LOCAL_RESTORE);

            // Make the GET request
            $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (!empty($data['backupUrls'])) {

                $parsedImages[$imageID]['unscaled']['original'] = '';


                $unscaledUrl = null;

                // Find the unscaled
                foreach ($data['backupUrls'] as $file) {
                    if ($file['sizeLabel'] === 'unscaled') {
                        $unscaledUrl = $file['fileUrl'];


                        $originalFilePath = get_attached_file($imageID);
                        $filePath = str_replace(basename($originalFilePath), '', $originalFilePath);
                        $unscaledBasename = basename($unscaledUrl);
                        $unscaledPath = $filePath . $unscaledBasename;

                        // Download to temp file
                        $tmp = download_url($unscaledUrl);
                        if (is_wp_error($tmp)) {
                            continue;
                        }

                        copy($tmp, $unscaledPath);
                        @unlink($tmp);

                        break;
                    }
                }


                foreach ($data['backupUrls'] as $index => $backupFile) {
                    $size = $backupFile['sizeLabel'];

                    // We just require the original
                    if ($size !== 'original') {
                        continue;
                    }

                    $url = $backupFile['fileUrl'];

                    // Download to temp file
                    $tmp = download_url($url);
                    if (is_wp_error($tmp)) {
                        continue;
                    }

                    $originalFilePath = get_attached_file($imageID);
                    if (!$originalFilePath) {
                        @unlink($tmp);
                        continue;
                    }

                    // Replace the file
                    copy($tmp, $originalFilePath);
                    @unlink($tmp);

                    // Remove backup
                    if (file_exists($originalFilePath . '_bkp')) {
                        @unlink($originalFilePath . '_bkp');
                    }

                    // generira iz scaled verzije opet
                    $unscaledPath = str_replace('-scaled.', '.', $originalFilePath);
                    if (file_exists($unscaledPath)) {
                        $originalFilePath = $unscaledPath;
                    }

                    $newMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);

                    if ($newMeta) {
                        wp_update_attachment_metadata($imageID, $newMeta);
                    }

                    // Optional status flag
                    delete_post_meta($imageID, 'ic_bulk_running');
                    delete_post_meta($imageID, 'ic_compressing');
                    delete_post_meta($imageID, 'wpc_images_compressed');
                    delete_post_meta($imageID, 'ic_stats');
                    update_post_meta($imageID, 'ic_status', 'restored');
                }

                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);
            }

            $bulkStatus = get_option('wps_ic_BulkStatus');

            if (empty($bulkStatus['restoredImageCount'])) {
                $bulkStatus['restoredImageCount'] = 0;
            }

            $bulkStatus['restoredImageCount'] += 1;
            update_option('wps_ic_BulkStatus', $bulkStatus);


            update_option('wps_ic_parsed_images', $parsedImages);

            wp_send_json_success();
        }
    }


    /**
     * Get a List of All Images to Compress
     * @return void
     */
    public function fetchImages()
    {
        if (isset($_GET['fetchImageByID'])) {

            // Check if API Key is valid
            $this->checkAPIKey();

            $image_id = absint($_GET['fetchImageByID']);
            if (!$image_id) {
                wp_send_json_error('Invalid image ID', 400);
            }

            if (!get_post($image_id) || get_post_type($image_id) !== 'attachment') {
                wp_send_json_error('Invalid image ID', 400);
            }

            // Save OLD post meta for restore usage
            if (!get_post_meta($image_id, 'wpc_old_meta')) {
                $oldMeta = wp_get_attachment_metadata($image_id);
                update_post_meta($image_id, 'wpc_old_meta', $oldMeta);
            }

            $original = wp_get_attachment_url($image_id);
            $thumb = wp_get_attachment_image_src($image_id, 'thumbnail')[0];

            $sizes = [];
            $available_sizes = get_intermediate_image_sizes();

            foreach ($available_sizes as $size) {
                $image_data = wp_get_attachment_image_src($image_id, $size);
                if (!empty($image_data[0])) {
                    $sizes[$size] = $image_data[0]; // include full size too
                }
            }

            // Add real original (unscaled) image if available
            $meta = wp_get_attachment_metadata($image_id);
            $upload_dir = wp_upload_dir();

            if (!empty($meta['file'])) {
                $original_path = path_join($upload_dir['basedir'], $meta['file']);
                $original_url = trailingslashit($upload_dir['baseurl']) . $meta['file'];

                // Add real original (unscaled) image if available
                if (!empty($original)) {
                    $unscaledFilePath = str_replace('-scaled.', '.', $original_path);
                    $unscaledFileUrl = str_replace('-scaled.', '.', $original_url);
                    if (file_exists($unscaledFilePath)) {
                        $sizes['unscaled'] = $unscaledFileUrl;
                    }
                }
            }

            wp_send_json(['original' => $original, 'thumb' => $thumb, 'filesizes' => $sizes]);
        }
    }


    /**
     * Download Compressed Image from API
     * @return void
     */
    public function downloadImages()
    {
        if (isset($_GET['downloadImage'])) {

            // Check if API Key is valid
            $expected_token = $this->checkAPIKey();

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $apiStatus = sanitize_text_field($_GET['status']);
            $isBulk = sanitize_text_field($_GET['bulk']) ?? false;
            $imageID = absint($_GET['downloadImage']);
            if (!$imageID) {
                wp_send_json_error('Invalid image ID', 400);
            }

            // Get original image URL to extract filename
            $original_url = wp_get_attachment_url($imageID);

            if (empty($original_url) || is_wp_error($original_url)) {
                wp_send_json_error('Invalid image ID', 400);
            }

            $basename = basename($original_url);

            // Skip the image, some error on API Side Occured
            if (!empty($apiStatus) && $apiStatus == 'skip') {
                // Stats
                $stats = [];
                $stats['original']['original']['size'] = 0;
                $stats['original']['compressed']['size'] = 0;
                $stats['original']['compressed']['thumbs'] = 0;

                // Parsed Images Array
                $parsedImages = get_option('wps_ic_parsed_images');

                if (!$parsedImages) {
                    $parsedImages = [];
                    $parsedImages['total']['original'] = 0;
                    $parsedImages['total']['compressed'] = 0;
                }

                // Flag for Bulk Memory
                if ($isBulk) {
                    $thumbCount = $this->getAllThumbSizes();
                    $bulkStatus = get_option('wps_ic_BulkStatus');

                    $parsedImages['total']['original'] += $stats['original']['original']['size'];
                    $parsedImages['total']['compressed'] += $stats['original']['compressed']['size'];

                    $parsedImages[$imageID]['total']['original'] = $parsedImages['total']['original'];
                    $parsedImages[$imageID]['total']['compressed'] = $parsedImages['total']['compressed'];

                    // Write down last compressed before-after
                    update_option('wps_ic_parsed_images', $parsedImages);

                    if (!$bulkStatus) {
                        $bulkStatus = [];
                        $bulkStatus['compressedImageCount'] = 0;
                        $bulkStatus['compressedThumbs'] = 0;
                        $bulkStatus['total']['original']['size'] = 0;
                        $bulkStatus['total']['compressed']['size'] = 0;
                    }

                    $bulkStatus['compressedImageCount'] += 1;
                    $bulkStatus['compressedThumbs'] += count($thumbCount);
                    $bulkStatus['total']['original']['size'] += $stats['original']['original']['size'];
                    $bulkStatus['total']['compressed']['size'] += $stats['original']['compressed']['size'];

                    update_option('wps_ic_BulkStatus', $bulkStatus);

                    // Write counter for bulk UI
                    $counter = [];
                    $counter['images'] = $bulkStatus['compressedImageCount'];
                    $counter['imagesAndThumbs'] = $bulkStatus['compressedThumbs'];
                    update_option('wps_ic_bulk_counter', $counter);
                }

                // Compressing status
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);

                $imageStats = get_post_meta($imageID, 'ic_stats', true);
                $compressing = get_post_meta($imageID, 'ic_compressing', true);

                // if Image is skipped, on restore do nothing just delete meta
                update_post_meta($imageID, 'ic_skipped', 'true');

                update_post_meta($imageID, 'wpc_images_compressed', 'true');
                update_post_meta($imageID, 'ic_status', 'compressed');
                update_post_meta($imageID, 'ic_compressing', array('status' => 'compressed'));
                update_post_meta($imageID, 'ic_stats', $stats);
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);
                die('skipped');
            }


            $api_url = WPC_IC_LOCAL_DOWNLOAD . '?imageID=' . $imageID . '&apikey=' . $expected_token;

            $response = wp_remote_get($api_url, ['timeout' => 20]);
            if (is_wp_error($response)) {
                wp_die('API call failed: ' . $response->get_error_message());
            }


            $image_data = wp_remote_retrieve_body($response);

            if (empty($image_data) || $image_data == 'No optimized images found.') {
                // No Image Optimized
            } else {
                $body = json_decode($image_data);

                // Save optimized image in WordPress uploads directory
                $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
                $upload_dir = wp_upload_dir();
                $absolute_path = $upload_dir['basedir'] . '/' . $relative_path;
                $finalImagePath = str_replace($basename, '', $absolute_path);

                // Flags
                $errors = false;
                $done = false;

                // Stats
                $stats = [];
                $stats['original']['original']['size'] = 0;
                $stats['original']['compressed']['size'] = 0;
                $stats['original']['compressed']['thumbs'] = 0;

                $parsedImages = get_option('wps_ic_parsed_images');

                if (!$parsedImages) {
                    $parsedImages = [];
                    $parsedImages['total']['original'] = 0;
                    $parsedImages['total']['compressed'] = 0;
                }

                if (!empty($body->files)) {
                    foreach ($body->files as $key => $value) {

                        // Optimized basename
                        $imageSize = $value->label;
                        $originalSize = $value->originalSize;
                        $compressedSize = $value->optimizedSize;
                        $savings = $value->savingsPercent;
                        $optimizedUrl = $value->url;

                        $parsedImages[$imageID][$imageSize]['original'] = $stats['original']['original']['size'];
                        $parsedImages[$imageID][$imageSize]['compressed'] = $stats['original']['compressed']['size'];

                        $stats['original']['original']['size'] += $originalSize;
                        $stats['original']['compressed']['size'] += $compressedSize;
                        $stats['original']['compressed']['thumbs'] += 1;

                        $optimizedBasename = basename($optimizedUrl);
                        $optimizedFilePath = $finalImagePath . $optimizedBasename;


                        if (file_exists($optimizedFilePath) || strpos($optimizedBasename,'.webp') !== false) {

                            // Download optimized
                            $response = wp_remote_get($optimizedUrl);

                            if (!is_wp_error($response)) {
                                $image_data = wp_remote_retrieve_body($response);

                                if ($image_data && @getimagesizefromstring($image_data)) {


                                    // Rename original file
                                    if (file_exists($optimizedFilePath)) {
                                        unlink($optimizedFilePath);
                                    }

                                    // Save the file
                                    file_put_contents($optimizedFilePath, $image_data);

                                    echo "Downloaded and replaced: " . $optimizedBasename;
                                    $done = true;
                                } else {
                                    $errors = true;
                                    echo "Error: Downloaded data is not a valid image for " . $optimizedUrl;
                                }

                            } else {
                                echo "Failed to download optimized image: " . $optimizedUrl;
                            }

                        }

                    }

                    if (!$errors && $done) {
                        // Flag for Bulk Memory
                        if ($isBulk) {
                            $thumbCount = $this->getAllThumbSizes();
                            $bulkStatus = get_option('wps_ic_BulkStatus');

                            $parsedImages['total']['original'] += $stats['original']['original']['size'];
                            $parsedImages['total']['compressed'] += $stats['original']['compressed']['size'];

                            $parsedImages[$imageID]['total']['original'] = $parsedImages['total']['original'];
                            $parsedImages[$imageID]['total']['compressed'] = $parsedImages['total']['compressed'];

                            // Write down last compressed before-after
                            update_option('wps_ic_parsed_images', $parsedImages);

                            if (!$bulkStatus) {
                                $bulkStatus = [];
                                $bulkStatus['compressedImageCount'] = 0;
                                $bulkStatus['compressedThumbs'] = 0;
                                $bulkStatus['total']['original']['size'] = 0;
                                $bulkStatus['total']['compressed']['size'] = 0;
                            }

                            $bulkStatus['compressedImageCount'] += 1;
                            $bulkStatus['compressedThumbs'] += count($thumbCount);
                            $bulkStatus['total']['original']['size'] += $stats['original']['original']['size'];
                            $bulkStatus['total']['compressed']['size'] += $stats['original']['compressed']['size'];

                            update_option('wps_ic_BulkStatus', $bulkStatus);

                            // Write counter for bulk UI
                            $counter = [];
                            $counter['images'] = $bulkStatus['compressedImageCount'];
                            $counter['imagesAndThumbs'] = $bulkStatus['compressedThumbs'];
                            update_option('wps_ic_bulk_counter', $counter);
                        }

                        // Compressing status
                        delete_transient('wps_ic_compress_' . $imageID);
                        delete_transient('wps_ic_queue_' . $imageID);

                        $imageStats = get_post_meta($imageID, 'ic_stats', true);
                        $compressing = get_post_meta($imageID, 'ic_compressing', true);

                        update_post_meta($imageID, 'wpc_images_compressed', 'true');
                        update_post_meta($imageID, 'ic_status', 'compressed');
                        update_post_meta($imageID, 'ic_compressing', array('status' => 'compressed'));
                        update_post_meta($imageID, 'ic_stats', $stats);
                        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

                        // Ovo je radilo probleme jer generira thumbove iz -scaled verzije slike, i onda generira nove thumbove koji su scaled
                        // Get full image path
                        $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
                        $upload_dir = wp_upload_dir();
                        $file_path = $upload_dir['basedir'] . '/' . $relative_path;

                        $unscaledPath = str_replace('-scaled.', '.', $file_path);
                        if (file_exists($unscaledPath)) {
                            $file_path = $unscaledPath;
                        }

                        // Regenerate metadata and thumbnails - ISSUE: because it rebuilds images and loses optimization
//                    $metadata = wp_generate_attachment_metadata($imageID, $file_path);
//                    if ($metadata && !is_wp_error($metadata)) {
//                        wp_update_attachment_metadata($imageID, $metadata);
//                        echo 'Metadata updated and thumbnails regenerated.';
//                    } else {
//                        echo 'Failed to generate metadata.';
//                    }
                    }

                }
            }

            die();
        }
    }


    /**
     * Start the Bulk Process (Restore or Compress)
     * @return void
     */
    public function initBulk()
    {
        if (!empty($_GET['getImageList'])) {

            // Check if API Key is valid
            $this->checkAPIKey();

            if (empty($_GET['action']) || $_GET['action'] == 'compress') {
                // Compress
                $imagesToProcess = $this->getAllImageIDs();
            } else {
                // Restore
                $imagesToProcess = $this->getImagesToRestore();
            }

            // Count number of found images
            $countImagesToOptimize = count($imagesToProcess);

            // Multiply by number of thumbnails
            $imageSizes = count($this->getAllThumbSizes());
            $thumbnailCount = $countImagesToOptimize * $imageSizes;

            $counter = [];
            $counter['images'] = 0;
            $counter['imagesAndThumbs'] = 0;
            update_option('wps_ic_bulk_counter', $counter);

            $bulkStats = get_option('wps_ic_BulkStatus');
            $bulkStats['foundImageCount'] = $countImagesToOptimize;
            $bulkStats['foundThumbCount'] = $thumbnailCount;
            update_option('wps_ic_BulkStatus', $bulkStats);

            wp_send_json_success($imagesToProcess);
        }
    }


    /**
     * Get All ImageIDs to Restore
     * @param $per_page
     * @return array|int[]|WP_Post[]
     */
    public function getImagesToRestore($per_page = 100)
    {
        $all_ids = [];

        // List of allowed image MIME types
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        // First query just to get total count of images WITHOUT 'ic_stats'
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => [['key' => 'ic_stats', 'compare' => 'EXISTS']],]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        // Now loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => [['key' => 'ic_stats', 'compare' => 'EXISTS']],]);

            $all_ids = array_merge($all_ids, $query->posts);
        }

        return $all_ids;
    }


    /**
     * Get All Image IDs to Compress
     * @param $per_page
     * @return array|int[]|WP_Post[]
     */
    public function getAllImageIDs($per_page = 100)
    {
        $all_ids = [];

        // List of allowed image MIME types
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        // First query just to get total count of images WITHOUT 'ic_stats'
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => [['key' => 'ic_stats', 'compare' => 'NOT EXISTS']],]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        // Now loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => [['key' => 'ic_stats', 'compare' => 'NOT EXISTS']],]);

            $all_ids = array_merge($all_ids, $query->posts);
        }

        return $all_ids;
    }


    /**
     * Delete WebP once Image Gets Deleted
     * @param $post_id
     * @return void
     */
    public function on_delete($post_id)
    {
        // Delete webP if exists
        $imagesCompressed = get_post_meta($post_id, 'wpc_images_compressed', true);
        if (!empty($imagesCompressed) && is_array($imagesCompressed)) {
            foreach ($imagesCompressed as $image => $data) {
                if (file_exists($data['webp_path'])) {
                    unlink($data['webp_path']);
                }
            }
        }
    }


    public function is_supported($imageID)
    {
        $file_data = get_attached_file($imageID);
        $type = wp_check_filetype($file_data);

        // Is file extension allowed
        if (!in_array(strtolower($type['ext']), self::$allowed_types)) {
            return false;
        } else {
            return true;
        }
    }

    public function backup_image($imageID)
    {
        wp_raise_memory_limit('image');

        if (empty(self::$settings['backup']['local'] || self::$settings['backup']['local'] === '0')) {
            return true;
        }

        // Image Backup Exists
        if ($this->backup_exists($imageID)) {
            return true;
        }

        // Setup Image Stats
        $stats = [];
        $backup_list = [];

        // Create backup directory
        $this->create_backup_directory();

        // Get filename
        $image = wp_get_original_image_url($imageID);
        $image_url = $image;
        $parsed_url = parse_url($image_url);
        $parsed_url['path'] = ltrim($parsed_url['path'], '/');
        $filename = basename($parsed_url['path']);
        $backup_folders = str_replace($filename, '', $parsed_url['path']);
        $backup_folders = rtrim($backup_folders, '/');
        $backup_folders = explode('/', $backup_folders);

        $backup_dir = self::$backup_directory;
        if (is_array($backup_folders)) {
            foreach ($backup_folders as $i => $folder) {
                $backup_dir .= '/' . $folder;
                if (!file_exists($backup_dir)) {
                    $made_dir = mkdir($backup_dir, 0755);
                }
            }
        }

        if (empty($image) || empty($image_url)) {
            return false;
        }

        // Define original / backup file paths
        $original_file_location = ABSPATH . $parsed_url['path'];

        // Where is backup saved?
        $backup_file_location = $backup_dir . '/' . $filename;

        // Stats
        $stats['original']['original']['size'] = filesize($original_file_location);

        copy($original_file_location, $backup_file_location);

        $backup_list['original'] = $backup_file_location;

        if (!file_exists($backup_file_location)) {
            // TODO: What then
            //wp_send_json_error('failed-to-create-backup');
        }

        update_post_meta($imageID, 'ic_stats', $stats);
        update_post_meta($imageID, 'ic_backup_images', $backup_list);
        update_post_meta($imageID, 'ic_original_stats', $stats);

        return true;
    }


    public function backup_exists($imageID)
    {
        $backup_exists = get_post_meta($imageID, 'ic_backup_images', true);
        if (!empty($backup_exists) && is_array($backup_exists)) {
            foreach ($backup_exists as $filename => $backup_location) {
                if (!empty($backup_location)) {
                    // If backup file exists
                    if (file_exists($backup_location)) {
                        return $backup_location;
                    } else {
                        return false;
                    }
                }
            }

            return true;
        } else {
            return false;
        }
    }


    public function create_backup_directory()
    {
        if (!file_exists(self::$backup_directory)) {
            mkdir(self::$backup_directory, 0755);
        }
    }

    public function on_upload($data, $attachment_id)
    {
        $imageID = $attachment_id;

        // Is the image type supported
        if (!$this->is_supported($imageID)) {
            $this->writeLog('Image not supported ' . $imageID);
            return $data;
        }

        // Is the image already Compressed
        if ($this->is_already_compressed($imageID)) {
            $this->writeLog('Image not supported ' . $imageID);
            return $data;
        }

        update_post_meta($imageID, 'wpc_old_meta', $data);

        $this->singleCompressV4($imageID, false);

        return $data;
    }

    public function writeLog($message)
    {
        if ($this->enabledLog == 'true') {
            fwrite($this->logFile, "[" . date('d.m.Y H:i:s') . "] " . $message . "\r\n");
        }
    }

    public function is_already_compressed($imageID)
    {
        $backup_exists = get_post_meta($imageID, 'ic_status', true);
        if (!empty($backup_exists) && $backup_exists == 'compressed') {
            return true;
        } else {
            return false;
        }
    }

    public function singleCompressV4($imageID, $output = 'json')
    {
        wp_raise_memory_limit('image');

        // Is the image type supported
        if (!$this->is_supported($imageID)) {
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        // Is the image already Compressed
        if ($this->is_already_compressed($imageID)) {
            $media_library = new wps_ic_media_library_live();
            $html = $media_library->compress_details($imageID);

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
            } else {
                return 'file-already-compressed';
            }
        }

        // Set the image status
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing'], 60);
        set_transient('wps_ic_queue_' . $imageID, ['imageID' => $imageID, 'status' => 'waiting'], 30);

        // Save OLD post meta for restore usage
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        // Prepare the request params WPC_IC_LOCAL_OPTIMIZE
        // Site URL where this plugin runs
        $site_url = get_site_url();

        // Build full API URL
        $request_url = add_query_arg(array('imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'],), WPC_IC_LOCAL_OPTIMIZE);

        // Make the GET request
        $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($output == 'json') {
            wp_send_json_success();
        }
    }

    public function generate_webp($arg, $type = 'click')
    {
        global $wpc_filesystem;

        $upload_dir = wp_upload_dir();
        $imageID = $arg;
        $return = [];
        $compressed = [];
        $extension = '';
        $stats = [];

        $image_url_full = wp_get_attachment_image_src($imageID, 'full');
        $image_url_full = $image_url_full[0];
        $image_filename = basename($image_url_full);

        if (strpos($image_filename, '.jpg') !== false) {
            $extension = 'jpg';
        } elseif (strpos($image_filename, '.jpeg') !== false) {
            $extension = 'jpeg';
        } elseif (strpos($image_filename, '.gif') !== false) {
            $extension = 'gif';
        } elseif (strpos($image_filename, '.png') !== false) {
            $extension = 'png';
        }

        foreach ($this->webp_sizes as $i => $size) {
            if ($size == 'full') {
                $image = wp_get_attachment_image_src($imageID, $size);
                if ($image) {
                    $image_url = $image[0];
                }
            } else {
                $image = wp_get_attachment_image_src($imageID, $size);
                if ($image) {
                    $image_url = $image[0];
                }
            }

            if (empty($image_url)) {
                continue;
            }

            if (!isset($image['path']) && !empty($image)) {
                $image['path'] = $image;
            }

            $image['path'] = str_replace($upload_dir['baseurl'] . '/', '', $image[0]);
            $image['path'] = str_replace('./', '', $image['path']);

            /**
             * Figure out the actual file path
             */
            $file_path = get_attached_file($imageID);
            $file_basename = basename($image[0]);

            // Setup POST Params
            $headers = ['timeout' => 300, 'httpversion' => '1.0', 'blocking' => true,];

            // Figure out image type
            $exif = exif_imagetype($file_path);
            $mime = image_type_to_mime_type($exif);

            $file_location = WPS_IC_UPLOADS_DIR . '/' . $image['path'];

            // Fetch the image content
            $file_content = $wpc_filesystem->get_contents($file_path);

            $post_fields = ['action' => 'compress', 'imageID' => $imageID, 'filename' => $file_basename, 'apikey' => self::$apiParams['apikey'], 'key' => self::$apiParams['apikey'], 'image' => $image[0], 'url' => $image[0], 'exif' => $exif, 'mime' => $mime, 'content' => base64_encode($file_content), 'quality' => self::$apiParams['quality'], 'width' => '1', 'retina' => 'false', 'webp' => 'true'];

            if (!empty($size)) {
                if ($size == 'full') {
                    $post_fields['width'] = '1';
                } else {
                    if (empty($image['width'])) {
                        $post_fields['width'] = '1';
                    } else {
                        $post_fields['width'] = $image['width'];
                    }
                }
            }

            // WebP File Path
            $webp_file_location = str_replace('.' . $extension, '.webp', $file_location);
            $call = wp_remote_post(self::$apiURL, ['timeout' => 300, 'method' => 'POST', 'headers' => $headers, 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                if (!empty($body)) {
                    file_put_contents($webp_file_location, $body);
                    clearstatcache();

                    $stats[$size . '-webp']['compressed']['size'] = filesize($webp_file_location);
                    $compressed[$size . '-webp'] = $webp_file_location;
                }
            }
        }

        $return['stats'] = $stats;
        $return['compressed'] = $compressed;

        $stats = get_post_meta($imageID, 'ic_stats', true);
        $stats = array_merge($stats, $return['stats']);
        update_post_meta($imageID, 'ic_stats', $stats);

        if ($type == 'click') {
            $compressed = get_post_meta($imageID, 'ic_compressed_images', true);
            $compressed = array_merge($compressed, $return['compressed']);
            update_post_meta($imageID, 'ic_compressed_images', $compressed);
        }

        return $return;
    }

    public function restoreV4($imageID)
    {

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

        $olderVersionBackup = $this->olderBackup($imageID);
        if ($olderVersionBackup) {
            return true;
        }

        // Check if image was skipped?
        $skipped = get_post_meta($imageID, 'ic_skipped', true);
        if (!empty($skipped) && $skipped == 'true') {
            // Optional status flag
            delete_post_meta($imageID, 'ic_bulk_running');
            delete_post_meta($imageID, 'ic_compressing');
            delete_post_meta($imageID, 'wpc_images_compressed');
            delete_post_meta($imageID, 'ic_stats');
            update_post_meta($imageID, 'ic_status', 'restored');
            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);
            die();
        }

        // Site URL where this plugin runs
        $site_url = get_site_url();

        // Build full API URL
        $request_url = add_query_arg(array('imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key'],), WPC_IC_LOCAL_RESTORE);

        // Make the GET request
        $response = wp_remote_get($request_url, array('timeout' => 15, 'sslverify' => false));

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!empty($data['backupUrls'])) {

            $unscaledUrl = null;

            // Find the unscaled
            foreach ($data['backupUrls'] as $file) {
                if ($file['sizeLabel'] === 'unscaled') {
                    $unscaledUrl = $file['fileUrl'];


                    $originalFilePath = get_attached_file($imageID);
                    $filePath = str_replace(basename($originalFilePath), '', $originalFilePath);
                    $unscaledBasename = basename($unscaledUrl);
                    $unscaledPath = $filePath . $unscaledBasename;

                    // Download to temp file
                    $tmp = download_url($unscaledUrl);
                    if (is_wp_error($tmp)) {
                        continue;
                    }

                    copy($tmp, $unscaledPath);
                    @unlink($tmp);

                    break;
                }
            }


            foreach ($data['backupUrls'] as $index => $backupFile) {
                $size = $backupFile['sizeLabel'];

                // We just require the original
                if ($size !== 'original') {
                    continue;
                }

                $url = $backupFile['fileUrl'];

                // Download to temp file
                $tmp = download_url($url);
                if (is_wp_error($tmp)) {
                    continue;
                }

                $originalFilePath = get_attached_file($imageID);
                if (!$originalFilePath) {
                    @unlink($tmp);
                    continue;
                }

                // Replace the file
                copy($tmp, $originalFilePath);
                @unlink($tmp);

                // Remove backup
                if (file_exists($originalFilePath . '_bkp')) {
                    @unlink($originalFilePath . '_bkp');
                }

                // generira iz scaled verzije opet
                $unscaledPath = str_replace('-scaled.', '.', $originalFilePath);
                if (file_exists($unscaledPath)) {
                    $originalFilePath = $unscaledPath;
                }

                $newMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);

                if ($newMeta) {
                    wp_update_attachment_metadata($imageID, $newMeta);
                }

                // Optional status flag
                delete_post_meta($imageID, 'ic_bulk_running');
                delete_post_meta($imageID, 'ic_compressing');
                delete_post_meta($imageID, 'wpc_images_compressed');
                delete_post_meta($imageID, 'ic_stats');
                update_post_meta($imageID, 'ic_status', 'restored');
            }

            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);
        }


        die();


        $this->writeLog('Started Image ID ' . $imageID);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response = wp_remote_retrieve_body($call);
            $response = json_decode($response, true);

            $this->writeLog('API Response IS 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));

            if ($response['success'] == 'true') {
                if (!empty($response['data'])) {

                    $alreadyRestored = [];
                    $oldMeta = get_post_meta($imageID, 'wpc_old_meta', true);

                    if (!empty($response['data']['imageURL'])) {
                        $imageUrl = $response['data']['imageURL'];
                        $imagePath = wp_get_original_image_path($imageID);

                        $downloadImage = download_url($imageUrl);

                        if (is_wp_error($downloadImage)) {
                            $this->writeLog('Unable to download Image');
                            $this->writeLog($imageUrl);
                            $this->writeLog($downloadImage);

                            $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);

                            if ($output == 'json') {
                                wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID, 'url' => $downloadImage]);
                            }

                            return false;
                        }

//                        $file_info = finfo_open(FILEINFO_MIME_TYPE);
//                        $mime_type = finfo_file($file_info, $downloadImage);
//                        finfo_close($file_info);

                        //$mime_type = mime_content_type($downloadImage);

                        // Verify if the downloaded file is an image
                        if (function_exists('mime_content_type')) {
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

                                if (file_exists($imagePath)) {
                                    unlink($imagePath);
                                }

                                copy($downloadImage, $imagePath);
                                unset($downloadImage);

                                // Delete webP if exists
                                $imagesCompressed = get_post_meta($imageID, 'wpc_images_compressed', true);
                                foreach ($imagesCompressed as $image => $data) {
                                    if (file_exists($data['webp_path'])) {
                                        unlink($data['webp_path']);
                                    }
                                }


                                // Remove meta tags
                                delete_post_meta($imageID, 'wpc_images_compressed');
                                delete_post_meta($imageID, 'ic_stats');
                                delete_post_meta($imageID, 'ic_compressed_images');
                                delete_post_meta($imageID, 'ic_compressed_thumbs');
                                delete_post_meta($imageID, 'ic_backup_images');
                                update_post_meta($imageID, 'ic_status', 'restored');
                                delete_post_meta($imageID, 'ic_bulk_running');
                                delete_transient('wps_ic_compress_' . $imageID);

                                $originalFilePath = wp_get_original_image_path($imageID);
                                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

                                $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
                                wp_update_attachment_metadata($imageID, $oldMeta);
                                // Add for heartbeat to pickup
                                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                                $this->writeLog('Ended Image ID - restored ' . $imageID);

                                if ($output == 'json') {
                                    wp_send_json_success(['msg' => 'backup-restored']);
                                }
                            }
                            wp_send_json_error(['msg' => 'invalid-backup']);
                        }
                        wp_send_json_error(['msg' => 'invalid-backup']);
                    }
                }
            }
        } else {
            $this->writeLog('API Response not 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));
            $this->writeLog('Ended Image ID ' . $imageID);

            // Failure to contact API
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
        }
    }

    public function olderBackup($imageID)
    {
        $backup_images = get_post_meta($imageID, 'ic_backup_images', true);

        if (!empty($backup_images) && is_array($backup_images)) {
            $compressed_images = get_post_meta($imageID, 'ic_compressed_images', true);

            // Remove Generated Images
            if (!empty($compressed_images)) {

                foreach ($compressed_images as $index => $path) {
                    if (strpos($index, 'webp') !== false) {
                        if (file_exists($path)) {
                            unlink($path);
                        }
                    }
                }

            }

            $upload_dir = wp_get_upload_dir();
            $sizes = get_intermediate_image_sizes();
            foreach ($sizes as $i => $size) {
                clearstatcache();
                $image = image_get_intermediate_size($imageID, $size);
                if ($image['path']) {
                    $path = $upload_dir['basedir'] . '/' . $image['path'];
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            $path_to_image = get_attached_file($imageID);

            // Restore only full
            $restore_image_path = $backup_images['full'];

            // If backup file exists
            if (file_exists($restore_image_path)) {
                unlink($path_to_image);

                // Restore from local backups
                $copy = copy($restore_image_path, $path_to_image);

                // Delete the backup
                unlink($restore_image_path);
            }

            clearstatcache();

            wp_update_attachment_metadata($imageID, wp_generate_attachment_metadata($imageID, $path_to_image));

            // Remove meta tags
            delete_post_meta($imageID, 'ic_stats');
            delete_post_meta($imageID, 'ic_compressed_images');
            delete_post_meta($imageID, 'ic_compressed_thumbs');
            delete_post_meta($imageID, 'ic_backup_images');
            update_post_meta($imageID, 'ic_status', 'restored');

            return true;
        }

        return false;
    }

    public function restore($imageID, $output = 'json')
    {
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

        $olderVersionBackup = $this->olderBackup($imageID);
        if ($olderVersionBackup) {
            return true;
        }

        // Is the image in process
        $inProcess = get_post_meta($imageID, 'ic_bulk_running', true);
        if ($inProcess && $inProcess == 'true') {
        }

        // Remote backup?

        //check api for original
        $params = ['timeout' => 300, 'method' => 'POST', 'sslverify' => false, 'body' => ['getS3Backup' => true, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID], 'user-agent' => WPS_IC_API_USERAGENT];

        $call = wp_remote_post(self::$apiURL, $params);

        $this->writeLog('Started Image ID ' . $imageID);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response = wp_remote_retrieve_body($call);
            $response = json_decode($response, true);


            $this->writeLog('API Response IS 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));

            if ($response['success'] == 'true') {
                if (!empty($response['data'])) {

                    $alreadyRestored = [];
                    $oldMeta = get_post_meta($imageID, 'wpc_old_meta', true);

                    if (!empty($response['data']['url']['original']['local'])) {
                        $imageUrl = $response['data']['url']['original']['local'];
                        $imagePath = wp_get_original_image_path($imageID);

                        $downloadImage = download_url($imageUrl);

                        if (is_wp_error($downloadImage)) {
                            $this->writeLog('Unable to download Image');
                            $this->writeLog($imageUrl);
                            $this->writeLog($downloadImage);

                            $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);

                            if ($output == 'json') {
                                wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID, 'url' => $downloadImage]);
                            }

                            return false;
                        }

                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }

                        copy($downloadImage, $imagePath);
                        unset($downloadImage);


                        // Remove meta tags
                        delete_post_meta($imageID, 'wpc_images_compressed');
                        delete_post_meta($imageID, 'ic_stats');
                        delete_post_meta($imageID, 'ic_compressed_images');
                        delete_post_meta($imageID, 'ic_compressed_thumbs');
                        delete_post_meta($imageID, 'ic_backup_images');
                        update_post_meta($imageID, 'ic_status', 'restored');
                        delete_post_meta($imageID, 'ic_bulk_running');
                        delete_transient('wps_ic_compress_' . $imageID);

                        $originalFilePath = wp_get_original_image_path($imageID);
                        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                        $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
                        wp_update_attachment_metadata($imageID, $oldMeta);

                        // Add for heartbeat to pickup
                        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                        $this->writeLog('Ended Image ID - restored ' . $imageID);

                        if ($output == 'json') {
                            wp_send_json_success(['msg' => 'backup-restored']);
                        }

                        return true;
                    }

                    foreach ($response['data']['url'] as $imageSize => $imageUrl) {

                        $imageUrl = $imageUrl['s3'];

                        // Image URL was already restored
                        if (in_array($imageUrl, $alreadyRestored)) {
                            $this->writeLog('Image was already restored');
                            $this->writeLog($imageUrl);
                            continue;
                        }

                        if ($imageSize == 'original') {
                            $imagePath = wp_get_original_image_path($imageID);
                        } else {
                            $originalFilePath = wp_get_original_image_path($imageID);
                            $originalFilename = wp_basename($originalFilePath);
                            $this->pathToDir = str_replace($originalFilename, '', $originalFilePath);
                            //
                            $imagePath = wp_get_attachment_image_src($imageID, $imageSize);
                            $imagePath = wp_basename($imagePath[0]);
                            $imagePath = $this->pathToDir . $imagePath;
                        }

                        // Local Filename
                        $localFilename = wp_basename($imagePath);

                        // Filename from API
                        $sentFilename = wp_basename($imageUrl);
                        $sentFilename = explode('?', $sentFilename);
                        $sentFilename = $sentFilename[0];

                        if ($sentFilename !== $localFilename) {
                            // Filename not matching?! Error!
                            $sentFilename = explode('-', $sentFilename);
                            $removed = array_shift($sentFilename);
                            $sentFilename = implode('-', $sentFilename);
                        }

                        if ($sentFilename !== $localFilename) {
                            // Still not a match
                        } else {
                            $downloadImage = download_url($imageUrl);

                            if (is_wp_error($downloadImage)) {
                                $this->writeLog('Unable to download Image');
                                $this->writeLog($imageUrl);
                                $this->writeLog($downloadImage);

                                $alreadyRestored[] = $imageUrl;
                                continue;
                            }

                            if (file_exists($imagePath)) {
                                unlink($imagePath);
                            }

                            copy($downloadImage, $imagePath);
                            unset($downloadImage);

                            // Delete webP if exists
                            $imagesCompressed = get_post_meta($imageID, 'wpc_images_compressed', true);
                            foreach ($imagesCompressed as $image => $data) {
                                if (file_exists($data['webp_path'])) {
                                    unlink($data['webp_path']);
                                }
                            }

                            $this->writeLog('WebP path ' . $data['webp_path']);
                            $this->writeLog('WebP path exists ' . file_exists($data['webp_path']));

                        }
                    }

                    $originalFilePath = wp_get_original_image_path($imageID);
                    $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);

                    wp_update_attachment_metadata($imageID, $oldMeta);

                    // Remove meta tags
                    delete_post_meta($imageID, 'wpc_images_compressed');
                    delete_post_meta($imageID, 'ic_stats');
                    delete_post_meta($imageID, 'ic_compressed_images');
                    delete_post_meta($imageID, 'ic_compressed_thumbs');
                    delete_post_meta($imageID, 'ic_backup_images');
                    update_post_meta($imageID, 'ic_status', 'restored');
                    delete_post_meta($imageID, 'ic_bulk_running');
                    delete_transient('wps_ic_compress_' . $imageID);

                    // Add for heartbeat to pickup
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                    $this->writeLog('Ended Image ID - restored ' . $imageID);

                    if ($output == 'json') {
                        wp_send_json_success(['msg' => 'backup-restored']);
                    }
                }
            } else {
                $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID]);
                }
            }

        } else {
            $this->writeLog('API Response not 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));
            $this->writeLog('Ended Image ID ' . $imageID);

            // Failure to contact API
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
        }
    }

    public function disable_scaling()
    {
        return false;
    }

    public function singleCompressV3($imageID, $output = 'json')
    {
        wp_raise_memory_limit('image');
        $settings = get_option(WPS_IC_SETTINGS);

        // Is the image type supported
        if (!$this->is_supported($imageID)) {
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        // Is the image already Compressed
        if ($this->is_already_compressed($imageID)) {
            $media_library = new wps_ic_media_library_live();
            $html = $media_library->compress_details($imageID);

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
            } else {
                return 'file-already-compressed';
            }
        }

        // Set the image status
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing'], 60);

        // Save OLD post meta for restore usage
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        // Prepare the request params
        $post_fields = ['action' => 'queueSingleImage', 'imageID' => $imageID, 'siteUrl' => self::$siteUrl, 'apikey' => self::$apiParams['apikey'], 'parameters' => ['maxWidth' => WPS_IC_MAXWIDTH, 'quality' => self::$apiParams['quality'], 'retina' => self::$apiParams['retina'], 'webp' => self::$apiParams['webp']],];

        // Notify API to queue to queue the request
        $notify = wp_remote_post(self::$apiURL . 'queueManager.php', ['timeout' => 60, 'method' => 'POST', 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($notify) == 200) {
            // All good, let's wait for queue
            wp_send_json_success('waiting-queue');
        } else {
            delete_transient('wps_ic_compress_' . $imageID);
            // We were unable to contact API
            wp_send_json_error(['msg' => 'unable-to-contact-api']);
        }
    }

    public function compress_image($imageID, $bulk = true, $retina = true, $webp = true, $just_thumbs = false, $regenerate = true, $output = 'json')
    {
        global $wpc_filesystem;
        wp_raise_memory_limit('image');

        $bulkStats = get_transient('wps_ic_bulk_stats');

        // Is the image type supported
        if (!$this->is_supported($imageID)) {
            if (!$bulk) {
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'file-not-supported']);
                } else {
                    return 'file-not-supported';
                }
            }

            return $bulkStats;
        }

        // Is the image already Compressed
        if ($this->is_already_compressed($imageID)) {
            if (!$bulk) {
                $media_library = new wps_ic_media_library_live();
                $html = $media_library->compress_details($imageID);

                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
                } else {
                    return 'file-already-compressed';
                }
            }

            return $bulkStats;
        }

        // Is the image in process
        $inProcess = get_post_meta($imageID, 'ic_bulk_running', true);
        if ($inProcess && $inProcess == 'true') {
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-in-bulk', 'imageID' => $imageID]);
            } else {
                return 'file-in-bulk';
            }
        }

        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing'], 30);

        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        $stats = get_post_meta($imageID, 'ic_stats', true);
        if (empty($stats) || !$stats) {
            $stats = [];
        }

        $post_fields = ['action' => 'compressArray', 'imageID' => $imageID, 'siteUrl' => self::$siteUrl, 'maxWidth' => WPS_IC_MAXWIDTH, 'apikey' => self::$apiParams['apikey'], 'quality' => self::$apiParams['quality'], 'retina' => self::$apiParams['retina'], 'webp' => self::$apiParams['webp'],];

        $response = wp_remote_post(self::$apiURL, ['timeout' => 60, 'method' => 'POST', 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($response) == 200) {
            set_transient('wps_ic_compress_' . $imageID, 'sent-to-api', 30);

            $body = wp_remote_retrieve_body($response);
            $body = json_decode($body);

            if ($body->success == 'true') {
                // All good
                if ($output == 'json') {
                    wp_send_json_success([self::$apiURL, $post_fields, $body]);
                } else {
                    return 'done';
                }
            } else {
                delete_transient('wps_ic_compress_' . $imageID);

                // Error?
                if ($output == 'json') {
                    wp_send_json_error(['msg' => $body->data->msg, 'server' => $body->data->server]);
                } else {
                    return 'done';
                }
            }

        } else {
            delete_transient('wps_ic_compress_' . $imageID);

            // We were unable to contact API
            wp_send_json_error(['msg' => 'unable-to-contact-api']);
        }
    }

    public function debug_msg($attachmentID, $mesage)
    {
        if (defined('WPS_IC_DEBUG') && WPS_IC_DEBUG == 'true') {
            $debug_log = get_post_meta($attachmentID, 'ic_debug', true);
            if (!$debug_log) {
                $debug_log = [];
            }
            $debug_log[] = $mesage;
            update_post_meta($attachmentID, 'ic_debug', $debug_log);
        }
    }

    public function generate_retina($arg)
    {
        $imageID = $arg;
        $return = [];
        $compressed = [];
        $filename = '';

        $image = $image_url = wp_get_attachment_image_src($imageID, 'full');
        $image_url = $image_url[0];

        if ($filename == '') {
            if (strpos($image_url, '.jpg') !== false) {
                $extension = 'jpg';
            } elseif (strpos($image_url, '.jpeg') !== false) {
                $extension = 'jpeg';
            } elseif (strpos($image_url, '.gif') !== false) {
                $extension = 'gif';
            } elseif (strpos($image_url, '.png') !== false) {
                $extension = 'png';
            } else {
                return true;
            }
        }

        /**
         * Figure out the actual file path
         */
        $file_path = get_attached_file($imageID);
        $file_basename = basename($image[0]);
        $file_path = str_replace($file_basename, '', $file_path);

        foreach ($this->sizes as $i => $size) {
            if (empty($image_url)) {
                continue;
            }

            $retinaAPIUrl = self::$apiURL . $image_url;

            if ($size == 'full') {
                continue;
            } else {
                $image = image_get_intermediate_size($imageID, $size);
                $image_url = $image['url'];
            }

            if (empty($image['width']) || $image['width'] == '') {
                continue;
            }

            $file_location = $file_path . basename($image_url);

            // Retina File Path
            $retina_file_location = str_replace('.' . $extension, '-x2.' . $extension, $file_location);

            // Enable Retina
            $retinaAPIUrl = str_replace('r:0', 'r:1', $retinaAPIUrl);
            $retinaAPIUrl = str_replace('w:1', 'w:' . $image['width'], $retinaAPIUrl);

            $call = wp_remote_get($retinaAPIUrl, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                if (!empty($body)) {
                    file_put_contents($retina_file_location, $body);
                    clearstatcache();

                    $stats[$size . '-2x']['compressed']['size'] = filesize($retina_file_location);
                    $compressed[$size . '-2x'] = $retina_file_location;
                }
            }
        }

        if (isset ($stats)) {
            $return['stats'] = $stats;
        }
        $return['compressed'] = $compressed;

        $stats = get_post_meta($imageID, 'ic_stats', true);

        if (empty($stats)) {
            $stats = [];
        }
        if (empty($return['stats'])) {
            $return['stats'] = [];
        }

        $stats = array_merge($stats, $return['stats']);
        update_post_meta($imageID, 'ic_stats', $stats);

        $compressed = get_post_meta($imageID, 'ic_compressed_images', true);
        $compressed = array_merge($compressed, $return['compressed']);
        update_post_meta($imageID, 'ic_compressed_images', $compressed);

        return $return;
    }

    public function regenerate_thumbnails($imageID)
    {
        wp_raise_memory_limit('image');
        $thumbs = [];
        $thumbs['total']['old'] = 0;
        $thumbs['total']['new'] = 0;

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        // Get all thumb sizes
        $upload_dir = wp_get_upload_dir();
        $sizes = get_intermediate_image_sizes();
        foreach ($sizes as $i => $size) {
            clearstatcache();
            $image = image_get_intermediate_size($imageID, $size);
            if (!empty($image) && isset($image['path'])) {
                $image['path'] = str_replace('./', '', $image['path']);
                $path = $upload_dir['basedir'] . '/' . $image['path'];
                $thumbs[$size]['old'] = filesize($path);
                $thumbs['total']['old'] = $thumbs['total']['old'] + filesize($path);
            } else if (!empty($image)) {
                $image = str_replace('./', '', $image);
                $path = $upload_dir['basedir'] . '/' . $image;
                $thumbs[$size]['old'] = filesize($path);
                $thumbs['total']['old'] = $thumbs['total']['old'] + filesize($path);
            }
        }

        add_filter('jpeg_quality', function ($arg) {
            return 70;
        });

        foreach ($sizes as $i => $size) {
            clearstatcache();
            $image = image_get_intermediate_size($imageID, $size);
            if (!empty($image) && isset($image['path'])) {
                $image['path'] = str_replace('./', '', $image['path']);
                $path = $upload_dir['basedir'] . '/' . $image['path'];
                $thumbs[$size]['new'] = filesize($path);
                $thumbs['total']['new'] = $thumbs['total']['new'] + filesize($path);
            } else if (!empty($image)) {
                $image = str_replace('./', '', $image);
                $path = $upload_dir['basedir'] . '/' . $image;
                $thumbs[$size]['new'] = filesize($path);
                $thumbs['total']['new'] = $thumbs['total']['new'] + filesize($path);
            }

        }

        update_post_meta($imageID, 'ic_compressed_thumbs', $thumbs);
    }

    public function restartCompressWorker()
    {
        // Prepare the request params
        $post_fields = ['action' => 'restartCompressWorker', 'apikey' => self::$apiParams['apikey'], 'siteurl' => self::$siteUrl,];

        // Notify API to queue to queue the request
        $notify = wp_remote_post(self::$apiURL, ['timeout' => 90, 'blocking' => true, 'body' => $post_fields, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    }

    public function restartRestoreWorker()
    {
        // Prepare the request params
        $post_fields = ['action' => 'restartRestoreWorker', 'apikey' => self::$apiParams['apikey'], 'siteurl' => self::$siteUrl,];

        // Notify API to queue to queue the request
        $notify = wp_remote_post(self::$apiURL, ['timeout' => 90, 'blocking' => true, 'body' => $post_fields, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    }

}