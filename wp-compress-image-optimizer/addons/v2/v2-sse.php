<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_use_sse_events')) {





function wpc_v2_use_sse_events()
{
    $opt = get_site_option('wpc_v2_use_sse_events', false);
    return (bool) apply_filters('wpc_v2_use_sse_events', !empty($opt));
}

function wpc_v2_register_sse_route()
{
    register_rest_route('wpc/v2', '/events/(?P<imageID>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'wpc_v2_sse_stream',


        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
        'args' => [
            'imageID' => [
                'validate_callback' => function ($v) { return is_numeric($v) && (int) $v > 0; },
            ],
        ],
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_sse_route');


function wpc_v2_sse_stream($request)
{
    $imageID = (int) $request->get_param('imageID');
    if ($imageID <= 0) {
        status_header(400);
        exit;
    }


    if (!wpc_v2_use_sse_events()) {
        status_header(503);
        exit;
    }

    
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    while (ob_get_level()) { @ob_end_flush(); }

    
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    


    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    
    
    header('Content-Encoding: identity');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }

    
    @set_time_limit(75);
    @ignore_user_abort(true);

    $start         = time();
    $max_seconds   = 60;
    $idle_close_s  = 8;
    $tick_us       = 150000; 

    $last_count    = -1;
    $last_change_t = $start;


    echo ": " . str_repeat(' ', 16384) . "\n\n";
    @flush();
    
    
    echo ":\n\n";
    @flush();

    
    echo "event: ready\ndata: {\"imageID\":" . $imageID . "}\n\n";
    @flush();

    while ((time() - $start) < $max_seconds) {
        if (connection_aborted()) break;


        wp_cache_delete($imageID, 'post_meta');
        $variants = get_post_meta($imageID, 'ic_local_variants', true);

        $count = 0; $jpeg = 0; $webp = 0; $avif = 0;
        if (is_array($variants)) {
            foreach ($variants as $vkey => $ventry) {
                
                if (!empty($ventry['bg_no_improvement'])) continue;
                if (empty($ventry['size'])) continue;
                $count++;
                if (strpos((string) $vkey, '-avif') !== false)      $avif++;
                elseif (strpos((string) $vkey, '-webp') !== false)  $webp++;
                else                                                 $jpeg++;
            }
        }

        
        
        $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
        $status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
            ? (string) $ic_compressing['status']
            : 'optimizing';

        $changed = ($count !== $last_count);


        $warming = (bool) get_transient('wpc_v2_warming_' . $imageID);


        static $last_warming = -1;
        if ($warming !== ($last_warming === 1)) {
            $changed = true;
            $last_warming = $warming ? 1 : 0;
        }
        if ($changed) {
            $payload = [
                'imageID' => $imageID,
                'count'   => $count,
                'jpeg'    => $jpeg,
                'webp'    => $webp,
                'avif'    => $avif,
                'status'  => $status,
                'warming' => $warming,
                't'       => time(),
            ];


            static $sent_compressed_html = false;
            if (!$sent_compressed_html && $status === 'compressed') {
                global $wps_ic;
                if (isset($wps_ic) && isset($wps_ic->media_library)
                    && method_exists($wps_ic->media_library, 'compress_details')) {
                    $payload['html'] = $wps_ic->media_library->compress_details($imageID);
                    $sent_compressed_html = true;
                }
            }
            echo "event: variant_count\ndata: " . wp_json_encode($payload) . "\n\n";
            @flush();
            $last_count    = $count;
            $last_change_t = time();
        } else {
            
            
            if ((time() - $last_change_t) % 5 === 0) {
                echo ": ping " . time() . "\n\n";
                @flush();
            }
        }

        
        
        if ($status === 'compressed' && (time() - $last_change_t) >= $idle_close_s) {
            echo "event: done\ndata: {\"count\":" . $count . "}\n\n";
            @flush();
            break;
        }

        usleep($tick_us);
    }

    exit;
}

}
