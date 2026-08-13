<?php

class wps_ic_js_delay_v3 extends wps_ic_js_delay_v2
{


    protected $manifest_paths = [];
    protected $manifest_inline = [];
    protected $manifest_names = [];
    protected $companion_ids = [];  // {handle}-js-before/-after/-extra of excluded-at-parse parents
    protected $wpc_family_keep747 = [];  // dependency-family members coupled to a kept lane


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
    protected $wpc_inline_pairs494 = [];
    protected $wpc_nodefer_all512 = false;
    protected $wpc_jq_parse_need803 = false;

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
            'n489D_var',
            'ngf298gh738qwbdh0s87v_vars',
            'wpcRunningCritical',
            'wpc-ga-bot-shield',
            'wpc-presc-reserve', // A3 uniqueness guard: apply-time count, never delayed
            'wpc-icon-belt', // icon-guard settle belt: must observe fonts as they load, not on gesture
            'wpcVitals', // RUM collector — an observer released on gesture misses the load it
                         // exists to measure, and a bounce view never beacons at all

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

            'form_embed', 'msgsndr', 'leadconnectorhq', 'hsforms', 'hbspt',
            'calendly', 'typeform', 'jotform',

            'dark-mode', 'SR7.',

            // Theme nav-inits that own first-paint layout.
            'wpbf',
            'page-builder-framework',

