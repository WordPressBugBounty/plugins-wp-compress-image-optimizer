<?php


class wps_ic_fonts
{


    public $stylesheet;
    public $stylesheetMap;
    private $url;


    public static function versionedFontsCssUrl($rel)
    {
        $url = WPS_IC_FONTS_URL . $rel;
        $mt  = @filemtime(WPS_IC_FONTS_DIR . $rel);
        return $mt ? $url . '?fdv=' . (int) $mt : $url;
    }
    
    private $filename;
    
    private $path;
    private $response;
    private $foundFonts;
    private $mimeMap = ['font/woff2' => 'woff2', 'application/font-woff2' => 'woff2', 'font/woff' => 'woff', 'application/font-woff' => 'woff', 'font/ttf' => 'ttf', 'application/x-font-ttf' => 'ttf', 'font/sfnt' => 'ttf', 
        'application/font-sfnt' => 'ttf', 'font/otf' => 'otf', 'application/x-font-opentype' => 'otf', 'application/vnd.ms-fontobject' => 'eot',];


    public function __construct()
    {
        $this->path = WPS_IC_FONTS_DIR;
        $this->stylesheetMap = get_option(WPS_IC_FONTS_MAP);
    }


    public function isActive() {
        $options = get_option(WPS_IC_SETTINGS);
        if (!empty($options) && !empty($options['replace-fonts'])) {
            
            if ($options['replace-fonts'] == 'local') {
                return 'local';
            } else if ($options['replace-fonts'] == 'bunny') {
                return 'bunny';
            }
        }

        return false;
    }


    public function listFoundFonts()
    {
        return $this->stylesheetMap;
    }


    public function replaceFrontend($html)
    {
        if (!empty($this->stylesheetMap)) {

            foreach ($this->stylesheetMap as $style => $replaceData) {
                if (empty($replaceData) || empty($replaceData['filename'])) {
                    continue;
                }

                if (!file_exists(WPS_IC_FONTS_DIR . $replaceData['dir'] . '/' . $replaceData['filename'])) {
                    continue;
                }

                $replaceUrl = WPS_IC_FONTS_URL . $replaceData['dir'] . '/' . $replaceData['filename'];
                $styleEncoded = str_replace('&', '&#038;', $style);

                
                $html = str_replace($styleEncoded, $replaceUrl, $html);
                $html = str_replace($style, $replaceUrl, $html);
            }


            $fixed = preg_replace_callback(
                '~(' . preg_quote(WPS_IC_FONTS_URL, '~') . '[^"\'\s>()]+?\.css)(?:[?&](?:#038;)?[^"\'\s>()]*)?~i',
                function ($m) {
                    $p = str_replace(WPS_IC_FONTS_URL, WPS_IC_FONTS_DIR, $m[1]);
                    $mt = @filemtime($p);
                    return $mt ? $m[1] . '?fdv=' . (int) $mt : $m[1];
                },
                $html
            );
            if (is_string($fixed)) {
                $html = $fixed;
            }
        }


        if (!empty($this->stylesheetMap)
            && (stripos($html, 'fonts.googleapis.com/css') !== false || stripos($html, 'fonts.bunny.net/css') !== false)) {
            $wpc_famsOf48 = function ($u) {
                $u = html_entity_decode((string) $u, ENT_QUOTES);
                if (!preg_match_all('/family=([^&]+)/i', $u, $fm48)) { return []; }
                $out = [];
                foreach ($fm48[1] as $f48) {
                    $n = strtolower(trim(str_replace('+', ' ', explode(':', urldecode($f48))[0])));
                    if ($n !== '') { $out[$n] = 1; }
                }
                return $out;
            };
            $wpc_entries48 = [];
            foreach ((array) $this->stylesheetMap as $wpc_style48 => $wpc_rd48) {
                if (empty($wpc_rd48['filename']) || empty($wpc_rd48['dir'])) { continue; }
                if (!file_exists(WPS_IC_FONTS_DIR . $wpc_rd48['dir'] . '/' . $wpc_rd48['filename'])) { continue; }
                $wpc_f48 = $wpc_famsOf48($wpc_style48);
                if (!empty($wpc_f48)) {
                    $wpc_entries48[] = ['fams' => $wpc_f48, 'url' => self::versionedFontsCssUrl($wpc_rd48['dir'] . '/' . $wpc_rd48['filename'])];
                }
            }
            if (!empty($wpc_entries48)) {
                $html = preg_replace_callback('/<link\b[^>]*href=["\']((?:https?:)?\/\/fonts\.(?:googleapis\.com|bunny\.net)\/css[^"\']*)["\'][^>]*>/i', function ($m48) use ($wpc_famsOf48, $wpc_entries48) {
                    $lf = $wpc_famsOf48($m48[1]);
                    if (empty($lf)) { return $m48[0]; }
                    foreach ($wpc_entries48 as $e48) {
                        if (!array_diff_key($lf, $e48['fams'])) {
                            return str_replace($m48[1], $e48['url'], $m48[0]);
                        }
                    }
                    return $m48[0];
                }, $html);
            }
        }


        if (stripos($html, 'fonts.googleapis.com/css') !== false
            && apply_filters('wpc_font_auto_rescan', true)
            && function_exists('get_transient') && !get_transient('wpc_font_rescan_lock')
            && function_exists('wp_schedule_single_event')) {
            set_transient('wpc_font_rescan_lock', 1, 6 * HOUR_IN_SECONDS);
            wp_schedule_single_event(time() + 60, 'wpc_font_rescan');
        }


        if (apply_filters('wpc_font_runtime_intercept', true)
            && stripos($html, '</body>') !== false && stripos($html, 'wpc-font-rt') === false) {
            $wpc_rt_map = [];
            if (!empty($this->stylesheetMap) && is_array($this->stylesheetMap)) {
                foreach ($this->stylesheetMap as $wpc_rt_style => $wpc_rt_d) {
                    if (empty($wpc_rt_d) || empty($wpc_rt_d['filename']) || empty($wpc_rt_d['dir'])) { continue; }
                    if (!file_exists(WPS_IC_FONTS_DIR . $wpc_rt_d['dir'] . '/' . $wpc_rt_d['filename'])) { continue; }
                    $wpc_rt_key = html_entity_decode((string) $wpc_rt_style, ENT_QUOTES);
                    $wpc_rt_map[$wpc_rt_key] = self::versionedFontsCssUrl($wpc_rt_d['dir'] . '/' . $wpc_rt_d['filename']);
                }
            }
            $wpc_rt_js = '<script id="wpc-font-rt">(function(){try{var M=' . wp_json_encode($wpc_rt_map)
                . ',A=' . wp_json_encode(admin_url('admin-ajax.php')) . ';'
                . 'function h(l){var u=l.getAttribute("href")||"";if(u.indexOf("fonts.googleapis.com/css")===-1&&u.indexOf("fonts.bunny.net/css")===-1)return;'
                . 'if(M[u]){l.setAttribute("href",M[u]);return;}'
                . 'var k="wpcfs_"+u.slice(0,120);try{if(sessionStorage.getItem(k))return;sessionStorage.setItem(k,"1");}catch(e){}'
                . 'try{var f=new FormData();f.append("action","wpc_font_seen");f.append("u",u);navigator.sendBeacon(A,f);}catch(e){}}'
                . 'new MutationObserver(function(ms){for(var i=0;i<ms.length;i++){var a=ms[i].addedNodes;for(var j=0;j<a.length;j++){var n=a[j];'
                . 'if(n.tagName==="LINK")h(n);else if(n.querySelectorAll){var ls=n.querySelectorAll("link[href]");for(var q=0;q<ls.length;q++)h(ls[q]);}}}})'
                . '.observe(document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';
            $html = wpc_body_inject809($html, $wpc_rt_js . "\n");
        }


        $html = $this->replaceInlineFonts($html);


        $html = $this->wpc_inline_fonts_css($html);


        if (function_exists('wpc_perf_debug_allowed741') && wpc_perf_debug_allowed741()) {
            $wpc_fmap = get_option(self::INLINE_MAP);
            $wpc_flat = $this->findInlineGstaticFaces($html);
            $html .= "\r\n<!-- WPC-FONT-DEBUG replace_fonts=" . $this->isActive()
                . " | gstatic_on_page=" . substr_count($html, 'fonts.gstatic.com')
                . " | latin_inline_faces=" . count($wpc_flat)
                . " | mapped=" . (is_array($wpc_fmap) ? count($wpc_fmap) : 0)
                . " | throttle=" . (get_transient('wpc_font_inline_lock') ? 'LOCKED' : 'clear')
                . " | fpm=" . (function_exists('fastcgi_finish_request') ? 'yes' : 'NO(cron-only)')
                . " -->";
        }

        return $html;
    }


