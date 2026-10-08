<?php

class wps_ic_js_delay_v3 extends wps_ic_js_delay_v2
{


    protected $manifest_paths = [];
    protected $manifest_inline = [];
    protected $manifest_names = [];
    protected $manifest_rc = [];
    protected $companion_ids = [];  // {handle}-js-before/-after/-extra of excluded-at-parse parents
    protected $wpc_family_keep_ids = [];  // dependency-family members coupled to a kept lane
    protected $wpc_first_screen_sliders = [];


    // JetFormBuilder's main.js by basename, its jet-plugins provider stayed delayed → ReferenceError
    // cascade killed the whole form chain). Collisions must only ever widen the KEEP direction.
    protected $promoted_src_ids = [];
    protected $parse_time_src_ids = [];

    // AUTO-100 per-vendor interaction-only cohort (A4: second release cohort for EVERY
    // visitor) + external-src dedupe map (recaptcha ×6 class), reset per document.
    // Lane patterns are SRC-ONLY force-delays — never userForceDelay, which content-
    // matches inline scripts ahead of every consent keep (the dm class via a side door).
    protected $wpc_io_patterns = [];
    protected $wpc_src_force_delay = [];
    // v7.10.393: lane entries checked WITHOUT the external-host guard — theme scripts
    // (sticky) are first-party and usually served through our zone.
    protected $wpc_lane_force_delay = [];
    protected $wpc_seen_ext_srcs = [];
    // True when THIS page's delay.json is a current-schema measured gen (ceiling{}
    // + render_critical emitted) — the gate for the aggressive interaction-only
    // default. A stale template-cached gen carries neither and stays on the timer.
    protected $wpc_measured = false;
    // Owner opted into delaying the consent manager (force-delay-consent=1):
    // the ordering invariant then also holds every tracking-class script and the
    // pre-gesture embed tick, so the CMP always boots first in the replay.
    protected $wpc_consent_delayed = false;
    protected $wpc_inline_pair_ids = [];
    protected $wpc_nodefer_all_keeps = false;
    // Script-module pages: vendor families of the executable type="module" tags on this
    // page and the WP core dist handles they consume (see wpc_module_consumer).
    protected $wpc_mod_vendors = [];
    protected $wpc_mod_core = [];
    protected $wpc_jquery_parse_needed = false;

