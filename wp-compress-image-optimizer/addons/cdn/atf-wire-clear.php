<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cdn/atf-wire-clear.php
 *
 * Wire-clearing (stage atf_wire_clear, cohort atf_wire_clear, default off): every eager <img>
 * that is not the measured LCP is written fetchpriority="low", and one the service measured below
 * the fold is also written loading="lazy".
 *
 * Why. PageSpeed's lab LCP is a Lantern simulation: it averages an optimistic and a pessimistic
 * pass, both cut at the LCP the trace observed, and charges every request that finishes before
 * that cut against the LCP. The one exception is Low or VeryLow priority images, which the
 * optimistic pass leaves out. Chrome gives the first five eager images Medium priority, Lantern
 * counts Medium, and fetchpriority="auto" counts as High, so only an explicit "low" takes a
 * competing image off the hero's critical path. On the fleet the LCP grew by about 3.5 ms per KB
 * loaded before it; in the LCP harness this stage gained 4 points with 300 KB of competing
 * requests and 11 points with 800 KB.
 *
 * Writes, once per tag: fetchpriority="low" (an "auto", a missing attribute and a theme "high" are
 * all replaced; duplicate fetchpriority attributes collapse into one), and loading="lazy" on a tag
 * with no loading attribute when the lazy verdict's measurement puts it below the fold (its
 * measured "below-fold" or trusted "fold-below"; the census guess never counts) and the site
 * lazy-loads at all.
 *
 * Never touches:
 *   - the hero: the lazy verdict's lcpIdentity, the same identity lcp_demote_competitors keeps
 *     high; inside a <picture> the <img> or any <source> naming it protects the whole picture;
 *   - an image a <link rel=preload as=image> names (href or imagesrcset, the plugin's or the
 *     theme's), and that link;
 *   - icons: an .svg file, or both declared sides at most ICON_MAX_PX;
 *   - images already loading="lazy", JS-lazy slots (data-src placeholders), data: srcs;
 *   - images on the site's lazy or CDN exclude list, or carrying a lazy marker class;
 *   - anything inside script, style, noscript, template, textarea or comments;
 *   - CSS and inline-style backgrounds: no attribute can lower a CSS fetch;
 *   - the whole page when the LCP is unknown (unmeasured or withheld).
 *
 * Position: stage atf_wire_clear in the render table (addons/cdn/cdn-rewrite.php), CDN on or off,
 * right after lcp_demote_competitors and so after emit_image_preloads: the preloads it spares are
 * written and the hero's high is settled before it runs; drop_dashicons follows.
 *
 * Gate and switches: on() needs cohort atf_wire_clear = 1 in the service policy
 * (wpc_policy_cohort). It is not a built-in cohort and the plugin default list is empty, so it is
 * off until the orchestrator names it for the site (the filter wpc_plugin_default_cohorts can add
 * it as a plugin default). The filter wpc_atf_wire_clear has the last word (false turns it off).
 * No constant. gate() also refuses AMP renders ('amp') and a tree without the lazy verdict
 * ('no-verdict'). Off, the page is returned as is.
 *
 * Receipt: belt note atf-wire-clear (wpc_render_belt_note) with state applied, none or skipped
 * (why = lcp-<type>) and the counts low, lazy, high (a theme high lowered), hero, preloaded, icon
 * and excluded. Every state but applied is sampled.
 *
 * @package wp-compress-image-optimizer
 */
if (!defined('ABSPATH')) { exit; }

final class wps_ic_atf_wire_clear
{
    const COHORT = 'atf_wire_clear';
    const NOTE = 'atf-wire-clear';
    /** An <img> whose declared width and height are both at most this many px is an icon or a logo
     * (a file of about 4 KB or less): it costs the hero next to nothing and is left as written. */
    const ICON_MAX_PX = 64;

    /** Whether the stage runs on this request: cohort atf_wire_clear is 1, then the filter wpc_atf_wire_clear decides. */
    public static function on()
    {
        $on = function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort(self::COHORT) === 1;
        return function_exists('apply_filters') ? (bool) apply_filters('wpc_atf_wire_clear', $on) : $on;
    }

    /** The stage table's gate: true, or why the stage does not run ('cohort-off', 'amp', 'no-verdict'). */
    public static function gate($ctx)
    {
        if (!self::on()) {
            return 'cohort-off';
        }
        if (!empty($ctx->isAmp)) {
            return 'amp';
        }
        return class_exists('wps_ic_lazy_verdict') ? true : 'no-verdict';
    }

    /**
     * The stage: asks the lazy verdict for the page's LCP identity and, when it is known, runs
     * pass() with the verdict's answers for "is this the hero" and "may this go lazy". Writes the
     * receipt either way.
     */
    public static function stage($html, $ctx)
    {
        if (!is_string($html) || $html === '' || !self::on() || !class_exists('wps_ic_lazy_verdict')) {
            return $html;
        }
        $owner = (isset($ctx->lazyVerdict) && $ctx->lazyVerdict instanceof wps_ic_lazy_verdict) ? $ctx->lazyVerdict : wps_ic_lazy_verdict::forRequest();
        $identity = $owner->lcpIdentity(isset($ctx->pristine) ? $ctx->pristine : null);
        // No known LCP, no edit: lowering every image against a missing or withheld identity would
        // take the high priority from the real LCP.
        if (empty($identity['known'])) {
            self::note(['state' => 'skipped', 'why' => 'lcp-' . (string) $identity['type']]);
            return $html;
        }
        $counts = [];
        // The hero is the image the lazy verdict keeps high, so this stage and lcp_demote_competitors
        // never disagree about which image it is.
        $out = self::pass($html, function ($tag) use ($owner) {
            return $owner->keepsHigh($tag);
        }, function ($tag) use ($owner) {
            // No loading="lazy" while the site's lazy loading is off: the site asked for none.
            if (!$owner->lazyLoadingOn()) {
                return false;
            }
            // Measured below only: the trusted slot verdict, or the fold verdict's own measured "below";
            // the census guess never counts.
            $verdict = $owner->forTag($tag, '', true);
            return $verdict['loading'] === 'lazy'
                && ($verdict['why'] === 'fold-below' || ($verdict['why'] === 'below-fold' && $owner->foldVerdict($tag) === 'below'));
        }, $counts, [self::class, 'excluded']);
        $changed = $counts['low'] + $counts['lazy'];
        self::note(['state' => $changed > 0 ? 'applied' : 'none', 'type' => (string) $identity['type']] + $counts);
        return $out;
    }

    /**
     * Whether the site asked to leave this image alone: an entry of the lazy exclude list, a lazy
     * marker class (skip-lazy, nolazy, ...), or a src on the lazy or CDN exclude list. The lazy
     * verdict honours the same list and markers, so an image a site keeps out of lazy loading is
     * not lowered here either.
     */
    public static function excluded($tag)
    {
        if (class_exists('wps_ic_lazy_verdict') && (wps_ic_lazy_verdict::listMatches($tag, '') || wps_ic_lazy_verdict::carriesMarker($tag))) {
            return true;
        }
        $src = trim((string) self::attr($tag, 'src'));
        if ($src === '' || stripos($src, 'data:') === 0 || !class_exists('wps_cdn_rewrite') || !is_callable(['wps_cdn_rewrite', 'isExcludedFrom'])) {
            return false;
        }
        return wps_cdn_rewrite::isExcludedFrom('lazy', $src) || wps_cdn_rewrite::isExcludedFrom('cdn', $src);
    }

    /**
     * The edit itself. $isHero(tag) says whether a tag names the hero, $belowFold(tag) whether an
     * eager image may go lazy, $excluded(tag) whether the site keeps it out. $counts receives the
     * receipt's counts. Edited tags are spliced back by offset, so every byte outside them stays as
     * written; with nothing to change the input is returned.
     */
    public static function pass($html, $isHero, $belowFold, &$counts = null, $excluded = null)
    {
        $counts = ['low' => 0, 'lazy' => 0, 'high' => 0, 'hero' => 0, 'preloaded' => 0, 'icon' => 0, 'excluded' => 0];
        if (!is_string($html) || $html === '' || !is_callable($isHero) || !is_callable($belowFold) || stripos($html, '<img') === false) {
            return $html;
        }
        $masked = self::masked($html);
        $preloaded = self::preloadedKeys($html, $masked);
        // The <source>s of each <picture> are read with its <img>: a hero named only by a source
        // still protects the picture's <img>.
        $pictures = [];
        if (preg_match_all('#<picture\b[^>]*>.*?</picture\s*>#is', $html, $blocks, PREG_OFFSET_CAPTURE)) {
            foreach ($blocks[0] as $block) {
                if (self::inside($block[1], $masked) === null) {
                    $pictures[] = [$block[1], $block[1] + strlen($block[0]), $block[0]];
                }
            }
        }
        $edits = [];
        if (preg_match_all('/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $tags, PREG_OFFSET_CAPTURE)) {
            foreach ($tags[0] as $one) {
                if (self::inside($one[1], $masked) !== null) {
                    continue;
                }
                $picture = self::inside($one[1], $pictures);
                $sources = [];
                if ($picture !== null && preg_match_all('/<source\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $picture[2], $found)) {
                    $sources = $found[0];
                }
                $new = self::image($one[0], $sources, $isHero, $belowFold, $preloaded, $counts, $excluded);
                if ($new !== $one[0]) {
                    $edits[] = [$one[1], strlen($one[0]), $new];
                }
            }
        }
        if ($edits === []) {
            return $html;
        }
        usort($edits, function ($a, $b) {
            return $a[0] - $b[0];
        });
        $out = '';
        $pos = 0;
        foreach ($edits as $edit) {
            $out .= substr($html, $pos, $edit[0] - $pos) . $edit[2];
            $pos = $edit[0] + $edit[1];
        }
        return $out . substr($html, $pos);
    }

    /** One <img> as it should be written: the tag as is, or with low priority and possibly lazy. */
    private static function image($tag, array $sources, $isHero, $belowFold, array $preloaded, array &$counts, $excluded = null)
    {
        // A tag the attribute walk cannot read whole is left as written.
        if (!self::wellFormed($tag)) {
            return $tag;
        }
        $src = self::attr($tag, 'src');
        $srcset = self::attr($tag, 'srcset');
        $fetches = ($src !== null && trim($src) !== '' && stripos(ltrim($src), 'data:') !== 0) || ($srcset !== null && trim($srcset) !== '');
        // Nothing to lower: no fetching src or srcset (a data: placeholder), or a JS-lazy slot whose
        // file waits in data-src for its library.
        if (!$fetches || wps_ic_lazy_verdict::dataSrcIsLazySlot($tag)) {
            return $tag;
        }
        // The site's own excludes are answered before anything else is counted.
        if (is_callable($excluded) && call_user_func($excluded, $tag)) {
            $counts['excluded']++;
            return $tag;
        }
        $loading = strtolower(trim((string) self::attr($tag, 'loading')));
        // Already lazy: left as written.
        if ($loading === 'lazy') {
            return $tag;
        }
        // The hero keeps its priority; inside a <picture> any <source> naming it protects the <img>.
        if (call_user_func($isHero, $tag)) {
            $counts['hero']++;
            return $tag;
        }
        foreach ($sources as $source) {
            if (call_user_func($isHero, $source)) {
                $counts['hero']++;
                return $tag;
            }
        }
        // An image the page preloads (the plugin's preload or the theme's, the logo included) is never
        // lowered: any candidate of the tag or of its sources that a preload names keeps the tag as is.
        if ($preloaded !== []) {
            $names = self::candidates((string) $src, (string) $srcset);
            foreach ($sources as $source) {
                $names = array_merge($names, self::candidates('', (string) self::attr($source, 'srcset')));
            }
            foreach ($names as $name) {
                if (isset($preloaded[self::key($name)])) {
                    $counts['preloaded']++;
                    return $tag;
                }
            }
        }
        // Icons and logos stay as written (ICON_MAX_PX).
        if (self::iconSized($tag, (string) $src)) {
            $counts['icon']++;
            return $tag;
        }
        $out = $tag;
        // "auto", no attribute and a theme "high" are all lowered: Chrome gives the first five eager
        // images Medium and Lantern counts Medium, so only an explicit "low" takes an image out of the
        // optimistic graph. withAttr drops every fetchpriority first, so duplicates collapse into one.
        $priority = strtolower(trim((string) self::attr($tag, 'fetchpriority')));
        if ($priority !== 'low') {
            $out = self::withAttr($out, 'fetchpriority', 'low');
            $counts['low']++;
            if ($priority === 'high') {
                $counts['high']++;
            }
        }
        // loading="lazy" only where the tag has no loading attribute at all (an existing one is never
        // changed) and the measurement puts it below the fold.
        if ($loading === '' && self::attr($tag, 'loading') === null && call_user_func($belowFold, $tag)) {
            $out = self::withAttr($out, 'loading', 'lazy');
            $counts['lazy']++;
        }
        return $out;
    }

    /** Keys of every image a <link rel=preload as=image> outside the masked regions names (href and imagesrcset). */
    private static function preloadedKeys($html, array $masked)
    {
        $keys = [];
        if (stripos($html, '<link') === false || !preg_match_all('/<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $links, PREG_OFFSET_CAPTURE)) {
            return $keys;
        }
        foreach ($links[0] as $one) {
            $tag = $one[0];
            if (self::inside($one[1], $masked) !== null
                || !in_array('preload', preg_split('/\s+/', strtolower(trim((string) self::attr($tag, 'rel')))), true)
                || strtolower(trim((string) self::attr($tag, 'as'))) !== 'image') {
                continue;
            }
            foreach (self::candidates((string) self::attr($tag, 'href'), (string) self::attr($tag, 'imagesrcset')) as $name) {
                $key = self::key($name);
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }
        return $keys;
    }

    /** Every fetching address a src and a srcset name, data: URIs left out. */
    private static function candidates($src, $srcset)
    {
        $out = [];
        if (trim($src) !== '' && stripos(ltrim($src), 'data:') !== 0) {
            $out[] = trim($src);
        }
        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if (is_array($parts) && isset($parts[0]) && $parts[0] !== '' && stripos($parts[0], 'data:') !== 0) {
                $out[] = $parts[0];
            }
        }
        return $out;
    }

    /**
     * The identity every size of one image shares: the preload set's imageKey when it is loaded, so
     * this stage and the preload writer compare the same keys; else the file name with its
     * extensions and size suffix (-WxH, -Nw, -scaled, -rotated) removed, lower-cased.
     */
    private static function key($url)
    {
        if (class_exists('wps_ic_image_preload_set')) {
            return wps_ic_image_preload_set::imageKey($url, true);
        }
        $path = (string) parse_url(html_entity_decode((string) $url, ENT_QUOTES), PHP_URL_PATH);
        return strtolower((string) preg_replace('/(?:-\d+x\d+|-\d+w|-scaled|-rotated)$/i', '', (string) preg_replace('/(?:\.[a-z0-9]{2,5})+$/i', '', basename($path))));
    }

    /** SVG, or both declared sides <= ICON_MAX_PX: a <= ~4 KB file, left as the page wrote it. A missing or non-numeric side is not an icon. */
    private static function iconSized($tag, $src)
    {
        if (preg_match('/\.svg(?:[?#]|$)/i', trim($src))) {
            return true;
        }
        foreach (['width', 'height'] as $side) {
            $value = self::attr($tag, $side);
            if ($value === null || !preg_match('/^\s*(\d{1,5})(?:px)?\s*$/i', $value, $m) || (int) $m[1] < 1 || (int) $m[1] > self::ICON_MAX_PX) {
                return false;
            }
        }
        return true;
    }

    /** [name(lower), start, length, value|null] per attribute, quotes respected (a value naming an attribute is not one). */
    private static function attrs($tag)
    {
        $found = [];
        if (!preg_match('/^<[A-Za-z][A-Za-z0-9-]*/', $tag, $head)) {
            return $found;
        }
        $at = strlen($head[0]);
        $end = strlen($tag);
        while ($at < $end && preg_match('/\G[\s\/]*([^\s"\'>\/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $tag, $m, 0, $at)) {
            $value = null;
            if (isset($m[2]) && $m[2] !== '') {
                $raw = $m[2];
                $value = ($raw[0] === '"' || $raw[0] === "'") ? substr($raw, 1, -1) : $raw;
            }
            $found[] = [strtolower($m[1]), $at, strlen($m[0]), $value];
            $at += strlen($m[0]);
        }
        return $found;
    }

    /** The attribute walk reached the closing bracket: a tag it cannot read whole is left as written. */
    private static function wellFormed($tag)
    {
        $attrs = self::attrs($tag);
        if (!preg_match('/^<[A-Za-z][A-Za-z0-9-]*/', $tag, $head)) {
            return false;
        }
        $at = $attrs === [] ? strlen($head[0]) : $attrs[count($attrs) - 1][1] + $attrs[count($attrs) - 1][2];
        return (bool) preg_match('/^[\s\/]*>$/', substr($tag, $at));
    }

    /** The first $name attribute's decoded value; '' when it has no value, null when it is absent. */
    private static function attr($tag, $name)
    {
        foreach (self::attrs($tag) as $attr) {
            if ($attr[0] === $name) {
                return $attr[3] === null ? '' : html_entity_decode($attr[3], ENT_QUOTES);
            }
        }
        return null;
    }

    /** Every $name attribute dropped, one written right after the tag name (no duplicates). */
    private static function withAttr($tag, $name, $value)
    {
        $out = $tag;
        foreach (array_reverse(self::attrs($tag)) as $attr) {
            if ($attr[0] === $name) {
                $out = substr($out, 0, $attr[1]) . substr($out, $attr[1] + $attr[2]);
            }
        }
        $written = preg_replace('/^<([A-Za-z][A-Za-z0-9-]*)/', '<$1 ' . $name . '="' . $value . '"', $out, 1);
        return is_string($written) ? $written : $tag;
    }

    /** Byte ranges the stage never reads tags from: script, style, noscript, template, textarea and comments. */
    private static function masked($html)
    {
        $masked = [];
        if (preg_match_all('#<noscript\b[^>]*>.*?</noscript\s*>|<script\b[^>]*>.*?</script\s*>|<template\b[^>]*>.*?</template\s*>|<style\b[^>]*>.*?</style\s*>|<textarea\b[^>]*>.*?</textarea\s*>|<!--.*?-->#is', $html, $regions, PREG_OFFSET_CAPTURE)) {
            foreach ($regions[0] as $region) {
                $masked[] = [$region[1], $region[1] + strlen($region[0])];
            }
        }
        return $masked;
    }

    /** The range holding offset $at, or null. */
    private static function inside($at, array $ranges)
    {
        foreach ($ranges as $range) {
            if ($at >= $range[0] && $at < $range[1]) {
                return $range;
            }
        }
        return null;
    }

    /** The receipt: belt note atf-wire-clear; every state but applied is sampled. */
    private static function note(array $fields)
    {
        if (function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note(self::NOTE, $fields, isset($fields['state']) && $fields['state'] !== 'applied');
        }
    }
}