    public function wpc_inline_fonts_css($html)
    {
        try {
            if (!is_string($html) || $html === '' || !apply_filters('wpc_fonts_css_inline', true)) {
                return $html;
            }
            if (!defined('WPS_IC_FONTS_URL') || !defined('WPS_IC_FONTS_DIR')) {
                return $html;
            }
            if (stripos($html, (string) WPS_IC_FONTS_URL) === false) {
                return $html;
            }
            
            
            if (function_exists('wpc_fonts_htaccess_ensure')) {
                wpc_fonts_htaccess_ensure(WPS_IC_FONTS_DIR);
            }
            $wpc_max132 = (int) apply_filters('wpc_fonts_css_inline_max', 24576);


            $wpc_armed133 = (strpos($html, 'id="wpc-critical-css"') !== false);
            $wpc_deferred_href133 = '';
            $wpc_out132 = preg_replace_callback(
                '~<link\b[^>]*href=["\'](' . preg_quote((string) WPS_IC_FONTS_URL, '~') . '[^"\']+?\.css)(?:\?[^"\']*)?["\'][^>]*>~i',
                function ($m) use ($wpc_max132, $wpc_armed133, &$wpc_deferred_href133, $html) {
                    if (stripos($m[0], 'wpc-late-stylesheet') !== false) { return $m[0]; } 
                    if (preg_match('/media\s*=\s*["\']?print/i', $m[0])) { return $m[0]; }
                    if (stripos($m[0], 'as=') !== false && stripos($m[0], 'preload') !== false) { return $m[0]; }
                    $p = str_replace((string) WPS_IC_FONTS_URL, (string) WPS_IC_FONTS_DIR, $m[1]);
                    $css = @file_get_contents($p);
                    if (is_string($css) && $css !== '' && strlen($css) <= $wpc_max132
                        && stripos($css, '</style') === false && stripos($css, '<script') === false) {
                        
                        
                        
                        
                        
                        
                        
                        $wpc_split529 = self::wpc_atf_scope_faces529($css, $html);
                        if ($wpc_split529 !== null) {
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            if (trim((string) $wpc_split529['atf']) === ''
                                && apply_filters('wpc_defer_link_over_inline', true)) {
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                
                                $wpc_lf589 = self::wpc_faces_live589($wpc_split529['rest'], 'wpc-fonts-css-faces');
                                if (preg_match('/(?<![\w.$])(?:rel|type)\s*=\s*(["\'])wpc-(?:mobile-)?stylesheet\1/i', $m[0])) {
                                    $wpc_deferred_href133 = $m[1];
                                    return $wpc_lf589 . $m[0];
                                }
                                if (preg_match('/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i', $m[0])) {
                                    $wpc_deferred_href133 = $m[1];
                                    return $wpc_lf589 . (string) preg_replace(
                                        '/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i',
                                        'rel="wpc-mobile-stylesheet"',
                                        $m[0],
                                        1
                                    );
                                }
                            }
                            return '<style id="wpc-fonts-css">' . $wpc_split529['atf'] . '</style>'
                                . self::wpc_faces_live589($wpc_split529['rest'], 'wpc-fonts-css-rest');
                        }
                        return '<style id="wpc-fonts-css">' . $css . '</style>';
                    }

                    
                    if ($wpc_armed133 && preg_match('/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i', $m[0])) {
                        $wpc_deferred_href133 = $m[1];
                        return (string) preg_replace('/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i', 'rel="wpc-mobile-stylesheet"', $m[0], 1);
                    }
                    return $m[0];
                },
                $html, 2);
            if (is_string($wpc_out132)) {
                $html = $wpc_out132;
            }


            if ($wpc_deferred_href133 !== '' || strpos($html, 'id="wpc-fonts-css"') !== false) {
                $wpc_out133 = preg_replace_callback(
                    '~<link\b[^>]*rel=["\']preload["\'][^>]*href=["\']' . preg_quote((string) WPS_IC_FONTS_URL, '~') . '[^"\']*\.css[^"\']*["\'][^>]*>\s*~i',
                    function ($pm) { return (stripos($pm[0], 'as="style"') !== false || stripos($pm[0], "as='style'") !== false) ? '' : $pm[0]; },
                    $html, 2);
                if (is_string($wpc_out133)) {
                    $html = $wpc_out133;
                }
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }


    const INLINE_DIR = 'inline';
    const INLINE_MAP = 'wps_ic_fonts_inline_map';


    public function replaceInlineFonts($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_localize_inline_fonts', true)) {
            return $html;
        }
        if (stripos($html, 'fonts.gstatic.com') === false) {
            return $html;
        }
        $map = get_option(self::INLINE_MAP);
        if (!empty($map) && is_array($map)) {
            $pairs = [];
            foreach ($map as $gurl => $fname) {
                if (empty($fname) || !is_string($fname) || !is_string($gurl)) {
                    continue;
                }
                if (!file_exists(WPS_IC_FONTS_DIR . self::INLINE_DIR . '/' . $fname)) {
                    continue;
                }
                $lurl = WPS_IC_FONTS_URL . self::INLINE_DIR . '/' . $fname;
                $pairs[$gurl] = $lurl;
                $enc = str_replace('&', '&#038;', $gurl);
                if ($enc !== $gurl) {
                    $pairs[$enc] = $lurl;
                }
            }
            if (!empty($pairs)) {
                $out = strtr($html, $pairs);
                if (is_string($out)) {
                    $html = $out;
                }
            }
        }


        
        
        if (strpos($html, WPS_IC_FONTS_URL . self::INLINE_DIR . '/') !== false) {
            $wpc_face_key107 = function ($block) {
                $fam = preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $block, $m1) ? strtolower(trim($m1[1])) : '';
                $wgt = preg_match('/font-weight\s*:\s*([0-9]{3}|normal|bold)\b/i', $block, $m2) ? strtolower($m2[1]) : '400';
                $wgt = ($wgt === 'normal') ? '400' : (($wgt === 'bold') ? '700' : $wgt);
                $sty = preg_match('/font-style\s*:\s*(italic|oblique)/i', $block) ? 'italic' : 'normal';
                return $fam . '|' . $wgt . '|' . $sty;
            };
            $wpc_embedded107 = [];
            if (strpos($html, 'wpc-fonts-embedded') !== false
                && preg_match_all('#@font-face\s*\{[^{}]*data:font/woff2;base64[^{}]*\}#i', $html, $wpc_em107)) {
                foreach ($wpc_em107[0] as $wpc_eb107) {
                    $wpc_embedded107[$wpc_face_key107($wpc_eb107)] = 1;
                }
            }
            $wpc_inl_base107 = WPS_IC_FONTS_URL . self::INLINE_DIR . '/';
            $wpc_html107 = preg_replace_callback('#@font-face\s*\{[^{}]*\}#i', function ($m) use ($wpc_inl_base107, $wpc_embedded107, $wpc_face_key107) {
                if (strpos($m[0], $wpc_inl_base107) === false) {
                    return $m[0];
                }
                if (!empty($wpc_embedded107) && isset($wpc_embedded107[$wpc_face_key107($m[0])])) {
                    return '/*wpc-inline-face-standdown*/';
                }
                $wpc_ff210 = preg_replace('/font-display\s*:\s*[^;}]+;?/i', '', $m[0]);
                $wpc_fd313 = function_exists('wpc_font_display_effective') ? wpc_font_display_effective('swap') : 'swap';
                return preg_replace('/\{/', '{font-display:' . $wpc_fd313 . ';', $wpc_ff210, 1);
            }, $html);
            if (is_string($wpc_html107) && $wpc_html107 !== '') {
                $html = $wpc_html107;
            }
        }
        
        $this->maybeScheduleInlineLocalize($html);
        return $html;
    }

    
    protected function maybeScheduleInlineLocalize($html)
    {
        if ($this->isActive() !== 'local' || !apply_filters('wpc_localize_inline_fonts', true)) {
            return;
        }
        if (stripos($html, 'fonts.gstatic.com') === false || get_transient('wpc_font_inline_lock')) {
            return;
        }

        
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return;
        }

        
        
