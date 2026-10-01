<?php

/**
 * The one owner of a render's image geometry: the `sizes` an <img> carries, the width and height
 * it is given when the page wrote none, and the aspect pin that holds its box before the file
 * decodes.
 *
 * Twenty-nine passes used to answer these three questions, each from its own reading of the
 * observation, the file name, the attachment meta or the file itself, and later passes repaired
 * what earlier ones wrote. So one file got two answers on one page: glass-inspirations.co.uk
 * shipped its 626 px hero slide at the 149w rung because a menu thumbnail of the same file was
 * measured first; greenvalleytint.com's 155 px header logo carried `sizes="(max-width: 767.98px)
 * 155px, 985px"` on a desktop render, the desktop measurement in the mobile leg and the file's
 * own width in the desktop leg. Each question now has one answer per image, decided once and
 * memoised for the render, and every pass that writes the attribute asks here.
 *
 *   sizesFor($src, $tagWidth, $tagHeight, $pageSizes)  the slot width, from the observation only
 *   dimsFor($src, $class, $offersOtherRungs, $style)   the file's natural size, from what names this file
 *   pinned()                                 count one aspect pin written from those dims
 *   sameUrlLadderDropped()                   count one srcset whose candidates are one URL
 *   receipt($uri)                            the one img-sizing receipt of the render
 *
 * SIZES. The service measures, per device, the box each above-the-fold and oversized file paints
 * in (wps_ic_atf_observation::widths(), which withholds a file the page uses more than once:
 * one `sizes` keyed by file name hits every copy, so no single width is right for all of them).
 * A render pinned to one device writes that device's leg alone; a render one cache copy serves
 * to both devices writes both legs under one breakpoint, `(max-width: 767.98px)`, the grammar the
 * crit service and the CSS use (a 768 px viewport is desktop). A combined render whose file was
 * measured on one device only writes that leg under its device's breakpoint and hands the other
 * device the page's own value, when the caller holds it (see mergeOneLeg()). When the observation
 * cannot answer — the file is multi-use, this device was not measured, one leg of a combined
 * render is missing and the caller does not hold the page's value, or the measured box
 * contradicts the tag's own aspect — the answer is nothing, and the tag keeps the sizes the page
 * wrote. The owner never invents a width.
 *
 * DIMS. Only for the file itself, in this order: the `-WxH` suffix WordPress puts on a sub-size;
 * the attachment meta entry whose file name IS this file (the full size or one sub-size, never the
 * full size's numbers for a sub-size's URL); the service's natural size, but only when the tag
 * offers no other rung, since the service measured whatever rung the browser picked; one bounded
 * read of the file (wpc_image_file_dims(), at most MEASURE_LIMIT per request). Otherwise nothing.
 * A tag whose inline style sets its width or height gets nothing either (styleSetsSize()).
 *
 * Kill switch: define WPC_IMAGE_SIZING_OFF true and every question answers nothing.
 */
final class wps_ic_image_sizing
{
    /** The one breakpoint a two-leg `sizes` is written with. */
    const BREAKPOINT = '(max-width: 767.98px)';

    /** The desktop side of BREAKPOINT, for a desktop leg written ahead of the page's own value. */
    const DESKTOP_BREAKPOINT = '(min-width: 768px)';

    /** File reads (getimagesize / SVG head) allowed per request. */
    const MEASURE_LIMIT = 40;

    /** Why sizesFor() withheld: the reasons the receipt counts. */
    const WITHHELD_REASONS = ['multi-use', 'no-observation', 'one-leg', 'aspect'];

    /** @var string|null  'mobile' or 'desktop', resolved on first use. */
    private $device;
    /** @var bool|null  One cache copy serves both devices (combined crit), resolved on first use. */
    private $combined;

    /** @var array  sizesFor() answers per stem|tag-width|tag-height. */
    private $sizesAnswers = [];
    /** @var array  dimsFor() answers per file key|other-rungs flag. */
    private $dimsAnswers = [];

