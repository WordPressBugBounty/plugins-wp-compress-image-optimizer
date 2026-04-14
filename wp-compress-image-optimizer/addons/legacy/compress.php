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

    /**
     * Build optimization params for /optimize and /bulk-start service calls.
     * Reads all Local Image Optimization settings and resolves Quality Override vs CDN level.
     */
    public static function buildOptimizeParams($imageID = null, $site_url = null, $settings = null)
    {
        if (!$settings) {
            $settings = get_option(WPS_IC_SETTINGS);
        }
        if (!$site_url) {
            $site_url = get_site_url();
            if (is_ssl()
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || strpos(home_url(), 'https://') === 0
                || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
            ) {
                $site_url = str_replace('http://', 'https://', $site_url);
            }
        }

        $options = get_option(WPS_IC_OPTIONS);

        // Quality Override: None (0) = use CDN Optimization Level, otherwise use local override
        $local_quality = $settings['local_qualityLevel'] ?? '0';
        $cdn_quality_map = ['1' => 'lossless', '2' => 'intelligent', '3' => 'ultra'];
        $cdn_level = $cdn_quality_map[$settings['qualityLevel'] ?? '2'] ?? 'intelligent';
        $resolved_level = ($local_quality === '0' || empty($local_quality))
            ? $cdn_level
            : ($cdn_quality_map[$local_quality] ?? $cdn_level);

        // Hosting detection — shared if low memory or short execution time
        $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $max_exec = (int) ini_get('max_execution_time');
        $hosting = ($memory < 268435456 || $max_exec < 60) ? 'shared' : 'vps';

        $params = [
            'imageSite' => $site_url,
            'apikey'    => $options['api_key'] ?? '',
            'level'     => $resolved_level,
            'webp'      => !empty($settings['generate_webp']) && $settings['generate_webp'] == '1' ? '1' : '0',
            'avif'      => !empty($settings['picture_avif']) && $settings['picture_avif'] == '1' ? '1' : '0',
            'maxWidth'  => $settings['maxWidth'] ?? $settings['local_maxWidth'] ?? '2560',
            'hosting'   => $hosting,
        ];

        if ($imageID) {
            $params['imageID'] = $imageID;
        }

        return $params;
    }

    /**
     * Build crops JSON from registered WordPress image sizes.
     * Sent to service so it can generate all thumbnails server-side.
     */
    public static function buildCropsJson() {
        $crops = [];
        if (!function_exists('wp_get_registered_image_subsizes')) {
            return json_encode($crops);
        }
        $subsizes = wp_get_registered_image_subsizes();
        foreach ($subsizes as $name => $size) {
            $crops[$name] = [
                'width'  => $size['width'],
                'height' => $size['height'],
                'crop'   => $size['crop'],
            ];
        }
        return json_encode($crops);
    }

    /**
     * Build filenames JSON mapping size labels → local WordPress filenames.
     * Sent to service so optimized files use the correct WP filenames (not hashes).
     */
    public static function buildFilenamesJson($imageID) {
        $filenames = [];

        // Unscaled original
        $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaledPath) {
            $filenames['original'] = basename($unscaledPath);
        }

        // Scaled version (if exists)
        $meta = wp_get_attachment_metadata($imageID);
        if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
            $filenames['scaled'] = basename($meta['file']);
        }

        // All registered thumbnail sizes
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $info) {
                if (!empty($info['file'])) {
                    $filenames[$sizeName] = $info['file'];
                }
            }
        }

        return json_encode($filenames);
    }

    /**
     * Send image to service via POST (file upload) with GET fallback.
     * POST is 3-6x faster than GET because the service doesn't need to download from the site.
     *
     * @param int   $imageID    WordPress attachment ID
     * @param array $params     Params from buildOptimizeParams()
     * @param bool  $blocking   true = wait for response, false = fire and forget
     * @param int   $timeout    Timeout in seconds (only for blocking)
     * @return array|WP_Error|true
     */
    public static function postOptimize($imageID, $params, $blocking = true, $timeout = 120) {
        $file_path = get_attached_file($imageID);

        // Fallback to GET if cURL/CURLFile not available or file not readable
        if (!function_exists('curl_init') || !class_exists('CURLFile') || !$file_path || !is_readable($file_path)) {
            $request_url = add_query_arg($params, WPC_IC_LOCAL_OPTIMIZE);
            return wp_remote_get($request_url, [
                'timeout'   => $blocking ? $timeout : 1,
                'blocking'  => $blocking,
                'sslverify' => false,
            ]);
        }

        // Send the LARGER file (handles corrupted unscaled after compress→restore cycle)
        $unscaled = wp_get_original_image_path($imageID);
        $unscaled_size = ($unscaled && file_exists($unscaled)) ? filesize($unscaled) : 0;
        $scaled_size = ($file_path && file_exists($file_path)) ? filesize($file_path) : 0;
        $upload_path = ($unscaled_size >= $scaled_size && $unscaled_size > 0) ? $unscaled : $file_path;

        // Build POST body with file
        $body = [
            'apikey'    => $params['apikey'] ?? '',
            'imageSite' => $params['imageSite'] ?? '',
            'imageID'   => $imageID,
            'level'     => $params['level'] ?? 'intelligent',
            'webp'      => $params['webp'] ?? '1',
            'avif'      => $params['avif'] ?? '1',
            'maxWidth'  => $params['maxWidth'] ?? '2560',
            'hosting'   => $params['hosting'] ?? 'shared',
            'crops'     => self::buildCropsJson(),
            'filenames' => self::buildFilenamesJson($imageID),
            'image'     => new CURLFile($upload_path, (function_exists('mime_content_type') ? mime_content_type($upload_path) : false) ?: 'image/jpeg', basename($upload_path)),
        ];

        $ch = curl_init(WPC_IC_LOCAL_OPTIMIZE);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'WP-Compress/' . WPS_IC_LOCAL_V,
        ]);

        if (!$blocking) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
            curl_exec($ch);
            curl_close($ch);
            return true;
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 403 = auth failure — don't retry or fall back, key is invalid
        if ($http_code === 403) {
            return new WP_Error('wpc_not_authorized', 'Local optimization not available on your plan');
        }

        if ($error || $http_code < 200 || $http_code >= 300) {
            // POST failed — fall back to GET
            $request_url = add_query_arg($params, WPC_IC_LOCAL_OPTIMIZE);
            $get_response = wp_remote_get($request_url, [
                'timeout'   => $blocking ? $timeout : 1,
                'blocking'  => $blocking,
                'sslverify' => false,
            ]);
            // Check GET fallback for 403 too
            if (!is_wp_error($get_response) && wp_remote_retrieve_response_code($get_response) === 403) {
                return new WP_Error('wpc_not_authorized', 'Local optimization not available on your plan');
            }
            return $get_response;
        }

        return ['body' => $response, 'response' => ['code' => $http_code]];
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

        register_rest_route('wpc/v1', '/compress-async', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'wpc_handle_async_compress'],
            'permission_callback' => [$this, 'wpc_permission_api_key'],
        ]);
    }

    /**
     * Sequential queue worker — processes one image at a time, then chains to the next.
     * Only one worker runs at a time (enforced by wpc_compress_lock transient).
     */
    public function wpc_handle_async_compress(\WP_REST_Request $request) {
        // Suppress auto-compress hook to prevent recursion
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        // Acquire lock (5 min TTL — failsafe if worker crashes)
        if (get_transient('wpc_compress_lock')) {
            error_log('[WPC Queue] Worker blocked — lock already held');
            return rest_ensure_response(['success' => false, 'reason' => 'worker-already-running']);
        }
        set_transient('wpc_compress_lock', time(), 300);

        $workerStart = microtime(true);
        $processed = 0;
        error_log('[WPC Queue] Worker started. Queue: ' . json_encode(get_option('wpc_compress_queue', [])));

        // Process queue sequentially until empty
        while (true) {
            wp_cache_delete('wpc_compress_queue', 'options');
            $queue = get_option('wpc_compress_queue', []);
            if (empty($queue)) break;

            // Take next image from front of queue
            $imageID = intval(array_shift($queue));
            update_option('wpc_compress_queue', $queue, false);

            if (!$imageID || get_post_type($imageID) !== 'attachment') {
                error_log('[WPC Queue] Skipping invalid ID=' . $imageID);
                delete_transient('wps_ic_compress_' . $imageID);
                continue;
            }

            $remaining = count($queue);
            $queuedAt = 0;
            $trans = get_transient('wps_ic_compress_' . $imageID);
            if ($trans && is_array($trans) && !empty($trans['time'])) {
                $queuedAt = time() - intval($trans['time']);
            }

            error_log('[WPC Queue] Processing image=' . $imageID . ' position=' . ($processed + 1) . ' remaining=' . $remaining . ' waited=' . $queuedAt . 's');

            // Refresh lock TTL for each image (worker is alive)
            set_transient('wpc_compress_lock', time(), 300);

            $imgStart = microtime(true);
            try {
                $backupOk = $this->backup_all_sizes($imageID);
                if (!$backupOk) {
                    error_log('[WPC Queue] SKIPPED image=' . $imageID . ' — backup failed, will not compress');
                } else {
                    $this->singleCompressV4($imageID, 'silent');
                }
            } catch (\Exception $e) {
                error_log('[WPC Queue] Exception image=' . $imageID . ': ' . $e->getMessage());
            } catch (\Error $e) {
                error_log('[WPC Queue] Fatal error image=' . $imageID . ': ' . $e->getMessage());
            }
            $imgElapsed = round(microtime(true) - $imgStart, 2);

            $status = get_post_meta($imageID, 'ic_status', true) ?: 'failed';
            $savings = get_post_meta($imageID, 'ic_savings', true) ?: '0';
            error_log('[WPC Queue] Done image=' . $imageID . ' status=' . $status . ' savings=' . $savings . '% time=' . $imgElapsed . 's');

            // Always clean up this image's transients
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);

            // If compression failed, set heartbeat so UI refreshes to uncompressed state
            // (successful compression already sets this inside singleCompressV4)
            if ($status !== 'compressed') {
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 300);
            }

            $processed++;
        }

        // Release lock
        delete_transient('wpc_compress_lock');

        $totalElapsed = round(microtime(true) - $workerStart, 2);
        error_log('[WPC Queue] Worker done. Processed=' . $processed . ' total_time=' . $totalElapsed . 's');

        return rest_ensure_response(['success' => true, 'processed' => $processed]);
    }

    /**
     * Fire the queue worker via non-blocking loopback (if not already running).
     */
    public function fireQueueWorker() {
        // Don't fire if worker is already running
        if (get_transient('wpc_compress_lock')) return;

        $loopback_status = get_option('wpc_loopback_status', '');
        if ($loopback_status === 'fail') return;

        $api_key = $this->getApiKey();
        wp_remote_post(rest_url('wpc/v1/compress-async'), [
            'blocking'  => false,
            'timeout'   => 0.01,
            'headers'   => ['x-api-key' => $api_key],
            'body'      => [],
            'sslverify' => false,
        ]);
    }

    // ─── Backup image files to /wpc-backups/ before compression ────────
    /**
     * Backup image files before compression. Respects the 'backup' setting:
     *   'full'      — all files (unscaled + scaled + thumbnails) — safest, instant restore
     *   'originals' — only unscaled original — smaller footprint, restore regenerates thumbnails
     *   'cloud'     — skip local backup — restore downloads from service
     *   'off'       — no backup, compression is permanent
     *
     * Returns true if backup succeeded (or was skipped by setting), false if backup failed.
     * Compression MUST NOT proceed if this returns false (except for 'off' mode).
     */
    public function backup_all_sizes($imageID) {
        $backupMode = self::$settings['backup'] ?? 'full';

        // 'off' = no backup, compression is permanent — proceed without backup
        if ($backupMode === 'off') {
            error_log('[WPC Backup] image=' . $imageID . ' mode=off — skipped');
            return true;
        }

        // 'cloud' = skip local backup — rely on service cloud backup only
        if ($backupMode === 'cloud') {
            update_post_meta($imageID, 'wpc_backup_mode', 'cloud');
            error_log('[WPC Backup] image=' . $imageID . ' mode=cloud — local skipped');
            return true;
        }

        // 'originals' or 'full' or 'local' or 'local-cloud' (legacy values) = local backup
        $backupBase = WP_CONTENT_DIR . '/wpc-backups/';
        $uploadDir = wp_upload_dir()['basedir'];
        $filesCopied = 0;
        $mainBackedUp = false;
        $backupFull = ($backupMode === 'full' || $backupMode === 'local-cloud');

        // Verify backup directory is writable
        $testDir = $backupBase . 'test_' . $imageID;
        if (!wp_mkdir_p($testDir)) {
            error_log('[WPC Backup] FAILED — backup directory not writable: ' . $backupBase);
            return false;
        }
        @rmdir($testDir);

        // Unscaled original (the real camera file) — ALWAYS backed up for local modes
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaled && file_exists($unscaled)) {
            $rel = str_replace($uploadDir . '/', '', $unscaled);
            $dest = $backupBase . $rel;
            wp_mkdir_p(dirname($dest));
            if (!file_exists($dest)) {
                copy($unscaled, $dest);
                if (file_exists($dest) && filesize($dest) > 0) {
                    $filesCopied++;
                    $mainBackedUp = true;
                } else {
                    error_log('[WPC Backup] FAILED to copy main file image=' . $imageID . ' src=' . basename($unscaled));
                    return false;
                }
            } else {
                $mainBackedUp = true;
            }
        }

        // Scaled version — backed up for 'full' and 'local'/'local-cloud' modes
        $scaled = get_attached_file($imageID);
        if ($backupFull || $backupMode === 'local') {
            if ($scaled && file_exists($scaled) && $scaled !== $unscaled) {
                $rel = str_replace($uploadDir . '/', '', $scaled);
                $dest = $backupBase . $rel;
                wp_mkdir_p(dirname($dest));
                if (!file_exists($dest)) {
                    copy($scaled, $dest);
                    if (file_exists($dest) && filesize($dest) > 0) {
                        $filesCopied++;
                        $mainBackedUp = true;
                    } else {
                        error_log('[WPC Backup] FAILED to copy scaled file image=' . $imageID);
                        return false;
                    }
                } else {
                    $mainBackedUp = true;
                }
            }
        }

        // Thumbnails — only for 'full' mode (non-critical, don't block on failure)
        if ($backupFull) {
            $meta = wp_get_attachment_metadata($imageID);
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                $dir = dirname($scaled ?: $unscaled);
                foreach ($meta['sizes'] as $size => $info) {
                    if (empty($info['file'])) continue;
                    $thumbPath = $dir . '/' . $info['file'];
                    if (file_exists($thumbPath)) {
                        $rel = str_replace($uploadDir . '/', '', $thumbPath);
                        $dest = $backupBase . $rel;
                        if (!file_exists($dest)) {
                            @copy($thumbPath, $dest);
                            if (file_exists($dest)) $filesCopied++;
                        }
                    }
                }
            }
        }

        // Store backup metadata for restore
        $mainFile = $scaled ?: $unscaled;
        if ($mainFile) {
            update_post_meta($imageID, 'wpc_backup_path', str_replace($uploadDir . '/', '', $mainFile));
        }
        update_post_meta($imageID, 'wpc_backup_mode', $backupMode);

        error_log('[WPC Backup] image=' . $imageID . ' mode=' . $backupMode . ' files=' . $filesCopied . ' main=' . ($mainBackedUp ? 'OK' : 'FAIL'));
        return $mainBackedUp;
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
        $expected_token = !empty($options['api_key']) ? $options['api_key'] : '';

        // Fallback: if object cache returned empty, read directly from database
        if (empty($expected_token)) {
            global $wpdb;
            $row = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = '" . WPS_IC_OPTIONS . "' LIMIT 1");
            if ($row) {
                $db_options = maybe_unserialize($row);
                $expected_token = !empty($db_options['api_key']) ? $db_options['api_key'] : '';
            }
        }

        if (empty($apikey) || $apikey !== $expected_token) {
            error_log('[WPC] Callback auth failed: received=' . substr($apikey, 0, 8) . '... expected=' . substr($expected_token, 0, 8) . '... URI=' . $_SERVER['REQUEST_URI']);
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

            // Skip excluded images — still advance bulk counter
            if (get_post_meta($imageID, 'wps_ic_exclude_live', true) === 'true') {
                $bulkStatus = get_option('wps_ic_BulkStatus');
                if (empty($bulkStatus['restoredImageCount'])) {
                    $bulkStatus['restoredImageCount'] = 0;
                }
                $bulkStatus['restoredImageCount'] += 1;
                update_option('wps_ic_BulkStatus', $bulkStatus);
                wp_send_json_success();
            }

            $parsedImages = get_option('wps_ic_parsed_images');

            if (!$parsedImages) {
                $parsedImages = [];
                $parsedImages['total']['original'] = 0;
                $parsedImages['total']['compressed'] = 0;
            }

            if (!function_exists('download_url')) {
                require_once(ABSPATH . "wp-admin" . '/includes/image.php');
                require_once(ABSPATH . "wp-admin" . '/includes/file.php');
                require_once(ABSPATH . "wp-admin" . '/includes/media.php');
            }

            // Use same restore logic as single image (restoreV4 approach)
            $restored = false;
            $scaledPath = get_attached_file($imageID);
            $unscaledPath = $scaledPath ? str_replace('-scaled.', '.', $scaledPath) : '';

            // Priority 1: Local _bkp backup
            $localBkpPaths = array_filter([$unscaledPath . '_bkp', $scaledPath . '_bkp']);
            foreach ($localBkpPaths as $bkpPath) {
                if ($bkpPath && file_exists($bkpPath) && filesize($bkpPath) > 0) {
                    $targetPath = str_replace('_bkp', '', $bkpPath);
                    if (@copy($bkpPath, $targetPath)) {
                        @unlink($bkpPath);
                        $isUnscaled = (strpos($targetPath, '-scaled.') === false && $unscaledPath === $targetPath);
                        if ($isUnscaled) {
                            remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                            $newMeta = wp_generate_attachment_metadata($imageID, $targetPath);
                            if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                        }
                        $restored = true;
                        break;
                    }
                }
            }

            // Priority 2: Download from service (prefer unscaled, fallback original)
            if (!$restored) {
                $site_url = get_site_url();
                $request_url = add_query_arg(array('imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']), WPC_IC_LOCAL_RESTORE);
                $response = wp_remote_get($request_url, array('timeout' => 30, 'sslverify' => false));

                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);

                    if (!empty($data['backupUrls'])) {
                        $restoreUrl = null;
                        $restoreLabel = null;

                        foreach ($data['backupUrls'] as $backupFile) {
                            if ($backupFile['sizeLabel'] === 'unscaled') {
                                $restoreUrl = $backupFile['fileUrl'];
                                $restoreLabel = 'unscaled';
                                break;
                            }
                            if ($backupFile['sizeLabel'] === 'original' && !$restoreUrl) {
                                $restoreUrl = $backupFile['fileUrl'];
                                $restoreLabel = 'original';
                            }
                        }

                        if ($restoreUrl) {
                            $tmp = download_url($restoreUrl, 60);
                            if (!is_wp_error($tmp)) {
                                if ($restoreLabel === 'unscaled') {
                                    copy($tmp, $unscaledPath);
                                    @unlink($tmp);
                                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                                } else {
                                    if ($scaledPath) copy($tmp, $scaledPath);
                                    @unlink($tmp);
                                }
                                $restored = true;
                            }
                        }
                    }
                }
            }

            // Always clean metadata (even if download failed — prevents stuck state)
            if ($restored) {
                // Clean leftover .webp and .avif files
                $attachedFile = get_attached_file($imageID);
                if ($attachedFile) {
                    $dir = dirname($attachedFile);
                    $baseName = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
                    foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { @unlink($webp); }
                    foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { @unlink($avif); }
                }
            }

            // Mark image as parsed for heartbeat to pick up
            $parsedImages[$imageID] = ['status' => $restored ? 'restored' : 'failed'];

            // Clean all optimization metadata
            delete_post_meta($imageID, 'ic_bulk_running');
            delete_post_meta($imageID, 'ic_compressing');
            delete_post_meta($imageID, 'wpc_images_compressed');
            delete_post_meta($imageID, 'ic_stats');
            delete_post_meta($imageID, 'ic_local_variants');
            delete_post_meta($imageID, 'ic_savings');
            delete_post_meta($imageID, 'ic_savings_format');
            delete_post_meta($imageID, 'ic_savings_bytes');
            delete_post_meta($imageID, 'ic_savings_baseline');
            delete_post_meta($imageID, 'ic_skipped');
            update_post_meta($imageID, 'ic_status', 'restored');

            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

            // Invalidate local cache + purge CDN for this image
            if (function_exists('wpc_invalidate_local_cache')) { wpc_invalidate_local_cache(); }
            if (function_exists('wpc_purge_cdn_urls')) { wpc_purge_cdn_urls($imageID); }

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

            // Skip excluded images — but still advance bulk counter so progress completes
            if (get_post_meta($imageID, 'wps_ic_exclude_live', true) === 'true') {
                if ($isBulk) {
                    $bulkStatus = get_option('wps_ic_BulkStatus');
                    if ($bulkStatus) {
                        $bulkStatus['compressedImageCount'] = ($bulkStatus['compressedImageCount'] ?? 0) + 1;
                        update_option('wps_ic_BulkStatus', $bulkStatus);
                    }
                }
                die('skipped');
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

            // Retry up to 3 times for bulk reliability
            $response = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $response = wp_remote_get($api_url, ['timeout' => 20]);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
                    break;
                }
                if ($attempt < 3) usleep(500000); // 0.5s delay between retries
            }
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


                        if (file_exists($optimizedFilePath) || strpos($optimizedBasename, '.webp') !== false || strpos($optimizedBasename, '.avif') !== false) {

                            // Download optimized
                            $response = wp_remote_get($optimizedUrl);

                            if (!is_wp_error($response)) {
                                $image_data = wp_remote_retrieve_body($response);

                                // AVIF isn't supported by getimagesizefromstring on PHP < 8.1; trust CDN for AVIF/WebP
                                $is_valid = !empty($image_data) && (
                                    @getimagesizefromstring($image_data) ||
                                    strpos($optimizedBasename, '.avif') !== false ||
                                    strpos($optimizedBasename, '.webp') !== false
                                );
                                if ($is_valid) {

                                    // Local backup: copy original to _bkp before overwrite when backup includes local
                                    $backupSetting = isset(self::$settings['backup']) ? self::$settings['backup'] : 'cloud';
                                    if (($backupSetting === 'local' || $backupSetting === 'local-cloud') && file_exists($optimizedFilePath)) {
                                        $pathInfo = pathinfo($optimizedFilePath);
                                        $bkpPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_bkp.' . $pathInfo['extension'];
                                        if (!file_exists($bkpPath)) {
                                            @copy($optimizedFilePath, $bkpPath);
                                        }
                                    }

                                    // Remove original file
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

                    // Proceed if at least one file downloaded — thumbnail errors are non-critical
                    if ($done) {
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

                        // Store variant data for <picture> delivery and savings display
                        $variants = [];
                        foreach ($body->files as $variant) {
                            $variants[$variant->label] = [
                                'url'          => $variant->url,
                                'originalSize' => $variant->originalSize,
                                'size'         => $variant->optimizedSize,
                                'savings'      => $variant->savingsPercent,
                            ];
                        }
                        update_post_meta($imageID, 'ic_local_variants', $variants);

                        // Best savings % from all full-size variants (matches stats modal)
                        $best_pct = 0;
                        $best_format = 'jpeg';
                        $best_orig = 0;
                        $best_opt = 0;
                        foreach ($variants as $key => $vdata) {
                            if ((strpos($key, 'original') === 0 || strpos($key, 'unscaled') === 0 || strpos($key, 'scaled') === 0)
                                && isset($vdata['savings']) && floatval($vdata['savings']) > $best_pct) {
                                $best_pct = floatval($vdata['savings']);
                                $best_orig = intval($vdata['originalSize'] ?? 0);
                                $best_opt = intval($vdata['size'] ?? 0);
                                if (strpos($key, 'avif') !== false) $best_format = 'avif';
                                elseif (strpos($key, 'webp') !== false) $best_format = 'webp';
                                else $best_format = 'jpeg';
                            }
                        }

                        if ($best_pct > 0 && $best_orig > 0) {
                            update_post_meta($imageID, 'ic_savings', round($best_pct, 1));
                            update_post_meta($imageID, 'ic_savings_format', $best_format);
                            update_post_meta($imageID, 'ic_savings_bytes', $best_orig - $best_opt);
                            update_post_meta($imageID, 'ic_savings_baseline', $best_orig);
                        }

                        // Invalidate CDN coexistence cache
                        if (function_exists('wpc_invalidate_local_cache')) {
                            wpc_invalidate_local_cache();
                        }

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

        $meta_query = [
            'relation' => 'AND',
            ['key' => 'ic_stats', 'compare' => 'EXISTS'],
            ['key' => 'wps_ic_exclude_live', 'compare' => 'NOT EXISTS'],
        ];

        // First query just to get total count
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $meta_query]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        // Now loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta_query]);

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

        $meta_query = [
            'relation' => 'AND',
            ['key' => 'ic_stats', 'compare' => 'NOT EXISTS'],
            ['key' => 'wps_ic_exclude_live', 'compare' => 'NOT EXISTS'],
        ];

        // First query just to get total count
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $meta_query]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        // Now loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta_query]);

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

        $backup_mode = self::$settings['backup'] ?? 'cloud';
        if ($backup_mode !== 'local' && $backup_mode !== 'local-cloud') {
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
        $t0 = microtime(true);
        $imageID = $attachment_id;

        if (!$this->is_supported($imageID)) {
            return $data;
        }

        if ($this->is_already_compressed($imageID)) {
            return $data;
        }

        // Save metadata to DB now so the async process can read filenames.
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
        wp_update_attachment_metadata($imageID, $data);
        add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);

        update_post_meta($imageID, 'wpc_old_meta', $data);

        // Mark as queued so media library shows spinner
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'queued', 'time' => time()], 300);

        // Add to sequential queue
        $queue = get_option('wpc_compress_queue', []);
        if (!in_array($imageID, $queue)) {
            $queue[] = $imageID;
            update_option('wpc_compress_queue', $queue, false);
        }

        $queueSize = count(get_option('wpc_compress_queue', []));
        $workerRunning = get_transient('wpc_compress_lock') ? 'YES' : 'NO';
        error_log('[WPC Queue] on_upload image=' . $imageID . ' queue_size=' . $queueSize . ' worker_running=' . $workerRunning . ' elapsed=' . round(microtime(true) - $t0, 3) . 's');

        // Start worker if not already running
        $this->fireQueueWorker();

        return $data;
    }

    // ─── Loopback health check ─────────────────────────────────

    private function canLoopback() {
        return get_option('wpc_loopback_status', '') !== 'fail';
    }

    public function testLoopback() {
        $api_key = $this->getApiKey();
        if (empty($api_key)) {
            update_option('wpc_loopback_status', 'fail', false);
            return false;
        }

        $response = wp_remote_post(rest_url('wpc/v1/fetch'), [
            'blocking'  => true,
            'timeout'   => 5,
            'headers'   => ['x-api-key' => $api_key],
            'body'      => ['image_id' => 0],
            'sslverify' => false,
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $works = !is_wp_error($response) && $code > 0;

        update_option('wpc_loopback_status', $works ? 'ok' : 'fail', false);
        return $works;
    }

    private function getApiKey() {
        if (defined('WPC_API_KEY')) return WPC_API_KEY;
        $options = get_option('wps_ic');
        return !empty($options['api_key']) ? $options['api_key'] : '';
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
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        // Is the image already Compressed
        if ($this->is_already_compressed($imageID)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            $media_library = new wps_ic_media_library_live();
            $html = $media_library->compress_details($imageID);

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
            } else {
                return 'file-already-compressed';
            }
        }

        // Set the image status (always include time for staleness detection)
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);
        set_transient('wps_ic_queue_' . $imageID, ['imageID' => $imageID, 'status' => 'waiting'], 30);

        // Save OLD post meta for restore usage
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        // Prepare the request params WPC_IC_LOCAL_OPTIMIZE
        // Site URL — force HTTPS if any SSL indicator is present (fixes HTTP→HTTPS redirect callback issue)
        $site_url = get_site_url();
        if (is_ssl()
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || strpos(home_url(), 'https://') === 0
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
        ) {
            $site_url = str_replace('http://', 'https://', $site_url);
        }

        // Build params and send image via POST with GET fallback
        $settings = get_option(WPS_IC_SETTINGS);
        $request_params = self::buildOptimizeParams($imageID, $site_url, $settings);
        $t_post = microtime(true);
        $response = self::postOptimize($imageID, $request_params, true, 120);
        $postTime = round(microtime(true) - $t_post, 2);
        error_log('[WPC Timing] image=' . $imageID . ' postOptimize=' . $postTime . 's');

        // Validate response — fail fast instead of leaving image in "compressing" state
        if (is_wp_error($response)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);

            // 403 = plan/auth issue — show specific message
            if ($response->get_error_code() === 'wpc_not_authorized') {
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'local-not-authorized']);
                }
                return;
            }

            error_log('[WPC] Local optimize failed for image ' . $imageID . ': ' . $response->get_error_message());
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code < 200 || $http_code >= 300) {
            error_log('[WPC] Local optimize HTTP ' . $http_code . ' for image ' . $imageID);
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

        // Extract AI metadata from POST response for stats modal
        $post_body = json_decode(wp_remote_retrieve_body($response));
        $ai_meta = null;
        if (!empty($post_body->optimizedResults[0]->ai)) {
            $ai = $post_body->optimizedResults[0]->ai;
            $ai_meta = [
                'ssim'            => $ai->ssim ?? null,
                'quality'         => $ai->quality ?? null,
                'category'        => $ai->category ?? null,
                'perceptualScore' => $ai->perceptualScore ?? null,
                'attempts'        => $ai->attempts ?? null,
            ];
        }

        // Use URLs from POST response (v1.12.6+) — skip separate /download call
        $dl_files = $post_body->optimizedResults ?? [];
        if (empty($dl_files)) {
            // Fallback: call /download endpoint for older service versions
            $options = get_option(WPS_IC_OPTIONS);
            $download_url = WPC_IC_LOCAL_DOWNLOAD . '?imageID=' . $imageID . '&apikey=' . ($options['api_key'] ?? '');
            $dl_response = wp_remote_get($download_url, ['timeout' => 30, 'sslverify' => false]);
            if (is_wp_error($dl_response) || wp_remote_retrieve_response_code($dl_response) !== 200) {
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'unable-to-contact-api']);
                }
                return;
            }
            $dl_body = json_decode(wp_remote_retrieve_body($dl_response));
            $dl_files = $dl_body->files ?? [];
        }

        if (empty($dl_files)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

        // Backup is handled by backup_all_sizes() in the queue worker BEFORE singleCompressV4 is called
        error_log('[WPC Timing] image=' . $imageID . ' files_to_download=' . count($dl_files));

        // Capture baseline from disk BEFORE download loop overwrites files
        $_orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : false;
        $_scaled_path = get_attached_file($imageID);
        $_orig_size = ($_orig_path && file_exists($_orig_path)) ? filesize($_orig_path) : 0;
        $_scaled_size = ($_scaled_path && file_exists($_scaled_path)) ? filesize($_scaled_path) : 0;
        $disk_baseline = max($_orig_size, $_scaled_size);

        // Resolve local paths
        $original_url = wp_get_attachment_url($imageID);
        $basename = basename($original_url);
        $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
        $upload_dir = wp_upload_dir();
        $absolute_path = $upload_dir['basedir'] . '/' . $relative_path;
        $finalImagePath = str_replace($basename, '', $absolute_path);

        $stats = [];
        $stats['original']['original']['size'] = 0;
        $stats['original']['compressed']['size'] = 0;
        $stats['original']['compressed']['thumbs'] = 0;
        $done = false;
        $errors = false;
        $skipped_variants = [];

        // Build download list — overwrite all local files with optimized versions
        $downloads = [];
        foreach ($dl_files as $value) {
            $file_url = $value->url ?? '';
            if (empty($file_url)) continue;
            $optimizedBasename = basename($file_url);
            $optimizedFilePath = $finalImagePath . $optimizedBasename;

            $stats['original']['original']['size'] += $value->originalSize;
            if ($value->optimizedSize > 0) {
                $stats['original']['compressed']['size'] += $value->optimizedSize;
            }
            $stats['original']['compressed']['thumbs'] += 1;

            if (file_exists($optimizedFilePath) || strpos($optimizedBasename, '.webp') !== false || strpos($optimizedBasename, '.avif') !== false) {
                $downloads[] = ['url' => $file_url, 'path' => $optimizedFilePath, 'basename' => $optimizedBasename];
            }
        }

        // Parallel download using curl_multi
        $t_dl = microtime(true);
        if (!empty($downloads) && function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($downloads as $i => $dl) {
                $ch = curl_init($dl['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }

            // Execute all downloads in parallel
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh, 1);
            } while ($active && $status == CURLM_OK);

            // Process results
            $backupSetting = self::$settings['backup'] ?? 'cloud';
            foreach ($handles as $i => $ch) {
                $file_data = curl_multi_getcontent($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($http_code < 200 || $http_code >= 300 || empty($file_data)) {
                    $errors = true;
                    continue;
                }

                $dl = $downloads[$i];
                $is_valid = @getimagesizefromstring($file_data) ||
                    strpos($dl['basename'], '.avif') !== false ||
                    strpos($dl['basename'], '.webp') !== false;

                if ($is_valid) {
                    // Size regression guard — don't overwrite if optimized is LARGER
                    $original_size = file_exists($dl['path']) ? filesize($dl['path']) : 0;
                    $optimized_size = strlen($file_data);
                    if ($original_size > 0 && $optimized_size >= $original_size) {
                        // Skip — optimized is same or larger, keep original
                        $skipped_variants[] = $dl['basename'];
                        continue;
                    }

                    // Inline _bkp removed — backup_all_sizes() handles all backups before compression
                    if (file_exists($dl['path'])) {
                        @unlink($dl['path']);
                    }
                    file_put_contents($dl['path'], $file_data);
                    $done = true;
                } else {
                    $errors = true;
                }
            }
            curl_multi_close($mh);
        } else {
            // Fallback: sequential download if curl_multi not available
            foreach ($downloads as $dl) {
                $file_response = wp_remote_get($dl['url'], ['timeout' => 20, 'sslverify' => false]);
                if (!is_wp_error($file_response)) {
                    $file_data = wp_remote_retrieve_body($file_response);
                    $is_valid = !empty($file_data) && (
                        @getimagesizefromstring($file_data) ||
                        strpos($dl['basename'], '.avif') !== false ||
                        strpos($dl['basename'], '.webp') !== false
                    );
                    if ($is_valid) {
                        if (file_exists($dl['path'])) @unlink($dl['path']);
                        file_put_contents($dl['path'], $file_data);
                        $done = true;
                    } else {
                        $errors = true;
                    }
                } else {
                    $errors = true;
                }
            }
        }

        error_log('[WPC Timing] image=' . $imageID . ' download=' . round(microtime(true) - $t_dl, 2) . 's files=' . count($downloads) . ' done=' . ($done ? 'Y' : 'N') . ' errors=' . ($errors ? 'Y' : 'N'));

        // Proceed if at least one file was downloaded successfully — thumbnail errors are non-critical
        if ($done) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);

            update_post_meta($imageID, 'wpc_images_compressed', 'true');
            update_post_meta($imageID, 'ic_status', 'compressed');
            update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
            update_post_meta($imageID, 'ic_stats', $stats);
            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

            // Store variant data for <picture> delivery and savings display
            $variants = [];
            foreach ($dl_files as $variant) {
                $label = $variant->sizeLabel ?? $variant->label ?? basename($variant->fileName ?? $variant->url ?? '', '.jpg');
                $orig = intval($variant->originalSize ?? 0);
                $opt = intval($variant->optimizedSize ?? 0);
                // Mark as skipped only if the SERVICE says optimized >= original (true regression)
                $is_regression = ($orig > 0 && $opt > 0 && $opt >= $orig);
                $variants[$label] = [
                    'url'          => $variant->url ?? '',
                    'originalSize' => $orig,
                    'size'         => $opt,
                    'savings'      => $variant->savingsPercent ?? 0,
                    'skipped'      => $is_regression,
                ];
            }
            update_post_meta($imageID, 'ic_local_variants', $variants);

            // Save AI metadata if available
            if (!empty($ai_meta)) {
                update_post_meta($imageID, 'ic_ai_meta', $ai_meta);
            }

            // End-to-end savings — find the best savings % from all full-size variants
            // Uses service-reported data directly (not filtered by download skips)
            $best_pct = 0;
            $best_fmt = 'jpeg';
            $best_orig = 0;
            $best_opt = 0;
            foreach ($variants as $k => $v) {
                if ((strpos($k, 'original') === 0 || strpos($k, 'unscaled') === 0 || strpos($k, 'scaled') === 0)
                    && isset($v['savings']) && floatval($v['savings']) > $best_pct
                    && empty($v['skipped'])) {
                    $best_pct = floatval($v['savings']);
                    $best_orig = intval($v['originalSize'] ?? 0);
                    $best_opt = intval($v['size'] ?? 0);
                    if (strpos($k, 'avif') !== false) $best_fmt = 'avif';
                    elseif (strpos($k, 'webp') !== false) $best_fmt = 'webp';
                    else $best_fmt = 'jpeg';
                }
            }
            if ($best_pct > 0 && $best_orig > 0) {
                update_post_meta($imageID, 'ic_savings', round($best_pct, 1));
                update_post_meta($imageID, 'ic_savings_format', $best_fmt);
                update_post_meta($imageID, 'ic_savings_bytes', $best_orig - $best_opt);
                update_post_meta($imageID, 'ic_savings_baseline', $best_orig);
            }

            if (function_exists('wpc_invalidate_local_cache')) {
                wpc_invalidate_local_cache();
            }
        } else {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

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
        $t_total = microtime(true);
        error_log('[WPC Restore] START image=' . $imageID);

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        wp_raise_memory_limit('image');

        $restored = false;
        $backupBase = WP_CONTENT_DIR . '/wpc-backups/';
        $uploadDir = wp_upload_dir()['basedir'];

        // Check if backup mode was 'off' — compression was permanent
        $backupMode = get_post_meta($imageID, 'wpc_backup_mode', true);
        if ($backupMode === 'off') {
            error_log('[WPC Restore] BLOCKED image=' . $imageID . ' — backup mode was off, compression is permanent');
            return false;
        }

        // Skipped images — just clear metadata
        $skipped = get_post_meta($imageID, 'ic_skipped', true);
        if (!empty($skipped) && $skipped == 'true') {
            $this->cleanRestoreMeta($imageID);
            error_log('[WPC Restore] DONE image=' . $imageID . ' method=skipped time=' . round(microtime(true) - $t_total, 2) . 's');
            return true;
        }

        // Suppress on_upload hook during any regeneration in this function
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        // ── PRIORITY 1: New /wpc-backups/ directory ──────────────────
        $backupRel = get_post_meta($imageID, 'wpc_backup_path', true);
        if ($backupRel && file_exists($backupBase . $backupRel)) {
            $restored = $this->restore_from_new_backup($imageID, $backupBase, $uploadDir);
            if ($restored) error_log('[WPC Restore] Restored from /wpc-backups/ image=' . $imageID);
        }

        // ── PRIORITY 2: Legacy backup directory (ic_backup_images meta) ──
        if (!$restored) {
            $legacyBackup = get_post_meta($imageID, 'ic_backup_images', true);
            if (!empty($legacyBackup) && is_array($legacyBackup)) {
                $legacyPath = $legacyBackup['original'] ?? $legacyBackup['full'] ?? '';
                if ($legacyPath && file_exists($legacyPath) && filesize($legacyPath) > 0) {
                    $scaledPath = get_attached_file($imageID);
                    $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaledPath;
                    $targetPath = ($unscaledPath && $unscaledPath !== $scaledPath) ? $unscaledPath : $scaledPath;

                    @copy($legacyPath, $targetPath);
                    @unlink($legacyPath);

                    // Regenerate -scaled + thumbnails from restored original
                    $newMeta = wp_generate_attachment_metadata($imageID, $targetPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);

                    $restored = true;
                    error_log('[WPC Restore] Restored from legacy backup image=' . $imageID . ' size=' . filesize($targetPath));
                }
                delete_post_meta($imageID, 'ic_backup_images');
                delete_post_meta($imageID, 'ic_compressed_images');
                delete_post_meta($imageID, 'ic_compressed_thumbs');
            }
        }

        // ── PRIORITY 3: Inline _bkp files ────────────────────────────
        if (!$restored) {
            $restored = $this->restore_from_bkp_files($imageID);
            if ($restored) error_log('[WPC Restore] Restored from _bkp files image=' . $imageID);
        }

        // ── PRIORITY 4: Cloud download from service ──────────────────
        if (!$restored) {
            $restored = $this->restore_from_cloud($imageID);
            if ($restored) error_log('[WPC Restore] Restored from cloud image=' . $imageID);
        }

        // ── PRIORITY 5: Safety net — regenerate from unscaled ────────
        if (!$restored) {
            $restored = $this->regenerate_from_unscaled($imageID);
            if ($restored) error_log('[WPC Restore] Restored via regeneration image=' . $imageID);
        }

        // Delete backup copies now that originals are restored
        $this->cleanup_backups($imageID, $backupBase, $uploadDir);

        // ALWAYS clean metadata — never leave image stuck
        $this->cleanRestoreMeta($imageID);

        clearstatcache(true);
        $finalFile = get_attached_file($imageID);
        $finalSize = ($finalFile && file_exists($finalFile)) ? filesize($finalFile) : 'MISSING';
        error_log('[WPC Restore] DONE image=' . $imageID . ' restored=' . ($restored ? 'Y' : 'N') . ' file_size=' . $finalSize . ' time=' . round(microtime(true) - $t_total, 2) . 's');

        return true;
    }

    // ─── Restore sub-functions ──────────────────────────────────────

    private function restore_from_new_backup($imageID, $backupBase, $uploadDir) {
        $meta = wp_get_attachment_metadata($imageID);
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;
        $filesCopied = 0;

        // Restore unscaled
        if ($unscaled) {
            $rel = str_replace($uploadDir . '/', '', $unscaled);
            $src = $backupBase . $rel;
            if (file_exists($src)) { @copy($src, $unscaled); $filesCopied++; }
        }

        // Restore scaled
        if ($scaled && $scaled !== $unscaled) {
            $rel = str_replace($uploadDir . '/', '', $scaled);
            $src = $backupBase . $rel;
            if (file_exists($src)) { @copy($src, $scaled); $filesCopied++; }
        }

        // Restore thumbnails
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($scaled ?: $unscaled);
            foreach ($meta['sizes'] as $size => $info) {
                if (empty($info['file'])) continue;
                $thumbPath = $dir . '/' . $info['file'];
                $rel = str_replace($uploadDir . '/', '', $thumbPath);
                $src = $backupBase . $rel;
                if (file_exists($src)) { @copy($src, $thumbPath); $filesCopied++; }
            }
        }

        // If backup mode was 'originals', or main file is missing, regenerate from unscaled
        $backupMode = get_post_meta($imageID, 'wpc_backup_mode', true) ?: 'full';
        $needsRegen = ($backupMode === 'originals' || $backupMode === 'local');
        $mainMissing = ($scaled && !file_exists($scaled) && $unscaled && file_exists($unscaled));

        if ($needsRegen || $mainMissing) {
            $regenSource = ($unscaled && file_exists($unscaled)) ? $unscaled : $scaled;
            if ($regenSource && file_exists($regenSource)) {
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $regenSource);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                error_log('[WPC Restore] Regenerated thumbnails image=' . $imageID . ' mode=' . $backupMode);
            }
        }

        error_log('[WPC Restore] new_backup files_copied=' . $filesCopied . ' image=' . $imageID);
        return $filesCopied > 0;
    }

    private function restore_from_bkp_files($imageID) {
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;
        $dir = dirname($scaled ?: $unscaled);
        $baseName = pathinfo($unscaled ?: $scaled, PATHINFO_FILENAME);
        $restored = false;

        // Find and restore all _bkp files for this image
        foreach (glob($dir . '/' . $baseName . '*_bkp.*') as $bkpFile) {
            $original = str_replace('_bkp.', '.', $bkpFile);
            @copy($bkpFile, $original);
            @unlink($bkpFile);
            $restored = true;
        }

        // Also check exact _bkp suffix (e.g. photo-scaled_bkp.jpg)
        $scaledBkp = preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaled);
        if ($scaledBkp && file_exists($scaledBkp)) {
            @copy($scaledBkp, $scaled);
            @unlink($scaledBkp);
            $restored = true;
        }

        // Regenerate if needed
        if ($restored && $scaled && !file_exists($scaled) && $unscaled && file_exists($unscaled) && $unscaled !== $scaled) {
            $newMeta = wp_generate_attachment_metadata($imageID, $unscaled);
            if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
        }

        return $restored;
    }

    private function restore_from_cloud($imageID) {
        $site_url = get_site_url();
        $options = get_option(WPS_IC_OPTIONS);
        $request_url = add_query_arg(['imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => $options['api_key'] ?? ''], WPC_IC_LOCAL_RESTORE);

        $t_svc = microtime(true);
        $response = wp_remote_get($request_url, ['timeout' => 30, 'sslverify' => false]);

        if (is_wp_error($response)) {
            error_log('[WPC Restore] Cloud service error image=' . $imageID . ' err=' . $response->get_error_message());
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        error_log('[WPC Restore] Cloud service image=' . $imageID . ' backups=' . count($data['backupUrls'] ?? []) . ' time=' . round(microtime(true) - $t_svc, 2) . 's');

        if (empty($data['backupUrls'])) return false;

        // Collect URLs by label
        $byLabel = [];
        foreach ($data['backupUrls'] as $b) {
            $byLabel[$b['sizeLabel']] = $b['fileUrl'];
        }

        // Try in priority order
        $scaledPath = get_attached_file($imageID);
        $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaledPath;
        $hasScaled = ($unscaledPath && $unscaledPath !== $scaledPath);

        foreach (['unscaled', 'original', 'scaled'] as $label) {
            if (empty($byLabel[$label])) continue;

            $t_dl = microtime(true);
            $tmp = download_url($byLabel[$label], 60);

            if (is_wp_error($tmp)) {
                error_log('[WPC Restore] Cloud download failed image=' . $imageID . ' label=' . $label . ' err=' . $tmp->get_error_message());
                continue;
            }

            $dlSize = filesize($tmp);
            error_log('[WPC Restore] Cloud download image=' . $imageID . ' label=' . $label . ' size=' . $dlSize . ' time=' . round(microtime(true) - $t_dl, 2) . 's');

            if ($label === 'unscaled' && $hasScaled) {
                // Restore unscaled original, regenerate scaled + thumbnails
                @copy($tmp, $unscaledPath);
                @unlink($tmp);
                $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
            } else {
                // Restore directly to the attached file path
                @copy($tmp, $scaledPath);
                @unlink($tmp);
            }

            return true;
        }

        // Last resort: try first available URL regardless of label
        if (!empty($data['backupUrls'][0]['fileUrl'])) {
            $tmp = download_url($data['backupUrls'][0]['fileUrl'], 60);
            if (!is_wp_error($tmp)) {
                @copy($tmp, $scaledPath);
                @unlink($tmp);
                return true;
            }
        }

        return false;
    }

    private function regenerate_from_unscaled($imageID) {
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;

        if ($unscaled && file_exists($unscaled) && $unscaled !== $scaled) {
            $newMeta = wp_generate_attachment_metadata($imageID, $unscaled);
            if ($newMeta) {
                wp_update_attachment_metadata($imageID, $newMeta);
                return true;
            }
        }

        // If unscaled == scaled and file exists, it's just a small image — nothing to regenerate
        if ($scaled && file_exists($scaled)) return true;

        return false;
    }

    private function cleanup_backups($imageID, $backupBase, $uploadDir) {
        $meta = wp_get_attachment_metadata($imageID);
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;

        // Delete new backup directory copies
        $filesToClean = [];
        if ($unscaled) $filesToClean[] = $unscaled;
        if ($scaled && $scaled !== $unscaled) $filesToClean[] = $scaled;
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($scaled ?: $unscaled);
            foreach ($meta['sizes'] as $info) {
                if (!empty($info['file'])) $filesToClean[] = $dir . '/' . $info['file'];
            }
        }
        foreach ($filesToClean as $f) {
            $rel = str_replace($uploadDir . '/', '', $f);
            $backupFile = $backupBase . $rel;
            if (file_exists($backupFile)) @unlink($backupFile);
        }

        // Clean up wpc_backup_path meta
        delete_post_meta($imageID, 'wpc_backup_path');
    }

    // ─── End restore sub-functions ──────────────────────────────────

    // ─── Dead legacy code below was removed in rebuild ──────────────
    // olderBackup(), old restoreV4 tail, old restore() — all replaced by
    // restoreV4() with sub-functions above.
    // Legacy ic_backup_images meta is handled in restoreV4 Priority 2.

    /* START DEAD CODE — kept for reference only
        if (is_wp_error($response)) {
            error_log('[WPC Restore] Service error image=' . $imageID . ' err=' . $response->get_error_message() . ' time=' . $svcTime . 's');
            $this->cleanRestoreMeta($imageID);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $backupCount = !empty($data['backupUrls']) ? count($data['backupUrls']) : 0;
        error_log('[WPC Restore] Service response image=' . $imageID . ' backups=' . $backupCount . ' time=' . $svcTime . 's');

        if (empty($data['backupUrls'])) {
            $this->cleanRestoreMeta($imageID);
            error_log('[WPC Restore] Done image=' . $imageID . ' method=no_backups time=' . round(microtime(true) - $t_total, 2) . 's');
            return true;
        }

        // Try local _bkp backup FIRST (most reliable — was copied before overwrite)
        $restored = false;
        $scaledPath = get_attached_file($imageID);
        $unscaledPath = $scaledPath ? str_replace('-scaled.', '.', $scaledPath) : '';
        $hasScaled = ($unscaledPath !== $scaledPath);

        $localBkpPaths = [];
        if ($hasScaled && $unscaledPath) $localBkpPaths[] = $unscaledPath . '_bkp';
        if ($scaledPath) $localBkpPaths[] = $scaledPath . '_bkp';

        foreach ($localBkpPaths as $bkpPath) {
            if ($bkpPath && file_exists($bkpPath) && filesize($bkpPath) > 0) {
                $targetPath = str_replace('_bkp', '', $bkpPath);
                if (@copy($bkpPath, $targetPath)) {
                    @unlink($bkpPath);

                    $isUnscaled = ($hasScaled && strpos($targetPath, '-scaled.') === false);
                    if ($isUnscaled) {
                        $t_regen = microtime(true);
                        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                        $newMeta = wp_generate_attachment_metadata($imageID, $targetPath);
                        if ($newMeta) {
                            wp_update_attachment_metadata($imageID, $newMeta);
                        }
                        error_log('[WPC Restore] Regenerated thumbnails image=' . $imageID . ' time=' . round(microtime(true) - $t_regen, 2) . 's');
                    }

                    error_log('[WPC Restore] Local _bkp restored image=' . $imageID . ' path=' . basename($bkpPath));
                    $restored = true;
                    break;
                }
            }
        }

        // Fallback: download from service if no local backup
        if (!$restored) {
        // Collect all available backup URLs by label
        $backupsByLabel = [];
        foreach ($data['backupUrls'] as $backupFile) {
            $backupsByLabel[$backupFile['sizeLabel']] = $backupFile['fileUrl'];
        }

        // Try in priority order: unscaled → original → scaled → any first URL
        $tryOrder = ['unscaled', 'original', 'scaled'];
        $restoreUrl = null;
        $restoreLabel = null;
        foreach ($tryOrder as $label) {
            if (!empty($backupsByLabel[$label])) {
                $restoreUrl = $backupsByLabel[$label];
                $restoreLabel = $label;
                break;
            }
        }
        if (!$restoreUrl && !empty($data['backupUrls'][0]['fileUrl'])) {
            $restoreUrl = $data['backupUrls'][0]['fileUrl'];
            $restoreLabel = $data['backupUrls'][0]['sizeLabel'] ?? 'unknown';
        }

        if ($restoreUrl) {
            error_log('[WPC Restore] Downloading from cloud image=' . $imageID . ' label=' . $restoreLabel);
            $t_dl = microtime(true);
            $tmp = download_url($restoreUrl, 60);
            $dlTime = round(microtime(true) - $t_dl, 2);

            if (!is_wp_error($tmp)) {
                error_log('[WPC Restore] Cloud download image=' . $imageID . ' time=' . $dlTime . 's size=' . filesize($tmp));
                if ($restoreLabel === 'unscaled') {
                    $unscaledPath = $hasScaled ? str_replace('-scaled.', '.', $scaledPath) : $scaledPath;
                    copy($tmp, $unscaledPath);
                    @unlink($tmp);

                    $t_regen = microtime(true);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                    if ($newMeta) {
                        wp_update_attachment_metadata($imageID, $newMeta);
                    }
                    error_log('[WPC Restore] Regenerated from unscaled image=' . $imageID . ' time=' . round(microtime(true) - $t_regen, 2) . 's');
                } else {
                    if ($scaledPath) {
                        copy($tmp, $scaledPath);
                    }
                    @unlink($tmp);
                    error_log('[WPC Restore] Replaced scaled file image=' . $imageID);
                }

                if ($scaledPath && file_exists($scaledPath . '_bkp')) {
                    @unlink($scaledPath . '_bkp');
                }

                $restored = true;
            } else {
                error_log('[WPC Restore] Cloud download FAILED image=' . $imageID . ' label=' . $restoreLabel . ' err=' . $tmp->get_error_message() . ' time=' . $dlTime . 's');

                // Retry with next available label
                foreach ($tryOrder as $retryLabel) {
                    if ($retryLabel === $restoreLabel) continue;
                    if (empty($backupsByLabel[$retryLabel])) continue;

                    error_log('[WPC Restore] Retrying cloud download image=' . $imageID . ' label=' . $retryLabel);
                    $t_dl2 = microtime(true);
                    $tmp2 = download_url($backupsByLabel[$retryLabel], 60);

                    if (!is_wp_error($tmp2)) {
                        error_log('[WPC Restore] Retry succeeded image=' . $imageID . ' label=' . $retryLabel . ' time=' . round(microtime(true) - $t_dl2, 2) . 's size=' . filesize($tmp2));
                        if ($retryLabel === 'unscaled') {
                            $unscaledPath = $hasScaled ? str_replace('-scaled.', '.', $scaledPath) : $scaledPath;
                            copy($tmp2, $unscaledPath);
                            @unlink($tmp2);
                            remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                            $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                            if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                        } else {
                            copy($tmp2, $scaledPath);
                            @unlink($tmp2);
                        }
                        $restored = true;
                        break;
                    } else {
                        error_log('[WPC Restore] Retry also failed image=' . $imageID . ' label=' . $retryLabel . ' err=' . $tmp2->get_error_message());
                    }
                }
            }
        }
        } // end if (!$restored) — service fallback

        // Final safety net: if the main attached file STILL doesn't exist on disk
        $attachedFile = get_attached_file($imageID);
        if ($attachedFile && !file_exists($attachedFile)) {
            $unscaledPath = str_replace('-scaled.', '.', $attachedFile);
            if ($unscaledPath !== $attachedFile && file_exists($unscaledPath)) {
                error_log('[WPC Restore] Safety net: regenerating from unscaled image=' . $imageID);
                $t_regen = microtime(true);
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                if ($newMeta) {
                    wp_update_attachment_metadata($imageID, $newMeta);
                }
                error_log('[WPC Restore] Safety net regen image=' . $imageID . ' time=' . round(microtime(true) - $t_regen, 2) . 's');
                $restored = true;
            } else {
                error_log('[WPC Restore] Safety net: no unscaled found image=' . $imageID . ' file_missing=' . basename($attachedFile));
            }
        }

        // Restore any thumbnail _bkp files that belong to this image
        $attachedFile = get_attached_file($imageID);
        if ($attachedFile) {
            $dir = dirname($attachedFile);
            $imgBase = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
            $bkpCount = 0;
            foreach (glob($dir . '/' . $imgBase . '*_bkp.*') as $bkpFile) {
                $original = str_replace('_bkp.', '.', $bkpFile);
                $bkpCount++;
                @copy($bkpFile, $original);
                @unlink($bkpFile);
            }
            if ($bkpCount > 0) {
                error_log('[WPC Restore] Restored ' . $bkpCount . ' thumbnail _bkp files image=' . $imageID);
            }
        }

        // Verify file exists after restore
        clearstatcache(true);
        $finalFile = get_attached_file($imageID);
        $finalExists = ($finalFile && file_exists($finalFile)) ? filesize($finalFile) : 'MISSING';
        $method = $restored ? 'local_bkp_or_cloud' : 'cleanup_only';

        // Clean metadata, variants, caches
        $this->cleanRestoreMeta($imageID);

        error_log('[WPC Restore] DONE image=' . $imageID . ' method=' . $method . ' file_size=' . $finalExists . ' total_time=' . round(microtime(true) - $t_total, 2) . 's');

        return true;


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

    END DEAD CODE */

    /**
     * Clean all optimization metadata and variants. Used by every restore exit path.
     * Guarantees the image is never stuck in a compressed/optimizing state.
     */
    private function cleanRestoreMeta($imageID) {
        $attachedFile = get_attached_file($imageID);
        if ($attachedFile) {
            $dir = dirname($attachedFile);
            $baseName = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
            foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { @unlink($webp); }
            foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { @unlink($avif); }
        }

        delete_post_meta($imageID, 'ic_bulk_running');
        delete_post_meta($imageID, 'ic_compressing');
        delete_post_meta($imageID, 'wpc_images_compressed');
        delete_post_meta($imageID, 'ic_stats');
        delete_post_meta($imageID, 'ic_local_variants');
        delete_post_meta($imageID, 'ic_savings');
        delete_post_meta($imageID, 'ic_savings_format');
        delete_post_meta($imageID, 'ic_savings_bytes');
        delete_post_meta($imageID, 'ic_savings_baseline');
        delete_post_meta($imageID, 'ic_skipped');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wps_ic_queue_' . $imageID);
        update_post_meta($imageID, 'ic_status', 'restored');

        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

        if (function_exists('wpc_invalidate_local_cache')) { wpc_invalidate_local_cache(); }
        if (function_exists('wpc_purge_cdn_urls')) { wpc_purge_cdn_urls($imageID); }
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

            $scaledPath = get_attached_file($imageID);
            $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaledPath;
            if (!$unscaledPath) $unscaledPath = $scaledPath;
            $hasScaledVersion = ($scaledPath !== $unscaledPath);

            // Restore only full (backups stored as 'original' key, legacy used 'full')
            $restore_image_path = isset($backup_images['original']) ? $backup_images['original'] : (isset($backup_images['full']) ? $backup_images['full'] : '');

            // Also check for _bkp file as alternative source
            $scaledBkp = $scaledPath . '_bkp';
            $inlineBkp = preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaledPath);

            if (!empty($restore_image_path) && file_exists($restore_image_path)) {
                // Backup directory restore
                if ($hasScaledVersion) {
                    // Has separate unscaled — restore to unscaled, regen scaled
                    @copy($restore_image_path, $unscaledPath);
                    if (file_exists($scaledPath)) @unlink($scaledPath);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                } else {
                    // No separate unscaled — restore directly to the attached file path
                    @copy($restore_image_path, $scaledPath);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $scaledPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                }
                @unlink($restore_image_path);
            } elseif (file_exists($inlineBkp)) {
                // _bkp inline file restore (e.g. photo-scaled_bkp.jpg)
                @copy($inlineBkp, $scaledPath);
                @unlink($inlineBkp);
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $hasScaledVersion ? $unscaledPath : $scaledPath);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);

                clearstatcache();

                // Remove all compression meta
                delete_post_meta($imageID, 'ic_stats');
                delete_post_meta($imageID, 'ic_compressed_images');
                delete_post_meta($imageID, 'ic_compressed_thumbs');
                delete_post_meta($imageID, 'ic_backup_images');
                delete_post_meta($imageID, 'ic_local_variants');
                delete_post_meta($imageID, 'ic_savings');
                delete_post_meta($imageID, 'ic_savings_format');
                delete_post_meta($imageID, 'ic_savings_bytes');
                delete_post_meta($imageID, 'ic_savings_baseline');
                delete_post_meta($imageID, 'ic_ai_meta');
                delete_post_meta($imageID, 'ic_compressing');
                delete_post_meta($imageID, 'wpc_images_compressed');
                delete_post_meta($imageID, 'ic_bulk_running');
                update_post_meta($imageID, 'ic_status', 'restored');
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                error_log('[WPC Restore] olderBackup succeeded image=' . $imageID);
                return true;
            }

            // Backup file missing — clean up the stale meta and fall through to newer restore logic
            error_log('[WPC Restore] olderBackup file MISSING image=' . $imageID . ' path=' . $restore_image_path);
            delete_post_meta($imageID, 'ic_backup_images');
            delete_post_meta($imageID, 'ic_compressed_images');
            delete_post_meta($imageID, 'ic_compressed_thumbs');
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
                        delete_post_meta($imageID, 'ic_local_variants');
                        delete_post_meta($imageID, 'ic_savings');
                        delete_post_meta($imageID, 'ic_savings_format');
                        delete_post_meta($imageID, 'ic_savings_bytes');
                        delete_post_meta($imageID, 'ic_savings_baseline');
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
                    delete_post_meta($imageID, 'ic_local_variants');
                    delete_post_meta($imageID, 'ic_savings');
                    delete_post_meta($imageID, 'ic_savings_format');
                    delete_post_meta($imageID, 'ic_savings_bytes');
                    delete_post_meta($imageID, 'ic_savings_baseline');
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