    public function __construct()
    {
        parent::__construct();

        // Dependency closure the parent computed conditionally (consent plugin active → jQuery/Woo
        // chain must run at parse WITH the consent manager). Preserve exactly what it decided.
        $wpc_closure = [];
        foreach (['jquery.min.js', 'jquery.js', 'jquery-migrate', 'jquery-ui', 'jquery.blockUI',
                     'js-cookie', 'js.cookie', 'woocommerce.min.js', 'wc-cart-fragments',
                     'wc-add-to-cart', 'wc-checkout'] as $wpc_dep) {
            if (in_array($wpc_dep, (array) $this->excludes, true)) {
                $wpc_closure[] = $wpc_dep;
            }
        }

        // SEMANTIC-ONLY excludes: run-at-parse because running late changes their MEANING, not
        // because of dependency ordering (v3's ordered replay solves ordering by construction).
        $this->excludes = array_values(array_unique(array_merge([
            // WPC's own parse-time config + guards
            'dist/optimizer',
            'wps-ic-lazy-image',
            'wps-ic-local-lazy',
            'wpcJqueryDeferMarker',
            'n489D_var',
            'ngf298gh738qwbdh0s87v_vars',
            'wpcRunningCritical',
            'wpc-ga-bot-shield',
            'wpc-presc-reserve', // A3 uniqueness guard: apply-time count, never delayed
            'wpc-icon-belt', // icon-guard settle belt: must observe fonts as they load, not on gesture
            'wpcVitals', // RUM collector — an observer released on gesture misses the load it
                         // exists to measure, and a bounce view never beacons at all
            'wpc-rum-beacon',
            'wpc-dev-check',

            // /elementor/optimize.js (served from the service CDN) owns the deferred-STYLESHEET
            // activation (rel="wpc-stylesheet" -> stylesheet), crit-CSS cleanup, and delayed


            'optimizerwpc',
            '/optimize.js',
            'optimize.dev.js',
            'optimize-v2',

            'document.write',
            'trustLogo',


            'recaptcha/api.js',
            'gstatic.com/recaptcha',
            'hcaptcha.com',
            'turnstile',
            'challenges.cloudflare.com',

            'gdpr-cookie-consent', 'cookie-law-info', 'cookieyes', 'complianz', 'cmplz',
            'cookie-notice', 'cookie-consent', 'moove_gdpr', 'osano', 'termly', 'iubenda',
            'wpl_cookie_consent', 'wpl_viewed_cookie', 'CookieConsent', 'cookiebot',
            'tarteaucitron', 'onetrust', 'quantcast', 'usercentrics', 'consently',
            'didomi', 'trustarc', 'truste.com', 'sourcepoint', 'axeptio', 'klaro', 'securiti.ai',
            // v7.10.493 — WORDPRESS CORE JS NAMESPACE. v2 keeps wp-includes/js/dist/{hooks,i18n} and
            // wp-polyfill eager; v3 dropped them. Anything touching wp.* at load then runs BEFORE the
            // namespace exists: on zinsenvergleich wp-i18n-js + its -after were captured as
            // delayed-script-37/38 while Real Cookie Banner's own bundle loaded eagerly, so RCB could
            // not initialise and the consent banner never rendered. Pair kept whole by keeping the
            // dependency eager, not by delaying the dependant.

            // Real Cookie Banner (devowl) — was absent from EVERY consent keep-list. This list is
            // case-sensitive, so the JS-global casing sits beside the path forms (captcha/Captcha
            // convention). Not the cause of the zinsenvergleich banner (the engine held nothing
            // there) — it closes the exposure wherever delay IS active, since a delayed CMP
            // cannot prior-block trackers booting in the same replay wave.
            'real-cookie-banner', 'devowl', 'realCookieBanner',
            'surecookie', 'ccm19', 'consentmanager.net', 'cookiefirst', 'cookiehub', 'cookie-script.com', 'cookieinformation',

            'form_embed', 'msgsndr', 'leadconnectorhq', 'hsforms', 'hbspt',
            'calendly', 'typeform', 'jotform',

            'ameliaShortcodeData',

            'dark-mode', 'SR7.',
            // v7.21.12 — Bricks names its dark-mode lane "dl-mode" (bricks-dl-mode handle,
            // bricks-dl-mode-js-after inline): the 'dark-mode' keep above never matched it, so
            // the theme setter delayed behind gesture — ridgeway relit light-themed on every
            // navigation and mobile stayed light until first touch. A theme-mode setter owns
            // first paint; it can never wait on a gesture.
            'dl-mode',

            // Theme nav-inits that own first-paint layout.
            'wpbf',
            'page-builder-framework',

            'sourcebuster',
            'borlabs',

        ], $wpc_closure)));

        // v7.21.25 — v3's constructor REPLACES v2's excludes list instead of extending it
        // (the literal above, merged only with $wpc_closure — v2's list never enters it), so
        // v2's unconditional wp-core-namespace protection vanished on every v3 site. v3's
        // ordered replay only fixes ordering WITHIN the delayed partition; an EAGER dependant
        // (kept consent bundle, inline wp.* caller) still runs at parse against a delayed
        // wp.hooks/wp.i18n — the .493 class, and this customer's: Elementor/WPForms/PixFort
        // half-rendered. Their site runs Hide My WP (/wp-includes/ -> /lib/), zero
        // 'wp-includes' occurrences in the served page, so the rename-proof tail forms ride
        // alongside the path forms — v2's proven set exactly. Plain excludes lane only (same
        // as 'dark-mode'/'wpbf' above); the SEPARATE wpc_keep_core_namespace filter (adds
        // HANDLE forms, frozen off by the .497 incident) is untouched.
        // v7.21.26 — its own switch, per the .497 law (an ordering-engine behaviour change
        // ships with a per-site kill, BEFORE it breaks something). Default ON: this restores
        // v2 behaviour, it does not invent new behaviour.
        if (apply_filters('wpc_core_ns_keep2126', true)) {
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, [
                'wp-includes/js/dist/hooks', 'wp-includes/js/dist/i18n',
                'js/dist/hooks', 'js/dist/i18n',
                'wp-polyfill',
            ])));
        }

        // v7.10.497 — DEFAULT OFF. Keeping the core namespace eager is correct in isolation but the
        // keep path also DEFERS. Since .512 a page carrying any inline -after/-before companion
        // defers NO keeps, so the mixed-order hazard is gone; still needs a staging pass before
        // enabling, because widening the keep set changes what the service measured against.
        if (apply_filters('wpc_keep_core_namespace', false)) {
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, [
                'wp-includes/js/dist/i18n', 'wp-includes/js/dist/hooks', 'wp-polyfill',
                'wp-i18n', 'wp-hooks',
                // Security plugins that rename /wp-includes/ (Hide My WP et al) serve the same
                // files as /lib/js/dist/hooks.min.js — the wp-includes forms above then match
                // NOTHING and the keep fails silently. Match the path tail, which survives any
                // prefix rewrite.
                'js/dist/i18n', 'js/dist/hooks',
            ])));
        }


        // jQuery + captcha stay in the keep-eager excludes by default; opt in per site via
        // force-delay-jquery / force-delay-captcha (wpc_force_delay_on), never fleet-wide.


        // Vendor io lanes (option-sourced via the warm.php bridges). io patterns also
        // enter the SRC-ONLY force list (capture door); lane 'delay' patterns are
        // src-only force too. Neither ever touches userForceDelay: userForceDelay
        // content-matches inline scripts before every consent keep (the dm class).
        foreach ((array) apply_filters('wpc_builtin_interaction_only', []) as $io_pattern) {
            if (self::wpc_io_pattern_ok($io_pattern)) {
                $this->wpc_io_patterns[] = strtolower((string) $io_pattern);
                $this->wpc_src_force_delay[] = strtolower((string) $io_pattern);
            }
        }
        // v7.10.387 — built-in lane-delay floor: scroll-behavior scripts whose function is
        // interaction-gated by definition (sticky repositioning needs a scroll; the scroll
        // releases the delay). Removes the tag at capture = out of the pre-LCP request graph.
        foreach ((array) apply_filters('wpc_builtin_lane_delay', ['sticky-elements.js']) as $lane_pattern) {
            if (self::wpc_io_pattern_ok($lane_pattern)) {
                $this->wpc_src_force_delay[] = strtolower((string) $lane_pattern);
                $this->wpc_lane_force_delay[] = strtolower((string) $lane_pattern);
            }
        }
        $this->wpc_io_patterns = array_slice(array_values(array_unique($this->wpc_io_patterns)), 0, 24);
        $this->wpc_src_force_delay = array_slice(array_values(array_unique($this->wpc_src_force_delay)), 0, 32);

        // Consent stays eager BY DEFAULT (a delayed CMP can't prior-block trackers
        // booting in the same replay wave — regulatory exposure on the customer's
        // site, not a visual bug). This is an EXPLICIT per-site owner opt-in only:
        // settings force-delay-consent=1 / WPC_FORCE_DELAY_CONSENT — never auto.
        if (apply_filters('wpc_force_delay_consent', self::wpc_force_delay_on('consent'))) {
            $this->wpc_consent_delayed = true;
            $this->excludes = array_values(array_diff((array) $this->excludes, [
                'gdpr-cookie-consent', 'cookie-law-info', 'cookieyes', 'complianz', 'cmplz',
                'cookie-notice', 'cookie-consent', 'moove_gdpr', 'osano', 'termly', 'iubenda',
                'wpl_cookie_consent', 'wpl_viewed_cookie', 'CookieConsent', 'cookiebot',
                'tarteaucitron', 'onetrust', 'quantcast', 'usercentrics', 'consently',
                'didomi', 'trustarc', 'truste.com', 'sourcepoint', 'axeptio', 'klaro', 'securiti.ai',
                'real-cookie-banner', 'devowl', 'realCookieBanner',
                'surecookie', 'ccm19', 'consentmanager.net', 'cookiefirst', 'cookiehub', 'cookie-script.com', 'cookieinformation',
                // ORDERING INVARIANT: consent-delayed => NO tracking-class script may
                // run eager — the replay is document-ordered, so the head CMP boots
                // first and its prior-blocking holds, same as the original page. An
                // eager tracker would run BEFORE the delayed CMP: an order inversion
                // the original page never had.
                'sourcebuster', 'gtag', 'googletag',
            ]));
        }
        $wpc_fd_cap = apply_filters('wpc_force_delay_captcha', self::wpc_force_delay_on('captcha'));
        $wpc_fd_jq  = apply_filters('wpc_force_delay_jquery', self::wpc_force_delay_on('jquery'));
        if ($wpc_fd_cap || $wpc_fd_jq) {
            $wpc_fd_drop = [];
            if ($wpc_fd_cap) {
                $wpc_fd_drop = array_merge($wpc_fd_drop, ['recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com']);
            }
            if ($wpc_fd_jq) {
                $wpc_fd_drop = array_merge($wpc_fd_drop, ['jquery.min.js', 'jquery.js', 'jquery-migrate', 'jquery-ui', 'jquery.blockUI']);
            }
            $this->excludes = array_values(array_diff((array) $this->excludes, $wpc_fd_drop));
        }
        $this->wpc_release_io_form_keeps();
    }

    // The form/booking families we hard-keep by default (form_embed, GHL/
    // LeadConnector, HubSpot, Calendly) — delaying their JS leaves the embed
    // unsized/blank. delay-v3's interaction-only + human-signal warming
    // loads them the instant a real person reaches for the form and leaves them
    // deferred for a cold measurement pass, so when the SERVICE measures one as
    // delay-interaction-only its built-in keep yields and it can go io. Consent
    // families are deliberately NOT in this set (the dm law: never auto-delay
    // consent), and a user's explicit UI keep is enforced separately via
    // userExcludes — so this releases only OUR conservative default, never
    // consent and never user intent. Idempotent; driven by wpc_io_patterns
    // (which is service-sourced: option lanes + the delay.json manifest).
    protected function wpc_release_io_form_keeps()
    {
        if (empty($this->wpc_io_patterns)) {
            return;
        }
        $form_keep_tokens = ['form_embed', 'msgsndr', 'leadconnectorhq', 'hsforms', 'hbspt', 'calendly'];
        $dropped_keeps = [];
        foreach ($form_keep_tokens as $keep_token) {
            if (!in_array($keep_token, (array) $this->excludes, true)) {
                continue;
            }
            foreach ($this->wpc_io_patterns as $io_pattern) {
                if ($io_pattern !== '' && (strpos($io_pattern, $keep_token) !== false || strpos($keep_token, $io_pattern) !== false)) {
                    $dropped_keeps[] = $keep_token;
                    break;
                }
            }
        }
        if (!empty($dropped_keeps)) {
            $this->excludes = array_values(array_diff((array) $this->excludes, $dropped_keeps));
        }
    }


    /** Is $host one of OUR hosts — the site origin, a wpc CDN edge (b-cdn/zapwp), or the
     *  customer's configured CDN zone (custom CNAME / zone name / verified CF CNAME)?
     *  Scripts on our zone are rewritten LOCALS and must never be io-demoted or
     *  force-delayed as external vendors. */
    protected function wpc_is_own_host($host)
    {
        $host = strtolower((string) $host);
        if ($host === '') {
            return true; // relative/protocol-relative to our own origin
        }
        $st = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
        $host = $st($host);
        if (strpos($host, 'b-cdn') !== false || strpos($host, 'zapwp') !== false) {
            return true;
        }
        static $own = null;
        if ($own === null) {
            $own = [];
            if (function_exists('home_url')) {
                $own[] = $st(strtolower((string) parse_url(home_url(), PHP_URL_HOST)));
            }
            $host_of = function ($z) use ($st) {
                $z = strtolower(trim((string) $z));
                if ($z === '') { return ''; }
                $z = (string) strtok($z, '/'); // drop any path/key suffix (zoneName can carry /key:)
                if (strpos($z, ':') !== false) { $z = (string) strtok($z, ':'); }
                return $st($z);
            };
            foreach (['ic_custom_cname', 'ic_cdn_zone_name'] as $option_name) {
                $z = function_exists('get_option') ? $host_of(get_option($option_name)) : '';
                if ($z !== '') { $own[] = $z; }
            }
            if (class_exists('wps_rewriteLogic') && property_exists('wps_rewriteLogic', 'zoneName')) {
                $z = $host_of(@wps_rewriteLogic::$zoneName);
                if ($z !== '') { $own[] = $z; }
            }
            $own = array_values(array_unique(array_filter($own)));
        }
        return in_array($host, $own, true);
    }

    /** A1 pattern sanity gate: match[] entries are applied via userForceDelay, which
     *  BEATS every keep — a generic or consent-family pattern here is the receipted
     *  bug factory (dm banner). Reject short/bare tokens and anything touching the
     *  consent or jQuery families. */
    public static function wpc_io_pattern_ok($p, $formsSafe = false)
    {
        if (!is_string($p)) {
            return false;
        }
        $p = trim($p);
        if (strlen($p) < 5 || strlen($p) > 160) {
            return false;
        }
        $pl = strtolower($p);
        if (in_array($pl, ['jquery', '.min.js', 'min.js', 'wp-content', 'wp-includes', 'https', 'http', 'script', 'window'], true)) {
            return false;
        }
        foreach (['jquery', 'gdpr', 'cookie', 'cmplz', 'complianz', 'borlabs', 'cookiebot', 'consent',
                     'usercentrics', 'onetrust', 'iubenda', 'osano', 'termly', 'tarteaucitron', 'quantcast',
                     'moove_gdpr', 'wpl_viewed', 'consently', 'wp-i18n', 'wp-polyfill', 'wp-hooks',
                     'wp-includes/', 'sourcebuster',
                     // CMP families beyond the classic list — same sacred class
                     'didomi', 'trustarc', 'truste.com', 'sourcepoint', 'axeptio', 'klaro', 'securiti.ai',
                     // form-embed / booking revealers: builtin keeps whose delay collapses forms
                     'form_embed', 'msgsndr', 'leadconnector', 'hsforms', 'hbspt', 'calendly',
                     'typeform', 'jotform',
                     // WPC's own machinery + theme nav-init keeps
                     'optimize.js', 'optimizerwpc', 'wpbf', 'page-builder-framework', 'wpc-presc',
                     'revslider', 'sr7'] as $tok) {
            if (strpos($pl, $tok) !== false) {
                // v7.10.386 — CHAT lanes are not the form family: LeadConnector's forms ride
                // msgsndr/form_embed (ban stays), its chat rides *.leadconnectorhq.com. The
                // vendor-wide token also banned the chat, so the manifest's measured
                // delay-interaction-only could never engage (chat booted ~5.6s = the LCP
                // re-record). Allow ONLY chat-scoped subdomain patterns; bare stays banned.
                if ($tok === 'leadconnector'
                    && preg_match('/^(widgets|beta|stcdn|services|images)\.leadconnectorhq\b/', $pl)
                    && apply_filters('wpc_chat_io_allowed', true)) {
                    continue;
                }
                // v7.10.388 — the form-vendor bans obey MEASUREMENT, same recipe as the
                // captcha-keep release: when the service observed has_form:false there is no
                // visible form to protect, so a measured delay-io pattern for a form vendor
                // may engage (HubSpot chat / Calendly badge = the .386 class, other vendors).
                // Consent/jQuery/WPC-machinery stay sacred regardless.
                if ($formsSafe
                    && in_array($tok, ['form_embed', 'msgsndr', 'leadconnector', 'hsforms', 'hbspt',
                        'calendly', 'typeform', 'jotform'], true)
                    && apply_filters('wpc_formless_io_allowed', true)) {
                    continue;
                }
                return false;
            }
        }
        return true;
    }

    /** v7.10.386 — expand a bare vendor io pattern into its chat-scoped hosts (the only
     *  form the validator accepts for this family). Non-matching patterns pass through. */
    public static function wpc_io_pattern_expand($p)
    {
        $pl = strtolower(trim((string) $p));
        if ($pl === 'leadconnectorhq.com' || $pl === 'leadconnectorhq') {
            return ['widgets.leadconnectorhq.com', 'beta.leadconnectorhq.com',
                'stcdn.leadconnectorhq.com', 'services.leadconnectorhq.com', 'images.leadconnectorhq.com'];
        }
        return [$p];
    }

    /** Does this (local) script file reference jQuery? mtime-keyed verdict cache. */
    public static function wpc_src_needs_jquery($url)
    {
        if ($url === '' || ($cp = strrpos($url, 'wp-content/')) === false) {
            return false;
        }
        $rel = (string) preg_replace('/[?#].*$/', '', substr($url, $cp));
        if (strpos($rel, '..') !== false) {
            return false;
        }
        $path = trailingslashit(ABSPATH) . $rel;
        if (!@is_readable($path) || (int) @filesize($path) > 1048576) {
            return false;
        }
        $key = basename($rel) . '|' . (int) @filemtime($path);
        $cache = get_option('wpc_delay_v3_jqneed', []);
        if (is_array($cache) && array_key_exists($key, $cache)) {
            return (bool) $cache[$key];
        }
        $js = (string) @file_get_contents($path);
        $need = $js !== '' && (strpos($js, 'jQuery') !== false || preg_match('/(?:^|[^\w$])\$\s*\(/', $js));
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache[$key] = $need ? 1 : 0;
        update_option('wpc_delay_v3_jqneed', array_slice($cache, -40, null, true), false);
        return $need;
    }

    /**
     * Is this script id one of the jQuery tags a parse-time inline companion depends on?
     * WP emits the library as jquery-core-js (and the alias handle as jquery-js), with
     * jquery-migrate-js riding the same lane; matching the id keeps this off every other
     * handle that merely has "jquery" in its name (jquery-ui-*, jquery.validate, ...).
     */
    public static function wpc_is_jquery_script_id($script_id)
    {
        $script_id = strtolower(trim((string) $script_id));
        if ($script_id === '') {
            return false;
        }
        return $script_id === 'jquery-js'
            || strpos($script_id, 'jquery-core') === 0
            || strpos($script_id, 'jquery-migrate') === 0;
    }

    public static function wpc_consent_satellites()
    {
        return (array) apply_filters('wpc_consent_satellites', ['burst.min.js', 'burst-goals', 'timeme', 'burst-js', 'burst-timeme']);
    }

    public function wpc_lazy_load_consent_css($html)
    {
        if (!$this->wpc_consent_delayed || !is_string($html) || $html === '' || !apply_filters('wpc_consent_css_lazy', true)) {
            return $html;
        }
        // The banner is off the wire until a gesture, so its sheets need not block first paint:
        // load them non-blocking (media=print → all on load). Complianz ids: cmplz-general-css,
        // cmplz-banner-*-css; other CMPs via the filter.
        $ids = (array) apply_filters('wpc_consent_css_ids', ['cmplz-']);
        $n = 0;
        $out = preg_replace_callback('/<link\b[^>]*\brel=(["\'])stylesheet\1[^>]*>/i', function ($m) use ($ids, &$n) {
            $tag = $m[0];
            if (preg_match('/\bmedia\s*=\s*(["\'])print\1/i', $tag) || stripos($tag, 'onload=') !== false) {
                return $tag;
            }
            if (!preg_match('/\bid=(["\'])([^"\']+)\1/i', $tag, $im)) {
                return $tag;
            }
            $hit = false;
            foreach ($ids as $p) {
                if ($p !== '' && stripos($im[2], (string) $p) === 0) { $hit = true; break; }
            }
            if (!$hit) {
                return $tag;
            }
            $n++;
            $tag = (string) preg_replace('/\s*\bmedia\s*=\s*(["\'])[^"\']*\1/i', '', $tag);
            return (string) preg_replace('/<link\b/i', '<link media="print" onload="this.media=\'all\'"', $tag, 1);
        }, $html);
        if ($n > 0 && function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_ccl59')) {
            set_transient('wpc_ccl59', 1, 3600);
            wpc_cache_first_log('consent-css-lazy', '', '', ['n' => $n]);
        }
        return is_string($out) ? $out : $html;
    }

    public static function wpc_force_delay_on($which)
    {
        $const = 'WPC_FORCE_DELAY_' . strtoupper((string) $which);
        if (defined($const) && constant($const)) {
            return true;
        }
        $s = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        if (is_array($s) && !empty($s['force-delay-' . $which]) && $s['force-delay-' . $which] == '1') {
            return true;
        }
        // v7.22.60 — consent AUTO: the checkbox forces it on; unchecked means "decide by CMP".
        // On only when the active consent plugin blocks server-side (rewrites trackers to
        // text/plain in the HTML), so delaying its banner cannot change what runs before consent.
        // Explicit off: filter wpc_consent_auto false / WPC_CONSENT_AUTO_OFF.
        if ($which === 'consent') {
            return self::wpc_consent_auto_enabled();
        }
        return false;
    }

    public static function wpc_consent_auto_enabled()
    {
        if (defined('WPC_CONSENT_AUTO_OFF') && WPC_CONSENT_AUTO_OFF) {
            return false;
        }
        if (!apply_filters('wpc_consent_auto', true)) {
            return false;
        }
        $active = (array) get_option('active_plugins', []);
        if (function_exists('get_site_option')) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        // Server-side blockers only. Complianz verified on a live page (09-06: zero text/plain-typed
        // scripts survive to the browser un-typed). Add others via the filter once verified.
        $servers = (array) apply_filters('wpc_consent_auto_plugins', ['complianz-gdpr/', 'complianz-gdpr-premium/']);
        foreach ($active as $p) {
            $p = strtolower((string) $p);
            foreach ($servers as $pre) {
                if ($pre !== '' && strpos($p, strtolower((string) $pre)) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    // THE measured-shape predicate — single source of truth for "this delay.json
    // is a current-schema measured gen the aggressive flip can trust." Freshness
    // signal = schema_epoch >= N (authoritative, additive; the service is moving
    // the flip gate here per the Artifact Identity Contract 2026-07-22) OR the
    // legacy ceiling{} PRESENCE proxy (NOT its score/reachable_100 — those are
    // unreliable-pessimistic and are never consumed). render_critical is the
    // safety keep list, required either way (a stale copy can carry render_critical
    // WITHOUT ceiling — busyprosai — so render_critical alone is not sufficient).
    public static function wpc_delay_measured_shape($j)
    {
        if (!is_array($j)) {
            return false;
        }
        $wpc_epoch = isset($j['schema_epoch']) && (int) $j['schema_epoch'] >= (int) apply_filters('wpc_delay_schema_epoch_min', 1);
        $wpc_ceil  = isset($j['ceiling']) && is_array($j['ceiling']);
        if (!$wpc_epoch && !$wpc_ceil) {
            return false;
        }
        foreach ([$j, $j['mobile'] ?? null, $j['desktop'] ?? null] as $wpc_ms) {
            if (is_array($wpc_ms) && isset($wpc_ms['render_critical']) && is_array($wpc_ms['render_critical'])) {
                return true;
            }
        }
        return false;
    }

    // A measured delay.json whose mtime is NEWER than $since. FILE READS ONLY —
    // never a render-path option write. Lets a fresh measured gen override a
    // stale promotion kill-switch (manifest_off, set by a PROMOTED-script
    // ReferenceError): that switch is orthogonal to the aggressive measured flip
    // (which has its own boot-watchdog/demote safety), yet it gates the whole
    // manifest read and is cleared only on the write-once delay.json write — so a
    // stale switch froze the flip forever (busyprosai).
    // v7.22.12 — ONE resolver for the page's delay.json: the exact key first, then the
    // query-stripped key (a param variant IS the same page; keeps/delays are names-based,
    // so a script absent from the variant is a no-op). Every manifest read routes here.
    public static function wpc_delay_manifest_file()
    {
        try {
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return '';
            }
            $base = rtrim(WPS_IC_CRITICAL, '/') . '/';
            $k = (string) (new wps_ic_url_key())->setup('');
            if ($k !== '' && @is_readable($base . $k . '/delay.json')) {
                return $base . $k . '/delay.json';
            }
            if (method_exists('wps_ic_url_key', 'cleanRequestKey') && apply_filters('wpc_delay_manifest_clean_key', true)) {
                $ck = (string) wps_ic_url_key::cleanRequestKey();
                if ($ck !== '' && $ck !== $k && @is_readable($base . $ck . '/delay.json')) {
                    if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_dmf112_' . md5($k))) {
                        set_transient('wpc_dmf112_' . md5($k), 1, 3600);
                        wpc_cache_first_log('delay-manifest-cleankey', $k, '', ['clean' => $ck]);
                    }
                    return $base . $ck . '/delay.json';
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    public static function wpc_measured_delay_newer_than($since)
    {
        try {
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return false;
            }
            $f = self::wpc_delay_manifest_file();
            if ($f === '' || !@is_readable($f) || (int) @filemtime($f) <= (int) $since) {
                return false;
            }
            $j = json_decode((string) @file_get_contents($f), true);
            return self::wpc_delay_measured_shape($j);
        } catch (\Throwable $e) {
        }
        return false;
    }

    // OUT-OF-THE-BOX MASTER: explicit '1' = on, explicit '0' = off (owner
    // intent always wins) — but a NEVER-CONFIGURED master arms automatically
    // on a MEASURED page (aggr_live), v3 engine only (never auto-arm legacy
    // v2). Best scores day one: install → traffic → gen lands → the whole
    // delay family + facade + flip arm in one render, no settings touched.
    public static function wpc_delay_master_on($s)
    {
        if (is_array($s) && isset($s['delay-js-v2'])) {
            return $s['delay-js-v2'] == '1';
        }
        if (is_array($s) && isset($s['delay-js-v3']) && $s['delay-js-v3'] == '0') {
            return false;
        }
        return self::wpc_aggr_live();
    }

    // Static mirror of the aggressive-default gate (measured current-schema gen
    // + not demoted + telemetry alive + master filter) for OTHER passes that
    // must co-arm with the flip — the iframe facade gate in cdn-rewrite reads
    // this so funnel/player embeds go gesture-restored exactly when scripts do.
    // Keep the predicate in lockstep with the process_html flip.
    public static function wpc_aggr_live()
    {
        static $aggr_live = null;
        if ($aggr_live !== null) {
            return $aggr_live;
        }
        $aggr_live = false;
        try {
            // manifest_off blocks only if it is NEWER than the on-disk measured
            // gen (parity with the process_html flip) — a fresh gen supersedes a
            // stale promotion kill-switch; the facade must arm exactly when the
            // flip does.
            $manifest_off_at = (int) get_option('wpc_delay_v3_manifest_off', 0);
            if (($manifest_off_at > 0 && !self::wpc_measured_delay_newer_than($manifest_off_at))
                || get_option('wpc_delay_aggr_off')
                || !apply_filters('wpc_delay_v3_telemetry', true)
                || !apply_filters('wpc_delay_v3_io_when_measured', true)
                || !apply_filters('wpc_delay_v3_manifest', true)
                || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return $aggr_live;
            }
            $manifest_file = self::wpc_delay_manifest_file();
            if ($manifest_file === '' || !@is_readable($manifest_file)) {
                return $aggr_live;
            }
            $manifest = json_decode((string) @file_get_contents($manifest_file), true);
            $aggr_live = self::wpc_delay_measured_shape($manifest);
        } catch (\Throwable $e) {
            $aggr_live = false;
        }
        return $aggr_live;
    }


    // v7.21.31 — form-family tokens: substrings that identify a JetFormBuilder-ecosystem
    // script by its id/src no matter how a security plugin rewrites the URL. 'jet-fb' catches
    // the blocks-v2 field modules (jet-fb-blocks-v2-phone-field), 'jet-plugins' the shared
    // Crocoblock loader that defines JetPlugins, 'intl-tel-input' the phone field's vendor.
    protected $wpc_form_family_token_cache = null;
    protected function wpc_form_family_tokens()
    {
        if ($this->wpc_form_family_token_cache === null) {
            // v7.21.347 — Smash Balloon YouTube joins the family (columbus /videos-gallery/:
            // sb-youtube's whole boot is one-shot phase machinery — YT iframe-api callback,
            // per-feed playerAPIReady, sby_init — measured unhealable piecemeal under
            // replay; player row never builds, clicks swap nothing; fine with disableWPC).
            // Keep restores native document order = parity by construction (.57 law,
            // same site: keep + dep closure; sync-jQuery arms below).
            $wpc_t = apply_filters('wpc_form_family_handles',
                ['jet-form-builder', 'jetformbuilder', 'jet-fb', 'jet-plugins', 'intl-tel-input',
                 'jet-appointments', 'jet-ab-', 'jet-apb', 'sb-youtube', 'sby-scripts']);
            $wpc_o = [];
            foreach ((array) $wpc_t as $wpc_ti) {
                $wpc_ti = strtolower((string) $wpc_ti);
                if ($wpc_ti !== '') { $wpc_o[] = $wpc_ti; }
            }
            $this->wpc_form_family_token_cache = $wpc_o;
        }
        return $this->wpc_form_family_token_cache;
    }

    // v7.21.159 — probe verdict law: only a definitive 4xx (rule-class refusal: 403 WAF,
    // 404 cleaner, 410) stands the uploads loader down; 2xx certifies it. 3xx/5xx/0 are
    // inconclusive (edge hiccup, maintenance) and must NEVER become a sticky verdict.
    public static function wpc_loader_probe_verdict($code)
    {
        $code = (int) $code;
        if ($code >= 200 && $code < 300) {
            return 'ok';
        }
        if ($code >= 400 && $code < 500) {
            return 'down';
        }
        return null;
    }

    // Memoized handle-keyed keep-set: every wp_scripts handle whose NAME carries a family
    // token, PLUS its full recursive dependency closure. Built from wp_scripts->registered,
    // which is keyed on handles the URL rewrite never touches — so a form module's vendor
    // provider (intl-tel-input, declared as a dep of the phone field) is kept atomically with
    // it rather than being left in the delay lane to invert. Cap-bounded; fail-open to empty.
    protected $wpc_form_keep_handles = null;
    protected function wpc_form_keep_handles()
    {
        if ($this->wpc_form_keep_handles !== null) {
            return $this->wpc_form_keep_handles;
        }
        $set = [];
        $ws = (!empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered))
            ? $GLOBALS['wp_scripts'] : null;
        if ($ws) {
            $toks = $this->wpc_form_family_tokens();
            foreach (array_keys($ws->registered) as $wpc_h) {
                $wpc_hl = strtolower((string) $wpc_h);
                $wpc_fam = false;
                foreach ($toks as $wpc_tk) {
                    if ($wpc_tk !== '' && strpos($wpc_hl, $wpc_tk) !== false) { $wpc_fam = true; break; }
                }
                if (!$wpc_fam) { continue; }
                $set[$wpc_hl] = true;
                $stack = [$wpc_h]; $seen = [$wpc_h => true]; $n = 0;
                while (!empty($stack) && $n++ < 200) {
                    $cur = array_pop($stack);
                    if (empty($ws->registered[$cur])) { continue; }
                    foreach ((array) $ws->registered[$cur]->deps as $d) {
                        if (isset($seen[$d])) { continue; }
                        $seen[$d] = true;
                        $set[strtolower((string) $d)] = true;
                        $stack[] = $d;
                    }
                }
            }
        }
        $this->wpc_form_keep_handles = $set;
        return $set;
    }

    // The user's Delay JS exclude list: the one list, `delay_js_v3` (wpc_delay_excludes_fold).
    protected function wpc_user_delay_excluded($x)
    {
        return is_object($this->userExcludes)
            && method_exists($this->userExcludes, 'excludedFromDelayV3')
            && $this->userExcludes->excludedFromDelayV3((string) $x);
    }

    // v7.21.55 — USER EXCLUDES CARRY THEIR DEP CLOSURE UNDER V3 (the .31 law generalized).
    // A v2-era exclude kept a script eager while most of its dependencies were ALSO eager;
    // v3's aggressive measured mode delays far more, so the same exclude now runs its script
    // at parse BEFORE formerly-eager, now-delayed providers ("X is not defined" — the exact
    // class James reported). Every wp_scripts handle whose src/handle matches a user v3
    // exclude pulls its full recursive dependency closure into the eager set, so document
    // order holds around the keep. Cap-bounded, memoized, fail-open; kill wpc_user_excl_closure.
    protected $wpc_user_exclude_keep_handles = null;
    protected function wpc_user_exclude_keep_handles()
    {
        if ($this->wpc_user_exclude_keep_handles !== null) {
            return $this->wpc_user_exclude_keep_handles;
        }
        $set = [];
        $ws = (!empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered))
            ? $GLOBALS['wp_scripts'] : null;
        $pats = [];
        if (is_object($this->userExcludes) && method_exists($this->userExcludes, 'delayJSExcludesV3')) {
            foreach ((array) $this->userExcludes->delayJSExcludesV3() as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ($pattern !== '' && strlen($pattern) > 2) { $pats[] = $pattern; }
            }
        }
        if ($ws && !empty($pats) && apply_filters('wpc_user_excl_closure', true)) {
            foreach ($ws->registered as $handle => $registration) {
                $handle_lower = strtolower((string) $handle);
                $src = !empty($registration->src) ? strtolower((string) $registration->src) : '';
                $matched = false;
                foreach ($pats as $exclude_pattern) {
                    if (strpos($handle_lower, $exclude_pattern) !== false || ($src !== '' && strpos($src, $exclude_pattern) !== false)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) { continue; }
                $stack = [$handle]; $seen = [$handle => true]; $n = 0;
                while (!empty($stack) && $n++ < 200) {
                    $cur = array_pop($stack);
                    if (empty($ws->registered[$cur])) { continue; }
                    foreach ((array) $ws->registered[$cur]->deps as $d) {
                        if (isset($seen[$d])) { continue; }
                        $seen[$d] = true;
                        $set[strtolower((string) $d)] = true;
                        $stack[] = $d;
                    }
                }
            }
        }
        $this->wpc_user_exclude_keep_handles = $set;
        return $set;
    }

    // v7.21.95 — src PATH -> wp_scripts handle, for tags a plugin printed without an id.
    // Built once over the whole registry. Only .js sources are mapped, paths normalize to a
    // leading slash (Amelia's v3RelativePath prints "wp-content/..." while wp_scripts holds
    // "/wp-content/..."), and the query is dropped so a ?ver= mismatch cannot defeat it. The
    // path is what survives our own CDN host rewrite. Last registration wins: two handles for
    // one file share a lane by construction. Fail-open to '' — an unresolved src keeps the
    // old skip, so this can only ever ADD keeps, never take one away.
    protected $wpc_src_path_handles = null;
    protected function wpc_handle_from_src($src)
    {
        if ($this->wpc_src_path_handles === null) {
            $this->wpc_src_path_handles = [];
            $ws = (!empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered))
                ? $GLOBALS['wp_scripts'] : null;
            if ($ws && apply_filters('wpc_srcless_id_resolve', true)) {
                foreach ($ws->registered as $handle => $registration) {
                    if (empty($registration->src) || !is_string($registration->src)) {
                        continue;
                    }
                    $path = strtolower((string) parse_url($registration->src, PHP_URL_PATH));
                    if ($path === '' || substr($path, -3) !== '.js') {
                        continue;
                    }
                    $this->wpc_src_path_handles['/' . ltrim($path, '/')] = strtolower((string) $handle);
                }
            }
        }
        if (empty($this->wpc_src_path_handles)) {
            return '';
        }
        $src_path = strtolower((string) parse_url(html_entity_decode((string) $src), PHP_URL_PATH));
        if ($src_path === '') {
            return '';
        }
        $src_path = '/' . ltrim($src_path, '/');
        return isset($this->wpc_src_path_handles[$src_path]) ? $this->wpc_src_path_handles[$src_path] : '';
    }

    protected function should_exclude_script($attributes, $content = '')
    {


        $wpc_type = isset($attributes['type']) ? strtolower(trim((string) $attributes['type'])) : '';
        if ($wpc_type !== '' && strpos($wpc_type, 'javascript') === false) {
            return true;
        }
        if ($this->wpc_module_consumer($attributes)) {
            return true;
        }

        // v7.21.55 — dependency providers of user-excluded scripts ride the same eager group
        // (matched by handle-id, survives path rewriting like the .31 form keep).
        $handle_id = isset($attributes['id']) ? strtolower(preg_replace('/-js$/', '', (string) $attributes['id'])) : '';
        if ($handle_id !== '') {
            $user_keep_handles = $this->wpc_user_exclude_keep_handles();
            if (isset($user_keep_handles[$handle_id])) {
                return true;
            }
        }

        // v7.21.31 — FORM-FAMILY ATOMIC KEEP, matched by HANDLE (survives path rewriting).
        // JetFormBuilder, its jet-plugins loader, and its field modules cross-depend through
        // RUNTIME GLOBALS (JetPlugins, window.JetFormBuilderAbstract, window.intlTelInput) — not
        // ES imports — so their execution order MUST equal document order. Under a security
        // plugin that rewrites plugin URLs (Hide My WP: /wp-content/plugins/... ->
        // /core/modules/<hash>/...), the src no longer carries the family name, so the src-only
        // keep is defeated, the set is split into the delay registry, and a module runs before
        // its provider — the whole form dies (acrystalglass.com /contact/, logged-out:
        // "JetPlugins is not defined" at the captcha module, "Cannot destructure 'InputData' of
        // window.JetFormBuilderAbstract" at jet-fb-blocks-v2-phone-field). Two matches, both
        // keyed on identifiers the rewrite CANNOT touch: (1) the script id/handle carries the
        // family token (jet-plugins-js, jet-form-builder-frontend-forms-js, jet-fb-blocks-v2-*);
        // (2) the wp_scripts dependency CLOSURE of any family handle — so a module's vendor
        // provider (intl-tel-input for the phone field) rides the same eager group instead of
        // inverting against it. Keeping the whole closure eager makes document order hold. This
        // is scoped to the family closure ONLY — NOT the general id-into-keep-haystack match the
        // .497 incident froze. Killable per-site via wpc_form_family_id_keep.
        if (apply_filters('wpc_form_family_id_keep', true)) {
            $form_id  = isset($attributes['id']) ? strtolower((string) $attributes['id']) : '';
            $form_src = isset($attributes['src']) ? strtolower(html_entity_decode((string) $attributes['src'])) : '';
            if ($form_id !== '' || $form_src !== '') {
                foreach ($this->wpc_form_family_tokens() as $family_token) {
                    if ($family_token !== ''
                        && (strpos($form_id, $family_token) !== false || strpos($form_src, $family_token) !== false)) {
                        return true;
                    }
                }
            }
            if ($form_id !== '' && substr($form_id, -3) === '-js') {
                $form_keep_handles = $this->wpc_form_keep_handles();
                if (isset($form_keep_handles[substr($form_id, 0, -3)])) {
                    return true;
                }
            }
        }

        // A sync-kept theme script that references jQuery needs jQuery itself sync —
        // an exempted nav-init throwing 'jQuery is not defined' at parse is dead forever.
        // Wins over force-delay: correctness before preference.
        if ($this->wpc_sync_jquery && !empty($attributes['src'])
            && preg_match('#/jquery(?:\.min)?\.js(?:\?|$)|jquery-migrate#i', (string) $attributes['src'])) {
            return true;
        }
        if ($content !== '' && (strpos($content, 'wps-ic-lazy-image') !== false || strpos($content, 'wps-ic-local-lazy') !== false || strpos($content, 'ngf298gh738qwbdh0s87v_vars') !== false)) {
            return true;
        }

        // A jQuery QUEUE-STUB (third-party defer snippet: fake window.jQuery that queues calls
        // until the real one lands) is a structural keep, never a delay candidate: replayed
        // inside the delayed partition it re-fakes window.jQuery around the real jQuery and
        // every later consumer breaks (fn={}, no expr). Its 'jQuery' content matched the
        // force-delay keyword, which is why the excludes-list pin alone was not enough — this
        // outranks force-delay, same law as the sync-jquery keep above.
        if ($content !== ''
            && (strpos($content, 'jqueryParams') !== false || strpos($content, 'customHeadScripts') !== false)
            && apply_filters('wpc_keep_jquery_stub', true)) {
            return true;
        }

        // v7.21.347 — the sby inline config (sbyOptions/sbyajaxurl) must ride eager with its
        // kept provider: sb-youtube.min.js reads it at parse (id/src-less inline, so the
        // family token above can never match it).
        if ($content !== ''
            && (strpos($content, 'sbyOptions') !== false || strpos($content, 'sbyajaxurl') !== false)
            && apply_filters('wpc_keep_sby_family', true)) {
            return true;
        }


        if (!empty($attributes['src'])) {
            $wpc_fsrc = strtolower((string) $attributes['src']);
            if ((strpos($wpc_fsrc, 'recaptcha') !== false || strpos($wpc_fsrc, 'hcaptcha') !== false
                    || strpos($wpc_fsrc, 'turnstile') !== false || strpos($wpc_fsrc, 'challenges.cloudflare') !== false)
                && apply_filters('wpc_force_delay_captcha', self::wpc_force_delay_on('captcha'))) {
                return false;
            }
            if (strpos($wpc_fsrc, 'jquery') !== false
                && apply_filters('wpc_force_delay_jquery', self::wpc_force_delay_on('jquery'))) {
                return false;
            }
        }


        if (!empty($this->userForceDelay)) {
            if (!empty($attributes['src']) && $this->checkKeyword((string) $attributes['src'], $this->userForceDelay)) {
                return false;
            }
            if (!empty($content) && $this->checkKeyword($content, $this->userForceDelay)) {
                return false;
            }
        }

        // Family lane-coupling keep: a dependency-chain member (registrar riding its scanner's
        // lane, runtime riding its consumer's) is a LOAD-ORDER invariant, so it outranks even
        // the lane force-delay below — a lane pattern must never split a webpack family.
        if (!empty($attributes['src']) && !empty($attributes['id'])
            && isset($this->wpc_family_keep_ids[(string) $attributes['id']])) {
            return true;
        }

        // v7.10.762 — LAYOUT-VAR WRITERS ARE NEVER DELAYED. A script that writes a CSS custom
        // property consumed by stylesheet layout rules is a LAYOUT INPUT: Breakdance's header
        // measurer sets --site-header-height, and .hero-section's margin-top is
        // calc(var(--site-header-height) - 1px) — with the writer delayed, the var is unset at
        // first paint, the calc is invalid, margin falls to 0, and the whole page shifts down
        // 119px at gesture release (heritagepavingltd served-bytes receipt: no style attr on
        // body server-side; the var only ever comes from JS). Same law as the .747 scanner
        // coupling: outranks lane force-delay, honors nothing below it.
        // v7.10.786 — A GLOBAL SOMEONE ELSE DESTRUCTURES IS A LOAD-ORDER INVARIANT. Heritage,
        // post-purge: "Cannot destructure property 'BASE_BREAKPOINT_ID' of
        // 'window.BreakdanceFrontend.data' as it is undefined" — breakdance-swiper runs at
        // DOM-ready and reads a global whose definition sat in a delayed carrier. Keeping the
        // DEFINER eager costs a few bytes; delaying it breaks every consumer that does not
        // guard, and a destructure throws rather than degrading. Keyed on the global's NAME so
        // it holds whichever carrier defines it (inline block or external file).
        $global_define_keeps = apply_filters('wpc_global_define_keep', ['BreakdanceFrontend']);
        if (!empty($global_define_keeps) && is_array($global_define_keeps)) {
            foreach ($global_define_keeps as $global_name) {
                if ($global_name === '') { continue; }
                if ((!empty($content) && strpos($content, (string) $global_name) !== false)
                    || (!empty($attributes['src']) && stripos((string) $attributes['src'], (string) $global_name) !== false)) {
                    return true;
                }
            }
        }

        $layout_var_keeps = apply_filters('wpc_layout_var_keep', ['--site-header-height', '--topbar-height', 'breakdance-utils']);
        if (!empty($layout_var_keeps) && is_array($layout_var_keeps)) {
            foreach ($layout_var_keeps as $layout_var) {
                if ($layout_var === '') { continue; }
                if ((!empty($content) && strpos($content, (string) $layout_var) !== false)
                    || (!empty($attributes['src']) && stripos((string) $attributes['src'], (string) $layout_var) !== false)) {
                    return true;
                }
            }
        }

        if (!empty($this->wpc_first_screen_sliders)) {
            if (!empty($attributes['src'])
                && preg_match('#/breakdance-elements/dependencies-files/(?:swiper@\d+/swiper-bundle(?:\.min)?\.js|breakdance-swiper/breakdance-swiper\.js)#i', (string) $attributes['src'])) {
                return true;
            }
            if ($content !== '' && strpos($content, 'BreakdanceSwiper') !== false) {
                foreach ($this->wpc_first_screen_sliders as $slider_id) {
                    if (strpos($content, "'" . $slider_id . "'") !== false || strpos($content, '"' . $slider_id . '"') !== false) {
                        return true;
                    }
                }
            }
        }

        // v7.10.395: lane-listed scripts outrank STALE structural pins — a scroll-behavior
        // script can never be a load-time dependency (its function needs the gesture that
        // releases it), but a link-and-go-era prescription pin held sticky eager forever.
        // Still honors per-tag opt-outs, the UI exclusion list and user keeps.
        if (!empty($attributes['src']) && !empty($this->wpc_lane_force_delay)
            && empty($attributes['data-nodefer'])
            && (empty($attributes['data-priority']) || $attributes['data-priority'] !== 'high')
            && $this->checkKeyword(strtolower(html_entity_decode((string) $attributes['src'])), $this->wpc_lane_force_delay)
            && !$this->checkKeyword(strtolower((string) $attributes['src']
                . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id']) ? ' ' . (string) $attributes['id'] : '')), $this->excludes)
            && !$this->wpc_user_delay_excluded((string) $attributes['src'])) {
            return false;
        }

        if (!empty($attributes['src']) && !empty($attributes['id'])
            && isset($this->companion_ids[(string) $attributes['id']])) {
            return true;
        }


        if (!empty($attributes['src']) && !empty($this->promoted_src_ids)
            && !empty($attributes['id']) && isset($this->promoted_src_ids[(string) $attributes['id']])) {
            return true;
        }

        // Vendor lane patterns: SRC-only force-delay, deliberately BELOW every
        // structural keep (sync-jquery, companion, promoted — a lane pattern must never
        // break a dependency chain). Honors the per-tag opt-outs, the UI exclusion list,
        // AND the settings/consent keep list ($this->excludes — carries gtag when
        // gtag-lazy=0, consent families, etc.): an explicit user/preset keep always wins
        // over a service auto-delay. External vendor hosts only (our zone = locals).
        // Inline content is never matched against these (the dm class).
        if (!empty($attributes['src']) && !empty($this->wpc_src_force_delay)
            && empty($attributes['data-nodefer'])
            && (empty($attributes['data-priority']) || $attributes['data-priority'] !== 'high')
            && !$this->checkKeyword(strtolower((string) $attributes['src']
                . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id']) ? ' ' . (string) $attributes['id'] : '')), $this->excludes)
            && !$this->wpc_user_delay_excluded((string) $attributes['src'])) {
            $lane_src = strtolower(html_entity_decode((string) $attributes['src']));
            $lane_host = strtolower((string) parse_url($lane_src, PHP_URL_HOST));
            if ($lane_host !== '' && !$this->wpc_is_own_host($lane_host)
                && $this->checkKeyword($lane_src, $this->wpc_src_force_delay)) {
                return false;
            }
            // v7.10.393: the external-host guard above made the .387 lane a NO-OP on every
            // CDN-on site (sticky rides our zone = own host). Lane entries are first-party
            // scroll-behavior scripts — the same excludes/user-keeps above still win.
            if (!empty($this->wpc_lane_force_delay)
                && $this->checkKeyword($lane_src, $this->wpc_lane_force_delay)) {
                return false;
            }
        }
        if (empty($attributes['src'])) {
            if (!empty($attributes['id']) && isset($this->companion_ids[(string) $attributes['id']])) {
                return true;
            }
            // WP registers jQuery's inline companions under the ALIAS handle 'jquery'
            // (id jquery-js-after) while the tag is jquery-core-js — the companion map
            // keys off the tag id and misses them; delaying them breaks $.each bridges
            if (!empty($attributes['id'])
                && preg_match('/^jquery(?:-core|-migrate)?-js-(?:before|after|extra)$/', (string) $attributes['id'])
                && isset($this->parse_time_src_ids['jquery-core-js'])) {
                return true;
            }
            if (!empty($this->manifest_names) && !empty($attributes['id']) && isset($this->manifest_names[(string) $attributes['id']])) {


                if (preg_match('/^(.+-js)-(?:before|after|extra)$/', (string) $attributes['id'], $wpc_pm)
                    && !isset($this->parse_time_src_ids[$wpc_pm[1]])) {

                } else {
                    return true;
                }
            }
            if (!empty($this->manifest_inline) && $content !== ''
                && isset($this->manifest_inline[substr(sha1($content), 0, 16)])) {
                return true;
            }
            // v7.21.24 — ANTI-FOUC THEME SETTERS ARE NEVER DELAYED, matched by SIGNATURE not by
            // name. .12 pinned Bricks' 'dl-mode' by handle, but ridgeway's real setter is Core
            // Framework's core-framework-theme-loader-js-after (reads localStorage['cf-theme'],
            // falls back to matchMedia, adds cf-theme-dark to <html>) — parked as delayed-script-1,
            // so dark visitors flashed light on every nav while the palette sat paint-ready in the
            // live vars-guard. The signature IS the class: a tiny inline that grabs the root
            // element, reads a stored/OS preference, and writes class/dataset/attribute state.
            // With the .23 state-rule guarantee, eager is paint-correct by the site's own
            // construction. Sits below userForceDelay: an explicit owner delay still wins.
            if ($content !== '' && strlen($content) <= 3500
                && apply_filters('wpc_theme_setter_eager', true)
                && preg_match('/documentElement|querySelector\s*\(\s*["\']html["\']\s*\)|getElementsByTagName\s*\(\s*["\']html["\']/', $content)
                && preg_match('/localStorage|matchMedia|prefers-color-scheme/i', $content)
                && preg_match('/classList|dataset\s*[.\[]|setAttribute\s*\(/', $content)) {
                return true;
            }
            // Pure data-assignment inlines (var X = {...} / window.X = [...]) are inert:
            // delaying them buys zero TBT and starves non-delayed consumers of their data
            // (cross-handle deps the companion map can't pair — Divi sticky-elements class)
            if ($content !== '' && strlen($content) < 200000
                && !preg_match('/\bfunction\b|=>|\bdocument\.|addEventListener|\bjQuery\b|\$\s*\(/i', $content)
                && preg_match('/^\s*(?:\/\*.*?\*\/\s*)?(?:var|let|const|window\.)\s*[\w$.\[\]\'"]+\s*=\s*(?:\{|\[|"|\'|JSON\.parse|\d|[\w$.]+\s*\|\|)/s', $content)) {
                return true;
            }
        }
        return parent::should_exclude_script($attributes, $content);
    }


    protected $wpc_captcha_intent = false;

    protected $wpc_sync_jquery = false;

    protected function process_script_tag($matches)
    {


        if (isset($matches[0]) && strpos($matches[0], 'wpc-arm-sentinel') !== false) {
            return $matches[0];
        }
        if (isset($matches[0]) && strpos($matches[0], 'wpc-rootvar-early') !== false) {
            return $matches[0];
        }
        // Keyless Maps (key= empty/absent) can never initialize — 365KB of dead bytes.
        if (isset($matches[0]) && stripos($matches[0], 'maps.googleapis.com') !== false
            && apply_filters('wpc_kill_keyless_maps', true)) {
            $wpc_km = $this->parse_script_attributes($matches[0]);
            $wpc_ks = isset($wpc_km['src']) ? html_entity_decode((string) $wpc_km['src']) : '';
            if ($wpc_ks !== '' && stripos($wpc_ks, 'maps.googleapis.com/maps/api/js') !== false
                && !preg_match('/[?&]key=[^&\s\'"]/i', $wpc_ks)) {
                return '';
            }
        }
        // Vendor dedupe (recaptcha ×6 class): an identical EXTERNAL src with no inline
        // body and no data- attrs is a pure duplicate — one tag serves every consumer.
        // Scoped to KNOWN vendor-family srcs only (io families + lane patterns): an
        // unrestricted first-seen-wins would let an inert/commented earlier copy of an
        // arbitrary script suppress the live one. Runs BEFORE capture so duplicates
        // never reach the registry or the io stamp.
        if (isset($matches[0]) && apply_filters('wpc_delay_dedupe_vendor', true)
            && (!isset($matches[1]) || trim((string) $matches[1]) === '')
            && stripos($matches[0], 'data-') === false) {
            $dedupe_families = $this->wpc_captcha_intent
                ? ['recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com']
                : [];
            if (!empty($this->wpc_io_patterns)) {
                $dedupe_families = array_merge($dedupe_families, $this->wpc_io_patterns);
            }
            if (!empty($this->wpc_src_force_delay)) {
                $dedupe_families = array_merge($dedupe_families, $this->wpc_src_force_delay);
            }
            $dedupe_attrs = !empty($dedupe_families) ? $this->parse_script_attributes($matches[0]) : [];
            // Consent-blocked copies (type="text/plain" etc.) are the consent manager's
            // to activate — they neither seed the seen-map nor get dropped.
            if (!empty($dedupe_attrs['type']) && stripos((string) $dedupe_attrs['type'], 'javascript') === false) {
                $dedupe_attrs = [];
            }
            if (!empty($dedupe_attrs['src'])) {
                $dedupe_src = html_entity_decode((string) $dedupe_attrs['src']);
                $dedupe_host = strtolower((string) parse_url($dedupe_src, PHP_URL_HOST));
                if ($dedupe_host !== '' && !$this->wpc_is_own_host($dedupe_host)
                    && $this->checkKeyword($dedupe_src, $dedupe_families)) {
                    if (isset($this->wpc_seen_ext_srcs[$dedupe_src])) {
                        return '';
                    }
                    if (count($this->wpc_seen_ext_srcs) < 50) {
                        $this->wpc_seen_ext_srcs[$dedupe_src] = 1;
                    }
                }
            }
        }
        $wpc_pre_n = is_array($this->script_registry) ? count($this->script_registry) : 0;
        $out = parent::process_script_tag($matches);


        // io cohort stamp: captcha families (manifest intent, unchanged) + vendor match[]
        // patterns. A2 guard is uniform: a jQuery-referencing src never demotes to the
        // io injection path (plain async tags bypass the ordered S()/C() replay).
        $io_families = [];
        if ($this->wpc_captcha_intent) {
            $io_families = ['recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com'];
        }
        if (!empty($this->wpc_io_patterns)) {
            $io_families = array_merge($io_families, $this->wpc_io_patterns);
        }
        if (!empty($io_families) && is_array($this->script_registry)) {
            $wpc_n = count($this->script_registry);
            for ($wpc_i = $wpc_pre_n; $wpc_i < $wpc_n; $wpc_i++) {
                $wpc_src = isset($this->script_registry[$wpc_i]['src']) ? (string) $this->script_registry[$wpc_i]['src'] : '';
                if ($wpc_src !== '' && !empty($this->script_registry[$wpc_i]['encoded'])) {
                    $wpc_dec = base64_decode($wpc_src, true);
                    if ($wpc_dec !== false) {
                        $wpc_src = $wpc_dec;
                    }
                }
                if ($wpc_src === '') {
                    continue;
                }
                foreach ($io_families as $wpc_fam) {
                    if (stripos($wpc_src, $wpc_fam) !== false) {
                        // io injection is plain-async (outside ordered replay): only
                        // EXTERNAL-host leaf scripts may demote — a same-host src can
                        // have delayed dependents; it stays in the ordered registry.
                        $io_host = strtolower((string) parse_url($wpc_src, PHP_URL_HOST));
                        // our hosts (origin, wpc edge, OR the configured CDN zone) serve
                        // rewritten locals — never io-demotable
                        $is_external = $io_host !== '' && !$this->wpc_is_own_host($io_host);
                        if ($is_external && !self::wpc_src_needs_jquery($wpc_src)) {
                            $this->script_registry[$wpc_i]['io'] = 1;
                        }
                        break;
                    }
                }
            }
        }
        if ($out === $matches[0]) {
            $attrs = $this->parse_script_attributes($out);
            $type  = isset($attrs['type']) ? strtolower(trim((string) $attrs['type'])) : '';
            $script_id = isset($attrs['id']) ? strtolower(trim((string) $attrs['id'])) : '';
            // v7.10.512 — .497 ANDed this with a filter defaulting FALSE, so the .494
            // disqualification was always false and every paired external was deferred again
            // while its inline -after companion ran at parse. The hazard .497 actually named
            // was un-deferring only SOME keeps; wpc_nodefer_all512 answers that by un-deferring
            // ALL of them whenever any pair exists, so the keep set never carries mixed order.
            $paired = $script_id !== '' && !empty($this->wpc_inline_pair_ids[$script_id]);
            if (!$paired && !empty($this->wpc_jquery_parse_needed) && $script_id !== ''
                && self::wpc_is_jquery_script_id($script_id)) {
                $paired = true;
            }
            if (!empty($attrs['src']) && !isset($attrs['defer']) && !isset($attrs['async'])
                && !$paired && empty($this->wpc_nodefer_all_keeps)
                && ($type === '' || strpos($type, 'javascript') !== false)) {
                // Excluded-from-delay srcs run deferred: parse never blocks and document
                // order preserves the jquery -> theme chain (defer executes in order).
                // data-wpc-defer marks OURS so the split pass can strip without touching
                // author-supplied defer.
                return preg_replace('/<script\b/i', '<script defer data-wpc-defer="1"', $out, 1);
            }
        }
        return $out;
    }

    // The kept set's wp_scripts dependency closure: ids (handle + '-js') that are ON the page,
    // NOT yet kept, and reachable from any kept handle's dependency graph. Pure computation —
    // the caller stamps the three keep sets. Cap-bounded, kill wpc_keep_dep_closure.
    /**
     * The measured manifest's keep entries, as [list, entry] pairs: render_critical and
     * atf_mutators, top level and per device (the v1 envelope), the first 20 of each list, an
     * {key} object unwrapped to its string, anything shorter than 4 characters skipped.
     * The one reading of those two lists: process_html builds its keep sets from it, and
     * keeps_script_at_load() asks wpc_manifest_names_script().
     */
    public static function wpc_manifest_keep_entries($manifest)
    {
        $entries = [];
        if (!is_array($manifest)) {
            return $entries;
        }
        $sections = [$manifest];
        foreach (['mobile', 'desktop'] as $device) {
            if (!empty($manifest[$device]) && is_array($manifest[$device])) {
                $sections[] = $manifest[$device];
            }
        }
        foreach ($sections as $section) {
            foreach (['render_critical', 'atf_mutators'] as $list) {
                if (empty($section[$list]) || !is_array($section[$list])) {
                    continue;
                }
                foreach (array_slice($section[$list], 0, 20) as $entry) {
                    if (is_array($entry) && !empty($entry['key'])) {
                        $entry = $entry['key'];
                    }
                    if (!is_string($entry) || strlen($entry) < 4) {
                        continue;
                    }
                    $entries[] = [$list, $entry];
                }
            }
        }
        return $entries;
    }

    /**
     * True when this page's measured manifest keeps a script with this src (by path, or by the
     * v1 basename), so the delay will run it at load rather than hold it. An unreadable manifest
     * answers true: the caller holds something only when it knows the script is delayed.
     */
    public static function wpc_manifest_names_script($src)
    {
        $manifestFile = self::wpc_delay_manifest_file();
        if (!is_string($manifestFile) || $manifestFile === '') {
            return false;
        }
        $manifest = json_decode((string) @file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            return true;
        }
        $srcPath = (string) parse_url((string) $src, PHP_URL_PATH);
        $srcBasename = strtolower(basename($srcPath));
        foreach (self::wpc_manifest_keep_entries($manifest) as $keep) {
            $entry = $keep[1];
            if (strpos($entry, 'inline:') === 0) {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                if ((string) parse_url($entry, PHP_URL_PATH) === $srcPath) {
                    return true;
                }
            } elseif (strtolower($entry) === $srcBasename) {
                return true;
            }
        }
        return false;
    }

    /**
     * The manifest entries the keep reader never reaches: everything past the first 20 of each
     * render_critical / atf_mutators list (top level and per device), as basenames.
     */
    public static function wpc_manifest_keep_overflow($manifest)
    {
        $names = [];
        if (!is_array($manifest)) {
            return $names;
        }
        $sections = [$manifest];
        foreach (['mobile', 'desktop'] as $device) {
            if (!empty($manifest[$device]) && is_array($manifest[$device])) {
                $sections[] = $manifest[$device];
            }
        }
        foreach ($sections as $section) {
            foreach (['render_critical', 'atf_mutators'] as $list) {
                if (empty($section[$list]) || !is_array($section[$list])) {
                    continue;
                }
                foreach (array_slice($section[$list], 20) as $entry) {
                    if (is_array($entry) && !empty($entry['key'])) {
                        $entry = $entry['key'];
                    }
                    if (!is_string($entry) || strlen($entry) < 4 || strpos($entry, 'inline:') === 0) {
                        continue;
                    }
                    $names[strtolower(basename((string) strtok($entry, '?')))] = true;
                }
            }
        }
        return array_keys($names);
    }

    /**
     * The name a Delay JS error report says is missing: ['kind' => 'fn'|'global', 'name' => NAME] for
     * "X is not a function", "X is not defined" and Safari's "Can't find variable: X", else null.
     */
    public static function wpc_missing_symbol($message)
    {
        $message = (string) $message;
        if (preg_match('/can\'?t find variable:\s*([A-Za-z_$][\w$]*)/i', $message, $found)
            || preg_match('/([A-Za-z_$][\w$]*)\s+is not defined/i', $message, $found)) {
            return ['kind' => 'global', 'name' => $found[1]];
        }
        if (preg_match('/([A-Za-z_$][\w$]*)\s+is not a function/i', $message, $found)) {
            return ['kind' => 'fn', 'name' => $found[1]];
        }
        return null;
    }

    /**
     * The Delay JS exclude patterns for the same-site scripts the render saw delayed that define a
     * missing name (wpc_missing_symbol): a jQuery plugin of that name for "is not a function", a
     * window global of that name for either kind. The pattern is the file's basename, or its path
     * under wp-content when the basename is one many scripts share (main.js, plugins.min.js). Only
     * files still on disk, and never the file that threw ($thrower_url).
     */
    public static function wpc_provider_patterns($symbol, $thrower_url = '')
    {
        $index = get_option('wpc_delay_v3_providers', []);
        if (!is_array($index) || !is_array($symbol) || empty($symbol['name'])) {
            return [];
        }
        $thrower = '/' . ltrim((string) parse_url(self::wpc_script_origin_url($thrower_url), PHP_URL_PATH), '/');
        $keys = $symbol['kind'] === 'fn' ? ['fn:' . $symbol['name'], 'g:' . $symbol['name']] : ['g:' . $symbol['name']];
        $patterns = [];
        foreach ($keys as $key) {
            foreach ((array) ($index[$key] ?? []) as $path) {
                $path = (string) $path;
                if ($path === $thrower || self::wpc_script_path_file($path) === '') {
                    continue;
                }
                $base = basename($path);
                $shared = preg_match('/^(?:main|index|app|script|scripts|common|frontend|front|theme|custom|global|bundle|vendor|vendors|plugins|all|public|admin|core)(?:\.min)?\.js$/i', $base) === 1;
                $content_at = strpos($path, '/wp-content/');
                $patterns[$shared && $content_at !== false ? substr($path, $content_at) : $base] = true;
            }
        }
        return array_keys($patterns);
    }

    /**
     * What the Delay JS tuner keeps for a missing-name error (wpc_missing_symbol): ['as' => 'provider',
     * 'patterns' => wpc_provider_patterns()] when a script on record other than the thrower defines
     * the name; ['as' => 'thrower', 'patterns' => [the thrower's basename]] when no script on disk
     * on record defines it (an inline or a foreign global) and no pattern of the site's Delay JS
     * exclude list already keeps the thrower; otherwise ['as' => 'none', 'patterns' => []] (the
     * thrower is the only definer on record, or it already runs at load).
     */
    public static function wpc_missing_symbol_keep($symbol, $thrower_url)
    {
        $providers = self::wpc_provider_patterns($symbol, $thrower_url);
        if ($providers !== []) {
            return ['as' => 'provider', 'patterns' => $providers];
        }
        $thrower = basename((string) parse_url((string) $thrower_url, PHP_URL_PATH));
        if ($thrower === '' || self::wpc_provider_patterns($symbol) !== [] || self::wpc_delay_exclude_matches($thrower_url)) {
            return ['as' => 'none', 'patterns' => []];
        }
        return ['as' => 'thrower', 'patterns' => [$thrower]];
    }

    /**
     * True when a pattern of the site's Delay JS exclude list (delay_js_v3, read through
     * wpc_delay_excludes_fold) is in the script URL, compared without case as the engine compares
     * it: the script runs at load on every page.
     */
    public static function wpc_delay_exclude_matches($src)
    {
        $excludes = function_exists('get_option') ? get_option('wpc-excludes', []) : [];
        if (!is_array($excludes)) {
            return false;
        }
        if (function_exists('wpc_delay_excludes_fold')) {
            $excludes = wpc_delay_excludes_fold($excludes);
        }
        $src = strtolower((string) $src);
        foreach ((array) ($excludes['delay_js_v3'] ?? []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && strpos($src, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The promotion kill switch is on: the site-wide promoted list and every page's record of what
     * it promoted are dropped, because nothing is promoted until the switch clears.
     */
    public static function wpc_promotion_clear()
    {
        if (get_option('wpc_delay_v3_promoted', false) !== false) {
            delete_option('wpc_delay_v3_promoted');
        }
        $log = get_option('wpc_delay_v3_promotion_log', false);
        if (is_array($log)) {
            $kept = [];
            foreach ($log as $key => $entry) {
                if (substr((string) $key, 0, 1) === '*') {
                    $kept[$key] = $entry;
                }
            }
            if ($kept !== $log) {
                update_option('wpc_delay_v3_promotion_log', $kept, false);
            }
        }
    }

    /**
     * Record what this render did with the measured manifest's promotions, in the option
     * wpc_delay_v3_promotion_log: for a page (url key) the basenames applied and, per basename,
     * why one was skipped (cap, shared-basename, list-truncated); for the whole site the reason no
     * page could promote anything (*manifest_off, *no-delay-json), refreshed hourly. Written only
     * when it changes; rows older than 14 days go; 40 rows at most.
     */
    public static function wpc_promotion_note($key, array $applied, array $skipped, $site_reason = '')
    {
        $key = (string) $key;
        $now = time();
        $log = get_option('wpc_delay_v3_promotion_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        $before = $log;
        foreach ($log as $row_key => $row) {
            if (!is_array($row) || (int) ($row['t'] ?? 0) < $now - 14 * DAY_IN_SECONDS) {
                unset($log[$row_key]);
            }
        }
        if ($site_reason === 'manifest_off') {
            foreach (array_keys($log) as $row_key) {
                if (substr((string) $row_key, 0, 1) !== '*') {
                    unset($log[$row_key]);
                }
            }
        } else {
            unset($log['*manifest_off']);
        }
        if ($site_reason !== '') {
            unset($log[$key]);
            $mark = '*' . $site_reason;
            if (!isset($log[$mark]) || (int) $log[$mark]['t'] < $now - HOUR_IN_SECONDS) {
                $log[$mark] = ['t' => $now, 'key' => $key];
            }
        } elseif ($key !== '') {
            if ($applied === [] && $skipped === []) {
                unset($log[$key]);
            } else {
                $row = ['applied' => array_slice(array_values($applied), 0, 12), 'skipped' => array_slice($skipped, 0, 12, true)];
                if (!isset($log[$key]) || array_diff_key($log[$key], ['t' => 1]) !== $row) {
                    $log[$key] = $row + ['t' => $now];
                }
            }
        }
        if (count($log) > 40) {
            uasort($log, function ($a, $b) { return (int) $b['t'] - (int) $a['t']; });
            $log = array_slice($log, 0, 40, true);
        }
        if ($log !== $before) {
            update_option('wpc_delay_v3_promotion_log', $log, false);
        }
    }

    /**
     * The builder runtime keeps (Bricks' swapper and its libs, Divi 5's script library) added to
     * this render's keep list. One place, asked by process_html and by keeps_script_at_load().
     * Idempotent: the list is merged uniquely.
     */
    protected function wpc_apply_builder_runtime_keeps($html, $noteReceipts = true)
    {
        // v7.21.56 — A LANE MUST CARRY ITS OWN REMOVER, theme edition: Bricks' native image
        // lazy-load ships every img as a transparent SVG placeholder (src=data:image/svg+xml
        // + data-src + .bricks-lazy-hidden) and ONLY bricks.min.js swaps the real image in.
        // On unmeasured renders (post-save purge window, fresh installs) the delay lane
        // captured that swapper into the gesture/timer registry — every image on the page
        // stayed a blank placeholder until first touch (ridgeway receipt: cached render kept
        // it `defer data-wpc-defer`, cache-bypassed render had it base64'd in the registry).
        // Presence-guarded on the marker class Bricks' own JS removes; the keep lands in the
        // excluded-src defer lane, matching the measured pages' shape exactly.
        if (strpos($html, 'bricks-lazy-hidden') !== false
            && apply_filters('wpc_bricks_swapper_keep', true)) {
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, [
                'bricks.min.js',
            ])));
            // A builder runtime the delay would hold is kept eager because delaying it broke a
            // page (keep lists, one per builder). Sampled: the page's scripts repeat every render.
            if (strpos($html, 'bricks.min.js') !== false && $noteReceipts && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('delay-keep-rules', ['bricks_swapper' => 1], true);
            }
        }
        // v7.22.35 — A KEPT RUNTIME KEEPS ITS OWN LIBS. bricks.min.js initialises every element
        // in ONE DOMContentLoaded listener; a lib it needs (splide, swiper, photoswipe, leaflet
        // — Bricks enqueues each only when the element is on the page) that is still in the
        // delay registry is checked once by functionCanRun() and never again: the slider
        // element on that page stays dead after the replay. Now that kept scripts' DCL
        // listeners flush at the gesture (loader .35) the libs must be there by then: they
        // ride with the runtime, same lane, same order.
        if (in_array('bricks.min.js', (array) $this->excludes, true)
            && apply_filters('wpc_bricks_libs_keep', true)) {
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, [
                '/themes/bricks/assets/js/libs/',
            ])));
            if (strpos($html, '/themes/bricks/assets/js/libs/') !== false && $noteReceipts && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('delay-keep-rules', ['bricks_libs' => 1], true);
            }
        }

        // v7.21.92 — A BUILDER'S RUNTIME IS NEVER DELAY CARGO (WP-3 F-2, Divi leg; Bricks
        // shipped .56). Divi 5's script library ARRANGES the page — menu, animation,
        // multi-view (responsive content swapping), frontend-scripts: delayed to ~4s they
        // made falknerei "then it's fine" only after the loader fired (waterfall receipt:
        // every script-library-* initiated by delay-v3-loader). They join the excluded-src
        // defer lane: same order, parse-adjacent execution. Kill wpc_divi_runtime_keep.
        if ((strpos($html, '/themes/Divi/') !== false || strpos($html, 'et_pb_') !== false)
            && apply_filters('wpc_divi_runtime_keep', true)) {
            // Name stems, not file names: SiteGround Optimizer serves every one of these renamed
            // to divi-<name>.min.js, which the old 'name.js' entries never matched, so the whole
            // runtime stayed delay cargo. theme-scripts-library-menu sets #page-container's top
            // padding to the header height; delayed, the 80px CSS default holds until a gesture
            // and the mobile hero sits 21px low under a white strip (bgqld.com.au, 2026-10-07).
            // theme-scripts-library itself stays exact so the stem does not take in every
            // theme-scripts-library-* file.
            $diviRuntime = [
                'theme-scripts-library-base',
                'theme-scripts-library-scroll-to-top',
                'theme-scripts-library-menu',
                'script-library-frontend-global-functions',
                'script-library-global-functions',
                'script-library-frontend-scripts',
                'script-library-ext-waypoint',
                'script-library-menu',
                'script-library-animation',
                'script-library-multi-view',
                'script-library-link',
                'theme-scripts-library.js',
                'theme-scripts-library.min.js',
            ];
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, $diviRuntime)));
            $diviKept = 0;
            foreach ($diviRuntime as $diviFile) {
                if (strpos($html, $diviFile) !== false) {
                    $diviKept++;
                }
            }
            if ($diviKept > 0 && $noteReceipts && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('delay-keep-rules', ['divi_runtime' => $diviKept], true);
            }
        }

        if (strpos($html, 'data-vc-full-width') !== false && apply_filters('wpc_wpbakery_runtime_keep', true)) {
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, ['js_composer_front'])));
            if (strpos($html, 'js_composer_front') !== false && $noteReceipts && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('delay-keep-rules', ['wpbakery_runtime' => 1], true);
            }
        }

    }

    /**
     * Whether this render keeps a script at load rather than holding it in the delay registry,
     * answered from the delay's own keep inputs: its keep list (defaults and the builder runtime
     * keeps), the site's Delay JS exclusions, and the page's measured manifest. The facade asks
     * it before it holds a builder video's source for the replay (Divi 4 on webdesign4u.com.au):
     * a script this answers true for runs at load, and the source must stay.
     */
    /**
     * Ids of the Breakdance sliders (advanced slider, basic slider, gallery) whose markup sits in
     * the page's first two <section> blocks after <body>: the carousels on the first screen.
     */
    public static function wpc_breakdance_first_screen_sliders($html)
    {
        if (!is_string($html) || strpos($html, 'BreakdanceSwiper') === false) {
            return [];
        }
        $body = stripos($html, '<body');
        if ($body === false) {
            return [];
        }
        $end = strlen($html);
        if (preg_match_all('/<section\b/i', $html, $sections, PREG_OFFSET_CAPTURE, $body) && count($sections[0]) >= 3) {
            $end = $sections[0][2][1];
        }
        $ids = [];
        if (preg_match_all('/class="[^"]*\b(bde-(?:advancedslider|basicslider|gallery)-[0-9]+(?:-[0-9]+)*)\b/i', substr($html, $body, $end - $body), $found)) {
            foreach ($found[1] as $id) {
                $ids[$id] = 1;
            }
        }
        return array_keys($ids);
    }

    public function keeps_script_at_load($src, $html)
    {
        $src = (string) $src;
        if ($src === '') {
            return true;
        }
        $this->wpc_apply_builder_runtime_keeps((string) $html, false);
        return $this->checkKeyword($src, (array) $this->excludes)
            || $this->wpc_user_delay_excluded($src)
            || self::wpc_manifest_names_script($src);
    }

    public static function wpc_is_builder_mutator_script($src, $rc = [])
    {
        $src = strtolower((string) $src);
        if ($src === '') {
            return false;
        }
        $path = (string) parse_url($src, PHP_URL_PATH);
        if ($path === '') {
            $path = $src;
        }
        $base = strtolower(basename((string) strtok($path, '?')));
        if ($base === '' || (is_array($rc) && isset($rc[$base]))) {
            return false;
        }
        return (bool) preg_match('#/plugins/(?:elementor|elementor-pro)/assets/(?:js|lib)/#', $path)
            || (bool) preg_match('#/wp-includes/js/dist/(?:a11y|i18n|hooks|dom-ready)(?:\.min)?\.js$#', $path);
    }

    public static function wpc_embed_gate_inline_js()
    {
        return '!function(){try{if(window.wpcFlushHeavyEmbeds)return;var F="undefined"!=typeof HTMLIFrameElement?Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype,"src"):null,S="undefined"!=typeof HTMLScriptElement?Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype,"src"):null,C=document.createElement;'
            . 'var H=function(){var c=window.wpcDelayV3Cfg||{},l=Array.isArray(c.heavyEmbeds)?c.heavyEmbeds.slice():[];return l.push("youtube.com/iframe_api","youtube.com/player_api","player.vimeo.com/api/player.js","fast.wistia.com/assets/external/","fast.wistia.net/assets/external/"),l};'
            . 'var V=function(v){try{if(window.wpcDelayV3Cfg&&0==+window.wpcDelayV3Cfg.embedGate)return!1;if(window.__wpcEngaged||window.__wpcHeavyEmbedsReleased)return!1;var s=String(v||"");if(!s||0===s.indexOf("about:")||0===s.indexOf("data:")||0===s.indexOf("javascript:"))return!1;for(var l=H(),i=0;i<l.length;i++)if(-1!==s.indexOf(l[i]))return!0}catch(e){}return!1};'
            . 'window.__wpcHeavyEmbedQueue=window.__wpcHeavyEmbedQueue||[];window.wpcFlushHeavyEmbeds=function(){window.__wpcHeavyEmbedsReleased=1;var q=window.__wpcHeavyEmbedQueue||[];window.__wpcHeavyEmbedQueue=[];for(var j=0;j<q.length;j++)try{q[j][0].call(q[j][1],q[j][2])}catch(e){}try{window.wpcRestoreAllParkedBackgrounds&&window.wpcRestoreAllParkedBackgrounds()}catch(e){}};'
            . 'var G=function(el,v,set){if(!V(v))return!1;window.__wpcHeavyEmbedQueue.push([set,el,v]);try{el.setAttribute("data-wpc-embed-held","1")}catch(e){}return!0};'
            . 'var W=function(el,D){try{Object.defineProperty(el,"src",{configurable:!0,get:function(){return D.get.call(el)},set:function(v){G(el,v,(function(x){D.set.call(this,x)}))||D.set.call(el,v)}});var A=el.setAttribute;el.setAttribute=function(n,v){if("src"!==String(n).toLowerCase()||!G(el,v,(function(x){A.call(this,"src",x)})))return A.apply(el,arguments)}}catch(e){}};'
            . 'document.createElement=function(t){var el=C.apply(document,arguments),l=String(t).toLowerCase();if(S&&"script"===l)W(el,S);if(F&&"iframe"===l)W(el,F);return el}}catch(e){}}();';
    }

    /**
     * Every script a kept script needs, by id: its declared wp_scripts dependencies, and the
     * same-site delayed scripts that define a jQuery plugin or a window global a kept script
     * calls (a provider joins its consumer's lane). Providers are walked for their own
     * dependencies and their own providers, up to the page cap (wpc_keep_plugin_provider_cap).
     */
    protected function wpc_keep_dependency_closure($wpc_excluded_ids, $wpc_seen_src_ids, $html = '')
    {
        $closure_ids = [];
        if (!apply_filters('wpc_keep_dep_closure', true)) {
            return $closure_ids;
        }
        $wpc_excluded_ids = (array) $wpc_excluded_ids;
        $registered = (!empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered))
            ? $GLOBALS['wp_scripts']->registered : [];
        $stack = array_keys((array) $wpc_excluded_ids);
        $seen = [];
        $steps = 0;
        $delayed = null;
        $inline = null;
        $pulled = [];
        $provider_cap = (int) apply_filters('wpc_keep_plugin_provider_cap', 8);
        $round = 0;
        do {
            while (!empty($stack) && $steps < 400) {
                $steps++;
                $kept_id = (string) array_pop($stack);
                $handle = preg_replace('/-js$/', '', $kept_id);
                if ($handle === '' || isset($seen[$handle]) || !isset($registered[$handle])) {
                    continue;
                }
                $seen[$handle] = true;
                $deps = isset($registered[$handle]->deps) ? (array) $registered[$handle]->deps : [];
                foreach ($deps as $dep) {
                    $dep = (string) $dep;
                    if ($dep === '') {
                        continue;
                    }
                    $dep_id = $dep . '-js';
                    // Aliases (jquery -> jquery-core/jquery-migrate) have no tag of their own;
                    // re-entering the stack walks through them to the real carriers.
                    $stack[] = $dep_id;
                    if (isset($wpc_seen_src_ids[$dep_id]) && !isset($wpc_excluded_ids[$dep_id])
                        && !isset($closure_ids[$dep_id])) {
                        $closure_ids[$dep_id] = true;
                    }
                }
            }
            $added = [];
            if ($round < 3 && count($pulled) < $provider_cap && apply_filters('wpc_keep_plugin_providers', true)) {
                if ($delayed === null) {
                    $delayed = $this->wpc_delayed_local_scans($wpc_excluded_ids + $closure_ids, $wpc_seen_src_ids);
                    self::wpc_provider_index_note($delayed);
                }
                $added = $this->wpc_plugin_provider_ids(
                    array_keys($wpc_excluded_ids + $closure_ids),
                    $wpc_seen_src_ids,
                    $delayed,
                    $closure_ids + $wpc_excluded_ids,
                    $html,
                    $inline,
                    $provider_cap - count($pulled)
                );
            }
            foreach ($added as $provider_id => $why) {
                $closure_ids[$provider_id] = true;
                $pulled[$provider_id] = $why;
                $stack[] = $provider_id;
            }
            $round++;
        } while (!empty($added));
        if (!empty($pulled) && function_exists('wpc_render_belt_note')) {
            $pairs = [];
            foreach (array_slice($pulled, 0, 6, true) as $provider_id => $why) {
                $pairs[] = $why['name'] . ':' . preg_replace('/-js$/', '', (string) $provider_id) . '<' . preg_replace('/-js$/', '', (string) $why['by']);
            }
            wpc_render_belt_note('delay-keep-rules', ['plugin_providers' => count($pulled), 'plugin_pairs' => implode(',', $pairs)], true);
        }
        return array_keys($closure_ids);
    }

    /**
     * The file on disk behind a same-site script URL (the site's host, or our zone with the
     * site's path embedded), or '' for a foreign host, a non-.js path or a missing file.
     */
    protected function wpc_local_script_file($src)
    {
        $url = self::wpc_script_origin_url($src);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && !$this->wpc_is_own_host($host)) {
            return '';
        }
        return self::wpc_script_path_file((string) parse_url($url, PHP_URL_PATH));
    }

    /** A script URL as the site served it: a zone URL carrying the origin after /a: gives that origin. */
    protected static function wpc_script_origin_url($src)
    {
        $src = html_entity_decode((string) $src);
        $embedded = strrpos($src, '/a:');
        return $embedded !== false ? substr($src, $embedded + 3) : $src;
    }

    /** The readable .js file at a URL path under the content dir or wp-includes, or ''. */
    public static function wpc_script_path_file($path)
    {
        $path = '/' . ltrim((string) $path, '/');
        if (substr($path, -3) !== '.js' || strpos($path, '..') !== false) {
            return '';
        }
        $file = '';
        $content_path = function_exists('content_url') ? rtrim((string) parse_url(content_url(), PHP_URL_PATH), '/') : '';
        if ($content_path !== '' && strpos($path, $content_path . '/') === 0 && defined('WP_CONTENT_DIR')) {
            $file = WP_CONTENT_DIR . substr($path, strlen($content_path));
        } elseif (($pos = strpos($path, '/wp-content/')) !== false && defined('WP_CONTENT_DIR')) {
            $file = WP_CONTENT_DIR . substr($path, $pos + 11);
        } elseif (($pos = strpos($path, '/wp-includes/')) !== false && defined('ABSPATH')) {
            $file = rtrim(ABSPATH, '/') . substr($path, $pos);
        }
        return $file !== '' && @is_file($file) && @is_readable($file) ? $file : '';
    }

    /**
     * What a script file defines and calls: 'fn' the jQuery plugin names it assigns
     * (.fn.NAME=), 'glob' the window globals it assigns, 'call' the method names it calls
     * (only when $with_calls). Keyed by path + mtime + size: answered from this request's memo,
     * then from the scan store (wpc_script_scan_stored), and read from the file only when
     * neither holds the answer. A file over wpc_provider_scan_bytes answers null.
     */
    protected static function wpc_script_scan($file, $with_calls = false)
    {
        static $memo = [];
        $size = (int) @filesize($file);
        if ($size <= 0 || $size > (int) apply_filters('wpc_provider_scan_bytes', 524288)) {
            return null;
        }
        $key = $file . '|' . (int) @filemtime($file) . '|' . $size;
        if (!isset($memo[$key])) {
            $stored = self::wpc_script_scan_stored($key);
            if ($stored !== null) {
                $memo[$key] = $stored;
            }
        }
        if (isset($memo[$key]) && (!$with_calls || isset($memo[$key]['call']))) {
            return $memo[$key];
        }
        $body = @file_get_contents($file);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $scan = ['fn' => [], 'glob' => []];
        if (preg_match_all('/\.fn\.([A-Za-z_$][\w$]{2,})\s*=(?!=)/', $body, $found)) {
            $scan['fn'] = array_flip($found[1]);
        }
        if (preg_match_all('/\b(?:window|self|globalThis)\)?\.([A-Za-z_$][\w$]{2,})\s*=(?!=)/', $body, $found)) {
            $scan['glob'] = array_slice(array_flip($found[1]), 0, 40, true);
        }
        if ($with_calls || isset($memo[$key]['call'])) {
            $scan['call'] = preg_match_all('/\.([A-Za-z_$][\w$]{3,})\s*\(/', $body, $found) ? array_flip($found[1]) : [];
        }
        $memo[$key] = $scan;
        self::wpc_script_scan_keep($key, $scan);
        return $scan;
    }

    /**
     * The scan store: wpc-assets/delay/scan/ under uploads (the delay artifacts' directory, which
     * a purge leaves alone), one JSON file per path + mtime + size + plugin version holding only a
     * scan's name lists. False when uploads is unusable or not a local path, or the
     * wpc_script_scan_store filter answers false; the scan then reads the file on every request.
     */
    public static function wpc_script_scan_dir()
    {
        static $dir = null;
        if ($dir !== null) {
            return $dir;
        }
        $dir = false;
        if (!apply_filters('wpc_script_scan_store', true)) {
            return $dir;
        }
        $paths = self::wpc_registry_sidecar_paths();
        if (is_array($paths) && strpos($paths['dir'], '://') === false) {
            $dir = $paths['dir'] . 'scan/';
        }
        return $dir;
    }

    /** The store's file for a scan key. */
    protected static function wpc_script_scan_file($dir, $key)
    {
        return $dir . 's-' . md5($key . '|' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '')) . '.json';
    }

    /** A stored scan for $key: ['fn', 'glob'] and 'call' when it was kept with calls, or null. */
    protected static function wpc_script_scan_stored($key)
    {
        $dir = self::wpc_script_scan_dir();
        if ($dir === false) {
            return null;
        }
        $json = @file_get_contents(self::wpc_script_scan_file($dir, $key));
        if (!is_string($json) || $json === '') {
            return null;
        }
        $scan = json_decode($json, true);
        if (!is_array($scan) || !isset($scan['fn'], $scan['glob']) || !is_array($scan['fn']) || !is_array($scan['glob'])
            || (isset($scan['call']) && !is_array($scan['call']))) {
            return null;
        }
        return $scan;
    }

    /**
     * Writes a scan to the store (temporary file, then rename). The first write of a request that
     * finds the store at wpc_script_scan_store_cap files or more first drops the oldest down to
     * three quarters of it. A failed write ends the store's writes for the request.
     */
    protected static function wpc_script_scan_keep($key, array $scan)
    {
        static $count = null;
        static $broken = false;
        $dir = self::wpc_script_scan_dir();
        if ($dir === false || $broken) {
            return;
        }
        if (!@is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !@is_dir($dir)) {
                $broken = true;
                return;
            }
            @file_put_contents($dir . 'index.html', '');
        }
        if ($count === null) {
            $cap = max(10, (int) apply_filters('wpc_script_scan_store_cap', 2000));
            $stored = @glob($dir . 's-*.json');
            $count = is_array($stored) ? count($stored) : 0;
            if ($count >= $cap) {
                $count -= self::wpc_script_scan_evict($dir, (int) floor($cap * 3 / 4), 0);
            }
        }
        $json = json_encode($scan);
        $file = self::wpc_script_scan_file($dir, $key);
        $tmp = $file . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
        if (!is_string($json) || @file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) {
            @unlink($tmp);
            $broken = true;
            return;
        }
        $count++;
    }

    /**
     * Drops stored scans older than $max_age seconds (0: no age limit), then the oldest until at
     * most $keep remain. Returns how many files it removed.
     */
    public static function wpc_script_scan_evict($dir, $keep, $max_age)
    {
        $files = [];
        $stored = @glob($dir . 's-*.json');
        foreach (is_array($stored) ? $stored : [] as $file) {
            $files[$file] = (int) @filemtime($file);
        }
        asort($files);
        $removed = 0;
        $left = count($files);
        $cut = $max_age > 0 ? time() - (int) $max_age : 0;
        foreach ($files as $file => $mtime) {
            if ($mtime >= $cut && $left <= $keep) {
                break;
            }
            if (@unlink($file)) {
                $removed++;
                $left--;
            }
        }
        $temporary = @glob($dir . '*.tmp');
        foreach (is_array($temporary) ? $temporary : [] as $file) {
            if ((int) @filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
        return $removed;
    }

    /** True for a script that is analytics, ads or consent: never pulled into the eager lane for a plugin call. */
    protected function wpc_provider_refused($id, $src)
    {
        $haystack = strtolower($id . ' ' . $src);
        $tokens = (array) apply_filters('wpc_provider_refuse_tokens', ['analytics', 'gtag', 'googletagmanager', 'gtm.js', 'fbevents',
            'facebook', 'hotjar', 'clarity', 'mixpanel', 'segment', 'doubleclick', 'adsbygoogle', 'tiktok', 'linkedin',
            'matomo', 'plausible', 'pinterest']);
        return $this->checkKeyword($haystack, array_merge($tokens, self::wpc_consent_satellites()))
            || $this->checkKeyword($haystack, (array) $this->wpc_src_force_delay)
            || $this->checkKeyword($haystack, (array) $this->wpc_io_patterns)
            || (!empty($this->userForceDelay) && $this->checkKeyword($src, $this->userForceDelay));
    }

    /**
     * The same-site scripts of this page that are not kept, with what each defines:
     * id => ['src' => url, 'fn' => names, 'glob' => names]. At most 60 files; jQuery's own
     * carriers and refused scripts are left out.
     */
    protected function wpc_delayed_local_scans($kept_ids, $seen_src_ids)
    {
        $delayed = [];
        foreach ((array) $seen_src_ids as $id => $src) {
            $id = (string) $id;
            if (count($delayed) >= 60) {
                break;
            }
            if (isset($kept_ids[$id]) || substr($id, -3) !== '-js' || $src === '' || self::wpc_is_jquery_script_id($id)
                || $this->wpc_provider_refused($id, (string) $src)) {
                continue;
            }
            $file = $this->wpc_local_script_file($src);
            $scan = $file !== '' ? self::wpc_script_scan($file) : null;
            if ($scan !== null && ($scan['fn'] !== [] || $scan['glob'] !== [])) {
                $delayed[$id] = ['src' => (string) $src, 'fn' => $scan['fn'], 'glob' => $scan['glob']];
            }
        }
        return $delayed;
    }

    /**
     * Method names a plugin call can never be told apart from: jQuery's own instance API and the
     * names media and window objects share with it. A script that defines one of these
     * (a jQuery bundle, an animate or pause override) is not pulled for a call to it.
     */
    protected static function wpc_provider_name_deny()
    {
        return array_flip((array) apply_filters('wpc_provider_name_deny', ['add', 'addBack', 'addClass', 'after', 'animate', 'append', 'appendTo',
            'attr', 'before', 'bind', 'blur', 'change', 'children', 'clearQueue', 'click', 'clone', 'closest', 'contents', 'contextmenu', 'css',
            'data', 'dblclick', 'delay', 'delegate', 'dequeue', 'detach', 'each', 'empty', 'end', 'eq', 'error', 'extend', 'fadeIn', 'fadeOut',
            'fadeTo', 'fadeToggle', 'filter', 'find', 'finish', 'first', 'focus', 'focusin', 'focusout', 'get', 'has', 'hasClass', 'height',
            'hide', 'hover', 'html', 'index', 'init', 'innerHeight', 'innerWidth', 'insertAfter', 'insertBefore', 'jquery', 'keydown', 'keypress',
            'keyup', 'last', 'length', 'load', 'map', 'mousedown', 'mouseenter', 'mouseleave', 'mousemove', 'mouseout', 'mouseover', 'mouseup',
            'next', 'nextAll', 'nextUntil', 'not', 'off', 'offset', 'offsetParent', 'one', 'outerHeight', 'outerWidth', 'parent', 'parents',
            'parentsUntil', 'position', 'prepend', 'prependTo', 'prev', 'prevAll', 'prevUntil', 'promise', 'prop', 'push', 'pushStack', 'queue',
            'ready', 'remove', 'removeAttr', 'removeClass', 'removeData', 'replaceAll', 'replaceWith', 'resize', 'scroll', 'scrollLeft',
            'scrollTop', 'select', 'serialize', 'serializeArray', 'show', 'siblings', 'size', 'slice', 'slideDown', 'slideToggle', 'slideUp',
            'sort', 'splice', 'stop', 'submit', 'text', 'toArray', 'toggle', 'toggleClass', 'trigger', 'triggerHandler', 'unbind', 'undelegate',
            'unload', 'unwrap', 'val', 'width', 'wrap', 'wrapAll', 'wrapInner', 'live', 'die', 'andSelf', 'uniqueSort',
            'pause', 'resume', 'play', 'close', 'open', 'reset', 'start', 'abort', 'cancel', 'clear', 'reload', 'replace']));
    }

    /**
     * The delayed same-site scripts a kept script calls into: for each kept consumer (its file and
     * its inline companions), a delayed provider whose .fn.NAME it calls as .NAME(. Answers
     * provider id => ['by' => consumer id, 'name' => NAME], at most $room of them.
     */
    protected function wpc_plugin_provider_ids($kept_ids, $seen_src_ids, $delayed, $taken, $html, &$inline, $room)
    {
        $found = [];
        $by_name = [];
        $deny = self::wpc_provider_name_deny();
        foreach ($delayed as $provider_id => $facts) {
            if (isset($taken[$provider_id])) {
                continue;
            }
            foreach ($facts['fn'] as $name => $unused) {
                if (strlen($name) >= 4 && !isset($deny[$name])) {
                    $by_name[$name][$provider_id] = true;
                }
            }
        }
        if ($room <= 0 || empty($by_name)) {
            return $found;
        }
        if ($inline === null) {
            $inline = [];
            if (is_string($html) && $html !== ''
                && preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+-js-(?:before|after|extra))["\'][^>]*>(.*?)<\/script>/is', $html, $bodies, PREG_SET_ORDER)) {
                foreach ($bodies as $body) {
                    if (strlen($body[2]) <= 65536) {
                        $inline[$body[1]] = $body[2];
                    }
                }
            }
        }
        foreach ($kept_ids as $kept_id) {
            $kept_id = (string) $kept_id;
            if (self::wpc_is_jquery_script_id($kept_id)) {
                continue;
            }
            $called = [];
            $own = [];
            $file = isset($seen_src_ids[$kept_id]) && $seen_src_ids[$kept_id] !== '' ? $this->wpc_local_script_file($seen_src_ids[$kept_id]) : '';
            $scan = $file !== '' ? self::wpc_script_scan($file, true) : null;
            if ($scan !== null) {
                $called = $scan['call'];
                $own = $scan['fn'];
            }
            $handle = preg_replace('/-js$/', '', $kept_id);
            foreach (['-js-before', '-js-after', '-js-extra'] as $suffix) {
                if (isset($inline[$handle . $suffix]) && preg_match_all('/\.([A-Za-z_$][\w$]{3,})\s*\(/', $inline[$handle . $suffix], $calls)) {
                    $called += array_flip($calls[1]);
                }
            }
            if ($called === []) {
                continue;
            }
            foreach ($by_name as $name => $providers) {
                if (!isset($called[$name]) || isset($own[$name])) {
                    continue;
                }
                foreach (array_keys($providers) as $provider_id) {
                    if (isset($found[$provider_id])) {
                        continue;
                    }
                    $found[$provider_id] = ['by' => $kept_id, 'name' => $name];
                    if (count($found) >= $room) {
                        return $found;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Persist which same-site delayed script defines which jQuery plugin or window global
     * (option wpc_delay_v3_providers, 'fn:NAME' / 'g:NAME' => URL paths), written only when it
     * changes, 200 names at most, 3 paths a name. The Delay JS report handler reads it to name the script a
     * "NAME is not a function / not defined" error needs.
     */
    protected static function wpc_provider_index_note($delayed)
    {
        if (empty($delayed) || !function_exists('get_option') || !function_exists('update_option')) {
            return;
        }
        $index = get_option('wpc_delay_v3_providers', []);
        if (!is_array($index)) {
            $index = [];
        }
        $before = $index;
        foreach ($delayed as $facts) {
            $path = '/' . ltrim((string) parse_url(self::wpc_script_origin_url($facts['src']), PHP_URL_PATH), '/');
            foreach (['fn' => 'fn:', 'glob' => 'g:'] as $set => $prefix) {
                foreach ($facts[$set] as $name => $unused) {
                    $key = $prefix . $name;
                    if (!isset($index[$key]) && count($index) >= 200) {
                        continue;
                    }
                    $paths = isset($index[$key]) && is_array($index[$key]) ? $index[$key] : [];
                    if (!in_array($path, $paths, true) && count($paths) < 3) {
                        $paths[] = $path;
                        unset($index[$key]);
                        $index[$key] = $paths;
                    }
                }
            }
        }
        if ($index !== $before) {
            update_option('wpc_delay_v3_providers', $index, false);
        }
    }

    // v7.23.15 — ONE LOADER TAG BUILDER. The uploads copy + retro-heal, the .159 self-probe,
    // the zone swap under the suppression umbrella, the .155 onerror fallback and the inline
    // branch all live here, not in the delay engine: Critical CSS needs the same loader file
    // (it is the only restorer of parked sheets and the only armer of wpc-bgl255) on renders
    // where the engine is off — see wpc_css_only_loader. Every caller gets the identical tag.
    /**
     * Where the delay registry sidecars live: the uploads dir and the root-relative URL of
     * wpc-assets/delay/, or false when uploads is unusable on this install.
     */
    public static function wpc_registry_sidecar_paths()
    {
        if (!function_exists('wp_upload_dir')) {
            return false;
        }
        $wpc_ud = wp_upload_dir(null, false);
        if (!is_array($wpc_ud) || !empty($wpc_ud['error']) || empty($wpc_ud['basedir']) || empty($wpc_ud['baseurl'])) {
            return false;
        }
        $wpc_path = (string) parse_url((string) $wpc_ud['baseurl'], PHP_URL_PATH);
        return [
            'dir' => rtrim((string) $wpc_ud['basedir'], '/') . '/wpc-assets/delay/',
            'url' => rtrim($wpc_path, '/') . '/wpc-assets/delay/',
        ];
    }

    /**
     * Moves the parked inline script bodies out of the registry into one content-addressed JSON
     * file under $dir and marks each moved entry ext=1. The document then carries the registry
     * skeleton (ids, srcs, attributes, order) and the loader fetches the bodies after load, so
     * a page never ships bytes it will not execute before a gesture.
     *
     * Returns ['url', 'file', 'n', 'bytes'] or null when the bodies stay inline: under $min bytes
     * to move, no usable dir, or the file could not be written. Idempotent: the same bodies always
     * name the same file, and an existing file is never rewritten; reusing one whose time is more
     * than a day old sets its time to now, so the trim sees it in use.
     */
    public static function wpc_registry_sidecar(array &$registry, $dir, $url, $min = 2048)
    {
        $wpc_bodies = [];
        $wpc_bytes = 0;
        foreach ($registry as $wpc_e) {
            if (!is_array($wpc_e) || empty($wpc_e['id']) || !isset($wpc_e['content']) || !is_string($wpc_e['content']) || $wpc_e['content'] === '') {
                continue;
            }
            $wpc_bodies[(string) $wpc_e['id']] = $wpc_e['content'];
            $wpc_bytes += strlen($wpc_e['content']);
        }
        if ($wpc_bytes < (int) $min || !is_string($dir) || $dir === '' || !is_string($url) || $url === '') {
            return null;
        }
        $wpc_json = json_encode(['v' => 1, 'b' => $wpc_bodies]);
        if (!is_string($wpc_json) || $wpc_json === '') {
            return null;
        }
        $wpc_key = 'r-' . md5($wpc_json);
        $wpc_name = $wpc_key . '.js';
        $wpc_json = '(window.wpcRegistrySidecar=window.wpcRegistrySidecar||{})[' . json_encode($wpc_key) . ']=' . $wpc_json . ';';
        if (!@is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        if (!@file_exists($dir . $wpc_name)) {
            if (!@is_writable($dir)) {
                return null;
            }
            $wpc_tmp = $dir . $wpc_name . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
            if (@file_put_contents($wpc_tmp, $wpc_json) !== strlen($wpc_json) || !@rename($wpc_tmp, $dir . $wpc_name)) {
                @unlink($wpc_tmp);
                return null;
            }
            if (!@file_exists($dir . 'index.html')) {
                @file_put_contents($dir . 'index.html', '');
            }
        } elseif ((int) @filemtime($dir . $wpc_name) < time() - 86400) {
            @touch($dir . $wpc_name);
        }
        foreach ($registry as $wpc_k => $wpc_e) {
            if (is_array($wpc_e) && !empty($wpc_e['id']) && isset($wpc_bodies[(string) $wpc_e['id']])) {
                unset($registry[$wpc_k]['content']);
                $registry[$wpc_k]['ext'] = 1;
            }
        }
        return ['url' => $url . $wpc_name, 'file' => $wpc_name, 'n' => count($wpc_bodies), 'bytes' => $wpc_bytes];
    }

    /**
     * Daily: drops the registry sidecars nothing can still name. A sidecar stays while it is
     * younger than fourteen days (two once the directory holds more than 5000 files; a render that
     * reuses one refreshes its time once a day) and while a stored page copy names it: the `s:`
     * token of the copy's links record (wpc_copy_links_scan, read through wpc_copy_page_records).
     * The records are read by a walk of the page cache in slices of wpc_delay_sidecar_trim_budget
     * seconds (5), resumed a minute later from the folder it stopped at (option
     * wpc_delay_sidecar_trim_walk, at most a day old); nothing is deleted before a walk has read
     * every record, and a sidecar whose time moved during the walk stays. One walk judges at most
     * the wpc_delay_sidecar_trim_batch (2000) oldest candidates. Where the page cache's records
     * cannot be read (no page cache constant, no record reader) no sidecar is deleted. Stored
     * script scans older than fourteen days go too, and the oldest beyond
     * wpc_script_scan_store_cap. Returns how many sidecars it removed.
     */
    public static function wpc_registry_sidecar_trim()
    {
        $wpc_p = self::wpc_registry_sidecar_paths();
        if (!$wpc_p || !@is_dir($wpc_p['dir'])) {
            return 0;
        }
        $wpc_walk = get_option('wpc_delay_sidecar_trim_walk', false);
        if (!is_array($wpc_walk) || !isset($wpc_walk['t'], $wpc_walk['cut'], $wpc_walk['left']) || !is_array($wpc_walk['left'])
            || time() - (int) $wpc_walk['t'] > 86400) {
            $wpc_files = array_merge((array) @glob($wpc_p['dir'] . 'r-*.json'), (array) @glob($wpc_p['dir'] . 'r-*.js'));
            $wpc_days = count($wpc_files) > 5000 ? 2 : 14;
            $wpc_cut = time() - $wpc_days * 86400;
            $wpc_candidates = [];
            foreach ($wpc_files as $wpc_f) {
                $wpc_m = @filemtime((string) $wpc_f);
                if ($wpc_m !== false && $wpc_m < $wpc_cut) {
                    $wpc_candidates[basename((string) $wpc_f)] = $wpc_m;
                }
            }
            asort($wpc_candidates);
            $wpc_batch = max(1, (int) apply_filters('wpc_delay_sidecar_trim_batch', 2000));
            $wpc_walk = ['t' => time(), 'after' => '', 'cut' => $wpc_cut, 'days' => $wpc_days, 'named' => 0, 'read' => 0,
                'left' => array_keys(array_slice($wpc_candidates, 0, $wpc_batch, true))];
        }
        $wpc_done = $wpc_walk['left'] === [] ? true
            : self::wpc_registry_sidecar_walk($wpc_walk, (float) apply_filters('wpc_delay_sidecar_trim_budget', 5.0));
        $wpc_n = 0;
        if ($wpc_done === false) {
            update_option('wpc_delay_sidecar_trim_walk', $wpc_walk, false);
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 60, 'wpc_delay_sidecar_trim_hook');
            }
        } else {
            delete_option('wpc_delay_sidecar_trim_walk');
            if ($wpc_done === true) {
                foreach ($wpc_walk['left'] as $wpc_name) {
                    $wpc_f = $wpc_p['dir'] . (string) $wpc_name;
                    if (!preg_match('/^r-[0-9a-f]{32}\.(?:js|json)$/', (string) $wpc_name)) {
                        continue;
                    }
                    clearstatcache(true, $wpc_f);
                    $wpc_m = @filemtime($wpc_f);
                    if ($wpc_m !== false && $wpc_m < (int) $wpc_walk['cut'] && @unlink($wpc_f)) {
                        $wpc_n++;
                    }
                }
            }
        }
        foreach ((array) @glob($wpc_p['dir'] . '*.tmp') as $wpc_f) {
            if ((int) @filemtime((string) $wpc_f) < time() - 3600) {
                @unlink((string) $wpc_f);
            }
        }
        $wpc_scan_dir = self::wpc_script_scan_dir();
        $wpc_scans = $wpc_scan_dir !== false && @is_dir($wpc_scan_dir)
            ? self::wpc_script_scan_evict($wpc_scan_dir, max(10, (int) apply_filters('wpc_script_scan_store_cap', 2000)), 14 * 86400) : 0;
        if (function_exists('wpc_cache_first_log') && ($wpc_n || $wpc_scans || $wpc_done !== true || (int) $wpc_walk['named'] > 0)) {
            wpc_cache_first_log('delay-sidecar-trim', '', '', ['removed' => $wpc_n, 'named' => (int) $wpc_walk['named'], 'days' => (int) $wpc_walk['days'],
                'scans' => $wpc_scans, 'walk' => $wpc_done === true ? 'done' : ($wpc_done === false ? 'resume' : 'no-records')]);
        }
        return $wpc_n;
    }

    /**
     * One slice of the trim's walk of the page cache: the page folders after $walk['after'], in
     * name order, each read through wpc_copy_page_records; every sidecar in $walk['left'] that a
     * record names leaves it ($walk['named'] counts them, $walk['read'] counts the records read).
     * True when the walk reached the end having read at least one record, or nothing is left to
     * look for; false when it stopped at the time budget ($walk['after'] holds the last folder
     * read); null when this process cannot read the records, there is no page cache, or the walk
     * read none (copies held only by another cache or an edge cannot be seen, so nothing goes).
     */
    protected static function wpc_registry_sidecar_walk(array &$walk, $budget)
    {
        if (!defined('WPS_IC_CACHE') || !function_exists('wpc_copy_page_records') || !function_exists('wpc_copy_page_folders')) {
            return null;
        }
        $root = rtrim(WPS_IC_CACHE, '/') . '/';
        if (!@is_dir($root)) {
            return null;
        }
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return null;
        }
        $left = array_fill_keys(array_map('strval', $walk['left']), true);
        $after = (string) $walk['after'];
        $started = microtime(true);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') !== false || $entry === 'css' || $entry === 'js'
                || ($after !== '' && strcmp($entry, $after) <= 0) || !is_dir($root . $entry)) {
                continue;
            }
            foreach (wpc_copy_page_folders($root, $entry) as $folder) {
                $records = wpc_copy_page_records($root . $folder);
                $walk['read'] = (int) ($walk['read'] ?? 0) + (int) $records['ledgers'];
                foreach ($records['tokens'] as $token) {
                    if (strpos($token, 's:') === 0 && isset($left[substr($token, 2)])) {
                        unset($left[substr($token, 2)]);
                        $walk['named'] = (int) $walk['named'] + 1;
                    }
                }
            }
            $after = $entry;
            if ($left === []) {
                break;
            }
            if (microtime(true) - $started >= (float) $budget) {
                $walk['after'] = $after;
                $walk['left'] = array_keys($left);
                return false;
            }
        }
        $walk['after'] = $after;
        $walk['left'] = array_keys($left);
        if ($left !== [] && (int) ($walk['read'] ?? 0) === 0) {
            return null;
        }
        return true;
    }

    /**
     * Bring the uploads copy of the delay loader in line with the plugin's own file.
     *
     * Stale by content, not by size (v7.23.17): a same-size edit of the hand-spliced min (the .16
     * kick change, 88,476 -> 88,476 bytes) never reached the copy, and the page kept serving the
     * old loader from uploads for a year (immutable). Older copies do not stack (v7.23.17,
     * staging: 4 MB of loaders, ~45 versions): the current one plus the three newest others are
     * kept, ranked by the version in the filename; cached HTML that names a deleted copy falls
     * through the onerror fallback to the plugin-dir file. Retro-heal (v7.22.09): cached HTML pins
     * old versioned filenames, so every surviving copy gets the current bytes and cached pages
     * heal on their next cold fetch. The filename keeps the version: the cache-dress checks and
     * the doctor read it as the "rendered by an older plugin" signal.
     */
    private static function wpc_loader_copy_refresh($dir, $name, $source, $version)
    {
        if (!@file_exists($source)) {
            return;
        }
        $sourceHash = @md5_file($source);
        if (@file_exists($dir . $name) && @md5_file($dir . $name) !== $sourceHash) {
            @unlink($dir . $name);
        }
        if (@file_exists($dir . $name)) {
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return;
        }
        $olderCopies = [];
        foreach ((array) @glob($dir . 'delay-v3-loader-*.min.js') as $copy) {
            if (preg_match('/^delay-v3-loader-(.+)\.min\.js$/', basename($copy), $m) && $m[1] !== (string) $version) {
                $olderCopies[$m[1]] = $copy;
            }
        }
        uksort($olderCopies, function ($a, $b) { return version_compare($b, $a); });
        $removed = 0;
        foreach (array_slice($olderCopies, max(0, (int) apply_filters('wpc_loader_keep', 3)), null, true) as $copy) {
            if (@unlink($copy)) {
                $removed++;
            }
        }
        if ($removed && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('loader-gc', '', '', ['n' => $removed]);
        }
        @copy($source, $dir . $name);
        $healed = 0;
        foreach ((array) @glob($dir . 'delay-v3-loader-*.min.js') as $copy) {
            if (basename($copy) !== $name && @md5_file($copy) !== $sourceHash && @copy($source, $copy)) {
                $healed++;
            }
        }
        if ($healed && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('loader-retroheal', '', '', ['n' => $healed]);
        }
        if (!@file_exists($dir . '.htaccess')) {
            wpc_fs_put($dir . '.htaccess',
                "<IfModule mod_headers.c>\n<FilesMatch \"\\.(js|css)$\">\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n</IfModule>\n<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType application/javascript \"access plus 1 year\"\n</IfModule>\n");
        }
    }

    public static function wpc_loader_script_tag()
    {
        $wpc_loader_base = defined('WPS_IC_URI') ? WPS_IC_URI : plugins_url('/', dirname(__FILE__));
        $wpc_loader_file = (defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'assets/js/delay-v3-loader.min.js'))
            ? 'assets/js/delay-v3-loader.min.js' : 'assets/js/delay-v3-loader.js';
        $wpc_loader_src = $wpc_loader_base . $wpc_loader_file . '?v=' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1');


        if (defined('WPS_IC_DIR') && function_exists('wp_upload_dir')) {
            try {
                $uploadDir = wp_upload_dir(null, false);
                if (empty($uploadDir['error']) && !empty($uploadDir['basedir']) && !empty($uploadDir['baseurl'])) {
                    $pluginVersion  = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1';
                    $assetsDir  = rtrim($uploadDir['basedir'], '/') . '/wpc-assets/';
                    $copyName = 'delay-v3-loader-' . $pluginVersion . '.min.js';
                    $sourceFile = WPS_IC_DIR . 'assets/js/delay-v3-loader.min.js';


                    // The copy is rebuilt only when the plugin's loader changed (its version, mtime
                    // or size) or the copy is gone; a render does one stat and one file_exists. The
                    // content compare, the retro-heal of older copies and the GC run inside the
                    // rebuild, once per change, instead of two md5_file() over ~88 KB per render.
                    $wpc_loader_key = $pluginVersion . '|' . (int) @filemtime($sourceFile) . '|' . (int) @filesize($sourceFile);
                    $loaderChanged = (function_exists('get_option') ? get_option('wpc_loader_copy_key') : '') !== $wpc_loader_key;
                    if ($loaderChanged || !@file_exists($assetsDir . $copyName)) {
                        self::wpc_loader_copy_refresh($assetsDir, $copyName, $sourceFile, $pluginVersion);
                        $loaderCopied = @file_exists($assetsDir . $copyName);
                        if ($loaderCopied && function_exists('update_option')) {
                            update_option('wpc_loader_copy_key', $wpc_loader_key, true);
                        }
                        // The uploads copy is named by version while its bytes change without one,
                        // so it is rebuilt when the plugin's loader changed or the copy vanished.
                        // A failed copy retries on every render and the tag falls back to the
                        // plugin URL: that line is sampled; a rebuild is written each time.
                        if (function_exists('wpc_render_belt_note')) {
                            if ($loaderCopied) {
                                wpc_render_belt_note('loader-copy-refreshed', ['why' => $loaderChanged ? 'loader-changed' : 'copy-missing']);
                            } else {
                                wpc_render_belt_note('loader-copy-failed', ['why' => $loaderChanged ? 'loader-changed' : 'copy-missing'], true);
                            }
                        }
                    }
                    // v7.21.159 — SELF-PROBE: on hosts that refuse .js under uploads (WAF rule,
                    // cleaner), the file exists for PHP yet 404s over HTTP; every cold load then
                    // pays a dead request before the .155 onerror belt heals it (rosariospadaro).
                    // One HEAD per plugin version, after output on shutdown, settles it: a 4xx
                    // verdict stands the emitter down to the plugin-dir URL permanently for that
                    // version. 5xx/timeouts are inconclusive (never a verdict — lock expiry
                    // retries); version bump or Refresh Auto Mode re-probes. Fail-open: no
                    // verdict = today's behavior, belt still rides every uploads tag.
                    $copyUrl = rtrim($uploadDir['baseurl'], '/') . '/wpc-assets/' . $copyName;
                    if (@file_exists($assetsDir . $copyName) && @filesize($assetsDir . $copyName) > 0
                        && function_exists('get_option') && function_exists('get_transient')) {
                        $probeVerdict = get_option('wpc_loader_probe159');
                        $probeFresh = is_array($probeVerdict) && isset($probeVerdict['ver'], $probeVerdict['state'])
                            && (string) $probeVerdict['ver'] === (string) $pluginVersion;
                        if (!$probeFresh && function_exists('wp_remote_head') && function_exists('add_action')
                            && !get_transient('wpc_loader_probe_lock159')) {
                            set_transient('wpc_loader_probe_lock159', 1, 300);
                            add_action('shutdown', function () use ($copyUrl, $pluginVersion) {
                                $probeResponse = wp_remote_head($copyUrl, array('timeout' => 5, 'redirection' => 2, 'sslverify' => false));
                                if (is_wp_error($probeResponse)) {
                                    return;
                                }
                                $verdict = self::wpc_loader_probe_verdict((int) wp_remote_retrieve_response_code($probeResponse));
                                if ($verdict !== null) {
                                    update_option('wpc_loader_probe159', array('ver' => (string) $pluginVersion, 'state' => $verdict, 't' => time()), false);
                                    if ($verdict === 'down' && function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('loader-probe-down', '', $copyUrl);
                                    }
                                }
                            });
                        }
                        if ($probeFresh && (string) $probeVerdict['state'] === 'down') {
                            $copyUrl = '';
                        }
                    } else {
                        $copyUrl = '';
                    }
                    if ($copyUrl !== '') {
                        $wpc_loader_src = $copyUrl;
                        // Zone-serve for a real edge TTL (same host-swap the image lanes use);
                        // origin URL stands whenever the zone isn't live
                        $settings = get_option(WPS_IC_SETTINGS);
                        // v7.21.14 — the loader swap must obey the SAME kill every image lane obeys.
                        // Reading the raw option here made the loader the ONE emitter outside the
                        // suppression umbrella: on a CF site held in cfwait (cname never verified),
                        // images stayed origin while the loader alone rode the zone — and when the
                        // zone's origin pulls are challenged, that lone request fails and every
                        // delayed script on the page stays dead for visitors (abasingbakes).
                        $zoneSuppressed = (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed());
                        if (!$zoneSuppressed && class_exists('wps_cdn_rewrite') && isset(wps_cdn_rewrite::$cdnEnabled)
                            && (string) wps_cdn_rewrite::$cdnEnabled !== '1') {
                            $zoneSuppressed = true;
                        }
                        // Scripts ride the page origin (v7.10.719); the loader moves to the CDN host
                        // only when a site turns that off through wpc_scripts_same_origin.
                        if (!$zoneSuppressed && apply_filters('wpc_scripts_same_origin', true)) {
                            $zoneSuppressed = true;
                        }
                        if (!$zoneSuppressed && is_array($settings) && !empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1') {
                            // v7.10.600 — resolve through the ONE helper. This site read only
                            // ic_custom_cname then the zone, missing the Cloudflare-provisioned
                            // cname that the three combine/enqueue resolvers check first — so on a
                            // CF-connected site the loader alone landed on the raw zone host and
                            // paid a second DNS+TCP+TLS (409ms measured) that no other node pays.
                            $cdnHost = function_exists('wpc_cdn_host')
                                ? (string) wpc_cdn_host()
                                : trim((string) get_option('ic_custom_cname'));
                            if ($cdnHost === '') {
                                $cdnHost = trim((string) get_option('ic_cdn_zone_name'));
                            }
                            if ($cdnHost !== '') {
                                $wpc_loader_src = preg_replace('#^https?://[^/]+#', 'https://' . $cdnHost, $wpc_loader_src);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {

            }
        }


        $inlineTag = '';
        if (defined('WPS_IC_DIR') && apply_filters('wpc_delay_loader_inline', true)) {
            $loaderPath = WPS_IC_DIR . $wpc_loader_file;
            if (@is_readable($loaderPath) && (int) @filesize($loaderPath) > 0 && (int) @filesize($loaderPath) <= 28672) {
                $loaderSource = (string) @file_get_contents($loaderPath);
                if ($loaderSource !== '' && stripos($loaderSource, '</script') === false) {
                    $inlineTag = '<script id="wpc-delay-v3-loader">' . $loaderSource . '</script>';
                }
            }
        }
        // v7.21.155 — ROSARIOSPADARO: the published/CDN loader URL 404'd (uploads copy present
        // at mint, gone at serve — sweeper/WAF class) and 35 placeholdered scripts sat dead:
        // 2 of 13 portfolio images rendered. Disk-at-mint is not HTTP-at-serve; any external
        // loader carries an onerror fallback to the plugin-dir copy, which ships in the zip
        // and cannot be missing. One 404 must never hold a page's whole JS hostage.
        $fallbackSrc = $wpc_loader_base . $wpc_loader_file . '?v=' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1');
        $fallbackAttrs = '';
        if ($wpc_loader_src !== $fallbackSrc) {
            $fallbackAttrs = ' data-wpc-lfb="' . esc_url($fallbackSrc) . '"'
                . ' onerror="if(!this.dataset.wpcLfbDone){this.dataset.wpcLfbDone=1;var s=document.createElement(\'script\');s.src=this.dataset.wpcLfb;s.async=true;s.id=\'wpc-delay-v3-loader\';document.head.appendChild(s);}"';
        }
        return ($inlineTag !== '')
            ? $inlineTag
            : '<script id="wpc-delay-v3-loader" src="' . esc_url($wpc_loader_src) . '" async' . $fallbackAttrs . '></script>';
    }

    public function process_html($html)
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return $html;
        }
        $this->wpc_mod_vendors = self::wpc_module_vendors($html);
        $this->wpc_mod_core = empty($this->wpc_mod_vendors) ? [] : self::wpc_module_core($html);

        $this->wpc_sync_jquery = (bool) preg_match('/<script\b[^>]*\bsrc=["\'][^"\']*(?:wpbf|page-builder-framework|sb-youtube)[^"\']*\.js/i', $html);

        $this->wpc_apply_builder_runtime_keeps($html);
        $this->wpc_first_screen_sliders = apply_filters('wpc_first_screen_slider_keep', true) ? self::wpc_breakdance_first_screen_sliders($html) : [];

        // v7.21.74 — A PARSE-TIME ROOT-VARIABLE SETTER IS A STYLESHEET IN SCRIPT'S CLOTHING.
        // Hozjan/falknerei: the theme's custom.js computes --fluid from the viewport and
        // writes it on <html> at parse; every fluid font-size is calc(min + range*var(--fluid))
        // with @property initial-value 0. Delayed, the whole page renders at MINIMUM type
        // scale until first gesture (h1 measured 35px vs 55.77px plugin-off). Any same-site
        // theme/child script whose body writes a custom property at parse is render-path:
        // it stays natural (excluded-src defer lane), and the .56 closure pulls its deps.
        // Bodies are fetched once per src+ver and the verdict cached; fail-open on every edge.
        // Kill wpc_rootvar_keep.
        if (apply_filters('wpc_rootvar_keep', true)
            && preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']*\/themes\/[^"\']+\.js[^"\']*)["\'][^>]*>/i', $html, $theme_script_matches)) {
            $rootvar_verdicts = get_option('wpc_rootvar_setters74');
            $rootvar_verdicts = is_array($rootvar_verdicts) ? $rootvar_verdicts : [];
            $rootvar_snippets = get_option('wpc_rootvar_snips94');
            $rootvar_snippets = is_array($rootvar_snippets) ? $rootvar_snippets : [];
            $snippets_changed = false;
            $hoist_snippets = [];
            $verdicts_changed = false;
            $bodies_fetched = 0;
            foreach (array_slice(array_unique((array) $theme_script_matches[1]), 0, 12) as $theme_src) {
                $src_hash = md5((string) $theme_src);
                $script_body = '';
                if (!array_key_exists($src_hash, $rootvar_verdicts)) {
                    if ($bodies_fetched >= 2) {
                        continue; // at most two body fetches per render; the rest classify next request
                    }
                    $bodies_fetched++;
                    $is_setter = 0;
                    if (count($rootvar_verdicts) < 48 && function_exists('wp_remote_get')) {
                        $fetch_response = wp_remote_get(html_entity_decode((string) $theme_src), ['timeout' => 3, 'sslverify' => false]);
                        if (function_exists('wpc_net_defer_on_render_guard') && wpc_net_defer_on_render_guard($fetch_response, 'rv74:' . $src_hash, function () use ($theme_src) { wps_ic_js_delay_v3::wpc_classify_root_var_setter($theme_src); })) {
                            continue;
                        }
                        $fetched_body = (!is_wp_error($fetch_response) && (int) wp_remote_retrieve_response_code($fetch_response) === 200)
                            ? substr((string) wp_remote_retrieve_body($fetch_response), 0, 65536) : '';
                        if ($fetched_body !== '' && preg_match('/\.style\.setProperty\(\s*["\']--/', $fetched_body)) {
                            $is_setter = 1;
                            $script_body = $fetched_body;
                        }
                    }
                    $rootvar_verdicts[$src_hash] = $is_setter;
                    $verdicts_changed = true;
                }
                if (!empty($rootvar_verdicts[$src_hash])) {
                    $setter_basename = basename((string) parse_url((string) $theme_src, PHP_URL_PATH));
                    if ($setter_basename !== '' && strlen($setter_basename) > 4) {
                        $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, [$setter_basename])));
                        if (function_exists('wpc_render_belt_note')) {
                            wpc_render_belt_note('delay-keep-rules', ['rootvar' => 1], true);
                        }
                        // v7.21.90 — the first frame WAITS on a root-var setter: it must never
                        // ride fetchpriority=low (falknerei 3G: 35px h1 for 6.5s ON vs 0.6s OFF).
                        $setter_names = (array) get_option('wpc_rootvar_names74', []);
                        if (!in_array($setter_basename, $setter_names, true) && count($setter_names) < 24) {
                            $setter_names[] = $setter_basename;
                            update_option('wpc_rootvar_names74', $setter_names, false);
                        }
                        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_rv74_log')) {
                            set_transient('wpc_rv74_log', 1, 3600);
                            wpc_cache_first_log('rootvar-keep', $setter_basename, '', []);
                        }
                        // v7.21.94 — FIRST-FRAME STATE NEVER WAITS ON A NETWORK SCRIPT. The
                        // .74 keep puts the setter in the defer lane, but defer still executes
                        // after the document arrives — on 3G falknerei painted at 1.8s and
                        // custom.js set --fluid at 6.0s: 4.2s of minimum-scale type that
                        // plugin-off never shows only because blocking CSS holds paint past
                        // the setter. The setter's own self-contained IIFE is hoisted inline
                        // at parse; the deferred original re-runs it idempotently.
                        // Kill wpc_rootvar_early.
                        if (!array_key_exists($src_hash, $rootvar_snippets)) {
                            if ($script_body === '' && $bodies_fetched < 2 && function_exists('wp_remote_get')) {
                                $bodies_fetched++;
                                $snippet_response = wp_remote_get(html_entity_decode((string) $theme_src), ['timeout' => 3, 'sslverify' => false]);
                                if (function_exists('wpc_net_defer_on_render_guard')) {
                                    wpc_net_defer_on_render_guard($snippet_response, 'rv74:' . $src_hash, function () use ($theme_src) { wps_ic_js_delay_v3::wpc_classify_root_var_setter($theme_src); });
                                }
                                if (!is_wp_error($snippet_response) && (int) wp_remote_retrieve_response_code($snippet_response) === 200) {
                                    $script_body = substr((string) wp_remote_retrieve_body($snippet_response), 0, 65536);
                                }
                            }
                            if ($script_body !== '' && count($rootvar_snippets) < 24) {
                                $rootvar_snippets[$src_hash] = self::wpc_extract_root_var_setter_iifes($script_body);
                                $snippets_changed = true;
                            }
                        }
                        if (!empty($rootvar_snippets[$src_hash])) {
                            $hoist_snippets[] = (string) $rootvar_snippets[$src_hash];
                        }
                    }
                }
            }
            if ($verdicts_changed) {
                update_option('wpc_rootvar_setters74', $rootvar_verdicts, false);
            }
            if ($snippets_changed) {
                update_option('wpc_rootvar_snips94', $rootvar_snippets, false);
            }
            if (!empty($hoist_snippets) && apply_filters('wpc_rootvar_early', true)
                && strpos($html, 'wpc-rootvar-early') === false) {
                $early_tag = '<script id="wpc-rootvar-early">try{' . implode("\n", array_slice($hoist_snippets, 0, 2)) . '}catch(wpcE94){}</script>';
                if (preg_match('/<head(\s[^>]*)?>/i', $html, $head_match, PREG_OFFSET_CAPTURE)) {
                    $head_end = (int) $head_match[0][1] + strlen($head_match[0][0]);
                    $html = substr($html, 0, $head_end) . $early_tag . substr($html, $head_end);
                }
            }
        }

        // v7.10.494 — INLINE COMPANIONS DISQUALIFY DEFER. An inline script cannot be deferred, so
        // deferring the external it depends on inverts their order: wp-i18n-js deferred while
        // wp-i18n-js-after runs at parse threw "wp is not defined" and killed Real Cookie Banner.
        // v7.10.564 — ONLY -after companions disqualify, which is WP core's actual rule in
        // filter_eligible_strategies: an -after companion CALLS its parent's API at parse, so the
        // parent must not defer; a -before companion is a config setter that runs at parse and is
        // read at DCL — order preserved by construction. Matching -before too stranded
        // elementor-frontend-js in the blocking lane while its whole dependency chain deferred
        // around it (staging receipt: webpack runtime deferred, frontend blocking = inversion).
        $this->wpc_inline_pair_ids = [];
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+?)-after["\']/i', $html, $after_companions)) {
            foreach ((array) $after_companions[1] as $parent_id) {
                $this->wpc_inline_pair_ids[strtolower($parent_id)] = 1;
            }
        }
        // v7.21.09 — WP attaches inline companions to the ALIAS handle: the companion renders
        // as jquery-js-after while the src tag is jquery-core-js, so the id-keyed map above
        // missed every alias pairing and jquery-core deferred under a parse-time -after of our
        // own making (wpc_check_cart_script rides the 'jquery' handle; its body never calls
        // jQuery, so the .803 body-sniff stayed silent too) — every first view logged
        // "jQuery is not defined". A src-less registered handle whose companion is paired
        // marks each of its deps' tags paired.
        $this->wpc_inline_pair_ids = self::wpc_expand_alias_pairs($this->wpc_inline_pair_ids);
        // v7.10.803 — AN -after COMPANION NEEDS ITS LIBRARIES, NOT JUST ITS PARENT. The pairing
        // above protects the companion's OWN external from deferring, which is WP core's rule.
        // But the companion body also calls jQuery at parse, and jQuery carries no -after
        // companion of its own, so nothing above disqualifies it: eloorac deferred
        // jquery-core-js while jquery-ui-datepicker-js-after ran during parse and threw
        // "jQuery is not defined". Read the companion BODIES and, only when one actually
        // references jQuery, hold the jQuery tags in the eager lane. Narrow by construction —
        // a page whose companions never touch jQuery still defers it, so this cannot become
        // the .512 blanket that .564 retired for costing 310 ms.
        $this->wpc_jquery_parse_needed = false;
        if (apply_filters('wpc_jquery_parse_companion_guard', true)
            && preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\'][^"\']+?-after["\'][^>]*>(.*?)<\/script>/is', $html, $companion_bodies)) {
            foreach ((array) $companion_bodies[1] as $companion_body) {
                if (preg_match('/(?:^|[^A-Za-z0-9_$])(?:jQuery|\$)\s*[\(\.]/', (string) $companion_body)) {
                    $this->wpc_jquery_parse_needed = true;
                    break;
                }
            }
        }
        // v7.10.564 — the .512 blanket and its .526 narrowing are both retired: the blanket
        // handed back 310 ms of render-blocking jQuery on every paired page, and .526's
        // narrowing missed the third state (a paired external that IS a keep, left blocking
        // amid deferred dependencies). The document-position split pass at the end of this
        // method now enforces the same invariant exactly, with the blanket as its worst case.
        // Support hatch only: force the full blanket per-site without a build.
        $this->wpc_nodefer_all_keeps = (bool) apply_filters('wpc_nodefer_all_keeps', false)
            && apply_filters('wpc_keep_defer_pair_safe', true);


        // manifest_off (promoted-script ReferenceError kill-switch) no longer
        // freezes the measured read when a NEWER measured gen is on disk — the
        // aggressive path is governed by its own boot-watchdog, not this switch.
        $manifest_off_at = (int) get_option('wpc_delay_v3_manifest_off', 0);
        $wpc_promo_site = ($manifest_off_at > 0 && !self::wpc_measured_delay_newer_than($manifest_off_at)) ? 'manifest_off' : '';
        $wpc_manifest_on = $wpc_promo_site === '' && apply_filters('wpc_delay_v3_manifest', true);
        $wpc_promo_skipped = [];
        if ($wpc_manifest_on) {
            $wpc_promo_site = 'no-delay-json';
        }
        if ($wpc_manifest_on && class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
            try {
                $wpc_mk = (new wps_ic_url_key())->setup('');
                $wpc_mf = self::wpc_delay_manifest_file();
                if ($wpc_mf && @is_readable($wpc_mf)) {
                    $wpc_m = json_decode((string) @file_get_contents($wpc_mf), true);
                    if (is_array($wpc_m)) {
                        $wpc_promo_site = '';
                        foreach (array_slice(self::wpc_manifest_keep_overflow($wpc_m), 0, 40) as $wpc_overflow) {
                            if (strpos($html, $wpc_overflow) !== false) {
                                $wpc_promo_skipped[$wpc_overflow] = 'list-truncated';
                            }
                        }
                        // Measured gate: schema_epoch>=N (authoritative) OR ceiling{}
                        // presence (legacy proxy), AND a render_critical KEY (the ATF
                        // keep list; empty array counts = "no script is ATF-critical").
                        $this->wpc_measured = self::wpc_delay_measured_shape($wpc_m);


                        if (apply_filters('wpc_captcha_intent', true)) {
                            $this->wpc_captcha_intent = true;
                            $this->excludes = array_values(array_diff((array) $this->excludes, [
                                'recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com',
                                'turnstile', 'challenges.cloudflare.com',
                            ]));
                        }


                        if (array_key_exists('has_form', $wpc_m) && $wpc_m['has_form'] === false
                            && apply_filters('wpc_captcha_scope', true)) {
                            $this->excludes = array_values(array_diff((array) $this->excludes, [
                                'recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com',
                                'turnstile', 'challenges.cloudflare.com',
                            ]));
                        }


                        // AUTO-100 §2: per-key third_parties[] — match[] verbatim (A1: host
                        // is informational), delay-interaction-only lane only. Option lanes
                        // load first (constructor); the manifest fills gaps — same io field,
                        // idempotent. keep-eager NEVER auto-wires here (dm dependency law).
                        if (!empty($wpc_m['third_parties']) && is_array($wpc_m['third_parties'])
                            && apply_filters('wpc_manifest_third_parties', true)) {
                            // v7.10.388 — measured has_form:false = no visible form to protect;
                            // form-vendor bans yield to measured delay-io (captcha-release twin).
                            $forms_safe = array_key_exists('has_form', $wpc_m) && $wpc_m['has_form'] === false;
                            foreach (array_slice($wpc_m['third_parties'], 0, 12) as $third_party) {
                                if (!is_array($third_party)
                                    || strtolower((string) ($third_party['recommended'] ?? '')) !== 'delay-interaction-only') {
                                    continue;
                                }
                                foreach (array_slice((array) ($third_party['match'] ?? []), 0, 6) as $match_pattern) {
                                    // v7.10.386 — bare chat-vendor patterns expand to their
                                    // chat-scoped hosts (the only form the validator accepts).
                                    foreach (self::wpc_io_pattern_expand($match_pattern) as $expanded_pattern) {
                                        if (!self::wpc_io_pattern_ok($expanded_pattern, $forms_safe)) {
                                            continue;
                                        }
                                        $this->wpc_io_patterns[] = strtolower((string) $expanded_pattern);
                                        $this->wpc_src_force_delay[] = strtolower((string) $expanded_pattern); // capture door, SRC-only
                                    }
                                }
                            }
                            $this->wpc_io_patterns = array_slice(array_values(array_unique($this->wpc_io_patterns)), 0, 24);
                            $this->wpc_src_force_delay = array_slice(array_values(array_unique($this->wpc_src_force_delay)), 0, 32);
                            // A form vendor the service just measured as delay-interaction-only
                            // releases its built-in keep so it can actually be delayed + io-stamped.
                            $this->wpc_release_io_form_keeps();
                        }
                        foreach (self::wpc_manifest_keep_entries($wpc_m) as $wpc_keep) {
                            list($wpc_k, $wpc_s) = $wpc_keep;
                            if ($wpc_k === 'render_critical') {
                                $this->manifest_rc[strtolower(basename((string) strtok($wpc_s, '?')))] = true;
                            }
                            if (strpos($wpc_s, 'inline:') === 0) {
                                $wpc_h = substr($wpc_s, 7, 16);
                                if (strlen($wpc_h) === 16) {
                                    $this->manifest_inline[$wpc_h] = true;
                                }
                            } elseif (strpos($wpc_s, '/') !== false) {
                                $wpc_p = parse_url($wpc_s, PHP_URL_PATH);
                                if (is_string($wpc_p) && strlen($wpc_p) >= 6) {
                                    $this->manifest_paths[$wpc_p] = true;
                                }
                            } else {
                                // v1 flat shape: a src BASENAME or an inline ID attribute.
                                // Either match errs toward NOT delaying — the safe direction.
                                $this->manifest_names[$wpc_s] = true;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }


        $this->promoted_src_ids = [];
        $wpc_cand_base = [];
        if ((!empty($this->manifest_paths) || !empty($this->manifest_names))
            && preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $wpc_ptags)) {


            if (!empty($this->manifest_names)) {
                $basename_paths = [];
                foreach ($wpc_ptags[0] as $script_tag) {
                    $tag_attrs = $this->parse_script_attributes($script_tag);
                    if (empty($tag_attrs['src'])) { continue; }
                    $tag_src = html_entity_decode((string) $tag_attrs['src']);
                    $embed_pos = strrpos($tag_src, '/a:');
                    if ($embed_pos !== false) {
                        $embedded_url = substr($tag_src, $embed_pos + 3);
                        if (preg_match('#^https?://#i', $embedded_url)) {
                            $src_path = parse_url($embedded_url, PHP_URL_PATH);
                        } else {
                            $src_path = strtok($embedded_url, '?');
                            if (is_string($src_path) && $src_path !== '' && $src_path[0] !== '/') { $src_path = '/' . $src_path; }
                        }
                    } else {
                        $src_path = parse_url($tag_src, PHP_URL_PATH);
                    }
                    if (!is_string($src_path) || $src_path === '') { continue; }
                    $src_basename = strtolower(basename($src_path));
                    if ($src_basename === '') { continue; }
                    if (!isset($basename_paths[$src_basename])) { $basename_paths[$src_basename] = []; }
                    $basename_paths[$src_basename][$src_path] = true;
                }
                foreach (array_keys($this->manifest_names) as $manifest_name) {
                    $name_key = strtolower((string) $manifest_name);
                    if (isset($basename_paths[$name_key]) && count($basename_paths[$name_key]) === 1) {
                        $this->manifest_paths[array_key_first($basename_paths[$name_key])] = true;
                    } elseif (isset($basename_paths[$name_key])) {
                        $wpc_promo_skipped[$name_key] = 'shared-basename';
                    }
                }
            }
            if (empty($this->manifest_paths)) {
                $wpc_ptags = [[]];
            }
            $wpc_page_ids   = [];
            $wpc_page_srcs  = [];
            $wpc_candidates = [];
            $wpc_parse_ids  = [];
            foreach ($wpc_ptags[0] as $wpc_pt) {
                $wpc_pa = $this->parse_script_attributes($wpc_pt);
                if (empty($wpc_pa['src'])) {
                    continue;
                }
                $wpc_pid = isset($wpc_pa['id']) ? (string) $wpc_pa['id'] : '';
                if ($wpc_pid !== '') {
                    $wpc_page_ids[$wpc_pid]  = true;
                    $wpc_page_srcs[$wpc_pid] = html_entity_decode((string) $wpc_pa['src']);


                    if ($this->should_exclude_script($wpc_pa, '')) {
                        $wpc_parse_ids[$wpc_pid] = true;
                    }
                }
                $wpc_purl = html_entity_decode((string) $wpc_pa['src']);


                $wpc_ai = strrpos($wpc_purl, '/a:');
                if ($wpc_ai !== false) {
                    $wpc_emb = substr($wpc_purl, $wpc_ai + 3);
                    if (preg_match('#^https?://#i', $wpc_emb)) {
                        $wpc_pp = parse_url($wpc_emb, PHP_URL_PATH);
                    } else {
                        $wpc_pp = strtok($wpc_emb, '?');
                        if (is_string($wpc_pp) && $wpc_pp !== '' && $wpc_pp[0] !== '/') {
                            $wpc_pp = '/' . $wpc_pp;
                        }
                    }
                } else {
                    $wpc_pp = parse_url($wpc_purl, PHP_URL_PATH);
                }
                if (!is_string($wpc_pp) || !isset($this->manifest_paths[$wpc_pp])) {
                    continue;
                }


                $wpc_ph = strtolower((string) parse_url($wpc_purl, PHP_URL_HOST));
                if ($wpc_ph !== '') {
                    $wpc_phome = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
                    $wpc_pst   = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
                    if ($wpc_pst($wpc_ph) !== $wpc_pst($wpc_phome)
                        && strpos($wpc_ph, 'zapwp') === false && strpos($wpc_ph, 'b-cdn') === false) {
                        continue;
                    }
                }
                if ($wpc_pid === '' || substr($wpc_pid, -3) !== '-js') {
                    continue;
                }
                $wpc_candidates[$wpc_pid]  = true;
                $wpc_cand_base[$wpc_pid]   = basename($wpc_pp);
            }

            $wpc_ws = !empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered)
                ? $GLOBALS['wp_scripts'] : null;
            $wpc_deps_all = function ($handle) use ($wpc_ws) {
                if (!$wpc_ws || empty($wpc_ws->registered[$handle])) {
                    return null;
                }
                $out = [];
                $stack = [$handle];
                $seen = [$handle => true];
                $n = 0;
                while (!empty($stack) && $n++ < 200) {
                    $h = array_pop($stack);
                    if (empty($wpc_ws->registered[$h])) {
                        continue;
                    }
                    foreach ((array) $wpc_ws->registered[$h]->deps as $d) {
                        if (isset($seen[$d])) {
                            continue;
                        }
                        $seen[$d] = true;
                        $out[]    = $d;
                        $stack[]  = $d;
                    }
                }
                return $out;
            };


            $wpc_dep_host_ok = function ($id) use ($wpc_page_srcs) {
                $st = function ($x) { return strpos($x, 'www.') === 0 ? substr($x, 4) : $x; };
                $u  = isset($wpc_page_srcs[$id]) ? (string) $wpc_page_srcs[$id] : '';
                $h  = strtolower((string) parse_url($u, PHP_URL_HOST));
                if ($h === '') {
                    return true;
                }
                if (strpos($h, 'zapwp') !== false || strpos($h, 'b-cdn') !== false) {
                    return true;
                }
                $home = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
                return $home !== '' && $st($h) === $st($home);
            };


            $wpc_form_fams = apply_filters('wpc_delay_v3_form_families', [
                'jetformbuilder', 'jet-form-builder', 'wpforms', 'gravityforms', 'ninja-forms',
                'fluentform', 'formidable', 'forminator', 'happyforms', 'everest-forms', 'ws-form',
                'jet-appointments', 'jet-ab-', 'jet-apb',
            ]);
            $wpc_is_form = function ($id) use ($wpc_page_srcs, $wpc_form_fams) {
                $s = strtolower((isset($wpc_page_srcs[$id]) ? (string) $wpc_page_srcs[$id] : '') . '|' . $id);
                foreach ($wpc_form_fams as $ff) {
                    if (strpos($s, $ff) !== false) {
                        return true;
                    }
                }
                return false;
            };
            $wpc_promo_cap = (int) apply_filters('wpc_delay_v3_promotion_cap', 24);
            for ($wpc_round = 0; $wpc_round < 6; $wpc_round++) {
                $wpc_changed = false;
                foreach (array_keys($wpc_candidates) as $wpc_cid) {
                    if (isset($this->promoted_src_ids[$wpc_cid]) || count($this->promoted_src_ids) >= $wpc_promo_cap) {
                        if (!isset($this->promoted_src_ids[$wpc_cid])) {
                            $wpc_promo_skipped[strtolower((string) ($wpc_cand_base[$wpc_cid] ?? $wpc_cid))] = 'cap';
                        }
                        continue;
                    }
                    $wpc_deps = $wpc_deps_all(substr($wpc_cid, 0, -3));
                    if ($wpc_deps === null) {
                        continue;
                    }
                    // v7.22.59 — consent delayed => its satellites go with it. Burst (Really Simple's
                    // analytics, consent-gated by the same CMP) was a manifest keep: 566ms long task,
                    // the whole TBT on welliathome, while the banner it waits on was off the wire.
                    if ($this->wpc_consent_delayed && $this->checkKeyword(strtolower((isset($wpc_page_srcs[$wpc_cid]) ? (string) $wpc_page_srcs[$wpc_cid] : '') . ' ' . $wpc_cid), self::wpc_consent_satellites())) {
                        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_cs59_' . md5($wpc_cid))) {
                            set_transient('wpc_cs59_' . md5($wpc_cid), 1, 3600);
                            wpc_cache_first_log('consent-satellite-delayed', $wpc_cid, '', []);
                        }
                        continue;
                    }
                    if ($wpc_is_form($wpc_cid)) {
                        continue;
                    }
                    if (self::wpc_is_builder_mutator_script(isset($wpc_page_srcs[$wpc_cid]) ? (string) $wpc_page_srcs[$wpc_cid] : '', $this->manifest_rc)
                        && !apply_filters('wpc_keep_builder_mutators', false, $wpc_cid)) {
                        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_bm41_' . md5($wpc_cid))) {
                            set_transient('wpc_bm41_' . md5($wpc_cid), 1, 3600);
                            wpc_cache_first_log('builder-mutator-delayed', $wpc_cid, '', []);
                        }
                        continue;
                    }
                    $wpc_missing = [];
                    $wpc_ok      = true;
                    foreach ($wpc_deps as $wpc_dh) {
                        $wpc_did = $wpc_dh . '-js';
                        if (isset($wpc_page_ids[$wpc_did])
                            && !isset($wpc_parse_ids[$wpc_did]) && !isset($this->promoted_src_ids[$wpc_did])) {
                            if (!$wpc_dep_host_ok($wpc_did) || $wpc_is_form($wpc_did)) {
                                $wpc_ok = false;
                                break;
                            }
                            $wpc_missing[] = $wpc_did;
                        }
                    }
                    // A promoted script that references jQuery pulls the core (+migrate) to parse with it.
                    if ($wpc_ok && isset($wpc_page_ids['jquery-core-js'])
                        && !isset($wpc_parse_ids['jquery-core-js']) && !isset($this->promoted_src_ids['jquery-core-js'])
                        && !in_array('jquery-core-js', $wpc_missing, true)
                        && self::wpc_src_needs_jquery(isset($wpc_page_srcs[$wpc_cid]) ? (string) $wpc_page_srcs[$wpc_cid] : '')) {
                        $wpc_missing[] = 'jquery-core-js';
                        if (isset($wpc_page_ids['jquery-migrate-js']) && !isset($wpc_parse_ids['jquery-migrate-js'])
                            && !isset($this->promoted_src_ids['jquery-migrate-js'])) {
                            $wpc_missing[] = 'jquery-migrate-js';
                        }
                    }
                    if ($wpc_ok && (count($this->promoted_src_ids) + 1 + count($wpc_missing)) <= $wpc_promo_cap) {
                        $this->promoted_src_ids[$wpc_cid] = true;
                        foreach ($wpc_missing as $wpc_mid) {
                            $this->promoted_src_ids[$wpc_mid] = true;
                            if (!isset($wpc_cand_base[$wpc_mid])) {
                                $wpc_mp = (string) parse_url((string) $wpc_page_srcs[$wpc_mid], PHP_URL_PATH);
                                $wpc_cand_base[$wpc_mid] = $wpc_mp !== '' ? basename($wpc_mp) : $wpc_mid;
                            }
                        }
                        $wpc_changed = true;
                    } elseif ($wpc_ok) {
                        $wpc_promo_skipped[strtolower((string) ($wpc_cand_base[$wpc_cid] ?? $wpc_cid))] = 'cap';
                    }
                }
                if (!$wpc_changed) {
                    break;
                }
            }
        }
        // Persist promoted basenames (bounded, only-on-change) — the telemetry self-tuner matches


        if (!empty($this->promoted_src_ids)) {
            try {
                $wpc_pb = get_option('wpc_delay_v3_promoted', []);
                if (!is_array($wpc_pb)) {
                    $wpc_pb = [];
                }
                $wpc_pn = array_values(array_unique(array_merge($wpc_pb, array_values(array_intersect_key($wpc_cand_base, $this->promoted_src_ids)))));
                $wpc_pn = array_slice($wpc_pn, -30);
                if ($wpc_pn !== $wpc_pb) {
                    update_option('wpc_delay_v3_promoted', $wpc_pn, false);
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            if ($wpc_promo_site === 'manifest_off') {
                self::wpc_promotion_clear();
            }
            $wpc_promo_applied = array_values(array_intersect_key($wpc_cand_base, $this->promoted_src_ids));
            foreach ($wpc_promo_applied as $wpc_promo_name) {
                unset($wpc_promo_skipped[strtolower((string) $wpc_promo_name)]);
            }
            $wpc_promo_key = class_exists('wps_ic_url_key') ? ltrim((string) (new wps_ic_url_key())->setup(''), '/') : '';
            if ($wpc_promo_key !== '' && ($wpc_promo_site !== '' || $wpc_promo_applied !== [] || $wpc_promo_skipped !== [] || get_option('wpc_delay_v3_promotion_log', false) !== false)) {
                self::wpc_promotion_note($wpc_promo_key, $wpc_promo_applied, $wpc_promo_skipped, $wpc_promo_site);
            }
        } catch (\Throwable $e) {
        }


        // AUTO-100 §4 lane-pin (A2 chain law): resolve stored pin intents against this
        // page's registered scripts. jQuery anywhere in the chain — or a chain we cannot
        // walk — DEGRADES to report-customer; never silently re-eager the biggest script.
        $lane_pins = get_option('wpc_presc_pins');
        if (is_array($lane_pins) && !empty($lane_pins) && apply_filters('wpc_presc_lane_pin', true)) {
            try {
                $pins_changed = false;
                $script_registry = !empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered)
                    ? $GLOBALS['wp_scripts'] : null;
                $src_by_id = [];
                if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $pin_tags)) {
                    foreach ($pin_tags[0] as $pin_tag) {
                        $pin_tag_attrs = $this->parse_script_attributes($pin_tag);
                        if (!empty($pin_tag_attrs['id']) && !empty($pin_tag_attrs['src'])) {
                            $src_by_id[(string) $pin_tag_attrs['id']] = html_entity_decode((string) $pin_tag_attrs['src']);
                        }
                    }
                }
                foreach ($lane_pins as $pin_id => $pin) {
                    if (!is_array($pin)) {
                        continue;
                    }
                    // Resolved pins RE-APPLY on every render (promoted_src_ids resets per
                    // document) — the stored handle-id list makes that a read-only pass.
                    if (($pin['state'] ?? '') === 'resolved') {
                        foreach ((array) ($pin['ids'] ?? []) as $resolved_id) {
                            if (isset($src_by_id[$resolved_id])) {
                                $this->promoted_src_ids[(string) $resolved_id] = true;
                            }
                        }
                        continue;
                    }
                    if (($pin['state'] ?? '') !== 'pending') {
                        continue;
                    }
                    $target_id = '';
                    foreach ((array) ($pin['cand'] ?? []) as $candidate) {
                        $candidate = strtolower(trim((string) $candidate));
                        if (strlen($candidate) < 4) {
                            continue;
                        }
                        foreach ($src_by_id as $page_script_id => $page_script_src) {
                            if (substr((string) $page_script_id, -3) === '-js' && stripos($page_script_src, $candidate) !== false) {
                                $target_id = (string) $page_script_id;
                                break 2;
                            }
                        }
                    }
                    if ($target_id === '') {
                        continue; // revealer not on this template — intent stays pending
                    }
                    $target_handle = substr($target_id, 0, -3);
                    $dep_chain = null;
                    if ($script_registry && !empty($script_registry->registered[$target_handle])) {
                        $dep_chain = [];
                        $walk_stack = [$target_handle];
                        $walk_seen = [$target_handle => true];
                        $walk_steps = 0;
                        while (!empty($walk_stack) && $walk_steps++ < 200) {
                            $walk_handle = array_pop($walk_stack);
                            if (empty($script_registry->registered[$walk_handle])) {
                                continue;
                            }
                            foreach ((array) $script_registry->registered[$walk_handle]->deps as $walk_dep) {
                                if (isset($walk_seen[$walk_dep])) {
                                    continue;
                                }
                                $walk_seen[$walk_dep] = true;
                                $dep_chain[] = $walk_dep;
                                $walk_stack[] = $walk_dep;
                            }
                        }
                    }
                    // A2 in full: jQuery anywhere in the chain (target src OR any chain
                    // member's src), an unwalkable chain, or a chain heavier than the win
                    // — all DEGRADE to report-customer.
                    $degrade = $dep_chain === null
                        || in_array('jquery', $dep_chain, true) || in_array('jquery-core', $dep_chain, true)
                        || self::wpc_src_needs_jquery((string) ($src_by_id[$target_id] ?? ''));
                    $degrade_reason = $dep_chain === null ? 'chain-unknown' : 'jquery-chain';
                    $chain_bytes = 0;
                    if (!$degrade) {
                        $chain_srcs = [(string) ($src_by_id[$target_id] ?? '')];
                        foreach ($dep_chain as $chain_handle) {
                            if (isset($src_by_id[$chain_handle . '-js'])) {
                                $chain_srcs[] = (string) $src_by_id[$chain_handle . '-js'];
                            }
                        }
                        foreach ($chain_srcs as $chain_src) {
                            if ($chain_src === '') {
                                continue;
                            }
                            if (self::wpc_src_needs_jquery($chain_src)) {
                                $degrade = true;
                                $degrade_reason = 'jquery-chain';
                                break;
                            }
                            if (($content_pos = strrpos($chain_src, 'wp-content/')) !== false) {
                                $relative_path = (string) preg_replace('/[?#].*$/', '', substr($chain_src, $content_pos));
                                if (strpos($relative_path, '..') === false) {
                                    $chain_bytes += (int) @filesize(trailingslashit(ABSPATH) . $relative_path);
                                }
                            }
                        }
                        if (!$degrade && $chain_bytes > (int) apply_filters('wpc_lane_pin_weight_cap', 262144)) {
                            $degrade = true;
                            $degrade_reason = 'heavier-than-win';
                        }
                    }
                    if ($degrade) {
                        $lane_pins[$pin_id]['state'] = 'degraded';
                        if (function_exists('wpc_presc_journal_put')) {
                            wpc_presc_journal_put((string) $pin_id, ['status' => 'report', 'fix' => 'lane-pin',
                                'class' => (string) ($pin['cl'] ?? ''), 'skipped' => $degrade_reason]);
                        }
                    } else {
                        $promotion_cap = (int) apply_filters('wpc_delay_v3_promotion_cap', 24);
                        if ((count($this->promoted_src_ids) + 1 + count($dep_chain)) > $promotion_cap) {
                            continue; // cap-full — intent stays pending for a later render
                        }
                        $pinned_ids = [$target_id];
                        $this->promoted_src_ids[$target_id] = true;
                        foreach ($dep_chain as $chain_handle) {
                            if (isset($src_by_id[$chain_handle . '-js'])) {
                                $this->promoted_src_ids[$chain_handle . '-js'] = true;
                                $pinned_ids[] = $chain_handle . '-js';
                            }
                        }
                        $lane_pins[$pin_id]['state'] = 'resolved';
                        $lane_pins[$pin_id]['ids'] = $pinned_ids;
                        if (function_exists('wpc_presc_journal_put')) {
                            wpc_presc_journal_put((string) $pin_id, ['status' => 'applied', 'fix' => 'lane-pin',
                                'class' => (string) ($pin['cl'] ?? '')]);
                        }
                    }
                    $pins_changed = true;
                }
                if ($pins_changed) {
                    update_option('wpc_presc_pins', $lane_pins, false);
                }
            } catch (\Throwable $e) {
            }
        }

        $this->companion_ids = [];
        $this->wpc_family_keep_ids = [];
        $wpc_excluded_ids = [];
        $wpc_runtime_tags = [];
        $seen_src_ids = [];
        if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $wpc_srctags)) {
            foreach ($wpc_srctags[0] as $wpc_t) {
                $wpc_a = $this->parse_script_attributes($wpc_t);
                // v7.21.95 — AN ID-LESS PARENT ORPHANS ITS COMPANIONS. This scan is the only
                // thing that pins a kept script's -js-before/-extra/-after inlines into the same
                // eager lane, and it keyed entirely on the tag's id — so a plugin that replaces
                // its own tag in script_loader_tag and drops the id gets its parent kept and its
                // companions left delayable. Amelia is exactly that shape: it prints
                // "<script type='module' crossorigin src='.../v3/public/assets/public.js'>" with
                // no id at all, the type rule in should_exclude_script keeps the module eager
                // (correctly — a module cannot be replayed in classic order), and modules are
                // spec-DEFERRED, so it executes before DOMContentLoaded while
                // amelia_booking_script_index-js-extra sits in the delay registry waiting for a
                // gesture: the Vue app boots with wpAmeliaSettings undefined and the booking
                // container stays empty forever. wp_scripts still holds the handle->src map at
                // this point, so resolve the handle off the src PATH and carry on with a
                // synthetic id. Kill wpc_srcless_id_resolve.
                if (empty($wpc_a['id']) && !empty($wpc_a['src'])) {
                    $resolved_handle = $this->wpc_handle_from_src((string) $wpc_a['src']);
                    if ($resolved_handle !== '') {
                        $wpc_a['id'] = $resolved_handle . '-js';
                    }
                }
                if (empty($wpc_a['id'])) {
                    continue;
                }
                $seen_src_ids[(string) $wpc_a['id']] = isset($wpc_a['src']) ? html_entity_decode((string) $wpc_a['src']) : '';
                if (preg_match('/^(.+)-webpack(?:-pro)?-runtime-js$/', (string) $wpc_a['id'], $wpc_fm)) {
                    $wpc_runtime_tags[(string) $wpc_a['id']] = $wpc_fm[1];
                }
                if ($this->should_exclude_script($wpc_a, '')) {
                    $wpc_excluded_ids[(string) $wpc_a['id']] = true;
                    $wpc_h = preg_replace('/-js$/', '', (string) $wpc_a['id']);
                    foreach (['-js-before', '-js-after', '-js-extra'] as $wpc_suf) {
                        $this->companion_ids[$wpc_h . $wpc_suf] = true;
                    }
                }
            }
        }

        // A KEPT INLINE COMPANION PINS ITS OWN LIBRARY. The map above runs one way only — parent
        // kept, therefore companions kept — but the reverse is just as fatal and it is what
        // eloorac hit the moment .805 stopped deferring jQuery: jquery-ui-datepicker-js-after was
        // kept and ran inside jQuery's ready, while jquery-ui-datepicker-js itself was delayed, so
        // jQuery.datepicker was undefined ("Cannot read properties of undefined (reading
        // 'setDefaults')"). An -after companion calls its parent's API by definition, so a companion
        // and its library must share a lane. Decided by the real should_exclude_script over the
        // companion's own attributes and body, not by a name pattern.
        if (apply_filters('wpc_companion_pins_library', true)
            && preg_match_all('/<script\b(?![^>]*\bsrc=)([^>]*\bid=["\']([^"\']+?)-after["\'][^>]*)>(.*?)<\/script>/is', $html, $kept_companions, PREG_SET_ORDER)) {
            foreach ($kept_companions as $companion_match) {
                $companion_parent_id = (string) $companion_match[2];
                if ($companion_parent_id === '' || isset($wpc_excluded_ids[$companion_parent_id])
                    || !isset($seen_src_ids[$companion_parent_id])) {
                    continue;
                }
                $companion_attrs = $this->parse_script_attributes('<script ' . $companion_match[1] . '>');
                if (!$this->should_exclude_script($companion_attrs, (string) $companion_match[3])) {
                    continue;
                }
                $this->companion_ids[$companion_parent_id]     = true;
                $this->wpc_family_keep_ids[$companion_parent_id] = true;
                $wpc_excluded_ids[$companion_parent_id]        = true;
            }
        }

        // elementor-frontend-js is the DOCUMENT SCANNER: at its init it instantiates every
        // elementor document on the page with whatever document classes are registered AT THAT
        // MOMENT. elementor-pro-frontend-js is the class REGISTRAR (popup among them). Scanner
        // kept + registrar delayed = every popup instantiated as a base document with no
        // getModal, permanently — the registrar arriving later re-instantiates nothing. So the
        // pro family rides the scanner's lane; the runtime loop below then carries
        // elementor-pro-webpack-runtime-js with it.
        // v7.22.28 — WIDGET RUNTIME DEPS RIDE WITH THE SCANNER. Elementor widgets declare
        // `imagesloaded`/`masonry` per WIDGET (get_script_depends), not on the frontend handle,
        // so the dep closure never carries them: Pro's Loop masonry (`initMasonry`) ran at
        // element_ready with `imagesloaded.min.js` still in the delayed lane — `imagesLoaded is
        // not defined` on columbus (audit check 7), masonry never laid out. Both are ~5KB
        // WP-core libs; they stay in the kept lane whenever elementor-frontend-js does.
        if (isset($wpc_excluded_ids['elementor-frontend-js'])
            && apply_filters('wpc_delay_elementor_family_lane', true)) {
            foreach (['elementor-pro-frontend-js', 'pro-elements-handlers-js', 'imagesloaded-js', 'masonry-js'] as $family_id) {
                if (!isset($seen_src_ids[$family_id]) || isset($wpc_excluded_ids[$family_id])) {
                    continue;
                }
                $this->companion_ids[$family_id]     = true;
                $this->wpc_family_keep_ids[$family_id] = true;
                $wpc_excluded_ids[$family_id]        = true;
                $family_handle = preg_replace('/-js$/', '', $family_id);
                foreach (['-js-before', '-js-after', '-js-extra'] as $wpc_suf) {
                    $this->companion_ids[$family_handle . $wpc_suf] = true;
                }
            }
        }
        // The other half of the same law: never keep a CONSUMER while delaying its DEPENDENCY.
        // pro-elements-handlers calls $menu.smartmenus() and .sticky() at widget init (DCL);
        // with the libs delayed, every RELOAD (cached = fast init) throws "smartmenus is not a
        // function" before the gesture releases them — staging receipt, reload-only, homepage.
        if (isset($wpc_excluded_ids['pro-elements-handlers-js'])
            && apply_filters('wpc_delay_elementor_family_lane', true)) {
            foreach (['smartmenus-js', 'e-sticky-js'] as $library_id) {
                if (!isset($seen_src_ids[$library_id]) || isset($wpc_excluded_ids[$library_id])) {
                    continue;
                }
                $this->companion_ids[$library_id]      = true;
                $this->wpc_family_keep_ids[$library_id] = true;
                $wpc_excluded_ids[$library_id]         = true;
            }
        }


        foreach ($wpc_runtime_tags as $wpc_rid => $wpc_fam) {
            foreach (array_keys($wpc_excluded_ids) as $wpc_eid) {
                if (strpos($wpc_eid, $wpc_fam . '-') === 0 && $wpc_eid !== $wpc_rid) {
                    $this->companion_ids[$wpc_rid]     = true;
                    $this->wpc_family_keep_ids[$wpc_rid] = true;
                    $wpc_excluded_ids[$wpc_rid]        = true;
                    $wpc_rh = preg_replace('/-js$/', '', $wpc_rid);
                    foreach (['-js-before', '-js-after', '-js-extra'] as $wpc_suf) {
                        $this->companion_ids[$wpc_rh . $wpc_suf] = true;
                    }
                    break;
                }
            }
        }


        // v7.21.56 — NEVER KEEP A CONSUMER WHILE DELAYING ITS DEPENDENCY, generalized from the
        // .747/.753 hardcoded families to the whole keep set: every kept handle pulls its
        // recursive wp_scripts dependency closure into the keep. columbuschiropractors:
        // uael-nav-menu-js (the mega-menu initializer) was kept by the measured manifest while
        // jquery sat in the delay registry — "jQuery is not defined" at parse, the nav module
        // never registered, and the open submenu rendered as overlapping text FOREVER (replayed
        // jQuery re-runs nothing). WordPress already knows the dependency edge (uael-nav-menu
        // depends on jquery); the walk resolves alias handles (jquery -> jquery-core) because
        // every dep re-enters the stack whether or not its own tag is on the page.
        foreach ($this->wpc_keep_dependency_closure($wpc_excluded_ids, $seen_src_ids, $html) as $closure_dep_id) {
            $this->companion_ids[$closure_dep_id]      = true;
            $this->wpc_family_keep_ids[$closure_dep_id] = true;
            $wpc_excluded_ids[$closure_dep_id]         = true;
            $closure_dep_handle = preg_replace('/-js$/', '', $closure_dep_id);
            foreach (['-js-before', '-js-after', '-js-extra'] as $closure_suffix) {
                $this->companion_ids[$closure_dep_handle . $closure_suffix] = true;
            }
        }

        // v7.22.14 — A KEEP CARRIES ITS PLUGIN'S PROVIDERS, handle-prefix edition. The .56
        // closure follows wp_scripts deps; a plugin that registers its provider as a SIBLING
        // handle (italianliquors: vidbgpro-js kept by the manifest, vidbgpro-vimeo-js =
        // player.vimeo.com/api/player.js delayed, no dep edge between them) throws
        // "Vimeo is not defined" from the kept script's jQuery-ready companion, the manifest
        // self-heal reverts the lane, and the page flips between measured and unmeasured on
        // every load. WordPress handle naming IS the family edge: every kept handle H also
        // keeps the page's H-<segment> handles. Core/library prefixes are excluded so a kept
        // jquery or elementor never sweeps their whole families. Over-keeping costs an eager
        // request; under-keeping costs a broken page. Cap-bounded, filter-killable.
        if (apply_filters('wpc_keep_prefix_family', true)) {
            $prefix_deny = ['jquery', 'jquery-core', 'jquery-migrate', 'jquery-ui', 'wp', 'wp-util', 'wp-i18n', 'wp-hooks',
                'underscore', 'backbone', 'lodash', 'react', 'react-dom', 'elementor', 'elementor-pro', 'wc', 'woocommerce',
                'google', 'gtm', 'gtag', 'swiper', 'bootstrap', 'font', 'fonts'];
            $prefix_kept_count = 0; $prefix_log = [];
            foreach (array_keys((array) $wpc_excluded_ids) as $kept_id) {
                $kept_handle = preg_replace('/-js$/', '', (string) $kept_id);
                if ($kept_handle === '' || strlen($kept_handle) < 4 || in_array($kept_handle, $prefix_deny, true)) {
                    continue;
                }
                foreach (array_keys((array) $seen_src_ids) as $sibling_id) {
                    $sibling_handle = preg_replace('/-js$/', '', (string) $sibling_id);
                    if (isset($wpc_excluded_ids[$sibling_id]) || strpos($sibling_handle, $kept_handle . '-') !== 0) {
                        continue;
                    }
                    if ($prefix_kept_count++ >= 20) { break 2; }
                    $this->companion_ids[$sibling_id]      = true;
                    $this->wpc_family_keep_ids[$sibling_id] = true;
                    $wpc_excluded_ids[$sibling_id]         = true;
                    foreach (['-js-before', '-js-after', '-js-extra'] as $sibling_suffix) {
                        $this->companion_ids[$sibling_handle . $sibling_suffix] = true;
                    }
                    if (count($prefix_log) < 6) { $prefix_log[] = $kept_handle . '>' . $sibling_handle; }
                }
            }
            if ($prefix_kept_count && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('keep-prefix-family', '', '', ['n' => $prefix_kept_count, 'pairs' => implode(',', $prefix_log)]);
            }
        }

        $this->parse_time_src_ids = $wpc_excluded_ids;

        $this->script_registry = array();
        $this->script_id = 0;
        $this->wpc_seen_ext_srcs = [];

        $pattern = '/<script\b[^>]*>(.*?)<\/script>/si';
        $html = preg_replace_callback($pattern, array($this, 'process_script_tag'), $html);

        // Integrations (Elementor entrance animations — same as v2)
        $html = $this->elementor_integration($html);

        // Loader config: timeout 0 = interaction-only (default); report = telemetry endpoint
        // (bounded + deduped server-side; disable per-site via the filter).


        $wpc_to = isset(self::$settings['delay-js-v3-timeout']) ? (int) self::$settings['delay-js-v3-timeout'] : 60;
        if ($wpc_to <= 0 && !apply_filters('wpc_delay_v3_interaction_only', false)) {
            $wpc_to = 60;
        }


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_maximum_mobile_on')
            && apply_filters('wpc_maximum_mobile', wps_rewriteLogic::wpc_maximum_mobile_on())) {
            $wpc_to = 0;
        }
        // Aggressive default: a MEASURED page (current-schema gen, keep list
        // authoritative) goes interaction-only — the trace runs ~zero JS, humans
        // get warmed/gesture boot. The boot watchdog demotes the site here
        // (wpc_delay_aggr_off) when boots break; a no-crit page self-clamps to
        // 4s client-side; wpc_delay_v3_timeout still has the last word. Gated
        // on the telemetry channel being alive — aggressive without its
        // rollback belt is not a trade we make on the owner's behalf.
        $aggressive = 0;
        if ($wpc_to > 0 && $this->wpc_measured
            && !get_option('wpc_delay_aggr_off')
            && apply_filters('wpc_delay_v3_telemetry', true)
            && apply_filters('wpc_delay_v3_io_when_measured', true)) {
            $wpc_to = 0;
            $aggressive = 1;
        }
        $wpc_cfg = [
            'timeout' => (int) apply_filters('wpc_delay_v3_timeout', $wpc_to),
            // v7.22.02 — one-shot owner + dispatch door (the .364 machinery). elementorHeal=0
            // reverts the loader to inert heals fleet-wide without a re-ship: the belts
            // then skip both diff-fire and door dispatches entirely (never the old
            // blanket re-fires — those are gone for good).
            'elementorHeal' => apply_filters('wpc_delay_v3_ef364', true) ? 1 : 0,
            // Marks the AGGRESSIVE-DEFAULT flip specifically (not maximum-mobile,
            // not user io): the boot watchdog arms ONLY on aggr pages, so demote
            // strikes always come from pages the demote actually fixes.
            'aggr' => $aggressive,


            'report'  => apply_filters('wpc_delay_v3_telemetry', true) && function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            // This page's report stamp: the report handler refuses a report that does not carry the
            // stamp minted for its path, because the Origin header it trusted before is set by any
            // client (a forged one switched site-wide levers). See wpc_delay_report_stamp().
            'rs' => function_exists('wpc_delay_report_stamp') ? wpc_delay_report_stamp(wpc_delay_report_page_path()) : '',


            // Google Maps iframe swapped at the 3s tick, then executed 445KB/286ms main-thread from
            // INSIDE that iframe — invisible to script-registry capture since it's a separate


            // HEAVY = restored only at boot (post-gesture under io) via frames(true)
            // — the correct direction for consent-delayed too (a .360-.362 bug
            // ZEROED this list under consent-delayed, which demoted embeds to the
            // NON-heavy immediate tick restore — backwards; fixed). Funnel/player
            // iframes (GHL widgets ~3MB of reCAPTCHA+forms JS per frame, receipted
            // on busyprosai) join Maps: separate documents that script delay can't
            // touch — the facade + this list is their only lever. The IO restore
            // in the loader still loads any frame a real visitor scrolls toward.
            'heavyEmbeds' => array_values(apply_filters('wpc_delay_heavy_embeds', [
                'google.com/maps', 'maps.google.', 'maps.googleapis.',
                'leadconnectorhq.com', 'msgsndr.com', 'filesafe.space',
                '/widget/booking/', '/widget/form/', '/widget/quiz/', '/widget/survey/',
                'player.vimeo.com', 'youtube.com/embed/', 'youtube-nocookie.com/embed/',
                'fast.wistia.',
                // v7.21.58 — Bunny Stream embeds: 1.28MB (plyr-vr 625KB + hls 311KB + its own
                // jQuery) and ~500ms main-thread per ctfx PSI, all from inside the frame where
                // script delay can't reach — exactly the youtube/vimeo class.
                'iframe.mediadelivery.net',
                'youtube.com/iframe_api', 'youtube.com/player_api', 'player.vimeo.com/api/player.js',
                'fast.wistia.com/assets/external/', 'fast.wistia.net/assets/external/',
            ])),
            'embedGate' => (int) apply_filters('wpc_delay_embed_gate', 1),


            // v7.10.504 — REACHABILITY FIX. .497 defaulted the atomic cascade OFF, but the flag was
            // only ever READ in the loader and never written into this config, so there was no way to
            // turn it back on. wpcompress.com measured TBT 0ms with it active and TBT 5,900ms with it
            // off (one 6,184ms task; Script Evaluation only 207ms against Other 7,130ms — style
            // recalculation, which is exactly what restoring ~44 sheets one at a time produces).
        ] + self::wpc_css_cfg();

        // Delayed lane rides the CDN (post-interaction — never in the render chain). The render-lane
        // standdown (wpc_scripts_same_origin) does not govern here; the loader carries origin
        // failover for every zoned src, so a dead zone costs one retry, never a lost chain.
        $any_zoned = false;
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_zone_delayed_js_url')
            && is_array($this->script_registry)) {
            foreach ($this->script_registry as $registry_index => $registry_entry) {
                if (empty($registry_entry['src']) || !is_string($registry_entry['src'])) {
                    continue;
                }
                // Modules and SRI-pinned tags stay on the origin: a cross-origin module needs CORS
                // headers, and a cross-origin integrity check needs crossorigin — either one fails
                // the load, and a failed load here would flip the whole session origin-first for a
                // reason that is not a zone outage.
                if (strtolower((string) (isset($registry_entry['type']) ? $registry_entry['type'] : '')) === 'module'
                    || !empty($registry_entry['attributes']['integrity'])) {
                    continue;
                }
                $src_encoded = !empty($registry_entry['encoded']);
                $delayed_src = $src_encoded ? base64_decode($registry_entry['src'], true) : $registry_entry['src'];
                if (!is_string($delayed_src) || $delayed_src === '') {
                    continue;
                }
                $zoned_src = wps_rewriteLogic::wpc_zone_delayed_js_url($delayed_src);
                if (is_string($zoned_src) && $zoned_src !== '' && $zoned_src !== $delayed_src) {
                    $this->script_registry[$registry_index]['src'] = $src_encoded ? base64_encode($zoned_src) : $zoned_src;
                    $any_zoned = true;
                }
            }
        }
        if ($any_zoned && class_exists('wps_rewriteLogic') && !empty(wps_rewriteLogic::$zoneName)
            && is_string(wps_rewriteLogic::$zoneName)) {
            $wpc_cfg['cdnHost'] = wps_rewriteLogic::$zoneName;
        }

        // Registry keeps the v2 name (wpcScriptRegistry) — the adopted loader and its debug tooling
        // (window.ScriptDelayDebug) consume it; v2 and v3 are mutually exclusive on a page.
        $delay_script = '';
        if (!empty(get_option('wps_ic_delay_v2_debug'))) {
            $delay_script .= '<script>var DEBUG = true;</script>';
        }
        // The bodies of parked inline scripts ride a sidecar file, not the document: the page
        // carries the registry skeleton and wpcDelayV3Cfg.registryUrl, and the loader merges the
        // bodies in after load. ?wpc_registry_inline=1 is the loader's own fallback request and
        // always gets them inline.
        $wpc_registry = $this->script_registry;
        $wpc_side = null;
        if (empty($_GET['wpc_registry_inline']) && apply_filters('wpc_delay_registry_sidecar', true)) {
            $wpc_sp = self::wpc_registry_sidecar_paths();
            if ($wpc_sp) {
                $wpc_side = self::wpc_registry_sidecar($wpc_registry, $wpc_sp['dir'], $wpc_sp['url']);
            }
        }
        if (is_array($wpc_side)) {
            $wpc_cfg['registryUrl'] = $wpc_side['url'];
            $wpc_cfg['registryN'] = (int) $wpc_side['n'];
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('delay-registry-sidecar', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', ['n' => $wpc_side['n'], 'bytes' => $wpc_side['bytes'], 'file' => $wpc_side['file']]);
            }
        }
        $delay_script .= '<script id="wpc-delay-v3-registry">var wpcScriptRegistry=' . json_encode($wpc_registry)
            . ';var wpcDelayV3Cfg=' . json_encode($wpc_cfg) . ';'


            . 'if(!document.getElementById("wpc-critical-css")){wpcDelayV3Cfg.timeout=Math.min(+wpcDelayV3Cfg.timeout||60,4);}'
            . '</script>';
        if (!empty($wpc_cfg['embedGate'])) {
            $delay_script .= '<script id="wpc-embed-gate">' . self::wpc_embed_gate_inline_js() . '</script>';
        }


        $delay_script .= self::wpc_loader_script_tag();

        // Same anchor the v2 engine uses (printed by enqueues.class.php when delay is on); fall
        // back to </body> so the registry can never be emitted without its loader.
        if (strpos($html, '<script type="wpc-delay-placeholder"></script>') !== false) {
            $html = str_replace('<script type="wpc-delay-placeholder"></script>', $delay_script, $html);
        } else {
            $html = wpc_inject_before_body_close($html, $delay_script);
        }

        $html = $this->wpc_defer_remaining_blocking_scripts($html);
        $html = self::wpc_enforce_defer_split_point($html);
        $html = $this->wpc_module_hoist($html);
        $html = $this->wpc_lazy_load_consent_css($html);
        $html = self::wpc_embed_load_recorder($html);

        return $html;
    }

    public static function wpc_embed_load_recorder($html)
    {
        if (!is_string($html) || $html === '' || strpos($html, 'id="wpc-embed-loads"') !== false
            || !apply_filters('wpc_embed_load_recorder', true)
            || !preg_match('/<head\b[^>]*>/i', $html, $wpc_m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $wpc_tag = '<script data-nodefer="1" id="wpc-embed-loads">(function(){try{var s=window.wpcEmbedLoads=new WeakMap,'
            . 'b=window.wpcEmbedBefore=new WeakSet,l=document.querySelectorAll("iframe,frame,object,embed");for(var i=0;i<l.length;i++)b.add(l[i]);'
            . 'document.addEventListener("load",function(e){var t=e.target,n=t&&t.tagName;'
            . 'if(n==="IFRAME"||n==="FRAME"||n==="OBJECT"||n==="EMBED")s.set(t,t.src||t.data||"")},true)}catch(x){}})();</script>';
        $wpc_at = $wpc_m[0][1] + strlen($wpc_m[0][0]);
        return substr($html, 0, $wpc_at) . $wpc_tag . substr($html, $wpc_at);
    }

    /**
     * Vendor families of the executable script modules on the page, keyed by the id
     * prefix before '/' (or the id minus -js/-js-module); an id-less module is '*'.
     * A script module is spec-deferred and cannot declare classic dependencies, so the
     * classics it consumes (WP core dist, its own family) must never be delayed, and the
     * module itself must run after them (wpc_module_hoist). Filter wpc_module_consumers.
     */
    public static function wpc_module_vendors($html)
    {
        $wpc_out = [];
        if (!is_string($html) || $html === '' || !apply_filters('wpc_module_consumers', true)
            || (stripos($html, 'type="module"') === false && stripos($html, "type='module'") === false)) {
            return $wpc_out;
        }
        if (!preg_match_all('/<script\b[^>]*\btype=["\']module["\'][^>]*>/i', $html, $wpc_m)) {
            return $wpc_out;
        }
        foreach ($wpc_m[0] as $wpc_t) {
            if (!preg_match('/\bsrc=["\']([^"\']+)["\']/i', $wpc_t, $wpc_s) || stripos($wpc_s[1], 'data:') === 0) {
                continue;
            }
            $wpc_id = preg_match('/\bid=["\']([^"\']+)["\']/i', $wpc_t, $wpc_i) ? strtolower(trim($wpc_i[1])) : '';
            $wpc_v = '';
            if ($wpc_id !== '') {
                $wpc_v = strpos($wpc_id, '/') !== false ? substr($wpc_id, 0, strpos($wpc_id, '/'))
                    : (string) preg_replace('/-js(?:-module)?$/', '', $wpc_id);
            }
            $wpc_out[$wpc_v !== '' ? $wpc_v : '*'] = true;
        }
        return $wpc_out;
    }

    /** Handles of the WP core dist scripts (/wp-includes/js/dist/, vendor/ included) on the page. */
    public static function wpc_module_core($html)
    {
        $wpc_out = [];
        if (is_string($html) && preg_match_all('/<script\b[^>]*\bsrc=["\'][^"\']*\/wp-includes\/js\/dist\/[^>]*>/i', $html, $wpc_m)) {
            foreach ($wpc_m[0] as $wpc_t) {
                if (preg_match('/\bid=["\']([^"\']+)-js["\']/i', $wpc_t, $wpc_i)
                    && preg_match('/\bsrc=["\'][^"\']*\/wp-includes\/js\/dist\/(?:vendor\/)?[^\/?"\']+\.js(?:\?[^"\']*)?["\']/i', $wpc_t)) {
                    $wpc_out[strtolower(trim($wpc_i[1]))] = true;
                }
            }
        }
        return $wpc_out;
    }

    /**
     * True for a classic script a module on this page consumes: a WP core dist file, the
     * -after/-before/-extra companion of a core handle, or a script whose id carries a
     * module family prefix. These stay eager on module pages.
     */
    protected function wpc_module_consumer($attributes)
    {
        if (empty($this->wpc_mod_vendors) || !is_array($this->wpc_mod_vendors) || !is_array($attributes)) {
            return false;
        }
        $wpc_src = isset($attributes['src']) ? strtolower(html_entity_decode((string) $attributes['src'])) : '';
        if ($wpc_src !== '' && strpos($wpc_src, 'data:') !== 0
            && preg_match('#/wp-includes/js/dist/(?:vendor/)?[^/?]+\.js(?:\?|$)#', $wpc_src)) {
            return true;
        }
        $wpc_id = isset($attributes['id']) ? strtolower(trim((string) $attributes['id'])) : '';
        if ($wpc_id === '') {
            return false;
        }
        if (!empty($this->wpc_mod_core) && is_array($this->wpc_mod_core)
            && preg_match('/^(.+)-js(?:-after|-before|-extra)$/', $wpc_id, $wpc_c) && isset($this->wpc_mod_core[$wpc_c[1]])) {
            return true;
        }
        foreach (array_keys($this->wpc_mod_vendors) as $wpc_v) {
            if ($wpc_v !== '*' && strlen($wpc_v) >= 3 && strpos($wpc_id, $wpc_v) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Moves the executable type="module" src tags to the end of <body>. Deferred classics
     * and modules share one in-order execution list, so the module then runs after every
     * classic it consumes, as it did with the plugin off; nothing is forced blocking.
     * Journal module-hoist. Filter wpc_module_hoist.
     */
    private function wpc_module_hoist($html)
    {
        if (empty($this->wpc_mod_vendors) || !is_string($html) || $html === '' || !apply_filters('wpc_module_hoist', true)) {
            return $html;
        }
        if (!preg_match_all('/<script\b(?:[^>]*\btype=["\']module["\'][^>]*\bsrc=|[^>]*\bsrc=[^>]*\btype=["\']module["\'])[^>]*>\s*<\/script>/i', $html, $wpc_m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $wpc_end = strripos($html, '</body>');
        if ($wpc_end === false) {
            return $html;
        }
        $wpc_tags = [];
        for ($wpc_i = count($wpc_m[0]) - 1; $wpc_i >= 0; $wpc_i--) {
            $wpc_off = (int) $wpc_m[0][$wpc_i][1];
            if ($wpc_off > $wpc_end) {
                continue;
            }
            $wpc_tags[] = $wpc_m[0][$wpc_i][0];
            $html = substr($html, 0, $wpc_off) . substr($html, $wpc_off + strlen($wpc_m[0][$wpc_i][0]));
        }
        if (empty($wpc_tags)) {
            return $html;
        }
        $wpc_end = strripos($html, '</body>');
        $html = substr($html, 0, $wpc_end) . implode('', array_reverse($wpc_tags)) . substr($html, $wpc_end);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('module-hoist', '', '', ['n' => count($wpc_tags), 'vendors' => implode(',', array_keys($this->wpc_mod_vendors))]);
        }
        return $html;
    }

    /**
     * FINAL DEFER SWEEP (v7.10.527).
     *
     * A render-blocking external in the head stops paint until it is fetched AND executed —
     * PSI measured jQuery at 1,200 ms duration on a page whose whole LCP delay was 2,060 ms.
     * The per-tag defer injection above should already have handled it and demonstrably does
     * not always, so this pass asserts the OUTCOME against the finished document rather than
     * trusting the injection that produced it.
     *
     * It is self-guarding: it re-derives safety from the page rather than trusting engine
     * state. A script is deferred only when nothing on the page can need it at parse.
     */
    private function wpc_defer_remaining_blocking_scripts($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_defer_sweep', true)) {
            return $html;
        }
        // Inline companions: an -after companion calls its parent's API at parse, so the parent
        // cannot defer. -before companions are config setters and do NOT disqualify (.564) —
        // WP core's actual rule in filter_eligible_strategies.
        $pairedIds = [];
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+?)-after["\']/i', $html, $afterMatches)) {
            foreach ((array) $afterMatches[1] as $parentId) {
                $pairedIds[strtolower($parentId)] = 1;
            }
        }
        // Bodies of inline scripts that will actually RUN (a type-swapped tag is a delay
        // placeholder and executes later, so it cannot need anything at parse).
        $executableInline = '';
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)([^>]*)>(.*?)<\/script>/is', $html, $inlineMatches, PREG_SET_ORDER)) {
            foreach ($inlineMatches as $inlineMatch) {
                if (preg_match('/type=["\']([^"\']+)["\']/i', $inlineMatch[1], $inlineType)
                    && stripos($inlineType[1], 'javascript') === false) {
                    continue;
                }
                $executableInline .= "\n" . $inlineMatch[2];
            }
        }
        $sweptDeferred = 0;
        $swept = preg_replace_callback('/<script\b[^>]*\bsrc=[^>]*>/i', function ($tagMatch) use ($pairedIds, $executableInline, &$sweptDeferred) {
            $tag = $tagMatch[0];
            if (preg_match('/\bdefer\b/i', $tag) || preg_match('/\basync\b/i', $tag)) {
                return $tag;
            }
            if (preg_match('/type=["\']([^"\']+)["\']/i', $tag, $tagType)
                && stripos($tagType[1], 'javascript') === false) {
                return $tag;
            }
            $scriptId = preg_match('/\bid=["\']([^"\']+)["\']/i', $tag, $idMatch)
                ? strtolower($idMatch[1]) : '';
            if ($scriptId !== '' && !empty($pairedIds[$scriptId])) {
                return $tag;
            }
            // jQuery is the one global an executable inline can plausibly touch at parse.
            // Verified on the live page: 39 inline scripts type-swapped, 15 executable, ZERO
            // referencing jQuery — but re-check per page, never assume.
            if (preg_match('/jquery/i', $tag)
                && preg_match('/\bjQuery\b|\$\(/', $executableInline)) {
                return $tag;
            }
            $sweptDeferred++;
            return preg_replace('/<script\b/i', '<script defer data-wpc-defer="1"', $tag, 1);
        }, $html);
        // An external script still render-blocking after the per-tag defer injection is deferred
        // here. Never sampled: each is a tag the injection missed.
        if ($sweptDeferred > 0 && is_string($swept) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('defer-sweep-deferred', ['n' => $sweptDeferred]);
        }
        return $swept;
    }

    /**
     * ONE-LANE-PER-CHAIN ENFORCEMENT (v7.10.564).
     *
     * A keep with an -after companion cannot defer, so it executes at parse — and WP prints
     * dependencies BEFORE dependents, so its whole dependency closure sits earlier in the
     * document. Any of those we deferred would execute AFTER their dependent: inversion.
     * Document position is a conservative superset of the dependency graph, so stripping our
     * defers at-or-before the LAST forced-blocking keep is provably inversion-free with no
     * registry knowledge. Marker-scoped: author-supplied defer is never touched. Degenerate
     * worst case (forced-blocking keep is the last keep) equals the old .512 blanket exactly.
     */
    // Expand a companion-pair map ({tag-id-minus-after} => 1) through wp_scripts alias handles:
    // a registered handle with no src renders no tag, so a companion attached to it belongs to
    // the tags its dependencies render. Bounded to 3 passes for nested aliases; fail-open when
    // wp_scripts is absent (static replay contexts) — the map is simply not widened.
    protected static function wpc_expand_alias_pairs($pairs)
    {
        if (empty($pairs) || !apply_filters('wpc_defer_alias_pairs', true)
            || empty($GLOBALS['wp_scripts']) || !is_object($GLOBALS['wp_scripts'])
            || empty($GLOBALS['wp_scripts']->registered) || !is_array($GLOBALS['wp_scripts']->registered)) {
            return $pairs;
        }
        for ($pass = 0; $pass < 3; $pass++) {
            $grew = false;
            foreach ($GLOBALS['wp_scripts']->registered as $handle => $registration) {
                if (!is_object($registration) || !empty($registration->src) || empty($registration->deps)) {
                    continue;
                }
                if (empty($pairs[strtolower((string) $handle) . '-js'])) {
                    continue;
                }
                foreach ((array) $registration->deps as $dep) {
                    $dep_key = strtolower((string) $dep) . '-js';
                    if (empty($pairs[$dep_key])) {
                        $pairs[$dep_key] = 1;
                        $grew = true;
                    }
                }
            }
            if (!$grew) {
                break;
            }
        }
        return $pairs;
    }

    private static function wpc_blocking_script_handles($html)
    {
        $handles = [];
        if (!preg_match_all('/<script\b([^>]*)>/i', (string) $html, $tags)) {
            return $handles;
        }
        foreach ($tags[1] as $attrs) {
            $bare = (string) preg_replace('/(["\'])(?:(?!\1).)*\1/s', ' ', $attrs);
            if (!preg_match('/\bsrc\s*=/i', $attrs) || preg_match('/(?<![-\w=])(?:defer|async|nomodule)(?![-\w])/i', $bare)) {
                continue;
            }
            if (preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $type)
                && !preg_match('#^(?:text|application)/(?:x-)?(?:java|ecma)script$#i', $type[1])) {
                continue;
            }
            if (preg_match('/\bid=["\']([^"\']+)-js["\']/i', $attrs, $id)) {
                $handles[strtolower($id[1])] = 1;
            }
        }
        return $handles;
    }

    private static function wpc_handle_has_blocking_dependant($handle, array $blocking_handles)
    {
        if ($handle === '' || $blocking_handles === []) {
            return false;
        }
        $ws = (!empty($GLOBALS['wp_scripts']) && is_object($GLOBALS['wp_scripts'])
            && !empty($GLOBALS['wp_scripts']->registered) && is_array($GLOBALS['wp_scripts']->registered))
            ? $GLOBALS['wp_scripts'] : null;
        if ($ws === null) {
            return true;
        }
        $registered = array_change_key_case($ws->registered, CASE_LOWER);
        foreach (array_keys($blocking_handles) as $blocking) {
            if ($blocking === $handle) {
                continue;
            }
            $seen = [];
            $stack = [$blocking];
            while ($stack && count($seen) < 400) {
                $current = array_pop($stack);
                if (isset($seen[$current])) {
                    continue;
                }
                $seen[$current] = 1;
                $deps = isset($registered[$current]) && is_object($registered[$current]) ? (array) $registered[$current]->deps : [];
                foreach ($deps as $dep) {
                    $dep = strtolower((string) $dep);
                    if ($dep === $handle) {
                        return true;
                    }
                    $stack[] = $dep;
                }
            }
        }
        return false;
    }

    private static function wpc_enforce_defer_split_point($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_defer_split', true)) {
            return $html;
        }
        if (strpos($html, 'data-wpc-defer="1"') === false) {
            return $html;
        }
        try {
            $after_parents = [];
            if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+?)-after["\']/i', $html, $after_matches)) {
                foreach ((array) $after_matches[1] as $parent_id) {
                    $after_parents[strtolower($parent_id)] = 1;
                }
            }
            if (empty($after_parents)) {
                return $html;
            }
            $after_parents = self::wpc_expand_alias_pairs($after_parents);
            $split_offset = -1;
            if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $tag_matches, PREG_OFFSET_CAPTURE)) {
                foreach ($tag_matches[0] as $tag_match) {
                    $tag = $tag_match[0];
                    if (preg_match('/(?<![-\w=])(?:defer|async)(?![-\w])/i', (string) preg_replace('/(["\'])(?:(?!\1).)*\1/s', ' ', $tag))) {
                        continue;
                    }
                    if (preg_match('/type=["\']([^"\']+)["\']/i', $tag, $type_match)
                        && stripos($type_match[1], 'javascript') === false) {
                        continue;
                    }
                    if (!preg_match('/\bid=["\']([^"\']+)["\']/i', $tag, $id_match)
                        || empty($after_parents[strtolower($id_match[1])])) {
                        continue;
                    }
                    $split_offset = max($split_offset, $tag_match[1] + strlen($tag));
                }
            }
            if ($split_offset < 0) {
                return $html;
            }
            $blocking_handles = self::wpc_blocking_script_handles($html);
            $head_slice = preg_replace_callback('/<script\b[^>]*>/i', function ($open_tag) use ($blocking_handles) {
                if (strpos($open_tag[0], ' defer data-wpc-defer="1"') === false) {
                    return $open_tag[0];
                }
                if ((stripos($open_tag[0], 'data-wp-strategy="defer"') !== false || stripos($open_tag[0], "data-wp-strategy='defer'") !== false)
                    && preg_match('/\bid=["\']([^"\']+)-js["\']/i', $open_tag[0], $strategy_id)
                    && !self::wpc_handle_has_blocking_dependant(strtolower($strategy_id[1]), $blocking_handles)) {
                    return $open_tag[0];
                }
                return str_replace(' defer data-wpc-defer="1"', '', $open_tag[0]);
            }, substr($html, 0, $split_offset));
            if (!is_string($head_slice)) {
                $head_slice = str_replace(' defer data-wpc-defer="1"', '', substr($html, 0, $split_offset));
            }
            // Our defers before the last blocking keep with an -after companion would run after
            // their dependant, so they are taken back. Sampled: most pages print such a keep
            // (jQuery with an inline -after), so this acts on nearly every render the same way.
            $undeferred = substr_count(substr($html, 0, $split_offset), ' defer data-wpc-defer="1"')
                - substr_count($head_slice, ' defer data-wpc-defer="1"');
            if ($undeferred > 0 && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('defer-split-undeferred', ['n' => $undeferred], true);
            }
            return $head_slice . substr($html, $split_offset);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    // v7.21.94 — extract self-contained top-level setter IIFEs from a script body.
    // Only blocks of the exact shape `(function ... { ... })(...)` that write a custom
    // property and reference nothing external (no jQuery/$, no DOM writes, no network)
    // qualify; anything else returns '' and the page simply keeps the .74 defer keep.
    public static function wpc_classify_root_var_setter($src)
    {
        try {
            if (!function_exists('wp_remote_get') || !function_exists('get_option')) {
                return;
            }
            $k = md5((string) $src);
            $r = wp_remote_get(html_entity_decode((string) $src), ['timeout' => 3, 'sslverify' => false]);
            if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
                return;
            }
            $b = substr((string) wp_remote_retrieve_body($r), 0, 65536);
            $v = ($b !== '' && preg_match('/\.style\.setProperty\(\s*["\']--/', $b)) ? 1 : 0;
            $map = get_option('wpc_rootvar_setters74');
            $map = is_array($map) ? $map : [];
            if (!array_key_exists($k, $map)) {
                if (count($map) >= 48) {
                    return;
                }
                $map[$k] = $v;
                update_option('wpc_rootvar_setters74', $map, false);
            }
            if ($v) {
                $sn = get_option('wpc_rootvar_snips94');
                $sn = is_array($sn) ? $sn : [];
                if (!array_key_exists($k, $sn) && count($sn) < 24) {
                    $sn[$k] = self::wpc_extract_root_var_setter_iifes($b);
                    update_option('wpc_rootvar_snips94', $sn, false);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    protected static function wpc_extract_root_var_setter_iifes($body)
    {
        try {
            $blocks = [];
            $offset = 0;
            while (count($blocks) < 2 && preg_match('/\(\s*function\b/', $body, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $start = (int) $match[0][1];
                $offset = $start + 1;
                $depth = 0;
                $end = -1;
                $scan_limit = min(strlen($body), $start + 4096);
                for ($i = $start; $i < $scan_limit; $i++) {
                    $char = $body[$i];
                    if ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }
                if ($end < 0) {
                    continue;
                }
                $tail = substr($body, $end + 1, 24);
                if (!preg_match('/^\s*\(\s*[\w.]*\s*\)\s*;?/', $tail, $call_match)) {
                    continue;
                }
                $block = substr($body, $start, ($end + 1 + strlen($call_match[0])) - $start);
                if (!preg_match('/\.style\.setProperty\(\s*["\']--/', $block)) {
                    continue;
                }
                if (preg_match('/jQuery|\$\s*\(|document\.write|<\/script|fetch\s*\(|XMLHttpRequest|localStorage|innerHTML|appendChild|createElement/i', $block)) {
                    continue;
                }
                if (substr_count($block, '{') !== substr_count($block, '}')
                    || substr_count($block, '{') === 0) {
                    continue;
                }
                $blocks[] = $block;
                $offset = $end + 1;
            }
            $joined = implode("\n", $blocks);
            return strlen($joined) <= 6144 ? $joined : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // v7.23.15 — the CSS lane's loader config, shared by process_html (delay on) and
    // wpc_css_only_loader (delay off): cascade mode, engagement signals, ATF reveal, the
    // late-CSS timers and the conceal classes. One definition, identical bytes on both lanes.
    public static function wpc_css_cfg()
    {
        return [
            'atomicCascade' => (int) apply_filters('wpc_atomic_cascade', 1),

            // v7.10.639 — renamed: the signals are engagement evidence (gesture, hover,
            // referrer, prior completed visit), not humanity detection. Old filter still
            // honored; the loader reads the old KEY too (cached pages emit it for a while).
            'engagementSignals' => apply_filters('wpc_delay_engagement_signals', apply_filters('wpc_delay_human_signals', true)) ? 1 : 0,


            'atfReveal' => apply_filters('wpc_delay_atf_anim_reveal', true) ? 1 : 0,


            'lateCssBackstop' => (int) apply_filters('wpc_delay_latecss_backstop', 30000),

            // v7.10.714 — the no-gesture late-CSS lane (loadEventEnd + this delay) must start
            // its fetches well past the point where the page has finished painting: an edge-warm
            // font completing near the LCP candidate window re-enters the simulated dependency
            // chain even though it changes no pixels above the fold (data: subsets + metric
            // fallbacks carry ATF text either way). 2500ms clears that window at any cache
            // temperature; every gesture path is untouched and still flips immediately.
            'lateCssTimer' => (int) apply_filters('wpc_delay_latecss_timer', 2500),
            'lateCssLcp' => (int) apply_filters('wpc_delay_latecss_lcp', 2000),
            'lateCssCap' => (int) apply_filters('wpc_delay_latecss_cap', 7000),


            'conceal' => array_values(array_filter(
                array_unique(array_map('strval', (array) apply_filters('wpc_delay_conceal_classes', array_merge(
                    ['pa-display-conditions-yes'],
                    (array) get_option('wpc_auto_conceal_classes', [])
                )))),
                function ($c) {
                    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $c);
                }
            )),
        ];
    }

    // v7.23.15 — CRITICAL CSS OWNS ITS RESTORER. When a render carries a crit block AND parked
    // forms but the v3 pass did not run (delay off, per-page JS off, v3 forced off), emit the
    // same loader with an EMPTY registry and cssOnly=1. The loader's JS lane stands down on
    // that flag (no traps, no replay, the site's own scripts run natively — the 7.22.53 rule);
    // its CSS lane restores the parked sheets, keeps #wpc-critical-css until CSS is live and
    // arms html.wpc-bgl255 on the first gesture, exactly as on a delay page. Same anchor as
    // process_html (placeholder, else body-end). No report key: no beacons without a replay.
    // Kill: wpc_css_only_loader.
    public static function wpc_css_only_loader($html)
    {
        try {
            if (!is_string($html) || $html === '' || !apply_filters('wpc_css_only_loader', true)) {
                return $html;
            }
            if (strpos($html, 'wpc-delay-v3-loader') !== false) {
                return $html; // the v3 pass emitted its own
            }
            if (!preg_match('/<style[^>]*id=["\']wpc-critical-css["\'][^>]*>\s*(?!<\/style)\S/i', $html)) {
                return $html; // no crit on this render, so nothing of ours parked a sheet
            }
            if (!preg_match('/<(?:link|style)\b[^>]*\b(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $html)) {
                return $html; // crit present, nothing parked (drift / blind / passthrough / excludes)
            }
            $wpc_cfg = ['cssOnly' => 1] + self::wpc_css_cfg();
            $wpc_tag = '<script id="wpc-delay-v3-registry">var wpcScriptRegistry=[];var wpcDelayV3Cfg=' . json_encode($wpc_cfg) . ';</script>'
                . self::wpc_loader_script_tag();
            if (strpos($html, '<script type="wpc-delay-placeholder"></script>') !== false) {
                return str_replace('<script type="wpc-delay-placeholder"></script>', $wpc_tag, $html);
            }
            if (function_exists('wpc_inject_before_body_close')) {
                return wpc_inject_before_body_close($html, $wpc_tag);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }
}
