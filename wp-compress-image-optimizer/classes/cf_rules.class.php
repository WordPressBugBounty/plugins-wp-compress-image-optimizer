<?php

/**
 * The one owner of the Cloudflare rules this plugin provisions: the cache rules and the WAF skip
 * rule that lets the service's origin fetches through the zone's security (Optimizer Bypass).
 *
 * It answers what the rules on this zone should be for this site right now (desired) and
 * whether the zone was last converged on exactly that (needs_converge). Callers pass a zone id;
 * none of them builds a rule.
 *
 * Rule: the desired state is resolved from every input that changes its bytes (the site's hosts,
 * the deploy-side combined/split mode, the TTL filters, the CF panel settings) and fingerprinted,
 * so an upgrade whose definitions did not move costs zero Cloudflare calls. Observed failure this
 * replaces: perkzilla.com kept override_origin rules from July 2026 while the code shipped
 * respect_origin, because the reassert lived in a once-per-site lane (wpc_cf_bypass_v7) and every
 * other patch path sat behind a keys round trip that timed out.
 */
class wps_ic_cf_rules
{
    const OPTION_CONVERGED = 'wpc_cf_rules_converged';
    const WAIT_TRANSIENT = 'wpc_cf_rules_converge_wait';
    const REF_BROWSER_TTL_MEDIA = 'wpc-browser-ttl-media';
    const REF_BROWSER_TTL_STATIC = 'wpc-browser-ttl-static';

    /**
     * The WAF skip rule's key in desired(). It is not a Cloudflare ref: the deployed rule has never
     * carried one and rotateCdnBypassToken() rewrites it by description, so it is matched by
     * SKIP_DESCRIPTION only.
     */
    const SKIP_KEY = 'wpc-origin-bypass';
    const SKIP_DESCRIPTION = 'Optimizer Bypass [DO NOT EDIT]';
    /** Stands for the bypass token in the desired expression; only a create fills it. */
    const SKIP_TOKEN_SLOT = '{bypass-token}';
    const SKIP_PHASES = ['http_request_firewall_managed', 'http_ratelimit', 'http_request_sbfm'];

    /** Requests that carry one of these query parameters are never served from the edge. */
    const QUERY_BYPASS = 'lower(http.request.uri.query) contains "nocache=" or lower(http.request.uri.query) contains "no-cache=" or lower(http.request.uri.query) contains "wc-ajax=" or lower(http.request.uri.query) contains "add-to-cart=" or lower(http.request.uri.query) contains "edd_action=" or lower(http.request.uri.query) contains "preview=" or lower(http.request.uri.query) contains "currency=" or lower(http.request.uri.query) contains "wc-api="';

    /** Visitors carrying one of these cookies (logged in, password post, cart) are never served from the edge. */
    const COOKIE_BYPASS = 'http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "wp_woocs_session_" or http.cookie contains "edd_"';

    /** The site host and its www twin: the host-scoped HTML rules must match both. */
    public static function hosts()
    {
        $host = (string) parse_url(get_site_url(), PHP_URL_HOST);
        if ($host === '') {
            return [];
        }
        return strpos($host, 'www.') === 0 ? [$host, substr($host, 4)] : [$host, 'www.' . $host];
    }

    private static function host_list()
    {
        return implode(' ', array_map(function ($host) { return '"' . $host . '"'; }, self::hosts()));
    }