    /** @var array  What this render decided, for the receipt. */
    private $sizesCounts = ['written' => 0, 'withheld' => ['multi-use' => 0, 'no-observation' => 0, 'one-leg' => 0, 'aspect' => 0]];
    private $dimsCounts = ['suffix' => 0, 'meta' => 0, 'observed' => 0, 'measured' => 0, 'withheld' => 0];
    private $pinCount = 0;
    private $pinKinds = ['svg' => 0, 'svg-qw' => 0, 'lazy' => 0];
    private $aspectOverrides = 0;
    private $sameUrlLadders = 0;
    private $receiptWritten = false;

    /** @var array  File facts per request: normalised URL => [w, h, source] or null. A fact about
     *              a file, not render state, so it survives across the renders of one request. */
    private static $fileFacts = [];
    /** @var int  File reads made this request (bounded by MEASURE_LIMIT). */
    private static $filesMeasured = 0;

    /**
     * @param string|null $device    'mobile' | 'desktop'; null = the render's device
     *                               (wps_rewriteLogic::$isMobile, the source the preload owner uses)
     * @param bool|null   $combined  null = wps_rewriteLogic::wpc_combined_crit_on()
     */
    public function __construct($device = null, $combined = null)
    {
        $this->device = ($device === 'mobile' || $device === 'desktop') ? $device : null;
        $this->combined = ($combined === null) ? null : (bool) $combined;
    }

    public static function off()
    {
        return defined('WPC_IMAGE_SIZING_OFF') && WPC_IMAGE_SIZING_OFF;
    }

    public function device()
    {
        if ($this->device === null) {
            $this->device = (class_exists('wps_rewriteLogic') && !empty(wps_rewriteLogic::$isMobile)) ? 'mobile' : 'desktop';
        }
        return $this->device;
    }

    public function combined()
    {
        if ($this->combined === null) {
            $this->combined = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on')
                && wps_rewriteLogic::wpc_combined_crit_on();
        }
        return $this->combined;
    }

    /**
     * The service's name for the file an URL points at: the basename without query, extension(s),
     * the `-WxH` sub-size suffix and WordPress's `-scaled`, lowercased. Every rung of one upload
     * shares it, which is exactly why a stem-keyed `sizes` has one answer per upload.
     */
    public static function stem($url)
    {
        $base = basename((string) preg_replace('/[?#].*$/', '', html_entity_decode((string) $url, ENT_QUOTES)));
        $base = (string) preg_replace('/(\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?))+$/i', '', $base);
        $base = (string) preg_replace('/-\d+x\d+$/', '', $base);
        $base = (string) preg_replace('/-scaled$/i', '', $base);
        return strtolower($base);
    }

    // ── (a) sizes ────────────────────────────────────────────────────────────────────────────

    /**
     * ['sizes' => value, 'why' => observed|one-leg-merged] or ['sizes' => '', 'why' => <withheld
     * reason>] for the image at $src in a tag whose own width/height are $tagWidth/$tagHeight
     * (0 = none). $pageSizes is the tag's own `sizes` as the page wrote it ('' = it wrote none);
     * null when the caller does not hold the tag, and then a one-leg combined answer withholds.
     */
    public function sizesFor($src, $tagWidth = 0, $tagHeight = 0, $pageSizes = null)
    {
        if (self::off()) {
            return ['sizes' => '', 'why' => 'off'];
        }
        $stem = self::stem($src);
        $key = $stem . '|' . (int) $tagWidth . '|' . (int) $tagHeight;
        $firstAsk = !isset($this->sizesAnswers[$key]);
        if ($firstAsk) {
            $this->sizesAnswers[$key] = self::observedSizes($stem, (int) $tagWidth, (int) $tagHeight, $this->device(), $this->combined());
        }
        $answer = self::withPageLeg($this->sizesAnswers[$key], $pageSizes, (int) $tagWidth);
        if ($firstAsk) {
            if ($answer['sizes'] !== '') {
                $this->sizesCounts['written']++;
            } elseif (isset($this->sizesCounts['withheld'][$answer['why']])) {
                $this->sizesCounts['withheld'][$answer['why']]++;
            }
        }
        return $answer;
    }

