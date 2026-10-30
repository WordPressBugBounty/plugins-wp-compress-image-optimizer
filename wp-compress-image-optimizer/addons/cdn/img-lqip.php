<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cdn/img-lqip.php
 *
 * An inline placeholder for the phone's <img> LCP hero (stage img_lqip, cohort img_lqip, default off).
 *
 * The phone paints a WebP placeholder of at most 10 KB, the service's record
 * lcp_element.mobile.img_lqip (read by wps_ic_atf_observation::imgLqip), as the hero <img>'s own
 * background, in the same box with the same framing. Chrome takes that paint as the LCP. The real
 * file then loads at fetchpriority="low" and paints over it at the same size; Chrome replaces an
 * LCP candidate only with a strictly larger one, so the LCP stays at the placeholder's paint. On the
 * lab fixture the real Chrome LCP went from 1.93 s to 0.24 s.
 *
 * Hard rule: fetchpriority="low" is never written without the placeholder. Low priority on its own
 * only games the lab simulation, which leaves Low image requests out of its LCP graph: the lab LCP
 * drops while the real Chrome LCP stays where it was (measured 1.92 s with low and no placeholder).
 *
 * Writes, all four or nothing (the page's own bytes otherwise):
 *   1. <style id="wpc-hero-lqip-img">, the rule under @media (max-width:450px), right after
 *      <style id="wpc-critical-css">, else before </head>;
 *   2. data-wpclqip="<sig>" fetchpriority="low" on the one served <img>, an earlier fetchpriority
 *      removed and loading="lazy" turned eager;
 *   3. <script id="wpc-img-lqip"> right after the tag, which removes the style once the file has
 *      painted;
 *   4. the wide preload <link id="wpc-img-lqip-wide" media="(min-width:451px)">, after the first
 *      viewport meta;
 * and it vetoes every other image preload of the hero's picture.
 *
 * Position: stage img_lqip in the render table (addons/cdn/cdn-rewrite.php), CDN on or off, right
 * after hero_lqip and before crit_bg_preload and emit_image_preloads, so the veto reaches the one
 * preload writer.
 *
 * Gate and switches: cohort img_lqip = 1 in the service policy (wps_ic_atf_observation::imgLqipOn,
 * through wpc_policy_cohort). It is not a built-in cohort, so it is off until the orchestrator names
 * it; it is a render cohort, so a flip purges the page cache. No constant, no filter. Both sides must
 * be on: a service record and the cohort; either alone writes nothing.
 *
 * Never touches: desktop renders, AMP renders, a page whose CSP refuses inline scripts, inline
 * styles or data: images, a page that already carries this placeholder or the background
 * placeholder (one placeholder per page), and any hero find() refuses.
 *
 * Receipt: belt note img-lqip {state, why}: skipped (sampled), refused or unapplied with the reason,
 * applied with bytes, lazy (the tag was lazy) and vetoed (preload keys vetoed).
 *
 * @package wp-compress-image-optimizer
 */

if (!defined('ABSPATH')) { exit; }

final class wps_ic_img_lqip
{
    // The style id starts with wpc-hero-lqip, so every service read that strips the background
    // placeholder strips this one too and measures the real page.
    const STYLE_ID = 'wpc-hero-lqip-img';
    const SCRIPT_ID = 'wpc-img-lqip';
    // The marker is data-wpclqip, never data-wpc-*: image_pins treats a data-wpc- tag as its own.
    const MARK = 'data-wpclqip';
    const PRELOAD_ID = 'wpc-img-lqip-wide';

    /**
     * The stage table's gate: true, or why the stage does not run, in this order: 'off' (checked first,
     * so the default render does no other work and stays byte-identical), 'amp', 'desktop' (not a
     * mobile render), 'csp' or 'csp-img' (a response header policy refuses). There is no user-agent
     * test beyond the mobile render: the page cache keeps one mobile copy for phones and tablets, so a
     * gate on the agent would let the first device to render decide for the whole bucket. The stage
     * writes markup instead, and each browser evaluates its media against its own viewport.
     */
    public static function gate($ctx, $headers = null)
    {
        if (!class_exists('wps_ic_atf_observation') || !wps_ic_atf_observation::imgLqipOn()) {
            return 'off';
        }
        if (!empty($ctx->isAmp)) {
            return 'amp';
        }
        if (!class_exists('wps_rewriteLogic') || empty(wps_rewriteLogic::$isMobile)) {
            return 'desktop';
        }
        $csp = self::cspRefusal('', $headers);

        return $csp === '' ? true : $csp;
    }

    /**
     * Why a Content-Security-Policy refuses the stage: 'csp' when inline scripts or styles are refused
     * (the swap script or the style would not run), 'csp-img' when data: images are, '' when neither.
     * The inline check runs first, so default-src 'self' alone answers 'csp'.
     */
    private static function cspRefusal($html, $headers = null)
    {
        if (class_exists('wps_cdn_rewrite')
            && (wps_cdn_rewrite::wpc_csp_blocks_inline_scripts($html, $headers) || wps_cdn_rewrite::wpc_csp_blocks_inline_styles($html, $headers))) {
            return 'csp';
        }

        return self::cspBlocksDataImages($html, $headers) ? 'csp-img' : '';
    }

    /**
     * Whether an enforced policy refuses data: images. The placeholder is a data: URI, so such a policy
     * would leave low on the tag with nothing painted. Every policy counts: meta tags and
     * Content-Security-Policy header lines, one header line carrying several comma-separated policies;
     * Report-Only never counts. The directive that governs images is img-src, else default-src; one
     * without a data: source refuses (* does not cover data:, 'none' allows nothing, scheme sources are
     * case-insensitive). A policy with neither directive allows it. Any one refusing policy refuses.
     */
    public static function cspBlocksDataImages($html, $headers = null)
    {
        $policies = [];
        if (is_string($html) && $html !== ''
            && preg_match_all('/<meta\b[^>]*http-equiv\s*=\s*["\']Content-Security-Policy["\'][^>]*\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $metas, PREG_SET_ORDER)) {
            foreach ($metas as $meta) {
                $policies[] = (string) (isset($meta[2]) && $meta[2] !== '' ? $meta[2] : $meta[1]);
            }
        }
        if ($headers === null) {
            $headers = function_exists('headers_list') ? headers_list() : [];
        }
        foreach ((array) $headers as $line) {
            if (preg_match('/^content-security-policy\s*:\s*(.*)$/is', (string) $line, $header)) {
                $policies = array_merge($policies, explode(',', $header[1]));
            }
        }
        foreach ($policies as $policy) {
            $named = [];
            foreach (explode(';', $policy) as $directive) {
                $tokens = preg_split('/\s+/', trim($directive), -1, PREG_SPLIT_NO_EMPTY);
                $name = is_array($tokens) && $tokens !== [] ? strtolower($tokens[0]) : '';
                if (($name === 'img-src' || $name === 'default-src') && !isset($named[$name])) {
                    $named[$name] = array_map('strtolower', array_slice($tokens, 1));
                }
            }
            $sources = isset($named['img-src']) ? $named['img-src'] : (isset($named['default-src']) ? $named['default-src'] : null);
            if ($sources !== null && !in_array('data:', $sources, true)) {
                return true;
            }
        }
        return false;
    }

    /** The placeholder's declarations, each !important: the image, the record's size and position, no-repeat, and origin and clip on the content box, so it fills the box the file paints. */
    public static function decls($uri, $size, $position)
    {
        return 'background-image:url(' . $uri . ')!important;background-size:' . $size . '!important;background-position:' . $position . '!important;'
            . 'background-repeat:no-repeat!important;background-origin:content-box!important;background-clip:content-box!important';
    }

    /** The rule for the one marked <img>. */
    public static function rule($sig, $uri, $size, $position)
    {
        return 'img[' . self::MARK . '="' . $sig . '"]{' . self::decls($uri, $size, $position) . '}';
    }

    /**
     * The style text: the rule under @media (max-width:IMG_LQIP_PHONE_MAX px), the same bytes the
     * service builds. The bound: Chrome sizes an image LCP candidate as min(visible area, intrinsic
     * area), and the placeholder is encoded at about 1.2 times the box of a 412 px read, so it keeps
     * its full size up to 451 px. On a wider box the real file is strictly larger and becomes the LCP,
     * and at low priority alone it would paint later than without this stage; the wide preload covers
     * those viewports.
     */
    public static function css($sig, $uri, $size, $position)
    {
        return '@media (max-width:' . wps_ic_atf_observation::IMG_LQIP_PHONE_MAX . 'px){' . self::rule($sig, $uri, $size, $position) . '}';
    }

    /**
     * The swap script: removes the style after load, decode() and two frames, at once on error, and
     * sets window.wpcImgLqip to 'swapped', 'dropped' or 'none'. A cached file paints over the
     * background in the first frame, so there is no flash; the background is on the same element, so
     * there is no layout shift.
     */
    public static function swapTag($sig)
    {
        return '<script id="' . self::SCRIPT_ID . '" data-nodefer="1">(function(){var d=document,w=window,i=d.querySelector(\'img[' . self::MARK . '="' . $sig . '"]\'),n=0,'
            . 'rm=function(v){if(n)return;n=1;var s=d.getElementById("' . self::STYLE_ID . '");if(s&&s.parentNode)s.parentNode.removeChild(s);w.wpcImgLqip=v},'
            . 'pt=function(){var f=w.requestAnimationFrame;if(typeof f!=="function"){rm("swapped");return}f(function(){f(function(){rm("swapped")})})},'
            . 'ok=function(){if(i.decode){i.decode().then(pt,pt)}else{pt()}};'
            . 'if(!i){rm("none");return}'
            . 'if(i.complete){if(i.naturalWidth){ok()}else{rm("dropped")}return}'
            . 'i.addEventListener("load",ok);i.addEventListener("error",function(){rm("dropped")})})();</script>';
    }

    /**
     * The wide preload: fetchpriority=high under media (min-width:IMG_LQIP_PHONE_MAX+1 px). Above the
     * bound no placeholder paints and the tag's request would stay Low (measured at 915x412 and
     * 768x1024; a script after the tag that raises fetchPriority does not re-prioritise it), so the
     * preload fetches the file at high there and the tag reuses that one request.
     *
     * It names the file the way the tag does, so the browser picks the same candidate for both: href
     * = the tag's src, imagesrcset/imagesizes = its srcset/sizes; in a <picture> the first <source>'s
     * type, srcset and sizes with no href (find() refuses a source with media, so the first source is
     * the one a browser that supports its type picks). crossorigin and referrerpolicy are copied: a
     * preload is reused only under the same request mode.
     *
     * The media is min-width only on purpose: the preload owner classes a min-width-only page preload
     * as desktop and leaves it byte for byte on a phone render, before its veto. A max-width form
     * (not all and (max-width:450px)) is classed mobile and dropped by the veto, and the range form
     * (width > 450px) carries a raw > that ends every <link\b[^>]*> match early, so a later stage
     * reads the link cut before its href; in both cases the later stages found no hero link left.
     */
    public static function preloadTag($tag, $source = null)
    {
        $attr = static function ($from, $name) {
            if (!preg_match('/\s' . $name . '(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?(?=[\s\/>])/i', $from, $m)) {
                return null;
            }
            return count($m) > 1 ? html_entity_decode($m[count($m) - 1], ENT_QUOTES) : '';
        };
        $q = static function ($value) {
            return '"' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        };
        $out = '<link rel="preload" as="image" id="' . self::PRELOAD_ID . '" fetchpriority="high" media="(min-width:' . (wps_ic_atf_observation::IMG_LQIP_PHONE_MAX + 1) . 'px)"';
        $from = $source === null ? $tag : $source;
        if ($source === null) {
            $out .= ' href=' . $q($attr($tag, 'src'));
        } else {
            $out .= ' type=' . $q(strtolower(trim((string) $attr($source, 'type'))));
        }
        $srcset = $attr($from, 'srcset');
        if ($srcset !== null && trim($srcset) !== '') {
            $out .= ' imagesrcset=' . $q($srcset);
            $sizes = $attr($from, 'sizes');
            if ($sizes !== null && trim($sizes) !== '') {
                $out .= ' imagesizes=' . $q($sizes);
            }
        }
        foreach (['crossorigin', 'referrerpolicy'] as $name) {
            $value = $attr($tag, $name);
            if ($value !== null) {
                $out .= ' ' . $name . '=' . $q($value);
            }
        }

        return $out . '>';
    }

    /**
     * Where the wide preload goes: right after the first viewport meta of the head outside the masked
     * regions, else at $headAt. The preload scanner evaluates media when it reaches the tag; before the
     * viewport meta the layout viewport is 980 px, so a link placed there would fetch at high on a
     * phone. hoist_viewport later moves the meta up, never below the link.
     */
    private static function preloadAt($html, $headAt, $before)
    {
        $end = stripos($html, '</head>');
        $end = $end === false ? $before : min($end, $before);
        $masked = self::masked($html);
        if (preg_match_all('/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', substr($html, 0, $end), $metas, PREG_OFFSET_CAPTURE)) {
            foreach ($metas[0] as $meta) {
                if (self::inside($meta[1], $masked) === null) {
                    return $meta[1] + strlen($meta[0]);
                }
            }
        }
        return $headAt;
    }

    /** Byte ranges no tag is read from: noscript, script, template, style, textarea and comments. */
    private static function masked($html)
    {
        $masked = [];
        if (preg_match_all('#<noscript\b[^>]*>.*?</noscript>|<script\b[^>]*>.*?</script>|<template\b[^>]*>.*?</template>|<style\b[^>]*>.*?</style>|<textarea\b[^>]*>.*?</textarea>|<!--.*?-->#is', $html, $regions, PREG_OFFSET_CAPTURE)) {
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

    /** The stage: the four writes and the veto for the record's hero, or the page as it came with the receipt's reason. */
    public static function stage($html, $ctx)
    {
        if (!is_string($html) || $html === '' || !class_exists('wps_ic_atf_observation')) {
            return $html;
        }
        $note = static function (array $fields) {
            if (function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('img-lqip', $fields, $fields['state'] === 'skipped');
            }
        };
        // One placeholder per page: a page that already carries this stage's style or marker is left alone.
        if (strpos($html, 'id="' . self::STYLE_ID . '"') !== false || strpos($html, self::MARK . '=') !== false) {
            $note(['state' => 'skipped', 'why' => 'present']);
            return $html;
        }
        $record = wps_ic_atf_observation::imgLqip('mobile');
        // No record to read (the cohort is off, or not a phone read): nothing to say.
        if ($record === []) {
            return $html;
        }
        if ($record['state'] !== 'ok') {
            $note(['state' => $record['state'] === 'skipped' ? 'skipped' : 'refused', 'why' => $record['why']]);
            return $html;
        }
        // One placeholder per page: the background placeholder (hero_lqip), written in this render or
        // already in the page, wins.
        if ((isset($ctx->heroLqip) && $ctx->heroLqip !== null) || strpos($html, 'id="wpc-hero-lqip"') !== false) {
            $note(['state' => 'unapplied', 'why' => 'hero-lqip']);
            return $html;
        }
        // The gate read the response headers; this reads the page's own meta policies too.
        $csp = self::cspRefusal($html);
        if ($csp !== '') {
            $note(['state' => 'unapplied', 'why' => $csp]);
            return $html;
        }
        // The style goes right after the crit block, else before </head>; with no head the page is not edited.
        if (preg_match('#<style\b[^>]*\bid=["\']wpc-critical-css["\'][^>]*>.*?</style>#is', $html, $crit, PREG_OFFSET_CAPTURE)) {
            $headAt = $crit[0][1] + strlen($crit[0][0]);
        } else {
            $headAt = stripos($html, '</head>');
            if ($headAt === false) {
                $note(['state' => 'unapplied', 'why' => 'no-head']);
                return $html;
            }
        }
        $found = self::find($html, $record);
        if ($found['why'] !== '') {
            $note(['state' => 'unapplied', 'why' => $found['why']]);
            return $html;
        }
        // A tag before the style's position (in the head) is refused.
        if ($found['at'] < $headAt) {
            $note(['state' => 'unapplied', 'why' => 'in-head']);
            return $html;
        }
        $tag = self::edit($found['tag'], $record['sig']);
        $style = '<style id="' . self::STYLE_ID . '">' . self::css($record['sig'], $record['uri'], $record['size'], $record['position']) . '</style>';
        $preload = self::preloadTag($found['tag'], $found['source']);
        $preloadAt = self::preloadAt($html, $headAt, $found['at']);
        $end = $found['at'] + strlen($found['tag']);
        $html = substr($html, 0, $headAt) . ($preloadAt === $headAt ? $preload : '') . $style . substr($html, $headAt, $found['at'] - $headAt) . $tag . self::swapTag($record['sig']) . substr($html, $end);
        if ($preloadAt !== $headAt) {
            $preloadAt += $preloadAt > $headAt ? strlen($style) : 0;
            $html = substr($html, 0, $preloadAt) . $preload . substr($html, $preloadAt);
        }

        // Every image preload of the hero's picture is vetoed (the record's file and every name the tag
        // and its sources carry, all sizes): at phone widths the tag's request must stay Low with the
        // placeholder as the LCP. By the veto's contract the preload owner also keeps a page-written
        // preload of the picture out; the wide preload survives as a desktop-classed page preload.
        $keys = [];
        foreach (array_merge([$record['url']], $found['names']) as $address) {
            $key = class_exists('wps_ic_image_preload_set') ? wps_ic_image_preload_set::imageKey($address) : '';
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        if (isset($ctx->imagePreloads) && is_object($ctx->imagePreloads)) {
            foreach (array_keys($keys) as $key) {
                $ctx->imagePreloads->veto($key, 'img-lqip');
            }
        }
        $note(['state' => 'applied', 'bytes' => (int) $record['bytes'], 'lazy' => (int) (bool) preg_match('/\sloading\s*=\s*(["\']?)lazy\1(?=[\s\/>])/i', $found['tag']), 'vetoed' => count($keys)]);

        return $html;
    }

    /**
     * The one served <img> the record names, or ['why' => reason]. The tag is found by the record's
     * picture identity (match.key: any size of the upload, heroLqipFile($u, true)), never by the
     * record's selector, which is diagnostic only. Tags inside the masked regions are not counted.
     * Each refusal below is a shape the placeholder cannot be proven to cover.
     */
    public static function find($html, array $record)
    {
        $key = (string) $record['key'];
        $masked = self::masked($html);
        $inside = static function ($at, array $ranges) {
            return self::inside($at, $ranges);
        };
        $pictures = [];
        if (preg_match_all('#<picture\b[^>]*>.*?</picture>#is', $html, $blocks, PREG_OFFSET_CAPTURE)) {
            foreach ($blocks[0] as $block) {
                if ($inside($block[1], $masked) === null) {
                    $pictures[] = [$block[1], $block[1] + strlen($block[0]), $block[0]];
                }
            }
        }
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags, PREG_OFFSET_CAPTURE)) {
            return ['why' => 'not-found'];
        }
        $hits = [];
        foreach ($tags[0] as $one) {
            if ($inside($one[1], $masked) !== null) {
                continue;
            }
            $picture = $inside($one[1], $pictures);
            $sources = $picture === null ? [] : self::sources($picture[2]);
            $names = self::names($one[0]);
            foreach ($sources as $source) {
                $names = array_merge($names, $source['names']);
            }
            foreach ($names as $address) {
                if (wps_ic_atf_observation::heroLqipFile($address, true) === $key) {
                    $hits[] = ['at' => $one[1], 'tag' => $one[0], 'picture' => $picture !== null, 'sources' => $sources, 'names' => $names];
                    break;
                }
            }
        }
        // Exactly one tag may name the picture: a file used twice has no single box to paint.
        if (count($hits) !== 1) {
            return ['why' => $hits === [] ? 'not-found' : 'multi-use'];
        }
        $hit = $hits[0];
        $tag = $hit['tag'];
        $attr = static function ($name) use ($tag) {
            return preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $m) ? html_entity_decode($m[count($m) - 1], ENT_QUOTES) : null;
        };
        $src = $attr('src');
        // The tag's own src must be the record's picture.
        if ($src === null || stripos(ltrim($src), 'data:') === 0 || wps_ic_atf_observation::heroLqipFile($src, true) !== $key) {
            return ['why' => 'src'];
        }
        // A JS-lazy tag, whose real file waits in a data- attribute, is refused.
        if ($attr('data-src') !== null || $attr('data-lazy-src') !== null || $attr('data-srcset') !== null || $attr('data-lazy-srcset') !== null) {
            return ['why' => 'lazy-src'];
        }
        $srcset = $attr('srcset');
        // Every srcset candidate must be the same picture, so whichever the phone picks is the one the
        // placeholder stands for.
        if ($srcset !== null) {
            foreach (self::candidates($srcset) as $candidate) {
                if (wps_ic_atf_observation::heroLqipFile($candidate, true) !== $key) {
                    return ['why' => 'mixed-srcset'];
                }
            }
        }
        $style = $attr('style');
        // The placeholder is written as the tag's background; a tag with its own inline background is refused.
        if ($style !== null && stripos($style, 'background') !== false) {
            return ['why' => 'own-background'];
        }
        // A tag a lazy library or a slider owns is refused: the lazy verdict's library words, its slider
        // pattern, or data-wpc-slide-lazy. Only the tag itself is read; the ancestor-aware slider skip is
        // the service's.
        $class = strtolower((string) $attr('class'));
        $words = array_filter((array) preg_split('/\s+/', trim($class)), 'strlen');
        if (stripos($tag, 'data-wpc-slide-lazy') !== false
            || (class_exists('wps_ic_lazy_verdict') && (array_intersect($words, wps_ic_lazy_verdict::LIBRARY_CLASS_WORDS) || preg_match(wps_ic_lazy_verdict::SLIDER_ENGINE_PATTERN, $class)))) {
            return ['why' => 'lib-owned'];
        }
        $sizes = $attr('sizes');
        // sizes="auto": on a lazy tag the eager flip would break the sizes contract; on an eager tag the
        // preload's imagesizes has no auto, so the preload and the tag could pick different files.
        if ($sizes !== null && stripos(ltrim($sizes), 'auto') === 0) {
            return ['why' => 'sizes-auto'];
        }
        // The record says whether the measured tag sat in a <picture>; a served tag of another shape is refused.
        if ($hit['picture'] !== (bool) $record['picture']) {
            return ['why' => 'picture'];
        }
        // No art direction (a source with media) and no source naming another picture.
        foreach ($hit['sources'] as $source) {
            if ($source['media']) {
                return ['why' => 'art-direction'];
            }
            foreach ($source['names'] as $address) {
                if (wps_ic_atf_observation::heroLqipFile($address, true) !== $key) {
                    return ['why' => 'mixed-sources'];
                }
            }
        }
        // In a <picture> the first source must be type="image/avif": hero_same_origin renames only the
        // AVIF arm of a picture (and an image preload alike), so with any other first arm the preload and
        // the painted file could part.
        $first = $hit['picture'] && $hit['sources'] !== [] ? $hit['sources'][0] : null;
        if ($first !== null && $first['type'] !== 'image/avif') {
            return ['why' => 'picture-type'];
        }

        return ['at' => $hit['at'], 'tag' => $tag, 'source' => $first === null ? null : $first['tag'], 'names' => array_values(array_unique($hit['names'])), 'why' => ''];
    }

    /**
     * The tag as served with the placeholder: every earlier fetchpriority (lcp_hint's high included)
     * removed, loading="lazy" turned eager, and the marker plus fetchpriority="low" written after the
     * tag name. The only place the stage writes low; stage() calls it after every refusal. The flip to
     * eager is needed because the earlier passes do not always make the hero eager: lcp_hint only when
     * its option names the hero, and the lazy verdict's keep cases can leave a theme's lazy.
     */
    public static function edit($tag, $sig)
    {
        $bare = preg_replace('/\sfetchpriority\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        $bare = is_string($bare) ? $bare : $tag;
        $eager = preg_replace('/\sloading\s*=\s*(["\']?)lazy\1(?=[\s\/>])/i', ' loading="eager"', $bare);
        $eager = is_string($eager) ? $eager : $bare;
        $out = preg_replace('/^<img\b/i', '<img ' . self::MARK . '="' . $sig . '" fetchpriority="low"', $eager, 1);

        return is_string($out) ? $out : $tag;
    }

    /** Every address a tag names: src, srcset and their data- lazy twins, srcset split into candidates. */
    private static function names($tag)
    {
        $names = [];
        if (preg_match_all('/\s(src|data-src|data-lazy-src|srcset|data-srcset|data-lazy-srcset)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $value = html_entity_decode($m[count($m) - 1], ENT_QUOTES);
                if (stripos($m[1], 'srcset') !== false) {
                    $names = array_merge($names, self::candidates($value));
                } elseif ($value !== '') {
                    $names[] = $value;
                }
            }
        }
        return $names;
    }

    /** The <source>s of a <picture>: tag, whether it carries a media other than all, type and names. */
    private static function sources($block)
    {
        $out = [];
        if (preg_match_all('/<source\b[^>]*>/i', $block, $found)) {
            foreach ($found[0] as $source) {
                $media = preg_match('/\smedia\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $source, $m) ? strtolower(trim(html_entity_decode($m[count($m) - 1], ENT_QUOTES))) : '';
                $type = preg_match('/\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $source, $t) ? strtolower(trim(html_entity_decode($t[count($t) - 1], ENT_QUOTES))) : '';
                $out[] = ['tag' => $source, 'media' => $media !== '' && $media !== 'all', 'type' => $type, 'names' => self::names(' ' . substr($source, 7))];
            }
        }
        return $out;
    }

    /** The addresses of a srcset, descriptors dropped. */
    private static function candidates($srcset)
    {
        $out = [];
        foreach (explode(',', (string) $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if (is_array($parts) && isset($parts[0]) && $parts[0] !== '') {
                $out[] = $parts[0];
            }
        }
        return $out;
    }
}
