<?php
include_once __DIR__ . '/wpc-fs.php';
define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');

// Drop-in loads before the plugin, so these are defined in BOTH places under !function_exists —
// whichever loads first wins and the two HTML writers can never drift apart.
if (!function_exists('wpc_edge_swr')) {
    // Default matches the s-maxage branch's 86400. Shipping 0 made .488 inert: line 25 reads
    // must-revalidate whenever swr is 0, so the fix was present and off.
    function wpc_edge_swr()
    {
        try {
            return max(0, (int) apply_filters('wpc_html_swr', 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_cc_freshness')) {
    function wpc_cc_freshness($maxAge, $sMaxAge, $swr)
    {
        $cc = 'public, max-age=' . (int) $maxAge;
        if ((int) $sMaxAge > 0) {
            return $cc . ', s-maxage=' . (int) $sMaxAge . ', stale-while-revalidate=86400';
        }
        return $cc . ((int) $swr > 0 ? ', stale-while-revalidate=' . (int) $swr : ', must-revalidate');
    }
}

if (!function_exists('wpc_stale_serve_plan')) {
    function wpc_stale_serve_plan($dir, $prefix = '')
    {
        try {
            $dir = rtrim((string) $dir, '/') . '/';
            if (!@is_file($dir . $prefix . 'index.html') && !@is_file($dir . $prefix . 'index.html_gzip')) {
                $stale_mtime = 0;
                foreach (['stale.html_gzip', 'stale.html'] as $stale_file) {
                    $stale_file_mtime = (int) @filemtime($dir . $prefix . $stale_file);
                    if ($stale_file_mtime > $stale_mtime) {
                        $stale_mtime = $stale_file_mtime;
                    }
                }
                if ($stale_mtime > 0) {
                    $stale_max_age = defined('WPC_STALE_SERVE_MAX') ? (int) WPC_STALE_SERVE_MAX : 86400;
                    if ($stale_max_age > 0 && (time() - $stale_mtime) > $stale_max_age) {
                        return 'miss';
                    }
                    $stale_rewarm_file = $dir . $prefix . 'wpc-rewarm43.txt';
                    $stale_rewarm_count = (@is_file($stale_rewarm_file) && (int) @filemtime($stale_rewarm_file) >= $stale_mtime) ? (int) @file_get_contents($stale_rewarm_file) : 0;
                    if ($stale_rewarm_count >= 4) {
                        return 'miss';
                    }
                    return wpc_stale_assets_ok($dir, $prefix) ? 'stale' : 'miss';
                }
            }
            if (!defined('WPS_IC_CACHE')) {
                return 'fresh';
            }
            $stale_marker_file = rtrim(WPS_IC_CACHE, '/') . '/wpc-stale43.txt';
            if (!@is_file($stale_marker_file)) {
                return 'fresh';
            }
            $stale_epoch = (int) @file_get_contents($stale_marker_file);
            if ($stale_epoch <= 0) {
                return 'fresh';
            }
            $dir = rtrim((string) $dir, '/') . '/';
            $index_mtime = 0;
            foreach (['index.html_gzip', 'index.html'] as $index_file) {
                $index_file_mtime = (int) @filemtime($dir . $prefix . $index_file);
                if ($index_file_mtime > $index_mtime) {
                    $index_mtime = $index_file_mtime;
                }
            }
            if ($index_mtime === 0 || $index_mtime >= $stale_epoch) {
                return 'fresh';
            }
            $marked_max_age = defined('WPC_STALE_SERVE_MAX') ? (int) WPC_STALE_SERVE_MAX : 86400;
            if ($marked_max_age > 0 && (time() - $stale_epoch) > $marked_max_age) {
                return 'miss';
            }
            $rewarm_file = $dir . $prefix . 'wpc-rewarm43.txt';
            $rewarm_count = (@is_file($rewarm_file) && (int) @filemtime($rewarm_file) >= $stale_epoch) ? (int) @file_get_contents($rewarm_file) : 0;
            if ($rewarm_count >= 4) {
                return 'miss';
            }
            return wpc_stale_assets_ok($dir, $prefix) ? 'stale' : 'miss';
        } catch (\Throwable $e) {
            return 'fresh';
        }
    }

    function wpc_stale_assets_ok($dir, $prefix = '')
    {
        if (!function_exists('wpc_cache_p1_flag') || !wpc_cache_p1_flag()) {
            return true;
        }
        $missing = wpc_copy_assets_missing($dir, $prefix);
        if ($missing !== [] && function_exists('wpc_fs_log')) {
            wpc_fs_log('stale-assets-missing', basename(rtrim((string) $dir, '/')), ['variant' => (string) $prefix, 'missing' => $missing]);
        }
        return $missing === [];
    }

    function wpc_stale_unlink_copies($dir, $prefix = '')
    {
        $dir = rtrim((string) $dir, '/') . '/';
        if (defined('WPS_IC_CACHE') && function_exists('wpc_fs_link_below') && wpc_fs_link_below(WPS_IC_CACHE, $dir)) {
            return;
        }
        foreach (['index.html_br', 'index.html_gzip', 'index.html', 'index.html_md5', 'stale.html_br', 'stale.html_gzip', 'stale.html', 'wpc-rewarm43.txt', 'links.txt', 'head.json'] as $copy_file) {
            @unlink($dir . $prefix . $copy_file);
        }
    }
}

class wps_advancedCache
{

    private $siteUrl;
    private $urlKey;
    private $cacheExists = false;
    private $cachedHtml = '';

    private $host;
    private $cachePath;
    private $userFolder = '';
    private $url_key_class;

    public function __construct()
    {
        if (!file_exists(WPS_IC_CACHE)) {
            mkdir(rtrim(WPS_IC_CACHE, '/'));
        }

        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();

	    $this->userFolder = wps_ic_url_key::userFolder();
	    $this->cachePath = WPS_IC_CACHE . $this->userFolder . $this->urlKey . wps_ic_url_key::cookieSuffix() . '/';


    }

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilderFE()
    {
        if (class_exists('BT_BB_Root')) {
            if (is_user_logged_in() && !is_admin()) {
                return true;
            }
        }

        return false;
    }

    public function init()
    {
        return '';
    }

    public function cacheEnabled()
    {
        return true;
    }

    public function cacheValid($prefix = '')
    {
        return true;

        $cacheFile = $this->cachePath . $prefix . 'index.html';

        if ((!file_exists($cacheFile) || filesize($cacheFile) <= 0) && (!file_exists($cacheFile . '_gzip') || filesize($cacheFile . '_gzip') <= 0)) {
            return false;
        }

        return true;
    }


    public function cacheExpired($prefix = '')
    {

        return false;

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        $cacheFile = $this->cachePath . $prefix . 'index.html';

        if (!file_exists($cacheFile . '_gzip') && !file_exists($cacheFile)) {
            return true;
        }

        // Hours into minutes into seconds
        $expireInterval = $this->options['cache']['expire'] * 60 * 60;
        $fileModifiedTime = filemtime($cacheFile);

        if ($fileModifiedTime + $expireInterval < time()) {
            unlink($cacheFile);
            return true;
        } else {
            return false;
        }
    }


    public function isWooFragments()
    {

        if (!empty($_GET['action']) && $_GET['action'] == 'get_wdtable') {
            return true;
        }

        if (isset($_GET['wc-ajax']) && $_GET['wc-ajax'] !== 'get_refreshed_fragments' ) {
            return true;
        }

        if ((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'wc-ajax=get_refreshed_fragments') !== false) ||
            (isset($_GET['wc-ajax']) && $_GET['wc-ajax'] === 'get_refreshed_fragments')) {
            return true;
        }

        return false;
    }


    public function byPass()
    {
        if (defined('WPC_NO_PAGE_CACHE') && WPC_NO_PAGE_CACHE) {
            return true;
        }

        if ((string) $this->urlKey === '') {
            return true;
        }

        if (wps_ic_url_key::userFolderDead()) {
            return true;
        }

        // A personal cookie (a WooCommerce cart, a password-protected post, a commenter) makes
        // the response that visitor's own: the drop-in serves no shared copy to it. The list is
        // wps_ic_url_key::personalCookieNames(), the same one the in-WordPress reader and the
        // store verdict ask; shoppers with items in the cart were served the cached empty-cart
        // page (rosella.com.co, anacletababy.com, babypinkworld.com). Without the owner class
        // loaded nothing can say the request is anonymous, so the drop-in serves nothing.
        if (!class_exists('wps_ic_url_key') || !method_exists('wps_ic_url_key', 'personalCookieMatch')
            || wps_ic_url_key::personalCookieMatch() !== '') {
            return true;
        }

        // Cart Fragments
        if ($this->isWooFragments()) {
            return true;
        }

        // Don't cache for specific WooCommerce pages or AJAX requests
        $excluded_pages = ['cart', 'checkout', 'my-account'];
        $request_uri = trim($_SERVER['REQUEST_URI']);
        $is_excluded_page = false;

        if (!empty($request_uri) && $request_uri !== '/') {
            $query_at = strpos($request_uri, '?');
            $path_part = $query_at === false ? $request_uri : substr($request_uri, 0, $query_at);
            $query_part = $query_at === false ? '' : substr($request_uri, $query_at);
            $is_excluded_page = preg_match('#(?:^|/)(?:cart|checkout|my-account)(?:/|$)#', $path_part) === 1;
            if (!$is_excluded_page) {
                foreach ($excluded_pages as $page) {
                    if (str_contains($query_part, $page)) {
                        $is_excluded_page = true;
                        break;
                    }
                }
            }
        }

        // Check for wc-ajax requests
        if ($is_excluded_page || str_contains($request_uri, 'wc-ajax')) {
            return true;
        }

        // Check mandatory cookies - bypass if any required cookie is missing
        $mandatoryCookies = wps_ic_url_key::mandatoryCookies();
        if ($mandatoryCookies) {
            foreach ($mandatoryCookies as $mandatoryCookie) {
                if (substr($mandatoryCookie, -1) === '_') {
                    $found = false;
                    foreach ($_COOKIE as $cookieName => $cookieValue) {
                        if (strpos($cookieName, $mandatoryCookie) === 0) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return true;
                    }
                } else {
                    if (!isset($_COOKIE[$mandatoryCookie])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }


    public function cacheExists($prefix = '')
    {
        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        if (wpc_warm_request()) {
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
            return false;
        }
        // The copy family is part of the file prefix from here on: every name below is the
        // device's local-only copy when that family is on disk.
        $prefix .= wpc_copy_family_on_disk($this->cachePath, $prefix);
        if (function_exists('wpc_stale_serve_plan') && wpc_stale_serve_plan($this->cachePath, $prefix) === 'miss') {
            wpc_stale_unlink_copies($this->cachePath, $prefix);
            return false;
        }
        foreach (['stale.html_gzip', 'stale.html'] as $staleFile) {
            if (@file_exists($this->cachePath . $prefix . $staleFile) && (int) @filesize($this->cachePath . $prefix . $staleFile) > 0) {
                return true;
            }
        }

        if (function_exists('gzencode')) {
            if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && filesize($this->cachePath . $prefix . 'index.html' . '_gzip') > 0) {
                return true;
            }
        }

        if (file_exists($this->cachePath . $prefix . 'index.html') && filesize($this->cachePath . $prefix . 'index.html') > 0) {
            return true;
        }

        return false;
    }


    public function getCache($prefix = '')
    {


        if (wpc_warm_request()) {
            return;
        }

        // Path-keyed mirror: never serve it for query URLs beyond marketing params.
        // v7.10.598 — same predicate as the WRITE gate (cacheHtml::saveCache), so read and write
        // cannot disagree about which query strings are cacheable. This file is include_once'd by
        // the advanced-cache drop-in (advancedCacheSample.php:51) alongside traits/url_key.php,
        // so both gates and the strip list are one deployable unit — no baked copy to drift.
        $queryString = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($queryString !== '') {
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'queryIsCacheable')) {
                if (!wps_ic_url_key::queryIsCacheable($queryString)) {
                    return;
                }
            } else {
                parse_str($queryString, $queryParams);
                foreach (array_keys((array) $queryParams) as $queryKey) {
                    $queryKey = strtolower((string) $queryKey);
                    if (strpos($queryKey, 'utm_') !== 0
                        && !in_array($queryKey, ['fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', '_ga', 'igshid', 'ttclid'], true)) {
                        return;
                    }
                }
            }
        }


        try {
            // The cron heartbeat asks wp-cron.php of THIS install: wpc_loopback_install_url() keeps a
            // subdirectory install's prefix. It was `GET /wp-cron.php` on the request's host, which on
            // a subdirectory install is not this install's cron (for noktaltema.com/tibet/teknikservis/
            // it asked noktaltema.com/wp-cron.php; found by reading, 2026-09-24).
            $cronHeartbeatStamp = $this->cachePath ? dirname(rtrim($this->cachePath, '/')) . '/wpc-cron-heartbeat.stamp' : '';
            if ($cronHeartbeatStamp && (!@file_exists($cronHeartbeatStamp) || (time() - (int) @filemtime($cronHeartbeatStamp)) > 60)) {
                @touch($cronHeartbeatStamp);
                $cronTarget = wpc_loopback_target(wpc_loopback_install_url('wp-cron.php') . '?doing_wp_cron');
                if ($cronTarget !== [] && strpos($cronTarget['host'], ':') === false) {
                    $cronSocket = @fsockopen(($cronTarget['https'] ? 'ssl://' : '') . $cronTarget['host'], $cronTarget['https'] ? 443 : 80, $cronErrorNumber, $cronErrorText, 0.3);
                    if ($cronSocket) {
                        @stream_set_blocking($cronSocket, false);
                        @fwrite($cronSocket, wpc_loopback_request($cronTarget, []));
                        @fclose($cronSocket);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }
        $wpc_family = wpc_copy_family_on_disk($this->cachePath, $prefix);
        $prefix .= $wpc_family;

        $stalePlan = function_exists('wpc_stale_serve_plan') ? wpc_stale_serve_plan($this->cachePath, $prefix) : 'fresh';
        $baseFileName = 'index.html';
        if ($stalePlan === 'stale' && !@file_exists($this->cachePath . $prefix . 'index.html') && !@file_exists($this->cachePath . $prefix . 'index.html_gzip')) {
            $baseFileName = 'stale.html';
        }

        // v7.10.647 — Brotli lane (spec R2: payload substitution inside the lane the
        // request already took; R1 pairing is the writers' job, so presence == paired).
        // Note readgzfile below INFLATES and re-lets the server compress — this branch
        // ships the pre-compressed q11 body as-is, so it is strictly cheaper.
        // v7.10.662 — CF passthrough. Serve the pre-compressed q11 blob when the client accepts
        // br directly, OR when the request arrived through Cloudflare (HTTP_CF_RAY present).
        // Behind CF the origin never sees Accept-Encoding (the edge strips it — B2's finding),
        // but PROVEN on wpspeedkit.com: CF forwards our Content-Encoding:br to br-capable
        // clients AND decompresses it for the rest, so serving br unconditionally is safe there
        // and ships our q11 (~16% smaller than CF's own on-the-fly ~q4-5 brotli). With NEITHER
        // signal we must not serve br — a non-br client with no negotiating layer in front would
        // get undecodable bytes. Filter kill-switch, default on; guarded for the drop-in where
        // apply_filters may not be loaded yet.
        $acceptEncoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $cloudflarePassthrough = isset($_SERVER['HTTP_CF_RAY'])
            && (!function_exists('apply_filters') || apply_filters('wpc_br_cf_passthrough', true));
        if (strpos($acceptEncoding, 'br') !== false || $cloudflarePassthrough) {
            $brotliFile = $this->cachePath . $prefix . $baseFileName . '_br';
            // A _br is always the current html's pair: every html writer unlinks it before writing
            // (cacheHtml::saveGzCache), and the only land re-checks the md5 sidecar after writing
            // (html-br-land.php). The serve-time mtime comparison that re-proved it on every hit
            // is gone; a blob present here is served.
            if (@file_exists($brotliFile) && @is_readable($brotliFile) && (int) @filesize($brotliFile) > 512) {
                $this->setupCacheHeaders($brotliFile, 'br', $wpc_family);
                wpc_hit_kick((string) $this->urlKey);
                if ($stalePlan === 'stale') {
                    wpc_serve_stale_copy($this->cachePath, $prefix, $this->userFolder !== '');
                }
                wpc_send_header('Content-Encoding: br');
                readfile($brotliFile);
                exit;
            }
        }

        if (function_exists('readgzfile')) {
            $gzipFile = $this->cachePath . $prefix . $baseFileName . '_gzip';
            if (file_exists($gzipFile) && is_readable($gzipFile)) {
                $this->setupCacheHeaders($this->cachePath . $prefix . $baseFileName . '_gzip', 'gzip', $wpc_family);
                wpc_hit_kick((string) $this->urlKey);
                if ($stalePlan === 'stale') {
                    wpc_serve_stale_copy($this->cachePath, $prefix, $this->userFolder !== '');
                }
                if (!wpc_send_gzip_copy($gzipFile)) {
                    readgzfile($gzipFile);
                }
                exit;
            }
        }

        if (file_exists($this->cachePath . $prefix . $baseFileName) && is_readable($this->cachePath . $prefix . $baseFileName)) {
            $this->setupCacheHeaders($this->cachePath . $prefix . $baseFileName, 'html', $wpc_family);
            wpc_hit_kick((string) $this->urlKey);
            if ($stalePlan === 'stale') {
                wpc_serve_stale_copy($this->cachePath, $prefix, $this->userFolder !== '');
            }
            readfile($this->cachePath . $prefix . $baseFileName);
            exit;
        }
    }

    // v7.10.717 — instrument parity serve: the armed clean-key copy with the trace beacon
    // injected after the opening head tag. Admission mirrors the normal serve lane (byPass +
    // exists + not expired + valid, same device/webp prefix), and the response is no-store:
    // an instrumented body must never become a cacheable representation anywhere.
    public function serveTraceCopy($script)
    {
        try {
            if (!is_string($script) || $script === '') {
                return false;
            }
            if ($this->byPass()) {
                return false;
            }
            $isMobile = $this->is_mobile();
            $acceptsWebp = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false);
            $devicePrefix = '';
            if ($isMobile && $acceptsWebp) { $devicePrefix = 'mobile-webp'; }
            elseif ($isMobile) { $devicePrefix = 'mobile'; }
            elseif ($acceptsWebp) { $devicePrefix = 'webp'; }
            if (!$this->cacheExists($devicePrefix) || $this->cacheExpired() || !$this->cacheValid()) {
                return false;
            }
            $filePrefix = $devicePrefix !== '' ? $devicePrefix . '_' : '';
            $filePrefix .= wpc_copy_family_on_disk($this->cachePath, $filePrefix);
            $html = '';
            $plainFile = $this->cachePath . $filePrefix . 'index.html';
            $gzipFile = $this->cachePath . $filePrefix . 'index.html_gzip';
            if (@is_readable($plainFile) && (int) @filesize($plainFile) >= 1024) {
                $html = (string) @file_get_contents($plainFile);
            } elseif (function_exists('gzdecode') && @is_readable($gzipFile) && (int) @filesize($gzipFile) >= 200) {
                $html = (string) @gzdecode((string) @file_get_contents($gzipFile));
            }
            if ($html === '' || stripos($html, '</html>') === false) {
                return false;
            }
            $headAt = stripos($html, '<head');
            $headTagEnd = $headAt !== false ? strpos($html, '>', $headAt) : false;
            if ($headTagEnd === false) {
                return false;
            }
            $out = substr($html, 0, $headTagEnd + 1) . "\n" . $script . substr($html, $headTagEnd + 1);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
                header('Cache-Control: private, no-store, max-age=0', true);
                header('Server-Timing: wpc-cache;desc=hit', false);
                header('X-Cache-By: Advanced Cache - Trace');
            }
            echo $out;
            exit;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Headers for a copy served from the file. $family is the copy family the reader found: a
     * local-only copy goes out private, with no s-maxage and no Cache-Tag, so no shared cache
     * holds it on the second request either — the file carries the verdict the render made.
     * A copy kept in a signed-in visitor's folder goes out the same way, whatever its family: it is
     * that visitor's page, and a shared cache must neither hold it nor tag it.
     */
    public function setupCacheHeaders($cache_filepath, $type = 'gzip', $family = '')
    {
        $wpc_signed_in_copy = $this->userFolder !== '';
        $wpc_localonly = (string) $family !== '' || $wpc_signed_in_copy;
        // Session cookies make CDNs refuse to store the response; cached-file serves are
        // anonymous by definition.
        if (function_exists('header_remove')) {
            @header_remove('Set-Cookie');
        }
        wpc_send_header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');


        $browserMaxAge = max(0, (int) apply_filters('wpc_html_max_age', 300));
        // Mirror serves carry the same edge-TTL gate as PHP renders.
        $edgeMaxAge = 0;
        // Only the edge TTL is decided here (whether Cloudflare is connected). This block used to
        // restate the renderer's combined/split crit verdict as well, through four more option
        // reads, and never read the result; the page this file serves was rendered under that
        // verdict already. get_option() does not exist yet when the drop-in serves, so on the
        // fast path the whole block is skipped; it runs when the plugin serves a trace copy.
        if (function_exists('get_option')) {
            // v7.10.670 — parity with wpc_edge_smaxage(): high edge TTL whenever Cloudflare is
            // CONNECTED, full stop. The purge is device-clearing by construction (purgeEdgeHtmlUrls
            // always prefix+tag), so no crit-mode or purge-crown gate: those proxy conditions are
            // exactly what collapsed the edge TTL when device-split turned on (99->88).
            $cloudflareSettings = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            if (is_array($cloudflareSettings) && !empty($cloudflareSettings['token']) && !empty($cloudflareSettings['zone'])
                && apply_filters('wpc_edge_smaxage_on', true)) {
                $edgeMaxAge = max(0, (int) apply_filters('wpc_cf_html_edge_ttl', 86400));
            }
        }
        if ($wpc_localonly) {
            wpc_send_header('Cache-Control: ' . WPC_CC_LOCAL_ONLY);
            wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            // The reason the render stored this copy private (wpc_copy_reason_label, wpc-fs.php).
            wpc_send_header('X-WPC-CC: local-only-' . ($wpc_signed_in_copy ? 'signed-in' : wpc_copy_reason_label($cache_filepath)));
        } else {
            wpc_send_header('Cache-Control: ' . wpc_cc_freshness($browserMaxAge, $edgeMaxAge, wpc_edge_swr()));
            wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $browserMaxAge) . ' GMT');
        }
        // v7.10.658 (B2-R4) — the cached document is served in more than one encoding (raw
        // brotli on the _br branch; inflated-then-server-recompressed on gzip/html). Emit Vary
        // on EVERY branch so an intermediary cache (CDN, proxy) keys on Accept-Encoding and
        // never hands a br body to a client that did not ask for it, or vice versa. Cheap and
        // correct even where the origin serves uncompressed and the edge compresses.
        wpc_send_header('Vary: Accept-Encoding');


        $tagHost = strtolower((string) preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
        if (strpos($tagHost, 'www.') === 0) { $tagHost = substr($tagHost, 4); }
        $tagPath = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
        $tagPath = '/' . trim($tagPath, '/');
        if ($tagPath !== '/') { $tagPath .= '/'; }
        if ($tagHost !== '' && !$wpc_localonly) {
            wpc_send_header('Cache-Tag: wpc-html,wpc-u-' . substr(md5($tagHost . $tagPath), 0, 20), false);
        }


        // (stored headers), readable same-origin via the navigation entry's serverTiming.
        wpc_send_header('Server-Timing: wpc-cache;desc=hit', false);

		    $headerCacheFile = $this->cachePath . 'headers.json';
		    // A local-only copy never takes an edge directive from the stored set, whichever
		    // render wrote it.
		    $wpc_edge_directives = $wpc_localonly ? ['cache-tag' => 1, 'cache-control' => 1, 'cdn-cache-control' => 1, 'surrogate-control' => 1, 'expires' => 1] : [];
		    $wpc_edge_directives['set-cookie'] = 1;
		    // A signed-in visitor's copy takes no other directive a shared cache or a host's page cache acts on either.
		    if ($wpc_signed_in_copy) {
			    $wpc_edge_directives += ['surrogate-key' => 1, 'x-litespeed-cache-control' => 1, 'x-litespeed-tag' => 1, 'x-accel-expires' => 1, 'xkey' => 1, 'x-cache-tags' => 1];
		    }
		    // Check if cache file exists
		    if (file_exists($headerCacheFile)) {

			    $cachedHeadersJson = file_get_contents($headerCacheFile);
			    $cachedHeaders = json_decode($cachedHeadersJson, true);

			    // Get headers we've already set in this response
			    $existingHeaders = array();
			    foreach (headers_list() as $header) {
				    $parts = explode(':', $header, 2);
				    if (count($parts) == 2) {
					    $existingHeaders[trim($parts[0])] = true;
				    }
			    }

			    // Apply cached headers that aren't already defined
			    if (is_array($cachedHeaders)) {
				    foreach ($cachedHeaders as $name => $value) {
					    if (!isset($existingHeaders[$name]) && !isset($wpc_edge_directives[strtolower((string) $name)])) {
						    wpc_send_header($name . ': ' . $value);
					    }
				    }
			    }
		    }

        wpc_send_header('X-Cache-By: Advanced Cache - ' . $type);
    }

    public function is_mobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }
        return isset($_SERVER['HTTP_USER_AGENT']) && wpc_ua_mobile_match((string) $_SERVER['HTTP_USER_AGENT']);
    }


    public function removeCacheFiles($post_id)
    {
        if (wpc_purge_scope_all($post_id)) {
            self::removeDirectory(WPS_IC_CACHE);
            return;
        }

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        $urlKey = $this->url_key_class->setup($url);
        self::removeDirectory(WPS_IC_CACHE . $urlKey);
    }

    public static function removeDirectory($path)
    {
        wpc_fs_remove_tree($path);
    }

    public function removeCombinedFiles($post_id)
    {
        // v7.10.644 — OVERWRITE-ONLY (service receipt: 4/16 domains with un-fetchable
        // combined CSS, 102 domains / 1,178 recorded 404s — cached HTML referenced files
        // this delete had removed). CSS combine rebuilds every uncached render (its
        // serve-from-existing gate is hard-disabled), so deleting bought nothing but the
        // 404 window; files stay and the next render overwrites them, the .642 retention
        // sweep collects dead keys. JS combine DOES serve-from-existing, so its rebuild
        // trigger becomes a stale marker (epoch for 'all', per-key file) that the gate
        // honors — rebuild overwrites in place, no absence window for either lane.
        if (wpc_purge_scope_all($post_id)) {
            update_option('wpc_combine_stale_epoch', time(), false);
            return;
        }

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        $urlKey = $this->url_key_class->setup($url);
        wpc_fs_put(WPS_IC_COMBINE . $urlKey . '/.wpc-stale', (string) time());
    }

    public function removeCriticalFiles($post_id)
    {


        if (function_exists('wpc_cache_first_log')) {
            $wpc_tw_via = [];
            foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9), 1, 7) as $wpc_tw_f) {
                $wpc_tw_via[] = (isset($wpc_tw_f['class']) ? $wpc_tw_f['class'] . '::' : '') . ($wpc_tw_f['function'] ?? '?');
            }
            wpc_cache_first_log('crit-wipe', is_scalar($post_id) ? (string) $post_id : gettype($post_id), '', [
                'hook' => function_exists('current_action') ? (string) current_action() : '',
                'via'  => implode('<', $wpc_tw_via),
            ]);
        }
        if (wpc_purge_scope_all($post_id)) {


            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'wipeCriticalPreservingStores')) {
                wps_ic_cache_integrations::wipeCriticalPreservingStores(WPS_IC_CRITICAL);
            } else {
                self::removeDirectory(WPS_IC_CRITICAL);
            }
            global $wpdb;
            $options_table = $wpdb->options;

            $wpdb->query("DELETE FROM $options_table
             WHERE option_name LIKE '_transient_wpc_critical_key_%'
             OR option_name LIKE '_transient_timeout_wpc_critical_key_%'
             OR option_name LIKE '_transient_wpc_critical_uuid_%'
             OR option_name LIKE '_transient_timeout_wpc_critical_uuid_%'
             OR option_name LIKE '_transient_wpc_critical_ajax_%'
             OR option_name LIKE '_transient_timeout_wpc_critical_ajax_%'
             OR option_name LIKE '_transient_wpc_push_nope_%'
             OR option_name LIKE '_transient_timeout_wpc_push_nope_%'
             OR option_name LIKE '_transient_wpc_push_domain_%'
             OR option_name LIKE '_transient_timeout_wpc_push_domain_%'");


            if (function_exists('wpc_land_cooldown_clear')) { wpc_land_cooldown_clear('all'); }

            return;
        }

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        $urlKey = $this->url_key_class->setup($url);


        if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'removeFiles')) {
            wps_ic_cache_integrations::removeFiles(WPS_IC_CRITICAL . $urlKey);
        } else {
            self::removeDirectory(WPS_IC_CRITICAL . $urlKey);
        }


        if (function_exists('wpc_land_cooldown_clear')) { wpc_land_cooldown_clear($urlKey); }
    }

    /** Deletes a folder and everything in it through the one tree delete (wpc_fs_remove_tree). */
    public function recursiveDelete($folder)
    {
        wpc_fs_remove_tree($folder, true);
    }

}