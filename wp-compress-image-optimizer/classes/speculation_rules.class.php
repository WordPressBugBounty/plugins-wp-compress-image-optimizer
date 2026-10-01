<?php
/**
 * WPC Speculation Rules — instant subsequent navigations via the Speculation Rules API.
 *
 * Injects <script type="speculationrules"> so Chrome prerenders likely next pages
 * (hover/viewport heuristics, eagerness "moderate"). Unsupported browsers ignore the
 * tag entirely (progressive enhancement, zero risk). PSI does not score it; real
 * users get ~0ms paint on the next click.
 *
 * SAFETY DOCTRINE (do not loosen):
 *  - Never active for logged-in users (personalized markup must not be prerendered
 *    stale, and wp-admin bars carry nonces).
 *  - href_matches excludes cart/checkout/account/wp-admin/wp-login + any URL with a
 *    query string (add-to-cart etc. are GET side effects on many stores).
 *    URLPattern NOTE (review 2026-08-24, verified against the urlpattern-polyfill
 *    reference impl): the query exclude MUST be written '/*\\?(.+)' — a leading '/'
 *    so the pattern is origin-rooted rather than resolved relative to the current
 *    page, and '(.+)' rather than '*' for the search part because a bare '*' search
 *    component also matches the EMPTY query and would exclude every URL. Never
 *    change this pattern without re-running the URLPattern test matrix.
 *  - "moderate" eagerness only: prerender starts on hover/mousedown — no speculative
 *    crawl of every link (data cost, analytics noise).
 *  - prerender with prefetch fallback: browsers that support the API but decline
 *    prerender (memory pressure, cross-origin iframes) still get the prefetch win.
 *  - WP >= 6.8 ships its own conservative speculationrules (prefetch-only). When our
 *    feature is ON we suppress core's block via register_hooks() so ours (prerender)
 *    is the single source; when OFF, core's stays untouched.
 *  - Chrome silently drops invalid rules/patterns. Any edit to the rules JSON must be
 *    re-verified in DevTools -> Application -> Speculative loads on a canary site.
 */
class wps_ic_speculation_rules
{
    /**
     * Call once at plugin init (see WIRING.md). When the feature is enabled,
     * suppresses WP core's own speculative-loading block (WP >= 6.8) so the page
     * carries exactly one ruleset — ours. Without this, core's tag is already in
     * the HTML and process_html()'s dedupe check makes this feature a permanent no-op
     * on modern WP.
     */
    public static function register_hooks($settings)
    {
        if (!empty($settings['speculation-rules']) && $settings['speculation-rules'] == '1') {
            add_filter('wp_speculation_rules_configuration', '__return_null');
        }
    }

    public static function isActive($settings, $page_excludes)
    {
        if (isset($_GET['disableSpeculation'])) return false; // testing bypass, same convention as disableDelay
        if (is_user_logged_in()) return false;
        // Per-page force-enable ('1') / force-disable ('0'), matching the enqueues.class.php convention.
        if (isset($page_excludes['speculation_rules'])) {
            if ($page_excludes['speculation_rules'] == '0') return false;
            if ($page_excludes['speculation_rules'] == '1') return true;
        }
        if (empty($settings['speculation-rules']) || $settings['speculation-rules'] != '1') return false;
        return true;
    }

    public function process_html($html)
    {
        if (stripos($html, 'type="speculationrules"') !== false) return $html; // page already ships rules
        if (stripos($html, "type='speculationrules'") !== false) return $html; // single-quoted variant

        // Subdirectory installs: root-relative excludes like '/wp-admin/*' never match
        // '/site/wp-admin/*', so prefix every path pattern with the home path (WP core's
        // prefix_path_pattern approach). Root installs get $base = '' (patterns unchanged).
        $base = '';
        if (function_exists('home_url')) {
            $p = parse_url(home_url('/'), PHP_URL_PATH);
            if (is_string($p) && $p !== '' && $p !== '/') $base = rtrim($p, '/');
        }

        $exclude = array(
            array('href_matches' => $base . '/wp-admin/*'),
            array('href_matches' => $base . '/wp-login.php*'),
            // Any same-origin URL carrying a query string — see URLPattern NOTE above.
            array('href_matches' => '/*\\?(.+)'),
            array('href_matches' => $base . '/cart/*'), array('href_matches' => $base . '/checkout/*'),
            array('href_matches' => $base . '/my-account/*'), array('href_matches' => $base . '/account/*'),
            array('selector_matches' => '.no-prerender, .no-prerender a, [rel~=nofollow]'),
        );

        // WooCommerce localizes/renames its endpoints ('/panier/', '/kasse/', ...) — derive
        // the real paths when Woo is present; the English literals above stay as belt.
        if (function_exists('wc_get_page_id') && function_exists('get_permalink')) {
            foreach (array('cart', 'checkout', 'myaccount') as $wcPage) {
                $pid = wc_get_page_id($wcPage);
                if ($pid > 0) {
                    $path = parse_url(get_permalink($pid), PHP_URL_PATH);
                    if (is_string($path) && $path !== '' && $path !== '/') {
                        $exclude[] = array('href_matches' => rtrim($path, '/') . '/*');
                    }
                }
            }
        }

        $where = array('and' => array(
            array('href_matches' => ($base !== '' ? $base : '') . '/*'),
            array('not' => array('or' => $exclude)),
        ));
        $rules = array(
            'prerender' => array(array('where' => $where, 'eagerness' => 'moderate')),
            'prefetch'  => array(array('where' => $where, 'eagerness' => 'moderate')),
        );
        $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        if (!$json) return $html; // encode failure -> page unchanged
        $tag = '<script type="speculationrules" id="wpc-speculation-rules">' . $json . '</script>';

        // First occurrence only — str_replace would inject at every '</head>' literal on the page.
        $pos = stripos($html, '</head>');
        if ($pos === false) return $html;
        return substr_replace($html, $tag, $pos, 0);
    }
}
