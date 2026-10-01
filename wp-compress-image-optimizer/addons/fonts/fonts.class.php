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
    /** @var string $filename */
    private $filename;
    /** @var string $path */
    private $path;
    private $response;
    private $foundFonts;
    private $mimeMap = ['font/woff2' => 'woff2', 'application/font-woff2' => 'woff2', 'font/woff' => 'woff', 'application/font-woff' => 'woff', 'font/ttf' => 'ttf', 'application/x-font-ttf' => 'ttf', 'font/sfnt' => 'ttf', // Can be WOFF2 or TTF, but we pick TTF.
        'application/font-sfnt' => 'ttf', 'font/otf' => 'otf', 'application/x-font-opentype' => 'otf', 'application/vnd.ms-fontobject' => 'eot',];


    public function __construct()
    {
        $this->path = WPS_IC_FONTS_DIR;
        $this->stylesheetMap = get_option(WPS_IC_FONTS_MAP);
    }


    public function isActive() {
        $options = get_option(WPS_IC_SETTINGS);
        if (!empty($options) && !empty($options['replace-fonts'])) {
            // Active, check if local or bunny
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


    /** $fontFaces is the render's @font-face owner ($ctx->fontFaces), handed down by
     *  stage_fonts_replace_frontend: the localized sheets' faces are registered with it. */
    public function replaceFrontend($html, wps_ic_font_face_set $fontFaces)
    {
        if (!empty($this->stylesheetMap)) {

            foreach ($this->stylesheetMap as $style => $replaceData) {
                if (empty($replaceData) || empty($replaceData['filename'])) {
                    continue;
                }

                // v7.21.133 — gfonts/bunny provider URLs are OWNED by the coverage-gated swap
                // below (.127/.133): this blind str_replace predates it and swapped a provider
                // URL to a local file that may not declare every pipe-delimited family. Provider
                // links only ever swap through the file-census gate; every other mapped
                // stylesheet keeps this fast path.
                if (stripos((string) $style, 'fonts.googleapis.com/css') !== false
                    || stripos((string) $style, 'fonts.bunny.net/css') !== false) {
                    continue;
                }

                if (!file_exists(WPS_IC_FONTS_DIR . $replaceData['dir'] . '/' . $replaceData['filename'])) {
                    continue;
                }

                $replaceUrl = WPS_IC_FONTS_URL . $replaceData['dir'] . '/' . $replaceData['filename'];
                $styleEncoded = str_replace('&', '&#038;', $style);

                // Try to find encoded and non encoded url
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
            $familiesInFontsUrl = function ($u) {
                $u = html_entity_decode((string) $u, ENT_QUOTES);
                if (!preg_match_all('/family=([^&]+)/i', $u, $familyParams)) { return []; }
                $out = [];
                foreach ($familyParams[1] as $familyParam) {
                    // v7.21.127 - v1 API packs families pipe-delimited INSIDE one param
                    // (family=Mukta:300,700|Noto+Sans:700,regular). Splitting on ':' first took
                    // only the first family and silently dropped every family after the pipe:
                    // a Mukta-only localized file then passed the covers-all-families test and
                    // the swap DELETED Noto Sans' faces (albertadiving serif headlines - a bare
                    // single-family stack falls to the UA serif). Pipes split first; the colon
                    // split is per-segment.
                    foreach (explode('|', urldecode($familyParam)) as $familySegment) {
                        $n = strtolower(trim(str_replace('+', ' ', explode(':', $familySegment)[0])));
                        if ($n !== '') { $out[$n] = 1; }
                    }
                }
                return $out;
            };
            $localizedSheets = [];
            foreach ((array) $this->stylesheetMap as $sheetData) {
                if (empty($sheetData['filename']) || empty($sheetData['dir'])) { continue; }
                $localSheetPath = WPS_IC_FONTS_DIR . $sheetData['dir'] . '/' . $sheetData['filename'];
                if (!file_exists($localSheetPath)) { continue; }
                // v7.21.133 — VERIFY THE FILE, NOT THE URL (.132's law applied to the
                // localizer): .127 proved coverage against families the URL *asked for*;
                // the swap's real guarantee is what the localized FILE *declares*. A file
                // missing a pipe-delimited family (partial download, stale mint) must
                // never win the swap — the live provider link stays.
                $declaredFamilies = [];
                $sheetCss = (string) @file_get_contents($localSheetPath, false, null, 0, 262144);
                if ($sheetCss !== ''
                    && preg_match_all('/@font-face\s*\{[^{}]*?font-family\s*:\s*["\']?([^"\';}]+)/is', $sheetCss, $familyMatches)) {
                    foreach ($familyMatches[1] as $familyName) {
                        $familyName = strtolower(trim($familyName));
                        if ($familyName !== '') { $declaredFamilies[$familyName] = 1; }
                    }
                }
                if (!empty($declaredFamilies)) {
                    $localizedSheets[] = ['fams' => $declaredFamilies, 'url' => self::versionedFontsCssUrl($sheetData['dir'] . '/' . $sheetData['filename'])];
                }
            }
            if (!empty($localizedSheets)) {
                $html = preg_replace_callback('/<link\b[^>]*href=["\']((?:https?:)?\/\/fonts\.(?:googleapis\.com|bunny\.net)\/css[^"\']*)["\'][^>]*>/i', function ($linkMatch) use ($familiesInFontsUrl, $localizedSheets) {
                    $lf = $familiesInFontsUrl($linkMatch[1]);
                    if (empty($lf)) { return $linkMatch[0]; }
                    foreach ($localizedSheets as $localEntry) {
                        if (!array_diff_key($lf, $localEntry['fams'])) {
                            return str_replace($linkMatch[1], $localEntry['url'], $linkMatch[0]);
                        }
                    }
                    return $linkMatch[0];
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
            $html = wpc_inject_before_body_close($html, $wpc_rt_js . "\n");
        }


        $html = $this->replaceInlineFonts($html);


        $html = $this->wpc_inline_fonts_css($html, $fontFaces);


        $html = $this->wpc_fontsheet_external_sweep($html);


        if (function_exists('wpc_perf_debug_is_allowed') && wpc_perf_debug_is_allowed()) {
            $wpc_fmap = get_option(self::INLINE_MAP);
            $wpc_flat = $this->findInlineGstaticFaces($html);
            $html .= "\r\n<!-- WPC-FONT-DEBUG replace_fonts=" . $this->isActive()
                . " | gstatic_on_page=" . substr_count($html, 'fonts.gstatic.com')
                . " | latin_inline_faces=" . count($wpc_flat)
                . " | mapped=" . (is_array($wpc_fmap) ? count($wpc_fmap) : 0)
                . " | throttle=" . (get_transient('wpc_font_inline_lock') ? 'LOCKED' : 'clear')
                . " | fpm=" . ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) ? 'yes' : 'NO(cron-only)')
                . " -->";
        }

        return $html;
    }


    public function wpc_inline_fonts_css($html, wps_ic_font_face_set $fontFaces)
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

            $inlineMaxBytes = (int) apply_filters('wpc_fonts_css_inline_max', 24576);


            $critTagPresent = (strpos($html, 'id="wpc-critical-css"') !== false);
            $deferredFontSheetHref = '';
            $inlinedHtml = preg_replace_callback(
                '~<link\b[^>]*href=["\'](' . preg_quote((string) WPS_IC_FONTS_URL, '~') . '[^"\']+?\.css)(?:\?[^"\']*)?["\'][^>]*>~i',
                function ($m) use ($inlineMaxBytes, $critTagPresent, &$deferredFontSheetHref, $html, $fontFaces) {
                    if (stripos($m[0], 'wpc-late-stylesheet') !== false) { return $m[0]; } // FA-late stays late
                    if (preg_match('/media\s*=\s*["\']?print/i', $m[0])) { return $m[0]; }
                    if (stripos($m[0], 'as=') !== false && stripos($m[0], 'preload') !== false) { return $m[0]; }
                    $p = str_replace((string) WPS_IC_FONTS_URL, (string) WPS_IC_FONTS_DIR, $m[1]);
                    $css = @file_get_contents($p);
                    if (is_string($css) && $css !== '' && strlen($css) <= $inlineMaxBytes
                        && stripos($css, '</style') === false && stripos($css, '<script') === false) {
                        // v7.10.529 — ATF-SCOPE THE INLINE FACES. Measured on the flagship: this
                        // block was 6,232 b of @font-face for Roboto across 9 unicode-range
                        // subsets, render-blocking in the head — while the ATF glyph set says the
                        // fold uses only Circular Std (already inlined as base64). Worse, it put a
                        // Roboto woff2 on the FCP critical path at 1,454 ms for text that is not
                        // above the fold. Inline only the families the fold actually needs; the
                        // rest still load, just deferred (and since .528, after LCP has painted).
                        $atfFaceSplit = self::wpc_scope_faces_to_atf_glyphs($css, $html);
                        if ($atfFaceSplit !== null) {
                            // v7.10.577 — DO NOT INLINE A SHEET WE HAVE ALREADY DECIDED TO DEFER.
                            // When the ATF part is empty, every face here is below the fold, and
                            // inlining buys nothing: the point of inlining is to remove a request
                            // from the critical path, and a deferred sheet is not on it. Worse, an
                            // inline <style> makes the DOCUMENT the initiator of every font it
                            // references, which is why the deferred Roboto woff2 kept appearing in
                            // Lighthouse's critical chain hanging directly off the navigation.
                            // Leaving it as a deferred <link> moves the initiator onto that sheet
                            // and takes 6,232 b (~1.5 KiB gzip) out of the document at the same
                            // time. Falls through to the normal inline path whenever any face IS
                            // fold-critical, so nothing above the fold ever loses its inline copy.
                            if (trim((string) $atfFaceSplit['atf']) === ''
                                && apply_filters('wpc_defer_link_over_inline', true)) {
                                // v7.10.587 — the gfonts converter (rewriteLogic) already stamps
                                // rel="wpc-mobile-stylesheet" before this filter runs, so testing
                                // only for rel="stylesheet" missed every converted sheet and fell
                                // through to inlining one that was ALREADY deferred. Inlining then
                                // makes the DOCUMENT the initiator of every woff2 it references,
                                // which is what put the deferred Roboto on Lighthouse's critical
                                // chain hanging off the navigation. Already deferred => leave it.
                                // v7.10.589 — the sheet may be deferred, its @font-face may not.
                                // atf is empty here, so rest holds every face in the sheet; emitting
                                // them live keeps the declaration present at first paint while the
                                // sheet's own rules stay deferred. Duplicated when the loader later
                                // flips the link on, which is idempotent for identical declarations.
                                self::registerLiveFaces($atfFaceSplit['rest'], wps_ic_font_face_set::ORIGIN_DISPLAY_INJECTED, $fontFaces);
                                if (preg_match('/(?<![\w.$])(?:rel|type)\s*=\s*(["\'])wpc-(?:mobile-)?stylesheet\1/i', $m[0])) {
                                    $deferredFontSheetHref = $m[1];
                                    return $m[0];
                                }
                                if (preg_match('/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i', $m[0])) {
                                    $deferredFontSheetHref = $m[1];
                                    return (string) preg_replace(
                                        '/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i',
                                        'rel="wpc-mobile-stylesheet"',
                                        $m[0],
                                        1
                                    );
                                }
                            }
                            self::registerLiveFaces($atfFaceSplit['rest'], wps_ic_font_face_set::ORIGIN_DISPLAY_INJECTED, $fontFaces);

                            // The fold-critical half keeps whatever is not a face; its faces go
                            // to the owner like every other lane's.
                            return wps_rewriteLogic::harvestFontFaces(
                                '<style id="wpc-fonts-css">' . $atfFaceSplit['atf'] . '</style>', 'fonts-css-atf', $fontFaces);
                        }
                        return wps_rewriteLogic::harvestFontFaces('<style id="wpc-fonts-css">' . $css . '</style>', 'fonts-css', $fontFaces);
                    }

                    // Attribute position only — never the copy inside an onload handler.
                    if ($critTagPresent && preg_match('/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i', $m[0])) {
                        $deferredFontSheetHref = $m[1];
                        return (string) preg_replace('/(?<![\w.$])rel\s*=\s*(["\'])stylesheet\1/i', 'rel="wpc-mobile-stylesheet"', $m[0], 1);
                    }
                    return $m[0];
                },
                $html, 2);
            if (is_string($inlinedHtml)) {
                $html = $inlinedHtml;
            }


            if ($deferredFontSheetHref !== '' || strpos($html, 'id="wpc-fonts-css"') !== false) {
                $preloadsDroppedHtml = preg_replace_callback(
                    '~<link\b[^>]*rel=["\']preload["\'][^>]*href=["\']' . preg_quote((string) WPS_IC_FONTS_URL, '~') . '[^"\']*\.css[^"\']*["\'][^>]*>\s*~i',
                    function ($pm) { return (stripos($pm[0], 'as="style"') !== false || stripos($pm[0], "as='style'") !== false) ? '' : $pm[0]; },
                    $html, 2);
                if (is_string($preloadsDroppedHtml)) {
                    $html = $preloadsDroppedHtml;
                }
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }


    const INLINE_DIR = 'inline';
    const INLINE_MAP = 'wps_ic_fonts_inline_map';


    /**
     * v7.10.918 — LOCALIZE FONTS DECLARED IN EXTERNAL SHEETS (ticket 11915, Breakdance).
     * Every localizer lane read the DOCUMENT only; builders like Breakdance declare their
     * gstatic @font-face inside generated CSS files (uploads/breakdance/css/*), so "local
     * fonts" never engaged there. Mirror of the .795 bgset pattern: read each SAME-ORIGIN
     * linked sheet from disk, swap gstatic URLs already localized in INLINE_MAP for their
     * local copies, rebase relative url()s, and relink to a content-keyed derived copy under
     * cache/wpc-fontsheet/ only when bytes changed. Verdicts indexed by mtime:size:mapcount
     * (map growth re-derives). Unmapped gstatic faces found in sheets feed the EXISTING
     * localizer dispatch, so the map converges without a new fetch pipeline. Derived faces
     * are stamped font-display:swap, never optional — a sheet loads after the document's
     * inline subsets, and optional at the tail yanks a family that is already painting.
     *
     * Kept when the render's @font-face passes became one owner: this is not one of them. It is
     * file-side work in the localizer, over a sheet's own bytes, feeding the localizer's fetch
     * map — never a pass over the rendered document.
     */
    public function wpc_fontsheet_external_sweep($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, '.css') === false
                || $this->isActive() !== 'local'
                || !apply_filters('wpc_fonts_sweep_external', true)) {
                return $html;
            }
            if (!defined('WPS_IC_FONTS_DIR') || !defined('WPS_IC_FONTS_URL') || !defined('WP_CONTENT_DIR')) {
                return $html;
            }
            $map = get_option(self::INLINE_MAP);
            if (!is_array($map)) {
                $map = [];
            }
            $oh = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $sitep = (string) wp_parse_url(site_url('/'), PHP_URL_PATH);
            if ($oh === '') {
                return $html;
            }
            $idx = get_option('wpc_fontsheet_idx');
            if (!is_array($idx)) {
                $idx = [];
            }
            $dirty = false;
            $n = 0;
            $pendingCss = '';
            $mapN = count($map);
            $out = preg_replace_callback('/<link\b[^>]*\bhref=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1[^>]*>/i',
                function ($lm) use ($oh, $sitep, $map, $mapN, &$idx, &$dirty, &$n, &$pendingCss) {
                    if ($n >= (int) apply_filters('wpc_fonts_sweep_external_max', 40)) {
                        return $lm[0];
                    }
                    $href = (string) $lm[2];
                    $bn = strtolower(basename((string) wp_parse_url($href, PHP_URL_PATH)));
                    if (strpos($href, '/cache/wpc-fontsheet/') !== false
                        || strpos($href, '/cache/wpc-bgset/') !== false
                        || strpos($href, '/wp-cio-fonts/') !== false
                        || preg_match('/^(?:wps_|critical_|font-subsets|used)/', $bn)) {
                        return $lm[0];
                    }
                    $h = (string) wp_parse_url($href, PHP_URL_HOST);
                    if ($h !== '' && strcasecmp($h, $oh) !== 0) {
                        return $lm[0];
                    }
                    $path = (string) wp_parse_url($href, PHP_URL_PATH);
                    if ($path === '' || strpos($path, '/wp-') === false) {
                        return $lm[0];
                    }
                    $rel = $path;
                    if ($sitep !== '' && $sitep !== '/' && strpos($rel, rtrim($sitep, '/') . '/') === 0) {
                        $rel = substr($rel, strlen(rtrim($sitep, '/')));
                    }
                    $disk = rtrim(ABSPATH, '/') . $rel;
                    $n++;
                    $sz = @filesize($disk);
                    $mt = @filemtime($disk);
                    if (!$sz || !$mt || $sz < 64 || $sz > (int) apply_filters('wpc_fonts_sweep_external_cap', 1048576)) {
                        return $lm[0];
                    }
                    $key = md5($path);
                    $sig = $mt . ':' . $sz . ':' . $mapN;
                    if (isset($idx[$key]) && $idx[$key]['sig'] === $sig) {
                        $o = (string) $idx[$key]['out'];
                        if ($o === '' || !@is_readable(WP_CONTENT_DIR . '/cache/wpc-fontsheet/' . $o)) {
                            return $lm[0];
                        }
                        return str_replace($lm[1] . $lm[2] . $lm[1],
                            $lm[1] . content_url('cache/wpc-fontsheet/' . $o) . $lm[1], $lm[0]);
                    }
                    $css = (string) @file_get_contents($disk);
                    $verdict = '';
                    if ($css !== '' && stripos($css, 'fonts.gstatic.com') !== false) {
                        $pairs = [];
                        foreach ($map as $g => $f) {
                            if (!is_string($g) || !is_string($f) || $f === '' || strpos($css, $g) === false) {
                                continue;
                            }
                            if (!file_exists(WPS_IC_FONTS_DIR . self::INLINE_DIR . '/' . $f)) {
                                continue;
                            }
                            $pairs[$g] = WPS_IC_FONTS_URL . self::INLINE_DIR . '/' . $f;
                        }
                        $swapped = !empty($pairs) ? strtr($css, $pairs) : $css;
                        if (stripos($swapped, 'fonts.gstatic.com') !== false) {
                            $pendingCss .= "\n" . $swapped;
                        }
                        if ($swapped !== $css) {
                            if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_rebase_css_urls')) {
                                $rebased = wps_cdn_rewrite::wpc_rebase_css_urls($swapped, 'https://' . $oh . $path);
                                if (is_string($rebased) && $rebased !== '') {
                                    $swapped = $rebased;
                                }
                            }
                            $inlineFontsBase = (string) WPS_IC_FONTS_URL . self::INLINE_DIR . '/';
                            $fontSettings = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
                            $fontDisplayOff = is_array($fontSettings) && !empty($fontSettings['font-display'])
                                && strtolower((string) $fontSettings['font-display']) === 'off';
                            $stamped = $fontDisplayOff ? $swapped : preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($fm) use ($inlineFontsBase) {
                                if (stripos($fm[0], $inlineFontsBase) === false) {
                                    return $fm[0];
                                }
                                $b = preg_replace('/font-display\s*:\s*[^;}]+;?/i', '', $fm[0]);
                                return preg_replace('/\{/', '{font-display:swap;', $b, 1);
                            }, $swapped);
                            if (is_string($stamped) && $stamped !== '') {
                                $swapped = $stamped;
                            }
                            $dd = WP_CONTENT_DIR . '/cache/wpc-fontsheet';
                            if (!is_dir($dd)) {
                                @mkdir($dd, 0755, true);
                            }
                            $stem = preg_replace('/\.css$/', '', basename($path));
                            $name = $stem . '-' . substr(md5($swapped), 0, 10) . '.css';
                            if (wpc_fs_put($dd . '/' . $name, $swapped) !== false) {
                                $sib = (array) @glob($dd . '/' . $stem . '-*.css');
                                if (count($sib) > 3) {
                                    usort($sib, static function ($a, $b) {
                                        return (int) @filemtime($a) - (int) @filemtime($b);
                                    });
                                    foreach (array_slice($sib, 0, count($sib) - 3) as $old) {
                                        if (basename($old) !== $name) {
                                            @unlink($old);
                                        }
                                    }
                                }
                                $verdict = $name;
                            }
                        }
                    }
                    $idx[$key] = ['sig' => $sig, 'out' => $verdict];
                    $dirty = true;
                    if ($verdict === '') {
                        return $lm[0];
                    }
                    return str_replace($lm[1] . $lm[2] . $lm[1],
                        $lm[1] . content_url('cache/wpc-fontsheet/' . $verdict) . $lm[1], $lm[0]);
                }, $html);
            if ($dirty) {
                if (count($idx) > 80) {
                    $idx = array_slice($idx, -60, null, true);
                }
                update_option('wpc_fontsheet_idx', $idx, false);
            }
            if ($pendingCss !== '') {
                $this->maybeScheduleInlineLocalize($pendingCss);
            }
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

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


        //      Remove the re-declaration ONLY on an exact family+weight+style match against an
        //      embedded face — never blind; unmatched blocks keep serving (with swap).
        //      v7.10.919: base widened from INLINE_DIR to every localized face (family-dir
        //      sheets, faces_live589 emissions) — those carried baked optional the runtime
        //      never re-resolved. Plus builder-duplicate removal: a raw gstatic face whose
        //      exact family|weight|style|range is served by a file-backed LOCAL face in the
        //      same document is redundant bytes (dalton's triple Poppins stack) — removed
        //      only on that exact-coverage proof, never blind.
        if (strpos($html, (string) WPS_IC_FONTS_URL) !== false) {
            $faceKeyOf = function ($block) {
                $fam = preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $block, $m1) ? strtolower(trim($m1[1])) : '';
                $wgt = preg_match('/font-weight\s*:\s*([0-9]{3}|normal|bold)\b/i', $block, $m2) ? strtolower($m2[1]) : '400';
                $wgt = ($wgt === 'normal') ? '400' : (($wgt === 'bold') ? '700' : $wgt);
                $sty = preg_match('/font-style\s*:\s*(italic|oblique)/i', $block) ? 'italic' : 'normal';
                return $fam . '|' . $wgt . '|' . $sty;
            };
            $rangeKeyOf = function ($block) {
                if (!preg_match('/unicode-range\s*:\s*([^;}]+)/i', $block, $rm)) {
                    return '';
                }
                return strtolower(preg_replace('/\s+/', '', (string) $rm[1]));
            };
            $embeddedFaceKeys = [];
            if (strpos($html, 'data:font/woff2;base64') !== false
                && preg_match_all('#@font-face\s*\{[^{}]*data:font/woff2;base64[^{}]*\}#i', $html, $embeddedMatches)) {
                foreach ($embeddedMatches[0] as $embeddedBlock) {
                    $embeddedFaceKeys[$faceKeyOf($embeddedBlock)] = 1;
                }
            }
            $inlineFontsBase = WPS_IC_FONTS_URL . self::INLINE_DIR . '/';
            $localFontsBase = (string) WPS_IC_FONTS_URL;
            $embeddedFamilies = [];
            foreach (array_keys($embeddedFaceKeys) as $embeddedKey) {
                $embeddedFamilies[strtok((string) $embeddedKey, '|')] = 1;
            }
            // Pre-scan: every localized face whose first local woff asset EXISTS on disk,
            // keyed family|weight|style|normalized-range. This is the coverage proof the
            // gstatic-duplicate removal requires.
            $localFaceKeys = [];
            if (preg_match_all('#@font-face\s*\{[^{}]*\}#i', $html, $faceMatches)) {
                foreach ($faceMatches[0] as $faceBlock) {
                    if (strpos($faceBlock, $localFontsBase) === false) {
                        continue;
                    }
                    if (!preg_match('#url\(\s*["\']?(' . preg_quote($localFontsBase, '#') . '[^"\')\s]+)#i', $faceBlock, $localUrlMatch)) {
                        continue;
                    }
                    $localFontPath = str_replace($localFontsBase, (string) WPS_IC_FONTS_DIR, $localUrlMatch[1]);
                    $localFontPath = (string) preg_replace('/\?.*$/', '', $localFontPath);
                    if (!@file_exists($localFontPath)) {
                        continue;
                    }
                    $localFaceKeys[$faceKeyOf($faceBlock) . '|' . $rangeKeyOf($faceBlock)] = 1;
                }
            }
            $rewrittenHtml = preg_replace_callback('#@font-face\s*\{[^{}]*\}#i', function ($m) use ($inlineFontsBase, $localFontsBase, $embeddedFaceKeys, $faceKeyOf, $rangeKeyOf, $embeddedFamilies, $localFaceKeys) {
                if (strpos($m[0], $localFontsBase) === false) {
                    if (stripos($m[0], 'fonts.gstatic.com') !== false
                        && isset($localFaceKeys[$faceKeyOf($m[0]) . '|' . $rangeKeyOf($m[0])])
                        && apply_filters('wpc_font_dup_standdown', true)) {
                        return '/*wpc-dup-face-standdown*/';
                    }
                    return $m[0];
                }
                $faceKey = $faceKeyOf($m[0]);
                if (strpos($m[0], $inlineFontsBase) !== false
                    && !empty($embeddedFaceKeys) && isset($embeddedFaceKeys[$faceKey])) {
                    return '/*wpc-inline-face-standdown*/';
                }
                $fontSettings = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
                if (is_array($fontSettings) && !empty($fontSettings['font-display'])
                    && strtolower((string) $fontSettings['font-display']) === 'off') {
                    return $m[0];
                }
                $faceWithoutDisplay = preg_replace('/font-display\s*:\s*[^;}]+;?/i', '', $m[0]);
                $faceFamily = strtok($faceKey, '|');
                $fontDisplay = function_exists('wpc_font_display_effective') ? wpc_font_display_effective('swap', $faceFamily) : 'swap';
                if ($fontDisplay === 'optional' && isset($embeddedFamilies[$faceFamily])) {
                    $fontDisplay = 'swap';
                }
                if (!in_array($fontDisplay, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) { $fontDisplay = 'swap'; }
                return preg_replace('/\{/', '{font-display:' . $fontDisplay . ';', $faceWithoutDisplay, 1);
            }, $html);
            if (is_string($rewrittenHtml) && $rewrittenHtml !== '') {
                $html = $rewrittenHtml;
            }
        }
        // Any still-unmapped LATIN inline faces? schedule a bounded background download (throttled).
        $this->maybeScheduleInlineLocalize($html);
        return $html;
    }

    /**
     * The gstatic URLs the inline map names whose file is not on disk. The map is an option and
     * the files are in the cache directory, so a staging copy, or a site whose cache directory was
     * emptied, has the first without the second; the localizer fetches these again.
     */
    private function mappedFilesMissing($map)
    {
        $missing = [];
        if (!is_array($map)) {
            return $missing;
        }
        foreach ($map as $gstaticUrl => $fileName) {
            if (is_string($gstaticUrl) && $gstaticUrl !== '' && is_string($fileName) && $fileName !== ''
                && !file_exists(WPS_IC_FONTS_DIR . self::INLINE_DIR . '/' . $fileName)) {
                $missing[] = $gstaticUrl;
            }
        }
        return $missing;
    }

    /**
     * Schedule the background download of the latin gstatic faces in $html that are unmapped, and
     * of every mapped file that is not on disk (shutdown after the response where the host can
     * flush, wp-cron otherwise; throttled). Called by the inline-face swap, the external-sheet
     * sweep, and the combined-crit lane, which swaps gstatic URLs before the font stage can see
     * them.
     */
    public function maybeScheduleInlineLocalize($html)
    {
        if ($this->isActive() !== 'local' || !apply_filters('wpc_localize_inline_fonts', true)) {
            return;
        }
        if (stripos($html, 'fonts.gstatic.com') === false || get_transient('wpc_font_inline_lock')) {
            return;
        }

        // Yield to visitors: the localizer (gstatic HTTP + purge) waits for a calm box
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return;
        }

        // Durable single-flight: concurrent renders on a flushed object cache must not
        // each dispatch a localizer (gstatic HTTP + a FULL HTML-cache purge apiece)
        $lastInlineLocalizeAt = (int) get_option('wpc_font_inline_at');
        if (time() - $lastInlineLocalizeAt < 15 * MINUTE_IN_SECONDS) {
            return;
        }
        update_option('wpc_font_inline_at', time(), false);

        set_transient('wpc_font_inline_lock', 1, 15 * MINUTE_IN_SECONDS);
        $latin = $this->findInlineGstaticFaces($html);
        $map = get_option(self::INLINE_MAP);
        if (!is_array($map)) {
            $map = [];
        }
        $unmapped = !empty($this->mappedFilesMissing($map));
        if (empty($latin) && !$unmapped) {
            return;
        }
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
        $wpc_can_flush = (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) && function_exists('register_shutdown_function');
        if ($wpc_can_flush) {
            register_shutdown_function(function () use ($wpc_inline_html) {
                wpc_finish_request();
                if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
                if (function_exists('set_time_limit')) { @set_time_limit(120); }
                try {
                    $n = (int) $this->localizeInlineFonts($wpc_inline_html);
                    if ($n > 0 && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                        try { wps_ic_cache::removeHtmlCacheFiles('all', '', '', 'soft'); } catch (\Throwable $e) {}
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
        // Backstop (also the ONLY path on non-FPM): cron event, if not already queued.
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

    /** True if a unicode-range includes any latin-family codepoint (start < U+0370 = below Greek). */
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
        $map = get_option(self::INLINE_MAP);
        if (!is_array($map)) {
            $map = [];
        }
        $urls = array_values(array_unique(array_merge($this->findInlineGstaticFaces($html), $this->mappedFilesMissing($map))));
        if (empty($urls)) {
            return 0;
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

        // Handle errors
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        // Get response body
        $body = wp_remote_retrieve_body($response);

        // Decode JSON if API returns JSON
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

        // Validate response structure
        if (!is_array($response) || !isset($response['found']) || !is_array($response['found'])) {
            return array();
        }

        $found = array();

        // Store google fonts stylesheets if present
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
                // Read CSS Stylesheet
                $this->stylesheet = null;
                $download = $this->read($font);

                // A failed or empty fetch must not be written or mapped — the live Google
                // link keeps serving and the next rescan retries.
                if (!empty($download['error']) || !is_string($this->stylesheet) || $this->stylesheet === '') {
                    continue;
                }

                // Every referenced font file must land before the sheet is published;
                // a partial set would bake remote URLs into the local copy for good.
                $stylesheetDir = md5($font);
                $urlReplacements = [];
                $allFontsDownloaded = true;
                if (!empty($download['all'])) {
                    foreach ($download['all'] as $fontFileUrl) {
                        $downloadFont = $this->download($fontFileUrl, $stylesheetDir);
                        if (!is_array($downloadFont) || !empty($downloadFont['error'])
                            || empty($downloadFont['url']) || empty($downloadFont['filename'])) {
                            $allFontsDownloaded = false;
                            break;
                        }
                        $urlReplacements[] = [$downloadFont['url'], WPS_IC_FONTS_URL . $stylesheetDir . '/' . $downloadFont['filename']];
                    }
                }
                if (!$allFontsDownloaded) {
                    continue;
                }

                $stylesheet = $this->saveStylesheet($font, $this->stylesheet, $stylesheetDir);
                if (!is_array($stylesheet) || empty($stylesheet['stylesheetPath'])) {
                    continue;
                }
                foreach ($urlReplacements as $urlReplacement) {
                    $this->replaceInStylesheet($stylesheet['stylesheetPath'], $urlReplacement[0], $urlReplacement[1]);
                }

            }

            return 'downloaded';
        }

        return 'error';
    }

    public function read($stylesheet)
    {
        // Normalize HTML-encoded ampersands (your scan example had &#038;)
        $css_url = str_replace('&#038;', '&', $stylesheet);

        // Support protocol-relative
        if (str_starts_with($css_url, '//')) {
            $css_url = 'https:' . $css_url;
        }

        $response = wp_remote_get($css_url, ['timeout' => 30, 'redirection' => 5, 'headers' => [// Browser-ish headers: helps avoid 403 with Google Fonts sometimes
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

        // Find all url(...) occurrences in CSS, tolerate quotes, spaces.
        // Captures: url( ... )
        preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $matches);

        $urls = [];
        if (!empty($matches[2])) {
            foreach ($matches[2] as $u) {
                $u = trim($u);

                // Skip data URIs
                if (str_starts_with($u, 'data:')) {
                    continue;
                }

                // Protocol-relative inside CSS
                if (str_starts_with($u, '//')) {
                    $u = 'https:' . $u;
                }

                // Basic sanitize
                $u = esc_url_raw($u);

                if ($u) {
                    $urls[] = $u;
                }
            }
        }

        // Unique while preserving order
        $urls = array_values(array_unique($urls));

        // Group by extension (ignoring querystrings)
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

        // Encode the filename because of special characters
        $stylesheetFilename = md5(basename($styleSheetURL)) . '.css';

        // Map the stylesheet URL to Filename
        $this->mapStylesheets($styleSheetURL, $dir, $stylesheetFilename);

        // Create directory
        wp_mkdir_p($stylesheetPath);


        if (file_exists($stylesheetPath . $stylesheetFilename)) {
            unlink($stylesheetPath . $stylesheetFilename);
        }


        $fdOpts = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
        $fd = (is_array($fdOpts) && !empty($fdOpts['font-display'])) ? strtolower((string) $fdOpts['font-display']) : 'swap';
        // v7.10.401: resolve through the effective value so a validated metrics-matched
        // fallback upgrades swap -> optional (kills the font-swap CLS on Divi headings).
        if ($fd !== 'off' && function_exists('wpc_font_display_effective')) { $fd = wpc_font_display_effective($fd); }

        if ($fd !== 'off') {
            if (!in_array($fd, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                $fd = 'swap';
            }
            // v7.10.483 — resolve PER FACE, because 'optional' is only safe for a family that
            // actually has a metric-matched fallback. Resolving once outside the callback meant
            // one family's metrics promoted every face on the site (zinsenvergleich: Astra got
            // optional with no size-adjust and a live network fetch — a glyph that may never
            // paint). $fdRaw is the setting; the family decides what it resolves to.
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

        // Write CSS content to File
        wpc_fs_put($stylesheetPath . $stylesheetFilename, $stylesheetCSS, LOCK_EX);


        if (defined('WPS_IC_FONTS_DIR') && function_exists('wpc_fonts_htaccess_ensure')) {
            wpc_fonts_htaccess_ensure(WPS_IC_FONTS_DIR);
        }

        return ['stylesheetPath' => $stylesheetPath . $stylesheetFilename];
    }


    public function rebakeFontDisplay()
    {
        $rebakedCount = 0;
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
                $faceFamily = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $familyMatch)
                    ? trim($familyMatch[1], " \t\"'") : '';
                $faceDisplay = ($faceFamily !== '' && function_exists('wpc_font_display_effective'))
                    ? wpc_font_display_effective($fd, $faceFamily) : $fd;
                if (!in_array($faceDisplay, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                    $faceDisplay = 'swap';
                }
                if (preg_match('/font-display\s*:/i', $m[0])) {
                    return preg_replace('/font-display\s*:\s*[^;]+;?/i', 'font-display:' . $faceDisplay . ';', $m[0], 1);
                }
                return preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $faceDisplay . ';', $m[0], 1);
            }, $css);
            if (is_string($baked) && $baked !== $css) {
                if (wpc_fs_put($path, $baked) !== false) { $rebakedCount++; }
            }
        }


        foreach ((array) @glob(rtrim(WPS_IC_FONTS_DIR, '/') . '/*/*.css') as $familySheetPath) {
            if (!@is_writable($familySheetPath)) {
                continue;
            }
            $sheetCss = @file_get_contents($familySheetPath);
            if (!is_string($sheetCss) || $sheetCss === '' || stripos($sheetCss, '@font-face') === false) {
                continue;
            }
            $bakedSheetCss = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($fd) {
                if (preg_match('/font-display\s*:/i', $m[0])) {
                    return $m[0];
                }
                $faceFamily = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $familyMatch)
                    ? trim($familyMatch[1], " \t\"'") : '';
                $faceDisplay = ($faceFamily !== '' && function_exists('wpc_font_display_effective'))
                    ? wpc_font_display_effective($fd, $faceFamily) : $fd;
                if (!in_array($faceDisplay, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                    $faceDisplay = 'swap';
                }
                return preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $faceDisplay . ';', $m[0], 1);
            }, $sheetCss);
            if (is_string($bakedSheetCss) && $bakedSheetCss !== $sheetCss) {
                if (wpc_fs_put($familySheetPath, $bakedSheetCss) !== false) { $rebakedCount++; }
            }
        }
        return $rebakedCount;
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

    /**
     * Split localized @font-face CSS into fold-critical and deferrable (v7.10.529).
     *
     * Families the fold needs come from delay.json's atf_glyphs keys ("Family|weight|style"),
     * i.e. the service's own measurement — never a guess. Returns null (inline everything,
     * unchanged behaviour) whenever that list cannot be established, so a missing or
     * old-schema manifest can never strip a face the page needs.
     */
    /**
     * Name the fail-open branch (v7.10.566). This splitter has now been wrong twice in ways that
     * were invisible from the outside — .529 never ran at all, .562 ran only when the CDN addon
     * happened to be loaded — because "inline everything" is also the correct behaviour when it
     * genuinely does not know. Every silent branch now says which one it took. Zero-cost when
     * the log helper is absent.
     */
    private static function wpc_log_atf_scope_branch($why, $key = '')
    {
        if (function_exists('wpc_cache_first_log')) {
            try { wpc_cache_first_log('atf-scope-open', (string) $key, '', ['why' => (string) $why]); } catch (\Throwable $e) {}
        }
    }

    /**
     * v7.10.589 — an @font-face DECLARATION is never inert.
     *
     * type="wpc-mobile-stylesheet" hid a <style> from the browser until the delay loader
     * flipped it on. Correct for layout rules, wrong for @font-face: a hidden declaration means
     * the family has no usable face, so matching leaves that family and lands on the next
     * entry in the stack — a system font — for as long as the visitor never interacts.
     * .529 deferred these to keep a below-fold woff2 off the FCP chain; that bought a
     * Lighthouse chain item and paid for it in the wrong typeface on every page.
     *
     * A live @font-face costs document bytes, not requests: the woff2 is fetched only when a
     * glyph actually matches the face, so a family this sheet declares but the page never
     * uses downloads nothing.
     *
     * The faces go to the render's face owner rather than into a block of this lane's own, and
     * the owner decides which block a face lands in. $origin names this lane in the render's
     * face receipt, and it is also what tells the owner to put font-display:swap on a face that
     * declares none — this lane is the only one that ever did, so it passes the origin constant
     * the owner keys that rule on rather than a string of its own.
     */
    private static function registerLiveFaces($faces, $origin, wps_ic_font_face_set $fontFaces)
    {
        $faces = (string) $faces;
        if (trim($faces) === '' || !class_exists('wps_rewriteLogic')) {
            return;
        }
        $fontFaces->add($faces, $origin, (bool) apply_filters('wpc_fonts_faces_always_live', true));
    }

    private static function wpc_scope_faces_to_atf_glyphs($css, $html = '')
    {
        if (!apply_filters('wpc_atf_scope_faces', true)) {
            return null;
        }
        if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
            self::wpc_log_atf_scope_branch('no-crit-const-or-urlkey');
            return null;
        }
        try {
            $urlKey = (new wps_ic_url_key())->setup('');
            if ($urlKey === '') {
                self::wpc_log_atf_scope_branch('empty-urlkey');
                return null;
            }
            $critDir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            // v7.10.562 — READ THROUGH THE CANONICAL READER, never a second implementation.
            // .529 parsed delay.json itself and looked only at the TOP LEVEL, but the live schema
            // nests the map per device (delay.json -> desktop|mobile -> atf_glyphs), so it
            // returned null on every real site and .529 never once ran.
            // v7.10.566 — that reader now lives in defines.php (include_once'd unconditionally).
            // Routing through wps_rewriteLogic made this depend on the CDN addon, which is
            // included only from cdn-rewrite.php:43 — on a render without it the class was absent
            // and every face went back inline. No class dependency here, by construction.
            if (!function_exists('wpc_atf_glyphs_read')) {
                self::wpc_log_atf_scope_branch('no-reader');
                return null;
            }
            $atfGlyphs = wpc_atf_glyphs_read($critDir);
            if (!is_array($atfGlyphs) || empty($atfGlyphs)) {
                self::wpc_log_atf_scope_branch('no-atf-glyphs', $urlKey);
                return null;
            }
            $atfFamilies = [];
            foreach (array_keys($atfGlyphs) as $glyphKey) {
                $glyphKeyParts = explode('|', (string) $glyphKey);
                $familyName = strtolower(trim($glyphKeyParts[0]));
                if ($familyName !== '') {
                    $atfFamilies[$familyName] = 1;
                }
            }
            if (empty($atfFamilies)) {
                self::wpc_log_atf_scope_branch('empty-family-list', $urlKey);
                return null;
            }
            // Brace-matched so a nested block can never split a face in half.
            $atfCss = '';
            $restCss = '';
            $offset = 0;
            $cssLength = strlen($css);
            $anyAtfFace = false;
            while ($offset < $cssLength) {
                $faceAt = stripos($css, '@font-face', $offset);
                if ($faceAt === false) {
                    $atfCss .= substr($css, $offset);
                    break;
                }
                $atfCss .= substr($css, $offset, $faceAt - $offset);
                $openBrace = strpos($css, '{', $faceAt);
                if ($openBrace === false) {
                    $atfCss .= substr($css, $faceAt);
                    break;
                }
                $braceDepth = 1;
                $blockEnd = $openBrace + 1;
                while ($blockEnd < $cssLength && $braceDepth > 0) {
                    if ($css[$blockEnd] === '{') { $braceDepth++; }
                    elseif ($css[$blockEnd] === '}') { $braceDepth--; }
                    $blockEnd++;
                }
                $faceBlock = substr($css, $faceAt, $blockEnd - $faceAt);
                $keepInline = false;
                if (preg_match('/font-family\s*:\s*([^;}]+)/i', $faceBlock, $familyMatch)) {
                    $faceFamily = strtolower(trim($familyMatch[1], " \t\n\r\0\x0B\"'"));
                    $keepInline = isset($atfFamilies[$faceFamily]);
                }
                if ($keepInline) { $atfCss .= $faceBlock; $anyAtfFace = true; }
                else { $restCss .= $faceBlock; }
                $offset = $blockEnd;
            }
            // Every face matched => nothing to defer, no split needed. Not a fail-open.
            if (trim($restCss) === '') {
                return null;
            }
            // v7.10.560 — zero matches is TWO different states and .529 collapsed them:
            //   (a) the matcher disagrees with this stylesheet's spelling  -> must fail open
            //   (b) the fold's families are simply not in this sheet       -> defer ALL of it
            // (b) is the case the feature exists for and .529 rejected it. Disambiguate on
            // evidence: if every ATF family already has an @font-face elsewhere in the document,
            // the fold is provably covered and this sheet is entirely below it.
            if (!$anyAtfFace) {
                // v7.10.562 — coverage is read from the ARTIFACTS, not only from $html. This runs
                // inside an output filter whose position relative to crit injection is not
                // guaranteed, so "is the family declared in the document yet" is a question about
                // pipeline order, not about the fold. The crit dir answers it order-independently.
                $coverageSource = (is_string($html) ? $html : '');
                foreach (['font-subsets.css', 'critical_desktop.css', 'critical_mobile.css'] as $artifactFile) {
                    if (@is_readable($critDir . $artifactFile)) {
                        $coverageSource .= (string) @file_get_contents($critDir . $artifactFile);
                    }
                }
                if ($coverageSource === '') {
                    self::wpc_log_atf_scope_branch('no-coverage-source', $urlKey);
                    return null;
                }
                $coveredFamilies = [];
                if (preg_match_all('/@font-face\s*\{[^}]*\}/i', $coverageSource, $sourceFaces)) {
                    foreach ($sourceFaces[0] as $sourceFace) {
                        if (preg_match('/font-family\s*:\s*([^;}]+)/i', $sourceFace, $sourceFamilyMatch)) {
                            $coveredFamilies[strtolower(trim($sourceFamilyMatch[1], " \t\n\r\0\x0B\"'"))] = 1;
                        }
                    }
                }
                foreach (array_keys($atfFamilies) as $neededFamily) {
                    if (!isset($coveredFamilies[$neededFamily])) {
                        self::wpc_log_atf_scope_branch('family-uncovered:' . $neededFamily, $urlKey);
                        return null;
                    }
                }
                return ['atf' => '', 'rest' => $atfCss . $restCss];
            }
            return ['atf' => $atfCss, 'rest' => $restCss];
        } catch (\Throwable $e) {
            self::wpc_log_atf_scope_branch('threw:' . substr($e->getMessage(), 0, 60));
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

        // Use the $url passed in (your original code used $this->url)
        $request_url = $url;

        // Handle protocol-relative URLs
        if (str_starts_with($request_url, '//')) {
            $request_url = 'https:' . $request_url;
        }


        $request_url = str_replace('&#038;', '&', $request_url);

        $temp_filename = $dir . $this->filename . '.' . uniqid('', true) . '.tmp';

        // v7.10.525 — 300s to fetch a FONT. Measured payload on a live site: a 32 KB woff2
        // in 0.57s, so the old ceiling was ~500x the real transfer and any stalled fetch held
        // an FPM worker for five minutes. 20s keeps ~35x headroom over the measured time while
        // making a hung CDN cost seconds, not minutes. Streaming to disk is unchanged.
        $args = ['timeout' => (int) apply_filters('wpc_font_download_timeout', 20), 'redirection' => 5, 'stream' => true, 'filename' => $temp_filename, 'headers' => [// Browser-like headers often prevent Google Fonts 403
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

            // Helpful debug info for 403s
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
        wpc_fs_put($stylesheetPath, $contents, LOCK_EX);
    }

    public function downloadFound($array)
    {
        if (!empty($array['googleFontsStylesheets'])) {
            foreach ($array['googleFontsStylesheets'] as $font) {
                #echo 'downloading: ' . $font . " -- ";
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