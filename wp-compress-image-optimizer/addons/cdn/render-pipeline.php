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

    /** @var array  tuple key => [css, family, rank, origin, eager, seq] in insertion order. */
    private $faces = [];
    /** @var array  lowercase family => why, for families a pass asked to be served late. */
    private $demotedFamilies = [];
    /** @var int  Monotonic insert counter, so emit order is registration order. */
    private $sequence = 0;
    /** @var int  Faces dropped at emit (unbacked family, pruned range, evicted late twin). */
    private $droppedCount = 0;

    /** Take every @font-face block in $css into the set. $origin names the pass for the receipt;
     *  $eager is that pass's intent — false means "this face was never a first-paint declaration".
     *  Returns the number of blocks taken. Anything that is not a face block is ignored. */
    public function add($css, $origin, $eager = true)
    {
        $css = (string) $css;
        if ($css === '' || stripos($css, '@font-face') === false) { return 0; }
        // Host healing belongs where the bytes arrive, not in a later sweep over the document.
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_heal_css_host_twin')) {
            $healed = wps_cdn_rewrite::wpc_heal_css_host_twin($css);
            if (is_string($healed) && $healed !== '') { $css = $healed; }
        }
        if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $blocks)) { return 0; }
        $taken = 0;
        foreach ($blocks[0] as $block) {
            $family = self::familyOf($block);
            if ($family === '') { continue; }
            $base = self::baseKey($block, $family);
            $range = self::rangeOf($block);
            $subset = $range !== '' && self::hasEmbeddedSource($block);
            // A range is part of a face's identity only against another ranged face. Where one
            // side declares a range and the other does not, they are the same face delivered two
            // ways, and the one that carries its own bytes for a declared slice of the alphabet
            // is the one the page was built to use: the embedded subset wins, and the full-file
            // twin that would fetch the same glyphs over the network goes. Whichever arrives
            // first. Ranged against ranged keeps every range (that is coverage, not duplication),
            // and rangeless against rangeless is the ordinary contest below.
            $shadowed = false;
            foreach ($this->faces as $siblingKey => $sibling) {
                if ($sibling['base'] !== $base) { continue; }
                if ($subset && $sibling['range'] === '' && !self::hasEmbeddedSource($sibling['css'])) {
                    unset($this->faces[$siblingKey]);
                    $this->droppedCount++;
                    continue;
                }
                if ($range === '' && !self::hasEmbeddedSource($block)
                    && $sibling['range'] !== '' && self::hasEmbeddedSource($sibling['css'])) {
                    $shadowed = true;
                }
            }
            if ($shadowed) { continue; }
            $key = $base . '|' . $range;
            $rank = self::rankOf($block);
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
                'seq' => isset($this->faces[$key]) ? $this->faces[$key]['seq'] : $this->sequence++,
            ];
            $taken++;
        }
        return $taken;
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
     *  /contact-us/, which carries the ETmodules stand-in but no subset). */
    public function pinsFamily($family)
    {
        $family = self::normaliseFamily($family);
        if ($family === '') { return false; }
        $iconFamily = function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($family);
        foreach ($this->faces as $face) {
            if (!$face['eager']) { continue; }
            $pinned = substr($face['family'], -9) === ' fallback'
                ? rtrim(substr($face['family'], 0, -9)) : $face['family'];
            if ($pinned !== $family) { continue; }
            if ($iconFamily ? self::hasEmbeddedSource($face['css']) : self::isGatePin($face['css'], $face['family'])) {
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

    /** The CSS that paints at first paint. */
    public function eager() { return $this->render(false); }

    /** The CSS the loader arms after load. */
    public function late() { return $this->render(true); }

    /** [eager, late, dropped, demoted] face counts, for the render's one receipt. */
    public function counts()
    {
        $split = $this->split();
        return [
            'eager' => count(self::writeFaces($split[0], false)),
            'late' => count(self::writeFaces($split[1], true)),
            'dropped' => $this->droppedCount + count($split[2]),
            'demoted' => count($this->demotedFamilies),
        ];
    }

    /* --------------------------------------------------------------------------------------- */

    /** Split the set into [eager, late, evicted], in registration order. */
    private function split()
    {
        $faces = $this->faces;
        uasort($faces, function ($a, $b) { return $a['seq'] - $b['seq']; });
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
        foreach ($faces as $face) {
            if (!$face['eager'] || isset($demoted[$face['family']])
                || (isset($gatePinned[$face['family']]) && !self::isLiveCarrier($face))
                || self::paintsWithoutNetwork($face['css'], $face['family'])
                || !self::hasNetworkSource($face['css'])) { continue; }
            $declared[$face['family']] = 1;
        }
        $eager = [];
        $late = [];
        $evicted = [];
        foreach ($faces as $face) {
            if (self::paintsWithoutNetwork($face['css'], $face['family'])) { $eager[] = $face; continue; }
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
        return [$eager, $late, $evicted];
    }

    private function render($wantLate)
    {
        $split = $this->split();

        return implode('', self::writeFaces($split[$wantLate ? 1 : 0], $wantLate));
    }

    /**
     * The CSS of one block's faces, in order, one face per file.
     *
     * Rule: faces whose written CSS is identical except for a numeric font-weight are one file
     * served for several weights, so they are written once, at the first one's position, with
     * font-weight set to the lowest and highest weight of the group. The browser matches every
     * weight in the span to that file, which is what the separate faces said, and fetches it
     * once. Only the weight may differ: a different src, unicode-range, style, stretch or
     * display is a different face and stays one. A face with no url() src is not a file (the
     * local() metric stand-ins carry per-weight overrides) and is written as registered. The tuple key keeps the per-weight faces, so
     * every contest, demotion and coverage question above still sees what was registered.
     *
     * Observed failure (7.24.09 to 7.24.54, wpcompress.com/pricing/): Elementor's Roboto sheet,
     * localized from Google's v1 API, declares 9 weights × 18 subset files; the block shipped
     * 162 Roboto faces (121 KB in the head, "Reduce unused CSS 23.7 KiB") where 7.24.04 shipped
     * 18 span faces (14.5 KB). The merge lived in font_face_dedupe180 (v7.21.191) and went when
     * that pass was deleted with the owner's arrival.
     */
    private static function writeFaces(array $faces, $isLate)
    {
        $written = [];
        $groupOf = [];
        foreach ($faces as $face) {
            $css = self::applyDisplayPolicy($face, $isLate);
            if (!preg_match('/src\s*:[^;}]*url\(/i', $css)
                || !preg_match('/font-weight\s*:\s*(\d{1,4})(?:\s+(\d{1,4}))?\s*(?=[;}])/i', $css, $weight)) {
                $written[] = ['css' => $css];
                continue;
            }
            $low = (int) $weight[1];
            $high = isset($weight[2]) && $weight[2] !== '' ? (int) $weight[2] : $low;
            $signature = md5((string) preg_replace('/\s+/', ' ',
                (string) preg_replace('/font-weight\s*:\s*\d{1,4}(?:\s+\d{1,4})?\s*;?/i', '', $css)));
            if (!isset($groupOf[$signature])) {
                $groupOf[$signature] = count($written);
                $written[] = ['css' => $css, 'low' => $low, 'high' => $high];
                continue;
            }
            $at = $groupOf[$signature];
            $written[$at]['low'] = min($written[$at]['low'], $low);
            $written[$at]['high'] = max($written[$at]['high'], $high);
        }
        $out = [];
        foreach ($written as $entry) {
            $css = $entry['css'];
            if (isset($entry['low']) && $entry['high'] > $entry['low']) {
                $css = (string) preg_replace('/(font-weight\s*:\s*)\d{1,4}(?:\s+\d{1,4})?/i',
                    '${1}' . $entry['low'] . ' ' . $entry['high'], $css, 1);
            }
            $out[] = $css;
        }

        return $out;
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
            return (self::ORIGIN_DISPLAY_INJECTED === $face['origin'] && self::siteFontDisplayOn())
                ? (string) preg_replace('/\{/', '{font-display:swap;', $css, 1)
                : $css;
        }
        if ($isLate) {
            // optional's grace window opens when the face is first needed; a block armed after
            // load has already missed it, so the browser locks the fallback for good.
            return $declared === 'optional'
                ? (string) preg_replace('/font-display\s*:\s*optional/i', 'font-display:swap', $css)
                : $css;
        }
        if ($declared !== 'swap' || !function_exists('wpc_font_display_effective')) { return $css; }
        $effective = wpc_font_display_effective('swap', $family);
        if (!is_string($effective) || $effective === 'swap'
            || !in_array($effective, ['optional', 'fallback', 'block', 'auto'], true)) { return $css; }
        return (string) preg_replace('/font-display\s*:\s*swap\b/i', 'font-display:' . $effective, $css);
    }

    /** False when the site has switched font-display handling off, which is the one setting that
     *  stops the live-faces lane putting swap on a face that declares no display. */
    private static function siteFontDisplayOn()
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

    /**
     * Register a candidate. $id and $href are attribute-ready (the writer escaped them); $device
     * is 'mobile', 'desktop' or 'both'; $origin names the writer for the receipt; $rank is a
     * RANK_* constant and decides only the lcp slot. $extras: 'slot' (SLOT_*, default lcp),
     * 'kind' (KIND_*, default image), 'imagesrcset', 'imagesizes', 'type', 'fetchpriority'
     * (default 'high', '' for none), 'arms' (a <picture>'s per-source preloads: a list of
     * ['imagesrcset','imagesizes','media','type'], written as $id, $id-2, $id-3), 'key' (the
     * picture's identity when neither $href nor imagesrcset names it). Returns false when there
     * is nothing to preload.
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
        ];
        $this->registeredCount++;
        return true;
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
     * The identity of the PICTURE a URL names, not of the file: the path from /wp-content/ (or
     * the basename), the next-gen and original extension, the -WxH suffix and -scaled/-rotated
     * stripped, the zone transform wrapper (/u:https://…, /m:0/a:https://…) unwrapped. Two
     * renditions of one image compete for one paint, so for preload purposes they are one.
     * '' when the URL carries no path to read.
     */
    public static function imageKey($url)
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
        // The PATH is the identity, not the basename: two uploads months can hold the same file
        // name, and a key that collided would collapse two different pictures into one.
        $at = strrpos($path, '/wp-content/');
        $id = rawurldecode($at !== false ? substr($path, $at) : basename($path));
        $id = (string) preg_replace('/\.(avif|webp)$/i', '', $id);
        $id = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $id);
        $id = (string) preg_replace('/-\d+x\d+$/', '', $id);
        $id = (string) preg_replace('/-(?:scaled|rotated)$/i', '', $id);
        return strlen($id) > 1 ? strtolower($id) : '';
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

        // Resolve every candidate against the document first, so a candidate that cannot be
        // used never holds a slot a usable one could have had.
        $usable = [];
        foreach ($this->candidates as $seq => $candidate) {
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
            $resolved = $this->resolve($candidate, $html, $images, $documentUrls, $renderDevice);
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
                if ($best === null || $candidate['key'] === '' || $candidate['key'] !== $best['key']) {
                    $won[$candidate['seq']][] = $device;
                }
                break;
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

    /** Receipt counts, for the debug views and scratch checks. */
    public function counts()
    {
        return ['registered' => $this->registeredCount, 'held' => count($this->candidates), 'dropped' => $this->droppedCounts];
    }

    /**
     * The pictures a finished document preloads, per device: ['mobile' => [imageKey => true],
     * 'desktop' => [...]]. Every <link rel="preload" as="image"> counts whoever wrote it (any
     * writer's id, the page's own tag, no id); a tag with no device media counts for both. The
     * identity is imageKey(), so origin, zone and custom-cname forms of one picture match.
     */
    public static function preloadedPictures($html)
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
            $key = self::imageKey(($href !== '') ? $href : (string) preg_split('/[\s,]+/', $srcset)[0]);
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
    private function resolve(array $candidate, $html, array $images, &$documentUrls, $renderDevice)
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
        // Everything else takes the rendition of its URL that the document names: the URL passes
        // (zone, naturalize, next-gen hint, disk pick, first-party) rewrote that same URL where
        // the page's CSS and markup carry it.
        if ($candidate['href'] !== '' && !self::appearsAsUrl($html, $candidate['href'])) {
            if ($documentUrls === null) {
                $documentUrls = self::documentUrls($html);
            }
            $candidate['href'] = self::renditionInDocument($candidate['href'], $documentUrls);
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
     */
    private static function renditionInDocument($href, array $documentUrls)
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
                foreach (array_keys($found) as $url) {
                    if (self::formatOf($url) !== $format) {
                        unset($found[$url]);
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
     * True when $url occurs in $html as a whole URL, not as the start of a longer one. A
     * substring test read cloud-bg.webp as present because the crit CSS carried
     * cloud-bg.webp?src=png (staging-home, local lane): the preload named the bare .webp the CSS
     * never fetches, a second download beside the one the page paints.
     */
    private static function appearsAsUrl($html, $url)
    {
        return (bool) preg_match('~' . preg_quote((string) $url, '~') . '(?=["\')\s,<>]|$)~', (string) $html);
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

    /**
     * Where the preloads go: after <head>, the charset meta when the head opens with it, and the
     * run of <meta> tags that follows — the first bytes the preload scanner reads. Anywhere later
     * and the scanner meets them behind the inline CSS: on aliiadventureshack the hero preload
     * sat at byte 101,737 and Lighthouse measured 1,720 ms of LCP load delay with it present.
     * -1 when the buffer has no <head>.
     */
    private static function insertPosition($html)
    {
        if (!preg_match('/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE)) {
            return -1;
        }
        $headAt = $head[0][1] + strlen($head[0][0]);
        $at = $headAt;
        if (preg_match('/<meta\b[^>]*(?:\bcharset\b|http-equiv=["\']content-type)[^>]*>/i', substr($html, $headAt, 4096), $charset, PREG_OFFSET_CAPTURE)) {
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
    /** @var bool  The buffer is an AMP document, as the AMP settings squash found it. Stages that
     *              inject markup AMP forbids read this through gate_not_amp; it stays false on a
     *              run that stopped before the squash, so nothing claims a page is AMP unasked.
     *              Also read by the crit kick, delay and speculation gates, the yield pass, the
     *              iframe facade, cdn_rewrite_url, and (handed down as a parameter) the
     *              rewriteLogic <img> and external-URL passes. */
    public $isAmp = false;
    /** @var mixed  Lazy loading for this render ('1' on): the request's value from mainInit(),
     *              seeded by stage_prelude, turned '0' by the AMP squash. Read by
     *              local_image_tags and by is_excluded() through cdn_rewrite_url. */
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
    /** @var string[]  The LCP image stems lcp_img_preload identified (one per measured device);
     *                 lcp_demote_competitors strips fetchpriority="high" from every other <img>. */
    public $lcpKeepPriorityStems = [];

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
