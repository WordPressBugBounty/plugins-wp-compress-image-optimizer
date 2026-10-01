<?php

if (!function_exists('wpc_response_cache_guard')) {
    // Responses are uncacheable until the finished body proves healthy; the ob tail
    // upgrades the header. A response that dies early can then never be edge-cached.
    function wpc_response_cache_guard()
    {
        try {
            if (!empty($GLOBALS['wpc_cc_guarded']) || isset($GLOBALS['wpc_cc_skip'])) {
                return;
            }
            // Each skip records why, so an "unguarded" response downstream can name its root.
            if (is_admin()) { $GLOBALS['wpc_cc_skip'] = 'admin'; return; }
            if (function_exists('is_user_logged_in') && is_user_logged_in()) { $GLOBALS['wpc_cc_skip'] = 'logged-in'; return; }
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') { $GLOBALS['wpc_cc_skip'] = 'method'; return; }
            if (function_exists('wp_doing_ajax') && wp_doing_ajax()) { $GLOBALS['wpc_cc_skip'] = 'ajax'; return; }
            if (defined('REST_REQUEST') && REST_REQUEST) { $GLOBALS['wpc_cc_skip'] = 'rest'; return; }
            // v7.22.64 — a personalised render is uncacheable in EVERY layer, whatever our own
            // cache setting: aliiadventureshack activated LiteSpeed Cache (LSCWP) after .63, and
            // LSCWP's own X-LiteSpeed-Cache-Control (public, max-age=604800) replaced the header
            // .63 sent — a cookie render served for a week. LSCWP exposes a control hook; the
            // same personal-cookie decision fires it here, on every front-end request.
            if (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'wpc_matched_personal_cookie')
                && wps_cacheHtml::wpc_matched_personal_cookie() !== '') {
                if (function_exists('do_action')) { do_action('litespeed_control_set_nocache', 'wpc: personal-cookie render'); }
                if (!headers_sent()) {
                    header('Cache-Control: no-store, max-age=0');
                    header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
                    header('X-LiteSpeed-Cache-Control: no-cache');
                    header('X-WPC-CC: no-store-personal-cookie');
                }
                $GLOBALS['wpc_cc_skip'] = 'personal-cookie-64';
                return;
            }
            // No writer, no pin: saveCache's tail is the only upgrader of this header — with
            // the page-cache pipeline off it never runs and the pin freezes the whole site
            // no-store, overriding the host's own cache layers (receipt: kalika/LiteSpeed).
            $wpc_cc_set = (defined('WPS_IC_SETTINGS') && function_exists('get_option')) ? get_option(WPS_IC_SETTINGS) : false;
            if (!is_array($wpc_cc_set) || empty($wpc_cc_set['cache']['advanced']) || $wpc_cc_set['cache']['advanced'] == '0') {
                $GLOBALS['wpc_cc_skip'] = 'cache-off';
                return;
            }
            if (headers_sent($wpc_hsf, $wpc_hsl)) {
                $GLOBALS['wpc_cc_skip'] = 'headers-sent@' . basename((string) $wpc_hsf) . ':' . (int) $wpc_hsl;
                return;
            }
            if (!apply_filters('wpc_response_cache_guard', true)) { $GLOBALS['wpc_cc_skip'] = 'filter'; return; }
            header('Cache-Control: no-store, max-age=0');
            // v7.21.346 — set Expires ourselves or mod_expires does: our Browser Cache
            // htaccess rules make Apache fill a missing Expires and APPEND its own bare
            // "Cache-Control: max-age=0" — the doubled header on every degraded response
            // (falknerei receipts; armed responses set Expires and were never doubled).
            header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            $GLOBALS['wpc_cc_guarded'] = true;
        } catch (\Throwable $e) {
        }
    }
    // The ob call-site provably doesn't run on every render lane (receipt: rebuild renders
    // shipped PHP-session no-cache headers with X-WPC-CC: unguarded-never-ran). send_headers
    // fires on every frontend request after auth — the guard arms there unconditionally.
    add_action('send_headers', 'wpc_response_cache_guard', 1);
}

