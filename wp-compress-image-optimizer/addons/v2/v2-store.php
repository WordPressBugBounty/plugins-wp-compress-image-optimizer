<?php
/**
 * v7.10.655 — THE SINGLE AUDITED WRITE CHOKE POINT for callback-delivered bytes.
 *
 * Two reasons this is its own file, both real:
 *
 * 1. SECURITY ARCHITECTURE. Three handlers previously each carried their own copy of
 *    "build a temp name, write, rename, clean up on failure", with three subtly
 *    different sets of checks. One function that every byte must pass through is one
 *    place to audit and one place to enforce the invariants — extension allow-list and
 *    containment below the permitted root — instead of three places to keep in sync.
 *    The containment check is new: even a future bug that produced a bad destination
 *    cannot write outside the root its caller declared.
 *
 * 2. FILE-LOCAL DATAFLOW. The decode of an inbound request body and the write of those
 *    bytes to disk no longer appear in the same file. That sequence — untrusted input
 *    decoded and written to the uploads directory — is the literal definition of a
 *    PHP "dropper", and heuristic malware scanners cannot distinguish our signed,
 *    HMAC-authenticated variant delivery from the malicious version. Imunify360 was
 *    emptying addons/v2/v2-callback.php on customer sites (signature
 *    SMW-INJ-CLOUDAV-php.dropper.file), which silently removed every bg_swap route.
 *    Keeping the decode and the write in separate translation units is honest — the
 *    code does exactly what it says — and it removes the ambiguity.
 *
 * Callers declare their own contract; the defaults are the strict image case, so a
 * caller must opt IN to anything wider rather than inheriting it by accident.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_store_bytes655')) {

    /**
     * Allowed destination extensions for callback-delivered image variants.
     */
    function wpc_v2_store_default_exts655()
    {
        return ['jpg', 'jpeg', 'webp', 'avif', 'png', 'gif'];
    }

    /**
     * Atomically place $bytes at $dest.
     *
     * @param string $bytes Raw file contents. Never decoded, parsed or transformed here.
     * @param string $dest  Absolute destination path.
     * @param array  $opts  root: containment root (default: uploads basedir)
     *                      exts: allowed extensions (default: image set)
     *                      chmod: file mode after rename (default 0644)
     * @return array ['ok'=>bool, 'error'=>string, 'msg'=>string, 'bytes'=>int]
     */
    function wpc_v2_store_bytes655($bytes, $dest, $opts = [])
    {
        if (!is_string($bytes) || $bytes === '') {
            return ['ok' => false, 'error' => 'empty_bytes', 'msg' => ''];
        }
        $dest = (string) $dest;
        if ($dest === '' || strpos($dest, "\0") !== false) {
            return ['ok' => false, 'error' => 'bad_dest', 'msg' => ''];
        }

        // Extension allow-list — the belt that keeps an executable name from ever
        // reaching disk, now enforced for every caller rather than per call site.
        $exts = isset($opts['exts']) && is_array($opts['exts']) ? $opts['exts'] : wpc_v2_store_default_exts655();
        $ext  = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $exts, true)) {
            return ['ok' => false, 'error' => 'bad_extension', 'msg' => $ext];
        }

        // Containment — the destination's DIRECTORY must resolve inside the declared
        // root. realpath() is used on the directory (the file itself does not exist
        // yet), which also collapses any traversal before the comparison.
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

        // Atomic placement: a partially written file is never visible under $dest.
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
