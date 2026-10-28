<?php

/**
 * The one owner of an <img>'s lazy/eager verdict: which `loading` it carries, and why.
 *
 * Seven writers used to decide it with seven input sets, and every builder appended its own
 * `loading` after copying the page's. dbmwebdesign.de (CDN off, native lazy) listed its hero in
 * "Exclude from Lazy" and the local lane never read that list (it read two legacy options the
 * single-site UI cannot write); the theme's `loading="eager"` was copied behind our
 * `loading="lazy"`, and the browser obeys the first. The CDN lane read the list through two
 * readers with two rules, only on pages with a post id. Each writer now asks here, once per tag,
 * and writes exactly what it is told.
 *
 *   forTag($tag, $url, $withinLeadingCount, $hiddenReason)  the verdict for one rendered <img>
 *   forTagPreBuffer($tag, $url)                       the reduced verdict before the buffer exists
 *   applyLoading($tag, $verdict)                      the one attribute writer
 *   hiddenReason($tag, $deviceHiddenImages)           rule 4's input, for the lanes' own counts
 *   foldVerdict($tag, $url)                           'above' | 'below' | 'unknown': where the crit service's own
 *                                                      measurement of this page's first screen puts the image
 *   measuredBelow($tag, $url)                         the fold verdict is 'below' (a fetchpriority writer's refusal)
 *   belowFirstScreen($tag, $url)                      the measurement puts the image below every served device's first screen, hero_fold or not
 *   listedFirstScreen($tag, $url)                     the measurement lists the image on every served device's first screen and names it as the LCP of none
 *   priorityReason($tag, $url)                        rule 6's input, for the CDN eager build
 *   lcpIdentity($document)                            the page's LCP as the fetchpriority rule reads it (known, type, file keys)
 *   keepsHigh($tag, $url, $anotherHigh)               whether a tag or a preload may carry fetchpriority="high" (WHO KEEPS)
 *   listMatches($tag, $url)                           the exclude list's matcher
 *   listMatchesMarkup($markup)                        the same list over arbitrary markup (video)
 *   receipt($uri)                                     the one lazy-verdict receipt of the render
 *
 * PRECEDENCE, first match wins: (1) lazy and native lazy both off: keep; (2) another plugin owns
 * lazy loading: keep; (3) a JS lazy library or a slider engine owns the tag: keep; (4) the markup
 * hides it on this device, or the census puts it below the fold: lazy; (5) the exclude list or an
 * author marker class: eager; (6) the page's fetchpriority="high" or the measured LCP
 * image: eager; (7) the page's loading="eager": eager; (8) inside the leading count: eager;
 * (9) otherwise lazy, except a logo, which keeps the page's attribute (never ours to make lazy). `keep` means the builder writes nothing about `loading` and the page's own
 * attribute stays; a lane parks an image (placeholder + data-src) only when the verdict is lazy.
 *
 * THE FOLD (policy.hero_fold). Where the page's own lcp.json is stamped with `leg_status`, the
 * service's list of first-screen images (`atf_images`) is a measurement: an image on a measured
 * leg's list, or the LCP itself, is above the fold (rule 4 does not take it and it is eager
 * past the leading count: 'measured-above'); an image a measured leg did not list is below it
 * (rule 4: lazy, no leading slot) unless the list was cut (a cut for narrowness alone, images under `min_w` CSS px, does not count), was read on the plugin's own optimized
 * page (not held by that when a svc 3.198.365 document stamps the leg `atf_complete` true and policy.atf_complete_lazy is on), or was carried from a measurement more than seven days old; anything else is unknown
 * and keeps the positional rule above. A device-blind host (a combined crit) asks both legs:
 * above when either lists it, below only when both measured legs agree. A leading count a
 * person chose (a non-default lazySkipCount, a per-page skip, the lever lazy_skip) keeps the
 * positional rule whatever the legs say. The measured LCP image is never made lazy by the
 * fold, on any lane.
 */
final class wps_ic_lazy_verdict
{
    /** Author markers: an image carrying one of these class words is never lazy (rule 5). */
    const MARKER_CLASS_WORDS = ['skip-lazy', 'notlazy', 'nolazy', 'show-on-hover'];

    /** Class words of JS lazy libraries that swap their own placeholder (rule 3). */
    const LIBRARY_CLASS_WORDS = ['lazyload', 'b-lazy', 'jet-image', 'breakdance', 'data-lazy'];

    /** A spacer file named in src, with the real image in data-src (a lazy loader's placeholder), not a resized rendition of a picture. */
    const SPACER_SRC_PATTERN = '/(?:spacer|pixel|1x1)[^\/]*\.(?:gif|png)$/i';

    /** The class words that keep a tag out of Negotiated Delivery, as that lane has always matched
     *  them (whole words, case-insensitive); the builders then decide the tag. */
    const NEGOTIATION_OPT_OUT_PATTERN = '/\b(skip-lazy|notlazy|nolazy|breakdance|jet-image|data-lazy)\b/i';

    /** Slider engines that position their own images (rule 3), matched by token on the class:
     *  a substring 'rs' used to hit 'colors', 'doctors' and 'sponsors' (columbuschiropractors). */
    const SLIDER_ENGINE_PATTERN = '/(?:^|[^a-z0-9])rs[-_]|rs-lazyload|revslider|rev_slider|lgx_app|dynamic-image/';

    /** Every reason a verdict can carry, in the receipt's order. */
    const REASONS = ['list', 'marker', 'logo', 'theme-priority', 'measured-lcp', 'theme-eager', 'slot',
        'past-count', 'device-hidden', 'below-fold', 'lib-owned', 'off', 'override', 'measured-above', 'fold-below', 'hidden-slide', 'slide-below'];

    const SLIDE_LAZY_MARK = 'data-wpc-slide-lazy';

    /** A carried-forward first-screen list older than this is not a measurement of this page now. */
    const CARRIED_MAX_AGE = 604800;

    /** @var string  'cdn' or 'local'. */
    private $lane;
    /** @var bool  Viewport lazy loading (placeholder + data-src) is on for this render. */
    private $viewportLazy;
    /** @var bool  The lane that builds this render's <img> can park one (placeholder + data-src).
     *              False on the CDN lane while Negotiated Delivery stands viewport parking down
     *              (wps_rewriteLogic::$lazyEnabled '0') although the setting is on. Read only by
     *              rule 3's generic-slider test. */
    private $parksViewport;
    /** @var bool  Native lazy loading (loading="lazy") is on for this render. */
    private $nativeLazy;
    /** @var bool  Another plugin owns lazy loading (Perfmatters, WP Rocket). */
    private $overridden;
    /** @var int  The leading count: how many painted images stay eager, after the per-page value. */
    private $leadingCount;
    /** @var array  The images this device does not paint, when the owner was handed the set. */
    private $deviceHiddenImages;
    /** @var string[]  The exclude list, normalised (lower case, trimmed, unique). */
    private $excludeList;
    /** @var bool|null  The leading count is a person's choice (or a lever's): the measured fold stands down. Read on first use. */
    private $countPinned = null;
    /** @var array  stem => 'above'|'below'|'unknown' for this render. */
    private $foldMemo = [];
    /** @var array  stem => bool: the trusted fold puts the file below the first screen on every render device. */
    private $trustedBelowMemo = [];
    private $negotiatedTookFirst = false;
    private $foldCounts = ['above' => 0, 'below' => 0, 'unknown' => 0];

    private $verdictCounts = ['eager' => 0, 'lazy' => 0, 'kept' => 0];
    private $reasonCounts = [];
    private $duplicatesStripped = 0;
    private $receiptWritten = false;
    /** @var int  Tags a builder kept on their own data-src this render (dataSrcIsLazySlot false):
     *            never JS-lazied, adaptive-swapped or parked. Receipt `page-data-src-kept`. */
    private $pageDataSrcKept = 0;

    /** @var bool  Negotiated Delivery walked this render's buffer in document order. */
    private $documentWalked = false;
    /** @var int  Leading-slot candidates seen so far by that walk (the last one's position). */
    private $documentPosition = 0;
    /** @var int  Leading slots that walk gave to images Negotiated Delivery built. */
    private $negotiatedLeadingSlots = 0;

    /** @var array|null  Verdicts the the_content picture lane acted on before this request's
     *                   render existed (applyPreBufferLoading): ['eager' =>, 'lazy' =>, 'kept' =>,
     *                   'why' => [reason => n]]. The render's receipt takes them in and clears them. */
    private static $preBufferTally = null;


