<?php

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

    public function __construct()
    {
        $this->cssPath = '';
        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();
        $this->combined_dir = WPS_IC_COMBINE . $this->urlKey . '/css/';
        $this->combined_url_base = WPS_IC_COMBINE_URL . $this->urlKey . '/css/';

        $this::$isMobile = $this->isMobile();

        $this->firstFoundStyle = false;

        self::$excludes = new wps_ic_excludes();
        self::$rewrite = new wps_cdn_rewrite();
        self::$site_url = site_url();
        $this->settings = get_option(WPS_IC_SETTINGS);
        #$this->filesize_cap           = '500000'; //in bytes
        $this->filesize_cap = '100000000000'; //in bytes
        $this->combine_inline_scripts = true;
        $this->combine_external = false;
        $this->allExcludes = self::$excludes->combineCSSExcludes();

        if (!empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))) {
            $this->settings['inline-css'] = '0';
            $this->criticalCombine = true;
            $this->filesize_cap = '10000000000'; //in bytes
            $this->combine_inline_scripts = true;
            $this->combine_external = true;
            $this->allExcludes = ['media="print"', 'media=\'print\''];
        }

        $this->patterns = '/(<link[^>]*rel=["\']stylesheet["\'][^>]*>)|((?<!<noscript>)<style\b[^>]*>(.*?)<\/style>)|(<link\b[^>]*?onload=["\']this.rel=["\']stylesheet["\']["\'][^>]*>)/si';

        $custom_cname = get_option('ic_custom_cname');
        if (empty($custom_cname) || !$custom_cname) {
            $this->zone_name = get_option('ic_cdn_zone_name');
        } else {
            $this->zone_name = $custom_cname;
        }

        //Check if Hide my WP is active and get replaces
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

        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

        if (!empty($_GET['dbgMobile'])) {
            $fp = fopen(ABSPATH . 'dbgMobile.txt', 'a+');
            fwrite($fp, 'UA: ' . $userAgent . "\r\n");
            fclose($fp);
        }

        // Desktop Detection
        $desktopKeywords = ['windows nt', 'macintosh', 'linux', 'cros', 'x11'];

        foreach ($desktopKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                if (!empty($_GET['dbgMobile'])) {
                    $fp = fopen(ABSPATH . 'dbgMobile.txt', 'a+');
                    fwrite($fp, 'Desktop Detected: ' . $keyword . "\r\n");
                    fclose($fp);
                }
                return false; // Detected a desktop identifier, so it's not a mobile device
            }
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            // Define an array of mobile device keywords to check against
            $mobileKeywords = ['android', 'iphone', 'ipad', 'ipod', 'windows phone', 'blackberry', 'bb10', 'webos', 'symbian', 'playbook', 'kindle', 'silk', 'opera mini', 'opera mobi', 'palm'];

            // Check if the user agent contains any of the mobile device keywords
            foreach ($mobileKeywords as $keyword) {
                if (strpos($userAgent, $keyword) !== false) {
                    if (!empty($_GET['dbgMobile'])) {
                        $fp = fopen(ABSPATH . 'dbgMobile.txt', 'a+');
                        fwrite($fp, 'Mobile Detected: ' . $keyword . "\r\n");
                        fclose($fp);
                    }
                    return true; // Found a match, so it's a mobile device
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
        preg_match_all('/<img\s[^>]*\bsrc\s*=\s*["\']([^"\']*)["\'][^>]*>/si', $html, $matches);
        if (!empty($matches[1])) {
            $images = array_slice($matches[1], 0, 3);
            foreach ($images as $k => $src) {
                $preloadLCP[] = $src;
            }
        }

        return $preloadLCP;
    }


    public function preparePreloads($html)
    {
        preg_match_all('/<link\s+[^>]*\bhref=(["\'])(.*?)\1[^>]*>/is', $html, $matches);

        $AlreadyLoadedLocaLFonts = [];
        $wpcPreloads = [];

        if (!empty($matches[2])) {
            foreach ($matches[2] as $k => $href) {

                if (strpos($href, '.css') === false && strpos($href, 'fonts.google') === false) {
                    continue;
                }

                // Href is local
                $cleanHref = explode('?', trim($href));
                $cleanHref = trim($cleanHref[0]);

                if (strpos($cleanHref, self::$site_url) !== false) {
                    $path = str_replace(self::$site_url, '', $cleanHref);
                    $path = ltrim($path, '/');
                    $relativePath = ABSPATH . '/' . $path;

                    $content = file_get_contents($relativePath);

                    if (!empty($content)) {
                        // Get the filename
                        $cssFilename = basename($href);
                        $cssUrlPath = str_replace($cssFilename, '', $href);

                        // Remove the site URL from the Path to retrieve just the path
                        $cssPath = str_replace([self::$site_url . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
                        $cssPath = rtrim($cssPath, '/');
                        $this->cssPath = self::$site_url . '/' . $cssPath;

                        // Find All The Fonts
                        $css = $this->fixUrlPaths($content);
                        #$foundFonts = $this->findFonts($css);
                        if (!empty($foundFonts)) {
                            $AlreadyLoaded = [];
                            foreach ($foundFonts as $i => $font) {
                                if (!in_array($font, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $font;
                                    #$wpcPreloads[] = "<link rel='preload' href='" . $font . "' as='font' />";
                                }
                            }
                        }

                        // Find All The Images
                        $foundBackgrounds = $this->findBackgrounds($css);
                        if (!empty($foundBackgrounds)) {
                            $AlreadyLoaded = [];
                            foreach ($foundBackgrounds as $i => $bg) {
                                if (!in_array($bg, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $bg;
                                    #$wpcPreloads[] = "<link rel='preload' href='" . $bg . "' as='image' />";
                                }
                            }
                        }

                    }
                } elseif (strpos($href, 'fonts.google')) {
                    #$preload = "<link rel='preload' href='" . $href . "' as='style' />";
                    #$wpcPreloads[] = $preload;
                } elseif (strpos($href, 'fontawesome.com')) {
                    if (!in_array($href, $AlreadyLoadedLocaLFonts)) {
                        $AlreadyLoadedLocaLFonts[] = $href;
                        $preload = "<link rel='preload' href='" . $href . "' as='style' />";
                        $wpcPreloads[] = $preload;
                    }
                }
            }
        }

        return $wpcPreloads;
    }

    public function fixUrlPaths($css)
    {
        $css = preg_replace_callback('/url(\(((?:[^()]+|(?1))+)\))/m', [$this, 'fixPathsWalker'], $css);

        // Fix URLs inside @import statements
        $css = preg_replace_callback('/@import\s+["\']([^"\']+)["\'];?/i', [$this, 'fixImportPaths'], $css);

        return $css;
    }

    public function findBackgrounds($css)
    {
        $pattern = '/(?:background(?:-image)?\s*:\s*url\s*\(\s*([\'"]?)(.*?)\1\s*\))/si';

        // Perform the regular expression match
        preg_match_all($pattern, $css, $matches);

        // Extracted URLs will be in $matches[1]
        $fontUrls = $matches[2];

        // Filter the URLs based on file extensions (eot, woff, etc.)
        $filteredUrls = array_filter($fontUrls, function ($url) {
            return preg_match('/\.(svg|jpeg|jpg|gif|png)\b/', $url);
        });

        // Remove quotes from the filtered URLs
        $filteredUrls = array_filter(array_map(function ($url) {
            return trim($url, '"\'');
        }, $filteredUrls));


        if (!empty($filteredUrls)) {
            return $filteredUrls;
        }

        return false;
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
        // Validate input
        if (!is_string($url) || $upCount < 0) {
            return false;
        }

        // Remove any trailing slashes from the URL
        $url = rtrim($url, '/');

        // Split the URL into parts
        $urlParts = parse_url($url);

        // If the URL doesn't have a path, there's nothing to move up
        if (!isset($urlParts['path'])) {
            return $url;
        }

        // Get the path and split it into segments
        $path = explode('/', trim($urlParts['path'], '/'));

        // Move up the specified number of directories
        $path = array_slice($path, 0, -$upCount);

        // Reconstruct the URL
        $urlParts['path'] = '/' . implode('/', $path);

        // Reassemble the URL
        $resultUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . (isset($urlParts['port']) ? ':' . $urlParts['port'] : '') . $urlParts['path'];

        return $resultUrl . '/';
    }

    public function preloadFontFace($css)
    {
        $preloadFonts = [];
        preg_match_all('/@font-face\s*{([^}]+)}/', $css, $matches);
        if ($matches) {
            foreach ($matches as $fontface) {
                preg_match('/url\("([^"]*woff2[^"]*)"\)/si', $fontface[0], $matchesWoff2);
                $preloadFonts[] = $matchesWoff2[1];
            }
        }

        return $preloadFonts;
    }

    public function isHtml($string)
    {
        return preg_match("/<[^<]+>/", $string) === 1;
    }

    public function doInline($html)
    {
        $wpcPreloads = [];
        preg_match_all('/<link\s+[^>]*\bhref=(["\'])(.*?)\1[^>]*>/is', $html, $matches);

        if (!empty($_GET['dbgStyleInline'])) {
            return print_r([$matches, $html], true);
        }

        $excludes_class = new wps_ic_excludes();

        if (!empty($matches[2])) {
            foreach ($matches[2] as $k => $href) {

                if ($excludes_class->strInArray($matches[0][$k], $excludes_class->inlineCSSExcludes())) {
                    continue;
                }

                if (!empty($_GET['dbgStyleInline2'])) {
                    $html .= print_r(['asd2', $href, self::$site_url, $matches[0][$k]], true);
                    continue;
                }

                if (strpos($href, '.css') === false && strpos($href, 'fonts.google') === false) {
                    continue;
                }

                // Href is local
                $cleanHref = explode('?', trim($href));
                $cleanHref = trim($cleanHref[0]);

                if (!empty($_GET['dbgStyleInline3'])) {
                    $html .= print_r(['asd3', $href, self::$site_url, strpos($cleanHref, self::$site_url) !== false], true);
                    continue;
                }

                #if (strpos($cleanHref, self::$site_url) !== false && strpos(strtolower($cleanHref), 'divi') === false) {
                if (strpos($cleanHref, self::$site_url) !== false) {
                    $path = str_replace(self::$site_url, '', $cleanHref);
                    $path = ltrim($path, '/');
                    $relativePath = ABSPATH . '/' . $path;

                    if (!file_exists($relativePath)) {
                        // do nothing
                        continue;
                    }

                    // get the file content
                    $content = file_get_contents($relativePath);

                    if (!empty($_GET['dbgStyleInline4'])) {
                        $html .= print_r(['asd4', $cleanHref, strlen($content)], true);
                        continue;
                    }

                    if (!empty($content)) {
                        // Check if it's valid CSS
                        // Get the filename
                        $cssFilename = basename($href);
                        $cssUrlPath = str_replace($cssFilename, '', $href);

                        #return print_r(array($cssFilename,$cssUrlPath),true);

                        // Remove the site URL from the Path to retrieve just the path
                        $cssPath = str_replace([self::$site_url . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
                        $cssPath = rtrim($cssPath, '/');
                        $this->cssPath = self::$site_url . '/' . $cssPath;

                        $content = $this->fixControlCharacter($content);
                        $content = $this->removeCommentsFromCSS($content);
                        $content = $this->removeCharsetFromCSS($content);
                        $content = $this->fixAnimations($content);
                        $content = $this->fixUrlPaths($content);

                        // Find FontFaces
                        $content = $this->findFontFace($content);

                        // Find All The Fonts
                        $foundFonts = $this->findFonts($content);
                        if (!empty($foundFonts)) {
                            $AlreadyLoaded = [];
                            foreach ($foundFonts as $i => $font) {
                                if (!in_array($font, $AlreadyLoaded)) {
                                    $AlreadyLoaded[] = $font;

                                    #$content = str_replace('src:url("'.$font.'") format("woff2");', '', $content);
                                    #$content = str_replace("src:url('".$font."') format('woff2');", '', $content);

                                    if (strpos($font, 'icon') !== false) continue;
                                    $figurePreloadType = $this->figurePreloadType($font);
                                    $wpcPreloads[] = "<link rel='wpc-lazy-font' href='" . $font . "' as='" . $figurePreloadType['as'] . "' type='" . $figurePreloadType['type'] . "' " . $figurePreloadType['extra'] . ">";
                                }
                            }
                        }

                        // Find All The Images
                        #$foundBackgrounds = $this->findBackgrounds($content);
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
                    // <link rel='preload' id='et-gf-open-sans-css' href='https://fonts.googleapis.com/css?family=Open+Sans%3A400%2C700&#038;ver=1.3.12' as='style' media='all' onload="this.onload=null;this.rel='stylesheet'" />
                    $preload = "<link rel='preload' href='" . $href . "' as='style' />";
                    $html = str_replace($matches[0][$k], $preload . $matches[0][$k], $html);
                } elseif (strpos($href, 'fontawesome.com')) {
                    // <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.13.1/css/all.css" integrity="sha384-B9BoFFAuBaCfqw6lxWBZrhg/z4NkwqdBci+E+Sc2XlK/Rz25RYn8Fetb+Aw5irxa" crossorigin="anonymous">
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
        // Use a regular expression to remove comments (/* ... */)
        $cssWithoutComments = preg_replace('/\/\*[^*]*\*+([^\/][^*]*\*+)*\//', '', $css);
        #$cssWithoutCommentsAndNewLines = preg_replace('/\/\*[^*]*\*+([^\/][^*]*\*+)*\s*\*\//', '', $css);
        return $cssWithoutComments;
    }

    public function removeCharsetFromCSS($css)
    {
        // Use a regular expression to remove @charset declarations
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

    public function findFontFace($css)
    {
        return preg_replace_callback('/@font-face\s*{[^}]+}/sim', function ($fontface) {
            $fontFamily = $fontStyle = $fontWeight = $woffUrl = '';
            $urlFound = false; // Flag to indicate if a URL was found

            // Try to match .woff or .woff2 URL
            if (preg_match('/url\((["\']?)([^)]+\.(woff2?))\1\)/si', $fontface[0], $matchesWoffUrl)) {
                $woffUrl = $matchesWoffUrl[2];
                $urlFound = true; // URL found, set flag to true
            }

            // Extract font-family, font-style, and font-weight
            if (preg_match('/font-family\s*:\s*([^;]+);/si', $fontface[0], $matchesFontFamily)) {
                $fontFamily = "font-family: " . $matchesFontFamily[1] . ";";
            }
            if (preg_match('/font-style\s*:\s*([^;]+);/si', $fontface[0], $matchesStyle)) {
                $fontStyle = 'font-style: ' . $matchesStyle[1] . ';';
            }
            if (preg_match('/font-weight\s*:\s*([^;]+);/si', $fontface[0], $matchesWeight)) {
                $fontWeight = 'font-weight: ' . $matchesWeight[1] . ';';
            }

            // If a URL was found, construct a new @font-face declaration; otherwise, return the original
            if ($urlFound) {
                $format = strpos($woffUrl, '.woff2') !== false ? 'woff2' : 'woff';
                return "@font-face{{$fontFamily}{$fontStyle}{$fontWeight}font-display:swap;src:url(\"$woffUrl\") format(\"$format\");}";
            } else {
                return $fontface[0]; // Return the original @font-face declaration
            }
        }, $css);
    }

    public function findFonts($css)
    {
        // Define the regular expression pattern
        $pattern = '/url\(([^)]+)\)/si';

        // Perform the regular expression match
        preg_match_all($pattern, $css, $matches);

        // Extracted URLs will be in $matches[1]
        $fontUrls = $matches[1];

        // Filter the URLs based on file extensions (eot, woff, etc.)
        $filteredUrls = array_filter($fontUrls, function ($url) {
            return preg_match('/\.(woff2)\b/', $url);
        });

        // Remove quotes from the filtered URLs
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
        // Remove spaces after colons
        $css = str_replace(': ', ':', $css);

        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);

        $css = preg_replace('/\/\*(.*?)\*\//s', '', $css); // Remove comments
        $css = preg_replace('/\s+/', ' ', $css); // Remove multiple whitespaces
        $css = preg_replace('/\s?([,:;{}])\s?/', '$1', $css); // Remove spaces around selectors and declarations
        $css = preg_replace('/;}/', '}', $css); // Remove trailing semicolons before closing brace

        return $css;
    }

    public function lazyFontawesome($html)
    {
        preg_match_all('/<link\s+[^>]*\bhref=(["\'])(.*?)\1[^>]*>/is', $html, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $k => $href) {
                if (strpos($href, 'fontawesome.com') || strpos($href, 'font-awesome')) {
                    // <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.13.1/css/all.css" integrity="sha384-B9BoFFAuBaCfqw6lxWBZrhg/z4NkwqdBci+E+Sc2XlK/Rz25RYn8Fetb+Aw5irxa" crossorigin="anonymous">
                    $preload = "<link rel='preload' href='" . $href . "' as='style' media='all' onload=\"this.onload=null;this.rel='stylesheet'\" />";
                    $html = str_replace($matches[0][$k], $preload, $html);
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

                // Remove the wrapping brackets
                $foundUrls = rtrim($foundUrls, ')');
                $foundUrls = ltrim($foundUrls, '(');
                $foundUrls = trim($foundUrls);

                // If the found url has // or http/s, just set on CDN?
                if (strpos($foundUrls, '//') === 0 || strpos($foundUrls, 'http') === 0) {
                    // Real URL, leave alone?
                    return 'url("' . $foundUrls . '")';
                } else {

                    // Remove the wrapping brackets
                    $foundUrls = rtrim($foundUrls, ')');
                    $foundUrls = ltrim($foundUrls, '(');

                    // If the found url has at least one ../ then do something with it
                    if (strpos($foundUrls, '../') !== false) {
                        $count = substr_count($foundUrls, '../');

                        #return print_r(array($this->cssPath, $count),true);

                        $newUrl = $this->moveUpDirectories($this->cssPath, $count);
                        $path = str_replace('../', '', $foundUrls);

                        // Once again, check if the file exists in figured out path
                        #if (file_exists($dirName . '/' . $walker)) {
                        return 'url("' . $newUrl . $path . '")';
                        #}
                    } elseif (strpos($foundUrls, './') !== false) {

                        // Same folder
                        $foundUrls = ltrim($foundUrls, '(');
                        $foundUrls = rtrim($foundUrls, ')');

                        // Get just the clean path, without ../
                        $removeRelative = str_replace('./', '', $foundUrls);

                        // Once again, check if the file exists in figured out path
                        return 'url("' . $cssUrlPath . $removeRelative . '")';
                    } elseif (strpos($foundUrls, '/wp-content') !== false && strpos($foundUrls, '/wp-content') == 0) {

                        $foundUrls = str_replace('("', '', $foundUrls);
                        $foundUrls = str_replace("('", '', $foundUrls);
                        $foundUrls = str_replace('")', '', $foundUrls);
                        $foundUrls = str_replace("')", '', $foundUrls);
                        return 'url("' . self::$site_url . $foundUrls . '")';
                    } elseif (strpos($foundUrls, '/') === 0) {
                        // Handle URLs starting with '/'
                        return 'url("' . $cssPath . $foundUrls . '")';
                    } else {
                        // its relative to the css script
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

		    if (!empty(get_option('wps_log_critCombine'))){
			    $this->log_criticalCombine = true;
			    $this->logger = new wps_ic_logger('criticalCombine');
		    }

        // Disabled for some reason?!
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

        $this->current_section = 'header';
        $html = preg_replace_callback('/<head(.*?)<\/head>/si', [$this, 'combine'], $html);
        #return 'bla'.$html;

        if (!$this->criticalCombine) {
            //we want 1 file in criticalCombine so we dont do this
            $this->write_file_and_next();
            $this->current_section = 'footer';
            $this->file_count = 1;
        }

        $html = preg_replace_callback('/<\/head>(.*?)<\/body>/si', [$this, 'combine'], $html);

        $this->write_file_and_next();

        update_option('wps_no_content_excludes_css', $this->no_content_excludes);
        $html = $this->insert_combined_scripts($html);

        return $html;
    }

    public function combine_exists()
    {
        $exists = is_dir($this->combined_dir);
        if ($exists) {
            $exists = (new \FilesystemIterator($this->combined_dir))->valid();
        }

        return $exists;
    }

    public function replace($html)
    {

        $html = preg_replace_callback($this->patterns, [$this, 'remove_scripts'], $html);
        $html = $this->insert_combined_scripts($html);

        return $html;
    }


    public function insert_combined_scripts($html)
    {
        $combined_files = new \FilesystemIterator($this->combined_dir);

        if ($this->criticalCombine) {
            foreach ($combined_files as $file) {
                $url = $this->combined_url_base . basename($file);
                $link = '<link rel="stylesheet" id="wpc-critical-combined-css" href="' . $url . '?hash='.time().'" type="text/css" media="all">' . PHP_EOL;
            }

            $html = str_replace('<!--WPC_INSERT_COMBINED_CSS-->', $link, $html);
            return $html;
        }

        $header_links = '';
        $footer_links = '';

        foreach ($combined_files as $file) {
            $url = $this->combined_url_base . basename($file);
            $criticalCSS = new wps_criticalCss();

            $styleSheetType = 'wpc-stylesheet';
            if (strpos($url, 'mobile') !== false) {
                $styleSheetType = 'wpc-mobile-stylesheet';
            }


            if (!empty($this->settings['critical']['css']) && $this->settings['critical']['css'] == '1' && $criticalCSS->criticalExists() !== false) {
                /////////////// Critical CSS Option is Enabled

                //        if (strpos($file, 'wps_header') !== false) {
                //          $header_links .= '<link rel="'.$styleSheetType.'" href="' . self::$rewrite->adjust_src_url($url) . '" type="'.$styleSheetType.'" media="all">' . PHP_EOL;
                //        } else {
                //          $footer_links .= '<link rel="'.$styleSheetType.'" href="' . self::$rewrite->adjust_src_url($url) . '" type="'.$styleSheetType.'" media="all">' . PHP_EOL;
                //        }

                if (self::$isMobile) {
                    // Mobile
                    if (strpos($file, 'wps_mobile') !== false) {
                        if (strpos($file, 'wps_mobile_header') !== false) {
                            $header_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        } else {
                            $footer_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        }
                    }
                } else {
                    // Desktop
                    if (strpos($file, 'wps_mobile') === false) {
                        if (strpos($file, 'wps_header') !== false) {
                            $header_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        } else {
                            $footer_links .= '<link rel="stylesheet" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                        }
                    }
                }


            } else if (!empty($this->settings['remove-render-blocking']) && $this->settings['remove-render-blocking'] == '1') {
                //////////////// Remove render blocking option is Enabled

                if (strpos($file, 'wps_header') !== false) {
                    $header_links .= '<link rel="preload" as="style"  onload="this.rel=\'stylesheet\'" defer href="' . $url . '" type="text/css" media="all">' . PHP_EOL;
                } else {
                    $footer_links .= '<link rel="preload" as="style"  onload="this.rel=\'stylesheet\'" defer href="' . $url . '" type="text/css" media="all">' . PHP_EOL;
                }

            } else if (!empty($this->settings['inline-css']) && $this->settings['inline-css'] == '1') {
                /////////////// Inline CSS Option is Enabled

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

                // Inline is not enabled, critical is not enabled
                if (self::$isMobile) {
                    // Mobile
                    if (strpos($file, 'wps_mobile') !== false) {

                        #$combineContent = file_get_contents($file->getPathname());

                        if (strpos($file, 'wps_mobile_header') !== false) {
                            $header_links .= '<link rel="preload" as="style" onload="this.rel=\'stylesheet\'" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                            //              $header_links .= '<style type="text/css" id="' . basename($file) . '">';
                            //              $header_links .= $this->minifyCss($combineContent);
                            //              $header_links .= '</style>';
                        } else {
                            $footer_links .= '<link rel="preload" as="style" onload="this.rel=\'stylesheet\'" href="' . self::$rewrite->adjust_src_url($url) . '" type="text/css" media="all"/>' . PHP_EOL;
                            //              $footer_links .= '<style type="text/css" id="' . basename($file) . '">';
                            //              $footer_links .= $this->minifyCss($combineContent);
                            //              $footer_links .= '</style>';
                        }
                    }
                } else {
                    // Desktop
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
            //apply their replacements to our combined files because they are doing them before our insert
            foreach ($this->hmwp_rewrite->_replace['from'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['to'][$key];
                $header_links = str_replace($value, $replace, $header_links);
                $footer_links = str_replace($value, $replace, $footer_links);
            }
        }

        //header
        if (!empty($_GET['testcombine'])) {
            $html = preg_replace('/<\/head>/', $header_links . '</head>', $html);
        } else {
            if (!empty($header_links)) {
                $html = str_replace('<!--WPC_INSERT_COMBINED_CSS-->', $header_links, $html);
            }
        }

        //footer
        $html = preg_replace('/<\/body>/', $footer_links . '</body>', $html);

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

        if ($this->criticalCombine) {
            file_put_contents($this->combined_dir . 'wps_combined.css', $this->current_file);
            return;
        }

        if ($this->current_file != '') {
            file_put_contents($this->combined_dir . 'wps_' . $prefix . $this->current_section . '_' . $this->file_count . '.css', $this->current_file);
        }

        $this->file_count++;
        $this->current_file = '';
    }

    public function minifyCssOld($css)
    {
        if (!empty($this->settings['css_minify']) && $this->settings['css_minify'] == '1') {
            //      // Remove line breaks and multiple spaces
            //      $css = preg_replace('/\s+/', ' ', $css);
            //
            //      // Remove spaces before and after braces
            //      $css = str_replace(array('{ ', ' }'), array('{', '}'), $css);
            //
            //      // Remove spaces before and after colons
            //      $css = str_replace(': ', ':', $css);
            $css = preg_replace('/\/\*(.*?)\*\//s', '', $css); // Remove comments
            $css = preg_replace('/\s+/', ' ', $css); // Remove multiple whitespaces
            $css = preg_replace('/\s?([,:;{}])\s?/', '$1', $css); // Remove spaces around selectors and declarations
            $css = preg_replace('/;}/', '}', $css); // Remove trailing semicolons before closing brace
        } else {
            // Remove line breaks and multiple spaces
            $css = preg_replace('/\s+/', ' ', $css);
        }
        return $css;
    }

    public function combine($html)
    {
        $html = $html[0];
        #return print_r($html[0], true);
        $html = preg_replace_callback($this->patterns, [$this, 'script_combine_and_replace'], $html);
        return $html;
    }

    public function script_combine_and_replace($tag)
    {
				if ($this->log_criticalCombine){
					$this->logger->log('Starting new script.');
				}

        $tag = trim($tag[0]);
        if (empty($tag)) {
            return $tag;
        }
        $src = '';
	      $media_query = null;

        if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'before') {
            return print_r([$tag], true);
        }

        // Check if the CSS is Excluded
        if (self::$excludes->strInArray($tag, $this->allExcludes)) {
            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'outputs') {
                return print_r([$tag, 'excluded'], true);
            }
		        if ($this->log_criticalCombine){
			        $this->logger->log('It is excluded.', true);
		        }
            return $tag;
        }

        // If it has ie9 tag exclude by default
        if (strpos($tag, 'ie9') !== false) {
            return $tag;
        }

				// Extract media query if present
		    if (preg_match('/media=["\']([^"\']+)["\']/', $tag, $media_match)) {
			      $media_query = $media_match[1];
			      if ($this->log_criticalCombine){
				      $this->logger->log('Media query found: ' . $media_query);
			      }
		    }

        if (strpos($tag, '<link') !== false) {
            $is_src_set = preg_match('/href=["|\'](.*?)["|\']/', $tag, $src);
        } elseif (strpos($tag, '<style') !== false) {
            $is_src_set = preg_match('/<style\b[^>]*\bhref=["\'](.*?)["\'][^>]*>/i', $tag, $src);
        }

        if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'preg') {
            return print_r([$tag], true);
        }

        if ($is_src_set == 1) {
            $src = str_replace('href=', '', $src);
            $src = str_replace("'", "", $src);
            $src = str_replace('"', "", $src);
            $src = $src[0];

		        if ($this->log_criticalCombine){
			        $this->logger->log('Src: ' . $src);
		        }

            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'pre-output') {
                return print_r([$tag, 'file', $this->combine_external, $src], true);
            }

            if (!$this->combine_external && $this->url_key_class->is_external($src)) {
                if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'outputs') {
                    return print_r([$tag, 'external'], true);
                }
		            if ($this->log_criticalCombine){
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
                if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'outputs') {
                    return print_r([$tag, 'no content', 'external' => $this->combine_external, 'is_external' => $this->url_key_class->is_external($src)], true);
                }
                return $tag;
            }

            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'getLocalContent') {
                return print_r(['no-content', $content], true);
            }

            //replace relative urls
            $this->asset_url = $src;
            $content = preg_replace_callback("/url(\(((?:[^()])+)\))/i", [$this, 'rewrite_relative_url'], $content);
        } else if ($this->combine_inline_scripts) {
            $src = 'Inline Script';

		        if ($this->log_criticalCombine){
			        $this->logger->log('Is inline.');
		        }

            $content = $tag;
            $content = preg_replace('/<style(.*?)>/', '', $content, -1, $count);
            $content = preg_replace('/<\/style>/', '', $content);

            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'pre-output') {
                return print_r([$tag, 'inline', $this->combine_inline_scripts, $content], true);
            }

            if (!$count) {
                //no href, and not a <style> tag
                if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'outputs') {
                    return print_r([$tag, 'not a style tag'], true);
                }
                return $tag;
            }
        } else {
            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'outputs') {
                return print_r([$tag, 'unknown'], true);
            }
            return $tag;
        }

		    if ($this->log_criticalCombine){
			    $this->logger->log('Fetched.');
		    }


        //sometimes php injects a zero width space char at the start of a new script, this clears it
        $content = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $content);
        $content = str_replace(['@font-face{', '@font-face {'], '@font-face{font-display: swap;', $content);

        // Find BG and replace with mobile BG
        #if ($this::$isMobile) {
        #$content = preg_replace_callback("/background-image:\s*url\((.*?)\)/is", array($this, 'changeBgImageToMobile'), $content);
        #}

        $content = preg_replace_callback('/src:\s*url\("([^"]+\.woff2)"\)\s*format\(\s*\'woff2\'\s*\);/is', [$this, 'changeFontToCDN'], $content);

        $this->current_file .= "/* SCRIPT : $src */" . PHP_EOL;
		    // Wrap content in media query if it exists
		    if ($media_query) {
				    $this->current_file .= "@media " . $media_query . " {" . PHP_EOL;
				    $this->current_file .= $content . PHP_EOL;
				    $this->current_file .= "}" . PHP_EOL;
		    } else {
			      $this->current_file .= $content . PHP_EOL;
		    }

        #if (mb_strlen($this->current_file, '8bit') >= $this->filesize_cap) {
        $this->write_file_and_next();
        #}

        if (!$this->firstFoundStyle) {
            $this->firstFoundStyle = true;
            return '<!--WPC_INSERT_COMBINED_CSS-->';
        } else {
            return '';
        }
    }

    public function getRemoteContent($url)
    {
		    if ($this->log_criticalCombine){
			    $this->logger->log('Fetching script content.');
		    }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

		    $args = array(
			    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
			    'headers' => array(
				    'Accept' => 'text/css,*/*;q=0.1',
				    'Accept-Language' => 'en-US,en;q=0.9',
			    )
		    );


	      $data = wp_remote_get($url, $args);

        //todo Check if file is really css

        if (is_wp_error($data)) {

		        if ($this->log_criticalCombine){
			        $this->logger->log('Failed fetching script content.' , true);
		        }

            return false;
        }

		    if ($this->log_criticalCombine){
			    $this->logger->log('Script content fetched.');
		    }

        return wp_remote_retrieve_body($data);
    }

    public function getLocalContent($url)
    {
        $output = [];

	    if ($this->log_criticalCombine){
		    $this->logger->log('Fetching script content.');
	    }

        if ($this->hmwpReplace) {
            //go trougn their replacements and reverse them to get true path to files
            foreach ($this->hmwp_rewrite->_replace['to'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['from'][$key];
                $url = str_replace($value, $replace, $url);
            }
	        if ($this->log_criticalCombine){
		        $this->logger->log('Did hidemywp replacements and got ' . $url);
	        }
        }

        if (!empty($this->zone_name) && strpos($url, $this->zone_name) !== false) {
            preg_match('/a:(.*?)(\?|$)/', $url, $match);
            $url = trim($match[1]);
        }

        if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'getLocalContent') {
            $output['abs_path'] = ABSPATH;
            $output['first_url'] = $url;
        }

        // url is: example https://site.com/wp-content/plugins/jeg-elementor-kit/assets/css/elements/main.css

        $url = preg_replace('/\?.*/', '', $url);

        if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'getLocalContent') {
            $output['preg_replace_url'] = $url;
        }

        $path = wp_make_link_relative($url);
        $path = ltrim($path, '/');

        if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'getLocalContent') {
            $output['relative'] = $path;
        }

        // Upload Dir Path
        $uploadDir = wp_upload_dir();
        $uploadDir = $uploadDir['basedir'];

        // Includes Path
        $includesPath = ABSPATH . WPINC;

        // Theme Dir Path (Without Active Theme)
        $themePath = get_theme_root();

        // $path relative is example: wp-content/plugins/jeg-elementor-kit/assets/css/elements/main.css
        if (strpos($path, 'wp-content/plugins/') !== false) {
            // Plugins DIR: WP_PLUGIN_DIR
            $pathExploded = explode('wp-content/plugins/', $path);
            $justPath = $pathExploded[1];
            $finalPath = WP_PLUGIN_DIR . '/' . $justPath;
        } else if (strpos($path, 'wp-includes/') !== false) {
            // Uploads DIR: wp_upload_dir()
            $pathExploded = explode('wp-includes/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $includesPath . '/' . $justPath;
        } else if (strpos($path, 'wp-content/uploads/') !== false) {
            // Uploads DIR: wp_upload_dir()
            $pathExploded = explode('wp-content/uploads/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $uploadDir . '/' . $justPath;
        } else if (strpos($path, 'wp-content/themes/') !== false) {
            // Themes Dir: TEMPLATEPATH
            $pathExploded = explode('wp-content/themes/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $themePath . '/' . $justPath;
        } else {
	          $finalPath = ABSPATH . $path;
        }

        if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'getLocalContent') {
            $output['relative_abs'] = $path;
            $output['file_get_content_path'] = ABSPATH . $path;
            $output['plugin_dir'] = WP_PLUGIN_DIR;
            $output['theme_dir'] = TEMPLATEPATH;
            $output['upload_dir'] = $uploadDir;
            $output['justPath'] = $justPath;
            $output['finalPath'] = $finalPath;
            $output['file_exists'] = file_exists($finalPath);
            #$output['read'] = file_get_contents($justPath);


            return $output;
        }

		    if ($this->log_criticalCombine){
			    $this->logger->log('Fetching script content.' . $finalPath);
		    }

        if (file_exists($finalPath)) {
            $content = file_get_contents($finalPath);
        }

        if (!$content) {

		        if ($this->log_criticalCombine){
			        $this->logger->log('Fetch failed,', true);
		        }

            return false;
        }

		    if ($this->log_criticalCombine){
			    $this->logger->log('Fetched.');
		    }

        return $content;
    }


    public function changeFontToCDN($html)
    {
        if (!empty($this->settings['font-subsetting']) && $this->settings['font-subsetting'] == '1') {
            if (strpos($html[1], 'icon') === false && strpos($html[1], 'awesome') === false && strpos($html[1], 'lightgallery') === false && strpos($html[1], 'gallery') === false && strpos($html[1], 'side-cart-woocommerce') === false) {
                return 'src:url("https://' . $this->zone_name . '/font:true/a:' . $html[1] . '");';
            }
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

        #return print_r(array($bgEntire, $bgUrl, $MobileBg, $html),true);
        return $html;
    }

    public function remove_scripts($tag)
    {
        $tag = $tag[0];
        $src = '';

        if (strpos('rs6', $tag) !== false) {
            return $tag;
        }

        if (!$this->combine_external && $this->url_key_class->is_external($tag)) {
            return $tag;
        }

        if (current_user_can('manage_options') || self::$excludes->strInArray($tag, $this->allExcludes)) {
            return $tag;
        }


        $is_src_set = preg_match('/href=["|\'](.*?)["|\']/', $tag, $src);
        if ($is_src_set == 1) {
            //nothing
        } else if ($this->combine_inline_scripts) {
            $src = 'Inline Script';

            $content = $tag;
            $content = preg_replace('/<style(.*?)>/', '', $content, -1, $count);

            if (!$count) {
                //no href, and not a <style> tag
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

    public function rewrite_relative_url($url)
    {

        $matched_url = $url[2];
        $asset_url = $this->asset_url;
        $matched_url = str_replace('"', '', $matched_url);
        $matched_url = str_replace("'", '', $matched_url);

        $parsed_url = parse_url($asset_url);
        $path = $parsed_url['path'];
        $path = str_replace(basename($path), '', $path);
        $path = ltrim($path, '/');
        $path = rtrim($path, '/');
        $directories = explode('/', $path);

        $host = $parsed_url['host'];
        $scheme = $parsed_url['scheme'];
        $parsed_homeurl = parse_url(get_home_url());

        if (!$host) {
            //relative asset url
            $host = $parsed_homeurl['host'];
        }

        if (!$scheme) {
            //relative asset url
            $scheme = $parsed_homeurl['scheme'];
        }

        if (strpos($matched_url, $this->zone_name) !== false || strpos($matched_url, 'zapwp.net') !== false) {
            return $url[0];
        }

        if (strpos($matched_url, 'google') !== false || strpos($matched_url, 'gstatic') !== false || strpos($matched_url, 'typekit') !== false) {
            return $url[0];
        }

        if (strpos($matched_url, 'data:') !== false) {
            return $url[0];
        }

        $first_char = substr($matched_url, 0, 1);
        if (strpos($matched_url, 'http') === false && ctype_alpha($first_char)) {
            // No,slash.. direct file
            // Same folder
            $relativePath = implode('/', $directories) . '/';
            $matched_url_trim = ltrim($matched_url, './');
            $relativePath .= $matched_url_trim;
            $relativeUrl = $scheme . '://' . $host . '/' . $relativePath;

        } else if (strpos($matched_url, '/') === 0 && strpos($matched_url, '//') !== 0) {
            // Root folder
            $relativePath = '';
            $matched_url_trim = ltrim($matched_url, './');
            $relativePath .= $matched_url_trim;
            $relativeUrl = $scheme . '://' . $host . '/' . $relativePath;

        } else if (strpos($matched_url, './') === 0) {
            // Same folder
            $relativePath = implode('/', $directories) . '/';
            $matched_url_trim = ltrim($matched_url, '.');
            $matched_url_trim = ltrim($matched_url_trim, '.');
            $relativePath .= $matched_url_trim;
            $relativeUrl = $scheme . '://' . $host . '/' . $relativePath;

        } else if (strpos($matched_url, '../') === 0) {
            // Are there more directories to go back?
            $exploded_dirs = explode('../', $matched_url);
            array_pop($exploded_dirs);

            foreach ($exploded_dirs as $i => $v) {
                // Back Folder
                array_pop($directories); // Remove 1 last dir
            }
            $relativePath = implode('/', $directories) . '/';
	          $matched_url_trim = preg_replace('/^(\.\.\/)+/', '', $matched_url);
            $relativePath .= $matched_url_trim;

            $relativeUrl = $scheme . '://' . $host . '/' . $relativePath;

        } else {

            // Regular path
            if (strpos($matched_url, 'http://') !== false || strpos($matched_url, 'https://') !== false) {
                // Regular URL
                $replace_url = $matched_url;
            } else {
                // Missing http/s ?
                $replace_url = ltrim($matched_url, '/');
                $matched_url = ltrim($matched_url, '/');
                $replace_url = $scheme . '://' . $replace_url;
            }

            if (strpos($matched_url, '.jpg') !== false || strpos($matched_url, '.png') !== false || strpos($matched_url, '.gif') !== false || strpos($matched_url, '.svg') !== false || strpos($matched_url, '.jpeg') !== false || strpos($matched_url, '.webp') !== false) {
                // Image, put on CDN
                $relativeUrl = $replace_url;
            } else if (strpos($matched_url, '.woff') !== false || strpos($matched_url, '.woff2') !== false || strpos($matched_url, '.ttf') !== false || strpos($matched_url, '.eot') !== false) {

                // Font file, put on site
                $relativeUrl = $replace_url;
            }
        }

        $relativeUrl = trim($relativeUrl);

        if ((strpos($matched_url, '.eot') !== false || strpos($matched_url, '.woff') !== false || strpos($matched_url, '.woff2') !== false || strpos($matched_url, '.ttf') !== false) && $this->settings['serve']['fonts'] == 1) {
            if (!empty($this->settings['font-subsetting']) && $this->settings['font-subsetting'] == '1') {
                if (strpos($matched_url, 'icon') !== false || strpos($matched_url, 'awesome') !== false || strpos($matched_url, 'lightgallery') !== false || strpos($matched_url, 'gallery') !== false || strpos($matched_url, 'side-cart-woocommerce') !== false) {
                    $relativeUrl = 'url("https://' . $this->zone_name . '/m:0/a:' . $relativeUrl . '")';
                } else {
                    $relativeUrl = 'url("https://' . $this->zone_name . '/font:true/a:' . $relativeUrl . '")';
                }
            } else {
                $relativeUrl = 'url("https://' . $this->zone_name . '/m:0/a:' . $relativeUrl . '")';
            }
        } else if ((strpos($matched_url, '.jpg') !== false && $this->settings['serve']['jpg'] == 1) || (strpos($matched_url, '.png') !== false && $this->settings['serve']['png'] == 1) || (strpos($matched_url, '.gif') !== false && $this->settings['serve']['gif'] == 1) || (strpos($matched_url, '.svg') !== false && $this->settings['serve']['svg'] == 1)) {

            if ($this::$isMobile) {
                $relativeUrl = 'url("https://' . $this->zone_name . '/m:0/a:' . $relativeUrl . '")';
            } else {
                $relativeUrl = 'url("https://' . $this->zone_name . '/m:0/a:' . $relativeUrl . '")';
            }

        } else {
            $relativeUrl = 'url("' . $relativeUrl . '")';
        }

        return $relativeUrl;
    }
}