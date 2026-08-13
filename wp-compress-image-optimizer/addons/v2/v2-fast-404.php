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
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($file, $body, LOCK_EX);
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
