<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/combine_css.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */


include_once WPS_IC_DIR . 'addons/cdn/cdn-rewrite.php';
include_once WPS_IC_DIR . 'traits/url_key.php';

class wps_ic_combine_css
{

    public static $excludes;
    public static $rewrite;
    public static $isMobile;
    public static $site_url;
    public $zone_name;
    public $cssPath;
    public $filesize_cap;
    public $combine_external;
    public $hmwpReplace;
    public $patterns;
    public $allExcludes;
    public $combine_inline_scripts;
    public $settings;
    public $firstFoundStyle;
    public $combined_url_base;
    public $combined_dir;
    public $urlKey;
    public $url_key_class;
    public $log_criticalCombine;
    public $logger;
    public $criticalCombine;
    public $hmwp_rewrite;
    public $no_content_excludes;
    public $current_file;
    public $file_count;
    public $current_section;
    public $asset_url;
    public $enabledCDN;

    public function __construct()
    {
        $this->cssPath = '';
        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();
        $this->combined_dir = WPS_IC_COMBINE . $this->urlKey . '/css/';
        $this->combined_url_base = WPS_IC_COMBINE_URL . $this->urlKey . '/css/';
        $this->enabledCDN = false;

        $this::$isMobile = $this->isMobile();

        $this->firstFoundStyle = false;

        self::$excludes = new wps_ic_excludes();
        self::$rewrite = new wps_cdn_rewrite();
        self::$site_url = site_url();
        $this->settings = get_option(WPS_IC_SETTINGS);
        $this->filesize_cap = '100000000000';
        $this->combine_inline_scripts = true;
        $this->combine_external = false;
        $this->allExcludes = self::$excludes->combineCSSExcludes();


        if (!empty($this->settings['serve']['jpg']) || !empty($this->settings['serve']['png']) || !empty($this->settings['serve']['gif']) || !empty($this->settings['serve']['svg'])) {
            $this->enabledCDN = true;
            $cf = get_option(WPS_IC_CF);
            if (!empty($cf['settings']['cdn']) && $cf['settings']['cdn'] == '0') {
                $this->enabledCDN = false;
            }
        }

        if (!empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))) {
            $this->settings['inline-css'] = '0';
            $this->criticalCombine = true;
            $this->filesize_cap = '10000000000';
            $this->combine_inline_scripts = true;
            $this->combine_external = true;
            $this->allExcludes = array_merge(['media="print"', 'media=\'print\''], $this->allExcludes);
        }

        $this->patterns = '/(<link[^>]*rel=["\']stylesheet["\'][^>]*>)|((?<!<noscript>)<style\b[^>]*>(.*?)<\/style>)|(<link\b[^>]*?onload=["\']this.rel=["\']stylesheet["\']["\'][^>]*>)/si';


        $cf = get_option(WPS_IC_CF);
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok())) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname) && function_exists('wpc_cdn_cname_reachable117') && !wpc_cdn_cname_reachable117($custom_cname)) { $custom_cname = ''; }
        if (empty($custom_cname) || !$custom_cname) {
            $this->zone_name = get_option('ic_cdn_zone_name');
        } else {
            $this->zone_name = $custom_cname;
        }

        
        $this->hmwpReplace = false;
        if (class_exists('HMWP_Classes_ObjController')) {
            $this->hmwpReplace = true;
            $plugin_path = WP_PLUGIN_DIR . '/hide-my-wp/';
            include_once($plugin_path . 'classes/ObjController.php');
            $hmwp_controller = new HMWP_Classes_ObjController();
            $this->hmwp_rewrite = $hmwp_controller::getClass('HMWP_Models_Rewrite');
        }
    }


    public function isMobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        $userAgent = '';
        if (!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false) {
            $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
        }

        
        $desktopKeywords = ['windows nt', 'macintosh', 'linux', 'cros', 'x11'];

        foreach ($desktopKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return false; 
            }
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            
            $mobileKeywords = ['android', 'iphone', 'ipad', 'ipod', 'windows phone', 'blackberry', 'bb10', 'webos', 'symbian', 'playbook', 'kindle', 'silk', 'opera mini', 'opera mobi', 'palm'];

            
            foreach ($mobileKeywords as $keyword) {
                if (strpos($userAgent, $keyword) !== false) {
                    return true; 
                }
            }
        }

        return false;
    }


    public function pathWalker($path, $find)
    {
        $paths = explode('/', $path);
        $foldersUp = substr_count($find, '../');

        $array = array_splice($paths, 0, -$foldersUp);
        $array = implode('/', $array);

        return $array;
    }


    public function preloadLCP($html)
    {
        $preloadLCP = [];

        
        if (preg_match('/<picture[^>]*class="[^"]*wpc-picture[^"]*"[^>]*>(.*?)<\/picture>/is', $html, $picMatch)) {
            $pictureInner = $picMatch[1];

            
            $sourceData = null;
            if (preg_match('/<source\s+[^>]*type=["\']image\/(avif|webp)["\'][^>]*>/i', $pictureInner, $sourceMatch)) {
                $sourceTag = $sourceMatch[0];
                $sourceType = 'image/' . strtolower($sourceMatch[1]);
                $srcsetM = null; $sizesM = null;
                if (preg_match('/srcset=["\']([^"\']+)["\']/i', $sourceTag, $ss)) $srcsetM = $ss[1];
                if (preg_match('/sizes=["\']([^"\']+)["\']/i', $sourceTag, $sz)) $sizesM = $sz[1];
                if ($srcsetM !== null) {
                    $sourceData = [
                        'type'     => $sourceType,
                        'srcset'   => $srcsetM,
                        'sizes'    => $sizesM !== null ? $sizesM : '100vw',
                    ];
                }
            }

            
            $innerImgSrc = '';
            $innerImgIsLcp = false;
            if (preg_match('/<img\s+[^>]*>/i', $pictureInner, $imgMatch)) {
                $innerTag = $imgMatch[0];
                $innerImgIsLcp = (bool) preg_match('/fetchpriority\s*=\s*["\']high["\']/i', $innerTag);
                if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $innerTag, $sm)) {
                    $innerImgSrc = $sm[1];
                }
            }

            
            if ($sourceData !== null) {
                $tag = '<link rel="preload" as="image"'
                     . ' imagesrcset="' . esc_attr($sourceData['srcset']) . '"'
                     . ' imagesizes="' . esc_attr($sourceData['sizes']) . '"'
                     . ' type="' . esc_attr($sourceData['type']) . '"'
                     . ' fetchpriority="high">';
                $preloadLCP[] = $tag;
            } elseif ($innerImgSrc !== '') {
                
                $preloadLCP[] = '<link rel="preload" as="image" href="' . esc_url($innerImgSrc) . '" fetchpriority="high">';
            }
        }

        
        if (empty($preloadLCP)) {
            if (preg_match('/<img\s+[^>]*fetchpriority\s*=\s*["\']high["\'][^>]*>/is', $html, $imgMatch)) {
                $imgTag = $imgMatch[0];
                $imgSrcsetM = null; $imgSizesM = null; $imgSrcM = null;
                if (preg_match('/srcset=["\']([^"\']+)["\']/i', $imgTag, $ss)) $imgSrcsetM = $ss[1];
                if (preg_match('/sizes=["\']([^"\']+)["\']/i', $imgTag, $sz)) $imgSizesM = $sz[1];
                if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $imgTag, $sm)) $imgSrcM = $sm[1];
                if ($imgSrcsetM !== null) {
                    $preloadLCP[] = '<link rel="preload" as="image"'
                        . ' imagesrcset="' . esc_attr($imgSrcsetM) . '"'
                        . ' imagesizes="' . esc_attr($imgSizesM !== null ? $imgSizesM : '100vw') . '"'
                        . ' fetchpriority="high">';
                } elseif ($imgSrcM !== null) {
                    $preloadLCP[] = '<link rel="preload" as="image" href="' . esc_url($imgSrcM) . '" fetchpriority="high">';
                }
            }
        }

        
        return (array) apply_filters('wpc_preload_lcp_links', $preloadLCP, $html);
    }


    public function preparePreloads($html)
    {
        preg_match_all('/<link\s+[^>]*\bhref=(["\'])(.*?)\1[^>]*>/is', $html, $matches);

        $AlreadyLoadedLocaLFonts = [];
        $wpcPreloads = '';
        $wpcPreloadsGenerator = '';

        if (!empty($matches[2])) {
            foreach ($matches[2] as $k => $href) {

                if (strpos($href, '.css') === false && strpos($href, 'fonts.google') === false) {
                    continue;
                }

                
                $cleanHref = explode('?', trim($href));
                $cleanHref = trim($cleanHref[0]);

                if (strpos($cleanHref, self::$site_url) !== false) {
                    
                    
                    
                    continue;
                }
                if (false) {
                    $path = str_replace([self::$site_url, $this->zone_name, 'https:///m:0/a:', 'https://' . $this->zone_name . '/m:0/a:','https:///m:1/a:', 'https://' . $this->zone_name . '/m:1/a:'], '', $cleanHref);
                    $path = urldecode(ltrim($path, '/'));

                    
                    if (preg_match('#^https?://#i', $path)) {
                        continue;
                    }

                    $relativePath = ABSPATH . $path;

                    if (!file_exists($relativePath)) {
                        continue;
                    }

                    $content = @file_get_contents($relativePath);

                    if (!empty($content)) {
                        
                        $cssFilename = basename($href);
                        $cssUrlPath = str_replace($cssFilename, '', $href);

                        
                        $cssPath = str_replace([self::$site_url . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/', 'https://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
                        $cssPath = rtrim($cssPath, '/');
                        $this->cssPath = self::$site_url . '/' . $cssPath;

                        
                        $css = $this->fixUrlPaths($content);
                        
                        if (!empty($foundFonts)) {
                            $AlreadyLoaded = [];
                            foreach ($foundFonts as $i => $font) {
                                if (!in_array($font, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $font;
                                }
                            }
                        }

                        
                        $foundBackgrounds = $this->findBackgrounds($css);
                        if (!empty($foundBackgrounds)) {
                            $AlreadyLoaded = [];
                            foreach ($foundBackgrounds as $i => $bg) {
                                if (!in_array($bg, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $bg;
                                    
                                }
                            }
                        }

                    }
                } elseif (strpos($href, 'fonts.google')) {
                    
                    
                } elseif (strpos($href, 'fontawesome.com')) {
                    if (!in_array($href, $AlreadyLoadedLocaLFonts)) {
                        $AlreadyLoadedLocaLFonts[] = $href;
                        $preload = "<link rel='preload' href='" . $href . "' as='style' />";
                        $wpcPreloads .= $preload;
                    }
                }
            }
        }
        if ($this->is_home_url()) {
            if (!self::$rewrite->is_mobile()) {
                $wpcPreloadsGenerator = self::$rewrite->preload_custom_assets('string', $html);
            } else {
                $wpcPreloadsGenerator = self::$rewrite->preload_custom_assetsMobile('string', $html);
            }
        }

        return $wpcPreloadsGenerator . $wpcPreloads;
    }


    public function fixUrlPaths($css)
    {
        $css = preg_replace_callback('/url\(([^)]*)\)/i', [$this, 'fixPathsWalker'], $css);

        
        $css = preg_replace_callback('/@import\s+["\']([^"\']+)["\'];?/i', [$this, 'fixImportPaths'], $css);

        return $css;
    }

    public function findBackgrounds($css)
    {
        $pattern = '/(?:background(?:-image)?\s*:\s*url\s*\(\s*([\'"]?)([^)\'"]*)\1\s*\))/i';

        
        preg_match_all($pattern, $css, $matches);

        
        $fontUrls = $matches[2];

        
        $filteredUrls = array_filter($fontUrls, function ($url) {
            return preg_match('/\.(svg|jpeg|jpg|gif|png)\b/', $url);
        });

        
        $filteredUrls = array_filter(array_map(function ($url) {
            return trim($url, '"\'');
        }, $filteredUrls));


        if (!empty($filteredUrls)) {
            return $filteredUrls;
        }

        return false;
    }

    public function is_home_url()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = wpc_request_scheme() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');
        $current_url = explode('?', $current_url);
        $current_url = $current_url[0];
        $home_url = rtrim($home_url, '/');
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    public function fixImportPaths($matches)
    {
        if (!empty($matches)) {
            $foundUrls = trim($matches[1]);

            if (strpos($foundUrls, 'data:') !== false) {
                return trim($matches[0]);
            } else {
                $cssPath = $this->cssPath;

                $foundUrls = str_replace(['("', "('", '")', "')"], '', $foundUrls);
                $foundUrls = trim($foundUrls, '()');

                if (strpos($foundUrls, '//') === 0 || strpos($foundUrls, 'http') === 0) {
                    return '@import "' . $foundUrls . '";';
                } else {
                    if (strpos($foundUrls, '../') !== false) {
                        $count = substr_count($foundUrls, '../');
                        $newUrl = $this->moveUpDirectories($this->cssPath, $count);
                        $path = str_replace('../', '', $foundUrls);
                        return '@import "' . $newUrl . $path . '";';
                    } elseif (strpos($foundUrls, './') !== false) {
                        $removeRelative = str_replace('./', '', $foundUrls);
                        return '@import "' . $cssPath . '/' . $removeRelative . '";';
                    } elseif (strpos($foundUrls, '/wp-content') !== false && strpos($foundUrls, '/wp-content') == 0) {
                        return '@import "' . self::$site_url . $foundUrls . '";';
                    } elseif (strpos($foundUrls, '/') === 0) {
                        return '@import "' . $cssPath . $foundUrls . '";';
                    } else {
                        return '@import "' . $cssPath . '/' . $foundUrls . '";';
                    }
                }
            }
        }

        return $matches[0];
    }

    public function moveUpDirectories($url, $upCount = 1)
    {
        
        if (!is_string($url) || $upCount < 0) {
            return false;
        }

        
        $url = rtrim($url, '/');

        
        $urlParts = parse_url($url);

        
        if (!isset($urlParts['path'])) {
            return $url;
        }

        
        $path = explode('/', trim($urlParts['path'], '/'));

        
        $path = array_slice($path, 0, -$upCount);

        
        $urlParts['path'] = '/' . implode('/', $path);

        
        $resultUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . (isset($urlParts['port']) ? ':' . $urlParts['port'] : '') . $urlParts['path'];

        return $resultUrl . '/';
    }

    public function isHtml($string)
    {
        return preg_match("/<[^<]+>/", $string) === 1;
    }

    
    
    
    
    
    
    public function wpc_looks_like_html_doc657($s)
    {
        if (!is_string($s) || $s === '') {
            return false;
        }
        
        
        
        
        
        $head = strtolower(ltrim($s));
        return strncmp($head, '<!doctype html', 14) === 0
            || strncmp($head, '<html', 5) === 0
            || strncmp($head, '<head>', 6) === 0
            || strncmp($head, '<head ', 6) === 0
            || strncmp($head, '<body', 5) === 0;
    }

    public function doInline($html)
    {
        $wpcPreloads = [];
        preg_match_all('/<link\s+[^>]*\bhref=(["\'])(.*?)\1[^>]*>/is', $html, $matches);

        $excludes_class = new wps_ic_excludes();

        if (!empty($matches[2])) {
            foreach ($matches[2] as $k => $href) {

                if ($excludes_class->strInArray($matches[0][$k], $excludes_class->inlineCSSExcludes())) {
                    continue;
                }

                if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::wpc_consent_family($matches[0][$k])) {
                    continue;
                }

                if (strpos($href, '.css') === false && strpos($href, 'fonts.google') === false) {
                    continue;
                }

                
                $cleanHref = explode('?', trim($href));
                $cleanHref = trim($cleanHref[0]);

                if (strpos($cleanHref, self::$site_url) !== false) {
                    $path = str_replace(self::$site_url, '', $cleanHref);
                    $path = urldecode(ltrim($path, '/'));
                    $relativePath = ABSPATH . '/' . $path;

                    if (!file_exists($relativePath)) {
                        continue;
                    }

                    $content = @file_get_contents($relativePath);

                    if (!empty($content)) {
                        
                        
                        $cssFilename = basename($href);
                        $cssUrlPath = str_replace($cssFilename, '', $href);

                        
                        $cssPath = str_replace([self::$site_url . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/', 'https://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
                        $cssPath = rtrim($cssPath, '/');
                        $this->cssPath = self::$site_url . '/' . $cssPath;

                        $content = $this->fixControlCharacter($content);
                        $content = $this->removeCommentsFromCSS($content);
                        $content = $this->removeCharsetFromCSS($content);
                        $content = $this->fixAnimations($content);
                        $content = $this->fixUrlPaths($content);

                        
                        $content = $this->findFontFace($content);

                        
                        $foundFonts = $this->findFonts($content);
                        if (!empty($foundFonts)) {
                            $AlreadyLoaded = [];
                            foreach ($foundFonts as $i => $font) {
                                if (!in_array($font, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $font;

                                    
                                    

                                    if (strpos($font, 'icon') !== false) continue;
                                    $figurePreloadType = $this->figurePreloadType($font);
                                    $wpcPreloads[] = "<link rel='wpc-lazy-font' href='" . $font . "' as='" . $figurePreloadType['as'] . "' type='" . $figurePreloadType['type'] . "' " . $figurePreloadType['extra'] . ">";
                                }
                            }
                        }

                        
                        
                        if (!empty($foundBackgrounds)) {
                            $AlreadyLoaded = [];
                            foreach ($foundBackgrounds as $i => $bg) {

                                if (strpos(strtolower($bg), 'array') !== false) {
                                    continue;
                                }

                                if (!in_array($bg, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $bg;
                                    $figurePreloadType = $this->figurePreloadType($bg);
                                    $wpcPreloads[] = "<link rel='preload' href='" . $bg . "' as='" . $figurePreloadType['as'] . "' type='" . $figurePreloadType['type'] . "'>";
                                }
                            }
                        }

                        $content = $this->minifyCSS($content);
                        $inlinedStyle = '<style type="text/css" id="doInline-' . mt_rand(999, 9999) . '">' . $content . '</style>';
                        $html = str_replace($matches[0][$k], $inlinedStyle, $html);
                    }
                } elseif (strpos($href, 'fonts.google')) {

                    $preload = "<link rel='preload' href='" . $href . "' as='style' />";
                    $html = str_replace($matches[0][$k], $preload . $matches[0][$k], $html);
                } elseif (strpos($href, 'fontawesome.com')) {

                    $preload = "<link rel='preload' href='" . $href . "' as='style' />";
                    $html = str_replace($matches[0][$k], $preload . $matches[0][$k], $html);
                }
            }
        }

        $preloadFonts = implode('', $wpcPreloads);

        $html = str_replace('<!--WPC_INSERT_PRELOAD-->', $preloadFonts, $html);

        return $html;
    }

    public function fixControlCharacter($css)
    {
        $css = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $css);
        return $css;
    }

    public function removeCommentsFromCSS($css)
    {
        
        $cssWithoutComments = preg_replace('#/\*.*?\*/#s', '', $css);
        
        return $cssWithoutComments;
    }

    public function removeCharsetFromCSS($css)
    {
        
        $cssWithoutCharset = preg_replace('/@charset[^;]+;/', '', $css);
        return $cssWithoutCharset;
    }

    public function fixAnimations($css)
    {
        $replacement = 'will-change: transform, opacity;$0';
        $modifiedCss = preg_replace('/\banimation:\s*[^;]+;/i', $replacement, $css);
        $modifiedCss = preg_replace('/\btransition:\s*[^;]+;/i', $replacement, $modifiedCss);
        return $modifiedCss;
    }


    public function rewriteInlineFontFaces($html)
    {
        if (strpos($html, '@font-face') === false) return $html;


        $wpc_inline_cdn = (class_exists('wps_cdn_rewrite')
            && apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))
            && !empty($this->settings['fonts']) && $this->settings['fonts'] == '1'
            && !empty($this->zone_name)
            && (empty($this->settings['css_combine']) || $this->settings['css_combine'] != '1')
            && !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()));
        $wpc_zone = $wpc_inline_cdn ? (string) $this->zone_name : '';
        $wpc_subsetting = ($wpc_inline_cdn && !empty($this->settings['font-subsetting']) && $this->settings['font-subsetting'] == '1');
        $wpc_site_host = $wpc_inline_cdn ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
        return preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) use ($wpc_inline_cdn, $wpc_zone, $wpc_subsetting, $wpc_site_host) {
            if (strpos($m[2], '@font-face') === false) return $m[0];
            $rewritten = $this->findFontFace($m[2]);
            if ($wpc_inline_cdn) {
                $rewritten = wps_cdn_rewrite::rewrite_fontface_css($rewritten, $wpc_zone, $wpc_subsetting, $wpc_site_host);
            }
            return $m[1] . $rewritten . $m[3];
        }, $html);
    }


    public function extractFontPreloadLinks($html, $cap = 4)
    {
        $settings = get_option(WPS_IC_SETTINGS);
        $wpc_pcf54 = isset($settings['preload-crit-fonts']) ? (string) $settings['preload-crit-fonts'] : '';
        if ($wpc_pcf54 === '' && isset($settings['replace-fonts']) && $settings['replace-fonts'] === 'local'
            && apply_filters('wpc_atf_faces_auto', true)) {
            $wpc_pcf54 = '1';
        }
        if ($wpc_pcf54 !== '1') {
            return [];
        }
        if (strpos($html, '@font-face') === false) return [];

        $found = [];
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styleMatches)) {
            foreach ($styleMatches[1] as $css) {
                if (strpos($css, '@font-face') === false) continue;
                if (preg_match_all('/@font-face\s*\{([^}]+)\}/is', $css, $faceMatches)) {
                    foreach ($faceMatches[1] as $faceBody) {
                        
                        if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $faceBody, $famM)) {
                            $fam = strtolower(trim($famM[1]));
                            if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $fam)) {
                                continue;
                            }
                        }
                        if (preg_match('/url\(["\']?([^)"\']+\.woff2)["\']?\)/i', $faceBody, $urlM)) {
                            $url = $urlM[1];
                            if (!in_array($url, $found, true)) {
                                $found[] = $url;
                                if (count($found) >= $cap) break 2;
                            }
                        }
                    }
                }
            }
        }
        
        $links = [];
        if ($found && function_exists('wpc_font_preload_postpaint_tag')) {
            $wpc_fpb689 = wpc_font_preload_postpaint_tag(array_map('esc_url', $found));
            if ($wpc_fpb689 !== '') {
                $links[] = $wpc_fpb689;
            }
        }
        return (array) apply_filters('wpc_font_preload_links', $links, $html);
    }

    public function findFontFace($css)
    {
        
        $settings = get_option(WPS_IC_SETTINGS);
        $textFontDisplay = !empty($settings['font-display']) ? $settings['font-display'] : 'smart';
        
        
        
        $textFontDisplayRaw = $textFontDisplay;
        if (function_exists('wpc_font_display_effective')) {
            $textFontDisplay = wpc_font_display_effective($textFontDisplay);
        }
        $iconFontDisplay = !empty($settings['icon-font-display']) ? $settings['icon-font-display'] : 'block';

        return preg_replace_callback('/@font-face\s*{[^}]+}/sim', function ($fontface) use ($textFontDisplay, $textFontDisplayRaw, $iconFontDisplay) {
            
            
            
            
            if ($textFontDisplayRaw === 'off' || $textFontDisplay === 'off') { return $fontface[0]; }
            $fontFamily = $fontStyle = $fontWeight = $woffUrl = '';
            $urlFound = false;

            
            if (preg_match('/url\((["\']?)([^)]+\.(woff2?))\1\)/si', $fontface[0], $matchesWoffUrl)) {
                $woffUrl = $matchesWoffUrl[2];
                $urlFound = true;
            }

            
            if (preg_match('/font-family\s*:\s*([^;]+);/si', $fontface[0], $matchesFontFamily)) {
                $fontFamily = "font-family: " . $matchesFontFamily[1] . ";";
            }
            if (preg_match('/font-style\s*:\s*([^;]+);/si', $fontface[0], $matchesStyle)) {
                $fontStyle = 'font-style: ' . $matchesStyle[1] . ';';
            }
            if (preg_match('/font-weight\s*:\s*([^;]+);/si', $fontface[0], $matchesWeight)) {
                $fontWeight = 'font-weight: ' . $matchesWeight[1] . ';';
            }

            if ($urlFound) {
                $format = strpos($woffUrl, '.woff2') !== false ? 'woff2' : 'woff';

                
                $fontDisplayValue = $textFontDisplay;
                $familyRaw = isset($matchesFontFamily[1]) ? strtolower(trim($matchesFontFamily[1])) : '';
                if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $familyRaw)) {
                    $fontDisplayValue = $iconFontDisplay;
                } elseif ($familyRaw !== '' && function_exists('wpc_font_display_effective')) {
                    
                    
                    
                    $wpc_fd485 = wpc_font_display_effective($textFontDisplayRaw, trim($familyRaw, " \t\"'"));
                    if (in_array($wpc_fd485, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                        $fontDisplayValue = $wpc_fd485;
                    }
                }

                return "@font-face{{$fontFamily}{$fontStyle}{$fontWeight}font-display:{$fontDisplayValue};src:url(\"$woffUrl\") format(\"$format\");}";
            } else {
                return $fontface[0];
            }
        }, $css);
    }

    public function findFonts($css)
    {
        
        $pattern = '/url\(([^)]+)\)/si';

        
        preg_match_all($pattern, $css, $matches);

        
        $fontUrls = $matches[1];

        
        $filteredUrls = array_filter($fontUrls, function ($url) {
            return preg_match('/\.(woff2)\b/', $url);
        });

        
        $filteredUrls = array_filter(array_map(function ($url) {
            return trim($url, '"\'');
        }, $filteredUrls));


        if (!empty($filteredUrls)) {
            return $filteredUrls;
        }

        return false;
    }

    public function figurePreloadType($preloadUrl)
    {
        $type = '';
        $extra = '';
        $ext = pathinfo($preloadUrl, PATHINFO_EXTENSION);
        switch ($ext) {
            case 'css':
                $as = 'style';
                $type = 'text/css';
                break;
            case 'js':
                $as = 'script';
                $type = 'text/javascript';
                break;
            case 'woff':
            case 'woff2':
            case 'ttf':
            case 'otf':
                $extra = 'crossorigin';
                $as = 'font';
                if ($ext == 'woff') {
                    $type = 'font/woff';
                } else if ($ext == 'woff2') {
                    $type = 'font/woff2';
                } else {
                    $type = 'font/' . $ext;
                }
                break;
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
            case 'svg':
                $as = 'image';
                if ($ext == 'jpg' || $ext == 'jpeg') {
                    $type = 'image/jpg';
                } else if ($ext == 'gif') {
                    $type = 'image/gif';
                } else if ($ext == 'png') {
                    $type = 'image/png';
                } else if ($ext == 'webp') {
                    $type = 'image/webp';
                } else if ($ext == 'svg') {
                    $type = 'image/svg+xml';
                } else if ($ext == 'avif') {
                    $type = 'image/avif';
                }
                break;
            default:
                $as = '';
                break;
        }

        return ['as' => $as, 'type' => $type, 'extra' => $extra];
    }

    public function minifyCSS($css)
    {
        
        $css = str_replace(': ', ':', $css);

        
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);

        $css = preg_replace('/\/\*(.*?)\*\//s', '', $css); 
        $css = preg_replace('/\s+/', ' ', $css); 
        $css = preg_replace('/\s?([,:;{}])\s?/', '$1', $css); 
        $css = preg_replace('/;}/', '}', $css); 

        return $css;
    }

    public function lazyFontawesome($html)
    {
        preg_match_all('/<link\b[^>]*>/is', $html, $matches);

        if (!empty($matches[0])) {
            $seen = [];
            foreach ($matches[0] as $tag) {
                preg_match('/\brel=(["\'])(.*?)\1/i', $tag, $relMatch);
                preg_match('/\bhref=(["\'])(.*?)\1/i', $tag, $hrefMatch);

                $rel  = $relMatch[2] ?? '';
                $href = $hrefMatch[2] ?? '';

                if (
                    strtolower($rel) === 'stylesheet' &&
                    (
                        strpos($href, 'fontawesome.com') !== false ||
                        strpos($href, 'font-awesome') !== false
                    )
                ) {
                    
                    if (isset($seen[$href])) {
                        $replacement = '';
                    } else {
                        $seen[$href] = true;
                        $replacement = "<link rel='preload' href='" . $href . "' as='style' media='all' onload=\"this.onload=null;this.rel='stylesheet'\" />";
                    }
                    $new = preg_replace('/' . preg_quote($tag, '/') . '/', $replacement, $html, 1);
                    if (is_string($new)) {
                        $html = $new;
                    }
                }
            }
        }

        return $html;
    }

    public function fixPathsWalker($matches)
    {

        if (!empty($matches)) {
            $foundUrls = trim($matches[1]);

            if (strpos($foundUrls, 'data:') !== false) {
                return trim($matches[0]);
            } else {

                $cssPath = $this->cssPath;

                $foundUrls = str_replace('("', '', $foundUrls);
                $foundUrls = str_replace("('", '', $foundUrls);
                $foundUrls = str_replace('")', '', $foundUrls);
                $foundUrls = str_replace("')", '', $foundUrls);

                
                $foundUrls = rtrim($foundUrls, ')');
                $foundUrls = ltrim($foundUrls, '(');
                $foundUrls = trim($foundUrls);

                
                if (strpos($foundUrls, '//') === 0 || strpos($foundUrls, 'http') === 0) {
                    
                    return 'url("' . $foundUrls . '")';
                } else {

                    
                    $foundUrls = rtrim($foundUrls, ')');
                    $foundUrls = ltrim($foundUrls, '(');


                    if (strpos($foundUrls, '../') !== false) {
                        $count = substr_count($foundUrls, '../');

                        

                        $newUrl = $this->moveUpDirectories($this->cssPath, $count);
                        $path = str_replace('../', '', $foundUrls);

                        
                        
                        return 'url("' . $newUrl . $path . '")';
                        
                    } elseif (strpos($foundUrls, './') !== false) {

                        
                        $foundUrls = ltrim($foundUrls, '(');
                        $foundUrls = rtrim($foundUrls, ')');

                        
                        $removeRelative = str_replace('./', '', $foundUrls);

                        
                        return 'url("' . $this->cssPath . '/' . $removeRelative . '")';
                    } elseif (strpos($foundUrls, '/wp-content') !== false && strpos($foundUrls, '/wp-content') == 0) {

                        $foundUrls = str_replace('("', '', $foundUrls);
                        $foundUrls = str_replace("('", '', $foundUrls);
                        $foundUrls = str_replace('")', '', $foundUrls);
                        $foundUrls = str_replace("')", '', $foundUrls);
                        return 'url("' . self::$site_url . $foundUrls . '")';
                    } elseif (strpos($foundUrls, '/') === 0) {
                        
                        return 'url("' . $cssPath . $foundUrls . '")';
                    } else {

                        return 'url("' . $cssPath . '/' . $foundUrls . '")';
                    }
                }
            }
        }

        return $matches[0];
    }

    public function replaceCSS($matches)
    {
        if (!empty($matches)) {
            $foundUrls = trim($matches[1]);

            if (strpos($foundUrls, 'data:') !== false) {
                return 'url("' . $foundUrls . '")';
            } else {
                return '';
            }
        }

        return $matches[0];
    }

    public function maybe_do_combine($html)
    {

        if (!empty(get_option('wps_log_critCombine'))) {
            $this->log_criticalCombine = true;
            $this->logger = new wps_ic_logger('criticalCombine');
        }

        
        if (1 == 0 && $this->combine_exists() && (empty($_GET['forceRecombine']) && !$this->criticalCombine)) {
            $this->no_content_excludes = get_option('wps_no_content_excludes_css');
            if ($this->no_content_excludes !== false) {
                $this->allExcludes = array_merge($this->allExcludes, $this->no_content_excludes);
            }

            $html = $this->replace($html);
            return $html;
        }


        $this->no_content_excludes = [];
        $this->current_file = '';
        $this->file_count = 1;

        $this->setup_dirs();

        
        
        
        
        
        
        
        
        
        
        $wpc_lock_fp657 = null;
        $wpc_have_lock657 = false;
        if (!$this->criticalCombine) {
            $wpc_lock_fp657 = @fopen($this->combined_dir . '.combine.lock', 'c');
            if ($wpc_lock_fp657) {
                $wpc_have_lock657 = @flock($wpc_lock_fp657, LOCK_EX | LOCK_NB);
                if (!$wpc_have_lock657) {
                    @fclose($wpc_lock_fp657);
                    return $html;
                }
            }
        }

        $this->current_section = 'header';
        $html = preg_replace_callback('/<head(.*?)<\/head>/si', [$this, 'combine'], $html);
        

        if (!$this->criticalCombine) {

            $this->write_file_and_next();
            $this->current_section = 'footer';
            $this->file_count = 1;
        }

        $html = preg_replace_callback('/<\/head>(.*?)<\/body>/si', [$this, 'combine'], $html);

        $this->write_file_and_next();
        $this->wpc_sweep_unwritten644('css');

        if ($wpc_have_lock657 && $wpc_lock_fp657) {
            @flock($wpc_lock_fp657, LOCK_UN);
            @fclose($wpc_lock_fp657);
        }

        update_option('wps_no_content_excludes_css', $this->no_content_excludes);
        $html = $this->insert_combined_scripts($html);

        
        
        
        
        
        
        
        if ($this->criticalCombine && function_exists('wpc_cache_first_log')) {
            try {
                $wpc_cf271 = [];
                if (!empty($this->combined_dir) && is_dir($this->combined_dir)) {
                    foreach (array_slice((array) @glob($this->combined_dir . '*.css'), 0, 8) as $wpc_f271) {
                        $wpc_cf271[basename((string) $wpc_f271)] = (int) @filesize($wpc_f271);
                    }
                }
                wpc_cache_first_log('critcombine-receipt', '', '', [
                    'html_bytes' => strlen((string) $html),
                    'link_present' => strpos((string) $html, 'combined.css') !== false ? 1 : 0,
                    'files' => $wpc_cf271,
                ]);
            } catch (\Throwable $e) {
            }
        }

        return $html;
    }

    public function combine_exists()
    {
        
        
        $exists = false;
        if (is_dir($this->combined_dir)) {
            foreach (new \FilesystemIterator($this->combined_dir) as $wpc_f642) {
                if (strpos($wpc_f642->getFilename(), '.') !== 0) {
                    $exists = true;
                    break;
                }
            }
        }

        return $exists;
    }

    public function replace($html)
    {

        $html = preg_replace_callback($this->patterns, [$this, 'remove_scripts'], $html);
        $html = $this->insert_combined_scripts($html);

        return $html;
    }

    
    
    
    
    private function wpc_mark_live642()
    {
        try {
            $wpc_lv642 = rtrim($this->combined_dir, '/') . '/.wpc-live';
            if (!@is_file($wpc_lv642) || (int) @filemtime($wpc_lv642) < time() - 86400) {
                @file_put_contents($wpc_lv642, (string) time());
            }
        } catch (\Throwable $e) {
        }
    }

    public function insert_combined_scripts($html)
    {
        $combined_files = new \FilesystemIterator($this->combined_dir);

        if ($this->criticalCombine) {
            $link = '';
            $wpc_want276 = self::$isMobile ? 'wps_mobile_combined.css' : 'wps_combined.css';
            foreach ($combined_files as $file) {
                if (strpos($file->getFilename(), '.') === 0) {
                    continue;
                }
                
                
                if ($file->getFilename() !== $wpc_want276) {
                    continue;
                }
                
                
                
                
                
                
                if (substr($file->getFilename(), -4) !== '.css') {
                    continue;
                }
                $url = $this->combined_url_base . basename($file);
                if (strpos($url, 'http://') !== false) {

                    $url = str_replace('http://', 'https://', $url);
                }
                
                
                
                
                $wpc_h646 = (string) @file_get_contents($file . '.md5');
                if (strlen($wpc_h646) !== 32) { $wpc_h646 = (string) @md5_file((string) $file); }
                $wpc_h642 = substr($wpc_h646, 0, 10);
                $link = '<link rel="stylesheet" id="wpc-critical-combined-css" href="' . $url . '?hash=' . $wpc_h642 . '" type="text/css" media="all">' . PHP_EOL;
            }

            if ($link !== '') {
                $this->wpc_mark_live642();
            }
            $html = str_replace('<!--WPC_INSERT_COMBINED_CSS-->', $link, $html);
            return $html;
        }

        $header_links = '';
        $footer_links = '';

        foreach ($combined_files as $file) {
            if (strpos($file->getFilename(), '.') === 0) {
                continue;
            }
            
            
            
            if (substr($file->getFilename(), -4) !== '.css') {
                continue;
            }
            $url = $this->combined_url_base . basename($file);
            $criticalCSS = new wps_criticalCss();

            $styleSheetType = 'wpc-stylesheet';
            if (strpos($url, 'mobile') !== false) {
                $styleSheetType = 'wpc-mobile-stylesheet';
            }


            if (!empty($this->settings['critical']['css']) && $this->settings['critical']['css'] == '1' && $criticalCSS->criticalExists() !== false) {
                


                

                

                if (self::$isMobile) {
                    
                    if (strpos($file, 'wps_mobile') !== false) {
                        if (strpos($file, 'wps_mobile_header') !== false) {
                            $header_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        } else {
                            $footer_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        }
                    }
                } else {
                    
                    if (strpos($file, 'wps_mobile') === false) {
                        if (strpos($file, 'wps_header') !== false) {
                            $header_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        } else {
                            $footer_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        }
                    }
                }


            } else if (!empty($this->settings['remove-render-blocking']) && $this->settings['remove-render-blocking'] == '1') {
                

                if (strpos($file, 'wps_header') !== false) {
                    $header_links .= '<link rel="preload" as="style"  onload="this.rel=\'stylesheet\'" defer href="' . $url . '" type="text/css" media="all">' . PHP_EOL;
                } else {
                    $footer_links .= '<link rel="preload" as="style"  onload="this.rel=\'stylesheet\'" defer href="' . $url . '" type="text/css" media="all">' . PHP_EOL;
                }

            } else if (!empty($this->settings['inline-css']) && $this->settings['inline-css'] == '1') {
                

                if (strpos($file, 'wps_header') !== false) {
                    $combineContent = file_get_contents($file->getPathname());

                    if (!empty($combineContent)) {
                        $header_links .= '<style type="text/css" id="' . basename($file) . '">';
                        $header_links .= $this->minifyCSS($combineContent);
                        $header_links .= '</style>';
                    }

                } else {
                    $combineContent = file_get_contents($file->getPathname());

                    if (!empty($combineContent)) {
                        $footer_links .= '<style type="text/css" id="' . basename($file) . '">';
                        $footer_links .= $this->minifyCSS($combineContent);
                        $footer_links .= '</style>';
                    }
                }

            } else {

                
                if (self::$isMobile) {
                    
                    if (strpos($file, 'wps_mobile') !== false) {

                        

                        if (strpos($file, 'wps_mobile_header') !== false) {
                            $header_links .= '<link rel="preload" as="style" onload="this.rel=\'stylesheet\'" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;


                        } else {
                            $footer_links .= '<link rel="preload" as="style" onload="this.rel=\'stylesheet\'" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;


                        }
                    }
                } else {
                    
                    if (strpos($file, 'wps_mobile') === false) {
                        if (strpos($file, 'wps_header') !== false) {
                            $header_links .= '<link rel="preload" as="style" onload="this.rel=\'stylesheet\'" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        } else {
                            $footer_links .= '<link rel="preload" as="style" onload="this.rel=\'stylesheet\'" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        }
                    }
                }

            }
        }

        if ($this->hmwpReplace) {

            foreach ($this->hmwp_rewrite->_replace['from'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['to'][$key];
                $header_links = str_replace($value, $replace, $header_links);
                $footer_links = str_replace($value, $replace, $footer_links);
            }
        }


        if (!empty($_GET['testcombine'])) {
            $html = preg_replace('/<\/head>/', $header_links . '</head>', $html);
        } else {
            if (!empty($header_links)) {
                $html = str_replace('<!--WPC_INSERT_COMBINED_CSS-->', $header_links, $html);
            }
        }


        $html = preg_replace('/<\/body>/', $footer_links . '</body>', $html);

        if ($header_links !== '' || $footer_links !== '') {
            $this->wpc_mark_live642();
        }
        return $html;
    }

    public function setup_dirs()
    {
        mkdir(WPS_IC_COMBINE . $this->urlKey . '/css', 0777, true);
    }

    public function write_file_and_next()
    {

        $prefix = '';
        if (self::$isMobile) {
            $prefix = 'mobile_';
        }


        if ($this->current_file != '' && class_exists('wps_cdn_rewrite')) {
            if (method_exists('wps_cdn_rewrite', 'wpc_raster_naturalize')) {
                $this->current_file = wps_cdn_rewrite::wpc_raster_naturalize($this->current_file);
            }
            if (method_exists('wps_cdn_rewrite', 'wpc_svg_naturalize')) {
                $this->current_file = wps_cdn_rewrite::wpc_svg_naturalize($this->current_file);
            }
            if (method_exists('wps_cdn_rewrite', 'wpc_svg_zoneify')) {
                $this->current_file = wps_cdn_rewrite::wpc_svg_zoneify($this->current_file);
            }
        }

        if ($this->criticalCombine) {
            
            
            
            
            $this->wpc_write_if_changed646(self::$isMobile ? 'wps_mobile_combined.css' : 'wps_combined.css');
            return;
        }

        if ($this->current_file != '') {
            $this->wpc_write_if_changed646('wps_' . $prefix . $this->current_section . '_' . $this->file_count . '.css');
        }

        $this->file_count++;
        $this->current_file = '';
    }

    
    
    
    
    
    
    public function wpc_write_if_changed646($wpc_name646)
    {
        
        
        
        
        
        
        if ($this->wpc_looks_like_html_doc657($this->current_file)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Refused to write HTML-containing combined CSS: ' . $wpc_name646, true);
            }
            return;
        }
        $wpc_path646 = $this->combined_dir . $wpc_name646;
        $wpc_md5646 = md5($this->current_file);
        if (!@is_file($wpc_path646) || (string) @file_get_contents($wpc_path646 . '.md5') !== $wpc_md5646) {
            
            
            
            
            
            
            $wpc_tmp276 = $wpc_path646 . '.tmp-' . getmypid();
            if (@file_put_contents($wpc_tmp276, $this->current_file) !== false && @rename($wpc_tmp276, $wpc_path646)) {
                file_put_contents($wpc_path646 . '.md5', $wpc_md5646);
            } else {
                @unlink($wpc_tmp276);
            }
        }
        $this->wpc_written644[] = $wpc_name646;
    }

    
    
    
    
    public $wpc_written644 = [];
    public function wpc_sweep_unwritten644($ext)
    {
        try {
            $wpc_pfx644 = (self::$isMobile ? 'wps_mobile_' : 'wps_');
            foreach ((array) @glob($this->combined_dir . 'wps_*.' . $ext) as $wpc_f644) {
                $wpc_b644 = basename($wpc_f644);
                if ($ext === 'css' && !self::$isMobile && strpos($wpc_b644, 'wps_mobile_') === 0) {
                    continue;
                }
                if ($ext === 'css' && self::$isMobile && strpos($wpc_b644, 'wps_mobile_') !== 0) {
                    
                    
                    
                    continue;
                }
                if (!in_array($wpc_b644, $this->wpc_written644, true)) {
                    @unlink($wpc_f644);
                    @unlink($wpc_f644 . '.md5');
                }
            }
        } catch (\Throwable $e) {
        }
    }

    public function minifyCssOld($css)
    {
        if (!empty($this->settings['css_minify']) && $this->settings['css_minify'] == '1') {


            $css = preg_replace('/\/\*(.*?)\*\//s', '', $css); 
            $css = preg_replace('/\s+/', ' ', $css); 
            $css = preg_replace('/\s?([,:;{}])\s?/', '$1', $css); 
            $css = preg_replace('/;}/', '}', $css); 
        } else {
            
            $css = preg_replace('/\s+/', ' ', $css);
        }
        return $css;
    }

    public function script_combine_and_replace($tag)
    {
        if ($this->log_criticalCombine) {
            $this->logger->log('Starting new script.');
        }

        $tag = trim($tag[0]);
        if (empty($tag)) {
            return $tag;
        }
        $src = '';
        $media_query = null;

        
        if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::wpc_consent_family($tag)) {
            return $tag;
        }

        
        
        
        
        
        
        
        if (preg_match('/\bid=["\']wpc-|\bdata-wpc-|type=["\']wpc\//i', $tag)) {
            return $tag;
        }

        
        if (self::$excludes->strInArray($tag, $this->allExcludes)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('It is excluded.', true);
            }
            return $tag;
        }

        
        if (strpos($tag, 'ie9') !== false) {
            return $tag;
        }

        
        if (preg_match('/media=["\']([^"\']+)["\']/', $tag, $media_match)) {
            $media_query = $media_match[1];
            if ($this->log_criticalCombine) {
                $this->logger->log('Media query found: ' . $media_query);
            }
        }

        if (strpos($tag, '<link') !== false) {
            $is_src_set = preg_match('/href=["|\'](.*?)["|\']/', $tag, $src);
        } elseif (strpos($tag, '<style') !== false) {
            $is_src_set = preg_match('/<style\b[^>]*\bhref=["\'](.*?)["\'][^>]*>/i', $tag, $src);
        }

        if ($is_src_set == 1) {
            $src = str_replace('href=', '', $src);
            $src = str_replace("'", "", $src);
            $src = str_replace('"', "", $src);
            $src = $src[0];

            if ($this->log_criticalCombine) {
                $this->logger->log('Src: ' . $src);
            }

            if (!$this->combine_external && $this->url_key_class->is_external($src)) {
                if ($this->log_criticalCombine) {
                    $this->logger->log('Is External.');
                }
                return $tag;
            } else if ($this->combine_external && $this->url_key_class->is_external($src)) {
                $content = $this->getRemoteContent($src);
            } else {
                $content = $this->getLocalContent($src);
            }

            if (!$content) {
                $this->no_content_excludes[] = $src;
                return $tag;
            }


            $this->asset_url = $src;

            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'oldrewrite') {
                $content = preg_replace_callback("/url(\(((?:[^()])+)\))/i", [$this, 'rewrite_relative_url'], $content);
            } else {
                    $re = '~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]+))\s*\)~i';

                    $content = preg_replace_callback($re, function ($m) {
                        $url = '';
                        foreach ([1, 2, 3] as $i) {
                            if (isset($m[$i]) && $m[$i] !== '') {
                                $url = $m[$i];
                                break;
                            }
                        }

                        if ($url === '') return $m[0];

                        $new = $this->rewrite_relative_url($url);
                        if ($new === '' || $new === null) return $m[0];

                        
                        if (is_string($new) && preg_match('~^\s*url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]+))\s*\)\s*$~i', $new, $mm)) {
                            foreach ([1, 2, 3] as $i) {
                                if (isset($mm[$i]) && $mm[$i] !== '') {
                                    $new = $mm[$i];
                                    break;
                                }
                            }
                        }

                        if (!empty($m[1])) return 'url("' . $new . '")';
                        if (!empty($m[2])) return "url('" . $new . "')";
                        return 'url(' . $new . ')';
                    }, $content);

            }


        } else if ($this->combine_inline_scripts) {
            $src = 'Inline Script';

            if ($this->log_criticalCombine) {
                $this->logger->log('Is inline.');
            }

            $content = $tag;
            $content = preg_replace('/<style(.*?)>/', '', $content, -1, $count);
            $content = preg_replace('/<\/style>/', '', $content);

            if (!$count) {

                return $tag;
            }
        } else {
            return $tag;
        }

        if ($this->log_criticalCombine) {
            $this->logger->log('Fetched.');
        }


        $content = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $content);
        
        
        
        
        
        
        
        $wpc_fdset135 = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
        $wpc_fdraw135 = (is_array($wpc_fdset135) && !empty($wpc_fdset135['font-display']))
            ? strtolower((string) $wpc_fdset135['font-display']) : 'smart';
        if ($wpc_fdraw135 !== 'off') {
        $content = preg_replace_callback('/@font-face\s*\{([^}]*)\}/is', function ($m) use ($wpc_fdraw135) {
            $body = (string) preg_replace('/(?:^|;)\s*font-display\s*:\s*[a-zA-Z-]+\s*(?=;|$)/i', '', $m[1]);
            $body = ltrim($body, "; \t\r\n");
            
            
            
            
            $disp = 'swap';
            $wpc_fam135 = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $body, $wpc_ff)
                ? trim($wpc_ff[1], " \t\"'") : '';
            if ($wpc_fam135 !== '' && wpc_css_is_icon_font($wpc_fam135)) {
                $disp = 'block';
            } elseif (function_exists('wpc_font_display_effective')) {
                $disp = wpc_font_display_effective($wpc_fdraw135, $wpc_fam135);
                if (!in_array($disp, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) { $disp = 'swap'; }
            }
            return '@font-face{font-display: ' . $disp . ';' . ($body !== '' ? $body : '') . '}';
        }, $content);
        }

        
        
        
        

        if ($this->enabledCDN) {
            $content = preg_replace_callback('/src:\s*url\("([^"]+\.woff2)"\)\s*format\(\s*\'woff2\'\s*\);/is', [$this, 'changeFontToCDN'], $content);
        }

        $this->current_file .= "/* SCRIPT : $src */" . PHP_EOL;
        
        if ($media_query) {
            $this->current_file .= "@media " . $media_query . " {" . PHP_EOL;
            $this->current_file .= $content . PHP_EOL;
            $this->current_file .= "}" . PHP_EOL;
        } else {
            $this->current_file .= $content . PHP_EOL;
        }

        
        $this->write_file_and_next();
        

        if (!$this->firstFoundStyle) {
            $this->firstFoundStyle = true;
            return '<!--WPC_INSERT_COMBINED_CSS-->';
        } else {
            return '';
        }
    }

    public function getRemoteContent($url)
    {
        if ($this->log_criticalCombine) {
            $this->logger->log('Fetching script content.');
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        
        
        $args = array('timeout' => (int) apply_filters('wpc_combine_fetch_timeout', 3), 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36', 'headers' => array('Accept' => 'text/css,*/*;q=0.1', 'Accept-Language' => 'en-US,en;q=0.9',));


        $data = wp_remote_get($url, $args);


        if (is_wp_error($data)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Failed fetching script content: WP_Error.', true);
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($data);

        if ($response_code !== 200) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Failed fetching script content. Response code: ' . $response_code, true);
            }
            return false;
        }

        $wpc_body657 = wp_remote_retrieve_body($data);

        
        
        
        
        
        $wpc_ct657 = strtolower((string) wp_remote_retrieve_header($data, 'content-type'));
        if (strpos($wpc_ct657, 'text/html') !== false || $this->wpc_looks_like_html_doc657($wpc_body657)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Rejected non-CSS response (soft-404 / HTML) for: ' . $url, true);
            }
            return false;
        }

        if ($this->log_criticalCombine) {
            $this->logger->log('Script content fetched.');
        }

        return $wpc_body657;
    }

    public function getLocalContent($url)
    {
        $output = [];

        if ($this->log_criticalCombine) {
            $this->logger->log('Fetching script content.');
        }

        if ($this->hmwpReplace) {

            foreach ($this->hmwp_rewrite->_replace['to'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['from'][$key];
                $url = str_replace($value, $replace, $url);
            }
            if ($this->log_criticalCombine) {
                $this->logger->log('Did hidemywp replacements and got ' . $url);
            }
        }

        if (!empty($this->zone_name) && strpos($url, $this->zone_name) !== false) {
            preg_match('/a:(.*?)(\?|$)/', $url, $match);
            $url = trim($match[1]);
        }


        $url = preg_replace('/\?.*/', '', $url);

        $path = wp_make_link_relative($url);
        $path = ltrim($path, '/');

        
        $uploadDir = wp_upload_dir();
        $uploadDir = $uploadDir['basedir'];

        
        $includesPath = ABSPATH . WPINC;

        
        $themePath = get_theme_root();

        
        if (strpos($path, 'wp-content/plugins/') !== false) {
            
            $pathExploded = explode('wp-content/plugins/', $path);
            $justPath = $pathExploded[1];
            $finalPath = WP_PLUGIN_DIR . '/' . $justPath;
        } else if (strpos($path, 'wp-includes/') !== false) {
            
            $pathExploded = explode('wp-includes/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $includesPath . '/' . $justPath;
        } else if (strpos($path, 'wp-content/uploads/') !== false) {
            
            $pathExploded = explode('wp-content/uploads/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $uploadDir . '/' . $justPath;
        } else if (strpos($path, 'wp-content/themes/') !== false) {
            
            $pathExploded = explode('wp-content/themes/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $themePath . '/' . $justPath;
        } else {
            $finalPath = ABSPATH . $path;
        }


        if ($this->log_criticalCombine) {
            $this->logger->log('Fetching script content.' . $finalPath);
        }

        if (file_exists($finalPath)) {
            $content = file_get_contents($finalPath);
        }

        if (!$content) {

             global $wp_filesystem;

            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            WP_Filesystem();

            $content = false;
            if ($wp_filesystem && $wp_filesystem->exists($finalPath)) {
                $content = $wp_filesystem->get_contents($finalPath);
            }

            if (!$content || empty($content)) {

                if ($this->log_criticalCombine) {
                    $this->logger->log('Fetch failed,', true);
                }

                return false;
            }
        }

        if ($this->log_criticalCombine) {
            $this->logger->log('Fetched.');
        }

        return $content;
    }

    public function rewrite_relative_url(string $matched_url): string
    {
        $matched_url = trim($matched_url);
        $matched_url = trim($matched_url, " \t\n\r\0\x0B'\"");

        
        if ($matched_url === '') return '';
        if (stripos($matched_url, 'data:') === 0) return $matched_url;


        if (stripos($matched_url, '/cache/wp-cio-fonts/') !== false) return $matched_url;

        if (strpos($matched_url, $this->zone_name) !== false || strpos($matched_url, 'zapwp.net') !== false) {
            return $matched_url;
        }
        if (strpos($matched_url, 'google') !== false || strpos($matched_url, 'gstatic') !== false || strpos($matched_url, 'typekit') !== false) {
            return $matched_url;
        }

        $asset_url = $this->asset_url;
        $parsed_asset = parse_url($asset_url);
        $home = parse_url(get_home_url());

        $scheme = $parsed_asset['scheme'] ?? ($home['scheme'] ?? 'https');
        $host = $parsed_asset['host'] ?? ($home['host'] ?? '');

        
        if (preg_match('~^https?://~i', $matched_url)) {
            $absolute = $matched_url;
        } elseif (strpos($matched_url, '//') === 0) {
            $absolute = $scheme . ':' . $matched_url;
        } else {
            
            $asset_path = $parsed_asset['path'] ?? '/';
            $base_dir = rtrim(str_replace(basename($asset_path), '', $asset_path), '/');

            if (strpos($matched_url, '/') === 0) {
                
                $path = $matched_url;
            } else {
                
                $path = $base_dir . '/' . $matched_url;
            }

            
            $path = $this->normalize_path($path);

            $absolute = $scheme . '://' . $host . $path;
        }

        
        $lower = strtolower($matched_url);

        $is_font = (strpos($lower, '.eot') !== false || strpos($lower, '.woff') !== false || strpos($lower, '.woff2') !== false || strpos($lower, '.ttf') !== false);
        $is_img = (strpos($lower, '.jpg') !== false || strpos($lower, '.jpeg') !== false || strpos($lower, '.png') !== false || strpos($lower, '.gif') !== false || strpos($lower, '.svg') !== false || strpos($lower, '.webp') !== false);

        if ($is_font && !empty($this->settings['serve']['fonts'])) {


            $rru_fhost = function_exists('wp_parse_url') ? wp_parse_url($absolute, PHP_URL_HOST) : '';
            $rru_shost = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
            if (!empty($rru_fhost) && !empty($rru_shost) && strcasecmp((string) $rru_fhost, (string) $rru_shost) === 0
                && stripos((string) wp_parse_url($absolute, PHP_URL_PATH), '/wp-content/') === false) {
                return $absolute;
            }
            $wpc_fp746 = (string) wp_parse_url($absolute, PHP_URL_PATH);
            if (!empty($rru_fhost) && !empty($rru_shost) && strcasecmp((string) $rru_fhost, (string) $rru_shost) === 0
                && stripos($wpc_fp746, '/wp-content/') !== false
                && strcasecmp((string) $this->zone_name, (string) $rru_shost) !== 0
                && apply_filters('wpc_combine_css_natural_fonts', true)) {
                return 'https://' . $this->zone_name . $wpc_fp746;
            }
            return 'https://' . $this->zone_name . '/m:0/a:' . $absolute;
        }

        if ($is_img) {

            $serve = false;
            if (strpos($lower, '.jpg') !== false && !empty($this->settings['serve']['jpg'])) $serve = true;
            if (strpos($lower, '.jpeg') !== false && !empty($this->settings['serve']['jpg'])) $serve = true;
            if (strpos($lower, '.png') !== false && !empty($this->settings['serve']['png'])) $serve = true;
            if (strpos($lower, '.gif') !== false && !empty($this->settings['serve']['gif'])) $serve = true;
            if (strpos($lower, '.svg') !== false && !empty($this->settings['serve']['svg'])) $serve = true;

            if ($serve) {


                $wpc_ih = function_exists('wp_parse_url') ? (string) wp_parse_url($absolute, PHP_URL_HOST) : '';
                $wpc_sh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
                $wpc_ip = function_exists('wp_parse_url') ? (string) wp_parse_url($absolute, PHP_URL_PATH) : '';
                if ($wpc_ih !== '' && $wpc_sh !== '' && strcasecmp($wpc_ih, $wpc_sh) === 0
                    && stripos($wpc_ip, '/wp-content/') !== false
                    && strcasecmp((string) $this->zone_name, $wpc_sh) !== 0
                    && apply_filters('wpc_combine_css_natural_rasters', true)) {
                    return 'https://' . $this->zone_name . $wpc_ip;
                }
                return 'https://' . $this->zone_name . '/q:u/r:0/wp:0/w:1/u:' . $absolute;
            }
        }


        return $absolute;
    }

    


    private function normalize_path(string $path): string
    {
        $is_abs = (strpos($path, '/') === 0);
        $parts = explode('/', $path);
        $out = [];

        foreach ($parts as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $p;
        }

        return ($is_abs ? '/' : '') . implode('/', $out);
    }

    public function changeFontToCDN($html)
    {
        
        
        if (stripos($html[1], '/cache/wp-cio-fonts/') !== false) {
            return 'src:url("' . $html[1] . '");';
        }


        if (!empty($this->zone_name) && strpos($html[1], $this->zone_name) !== false) {
            return 'src:url("' . $html[1] . '");';
        }


        $cf2_host = function_exists('wp_parse_url') ? wp_parse_url($html[1], PHP_URL_HOST) : '';
        $cf2_site = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if (!empty($cf2_host) && !empty($cf2_site) && strcasecmp((string) $cf2_host, (string) $cf2_site) === 0
            && stripos((string) wp_parse_url($html[1], PHP_URL_PATH), '/wp-content/') === false) {
            return 'src:url("' . $html[1] . '");';
        }
        if (!empty($this->settings['font-subsetting']) && $this->settings['font-subsetting'] == '1') {
            if (strpos($html[1], 'icon') === false && strpos($html[1], 'awesome') === false && strpos($html[1], 'lightgallery') === false && strpos($html[1], 'gallery') === false && strpos($html[1], 'side-cart-woocommerce') === false) {
                return 'src:url("https://' . $this->zone_name . '/font:true/a:' . $html[1] . '");';
            }
        }

        $wpc_ffp746 = (string) wp_parse_url($html[1], PHP_URL_PATH);
        if (!empty($cf2_host) && !empty($cf2_site) && strcasecmp((string) $cf2_host, (string) $cf2_site) === 0
            && stripos($wpc_ffp746, '/wp-content/') !== false
            && strcasecmp((string) $this->zone_name, (string) $cf2_site) !== 0
            && apply_filters('wpc_combine_css_natural_fonts', true)) {
            return 'src:url("https://' . $this->zone_name . $wpc_ffp746 . '");';
        }
        return 'src:url("https://' . $this->zone_name . '/m:0/a:' . $html[1] . '");';
    }

    public function changeBgImageToMobile($html)
    {
        if (!$this->isMobile()) {
            return $html[0];
        }

        $bgEntire = $html[0];
        $bgUrl = $html[1];

        $MobileBg = str_replace('m:0/', 'mo:1/', $bgUrl);
        $html = str_replace($bgUrl, $MobileBg, $bgEntire);

        
        return $html;
    }

    public function remove_scripts($tag)
    {
        $tag = $tag[0];
        $src = '';


        if (strpos($tag, 'wpc-critical-css') !== false
            || strpos($tag, 'wpc-gfont-atf') !== false
            || strpos($tag, 'wpc-elementor-anim-start') !== false
            || strpos($tag, 'wpc-lazy-thumb-bgfix') !== false


            || strpos($tag, 'wpc-lcp-bg-authority') !== false
            || strpos($tag, 'wpc-bgvideo-contain') !== false) {
            return $tag;
        }

        if (strpos('rs6', $tag) !== false) {
            return $tag;
        }

        if (!$this->combine_external && $this->url_key_class->is_external($tag)) {
            return $tag;
        }

        if (current_user_can('manage_wpc_settings') || self::$excludes->strInArray($tag, $this->allExcludes)) {
            return $tag;
        }


        $is_src_set = preg_match('/href=["|\'](.*?)["|\']/', $tag, $src);
        if ($is_src_set == 1) {

        } else if ($this->combine_inline_scripts) {
            $src = 'Inline Script';

            $content = $tag;
            $content = preg_replace('/<style(.*?)>/', '', $content, -1, $count);

            if (!$count) {

                return $tag;
            }
        } else {
            return $tag;
        }

        if (!$this->firstFoundStyle) {
            $this->firstFoundStyle = true;
            return '<!--WPC_INSERT_COMBINED_CSS-->';
        } else {
            return '';
        }
    }

    public function get_combined_css($html)
    {
        
        $this->current_file = '';
        $this->combine_external = false;
        $this->combine_inline_scripts = true;

        
        if (preg_match('/<head(.*?)<\/head>/si', $html, $head_match)) {
            $this->combine($head_match);
        }

        
        if (preg_match('/<\/head>(.*?)<\/body>/si', $html, $body_match)) {
            $this->combine($body_match);
        }

        return $this->current_file;
    }

    public function combine($html)
    {
        $html = $html[0];

        
        if (!empty($_GET['testCompliant'])) {
            $html = $this->cookieCompliantCSS($html);
        }

        
        $script_placeholders = [];
        $script_counter = 0;

        $html = preg_replace_callback('/<script\b[^>]*>.*?<\/script>/si', function ($match) use (&$script_placeholders, &$script_counter) {
            $placeholder = "___SCRIPT_PLACEHOLDER_{$script_counter}___";
            $script_placeholders[$placeholder] = $match[0];
            $script_counter++;
            return $placeholder;
        }, $html);

        
        $html = preg_replace_callback($this->patterns, [$this, 'script_combine_and_replace'], $html);

        
        foreach ($script_placeholders as $placeholder => $original_script) {
            $html = str_replace($placeholder, $original_script, $html);
        }

        return $html;
    }

    public function cookieCompliantCSS($html)
    {
        $pattern = '/<script[^>]*id="cmplz-cookiebanner-js-extra"[^>]*>(.*?)<\/script>/si';
        if (preg_match($pattern, $html, $matches)) {
            $script_content = $matches[1];

            
            if (preg_match('/var complianz\s*=\s*(\{.*?\});/s', $script_content, $json_match)) {
                $json_string = $json_match[1];

                
                $complianz = json_decode($json_string, true);


                if ($complianz && isset($complianz['css_file'])) {
                    $css_file = $complianz['css_file'];
                    $banner_id = $complianz['user_banner_id'] ?? '1';
                    $type = $complianz['consenttype'] ?? 'optin';

                    
                    $css_file_final = str_replace(['{banner_id}', '{type}'], [$banner_id, $type], $css_file);

                    
                    

                    if (!empty($_GET['dbgCmplz']) && $_GET['dbgCmplz'] == 'inject-entities') {
                        $link_tag = htmlentities("<link rel='stylesheet' id='wpc-cmplz-banner' href='" . $css_file_final . "' type='text/css' media='all' />");
                    } else {
                        $link_tag = '<link rel="stylesheet" id="wpc-cmplz-banner" href="' . $css_file_final . '" type="text/css" media="all" />';
                    }

                    $pattern = '/<script[^>]*id="cmplz-cookiebanner-js-extra"[^>]*>.*?<\/script>/si';

                    if (preg_match($pattern, $html, $matches)) {
                        $matched_script = $matches[0];

                        
                        $html = str_replace($matched_script, $link_tag, $html);
                    } else {
                        return 'REGEX DID NOT MATCH';
                    }

                    return $html;
                }
            }
        }

        return $html;
    }
}