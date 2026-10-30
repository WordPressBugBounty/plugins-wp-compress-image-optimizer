<?php
/**
 * Theme and plugin image url() refs in first-party CSS, moved onto the CDN zone (policy cohort
 * theme_bg_cdn, off unless the service sends it).
 *
 * Pure: no WordPress call, no I/O. The caller (wps_cdn_rewrite::wpc_theme_bg_zoneify) passes the
 * site, the zone, the gates and the exclude test. Rewrites ONLY:
 *   - url() inside background / background-image / border-image(-source) / list-style(-image)
 *     declarations (never mask, filter, cursor, src, or a custom property: mask-image needs CORS,
 *     a font src is the font lane's);
 *   - nothing inside @font-face or a comment;
 *   - refs that resolve to the site's own host (absolute, protocol-relative, root-relative, and
 *     relative when the caller gives the sheet's URL) under one of the allowed directories
 *     (default /wp-content/themes/ and /wp-content/plugins/; uploads belong to the existing lanes);
 *   - the extensions the caller allows (png, jpg, jpeg, webp, avif), never fonts, svg or gif;
 *   - in natural form only (the zone host, the same path): never a transform URL;
 *   - never data:, blob:, #fragment, a ref with escapes or whitespace, or one the exclude test names.
 * Relative refs with no base are left alone: in an inline <style> they resolve against the page,
 * and this pass never changes what a ref means.
 */