    /** A one-leg withhold turned into the merged value when the caller holds the page's value. */
    private static function withPageLeg($answer, $pageSizes, $tagWidth)
    {
        if ($pageSizes === null || $answer['why'] !== 'one-leg' || !isset($answer['leg'])) {
            return $answer;
        }
        return ['sizes' => self::mergeOneLeg($answer['leg'][0], $answer['leg'][1], $pageSizes, $tagWidth), 'why' => 'one-leg-merged'];
    }

    /** The rule behind sizesFor(), without memo or counts. See SIZES on the class. */
    public static function observedSizes($stem, $tagWidth, $tagHeight, $device, $combined, $pageSizes = null)
    {
        $withheld = function ($why) { return ['sizes' => '', 'why' => $why]; };
        if ($stem === '' || strlen($stem) < 3 || !preg_match('/^[a-z0-9._@-]+$/', $stem)) {
            return $withheld('no-observation');
        }
        if (isset(wps_ic_atf_observation::multiUseStems(wps_ic_atf_observation::SCOPE_ALL)[$stem])) {
            return $withheld('multi-use');
        }
        $slots = wps_ic_atf_observation::widths(wps_ic_atf_observation::SCOPE_ALL);
        if (!isset($slots[$stem])) {
            return $withheld('no-observation');
        }
        $slot = $slots[$stem];
        $legs = [];
        $aspectDropped = false;
        foreach (['m' => 'mobile', 'd' => 'desktop'] as $leg => $legDevice) {
            $width = (int) $slot[$leg];
            if ($width < 24 || $width > 2000) {
                continue;
            }
            // A box whose aspect contradicts the tag's own width/height was not a layout of this
            // image (a crop of the same upload, or a box measured mid-transition): that leg is not
            // an answer for this tag. Not dated by crit-push v3.198.283: its guard compares a box
            // with the OBSERVED tag's attributes, skips JS-lazy placeholder entries, and counts
            // `dom_n` by path + extension where this key is the bare stem, so a same-named file in
            // another upload folder or format still reaches here with the other tag's box.
            $height = isset($slot[$leg . 'h']) ? (int) $slot[$leg . 'h'] : 0;
            if ($tagWidth > 0 && $tagHeight > 0 && $height > 0
                && abs(($width / $height) / ($tagWidth / $tagHeight) - 1) > 0.34) {
                $aspectDropped = true;
                continue;
            }
            $legs[$legDevice] = $width;
        }
        if ($combined) {
            if (isset($legs['mobile'], $legs['desktop'])) {
                $value = ($legs['mobile'] === $legs['desktop'])
                    ? $legs['desktop'] . 'px'
                    : self::BREAKPOINT . ' ' . $legs['mobile'] . 'px, ' . $legs['desktop'] . 'px';
                return ['sizes' => $value, 'why' => 'observed'];
            }
            if (!$aspectDropped && count($legs) === 1) {
                return self::withPageLeg(['sizes' => '', 'why' => 'one-leg', 'leg' => [key($legs), reset($legs)]], $pageSizes, $tagWidth);
            }
            return $withheld($aspectDropped ? 'aspect' : (empty($legs) ? 'no-observation' : 'one-leg'));
        }
        if (isset($legs[$device])) {
            return ['sizes' => $legs[$device] . 'px', 'why' => 'observed'];
        }
        return $withheld($aspectDropped ? 'aspect' : 'no-observation');
    }

