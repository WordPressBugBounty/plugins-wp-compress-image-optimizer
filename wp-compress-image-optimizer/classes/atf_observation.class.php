<?php

/**
 * The image geometry the crit service measured on this URL, read once.
 *
 * `lcp.json` carries, per device, the images the service found above the fold (`atf_images`)
 * and, full-page, the ones whose file is bigger than the box they paint in
 * (`oversized_images`). Each entry is `{stem, css_w, css_h, top, nat_w, nat_h}` plus the
 * optional stamps described under RESOLUTION below.
 *
 * WHY THIS CLASS EXISTS. Five places used to read that data — the afold sizes map, Smart
 * Delivery's afoldHints, the combined-crit sizes lane, the census rung builder and the v2
 * health endpoint. Each resolved the file path its own way, decoded the JSON again, named
 * its keys differently (`m`/`d`, `css_w_m`/`css_w_d`, `mobile_w`/`desktop_w`) and applied a
 * different subset of the validation. So a width defect had to be fixed in five places and
 * never was: zahnarzt-naumann.de (a header that never loaded, laid out as 197x25 alt text)
 * was fixed in the readers that had a guard, and glass-inspirations.co.uk — where one file
 * is used twice above the fold and the narrower use won — is open in all of them.
 *
 * One reader, one answer. It resolves the path once, decodes once, validates once and
 * answers these questions:
 *
 *   rows($scope, $device)       the validated entries for one device, in observation order
 *   widths($scope)              stem => ['m' => w, 'd' => w, 'mh' => h, 'dh' => h], resolved
 *   multiUseStems($scope)       the files the service saw on more than one <img> (withheld by widths)
 *   naturalSizes()              every natural size recorded per upload (the image-sizing owner's dims)
 *   lcpElement($device, $page)  the measured (or service-derived) LCP element for one device
 *   lcpPreloadHints($device)    the service's hero preload directives (the preload writers)
 *   observedPageKey()           the page the observation measured, for receipts
 *
 * SELECTORS AND VIDEOS. A `sel` is handed out only with `sel_unique: true`
 * (with_proven_selector), and a video LCP is always `type: 'video'` with no image url
 * (lcpElement); both follow the lcp.json contract of crit-push 3.198.293 and read older
 * documents into it.
 *   measuredDpr($device)        the device pixel ratio the service measured at (the background LCP rung)
 *   preconnectOrigins()         the hosts the service asks to warm (the crit head)
 *   topsByStem($device)         where each image sits on this page (the census below-fold veto)
 *   revealRules($device)        what must be visible at first paint on this page (the reveal sheet)
 *   concealedBoxes($device)     boxes that shrink after paint on this page (the CLS reserve)
 *   selectorVerdicts()          the service's uniqueness verdict per selector (the CLS reserve)
 *   aboveFoldSelectors()        the selectors of the LCP and the images above the fold (section containment)
 *
 * WHICH FILE. Two documents can answer. The image-identity questions (rows, widths,
 * naturalSizes, lcpElement, lcpPreloadHints, measuredDpr, preconnectOrigins) read the page's
 * lcp.json or, while the page has none, the home page's: every one of their consumers matches
 * an entry against the page's own markup by file name or URL, so a home entry that is not on
 * the page does nothing. The position and selector questions (topsByStem, revealRules,
 * concealedBoxes, selectorVerdicts, aboveFoldSelectors, and lcpElement with $page) read the
 * page's own lcp.json or nothing: a home page's `top` vetoes an interior page's image from the
 * eager slot, and a home page's selector pins a box or reveals an element on a page it was
 * never measured on.
 *
 * RESOLUTION. `widths()` answers only for files the page uses ONCE. The service stamps
 * `dom_n` (how many <img> carry this file) and `css_w_max` (the widest box among them) when
 * it saw a file more than once; both are absent otherwise, and absent on anything written
 * before crit-push v3.198.254. For a multi-use file there is no width a stem-keyed `sizes`
 * attribute can carry — it hits every one of those tags — so the stem is withheld and the
 * image keeps whatever `sizes` the theme wrote. Baking the widest box would have pessimised
 * glass-inspirations.co.uk's 149px mega-menu thumbnail; baking the first observation is what
 * shipped its 699px hero slide at the 149w rung. Entries for those files carry `sel`, and
 * `rows()` hands them over untouched for a consumer that can scope a bake to one element.
 *
 * It then clamps a desktop leg that sits below 75% of the mobile leg up to the mobile value.
 * A desktop measurement far under its own mobile measurement is a shrunken state (a Divi
 * sticky header halves its logo after scroll), not a layout. A genuine desktop-column
 * layout — 300px column against 390px mobile full-width — sits above that ratio and keeps
 * its honest measurement. Overshooting a rung is invisible; undershooting is not.
 *
 * VALIDATION. `entry_is_laid_out()` rejects an entry that does not describe a laid-out
 * image: `nat_w` 0 is the service's own not-laid-out signal, and a box whose aspect
 * disagrees with the image's natural aspect by more than a third is not a width the file's
 * rungs can answer. crit-push v3.198.283 `guardAtfImages` strips `css_w` for a narrower set:
 * boxes that disagree with the tag's width/height ATTRIBUTES, and never for a JS-lazy
 * placeholder or a CSS background. So this test is NOT a pre-.283 compatibility shim and must
 * not be dated out: on renegadeuxs.com /system/sandman/ (svc 3.198.283) an object-fit box of
 * 120x80 on a 1439x2104 upload passes the service and, with this test gated off, rebuilt the
 * page's LCP srcset from that box (first candidate 120w instead of 647w; t1601 fixture
 * renegade-system-sandman). The sanity bounds some callers apply (width 24..2000, stem
 * charset) are NOT applied here: only three of the readers had them, and moving them would change what the other two
 * accept. They stay at the call sites until that is settled deliberately.
 */