    /**
     * @param array $seed  lane, viewportLazy, parksViewport (default: viewportLazy), nativeLazy,
     *                     overridden, leadingCount, deviceHiddenImages, excludeList (null: read
     *                     wpc-excludes['lazy']). viewportLazy and nativeLazy are the request's
     *                     settings; parksViewport is what the building lane can do with them.
     */
    public function __construct(array $seed = [])
    {
        $this->lane = isset($seed['lane']) ? (string) $seed['lane'] : 'cdn';
        $this->viewportLazy = !empty($seed['viewportLazy']);
        $this->parksViewport = array_key_exists('parksViewport', $seed) ? !empty($seed['parksViewport']) : $this->viewportLazy;
        $this->nativeLazy = !empty($seed['nativeLazy']);
        $this->overridden = !empty($seed['overridden']);
        $this->leadingCount = isset($seed['leadingCount']) ? (int) $seed['leadingCount'] : 4;
        $this->deviceHiddenImages = isset($seed['deviceHiddenImages']) && is_array($seed['deviceHiddenImages']) ? $seed['deviceHiddenImages'] : [];
        $this->excludeList = array_key_exists('excludeList', $seed) && $seed['excludeList'] !== null
            ? self::normaliseList($seed['excludeList']) : self::excludeList();
        if (array_key_exists('countPinned', $seed)) {
            $this->countPinned = !empty($seed['countPinned']);
        }
        $this->reasonCounts = array_fill_keys(self::REASONS, 0);
    }

    /**
     * An owner for a caller outside a render (a pre-buffer filter, Negotiated Delivery called
     * without a render owner): the request's own flags, as mainInit() resolved them when it ran,
     * the settings otherwise.
     */
    public static function forRequest()
    {
        // One owner per request for the pre-buffer writers (every Modern Delivery <picture> and
        // every the_content image asks). Keyed on its inputs, so a process that serves several
        // requests (the suites, WP-CLI) never answers one request with another's flags.
        static $memo = [];
        $settings = function_exists('get_option') && defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        $settings = is_array($settings) ? $settings : [];
        $haveRewrite = class_exists('wps_cdn_rewrite', false);
        $memoKey = serialize([
            $haveRewrite ? [wps_cdn_rewrite::$lazy_enabled, wps_cdn_rewrite::$native_lazy_enabled, wps_cdn_rewrite::$lazy_override] : null,
            isset($settings['lazy']) ? $settings['lazy'] : null, isset($settings['nativeLazy']) ? $settings['nativeLazy'] : null,
            isset($settings['lazySkipCount']) ? $settings['lazySkipCount'] : null,
            isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '', isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
            isset($GLOBALS['post']->ID) ? $GLOBALS['post']->ID : 0,
        ]);
        if (!isset($memo[$memoKey])) {
            if (count($memo) > 16) {
                $memo = [];
            }
            $memo[$memoKey] = self::buildForRequest($settings, $haveRewrite);
        }
        return $memo[$memoKey];
    }

    private static function buildForRequest(array $settings, $haveRewrite)
    {
        $viewport = $haveRewrite && wps_cdn_rewrite::$lazy_enabled !== null ? wps_cdn_rewrite::$lazy_enabled
            : (isset($settings['lazy']) ? $settings['lazy'] : '0');
        $native = $haveRewrite && wps_cdn_rewrite::$native_lazy_enabled !== null ? wps_cdn_rewrite::$native_lazy_enabled
            : (isset($settings['nativeLazy']) ? $settings['nativeLazy'] : '0');
        return new self([
            'viewportLazy' => (string) $viewport === '1',
            'nativeLazy' => (string) $native === '1',
            'overridden' => $haveRewrite && !empty(wps_cdn_rewrite::$lazy_override),
            'leadingCount' => $haveRewrite ? wps_cdn_rewrite::lazy_leading_count() : (!empty($settings['lazySkipCount']) ? (int) $settings['lazySkipCount'] : 4),
        ]);
    }

    /** The AMP squash turns viewport lazy off for the render; native lazy stays as it was. */
    public function squashViewportLazy()
    {
        $this->viewportLazy = false;
        $this->parksViewport = false;
    }

    /** True when the render lazy-loads at all: a lazy mode is on and the page has not overridden it. */
    public function lazyLoadingOn()
    {
        return ($this->viewportLazy || $this->nativeLazy) && !$this->overridden;
    }

    /** The leading count after the per-page value: how many painted images stay eager. */
    public function leadingCount()
    {
        return $this->leadingCount;
    }

    /*
     * ONE LEADING COUNT FOR THE PAGE. The leading count is the first N painted images in document
     * order, whichever lane builds them. Negotiated Delivery runs over the whole buffer before the
     * builder, and each used to count only its own images: on staging /pricing/ (per-page count
     * 9) the ninth image Negotiated Delivery built sat at document position 21, so nine
     * screenshots far down the page were made eager over WordPress's loading="lazy" and quiet-wire
     * no longer parked them, while the builder gave its own nine slots to icons as far as position
     * 15 (12 fetchpriority="high"; 7 on 7.26.00, which did not read the per-page count on the CDN
     * lane and let Negotiated Delivery keep WordPress's lazy). /wp-rocket-alternative/ (count 4) showed
     * the same with ND images at positions 12-14. So Negotiated Delivery walks every <img> in
     * document order and numbers the slot candidates (the CDN builder's own counting rule:
     * slotCandidate() and no hidden reason), takes its own slots from that numbering, and the
     * builder's count is what that walk left for the images it builds.
     */

    /** Start Negotiated Delivery's document-order walk for this render. */
    public function startDocumentWalk()
    {
        $this->documentWalked = true;
        $this->documentPosition = 0;
        $this->negotiatedLeadingSlots = 0;
    }

    /** Forget a walk that did not finish (Negotiated Delivery returned the buffer unchanged). */
    public function abandonDocumentWalk()
    {
        $this->documentWalked = false;
        $this->documentPosition = 0;
        $this->negotiatedLeadingSlots = 0;
    }

    /**
     * The next <img> of the walk, in document order: its position among the leading-slot
     * candidates, or 0 when it takes no slot (not a candidate, or hidden on this device / below
     * the fold).
     */
    public function takeDocumentPosition($tag, $hiddenReason)
    {
        if ($hiddenReason !== '' || !self::slotCandidate($tag)) {
            return 0;
        }
        return ++$this->documentPosition;
    }

    /** Negotiated Delivery built the image at this walk position. */
    public function noteNegotiatedImage($position)
    {
        if ($position > 0 && $position <= $this->leadingCount) {
            $this->negotiatedLeadingSlots++;
        }
        if ($position === 1) {
            $this->negotiatedTookFirst = true;
        }
    }

    /** True while the page's first image is still open to a builder: with the one-high rule on, until some writer has taken the page's one high; with it off, until Negotiated Delivery built the first image. */
    public function firstSlotFree()
    {
        if (self::firstNTrimOn() && class_exists('wps_rewriteLogic') && property_exists('wps_rewriteLogic', 'firstNHighGiven')) {
            return !wps_rewriteLogic::$firstNHighGiven;
        }
        return !$this->negotiatedTookFirst;
    }

    /**
     * The $firstImage a builder hands priorityFor(): with the one-high rule on, any image while no writer has taken the page's
     * high (highAllowed then picks the first that is not a logo or icon, the one counter shared by every lane); with it off,
     * the lane's own first image ($laneCount === 1; 0 for a lane with no count) while Negotiated Delivery has not built it.
     */
    public function firstImageOpen($laneCount)
    {
        return self::firstNTrimOn() ? $this->firstSlotFree() : ((int) $laneCount === 1 && $this->firstSlotFree());
    }

    /** True when the tag names the file a measured stem or file name stands for. */
    public function namesFile($tag, $stemOrFile)
    {
        $key = self::fileKey((string) $stemOrFile);
        return $key !== '' && self::fileKey($this->tagUrl((string) $tag)) === $key;
    }

    /**
     * A builder's leading count once Negotiated Delivery took its slots: the lane's own count
     * less the leading slots that walk gave to images it built, so the builder's first N
     * counted images are the remaining candidates inside the page's first N. The lane's count is
     * returned as it is when no walk ran (Negotiated Delivery off).
     */
    public function leadingCountAfterNegotiated($laneLeadingCount)
    {
        if (!$this->documentWalked) {
            return $laneLeadingCount;
        }
        return max(0, (int) $laneLeadingCount - $this->negotiatedLeadingSlots);
    }

