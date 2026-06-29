<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * (v7.03.88) NO-CACHE WHILE THE LCP/ATF HINT IS PENDING.
 *
 * The race the crit team mapped: crit exists for a URL, but its .lcp.json lands ~28s AFTER the regen.
 * In that window the rendered page is hint-LESS. If a shared cache stores that hint-less render under the
 * page's long s-maxage (the host's Varnish here, a Bunny edge on other customers, or the WPC/3rd-party
 * page cache), two bad things happen: (1) anon visitors are pinned to the un-optimized page for the full
 * TTL, and (2) renders STOP flowing, so the inline healer (rewriteLogic.php) never gets another miss to
 * capture the file on. So we suppress caching for EXACTLY that pending window — origin-controlled, which
 * every well-behaved shared cache respects — and revert to normal caching the instant the file's on disk.
 *
 * Universal by construction: we don't touch the host's s-maxage directive (we don't emit it — Varnish/
 * Cloudways does). We emit `no-store` (every shared cache honours it at the origin) AND define
 * DONOTCACHEPAGE (WPC's own page cache honours it — cacheHtml.php:180/318 — as do WP-Rocket/W3TC/etc).
 *
 * Inert on non-LCP sites (no crit → bail on the first file_exists) and the moment the hint lands.
 */
add_action('send_headers', function () {
    // front-end anonymous GET only — admin/REST/cron and logged-in already bypass page caches
    if (is_admin()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (defined('DOING_CRON') && DOING_CRON)
        || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
        return;
    }
    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
        return;
    }
    if (headers_sent() || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
        return;
    }

    // Resolve THIS request's crit dir (same derivation the healer + reader use).
    $url = (is_ssl() ? 'https://' : 'http://')
        . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
        . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
    $key = (new wps_ic_url_key())->setup($url);
    if ($key === '') {
        return;
    }
    $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/';

    // PENDING = crit exists, lcp.json NOT yet on disk, a lcp_url IS stashed (so it's genuinely coming),
    // and we haven't given up. Any other state → leave caching alone.
    // (v7.03.91) CRIT-REGEN-PENDING: crit was just purged and is regenerating. A crit-LESS render must NOT
    // get cached (Varnish/edge/WPC/3rd-party) or the HIT walls the regen — WP never re-runs, so crit never
    // comes back. Suppress storage while the crit-purge's flag is live (bounded TTL → self-clears, so a
    // genuinely non-crit page isn't held no-cache forever). Layer-agnostic: origin no-store is honored at
    // every shared cache, so this converges interior pages the homepage-only Varnish purge can't reach.
    if (!@file_exists($dir . 'critical_desktop.css')) {
        $wpc_crit_pending = function_exists('get_transient') && (
            get_transient('wpc_crit_regen_pending')        // a crit purge just happened (site-wide flag)
            || get_transient('wpc_critical_key_' . $key)   // crit-gen IN FLIGHT for this url — path-independent:
        );                                                 // catches ANY crit-clear once a visit re-triggers gen
        if ($wpc_crit_pending) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
            header('Pragma: no-cache');
        }
        return;                                   // no crit for this URL → nothing else pending
    }
    if (@is_readable($dir . 'lcp.json')) {
        return;                                   // hint already landed → normal caching
    }
    if (!@is_readable($dir . 'lcp_url.txt')) {
        return;                                   // no hint is coming → don't suppress caching
    }
    $lcp_url = trim((string) @file_get_contents($dir . 'lcp_url.txt'));
    if ($lcp_url === '') {
        return;
    }
    if (function_exists('get_transient') && (int) get_transient('wpc_lcp_healn_' . md5($lcp_url)) >= 15) {
        return;                                   // healer gave up → let the page cache normally
    }

    // PENDING → suppress storage at every layer for THIS render only.
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);           // WPC page cache + WP-Rocket/W3TC/WP-Super-Cache/etc
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
    header('Pragma: no-cache');
}, 0);
