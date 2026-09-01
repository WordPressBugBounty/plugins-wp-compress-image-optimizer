<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-store.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.353
 */




























if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_store_bytes655')) {

    


    function wpc_v2_store_default_exts655()
    {
        return ['jpg', 'jpeg', 'webp', 'avif', 'png', 'gif'];
    }

    









    function wpc_v2_store_bytes655($bytes, $dest, $opts = [])
    {
        if (!is_string($bytes) || $bytes === '') {
            return ['ok' => false, 'error' => 'empty_bytes', 'msg' => ''];
        }
        $dest = (string) $dest;
        if ($dest === '' || strpos($dest, "\0") !== false) {
            return ['ok' => false, 'error' => 'bad_dest', 'msg' => ''];
        }

        
        
        $exts = isset($opts['exts']) && is_array($opts['exts']) ? $opts['exts'] : wpc_v2_store_default_exts655();
        $ext  = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $exts, true)) {
            return ['ok' => false, 'error' => 'bad_extension', 'msg' => $ext];
        }

        
        
        
        $root = isset($opts['root']) ? (string) $opts['root'] : '';
        if ($root === '' && function_exists('wp_get_upload_dir')) {
            $ud = wp_get_upload_dir();
            $root = isset($ud['basedir']) ? (string) $ud['basedir'] : '';
        }
        $root_real = $root !== '' ? @realpath($root) : false;
        $dir_real  = @realpath(dirname($dest));
        if ($root_real === false || $dir_real === false) {
            return ['ok' => false, 'error' => 'unresolvable_path', 'msg' => ''];
        }
        $root_real = rtrim($root_real, '/\\') . DIRECTORY_SEPARATOR;
        $dir_cmp   = rtrim($dir_real, '/\\') . DIRECTORY_SEPARATOR;
        if (strpos($dir_cmp, $root_real) !== 0) {
            return ['ok' => false, 'error' => 'outside_root', 'msg' => ''];
        }

        
        $tmp = $dest . '.wpc_v2_tmp_' . (function_exists('wp_generate_password')
            ? wp_generate_password(8, false)
            : substr(md5(uniqid('', true)), 0, 8));

        if (@file_put_contents($tmp, $bytes) === false) {
            $e = error_get_last();
            return ['ok' => false, 'error' => 'write_failed', 'msg' => isset($e['message']) ? (string) $e['message'] : ''];
        }
        if (!@rename($tmp, $dest)) {
            $e = error_get_last();
            @unlink($tmp);
            return ['ok' => false, 'error' => 'rename_failed', 'msg' => isset($e['message']) ? (string) $e['message'] : ''];
        }

        $mode = isset($opts['chmod']) ? (int) $opts['chmod'] : 0644;
        @chmod($dest, $mode);

        return ['ok' => true, 'error' => '', 'msg' => '', 'bytes' => strlen($bytes)];
    }
}
