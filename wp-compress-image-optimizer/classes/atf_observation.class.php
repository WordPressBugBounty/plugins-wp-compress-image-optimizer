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
 *   lcpBackgroundUrl($device)   the file of the page's own measured LCP when it is a CSS background image, '' otherwise
 *   lcpPaintedUrl($device)      the file the service's browser fetched to paint the page's own background hero, '' otherwise
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
 *   heroFold()                  the per-site switch (policy.hero_fold) every reader below that changes an answer asks
 *   phoneHeroOn()               the per-site switch of the phone-width hero (policy.phone_hero)
 *   phoneHero($device)          the width-w rendition of the hero the service asks a phone to be given
 *                               (lcp_element.mobile.rendition), the page's own lcp.json only
 *   bgMobileSourceOn()          the per-site switch of the phone's background source (policy.bg_mobile_src)
 *   bgMobileSourceNamed($device) whether the page's own lcp.json says the service swapped that size in, whatever the switch says
 *   bgMobileSource($device)     the smaller WordPress size the service paints a phone's background hero from
 *                               (lcp_element.mobile.bg_mobile_src), validated, the page's own lcp.json only
 *   heroLqipOn()                the per-site switch of the phone's inline hero placeholder (policy.hero_lqip)
 *   heroLqip($device)           the placeholder record of the phone's background hero (lcp_element.mobile.hero_lqip),
 *                               validated, the page's own lcp.json only
 *   heroOneUrlOn()              the per-site switch of the phone hero's one file (policy.hero_one_url)
 *   heroPhoneUrl()              the one file a phone's background hero is named by in every writer: bgMobileSource's `to`, else the
 *                               cheapest file the measurement names for that picture
 *   heroOwnForm($url)           $url renamed to that file in the form it was written, '' when it already is, names another picture or there is no answer
 *   heroCostsMore($url, $ref)   whether the file $url names costs more bytes than the file $ref names (the disk, then the width)
 *   paintSelector($device)      the selector a background pin goes on: the proven `sel`, with its pseudo-element when
 *                               the service named one for that same selector
 *   lcpHeld($device)            the service holds this device's background preload back (lever_view.action hold)
 *   lcpUnknown($device)         the service says in so many words that it could not tell this device's LCP
 *   foldFacts($device)          what the page's own lcp.json says about the first screen, with the stamps that
 *                               tell "nothing is there" from "nobody looked" (leg_status, atf_cut, carried_from)
 *   lcpKind($device)            'img' | 'other' | 'none': what the page's own lcp.json says the LCP is
 *   needPx($device)             the width the hero must be stored at, when the service says
 *   cvContainers($device)       the containers below the first screen the service proved can skip rendering
 *                               (`cv_containers`, crit ask 116), with the state of the field for that device
 *   interactive($device)       the sliders and menu toggles on the first screen and the scripts that bind them
 *                               (`atf_interactive`, crit ask 105), with the state of the field for that device
 *   fontPreloadUnused($device)  the page's font preloads whose face painted no glyph on that device's first screen
 *                               (`font_preload_unused`, crit ask 116), with the state of the field for that device
 *   atfHandover()               the hand-over script of the first-screen faces contract (`atf_handover`, crit ask 067),
 *                               with the state of the field; the script text is answered only when it is a pinned one
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

    const ATF_COMPLETE_SELF_LIFT_SVC = '3.198.365';

    const HERO_LQIP_IMAGE_MAX = 10240;
    const HERO_LQIP_INLINE_MAX = 12288;
    const HERO_LQIP_SELECTOR_MAX = 300;
    const HERO_LQIP_LAYERS_MAX = 2048;
    const HERO_LQIP_IMAGES_MAX = 8;

    public static function num($const)
    {
        $levers = [
            'HERO_LQIP_IMAGE_MAX'    => ['hero_lqip_image_max', 2560, 40960],
            'HERO_LQIP_INLINE_MAX'   => ['hero_lqip_inline_max', 3072, 49152],
            'HERO_LQIP_SELECTOR_MAX' => ['hero_lqip_selector_max', 75, 1200],
            'HERO_LQIP_LAYERS_MAX'   => ['hero_lqip_layers_max', 512, 8192],
            'HERO_LQIP_IMAGES_MAX'   => ['hero_lqip_images_max', 2, 32],
        ];
        $const = (string) $const;
        $default = defined(self::class . '::' . $const) ? (int) constant(self::class . '::' . $const) : 0;
        if (!isset($levers[$const]) || !function_exists('wpc_lever_num')) {
            return $default;
        }
        return wpc_lever_num($levers[$const][0], $default, $levers[$const][1], $levers[$const][2]);
    }

    const INTERACTIVE_FIELD = 'atf_interactive';
    const INTERACTIVE_KINDS = ['menu_toggle', 'slider'];
    const INTERACTIVE_ATTRIBS = ['stack', 'handler', 'none'];
    const INTERACTIVE_MAX = 8;

    const FONT_PRELOAD_UNUSED_FIELD = 'font_preload_unused';
    const FONT_PRELOAD_UNUSED_MAX = 40;
    const FONT_PRELOAD_URL_MAX = 2048;

    const ATF_HANDOVER_FIELD = 'atf_handover';
    const ATF_HANDOVER_NAME = 'wpc-atf-handover';
    const ATF_HANDOVER_MAX = 8192;
    const ATF_HANDOVER_PINS = [1 => '87b7a2da4288ea264f08b3944bf1f8445488e46a97f8dce6b27dbd4d7d128293'];

    const CV_CONTAINERS_FIELD = 'cv_containers';
    const CV_CONTAINERS_MAX = 40;
    const CV_SELECTOR_MAX = 240;
    const CV_IDENT = '-?[A-Za-z_][A-Za-z0-9_-]*';
    const CV_SELECTOR = '/\A(?:#' . self::CV_IDENT . '|(?:body|#' . self::CV_IDENT . '|\.' . self::CV_IDENT . ') ?> ?\.' . self::CV_IDENT . '(?:\.' . self::CV_IDENT . ')*)\z/';

    /** Decoded lcp.json for this request per source ('page-or-home', 'page'); false once a read
     *  has been tried and failed. See WHICH FILE on the class. */
    private static $documents = [];

    /** Memoised rows per "scope|device" and resolved width maps per scope. */
    private static $rows = [];
    private static $widths = [];
    private static $multiUse = [];
    private static $naturalSizes = null;
    private static $interactive = [];
    private static $cvContainers = [];
    private static $fontPreloadUnused = [];
    private static $atfHandover = null;
    private static $hero = [];
    private static $heroLqip = [];
    private static $bgDerived = [];

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
        // A held background has no preload to build, whatever the merge above took from lcp[dev].
        if (self::heroFold() && self::documentHolds($document, $device)) {
            unset($element['url'], $element['net_url']);
        }
        return $element;
    }

    /**
     * The file the service measured as the LCP of one device when that LCP is a CSS background
     * image, '' for anything else: an <img>, text, a video, a background the service holds back
     * (lcpElement drops its url), a data: URI, or no observation. Only the page's own lcp.json
     * answers: a page that has none and reads the home page's measures nothing of its own, so the
     * preload writers that act on a measurement act on none.
     */
    public static function lcpBackgroundUrl($device)
    {
        $element = self::lcpElement($device, true);
        if (!isset($element['type'], $element['url']) || !is_string($element['type']) || !is_string($element['url'])
            || strtolower(trim($element['type'])) !== 'bg') {
            return '';
        }
        $url = trim($element['url']);
        return ($url === '' || stripos($url, 'data:') === 0) ? '' : $url;
    }

    /**
     * The file the service's browser fetched to paint one device's hero when that hero is a CSS
     * background (`src` of lcp[device] / lcp_element[device]), '' for anything else: an <img>, text,
     * a video, a background the service holds back, a leg it did not measure, an address that is not
     * an http(s) image on this site's host or its zone, or no observation of the page's own.
     */
    public static function lcpPaintedUrl($device)
    {
        $document = self::document(true);
        if (!is_array($document) || self::documentHolds($document, $device)) {
            return '';
        }
        $leg = (isset($document['leg_status'][$device]) && is_array($document['leg_status'][$device])) ? $document['leg_status'][$device] : [];
        if (array_key_exists('measured', $leg) && empty($leg['measured'])) {
            return '';
        }
        $element = self::lcpElement($device, true);
        if (!isset($element['type'], $element['src']) || !is_string($element['type']) || !is_string($element['src'])
            || strtolower(trim($element['type'])) !== 'bg') {
            return '';
        }
        $src = trim($element['src']);
        if (strlen($src) > 2048 || !preg_match('#^https?://[^/?\#\s"\'<>\\\\]+/[^?\#\s"\'<>\\\\]*\.(?:jpe?g|png|webp|avif|gif)(?:\?src=[a-z0-9]{3,4})?$#i', $src)
            || !self::ownHost((string) parse_url($src, PHP_URL_HOST))) {
            return '';
        }
        return $src;
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
     * stem => the smallest `top` the service measured for that file on this page, for one device
     * only: from `atf_images` (the first screen), and with policy.hero_fold also from `oversized_images`
     * (the full-page list, whose entries are the ones past the first screen). A file seen twice (a header and a footer instance) keeps its
     * highest sighting, so any above-the-fold sighting wins. No cross-device fallback and no
     * flat, pre-per-device list: a desktop top on a mobile render is a measurement of another
     * layout. Every entry counts, laid out or not: the layout test is about the box, and an
     * image the browser never laid out still sits where it sits. Stems are lower-cased.
     */
    public static function topsByStem($device)
    {
        $document = self::document(true);
        if (!is_array($document)) {
            return [];
        }
        $lists = [];
        if (isset($document['atf_images'][$device]) && is_array($document['atf_images'][$device])) {
            $lists[] = $document['atf_images'][$device];
        }
        if (self::heroFold() && isset($document['oversized_images'][$device]) && is_array($document['oversized_images'][$device])) {
            $lists[] = $document['oversized_images'][$device];
        }
        $tops = [];
        foreach ($lists as $list) {
            foreach ($list as $entry) {
                if (!is_array($entry) || empty($entry['stem']) || !is_string($entry['stem']) || !isset($entry['top']) || !is_numeric($entry['top'])) {
                    continue;
                }
                $stem = strtolower($entry['stem']);
                $top = (int) $entry['top'];
                if (!isset($tops[$stem]) || $top < $tops[$stem]) {
                    $tops[$stem] = $top;
                }
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
            $perDevice = self::heroFold() && (array_key_exists('mobile', $document['atf_images']) || array_key_exists('desktop', $document['atf_images']));
            foreach (['mobile', 'desktop'] as $device) {
                if ($perDevice) {
                    $list = (isset($document['atf_images'][$device]) && is_array($document['atf_images'][$device])) ? $document['atf_images'][$device] : [];
                } else {
                    $list = isset($document['atf_images'][$device]) ? $document['atf_images'][$device] : $document['atf_images'];
                }
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
     * The per-site switch of the hero and first-screen readers (policy.hero_fold). Off or absent:
     * every reader answers as it did before this release.
     */
    public static function heroFold()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('hero_fold') === 1;
    }

    /** The phone-fit rung of the Elementor guess is on unless WPC_BG_RUNG_FIT_OFF or cohort off_bg_rung_fit turns it off. */
    public static function bgRungFitOn()
    {
        if (defined('WPC_BG_RUNG_FIT_OFF') && WPC_BG_RUNG_FIT_OFF) {
            return false;
        }
        return !(function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_bg_rung_fit') === 1);
    }

    /** The guess's preload names the file its pin paints unless WPC_BG_RUNG_PAIR_OFF or cohort off_bg_rung_pair turns that off. */
    public static function bgRungPairOn()
    {
        if (defined('WPC_BG_RUNG_PAIR_OFF') && WPC_BG_RUNG_PAIR_OFF) {
            return false;
        }
        return !(function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_bg_rung_pair') === 1);
    }

    /**
     * The width in CSS pixels the service measured for the background LCP of one device when that background is the picture $url names;
     * 0 when nothing is measured, the LCP is another element or picture, or the width is not between 64 and 2048.
     */
    public static function lcpBoxWidth($device, $url)
    {
        $element = self::lcpElement($device);
        $type = (is_array($element) && isset($element['type']) && is_string($element['type'])) ? strtolower(trim($element['type'])) : '';
        if ($type !== 'bg' && $type !== 'bg-img') {
            return 0;
        }
        $key = wps_ic_image_preload_set::imageKey((string) $url);
        if ($key === '' || !isset($element['css_w']) || !is_numeric($element['css_w'])) {
            return 0;
        }
        $width = (int) ceil((float) $element['css_w']);
        $height = isset($element['css_h']) && is_numeric($element['css_h']) ? (int) ceil((float) $element['css_h']) : 0;
        if ($height > $width) {
            $natW = isset($element['nat_w']) && is_numeric($element['nat_w']) ? (float) $element['nat_w'] : 0;
            $natH = isset($element['nat_h']) && is_numeric($element['nat_h']) ? (float) $element['nat_h'] : 0;
            $width = ($natW > 0 && $natH > 0) ? max($width, (int) ceil($height * $natW / $natH)) : 0;
        }
        if ($width < 64 || $width > 2048) {
            return 0;
        }
        foreach (['net_url', 'url'] as $field) {
            if (!empty($element[$field]) && is_string($element[$field]) && wps_ic_image_preload_set::imageKey($element[$field]) === $key) {
                return $width;
            }
        }
        return 0;
    }

    /** The per-site switch that lets a leg the service stamped `atf_complete` count as measured (policy.atf_complete_lazy): foldFacts() is its one reader. */
    public static function atfCompleteLazyOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('atf_complete_lazy') === 1;
    }

    /**
     * The per-site switch of the phone-width hero (policy.phone_hero): the one reader of the
     * cohort, asked by the render and by the dispatch that declares the capability.
     */
    public static function phoneHeroOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('phone_hero') === 1;
    }

    /**
     * The phone-width rendition the service asks the hero of a phone to be painted and preloaded at
     * (`lcp_element.mobile.rendition`, crit 3.198.346, sent only to a site whose dispatch declares
     * capabilities.hero_rendition): ['src' => the hero's file, 'w' => its width in px, 'fit' => 'width'],
     * or [] for anything else. Only the page's OWN lcp.json answers (WHICH FILE on the class), only for
     * 'mobile', and only while policy.phone_hero is on.
     *
     * It answers [] unless the leg is measured and not held, the leg's LCP is an image or a background,
     * `v` is 1, `fit` is 'width', `w` is an integer from 64 to 4096 and `src` is an http(s) address of
     * a jpg, jpeg, png, webp or avif on this site's host or its zone (no query, fragment, space or quote).
     *
     * The service's `w` is in device pixels (the box's CSS width times the ratio it measured at, 1.75 on
     * a phone, rounded up to 8). The width answered is that `w` while the site's Retina setting is on
     * (wpc_retina_on()); with Retina off it is the box's CSS width (`lcp_element.mobile.css_w`, else `w`
     * divided by the measured ratio `meta.mobile_dpr`, else by 1.75), never above `w` and never below 64.
     */
    public static function phoneHero($device)
    {
        if ($device !== 'mobile' || !self::phoneHeroOn()) {
            return [];
        }
        $document = self::document(true);
        if (!is_array($document) || self::documentHolds($document, 'mobile')) {
            return [];
        }
        $leg = (isset($document['leg_status']['mobile']) && is_array($document['leg_status']['mobile'])) ? $document['leg_status']['mobile'] : [];
        if (array_key_exists('measured', $leg) && empty($leg['measured'])) {
            return [];
        }
        $element = (isset($document['lcp_element']['mobile']) && is_array($document['lcp_element']['mobile'])) ? $document['lcp_element']['mobile'] : [];
        $rendition = (isset($element['rendition']) && is_array($element['rendition'])) ? $element['rendition'] : [];
        if (!isset($element['type']) || !in_array($element['type'], ['img', 'bg'], true) || !isset($rendition['v'], $rendition['src'], $rendition['w'], $rendition['fit'])
            || $rendition['v'] !== 1 || $rendition['fit'] !== 'width' || !is_int($rendition['w']) || $rendition['w'] < 64 || $rendition['w'] > 4096
            || !is_string($rendition['src']) || strlen($rendition['src']) > 2048
            || !preg_match('#^https?://[^/?\#\s"\'<>\\\\]+/[^?\#\s"\'<>\\\\]*\.(?:jpe?g|png|webp|avif)$#i', $rendition['src'])
            || !self::ownHost((string) parse_url($rendition['src'], PHP_URL_HOST))) {
            return [];
        }
        $width = $rendition['w'];
        if (function_exists('wpc_retina_on') && !wpc_retina_on()) {
            $ratio = (isset($document['meta']['mobile_dpr']) && is_numeric($document['meta']['mobile_dpr']) && $document['meta']['mobile_dpr'] > 0 && $document['meta']['mobile_dpr'] <= 4)
                ? (float) $document['meta']['mobile_dpr'] : 1.75;
            $slot = (isset($element['css_w']) && is_numeric($element['css_w']) && $element['css_w'] > 0) ? (int) ceil((float) $element['css_w']) : (int) ceil($width / $ratio);
            $width = min($width, max(64, $slot));
        }
        return ['src' => $rendition['src'], 'w' => $width, 'fit' => 'width'];
    }

    /**
     * The per-site switch of the phone's background source (policy.bg_mobile_src): the one reader of the
     * cohort, asked by the render stage that names the file in the sheets a phone is served.
     */
    public static function bgMobileSourceOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('bg_mobile_src') === 1;
    }

    /**
     * The per-site switch of the plugin's own pick of the phone's background source (the cohort phone_bg_derive, off unless set):
     * the one reader, asked by the render stage that derives the record.
     */
    public static function bgDeriveOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('phone_bg_derive') === 1;
    }

    /**
     * Sets this render's derived phone background record (the ok shape of bgMobileSource plus 'src' => 'derived'), or
     * clears it with []. Drops the memoised phone hero file so the next reader sees the record.
     */
    public static function setBgDerived(array $record)
    {
        self::$bgDerived = $record;
        unset(self::$hero['one']);
    }

    /** The derived phone background record of this render, [] when none was set. */
    public static function bgDerived()
    {
        return self::$bgDerived;
    }

    /**
     * The smaller WordPress size a phone's background hero is painted from, one answer for every reader: the service's
     * record (bgMobileSourceCrit) whenever it holds one, ok or refused, else the record this render derived
     * (setBgDerived), else []. Only 'mobile' is answered.
     */
    public static function bgMobileSource($device)
    {
        if ($device !== 'mobile') {
            return [];
        }
        $crit = self::bgMobileSourceCrit($device);
        if ($crit !== []) {
            return $crit;
        }
        return self::$bgDerived;
    }

    /**
     * leg_status[device].lcp_read_reason when crit-push skipped the LCP read on purpose: interior
     * generations run the one-render completeness read and only a sample (OBS_ENRICH_SAMPLE_PCT,
     * default 20) reads the LCP. Written by crit-push lcp-signal.js:94 legStatusOf() (origin/rel403,
     * 3.198.403), chosen at server.js:13923-13924 (obsEnrichVerdict).
     */
    const LCP_READ_SKIPPED_REASON = 'completeness_only';

    /** The width in pixels of the WordPress size a derived phone background record names: the upload's 768-wide size (medium_large), else the smallest registered size wider than that. */
    const BG_DERIVE_WIDTH = 768;

    public static function bgDeriveNeed()
    {
        return self::BG_DERIVE_WIDTH;
    }

    /**
     * The address checks every phone background record shares: '' when `$to` and `$was` are http(s) jpg, jpeg, png, webp
     * or avif addresses of this site's host or its zone (no query or fragment), else 'url' or 'host'.
     */
    public static function bgMobileAddressWhy($to, $was)
    {
        $address = '#^https?://[^/?\#\s"\'<>\\\\]+/[^?\#\s"\'<>\\\\]*\.(?:jpe?g|png|webp|avif)$#i';
        if (!is_string($to) || !is_string($was) || strlen($to) > 2048 || strlen($was) > 2048 || !preg_match($address, $to) || !preg_match($address, $was)) {
            return 'url';
        }
        if (!self::ownHost((string) parse_url($to, PHP_URL_HOST)) || !self::ownHost((string) parse_url($was, PHP_URL_HOST))) {
            return 'host';
        }
        return '';
    }

    /** True when `$url` is a file of this install that can be read. */
    public static function uploadFileExists($url)
    {
        $file = is_string($url) ? self::installFile($url) : '';
        return $file !== '' && @is_file($file);
    }

    /**
     * The file checks every phone background record shares: '' when `$to` is the same upload's `<name>-<W>x<H>.<ext>` in the
     * same folder as `$was`, both are files on this install, the original's size can be read, `$to` is narrower than the
     * original and not narrower than `$need`, and its aspect is within 2% of the original's; else 'name', 'file', 'dims',
     * 'size' or 'aspect'. On '' `$found` holds ['w', 'h', 'file' (where `$to` is on disk)].
     */
    public static function bgMobileSizeWhy($to, $was, $need, &$found)
    {
        $found = [];
        $toPath = (string) parse_url($to, PHP_URL_PATH);
        $wasPath = (string) parse_url($was, PHP_URL_PATH);
        $folder = substr($wasPath, 0, (int) strrpos($wasPath, '/') + 1);
        $wasBase = substr($wasPath, strlen($folder));
        $dot = (int) strrpos($wasBase, '.');
        $name = substr($wasBase, 0, $dot);
        $ext = substr($wasBase, $dot + 1);
        if (strpos($toPath, $folder) !== 0 || !preg_match('#^' . preg_quote($name, '#') . '-(\d{2,5})x(\d{2,5})\.' . preg_quote($ext, '#') . '$#', substr($toPath, strlen($folder)), $size)) {
            return 'name';
        }
        $toFile = self::installFile($to);
        $wasFile = self::installFile($was);
        if ($toFile === '' || $wasFile === '' || !@is_file($toFile) || !@is_file($wasFile)) {
            return 'file';
        }
        $dims = function_exists('wpc_image_file_dims') ? wpc_image_file_dims($wasFile) : null;
        if (!is_array($dims) || (float) $dims[0] <= 0 || (float) $dims[1] <= 0) {
            return 'dims';
        }
        $w = (int) $size[1];
        $h = (int) $size[2];
        if ($w >= (int) $dims[0] || $w < $need) {
            return 'size';
        }
        $aspect = (float) $dims[0] / (float) $dims[1];
        if ($h < 1 || abs(($w / $h) - $aspect) > 0.02 * $aspect) {
            return 'aspect';
        }
        $found = ['w' => $w, 'h' => $h, 'file' => $toFile];
        return '';
    }

    /**
     * The smaller WordPress size the service paints a phone's background hero from
     * (`lcp_element.mobile.bg_mobile_src`, crit 3.198.345): {v: 1, to, from, was: {url, net_url, entry_url},
     * need_px, kind: 'wp_intermediate'}, every address an origin address of this site. [] for any device but
     * 'mobile', while policy.bg_mobile_src is off, for a leg that is held or not measured and for a leg that
     * carries no record. Only the page's OWN lcp.json answers (WHICH FILE on the class).
     *
     * A record this site cannot trust is answered as ['state' => 'refused', 'why' => ...] and the page is left
     * as it is: the leg's element is not a background, `v` is not 1, `kind` is not wp_intermediate, an address
     * is not an http(s) jpg, jpeg, png, webp or avif on this site's host or its zone (no query or fragment),
     * `from` names another file than `was.url`, the element's own url is not `to`, `to` is not the same
     * upload's `<name>-<W>x<H>.<ext>` in the same folder as `was.url`, either file is not a file on this
     * install, the original's size cannot be read, `to` is not narrower than the original or than `need_px`
     * asks, or its aspect differs from the original's by more than 2%.
     * Otherwise ['state' => 'ok', 'to', 'was', 'w', 'h', 'need_px', 'file' (where `to` is on disk), 'src' => 'crit'].
     */
    public static function bgMobileSourceCrit($device)
    {
        if ($device !== 'mobile' || !self::bgMobileSourceOn()) {
            return [];
        }
        $document = self::document(true);
        if (!is_array($document) || self::documentHolds($document, 'mobile')) {
            return [];
        }
        $leg = (isset($document['leg_status']['mobile']) && is_array($document['leg_status']['mobile'])) ? $document['leg_status']['mobile'] : [];
        if (array_key_exists('measured', $leg) && empty($leg['measured'])) {
            return [];
        }
        $element = (isset($document['lcp_element']['mobile']) && is_array($document['lcp_element']['mobile'])) ? $document['lcp_element']['mobile'] : [];
        if (!isset($element['bg_mobile_src']) || !is_array($element['bg_mobile_src'])) {
            return [];
        }
        $record = $element['bg_mobile_src'];
        $refuse = static function ($why) {
            return ['state' => 'refused', 'why' => $why];
        };
        $to = isset($record['to']) ? $record['to'] : null;
        $was = (isset($record['was']) && is_array($record['was']) && isset($record['was']['url'])) ? $record['was']['url'] : null;
        $need = isset($record['need_px']) ? $record['need_px'] : null;
        if (!isset($record['v']) || $record['v'] !== 1 || !isset($record['kind']) || $record['kind'] !== 'wp_intermediate'
            || !is_string($to) || !is_string($was) || !is_int($need) || $need < 1 || $need > 8192) {
            return $refuse('shape');
        }
        if (!isset($element['type']) || $element['type'] !== 'bg') {
            return $refuse('type');
        }
        $why = self::bgMobileAddressWhy($to, $was);
        if ($why !== '') {
            return $refuse($why);
        }
        if (isset($record['from']) && $record['from'] !== $was) {
            return $refuse('from');
        }
        if (!isset($element['url']) || $element['url'] !== $to) {
            return $refuse('element');
        }
        $why = self::bgMobileSizeWhy($to, $was, $need, $found);
        if ($why !== '') {
            return $refuse($why);
        }
        return ['state' => 'ok', 'to' => $to, 'was' => $was, 'w' => $found['w'], 'h' => $found['h'], 'need_px' => $need, 'file' => $found['file'], 'src' => 'crit'];
    }

    /**
     * Whether the page's OWN lcp.json says the service swapped a smaller size into the phone's background: the leg's element is a
     * background whose own url is the `to` of a well-formed record (v 1, kind wp_intermediate, `to` and `was.url` strings), whatever
     * the policy switch says. The measured file of such a leg is the size the service painted, not the file the page's CSS names.
     */
    public static function bgMobileSourceNamed($device)
    {
        if ($device !== 'mobile') {
            return false;
        }
        $document = self::document(true);
        $element = (is_array($document) && isset($document['lcp_element']['mobile']) && is_array($document['lcp_element']['mobile'])) ? $document['lcp_element']['mobile'] : [];
        $record = (isset($element['bg_mobile_src']) && is_array($element['bg_mobile_src'])) ? $element['bg_mobile_src'] : [];
        return $record !== [] && isset($element['type'], $element['url'], $record['v'], $record['kind'], $record['to'], $record['was']['url'])
            && $element['type'] === 'bg' && $record['v'] === 1 && $record['kind'] === 'wp_intermediate'
            && is_string($record['to']) && is_string($record['was']['url']) && $element['url'] === $record['to'];
    }

    /**
     * The per-site switch of the phone's inline hero placeholder (policy.hero_lqip): the one reader of
     * the cohort, asked by the render stage that prints the placeholder, the dispatch that declares the
     * capability and the land report.
     */
    public static function heroLqipOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('hero_lqip') === 1;
    }

    /**
     * The inline placeholder of the phone's background hero (`lcp_element.mobile.hero_lqip`):
     * {v: 2, kind: 'hero_lqip', sel, layers_tpl, token, media, images: [{type, uri}, ...], hero_url, sig}. [] for any device but 'mobile' and while policy.hero_lqip
     * is off. A page the placeholder is not written on is answered as ['state' => 'skipped', 'why' => ...]: `combined-crit` (a combined-crit render), `no-document` (a page with no
     * lcp.json of its own), `held` (a held leg), `unmeasured` (a leg not measured) or `no-record` (a leg that carries no record). Only the page's OWN lcp.json answers.
     *
     * A record this site cannot trust is answered as ['state' => 'refused', 'why' => ...] and the page is
     * left as it is: `shape` (not v 2, `kind` not 'hero_lqip', a field missing or not a string, `token` not 1 to 32 of A-Z a-z 0-9 { } _ -, or `images` not a list), `type` (the leg's element is
     * not a background), `pseudo` (the box that paints is a pseudo-element), `element` (the picture of `hero_url` is not the picture of any
     * address the leg names its element by, heroLqipFile() with $picture: any size of it counts, the render stage decides the file), `sel` (empty, longer than 300 characters, or anything
     * but compounds of a tag, #ids, .classes and :nth-of-type(N) joined by `>` or a space; the record's selector is its own, not the leg's), `layers` (`layers_tpl` not exactly one `token` in a value of gradients and colours, at most
     * 2048 characters), `sig` (not 6 to 64 of A-Z a-z 0-9 _ -), `media` (not 1 to 200 characters of A-Z a-z 0-9 ( ) space : . , - with
     * a parenthesis), `images` (none, more than 8, an entry that is not an array with a string `type` and a string `uri`, or a `type`
     * listed twice), `image` (no image/webp entry, or an image/webp or image/avif entry whose uri is a data: URI that is not the stated
     * format, not base64, empty, over 10240 bytes, or not a WebP / AVIF file by its first bytes). Entries of any other type are skipped.
     * Otherwise ['state' => 'ok', 'sel', 'layers' (layers_tpl with the token written `{lqip}`), 'media', 'webp' (data: URI), 'avif' (data: URI or ''), 'sig',
     * 'url' (`hero_url`), 'names' (every address the leg names the hero by: hero_url, its url, entry_url, net_url, zone_url, src and the
     * bg_mobile_src pair), 'capped' (true when the AVIF was left out because both files together are over 12288 bytes),
     * 'bytes' (decoded bytes of the files kept)].
     */
    public static function heroLqip($device)
    {
        if ($device !== 'mobile' || !self::heroLqipOn()) {
            return [];
        }
        if (isset(self::$heroLqip[$device])) {
            return self::$heroLqip[$device];
        }
        return self::$heroLqip[$device] = self::heroLqipRecord();
    }

    private static function heroLqipRecord()
    {
        if (!class_exists('wps_rewriteLogic')) {
            return [];
        }
        if (wps_rewriteLogic::wpc_combined_crit_on()) {
            return ['state' => 'skipped', 'why' => 'combined-crit'];
        }
        $document = self::document(true);
        if (!is_array($document)) {
            return ['state' => 'skipped', 'why' => 'no-document'];
        }
        if (self::documentHolds($document, 'mobile')) {
            return ['state' => 'skipped', 'why' => 'held'];
        }
        $leg = (isset($document['leg_status']['mobile']) && is_array($document['leg_status']['mobile'])) ? $document['leg_status']['mobile'] : [];
        if (array_key_exists('measured', $leg) && empty($leg['measured'])) {
            return ['state' => 'skipped', 'why' => 'unmeasured'];
        }
        $container = (isset($document['lcp_element']['mobile']) && is_array($document['lcp_element']['mobile'])) ? $document['lcp_element']['mobile'] : [];
        if (!array_key_exists('hero_lqip', $container)) {
            return ['state' => 'skipped', 'why' => 'no-record'];
        }
        $refuse = static function ($why) {
            return ['state' => 'refused', 'why' => $why];
        };
        $record = $container['hero_lqip'];
        if (!is_array($record) || !isset($record['v']) || $record['v'] !== 2
            || !isset($record['kind'], $record['sel'], $record['layers_tpl'], $record['token'], $record['media'], $record['hero_url'], $record['sig'], $record['images'])
            || $record['kind'] !== 'hero_lqip'
            || !is_string($record['sel']) || !is_string($record['layers_tpl']) || !is_string($record['token']) || !is_string($record['media']) || !is_string($record['hero_url']) || !is_string($record['sig'])
            || !preg_match('/^[A-Za-z0-9{}_\-]{1,32}$/D', $record['token'])
            || !is_array($record['images']) || array_values($record['images']) !== $record['images']) {
            return $refuse('shape');
        }
        $element = self::lcpElement('mobile', true);
        if (!isset($element['type']) || $element['type'] !== 'bg') {
            return $refuse('type');
        }
        if (isset($element['pseudo']) && is_string($element['pseudo']) && $element['pseudo'] !== '') {
            return $refuse('pseudo');
        }
        $heroPicture = self::heroLqipFile($record['hero_url'], true);
        $ownsHero = false;
        if ($heroPicture !== '') {
            foreach (['url', 'entry_url', 'net_url', 'zone_url', 'src'] as $field) {
                if (isset($element[$field]) && is_string($element[$field]) && self::heroLqipFile($element[$field], true) === $heroPicture) {
                    $ownsHero = true;
                }
            }
        }
        if (!$ownsHero) {
            return $refuse('element');
        }
        $sel = $record['sel'];
        $compound = '(?=[A-Za-z#.])(?:[A-Za-z][A-Za-z0-9-]*)?(?:[#.][A-Za-z_-][A-Za-z0-9_-]*)*(?::nth-of-type\(\d+\))*';
        if ($sel === '' || strlen($sel) > self::num('HERO_LQIP_SELECTOR_MAX')
            || !preg_match('/^' . $compound . '(?:(?: *> *| +)' . $compound . ')*$/D', $sel)) {
            return $refuse('sel');
        }
        $token = $record['token'];
        $template = $record['layers_tpl'];
        $rest = str_replace($token, '', $template);
        if (strlen($template) > self::num('HERO_LQIP_LAYERS_MAX') || substr_count($template, $token) !== 1
            || !preg_match('/^[A-Za-z0-9#%(),.\s+\-\/]*$/', $rest)
            || (preg_match_all('/([A-Za-z-]+)\s*\(/', $rest, $functions) && array_diff(array_map('strtolower', $functions[1]), ['linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient', 'rgb', 'rgba', 'hsl', 'hsla']) !== [])) {
            return $refuse('layers');
        }
        $layers = str_replace($token, '{lqip}', $template);
        if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/D', $record['sig'])) {
            return $refuse('sig');
        }
        $media = $record['media'];
        if (strlen($media) < 1 || strlen($media) > 200 || !preg_match('/^[A-Za-z0-9() :.,\-]+$/D', $media) || strpos($media, '(') === false) {
            return $refuse('media');
        }
        $images = $record['images'];
        if ($images === [] || count($images) > self::num('HERO_LQIP_IMAGES_MAX')) {
            return $refuse('images');
        }
        $uris = [];
        foreach ($images as $entry) {
            if (!is_array($entry) || !isset($entry['type'], $entry['uri']) || !is_string($entry['type']) || !is_string($entry['uri']) || array_key_exists($entry['type'], $uris)) {
                return $refuse('images');
            }
            $uris[$entry['type']] = $entry['uri'];
        }
        if (!isset($uris['image/webp'])) {
            return $refuse('image');
        }
        $webp = self::heroLqipImage($uris['image/webp'], 'webp');
        $avif = isset($uris['image/avif']) ? self::heroLqipImage($uris['image/avif'], 'avif') : '';
        if ($webp === null || $avif === null) {
            return $refuse('image');
        }
        $keepAvif = $avif !== '';
        $capped = false;
        if ($keepAvif && strlen($webp) + strlen($avif) > self::num('HERO_LQIP_INLINE_MAX')) {
            $keepAvif = false;
            $capped = true;
        }
        $names = [$record['hero_url']];
        foreach (['url', 'entry_url', 'net_url', 'zone_url', 'src'] as $field) {
            if (isset($element[$field]) && is_string($element[$field])) {
                $names[] = $element[$field];
            }
        }
        if (isset($container['bg_mobile_src']) && is_array($container['bg_mobile_src'])) {
            foreach ([isset($container['bg_mobile_src']['to']) ? $container['bg_mobile_src']['to'] : null, isset($container['bg_mobile_src']['was']['url']) ? $container['bg_mobile_src']['was']['url'] : null] as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }
        return [
            'state'  => 'ok',
            'sel'    => $sel,
            'layers' => $layers,
            'media'  => $media,
            'webp'   => $uris['image/webp'],
            'avif'   => $keepAvif ? $uris['image/avif'] : '',
            'sig'    => $record['sig'],
            'url'    => $record['hero_url'],
            'names'  => array_values(array_unique($names)),
            'capped' => $capped,
            'bytes'  => strlen($webp) + ($keepAvif ? strlen($avif) : 0),
        ];
    }

    /**
     * The file an address names, for the comparison of the placeholder's `hero_url` with the leg's own addresses: the path from
     * /wp-content/ (the basename when there is none), the zone transform wrapper (/u:https://..., /m:0/a:https://...) unwrapped,
     * url-decoded and lower-cased, a .avif / .webp twin suffix and the original extension stripped. Host and query are ignored and
     * the size suffix (-WxH) is kept, so two sizes of one picture are two files; with $picture the size suffix and -scaled / -rotated
     * are stripped too, so every size of one upload is one picture. '' when the address carries no path.
     */
    public static function heroLqipFile($url, $picture = false)
    {
        $u = html_entity_decode((string) $url, ENT_QUOTES);
        $inner = strrpos($u, '/u:http');
        if ($inner !== false) {
            $u = substr($u, $inner + 3);
        }
        if (preg_match('#^https?://[^/]+/m:0/a:(https?://.+)$#i', $u, $wrapped)) {
            $u = $wrapped[1];
        }
        $path = (string) parse_url($u, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }
        $at = strrpos($path, '/wp-content/');
        $id = rawurldecode($at !== false ? substr($path, $at) : basename($path));
        $id = (string) preg_replace('/\.(avif|webp)$/i', '', $id);
        $id = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $id);
        if ($picture) {
            $id = (string) preg_replace('/-(?:scaled|rotated)$/i', '', (string) preg_replace('/-\d+x\d+$/', '', $id));
        }
        return strlen($id) > 1 ? strtolower($id) : '';
    }

    /**
     * The decoded file of a placeholder data: URI of the stated format ('webp' or 'avif'), or null when
     * the string is not that: not a base64 data: URI of that subtype, empty, over 10240 bytes, or a file
     * whose first bytes are not the format's own (RIFF....WEBP; ftyp with avif or avis in the first 32).
     */
    private static function heroLqipImage($uri, $format)
    {
        if (strlen($uri) > 16000 || !preg_match('#^data:image/(webp|avif);base64,([A-Za-z0-9+/]+={0,2})$#D', $uri, $parts) || $parts[1] !== $format) {
            return null;
        }
        $bytes = base64_decode($parts[2], true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::num('HERO_LQIP_IMAGE_MAX')) {
            return null;
        }
        if ($format === 'webp') {
            return (strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') ? $bytes : null;
        }
        $head = substr($bytes, 0, 32);
        return (strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp' && (strpos($head, 'avif') !== false || strpos($head, 'avis') !== false)) ? $bytes : null;
    }

    /** The file on this install that an address on the site's own host names, '' when it is not inside the install. */
    private static function installFile($url)
    {
        if (!function_exists('wpc_install_file_real') || !defined('ABSPATH')) {
            return '';
        }
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        if ($path === '' || $path[0] !== '/' || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            return '';
        }
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        if (is_array($uploads) && !empty($uploads['baseurl']) && !empty($uploads['basedir'])) {
            $basePath = rtrim((string) parse_url((string) $uploads['baseurl'], PHP_URL_PATH), '/');
            if ($basePath !== '' && strpos($path, $basePath . '/') === 0) {
                return wpc_install_file_real(rtrim((string) $uploads['basedir'], '/') . substr($path, strlen($basePath)));
            }
        }
        $sitePath = function_exists('site_url') ? rtrim((string) parse_url((string) site_url('/'), PHP_URL_PATH), '/') : '';
        $relative = ($sitePath !== '' && strpos($path, $sitePath . '/') === 0) ? substr($path, strlen($sitePath)) : $path;
        return wpc_install_file_real(rtrim((string) ABSPATH, '/') . $relative);
    }

    /**
     * The per-site switch of the phone hero's one file (policy.hero_one_url): the one reader of the cohort, asked by
     * every writer that names the file a phone paints its background hero from.
     */
    public static function heroOneUrlOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('hero_one_url') === 1;
    }

    /**
     * The one file a phone's background hero is named by in every writer (the preload, the pin, the crit text, the
     * starters): ['source' => 'bg_mobile_src', 'url' => the record's `to`, 'was' => its original, 'key'] while the service
     * swapped a smaller size in (bgMobileSource says ok), else ['source' => 'crit', 'url', 'key'], the file the
     * service's measurement names for that picture (`net_url`, `url`, `entry_url`, `src`, `fetched_url` of lcp_element.mobile,
     * the page's own lcp.json only) that costs the fewest bytes (heroCostsMore). [] for any device but 'mobile', while
     * policy.hero_one_url is off and no derived record is set, for a leg that is held or not measured, a leg whose LCP is not a background, and a
     * measurement that names no address of this site.
     */
    public static function heroPhoneUrl($device = 'mobile')
    {
        if ($device !== 'mobile' || (!self::heroOneUrlOn() && self::$bgDerived === []) || !class_exists('wps_ic_image_preload_set')) {
            return [];
        }
        if (array_key_exists('one', self::$hero)) {
            return self::$hero['one'];
        }
        self::$hero['one'] = [];
        $record = self::bgMobileSource('mobile');
        if ($record !== [] && $record['state'] === 'ok') {
            return self::$hero['one'] = ['source' => 'bg_mobile_src', 'url' => $record['to'], 'was' => $record['was'], 'key' => wps_ic_image_preload_set::imageKey($record['to'])];
        }
        if (!self::heroOneUrlOn()) {
            return [];
        }
        $document = self::document(true);
        if (!is_array($document) || self::documentHolds($document, 'mobile')) {
            return [];
        }
        $leg = (isset($document['leg_status']['mobile']) && is_array($document['leg_status']['mobile'])) ? $document['leg_status']['mobile'] : [];
        if (array_key_exists('measured', $leg) && empty($leg['measured'])) {
            return [];
        }
        $element = self::lcpElement('mobile', true);
        if (!isset($element['type']) || $element['type'] !== 'bg') {
            return [];
        }
        $address = '#^https?://[^/?\#\s"\'<>\\\\]+/[^?\#\s"\'<>\\\\]*\.(?:jpe?g|png|webp|avif)(?:\?src=[a-z0-9]{3,4})?$#i';
        $named = [];
        foreach (['net_url', 'url', 'entry_url', 'src', 'fetched_url'] as $field) {
            if (isset($element[$field]) && is_string($element[$field])) {
                $value = trim($element[$field]);
                if (strlen($value) <= 2048 && preg_match($address, $value) && self::ownHost((string) parse_url($value, PHP_URL_HOST))) {
                    $named[] = $value;
                }
            }
        }
        if ($named === []) {
            return [];
        }
        $key = wps_ic_image_preload_set::imageKey($named[0]);
        $same = [];
        foreach ($named as $value) {
            if ($key !== '' && wps_ic_image_preload_set::imageKey($value) === $key) {
                $same[] = $value;
            }
        }
        if ($same === []) {
            return [];
        }
        $cheapest = $same[0];
        foreach ($same as $value) {
            if (self::heroCostsMore($cheapest, $value)) {
                $cheapest = $value;
            }
        }
        $rung = self::heroCheapestRung($cheapest, $element);
        if ($rung !== '') {
            $cheapest = $rung;
        }
        return self::$hero['one'] = ['source' => 'crit', 'url' => $cheapest, 'key' => $key];
    }

    /**
     * The rendition of the hero's picture on this install that costs the fewest bytes among those at least as wide as the phone needs
     * (the upload and every -WxH size of it with the aspect of the file the measurement named), written in the form of $sample, or '' when
     * no such file can be told apart: fewer than two candidates, no format every candidate has on the disk, or no known width to cover.
     * Bytes are read in the first format every candidate has (avif, webp, then the upload's own); for the file the service fetched, the
     * bytes it measured (`fetched_bytes`) stand in for its disk size while that format is a next-gen one.
     */
    private static function heroCheapestRung($sample, array $element)
    {
        $inner = self::heroInner($sample);
        $path = (string) parse_url($inner, PHP_URL_PATH);
        $name = self::heroName($inner);
        if ($name === '' || strpos($path, '/wp-content/') === false || strpos($path, '..') !== false) {
            return '';
        }
        $need = self::heroNeed($element);
        $stem = (string) preg_replace('/-\d{2,5}x\d{2,5}$/', '', $name);
        $folder = rawurldecode(substr($path, 0, (int) strrpos($path, '/') + 1));
        $dir = '';
        foreach (['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif'] as $format) {
            $file = self::installFile($folder . $name . '.' . $format);
            if ($file !== '' && @is_file($file)) {
                $dir = dirname($file);
                break;
            }
        }
        if ($need < 64 || $stem === '' || $dir === '') {
            return '';
        }
        $reference = self::heroAspect($folder . $name . '.jpg');
        if ($reference <= 0) {
            return '';
        }
        $names = [];
        foreach ((array) @glob($dir . '/' . preg_replace('/([\[\]*?\\\\])/', '\\\\$1', $stem) . '*') as $file) {
            $base = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($file));
            if (($base === $stem || preg_match('/^' . preg_quote($stem, '/') . '-\d{2,5}x\d{2,5}$/', $base)) && !in_array($base, $names, true)) {
                $names[] = $base;
            }
        }
        $fetched = (isset($element['fetched_url']) && is_string($element['fetched_url'])) ? self::heroName($element['fetched_url']) : '';
        $fetchedBytes = (isset($element['fetched_bytes']) && (is_int($element['fetched_bytes']) || (is_string($element['fetched_bytes']) && ctype_digit($element['fetched_bytes'])))) ? (int) $element['fetched_bytes'] : 0;
        $candidates = [];
        foreach ($names as $base) {
            $address = $folder . $base . '.jpg';
            $aspect = self::heroAspect($address);
            if ($aspect <= 0 || abs($aspect - $reference) > 0.02 * $reference || self::heroWidth($address) < $need) {
                continue;
            }
            $bytes = self::heroFileBytes($address);
            if ($bytes !== []) {
                $candidates[] = ['name' => $base, 'bytes' => $bytes];
            }
        }
        if (count($candidates) < 2) {
            return '';
        }
        foreach (['avif', 'webp', 'jpg', 'png', 'gif'] as $format) {
            $best = null;
            foreach ($candidates as $candidate) {
                if (!isset($candidate['bytes'][$format])) {
                    continue 2;
                }
                $size = ($candidate['name'] === $fetched && $fetchedBytes > 0 && $fetchedBytes <= 50000000 && ($format === 'avif' || $format === 'webp'))
                    ? $fetchedBytes : (int) $candidate['bytes'][$format];
                if ($best === null || $size < $best['size'] || ($size === $best['size'] && $candidate['name'] === $name)) {
                    $best = ['name' => $candidate['name'], 'size' => $size];
                }
            }
            return $best['name'] === $name ? '' : self::heroRename($sample, $name, $best['name']);
        }
        return '';
    }

    /** The width in device pixels the phone's hero needs: the file the service asked for (phone_hero), else its measured need (hero_fold), else the box times the ratio it measured at. 0 when none is known. */
    private static function heroNeed(array $element)
    {
        $phone = self::phoneHero('mobile');
        if ($phone !== [] && isset($phone['w'], $phone['src']) && wps_ic_image_preload_set::imageKey($phone['src']) !== ''
            && wps_ic_image_preload_set::imageKey($phone['src']) === wps_ic_image_preload_set::imageKey(isset($element['url']) ? (string) $element['url'] : '')) {
            return (int) $phone['w'];
        }
        $measured = self::heroFold() ? self::needPx('mobile', true) : 0;
        if ($measured > 0) {
            return $measured;
        }
        $dpr = self::measuredDpr('mobile');
        $box = (isset($element['css_w']) && is_numeric($element['css_w'])) ? (float) $element['css_w'] : 0.0;
        return $box > 0 ? (int) ceil($box * ($dpr > 0 ? $dpr : 2)) : 0;
    }

    /** The width over height of the rendition an address names: its -WxH, else the upload's own file; 0 when neither is known. */
    private static function heroAspect($url)
    {
        $name = self::heroName($url);
        if (preg_match('/-(\d{2,5})x(\d{2,5})$/', $name, $size)) {
            return (float) $size[1] / (float) $size[2];
        }
        $path = (string) parse_url(self::heroInner($url), PHP_URL_PATH);
        if ($name === '' || strpos($path, '/wp-content/') === false || strpos($path, '..') !== false || !function_exists('wpc_image_file_dims')) {
            return 0.0;
        }
        $folder = substr($path, 0, (int) strrpos($path, '/') + 1);
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $format) {
            $file = self::installFile(rawurldecode($folder) . $name . '.' . $format);
            if ($file !== '' && @is_file($file)) {
                $dims = wpc_image_file_dims($file);
                if (is_array($dims) && (float) $dims[0] > 0 && (float) $dims[1] > 0) {
                    return (float) $dims[0] / (float) $dims[1];
                }
            }
        }
        return 0.0;
    }

    /** $url with the rendition name $name replaced by $target in the spelling the address was written in; '' when the address does not carry the name. */
    private static function heroRename($url, $name, $target)
    {
        foreach (array_unique([$name, rawurlencode($name)]) as $spelling) {
            $at = strrpos($url, '/' . $spelling . '.');
            if ($at !== false) {
                return substr($url, 0, $at + 1) . rawurlencode($target) . substr($url, $at + 1 + strlen($spelling));
            }
        }
        return '';
    }

    /**
     * $url in the form it was written (host, next-gen twin, query, transform wrapper) naming the one file of heroPhoneUrl(), or
     * '' when $url already names it, names another picture, or there is no answer. With a bg_mobile_src record only the original's
     * file is swapped for the smaller size's, and with a derived one every size of the picture wider than it; from the service's measurement a rendition that costs more bytes than the file the
     * measurement names is swapped for that file. $only ('bg_mobile_src' or 'crit') restricts the answer to one source.
     */
    public static function heroOwnForm($url, $only = '')
    {
        $one = self::heroPhoneUrl();
        if ($one === [] || ($only !== '' && $one['source'] !== $only) || !is_string($url) || $url === '') {
            return '';
        }
        $name = self::heroName($url);
        $target = self::heroName($one['url']);
        if ($name === '' || $target === '' || strcasecmp($name, $target) === 0 || wps_ic_image_preload_set::imageKey($url) !== $one['key']) {
            return '';
        }
        $swap = ($one['source'] === 'bg_mobile_src')
            ? (strcasecmp($name, self::heroName($one['was'])) === 0 || (self::$bgDerived !== [] && self::heroWidth($url) > self::heroWidth($one['url'])))
            : self::heroCostsMore($url, $one['url']);
        if (!$swap) {
            return '';
        }
        return self::heroRename($url, $name, $target);
    }

    /**
     * True when the file $url names costs more bytes than the file $reference names: both read on this install in the first format
     * they share (the next-gen files beside the upload, then the upload's own), and where they share none the wider
     * rendition (the -WxH of its name, the natural width of the upload without one) costs more. False when neither can be told.
     */
    public static function heroCostsMore($url, $reference)
    {
        if (!is_string($url) || !is_string($reference) || $url === '' || $reference === '') {
            return false;
        }
        $mine = self::heroFileBytes($url);
        $theirs = self::heroFileBytes($reference);
        foreach ($mine as $format => $bytes) {
            if (isset($theirs[$format])) {
                return $bytes > $theirs[$format];
            }
        }
        $width = self::heroWidth($url);
        $other = self::heroWidth($reference);
        return $width > 0 && $other > 0 && $width > $other;
    }

    /** The rendition name an address carries: its file name without the extension and without the next-gen extension, the -WxH kept, inside a transform wrapper the innermost address's. */
    private static function heroName($url)
    {
        $inner = self::heroInner($url);
        $name = rawurldecode(basename((string) parse_url($inner, PHP_URL_PATH)));
        $name = (string) preg_replace('/\.(?:avif|webp)$/i', '', $name);
        return (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name);
    }

    private static function heroInner($url)
    {
        $inner = html_entity_decode((string) $url, ENT_QUOTES);
        $wrapped = strrpos($inner, '/u:http');
        return $wrapped === false ? $inner : substr($inner, $wrapped + 3);
    }

    /** format => bytes of every file the address names that is on this install beside the upload (avif, webp, then the upload's own formats); [] when it is not an upload of this install. */
    private static function heroFileBytes($url)
    {
        $inner = self::heroInner($url);
        $path = (string) parse_url($inner, PHP_URL_PATH);
        $name = self::heroName($inner);
        if ($name === '' || strpos($path, '/wp-content/') === false || strpos($path, '..') !== false) {
            return [];
        }
        $folder = substr($path, 0, (int) strrpos($path, '/') + 1);
        $memo = $folder . $name;
        if (isset(self::$hero['bytes'][$memo])) {
            return self::$hero['bytes'][$memo];
        }
        $found = [];
        foreach (['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif'] as $format) {
            $file = self::installFile(rawurldecode($folder) . $name . '.' . $format);
            $size = $file !== '' && @is_file($file) ? (int) @filesize($file) : 0;
            if ($size > 0) {
                $found[$format === 'jpeg' ? 'jpg' : $format] = $size;
            }
        }
        return self::$hero['bytes'][$memo] = $found;
    }

    /** The width of the rendition an address names: its -WxH, else the upload's own width read from the file, 0 when neither is known. */
    private static function heroWidth($url)
    {
        $name = self::heroName($url);
        if (preg_match('/-(\d{2,5})x\d{2,5}$/', $name, $size)) {
            return (int) $size[1];
        }
        $inner = self::heroInner($url);
        $path = (string) parse_url($inner, PHP_URL_PATH);
        if ($name === '' || strpos($path, '/wp-content/') === false || strpos($path, '..') !== false || !function_exists('wpc_image_file_dims')) {
            return 0;
        }
        $folder = substr($path, 0, (int) strrpos($path, '/') + 1);
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $format) {
            $file = self::installFile(rawurldecode($folder) . $name . '.' . $format);
            if ($file !== '' && @is_file($file)) {
                $dims = wpc_image_file_dims($file);
                if (is_array($dims) && (float) $dims[0] > 0) {
                    return (int) $dims[0];
                }
            }
        }
        return 0;
    }

    /** True when $host is this site's host (www or not, or a sub-domain of it) or its zone's: the hosts a hero file may be named on. */
    public static function ownHost($host)
    {
        $strip = function ($name) {
            $name = strtolower(trim((string) $name));
            return strpos($name, 'www.') === 0 ? substr($name, 4) : $name;
        };
        $host = $strip($host);
        if ($host === '' || !function_exists('home_url')) {
            return false;
        }
        $home = $strip(parse_url(home_url(), PHP_URL_HOST));
        if ($home !== '' && ($host === $home || substr($host, -strlen('.' . $home)) === '.' . $home)) {
            return true;
        }
        foreach (['ic_cdn_zone_name', 'ic_custom_cname'] as $option) {
            $zone = function_exists('get_option') ? $strip(strtok((string) get_option($option), '/')) : '';
            if ($zone !== '' && $host === $zone) {
                return true;
            }
        }
        return false;
    }

    /**
     * The containers lcpElement() merges, in its order, each as the service wrote it.
     */
    private static function lcpContainers($document, $device)
    {
        $containers = [];
        if (!is_array($document)) {
            return $containers;
        }
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
        return $containers;
    }

    /**
     * The selector a background pin or rewrite goes on for one device: the proven `sel`, followed
     * by the pseudo-element (`::before` or `::after`, nothing else) the same container names.
     * '' when no container proves a selector.
     */
    public static function paintSelector($device, $page = false)
    {
        $document = self::document($page);
        foreach (self::lcpContainers($document, $device) as $container) {
            $container = self::with_proven_selector($container);
            if (!isset($container['sel']) || !is_string($container['sel']) || trim($container['sel']) === '') {
                continue;
            }
            $selector = trim($container['sel']);
            $pseudo = (isset($container['pseudo']) && is_string($container['pseudo'])
                && ($container['pseudo'] === '::before' || $container['pseudo'] === '::after')) ? $container['pseudo'] : '';
            return $selector . $pseudo;
        }
        return '';
    }

    /**
     * True when the service holds this device's background preload back: lcp_element[device]
     * carries `lever_view.action == "hold"`. Read from that container, not from the merged element.
     */
    public static function lcpHeld($device, $page = false)
    {
        return self::documentHolds(self::document($page), $device);
    }

    /** lcpHeld() over a decoded lcp.json the caller already has (the cache admission reads one by key). */
    public static function documentHolds($document, $device)
    {
        return is_array($document)
            && isset($document['lcp_element'][$device]['lever_view']) && is_array($document['lcp_element'][$device]['lever_view'])
            && ($document['lcp_element'][$device]['lever_view']['action'] ?? '') === 'hold';
    }

    /**
     * True when the page's own document says in so many words that the service could not tell
     * this device's LCP: its leg was not measured, its LCP read failed, or lcp_element[device] is
     * an explicit null, and nothing usable (a url, a stem, a selector) came through any container,
     * carried forward or not. No document, another page's document, one that says nothing about
     * the device, or a leg whose LCP read the service skipped on purpose (LCP_READ_SKIPPED_REASON)
     * is not an answer: the plugin's own guesses stand for those.
     */
    public static function lcpUnknown($device)
    {
        $document = self::document(true);
        if (!is_array($document)) {
            return false;
        }
        $element = self::lcpElement($device, true);
        foreach (['url', 'net_url', 'stem', 'sel'] as $field) {
            if (!empty($element[$field])) {
                return false;
            }
        }
        $leg = (isset($document['leg_status'][$device]) && is_array($document['leg_status'][$device])) ? $document['leg_status'][$device] : null;
        // A leg whose LCP read the service skipped on purpose is no answer, not "could not tell":
        // the guesses stand for it as for a page with no document. Staging /pricing/ 2026-10-28
        // (lcp.json svc 3.198.380, both legs completeness_only, lcp_element null) lost its derived
        // background preload under hero_fold and phone LCP went from 1.8 s to 2.8 s. A leg that was
        // not measured, or whose read ran and failed (any other reason), still stands them down.
        if ($leg !== null && ($leg['lcp_read_reason'] ?? null) === self::LCP_READ_SKIPPED_REASON) {
            return false;
        }
        if ($leg !== null && (array_key_exists('measured', $leg) && empty($leg['measured']))) {
            return true;
        }
        if ($leg !== null && array_key_exists('lcp_read', $leg) && $leg['lcp_read'] === false) {
            return true;
        }
        return isset($document['lcp_element']) && is_array($document['lcp_element'])
            && array_key_exists($device, $document['lcp_element']) && $document['lcp_element'][$device] === null;
    }

    /**
     * What this page's own lcp.json says about the first screen of one device, or null when it
     * says nothing the stamps can vouch for: no document, or one written before the service
     * stamped `leg_status` (crit 3.198.317), where an empty `atf_images` leg cannot be told from
     * a leg nobody measured.
     *
     *   measured   bool         the leg ran (leg_status[device].measured); with policy.atf_complete_lazy also a leg
     *                           stamped `atf_complete` true (leg_status[device].atf_complete or atf_complete[device]),
     *                           whether or not the document carries leg_status, unless the leg says measured or
     *                           lcp_read false
     *   complete   bool         that stamp stood and the cohort is on
     *   listed     array|null   the stems of atf_images[device]; null = not measured, [] = measured
     *                           and nothing but the LCP is on the first screen
     *   lcp        string[]     the LCP element's stem and file names
     *   cut        int          images the list left out that could have been on the first screen
     *                           (atf_cut[device].dropped less the ones cut for being narrower than min_w, which
     *                           no content image is); more than 0 means "not listed" no longer says "below"
     *   self       bool         the leg read the plugin's own optimized page (promotes, never demotes); false for a leg
     *                           stamped `atf_complete` true (cohort on) in a document from svc 3.198.365 or later
     *   carried_age int|null    age in seconds of a carried-forward atf_images list
     *   reason     string       leg_status[device].atf_reason, when the service gave one
     *   trusted    bool         only with $trustComplete: the leg is measured, stamped complete everywhere it is stamped and the
     *                           document is from svc 3.198.365 or later, with policy.atf_complete_lazy on or off; with $trustComplete
     *                           such a leg also counts as complete and measured. The one caller passing it is the first-N slot rule.
     */
    public static function foldFacts($device, $trustComplete = false)
    {
        $document = self::document(true);
        if (!is_array($document)) {
            return null;
        }
        $leg = (isset($document['leg_status'][$device]) && is_array($document['leg_status'][$device])) ? $document['leg_status'][$device] : null;
        $trusted = $trustComplete && self::atfCompleteTrusted($document, $device, $leg);
        $complete = self::atfComplete($document, $device, $leg) || $trusted;
        if ($leg === null && !$complete) {
            return null;
        }
        $leg = $leg === null ? [] : $leg;
        $listed = null;
        if (isset($document['atf_images']) && is_array($document['atf_images'])
            && isset($document['atf_images'][$device]) && is_array($document['atf_images'][$device])) {
            $listed = [];
            foreach ($document['atf_images'][$device] as $entry) {
                if (is_array($entry) && !empty($entry['stem']) && is_string($entry['stem'])) {
                    $listed[] = $entry['stem'];
                }
            }
        }
        $element = self::lcpElement($device, true);
        $lcp = [];
        foreach (['stem', 'url', 'net_url'] as $field) {
            if (!empty($element[$field]) && is_string($element[$field])) {
                $lcp[] = $field === 'stem' ? $element[$field] : (string) basename((string) strtok($element[$field], '?#'));
            }
        }
        $carriedAge = null;
        if (isset($document['carried_from'][$device]['atf_images']) && is_array($document['carried_from'][$device]['atf_images'])) {
            $carriedAge = isset($document['carried_from'][$device]['atf_images']['age_s'])
                && is_numeric($document['carried_from'][$device]['atf_images']['age_s'])
                ? max(0, (int) $document['carried_from'][$device]['atf_images']['age_s']) : PHP_INT_MAX;
        }
        $facts = [
            'measured' => !empty($leg['measured']) || $complete,
            'complete' => $complete,
            'listed' => $listed,
            'lcp' => $lcp,
            'cut' => self::cutCouldHideAnImage(isset($document['atf_cut'][$device]) ? $document['atf_cut'][$device] : null),
            'self' => (!empty($leg['optimized_page']) || !empty($leg['self_measured']) || !empty($element['self_measured']))
                && !($complete && self::serviceAtLeast($document, self::ATF_COMPLETE_SELF_LIFT_SVC)),
            'carried_age' => $carriedAge,
            'reason' => (isset($leg['atf_reason']) && is_string($leg['atf_reason'])) ? $leg['atf_reason'] : '',
            'lcp_not_img' => self::elementNotImage($element),
        ];
        if ($trustComplete) {
            $facts['trusted'] = $trusted;
        }
        return $facts;
    }

    public static function videoFold($device)
    {
        $device = $device === 'mobile' ? 'mobile' : 'desktop';
        if (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'wpc_image_stem')) {
            return null;
        }
        $facts = self::foldFacts($device);
        if ($facts === null || !$facts['measured'] || $facts['reason'] !== ''
            || ($facts['carried_age'] !== null && $facts['carried_age'] > (class_exists('wps_ic_lazy_verdict') ? wps_ic_lazy_verdict::CARRIED_MAX_AGE : 604800))) {
            return null;
        }
        $document = self::document(true);
        if (!is_array($document)) {
            return null;
        }
        $leg = (isset($document['leg_status'][$device]) && is_array($document['leg_status'][$device])) ? $document['leg_status'][$device] : [];
        if (array_key_exists('lcp_read', $leg) && $leg['lcp_read'] === false) {
            return null;
        }
        $vh = (isset($document['meta'][$device . '_vh']) && is_numeric($document['meta'][$device . '_vh'])) ? (int) $document['meta'][$device . '_vh'] : 0;
        if ($vh < 200) {
            return null;
        }
        $posters = [];
        $list = (isset($document['oversized_images'][$device]) && is_array($document['oversized_images'][$device])) ? $document['oversized_images'][$device] : [];
        foreach ($list as $entry) {
            if (!is_array($entry) || ($entry['kind'] ?? '') !== 'poster' || empty($entry['stem']) || !is_string($entry['stem'])
                || !isset($entry['top']) || !is_numeric($entry['top'])) {
                continue;
            }
            $stem = wps_rewriteLogic::wpc_image_stem($entry['stem']);
            if ($stem !== '' && (!isset($posters[$stem]) || (int) $entry['top'] < $posters[$stem])) {
                $posters[$stem] = (int) $entry['top'];
            }
        }
        $listed = [];
        foreach ((array) $facts['listed'] as $named) {
            $listed[] = wps_rewriteLogic::wpc_image_stem($named);
        }
        $element = self::lcpElement($device, true);
        $lcpVideo = isset($element['type']) && $element['type'] === 'video';
        $cv = self::cvContainers($device);
        return [
            'vh' => $vh,
            'posters' => $posters,
            'listed' => $listed,
            'lcp_video' => $lcpVideo,
            'lcp_stem' => ($lcpVideo && !empty($element['stem']) && is_string($element['stem'])) ? wps_rewriteLogic::wpc_image_stem($element['stem']) : '',
            'containers' => $cv['state'] === 'measured' ? $cv['entries'] : [],
        ];
    }

    /**
     * True when policy.atf_complete_lazy is on and every `atf_complete` stamp the document carries for the device
     * is the boolean true: leg_status[device].atf_complete, atf_complete[device], or both. A stamp that is false or
     * not a boolean, a leg that says `measured` or `lcp_read` false, and a document with no stamp are not complete.
     */
    private static function atfComplete($document, $device, $leg)
    {
        if (!self::atfCompleteLazyOn()) {
            return false;
        }
        return self::atfCompleteStamped($document, $device, $leg);
    }

    /**
     * True when the leg is measured, the document's `svc` is at or above ATF_COMPLETE_SELF_LIFT_SVC and the stamp rule of
     * atfComplete() holds, whether or not policy.atf_complete_lazy is on. Read only through foldFacts($device, true).
     */
    private static function atfCompleteTrusted($document, $device, $leg)
    {
        return is_array($leg) && !empty($leg['measured'])
            && self::serviceAtLeast($document, self::ATF_COMPLETE_SELF_LIFT_SVC)
            && self::atfCompleteStamped($document, $device, $leg);
    }

    /** The stamp rule of atfComplete() without the cohort: a leg that says measured or lcp_read false is not complete, and every stamp present is boolean true. */
    private static function atfCompleteStamped($document, $device, $leg)
    {
        if (is_array($leg) && ((array_key_exists('measured', $leg) && empty($leg['measured']))
            || (array_key_exists('lcp_read', $leg) && $leg['lcp_read'] === false))) {
            return false;
        }
        $stamps = [];
        if (is_array($leg) && array_key_exists('atf_complete', $leg)) {
            $stamps[] = $leg['atf_complete'];
        }
        if (isset($document['atf_complete']) && is_array($document['atf_complete']) && array_key_exists($device, $document['atf_complete'])) {
            $stamps[] = $document['atf_complete'][$device];
        }
        if ($stamps === []) {
            return false;
        }
        foreach ($stamps as $stamp) {
            if ($stamp !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * The devices of the decoded lcp.json body $document whose leg atfComplete() calls complete, in the order mobile, desktop:
     * [] with policy.atf_complete_lazy off and for anything that is not a document. The one reader of the stamp for a body that
     * is not yet the page's file (the writer's carry-forward asks it before it grafts an older list).
     */
    public static function completeDevices($document)
    {
        $devices = [];
        if (!is_array($document) || !self::atfCompleteLazyOn()) {
            return $devices;
        }
        foreach (['mobile', 'desktop'] as $device) {
            $leg = (isset($document['leg_status'][$device]) && is_array($document['leg_status'][$device])) ? $document['leg_status'][$device] : null;
            if (self::atfComplete($document, $device, $leg)) {
                $devices[] = $device;
            }
        }
        return $devices;
    }

    /**
     * True when the document's `svc` (the crit service version that wrote it) is a dotted number at or above $minimum,
     * compared part by part as numbers. A document with no `svc`, or one that is not a plain x.y.z, is below every minimum.
     */
    private static function serviceAtLeast($document, $minimum)
    {
        if (!is_array($document) || !isset($document['svc']) || !is_string($document['svc'])
            || !preg_match('/^\d+\.\d+\.\d+$/', $document['svc'])) {
            return false;
        }
        return version_compare($document['svc'], (string) $minimum, '>=');
    }

    /**
     * How many images the service's cut left out of `atf_images` for a reason that could have hidden
     * a first-screen image. `dropped` is the sum of `by_max_n` (past the cap), `no_url` (no file to name)
     * and `by_min_w` (narrower than `min_w` CSS px: an icon, a spacer, a tracking pixel). Only the first two
     * can be an image this plugin gives a `loading` to; when the three add up to `dropped` the narrow ones are
     * not counted, unless `min_w` is above 64 (a cut that wide could have hidden a content image). A row that
     * does not break `dropped` down (an older service) counts all of it.
     */
    private static function cutCouldHideAnImage($row)
    {
        if (!is_array($row) || !isset($row['dropped']) || !is_numeric($row['dropped'])) {
            return 0;
        }
        $dropped = max(0, (int) $row['dropped']);
        foreach (['by_max_n', 'no_url', 'by_min_w'] as $part) {
            if (!isset($row[$part]) || !is_numeric($row[$part]) || (int) $row[$part] < 0) {
                return $dropped;
            }
        }
        if ((int) $row['by_max_n'] + (int) $row['no_url'] + (int) $row['by_min_w'] !== $dropped
            || (isset($row['min_w']) && is_numeric($row['min_w']) && (int) $row['min_w'] > 64)) {
            return $dropped;
        }
        return (int) $row['by_max_n'] + (int) $row['no_url'];
    }

    /**
     * What this page's own lcp.json says the LCP of one device is: 'img' (a measured <img> with a
     * file stem), 'other' (the document is there and names no <img>: text, a background, a video,
     * or nothing, which is what a withheld element looks like), 'none' (the page has no lcp.json
     * of its own). The page's own file only (WHICH FILE on the class).
     */
    public static function lcpKind($device)
    {
        if (!is_array(self::document(true))) {
            return 'none';
        }
        $element = self::lcpElement($device, true);
        return (isset($element['type']) && $element['type'] === 'img' && !empty($element['stem']) && is_string($element['stem'])) ? 'img' : 'other';
    }

    /** The stem of the page's own measured LCP <img> for one device, '' when lcpKind() is not 'img'. */
    public static function lcpImageStem($device)
    {
        if (self::lcpKind($device) !== 'img') {
            return '';
        }
        $element = self::lcpElement($device, true);
        return (string) $element['stem'];
    }

    public static function elementNotImage($element)
    {
        if (!is_array($element) || $element === []) {
            return false;
        }
        $type = (isset($element['type']) && is_string($element['type'])) ? strtolower(trim($element['type'])) : '';
        if ($type !== '') {
            return in_array($type, ['bg', 'bg-img', 'text', 'video'], true);
        }
        return !empty($element['bg']);
    }

    /**
     * The width in CSS-pixel-times-ratio terms the hero file must be stored at on one device, from
     * `need_px` on lcp[device] or lcp_element[device] (crit ask 102; the field is read as an
     * integer or a list of integers, a list answering with its largest). 0 when the service did
     * not say or said something that is not a width between 64 and 4096.
     */
    public static function needPx($device, $page = false)
    {
        return self::needPxOf(self::document($page), $device);
    }

    /**
     * needPx() over a decoded lcp.json the caller already has (a cron job reads a page by its folder). With $keyed the service's
     * `need_px` object {mobile, desktop} answers the width of $device alone, where needPx() answers the largest of the list.
     */
    public static function needPxOf($document, $device, $keyed = false)
    {
        foreach (self::lcpContainers($document, $device) as $container) {
            if (!array_key_exists('need_px', $container)) {
                continue;
            }
            $raw = $container['need_px'];
            $values = is_array($raw) ? $raw : [$raw];
            if ($keyed && is_array($raw) && array_key_exists($device, $raw)) {
                $values = [$raw[$device]];
            }
            $best = 0;
            foreach ($values as $value) {
                if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 64 && (int) $value <= 4096) {
                    $best = max($best, (int) $value);
                }
            }
            if ($best > 0) {
                return $best;
            }
        }
        return 0;
    }

    /**
     * The containers below the first screen of one device that the service proved can skip rendering
     * (`cv_containers[device]`, crit ask 116): each `{sel, top, height, contain_intrinsic_size}`, listed
     * only when the same render showed that `content-visibility:auto` with that size moves nothing.
     *
     *   state     'absent'       no lcp.json of the page's own, or one written before the service sent the field
     *             'unmeasured'   the field is there and this device's leg is null or missing
     *             'unparseable'  the field or the leg is not a list, or an entry is not the shape below
     *             'measured'     a list, possibly empty: empty means none qualified
     *   entries   list of {sel, top, height, size}; empty unless measured. `size` is the content-box height
     *             as a string, two decimals with the trailing zeros trimmed, '.' as the separator
     *
     * An entry is valid when `sel` is at most 240 characters and is `#id` or `<anchor> > .class[.class...]`
     * with `body`, `#id` or `.class` as the anchor and ids and classes of letters, digits, `_` and `-`
     * that do not start with a digit (no escapes, no pseudo-classes, no attribute selectors, one `>`);
     * `top` and `height` are integers from 0; `size` is a number above 0 and below 100000. One bad
     * entry makes the device unparseable, so nothing is applied from a list the reader cannot trust, and
     * so does a selector named twice. At most 40 entries are read. The page's own file only: a home
     * page's container is not this page's.
     */
    public static function cvContainers($device)
    {
        $device = $device === 'mobile' ? 'mobile' : 'desktop';
        if (isset(self::$cvContainers[$device])) {
            return self::$cvContainers[$device];
        }
        $out = ['state' => 'absent', 'entries' => []];
        self::$cvContainers[$device] = $out;
        $document = self::document(true);
        if (!is_array($document) || !isset($document[self::CV_CONTAINERS_FIELD])) {
            return $out;
        }
        $field = $document[self::CV_CONTAINERS_FIELD];
        if (!is_array($field)) {
            $out['state'] = 'unparseable';
            return self::$cvContainers[$device] = $out;
        }
        if (!array_key_exists($device, $field) || $field[$device] === null) {
            $out['state'] = 'unmeasured';
            return self::$cvContainers[$device] = $out;
        }
        $list = $field[$device];
        if (!is_array($list) || $list !== array_values($list)) {
            $out['state'] = 'unparseable';
            return self::$cvContainers[$device] = $out;
        }
        $entries = [];
        $seen = [];
        foreach (array_slice($list, 0, self::CV_CONTAINERS_MAX) as $raw) {
            $entry = self::cv_container_entry($raw);
            if ($entry === null || isset($seen[$entry['sel']])) {
                $out['state'] = 'unparseable';
                return self::$cvContainers[$device] = $out;
            }
            $seen[$entry['sel']] = true;
            $entries[] = $entry;
        }
        return self::$cvContainers[$device] = ['state' => 'measured', 'entries' => $entries];
    }

    /** One `cv_containers` entry validated and normalised, or null when it is not the shape the contract describes. */
    private static function cv_container_entry($raw)
    {
        if (!is_array($raw) || !isset($raw['sel'], $raw['top'], $raw['height'], $raw['contain_intrinsic_size'])
            || !is_string($raw['sel']) || strlen($raw['sel']) > self::CV_SELECTOR_MAX || preg_match(self::CV_SELECTOR, $raw['sel']) !== 1
            || !is_int($raw['top']) || $raw['top'] < 0 || !is_int($raw['height']) || $raw['height'] < 0) {
            return null;
        }
        $size = $raw['contain_intrinsic_size'];
        if (!(is_int($size) || is_float($size)) || !is_finite($size) || round($size, 2) <= 0 || round($size, 2) >= 100000) {
            return null;
        }
        return [
            'sel' => preg_replace('/ ?> ?/', ' > ', $raw['sel'], 1),
            'top' => $raw['top'],
            'height' => $raw['height'],
            'size' => rtrim(rtrim(number_format(round($size, 2), 2, '.', ''), '0'), '.'),
        ];
    }

    /**
     * What the service says is interactive on the first screen of one device (`atf_interactive[device]`,
     * crit ask 105): the sliders and menu toggles, each with the scripts that bind it.
     *
     *   state     'absent'       no document, or one written before the service sent the field
     *             'unmeasured'   the field is there and this device's leg is null or missing
     *             'unparseable'  the leg, or an entry of a kind this reader knows, is malformed
     *             'measured'     a list, possibly empty: nothing was found when it is empty
     *   entries   list of {kind, sel, attrib, top?, height?, binders: [{url, id, bytes, hash?}]}; empty unless measured.
     *             A binder's hash, when the service sends one, is the hex md5, sha1 or sha256 of an inline script's text; any
     *             other value is left out, and the binder is read as it was.
     *   home      true when the page has no lcp.json of its own and the home page's answered
     *   dropped   the slider entries a home answer was not trusted for
     *
     * Only `measured` replaces what the plugin does without the field; every other state is the plugin's own
     * list. Sliders are trusted from the page's own file only (a home page's slider is not this page's);
     * menu toggles may come from the home page's. At most 8 entries are read per device; an entry of a kind
     * this reader does not know is ignored, never a reason to hold the rest, but a list of nothing else
     * holds (unparseable): it says nothing this release can act on.
     */
    public static function interactive($device)
    {
        $device = $device === 'mobile' ? 'mobile' : 'desktop';
        if (isset(self::$interactive[$device])) {
            return self::$interactive[$device];
        }
        $out = ['state' => 'absent', 'entries' => [], 'home' => false, 'dropped' => 0];
        self::$interactive[$device] = $out;
        $document = self::document();
        if (!is_array($document) || !isset($document[self::INTERACTIVE_FIELD])) {
            return $out;
        }
        $field = $document[self::INTERACTIVE_FIELD];
        if (!is_array($field)) {
            $out['state'] = 'unparseable';
            return self::$interactive[$device] = $out;
        }
        if (!array_key_exists($device, $field) || $field[$device] === null) {
            $out['state'] = 'unmeasured';
            return self::$interactive[$device] = $out;
        }
        $list = $field[$device];
        if (!is_array($list) || $list !== array_values($list)) {
            $out['state'] = 'unparseable';
            return self::$interactive[$device] = $out;
        }
        $home = !is_array(self::document(true));
        $entries = [];
        $dropped = 0;
        $known = 0;
        foreach (array_slice($list, 0, self::INTERACTIVE_MAX) as $raw) {
            if (!is_array($raw) || !isset($raw['kind']) || !is_string($raw['kind'])) {
                $out['state'] = 'unparseable';
                return self::$interactive[$device] = $out;
            }
            if (!in_array($raw['kind'], self::INTERACTIVE_KINDS, true)) {
                continue;
            }
            $entry = self::interactive_entry($raw);
            if ($entry === null) {
                $out['state'] = 'unparseable';
                return self::$interactive[$device] = $out;
            }
            $known++;
            if ($home && $entry['kind'] === 'slider') {
                $dropped++;
                continue;
            }
            $entries[] = $entry;
        }
        if ($list !== [] && $known === 0) {
            $out['state'] = 'unparseable';
            return self::$interactive[$device] = $out;
        }
        return self::$interactive[$device] = ['state' => 'measured', 'entries' => $entries, 'home' => $home, 'dropped' => $dropped];
    }

    /** One `atf_interactive` entry validated, or null when it is not the shape the contract describes. */
    private static function interactive_entry(array $raw)
    {
        if (!isset($raw['sel'], $raw['attrib'], $raw['binders']) || !is_string($raw['sel']) || $raw['sel'] === '' || strlen($raw['sel']) > 240
            || !is_string($raw['attrib']) || !in_array($raw['attrib'], self::INTERACTIVE_ATTRIBS, true)
            || !is_array($raw['binders']) || $raw['binders'] !== array_values($raw['binders'])) {
            return null;
        }
        $binders = [];
        foreach ($raw['binders'] as $binder) {
            if (!is_array($binder) || !isset($binder['url'], $binder['bytes']) || !is_string($binder['url']) || $binder['url'] === '' || strlen($binder['url']) > 2048
                || !(is_int($binder['bytes']) || is_float($binder['bytes'])) || $binder['bytes'] < 0
                || (isset($binder['id']) && !is_string($binder['id']))) {
                return null;
            }
            $row = ['url' => $binder['url'], 'id' => isset($binder['id']) ? $binder['id'] : '', 'bytes' => (int) min(round($binder['bytes']), 2147483647)];
            if (isset($binder['hash']) && is_string($binder['hash']) && preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{40}|[0-9a-f]{64})$/i', $binder['hash'])) {
                $row['hash'] = strtolower($binder['hash']);
            }
            $binders[] = $row;
        }
        $entry = ['kind' => $raw['kind'], 'sel' => $raw['sel'], 'attrib' => $raw['attrib'], 'binders' => $binders];
        foreach (['top', 'height'] as $box) {
            if (isset($raw[$box]) && (is_int($raw[$box]) || is_float($raw[$box]))) {
                $entry[$box] = $raw[$box];
            }
        }
        return $entry;
    }

    /**
     * The font preloads the service found unused on the first screen of one device
     * (`font_preload_unused[device]`, crit ask 116): the absolute URLs, query stripped, of every
     * <link rel=preload as=font> in the original page whose @font-face painted no glyph there.
     *
     *   state     'absent'       no lcp.json of the page's own, or one written before the service sent the field
     *             'unmeasured'   the field is null, or this device's leg is null or missing
     *             'unparseable'  the field or the leg is not what the contract describes: not a list, more than
     *                            40 entries, or an entry that is not an http(s) URL
     *             'measured'     a list, possibly empty: empty means none is unused
     *   entries   the URLs in the order the service listed them, each once; empty unless measured
     *
     * An entry is valid when it is a string of at most 2048 characters that starts with http:// or https://
     * and holds no whitespace, control character, quote or angle bracket. One bad entry makes the device
     * unparseable: nothing is dropped from a list the reader cannot trust. The page's own file only: a home
     * page's unused font is not this page's.
     */
    public static function fontPreloadUnused($device)
    {
        $device = $device === 'mobile' ? 'mobile' : 'desktop';
        if (isset(self::$fontPreloadUnused[$device])) {
            return self::$fontPreloadUnused[$device];
        }
        $out = ['state' => 'absent', 'entries' => []];
        self::$fontPreloadUnused[$device] = $out;
        $document = self::document(true);
        if (!is_array($document) || !array_key_exists(self::FONT_PRELOAD_UNUSED_FIELD, $document)) {
            return $out;
        }
        $field = $document[self::FONT_PRELOAD_UNUSED_FIELD];
        if ($field === null || $field === []) {
            $out['state'] = 'unmeasured';
            return self::$fontPreloadUnused[$device] = $out;
        }
        if (!is_array($field) || $field === array_values($field)) {
            $out['state'] = 'unparseable';
            return self::$fontPreloadUnused[$device] = $out;
        }
        if (!array_key_exists($device, $field) || $field[$device] === null) {
            $out['state'] = 'unmeasured';
            return self::$fontPreloadUnused[$device] = $out;
        }
        $list = $field[$device];
        if (!is_array($list) || $list !== array_values($list) || count($list) > self::FONT_PRELOAD_UNUSED_MAX) {
            $out['state'] = 'unparseable';
            return self::$fontPreloadUnused[$device] = $out;
        }
        $entries = [];
        foreach ($list as $url) {
            if (!is_string($url) || strlen($url) > self::FONT_PRELOAD_URL_MAX || preg_match('~\Ahttps?://[^\x00-\x20\x7f"\'<>]+\z~i', $url) !== 1) {
                $out['state'] = 'unparseable';
                return self::$fontPreloadUnused[$device] = $out;
            }
            if (!in_array($url, $entries, true)) {
                $entries[] = $url;
            }
        }
        return self::$fontPreloadUnused[$device] = ['state' => 'measured', 'entries' => $entries];
    }

    /**
     * The hand-over script the service ships for the first-screen faces contract
     * (`atf_handover` of the page's own lcp.json: `{name, v, js}`).
     *
     *   state   'absent'       no lcp.json of the page's own, or no field, or null
     *           'unparseable'  not an object with the name `wpc-atf-handover`, an integer `v` and a string `js` of at most 8192 bytes
     *           'unsupported'  a version this plugin has no pinned text for
     *           'altered'      the text of a supported version is not the pinned one (sha-256)
     *           'valid'        the pinned text of that version
     *   v       the version when valid, else 0
     *   js      the script text when valid, else ''
     *
     * The text is inlined into every page that carries it, so only the exact bytes of a version pinned in
     * ATF_HANDOVER_PINS are ever answered; a new version is a plugin release that adds its digest.
     */
    public static function atfHandover()
    {
        if (self::$atfHandover !== null) {
            return self::$atfHandover;
        }
        $out = ['state' => 'absent', 'v' => 0, 'js' => ''];
        self::$atfHandover = $out;
        $document = self::document(true);
        if (!is_array($document) || !isset($document[self::ATF_HANDOVER_FIELD]) || $document[self::ATF_HANDOVER_FIELD] === []) {
            return $out;
        }
        $field = $document[self::ATF_HANDOVER_FIELD];
        if (!is_array($field) || $field === array_values($field)
            || !isset($field['name'], $field['v'], $field['js'])
            || $field['name'] !== self::ATF_HANDOVER_NAME || !is_int($field['v'])
            || !is_string($field['js']) || $field['js'] === '' || strlen($field['js']) > self::ATF_HANDOVER_MAX) {
            $out['state'] = 'unparseable';
            return self::$atfHandover = $out;
        }
        if (!isset(self::ATF_HANDOVER_PINS[$field['v']])) {
            $out['state'] = 'unsupported';
            return self::$atfHandover = $out;
        }
        if (!hash_equals(self::ATF_HANDOVER_PINS[$field['v']], hash('sha256', $field['js']))) {
            $out['state'] = 'altered';
            return self::$atfHandover = $out;
        }
        return self::$atfHandover = ['state' => 'valid', 'v' => $field['v'], 'js' => $field['js']];
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
        self::$interactive = [];
        self::$cvContainers = [];
        self::$fontPreloadUnused = [];
        self::$atfHandover = null;
        self::$hero = [];
        self::$heroLqip = [];
        self::$bgDerived = [];
    }
}
