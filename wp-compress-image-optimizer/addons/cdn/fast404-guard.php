<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * v7.20.18 — uploads fast-404. A request for a missing image under uploads costs a full WP
 * boot before the PHP-level 404 guards answer it; on Apache/LiteSpeed a marker-fenced rule in
 * uploads/.htaccess answers at the server instead. The -WxH rung shape is carved out so those
 * requests still reach WP for wpc_v2_rung_intercept (nearest-rung serve + regen queue). The
 * write is verified by probing a known-missing URL: a 404 WITHOUT the X-WPC-Fast-404 header
 * proves the server answered before PHP; the header present means the rule is ineffective
 * (nginx and friends) — journaled, never retried hot.
 */

if (!function_exists('wpc_fast404_guard_active')) {
    function wpc_fast404_guard_active()
    {
        $on = get_option('wpc_fast404_on', 1);
        if (!apply_filters('wpc_uploads_fast404', !empty($on))) { return false; }
        $srv = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
        if ($srv !== '' && strpos($srv, 'apache') === false && strpos($srv, 'litespeed') === false) { return false; }
        return true;
    }
}

if (!function_exists('wpc_fast404_guard_rules')) {
    function wpc_fast404_guard_rules()
    {
        return [
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_URI} !-\d+x\d+\.(?:avif|webp|jpe?g|png)$ [NC]',
            'RewriteRule \.(?:avif|webp|jpe?g|png|gif|bmp|ico)$ - [R=404,L]',
            '</IfModule>',
        ];
    }
}

if (!function_exists('wpc_fast404_guard_write_block')) {
    function wpc_fast404_guard_write_block($remove = false)
    {
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
        $dir = (is_array($up) && !empty($up['basedir'])) ? (string) $up['basedir'] : '';
        if ($dir === '' || !@is_dir($dir)) { return false; }
        $file = rtrim($dir, '/\\') . '/.htaccess';
        if (@file_exists($file) ? !@is_writable($file) : !@is_writable($dir)) { return false; }
        if (!function_exists('insert_with_markers')) {
            $wpc_misc18 = ABSPATH . 'wp-admin/includes/misc.php';
            if (@is_readable($wpc_misc18)) { require_once $wpc_misc18; }
        }
        if (!function_exists('insert_with_markers')) { return false; }
        return (bool) insert_with_markers($file, 'WPC Fast 404', $remove ? [] : wpc_fast404_guard_rules());
    }
}

if (!function_exists('wpc_fast404_guard_probe')) {
    /** 404 without our PHP header = the server answered pre-WP. Returns 'static'|'php'|'other'. */
    function wpc_fast404_guard_probe()
    {
        if (!function_exists('wp_remote_get')) { return 'other'; }
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
        $base = (is_array($up) && !empty($up['baseurl'])) ? (string) $up['baseurl'] : '';
        if ($base === '') { return 'other'; }
        $probe = rtrim($base, '/') . '/wpc-f404-' . substr(md5((string) microtime(true)), 0, 10) . '.png';
        $resp = wp_remote_get($probe, ['timeout' => 5, 'redirection' => 0, 'sslverify' => false, 'limit_response_size' => 2048]);
        if (function_exists('is_wp_error') && is_wp_error($resp)) { return 'other'; }
        if ((int) wp_remote_retrieve_response_code($resp) !== 404) { return 'other'; }
        $hdr = (string) wp_remote_retrieve_header($resp, 'x-wpc-fast-404');
        return $hdr === '' ? 'static' : 'php';
    }
}

if (!function_exists('wpc_fast404_guard_tick')) {
    function wpc_fast404_guard_tick($force = false)
    {
        try {
            if (!wpc_fast404_guard_active()) { return null; }
            if (!$force && get_transient('wpc_fast404_tick')) { return null; }
            set_transient('wpc_fast404_tick', 1, 6 * HOUR_IN_SECONDS);

            $st = get_option('wpc_fast404_state', []);
            $prev = (is_array($st) && isset($st['state'])) ? (string) $st['state'] : '';
            if ($prev === 'ineffective' && !$force) { return null; }
            if ($prev === 'armed' && !$force) { return null; }

            if (!wpc_fast404_guard_write_block()) {
                update_option('wpc_fast404_state', ['state' => 'unwritable', 'ts' => time()], false);
                return false;
            }
            $verdict = wpc_fast404_guard_probe();
            if ($verdict === 'static') {
                update_option('wpc_fast404_state', ['state' => 'armed', 'ts' => time()], false);
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('fast404-armed', '', '', []); }
                return true;
            }
            if ($verdict === 'php') {
                // Rule written but PHP still answers (nginx-class front) — leave the block
                // (harmless), journal ineffective, back off a week like the CORP guard.
                update_option('wpc_fast404_state', ['state' => 'ineffective', 'ts' => time()], false);
                set_transient('wpc_fast404_tick', 1, 7 * DAY_IN_SECONDS);
                return false;
            }
            update_option('wpc_fast404_state', ['state' => 'unverified', 'ts' => time()], false);
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }
    add_action('wpc_natural_converge_hook', 'wpc_fast404_guard_tick');
}
