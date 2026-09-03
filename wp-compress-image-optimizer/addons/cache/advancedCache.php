<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/advancedCache.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */


define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');



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

	      
	      $user_hash = '';
				if (defined('WPC_CACHE_LOGGED_IN') && WPC_CACHE_LOGGED_IN){
						foreach ( $_COOKIE as $key => $value ) {
							if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
								$user_hash = md5( $key . substr( $value, 0, 10 ) ) . '/';
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
        
        if ($this->isWooFragments()) {
            return true;
        }

        
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

        
        if ($is_excluded_page || str_contains($request_uri, 'wc-ajax')) {
            return true;
        }

        
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

        
        
        foreach (['index.html_gzip', 'index.html'] as $wpc_cf178) {
            $wpc_cfp178 = $this->cachePath . $prefix . $wpc_cf178;
            if (@file_exists($wpc_cfp178) && (int) @filesize($wpc_cfp178) < 1024) {
                @unlink($wpc_cfp178);
                
                
                @unlink($this->cachePath . $prefix . 'index.html_br');
                @unlink($this->cachePath . $prefix . 'index.html_md5');
            }
        }

        
        foreach (['index.html_br', 'index.html_gzip', 'index.html'] as $wpc_cf146) {
            $wpc_cfp146 = $this->cachePath . $prefix . $wpc_cf146;
            if (function_exists('wpc_dcv_stale146') && @file_exists($wpc_cfp146) && wpc_dcv_stale146($wpc_cfp146)) {
                @unlink($wpc_cfp146);
                @unlink($this->cachePath . $prefix . 'index.html_md5');
            }
        }

        
        
        
        
        
        
        
        
        
        
        
        
        
        $wpc_ae662 = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $wpc_cfpass662 = isset($_SERVER['HTTP_CF_RAY'])
            && (!function_exists('apply_filters') || apply_filters('wpc_br_cf_passthrough', true));
        if (strpos($wpc_ae662, 'br') !== false || $wpc_cfpass662) {
            $wpc_br647 = $this->cachePath . $prefix . 'index.html_br';
            if (@file_exists($wpc_br647) && @is_readable($wpc_br647) && (int) @filesize($wpc_br647) > 512) {
                
                
                
                
                
                
                
                
                
                
                
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
        
        
        if (function_exists('header_remove')) {
            @header_remove('Set-Cookie');
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');


        $wpc_hma49 = max(0, (int) apply_filters('wpc_html_max_age', 300));
        
        $wpc_sm210 = 0;
        if (function_exists('get_option')) {
            $wpc_st210 = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            
            
            
            
            
            
            $wpc_cc210 = (is_array($wpc_st210) && isset($wpc_st210['combined-crit']))
                ? (string) $wpc_st210['combined-crit'] : '';
            if ($wpc_cc210 === '1') {
                $wpc_on210 = true;
            } elseif ($wpc_cc210 === '0') {
                $wpc_on210 = false;
            } else {
                
                
                if (apply_filters('wpc_split_default_on', true)) {
                    $wpc_on210 = false;
                } else {
                    $wpc_cf210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                    $wpc_on210 = is_array($wpc_cf210) && !empty($wpc_cf210['token']) && !empty($wpc_cf210['zone'])
                        && !(is_array($wpc_st210) && !empty($wpc_st210['minimal-mobile-css'])
                            && $wpc_st210['minimal-mobile-css'] == '1');
                }
            }
            
            
            
            
            if (!$wpc_on210 && apply_filters('wpc_combined_crit_devkey_floor', true)) {
                $wpc_cfx210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                if (is_array($wpc_cfx210) && !empty($wpc_cfx210['token']) && !empty($wpc_cfx210['zone'])) {
                    $wpc_dk210 = get_option('wpc_cf_devkey_verified');
                    
                    
                    if (!is_array($wpc_dk210) || empty($wpc_dk210['devkey'])
                        || (isset($wpc_dk210['src']) ? (string) $wpc_dk210['src'] : '') !== 'readback') {
                        $wpc_on210 = true;
                    }
                }
            }
            
            
            if (!$wpc_on210) {
                $wpc_cfd210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                if (!(is_array($wpc_cfd210) && !empty($wpc_cfd210['token']) && !empty($wpc_cfd210['zone']))
                    && function_exists('wpc_foreign_device_blind_cache') && wpc_foreign_device_blind_cache()) {
                    $wpc_on210 = true;
                }
            }
            
            
            
            
            $wpc_cfsm210 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            if (is_array($wpc_cfsm210) && !empty($wpc_cfsm210['token']) && !empty($wpc_cfsm210['zone'])
                && apply_filters('wpc_edge_smaxage_on', true)) {
                $wpc_sm210 = max(0, (int) apply_filters('wpc_cf_html_edge_ttl', 86400));
            }
        }
        header('Cache-Control: ' . wpc_cc_freshness($wpc_hma49, $wpc_sm210, wpc_edge_swr()));
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $wpc_hma49) . ' GMT');
        
        
        
        
        
        header('Vary: Accept-Encoding');


        $wpc_th105 = strtolower((string) preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
        if (strpos($wpc_th105, 'www.') === 0) { $wpc_th105 = substr($wpc_th105, 4); }
        $wpc_tp105 = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
        $wpc_tp105 = '/' . trim($wpc_tp105, '/');
        if ($wpc_tp105 !== '/') { $wpc_tp105 .= '/'; }
        if ($wpc_th105 !== '') {
            header('Cache-Tag: wpc-html,wpc-u-' . substr(md5($wpc_th105 . $wpc_tp105), 0, 20), false);
        }


        
        header('Server-Timing: wpc-cache;desc=hit', false);

		    $headerCacheFile = $this->cachePath . 'headers.json';
		    
		    if (file_exists($headerCacheFile)) {

			    $cachedHeadersJson = file_get_contents($headerCacheFile);
			    $cachedHeaders = json_decode($cachedHeadersJson, true);

			    
			    $existingHeaders = array();
			    foreach (headers_list() as $header) {
				    $parts = explode(':', $header, 2);
				    if (count($parts) == 2) {
					    $existingHeaders[trim($parts[0])] = true;
				    }
			    }

			    
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

}