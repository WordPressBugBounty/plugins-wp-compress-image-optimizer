<?php

// ─── PHP 7.4 POLYFILLS (v7.10.612) ──────────────────────────────────────────────
// readme.txt declares "Requires PHP: 7.4" and the advanced-cache drop-in admits anything >= 7.2,
// but nine str_contains/str_starts_with/str_ends_with calls ship across advancedCache.php,
// fonts.class.php, rewriteLogic.php and integrations/elementor.php with no polyfill anywhere.
// Those are PHP 8.0+. On 7.4 they are a fatal — and in advancedCache.php that fatal happens in
// the drop-in, before WordPress loads, on EVERY request: a white screen, not a degraded page.
// Defined in BOTH defines.php and traits/url_key.php because the drop-in loads url_key.php and
// never loads defines.php. function_exists-guarded, so double definition is impossible.
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strpos((string) $haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strncmp((string) $haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}


class wps_ic_url_key
{
    /** How long a signed-in visitor's folder may sit untouched before the daily sweep removes it: the longest a WordPress login cookie lives (Remember Me, 14 days). */
    const USER_FOLDER_IDLE_SECONDS = 1209600;

    public $urlKey;
    public $url;
    public $trp_active;
    public $trp_settings;
	private static $url_mappings = [];

    /**
     * Captured host+URI from before translation plugins (e.g. Weglot) rewrite
     * $_SERVER['REQUEST_URI'] to strip the language prefix.  Set once, early,
     * via captureRequestUrl() and reused by every subsequent setup() call that
     * has no explicit $url argument.
     */
    private static $captured_request_url = null;

    /**
     * Lock in the current host + REQUEST_URI.  Call this as early as possible
     * (e.g. at plugins_loaded priority 1) before any translation plugin has a
     * chance to modify $_SERVER['REQUEST_URI'].
     */
    public static function captureRequestUrl()
    {
        if (self::$captured_request_url === null) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri  = $_SERVER['REQUEST_URI'] ?? '';
            self::$captured_request_url = $host . $uri;
        }
    }

    public function __construct()
    {
        $this->trp_active = 0;

        if (class_exists('TRP_Translate_Press')) {
            $this->trp_active = 1;
            $this->trp_settings = get_option('trp_settings');
        }
    }

    /**
     * The host + URI this request keys from: the value captureRequestUrl() locked in before any
     * translation plugin could rewrite REQUEST_URI, or the live one when nothing captured.
     *
     * setup() reads it through here so that a caller which has to CHANGE the request URL before
     * keying it — wpc_crit_push_url_key() strips the parameters the crit push added to its own
     * fetch — starts from the same bytes setup() would have keyed, not from a second, unprotected
     * reading of $_SERVER.
     */
    public static function requestUrl()
    {
        if (self::$captured_request_url !== null) {
            return self::$captured_request_url;
        }

        return ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    }

    public static function cookieSegment($value)
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $value = (string) $value;
        if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $value) === 1) {
            return $value;
        }

        return 'x' . substr(md5($value), 0, 16);
    }

    /**
     * The one character that joins a page key to the cookie segments of its variant folder. A page
     * key is [%a-z0-9_-] and a segment is [A-Za-z0-9_-], so this character is in neither: a folder
     * name splits back into exactly one key and one list of segments.
     */
    public static function variantSeparator()
    {
        return '~';
    }

    /**
     * One of the three cookie lists, as the Cache Cookies row (`wps_ic_cache_cookies`, read when its
     * switch is on) and the filter that adds to it name it, as a list of strings. $rowKey is the
     * list's key in the row, '' for a list the row does not hold.
     */
    private static function cookieListFromSettings($rowKey, $filter)
    {
        $list = [];
        if ($rowKey !== '' && function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $settings = get_option(WPS_IC_SETTINGS);
            if (is_array($settings) && !empty($settings['cache']['cookies']) && $settings['cache']['cookies'] == 1) {
                $stored = get_option('wps_ic_cache_cookies', []);
                if (is_array($stored) && !empty($stored[$rowKey])) {
                    $list = $stored[$rowKey];
                }
            }
        }
        if (function_exists('apply_filters')) {
            $list = apply_filters($filter, $list);
        }

        $names = [];
        foreach ((array) $list as $name) {
            if (is_scalar($name)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The cookies whose value varies a page's copy: the one derivation the advanced-cache drop-in
     * is baked from (WPC_CACHE_COOKIES) and the one an install with no drop-in reads at request time.
     */
    public static function cacheCookiesFromSettings()
    {
        return self::cookieListFromSettings('cookies', 'wps_ic_cache_cookies');
    }

    /** The cookies whose presence keeps a visit out of the cache (WPC_EXCLUDE_COOKIES is baked from this). */
    public static function excludeCookiesFromSettings()
    {
        return self::cookieListFromSettings('exclude_cookies', 'wps_ic_exclude_cookies');
    }

    /** The cookies a visit must carry to be cached (WPC_MANDATORY_COOKIES is baked from this); the filter alone names them. */
    public static function mandatoryCookiesFromSettings()
    {
        return self::cookieListFromSettings('', 'wps_ic_mandatory_cookies');
    }

    /**
     * The cookies that vary the copy for this request. The drop-in's baked constant is the answer
     * when it is defined (`false` included: the row is off); an install with no drop-in reads the
     * row, as cacheQueryParams() does for its lists.
     */
    public static function cacheCookies()
    {
        if (defined('WPC_CACHE_COOKIES')) {
            return WPC_CACHE_COOKIES === false ? [] : (array) WPC_CACHE_COOKIES;
        }

        return self::cacheCookiesFromSettings();
    }

    /** The exclude-cookie list for this request, by the contract of cacheCookies(): a visit with one of them is never stored. */
    public static function excludeCookies()
    {
        if (defined('WPC_EXCLUDE_COOKIES')) {
            return WPC_EXCLUDE_COOKIES === false ? [] : (array) WPC_EXCLUDE_COOKIES;
        }

        return self::excludeCookiesFromSettings();
    }

    /** The mandatory-cookie list for this request, by the contract of cacheCookies(): a visit without one is never stored. */
    public static function mandatoryCookies()
    {
        if (defined('WPC_MANDATORY_COOKIES')) {
            return WPC_MANDATORY_COOKIES === false ? [] : (array) WPC_MANDATORY_COOKIES;
        }

        return self::mandatoryCookiesFromSettings();
    }

    /**
     * The name of the cookie that says this visitor is signed in: WordPress's LOGGED_IN_COOKIE when
     * the request carries it, else the first cookie with the default prefix. The advanced-cache
     * drop-in runs before WordPress defines LOGGED_IN_COOKIE, so there it is known only when
     * wp-config.php defines it; the prefix finds the default name.
     */
    public static function loginCookieName()
    {
        if (defined('LOGGED_IN_COOKIE') && is_string(LOGGED_IN_COOKIE) && LOGGED_IN_COOKIE !== '' && isset($_COOKIE[LOGGED_IN_COOKIE])) {
            return LOGGED_IN_COOKIE;
        }
        foreach ((array) $_COOKIE as $name => $value) {
            if (strpos((string) $name, 'wordpress_logged_in_') === 0) {
                return (string) $name;
            }
        }

        return '';
    }

    /**
     * The folder level of a signed-in visitor's copy ("<32 hex>/"), or '' when the copy is not kept
     * per visitor: Cache Logged-In Users is off in the drop-in (WPC_CACHE_LOGGED_IN), or the
     * request carries no login cookie value. The store verdict refuses a signed-in render whose copy
     * has none, so such a render is never stored under the key every anonymous visitor reads.
     * The name is a hash of the cookie's name and its whole value (username, expiration, session
     * token and HMAC): the drop-in runs before WordPress can check the cookie, so a name made of
     * anything a visitor can write without the real cookie, such as the username, would let a forged
     * cookie read another visitor's folder. One folder per login session follows.
     */
    public static function userFolder()
    {
        if (!defined('WPC_CACHE_LOGGED_IN') || !WPC_CACHE_LOGGED_IN) {
            return '';
        }
        $name = self::loginCookieName();
        if ($name === '' || !is_string($_COOKIE[$name]) || $_COOKIE[$name] === '') {
            return '';
        }

        return substr(hash('sha256', $name . "\0" . $_COOKIE[$name]), 0, 32) . '/';
    }

    /**
     * The time a login cookie value expires: the second field of the four WordPress writes
     * (user|expiration|token|hmac), the one wp_validate_auth_cookie() reads first. 0 when the value
     * has not that shape.
     */
    public static function loginCookieExpiry($value)
    {
        if (!is_string($value)) {
            return 0;
        }
        $fields = explode('|', $value);
        if (count($fields) !== 4 || preg_match('/^[0-9]{1,12}\z/', $fields[1]) !== 1) {
            return 0;
        }

        return (int) $fields[1];
    }

    /**
     * Whether userFolder() names a folder for a login cookie that signs no one in: its expiration is
     * past, or it is not of the shape WordPress writes. No stored copy is served from such a folder
     * and no visit refreshes it; WordPress answers the visit. A visit whose cookie names no folder
     * (Cache Logged-In Users off, an empty value) is not this.
     */
    public static function userFolderDead()
    {
        if (self::userFolder() === '') {
            return false;
        }

        return self::loginCookieExpiry($_COOKIE[self::loginCookieName()]) < time();
    }

    public static function cookieSuffix()
    {
        $cookies = self::cacheCookies();
        if (!$cookies) {
            return '';
        }

        $segments = [];
        foreach ($cookies as $cookie_name) {
            if (substr($cookie_name, -1) === '_') {
                foreach ($_COOKIE as $actual_cookie_name => $cookie_value) {
                    if (strpos($actual_cookie_name, $cookie_name) === 0 && !empty($cookie_value)) {
                        $suffix = substr($actual_cookie_name, strlen($cookie_name));
                        $segments[] = self::cookieSegment($cookie_value) . '_' . substr(hash('md5', $suffix), 0, 7);
                    }
                }
            } elseif (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
                $segments[] = self::cookieSegment($_COOKIE[$cookie_name]);
            }
        }

        $separator = self::variantSeparator();

        return $segments ? $separator . implode($separator, $segments) : '';
    }

    public function setup($url = '')
    {
      if (empty($url)) {
          // Use the pre-captured URL if available (prevents translation plugins
          // that rewrite $_SERVER['REQUEST_URI'] from corrupting the cache key).
          $url = self::requestUrl();
      }

	    $original_url = $url;

        $this->urlKey = $this->keyFor($url);

	    // Store the mapping
	    if ($this->urlKey !== '') {
	        self::$url_mappings[$this->urlKey] = $original_url;
	    }

        return $this->urlKey;
    }

    /**
     * The key a URL would get from setup(), without recording it: no mapping is stored and
     * $this->urlKey is left as it is. A caller that asks what key another URL of the same request
     * has (the same path without its query string) reads it here.
     */
    public function keyFor($url)
    {
        $url = str_replace(['https://', 'http://'], '', (string) $url);
        $queryAt = strpos($url, '?');
        $rawQuery = $queryAt === false ? '' : (string) substr($url, $queryAt + 1);
        $fragmentAt = strpos($rawQuery, '#');
        if ($fragmentAt !== false) {
            $rawQuery = substr($rawQuery, 0, $fragmentAt);
        }
        if ($rawQuery !== '' && !self::queryIsPlain($rawQuery)) {
            return '';
        }
        $url = rtrim($url, '?');
        $url = rtrim($url, '/');
        $url = str_replace('wpc_visitor_mode=true', '', $url);
        $url = str_replace('?remote_generate_critical=true', '', $url);
        $url = str_replace('dbgCache=true', '', $url);
        $url = str_replace('forceCritical=true', '', $url);
        $url = str_replace('removeCritical=true', '', $url);
        $url = preg_replace('/&?forceRecombine=true.*/', '', $url);

        $url = $this->removeTrackingParams($url);

        $url = rtrim($url, '?');
        $url = rtrim($url, '/');

        $url = str_replace(['?'], '', $url);
        $url = str_replace(['='], '-', $url);
        $url = str_replace(['&'], '_', $url);

        return $this->createUrlKey($url);
    }

    /**
     * v7.10.598 — params this class strips from the cache key. ONE list, exposed statically so
     * the cache gates can agree with it instead of restating it. Lives here because the
     * advanced-cache drop-in already include_once's this file (templates/samples/
     * advancedCacheSample.php:49), so the read gate and the write gate see the same array and
     * update together — there is no baked copy to drift.
     */
    // v7.22.12 — ARTIFACT IDENTITY IS PAGE IDENTITY. A query string outside the strip
    // list minted a NEW key, and a new key has none of the page's measured artifacts —
    // so ?x=1 (or a first uncached hit) ran the unmeasured delay lane while / ran the
    // measured one (italianliquors: jQuery delayed, theme menu stuck open). The clean
    // key for the current request = the same setup() over the path-only URL.
    public static function cleanRequestKey()
    {
        try {
            $url = self::$captured_request_url;
            if ($url === null) {
                $url = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
            }
            $url = (string) $url;
            if ($url === '' || strpos($url, '?') === false) {
                return '';
            }
            return (string) (new self())->setup(strtok($url, '?'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function trackingParams()
    {
        return ['disable_cache', 'wpcnc', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_expid', 'utm_term', 'utm_content', 'mtm_source', 'mtm_medium', 'mtm_campaign', 'mtm_keyword', 'mtm_cid', 'mtm_content', 'pk_source', 'pk_medium', 'pk_campaign', 'pk_keyword', 'pk_cid', 'pk_content', 'fb_action_ids', 'fb_action_types', 'fb_source', 'fbclid', 'campaignid', 'adgroupid', 'adid', 'gclid', 'age-verified', 'ao_noptimize', 'usqp', 'cn-reloaded', '_ga', 'sscid', 'gclsrc', '_gl', 'mc_cid', 'mc_eid', '_bta_tid', '_bta_c', 'trk_contact', 'trk_msg', 'trk_module', 'trk_sid', 'gdfms', 'gdftrk', 'gdffi', '_ke', 'redirect_log_mongo_id', 'redirect_mongo_id', 'sb_referer_host', 'mkwid', 'pcrid', 'ef_id', 's_kwcid', 'msclkid', 'dm_i', 'epik', 'pp', 'gbraid', 'wbraid', 'utm_id', 'wpc_key',
            // The render debug flags read the page they debug: with their own key, a ?wpc_stages=1
            // trace read an empty crit folder and showed a crit-less page (rig, 2026-09-27).
            'wpc_stages', 'stop_before', 'stop_after', 'no_rewriter'];
    }

    /**
     * v7.10.598 — STRIPPED FROM THE KEY IS NOT THE SAME AS SAFE TO CACHE.
     *
     * Two different reasons a param gets stripped above, and only one of them implies the page is
     * cacheable:
     *   TRACKING  — the param does not change the response, so the clean page's bytes are correct
     *               for it. Sharing the key AND caching is right.
     *   CONTROL   — the param exists to CHANGE behaviour (bypass, debug, force). It is stripped so
     *               it cannot fragment the key, but serving a cached page for it defeats its whole
     *               purpose. `disable_cache` and `ao_noptimize` sit in the list above and are
     *               exactly this.
     * Also held back: `age-verified` and `cn-reloaded` plausibly gate CONTENT, and `usqp` is a
     * Google AMP viewer flag — none are pure click IDs, so they stay out of the cacheable set even
     * though the key ignores them. Widening deliberately and conservatively beats widening whole.
     */
    public static function controlParams()
    {
        return ['disable_cache', 'wpcnc', 'ao_noptimize', 'age-verified', 'cn-reloaded', 'usqp',
            'disablewpc', 'wpc_visitor_mode', 'remote_generate_critical', 'dbgcache',
            'forcecritical', 'removecritical', 'forcerecombine', 'crit', 'cdn', 'nocache',
            'wpc_no_buffer', 'wpc_smoke', 'wpcdoc', 'wpc_perf_debug', 'wpc_img_debug',
            'wpc_tier', 'wpc_key', 'wpc_stages', 'stop_before', 'stop_after', 'no_rewriter', 'apikey', 'wpc_sig', 'wpc_exp'];
    }

    public static function tierNames()
    {
        return ['control', 'free', 'local', 'edge'];
    }

    /**
     * Cookies that make a response belong to one visitor. A request carrying one is never
     * served a shared copy and its render is never stored as one; this list is the only place
     * that says which cookies those are, and personalCookieMatch() is the only matcher. Read
     * by both page cache serve paths (the advanced-cache drop-in and the in-WordPress reader),
     * the store verdict, the send_headers no-store guard and the crit corpus stash.
     *
     * A name ending in "_" is a prefix, any other name is exact; both case-insensitive.
     *
     * WooCommerce: woocommerce_cart_hash and woocommerce_items_in_cart exist exactly while the
     * cart holds something (WooCommerce sets both on the first add and deletes both when the
     * cart empties). Shoppers with items in the cart were served the cached empty-cart page,
     * and a shopper whose render was the first after a purge had their cart stored for every
     * visitor (rosella.com.co, anacletababy.com, babypinkworld.com). wp_woocommerce_session_ is
     * not listed: it outlives the cart by two days, so it would send every visitor who once
     * added and removed something past the cache, while their page (an empty cart) is the
     * shared one.
     *
     * The drop-in runs before any plugin has added a filter, so setAdvancedCache() bakes the
     * filtered list into WPC_PERSONAL_COOKIES and the drop-in reads that; WordPress, once the
     * plugins are loaded, reads the filter live. A drop-in baked before this constant existed
     * reads the default list.
     */
    public static function personalCookieNames()
    {
        $defaults = ['simplefavorites', 'wp-postpass_', 'comment_author_',
            'woocommerce_cart_hash', 'woocommerce_items_in_cart'];
        $pluginsLoaded = function_exists('did_action') && did_action('plugins_loaded');
        if (!$pluginsLoaded && defined('WPC_PERSONAL_COOKIES') && is_array(WPC_PERSONAL_COOKIES)) {
            return WPC_PERSONAL_COOKIES;
        }
        $names = function_exists('apply_filters') ? apply_filters('wpc_personal_cookies', $defaults) : $defaults;
        return is_array($names) ? array_values($names) : [];
    }

    /**
     * The first request cookie on personalCookieNames(), or '' when the request has none.
     *
     * The request is the one the visitor sent: the Cookie header as it arrived, and $_COOKIE.
     * Rule: whether a render is personal is decided once per request, so the send_headers guard,
     * the store verdict and the crit page stash, which ask at different moments of the render,
     * give the same answer. $_COOKIE alone is not that request: WooCommerce unsets both cart
     * cookies from it at `wp` (priority 99) when it finds no cart behind them
     * (WC_Cart_Session::set_cart_cookies(false)). Observed failure (ticket 12239 rosella.com.co,
     * 2026-10-19, and the rig 2026-10-28 on 7.26.29 and our cb68f294): a request carrying
     * woocommerce_items_in_cart answered `no-store` from the guard (X-WPC-CC:
     * unguarded-personal-cookie-64) while the store verdict, asked after `wp`, no longer saw the
     * cookie and wrote the render as the local-only copy, which the next cookie-less visitor was
     * served as a hit.
     */
    public static function personalCookieMatch()
    {
        $requestCookieNames = self::requestCookieNames();
        if (!$requestCookieNames) {
            return '';
        }
        $personalNames = self::personalCookieNames();
        foreach ($requestCookieNames as $cookieName) {
            $cookieName = (string) $cookieName;
            foreach ($personalNames as $personalName) {
                $personalName = (string) $personalName;
                if ($personalName === '') {
                    continue;
                }
                if (substr($personalName, -1) === '_') {
                    if (stripos($cookieName, $personalName) === 0) {
                        return $cookieName;
                    }
                } elseif (strcasecmp($cookieName, $personalName) === 0) {
                    return $cookieName;
                }
            }
        }
        return '';
    }

    /**
     * The names of the cookies this request carries: those in the Cookie header as the visitor
     * sent it, then any in $_COOKIE the header does not name (PHP turns a "." or " " in a name
     * into "_", and a plugin may add one), each once. A plugin can unset a $_COOKIE entry while
     * the page renders; it cannot change the header the request arrived with.
     */
    private static function requestCookieNames()
    {
        $names = [];
        $cookieHeader = isset($_SERVER['HTTP_COOKIE']) ? (string) $_SERVER['HTTP_COOKIE'] : '';
        if ($cookieHeader !== '') {
            foreach (explode(';', $cookieHeader) as $pair) {
                $equalsAt = strpos($pair, '=');
                $name = trim($equalsAt === false ? $pair : substr($pair, 0, $equalsAt));
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        if (!empty($_COOKIE) && is_array($_COOKIE)) {
            foreach (array_keys($_COOKIE) as $name) {
                $names[(string) $name] = true;
            }
        }
        return array_map('strval', array_keys($names));
    }

    /**
     * The site's editable query-parameter lists, in the same shape the cookie lists use
     * (option `wps_ic_cache_query_params`, written by the "Cache by Query Parameter" row):
     *
     *   'params'         — parameters whose VALUE varies the response. They stay in the url key
     *                      and the gates admit them, so ?currency=usd and ?currency=eur each get
     *                      their own cache entry instead of both bypassing the cache.
     *   'exclude_params' — parameters that never change the response. Stripped from the key and
     *                      admitted, exactly like the built-in tracking list, so every value
     *                      collapses onto the clean page's single entry.
     *
     * Both lists are empty by default: an unlisted parameter still mints its own key and is still
     * refused by the gates, which is the behaviour every install has today.
     *
     * The baked constants win over the option because the advanced-cache drop-in runs before
     * WordPress and cannot read an option — same contract as WPC_URL_EXCLUDES / WPC_CACHE_EXCLUDES.
     * A constant that is defined means setAdvancedCache() has spoken, `false` included ("the row is
     * off"); only an unbaked drop-in falls through to the option, so the read gate, the write gate
     * and the admin can never disagree about which parameters vary the cache.
     */
    public static function cacheQueryParams()
    {
        $varying  = defined('WPC_CACHE_QUERY_PARAMS') ? ((WPC_CACHE_QUERY_PARAMS === false) ? [] : (array) WPC_CACHE_QUERY_PARAMS) : null;
        $ignored  = defined('WPC_IGNORE_QUERY_PARAMS') ? ((WPC_IGNORE_QUERY_PARAMS === false) ? [] : (array) WPC_IGNORE_QUERY_PARAMS) : null;

        if (($varying === null || $ignored === null) && function_exists('get_option')) {
            $stored = get_option('wps_ic_cache_query_params', []);
            $stored = is_array($stored) ? $stored : [];
            if ($varying === null) {
                $varying = (!empty($stored['params']) && is_array($stored['params'])) ? $stored['params'] : [];
            }
            if ($ignored === null) {
                $ignored = (!empty($stored['exclude_params']) && is_array($stored['exclude_params'])) ? $stored['exclude_params'] : [];
            }
        }

        // One textarea line becomes one parameter name: trimmed, lower-cased and de-duplicated,
        // with blanks and comment lines dropped. Lower-cased because both consumers compare
        // lower-cased keys, so ?Currency= and ?currency= cannot land on different sides of a rule.
        $normalise = function ($list) {
            $clean = [];
            foreach ((array) $list as $name) {
                if (!is_string($name) && !is_numeric($name)) {
                    continue;
                }
                $name = strtolower(trim((string) $name));
                if ($name === '' || $name[0] === '#') {
                    continue;
                }
                $clean[$name] = true;
            }

            return array_keys($clean);
        };

        return ['params' => $normalise($varying), 'exclude_params' => $normalise($ignored)];
    }

    /**
     * v7.10.743 — the benchmark key, minted per site so an install needs no wp-config edit.
     *
     * Lives HERE and not in defines.php because the door runs at wp-compress.php file scope,
     * before defines.php is included — the drop-in has already loaded this file by then.
     * Never a shipped literal: a key baked into distributed source would be the same secret on
     * every install. Never written to a file either — it stays an option, read at door time with
     * WordPress loaded, so only the non-secret cache flag needs to reach the pre-WP drop-in.
     */
    public static function tierKey($mint = true)
    {
        if (defined('WPC_TIER_KEY') && (string) WPC_TIER_KEY !== '') {
            return (string) WPC_TIER_KEY;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $tierKey = (string) get_option('wpc_tier_key', '');
        if ($tierKey !== '' || !$mint) {
            return $tierKey;
        }
        if (function_exists('random_bytes')) {
            try { $tierKey = bin2hex(random_bytes(16)); } catch (\Throwable $e) { $tierKey = ''; }
        }
        if ($tierKey === '' && function_exists('wp_generate_password')) {
            $tierKey = (string) wp_generate_password(32, false, false);
        }
        if ($tierKey !== '' && function_exists('update_option')) {
            update_option('wpc_tier_key', $tierKey, false);
        }
        return $tierKey;
    }

    /**
     * v7.10.739 — the benchmark arms measure a COLD render otherwise. ?wpc_tier= sets
     * DONOTCACHEPAGE, so every arm pays an origin render (~1.2s vs ~0.07s warm on staging) and the
     * comparison carries a TTFB penalty no production visitor ever sees. Opt-in per site via two
     * wp-config constants, so no production install changes behaviour by default.
     */
    public static function tierCacheOn()
    {
        // The drop-in cannot read an option, so the key cannot be verified here. The constant is
        // written by setAdvancedCache() ONLY when a key already exists, so it implies both halves.
        // A wp-config WPC_TIER_KEY still satisfies the same contract on sites that pin one.
        return defined('WPC_TIER_CACHE') && WPC_TIER_CACHE;
    }

    /**
     * v7.10.739 — READ may be optimistic (a miss costs nothing); WRITE may not. The drop-in read
     * gate runs before WordPress, so it cannot check a capability — which means an anonymous
     * ?wpc_tier=edge with no key would render UNTIERED and, with DONOTCACHEPAGE gone, write those
     * bytes to the edge arm's key. The door defines WPC_TIER_ACTIVE only after it authorises; every
     * cache writer consults this before storing a tier-keyed render.
     */
    public static function tierWriteBlocked()
    {
        $queryString = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($queryString === '' || stripos($queryString, 'wpc_tier') === false) {
            return false;
        }
        return !(defined('WPC_TIER_ACTIVE') && WPC_TIER_ACTIVE);
    }

    /**
     * v7.10.598 — may a response for this query string be written to, or served from, cache?
     *
     * THE INVARIANT: if the urlKey strips a param, the gates must allow it — otherwise we share
     * the clean page's artifacts while refusing to cache, which is the worst of both. Receipted on
     * production: `?gbraid=…` returned 200 with `plugin_cache=NONE` (a full origin render) while
     * `?utm_source=…` returned `Advanced Cache - gzip`, because the key strips 61 params and the
     * gates named only utm_* plus 11. At ~1.0s mean origin work per MISS against 0.10s on a HIT,
     * that gap is capacity, not milliseconds.
     *
     * Costs ZERO extra cache entries: these params are stripped from the key, so every variant
     * collapses onto the clean URL's existing entry. The "unbounded inodes" the gates were
     * written to prevent only applies to params the key does NOT strip — those still return false.
     */
    public static function queryIsCacheable($queryString, $context = 'read')
    {
        $queryString = (string) $queryString;
        if (trim($queryString) === '') {
            return true;
        }
        if (!self::queryIsPlain($queryString)) {
            return false;
        }
        $controlParams = array_flip(self::controlParams());
        $allowedParams = array_flip(array_map('strtolower', self::trackingParams()));
        // Legacy allowlist the gates already honoured; keep them even though some are not in the
        // strip list, because refusing them now would be a REGRESSION in cache coverage.
        foreach (['dclid', 'ref', 'igshid', 'ttclid'] as $legacyParam) {
            $allowedParams[$legacyParam] = 1;
        }
        // The site's own lists. Both sides are admitted, for the two different reasons the invariant
        // above names: an "ignore" param is stripped from the key (so it must be cacheable, or we
        // share the clean page's artifacts while refusing to store them), and a "vary" param keeps
        // its own key (so admitting it costs exactly one entry per value the visitor actually asks
        // for). Control params are tested first in the loop below and still win.
        $wpc_query_lists = self::cacheQueryParams();
        foreach (array_merge($wpc_query_lists['params'], $wpc_query_lists['exclude_params']) as $wpc_site_param) {
            $allowedParams[$wpc_site_param] = 1;
        }
        $queryArgs = [];
        parse_str($queryString, $queryArgs);
        // v7.10.739 — the tier arms are the one CONTROL family that must be cacheable, because the
        // whole point is measuring a warm render. Admitted only under the wp-config opt-in, only
        // for a known tier name, and — on the write side — only once the door has authorised.
        // wpc_tier stays IN the url key (each arm gets its own path, so no arm can ever be served
        // to a plain visitor); wpc_key is stripped by trackingParams so the secret never becomes
        // a directory name on disk.
        if (self::tierCacheOn()) {
            $tierArm = '';
            foreach ((array) $queryArgs as $argName => $argValue) {
                if (strtolower((string) $argName) === 'wpc_tier') { $tierArm = (string) $argValue; }
            }
            if ($tierArm !== '') {
                $tierName = strtolower(preg_replace('/[^A-Za-z]/', '', $tierArm));
                if (!in_array($tierName, self::tierNames(), true)) {
                    return false;
                }
                if ($context === 'write' && !(defined('WPC_TIER_ACTIVE') && WPC_TIER_ACTIVE)) {
                    return false;
                }
                foreach (array_keys((array) $queryArgs) as $argName) {
                    if (in_array(strtolower((string) $argName), ['wpc_tier', 'wpc_key'], true)) {
                        unset($queryArgs[$argName]);
                    }
                }
            }
        }
        foreach (array_keys((array) $queryArgs) as $paramName) {
            $paramName = strtolower((string) $paramName);
            if (isset($controlParams[$paramName]) || strpos($paramName, 'wpc_lever_') === 0) {
                return false;
            }
            // Prefix families: utm_ was already honoured; mtm_ (Matomo) and pk_ (Piwik) are the
            // same shape and are enumerated in the strip list anyway.
            if (strpos($paramName, 'utm_') === 0 || strpos($paramName, 'mtm_') === 0
                || strpos($paramName, 'pk_') === 0) {
                continue;
            }
            if (!isset($allowedParams[$paramName])) {
                return false;
            }
        }
        return true;
    }

    public function removeTrackingParams($url)
    {
        if (empty($url) || !$url) return;


        $trackingParams = self::trackingParams();

        $parts = parse_url($url);

        if (!isset($parts['query'])) {
            return $url; // No query, nothing to remove
        }

        parse_str($parts['query'], $query);

        foreach ($trackingParams as $param) {
            unset($query[$param]);
        }

        // The site's "ignore" list gets the same treatment as the built-in tracking list — out of
        // the key, so every value collapses onto the clean page's entry. Matched case-insensitively
        // because the list is stored lower-cased; the built-in loop above stays an exact match so
        // no shipped param changes behaviour.
        $ignoredParams = self::cacheQueryParams()['exclude_params'];
        if (!empty($ignoredParams)) {
            $ignoredParams = array_flip($ignoredParams);
            foreach (array_keys($query) as $queryParam) {
                if (isset($ignoredParams[strtolower((string) $queryParam)])) {
                    unset($query[$queryParam]);
                }
            }
        }

        $queryString = http_build_query($query);

        // Rebuild URL safely
        $cleanUrl = '';

        if (isset($parts['scheme'])) {
            $cleanUrl .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $cleanUrl .= $parts['user'];
            if (isset($parts['pass'])) {
                $cleanUrl .= ':' . $parts['pass'];
            }
            $cleanUrl .= '@';
        }

        if (isset($parts['host'])) {
            $cleanUrl .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $cleanUrl .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            $cleanUrl .= $parts['path'];
        }

        if ($queryString) {
            $cleanUrl .= '?' . $queryString;
        }

        if (isset($parts['fragment'])) {
            $cleanUrl .= '#' . $parts['fragment'];
        }

        return $cleanUrl;
    }

    public function createUrlKey($url)
    {
        $url = str_replace(['http://', 'https://'], '', $url);

        if (strpos($url, '?testCritical') !== false) {
            $url = explode('?', $url)[0];
        }

        if (strpos($url, '?dbgCache') !== false) {
            $url = explode('?', $url)[0];
        }

        if (strpos($url, '?dbg_') !== false) {
            $url = explode('?', $url)[0];
        }

        return $this->sanitize_title(urldecode(rtrim($url, '/')));
    }

    private function sanitize_title($title, $fallback_title = '', $context = 'save')
    {
        $raw_title = $title;

        if ('save' === $context) {
            $title = $this->remove_accents($title);
        }

        $title = $this->sanitize_title_with_dashes($title);

        if ('' === $title || false === $title) {
            $title = $fallback_title;
        }

        return $title;
    }

    private function remove_accents($string)
    {
        if (!preg_match('/[\x80-\xff]/', $string)) {
            return $string;
        }

        if ($this->seems_utf8($string)) {
            return strtr($string, $this->utf8_char_map());
        } else {
            return $string; // Basic fallback; your original map can be reused here if needed.
        }
    }

    private function seems_utf8($str)
    {
        $this->mbstring_binary_safe_encoding();
        $length = strlen($str);
        $this->reset_mbstring_encoding();
        for ($i = 0; $i < $length; $i++) {
            $c = ord($str[$i]);
            if ($c < 0x80) {
                $n = 0;
            } elseif (($c & 0xE0) == 0xC0) {
                $n = 1;
            } elseif (($c & 0xF0) == 0xE0) {
                $n = 2;
            } elseif (($c & 0xF8) == 0xF0) {
                $n = 3;
            } elseif (($c & 0xFC) == 0xF8) {
                $n = 4;
            } elseif (($c & 0xFE) == 0xFC) {
                $n = 5;
            } else {
                return false;
            }
            for ($j = 0; $j < $n; $j++) {
                if ((++$i == $length) || ((ord($str[$i]) & 0xC0) != 0x80)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function mbstring_binary_safe_encoding($reset = false)
    {
        static $encodings = [];
        static $overloaded = null;

        if (is_null($overloaded)) {
            $overloaded = function_exists('mb_internal_encoding');
        }

        if (false === $overloaded) {
            return;
        }

        if (!$reset) {
            $encoding = mb_internal_encoding();
            array_push($encodings, $encoding);
            mb_internal_encoding('ISO-8859-1');
        }

        if ($reset && $encodings) {
            $encoding = array_pop($encodings);
            mb_internal_encoding($encoding);
        }
    }

    private function reset_mbstring_encoding()
    {
        $this->mbstring_binary_safe_encoding(true);
    }

    private function utf8_char_map()
    {
        return ['À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'TH', 'ß' => 's', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y'// Extend with more mappings if needed
        ];
    }

    private function sanitize_title_with_dashes($title, $context = 'display')
    {
        $title = strip_tags($title);

        $title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title);
        $title = str_replace('%', '', $title);
        $title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title);

        if ($this->seems_utf8($title)) {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title, 'UTF-8');
            }
            $title = $this->utf8_uri_encode($title, 200);
        }

        $title = strtolower($title);

        if ('save' === $context) {
            $title = str_replace(['%c2%a0', '%e2%80%93', '%e2%80%94'], '-', $title);
            $title = str_replace(['&nbsp;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;'], '-', $title);
            $title = str_replace('/', '-', $title);

            $title = str_replace(['%c2%ad', '%c2%a1', '%c2%bf', '%c2%ab', '%c2%bb', '%e2%80%b9', '%e2%80%ba', '%e2%80%98', '%e2%80%99', '%e2%80%9c', '%e2%80%9d', '%e2%80%9a', '%e2%80%9b', '%e2%80%9e', '%e2%80%9f', '%e2%80%a2', '%c2%a9', '%c2%ae', '%c2%b0', '%e2%80%a6', '%e2%84%a2', '%c2%b4', '%cb%8a', '%cc%81', '%cd%81', '%cc%80', '%cc%84', '%cc%8c'], '', $title);

            $title = str_replace('%c3%97', 'x', $title);
        }

        $title = preg_replace('/&.+?;/', '', $title);
        $title = str_replace('.', '-', $title);

        $title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);
        $title = preg_replace('|-+|', '-', $title);
        $title = trim($title, '-');

        return $title;
    }

    private function utf8_uri_encode($utf8_string, $length = 0)
    {
        $unicode = '';
        $values = [];
        $num_octets = 1;
        $unicode_length = 0;

        $this->mbstring_binary_safe_encoding();
        $string_length = strlen($utf8_string);
        $this->reset_mbstring_encoding();

        for ($i = 0; $i < $string_length; $i++) {
            $value = ord($utf8_string[$i]);

            if ($value < 128) {
                if ($length && ($unicode_length >= $length)) {
                    break;
                }
                $unicode .= chr($value);
                $unicode_length++;
            } else {
                if (count($values) == 0) {
                    if ($value < 224) {
                        $num_octets = 2;
                    } elseif ($value < 240) {
                        $num_octets = 3;
                    } else {
                        $num_octets = 4;
                    }
                }

                $values[] = $value;

                if ($length && ($unicode_length + ($num_octets * 3)) > $length) {
                    break;
                }
                if (count($values) == $num_octets) {
                    foreach ($values as $val) {
                        $unicode .= '%' . dechex($val);
                    }
                    $unicode_length += $num_octets * 3;
                    $values = [];
                    $num_octets = 1;
                }
            }
        }

        return $unicode;
    }

    public function is_external($url)
    {
        if (empty($url)) {
            return false;
        }

        $site_url = home_url();
        $url = str_replace(['https://', 'http://'], '', $url);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($url, '/') === 0 && strpos($url, '//') === false) {
            // Absolute
            return false;
        } elseif (strpos($url, $site_url) === false || strpos($url, '//') === 0) {
            return true;
        } else {
            return false;
        }
    }

    public function removeUrl($url)
    {
        $siteUrl = home_url();
        $noUrl = str_replace($siteUrl, '', $url);

        $noUrl = str_replace('&remote_generate_critical=1', '', $noUrl);
        $noUrl = str_replace('&apikey=' . get_option(WPS_IC_OPTIONS)['api_key'], '', $noUrl);

        if ($this->trp_active) {
            global $TRP_LANGUAGE;

            if ($TRP_LANGUAGE == $this->trp_settings['default-language']) {
                if (isset($this->trp_settings['add-subdirectory-to-default-language']) && $this->trp_settings['add-subdirectory-to-default-language'] == 'yes') {
                    $pos = strpos($noUrl, $this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/');
                    if ($pos !== false) {
                        $noUrl = substr_replace($noUrl, '', $pos, strlen($this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/'));
                    }
                }
            } else {
                $pos = strpos($noUrl, $this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/');
                if ($pos !== false) {
                    $noUrl = substr_replace($noUrl, '', $pos, strlen($this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/'));
                }
            }
        }

        return $noUrl;
    }

    public function get_allowed_params()
    {
        return ['lang', 'wpc_visitor_mode'];
    }

    /**
     * The language codes this site serves, lower case with '-' for '_' (a locale also gives its
     * primary subtag): WPML's active languages, Polylang's slugs and locales, qTranslate's enabled
     * languages, the site's own locale and the language packs it has installed. The
     * wpc_served_languages filter adds a translation plugin this list does not know.
     */
    public static function servedLanguages()
    {
        $codes = [];
        $add = function ($code) use (&$codes) {
            $code = strtolower(str_replace('_', '-', trim((string) $code)));
            $primary = preg_split('/[-@]/', $code, 2)[0];
            if (preg_match('/^[a-z]{2,3}$/', $primary) !== 1) {
                return;
            }
            $codes[$primary] = true;
            if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code) === 1) {
                $codes[$code] = true;
            }
        };
        if (function_exists('apply_filters')) {
            $wpml = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            foreach (is_array($wpml) ? $wpml : [] as $code => $language) {
                $add($code);
                if (is_array($language) && isset($language['default_locale'])) { $add($language['default_locale']); }
            }
        }
        if (function_exists('pll_languages_list')) {
            foreach (['slug', 'locale'] as $field) {
                foreach ((array) pll_languages_list(['fields' => $field]) as $code) { $add($code); }
            }
        }
        if (!empty($GLOBALS['q_config']['enabled_languages']) && is_array($GLOBALS['q_config']['enabled_languages'])) {
            foreach ($GLOBALS['q_config']['enabled_languages'] as $code) { $add($code); }
        }
        if (function_exists('get_locale')) { $add(get_locale()); }
        if (function_exists('get_option')) { $add(get_option('WPLANG')); }
        if (function_exists('get_available_languages')) {
            foreach ((array) get_available_languages() as $code) { $add($code); }
        }
        if (function_exists('apply_filters')) {
            foreach ((array) apply_filters('wpc_served_languages', []) as $code) { $add($code); }
        }
        return $codes;
    }

    /**
     * Whether $value is a language this site serves. A visitor's request names it; only a site's own
     * language may take part in a cache key.
     */
    public static function languageServed($value)
    {
        if (!is_string($value)) {
            return false;
        }
        $code = strtolower(str_replace('_', '-', trim($value)));
        return $code !== '' && isset(self::servedLanguages()[$code]);
    }

    /**
     * Whether a raw query string is spelled the way PHP reads it: every parameter name is written as PHP names it (no percent-encoding,
     * `+`, `.`, leading space or array suffix to decode), no name is written twice (in any letter case), and wpc_visitor_mode and
     * remote_generate_critical carry the plain word `true`.
     */
    public static function queryIsPlain($rawQuery)
    {
        $seen = [];
        foreach (explode('&', (string) $rawQuery) as $segment) {
            $cut = strpos($segment, '=');
            $rawName = $cut === false ? $segment : substr($segment, 0, $cut);
            $name = urldecode($rawName);
            $open = strpos($name, '[');
            if ($open !== false) {
                $name = substr($name, 0, $open);
            }
            $name = strtr($name, [' ' => '_', '.' => '_']);
            if ($name === '') {
                continue;
            }
            if ($rawName !== $name) {
                return false;
            }
            $folded = strtolower($name);
            if (isset($seen[$folded])) {
                return false;
            }
            $seen[$folded] = true;
            if (($name === 'wpc_visitor_mode' || $name === 'remote_generate_critical') && ($cut === false ? '' : substr($segment, $cut + 1)) !== 'true') {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the values of the allowed parameters a request carries are ones the plugin itself sends:
     * wpc_visitor_mode is the word `true` (url_key strips only that from the key) and lang is a language
     * the site serves. A parameter the request does not carry is not judged.
     */
    public static function allowedParamValuesOk(array $query, $rawQuery)
    {
        if (!self::queryIsPlain($rawQuery)) {
            return false;
        }
        if (array_key_exists('wpc_visitor_mode', $query) && $query['wpc_visitor_mode'] !== 'true') {
            return false;
        }
        if (array_key_exists('lang', $query) && !self::languageServed($query['lang'])) {
            return false;
        }
        return true;
    }

	public static function getUrlFromKey($url_key)
	{
		if (isset(self::$url_mappings[$url_key])) {
			return self::$url_mappings[$url_key];
		}


		if (!empty($url_key) && is_string($url_key) && strpos($url_key, '/') === false && strpos($url_key, '..') === false) {
			$candidates = [];
			if (defined('WPS_IC_CRITICAL')) {
				$candidates[] = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/url.txt';
			}
			if (defined('WPS_IC_CACHE')) {
				$candidates[] = rtrim(WPS_IC_CACHE, '/') . '/' . $url_key . '/url.txt';
			}
			foreach ($candidates as $file) {
				if (@is_readable($file)) {
					$clean = self::sanitizeSameHostUrl((string) @file_get_contents($file));
					if ($clean !== '') {
						self::$url_mappings[$url_key] = $clean;
						return $clean;
					}
				}
			}
		}
		return '';
	}


	/**
	 * Is this URL a front-end page, i.e. something a critical-CSS key may exist for?
	 * setup() falls back to HTTP_HOST.REQUEST_URI when given no argument, so a key minted
	 * during an admin-ajax/cron/REST request describes an endpoint, not a page — and
	 * persistKeyUrl() would mkdir a folder for it (…/critical/host-wp-adminadmin-ajax-php/url.txt).
	 * Tested on the URL, never on the request context: the crit AJAX legitimately persists a
	 * front-end URL while itself running under DOING_AJAX.
	 */
	public static function isPageUrl($url)
	{
		$path = (string) parse_url((string) $url, PHP_URL_PATH);
		if ($path === '') {
			return true;
		}
		return !preg_match(
			'#(?:^|/)(?:wp-admin(?:/|$)|wp-json(?:/|$)|wp-login\.php|wp-cron\.php|xmlrpc\.php'
			. '|admin-ajax\.php|wp-comments-post\.php|wp-signup\.php|wp-activate\.php'
			. '|wp-trackback\.php|wp-mail\.php|wp-links-opml\.php)#i',
			$path
		);
	}

	public static function persistKeyUrl($url_key, $url)
	{
		if (empty($url_key) || !is_string($url_key) || empty($url) || !defined('WPS_IC_CRITICAL')
			|| strpos($url_key, '/') !== false || strpos($url_key, '..') !== false) {
			return false;
		}
		$clean = self::sanitizeSameHostUrl($url);
		if ($clean === '' || !self::isPageUrl($clean)) {
			return false;
		}
		$dir  = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/';
		$file = $dir . 'url.txt';
		if (@is_readable($file) && trim((string) @file_get_contents($file)) === $clean) {
			return true;
		}
		if (!is_dir($dir)) {
			if (!function_exists('wp_mkdir_p') || !wp_mkdir_p($dir)) {
				return false;
			}
		}
		$tmp = $file . '.' . uniqid('tmp', true);
		if (wpc_fs_put($tmp, $clean) === false) {
			return false;
		}
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	 * Whether $url names a page this site serves, at the address the site itself gives it: the home page, the posts page, a
	 * published post, page or product at its permalink (and, for a post split with <!--nextpage-->, its pages 2 to the last), a term of
	 * a public taxonomy, the archive of a public post type, an author, or a year, month or day that has posts. A listing is also a page
	 * at /page/N/ up to the pages its posts fill. On a site whose multilingual plugin puts the language in a directory (WPML with directories,
	 * Polylang with the language in the path, TranslatePress) an address under that directory is judged as written and without it. A search,
	 * a comment page, a page number past the last and any other address is not; the wpc_url_is_site_page filter adds a page a plugin serves
	 * from its own routes.
	 */
	public static function isSitePage($url)
	{
		$clean = self::sanitizeSameHostUrl((string) $url);
		self::$refusal = 'endpoint';
		if ($clean === '' || !self::isPageUrl($clean) || !function_exists('wpc_address_identity') || !function_exists('home_url')) {
			return false;
		}
		self::$refusal = 'not-a-page';
		self::$languageDirectories = self::languageDirectories();
		$paged = 0;
		$base = $clean;
		if (preg_match('#^(.*/)page/([1-9][0-9]{0,5})/?$#', $clean, $pagedParts) === 1) {
			$base = $pagedParts[1];
			$paged = (int) $pagedParts[2];
		}
		$forms = self::addressForms($base);
		$listing = function (array $postTypes, $count = null) use ($paged, $clean) {
			if ($paged === 0 || ($paged >= 2 && $paged <= self::listingMaxPages($postTypes, $count, $clean))) {
				return true;
			}
			self::$refusal = 'page-number';
			return false;
		};
		$staticFront = function_exists('get_option') && get_option('show_on_front') === 'page';
		if (self::anyAddress($forms, home_url('/'))) {
			if ($paged > 0 && $staticFront) {
				self::$refusal = 'page-number';
				return false;
			}
			return $listing(['post']);
		}
		$postsPage = $staticFront ? (int) get_option('page_for_posts') : 0;
		if ($postsPage > 0 && function_exists('get_permalink') && function_exists('get_post_status') && get_post_status($postsPage) === 'publish'
			&& self::anyAddress($forms, get_permalink($postsPage))) {
			return $listing(['post']);
		}
		$claim = self::dateShape($forms);
		if ($claim !== null) {
			self::$refusal = 'date-shape';
			return self::datePeriodValid($claim) && self::anyAddress($forms, self::datePeriodLink($claim)) && self::datePeriodPosts($claim, false) > 0
				&& $listing(['post'], function () use ($claim) { return self::datePeriodPosts($claim, true); });
		}
		if ($paged === 0 && self::isSingularAddress($clean, $forms)) {
			return true;
		}
		$slug = rawurldecode((string) basename(trim((string) parse_url($base, PHP_URL_PATH), '/')));
		if ($slug !== '' && function_exists('get_taxonomies') && function_exists('get_term_by') && function_exists('get_term_link')) {
			foreach ((array) get_taxonomies(['public' => true], 'names') as $taxonomy) {
				$term = get_term_by('slug', $slug, $taxonomy);
				if (!is_object($term) || !self::anyAddress($forms, get_term_link($term))) {
					continue;
				}
				$taxonomyObject = function_exists('get_taxonomy') ? get_taxonomy($taxonomy) : null;
				$types = is_object($taxonomyObject) && !empty($taxonomyObject->object_type) ? (array) $taxonomyObject->object_type : ['post'];
				$flat = function_exists('is_taxonomy_hierarchical') && !is_taxonomy_hierarchical($taxonomy) && isset($term->count);
				return $listing($types, $flat ? (int) $term->count : null);
			}
		}
		if (function_exists('get_post_types') && function_exists('get_post_type_archive_link')) {
			foreach ((array) get_post_types(['public' => true, 'has_archive' => true], 'names') as $postType) {
				if (self::anyAddress($forms, get_post_type_archive_link($postType))) {
					return $listing([$postType]);
				}
			}
		}
		if ($slug !== '' && function_exists('get_user_by') && function_exists('get_author_posts_url')) {
			$author = get_user_by('slug', $slug);
			if (is_object($author) && !empty($author->ID) && self::anyAddress($forms, get_author_posts_url($author->ID))) {
				return $listing(['post'], function_exists('count_user_posts') ? function () use ($author) { return (int) count_user_posts($author->ID, 'post', true); } : null);
			}
		}
		return function_exists('apply_filters') && apply_filters('wpc_url_is_site_page', false, $clean) === true;
	}

	/** Why the last isSitePage() said no: 'endpoint', 'not-a-page', 'page-number' or 'date-shape'. */
	private static $refusal = '';

	public static function lastRefusal()
	{
		return self::$refusal;
	}

	/** The language directories of the current proof (languageDirectories()). */
	private static $languageDirectories = [];

	/**
	 * The names a multilingual plugin gives the directory of a language, lower case; none on a site without such a plugin in directory mode
	 * (WPML with language negotiation type 1, Polylang with force_lang 1, TranslatePress's url slugs, and what the wpc_language_directories
	 * filter adds), so a stock site's locale is never read as a directory.
	 */
	private static function languageDirectories()
	{
		$directories = [];
		if (function_exists('apply_filters') && (int) apply_filters('wpml_setting', 0, 'language_negotiation_type') === 1) {
			$active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
			foreach (is_array($active) ? array_keys($active) : [] as $code) {
				$directories[] = (string) $code;
			}
		}
		if (function_exists('pll_languages_list') && function_exists('get_option')) {
			$polylang = get_option('polylang');
			if (is_array($polylang) && isset($polylang['force_lang']) && (int) $polylang['force_lang'] === 1) {
				foreach ((array) pll_languages_list(['fields' => 'slug']) as $slug) {
					$directories[] = (string) $slug;
				}
			}
		}
		if (class_exists('TRP_Translate_Press') && function_exists('get_option')) {
			$translatePress = get_option('trp_settings');
			foreach (is_array($translatePress) && !empty($translatePress['url-slugs']) ? (array) $translatePress['url-slugs'] : [] as $slug) {
				$directories[] = (string) $slug;
			}
		}
		if (function_exists('apply_filters')) {
			$directories = (array) apply_filters('wpc_language_directories', $directories);
		}
		return array_values(array_unique(array_filter(array_map(function ($name) { return strtolower(trim((string) $name, '/')); }, $directories), function ($name) { return $name !== ''; })));
	}

	/** The address as written and, when its first directory under the home is a language directory (languageDirectories()), without that directory. */
	private static function addressForms($address)
	{
		$forms = [(string) $address];
		$parts = parse_url((string) $address);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return $forms;
		}
		$path = isset($parts['path']) ? (string) $parts['path'] : '/';
		$home = rtrim(self::homePath(), '/');
		if ($home !== '' && strpos($path, $home . '/') !== 0) {
			return $forms;
		}
		$relative = ltrim((string) substr($path, strlen($home)), '/');
		$cut = strpos($relative, '/');
		$first = $cut === false ? $relative : substr($relative, 0, $cut);
		if ($first !== '' && in_array(strtolower($first), self::$languageDirectories, true)) {
			$forms[] = $parts['scheme'] . '://' . $parts['host'] . $home . '/' . ($cut === false ? '' : substr($relative, $cut + 1));
		}
		return $forms;
	}

	/** Whether $link is any of the addresses in $forms. */
	private static function anyAddress(array $forms, $link)
	{
		foreach ($forms as $form) {
			if (self::sameAddress($form, $link)) {
				return true;
			}
		}
		return false;
	}

	/** Whether two addresses are one page address: host without www, path without its outer slashes, in any letter case. */
	private static function sameAddress($a, $b)
	{
		if (!is_string($a) || !is_string($b) || $a === '' || $b === '') {
			return false;
		}
		return strtolower(wpc_address_identity($a)) === strtolower(wpc_address_identity($b));
	}

	/** Whether a published post, page or product answers for $clean at its own permalink, or, split with <!--nextpage-->, at /N/ for N up to its pages. */
	private static function isSingularAddress($clean, array $forms)
	{
		if (!function_exists('url_to_postid') || !function_exists('get_permalink') || !function_exists('get_post_status') || !function_exists('get_post_type')) {
			return false;
		}
		$numbered = preg_match('#^(.*/)([2-9]|[1-9][0-9]{1,5})/?$#', $clean, $numberParts) === 1;
		$ids = [];
		foreach ($forms as $candidate) {
			$id = (int) url_to_postid($candidate);
			if ($id > 0) {
				$ids[$id] = true;
			}
		}
		foreach (array_keys($ids) as $id) {
			if (get_post_status($id) !== 'publish' || get_post_type($id) === 'attachment') {
				continue;
			}
			$link = get_permalink($id);
			if (self::anyAddress($forms, $link)) {
				return true;
			}
			if ($numbered && self::anyAddress(self::addressForms($numberParts[1]), $link) && (int) $numberParts[2] <= self::postPages($id)) {
				return true;
			}
		}
		return false;
	}

	/** The pages of a post: one more than its <!--nextpage--> breaks. */
	private static function postPages($postId)
	{
		return function_exists('get_post_field') ? substr_count((string) get_post_field('post_content', $postId), '<!--nextpage-->') + 1 : 1;
	}

	/** The path in front of the year in the site's date archive links (get_year_link), '' for none, null when the links are not paths. */
	private static function dateFront()
	{
		if (!function_exists('get_year_link')) {
			return null;
		}
		$path = self::pathUnderHome((string) get_year_link(1999));
		return $path !== null && preg_match('#^(.*?)1999$#', $path, $parts) === 1 ? $parts[1] : null;
	}

	/** The path of $address below the site's home, without outer slashes; null when the address is not below it. */
	private static function pathUnderHome($address)
	{
		$path = trim((string) parse_url((string) $address, PHP_URL_PATH), '/');
		$home = trim(rtrim(self::homePath(), '/'), '/');
		if ($home === '') {
			return $path;
		}
		if ($path === $home) {
			return '';
		}
		return strpos($path, $home . '/') === 0 ? substr($path, strlen($home) + 1) : null;
	}

	/**
	 * [year, month, day] (0 for a part the address does not have) of an address that is in the shape of one of the site's date archives:
	 * the date front, four digits, and up to two numbers of one or two digits, whatever they hold (/2026/00/, /2026/5/, /2026/05/00/
	 * included, so none reaches WordPress's own date query); null for any other address.
	 */
	private static function dateShape(array $forms)
	{
		$front = self::dateFront();
		if ($front === null) {
			return null;
		}
		foreach ($forms as $form) {
			$path = self::pathUnderHome($form);
			if ($path !== null && preg_match('#^' . preg_quote($front, '#') . '([0-9]{4})(?:/([0-9]{1,2}))?(?:/([0-9]{1,2}))?$#', $path, $parts) === 1) {
				return [(int) $parts[1], isset($parts[2]) ? (int) $parts[2] : 0, isset($parts[3]) ? (int) $parts[3] : 0];
			}
		}
		return null;
	}

	/** Whether the period is a real date, from 1970 and no later than tomorrow. */
	private static function datePeriodValid(array $period)
	{
		$month = $period[1] > 0 ? $period[1] : 1;
		$day = $period[2] > 0 ? $period[2] : 1;
		return $period[0] >= 1970 && ($period[2] === 0 || $period[1] > 0) && checkdate($month, $day, $period[0]) && gmmktime(0, 0, 0, $month, $day, $period[0]) <= time() + 86400;
	}

	/** The archive link WordPress builds for the period, from the site's own date structure. */
	private static function datePeriodLink(array $period)
	{
		if ($period[2] > 0) {
			return function_exists('get_day_link') ? get_day_link($period[0], $period[1], $period[2]) : '';
		}
		if ($period[1] > 0) {
			return function_exists('get_month_link') ? get_month_link($period[0], $period[1]) : '';
		}
		return function_exists('get_year_link') ? get_year_link($period[0]) : '';
	}

	/**
	 * Whether the period holds a published post (an archive with none is a 404), or, with $count, how many: one post by id over a range of
	 * post_date, nothing else read or counted unless the count is asked for (a page number past the first).
	 */
	private static function datePeriodPosts(array $period, $count)
	{
		if (!class_exists('WP_Query')) {
			return 0;
		}
		$month = $period[1] > 0 ? $period[1] : 1;
		$first = sprintf('%04d-%02d-%02d', $period[0], $period[1] > 0 ? $period[1] : 1, $period[2] > 0 ? $period[2] : 1);
		$lastDay = $period[2] > 0 ? $period[2] : ($period[1] > 0 ? (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $period[0])) : 31);
		$last = sprintf('%04d-%02d-%02d', $period[0], $period[1] > 0 ? $period[1] : 12, $lastDay);
		$query = new WP_Query([
			'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => !$count, 'orderby' => 'none',
			'ignore_sticky_posts' => true, 'suppress_filters' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
			'date_query' => [['after' => $first . ' 00:00:00', 'before' => $last . ' 23:59:59', 'inclusive' => true]],
		]);
		return $count ? (int) $query->found_posts : count((array) $query->posts);
	}

	/**
	 * The last page of a listing of $postTypes: the posts it can hold ($count when the caller knows it, a closure that counts them, else every
	 * published post of the types), asked only for a page number past the first, over the posts it shows a page (the products per page of the shop for a listing of products, else the site's posts per
	 * page); 50 when the counts cannot be read. The wpc_site_page_max_pages filter answers for a listing that shows another number.
	 */
	private static function listingMaxPages(array $postTypes, $count, $url)
	{
		$max = 50;
		if ($count instanceof Closure) {
			$count = $count();
		}
		if ($count !== null || function_exists('wp_count_posts')) {
			$total = $count !== null ? (int) $count : 0;
			if ($count === null) {
				foreach ($postTypes as $postType) {
					$counts = wp_count_posts((string) $postType);
					$total += is_object($counts) && isset($counts->publish) ? (int) $counts->publish : 0;
				}
			}
			$perPage = max(1, (int) (function_exists('get_option') ? get_option('posts_per_page', 10) : 10));
			if (in_array('product', $postTypes, true) && function_exists('get_option') && function_exists('apply_filters')) {
				$perPage = max(1, (int) apply_filters('loop_shop_per_page', max(1, (int) get_option('woocommerce_catalog_columns', 4)) * max(1, (int) get_option('woocommerce_catalog_rows', 4))));
			}
			$max = max(1, (int) ceil($total / $perPage));
		}
		return function_exists('apply_filters') ? (int) apply_filters('wpc_site_page_max_pages', $max, (string) $url) : $max;
	}

	/**
	 * Validate + normalize a URL for purge use: must parse, must be same-host as home_url
	 * (www/non-www alias tolerated), reduced to scheme://host/path (query/fragment dropped —
	 * url_keys strip params anyway; scheme pinned to the site's canonical scheme). Returns ''
	 * when the URL isn't safe to purge against.
	 */
	public static function sanitizeSameHostUrl($url)
	{
		$url = trim((string) $url);
		if ($url === '' || !function_exists('home_url')) {
			return '';
		}
		if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
			$url = 'https://' . ltrim($url, '/');
		}
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return '';
		}
		$parts = parse_url($url);
		if (empty($parts['host'])) {
			return '';
		}
		$homeHost = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
		$urlHost  = strtolower($parts['host']);
		$stripWww = function ($h) {
			return (strpos($h, 'www.') === 0) ? substr($h, 4) : $h;
		};
		if ($homeHost === '' || $stripWww($homeHost) !== $stripWww($urlHost)) {
			return '';
		}
		$homeScheme = (string) parse_url(home_url(), PHP_URL_SCHEME);
		$scheme     = ($homeScheme === 'http') ? 'http' : 'https';
		$path       = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
		return $scheme . '://' . $urlHost . $path;
	}

	public static function requestHostPath($url = null)
	{
		$url = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', (string) ($url === null ? self::requestUrl() : $url));
		return explode('#', explode('?', (string) $url, 2)[0], 2)[0];
	}

	public static function requestQuery($url = null)
	{
		$url = (string) ($url === null ? self::requestUrl() : $url);
		$cut = strpos($url, '?');
		return $cut === false ? '' : explode('#', (string) substr($url, $cut + 1), 2)[0];
	}

	public static function homePath()
	{
		if (!function_exists('home_url')) {
			return '/';
		}
		$path = (string) parse_url((string) home_url('/'), PHP_URL_PATH);
		return '/' . ltrim($path === '' ? '/' : $path, '/');
	}

	public static function excludeHost($host)
	{
		$host = strtolower(trim((string) $host));
		$host = preg_replace('/:\d+$/', '', $host);
		$host = rtrim((string) $host, '.');
		return (strpos($host, 'www.') === 0) ? (string) substr($host, 4) : (string) $host;
	}

	public static function excludeMatch($pattern, $hostPath, $homePath = '/')
	{
		$pattern = trim((string) $pattern);
		if ($pattern === '' || $pattern[0] === '#') {
			return false;
		}

		$hostPath = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', (string) $hostPath);
		$hostPath = explode('?', (string) $hostPath, 2)[0];
		$cut  = strcspn($hostPath, '/');
		$host = self::excludeHost(substr($hostPath, 0, $cut));
		$path = rawurldecode((string) substr($hostPath, $cut));
		if ($path === '') {
			$path = '/';
		}

		$sited = false;
		if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//([^/?\#]*)(.*)$#is', $pattern, $m)) {
			if ($host === '' || self::excludeHost($m[1]) !== $host) {
				return false;
			}
			$pattern = $m[2];
			$sited = true;
		} else {
			$first = strcspn($pattern, '/');
			$lead  = (string) substr($pattern, 0, $first);
			if ($host !== '' && strpos($lead, '.') !== false && self::excludeHost($lead) === $host) {
				$pattern = (string) substr($pattern, $first);
				$sited = true;
			}
		}
		if ($sited) {
			$pattern = preg_split('/[?#]/', $pattern, 2)[0];
		}
		$pattern = rawurldecode((string) $pattern);

		$homePath = '/' . trim(rawurldecode((string) $homePath), '/');
		$homePath = ($homePath === '/') ? '/' : $homePath . '/';
		$atHome = (rtrim(strtolower($path), '/') . '/') === strtolower($homePath);
		if ($pattern === '' || $pattern === '/') {
			return $atHome;
		}
		if ($sited && (rtrim(strtolower($pattern), '/') . '/') === strtolower($homePath)) {
			return $atHome;
		}

		if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
			$regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '.'], preg_quote($pattern, '#'));
			if (@preg_match('#' . $regex . '#i', $path) === 1) {
				return true;
			}
			return $host !== '' && stripos($pattern, $host) !== false
				&& @preg_match('#' . $regex . '#i', $host . $path) === 1;
		}

		return stripos($path, $pattern) !== false || stripos(rtrim($path, '/') . '/', $pattern) !== false;
	}

	public static function queryExcludeMatch($pattern, $hostPath, $query)
	{
		$pattern = trim((string) $pattern);
		$query = (string) $query;
		if ($query === '' || $pattern === '' || $pattern[0] === '#' || trim($pattern, '/') === '') {
			return false;
		}
		$hostPath = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', (string) $hostPath);
		$path = (string) substr($hostPath, strcspn($hostPath, '/'));
		$path = $path === '' ? '/' : $path;
		$uri = $path . '?' . $query;
		$queryAt = strlen($path);
		for ($at = strpos($uri, $pattern); $at !== false; $at = strpos($uri, $pattern, $at + 1)) {
			if ($at + strlen($pattern) > $queryAt) {
				return true;
			}
		}
		return false;
	}

	public static function cacheExcludeMatch($pattern, $hostPath, $query = '', $homePath = '/')
	{
		return self::excludeMatch($pattern, $hostPath, $homePath) || self::queryExcludeMatch($pattern, $hostPath, $query);
	}

	public static function cacheExcludedBy($patterns, $hostPath, $query = '', $homePath = null)
	{
		if ($homePath === null) {
			$homePath = self::homePath();
		}
		if (is_string($patterns)) {
			$patterns = explode("\n", $patterns);
		}
		if (!is_array($patterns)) {
			return false;
		}
		foreach ($patterns as $pattern) {
			if (is_scalar($pattern) && self::cacheExcludeMatch((string) $pattern, $hostPath, $query, $homePath)) {
				return trim((string) $pattern);
			}
		}
		return false;
	}

	public static function requestCacheExcludedBy($patterns, $url = null)
	{
		return self::cacheExcludedBy($patterns, self::requestHostPath($url), self::requestQuery($url));
	}

	public static function excludedBy($patterns, $hostPath, $homePath = null)
	{
		if ($homePath === null) {
			$homePath = self::homePath();
		}
		if (is_string($patterns)) {
			$patterns = explode("\n", $patterns);
		}
		if (!is_array($patterns)) {
			return false;
		}
		foreach ($patterns as $pattern) {
			if (is_scalar($pattern) && self::excludeMatch((string) $pattern, $hostPath, $homePath)) {
				return trim((string) $pattern);
			}
		}
		return false;
	}
}
