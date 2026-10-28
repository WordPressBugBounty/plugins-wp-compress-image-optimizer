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
            '# wpc-mvf-302u (.273): a missing next-gen variant with an on-disk source is a redirect, not a 404',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{QUERY_STRING} (?:^|&)src=(jpe?g|png|gif)(?:&|$)',
            'RewriteRule ^(.+)\.(?:avif|webp)$ $1.%1? [R=302,L]',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_URI} !-\d+x\d+\.(?:avif|webp|jpe?g|png)$ [NC]',
            'RewriteRule \.(?:avif|webp|jpe?g|png|gif|bmp|ico)$ - [R=404,L]',
            '</IfModule>',
        ];
    }
}

if (!function_exists('wpc_fast404_bundle_rules')) {
    /**
     * The combined parked-CSS bundles (critical/combined/cmb-<md5>.css) are written only by a page
     * render, never on request, so a request for a name that is not on disk is a stale link and
     * can be answered by the server. Observed failure (ticket 12239, lifetimeglassmirror.com,
     * 2026-10-12 12:10 UTC): LiteSpeed kept serving pages that linked bundles our hard purge had
     * deleted, and every one of those links booted WordPress for 4-5 s (slow-render receipts) and
     * was logged by Hide My WP as a probe. RewriteEngine On in this directory means the root
     * rules of WordPress do not reach it: a name on disk is served as a file, a missing one is a
     * server 404. Apache and LiteSpeed only (OpenLiteSpeed reads .htaccess at restart).
     */
    function wpc_fast404_bundle_rules()
    {
        return [
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteRule \.css$ - [R=404,L]',
            '</IfModule>',
        ];
    }
}

if (!function_exists('wpc_fast404_bundle_rules_write')) {
    /**
     * Writes the block into critical/combined/.htaccess under the fast-404 marker. Returns true
     * when written, false when the directory is missing or not writable, null when there is
     * nothing to do (guard off, another server class, or the block is already there).
     */
    function wpc_fast404_bundle_rules_write()
    {
        if (!function_exists('wpc_fast404_guard_active') || !wpc_fast404_guard_active()) { return null; }
        if (!defined('WPS_IC_CRITICAL')) { return null; }
        $dir = rtrim(WPS_IC_CRITICAL, '/\\') . '/combined';
        if (!@is_dir($dir)) { return false; }
        $file = $dir . '/.htaccess';
        if (@is_file($file) && strpos((string) @file_get_contents($file), '# BEGIN WPC Fast 404') !== false) { return null; }
        if (@file_exists($file) ? !@is_writable($file) : !@is_writable($dir)) { return false; }
        if (!function_exists('insert_with_markers')) {
            $misc_file = ABSPATH . 'wp-admin/includes/misc.php';
            if (@is_readable($misc_file)) { require_once $misc_file; }
        }
        if (!function_exists('insert_with_markers')) { return false; }
        $written = (bool) insert_with_markers($file, 'WPC Fast 404', wpc_fast404_bundle_rules());
        if ($written && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('fast404-bundle-rule', '', '', []);
        }
        return $written;
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
            $misc_file = ABSPATH . 'wp-admin/includes/misc.php';
            if (@is_readable($misc_file)) { require_once $misc_file; }
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
            // The bundle directory's own rule, independent of the uploads block below: it is
            // written once and survives until a hard purge removes the directory, after which
            // the render that recreates the directory writes it again.
            if (function_exists('wpc_fast404_bundle_rules_write')) { wpc_fast404_bundle_rules_write(); }
            if (!wpc_fast404_guard_active()) { return null; }
            if (!$force && get_transient('wpc_fast404_tick')) { return null; }
            set_transient('wpc_fast404_tick', 1, 6 * HOUR_IN_SECONDS);

            $st = get_option('wpc_fast404_state', []);
            $prev = (is_array($st) && isset($st['state'])) ? (string) $st['state'] : '';
            // .273 — rules are versioned: an armed block from an older ruleset re-writes once.
            $rules_version = md5(implode("\n", wpc_fast404_guard_rules()));
            $same_rules = (is_array($st) && isset($st['rules_v']) && $st['rules_v'] === $rules_version);
            if ($prev === 'ineffective' && !$force) { return null; }
            if ($prev === 'armed' && $same_rules && !$force) { return null; }

            if (!wpc_fast404_guard_write_block()) {
                update_option('wpc_fast404_state', ['state' => 'unwritable', 'ts' => time()], false);
                return false;
            }
            $verdict = wpc_fast404_guard_probe();
            if ($verdict === 'static') {
                update_option('wpc_fast404_state', ['state' => 'armed', 'rules_v' => $rules_version, 'ts' => time()], false);
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