    /**
     * The CDN builder's test for a tag it counts toward the leading slots before the hidden test:
     * not a tag an earlier pass already built, not a placeholder or spinner. The builder skips
     * such a tag uncounted (wps_rewriteLogic::replaceImageTagsDo); the document walk must number
     * exactly the tags the builder numbers.
     */
    public static function slotCandidate($tag)
    {
        return !self::alreadyBuilt($tag) && !self::isPlaceholderTag($tag);
    }

    /** A tag a CDN pass already built (the CDN builder returns it untouched). */
    public static function alreadyBuilt($tag)
    {
        $tag = (string) $tag;
        return strpos($tag, 'wps-ic-cdn') !== false
            || strpos($tag, 'data-wpc-fb') !== false
            || strpos($tag, 'wpc-size="preserve"') !== false
            || strpos($tag, 'wps-ic-lazy-image') !== false;
    }

    /** A data: image, a blank or a spinner/placeholder file (the CDN builder returns it untouched). */
    public static function isPlaceholderTag($tag)
    {
        $tag = (string) $tag;
        return strpos($tag, 'data:image') !== false || strpos($tag, 'blank') !== false
            || strpos($tag, 'gform_ajax_spinner') !== false || strpos($tag, 'spinner.svg') !== false
            || preg_match('#/placeholder\.(?:png|gif|jpe?g|webp|svg)(?:[?"\']|$)#i', $tag);
    }


    /**
     * The verdict for one rendered <img>.
     *
     * @param string      $tag                 the tag as the page wrote it (its class, its own
     *                                          loading and fetchpriority are read here)
     * @param string      $imageUrl            the image's URL as the lane resolved it
     * @param bool        $withinLeadingCount  the lane's own position test (rule 8)
     * @param string|null $hiddenReason        '' / 'device-hidden' / 'below-fold' when the lane
     *                                          already worked it out; null to work it out here
     * @return array  ['loading' => 'eager'|'lazy'|'keep', 'why' => reason, 'owner' => 'library'|'slider'|'']
     */
    public function forTag($tag, $imageUrl, $withinLeadingCount, $hiddenReason = null, $boxReserved = false)
    {
        $tag = (string) $tag;
        $imageUrl = (string) $imageUrl;
        // Rule 6's input is carried on every verdict ('priority'): the CDN builder's eager build
        // reads it whatever rule decided `loading`, as it did before the verdict existed.
        $priority = $this->priorityReason($tag, $imageUrl);
        $verdict = $this->decide($tag, $imageUrl, $withinLeadingCount, $hiddenReason, $priority, $boxReserved);
        $verdict['priority'] = $priority;
        return $verdict;
    }

    private function decide($tag, $imageUrl, $withinLeadingCount, $hiddenReason, $priority, $boxReserved = false)
    {
        $class = self::classOf($tag);
        $early = $this->ownershipVerdict($tag, $class);
        if ($early !== null) {
            if ($early['why'] === 'lib-owned' && $early['owner'] === 'slider' && !preg_match(self::SLIDER_ENGINE_PATTERN, strtolower($class))) {
                if ($hiddenReason === null) {
                    $hiddenReason = $this->hiddenReason($tag, $this->deviceHiddenImages);
                }
                if ($hiddenReason === 'hidden-slide') {
                    return self::verdict('lazy', 'hidden-slide');
                }
                if (self::sliderFoldLazyOn() && !$this->parksViewport && ($boxReserved || self::declaresBox($tag))
                    && $this->trustedFoldBelow($tag, $imageUrl)) {
                    return self::verdict('lazy', 'slide-below');
                }
            }
            return $early;
        }
        if ($hiddenReason === null) {
            $hiddenReason = $this->hiddenReason($tag, $this->deviceHiddenImages);
        }
        if ($hiddenReason !== '') {
            return self::verdict('lazy', $hiddenReason);
        }
        $authorEager = $this->authorEagerReason($tag, $class, $imageUrl);
        if ($authorEager !== '') {
            return self::verdict('eager', $authorEager);
        }
        if ($priority !== '') {
            return self::verdict('eager', $priority);
        }
        if (self::pageWroteEager($tag)) {
            return self::verdict('eager', 'theme-eager');
        }
        if ($this->foldVerdict($tag, $imageUrl) === 'above') {
            return self::verdict('eager', 'measured-above');
        }
        if ($withinLeadingCount) {
            return $this->trustedFoldBelow($tag, $imageUrl) ? self::verdict('lazy', 'fold-below') : self::verdict('eager', 'slot');
        }
        return self::isLogo($class, $imageUrl) ? self::verdict('keep', 'logo') : self::verdict('lazy', 'past-count');
    }

    /**
     * The reduced verdict for a writer that runs before the buffer exists (Modern Delivery's two
     * builders, the the_content picture lane): no position and no device set exist there, so
     * only rules 1-3, 5 and 7 apply. `lazy`/'past-count' means none of them matched; the caller's
     * own LCP heuristic then decides whether the image is eager.
     */
    public static function forTagPreBuffer($tag, $imageUrl)
    {
        $owner = self::forRequest();
        $tag = (string) $tag;
        $class = self::classOf($tag);
        $early = $owner->ownershipVerdict($tag, $class);
        if ($early !== null) {
            return $early;
        }
        $authorEager = $owner->authorEagerReason($tag, $class, (string) $imageUrl);
        if ($authorEager !== '') {
            return self::verdict('eager', $authorEager);
        }
        if (self::pageWroteEager($tag)) {
            return self::verdict('eager', 'theme-eager');
        }
        return self::isLogo($class, (string) $imageUrl) ? self::verdict('keep', 'logo') : self::verdict('lazy', 'past-count');
    }

    /**
     * The `loading` of an <img> a pre-buffer writer wraps (the the_content picture lane). With the
     * CDN off the CDN-off builder never sees a tag inside that <picture> (the picture stash hides
     * it), so this is the tag's last `loading` decision. An eager verdict (the exclude list, an
     * author marker, the theme's loading="eager") goes through the one writer's replace
     * semantics; a lazy one is added only where the caller's own test allows it ($lazyAllowed:
     * native lazy on, no `loading` yet, not its eager LCP marker); `keep` leaves the tag. The lane
     * used to decide only whether to ADD lazy, so a listed vitals.jpg kept WordPress core's
     * loading="lazy" and quiet-wire parked it (staging A/B, 2026-10-09).
     */
    public static function applyPreBufferLoading($tag, $imageUrl, $lazyAllowed)
    {
        $verdict = self::forTagPreBuffer($tag, $imageUrl);
        self::tallyPreBuffer($verdict);
        if ($verdict['loading'] === 'eager') {
            return self::forRequest()->applyLoading($tag, $verdict);
        }
        if ($verdict['loading'] === 'lazy' && $lazyAllowed) {
            $written = preg_replace('/<img\b/i', '<img loading="lazy"', (string) $tag, 1);
            return is_string($written) ? $written : $tag;
        }
        return $tag;
    }

    /**
     * Count a verdict the picture lane acted on, for the render's one receipt. Without it the
     * receipt missed that lane's decisions: staging, CDN off, read list:1 while the listed
     * vitals.jpg it wrapped was eager on the page.
     */
    private static function tallyPreBuffer(array $verdict)
    {
        if (self::$preBufferTally === null) {
            self::$preBufferTally = ['eager' => 0, 'lazy' => 0, 'kept' => 0, 'why' => []];
        }
        $side = $verdict['loading'] === 'keep' ? 'kept' : $verdict['loading'];
        self::$preBufferTally[$side]++;
        self::$preBufferTally['why'][$verdict['why']] = (isset(self::$preBufferTally['why'][$verdict['why']]) ? self::$preBufferTally['why'][$verdict['why']] : 0) + 1;
    }

    /** Rules 1-3: settings off, another plugin, a library or slider engine. Null: none applies. */
    private function ownershipVerdict($tag, $class)
    {
        if (!$this->viewportLazy && !$this->nativeLazy) {
            return self::verdict('keep', 'off');
        }
        if ($this->overridden) {
            return self::verdict('keep', 'override');
        }
        $libraryOwner = $this->libraryOwner($tag, $class);
        if ($libraryOwner !== '') {
            $verdict = self::verdict('keep', 'lib-owned');
            $verdict['owner'] = $libraryOwner;
            return $verdict;
        }
        return null;
    }