class wps_ic_atf_observation
{
    const SCOPE_ATF = 'atf';
    const SCOPE_OVERSIZED = 'oversized';
    const SCOPE_ALL = 'atf+oversized';

    /** Decoded lcp.json for this request per source ('page-or-home', 'page'); false once a read
     *  has been tried and failed. See WHICH FILE on the class. */
    private static $documents = [];

    /** Memoised rows per "scope|device" and resolved width maps per scope. */
    private static $rows = [];
    private static $widths = [];
    private static $multiUse = [];
    private static $naturalSizes = null;

    /**
     * The validated entries of one scope for one device, in the order the service observed
     * them. $device is 'mobile' or 'desktop'.
     *
     * When the service wrote a flat list instead of a per-device one — older artifacts — the
     * same list answers for both devices, which is what every caller did by hand.
     */
    public static function rows($scope, $device)
    {
        $cache_key = $scope . '|' . $device;
        if (isset(self::$rows[$cache_key])) {
            return self::$rows[$cache_key];
        }
        self::$rows[$cache_key] = [];
        if ($scope === self::SCOPE_ALL) {
            self::$rows[$cache_key] = array_merge(
                self::rows(self::SCOPE_ATF, $device),
                self::rows(self::SCOPE_OVERSIZED, $device)
            );
            return self::$rows[$cache_key];
        }

        $field = ($scope === self::SCOPE_OVERSIZED) ? 'oversized_images' : 'atf_images';
        $document = self::document();
        if (!is_array($document) || empty($document[$field]) || !is_array($document[$field])) {
            return self::$rows[$cache_key];
        }
        $lists = $document[$field];
        $list = (isset($lists[$device]) && is_array($lists[$device])) ? $lists[$device] : [];
        if (empty($list) && empty($lists['mobile']) && empty($lists['desktop']) && isset($lists[0])) {
            $list = $lists; // flat, pre-per-device artifact: one list answers for both legs
        }

        $out = [];
        $notLaidOut = 0;
        foreach ((array) $list as $entry) {
            if (!is_array($entry) || empty($entry['stem']) || !is_string($entry['stem'])) {
                continue;
            }
            if (!self::entry_is_laid_out($entry)) {
                $notLaidOut++;
                continue;
            }
            $out[] = self::with_proven_selector($entry);
        }
        // An entry that describes no laid-out image (zero natural width, or a box whose aspect
        // contradicts the file) is the service's measurement gone wrong: its v3.198.283 guard
        // covers only part of it. Not sampled: the count per device says how often it still does.
        if ($notLaidOut > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('atf-entry-not-laid-out', [(string) $device => $notLaidOut]);
        }
        self::$rows[$cache_key] = $out;
        return self::$rows[$cache_key];
    }

