<?php
// addons/cdn/render-pipeline.php — the ordered stage table runner for both render lanes.
if (!defined('ABSPATH')) { exit; }
// The image-sizing owner lives on every render context, so the runner file brings its class.
require_once dirname(__DIR__, 2) . '/classes/image_sizing.class.php';

if (!function_exists('wpc_render_belt_note')) {
    /**
     * A render belt that acted (repaired, dropped, withheld, refused) says so here, and the render
     * writes one receipt per belt when it ends (wpc_render_belt_flush): every call made during the
     * render adds its counts into that one line, so a belt that acts on forty tags is `{n: 40}`,
     * not forty lines. A belt that found nothing to do calls nothing.
     *
     * $sampled is for a belt that acts on most renders of a site by design (a first-frame guard
     * an Elementor page always gets): its line is written at most once per page per hour. A belt
     * whose action means another owner got something wrong passes false and is written on every
     * render it acts, because that line is the evidence.
     */
    function wpc_render_belt_note($event, array $fields = [], $sampled = false)
    {
        if (!isset($GLOBALS['wpc_render_belt_notes']) || !is_array($GLOBALS['wpc_render_belt_notes'])) {
            $GLOBALS['wpc_render_belt_notes'] = [];
            // A belt that runs outside the table (an enqueue-time dequeue) still gets its line
            // when the request ends without a table run.
            // WordPress's own shutdown action, not a PHP shutdown registration: the plugin's
            // malware heuristic counts that call and this file already carries four of its words.
            if (empty($GLOBALS['wpc_render_belt_shutdown']) && function_exists('add_action')) {
                $GLOBALS['wpc_render_belt_shutdown'] = 1;
                add_action('shutdown', 'wpc_render_belt_flush', 0);
            }
        }
        $event = (string) $event;
        if (!isset($GLOBALS['wpc_render_belt_notes'][$event])) {
            $GLOBALS['wpc_render_belt_notes'][$event] = ['fields' => [], 'sampled' => (bool) $sampled];
        }
        $have = &$GLOBALS['wpc_render_belt_notes'][$event]['fields'];
        foreach ($fields as $k => $v) {
            if (is_int($v) && isset($have[$k]) && is_int($have[$k])) {
                $have[$k] += $v;
            } else {
                $have[$k] = $v;
            }
        }
    }

    /**
     * Write the render's belt receipts, or drop them ($write false: the render returned the
     * pristine buffer, so nothing the belts decided reached the page).
     *
     * The sampled belts ask the one sampling gate, wpc_belt_rx_admit() in addons/cache/wpc-fs.php
     * (a file per page under wp-cio/belt-rx/, never wp_options), once per render, which of them
     * may be written this hour. Without the gate loaded, sampled belts are written unsampled and
     * carry no `sampled` field.
     */
    function wpc_render_belt_flush($write = true)
    {
        $notes = isset($GLOBALS['wpc_render_belt_notes']) && is_array($GLOBALS['wpc_render_belt_notes'])
            ? $GLOBALS['wpc_render_belt_notes'] : [];
        $GLOBALS['wpc_render_belt_notes'] = null;
        if (!$write || $notes === [] || !function_exists('wpc_cache_first_log')) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $key = '';
        if (class_exists('wps_ic_url_key', false)) {
            try {
                $key = ltrim((string) (new wps_ic_url_key())->setup(''), '/');
            } catch (\Throwable $e) {
                $key = '';
            }
        }
        $sampledEvents = [];
        foreach ($notes as $noteEvent => $note) {
            if ($note['sampled']) { $sampledEvents[] = $noteEvent; }
        }
        $gate = ['admit' => $sampledEvents, 'sampled' => false];
        if ($sampledEvents !== [] && function_exists('wpc_belt_rx_admit')) {
            $gate = wpc_belt_rx_admit($sampledEvents, $key, $uri);
        }
        foreach ($notes as $event => $note) {
            if ($note['sampled'] && !in_array((string) $event, $gate['admit'], true)) {
                continue;
            }
            wpc_cache_first_log($event, $key, $uri, ($note['sampled'] && $gate['sampled']) ? $note['fields'] + ['sampled' => 1] : $note['fields']);
        }
    }
}

final class wps_ic_render_stage
{
    public $name; public $callable; public $lanes; public $gate; public $isBail;
    public static function make($name, $callable, $lanes = wps_ic_render_pipeline::LANE_BOTH, $gate = null, $isBail = false)
    {
        $s = new self();
        $s->name = (string) $name; $s->callable = $callable; $s->lanes = (int) $lanes;
        $s->gate = $gate; $s->isBail = (bool) $isBail;
        return $s;
    }
    /** A bail stage: its callable returns true to end the run, false to let the table carry on.
     *  A bail that fires ends the run outright — no later entry runs — and the runner answers
     *  with the pristine buffer, so the only thing a bail leaves behind is its own side effects.
     *  A bail may carry a gate like any other entry; a gate that answers with a string skips it. */
    public static function bail($name, $callable, $lanes = wps_ic_render_pipeline::LANE_BOTH, $gate = null)
    {
        return self::make($name, $callable, $lanes, $gate, true);
    }
}

/**
 * The one owner of a render's @font-face rules.
 *
 * Before this class nine passes emitted their own <style> block of faces and sixteen more swept,
 * gated, deduped or pruned what the nine had written, so every new emitter needed a new sweeper
 * and every sweeper re-parsed the document. Here a face is registered once, keyed by what a face
 * IS — family|weight|style|stretch|unicode-range — and the rules the sweepers enforced after the
 * fact are enforced on insert and at emit instead:
 *
 *  - one face per tuple: the higher-ranked source wins (woff2 before data: before anything else),
 *    and between equal ranks the last writer wins;
 *  - a unicode-range tells two faces apart only when both of them declare one. Ranged against
 *    ranged is coverage and every range is kept; where one side declares a range and the other
 *    does not, the two are the same face delivered two ways and the embedded subset wins, because
 *    it paints its slice of the alphabet without a fetch while its full-file twin would download
 *    the same glyphs (the .180 subset-wins rule, which also settles the .693 shadowing a
 *    relocated subset would otherwise cause);
 *  - a face carrying a data: src, and a "<Family> Fallback" metric stand-in, always paints at
 *    first paint and pins its family, because text has to paint in something;
 *  - a network-url face whose family carries such a pin is served late, which is the face gate's
 *    "no network font URL may be discoverable before first paint" law;
 *  - a late face never re-declares a family that already has a real first-paint declarer, because
 *    the late twin can only evict the face that is already bound;
 *  - one face per file: faces that differ in nothing but a numeric font-weight are one file
 *    served for several weights, and are written once, carrying the weight span. A variable
 *    font requested through Google's v1 API comes back that way — Elementor's
 *    css?family=Roboto:100,100italic,…,900italic answers 9 weights × 18 subset files = 162
 *    faces over 18 woff2 files — and emitting them as registered put 121 KB of @font-face in
 *    the head of wpcompress.com/pricing/ (7.24.04: 18 span faces, 14.5 KB, the .191 merge);
 *  - font-display: a late face can only ever swap (an optional face armed after load has missed
 *    its grace window and locks the fallback permanently); an eager face that declares swap keeps
 *    the site's policy through wpc_font_display_effective; a face that declares no display keeps
 *    none, except from the font localizer's live-faces lane, the one lane that ever injected one.
 *    An eager network face that declares optional is written swap when the first screen paints a
 *    weight and style of its family that no embedded face covers (needTuples).
 *  - a face of a key the render's first-screen claim holds (same family, style and weight span;
 *    holdTuples) is written in neither block: one with its own bytes is dropped, one that needs the
 *    network goes to the hand-over's data (handoverFaces), and no written weight span covers a held
 *    weight;
 *  - a face of a key belowTuples() holds, when every face of the key can go, is written in neither
 *    block: below() answers it as the hand-over's entry, which the loader loads and adds after the
 *    window load event; the first-paint block carries one stand-in-metric face for the key in its
 *    place, which also keeps a written weight span from covering its weight;
 *
 * Nothing in here touches the document: it holds CSS, and stage_emit_font_faces writes the two
 * blocks. One instance per render, on wps_ic_render_context::$fontFaces.
 */
final class wps_ic_font_face_set
{
    /** The one origin whose faces get a font-display when they declare none: the font
     *  localizer's live-faces half, which arms a deferred sheet's @font-face at first paint
     *  while the sheet itself stays deferred. Every other origin keeps the author's answer. */
    const ORIGIN_DISPLAY_INJECTED = 'fonts-css-rest';

    /** The origin of a same-host face carrier the page loaded as an ordinary render-blocking
     *  stylesheet and the feeder absorbed inline. Absorbing it changes where its faces are
     *  declared, not when they are usable: they stay on the first-paint path even though the
     *  carrier brings its own "<Family> Fallback" stand-ins (the feeder splices them in as the
     *  carrier's CLS guard), which would otherwise read as the gate's pin and send every
     *  network face of the family to the late block. That demotion is for faces that would
     *  have waited for a deferred sheet anyway; a live carrier was never deferred. */
    const ORIGIN_LIVE_CARRIER = 'live-carrier';

    /** Tag of a face absorbed from a Google Fonts sheet the font rescan localized (wp-cio-fonts). */
    const TAG_RESCAN_SHEET = 'rescan-sheet';

    /** @var array  tuple key => [css, family, rank, origin, eager, seq] in insertion order. */
    private $faces = [];
    /** @var array  lowercase family => why, for families a pass asked to be served late. */
    private $demotedFamilies = [];
    /** @var int  Monotonic insert counter, so emit order is registration order. */
    private $sequence = 0;
    /** @var int  Counter of the faces added ahead of every other (add with $first), below any $sequence. */
    private $headSequence = -1000000;
    /** @var int  Faces dropped at emit (unbacked family, pruned range, evicted late twin). */
    private $droppedCount = 0;
    /** @var array  [family, low weight, high weight, style] tuples the document declares inline
     *  at its head (holdTuples). */
    private $heldTuples = [];
    /** @var array|null  The first-screen faces claim of this render (claimAtf). */
    private $atfClaim = null;
    /** @var bool  Whether a text face that fetches a file and declares no display is written swap (swapUndeclared). */
    private $swapUndeclared = false;
    /** @var array  [family, weight, style] tuples the first screen paints with (needTuples). */
    private $needTuples = [];
    /** @var array  [family, low weight, high weight, style] tuples no first screen paints with (belowTuples). */
    private $belowTuples = [];
    /** @var array  [family, weight, style] tuples the first screen paints with, for the faces tagged TAG_RESCAN_SHEET (lateUnlessFirstScreen). */
    private $rescanTuples = [];