        $wpc_fil330 = (int) get_option('wpc_font_inline_at');
        if (time() - $wpc_fil330 < 15 * MINUTE_IN_SECONDS) {
            return;
        }
        update_option('wpc_font_inline_at', time(), false);

        set_transient('wpc_font_inline_lock', 1, 15 * MINUTE_IN_SECONDS);
        $latin = $this->findInlineGstaticFaces($html);
        if (empty($latin)) {
            return;
        }
        $map = get_option(self::INLINE_MAP);
        if (!is_array($map)) {
            $map = [];
        }
        $unmapped = false;
        foreach ($latin as $g) {
            if (empty($map[$g]) || !file_exists(WPS_IC_FONTS_DIR . self::INLINE_DIR . '/' . $map[$g])) {
                $unmapped = true;
                break;
            }
        }
        if (!$unmapped) {
            return;
        }
        set_transient('wpc_font_inline_lock', 1, 30 * MINUTE_IN_SECONDS);


        $wpc_inline_html = $html;
        $wpc_can_flush = function_exists('fastcgi_finish_request') && function_exists('register_shutdown_function');
        if ($wpc_can_flush) {
            register_shutdown_function(function () use ($wpc_inline_html) {
                @fastcgi_finish_request();
                if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
                if (function_exists('set_time_limit')) { @set_time_limit(120); }
                try {
                    $n = (int) $this->localizeInlineFonts($wpc_inline_html);
                    if ($n > 0 && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                        try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                    }
                    if ($n > 0) {


                        delete_transient('wpc_font_inline_lock');
                    }
                    if ($n > 0 && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('font-inline-localized', '', '', ['n' => $n, 'via' => 'flush']);
                    }
                } catch (\Throwable $e) {
                }
            });
        }
        
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_font_inline_localize_cron')) {
            wp_schedule_single_event(time() + ($wpc_can_flush ? 90 : 15), 'wpc_font_inline_localize_cron');
            if (!$wpc_can_flush && function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }
        }
    }


    public function findInlineGstaticFaces($html)
    {
        $out = [];
        if (!is_string($html) || stripos($html, 'fonts.gstatic.com') === false) {
            return $out;
        }
        if (!preg_match_all('/@font-face\s*\{[^}]*\}/is', $html, $blocks)) {
            return $out;
        }
        foreach ($blocks[0] as $blk) {
            if (stripos($blk, 'fonts.gstatic.com') === false) {
                continue;
            }
            if (preg_match('/unicode-range\s*:\s*([^;}]+)/i', $blk, $ur) && !$this->isLatinRange($ur[1])) {
                continue;
            }
            if (preg_match_all('#https?://fonts\.gstatic\.com/[^\s\'")]+\.woff2?#i', $blk, $u)) {
                foreach ($u[0] as $g) {
                    $out[$g] = 1;
                }
            }
        }
        return array_keys($out);
    }

    
    protected function isLatinRange($range)
    {
        if (!preg_match_all('/U\+([0-9A-Fa-f]{1,6})/', (string) $range, $m)) {
            return true;
        }
        foreach ($m[1] as $hex) {
            if (hexdec($hex) < 0x0370) {
                return true;
            }
        }
        return false;
    }


    public function localizeInlineFonts($html = '')
    {
        if ($this->isActive() !== 'local' || !apply_filters('wpc_localize_inline_fonts', true)) {
            return 0;
        }
        if ($html === '') {
            $ua   = defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'WPCompress';
            $resp = wp_remote_get(home_url('/'), ['timeout' => 8, 'redirection' => 3,
                'headers' => ['User-Agent' => $ua, 'X-WPC-Cache-Warm' => '1']]);
            if (is_wp_error($resp)) {
                return 0;
            }
            $html = (string) wp_remote_retrieve_body($resp);
        }
        $urls = $this->findInlineGstaticFaces($html);
        if (empty($urls)) {
            return 0;
        }
        $map = get_option(self::INLINE_MAP);
        if (!is_array($map)) {
            $map = [];
        }
        $cap     = (int) apply_filters('wpc_localize_inline_fonts_cap', 60);
        $done    = 0;
        $changed = false;
        foreach ($urls as $g) {
            if ($done >= $cap) {
                break;
            }
            if (!empty($map[$g]) && file_exists(WPS_IC_FONTS_DIR . self::INLINE_DIR . '/' . $map[$g])) {
                continue;
            }
            $res = $this->download($g, self::INLINE_DIR);
            if (is_array($res) && empty($res['error']) && !empty($res['filename'])) {
                $map[$g] = $res['filename'];
                $done++;
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::INLINE_MAP, $map, false);
        }
        return $done;
    }


    public function callAPI($urlToScan)
    {
        $this->url = $urlToScan;

        $response = wp_remote_get(add_query_arg(array('url' => esc_url_raw($urlToScan),), WPS_IC_FONTS_SCAN), array('method' => 'POST', 'timeout' => 15, 'headers' => array('Content-Type' => 'application/json',),));

        
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        
        $body = wp_remote_retrieve_body($response);

        
        $data = json_decode($body, true);
        $this->response = $data;

        return $data;
    }


    public function localScan($url)
    {
        $found = ['googleFontsStylesheets' => [], 'gstaticUrls' => []];
        $u = (string) $url;
        if ($u === '') {
            return $found;
        }
        $u .= (strpos($u, '?') === false ? '?' : '&') . 'disableWPC=true';
        $r = wp_remote_get($u, ['timeout' => 20, 'sslverify' => false, 'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            return $found;
        }
        $html = (string) wp_remote_retrieve_body($r);
        if ($html === '') {
            return $found;
        }
        if (preg_match_all('/<link\b[^>]*href=["\']((?:https?:)?\/\/fonts\.(?:googleapis\.com|bunny\.net)\/css[^"\']*)["\']/i', $html, $lm)) {
            foreach (array_unique($lm[1]) as $l) {
                $l = html_entity_decode((string) $l, ENT_QUOTES);
                if (strpos($l, '//') === 0) { $l = 'https:' . $l; }
                $found['googleFontsStylesheets'][] = $l;
            }
        }
        if (preg_match_all('/https?:\/\/fonts\.gstatic\.com\/[^"\'\s)>{}]+\.woff2?/i', $html, $gm)) {
            $found['gstaticUrls'] = array_values(array_unique($gm[0]));
        }
        return $found;
    }

    public function scanForFonts($response)
    {


        if (is_string($response)) {
            $response = json_decode($response, true);
        }

        
        if (!is_array($response) || !isset($response['found']) || !is_array($response['found'])) {
            return array();
        }

        $found = array();

        
        if (!empty($response['found']['googleFontsStylesheets'])) {
            $found['googleFontsStylesheets'] = array_values($response['found']['googleFontsStylesheets']);
        } else {
            $found['googleFontsStylesheets'] = array();
        }


        if (!empty($response['found']['gstaticUrls'])) {
            $found['gstaticUrls'] = array_values($response['found']['gstaticUrls']);
        } else {
            $found['gstaticUrls'] = array();
        }

        $this->foundFonts = $found;
        return $found;
    }

    public function readGoogleStylesheet($array)
    {
        if (!empty($array['googleFontsStylesheets'])) {
            foreach ($array['googleFontsStylesheets'] as $font) {
                
                $this->stylesheet = null;
                $download = $this->read($font);

                
                
                if (!empty($download['error']) || !is_string($this->stylesheet) || $this->stylesheet === '') {
                    continue;
                }

                
                
                $stylesheetDir = md5($font);
                $wpc_repl151 = [];
                $wpc_ok151 = true;
                if (!empty($download['all'])) {
                    foreach ($download['all'] as $wpc_fu151) {
                        $downloadFont = $this->download($wpc_fu151, $stylesheetDir);
                        if (!is_array($downloadFont) || !empty($downloadFont['error'])
                            || empty($downloadFont['url']) || empty($downloadFont['filename'])) {
                            $wpc_ok151 = false;
                            break;
                        }
                        $wpc_repl151[] = [$downloadFont['url'], WPS_IC_FONTS_URL . $stylesheetDir . '/' . $downloadFont['filename']];
                    }
                }
                if (!$wpc_ok151) {
                    continue;
                }

                $stylesheet = $this->saveStylesheet($font, $this->stylesheet, $stylesheetDir);
                if (!is_array($stylesheet) || empty($stylesheet['stylesheetPath'])) {
                    continue;
                }
                foreach ($wpc_repl151 as $wpc_r151) {
                    $this->replaceInStylesheet($stylesheet['stylesheetPath'], $wpc_r151[0], $wpc_r151[1]);
                }

            }

            return 'downloaded';
        }

        return 'error';
    }

    public function read($stylesheet)
    {
        
        $css_url = str_replace('&#038;', '&', $stylesheet);

        
        if (str_starts_with($css_url, '//')) {
            $css_url = 'https:' . $css_url;
        }

        $response = wp_remote_get($css_url, ['timeout' => 30, 'redirection' => 5, 'headers' => [
            'User-Agent' => WPS_IC_API_USERAGENT, 'Accept' => 'text/css,*/*;q=0.1',],]);

        if (is_wp_error($response)) {
            return ['all' => [], 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [], 'error' => $response->get_error_message(),];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['all' => [], 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [], 'error' => 'HTTP ' . $code,];
        }

        $css = wp_remote_retrieve_body($response);
        if (!is_string($css) || $css === '') {
            return ['all' => [], 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [], 'error' => 'Empty CSS body',];
        }

        $this->stylesheet = $css;

        
        
        preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $matches);

        $urls = [];
        if (!empty($matches[2])) {
            foreach ($matches[2] as $u) {
                $u = trim($u);

                
                if (str_starts_with($u, 'data:')) {
                    continue;
                }

                
                if (str_starts_with($u, '//')) {
                    $u = 'https:' . $u;
                }

                
                $u = esc_url_raw($u);

                if ($u) {
                    $urls[] = $u;
                }
            }
        }

        
        $urls = array_values(array_unique($urls));

        
        $out = ['all' => $urls, 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [],];

        foreach ($urls as $u) {
            $path = wp_parse_url($u, PHP_URL_PATH);
            $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

            switch ($ext) {
                case 'woff2':
                    $out['woff2'][] = $u;
                    break;
                case 'woff':
                    $out['woff'][] = $u;
                    break;
                case 'ttf':
                    $out['ttf'][] = $u;
                    break;
                case 'otf':
                    $out['otf'][] = $u;
                    break;
                case 'eot':
                    $out['eot'][] = $u;
                    break;
                case 'svg':
                    $out['svg'][] = $u;
                    break;
                default:
                    $out['unknown'][] = $u;
                    break;
            }
        }

        return $out;
    }

    public function saveStylesheet($styleSheetURL, $stylesheetCSS, $dir)
    {
        $stylesheetPath = WPS_IC_FONTS_DIR . $dir . '/';

        
        $stylesheetFilename = md5(basename($styleSheetURL)) . '.css';

        
        $this->mapStylesheets($styleSheetURL, $dir, $stylesheetFilename);

        
        wp_mkdir_p($stylesheetPath);


        if (file_exists($stylesheetPath . $stylesheetFilename)) {
            unlink($stylesheetPath . $stylesheetFilename);
        }


        $fdOpts = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
        $fd = (is_array($fdOpts) && !empty($fdOpts['font-display'])) ? strtolower((string) $fdOpts['font-display']) : 'swap';
        
        
        if ($fd !== 'off' && function_exists('wpc_font_display_effective')) { $fd = wpc_font_display_effective($fd); }

        if ($fd !== 'off') {
            if (!in_array($fd, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                $fd = 'swap';
            }
            
            
            
            
            
            $fdRaw = $fd;
            $baked = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($fdRaw) {
                $fam = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $fm)
                    ? trim($fm[1], " \t\"'") : '';
                $fd = ($fam !== '' && function_exists('wpc_font_display_effective'))
                    ? wpc_font_display_effective($fdRaw, $fam) : $fdRaw;
                if (!in_array($fd, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                    $fd = 'swap';
                }
                if (preg_match('/font-display\s*:/i', $m[0])) {
                    return preg_replace('/font-display\s*:\s*[^;]+;?/i', 'font-display:' . $fd . ';', $m[0], 1);
                }
                return preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $fd . ';', $m[0], 1);
            }, $stylesheetCSS);
            if (is_string($baked)) {
                $stylesheetCSS = $baked;
            }
        }

        
        file_put_contents($stylesheetPath . $stylesheetFilename, $stylesheetCSS, LOCK_EX);


        if (defined('WPS_IC_FONTS_DIR') && function_exists('wpc_fonts_htaccess_ensure')) {
            wpc_fonts_htaccess_ensure(WPS_IC_FONTS_DIR);
        }

        return ['stylesheetPath' => $stylesheetPath . $stylesheetFilename];
    }


    public function rebakeFontDisplay()
    {
        $wpc_baked115 = 0;
        $fdOpts = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
        $fd = (is_array($fdOpts) && !empty($fdOpts['font-display'])) ? strtolower((string) $fdOpts['font-display']) : 'swap';
        if ($fd !== 'off' && function_exists('wpc_font_display_effective')) { $fd = wpc_font_display_effective($fd); }
        if ($fd === 'off') {
            return 0;
        }
        if (!in_array($fd, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
            $fd = 'swap';
        }

        $map = get_option(WPS_IC_FONTS_MAP);
        if (empty($map) || !is_array($map)) {
            $map = [];
        }

        foreach ($map as $rd) {
            if (empty($rd['dir']) || empty($rd['filename'])) {
                continue;
            }
            $path = WPS_IC_FONTS_DIR . $rd['dir'] . '/' . $rd['filename'];
            if (!file_exists($path) || !is_writable($path)) {
                continue;
            }
            $css = @file_get_contents($path);
            if (!is_string($css) || $css === '') {
                continue;
            }
            $baked = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($fd) {
                if (preg_match('/font-display\s*:/i', $m[0])) {
                    return preg_replace('/font-display\s*:\s*[^;]+;?/i', 'font-display:' . $fd . ';', $m[0], 1);
                }
                return preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $fd . ';', $m[0], 1);
            }, $css);
            if (is_string($baked) && $baked !== $css) {
                if (@file_put_contents($path, $baked) !== false) { $wpc_baked115++; }
            }
        }


        foreach ((array) @glob(rtrim(WPS_IC_FONTS_DIR, '/') . '/*/*.css') as $wpc_fcss109) {
            if (!@is_writable($wpc_fcss109)) {
                continue;
            }
            $wpc_c109 = @file_get_contents($wpc_fcss109);
            if (!is_string($wpc_c109) || $wpc_c109 === '' || stripos($wpc_c109, '@font-face') === false) {
                continue;
            }
            $wpc_b109 = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($fd) {
                if (preg_match('/font-display\s*:/i', $m[0])) {
                    return $m[0];
                }
                return preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $fd . ';', $m[0], 1);
            }, $wpc_c109);
            if (is_string($wpc_b109) && $wpc_b109 !== $wpc_c109) {
                if (@file_put_contents($wpc_fcss109, $wpc_b109) !== false) { $wpc_baked115++; }
            }
        }
        return $wpc_baked115;
    }

    public function mapStylesheets($url, $dir, $filename)
    {
        $this->stylesheetMap = get_option(WPS_IC_FONTS_MAP);
        $this->stylesheetMap[$url] = ['dir' => $dir, 'filename' => $filename];
        update_option(WPS_IC_FONTS_MAP, $this->stylesheetMap);
    }


    public function removeFont($fontId)
    {
        $this->stylesheetMap = get_option(WPS_IC_FONTS_MAP);
        unset($this->stylesheetMap[$fontId]);
        update_option(WPS_IC_FONTS_MAP, $this->stylesheetMap);
    }

    







    






    private static function wpc_atf_log566($why, $key = '')
    {
        if (function_exists('wpc_cache_first_log')) {
            try { wpc_cache_first_log('atf-scope-open', (string) $key, '', ['why' => (string) $why]); } catch (\Throwable $e) {}
        }
    }

    














    private static function wpc_faces_live589($wpc_faces589, $wpc_id589)
    {
        $wpc_faces589 = (string) $wpc_faces589;
        if (trim($wpc_faces589) === '') {
            return '';
        }
        if (!apply_filters('wpc_fonts_faces_always_live', true)) {
            return '<style id="' . $wpc_id589 . '" type="wpc-mobile-stylesheet">' . $wpc_faces589 . '</style>';
        }
        $wpc_sw589 = preg_replace_callback('/@font-face\s*\{[^}]*\}/i', function ($wpc_b589) {
            return stripos($wpc_b589[0], 'font-display') !== false
                ? $wpc_b589[0]
                : preg_replace('/\{/', '{font-display:swap;', $wpc_b589[0], 1);
        }, $wpc_faces589);
        if (is_string($wpc_sw589) && $wpc_sw589 !== '') {
            $wpc_faces589 = $wpc_sw589;
        }
        return '<style id="' . $wpc_id589 . '">' . $wpc_faces589 . '</style>';
    }

    private static function wpc_atf_scope_faces529($css, $html = '')
    {
        if (!apply_filters('wpc_atf_scope_faces', true)) {
            return null;
        }
        if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
            self::wpc_atf_log566('no-crit-const-or-urlkey');
            return null;
        }
        try {
            $wpc_k529 = (new wps_ic_url_key())->setup('');
            if ($wpc_k529 === '') {
                self::wpc_atf_log566('empty-urlkey');
                return null;
            }
            $wpc_dir529 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k529 . '/';
            
            
            
            
            
            
            
            
            if (!function_exists('wpc_atf_glyphs_read')) {
                self::wpc_atf_log566('no-reader');
                return null;
            }
            $wpc_ag529 = wpc_atf_glyphs_read($wpc_dir529);
            if (!is_array($wpc_ag529) || empty($wpc_ag529)) {
                self::wpc_atf_log566('no-atf-glyphs', $wpc_k529);
                return null;
            }
            $wpc_fam529 = [];
            foreach (array_keys($wpc_ag529) as $wpc_ky529) {
                $wpc_p529 = explode('|', (string) $wpc_ky529);
                $wpc_n529 = strtolower(trim($wpc_p529[0]));
                if ($wpc_n529 !== '') {
                    $wpc_fam529[$wpc_n529] = 1;
                }
            }
            if (empty($wpc_fam529)) {
                self::wpc_atf_log566('empty-family-list', $wpc_k529);
                return null;
            }
            
            $wpc_atf529 = '';
            $wpc_rest529 = '';
            $wpc_i529 = 0;
            $wpc_len529 = strlen($css);
            $wpc_any529 = false;
            while ($wpc_i529 < $wpc_len529) {
                $wpc_at529 = stripos($css, '@font-face', $wpc_i529);
                if ($wpc_at529 === false) {
                    $wpc_atf529 .= substr($css, $wpc_i529);
                    break;
                }
                $wpc_atf529 .= substr($css, $wpc_i529, $wpc_at529 - $wpc_i529);
                $wpc_ob529 = strpos($css, '{', $wpc_at529);
                if ($wpc_ob529 === false) {
                    $wpc_atf529 .= substr($css, $wpc_at529);
                    break;
                }
                $wpc_d529 = 1;
                $wpc_p2529 = $wpc_ob529 + 1;
                while ($wpc_p2529 < $wpc_len529 && $wpc_d529 > 0) {
                    if ($css[$wpc_p2529] === '{') { $wpc_d529++; }
                    elseif ($css[$wpc_p2529] === '}') { $wpc_d529--; }
                    $wpc_p2529++;
                }
                $wpc_blk529 = substr($css, $wpc_at529, $wpc_p2529 - $wpc_at529);
                $wpc_keep529 = false;
                if (preg_match('/font-family\s*:\s*([^;}]+)/i', $wpc_blk529, $wpc_fm529)) {
                    $wpc_nm529 = strtolower(trim($wpc_fm529[1], " \t\n\r\0\x0B\"'"));
                    $wpc_keep529 = isset($wpc_fam529[$wpc_nm529]);
                }
                if ($wpc_keep529) { $wpc_atf529 .= $wpc_blk529; $wpc_any529 = true; }
                else { $wpc_rest529 .= $wpc_blk529; }
                $wpc_i529 = $wpc_p2529;
            }
            
            if (trim($wpc_rest529) === '') {
                return null;
            }
            
            
            
            
            
            
            if (!$wpc_any529) {
                
                
                
                
                $wpc_hay560 = (is_string($html) ? $html : '');
                foreach (['font-subsets.css', 'critical_desktop.css', 'critical_mobile.css'] as $wpc_af560) {
                    if (@is_readable($wpc_dir529 . $wpc_af560)) {
                        $wpc_hay560 .= (string) @file_get_contents($wpc_dir529 . $wpc_af560);
                    }
                }
                if ($wpc_hay560 === '') {
                    self::wpc_atf_log566('no-coverage-source', $wpc_k529);
                    return null;
                }
                $wpc_seen560 = [];
                if (preg_match_all('/@font-face\s*\{[^}]*\}/i', $wpc_hay560, $wpc_ff560)) {
                    foreach ($wpc_ff560[0] as $wpc_b560) {
                        if (preg_match('/font-family\s*:\s*([^;}]+)/i', $wpc_b560, $wpc_m560)) {
                            $wpc_seen560[strtolower(trim($wpc_m560[1], " \t\n\r\0\x0B\"'"))] = 1;
                        }
                    }
                }
                foreach (array_keys($wpc_fam529) as $wpc_need560) {
                    if (!isset($wpc_seen560[$wpc_need560])) {
                        self::wpc_atf_log566('family-uncovered:' . $wpc_need560, $wpc_k529);
                        return null;
                    }
                }
                return ['atf' => '', 'rest' => $wpc_atf529 . $wpc_rest529];
            }
            return ['atf' => $wpc_atf529, 'rest' => $wpc_rest529];
        } catch (\Throwable $e) {
            self::wpc_atf_log566('threw:' . substr($e->getMessage(), 0, 60));
            return null;
        }
    }

    public function download($url, $dir = '')
    {
        $this->filename = basename($url);

        if (empty($dir)) {
            $dir = $this->path;
            $uri = $this->uriPath;
        } else {
            $dir = WPS_IC_FONTS_DIR . $dir . '/';
            $uri = WPS_IC_FONTS_URL . $dir . '/';
        }

        wp_mkdir_p($dir);

        
        $request_url = $url;

        
        if (str_starts_with($request_url, '//')) {
            $request_url = 'https:' . $request_url;
        }


        $request_url = str_replace('&#038;', '&', $request_url);

        $temp_filename = $dir . $this->filename . '.' . uniqid('', true) . '.tmp';

        
        
        
        
        $args = ['timeout' => (int) apply_filters('wpc_font_download_timeout', 20), 'redirection' => 5, 'stream' => true, 'filename' => $temp_filename, 'headers' => [
            'User-Agent' => 'Mozilla/5.0 (compatible; WordPress; +https://wordpress.org/)', 'Accept' => 'text/css,*/*;q=0.1',],];

        $response = wp_remote_get($request_url, $args);

        if (is_wp_error($response)) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'WP Error: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }

            
            $server_msg = wp_remote_retrieve_header($response, 'status') ?: '';
            return ['error' => true, 'msg' => 'Response code: ' . $code . ' ' . $server_msg];
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'Missing content-type header'];
        }

        $content_type = strtolower(trim(explode(';', $content_type)[0]));
        $extension = $this->mimeMap[$content_type] ?? '';

        if (!$extension) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'Extension not accepted for content-type: ' . $content_type];
        }

        $final_path = $dir . '/' . $this->filename;

        if (!rename($temp_filename, $final_path)) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'Unable to rename file'];
        }


        return ['url' => $request_url, 'path' => $final_path, 'uriPath' => $uri, 'filename' => $this->filename];
    }

    public function replaceInStylesheet($stylesheetPath, $findUrl, $replaceUrl)
    {
        $contents = file_get_contents($stylesheetPath);
        $contents = str_replace($findUrl, $replaceUrl, $contents);
        file_put_contents($stylesheetPath, $contents, LOCK_EX);
    }

    public function downloadFound($array)
    {
        if (!empty($array['googleFontsStylesheets'])) {
            foreach ($array['googleFontsStylesheets'] as $font) {
                
                $download = $this->download($font);
                if (!empty($download) && empty($download['error'])) {
                    echo 'Download successful!' . "\r\n";
                } else {
                    echo 'Download failed! ' . $download['msg'] . "\r\n";
                }
            }
        }
    }


}