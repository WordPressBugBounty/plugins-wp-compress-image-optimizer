<?php
/**
 * Negotiated Delivery — emit one plain <img> (no <picture>) with clean .webp native URLs.
 *
 * Delivery model: src + every srcset entry is a clean .webp URL (e.g. .../photo-768x1042.webp).
 * The Bunny edge Accept-varies the .webp URL into 3 cache buckets (avif/webp/legacy) and the
 * container serves real bytes down an AVIF→WebP→JPEG ladder, so an avif browser still gets AVIF
 * bytes under the .webp URL — browsers render by Content-Type, not extension. Result: theme-safe
 * (no wrap), correct-per-browser, edge-cached + bucketed, no redirect, page-cache-safe.
 *
 * Why .webp and not .avif: Bunny's Accept-vary cache only covers jpg/jpeg/webp/png/gif. A .avif
 * URL is URL-keyed (no vary) so the first format served poisons all Accepts. .webp is the stable
 * vary-eligible cache key the container negotiates under.
 *
 * Safety: gated by EMISSION_READY + the resolver. While inactive the buffer early-return never
 * fires, so existing <picture> delivery is unchanged. rewrite_buffer() is also wrapped in
 * try/catch — any error returns the buffer untouched, so a bug here can't blank a page.
 *
 * Cutover prerequisites: the caller must still run the non-image rewriters (CSS bg-images, fonts,
 * JS, critical-CSS) AFTER this img pass — this module touches <img> only. PZ config needs
 * EnableAvifVary/EnableWebPVary on, SmartCache + Forward-Host off, apikey→origin authed fetch.
 * The -WxH siblings come from normal compress + lazy backfill; the downgrade ladder covers any
 * not-yet-encoded format.
 *
 * KILL-SWITCH: define('WPC_NEGOTIATED_KILL', true) → instant global revert to <picture>.
 */
if (!defined('ABSPATH')) exit;

class WPC_Negotiated_Delivery
{
    /**
     * Baseline GA gate, ships ON. Overridable per-site (WPC_NEGOTIATED_GA const / wpc_negotiated_ga
     * option / the Next-Gen card — see emission_ready()). Default-ON is safe because the resolver is
     * promote-on-proof: negotiated only activates where a live loopback verify proves CDN-edge; elsewhere
     * it stays on legacy <picture>/jpeg. KILL or the card's "Off" instantly revert. Set false for a
     * conservative opt-in-via-card GA.
     */
    const EMISSION_READY = true;

    /** Marker so the non-image rewriters / a second pass never re-touch our output. */
    const MARK = 'data-wpc-nd';

    /**
     * Per-page <img> counter for positional native-lazy injection (reset atop rewrite_buffer()).
     * First $skipFirst images = eager (LCP region), the rest = native loading="lazy".
     */
    private static $img_index = 0;

    /**
     * JPEG-NATURAL mode flag. When the edge is proven (verify.cdn.ok) but the next-gen ceiling is OFF,
     * we still emit clean natural URLs but keep the ORIGINAL extension (.jpg/.png) — a .jpg zone URL
     * returns image/jpeg to every Accept (no up-negotiation), so the visitor gets the optimized on-disk
     * jpg with no transform URL and no optimizer JS. Set per-request atop rewrite_buffer(); read by
     * build_natural_url(). Mutually exclusive with the webp/avif negotiated path (is_active()).
     */
    private static $jpeg_mode = false;

    /**
     * Mode-B flag. build_natural_url() appends ?_wpc_m=r&_redirect_target=origin so the edge
     * 302-redirects each image to the customer ORIGIN (origin serves the bytes, ~99% Bunny BW). Set by
     * the ?wpc_delivery=modeb test force OR by edge_origin_active() (resolver-proven Mode-B). The tokens
     * are constant → one stable cache key per image.
     */
    private static $modeb_test = false;

    /**
     * GA gate, overridable without a code deploy so the cutover is a per-site/per-cohort flip.
     * Precedence: WPC_NEGOTIATED_GA const → wpc_negotiated_ga option → Next-Gen card → EMISSION_READY.
     * The resolver still gates per-site safety on top of this — this only says "feature is GA here".
     */
    public static function emission_ready()
    {
        // wp-config const wins — for QA + emergencies.
        if (defined('WPC_NEGOTIATED_GA')) return (bool) WPC_NEGOTIATED_GA;
        if (function_exists('get_option')) {
            // The cohort/rollout flip — only honoured when explicitly set, regardless of the UI.
            $o = get_option('wpc_negotiated_ga', null);
            if ($o !== null && $o !== false && $o !== '') {
                return ($o === '1' || $o === 1 || $o === true);
            }
            // The Next-Gen Images card is the user-facing enable: WebP/AVIF/Auto turns negotiated ON,
            // 'Off' turns it off. This is what makes a plain zip-install enableable from the UI with no
            // backend flag; the resolver still verifies + falls back to <picture>/jpeg if unproven.
            if (defined('WPS_IC_SETTINGS')) {
                $s = get_option(WPS_IC_SETTINGS);
                if (is_array($s) && isset($s['wpc_nextgen']) && $s['wpc_nextgen'] !== '') {
                    $ng = strtolower((string) $s['wpc_nextgen']);
                    if ($ng === 'off') return false;
                    if ($ng === 'webp' || $ng === 'avif' || $ng === 'auto') return true;
                }
            }
        }
        // Never-configured installs (card untouched) stay inert.
        return self::EMISSION_READY;
    }

    /**
     * Test-mode per-request delivery override (the "5th axis": Edge Negotiate). Inert in production:
     * only reads ?wpc_delivery=… when the test flag is explicitly on (wpc_delivery_test_mode='1' or
     * const WPC_DELIVERY_TEST_MODE). Lets a tester A/B paths in the browser without changing a saved
     * setting or disturbing the resolver's promote-on-proof state — a per-request emitter decision read
     * by is_active()/is_active_jpeg().
     *   edge    → force the negotiated webp/avif clean-URL path (needs a zone to negotiate against)
     *   natural → force the jpeg-natural clean-.jpg path
     *   legacy  → force the /wp:N/ transform + <picture> path (stand both clean paths down)
     *   modeb   → like 'edge' but appends ?_wpc_m=r&_redirect_target=origin so the edge 302s each image
     *             to the customer ORIGIN. TEST-ONLY: prod uses the per-zone enabler #7
     *             (wpc_edge_origin_bytes → redirect_target=origin) on bare URLs instead.
     * Anything else / absent → null = normal resolver decision. Request-static cached.
     */
    public static function test_force_mode()
    {
        static $resolved = false, $mode = null;
        if ($resolved) return $mode;
        $resolved = true;
        if (!function_exists('get_option') || empty($_GET['wpc_delivery'])) return $mode;
        $enabled = (defined('WPC_DELIVERY_TEST_MODE') && WPC_DELIVERY_TEST_MODE);
        if (!$enabled) {
            $o = get_option('wpc_delivery_test_mode', '');
            $enabled = ($o === '1' || $o === 1 || $o === true);
        }
        if (!$enabled) return $mode;
        $m = strtolower(preg_replace('/[^a-z]/', '', (string) $_GET['wpc_delivery']));
        if (in_array($m, ['edge', 'legacy', 'natural', 'modeb'], true)) $mode = $m;
        return $mode;
    }

