<?php


class wps_ic_offloading
{


    public function __construct()
    {
        if (!empty($_GET['runOffloading'])) {
            wp_send_json_success('running offloading');
        }
    }


    public function init()
    {
        // Hook into 'wp_get_attachment_url' to modify the URL for images in the Media Library
        add_filter('wp_get_attachment_url', [__CLASS__,'custom_replace_thumbnail_url'], 10, 2);
        add_filter('wp_get_attachment_image_src', [__CLASS__,'custom_replace_admin_thumbnail_url'], 10, 3);
    }


    public static function custom_replace_thumbnail_url($url, $post_id)
    {
        // Define your S3 bucket or custom CDN URL
        // v7.10.650 — a hardcoded live zapwp credential shipped here in plaintext to every
        // install. This path is dormant (init() is never called, so these filters are never
        // registered), but a real secret must not travel in distributed source. The zone is
        // resolved from this site's own configuration instead; no zone means no rewrite.
        $wpc_zone650 = (string) get_option('ic_cdn_zone_name', '');
        if ($wpc_zone650 === '') {
            return $url;
        }
        $custom_base_url = 'https://' . $wpc_zone650 . '/q:i/r:0/wp:1/w:1/u:';

        // Get the file path of the image
        $file_path = get_post_meta($post_id, '_wp_attached_file', true);

        if ($file_path) {
            // Construct the full URL by appending the relative file path to the custom base URL
            if (is_array($url)) {
                $url[0] = $custom_base_url . site_url('wp-content/uploads/' . ltrim($file_path, '/'));
            } else {
                $url = $custom_base_url . site_url('wp-content/uploads/' . ltrim($file_path, '/'));
            }
        }

        return $url;
    }


    // Filter the thumbnail URL for images in the Admin area
    public static function custom_replace_admin_thumbnail_url($url, $attachment_id, $size)
    {
        return self::custom_replace_thumbnail_url($url, $attachment_id);
    }


}