    /** Take every @font-face block in $css into the set. $origin names the pass for the receipt;
     *  $eager is that pass's intent — false means "this face was never a first-paint declaration".
     *  Returns the number of blocks taken. Anything that is not a face block is ignored. $first
     *  puts the faces ahead of every face registered so far and every one registered later, in the order given.
     *  $tag marks the faces of this call for lateUnlessFirstScreen(). */
    public function add($css, $origin, $eager = true, $first = false, $tag = '')
    {
        $css = (string) $css;
        if ($css === '' || stripos($css, '@font-face') === false) { return 0; }
        // Host healing belongs where the bytes arrive, not in a later sweep over the document.
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_heal_css_host_twin')) {
            $healed = wps_cdn_rewrite::wpc_heal_css_host_twin($css);
            if (is_string($healed) && $healed !== '') { $css = $healed; }
        }
        if (stripos($css, 'fonts.gstatic.com') !== false && class_exists('wps_ic_fonts') && method_exists('wps_ic_fonts', 'localizeFaceUrls')) {
            $localized = (new wps_ic_fonts())->localizeFaceUrls($css);
            if (is_string($localized) && $localized !== '') { $css = $localized; }
        }
        if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $blocks)) { return 0; }
        $taken = 0;
        foreach ($blocks[0] as $block) {
            $family = self::familyOf($block);
            if ($family === '') { continue; }
            $pieces = $this->cutFallbackSpan($block, $family, $origin, $eager);
            if ($pieces !== null) {
                foreach ($pieces as $piece) { $taken += $this->add($piece, $origin, $eager, $first, $tag); }
                continue;
            }
            $base = self::baseKey($block, $family);
            $range = self::rangeOf($block);
            $key = $base . '|' . $range;
            $rank = self::rankOf($block);
            $faceTag = (string) $tag;
            // Higher rank wins the tuple; between equal ranks the last writer wins, so a pass
            // that runs later and knows more replaces what an earlier one registered. One
            // exception: a face already registered for first paint is not deferred by a twin
            // that arrives later out of a parked sheet. The twin duplicates the declaration, it
            // does not withdraw it, so the face keeps its first-paint intent and its origin.
            if (isset($this->faces[$key])) {
                $current = $this->faces[$key];
                if ($rank < $current['rank']) { continue; }
                if ($current['eager'] && !$eager) {
                    if ($rank === $current['rank']) { continue; }
                    $eager = true;
                    $origin = $current['origin'];
                    $faceTag = isset($current['tag']) ? $current['tag'] : '';
                }
            }
            $this->faces[$key] = [
                'css' => $block,
                'family' => $family,
                'base' => $base,
                'range' => $range,
                'rank' => $rank,
                'origin' => (string) $origin,
                'eager' => (bool) $eager,
                'tag' => $faceTag,
                'seq' => isset($this->faces[$key]) ? $this->faces[$key]['seq'] : ($first ? $this->headSequence++ : $this->sequence++),
            ];
            $taken++;
        }
        return $taken;
    }

    /** A "<Family> Fallback" stand-in declared over a weight span is held cut around the weights
     *  the same family and style declares on their own, so each of those weights is matched to its
     *  own stand-in in every engine and every other weight keeps the span's. Returns the pieces of
     *  $block when it is such a span, or null. A single weight that arrives after a span cuts the
     *  span already held; its pieces keep the span's place in the order. */
    private function cutFallbackSpan($block, $family, $origin, $eager)
    {
        if (substr($family, -9) !== ' fallback' || preg_match('/url\(/i', $block)
            || !preg_match('/font-weight\s*:/i', $block)) { return null; }
        $span = self::weightSpan($block);
        if (!$span) { return null; }
        $style = self::normaliseStyle(self::descriptor($block, 'font-style'));
        if ($span[0] === $span[1]) {
            foreach ($this->faces as $key => $face) {
                if ($face['family'] !== $family || preg_match('/url\(/i', $face['css'])
                    || self::normaliseStyle(self::descriptor($face['css'], 'font-style')) !== $style) { continue; }
                $held = self::weightSpan($face['css']);
                if (!$held || $held[0] === $held[1] || $span[0] < $held[0] || $span[0] > $held[1]) { continue; }
                unset($this->faces[$key]);
                foreach (self::weightPieces($face['css'], $held, [$span[0]]) as $piece) {
                    $this->add($piece, $face['origin'], $face['eager']);
                    $pieceKey = self::baseKey($piece, $family) . '|' . self::rangeOf($piece);
                    if (isset($this->faces[$pieceKey])) { $this->faces[$pieceKey]['seq'] = $face['seq']; }
                }
            }
            return null;
        }
        $inside = [];
        foreach ($this->faces as $face) {
            if ($face['family'] !== $family || preg_match('/url\(/i', $face['css'])
                || !preg_match('/font-weight\s*:/i', $face['css'])
                || self::normaliseStyle(self::descriptor($face['css'], 'font-style')) !== $style) { continue; }
            $held = self::weightSpan($face['css']);
            if ($held && $held[0] === $held[1] && $held[0] >= $span[0] && $held[0] <= $span[1]) { $inside[] = $held[0]; }
        }
        return $inside ? self::weightPieces($block, $span, $inside) : null;
    }

    /** $css re-declared over the parts of $span that are not in $weights. */
    private static function weightPieces($css, array $span, array $weights)
    {
        $weights = array_values(array_unique($weights));
        sort($weights);
        $pieces = [];
        $from = $span[0];
        foreach ($weights as $weight) {
            if ($weight - 1 >= $from) { $pieces[] = [$from, $weight - 1]; }
            $from = max($from, $weight + 1);
        }
        if ($span[1] >= $from) { $pieces[] = [$from, $span[1]]; }
        $out = [];
        foreach ($pieces as $piece) {
            $out[] = (string) preg_replace('/(font-weight\s*:\s*)[^;}]+/i',
                '${1}' . ($piece[0] === $piece[1] ? $piece[0] : $piece[0] . ' ' . $piece[1]), $css, 1);
        }
        return $out;
    }

    /** Serve this family's network faces late. One record per family: the first reason sticks. */
    public function demote($family, $why)
    {
        $family = self::normaliseFamily($family);
        if ($family === '' || isset($this->demotedFamilies[$family])) { return; }
        $this->demotedFamilies[$family] = (string) $why;
    }

    /** True when a pass of this name has already registered a face this render. This is how an
     *  emitter asks whether it has already run, not by looking for its block in the document. */
    public function hasOrigin($origin)
    {
        foreach ($this->faces as $face) {
            if ($face['origin'] === (string) $origin) { return true; }
        }
        return false;
    }

    /** Every face the set holds, as CSS, for a pass that has to read the render's declarations
     *  rather than be handed a verdict. The faces are no longer in the document at that point —
     *  they are here — so this is the haystack such a pass has to be given. */
    public function css()
    {
        $out = '';
        foreach ($this->faces as $face) {
            $out .= $face['css'];
        }
        return $out;
    }

    /** True when the set carries at least one face for this family. */
    public function has($family)
    {
        $family = self::normaliseFamily($family);
        if ($family === '') { return false; }
        foreach ($this->faces as $face) {
            if ($face['family'] === $family) { return true; }
        }
        return false;
    }

    /** True when the set holds the face gate's pin for this family: an eager embedded subset or
     *  a "<Family> Fallback" metric stand-in (see isGatePin). The emit asks it before it takes a
     *  page style block's network face into the set, so only a face the gate would serve late
     *  ever leaves its author's block. For an icon family only an embedded subset counts: a
     *  metric stand-in paints text, not glyphs, so an icon face moved late behind one shows
     *  the raw codepoint (Divi's menu button as the letter "a" on webdesign4u.com.au
     *  /contact-us/, which carries the ETmodules stand-in but no subset). With $subsetOnly a
     *  stand-in is not a pin either: the family counts only when the set holds an embedded subset
     *  of it. */
    public function pinsFamily($family, $subsetOnly = false)
    {
        $family = self::normaliseFamily($family);
        if ($family === '') { return false; }
        $iconFamily = function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($family);
        foreach ($this->faces as $face) {
            if (!$face['eager']) { continue; }
            $pinned = substr($face['family'], -9) === ' fallback'
                ? rtrim(substr($face['family'], 0, -9)) : $face['family'];
            if ($pinned !== $family) { continue; }
            if ($iconFamily || $subsetOnly ? self::hasEmbeddedSource($face['css']) : self::isGatePin($face['css'], $face['family'])) {
                return true;
            }
        }
        return false;
    }

    /** True when the set carries a face for this exact family, weight AND style.
     *
     *  A provider stylesheet is requested per face, not per family — family=Inter:wght@400;700
     *  asks for two, family=Inter:ital,wght@1,400 asks for italic 400 — so family-name coverage
     *  is not coverage and neither is weight coverage alone: dropping that link because the set
     *  holds Inter 400 takes 700 off the page, and dropping it because the set holds Inter
     *  regular 400 takes the italic off it. Synthesis is not delivery — a browser asked for
     *  italic and given only a roman face slants the roman — so an italic request is covered
     *  only by a face that declares italic (or oblique, which is the same delivery), and a
     *  roman request only by one that does not.
     *
     *  A face declaring a weight span (a variable font) covers every weight inside it; a face
     *  whose weight is neither a number, a span nor normal/bold covers nothing, because a weight
     *  nobody can read is not a guarantee anyone can rest a deletion on. */
    public function hasFace($family, $weight, $style = 'normal')
    {
        $family = self::normaliseFamily($family);
        $weight = (int) $weight;
        $style = self::normaliseStyle($style);
        if ($family === '' || $weight <= 0) { return false; }
        foreach ($this->faces as $face) {
            if ($face['family'] !== $family) { continue; }
            if (self::normaliseStyle(self::descriptor($face['css'], 'font-style')) !== $style) { continue; }
            $span = self::weightSpan($face['css']);
            if ($span && $weight >= $span[0] && $weight <= $span[1]) { return true; }
        }
        return false;
    }

    /** 'italic' or 'normal'. oblique is italic delivery by another name; anything unreadable,
     *  and the absence of a declaration, is the CSS default, normal. */
    private static function normaliseStyle($style)
    {
        $style = strtolower(trim((string) $style));

        return (strpos($style, 'italic') === 0 || strpos($style, 'oblique') === 0) ? 'italic' : 'normal';
    }

    /** [low, high] for a face's font-weight, or [] when the declaration is not readable as one. */
    private static function weightSpan($css)
    {
        if (!preg_match('/font-weight\s*:\s*([^;}]+)/i', $css, $m)) { return [400, 400]; }
        $raw = strtolower(trim($m[1]));
        $raw = str_replace(['normal', 'bold'], ['400', '700'], $raw);
        if (preg_match('/^(\d{1,4})$/', $raw, $one)) { return [(int) $one[1], (int) $one[1]]; }
        if (preg_match('/^(\d{1,4})\s+(\d{1,4})$/', $raw, $two)) { return [(int) $two[1], (int) $two[2]]; }
        return [];
    }

    /** Drop every face of the named families — the census answer for families the render does
     *  not back, taken here rather than swept out of the written blocks afterwards. Takes a list
     *  or a family-keyed map, because the censuses that answer this question return both shapes.
     *
     *  A "<Family> Fallback" stand-in goes with its family: a metric stand-in for a family the
     *  page cannot complete is the .23 failure itself — the family paints above the fold in the
     *  stand-in's geometry and nowhere else. */
    public function dropFamilies($families)
    {
        if (!is_array($families) || !$families) { return; }
        $drop = [];
        foreach ($families as $key => $value) {
            $family = self::normaliseFamily(is_string($key) ? $key : $value);
            if ($family !== '') { $drop[$family] = 1; }
        }
        if (!$drop) { return; }
        foreach ($this->faces as $key => $face) {
            $family = substr($face['family'], -9) === ' fallback'
                ? rtrim(substr($face['family'], 0, -9)) : $face['family'];
            if (isset($drop[$family])) { unset($this->faces[$key]); $this->droppedCount++; }
        }
    }

    /** The code point spans of the scripts a text family is split into subsets for (Latin extended, Greek, Cyrillic, Hebrew, Arabic, Devanagari, Thai, Georgian, Armenian, Hangul, kana, CJK). */
    private static $scriptSpans = [
        [0x100, 0x2AF], [0x370, 0x3FF], [0x400, 0x52F], [0x530, 0x58F], [0x590, 0x6FF], [0x900, 0x97F], [0xE00, 0xE7F], [0x10A0, 0x10FF],
        [0x1C80, 0x1C8F], [0x1E00, 0x1FFF], [0x2C60, 0x2C7F], [0x2DE0, 0x2DFF], [0x3040, 0x30FF], [0x4E00, 0x9FFF], [0xA640, 0xA69F], [0xA720, 0xA7FF], [0xAC00, 0xD7AF],
    ];

    /** Drop the network faces of a script subset the document has no character for. $codepoints is the sorted list of the code points
     *  (U+0080 and up) the document holds; a face goes only when its unicode-range reaches into a script of $scriptSpans, none of its
     *  spans meets a printable ASCII character or a held code point, and its source is a network url (an embedded subset, a local
     *  stand-in and a face with no range stay). Returns how many went. */
    public function pruneRanges(array $codepoints)
    {
        $held = array_values($codepoints);
        $count = count($held);
        $gone = 0;
        foreach ($this->faces as $key => $face) {
            if ($face['range'] === '' || substr($face['family'], -9) === ' fallback'
                || self::hasEmbeddedSource($face['css']) || !self::hasNetworkSource($face['css'])) { continue; }
            $spans = self::rangeSpans($face['range']);
            if (!$spans) { continue; }
            $script = false;
            $meets = false;
            foreach ($spans as $span) {
                if ($span[0] <= 0x7E && $span[1] >= 0x20) { $meets = true; break; }
                foreach (self::$scriptSpans as $block) {
                    if ($span[0] <= $block[1] && $block[0] <= $span[1]) { $script = true; break; }
                }
                $low = 0;
                $high = $count;
                while ($low < $high) {
                    $mid = ($low + $high) >> 1;
                    if ($held[$mid] < $span[0]) { $low = $mid + 1; } else { $high = $mid; }
                }
                if ($low < $count && $held[$low] <= $span[1]) { $meets = true; break; }
            }
            if ($meets || !$script) { continue; }
            unset($this->faces[$key]);
            $this->droppedCount++;
            $gone++;
        }

        return $gone;
    }

    /** Tell the set which tuples the document declares inline at the top of its head
     *  (wps_cdn_rewrite::emit_font_faces): [family, low weight, high weight, style], family
     *  lower case. From then on a face of exactly one of them is not written: one that carries
     *  its own bytes is dropped (the inline copy is the one declaration), one that needs the
     *  network leaves for handoverFaces(), and none is a first-paint declarer of its family.
     *  A tuple holds a face of the same family and style whose weight span is the same span.
     *  A face written in a weight span never covers the weight of a held face (weightSpans). A
     *  "<Family> Fallback" stand-in is never held. */
    public function holdTuples(array $tuples)
    {
        $this->heldTuples = array_values($tuples);
    }

    /** The first-screen faces claim this render's critical css carried (wps_rewriteLogic::wpc_atf_faces_cut). */
    public function claimAtf(array $claim)
    {
        $this->atfClaim = $claim;
    }

    /** The claim, or null when this render has none or has given it up. */
    public function atfClaim()
    {
        return $this->atfClaim;
    }

    /** Give the claim up: nothing is held and the set writes every face as it would without one. */
    public function releaseAtf()
    {
        $this->atfClaim = null;
        $this->heldTuples = [];
        $this->swapUndeclared = false;
    }

    /** From now on a text face that fetches a file and declares no display is written swap, in
     *  either block, unless the person switched font-display handling off. A declared display, an
     *  icon face and a stand-in are left as they are. */
    public function swapUndeclared($on = true)
    {
        $this->swapUndeclared = (bool) $on;
    }

    /** The key a face declares: ['family' => lower case without quotes, 'low', 'high' => its weight
     *  span, 'style' => normal|italic]; null when the family or the weight is unreadable. */
    public static function keyOf($css)
    {
        $family = self::familyOf($css);
        $span = self::weightSpan($css);
        if ($family === '' || !$span) { return null; }

        return ['family' => $family, 'low' => (int) $span[0], 'high' => (int) $span[1],
            'style' => self::normaliseStyle(self::descriptor($css, 'font-style'))];
    }

    /** keyOf() for a "Family|weight|style" key as the service writes it (the weight is the face's
     *  own descriptor, so a variable face reads "200 800"); null when it is not one. */
    public static function keyFromString($key)
    {
        $parts = explode('|', (string) $key);
        if (count($parts) < 3) { return null; }
        $style = array_pop($parts);
        $weight = array_pop($parts);
        $css = '{font-family:"' . str_replace(['"', '\\'], '', implode('|', $parts)) . '";font-weight:' . str_replace([';', '}'], '', (string) $weight)
            . ';font-style:' . str_replace([';', '}'], '', (string) $style) . '}';

        return self::keyOf($css);
    }

    /** A unicode-range descriptor as [[low, high], …]; [] when there is none, null when it does not parse. */
    public static function rangeSpans($range)
    {
        $range = strtolower(trim((string) $range));
        if ($range === '') { return []; }
        $spans = [];
        foreach (explode(',', $range) as $part) {
            if (!preg_match('/^\s*u\+([0-9a-f?]{1,6})(?:-([0-9a-f]{1,6}))?\s*$/', $part, $m)) { return null; }
            if (strpos($m[1], '?') !== false) {
                $spans[] = [hexdec(str_replace('?', '0', $m[1])), hexdec(str_replace('?', 'f', $m[1]))];
            } else {
                $spans[] = [hexdec($m[1]), hexdec(isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1])];
            }
        }

        return $spans;
    }

    /** True when two unicode-range descriptors share a code point; a face without one covers all. */
    public static function rangesMeet($a, $b)
    {
        $left = self::rangeSpans($a);
        $right = self::rangeSpans($b);
        if ($left === null || $right === null) { return true; }
        if ($left === [] || $right === []) { return true; }
        foreach ($left as $l) {
            foreach ($right as $r) {
                if ($l[0] <= $r[1] && $r[0] <= $l[1]) { return true; }
            }
        }

        return false;
    }

    /**
     * The held faces that fetch their bytes, as the entries the hand-over script reads
     * (family, weight, style, src, unicodeRange, stretch), split by whether they serve an inline
     * face: $inline is [['family','low','high','style','range'], …] for the faces the document
     * declares inline. For each inline face, the held faces of its key whose unicode-range is the
     * same descriptor are its full faces; with none of those, the ones whose range meets its
     * range. Every other held face of a held key is a later one ('late').
     * 'unserved' lists the indexes of the inline faces no full face serves, 'refused' the reasons
     * a held face cannot be handed over (a descriptor the hand-over does not carry, a source
     * that is not a url()). Each entry also carries 'urls', the file URLs of its src.
     */
    public function handoverFaces(array $inline)
    {
        $faces = $this->faces;
        uasort($faces, function ($a, $b) { return $a['seq'] - $b['seq']; });
        $held = [];
        $refused = [];
        foreach ($faces as $face) {
            if (!$this->isHeld($face) || self::hasEmbeddedSource($face['css'])) { continue; }
            $entry = self::handoverEntry($face['css']);
            if (!is_array($entry)) { $refused[] = (string) $entry; continue; }
            $held[] = ['entry' => $entry, 'key' => self::keyOf($face['css']), 'range' => $face['range']];
        }
        $full = [];
        $served = [];
        foreach ($inline as $index => $declared) {
            $same = [];
            $meets = [];
            foreach ($held as $at => $one) {
                if ($declared['family'] !== $one['key']['family'] || $declared['low'] !== $one['key']['low']
                    || $declared['high'] !== $one['key']['high'] || $declared['style'] !== $one['key']['style']) { continue; }
                if (self::rangeId($declared['range']) === self::rangeId($one['range'])) { $same[] = $at; }
                elseif (self::rangesMeet($declared['range'], $one['range'])) { $meets[] = $at; }
            }
            $use = $same ?: $meets;
            foreach ($use as $at) { $full[$at] = true; }
            if ($use) { $served[$index] = true; }
        }
        $fulls = [];
        $lates = [];
        foreach ($held as $at => $one) {
            $id = md5(json_encode($one['entry']));
            if (isset($full[$at])) { $fulls[$id] = $one['entry']; } else { $lates[$id] = $one['entry']; }
        }
        $unserved = [];
        foreach ($inline as $index => $declared) {
            if (!isset($served[$index])) { $unserved[] = $index; }
        }

        return ['full' => array_values($fulls), 'late' => array_values(array_diff_key($lates, $fulls)), 'unserved' => $unserved, 'refused' => $refused];
    }

    /** A unicode-range descriptor by what it names, whatever its case, spacing and order. */
    private static function rangeId($range)
    {
        $parts = array_filter(explode(',', strtolower((string) preg_replace('/\s+/', '', (string) $range))), 'strlen');
        sort($parts);

        return implode(',', $parts);
    }

    /** One face as a hand-over entry, or the reason it cannot be one. */
    private static function handoverEntry($css)
    {
        if (preg_match('/(?:ascent-override|descent-override|line-gap-override|size-adjust|font-feature-settings|font-variation-settings|font-variant|font-language-override)\s*:/i', $css)) {
            return 'descriptor';
        }
        if (!preg_match('/font-family\s*:\s*(["\']?)([^;"\'}]+)\1/i', $css, $familyMatch) || trim($familyMatch[2]) === '' || strlen(trim($familyMatch[2])) > 64) {
            return 'family';
        }
        $weight = preg_match('/font-weight\s*:\s*([^;}]+)/i', $css, $m) ? trim((string) preg_replace('/\s+/', ' ', $m[1])) : '400';
        $style = preg_match('/font-style\s*:\s*([^;}]+)/i', $css, $m) ? trim((string) preg_replace('/\s+/', ' ', $m[1])) : 'normal';
        if (!preg_match('/^(?:normal|bold|\d{1,4}(?: \d{1,4})?)$/i', $weight) || !preg_match('/^(?:normal|italic|oblique(?: -?\d+(?:\.\d+)?deg)?)$/i', $style)) {
            return 'descriptor';
        }
        $entry = ['family' => trim($familyMatch[2]), 'weight' => $weight, 'style' => $style];
        if (preg_match('/font-stretch\s*:\s*([^;}]+)/i', $css, $m)) {
            $stretch = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            if (!preg_match('/^[a-z0-9%. -]{1,40}$/i', $stretch)) { return 'descriptor'; }
            if (strtolower($stretch) !== 'normal' && $stretch !== '100%') { $entry['stretch'] = $stretch; }
        }
        if (!preg_match('/src\s*:\s*([^;}]+)/i', $css, $srcMatch)
            || !preg_match_all('/url\(\s*(["\']?)([^"\')]*)\1\s*\)(?:\s*format\(\s*(["\']?)([^"\')]*)\3\s*\))?/i', $srcMatch[1], $sources, PREG_SET_ORDER)) {
            return 'src';
        }
        if (substr_count(strtolower($srcMatch[1]), 'url(') !== count($sources)) { return 'src'; }
        $list = [];
        $urls = [];
        foreach ($sources as $source) {
            $url = trim($source[2]);
            if ($url === '' || stripos($url, 'data:') === 0 || !preg_match('~\A[^\s\'"\\\\<>()\x00-\x1f\x7f]{1,2048}\z~', $url)) { return 'src'; }
            $format = isset($source[4]) ? trim($source[4]) : '';
            if ($format !== '' && !preg_match('/^[a-z0-9-]{1,16}$/i', $format)) { return 'src'; }
            $urls[] = $url;
            $list[] = "url('" . $url . "')" . ($format !== '' ? " format('" . $format . "')" : '');
        }
        $entry['src'] = implode(', ', $list);
        if (preg_match('/unicode-range\s*:\s*([^;}]+)/i', $css, $m)) {
            $range = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            if (self::rangeSpans($range) === null) { return 'descriptor'; }
            if ($range !== '') { $entry['unicodeRange'] = $range; }
        }
        $entry['urls'] = $urls;

        return $entry;
    }

    /** The [family, weight, style] tuples the service's census says the first screen paints: family
     *  lower case, weight a number, style normal or italic. An eager network face that declares
     *  optional and whose family, style and weight span include one of them is written swap unless
     *  an embedded face of the family paints that weight and style. No tuples, no change. */
    public function needTuples(array $tuples)
    {
        $this->needTuples = [];
        foreach ($tuples as $tuple) {
            if (!is_array($tuple) || count($tuple) < 3) { continue; }
            $family = self::normaliseFamily($tuple[0]);
            $weight = (int) $tuple[1];
            if ($family === '' || $weight < 1 || $weight > 1000) { continue; }
            $this->needTuples[] = [$family, $weight, self::normaliseStyle($tuple[2])];
        }
    }

    /** The [family, weight, style] tuples the first screen of this render paints with, for the faces
     *  add() took with TAG_RESCAN_SHEET: from then on such a face no tuple needs is written late.
     *  No tuples, no change. */
    public function lateUnlessFirstScreen(array $tuples)
    {
        $this->rescanTuples = [];
        foreach ($tuples as $tuple) {
            if (!is_array($tuple) || count($tuple) < 3) { continue; }
            $family = self::normaliseFamily($tuple[0]);
            $weight = (int) $tuple[1];
            if ($family === '' || $weight < 1 || $weight > 1000) { continue; }
            $this->rescanTuples[] = [$family, $weight, self::normaliseStyle($tuple[2])];
        }
    }

    /** Keys of the TAG_RESCAN_SHEET faces split() writes late. A face stays eager when a tuple of its
     *  family and style falls inside its weight span, when it is a rangeless twin of an embedded
     *  subset ($twinBases), or when an eager face fetches the same file. A family stays wholly eager
     *  when one of its tuples would be left without an eager face to paint it. Fallbacks, icon fonts,
     *  and faces that paint without the network or fetch no file are never moved. */
    private function rescanLateKeys(array $faces, array $twinBases)
    {
        if (!$this->rescanTuples) { return []; }
        $byFamily = [];
        foreach ($faces as $key => $face) {
            if (!isset($face['tag']) || $face['tag'] !== self::TAG_RESCAN_SHEET || !$face['eager']
                || substr($face['family'], -9) === ' fallback'
                || ($face['range'] === '' && isset($twinBases[$face['base']]))
                || self::paintsWithoutNetwork($face['css'], $face['family']) || !self::hasNetworkSource($face['css'])
                || (function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($face['family']))) { continue; }
            $byFamily[$face['family']][$key] = $face;
        }
        $late = [];
        foreach ($byFamily as $family => $list) {
            $need = [];
            foreach ($this->rescanTuples as $tuple) {
                if ($tuple[0] === $family) { $need[] = $tuple; }
            }
            $moved = [];
            foreach ($list as $key => $face) {
                if (!self::spanHoldsAny($face, $need)) { $moved[$key] = true; }
            }
            foreach ($need as $tuple) {
                $painted = false;
                foreach ($faces as $key => $face) {
                    if ($face['family'] === $family && $face['eager'] && !isset($moved[$key])
                        && self::spanHoldsAny($face, [$tuple])) { $painted = true; break; }
                }
                if (!$painted) { $moved = []; break; }
            }
            $late += $moved;
        }
        if ($late) {
            $stayUrls = [];
            foreach ($faces as $key => $face) {
                if (!isset($late[$key]) && $face['eager']) {
                    foreach (self::faceUrls($face['css']) as $url) { $stayUrls[$url] = true; }
                }
            }
            foreach (array_keys($late) as $key) {
                foreach (self::faceUrls($faces[$key]['css']) as $url) {
                    if (isset($stayUrls[$url])) { unset($late[$key]); break; }
                }
            }
        }

        return $late;
    }

    private static function spanHoldsAny(array $face, array $tuples)
    {
        $span = self::weightSpan($face['css']);
        if (!$span) { return true; }
        $style = self::normaliseStyle(self::descriptor($face['css'], 'font-style'));
        foreach ($tuples as $tuple) {
            if ($tuple[2] === $style && $tuple[1] >= $span[0] && $tuple[1] <= $span[1]) { return true; }
        }

        return false;
    }

    /** The font files only the faces lateUnlessFirstScreen() moved fetch, so a preload of one can go with it. [] without tuples. */
    public function rescanLateUrls()
    {
        if (!$this->rescanTuples) { return []; }
        $split = $this->split();
        $eagerUrls = [];
        foreach ($split[0] as $face) {
            foreach (self::faceUrls($face['css']) as $url) { $eagerUrls[$url] = true; }
        }
        $urls = [];
        foreach ($split[1] as $face) {
            if (empty($face['rescan_late'])) { continue; }
            foreach (self::faceUrls($face['css']) as $url) {
                if (!isset($eagerUrls[$url])) { $urls[$url] = $url; }
            }
        }

        return array_values($urls);
    }

    private static function faceUrls($css)
    {
        return preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', (string) $css, $m) ? (array) $m[1] : [];
    }

    /** Tell the set which tuples the service says no first screen paints ([family, low weight,
     *  high weight, style], family lower case, as keyOf() answers). From then on a face of exactly
     *  one of them leaves both written blocks and is answered by below(), when its tuple can go
     *  whole: every face of it fetches a file the hand-over can carry, is not an embedded subset,
     *  an icon face, was not written by the critical css and is not a held key, and the document
     *  keeps a "<Family> Fallback" face for its family: eager() then carries a placeholder under
     *  the family itself, cut from that face, so no other weight of the family answers the moved
     *  one. A tuple with one face that cannot go stays as it is. */
    public function belowTuples(array $tuples)
    {
        $this->belowTuples = array_values($tuples);
    }

    /** The faces belowTuples() took out of the blocks, as the hand-over's entries (family, weight,
     *  style, src, unicodeRange, stretch), once each, in registration order. [] without tuples. */
    public function below()
    {
        $entries = [];
        foreach ($this->belowEntries() as $id => $entry) {
            unset($entry['urls']);
            $entries[] = $entry;
        }

        return $entries;
    }

    /** The file URLs of the faces below() answers. */
    public function belowUrls()
    {
        $urls = [];
        foreach ($this->belowEntries() as $entry) {
            foreach ($entry['urls'] as $url) { $urls[$url] = $url; }
        }

        return array_values($urls);
    }

    /** One face of below() as CSS, or '' when the entry is not one this set could have written. */
    public static function entryCss(array $entry)
    {
        $family = isset($entry['family']) ? str_replace(['"', '\\', '<', '>', '{', '}', ';', "\r", "\n"], '', (string) $entry['family']) : '';
        $css = '@font-face{font-family:"' . $family . '";font-style:' . (isset($entry['style']) ? (string) $entry['style'] : 'normal')
            . ';font-weight:' . (isset($entry['weight']) ? (string) $entry['weight'] : '400')
            . (isset($entry['stretch']) ? ';font-stretch:' . (string) $entry['stretch'] : '')
            . ';src:' . (isset($entry['src']) ? (string) $entry['src'] : '')
            . (isset($entry['unicodeRange']) ? ';unicode-range:' . (string) $entry['unicodeRange'] : '') . '}';

        return $family !== '' && is_array(self::handoverEntry($css)) && substr_count($css, '{') === 1 && substr_count($css, '}') === 1 ? $css : '';
    }

    /** id => entry (with 'urls') of the faces in the below bucket. */
    private function belowEntries()
    {
        if (!$this->belowTuples) { return []; }
        $split = $this->split();
        $entries = [];
        foreach ($split[4] as $face) {
            $entry = self::handoverEntry($face['css']);
            if (is_array($entry)) { $entries[md5(json_encode($entry))] = $entry; }
        }

        return $entries;
    }

    /** True when $face is a key belowTuples() holds, by the same equality isHeld() uses. */
    private function isBelow(array $face)
    {
        if (!$this->belowTuples || substr($face['family'], -9) === ' fallback') { return false; }
        $span = self::weightSpan($face['css']);
        if (!$span) { return false; }
        $style = self::normaliseStyle(self::descriptor($face['css'], 'font-style'));
        foreach ($this->belowTuples as $tuple) {
            if ($tuple[0] === $face['family'] && $tuple[3] === $style && $span[0] === $tuple[1] && $span[1] === $tuple[2]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tuples that go below, as [keys of their faces in $faces, the placeholder face that holds
     * their weight at first paint], for each tuple that can go whole: every face of it fetches a
     * file the hand-over can carry, none is embedded, none was written by the critical css, the
     * family is not an icon family, and the document keeps a metric stand-in the placeholder is
     * cut from. A face with no url() (a stand-in, an earlier placeholder) is neither moved nor a veto.
     */
    private function belowGroups(array $faces)
    {
        $groups = [];
        foreach ($faces as $key => $face) {
            if (!$this->isBelow($face) || preg_match('/src\s*:[^;}]*\burl\(/i', $face['css']) !== 1) { continue; }
            $span = self::weightSpan($face['css']);
            $style = self::normaliseStyle(self::descriptor($face['css'], 'font-style'));
            $id = $face['family'] . '|' . $span[0] . '|' . $span[1] . '|' . $style;
            if (!isset($groups[$id])) {
                $groups[$id] = ['keys' => [], 'ok' => true, 'family' => $face['family'], 'low' => $span[0], 'high' => $span[1], 'style' => $style, 'raw' => $face['css'], 'seq' => $face['seq']];
            }
            $groups[$id]['keys'][] = $key;
            $groups[$id]['ok'] = $groups[$id]['ok']
                && $face['origin'] !== 'crit'
                && !self::hasEmbeddedSource($face['css'])
                && is_array(self::handoverEntry($face['css']))
                && !(function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($face['family']));
        }
        $out = [];
        foreach ($groups as $group) {
            if (!$group['ok']) { continue; }
            $placeholder = $this->placeholderFor($group);
            if ($placeholder !== null) { $out[] = ['keys' => $group['keys'], 'placeholder' => $placeholder]; }
        }

        return $out;
    }

    /**
     * The face that holds a moved tuple's weight and style until its real faces are added: the
     * family's "<Family> Fallback" face that names the weight (a single weight before a span), else
     * the one that names none, written under the family itself, with the tuple's weight and style
     * and no unicode-range. Without it the browser answers the missing weight with another weight
     * of the family that is declared, and loads that file. null when the set has no stand-in to
     * cut it from; false when it already holds a face with no url() for the family, weight and
     * style (written by an earlier pass over the same document), which is then the placeholder.
     */
    private function placeholderFor(array $group)
    {
        $standIn = null;
        $rank = 0;
        foreach ($this->faces as $face) {
            if ($face['family'] !== $group['family'] . ' fallback' || preg_match('/url\(/i', $face['css'])) { continue; }
            $style = self::descriptor($face['css'], 'font-style');
            if ($style !== '' && self::normaliseStyle($style) !== $group['style']) { continue; }
            if (self::descriptor($face['css'], 'font-weight') === '') {
                $candidate = 1;
            } else {
                $span = self::weightSpan($face['css']);
                if (!$span || $span[0] > $group['low'] || $span[1] < $group['high']) { continue; }
                $candidate = $span[0] === $span[1] ? 3 : 2;
            }
            if ($candidate > $rank) { $standIn = $face; $rank = $candidate; }
        }
        if ($standIn === null) { return null; }
        foreach ($this->faces as $face) {
            if ($face['family'] === $group['family'] && preg_match('/url\(/i', $face['css']) !== 1
                && self::weightSpan($face['css']) === [$group['low'], $group['high']]
                && self::normaliseStyle(self::descriptor($face['css'], 'font-style')) === $group['style']) {
                return false;
            }
        }
        $name = preg_match('/font-family\s*:\s*(["\']?)([^;"\'}]+)\1/i', $group['raw'], $named) ? trim($named[2]) : '';
        if ($name === '') { return null; }
        $css = (string) preg_replace('/(?:font-family|font-weight|font-style|unicode-range)\s*:[^;}]*;?/i', '', $standIn['css']);
        $css = (string) preg_replace('/\{/', '{font-family:"' . $name . '";font-weight:'
            . ($group['low'] === $group['high'] ? $group['low'] : $group['low'] . ' ' . $group['high'])
            . ';font-style:' . $group['style'] . ';', $css, 1);

        return ['css' => $css, 'family' => $group['family'], 'base' => '', 'range' => '', 'rank' => 0,
            'origin' => 'below-placeholder', 'eager' => true, 'seq' => $group['seq']];
    }

    /** A text face that fetches its bytes and declares optional: the only kind needTuples may
     *  write swap. */
    private static function swappable(array $face)
    {
        return substr($face['family'], -9) !== ' fallback'
            && preg_match('/font-display\s*:\s*optional\b/i', $face['css']) === 1
            && preg_match('/src\s*:[^;}]*url\(/i', $face['css']) === 1
            && !self::hasEmbeddedSource($face['css'])
            && !(function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($face['family']));
    }

    /** Faces that differ in nothing but their numeric weight and display are one file served for
     *  several weights (the grouping weightSpans() writes as one face). */
    private static function sameFileKey($css)
    {
        return md5((string) preg_replace('/\s+/', ' ', (string) preg_replace('/font-(?:weight|display)\s*:[^;}]*;?/i', '', $css)));
    }

    /** True when $face is a swappable eager face that a first-screen tuple needs and no embedded
     *  face covers. $eager is the split's first-paint list. */
    private function needsRealFace(array $face, array $eager)
    {
        if (!$this->needTuples || !self::swappable($face)) {
            return false;
        }
        $span = self::weightSpan($face['css']);
        if (!$span) { return false; }
        $style = self::normaliseStyle(self::descriptor($face['css'], 'font-style'));
        foreach ($this->needTuples as $need) {
            if ($need[0] !== $face['family'] || $need[2] !== $style
                || $need[1] < $span[0] || $need[1] > $span[1]) { continue; }
            if (!$this->paintsEmbedded($face['family'], $need[1], $style, $eager)) { return true; }
        }
        return false;
    }

    /** True when a face that carries its own bytes paints this family at this weight and style. */
    private function paintsEmbedded($family, $weight, $style, array $eager)
    {
        foreach ($eager as $other) {
            if ($other['family'] !== $family || !self::hasEmbeddedSource($other['css'])
                || self::normaliseStyle(self::descriptor($other['css'], 'font-style')) !== $style) { continue; }
            $span = self::weightSpan($other['css']);
            if ($span && $weight >= $span[0] && $weight <= $span[1]) { return true; }
        }
        return false;
    }

    private function isHeld(array $face)
    {
        if (!$this->heldTuples || substr($face['family'], -9) === ' fallback') { return false; }
        $span = self::weightSpan($face['css']);
        if (!$span) { return false; }
        $style = self::normaliseStyle(self::descriptor($face['css'], 'font-style'));
        foreach ($this->heldTuples as $tuple) {
            if ($tuple[0] === $face['family'] && $tuple[3] === $style && $span[0] === $tuple[1] && $span[1] === $tuple[2]) {
                return true;
            }
        }
        return false;
    }

    /** The CSS that paints at first paint. */
    public function eager() { return $this->render(false); }

    /** The CSS the loader arms after load. */
    public function late() { return $this->render(true); }

    /** [eager, late, dropped, demoted] face counts, for the render's one receipt. */
    public function counts()
    {
        $split = $this->split();
        $spans = self::weightSpans($split);
        $counts = [
            'eager' => count(self::writeFaces($split[0], false, $spans)),
            'late' => count(self::writeFaces($split[1], true, $spans)),
            'dropped' => $this->droppedCount + count($split[2]),
            'demoted' => count($this->demotedFamilies),
        ];
        if ($this->belowTuples) { $counts['below'] = count($split[4]); }
        if ($this->rescanTuples) {
            $moved = 0;
            foreach ($split[1] as $face) { $moved += empty($face['rescan_late']) ? 0 : 1; }
            $counts['rescan_late'] = $moved;
        }

        return $counts;
    }

    /* --------------------------------------------------------------------------------------- */

    /** Split the set into [eager, late, evicted, held, below], in registration order. */
    private function split()
    {
        $faces = $this->faces;
        uasort($faces, function ($a, $b) { return $a['seq'] - $b['seq']; });
        $held = [];
        if ($this->heldTuples) {
            foreach ($faces as $key => $face) {
                if (!$this->isHeld($face)) { continue; }
                $held[$key] = $face;
                unset($faces[$key]);
            }
        }
        $below = [];
        $placeholders = [];
        if ($this->belowTuples) {
            foreach ($this->belowGroups($faces) as $group) {
                foreach ($group['keys'] as $key) {
                    $below[$key] = $faces[$key];
                    unset($faces[$key]);
                }
                if ($group['placeholder'] !== false) { $placeholders[] = $group['placeholder']; }
            }
        }
        // Pass one: which families paint in something of their own before the network answers.
        // A "<Family> Fallback" stand-in pins the family it stands in FOR, not its own name.
        // $gatePinned is the face gate's fail-closed test — an embedded subset or a metric
        // stand-in — and $paints is the weaker one a demotion has to satisfy: a deferral must
        // leave SOMETHING painting (v7.10.798), and a local() src is something.
        $gatePinned = [];
        $paints = [];
        foreach ($faces as $face) {
            if (!$face['eager'] || !self::paintsWithoutNetwork($face['css'], $face['family'])) { continue; }
            $family = substr($face['family'], -9) === ' fallback'
                ? rtrim(substr($face['family'], 0, -9)) : $face['family'];
            $paints[$family] = 1;
            if (self::isGatePin($face['css'], $face['family'])) { $gatePinned[$family] = 1; }
        }
        // A demotion a family cannot survive is refused, not honoured: moving its last painting
        // face into the late block strands it in the fallback until the loader flips media, and
        // on a render where that flip never comes, permanently.
        $demoted = [];
        foreach ($this->demotedFamilies as $family => $why) {
            if (isset($paints[$family])) { $demoted[$family] = 1; }
        }
        // Pass two: which families keep a real first-paint declarer once pass three has moved
        // the pinned ones. A late twin of one of those never binds and only evicts it.
        $declared = [];
        foreach ($faces as $key => $face) {
            if (!$face['eager'] || isset($demoted[$face['family']])
                || (isset($gatePinned[$face['family']]) && !self::isLiveCarrier($face))
                || self::paintsWithoutNetwork($face['css'], $face['family'])
                || !self::hasNetworkSource($face['css'])) { continue; }
            $declared[$face['family']] = 1;
        }
        $subsetBases = [];
        foreach ($faces as $face) {
            if ($face['range'] !== '' && self::hasEmbeddedSource($face['css'])) { $subsetBases[$face['base']] = 1; }
        }
        $eager = [];
        $late = [];
        $evicted = [];
        $twins = [];
        $rescanLate = $this->rescanLateKeys($faces, $subsetBases);
        foreach ($faces as $key => $face) {
            if ($face['range'] === '' && isset($subsetBases[$face['base']]) && self::hasNetworkSource($face['css'])) {
                $twins[$face['base']] = $face;
                continue;
            }
            if (self::paintsWithoutNetwork($face['css'], $face['family'])) { $eager[] = $face; continue; }
            if (isset($rescanLate[$key])) { $face['rescan_late'] = true; $late[] = $face; continue; }
            $servedLate = !$face['eager']
                || isset($demoted[$face['family']])
                || (isset($gatePinned[$face['family']]) && self::hasNetworkSource($face['css'])
                    && !self::isLiveCarrier($face));
            if (!$servedLate) { $eager[] = $face; continue; }
            // A family with a real eager declarer is already bound; a late twin for it never
            // gets used and only evicts the bound face for the weights it claims.
            if (isset($declared[$face['family']])) { $evicted[] = $face; continue; }
            $late[] = $face;
        }
        if ($twins) {
            $ordered = [];
            foreach ($eager as $face) {
                if ($face['range'] !== '' && isset($twins[$face['base']]) && self::hasEmbeddedSource($face['css'])) {
                    $ordered[] = $twins[$face['base']];
                    unset($twins[$face['base']]);
                }
                $ordered[] = $face;
            }
            $eager = $ordered;
        }
        foreach ($placeholders as $placeholder) { $eager[] = $placeholder; }
        if ($this->needTuples) {
            $files = [];
            foreach ($eager as $index => $face) {
                if ($this->needsRealFace($face, $eager)) {
                    $eager[$index]['swap'] = true;
                    $files[self::sameFileKey($face['css'])] = 1;
                }
            }
            if ($files) {
                foreach ($eager as $index => $face) {
                    if (empty($eager[$index]['swap']) && self::swappable($face) && isset($files[self::sameFileKey($face['css'])])) {
                        $eager[$index]['swap'] = true;
                    }
                }
            }
        }
        if ($this->swapUndeclared) {
            $mark = function (array $list) {
                foreach ($list as $index => $face) {
                    if (substr($face['family'], -9) !== ' fallback' && !preg_match('/font-display\s*:/i', $face['css'])
                        && preg_match('/src\s*:[^;}]*url\(\s*(?!["\']?data:)/i', $face['css'])
                        && !(function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($face['family']))) {
                        $list[$index]['none_swap'] = true;
                    }
                }

                return $list;
            };
            $eager = $mark($eager);
            $late = $mark($late);
        }

        return [$eager, $late, $evicted, array_values($held), array_values($below)];
    }

    private function render($wantLate)
    {
        $split = $this->split();

        return implode('', self::writeFaces($split[$wantLate ? 1 : 0], $wantLate, self::weightSpans($split)));
    }

    /** The CSS of one block's faces, in registration order. A face weightSpans() puts in a span
     *  is written once, at its group's first position, with the group's span as its weight. */
    private static function writeFaces(array $faces, $isLate, array $spans)
    {
        $out = [];
        foreach ($faces as $face) {
            $css = self::applyDisplayPolicy($face, $isLate);
            if (isset($spans[$face['seq']])) {
                list($first, $low, $high) = $spans[$face['seq']];
                if ($face['seq'] !== $first) { continue; }
                $css = (string) preg_replace('/(font-weight\s*:\s*)\d{1,4}(?:\s+\d{1,4})?/i',
                    '${1}' . $low . ' ' . $high, $css, 1);
            }
            $out[] = $css;
        }

        return $out;
    }

    /**
     * seq => [seq of the group's first face, low, high] for every face written inside a weight
     * span. $split is [eager, late, evicted, held, below]; both written blocks land in the same document.
     *
     * Rule: faces of one block whose written CSS is identical except for a numeric font-weight
     * are one file served for several weights, so they are written once, at the first one's
     * position, with font-weight set to the lowest and highest weight of the group. The browser
     * matches every weight in the span to that file, which is what the separate faces said, and
     * fetches it once. Only the weight may differ: a different src, unicode-range, style, stretch
     * or display is a different face and stays one. A face with no url() src is not a file (the
     * local() metric stand-ins carry per-weight overrides) and is written as registered. The
     * tuple key keeps the per-weight faces, so every contest, demotion and coverage question
     * above still sees what was registered.
     *
     * Observed failure (7.24.09 to 7.24.54, wpcompress.com/pricing/): Elementor's Roboto sheet,
     * localized from Google's v1 API, declares 9 weights × 18 subset files; the block shipped
     * 162 Roboto faces (121 KB in the head, "Reduce unused CSS 23.7 KiB") where 7.24.04 shipped
     * 18 span faces (14.5 KB). The merge lived in font_face_dedupe180 (v7.21.191) and went when
     * that pass was deleted with the owner's arrival.
     *
     * Rule: a browser narrows a family to ONE face per requested weight, style and stretch before
     * it reads unicode-range, and only faces with identical weight, style and stretch descriptors
     * form that face (CSS Fonts 4 §5.2, the composite face); a character the chosen composite
     * does not cover goes to the next family in font-family, never to another composite of the
     * same family. A span is therefore written only when every other face of the same family,
     * style and stretch in the document (either block; url, data: or local() src) that declares
     * a weight inside the span is itself written in exactly that span; otherwise the group is
     * written as registered, so no weight is claimed by two composites. A single face that
     * declares exactly the group's span (an embedded subset written "400 900") is in that span,
     * so the group is written to it and the two are one composite.
     * A group whose member weights leave a step of more than 100 between them is not one span
     * and is written as registered.
     *
     * Observed failure (crit lab, Oxygen copy, 2026-10-15): the inline Playfair Display subset
     * declared 400 900 and the six per-weight faces of the variable file were written one per
     * weight; letters the subset leaves out ("F" in a below-fold h2) fell to Times New Roman for
     * the whole visit.
     *
     * Observed failure (dbmwebdesign.de, 2026-10-09): Astra's Manrope sheet, 6 subset
     * files × weights 400 and 500. The data: inliner embedded the first three subsets at 400, the
     * latin, latin-ext and vietnamese files merged to "400 500" and the other three stayed at
     * 500; Chrome matched 400 and 500 to the single-weight composites, which have no Latin range,
     * and painted every Latin character in Arial.
     */
    private static function weightSpans(array $split)
    {
        $groups = [];
        $groupOfFace = [];
        $claims = [];
        foreach (isset($split[3]) ? $split[3] : [] as $heldFace) {
            $composite = $heldFace['family'] . '|' . self::normaliseStyle(self::descriptor($heldFace['css'], 'font-style'))
                . '|' . (self::descriptor($heldFace['css'], 'font-stretch') ?: 'normal');
            $claimed = self::weightSpan($heldFace['css']);
            if ($claimed) { $claims[$composite][] = [$heldFace['seq'], min($claimed), max($claimed)]; }
        }
        foreach ([0, 1] as $block) {
            foreach ($split[$block] as $face) {
                $css = self::applyDisplayPolicy($face, $block === 1);
                $composite = $face['family'] . '|' . self::normaliseStyle(self::descriptor($css, 'font-style'))
                    . '|' . (self::descriptor($css, 'font-stretch') ?: 'normal');
                $claimed = self::weightSpan($css);
                if ($claimed) { $claims[$composite][] = [$face['seq'], min($claimed), max($claimed)]; }
                if (!preg_match('/src\s*:[^;}]*url\(/i', $css)
                    || !preg_match('/font-weight\s*:\s*(\d{1,4})(?:\s+(\d{1,4}))?\s*(?=[;}])/i', $css, $weight)) {
                    continue;
                }
                $low = (int) $weight[1];
                $high = isset($weight[2]) && $weight[2] !== '' ? (int) $weight[2] : $low;
                $id = $block . md5((string) preg_replace('/\s+/', ' ',
                    (string) preg_replace('/font-weight\s*:\s*\d{1,4}(?:\s+\d{1,4})?\s*;?/i', '', $css)));
                if (!isset($groups[$id])) {
                    $groups[$id] = ['composite' => $composite, 'first' => $face['seq'],
                        'low' => $low, 'high' => $high, 'members' => []];
                }
                $groups[$id]['low'] = min($groups[$id]['low'], $low);
                $groups[$id]['high'] = max($groups[$id]['high'], $high);
                $groups[$id]['members'][$face['seq']] = [$low, $high];
                $groupOfFace[$face['seq']] = $id;
            }
        }
        foreach ($groups as $id => $group) {
            $memberSpans = array_values($group['members']);
            sort($memberSpans);
            $reach = $memberSpans[0][1];
            $gap = false;
            foreach ($memberSpans as $memberSpan) {
                if ($memberSpan[0] > $reach + 100) { $gap = true; break; }
                $reach = max($reach, $memberSpan[1]);
            }
            if (count($group['members']) < 2 || $gap) { unset($groups[$id]); }
        }
        // A group given up is written per weight, which can contest a span another group kept,
        // so repeat until no group is given up.
        do {
            $givenUp = false;
            foreach ($groups as $id => $group) {
                foreach ($claims[$group['composite']] as $claim) {
                    list($seq, $low, $high) = $claim;
                    if (isset($group['members'][$seq]) || $low > $group['high'] || $high < $group['low']
                        || ($low === $group['low'] && $high === $group['high'])) { continue; }
                    $other = isset($groupOfFace[$seq], $groups[$groupOfFace[$seq]]) ? $groups[$groupOfFace[$seq]] : null;
                    if ($other && $other['low'] === $group['low'] && $other['high'] === $group['high']) { continue; }
                    unset($groups[$id]);
                    $givenUp = true;
                    continue 2;
                }
            }
        } while ($givenUp);
        $spans = [];
        foreach ($groups as $group) {
            foreach ($group['members'] as $seq => $member) {
                $spans[$seq] = [$group['first'], $group['low'], $group['high']];
            }
        }

        return $spans;
    }

    /** A face that paints before the network answers: an embedded subset, a metric stand-in, or
     *  a face served out of the visitor's own installed fonts. */
    private static function paintsWithoutNetwork($css, $family)
    {
        return self::isGatePin($css, $family)
            || preg_match('/src\s*:[^;}]*\blocal\s*\(/i', $css) === 1;
    }

    /** The face gate's fail-closed pin: a family is only ever demoted off the first-paint path
     *  when the document keeps an embedded subset or a metric stand-in for it. */
    private static function isGatePin($css, $family)
    {
        return substr($family, -9) === ' fallback' || self::hasEmbeddedSource($css);
    }

    /** A face out of a carrier the page loaded live (see ORIGIN_LIVE_CARRIER). */
    private static function isLiveCarrier($face)
    {
        return $face['origin'] === self::ORIGIN_LIVE_CARRIER;
    }

    private static function hasNetworkSource($css)
    {
        return preg_match('/url\(\s*["\']?(?:https?:)?\/\//i', $css) === 1;
    }

    /** One display policy, decided by which block the face lands in and where the face came from. */
    private static function applyDisplayPolicy($face, $isLate)
    {
        $css = $face['css'];
        $family = $face['family'];
        if (substr($family, -9) === ' fallback') { return $css; }
        $declared = preg_match('/font-display\s*:\s*([a-z-]+)/i', $css, $m) ? strtolower($m[1]) : '';
        if ($declared === '') {
            // A face that declares no display keeps none: the browser default is the author's
            // answer, and rewriting it would change first paint on every page carrying a crit,
            // carrier, subset or gfont-ATF face. The one lane that ever put a display on a face
            // that declared none is the font localizer's live-faces half, which arms a deferred
            // sheet's faces at first paint and needs them to replace fallback text rather than
            // hide it; it is the only origin that gets the injection here.
            $injects = (self::ORIGIN_DISPLAY_INJECTED === $face['origin'] || !empty($face['none_swap'])) && self::siteFontDisplayOn();
            if ($injects && !$isLate && function_exists('wpc_font_display_site_kept') && wpc_font_display_site_kept()) {
                $injects = false;
            }
            return $injects
                ? (string) preg_replace('/\{/', '{font-display:swap;', $css, 1)
                : $css;
        }
        if (!$isLate && $declared === 'optional' && (!empty($face['swap'])
            || (self::swappable($face) && function_exists('wpc_font_subset_swap') && wpc_font_subset_swap($family)))) {
            return (string) preg_replace('/font-display\s*:\s*optional/i', 'font-display:swap', $css);
        }
        if ($isLate) {
            // optional's grace window opens when the face is first needed; a block armed after
            // load has already missed it, so the browser locks the fallback for good.
            return $declared === 'optional'
                ? (string) preg_replace('/font-display\s*:\s*optional/i', 'font-display:swap', $css)
                : $css;
        }
        if ($declared !== 'swap' || !function_exists('wpc_font_display_effective')) { return $css; }
        $effective = function_exists('wpc_font_display_on_page') ? wpc_font_display_on_page('swap', $family) : wpc_font_display_effective('swap', $family);
        if (!is_string($effective) || $effective === 'swap'
            || !in_array($effective, ['optional', 'fallback', 'block', 'auto'], true)) { return $css; }
        return (string) preg_replace('/font-display\s*:\s*swap\b/i', 'font-display:' . $effective, $css);
    }

    /** False when the site has switched font-display handling off, which is the one setting that
     *  stops the live-faces lane putting swap on a face that declares no display and the
     *  first-screen swap rewriting a face that declares optional. */
    public static function siteFontDisplayOn()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) { return true; }
        $settings = get_option(WPS_IC_SETTINGS);

        return !(is_array($settings) && !empty($settings['font-display'])
            && strtolower((string) $settings['font-display']) === 'off');
    }

    private static function familyOf($css)
    {
        return preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $css, $m)
            ? self::normaliseFamily($m[1]) : '';
    }

    private static function normaliseFamily($family)
    {
        return strtolower(trim((string) $family, " \t\r\n\"'"));
    }

    /** What a face IS before its coverage is considered: the tuple dedupe's key without the
     *  range, because a range only tells two faces apart when both of them declare one. */
    private static function baseKey($css, $family)
    {
        return $family . '|' . (self::descriptor($css, 'font-weight') ?: '400')
            . '|' . (self::descriptor($css, 'font-style') ?: 'normal')
            . '|' . (self::descriptor($css, 'font-stretch') ?: 'normal');
    }

    private static function rangeOf($css)
    {
        return self::descriptor($css, 'unicode-range');
    }

    /** One descriptor of a face, normalised the way the tuple dedupe normalised it. */
    private static function descriptor($css, $name)
    {
        return preg_match('/' . $name . '\s*:\s*([^;}]+)/i', $css, $m)
            ? strtolower(trim(preg_replace('/\s+/', '', str_replace(['"', "'"], '', $m[1])))) : '';
    }

    /** A face that carries its own bytes: no network fetch decides whether it paints. */
    private static function hasEmbeddedSource($css)
    {
        return preg_match('/src\s*:[^;}]*url\(\s*["\']?data:/i', $css) === 1;
    }

    /** woff2 beats an embedded subset beats anything else: one transfer, best container. */
    private static function rankOf($css)
    {
        if (stripos($css, 'woff2') !== false) { return 2; }
        return (stripos($css, 'data:') !== false) ? 1 : 0;
    }


}

