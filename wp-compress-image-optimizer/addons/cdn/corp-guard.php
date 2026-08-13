<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * v7.10.823 — CORP guard. auktion-orebro: the origin stamped every response — images included —
 * with Cross-Origin-Resource-Policy: same-origin (blanket security config). The CDN pull replays
 * origin headers verbatim, the zone is a different origin, so Chrome blocked every passthrough
 * image with ERR_BLOCKED_BY_RESPONSE.NotSameOrigin. Optimizer-minted variants (our own headers,
 * no CORP) loaded fine, which made it look lazy-vs-eager.
 *
 * The guard probes ONE origin uploads image per tick (cache-busted, so an origin-front cache can
 * never hide the live header), and when the CORP header would block cross-origin replay it writes
 * a marker-fenced override into uploads/.htaccess scoped to image extensions, re-probes to verify
 * the override actually took (nginx ignores .htaccess — that outcome is journaled as ineffective,
 * never retried hot), and purges the receipt URL through the customer-purge pipe once on the
 * not-armed -> armed flip. Zero render-path work; one or two HEAD requests per 6h at most.
 */

if (!function_exists('wpc_corp_guard_active')) {
    function wpc_corp_guard_active()
    {
        $on = get_option('wpc_corp_guard_on', 1);
        if (!apply_filters('wpc_corp_guard', !empty($on))) { return false; }
        $wpc_s823 = get_option(WPS_IC_SETTINGS);
        if (!is_array($wpc_s823) || empty($wpc_s823['live-cdn']) || (string) $wpc_s823['live-cdn'] !== '1') { return false; }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) { return false; }
        return true;
    }
}

if (!function_exists('wpc_corp_guard_rules')) {
    function wpc_corp_guard_rules()
    {
        return [
            '<IfModule mod_headers.c>',
            '<FilesMatch "\.(?i:avif|bmp|gif|ico|jpe?g|png|svg|webp)$">',
            'Header always set Cross-Origin-Resource-Policy "cross-origin"',
            '</FilesMatch>',
            '</IfModule>',
        ];
    }
}

if (!function_exists('wpc_corp_guard_sample_url')) {
    function wpc_corp_guard_sample_url()
    {
        global $wpdb;
        if (!isset($wpdb)) { return ''; }
        $rows = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' ORDER BY meta_id DESC LIMIT 20");
        if (empty($rows)) { return ''; }
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
        $base = (is_array($up) && !empty($up['baseurl'])) ? (string) $up['baseurl'] : '';
        if ($base === '') { return ''; }
        foreach ($rows as $rel) {
            $rel = (string) $rel;
            if (preg_match('/\.(?:jpe?g|png|gif|webp|avif|bmp)$/i', $rel)) {
                return rtrim($base, '/') . '/' . ltrim($rel, '/');
            }
        }
        return '';
    }
}

if (!function_exists('wpc_corp_probe_header')) {
    /** Live origin CORP header for $url, cache-busted so a front cache never answers for it. */
    function wpc_corp_probe_header($url, $bust)
    {
        if (!function_exists('wp_remote_head')) { return null; }
        $probe = $url . ((strpos($url, '?') === false) ? '?' : '&') . 'wpcgp=' . rawurlencode((string) $bust);
        $resp = wp_remote_head($probe, ['timeout' => 5, 'redirection' => 2, 'sslverify' => false]);
        if (function_exists('is_wp_error') && is_wp_error($resp)) { return null; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 400) { return null; }
        return strtolower(trim((string) wp_remote_retrieve_header($resp, 'cross-origin-resource-policy')));
    }
}

if (!function_exists('wpc_corp_guard_write_block')) {
    function wpc_corp_guard_write_block()
    {
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
        $dir = (is_array($up) && !empty($up['basedir'])) ? (string) $up['basedir'] : '';
        if ($dir === '' || !@is_dir($dir)) { return false; }
        $file = rtrim($dir, '/\\') . '/.htaccess';
        if (@file_exists($file) ? !@is_writable($file) : !@is_writable($dir)) { return false; }
        if (!function_exists('insert_with_markers')) {
            $wpc_misc823 = ABSPATH . 'wp-admin/includes/misc.php';
            if (@is_readable($wpc_misc823)) { require_once $wpc_misc823; }
        }
        if (!function_exists('insert_with_markers')) { return false; }
        return (bool) insert_with_markers($file, 'WPC CORP Guard', wpc_corp_guard_rules());
    }
}

if (!function_exists('wpc_corp_guard_tick')) {
    function wpc_corp_guard_tick($force = false)
    {
        try {
            if (!wpc_corp_guard_active()) { return null; }
            if (!$force && get_transient('wpc_corp_guard_tick')) { return null; }
            set_transient('wpc_corp_guard_tick', 1, 6 * HOUR_IN_SECONDS);

            $url = wpc_corp_guard_sample_url();
            if ($url === '') { return null; }
            $st = get_option('wpc_corp_guard_state', []);
            $prev = (is_array($st) && isset($st['state'])) ? (string) $st['state'] : '';

            $corp = wpc_corp_probe_header($url, 'a' . time());
            if ($corp === null) { return null; }
            // A pull replayed cross-origin is blocked by same-origin always, and by same-site
            // whenever the zone is not a sibling of the site (every *.zapwp.com zone).
            $hazard = in_array($corp, ['same-origin', 'same-site'], true);

            if (!$hazard) {
                update_option('wpc_corp_guard_state', ['state' => 'healthy', 'corp' => $corp, 'url' => $url, 'ts' => time()], false);
                if ($prev !== '' && $prev !== 'healthy' && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('corp-guard-healthy', '', '', ['corp' => $corp]);
                }
                return 'healthy';
            }

            $wrote = wpc_corp_guard_write_block();
            $after = $wrote ? wpc_corp_probe_header($url, 'b' . time()) : $corp;
            $effective = ($wrote && $after !== null && !in_array($after, ['same-origin', 'same-site'], true));

            if ($effective) {
                update_option('wpc_corp_guard_state', ['state' => 'active', 'corp' => $corp, 'after' => (string) $after, 'url' => $url, 'ts' => time()], false);
                if ($prev !== 'active') {
                    if (function_exists('wpc_purge_compat')) {
                        wpc_purge_compat('urls', [$url], 'corp_guard', '', false);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('corp-guard-armed', '', '', ['corp' => $corp, 'after' => (string) $after]);
                    }
                }
                return 'active';
            }

            update_option('wpc_corp_guard_state', ['state' => 'ineffective', 'corp' => $corp, 'wrote' => $wrote ? 1 : 0, 'url' => $url, 'ts' => time()], false);
            set_transient('wpc_corp_guard_tick', 1, 7 * DAY_IN_SECONDS);
            if ($prev !== 'ineffective' && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('corp-guard-ineffective', '', '', ['corp' => $corp, 'wrote' => $wrote ? 1 : 0]);
            }
            return 'ineffective';
        } catch (\Throwable $e) {
            return null;
        }
    }
}

add_action('wpc_natural_converge_hook', 'wpc_corp_guard_tick');