if (!class_exists('WPC_Css_Url_Zoneify')) {
    class WPC_Css_Url_Zoneify
    {
        const DEFAULT_DIRS = ['/wp-content/themes/', '/wp-content/plugins/'];
        // The value runs to the declaration's end: quoted strings and (recursively) balanced parens are
        // taken whole, so a ';' inside a data: URI or an image-set(url() type()) never ends it early.
        const DECL_RE = '/(?<![\w-])((?:-webkit-)?(?:background-image|background|border-image-source|border-image|list-style-image|list-style))(\s*:\s*)((?:[^;{}"\'()]|"[^"]*"|\'[^\']*\'|(?<paren>\((?:[^()"\']|"[^"]*"|\'[^\']*\'|(?&paren))*\)))*)/i';
        const URL_RE = '/url\(\s*(["\']?)([^"\')]*?)\1\s*\)/i';

        /**
         * @param string $css
         * @param array  $o   site_host, zone (required); base_url ('' = relative refs untouched); site_path ('' or
         *                    '/sub'); dirs; exts; excluded (callable(string $absUrl): bool). The zone form is always
         *                    natural (zone host, same path): never a transform URL (hub ask 087, plain_paths).
         * @param int    $count refs rewritten
         * @return string
         */
        public static function rewrite($css, array $o, &$count = 0)
        {
            $count = 0;
            if (!is_string($css) || $css === '' || stripos($css, 'url(') === false) {
                return is_string($css) ? $css : '';
            }
            $site = strtolower(trim((string) ($o['site_host'] ?? '')));
            $zone = strtolower(trim((string) ($o['zone'] ?? '')));
            if ($site === '' || $zone === '' || $site === $zone) {
                return $css;
            }
            $exts = array_map('strtolower', (array) ($o['exts'] ?? ['png', 'jpg', 'jpeg', 'webp', 'avif']));
            // Never fonts, svg or gif, whatever the caller passes.
            $exts = array_values(array_diff($exts, ['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg', 'gif']));
            if ($exts === []) {
                return $css;
            }
            $sitePath = rtrim((string) ($o['site_path'] ?? ''), '/');
            $dirs = [];
            foreach ((array) ($o['dirs'] ?? self::DEFAULT_DIRS) as $d) {
                $d = '/' . trim((string) $d, '/') . '/';
                if ($d !== '//' && stripos($d, '/uploads/') === false) {
                    $dirs[] = $sitePath . $d;
                }
            }
            if ($dirs === []) {
                return $css;
            }
            $ctx = [
                'site' => $site,
                'zone' => $zone,
                'base' => (string) ($o['base_url'] ?? ''),
                'dirs' => $dirs,
                'exts' => $exts,
                'excluded' => isset($o['excluded']) && is_callable($o['excluded']) ? $o['excluded'] : null,
            ];

            // Park comments and @font-face blocks: nothing inside them is touched.
            $parked = [];
            $masked = preg_replace_callback('#/\*.*?\*/|@font-face\s*\{[^}]*\}#is', function ($m) use (&$parked) {
                $k = "\x01WPCTB" . count($parked) . "\x01";
                $parked[$k] = $m[0];
                return $k;
            }, $css);
            if (!is_string($masked)) {
                return $css;
            }

            $n = 0;
            $out = preg_replace_callback(self::DECL_RE, function ($d) use ($ctx, &$n) {
                if (stripos($d[3], 'url(') === false) {
                    return $d[0];
                }
                $value = preg_replace_callback(self::URL_RE, function ($u) use ($ctx, &$n) {
                    $to = self::zone_url($u[2], $ctx);
                    if ($to === null) {
                        return $u[0];
                    }
                    $n++;
                    return 'url(' . $u[1] . $to . $u[1] . ')';
                }, $d[3]);
                return is_string($value) ? $d[1] . $d[2] . $value : $d[0];
            }, $masked);
            if (!is_string($out)) {
                return $css;
            }
            if ($parked !== []) {
                $out = strtr($out, $parked);
            }
            $count = $n;
            return $n > 0 ? $out : $css;
        }

        /** True when the sheet has at least one url() this pass could take (cheap pre-check). */
        public static function has_candidate($css, array $exts = ['png', 'jpg', 'jpeg', 'webp', 'avif'])
        {
            if (!is_string($css) || stripos($css, 'url(') === false) {
                return false;
            }
            $alt = implode('|', array_map(function ($e) { return preg_quote((string) $e, '#'); }, $exts));
            return $alt !== '' && (bool) preg_match('#url\(\s*["\']?[^"\')]*\.(?:' . $alt . ')(?:[?\#][^"\')]*)?["\']?\s*\)#i', $css);
        }

        /** The zone form of one ref, or null to leave it. */
        public static function zone_url($ref, array $ctx)
        {
            $ref = trim((string) $ref);
            if ($ref === '' || preg_match('#^(?:data:|blob:|about:|javascript:|\#)#i', $ref) || preg_match('#[\s\\\\<>]#', $ref)) {
                return null;
            }
            $host = '';
            $rest = '';
            if (preg_match('#^(?:(https?):)?//([^/?\#]+)(.*)$#i', $ref, $m)) {
                $host = strtolower($m[2]);
                $rest = $m[3] === '' ? '/' : $m[3];
            } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
                return null;
            } elseif ($ref[0] === '/') {
                $host = $ctx['site'];
                $rest = $ref;
            } else {
                if ($ctx['base'] === '') {
                    return null;
                }
                $abs = self::resolve($ref, $ctx['base']);
                if ($abs === null || !preg_match('#^https?://([^/?\#]+)(.*)$#i', $abs, $m)) {
                    return null;
                }
                $host = strtolower($m[1]);
                $rest = $m[2];
            }
            if ($host === $ctx['zone'] || $host !== $ctx['site']) {
                return null;
            }
            $path = (string) preg_replace('#[?\#].*$#', '', $rest);
            $tail = (string) substr($rest, strlen($path));
            if ($path === '' || $path[0] !== '/') {
                return null;
            }
            $path = self::remove_dots($path);
            if ($path === null) {
                return null;
            }
            $inDir = false;
            foreach ($ctx['dirs'] as $d) {
                if (stripos($path, $d) === 0) {
                    $inDir = true;
                    break;
                }
            }
            if (!$inDir) {
                return null;
            }
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, $ctx['exts'], true)) {
                return null;
            }
            $originUrl = 'https://' . $ctx['site'] . $path . $tail;
            if ($ctx['excluded'] !== null && call_user_func($ctx['excluded'], $originUrl)) {
                return null;
            }
            return 'https://' . $ctx['zone'] . $path . $tail;
        }

        /** A relative ref resolved against the sheet URL (RFC 3986 merge + dot removal), or null. */
        public static function resolve($ref, $base)
        {
            if (!preg_match('#^(https?:)?//([^/?\#]+)([^?\#]*)#i', (string) $base, $b)) {
                return null;
            }
            $scheme = $b[1] !== '' ? $b[1] : 'https:';
            $dir = $b[3] === '' ? '/' : (string) preg_replace('#/[^/]*$#', '/', $b[3]);
            $path = (string) preg_replace('#[?\#].*$#', '', $ref);
            $tail = (string) substr($ref, strlen($path));
            $merged = self::remove_dots($dir . $path);
            return $merged === null ? null : $scheme . '//' . $b[2] . $merged . $tail;
        }

        /** Dot-segment removal; null when the path climbs above the root or is percent-encoded dots. */
        public static function remove_dots($path)
        {
            if (preg_match('#%2e#i', $path)) {
                return null;
            }
            $out = [];
            $segs = explode('/', $path);
            $last = count($segs) - 1;
            foreach ($segs as $i => $s) {
                if ($s === '.') {
                    if ($i === $last) {
                        $out[] = '';
                    }
                    continue;
                }
                if ($s === '..') {
                    if (count($out) <= 1) {
                        return null;
                    }
                    array_pop($out);
                    if ($i === $last) {
                        $out[] = '';
                    }
                    continue;
                }
                $out[] = $s;
            }
            $r = implode('/', $out);
            return ($r === '' || $r[0] !== '/') ? '/' . ltrim($r, '/') : $r;
        }
    }
}