    /**
     * True when the edge should hold one device-universal HTML copy (no device key).
     *
     * Rule: this is the DEPLOY desire (wpc_deploy_combined, no floors), never the floor-gated
     * render decision wpc_combined_crit_on(). v7.10.669 found the circular trap: a floor-gated
     * deploy strips the device key, the readback then never sees one, and the edge can never be
     * bootstrapped to split.
     */
    private static function combined_mode()
    {
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_deploy_combined')) {
            return (bool) wps_rewriteLogic::wpc_deploy_combined();
        }
        return false;
    }

    /**
     * The CF panel's two cache switches. An absent settings array reads as the connect default
     * (assets on, all HTML), the value every connect path writes; an empty or '0' edge-cache
     * reads as off, as the panel and wps_rewriteLogic read it.
     */
    private static function panel_settings()
    {
        $cf = get_option(WPS_IC_CF);
        if (!is_array($cf) || !isset($cf['settings']) || !is_array($cf['settings'])) {
            return ['assets' => true, 'edge-cache' => 'all'];
        }
        $settings = $cf['settings'];
        $edge = isset($settings['edge-cache']) ? (string) $settings['edge-cache'] : 'all';
        if ($edge === '' || $edge === '0') {
            $edge = 'off';
        }
        return ['assets' => (string) ($settings['assets'] ?? '1') !== '0', 'edge-cache' => $edge];
    }

    // ── definitions ──────────────────────────────────────────────────────────────────────────
    // Each shipped definition exists once, here; the SDK's remaining getters (used by
    // updateWPCCacheConfig) delegate to these.

    public static function bypass_rule()
    {
        return ['ref' => WPC_BYPASS_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Bypass cache for admin/login/commerce', 'enabled' => true,
            'expression' => '(http.request.method ne "GET" and http.request.method ne "HEAD") or (starts_with(http.request.uri.path, "/wp-admin") or http.request.uri.path contains "/wp-login.php" or http.request.uri.path contains "/wp-cron.php" or http.request.uri.path contains "/xmlrpc.php" or starts_with(http.request.uri.path, "/wp-json/") or http.request.uri.path contains "/admin-ajax.php" or http.request.uri.path contains "/cart/" or http.request.uri.path contains "/checkout/" or http.request.uri.path contains "/wc-api/" or http.request.uri.path contains "/my-account") or (' . self::COOKIE_BYPASS . ') or (' . self::QUERY_BYPASS . ')',
            'action_parameters' => ['cache' => false]];
    }

    /**
     * Statics: the edge respects the origin, the browser gets a year. v7.21.18: no 'default'
     * beside respect_origin, CF rejects the pair ("default is useless in respect_origin mode")
     * and every patch failed and retried forever.
     */
    public static function static_assets_rule()
    {
        return ['ref' => WPC_STATIC_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Static assets cache', 'enabled' => true,
            'expression' => '(http.request.method in {"GET" "HEAD"}) and lower(http.request.uri.path.extension) in {"css" "js" "mjs" "json" "map" "jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "ico" "ttf" "otf" "woff" "woff2" "eot" "mp4" "webm" "ogg"} and not starts_with(http.request.uri.path, "/cdn-cgi/")',
            'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'respect_origin'],
                'browser_ttl' => ['mode' => 'override_origin', 'default' => (int) apply_filters('wpc_cf_static_browser_ttl', 31536000)],
                'cache_key' => ['ignore_query_strings_order' => true]]];
    }

    /**
     * robots.txt and sitemaps are PHP-generated on most WP sites: every crawler request hits the
     * origin, and an origin micro-stall makes PSI/SEO fail the robots fetch. One hour at the edge
     * ends that failure class; staleness is harmless here.
     */
    public static function robots_rule()
    {
        return ['ref' => WPC_ROBOTS_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Robots + sitemap edge cache', 'enabled' => true,
            'expression' => '(http.request.method in {"GET" "HEAD"}) and (http.request.uri.path eq "/robots.txt" or ends_with(http.request.uri.path, "sitemap.xml") or ends_with(http.request.uri.path, "sitemap_index.xml"))',
            'action_parameters' => ['cache' => true,
                'edge_ttl' => ['mode' => 'override_origin', 'default' => (int) apply_filters('wpc_cf_robots_edge_ttl', 3600)],
                'browser_ttl' => ['mode' => 'respect_origin']]];
    }

    /**
     * HTML at the edge follows the origin's headers. An override pinned even no-store pages for
     * the full TTL (perkzilla.com, 2026-09-24: override_origin 1800 s masked the crit-less
     * no-store verdict), and device-keyed entries cannot be purged by URL on non-Enterprise
     * plans, so the origin's max-age=60 must-revalidate governs instead.
     *
     * Split mode keys the edge per device. Combined mode carries no cache_key at all: any
     * cache_key on a combined zone's HTML rule is drift (governed_keys), so the edge holds one
     * default-keyed copy per URL that a purge by URL reaches on every plan.
     */
    private static function html_action_parameters()
    {
        $params = ['cache' => true, 'edge_ttl' => ['mode' => 'respect_origin'], 'browser_ttl' => ['mode' => 'respect_origin'],
            'serve_stale' => ['disable_stale_while_updating' => false]];
        if (!self::combined_mode()) {
            $params['cache_key'] = ['cache_by_device_type' => true, 'ignore_query_strings_order' => true];
        }
        return $params;
    }

    public static function homepage_html_rule()
    {
        return ['ref' => WPC_HOMEPAGE_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Homepage HTML cache', 'enabled' => true,
            'expression' => '(http.host in {' . self::host_list() . '}) and (http.request.method in {"GET" "HEAD"}) and http.request.uri.path eq "/" and not (' . self::QUERY_BYPASS . ') and not starts_with(http.request.uri.path, "/cdn-cgi/") and not (' . self::COOKIE_BYPASS . ')',
            'action_parameters' => self::html_action_parameters()];
    }

    public static function full_html_rule()
    {
        return ['ref' => WPC_FULLHTML_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Full HTML cache', 'enabled' => true,
            'expression' => '(http.host in {' . self::host_list() . '}) and (http.request.method in {"GET" "HEAD"}) and not starts_with(http.request.uri.path, "/cdn-cgi/") and not starts_with(http.request.uri.path, "/wp-admin") and not (http.request.uri.path contains "/wp-login.php") and not starts_with(http.request.uri.path, "/wp-json/") and (http.request.uri.path.extension eq "" or lower(http.request.uri.path.extension) in {"html" "htm" "xhtml"}) and not (' . self::QUERY_BYPASS . ') and not (http.request.uri.path contains "/cart/" or http.request.uri.path contains "/checkout/" or http.request.uri.path contains "/wc-api/" or http.request.uri.path contains "/my-account" or http.request.uri.path contains "/admin-ajax.php" or http.request.uri.path contains "/wp-cron.php" or http.request.uri.path contains "/xmlrpc.php") and not (' . self::COOKIE_BYPASS . ')',
            'action_parameters' => self::html_action_parameters()];
    }

    /**
     * Browser TTL at the edge for origins that send no expiry (nginx ignores .htaccess):
     * immutable-content media a year, css/js a week (the htaccess values). Neither expression
     * can reach HTML.
     */
    public static function browser_ttl_rules()
    {
        return [
            self::REF_BROWSER_TTL_MEDIA => ['ref' => self::REF_BROWSER_TTL_MEDIA, 'action' => 'set_cache_settings', 'description' => 'WPC Browser TTL Media [DO NOT EDIT]', 'enabled' => true,
                'expression' => 'http.request.uri.path.extension in {"jpg" "jpeg" "png" "gif" "svg" "webp" "avif" "ico" "woff" "woff2" "ttf" "otf"}',
                'action_parameters' => ['browser_ttl' => ['mode' => 'override_origin', 'default' => 31536000]]],
            self::REF_BROWSER_TTL_STATIC => ['ref' => self::REF_BROWSER_TTL_STATIC, 'action' => 'set_cache_settings', 'description' => 'WPC Browser TTL Static [DO NOT EDIT]', 'enabled' => true,
                'expression' => 'http.request.uri.path.extension in {"css" "js"}',
                'action_parameters' => ['browser_ttl' => ['mode' => 'override_origin', 'default' => 604800]]],
        ];
    }

    public static function skip_expression($token)
    {
        return 'any(http.request.headers["x-origin-auth"][*] == "' . $token . '")';
    }

    /**
     * The WAF skip rule: a request carrying the service's x-origin-auth token skips the managed
     * rules, rate limiting, super bot fight mode and every later custom rule, and it must be the
     * FIRST custom rule because a skip only protects what runs after it (v7.21.07/.12, ridgeway:
     * the customer's "managed challenge if not in US" rule ran before ours and challenged the
     * pods). The same rule addCdnBypassRule() wrote since v7.21.12.
     *
     * The token is not part of the definition. It is minted by keys and changed only by
     * rotateCdnBypassToken(), which widens the deployed expression to old-or-new and tightens it
     * later, so converge never compares or rewrites a deployed expression; a create fills the slot
     * with the token keys answers at that moment.
     */
    public static function skip_rule()
    {
        return ['action' => 'skip', 'description' => self::SKIP_DESCRIPTION, 'enabled' => true,
            'expression' => self::skip_expression(self::SKIP_TOKEN_SLOT),
            'action_parameters' => ['products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],
                'phases' => self::SKIP_PHASES, 'ruleset' => 'current']];
    }

    /**
     * The rules this site wants on the zone right now, keyed by ref, in provisioning order.
     *
     * The panel's HTML mode picks ONE HTML rule, as the SDK's own panel writer does
     * (updateWPCCacheConfig): 'all' wants the full-HTML rule (it already covers "/"), 'home' wants
     * only the homepage rule, anything else wants neither. Desiring both would put every page at
     * the edge on a site whose owner chose homepage-only. The bypass rule is wanted whenever any
     * edge cache is on. The WAF skip rule (SKIP_KEY, last) is always wanted: the service fetches
     * the origin through this zone whatever the panel caches.
     *
     * Absent means "not ours to touch", never "delete", with one exception converge() owns: when
     * one HTML mode is selected, our rule of the other mode is removed (other_mode_html_rule()).
     */
    public static function desired($zoneId)
    {
        $panel = self::panel_settings();
        $htmlRef = '';
        if ($panel['edge-cache'] === 'all') {
            $htmlRef = WPC_FULLHTML_RULE_REF;
        } elseif ($panel['edge-cache'] === 'home') {
            $htmlRef = WPC_HOMEPAGE_RULE_REF;
        }
        if ($htmlRef !== '' && !(apply_filters('wpc_cf_html_respect_origin', true) && apply_filters('wpc_cf_html_ensure_rules', true))) {
            $htmlRef = '';
        }

        $set = [];
        if ($panel['assets'] || $htmlRef !== '') {
            $set[WPC_BYPASS_RULE_REF] = self::bypass_rule();
        }
        if ($panel['assets']) {
            $set[WPC_STATIC_RULE_REF] = self::static_assets_rule();
        }
        if ($htmlRef === WPC_FULLHTML_RULE_REF) {
            $set[WPC_FULLHTML_RULE_REF] = self::full_html_rule();
        } elseif ($htmlRef === WPC_HOMEPAGE_RULE_REF) {
            $set[WPC_HOMEPAGE_RULE_REF] = self::homepage_html_rule();
        }
        if (apply_filters('wpc_cf_browser_ttl', true)) {
            $set += self::browser_ttl_rules();
        }
        if (apply_filters('wpc_cf_robots_rule', true)) {
            $set[WPC_ROBOTS_RULE_REF] = self::robots_rule();
        }
        $set[self::SKIP_KEY] = self::skip_rule();
        return $set;
    }

    /** sha1 of the canonical JSON of desired(): keys sorted at every depth, refs included. */
    public static function fingerprint($zoneId)
    {
        $set = self::desired($zoneId);
        self::ksort_deep($set);
        return sha1((string) wp_json_encode($set));
    }

    private static function ksort_deep(&$value)
    {
        if (!is_array($value)) {
            return;
        }
        ksort($value);
        foreach ($value as &$child) {
            self::ksort_deep($child);
        }
        unset($child);
    }

    /**
     * No network: true when the zone was never converged, was converged as another zone, or on
     * a desired set that has since changed. The upgrade pass calls this on every version change,
     * so it must stay a local read.
     */
    public static function needs_converge($zoneId)
    {
        $record = get_option(self::OPTION_CONVERGED);
        if (!is_array($record) || (string) ($record['zone'] ?? '') !== (string) $zoneId) {
            return true;
        }
        return (string) ($record['fp'] ?? '') !== self::fingerprint($zoneId);
    }

    // ── converge ─────────────────────────────────────────────────────────────────────────────

    /**
     * The action_parameters keys a definition governs; anything else on the deployed rule is
     * kept. The HTML rules also govern cache_key when the definition leaves it out: combined mode
     * wants no cache key at all, so a deployed device key there is drift, not someone else's field.
     */
    private static function governed_keys(array $desired)
    {
        $keys = array_keys($desired['action_parameters']);
        if (in_array($desired['ref'], [WPC_HOMEPAGE_RULE_REF, WPC_FULLHTML_RULE_REF], true) && !in_array('cache_key', $keys, true)) {
            $keys[] = 'cache_key';
        }
        return $keys;
    }

    /**
     * The deployed rule for a definition: by ref, else by description for a rule an older release
     * created without a ref. perkzilla's two browser-TTL rules (2026-09-24 09:19) were POSTed
     * ref-less; matching by ref alone would create a duplicate of each on every converge.
     */
    private static function find_deployed(array $deployed, array $desired)
    {
        foreach ($deployed as $rule) {
            if (isset($rule['ref']) && $rule['ref'] === $desired['ref']) { return $rule; }
        }
        foreach ($deployed as $rule) {
            if (empty($rule['ref']) && isset($rule['description']) && $rule['description'] === $desired['description']) { return $rule; }
        }
        return null;
    }

    /** The hosts named in an expression's `http.host in {...}` set, [] when it has none. */
    private static function expression_hosts($expression)
    {
        if (!preg_match('/http\.host in \{([^}]*)\}/', (string) $expression, $m)) {
            return [];
        }
        preg_match_all('/"([^"]+)"/', $m[1], $hosts);
        return $hosts[1];
    }

    /** The expression with its host set replaced by $hosts, quoted, in the given order. */
    private static function with_hosts($expression, array $hosts)
    {
        $list = implode(' ', array_map(function ($host) { return '"' . $host . '"'; }, $hosts));
        return preg_replace('/http\.host in \{[^}]*\}/', 'http.host in {' . $list . '}', (string) $expression, 1);
    }

    /**
     * Host-scoped expressions accumulate other sites' hosts on a shared zone, so "equal" means:
     * this site's hosts are named and, with the host set put aside, the expression is the
     * definition's. That also rejects every retired form (the tk_ai carve-out, the starts_with
     * my-account form, a rule without add-to-cart), which the old patchers each tested for one by
     * one. Expressions without a host set compare byte for byte.
     */
    private static function expression_ok(array $deployed, array $desired)
    {
        $expr = (string) ($deployed['expression'] ?? '');
        if (strpos($desired['expression'], 'http.host in {') === false) {
            return $expr === $desired['expression'];
        }
        $deployedHosts = self::expression_hosts($expr);
        foreach (self::hosts() as $host) {
            if (!in_array($host, $deployedHosts, true)) { return false; }
        }
        return self::with_hosts($desired['expression'], $deployedHosts) === $expr;
    }

    private static function parameters_ok(array $deployed, array $desired)
    {
        $ap = isset($deployed['action_parameters']) && is_array($deployed['action_parameters']) ? $deployed['action_parameters'] : [];
        foreach (self::governed_keys($desired) as $k) {
            $want = $desired['action_parameters'][$k] ?? null;
            $have = $ap[$k] ?? null;
            if ($k === 'cache_key') {
                if ($want === null) {
                    if (!empty($have)) { return false; }
                    continue;
                }
                // Only the flags we set are compared; Cloudflare adds its own defaults to the readback.
                foreach ((array) $want as $flag => $v) { if (($have[$flag] ?? null) != $v) { return false; } }
                if (!isset($want['cache_by_device_type']) && !empty($have['cache_by_device_type'])) { return false; }
                continue;
            }
            if ($want != $have) { return false; }
        }
        return !empty($deployed['enabled']) === !empty($desired['enabled']);
    }

    /**
     * The PATCH body: the deployed rule with the desired enabled flag and governed parameters over
     * it. The expression is replaced only when expression_ok() failed, and then keeps every host
     * the deployed one named (another site on the zone put them there) plus this site's.
     */
    private static function patch_body(array $rule, array $desired, $expressionOk)
    {
        $patch = $rule;
        if (!$expressionOk) {
            $patch['expression'] = $desired['expression'];
            if (strpos($desired['expression'], 'http.host in {') !== false) {
                $hosts = array_values(array_unique(array_merge(self::expression_hosts($rule['expression'] ?? ''), self::hosts())));
                $patch['expression'] = self::with_hosts($desired['expression'], $hosts);
            }
        }
        $patch['enabled'] = $desired['enabled'];
        $patch['action_parameters'] = is_array($rule['action_parameters'] ?? null) ? $rule['action_parameters'] : [];
        foreach (self::governed_keys($desired) as $k) {
            if (isset($desired['action_parameters'][$k])) {
                $patch['action_parameters'][$k] = $desired['action_parameters'][$k];
            } else {
                unset($patch['action_parameters'][$k]);
            }
        }
        unset($patch['last_updated'], $patch['version']);
        return $patch;
    }

    /**
     * The HTML definition of the panel mode that is NOT selected, or null when no HTML mode is
     * selected ('off', or the HTML rules filtered off: then neither rule is converge's to remove).
     */
    private static function other_mode_html_rule(array $desiredSet)
    {
        if (isset($desiredSet[WPC_FULLHTML_RULE_REF])) { return self::homepage_html_rule(); }
        if (isset($desiredSet[WPC_HOMEPAGE_RULE_REF])) { return self::full_html_rule(); }
        return null;
    }

    /**
     * Removes this site from our deployed rule of the other HTML mode: the whole rule when it names
     * only this site's hosts (or no host set), else only this site's hosts, because on a zone other
     * sites share the rest of the host set is theirs (the same domain-by-domain rule as
     * updateWPCCacheConfig). A rule that does not name this site is another site's: null, no write.
     * Returns [action, write answer] or null.
     */
    private static function remove_other_mode_rule($sdk, $zoneId, array $leftover)
    {
        $deployedHosts = self::expression_hosts($leftover['expression'] ?? '');
        $remainingHosts = array_values(array_diff($deployedHosts, self::hosts()));
        if ($deployedHosts !== [] && count($remainingHosts) === count($deployedHosts)) {
            return null;
        }
        if ($remainingHosts === []) {
            return ['deleted', $sdk->deleteCacheRule($zoneId, $leftover['id'])];
        }
        $patch = $leftover;
        $patch['expression'] = self::with_hosts($leftover['expression'], $remainingHosts);
        unset($patch['last_updated'], $patch['version']);
        return ['hosts-removed', $sdk->patchCacheRule($zoneId, $leftover['id'], $patch)];
    }

    /** A write answer is ok unless it is a WP_Error or says success=false. */
    private static function write_ok($resp)
    {
        return !is_wp_error($resp) && (!is_array($resp) || !array_key_exists('success', $resp) || $resp['success']);
    }

    /**
     * One readback, then per desired rule: missing → create, drifted → PATCH, equal → skip. Rules
     * outside desired() are never touched, except our own HTML rule of the panel mode not selected:
     * one HTML rule per mode holds on the zone too, so converge removes it (receipt field `removed`
     * names it). Observed failure: a July-form homepage rule left on an "all" site keeps pinning "/"
     * with override_origin 1800 while converge reported the zone converged, and a device-blind
     * leftover turns the device-key stamp false. Only a rule recognised as ours (our ref, or our
     * description on a ref-less rule) that names this site is removed; a foreign rule is never
     * touched. It is removed only after the selected mode's rule converged, so the zone never goes
     * without an HTML rule for this site.
     *
     * Records the fingerprint only when every rule converged. A readback failure writes nothing
     * (withhold rather than guess: a failed list read as "rule missing" is how duplicate rules were
     * created) and arms an hour's wait, as does any refused write, so a zone that keeps failing is
     * not hit again by every automatic caller; Refresh Connection ('refresh') always runs.
     *
     * The device-key stamp reads the ruleset Cloudflare answered to the last write (a rule write
     * answers the whole ruleset), or the readback when nothing was written, so the zone is read once.
     */
    public static function converge($zoneId, $reason)
    {
        $t0 = microtime(true);
        $report = ['ok' => false, 'why' => '', 'rules' => [], 'ms' => 0];
        if (empty($zoneId)) { $report['why'] = 'no-zone'; return $report; }
        if ($reason !== 'refresh' && get_transient(self::WAIT_TRANSIENT)) { $report['why'] = 'wait'; return $report; }
        $cf = get_option(WPS_IC_CF);
        if (!is_array($cf) || empty($cf['token'])) { $report['why'] = 'no-token'; return $report; }
        $sdk = new WPC_CloudflareAPI($cf['token']);

        $deployed = $sdk->listCacheRules($zoneId);
        if (is_wp_error($deployed)) {
            set_transient(self::WAIT_TRANSIENT, 1, HOUR_IN_SECONDS);
            $report['why'] = 'readback:' . substr($deployed->get_error_message(), 0, 120);
            self::receipt($reason, $report, $t0);
            return $report;
        }

        $latestRules = (array) $deployed;
        $failed = 0; $patched = 0; $created = 0;
        $desiredSet = self::desired($zoneId);
        $skipDesired = $desiredSet[self::SKIP_KEY];
        unset($desiredSet[self::SKIP_KEY]);
        foreach ($desiredSet as $ref => $desired) {
            $rule = self::find_deployed((array) $deployed, $desired);
            if (!$rule) {
                $resp = $sdk->addCacheRule($zoneId, $desired);
                $action = 'created';
            } else {
                $expressionOk = self::expression_ok($rule, $desired);
                if ($expressionOk && self::parameters_ok($rule, $desired)) { $report['rules'][$ref] = 'equal'; continue; }
                $resp = $sdk->patchCacheRule($zoneId, $rule['id'], self::patch_body($rule, $desired, $expressionOk));
                $action = 'patched';
            }
            if (!self::write_ok($resp)) {
                $report['rules'][$ref] = 'failed:' . self::why($resp);
                $failed++;
                continue;
            }
            $report['rules'][$ref] = $action;
            if ($action === 'created') { $created++; } else { $patched++; }
            // null = the answer carried no ruleset: the stamp below then reads the zone itself.
            $latestRules = (is_array($resp) && isset($resp['result']['rules']) && is_array($resp['result']['rules'])) ? $resp['result']['rules'] : null;
        }

        $removed = '';
        $otherModeRule = self::other_mode_html_rule($desiredSet);
        $leftover = $otherModeRule ? self::find_deployed((array) $deployed, $otherModeRule) : null;
        $selectedHtmlOutcome = (string) ($report['rules'][WPC_FULLHTML_RULE_REF] ?? $report['rules'][WPC_HOMEPAGE_RULE_REF] ?? '');
        if ($leftover && !empty($leftover['id']) && strpos($selectedHtmlOutcome, 'failed') !== 0) {
            $removal = self::remove_other_mode_rule($sdk, $zoneId, $leftover);
            if ($removal !== null) {
                list($action, $resp) = $removal;
                $otherRef = $otherModeRule['ref'];
                if (!self::write_ok($resp)) {
                    $report['rules'][$otherRef] = 'failed:' . self::why($resp);
                    $failed++;
                } else {
                    $report['rules'][$otherRef] = $action;
                    $removed = $otherRef . ':' . $action . ':' . $leftover['id'];
                    $latestRules = (is_array($resp) && isset($resp['result']['rules']) && is_array($resp['result']['rules'])) ? $resp['result']['rules'] : null;
                }
            }
        }

        // The WAF skip rule, in its own phase. A token without the Zone WAF permission is an
        // optional feature the panel reports, not a failure: counting it would keep every such
        // zone unconverged and re-asked on every upgrade.
        $skip = self::converge_skip($sdk, $zoneId, $skipDesired);
        $report['rules'][self::SKIP_KEY] = $skip['outcome'];
        $report['skip_twins'] = (int) ($skip['twins'] ?? 0);
        if (strpos($skip['outcome'], 'failed') === 0) {
            $failed++;
        } elseif ($skip['outcome'] === 'created') {
            $created++;
        } elseif (in_array($skip['outcome'], ['recreated', 'patched'], true)) {
            $patched++;
        }

        $report['ok'] = ($failed === 0);
        if ($report['ok']) {
            update_option(self::OPTION_CONVERGED, ['fp' => self::fingerprint($zoneId), 'zone' => (string) $zoneId, 't' => time()], false);
            delete_transient(self::WAIT_TRANSIENT);
        } else {
            set_transient(self::WAIT_TRANSIENT, 1, HOUR_IN_SECONDS);
        }
        $sdk->recordHtmlKeyState($zoneId, self::combined_mode(), $latestRules);
        $report['tiered'] = $sdk->applyTieredVerdict($zoneId);
        self::receipt($reason, $report, $t0, $patched, $created, $failed, $removed);
        return $report;
    }

    // ── Vary for Images ──────────────────────────────────────────────────────────────────────

    /** The MIME types the Apache negotiation block may answer each image extension with. */
    public static function vary_images_value()
    {
        return [
            'jpeg' => ['image/webp', 'image/avif'],
            'jpg'  => ['image/webp', 'image/avif'],
            'png'  => ['image/webp', 'image/avif'],
            'gif'  => ['image/webp', 'image/avif'],
        ];
    }

    /**
     * Sets Cloudflare's "Vary for Images" on the zone and keeps the witness the negotiated-body
     * policy reads (WPC_Delivery_Resolver::negotiated_cache_policy). One read; a write only when the
     * zone does not already vary every extension the block negotiates; a readback after the write;
     * the witness (WPC_CF_VARY_IMAGES_OPTION = {t, zone}) only on a readback that matches, cleared
     * on anything else. When the witness changes the negotiation block is rewritten there and then.
     *
     * Answers ['result' => equal | set | refused | permission | mismatch | failed | off, 'code' => the
     * Cloudflare error code or ''], receipt cf-vary-images. Rule: public negotiated bodies only when
     * the edge is proven to key them by Accept. Observed failure: Cloudflare ignores Vary: Accept
     * without this setting, so a webp body cached under a .jpg URL reached Safari 15 (7.21.34); the
     * setting is Pro and above and API-only, so a Free zone answers an error here ('refused') and
     * keeps the private floor. WPC_CF_VARY_IMAGES_OFF skips the attempt and clears the witness.
     */
    public static function converge_vary_images($sdk, $zoneId)
    {
        if (defined('WPC_CF_VARY_IMAGES_OFF') && WPC_CF_VARY_IMAGES_OFF) {
            return self::vary_images_outcome($zoneId, 'off', '', false);
        }
        $current = $sdk->getCacheVariants($zoneId);
        if (!is_wp_error($current) && self::vary_images_cover($current)) {
            return self::vary_images_outcome($zoneId, 'equal', '', true);
        }
        $written = $sdk->patchCacheVariants($zoneId, self::vary_images_value());
        if (is_wp_error($written) || (is_array($written) && array_key_exists('success', $written) && !$written['success'])) {
            list($result, $code) = self::vary_images_refusal($written);
            return self::vary_images_outcome($zoneId, $result, $code, false);
        }
        $readback = $sdk->getCacheVariants($zoneId);
        if (!is_wp_error($readback) && self::vary_images_cover($readback)) {
            return self::vary_images_outcome($zoneId, 'set', '', true);
        }
        return self::vary_images_outcome($zoneId, is_wp_error($readback) ? 'failed' : 'mismatch',
            is_wp_error($readback) ? self::vary_images_refusal($readback)[1] : '', false);
    }

    /** Does a variants answer vary every extension the block negotiates, for every type it serves? */
    private static function vary_images_cover($answer)
    {
        $value = is_array($answer) && isset($answer['result']['value']) && is_array($answer['result']['value']) ? $answer['result']['value'] : null;
        if ($value === null) { return false; }
        foreach (self::vary_images_value() as $ext => $types) {
            if (!isset($value[$ext]) || !is_array($value[$ext]) || array_diff($types, $value[$ext]) !== []) { return false; }
        }
        return true;
    }

    /** [result, code] for a refused call: an answer from Cloudflare is 'refused' (or 'permission'), a
     *  call that never got one (timeout, non-JSON, 429) is 'failed'. */
    private static function vary_images_refusal($resp)
    {
        $code = '';
        $data = is_wp_error($resp) ? $resp->get_error_data() : (is_array($resp) ? ($resp['errors'] ?? null) : null);
        if (is_array($data) && isset($data[0]['code'])) { $code = (string) $data[0]['code']; }
        if ((WPC_CloudflareAPI::classifyResult($resp)['mode'] ?? '') === 'permission') { return ['permission', $code]; }
        if ($code !== '' || (is_array($resp) && !is_wp_error($resp))) { return ['refused', $code]; }
        return ['failed', is_wp_error($resp) ? (string) $resp->get_error_code() : ''];
    }

    private static function vary_images_outcome($zoneId, $result, $code, $witnessed)
    {
        $before = class_exists('WPC_Delivery_Resolver') && WPC_Delivery_Resolver::cf_vary_images_witnessed();
        if ($witnessed) {
            update_option(WPC_CF_VARY_IMAGES_OPTION, ['t' => time(), 'zone' => (string) $zoneId], false);
        } else {
            delete_option(WPC_CF_VARY_IMAGES_OPTION);
        }
        $after = class_exists('WPC_Delivery_Resolver') && WPC_Delivery_Resolver::cf_vary_images_witnessed();
        if ($before !== $after && class_exists('wps_ic_htaccess')) {
            (new wps_ic_htaccess())->syncWebpReplace(get_option(WPS_IC_SETTINGS));
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cf-vary-images', '', '', ['result' => $result, 'code' => $code, 'witness' => $after ? 1 : 0]);
        }
        return ['result' => $result, 'code' => $code];
    }

    // ── the WAF skip rule ────────────────────────────────────────────────────────────────────

    /**
     * Converges the WAF skip rule: one read of the custom-rules entrypoint, then equal → no write;
     * missing → created first (the token is fetched from keys only now); present but not first →
     * deleted and created first with the deployed expression; first but without the phases or
     * ruleset:'current' shape, or disabled → PATCHed in place with the deployed products and
     * expression. Answers ['outcome' => equal | created | recreated | patched | kept:<why> |
     * legacy | permission | failed:<why>, 'result' => what addCdnBypassRule() answers, 'twins' =>
     * how many OTHER custom rules test the x-origin-auth header]. Twins are counted, never touched:
     * keys' updateCFConfig (called by configureCF on a CF panel save) writes its own
     * "WP Compress CDN Bypass" rule of another shape (keys cfSDK ensureCdnBypassRule, June 2026
     * source), so a zone can carry two token skips that rotation does not keep in step.
     *
     * The companion rules (renderer ASN allow, CDN host exempt) run first, unchanged, as they did
     * inside addCdnBypassRule(): the host exempt positions itself first, and this rule then moves
     * back in front of it once.
     *
     * Rule: this and rotation are the only writers of the skip rule. Observed failure it replaces:
     * three healers re-asserted it (the doctrine reconciler once per version, origin-reach through
     * keys cdn_setcname_v6, which carries no Cloudflare token and cannot write a zone rule, and
     * Refresh/Connect through addCdnBypassRule), none of them fed one converged state, and keys
     * was asked for the token on every call even when the rule was present.
     */
    public static function converge_skip($sdk, $zoneId, array $desired = null)
    {
        $desired = $desired ?: self::skip_rule();
        try { $sdk->addRendererAllowRule($zoneId); } catch (\Throwable $e) {}
        try { $sdk->addCdnHostExemptRule($zoneId); } catch (\Throwable $e) {}

        $entry = $sdk->getFirewallCustomEntrypoint($zoneId);
        if (is_wp_error($entry)) {
            $mode = WPC_CloudflareAPI::classifyResult($entry)['mode'] ?? '';
            return ['outcome' => $mode === 'permission' ? 'permission' : 'failed:readback:' . self::why($entry), 'result' => $entry];
        }
        $index = null;
        $twins = 0;
        foreach ($entry['rules'] as $i => $rule) {
            if ($index === null && ($rule['description'] ?? '') === self::SKIP_DESCRIPTION && !empty($rule['id'])) { $index = $i; continue; }
            if (strpos((string) ($rule['expression'] ?? ''), 'x-origin-auth') !== false) { $twins++; }
        }
        $answer = self::converge_skip_found($sdk, $zoneId, $desired, $entry, $index);
        $answer['twins'] = $twins;
        return $answer;
    }

    /** The write half of converge_skip(): $index is our rule's position in $entry, null when absent. */
    private static function converge_skip_found($sdk, $zoneId, array $desired, array $entry, $index)
    {
        if ($index === null) {
            if ($sdk->hasLegacyBypassRule($zoneId)) {
                return ['outcome' => 'legacy', 'result' => true];
            }
            $token = $sdk->getCdnBypassToken();
            if (!$token) {
                return ['outcome' => 'failed:no-bypass-token', 'result' => false];
            }
            $rule = $desired;
            $rule['expression'] = self::skip_expression($token);
            return self::skip_write_outcome('created', self::create_skip_first($sdk, $zoneId, $entry, $rule));
        }

        $deployed = $entry['rules'][$index];
        $params = is_array($deployed['action_parameters'] ?? null) ? $deployed['action_parameters'] : [];
        if ($index === 0 && !empty($params['phases']) && !empty($params['ruleset']) && !empty($deployed['enabled'])) {
            return ['outcome' => 'equal', 'result' => true];
        }
        if (!apply_filters('wpc_cf_bypass_phase_upgrade', true)) {
            return ['outcome' => 'kept:filtered', 'result' => true];
        }
        $expression = (string) ($deployed['expression'] ?? '');
        if ($index !== 0 && $expression !== '') {
            $deleted = $sdk->deleteFirewallCustomRule($zoneId, $entry['ruleset'], $deployed['id']);
            if (self::write_ok($deleted) && !empty($deleted['success'])) {
                $rule = $desired;
                $rule['expression'] = $expression;
                $rest = $entry;
                unset($rest['rules'][$index]);
                $rest['rules'] = array_values($rest['rules']);
                return self::skip_write_outcome('recreated', self::create_skip_first($sdk, $zoneId, $rest, $rule));
            }
            // Delete refused: upgrade the shape where it stands, as addCdnBypassRule did.
        }
        $patch = ['action' => 'skip', 'description' => self::SKIP_DESCRIPTION, 'enabled' => true, 'expression' => $expression,
            'action_parameters' => array_merge($params, ['phases' => self::SKIP_PHASES, 'ruleset' => 'current'])];
        $resp = $sdk->patchFirewallCustomRule($zoneId, $entry['ruleset'], $deployed['id'], $patch);
        if (!self::write_ok($resp) || empty($resp['success'])) {
            // Some plans reject the sbfm phase.
            $patch['action_parameters']['phases'] = array_values(array_diff(self::SKIP_PHASES, ['http_request_sbfm']));
            $resp = $sdk->patchFirewallCustomRule($zoneId, $entry['ruleset'], $deployed['id'], $patch);
        }
        if (self::write_ok($resp) && !empty($resp['success'])) {
            return ['outcome' => 'patched', 'result' => $resp];
        }
        // The rule is present and still skips; the zone refuses the fuller shape. Not a failure,
        // as before: a zone on such a plan would otherwise never converge.
        return ['outcome' => 'kept:' . self::why($resp), 'result' => true];
    }

    /**
     * The create ladder of v7.21.07/.12: the full shape first, then without sbfm, then products +
     * ruleset:'current', then products only (some plans reject phases); each positioned before the
     * first custom rule, and appended when the position is refused (better last than absent).
     */
    private static function create_skip_first($sdk, $zoneId, array $entry, array $rule)
    {
        $products = $rule['action_parameters']['products'];
        $shapes = [
            $rule['action_parameters'],
            ['products' => $products, 'phases' => array_values(array_diff(self::SKIP_PHASES, ['http_request_sbfm'])), 'ruleset' => 'current'],
            ['products' => $products, 'ruleset' => 'current'],
            ['products' => $products],
        ];
        $first = !empty($entry['rules'][0]['id']) ? (string) $entry['rules'][0]['id'] : '';
        $resp = false;
        foreach ($shapes as $shape) {
            $rule['action_parameters'] = $shape;
            if ($entry['ruleset'] !== '' && $first !== '') {
                $resp = $sdk->postFirewallCustomRule($zoneId, $entry['ruleset'], $rule + ['position' => ['before' => $first]]);
                if (self::write_ok($resp) && !empty($resp['success'])) { return $resp; }
            }
            $resp = $sdk->postFirewallCustomRule($zoneId, $entry['ruleset'], $rule);
            if (self::write_ok($resp) && !empty($resp['success'])) { return $resp; }
        }
        return $resp;
    }

    private static function skip_write_outcome($action, $resp)
    {
        if (self::write_ok($resp) && !empty($resp['success'])) {
            return ['outcome' => $action, 'result' => $resp];
        }
        $mode = WPC_CloudflareAPI::classifyResult($resp)['mode'] ?? '';
        return ['outcome' => $mode === 'permission' ? 'permission' : 'failed:' . self::why($resp), 'result' => $resp];
    }

    private static function why($resp)
    {
        if (is_wp_error($resp)) { return substr($resp->get_error_message(), 0, 120); }
        if (is_array($resp) && !empty($resp['errors'][0]['message'])) { return substr((string) $resp['errors'][0]['message'], 0, 120); }
        return 'unknown';
    }

    private static function receipt($reason, array &$report, $t0, $patched = 0, $created = 0, $failed = 0, $removed = '')
    {
        $report['ms'] = (int) round((microtime(true) - $t0) * 1000);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cf-rules-converged', '', '', ['reason' => (string) $reason, 'ok' => $report['ok'] ? 1 : 0, 'why' => $report['why'],
                'patched' => $patched, 'created' => $created, 'failed' => $failed, 'removed' => $removed,
                'skip' => (string) ($report['rules'][self::SKIP_KEY] ?? ''), 'skip_twins' => (int) ($report['skip_twins'] ?? 0), 'ms' => $report['ms']]);
        }
    }
}