    /**
     * stem => ['m' => width, 'd' => width, 'mh' => height, 'dh' => height] for the scope,
     * with the widest-box resolution and the shrunken-desktop clamp applied. Widths and
     * heights are rounded integers; a leg the service never measured is 0.
     */
    public static function widths($scope)
    {
        if (isset(self::$widths[$scope])) {
            return self::$widths[$scope];
        }
        // A file the service saw on more than one <img> cannot be sized by stem at all: one
        // `sizes` attribute keyed by file name hits every one of those tags. Baking the hero's
        // width pessimises the thumbnail and baking the thumbnail's ships the hero pixelated,
        // which is glass-inspirations.co.uk exactly (a 149px mega-menu thumbnail and a 699px
        // slide, same file, both above the fold). The service stamps `sel` for these so a
        // consumer can scope the bake to one element; no consumer here can address a CSS
        // selector, so the stem is withheld and the image keeps the theme's own `sizes`.
        $multi_use = self::multiUseStems($scope);

        $map = [];
        foreach (['m' => 'mobile', 'd' => 'desktop'] as $leg => $device) {
            foreach (self::rows($scope, $device) as $entry) {
                if (empty($entry['css_w'])) {
                    continue;
                }
                $stem = strtolower((string) $entry['stem']);
                if ($stem === '' || isset($multi_use[$stem])) {
                    continue;
                }
                if (!isset($map[$stem])) {
                    $map[$stem] = ['m' => 0, 'd' => 0, 'mh' => 0, 'dh' => 0];
                }
                if ($map[$stem][$leg] !== 0) {
                    continue; // one observation per file per leg; the first is the measurement
                }
                $map[$stem][$leg] = (int) round((float) $entry['css_w']);
                $map[$stem][$leg . 'h'] = isset($entry['css_h']) ? (int) round((float) $entry['css_h']) : 0;
            }
        }
        foreach ($map as $stem => $record) {
            $map[$stem] = self::clamp_shrunken_desktop($record);
        }
        self::$widths[$scope] = $map;
        return self::$widths[$scope];
    }

    /**
     * stem => ['WxH' => [w, h], …]: every natural size the service recorded for an upload, over
     * both devices and both lists, laid out or not (the box can be unusable while the file's size
     * is still what the browser decoded). One size means every observed use loaded the same file;
     * more than one means the uses loaded different rungs. For the image-sizing owner's dims.
     */
    public static function naturalSizes()
    {
        if (self::$naturalSizes !== null) {
            return self::$naturalSizes;
        }
        self::$naturalSizes = [];
        $document = self::document();
        if (!is_array($document)) {
            return self::$naturalSizes;
        }
        foreach (['atf_images', 'oversized_images'] as $field) {
            $lists = (isset($document[$field]) && is_array($document[$field])) ? $document[$field] : [];
            $perDevice = [];
            foreach (['mobile', 'desktop'] as $device) {
                if (isset($lists[$device]) && is_array($lists[$device])) {
                    $perDevice[] = $lists[$device];
                }
            }
            if (empty($perDevice) && isset($lists[0])) {
                $perDevice[] = $lists; // flat, pre-per-device artifact
            }
            foreach ($perDevice as $list) {
                foreach ($list as $entry) {
                    if (!is_array($entry) || empty($entry['stem']) || !is_string($entry['stem'])) {
                        continue;
                    }
                    $natW = isset($entry['nat_w']) ? (int) $entry['nat_w'] : 0;
                    $natH = isset($entry['nat_h']) ? (int) $entry['nat_h'] : 0;
                    if ($natW > 0 && $natH > 0) {
                        self::$naturalSizes[strtolower($entry['stem'])][$natW . 'x' . $natH] = [$natW, $natH];
                    }
                }
            }
        }
        return self::$naturalSizes;
    }

    /**
     * stem => true for every file the service saw on more than one <img> in the scope, on either
     * device. widths() withholds these; the image-sizing owner names them in its receipt.
     */
    public static function multiUseStems($scope)
    {
        if (isset(self::$multiUse[$scope])) {
            return self::$multiUse[$scope];
        }
        $multi_use = [];
        foreach (['mobile', 'desktop'] as $device) {
            foreach (self::rows($scope, $device) as $entry) {
                if (self::reports_multiple_uses($entry)) {
                    $multi_use[strtolower((string) $entry['stem'])] = true;
                }
            }
        }
        self::$multiUse[$scope] = $multi_use;
        return $multi_use;
    }