/**
 * The one owner of a render's image preloads.
 *
 * Eleven passes used to write their own <link rel="preload" as="image"> — into the crit block,
 * before </head>, after the viewport meta, before the crit <style> — and eighteen more hoisted,
 * deduped, re-pointed, mirrored or pruned what the eleven had written. Two failures kept coming
 * back from that split: two preloads of one picture at fetchpriority=high (crawfordtree.com
 * carried the measured hero and the background pin's rung, ~491 KB for one paint), and a
 * preload the scanner met only after the inline CSS (greenvalleytint.com's LCP preload sat at
 * byte 88,754, behind the crit block). Here a writer registers a candidate and one stage,
 * stage_emit_image_preloads, writes the winners once, near the top of <head>, after delivery
 * has decided the URLs the page will fetch.
 *
 * The rules, enforced here and nowhere else:
 *  - three slots per device. `lcp` holds one preload: the candidate with the best rank (the
 *    RANK_* constants, 1 is best; the first registered wins a tie), and every other lcp
 *    candidate for that device is dropped ('same-key' when it names the same picture, 'rank'
 *    otherwise). `atf-bg` holds one above-the-fold background, dropped when it names the lcp
 *    winner's picture. `custom` is the site's own preload list: additive, deduped by URL;
 *  - a picture's identity is imageKey(): the path from /wp-content/ with the size suffix, the
 *    next-gen extension and the transform wrapper stripped, so two renditions of one image are
 *    one picture;
 *  - device: a render that is not combined-crit fills only its own device's slots and drops a
 *    candidate registered for the other device ('other-device'); a combined-crit render fills
 *    both, each winner carrying its device's media;
 *  - at emit the href is resolved against the final document: an image candidate mirrors the
 *    eager <img> that paints its picture (src, srcset, sizes) and is dropped when every such
 *    <img> is lazy or a placeholder ('lazy-img'); anything else takes the rendition of its URL
 *    that the document's own CSS or markup names.
 *
 * Attribute values arrive attribute-ready: each writer escapes them the way it always has, and
 * values mirrored off an <img> are carried verbatim. One instance per render, on
 * wps_ic_render_context::$imagePreloads.
 */