    /**
     * Images-master gate. The "Images" tile is the master switch for image CDN delivery: it binds to
     * serve[jpg] but drives ALL image formats. When OFF, image delivery stands down (served from origin)
     * — matching the model "Images off ⇒ no images on the CDN at all". Next-Gen only decides the FORMAT
     * when images are actually on the CDN. Pass $s to reuse an already-loaded settings copy.
     */
    public static function cdn_images_enabled($s = null)
    {
        if ($s === null) {
            if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) return false;
            $s = get_option(WPS_IC_SETTINGS);
        }
        if (!is_array($s) || empty($s['serve']) || !is_array($s['serve'])) return false;
        foreach (['jpg', 'png', 'gif', 'svg'] as $k) {
            if (!empty($s['serve'][$k]) && (string) $s['serve'][$k] === '1') return true;
        }
        return false;
    }

    public static function is_active()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        // Context bypasses — never rewrite in admin/ajax/cron/feed/amp/rest.
        if (is_admin()) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (function_exists('is_feed') && is_feed()) return false;
        if (function_exists('amp_is_request') && amp_is_request()) return false;
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;

        // Test-mode override (runs before emission_ready so a tester can force edge on a next-gen-off
        // site). 'edge' → on (needs a zone); any other forced mode stands this path down.
        $forced = self::test_force_mode();
        if ($forced === 'edge' || $forced === 'modeb') return self::cdn_host() !== ''; // both emit clean .webp; modeb adds origin-302 tokens
        if ($forced !== null)   return false;

        // Images-master gate (test-mode forces above bypass it so testers can still force edge).
        if (!self::cdn_images_enabled()) return false;

        if (!self::emission_ready()) return false;                        // master GA switch (overridable)

        // Emergency force-off filter (defaults true — resolver remains the real gate).
        if (!apply_filters('wpc_negotiated_delivery_enabled', true)) return false;

        // Unified per-zone suppression gate — emit ZERO CDN URLs on a suppressed zone. CRITICAL: the
        // resolver's cap-merge only carries cdn_disabled / auto_disabled, so resolve() can still return
        // TIER_CDN_EDGE on an UNCONFIRMED env (fresh install, staging clone, CF-cname not yet live) whose
        // self-test verify happened to pass — and is_active() would then emit natural .webp/.avif URLs the
        // edge 404s (zone not yet OTF-provisioned). wpc_v2_zone_cdn_suppressed() ALSO covers
        // wpc_v2_provision_env_changed() + the cf-cname-wait, which resolve() does not. This is the gate
        // every other emission surface already checks (cdn-rewrite mainInit, is_active_jpeg, modern-delivery);
        // the WebP/AVIF negotiated lane was the one exception — this closes it so an unprovisioned zone
        // fails safe to origin (no 404) until it's genuinely verified + un-suppressed.
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) return false;

        // Activate only where the resolver has verified the CDN-edge tier for this site.
        if (!class_exists('WPC_Delivery_Resolver')) return false;
        return WPC_Delivery_Resolver::resolve() === WPC_Delivery_Resolver::TIER_CDN_EDGE;
    }

    /**
     * Sibling gate for the JPEG-NATURAL clean-URL path (Next-Gen OFF + proven CDN-edge).
     *
     * With next-gen OFF the webp/avif path is inert, but if the edge is proven (verify.cdn.ok) and CDN
     * is live we can still replace the legacy transform-URL + optimizer-JS path with clean .jpg URLs the
     * edge serves straight from the on-disk optimized jpg (a .jpg URL returns image/jpeg to every Accept
     * — no up-negotiation). Same promote-on-proof discipline as is_active(); elsewhere the legacy /wp:N/
     * transform path runs untouched. MUTUALLY EXCLUSIVE with is_active() — a zone is either webp/avif
     * negotiated (ceiling != off) or jpeg-natural (ceiling == off), never both.
     */
    public static function is_active_jpeg()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        // Same context bypasses as is_active() — never rewrite in admin/ajax/cron/feed/amp/rest.
        if (is_admin()) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (function_exists('is_feed') && is_feed()) return false;
        if (function_exists('amp_is_request') && amp_is_request()) return false;
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;

        // Test-mode override: 'natural' forces THIS path on; 'edge'/'legacy' stand it down.
        $forced = self::test_force_mode();
        if ($forced === 'natural') return self::cdn_host() !== '';
        if ($forced !== null)      return false;

        // Emergency force-off: the shared negotiated kill-filter + a jpeg-natural-specific one.
        if (!apply_filters('wpc_negotiated_delivery_enabled', true)) return false;
        if (!apply_filters('wpc_jpeg_natural_enabled', true)) return false;

        if (!class_exists('WPC_Delivery_Resolver')) return false;
        if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) return false;
        $s = get_option(WPS_IC_SETTINGS);
        if (!is_array($s)) return false;

        // Images-master gate: jpeg-natural images also stand down when image CDN is off.
        if (!self::cdn_images_enabled($s)) return false;

        // JPEG-natural is ONLY for the Next-Gen-OFF ceiling. webp/avif go through is_active().
        if (WPC_Delivery_Resolver::effective_ceiling($s) !== 'off') return false;

        // CDN must be live — clean zone URLs only make sense with the CDN actually on.
        if (empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') return false;

        // Respect the per-zone master kill (cdn_disabled / auto-disable) — emit ZERO CDN URLs then.
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) return false;

        // Require a PROVEN CDN edge: a zone that negotiates .webp certainly serves a plain .jpg.
        // resolve_verbose() returns the cached verify on the front-end (no probe — never blocks a render).
        $v = WPC_Delivery_Resolver::resolve_verbose();
        return is_array($v) && isset($v['verify']['cdn']['ok']) && $v['verify']['cdn']['ok'] === true;
    }

    /**
     * Resolve the customer CDN host: CF cname (when CF cdn on) → ic_custom_cname → ic_cdn_zone_name → ''.
     * Mirrors WPC_Modern_Delivery's precedence so no zapwp/Bunny-isms leak into the HTML.
     *
     * CF cname goes first when CF cdn is on: a CF-front site is fetched by the browser on the proxied CF
     * cname, a DIFFERENT host from the underlying Bunny pod. If we returned the Bunny zone instead, both
     * the emission and the resolver's verify probe would point at the pod the browser never hits — a
     * false-negative witness that keeps the zone off natural delivery. Matches the healthcheck.
     */
    public static function cdn_host()
    {
        $cf_cname  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $cf_set    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        $cf_cdn_on = is_array($cf_set) && !empty($cf_set['settings']['cdn']);
        if ($cf_cname !== '' && $cf_cdn_on) return $cf_cname;

        $cname = trim((string) get_option('ic_custom_cname'));
        if ($cname !== '') return $cname;
        return trim((string) get_option('ic_cdn_zone_name'));
    }

    /**
     * TRUE when the resolver has proven CDN-edge with redirect_target='origin' (the "Edge negotiate"
     * radio with CDN-bytes off: the zone 302-negotiates and the ORIGIN serves the bytes — Mode-B).
     * Drives the production token append in build_natural_url(). resolve_verbose() is front-end-cached.
     */
    public static function edge_origin_active()
    {
        if (!class_exists('WPC_Delivery_Resolver')) return false;
        $rv = WPC_Delivery_Resolver::resolve_verbose();
        return is_array($rv) && isset($rv['tier'], $rv['redirect_target'])
            && (int) $rv['tier'] === WPC_Delivery_Resolver::TIER_CDN_EDGE
            && $rv['redirect_target'] === 'origin';
    }

    /**
     * Buffer-level rewrite. Rewrites each eligible <img> to a single plain <img> with .webp native URLs.
     * Touches <img> ONLY — the caller must still run the CSS/font/JS rewriters. try/catch → on any error
     * returns the buffer unchanged (never blank a page).
     */
    public static function rewrite_buffer($html)
    {
        try {
            // Page-context guards: reuse the legacy gate verbatim (single source of truth).
            if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'dontRunif')
                && !wps_cdn_rewrite::dontRunif()) {
                return $html;
            }

            // Skip front-end AJAX/fragment responses (load-more, JetEngine "AJAX Replace", filters):
            // they aren't full documents, and emitting natural-.webp into a partial that gets
            // innerHTML'd is wrong. Only the negotiated pass returns early — the legacy CDN pass
            // downstream still rewrites these fragment <img>s with its /wp:N/ transform URLs. Same
            // trigger as that legacy branch: a front-end POST carrying an 'action'.
            if (!empty($_POST['action'])) {
                return $html;
            }

            // Reset the per-page <img> counter that drives positional native-lazy injection.
            self::$img_index = 0;

            // Decide the URL flavour for THIS render. The caller invokes us when either is_active()
            // (webp/avif negotiated) OR is_active_jpeg() (Next-Gen-off clean .jpg). jpeg_mode is true
            // only on the latter; build_natural_url() reads it to keep the original extension instead of
            // swapping to .webp. Mutually exclusive — is_active() wins if both.
            self::$jpeg_mode = (!self::is_active() && self::is_active_jpeg());
            // Token append: edge_origin_active() is true when the resolver proved CDN_EDGE with
            // redirect_target='origin' (Mode-B). Constant tokens = one stable cache key per image;
            // unreachable on live-cdn=1 and without a mode_b-proven verify.
            self::$modeb_test = (self::test_force_mode() === 'modeb') || self::edge_origin_active();

            // Protect <noscript> and any pre-existing <picture> from being touched.
            $picture_stash = [];
            $html = preg_replace_callback('/<noscript\b[^>]*>.*?<\/noscript>/is', function ($m) use (&$picture_stash) {
                $i = '___WPCND_NOSCRIPT_' . count($picture_stash) . '___';
                $picture_stash[$i] = $m[0];
                return $i;
            }, $html);
            $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$picture_stash) {
                $i = '___WPCND_PICTURE_' . count($picture_stash) . '___';
                $picture_stash[$i] = $m[0];
                return $i;
            }, $html);

            // Negative lookbehind on quote: don't match <img> inside an attribute string.
            $html = preg_replace_callback('/(?<![\\"\'])<img\b[^>]*>/i', function ($m) {
                try {
                    return self::rewrite_one_img($m[0]);
                } catch (\Throwable $e) {
                    return $m[0]; // per-image safety: leave untouched on any error
                }
            }, $html);

            if (!empty($picture_stash)) {
                $html = strtr($html, $picture_stash);
            }
            // The per-image fallback is an inline onerror on each negotiated <img>, not a page-level
            // <script>: a delegated end-of-body listener was defeated two ways — eager/LCP images fire
            // their one-shot 'error' before it registers, and WPC's own JS-delay deferred the script so
            // it never ran. An inline onerror has neither flaw.
            return $html;
        } catch (\Throwable $e) {
            error_log('[WPC NegotiatedDelivery] rewrite_buffer error — passthrough: ' . $e->getMessage());
            return $html;
        }
    }

    /**
     * Rewrite a single <img …> to a plain <img> with .webp native URLs, or return it
     * unchanged if it must be skipped. Reuses the legacy skip predicates (intrinsic guards).
     */
    private static function rewrite_one_img($tag)
    {
        // Already processed by us, or already on the CDN → leave it.
        if (strpos($tag, self::MARK) !== false) return $tag;

        // Parse ALL attributes ourselves. We deliberately do NOT instantiate wps_rewriteLogic: its
        // constructor resets shared static state (imageCounter, excludes, post_id, lazy limits) and
        // getAllTags() ignores src/srcset/data-src by default, which would blank our src read + lazy guards.
        $attrs = self::parse_attrs($tag);

        $src = isset($attrs['src']) ? trim($attrs['src']) : '';
        if ($src === '') return $tag;

        // ── Intrinsic skips (render-independent; reuse legacy guards) ──────────────
        if (stripos($src, 'data:') === 0) return $tag;                 // data-uri / placeholder
        if (preg_match('/\.(svg|svgz|gif|ico)(\?|$)/i', $src)) return $tag; // non-negotiable types
        $host = self::cdn_host();
        if ($host !== '' && strpos($src, $host) !== false) return $tag; // already on the CDN
        if (preg_match('#//#', $src) && !self::is_local($src)) return $tag; // external host
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'isExcluded')
            && wps_cdn_rewrite::isExcluded('cdn', $src)) return $tag;   // per-url/global excludes
        // Lazy-library markers — defer to the site's own lazy handling.
        foreach (['data-src', 'data-srcset', 'data-cp-src', 'data-oi', 'data-interchange', 'data-mk-image-src-set'] as $k) {
            if (isset($attrs[$k])) return $tag;
        }
        $cls = isset($attrs['class']) ? $attrs['class'] : '';
        if (preg_match('/\b(skip-lazy|notlazy|nolazy|breakdance|jet-image|data-lazy)\b/i', $cls)) return $tag;

        // Resolve the attachment + metadata (need the registered -WxH sub-sizes).
        $att = (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'resolve_attachment_id'))
            ? (int) WPC_Modern_Delivery::resolve_attachment_id($src, $cls)
            : (function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid(preg_replace('/\?.*$/', '', $src)) : 0);
        if ($att <= 0) return $tag; // can't map to a WP attachment → leave (e.g. theme asset w/o metadata)
        if (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'is_processable')
            && !WPC_Modern_Delivery::is_processable($att)) return $tag;  // offloaded / animated-gif / missing file

        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($att) : false;
        if (!is_array($meta) || empty($meta['file'])) return $tag;

        return self::build_negotiated_img($tag, $attrs, $att, $meta);
    }

    /**
     * (v7.03.90) ATF right-sizing hints, read DIRECTLY from this page's lcp.json — memoized once per
     * request. The crit reader (rewriteLogic::addCriticalCSS) also publishes these via the
     * wpc_afold_image_hints filter, but it runs LATER in the pipeline than the img rewrite
     * (cdn-rewrite: replaceImageTags @ ~4744 BEFORE addCritical @ ~4931), so at consume-time that filter
     * is empty. Reading the file here makes the consumer ordering-independent; a registered filter still
     * overrides. Returns stem (lowercased, NO -scaled/-WxH) → ['m'=>cssW,'d'=>cssW]. Inert on non-LCP pages.
     */
    private static $afold_hints_cache = null;
    private static function afoldHints()
    {
        if (self::$afold_hints_cache !== null) {
            return self::$afold_hints_cache;
        }
        self::$afold_hints_cache = [];
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return self::$afold_hints_cache;
        }
        $url = (is_ssl() ? 'https://' : 'http://')
            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
            . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
        $key = (new wps_ic_url_key())->setup($url);
        if ($key === '') {
            return self::$afold_hints_cache;
        }
        $f = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/lcp.json';
        if (!@is_readable($f)) {
            return self::$afold_hints_cache;
        }
        $j   = json_decode((string) @file_get_contents($f), true);
        $atf = (is_array($j) && isset($j['atf_images']) && is_array($j['atf_images'])) ? $j['atf_images'] : null;
        if ($atf === null) {
            return self::$afold_hints_cache;
        }
        $mob = (isset($atf['mobile'])  && is_array($atf['mobile']))  ? $atf['mobile']  : [];
        $des = (isset($atf['desktop']) && is_array($atf['desktop'])) ? $atf['desktop'] : [];
        if (empty($mob) && empty($des)) { $mob = $atf; $des = $atf; }   // flat fallback → both viewports
        $map = [];
        foreach (['m' => $mob, 'd' => $des] as $slot => $list) {
            foreach ((array) $list as $im) {
                if (!is_array($im) || empty($im['stem']) || empty($im['css_w'])) {
                    continue;
                }
                $st = strtolower((string) $im['stem']);
                if ($st === '') {
                    continue;
                }
                if (!isset($map[$st])) {
                    $map[$st] = ['m' => 0, 'd' => 0];
                }
                if ($map[$st][$slot] === 0) {
                    $map[$st][$slot] = (int) round((float) $im['css_w']);
                }
            }
        }
        self::$afold_hints_cache = $map;
        return self::$afold_hints_cache;
    }

    /**
     * Build the plain <img>. NO <picture>. src + srcset are .webp native URLs at the
     * registered sub-sizes; the edge negotiates the real format per Accept.
     */
    private static function build_negotiated_img($tag, $attrs, $att, $meta)
    {
        // Width → -WxH .webp URL for each registered sub-size (+ the full size).
        $entries = [];   // "url Ww"
        $by_width = [];
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sz) {
                if (empty($sz['file']) || empty($sz['width'])) continue;
                // Mirror core's wp_calculate_image_srcset ratio rule: core excludes different-aspect
                // crops, so without this a hard-cropped 150x150/700x700 size gets advertised into a
                // portrait's srcset and the browser hands a square file to a portrait box ("smushed").
                if (!empty($sz['height']) && !empty($meta['width']) && !empty($meta['height'])
                    && function_exists('wp_image_matches_ratio')
                    && !wp_image_matches_ratio((int) $sz['width'], (int) $sz['height'], (int) $meta['width'], (int) $meta['height'])) {
                    continue;
                }
                $w = (int) $sz['width'];
                $url = self::build_natural_url((string) $sz['file'], $meta);
                if ($url === '') continue;
                $by_width[$w] = $url;
            }
        }
        // Full / main size (its basename = the attached file).
        if (!empty($meta['width']) && !empty($meta['file'])) {
            $full = self::build_natural_url(basename((string) $meta['file']), $meta);
            if ($full !== '') $by_width[(int) $meta['width']] = $full;
        }

        // PIXEL-OPTIMAL RUNGS: also advertise on-disk ADAPTIVE variants. The ladder above lists only
        // registered WP sizes, so the browser rounds UP past a pixel-optimal width that's already on
        // disk under -WxH naming (e.g. picks 1510w when a 1280w sits there). Source = ic_local_variants
        // adaptive labels. Each rung is DISK-GATED per emission mode: jpeg-natural needs the original-ext
        // file (.jpg serves by extension, no negotiation); webp mode accepts a .webp OR .avif sibling
        // (the edge ladder serves avif under the natural .webp URL). Skip rungs within 8% of an existing
        // one (no byte win, srcset bloat only).
        if (!empty($att) && !empty($meta['file'])) {
            $lv = get_post_meta((int) $att, 'ic_local_variants', true);
            if (is_array($lv) && !empty($lv)) {
                $up_pr   = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
                $base_pr = !empty($up_pr['basedir']) ? rtrim($up_pr['basedir'], '/') : '';
                $dir_pr  = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
                $stem_pr = preg_replace('/(-scaled)?\.[^.]+$/', '', basename((string) $meta['file']));
                $oext_pr = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
                $seen_wh = [];
                foreach ($lv as $lk => $le) {
                    if (!preg_match('/^(\d+)x(\d+)(?:-[a-z0-9]+)?$/', (string) $lk, $am)) continue;
                    $aw = (int) $am[1];
                    $wh = $am[1] . 'x' . $am[2];
                    if ($aw <= 0 || isset($by_width[$aw]) || isset($seen_wh[$wh]) || $base_pr === '') continue;
                    // Same ratio rule for adaptive variants (defensive — a crop-labeled variant must
                    // never reach a mismatched-ratio srcset).
                    if (!empty($meta['width']) && !empty($meta['height'])
                        && function_exists('wp_image_matches_ratio')
                        && !wp_image_matches_ratio($aw, (int) $am[2], (int) $meta['width'], (int) $meta['height'])) {
                        continue;
                    }
                    $seen_wh[$wh] = true;
                    $close_pr = false;
                    foreach (array_keys($by_width) as $ew) {
                        if ($ew > 0 && abs($ew - $aw) / $ew < 0.08) { $close_pr = true; break; }
                    }
                    if ($close_pr) continue;
                    $fbase_pr = $stem_pr . '-' . $wh;
                    $exts_pr  = self::$jpeg_mode ? [$oext_pr] : ['webp', 'avif'];
                    foreach ($exts_pr as $xt) {
                        if ($xt !== '' && @file_exists($base_pr . '/' . $dir_pr . $fbase_pr . '.' . $xt)) {
                            $u_pr = self::build_natural_url($fbase_pr . '.' . $xt, $meta);
                            if ($u_pr !== '') $by_width[$aw] = $u_pr;
                            break;
                        }
                    }
                }
            }
        }
        if (empty($by_width)) return $tag; // nothing to emit → leave original

        // IDEAL-WIDTH GENERATOR. The rungs above only advertise widths that already exist; this computes
        // the widths the page's real slots NEED (slot tiers × DPR {1, 1.75, 2, 3}; 1.75 = PSI Moto G)
        // and closes the gap two ways:
        //   (a) GENERATE: queue missing targets through the plugin-signed sized-trigger (≤6/render batch,
        //       60/h bucket, natural-width cap, skip-if-on-disk, burst guard — all in the queue helper).
        //       Files land via pull; the disk-gated merge above advertises them on later renders.
        //   (b) BOOTSTRAP (option wpc_nd_bootstrap_rungs, DEFAULT OFF): emit the missing targets as
        //       transform rungs NOW so even the first visitor picks pixel-exact bytes; flips to natural
        //       automatically once the file lands.
        // CONFIDENCE GATE: only when the slot is trustworthy (detected content width, no full-bleed/
        // builder markers) — otherwise the slot model is wrong and wrong here means blur or waste, so
        // those images keep today's behavior. Never-exceed-natural-width is enforced in the queue helper
        // + the explicit cap below.
        // LCP sizes are hoisted/computed early because the emitter OVERRIDES the LCP image's sizes to the
        // optimize-lcp ladder — the generator must derive targets from the EMITTED sizes, not the
        // original attr, or it computes the wrong slot and the browser rounds up past the rung it needs.
        $loading = isset($attrs['loading']) ? $attrs['loading'] : '';
        // Baked-ladder scrub. Some builders (Elementor) SAVE our legacy content-width ladder into the
        // post content itself, so the tag's "own" sizes is poisoned input that survives optimize-lcp OFF
        // and every render-time guard. For full-bleed images (the under-fetch class) replace a recognized
        // WPC ladder with core-equivalent width-based sizes; column images keep it (correct + byte-saving
        // there). Unconditional — NOT gated on optimize-lcp, because baked content persists when off.
        if (isset($attrs['sizes']) && $attrs['sizes'] !== ''
            && preg_match('/^(?:auto, *)?\(max-width: *600px\) *50vw, *\(max-width: *1024px\) *40vw, *(\d+)px$/i', trim((string) $attrs['sizes']), $m_baked)
            && preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', isset($attrs['class']) ? (string) $attrs['class'] : '')) {
            $w_baked = isset($attrs['width']) ? (int) preg_replace('/\D/', '', (string) $attrs['width']) : 0;
            if ($w_baked > (int) $m_baked[1]) {
                $attrs['sizes'] = '(max-width: ' . $w_baked . 'px) 100vw, ' . $w_baked . 'px';
            } else {
                unset($attrs['sizes']); // no trustworthy width → browser's 100vw default (never under-fetches)
            }
        }
        $wpc_lcp_sizes = '';
        $wpc_crit_sized = false;
        // (v7.03.83) ATF right-sizing. If the crit render measured THIS image's exact display width (matched by
        // src stem), use it as the authoritative sizes — it drives BOTH the DPR-ladder rung generator (below)
        // and the output sizes (~line 730). Generalizes the LCP override to the first-few ATF images. No 'auto,'
        // prefix: the crit width is EXACT, so we don't want the browser to self-measure (which over-fetches on
        // crit-deferred sites — the very thing this fixes). Stem-keyed, so it only touches images crit measured;
        // fully inert until crit emits images:[].
        $wpc_afold_hints = apply_filters('wpc_afold_image_hints', self::afoldHints());
        if (is_array($wpc_afold_hints) && !empty($wpc_afold_hints) && !empty($attrs['src'])) {
            $wpc_afold_base = basename(strtok((string) $attrs['src'], '?#'));
            $wpc_afold_stem = strtolower(preg_replace('/(-\d+x\d+|-scaled)?\.[^.]+$/', '', $wpc_afold_base));
            if ($wpc_afold_stem !== '' && isset($wpc_afold_hints[$wpc_afold_stem])) {
                $wpc_afold_mW = (int) $wpc_afold_hints[$wpc_afold_stem]['m'];
                $wpc_afold_dW = (int) $wpc_afold_hints[$wpc_afold_stem]['d'];
                if ($wpc_afold_mW > 0 && $wpc_afold_dW > 0) {
                    $wpc_lcp_sizes  = '(max-width: 768px) ' . $wpc_afold_mW . 'px, ' . $wpc_afold_dW . 'px';
                    $wpc_crit_sized = true;
                } elseif ($wpc_afold_dW > 0) {
                    $wpc_lcp_sizes  = (string) $wpc_afold_dW . 'px';
                    $wpc_crit_sized = true;
                } elseif ($wpc_afold_mW > 0) {
                    $wpc_lcp_sizes  = (string) $wpc_afold_mW . 'px';
                    $wpc_crit_sized = true;
                }
            }
        }
        // $img_index increments LATER (just before attr emission), so here the FIRST image still reads 0.
        if ($wpc_lcp_sizes === '' && self::$img_index === 0) {
            $set_l = get_option(WPS_IC_SETTINGS);
            if (is_array($set_l) && !empty($set_l['optimize-lcp'])) {
                $w_lcp  = isset($attrs['width']) ? (int) preg_replace('/\D/', '', (string) $attrs['width']) : 0;
                $pfx    = ($loading === 'lazy') ? 'auto, ' : '';
                // Full-bleed guard. This override's content-column model fixes core's 100vw OVER-fetch
                // for column-constrained LCP images, but a size-full/alignfull/cover/builder image's slot
                // IS the viewport, so the same cap becomes an UNDER-fetch (soft LCP on retina). Keep the
                // tag's own sizes for those.
                $cls_lcp = isset($attrs['class']) ? (string) $attrs['class'] : '';
                $lcp_full_bleed = (bool) preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $cls_lcp);
                if ($lcp_full_bleed) {
                    // leave $wpc_lcp_sizes = '' → original sizes attr carries through
                } elseif ($w_lcp > 0 && $w_lcp < 1200) {
                    $wpc_lcp_sizes = $pfx . '(max-width: ' . $w_lcp . 'px) 100vw, ' . $w_lcp . 'px';
                } else {
                    $maxW_l = !empty($set_l['maxWidth']) ? (int) $set_l['maxWidth'] : 2560;
                    $cw_l   = function_exists('wpc_get_theme_content_width') ? (int) wpc_get_theme_content_width() : 0;
                    $cap_l  = $cw_l > 0 ? $cw_l : min(1200, max(400, $maxW_l));
                    $wpc_lcp_sizes = $pfx . '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $cap_l . 'px';
                }
                $wpc_lcp_sizes = (string) apply_filters('wpc_picture_lcp_sizes', $wpc_lcp_sizes, $attrs, $set_l);
            }
        }

        $ng_cls   = isset($attrs['class']) ? (string) $attrs['class'] : '';
        // (v7.03.74) sizes=auto → the browser self-measures the real slot at runtime, so the generator's
        // content-width model isn't required (the srcset just needs rungs spanning the range). Without this,
        // nd images on a NON-singular view — sidebar/widget ads, where wpc_get_theme_content_width() returns 0
        // — failed this gate, so the generator and the .73 auto down-ladder inside it never ran, and the
        // 887-natural ads kept serving 361 in a 288 box. Let an auto-sized, non-full-bleed tag through on auto.
        $ng_has_auto = isset($attrs['sizes']) && stripos((string) $attrs['sizes'], 'auto') !== false;
        $ng_confident = !preg_match('/\b(alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $ng_cls)
            && (
                (function_exists('wpc_get_theme_content_width') && (int) wpc_get_theme_content_width() > 0)
                || $ng_has_auto
                || $wpc_crit_sized   // (v7.03.83) crit measured this image's exact width → confident on any view
            )
            && apply_filters('wpc_ideal_width_generator', true);
        if ($ng_confident && !empty($meta['width']) && function_exists('wpc_v2_sized_trigger_queue')) {
            $ng_natural = (int) $meta['width'];
            $ng_cap     = (int) wpc_get_theme_content_width();                      // fallback desktop slot
            // Per-image targets from this image's OWN sizes attribute (each slot gets its true DPR ladder
            // — the hero's tier ≠ a thumbnail's); falls back to the content-width model when no sizes.
            $ng_sizes_eff = ($wpc_lcp_sizes !== '') ? $wpc_lcp_sizes
                : (isset($attrs['sizes']) ? (string) $attrs['sizes'] : '');
            $ng_targets = function_exists('wpc_v2_ideal_targets_from_sizes')
                ? wpc_v2_ideal_targets_from_sizes($ng_sizes_eff, $ng_cap)
                : [];
            $ng_targets = array_unique(array_map('intval', apply_filters('wpc_ideal_width_targets', $ng_targets, $attrs, $meta)));
            sort($ng_targets);
            $ng_boot = (string) get_option('wpc_nd_bootstrap_rungs', '1') === '1';
            $ng_zone = self::cdn_host();
            $ng_seen = [];                                                           // accepted targets — self-dedupe (618 vs 620)
            foreach ($ng_targets as $tw) {
                if ($tw < 200 || $tw >= $ng_natural) continue;                       // never ≥ natural width
                // Asymmetric dedupe: only an existing rung AT/ABOVE the target within 8% satisfies it.
                // A smaller rung must never suppress a target, or the browser rounds UP past it.
                $ng_skip = false;
                foreach (array_merge(array_keys($by_width), $ng_seen) as $ew) {
                    if ($ew >= $tw && ($ew - $tw) / $tw < 0.08) { $ng_skip = true; break; }
                }
                if ($ng_skip) continue;
                $ng_seen[] = $tw;
                wpc_v2_sized_trigger_queue($att, $tw, $tw);                          // (a) generate (self-guarded)
                // (b) bootstrap rung (flag-gated) — NATURAL URL, not the transform form: the edge serves
                // missing exact-name rungs on-the-fly (zone-relay transcode, resize-capped to the URL's
                // -WxH) AND fires the avif trigger itself, so the first visitor gets live-encoded webp at
                // the exact width under the SAME URL that later serves the on-disk avif. One URL forever:
                // continuous cache key, format-stable (CF-safe), no /q:i/, "flip to natural" is a no-op.
                if ($ng_boot && $ng_zone !== '' && !self::$jpeg_mode
                    && function_exists('wpc_v2_adaptive_variant_suffix')) {
                    $sfx_b = wpc_v2_adaptive_variant_suffix($tw, $meta);
                    if ($sfx_b !== '' && strpos($sfx_b, 'x') !== false) {
                        $stem_b = preg_replace('/(-scaled)?\.[^.]+$/', '', basename((string) $meta['file']));
                        $u_b = self::build_natural_url($stem_b . $sfx_b . '.webp', $meta);
                        if ($u_b !== '') $by_width[$tw] = $u_b;
                    }
                }
            }
            ksort($by_width);
        }

        foreach ($by_width as $w => $url) $entries[] = $url . ' ' . $w . 'w';

        // src = the author's chosen size (host/ext swapped) so width/height + the no-srcset
        // fallback stay correct; fall back to the largest registered width if it can't be derived.
        $max_w = max(array_keys($by_width));
        $orig_src = isset($attrs['src']) ? trim($attrs['src']) : '';
        $src_rel = self::uploads_relative($orig_src);
        $src_url = ($src_rel !== '') ? self::build_natural_url($src_rel, $meta) : '';
        if ($src_url === '') $src_url = $by_width[$max_w];

        // ── Verified origin fallback URL ─────────────────────────────────────────────
        // The inline onerror (below) swaps to this if the negotiated .webp fails at the edge, so it MUST
        // be a file the origin actually serves. is_processable() only verified the FULL attached file,
        // not the author's chosen sub-size — and the author src may be a .webp a third-party layer
        // emitted that the origin lacks. So use the author src only when confirmed on disk; otherwise
        // fall back to the full attached file (larger, but guaranteed to render).
        $fb_url = '';
        $upl     = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : array();
        $basedir = !empty($upl['basedir']) ? rtrim($upl['basedir'], '/') : '';
        $baseurl = !empty($upl['baseurl']) ? rtrim($upl['baseurl'], '/') : '';
        if ($src_rel !== '' && $basedir !== '' && @file_exists($basedir . '/' . ltrim($src_rel, '/'))) {
            $fb_url = $orig_src; // author's chosen size exists on disk → serve it verbatim (right size)
        } elseif ($baseurl !== '' && !empty($meta['file'])) {
            // Author sub-size absent on disk. Prefer the LARGEST registered sub-size that IS on disk over
            // the full original (which can be multi-MB and would over-fetch into a small slot). Each
            // candidate is file_exists-gated; fall to the full file only if no sub-size is on disk.
            $dir   = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
            $best_w = 0; $best_file = '';
            if ($basedir !== '' && !empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz) {
                    if (empty($sz['file']) || empty($sz['width']) || (int) $sz['width'] <= $best_w) continue;
                    if (@file_exists($basedir . '/' . $dir . $sz['file'])) {
                        $best_w = (int) $sz['width'];
                        $best_file = (string) $sz['file'];
                    }
                }
            }
            $fb_url = ($best_file !== '')
                ? $baseurl . '/' . $dir . $best_file
                : $baseurl . '/' . ltrim((string) $meta['file'], '/');
        }

        // ── Assemble, preserving original attributes (esc_attr; single alt) ──
        // Anything emitted explicitly below is skipped in the carry-loop to avoid a duplicate attribute
        // (browsers honour the FIRST occurrence, which would shadow ours). This intentionally REPLACES
        // any theme/plugin onerror — fine, since onerror only fires on an already-failed image where our
        // verified-origin swap is equal-or-better. We deliberately do NOT chain the author handler in:
        // that would re-introduce the JS-string injection surface this static onerror avoids.
        $skip = ['src' => 1, 'srcset' => 1, 'sizes' => 1, 'class' => 1, 'alt' => 1,
                 'data-src' => 1, 'data-srcset' => 1, 'data-mk-image-src-set' => 1, 'data-prehidden' => 1,
                 'onerror' => 1, 'data-wpc-fb' => 1];
        // v7.02.49 — Sizing-basis gate. A w-descriptor srcset MUST be paired with a sizes value (the
        // browser defaults an absent sizes to 100vw). For an image with NO width attr, NO author sizes and
        // NO LCP-ladder we have no honest display width — the old `sizes="auto, 100vw"` fallback then forced
        // intrinsic-sized images (theme/widget logos sized only by their natural dimensions) to lay out at
        // ~100vw = GIANT. PROVEN by the same image with CDN OFF: it's emitted as a bare src (no srcset/sizes)
        // and renders correctly. So with no basis, emit a BARE natural src — byte-identical sizing to the
        // CDN-off markup — instead of a w-srcset + 100vw we can't justify. Format negotiation still happens
        // on the single natural URL at the edge.
        $nd_w_attr    = isset($attrs['width']) ? (int) preg_replace('/\D/', '', (string) $attrs['width']) : 0;
        $nd_has_basis = ($wpc_lcp_sizes !== '' || (isset($attrs['sizes']) && $attrs['sizes'] !== '') || $nd_w_attr > 0);
        // (v7.03.60) No-basis RIGHT-SIZE: a no-basis negotiated <img> (no width/sizes/LCP-ladder — typically a
        // width:100% sidebar/skyscraper ad) would otherwise emit a BARE full-size src and over-fetch badly
        // (e.g. 887x1774 into a ~290px slot). When "Right-size Lazy Images" is on, give it the natural-width
        // srcset + force lazy + sizes="auto, {natural-max}px" (below) so the browser self-sizes. sizes="auto"
        // sidesteps the v7.02.49 GIANT-render bug (auto measures the REAL box, not 100vw — the reason the
        // bare-src branch exists), and the {max}px fallback (NOT 100vw) keeps today's full-size pick on
        // browsers without sizes=auto support = zero regression. Protect image #1 (LCP): img_index is still 0
        // here (pre-++). Sliders excluded (non-active slides may never enter the viewport).
        $nd_set_rs     = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $nd_nobasis_rs = (!$nd_has_basis
            && self::$img_index >= 1
            && is_array($entries) && count($entries) > 1
            && is_array($nd_set_rs) && !empty($nd_set_rs['lazy-auto-sizes'])
            && !preg_match('/\b(rs|slide|lgx_app|dynamic-image|breakdance)\b/i', (isset($attrs['class']) ? (string) $attrs['class'] : ''))
            && apply_filters('wpc_nd_auto_sizes', true, $attrs));
        $out = '<img ' . self::MARK . ' src="' . esc_attr($src_url) . '"';
        if ($nd_has_basis || $nd_nobasis_rs) {
            $out .= ' srcset="' . esc_attr(implode(', ', $entries)) . '"';
        }
        // (v7.03.66) No-basis right-size: emit the attachment's natural width/height so the browser has an
        // aspect-ratio to reserve. The author's custom-HTML ads (wpc-nd, style="width:100%;height:auto") ship
        // NO width/height attrs — and this path adds srcset + sizes="auto" + forces lazy while BYPASSING the
        // aspect-safe gate (see $nd_nobasis_rs short-circuit below). With no intrinsic ratio, sizes="auto" +
        // lazy + height:auto drops the aspect → the image lays out at the wrong height (the ahramag sidebar
        // skyscraper rendered far too tall). Supplying real dims locks the ratio; CSS width:100% + height:auto
        // still drives the displayed size. Guarded: nd_nobasis_rs implies no width attr; skip if a height
        // attr somehow exists (the carry-loop would emit it) or meta lacks real dims.
        if ($nd_nobasis_rs && $nd_w_attr === 0 && empty($attrs['height'])
            && !empty($meta['width']) && !empty($meta['height'])) {
            $out .= ' width="' . (int) $meta['width'] . '" height="' . (int) $meta['height'] . '"';
        }

        // Native-lazy injection by POSITION. The negotiated <img> replaces WP's tag before core's
        // loading="lazy" lands, and the legacy positional injectors stand down when negotiated is active
        // — without this EVERY negotiated image would load eagerly. Mirror the legacy model: first
        // $skipFirst images = eager (LCP region), the rest = native loading="lazy". Slider/dynamic-image
        // classes stay eager (non-active slides may never enter the viewport). fetchpriority="high" on #1.
        self::$img_index++;
        if (empty($attrs['loading'])) {
            $nd_s         = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
            // On the negotiated path native loading="lazy" is the ONLY lazy mechanism (no JS data-src
            // lazy), so nativeLazy='0' alone must not force everything eager when viewport-lazy is on.
            // Apply position-based native lazy unless the user disabled BOTH lazy toggles.
            $nd_lazy_off  = is_array($nd_s)
                && isset($nd_s['nativeLazy']) && (string) $nd_s['nativeLazy'] === '0'
                && (!isset($nd_s['lazy']) || (string) $nd_s['lazy'] !== '1');
            $nd_skip_first = (is_array($nd_s) && !empty($nd_s['lazySkipCount'])) ? (int) $nd_s['lazySkipCount'] : 4;
            $nd_cls       = isset($attrs['class']) ? strtolower($attrs['class']) : '';
            $nd_is_slider = (bool) preg_match('/\b(rs|slide|lgx_app|dynamic-image|breakdance)\b/', $nd_cls);
            if (!$nd_lazy_off && !$nd_is_slider && self::$img_index > $nd_skip_first) {
                $attrs['loading'] = 'lazy';
            } else {
                $attrs['loading'] = 'eager';
            }
        }
        if ($nd_nobasis_rs) {
            $attrs['loading'] = 'lazy'; // right-size: force lazy so sizes="auto" is valid + the browser self-sizes
        }
        if (self::$img_index === 1 && !isset($attrs['fetchpriority'])) {
            $attrs['fetchpriority'] = 'high'; // first negotiated image ≈ LCP candidate
        }

        // sizes: from the hoisted LCP-ladder above (the generator derives its targets from the same
        // value, so emitted sizes and generated rungs can never diverge).
        // Resolve the sizes basis (unchanged logic): hoisted LCP-ladder, else author sizes, else width-anchored.
        $nd_sizes = '';
        if ($wpc_lcp_sizes !== '') {
            $nd_sizes = (string) $wpc_lcp_sizes;
        } elseif (isset($attrs['sizes']) && $attrs['sizes'] !== '') {
            $nd_sizes = (string) $attrs['sizes'];
        } elseif ($nd_w_attr > 0) {
            // Width attr present: anchor sizes to it (avoids 100vw over-fetch on small slots;
            // see reference_auto_prefix_poisons_eager_lcp).
            $nd_sizes = '(max-width: ' . $nd_w_attr . 'px) 100vw, ' . $nd_w_attr . 'px';
        } elseif ($nd_nobasis_rs && isset($max_w) && (int) $max_w > 0) {
            // No basis but right-sizing (v7.03.60): the SAFE fallback is the natural MAX width (NOT 100vw → no
            // giant render). "auto, {max}px" is assembled below — supporting browsers self-size, others keep full.
            $nd_sizes = (int) $max_w . 'px';
        }
        if ($nd_sizes !== '') {
            // sizes="auto" makes the browser pick the srcset candidate from the image's ACTUAL rendered box,
            // not the inserted-width-derived value — which over-fetches badly when a full-size image is shown
            // small (gallery/grid cell, narrow column): up to ~90% wasted bytes. This mirrors the <picture>
            // path (modern-delivery.php line ~1268), which already does exactly this — closing the gap where
            // the natural-delivery (wpc-nd) <img> kept the inserted-width sizes.
            //
            // SAFETY (why this is additive + cannot regress):
            //  • `auto` is valid ONLY on loading="lazy" (HTML spec). On eager it's invalid and Chrome falls
            //    back to 100vw = over-fetch (reference_auto_prefix_poisons_eager_lcp) — so we add it ONLY when
            //    THIS image is lazy. Eager/LCP images are untouched (no loading change → no LCP risk).
            //  • PREPENDED to the existing value, so browsers without sizes="auto" (Chrome <117 / Safari
            //    <18.4) silently use the real fallback rule — never bare 100vw. Zero regression.
            //  • Slider/dynamic-image classes are forced eager above (so never reach here as lazy) → no
            //    "auto measures a hidden/0-width slide → blurry" footgun.
            //  • The no-basis branch (bare src, no srcset/sizes) is deliberately NOT given a sizes at all —
            //    that path exists to avoid the auto→100vw GIANT-render bug (v7.02.49) and has no srcset to size.
            // sizes="auto" lets the browser size the srcset pick from the image's ACTUAL rendered box (fixes
            // the small-display over-fetch). THREE guards make it safe: TOGGLE off by default
            // ('wpc_nd_auto_sizes'); loading="lazy" ONLY (sliders/LCP are eager → excluded); and an
            // ASPECT-MATCH check — added only when the declared width/height aspect matches the image's REAL
            // aspect (from $meta's intrinsic dims), so it can never squish a mismatched-attr image (the
            // 7.03.27 regression cannot recur). apply_filters is evaluated first (short-circuit), so the
            // aspect call only runs when the toggle is on.
            $nd_loading = isset($attrs['loading']) ? (string) $attrs['loading'] : '';
            $nd_dw = isset($attrs['width'])  ? (int) preg_replace('/\D/', '', (string) $attrs['width'])  : 0;
            $nd_dh = isset($attrs['height']) ? (int) preg_replace('/\D/', '', (string) $attrs['height']) : 0;
            $nd_rw = (is_array($meta) && !empty($meta['width']))  ? (int) $meta['width']  : 0;
            $nd_rh = (is_array($meta) && !empty($meta['height'])) ? (int) $meta['height'] : 0;
            // Toggle: "Right-size Lazy Images" (Other Optimizations → Media & Assets). The setting drives the
            // default; the wpc_nd_auto_sizes filter can still override. Default OFF ⇒ byte-identical to <=7.03.26.
            $nd_set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
            $nd_auto_on = is_array($nd_set) && !empty($nd_set['lazy-auto-sizes']);
            if ($nd_loading === 'lazy'
                && apply_filters('wpc_nd_auto_sizes', $nd_auto_on, $attrs)
                && ($nd_nobasis_rs || wps_rewriteLogic::lazy_auto_aspect_safe($nd_dw, $nd_dh, $nd_rw, $nd_rh))
                && stripos($nd_sizes, 'auto') === false) {
                $nd_sizes = 'auto, ' . $nd_sizes;
            }
            $out .= ' sizes="' . esc_attr($nd_sizes) . '"';
        }
        // else: no sizing basis → no srcset (gated above) + no sizes → bare natural src → intrinsic
        // sizing, byte-identical to the CDN-off markup. (Was `sizes="auto, 100vw"` → the GIANT-render bug.)

        // class (merge + keep markers from the original).
        $class = trim((isset($attrs['class']) ? $attrs['class'] : '') . ' wpc-nd');
        $out .= ' class="' . esc_attr($class) . '"';

        // carry remaining original attributes verbatim (escaped).
        foreach ($attrs as $k => $v) {
            if (isset($skip[$k]) || $k === 'class') continue;
            $out .= ' ' . $k . '="' . esc_attr($v) . '"';
        }

        // alt emitted exactly once (dup-alt fix v7.08.36).
        $alt = isset($attrs['alt']) ? $attrs['alt'] : '';
        $out .= ' alt="' . esc_attr($alt) . '"';

        // Per-image inline onerror fallback. If the negotiated .webp fails at the edge (un-provisioned
        // zone / regression / DB blip), swap to $fb_url, a verified-on-disk origin file. The URL lives in
        // data-wpc-fb (esc_attr) and the onerror is a 100% STATIC literal that reads it via getAttribute
        // — zero JS-escaping/XSS surface. Inline (not a delegated <script>) is deliberate: it fires the
        // instant THIS image errors (no listener-registration race for eager/LCP images) and, being an
        // HTML attribute, is immune to WPC's own JS-delay which only defers <script>. this.onerror=null
        // makes it one-shot. Degrades to a silent no-op under strict CSP.
        if ($fb_url !== '') {
            $out .= ' data-wpc-fb="' . esc_attr($fb_url) . '"';
            $out .= ' onerror="this.onerror=null;this.removeAttribute(\'srcset\');this.src=this.getAttribute(\'data-wpc-fb\');"';
        }

        $out .= '>';
        return $out;
    }

    /**
     * One natural .webp URL on the customer CDN host for an uploads-relative sub-size file.
     * Always .webp (the stable vary-eligible cache key); the edge negotiates the real format.
     *
     * @param string $sub_file uploads-relative file (e.g. "2026/05/photo-768x1042.jpg" or "photo-768x1042.jpg")
     * @param array  $meta     attachment metadata (for the year/month subdir of $meta['file'])
     * @return string
     */
    public static function build_natural_url($sub_file, $meta)
    {
        $host = self::cdn_host();
        if ($host === '' || $sub_file === '') return '';

        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : (function_exists('wp_upload_dir') ? wp_upload_dir() : []);
        if (empty($up['baseurl'])) return '';

        // Sub-size 'file' is just a basename; prefix with the main file's year/month subdir.
        $subdir = '';
        if (!empty($meta['file'])) {
            $d = dirname((string) $meta['file']);
            if ($d !== '' && $d !== '.') $subdir = trim($d, '/') . '/';
        }
        $rel = (strpos($sub_file, '/') !== false) ? ltrim($sub_file, '/') : ($subdir . basename($sub_file));

        if (self::$jpeg_mode) {
            // JPEG-NATURAL: keep the ORIGINAL on-disk format so the edge serves the optimized jpg/png by
            // extension (no up-negotiation). Normalise to the ATTACHMENT'S extension (meta['file'] is the
            // authoritative source format) so even a stray .webp author src maps back to the on-disk .jpg.
            $orig_ext = ($meta && !empty($meta['file'])) ? strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION)) : '';
            if ($orig_ext !== '') {
                $rel = preg_replace('/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $orig_ext, $rel);
            }
            // else: no known source ext → leave $rel verbatim (keep whatever extension the sub-file had).
        } else {
            // Route the extension through the resolver instead of an unconditional swap to .webp: a
            // single-URL <img src> .webp on a vary-blind CF-DIRECT zone with no positive witness pins
            // webp and breaks non-webp browsers (no <picture> fallback). So: same-ext on an un-witnessed
            // CF-direct zone, negotiated .webp only where the edge is proven to negotiate or forced.
            $orig_ext = ($meta && !empty($meta['file'])) ? strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION)) : '';
            $cur_ext  = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
            $pick_ext = ($orig_ext !== '') ? $orig_ext : ($cur_ext !== '' ? $cur_ext : 'jpg');
            // This branch runs ONLY when is_active() (edge PROVEN to negotiate), so witness_ok=true. The
            // "vary-blind CF → same-ext floor" guard must key on whether THIS ZONE is CF-DIRECT (emit host
            // IS the configured cname), NOT on whether the request merely arrived through Cloudflare: a
            // Bunny zone on a CF-fronted site (CF-RAY present, no cf_cname) negotiates .webp fine, but
            // keying off the CF-RAY header would wrongly drop it to the same-ext floor → all-jpg. Compute
            // CF-direct from the emit host vs the cname; un-set $host → no false CF-direct.
            $cf_cname_nd = defined('WPS_IC_CF_CNAME') && function_exists('get_option') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $zone_is_cf_direct = ($cf_cname_nd !== '' && stripos((string) $host, $cf_cname_nd) !== false);
            $fmt = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_single_url_format')
                ? wps_rewriteLogic::wpc_single_url_format($pick_ext, $zone_is_cf_direct, true)
                : 'webp';
            // FALSE (KILL / unknown) → keep the proven .webp cache-key default (safe under KILL because
            // this whole path is gated off when negotiated is_active() is false).
            $swap_ext = (is_string($fmt) && $fmt !== '') ? $fmt : 'webp';
            $rel = preg_replace('/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $swap_ext, $rel);
        }

        // Build on the uploads path, but with the CDN host (not the origin host).
        $path = parse_url($up['baseurl'], PHP_URL_PATH); // e.g. /wp-content/uploads
        $path = $path ? rtrim($path, '/') : '/wp-content/uploads';

        $url = 'https://' . $host . $path . '/' . $rel;
        if (self::$modeb_test) {
            // Per-request triggers so the edge 302-redirects each image to the customer ORIGIN (origin
            // serves bytes, ~99% Bunny BW). Used by both the "Edge negotiate" radio (edge_origin_active())
            // and the ?wpc_delivery=modeb test force. The tokens are CONSTANT → one stable cache key per
            // image. The per-zone bare-URL enabler #7 is the cleaner long-term replacement once orch ships
            // it (drop the tokens then).
            $url .= (strpos($url, '?') === false ? '?' : '&') . '_wpc_m=r&_redirect_target=origin';
        }

        // ?src source-format hint (v7.03.38) — now on the negotiated path too, so the shared "Source Hints"
        // toggle works in ALL delivery modes. Tells the edge the on-disk source format so it skips the slow
        // origin format-probe (the degrade-to-origin / Fast-404 storm). Gated by the SAME shared gate
        // (wps_rewriteLogic::src_hint_enabled — orch per-zone echo + global default + UI toggle). Non-keying
        // (hinted + clean URLs share one cached object) + best-effort: only jpg/png/gif (webp/avif are
        // OUTPUTS, never transcode sources → never a wrong hint). Skipped in jpeg_mode (the URL already
        // carries the source ext — no negotiation, no probe). $orig_ext is the authoritative source format
        // from meta['file']. Idempotent (won't double-append if a hint is already present).
        if (!self::$jpeg_mode
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'src_hint_enabled')
            && wps_rewriteLogic::src_hint_enabled()) {
            $sh_oe  = isset($orig_ext) ? strtolower((string) $orig_ext) : '';
            $sh_src = ($sh_oe === 'png' || $sh_oe === 'gif' || $sh_oe === 'webp') ? $sh_oe : (($sh_oe === 'jpg' || $sh_oe === 'jpeg') ? 'jpg' : ''); // (v7.03.61) +webp source (avif excluded — never a transcode source)
            if ($sh_src !== '' && stripos($url, 'src=') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'src=' . $sh_src;
            }
        }
        return $url;
    }

    /**
     * Parse ALL attributes of a single tag → [lower-name => value]. Handles double-, single-,
     * and unquoted values; entity-decodes first so esc_attr on re-emit doesn't double-encode.
     * Mirrors wps_rewriteLogic::getAllTags' regex but is self-contained + side-effect-free.
     */
    private static function parse_attrs($tag)
    {
        $attrs = [];
        // Parse the RAW tag — do NOT html_entity_decode the whole tag first. WP emits values through
        // esc_attr(), encoding inner quotes as &quot;/&#039;. Decoding the whole tag before this
        // quote-delimited parse would turn those into literal quotes that terminate value capture early
        // (alt="The &quot;Best&quot;" → "The "). We decode each captured VALUE individually below, so
        // output re-encodes exactly once via esc_attr() (no double-encode).
        if (!preg_match_all('/([a-zA-Z0-9_:-]+(?:--[a-zA-Z0-9_:-]+)*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+)))?/', $tag, $m, PREG_SET_ORDER)) {
            return $attrs;
        }
        $first = true;
        foreach ($m as $p) {
            if ($first) { $first = false; continue; } // drop the tag name ("img")
            $name = strtolower($p[1]);
            if ($name === '') continue;
            $val = '';
            foreach ([2, 3, 4] as $g) {
                if (isset($p[$g]) && $p[$g] !== '') { $val = $p[$g]; break; }
            }
            $attrs[$name] = function_exists('html_entity_decode') ? html_entity_decode($val, ENT_QUOTES) : $val;
        }
        return $attrs;
    }

    /**
     * Uploads-relative path of a local URL (e.g. "2026/05/photo-768x1042.jpg"), or '' if the
     * URL isn't under the uploads dir. Host-agnostic (compares PATHs only).
     */
    private static function uploads_relative($url)
    {
        if ($url === '') return '';
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : (function_exists('wp_upload_dir') ? wp_upload_dir() : []);
        if (empty($up['baseurl'])) return '';
        $base_path = parse_url($up['baseurl'], PHP_URL_PATH);
        $url_path  = parse_url(preg_replace('/\?.*$/', '', $url), PHP_URL_PATH);
        if (!$base_path || !$url_path) return '';
        $base_path = rtrim($base_path, '/');
        if (strpos($url_path, $base_path . '/') !== 0) return '';
        return ltrim(substr($url_path, strlen($base_path)), '/');
    }

    /** Is this URL on the local site (uploads), not an external host? */
    private static function is_local($url)
    {
        $site = function_exists('site_url') ? site_url() : '';
        if ($site === '') return true;
        $sh = parse_url($site, PHP_URL_HOST);
        $uh = parse_url($url, PHP_URL_HOST);
        return ($uh === null || $uh === '' || $uh === $sh);
    }
}
