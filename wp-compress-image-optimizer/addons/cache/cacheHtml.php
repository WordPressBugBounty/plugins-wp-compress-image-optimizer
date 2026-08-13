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
            return max(0, (int) apply_filters('wpc_html_swr', 86400));
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


    public function cacheExists($prefix = '')
    {
        if (!empty($_GET['disable_cache'])) {
            return false;
        }

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
        } else {
            return false;
        }
    }


    /**
     * Just verify it's not some page test as we don't want those to cache HTML
     * @return void
     */
    public function pageTest()
    {
        return false;
    }

    // The LCP is often a CSS background whose URL already sits in the inlined crit —
    // discoverable but queued at low priority (perkzilla receipt: 1,790ms resource
    // delay, 110ms load). Preload the first ATF background image found in crit with
    // fetchpriority=high; crit regenerates with the page, so the URL is always live.
    public static function critBgPreload($buffer)
    {
        if (!is_string($buffer) || strpos($buffer, 'wpc-lcp-bg-preload') !== false
            || strpos($buffer, 'id="wpc-crit-bg-preload"') !== false
            || !apply_filters('wpc_crit_bg_preload', true)
            || !preg_match('/<style[^>]*id="wpc-critical-css"[^>]*>(.*?)<\/style>/s', $buffer, $wpc_pm)) {
            return $buffer;
        }
        if (!preg_match('/background(?:-image)?\s*:\s*[^;{}]*url\(\s*([\'"]?)((?:https?:)?\/[^)\'"]{8,500}\.(?:jpe?g|png|webp|avif|svg)(?:\?[^)\'"]{0,120})?)\1/i', $wpc_pm[1], $wpc_pu)) {
            return $buffer;
        }
        $wpc_ph = html_entity_decode($wpc_pu[2], ENT_QUOTES);
        if (strpos($wpc_ph, '//') === 0) {
            $wpc_ph = 'https:' . $wpc_ph;
        } elseif ($wpc_ph[0] === '/' && !empty($_SERVER['HTTP_HOST'])) {
            $wpc_ph = 'https://' . $_SERVER['HTTP_HOST'] . $wpc_ph;
        }
        // Same hero, two formats: the atf lane preloads the base url() (.jpg) while THIS lane
        // preloads the image-set candidate the browser actually paints (.webp) — the atf copy
        // is then a dead high-priority fetch (PSI: unused preload). Same stem => drop the twin.
        $wpc_stem840 = preg_replace('/\.[a-z0-9]+$/i', '', basename((string) parse_url($wpc_ph, PHP_URL_PATH)));
        if ($wpc_stem840 !== '' && preg_match('/<link\b[^>]*id="wpc-atf-bg-preload"[^>]*>\s*/i', $buffer, $wpc_atfm840)
            && strpos($wpc_atfm840[0], $wpc_stem840 . '.') !== false) {
            $buffer = str_replace($wpc_atfm840[0], '', $buffer);
        }
        $wpc_pt = '<link id="wpc-crit-bg-preload" rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_ph) . '">';
        return str_replace($wpc_pm[0], $wpc_pt . $wpc_pm[0], $buffer);
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
            $wpc_st = preg_replace_callback('/<[a-z][a-z0-9-]*\b[^>]*\bclass=["\'][^"\']*bricks-lazy-hidden[^"\']*["\'][^>]*>/i', function ($m) {
                return preg_replace('/\s*\bbricks-lazy-hidden\b/', '', $m[0]);
            }, $buffer);
            return is_string($wpc_st) ? $wpc_st : $buffer;
        }
        $wpc_out = '';
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
                    $wpc_decl .= strtolower($wpc_d[1]) . ':' . $wpc_v . ' !important;';
                }
                if ($wpc_decl !== '') {
                    $wpc_out .= implode(',', $wpc_sels) . '{' . $wpc_decl . '}';
                }
            }
        }
        if ($wpc_out !== '') {
            // @layer: the theme suppressor is layered (!important-in-layer beats any unlayered
            // !important at any specificity); earliest-declared layer wins among importants,
            // and this style parses before the theme sheet ever applies.
            $buffer = str_replace($wpc_bm[0], $wpc_bm[0] . '<style id="wpc-bricks-unveil">@layer wpc-unveil{' . $wpc_out . '}</style>', $buffer);
        }
        return $buffer;
    }

    // var() in crit resolves only if its definition is present at paint — inline the
    // custom-property blocks from every deferred local sheet (mtime-cached siblings).
    // Render-path pass: must run on every crit-bearing buffer, not only cache writes.
    public static function varsGuard($buffer)
    {
        if (!is_string($buffer)
            || (strpos($buffer, 'id="wpc-critical-css"') === false && strpos($buffer, "id='wpc-critical-css'") === false)
            || strpos($buffer, 'wpc-vars-guard') !== false || !apply_filters('wpc_vars_guard', true)) {
            return $buffer;
        }
        $wpc_vg = '';
        $wpc_vgn = 0;
        if (preg_match_all('/<link\b[^>]*rel=["\']wpc-[a-z-]*stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $buffer, $wpc_vgm)) {
            foreach (array_unique($wpc_vgm[1]) as $wpc_vgh) {
                if ($wpc_vgn >= 12 || strlen($wpc_vg) > 24576) {
                    break;
                }
                $wpc_vgu = html_entity_decode($wpc_vgh, ENT_QUOTES);
                $wpc_vgc = strrpos($wpc_vgu, 'wp-content/');
                if ($wpc_vgc === false || !preg_match('/\.css(\?|$)/', $wpc_vgu)) {
                    continue;
                }
                $wpc_vgr = (string) preg_replace('/[?#].*$/', '', substr($wpc_vgu, $wpc_vgc));
                if (strpos($wpc_vgr, '..') !== false) {
                    continue;
                }
                $wpc_vgp = trailingslashit(ABSPATH) . $wpc_vgr;
                if (!@is_readable($wpc_vgp)) {
                    continue;
                }
                $wpc_vgmt = (int) @filemtime($wpc_vgp);
                // .vars2: media-AWARE extraction — defs inside @media keep their wrapper
                // (dm receipt: Elementor's mobile --flex-wrap vars emitted bare broke desktop).
                $wpc_vgs = $wpc_vgp . '.vars2.css';
                if (!@is_readable($wpc_vgs) || (int) @filemtime($wpc_vgs) < $wpc_vgmt) {
                    $wpc_vgcss = (string) @file_get_contents($wpc_vgp);
                    $wpc_vgout = '';
                    if ($wpc_vgcss !== '' && strpos($wpc_vgcss, '--') !== false) {
                        $wpc_vgemit = function ($chunk, $media) use (&$wpc_vgout) {
                            if (strlen($wpc_vgout) > 16384 || strpos($chunk, '--') === false) {
                                return;
                            }
                            if (preg_match_all('/[^{}@]+\{[^{}]*--[\w-]+\s*:[^{}]*\}/', $chunk, $wpc_vgb2)) {
                                $wpc_blob = '';
                                foreach ($wpc_vgb2[0] as $wpc_vgblk) {
                                    if (strlen($wpc_vgout) + strlen($wpc_blob) > 16384) {
                                        break;
                                    }
                                    $wpc_blob .= trim($wpc_vgblk);
                                }
                                if ($wpc_blob !== '') {
                                    $wpc_vgout .= $media !== '' ? '@media ' . $media . '{' . $wpc_blob . '}' : $wpc_blob;
                                }
                            }
                        };
                        $wpc_off = 0;
                        $wpc_len = strlen($wpc_vgcss);
                        while (preg_match('/@media([^{]+)\{/i', $wpc_vgcss, $wpc_vm, PREG_OFFSET_CAPTURE, $wpc_off)) {
                            $wpc_mstart = (int) $wpc_vm[0][1];
                            $wpc_vgemit(substr($wpc_vgcss, $wpc_off, $wpc_mstart - $wpc_off), '');
                            $wpc_i = $wpc_mstart + strlen($wpc_vm[0][0]);
                            $wpc_d = 1;
                            while ($wpc_i < $wpc_len && $wpc_d > 0) {
                                $wpc_ch = $wpc_vgcss[$wpc_i];
                                if ($wpc_ch === '{') { $wpc_d++; } elseif ($wpc_ch === '}') { $wpc_d--; }
                                $wpc_i++;
                            }
                            $wpc_vgemit(substr($wpc_vgcss, $wpc_mstart + strlen($wpc_vm[0][0]), $wpc_i - $wpc_mstart - strlen($wpc_vm[0][0]) - 1), trim((string) $wpc_vm[1][0]));
                            $wpc_off = $wpc_i;
                        }
                        $wpc_vgemit(substr($wpc_vgcss, $wpc_off), '');
                    }
                    @file_put_contents($wpc_vgs, $wpc_vgout, LOCK_EX);
                    @touch($wpc_vgs, $wpc_vgmt);
                }
                $wpc_vgtxt = (string) @file_get_contents($wpc_vgs);
                if ($wpc_vgtxt !== '') {
                    $wpc_vg .= $wpc_vgtxt;
                    $wpc_vgn++;
                }
            }
        }
        if ($wpc_vg !== '') {
            // BEFORE the first deferred-sheet link, never at </head>: the guard is a
            // fallback layer and must LOSE to the real stylesheets once they go live —
            // at equal specificity source order decides, and the 16KB extraction cap can
            // truncate late fluid overrides (clamp/vw), so a guard placed after the links
            // pins desktop-frozen typography forever (the giant-mobile-h1 class)
            $wpc_vgtag = '<style id="wpc-vars-guard">' . $wpc_vg . '</style>';
            // The guard is a fallback layer: at equal specificity source order decides, so
            // it must LOSE to the crit during the crit window AND to the real sheets once
            // live — insert before whichever of the two appears first in the head
            $wpc_vgat = -1;
            if (preg_match('/<style\b[^>]*id=["\']wpc-critical-css["\']/i', $buffer, $wpc_vgcm, PREG_OFFSET_CAPTURE)) {
                $wpc_vgat = (int) $wpc_vgcm[0][1];
            }
            if (preg_match('/<link\b[^>]*rel=["\']wpc-[a-z-]*stylesheet["\']/i', $buffer, $wpc_vgfm, PREG_OFFSET_CAPTURE)
                && ($wpc_vgat < 0 || (int) $wpc_vgfm[0][1] < $wpc_vgat)) {
                $wpc_vgat = (int) $wpc_vgfm[0][1];
            }
            if ($wpc_vgat >= 0) {
                $buffer = substr_replace($buffer, $wpc_vgtag, $wpc_vgat, 0);
            } else {
                $buffer = str_ireplace('</head>', $wpc_vgtag . '</head>', $buffer);
            }
        }
        return $buffer;
    }

    // SELF-CONSISTENCY INVARIANT: a render may defer CSS only if it carries crit.
    // Crit missing (hard-purged, mid-regen, first install) → restore every deferred
    // sheet to render-blocking — the page paints fully styled (no FOUC) and becomes
    // cacheable, so crit-less periods serve HITs instead of no-store render convoys.
    // When crit lands, the land purges this URL and crit-full copies take over.
    // Render-path pass: must run on every render, not only cache writes.
    public static function critlessUndefer($buffer, $ctx = 'render')
    {
        if (!is_string($buffer)
            || strpos($buffer, 'id="wpc-critical-css"') !== false || strpos($buffer, "id='wpc-critical-css'") !== false
            || !preg_match('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer)) {
            return $buffer;
        }
        // Scoped to <link> tags only — the loader's inline JS carries these marker
        // strings as selectors and must never be rewritten.
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
        if (function_exists('wpc_cache_first_log') && !get_transient('wpc_undefer_log')) {
            set_transient('wpc_undefer_log', 1, 300);
            wpc_cache_first_log('critless-undefer', '', '', ['variant' => $ctx]);
        }
        return $buffer;
    }

    public function saveCache($buffer, $prefix = '')
    {
        // Mint gate: never create cache/crit state for query URLs beyond marketing params.
        // v7.10.598 — one predicate, shared with the READ gate in advancedCache::getCache() and
        // derived from the same list url_key strips, so the two can no longer disagree. They did:
        // the key stripped 61 params while both gates named utm_* plus 11, so `?gbraid=…` shared
        // the clean page's artifacts and was still refused a cache entry — a full origin render
        // on every paid click. Zero extra inodes, because a stripped param collapses onto the
        // clean URL's key. Falls back to the old inline test if the class is somehow absent.
        $wpc_qs284 = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($wpc_qs284 !== '') {
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'queryIsCacheable')) {
                if (!wps_ic_url_key::queryIsCacheable($wpc_qs284, 'write')) {
                    return $buffer;
                }
            } else {
                parse_str($wpc_qs284, $wpc_qp284);
                foreach (array_keys((array) $wpc_qp284) as $wpc_qk284) {
                    $wpc_qk284 = strtolower((string) $wpc_qk284);
                    if (strpos($wpc_qk284, 'utm_') !== 0
                        && !in_array($wpc_qk284, ['fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', '_ga', 'igshid', 'ttclid'], true)) {
                        return $buffer;
                    }
                }
            }
        }


        // Live showed warm-rx (arrival) without variants materializing; every exit below now
        // says WHICH gate dropped a warm render, and the write itself logs. Warm-only, bounded.
        $wpc_is_warm66 = !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) && function_exists('wpc_cache_first_log');
        $wpc_wgate = function ($g, $x = []) use ($wpc_is_warm66, $prefix) {


            if (!empty($_SERVER['HTTP_X_WPC_DEBUG']) && !headers_sent()) {
                header('X-WPC-Cache-Save: skip-' . $g, false);
            }
            if ($wpc_is_warm66) {
                wpc_cache_first_log('warm-drop-' . $g, (string) $this->urlKey, '', array_merge(['variant' => (string) $prefix], $x));
            }
        };
        if ($wpc_is_warm66) {
            wpc_cache_first_log('warm-rx', (string) $this->urlKey, '', ['variant' => (string) $prefix]);
        }

        if (!empty($_GET['disable_cache'])) {
            $wpc_wgate('disable-param');
            return $buffer;
        }

        // A page cache file must never hold an empty or truncated document.
        if (!is_string($buffer) || strlen($buffer) < 1024 || stripos($buffer, '</html>') === false) {
            $wpc_wgate('body-floor', ['len' => is_string($buffer) ? strlen($buffer) : -1]);
            return $buffer;
        }

        // v7.10.700 — VERIFIED COPY CONTRACT, Law A: a degraded render serves once and
        // evaporates. Refusal covers all three memoizers from this one choke point: the
        // store and mirror (early return) and the CF edge (no-store beats the full-HTML
        // rule's respect_origin). The visitor still gets the page.
        if (function_exists('wpc_copy_admissible')) {
            $wpc_adm700 = wpc_copy_admissible($buffer, (string) $this->urlKey, (string) $prefix);
            if ($wpc_adm700 !== '') {
                if (!headers_sent()) {
                    header('Cache-Control: private, no-store, max-age=0', true);
                }
                $wpc_wgate('admission-' . $wpc_adm700);
                return $buffer;
            }
        }

        // v7.10.613 — observe only. Exits on the first line of a visitor render; never reads or
        // writes $buffer beyond a bounded scan on a warm/cron request, at most once a day.
        if (function_exists('wpc_lane_split_detect613')) {
            wpc_lane_split_detect613($buffer);
        }

        $buffer = self::critlessUndefer($buffer, (string) $prefix);

        $buffer = self::varsGuard($buffer);

        // Crit-full pages only; sibling files are mtime-cached.
        if (is_string($buffer)
            && strpos($buffer, 'id="wpc-critical-css"') !== false
            && apply_filters('wpc_late_faces', true)
            && ($wpc_ss210 = (int) get_option('wpc_subsets_seen', 0)) && (time() - $wpc_ss210) < 7 * DAY_IN_SECONDS) {
            $wpc_lfl210 = '';
            $wpc_n210 = 0;
            // Only families with a metric-pinned fallback (or inline subset) may leave their
            // sheet — an unpinned family's swap reflows whatever it styles.
            $wpc_pin210 = [];
            // v7.10.731 — the pin is a DECLARED fallback face, not a stack reference: an
            // undeclared "<family> Fallback" name in a stack is skipped by the browser.
            if (preg_match_all('/@font-face\s*\{[^{}]*font-family\s*:\s*[\'"]?([^;\'"}]+?) Fallback[\'"]?\s*[;}]/i', $buffer, $wpc_pfm210)) {
                foreach ($wpc_pfm210[1] as $wpc_pf210) {
                    $wpc_pin210[strtolower(trim($wpc_pf210))] = 1;
                }
            }
            if (preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"}]+)[\'"]?\s*;[^}]{0,400}data:font\/woff2/i', $buffer, $wpc_sfm210)) {
                foreach ($wpc_sfm210[1] as $wpc_sf210) {
                    $wpc_pin210[strtolower(trim($wpc_sf210))] = 1;
                }
            }
            $buffer = preg_replace_callback(
                '/<link\b[^>]*rel=["\'](?:wpc-(?:mobile-|late-)?)?stylesheet["\'][^>]*>/i',
                function ($lm) use (&$wpc_lfl210, &$wpc_n210, $wpc_pin210) {
                    if ($wpc_n210 >= 8 || !preg_match('/href=["\']([^"\']+)["\']/', $lm[0], $hm)) {
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
                    $wpc_rr210 = get_option('wpc_font_remote_ranges', []);
                    if (!is_array($wpc_rr210)) { $wpc_rr210 = []; }
                    if (!empty($wpc_rr210)) { ksort($wpc_rr210); }
                    $wpc_pfh210 = substr(md5(
                        implode(',', array_keys($wpc_pin210))
                        . (empty($wpc_rr210) ? '' : '|rr2:' . md5(serialize($wpc_rr210)))
                    ), 0, 8);
                    if (!@is_readable($sib) || (int) @filemtime($sib) < $mt
                        || strpos((string) @file_get_contents($sib, false, null, 0, 24), $wpc_pfh210) === false) {
                        // Sibling (re)builds are background-lane work; visitor renders serve the
                        // original link untouched until a warm builds it.
                        if (!((function_exists('wp_doing_ajax') && wp_doing_ajax())
                            || (defined('DOING_CRON') && DOING_CRON)
                            || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']))) {
                            return $lm[0];
                        }
                        $css = (string) @file_get_contents($path);
                        if ($css === '' || stripos($css, '@font-face') === false || stripos($css, 'url(') === false) {
                            return $lm[0];
                        }
                        $faces = '';
                        $stripped = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($fm) use (&$faces, $wpc_pin210) {
                            if (stripos($fm[0], 'url(') !== false && stripos($fm[0], 'data:') === false) {
                                if (preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $fm[0], $wpc_ffm210)
                                    && empty($wpc_pin210[strtolower(trim($wpc_ffm210[1]))])) {
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
                                $wpc_min210 = (new wps_ic_combine_css())->minifyCSS($stripped);
                                if (is_string($wpc_min210) && $wpc_min210 !== '') {
                                    $stripped = $wpc_min210;
                                }
                            } catch (\Throwable $e) {
                            }
                        }
                        @file_put_contents($sib, '/*pf:' . $wpc_pfh210 . '*/' . $stripped, LOCK_EX);
                        @file_put_contents($fsib, $faces, LOCK_EX);
                        @touch($sib, $mt);
                        @touch($fsib, $mt);
                    }
                    if (!@is_readable($fsib) || (int) @filesize($fsib) < 1) {
                        return $lm[0];
                    }
                    $wpc_n210++;
                    $base = substr($href, 0, $cp);
                    $newHref = $base . preg_replace('/\.css$/', '.nofaces.css', $rel) . '?nf=' . $mt;
                    $faceHref = $base . preg_replace('/\.css$/', '.faces.css', $rel) . '?nf=' . $mt;
                    // v7.10.714 — hrefless until the late-CSS flip: even media="not all"
                    // stylesheets download at low priority, and those fetches sit inside the
                    // paint-mark windows. The loader attaches the href at flip time.
                    $wpc_lfl210 .= '<link rel="stylesheet" data-wpc-lf-href="' . esc_url($faceHref) . '" media="not all" data-wpc-lf="1" />';
                    return str_replace($hm[1], esc_url($newHref), $lm[0]);
                },
                $buffer
            );
            if ($wpc_lfl210 !== '') {
                $buffer = str_ireplace('</head>', $wpc_lfl210 . '</head>', $buffer);
            }
        }

        // Body proven — upgrade the default-deny header so edges may cache this response.
        // This is the SINGLE header authority and it judges the FINAL buffer: an update-window
        // render that isn't armed keeps the guard's no-store (marked for observability).
        // Every withheld upgrade names its reason: X-WPC-CC header when headers are still
        // open, journal when they aren't — a no-store response must never be a mystery.
        if (empty($GLOBALS['wpc_cc_guarded'])) {
            if (!headers_sent() && !is_admin() && !(function_exists('is_user_logged_in') && is_user_logged_in())) {
                header('X-WPC-CC: unguarded-' . (isset($GLOBALS['wpc_cc_skip']) ? (string) $GLOBALS['wpc_cc_skip'] : 'never-ran'));
            }
        } elseif (headers_sent($wpc_hsf195, $wpc_hsl195)) {
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cc_hs_log')) {
                set_transient('wpc_cc_hs_log', 1, 60);
                wpc_cache_first_log('cc-headers-sent', (string) $this->urlKey, '', ['variant' => (string) $prefix,
                    'at' => basename((string) $wpc_hsf195) . ':' . (int) $wpc_hsl195]);
            }
            unset($GLOBALS['wpc_cc_guarded']);
        }
        if (!empty($GLOBALS['wpc_cc_guarded']) && !headers_sent()) {
            if (function_exists('wpc_update_window_active') && wpc_update_window_active()
                && function_exists('wpc_render_armed_for_cache') && !wpc_render_armed_for_cache($buffer)) {
                $wpc_why195 = 'crit-missing';
                if (!is_string($buffer) || $buffer === '') { $wpc_why195 = 'empty-buffer'; }
                elseif (!empty($_GET['criticalCombine'])) { $wpc_why195 = 'combine-param'; }
                elseif (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) { $wpc_why195 = 'cdn-suppressed'; }
                header('X-WPC-Update-Window: degraded');
                header('X-WPC-CC: window-unarmed-' . $wpc_why195);
                unset($GLOBALS['wpc_cc_guarded']);
            } else {
                // A Set-Cookie on a publicly-cacheable page makes CDNs refuse to store it
                // (cf-cache-status: BYPASS regardless of Cache-Control) — a plugin-started
                // PHPSESSID on anonymous renders kept the whole site edge-uncacheable. The
                // guard already limits this lane to anonymous GETs, where a session cookie
                // is cache-poison by definition.
                if (function_exists('header_remove') && apply_filters('wpc_strip_setcookie_on_public', true)) {
                    @header_remove('Set-Cookie');
                }
                $wpc_hma174 = max(0, (int) apply_filters('wpc_html_max_age', 300));
                $wpc_sm178  = function_exists('wpc_edge_smaxage') ? wpc_edge_smaxage() : 0;
                // PHP's session engine auto-sends Pragma: no-cache + a 1981 Expires on any
                // request where a plugin started a session — both override-purge here or the
                // edge honors them over our Cache-Control.
                if (function_exists('header_remove')) {
                    @header_remove('Pragma');
                }
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $wpc_hma174) . ' GMT');
                header('Cache-Control: ' . wpc_cc_freshness($wpc_hma174, $wpc_sm178,
                    function_exists('wpc_edge_swr') ? wpc_edge_swr() : 0));
                // v7.10.683 — RUM cache-layer truth for EDGE hits. A shared cache (CF, host proxy)
                // stores this render WITH its headers and replays them on every HIT — so a copy
                // minted by a fresh render carried no wpc-cache marker and every edge HIT counted
                // as "rendered" forever (the 0%-served-from-cache panel on a CF site whose views
                // were overwhelmingly edge HITs). Stamp the mint epoch into the frozen headers:
                // the collector reads it same-origin via the navigation entry and classifies a
                // replay older than its threshold as cache-served. Fresh renders read as now.
                header('Server-Timing: wpc-mint;desc=' . time(), false);
                // Natural-assets verdict for THIS render, riding the same frozen-header channel:
                // 'natural' when on, else the first refusing gate. One DevTools look answers
                // "why is this site still on transform URLs" for any site, forever.
                if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_nat_why808')) {
                    header('Server-Timing: wpc-nat;desc=' . wps_rewriteLogic::wpc_nat_why808(), false);
                }
                unset($GLOBALS['wpc_cc_guarded']);
            }
        }


        // During an update window only UNARMED renders skip the file write — armed copies
        // get cached locally so window traffic serves from files instead of stampeding
        // full renders on small pools. The window-end handler purges every layer anyway.
        if (function_exists('wpc_update_window_active') && wpc_update_window_active()
            && function_exists('wpc_render_armed_for_cache') && !wpc_render_armed_for_cache($buffer)) {
            $wpc_wgate('update-window');
            return $buffer;
        }

        if (empty($buffer) || strlen($buffer) < 100 || strpos($buffer, '</body>') === false) {
            $wpc_wgate('thin-buffer', ['len' => strlen((string) $buffer)]);
            return $buffer;
        }

        // v7.10.520 — the rewrite was shed under pressure, so this buffer is UNOPTIMISED.
        // Storing it would serve an unoptimised page from cache long after the pressure
        // cleared, which is worse than not caching at all.
        if (!empty($GLOBALS['wpc_shed520'])) {
            $wpc_wgate('rewrite-shed', []);
            return $buffer;
        }

        if (empty($this->options['cache']['ignore-server-control']) || $this->options['cache']['ignore-server-control'] == '0') {
            $cacheControl = strtolower($_SERVER['HTTP_CACHE_CONTROL'] ?? '');
            if (strpos($cacheControl, 'no-cache') !== false || strpos($cacheControl, 'no-store') !== false || strpos($cacheControl, 'private') !== false) {
                $wpc_wgate('server-control', ['cc' => substr($cacheControl, 0, 40)]);
                return $buffer;
            }
        }


        try {
            $wpc_set45 = get_option(WPS_IC_SETTINGS);
            if (is_array($wpc_set45) && !empty($wpc_set45['critical']['css']) && $wpc_set45['critical']['css'] == '1'
                && $this->urlKey && defined('WPS_IC_CRITICAL')
                && strpos($buffer, 'id="wpc-critical-css"') === false
                && apply_filters('wpc_cache_require_crit', true)) {
                $wpc_cd45 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/';
                // v7.10.391: bypass-window critless renders are a deliberate cacheable state
                if (@filesize($wpc_cd45 . 'critical_desktop.css') > 5 && @filesize($wpc_cd45 . 'critical_mobile.css') > 5
                    && !(function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($this->urlKey))) {
                    $wpc_wgate('crit-guard-45');
                    return $buffer;
                }
            }


            if (is_array($wpc_set45 ?? null) && !empty($wpc_set45['critical']['css']) && $wpc_set45['critical']['css'] == '1'
                && $this->urlKey && defined('WPS_IC_CRITICAL')
                && strpos($buffer, 'wpc-lcp-bg-preload') === false
                && apply_filters('wpc_lcp_bg_responder', true)
                && apply_filters('wpc_cache_require_crit', true)) {
                $wpc_lj48 = @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/lcp.json');
                if (is_string($wpc_lj48) && $wpc_lj48 !== '' && strpos($wpc_lj48, '"lcp_element"') !== false) {
                    $wpc_ld48 = json_decode($wpc_lj48, true);
                    $wpc_le48 = is_array($wpc_ld48) && isset($wpc_ld48['lcp_element']) && is_array($wpc_ld48['lcp_element'])
                        ? $wpc_ld48['lcp_element'] : [];
                    foreach (['mobile', 'desktop'] as $wpc_dv48) {
                        if (!empty($wpc_le48[$wpc_dv48]['type']) && $wpc_le48[$wpc_dv48]['type'] === 'bg') {


                            $wpc_bgp48 = (string) parse_url(strtolower((string) ($wpc_le48[$wpc_dv48]['url'] ?? '')), PHP_URL_PATH);
                            if ($wpc_bgp48 !== '' && preg_match('/\.(jpe?g|png|webp|avif|gif|bmp|tiff?)$/', $wpc_bgp48)) {
                                $wpc_wgate('lcp-bg-transition-48', ['dev' => $wpc_dv48]);
                                return $buffer;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {

        }

        $excludes = get_option('wpc-excludes');
        $url = rtrim($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], '/');
        if (!empty($excludes) && !empty($excludes['cache'])) {
            if (in_array($url, $excludes['cache'])) {
                return $buffer;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $buffer;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PURGE') {
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

        if (is_user_logged_in()) {
            if (!$this->cacheLoggedIn()) {
                return $buffer;
            }
        }

        // Check for excluded cookies
        if (defined('WPC_EXCLUDE_COOKIES')) {
            if (WPC_EXCLUDE_COOKIES !== false && is_array(WPC_EXCLUDE_COOKIES)) {
                foreach ($_COOKIE as $cookieName => $cookieValue) {
                    foreach (WPC_EXCLUDE_COOKIES as $excludedCookie) {

                        // Trailing "_" means: treat as wildcard prefix (e.g. "wp-postpass_")
                        if (substr($excludedCookie, -1) === '_') {
                            if (stripos($cookieName, $excludedCookie) === 0) {
                                return $buffer; // Don't cache if excluded cookie prefix is detected
                            }
                        } else {
                            // Exact match (case-insensitive)
                            if (strcasecmp($cookieName, $excludedCookie) === 0) {
                                return $buffer; // Don't cache if exact excluded cookie is detected
                            }
                        }
                    }
                }
            }
        }

        // Check mandatory cookies - don't save cache if any required cookie is missing
        if (defined('WPC_MANDATORY_COOKIES') && WPC_MANDATORY_COOKIES !== false && is_array(WPC_MANDATORY_COOKIES)) {
            foreach (WPC_MANDATORY_COOKIES as $mandatoryCookie) {
                if (substr($mandatoryCookie, -1) === '_') {
                    $found = false;
                    foreach ($_COOKIE as $cookieName => $cookieValue) {
                        if (strpos($cookieName, $mandatoryCookie) === 0 && !empty($cookieValue)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return $buffer; // Don't save cache if mandatory cookie prefix not present
                    }
                } else {
                    if (empty($_COOKIE[$mandatoryCookie])) {
                        return $buffer; // Don't save cache if mandatory cookie not set
                    }
                }
            }
        }


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
                @file_put_contents($wpc_map_file, $wpc_map_url);
            }
        }

        if (function_exists('wpc_compute_tpl_key') && class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
            $wpc_tk = wpc_compute_tpl_key();
            if ($wpc_tk !== '' && $this->urlKey) {
                $wpc_td = rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/';
                if (is_dir($wpc_td) || (function_exists('wp_mkdir_p') && wp_mkdir_p($wpc_td))) {
                    if (!@file_exists($wpc_td . 'tpl.txt') || trim((string) @file_get_contents($wpc_td . 'tpl.txt')) !== $wpc_tk) {
                        @file_put_contents($wpc_td . 'tpl.txt', $wpc_tk);
                    }
                }
            }
        }

        if (!empty($this->options['cache']['headers']) && $this->options['cache']['headers'] == '1') {
            $headers = array();

            foreach (headers_list() as $header) {
                $parts = explode(':', $header, 2);
                if (count($parts) == 2) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
            }

            $headersJson = json_encode($headers);
            file_put_contents($this->cachePath . 'headers.json', $headersJson);
        }

        if (function_exists('gzencode')) {
            $this->saveGzCache($buffer, $prefix);
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

    public function saveGzCache($buffer, $prefix)
    {
        if (!empty($_GET['disable_cache'])) {
            return true;
        }
        // v7.10.660 (B3) — fold-split wrap at cache-write, behind wpc_fold_split (default false).
        // The CACHED document is the one that gets split; the §4 stamper restores it in-viewport.
        // buildTemplateDocument is a byte-verbatim port of the service planner (t660 differential
        // parity vs the shipped fold-split.js), so the plugin reproduces exactly what the service
        // render-verified before it published the artifact. Inert by default; fail-open on all.
        if (!function_exists('wpc_fs_maybe_wrap660')) {
            @include_once __DIR__ . '/fold-split.php';
        }
        if (function_exists('wpc_fs_maybe_wrap660')) {
            $buffer = wpc_fs_maybe_wrap660($buffer);
        }
        if (!is_string($buffer) || strlen($buffer) < 1024 || stripos($buffer, '</html>') === false) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gz-drop-body-floor', '', '', ['variant' => (string) $prefix, 'len' => is_string($buffer) ? strlen($buffer) : -1]);
            }
            return $buffer;
        }


        $final = $this->cachePath . $prefix . 'index.html' . '_gzip';
        // v7.10.647 — Brotli pairing invariant (R1): the _br sibling dies BEFORE any
        // html write; it may only be recreated by the land handler against the md5
        // sidecar written below. br present => paired, structurally.
        @unlink($this->cachePath . $prefix . 'index.html_br');
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
            @file_put_contents($this->cachePath . $prefix . 'index.html_md5', md5($buffer));
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('warm-wrote', '', '', ['variant' => (string) $prefix, 'bytes' => (int) @filesize($final)]);
            }
        }


        $this->writeStaticMirror($buffer, $prefix);

        return $buffer;
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
            $wpc_mh186 = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
            $wpc_mu186 = rtrim(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/');
            if ($wpc_mh186 !== '' && strpos($wpc_mu186, '..') === false && strpos($wpc_mu186, "\0") === false) {
                foreach (['index.html_gzip', 'mobile_index.html_gzip', 'index.html', 'mobile_index.html'] as $wpc_mf186) {
                    $wpc_mp186 = WPS_IC_CACHE . $wpc_mh186 . $wpc_mu186 . '/' . $wpc_mf186;
                    if (@file_exists($wpc_mp186)) {
                        @unlink($wpc_mp186);
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
        $wpc_murl673 = ($urlOverride !== null && $urlOverride !== '') ? (string) $urlOverride : home_url('/');
        $dir  = ($dirOverride !== null && $dirOverride !== '')
            ? rtrim((string) $dirOverride, '/') . '/'
            : rtrim(WPS_IC_CACHE, '/') . '/' . $host . '/';
        $file = $dir . '.htaccess';
        $wpc_utag673 = self::wpc_mirror_url_tag($wpc_murl673);

        // Cache-Control pin, so existing mirrors must be REWRITTEN once. Marker bump = one more
        // rewrite pass, self-healing via the same every-mirror-write ensure.


        // REVALIDATED on every hit — where max-age=60 pages served HIT at ~20ms). 60s keeps the
        // original protection (explicit header still beats a host's blanket month-long expiry;


        // v7.10.569 — THE CROWN GOES IN THE MARKER, so the existing write-once ensure becomes the
        // expiry mechanism. .559 baked s-maxage=0 here on the reasoning that a static file cannot
        // re-evaluate the purge crown. True, but the file does not have to: fold the crown-derived
        // value into the marker and a change of crown state no longer matches, which triggers the
        // same rewrite path that already exists. Crown lapses -> next mirror write emits s-maxage=0.
        $wpc_sm569 = function_exists('wpc_edge_smaxage') ? (int) wpc_edge_smaxage() : 0;
        // v8 + the per-URL tag in the marker → existing v7 (constant-tag) mirrors rewrite once, and a
        // dir that somehow held a different URL's tag self-heals on the next write.
        // v9 (v7.10.683): + Server-Timing wpc-cache;desc=hit — mirror serves are cache serves and
        // must say so to the RUM collector (zero-PHP path emitted no marker => counted "rendered").
        $wpc_marker = '# wpc-mirror-headers-v9-s' . $wpc_sm569 . '-' . $wpc_utag673;
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
            ? wpc_cc_freshness($wpc_hma, $wpc_sm569, $wpc_swr)
            : 'public, max-age=' . $wpc_hma
                . ($wpc_sm569 > 0 ? ', s-maxage=' . $wpc_sm569 . ', stale-while-revalidate=86400'
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
           . 'Header set Cache-Tag "wpc-html,' . $wpc_utag673 . '"' . PHP_EOL
           . 'Header set Cache-Control "' . $wpc_cc . '"' . PHP_EOL
           . 'Header set Server-Timing "wpc-cache;desc=hit"' . PHP_EOL
           . '</IfModule>' . PHP_EOL;
        return (bool) @file_put_contents($file, $c);
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
        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        // Read-side body floor: the write-path floors can't retire a poisoned file that
        // landed before them — a sub-floor file here would serve an empty 200 (with public
        // caching headers) until someone purges. Delete it and fall through to a live render.
        foreach (['index.html_gzip', 'index.html'] as $wpc_cf178) {
            $wpc_cfp178 = $this->cachePath . $prefix . $wpc_cf178;
            if (@file_exists($wpc_cfp178) && (int) @filesize($wpc_cfp178) < 1024) {
                @unlink($wpc_cfp178);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('serve-drop-body-floor', '', '', ['f' => $prefix . $wpc_cf178]);
                }
            }
        }

        if (function_exists('readgzfile')) {
            if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && is_readable($this->cachePath . $prefix . 'index.html' . '_gzip')) {
                $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html' . '_gzip');
                // Nginx instantly echoes readgzfile instead of saving it to variable.
                readgzfile($this->cachePath . $prefix . 'index.html' . '_gzip');
                die();
            }
        }

        if (file_exists($this->cachePath . $prefix . 'index.html') && is_readable($this->cachePath . $prefix . 'index.html')) {
            $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html');
            readfile($this->cachePath . $prefix . 'index.html');
            die();
        }
    }

    public function setupCacheHeaders($cache_filepath)
    {
        // A session cookie (started by any plugin at boot) rides every PHP response and
        // makes CDNs refuse to store it — cached-file serves are anonymous by definition.
        // Same for the session engine's auto-sent Pragma: no-cache.
        if (function_exists('header_remove') && apply_filters('wpc_strip_setcookie_on_public', true)) {
            @header_remove('Set-Cookie');
            @header_remove('Pragma');
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');


        $wpc_hma49 = max(0, (int) apply_filters('wpc_html_max_age', 300));
        $wpc_sm178 = function_exists('wpc_edge_smaxage') ? wpc_edge_smaxage() : 0;
        header('Cache-Control: ' . wpc_cc_freshness($wpc_hma49, $wpc_sm178,
            function_exists('wpc_edge_swr') ? wpc_edge_swr() : 0));
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $wpc_hma49) . ' GMT');


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

        header('X-Cache-By: Advanced Cache - Gzip');
    }

    public function removeCacheFiles($post_id)
    {
        if ($post_id == 'home') {
            $post_id = 0;
        }

        if ($post_id == 'all') {
            // The journal must survive the event it records — a purge that wipes its own
            // receipt makes every storm undiagnosable. Keep the last 64KB across the wipe.
            $wpc_jf179 = rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl';
            $wpc_jl179 = '';
            if (@is_readable($wpc_jf179)) {
                $wpc_js179 = (int) @filesize($wpc_jf179);
                $wpc_jl179 = (string) @file_get_contents($wpc_jf179, false, null, max(0, $wpc_js179 - 65536));
            }
            // PURGE = page copies only. The derived asset stores (css/js/used-css) are
            // CONTENT-ADDRESSED (hashed filenames; icv is a query-buster) — deleting them
            // orphans every asset URL still referenced by LiteSpeed/CF/browser-cached HTML
            // (naked-page class) and forces a full regeneration herd on a busy origin.
            // They age out via GC in the deferred post-update handler instead.
            $wpc_keep179 = ['css', 'js', 'wpc-cflog.jsonl'];
            $wpc_root179 = rtrim(WPS_IC_CACHE, '/');
            // v7.10.530 — RENAME, THEN DELETE. The recursive unlink below blocks every
            // concurrent render trying to WRITE into the same tree: receipted on the flagship as
            // requests queueing ~44 s and draining together the instant the purge finished, with
            // load 0.6 and zero HTTP — I/O contention, not CPU. A directory rename is atomic and
            // costs microseconds, so renders see an empty tree immediately and the expensive
            // unlink happens after the response is flushed. Same trick the sane cache layers use.
            $wpc_tomb530 = '';
            if (apply_filters('wpc_purge_rename_first', true)) {
                foreach ((array) @scandir($wpc_root179) as $wpc_pk530) {
                    if ($wpc_pk530 === '.' || $wpc_pk530 === '..' || in_array($wpc_pk530, $wpc_keep179, true)
                        || strpos($wpc_pk530, '.purging-') === 0) {
                        continue;
                    }
                    $wpc_src530 = $wpc_root179 . '/' . $wpc_pk530;
                    if (!@is_dir($wpc_src530)) {
                        continue;
                    }
                    if ($wpc_tomb530 === '') {
                        $wpc_tomb530 = $wpc_root179 . '/.purging-' . substr(md5(uniqid('', true)), 0, 10);
                        if (!@mkdir($wpc_tomb530, 0777, true)) {
                            $wpc_tomb530 = '';
                            break;
                        }
                    }
                    // A failed rename simply leaves it for the ordinary unlink pass below.
                    @rename($wpc_src530, $wpc_tomb530 . '/' . $wpc_pk530);
                }
            }
            foreach ((array) @scandir($wpc_root179) as $wpc_it179) {
                if ($wpc_it179 === '.' || $wpc_it179 === '..' || in_array($wpc_it179, $wpc_keep179, true)) {
                    continue;
                }
                if (strpos($wpc_it179, '.purging-') === 0) {
                    continue; // tombstones are drained post-response, never inline
                }
                $wpc_ip179 = $wpc_root179 . '/' . $wpc_it179;
                is_dir($wpc_ip179) ? self::removeDirectory($wpc_ip179) : @unlink($wpc_ip179);
            }
            // Drain every tombstone (this one and any orphaned by a killed request) after the
            // response is flushed, so a crash can never leak them permanently.
            if (function_exists('register_shutdown_function')) {
                register_shutdown_function(function () use ($wpc_root179) {
                    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                    if (function_exists('set_time_limit')) { @set_time_limit(120); }
                    $wpc_n530 = 0;
                    $wpc_seen530 = [];
                    $GLOBALS['wpc_rmdl530'] = microtime(true) + 10.0;
                    while (microtime(true) <= $GLOBALS['wpc_rmdl530']) {
                        $wpc_any530 = false;
                        foreach ((array) @scandir($wpc_root179) as $wpc_t530) {
                            if (strpos((string) $wpc_t530, '.purging-') !== 0 || isset($wpc_seen530[$wpc_t530])) {
                                continue;
                            }
                            $wpc_seen530[$wpc_t530] = 1;
                            if (microtime(true) > $GLOBALS['wpc_rmdl530']) {
                                break 2;
                            }
                            $wpc_any530 = true;
                            $wpc_tp530 = $wpc_root179 . '/' . $wpc_t530;
                            foreach ((array) @scandir($wpc_tp530) as $wpc_c530) {
                                if (strpos((string) $wpc_c530, '.purging-') === 0) {
                                    @rename($wpc_tp530 . '/' . $wpc_c530, $wpc_root179 . '/.purging-' . substr(md5(uniqid('', true)), 0, 10));
                                }
                            }
                            self::removeDirectory($wpc_tp530);
                            $wpc_n530++;
                        }
                        if (!$wpc_any530) {
                            break;
                        }
                    }
                    unset($GLOBALS['wpc_rmdl530']);
                    if ($wpc_n530 && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('purge-tombstone-drained', '', '', ['n' => $wpc_n530]);
                    }
                });
            }
            self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/');
            // Template-keyed used-css is MUTABLE under the same key (no content version in
            // the contract yet) — the pickup skips refetch on tpl match, so service-side
            // artifact fixes never propagate. Purge resets the MARKERS only (files stay so
            // cached pages keep valid URLs; refetch overwrites in place). hawkeye receipt:
            // stored blob still pre-fix while service shelf carried the corrected artifact.
            if (defined('WPS_IC_CRITICAL')) {
                $wpc_ut179 = (array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/used_tpl.txt');
                $wpc_utn179 = 0;
                foreach ($wpc_ut179 as $wpc_utf179) {
                    if ($wpc_utn179 >= 200) { break; }
                    if (@unlink($wpc_utf179)) { $wpc_utn179++; }
                }
            }
            if ($wpc_jl179 !== '') {
                @mkdir(rtrim(WPS_IC_CACHE, '/'), 0777, true);
                if (!@is_file($wpc_jf179)) {
                    @file_put_contents($wpc_jf179, $wpc_jl179);
                }
            }

        } else {
            if ($post_id != 0) {
                $url = get_permalink($post_id);
            } else {
                $url = home_url();
            }

            $urlKey = $this->url_key_class->setup($url);
            self::removeDirectory(WPS_IC_CACHE . $urlKey);
            self::removeDirectory(WP_CONTENT_DIR . '/cache/wp-preload/' . $urlKey);
            self::removeStaticMirror($url);
        }
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
        $files = glob($path . '/*');

        if (!empty($files)) {
            foreach ($files as $file) {
                // v7.10.530b — the deadline must be honoured INSIDE the recursion, not around it.
                // critical/ holds ~20,000 files on the flagship, so one call to this function was
                // the entire drain: a budget checked only between top-level tombstones could never
                // fire. Leftovers are inert and swept by the next purge.
                if (!empty($GLOBALS['wpc_rmdl530']) && microtime(true) > $GLOBALS['wpc_rmdl530']) {
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