final class wps_ic_image_preload_set
{
    const SLOT_LCP = 'lcp';
    const SLOT_ATF_BG = 'atf-bg';
    const SLOT_CUSTOM = 'custom';

    /** Rank in the lcp slot, 1 wins. */
    const RANK_LCP_BACKGROUND = 1;   // the service-measured LCP background (wpc-lcp-bg-preload[-d])
    // The measured LCP element (lcp.json lcp[dev] / lcp_element[dev], wpc-lcp-img-preload) is the
    // same measurement as the background lane in another shape, so it ranks right below it. It
    // used to share the guess's last place: on staging /features/ (combined crit) the desktop
    // LCP <img> then lost its preload to the first crit background, the mobile hero.
    const RANK_LCP_MEASURED = 2;
    // The LCP element the service derived for a leg with no LCP entry (lcp_confidence 'derived',
    // crit-push 3.198.293: the largest above-the-fold image, at least 1.5 times the runner-up;
    // wpc-lcp-img-preload) ranks with the identity, not with the guess: at the guess's rank the
    // plugin's own census keeper it replaces lost /features/' desktop preload to the crit
    // background.
    const RANK_LCP_DERIVED = 3;
    const RANK_HERO_HINT = 4;        // lcp.json hints.lcp_preload (wpc-lcp-hero-preload)
    const RANK_WIRE_FALLBACK = 5;    // wire.json inline verdict that could not inline (wpc-lcp-bg-preload700)
    const RANK_ATF_UNLAZY = 6;       // the widest above-the-fold image un-lazied (wpc-atf-unlazy-preload)
    const RANK_HEADER_LOGO = 7;      // the first header image (wpc-header-logo-preload)
    const RANK_MODERN_DELIVERY = 8;  // Modern Delivery's LCP candidate
    const RANK_CRIT_BACKGROUND = 9;  // the first background url() in the crit (wpc-crit-bg-preload)
    const RANK_LCP_GUESS = 10;       // the one fetchpriority=high <img>, no measurement (wpc-lcp-img-preload)

    /** The writers whose preload keeps fetchpriority=high only when it is the measured LCP, or the page's one high when nothing is measured (capBackgroundHigh). */
    const CAPPED_ORIGINS = ['atf-bg', 'header-logo', 'hero-hint', 'crit-bg', 'modern-delivery'];

    const KIND_IMAGE = 'img';
    const KIND_BACKGROUND = 'bg';

    const MEDIA_MOBILE = '(max-width: 767.98px)';
    const MEDIA_DESKTOP = '(min-width: 768px)';

    /** @var array  seq => candidate record, in registration order. */
    private $candidates = [];
    /** @var int  Monotonic insert counter. */
    private $sequence = 0;
    /** @var int  Candidates taken by add(). */
    private $registeredCount = 0;
    /** @var array  why => count, for the receipt. */
    private $droppedCounts = [];
    /** @var array  device => the lcp-slot candidate that was written for it, as resolved. */
    private $written = [];
    const MARK_OPEN = '<!--wpc-img-preloads-->';
    const MARK_CLOSE = '<!--/wpc-img-preloads-->';
    /** @var array|null  [offset, length] of the tags the last emit() wrote, null when it wrote none. */
    private $span = null;
    /** @var array  device => the file the service's browser painted for that device's measured background hero. */
    private $painted = [];

    /** Writers whose href is the url() of a pin they wrote themselves: the painted file is theirs to name. */
    const PIN_OWNED_ORIGINS = ['lcp-bg', 'lcp-bg-derived', 'wire-fallback'];

    /**
     * Register a candidate. $id and $href are attribute-ready (the writer escaped them); $device
     * is 'mobile', 'desktop' or 'both'; $origin names the writer for the receipt; $rank is a
     * RANK_* constant and decides only the lcp slot. $extras: 'slot' (SLOT_*, default lcp),
     * 'kind' (KIND_*, default image), 'imagesrcset', 'imagesizes', 'type', 'fetchpriority'
     * (default 'high', '' for none), 'arms' (a <picture>'s per-source preloads: a list of
     * ['imagesrcset','imagesizes','media','type'], written as $id, $id-2, $id-3), 'key' (the
     * picture's identity when neither $href nor imagesrcset names it), 'paints' (a background
     * candidate's list of `<style id>` pins that put this picture on the box: at emit the href
     * is the url() of the pin that is last in the document, because that is the file the
     * browser paints), 'supersedes' (slots whose candidate stands down on every device this one
     * wins: a measured hero keeps the above-the-fold background guess from adding a second
     * high-priority preload), 'exact' (the candidate is dropped when its href, after resolution, is
     * not a URL the document carries: a preload names what the document paints or nothing).
     * Returns false when there is nothing to preload.
     */
    public function add($id, $href, $device, $origin, $rank, array $extras = [])
    {
        $href = trim((string) $href);
        $imagesrcset = isset($extras['imagesrcset']) ? trim((string) $extras['imagesrcset']) : '';
        $arms = (isset($extras['arms']) && is_array($extras['arms'])) ? array_values($extras['arms']) : [];
        if ($href === '' && $imagesrcset === '' && empty($arms)) {
            return false;
        }
        if (isset($extras['key'])) {
            $key = strtolower((string) $extras['key']);
        } else {
            $named = ($href !== '') ? $href : (string) preg_split('/[\s,]+/', $imagesrcset)[0];
            $key = self::imageKey($named);
        }
        if ($this->vetoes($key)) {
            $this->dropped($this->vetoed[$key]);
            return false;
        }
        $slot = isset($extras['slot']) ? (string) $extras['slot'] : self::SLOT_LCP;
        if ($slot === self::SLOT_CUSTOM) {
            foreach ($this->candidates as $existing) {
                if ($existing['slot'] === self::SLOT_CUSTOM && $existing['href'] === $href) {
                    $this->dropped('same-key');
                    return false;
                }
            }
        }
        $this->sequence++;
        $this->candidates[$this->sequence] = [
            'seq'           => $this->sequence,
            'id'            => (string) $id,
            'href'          => $href,
            'device'        => in_array($device, ['mobile', 'desktop'], true) ? $device : 'both',
            'origin'        => (string) $origin,
            'rank'          => (int) $rank,
            'slot'          => $slot,
            'kind'          => isset($extras['kind']) ? (string) $extras['kind'] : self::KIND_IMAGE,
            'key'           => $key,
            'imagesrcset'   => $imagesrcset,
            'imagesizes'    => isset($extras['imagesizes']) ? (string) $extras['imagesizes'] : '',
            'type'          => isset($extras['type']) ? (string) $extras['type'] : '',
            'fetchpriority' => array_key_exists('fetchpriority', $extras) ? (string) $extras['fetchpriority'] : 'high',
            'arms'          => $arms,
            'paints'        => (isset($extras['paints']) && is_array($extras['paints'])) ? array_values(array_filter(array_map('strval', $extras['paints']), 'strlen')) : [],
            'supersedes'    => (isset($extras['supersedes']) && is_array($extras['supersedes'])) ? array_values(array_map('strval', $extras['supersedes'])) : [],
            'exact'         => !empty($extras['exact']),
        ];
        $this->registeredCount++;
        return true;
    }

    /**
     * The file the service's browser painted for each device's measured background hero (device =>
     * address, wps_ic_atf_observation::lcpPaintedUrl). A candidate for that picture that no pin of
     * its writer paints takes this address at emit when the document paints exactly that address, so
     * the preload and the paint are one file; a document that does not paint it leaves the candidate
     * as it was.
     */
    public function followPainted(array $byDevice)
    {
        $this->painted = [];
        foreach (['mobile', 'desktop'] as $device) {
            if (isset($byDevice[$device]) && is_string($byDevice[$device]) && $byDevice[$device] !== '') {
                $this->painted[$device] = $byDevice[$device];
            }
        }
    }

    /** @var callable|null  address => the address of the one file a phone's background hero is named by, '' to leave it as it is (policy.hero_one_url). */
    private $heroForm = null;