    /**
     * True when the entry describes an image that was actually laid out. See VALIDATION on
     * the class: the service's v3.198.283 guard covers only part of this, so it stays for
     * current artifacts too.
     */
    public static function entry_is_laid_out($entry)
    {
        if (!is_array($entry)) {
            return false;
        }
        if (isset($entry['nat_w']) && (int) $entry['nat_w'] === 0) {
            return false;
        }
        $nat_w = isset($entry['nat_w']) ? (int) $entry['nat_w'] : 0;
        $nat_h = isset($entry['nat_h']) ? (int) $entry['nat_h'] : 0;
        $css_w = isset($entry['css_w']) ? (int) $entry['css_w'] : 0;
        $css_h = isset($entry['css_h']) ? (int) $entry['css_h'] : 0;
        if ($nat_w > 0 && $nat_h > 0 && $css_w > 0 && $css_h > 0
            && abs(($css_w / $css_h) / ($nat_w / $nat_h) - 1) > 0.34) {
            return false;
        }
        return true;
    }

    /**
     * True when the service saw this file on more than one <img> on the page. `dom_n` is
     * stamped only in that case — a single-occurrence entry, and every entry written before
     * crit-push v3.198.254, carries neither `dom_n` nor `css_w_max`, so its absence means one.
     */
    private static function reports_multiple_uses($entry)
    {
        return isset($entry['dom_n']) && (int) $entry['dom_n'] > 1;
    }

    /** See RESOLUTION on the class: a desktop leg under 75% of mobile is a shrunken state. */
    private static function clamp_shrunken_desktop($record)
    {
        if ($record['m'] >= 24 && $record['d'] >= 24 && $record['d'] * 4 < $record['m'] * 3) {
            $record['d'] = $record['m'];
            $record['dh'] = $record['mh'];
        }
        return $record;
    }

    /**
     * The service's measured LCP element for one device ('mobile' or 'desktop'): the fields of
     * lcp[dev] (the identity stem), lcp_element[dev] (the measured type, url, net_url, sel,
     * css_w), {dev}.lcp_element and a flat, pre-per-device lcp_element (only when it names a stem
     * or url), each field taken from the first of those that carries a non-empty value. The containers are
     * complementary, not alternatives: a site whose artifact holds both lcp[dev] and
     * lcp_element[dev] read "the first container that exists" as a stem with no type and no url,
     * and its background LCP could never be preloaded. [] when the page has no observation.
     *
     * With $page true the element comes from the page's own lcp.json only (WHICH FILE on the
     * class): for the readers that keep a page's own background or force its own image eager.
     */
    public static function lcpElement($device, $page = false)
    {
        $document = self::document($page);
        if (!is_array($document)) {
            return [];
        }
        $containers = [];
        if (isset($document['lcp'][$device]) && is_array($document['lcp'][$device])) {
            $containers[] = $document['lcp'][$device];
        }
        if (isset($document['lcp_element'][$device]) && is_array($document['lcp_element'][$device])) {
            $containers[] = $document['lcp_element'][$device];
        }
        if (isset($document[$device]['lcp_element']) && is_array($document[$device]['lcp_element'])) {
            $containers[] = $document[$device]['lcp_element'];
        }
        if (isset($document['lcp_element']) && is_array($document['lcp_element'])
            && (isset($document['lcp_element']['stem']) || isset($document['lcp_element']['url']))) {
            $containers[] = $document['lcp_element'];
        }
        $element = [];
        $isVideo = false;
        foreach ($containers as $container) {
            // A selector is taken only from the container that proves it: one container's
            // sel_unique never vouches for another container's sel.
            $container = self::with_proven_selector($container);
            $isVideo = $isVideo || (isset($container['type']) && $container['type'] === 'video')
                || (isset($container['tag']) && is_string($container['tag']) && strtolower($container['tag']) === 'video');
            foreach ($container as $field => $value) {
                $empty = ($value === null || (is_string($value) && trim($value) === '') || $value === []);
                if (!$empty && !array_key_exists($field, $element)) {
                    $element[$field] = $value;
                }
            }
        }
        // VIDEO IS NOT AN IMAGE. Since crit-push 3.198.293 a <video> LCP is `type:'video'` with
        // its poster as `url` (lcp-detect.js), but lcp[dev] still carries the observer's older
        // type ('bg-img') and merges first, so the service's type is set here. Before .293 the
        // same element came as `type:'bg'` with the .mp4 (currentSrc) as url, and the measured
        // background lane preloaded it as an image (bestofmargaretriver: a 6.7 MB mp4 preloaded
        // at high priority, LCP 5.7 s): a url that names a video file is typed video too, and
        // carries no url, because no preload writer may name it.
        if (!$isVideo && ((isset($element['url']) && is_string($element['url']) && self::names_video_file($element['url']))
            || (isset($element['stem']) && is_string($element['stem']) && self::names_video_file($element['stem'])))) {
            $isVideo = true;
            unset($element['url'], $element['net_url']);
        }
        if ($isVideo) {
            $element['type'] = 'video';
        }
        return $element;
    }

