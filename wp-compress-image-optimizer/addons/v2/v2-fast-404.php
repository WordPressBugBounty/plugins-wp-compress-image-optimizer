<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-fast-404.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_fast404_enabled')) {
    function wpc_v2_fast404_enabled()
    {
        if (defined('WPC_FAST404_OFF') && WPC_FAST404_OFF) {
            return false;
        }
        return (bool) apply_filters('wpc_fast404_enabled', true);
    }
}

if (!function_exists('wpc_v2_fast404_file')) {
    function wpc_v2_fast404_file()
    {
        return defined('WPMU_PLUGIN_DIR') ? rtrim(WPMU_PLUGIN_DIR, '/\\') . '/wpc-fast-404.php' : '';
    }
}

if (!function_exists('wpc_v2_fast404_body')) {

    function wpc_v2_fast404_body($ver)
    {
        $v   = preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $ver);
        $tpl = <<<'PHP'
<?php
/**
 * Plugin Name: WP Compress - Fast 404 (auto-managed, do not edit)
 * Description: Instant 404 for missing image files (any docroot path: uploads, /storage, etc.) before
 *   the theme/query/template boot, so a CDN cold-probe storm cannot saturate PHP-FPM. Auto-written +
 *   removed by WP Compress. Kill: define('WPC_FAST404_OFF', true) / filter wpc_fast404_enabled.
 * Version: __VER__
 */
if (!defined('ABSPATH')) { return; }
if (defined('WPC_FAST404_OFF') && WPC_FAST404_OFF) { return; }
(static function () {
    if (PHP_SAPI === 'cli') { return; }
    $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($req === '') { return; }
    $path = parse_url($req, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path[0] !== '/') { return; }
    if (!preg_match('~\.(avif|webp|jpe?g|png|gif|svg|ico|bmp|tiff?)$~i', $path)) { return; }
    $rel = rawurldecode($path);
    if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) { return; }
    $abs = rtrim(ABSPATH, '/\\') . '/' . ltrim($rel, '/');
    $dir = dirname($abs);
    if ($dir === '' || !is_dir($dir)) { return; }   // parent must be a real dir -> never false-404 (virtual/dynamic endpoints, foreign/relocated paths fall through to WP)
    if (@is_file($abs)) { return; }                  // exists -> let the normal path serve it
    // v7.21.273 -- a missing NEXT-GEN VARIANT with an on-disk source is a redirect, not a 404:
    // css/crit mint bare .avif urls while disk holds sized rungs or only the source (columbus
    // Section-6-bg.avif?src=png -> our own fast-404 answered while Section-6-bg.png sat beside
    // it). src= names the source; unhinted falls down jpg/jpeg/png. Bounded: two stat calls max
    // per ext, only for avif/webp, only when the parent dir is real.
    if (preg_match('~\.(avif|webp)$~i', $rel, $xm273)) {
        $stemA273 = preg_replace('~\.(avif|webp)$~i', '', $abs);
        $relA273  = preg_replace('~\.(avif|webp)$~i', '', $path);
        // Nearest-rung first: disk holds sized {base}-WxH twins (AVIF naming law), never the
        // bare full -- serve the largest same-format rung (19KB avif beats a 455KB png source).
        $best273 = ''; $bw273 = 0;
        foreach ((array) @glob($stemA273 . '-*.' . strtolower($xm273[1])) as $c273) {
            if (preg_match('~-(\d+)x\d+\.' . strtolower($xm273[1]) . '$~i', (string) $c273, $wm273) && (int) $wm273[1] > $bw273) {
                $bw273 = (int) $wm273[1]; $best273 = (string) $c273;
            }
        }
        if ($best273 !== '') {
            // v7.21.274 -- serve the rung's bytes DIRECTLY (200): no redirect hop, and the
            // response is cacheable under the requested URL, so edges/browsers absorb the
            // cost. Never materialized to disk: a bare copy would be an orphan the optimizer
            // does not manage (stale after re-optimization). ETag from mtime-size so a
            // regenerated rung revalidates.
            $sz274 = (int) @filesize($best273);
            if ($sz274 > 0) {
                if (!headers_sent()) {
                    $proto274 = (isset($_SERVER['SERVER_PROTOCOL']) && strpos((string) $_SERVER['SERVER_PROTOCOL'], 'HTTP/') === 0) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
                    $et274 = '"' . dechex((int) @filemtime($best273)) . '-' . dechex($sz274) . '"';
                    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $et274) {
                        header($proto274 . ' 304 Not Modified', true, 304);
                        header('ETag: ' . $et274);
                        exit;
                    }
                    header($proto274 . ' 200 OK', true, 200);
                    header('Content-Type: image/' . strtolower($xm273[1]));
                    header('Content-Length: ' . $sz274);
                    header('ETag: ' . $et274);
                    header('Cache-Control: public, max-age=86400');
                    header('X-WPC-Fast-404: rung');
                }
                @readfile($best273);
                exit;
            }
        }
        $q273 = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $exts273 = array();
        if (preg_match('~(?:^|&)src=(jpe?g|png|gif)(?:&|$)~i', $q273, $m273)) { $exts273[] = strtolower($m273[1]); }
        foreach (array('jpg', 'jpeg', 'png') as $e273) { if (!in_array($e273, $exts273, true)) { $exts273[] = $e273; } }
        $stem273 = preg_replace('~\.(avif|webp)$~i', '', $abs);
        $relStem273 = preg_replace('~\.(avif|webp)$~i', '', $path);
        foreach ($exts273 as $e273) {
            if (@is_file($stem273 . '.' . $e273)) {
                if (!headers_sent()) {
                    $proto273 = (isset($_SERVER['SERVER_PROTOCOL']) && strpos((string) $_SERVER['SERVER_PROTOCOL'], 'HTTP/') === 0) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
                    header($proto273 . ' 302 Found', true, 302);
                    header('Location: ' . $relStem273 . '.' . $e273);
                    header('X-WPC-Fast-404: 302');
                    header('Cache-Control: no-store, max-age=0');
                }
                exit;
            }
        }
    }
    if (!headers_sent()) {
        $proto = (isset($_SERVER['SERVER_PROTOCOL']) && strpos((string) $_SERVER['SERVER_PROTOCOL'], 'HTTP/') === 0) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($proto . ' 404 Not Found', true, 404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-WPC-Fast-404: 1');
        header('Cache-Control: no-store, max-age=0');
    }
    echo 'Not Found';
    exit;
})();
PHP;
        return str_replace('__VER__', $v, $tpl);
    }
}

if (!function_exists('wpc_v2_fast404_remove')) {
    function wpc_v2_fast404_remove()
    {
        $file = wpc_v2_fast404_file();
        if ($file !== '' && @is_file($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('wpc_v2_fast404_sync')) {
    



    function wpc_v2_fast404_sync()
    {
        $file = wpc_v2_fast404_file();
        if ($file === '') {
            return;
        }
        if (!wpc_v2_fast404_enabled()) {
            wpc_v2_fast404_remove();
            return;
        }
        $ver  = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1';
        $body = wpc_v2_fast404_body($ver);

        $existing = @is_file($file) ? (string) @file_get_contents($file) : '';
        if ($existing === $body) {
            
            
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($file, $body, LOCK_EX);
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        }
    }
}



add_action('admin_init', 'wpc_v2_fast404_sync');
if (defined('WPC_CC_PLUGIN_FILE')) {
    register_activation_hook(WPC_CC_PLUGIN_FILE, 'wpc_v2_fast404_sync');
    register_deactivation_hook(WPC_CC_PLUGIN_FILE, 'wpc_v2_fast404_remove');
}