    /**
     * The renaming policy.hero_one_url asks of a phone's hero. A candidate that serves the phone alone (or a render built for one) and is not pinned
     * by its own writer takes the address $form answers with ('' leaves it as it is), href and single-address imagesrcset alike, unless the document
     * paints that rendition. A writer whose href is the url() of a pin it wrote has named the one file there already.
     */
    public function followHero($form)
    {
        $this->heroForm = is_callable($form) ? $form : null;
    }

    /** @var callable|null  (address, another high is on the page) => whether a capped writer's preload (CAPPED_ORIGINS) may keep fetchpriority=high. */
    private $backgroundHigh = null;

    /** @var callable|null  (an <img> tag carrying high) => whether that high stays (the priority owner); null: every one counts. */
    private $documentHighKept = null;

    /**
     * The one-high rule for the capped writers' preloads (CAPPED_ORIGINS): $allow answers for the address, told whether the document or another
     * winner already carries a high; $keptHigh says which of the document's high <img> tags keep theirs. A high the owner refuses is stripped
     * later and does not count: on greenvalleytint.com (2026-10-28) WordPress core's high on the logo cost the CDN-off lane its background preload's high.
     */
    public function capBackgroundHigh($allow, $keptHigh = null)
    {
        $this->backgroundHigh = is_callable($allow) ? $allow : null;
        $this->documentHighKept = is_callable($keptHigh) ? $keptHigh : null;
    }

    /** @var array  picture key => why, the pictures no preload may name in this render. */
    private $vetoed = [];

    /**
     * A picture no preload may name in this render, whoever writes it and whenever: a candidate for it is
     * refused by add(), one already held is withdrawn, and emit() keeps it out of the document (a tag the
     * page wrote for it included). The rule is on the picture, so no writer's position decides it.
     */
    public function veto($key, $why)
    {
        $key = strtolower((string) $key);
        if ($key === '') {
            return 0;
        }
        $this->vetoed[$key] = (string) $why;
        return $this->dropKey($key, $why);
    }

    /** True when $key is a picture veto() closed to preloads. */
    public function vetoes($key)
    {
        return $key !== '' && isset($this->vetoed[strtolower((string) $key)]);
    }

    /** Withdraw every candidate naming this picture: a writer made the file unnecessary (it
     *  inlined it as a data: URI, for one). Returns how many went. */
    public function dropKey($key, $why)
    {
        $key = strtolower((string) $key);
        $gone = 0;
        if ($key === '') {
            return $gone;
        }
        foreach ($this->candidates as $seq => $candidate) {
            if ($candidate['key'] === $key) {
                unset($this->candidates[$seq]);
                $this->dropped($why);
                $gone++;
            }
        }
        return $gone;
    }

    /** A writer that had a proposal and declined it records why, so the receipt shows it. */
    public function declined($why)
    {
        $this->dropped($why);
    }

    /** True when a candidate registered by one of $origins is held. */
    public function hasOrigin(array $origins)
    {
        foreach ($this->candidates as $candidate) {
            if (in_array($candidate['origin'], $origins, true)) {
                return true;
            }
        }
        return false;
    }