    /**
     * $node with its `sel` (and `sel_raw`) removed unless the service proved it addresses one
     * element (`sel_unique: true`). Since crit-push 3.198.293 every node that carries a `sel`
     * says `sel_unique`; the PSI, RUM and hermetic legs say false, because they never had a DOM
     * to prove it in, and before .293 PSI entries carried no field at all. Every consumer here
     * paints or pins by selector (the background authority pin, the CLS reserve, the LCP reveal),
     * and a selector shared by several elements paints them all (the tarlo 722 px header, the
     * hero fill stamped onto every top section on wpcompress /compare). So an unproven selector
     * is withheld here, once, rather than judged again by each consumer's pattern list.
     * atf_reveal items are not passed through this: the service builds them only from a
     * selector that matched exactly one element (lcp-detect.js reveal census) and stamps no field.
     */
    private static function with_proven_selector($node)
    {
        if (!is_array($node) || !isset($node['sel'])) {
            return $node;
        }
        if (($node['sel_unique'] ?? null) !== true || !is_string($node['sel']) || trim($node['sel']) === '') {
            unset($node['sel'], $node['sel_raw']);
        }
        return $node;
    }

    /** True when $url's path (or a bare stem) names a video file rather than an image. */
    private static function names_video_file($url)
    {
        return (bool) preg_match('/\.(?:mp4|webm|ogv|ogg|mov|m4v|m3u8)(?:[?#]|$)/i', (string) $url);
    }

