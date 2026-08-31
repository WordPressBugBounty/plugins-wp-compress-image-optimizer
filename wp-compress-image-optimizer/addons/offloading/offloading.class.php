<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/offloading/offloading.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



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
        
        add_filter('wp_get_attachment_url', [__CLASS__,'custom_replace_thumbnail_url'], 10, 2);
        add_filter('wp_get_attachment_image_src', [__CLASS__,'custom_replace_admin_thumbnail_url'], 10, 3);
    }


    public static function custom_replace_thumbnail_url($url, $post_id)
    {
        
        
        
        
        
        $wpc_zone650 = (string) get_option('ic_cdn_zone_name', '');
        if ($wpc_zone650 === '') {
            return $url;
        }
        $custom_base_url = 'https://' . $wpc_zone650 . '/q:i/r:0/wp:1/w:1/u:';

        
        $file_path = get_post_meta($post_id, '_wp_attached_file', true);

        if ($file_path) {
            
            if (is_array($url)) {
                $url[0] = $custom_base_url . site_url('wp-content/uploads/' . ltrim($file_path, '/'));
            } else {
                $url = $custom_base_url . site_url('wp-content/uploads/' . ltrim($file_path, '/'));
            }
        }

        return $url;
    }


    
    public static function custom_replace_admin_thumbnail_url($url, $attachment_id, $size)
    {
        return self::custom_replace_thumbnail_url($url, $attachment_id);
    }


}