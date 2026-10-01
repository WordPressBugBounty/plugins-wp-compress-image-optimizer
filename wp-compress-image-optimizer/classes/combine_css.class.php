<?php

include_once WPS_IC_DIR . 'addons/cdn/cdn-rewrite.php';
include_once WPS_IC_DIR . 'traits/url_key.php';

/**
 * CSS helpers the render shares: the font-awesome park (lazyFontawesome), the inline @font-face
 * rewrite (rewriteInlineFontFaces), the homepage preload set (preparePreloads) and minifyCSS.
 * The visitor combine lane that gave the class its name is gone; the crit generator's corpus is
 * built by wps_ic_crit_corpus.
 */
class wps_ic_combine_css
{

    public static $rewrite;
    public static $site_url;
    public $zone_name;
    public $cssPath;
    public $settings;

    public function __construct()
    {
        $this->cssPath = '';

        self::$rewrite = new wps_cdn_rewrite();
        self::$site_url = site_url();
        $this->settings = get_option(WPS_IC_SETTINGS);

        $cf = get_option(WPS_IC_CF);
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok())) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname) && function_exists('wpc_cdn_cname_is_reachable') && !wpc_cdn_cname_is_reachable($custom_cname)) { $custom_cname = ''; }
        if (empty($custom_cname) || !$custom_cname) {
            $this->zone_name = get_option('ic_cdn_zone_name');
        } else {
            $this->zone_name = $custom_cname;
        }
    }

    public function pathWalker($path, $find)
    {
        $paths = explode('/', $path);
        $foldersUp = substr_count($find, '../');

        $array = array_splice($paths, 0, -$foldersUp);
        $array = implode('/', $array);

        return $array;
    }



    public function preparePreloads($html, $imagePreloads = null)
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

                // Href is local
                $cleanHref = explode('?', trim($href));
                $cleanHref = trim($cleanHref[0]);

                if (strpos($cleanHref, self::$site_url) !== false) {
                    // Dead work removed: this branch read EVERY local sheet (339KB used.css
                    // included) and ran recursive url() + background regexes per render —
                    // 20-62s CPU — while both preload outputs were commented out. Nothing used it.
                    continue;
                }
                if (false) {
                    $path = str_replace([self::$site_url, $this->zone_name, 'https:///m:0/a:', 'https://' . $this->zone_name . '/m:0/a:','https:///m:1/a:', 'https://' . $this->zone_name . '/m:1/a:'], '', $cleanHref);
                    $path = urldecode(ltrim($path, '/'));

                    // Skip if CDN URL patterns leaked through the str_replace
                    if (preg_match('#^https?://#i', $path)) {
                        continue;
                    }

                    $relativePath = ABSPATH . $path;

                    if (!file_exists($relativePath)) {
                        continue;
                    }

                    $content = @file_get_contents($relativePath);

                    if (!empty($content)) {
                        // Get the filename
                        $cssFilename = basename($href);
                        $cssUrlPath = str_replace($cssFilename, '', $href);

                        // Remove the site URL from the Path to retrieve just the path
                        $cssPath = str_replace([self::$site_url . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/', 'https://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
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
                        $wpcPreloads .= $preload;
                    }
                }
            }
        }
        if ($this->is_home_url()) {
            if (!self::$rewrite->is_mobile()) {
                $wpcPreloadsGenerator = self::$rewrite->preload_custom_assets('string', $html, $imagePreloads);
            } else {
                $wpcPreloadsGenerator = self::$rewrite->preload_custom_assetsMobile('string', $html, $imagePreloads);
            }
        }

        return $wpcPreloadsGenerator . $wpcPreloads;
    }


    public function fixUrlPaths($css)
    {
        $css = preg_replace_callback('/url\(([^)]*)\)/i', [$this, 'fixPathsWalker'], $css);

        // Fix URLs inside @import statements
        $css = preg_replace_callback('/@import\s+["\']([^"\']+)["\'];?/i', [$this, 'fixImportPaths'], $css);

        return $css;
    }

    public function findBackgrounds($css)
    {
        $pattern = '/(?:background(?:-image)?\s*:\s*url\s*\(\s*([\'"]?)([^)\'"]*)\1\s*\))/i';

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

    public function isHtml($string)
    {
        return preg_match("/<[^<]+>/", $string) === 1;
    }

    public function fixControlCharacter($css)
    {
        $css = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $css);
        return $css;
    }

    public function removeCommentsFromCSS($css)
    {
        // Use a regular expression to remove comments (/* ... */)
        $cssWithoutComments = preg_replace('#/\*.*?\*/#s', '', $css);
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


    public function rewriteInlineFontFaces($html)
    {
        if (strpos($html, '@font-face') === false) return $html;


        $wpc_inline_cdn = (class_exists('wps_cdn_rewrite')
            && apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))
            && !empty($this->settings['fonts']) && $this->settings['fonts'] == '1'
            && !empty($this->zone_name)
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
        $preloadCritFonts = isset($settings['preload-crit-fonts']) ? (string) $settings['preload-crit-fonts'] : '';
        if ($preloadCritFonts === '' && isset($settings['replace-fonts']) && $settings['replace-fonts'] === 'local'
            && apply_filters('wpc_atf_faces_auto', true)) {
            $preloadCritFonts = '1';
        }
        if ($preloadCritFonts !== '1') {
            return [];
        }
        if (strpos($html, '@font-face') === false) return [];

        $found = [];
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styleMatches)) {
            foreach ($styleMatches[1] as $css) {
                if (strpos($css, '@font-face') === false) continue;
                if (preg_match_all('/@font-face\s*\{([^}]+)\}/is', $css, $faceMatches)) {
                    foreach ($faceMatches[1] as $faceBody) {
                        // Skip icon fonts (the shared test, which knows Divi's ETmodules)
                        if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $faceBody, $famM)) {
                            if (wpc_css_is_icon_font($famM[1])) {
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
        // v7.10.689 — post-paint injected; a static as=font tag render-holds Chrome 150.
        $links = [];
        if ($found && function_exists('wpc_font_preload_postpaint_tag')) {
            $postpaintTag = wpc_font_preload_postpaint_tag(array_map('esc_url', $found));
            if ($postpaintTag !== '') {
                $links[] = $postpaintTag;
            }
        }
        return (array) apply_filters('wpc_font_preload_links', $links, $html);
    }

    public function findFontFace($css)
    {
        // Read settings once outside the callback to avoid repeated DB queries
        $settings = get_option(WPS_IC_SETTINGS);
        $textFontDisplay = !empty($settings['font-display']) ? $settings['font-display'] : 'smart';
        // v7.10.485 — keep the RAW so each face can resolve for ITS OWN family. Resolving once
        // here applied one family's 'optional' to every face; this is the emitter that actually
        // produced the live Astra optional (quoted url form, inside astra-theme-css-inline-css).
        $textFontDisplayRaw = $textFontDisplay;
        if (function_exists('wpc_font_display_effective')) {
            $textFontDisplay = wpc_font_display_effective($textFontDisplay);
        }
        $iconFontDisplay = !empty($settings['icon-font-display']) ? $settings['icon-font-display'] : 'block';

        return preg_replace_callback('/@font-face\s*{[^}]+}/sim', function ($fontface) use ($textFontDisplay, $textFontDisplayRaw, $iconFontDisplay) {
            // v7.21.134 — 'off' means DON'T TOUCH: this writer emitted the literal
            // "font-display:off", which is not a CSS value — browsers discarded the whole
            // descriptor, so Off silently behaved like auto (optica-suiza report). Off now
            // leaves the face exactly as the theme authored it.
            if ($textFontDisplayRaw === 'off' || $textFontDisplay === 'off') { return $fontface[0]; }
            $fontFamily = $fontStyle = $fontWeight = $woffUrl = '';
            $urlFound = false;

            // Try to match .woff or .woff2 URL
            if (preg_match('/url\((["\']?)([^)]+\.(woff2?))\1\)/si', $fontface[0], $matchesWoffUrl)) {
                $woffUrl = $matchesWoffUrl[2];
                $urlFound = true;
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

            if ($urlFound) {
                $format = strpos($woffUrl, '.woff2') !== false ? 'woff2' : 'woff';

                // An icon font takes the icon setting (block by default), never the text policy.
                // The icon test is the shared one: this copy's own list had no 'etmodules', so
                // Divi's icon face in divi-dynamic-critical-inline-css resolved as text, and once
                // the service's font metrics carried an ETmodules row the text policy said
                // optional. With optional, a font that arrives after the ~100 ms grace is never
                // used, and the mobile menu button painted as the letter "a" in the Times New
                // Roman fallback for the life of the page (webdesign4u.com.au /contact-us/,
                // ticket 12053, 2026-09-28).
                $fontDisplayValue = $textFontDisplay;
                $familyRaw = isset($matchesFontFamily[1]) ? strtolower(trim($matchesFontFamily[1])) : '';
                if (wpc_css_is_icon_font($familyRaw)) {
                    $fontDisplayValue = $iconFontDisplay;
                } elseif ($familyRaw !== '' && function_exists('wpc_font_display_effective')) {
                    // .485 PER-FAMILY: 'optional' is only safe for a family with a metric-matched
                    // fallback. Astra has none and is fetched over the network, so optional there
                    // means the glyph may never paint.
                    $familyDisplay = wpc_font_display_effective($textFontDisplayRaw, trim($familyRaw, " \t\"'"));
                    if (in_array($familyDisplay, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                        $fontDisplayValue = $familyDisplay;
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
                    // Same sheet is often enqueued twice (e.g. uael + hfe handles) — one preload per href.
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
                        return 'url("' . $this->cssPath . '/' . $removeRelative . '")';
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
}