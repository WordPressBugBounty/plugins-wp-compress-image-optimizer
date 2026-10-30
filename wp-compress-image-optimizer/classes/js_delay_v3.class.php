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
    protected $wpc_sd_families = [];
    protected $wpc_sd_src_tags = [];
    protected $wpc_first_screen_sr7_module = false;  // a Slider Revolution 7 module sits on the first screen


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

    const WPC_FONTAWESOME_HOSTS = ['kit.fontawesome.com', 'use.fontawesome.com', 'ka-p.fontawesome.com', 'ka-f.fontawesome.com'];
    protected $wpc_fontawesome_keep = false;

    public function __construct()
    {
        parent::__construct();

        $this->wpc_fontawesome_keep = (bool) apply_filters('wpc_never_delay_fontawesome_kit', true);

        // Dependency closure the parent computed conditionally (consent plugin active → jQuery/Woo
        // chain must run at parse WITH the consent manager). Preserve exactly what it decided.
        $wpc_closure = [];
        foreach (self::WOO_EAGER_CHAIN as $wpc_dep) {
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
            'ngf298gh738qwbdh0s87v_vars',
            'wpcRunningCritical',
            'wpc-ga-bot-shield',
            'wpc-presc-reserve', // A3 uniqueness guard: apply-time count, never delayed
            'wpc-icon-belt', // icon-guard settle belt: must observe fonts as they load, not on gesture
            'wpcVitals', // RUM collector — an observer released on gesture misses the load it
                         // exists to measure, and a bounce view never beacons at all
            'wpc-rum-beacon',
            'wpc-ela',
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

        ], self::wpc_consent_tokens(), [

            'form_embed', 'msgsndr', 'leadconnectorhq', 'hsforms', 'hbspt',
            'calendly', 'typeform', 'jotform',

            'ameliaShortcodeData',

            // 'SR7.' matches the inline SR7.JSON config scripts only (case-sensitive match); the
            // SR7 engine keep (sr7.js, tptools.js) lives in should_exclude_script.
            'dark-mode', 'SR7.',
            // v7.21.12 — Bricks names its dark-mode lane "dl-mode" (bricks-dl-mode handle,
            // bricks-dl-mode-js-after inline): the 'dark-mode' keep above never matched it, so
            // the theme setter delayed behind gesture — ridgeway relit light-themed on every
            // navigation and mobile stayed light until first touch. A theme-mode setter owns
            // first paint; it can never wait on a gesture.
            'dl-mode',
            'woocommerce-no-js',

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
            $this->excludes = array_values(array_diff((array) $this->excludes, array_merge(self::wpc_consent_tokens(), [
                'sourcebuster', 'gtag', 'googletag',
            ])));
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


    protected function wpc_is_fontawesome_src($src)
    {
        if (!$this->wpc_fontawesome_keep) {
            return false;
        }
        $host = strtolower((string) parse_url(html_entity_decode((string) $src), PHP_URL_HOST));
        return $host !== '' && in_array($host, self::WPC_FONTAWESOME_HOSTS, true);
    }

    /** Is $host one of OUR hosts — the site origin, a wpc CDN edge (b-cdn/zapwp), or the
     *  customer's configured CDN zone (custom CNAME / zone name / verified CF CNAME)?
     *  Scripts on our zone are rewritten LOCALS and must never be io-demoted or
     *  force-delayed as external vendors. */
    protected static function wpc_is_own_host($host)
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

    /** The consent managers' script and style names: the tokens the keep-eager list holds, the force-delay-consent opt-in releases, and the delay-pattern gate refuses (lowercased when $lower). */
    public static function wpc_consent_tokens($lower = false)
    {
        $tokens = [
            'gdpr-cookie-consent', 'cookie-law-info', 'cookieyes', 'complianz', 'cmplz',
            'cookie-notice', 'cookie-consent', 'moove_gdpr', 'osano', 'termly', 'iubenda',
            'wpl_cookie_consent', 'wpl_viewed_cookie', 'CookieConsent', 'cookiebot',
            'tarteaucitron', 'onetrust', 'quantcast', 'usercentrics', 'consently',
            'didomi', 'trustarc', 'truste.com', 'sourcepoint', 'axeptio', 'klaro', 'securiti.ai',
            'real-cookie-banner', 'devowl', 'realCookieBanner',
            'surecookie', 'ccm19', 'consentmanager.net', 'cookiefirst', 'cookiehub', 'cookie-script.com', 'cookieinformation',
        ];

        return $lower ? array_map('strtolower', $tokens) : $tokens;
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
        foreach (array_merge(['jquery', 'gdpr', 'cookie', 'borlabs', 'consent', 'wpl_viewed'], self::wpc_consent_tokens(true), [
                     'wp-i18n', 'wp-polyfill', 'wp-hooks',
                     'wp-includes/', 'sourcebuster',
                     // form-embed / booking revealers: builtin keeps whose delay collapses forms
                     'form_embed', 'msgsndr', 'leadconnector', 'hsforms', 'hbspt', 'calendly',
                     'typeform', 'jotform',
                     // WPC's own machinery + theme nav-init keeps
                     'optimize.js', 'optimizerwpc', 'wpbf', 'page-builder-framework', 'wpc-presc',
                     'revslider', 'sr7']) as $tok) {
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

    public static function wpc_force_delay_on($which, $with_cohort = true)
    {
        $const = 'WPC_FORCE_DELAY_' . strtoupper((string) $which);
        if (defined($const) && constant($const)) {
            return true;
        }
        $s = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        if (is_array($s) && !empty($s['force-delay-' . $which]) && $s['force-delay-' . $which] == '1') {
            return true;
        }
        if ($with_cohort && $which === 'jquery' && function_exists('wpc_policy_cohort') && wpc_policy_cohort('force_delay_jquery') === 1) {
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
    public static function wpc_delay_manifest_file($urlKey = '')
    {
        try {
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return '';
            }
            $base = rtrim(WPS_IC_CRITICAL, '/') . '/';
            $k = $urlKey !== '' ? (string) $urlKey : (string) (new wps_ic_url_key())->setup('');
            if ($k !== '' && @is_readable($base . $k . '/delay.json')) {
                return $base . $k . '/delay.json';
            }
            if ($urlKey === '' && method_exists('wps_ic_url_key', 'cleanRequestKey') && apply_filters('wpc_delay_manifest_clean_key', true)) {
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

    public static function wpc_measured_delay_newer_than($since, $urlKey = '')
    {
        try {
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return false;
            }
            $f = self::wpc_delay_manifest_file($urlKey);
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
                 'jet-appointments', 'jet-ab-', 'jet-apb', 'sb-youtube', 'sby-scripts',
                 // SureForms: its submit handler (srfm-form-submit, formSubmit.js) must be bound
                 // before the visitor's first click. Delayed, a cold first click on a captcha-less
                 // form posted natively to the page and reloaded it empty, and on a v3 form only
                 // the loader's replayed click delivered the submit (ticket 11945, rig 2026-10-12).
                 // srfm-frontend (frontend.min.js) rides along because formSubmit calls its
                 // window.srfm.toggleErrorState / srfmUtility: without it a cold first click on an
                 // empty required field was blocked with no error message shown (rig 2026-10-14).
                 // Both matched by handle id (plus srfm-form-submit's localized data and its deps
                 // wp-i18n, wp-hooks). Field scripts (srfm-input, dropdown, phone...) stay delayed.
                 // Not 'sureforms' (every SureForms file by src) and not 'srfm-' (also catches
                 // Google's v3 api.js handle 'srfm-google-recaptchaV3', which stays with the
                 // captcha rule).
                 'srfm-form-submit', 'srfm-frontend']);
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
    protected function wpc_excludes_lower()
    {
        return array_map('strtolower', array_map('strval', (array) $this->excludes));
    }

    protected function wpc_user_delay_excluded($x)
    {
        if ($this->wpc_sd_families !== [] && $this->wpc_safe_delay_keeps((string) $x)) {
            return true;
        }
        return is_object($this->userExcludes)
            && method_exists($this->userExcludes, 'excludedFromDelayV3')
            && $this->userExcludes->excludedFromDelayV3((string) $x);
    }

    /** True when a src URL or an inline body belongs to a first-screen widget family that safe_delay_families keeps eager on this page. */
    protected function wpc_safe_delay_keeps($x)
    {
        $url = $x !== '' && strlen($x) < 2048 && !preg_match('/\s/', $x) && preg_match('~^(?:https?:)?//|^/~i', $x);
        return self::wpc_safe_delay_owns($this->wpc_sd_families, $url ? $x : '', $url ? '' : $x) !== '';
    }

    /** True for a handle's -js-before/-js-after body that calls something while the handle's own tag will be delayed: the body waits with it. */
    protected function wpc_safe_delay_parent_delayed($attributes, $content = '')
    {
        if ($this->wpc_sd_src_tags === [] || empty($attributes['id']) || !is_string($attributes['id'])
            || !preg_match('/^(.+-js)-(?:before|after)$/', $attributes['id'], $parent) || !isset($this->wpc_sd_src_tags[$parent[1]])) {
            return false;
        }
        $calls = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|`(?:[^`\\\\]++|\\\\.)*+`/s', '""', (string) $content);
        $calls = is_string($calls) ? $calls : (string) $content;
        $calls = (string) preg_replace('/\b(?:JSON\.parse|decodeURIComponent)\s*\(/', '', $calls);
        if (!preg_match('/[\w$\]\)]\s*\(/', $calls)) {
            return false;
        }
        $id = $parent[1];
        if (isset($this->parse_time_src_ids[$id]) || isset($this->promoted_src_ids[$id]) || isset($this->companion_ids[$id])) {
            return false;
        }
        static $depth = 0;
        if ($depth > 0) {
            return false;
        }
        $depth++;
        try {
            $kept = $this->should_exclude_script($this->wpc_sd_src_tags[$id], '') || $this->should_defer_script($this->wpc_sd_src_tags[$id]);
        } finally {
            $depth--;
        }
        return !$kept;
    }

    /** Indexes the page's id-carrying src tags (the data-only keep's call guard reads them) and, under safe_delay_families, picks the widget families kept eager on this page. */
    protected function wpc_safe_delay_prepare($html)
    {
        $this->wpc_sd_families = [];
        $this->wpc_sd_src_tags = [];
        $wpc_families = function_exists('wpc_safe_delay_on') && wpc_safe_delay_on('families');
        $wpc_call_guard = !(defined('WPC_DATA_ONLY_CALL_GUARD_OFF') && WPC_DATA_ONLY_CALL_GUARD_OFF)
            && !(function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_data_only_call_guard') === 1)
            && (!function_exists('apply_filters') || apply_filters('wpc_data_only_call_guard', true) !== false);
        if (!$wpc_families && !$wpc_call_guard) {
            return;
        }
        if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', (string) $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $a = $this->parse_script_attributes($tag);
                if (!empty($a['id']) && is_string($a['id']) && !empty($a['src']) && count($this->wpc_sd_src_tags) < 400) {
                    $this->wpc_sd_src_tags[$a['id']] = $a;
                }
            }
        }
        if (!$wpc_families) {
            return;
        }
        $this->wpc_sd_families = self::wpc_kept_families((string) $html);
        $this->wpc_user_exclude_keep_handles = null;
        if ($this->wpc_sd_families !== [] && function_exists('wpc_render_belt_note')) {
            $notes = [];
            foreach ($this->wpc_sd_families as $key => $why) {
                $notes[] = $key . ':' . $why;
            }
            wpc_render_belt_note('delay-keep-rules', ['safe_delay' => implode(',', $notes)], true);
        }
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
        foreach (array_keys($this->wpc_sd_families) as $wpc_sd_key) {
            foreach (self::WPC_SAFE_DELAY_FAMILIES[$wpc_sd_key]['src'] ?? [] as $wpc_sd_pat) {
                $wpc_sd_pat = strtolower(trim((string) $wpc_sd_pat, '/'));
                if (strlen($wpc_sd_pat) > 2) { $pats[] = $wpc_sd_pat; }
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

    const WPC_OWN_SCRIPT_IDS = ['wpc-css-orb-fallback', 'wpc-icon-belt', 'wpc-ga-bot-shield', 'wpc-rum-beacon', 'wpc-ela', 'wpc-dev-check'];

    protected function should_exclude_script($attributes, $content = '')
    {


        $wpc_type = isset($attributes['type']) ? strtolower(trim((string) $attributes['type'])) : '';
        if ($wpc_type !== '' && strpos($wpc_type, 'javascript') === false) {
            return true;
        }
        if ($this->wpc_module_consumer($attributes)) {
            return true;
        }
        if (isset($attributes['id']) && is_string($attributes['id']) && in_array($attributes['id'], self::WPC_OWN_SCRIPT_IDS, true)) {
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
                if (apply_filters('wpc_force_delay_jquery', self::wpc_force_delay_on('jquery', false))) {
                    return false;
                }
                if (!empty($attributes['data-nodefer'])
                    || (!empty($attributes['data-priority']) && $attributes['data-priority'] === 'high')
                    || $this->checkKeyword(strtolower((string) $attributes['src']
                        . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id']) ? ' ' . (string) $attributes['id'] : '')), $this->wpc_excludes_lower())
                    || $this->wpc_user_delay_excluded((string) $attributes['src'])) {
                    return true;
                }
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

        if (!empty($attributes['src']) && $this->wpc_is_fontawesome_src((string) $attributes['src'])) {
            return true;
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
            if (!empty($attributes['src']) && self::wpc_is_breakdance_swiper_src((string) $attributes['src'])) {
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

        // A Slider Revolution 7 module on the first screen needs its engine at load: SR7's inline
        // config ends with `if (SR7.F.init) SR7.F.init(); else … shouldBeInited = true`, so with
        // sr7.js / tptools.js held the hero waits for the first gesture (or the 60 s timeout).
        // The 'SR7.' keep token never matched these files (case-sensitive, it only hits the
        // inline SR7.JSON config scripts). Ticket 12263 (seelennacht.de, proven offline).
        if ($this->wpc_first_screen_sr7_module && !empty($attributes['src'])
            && preg_match('#/revslider/public/js/(?:sr7\.js|libs/tptools\.js)(?:\?|$)#i', (string) $attributes['src'])) {
            return true;
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
                . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id']) ? ' ' . (string) $attributes['id'] : '')), $this->wpc_excludes_lower())
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
                . (apply_filters('wpc_keep_match_id', false) && !empty($attributes['id']) ? ' ' . (string) $attributes['id'] : '')), $this->wpc_excludes_lower())
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
                && !$this->wpc_safe_delay_parent_delayed($attributes, $content)
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

    /** The delay manifest's keep entries sorted by shape: rc (render-critical basenames), inline (16-char hashes), paths (URL paths) and names (flat basenames or ids). */
    public static function wpc_manifest_keep_index($manifest)
    {
        $index = ['rc' => [], 'inline' => [], 'paths' => [], 'names' => []];
        foreach (self::wpc_manifest_keep_entries($manifest) as $keep) {
            list($list, $entry) = $keep;
            if ($list === 'render_critical') {
                $index['rc'][strtolower(basename((string) strtok($entry, '?')))] = true;
            }
            if (strpos($entry, 'inline:') === 0) {
                $hash = substr($entry, 7, 16);
                if (strlen($hash) === 16) {
                    $index['inline'][$hash] = true;
                }
            } elseif (strpos($entry, '/') !== false) {
                $path = parse_url($entry, PHP_URL_PATH);
                if (is_string($path) && strlen($path) >= 6) {
                    $index['paths'][$path] = true;
                }
            } else {
                $index['names'][$entry] = true;
            }
        }
        return $index;
    }

    /** The first-screen script names the page's delay manifest lists (names, paths and render-critical basenames), or [] while the manifest is off, missing or unreadable. */
    public static function wpc_manifest_first_screen_names()
    {
        $offAt = (int) get_option('wpc_delay_v3_manifest_off', 0);
        if (($offAt > 0 && !self::wpc_measured_delay_newer_than($offAt)) || !apply_filters('wpc_delay_v3_manifest', true)
            || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return [];
        }
        $file = self::wpc_delay_manifest_file();
        $manifest = $file && @is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($manifest)) {
            return [];
        }
        $index = self::wpc_manifest_keep_index($manifest);
        return array_merge(array_keys($index['names']), array_keys($index['paths']), array_keys($index['rc']));
    }

    // The kept set's wp_scripts dependency closure: ids (handle + '-js') that are ON the page,
    // NOT yet kept, and reachable from any kept handle's dependency graph. Pure computation —
    // the caller stamps the three keep sets. Cap-bounded, kill wpc_keep_dep_closure.
    /**
     * The measured manifest's keep entries, as [list, entry] pairs: render_critical and
     * atf_mutators, top level and per device (the v1 envelope), every entry of each list, an
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
                foreach ($section[$list] as $entry) {
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
     * window global of that name for either kind. The pattern is the file's path from the content or
     * includes directory (wpc_script_exclude_pattern), which no other script's URL contains. Only
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
                $patterns[self::wpc_script_exclude_pattern($path)] = true;
            }
        }
        return array_keys($patterns);
    }

    /**
     * The Delay JS exclude pattern that keeps one script: its path from the site's own content or
     * includes directory (content_url(), includes_url(): /wp-content/, a renamed /app/, a
     * subdirectory install's /blog/wp-content/, /wp/wp-includes/), which no other file's URL
     * contains, or its basename for a path under neither. A basename matches every script whose
     * URL contains it (data.min.js is inside core-data.min.js, view.min.js inside preview.min.js).
     */
    public static function wpc_script_exclude_pattern($path)
    {
        $path = (string) $path;
        $markers = [];
        foreach (['content_url' => '/wp-content/', 'includes_url' => '/wp-includes/'] as $function => $default) {
            $own = function_exists($function) ? rtrim((string) parse_url((string) $function(), PHP_URL_PATH), '/') : '';
            if ($own !== '') {
                $markers[] = $own . '/';
                $markers[] = '/' . basename($own) . '/';
            }
            $markers[] = $default;
        }
        foreach (array_unique($markers) as $marker) {
            $at = strpos($path, $marker);
            if ($at !== false) {
                return substr($path, $at);
            }
        }
        return basename($path);
    }

    /** wpc_script_exclude_pattern() of a script URL as the site served it (a zone URL carrying the origin after /a: gives that origin). */
    public static function wpc_script_exclude_pattern_for_url($src)
    {
        return self::wpc_script_exclude_pattern((string) parse_url(self::wpc_script_origin_url($src), PHP_URL_PATH));
    }

    /**
     * What the Delay JS tuner keeps for a missing-name error (wpc_missing_symbol): ['as' => 'provider',
     * 'patterns' => wpc_provider_patterns()] when a script on record other than the thrower defines
     * the name; ['as' => 'thrower', 'patterns' => [the thrower's path pattern]] when no script on disk
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
        $thrower = self::wpc_script_exclude_pattern_for_url($thrower_url);
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
     * why one was skipped (cap, shared-basename); for the whole site the reason no
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
                'theme-scripts-library-search-menu',
                'script-library-frontend-global-functions',
                'script-library-global-functions',
                'script-library-frontend-scripts',
                'script-library-gallery',
                'script-library-ext-waypoint',
                'script-library-menu',
                'script-library-animation',
                'script-library-multi-view',
                'script-library-link',
                'module-script-',
                'module-library-script-',
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

        if (function_exists('wpc_delay_host_rule_on') && wpc_delay_host_rule_on()) {
            $hostKeeps = self::wpc_host_rule_keeps();
            $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, $hostKeeps)));
            if ($noteReceipts && function_exists('wpc_render_belt_note')) {
                $hostKept = 0;
                foreach ($hostKeeps as $hostKeep) {
                    if (strpos($html, $hostKeep) !== false) {
                        $hostKept++;
                    }
                }
                if ($hostKept > 0) {
                    wpc_render_belt_note('delay-keep-rules', ['host_rule' => $hostKept], true);
                }
            }
        }

    }

    public static function wpc_host_rule_keeps()
    {
        $keeps = [];
        foreach (array_unique(array_filter([function_exists('get_template') ? (string) get_template() : '', function_exists('get_stylesheet') ? (string) get_stylesheet() : ''])) as $themeSlug) {
            $keeps[] = '/themes/' . $themeSlug . '/';
        }
        $keeps = array_merge($keeps, [
            '/plugins/elementor/assets/js/',
            '/plugins/elementor-pro/assets/js/',
            '/plugins/divi-builder/',
            '/themes/Divi/',
            '/plugins/beaver-builder-lite-version/js/',
            '/plugins/bb-plugin/js/',
            '/uploads/bb-plugin/cache/',
            '/themes/bricks/assets/js/',
            '/plugins/oxygen/component-framework/',
        ]);
        return array_values(array_unique(array_map('strval', (array) apply_filters('wpc_delay_host_rule_keeps', $keeps))));
    }

    /**
     * Whether this render keeps a script at load rather than holding it in the delay registry,
     * answered from the delay's own keep inputs: its keep list (defaults and the builder runtime
     * keeps), the site's Delay JS exclusions, and the page's measured manifest. The facade asks
     * it before it holds a builder video's source for the replay (Divi 4 on webdesign4u.com.au):
     * a script this answers true for runs at load, and the source must stay.
     */
    const WPC_BREAKDANCE_SLIDER_ID = 'bde-(?:advancedslider|basicslider|gallery)-[0-9]+(?:-[0-9]+)*';
    const WPC_BREAKDANCE_SWIPER_SRC = '#/breakdance-elements/dependencies-files/(?:swiper@\d+/swiper-bundle(?:\.min)?\.js|breakdance-swiper/breakdance-swiper\.js)#i';

    public static function wpc_is_breakdance_swiper_src($src)
    {
        return preg_match(self::WPC_BREAKDANCE_SWIPER_SRC, (string) $src) === 1;
    }

    const WPC_SAFE_DELAY_FAMILIES = [
        'smart_slider_3' => ['manifest' => true, 'inline_max' => 65536, 'src' => ['/smart-slider-3/'], 'inline' => ['_N2'],
            'marker' => '/class="[^"]*\bn2-section-smartslider\b/'],
        'metaslider' => ['manifest' => true, 'inline_max' => 16384, 'src' => ['/ml-slider/assets/'], 'inline' => ['metaslider_'],
            'marker' => '/\bid="metaslider_\d+"/'],
        'kadence_blocks' => ['manifest' => false, 'inline_max' => 0, 'inline' => [], 'src' => ['/kadence-blocks/'],
            'marker' => '/class="[^"]*\b(?:kb-form|wp-block-kadence-(?:tabs|accordion|form|advanced-form))\b/'],
        'woo_blocks' => ['manifest' => false, 'inline_max' => 0, 'inline' => [], 'src' => ['/woocommerce/assets/client/blocks/'],
            'marker' => '/class="[^"]*\bwp-block-woocommerce-/'],
    ];

    /** True when a script URL contains one of the family's source patterns. */
    public static function wpc_safe_delay_src_in(array $family, $src)
    {
        $src = strtolower(html_entity_decode((string) $src));
        if ($src === '') {
            return false;
        }
        foreach ($family['src'] as $pattern) {
            if (strpos($src, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Body markup without script, style, template and noscript bodies: the order the first-screen share is measured on. */
    public static function wpc_safe_delay_visible_body($html)
    {
        $html = (string) $html;
        $body = self::wpc_real_body_offset($html);
        if ($body === false) {
            return '';
        }
        return (string) preg_replace('~<(script|style|template|noscript)\b[^>]*>.*?</\1\s*>~is', '', substr($html, $body, 3145728));
    }

    /** A field slider entry speaks for a widget only when its selector is not html, body or a body class and its height is at most 2500 px. */
    public static function wpc_safe_delay_entry_usable(array $entry, array $bodyClasses)
    {
        $sel = trim((string) ($entry['sel'] ?? ''));
        if ($sel === '' || preg_match('/^(?:html|body)\b/i', $sel)) {
            return false;
        }
        if (preg_match('/^\.([\w-]+)$/', $sel, $one) && in_array($one[1], $bodyClasses, true)) {
            return false;
        }
        return (isset($entry['height']) ? (float) $entry['height'] : 0.0) <= 2500.0;
    }

    /**
     * Which widget families stay eager on this page: [family => field|manifest|page].
     * $entries is the measured slider list for this device (null when unmeasured), $atfNames the service's named first-screen files.
     */
    public static function wpc_safe_delay_pick($html, $entries, array $atfNames)
    {
        $html = (string) $html;
        $out = [];
        $srcs = [];
        if (preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $found)) {
            $srcs = array_map('html_entity_decode', $found[2]);
        }
        $bodyClasses = [];
        if (preg_match('/<body\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $bc)) {
            $bodyClasses = preg_split('/\s+/', trim($bc[2]));
        }
        $usable = [];
        $unusable = 0;
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && self::wpc_safe_delay_entry_usable($entry, $bodyClasses)) {
                $usable[] = $entry;
            } else {
                $unusable++;
            }
        }
        $names = [];
        $paths = [];
        foreach ($atfNames as $name) {
            $name = (string) $name;
            if (strpos($name, '/') !== false) {
                $paths[strtolower((string) parse_url($name, PHP_URL_PATH))] = true;
            }
            $names[strtolower(basename((string) strtok($name, '?')))] = true;
        }
        $baseCount = [];
        foreach ($srcs as $src) {
            $base = strtolower(basename((string) parse_url($src, PHP_URL_PATH)));
            $baseCount[$base] = ($baseCount[$base] ?? 0) + 1;
        }
        $visible = null;
        foreach (self::WPC_SAFE_DELAY_FAMILIES as $key => $family) {
            $present = false;
            foreach ($srcs as $src) {
                if (self::wpc_safe_delay_src_in($family, $src)) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                continue;
            }
            foreach ($usable as $entry) {
                foreach ((array) ($entry['binders'] ?? []) as $binder) {
                    if (is_array($binder) && self::wpc_safe_delay_src_in($family, (string) ($binder['url'] ?? ''))) {
                        $out[$key] = 'field';
                        continue 3;
                    }
                }
            }
            if ($family['manifest']) {
                foreach ($srcs as $src) {
                    $path = strtolower((string) parse_url($src, PHP_URL_PATH));
                    $base = basename($path);
                    if (self::wpc_safe_delay_src_in($family, $src)
                        && (isset($paths[$path]) || (isset($names[$base]) && ($baseCount[$base] ?? 0) === 1))) {
                        $out[$key] = 'manifest';
                        continue 2;
                    }
                }
            }
            if ($usable !== [] || (is_array($entries) && $unusable === 0)) {
                continue;
            }
            if ($visible === null) {
                $visible = self::wpc_safe_delay_visible_body($html);
            }
            if ($visible !== '' && preg_match($family['marker'], $visible, $m, PREG_OFFSET_CAPTURE)
                && $m[0][1] < 0.15 * strlen($visible) && $m[0][1] < 120000) {
                $out[$key] = 'page';
            }
        }
        return $out;
    }

    /** The active family a src URL or an inline body belongs to, or '' when none. */
    public static function wpc_safe_delay_owns(array $active, $src, $content)
    {
        foreach (array_keys($active) as $key) {
            if (!isset(self::WPC_SAFE_DELAY_FAMILIES[$key])) {
                continue;
            }
            $family = self::WPC_SAFE_DELAY_FAMILIES[$key];
            if ((string) $src !== '' && self::wpc_safe_delay_src_in($family, $src)) {
                return $key;
            }
            $content = (string) $content;
            if ($content !== '' && $family['inline'] !== [] && strlen($content) <= $family['inline_max']) {
                foreach ($family['inline'] as $token) {
                    if (strpos($content, $token) !== false) {
                        return $key;
                    }
                }
            }
        }
        return '';
    }

    /** The widget families cohort safe_delay_families keeps eager on this render, [family => field|manifest|page]: the one answer the script delay and the stylesheet parking both ask. */
    public static function wpc_kept_families($html)
    {
        if (!function_exists('wpc_safe_delay_on') || !wpc_safe_delay_on('families')) {
            return [];
        }
        return self::wpc_safe_delay_pick((string) $html, self::wpc_first_screen_slider_entries(), self::wpc_manifest_first_screen_names());
    }

    /** The kept family a stylesheet href belongs to by the family's source stems, or ''. */
    public static function wpc_safe_delay_owns_sheet(array $active, $href)
    {
        foreach (array_keys($active) as $key) {
            if (isset(self::WPC_SAFE_DELAY_FAMILIES[$key]) && self::wpc_safe_delay_src_in(self::WPC_SAFE_DELAY_FAMILIES[$key], $href)) {
                return $key;
            }
        }
        return '';
    }

    /**
     * The slider entries of the service's measurement of this page's first screen for the request's device
     * (lcp.json atf_interactive, read by wps_ic_atf_observation::interactive): a list, empty when the
     * measurement found no slider. Null when the measurement does not speak for this page's sliders: no
     * field, a leg the service did not measure, a field it could not read, or the home page's answer
     * standing in for a page that has no lcp.json of its own.
     */
    public static function wpc_first_screen_slider_entries()
    {
        if (!class_exists('wps_ic_atf_observation')) {
            return null;
        }
        $answer = wps_ic_atf_observation::interactive(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile() ? 'mobile' : 'desktop');
        if (!is_array($answer) || ($answer['state'] ?? '') !== 'measured' || !empty($answer['home'])) {
            return null;
        }
        $sliders = [];
        foreach ((array) $answer['entries'] as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? '') === 'slider') {
                $sliders[] = $entry;
            }
        }
        return $sliders;
    }

    /**
     * Ids of the Breakdance sliders the service's slider entries name, as far as the page carries them: an id
     * in an entry's selector, or, for an entry whose selector names none but whose binders include Breakdance's
     * swiper library or runtime, every Breakdance slider id on the page.
     */
    public static function wpc_breakdance_measured_sliders($html, array $sliders)
    {
        $html = (string) $html;
        $named = [];
        $unnamed = false;
        foreach ($sliders as $slider) {
            if (!is_array($slider)) {
                continue;
            }
            if (preg_match_all('/(?<![A-Za-z0-9_-])(' . self::WPC_BREAKDANCE_SLIDER_ID . ')(?![A-Za-z0-9_-])/i', (string) ($slider['sel'] ?? ''), $found)) {
                foreach ($found[1] as $id) {
                    $named[$id] = 1;
                }
                continue;
            }
            foreach ((array) ($slider['binders'] ?? []) as $binder) {
                if (is_array($binder) && self::wpc_is_breakdance_swiper_src((string) ($binder['url'] ?? ''))) {
                    $unnamed = true;
                    break;
                }
            }
        }
        if ($unnamed && preg_match_all('/class="[^"]*\b(' . self::WPC_BREAKDANCE_SLIDER_ID . ')\b/i', $html, $onPage)) {
            foreach ($onPage[1] as $id) {
                $named[$id] = 1;
            }
        }
        $ids = [];
        foreach (array_keys($named) as $id) {
            if (strpos($html, (string) $id) !== false) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    /**
     * Ids of the Breakdance sliders (advanced slider, basic slider, gallery) on the page's first screen:
     * the ones the service's measurement names (wpc_first_screen_slider_entries), none when it names none;
     * without a measurement, the ones whose markup sits in the page's first two <section> blocks after <body>.
     */
    public static function wpc_breakdance_first_screen_sliders($html)
    {
        if (!is_string($html) || strpos($html, 'BreakdanceSwiper') === false) {
            return [];
        }
        $measured = self::wpc_first_screen_slider_entries();
        if ($measured !== null) {
            return self::wpc_breakdance_measured_sliders($html, $measured);
        }
        $body = self::wpc_real_body_offset($html);
        if ($body === false) {
            return [];
        }
        $end = strlen($html);
        $sections = self::wpc_first_screen_markup_offsets($html, '/<section(?![\w:.-])/i', $body);
        if (count($sections) >= 3) {
            $end = $sections[2];
        }
        $ids = [];
        if (preg_match_all('/class="[^"]*\b(bde-(?:advancedslider|basicslider|gallery)-[0-9]+(?:-[0-9]+)*)\b/i', substr($html, $body, $end - $body), $found)) {
            foreach ($found[1] as $id) {
                $ids[$id] = 1;
            }
        }
        return array_keys($ids);
    }

    /**
     * True when a Slider Revolution 7 module (<sr7-module>) sits on the first screen: between
     * <body and the third <section after it, or anywhere in the body on a page with fewer than
     * three sections (the window wpc_breakdance_first_screen_sliders uses). Such a module needs
     * the SR7 engine at load; see should_exclude_script. A section is a real <section> tag and a
     * module is a real <sr7-module> tag or its key; text inside script, style, template or a
     * comment counts for neither.
     *
     * Inside a render the module is not in the buffer when the delay pass runs: the
     * slider_module_stash stage (cdn-rewrite.php) has parked it under a ___WPCSR7_MODULE_N___
     * key at the same spot until slider_module_restore, so the key counts as the module.
     */
    public static function wpc_sr7_first_screen_module($html)
    {
        if (!is_string($html) || (stripos($html, 'sr7-module') === false && strpos($html, '___WPCSR7_MODULE_') === false)) {
            return false;
        }
        return self::wpc_first_screen_markup_has($html, '/<sr7-module(?![\w:.-])|___WPCSR7_MODULE_\d+___/i');
    }

    /** True when the first match of $pattern outside inert markup lies between the page's <body and its third real <section (the whole body when it has fewer than three). */
    public static function wpc_first_screen_markup_has($html, $pattern)
    {
        $html = (string) $html;
        $body = self::wpc_real_body_offset($html);
        if ($body === false) {
            return false;
        }
        $hits = self::wpc_first_screen_markup_offsets($html, $pattern, $body);
        if ($hits === []) {
            return false;
        }
        $end = strlen($html);
        $sections = self::wpc_first_screen_markup_offsets($html, '/<section(?![\w:.-])/i', $body);
        if (count($sections) >= 3) {
            $end = $sections[2];
        }
        return $hits[0] < $end;
    }

    /** Where the inert markup that opens at $start ends: a comment at its closer, script, style and textarea at their first closing tag, a template at the closing tag that balances its own opener. */
    private static function wpc_inert_stop($html, $start, $open)
    {
        $length = strlen($html);
        if ($open === '<!--') {
            $close = strpos($html, '-->', $start + 4);
            return $close === false ? $length : $close + 3;
        }
        $name = strtolower($open);
        if ($name !== 'template') {
            $close = stripos($html, '</' . $name, $start);
            return $close === false ? $length : $close;
        }
        $depth = 1;
        $at = $start + 9;
        while (preg_match('~<template(?![\w:.-])|</template(?![\w:.-])~i', $html, $hit, PREG_OFFSET_CAPTURE, $at)) {
            if ($hit[0][0][1] === '/') {
                $depth--;
                if ($depth === 0) {
                    return $hit[0][1];
                }
            } else {
                $depth++;
            }
            $at = $hit[0][1] + 1;
        }
        return $length;
    }

    /** The offset of the page's own <body> tag: the first one that is not text inside a script, style, template, textarea or comment; false when there is none. */
    public static function wpc_real_body_offset($html)
    {
        $html = (string) $html;
        $at = 0;
        while (preg_match('/<!--|<(script|style|template|textarea)(?![\w:.-])|<body(?![\w:.-])/i', $html, $open, PREG_OFFSET_CAPTURE, $at)) {
            $start = $open[0][1];
            if (!isset($open[1]) && $open[0][0] !== '<!--') {
                return $start;
            }
            $at = max(self::wpc_inert_stop($html, $start, isset($open[1]) ? $open[1][0] : '<!--'), $start + 1);
        }
        return false;
    }

    private static function wpc_first_screen_markup_offsets($html, $pattern, $from)
    {
        $inert = [];
        $at = $from;
        while (preg_match('/<!--|<(script|style|template|textarea)(?![\w:.-])/i', $html, $open, PREG_OFFSET_CAPTURE, $at)) {
            $start = $open[0][1];
            $stop = self::wpc_inert_stop($html, $start, $open[0][0] === '<!--' ? '<!--' : $open[1][0]);
            $inert[] = [$start, $stop];
            $at = max($stop, $start + 1);
        }
        $offsets = [];
        if (!preg_match_all($pattern, $html, $found, PREG_OFFSET_CAPTURE, $from)) {
            return $offsets;
        }
        foreach ($found[0] as $hit) {
            foreach ($inert as $range) {
                if ($hit[1] >= $range[0] && $hit[1] < $range[1]) {
                    continue 2;
                }
            }
            $offsets[] = $hit[1];
        }
        return $offsets;
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

    /** The addresses of the embeds the loader holds until they are near the screen: one list for the loader
     *  config (heavyEmbeds) and for the render pass that parks an <embed> or <object> on it. */
    public static function wpc_heavy_embeds()
    {
        $embeds = [
            'google.com/maps', 'maps.google.', 'maps.googleapis.',
            'leadconnectorhq.com', 'msgsndr.com', 'filesafe.space',
            '/widget/booking/', '/widget/form/', '/widget/quiz/', '/widget/survey/',
            'player.vimeo.com', 'youtube.com/embed/', 'youtube-nocookie.com/embed/',
            'fast.wistia.',
            'iframe.mediadelivery.net',
            'youtube.com/iframe_api', 'youtube.com/player_api', 'player.vimeo.com/api/player.js',
            'fast.wistia.com/assets/external/', 'fast.wistia.net/assets/external/',
        ];
        if (function_exists('wpc_delay_host_rule_on') && wpc_delay_host_rule_on()) {
            $embeds = array_merge($embeds, [
                'platform.twitter.com/widgets.js', 'platform.x.com/widgets.js',
                'connect.facebook.net/',
                'instagram.com/embed.js',
                'tiktok.com/embed.js',
                'assets.pinterest.com/js/pinit.js',
            ]);
        }
        return array_values(apply_filters('wpc_delay_heavy_embeds', $embeds));
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
    protected function wpc_keep_dependency_closure($wpc_excluded_ids, $wpc_seen_src_ids, $html = '', $note = true)
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
                    if ($note) {
                        self::wpc_provider_index_note($delayed);
                    }
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
        if ($note && !empty($pulled) && function_exists('wpc_render_belt_note')) {
            $pairs = [];
            foreach (array_slice($pulled, 0, 6, true) as $provider_id => $why) {
                $pairs[] = $why['name'] . ':' . preg_replace('/-js$/', '', (string) $provider_id) . '<' . preg_replace('/-js$/', '', (string) $why['by']);
            }
            wpc_render_belt_note('delay-keep-rules', ['plugin_providers' => count($pulled), 'plugin_pairs' => implode(',', $pairs)], true);
        }
        return array_keys($closure_ids);
    }

    /**
     * Which delayed scripts bind the sliders and menu toggles the service measured on this page's first
     * screen (`atf_interactive`, lever menu_early), for the loader to run once the first paint has cleared
     * instead of at the first input.
     *
     * Each binder the service names is matched to the page's own scripts in this order: its <script id>,
     * then its path (host and query dropped, so a zone host, a CDN rewrite and a version string do not
     * matter), then its basename when exactly one path on the page carries it (the guard the measured
     * manifest reader uses, wpc_script_basename_paths). Nothing matched, or a match that is not one script,
     * leaves the element to the first tap. Returns [element => state] in the order the service gave them;
     * `regs` of an early element are the registry positions its binders matched, and `matched` counts the
     * elements that will run early.
     *
     * $registry is the delay registry as the engine built it, $pageScripts every `<script src>` tag of the
     * page as [id, src], $bytesCap the sum of binder bytes above which an element is left to the tap. A binder
     * whose bytes are 0 or absent has an unknown weight, not none: its element is left to the tap too.
     *
     * An element nobody could attribute to a script that has an address (attrib none, no binders, or binders
     * that name no script of the page) is matched to a delayed inline script instead (wpc_menu_early_inline_match):
     * the one inline script that names the element's class or id in a string and binds an event, or the one the
     * service's content hash (a binder's `hash`) names. Its `by` is ['inline'] and its element carries inline true.
     */
    public static function wpc_menu_early_match(array $registry, array $entries, array $pageScripts, $bytesCap)
    {
        $rows = [];
        $regIds = [];
        $regPaths = [];
        foreach ($registry as $position => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $src = '';
            if (!empty($entry['src']) && is_string($entry['src'])) {
                $src = !empty($entry['encoded']) ? (string) base64_decode($entry['src'], true) : $entry['src'];
            }
            $id = (isset($entry['attributes']['id']) && is_string($entry['attributes']['id'])) ? $entry['attributes']['id'] : '';
            $path = $src !== '' ? self::wpc_script_url_path($src) : '';
            $rows[] = ['reg' => $position, 'id' => $id, 'path' => $path];
            if ($id !== '') {
                $regIds[$id] = true;
            }
            if ($path !== '') {
                $regPaths[$path] = true;
            }
        }
        $basenames = [];
        foreach ($rows as $row) {
            if ($row['path'] !== '') {
                $basenames[strtolower(basename($row['path']))][$row['path']] = true;
            }
        }
        foreach ($pageScripts as $script) {
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $path = self::wpc_script_url_path(isset($script['src']) ? (string) $script['src'] : '');
            if ($path !== '') {
                $basenames[strtolower(basename($path))][$path] = true;
            }
            $inRegistry = $id !== '' ? isset($regIds[$id]) : ($path !== '' && isset($regPaths[$path]));
            if (!$inRegistry) {
                $rows[] = ['reg' => null, 'id' => $id, 'path' => $path];
            }
        }
        $pick = function ($candidates, $path) {
            if (count($candidates) > 1 && $path !== '') {
                $candidates = array_values(array_filter($candidates, function ($row) use ($path) {
                    return $row['path'] === $path;
                }));
            }
            return $candidates;
        };
        $resolve = function ($binder) use ($rows, $basenames, $pick) {
            $id = (string) $binder['id'];
            $path = self::wpc_script_url_path($binder['url']);
            if ($id !== '') {
                $hit = $pick(array_values(array_filter($rows, function ($row) use ($id) {
                    return $row['id'] === $id;
                })), $path);
                if ($hit !== []) {
                    return count($hit) === 1 ? ['row' => $hit[0], 'by' => 'id'] : ['row' => null, 'by' => 'ambiguous'];
                }
            }
            if ($path !== '') {
                $hit = array_values(array_filter($rows, function ($row) use ($path) {
                    return $row['path'] === $path;
                }));
                if ($hit !== []) {
                    return count($hit) === 1 ? ['row' => $hit[0], 'by' => 'path'] : ['row' => null, 'by' => 'ambiguous'];
                }
                $base = strtolower(basename($path));
                if ($base !== '' && isset($basenames[$base])) {
                    if (count($basenames[$base]) !== 1) {
                        return ['row' => null, 'by' => 'shared-basename'];
                    }
                    $only = array_key_first($basenames[$base]);
                    $hit = array_values(array_filter($rows, function ($row) use ($only) {
                        return $row['path'] === $only;
                    }));
                    if ($hit !== []) {
                        return count($hit) === 1 ? ['row' => $hit[0], 'by' => 'basename'] : ['row' => null, 'by' => 'ambiguous'];
                    }
                }
            }
            return ['row' => null, 'by' => 'none'];
        };
        $out = ['elements' => [], 'matched' => 0, 'entries' => count($entries)];
        foreach ($entries as $entry) {
            $element = ['kind' => (string) $entry['kind'], 'sel' => (string) $entry['sel'], 'state' => 'early', 'regs' => [], 'by' => []];
            $bytes = 0;
            $unweighed = false;
            foreach ((array) $entry['binders'] as $binder) {
                $weight = is_array($binder) && isset($binder['bytes']) ? (int) $binder['bytes'] : 0;
                $unweighed = $unweighed || $weight <= 0;
                $bytes += $weight;
            }
            if ($entry['attrib'] === 'none') {
                $element['state'] = 'attrib-none';
            } elseif (empty($entry['binders'])) {
                $element['state'] = 'no-binders';
            } elseif ($bytes > (int) $bytesCap) {
                $element['state'] = 'heavy';
            } elseif ($unweighed) {
                $element['state'] = 'bytes-unknown';
            } else {
                $native = 0;
                foreach ($entry['binders'] as $binder) {
                    $found = $resolve($binder);
                    $element['by'][] = $found['by'];
                    if ($found['row'] === null) {
                        $element['state'] = 'unmatched';
                        break;
                    }
                    if ($found['row']['reg'] === null) {
                        $native++;
                    } elseif (!empty($registry[$found['row']['reg']]['io'])) {
                        $element['state'] = 'io';
                        break;
                    } else {
                        $element['regs'][$found['row']['reg']] = true;
                    }
                }
                if ($element['state'] === 'early' && $element['regs'] === []) {
                    $element['state'] = $native > 0 ? 'native' : 'unmatched';
                }
                $element['regs'] = $element['state'] === 'early' ? array_keys($element['regs']) : [];
            }
            if (in_array($element['state'], ['attrib-none', 'no-binders', 'unmatched'], true)
                && ($element['state'] !== 'unmatched' || array_diff($element['by'], ['none']) === [])) {
                $inline = self::wpc_menu_early_inline_match($registry, $entry, $bytesCap);
                if ($inline['state'] === 'early') {
                    $element['state'] = 'early';
                    $element['regs'] = [$inline['reg']];
                    $element['by'] = ['inline'];
                    $element['inline'] = true;
                } elseif ($inline['state'] !== 'none') {
                    $element['state'] = $inline['state'];
                }
            }
            if ($element['state'] === 'early') {
                $out['matched']++;
            }
            $out['elements'][] = $element;
        }
        return $out;
    }

    /**
     * The delayed inline script that binds one element the service measured but could not attribute to a script
     * with an address. Candidates are the registry's classic inline entries only. With a content hash on a binder
     * (`hash`: hex md5, sha1 or sha256 of the script text, as served or trimmed) the script is the one whose text
     * hashes to it, and none when no script does. Without one, and only for a menu toggle (a slider is bound by a
     * hash alone: its container's class is named in too many scripts that do not bind it), the script is the one
     * that binds a class or id of the element's last compound selector: the word is the selector of a delegated
     * handler (.on('click', 'SEL', f), .delegate('SEL', 'click', f)) or the argument of $('SEL'), find, closest,
     * querySelector, querySelectorAll or getElementById that the same expression binds an event on
     * (wpc_menu_early_binds_selector). Two scripts, or a bundle past $bytesCap, name no script: the state says why
     * ('inline-ambiguous', 'heavy', 'io'). 'none' when nothing matched, which leaves the element's own state.
     */
    protected static function wpc_menu_early_inline_match(array $registry, array $entry, $bytesCap)
    {
        $hashes = [];
        foreach ((array) $entry['binders'] as $binder) {
            if (is_array($binder) && !empty($binder['hash']) && is_string($binder['hash'])) {
                $hashes[] = strtolower($binder['hash']);
            }
        }
        $tokens = ($hashes === [] && (string) $entry['kind'] === 'menu_toggle') ? self::wpc_menu_early_selector_tokens((string) $entry['sel']) : [];
        if ($hashes === [] && $tokens === []) {
            return ['state' => 'none'];
        }
        $hits = [];
        foreach ($registry as $position => $row) {
            if (!is_array($row) || !empty($row['src']) || !in_array(strtolower((string) (isset($row['type']) ? $row['type'] : '')), ['', 'text/javascript', 'application/javascript'], true)) {
                continue;
            }
            $code = self::wpc_menu_early_inline_code($row);
            if ($code === '') {
                continue;
            }
            if ($hashes !== [] ? self::wpc_menu_early_code_hashed($code, $hashes) : self::wpc_menu_early_binds_selector($code, $tokens)) {
                $hits[$position] = $row;
            }
        }
        if ($hits === []) {
            return ['state' => 'none'];
        }
        if (count($hits) !== 1) {
            return ['state' => 'inline-ambiguous'];
        }
        $position = array_keys($hits)[0];
        if (!empty($hits[$position]['io'])) {
            return ['state' => 'io'];
        }
        if (strlen(self::wpc_menu_early_inline_code($hits[$position])) > (int) $bytesCap) {
            return ['state' => 'heavy'];
        }
        return ['state' => 'early', 'reg' => $position];
    }

    /** The classes and ids (at least three characters, at most four) of a selector's last compound, without the dot or the hash. */
    public static function wpc_menu_early_selector_tokens($selector)
    {
        $last = '';
        foreach (array_reverse((array) preg_split('/\s*[>+~]\s*|\s+/', trim((string) $selector))) as $compound) {
            if ($compound !== '') {
                $last = $compound;
                break;
            }
        }
        $last = (string) preg_replace('/\[[^\]]*\]|:{1,2}[\w-]+(?:\([^)]*\))?/', '', $last);
        if (!preg_match_all('/[.#]([A-Za-z_][\w-]*)(?![\w\\\\-])/', $last, $found)) {
            return [];
        }
        return array_slice(array_values(array_filter(array_unique($found[1]), function ($token) {
            return strlen($token) >= 3;
        })), 0, 4);
    }

    /**
     * Whether an inline script binds an event on a selector that holds one of $tokens as a whole word: the selector of a
     * delegated handler (.on('click', 'SEL', f), .one, .delegate('SEL', 'click', f)), or the string argument of $('SEL'), jQuery,
     * .find, .closest, .querySelector, .querySelectorAll or .getElementById that the same expression goes on to bind
     * (.on .one .bind .click .dblclick .hover .live .touchstart .touchend .mousedown .addEventListener, .onclick =, or .each and
     * .forEach whose callback binds an event on its element, wpc_menu_early_loop_binds). A word that is only held in a string, or is the
     * selector of a call that binds nothing (addClass, css, is) or of a loop whose callback binds nothing on its element, or sits in a
     * variable the binding names, is not it.
     */
    public static function wpc_menu_early_binds_selector($code, array $tokens)
    {
        $code = (string) $code;
        if ($tokens === []) {
            return false;
        }
        $string = '"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|`(?:[^`\\\\]|\\\\.)*`';
        $bare = preg_replace_callback('~(' . $string . ')|/\*.*?\*/|//[^\n]*~s', function ($found) {
            return isset($found[1]) && $found[1] !== '' ? $found[1] : '';
        }, $code);
        if (!is_string($bare)) {
            return false;
        }
        $selectors = [];
        $rules = [
            '/\.\s*(?:on|one)\s*\(\s*(?:' . $string . ')\s*,\s*(' . $string . ')/',
            '/\.\s*delegate\s*\(\s*(' . $string . ')\s*,\s*(?:' . $string . ')/',
            '/(?:(?<![\w$.])(?:jQuery|\$)|\.\s*(?:find|closest|querySelectorAll|querySelector|getElementById))\s*\(\s*(' . $string . ')\s*\)\s*\.\s*(?:on|one|bind|click|dblclick|hover|live|touchstart|touchend|mousedown|addEventListener|onclick\s*=)/',
        ];
        foreach ($rules as $rule) {
            if (preg_match_all($rule, $bare, $found)) {
                $selectors = array_merge($selectors, $found[1]);
            }
        }
        if (preg_match_all('/(?:(?<![\w$.])(?:jQuery|\$)|\.\s*(?:find|closest|querySelectorAll))\s*\(\s*(' . $string . ')\s*\)\s*\.\s*(each|forEach)\s*\(/', $bare, $loops, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($loops as $loop) {
                $args = self::wpc_menu_early_call_args($bare, $loop[0][1] + strlen($loop[0][0]) - 1);
                if ($args !== null && self::wpc_menu_early_loop_binds($args, $loop[2][0])) {
                    $selectors[] = $loop[1][0];
                }
            }
        }
        foreach ($selectors as $quoted) {
            $text = substr((string) $quoted, 1, -1);
            foreach ($tokens as $token) {
                if ($text !== '' && preg_match('/(?<![\w-])' . preg_quote($token, '/') . '(?![\w-])/', $text)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The text between the parenthesis of a call that opens at $open and the one that closes it (strings skipped), or null when it never closes. */
    protected static function wpc_menu_early_call_args($text, $open)
    {
        $depth = 0;
        for ($i = (int) $open, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '"' || $char === "'" || $char === '`') {
                for ($i++; $i < $length && $text[$i] !== $char; $i++) {
                    $i += $text[$i] === '\\' ? 1 : 0;
                }
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, (int) $open + 1, $i - (int) $open - 1);
                }
            }
        }
        return null;
    }

    /**
     * Whether the callback of an .each or .forEach binds an event on the element it is called for: `this`, `$(this)` or the
     * element parameter (the second of .each, the first of .forEach), or a variable the callback sets to one of them, takes
     * .on, .one, .bind, .delegate, .live, .click, .hover or .addEventListener, or has .onclick assigned.
     */
    protected static function wpc_menu_early_loop_binds($args, $method)
    {
        $name = '[A-Za-z_$][\w$]*';
        if (!preg_match('/^\s*(?:function\s*(?:' . $name . ')?\s*\(([^)]*)\)\s*\{|\(([^()]*)\)\s*=>|(' . $name . ')\s*=>)/', (string) $args, $head, PREG_UNMATCHED_AS_NULL)) {
            return false;
        }
        $params = array_map('trim', explode(',', (string) ($head[1] ?? $head[2] ?? $head[3])));
        $element = $method === 'each' ? (isset($params[1]) ? $params[1] : '') : $params[0];
        $refs = $method === 'each' ? ['this'] : [];
        if (preg_match('/^' . $name . '$/', $element)) {
            $refs[] = $element;
        }
        if ($refs === []) {
            return false;
        }
        $quote = function (array $names) {
            return '(?<![\w$.])(?:' . implode('|', array_map(function ($one) {
                return preg_quote($one, '/');
            }, $names)) . ')(?![\w$])';
        };
        $wrapped = '(?:jQuery|\$)\s*\(\s*' . $quote($refs) . '\s*\)';
        if (preg_match_all('/(?<![\w$.])(?:var|let|const)\s+(' . $name . ')\s*=\s*' . $wrapped . '/', (string) $args, $aliases)) {
            $refs = array_merge($refs, $aliases[1]);
        }
        return preg_match('/(?:' . $wrapped . '|' . $quote($refs) . ')\s*\.\s*(?:(?:on|one|bind|delegate|live|click|hover|addEventListener)\s*\(|onclick\s*=(?!=))/', (string) $args) === 1;
    }

    /** Whether the md5, sha1 or sha256 (by the hash's length) of the script text, as it stands or trimmed, is one of $hashes. */
    public static function wpc_menu_early_code_hashed($code, array $hashes)
    {
        foreach ($hashes as $hash) {
            $algo = [32 => 'md5', 40 => 'sha1', 64 => 'sha256'];
            if (!isset($algo[strlen($hash)])) {
                continue;
            }
            foreach ([(string) $code, trim((string) $code)] as $text) {
                if (hash($algo[strlen($hash)], $text) === $hash) {
                    return true;
                }
            }
        }
        return false;
    }

    /** An inline registry entry's script text; '' for one with an address. */
    protected static function wpc_menu_early_inline_code(array $entry)
    {
        if (!empty($entry['src'])) {
            return '';
        }
        $body = isset($entry['content']) && is_string($entry['content']) ? $entry['content'] : '';
        return !empty($entry['encoded']) ? (string) base64_decode($body, true) : $body;
    }

    /**
     * The registry positions an early element runs: its binders, the scripts they depend on (the page's
     * wp_scripts edges and the same-site providers of the plugins they call, as for any kept script), and
     * each of those handles' inline companions (-js-before, -js-extra, -js-after, -js-translations), in
     * document order, plus the delayed inline scripts without a handle that stand before the last binder
     * (wpc_menu_early_inline_gap). A string instead of the set when the element must be left whole to the
     * first tap: 'io' (a script it needs is held for the first interaction), 'inline' (a delayed inline script
     * before a binder may define something the binder reads and cannot be shown not to), 'closure' (what comes
     * with the binders weighs more than the cap, or a script of it cannot be weighed). For a binder matched by
     * its selector ($inline: an inline script no address names) the binder itself is read (wpc_menu_early_inline_reads):
     * one that reads a name another script could define is 'inline-reads'; one that does not needs no inline script
     * before it, and brings jQuery's files when it uses jQuery. With $closed (the idle lane) a delayed inline script before
     * the last binder that cannot be analysed stays behind when it writes no name a later script could read
     * (wpc_idle_release_closed), where otherwise it leaves the element whole to the first tap.
     */
    protected function wpc_menu_early_set(array $regs, $seenSrcIds, $html, $inline = false, $closed = false)
    {
        $byId = [];
        foreach ($this->script_registry as $position => $entry) {
            if (is_array($entry) && isset($entry['attributes']['id']) && is_string($entry['attributes']['id']) && $entry['attributes']['id'] !== '') {
                $byId[$entry['attributes']['id']][] = $position;
            }
        }
        $kept = [];
        foreach ($regs as $position) {
            $entry = $this->script_registry[$position];
            $id = (isset($entry['attributes']['id']) && is_string($entry['attributes']['id'])) ? $entry['attributes']['id'] : '';
            if ($id === '' && !empty($entry['src'])) {
                $src = !empty($entry['encoded']) ? (string) base64_decode($entry['src'], true) : (string) $entry['src'];
                $found = array_search($src, (array) $seenSrcIds, true);
                $id = $found === false ? '' : (string) $found;
                if ($id === '' && ($handle = $this->wpc_handle_from_src($src)) !== '') {
                    $id = $handle . '-js';
                }
            }
            if ($id !== '') {
                $kept[$id] = true;
            }
        }
        if (!$inline && function_exists('wpc_policy_cohort') && wpc_policy_cohort('menu_early') === 1) {
            foreach ($regs as $position) {
                if (!empty($this->script_registry[$position]['src'])
                    && preg_match('/(?<![\w$.])(?:jQuery\b|\$\s*[(.])/', $this->wpc_menu_early_source($this->script_registry[$position]))) {
                    $kept['jquery-js'] = true;
                    $kept['jquery-core-js'] = true;
                }
            }
        }
        if ($inline) {
            foreach ($regs as $position) {
                $reads = self::wpc_menu_early_inline_reads($this->wpc_menu_early_source($this->script_registry[$position]));
                if ($reads === null) {
                    return 'inline-reads';
                }
                if ($reads['jquery']) {
                    $kept['jquery-js'] = true;
                    $kept['jquery-core-js'] = true;
                }
            }
        }
        $ids = $kept;
        foreach ($this->wpc_keep_dependency_closure($kept, $seenSrcIds, $html, false) as $dependency) {
            $ids[$dependency] = true;
        }
        $set = array_fill_keys($regs, true);
        foreach (array_keys($ids) as $id) {
            $handle = preg_replace('/-js$/', '', (string) $id);
            foreach ([$id, $handle . '-js-before', $handle . '-js-extra', $handle . '-js-after', $handle . '-js-translations'] as $name) {
                foreach (isset($byId[$name]) ? $byId[$name] : [] as $position) {
                    if (!empty($this->script_registry[$position]['io'])) {
                        return 'io';
                    }
                    $set[$position] = true;
                }
            }
        }
        $gap = $inline ? [] : $this->wpc_menu_early_inline_gap($set, $regs, $seenSrcIds, $closed);
        if (!is_array($gap)) {
            return $gap;
        }
        $set += array_fill_keys($gap, true);
        $weight = $this->wpc_menu_early_weight($set, $regs);
        if ($weight === null || $weight > (int) apply_filters('wpc_menu_early_closure_cap', 61440)) {
            return 'closure';
        }
        ksort($set);
        return $set;
    }

    /**
     * A script's text for the readers above: an inline entry's body, a same-site file's first 400 KB, or ''
     * for a script that cannot be read.
     */
    protected function wpc_menu_early_source(array $entry)
    {
        if (empty($entry['src'])) {
            return self::wpc_menu_early_inline_code($entry);
        }
        $src = !empty($entry['encoded']) ? (string) base64_decode((string) $entry['src'], true) : (string) $entry['src'];
        $file = self::wpc_local_script_file($src);
        return $file === '' ? '' : (string) @file_get_contents($file, false, null, 0, 409600);
    }

    /**
     * What stands between the registry's start and the last binder that the set does not carry: the delayed
     * inline scripts that belong to no handle. One that only assigns literals comes with the set (it can need
     * nothing). One that does more is left out only when it can be shown to define nothing the set's scripts
     * mention (the names it declares or assigns against every word of their sources, none of them dynamic);
     * otherwise the element is left to the first tap ('inline'). One that touches the document and loads no
     * code (wpc_menu_early_dom_names) is left out when none of its words stands in the sources of the set,
     * and comes with the set, in document order, when one does and it needs nothing a script before it
     * defines; its own words then count for the scripts before it. Returns the positions to add, or the reason.
     */
    protected function wpc_menu_early_inline_gap(array $set, array $regs, $seenSrcIds, $closed = false)
    {
        $last = max($regs);
        $words = null;
        $texts = [];
        $add = [];
        $data = [];
        do {
            $joined = false;
            foreach ($this->script_registry as $position => $entry) {
                if ($position >= $last || isset($set[$position]) || isset($data[$position]) || !is_array($entry) || !empty($entry['src'])) {
                    continue;
                }
                $id = (isset($entry['attributes']['id']) && is_string($entry['attributes']['id'])) ? $entry['attributes']['id'] : '';
                if ($id !== '' && preg_match('/^(.+)-js-(?:extra|before|after|translations)$/', $id, $parent) && isset($seenSrcIds[$parent[1] . '-js'])) {
                    continue;
                }
                if ($closed && strpos($id, 'wpc-') === 0) {
                    continue;
                }
                $code = $this->wpc_menu_early_source($entry);
                if (self::wpc_menu_early_inline_is_data($code)) {
                    $add[] = $position;
                    $data[$position] = true;
                    continue;
                }
                $names = self::wpc_menu_early_inline_names($code);
                if ($closed && ($names === [] || ($names === null && self::wpc_idle_release_closed($code)))) {
                    continue;
                }
                $dom = ($names === null && !$closed) ? self::wpc_menu_early_dom_names($code) : null;
                if ($words === null) {
                    $words = [];
                    foreach (array_keys($set) as $member) {
                        if ($this->wpc_menu_early_is_core($this->script_registry[$member])) {
                            continue;
                        }
                        $text = $this->wpc_menu_early_source($this->script_registry[$member]);
                        if ($text === '') {
                            return 'inline';
                        }
                        $texts[] = $text;
                        preg_match_all('/[A-Za-z_$][\w$]*/', $text, $found);
                        $words += array_flip($found[0]);
                    }
                }
                if ($dom === null && ($names === null || $names === [])) {
                    if ($names === null && !$closed && function_exists('wpc_safe_delay_on') && wpc_safe_delay_on() && self::wpc_menu_early_inline_unrelated($code, $texts)) {
                        continue;
                    }
                    return 'inline';
                }
                if ($dom !== null) {
                    $hit = false;
                    foreach ($dom['names'] as $name) {
                        if (self::wpc_menu_early_text_has($texts, $name)) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        continue;
                    }
                    if (!$dom['free']) {
                        return 'inline';
                    }
                    $set[$position] = true;
                    $add[] = $position;
                    $texts[] = $code;
                    preg_match_all('/[A-Za-z_$][\w$]*/', $code, $found);
                    $words += array_flip($found[0]);
                    $joined = true;
                    continue;
                }
                foreach ($names as $name) {
                    if (isset($words[$name])) {
                        return 'inline';
                    }
                }
            }
        } while ($joined);
        return $add;
    }

    /** Whether a registry entry is one of jQuery's own files or WordPress's hooks/i18n (or their companions): never counted, never read for the names a set mentions. */
    protected function wpc_menu_early_is_core(array $entry)
    {
        $id = (isset($entry['attributes']['id']) && is_string($entry['attributes']['id'])) ? $entry['attributes']['id'] : '';
        return in_array(preg_replace('/-js(?:-(?:extra|before|after|translations))?$/', '', $id), ['jquery', 'jquery-core', 'jquery-migrate', 'wp-hooks', 'wp-i18n'], true);
    }

    /** The bytes the set carries besides its binders and jQuery's own files, or null when one of them cannot be weighed. */
    protected function wpc_menu_early_weight(array $set, array $regs)
    {
        $sum = 0;
        foreach (array_keys($set) as $position) {
            if (in_array($position, $regs, true)) {
                continue;
            }
            $entry = $this->script_registry[$position];
            if ($this->wpc_menu_early_is_core($entry)) {
                continue;
            }
            if (empty($entry['src'])) {
                $sum += strlen($this->wpc_menu_early_source($entry));
                continue;
            }
            $src = !empty($entry['encoded']) ? (string) base64_decode((string) $entry['src'], true) : (string) $entry['src'];
            $file = self::wpc_local_script_file($src);
            if ($file === '') {
                return null;
            }
            $sum += (int) @filesize($file);
        }
        return $sum;
    }

    /** The names an inline binder may read without another script having defined them: jQuery, the document and the language's own. */
    const MENU_EARLY_SAFE_NAMES = ['jQuery', '$', 'document', 'window', 'Math', 'Date', 'JSON', 'console', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval',
        'requestAnimationFrame', 'cancelAnimationFrame', 'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'Object', 'Array', 'String',
        'Number', 'Boolean', 'RegExp', 'Error', 'Event', 'CustomEvent', 'MouseEvent', 'location', 'navigator', 'screen', 'history', 'localStorage', 'sessionStorage', 'innerWidth', 'innerHeight',
        'scrollX', 'scrollY', 'pageXOffset', 'pageYOffset', 'devicePixelRatio', 'undefined', 'NaN', 'Infinity', 'arguments', 'this'];

    /** A script's string, template and regex literals and its comments in one pass: group 1 a quoted string, group 3 a regex literal after the operator in group 2. */
    const MENU_EARLY_LITERALS = '~("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|`(?:[^`\\\\]|\\\\.)*`)|/\*.*?\*/|//[^\n]*|((?:^|[(,=:;!&|?{}\[]|\breturn)\s*)(/(?![/*])(?:[^/\\\\\n\[]|\\\\.|\[(?:[^\]\\\\\n]|\\\\.)*\])+/[a-z]*)~ms';

    /** The methods an inline binder may call: jQuery's own, the DOM's and the language's, so no plugin another script defines. */
    const MENU_EARLY_SAFE_CALLS = ['ready', 'on', 'off', 'one', 'click', 'dblclick', 'trigger', 'triggerHandler', 'bind', 'unbind', 'delegate', 'undelegate', 'hover', 'focus', 'blur',
        'change', 'keydown', 'keyup', 'keypress', 'mousedown', 'mouseup', 'mouseenter', 'mouseleave', 'mouseover', 'mouseout', 'touchstart', 'touchend', 'resize', 'scroll', 'toggleClass',
        'addClass', 'removeClass', 'hasClass', 'attr', 'removeAttr', 'prop', 'removeProp', 'css', 'show', 'hide', 'toggle', 'slideDown', 'slideUp', 'slideToggle', 'fadeIn', 'fadeOut',
        'fadeToggle', 'animate', 'stop', 'finish', 'delay', 'parent', 'parents', 'parentsUntil', 'closest', 'children', 'siblings', 'find', 'next', 'nextAll', 'prev', 'prevAll', 'first',
        'last', 'eq', 'filter', 'not', 'is', 'has', 'add', 'end', 'each', 'map', 'get', 'index', 'data', 'removeData', 'text', 'html', 'val', 'append', 'appendTo', 'prepend', 'prependTo',
        'after', 'before', 'remove', 'empty', 'detach', 'wrap', 'wrapInner', 'clone', 'width', 'height', 'innerWidth', 'innerHeight', 'outerWidth', 'outerHeight', 'offset', 'position',
        'scrollTop', 'scrollLeft', 'preventDefault', 'stopPropagation', 'stopImmediatePropagation', 'contains', 'addEventListener', 'removeEventListener', 'dispatchEvent', 'querySelector',
        'querySelectorAll', 'getElementById', 'getElementsByClassName', 'getElementsByTagName', 'getAttribute', 'setAttribute', 'removeAttribute', 'hasAttribute', 'matches', 'createElement',
        'createTextNode', 'appendChild', 'insertBefore', 'removeChild', 'cloneNode', 'getBoundingClientRect', 'matchMedia', 'forEach', 'indexOf', 'slice', 'split', 'join', 'push', 'pop',
        'replace', 'trim', 'test', 'match', 'toLowerCase', 'toUpperCase', 'toString', 'call', 'apply', 'now', 'floor', 'ceil', 'round', 'max', 'min', 'abs', 'getTime', 'isArray'];

    /**
     * Whether an inline script can run before the scripts that stand ahead of it: it reads no name but jQuery, the
     * document and the language's own (or one it declares, or takes as a parameter), calls only jQuery's, the
     * DOM's and the language's methods, and builds no code (eval, Function, import, a template string, a name made
     * of strings). The same text can then not depend on anything a script before it defines. null when it cannot
     * be shown; else ['jquery' => whether it uses jQuery or $].
     */
    public static function wpc_menu_early_inline_reads($code)
    {
        $code = (string) $code;
        if ($code === '' || strlen($code) > 65536) {
            return null;
        }
        $bare = preg_replace('/"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/', '""', $code);
        $bare = is_string($bare) ? preg_replace('~/\*.*?\*/|(?<![:\w])//[^\n]*~s', '', $bare) : null;
        if (!is_string($bare) || strpbrk($bare, '`') !== false
            || preg_match('/(?<![\w$])(?:eval|Function|importScripts|import|export|with|class|async|await|yield|globalThis)(?![\w$])|(?<![\w$.])(?:window|self|top|parent)\s*\[|\]\s*\(/', $bare)
            || preg_match('/(?<![\w$])(?:setTimeout|setInterval)\s*\(\s*["\']/', $code)) {
            return null;
        }
        $declared = [];
        if (preg_match_all('/(?<![\w$.])(?:var|let|const)\s/', $bare, $hits, PREG_OFFSET_CAPTURE)) {
            foreach ($hits[0] as $hit) {
                $declared = array_merge($declared, self::wpc_menu_early_declaration_names($bare, $hit[1] + strlen($hit[0])));
            }
        }
        $lists = [];
        foreach (['/(?<![\w$.])function\s*[\w$]*\s*\(([^)]*)\)/', '/\(([^()]*)\)\s*=>/'] as $pattern) {
            if (preg_match_all($pattern, $bare, $found)) {
                $lists = array_merge($lists, $found[1]);
            }
        }
        foreach ($lists as $list) {
            foreach (explode(',', (string) $list) as $parameter) {
                if (preg_match('/^\s*([A-Za-z_$][\w$]*)/', $parameter, $name)) {
                    $declared[] = $name[1];
                }
            }
        }
        foreach (['/(?<![\w$.])function\s+([A-Za-z_$][\w$]*)/', '/(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>/', '/(?<![\w$.])catch\s*\(\s*([A-Za-z_$][\w$]*)/'] as $pattern) {
            if (preg_match_all($pattern, $bare, $found)) {
                $declared = array_merge($declared, $found[1]);
            }
        }
        $declared = array_flip($declared);
        $keywords = array_flip(['break', 'case', 'catch', 'continue', 'debugger', 'default', 'delete', 'do', 'else', 'finally', 'for', 'function', 'if', 'in', 'instanceof', 'new', 'of', 'return',
            'switch', 'throw', 'try', 'typeof', 'var', 'let', 'const', 'void', 'while', 'true', 'false', 'null']);
        $safe = array_flip(self::MENU_EARLY_SAFE_NAMES);
        $words = [];
        preg_match_all('/(?<![\w$])[A-Za-z_$][\w$]*/', $bare, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[0] as $hit) {
            $word = $hit[0];
            $before = rtrim(substr($bare, 0, $hit[1]));
            $previous = substr($before, -1);
            $after = ltrim((string) substr($bare, $hit[1] + strlen($word), 12));
            if ($previous === '.') {
                if (!preg_match('/(?<![\w$.])(?:window|self|top|parent)$/', rtrim(substr($before, 0, -1))) || (isset($after[0]) && $after[0] === '(')) {
                    continue;
                }
            } elseif (isset($after[0]) && $after[0] === ':' && ($previous === '{' || $previous === ',')) {
                continue;
            }
            $words[$word] = true;
        }
        foreach (array_keys($words) as $word) {
            if (!isset($keywords[$word]) && !isset($declared[$word]) && !isset($safe[$word])) {
                return null;
            }
        }
        if (preg_match_all('/\.\s*([A-Za-z_$][\w$]*)\s*\(/', $bare, $calls)) {
            foreach ($calls[1] as $call) {
                if (!in_array($call, self::MENU_EARLY_SAFE_CALLS, true)) {
                    return null;
                }
            }
        }
        return ['jquery' => isset($words['jQuery']) || (isset($words['$']) && !isset($declared['$']))];
    }

    /**
     * Whether an inline script only assigns literals (var/let/const NAME = {...}; window.NAME = [...];): no
     * call, no function, no word that is not a literal or an object key, so it needs nothing to have run before it.
     */
    public static function wpc_menu_early_inline_is_data($code)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return false;
        }
        $bare = preg_replace('/"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/', '""', $code);
        $bare = preg_replace('~/\*.*?\*/|(?<![:\w])//[^\n]*~s', '', (string) $bare);
        if (preg_match('/=>|\b(?:function|class|new|import|export|await|async|eval|return|if|for|while|do|switch|try|throw|delete|void|typeof|instanceof|in|of|with)\b/', (string) $bare)) {
            return false;
        }
        $statements = 0;
        foreach (preg_split('/;/', (string) $bare) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if (!preg_match('/^(?:(?:var|let|const)\s+)?(?:window\s*\.\s*)?[A-Za-z_$][\w$]*(?:\s*\.\s*[A-Za-z_$][\w$]*)*\s*=(?!=)\s*(.*)$/s', $statement, $parts)) {
                return false;
            }
            $value = preg_replace('/[A-Za-z_$][\w$]*\s*:|"[^"]*"\s*:/', '', $parts[1]);
            if (preg_match_all('/[A-Za-z_$][\w$]*/', (string) $value, $words) && array_diff($words[0], ['true', 'false', 'null', 'undefined', 'NaN', 'Infinity']) !== []) {
                return false;
            }
            $statements++;
        }
        return $statements > 0;
    }

    /**
     * The names an inline script declares or writes: var/let/const/function/class names (every name of a
     * declaration list), window.NAME and bare NAME assignments, and every name along the chain of any other
     * assignment, increment or delete (Theme.settings.x = 1 writes Theme and settings). null when the script
     * cannot be shown to touch nothing else: it builds names or code at run time, touches the document, loads
     * or defines things through a call (a call is allowed only to a function the script declares or to a
     * language built-in), assigns through brackets or a call result, or destructures.
     */
    public static function wpc_menu_early_inline_names($code)
    {
        $code = (string) $code;
        if ($code === '' || strlen($code) > 65536 || preg_match('/(?<![\w$])(?:eval|Function|globalThis|importScripts|import|export|with)(?![\w$])|(?<![\w$])document\s*\.|\.\s*(?:write|writeln|extend|defineProperty|defineProperties|assign|innerHTML|outerHTML|className|classList|setAttribute|appendChild|insertBefore)(?![\w$])|(?<![\w$])(?:window|self)\s*\[|(?<![\w$])window\s*,|\(\s*window\s*\)|(?<![\w$])(?:var|let|const)\s*[\[{]|(?<![\w$])(?:setTimeout|setInterval)\s*\(\s*["\']/', $code) !== 0) {
            return null;
        }
        $bare = preg_replace('/"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/', '""', $code);
        $bare = is_string($bare) ? preg_replace('~/\*.*?\*/|(?<![:\w])//[^\n]*~s', '', $bare) : null;
        if (!is_string($bare) || strpbrk($bare, '`') !== false) {
            return null;
        }
        $operator = '(?:\*\*|<<|>>>?|\|\||&&|\?\?|[-+*\/%&|^])?=(?![=>])';
        $chain = '(?:[A-Za-z_$][\w$]*\s*\.\s*)*[A-Za-z_$][\w$]*';
        $scan = function ($pattern, $flags = 0) use ($bare) {
            $found = [[], [], [], []];
            preg_match_all($pattern, $bare, $found, $flags);
            return $found;
        };
        $through = preg_match('/\]\s*(?:\.\s*[A-Za-z_$][\w$]*\s*)*' . $operator . '|\)\s*\.\s*' . $chain . '\s*' . $operator . '/', $bare);
        if ($through !== 0) {
            return null;
        }
        $declared = [];
        foreach ($scan('/(?<![\w$.])(?:var|let|const)\s/', PREG_OFFSET_CAPTURE)[0] as $hit) {
            $declared = array_merge($declared, self::wpc_menu_early_declaration_names($bare, $hit[1] + strlen($hit[0])));
        }
        $declared = array_merge($declared, $scan('/(?<![\w$.])(?:function|class)\s+([A-Za-z_$][\w$]*)/')[1]);
        $chains = $scan('/(?<![\w$.])(' . $chain . ')\s*' . $operator . '/')[1];
        $steps = $scan('/(?<![\w$.])(' . $chain . ')\s*(?:\+\+|--)|(?:\+\+|--)\s*(' . $chain . ')|(?<![\w$.])delete\s+(' . $chain . ')/');
        $chains = array_merge($chains, array_filter(array_merge($steps[1], $steps[2], $steps[3])));
        $skip = ['window' => 1, 'self' => 1, 'this' => 1, 'top' => 1, 'parent' => 1];
        $touched = [];
        foreach ($chains as $link) {
            $parts = array_map('trim', explode('.', $link));
            if (count($parts) === 1) {
                $declared[] = $parts[0];
            } elseif (isset($skip[$parts[0]])) {
                $declared[] = $parts[1];
            }
            foreach ($parts as $step) {
                if (!isset($skip[$step])) {
                    $touched[] = $step;
                }
            }
        }
        $own = array_flip($declared);
        $builtin = array_flip(['Date', 'Math', 'JSON', 'console', 'Object', 'Array', 'String', 'Number', 'Boolean', 'Symbol', 'RegExp', 'Error', 'TypeError', 'Promise', 'Map', 'Set', 'WeakMap', 'WeakSet', 'Intl',
            'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'encodeURI', 'decodeURI', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'requestAnimationFrame', 'requestIdleCallback']);
        $control = array_flip(['if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof', 'void', 'else', 'do', 'in', 'of', 'throw', 'case', 'instanceof', 'new', 'await', 'yield', 'async']);
        foreach ($scan('/(?<![\w$.])(' . $chain . ')\s*\(/')[1] as $link) {
            $parts = array_values(array_filter(array_map('trim', explode('.', $link)), function ($step) use ($skip) {
                return !isset($skip[$step]);
            }));
            if ($parts === []) {
                return null;
            }
            if (count($parts) === 1 && isset($control[$parts[0]])) {
                continue;
            }
            if (!isset($own[$parts[0]]) && !isset($builtin[$parts[0]])) {
                return null;
            }
        }
        return array_values(array_unique(array_merge($declared, $touched)));
    }

    const MENU_EARLY_DOM_CALLS = ['addEventListener', 'removeEventListener', 'addListener', 'removeListener', 'matchMedia', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval',
        'requestAnimationFrame', 'cancelAnimationFrame', 'requestIdleCallback', 'cancelIdleCallback', 'getComputedStyle', 'querySelector', 'querySelectorAll', 'getElementById',
        'getElementsByClassName', 'getElementsByTagName', 'getAttribute', 'setAttribute', 'removeAttribute', 'hasAttribute', 'toggleAttribute', 'add', 'remove', 'toggle', 'replace',
        'contains', 'closest', 'matches', 'focus', 'blur', 'scrollIntoView', 'scrollTo', 'scrollBy', 'getBoundingClientRect', 'observe', 'unobserve', 'disconnect', 'forEach', 'map', 'filter',
        'some', 'every', 'indexOf', 'includes', 'slice', 'split', 'join', 'push', 'pop', 'shift', 'unshift', 'concat', 'keys', 'test', 'match', 'trim', 'substring', 'substr', 'charAt',
        'startsWith', 'endsWith', 'toLowerCase', 'toUpperCase', 'toString', 'floor', 'ceil', 'round', 'max', 'min', 'abs', 'now', 'getTime', 'isArray', 'log', 'warn'];

    const MENU_EARLY_DOM_FUNCTIONS = ['matchMedia', 'getComputedStyle', 'requestAnimationFrame', 'cancelAnimationFrame', 'requestIdleCallback', 'cancelIdleCallback', 'setTimeout', 'clearTimeout',
        'setInterval', 'clearInterval', 'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'String', 'Number', 'Boolean', 'Array'];

    const MENU_EARLY_DOM_CONSTRUCTORS = ['IntersectionObserver', 'MutationObserver', 'ResizeObserver', 'Date', 'RegExp', 'Map', 'Set', 'WeakMap', 'WeakSet', 'Array', 'Object', 'Error'];

    const MENU_EARLY_DOM_NAMES = ['document', 'window', 'self', 'navigator', 'location', 'history', 'screen', 'console', 'Math', 'Date', 'JSON', 'Object', 'Array', 'String', 'Number', 'Boolean',
        'RegExp', 'Error', 'Map', 'Set', 'WeakMap', 'WeakSet', 'Symbol', 'Intl', 'IntersectionObserver', 'MutationObserver', 'ResizeObserver', 'matchMedia', 'getComputedStyle', 'requestAnimationFrame',
        'cancelAnimationFrame', 'requestIdleCallback', 'cancelIdleCallback', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'parseInt', 'parseFloat', 'isNaN', 'isFinite',
        'encodeURIComponent', 'decodeURIComponent', 'innerWidth', 'innerHeight', 'scrollX', 'scrollY', 'pageXOffset', 'pageYOffset', 'devicePixelRatio', 'performance', 'undefined', 'NaN', 'Infinity',
        'arguments', 'this', 'addEventListener', 'removeEventListener'];

    const MENU_EARLY_DOM_WORDS = ['DOMContentLoaded', 'readystatechange', 'hashchange', 'popstate', 'pageshow', 'pagehide', 'visibilitychange', 'orientationchange', 'beforeunload', 'unload', 'load',
        'click', 'dblclick', 'change', 'resize', 'scroll', 'wheel', 'touchstart', 'touchend', 'touchmove', 'touchcancel', 'pointerdown', 'pointerup', 'pointermove', 'pointercancel', 'mousedown',
        'mouseup', 'mousemove', 'mouseover', 'mouseout', 'mouseenter', 'mouseleave', 'keydown', 'keyup', 'keypress', 'focusin', 'focusout', 'contextmenu', 'max-width', 'min-width', 'max-height',
        'min-height'];

    /**
     * What a delayed inline script that touches the document leaves behind, or null when that cannot be shown. null: it is over 64 KB, builds or loads code (eval, Function, import, a string timer,
     * a src, srcset or srcdoc, innerHTML or document.write), writes through a bracket, a call result or an alias of window, destructures, or calls anything but the DOM's and the language's own
     * methods and functions (or one it declares); a call that names a class, attribute or property (add, remove, toggle, setAttribute, removeAttribute) takes a string for its name.
     * Else ['names' => the words it can write: every word of its string literals (class names, ids, data attributes, event names that are not the browser's own), the properties it assigns, the
     * names it declares or assigns outside any function, 'free' => whether every name it reads is its own, the document's or the language's, so it can run before the scripts that stand ahead of it].
     */
    public static function wpc_menu_early_dom_names($code)
    {
        $code = (string) $code;
        if ($code === '' || strlen($code) > 65536) {
            return null;
        }
        $quoted = [];
        $dynamic = false;
        $bare = preg_replace_callback(
            self::MENU_EARLY_LITERALS,
            function ($found) use (&$quoted, &$dynamic) {
                if (isset($found[1]) && $found[1] !== '') {
                    $quoted[] = substr($found[1], 1, -1);
                    $dynamic = $dynamic || ($found[1][0] === '`' && strpos($found[1], '${') !== false);
                    return '""';
                }
                if (isset($found[3]) && $found[3] !== '') {
                    return $found[2] . '/0/';
                }
                return ' ';
            },
            $code
        );
        if ($dynamic || !is_string($bare) || strpbrk(str_replace('""', '', $bare), '\'"`') !== false) {
            return null;
        }
        $name = '[A-Za-z_$][\w$]*';
        $operator = '(?:\*\*|<<|>>>?|\|\||&&|\?\?|[-+*\/%&|^])?=(?![=>])';
        if (preg_match('/(?<![\w$.])(?:eval|Function|globalThis|importScripts|import|export|with|class|async|await|yield)(?![\w$])|(?<![\w$.])(?:window|self|top|parent)\s*\[|(?<![\w$])window\s*,|\(\s*window\s*\)'
            . '|\.\s*(?:write|writeln|innerHTML|outerHTML|insertAdjacentHTML|src|srcset|srcdoc|extend|assign|defineProperty|defineProperties)(?![\w$])|\.\s*href\s*=(?!=)|(?<![\w$.])location\s*=(?!=)'
            . '|(?<![\w$.])(?:var|let|const)\s*[\[{]|(?<![\w$.])(?:setTimeout|setInterval)\s*\(\s*""'
            . strtr('|\]\s*(?:\.\s*{N}\s*)*{O}|\)\s*\.\s*{N}(?:\s*\.\s*{N})*\s*{O}|\]\s*(?:\+\+|--)|\]\s*\(', ['{N}' => $name, '{O}' => $operator])
            . '|\.\s*(?:add|remove|toggle|setAttribute|removeAttribute|toggleAttribute)\s*\(\s*+(?!""\s*[,)]|\))|classList\s*\.\s*replace\s*\(\s*+(?!""\s*,)/', $bare) !== 0) {
            return null;
        }
        $declared = [];
        if (preg_match_all('/(?<![\w$.])(?:var|let|const)\s/', $bare, $hits, PREG_OFFSET_CAPTURE)) {
            foreach ($hits[0] as $hit) {
                $declared = array_merge($declared, self::wpc_menu_early_declaration_names($bare, $hit[1] + strlen($hit[0])));
            }
        }
        foreach (['/(?<![\w$.])function\s*[\w$]*\s*\(([^)]*)\)/', '/\(([^()]*)\)\s*=>/'] as $pattern) {
            if (preg_match_all($pattern, $bare, $found)) {
                foreach ($found[1] as $list) {
                    foreach (explode(',', (string) $list) as $parameter) {
                        if (preg_match('/^\s*(' . $name . ')/', $parameter, $parameterName)) {
                            $declared[] = $parameterName[1];
                        }
                    }
                }
            }
        }
        foreach (['/(?<![\w$.])function\s+(' . $name . ')/', '/(?<![\w$.])(' . $name . ')\s*=>/', '/(?<![\w$.])catch\s*\(\s*(' . $name . ')/'] as $pattern) {
            if (preg_match_all($pattern, $bare, $found)) {
                $declared = array_merge($declared, $found[1]);
            }
        }
        $own = array_flip($declared);
        $keywords = array_flip(['break', 'case', 'catch', 'continue', 'debugger', 'default', 'delete', 'do', 'else', 'finally', 'for', 'function', 'if', 'in', 'instanceof', 'new', 'of', 'return',
            'switch', 'throw', 'try', 'typeof', 'var', 'let', 'const', 'void', 'while', 'true', 'false', 'null']);
        if (preg_match_all('/(?<![\w$.])new\s+(' . $name . ')/', $bare, $made)) {
            foreach ($made[1] as $constructor) {
                if (!isset($own[$constructor]) && !in_array($constructor, self::MENU_EARLY_DOM_CONSTRUCTORS, true)) {
                    return null;
                }
            }
        }
        $plain = (string) preg_replace('/(?<![\w$.])new\s+' . $name . '/', ' ', $bare);
        if (preg_match_all('/(?<![\w$.])(' . $name . ')\s*\(/', $plain, $called)) {
            foreach ($called[1] as $callee) {
                if (!isset($keywords[$callee]) && !isset($own[$callee]) && !in_array($callee, self::MENU_EARLY_DOM_FUNCTIONS, true)) {
                    return null;
                }
            }
        }
        if (preg_match_all('/\.\s*(' . $name . ')\s*\(/', $bare, $methods)) {
            foreach ($methods[1] as $method) {
                if (!in_array($method, self::MENU_EARLY_DOM_CALLS, true)) {
                    return null;
                }
            }
        }
        $top = '';
        $stack = [];
        $inside = 0;
        for ($i = 0, $length = strlen($bare); $i < $length; $i++) {
            $char = $bare[$i];
            if ($char === '{') {
                $function = preg_match('/(?:\bfunction\b[^{};]*|=>\s*)$/', substr($bare, max(0, $i - 240), min($i, 240))) === 1;
                $stack[] = $function;
                $inside += $function ? 1 : 0;
            } elseif ($char === '}') {
                $inside -= array_pop($stack) ? 1 : 0;
            }
            if ($inside === 0) {
                $top .= $char;
            }
        }
        if ($inside !== 0 || $stack !== []) {
            return null;
        }
        $written = [];
        if (preg_match_all('/(?<![\w$.])(?:var|let|const)\s/', $top, $hits, PREG_OFFSET_CAPTURE)) {
            foreach ($hits[0] as $hit) {
                $written = array_merge($written, self::wpc_menu_early_declaration_names($top, $hit[1] + strlen($hit[0])));
            }
        }
        if (preg_match_all('/(?<![\w$.])(?:function|class)\s+(' . $name . ')/', $top, $found)) {
            $written = array_merge($written, $found[1]);
        }
        $safe = array_flip(self::MENU_EARLY_DOM_NAMES);
        $chain = '(?:' . $name . '\s*\.\s*)*' . $name;
        foreach (['/(?<![\w$.])({C})\s*{O}/', '/(?<![\w$.])({C})\s*(?:\+\+|--)|(?:\+\+|--)\s*({C})|(?<![\w$.])delete\s+({C})/'] as $pattern) {
            if (preg_match_all(strtr($pattern, ['{C}' => $chain, '{O}' => $operator]), $top, $found)) {
                foreach (array_slice($found, 1) as $links) {
                    foreach ($links as $link) {
                        $parts = preg_split('/\s*\.\s*/', trim((string) $link), -1, PREG_SPLIT_NO_EMPTY);
                        if (count($parts) > 1 && in_array($parts[0], ['window', 'self', 'this', 'top', 'parent'], true)) {
                            array_shift($parts);
                        } elseif (count($parts) > 1 && (isset($own[$parts[0]]) || isset($safe[$parts[0]]))) {
                            $parts = [end($parts)];
                        }
                        $written = array_merge($written, $parts);
                    }
                }
            }
        }
        foreach (['/\.\s*({N})\s*{O}/', '/\.\s*({N})\s*(?:\+\+|--)/', '/(?:\+\+|--)\s*{N}(?:\s*\.\s*{N})*?\s*\.\s*({N})(?![\w$.])/'] as $pattern) {
            if (preg_match_all(strtr($pattern, ['{N}' => $name, '{O}' => $operator]), $bare, $found)) {
                $written = array_merge($written, $found[1]);
            }
        }
        $tokens = [];
        $skip = array_flip(self::MENU_EARLY_DOM_WORDS);
        foreach ($quoted as $text) {
            $text = (string) $text;
            if (preg_match_all('~[\w-]+(?:/[\w-]+)+~', $text, $paths)) {
                foreach ($paths[0] as $path) {
                    $tokens[$path] = true;
                }
            }
            $rest = (string) preg_replace(['~[\w-]+(?:/[\w-]+)+~', '/:{1,2}[A-Za-z-]+/'], ' ', $text);
            if (preg_match_all('/(?<![\w-])[A-Za-z_][\w-]*/', $rest, $words)) {
                foreach ($words[0] as $word) {
                    if (strlen($word) >= 2 && !isset($skip[$word])) {
                        $tokens[$word] = true;
                    }
                }
            }
        }
        foreach ($written as $word) {
            if ($word !== '' && !in_array($word, ['window', 'self', 'this', 'top', 'parent'], true)) {
                $tokens[$word] = true;
            }
        }
        if (count($tokens) > 200) {
            return null;
        }
        $free = true;
        preg_match_all('/(?<![\w$])[A-Za-z_$][\w$]*/', $bare, $words, PREG_OFFSET_CAPTURE);
        foreach ($words[0] as $hit) {
            $before = rtrim(substr($bare, 0, $hit[1]));
            $previous = substr($before, -1);
            $after = ltrim((string) substr($bare, $hit[1] + strlen($hit[0]), 12));
            if ($previous === '.') {
                if (!preg_match('/(?<![\w$.])(?:window|self|top|parent)$/', rtrim(substr($before, 0, -1))) || (isset($after[0]) && $after[0] === '(')) {
                    continue;
                }
            } elseif (isset($after[0]) && $after[0] === ':' && ($previous === '{' || $previous === ',')) {
                continue;
            }
            if (!isset($keywords[$hit[0]]) && !isset($own[$hit[0]]) && !isset($safe[$hit[0]])) {
                $free = false;
                break;
            }
        }
        return ['names' => array_keys($tokens), 'free' => $free];
    }

    /**
     * The free identifiers of an inline script (two characters or more, not a language or document name, not a property or an object key,
     * window.X and this.X read as X), or null when it cannot be told: dynamic global access, a template with an expression, text the literal pass cannot balance.
     */
    public static function wpc_menu_early_free_ids($code)
    {
        $code = (string) $code;
        if ($code === '' || strlen($code) > 65536) {
            return null;
        }
        $dynamic = false;
        $bare = preg_replace_callback(self::MENU_EARLY_LITERALS, function ($found) use (&$dynamic) {
            if (isset($found[1]) && $found[1] !== '') {
                $dynamic = $dynamic || ($found[1][0] === '`' && strpos($found[1], '${') !== false);
                return '""';
            }
            if (isset($found[3]) && $found[3] !== '') {
                return $found[2] . '/0/';
            }
            return ' ';
        }, $code);
        if ($dynamic || !is_string($bare) || strpbrk(str_replace('""', '', $bare), '\'"`') !== false) {
            return null;
        }
        if (preg_match('/(?<![\w$.])(?:eval|Function|importScripts|with)(?![\w$])|(?<![\w$.])(?:window|self|top|parent|globalThis|this)\s*\[|\.\s*(?:extend|assign|defineProperty|defineProperties|write|writeln|fn)(?![\w$])/', $bare) !== 0) {
            return null;
        }
        $bare = str_replace('...', ' ', (string) preg_replace('/(?<=[{,])\s*[A-Za-z_$][\w$]*\s*:(?!:)/', ' ', $bare));
        $found = [];
        $global = [];
        if (preg_match_all('/(?<![\w$.])[A-Za-z_$][\w$]*/', $bare, $found) === false || preg_match_all('/(?<![\w$.])(?:window|self|globalThis|this)\s*\.\s*([A-Za-z_$][\w$]*)/', $bare, $global) === false) {
            return null;
        }
        static $skip = null;
        if ($skip === null) {
            $skip = array_flip(array_merge(self::MENU_EARLY_SAFE_NAMES, ['break', 'case', 'catch', 'class', 'const', 'continue', 'debugger', 'default', 'delete', 'do', 'else', 'extends',
                'finally', 'for', 'function', 'if', 'in', 'instanceof', 'let', 'new', 'of', 'return', 'super', 'switch', 'throw', 'try', 'typeof', 'var', 'void', 'while', 'yield',
                'async', 'await', 'null', 'true', 'false', 'self', 'globalThis']));
        }
        $ids = [];
        foreach (array_merge($found[0], $global[1]) as $word) {
            if (strlen($word) >= 2 && !isset($skip[$word])) {
                $ids[$word] = true;
            }
        }
        return $ids;
    }

    /**
     * Whether an inline script the menu gap cannot read is unrelated to the scripts that run ahead of it: none of its free identifiers is a word
     * of theirs, and none of the class or id tokens in their string literals stands in it. Any doubt (nothing to compare, an unreadable text) is related.
     */
    public static function wpc_menu_early_inline_unrelated($code, array $texts)
    {
        $mine = self::wpc_menu_early_free_ids($code);
        if ($mine === null || $texts === []) {
            return false;
        }
        foreach (array_keys($mine) as $id) {
            if (self::wpc_menu_early_text_has($texts, $id)) {
                return false;
            }
        }
        foreach ($texts as $text) {
            $text = (string) $text;
            if ($text === '') {
                return false;
            }
            $literals = [];
            if (preg_match_all('~"(?:[^"\\\\\n]++|\\\\.)*+"|\'(?:[^\'\\\\\n]++|\\\\.)*+\'|`(?:[^`\\\\]++|\\\\.)*+`~', $text, $literals) === false) {
                return false;
            }
            $tokens = [];
            if ($literals[0] !== [] && preg_match_all('/(?<![\w-])[.#]([A-Za-z_][\w-]{2,})/', implode(' ', $literals[0]), $tokens) === false) {
                return false;
            }
            foreach (isset($tokens[1]) ? array_unique($tokens[1]) : [] as $token) {
                if (self::wpc_menu_early_text_has([$code], $token)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Whether any of $texts holds $name as a whole word (a class name or event name may carry hyphens, dots and colons: these are not word boundaries). */
    public static function wpc_menu_early_text_has(array $texts, $name)
    {
        $pattern = '/(?<![\w$-])' . preg_quote((string) $name, '/') . '(?![\w$-])/';
        foreach ($texts as $text) {
            if (preg_match($pattern, (string) $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an inline script writes no name another script could read: no declaration and no assignment outside a
     * function body, no member of window, self, top, parent or this assigned, no variable assigned that the script
     * has not declared, no code or name built at run time, no call into a global it did not declare (the browser's
     * own and jQuery excepted: a call into a script's object may change what a later script reads). What it does to
     * the document is not a name.
     */
    public static function wpc_idle_release_closed($code)
    {
        $code = (string) $code;
        if ($code === '' || strlen($code) > 65536) {
            return false;
        }
        $bare = preg_replace('/"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/', '""', $code);
        $bare = is_string($bare) ? preg_replace('~/\*.*?\*/|(?<![:\w])//[^\n]*~s', '', $bare) : null;
        if (!is_string($bare) || strpbrk($bare, '`') !== false) {
            return false;
        }
        $operator = '(?:\*\*|<<|>>>?|\|\||&&|\?\?|[-+*\/%&|^])?=(?![=>])';
        $name = '[A-Za-z_$][\w$]*';
        $chain = '(?<![\w$.])' . $name . '(?:\s*\.\s*' . $name . ')*';
        if (preg_match('/(?<![\w$.])(?:eval|Function|globalThis|importScripts|import|export|with)(?![\w$])|\.\s*(?:write|writeln)\s*\(|(?<![\w$])(?:window|self|top|parent)\s*\[|(?<![\w$])(?:var|let|const)\s*[\[{]|(?<![\w$.])(?:window|self|top|parent|this)\s*\.\s*' . $name . '\s*' . $operator . '/', $bare) !== 0) {
            return false;
        }
        $top = '';
        $stack = [];
        $inside = 0;
        for ($i = 0, $length = strlen($bare); $i < $length; $i++) {
            $char = $bare[$i];
            if ($char === '{') {
                $function = preg_match('/(?:\bfunction\b[^{};]*|=>\s*)$/', substr($bare, max(0, $i - 240), min($i, 240))) === 1;
                $stack[] = $function;
                $inside += $function ? 1 : 0;
            } elseif ($char === '}') {
                $inside -= array_pop($stack) ? 1 : 0;
            }
            if ($inside === 0) {
                $top .= $char;
            }
        }
        if ($inside !== 0 || $stack !== []) {
            return false;
        }
        if (preg_match(strtr('/(?<![\w$.])(?:var|let|const|class|delete)\s|(?:^|[;}\n])\s*function\s+{N}|(?:\+\+|--)\s*{N}|{N}\s*(?:\+\+|--)|{C}\s*{O}/', ['{N}' => $name, '{C}' => $chain, '{O}' => $operator]), $top) !== 0) {
            return false;
        }
        $declared = ['document' => 1, 'arguments' => 1];
        if (preg_match_all('/(?<![\w$.])(?:var|let|const)\s/', $bare, $hits, PREG_OFFSET_CAPTURE)) {
            foreach ($hits[0] as $hit) {
                $declared += array_flip(self::wpc_menu_early_declaration_names($bare, $hit[1] + strlen($hit[0])));
            }
        }
        if (preg_match_all(strtr('/(?<![\w$.])(?:function|class)\s+({N})|(?<![\w$.])catch\s*\(\s*({N})|(?<![\w$.])function\s*(?:{N})?\s*\(([^()]*)\)|\(([^()]*)\)\s*=>|({N})\s*=>/', ['{N}' => $name]), $bare, $found, PREG_SET_ORDER)) {
            foreach ($found as $one) {
                foreach (array_slice($one, 1) as $list) {
                    foreach (preg_split('/[\s,=]+/', (string) $list) as $word) {
                        if (preg_match('/^' . $name . '$/', $word)) {
                            $declared[$word] = 1;
                        }
                    }
                }
            }
        }
        if (preg_match_all('/(' . $chain . ')\s*' . $operator . '/', $bare, $writes)) {
            foreach ($writes[1] as $link) {
                if (!isset($declared[trim((string) strtok($link, '.'))])) {
                    return false;
                }
            }
        }
        $standard = array_flip(['window', 'self', 'top', 'parent', 'this', 'document', 'navigator', 'location', 'history', 'screen', 'console', 'Math', 'JSON', 'Date', 'Object', 'Array', 'String', 'Number',
            'Boolean', 'RegExp', 'Error', 'Promise', 'Map', 'Set', 'WeakMap', 'WeakSet', 'URL', 'URLSearchParams', 'Image', 'Event', 'CustomEvent', 'MutationObserver', 'IntersectionObserver', 'ResizeObserver',
            'PerformanceObserver', 'XMLHttpRequest', 'fetch', 'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'encodeURI', 'decodeURI', 'setTimeout', 'clearTimeout',
            'setInterval', 'clearInterval', 'requestAnimationFrame', 'cancelAnimationFrame', 'requestIdleCallback', 'getComputedStyle', 'matchMedia', 'addEventListener', 'removeEventListener', 'dispatchEvent',
            'scrollTo', 'jQuery', '$']);
        $control = array_flip(['if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof', 'void', 'else', 'do', 'in', 'of', 'throw', 'case', 'instanceof', 'new', 'await', 'yield', 'async']);
        if (preg_match_all('/(?<![\w$.\/])(?:(?:window|self|this)\s*\.\s*)*(' . $name . ')(?:\s*\.\s*' . $name . ')*\s*\(/', $bare, $calls)) {
            foreach ($calls[1] as $root) {
                if (!isset($control[$root]) && !isset($declared[$root]) && !isset($standard[$root])) {
                    return false;
                }
            }
        }
        return true;
    }

    /** The names of one var/let/const declaration list that starts at $from: those at its own depth, up to the semicolon that ends it. */
    protected static function wpc_menu_early_declaration_names($bare, $from)
    {
        $names = [];
        $expect = true;
        $depth = 0;
        for ($i = (int) $from, $length = strlen($bare); $i < $length; $i++) {
            $char = $bare[$i];
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth > 0) {
                continue;
            } elseif ($char === ';') {
                break;
            } elseif ($char === ',') {
                $expect = true;
            } elseif ($expect && preg_match('/[A-Za-z_$][\w$]*/A', $bare, $word, 0, $i)) {
                $names[] = $word[0];
                $i += strlen($word[0]) - 1;
                $expect = false;
            }
        }
        return $names;
    }

    /**
     * The early lane's plan for this render, or null while lever menu_early is off or the service has not
     * measured this page's first screen (the plugin's own list of menu scripts applies then, defines.php
     * wpc_menu_early_patterns). ['ids' => registry ids in document order, 'matched', 'entries', 'elements', 'sel' => selector => ['k' => kind,
     * 'i' => the registry ids of the set of the elements early under that selector], which the loader config carries as menuEarlySel].
     */
    protected function wpc_menu_early_plan($pageHtml, $seenSrcIds)
    {
        $field = function_exists('wpc_menu_early_field') ? wpc_menu_early_field() : null;
        if ($field === null || !is_array($this->script_registry)) {
            return null;
        }
        $pageScripts = [];
        if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', (string) $pageHtml, $tags)) {
            foreach ($tags[0] as $tag) {
                $attributes = $this->parse_script_attributes($tag);
                if (!empty($attributes['src'])) {
                    $pageScripts[] = ['id' => isset($attributes['id']) && is_string($attributes['id']) ? $attributes['id'] : '', 'src' => html_entity_decode((string) $attributes['src'])];
                }
            }
        }
        $plan = self::wpc_menu_early_match($this->script_registry, $field['entries'], $pageScripts, (int) apply_filters('wpc_menu_early_bytes_cap', 61440));
        $room = (int) apply_filters('wpc_menu_early_cap', 16);
        $chosen = [];
        $bySelector = [];
        $viaInline = 0;
        foreach ($plan['elements'] as $index => $element) {
            if ($element['state'] !== 'early') {
                continue;
            }
            $set = $this->wpc_menu_early_set($element['regs'], $seenSrcIds, $pageHtml, !empty($element['inline']));
            if (is_string($set)) {
                $plan['elements'][$index]['state'] = $set;
                $plan['matched']--;
                continue;
            }
            if (count($chosen + $set) > $room) {
                $plan['elements'][$index]['state'] = 'cap';
                $plan['matched']--;
                continue;
            }
            $chosen += $set;
            $viaInline += !empty($element['inline']) ? 1 : 0;
            if ($element['sel'] !== '') {
                $held = isset($bySelector[$element['sel']]) ? $bySelector[$element['sel']] : ['kind' => $element['kind'], 'set' => []];
                $held['kind'] = $held['kind'] === 'menu_toggle' || $element['kind'] === 'menu_toggle' ? 'menu_toggle' : $element['kind'];
                $held['set'] += $set;
                $bySelector[$element['sel']] = $held;
            }
        }
        ksort($chosen);
        $plan['ids'] = [];
        foreach (array_keys($chosen) as $position) {
            if (isset($this->script_registry[$position]['id'])) {
                $plan['ids'][] = (string) $this->script_registry[$position]['id'];
            }
        }
        $plan['sel'] = [];
        foreach ($bySelector as $selector => $held) {
            ksort($held['set']);
            $ids = [];
            foreach (array_keys($held['set']) as $position) {
                if (isset($this->script_registry[$position]['id'])) {
                    $ids[] = (string) $this->script_registry[$position]['id'];
                }
            }
            $plan['sel'][(string) $selector] = ['k' => $held['kind'], 'i' => $ids];
        }
        $plan['native'] = [];
        foreach ($plan['elements'] as $element) {
            if ($element['state'] === 'native' && $element['kind'] === 'menu_toggle' && $element['sel'] !== '' && strlen($element['sel']) <= 200 && !in_array($element['sel'], $plan['native'], true)) {
                $plan['native'][] = $element['sel'];
            }
        }
        $plan['native'] = array_slice($plan['native'], 0, 8);
        if (function_exists('wpc_render_belt_note')) {
            $why = [];
            foreach ($plan['elements'] as $element) {
                if ($element['state'] !== 'early') {
                    $why[$element['state']] = (isset($why[$element['state']]) ? $why[$element['state']] : 0) + 1;
                }
            }
            $fields = ['menu_early' => $plan['matched'] . '/' . $plan['entries'], 'menu_early_scripts' => count($plan['ids'])];
            if ($viaInline > 0) {
                $fields['menu_early_inline'] = $viaInline;
            }
            if ($why !== []) {
                ksort($why);
                $pairs = [];
                foreach ($why as $state => $count) {
                    $pairs[] = $state . ':' . $count;
                }
                $fields['menu_early_left'] = implode(',', $pairs);
            }
            if (!empty($field['home'])) {
                $fields['menu_early_home'] = 1;
            }
            wpc_render_belt_note('delay-keep-rules', $fields, true);
        }
        return $plan;
    }

    /**
     * Cohort menu_toggle_live: whether a menu element's set needs jQuery (or cannot be read to say it does not), which keeps it on the lane.
     */
    protected function wpc_menu_live_needs_jquery(array $set)
    {
        foreach (array_keys($set) as $position) {
            $entry = isset($this->script_registry[$position]) ? $this->script_registry[$position] : null;
            if (!is_array($entry)) {
                return true;
            }
            $type = strtolower((string) (isset($entry['type']) ? $entry['type'] : ''));
            if (!in_array($type, ['', 'text/javascript', 'application/javascript'], true) || !empty($entry['async'])) {
                return true;
            }
            $id = (isset($entry['attributes']['id']) && is_string($entry['attributes']['id'])) ? strtolower($entry['attributes']['id']) : '';
            if (preg_match('/^jquery(?:-|$)/', $id)) {
                return true;
            }
            $source = $this->wpc_menu_early_source($entry);
            if ($source === '' || preg_match('/(?<![\w$.])(?:jQuery\b|\$\s*[(.])/', $source)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cohort menu_toggle_live: whether every script the delay parks has an address, so that no parked inline script can be the handler of a toggle the service measured but the page's scripts do not name.
     */
    protected function wpc_menu_live_externals_only()
    {
        foreach ((array) $this->script_registry as $entry) {
            if (!is_array($entry) || empty($entry['src'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Cohort menu_toggle_live: the tag a restored set member is printed as, a deferred script at the place it stood, or null for an entry that cannot be one.
     */
    protected function wpc_menu_live_tag(array $entry)
    {
        $attributes = '';
        foreach ((array) (isset($entry['attributes']) ? $entry['attributes'] : []) as $name => $value) {
            $name = strtolower((string) $name);
            if (!preg_match('/^[a-z][\w:-]*$/', $name) || in_array($name, ['src', 'type', 'defer', 'async', 'priorityrun', 'data-wpc-menu'], true)) {
                continue;
            }
            $attributes .= $value === true ? ' ' . $name : ' ' . $name . '="' . str_replace('"', '&quot;', (string) $value) . '"';
        }
        if (!empty($entry['src'])) {
            $src = !empty($entry['encoded']) ? base64_decode((string) $entry['src'], true) : (string) $entry['src'];
            if (!is_string($src) || $src === '') {
                return null;
            }
            return '<script' . $attributes . ' src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8', false) . '" defer fetchpriority="high" data-wpc-menu="1"></script>';
        }
        $body = isset($entry['content']) && is_string($entry['content']) ? $entry['content'] : '';
        if ($body === '') {
            return null;
        }
        $encoded = !empty($entry['encoded']) ? $body : base64_encode($body);
        return '<script' . $attributes . ' defer src="data:text/javascript;charset=utf-8;base64,' . $encoded . '" data-wpc-menu="1"></script>';
    }

    /**
     * Cohort menu_toggle_live: the document without the script and link tags the cohort gave a priority hint of its own (the scripts it restores, the preloads of those and of the registry
     * sidecar, a binder it raised from no hint at all), so a reader of "the page carries a priority hint" reads what the page carried without the cohort.
     */
    public static function wpc_without_menu_live_priority($html)
    {
        if (!is_string($html) || (stripos($html, 'data-wpc-menu') === false && stripos($html, 'data-wpc-fp') === false)) {
            return $html;
        }
        $bare = preg_replace('/<(?:script|link)\b(?=[^>]*\sdata-wpc-(?:menu|fp)(?:=|\s|>|\/))[^>]*>/i', '', $html);

        return is_string($bare) ? $bare : $html;
    }

    /**
     * Cohort menu_toggle_live: the page script that takes a tap on a first-screen menu toggle the page's own handler has not met yet and gives it back exactly once.
     * It watches the document from the first pointerdown or touchstart on a measured toggle (the click, for a keyboard activation) until the tap is given back. A tap the page answered is
     * left alone: any attribute or child of the document changed while the click was dispatched, or, at any time since the gesture began, a class (other than the plugin's own wpc- ones),
     * hidden or aria-* attribute of the toggle, of its panel (aria-controls, else the next sibling) or of an element around it, except once a click listener has been added to the toggle or to an element around it
     * (what a binder does as it starts up is not an answer). Otherwise the tap is given back once, when the handler is there: 60 ms after the last script load or click listener added to the toggle or
     * to an element around it, 250 ms after the last of them when it was added to the body, the document or the window (or the window load event came after the tap), and at the latest 3 s after the tap
     * (after DOMContentLoaded, for a tap that came before it). What the page did is only ever read to stop, never to click again. $selectors are the toggles the service measured.
     */
    public static function wpc_menu_live_arm_tag(array $selectors)
    {
        $json = json_encode(array_values($selectors), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '' || $json === '[]') {
            return '';
        }
        return '<script id="wpc-menu-live" data-nodefer="1">(function(){var d=document,w=window,S=' . $json . ',rec=null,fin=0,tm=0,ls=0,mo=0,oa=0,pa=0,dd=d.readyState==="complete",EP=w.EventTarget&&w.EventTarget.prototype,'
            . 'P=function(){return w.performance&&performance.now?performance.now():+new Date},'
            . 'T=function(t){try{if(!t||!t.closest||t.closest(".elementor-menu-toggle"))return null;for(var j=0;j<S.length;j++){var c=t.closest(S[j]);if(c)return c}}catch(x){}return null},'
            . 'Q=function(f){try{if(w.scheduler&&w.scheduler.postTask){w.scheduler.postTask(f,{priority:"user-blocking"});return}}catch(x){}setTimeout(f,0)},'
            . 'cu=function(){try{d.removeEventListener("load",ld,!0);d.removeEventListener("error",ld,!0);w.removeEventListener("load",wl);if(mo)mo.disconnect();mo=0;if(oa&&EP.addEventListener===pa){EP.addEventListener=oa;oa=0}}catch(x){}},'
            . 'ab=function(){if(rec)clearTimeout(rec.pt);clearTimeout(tm);cu();rec=null;w.wpcMenuLiveIq=0},'
            . 'end=function(){fin=1;if(rec)clearTimeout(rec.pt);rec=null;clearTimeout(tm);w.wpcMenuLiveDone=1;w.wpcMenuLiveIq=0;cu();try{w.removeEventListener("click",on,!0);w.removeEventListener("pointerdown",st,!0);w.removeEventListener("touchstart",st,!0);'
            . 'w.dispatchEvent(new Event("wpc-menu-live-done"))}catch(x){}},'
            . 'fire=function(){var t=rec&&rec.t;if(t&&t.isConnected){w.wpcMenuLiveFired=t;try{var e=new MouseEvent("click",{bubbles:!0,cancelable:!0,view:w,clientX:rec.x,clientY:rec.y});e.__wpcReplay=!0;t.dispatchEvent(e)}catch(x){}}end()},'
            . 'tick=function(){clearTimeout(tm);if(fin||!rec||!rec.ok)return;var n=P(),at=dd?rec.c+3000:1/0;if(rec.b)at=Math.min(at,ls+60);if(dd&&(rec.e||rec.l))at=Math.min(at,ls+250);if(at===1/0)return;if(at<=n){fire();return}tm=setTimeout(tick,Math.max(at-n,10))},'
            . 'ld=function(e){var t=e&&e.target;if(rec&&t&&t.tagName==="SCRIPT"){ls=P();tick()}},'
            . 'wl=function(){if(rec){rec.l=1;ls=P();tick()}},'
            . 'nb=function(o){ls=P();if(o){if(o.nodeType===1&&o!==d.body&&o!==d.documentElement){if(o===rec.t||o.contains(rec.t))rec.b=1}else if(o===d||o===w||o===d.body||o===d.documentElement)rec.e=1}tick()},'
            . 'cd=function(r,n){var o=(r.oldValue||"").split(/\s+/),c=(n.getAttribute("class")||"").split(/\s+/),k,z=0;'
            . 'for(k=0;k<c.length&&!z;k++)if(c[k]&&o.indexOf(c[k])<0&&c[k].indexOf("wpc-")!==0)z=1;for(k=0;k<o.length&&!z;k++)if(o[k]&&c.indexOf(o[k])<0&&o[k].indexOf("wpc-")!==0)z=1;return z},'
            . 'ow=function(r){var n=r.target,a=r.attributeName;if(!n)return 1;try{if(n.closest&&n.closest("head"))return 1;if(r.type==="attributes"){if(a.indexOf("data-wpc")===0)return 1;if(a==="class"?!cd(r,n):r.oldValue===n.getAttribute(a))return 1}}catch(x){}return 0},'
            . 'tg=function(r){var n=r.target,a=r.attributeName,t=rec.t;if(r.type!=="attributes"||!n||!a||!(a==="class"||a==="hidden"||a.indexOf("aria-")===0))return 0;if(a!=="class"&&r.oldValue===n.getAttribute(a))return 0;'
            . 'if(a==="class"&&!cd(r,n))return 0;'
            . 'if(n.contains&&n.contains(t))return 1;var id=t.getAttribute("aria-controls"),p=id?d.getElementById(id):t.nextElementSibling;return p&&n===p?1:0},'
            . 'sc=function(rs){if(!rec||rec.ans)return;try{var ts=[],as=[],os=[],i,j,r,q;for(i=0;i<rs.length;i++){r=rs[i];q=r;if(r.type==="attributes"){for(j=0;j<ts.length&&!(ts[j]===r.target&&as[j]===r.attributeName);j++);if(j===ts.length){ts.push(r.target);as.push(r.attributeName);os.push(r.oldValue)}q={type:r.type,target:r.target,attributeName:r.attributeName,oldValue:os[j]}}'
            . 'if((rec.iq&&!ow(q))||(!rec.b&&tg(q))){rec.ans=1;break}}}catch(x){}if(rec.ans&&rec.ck)end()},'
            . 'chk=function(){if(fin||!rec)return;var m=[];try{if(mo)m=mo.takeRecords()}catch(x){}sc(m);if(!rec)return;rec.iq=w.wpcMenuLiveIq=0;if(rec.ans){end();return}rec.ok=1;tick()},'
            . 'bg=function(t){rec={t:t,x:0,y:0,ok:0,b:0,e:0,l:0,c:P(),ck:0,ans:0,iq:0,pt:0};ls=rec.c;'
            . 'try{if(w.MutationObserver){mo=new MutationObserver(sc);mo.observe(d.documentElement,{attributes:!0,attributeOldValue:!0,childList:!0,subtree:!0})}}catch(x){mo=0}'
            . 'd.addEventListener("load",ld,!0);d.addEventListener("error",ld,!0);w.addEventListener("load",wl);'
            . 'try{if(EP&&!oa){oa=EP.addEventListener;pa=EP.addEventListener=function(a){try{if(rec&&a==="click")nb(this)}catch(x){}return oa.apply(this,arguments)}}}catch(x){oa=0}},'
            . 'st=function(e){if(fin||rec||e.isTrusted===!1)return;var t=T(e.target);if(!t)return;bg(t);var r=rec;r.pt=setTimeout(function(){if(rec===r&&!r.ck)ab()},1500)},'
            . 'on=function(e){if(fin||e.__wpcReplay||e.isTrusted===!1)return;var t=T(e.target);if(!t)return;if(rec&&rec.ck){end();return}if(rec&&rec.t!==t)ab();if(!rec)bg(t);'
            . 'clearTimeout(rec.pt);rec.ck=1;rec.x=e.clientX;rec.y=e.clientY;rec.iq=w.wpcMenuLiveIq=1;rec.c=P();ls=rec.c;if(rec.ans){end();return}Q(chk)};'
            . 'if(!dd)d.addEventListener("DOMContentLoaded",function(){Q(function(){dd=1;if(rec){rec.c=P();ls=rec.c;tick()}})},{once:!0});'
            . 'w.wpcMenuLiveSel=S;w.wpcMenuLiveChk=function(){if(rec&&rec.iq)chk()};w.addEventListener("pointerdown",st,{capture:!0,passive:!0});w.addEventListener("touchstart",st,{capture:!0,passive:!0});w.addEventListener("click",on,{capture:!0,passive:!0})})();</script>';
    }

    /**
     * Cohort menu_toggle_live: a first-screen menu toggle's handler is live at the first paint of the page, as on the page without the plugin.
     * For each measured menu toggle whose binders the lane would run (state early) and whose set needs no jQuery, the set stands in the
     * document as the deferred scripts it was (in place of their placeholders, out of the registry and the loader config) with its address
     * preloaded, so a tap does not wait for a loader, an idle callback or the chain. A script of the set's handles (-js-extra, -js-before,
     * -js-after, -js-translations) is marked data-wpc-menu so the LCP chain leaves it where it stands. A toggle whose handler is native
     * (state native) or restored is given to a page script that takes a tap before the handler is there and gives it back once
     * (wpc_menu_live_arm_tag). A set that needs jQuery stays on the lane. Returns the document.
     */
    protected function wpc_menu_live_apply($html, array $plan, $pageHtml, $seenSrcIds, array &$cfg)
    {
        if (strpos($html, 'id="wpc-menu-live"') !== false || !preg_match('/<head\b[^>]*>/i', $html)) {
            return $html;
        }
        $toggles = [];
        $earlyToggles = [];
        $restore = [];
        foreach ((array) $plan['elements'] as $element) {
            $selector = (string) $element['sel'];
            if ($element['kind'] !== 'menu_toggle' || $selector === '' || strlen($selector) > 200 || stripos($selector, 'elementor-menu-toggle') !== false) {
                continue;
            }
            if ($element['state'] === 'native' || ($element['state'] === 'unmatched' && $this->wpc_menu_live_externals_only())) {
                $toggles[$selector] = true;
                continue;
            }
            if ($element['state'] !== 'early') {
                continue;
            }
            $set = $this->wpc_menu_early_set($element['regs'], $seenSrcIds, $pageHtml, !empty($element['inline']));
            if (is_array($set) && $this->wpc_menu_live_needs_jquery($set)) {
                $cfg['menuJq'] = 1;
            }
            if (!is_array($set) || $this->wpc_menu_live_needs_jquery($set)) {
                continue;
            }
            $restore += $set;
            $earlyToggles[$selector] = true;
        }
        ksort($restore);
        $replace = [];
        $ids = [];
        $handles = [];
        foreach (array_keys($restore) as $position) {
            $entry = $this->script_registry[$position];
            $tag = $this->wpc_menu_live_tag($entry);
            $holder = '<script type="text/placeholder" data-script-id="' . $entry['id'] . '"></script>';
            if ($tag === null || substr_count($html, $holder) !== 1) {
                $restore = [];
                $replace = [];
                $earlyToggles = [];
                break;
            }
            $replace[$holder] = $tag;
            $ids[$entry['id']] = true;
            if (isset($entry['attributes']['id']) && is_string($entry['attributes']['id']) && $entry['attributes']['id'] !== '') {
                $handles[] = $entry['attributes']['id'];
            }
        }
        $preloads = '';
        if ($replace !== []) {
            $html = strtr($html, $replace);
            foreach (array_keys($restore) as $position) {
                $entry = $this->script_registry[$position];
                if (!empty($entry['src'])) {
                    $src = !empty($entry['encoded']) ? (string) base64_decode((string) $entry['src'], true) : (string) $entry['src'];
                    $cross = isset($entry['attributes']['crossorigin']) ? ($entry['attributes']['crossorigin'] === true ? ' crossorigin' : ' crossorigin="' . str_replace('"', '&quot;', (string) $entry['attributes']['crossorigin']) . '"') : '';
                    $preloads .= '<link rel="preload" as="script" href="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8', false) . '"' . $cross . '>';
                }
                unset($this->script_registry[$position]);
            }
            $this->script_registry = array_values($this->script_registry);
            foreach ($handles as $handle) {
                foreach (['-extra', '-before', '-after', '-translations'] as $suffix) {
                    $html = preg_replace('/<script\b((?=[^>]*\bid=["\']' . preg_quote($handle . $suffix, '/') . '["\'])(?![^>]*\bdata-wpc-menu)[^>]*)>/i', '<script$1 data-wpc-menu="1">', $html, 1);
                }
            }
            if (isset($cfg['menuEarly'])) {
                $cfg['menuEarly'] = array_values(array_diff((array) $cfg['menuEarly'], array_keys($ids)));
                if ($cfg['menuEarly'] === []) {
                    unset($cfg['menuEarly'], $cfg['menuEarlySel'], $cfg['menuEarlyLcp'], $cfg['menuPreTap']);
                } elseif (isset($cfg['menuEarlySel'])) {
                    $left = [];
                    foreach ((array) $cfg['menuEarlySel'] as $selector => $held) {
                        $held['i'] = array_values(array_diff((array) $held['i'], array_keys($ids)));
                        if ($held['i'] !== []) {
                            $left[$selector] = $held;
                        }
                    }
                    if ($left === []) {
                        unset($cfg['menuEarlySel'], $cfg['menuPreTap']);
                    } else {
                        $cfg['menuEarlySel'] = (object) $left;
                    }
                }
            }
        }
        $selectors = array_slice(array_keys($toggles + $earlyToggles), 0, 8);
        $paths = [];
        $field = function_exists('wpc_menu_early_field') ? wpc_menu_early_field() : null;
        foreach ((array) $plan['elements'] as $index => $element) {
            if (!isset($toggles[(string) $element['sel']]) || !isset($field['entries'][$index]['binders'])) {
                continue;
            }
            foreach ((array) $field['entries'][$index]['binders'] as $binder) {
                $path = is_array($binder) && !empty($binder['url']) ? strtolower((string) self::wpc_script_url_path($binder['url'])) : '';
                if ($path !== '') {
                    $paths[$path] = true;
                }
            }
        }
        if ($paths !== []) {
            $html = preg_replace_callback('/<link\b[^>]*\brel=["\']?(?:modulepreload|preload)["\']?[^>]*>/i', function ($m) use ($paths) {
                if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $m[0], $href) && isset($paths[strtolower((string) self::wpc_script_url_path(html_entity_decode($href[1])))])) {
                    return preg_replace('/\bfetchpriority=(["\'])low\1/i', 'fetchpriority=$1high$1', $m[0]);
                }
                return $m[0];
            }, $html);
            $linked = [];
            if (preg_match_all('/<link\b[^>]*\brel=["\']?(?:modulepreload|preload)["\']?[^>]*>/i', $html, $links)) {
                foreach ($links[0] as $link) {
                    if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $link, $href)) {
                        $linked[strtolower((string) self::wpc_script_url_path(html_entity_decode($href[1])))] = true;
                    }
                }
            }
            $html = preg_replace_callback('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', function ($m) use ($paths) {
                if (stripos($m[1], 'data:') === 0 || !isset($paths[strtolower((string) self::wpc_script_url_path(html_entity_decode($m[1])))])) {
                    return $m[0];
                }
                return preg_match('/\bfetchpriority=/i', $m[0]) ? preg_replace('/\bfetchpriority=(["\'])low\1/i', 'fetchpriority=$1high$1', $m[0]) : preg_replace('/<script\b/i', '<script data-wpc-fp="1" fetchpriority="high"', $m[0], 1);
            }, $html);
            if (preg_match_all('/<script\b([^>]*\bsrc=["\']([^"\']+)["\'][^>]*)>/i', $html, $tags, PREG_SET_ORDER)) {
                foreach ($tags as $tag) {
                    $path = strtolower((string) self::wpc_script_url_path(html_entity_decode($tag[2])));
                    if ($path === '' || !isset($paths[$path]) || isset($linked[$path]) || stripos($tag[2], 'data:') === 0) {
                        continue;
                    }
                    $module = preg_match('/\btype=["\']?module/i', $tag[1]) === 1;
                    $cross = preg_match('/\bcrossorigin(?:=("[^"]*"|\'[^\']*\'|[^\s>]+))?/i', $tag[1], $found) ? ' ' . $found[0] : '';
                    $preloads .= '<link rel="' . ($module ? 'modulepreload' : 'preload') . '"' . ($module ? '' : ' as="script"') . ' href="' . $tag[2] . '" fetchpriority="high" data-wpc-fp="1"' . $cross . '>';
                    $linked[$path] = true;
                }
            }
        }
        $arm = self::wpc_menu_live_arm_tag($selectors);
        if (!empty($cfg['menuJq'])) {
            $cfg['menuLive'] = 1;
        }
        if ($arm === '' || !preg_match('/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $cfg['menuLive'] = 1;
        $cfg['menuToggles'] = $selectors;
        $at = $head[0][1] + strlen($head[0][0]);
        return substr($html, 0, $at) . $preloads . $arm . substr($html, $at);
    }

    /**
     * The stylesheets the loader lets in before the first input, as the links of the page name them: the rest sheet
     * (data-wpc-rest), the late sheets, and the parked and combined ones while cohort css_release_idle lets them in.
     * Each as the address the page gives, once.
     */
    protected function wpc_idle_release_sheets($pageHtml, array $cfg)
    {
        $hrefs = [];
        if (!preg_match_all('/<link\b[^>]*>/i', (string) $pageHtml, $tags)) {
            return $hrefs;
        }
        foreach ($tags[0] as $tag) {
            $href = '';
            if (preg_match('/\sdata-wpc-rest\s*=\s*(["\'])(.*?)\1/is', $tag, $rest)) {
                $href = $rest[2];
            } elseif (preg_match('/\srel\s*=\s*["\']?(wpc-(?:mobile-|late-)?stylesheet)["\'\s>\/]/i', $tag, $rel)
                && (!empty($cfg['cssReleaseIdle']) || strtolower($rel[1]) === 'wpc-late-stylesheet')
                && preg_match('/\shref\s*=\s*(["\'])(.*?)\1/is', $tag, $link)) {
                $href = $link[2];
            }
            if ($href !== '') {
                $hrefs[html_entity_decode($href)] = true;
            }
        }
        return array_keys($hrefs);
    }

    /**
     * The hide-until-script pairs (wps_rewriteLogic::wpc_css_hide_until_js_pairs) of the sheets the loader lets in before the
     * first input, kept when the page holds an element with every class of the hidden selector's last compound ('tokens').
     * $why says what stopped the reading: 'sheet-missing' (a sheet of this site that is not on disk), 'sheet-large'.
     * A sheet on another host is not read.
     */
    protected function wpc_idle_release_pairs($pageHtml, array $cfg, &$why)
    {
        static $memo = [];
        $why = '';
        $found = [];
        foreach ($this->wpc_idle_release_sheets($pageHtml, $cfg) as $href) {
            $file = self::wpc_local_sheet_file($href);
            if ($file === null) {
                continue;
            }
            $size = $file === '' ? -1 : (int) @filesize($file);
            if ($size < 0) {
                $why = 'sheet-missing';
                return [];
            }
            if ($size > 2097152) {
                $why = 'sheet-large';
                return [];
            }
            $key = $file . '|' . (int) @filemtime($file) . '|' . $size;
            if (!isset($memo[$key])) {
                $memo[$key] = wps_rewriteLogic::wpc_css_hide_until_js_pairs((string) @file_get_contents($file));
            }
            foreach ($memo[$key] as $row) {
                $found[$row['key'] . '|' . $row['prop'] . '|' . $row['shown']] = $row;
            }
        }
        if ($found === []) {
            return [];
        }
        $values = [];
        if (preg_match_all('/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', (string) $pageHtml, $classes)) {
            $values = array_unique(array_filter(array_merge($classes[1], $classes[2]), 'strlen'));
        }
        $kept = [];
        foreach ($found as $row) {
            $row['tokens'] = self::wpc_menu_early_selector_tokens($row['key']);
            if ($row['tokens'] === []) {
                continue;
            }
            $need = array_flip($row['tokens']);
            foreach ($values as $value) {
                if (array_diff_key($need, array_flip((array) preg_split('/\s+/', trim($value)))) === []) {
                    $kept[] = $row;
                    break;
                }
            }
        }
        return $kept;
    }

    /**
     * The registry positions that bind the pairs: a library script (its text names an init class a pair is shown under) and the
     * scripts that start it (their text names a class of the hidden element, or calls a jQuery plugin a library script defines
     * and does not define it itself). Sorted. [] with $why: 'no-library', 'no-init', 'binders-many', 'heavy', 'pattern'. Scripts
     * held for the first input on purpose (io, force-delayed, analytics, consent) and modules are not read.
     */
    protected function wpc_idle_release_binders(array $pairs, &$why)
    {
        $why = '';
        $shown = [];
        $tokens = [];
        foreach ($pairs as $row) {
            $shown[$row['shown']] = true;
            foreach ($row['tokens'] as $token) {
                $tokens[$token] = true;
            }
        }
        $word = function (array $names) {
            return '/(?<![\w-])(?:' . implode('|', array_map(function ($name) {
                return preg_quote((string) $name, '/');
            }, array_keys($names))) . ')(?![\w-])/';
        };
        $libraryPattern = $word($shown);
        $initPattern = $word($tokens);
        if (@preg_match($libraryPattern, '') === false || @preg_match($initPattern, '') === false) {
            $why = 'pattern';
            return [];
        }
        $texts = [];
        foreach ($this->script_registry as $position => $entry) {
            if (!is_array($entry) || !empty($entry['io']) || strtolower((string) (isset($entry['type']) ? $entry['type'] : '')) === 'module'
                || $this->wpc_menu_early_is_core($entry)) {
                continue;
            }
            $id = (isset($entry['attributes']['id']) && is_string($entry['attributes']['id'])) ? $entry['attributes']['id'] : '';
            $src = empty($entry['src']) ? '' : (!empty($entry['encoded']) ? (string) base64_decode((string) $entry['src'], true) : (string) $entry['src']);
            if ($this->wpc_provider_refused($id, $src)) {
                continue;
            }
            $text = $this->wpc_menu_early_source($entry);
            if ($text !== '') {
                $texts[$position] = $text;
            }
        }
        $library = [];
        $init = [];
        $plugins = [];
        foreach ($texts as $position => $text) {
            if (preg_match($libraryPattern, $text)) {
                $library[$position] = true;
                if (preg_match_all('/\.fn\.([A-Za-z_$][\w$]{3,})\s*=(?!=)/', $text, $defined)) {
                    foreach ($defined[1] as $name) {
                        $plugins[$name] = true;
                    }
                }
            }
            if (preg_match($initPattern, $text)) {
                $init[$position] = true;
            }
        }
        $deny = self::wpc_provider_name_deny();
        foreach (array_keys($plugins) as $name) {
            if (isset($deny[$name])) {
                continue;
            }
            $calls = '/\.' . preg_quote($name, '/') . '\s*\(/';
            $defines = '/\.fn\.' . preg_quote($name, '/') . '\s*=(?!=)/';
            foreach ($texts as $position => $text) {
                if (preg_match($calls, $text) && !preg_match($defines, $text)) {
                    $init[$position] = true;
                }
            }
        }
        if ($library === []) {
            $why = 'no-library';
            return [];
        }
        if ($init === []) {
            $why = 'no-init';
            return [];
        }
        $binders = $library + $init;
        if (count($binders) > (int) apply_filters('wpc_idle_release_binders_cap', 6)) {
            $why = 'binders-many';
            return [];
        }
        $bytes = 0;
        foreach (array_keys($binders) as $position) {
            $bytes += strlen($texts[$position]) >= 409600 ? PHP_INT_MAX >> 2 : strlen($texts[$position]);
        }
        if ($bytes > (int) apply_filters('wpc_idle_release_bytes_cap', 131072)) {
            $why = 'heavy';
            return [];
        }
        $positions = array_keys($binders);
        sort($positions);
        return $positions;
    }

    /**
     * Cohort hidden_until_js_idle: the registry ids of the delayed scripts the loader runs at the idle point where the rest of
     * the page's CSS lands (loader config idleRelease), because that CSS hides an element of the page until a slider library has
     * run on it. The binders (wpc_idle_release_binders) with their closure and inline companions, in document order, as
     * the menu early lane builds a set (wpc_menu_early_set). null while the cohort is off or no sheet of the page hides an
     * element of the page until a script ran; else ['ids', 'pairs', 'why'], ids empty with the reason the closure or the
     * binders were refused, which the belt note (delay-keep-rules) carries as idle_release_why.
     */
    protected function wpc_idle_release_plan($pageHtml, $seenSrcIds, array $cfg)
    {
        if (!function_exists('wpc_policy_cohort') || wpc_policy_cohort('hidden_until_js_idle') !== 1
            || !is_array($this->script_registry) || $this->script_registry === []
            || !class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'wpc_css_hide_until_js_pairs')) {
            return null;
        }
        $why = '';
        $pairs = $this->wpc_idle_release_pairs($pageHtml, $cfg, $why);
        $ids = [];
        if ($pairs !== []) {
            $regs = $this->wpc_idle_release_binders($pairs, $why);
            if ($regs !== []) {
                $set = $this->wpc_menu_early_set($regs, $seenSrcIds, $pageHtml, false, true);
                if (is_string($set)) {
                    $why = $set;
                } else {
                    foreach (array_keys($set) as $position) {
                        if (isset($this->script_registry[$position]['id'])) {
                            $ids[] = (string) $this->script_registry[$position]['id'];
                        }
                    }
                }
            }
        } elseif ($why === '') {
            return null;
        }
        if (function_exists('wpc_render_belt_note')) {
            $fields = ['idle_release' => count($ids), 'idle_release_pairs' => count($pairs)];
            if ($ids === []) {
                $fields['idle_release_why'] = $why;
            }
            wpc_render_belt_note('delay-keep-rules', $fields, true);
        }
        return ['ids' => $ids, 'pairs' => count($pairs), 'why' => $ids === [] ? $why : ''];
    }

    /**
     * The file on disk behind a same-site script URL (the site's host, or our zone with the
     * site's path embedded), or '' for a foreign host, a non-.js path or a missing file.
     */
    public static function wpc_local_script_file($src)
    {
        $url = self::wpc_script_origin_url($src);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && !self::wpc_is_own_host($host)) {
            return '';
        }
        return self::wpc_script_path_file((string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * The file on disk behind a stylesheet URL on the site's host or our zone, '' for one that is not on disk,
     * null for a foreign host: the same reading of the address as for a script (wpc_local_script_file).
     */
    public static function wpc_local_sheet_file($href)
    {
        $url = self::wpc_script_origin_url($href);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && !self::wpc_is_own_host($host)) {
            return null;
        }
        return self::wpc_script_path_file((string) parse_url($url, PHP_URL_PATH), '.css');
    }

    /** A script URL as the site served it: a zone URL carrying the origin after /a: gives that origin. */
    protected static function wpc_script_origin_url($src)
    {
        $src = html_entity_decode((string) $src);
        $origin = self::wpc_script_embedded_origin($src);
        return $origin !== null ? $origin : $src;
    }

    /** What follows the last "/a:" before the URL's query or fragment, or null when the URL carries none. */
    protected static function wpc_script_embedded_origin($src)
    {
        $src = (string) $src;
        $embedded = strrpos((string) substr($src, 0, strcspn($src, '?#')), '/a:');
        return $embedded !== false ? (string) substr($src, $embedded + 3) : null;
    }

    /**
     * The path of a script URL (entities already decoded) as the site serves the file: the origin a zone URL
     * embeds after /a: when it carries one, the host and the query dropped. '' when the URL has no path. The
     * one reading of a script's address for every reader that matches scripts by path (the measured
     * manifest, the menu_early binders).
     */
    protected static function wpc_script_url_path($src)
    {
        $src = (string) $src;
        $embedded = self::wpc_script_embedded_origin($src);
        if ($embedded !== null) {
            if (preg_match('#^https?://#i', $embedded)) {
                $path = parse_url($embedded, PHP_URL_PATH);
            } else {
                $path = strtok($embedded, '?');
                if (is_string($path) && $path !== '' && $path[0] !== '/') {
                    $path = '/' . $path;
                }
            }
        } else {
            $path = parse_url($src, PHP_URL_PATH);
        }
        return is_string($path) ? $path : '';
    }

    /**
     * lower-cased basename => [path => true] over the given `<script src>` tags: how many different files on
     * the page share a basename. A basename that names more than one path names none of them.
     */
    protected function wpc_script_basename_paths(array $tags)
    {
        $basename_paths = [];
        foreach ($tags as $script_tag) {
            $tag_attrs = $this->parse_script_attributes($script_tag);
            if (empty($tag_attrs['src'])) {
                continue;
            }
            $src_path = self::wpc_script_url_path(html_entity_decode((string) $tag_attrs['src']));
            if ($src_path === '') {
                continue;
            }
            $src_basename = strtolower(basename($src_path));
            if ($src_basename === '') {
                continue;
            }
            $basename_paths[$src_basename][$src_path] = true;
        }
        return $basename_paths;
    }

    /** The readable file ending in $ext (.js, or .css for a stylesheet) at a URL path under the content dir or wp-includes, or ''. */
    public static function wpc_script_path_file($path, $ext = '.js')
    {
        $path = '/' . ltrim((string) $path, '/');
        if (substr($path, -strlen($ext)) !== $ext || strpos($path, '..') !== false) {
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
        $real = $file !== '' ? wpc_install_file_real($file) : '';
        return $real !== '' && @is_file($real) && @is_readable($real) ? $real : '';
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
     * What names a parked inline body across two renders of a page: 2 when the script's tag has an id
     * attribute (the WordPress handle, such as gform_gravityforms_theme-js-extra, which a render's
     * nonces and its order never change; the entry already carries it in attributes.id), else the
     * short hash of the body (its registry content, base64) for a script with no id. The registry
     * skeleton's ext and, for a hashed body, the inline form's h.
     */
    public static function wpc_registry_body_name(array $entry)
    {
        if (isset($entry['attributes']['id']) && is_string($entry['attributes']['id']) && $entry['attributes']['id'] !== '') {
            return 2;
        }
        return substr(md5(isset($entry['content']) ? (string) $entry['content'] : ''), 0, 8);
    }

    /**
     * Moves the parked inline script bodies out of the registry into one content-addressed JSON
     * file under $dir and marks each moved entry ext with its body's name (wpc_registry_body_name).
     * The document then carries the registry skeleton (ids, srcs, attributes, order) and the
     * loader fetches the bodies after load, so a page never ships bytes it will not execute
     * before a gesture. A loader that cannot read the file asks the page's inline form for the
     * bodies and takes, for each entry, the body its name picks out: the one whose tag has the same
     * id attribute, or the one with the same hash.
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
                $registry[$wpc_k]['ext'] = self::wpc_registry_body_name($wpc_e);
                unset($registry[$wpc_k]['content']);
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
     * The copy is named by the version and by the first 8 hex characters of the md5 of the loader's bytes
     * (delay-v3-loader-<version>-<hash>.min.js, and ?v=<version>.<hash> on the plugin URL), so the bytes of a
     * name never change under the zone and a browser that keeps a file for a year: a build of the same version
     * with another loader is another URL. Stale by content, not by size (v7.23.17): a same-size edit of the min
     * never reached a copy that kept its name, and the page kept serving the old loader from uploads for a year
     * (immutable). Older copies do not stack (v7.23.17, staging: 4 MB of loaders, ~45 versions): the current one
     * plus the three newest others are kept, ranked by the version in the filename and, within one version, by the
     * time each was made (a heal keeps it); cached HTML that names a deleted copy falls through the onerror
     * fallback to the plugin-dir file. A name this rule does not make is never touched. Retro-heal (v7.22.09):
     * cached HTML pins old filenames, so every surviving copy named without a hash gets the current bytes and cached
     * pages heal on their next cold fetch; a copy named by its bytes is never rewritten. The filename keeps the version: the cache-dress checks and the doctor read it as the
     * "rendered by an older plugin" signal (wpc_loader_in_html).
     */
    private static function wpc_loader_copy_refresh($dir, $name, $source)
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
        $others = [];
        foreach ((array) @glob($dir . 'delay-v3-loader-*.min.js') as $copy) {
            if (basename($copy) !== $name && preg_match('/^delay-v3-loader-([0-9][0-9.]*)(?:-[0-9a-f]{8})?\.min\.js$/', basename($copy), $m)) {
                $others[] = ['copy' => $copy, 'version' => $m[1], 'made' => (int) @filemtime($copy)];
            }
        }
        usort($others, function ($a, $b) {
            $order = version_compare($b['version'], $a['version']);
            if ($order === 0) {
                $order = $b['made'] <=> $a['made'];
            }
            return $order !== 0 ? $order : strcmp($b['copy'], $a['copy']);
        });
        $removed = 0;
        foreach (array_slice($others, max(0, (int) apply_filters('wpc_loader_keep', 3))) as $old) {
            if (@unlink($old['copy'])) {
                $removed++;
            }
        }
        if ($removed && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('loader-gc', '', '', ['n' => $removed]);
        }
        @copy($source, $dir . $name);
        $healed = 0;
        foreach ((array) @glob($dir . 'delay-v3-loader-*.min.js') as $copy) {
            if (basename($copy) !== $name && preg_match('/^delay-v3-loader-[0-9][0-9.]*\.min\.js$/', basename($copy)) && @md5_file($copy) !== $sourceHash) {
                $made = (int) @filemtime($copy);
                if (@copy($source, $copy)) {
                    $made > 0 && @touch($copy, $made);
                    $healed++;
                }
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

    /** The first 8 hex characters of the md5 of the loader file's bytes; '' when it cannot be read. */
    private static function wpc_loader_content_hash($file)
    {
        $md5 = @md5_file($file);
        return is_string($md5) ? substr($md5, 0, 8) : '';
    }

    /**
     * The loader a page names, by either of its two URLs (the uploads copy delay-v3-loader-<version>[-<hash>].min.js, the plugin
     * file delay-v3-loader.min.js?v=<version>[.<hash>]): ['ver' => the plugin version, 'hash' => the 8 hex characters or ''], or null.
     */
    public static function wpc_loader_in_html($html)
    {
        $html = (string) $html;
        if (preg_match('/delay-v3-loader-([0-9][0-9.]*)(?:-([0-9a-f]{8}))?\.min\.js/', $html, $m)) {
            return ['ver' => $m[1], 'hash' => isset($m[2]) ? $m[2] : ''];
        }
        if (preg_match('/delay-v3-loader\.min\.js\?v=([0-9]+(?:\.[0-9]+)*?)(?:\.([0-9a-f]{8}))?(?![0-9a-zA-Z.])/', $html, $m)) {
            return ['ver' => $m[1], 'hash' => isset($m[2]) ? $m[2] : ''];
        }
        return null;
    }

    public static function wpc_loader_script_tag()
    {
        $wpc_loader_base = defined('WPS_IC_URI') ? WPS_IC_URI : plugins_url('/', dirname(__FILE__));
        $wpc_loader_file = (defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'assets/js/delay-v3-loader.min.js'))
            ? 'assets/js/delay-v3-loader.min.js' : 'assets/js/delay-v3-loader.js';
        $pluginVersion = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1';
        $sourceFile = defined('WPS_IC_DIR') ? WPS_IC_DIR . 'assets/js/delay-v3-loader.min.js' : '';
        // The loader's hash is made once per change of its version, mtime or size (the key below) and kept beside the key as
        // "<hash>@<key>", so a render reads two options and does no md5, and a hash that was made for another key is not trusted.
        $wpc_loader_key = $pluginVersion . '|' . (int) @filemtime($sourceFile) . '|' . (int) @filesize($sourceFile);
        $loaderChanged = false;
        $loaderHash = '';
        if ($sourceFile !== '' && function_exists('get_option')) {
            $loaderChanged = get_option('wpc_loader_copy_key') !== $wpc_loader_key;
            if (!$loaderChanged && preg_match('/^([0-9a-f]{8})@(.*)$/s', (string) get_option('wpc_loader_copy_hash'), $storedHash) && $storedHash[2] === $wpc_loader_key) {
                $loaderHash = $storedHash[1];
            } else {
                $loaderChanged = true;
                $loaderHash = self::wpc_loader_content_hash($sourceFile);
            }
        }
        $wpc_loader_ver = $pluginVersion . ($loaderHash !== '' ? '.' . $loaderHash : '');
        $wpc_loader_src = $wpc_loader_base . $wpc_loader_file . '?v=' . $wpc_loader_ver;


        if (defined('WPS_IC_DIR') && function_exists('wp_upload_dir')) {
            try {
                $uploadDir = wp_upload_dir(null, false);
                if (empty($uploadDir['error']) && !empty($uploadDir['basedir']) && !empty($uploadDir['baseurl'])) {
                    $assetsDir  = rtrim($uploadDir['basedir'], '/') . '/wpc-assets/';
                    $copyName = 'delay-v3-loader-' . $pluginVersion . ($loaderHash !== '' ? '-' . $loaderHash : '') . '.min.js';


                    // The copy is rebuilt only when the plugin's loader changed (its version, mtime
                    // or size) or the copy is gone; a render does one stat and one file_exists. The
                    // content compare, the retro-heal of older copies and the GC run inside the
                    // rebuild, once per change, instead of two md5_file() over ~88 KB per render.
                    if ($loaderChanged || !@file_exists($assetsDir . $copyName)) {
                        self::wpc_loader_copy_refresh($assetsDir, $copyName, $sourceFile);
                        $loaderCopied = @file_exists($assetsDir . $copyName);
                        if ($loaderCopied && function_exists('update_option')) {
                            update_option('wpc_loader_copy_hash', $loaderHash . '@' . $wpc_loader_key, true);
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
        $fallbackSrc = $wpc_loader_base . $wpc_loader_file . '?v=' . $wpc_loader_ver;
        $fallbackAttrs = '';
        if ($wpc_loader_src !== $fallbackSrc) {
            $fallbackAttrs = ' data-wpc-lfb="' . esc_url($fallbackSrc) . '"'
                . ' onerror="if(!this.dataset.wpcLfbDone){this.dataset.wpcLfbDone=1;var s=document.createElement(\'script\');s.src=this.dataset.wpcLfb;s.async=true;s.id=\'wpc-delay-v3-loader\';document.head.appendChild(s);}"';
        }
        return ($inlineTag !== '')
            ? $inlineTag
            : '<script id="wpc-delay-v3-loader" src="' . esc_url($wpc_loader_src) . '" async' . $fallbackAttrs . '></script>';
    }

    public function process_html($html, $critDir = '')
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return $html;
        }
        $this->wpc_mod_vendors = self::wpc_module_vendors($html);
        $this->wpc_mod_core = empty($this->wpc_mod_vendors) ? [] : self::wpc_module_core($html);

        $this->wpc_sync_jquery = (bool) preg_match('/<script\b[^>]*\bsrc=["\'][^"\']*(?:wpbf|page-builder-framework|sb-youtube)[^"\']*\.js/i', $html);

        $this->wpc_apply_builder_runtime_keeps($html);
        $wpc_slider_keep = apply_filters('wpc_first_screen_slider_keep', true);
        $this->wpc_first_screen_sliders = $wpc_slider_keep ? self::wpc_breakdance_first_screen_sliders($html) : [];
        $this->wpc_first_screen_sr7_module = $wpc_slider_keep && self::wpc_sr7_first_screen_module($html);
        if ($wpc_slider_keep && function_exists('wpc_render_belt_note') && strpos($html, 'BreakdanceSwiper') !== false) {
            $wpc_slider_by = self::wpc_first_screen_slider_entries() !== null ? 'field' : 'page';
            if ($this->wpc_first_screen_sliders !== [] || $wpc_slider_by === 'field') {
                wpc_render_belt_note('delay-keep-rules', ['breakdance_sliders' => count($this->wpc_first_screen_sliders), 'breakdance_sliders_by' => $wpc_slider_by], true);
            }
        }

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
                        $this->excludes = array_values(array_unique(array_merge((array) $this->excludes, [self::wpc_script_exclude_pattern_for_url((string) $theme_src)])));
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
                        $wpc_index = self::wpc_manifest_keep_index($wpc_m);
                        $this->manifest_rc = $wpc_index['rc'] + $this->manifest_rc;
                        $this->manifest_inline = $wpc_index['inline'] + $this->manifest_inline;
                        $this->manifest_paths = $wpc_index['paths'] + $this->manifest_paths;
                        $this->manifest_names = $wpc_index['names'] + $this->manifest_names;
                    }
                }
            } catch (\Throwable $e) {
            }
        }


        $this->wpc_safe_delay_prepare($html);
        $this->promoted_src_ids = [];
        $wpc_cand_base = [];
        if ((!empty($this->manifest_paths) || !empty($this->manifest_names))
            && preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $wpc_ptags)) {


            if (!empty($this->manifest_names)) {
                $basename_paths = $this->wpc_script_basename_paths($wpc_ptags[0]);
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
                $wpc_pp = self::wpc_script_url_path($wpc_purl);
                if ($wpc_pp === '' || !isset($this->manifest_paths[$wpc_pp])) {
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
                $jq_self_ids = ['jquery-core-js', 'jquery-migrate-js', 'jquery-js'];
                $jq_self_degrade = (bool) apply_filters('wpc_lane_pin_jquery_self_degrade', true);
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
                        if ($jq_self_degrade && array_intersect((array) ($pin['ids'] ?? []), $jq_self_ids)) {
                            $lane_pins[$pin_id]['state'] = 'degraded';
                            if (function_exists('wpc_presc_journal_put')) {
                                wpc_presc_journal_put((string) $pin_id, ['status' => 'report', 'fix' => 'lane-pin',
                                    'class' => (string) ($pin['cl'] ?? ''), 'skipped' => 'jquery-self']);
                            }
                            $pins_changed = true;
                            continue;
                        }
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
                    if ($jq_self_degrade && in_array($target_id, $jq_self_ids, true)) {
                        $degrade = true;
                        $degrade_reason = 'jquery-self';
                    }
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
        $wpc_page_html = $html;
        $html = preg_replace_callback($pattern, array($this, 'process_script_tag'), $html);

        // Integrations (Elementor entrance animations — same as v2)
        $html = $this->elementor_integration($html);

        // Loader config: timeout 0 = interaction-only (default); report = telemetry endpoint
        // (bounded + deduped server-side; disable per-site via the filter).


        $wpc_to = isset(self::$settings['delay-js-v3-timeout']) ? (int) self::$settings['delay-js-v3-timeout'] : 60;
        $wpc_to = (int) self::wpc_knob('delay_timeout', $wpc_to, 1, 240);
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


            'report'  => apply_filters('wpc_delay_v3_telemetry', true) && function_exists('admin_url')
                && !(function_exists('wpc_lever_test_active') && wpc_lever_test_active()) ? admin_url('admin-ajax.php') : '',
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
            'heavyEmbeds' => self::wpc_heavy_embeds(),
            'embedGate' => (int) apply_filters('wpc_delay_embed_gate', 1),


            // v7.10.504 — REACHABILITY FIX. .497 defaulted the atomic cascade OFF, but the flag was
            // only ever READ in the loader and never written into this config, so there was no way to
            // turn it back on. wpcompress.com measured TBT 0ms with it active and TBT 5,900ms with it
            // off (one 6,184ms task; Script Evaluation only 207ms against Other 7,130ms — style
            // recalculation, which is exactly what restoring ~44 sheets one at a time produces).
        ] + self::wpc_css_cfg($critDir);
        if (!empty($wpc_cfg['trusted']) && function_exists('wpc_delay_render_id')) {
            $wpc_cfg['r'] = wpc_delay_render_id(wpc_delay_report_page_path(), function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile() ? 'm' : 'd');
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('menu_fix')) {
            $wpc_cfg['menuFix'] = 1;
        }
        if (function_exists('wpc_deferred_after_lcp_on') && wpc_deferred_after_lcp_on()) {
            $wpc_cfg['tapAfterChain'] = 1;
        }
        if (function_exists('wpc_lever') && in_array(wpc_lever('delay_onload'), [1, '1', 'on', true], true)
            && function_exists('wpc_after_hero_on') && wpc_after_hero_on()) {
            $wpc_cfg['startOnLoad'] = 1;
        }
        $wpc_early = apply_filters('wpc_keep_dep_closure', true) ? $this->wpc_menu_early_plan($wpc_page_html, $seen_src_ids) : null;
        if (is_array($wpc_early) && !empty($wpc_early['ids'])) {
            $wpc_cfg['menuEarly'] = $wpc_early['ids'];
            if (!empty($wpc_early['sel'])) {
                $wpc_cfg['menuEarlySel'] = (object) $wpc_early['sel'];
                if (apply_filters('wpc_menu_early_pretap', true)) {
                    $wpc_cfg['menuPreTap'] = 1;
                }
            }
            if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('menu_early') === 1) {
                $wpc_cfg['menuEarlyLcp'] = (int) apply_filters('wpc_menu_early_lcp_delay', self::wpc_knob('menu_early_lcp', 0, 0, 5000));
            }
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('menu_early') === 1) {
            $wpc_cfg['menuResizeHold'] = 1;
        }
        if (is_array($wpc_early) && function_exists('wpc_menu_toggle_live_on') && wpc_menu_toggle_live_on()) {
            $html = $this->wpc_menu_live_apply($html, $wpc_early, $wpc_page_html, $seen_src_ids, $wpc_cfg);
        }
        if (function_exists('wpc_safe_delay_on') && wpc_safe_delay_on()) {
            $wpc_cfg['lightboxHold'] = 1;
            if (!empty($wpc_cfg['menuLive']) && !empty($wpc_cfg['menuResizeHold'])) {
                $wpc_cfg['menuSnapAtHold'] = 1;
            }
        }
        if (function_exists('wpc_safe_delay_on') && wpc_safe_delay_on('families') && !empty($wpc_cfg['trusted']) && empty($wpc_cfg['timeout'])) {
            $wpc_cfg['trustedFineMove'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('form_submit_hold') === 1) {
            $wpc_cfg['submitHold'] = 1;
        }
        $wpc_idle = apply_filters('wpc_keep_dep_closure', true) ? $this->wpc_idle_release_plan($wpc_page_html, $seen_src_ids, $wpc_cfg) : null;
        if (is_array($wpc_idle) && !empty($wpc_idle['ids'])) {
            $wpc_cfg['idleRelease'] = $wpc_idle['ids'];
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('click_serviced') === 1) {
            $wpc_cfg['clickSkip'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('tap_first') === 1) {
            $wpc_cfg['tapFirst'] = 1;
            if (is_array($wpc_early) && !empty($wpc_early['native'])) {
                $wpc_cfg['tapNative'] = $wpc_early['native'];
            }
        }
        if (function_exists('wpc_after_hero_on') && wpc_after_hero_on()) {
            $wpc_cfg['releaseYield'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('release_no_transition') === 1) {
            $wpc_cfg['releaseNoTransition'] = 1;
        }
        unset($wpc_page_html);

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
        if (!empty($_GET['wpc_registry_inline'])) {
            foreach ($wpc_registry as $wpc_k => $wpc_e) {
                if (is_array($wpc_e) && isset($wpc_e['content']) && is_string($wpc_e['content']) && $wpc_e['content'] !== ''
                    && is_string($wpc_name = self::wpc_registry_body_name($wpc_e))) {
                    $wpc_registry[$wpc_k]['h'] = $wpc_name;
                }
            }
        } elseif (apply_filters('wpc_delay_registry_sidecar', true)) {
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


        $delay_script .= self::wpc_loader_after_lcp_tag(self::wpc_loader_script_tag(), $html);

        // Same anchor the v2 engine uses (printed by enqueues.class.php when delay is on); fall
        // back to </body> so the registry can never be emitted without its loader.
        if (strpos($html, '<script type="wpc-delay-placeholder"></script>') !== false) {
            $html = str_replace('<script type="wpc-delay-placeholder"></script>', $delay_script, $html);
        } else {
            $html = wpc_inject_before_body_close($html, $delay_script);
        }
        if (!empty($wpc_cfg['menuJq']) && !empty($wpc_cfg['menuLive']) && !empty($wpc_cfg['registryUrl']) && !empty($wpc_cfg['menuEarly'])) {
            $wpc_lane_body = false;
            foreach ($wpc_registry as $wpc_e) {
                if (is_array($wpc_e) && isset($wpc_e['id'], $wpc_e['ext']) && in_array($wpc_e['id'], (array) $wpc_cfg['menuEarly'], true)) {
                    $wpc_lane_body = true;
                    break;
                }
            }
            if ($wpc_lane_body) {
                $html = (string) preg_replace('/<head\b[^>]*>/i', '$0<link rel="preload" as="script" href="' . htmlspecialchars((string) $wpc_cfg['registryUrl'], ENT_QUOTES, 'UTF-8', false) . '" fetchpriority="low" data-wpc-fp="1">', $html, 1);
            }
        }

        $html = $this->wpc_defer_remaining_blocking_scripts($html);
        $html = self::wpc_enforce_defer_split_point($html);
        $html = $this->wpc_module_hoist($html);
        $html = $this->wpc_lazy_load_consent_css($html);
        $html = self::wpc_embed_load_recorder($html);
        $html = self::wpc_click_record_tag($html);

        return $html;
    }

    public static function num($const)
    {
        $levers = [
            'LOADER_AFTER_LCP_CAP_MS' => ['loader_after_lcp_cap_ms', 1000, 16000],
            'AFTER_HERO_CAP_MS'       => ['after_hero_cap_ms', 625, 10000],
            'AFTER_HERO_PAINT_MS'     => ['after_hero_paint_ms', 100, 1600],
            'IMG_AFTER_HERO_CAP_MS'   => ['img_after_hero_cap_ms', 1500, 24000],
            'HERO_PAINTED_TIMEOUT_MS' => ['hero_painted_timeout_ms', 750, 12000],
            'AFTER_HERO_ENTRY_CAP_MS' => ['after_hero_entry_cap_ms', 875, 14000],
            'HERO_LQIP_LOAD_CAP_MS'   => ['hero_lqip_load_cap_ms', 2000, 32000],
        ];
        $const = (string) $const;
        $default = defined(self::class . '::' . $const) ? (int) constant(self::class . '::' . $const) : 0;
        if (!isset($levers[$const])) {
            return $default;
        }
        return self::wpc_knob($levers[$const][0], $default, $levers[$const][1], $levers[$const][2]);
    }

    private static function wpc_knob($lever, $default, $min, $max)
    {
        return function_exists('wpc_lever_num') ? wpc_lever_num($lever, $default, $min, $max) : $default;
    }

    public static function wpc_lever_cfg()
    {
        $out = [];
        if (!function_exists('wpc_lever_num')) {
            return $out;
        }
        foreach ([
            'preloadCap'       => ['preload_cap', 1, 12],
            'preloadGapMs'     => ['preload_gap_ms', 0, 1000],
            'cssBarrier'       => ['css_barrier', 0, 8000],
            'lateCssTimerCap'  => ['late_css_timer_cap', 1000, 20000],
            'styleHoldMs'      => ['style_hold_ms', 0, 10000],
            'submitHoldMs'     => ['submit_hold_ms', 0, 10000],
            'menuResizeHoldMs' => ['menu_resize_hold_ms', 0, 120000],
            'loaderPaceMs'     => ['loader_pace_ms', 0, 4000, 0],
        ] as $key => $knob) {
            $value = wpc_lever_num($knob[0], null, $knob[1], $knob[2]);
            if ($value !== null && !(isset($knob[3]) && $value == $knob[3])) {
                $out[$key] = $value;
            }
        }
        return $out;
    }


    /** How long after its starter ran the delay loader is requested when neither a largest-contentful-paint entry nor the window load event has come, in ms. */
    const LOADER_AFTER_LCP_CAP_MS = 4000;

    /** The delay loader is requested after the first largest-contentful-paint entry unless WPC_LOADER_AFTER_LCP_OFF, cohort off_loader_after_lcp or the wpc_loader_after_lcp filter turns it off, or a cohort that already times the loader (after_hero, deferred_after_lcp) is on. */
    public static function wpc_loader_after_lcp_on()
    {
        if (defined('WPC_LOADER_AFTER_LCP_OFF') && WPC_LOADER_AFTER_LCP_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_loader_after_lcp') === 1) {
            return false;
        }
        if ((function_exists('wpc_after_hero_on') && wpc_after_hero_on()) || (function_exists('wpc_deferred_after_lcp_on') && wpc_deferred_after_lcp_on())) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_loader_after_lcp', true) !== false;
    }

    /**
     * The loader's tag as $loaderTag with its request moved after the page's first largest-contentful-paint entry: the tag becomes the inert tag cohort after_hero also leaves (type wpc-late-script, its address in data-wpc-late-src)
     * and a starter beside it swaps it for the real one at the first entry, at the window load event, at a visitor's first gesture or LOADER_AFTER_LCP_CAP_MS after it ran. A tag with no address, a page whose CSP blocks inline scripts and a page with the switch off keep the tag as it was.
     */
    public static function wpc_loader_after_lcp_tag($loaderTag, $html = '')
    {
        if (!is_string($loaderTag) || !self::wpc_loader_after_lcp_on()
            || !preg_match('#^<script id="wpc-delay-v3-loader" src="([^"]+)"(.*)></script>$#s', $loaderTag, $m)) {
            return $loaderTag;
        }
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_csp_blocks_inline_scripts') && wps_cdn_rewrite::wpc_csp_blocks_inline_scripts((string) $html)) {
            return $loaderTag;
        }

        return '<script type="wpc-late-script" data-wpc-late-first="1" data-wpc-late-src="' . $m[1] . '" id="wpc-delay-v3-loader"' . $m[2] . '></script>' . self::wpc_loader_after_lcp_starter();
    }

    /** The chain starters (lever scripts_late, cohort after_hero) end the chain, and so open the deferred-jQuery gate, when their inert tags are already gone at starter time, unless WPC_LATE_CHAIN_GATE_OFF, cohort off_late_chain_gate or the wpc_late_chain_gate filter turns it off. */
    public static function wpc_late_chain_gate_on()
    {
        if (defined('WPC_LATE_CHAIN_GATE_OFF') && WPC_LATE_CHAIN_GATE_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_late_chain_gate') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_late_chain_gate', true) !== false;
    }

    /**
     * The starter wpc_loader_after_lcp_tag prints. It stands for the loader until the browser reports the first largest-contentful-paint entry (a browser without that entry type: two frames after the first paint, or at once without a paint entry either),
     * the window load event or LOADER_AFTER_LCP_CAP_MS, and at once for a visitor who has already acted. A press before it opens is recorded for the loader as cohort after_hero's starter records it (window.wpcPreBootClick).
     */
    public static function wpc_loader_after_lcp_starter()
    {
        $trusted = function_exists('wpc_policy_cohort') && wpc_policy_cohort('delay_trusted');
        $needs = !$trusted && function_exists('wpc_policy_cohort') && wpc_policy_cohort('release_needs_gesture') === 1;
        $seen = $trusted ? 'w.wpcHardSeen===1' : ($needs ? 'w.wpcGestureSeen===1' : 'w.wpcGestureSeen===1||h.classList.contains("wpc-bgl255")');
        $events = $trusted ? '["pointerdown","keydown","touchstart","wheel","click"]' : '["pointerdown","keydown","touchstart","wheel","scroll","mousemove","click"]';

        return '<script id="wpc-loader-after-lcp" data-nodefer="1">(function(){var d=document,w=window,h=d.documentElement,'
            . 'E=' . $events . ',f=0,tm=0,po=0;'
            . 'var sn=function(t){var s=(t.getAttribute("aria-expanded")||"")+"|"+(t.getAttribute("class")||"");try{var c=t.getAttribute("aria-controls"),p=c?d.getElementById(c):t.nextElementSibling;if(p&&p.nodeType===1)s+="|"+(p.getAttribute("class")||"")+"|"+(p.getAttribute("style")||"")+"|"+(p.getAttribute("aria-hidden")||"")+(p.hidden?"H":"")}catch(x){}return s},'
            . 'sc=function(t){var r=[];try{for(var i=0;t&&t.nodeType===1&&i<5;i++,t=t.parentNode){r.push(t,sn(t))}}catch(x){}return r};'
            . 'var sw=function(){[].slice.call(d.querySelectorAll(\'script[type="wpc-late-script"][data-wpc-late-first]\')).forEach(function(o){if(!o.parentNode)return;var s=d.createElement("script");for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name!=="type"&&a.name!=="data-wpc-late-src"&&a.name!=="data-wpc-late-first")s.setAttribute(a.name,a.value)}s.src=o.getAttribute("data-wpc-late-src");o.parentNode.replaceChild(s,o)})};'
            . 'var open=function(g){' . ($trusted ? 'if(g)w.wpcHardSeen=1;' : '') . 'if(g)w.wpcAfterHeroGesture=1;if(f)return;f=1;clearTimeout(tm);try{po&&po.disconnect()}catch(x){}E.forEach(function(e){if(e!=="click")try{w.removeEventListener(e,on,!0)}catch(x){}});'
            . 'sw();if(d.readyState==="loading")d.addEventListener("DOMContentLoaded",sw,{once:!0})};'
            . 'var on=function(e){if(e&&e.isTrusted===!1)return;if(e&&e.type==="scroll"){var q=e.target;if(q&&q!==d&&q!==w&&q!==h&&q!==d.scrollingElement)return}if(w.wpcStartDelayed){try{w.removeEventListener("click",on,!0)}catch(x){}return}'
            . 'if(e&&e.type==="click")w.wpcPreBootClick={ev:e,t:performance.now(),y:w.pageYOffset||0,m:sc(e.target)};open(' . ($needs ? '/^(?:pointerdown|touchstart)$/.test(e.type)?1:0' : '1') . ')};'
            . 'var go=function(){setTimeout(function(){open(0)},0)};'
            . 'try{if(' . $seen . ($trusted || $needs ? '){open(1);return}if((w.pageYOffset||0)>0){open(0);return}' : '||(w.pageYOffset||0)>0){open(1);return}')
            . 'E.forEach(function(e){w.addEventListener(e,on,{passive:!0,capture:!0})});'
            . 'try{if((w.PerformanceObserver.supportedEntryTypes||[]).indexOf("largest-contentful-paint")<0)throw 0;po=new PerformanceObserver(function(l){if(l.getEntries().length)go()});po.observe({type:"largest-contentful-paint",buffered:!0})}'
            . 'catch(x){var fr=function(){var q=w.requestAnimationFrame;if(q){q(function(){q(go)})}else{go()}};try{if((w.PerformanceObserver.supportedEntryTypes||["paint"]).indexOf("paint")<0)throw 0;po=new PerformanceObserver(function(l){var a=l.getEntries();for(var i=0;i<a.length;i++){if(a[i].name==="first-contentful-paint"){try{po.disconnect()}catch(z){}return fr()}}});po.observe({type:"paint",buffered:!0})}catch(y){fr()}}'
            . 'if(d.readyState==="complete"){go()}else{w.addEventListener("load",go,{once:!0})}'
            . 'tm=setTimeout(function(){open(0)},' . self::num('LOADER_AFTER_LCP_CAP_MS') . ')}catch(x){open(0)}})();</script>';
    }

    /**
     * $html with the plugin's body file (the tag with id wpc-body-js) made an inert tag that wpc_loader_after_lcp_starter swaps in beside the loader, so the file is requested after the page's first largest-contentful-paint entry
     * and not at parse. Only a page that carries that starter and a plain tag of the file is changed (a page with cohort after_hero or deferred_after_lcp, a CSP that blocks inline scripts, or the switch off carries no starter). The font runtime's
     * inline script is the one part that stays at parse: a page whose body file serves it gets it back as its own inline script in the place of its config block, so the observer is in place before the loader replays any script that adds a font link.
     */
    public static function wpc_body_js_after_lcp($html)
    {
        if (!is_string($html) || strpos($html, 'id="wpc-body-js"') === false || strpos($html, 'id="wpc-loader-after-lcp"') === false || !self::wpc_loader_after_lcp_on()) {
            return $html;
        }
        $swapped = 0;
        $out = preg_replace_callback('#<script\b([^>]*\sid="wpc-body-js"[^>]*)></script>#i', function ($m) use (&$swapped) {
            if (preg_match('/\stype\s*=/i', $m[1]) || !preg_match('/\ssrc="([^"]*)"/', $m[1], $src) || $src[1] === '') {
                return $m[0];
            }
            $swapped++;

            return '<script type="wpc-late-script" data-wpc-late-first="1" data-wpc-late-src="' . $src[1] . '"' . preg_replace('/\ssrc="[^"]*"/', '', $m[1], 1) . '></script>';
        }, $html);
        if (!is_string($out) || $swapped === 0) {
            return $html;
        }
        if (class_exists('wps_ic_fonts') && method_exists('wps_ic_fonts', 'font_rt_code') && function_exists('wp_json_encode')
            && preg_match('#<script type="application/json" id="wpc-cfg-rt">(.*?)</script>#s', $out, $rt)) {
            $cfg = json_decode($rt[1], true);
            if (is_array($cfg) && array_key_exists('M', $cfg) && isset($cfg['A']) && is_string($cfg['A'])) {
                $inline = '<script id="wpc-font-rt">(function(){try{var M=' . wp_json_encode($cfg['M']) . ',A=' . wp_json_encode($cfg['A']) . ';' . wps_ic_fonts::font_rt_code(true) . '}catch(e){}})();</script>';
                $out = str_replace($rt[0], $inline, $out);
            }
        }

        return $out;
    }

    /** How long after DOMContentLoaded a hero that has not loaded stops holding the delay loader back, in ms. */
    const AFTER_HERO_CAP_MS = 2500;

    /** How long after a hero has loaded the loader waits for the browser to report that hero painted, in ms. */
    const AFTER_HERO_PAINT_MS = 400;

    /** How long after the starter of cohort img_after_hero ran a hero that has neither loaded nor failed stops holding the images below it, in ms. */
    const IMG_AFTER_HERO_CAP_MS = 6000;

    /** How long the hero-painted owner (cohort release_on_lcp_entry, img_hold_first_screen, faces_after_lcp) waits for the browser's largest-contentful-paint entry that names the hero, in ms. */
    const HERO_PAINTED_TIMEOUT_MS = 3000;

    /** How long after DOMContentLoaded the chain starter's cap holds the loader back while the owner waits for the hero's entry, in ms: past the owner's own timeout. */
    const AFTER_HERO_ENTRY_CAP_MS = 3500;

    /**
     * The part of the chain starter both starters share: it reads T (the inert tags) and defines go() and the ready-gate event.
     * With $keepErrors a tag's own error handler stays (the load and the error each end its wait through a listener, not through
     * the property that would replace the handler the page printed).
     */
    public static function wpc_late_chain_js($keepErrors = false)
    {
        return 'var dp=Object.getOwnPropertyDescriptor(document,"addEventListener"),wp=Object.getOwnPropertyDescriptor(window,"addEventListener"),last=T.length,n=0;'
            . 'var late=function(o,t,f){if(typeof f==="function"){setTimeout(function(){try{f.call(o,new Event(t))}catch(e){}},0)}else if(f&&typeof f.handleEvent==="function"){setTimeout(function(){try{f.handleEvent(new Event(t))}catch(e){}},0)}};'
            . 'var done=function(){if(dp){Object.defineProperty(document,"addEventListener",dp)}else{delete document.addEventListener}if(wp){Object.defineProperty(window,"addEventListener",wp)}else{delete window.addEventListener}try{window.dispatchEvent(new Event("wpc-late-chain"))}catch(e){}};'
            . 'var fin=function(){if(++n===last)setTimeout(done,0)};'
            . 'var go=function(){T=T.filter(function(o){return!!o.parentNode});last=T.length;if(!last){setTimeout(done,0);return}'
            . 'document.addEventListener=function(t,f,o){var r=document.readyState;if((t==="DOMContentLoaded"&&r!=="loading")||(t==="readystatechange"&&r==="complete")){late(this,t,f);return}return(dp&&dp.value||EventTarget.prototype.addEventListener).apply(this,arguments)};'
            . 'window.addEventListener=function(t,f,o){var r=document.readyState;if((t==="load"&&r==="complete")||(t==="DOMContentLoaded"&&r!=="loading")){late(this,t,f);return}return(wp&&wp.value||EventTarget.prototype.addEventListener).apply(this,arguments)};'
            . 'T.forEach(function(o){var s=document.createElement("script");for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name!=="type"&&a.name!=="data-wpc-late-src")s.setAttribute(a.name,a.value)}'
            . ($keepErrors ? 's.async=false;s.addEventListener("load",fin);s.addEventListener("error",fin);' : 's.async=false;s.onload=s.onerror=fin;')
            . 's.src=o.getAttribute("data-wpc-late-src");o.parentNode.replaceChild(s,o)})};';
    }

    /**
     * The JS the three hero-gated starters share for a placeholder hero (a hero entry carrying `q`): qm(element) answers 1 when the element matches the `q` of an entry of $list.
     */
    private static function wpc_hero_q_matcher_js($list)
    {
        return 'var qm=function(e){try{for(var j=0;j<' . $list . '.length;j++){if(' . $list . '[j].q&&e&&e.matches&&e.matches(' . $list . '[j].q))return 1}}catch(x){}return 0};';
    }

    /**
     * The starter tag cohort after_hero prints before the body closes: $heroes as wpc_after_hero_heroes() answers, $hasChain when the deferred chain is among the inert tags it swaps. An empty string when the config cannot be encoded.
     * With $lcp (cohort deferred_after_lcp) the window load event also opens it, and a viewport with no measured hero waits for the first image the browser reports as the LCP that
     * covers a fifth of the viewport (a logo does not) instead of opening at once.
     * With $entry (cohort release_on_lcp_entry) a hero for this viewport is waited for through window.wpcHeroPainted (wpc_hero_painted_tag) and nothing else opens it
     * but a gesture or the cap (AFTER_HERO_ENTRY_CAP_MS after DOMContentLoaded): not the load of the hero, not the window load event; a page without the owner is opened as it was.
     */
    public static function wpc_after_hero_starter(array $heroes, $hasChain, $lcp = false, $menuLive = false, $entry = false)
    {
        $config = json_encode(['c' => $entry ? self::num('AFTER_HERO_ENTRY_CAP_MS') : self::num('AFTER_HERO_CAP_MS'), 'p' => self::num('AFTER_HERO_PAINT_MS'), 'h' => $heroes] + ($lcp ? ['l' => 1] : []) + ($entry ? ['e' => 1] : []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($config) || $config === '') {
            return '';
        }
        $trusted = function_exists('wpc_policy_cohort') && wpc_policy_cohort('delay_trusted');
        $needs = !$trusted && function_exists('wpc_policy_cohort') && wpc_policy_cohort('release_needs_gesture') === 1;
        $seen = $trusted ? 'w.wpcHardSeen===1' : ($needs ? 'w.wpcGestureSeen===1' : 'w.wpcGestureSeen===1||h.classList.contains("wpc-bgl255")');
        $events = $trusted ? '["pointerdown","keydown","touchstart","wheel","click"]' : '["pointerdown","keydown","touchstart","wheel","scroll","mousemove","click"]';
        $noHero = $heroes === [];
        $hasQ = false;
        foreach ($heroes as $hero) {
            $noHero = $noHero || !empty($hero['m']);
            $hasQ = $hasQ || !empty($hero['q']);
        }
        $chainOpens = $hasChain && self::wpc_late_chain_gate_on();
        return '<script id="wpc-after-hero" data-nodefer="1">(function(){var d=document,w=window,h=d.documentElement,C=' . $config . ','
            . 'G=[].slice.call(d.querySelectorAll(\'script[type="wpc-late-script"][data-wpc-late-first]\')),T=' . ($hasChain ? '[].slice.call(d.querySelectorAll(\'script[type="wpc-late-script"]:not([data-wpc-late-first])\'))' : '[]') . ','
            . 'E=' . $events . ',f=0,tm=0;'
            . self::wpc_late_chain_js($lcp)
            . 'var sn=function(t){var s=(t.getAttribute("aria-expanded")||"")+"|"+(t.getAttribute("class")||"");try{var c=t.getAttribute("aria-controls"),p=c?d.getElementById(c):t.nextElementSibling;if(p&&p.nodeType===1)s+="|"+(p.getAttribute("class")||"")+"|"+(p.getAttribute("style")||"")+"|"+(p.getAttribute("aria-hidden")||"")+(p.hidden?"H":"")}catch(x){}return s},'
            . 'sc=function(t){var r=[];try{for(var i=0;t&&t.nodeType===1&&i<5;i++,t=t.parentNode){r.push(t,sn(t))}}catch(x){}return r};'
            . ($lcp ? 'var sd=0,cg=function(){if(sd)return;sd=1;go()};' : '')
            . 'var open=function(g){' . ($trusted ? 'if(g)w.wpcHardSeen=1;' : '') . ($lcp ? 'if(g)w.wpcAfterHeroGesture=1;' : '') . 'if(f)return;f=1;clearTimeout(tm);E.forEach(function(e){if(e!=="click")try{w.removeEventListener(e,on,!0)}catch(x){}});w.wpcAfterHero=1;if(g)w.wpcAfterHeroGesture=1;'
            . ($lcp ? 'try{var pl=function(o,p){var u=o.getAttribute("data-wpc-late-src");if(!u||u.indexOf("data:")===0)return;var l=d.createElement("link");l.rel="preload";l.as="script";l.href=u;if(p)l.setAttribute("fetchpriority",p);var x=o.getAttribute("crossorigin");if(x!==null)l.setAttribute("crossorigin",x);var g=o.getAttribute("integrity");if(g)l.setAttribute("integrity",g);(d.head||d.documentElement).appendChild(l)};G.forEach(function(o){pl(o,"")});T.forEach(function(o){pl(o,"low")})}catch(x){}' : '')
            . 'try{G.forEach(function(o){if(!o.parentNode)return;var s=d.createElement("script");for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name!=="type"&&a.name!=="data-wpc-late-src"&&a.name!=="data-wpc-late-first")s.setAttribute(a.name,a.value)}' . ($lcp ? 's.addEventListener("load",cg);s.addEventListener("error",cg);' : '') . 's.src=o.getAttribute("data-wpc-late-src");o.parentNode.replaceChild(s,o)});' . ($chainOpens ? '{' : 'if(T.length){') . ($lcp ? 'if(G.length){setTimeout(cg,1500)}else{cg()}' : 'go()') . '}}catch(x){}};'
            . ($menuLive ? 'var hd=0,mn=function(e){var S=w.wpcMenuLiveSel,t=e&&e.target;if(!S||w.wpcMenuLiveDone||!t||!t.closest)return 0;try{if(t.closest(".elementor-menu-toggle"))return 0;for(var j=0;j<S.length;j++){if(t.closest(S[j])){if(!hd){hd=1;setTimeout(function(){open(1)},1500);w.addEventListener("wpc-menu-live-done",function(){open(1)},{once:!0})}return 1}}}catch(x){}return 0};' : '')
            . 'var on=function(e){if(e&&e.isTrusted===!1)return;if(e&&e.type==="scroll"){var q=e.target;if(q&&q!==d&&q!==w&&q!==h&&q!==d.scrollingElement)return}if(w.wpcStartDelayed){try{w.removeEventListener("click",on,!0)}catch(x){}return}'
            . 'if(e&&e.type==="click")w.wpcPreBootClick={ev:e,t:performance.now(),y:w.pageYOffset||0,m:sc(e.target)};' . ($menuLive ? 'if(mn(e))return;' : '') . 'open(' . ($needs ? '/^(?:pointerdown|touchstart)$/.test(e.type)?1:0' : '1') . ')};'
            . 'try{if(' . $seen . ($trusted || $needs ? '){open(1);return}if((w.pageYOffset||0)>0){open(0);return}' : '||(w.pageYOffset||0)>0){open(1);return}')
            . 'var hs=C.h.filter(function(x){return!x.m||(w.matchMedia&&w.matchMedia(x.m).matches)});if(!hs.length' . ($lcp ? '&&!C.l' : '') . '){open(0);return}'
            . 'E.forEach(function(e){w.addEventListener(e,on,{passive:!0,capture:!0})});'
            . ($entry ? 'if(C.e&&hs.length&&w.wpcHeroPainted){w.wpcHeroPainted(function(){setTimeout(function(){open(0)},0)})}else{' : '')
            . ($hasQ ? self::wpc_hero_q_matcher_js('hs') : '')
            . 'var left=hs.length,want=[],paint=function(){var s=0,po=0,fp=0,st=0,fin=function(){if(s)return;s=1;clearTimeout(st);try{po&&po.disconnect();fp&&fp.disconnect()}catch(x){}setTimeout(function(){open(0)},0)};var sp=function(){if(!st&&!s)st=setTimeout(fin,C.p)};'
            . 'try{fp=new PerformanceObserver(function(l){var a=l.getEntries();for(var i=0;i<a.length;i++){if(a[i].name==="first-contentful-paint"){try{fp.disconnect()}catch(x){}return sp()}}});fp.observe({type:"paint",buffered:!0});if((PerformanceObserver.supportedEntryTypes||["paint"]).indexOf("paint")<0)throw 0}catch(x){try{fp&&fp.disconnect()}catch(x){}if(w.requestAnimationFrame){w.requestAnimationFrame(function(){w.requestAnimationFrame(sp)})}else{sp()}}'
            . 'try{po=new PerformanceObserver(function(l){var a=l.getEntries();for(var i=0;i<a.length;i++){if(want.indexOf(a[i].url)>-1' . ($hasQ ? '||qm(a[i].element)' : '') . ')return fin()}});po.observe({type:"largest-contentful-paint",buffered:!0})}'
            . 'catch(x){if(w.requestAnimationFrame){w.requestAnimationFrame(function(){w.requestAnimationFrame(fin)})}else{fin()}}},after=function(){if(--left>0)return;paint()};'
            . 'hs.forEach(function(x){' . ($hasQ ? 'if(x.q){after();return}' : '') . 'var im=new Image(),r=0,ok=function(){if(r)return;r=1;try{want.push(im.currentSrc||new URL(x.u,d.baseURI).href)}catch(e){}after()};im.onload=im.onerror=ok;if(x.z)im.sizes=x.z;if(x.s)im.srcset=x.s;if(x.u)im.src=x.u;if(im.complete&&(im.currentSrc||im.src))ok()});'
            . ($lcp ? ($noHero ? 'if(!hs.length){try{var q=new PerformanceObserver(function(l){var a=l.getEntries();for(var i=0;i<a.length;i++){if(a[i].url&&a[i].size>=(w.innerWidth||0)*(w.innerHeight||0)*0.2){q.disconnect();setTimeout(function(){open(0)},0);return}}});q.observe({type:"largest-contentful-paint",buffered:!0})}catch(x){}}' : '')
                . 'var ld=function(){setTimeout(function(){open(0)},hs.length?120:0)};if(d.readyState==="complete"){ld()}else{w.addEventListener("load",ld,{once:!0})}' : '')
            . ($entry ? '}' : '')
            . 'var cap=function(){if(!f)tm=setTimeout(function(){open(0)},C.c)};if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",cap,{once:!0})}else{cap()}}catch(x){open(0)}})();</script>';
    }

    /**
     * The starter cohort img_after_hero prints after the quiet-wire restore script: the images that script holds are asked for (the lane's own
     * first-gesture step, window.wpcQwRelease: the ones near the viewport now, the rest as they near it) once the page's measured hero has loaded
     * or failed to, at window load, or at once when no hero of $heroes (as wpc_after_hero_heroes() answers) is for this viewport. The visitor's
     * first gesture is the lane's own. A hero that neither loads nor fails releases them $capMs ms (IMG_AFTER_HERO_CAP_MS when 0) after the starter ran.
     * An empty string when the config cannot be encoded.
     * With $entry (cohorts release_on_lcp_entry, img_hold_first_screen) the images are asked for when window.wpcHeroPainted answers, or at the cap; a page without the owner releases as it did.
     */
    public static function wpc_img_after_hero_starter(array $heroes, $capMs = 0, $entry = false)
    {
        $config = json_encode(['h' => $heroes, 'c' => (int) $capMs > 0 ? (int) $capMs : self::num('IMG_AFTER_HERO_CAP_MS')] + ($entry ? ['e' => 1] : []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($config) || $config === '') {
            return '';
        }
        $hasQ = false;
        foreach ($heroes as $hero) {
            $hasQ = $hasQ || !empty($hero['q']);
        }
        return '<script id="wpc-img-after-hero" data-nodefer="1">(function(){var d=document,w=window,C=' . $config . ',f=0,'
            . 'go=function(){if(f)return;f=1;try{if(w.wpcQwRelease)w.wpcQwRelease()}catch(x){}};'
            . 'try{' . ($entry ? 'if(C.e&&w.wpcHeroPainted){setTimeout(go,C.c);w.wpcHeroPainted(go);return}' : '') . 'if(d.readyState==="complete"){go();return}w.addEventListener("load",go,{once:!0});'
            . 'var hs=C.h.filter(function(x){return!x.m||(w.matchMedia&&w.matchMedia(x.m).matches)});if(!hs.length){go();return}'
            . 'setTimeout(go,C.c);'
            . 'var left=hs.length;' . ($hasQ ? self::wpc_hero_q_matcher_js('hs') : '') . 'hs.forEach(function(x){var im=new Image(),r=0,ok=function(){if(r)return;r=1;if(--left<1)go()};'
            . ($hasQ ? 'if(x.q){var pq=0,fq=function(){if(r)return;try{pq&&pq.disconnect()}catch(e){}ok()};try{pq=new PerformanceObserver(function(l){var a=l.getEntries();for(var i=0;i<a.length;i++){if(qm(a[i].element))return fq()}});pq.observe({type:"largest-contentful-paint",buffered:!0})}catch(e){if(w.requestAnimationFrame){w.requestAnimationFrame(function(){w.requestAnimationFrame(fq)})}else{fq()}}return}' : '')
            . 'im.onload=im.onerror=ok;if(x.z)im.sizes=x.z;if(x.s)im.srcset=x.s;if(x.u)im.src=x.u;if(im.complete&&(im.currentSrc||im.src))ok()})}catch(x){go()}})();</script>';
    }

    /**
     * The one owner of "the hero has painted" (cohorts release_on_lcp_entry, img_hold_first_screen, faces_after_lcp): the script tag that decides when the page's
     * measured hero (as wpc_after_hero_heroes() answers: u, s, z, m) is on screen, and tells whoever asked. window.wpcHeroPainted(callback) runs the callback once,
     * with how it came about, as soon as that is known, at once if it already is. The browser's largest-contentful-paint entry whose address is the hero's (an
     * address of its srcset, or the same file under another size or extension) is "entry"; $timeoutMs (HERO_PAINTED_TIMEOUT_MS when 0) after the script ran is
     * "timeout"; a viewport none of the heroes is for is "none"; a browser that does not report the entry type waits for the window load event, "load"; a script
     * that throws is "error". With $swap (a page printing the hero placeholder), window.wpcHeroPaintedBy() asks the owner to look again: once the placeholder swap
     * has decoded the real file and taken the placeholder off (window.wpcHeroLqip "swapped", before or after this script ran) the answer is "swap". window.wpcHeroPaintedHow and window.wpcHeroPaintedAt (ms) keep the answer. An empty string when the config cannot be encoded.
     */
    public static function wpc_hero_painted_tag(array $heroes, $timeoutMs = 0, $swap = false)
    {
        $config = json_encode(['h' => $heroes, 't' => (int) $timeoutMs > 0 ? (int) $timeoutMs : self::num('HERO_PAINTED_TIMEOUT_MS')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($config) || $config === '') {
            return '';
        }
        $hasQ = false;
        foreach ($heroes as $hero) {
            $hasQ = $hasQ || !empty($hero['q']);
        }
        return '<script id="wpc-hero-painted" data-nodefer="1">(function(){var w=window,d=document,C=' . $config . ',Q=[],f=0,tm=0,po=0,W=[],S=[];'
            . 'var fire=function(h){if(f)return;f=1;w.wpcHeroPaintedHow=h;w.wpcHeroPaintedAt=Math.round(w.performance&&performance.now?performance.now():0);clearTimeout(tm);try{po&&po.disconnect()}catch(x){}'
            . 'Q.splice(0).forEach(function(cb){try{cb(h)}catch(x){}})};'
            . 'w.wpcHeroPainted=function(cb){if(typeof cb!=="function")return;if(f){setTimeout(function(){try{cb(w.wpcHeroPaintedHow)}catch(x){}},0)}else{Q.push(cb)}};'
            . 'var st=function(u){u=String(u||"").split("#")[0].split("?")[0];u=u.split("/").pop().toLowerCase().replace(/\.[a-z0-9]{2,5}$/,"").replace(/\.(?:jpe?g|png|gif|webp|avif)$/,"");return u.replace(/(?:-\d+x\d+|-\d{2,5}w|-scaled|@\dx)+$/,"")};'
            . 'var add=function(u){try{u=new URL(u,d.baseURI).href}catch(x){return}if(u.indexOf("data:")===0||W.indexOf(u)>-1)return;W.push(u);var s=st(u);if(s&&S.indexOf(s)<0)S.push(s)};'
            . 'var hit=function(u){if(!u||u.indexOf("data:")===0)return 0;if(W.indexOf(u)>-1)return 1;var s=st(u);return s&&S.indexOf(s)>-1?1:0};'
            . 'try{var hs=C.h.filter(function(x){return!x.m||(w.matchMedia&&w.matchMedia(x.m).matches)});if(!hs.length){fire("none");return}'
            . ($hasQ ? self::wpc_hero_q_matcher_js('hs') : '')
            . 'hs.forEach(function(x){if(x.u)add(x.u);if(x.s)String(x.s).split(",").forEach(function(c){c=c.trim().split(/\s+/)[0];if(c)add(c)})});'
            . 'var ok=0;try{ok=!!(w.PerformanceObserver&&PerformanceObserver.supportedEntryTypes&&PerformanceObserver.supportedEntryTypes.indexOf("largest-contentful-paint")>-1)}catch(x){}'
            . 'if(ok){try{po=new PerformanceObserver(function(l){var a=l.getEntries();for(var i=0;i<a.length;i++){if(hit(a[i].url)' . ($hasQ ? '||qm(a[i].element)' : '') . '){fire("entry");return}}});po.observe({type:"largest-contentful-paint",buffered:!0});tm=setTimeout(function(){fire("timeout")},C.t)}catch(x){ok=0;po=0}}'
            . ($swap ? 'w.wpcHeroPaintedBy=function(){if(w.wpcHeroLqip==="swapped")fire("swap")};w.wpcHeroPaintedBy();if(f)return;' : '')
            . 'if(!ok){if(d.readyState==="complete"){fire("load")}else{w.addEventListener("load",function(){fire("load")},{once:!0})}}}catch(x){fire("error")}})();</script>';
    }

    /** How long after DOMContentLoaded the inline hero placeholder's swap starts its fetch when the window load event has not fired, and how long it waits for that fetch to settle, in ms. */
    const HERO_LQIP_LOAD_CAP_MS = 8000;

    /**
     * The script tag that takes the inline hero placeholder (`<style id="wpc-hero-lqip">`) off the page once the real hero is in the browser's
     * hands. $selector is the box that paints the hero, $sig the record's signature, $media the media query the placeholder's rule is wrapped in.
     * A browser where the query does not match (or cannot be asked) has the placeholder removed at once, window.wpcHeroLqip is "media", nothing is
     * stored and nothing is fetched. A visitor whose localStorage `wpcLqip` already holds the
     * signature has the file in the HTTP cache: the placeholder is removed before the first paint and window.wpcHeroLqip is "warm". Otherwise the
     * placeholder's style element, printed with media "not all", is switched on (media "all") before the first paint, and at
     * the window load event (HERO_LQIP_LOAD_CAP_MS after DOMContentLoaded when it has not fired), the page's own cascade is asked for the box's
     * background-image with the placeholder's sheet disabled and the box's transitions held off (the sheet enabled, the box's style read again and its own inline
     * transition-property put back in the same task, its other transition declarations untouched, so a box with a background transition is read at its new value and starts no transition), and the one file it names is fetched and
     * decoded; a value that is an image-set is fetched through a hidden <picture> of the same entries, so the browser makes the choice it makes
     * for the CSS. The placeholder goes when the file has decoded and the placeholder has been painted (the hero element's largest-contentful-paint entry, or 1500 ms and two frames, or two frames where the browser reports no such entries) or failed, or HERO_LQIP_LOAD_CAP_MS after the fetch began, and the signature
     * is stored only for a file that decoded; window.wpcHeroLqip is then "swapped" or "dropped". No box, no image layer or more than one, or a data: address: the placeholder goes at once and the
     * page loads what its own CSS names. An empty string when the config cannot be encoded.
     */
    public static function wpc_hero_lqip_swap_tag($selector, $sig, $media)
    {
        $config = json_encode(['q' => (string) $selector, 'g' => (string) $sig, 'm' => (string) $media, 'c' => self::num('HERO_LQIP_LOAD_CAP_MS')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($config) || $config === '') {
            return '';
        }
        $body = <<<'JS'
var d=document,w=window,C=%CONFIG%,K="wpcLqip",S=d.getElementById("wpc-hero-lqip"),n=0,r=0,el=null;
if(!S)return;
var rm=function(){var e=d.getElementById("wpc-hero-lqip");if(e&&e.parentNode)e.parentNode.removeChild(e)},
st=function(){try{var a=(w.localStorage.getItem(K)||"").split(" ").filter(function(x){return x&&x!==C.g});a.push(C.g);w.localStorage.setItem(K,a.slice(-20).join(" "))}catch(x){}},
fin=function(v){if(n)return;n=1;rm();w.wpcHeroLqip=v?"swapped":"dropped";if(v){st();if(typeof w.wpcHeroPaintedBy==="function")w.wpcHeroPaintedBy()}};
var pf=function(f){var raf=w.requestAnimationFrame,P=w.PerformanceObserver,dn=0,o=null,fz=function(){if(dn)return;dn=1;try{if(o)o.disconnect()}catch(x){}if(!n)f()},two=function(){raf(function(){raf(fz)})};
if(typeof raf!=="function"){fz();return}
try{if(P&&P.supportedEntryTypes&&P.supportedEntryTypes.indexOf("largest-contentful-paint")>-1){o=new P(function(l){var e=l.getEntries(),i,h;for(i=0;i<e.length;i++){h=e[i].element;if(h&&(h===el||(el&&el.contains&&el.contains(h)))){fz();return}}});o.observe({type:"largest-contentful-paint",buffered:true});setTimeout(two,1500);return}}catch(x){}
two()};
var mm=0;try{mm=w.matchMedia&&w.matchMedia(C.m).matches}catch(x){}
if(!mm){n=1;rm();w.wpcHeroLqip="media";return}
try{if((w.localStorage.getItem(K)||"").split(" ").indexOf(C.g)>-1){n=1;rm();w.wpcHeroLqip="warm";return}}catch(x){}
S.setAttribute("media","all");
var one=function(u){var i=new Image(),t=setTimeout(function(){fin(0)},C.c),ok=function(){pf(function(){clearTimeout(t);fin(1)})},bad=function(){clearTimeout(t);fin(0)};
if(i.decode){i.src=u;i.decode().then(ok,bad)}else{i.onload=ok;i.onerror=bad;i.src=u}},
set=function(b){var a=b.search(/image-set\(/i),o=b.indexOf("(",a),k=1,j=o+1,q="",c,inner,rest,ps=[],cur="",dp=0,es=[],m,x,y,e,p,im,gs=[],un=[],gi,z,t;
for(;j<b.length&&k>0;j++){c=b.charAt(j);if(q){if(c===q)q=""}else if(c==='"'||c==="'")q=c;else if(c==="(")k++;else if(c===")")k--}
if(k){fin(0);return}
inner=b.slice(o+1,j-1);rest=b.slice(0,a)+b.slice(j);
if(/url\(|image-set\(/i.test(rest)){fin(0);return}
q="";
for(j=0;j<inner.length;j++){c=inner.charAt(j);if(q){if(c===q)q=""}else if(c==='"'||c==="'")q=c;else if(c==="(")dp++;else if(c===")")dp--;if(c===","&&!dp&&!q){ps.push(cur);cur=""}else cur+=c}
ps.push(cur);
for(j=0;j<ps.length;j++){m=ps[j].match(/^\s*url\(\s*(?:"([^"]*)"|'([^']*)'|([^)\s"']*))\s*\)([\s\S]*)$/i);if(!m){fin(0);return}
x=m[1]||m[2]||m[3]||"";if(!x||/^data:/i.test(x)){fin(0);return}
y=m[4].match(/(\d*\.?\d+)\s*(?:x|dppx)\b/i);e=m[4].match(/type\(\s*(?:"([^"]*)"|'([^']*)')\s*\)/i);
es.push({u:x,r:y?y[1]:"1",t:e?(e[1]||e[2]||""):""})}
if(!es.length){fin(0);return}
p=d.createElement("picture");im=d.createElement("img");
for(j=0;j<es.length;j++){e=es[j];if(!un.length&&e.t){gi=-1;for(x=0;x<gs.length;x++){if(gs[x].t===e.t)gi=x}if(gi<0){gs.push({t:e.t,c:[]});gi=gs.length-1}gs[gi].c.push(e.u+" "+e.r+"x")}else un.push(e.u+" "+e.r+"x")}
gs.forEach(function(G){var s=d.createElement("source");s.setAttribute("type",G.t);s.setAttribute("srcset",G.c.join(", "));p.appendChild(s)});
if(un.length)im.setAttribute("srcset",un.join(", "));else im.setAttribute("src",es[es.length-1].u);
p.setAttribute("style","position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden");
p.appendChild(im);(d.body||d.documentElement).appendChild(p);
z=function(v){clearTimeout(t);try{if(p.parentNode)p.parentNode.removeChild(p)}catch(x){}fin(v)};
t=setTimeout(function(){z(0)},C.c);
if(im.decode){im.decode().then(function(){pf(function(){z(1)})},function(){z(0)})}else{im.onload=function(){pf(function(){z(1)})};im.onerror=function(){z(0)}}},
run=function(){var b="",sh=null,u=[],sy=null,tv="",tp="",re=/url\(\s*(?:"([^"]*)"|'([^']*)'|([^)\s"']*))\s*\)/gi;
try{try{el=d.querySelector(C.q)}catch(x){}
if(!el){fin(0);return}
try{sh=S.sheet}catch(x){}
try{sy=el.style;tv=sy.getPropertyValue("transition-property");tp=sy.getPropertyPriority("transition-property");sy.setProperty("transition-property","none","important")}catch(x){sy=null}
try{if(sh)sh.disabled=true;b=w.getComputedStyle(el).backgroundImage||""}catch(x){b=""}finally{if(sh){try{sh.disabled=false}catch(x){}}if(sy){try{w.getComputedStyle(el).backgroundImage;sy.removeProperty("transition-property");if(tv)sy.setProperty("transition-property",tv,tp)}catch(x){}}}
if(/image-set\(/i.test(b)){set(b);return}
b.replace(re,function(a,p1,p2,p3){u.push(p1||p2||p3||"")});
if(u.length!==1||!u[0]||/^data:/i.test(u[0])){fin(0);return}
one(u[0])}catch(x){fin(0)}},
go=function(){if(r)return;r=1;run()};
if(d.readyState==="complete"){go()}else{w.addEventListener("load",go);var cap=function(){setTimeout(go,C.c)};if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",cap)}else{cap()}}
JS;
        $body = str_replace('%CONFIG%', $config, (string) preg_replace('/\s*\n\s*/', '', $body));
        if (self::heroLqipAfterPaintOn()) {
            $gated = $body;
            $hits = 0;
            foreach ([
                'n=0,r=0,el=null;' => 'n=0,r=0,el=null,pt=0;',
                'setTimeout(two,1500);return}' => 'setTimeout(two,pt?0:1500);return}',
                'go=function(){if(r)return;r=1;run()};' =>
                    'pg=function(f){var P=w.PerformanceObserver,dn=0,o=null,g=function(){if(dn)return;dn=1;try{if(o)o.disconnect()}catch(x){}f()};'
                    . 'try{if(P&&P.supportedEntryTypes&&P.supportedEntryTypes.indexOf("largest-contentful-paint")>-1){o=new P(function(l){var e=l.getEntries(),i;'
                    . 'for(i=0;i<e.length;i++){if(e[i].element===el&&e[i].url){g();return}}});o.observe({type:"largest-contentful-paint",buffered:true});'
                    . 'setTimeout(function(){if(!dn)pt=1;g()},1500);return}}catch(x){}g()},'
                    . 'go=function(){if(r)return;r=1;try{el=d.querySelector(C.q)}catch(x){}if(!el){run();return}pg(run)};',
            ] as $from => $to) {
                if (substr_count($gated, $from) === 1) {
                    $gated = str_replace($from, $to, $gated);
                    $hits++;
                }
            }
            if ($hits === 3) {
                $body = $gated;
            }
        }

        return '<script id="wpc-hero-lqip-swap" data-nodefer="1">(function(){' . $body . '})();</script>';
    }

    public static function heroLqipAfterPaintOn()
    {
        if (defined('WPC_HERO_LQIP_AFTER_PAINT_OFF') && WPC_HERO_LQIP_AFTER_PAINT_OFF) {
            return false;
        }
        if (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_hero_lqip_after_paint') === 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_hero_lqip_after_paint', true) !== false;
    }

    /**
     * The first script of the head: it records the elements a click listener is bound to from the first
     * script on, so the loader's held-click replay (wpcClickServiced) also sees a listener a kept script bound
     * before the loader booted. It only adds the element to a WeakSet and calls the native method; the loader
     * takes the set over at boot and puts the native method back in its own patch. Once per page. Only for a site
     * the service has put on cohort click_record (absent or 0: no tag, the page as it was).
     */
    public static function wpc_click_record_tag($html)
    {
        if (!is_string($html) || $html === '' || strpos($html, 'id="wpc-click-record"') !== false
            || !function_exists('wpc_policy_cohort') || wpc_policy_cohort('click_record') !== 1
            || !apply_filters('wpc_click_record', true)
            || !preg_match('/<head\b[^>]*>/i', $html, $wpc_m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $wpc_tag = '<script data-nodefer="1" id="wpc-click-record">(function(){try{var P=EventTarget.prototype,a=P.addEventListener;'
            . 'if(a.__wpcCR||typeof WeakSet!=="function")return;var s=window.wpcClickBoundEls=new WeakSet,'
            . 'w=function(t,l){if(t==="click"&&this&&this.nodeType===1){try{s.add(this)}catch(e){}}return a.apply(this,arguments)};'
            . 'w.__wpcCR=a;P.addEventListener=w}catch(x){}})();</script>';
        $wpc_at = $wpc_m[0][1] + strlen($wpc_m[0][0]);
        return substr($html, 0, $wpc_at) . $wpc_tag . substr($html, $wpc_at);
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
        // A bare existence test (typeof jQuery, typeof window.jQuery) does not use jQuery and
        // runs fine before it, so it must not keep jQuery blocking; every other mention still
        // does. Counting it as use kept jQuery render-blocking on WooCommerce /shop/ pages once
        // the Disable Cart Fragments loader waited for jQuery with a typeof check, and changed
        // how /shop/ loads on every store with that option on.
        // "typeof jQuery.fn" or "typeof jQuery(...)" reads jQuery itself and stays a use.
        $executableInline = (string) preg_replace('/\btypeof\s+(?:window\s*\.\s*)?jQuery\b(?!\s*[.(\[])/', '', $executableInline);
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
    public static function wpc_css_cfg($critDir = '')
    {
        $cfg = [
            'atomicCascade' => (int) apply_filters('wpc_atomic_cascade', 1),

            // v7.10.639 — renamed: the signals are engagement evidence (gesture, hover,
            // referrer, prior completed visit), not humanity detection. Old filter still
            // honored; the loader reads the old KEY too (cached pages emit it for a while).
            'engagementSignals' => apply_filters('wpc_delay_engagement_signals', apply_filters('wpc_delay_human_signals', true)) ? 1 : 0,


            'atfReveal' => apply_filters('wpc_delay_atf_anim_reveal', true) ? 1 : 0,


            'lateCssBackstop' => (int) apply_filters('wpc_delay_latecss_backstop', self::wpc_knob('late_css_backstop', 30000, 7500, 120000)),

            // v7.10.714 — the no-gesture late-CSS lane (loadEventEnd + this delay) must start
            // its fetches well past the point where the page has finished painting: an edge-warm
            // font completing near the LCP candidate window re-enters the simulated dependency
            // chain even though it changes no pixels above the fold (data: subsets + metric
            // fallbacks carry ATF text either way). 2500ms clears that window at any cache
            // temperature; every gesture path is untouched and still flips immediately.
            'lateCssTimer' => (int) apply_filters('wpc_delay_latecss_timer', self::wpc_knob('late_css_timer', 2500, 625, 10000)),
            'lateCssLcp' => (int) apply_filters('wpc_delay_latecss_lcp', self::wpc_knob('late_css_lcp', 2000, 500, 8000)),
            'lateCssCap' => (int) apply_filters('wpc_delay_latecss_cap', self::wpc_knob('late_css_cap', 7000, 1750, 28000)),


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
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('delay_trusted')) {
            $cfg['trusted'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('late_faces_handover') === 1) {
            $cfg['lateFacesHandover'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('late_faces_below_fold') === 1) {
            $cfg['lateFacesBelowFold'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('release_needs_gesture') === 1) {
            $cfg['releaseNeedsGesture'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('css_release_idle') === 1) {
            $cfg['cssReleaseIdle'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('crit_retire') === 1) {
            $cfg['critRetire'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('rest_swap_hold') === 1) {
            $cfg['restSwapHold'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('swap_no_transition') === 1) {
            $cfg['swapNoTransition'] = 1;
        }
        if (function_exists('wpc_policy_cohort') && wpc_policy_cohort('off_retire_live_gate') === 1) {
            $cfg['retireLiveOff'] = 1;
        }
        $restAtCrit = function_exists('wpc_policy_cohort') && wpc_policy_cohort('rest_at_crit') === 1
            && !(defined('WPC_REST_AT_CRIT_OFF') && WPC_REST_AT_CRIT_OFF)
            && wpc_policy_cohort('off_rest_at_crit') !== 1
            && apply_filters('wpc_rest_at_crit', true) !== false;
        if ($restAtCrit) {
            $cfg['restAtCritOff'] = 0;
        }
        if ((defined('WPC_EL_PIN_REACH_OFF') && WPC_EL_PIN_REACH_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_el_pin_reach') === 1)
            || apply_filters('wpc_el_pin_reach', true) === false) {
            $cfg['elPinReachOff'] = 1;
        }
        if ((defined('WPC_IMG_LOAD_UNHOLD_OFF') && WPC_IMG_LOAD_UNHOLD_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_img_load_unhold') === 1)
            || apply_filters('wpc_img_load_unhold', true) === false) {
            $cfg['imgLoadHold'] = 1;
        }
        if ((defined('WPC_EL_STRETCH_FIT_OFF') && WPC_EL_STRETCH_FIT_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_el_stretch_fit') === 1)
            || apply_filters('wpc_el_stretch_fit', true) === false) {
            $cfg['elStretchFitOff'] = 1;
        }
        if ((defined('WPC_EL_HEAL_PX_OFF') && WPC_EL_HEAL_PX_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_el_heal_px') === 1)
            || apply_filters('wpc_el_heal_px', true) === false) {
            $cfg['elementorHealPxWidth'] = 0;
        }
        if ((defined('WPC_CV_TRANSITION_GUARD_OFF') && WPC_CV_TRANSITION_GUARD_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_cv_transition_guard') === 1)
            || apply_filters('wpc_cv_transition_guard', true) === false) {
            $cfg['cvGuardOff'] = 1;
        }
        if ((defined('WPC_REST_FOCUS_GUARD_OFF') && WPC_REST_FOCUS_GUARD_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_rest_focus_guard') === 1)
            || apply_filters('wpc_rest_focus_guard', true) === false) {
            $cfg['restFocusGuardOff'] = 1;
        }
        $gfFirst = self::wpc_gf_hold_first($critDir);
        if ($gfFirst !== null) {
            $cfg['gfHold'] = 1;
            $cfg['gfFirst'] = $gfFirst;
        }
        $cfg += self::wpc_lever_cfg();
        return $cfg;
    }

    /**
     * The first-screen family|weight|style census a Google/Bunny Fonts sheet is checked against before the loader
     * holds it to the first gesture (wpcGfHeld in delay-v3-loader.js), read from the crit dir the render resolved:
     * split crit = the requesting device's census, combined crit = the union of both and only when both have one.
     * null = no hold (killed, no crit dir, or a census missing), so the sheet is released early.
     */
    private static function wpc_gf_hold_first($critDir)
    {
        if ((defined('WPC_GF_HOLD_OFF') && WPC_GF_HOLD_OFF)
            || (function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_gf_hold') === 1)
            || !class_exists('wps_rewriteLogic') || !class_exists('wps_cdn_rewrite') || !method_exists('wps_cdn_rewrite', 'wpc_first_screen_census')) {
            return null;
        }
        $dir = rtrim((string) $critDir, '/');
        if ($dir === '' || strpos($dir, '..') !== false) {
            return null;
        }
        $dir .= '/';
        $devices = wps_rewriteLogic::wpc_combined_crit_on() ? ['mobile', 'desktop'] : [!empty(wps_rewriteLogic::$isMobile) ? 'mobile' : 'desktop'];
        $tuples = [];
        foreach ($devices as $device) {
            $rows = wps_cdn_rewrite::wpc_first_screen_census($dir, $device);
            if ($rows === []) {
                return null;
            }
            foreach ($rows as $row) {
                $tuples[$row['family'] . '|' . $row['weight'] . '|' . $row['style']] = true;
                if (count($tuples) > 96) {
                    return null;
                }
            }
        }
        return $tuples === [] ? null : array_keys($tuples);
    }

    /** The classes the loader's own sweep reveals on a first screen: the entrance-animation class and the conceal list wpc_css_cfg() prints. */
    public static function wpc_reveal_classes()
    {
        $cfg = self::wpc_css_cfg();
        return array_values(array_unique(array_merge(['elementor-invisible'], (array) $cfg['conceal'])));
    }

    // v7.23.15 — CRITICAL CSS OWNS ITS RESTORER. When a render carries a crit block AND parked
    // forms but the v3 pass did not run (delay off, per-page JS off, v3 forced off), emit the
    // same loader with an EMPTY registry and cssOnly=1. The loader's JS lane stands down on
    // that flag (no traps, no replay, the site's own scripts run natively — the 7.22.53 rule);
    // its CSS lane restores the parked sheets, keeps #wpc-critical-css until CSS is live and
    // arms html.wpc-bgl255 on the first gesture, exactly as on a delay page. Same anchor as
    // process_html (placeholder, else body-end). No report key: no beacons without a replay.
    // Kill: wpc_css_only_loader.
    public static function wpc_css_only_loader($html, $critDir = '')
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
            $wpc_cfg = ['cssOnly' => 1] + self::wpc_css_cfg($critDir);
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