    /**
     * One measured leg of a combined render, written under its device's breakpoint ahead of the
     * page's own value, which the other device keeps: `(max-width: 767.98px) 362px, (max-width:
     * 1387px) 100vw, 1387px`. Withholding the whole value cost staging.wpcompress.com /features/
     * on 7.24.40: `speeds-two-column.jpg` was measured at 362 px on mobile only (it is below the
     * fold on desktop), so the page's own-width ladder stood for both devices and the mobile LCP
     * loaded a 26 KB JPEG rung where the 362 px slot had taken a 5 KB AVIF. Writing the one leg for
     * both devices (the pre-owner behaviour) under-serves the device nobody measured, so the other
     * device gets the page's value, not a guess. When the page wrote no `sizes` the other device
     * gets the tag's width, or `100vw`, which is what the browser used without one. A leading
     * `auto` stays first (it is only valid there), and a leg this method wrote on an earlier ask
     * (Negotiated Delivery asks before image_sizes does) is not written twice.
     */
    public static function mergeOneLeg($legDevice, $width, $pageSizes, $tagWidth = 0)
    {
        $other = trim((string) $pageSizes);
        $auto = false;
        if (preg_match('/^auto\s*(?:,\s*|$)/i', $other, $autoPrefix)) {
            $auto = true;
            $other = trim(substr($other, strlen($autoPrefix[0])));
        }
        $other = trim((string) preg_replace('/^(?:\(max-width:\s*767\.98px\)|\(min-width:\s*768px\))\s+\d+px\s*,\s*/i', '', $other));
        if ($other === '') {
            $other = ((int) $tagWidth > 0) ? (int) $tagWidth . 'px' : '100vw';
        }
        $breakpoint = ($legDevice === 'mobile') ? self::BREAKPOINT : self::DESKTOP_BREAKPOINT;
        return ($auto ? 'auto, ' : '') . $breakpoint . ' ' . (int) $width . 'px, ' . $other;
    }

    // ── (b) intrinsic dimensions ─────────────────────────────────────────────────────────────

    /**
     * ['w' => int|float, 'h' => int|float, 'source' => suffix|meta|observed|measured] for the file
     * at $src, or null. $classAttr carries `wp-image-{ID}` when the page printed it;
     * $offersOtherRungs is true when the tag has a srcset, which is what makes the service's
     * natural size ambiguous (it measured whichever rung the browser chose). $styleAttr is the
     * tag's inline style: one that sets width or height gets no dims (styleSetsSize()).
     */
    public function dimsFor($src, $classAttr = '', $offersOtherRungs = false, $styleAttr = '')
    {
        if (self::off()) {
            return null;
        }
        $key = self::fileKey($src) . '|' . ($offersOtherRungs ? 1 : 0) . '|' . (preg_match('/\bwp-image-(\d+)\b/', (string) $classAttr, $m) ? $m[1] : '')
            . (self::styleSetsSize($styleAttr) ? '|styled' : '');
        if (!array_key_exists($key, $this->dimsAnswers)) {
            $answer = self::fileDims($src, $classAttr, $offersOtherRungs, $styleAttr);
            $this->dimsAnswers[$key] = $answer;
            $this->dimsCounts[$answer === null ? 'withheld' : $answer['source']]++;
        }
        return $this->dimsAnswers[$key];
    }