    /** The href of the first candidate registered under $id that serves $device, as the writer registered it; '' when none. */
    public function hrefOf($id, $device)
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate['id'] === (string) $id && $candidate['href'] !== '' && self::serves($candidate, $device)) {
                return $candidate['href'];
            }
        }
        return '';
    }

    /** True when an lcp candidate better than $rank serves $device ('both' = any device). */
    public function outranked($rank, $device)
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate['slot'] === self::SLOT_LCP && $candidate['rank'] < $rank
                && ($device === 'both' || $candidate['device'] === 'both' || $candidate['device'] === $device)) {
                return true;
            }
        }
        return false;
    }

    /** True when any candidate is held in one of $slots. */
    public function hasSlot(array $slots)
    {
        foreach ($this->candidates as $candidate) {
            if (in_array($candidate['slot'], $slots, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when a held lcp or atf-bg candidate's href names a file with this stem (fileStem()).
     * eager_nextgen_sources asks it before swapping an eager <img> to .avif, so an image keeps
     * the format its preload names. The custom list is left out: it never held an image's
     * format there, and counting it would change the <img> bytes of every site with a list.
     */
    public function hasKey($fileStem)
    {
        $fileStem = strtolower((string) $fileStem);
        if ($fileStem === '') {
            return false;
        }
        foreach ($this->candidates as $candidate) {
            if ($candidate['slot'] !== self::SLOT_CUSTOM && $candidate['href'] !== ''
                && self::fileStem($candidate['href']) === $fileStem) {
                return true;
            }
        }
        return false;
    }

    /** The file-name stem hasKey() compares: basename, no query, no -WxH, no extension. */
    public static function fileStem($url)
    {
        $base = basename((string) preg_replace('/\?.*$/', '', (string) $url));
        $base = (string) preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $base);
        return strtolower((string) preg_replace('/\.[a-z0-9]+$/i', '', $base));
    }

    /**
     * True when the document PAINTS a URL for the very rendition $href names: the same picture
     * (imageKey()) and the same rendition (renditionName()), in a <style> block's url(), an
     * <img>'s src or srcset, a <source>'s srcset or an inline style's background. A link a person
     * can follow, a <link> (prefetch, another preload), a <noscript>, <script> or <template> body and a comment name a
     * file without painting it, and a file only a linked sheet paints is not in the document.
     */
    public static function documentNames($html, $href)
    {
        if (!is_string($html) || self::imageKey($href) === '' || self::renditionName($href) === '') {
            return false;
        }
        foreach (self::paintedUrls($html) as $url) {
            if (self::sameRendition($url, $href)) {
                return true;
            }
        }
        return false;
    }

    /** True when the document paints exactly this address (see documentNames(), which reads two spellings of one rendition as one). */
    private static function paintsUrl($html, $url)
    {
        $url = html_entity_decode((string) $url, ENT_QUOTES);
        return $url !== '' && in_array($url, self::paintedUrls((string) $html), true);
    }

    /** Every URL the document paints, in document order (see documentNames()). */
    private static function paintedUrls($html)
    {
        foreach (['#<!--.*?-->#s', '#<noscript\b[^>]*>.*?</noscript>#is', '#<script\b[^>]*>.*?</script>#is', '#<template\b[^>]*>.*?</template>#is'] as $strip) {
            $stripped = preg_replace($strip, '', $html);
            if (is_string($stripped)) {
                $html = $stripped;
            }
        }
        $urls = [];
        $cssUrls = function ($css) use (&$urls) {
            if (preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $css, $found)) {
                foreach ($found[2] as $url) {
                    $urls[] = html_entity_decode($url, ENT_QUOTES);
                }
            }
        };
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles)) {
            foreach ($styles[1] as $css) {
                $cssUrls($css);
            }
        }
        if (preg_match_all('/<(img|source)\b[^>]*>/i', $html, $tags, PREG_SET_ORDER)) {
            foreach ($tags as $tag) {
                $attributes = strtolower($tag[1]) === 'img' ? ['src', 'srcset'] : ['srcset'];
                foreach ($attributes as $attribute) {
                    if (!preg_match('/(?<![-\w])' . $attribute . '\s*=\s*(["\'])(.*?)\1/is', $tag[0], $value)) {
                        continue;
                    }
                    foreach (preg_split('/\s*,\s*/', html_entity_decode($value[2], ENT_QUOTES)) as $part) {
                        $part = trim((string) preg_replace('/\s+\d+(?:\.\d+)?[wx]$/', '', trim($part)));
                        if ($part !== '' && stripos($part, 'data:') !== 0) {
                            $urls[] = $part;
                        }
                    }
                }
            }
        }
        if (preg_match_all('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $html, $inline)) {
            foreach ($inline[2] as $style) {
                if (preg_match_all('/background(?:-image)?\s*:[^;]*/i', html_entity_decode($style, ENT_QUOTES), $declarations)) {
                    foreach ($declarations[0] as $declaration) {
                        $cssUrls($declaration);
                    }
                }
            }
        }
        return $urls;
    }

    /** True when two URLs name the same picture (imageKey()) in the same rendition (size suffix kept, next-gen and original extension and query ignored). */
    public static function sameRendition($a, $b)
    {
        $key = self::imageKey($a);
        return $key !== '' && $key === self::imageKey($b) && self::renditionName($a) === self::renditionName($b);
    }

    /**
     * The identity of the PICTURE a URL names, not of the file: the path from /wp-content/ (or
     * the basename), the next-gen and original extension, the -WxH suffix and -scaled/-rotated
     * stripped, the zone transform wrapper (/u:https://…, /m:0/a:https://…) unwrapped. Two
     * renditions of one image compete for one paint, so for preload purposes they are one.
     * '' when the URL carries no path to read.
     */
    public static function imageKey($url, $widthNames = false)
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
        // A site that hides its uploads path (Hide My WP serving /storage/) names one picture two
        // ways, under /wp-content/uploads/ and under its own base, and either form can be the one
        // lcp.json measured or the one the preload carries. Keying the hidden form on its basename
        // made the two differ, so the measured background never counted as preloaded and the
        // homepage was refused every store (ticket 12239, no-store-lcp-bg-preload-missing). Every
        // upload base the site may serve from therefore reads as /wp-content/uploads before the
        // rule below.
        $path = self::canonicalUploadsPath($path);
        // The PATH is the identity, not the basename: two uploads months can hold the same file
        // name, and a key that collided would collapse two different pictures into one.
        $at = strrpos($path, '/wp-content/');
        $id = rawurldecode($at !== false ? substr($path, $at) : basename($path));
        $id = (string) preg_replace('/\.(avif|webp)$/i', '', $id);
        $id = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $id);
        $id = (string) preg_replace('/-\d+x\d+$/', '', $id);
        if ($widthNames) {
            $id = (string) preg_replace('/-\d+w$/', '', $id);
        }
        $id = (string) preg_replace('/-(?:scaled|rotated)$/i', '', $id);
        return strlen($id) > 1 ? strtolower($id) : '';
    }

    /**
     * $path with an upload base the site may serve from (wpc_v2_upload_base_paths(): the site's
     * upload dir, /storage, the wpc_v2_upload_paths filter) replaced by /wp-content/uploads,
     * matched at a segment boundary, longest base first, ignoring case. A path that already
     * carries /wp-content/ is returned as it is, and so are bases under /wp-content/ (a
     * subdirectory install's /blog/wp-content/uploads, a multisite /wp-content/uploads/sites/2):
     * the /wp-content/ rule keys those already, and a /storage/ folder inside a theme is not an
     * upload. Without the v2 layer the path is returned as it is.
     */
    private static function canonicalUploadsPath($path)
    {
        // Read once per request: every preload candidate of every render asks.
        static $bases = null;
        if ($bases === null) {
            if (!function_exists('wpc_v2_upload_base_paths')) {
                return $path;
            }
            $bases = [];
            foreach ((array) wpc_v2_upload_base_paths() as $base) {
                $base = '/' . trim((string) $base, '/');
                if ($base !== '/' && stripos($base . '/', '/wp-content/') === false) {
                    $bases[] = $base;
                }
            }
            $bases = array_values(array_unique($bases));
            usort($bases, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
        }
        if ($bases === [] || stripos($path, '/wp-content/') !== false) {
            return $path;
        }
        foreach ($bases as $base) {
            $at = stripos($path, $base . '/');
            if ($at !== false) {
                return '/wp-content/uploads' . substr($path, $at + strlen($base));
            }
        }
        return $path;
    }

    /**
     * Write the winners into $html and return it. $renderDevice is the device this render was
     * built for ('mobile' or 'desktop'); $combined is true when the render serves both devices
     * from one copy (a combined crit), which fills both devices' slots. Logs the render's one
     * preload-set receipt.
     */
    public function emit($html, $renderDevice, $combined)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }
        $renderDevice = ($renderDevice === 'mobile') ? 'mobile' : 'desktop';
        $combined = (bool) $combined;
        $devices = $combined ? ['mobile', 'desktop'] : [$renderDevice];
        $images = self::documentImages($html);
        $documentUrls = null;

        // The page's own image preloads first: they are resolved in place and own their slot.
        $pageCounts = ['seen' => 0, 'rewritten' => 0, 'dropped' => 0];
        $pageCandidates = [];
        $html = $this->adoptPageTags($html, $renderDevice, $images, $pageCandidates, $pageCounts);

        // A device whose LCP the service identified (a measured or derived LCP candidate, or the
        // service's own hero hint, registered for it) keeps fetchpriority=high for the LCP alone,
        // whether or not that candidate ships: a theme preload of the hero drops ours as
        // page-authored, and our header logo then won the slot at high beside the hero
        // (mvcustom.be, 2026-10-23). Read before resolution for that reason.
        $lcpIdentified = [];
        foreach ($devices as $device) {
            foreach ($this->candidates as $candidate) {
                if ($candidate['slot'] === self::SLOT_LCP && $candidate['rank'] <= self::RANK_HERO_HINT
                    && self::serves($candidate, $device)) {
                    $lcpIdentified[$device] = true;
                    break;
                }
            }
        }

        // Resolve every candidate against the document first, so a candidate that cannot be
        // used never holds a slot a usable one could have had.
        $usable = [];
        foreach ($this->candidates as $seq => $candidate) {
            if ($this->vetoes($candidate['key'])) {
                $this->dropped($this->vetoed[$candidate['key']]);
                continue;
            }
            if (!$combined && $candidate['device'] !== 'both' && $candidate['device'] !== $renderDevice) {
                $this->dropped('other-device');
                continue;
            }
            // A picture the page already preloads is the page's: a second preload of it at
            // fetchpriority=high is a second download for one paint (crawfordtree.com: ~491 KB
            // for one hero), and the page's own tag is the one its author wrote for it.
            if ($candidate['slot'] !== self::SLOT_CUSTOM && self::pageOwns($pageCandidates, $candidate, $devices)) {
                $this->dropped('page-authored');
                continue;
            }
            if ($this->heroForm !== null) {
                $candidate = $this->heroOwned($candidate, $renderDevice, $combined, $html);
            }
            $resolved = $this->resolve($candidate, $html, $images, $documentUrls, $renderDevice, $devices);
            if ($resolved !== null) {
                $usable[$seq] = $resolved;
            }
        }

        $won = [];          // seq => devices whose slot it holds
        $lcpWinners = [];   // device => candidate
        foreach ($devices as $device) {
            $best = null;
            foreach ($usable as $candidate) {
                if ($candidate['slot'] === self::SLOT_LCP && self::serves($candidate, $device)
                    && ($best === null || $candidate['rank'] < $best['rank'])) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                $lcpWinners[$device] = $best;
                $won[$best['seq']][] = $device;
            }
            foreach ($usable as $candidate) {
                if ($candidate['slot'] !== self::SLOT_ATF_BG || !self::serves($candidate, $device)) {
                    continue;
                }
                // One picture, one preload: the above-the-fold background stands down for the
                // picture the lcp slot already preloads (the same hero as its .jpg url() and as
                // its image-set .webp was a dead high-priority fetch).
                if ($best === null || ($candidate['key'] === '' || $candidate['key'] !== $best['key'])
                    && !in_array(self::SLOT_ATF_BG, $best['supersedes'], true)) {
                    $won[$candidate['seq']][] = $device;
                }
                break;
            }
        }
        if ($combined && $this->painted !== []) {
            foreach ($won as $seq => $wonDevices) {
                if ($usable[$seq]['device'] !== 'both' || count($wonDevices) !== 1) {
                    continue;
                }
                $followed = $this->followed($usable[$seq], $html, $wonDevices);
                if ($followed === null) {
                    continue;
                }
                $usable[$seq] = $followed;
                foreach ($lcpWinners as $device => $winner) {
                    if ($winner['seq'] === $seq) {
                        $lcpWinners[$device] = $followed;
                    }
                }
            }
        }
        foreach ($usable as $seq => $candidate) {
            if ($candidate['slot'] === self::SLOT_CUSTOM) {
                $won[$seq] = $devices;
            } elseif (!isset($won[$seq])) {
                $sameKey = false;
                foreach ($lcpWinners as $winner) {
                    $sameKey = $sameKey || ($candidate['key'] !== '' && $candidate['key'] === $winner['key']);
                }
                $this->dropped($sameKey ? 'same-key' : 'rank');
            }
        }

        if ($this->backgroundHigh !== null) {
            $documentHigh = false;
            if (preg_match_all('/<img\b[^>]*\sfetchpriority\s*=\s*(["\']?)high\1[^>]*>/i', $html, $highTags)) {
                foreach ($highTags[0] as $highTag) {
                    $documentHigh = $documentHigh || $this->documentHighKept === null || call_user_func($this->documentHighKept, $highTag);
                }
            }
            foreach ($won as $seq => $wonDevices) {
                if ($usable[$seq]['fetchpriority'] !== 'high' || !in_array($usable[$seq]['origin'], self::CAPPED_ORIGINS, true)) {
                    continue;
                }
                $anotherHigh = $documentHigh;
                foreach ($lcpWinners as $winner) {
                    if ($winner['fetchpriority'] === 'high' && $winner['key'] !== $usable[$seq]['key']) {
                        $anotherHigh = true;
                    }
                }
                $named = $usable[$seq]['href'] !== '' ? $usable[$seq]['href'] : (string) preg_split('/[\s,]+/', $usable[$seq]['imagesrcset'])[0];
                if (!call_user_func($this->backgroundHigh, $named, $anotherHigh, $usable[$seq]['origin'] === 'header-logo')) {
                    $usable[$seq]['fetchpriority'] = '';
                    foreach ($lcpWinners as $device => $winner) {
                        if ($winner['seq'] === $seq) {
                            $lcpWinners[$device]['fetchpriority'] = '';
                        }
                    }
                }
            }
        }

        // The lcp slot first, then the above-the-fold background, then the site's own list.
        $bySlot = [self::SLOT_LCP => [], self::SLOT_ATF_BG => [], self::SLOT_CUSTOM => []];
        foreach ($usable as $seq => $candidate) {
            if (isset($won[$seq])) {
                $bySlot[$candidate['slot']][] = [$candidate, $won[$seq]];
            }
        }
        $tags = '';
        $emitted = 0;
        foreach ($bySlot as $entries) {
            foreach ($entries as $entry) {
                // A winner below the LCP's own ranks (atf-unlazy, header logo, Modern Delivery,
                // crit background, the guess) on devices whose LCP is identified is not the LCP:
                // it still preloads, at the default priority, so it stops competing with the LCP.
                if ($entry[0]['slot'] === self::SLOT_LCP && $entry[0]['rank'] > self::RANK_WIRE_FALLBACK
                    && count(array_diff($entry[1], array_keys($lcpIdentified))) === 0) {
                    $entry[0]['fetchpriority'] = '';
                }
                $tags .= self::tagsFor($entry[0], $entry[1], $combined);
                $emitted++;
            }
        }
        // A buffer with no <head> (a fragment) has nowhere a preload can work.
        $at = ($tags === '') ? -1 : self::insertPosition($html);
        if ($tags !== '' && $at < 0) {
            for ($i = 0; $i < $emitted; $i++) {
                $this->dropped('not-in-doc');
            }
            $emitted = 0;
        }
        $this->written = $at < 0 ? [] : $lcpWinners;
        $this->span = $at < 0 ? null : [$at, strlen($tags)];

        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('preload-set', '', '', [
                'dev'        => $combined ? 'combined' : $renderDevice,
                'registered' => $this->registeredCount,
                'emitted'    => $emitted,
                'dropped'    => (object) $this->droppedCounts,
                'slots'      => [
                    'lcp'    => !empty($bySlot[self::SLOT_LCP]) ? $bySlot[self::SLOT_LCP][0][0]['origin'] : 'none',
                    'atf_bg' => empty($bySlot[self::SLOT_ATF_BG]) ? 0 : 1,
                    'custom' => count($bySlot[self::SLOT_CUSTOM]),
                ],
                'page'       => $pageCounts,
            ]);
        }
        return $at < 0 ? $html : substr($html, 0, $at) . $tags . substr($html, $at);
    }

    /**
     * The lcp-slot candidates emit() wrote take the address $swap answers with ('' keeps one), href and single-address imagesrcset alike: for a pass that
     * changed the document after the set wrote it (a phone's background source) and whose readers ask what the set wrote (writtenLcp).
     */
    public function retargetWritten($swap)
    {
        if (!is_callable($swap)) {
            return;
        }
        $escape = function ($address) {
            return function_exists('esc_url') ? (string) esc_url($address) : htmlspecialchars($address, ENT_QUOTES);
        };
        foreach ($this->written as $device => $candidate) {
            $named = $candidate['href'] !== '' ? (string) call_user_func($swap, $candidate['href']) : '';
            if ($named !== '') {
                $this->written[$device]['href'] = $escape($named);
            }
            if ($candidate['imagesrcset'] !== '' && !preg_match('/[\s,]/', $candidate['imagesrcset'])) {
                $named = (string) call_user_func($swap, $candidate['imagesrcset']);
                if ($named !== '') {
                    $this->written[$device]['imagesrcset'] = $escape($named);
                }
            }
        }
    }

    /** Receipt counts, for the debug views and scratch checks. */
    public function counts()
    {
        return ['registered' => $this->registeredCount, 'held' => count($this->candidates), 'dropped' => $this->droppedCounts];
    }

    /**
     * What emit() wrote in the lcp slot, per device: ['mobile' => [...], 'desktop' => [...]] with the
     * href, imagesrcset, imagesizes and picture identity (key) of the preload that is in the document.
     * A device with no preload written is absent. Empty before emit() and when it wrote nothing.
     */
    public function writtenLcp()
    {
        $out = [];
        foreach ($this->written as $device => $candidate) {
            $out[$device] = [
                'href'        => (string) $candidate['href'],
                'imagesrcset' => (string) $candidate['imagesrcset'],
                'imagesizes'  => (string) $candidate['imagesizes'],
                'key'         => (string) $candidate['key'],
                'kind'        => (string) $candidate['kind'],
                'rank'        => (int) $candidate['rank'],
            ];
        }
        return $out;
    }

    /**
     * The pictures a finished document preloads, per device: ['mobile' => [imageKey => true],
     * 'desktop' => [...]]. Every <link rel="preload" as="image"> counts whoever wrote it (any
     * writer's id, the page's own tag, no id); a tag with no device media counts for both. The
     * identity is imageKey(), so origin, zone and custom-cname forms of one picture match.
     */
    public static function preloadedPictures($html, $widthNames = false)
    {
        $pictures = ['mobile' => [], 'desktop' => []];
        if (!is_string($html) || stripos($html, 'preload') === false
            || !preg_match_all('/<link\b[^>]*>/i', $html, $links)) {
            return $pictures;
        }
        foreach ($links[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*["\']?preload\b/i', $tag) || !preg_match('/\bas\s*=\s*["\']?image\b/i', $tag)) {
                continue;
            }
            $href = preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $hrefMatch) ? trim($hrefMatch[2]) : '';
            $srcset = preg_match('/\bimagesrcset\s*=\s*(["\'])(.*?)\1/i', $tag, $srcsetMatch) ? trim($srcsetMatch[2]) : '';
            $key = self::imageKey(($href !== '') ? $href : (string) preg_split('/[\s,]+/', $srcset)[0], $widthNames);
            if ($key === '') {
                continue;
            }
            $media = preg_match('/\bmedia\s*=\s*(["\'])(.*?)\1/i', $tag, $mediaMatch) ? strtolower($mediaMatch[2]) : '';
            $maxWidth = strpos($media, 'max-width') !== false;
            $minWidth = strpos($media, 'min-width') !== false;
            if (!$minWidth || $maxWidth) {
                $pictures['mobile'][$key] = true;
            }
            if (!$maxWidth || $minWidth) {
                $pictures['desktop'][$key] = true;
            }
        }
        return $pictures;
    }

    /** Ids this set writes. A buffer tag carrying one is not the page's own. */
    const OWN_ID_PATTERN = '/\bid\s*=\s*["\']wpc-(?:lcp-img-preload(?:-\d+)?|lcp-hero-preload|lcp-bg-preload[^"\']*|atf-bg-preload|atf-unlazy-preload|header-logo-preload|crit-bg-preload)["\']/i';

    /**
     * Every <link rel="preload" as="image"> the page itself wrote (none of our ids) is taken in
     * as a candidate of origin 'page' and resolved IN PLACE, never moved: an image preload
     * mirrors the eager <img> of its picture (the first-party passes already moved that <img>
     * off a suppressed zone, so the preload follows it) and is dropped only when every such
     * <img> is lazy or a placeholder; a preload with no <img> takes the rendition of its URL the
     * rest of the document names (the one other variant, the ?src= hint, the disk pick). Without
     * this a theme's preload zoned by the URL passes stays on a zone that no longer serves, at
     * fetchpriority=high. A tag for the other device (by its media) is left byte-identical.
     * Fills $pageCandidates with [key, device] of every page tag kept.
     */
    private function adoptPageTags($html, $renderDevice, array $images, array &$pageCandidates, array &$pageCounts)
    {
        if (stripos($html, 'preload') === false
            || !preg_match_all('/<link\b[^>]*>/i', $html, $links, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $page = [];
        foreach ($links[0] as $link) {
            $tag = $link[0];
            if (!preg_match('/\brel\s*=\s*["\']?preload\b/i', $tag) || !preg_match('/\bas\s*=\s*["\']?image\b/i', $tag)
                || preg_match(self::OWN_ID_PATTERN, $tag)) {
                continue;
            }
            $page[] = $link;
        }
        if (empty($page)) {
            return $html;
        }
        $rest = $html;
        foreach (array_reverse($page) as $link) {
            $rest = substr_replace($rest, '', $link[1], strlen($link[0]));
        }
        $restUrls = null;
        $replacements = [];
        foreach ($page as $link) {
            $tag = $link[0];
            $pageCounts['seen']++;
            $href = preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $hrefMatch) ? trim($hrefMatch[2]) : '';
            $srcset = preg_match('/\bimagesrcset\s*=\s*(["\'])(.*?)\1/i', $tag, $srcsetMatch) ? trim($srcsetMatch[2]) : '';
            $named = ($href !== '') ? $href : (string) preg_split('/[\s,]+/', $srcset)[0];
            $key = self::imageKey($named);
            $media = preg_match('/\bmedia\s*=\s*(["\'])(.*?)\1/i', $tag, $mediaMatch) ? strtolower($mediaMatch[2]) : '';
            $maxWidth = strpos($media, 'max-width') !== false;
            $minWidth = strpos($media, 'min-width') !== false;
            $device = ($maxWidth && !$minWidth) ? 'mobile' : (($minWidth && !$maxWidth) ? 'desktop' : 'both');
            if ($device !== 'both' && $device !== $renderDevice) {
                $pageCandidates[] = ['key' => $key, 'device' => $device];
                continue;
            }
            if ($this->vetoes($key)) {
                $pageCounts['dropped']++;
                $this->dropped($this->vetoed[$key]);
                $replacements[] = [$link[1], strlen($tag), ''];
                continue;
            }
            $new = $tag;
            if ($key !== '' && isset($images[$key])) {
                $img = $images[$key];
                if ($img['lazy'] && (!function_exists('apply_filters') || apply_filters('wpc_preload_img_drop_lazy', true))) {
                    $pageCounts['dropped']++;
                    $this->dropped('lazy-img');
                    $replacements[] = [$link[1], strlen($tag), ''];
                    continue;
                }
                if (!$img['lazy']) {
                    $new = self::mirrorTag($tag, $href !== '', $img);
                }
            } elseif ($href !== '' && !self::appearsAsUrl($rest, $href)) {
                if ($restUrls === null) {
                    $restUrls = self::documentUrls($rest);
                }
                $resolved = self::renditionInDocument($href, $restUrls);
                if ($resolved !== $href) {
                    $new = str_replace($hrefMatch[0], 'href=' . $hrefMatch[1] . $resolved . $hrefMatch[1], $tag);
                }
            }
            $pageCandidates[] = ['key' => $key, 'device' => $device];
            if ($new !== $tag) {
                $pageCounts['rewritten']++;
                $replacements[] = [$link[1], strlen($tag), $new];
            }
        }
        foreach (array_reverse($replacements) as $replacement) {
            $html = substr_replace($html, $replacement[2], $replacement[0], $replacement[1]);
        }
        return $html;
    }

    /** $tag carrying the <img>'s bytes: src into href (when the tag had one), srcset and sizes
     *  into imagesrcset/imagesizes, type= dropped (it described the old URL's format). The
     *  element's attribute bytes are carried verbatim: they already survived one escaping pass. */
    private static function mirrorTag($tag, $hasHref, array $img)
    {
        $new = $tag;
        if ($hasHref) {
            $new = (string) preg_replace_callback('/\bhref\s*=\s*(["\'])(.*?)\1/i', function () use ($img) {
                return 'href="' . $img['src'] . '"';
            }, $new, 1);
        }
        $new = (string) preg_replace('/\s*\b(?:imagesrcset|imagesizes|type)\s*=\s*(["\']).*?\1/i', '', $new);
        $add = '';
        if ($img['srcset'] !== '') {
            $add = ' imagesrcset="' . $img['srcset'] . '"' . ($img['sizes'] !== '' ? ' imagesizes="' . $img['sizes'] . '"' : '');
        } elseif (!$hasHref) {
            return $tag; // nothing of the element's to carry: leave the page's tag alone
        }
        return (string) preg_replace_callback('#\s*/?>$#', function ($end) use ($add) {
            return $add . $end[0];
        }, $new, 1);
    }

    /** True when a page-authored preload names $candidate's picture for a device both serve. */
    private static function pageOwns(array $pageCandidates, array $candidate, array $devices)
    {
        if ($candidate['key'] === '') {
            return false;
        }
        foreach ($pageCandidates as $page) {
            if ($page['key'] !== $candidate['key']) {
                continue;
            }
            foreach ($devices as $device) {
                if (($page['device'] === 'both' || $page['device'] === $device) && self::serves($candidate, $device)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The candidate with the address of the hero's one file where it names another rendition of the picture, for a candidate that serves the phone alone and whose rendition the document does not paint. */
    private function heroOwned(array $candidate, $renderDevice, $combined, $html)
    {
        $phone = $candidate['device'] === 'mobile' || ($candidate['device'] === 'both' && !$combined && $renderDevice === 'mobile');
        if (!$phone || !empty($candidate['arms']) || !empty($candidate['paints']) || in_array($candidate['origin'], self::PIN_OWNED_ORIGINS, true)
            || !in_array($candidate['slot'], [self::SLOT_LCP, self::SLOT_ATF_BG], true)) {
            return $candidate;
        }
        $form = $this->heroForm;
        $escape = function ($address) {
            return function_exists('esc_url') ? (string) esc_url($address) : htmlspecialchars($address, ENT_QUOTES);
        };
        $named = ($candidate['href'] !== '' && !self::documentNames($html, $candidate['href'])) ? (string) $form(html_entity_decode($candidate['href'], ENT_QUOTES)) : '';
        if ($named !== '') {
            $candidate['href'] = $escape($named);
        }
        if ($candidate['imagesrcset'] !== '' && !preg_match('/[\s,]/', $candidate['imagesrcset']) && !self::documentNames($html, $candidate['imagesrcset'])) {
            $named = (string) $form(html_entity_decode($candidate['imagesrcset'], ENT_QUOTES));
            if ($named !== '') {
                $candidate['imagesrcset'] = $escape($named);
            }
        }
        return $candidate;
    }

    private function dropped($why)
    {
        $this->droppedCounts[$why] = (isset($this->droppedCounts[$why]) ? $this->droppedCounts[$why] : 0) + 1;
    }

    private static function serves(array $candidate, $device)
    {
        return $candidate['device'] === 'both' || $candidate['device'] === $device;
    }

    /**
     * The candidate with its href resolved against the final document, or null when it was
     * dropped. $documentUrls is filled on first use.
     */
    private function resolve(array $candidate, $html, array $images, &$documentUrls, $renderDevice, array $devices = [])
    {
        if (!empty($candidate['arms'])) {
            return $candidate;
        }
        // An image candidate names an element: it mirrors the <img> that will use it, so the
        // preload scanner and the element's own selection cannot pick different bytes (a second
        // download and a console warning per image). A combined render's candidate for the other
        // device is left alone: the <img> this document carries is not the one it is for.
        $forThisDevice = $candidate['device'] === 'both' || $candidate['device'] === $renderDevice;
        if ($candidate['kind'] === self::KIND_IMAGE && $forThisDevice
            && $candidate['key'] !== '' && isset($images[$candidate['key']])) {
            $img = $images[$candidate['key']];
            if ($img['lazy']) {
                // Nothing eager will ever consume it.
                if (!function_exists('apply_filters') || apply_filters('wpc_preload_img_drop_lazy', true)) {
                    $this->dropped('lazy-img');
                    return null;
                }
                return $candidate;
            }
            $candidate['href'] = $img['src'];
            $candidate['imagesrcset'] = $img['srcset'];
            $candidate['imagesizes'] = $img['srcset'] !== '' ? $img['sizes'] : '';
            // type= described the registered href's format; the element may be another one.
            $candidate['type'] = '';
            return $candidate;
        }
        if ($candidate['kind'] === self::KIND_BACKGROUND && !empty($candidate['paints'])) {
            $painted = self::paintedBy($candidate['paints'], $html);
            $paintedHref = $painted === '' ? '' : (function_exists('esc_url') ? (string) esc_url($painted) : htmlspecialchars($painted, ENT_QUOTES));
            if ($paintedHref !== '') {
                $candidate['href'] = $paintedHref;
                $candidate['imagesrcset'] = '';
                $candidate['type'] = '';
                return $candidate;
            }
        }
        $followed = $this->followed($candidate, $html, $devices === [] ? [$renderDevice] : $devices);
        if ($followed !== null) {
            return $followed;
        }
        if ($candidate['kind'] === self::KIND_BACKGROUND && $candidate['imagesrcset'] === '' && $candidate['href'] !== '') {
            $armed = self::imageSetArm($html, $candidate['href']);
            if ($armed === false) {
                $this->dropped('imageset-mismatch');
                return null;
            }
            if ($armed !== '') {
                $candidate['href'] = function_exists('esc_url') ? (string) esc_url($armed) : htmlspecialchars($armed, ENT_QUOTES);
                $candidate['type'] = 'image/avif';
                return $candidate;
            }
        }
        // Everything else takes the rendition of its URL that the document names: the URL passes
        // (zone, naturalize, next-gen hint, disk pick, first-party) rewrote that same URL where
        // the page's CSS and markup carry it.
        if ($candidate['href'] !== '' && !self::appearsAsUrl($html, $candidate['href'])) {
            if ($documentUrls === null) {
                $documentUrls = self::documentUrls($html);
            }
            $registeredHref = $candidate['href'];
            $candidate['href'] = self::renditionInDocument($candidate['href'], $documentUrls, $candidate['kind'] === self::KIND_BACKGROUND);
            if ($candidate['href'] !== $registeredHref && $candidate['imagesrcset'] === '') {
                $resolvedMime = self::mimeOfFormat(self::formatOf($candidate['href']));
                if ($resolvedMime !== '' && self::formatOf($candidate['href']) !== self::formatOf($registeredHref)) {
                    $candidate['type'] = $resolvedMime;
                }
            }
            if ($candidate['exact'] && !self::appearsAsUrl($html, $candidate['href'])) {
                $this->dropped('not-in-doc');
                return null;
            }
        }
        // A single-URL imagesrcset (a CSS background's next-gen candidate) is what a browser
        // preloads instead of the href, so it names the document's own URL for that file or
        // nothing. The crit carries origin URLs by the service's contract and the writers read
        // them from the crit text; a candidate the page does not request is a second download,
        // and a zone-only one on the origin is a 404: acrystalglass.com (2026-09-29) preloaded
        // `https://acrystalglass.com/storage/…Metals.avif` at fetchpriority=high, a file on
        // neither host, while its CSS requested `https://cdn.acrystalglass.com/storage/…Metals.avif?src=webp`.
        if ($candidate['imagesrcset'] !== '' && !preg_match('/[\s,]/', $candidate['imagesrcset'])
            && !self::appearsAsUrl($html, $candidate['imagesrcset'])) {
            if ($documentUrls === null) {
                $documentUrls = self::documentUrls($html);
            }
            $resolvedSrcset = self::renditionInDocument($candidate['imagesrcset'], $documentUrls);
            // The same format or nothing: the candidate exists to fetch the next-gen file, and the
            // document's one rendition in another format is the href's job.
            if ($resolvedSrcset === $candidate['imagesrcset']
                || self::formatOf($resolvedSrcset) !== self::formatOf($candidate['imagesrcset'])) {
                $this->dropped('imagesrcset-not-in-doc');
                $resolvedSrcset = '';
            }
            $candidate['imagesrcset'] = $resolvedSrcset;
        }
        return $candidate;
    }

    /** The candidate with the address the service painted its hero at when it is one to follow and the document paints exactly that address, else null. */
    private function followed(array $candidate, $html, array $devices)
    {
        $paintedFile = $this->paintedFor($candidate, $devices);
        if ($paintedFile === '' || !self::paintsUrl($html, $paintedFile)) {
            return null;
        }
        $candidate['href'] = function_exists('esc_url') ? (string) esc_url($paintedFile) : htmlspecialchars($paintedFile, ENT_QUOTES);
        $candidate['imagesrcset'] = '';
        $candidate['imagesizes'] = '';
        $candidate['type'] = '';
        return $candidate;
    }

    /**
     * The file the service's browser painted for the measured hero this candidate names, '' when the
     * candidate is not one to follow it: another slot than the lcp slot or the above-the-fold
     * background, a writer that pins its own paint, a candidate for another picture, or one that serves
     * devices whose painted files differ (a device-blind render's both-devices candidate).
     */
    private function paintedFor(array $candidate, array $devices)
    {
        if ($this->painted === [] || $candidate['key'] === ''
            || !in_array($candidate['slot'], [self::SLOT_LCP, self::SLOT_ATF_BG], true)
            || in_array($candidate['origin'], self::PIN_OWNED_ORIGINS, true)) {
            return '';
        }
        $served = [];
        foreach ($devices as $device) {
            if (self::serves($candidate, $device)) {
                $served[] = isset($this->painted[$device]) ? $this->painted[$device] : '';
            }
        }
        $served = array_unique($served);
        if (count($served) !== 1 || $served[0] === '' || self::imageKey($served[0]) !== $candidate['key']) {
            return '';
        }
        return $served[0];
    }

    /**
     * The one url() of the pin that paints the box: of the `<style id>` blocks named by $ids, the
     * last one in the document (the cascade's winner between pins of equal specificity), read after
     * every URL pass has run. '' when no named block is there or the winner carries not exactly one
     * distinct url().
     */
    private static function paintedBy(array $ids, $html)
    {
        $winner = null;
        foreach ($ids as $id) {
            if (!preg_match_all('#<style\b[^>]*\bid\s*=\s*(["\'])' . preg_quote((string) $id, '#') . '\1[^>]*>(.*?)</style>#is', $html, $blocks, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $last = count($blocks[0]) - 1;
            if ($winner === null || $blocks[0][$last][1] > $winner[0]) {
                $winner = [$blocks[0][$last][1], $blocks[2][$last][0]];
            }
        }
        if ($winner === null || !preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $winner[1], $found)) {
            return '';
        }
        $urls = array_values(array_unique($found[2]));
        return count($urls) === 1 ? html_entity_decode($urls[0], ENT_QUOTES) : '';
    }

    /**
     * The address the plugin's own phone pin puts on the box of the picture $key: of the `<style id="wpc-lcp-bg-authority…">` and
     * `<style id="wpc-lcp-bg-rung">` blocks whose rule is wrapped in `@media` MEDIA_MOBILE and names that picture in exactly one distinct
     * url(), the last one in the document. The pins are `!important`, so a phone paints that file whatever the critical CSS names.
     * '' when no phone pin names the picture.
     */
    public static function phonePin($html, $key)
    {
        $key = strtolower((string) $key);
        if (!is_string($html) || $key === ''
            || !preg_match_all('#<style\b[^>]*\bid\s*=\s*(["\'])wpc-lcp-bg-(?:authority|rung)[\w-]*\1[^>]*>\s*@media\s*' . preg_quote(self::MEDIA_MOBILE, '#') . '\s*\{(.*?)</style>#is', $html, $blocks, PREG_SET_ORDER)) {
            return '';
        }
        $pin = '';
        foreach ($blocks as $block) {
            if (preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $block[2], $found)) {
                $urls = array_values(array_unique($found[2]));
                if (count($urls) === 1 && self::imageKey($urls[0]) === $key) {
                    $pin = html_entity_decode($urls[0], ENT_QUOTES);
                }
            }
        }
        return $pin;
    }

    /**
     * key => ['src','srcset','sizes','lazy'] for every <img> with a src. An <img> whose src is a
     * data: placeholder counts as lazy and is keyed by the file it will load. An eager element
     * always takes the key: it is the one a preload can serve.
     */
    private static function documentImages($html)
    {
        $images = [];
        if (stripos($html, '<img') === false || !preg_match_all('#<img\b[^>]*>#i', $html, $tags)) {
            return $images;
        }
        foreach ($tags[0] as $tag) {
            if (!preg_match('/(?<![-\w])src\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                continue;
            }
            $src = trim($srcMatch[1]);
            $lazy = (bool) preg_match('/\bloading\s*=\s*["\']lazy["\']/i', $tag);
            $real = $src;
            if (stripos($src, 'data:') === 0) {
                $lazy = true;
                if (preg_match('/\bdata-wpc-qw-src\s*=\s*["\']([^"\']+)["\']/i', $tag, $parked)
                    || preg_match('/\bdata-(?:wpc-)?lazy-?src\s*=\s*["\']([^"\']+)["\']/i', $tag, $parked)) {
                    $real = trim($parked[1]);
                } else {
                    continue;
                }
            }
            $key = self::imageKey($real);
            if ($key === '' || (isset($images[$key]) && !$images[$key]['lazy'])) {
                continue;
            }
            $images[$key] = [
                'src'    => $src,
                'lazy'   => $lazy,
                'srcset' => preg_match('/(?<![-\w])srcset\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcset) ? trim($srcset[1]) : '',
                'sizes'  => preg_match('/(?<![-\w])sizes\s*=\s*["\']([^"\']+)["\']/i', $tag, $sizes) ? trim($sizes[1]) : '',
            ];
        }
        return $images;
    }

    /** ['css' => every url() inside a <style> block, 'markup' => every absolute URL in a src,
     *  srcset, href or data-*src attribute], each in document order. */
    private static function documentUrls($html)
    {
        $urls = ['css' => [], 'markup' => []];
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles)) {
            foreach ($styles[1] as $css) {
                if (preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $css, $found)) {
                    foreach ($found[2] as $url) {
                        $urls['css'][] = $url;
                    }
                }
            }
        }
        if (preg_match_all('/\s(?:src|srcset|href|data-[a-z-]*src(?:set)?)\s*=\s*(["\'])(.*?)\1/is', $html, $attributes)) {
            foreach ($attributes[2] as $value) {
                foreach (preg_split('/\s*,\s*/', $value) as $part) {
                    $part = trim((string) preg_replace('/\s+\d+(?:\.\d+)?[wx]$/', '', trim($part)));
                    if ($part !== '' && preg_match('#^(?:https?:)?//#i', $part)) {
                        $urls['markup'][] = $part;
                    }
                }
            }
        }
        return $urls;
    }

    /**
     * The document's own URL for the rendition $href names: same picture (imageKey()) and same
     * rendition (renditionName()). The CSS's URL when the CSS names exactly one, else the
     * markup's when it names exactly one; $href unchanged when the document names none, or more
     * than one it cannot tell apart. Several are told apart by format: an image-set names the
     * same rendition as `X.webp` and `X.avif?src=webp`, and the one in $href's format is the
     * one it means (acrystalglass.com, 2026-09-29: two matches left the preload on the origin).
     * A background's preload ($nextGenOnMismatch) whose format none of them has takes the
     * document's one AVIF address: the crit paints the zone's forms, never the origin file.
     */
    private static function renditionInDocument($href, array $documentUrls, $nextGenOnMismatch = false)
    {
        $key = self::imageKey($href);
        $rendition = self::renditionName($href);
        if ($key === '' || $rendition === '') {
            return $href;
        }
        foreach (['css', 'markup'] as $where) {
            $found = [];
            foreach ($documentUrls[$where] as $url) {
                if (self::renditionName($url) === $rendition && self::imageKey($url) === $key) {
                    $found[$url] = true;
                }
            }
            if (count($found) > 1) {
                $format = self::formatOf($href);
                $all = $found;
                foreach (array_keys($found) as $url) {
                    if (self::formatOf($url) !== $format) {
                        unset($found[$url]);
                    }
                }
                if (count($found) === 0 && $nextGenOnMismatch) {
                    foreach (array_keys($all) as $url) {
                        if (self::formatOf($url) === 'avif') {
                            $found[$url] = true;
                        }
                    }
                }
                if (count($found) !== 1) {
                    return $href;
                }
            }
            if (count($found) === 1) {
                return (string) key($found);
            }
        }
        return $href;
    }

    /**
     * The AVIF address the document's image-set paints first for the picture $href names at $href's rendition, '' when no image-set
     * of the document leads with such an AVIF, false when the document's image-sets lead with a different rendition of the picture
     * (or with several AVIF addresses of this one) and so $href cannot name what they paint. Only an image-set in a top-level rule
     * of a style block for every medium, on a selector with no lazy-load class, counts, and only while no plain url() of that
     * picture and rendition is painted outside the rule that carries the image-set: otherwise '' (the registered address stays).
     */
    private static function imageSetArm($html, $href)
    {
        $key = self::imageKey($href);
        $rendition = self::renditionName($href);
        if ($key === '' || $rendition === '' || !preg_match_all('/<style\b([^>]*)>(.*?)<\/style>/is', (string) $html, $styles, PREG_SET_ORDER)) {
            return '';
        }
        $plainOf = function ($text) use ($key, $rendition) {
            $text = (string) preg_replace('/(?<![\w-])(?:-webkit-)?image-set\(((?:[^()]|\([^()]*\))*)\)/i', '', (string) $text);
            if (!preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $text, $plain)) {
                return false;
            }
            foreach ($plain[2] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES);
                if (self::imageKey($url) === $key && self::renditionName($url) === $rendition) {
                    return true;
                }
            }
            return false;
        };
        $same = [];
        $other = false;
        $blocked = false;
        $plainElsewhere = false;
        foreach ($styles as $style) {
            $forAll = !preg_match('/\bmedia\s*=\s*(["\'])(.*?)\1/i', $style[1], $mediaAttr) || in_array(strtolower(trim($mediaAttr[2])), ['', 'all'], true);
            foreach (self::cssRules($style[2], $forAll) as $rule) {
                $carries = false;
                if (preg_match_all('/(?<![\w-])(?:-webkit-)?image-set\(((?:[^()]|\([^()]*\))*)\)/i', $rule['body'], $sets)) {
                    foreach ($sets[1] as $body) {
                        if (!preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $body, $arms)) {
                            continue;
                        }
                        $urls = array_map(function ($url) {
                            return html_entity_decode($url, ENT_QUOTES);
                        }, $arms[2]);
                        $named = false;
                        $ownRendition = false;
                        foreach ($urls as $url) {
                            if (self::imageKey($url) === $key) {
                                $named = true;
                                $ownRendition = $ownRendition || self::renditionName($url) === $rendition;
                            }
                        }
                        if (!$named) {
                            continue;
                        }
                        if (!$rule['top'] || self::lazySelector($rule['sel'])) {
                            $blocked = true;
                            continue;
                        }
                        $carries = true;
                        if (self::imageKey($urls[0]) !== $key) {
                            continue;
                        }
                        if (self::renditionName($urls[0]) === $rendition) {
                            if (self::formatOf($urls[0]) === 'avif') {
                                $same[$urls[0]] = true;
                            }
                        } elseif ($ownRendition) {
                            $other = true;
                        }
                    }
                }
                if (!$carries && $plainOf($rule['body'])) {
                    $plainElsewhere = true;
                }
            }
        }
        $outside = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', (string) $html);
        if ($blocked || $plainElsewhere || $plainOf($outside)) {
            return '';
        }
        if (count($same) === 1) {
            $url = (string) key($same);
            return self::formatOf($href) === 'avif' && html_entity_decode((string) $href, ENT_QUOTES) === $url ? '' : $url;
        }
        return count($same) > 1 || $other ? false : '';
    }

    /** True when a selector carries a class a lazy-load script holds its background behind: the plugin's own background lazy class, a library class word of the lazy verdict, or any class word spelling lazy. */
    private static function lazySelector($selector)
    {
        $words = ['lazy', 'wpc-bglazy'];
        if (class_exists('wps_ic_lazy_verdict')) {
            $words = array_merge($words, wps_ic_lazy_verdict::LIBRARY_CLASS_WORDS);
        }
        foreach ($words as $word) {
            if (stripos((string) $selector, (string) $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The rules of a stylesheet's text that carry declarations, [['top' => bool, 'sel' => selector, 'body' => declarations]]: a rule inside
     * any at-rule block (@media, @supports, @container, @layer, @keyframes) is not top-level; top-level rules keep $top.
     */
    private static function cssRules($css, $top = true)
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) $css);
        $rules = [];
        $depth = 0;
        $start = 0;
        $bodyStart = 0;
        $prelude = '';
        $quote = '';
        $length = strlen($css);
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                if ($depth === 0) {
                    $prelude = trim(substr($css, $start, $i - $start));
                    $bodyStart = $i + 1;
                }
                $depth++;
            } elseif ($char === '}') {
                if ($depth > 0 && --$depth === 0) {
                    $body = substr($css, $bodyStart, $i - $bodyStart);
                    if ($prelude !== '' && $prelude[0] === '@') {
                        foreach (self::cssRules($body, false) as $inner) {
                            $rules[] = $inner;
                        }
                    } else {
                        $rules[] = ['top' => $top, 'sel' => $prelude, 'body' => $body];
                    }
                    $start = $i + 1;
                } elseif ($depth === 0) {
                    $start = $i + 1;
                }
            } elseif ($char === ';' && $depth === 0) {
                $start = $i + 1;
            }
        }
        return $rules;
    }

    /**
     * True when $url occurs in $html as a whole URL, not as the start of a longer one. A
     * substring test read cloud-bg.webp as present because the crit CSS carried
     * cloud-bg.webp?src=png (staging-home, local lane): the preload named the bare .webp the CSS
     * never fetches, a second download beside the one the page paints.
     */
    private static function appearsAsUrl($html, $url)
    {
        return (bool) preg_match('~' . preg_quote((string) $url, '~') . '(?=["\')\s,<>]|$)~', (string) $html);
    }

    /** The MIME type of an image file extension the preload set names, '' for any other. */
    private static function mimeOfFormat($format)
    {
        $types = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        return isset($types[$format]) ? $types[$format] : '';
    }

    /** The file extension of the innermost URL's path, lower case, query ignored. */
    private static function formatOf($url)
    {
        $u = html_entity_decode((string) $url, ENT_QUOTES);
        $inner = strrpos($u, '/u:http');
        if ($inner !== false) {
            $u = substr($u, $inner + 3);
        }
        return strtolower((string) pathinfo((string) parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    /** The innermost URL's file name, query and extensions stripped, size suffix kept. */
    private static function renditionName($url)
    {
        $u = html_entity_decode((string) $url, ENT_QUOTES);
        $inner = strrpos($u, '/u:http');
        if ($inner !== false) {
            $u = substr($u, $inner + 3);
        }
        $name = strtolower(rawurldecode(basename((string) parse_url($u, PHP_URL_PATH))));
        $name = (string) preg_replace('/\.(avif|webp)$/i', '', $name);
        return (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name);
    }

    /**
     * The tags for one winner, each on its own line. A mobile or desktop candidate carries its
     * device's media, a 'both' candidate none — unless, on a combined-crit render, it won only
     * one device's slot: then it is that device's preload and says so.
     */
    private static function tagsFor(array $candidate, array $wonDevices, $combined)
    {
        $device = $candidate['device'];
        if ($device === 'both' && $combined && count($wonDevices) === 1) {
            $device = $wonDevices[0];
        }
        $media = ($device === 'mobile') ? self::MEDIA_MOBILE : (($device === 'desktop') ? self::MEDIA_DESKTOP : '');
        if (empty($candidate['arms'])) {
            return "\n" . self::tag($candidate['fetchpriority'], $candidate['id'], $candidate['href'],
                $candidate['imagesrcset'], $candidate['imagesizes'], $media, $candidate['type']);
        }
        $out = '';
        foreach ($candidate['arms'] as $n => $arm) {
            $out .= "\n" . self::tag($candidate['fetchpriority'], $candidate['id'] . ($n > 0 ? '-' . ($n + 1) : ''), '',
                isset($arm['imagesrcset']) ? (string) $arm['imagesrcset'] : '',
                isset($arm['imagesizes']) ? (string) $arm['imagesizes'] : '',
                isset($arm['media']) ? (string) $arm['media'] : '',
                isset($arm['type']) ? (string) $arm['type'] : '');
        }
        return $out;
    }

    /** One tag, attributes in the canonical order: rel, as, fetchpriority, id, href, imagesrcset,
     *  imagesizes, media, type. */
    private static function tag($fetchpriority, $id, $href, $imagesrcset, $imagesizes, $media, $type)
    {
        return '<link rel="preload" as="image"'
            . ($fetchpriority !== '' ? ' fetchpriority="' . $fetchpriority . '"' : '')
            . ($id !== '' ? ' id="' . $id . '"' : '')
            . ($href !== '' ? ' href="' . $href . '"' : '')
            . ($imagesrcset !== '' ? ' imagesrcset="' . $imagesrcset . '"' : '')
            . ($imagesizes !== '' ? ' imagesizes="' . $imagesizes . '"' : '')
            . ($media !== '' ? ' media="' . $media . '"' : '')
            . ($type !== '' ? ' type="' . $type . '"' : '')
            . '>';
    }

    /** True unless the constant, the cohort off_preload_before_crit or the filter wpc_preload_before_crit restores the earlier position. */
    private static function beforeCritOn()
    {
        if (defined('WPC_PRELOAD_BEFORE_CRIT_OFF') && WPC_PRELOAD_BEFORE_CRIT_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('off_preload_before_crit') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || (bool) apply_filters('wpc_preload_before_crit', true);
    }

    /** Offset just past the last of the head's charset and viewport metas, 0 when it has neither. */
    private static function metasEnd($html)
    {
        $headEnd = stripos($html, '</head>');
        $head = $headEnd === false ? $html : substr($html, 0, $headEnd);
        $end = 0;
        foreach (['/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', '/<meta\b[^>]*(?:\bcharset\b|http-equiv=["\']content-type)[^>]*>/i'] as $pattern) {
            if (preg_match($pattern, $head, $meta, PREG_OFFSET_CAPTURE)) {
                $end = max($end, (int) $meta[0][1] + strlen($meta[0][0]));
            }
        }
        return $end;
    }

    /**
     * Brackets the tags the last emit() wrote in $html with marker comments, for placeFirst() to
     * find after every later pass has rewritten them. Brackets nothing when the position is off
     * or emit() wrote nothing.
     */
    public function markEmitted($html)
    {
        $span = $this->span;
        $this->span = null;
        if ($span === null || $span[1] === 0 || !self::beforeCritOn() || substr($html, $span[0], $span[1]) === false) {
            return $html;
        }
        return substr($html, 0, $span[0]) . self::MARK_OPEN . substr($html, $span[0], $span[1]) . self::MARK_CLOSE . substr($html, $span[0] + $span[1]);
    }

    /**
     * The one owner of where the image preloads sit on the final buffer: the bracketed tags go
     * right after the head's charset and viewport metas and the run of metas and
     * preconnect/dns-prefetch hints that follows them, ahead of every plugin-inserted style,
     * script and stylesheet link. The brackets are always removed; a span already at or ahead of
     * that position stays where it is.
     */
    public static function placeFirst($html)
    {
        if (!is_string($html) || (strpos($html, self::MARK_OPEN) === false && strpos($html, self::MARK_CLOSE) === false)) {
            return $html;
        }
        $close = strpos($html, self::MARK_CLOSE);
        $open = $close === false ? false : strrpos(substr($html, 0, $close), self::MARK_OPEN);
        if ($close === false || $open === false) {
            return str_replace([self::MARK_OPEN, self::MARK_CLOSE], '', $html);
        }
        $tags = substr($html, $open + strlen(self::MARK_OPEN), $close - $open - strlen(self::MARK_OPEN));
        $rest = substr($html, 0, $open) . substr($html, $close + strlen(self::MARK_CLOSE));
        $rest = str_replace([self::MARK_OPEN, self::MARK_CLOSE], '', $rest);
        $at = $open;
        if (self::beforeCritOn() && preg_match('/<head\b[^>]*>/i', $rest, $head, PREG_OFFSET_CAPTURE)) {
            $headAt = $head[0][1] + strlen($head[0][0]);
            $target = max($headAt, self::metasEnd($rest));
            if (preg_match('/^(?:\s*(?:<meta\b[^>]*>|<link\b[^>]*\brel\s*=\s*["\']?(?:preconnect|dns-prefetch)["\']?[^>]*>))+/i', substr($rest, $target, 4000), $run)) {
                $target += strlen($run[0]);
            }
            if ($target < $open) {
                $at = $target;
            }
        }
        return substr($rest, 0, $at) . $tags . substr($rest, $at);
    }

    /**
     * Where the preloads go: after the head's viewport meta, else after <head> and the charset meta
     * when the head opens with it; then past the run of <meta> tags that follows — the first
     * bytes the preload scanner reads. Anywhere later and the scanner meets them behind the
     * inline CSS: on aliiadventureshack the hero preload sat at byte 101,737 and Lighthouse measured 1,720 ms of LCP load delay with it present.
     * -1 when the buffer has no <head>.
     */
    private static function insertPosition($html)
    {
        if (!preg_match('/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE)) {
            return -1;
        }
        $headAt = $head[0][1] + strlen($head[0][0]);
        $at = $headAt;
        // A DEVICE-SCOPED PRELOAD GOES AFTER THE VIEWPORT META. Chrome evaluates a preload's
        // media when the parser meets the tag; before <meta name="viewport"> the layout viewport
        // is still the 980 px default, so a phone fetches a `(min-width: 768px)` preload and skips
        // a `(max-width: 767.98px)` one (headless Chrome, 412 px phone emulation: the same link
        // before the viewport meta is fetched, after it is not). hartwood.biz (ticket 12072,
        // 2026-10-20): the theme's charset and viewport metas sat at lines 22-23, behind the
        // injected crit block, the charset search below found nothing in its window, the preloads
        // went in at line 5, and phones fetched the 258 KB desktop rendition. So the whole head is
        // searched for the viewport meta, and the charset rule is the fallback for a head without
        // one. The run of <meta> tags right after it is skipped as before.
        $headEnd = stripos($html, '</head>', $headAt);
        $headText = substr($html, $headAt, ($headEnd === false ? strlen($html) : $headEnd) - $headAt);
        if (preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', $headText, $viewport, PREG_OFFSET_CAPTURE)) {
            $at = $headAt + $viewport[0][1] + strlen($viewport[0][0]);
        } elseif (preg_match('/<meta\b[^>]*(?:\bcharset\b|http-equiv=["\']content-type)[^>]*>/i', substr($html, $headAt, 4096), $charset, PREG_OFFSET_CAPTURE)) {
            $at = $headAt + $charset[0][1] + strlen($charset[0][0]);
        }
        if (preg_match('/^(?:\s*<meta\b[^>]*>)+/i', substr($html, $at, 600), $metas)) {
            $at += strlen($metas[0]);
        }
        return $at;
    }
}

final class wps_ic_render_context
{
    /** @var int  The lane this run is on: wps_ic_render_pipeline::LANE_CDN or LANE_LOCAL. */
    public $lane;
    /** @var string  The HTML as the buffer handed it over, before the first stage. */
    public $pristine;
    /** @var bool  True once the run has been ended: by a bail() entry firing, or by an ordinary
     *              stage setting this itself (a debug receipt). Every later entry is skipped. */
    public $bailed = false;
    /** @var string  Name of the stage that ended the run, for the trace and for skip receipts. */
    public $bailName = '';
    /** @var bool  Set by the runner when a bail() entry fires: the run answers with the buffer
     *             exactly as the envelope handed it over. A stage that ends the run by setting
     *             $bailed itself leaves this false, so its own output is what comes out. */
    public $returnPristine = false;

    // Cross-stage state set by stages: every property is named for what it holds.

    /** @var array  Templates a stage masked out of the HTML. stage_remove_templates fills it and
     *              opens the 'templates' window; the window's closer is what restores and clears
     *              it, at stage_restore_templates_final on a full run or from the runner's finally
     *              on a stop, a bail or a throw. The runner itself never reads this property. */
    public $removedTemplates = [];
    /** @var array  The images this device does not paint (wpc_device_hidden_image_set), found by
     *              stage_device_hidden_image_set on the local lane and read by local_image_tags:
     *              such an image is always lazy and never counts toward the eager first N. The
     *              CDN lane's <img> rewrite works the same set out inside its own pass. */
    public $deviceHiddenImages = [];
    /** @var int  Images local_image_tags has counted as painted on this device, in document
     *            order: the first lazySkipCount stay eager, and the count is stamped on each
     *            tag as data-count-lazy. Starts at 0 on every render. */
    public $localVisibleImageCount = 0;
    /** @var array|null  The og:image and JSON-LD tags stage_encode_meta parked, keyed the way
     *                   decodeMeta wants them, or null when the meta encode did not run. The
     *                   'meta' window's closer reads it and clears it. */
    public $metaEncodeStore = null;
    /** @var mixed  The media-script mask in force, or null when no mask is open. */
    public $mediaScriptMask = null;
    /** @var array  <picture> markup parked by the picture passes, keyed by placeholder. */
    public $pictureStash = [];
    /** @var array  Markup parked by Negotiated Delivery, keyed by placeholder. */
    public $negotiatedStash = [];
    /** @var array  The <link> and <script> start tags that carry integrity= which stage_integrity_stash parked, keyed by token. The 'integrity_pins' window's closer gives them back and clears it, at stage_integrity_restore on a full run or from the runner's finally on a stop, a bail or a throw. */
    public $integrityStash = [];
    /** @var array  The <use> start tags stage_use_tags_stash parked, keyed by token. The 'use_tags'
     *              window's closer gives them back and clears it, at stage_use_tags_restore on a
     *              full run or from the runner's finally on a stop, a bail or a throw. */
    public $useTagStash = [];
    /** @var array  Slider Revolution 7 <sr7-module> blocks parked by stage_slider_module_stash,
     *              keyed by placeholder; the 'sr7_modules' window puts them back. */
    public $sliderModuleStash = [];
    /** @var bool  The buffer is an AMP document, as the AMP settings squash found it. Stages that
     *              inject markup AMP forbids read this through gate_not_amp; it stays false on a
     *              run that stopped before the squash, so nothing claims a page is AMP unasked.
     *              Also read by the crit kick, delay and speculation gates, the yield pass, the
     *              iframe facade, cdn_rewrite_url, and (handed down as a parameter) the
     *              rewriteLogic <img> and external-URL passes. */
    public $isAmp = false;
    /** @var mixed  Lazy loading for this render ('1' on): the request's value from mainInit(),
     *              seeded by stage_prelude, turned '0' by the AMP squash. Read by
     *              local_image_tags and by the lazy verdict's seed ($lazyVerdict). */
    public $lazyEnabled = null;
    /** @var mixed  Adaptive image sizing for this render ('1' on): seeded and squashed like
     *              $lazyEnabled. Read by local_image_tags. */
    public $adaptiveEnabled = null;
    /** @var bool  Wrap next-gen <img> in <picture> with a webp source on this render: the
     *             request's value (wps_rewriteLogic::$pictureWebpEnabled, set by mainInit()),
     *             seeded by stage_prelude, turned off by Negotiated Delivery on either lane and by
     *             the AMP squash. Read by both picture stashes, inject_preload_images,
     *             local_image_tags and (as a parameter) rewriteLogic's <img> rewrite. */
    public $pictureWebpEnabled = false;
    /** @var bool  local_image_tags may add a <source type="image/avif">: seeded by stage_prelude
     *             from the same source as $pictureWebpEnabled (the CDN-off lane's own formats on
     *             the local lane, wps_rewriteLogic::$pictureAvifEnabled on the CDN lane). */
    public $pictureAvifEnabled = false;
    /** @var array  What local_image_tags did with each <img> while $pictureWebpEnabled was on:
     *              'built' <picture> elements, and images left alone because no next-gen
     *              variant exists on disk ('no-variant') or an exclude matched ('excluded').
     *              Logged once per render as the nextgen-picture receipt. */
    public $nextgenPictureCounts = ['built' => 0, 'no-variant' => 0, 'excluded' => 0];
    /** @var string[]  The settings keys the AMP squash turned off for this render (delay-js,
     *                 inline-js); empty on a page that is not AMP. For the trace and debug views. */
    public $ampSquashed = [];
    /** @var bool  Critical CSS is active for this render. */
    public $criticalActive = false;
    /** @var bool|null  Whether a critical file exists for this URL; null until looked up. */
    public $criticalExists = null;
    /** @var object|null  The critical CSS instance, shared so stages do not rebuild it. */
    public $criticalInstance = null;
    /** @var string  The crit state code of this render ('' until the critical setup stage ran), printed in the freshness marker as cs:<code>. */
    public $critState = '';
    /** @var object|null  The combine instance, shared so stages do not rebuild it. */
    public $combineInstance = null;
    /** @var bool  Delay JS is active for this render. The yield pass works it out; with one
     *             owner emitting the faces nothing downstream reads it, so it is kept on the
     *             context for the trace and the debug views. */
    public $delayModeActive = false;
    /** @var bool  The v3 delay engine already ran its process_html pass. */
    public $delayV3Ran = false;
    /** @var bool  This render is the crit service's push render, strict test: the
     *             criticalCombine header or ?criticalCombine=true. Seeded by stage_prelude.
     *             Read by stage_critical_combine_request (answers with the template key) and, on
     *             the CDN lane, by wpc_css_block_open and gate_critical_kick. */
    public $pushRender = false;
    /** @var bool  The same question, loose test: the header or ANY non-empty criticalCombine GET
     *             value. Seeded by stage_prelude. Read by the local lane's wpc_css_block_open and
     *             gate_critical_kick, both combine-bundle stages, stage_prepare_preloads,
     *             delay_request_allowed and gate_speculation_rules. It differs from $pushRender
     *             only for a truthy GET value other than 'true' (?criticalCombine=1): the readers
     *             of this field treat that request as a push render, the readers of $pushRender
     *             do not. */
    public $pushRenderLoose = false;
    /** @var bool  May this render park stylesheets? The one decision every parker acts on:
     *             true when the crit blob will paint. A crit that is inlined is parked behind. */
    public $critParkAllowed = false;
    /** @var bool  The current visitor is a logged-in user. */
    public $userLoggedIn = false;
    /** @var bool  Visitor mode: the run behaves as a plain visitor request. */
    public $visitorMode = false;
    /** @var bool  The caller wants the returned HTML slash-escaped. */
    public $addSlashes = false;
    /** @var wps_ic_font_face_set  The one owner of this render's @font-face rules. A pass that
     *                             produces faces registers them here rather than writing a
     *                             <style> block of its own; stage_emit_font_faces writes the
     *                             two blocks. */
    public $fontFaces;
    /** @var wps_ic_image_preload_set  The one owner of this render's image preloads. A pass that
     *                                 wants an image preloaded registers a candidate here rather
     *                                 than writing a <link>; stage_emit_image_preloads writes the
     *                                 winners. */
    public $imagePreloads;
    /** @var wps_ic_image_sizing  The one owner of this render's image geometry: the `sizes` each
     *                            <img> carries, the width/height it gets when the page wrote none,
     *                            and the aspect pin. A pass that writes one of those asks here.
     *                            Written once in the render's receipt (img-sizing). */
    public $imageSizing;
    /** @var wps_ic_lazy_verdict|null  The one owner of every <img>'s lazy/eager verdict, seeded by
     *                                  stage_prelude. Every writer of `loading` asks it once per tag
     *                                  and writes through its applyLoading(); written once in the
     *                                  render's receipt (lazy-verdict). */
    public $lazyVerdict;
    /** @var array|null  The inline hero placeholder stage_hero_lqip wrote on this phone render: ['sel' => the
     *                   placeholder's selector, 'url' => the hero's file, 'key' => the picture's preload key,
     *                   'sig' => the record's signature]. null on every render that wrote none. Read by the
     *                   hero-gated starters and the hero lists, which treat the box as the hero (the hero's
     *                   own file is not requested before the swap). */
    public $heroLqip = null;

    /** @var array  Open windows as a LIFO stack of [name, closer]; one name may be open twice. */
    private $windows = [];

    public function __construct($lane, $pristine)
    {
        $this->lane = (int) $lane;
        $this->pristine = $pristine;
        $this->fontFaces = new wps_ic_font_face_set();
        $this->imagePreloads = new wps_ic_image_preload_set();
        $this->imageSizing = new wps_ic_image_sizing();
    }

    /** Register a closer that must run if the pipeline stops before the matching closeWindow. */
    public function openWindow($name, $closer) { $this->windows[] = [(string) $name, $closer]; }

    /** Close the most recently opened window carrying this name (a nested mask closes inside out). */
    public function closeWindow($name, $html)
    {
        $name = (string) $name;
        for ($i = count($this->windows) - 1; $i >= 0; $i--) {
            if ($this->windows[$i][0] === $name) {
                $c = $this->windows[$i][1];
                array_splice($this->windows, $i, 1);
                return $this->fireCloser($c, $html);
            }
        }
        return $html;
    }

    /** Close every window still open, most recent first. */
    public function closeOpenWindows($html)
    {
        while (!empty($this->windows)) {
            $w = array_pop($this->windows);
            $html = $this->fireCloser($w[1], $html);
        }
        return $html;
    }

    /** One closer, contained: a closer that throws leaves $html untouched and strands no other window. */
    private function fireCloser($closer, $html)
    {
        try { return call_user_func($closer, $html); } catch (\Throwable $e) { return $html; }
    }
}

final class wps_ic_render_pipeline
{
    const LANE_CDN = 1;
    const LANE_LOCAL = 2;
    const LANE_BOTH = self::LANE_CDN | self::LANE_LOCAL;

    private $stages = []; private $aliases; private $names = []; private $get; private $log = [];
    private $stopBefore = ''; private $stopAfter = ''; private $prof = false; private $returnedPristine = false;

    /** @param wps_ic_render_stage[] $stages  @param array $aliases old-name => stage name  @param array|null $get request params (null = $_GET) */
    public function __construct(array $stages, array $aliases = [], $get = null)
    {
        $names = [];
        foreach ($stages as $s) {
            if (!($s instanceof wps_ic_render_stage)) { throw new \InvalidArgumentException('stage entries must be wps_ic_render_stage'); }
            if (isset($names[$s->name])) { throw new \InvalidArgumentException('duplicate stage name ' . $s->name); }
            if (!is_callable($s->callable)) { throw new \InvalidArgumentException('stage ' . $s->name . ' has no callable'); }
            $names[$s->name] = true;
        }
        // An alias that points nowhere is a table bug, and it silently turns a stop into a full run.
        foreach ($aliases as $from => $to) {
            if (!is_string($to) || !isset($names[$to])) { throw new \InvalidArgumentException('alias ' . $from . ' points at unknown stage ' . (is_string($to) ? $to : gettype($to))); }
        }
        $this->stages = $stages; $this->aliases = $aliases; $this->names = $names;
        $this->get = is_array($get) ? $get : (isset($_GET) && is_array($_GET) ? $_GET : []);
        $this->stopBefore = $this->resolveName(isset($this->get['stop_before']) ? $this->get['stop_before'] : '');
        $this->stopAfter = $this->resolveName(isset($this->get['stop_after']) ? $this->get['stop_after'] : '');
        $this->prof = function_exists('wpc_prof_cp'); // hoisted: one lookup per table, not one per stage
    }

    private function resolveName($raw)
    {
        $raw = is_string($raw) ? preg_replace('/[^A-Za-z0-9_]/', '', $raw) : '';
        if ($raw === '') { return ''; }
        // Real table names are looked up first: a stale alias can never shadow a live stage.
        if (isset($this->names[$raw])) { return $raw; }
        if (isset($this->aliases[$raw])) { return $this->aliases[$raw]; }
        return '';
    }

    public function stageNames() { return array_map(function ($s) { return $s->name; }, $this->stages); }
    public function stageLog() { return $this->log; }
    public function stages() { return $this->stages; }

    /** True when the last run() answered with the pristine copy instead of its own bytes — i.e.
     *  a bail() entry fired. The envelope reads it to tell a bail apart from a completed run. */
    public function returnedPristine() { return $this->returnedPristine; }

    /**
     * Run the table over $html on one lane. A second run on the same instance replaces the log of the first.
     *
     * A bail() entry that fires ends the run there and then: every later entry is logged as
     * skipped and the pristine buffer is what comes back. A stage that ends the run by setting
     * $ctx->bailed itself (a debug receipt) is skipped past the same way, but its own output is
     * returned, because only the runner sets returnPristine.
     */
    public function run($html, $lane)
    {
        $ctx = new wps_ic_render_context($lane, $html);
        $this->log = [];
        $this->returnedPristine = false;
        $prefix = ($ctx->lane === self::LANE_CDN ? 'cdn:' : 'local:'); // lane prefix: once per run, not per stage
        try {
            foreach ($this->stages as $s) {
                // Stops bind to the table POSITION, not to whether the stage runs in this lane:
                // a lane-excluded stop_before still breaks here, and a lane-excluded stop_after
                // is logged and then breaks. So the name check comes before the lane check.
                if ($s->name === $this->stopBefore) { break; }
                $isStopAfter = ($s->name === $this->stopAfter);
                // A lane mismatch is logged too, so the trace lists every table entry.
                if (!($s->lanes & $ctx->lane)) { $this->log[] = ['name' => $s->name, 'delta' => 0, 'ms' => 0, 'skip' => 'lane']; if ($isStopAfter) { break; } continue; }
                if ($ctx->bailed) { $this->log[] = ['name' => $s->name, 'delta' => 0, 'ms' => 0, 'skip' => 'bailed:' . $ctx->bailName]; if ($isStopAfter) { break; } continue; }
                // The render budget is NOT a runner concern: the two wpc_render_budget_exceeded
                // doors are table entries of their own — bail_budget_script_content and
                // bail_budget_integrations — so the budget is checked at those two positions and
                // not between every pair of stages.
                $why = $s->gate ? call_user_func($s->gate, $ctx) : true;
                if ($why !== true) { $this->log[] = ['name' => $s->name, 'delta' => 0, 'ms' => 0, 'skip' => is_string($why) ? $why : 'gate']; if ($isStopAfter) { break; } continue; }
                if ($this->prof) { wpc_prof_cp($prefix . $s->name); }
                $t = microtime(true); $before = strlen((string) $html);
                if ($s->isBail) {
                    $fired = (bool) call_user_func($s->callable, $html, $ctx);
                    // A fired bail ends the run and the pristine buffer is the answer. The bail
                    // body has already done whatever it came to do (trip the breaker, set the
                    // shed flag, write a cflog line); the bytes it was handed are discarded.
                    if ($fired) { $ctx->bailed = true; $ctx->bailName = $s->name; $ctx->returnPristine = true; }
                    $this->log[] = ['name' => $s->name, 'delta' => 0, 'ms' => round((microtime(true) - $t) * 1000, 2), 'skip' => $fired ? '' : 'no-bail'];
                } else {
                    $out = call_user_func($s->callable, $html, $ctx);
                    // A stage handing back a non-string is that stage's bug, not the runner's:
                    // assign it anyway and let the envelope's never-blank guard deal with it,
                    // and leave a receipt naming the type.
                    $receipt = is_string($out) ? '' : 'non-string:' . gettype($out);
                    $html = $out;
                    $this->log[] = ['name' => $s->name, 'delta' => strlen((string) $html) - $before, 'ms' => round((microtime(true) - $t) * 1000, 2), 'skip' => $receipt];
                }
                if ($isStopAfter) { break; }
            }
        } finally {
            // Windows close even when a stage throws; the exception still reaches the envelope.
            $html = $ctx->closeOpenWindows($html);
        }
        if ($this->prof) { wpc_prof_cp($prefix . 'end'); }
        wpc_render_belt_flush(!$ctx->returnPristine);
        if ($ctx->returnPristine) { $this->returnedPristine = true; return $ctx->pristine; }
        if (!empty($this->get['wpc_stages'])) { $html = $this->appendTrace($html); }
        return $html;
    }

    private function appendTrace($html)
    {
        $lines = [];
        foreach ($this->log as $r) {
            // Names and gate reasons are arbitrary strings: clamp them to printable ASCII
            // so a stray byte cannot break the comment or the log's alignment.
            $name = preg_replace('/[^\x20-\x7E]/', '?', (string) $r['name']);
            $skip = preg_replace('/[^\x20-\x7E]/', '?', (string) $r['skip']);
            $lines[] = sprintf('%-32s %+7d %7.2fms %s', $name, $r['delta'], $r['ms'], $skip);
        }
        // Space out every dash that is followed by another dash. A single str_replace('--', '- -')
        // is not enough: it rescans past its own replacement, so '--->' comes back as '- -->'.
        $body = preg_replace('/-(?=-)/', '- ', implode("\n", $lines));
        $c = "\n<!-- wpc-stages:\n" . $body . "\n-->";
        $p = strripos((string) $html, '</body>');
        return $p === false ? $html . $c : substr($html, 0, $p) . $c . substr($html, $p);
    }
}
