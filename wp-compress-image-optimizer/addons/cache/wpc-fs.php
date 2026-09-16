<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/wpc-fs.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.24.04
 */


if (!function_exists('wpc_fs_put')) {
    





    function wpc_fs_put($path, $data, $flags = 0)
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }
        $append = (bool) ((int) $flags & FILE_APPEND);
        $lock   = (bool) ((int) $flags & LOCK_EX);
        $mode   = $append ? 'ab' : ($lock ? 'cb' : 'wb');
        $fh = @fopen($path, $mode);
        if (!$fh) {
            return false;
        }
        if ($lock && !@flock($fh, LOCK_EX)) {
            @fclose($fh);
            return false;
        }
        if ($lock && !$append) {
            if (!@ftruncate($fh, 0)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
                return false;
            }
            @rewind($fh);
        }
        if (is_resource($data)) {
            $done = @stream_copy_to_stream($data, $fh);
            $len  = $done;
        } else {
            $data = is_array($data) ? implode('', $data) : (string) $data;
            $len  = strlen($data);
            $done = 0;
            while ($done < $len) {
                $n = @fwrite($fh, substr($data, $done));
                if ($n === false || $n === 0) {
                    break;
                }
                $done += $n;
            }
        }
        if ($lock) {
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
        return ($done !== false && $done === $len) ? $done : false;
    }
}

if (!function_exists('wpc_fs_update')) {
    





    function wpc_fs_update($path, $cb, $nonblock = false)
    {
        $path = (string) $path;
        if ($path === '' || !is_callable($cb)) {
            return [false, null];
        }
        $fh = @fopen($path, 'c+b');
        if (!$fh) {
            return [false, null];
        }
        if (!@flock($fh, $nonblock ? (LOCK_EX | LOCK_NB) : LOCK_EX)) {
            @fclose($fh);
            return [false, null];
        }
        $cur = (string) @stream_get_contents($fh);
        $out = $cb($cur);
        $new = is_array($out) ? (isset($out[0]) ? $out[0] : null) : $out;
        $res = is_array($out) && array_key_exists(1, $out) ? $out[1] : null;
        $ok  = true;
        if ($new !== null) {
            $new = (string) $new;
            if (!@ftruncate($fh, 0) || !@rewind($fh)) {
                $ok = false;
            } else {
                $len = strlen($new);
                $done = 0;
                while ($done < $len) {
                    $n = @fwrite($fh, substr($new, $done));
                    if ($n === false || $n === 0) {
                        break;
                    }
                    $done += $n;
                }
                $ok = ($done === $len);
            }
        }
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return [$ok, $res];
    }
}
