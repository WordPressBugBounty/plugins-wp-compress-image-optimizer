<?php


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
        // Build params with all local optimization settings
        $params = wps_local_compress::buildOptimizeParams(null, self::$siteUrl);

        // Build full API URL
        $request_url = add_query_arg($params, WPC_IC_LOCAL_BULK_START);

        // Make the GET request
        $response = wp_remote_get($request_url, array('timeout' => 80, 'sslverify' => false));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);

            if ($body == 'queue-prepared') {

                $request_url = add_query_arg($params, WPC_IC_LOCAL_BULK_RUN);

                // Make the GET request
                $response = wp_remote_get($request_url, array('timeout' => 60, 'sslverify' => false));

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

        // Values to prepare
        $post_type = 'attachment';
        $wpc_mimes_pi = function_exists('wpc_optimizable_mimes')
            ? array_values(wpc_optimizable_mimes())
            : ['image/jpeg', 'image/png', 'image/gif'];
        $wpc_mimes_ph = implode(', ', array_fill(0, count($wpc_mimes_pi), '%s'));


        // UNCOMPRESSED (exclude excluded images)
        $queryUncompressed = $wpdb->get_results(
            $wpdb->prepare(
                "
        SELECT posts.ID
        FROM {$wpdb->posts} posts
        WHERE posts.post_type = %s
        AND posts.post_mime_type IN (" . $wpc_mimes_ph . ")
        AND NOT EXISTS (
            SELECT 1
            FROM {$wpdb->postmeta} meta
            WHERE meta.post_id = posts.ID
            AND (
                meta.meta_key = 'ic_stats'
                OR (meta.meta_key = 'ic_status' AND meta.meta_value = 'compressed')
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} ex
            WHERE ex.post_id = posts.ID AND ex.meta_key = 'wps_ic_exclude_live'
        )
        ",
                $post_type,
                ...$wpc_mimes_pi
            )
        );

        // COMPRESSED (exclude excluded images)
        $queryCompressed = $wpdb->get_results(
            $wpdb->prepare(
                "
        SELECT posts.ID
        FROM {$wpdb->posts} posts
        WHERE posts.post_type = %s
        AND posts.post_mime_type IN (" . $wpc_mimes_ph . ")
        AND EXISTS (
            SELECT 1
            FROM {$wpdb->postmeta} meta
            WHERE meta.post_id = posts.ID
            AND (
                meta.meta_key = 'ic_stats'
                OR (meta.meta_key = 'ic_status' AND meta.meta_value = 'compressed')
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} ex
            WHERE ex.post_id = posts.ID AND ex.meta_key = 'wps_ic_exclude_live'
        )
        ",
                $post_type,
                ...$wpc_mimes_pi
            )
        );


        $bulkStatus['foundImageCount'] = 0;
        $bulkStatus['foundThumbCount'] = 0;
        $bulkStatus['restoredImageCount'] = 0;

        if ($queryUncompressed) {
            foreach ($queryUncompressed as $image) {
                $imageID = $image->ID;
                self::$uncompressedImages[$imageID] = $imageID;
            }
        }

        if ($queryCompressed) {
            foreach ($queryCompressed as $image) {
                $imageID = $image->ID;
                self::$compressedImages[$imageID] = $imageID;
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

    public static function countLibraryImages($fresh = false)
    {
        // SNAPSHOT-FIRST (never block a render): any existing snapshot serves immediately,
        // however stale — staleness schedules a detached post-response recompute instead.
        // The full-library scan (temp table + filesort over all attachment postmeta) runs
        // inline only on the very first call ever, pre-stamped so concurrent loads can't
        // stampede it. This was the "bulk page sometimes needs a refresh" root cause.
        $wpc_blc = get_option('wpc_bulk_library_counts_d');
        $wpc_has_snap = is_array($wpc_blc) && isset($wpc_blc['uncompressed'], $wpc_blc['compressed']);
        if (!$fresh && $wpc_has_snap) {
            if ((time() - (int) ($wpc_blc['t'] ?? 0)) >= 300) {
                self::scheduleCountsRefresh();
            }
            $u = (int) $wpc_blc['uncompressed'];
            $c = (int) $wpc_blc['compressed'];
            return [
                'compressed'   => $c > 0 ? array_fill(0, $c, 1) : [],
                'uncompressed' => $u > 0 ? array_fill(0, $u, 1) : [],
            ];
        }
        if (!$fresh && !$wpc_has_snap) {
            // First call ever: pre-stamp BEFORE the scan so a concurrent load returns the
            // pending placeholder instead of running a second full scan (anti-stampede).
            $wpc_pend = is_array($wpc_blc) && !empty($wpc_blc['pending']);
            if ($wpc_pend && (time() - (int) ($wpc_blc['t'] ?? 0)) < 120) {
                return ['compressed' => [], 'uncompressed' => []];
            }
            update_option('wpc_bulk_library_counts_d', ['t' => time(), 'pending' => 1, 'uncompressed' => 0, 'compressed' => 0], false);
        }

        global $wpdb;

        $uncompressed = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM (
                SELECT MIN(p.ID) AS id
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} f
                    ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
                LEFT JOIN {$wpdb->postmeta} s
                    ON s.post_id = p.ID AND s.meta_key = 'ic_stats'
                LEFT JOIN {$wpdb->postmeta} v
                    ON v.post_id = p.ID AND v.meta_key = 'ic_status' AND v.meta_value = 'compressed'
                WHERE p.post_type = 'attachment'
                  AND p.post_mime_type IN ('" . implode("','", array_map('esc_sql', function_exists('wpc_optimizable_mimes') ? wpc_optimizable_mimes() : ['image/jpeg','image/png','image/gif'])) . "')
                  AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} ex
                      WHERE ex.post_id = p.ID AND ex.meta_key = 'wps_ic_exclude_live'
                  )
                GROUP BY f.meta_value
                HAVING SUM(CASE WHEN s.meta_id IS NULL AND v.meta_id IS NULL THEN 0 ELSE 1 END) = 0
            ) AS u
        ");

        $compressed = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
              AND p.post_mime_type IN ('" . implode("','", array_map('esc_sql', function_exists('wpc_optimizable_mimes') ? wpc_optimizable_mimes() : ['image/jpeg','image/png','image/gif'])) . "')
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} meta
                  WHERE meta.post_id = p.ID
                  AND (
                      meta.meta_key = 'ic_stats'
                      OR (meta.meta_key = 'ic_status' AND meta.meta_value = 'compressed')
                  )
              )
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} ex
                  WHERE ex.post_id = p.ID AND ex.meta_key = 'wps_ic_exclude_live'
              )
        ");

        set_transient('wpc_bulk_library_counts', [
            'uncompressed' => $uncompressed,
            'compressed'   => $compressed,
        ], 60);
        update_option('wpc_bulk_library_counts_d', [
            't'            => time(),
            'uncompressed' => $uncompressed,
            'compressed'   => $compressed,
        ], false);

        return [
            'compressed'   => $compressed > 0 ? array_fill(0, $compressed, 1) : [],
            'uncompressed' => $uncompressed > 0 ? array_fill(0, $uncompressed, 1) : [],
        ];
    }

    // Detached recompute: response flushes first (law 9 — no cron reliance, no admin
    // wall-time); duplicate scheduling collapsed by a static flag + the pre-stamp above.
    public static function scheduleCountsRefresh()
    {
        static $armed = false;
        if ($armed) { return; }
        $armed = true;
        add_action('shutdown', function () {
            $fin = false;
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); $fin = true; }
            elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); $fin = true; }
            if (!$fin) { return; }   // no detach available → next natural first-call recomputes
            if (function_exists('ignore_user_abort')) { ignore_user_abort(true); }
            @set_time_limit(120);
            // Re-stamp before the scan so overlapping shutdown runners from parallel
            // requests skip (the 300s staleness check above won't re-arm for 300s).
            $snap = get_option('wpc_bulk_library_counts_d');
            if (is_array($snap)) {
                $snap['t'] = time();
                update_option('wpc_bulk_library_counts_d', $snap, false);
            }
            self::countLibraryImages(true);
        }, PHP_INT_MAX);
    }


    public function prepareImages($action = 'compressing', $process = 'count', $limit = '-1')
    {


        if ($process === 'count' && $action !== 'compressing') {
            return self::countLibraryImages();
        }

        // Raise resource limits
        ini_set('memory_limit', '2024M');
        ini_set('max_execution_time', '300');

        global $wpdb;

        self::$uncompressedImages = [];
        self::$compressedImages = [];

        $batch_size = 1000;
        $offset = 0;
        $bulkStatus = ['foundImageCount' => 0, 'foundThumbCount' => 0,];

        // Both scans below are unbounded batch loops over posts x postmeta whose only
        // real ceiling was set_time_limit(120). On a 4-worker host one of these can hold
        // a quarter of the pool for two minutes, so carry a wall budget and stop cleanly.
        // Checked at the END of a body, so at least one batch always lands and the counts
        // are never zero; a trip is journaled because a partial count must not read as
        // "this library is small".
        $wpc_pi514_t0  = microtime(true);
        $wpc_pi514_bud = (float) apply_filters('wpc_prepare_images_budget_s', 20.0);
        $wpc_pi514_cut = false;

        while (true) {


            $uncompressed_ids = $wpdb->get_col($wpdb->prepare("
        SELECT MIN(p.ID) AS id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} f
            ON f.post_id = p.ID
           AND f.meta_key = '_wp_attached_file'
        LEFT JOIN {$wpdb->postmeta} s
            ON s.post_id = p.ID
           AND s.meta_key = 'ic_stats'
        LEFT JOIN {$wpdb->postmeta} v
            ON v.post_id = p.ID
           AND v.meta_key = 'ic_status'
           AND v.meta_value = 'compressed'
        WHERE p.post_type = 'attachment'
          AND p.post_mime_type IN ('" . implode("','", array_map('esc_sql', function_exists('wpc_optimizable_mimes') ? wpc_optimizable_mimes() : ['image/jpeg','image/png','image/gif'])) . "')
          AND NOT EXISTS (
              SELECT 1 FROM {$wpdb->postmeta} ex
              WHERE ex.post_id = p.ID AND ex.meta_key = 'wps_ic_exclude_live'
          )
        GROUP BY f.meta_value
        HAVING SUM(CASE WHEN s.meta_id IS NULL AND v.meta_id IS NULL THEN 0 ELSE 1 END) = 0
        ORDER BY id ASC
        LIMIT %d OFFSET %d
    ", $batch_size, $offset));

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
            if ($wpc_pi514_bud > 0 && (microtime(true) - $wpc_pi514_t0) >= $wpc_pi514_bud) {
                $wpc_pi514_cut = 'uncompressed@' . $offset;
                break;
            }
        }


        $offset = 0;
        while (true) {
            $compressed_ids = $wpdb->get_col($wpdb->prepare("
            SELECT posts.ID
            FROM {$wpdb->posts} posts
            WHERE posts.post_type = 'attachment'
              AND posts.post_mime_type IN ('" . implode("','", array_map('esc_sql', function_exists('wpc_optimizable_mimes') ? wpc_optimizable_mimes() : ['image/jpeg','image/png','image/gif'])) . "')
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} meta
                  WHERE meta.post_id = posts.ID
                  AND (
                      meta.meta_key = 'ic_stats'
                      OR (meta.meta_key = 'ic_status' AND meta.meta_value = 'compressed')
                  )
              )
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} ex
                  WHERE ex.post_id = posts.ID AND ex.meta_key = 'wps_ic_exclude_live'
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
            if ($wpc_pi514_bud > 0 && (microtime(true) - $wpc_pi514_t0) >= $wpc_pi514_bud) {
                $wpc_pi514_cut = 'compressed@' . $offset;
                break;
            }
        }

        if ($wpc_pi514_cut !== false && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('prepare-images-budget', '', '', [
                'cut'   => $wpc_pi514_cut,
                'ms'    => (int) round((microtime(true) - $wpc_pi514_t0) * 1000),
                'found' => (int) $bulkStatus['foundImageCount'],
            ]);
        }

        // Save to option if requested
        if ($action === 'compressing' && $process !== 'count') {
            update_option('wps_ic_BulkStatus', $bulkStatus);
        }

        return ['compressed' => self::$compressedImages, 'uncompressed' => self::$uncompressedImages,];
    }

}