<?php


/**
 * The bulk page's library counts and the bulk-restore population. It sends nothing:
 * bulk compress and restore run through wps_ic_ajax's v2 drains.
 */
class wps_ic_local
{

    private static $uncompressedImages;
    private static $compressedImages;

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

}