<?php


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
    if (preg_match('~\.(avif|webp)$~i', $rel, $variantExtMatch)) {
        $rungStem = preg_replace('~\.(avif|webp)$~i', '', $abs);
        // Nearest-rung first: disk holds sized {base}-WxH twins (AVIF naming law), never the
        // bare full -- serve the largest same-format rung (19KB avif beats a 455KB png source).
        $bestRung = ''; $bestRungWidth = 0;
        foreach ((array) @glob($rungStem . '-*.' . strtolower($variantExtMatch[1])) as $candidate) {
            if (preg_match('~-(\d+)x\d+\.' . strtolower($variantExtMatch[1]) . '$~i', (string) $candidate, $widthMatch) && (int) $widthMatch[1] > $bestRungWidth) {
                $bestRungWidth = (int) $widthMatch[1]; $bestRung = (string) $candidate;
            }
        }
        if ($bestRung !== '') {
            // v7.21.274 -- serve the rung's bytes DIRECTLY (200): no redirect hop, and the
            // response is cacheable under the requested URL, so edges/browsers absorb the
            // cost. Never materialized to disk: a bare copy would be an orphan the optimizer
            // does not manage (stale after re-optimization). ETag from mtime-size so a
            // regenerated rung revalidates.
            $rungSize = (int) @filesize($bestRung);
            if ($rungSize > 0) {
                if (!headers_sent()) {
                    $rungProto = (isset($_SERVER['SERVER_PROTOCOL']) && strpos((string) $_SERVER['SERVER_PROTOCOL'], 'HTTP/') === 0) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
                    $etag = '"' . dechex((int) @filemtime($bestRung)) . '-' . dechex($rungSize) . '"';
                    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                        header($rungProto . ' 304 Not Modified', true, 304);
                        header('ETag: ' . $etag);
                        exit;
                    }
                    header($rungProto . ' 200 OK', true, 200);
                    header('Content-Type: image/' . strtolower($variantExtMatch[1]));
                    header('Content-Length: ' . $rungSize);
                    header('ETag: ' . $etag);
                    header('Cache-Control: public, max-age=86400');
                    header('X-WPC-Fast-404: rung');
                }
                @readfile($bestRung);
                exit;
            }
        }
        $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $sourceExts = array();
        if (preg_match('~(?:^|&)src=(jpe?g|png|gif)(?:&|$)~i', $query, $srcMatch)) { $sourceExts[] = strtolower($srcMatch[1]); }
        foreach (array('jpg', 'jpeg', 'png') as $ext) { if (!in_array($ext, $sourceExts, true)) { $sourceExts[] = $ext; } }
        $sourceStem = preg_replace('~\.(avif|webp)$~i', '', $abs);
        $relSourceStem = preg_replace('~\.(avif|webp)$~i', '', $path);
        foreach ($sourceExts as $ext) {
            if (@is_file($sourceStem . '.' . $ext)) {
                if (!headers_sent()) {
                    $redirectProto = (isset($_SERVER['SERVER_PROTOCOL']) && strpos((string) $_SERVER['SERVER_PROTOCOL'], 'HTTP/') === 0) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
                    header($redirectProto . ' 302 Found', true, 302);
                    header('Location: ' . $relSourceStem . '.' . $ext);
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
    /**
     * Write/refresh the mu-plugin when missing or stale (version drift), or remove it when disabled.
     * Best-effort: a read-only mu-plugins dir just leaves the in-WP early-404 handler as the fallback.
     */
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
            // .277 — bytes current but the OPCODE may not be (validate_timestamps=0 hosts):
            // force-invalidate on every sync so a stale compile never outlives an update.
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            wpc_fs_put($file, $body, LOCK_EX);
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        }
    }
}

// Self-install / keep-fresh on admin loads (re-writes on version drift) + on activation; remove on
// deactivation. All guarded + best-effort.
add_action('admin_init', 'wpc_v2_fast404_sync');
if (defined('WPC_CC_PLUGIN_FILE')) {
    register_activation_hook(WPC_CC_PLUGIN_FILE, 'wpc_v2_fast404_sync');
    register_deactivation_hook(WPC_CC_PLUGIN_FILE, 'wpc_v2_fast404_remove');
}