if (!function_exists('wpc_edge_smaxage')) {
    // v7.10.670 — SIMPLE + ANTI-FRAGILE. A long edge TTL is safe iff a stale object is
    // CORRECTABLE, and the wpc-html purge is device-clearing BY CONSTRUCTION (purgeEdgeHtmlUrls
    // always prefix+tag purges — both evict device variants AND the tagless static mirror on every
    // CF plan). So the ONLY condition is: is Cloudflare connected. No crit-mode / device-key /
    // purge-crown gate — those proxy conditions are exactly what silently collapsed the edge TTL
    // when device-split turned on (wpcompress 99->88). Worst case if a purge ever fails is bounded
    // staleness (<= this TTL), then the object self-corrects via stale-while-revalidate.
    function wpc_edge_smaxage()
    {
        try {
            if (!apply_filters('wpc_edge_smaxage_on', true)) {
                return 0;
            }
            $cf = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : null;
            if (!is_array($cf) || empty($cf['token']) || empty($cf['zone'])) {
                return 0;
            }
            return max(0, (int) apply_filters('wpc_cf_html_edge_ttl', 86400));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_edge_swr')) {
    // Independent of the purge crown BY DESIGN: s-maxage is an unbounded hold that needs a
    // verified purge to correct, stale-while-revalidate is bounded and self-corrects inside one
    // revalidation cycle. Bundling them handed must-revalidate — a BLOCKING revalidate — to the
    // origin-serving sites least able to absorb one. Filter to 0 to restore must-revalidate.
    function wpc_edge_swr()
    {
        try {
            // v7.21.92 — SWR let browsers replay day-old copies AFTER a site was fixed
            // (falknerei: every purge was invisible to browser caches for 86400s). A fixed
            // site must become universally fixed within max-age. Filterable back up.
            return max(0, (int) apply_filters('wpc_html_swr', 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_cc_freshness')) {
    // Single formatter for both HTML writers (PHP render + mirror serve) so the two can never
    // drift apart again.
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
                    return 'stale';
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
            return 'stale';
        } catch (\Throwable $e) {
            return 'fresh';
        }
    }

    function wpc_stale_unlink_copies($dir, $prefix = '')
    {
        $dir = rtrim((string) $dir, '/') . '/';
        foreach (['index.html_br', 'index.html_gzip', 'index.html', 'index.html_md5', 'stale.html_br', 'stale.html_gzip', 'stale.html', 'wpc-rewarm43.txt'] as $copy_file) {
            @unlink($dir . $prefix . $copy_file);
        }
    }
}

class wps_cacheHtml
{

    private $siteUrl;
    private $urlKey;
    private $cacheExists = false;
    private $cachedHtml = '';

    private $host;
    private $cachePath;
    private $options;
    private $url_key_class;

    public function __construct()
    {

        $this->options = get_option(WPS_IC_SETTINGS);

        if (!file_exists(WPS_IC_CACHE)) {
            mkdir(rtrim(WPS_IC_CACHE, '/'));
        }

        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();

        // Append user cookie hash to the cache path if user is logged in
        $user_hash = '';
        if (defined('WPC_CACHE_LOGGED_IN') && WPC_CACHE_LOGGED_IN) {
            foreach ($_COOKIE as $key => $value) {
                if (strpos($key, 'wordpress_logged_in_') === 0) {
                    $user_hash = md5($key . substr($value, 0, 10)) . '/';
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

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
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

        if (!empty($_GET['test_cache'])) {
            return true;
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            return false;
        }


        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return false;
        }

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return false;
        }

        if (empty($this->options['cache']['advanced']) || $this->options['cache']['advanced'] == '0') {
            return false;
        }

        return true;
    }

    public function cacheValid($prefix = '')
    {
        $cacheFile = $this->cachePath . $prefix . wpc_copy_family_on_disk($this->cachePath, $prefix) . 'index.html';

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


    public function cacheExists($prefix = '')
    {
        if (!empty($_GET['disable_cache'])) {
            return false;
        }

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
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
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('stale-serve-miss', (string) $this->urlKey, '', ['variant' => (string) $prefix]);
            }
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
        } else {
            return false;
        }
    }


    // The LCP is often a CSS background whose URL already sits in the inlined crit —
    // discoverable but queued at low priority (perkzilla receipt: 1,790ms resource
    // delay, 110ms load). Preload the first ATF background image found in crit with
    // fetchpriority=high; crit regenerates with the page, so the URL is always live.
    /**
     * Proposes the first background url() of the inlined crit as the page's preload
     * (wpc-crit-bg-preload, below every measured candidate in the set's rank).
     */
    public static function critBgPreload($buffer, $imagePreloads = null)
    {
        if (!$imagePreloads instanceof wps_ic_image_preload_set) {
            return $buffer;
        }
        if (!is_string($buffer) || wpc_lcp_bg_preloaded($buffer)
            || strpos($buffer, 'id="wpc-crit-bg-preload"') !== false
            || !apply_filters('wpc_crit_bg_preload', true)
            || !preg_match('/<style[^>]*id="wpc-critical-css"[^>]*>(.*?)<\/style>/s', $buffer, $wpc_pm)) {
            return $buffer;
        }
        if (!preg_match('/background(?:-image)?\s*:\s*[^;{}]*url\(\s*([\'"]?)((?:https?:)?\/[^)\'"]{8,500}\.(?:jpe?g|png|webp|avif|svg)(?:\?[^)\'"]{0,120})?)\1/i', $wpc_pm[1], $wpc_pu)) {
            return $buffer;
        }
        // v7.21.58 — the FIRST bg url() in crit is not always a hero: ctfx preloaded
        // WooCommerce's photoswipe default-skin.png sprite at fetchpriority=high through the
        // q:i queue. UI-chrome sprites/skins/icons are never the LCP — veto them instead of
        // spending the page's highest-priority fetch on one.
        if (preg_match('/default-skin|photoswipe|sprite|\/icons?[\/.-]|loading|spinner|arrow/i', (string) $wpc_pu[2])) {
            // The veto withholds the page's highest-priority fetch. The same crit vetoes on every
            // render until it regenerates, so the line is sampled once an hour per page.
            if (function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('crit-bg-preload-vetoed', ['why' => 'ui-chrome', 'src' => substr((string) $wpc_pu[2], -120)], true);
            }
            return $buffer;
        }
        $wpc_ph = html_entity_decode($wpc_pu[2], ENT_QUOTES);
        if (strpos($wpc_ph, '//') === 0) {
            $wpc_ph = 'https:' . $wpc_ph;
        } elseif ($wpc_ph[0] === '/' && !empty($_SERVER['HTTP_HOST'])) {
            $wpc_ph = 'https://' . $_SERVER['HTTP_HOST'] . $wpc_ph;
        }
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_align_crit_urls_to_zone')) {
            $aligned_href = wps_rewriteLogic::wpc_align_crit_urls_to_zone('url(' . $wpc_ph . ')');
            if (is_string($aligned_href) && preg_match('#url\((https?://[^)]+)\)#i', $aligned_href, $aligned_match)) {
                $wpc_ph = $aligned_match[1];
            }
        }
        $imagePreloads->add('wpc-crit-bg-preload', esc_url($wpc_ph), 'both', 'crit-bg',
            wps_ic_image_preload_set::RANK_CRIT_BACKGROUND, ['kind' => wps_ic_image_preload_set::KIND_BACKGROUND]);
        return $buffer;
    }

    // Bricks marks bg-image elements .bricks-lazy-hidden (background-image:none!important)
    // and only its JS — which the delay holds — removes the class: ATF backgrounds paint
    // white until the timer. Re-assert every crit background rule at higher specificity
    // WITH the class, so ATF paints at first frame; the JS class-removal lands the same
    // value (no flicker) and below-fold elements stay lazy (their rules aren't in crit).
    public static function bricksAtfUnveil($buffer)
    {
        if (!is_string($buffer) || strpos($buffer, 'bricks-lazy-hidden') === false
            || strpos($buffer, 'id="wpc-bricks-unveil"') !== false || !apply_filters('wpc_bricks_unveil', true)) {
            return $buffer;
        }
        if (!preg_match('/<style[^>]*id="wpc-critical-css"[^>]*>(.*?)<\/style>/s', $buffer, $wpc_bm)) {
            // Critless recovery render: sheets are render-blocking (undefer invariant) and
            // only the class suppresses backgrounds — no crit to source re-asserts from.
            // Strip it in markup; the delayed JS class-removal becomes a no-op.
            $strippedTags = 0;
            $wpc_st = preg_replace_callback('/<[a-z][a-z0-9-]*\b[^>]*\bclass=["\'][^"\']*bricks-lazy-hidden[^"\']*["\'][^>]*>/i', function ($m) {
                return preg_replace('/\s*\bbricks-lazy-hidden\b/', '', $m[0]);
            }, $buffer, -1, $strippedTags);
            // Rule: a background the delay holds behind Bricks' lazy JS paints at first frame. On
            // every crit-less render of a Bricks page that JS is held, so the line is sampled once
            // an hour per page.
            if (is_string($wpc_st) && $strippedTags > 0 && function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('bricks-unveil', ['how' => 'class-stripped', 'n' => $strippedTags], true);
            }
            return is_string($wpc_st) ? $wpc_st : $buffer;
        }
        $wpc_out = '';
        $unveiledRules = 0;
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $wpc_bm[1], $wpc_br, PREG_SET_ORDER)) {
            foreach ($wpc_br as $wpc_r) {
                if (strlen($wpc_out) > 8192 || strpos($wpc_r[1], 'bricks-lazy-hidden') !== false) {
                    continue;
                }
                if (!preg_match_all('/(?:^|;)\s*(background(?:-image)?)\s*:\s*([^;]+)/i', $wpc_r[2], $wpc_bd, PREG_SET_ORDER)) {
                    continue;
                }
                $wpc_sels = [];
                foreach (explode(',', $wpc_r[1]) as $wpc_s) {
                    $wpc_s = trim($wpc_s);
                    if ($wpc_s === '' || $wpc_s[0] === '@') {
                        continue;
                    }
                    // Tripled class: theme suppressors are compound (.brxe-section.bricks-lazy-hidden
                    // !important = 0,2,0) and land LATER — a tie loses. 0,4,0 is untieable.
                    $wpc_lz = '.bricks-lazy-hidden.bricks-lazy-hidden.bricks-lazy-hidden';
                    if (preg_match('/^(.*?)((?:::?[a-zA-Z-]+(?:\([^()]*\))?)+)$/', $wpc_s, $wpc_pm) && $wpc_pm[1] !== '') {
                        $wpc_sels[] = $wpc_pm[1] . $wpc_lz . $wpc_pm[2];
                    } else {
                        $wpc_sels[] = $wpc_s . $wpc_lz;
                    }
                }
                if (!$wpc_sels) {
                    continue;
                }
                $wpc_decl = '';
                foreach ($wpc_bd as $wpc_d) {
                    $wpc_v = trim(preg_replace('/\s*!important\s*/i', '', $wpc_d[2]));
                    if ($wpc_v === '' || stripos($wpc_v, 'none') === 0) {
                        continue;
                    }
                    // v7.22.61 — re-assert ONLY declarations that carry an image. Bricks' class
                    // suppresses background-image alone; re-asserting a colour-only `background`
                    // (.card{background:var(--bg-surface)}) at 0,4,0 !important inverted the cascade
                    // over the element's own #id background-color, and two ridgeway sections painted
                    // the theme fallback instead of their teal/black until the class came off.
                    if (!preg_match('/\burl\(|\bimage-set\(|-gradient\(/i', $wpc_v)) {
                        continue;
                    }
                    $wpc_decl .= strtolower($wpc_d[1]) . ':' . $wpc_v . ' !important;';
                }
                if ($wpc_decl !== '') {
                    $wpc_out .= implode(',', $wpc_sels) . '{' . $wpc_decl . '}';
                    $unveiledRules++;
                }
            }
        }
        if ($wpc_out !== '') {
            // @layer: the theme suppressor is layered (!important-in-layer beats any unlayered
            // !important at any specificity); earliest-declared layer wins among importants,
            // and this style parses before the theme sheet ever applies.
            $buffer = str_replace($wpc_bm[0], $wpc_bm[0] . '<style id="wpc-bricks-unveil">@layer wpc-unveil{' . $wpc_out . '}</style>', $buffer);
            // Same rule as the crit-less branch: every render of a Bricks page with crit acts
            // here by design, so the line is sampled once an hour per page.
            if (function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('bricks-unveil', ['how' => 'rules-reasserted', 'n' => $unveiledRules], true);
            }
        }
        return $buffer;
    }


    // v7.21.88 — CSS PASSTHROUGH: the site-level simple answer. One option, one seam
    // (saveCache, which every stored copy passes through): every parked rel restored
    // regardless of which seam parked it; the page's own CSS serves blocking, correct by
    // construction, until the service generators carry typography and the operator flips it
    // back. A delivery choice the operator makes, not a belt on a pass that went wrong.
    // v7.21.92/.101 — TOTAL-LIVE EXTRAS: when every sheet must be live, NO wpc-managed CSS may
    // activate after parse. late-faces goes live (plugin-off ships those faces in blocking CSS),
    // hrefless flip links become real links, and the used-css rest link loads AT PARSE — the page
    // can DEPEND on its folded-inline rules, and its post-parse flip IS the white flash
    // (falknerei customer receipt, twice: .92 waterfall and the .101 forfeit-mode repaint).
    // v7.24.11 — two ways a page is total-live: the operator's passthrough option below, and the
    // park verdict when it refuses (a drifted or knowingly-degraded crit). It must reach
    // the second: the late-faces block and the flip links are armed by the crit lane whether or
    // not anything parked, so refusing to park does not by itself disarm them, and their
    // post-parse flip is the receipt above. wpc_css_total_live() answers for both.
    public static function wpc_make_managed_css_live($buffer)
    {
        $lateFaces = 0;
        $flipLinks = 0;
        $restLinks = 0;
        $buffer = (string) preg_replace('/(<style\b[^>]*id=["\']wpc-late-faces["\'][^>]*?)\s*media=["\']not all["\']/i', '$1', $buffer, -1, $lateFaces);
        $buffer = (string) preg_replace_callback('/<link\b[^>]*data-wpc-lf-href=["\']([^"\']+)["\'][^>]*>/i', function ($lf) {
            return '<link rel="stylesheet" href="' . $lf[1] . '" />';
        }, $buffer, -1, $flipLinks);
        $buffer = (string) preg_replace_callback('/<link\b[^>]*id=["\']wpc-used-css-rest["\'][^>]*>/i', function ($ur) use (&$restLinks) {
            if (preg_match('/data-wpc-rest=["\']([^"\']+)["\']/i', $ur[0], $uh)) {
                $restLinks++;
                return '<link rel="stylesheet" id="wpc-used-css-rest" href="' . $uh[1] . '" />';
            }
            return $ur[0];
        }, $buffer);
        // Rule: on a total-live page nothing wpc-managed activates after parse. The late-faces
        // writer and the flip-link mint arm after the crit stage disarmed them, so this seam
        // disarms again. That happens on every render of a total-live page by design, so the
        // line is sampled once an hour per page.
        if (($lateFaces + $flipLinks + $restLinks) > 0 && function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('css-total-live-disarmed', [
                'why' => !empty($GLOBALS['wpc_crit_park_refused']) ? 'park-refused' : 'passthrough',
                'late_faces' => $lateFaces, 'flip_links' => $flipLinks, 'rest' => $restLinks,
            ], true);
        }
        return $buffer;
    }

    /**
     * Is every wpc-managed stylesheet on this page meant to be live at parse?
     *
     * True on the operator's site-level passthrough option, and on a render whose park verdict
     * refused — the crit is drifted or knowingly degraded, so nothing was parked behind
     * it and nothing wpc-managed may activate after parse either.
     */
    public static function wpc_css_total_live()
    {
        if (!empty($GLOBALS['wpc_crit_park_refused'])) {
            return true;
        }

        return function_exists('get_option') && get_option('wpc_css_passthrough') === '1'
            && apply_filters('wpc_css_passthrough', true);
    }

    // v7.21.356 — ATF ASPECT CONTAINERS NEED A DEFINITE WIDTH (rosariospadaro CLS 0.300,
    // the single culprit in two PSI reports and reproduced locally with Lighthouse). The
    // hero image sits in a container the theme sizes with `aspect-ratio:4/3`. During the
    // streaming layout that container has no definite width, so the ratio resolves to a
    // 0x0 box: first paint happens with the image area collapsed, then layout lands it at
    // 372x279 and everything below jumps (the hero's `top:10%` ::before was merely the
    // element painted at that moment, which is why the report blamed a decorative glow).
    // Supplying the missing partner — a width the ratio can resolve against — fixes it at
    // the first layout: measured with real Lighthouse, CLS 0.300 -> 0 and 63 -> 78, with
    // no other change. Scoped hard: only classes that (a) sit on an ancestor of the ATF
    // image in the markup and (b) carry an aspect-ratio WITHOUT a width in the critical
    // CSS itself; emitted through :where() so it has zero specificity and any real author
    // width still wins. Kill with the wpc_atf_aspect_width filter.
    // v7.21.357 — BIG PARKED CSS RIDES A SIDECAR FILE, NOT THE DOCUMENT (rosariospadaro:
    // 400KB of HTML, 254KB of it inline <style>, of which 112KB is theme/Elementor CSS we
    // park inert and restore later — the browser still downloads and tokenizes every byte
    // before it reaches the markup, and on an emulated mid-range phone that is what makes
    // FCP 3.0s with a 135ms document). A parked block above the threshold is written once
    // to a content-addressed file and the placeholder becomes a parked LINK at the very
    // same position, so the existing restore path carries it: same selector, same atomic
    // barrier, same cascade order — no loader change at all.
    //
    // Guardrails, because losing a stylesheet is worse than shipping its bytes: the file
    // is written atomically and re-verified (exists + exact length) BEFORE the buffer is
    // touched — a failed write leaves the CSS inline; names are md5 of the content, so a
    // stale file is never wrong and identical blocks across pages collapse onto one file;
    // the store lives beside used-css in the preserve list, so a crit wipe cannot orphan
    // HTML that is already cached at an edge. Kill with wpc_css_sidecar.
    // v7.21.358 — one-time, per-site proof that the sidecar store is publicly fetchable.
    // Verdict is cached: 'ok' for 30 days, 'down' for 6 hours (a host fix re-probes on its
    // own). Never blocks a render — the probe is short, runs at most once per window, and
    // any failure simply leaves the CSS inline. Kill with wpc_css_sidecar_probe.
    public static function wpc_is_sidecar_reachable($url, $expect_len, $force_probe = false)
    {
        static $verdict = null;
        if ($verdict !== null && !$force_probe) {
            return $verdict === 'ok';
        }
        if (!apply_filters('wpc_css_sidecar_probe', true)) {
            $verdict = 'ok';
            return true;
        }
        if (!function_exists('get_option') || !function_exists('wp_remote_get')) {
            $verdict = 'down';
            return false;
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            // no verdict recorded — a loaded box must not decide this site's answer
            $verdict = 'down';
            return false;
        }
        $plugin_version = defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '';
        $stored_probe = get_option('wpc_sidecar_probe358', []);
        if (is_array($stored_probe) && !empty($stored_probe['v']) && !empty($stored_probe['t'])
            && (string) (isset($stored_probe['pv']) ? $stored_probe['pv'] : '') === $plugin_version) {
            $probe_age = time() - (int) $stored_probe['t'];
            $verdict_ttl = $stored_probe['v'] === 'ok' ? 30 * DAY_IN_SECONDS : 6 * HOUR_IN_SECONDS;
            if ($probe_age < $verdict_ttl) {
                $verdict = (string) $stored_probe['v'];
                return $verdict === 'ok';
            }
        }
        $response = wp_remote_get($url, ['timeout' => 2, 'sslverify' => false, 'redirection' => 0,
            'headers' => ['Cache-Control' => 'no-cache']]);
        if (function_exists('wpc_net_defer_on_render_guard') && wpc_net_defer_on_render_guard($response, 'sidecar358', function () use ($url, $expect_len) { wps_cacheHtml::wpc_is_sidecar_reachable($url, $expect_len, true); })) {
            $verdict = 'down';
            return false;
        }
        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $reachable = ($status_code >= 200 && $status_code < 300 && strlen($body) === (int) $expect_len);
        $verdict = $reachable ? 'ok' : 'down';
        update_option('wpc_sidecar_probe358', ['v' => $verdict, 't' => time(), 'code' => $status_code,
            'pv' => $plugin_version], false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('sidecar-probe', '', '', ['v' => $verdict, 'code' => $status_code,
                'len' => strlen($body), 'want' => (int) $expect_len]);
        }
        return $reachable;
    }

    public static function wpc_css_sidecar($buffer)
    {
        try {
            if (!is_string($buffer) || $buffer === ''
                || !apply_filters('wpc_css_sidecar', true)
                || !defined('WPS_IC_CRITICAL') || !defined('WPS_IC_CRITICAL_URL')) {
                return $buffer;
            }
            $min_bytes = (int) apply_filters('wpc_css_sidecar_min_bytes', 8192);
            $max_blocks = (int) apply_filters('wpc_css_sidecar_max', 8);
            if ($min_bytes < 4096 || $max_blocks < 1) {
                return $buffer;
            }
            if (!preg_match_all('/<style\b([^>]*\btype=["\'](wpc-(?:late-|mobile-)?stylesheet)["\'][^>]*)>(.*?)<\/style>/is',
                    $buffer, $style_matches, PREG_SET_ORDER)) {
                return $buffer;
            }
            $sidecar_dir = rtrim(WPS_IC_CRITICAL, '/') . '/sidecar/';
            if (!@is_dir($sidecar_dir)) {
                // v7.21.359 — NAME THE BAIL. rosariospadaro shipped .358 with the store
                // never created and no receipt to say so: the belt looked identical to
                // "nothing to do". Every refusal now writes why, with the evidence that
                // decides it (parent present/writable, the perms actually attempted).
                $parent_dir = rtrim(WPS_IC_CRITICAL, '/');
                @mkdir($sidecar_dir, 0755, true);
                clearstatcache(true, $sidecar_dir);
                if (!@is_dir($sidecar_dir)) {
                    // some hosts refuse 0755 under a group-writable parent; try the
                    // parent's own mode once before giving up
                    $parent_perms = @fileperms($parent_dir);
                    if ($parent_perms !== false) {
                        @mkdir($sidecar_dir, $parent_perms & 0777, true);
                        clearstatcache(true, $sidecar_dir);
                    }
                }
                if (!@is_dir($sidecar_dir)) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('sidecar-nodir', '', '', [
                            'dir'          => $sidecar_dir,
                            'parent_is'    => @is_dir($parent_dir) ? 1 : 0,
                            'parent_write' => @is_writable($parent_dir) ? 1 : 0,
                            'parent_perms' => @is_dir($parent_dir) ? substr(sprintf('%o', @fileperms($parent_dir)), -4) : '-',
                            'err'          => substr((string) (error_get_last()['message'] ?? ''), 0, 120),
                        ]);
                    }
                    return $buffer;
                }
            }
            $moved_count = 0;
            foreach ($style_matches as $style_match) {
                if ($moved_count >= $max_blocks) {
                    break;
                }
                $css = (string) $style_match[3];
                $css_len = strlen($css);
                if ($css_len < $min_bytes) {
                    continue;
                }
                // never a block that carries markup (a <style> whose text closes a tag)
                if (strpos($css, '</') !== false) {
                    continue;
                }
                $style_id = '';
                if (preg_match('/\bid=["\']([^"\']{1,80})["\']/i', $style_match[1], $id_match)) {
                    $style_id = (string) $id_match[1];
                }
                // never sidecar OUR OWN injected machinery (the parked type always reads
                // wpc-*, so the id is what actually distinguishes ours from the theme's)
                if ($style_id !== '' && stripos($style_id, 'wpc-') === 0) {
                    continue;
                }
                $css_hash = md5($css);
                $sidecar_file = $sidecar_dir . $css_hash . '.css';
                if (!@is_file($sidecar_file) || (int) @filesize($sidecar_file) !== $css_len) {
                    $tmp_file = $sidecar_file . '.tmp.' . getmypid();
                    if (wpc_fs_put($tmp_file, $css, LOCK_EX) === false || !@rename($tmp_file, $sidecar_file)) {
                        @unlink($tmp_file);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('sidecar-nowrite', '', '', ['bytes' => $css_len,
                                'dir_write' => @is_writable($sidecar_dir) ? 1 : 0,
                                'err' => substr((string) (error_get_last()['message'] ?? ''), 0, 120)]);
                        }
                        continue;
                    }
                }
                clearstatcache(true, $sidecar_file);
                // re-verify the bytes landed before anything leaves the document
                if (!@is_file($sidecar_file) || (int) @filesize($sidecar_file) !== $css_len) {
                    continue;
                }
                // v7.21.358 — WRITTEN IS NOT SERVED. The store is only useful if the browser
                // can actually fetch it: a host that blocks or rewrites wp-content/cache would
                // turn a sidecar into a 404 and an unstyled page after the restore. Prove it
                // once per site against a real written file, cache the verdict, and keep the
                // CSS inline until the answer is yes — a site that can never serve the store
                // simply never sidecars. Only a definitive 2xx with matching bytes admits it.
                $sidecar_url = rtrim(WPS_IC_CRITICAL_URL, '/') . '/sidecar/' . $css_hash . '.css';
                if (!self::wpc_is_sidecar_reachable($sidecar_url, $css_len)) {
                    continue;
                }
                $media = 'all';
                if (preg_match('/\bmedia=["\']([^"\']{1,60})["\']/i', $style_match[1], $media_match)) {
                    $media = $media_match[1];
                }
                $id_attr = $style_id !== '' ? ' id="' . $style_id . '"' : '';
                $link_tag = '<link rel="' . $style_match[2] . '"' . $id_attr
                    . ' href="' . esc_url($sidecar_url) . '" media="' . esc_attr($media) . '" data-wpc-sc="1" />';
                $style_pos = strpos($buffer, $style_match[0]);
                if ($style_pos === false) {
                    continue;
                }
                $buffer = substr_replace($buffer, $link_tag, $style_pos, strlen($style_match[0]));
                $moved_count++;
            }
            if ($moved_count && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('css-sidecar', '', '', ['n' => $moved_count]);
            }
            return $buffer;
        } catch (\Throwable $e) {
            return $buffer;
        }
    }

    /**
     * Assert-only for one release, then deleted. This wrote `:where(.a,.b){width:100%}` after the
     * critical CSS for a crit rule that sizes a media wrapper by aspect-ratio and states no
     * width, guessing 100%; it ran on the stored copy only, outside the stage table, so neither
     * `?wpc_stages=1` nor the goldens ever saw it. The image-sizing owner now decides every
     * <img>'s width, height and pin inside the table. When the old condition still holds, the
     * render logs `sizing-drift {what: atf-aspect-width, sel}` and the page is left as the table
     * made it; a site that shows this receipt with a collapsed media box is the case to bring
     * back into the owner.
     */
    public static function wpc_atf_aspect_width($buffer)
    {
        try {
            if (!is_string($buffer) || $buffer === '' || !apply_filters('wpc_atf_aspect_width', true)) {
                return $buffer;
            }
            if (!preg_match('/<style[^>]*id=["\']wpc-critical-css["\'][^>]*>(.*?)<\/style>/is', $buffer, $critMatch)) {
                return $buffer;
            }
            $crit = (string) $critMatch[1];
            if (stripos($crit, 'aspect-ratio') === false) {
                return $buffer;
            }
            $selectors = [];
            if (preg_match_all('/([^{}]{1,300})\{([^{}]{0,600}?aspect-ratio[^{}]{0,600})\}/i', $crit, $rules, PREG_SET_ORDER)) {
                foreach ($rules as $rule) {
                    $body = (string) $rule[2];
                    if (preg_match('/(?:^|[;{\s])width\s*:/i', $body) || preg_match('/position\s*:\s*(?:absolute|fixed)/i', $body)) {
                        continue;
                    }
                    foreach (explode(',', (string) $rule[1]) as $selector) {
                        $selector = trim($selector);
                        if (!preg_match('/^\.([A-Za-z][\w-]{2,60})$/', $selector, $classMatch)) {
                            continue;
                        }
                        $wrapsMedia = '/class=["\'][^"\']*\\b' . preg_quote($classMatch[1], '/')
                            . '\\b[^"\']*["\'][^>]*>[\\s\\S]{0,2000}?<(?:img|picture|video)\\b/i';
                        if (!preg_match($wrapsMedia, $buffer)) {
                            continue;
                        }
                        $selectors['.' . $classMatch[1]] = 1;
                        if (count($selectors) >= 4) {
                            break 2;
                        }
                    }
                }
            }
            if (!empty($selectors) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('sizing-drift', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                    ['what' => 'atf-aspect-width', 'sel' => implode(',', array_keys($selectors))]);
            }
            return $buffer;
        } catch (\Throwable $e) {
            return $buffer;
        }
    }

    public static function wpc_css_passthrough_restore($buffer)
    {
      try {
        if (!is_string($buffer)) {
            return $buffer;
        }
        $passthroughOn = get_option('wpc_css_passthrough') === '1'
            && apply_filters('wpc_css_passthrough', true);
        // v7.24.09 — a sheet is only ever parked into a buffer that already carries the crit
        // tag, so "parked links and no first-frame carrier" is not a state a render can reach
        // and needs no restore here. What is left is the option this function is named for.
        if (!$passthroughOn) {
            return $buffer;
        }
        // v7.21.93 — the .92 extras hid behind a parked-links gate and skipped on renders
        // with quarantined-but-unparked carriers (live receipt: late-faces still media="not
        // all" on a parked:0 render). Rel-restore stays conditional; the extras always run.
        $parkedLinks = (int) preg_match_all('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer);
        $parkedStyles = (int) preg_match_all('/<style\b[^>]*type=["\']wpc-stylesheet["\']/i', $buffer);
        $buffer = self::wpc_restore_parked_styles($buffer);
        if (preg_match('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer)) {
        $buffer = preg_replace_callback('/<link\b[^>]*>/i', function ($lm) {
            return str_replace(
                ['rel="wpc-late-stylesheet"', "rel='wpc-late-stylesheet'",
                 'rel="wpc-stylesheet"', "rel='wpc-stylesheet'",
                 'rel="wpc-mobile-stylesheet"', "rel='wpc-mobile-stylesheet'",
                 'type="wpc-stylesheet"', "type='wpc-stylesheet'",
                 'type="wpc-mobile-stylesheet"', "type='wpc-mobile-stylesheet'"],
                ['rel="stylesheet"', "rel='stylesheet'",
                 'rel="stylesheet"', "rel='stylesheet'",
                 'rel="stylesheet"', "rel='stylesheet'",
                 'type="text/css"', "type='text/css'",
                 'type="text/css"', "type='text/css'"],
                $lm[0]
            );
        }, $buffer);
        $buffer = self::wpc_restore_parked_styles($buffer);
        }
        // The total-live extras belong at saveCache's terminal seam, below the face-splitter's
        // flip-link mint: taken at this seam they are undone a few hundred lines later, on the
        // very renders they exist for.
        // Rule: with the passthrough option on, every sheet is live at parse. The parkers do not
        // read the option, so they park and this seam restores what they parked. It acts on every
        // stored render of such a site by design, so it is sampled once an hour per page.
        if (($parkedLinks + $parkedStyles) > 0 && function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('css-passthrough', ['links' => $parkedLinks, 'styles' => $parkedStyles], true);
        }
        return $buffer;
      } catch (\Throwable $e) { return $buffer; }
    }


    // v7.21.154 — ROSARIOSPADARO: whatever restores parked LINKS must restore parked STYLE
    // blocks. cssStyleLazy parks inline <style> as type="wpc-stylesheet" for the loader's
    // rest-css flip — but the restore lane rewrote only <link> tags, and on a total-live render
    // the .101 extras disarm the flip, so a parked style
    // (Elementor's own frontend-inline widget CSS) stayed inert FOREVER: unstyled page in
    // exactly the mode whose whole point is full styling. Scoped to <style OPEN TAGS only —
    // the loader JS carries the marker as a selector string and must never be rewritten.
    public static function wpc_restore_parked_styles($buffer)
    {
        if (!is_string($buffer) || stripos($buffer, 'wpc-stylesheet') === false) {
            return $buffer;
        }
        $out = preg_replace_callback('/<style\b[^>]*>/i', function ($sm) {
            return str_replace(
                ['type="wpc-stylesheet"', "type='wpc-stylesheet'"],
                ['type="text/css"', "type='text/css'"],
                $sm[0]
            );
        }, $buffer);
        return is_string($out) ? $out : $buffer;
    }

    public static function wpc_personal_cookie_names()
    {
        $names = apply_filters('wpc_personal_cookies', ['simplefavorites', 'wp-postpass_', 'comment_author_']);
        return is_array($names) ? $names : [];
    }

    public static function wpc_matched_personal_cookie()
    {
        if (empty($_COOKIE) || !is_array($_COOKIE)) {
            return '';
        }
        $personalNames = self::wpc_personal_cookie_names();
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            $cookieName = (string) $cookieName;
            foreach ($personalNames as $personalName) {
                $personalName = (string) $personalName;
                if ($personalName === '') {
                    continue;
                }
                if (substr($personalName, -1) === '_') {
                    if (stripos($cookieName, $personalName) === 0) {
                        return $cookieName;
                    }
                } elseif (strcasecmp($cookieName, $personalName) === 0) {
                    return $cookieName;
                }
            }
        }
        return '';
    }

    /**
     * A render refused before the store verdict (a query the key does not strip, ?disable_cache,
     * a truncated body, the admission contract) names its reason in the same header the verdict
     * uses, while the guard's no-store is still the response's Cache-Control.
     */
    private static function wpc_name_early_refusal($reason)
    {
        if (!empty($GLOBALS['wpc_cc_guarded']) && !headers_sent() && function_exists('wpc_send_header')) {
            wpc_send_header('X-WPC-CC: no-store-' . $reason);
        }
    }

    /**
     * The store verdict of a visitor render, receipted once per key and reason per 120 s: a
     * refused render writes no file, so without this line nothing on disk says why a page is not
     * cached. The sample is per reason because a per-key one let an `armed` line hide every
     * refusal of that page for two minutes (dbmwebdesign.de, 2026-09-28: `/referenzen/` hard
     * reloads refused `server-control` with no line in the journal). Warm renders already
     * receipt every gate they hit (warm-drop-*).
     */
    private static function wpc_log_store_verdict(array $verdict, $urlKey)
    {
        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) || !function_exists('wpc_cache_first_log')
            || !function_exists('get_transient') || (string) $urlKey === '') {
            return;
        }
        $sampleKey = 'wpc_store_verdict_seen_' . md5((string) $urlKey . '|' . (string) $verdict['reason']);
        if (get_transient($sampleKey)) {
            return;
        }
        set_transient($sampleKey, 1, 120);
        wpc_cache_first_log('store-verdict', (string) $urlKey, '', [
            'local' => !empty($verdict['local']) ? 1 : 0,
            'edge' => !empty($verdict['edge']) ? 1 : 0,
            'reason' => (string) $verdict['reason'],
        ]);
    }

    public function saveCache($buffer, $prefix = '')
    {
        if (function_exists('wpc_requote_crit_urls')) {
            $buffer = wpc_requote_crit_urls($buffer);
        }
        // Mint gate: never create cache/crit state for query URLs beyond marketing params.
        // v7.10.598 — one predicate, shared with the READ gate in advancedCache::getCache() and
        // derived from the same list url_key strips, so the two can no longer disagree. They did:
        // the key stripped 61 params while both gates named utm_* plus 11, so `?gbraid=…` shared
        // the clean page's artifacts and was still refused a cache entry — a full origin render
        // on every paid click. Zero extra inodes, because a stripped param collapses onto the
        // clean URL's key. Falls back to the old inline test if the class is somehow absent.
        $wpc_query_string = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($wpc_query_string !== '') {
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'queryIsCacheable')) {
                if (!wps_ic_url_key::queryIsCacheable($wpc_query_string, 'write')) {
                    self::wpc_name_early_refusal('query');
                    return $buffer;
                }
            } else {
                parse_str($wpc_query_string, $wpc_query_params);
                foreach (array_keys((array) $wpc_query_params) as $wpc_query_key) {
                    $wpc_query_key = strtolower((string) $wpc_query_key);
                    if (strpos($wpc_query_key, 'utm_') !== 0
                        && !in_array($wpc_query_key, ['fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', '_ga', 'igshid', 'ttclid'], true)) {
                        self::wpc_name_early_refusal('query');
                        return $buffer;
                    }
                }
            }
        }


        // Live showed warm-rx (arrival) without variants materializing; every exit below now
        // says WHICH gate dropped a warm render, and the write itself logs. Warm-only, bounded.
        $isWarmRender = !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) && function_exists('wpc_cache_first_log');
        $wpc_wgate = function ($g, $x = []) use ($isWarmRender, $prefix) {


            if (!empty($_SERVER['HTTP_X_WPC_DEBUG']) && !headers_sent()) {
                header('X-WPC-Cache-Save: skip-' . $g, false);
            }
            if ($isWarmRender) {
                wpc_cache_first_log('warm-drop-' . $g, (string) $this->urlKey, '', array_merge(['variant' => (string) $prefix], $x));
            }
        };
        if ($isWarmRender) {
            wpc_cache_first_log('warm-rx', (string) $this->urlKey, '', ['variant' => (string) $prefix]);
            // The warm run reads this to tell a page it rendered from one it never reached.
            if (function_exists('set_transient') && (string) $this->urlKey !== '') {
                set_transient('wpc_warm_rx_seen_' . md5((string) $this->urlKey), function_exists('wpc_warm_now') ? wpc_warm_now() : time(), HOUR_IN_SECONDS);
            }
        }

        if (!empty($_GET['disable_cache'])) {
            $wpc_wgate('disable-param');
            self::wpc_name_early_refusal('disable-param');
            return $buffer;
        }
        // v7.22.55 — belt for a lost or unlanded announce: the service told us a uuid, the page is
        // rendering, and what landed is not it → re-poll /v2/latest for this key (once per 5 min).
        try {
            $wpc_announce_key = 'wpc_land_announced55_' . md5((string) $this->urlKey);
            $wpc_announced_uuid = function_exists('get_transient') ? (string) get_transient($wpc_announce_key) : '';
            if ($wpc_announced_uuid !== '' && defined('WPS_IC_CRITICAL') && !get_transient('wpc_land_repoll55_' . md5((string) $this->urlKey))) {
                $wpc_landed_uuid = preg_replace('/[^A-Za-z0-9-]/', '', (string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/land_uuid.txt'));
                // An announce older than the land on disk is spent: re-polling it landed the older
                // generation over the newer one on two test sites.
                if ($wpc_landed_uuid === $wpc_announced_uuid
                    || (function_exists('wpc_gen_dispatched_before') && wpc_gen_dispatched_before((string) $this->urlKey, $wpc_announced_uuid, $wpc_landed_uuid))) {
                    delete_transient($wpc_announce_key);
                } elseif (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_land_repoll55', [(string) $this->urlKey, $wpc_announced_uuid])) {
                    set_transient('wpc_land_repoll55_' . md5((string) $this->urlKey), 1, 300);
                    if (function_exists('wpc_pl_sched')) {
                        wpc_pl_sched(time() + 5, 'wpc_land_repoll55', [(string) $this->urlKey, $wpc_announced_uuid]);
                    } else {
                        wp_schedule_single_event(time() + 5, 'wpc_land_repoll55', [(string) $this->urlKey, $wpc_announced_uuid]);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('land-repoll55', (string) $this->urlKey, '', ['announced' => substr($wpc_announced_uuid, 0, 8), 'landed' => substr($wpc_landed_uuid, 0, 8)]);
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        // An empty or truncated document is refused by the store verdict below (body-floor),
        // which judges the final buffer; this was the same test run first on the raw one.
        if (!is_string($buffer)) {
            return $buffer;
        }

        // v7.10.700 — VERIFIED COPY CONTRACT, Law A: a degraded render serves once and
        // evaporates. Refusal covers all three memoizers from this one choke point: the
        // store and mirror (early return) and the CF edge (no-store beats the full-HTML
        // rule's respect_origin). The visitor still gets the page. It is judged here, on the
        // buffer before the belts below touch it, and is the one refusal the store verdict
        // does not repeat.
        if (function_exists('wpc_copy_admissible')) {
            $wpc_admission_refusal = wpc_copy_admissible($buffer, (string) $this->urlKey, (string) $prefix);
            if ($wpc_admission_refusal !== '') {
                if (!headers_sent()) {
                    wpc_send_header('Cache-Control: private, no-store, max-age=0', true);
                }
                self::wpc_name_early_refusal('admission-' . $wpc_admission_refusal);
                $wpc_wgate('admission-' . $wpc_admission_refusal);
                return $buffer;
            }
        }

        // v7.10.613 — observe only. Exits on the first line of a visitor render; never reads or
        // writes $buffer beyond a bounded scan on a warm/cron request, at most once a day.
        if (function_exists('wpc_lane_detect_split')) {
            wpc_lane_detect_split($buffer);
        }

        // v7.24.10 — the site-level CSS passthrough option is a delivery choice, not a belt,
        // and saveCache owns it because it must hold on every path that reaches a stored copy.
        // No first-frame guard runs alongside it: a crit that landed is the fold, whatever its
        // size, and the park verdict is taken before anything is parked.
        $buffer = self::wpc_css_passthrough_restore($buffer);
        $buffer = self::wpc_atf_aspect_width($buffer);

        // Crit-full pages only; sibling files are mtime-cached.
        // v7.21.328 — FACE-COLLISION GATE (service receipt, falknerei: gesture restore
        // re-declared Manrope on top of wpc-live-faces — 16 face entries, newest never bind,
        // 56/64 text nodes render the fitted fallback after interaction; invisible to
        // computed-style checks, caught by their rendered-width sweep). When the page
        // carries wpc-live-faces, a restored sheet re-declaring those families is a proven
        // collision: run the splitter regardless of subsets_seen, and let VISITOR renders
        // build the .nofaces/.faces twins inline (bounded: 8 links, mtime-keyed, LOCK_EX)
        // instead of serving the colliding original until a warm happens by.
        // v7.21.331 — ATF FAMILIES ARE FIRST-FRAME STATE (felderwaterwell: crit styles
        // Teko 59x, the only real Teko faces sat behind an hrefless data-wpc-lf-href flip
        // -> headline rendered the fitted fallback until the late flip, then snapped;
        // condensed faces have no width-close fallback, so the triple-phase FOUT is
        // structural). A family the CRIT references may never have its faces quarantined
        // behind the flip: such sheets keep their faces at parse.
        $wpc_crit_families = [];
        if (is_string($buffer) && preg_match('/<style[^>]*id=["\']wpc-critical-css["\'][^>]*>(.*?)<\/style>/s', $buffer, $wpc_crit_style_match)
            && preg_match_all('/font-family\s*:\s*["\']?([^;,"\'}<]+)/i', $wpc_crit_style_match[1], $wpc_crit_family_matches)) {
            foreach ($wpc_crit_family_matches[1] as $wpc_crit_family) {
                $wpc_crit_family = strtolower(trim($wpc_crit_family));
                if ($wpc_crit_family !== '' && strlen($wpc_crit_family) <= 40 && strpos($wpc_crit_family, 'var(') === false
                    && strpos($wpc_crit_family, '--') === false && stripos($wpc_crit_family, 'fallback') === false) {
                    $wpc_crit_families[$wpc_crit_family] = 1;
                }
            }
        }
        $wpc_has_live_faces = is_string($buffer) && strpos($buffer, 'wpc-live-faces') !== false;
        $wpc_live_face_families = [];
        if ($wpc_has_live_faces && preg_match_all('/<style\b[^>]*wpc-live-faces[^>]*>(.*?)<\/style>/is', $buffer, $wpc_live_face_blocks)) {
            foreach ($wpc_live_face_blocks[1] as $wpc_live_face_css) {
                if (preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $wpc_live_face_css, $wpc_live_family_matches)) {
                    foreach ($wpc_live_family_matches[1] as $wpc_live_family) {
                        $wpc_live_face_families[strtolower(trim($wpc_live_family))] = 1;
                    }
                }
            }
        }
        if (is_string($buffer)
            && strpos($buffer, 'id="wpc-critical-css"') !== false
            && apply_filters('wpc_late_faces', true)
            && ($wpc_has_live_faces
                || (($wpc_subsets_seen_at = (int) get_option('wpc_subsets_seen', 0)) && (time() - $wpc_subsets_seen_at) < 7 * DAY_IN_SECONDS))) {
            $wpc_late_face_links = '';
            $wpc_split_count = 0;
            // Only families with a metric-pinned fallback (or inline subset) may leave their
            // sheet — an unpinned family's swap reflows whatever it styles.
            $wpc_pinned_families = [];
            // v7.10.731 — the pin is a DECLARED fallback face, not a stack reference: an
            // undeclared "<family> Fallback" name in a stack is skipped by the browser.
            if (preg_match_all('/@font-face\s*\{[^{}]*font-family\s*:\s*[\'"]?([^;\'"}]+?) Fallback[\'"]?\s*[;}]/i', $buffer, $wpc_fallback_face_matches)) {
                foreach ($wpc_fallback_face_matches[1] as $wpc_fallback_family) {
                    $wpc_pinned_families[strtolower(trim($wpc_fallback_family))] = 1;
                }
            }
            if (preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"}]+)[\'"]?\s*;[^}]{0,400}data:font\/woff2/i', $buffer, $wpc_subset_family_matches)) {
                foreach ($wpc_subset_family_matches[1] as $wpc_subset_family) {
                    $wpc_pinned_families[strtolower(trim($wpc_subset_family))] = 1;
                }
            }
            $buffer = preg_replace_callback(
                '/<link\b[^>]*rel=["\'](?:wpc-(?:mobile-|late-)?)?stylesheet["\'][^>]*>/i',
                function ($lm) use (&$wpc_late_face_links, &$wpc_split_count, $wpc_pinned_families, $wpc_has_live_faces, $wpc_live_face_families, $wpc_crit_families) {
                    if ($wpc_split_count >= 8 || !preg_match('/href=["\']([^"\']+)["\']/', $lm[0], $hm)) {
                        return $lm[0];
                    }
                    if (stripos($lm[0], 'wpc-used-css') !== false) {
                        return $lm[0];
                    }
                    $href = html_entity_decode($hm[1], ENT_QUOTES);
                    $cp = strrpos($href, 'wp-content/');
                    if ($cp === false || !preg_match('/\.css(\?|$)/', $href)) {
                        return $lm[0];
                    }
                    $rel = (string) preg_replace('/[?#].*$/', '', substr($href, $cp));
                    if (strpos($rel, '..') !== false || substr($rel, -12) === '.nofaces.css') {
                        return $lm[0];
                    }
                    // v7.21.82: .nofaces/.faces siblings written into et-cache were adopted by
                    // Divi's own newest-file glob in place of its real file. Never split a
                    // foreign-cache source.
                    if (strpos($rel, '/et-cache/') !== false) {
                        return $lm[0];
                    }
                    $path = trailingslashit(ABSPATH) . $rel;
                    if (!@is_readable($path)) {
                        return $lm[0];
                    }
                    $mt = (int) @filemtime($path);
                    $sib = preg_replace('/\.css$/', '.nofaces.css', $path);
                    $fsib = preg_replace('/\.css$/', '.faces.css', $path);
                    // The extracted .faces.css carries the @font-face blocks VERBATIM, including the
                    // unicode-range remote_range injects. Keying only on pinned families meant a landed
                    // range never invalidated it — and the source CSS mtime does not move either, so
                    // neither rebuild condition fired and the stale range served indefinitely (busy kept
                    // 8de0d6bf's U+0-34, covering U+33, so the 91 KiB icon font stayed on the pipe even
                    // after .429 unfroze the CSS-file layer one level up). Fold the map into the pf key.
                    $wpc_remote_ranges = get_option('wpc_font_remote_ranges', []);
                    if (!is_array($wpc_remote_ranges)) { $wpc_remote_ranges = []; }
                    if (!empty($wpc_remote_ranges)) { ksort($wpc_remote_ranges); }
                    $wpc_pin_hash = substr(md5(
                        implode(',', array_keys($wpc_pinned_families))
                        . (empty($wpc_remote_ranges) ? '' : '|rr2:' . md5(serialize($wpc_remote_ranges)))
                    ), 0, 8);
                    if (!@is_readable($sib) || (int) @filemtime($sib) < $mt
                        || strpos((string) @file_get_contents($sib, false, null, 0, 24), $wpc_pin_hash) === false) {
                        // Sibling (re)builds are background-lane work; visitor renders serve the
                        // original link untouched until a warm builds it.
                        if (!$wpc_has_live_faces
                            && !((function_exists('wp_doing_ajax') && wp_doing_ajax())
                            || (defined('DOING_CRON') && DOING_CRON)
                            || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']))) {
                            return $lm[0];
                        }
                        $css = (string) @file_get_contents($path);
                        if ($css === '' || stripos($css, '@font-face') === false || stripos($css, 'url(') === false) {
                            return $lm[0];
                        }
                        $faces = '';
                        $stripped = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($fm) use (&$faces, $wpc_pinned_families) {
                            if (stripos($fm[0], 'url(') !== false && stripos($fm[0], 'data:') === false) {
                                if (preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $fm[0], $wpc_face_family_match)
                                    && empty($wpc_pinned_families[strtolower(trim($wpc_face_family_match[1]))])) {
                                    return $fm[0];
                                }
                                $faces .= $fm[0];
                                return '';
                            }
                            return $fm[0];
                        }, $css);
                        if (!is_string($stripped) || $faces === '') {
                            return $lm[0];
                        }
                        if (class_exists('wps_ic_combine_css')) {
                            try {
                                $wpc_minified = (new wps_ic_combine_css())->minifyCSS($stripped);
                                if (is_string($wpc_minified) && $wpc_minified !== '') {
                                    $stripped = $wpc_minified;
                                }
                            } catch (\Throwable $e) {
                            }
                        }
                        wpc_fs_put($sib, '/*pf:' . $wpc_pin_hash . '*/' . $stripped, LOCK_EX);
                        wpc_fs_put($fsib, $faces, LOCK_EX);
                        @touch($sib, $mt);
                        @touch($fsib, $mt);
                    }
                    if (!@is_readable($fsib) || (int) @filesize($fsib) < 1) {
                        return $lm[0];
                    }
                    // v7.21.331 — crit-referenced family in this sheet's faces: keep the sheet
                    // WHOLE (faces load at parse), never quarantine behind the flip.
                    if (!empty($wpc_crit_families)) {
                        $wpc_crit_check_faces_css = (string) @file_get_contents($fsib, false, null, 0, 65536);
                        if ($wpc_crit_check_faces_css !== '' && preg_match_all('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $wpc_crit_check_faces_css, $wpc_crit_check_family_matches)) {
                            foreach ($wpc_crit_check_family_matches[1] as $wpc_crit_check_family) {
                                if (!empty($wpc_crit_families[strtolower(trim($wpc_crit_check_family))])) {
                                    return $lm[0];
                                }
                            }
                        }
                    }
                    $wpc_split_count++;
                    $base = substr($href, 0, $cp);
                    $newHref = $base . preg_replace('/\.css$/', '.nofaces.css', $rel) . '?nf=' . $mt;
                    $faceHref = $base . preg_replace('/\.css$/', '.faces.css', $rel) . '?nf=' . $mt;
                    // v7.10.714 — hrefless until the late-CSS flip: even media="not all"
                    // stylesheets download at low priority, and those fetches sit inside the
                    // paint-mark windows. The loader attaches the href at flip time.
                    // v7.21.328 — if wpc-live-faces already declares EVERY family this .faces.css
                    // would re-declare, the flip-time re-declaration IS the never-binds collision:
                    // the sheet goes .nofaces and the faces ride the live block alone.
                    $wpc_skip_face_link = false;
                    if ($wpc_has_live_faces && !empty($wpc_live_face_families)) {
                        $wpc_live_check_faces_css = (string) @file_get_contents($fsib, false, null, 0, 65536);
                        if ($wpc_live_check_faces_css !== '' && preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $wpc_live_check_faces_css, $wpc_live_check_family_matches)) {
                            $wpc_skip_face_link = true;
                            foreach ($wpc_live_check_family_matches[1] as $wpc_live_check_family) {
                                if (empty($wpc_live_face_families[strtolower(trim($wpc_live_check_family))])) {
                                    $wpc_skip_face_link = false;
                                    break;
                                }
                            }
                        }
                    }
                    if (!$wpc_skip_face_link) {
                        $wpc_late_face_links .= '<link rel="stylesheet" data-wpc-lf-href="' . esc_url($faceHref) . '" media="not all" data-wpc-lf="1" />';
                    }
                    return str_replace($hm[1], esc_url($newHref), $lm[0]);
                },
                $buffer
            );
            if ($wpc_late_face_links !== '') {
                $buffer = str_ireplace('</head>', $wpc_late_face_links . '</head>', $buffer);
            }
        }

        // v7.24.11 — TOTAL-LIVE EXTRAS, TERMINAL SEAM. This is the last pass that touches
        // wpc-managed CSS, and it has to be: the three face-gate stages re-emit #wpc-late-faces
        // with media="not all" after the crit stage disarmed it, and the splitter directly above
        // mints hrefless data-wpc-lf-href flip links later still. Taken any earlier, the disarm
        // is undone downstream and the page ships the post-parse flip the .101 white-flash
        // receipt names. The crit stage also disarms mid-pipeline, for the renders that bail out
        // of saveCache above and never reach this seam.
        if (self::wpc_css_total_live()) {
            $buffer = self::wpc_make_managed_css_live($buffer);
        }

        // THE STORE VERDICT, then the header that carries it. One decision on the final buffer
        // answers both questions — is this render stored, and may a cache in front of the origin
        // hold it — before any Cache-Control upgrade, so a render refused a copy never reaches
        // the edge as `public`. The guard (wpc_response_cache_guard) sent no-store at
        // send_headers; this block is the single place that changes it, and every response it
        // does not upgrade names its reason in X-WPC-CC.
        $wpc_verdict = function_exists('wpc_store_verdict')
            ? wpc_store_verdict($buffer, [
                'url_key' => (string) $this->urlKey,
                'cache_logged_in' => $this->cacheLoggedIn(),
                'ignore_server_control' => !empty($this->options['cache']['ignore-server-control'])
                    && $this->options['cache']['ignore-server-control'] != '0',
            ])
            : ['local' => false, 'edge' => false, 'reason' => 'no-verdict'];
        $wpc_reason = (string) $wpc_verdict['reason'];
        self::wpc_log_store_verdict($wpc_verdict, (string) $this->urlKey);

        // A render personalised by a cookie tells every upstream cache so from the send_headers
        // guard above (wpc_response_cache_guard: no-store, X-LiteSpeed-Cache-Control, X-WPC-CC),
        // which decides it with the same wps_cacheHtml::wpc_matched_personal_cookie() the verdict
        // uses, on every front-end GET whatever the cache setting. The verdict refuses the copy;
        // it no longer sends those headers a second time.
        if (empty($GLOBALS['wpc_cc_guarded'])) {
            if ($wpc_reason !== 'personal-cookie' && !headers_sent() && !is_admin()
                && !(function_exists('is_user_logged_in') && is_user_logged_in())) {
                wpc_send_header('X-WPC-CC: unguarded-' . (isset($GLOBALS['wpc_cc_skip']) ? (string) $GLOBALS['wpc_cc_skip'] : 'never-ran'));
            }
        } elseif (headers_sent($wpc_headers_sent_file, $wpc_headers_sent_line)) {
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cc_hs_log')) {
                set_transient('wpc_cc_hs_log', 1, 60);
                wpc_cache_first_log('cc-headers-sent', (string) $this->urlKey, '', ['variant' => (string) $prefix,
                    'at' => basename((string) $wpc_headers_sent_file) . ':' . (int) $wpc_headers_sent_line]);
            }
            unset($GLOBALS['wpc_cc_guarded']);
        } elseif (empty($wpc_verdict['local'])) {
            wpc_send_header('Cache-Control: ' . WPC_CC_NO_STORE);
            wpc_send_header('X-WPC-CC: no-store-' . $wpc_reason);
            unset($GLOBALS['wpc_cc_guarded']);
        } elseif (empty($wpc_verdict['edge'])) {
            // Stored for this origin only: private, no s-maxage, no Cache-Tag, so no shared cache
            // holds it and a browser revalidates on every view.
            wpc_send_header('Cache-Control: ' . WPC_CC_LOCAL_ONLY);
            wpc_send_header('X-WPC-CC: local-only-' . $wpc_reason);
            unset($GLOBALS['wpc_cc_guarded']);
        } elseif (function_exists('wpc_update_window_active') && wpc_update_window_active()) {
            // Rule: a render made inside the update window is never handed to a cache in front of
            // the origin; the copy stored here is kept as the verdict decided (armed = stored),
            // only this response goes out private. The update purged every edge at the start of
            // the window and nothing purges it at the end, so a public answer here is what the
            // edge keeps for a day. Observed failure (wpcompress.com and acrystalglass.com,
            // 2026-09-28): Cloudflare and LiteSpeed served the window render, still carrying
            // X-WPC-Update-Window: 1, as a HIT with s-maxage=86400 long after the window closed.
            wpc_send_header('Cache-Control: ' . WPC_CC_LOCAL_ONLY);
            wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            wpc_send_header('X-WPC-CC: private-update-window');
            unset($GLOBALS['wpc_cc_guarded']);
        } else {
            // A Set-Cookie on a publicly-cacheable page makes CDNs refuse to store it
            // (cf-cache-status: BYPASS regardless of Cache-Control) — a plugin-started
            // PHPSESSID on anonymous renders kept the whole site edge-uncacheable. The
            // guard already limits this lane to anonymous GETs, where a session cookie
            // is cache-poison by definition.
            self::wpc_log_session_headers_stripped('render', (bool) apply_filters('wpc_strip_setcookie_on_public', true));
            if (function_exists('header_remove') && apply_filters('wpc_strip_setcookie_on_public', true)) {
                @header_remove('Set-Cookie');
            }
            $wpc_html_max_age_s = max(0, (int) apply_filters('wpc_html_max_age', 300));
            $wpc_edge_smaxage_s  = function_exists('wpc_edge_smaxage') ? wpc_edge_smaxage() : 0;
            // PHP's session engine auto-sends Pragma: no-cache + a 1981 Expires on any
            // request where a plugin started a session — both override-purge here or the
            // edge honors them over our Cache-Control.
            if (function_exists('header_remove')) {
                @header_remove('Pragma');
            }
            wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $wpc_html_max_age_s) . ' GMT');
            wpc_send_header('Cache-Control: ' . wpc_cc_freshness($wpc_html_max_age_s, $wpc_edge_smaxage_s,
                function_exists('wpc_edge_swr') ? wpc_edge_swr() : 0));
            // v7.10.683 — RUM cache-layer truth for EDGE hits. A shared cache (CF, host proxy)
            // stores this render WITH its headers and replays them on every HIT — so a copy
            // minted by a fresh render carried no wpc-cache marker and every edge HIT counted
            // as "rendered" forever (the 0%-served-from-cache panel on a CF site whose views
            // were overwhelmingly edge HITs). Stamp the mint epoch into the frozen headers:
            // the collector reads it same-origin via the navigation entry and classifies a
            // replay older than its threshold as cache-served. Fresh renders read as now.
            wpc_send_header('Server-Timing: wpc-mint;desc=' . time(), false);
            if (function_exists('wpc_views_24h_bump')) { wpc_views_24h_bump((string) $this->urlKey); }
            // Natural-assets verdict for THIS render, riding the same frozen-header channel:
            // 'natural' when on, else the first refusing gate. One DevTools look answers
            // "why is this site still on transform URLs" for any site, forever.
            if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_natural_assets_reason')) {
                wpc_send_header('Server-Timing: wpc-nat;desc=' . wps_rewriteLogic::wpc_natural_assets_reason(), false);
            }
            unset($GLOBALS['wpc_cc_guarded']);
        }

        if (empty($wpc_verdict['local'])) {
            // The debug gate keeps the names the tarlo diagnosis recipe reads: the checks that
            // used to live in the armed test all answer "unarmed".
            $wpc_gate_names = ['personal-cookie' => 'personal-cookie-62'];
            foreach (['empty-buffer', 'combine-param', 'cdn-suppressed', 'crit-missing-parked-sheets', 'collecting', 'exception', 'no-verdict'] as $wpc_unarmed_reason) {
                $wpc_gate_names[$wpc_unarmed_reason] = 'unarmed';
            }
            $wpc_wgate(isset($wpc_gate_names[$wpc_reason]) ? $wpc_gate_names[$wpc_reason] : $wpc_reason);
            if ($wpc_reason === 'donotcachepage') {
                global $post;
                if (!empty($post->ID)) {
                    $preload_warmup = new wps_ic_preload_warmup();
                    $preload_warmup->addError($post->ID, 'DONOTCACHEPAGE');
                }
            }
            return $buffer;
        }
        $wpc_copy_family = function_exists('wpc_copy_family') ? wpc_copy_family($wpc_verdict) : '';



        $purge_rules = get_option('wps_ic_purge_rules');
        if (!isset($purge_rules['post-publish'])) {
            $options = new wps_ic_options();
            $purge_rules = $options->get_preset('purge_rules');
        }
        $type_lists = [];
        if (!empty($purge_rules['type-lists'])) {
            $type_lists = $purge_rules['type-lists'];
        }

        if (is_archive() || is_category() || is_tag() || is_author() || is_date() || is_post_type_archive() || is_tax()) {
            if (!isset($type_lists['archive-pages'])) {
                $type_lists['archive-pages'] = [];
            }
            if (!in_array($this->urlKey, $type_lists['archive-pages'])) {
                $type_lists['archive-pages'][] = $this->urlKey;
            }
        }

        if ($this->hasRecentPostsWidget($buffer)) {
            if (!isset($type_lists['recent-posts-widget'])) {
                $type_lists['recent-posts-widget'] = [];
            }
            if (!in_array($this->urlKey, $type_lists['recent-posts-widget'])) {
                $type_lists['recent-posts-widget'][] = $this->urlKey;
            }
        }


        // v7.10.522 — the webp dimension is dead weight. It only ever built this filename:
        // is_webp_request() has exactly one caller and it is a prefix builder, and NOTHING
        // swaps an image URL on the request's Accept (format selection lives in <picture>,
        // htaccess RewriteCond and CDN edge negotiation — all URL-identical). The proof is
        // downstream: the htaccess mirror at :1175 ALREADY collapses it
        // ($htPrefix = strpos($prefix,'mobile') ? 'mobile_' : ''), so zero-PHP static serve
        // has been handing one file to webp and non-webp clients in production all along.
        // Splitting it in the PHP cache therefore doubled the cache footprint AND the warm
        // fan-out (4 renders instead of 2) to store byte-identical copies.
        $wpc_req_webp = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false)
            && apply_filters('wpc_webp_cache_variant', false);
        $prefix = $this->is_mobile() ? ($wpc_req_webp ? 'mobile-webp' : 'mobile') : ($wpc_req_webp ? 'webp' : '');

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        if (!file_exists($this->cachePath)) {
            mkdir(rtrim($this->cachePath, '/'), 0777, true);
        }


        $wpc_map_file = $this->cachePath . 'url.txt';
        if (!@file_exists($wpc_map_file) && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
            $wpc_map_url = wps_ic_url_key::sanitizeSameHostUrl(
                (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
                . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?')
            );
            if ($wpc_map_url !== '') {
                wpc_fs_put($wpc_map_file, $wpc_map_url);
            }
        }

        if (function_exists('wpc_compute_tpl_key') && class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
            $wpc_tk = wpc_compute_tpl_key();
            if ($wpc_tk !== '' && $this->urlKey) {
                $wpc_td = rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/';
                if (is_dir($wpc_td) || (function_exists('wp_mkdir_p') && wp_mkdir_p($wpc_td))) {
                    if (!@file_exists($wpc_td . 'tpl.txt') || trim((string) @file_get_contents($wpc_td . 'tpl.txt')) !== $wpc_tk) {
                        wpc_fs_put($wpc_td . 'tpl.txt', $wpc_tk);
                    }
                }
            }
        }

        if (!empty($this->options['cache']['headers']) && $this->options['cache']['headers'] == '1') {
            $headers = array();

            foreach (headers_list() as $header) {
                $parts = explode(':', $header, 2);
                // X-WPC-CC describes this render's store verdict, and the file is shared by both
                // devices and both copy families: replayed on another copy it would name the
                // wrong one.
                if (count($parts) == 2 && strcasecmp(trim($parts[0]), 'X-WPC-CC') !== 0) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
            }

            $headersJson = json_encode($headers);
            wpc_fs_put($this->cachePath . 'headers.json', $headersJson);
        }

        // Last transform before the write: every belt above still sees the inline CSS.
        $buffer = self::wpc_css_sidecar($buffer);

        if (function_exists('gzencode')) {
            $this->saveGzCache($buffer, $prefix, $wpc_copy_family, $wpc_reason);
        }


        do_action('wpc_cache_buffer_ready', $buffer, $url ?? '', $prefix);

        $purge_rules['type-lists'] = $type_lists;
        update_option('wps_ic_purge_rules', $purge_rules, false);

        return $buffer;
    }

    public function cacheLoggedIn()
    {

        if (!empty($this->options['cache']['cache-logged-in']) && $this->options['cache']['cache-logged-in'] == '1') {
            return true;
        }

        return false;
    }

    public function hasRecentPostsWidget($buffer)
    {
        if (empty($buffer)) {
            return false;
        }

        // Primary WordPress recent posts widget identifiers
        $primary_markers = ['widget_recent_entries', 'wp-block-latest-posts', 'class="recent-posts'];

        // Check for definitive recent posts markers first
        foreach ($primary_markers as $marker) {
            if (strpos($buffer, $marker) !== false) {
                return true;
            }
        }

        // Check for specific shortcodes that display recent posts
        if (strpos($buffer, '[recent_posts') !== false || strpos($buffer, '[display-posts') !== false) {
            return true;
        }

        return false;
    }

    public function is_mobile()
    {
        // v7.10.671 — single shared detector so the cache bucket can never disagree with the
        // crit device (wps_rewriteLogic::isMobile). Existing broad set kept as fail-open fallback.
        if (function_exists('wpc_ua_is_mobile')) {
            return wpc_ua_is_mobile();
        }

        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
            if (strpos($agent, 'ipad') !== false || strpos($agent, 'tablet') !== false
                || strpos($agent, 'windows phone') !== false || strpos($agent, 'mobile') !== false) {
                return true;
            }
            if (preg_match('#^.*(2.0\ MMP|240x320|400X240|mobile|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $agent) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($agent, 0, 4))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write one copy. $family is '' for the public family or the local-only prefix (see
     * wpc_copy_names). The copy lands in one atomic rename; after it, the other family's copy of
     * the same device is removed, so a reader never finds a page that is both. $reason is the
     * store verdict's reason; a local-only copy keeps it beside itself for the serve header
     * (wpc_copy_reason_label()).
     */
    public function saveGzCache($buffer, $prefix, $family = '', $reason = '')
    {
        if (!empty($_GET['disable_cache'])) {
            return true;
        }
        $family = (string) $family;
        // v7.10.660 (B3) — fold-split wrap at cache-write, behind wpc_fold_split (default false).
        // The CACHED document is the one that gets split; the §4 stamper restores it in-viewport.
        // buildTemplateDocument is a byte-verbatim port of the service planner (t660 differential
        // parity vs the shipped fold-split.js), so the plugin reproduces exactly what the service
        // render-verified before it published the artifact. Inert by default; fail-open on all.
        if (!function_exists('wpc_fs_maybe_wrap_below_fold')) {
            @include_once __DIR__ . '/fold-split.php';
        }
        if (function_exists('wpc_fs_maybe_wrap_below_fold')) {
            $buffer = wpc_fs_maybe_wrap_below_fold($buffer);
        }
        if (!is_string($buffer) || strlen($buffer) < 1024 || stripos($buffer, '</html>') === false) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gz-drop-body-floor', '', '', ['variant' => (string) $prefix, 'len' => is_string($buffer) ? strlen($buffer) : -1]);
            }
            return $buffer;
        }


        $wpc_names = wpc_copy_names($this->cachePath, (string) $prefix, $family);
        $final = $wpc_names['gzip'];
        // v7.10.647 — Brotli pairing invariant (R1): the _br sibling dies BEFORE any
        // html write; it may only be recreated by the land handler against the md5
        // sidecar written below. br present => paired, structurally.
        @unlink($wpc_names['br']);
        $tmp   = $final . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 8);
        $fp = @fopen($tmp, 'w+');
        if ($fp === false) {
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('warm-drop-fopen', '', '', ['variant' => (string) $prefix, 'path' => substr($final, -60)]);
            }
            return $buffer;
        }
        fwrite($fp, gzencode($buffer, 8));
        fclose($fp);
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('warm-drop-rename', '', '', ['variant' => (string) $prefix, 'path' => substr($final, -60)]);
            }
        } else {
            wpc_fs_put($wpc_names['md5'], md5($buffer));
            // The file serve names the reason this copy is private (X-WPC-CC), not a fixed label
            // (webdesign4u.com.au, 2026-09-28: a device-mix copy was served as local-only-critless).
            if ($family !== '' && preg_match('/^[a-z0-9-]{1,40}$/', (string) $reason)) {
                wpc_fs_put($wpc_names['reason'], (string) $reason);
            } else {
                @unlink($wpc_names['reason']);
            }
            foreach (['rewarm', 'stale_br', 'stale_gzip', 'stale_html'] as $copyKey) {
                @unlink($wpc_names[$copyKey]);
            }
            // The page is now exactly one family: the other one's copy, stale copy and rewarm
            // counter for this device go.
            foreach (wpc_copy_names($this->cachePath, (string) $prefix, $family === '' ? WPC_COPY_FAMILY_LOCALONLY : '') as $wpc_other) {
                @unlink($wpc_other);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('copy-written', (string) $this->urlKey, '', [
                    'family' => $family === '' ? 'public' : 'localonly',
                    'device' => (string) $prefix === '' ? 'desktop' : rtrim((string) $prefix, '_'),
                ]);
            }
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('warm-wrote', '', '', ['variant' => (string) $prefix, 'bytes' => (int) @filesize($final)]);
            }
            // v7.21.288 — WRITE RECEIPT, every successful write (warm or visitor): the one
            // fact that splits "writes never happen" from "writes happen, reads miss"
            // (staging: no X-Cache-By all day, cause invisible). Cheap no-autoload option.
            if (function_exists('update_option')) {
                update_option('wpc_cache_lastwrite288', [
                    't' => time(), 'key' => (string) $this->urlKey, 'variant' => (string) $prefix,
                    'bytes' => (int) @filesize($final), 'path_tail' => substr($final, -80),
                ], false);
            }
        }


        if ($family === '') {
            $this->writeStaticMirror($buffer, $prefix);
        } else {
            // The mirror is served by the web server with the mirror's own public headers, so a
            // local-only page has none, and an older public one for this URL must go.
            $this->unlinkStaticMirrorCopy($prefix);
        }

        return $buffer;
    }

    /** Remove the static-mirror copy of the current URL for one device, if the mirror is on. */
    private function unlinkStaticMirrorCopy($prefix)
    {
        if (!apply_filters('wpc_static_serve', defined('WPC_STATIC_SERVE') && WPC_STATIC_SERVE) || !defined('WPS_IC_CACHE')) {
            return;
        }
        $host = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : (string) ($_SERVER['HTTP_HOST'] ?? '');
        $uri = rtrim((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/');
        if ($host === '' || strpos($uri, '..') !== false || strpos($uri, "\0") !== false || strpos($host, '/') !== false) {
            return;
        }
        $device = (strpos((string) $prefix, 'mobile') !== false) ? 'mobile_' : '';
        $removed = 0;
        foreach (['index.html_gzip', 'index.html'] as $name) {
            $path = WPS_IC_CACHE . $host . $uri . '/' . $device . $name;
            if (@is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        if ($removed && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('mirror-unlinked', (string) $this->urlKey, '', ['reason' => 'localonly', 'device' => $device === '' ? 'desktop' : 'mobile']);
        }
    }


    private function writeStaticMirror($buffer, $prefix)
    {
        if (!apply_filters('wpc_static_serve', defined('WPC_STATIC_SERVE') && WPC_STATIC_SERVE)) {
            return;
        }
        // nginx ignores the .htaccess that governs mirror responses, so a mirror file there
        // serves with NO headers (CF default-caches it ~2h — the white-screen pin vector) and
        // our PHP read floors never see it. Stand down AND retire any files already written.
        if (stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'nginx') !== false
            && !apply_filters('wpc_mirror_on_nginx', false)) {
            $mirrorHost = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
            $mirrorPath = rtrim(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/');
            if ($mirrorHost !== '' && strpos($mirrorPath, '..') === false && strpos($mirrorPath, "\0") === false) {
                foreach (['index.html_gzip', 'mobile_index.html_gzip', 'index.html', 'mobile_index.html'] as $mirrorFileName) {
                    $mirrorFilePath = WPS_IC_CACHE . $mirrorHost . $mirrorPath . '/' . $mirrorFileName;
                    if (@file_exists($mirrorFilePath)) {
                        @unlink($mirrorFilePath);
                    }
                }
            }
            return;
        }
        // Canonical host (matches the htaccess $http_host = home_url host AND the dual-purge below). Using
        // the request HTTP_HOST would mis-key alias/www hits vs the fixed htaccess rule.
        $host = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return;
        }
        $uri  = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $uri  = rtrim($uri, '/');
        // Security: never let a crafted URI escape the cache dir. A '..' or NUL → skip the mirror
        // (the request still serves fine via the PHP drop-in). Normal WP paths are unaffected.
        if (strpos($uri, '..') !== false || strpos($uri, "\0") !== false || strpos($host, '/') !== false) {
            return;
        }
        // Map our variant prefix ('mobile-webp_' | 'mobile_' | 'webp_' | '') → the htaccess variant.
        $htPrefix = (strpos((string) $prefix, 'mobile') !== false) ? 'mobile_' : '';
        $dir = WPS_IC_CACHE . $host . $uri . '/';
        if (!file_exists($dir)) {
            @mkdir(rtrim($dir, '/'), 0777, true);
        }
        if (!is_dir($dir)) {
            return;
        }
        $final = $dir . $htPrefix . 'index.html' . '_gzip';
        if (!is_string($buffer) || strlen($buffer) < 1024 || stripos($buffer, '</html>') === false) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('mirror-drop-body-floor', '', '', ['len' => is_string($buffer) ? strlen($buffer) : -1]);
            }
            return;
        }
        $tmp   = $final . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 8);
        $fp = @fopen($tmp, 'w+');
        if ($fp === false) {
            return;
        }
        fwrite($fp, gzencode($buffer, 8));
        fclose($fp);
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
        }
        // Each mirror dir gets its OWN .htaccess carrying THIS page's per-URL tag, so a per-URL purge
        // evicts exactly this page and a homepage purge does not over-evict subpages. $uri is
        // '' for the homepage (host-root dir), '/path' otherwise; the tag ignores the scheme.
        self::ensureStaticMirrorHeaderHtaccess($dir, 'https://' . $host . ($uri === '' ? '/' : $uri . '/'));
    }


    public static function wpc_mirror_url_tag($url)
    {
        // MUST equal wpc_cf_url_tag() (the purge side) byte-for-byte, or the crit-land tag purge
        // misses this mirror-served copy. Delegate to it when loaded (single source of truth); the
        // fallback replicates its host/path normalization exactly for load-order safety.
        if (function_exists('wpc_cf_url_tag')) {
            return wpc_cf_url_tag($url);
        }
        $p    = parse_url((string) $url);
        $host = strtolower((string) (isset($p['host']) ? $p['host'] : ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        $path = (isset($p['path']) && $p['path'] !== '') ? (string) $p['path'] : '/';
        $path = '/' . trim($path, '/');
        if ($path !== '/') { $path .= '/'; }
        return 'wpc-u-' . substr(md5($host . $path), 0, 20);
    }

    public static function ensureStaticMirrorHeaderHtaccess($dirOverride = null, $urlOverride = null)
    {
        if (!defined('WPS_IC_CACHE') || !function_exists('home_url')) {
            return false;
        }
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host === '' || strpos($host, '/') !== false) {
            return false;
        }
        // v7.10.673 — this .htaccess governs ONE mirror dir (the page it was written for), so it
        // carries THAT page's per-URL tag. Default (no args) = host root = homepage. Each mirror
        // subdir gets its own .htaccess (see writeStaticMirror), overriding the inherited homepage tag.
        $mirrorUrl = ($urlOverride !== null && $urlOverride !== '') ? (string) $urlOverride : home_url('/');
        $dir  = ($dirOverride !== null && $dirOverride !== '')
            ? rtrim((string) $dirOverride, '/') . '/'
            : rtrim(WPS_IC_CACHE, '/') . '/' . $host . '/';
        $file = $dir . '.htaccess';
        $urlTag = self::wpc_mirror_url_tag($mirrorUrl);

        // Cache-Control pin, so existing mirrors must be REWRITTEN once. Marker bump = one more
        // rewrite pass, self-healing via the same every-mirror-write ensure.


        // REVALIDATED on every hit — where max-age=60 pages served HIT at ~20ms). 60s keeps the
        // original protection (explicit header still beats a host's blanket month-long expiry;


        // v7.10.569 — THE CROWN GOES IN THE MARKER, so the existing write-once ensure becomes the
        // expiry mechanism. .559 baked s-maxage=0 here on the reasoning that a static file cannot
        // re-evaluate the purge crown. True, but the file does not have to: fold the crown-derived
        // value into the marker and a change of crown state no longer matches, which triggers the
        // same rewrite path that already exists. Crown lapses -> next mirror write emits s-maxage=0.
        $edgeMaxAge = function_exists('wpc_edge_smaxage') ? (int) wpc_edge_smaxage() : 0;
        // v8 + the per-URL tag in the marker → existing v7 (constant-tag) mirrors rewrite once, and a
        // dir that somehow held a different URL's tag self-heals on the next write.
        // v9 (v7.10.683): + Server-Timing wpc-cache;desc=hit — mirror serves are cache serves and
        // must say so to the RUM collector (zero-PHP path emitted no marker => counted "rendered").
        $wpc_marker = '# wpc-mirror-headers-v9-s' . $edgeMaxAge . '-' . $urlTag;
        if (@file_exists($file) && strpos((string) @file_get_contents($file), $wpc_marker) !== false) {
            return true;
        }
        if (!is_dir($dir)) {
            if (!function_exists('wp_mkdir_p') || !wp_mkdir_p($dir)) {
                return false;
            }
        }


        $wpc_hma = max(0, (int) apply_filters('wpc_html_max_age', 300));
        // Same formatter and the same crown the PHP writer uses, so the two serve paths can no
        // longer hand the edge different TTLs for the same page. Measured on the flagship: a
        // CF object cached from the PHP path carried s-maxage=86400 and was still HIT at age
        // 13,239 s, while one cached from this path would have expired at 300 s — and a MISS
        // costs 1.587 s TTFB against 0.096 s on HIT. Which path warmed the edge was a coin flip.
        // wpc_edge_smaxage() is class_exists-guarded and try/catch'd; absent => 0 => .559 behaviour.
        $wpc_swr = function_exists('wpc_edge_swr') ? (int) wpc_edge_swr() : 0;
        $wpc_cc  = function_exists('wpc_cc_freshness')
            ? wpc_cc_freshness($wpc_hma, $edgeMaxAge, $wpc_swr)
            : 'public, max-age=' . $wpc_hma
                . ($edgeMaxAge > 0 ? ', s-maxage=' . $edgeMaxAge . ', stale-while-revalidate=86400'
                    : ($wpc_swr > 0 ? ', stale-while-revalidate=' . $wpc_swr : ', must-revalidate'));
        // v7.10.673 — THE MIRROR CARRIES ITS OWN PER-URL TAG. .574 tagged the mirror with only the
        // CONSTANT wpc-html, reasoning "a per-URL tag needs an md5 no .htaccess can compute" — but it
        // doesn't: PHP computes wpc_cf_url_tag() at write time and bakes it in as a literal. So a
        // per-URL crit-land purge now evicts this mirror-served copy directly (the homepage — no path,
        // so no prefix purge — depended entirely on this), no host-wide widening. It is the SAME tag
        // the PHP serve path already emits, so every serve path is now purge-identical.
        $c = $wpc_marker . ' — marks + governs responses served straight from this static mirror (zero PHP).' . PHP_EOL
           . '<IfModule mod_headers.c>' . PHP_EOL
           . 'Header set X-Cache-By "Advanced Cache - Static"' . PHP_EOL
           . 'Header set Cache-Tag "wpc-html,' . $urlTag . '"' . PHP_EOL
           . 'Header set Cache-Control "' . $wpc_cc . '"' . PHP_EOL
           . 'Header set Server-Timing "wpc-cache;desc=hit"' . PHP_EOL
           . '</IfModule>' . PHP_EOL;
        return (bool) wpc_fs_put($file, $c);
    }

    public function getCache($prefix = '')
    {

        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return;
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

        if (function_exists('readgzfile')) {
            $gzipFile = $this->cachePath . $prefix . $baseFileName . '_gzip';
            if (file_exists($gzipFile) && is_readable($gzipFile)) {
                $this->setupCacheHeaders($this->cachePath . $prefix . $baseFileName . '_gzip', $wpc_family);
                wpc_hit_kick((string) $this->urlKey);
                if ($stalePlan === 'stale' && wpc_serve_stale_copy($this->cachePath, $prefix) && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('stale-serve-rewarm', (string) $this->urlKey, '', ['variant' => (string) $prefix]);
                }
                if (function_exists('wpc_views_24h_bump')) { wpc_views_24h_bump((string) $this->urlKey); }
                // Nginx instantly echoes readgzfile instead of saving it to variable.
                readgzfile($this->cachePath . $prefix . $baseFileName . '_gzip');
                die();
            }
        }

        if (file_exists($this->cachePath . $prefix . $baseFileName) && is_readable($this->cachePath . $prefix . $baseFileName)) {
            $this->setupCacheHeaders($this->cachePath . $prefix . $baseFileName, $wpc_family);
            wpc_hit_kick((string) $this->urlKey);
            if ($stalePlan === 'stale' && wpc_serve_stale_copy($this->cachePath, $prefix) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('stale-serve-rewarm', (string) $this->urlKey, '', ['variant' => (string) $prefix]);
            }
            if (function_exists('wpc_views_24h_bump')) { wpc_views_24h_bump((string) $this->urlKey); }
            readfile($this->cachePath . $prefix . $baseFileName);
            die();
        }
    }

    /**
     * session-headers-stripped {via, set_cookie, pragma}: a public page is served without the
     * Set-Cookie / Pragma another plugin's session start put on the response, because a shared
     * cache refuses or distrusts a copy that carries them. Only this reader and the render strip
     * them (the drop-in serves before any plugin can start a session). Another plugin sends them
     * on every request of such a site, so the line is sampled once an hour per page.
     */
    public static function wpc_log_session_headers_stripped($via, $withCookies = true)
    {
        if (!function_exists('headers_list') || !function_exists('wpc_belt_receipt')) {
            return;
        }
        $setCookie = 0;
        $pragma = 0;
        foreach ((array) headers_list() as $line) {
            $name = strtolower(trim(strtok((string) $line, ':')));
            if ($name === 'set-cookie' && $withCookies) {
                $setCookie++;
            } elseif ($name === 'pragma') {
                $pragma++;
            }
        }
        if (($setCookie + $pragma) > 0) {
            wpc_belt_receipt('session-headers-stripped', ['via' => (string) $via, 'set_cookie' => $setCookie, 'pragma' => $pragma], true);
        }
    }

    /**
     * Headers for a copy served from the file. $family is the copy family the reader found: a
     * local-only copy goes out private, with no s-maxage and no Cache-Tag, so no shared cache
     * holds it on the second request either — the file carries the verdict the render made.
     */
    public function setupCacheHeaders($cache_filepath, $family = '')
    {
        // A session cookie (started by any plugin at boot) rides every PHP response and
        // makes CDNs refuse to store it — cached-file serves are anonymous by definition.
        // Same for the session engine's auto-sent Pragma: no-cache.
        if (function_exists('header_remove') && apply_filters('wpc_strip_setcookie_on_public', true)) {
            self::wpc_log_session_headers_stripped('reader');
            @header_remove('Set-Cookie');
            @header_remove('Pragma');
        }
        wpc_send_header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');

        if ((string) $family !== '') {
            wpc_send_header('Cache-Control: ' . WPC_CC_LOCAL_ONLY);
            wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            wpc_send_header('X-WPC-CC: local-only-' . wpc_copy_reason_label($cache_filepath));
            wpc_send_header('Server-Timing: wpc-cache;desc=hit', false);
            wpc_send_header('X-Cache-By: Advanced Cache - Gzip');
            return;
        }

        $browserMaxAge = max(0, (int) apply_filters('wpc_html_max_age', 300));
        $edgeMaxAge = function_exists('wpc_edge_smaxage') ? wpc_edge_smaxage() : 0;
        wpc_send_header('Cache-Control: ' . wpc_cc_freshness($browserMaxAge, $edgeMaxAge,
            function_exists('wpc_edge_swr') ? wpc_edge_swr() : 0));
        wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $browserMaxAge) . ' GMT');


        $tagHost = strtolower((string) preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
        if (strpos($tagHost, 'www.') === 0) { $tagHost = substr($tagHost, 4); }
        $tagPath = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
        $tagPath = '/' . trim($tagPath, '/');
        if ($tagPath !== '/') { $tagPath .= '/'; }
        if ($tagHost !== '') {
            wpc_send_header('Cache-Tag: wpc-html,wpc-u-' . substr(md5($tagHost . $tagPath), 0, 20), false);
        }


        // (stored headers), readable same-origin via the navigation entry's serverTiming.
        wpc_send_header('Server-Timing: wpc-cache;desc=hit', false);

        wpc_send_header('X-Cache-By: Advanced Cache - Gzip');
    }

    public function removeCacheFiles($post_id, $mode = null)
    {
        if ($post_id == 'home') {
            $post_id = 0;
        }

        if ($post_id == 'all') {
            // The journal must survive the event it records — a purge that wipes its own
            // receipt makes every storm undiagnosable. Keep the last 64KB across the wipe.
            $journalFile = function_exists('wpc_cflog_path') ? wpc_cflog_path() : '';
            $journalTail = function_exists('wpc_cflog_tail') ? wpc_cflog_tail(1048576) : '';
            // PURGE = page copies only. The derived asset stores (css/js/used-css) are
            // CONTENT-ADDRESSED (hashed filenames; icv is a query-buster) — deleting them
            // orphans every asset URL still referenced by LiteSpeed/CF/browser-cached HTML
            // (naked-page class) and forces a full regeneration herd on a busy origin.
            // They are collected by wpc_processed_copy_gc() (defines.php) instead.
            $isSoftPurge = function_exists('wpc_purge_is_soft') && wpc_purge_is_soft($mode);
            if ($isSoftPurge) {
                wpc_mark_site_stale();
                self::wpc_stale_walk_after_response();
            } else {
            $keepEntries = ['css', 'js', 'wpc-cflog.php'];
            $cacheRoot = rtrim(WPS_IC_CACHE, '/');
            // v7.10.530 — RENAME, THEN DELETE. The recursive unlink below blocks every
            // concurrent render trying to WRITE into the same tree: receipted on the flagship as
            // requests queueing ~44 s and draining together the instant the purge finished, with
            // load 0.6 and zero HTTP — I/O contention, not CPU. A directory rename is atomic and
            // costs microseconds, so renders see an empty tree immediately and the expensive
            // unlink happens after the response is flushed. Same trick the sane cache layers use.
            $tombstoneDir = '';
            if (apply_filters('wpc_purge_rename_first', true)) {
                foreach ((array) @scandir($cacheRoot) as $renameEntry) {
                    if ($renameEntry === '.' || $renameEntry === '..' || in_array($renameEntry, $keepEntries, true)
                        || strpos($renameEntry, '.purging-') === 0) {
                        continue;
                    }
                    $renameSource = $cacheRoot . '/' . $renameEntry;
                    if (!@is_dir($renameSource)) {
                        continue;
                    }
                    if ($tombstoneDir === '') {
                        $tombstoneDir = $cacheRoot . '/.purging-' . substr(md5(uniqid('', true)), 0, 10);
                        if (!@mkdir($tombstoneDir, 0777, true)) {
                            $tombstoneDir = '';
                            break;
                        }
                    }
                    // A failed rename simply leaves it for the ordinary unlink pass below.
                    @rename($renameSource, $tombstoneDir . '/' . $renameEntry);
                }
            }
            foreach ((array) @scandir($cacheRoot) as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, $keepEntries, true)) {
                    continue;
                }
                if (strpos($entry, '.purging-') === 0) {
                    continue; // tombstones are drained post-response, never inline
                }
                $entryPath = $cacheRoot . '/' . $entry;
                is_dir($entryPath) ? self::removeDirectory($entryPath) : @unlink($entryPath);
            }
            // Drain every tombstone (this one and any orphaned by a killed request) after the
            // response is flushed, so a crash can never leak them permanently.
            if (function_exists('register_shutdown_function')) {
                register_shutdown_function(function () use ($cacheRoot) {
                    if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) { wpc_finish_request(); }
                    if (function_exists('set_time_limit')) { @set_time_limit(120); }
                    $drainedCount = 0;
                    $seenTombstones = [];
                    $GLOBALS['wpc_tombstone_drain_deadline'] = microtime(true) + 10.0;
                    while (microtime(true) <= $GLOBALS['wpc_tombstone_drain_deadline']) {
                        $foundTombstone = false;
                        foreach ((array) @scandir($cacheRoot) as $tombstone) {
                            if (strpos((string) $tombstone, '.purging-') !== 0 || isset($seenTombstones[$tombstone])) {
                                continue;
                            }
                            $seenTombstones[$tombstone] = 1;
                            if (microtime(true) > $GLOBALS['wpc_tombstone_drain_deadline']) {
                                break 2;
                            }
                            $foundTombstone = true;
                            $tombstonePath = $cacheRoot . '/' . $tombstone;
                            foreach ((array) @scandir($tombstonePath) as $tombstoneChild) {
                                if (strpos((string) $tombstoneChild, '.purging-') === 0) {
                                    @rename($tombstonePath . '/' . $tombstoneChild, $cacheRoot . '/.purging-' . substr(md5(uniqid('', true)), 0, 10));
                                }
                            }
                            self::removeDirectory($tombstonePath);
                            $drainedCount++;
                        }
                        if (!$foundTombstone) {
                            break;
                        }
                    }
                    unset($GLOBALS['wpc_tombstone_drain_deadline']);
                    if ($drainedCount && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('purge-tombstone-drained', '', '', ['n' => $drainedCount]);
                    }
                });
            }
            if (function_exists('wpc_stale_hard_purge_done')) {
                wpc_stale_hard_purge_done();
            }
            }
            self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/');
            // Template-keyed used-css is MUTABLE under the same key (no content version in
            // the contract yet) — the pickup skips refetch on tpl match, so service-side
            // artifact fixes never propagate. Purge resets the MARKERS only (files stay so
            // cached pages keep valid URLs; refetch overwrites in place). hawkeye receipt:
            // stored blob still pre-fix while service shelf carried the corrected artifact.
            if (defined('WPS_IC_CRITICAL')) {
                $usedTplMarkers = (array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/used_tpl.txt');
                $usedTplRemoved = 0;
                foreach ($usedTplMarkers as $usedTplMarker) {
                    if ($usedTplRemoved >= 200) { break; }
                    if (@unlink($usedTplMarker)) { $usedTplRemoved++; }
                }
            }
            if ($journalTail !== '' && $journalFile !== '') {
                @mkdir(rtrim(WPS_IC_CACHE, '/'), 0777, true);
                if (!@is_file($journalFile)) {
                    wpc_fs_put($journalFile, WPC_CFLOG_HEADER . $journalTail);
                }
            }

        } else {
            if ($post_id != 0) {
                $url = get_permalink($post_id);
            } else {
                $url = home_url();
            }

            $urlKey = $this->url_key_class->setup($url);
            if (function_exists('wpc_purge_is_soft') && wpc_purge_is_soft($mode)) {
                $renamedCount = self::wpc_stale_mark_page_dir(WPS_IC_CACHE . $urlKey);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('purge-soft-url', (string) $urlKey, '', ['renamed' => $renamedCount]);
                }
            } else {
                self::removeDirectory(WPS_IC_CACHE . $urlKey);
                self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/' . $urlKey);
                self::removeStaticMirror($url);
            }
        }
    }

    public static function wpc_stale_mark_page_dir($dir)
    {
        $dir = rtrim((string) $dir, '/');
        $n = 0;
        if ($dir === '' || !@is_dir($dir)) {
            return 0;
        }
        // Both devices, both copy families: a local-only copy is stale-marked like any other and
        // keeps its family ('localonly_stale.html_gzip'), so the stale serve stays private.
        foreach (['', 'mobile_', WPC_COPY_FAMILY_LOCALONLY, 'mobile_' . WPC_COPY_FAMILY_LOCALONLY] as $copy_prefix) {
            foreach (['index.html_br', 'index.html_gzip', 'index.html'] as $copy_file) {
                $source_path = $dir . '/' . $copy_prefix . $copy_file;
                if (@is_file($source_path) && @rename($source_path, $dir . '/' . $copy_prefix . str_replace('index.html', 'stale.html', $copy_file))) {
                    $n++;
                }
            }
            @unlink($dir . '/' . $copy_prefix . 'index.html_md5');
        }
        return $n;
    }

    public static function wpc_stale_walk_cache_tree($epoch, $budget_s = 20.0)
    {
        if (!defined('WPS_IC_CACHE')) {
            return ['n' => 0, 'done' => false];
        }
        $root = rtrim(WPS_IC_CACHE, '/');
        $t0 = microtime(true);
        $n = 0;
        $done = true;
        try {
            foreach ((array) @scandir($root) as $top_entry) {
                if ($top_entry === '.' || $top_entry === '..' || $top_entry === 'css' || $top_entry === 'js' || strpos($top_entry, '.purging-') === 0 || !@is_dir($root . '/' . $top_entry)) {
                    continue;
                }
                $page_dirs = [$root . '/' . $top_entry];
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $top_entry, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $entry) {
                    if ($entry->isDir()) {
                        $page_dirs[] = $entry->getPathname();
                    }
                }
                foreach ($page_dirs as $page_dir) {
                    $newest_mtime = 0;
                    foreach (['', 'mobile_', WPC_COPY_FAMILY_LOCALONLY, 'mobile_' . WPC_COPY_FAMILY_LOCALONLY] as $copy_prefix) {
                        $newest_mtime = max($newest_mtime, (int) @filemtime($page_dir . '/' . $copy_prefix . 'index.html'), (int) @filemtime($page_dir . '/' . $copy_prefix . 'index.html_gzip'));
                    }
                    if ($newest_mtime > 0 && $newest_mtime < (int) $epoch) {
                        $n += self::wpc_stale_mark_page_dir($page_dir);
                    }
                    if ((microtime(true) - $t0) > $budget_s) {
                        $done = false;
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $done = false;
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('purge-soft-walk', '', '', ['renamed' => $n, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'done' => $done ? 1 : 0]);
        }
        return ['n' => $n, 'done' => $done];
    }

    public static function wpc_stale_walk_after_response()
    {
        static $armed = false;
        if ($armed || !function_exists('add_action')) {
            return false;
        }
        $armed = true;
        $epoch = time();
        add_action('shutdown', function () use ($epoch) {
            if (function_exists('wpc_finish_request') && empty($GLOBALS['wpc_response_released'])) {
                wpc_finish_request();
            }
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
            @set_time_limit(60);
            self::wpc_stale_walk_cache_tree($epoch);
        }, 97);
        return true;
    }


    public static function removeStaticMirror($url)
    {
        if (!apply_filters('wpc_static_serve', defined('WPC_STATIC_SERVE') && WPC_STATIC_SERVE)) {
            return;
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url((string) $url) : parse_url((string) $url);
        $host  = isset($parts['host']) ? (string) $parts['host'] : '';
        if ($host === '' || strpos($host, '/') !== false) {
            return;
        }
        $path = rtrim(isset($parts['path']) ? (string) $parts['path'] : '', '/');
        if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            return;
        }
        if ($path === '') {
            @unlink(WPS_IC_CACHE . $host . '/index.html' . '_gzip');
            @unlink(WPS_IC_CACHE . $host . '/mobile_index.html' . '_gzip');
        } else {
            self::removeDirectory(WPS_IC_CACHE . $host . $path);
        }
    }

    public static function removeDirectory($path)
    {

        $path = rtrim($path, '/');
        if (defined('WPS_IC_CACHE') && rtrim(WPS_IC_CACHE, '/') === $path) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-root-refused', '', $path, []);
            }
            return;
        }
        $files = glob($path . '/*');

        if (!empty($files)) {
            foreach ($files as $file) {
                // v7.10.530b — the deadline must be honoured INSIDE the recursion, not around it.
                // critical/ holds ~20,000 files on the flagship, so one call to this function was
                // the entire drain: a budget checked only between top-level tombstones could never
                // fire. Leftovers are inert and swept by the next purge.
                if (!empty($GLOBALS['wpc_tombstone_drain_deadline']) && microtime(true) > $GLOBALS['wpc_tombstone_drain_deadline']) {
                    return;
                }
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }

        $files = glob($path . '/*');

        if (is_dir($path) && empty($files)) {
            @rmdir($path);
        }
    }

    public function removeCacheFilesByKey($urlKey)
    {
        self::removeDirectory(WPS_IC_CACHE . $urlKey);
        self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/' . $urlKey);
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
        wpc_fs_put(WPS_IC_COMBINE . $urlKey . '/.wpc-stale', (string) time());
    }

    public function removeCriticalFiles($post_id)
    {
        // v7.21.245 — REMOVE MEANS VISIBLY OFF. Deleting files alone was undone in minutes
        // by the self-heal lanes (pointer/doctor re-land) and by CF's cached HTML — "remove
        // crit isn't working". Arm the bypass window so renders serve crit-less immediately;
        // the .690 broken-promise lift still bounds it.
        if (function_exists('wpc_crit_bypass_start')) {
            wpc_crit_bypass_start();
        }


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


        delete_transient('wpc_critical_key_' . $urlKey);
        delete_transient('wpc_critical_uuid_' . $urlKey);
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

    private function getAllHeaders()
    {
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            } elseif ($name == 'CONTENT_TYPE' || $name == 'CONTENT_LENGTH') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

}