            'sourcebuster',
            'borlabs',
        ], $wpc_closure)));

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
        foreach ((array) apply_filters('wpc_builtin_interaction_only', []) as $wpc_iop356) {
            if (self::wpc_io_pattern_ok($wpc_iop356)) {
                $this->wpc_io_patterns[] = strtolower((string) $wpc_iop356);
                $this->wpc_src_force_delay[] = strtolower((string) $wpc_iop356);
            }
        }
        // v7.10.387 — built-in lane-delay floor: scroll-behavior scripts whose function is
        // interaction-gated by definition (sticky repositioning needs a scroll; the scroll
        // releases the delay). Removes the tag at capture = out of the pre-LCP request graph.
        foreach ((array) apply_filters('wpc_builtin_lane_delay', ['sticky-elements.js']) as $wpc_ldp356) {
            if (self::wpc_io_pattern_ok($wpc_ldp356)) {
                $this->wpc_src_force_delay[] = strtolower((string) $wpc_ldp356);
                $this->wpc_lane_force_delay[] = strtolower((string) $wpc_ldp356);
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
    // LeadConnector, HubSpot, Calendly) — delaying their JS used to leave the
    // embed unsized/blank. delay-v3's interaction-only + human-signal warming
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
        $wpc_ovr358 = ['form_embed', 'msgsndr', 'leadconnectorhq', 'hsforms', 'hbspt', 'calendly'];
        $wpc_drop358 = [];
        foreach ($wpc_ovr358 as $wpc_k358) {
            if (!in_array($wpc_k358, (array) $this->excludes, true)) {
                continue;
            }
            foreach ($this->wpc_io_patterns as $wpc_p358) {
                if ($wpc_p358 !== '' && (strpos($wpc_p358, $wpc_k358) !== false || strpos($wpc_k358, $wpc_p358) !== false)) {
                    $wpc_drop358[] = $wpc_k358;
                    break;
                }
            }
        }
        if (!empty($wpc_drop358)) {
            $this->excludes = array_values(array_diff((array) $this->excludes, $wpc_drop358));
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
            $wpc_hostof356 = function ($z) use ($st) {
                $z = strtolower(trim((string) $z));
                if ($z === '') { return ''; }
                $z = (string) strtok($z, '/'); // drop any path/key suffix (zoneName can carry /key:)
                if (strpos($z, ':') !== false) { $z = (string) strtok($z, ':'); }
                return $st($z);
            };
            foreach (['ic_custom_cname', 'ic_cdn_zone_name'] as $wpc_zk356) {
                $z = function_exists('get_option') ? $wpc_hostof356(get_option($wpc_zk356)) : '';
                if ($z !== '') { $own[] = $z; }
            }
            if (class_exists('wps_rewriteLogic') && property_exists('wps_rewriteLogic', 'zoneName')) {
                $z = $wpc_hostof356(@wps_rewriteLogic::$zoneName);
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
    public static function wpc_is_jquery_id803($wpc_id803)
    {
        $wpc_id803 = strtolower(trim((string) $wpc_id803));
        if ($wpc_id803 === '') {
            return false;
        }
        return $wpc_id803 === 'jquery-js'
            || strpos($wpc_id803, 'jquery-core') === 0
            || strpos($wpc_id803, 'jquery-migrate') === 0;
    }

    public static function wpc_force_delay_on($which)
    {
        $const = 'WPC_FORCE_DELAY_' . strtoupper((string) $which);
        if (defined($const) && constant($const)) {
            return true;
        }
        $s = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        return is_array($s) && !empty($s['force-delay-' . $which]) && $s['force-delay-' . $which] == '1';
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
    public static function wpc_measured_delay_newer_than($since)
    {
        try {
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return false;
            }
            $k = (new wps_ic_url_key())->setup('');
            $f = $k ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/delay.json' : '';
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
        static $wpc_al364 = null;
        if ($wpc_al364 !== null) {
            return $wpc_al364;
        }
        $wpc_al364 = false;
        try {
            // manifest_off blocks only if it is NEWER than the on-disk measured
            // gen (parity with the process_html flip) — a fresh gen supersedes a
            // stale promotion kill-switch; the facade must arm exactly when the
            // flip does.
            $wpc_moff364 = (int) get_option('wpc_delay_v3_manifest_off', 0);
            if (($wpc_moff364 > 0 && !self::wpc_measured_delay_newer_than($wpc_moff364))
                || get_option('wpc_delay_aggr_off')
                || !apply_filters('wpc_delay_v3_telemetry', true)
                || !apply_filters('wpc_delay_v3_io_when_measured', true)
                || !apply_filters('wpc_delay_v3_manifest', true)
                || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return $wpc_al364;
            }
            $wpc_mk364 = (new wps_ic_url_key())->setup('');
            $wpc_mf364 = $wpc_mk364 ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_mk364 . '/delay.json' : '';
            if ($wpc_mf364 === '' || !@is_readable($wpc_mf364)) {
                return $wpc_al364;
            }
            $wpc_m364 = json_decode((string) @file_get_contents($wpc_mf364), true);
            $wpc_al364 = self::wpc_delay_measured_shape($wpc_m364);
        } catch (\Throwable $e) {
            $wpc_al364 = false;
        }
        return $wpc_al364;
    }


    protected function should_exclude_script($attributes, $content = '')
    {


        $wpc_type = isset($attributes['type']) ? strtolower(trim((string) $attributes['type'])) : '';
        if ($wpc_type !== '' && strpos($wpc_type, 'javascript') === false) {
            return true;
        }

        // A sync-kept theme script that references jQuery needs jQuery itself sync —
        // an exempted nav-init throwing 'jQuery is not defined' at parse is dead forever.
        // Wins over force-delay: correctness before preference.
        if ($this->wpc_sync_jquery && !empty($attributes['src'])
            && preg_match('#/jquery(?:\.min)?\.js(?:\?|$)|jquery-migrate#i', (string) $attributes['src'])) {
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
            && isset($this->wpc_family_keep747[(string) $attributes['id']])) {
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
        $wpc_gdk786 = apply_filters('wpc_global_define_keep', ['BreakdanceFrontend']);
        if (!empty($wpc_gdk786) && is_array($wpc_gdk786)) {
            foreach ($wpc_gdk786 as $wpc_gd786) {
                if ($wpc_gd786 === '') { continue; }
                if ((!empty($content) && strpos($content, (string) $wpc_gd786) !== false)
                    || (!empty($attributes['src']) && stripos((string) $attributes['src'], (string) $wpc_gd786) !== false)) {
                    return true;
                }
            }
        }

        $wpc_lvk762 = apply_filters('wpc_layout_var_keep', ['--site-header-height', '--topbar-height', 'breakdance-utils']);
        if (!empty($wpc_lvk762) && is_array($wpc_lvk762)) {
            foreach ($wpc_lvk762 as $wpc_lv762) {
                if ($wpc_lv762 === '') { continue; }
                if ((!empty($content) && strpos($content, (string) $wpc_lv762) !== false)
                    || (!empty($attributes['src']) && stripos((string) $attributes['src'], (string) $wpc_lv762) !== false)) {
                    return true;
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
            && !(is_object($this->userExcludes) && method_exists($this->userExcludes, 'excludedFromDelayV2')
                && $this->userExcludes->excludedFromDelayV2((string) $attributes['src']))) {
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
            && !(is_object($this->userExcludes) && method_exists($this->userExcludes, 'excludedFromDelayV2')
                && $this->userExcludes->excludedFromDelayV2((string) $attributes['src']))) {
            $wpc_lfsrc356 = strtolower(html_entity_decode((string) $attributes['src']));
            $wpc_lfh356 = strtolower((string) parse_url($wpc_lfsrc356, PHP_URL_HOST));
            if ($wpc_lfh356 !== '' && !$this->wpc_is_own_host($wpc_lfh356)
                && $this->checkKeyword($wpc_lfsrc356, $this->wpc_src_force_delay)) {
                return false;
            }
            // v7.10.393: the external-host guard above made the .387 lane a NO-OP on every
            // CDN-on site (sticky rides our zone = own host). Lane entries are first-party
            // scroll-behavior scripts — the same excludes/user-keeps above still win.
            if (!empty($this->wpc_lane_force_delay)
                && $this->checkKeyword($wpc_lfsrc356, $this->wpc_lane_force_delay)) {
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
            $wpc_ddfams356 = $this->wpc_captcha_intent
                ? ['recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com']
                : [];
            if (!empty($this->wpc_io_patterns)) {
                $wpc_ddfams356 = array_merge($wpc_ddfams356, $this->wpc_io_patterns);
            }
            if (!empty($this->wpc_src_force_delay)) {
                $wpc_ddfams356 = array_merge($wpc_ddfams356, $this->wpc_src_force_delay);
            }
            $wpc_dda356 = !empty($wpc_ddfams356) ? $this->parse_script_attributes($matches[0]) : [];
            // Consent-blocked copies (type="text/plain" etc.) are the consent manager's
            // to activate — they neither seed the seen-map nor get dropped.
            if (!empty($wpc_dda356['type']) && stripos((string) $wpc_dda356['type'], 'javascript') === false) {
                $wpc_dda356 = [];
            }
            if (!empty($wpc_dda356['src'])) {
                $wpc_ddu356 = html_entity_decode((string) $wpc_dda356['src']);
                $wpc_ddh356 = strtolower((string) parse_url($wpc_ddu356, PHP_URL_HOST));
                if ($wpc_ddh356 !== '' && !$this->wpc_is_own_host($wpc_ddh356)
                    && $this->checkKeyword($wpc_ddu356, $wpc_ddfams356)) {
                    if (isset($this->wpc_seen_ext_srcs[$wpc_ddu356])) {
                        return '';
                    }
                    if (count($this->wpc_seen_ext_srcs) < 50) {
                        $this->wpc_seen_ext_srcs[$wpc_ddu356] = 1;
                    }
                }
            }
        }
        $wpc_pre_n = is_array($this->script_registry) ? count($this->script_registry) : 0;
        $out = parent::process_script_tag($matches);


        // io cohort stamp: captcha families (manifest intent, unchanged) + vendor match[]
        // patterns. A2 guard is uniform: a jQuery-referencing src never demotes to the
        // io injection path (plain async tags bypass the ordered S()/C() replay).
        $wpc_io_fams356 = [];
        if ($this->wpc_captcha_intent) {
            $wpc_io_fams356 = ['recaptcha/api.js', 'gstatic.com/recaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com'];
        }
        if (!empty($this->wpc_io_patterns)) {
            $wpc_io_fams356 = array_merge($wpc_io_fams356, $this->wpc_io_patterns);
        }
        if (!empty($wpc_io_fams356) && is_array($this->script_registry)) {
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
                foreach ($wpc_io_fams356 as $wpc_fam) {
                    if (stripos($wpc_src, $wpc_fam) !== false) {
                        // io injection is plain-async (outside ordered replay): only
                        // EXTERNAL-host leaf scripts may demote — a same-host src can
                        // have delayed dependents; it stays in the ordered registry.
                        $wpc_ioh356 = strtolower((string) parse_url($wpc_src, PHP_URL_HOST));
                        $wpc_iohome356 = function_exists('home_url') ? strtolower((string) parse_url(home_url(), PHP_URL_HOST)) : '';
                        $wpc_iost356 = function ($h) {
                            return strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
                        };
                        // our hosts (origin, wpc edge, OR the configured CDN zone) serve
                        // rewritten locals — never io-demotable
                        $wpc_ioext356 = $wpc_ioh356 !== '' && !$this->wpc_is_own_host($wpc_ioh356);
                        if ($wpc_ioext356 && !self::wpc_src_needs_jquery($wpc_src)) {
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
            $wpc_id494 = isset($attrs['id']) ? strtolower(trim((string) $attrs['id'])) : '';
            // v7.10.512 — .497 ANDed this with a filter defaulting FALSE, so the .494
            // disqualification was always false and every paired external was deferred again
            // while its inline -after companion ran at parse. The hazard .497 actually named
            // was un-deferring only SOME keeps; wpc_nodefer_all512 answers that by un-deferring
            // ALL of them whenever any pair exists, so the keep set never carries mixed order.
            $wpc_paired494 = $wpc_id494 !== '' && !empty($this->wpc_inline_pairs494[$wpc_id494]);
            if (!$wpc_paired494 && !empty($this->wpc_jq_parse_need803) && $wpc_id494 !== ''
                && self::wpc_is_jquery_id803($wpc_id494)) {
                $wpc_paired494 = true;
            }
            if (!empty($attrs['src']) && !isset($attrs['defer']) && !isset($attrs['async'])
                && !$wpc_paired494 && empty($this->wpc_nodefer_all512)
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

    public function process_html($html)
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return $html;
        }

        $this->wpc_sync_jquery = (bool) preg_match('/<script\b[^>]*\bsrc=["\'][^"\']*(?:wpbf|page-builder-framework)[^"\']*\.js/i', $html);

        // v7.10.494 — INLINE COMPANIONS DISQUALIFY DEFER. An inline script cannot be deferred, so
        // deferring the external it depends on inverts their order: wp-i18n-js deferred while
        // wp-i18n-js-after runs at parse threw "wp is not defined" and killed Real Cookie Banner.
        // v7.10.564 — ONLY -after companions disqualify, which is WP core's actual rule in
        // filter_eligible_strategies: an -after companion CALLS its parent's API at parse, so the
        // parent must not defer; a -before companion is a config setter that runs at parse and is
        // read at DCL — order preserved by construction. Matching -before too stranded
        // elementor-frontend-js in the blocking lane while its whole dependency chain deferred
        // around it (staging receipt: webpack runtime deferred, frontend blocking = inversion).
        $this->wpc_inline_pairs494 = [];
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+?)-after["\']/i', $html, $wpc_ip494)) {
            foreach ((array) $wpc_ip494[1] as $wpc_b494) {
                $this->wpc_inline_pairs494[strtolower($wpc_b494)] = 1;
            }
        }
        // v7.10.803 — AN -after COMPANION NEEDS ITS LIBRARIES, NOT JUST ITS PARENT. The pairing
        // above protects the companion's OWN external from deferring, which is WP core's rule.
        // But the companion body also calls jQuery at parse, and jQuery carries no -after
        // companion of its own, so nothing above disqualifies it: eloorac deferred
        // jquery-core-js while jquery-ui-datepicker-js-after ran during parse and threw
        // "jQuery is not defined". Read the companion BODIES and, only when one actually
        // references jQuery, hold the jQuery tags in the eager lane. Narrow by construction —
        // a page whose companions never touch jQuery still defers it, so this cannot become
        // the .512 blanket that .564 retired for costing 310 ms.
        $this->wpc_jq_parse_need803 = false;
        if (apply_filters('wpc_jquery_parse_companion_guard', true)
            && preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\'][^"\']+?-after["\'][^>]*>(.*?)<\/script>/is', $html, $wpc_cb803)) {
            foreach ((array) $wpc_cb803[1] as $wpc_body803) {
                if (preg_match('/(?:^|[^A-Za-z0-9_$])(?:jQuery|\$)\s*[\(\.]/', (string) $wpc_body803)) {
                    $this->wpc_jq_parse_need803 = true;
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
        $this->wpc_nodefer_all512 = (bool) apply_filters('wpc_nodefer_all_keeps', false)
            && apply_filters('wpc_keep_defer_pair_safe', true);


        // manifest_off (promoted-script ReferenceError kill-switch) no longer
        // freezes the measured read when a NEWER measured gen is on disk — the
        // aggressive path is governed by its own boot-watchdog, not this switch.
        $wpc_moff607 = (int) get_option('wpc_delay_v3_manifest_off', 0);
        $wpc_manifest_on = ($wpc_moff607 <= 0 || self::wpc_measured_delay_newer_than($wpc_moff607))
            && apply_filters('wpc_delay_v3_manifest', true);
        if ($wpc_manifest_on && class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
            try {
                $wpc_mk = (new wps_ic_url_key())->setup('');
                $wpc_mf = $wpc_mk ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_mk . '/delay.json' : '';
                if ($wpc_mf && @is_readable($wpc_mf)) {
                    $wpc_m = json_decode((string) @file_get_contents($wpc_mf), true);
                    if (is_array($wpc_m)) {
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
                            $wpc_formsSafe388 = array_key_exists('has_form', $wpc_m) && $wpc_m['has_form'] === false;
                            foreach (array_slice($wpc_m['third_parties'], 0, 12) as $wpc_tp356) {
                                if (!is_array($wpc_tp356)
                                    || strtolower((string) ($wpc_tp356['recommended'] ?? '')) !== 'delay-interaction-only') {
                                    continue;
                                }
                                foreach (array_slice((array) ($wpc_tp356['match'] ?? []), 0, 6) as $wpc_tm356) {
                                    // v7.10.386 — bare chat-vendor patterns expand to their
                                    // chat-scoped hosts (the only form the validator accepts).
                                    foreach (self::wpc_io_pattern_expand($wpc_tm356) as $wpc_tmx386) {
                                        if (!self::wpc_io_pattern_ok($wpc_tmx386, $wpc_formsSafe388)) {
                                            continue;
                                        }
                                        $this->wpc_io_patterns[] = strtolower((string) $wpc_tmx386);
                                        $this->wpc_src_force_delay[] = strtolower((string) $wpc_tmx386); // capture door, SRC-only
                                    }
                                }
                            }
                            $this->wpc_io_patterns = array_slice(array_values(array_unique($this->wpc_io_patterns)), 0, 24);
                            $this->wpc_src_force_delay = array_slice(array_values(array_unique($this->wpc_src_force_delay)), 0, 32);
                            // A form vendor the service just measured as delay-interaction-only
                            // releases its built-in keep so it can actually be delayed + io-stamped.
                            $this->wpc_release_io_form_keeps();
                        }
                        // v1 envelope is per-device ({mobile:{...},desktop:{...}}); accept top-level too.
                        $wpc_secs = [$wpc_m];
                        foreach (['mobile', 'desktop'] as $wpc_d) {
                            if (!empty($wpc_m[$wpc_d]) && is_array($wpc_m[$wpc_d])) {
                                $wpc_secs[] = $wpc_m[$wpc_d];
                            }
                        }
                        foreach ($wpc_secs as $wpc_sec) {
                            foreach (['render_critical', 'atf_mutators'] as $wpc_k) {
                                if (empty($wpc_sec[$wpc_k]) || !is_array($wpc_sec[$wpc_k])) {
                                    continue;
                                }
                                foreach (array_slice($wpc_sec[$wpc_k], 0, 20) as $wpc_s) {

                                    if (is_array($wpc_s) && !empty($wpc_s['key'])) {
                                        $wpc_s = $wpc_s['key'];
                                    }
                                    if (!is_string($wpc_s) || strlen($wpc_s) < 4) {
                                        continue;
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
                $wpc_bn_map65 = [];
                foreach ($wpc_ptags[0] as $wpc_bt65) {
                    $wpc_ba65 = $this->parse_script_attributes($wpc_bt65);
                    if (empty($wpc_ba65['src'])) { continue; }
                    $wpc_bu65 = html_entity_decode((string) $wpc_ba65['src']);
                    $wpc_bi65 = strrpos($wpc_bu65, '/a:');
                    if ($wpc_bi65 !== false) {
                        $wpc_be65 = substr($wpc_bu65, $wpc_bi65 + 3);
                        if (preg_match('#^https?://#i', $wpc_be65)) {
                            $wpc_bp65 = parse_url($wpc_be65, PHP_URL_PATH);
                        } else {
                            $wpc_bp65 = strtok($wpc_be65, '?');
                            if (is_string($wpc_bp65) && $wpc_bp65 !== '' && $wpc_bp65[0] !== '/') { $wpc_bp65 = '/' . $wpc_bp65; }
                        }
                    } else {
                        $wpc_bp65 = parse_url($wpc_bu65, PHP_URL_PATH);
                    }
                    if (!is_string($wpc_bp65) || $wpc_bp65 === '') { continue; }
                    $wpc_bb65 = strtolower(basename($wpc_bp65));
                    if ($wpc_bb65 === '') { continue; }
                    if (!isset($wpc_bn_map65[$wpc_bb65])) { $wpc_bn_map65[$wpc_bb65] = []; }
                    $wpc_bn_map65[$wpc_bb65][$wpc_bp65] = true;
                }
                foreach (array_keys($this->manifest_names) as $wpc_bn65) {
                    $wpc_bk65 = strtolower((string) $wpc_bn65);
                    if (isset($wpc_bn_map65[$wpc_bk65]) && count($wpc_bn_map65[$wpc_bk65]) === 1) {
                        $this->manifest_paths[array_key_first($wpc_bn_map65[$wpc_bk65])] = true;
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
                        continue;
                    }
                    $wpc_deps = $wpc_deps_all(substr($wpc_cid, 0, -3));
                    if ($wpc_deps === null) {
                        continue;
                    }
                    if ($wpc_is_form($wpc_cid)) {
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


        // AUTO-100 §4 lane-pin (A2 chain law): resolve stored pin intents against this
        // page's registered scripts. jQuery anywhere in the chain — or a chain we cannot
        // walk — DEGRADES to report-customer; never silently re-eager the biggest script.
        $wpc_pins356 = get_option('wpc_presc_pins');
        if (is_array($wpc_pins356) && !empty($wpc_pins356) && apply_filters('wpc_presc_lane_pin', true)) {
            try {
                $wpc_pdirty356 = false;
                $wpc_pws356 = !empty($GLOBALS['wp_scripts']) && !empty($GLOBALS['wp_scripts']->registered)
                    ? $GLOBALS['wp_scripts'] : null;
                $wpc_psrc356 = [];
                if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $wpc_ppt356)) {
                    foreach ($wpc_ppt356[0] as $wpc_ppe356) {
                        $wpc_ppa356 = $this->parse_script_attributes($wpc_ppe356);
                        if (!empty($wpc_ppa356['id']) && !empty($wpc_ppa356['src'])) {
                            $wpc_psrc356[(string) $wpc_ppa356['id']] = html_entity_decode((string) $wpc_ppa356['src']);
                        }
                    }
                }
                foreach ($wpc_pins356 as $wpc_pnid356 => $wpc_pin356) {
                    if (!is_array($wpc_pin356)) {
                        continue;
                    }
                    // Resolved pins RE-APPLY on every render (promoted_src_ids resets per
                    // document) — the stored handle-id list makes that a read-only pass.
                    if (($wpc_pin356['state'] ?? '') === 'resolved') {
                        foreach ((array) ($wpc_pin356['ids'] ?? []) as $wpc_rpid356) {
                            if (isset($wpc_psrc356[$wpc_rpid356])) {
                                $this->promoted_src_ids[(string) $wpc_rpid356] = true;
                            }
                        }
                        continue;
                    }
                    if (($wpc_pin356['state'] ?? '') !== 'pending') {
                        continue;
                    }
                    $wpc_tid356 = '';
                    foreach ((array) ($wpc_pin356['cand'] ?? []) as $wpc_pc356) {
                        $wpc_pc356 = strtolower(trim((string) $wpc_pc356));
                        if (strlen($wpc_pc356) < 4) {
                            continue;
                        }
                        foreach ($wpc_psrc356 as $wpc_pi356 => $wpc_pu356) {
                            if (substr((string) $wpc_pi356, -3) === '-js' && stripos($wpc_pu356, $wpc_pc356) !== false) {
                                $wpc_tid356 = (string) $wpc_pi356;
                                break 2;
                            }
                        }
                    }
                    if ($wpc_tid356 === '') {
                        continue; // revealer not on this template — intent stays pending
                    }
                    $wpc_ph356 = substr($wpc_tid356, 0, -3);
                    $wpc_chain356 = null;
                    if ($wpc_pws356 && !empty($wpc_pws356->registered[$wpc_ph356])) {
                        $wpc_chain356 = [];
                        $wpc_pst356 = [$wpc_ph356];
                        $wpc_psn356 = [$wpc_ph356 => true];
                        $wpc_pn356 = 0;
                        while (!empty($wpc_pst356) && $wpc_pn356++ < 200) {
                            $wpc_pcur356 = array_pop($wpc_pst356);
                            if (empty($wpc_pws356->registered[$wpc_pcur356])) {
                                continue;
                            }
                            foreach ((array) $wpc_pws356->registered[$wpc_pcur356]->deps as $wpc_pd356) {
                                if (isset($wpc_psn356[$wpc_pd356])) {
                                    continue;
                                }
                                $wpc_psn356[$wpc_pd356] = true;
                                $wpc_chain356[] = $wpc_pd356;
                                $wpc_pst356[] = $wpc_pd356;
                            }
                        }
                    }
                    // A2 in full: jQuery anywhere in the chain (target src OR any chain
                    // member's src), an unwalkable chain, or a chain heavier than the win
                    // — all DEGRADE to report-customer.
                    $wpc_jq356 = $wpc_chain356 === null
                        || in_array('jquery', $wpc_chain356, true) || in_array('jquery-core', $wpc_chain356, true)
                        || self::wpc_src_needs_jquery((string) ($wpc_psrc356[$wpc_tid356] ?? ''));
                    $wpc_reason356 = $wpc_chain356 === null ? 'chain-unknown' : 'jquery-chain';
                    $wpc_bytes356 = 0;
                    if (!$wpc_jq356) {
                        $wpc_pinsrcs356 = [(string) ($wpc_psrc356[$wpc_tid356] ?? '')];
                        foreach ($wpc_chain356 as $wpc_pch356) {
                            if (isset($wpc_psrc356[$wpc_pch356 . '-js'])) {
                                $wpc_pinsrcs356[] = (string) $wpc_psrc356[$wpc_pch356 . '-js'];
                            }
                        }
                        foreach ($wpc_pinsrcs356 as $wpc_psu356) {
                            if ($wpc_psu356 === '') {
                                continue;
                            }
                            if (self::wpc_src_needs_jquery($wpc_psu356)) {
                                $wpc_jq356 = true;
                                $wpc_reason356 = 'jquery-chain';
                                break;
                            }
                            if (($wpc_pcp356 = strrpos($wpc_psu356, 'wp-content/')) !== false) {
                                $wpc_prel356 = (string) preg_replace('/[?#].*$/', '', substr($wpc_psu356, $wpc_pcp356));
                                if (strpos($wpc_prel356, '..') === false) {
                                    $wpc_bytes356 += (int) @filesize(trailingslashit(ABSPATH) . $wpc_prel356);
                                }
                            }
                        }
                        if (!$wpc_jq356 && $wpc_bytes356 > (int) apply_filters('wpc_lane_pin_weight_cap', 262144)) {
                            $wpc_jq356 = true;
                            $wpc_reason356 = 'heavier-than-win';
                        }
                    }
                    if ($wpc_jq356) {
                        $wpc_pins356[$wpc_pnid356]['state'] = 'degraded';
                        if (function_exists('wpc_presc_journal_put')) {
                            wpc_presc_journal_put((string) $wpc_pnid356, ['status' => 'report', 'fix' => 'lane-pin',
                                'class' => (string) ($wpc_pin356['cl'] ?? ''), 'skipped' => $wpc_reason356]);
                        }
                    } else {
                        $wpc_pcap356 = (int) apply_filters('wpc_delay_v3_promotion_cap', 24);
                        if ((count($this->promoted_src_ids) + 1 + count($wpc_chain356)) > $wpc_pcap356) {
                            continue; // cap-full — intent stays pending for a later render
                        }
                        $wpc_pinids356 = [$wpc_tid356];
                        $this->promoted_src_ids[$wpc_tid356] = true;
                        foreach ($wpc_chain356 as $wpc_pch356) {
                            if (isset($wpc_psrc356[$wpc_pch356 . '-js'])) {
                                $this->promoted_src_ids[$wpc_pch356 . '-js'] = true;
                                $wpc_pinids356[] = $wpc_pch356 . '-js';
                            }
                        }
                        $wpc_pins356[$wpc_pnid356]['state'] = 'resolved';
                        $wpc_pins356[$wpc_pnid356]['ids'] = $wpc_pinids356;
                        if (function_exists('wpc_presc_journal_put')) {
                            wpc_presc_journal_put((string) $wpc_pnid356, ['status' => 'applied', 'fix' => 'lane-pin',
                                'class' => (string) ($wpc_pin356['cl'] ?? '')]);
                        }
                    }
                    $wpc_pdirty356 = true;
                }
                if ($wpc_pdirty356) {
                    update_option('wpc_presc_pins', $wpc_pins356, false);
                }
            } catch (\Throwable $e) {
            }
        }

        $this->companion_ids = [];
        $this->wpc_family_keep747 = [];
        $wpc_excluded_ids = [];
        $wpc_runtime_tags = [];
        $wpc_seen_src_ids747 = [];
        if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $wpc_srctags)) {
            foreach ($wpc_srctags[0] as $wpc_t) {
                $wpc_a = $this->parse_script_attributes($wpc_t);
                if (empty($wpc_a['id'])) {
                    continue;
                }
                $wpc_seen_src_ids747[(string) $wpc_a['id']] = true;
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
            && preg_match_all('/<script\b(?![^>]*\bsrc=)([^>]*\bid=["\']([^"\']+?)-after["\'][^>]*)>(.*?)<\/script>/is', $html, $wpc_ic805, PREG_SET_ORDER)) {
            foreach ($wpc_ic805 as $wpc_cm805) {
                $wpc_pid805 = (string) $wpc_cm805[2];
                if ($wpc_pid805 === '' || isset($wpc_excluded_ids[$wpc_pid805])
                    || !isset($wpc_seen_src_ids747[$wpc_pid805])) {
                    continue;
                }
                $wpc_ca805 = $this->parse_script_attributes('<script ' . $wpc_cm805[1] . '>');
                if (!$this->should_exclude_script($wpc_ca805, (string) $wpc_cm805[3])) {
                    continue;
                }
                $this->companion_ids[$wpc_pid805]     = true;
                $this->wpc_family_keep747[$wpc_pid805] = true;
                $wpc_excluded_ids[$wpc_pid805]        = true;
            }
        }

        // elementor-frontend-js is the DOCUMENT SCANNER: at its init it instantiates every
        // elementor document on the page with whatever document classes are registered AT THAT
        // MOMENT. elementor-pro-frontend-js is the class REGISTRAR (popup among them). Scanner
        // kept + registrar delayed = every popup instantiated as a base document with no
        // getModal, permanently — the registrar arriving later re-instantiates nothing. So the
        // pro family rides the scanner's lane; the runtime loop below then carries
        // elementor-pro-webpack-runtime-js with it.
        if (isset($wpc_excluded_ids['elementor-frontend-js'])
            && apply_filters('wpc_delay_elementor_family_lane', true)) {
            foreach (['elementor-pro-frontend-js', 'pro-elements-handlers-js'] as $wpc_pid747) {
                if (!isset($wpc_seen_src_ids747[$wpc_pid747]) || isset($wpc_excluded_ids[$wpc_pid747])) {
                    continue;
                }
                $this->companion_ids[$wpc_pid747]     = true;
                $this->wpc_family_keep747[$wpc_pid747] = true;
                $wpc_excluded_ids[$wpc_pid747]        = true;
                $wpc_ph747 = preg_replace('/-js$/', '', $wpc_pid747);
                foreach (['-js-before', '-js-after', '-js-extra'] as $wpc_suf) {
                    $this->companion_ids[$wpc_ph747 . $wpc_suf] = true;
                }
            }
        }
        // The other half of the same law: never keep a CONSUMER while delaying its DEPENDENCY.
        // pro-elements-handlers calls $menu.smartmenus() and .sticky() at widget init (DCL);
        // with the libs delayed, every RELOAD (cached = fast init) throws "smartmenus is not a
        // function" before the gesture releases them — staging receipt, reload-only, homepage.
        if (isset($wpc_excluded_ids['pro-elements-handlers-js'])
            && apply_filters('wpc_delay_elementor_family_lane', true)) {
            foreach (['smartmenus-js', 'e-sticky-js'] as $wpc_lid753) {
                if (!isset($wpc_seen_src_ids747[$wpc_lid753]) || isset($wpc_excluded_ids[$wpc_lid753])) {
                    continue;
                }
                $this->companion_ids[$wpc_lid753]      = true;
                $this->wpc_family_keep747[$wpc_lid753] = true;
                $wpc_excluded_ids[$wpc_lid753]         = true;
            }
        }


        foreach ($wpc_runtime_tags as $wpc_rid => $wpc_fam) {
            foreach (array_keys($wpc_excluded_ids) as $wpc_eid) {
                if (strpos($wpc_eid, $wpc_fam . '-') === 0 && $wpc_eid !== $wpc_rid) {
                    $this->companion_ids[$wpc_rid]     = true;
                    $this->wpc_family_keep747[$wpc_rid] = true;
                    $wpc_excluded_ids[$wpc_rid]        = true;
                    $wpc_rh = preg_replace('/-js$/', '', $wpc_rid);
                    foreach (['-js-before', '-js-after', '-js-extra'] as $wpc_suf) {
                        $this->companion_ids[$wpc_rh . $wpc_suf] = true;
                    }
                    break;
                }
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
        $wpc_aggr360 = 0;
        if ($wpc_to > 0 && $this->wpc_measured
            && !get_option('wpc_delay_aggr_off')
            && apply_filters('wpc_delay_v3_telemetry', true)
            && apply_filters('wpc_delay_v3_io_when_measured', true)) {
            $wpc_to = 0;
            $wpc_aggr360 = 1;
        }
        $wpc_cfg = [
            'timeout' => (int) apply_filters('wpc_delay_v3_timeout', $wpc_to),
            // Marks the AGGRESSIVE-DEFAULT flip specifically (not maximum-mobile,
            // not user io): the boot watchdog arms ONLY on aggr pages, so demote
            // strikes always come from pages the demote actually fixes.
            'aggr' => $wpc_aggr360,


            'report'  => apply_filters('wpc_delay_v3_telemetry', true) && function_exists('admin_url') ? admin_url('admin-ajax.php') : '',


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
            ])),


            // v7.10.504 — REACHABILITY FIX. .497 defaulted the atomic cascade OFF, but the flag was
            // only ever READ in the loader and never written into this config, so there was no way to
            // turn it back on. wpcompress.com measured TBT 0ms with it active and TBT 5,900ms with it
            // off (one 6,184ms task; Script Evaluation only 207ms against Other 7,130ms — style
            // recalculation, which is exactly what restoring ~44 sheets one at a time produces).
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

        // Delayed lane rides the CDN (post-interaction — never in the render chain). The render-lane
        // standdown (wpc_scripts_same_origin) does not govern here; the loader carries origin
        // failover for every zoned src, so a dead zone costs one retry, never a lost chain.
        $wpc_zoned804 = false;
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_zone_delayed_js804')
            && is_array($this->script_registry)) {
            foreach ($this->script_registry as $wpc_k804 => $wpc_e804) {
                if (empty($wpc_e804['src']) || !is_string($wpc_e804['src'])) {
                    continue;
                }
                // Modules and SRI-pinned tags stay on the origin: a cross-origin module needs CORS
                // headers, and a cross-origin integrity check needs crossorigin — either one fails
                // the load, and a failed load here would flip the whole session origin-first for a
                // reason that is not a zone outage.
                if (strtolower((string) (isset($wpc_e804['type']) ? $wpc_e804['type'] : '')) === 'module'
                    || !empty($wpc_e804['attributes']['integrity'])) {
                    continue;
                }
                $wpc_enc804 = !empty($wpc_e804['encoded']);
                $wpc_u804 = $wpc_enc804 ? base64_decode($wpc_e804['src'], true) : $wpc_e804['src'];
                if (!is_string($wpc_u804) || $wpc_u804 === '') {
                    continue;
                }
                $wpc_z804 = wps_rewriteLogic::wpc_zone_delayed_js804($wpc_u804);
                if (is_string($wpc_z804) && $wpc_z804 !== '' && $wpc_z804 !== $wpc_u804) {
                    $this->script_registry[$wpc_k804]['src'] = $wpc_enc804 ? base64_encode($wpc_z804) : $wpc_z804;
                    $wpc_zoned804 = true;
                }
            }
        }
        if ($wpc_zoned804 && class_exists('wps_rewriteLogic') && !empty(wps_rewriteLogic::$zoneName)
            && is_string(wps_rewriteLogic::$zoneName)) {
            $wpc_cfg['cdnHost'] = wps_rewriteLogic::$zoneName;
        }

        // Registry keeps the v2 name (wpcScriptRegistry) — the adopted loader and its debug tooling
        // (window.ScriptDelayDebug) consume it; v2 and v3 are mutually exclusive on a page.
        $delay_script = '';
        if (!empty(get_option('wps_ic_delay_v2_debug'))) {
            $delay_script .= '<script>var DEBUG = true;</script>';
        }
        $delay_script .= '<script id="wpc-delay-v3-registry">var wpcScriptRegistry=' . json_encode($this->script_registry)
            . ';var wpcDelayV3Cfg=' . json_encode($wpc_cfg) . ';'


            . 'if(!document.getElementById("wpc-critical-css")){wpcDelayV3Cfg.timeout=Math.min(+wpcDelayV3Cfg.timeout||60,4);}'
            . '</script>';


        $wpc_loader_base = defined('WPS_IC_URI') ? WPS_IC_URI : plugins_url('/', dirname(__FILE__));
        $wpc_loader_file = (defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'assets/js/delay-v3-loader.min.js'))
            ? 'assets/js/delay-v3-loader.min.js' : 'assets/js/delay-v3-loader.js';
        $wpc_loader_src = $wpc_loader_base . $wpc_loader_file . '?v=' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1');


        if (defined('WPS_IC_DIR') && function_exists('wp_upload_dir')) {
            try {
                $wpc_ud107 = wp_upload_dir(null, false);
                if (empty($wpc_ud107['error']) && !empty($wpc_ud107['basedir']) && !empty($wpc_ud107['baseurl'])) {
                    $wpc_ver107  = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1';
                    $wpc_dir107  = rtrim($wpc_ud107['basedir'], '/') . '/wpc-assets/';
                    $wpc_name107 = 'delay-v3-loader-' . $wpc_ver107 . '.min.js';
                    $wpc_srcf107 = WPS_IC_DIR . 'assets/js/delay-v3-loader.min.js';


                    $wpc_stale108 = @file_exists($wpc_dir107 . $wpc_name107) && @file_exists($wpc_srcf107)
                        && (int) @filesize($wpc_dir107 . $wpc_name107) !== (int) @filesize($wpc_srcf107);
                    if ($wpc_stale108) {
                        @unlink($wpc_dir107 . $wpc_name107);
                    }
                    if (!@file_exists($wpc_dir107 . $wpc_name107) && @file_exists($wpc_srcf107)) {
                        if (!is_dir($wpc_dir107)) {
                            @mkdir($wpc_dir107, 0755, true);
                        }
                        if (is_dir($wpc_dir107)) {
                            // GC only versions older than 7 days: cached HTML keeps referencing
                            // the previous loader for hours — deleting it eagerly serves 500s
                            foreach ((array) @glob($wpc_dir107 . 'delay-v3-loader-*.min.js') as $wpc_old107) {
                                $wpc_omt107 = (int) @filemtime($wpc_old107);
                                if ($wpc_omt107 && (time() - $wpc_omt107) > 7 * 86400) {
                                    @unlink($wpc_old107);
                                }
                            }
                            @copy($wpc_srcf107, $wpc_dir107 . $wpc_name107);
                            if (!@file_exists($wpc_dir107 . '.htaccess')) {
                                @file_put_contents($wpc_dir107 . '.htaccess',
                                    "<IfModule mod_headers.c>\n<FilesMatch \"\\.(js|css)$\">\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n</IfModule>\n<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType application/javascript \"access plus 1 year\"\n</IfModule>\n");
                            }
                        }
                    }
                    if (@file_exists($wpc_dir107 . $wpc_name107) && @filesize($wpc_dir107 . $wpc_name107) > 0) {
                        $wpc_loader_src = rtrim($wpc_ud107['baseurl'], '/') . '/wpc-assets/' . $wpc_name107;
                        // Zone-serve for a real edge TTL (same host-swap the image lanes use);
                        // origin URL stands whenever the zone isn't live
                        $wpc_set107 = get_option(WPS_IC_SETTINGS);
                        if (is_array($wpc_set107) && !empty($wpc_set107['live-cdn']) && (string) $wpc_set107['live-cdn'] === '1') {
                            // v7.10.600 — resolve through the ONE helper. This site read only
                            // ic_custom_cname then the zone, missing the Cloudflare-provisioned
                            // cname that the three combine/enqueue resolvers check first — so on a
                            // CF-connected site the loader alone landed on the raw zone host and
                            // paid a second DNS+TCP+TLS (409ms measured) that no other node pays.
                            $wpc_ch107 = function_exists('wpc_cdn_host600')
                                ? (string) wpc_cdn_host600()
                                : trim((string) get_option('ic_custom_cname'));
                            if ($wpc_ch107 === '') {
                                $wpc_ch107 = trim((string) get_option('ic_cdn_zone_name'));
                            }
                            if ($wpc_ch107 !== '') {
                                $wpc_loader_src = preg_replace('#^https?://[^/]+#', 'https://' . $wpc_ch107, $wpc_loader_src);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {

            }
        }


        $wpc_inl109 = '';
        if (defined('WPS_IC_DIR') && apply_filters('wpc_delay_loader_inline', true)) {
            $wpc_lfile109 = WPS_IC_DIR . $wpc_loader_file;
            if (@is_readable($wpc_lfile109) && (int) @filesize($wpc_lfile109) > 0 && (int) @filesize($wpc_lfile109) <= 28672) {
                $wpc_lsrc109 = (string) @file_get_contents($wpc_lfile109);
                if ($wpc_lsrc109 !== '' && stripos($wpc_lsrc109, '</script') === false) {
                    $wpc_inl109 = '<script id="wpc-delay-v3-loader">' . $wpc_lsrc109 . '</script>';
                }
            }
        }
        $delay_script .= ($wpc_inl109 !== '')
            ? $wpc_inl109
            : '<script id="wpc-delay-v3-loader" src="' . esc_url($wpc_loader_src) . '" async></script>';

        // Same anchor the v2 engine uses (printed by enqueues.class.php when delay is on); fall
        // back to </body> so the registry can never be emitted without its loader.
        if (strpos($html, '<script type="wpc-delay-placeholder"></script>') !== false) {
            $html = str_replace('<script type="wpc-delay-placeholder"></script>', $delay_script, $html);
        } else {
            $html = wpc_body_inject809($html, $delay_script);
        }

        $html = $this->wpc_defer_sweep527($html);
        $html = self::wpc_split_enforce564($html);

        return $html;
    }

    /**
     * FINAL DEFER SWEEP (v7.10.527).
     *
     * A render-blocking external in the head stops paint until it is fetched AND executed —
     * PSI measured jQuery at 1,200 ms duration on a page whose whole LCP delay was 2,060 ms.
     * The per-tag defer injection above should already have handled it and demonstrably did
     * not, so rather than infer why from a function that has been wrong twice this release,
     * this pass asserts the OUTCOME against the finished document.
     *
     * It is self-guarding: it re-derives safety from the page rather than trusting engine
     * state. A script is deferred only when nothing on the page can need it at parse.
     */
    private function wpc_defer_sweep527($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_defer_sweep', true)) {
            return $html;
        }
        // Inline companions: an -after companion calls its parent's API at parse, so the parent
        // cannot defer. -before companions are config setters and do NOT disqualify (.564) —
        // WP core's actual rule in filter_eligible_strategies.
        $wpc_pairs527 = [];
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+?)-after["\']/i', $html, $wpc_p527)) {
            foreach ((array) $wpc_p527[1] as $wpc_b527) {
                $wpc_pairs527[strtolower($wpc_b527)] = 1;
            }
        }
        // Bodies of inline scripts that will actually RUN (a type-swapped tag is a delay
        // placeholder and executes later, so it cannot need anything at parse).
        $wpc_exec527 = '';
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)([^>]*)>(.*?)<\/script>/is', $html, $wpc_i527, PREG_SET_ORDER)) {
            foreach ($wpc_i527 as $wpc_m527) {
                if (preg_match('/type=["\']([^"\']+)["\']/i', $wpc_m527[1], $wpc_t527)
                    && stripos($wpc_t527[1], 'javascript') === false) {
                    continue;
                }
                $wpc_exec527 .= "\n" . $wpc_m527[2];
            }
        }
        return preg_replace_callback('/<script\b[^>]*\bsrc=[^>]*>/i', function ($wpc_mm527) use ($wpc_pairs527, $wpc_exec527) {
            $wpc_tag527 = $wpc_mm527[0];
            if (preg_match('/\bdefer\b/i', $wpc_tag527) || preg_match('/\basync\b/i', $wpc_tag527)) {
                return $wpc_tag527;
            }
            if (preg_match('/type=["\']([^"\']+)["\']/i', $wpc_tag527, $wpc_ty527)
                && stripos($wpc_ty527[1], 'javascript') === false) {
                return $wpc_tag527;
            }
            $wpc_sid527 = preg_match('/\bid=["\']([^"\']+)["\']/i', $wpc_tag527, $wpc_id527)
                ? strtolower($wpc_id527[1]) : '';
            if ($wpc_sid527 !== '' && !empty($wpc_pairs527[$wpc_sid527])) {
                return $wpc_tag527;
            }
            // jQuery is the one global an executable inline can plausibly touch at parse.
            // Verified on the live page: 39 inline scripts type-swapped, 15 executable, ZERO
            // referencing jQuery — but re-check per page, never assume.
            if (preg_match('/jquery/i', $wpc_tag527)
                && preg_match('/\bjQuery\b|\$\(/', $wpc_exec527)) {
                return $wpc_tag527;
            }
            return preg_replace('/<script\b/i', '<script defer data-wpc-defer="1"', $wpc_tag527, 1);
        }, $html);
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
    private static function wpc_split_enforce564($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_defer_split', true)) {
            return $html;
        }
        if (strpos($html, 'data-wpc-defer="1"') === false) {
            return $html;
        }
        try {
            $wpc_after564 = [];
            if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*\bid=["\']([^"\']+?)-after["\']/i', $html, $wpc_a564)) {
                foreach ((array) $wpc_a564[1] as $wpc_id564) {
                    $wpc_after564[strtolower($wpc_id564)] = 1;
                }
            }
            if (empty($wpc_after564)) {
                return $html;
            }
            $wpc_split564 = -1;
            if (preg_match_all('/<script\b[^>]*\bsrc=[^>]*>/i', $html, $wpc_m564, PREG_OFFSET_CAPTURE)) {
                foreach ($wpc_m564[0] as $wpc_h564) {
                    $wpc_tag564 = $wpc_h564[0];
                    if (preg_match('/\b(?:defer|async)\b/i', $wpc_tag564)) {
                        continue;
                    }
                    if (preg_match('/type=["\']([^"\']+)["\']/i', $wpc_tag564, $wpc_ty564)
                        && stripos($wpc_ty564[1], 'javascript') === false) {
                        continue;
                    }
                    if (!preg_match('/\bid=["\']([^"\']+)["\']/i', $wpc_tag564, $wpc_i564)
                        || empty($wpc_after564[strtolower($wpc_i564[1])])) {
                        continue;
                    }
                    $wpc_split564 = max($wpc_split564, $wpc_h564[1] + strlen($wpc_tag564));
                }
            }
            if ($wpc_split564 < 0) {
                return $html;
            }
            return str_replace(' defer data-wpc-defer="1"', '', substr($html, 0, $wpc_split564))
                . substr($html, $wpc_split564);
        } catch (\Throwable $e) {
            return $html;
        }
    }
}
