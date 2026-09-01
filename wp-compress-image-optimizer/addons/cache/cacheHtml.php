<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/cacheHtml.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */


if (!function_exists('wpc_response_cache_guard')) {
    
    
    function wpc_response_cache_guard()
    {
        try {
            if (!empty($GLOBALS['wpc_cc_guarded']) || isset($GLOBALS['wpc_cc_skip'])) {
                return;
            }
            
            if (is_admin()) { $GLOBALS['wpc_cc_skip'] = 'admin'; return; }
            if (function_exists('is_user_logged_in') && is_user_logged_in()) { $GLOBALS['wpc_cc_skip'] = 'logged-in'; return; }
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') { $GLOBALS['wpc_cc_skip'] = 'method'; return; }
            if (function_exists('wp_doing_ajax') && wp_doing_ajax()) { $GLOBALS['wpc_cc_skip'] = 'ajax'; return; }
            if (defined('REST_REQUEST') && REST_REQUEST) { $GLOBALS['wpc_cc_skip'] = 'rest'; return; }
            
            
            
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
            
            
            
            
            header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            $GLOBALS['wpc_cc_guarded'] = true;
        } catch (\Throwable $e) {
        }
    }
    
    
    
    add_action('send_headers', 'wpc_response_cache_guard', 1);
}