    /**
     * The service's hero preload directives, `hints.lcp_preload[]`: every entry that is an
     * array with a non-empty string url, in the service's order. With $device, only the entries
     * for that device or for both (an entry without a device is for both). [] when the page has
     * no observation or no directive.
     */
    public static function lcpPreloadHints($device = null)
    {
        $document = self::document();
        if (!is_array($document) || empty($document['hints']['lcp_preload']) || !is_array($document['hints']['lcp_preload'])) {
            return [];
        }
        $hints = [];
        foreach ($document['hints']['lcp_preload'] as $hint) {
            if (!is_array($hint) || empty($hint['url']) || !is_string($hint['url'])) {
                continue;
            }
            // An image preload that names a video file: hints written before crit-push 3.198.293
            // turned a <video> LCP into one (see lcpElement). Since .293 hints.js never does.
            if (self::names_video_file($hint['url'])) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-preload-skip-video', '', '', ['dev' => isset($hint['device']) ? (string) $hint['device'] : 'both']);
                }
                continue;
            }
            $for = isset($hint['device']) ? (string) $hint['device'] : 'both';
            if ($device !== null && $for !== $device && $for !== 'both') {
                continue;
            }
            $hints[] = $hint;
        }
        return $hints;
    }

    /**
     * The page the observation behind the image-identity questions measured (`page.url_key`, the
     * service's canonical key, host and path), '' when the document does not say: written before
     * crit-push 3.198.293, or with its LCP_PAGE_ID_OFF kill switch. A template-cache hit names the
     * sibling it came from. For receipts: the image-identity answers already match by file name.
     */
    public static function observedPageKey()
    {
        $document = self::document();
        return (is_array($document) && isset($document['page']['url_key']) && is_string($document['page']['url_key']))
            ? $document['page']['url_key'] : '';
    }

    /**
     * The device pixel ratio the service rendered one device at (`meta.{device}_dpr`), or 0.0
     * when it is absent or outside (0, 4]. The same document as lcpElement(), so a background
     * LCP's rung is sized with the ratio it was measured at: the element used to come from the
     * page-or-home artifact and the ratio from the page's own, which on a page with no
     * observation of its own paired the home page's box with the default ratio.
     */
    public static function measuredDpr($device)
    {
        $document = self::document();
        $key = $device . '_dpr';
        if (!is_array($document) || !isset($document['meta'][$key]) || !is_numeric($document['meta'][$key])) {
            return 0.0;
        }
        $dpr = (float) $document['meta'][$key];
        return ($dpr > 0 && $dpr <= 4) ? $dpr : 0.0;
    }

    /**
     * The origins the service asks the page to preconnect (`hints.preconnect[]`), at most four:
     * https origins only (scheme, host, optional port, nothing else), one per host, in the
     * service's order. The worst a wrong one costs is one idle socket, which is why it is not
     * narrowed further. [] when there are none.
     */
    public static function preconnectOrigins()
    {
        $document = self::document();
        if (!is_array($document) || empty($document['hints']['preconnect']) || !is_array($document['hints']['preconnect'])) {
            return [];
        }
        $origins = [];
        foreach ($document['hints']['preconnect'] as $entry) {
            if (count($origins) >= 4) {
                break;
            }
            if (!is_string($entry)) {
                continue;
            }
            $origin = rtrim(trim($entry), '/');
            if (!preg_match('#^https://[a-z0-9.\-]+(?::\d+)?$#i', $origin)) {
                continue;
            }
            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            if ($host === '' || isset($origins[$host])) {
                continue;
            }
            $origins[$host] = $origin;
        }
        return array_values($origins);
    }

    /**
     * stem => the smallest `top` the service measured for that file above the fold on this
     * page, for one device only. A file seen twice (a header and a footer instance) keeps its
     * highest sighting, so any above-the-fold sighting wins. No cross-device fallback and no
     * flat, pre-per-device list: a desktop top on a mobile render is a measurement of another
     * layout. Every entry counts, laid out or not: the layout test is about the box, and an
     * image the browser never laid out still sits where it sits. Stems are lower-cased.
     */
    public static function topsByStem($device)
    {
        $document = self::document(true);
        if (!is_array($document) || !isset($document['atf_images'][$device]) || !is_array($document['atf_images'][$device])) {
            return [];
        }
        $tops = [];
        foreach ($document['atf_images'][$device] as $entry) {
            if (!is_array($entry) || empty($entry['stem']) || !is_string($entry['stem']) || !isset($entry['top'])) {
                continue;
            }
            $stem = strtolower($entry['stem']);
            $top = (int) $entry['top'];
            if (!isset($tops[$stem]) || $top < $tops[$stem]) {
                $tops[$stem] = $top;
            }
        }
        return $tops;
    }

    /**
     * What must be visible at first paint on this page for one device: a list of
     * ['sel' => selector, 'props' => [property => value]] from `atf_reveal[dev].items`, at most
     * 24, each selector at most 512 characters with none of `{ } < > @ \`, and only opacity,
     * visibility and display, each value at most 64 characters of [a-z0-9 .%-]. An item with
     * nothing left is dropped.
     *
     * When the service observed no reveal items for the device, the answer is the page's LCP
     * element made visible: the loader's reveal sweep would otherwise hold it until its
     * IntersectionObserver or its 1200 ms timer fired, and that second commit costs the ~1 s
     * presentation quantum (live PSI: render delay 320 ms against 1,480 ms, same bytes).
     */
    public static function revealRules($device)
    {
        $document = self::document(true);
        if (!is_array($document)) {
            return [];
        }
        $items = isset($document['atf_reveal'][$device]['items']) ? $document['atf_reveal'][$device]['items'] : null;
        if (empty($items) || !is_array($items)) {
            $lcpNode = isset($document['lcp_element'][$device]) ? self::with_proven_selector($document['lcp_element'][$device]) : [];
            $lcpSelector = isset($lcpNode['sel']) ? trim((string) $lcpNode['sel']) : '';
            if ($lcpSelector !== '' && strlen($lcpSelector) <= 512 && !preg_match('/[{}<>@\\\\]/', $lcpSelector)) {
                return [['sel' => $lcpSelector, 'props' => ['visibility' => 'visible']]];
            }
            return [];
        }
        $rules = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['sel']) || empty($item['props']) || !is_array($item['props'])) {
                continue;
            }
            $selector = trim((string) $item['sel']);
            if ($selector === '' || strlen($selector) > 512 || preg_match('/[{}<>@\\\\]/', $selector)) {
                continue;
            }
            $props = [];
            foreach ($item['props'] as $property => $value) {
                $property = strtolower(trim((string) $property));
                if (!in_array($property, ['opacity', 'visibility', 'display'], true)) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value === '' || strlen($value) > 64 || !preg_match('/^[a-z0-9 .%-]+$/i', $value)) {
                    continue;
                }
                $props[$property] = $value;
            }
            if (empty($props)) {
                continue;
            }
            $rules[] = ['sel' => $selector, 'props' => $props];
            if (count($rules) >= 24) {
                break;
            }
        }
        return $rules;
    }

    /**
     * Elements near the top of this page whose height shrinks between paint and settle (a
     * script-collapsed header), from `atf_conceal`: a list of ['sel' => selector, 'height' =>
     * the settled height, 'sel_unique' => true]. The per-device list, or a flat one for both
     * devices; the service's {items: [...]} wrapper is unwrapped. The height is the first numeric
     * of final_h, h, height, box_h, then box[1] or box.h; an entry with no selector, no height or
     * no `sel_unique: true` is left out (with_proven_selector). Bounds stay with the caller.
     */
    public static function concealedBoxes($device)
    {
        $document = self::document(true);
        if (!is_array($document) || !isset($document['atf_conceal']) || !is_array($document['atf_conceal'])) {
            return [];
        }
        $entries = [];
        if (isset($document['atf_conceal'][$device]) && is_array($document['atf_conceal'][$device])) {
            $entries = $document['atf_conceal'][$device];
        } elseif (!isset($document['atf_conceal']['desktop'])) {
            $entries = $document['atf_conceal'];
        }
        if (isset($entries['items']) && is_array($entries['items'])) {
            $entries = $entries['items'];
        }
        $boxes = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $selector = isset($entry['sel']) ? trim((string) $entry['sel']) : '';
            $height = null;
            foreach (['final_h', 'h', 'height', 'box_h'] as $heightKey) {
                if (isset($entry[$heightKey]) && is_numeric($entry[$heightKey])) {
                    $height = (float) $entry[$heightKey];
                    break;
                }
            }
            if ($height === null && isset($entry['box']) && is_array($entry['box'])) {
                if (isset($entry['box'][1]) && is_numeric($entry['box'][1])) {
                    $height = (float) $entry['box'][1];
                } elseif (isset($entry['box']['h']) && is_numeric($entry['box']['h'])) {
                    $height = (float) $entry['box']['h'];
                }
            }
            if ($selector === '' || $height === null || ($entry['sel_unique'] ?? null) !== true) {
                continue;
            }
            $boxes[] = ['sel' => $selector, 'height' => $height, 'sel_unique' => true];
        }
        return $boxes;
    }

    /**
     * selector => the service's `sel_unique` verdict, harvested from every node of this page's
     * lcp.json that carries both (the field sits on lcp_element but not always on the entry that
     * shares its selector). A false anywhere wins over a true elsewhere: non-uniqueness is the
     * dangerous direction, so one node proving it outranks another node's true.
     */
    public static function selectorVerdicts()
    {
        $document = self::document(true);
        $verdicts = [];
        if (is_array($document)) {
            self::harvest_selector_verdicts($document, $verdicts);
        }
        return $verdicts;
    }

    private static function harvest_selector_verdicts($node, &$verdicts)
    {
        if (isset($node['sel']) && is_string($node['sel']) && $node['sel'] !== ''
            && array_key_exists('sel_unique', $node)) {
            $selector = $node['sel'];
            if (!isset($verdicts[$selector]) || $verdicts[$selector]) {
                $verdicts[$selector] = (bool) $node['sel_unique'];
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                self::harvest_selector_verdicts($child, $verdicts);
            }
        }
    }

    /**
     * Every selector this page's lcp.json names above the fold, both devices: the LCP element's
     * `lcp_element[dev].sel`, then each `atf_images` entry's `sel` (the per-device lists, or a
     * flat list). Laid out or not: the question is where the content is, not how big it is.
     * Unvalidated strings; the caller extracts the ids it can address.
     */
    public static function aboveFoldSelectors()
    {
        $document = self::document(true);
        if (!is_array($document)) {
            return [];
        }
        $selectors = [];
        if (isset($document['lcp_element']) && is_array($document['lcp_element'])) {
            foreach (['mobile', 'desktop'] as $device) {
                if (!empty($document['lcp_element'][$device]['sel'])) {
                    $selectors[] = (string) $document['lcp_element'][$device]['sel'];
                }
            }
        }
        if (isset($document['atf_images']) && is_array($document['atf_images'])) {
            foreach (['mobile', 'desktop'] as $device) {
                $list = isset($document['atf_images'][$device]) ? $document['atf_images'][$device] : $document['atf_images'];
                foreach ((array) $list as $entry) {
                    if (is_array($entry) && !empty($entry['sel'])) {
                        $selectors[] = (string) $entry['sel'];
                    }
                }
            }
        }
        return $selectors;
    }

    /**
     * The decoded lcp.json for this request, or false when there is none to read. $page true
     * reads the page's own file only; false, the page's or the home page's (WHICH FILE).
     */
    private static function document($page = false)
    {
        $source = $page ? 'page' : 'page-or-home';
        if (isset(self::$documents[$source])) {
            return self::$documents[$source];
        }
        self::$documents[$source] = false;
        try {
            $file = self::file($page);
            if ($file === '' || !@is_readable($file)) {
                return self::$documents[$source];
            }
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                self::$documents[$source] = $decoded;
            }
        } catch (\Throwable $e) {
            self::$documents[$source] = false;
        }
        return self::$documents[$source];
    }

    /**
     * The lcp.json path for this request. `wps_rewriteLogic::wpc_lcp_json_file()` is the
     * thorough resolver — request key, then the home key, then the crit folder the desktop
     * critical file sits in — and is used when it is loaded; with $page, its page-only twin
     * `wpc_page_lcp_json_file()`, which never falls back to the home key. The fallback
     * reproduces the bare $_SERVER key that the afold and Smart Delivery readers built by
     * hand, for the contexts (the cache drop-in among them) where WP's home_url() is not
     * available yet.
     */
    private static function file($page = false)
    {
        $resolver = $page ? 'wpc_page_lcp_json_file' : 'wpc_lcp_json_file';
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', $resolver)) {
            $file = (string) wps_rewriteLogic::$resolver();
            if ($file !== '') {
                return $file;
            }
        }
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return '';
        }
        $url = (function_exists('is_ssl') && is_ssl() ? 'https://' : 'http://')
            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
            . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
        $key = (string) (new wps_ic_url_key())->setup($url);
        if ($key === '') {
            return '';
        }
        return rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/lcp.json';
    }

    /**
     * The `sizes` value for a large or above-the-fold image this page has no observation for:
     * `(max-width: Wpx) 100vw, Wpx`, W being the image's own width — the `width` attribute, or
     * the widest candidate in the page's own srcset when the tag has none. '' when neither
     * says. Every lane that writes an LCP / hero `sizes` without a measurement calls this; a
     * measured slot (widths() above) still wins wherever one exists.
     *
     * Rule: the ceiling is the image's own width, never the theme's content width, and the
     * phone step is 100vw. The five lanes used to write `(max-width: 600px) 50vw,
     * (max-width: 1024px) 40vw, {content width}px` (or clamp WordPress's own default down to
     * the content width), which assumed the hero sits in the post column: on acrystalglass.com
     * (2026-09-24, Elementor on a classic theme, `$content_width = 640`) a hero painted about
     * 2,000 px wide was served the 640x427 rung and stretched. Nothing in the render can tell a
     * content-column image from a full-bleed one, so no lane caps at the column any more.
     */
    public static function fallback_sizes($width_attr, $srcset = '')
    {
        $width = preg_match('/^\s*(\d+)\s*(?:px)?\s*$/i', (string) $width_attr, $wm) ? (int) $wm[1] : 0;
        if ($width <= 0 && preg_match_all('/\s(\d+)w\s*(?:,|$)/', ' ' . (string) $srcset, $cm)) {
            $width = (int) max(array_map('intval', $cm[1]));
        }
        return $width > 0 ? '(max-width: ' . $width . 'px) 100vw, ' . $width . 'px' : '';
    }

    /** @var int  Retired ladders replaced this request, by any of the four callers; read into
     *            the render's img-sizing receipt. */
    public static $retiredLaddersReplaced = 0;

    /**
     * `$sizes` unchanged, unless it is the capped ladder this plugin used to write,
     * `[auto, ](max-width: 600px) 50vw, (max-width: 1024px) 40vw, Npx`: that becomes
     * fallback_sizes() for the same image, keeping WordPress's `auto, ` prefix when it had one
     * ('' when the image's width is unknown). The ladder was printed into the page by
     * WordPress itself (through the plugin's `wp_calculate_image_sizes` filter), so a stored
     * page or a builder's saved markup can still carry it after the update; it was the
     * plugin's guess, never the theme's.
     */
    public static function replace_retired_capped_ladder($sizes, $width_attr, $srcset = '')
    {
        $sizes = trim((string) $sizes);
        if (!preg_match('/^(auto, *)?\(max-width: *600px\) *50vw, *\(max-width: *1024px\) *40vw, *\d+px$/i', $sizes, $lm)) {
            return $sizes;
        }
        self::$retiredLaddersReplaced++;
        $ownWidthSizes = self::fallback_sizes($width_attr, $srcset);
        return ($ownWidthSizes !== '' && !empty($lm[1])) ? 'auto, ' . $ownWidthSizes : $ownWidthSizes;
    }
    /** Drops the per-request memo. For scratch checks and the v2 health endpoint only. */
    public static function reset()
    {
        self::$documents = [];
        self::$rows = [];
        self::$widths = [];
        self::$multiUse = [];
        self::$naturalSizes = null;
    }
}