    /** The rule behind dimsFor(), with the per-request file memo but no render counts. */
    public static function fileDims($src, $classAttr = '', $offersOtherRungs = false, $styleAttr = '')
    {
        if (self::off() || self::styleSetsSize($styleAttr)) {
            return null;
        }
        $src = html_entity_decode((string) $src, ENT_QUOTES);
        if ($src === '' || stripos($src, 'data:') === 0) {
            return null;
        }
        $path = (string) parse_url(preg_replace('/[?#].*$/', '', $src), PHP_URL_PATH);
        $file = basename($path);
        $name = (string) preg_replace('/(\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?))+$/i', '', $file);

        // 1. The sub-size suffix WordPress writes is the file's own size.
        if (preg_match('/-(\d{1,5})x(\d{1,5})$/', $name, $suffix) && (int) $suffix[1] > 0 && (int) $suffix[2] > 0) {
            return ['w' => (int) $suffix[1], 'h' => (int) $suffix[2], 'source' => 'suffix'];
        }

        // 2. The attachment meta entry for exactly this file.
        if (preg_match('/\bwp-image-(\d+)\b/', (string) $classAttr, $id) && function_exists('wp_get_attachment_metadata')) {
            $meta = wp_get_attachment_metadata((int) $id[1]);
            if (is_array($meta)) {
                $candidates = [];
                if (!empty($meta['file'])) {
                    $candidates[] = [basename((string) $meta['file']), isset($meta['width']) ? $meta['width'] : 0, isset($meta['height']) ? $meta['height'] : 0];
                }
                foreach ((isset($meta['sizes']) && is_array($meta['sizes'])) ? $meta['sizes'] : [] as $size) {
                    if (is_array($size) && !empty($size['file'])) {
                        $candidates[] = [basename((string) $size['file']), isset($size['width']) ? $size['width'] : 0, isset($size['height']) ? $size['height'] : 0];
                    }
                }
                foreach ($candidates as $candidate) {
                    if ($candidate[0] === $file && (int) $candidate[1] > 0 && (int) $candidate[2] > 0) {
                        return ['w' => (int) $candidate[1], 'h' => (int) $candidate[2], 'source' => 'meta'];
                    }
                }
            }
        }

        // 3. The service's natural size, when the tag offered the browser no other rung and every
        //    observation of the upload names the same size. The service records the rung the
        //    browser loaded, keyed by the upload's stem: renegadeuxs.com /system/sandman/ uses one
        //    upload three times and the observation holds 1439x2104 for two uses and 1350x1973 for
        //    the third (a srcset rung), so a stem with two natural sizes says nothing about any one
        //    file.
        if (!$offersOtherRungs && class_exists('wps_ic_atf_observation')) {
            $naturalByStem = wps_ic_atf_observation::naturalSizes();
            $natural = isset($naturalByStem[self::stem($src)]) ? $naturalByStem[self::stem($src)] : [];
            if (count($natural) === 1) {
                $only = reset($natural);
                if ($only[0] >= 8 && $only[1] >= 8) {
                    return ['w' => $only[0], 'h' => $only[1], 'source' => 'observed'];
                }
            }
        }

        // 4. One bounded read of the file itself, when it is this site's.
        return self::measure($src);
    }