if (!function_exists('wpc_edge_smaxage')) {
    
    
    
    
    
    
    
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

if (!function_exists('wpc_dcv_stale146')) {
    
    
    function wpc_dcv_stale146($file)
    {
        try {
            if (!defined('WPS_IC_CACHE')) {
                return false;
            }
            $wpc_d146 = rtrim(WPS_IC_CACHE, '/') . '/dcv.txt';
            if (!@file_exists($wpc_d146)) {
                return false;
            }
            $wpc_dm146 = (int) @filemtime($wpc_d146);
            $wpc_fm146 = (int) @filemtime($file);
            return $wpc_dm146 > 0 && $wpc_fm146 > 0 && $wpc_fm146 < $wpc_dm146;
        } catch (\Throwable $e) {
            return false;
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

        
        $user_hash = '';
        if (defined('WPC_CACHE_LOGGED_IN') && WPC_CACHE_LOGGED_IN) {
            foreach ($_COOKIE as $key => $value) {
                if (strpos($key, 'wordpress_logged_in_') === 0) {
                    $user_hash = md5($key . substr($value, 0, 10)) . '/';
                    break;
                }
            }

        }

        
        $cookie_string = '';
        if (defined('WPC_CACHE_COOKIES') && WPC_CACHE_COOKIES !== false) {
            $cookie_values = [];
            $cache_cookies = WPC_CACHE_COOKIES;

            foreach ($cache_cookies as $cookie_name) {
                
                if (substr($cookie_name, -1) === '_') {
                    
                    $prefix = $cookie_name; 
                    foreach ($_COOKIE as $actual_cookie_name => $cookie_value) {
                        if (strpos($actual_cookie_name, $prefix) === 0 && !empty($cookie_value)) {
                            
                            $suffix = substr($actual_cookie_name, strlen($prefix));

                            
                            $suffix_hash = substr(hash('md5', $suffix), 0, 7);
                            $cookie_values[] = $cookie_value . '_' . $suffix_hash;
                        }
                    }
                } else {
                    
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

    



    public static function isPageBuilder()
    {
        $page_builders = ['run_compress',
            'run_restore',
            'elementor-preview',
            'fl_builder',
            'et_fb',
            'preview', 
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


    



    public function pageTest()
    {
        return false;
    }

    
    
    
    
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
        
        
        
        
        if (preg_match('/default-skin|photoswipe|sprite|\/icons?[\/.-]|loading|spinner|arrow/i', (string) $wpc_pu[2])) {
            return $buffer;
        }
        $wpc_ph = html_entity_decode($wpc_pu[2], ENT_QUOTES);
        if (strpos($wpc_ph, '//') === 0) {
            $wpc_ph = 'https:' . $wpc_ph;
        } elseif ($wpc_ph[0] === '/' && !empty($_SERVER['HTTP_HOST'])) {
            $wpc_ph = 'https://' . $_SERVER['HTTP_HOST'] . $wpc_ph;
        }
        
        
        
        $wpc_stem840 = preg_replace('/\.[a-z0-9]+$/i', '', basename((string) parse_url($wpc_ph, PHP_URL_PATH)));
        if ($wpc_stem840 !== '' && preg_match('/<link\b[^>]*id="wpc-atf-bg-preload"[^>]*>\s*/i', $buffer, $wpc_atfm840)
            && strpos($wpc_atfm840[0], $wpc_stem840 . '.') !== false) {
            $buffer = str_replace($wpc_atfm840[0], '', $buffer);
        }
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_crit_url_align225')) {
            $wpc_ph2 = wps_rewriteLogic::wpc_crit_url_align225('url(' . $wpc_ph . ')');
            if (is_string($wpc_ph2) && preg_match('#url\((https?://[^)]+)\)#i', $wpc_ph2, $wpc_pm2)) {
                $wpc_ph = $wpc_pm2[1];
            }
        }
        $wpc_pt = '<link id="wpc-crit-bg-preload" rel="preload" as="image" fetchpriority="high" href="' . esc_url($wpc_ph) . '">';
        return str_replace($wpc_pm[0], $wpc_pt . $wpc_pm[0], $buffer);
    }

    
    
    
    
    
    public static function bricksAtfUnveil($buffer)
    {
        if (!is_string($buffer) || strpos($buffer, 'bricks-lazy-hidden') === false
            || strpos($buffer, 'id="wpc-bricks-unveil"') !== false || !apply_filters('wpc_bricks_unveil', true)) {
            return $buffer;
        }
        if (!preg_match('/<style[^>]*id="wpc-critical-css"[^>]*>(.*?)<\/style>/s', $buffer, $wpc_bm)) {
            
            
            
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
            
            
            
            $buffer = str_replace($wpc_bm[0], $wpc_bm[0] . '<style id="wpc-bricks-unveil">@layer wpc-unveil{' . $wpc_out . '}</style>', $buffer);
        }
        return $buffer;
    }

    
    
    public static function typeGuardCritFilter259($guard, $buffer)
    {
        try {
            if (!is_string($guard) || $guard === ''
                || !preg_match('/<style\b[^>]*id=["\']wpc-critical-css["\'][^>]*>(.*?)<\/style>/is', $buffer, $wpc_cm259)) {
                return $guard;
            }
            $crit = (string) $wpc_cm259[1];
            if (stripos($crit, '@media') === false) { return $guard; }
            $pairs = [];
            $n = strlen($crit); $i = 0;
            while (($mp = stripos($crit, '@media', $i)) !== false) {
                $ob = strpos($crit, '{', $mp);
                if ($ob === false) { break; }
                $d = 1; $j = $ob;
                while ($d > 0 && ++$j < $n) {
                    if ($crit[$j] === '{') { $d++; } elseif ($crit[$j] === '}') { $d--; }
                }
                $inner = substr($crit, $ob + 1, $j - $ob - 1);
                if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $inner, $rm259, PREG_SET_ORDER)) {
                    foreach ($rm259 as $r259) {
                        foreach (explode(',', $r259[1]) as $sp259) {
                            $sk = strtolower((string) preg_replace('/\s+/', ' ', trim($sp259)));
                            if ($sk === '') { continue; }
                            foreach (explode(';', $r259[2]) as $dc259) {
                                $pp = strtolower(trim((string) strstr($dc259, ':', true)));
                                if ($pp !== '') { $pairs[$sk][$pp] = 1; }
                            }
                        }
                    }
                }
                $i = $j + 1;
            }
            if (empty($pairs)) { return $guard; }
            $out = preg_replace_callback('/([^{}]+)\{([^{}]*)\}/', function ($g259) use ($pairs) {
                $hit = [];
                foreach (explode(',', $g259[1]) as $sp259) {
                    $sk = strtolower((string) preg_replace('/\s+/', ' ', trim($sp259)));
                    if (!empty($pairs[$sk])) { $hit += $pairs[$sk]; }
                }
                if (empty($hit)) { return $g259[0]; }
                $keep = [];
                foreach (explode(';', $g259[2]) as $dc259) {
                    $pp = strtolower(trim((string) strstr($dc259, ':', true)));
                    if ($pp !== '' && isset($hit[$pp])) { continue; }
                    if (trim($dc259) !== '') { $keep[] = $dc259; }
                }
                return empty($keep) ? '' : $g259[1] . '{' . implode(';', $keep) . '}';
            }, $guard);
            return is_string($out) ? $out : $guard;
        } catch (\Throwable $e) {
            return $guard;
        }
    }

    
    
    
    
    
    
    
    
    public static function typeGuard84($buffer)
    {
      try {
        
        
        
        
        if (!is_string($buffer)
            || (strpos($buffer, 'id="wpc-critical-css"') === false && strpos($buffer, "id='wpc-critical-css'") === false
                && strpos($buffer, 'wpc-used-css') === false)
            || strpos($buffer, 'wpc-type-guard') !== false || !apply_filters('wpc_type_guard', true)) {
            return $buffer;
        }
        $wpc_tg = '';
        $wpc_tgn = 0;
        if (preg_match_all('/<link\b[^>]*rel=["\']wpc-[a-z-]*stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $buffer, $wpc_tgm)) {
            foreach (array_unique($wpc_tgm[1]) as $wpc_tgh) {
                if ($wpc_tgn >= 8 || strlen($wpc_tg) > 10240) { break; }
                $wpc_tgu = html_entity_decode($wpc_tgh, ENT_QUOTES);
                $wpc_tgc = strrpos($wpc_tgu, 'wp-content/');
                if ($wpc_tgc === false || !preg_match('/\.css(\?|$)/', $wpc_tgu)) { continue; }
                $wpc_tgr = (string) preg_replace('/[?#].*$/', '', substr($wpc_tgu, $wpc_tgc));
                if (strpos($wpc_tgr, '..') !== false) { continue; }
                $wpc_tgp = trailingslashit(ABSPATH) . $wpc_tgr;
                if (!@is_readable($wpc_tgp)) { continue; }
                $wpc_tgmt = (int) @filemtime($wpc_tgp);
                $wpc_tgd = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/typeguard/' : '';
                if ($wpc_tgd !== '' && !is_dir($wpc_tgd)) { @mkdir($wpc_tgd, 0777, true); }
                $wpc_tgs = $wpc_tgd !== '' ? $wpc_tgd . md5($wpc_tgr) . '.css' : '';
                if ($wpc_tgs === '' || !@is_readable($wpc_tgs) || (int) @filemtime($wpc_tgs) < $wpc_tgmt) {
                    $wpc_tgcss = (string) @file_get_contents($wpc_tgp);
                    $wpc_tgout = '';
                    if ($wpc_tgcss !== '' && stripos($wpc_tgcss, 'font-size') !== false) {
                        $wpc_tgemit = function ($chunk, $media) use (&$wpc_tgout) {
                            if (strlen($wpc_tgout) > 8192 || stripos($chunk, 'font-size') === false) { return; }
                            if (preg_match_all('/([^{}@]+)\{([^{}]*font-size[^{}]*)\}/i', $chunk, $wpc_tgb)) {
                                $wpc_blob = '';
                                foreach ($wpc_tgb[0] as $wpc_tgk => $wpc_tgblk) {
                                    if (strlen($wpc_tgout) + strlen($wpc_blob) > 8192) { break; }
                                    if (!preg_match('/(^|[\s,>+~])(?:html|:root|h[1-6]|body)\b|\.et_pb_(?:text|heading|button|blurb|slide_title)|(?:^|[ ,.#])(?:site-|page-|entry-|section-)?title|heading/i', $wpc_tgb[1][$wpc_tgk])) { continue; }
                                    $wpc_blob .= trim($wpc_tgblk);
                                }
                                if ($wpc_blob !== '') {
                                    $wpc_tgout .= $media !== '' ? '@media ' . $media . '{' . $wpc_blob . '}' : $wpc_blob;
                                }
                            }
                        };
                        $wpc_toff = 0;
                        $wpc_tlen = strlen($wpc_tgcss);
                        while (preg_match('/@media([^{]+)\{/i', $wpc_tgcss, $wpc_tvm, PREG_OFFSET_CAPTURE, $wpc_toff)) {
                            $wpc_tms = (int) $wpc_tvm[0][1];
                            $wpc_tgemit(substr($wpc_tgcss, $wpc_toff, $wpc_tms - $wpc_toff), '');
                            $wpc_ti = $wpc_tms + strlen($wpc_tvm[0][0]);
                            $wpc_td = 1;
                            while ($wpc_ti < $wpc_tlen && $wpc_td > 0) {
                                $wpc_tch = $wpc_tgcss[$wpc_ti];
                                if ($wpc_tch === '{') { $wpc_td++; } elseif ($wpc_tch === '}') { $wpc_td--; }
                                $wpc_ti++;
                            }
                            $wpc_tgemit(substr($wpc_tgcss, $wpc_tms + strlen($wpc_tvm[0][0]), $wpc_ti - $wpc_tms - strlen($wpc_tvm[0][0]) - 1), trim((string) $wpc_tvm[1][0]));
                            $wpc_toff = $wpc_ti;
                        }
                        $wpc_tgemit(substr($wpc_tgcss, $wpc_toff), '');
                    }
                    if ($wpc_tgs !== '') {
                        @file_put_contents($wpc_tgs, $wpc_tgout, LOCK_EX);
                        @touch($wpc_tgs, $wpc_tgmt);
                    }
                    $wpc_tgtxt = $wpc_tgout;
                } else {
                    $wpc_tgtxt = (string) @file_get_contents($wpc_tgs);
                }
                if ($wpc_tgtxt !== '') {
                    $wpc_tg .= $wpc_tgtxt;
                    $wpc_tgn++;
                }
            }
        }
        if ($wpc_tg === '') { return $buffer; }
        
        
        
        
        
        
        
        
        
        
        $wpc_basis109 = '';
        if (apply_filters('wpc_type_guard_basis', true)) {
            $wpc_bpick109 = function ($chunk) {
                $wpc_bout = '';
                if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $chunk, $wpc_bb)) {
                    foreach ($wpc_bb[1] as $wpc_bk => $wpc_bsel) {
                        if (!preg_match('/(?:^|,)\s*(?:html|:root)\s*(?:,|$)/i', trim($wpc_bsel))) { continue; }
                        if (preg_match_all('/(?<![-\w])font-size\s*:\s*[^;}]+/i', $wpc_bb[2][$wpc_bk], $wpc_bd)) {
                            $wpc_bout .= 'html{' . implode(';', $wpc_bd[0]) . '}';
                        }
                    }
                }
                return $wpc_bout;
            };
            $wpc_boff = 0;
            $wpc_blen = strlen($wpc_tg);
            while (preg_match('/@media([^{]+)\{/i', $wpc_tg, $wpc_bvm, PREG_OFFSET_CAPTURE, $wpc_boff)) {
                $wpc_bms = (int) $wpc_bvm[0][1];
                $wpc_basis109 .= $wpc_bpick109(substr($wpc_tg, $wpc_boff, $wpc_bms - $wpc_boff));
                $wpc_bi = $wpc_bms + strlen($wpc_bvm[0][0]);
                $wpc_bd2 = 1;
                while ($wpc_bi < $wpc_blen && $wpc_bd2 > 0) {
                    $wpc_bch = $wpc_tg[$wpc_bi];
                    if ($wpc_bch === '{') { $wpc_bd2++; } elseif ($wpc_bch === '}') { $wpc_bd2--; }
                    $wpc_bi++;
                }
                $wpc_bin109 = $wpc_bpick109(substr($wpc_tg, $wpc_bms + strlen($wpc_bvm[0][0]), $wpc_bi - $wpc_bms - strlen($wpc_bvm[0][0]) - 1));
                if ($wpc_bin109 !== '') { $wpc_basis109 .= '@media' . $wpc_bvm[1][0] . '{' . $wpc_bin109 . '}'; }
                $wpc_boff = $wpc_bi;
            }
            $wpc_basis109 .= $wpc_bpick109(substr($wpc_tg, $wpc_boff));
        }
        
        
        
        
        
        
        
        if (is_callable(['wps_cacheHtml', 'typeGuardCritFilter259'])) {
            $wpc_tg = wps_cacheHtml::typeGuardCritFilter259($wpc_tg, $buffer);
        }
        $wpc_tgtag = '<style id="wpc-type-guard">' . $wpc_tg . '</style>';
        
        
        
        
        
        
        
        
        
        if (preg_match('/<\/head>/i', $buffer, $wpc_tghm, PREG_OFFSET_CAPTURE)) {
            $buffer = substr_replace($buffer, $wpc_tgtag, (int) $wpc_tghm[0][1], 0);
        } else {
            $wpc_tgat = -1;
            if (preg_match('/<style\b[^>]*id=["\']wpc-critical-css["\']/i', $buffer, $wpc_tgcm, PREG_OFFSET_CAPTURE)) {
                $wpc_tgat = (int) $wpc_tgcm[0][1];
            }
            if (preg_match('/<link\b[^>]*rel=["\']wpc-[a-z-]*stylesheet["\']/i', $buffer, $wpc_tgfm, PREG_OFFSET_CAPTURE)
                && ($wpc_tgat < 0 || (int) $wpc_tgfm[0][1] < $wpc_tgat)) {
                $wpc_tgat = (int) $wpc_tgfm[0][1];
            }
            if ($wpc_tgat >= 0) {
                $buffer = substr_replace($buffer, $wpc_tgtag, $wpc_tgat, 0);
            }
        }
        if ($wpc_basis109 !== '' && preg_match('/<\/head>/i', $buffer, $wpc_bhm109, PREG_OFFSET_CAPTURE)) {
            $buffer = substr_replace($buffer, '<style id="wpc-type-guard-basis">' . $wpc_basis109 . '</style>', (int) $wpc_bhm109[0][1], 0);
        }
        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_tg84_log')) {
            set_transient('wpc_tg84_log', 1, 3600);
            wpc_cache_first_log('type-guard', '', '', ['sheets' => $wpc_tgn, 'b' => strlen($wpc_tg)]);
        }
        return $buffer;
      } catch (\Throwable $wpc_e84) { return $buffer; }
    }

    
    
    
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
                
                
                
                
                
                
                
                if (strpos($wpc_vgr, '.vars2.css') !== false) {
                    $wpc_true80 = $wpc_vgr;
                    while (substr($wpc_true80, -10) === '.vars2.css') {
                        $wpc_chain80 = trailingslashit(ABSPATH) . $wpc_true80;
                        if (strpos($wpc_true80, 'wp-content/') === 0 && @is_file($wpc_chain80)) {
                            @unlink($wpc_chain80);
                        }
                        $wpc_true80 = substr($wpc_true80, 0, -10);
                    }
                    if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_vars2_heal_log')) {
                        set_transient('wpc_vars2_heal_log', 1, 3600);
                        wpc_cache_first_log('vars2-chain-healed', basename($wpc_true80), '', []);
                    }
                    $wpc_vgr = $wpc_true80;
                }
                $wpc_vgp = trailingslashit(ABSPATH) . $wpc_vgr;
                if (!@is_readable($wpc_vgp)) {
                    continue;
                }
                $wpc_vgmt = (int) @filemtime($wpc_vgp);
                
                
                @unlink($wpc_vgp . '.vars2.css');
                $wpc_vgd80 = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/vars2/' : '';
                if ($wpc_vgd80 !== '' && !is_dir($wpc_vgd80)) {
                    @mkdir($wpc_vgd80, 0777, true);
                }
                $wpc_vgs = $wpc_vgd80 !== '' ? $wpc_vgd80 . md5($wpc_vgr) . '.css' : '';
                if ($wpc_vgs === '' || !@is_readable($wpc_vgs) || (int) @filemtime($wpc_vgs) < $wpc_vgmt) {
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
                    if ($wpc_vgs !== '') {
                        @file_put_contents($wpc_vgs, $wpc_vgout, LOCK_EX);
                        @touch($wpc_vgs, $wpc_vgmt);
                    }
                    $wpc_vgtxt = $wpc_vgout;
                } else {
                    $wpc_vgtxt = (string) @file_get_contents($wpc_vgs);
                }
                $wpc_vgtxt = (string) $wpc_vgtxt;
                if ($wpc_vgtxt !== '') {
                    $wpc_vg .= $wpc_vgtxt;
                    $wpc_vgn++;
                }
            }
        }
        if ($wpc_vg !== '') {
            
            
            
            
            
            $wpc_vgtag = '<style id="wpc-vars-guard">' . $wpc_vg . '</style>';
            
            
            
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

    
    
    
    
    
    
    
    
    
    
    
    protected static function wpc_css_live_extras93($buffer)
    {
        $buffer = (string) preg_replace('/(<style\b[^>]*id=["\']wpc-late-faces["\'][^>]*?)\s*media=["\']not all["\']/i', '$1', $buffer);
        $buffer = (string) preg_replace_callback('/<link\b[^>]*data-wpc-lf-href=["\']([^"\']+)["\'][^>]*>/i', function ($lf) {
            return '<link rel="stylesheet" href="' . $lf[1] . '" />';
        }, $buffer);
        $buffer = (string) preg_replace_callback('/<link\b[^>]*id=["\']wpc-used-css-rest["\'][^>]*>/i', function ($ur) {
            if (preg_match('/data-wpc-rest=["\']([^"\']+)["\']/i', $ur[0], $uh)) {
                return '<link rel="stylesheet" id="wpc-used-css-rest" href="' . $uh[1] . '" />';
            }
            return $ur[0];
        }, $buffer);
        return $buffer;
    }

    
    
    
    
    
    
    public static function wpc_late_faces_law330($buffer)
    {
      try {
        if (!is_string($buffer) || !apply_filters('wpc_late_faces_law', true)
            || !preg_match('/(<style\b[^>]*\bid=["\']wpc-late-faces["\'][^>]*>)(.*?)(<\/style>)/is', $buffer, $wpc_lm330)) {
            return $buffer;
        }
        $wpc_in330 = $wpc_lm330[2];
        $wpc_out330 = function_exists('wpc_strip_livefaces_fams329')
            ? (string) wpc_strip_livefaces_fams329($wpc_in330, $buffer)
            : $wpc_in330;
        $wpc_out330 = (string) preg_replace('/font-display\s*:\s*optional/i', 'font-display:swap', $wpc_out330);
        if ($wpc_out330 === $wpc_in330) {
            return $buffer;
        }
        return str_replace($wpc_lm330[0], $wpc_lm330[1] . $wpc_out330 . $wpc_lm330[3], $buffer);
      } catch (\Throwable $e) {
        return $buffer;
      }
    }

    
    
    
    
    
    
    
    
    
    public static function wpc_crit_rearguard326($buffer)
    {
      try {
        if (!is_string($buffer)
            || strpos($buffer, 'wpc-crit-rearguard326') !== false
            || !apply_filters('wpc_crit_rearguard', true)
            || (strpos($buffer, 'rel="wpc-mobile-stylesheet"') === false
                && strpos($buffer, "rel='wpc-mobile-stylesheet'") === false)
            || !preg_match('/<style[^>]*id="wpc-critical-css"[^>]*>(.*?)<\/style>/s', $buffer, $wpc_cm326)) {
            return $buffer;
        }
        $wpc_rg326 = '';
        if (preg_match_all('/([^{}]{1,300})\{([^{}]*font-family:\s*var\((--et_global_[a-z_]+)\)[^{}]*)\}/i', $wpc_cm326[1], $wpc_rm326, PREG_SET_ORDER)) {
            foreach (array_slice($wpc_rm326, 0, 12) as $wpc_r326) {
                $wpc_sel326 = trim($wpc_r326[1]);
                
                if (!preg_match('/^[a-z0-6\s,]+$/i', $wpc_sel326)) {
                    continue;
                }
                
                
                
                
                
                
                
                
                $wpc_vn327 = strtolower($wpc_r326[3]);
                if (!preg_match('/' . preg_quote($wpc_vn327, '/') . ':\s*([^;}]{1,120})[;}]/i', $buffer, $wpc_vv327)) {
                    continue;
                }
                $wpc_val327 = trim($wpc_vv327[1]);
                if ($wpc_val327 === '' || strpos($wpc_val327, 'var(') !== false) {
                    continue;
                }
                $wpc_decl326 = (string) preg_replace(
                    '/var\(' . preg_quote($wpc_vn327, '/') . '\)/i',
                    'var(' . $wpc_vn327 . ',' . $wpc_val327 . ')',
                    trim($wpc_r326[2]));
                $wpc_rg326 .= $wpc_sel326 . '{' . $wpc_decl326 . '}';
            }
        }
        if ($wpc_rg326 === '' || strlen($wpc_rg326) > 8192) {
            return $buffer;
        }
        $wpc_tag326 = '<style id="wpc-crit-rearguard326">' . $wpc_rg326 . '</style>';
        $wpc_out326 = str_ireplace('</body>', $wpc_tag326 . '</body>', $buffer);
        return is_string($wpc_out326) ? $wpc_out326 : $buffer;
      } catch (\Throwable $e) {
        return $buffer;
      }
    }

    public static function wpc_css_passthrough_restore88($buffer)
    {
      try {
        if (!is_string($buffer)) {
            return $buffer;
        }
        $wpc_pass88 = get_option('wpc_css_passthrough') === '1'
            && apply_filters('wpc_css_passthrough', true);
        
        
        
        
        
        
        
        
        
        
        $wpc_carrierless323 = false;
        if (!$wpc_pass88 && apply_filters('wpc_carrierless_park_restore', true)
            && preg_match('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer)
            && strpos($buffer, 'id="wpc-critical-css"') === false
            && strpos($buffer, "id='wpc-critical-css'") === false
            && stripos($buffer, 'wpc-used-css') === false) {
            $wpc_carrierless323 = true;
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_carrierless_log323')) {
                set_transient('wpc_carrierless_log323', 1, 3600);
                wpc_cache_first_log('carrierless-park-restore', '', '', []);
            }
        }
        if (!$wpc_pass88 && !$wpc_carrierless323) {
            return $buffer;
        }
        
        
        
        $buffer = self::wpc_restore_parked_styles154($buffer);
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
        $buffer = self::wpc_restore_parked_styles154($buffer);
        }
        $buffer = self::wpc_css_live_extras93($buffer);
        if (function_exists('wpc_cache_first_log') && !get_transient('wpc_passthru_log')) {
            set_transient('wpc_passthru_log', 1, 3600);
            wpc_cache_first_log('css-passthrough', '', '', ['total' => 1]);
        }
        return $buffer;
      } catch (\Throwable $wpc_e88) { return $buffer; }
    }

    
    
    
    
    
    
    
    
    

    
    
    
    
    
    
    
    public static function wpc_restore_parked_styles154($buffer)
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

    public static function wpc_trimmed_crit_forfeit85($buffer, $ctx = 'render')
    {
      try {
        if (!is_string($buffer)
            || (strpos($buffer, 'id="wpc-critical-css"') === false && strpos($buffer, "id='wpc-critical-css'") === false)
            || !preg_match('/wpc-budget-final:\s*(?:mobile\s+|desktop\s+)?(over|ok)\s+out=(\d+)\s+cap=(\d+)/', $buffer, $wpc_bm85)
            || !apply_filters('wpc_trimmed_crit_forfeit', true)) {
            return $buffer;
        }
        
        
        
        
        
        
        
        if (defined('WPS_IC_CRITICAL') && function_exists('wpc_ua_is_mobile') && class_exists('wps_ic_url_key')
            && apply_filters('wpc_forfeit_per_device', true)) {
            $wpc_dev141 = wpc_ua_is_mobile() ? 'mobile' : 'desktop';
            $wpc_dk141 = ltrim((string) (new wps_ic_url_key())->setup(), '/');
            $wpc_df141 = $wpc_dk141 !== ''
                ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_dk141 . '/critical_' . $wpc_dev141 . '.css' : '';
            if ($wpc_df141 !== '' && @is_readable($wpc_df141)) {
                $wpc_dh141 = (string) @file_get_contents($wpc_df141, false, null, 0, 4096);
                if (preg_match('/wpc-budget-final:\s*(?:mobile\s+|desktop\s+)?(over|ok)\s+out=(\d+)\s+cap=(\d+)/', $wpc_dh141, $wpc_dm141)) {
                    $wpc_bm85 = $wpc_dm141;
                    
                    $wpc_devstamp141 = $wpc_dh141;
                }
            }
        }
        $wpc_cap85 = max(1, (int) $wpc_bm85[3]);
        
        
        
        
        
        
        $wpc_newfmt104 = preg_match('/wpc-budget-final:[^*]{0,200}\b(?:(?:inline_)?fonts=\d|dev=(?:mobile|desktop))/', isset($wpc_devstamp141) ? $wpc_devstamp141 : $buffer) === 1
            && apply_filters('wpc_trust_new_stamp', true);
        
        
        
        
        
        
        
        
        $wpc_stampre147 = '';
        if (preg_match('/wpc-budget-final:[^*]{0,200}/', isset($wpc_devstamp141) ? $wpc_devstamp141 : $buffer, $wpc_srm147)) {
            $wpc_stampre147 = $wpc_srm147[0];
        }
        
        
        
        
        $wpc_if148 = preg_match('/\binline_fonts=(\d+)/', $wpc_stampre147, $wpc_ifm148) ? (int) $wpc_ifm148[1] : 0;
        if (preg_match('/\bx=([0-9.]+)/', $wpc_stampre147, $wpc_xm148) && (float) $wpc_xm148[1] > 0) {
            $wpc_ratio147 = (float) $wpc_xm148[1];
        } else {
            $wpc_ratio147 = max(0, (int) $wpc_bm85[2] - $wpc_if148) / $wpc_cap85;
        }
        $wpc_markers147 = preg_match('/\b(?:still-over|rescope-abort|lossy)\b/', $wpc_stampre147) === 1;
        $wpc_rung147 = (float) apply_filters('wpc_forfeit_over_grace', 1.15);
        if ($wpc_bm85[1] === 'ok') {
            $wpc_lossy85 = !$wpc_newfmt104 && (int) $wpc_bm85[2] >= (int) floor($wpc_cap85 * (float) apply_filters('wpc_trim_forfeit_ratio', 0.97));
        } else {
            
            
            
            
            
            
            $wpc_wok172 = preg_match('/\bwave_ok=([01])\b/', $wpc_stampre147, $wpc_wm172) ? (int) $wpc_wm172[1] : null;
            if ($wpc_wok172 === 1) {
                $wpc_lossy85 = false;
            } elseif ($wpc_wok172 === 0) {
                $wpc_lossy85 = true;
            } else {
                $wpc_lossy85 = !($wpc_newfmt104 && !$wpc_markers147 && $wpc_ratio147 <= $wpc_rung147);
            }
        }
        
        
        
        
        
        
        if (isset($wpc_df141) && $wpc_df141 !== '' && isset($wpc_dev141)
            && apply_filters('wpc_forfeit_hysteresis', true)) {
            $wpc_sd147 = dirname($wpc_df141);
            $wpc_sf147 = $wpc_sd147 . '/forfeit_state_' . $wpc_dev141 . '.json';
            $wpc_st147 = json_decode((string) @file_get_contents($wpc_sf147), true);
            $wpc_inc147 = (is_array($wpc_st147) && isset($wpc_st147['mode'])) ? (string) $wpc_st147['mode'] : '';
            if ($wpc_lossy85) {
                if ($wpc_inc147 !== 'forfeit') {
                    @file_put_contents($wpc_sf147, '{"mode":"forfeit"}');
                }
            } else {
                $wpc_uu147 = preg_replace('/[^A-Za-z0-9-]/', '', (string) @file_get_contents($wpc_sd147 . '/land_uuid.txt'));
                
                
                
                
                
                
                
                $wpc_rel181 = isset($wpc_wok172) && $wpc_wok172 === 1;
                if ($wpc_inc147 === 'forfeit' && ($wpc_uu147 !== '' || $wpc_rel181)) {
                    $wpc_pu147 = (is_array($wpc_st147) && isset($wpc_st147['trusted_uuid'])) ? (string) $wpc_st147['trusted_uuid'] : '';
                    if (!$wpc_rel181 && $wpc_pu147 === '') {
                        @file_put_contents($wpc_sf147, '{"mode":"forfeit","trusted_uuid":"' . $wpc_uu147 . '"}');
                        $wpc_lossy85 = true;
                    } elseif (!$wpc_rel181 && $wpc_pu147 === $wpc_uu147) {
                        $wpc_lossy85 = true;
                    } else {
                        
                        
                        
                        
                        
                        
                        @file_put_contents($wpc_sf147, '{"mode":"park"}');
                        if (class_exists('wps_ic_cache_integrations')
                            && method_exists('wps_ic_cache_integrations', 'purgeCacheFiles')) {
                            try {
                                $wpc_pk176 = ltrim((string) (new wps_ic_url_key())->setup(), '/');
                                if ($wpc_pk176 !== '') {
                                    wps_ic_cache_integrations::purgeCacheFiles($wpc_pk176);
                                    if (method_exists('wps_cacheHtml', 'removeStaticMirror') && function_exists('home_url')) {
                                        wps_cacheHtml::removeStaticMirror(home_url((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)));
                                    }
                                    
                                    
                                    
                                    
                                    if (method_exists('wps_ic_cache_integrations', 'purgeEdgeHtmlUrls') && function_exists('home_url')) {
                                        $wpc_pu178 = home_url((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
                                        wps_ic_cache_integrations::purgeEdgeHtmlUrls([$wpc_pu178, rtrim($wpc_pu178, '/')]);
                                    }
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('hysteresis-release-purge', $wpc_pk176, '', ['dev' => isset($wpc_dev141) ? $wpc_dev141 : '', 'edge' => 1]);
                                    }
                                }
                            } catch (\Throwable $wpc_e176) {
                            }
                        }
                    }
                } elseif ($wpc_inc147 !== 'park') {
                    @file_put_contents($wpc_sf147, '{"mode":"park"}');
                }
            }
        }
        if (!$wpc_lossy85) {
            return $buffer;
        }
        
        
        
        
        $buffer = self::wpc_css_live_extras93($buffer);
        $buffer = self::wpc_restore_parked_styles154($buffer);
        if (!preg_match('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer)) {
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_forfeit85_log')) {
                set_transient('wpc_forfeit85_log', 1, 3600);
                wpc_cache_first_log('trimmed-crit-forfeit', '', '', ['why' => $wpc_bm85[1], 'out' => (int) $wpc_bm85[2], 'cap' => $wpc_cap85, 'variant' => $ctx, 'extras_only' => 1]);
            }
            return $buffer;
        }
        
        
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
        $buffer = self::wpc_restore_parked_styles154($buffer);
        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_forfeit85_log')) {
            set_transient('wpc_forfeit85_log', 1, 3600);
            wpc_cache_first_log('trimmed-crit-forfeit', '', '', ['why' => $wpc_bm85[1], 'out' => (int) $wpc_bm85[2], 'cap' => $wpc_cap85, 'variant' => $ctx]);
        }
        return $buffer;
      } catch (\Throwable $wpc_e85) { return $buffer; }
    }

    
    
    
    
    
    
    public static function critlessUndefer($buffer, $ctx = 'render')
    {
        if (!is_string($buffer)
            || strpos($buffer, 'id="wpc-critical-css"') !== false || strpos($buffer, "id='wpc-critical-css'") !== false) {
            return $buffer;
        }
        $buffer = self::wpc_restore_parked_styles154($buffer);
        if (!preg_match('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer)) {
            return $buffer;
        }
        
        
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
        $buffer = self::wpc_restore_parked_styles154($buffer);
        if (function_exists('wpc_cache_first_log') && !get_transient('wpc_undefer_log')) {
            set_transient('wpc_undefer_log', 1, 300);
            wpc_cache_first_log('critless-undefer', '', '', ['variant' => $ctx]);
        }
        return $buffer;
    }

    public function saveCache($buffer, $prefix = '')
    {
        if (function_exists('wpc_crit_parse_belt198')) {
            $buffer = wpc_crit_parse_belt198($buffer);
        }
        
        
        
        
        
        
        
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

        
        if (!is_string($buffer) || strlen($buffer) < 1024 || stripos($buffer, '</html>') === false) {
            $wpc_wgate('body-floor', ['len' => is_string($buffer) ? strlen($buffer) : -1]);
            return $buffer;
        }

        
        
        
        
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

        
        
        if (function_exists('wpc_lane_split_detect613')) {
            wpc_lane_split_detect613($buffer);
        }

        $buffer = self::wpc_css_passthrough_restore88($buffer);
        $buffer = self::wpc_crit_rearguard326($buffer);
        $buffer = self::wpc_late_faces_law330($buffer);

        $buffer = self::wpc_trimmed_crit_forfeit85($buffer, (string) $prefix);

        $buffer = self::critlessUndefer($buffer, (string) $prefix);

        $buffer = self::varsGuard($buffer);

        $buffer = self::typeGuard84($buffer);

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $wpc_critfam330 = [];
        if (is_string($buffer) && preg_match('/<style[^>]*id=["\']wpc-critical-css["\'][^>]*>(.*?)<\/style>/s', $buffer, $wpc_cfm330)
            && preg_match_all('/font-family\s*:\s*["\']?([^;,"\'}<]+)/i', $wpc_cfm330[1], $wpc_cff330)) {
            foreach ($wpc_cff330[1] as $wpc_cf330) {
                $wpc_cf330 = strtolower(trim($wpc_cf330));
                if ($wpc_cf330 !== '' && strlen($wpc_cf330) <= 40 && strpos($wpc_cf330, 'var(') === false
                    && strpos($wpc_cf330, '--') === false && stripos($wpc_cf330, 'fallback') === false) {
                    $wpc_critfam330[$wpc_cf330] = 1;
                }
            }
        }
        $wpc_lf327 = is_string($buffer) && strpos($buffer, 'wpc-live-faces') !== false;
        $wpc_lff327 = [];
        if ($wpc_lf327 && preg_match_all('/<style\b[^>]*wpc-live-faces[^>]*>(.*?)<\/style>/is', $buffer, $wpc_lfb327)) {
            foreach ($wpc_lfb327[1] as $wpc_lfc327) {
                if (preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $wpc_lfc327, $wpc_lfn327)) {
                    foreach ($wpc_lfn327[1] as $wpc_ln327) {
                        $wpc_lff327[strtolower(trim($wpc_ln327))] = 1;
                    }
                }
            }
        }
        if (is_string($buffer)
            && strpos($buffer, 'id="wpc-critical-css"') !== false
            && apply_filters('wpc_late_faces', true)
            && ($wpc_lf327
                || (($wpc_ss210 = (int) get_option('wpc_subsets_seen', 0)) && (time() - $wpc_ss210) < 7 * DAY_IN_SECONDS))) {
            $wpc_lfl210 = '';
            $wpc_n210 = 0;
            
            
            $wpc_pin210 = [];
            
            
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
                function ($lm) use (&$wpc_lfl210, &$wpc_n210, $wpc_pin210, $wpc_lf327, $wpc_lff327, $wpc_critfam330) {
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
                    
                    
                    
                    
                    if (strpos($rel, '/et-cache/') !== false) {
                        $wpc_fp82 = trailingslashit(ABSPATH) . $rel;
                        @unlink(preg_replace('/\.css$/', '.nofaces.css', $wpc_fp82));
                        @unlink(preg_replace('/\.css$/', '.faces.css', $wpc_fp82));
                        return $lm[0];
                    }
                    $path = trailingslashit(ABSPATH) . $rel;
                    if (!@is_readable($path)) {
                        return $lm[0];
                    }
                    $mt = (int) @filemtime($path);
                    $sib = preg_replace('/\.css$/', '.nofaces.css', $path);
                    $fsib = preg_replace('/\.css$/', '.faces.css', $path);
                    
                    
                    
                    
                    
                    
                    $wpc_rr210 = get_option('wpc_font_remote_ranges', []);
                    if (!is_array($wpc_rr210)) { $wpc_rr210 = []; }
                    if (!empty($wpc_rr210)) { ksort($wpc_rr210); }
                    $wpc_pfh210 = substr(md5(
                        implode(',', array_keys($wpc_pin210))
                        . (empty($wpc_rr210) ? '' : '|rr2:' . md5(serialize($wpc_rr210)))
                    ), 0, 8);
                    if (!@is_readable($sib) || (int) @filemtime($sib) < $mt
                        || strpos((string) @file_get_contents($sib, false, null, 0, 24), $wpc_pfh210) === false) {
                        
                        
                        if (!$wpc_lf327
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
                    
                    
                    if (!empty($wpc_critfam330)) {
                        $wpc_fbh330 = (string) @file_get_contents($fsib, false, null, 0, 65536);
                        if ($wpc_fbh330 !== '' && preg_match_all('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $wpc_fbh330, $wpc_fbf330)) {
                            foreach ($wpc_fbf330[1] as $wpc_fbn330) {
                                if (!empty($wpc_critfam330[strtolower(trim($wpc_fbn330))])) {
                                    return $lm[0];
                                }
                            }
                        }
                    }
                    $wpc_n210++;
                    $base = substr($href, 0, $cp);
                    $newHref = $base . preg_replace('/\.css$/', '.nofaces.css', $rel) . '?nf=' . $mt;
                    $faceHref = $base . preg_replace('/\.css$/', '.faces.css', $rel) . '?nf=' . $mt;
                    
                    
                    
                    
                    
                    
                    $wpc_skiplf327 = false;
                    if ($wpc_lf327 && !empty($wpc_lff327)) {
                        $wpc_fb327 = (string) @file_get_contents($fsib, false, null, 0, 65536);
                        if ($wpc_fb327 !== '' && preg_match_all('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $wpc_fb327, $wpc_ffam327)) {
                            $wpc_skiplf327 = true;
                            foreach ($wpc_ffam327[1] as $wpc_fn327) {
                                if (empty($wpc_lff327[strtolower(trim($wpc_fn327))])) {
                                    $wpc_skiplf327 = false;
                                    break;
                                }
                            }
                        }
                    }
                    if (!$wpc_skiplf327) {
                        $wpc_lfl210 .= '<link rel="stylesheet" data-wpc-lf-href="' . esc_url($faceHref) . '" media="not all" data-wpc-lf="1" />';
                    }
                    return str_replace($hm[1], esc_url($newHref), $lm[0]);
                },
                $buffer
            );
            if ($wpc_lfl210 !== '') {
                $buffer = str_ireplace('</head>', $wpc_lfl210 . '</head>', $buffer);
            }
        }

        
        
        
        
        
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
            if (function_exists('wpc_render_armed_for_cache') && !wpc_render_armed_for_cache($buffer)) {
                
                
                
                
                $wpc_why195 = 'crit-missing';
                if (strpos((string) $buffer, 'id="wpc-arm-sentinel"') !== false) { $wpc_why195 = 'collecting'; }
                if (!is_string($buffer) || $buffer === '') { $wpc_why195 = 'empty-buffer'; }
                elseif (!empty($_GET['criticalCombine'])) { $wpc_why195 = 'combine-param'; }
                if (!empty($GLOBALS['wpc_unarmed_why342'])) { $wpc_why195 = (string) $GLOBALS['wpc_unarmed_why342']; }
                header('X-WPC-Update-Window: degraded');
                header('X-WPC-CC: window-unarmed-' . $wpc_why195);
                unset($GLOBALS['wpc_cc_guarded']);
            } else {
                
                
                
                
                
                if (function_exists('header_remove') && apply_filters('wpc_strip_setcookie_on_public', true)) {
                    @header_remove('Set-Cookie');
                }
                $wpc_hma174 = max(0, (int) apply_filters('wpc_html_max_age', 300));
                $wpc_sm178  = function_exists('wpc_edge_smaxage') ? wpc_edge_smaxage() : 0;
                
                
                
                if (function_exists('header_remove')) {
                    @header_remove('Pragma');
                }
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $wpc_hma174) . ' GMT');
                header('Cache-Control: ' . wpc_cc_freshness($wpc_hma174, $wpc_sm178,
                    function_exists('wpc_edge_swr') ? wpc_edge_swr() : 0));
                
                
                
                
                
                
                
                header('Server-Timing: wpc-mint;desc=' . time(), false);
                
                
                
                if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_nat_why808')) {
                    header('Server-Timing: wpc-nat;desc=' . wps_rewriteLogic::wpc_nat_why808(), false);
                }
                unset($GLOBALS['wpc_cc_guarded']);
            }
        }


        
        
        
        if (function_exists('wpc_render_armed_for_cache') && !wpc_render_armed_for_cache($buffer)) {
            $wpc_wgate('unarmed');
            return $buffer;
        }

        if (empty($buffer) || strlen($buffer) < 100 || strpos($buffer, '</body>') === false) {
            $wpc_wgate('thin-buffer', ['len' => strlen((string) $buffer)]);
            return $buffer;
        }

        
        
        
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

        
        if (defined('WPC_EXCLUDE_COOKIES')) {
            if (WPC_EXCLUDE_COOKIES !== false && is_array(WPC_EXCLUDE_COOKIES)) {
                foreach ($_COOKIE as $cookieName => $cookieValue) {
                    foreach (WPC_EXCLUDE_COOKIES as $excludedCookie) {

                        
                        if (substr($excludedCookie, -1) === '_') {
                            if (stripos($cookieName, $excludedCookie) === 0) {
                                return $buffer; 
                            }
                        } else {
                            
                            if (strcasecmp($cookieName, $excludedCookie) === 0) {
                                return $buffer; 
                            }
                        }
                    }
                }
            }
        }

        
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
                        return $buffer; 
                    }
                } else {
                    if (empty($_COOKIE[$mandatoryCookie])) {
                        return $buffer; 
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

        
        $primary_markers = ['widget_recent_entries', 'wp-block-latest-posts', 'class="recent-posts'];

        
        foreach ($primary_markers as $marker) {
            if (strpos($buffer, $marker) !== false) {
                return true;
            }
        }

        
        if (strpos($buffer, '[recent_posts') !== false || strpos($buffer, '[display-posts') !== false) {
            return true;
        }

        return false;
    }

    public function is_mobile()
    {
        
        
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
            
            
            
            if (function_exists('update_option')) {
                update_option('wpc_cache_lastwrite288', [
                    't' => time(), 'key' => (string) $this->urlKey, 'variant' => (string) $prefix,
                    'bytes' => (int) @filesize($final), 'path_tail' => substr($final, -80),
                ], false);
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
        
        
        $host = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return;
        }
        $uri  = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $uri  = rtrim($uri, '/');
        
        
        if (strpos($uri, '..') !== false || strpos($uri, "\0") !== false || strpos($host, '/') !== false) {
            return;
        }
        
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
        
        
        
        self::ensureStaticMirrorHeaderHtaccess($dir, 'https://' . $host . ($uri === '' ? '/' : $uri . '/'));
    }


    public static function wpc_mirror_url_tag($url)
    {
        
        
        
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
        
        
        
        $wpc_murl673 = ($urlOverride !== null && $urlOverride !== '') ? (string) $urlOverride : home_url('/');
        $dir  = ($dirOverride !== null && $dirOverride !== '')
            ? rtrim((string) $dirOverride, '/') . '/'
            : rtrim(WPS_IC_CACHE, '/') . '/' . $host . '/';
        $file = $dir . '.htaccess';
        $wpc_utag673 = self::wpc_mirror_url_tag($wpc_murl673);

        
        


        
        


        
        
        
        
        
        $wpc_sm569 = function_exists('wpc_edge_smaxage') ? (int) wpc_edge_smaxage() : 0;
        
        
        
        
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
        
        
        
        
        
        
        $wpc_swr = function_exists('wpc_edge_swr') ? (int) wpc_edge_swr() : 0;
        $wpc_cc  = function_exists('wpc_cc_freshness')
            ? wpc_cc_freshness($wpc_hma, $wpc_sm569, $wpc_swr)
            : 'public, max-age=' . $wpc_hma
                . ($wpc_sm569 > 0 ? ', s-maxage=' . $wpc_sm569 . ', stale-while-revalidate=86400'
                    : ($wpc_swr > 0 ? ', stale-while-revalidate=' . $wpc_swr : ', must-revalidate'));
        
        
        
        
        
        
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

        
        
        
        foreach (['index.html_gzip', 'index.html'] as $wpc_cf178) {
            $wpc_cfp178 = $this->cachePath . $prefix . $wpc_cf178;
            if (@file_exists($wpc_cfp178) && (int) @filesize($wpc_cfp178) < 1024) {
                @unlink($wpc_cfp178);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('serve-drop-body-floor', '', '', ['f' => $prefix . $wpc_cf178]);
                }
            }
        }

        
        foreach (['index.html_br', 'index.html_gzip', 'index.html'] as $wpc_cf146) {
            $wpc_cfp146 = $this->cachePath . $prefix . $wpc_cf146;
            if (function_exists('wpc_dcv_stale146') && @file_exists($wpc_cfp146) && wpc_dcv_stale146($wpc_cfp146)) {
                @unlink($wpc_cfp146);
                @unlink($this->cachePath . $prefix . 'index.html_md5');
            }
        }

        if (function_exists('readgzfile')) {
            if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && is_readable($this->cachePath . $prefix . 'index.html' . '_gzip')) {
                $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html' . '_gzip');
                
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


        
        header('Server-Timing: wpc-cache;desc=hit', false);

        header('X-Cache-By: Advanced Cache - Gzip');
    }

    public function removeCacheFiles($post_id)
    {
        if ($post_id == 'home') {
            $post_id = 0;
        }

        if ($post_id == 'all') {
            
            
            $wpc_jf179 = rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl';
            $wpc_jl179 = '';
            if (@is_readable($wpc_jf179)) {
                $wpc_js179 = (int) @filesize($wpc_jf179);
                $wpc_jl179 = (string) @file_get_contents($wpc_jf179, false, null, max(0, $wpc_js179 - 65536));
            }
            
            
            
            
            
            $wpc_keep179 = ['css', 'js', 'wpc-cflog.jsonl'];
            $wpc_root179 = rtrim(WPS_IC_CACHE, '/');
            
            
            
            
            
            
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
                    
                    @rename($wpc_src530, $wpc_tomb530 . '/' . $wpc_pk530);
                }
            }
            foreach ((array) @scandir($wpc_root179) as $wpc_it179) {
                if ($wpc_it179 === '.' || $wpc_it179 === '..' || in_array($wpc_it179, $wpc_keep179, true)) {
                    continue;
                }
                if (strpos($wpc_it179, '.purging-') === 0) {
                    continue; 
                }
                $wpc_ip179 = $wpc_root179 . '/' . $wpc_it179;
                is_dir($wpc_ip179) ? self::removeDirectory($wpc_ip179) : @unlink($wpc_ip179);
            }
            
            
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
        
        $files = glob($folder . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } else {
                $this->recursiveDelete($file);
            }
        }

        
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