<?php

define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');

// Drop-in loads before the plugin, so these are defined in BOTH places under !function_exists —
// whichever loads first wins and the two HTML writers can never drift apart.
if (!function_exists('wpc_edge_swr')) {
    // Default matches the s-maxage branch's 86400. Shipping 0 made .488 inert: line 25 reads
    // must-revalidate whenever swr is 0, so the fix was present and off.
    function wpc_edge_swr()
    {
        try {
            return max(0, (int) apply_filters('wpc_html_swr', 86400));
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

class wps_advancedCache
{

    private $siteUrl;
    private $urlKey;
    private $cacheExists = false;
    private $cachedHtml = '';

    private $host;
    private $cachePath;
    private $url_key_class;

    public function __construct()
    {
        if (!file_exists(WPS_IC_CACHE)) {
            mkdir(rtrim(WPS_IC_CACHE, '/'));
        }

        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();

	      // Append user cookie hash to the cache path if user is logged in
	      $user_hash = '';
				if (defined('WPC_CACHE_LOGGED_IN') && WPC_CACHE_LOGGED_IN){
						foreach ( $_COOKIE as $key => $value ) {
							if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
								$user_hash = md5( $key . substr( $value, 0, 10 ) ) . '/';
								break;
							}
						}

				}

	    // Add cookie variation to cache path
	    $cookie_string = '';
	    if (defined('WPC_CACHE_COOKIES') && WPC_CACHE_COOKIES !== false) {
		    $cookie_values = [];
		    $cache_cookies = WPC_CACHE_COOKIES;

		    foreach ($cache_cookies as $cookie_name) {
			    // Check if this is a prefix cookie (ends with _)
			    if (substr($cookie_name, -1) === '_') {
				    // This is a prefix - find all cookies that start with this prefix
				    $prefix = $cookie_name; // Keep the underscore for matching
				    foreach ($_COOKIE as $actual_cookie_name => $cookie_value) {
					    if (strpos($actual_cookie_name, $prefix) === 0 && !empty($cookie_value)) {
						    // Get the suffix (part after the prefix)
						    $suffix = substr($actual_cookie_name, strlen($prefix));

						    // Create a 7-character hash of the suffix and append to cookie value
						    $suffix_hash = substr(hash('md5', $suffix), 0, 7);
						    $cookie_values[] = $cookie_value . '_' . $suffix_hash;
					    }
				    }
			    } else {
				    // Regular cookie - exact match
				    if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
					    $cookie_values[] = $_COOKIE[$cookie_name];
				    }
			    }
		    }

		    if (!empty($cookie_values)) {
			    $cookie_string = '_' . implode('_', $cookie_values);
		    }
	    }

	    $this->cachePath = WPS_IC_CACHE . $user_hash . $this->urlKey . $cookie_string . '/';


    }

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilder()
    {
        $page_builders = ['run_compress',
            'run_restore',
            'elementor-preview',
            'fl_builder',
            'et_fb',
            'preview', //WP Preview
            'builder',
            'brizy',
            'fb-edit',
            'bricks',
            'ct_template',
            'ct_builder',
            'cs-render',
            'tatsu',
            'trp-edit-translation',
            'brizy-edit-iframe',
            'ct_builder',
            'livecomposer_editor',
            'tatsu',
            'tatsu-header',
            'tatsu-footer',
            'is-editor-iframe',
            'tve'
        ];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {

            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {

            return true;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (!empty($page_builders)) {
            foreach ($page_builders as $page_builder) {
                if (isset($_GET[$page_builder])) {
                    return true;
                }
            }
        }

        return false;
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

    public static function isFEBuilder()
    {
        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['trp-edit-translation']) || !empty($_GET['elementor-preview']) || !empty($_GET['tatsu']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['tve']) || !empty($_GET['et_fb']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['brizy-edit-iframe']) || !empty($_GET['brizy-edit']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return true;
        } else {
            return false;
        }
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

        if ( ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
            return true;
        }

        if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) ) {
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
        // Cart Fragments
        if ($this->isWooFragments()) {
            return true;
        }

        // Don't cache for specific WooCommerce pages or AJAX requests
        $excluded_pages = ['cart', 'checkout', 'my-account'];
        $request_uri = trim($_SERVER['REQUEST_URI']);
        $is_excluded_page = false;

        if (!empty($request_uri) && $request_uri !== '/') {
            foreach ($excluded_pages as $page) {
                if (str_contains($request_uri, $page)) {
                    $is_excluded_page = true;
                    break;
                }
            }
        }

        // Check for wc-ajax requests
        if ($is_excluded_page || str_contains($request_uri, 'wc-ajax')) {
            return true;
        }

        // Check mandatory cookies - bypass if any required cookie is missing
        if (defined('WPC_MANDATORY_COOKIES') && WPC_MANDATORY_COOKIES !== false && is_array(WPC_MANDATORY_COOKIES)) {
            foreach (WPC_MANDATORY_COOKIES as $mandatoryCookie) {
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


    /**
     * Just verify it's not some page test as we don't want those to cache HTML
     * @return void
     */
    public function pageTest()
    {
        return false;
    }

    public function saveCache($buffer, $prefix = '')
    {

        if (!empty($_GET['disable_cache'])) {
            return true;
        }


        if (function_exists('wpc_update_window_active') && wpc_update_window_active()
            && (!function_exists('wpc_render_armed_for_cache') || !wpc_render_armed_for_cache($buffer))) {
            return $buffer;
        }

        if (empty($buffer) || strlen($buffer) < 100 || strpos($buffer, '</body>') === false) {
            return $buffer;
        }

        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            global $post;
            if (!empty($post->ID)) {
                $preload_warmup = new wps_ic_preload_warmup();
                $preload_warmup->addError($post->ID, 'DONOTCACHEPAGE');
            }
            return $buffer;
        }

        // v7.10.739 — this writer has no query-string gate of its own, so an unauthorised
        // ?wpc_tier= render (plain bytes, door declined) would land in that arm's key.
        if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierWriteBlocked')
            && wps_ic_url_key::tierWriteBlocked()) {
            return $buffer;
        }

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        $excludes = get_option('wpc-excludes');
        $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $url = explode('?', $url)[0];
        if (!empty($excludes) && !empty($excludes['cache']) && function_exists('wpc_url_is_excluded')) {
            if (wpc_url_is_excluded($url, $excludes['cache']) !== false) {
                return $buffer;
            }
        }

        if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            return $buffer;
        }

		    if (is_user_logged_in()) {
				    return $buffer;
		    }

        if (!file_exists($this->cachePath)) {
            mkdir(rtrim($this->cachePath, '/'), 0777, true);
        }

        if (function_exists('gzencode')) {
            $this->saveGzCache($buffer, $prefix);
        }

        return $buffer;
    }

    public function saveGzCache($buffer, $prefix)
    {
        if (!empty($_GET['disable_cache'])) {
            return true;
        }


        $final = $this->cachePath . $prefix . 'index.html' . '_gzip';
        // v7.10.647 — Brotli pairing invariant (R1); see cacheHtml::saveGzCache.
        @unlink($this->cachePath . $prefix . 'index.html_br');
        $tmp   = $final . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 8);
        $fp = @fopen($tmp, 'w+');
        if ($fp === false) {
            return $buffer;
        }
        fwrite($fp, gzencode($buffer, 8));
        fclose($fp);
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
        } else {
            @file_put_contents($this->cachePath . $prefix . 'index.html_md5', md5($buffer));
        }

        return $buffer;
    }

    public function getCacheFilePath($prefix = '')
    {
        if (function_exists('readgzfile')) {
            return $this->cachePath . $prefix . '/index.html' . '_gzip';
        }

        return $this->cachePath . $prefix . '/index.html';
    }

    public function getCache($prefix = '')
    {


        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return;
        }

        // Path-keyed mirror: never serve it for query URLs beyond marketing params.
        // v7.10.598 — same predicate as the WRITE gate (cacheHtml::saveCache), so read and write
        // cannot disagree about which query strings are cacheable. This file is include_once'd by
        // the advanced-cache drop-in (advancedCacheSample.php:51) alongside traits/url_key.php,
        // so both gates and the strip list are one deployable unit — no baked copy to drift.
        $wpc_qs210 = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($wpc_qs210 !== '') {
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'queryIsCacheable')) {
                if (!wps_ic_url_key::queryIsCacheable($wpc_qs210)) {
                    return;
                }
            } else {
                parse_str($wpc_qs210, $wpc_qp210);
                foreach (array_keys((array) $wpc_qp210) as $wpc_qk210) {
                    $wpc_qk210 = strtolower((string) $wpc_qk210);
                    if (strpos($wpc_qk210, 'utm_') !== 0
                        && !in_array($wpc_qk210, ['fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', '_ga', 'igshid', 'ttclid'], true)) {
                        return;
                    }
                }
            }
        }


        try {
            $wpc_hb = $this->cachePath ? dirname(rtrim($this->cachePath, '/')) . '/wpc-cron-heartbeat.stamp' : '';
            if ($wpc_hb && (!@file_exists($wpc_hb) || (time() - (int) @filemtime($wpc_hb)) > 60)) {
                @touch($wpc_hb);
                $wpc_hh = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
                if ($wpc_hh && strpos($wpc_hh, ':') === false) {
                    $wpc_ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
                    $wpc_fp  = @fsockopen(($wpc_ssl ? 'ssl://' : '') . $wpc_hh, $wpc_ssl ? 443 : 80, $wpc_en, $wpc_es, 0.3);
                    if ($wpc_fp) {
                        @stream_set_blocking($wpc_fp, false);
                        @fwrite($wpc_fp, "GET /wp-cron.php?doing_wp_cron HTTP/1.1
Host: " . $wpc_hh . "
Connection: Close

");
                        @fclose($wpc_fp);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        // Read-side body floor: a poisoned sub-floor file that predates the write-path
        // floors would serve an empty 200 with public caching headers until purged.
        foreach (['index.html_gzip', 'index.html'] as $wpc_cf178) {
            $wpc_cfp178 = $this->cachePath . $prefix . $wpc_cf178;
            if (@file_exists($wpc_cfp178) && (int) @filesize($wpc_cfp178) < 1024) {
                @unlink($wpc_cfp178);
                // v7.10.647 R1: siblings die with the html they were paired to — an
                // orphaned _br here would keep serving the poisoned generation.
                @unlink($this->cachePath . $prefix . 'index.html_br');
                @unlink($this->cachePath . $prefix . 'index.html_md5');
            }
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
        $wpc_ae662 = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $wpc_cfpass662 = isset($_SERVER['HTTP_CF_RAY'])
            && (!function_exists('apply_filters') || apply_filters('wpc_br_cf_passthrough', true));
        if (strpos($wpc_ae662, 'br') !== false || $wpc_cfpass662) {
            $wpc_br647 = $this->cachePath . $prefix . 'index.html_br';
            if (@file_exists($wpc_br647) && @is_readable($wpc_br647) && (int) @filesize($wpc_br647) > 512) {
                // v7.10.658 (B2-R1, serve-time pairing) — a _br may only encode the CURRENT
                // cached html. Every html writer unlinks _br before a rewrite, but this belt
                // makes the pairing structural instead of writer-discipline-dependent: if any
                // representation of the html is NEWER than the blob, the html changed after the
                // blob was built, so it is stale and can NEVER become valid — delete it and
                // fall through. mtime (not a content md5) because a serve-time content compare
                // would need the land handler to WRITE a sidecar, reintroducing the decode+write
                // dropper shape .656 removed; a rewrite always advances the html files' mtimes.
                // Anchor on ALL representations, newest wins: the common cache layout stores only
                // index.html_gzip (+ _md5) with NO plain index.html, so anchoring on index.html
                // alone would treat every blob as stale and Brotli would never serve.
                $wpc_brm658 = (int) @filemtime($wpc_br647);
                $wpc_hm658 = 0;
                $wpc_hany658 = false;
                foreach (['index.html_gzip', 'index.html_md5', 'index.html'] as $wpc_hf658) {
                    $wpc_hfp658 = $this->cachePath . $prefix . $wpc_hf658;
                    if (@file_exists($wpc_hfp658)) {
                        $wpc_hany658 = true;
                        $wpc_hfm658 = (int) @filemtime($wpc_hfp658);
                        if ($wpc_hfm658 > $wpc_hm658) {
                            $wpc_hm658 = $wpc_hfm658;
                        }
                    }
                }
                if ($wpc_hany658 && $wpc_brm658 >= $wpc_hm658) {
                    $this->setupCacheHeaders($wpc_br647, 'br');
                    header('Content-Encoding: br');
                    readfile($wpc_br647);
                    exit;
                }
                @unlink($wpc_br647);
            }
        }

        if (function_exists('readgzfile')) {
            if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && is_readable($this->cachePath . $prefix . 'index.html' . '_gzip')) {
                $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html' . '_gzip', 'gzip');
                // Nginx instantly echoes readgzfile instead of saving it to variable.
                readgzfile($this->cachePath . $prefix . 'index.html' . '_gzip');
                exit;
            }
        }

        if (file_exists($this->cachePath . $prefix . 'index.html') && is_readable($this->cachePath . $prefix . 'index.html')) {
            $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html', 'html');
            readfile($this->cachePath . $prefix . 'index.html');
            exit;
        }
    }

    // v7.10.717 — instrument parity serve: the armed clean-key copy with the trace beacon
    // injected after the opening head tag. Admission mirrors the normal serve lane (byPass +
    // exists + not expired + valid, same device/webp prefix), and the response is no-store:
    // an instrumented body must never become a cacheable representation anywhere.
    public function serveTraceCopy717($script)
    {
        try {
            if (!is_string($script) || $script === '') {
                return false;
            }
            if ($this->byPass()) {
                return false;
            }
            $wpc_pm717 = $this->is_mobile();
            $wpc_pw717 = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false);
            $wpc_pfx717 = '';
            if ($wpc_pm717 && $wpc_pw717) { $wpc_pfx717 = 'mobile-webp'; }
            elseif ($wpc_pm717) { $wpc_pfx717 = 'mobile'; }
            elseif ($wpc_pw717) { $wpc_pfx717 = 'webp'; }
            if (!$this->cacheExists($wpc_pfx717) || $this->cacheExpired() || !$this->cacheValid()) {
                return false;
            }
            $wpc_dir717 = $wpc_pfx717 !== '' ? $wpc_pfx717 . '_' : '';
            $html = '';
            $wpc_pl717 = $this->cachePath . $wpc_dir717 . 'index.html';
            $wpc_gz717 = $this->cachePath . $wpc_dir717 . 'index.html_gzip';
            if (@is_readable($wpc_pl717) && (int) @filesize($wpc_pl717) >= 1024) {
                $html = (string) @file_get_contents($wpc_pl717);
            } elseif (function_exists('gzdecode') && @is_readable($wpc_gz717) && (int) @filesize($wpc_gz717) >= 200) {
                $html = (string) @gzdecode((string) @file_get_contents($wpc_gz717));
            }
            if ($html === '' || stripos($html, '</html>') === false) {
                return false;
            }
            $wpc_at717 = stripos($html, '<head');
            $wpc_cut717 = $wpc_at717 !== false ? strpos($html, '>', $wpc_at717) : false;
            if ($wpc_cut717 === false) {
                return false;
            }
            $out = substr($html, 0, $wpc_cut717 + 1) . "\n" . $script . substr($html, $wpc_cut717 + 1);
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

    public function setupCacheHeaders($cache_filepath, $type = 'gzip')
    {
        // Session cookies make CDNs refuse to store the response; cached-file serves are
        // anonymous by definition.
        if (function_exists('header_remove')) {
            @header_remove('Set-Cookie');
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');


        $wpc_hma49 = max(0, (int) apply_filters('wpc_html_max_age', 300));
        // Mirror serves carry the same edge-TTL gate as PHP renders.
        $wpc_sm210 = 0;
        if (function_exists('get_option')) {
            $wpc_st210 = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            // Mirrors wps_rewriteLogic::wpc_combined_crit_on() (rewriteLogic.php:404). The class
            // cannot be called here — the drop-in runs before the plugin loads — so the THREE-state
            // rule is restated: '1' on, '0' off, UNSET = auto-on when Cloudflare is connected.
            // Reading only === '1' made the mirror disagree with the renderer on every site that
            // never ticked the box, which is the default when CF is connected. The mirror serves
            // nearly all traffic, so the crown was earned and then discarded on the fast path.
            $wpc_cc210 = (is_array($wpc_st210) && isset($wpc_st210['combined-crit']))
                ? (string) $wpc_st210['combined-crit'] : '';
            if ($wpc_cc210 === '1') {
                $wpc_on210 = true;
            } elseif ($wpc_cc210 === '0') {
                $wpc_on210 = false;
            } else {
                // v7.10.666 (R4 — THE FLIP) — mirror of wps_rewriteLogic: prefer split; the floors
                // below gate safety. Kept in byte-parity with the renderer's AUTO default.
                if (apply_filters('wpc_split_default_on', true)) {
                    $wpc_on210 = false;
                } else {
                    $wpc_cf210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                    $wpc_on210 = is_array($wpc_cf210) && !empty($wpc_cf210['token']) && !empty($wpc_cf210['zone'])
                        && !(is_array($wpc_st210) && !empty($wpc_st210['minimal-mobile-css'])
                            && $wpc_st210['minimal-mobile-css'] == '1');
                }
            }
            // v7.10.568 — device-key safety floor, twin of wps_rewriteLogic::wpc_combined_crit_on().
            // Kept byte-for-byte in step with the renderer: the mirror decides s-maxage, so a
            // mirror that disagrees about the mode hands out an edge TTL the renderer never
            // sanctioned. Split is only reachable when a readback OBSERVED the device key.
            if (!$wpc_on210 && apply_filters('wpc_combined_crit_devkey_floor', true)) {
                $wpc_cfx210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                if (is_array($wpc_cfx210) && !empty($wpc_cfx210['token']) && !empty($wpc_cfx210['zone'])) {
                    $wpc_dk210 = get_option('wpc_cf_devkey_verified');
                    // v7.10.682 — ALLOWLIST twin (was a src!=='probe' blocklist): only a stamp that
                    // POSITIVELY names a deploy readback unlocks split; a src-less stamp stays combined.
                    if (!is_array($wpc_dk210) || empty($wpc_dk210['devkey'])
                        || (isset($wpc_dk210['src']) ? (string) $wpc_dk210['src'] : '') !== 'readback') {
                        $wpc_on210 = true;
                    }
                }
            }
            // v7.10.664 — non-CF device-blind foreign-cache floor (twin of wps_rewriteLogic). Kept
            // in step so the mirror never sanctions a mode the renderer refused.
            if (!$wpc_on210) {
                $wpc_cfd210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                if (!(is_array($wpc_cfd210) && !empty($wpc_cfd210['token']) && !empty($wpc_cfd210['zone']))
                    && function_exists('wpc_foreign_device_blind_cache') && wpc_foreign_device_blind_cache()) {
                    $wpc_on210 = true;
                }
            }
            // v7.10.670 — parity with wpc_edge_smaxage(): high edge TTL whenever Cloudflare is
            // CONNECTED, full stop. The purge is device-clearing by construction (purgeEdgeHtmlUrls
            // always prefix+tag), so no crit-mode ($wpc_on210) / purge-crown gate — those proxy
            // conditions are exactly what collapsed the edge TTL when device-split turned on (99->88).
            $wpc_cfsm210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            if (is_array($wpc_cfsm210) && !empty($wpc_cfsm210['token']) && !empty($wpc_cfsm210['zone'])
                && apply_filters('wpc_edge_smaxage_on', true)) {
                $wpc_sm210 = max(0, (int) apply_filters('wpc_cf_html_edge_ttl', 86400));
            }
        }
        header('Cache-Control: ' . wpc_cc_freshness($wpc_hma49, $wpc_sm210, wpc_edge_swr()));
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $wpc_hma49) . ' GMT');
        // v7.10.658 (B2-R4) — the cached document is served in more than one encoding (raw
        // brotli on the _br branch; inflated-then-server-recompressed on gzip/html). Emit Vary
        // on EVERY branch so an intermediary cache (CDN, proxy) keys on Accept-Encoding and
        // never hands a br body to a client that did not ask for it, or vice versa. Cheap and
        // correct even where the origin serves uncompressed and the edge compresses.
        header('Vary: Accept-Encoding');


        $wpc_th105 = strtolower((string) preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
        if (strpos($wpc_th105, 'www.') === 0) { $wpc_th105 = substr($wpc_th105, 4); }
        $wpc_tp105 = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
        $wpc_tp105 = '/' . trim($wpc_tp105, '/');
        if ($wpc_tp105 !== '/') { $wpc_tp105 .= '/'; }
        if ($wpc_th105 !== '') {
            header('Cache-Tag: wpc-html,wpc-u-' . substr(md5($wpc_th105 . $wpc_tp105), 0, 20), false);
        }


        // (stored headers), readable same-origin via the navigation entry's serverTiming.
        header('Server-Timing: wpc-cache;desc=hit', false);

		    $headerCacheFile = $this->cachePath . 'headers.json';
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
					    if (!isset($existingHeaders[$name])) {
						    header($name . ': ' . $value);
					    }
				    }
			    }
		    }

        header('X-Cache-By: Advanced Cache - ' . $type);
    }

    public function is_mobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
            // v7.10.663 — cache-bucket detector in lockstep with the crit-choice detector
            // (wps_rewriteLogic::isMobile): iPad / tablet / Windows-Phone count as MOBILE here too,
            // so a tablet's mobile crit is stored in the mobile bucket and never poisons the desktop.
            if (strpos($agent, 'ipad') !== false || strpos($agent, 'tablet') !== false
                || strpos($agent, 'windows phone') !== false || strpos($agent, 'mobile') !== false) {
                return true;
            }
            if ((preg_match('#^.*(2.0\ MMP|240x320|400X240|mobile|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $agent) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($agent, 0, 4)))) {
                return true;
            }
        }

        return false;
    }


    public function removeCacheFiles($post_id)
    {
        if ($post_id == 'all') {
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
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }

        $files = glob($path . '/*');
        if (is_dir($path) && empty($files)) {
            rmdir($path);
        }
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
        if ($post_id == 'all') {
            update_option('wpc_combine_stale_epoch', time(), false);
            return;
        }

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        $urlKey = $this->url_key_class->setup($url);
        @file_put_contents(WPS_IC_COMBINE . $urlKey . '/.wpc-stale', (string) time());
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
        if ($post_id == 'all') {


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

    public function recursiveDelete($folder)
    {
        // Delete all the files in the folder
        $files = glob($folder . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } else {
                $this->recursiveDelete($file);
            }
        }

        // Delete the folder itself
        if (is_dir($folder)) rmdir($folder);
    }

}