    /**
     * Rule 3's input: 'library' when a JS lazy library owns the tag (a library class word, a
     * data-lazy* attribute, a data-src behind a placeholder src), 'slider' when a slider engine
     * positions it, '' otherwise. Generic slide/swiper images are the slider's only where the
     * render does not park them: viewport lazy parks them by default (v7.21.264, columbus
     * Section-3: a whole band of slides fetched with no gesture), the wpc_park_slider_imgs
     * filter turns that off, and native lazy never stamps them (a transformed slide marked lazy
     * stays blank until it is slid into view).
     */
    private function libraryOwner($tag, $class)
    {
        if (self::libraryMarked($tag, $class) || self::dataSrcIsLazySlot($tag)) {
            return 'library';
        }
        $lowerClass = strtolower($class);
        if (preg_match(self::SLIDER_ENGINE_PATTERN, $lowerClass)) {
            return 'slider';
        }
        if (strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'swiper') !== false) {
            $parkedBySlider = $this->parksViewport && (!function_exists('apply_filters') || apply_filters('wpc_park_slider_imgs', true));
            if (!$parkedBySlider) {
                return 'slider';
            }
        }
        return '';
    }

    /**
     * Whose is an <img>'s data-src: a lazy loader's slot for the real image (true), or an
     * attribute the page wrote for its own scripts (false)? A slot is a data-src behind a src that
     * is not the image (none, empty, a data: URI, a placeholder, blank, spacer, pixel or 1x1 gif or png file), or on a tag a JS
     * lazy library marks (LIBRARY_CLASS_WORDS, a data-lazy* attribute). Only a slot may be taken
     * over by our builders; any other data-src reaches the browser as the page wrote it.
     *
     * Observed failure: WooCommerce writes every product gallery image with a real src (the
     * woocommerce_single size) and data-src = the full-size file, which its zoom reads first. The
     * builders took every data-src for a lazy slot, used it as the image and dropped it, so the
     * zoom loaded the 600 px rendition instead of the 1999 px original (rosella.com.co, ticket
     * 12244).
     */
    public static function dataSrcIsLazySlot($tag)
    {
        $tag = (string) $tag;
        if (!preg_match('/\sdata-src\s*=/i', $tag)) {
            return false;
        }
        if (self::libraryMarked($tag, self::classOf($tag))) {
            return true;
        }
        $src = preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $tag, $srcMatch) ? $srcMatch[2] : '';
        return $src === '' || stripos($src, 'data:') === 0 || stripos($src, 'placeholder') !== false || stripos($src, 'blank') !== false
            || self::srcIsSpacerFile($src);
    }

    private static function srcIsSpacerFile($src)
    {
        $path = (string) preg_replace('/[?#].*$/', '', (string) $src);
        return (bool) preg_match(self::SPACER_SRC_PATTERN, $path)
            && !preg_match('/-(?!1x1\.)\d+x\d+\.(?:gif|png)$/i', $path);
    }

    /** A JS lazy library's mark on the tag: one of its class words or a data-lazy* attribute. */
    private static function libraryMarked($tag, $class)
    {
        return (bool) array_intersect(self::classWords($class), self::LIBRARY_CLASS_WORDS)
            || (bool) preg_match('/\sdata-lazy[a-z-]*(?:\s*=|[\s>\/])/i', (string) $tag);
    }

    /**
     * Negotiated Delivery leaves a tag alone when its class carries one of its opt-out words
     * (NEGOTIATION_OPT_OUT_PATTERN): the builders then decide it. The set is that lane's own; the
     * verdict's marker and library words are wider and decide only `loading`, never the lane.
     */
    public static function classOptsOutOfNegotiation($class)
    {
        return (bool) preg_match(self::NEGOTIATION_OPT_OUT_PATTERN, (string) $class);
    }

    /**
     * Rule 4's input, worked out once for both lanes: 'device-hidden' when the markup hides the
     * image on this device, 'hidden-slide' when it sits in a carousel slide marked aria-hidden="true" or an inline display:none slide of a MetaSlider/FlexSlider container (the measured LCP never),
     * 'below-fold' when the census measured it below the fold, '' otherwise.
     * Such an image never takes a leading slot and always ends lazy.
     */
    public function hiddenReason($tag, $deviceHiddenImages)
    {
        if (!empty($deviceHiddenImages) && function_exists('wpc_device_hidden_has')) {
            foreach (['src', 'data-src', 'data-cp-src'] as $srcAttribute) {
                if (preg_match('/\b' . $srcAttribute . '="([^"]+)"/i', (string) $tag, $hiddenSrcMatch)
                    && wpc_device_hidden_has($deviceHiddenImages, $hiddenSrcMatch[1])) {
                    return 'device-hidden';
                }
            }
        }
        if (!empty($deviceHiddenImages) && function_exists('wpc_hidden_slide_has') && self::hiddenSlideDeferOn()) {
            foreach (['src', 'data-src', 'data-cp-src'] as $slideAttribute) {
                if (preg_match('/\b' . $slideAttribute . '="([^"]+)"/i', (string) $tag, $slideSrcMatch)
                    && wpc_hidden_slide_has($deviceHiddenImages, $slideSrcMatch[1])) {
                    if ($this->priorityReason((string) $tag, $slideSrcMatch[1]) !== 'measured-lcp') {
                        return 'hidden-slide';
                    }
                    break;
                }
            }
        }
        $fold = $this->foldVerdict($tag, '');
        if ($fold === 'below') {
            return 'below-fold';
        }
        if ($fold !== 'above' && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_is_census_below_fold')
            && wps_rewriteLogic::wpc_is_census_below_fold((string) $tag)
            && !($this->heroEagerOn() && $this->priorityReason((string) $tag, $this->tagUrl((string) $tag)) === 'measured-lcp')) {
            return 'below-fold';
        }
        return '';
    }

    private function countPinned()
    {
        if ($this->countPinned === null) {
            $this->countPinned = class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'lazy_count_pinned') && wps_cdn_rewrite::lazy_count_pinned();
        }
        return $this->countPinned;
    }

    private function foldOn()
    {
        return class_exists('wps_ic_atf_observation') && wps_ic_atf_observation::heroFold();
    }

    /** The measured LCP <img> is never put below the fold: policy.hero_fold, or the lever hero_eager set on ("on" is the only value it accepts). */
    private function heroEagerOn()
    {
        if ($this->foldOn()) {
            return true;
        }
        $lever = function_exists('wpc_lever') ? wpc_lever('hero_eager') : null;
        return $lever === 'on' || $lever === 1 || $lever === '1' || $lever === true;
    }

    /** The tag's own file address: src, else data-src, else data-cp-src. */
    private function tagUrl($tag)
    {
        foreach (['src', 'data-src', 'data-cp-src'] as $attribute) {
            if (preg_match('/\s' . $attribute . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match) && stripos($match[2], 'data:') !== 0) {
                return $match[2];
            }
        }
        return '';
    }

    private static function stemOf($address)
    {
        $address = (string) $address;
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_image_stem')) {
            return wps_rewriteLogic::wpc_image_stem($address);
        }
        $address = strtolower((string) basename((string) strtok($address, '?#')));
        $address = (string) preg_replace('/\.[a-z0-9]{2,5}$/', '', $address);
        return (string) preg_replace('/(-\d+x\d+|-\d{2,5}w|-scaled|@\dx)+$/', '', $address);
    }

    /** The file key of an address: its name after -scaled, -WxH and the extension. A next-gen copy written beside the original under its full name (hero.jpg.webp, hero-768x384.png.avif) is the original's file (crit, hub 152). */
    private static function fileKey($address)
    {
        $key = self::stemOf($address);
        return preg_match('/\.(?:jpe?g|png|gif|webp|avif)$/', $key) ? self::stemOf($key) : $key;
    }

    /**
     * Where the crit service's measurement of this page's first screen puts one image: 'above',
     * 'below', or 'unknown' (see THE FOLD on the class). Always 'unknown' while the site's
     * switch is off, while a person's leading count stands, and for a page whose own lcp.json
     * carries no `leg_status`.
     */
    public function foldVerdict($tag, $imageUrl = '')
    {
        if (!$this->foldOn() || $this->countPinned()) {
            return 'unknown';
        }
        $url = (string) $imageUrl;
        if ($url === '' || stripos($url, 'data:') === 0) {
            $url = $this->tagUrl((string) $tag);
        }
        $key = self::fileKey($url);
        if ($key === '') {
            return 'unknown';
        }
        if (isset($this->foldMemo[$key])) {
            return $this->foldMemo[$key];
        }
        $devices = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on') && wps_rewriteLogic::wpc_combined_crit_on())
            ? ['mobile', 'desktop'] : [(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop'];
        $answers = [];
        foreach ($devices as $device) {
            $answers[] = $this->foldAnswer($device, $key);
        }
        if (in_array('above', $answers, true)) {
            $answer = 'above';
        } elseif (count(array_unique($answers)) === 1 && $answers[0] === 'below') {
            $answer = 'below';
        } else {
            $answer = 'unknown';
        }
        $this->foldMemo[$key] = $answer;
        $this->foldCounts[$answer]++;
        return $answer;
    }

    /** The per-site switch of the hero-only fetch priority (policy.fetchpriority_hero). */
    public function heroPriorityOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('fetchpriority_hero') === 1;
    }

    /**
     * The fetch priority an image gets under policy.fetchpriority_hero, for every writer of one:
     * 'high' only on the page's measured LCP <img> of this device (of either device on a
     * device-blind host), or, while the page has no lcp.json of its own, on its first image
     * ($firstImage, the lane's own count); nothing when the document is there and its LCP is
     * text, a background or unknown. 'low' only on an image this verdict made lazy because the
     * measurement puts it below the first screen. '' leaves the lane's own rule (the site's own
     * preload list, a theme's attribute) alone. A measured-below image is never 'high'.
     * $verdict is what forTag() answered for the tag.
     */
    public function priorityFor($tag, $imageUrl, $firstImage, array $verdict)
    {
        if (!$this->heroPriorityOn()) {
            return '';
        }
        if ($verdict['loading'] === 'lazy') {
            return ($verdict['why'] === 'below-fold' && $this->foldVerdict($tag, $imageUrl) === 'below') ? 'low' : '';
        }
        if ($this->foldVerdict($tag, $imageUrl) === 'below') {
            return '';
        }
        return $this->keepsHigh($tag, $imageUrl, !$firstImage) ? 'high' : '';
    }

    /** True when the fold verdict for the image is 'below': a writer of fetchpriority="high" refuses it. */
    public function measuredBelow($tag, $imageUrl = '')
    {
        return $this->foldVerdict($tag, $imageUrl) === 'below';
    }

    /**
     * True when the crit service's measurement puts the image below the first screen of every device this render
     * serves, with policy.hero_fold on or off: each leg is measured and lists neither the image nor the LCP under
     * that file, and its list was not cut, read on the plugin's own page or carried from a measurement more than
     * seven days old. Anything else, a file it cannot name included, is false.
     */
    public function belowFirstScreen($tag, $imageUrl = '')
    {
        $url = (string) $imageUrl;
        if ($url === '' || stripos($url, 'data:') === 0) {
            $url = $this->tagUrl((string) $tag);
        }
        $key = self::fileKey($url);
        if ($key === '') {
            return false;
        }
        $devices = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on') && wps_rewriteLogic::wpc_combined_crit_on())
            ? ['mobile', 'desktop'] : [(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop'];
        foreach ($devices as $device) {
            if ($this->foldAnswer($device, $key) !== 'below') {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the crit service's measurement lists the image on the first screen of every device this render serves and names it as the LCP of none of them (cohort img_hold_first_screen):
     * each leg is measured, its first-screen list is there and carries the file, and neither the LCP element's names nor the url of one of its candidates (the images that were the LCP before the last one) do. A leg that is not measured, has no list, or lists another file is false,
     * and so is every image while a leading count a person chose stands (a non-default lazySkipCount, a per-page skip, the lever lazy_skip): the person decides which images wait.
     */
    public function listedFirstScreen($tag, $imageUrl = '')
    {
        if ($this->countPinned()) {
            return false;
        }
        $url = (string) $imageUrl;
        if ($url === '' || stripos($url, 'data:') === 0) {
            $url = $this->tagUrl((string) $tag);
        }
        $key = self::fileKey($url);
        if ($key === '') {
            return false;
        }
        $devices = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on') && wps_rewriteLogic::wpc_combined_crit_on())
            ? ['mobile', 'desktop'] : [(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop'];
        foreach ($devices as $device) {
            $facts = wps_ic_atf_observation::foldFacts($device);
            if ($facts === null || !$facts['measured'] || !is_array($facts['listed'])) {
                return false;
            }
            $element = wps_ic_atf_observation::lcpElement($device, true);
            $names = (array) $facts['lcp'];
            foreach ((isset($element['candidates']) && is_array($element['candidates'])) ? $element['candidates'] : [] as $candidate) {
                if (is_array($candidate) && !empty($candidate['url']) && is_string($candidate['url'])) {
                    $names[] = $candidate['url'];
                }
            }
            foreach ($names as $named) {
                if (self::fileKey($named) === $key) {
                    return false;
                }
            }
            $listed = false;
            foreach ($facts['listed'] as $named) {
                if (self::fileKey($named) === $key) {
                    $listed = true;
                    break;
                }
            }
            if (!$listed) {
                return false;
            }
        }
        return true;
    }

    private function foldAnswer($device, $key, $trusted = false)
    {
        $facts = wps_ic_atf_observation::foldFacts($device, $trusted);
        if ($facts === null || !$facts['measured']) {
            return 'unknown';
        }
        $lcpNotImage = !empty($facts['lcp_not_img']) && self::lcpKindGateOn();
        foreach (array_merge((array) $facts['listed'], $lcpNotImage ? [] : (array) $facts['lcp']) as $named) {
            if (self::fileKey($named) === $key) {
                return 'above';
            }
        }
        if ($lcpNotImage) {
            foreach ((array) $facts['lcp'] as $named) {
                if (self::fileKey($named) === $key) {
                    return 'unknown';
                }
            }
        }
        if ($trusted && empty($facts['trusted'])) {
            return 'unknown';
        }
        if ($facts['listed'] === null || $facts['cut'] > 0 || $facts['self'] || $facts['reason'] !== ''
            || ($facts['carried_age'] !== null && $facts['carried_age'] > self::CARRIED_MAX_AGE)) {
            return 'unknown';
        }
        return 'below';
    }

    /** Rule 5: the exclude list, an author marker class. '' when none. */
    private function authorEagerReason($tag, $class, $imageUrl)
    {
        if (self::listMatches($tag, $imageUrl, $this->excludeList)) {
            return 'list';
        }
        if (array_intersect(self::classWords($class), self::MARKER_CLASS_WORDS)) {
            return 'marker';
        }
        return '';
    }

    /**
     * The logo exception to rule 9: `logo` in the file URL or the class (a WordPress logo
     * excepted). Both builders never made a logo lazy, and the page's own attribute stayed; the
     * verdict keeps that past the leading count. Inside the count, or as the measured LCP image,
     * a logo is eager like any other image. Making it eager would also have overridden WordPress's loading="lazy" on every
     * partner-logo strip below the fold (staging: wix-logo.svg, logo-speed.jpg).
     */
    private static function isLogo($class, $imageUrl)
    {
        $lowerUrl = strtolower((string) $imageUrl);
        return (strpos($lowerUrl, 'logo') !== false || strpos(strtolower((string) $class), 'logo') !== false)
            && strpos($lowerUrl, 'wordpress') === false;
    }

    /**
     * Rule 6's input: 'theme-priority' when the page wrote fetchpriority="high" on the tag,
     * 'measured-lcp' when the file is the page's measured LCP <img> for either device, ''
     * otherwise. The wpc_lcp_force_eager filter turns both off for one image.
     */
    public function priorityReason($tag, $imageUrl)
    {
        $reason = '';
        if (preg_match('/\bfetchpriority\s*=\s*(["\']?)high\1/i', (string) $tag)) {
            $reason = 'theme-priority';
        } elseif (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_is_measured_lcp_match')
            && wps_rewriteLogic::wpc_is_measured_lcp_match((string) $imageUrl)) {
            $reason = 'measured-lcp';
        }
        if ($reason !== '' && function_exists('apply_filters') && !apply_filters('wpc_lcp_force_eager', true, $imageUrl)) {
            return '';
        }
        return $reason;
    }

    /** The page wrote loading="eager" on the tag. */
    public static function pageWroteEager($tag)
    {
        return (bool) preg_match('/\sloading\s*=\s*(["\']?)eager\1/i', (string) $tag);
    }

    public static function sliderFoldLazyOn()
    {
        if (defined('WPC_SLIDER_FOLD_LAZY_OFF') && WPC_SLIDER_FOLD_LAZY_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_slider_fold_lazy') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_slider_fold_lazy', true) !== false;
    }

    private static function declaresBox($tag)
    {
        foreach (['width', 'height'] as $side) {
            if (!preg_match('/\s' . $side . '\s*=\s*(["\']?)(\d{1,5})(?:px)?\1(?=[\s\/>])/i', (string) $tag, $m) || (int) $m[2] <= 0) {
                return false;
            }
        }
        return true;
    }

    public static function lcpKindGateOn()
    {
        if (defined('WPC_LCP_KIND_GATE_OFF') && WPC_LCP_KIND_GATE_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_lcp_kind_gate') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_lcp_kind_gate', true) !== false;
    }

    public function lcpCopyLow($tag, $imageUrl)
    {
        if (!self::lcpKindGateOn() || !self::lcpCopyLowOn() || !class_exists('wps_ic_atf_observation')
            || preg_match('/\sfetchpriority\s*=/i', (string) $tag) || $this->countPinned()) {
            return false;
        }
        $url = (string) $imageUrl;
        if ($url === '' || stripos($url, 'data:') === 0) {
            $url = $this->tagUrl((string) $tag);
        }
        $key = self::fileKey($url);
        if ($key === '') {
            return false;
        }
        foreach ($this->renderDevices() as $device) {
            $facts = wps_ic_atf_observation::foldFacts($device, true);
            if ($facts === null || !$facts['measured'] || empty($facts['trusted']) || empty($facts['lcp_not_img'])
                || !is_array($facts['listed'])) {
                return false;
            }
            foreach ($facts['listed'] as $named) {
                if (self::fileKey($named) === $key) {
                    return false;
                }
            }
            $names = false;
            foreach ((array) $facts['lcp'] as $named) {
                if (self::fileKey($named) === $key) {
                    $names = true;
                    break;
                }
            }
            if (!$names) {
                return false;
            }
        }
        return true;
    }

    public static function lcpCopyLowOn()
    {
        if (defined('WPC_LCP_COPY_LOW_OFF') && WPC_LCP_COPY_LOW_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_lcp_copy_low') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_lcp_copy_low', true) !== false;
    }

    /** The hidden-slide rule is on unless WPC_HIDDEN_SLIDE_DEFER_OFF, cohort off_hidden_slide_defer or the wpc_hidden_slide_defer filter turns it off. */
    public static function hiddenSlideDeferOn()
    {
        if (defined('WPC_HIDDEN_SLIDE_DEFER_OFF') && WPC_HIDDEN_SLIDE_DEFER_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_hidden_slide_defer') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_hidden_slide_defer', true) !== false;
    }

    /** The trusted-fold slot rule is on unless WPC_FIRST_N_MEASURED_FOLD_OFF, cohort off_first_n_measured_fold or the wpc_first_n_measured_fold filter turns it off. */
    public static function firstNMeasuredFoldOn()
    {
        if (defined('WPC_FIRST_N_MEASURED_FOLD_OFF') && WPC_FIRST_N_MEASURED_FOLD_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_first_n_measured_fold') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_first_n_measured_fold', true) !== false;
    }

    /**
     * True when an image inside the leading count keeps its slot but is not eager: the service's complete, trusted
     * measurement (every render device measured, every atf_complete stamp true, svc 3.198.365 or later, whatever
     * policy.hero_fold and policy.atf_complete_lazy say) lists neither the file nor the LCP on any of them and
     * puts it below the first screen. A leg anything less than that, a file the fold names, and a leading count a person chose answer false.
     */
    private function trustedFoldBelow($tag, $imageUrl)
    {
        if (!self::firstNMeasuredFoldOn() || $this->countPinned() || !class_exists('wps_ic_atf_observation')) {
            return false;
        }
        $url = (string) $imageUrl;
        if ($url === '' || stripos($url, 'data:') === 0) {
            $url = $this->tagUrl((string) $tag);
        }
        $key = self::fileKey($url);
        if ($key === '') {
            return false;
        }
        if (!isset($this->trustedBelowMemo[$key])) {
            $below = true;
            foreach ($this->renderDevices() as $device) {
                if ($this->foldAnswer($device, $key, true) !== 'below') {
                    $below = false;
                    break;
                }
            }
            $this->trustedBelowMemo[$key] = $below;
        }
        return $this->trustedBelowMemo[$key];
    }

    /** True when the verdict for this file inside the count was made lazy by the trusted fold (the slot stays counted): a writer of fetchpriority="high" refuses it. */
    public function slotTrimmed($tag, $imageUrl = '')
    {
        return $this->trustedFoldBelow($tag, $imageUrl);
    }

    /** The first-N trim and the one-high rule are on unless WPC_FIRST_N_TRIM_OFF or cohort off_first_n_trim turns them off. */
    public static function firstNTrimOn()
    {
        if (defined('WPC_FIRST_N_TRIM_OFF') && WPC_FIRST_N_TRIM_OFF) {
            return false;
        }
        return !(function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_first_n_trim') === 1);
    }

    /**
     * WHO KEEPS fetchpriority="high". One question, one answer, for every writer of it (the first-N
     * image writers through highAllowed(), the guessed image preloads through backgroundHighAllowed(),
     * the fetchpriority_hero writers through priorityFor()) and for the one pass that takes it off
     * markup somebody else wrote (wps_cdn_rewrite::stage_lcp_demote_competitors). Rule (Denis,
     * 2026-10-23, mvcustom.be ticket 12234): on a page whose LCP the service identified, only the LCP
     * file keeps high, in any form; on a page it did not identify, the first image that is neither a
     * logo nor icon-sized, once. Three readers used to answer it from three sets of fields: hartwood.biz's
     * phone banner, the <img> of the background LCP's own file, was refused by the writer and kept by
     * the strip (ticket 12072), and on greenvalleytint.com the CDN lane and the CDN-off lane gave the
     * page's high to different pictures (fixture A/B, 2026-10-28).
     */

    /** @var string|null  The running render's document, where an <img> LCP must be found (noteRenderDocument). */
    private static $identityDocument = null;
    /** @var array|null  ['sig' => the inputs it was read from, 'identity' => what lcpIdentity() answered]. */
    private static $identityMemo = null;

    /** The render's pristine document (stage_prelude): an <img> LCP that no tag of it carries is not this page's LCP. */
    public static function noteRenderDocument($html)
    {
        self::$identityDocument = (is_string($html) && $html !== '') ? $html : null;
        self::$identityMemo = null;
    }

    /**
     * The page's LCP as the priority rule reads it, from the page's own lcp.json, one leg per device
     * this render serves: ['known' => bool, 'type' => 'img'|'bg'|'text'|'video' (legs joined by '+'),
     * or 'unmeasured' (no leg) | 'withheld', 'keys' => the file keys that keep high, 'src' =>
     * 'json'|'derived']. An <img> leg gives the image's file key, a background leg ('bg', or the
     * observer's older 'bg-img') the background file's key, a text or video leg none. A leg with no
     * element is skipped (hartwood.biz: mobile null, desktop the background). A leg it cannot place, an
     * <img> no tag of the render's document carries (another page state, a device twin the crit does
     * not serve), a background without a file, a type it does not know, or a leg the service holds back
     * or says it could not tell (lcpHeld, lcpUnknown), makes the page 'withheld'
     * (DEVELOPING §3.4): demoting against a wrong identity takes high from the real LCP. With the
     * one-high rule off (off_first_n_trim) a page with no <img> leg is withheld too: that kill restores
     * the writers of 7.26.12, which knew only an <img> LCP.
     */
    public function lcpIdentity($document = null)
    {
        if (is_string($document) && $document !== self::$identityDocument) {
            self::noteRenderDocument($document);
        }
        $elements = [];
        $withheldByService = [];
        if (class_exists('wps_ic_atf_observation')) {
            foreach ($this->renderDevices() as $device) {
                $elements[$device] = wps_ic_atf_observation::lcpElement($device, true);
                $withheldByService[$device] = wps_ic_atf_observation::lcpHeld($device, true) || wps_ic_atf_observation::lcpUnknown($device);
            }
        }
        $sig = serialize([$elements, $withheldByService, self::firstNTrimOn()]);
        if (self::$identityMemo !== null && self::$identityMemo['sig'] === $sig) {
            return self::$identityMemo['identity'];
        }
        $types = [];
        $keys = [];
        $sources = [];
        $placed = true;
        foreach ($elements as $device => $element) {
            if (empty($element)) {
                continue;
            }
            // The service's own withholding is read whatever policy.hero_fold says: a leg it holds back
            // (lever_view.action hold) or says it could not tell (lcpUnknown: leg not measured, LCP read
            // failed) is not an identity to strip against, even when a type came through (DEVELOPING §3.4).
            // The container gate on 2026-10-28 (t1610 ZW4 "bg held") stripped the page's highs against a
            // background the service held. A leg with no element at all stays skipped (hartwood.biz:
            // mobile null, desktop the background).
            if (!empty($withheldByService[$device])) {
                $placed = false;
                break;
            }
            $type = (isset($element['type']) && is_string($element['type'])) ? strtolower(trim($element['type'])) : '';
            $type = ($type === 'bg-img') ? 'bg' : ($type === '' ? 'img' : $type);
            $key = '';
            foreach (['stem', 'url', 'net_url'] as $field) {
                if ($key === '' && !empty($element[$field]) && is_string($element[$field])) {
                    $key = self::fileKey($element[$field]);
                }
            }
            if (!in_array($type, ['img', 'bg', 'text', 'video'], true) || ($type === 'bg' && $key === '')
                || ($type === 'img' && ($key === '' || !$this->documentCarries($key)))) {
                $placed = false;
                break;
            }
            $types[] = $type;
            if ($type === 'img' || $type === 'bg') {
                $keys[] = $key;
            }
            $sources[] = (($element['lcp_confidence'] ?? '') === 'derived') ? 'derived' : 'json';
        }
        if (!$placed || ($types !== [] && !self::firstNTrimOn() && !in_array('img', $types, true))) {
            $identity = ['known' => false, 'type' => 'withheld', 'keys' => [], 'src' => ''];
        } elseif ($types === []) {
            $identity = ['known' => false, 'type' => 'unmeasured', 'keys' => [], 'src' => ''];
        } else {
            $identity = ['known' => true, 'type' => implode('+', array_unique($types)), 'keys' => array_values(array_unique($keys)),
                'src' => implode('+', array_unique($sources))];
        }
        self::$identityMemo = ['sig' => $sig, 'identity' => $identity];
        return $identity;
    }

    /** True when an <img> of the render's document names the file $key; with no document known (a pre-buffer writer), true. */
    private function documentCarries($key)
    {
        $document = self::$identityDocument;
        if ($document === null) {
            return true;
        }
        if (stripos($document, $key) === false || !preg_match_all('/<img\b[^>]*>/i', $document, $tags)) {
            return false;
        }
        foreach ($tags[0] as $tag) {
            if (stripos($tag, $key) !== false && in_array($key, $this->tagKeys($tag, ''), true)) {
                return true;
            }
        }
        return false;
    }

    /** The file keys of every address a tag names ($url, src, data-src, data-cp-src, data-wpc-fb, each srcset candidate). */
    private function tagKeys($tag, $url)
    {
        $addresses = [(string) $url];
        if (preg_match_all('/\s(src|data-src|data-cp-src|data-wpc-fb|srcset|data-srcset)\s*=\s*(["\'])(.*?)\2/is', (string) $tag, $attributes, PREG_SET_ORDER)) {
            foreach ($attributes as $attribute) {
                if (stripos($attribute[1], 'srcset') === false) {
                    $addresses[] = $attribute[3];
                    continue;
                }
                foreach (explode(',', $attribute[3]) as $candidate) {
                    $addresses[] = (string) strtok(trim($candidate), " \t\n");
                }
            }
        }
        $keys = [];
        foreach ($addresses as $address) {
            $address = trim((string) $address);
            if ($address !== '' && stripos($address, 'data:') !== 0) {
                $key = self::fileKey($address);
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }
        return array_keys($keys);
    }

    /** @var string  The file key keepsHigh() gave the page's one high on a page whose LCP is not identified; valid while wps_rewriteLogic::$firstNHighGiven stands (reset per render with it). */
    private static $grantedKey = '';

    /**
     * Whether $tag (an <img>, or '' for a preload of $url) may carry fetchpriority="high". Identified
     * page: only when a file it names has an LCP file key (the LCP <img> and its device twins, the <img>
     * of a background LCP's file), whatever else is high. Any other page: one file per render, the
     * first image asked that is neither a logo nor icon-sized while the page's one high is free
     * ($anotherHigh: a high the caller already knows of); the answer takes that high and remembers the
     * file, so every later ask for the same file is yes and every other is no, whichever writer or the
     * strip asks. One fetchpriority=high per page (7.26.13, Denis accepted) holds on both lanes: on
     * greenvalleytint.com (no measurement) WordPress core's high on the logo survived on the CDN-off
     * lane, which passes that tag through unbuilt, while the CDN lane gave the high to the background
     * preload (fixture A/B, 2026-10-28). With the one-high rule off, any image while no other high is known.
     */
    public function keepsHigh($tag, $url = '', $anotherHigh = false)
    {
        $identity = $this->lcpIdentity();
        if ($identity['known']) {
            return array_intersect($this->tagKeys((string) $tag, (string) $url), $identity['keys']) !== [];
        }
        if (!self::firstNTrimOn()) {
            return !$anotherHigh;
        }
        $counter = class_exists('wps_rewriteLogic') && property_exists('wps_rewriteLogic', 'firstNHighGiven');
        $given = $counter && wps_rewriteLogic::$firstNHighGiven;
        if ($given && self::$grantedKey !== '') {
            return in_array(self::$grantedKey, $this->tagKeys((string) $tag, (string) $url), true);
        }
        if ($anotherHigh || $given) {
            return false;
        }
        $address = (string) $url;
        if ($address === '' || stripos($address, 'data:') === 0) {
            $address = $this->tagUrl((string) $tag);
        }
        if (self::isLogo(self::classOf($tag), $address) || self::iconSized($tag, $address)) {
            return false;
        }
        self::$grantedKey = self::fileKey($address);
        if ($counter) {
            wps_rewriteLogic::$firstNHighGiven = true;
        }
        return true;
    }

    /** Whether this eager first-N image may carry fetchpriority=high: never one the trusted fold made lazy; every one with the one-high rule off; else keepsHigh(). */
    public function highAllowed($tag, $imageUrl, $alreadyGiven)
    {
        if ($this->slotTrimmed($tag, $imageUrl)) {
            return false;
        }
        if (!self::firstNTrimOn()) {
            return true;
        }
        return $this->keepsHigh($tag, $imageUrl, $alreadyGiven);
    }

    /**
     * Whether a guessed image preload (above-the-fold background, header logo, hero hint, crit background, Modern Delivery) for $address may carry
     * fetchpriority=high: keepsHigh() for the address, the header logo never on a page whose LCP is not identified. Takes the page's one high through the shared counter.
     */
    public function backgroundHighAllowed($address, $anotherHigh, $logo = false)
    {
        if (!self::firstNTrimOn()) {
            return true;
        }
        if (($logo && !$this->lcpIdentity()['known']) || !$this->keepsHigh('', (string) $address, $anotherHigh)) {
            return false;
        }
        return $this->takeHigh();
    }

    private function takeHigh()
    {
        if (class_exists('wps_rewriteLogic') && property_exists('wps_rewriteLogic', 'firstNHighGiven')) {
            wps_rewriteLogic::$firstNHighGiven = true;
        }
        return true;
    }

    /** An SVG, or an image whose every declared side is under 200 px (icon, badge, avatar), is not the hero. */
    private static function iconSized($tag, $url)
    {
        if (preg_match('/\.svg(?:[?#]|$)/i', (string) $url)) {
            return true;
        }
        $declared = 0;
        foreach (['width', 'height'] as $side) {
            if (preg_match('/\s' . $side . '\s*=\s*(["\']?)(\d{1,5})\1/i', (string) $tag, $m) && (int) $m[2] > 0) {
                if ((int) $m[2] >= 200) {
                    return false;
                }
                $declared++;
            }
        }
        return $declared > 0;
    }

    // Same device set foldVerdict() / priorityFor() read.
    private function renderDevices()
    {
        return (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on') && wps_rewriteLogic::wpc_combined_crit_on())
            ? ['mobile', 'desktop'] : [(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop'];
    }


    /**
     * The one attribute writer. Strips every `loading` from the built tag and writes the
     * verdict's value once, right after `<img`. `keep` returns the tag as built, with the page's
     * own attribute. An eager verdict is written only where it replaces an attribute the tag
     * carried: eager is what the browser does without one, and an unconditional loading="eager"
     * on every leading image made logo_rightsize read each header logo as "over" and lazify it.
     */
    public function applyLoading($tag, array $verdict)
    {
        $this->record($verdict);
        if ($verdict['loading'] === 'keep') {
            return $tag;
        }
        $attributePattern = '/\sloading\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i';
        $carried = preg_match_all($attributePattern, $tag);
        if ($carried > 1) {
            $this->duplicatesStripped++;
        }
        $stripped = $carried > 0 ? preg_replace($attributePattern, '', $tag) : $tag;
        if (!is_string($stripped)) {
            return $tag;
        }
        if ($verdict['loading'] === 'eager' && $carried === 0) {
            return $stripped;
        }
        $written = preg_replace('/^<img\b/i', '<img loading="' . $verdict['loading'] . '"'
            . (($verdict['why'] === 'slide-below' && $verdict['loading'] === 'lazy' && stripos($stripped, self::SLIDE_LAZY_MARK) === false) ? ' ' . self::SLIDE_LAZY_MARK . '="1"' : ''), $stripped, 1);
        return is_string($written) ? $written : $tag;
    }

    /** Count one verdict a writer acted on, for the receipt. */
    public function record(array $verdict)
    {
        $side = $verdict['loading'] === 'keep' ? 'kept' : $verdict['loading'];
        if (isset($this->verdictCounts[$side])) {
            $this->verdictCounts[$side]++;
        }
        if (isset($this->reasonCounts[$verdict['why']])) {
            $this->reasonCounts[$verdict['why']]++;
        }
    }

    /**
     * The exclude list's matcher. An entry `#name` matches as a substring of the class
     * attribute; any other entry as a substring of the image URL (the src, or data-src when the
     * src is a data: placeholder), tested as written and with the WordPress size suffix removed
     * (-WxH, -scaled, @2x before the extension), so `name.webp` matches `name-1024x439.webp`.
     * Case-insensitive. srcset candidates, alt text and the file on disk are not matched.
     */
    public static function listMatches($tag, $imageUrl, $list = null)
    {
        $list = $list === null ? self::excludeList() : $list;
        if (empty($list)) {
            return false;
        }
        $tag = (string) $tag;
        $url = strtolower(trim((string) $imageUrl));
        if (($url === '' || strpos($url, 'data:') === 0)
            && preg_match('/\sdata-src\s*=\s*(["\'])(.*?)\1/is', $tag, $dataSrcMatch)) {
            $url = strtolower(trim($dataSrcMatch[2]));
        }
        if ($url === '' && preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $tag, $srcMatch)) {
            $url = strtolower(trim($srcMatch[2]));
        }
        if (strpos($url, 'data:') === 0) {
            $url = '';
        }
        $unsuffixedUrl = $url === '' ? '' : (string) preg_replace('/(?:-\d+x\d+|-\d{2,5}w|-scaled|@\dx)+(?=\.[a-z0-9]{2,5}(?:[?#]|$))/', '', $url);
        $class = strtolower(self::classOf($tag));
        foreach ($list as $entry) {
            if ($entry[0] === '#') {
                $className = substr($entry, 1);
                if ($className !== '' && $class !== '' && strpos($class, $className) !== false) {
                    return true;
                }
                continue;
            }
            if ($url !== '' && (strpos($url, $entry) !== false || strpos($unsuffixedUrl, $entry) !== false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The same list over arbitrary markup: any entry but a `#class` one, as a case-insensitive
     * substring. The keep decision for a <video> asks it, so an author can keep a video's source
     * by naming its file, as the old lazy option let them.
     */
    public static function listMatchesMarkup($markup)
    {
        $markup = strtolower((string) $markup);
        if ($markup === '') {
            return false;
        }
        foreach (self::excludeList() as $entry) {
            if ($entry[0] !== '#' && strpos($markup, $entry) !== false) {
                return true;
            }
        }
        return false;
    }

    /** The one exclude list: wpc-excludes['lazy'], site-wide, normalised. */
    public static function excludeList()
    {
        // Normalised once per stored value: logo_rightsize and the video keep decision ask per tag.
        static $memoRaw = null, $memoList = [];
        $excludes = function_exists('get_option') ? get_option('wpc-excludes') : [];
        $raw = is_array($excludes) && isset($excludes['lazy']) ? $excludes['lazy'] : [];
        if ($memoRaw !== $raw) {
            $memoRaw = $raw;
            $memoList = self::normaliseList($raw);
        }
        return $memoList;
    }

    /** Entries trimmed, lower case, unique, empty ones dropped; a string is one entry per line. */
    public static function normaliseList($list)
    {
        if (is_string($list)) {
            $list = preg_split('/\r\n|\r|\n/', $list);
        }
        $normalised = [];
        foreach ((array) $list as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry !== '' && $entry !== '#') {
                $normalised[$entry] = true;
            }
        }
        return array_keys($normalised);
    }

    /**
     * The one lazy-verdict receipt of this render, written at image_pins beside img-sizing.
     * dup_stripped counts tags whose built string carried more than one `loading` when the
     * writer saw it: zero once no builder appends its own, so a re-added append shows here.
     * eager / lazy / kept count verdicts, not written attributes: an eager verdict on a tag that
     * carried no `loading` writes nothing (the browser default). The counts include the verdicts
     * the the_content picture lane acted on before the render; pre_buffer says how many.
     */
    public function receipt($uri = '')
    {
        if ($this->receiptWritten || !function_exists('wpc_cache_first_log')) {
            return;
        }
        $this->receiptWritten = true;
        $preBuffer = self::$preBufferTally;
        self::$preBufferTally = null;
        $preBufferCount = 0;
        if ($preBuffer !== null) {
            foreach (['eager', 'lazy', 'kept'] as $side) {
                $this->verdictCounts[$side] += $preBuffer[$side];
                $preBufferCount += $preBuffer[$side];
            }
            foreach ($preBuffer['why'] as $why => $n) {
                if (isset($this->reasonCounts[$why])) {
                    $this->reasonCounts[$why] += $n;
                }
            }
        }
        $this->pageDataSrcReceipt((string) $uri);
        if (array_sum($this->verdictCounts) === 0) {
            return;
        }
        $foldField = $this->foldOn() ? ['fold' => ($this->countPinned() ? 'pinned' : array_filter($this->foldCounts))] : [];
        wpc_cache_first_log('lazy-verdict', '', (string) $uri, $foldField + [
            'lane' => $this->lane,
            'dev' => function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile() ? 'mobile' : 'desktop',
            'mode' => $this->mode(),
            'count' => $this->leadingCount,
            'eager' => $this->verdictCounts['eager'],
            'lazy' => $this->verdictCounts['lazy'],
            'kept' => $this->verdictCounts['kept'],
            'why' => array_filter($this->reasonCounts),
            'dup_stripped' => $this->duplicatesStripped,
            'pre_buffer' => $preBufferCount,
            // The leading slots Negotiated Delivery's images took (null: no walk); the builders
            // were left `count` less this.
            'nd_slots' => $this->documentWalked ? $this->negotiatedLeadingSlots : null,
        ]);
    }

    /** A builder kept one tag on the page's own data-src (it asked dataSrcIsLazySlot()). */
    public function notePageDataSrcKept()
    {
        $this->pageDataSrcKept++;
    }

    /**
     * `page-data-src-kept {lane, n}`: why a page's gallery is not lazy-loaded or swapped by us.
     * Sampled, one line an hour per page: a WooCommerce product page writes such tags on every
     * render by design. No line when no tag was kept.
     */
    private function pageDataSrcReceipt($uri)
    {
        if ($this->pageDataSrcKept === 0) {
            return;
        }
        $fields = ['lane' => $this->lane, 'n' => $this->pageDataSrcKept];
        if (function_exists('wpc_belt_rx_admit')) {
            $gate = wpc_belt_rx_admit(['page-data-src-kept'], '', $uri);
            if (!in_array('page-data-src-kept', $gate['admit'], true)) {
                return;
            }
            if (!empty($gate['sampled'])) {
                $fields['sampled'] = 1;
            }
        }
        wpc_cache_first_log('page-data-src-kept', '', $uri, $fields);
    }

    /** What the receipt would say, for the trace and the checks. */
    public function counts()
    {
        return $this->verdictCounts + ['why' => array_filter($this->reasonCounts), 'dup_stripped' => $this->duplicatesStripped];
    }

    private function mode()
    {
        if (!$this->viewportLazy && !$this->nativeLazy) {
            return 'off';
        }
        if ($this->overridden) {
            return 'override';
        }
        return $this->viewportLazy ? 'viewport' : 'native';
    }

    private static function verdict($loading, $why)
    {
        return ['loading' => $loading, 'why' => $why, 'owner' => ''];
    }

    private static function classOf($tag)
    {
        return preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/is', (string) $tag, $classMatch) ? $classMatch[2] : '';
    }

    private static function classWords($class)
    {
        $words = preg_split('/\s+/', strtolower(trim((string) $class)));
        return $words === false ? [] : array_filter($words, 'strlen');
    }
}