    /**
     * Does a tag's inline style set its width or height (any value)? Such a tag gets no width or
     * height attribute: the style already sizes the box, and an attribute beside it becomes the
     * constraint on the axis the style left open. staging.wpcompress.com /wp-rocket-alternative/ on
     * 7.24.40: the header `top-logo.svg` carried `style="max-width:100%;margin-bottom:-10px;
     * height:45px"` and no width, drawn at 222x45 from its 561:120 aspect; the file's
     * `width="153" height="31"` written beside it made the box 153x45 and contained the mark at
     * 153x31. max-width, min-height and the like are not a size and do not count.
     */
    public static function styleSetsSize($styleAttr)
    {
        $styleAttr = html_entity_decode((string) $styleAttr, ENT_QUOTES);
        if ($styleAttr === '' || (stripos($styleAttr, 'width') === false && stripos($styleAttr, 'height') === false)) {
            return false;
        }
        foreach (explode(';', $styleAttr) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            if (($property === 'width' || $property === 'height') && trim(substr($declaration, $colon + 1)) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * ['width' => w, 'height' => h] of a same-site raster file from the one bounded read, or null
     * (an SVG always null). For the delivery passes that cap a rung ladder or mint a placeholder
     * from the file's real size (wps_rewriteLogic::wpc_true_image_dimensions).
     */
    public static function measuredRaster($url)
    {
        if (preg_match('/\.svg$/i', self::fileKey($url))) {
            return null;
        }
        $dims = self::measure($url);
        return $dims === null ? null : ['width' => (int) $dims['w'], 'height' => (int) $dims['h']];
    }

    /** The file facts key: the URL without query or fragment. */
    private static function fileKey($src)
    {
        return (string) preg_replace('/[?#].*$/', '', html_entity_decode((string) $src, ENT_QUOTES));
    }

    /** One read of a same-site file, memoised per request and capped at MEASURE_LIMIT. */
    private static function measure($src)
    {
        $key = self::fileKey($src);
        if (array_key_exists($key, self::$fileFacts)) {
            return self::$fileFacts[$key];
        }
        self::$fileFacts[$key] = null;
        if (!function_exists('wpc_image_file_dims') || !defined('ABSPATH') || !function_exists('site_url')) {
            return null;
        }
        $site = parse_url((string) site_url());
        $url = parse_url(preg_match('#^//#', $key) ? 'https:' . $key : $key);
        if (!is_array($url) || !is_array($site) || empty($url['path'])) {
            return null;
        }
        if (!empty($url['host']) && (empty($site['host']) || strcasecmp($url['host'], $site['host']) !== 0)) {
            return null;
        }
        $relative = (string) $url['path'];
        $sitePath = isset($site['path']) ? trim((string) $site['path'], '/') : '';
        if ($sitePath !== '' && strpos(ltrim($relative, '/'), $sitePath . '/') === 0) {
            $relative = substr(ltrim($relative, '/'), strlen($sitePath));
        }
        if (strpos($relative, '..') !== false || self::$filesMeasured >= self::MEASURE_LIMIT) {
            return null;
        }
        self::$filesMeasured++;
        $dims = wpc_image_file_dims(rtrim((string) ABSPATH, '/') . '/' . ltrim(rawurldecode($relative), '/'));
        if (is_array($dims) && isset($dims[0], $dims[1]) && $dims[0] > 0 && $dims[1] > 0) {
            self::$fileFacts[$key] = ['w' => $dims[0], 'h' => $dims[1], 'source' => 'measured'];
        }
        return self::$fileFacts[$key];
    }

    // ── (c) the aspect pin and the receipt ───────────────────────────────────────────────────

    /** $kind: 'svg' (a sized SVG; 'svg-qw' when its file was read behind quiet-wire's carrier,
     *  because image_pins runs after quiet_wire moved src) or 'lazy' (our lazy placeholder, whose
     *  swap loses the box). */
    public function pinned($kind = '')
    {
        $this->pinCount++;
        if (isset($this->pinKinds[$kind])) {
            $this->pinKinds[$kind]++;
        }
    }

    /** image_dims replaced a page's height that contradicted the file's aspect (WordPress core
     *  printing 800x800 for a 561x120 SVG). */
    public function aspectOverridden()
    {
        $this->aspectOverrides++;
    }

    public function sameUrlLadderDropped()
    {
        $this->sameUrlLadders++;
    }

    /** The one img-sizing receipt of this render (D5), plus the two it names separately. */
    public function receipt($uri = '')
    {
        if ($this->receiptWritten || !function_exists('wpc_cache_first_log')) {
            return;
        }
        $this->receiptWritten = true;
        $uri = (string) $uri;
        // The kind counts and the two repairs are the belts on this owner's inputs and outputs:
        // pins behind quiet-wire's carrier and on our lazy placeholders, a page height that
        // contradicted the file (aspect_override), and the capped `sizes` ladder this plugin once
        // printed, still in stored pages (retired_ladder). Every render, like the receipt itself.
        wpc_cache_first_log('img-sizing', '', $uri, [
            'dev'   => $this->combined() ? 'combined' : $this->device(),
            'sizes' => $this->sizesCounts,
            'dims'  => $this->dimsCounts,
            'pins'  => $this->pinCount,
            'pins_svg' => $this->pinKinds['svg'],
            'pins_svg_qw' => $this->pinKinds['svg-qw'],
            'pins_lazy' => $this->pinKinds['lazy'],
            'aspect_override' => $this->aspectOverrides,
            'retired_ladder' => class_exists('wps_ic_atf_observation', false) ? (int) wps_ic_atf_observation::$retiredLaddersReplaced : 0,
        ]);
        if (self::$filesMeasured > 0) {
            wpc_cache_first_log('img-dims-measured', '', $uri, ['n' => self::$filesMeasured]);
        }
        if ($this->sameUrlLadders > 0) {
            wpc_cache_first_log('srcset-same-url-dropped', '', $uri, ['n' => $this->sameUrlLadders]);
        }
    }

    /** What the receipt would say, for the trace and the checks. */
    public function counts()
    {
        return ['sizes' => $this->sizesCounts, 'dims' => $this->dimsCounts, 'pins' => $this->pinCount,
                'measured' => self::$filesMeasured, 'same_url_ladders' => $this->sameUrlLadders];
    }
}
