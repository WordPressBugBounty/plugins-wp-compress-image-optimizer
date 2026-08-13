<?php

if (!class_exists('wps_ic_url_key')) {
    include_once WPS_IC_DIR . 'traits/url_key.php';
}


if (!function_exists('wpc_crit_meta_write')) {
    // Mixed-tree belt: the canonical atomic writer lives in defines.php — a partial
    // deploy (FTP truncation, stale defines.php) degrades to a plain write here,
    // never a fatal (law 10). Complete trees never reach this definition.
    function wpc_crit_meta_write($path, $value)
    {
        try {
            return @file_put_contents($path, (string) $value) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

class wps_criticalCss
{

    static public $API_URL = WPS_IC_CRITICAL_API_URL;
    static public $API_ASSETS_URL = WPS_IC_CRITICAL_API_ASSETS_URL;
    public static $url;
    private static $maxRetries = 5;
    public $urlKey;
    public $serverRequest;
    public $url_key_class;
    /**
     * Normalize a URL to use the public-facing hostname from home_url().
     * On reverse proxy / Kinsta sites, HTTP_HOST and get_permalink() return the
     * origin hostname. This rewrites it to the public domain so keys match.
     * On normal sites (HTTP_HOST === home_url host), returns URL unchanged.
     */
    private function normalizeUrl($url) {
        $homeUrl = rtrim(home_url(), '/');
        $homeHost = parse_url($homeUrl, PHP_URL_HOST);
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';

        if (!$homeHost || !$httpHost || $httpHost === $homeHost) {
            // Same host — no proxy, return as-is (99% of sites)
            if (strpos($url, 'http') !== 0 && strpos($url, '/') === 0) {
                return $homeUrl . $url;
            }
            return $url;
        }

        // Proxy detected: HTTP_HOST differs from home_url host
        $parsed = parse_url($url);

        if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
            // Full URL with scheme
            if ($parsed['host'] === $homeHost) {

                return $url;
            }
            // Wrong host → replace with home_url
            return $homeUrl . ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
        }

        // No scheme (e.g. "origin.host.com/path") → strip origin hostname, prepend home_url
        $path = $url;
        if (strpos($path, $httpHost) === 0) {
            $path = substr($path, strlen($httpHost));
        }
        return $homeUrl . '/' . ltrim($path, '/');
    }

    public function __construct($url = '')
    {
        $wpc_fromreq132 = empty($url);
        if (empty($url)) {
            $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        $url = $this->normalizeUrl($url);

        self::$url = $url;

        if (!empty($_GET['debugCritical_replace'])) {
            $url = explode('?', $url);
            $url = $url[0];
        }

        $this->serverRequest = $url;

        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup($url);
        $this->urlKey = ltrim($this->urlKey, '/');


        try {
            if ($wpc_fromreq132
                && strpos($url, '?') !== false
                && !isset($_GET['wpc_nocrit'])
                && !(isset($_GET['crit']) && (string) $_GET['crit'] === '0')
                && apply_filters('wpc_unknown_param_crit', true)
                && defined('WPS_IC_CRITICAL')
                && !@file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')) {
                parse_str((string) substr((string) strstr($url, '?'), 1), $wpc_qp132);
                $wpc_deny132 = apply_filters('wpc_content_param_denylist', ['p', 'page_id', 's', 'cat', 'tag', 'm', 'paged', 'attachment_id', 'preview', 'preview_id', 'preview_nonce', 'elementor-preview', 'lang', 'product', 'post_type', 'name', 'author', 'currency', 'add-to-cart', 'orderby', 'min_price', 'max_price']);
                $wpc_safe132 = !empty($wpc_qp132);
                foreach (array_keys((array) $wpc_qp132) as $wpc_pk132) {
                    if (in_array(strtolower((string) $wpc_pk132), (array) $wpc_deny132, true)
                        || stripos((string) $wpc_pk132, 'preview') !== false
                        || stripos((string) $wpc_pk132, 'filter') === 0) {
                        $wpc_safe132 = false;
                        break;
                    }
                }
                if ($wpc_safe132) {
                    $wpc_canon132 = ltrim((string) (new wps_ic_url_key())->setup((string) strtok($url, '?')), '/');
                    if ($wpc_canon132 !== '' && $wpc_canon132 !== $this->urlKey
                        && @file_exists(WPS_IC_CRITICAL . $wpc_canon132 . '/critical_desktop.css')) {
                        $this->urlKey = $wpc_canon132;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $this->createDirectory();

    }

    public function createDirectory()
    {
        if (!file_exists(WPS_IC_CRITICAL)) {
            mkdir(WPS_IC_CRITICAL);
        }
    }


    public function criticalRunning($id = false)
    {
		if ($id === false){
			$url = self::$url;
		} else {
			if ($id === 'home' || $id == 0) {
				$homePage = get_option('page_on_front');

				if (!$homePage) {
					$url = home_url();
				} else {
					$url = get_permalink($homePage);
				}
			} else {
				$url = get_permalink($id);
			}
		}

        $running = get_transient('wpc_critical_key_' . $this->url_key_class->setup($url));
        if (empty($running) || !$running) {
            return false;
        } else {
            return true;
        }
    }

    public function generateCriticalCSS($postID = 0, $skipCap = false)
    {

        // The branch below already resolves the front page for 'home' / falsy / 0, so the
        // former if (!empty($postID)) wrapper made this method a silent no-op for its OWN
        // default argument — every caller passing 0 did nothing and reported success.
        if ($postID === 'home' || !$postID || $postID == 0) {
            $homePage = get_option('page_on_front');
            $blogPage = get_option('page_for_posts');

            if (!$homePage) {
                $url = home_url();
            } else {
                $url = get_permalink($homePage);
            }
        } else {
            $url = get_permalink($postID);
        }

        if (empty($url)) {
            return;
        }

        $url_key = $this->url_key_class->setup($url);

        if ($this->criticalExists()) {
            // Nothing
        } else {
            $url = rtrim($url, '?');
            $this->initCritical($postID, $url, $url_key, $type = 'meta','', $skipCap);
        }
    }

    public function isHomeURL()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');
        $current_url = explode('?', $current_url);
        $current_url = $current_url[0];
        $home_url = rtrim($home_url, '/');
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    public function criticalExists($returnDir = false)
    {
        if (!empty($_GET['debugCritical_replace'])) {
            return [WPS_IC_CRITICAL, $this->urlKey, 'file' => WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css', 'exists' => file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')];
        }


        if (function_exists('wpc_inv2_gate_serve') && wpc_inv2_gate_serve($this->urlKey)) {
            return false;
        }

        // v7.10.391 zero-dark purge: stale artifacts inside a hard-purge bypass window
        // resolve as absent — every lane degrades to the ordinary critless render.
        if (function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($this->urlKey)) {
            return false;
        }

        // v7.10.524 — GENERATOR EPOCH GATE. Artifacts were keyed on the template hash alone,
        // so a service-side correctness fix could never reach one already on disk: it looked
        // exactly like the bug was never fixed. Same shape as the bypass above — below the
        // advertised floor resolves as ABSENT, and the existing refetch path does the rest.
        // Fails OPEN: floor 0 (the shipped default) or an unreadable stamp changes nothing.
        $wpc_fmin524 = (int) get_option('wpc_crit_epoch_min', 0);
        if ($wpc_fmin524 > 0 && defined('WPS_IC_CRITICAL')) {
            $wpc_epf524 = WPS_IC_CRITICAL . $this->urlKey . '/crit_epoch.txt';
            $wpc_have524 = @is_file($wpc_epf524) ? (int) trim((string) @file_get_contents($wpc_epf524)) : 0;
            if ($wpc_have524 < $wpc_fmin524) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-epoch-stale', (string) $this->urlKey, '', [
                        'have' => $wpc_have524, 'min' => $wpc_fmin524,
                    ]);
                }
                return false;
            }
        }

        $return = [];

        $desktopFilePath = WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css';
        $mobileFilePath = WPS_IC_CRITICAL . $this->urlKey . '/critical_mobile.css';

        $desktopFileUrl = WPS_IC_CRITICAL_URL . $this->urlKey . '/critical_desktop.css';
        $mobileFileUrl = WPS_IC_CRITICAL_URL . $this->urlKey . '/critical_mobile.css';

        if (file_exists($desktopFilePath) && filesize($desktopFilePath) > 0) {
            $content = file_get_contents($desktopFilePath);
            $isHtml = preg_match('/<body\b[^>]*>/', $content);

            if ($isHtml) {
                return false;
            }

            // v7.10.617 — sanity mark: the rewrite seam proved these exact bytes are blind
            // to the page's ATF sections. Resolve ABSENT like the gates above; regenerated
            // bytes no longer match the mark, so a fresh artifact serves untouched.
            if (function_exists('wpc_crit_sanity_bad617')
                && wpc_crit_sanity_bad617(dirname($desktopFilePath), $content)) {
                // v7.10.622 — the loop runs HERE (mark holds, page critless, dispatch
                // re-fires): tick the stall where it can actually count.
                $wpc_stall622 = function_exists('wpc_crit_sanity_stall_tick622')
                    ? wpc_crit_sanity_stall_tick622(dirname($desktopFilePath)) : 0;
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-sanity-mark', (string) $this->urlKey, '', [
                        'stall' => $wpc_stall622,
                    ]);
                }
                return false;
            }

            // v7.10.546 — ALWAYS expose the disk paths under explicit keys, whatever $returnDir
            // says. The default is FALSE, so a caller that omits it silently receives URLs; feed
            // one to file_get_contents/filemtime and PHP opens a NETWORK stream on
            // default_socket_timeout, following redirects. That cost this site 18-42s per render
            // and was invisible to http_n, the FPM slowlog and PROCESSLIST simultaneously.
            // A filesystem call can now ask for a path by name and never get a URL by omission.
            $return['desktop_path'] = $desktopFilePath;
            $return['dir']          = dirname($desktopFilePath) . '/';
            if ($returnDir) {
                $return['desktop'] = $desktopFilePath;
            } else {
                $return['desktop'] = $desktopFileUrl;
            }
        }

        if (file_exists($mobileFilePath) && filesize($mobileFilePath) > 0) {
            $content = file_get_contents($mobileFilePath);
            $isHtml = preg_match('/<body\b[^>]*>/', $content);

            if ($isHtml) {
                return false;
            }

$return['mobile_path'] = $mobileFilePath;
                        if ($returnDir) {
                $return['mobile'] = $mobileFilePath;
            } else {
                $return['mobile'] = $mobileFileUrl;
            }
        }

        if (empty($return['desktop']) || empty($return['mobile'])) {
            return false;
        }

        return $return;
    }

    public function initCritical($postID, $url, $url_key, $type, $timeout = 120, $skipCap = false)
    {

        if (function_exists('is_404') && function_exists('did_action') && did_action('template_redirect') && is_404()) {
            return true;
        }
        // v7.10.530 — attachment/search/feed pages were minting a crit dir each (19,955 files on
        // the flagship vs 20 page-cache entries). Same did_action guard: conditionals are only
        // meaningful once the query is resolved.
        if (function_exists('did_action') && did_action('template_redirect')
            && function_exists('wpc_is_low_value_page') && wpc_is_low_value_page()) {
            return true;
        }
        $requests = new wps_ic_requests();

        $url = trim($url);
        if (empty($url) || empty(get_option(WPS_IC_OPTIONS)['api_key'])) {
            return false;
        }

        // Normalize URL + recompute key so ALL downstream code uses the public domain
        $url = $this->normalizeUrl($url);
        $url_key = $this->url_key_class->setup($url);

        // A generation nobody will consume is pure waste: every dispatch lane funnels
        // through here, so the consume-side switch gates the gen side at the choke point
        if (apply_filters('wpc_gen_requires_consume', true) && empty($_GET['forceCritical'])
            && !(function_exists('wp_doing_ajax') && wp_doing_ajax()
                && function_exists('current_user_can') && current_user_can('manage_options'))) {
            $wpc_set765 = get_option(WPS_IC_SETTINGS);
            $wpc_on765 = is_array($wpc_set765) && !empty($wpc_set765['critical']['css'])
                && $wpc_set765['critical']['css'] == '1';
            if (!$wpc_on765) {
                $wpc_ex765 = get_option('wpc-excludes');
                $wpc_pid765 = ($postID === 'home' || empty($postID)) ? 'home' : $postID;
                if (is_array($wpc_ex765)
                    && isset($wpc_ex765['page_excludes'][$wpc_pid765]['critical_css'])
                    && $wpc_ex765['page_excludes'][$wpc_pid765]['critical_css'] == '1') {
                    $wpc_on765 = true;
                }
            }
            if (!$wpc_on765) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('gen-skip-consume-off', (string) $url_key, (string) $url, []);
                }
                return true;
            }
        }

        // The .530 guard above is gated on template_redirect, so off the render path
        // (admin-ajax Rebuild, cron, CLI) it never ran and attachment permalinks minted a
        // crit dir each. Same intent, resolved from the URL. Render path keeps the query test.
        if (!(function_exists('did_action') && did_action('template_redirect'))
            && function_exists('wpc_url_is_low_value') && wpc_url_is_low_value($url)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-skip-low-value-url', (string) $url_key, (string) $url, []);
            }
            return true;
        }

        // Successful-generation rate cap: landless loops park below, but a loop whose
        // generations all LAND resets that brake and can run for days. Four automatic
        // dispatches per URL per two hours; human regenerate bypasses.
        if (!$skipCap && !(function_exists('wp_doing_ajax') && wp_doing_ajax())
            && apply_filters('wpc_gen_rate_cap', true)) {
            $wpc_rc166 = (array) get_option('wpc_gen_rate', []);
            $wpc_rk166 = (string) $url_key;
            $wpc_rl166 = array_values(array_filter((array) ($wpc_rc166[$wpc_rk166] ?? []), function ($t) {
                return (time() - (int) $t) < 7200;
            }));
            if (count($wpc_rl166) >= 4) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('gen-dispatch-capped', $wpc_rk166, (string) $url, ['n2h' => count($wpc_rl166)]);
                }
                return false;
            }
            // Site-wide automatic budget: per-URL caps don't bound breadth (sitemap-wide
            // crawls = URLs × 4/2h); human regenerate bypasses via the same gate above.
            $wpc_sb166 = array_values(array_filter((array) get_option('wpc_gen_rate_site', []), function ($t) {
                return (time() - (int) $t) < 3600;
            }));
            if (count($wpc_sb166) >= (int) apply_filters('wpc_gen_site_budget', 15)) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('gen-dispatch-site-budget', $wpc_rk166, (string) $url, ['n1h' => count($wpc_sb166)]);
                }
                return false;
            }
            $wpc_sb166[] = time();
            update_option('wpc_gen_rate_site', $wpc_sb166, false);
            $wpc_rl166[] = time();
            $wpc_rc166[$wpc_rk166] = $wpc_rl166;
            if (count($wpc_rc166) > 200) {
                $wpc_rc166 = array_slice($wpc_rc166, -100, null, true);
            }
            update_option('wpc_gen_rate', $wpc_rc166, false);
        }


        if (method_exists('wps_ic_url_key', 'persistKeyUrl')) {
            wps_ic_url_key::persistKeyUrl($url_key, $url);
        }

        // Poll /status for any in-flight request for this URL
        $needsPush = true;
        $uuid_key    = 'wpc_critical_uuid_' . $url_key;
        $pendingUuid = get_transient($uuid_key);

        // Fallback: if object cache (Redis) lost the transient, read directly from DB
        if (!$pendingUuid) {
            global $wpdb;
            $dbVal = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_' . $uuid_key
            ));
            if ($dbVal) {
                $pendingUuid = maybe_unserialize($dbVal);
            }
        }


        if (!$pendingUuid && defined('WPS_IC_CRITICAL')) {
            $wpc_uf = WPS_IC_CRITICAL . $url_key . '/uuid.txt';
            if (@is_readable($wpc_uf)) {
                // IDENTITY BELT (service receipt: /compare-2/ crit, ZERO dispatches in their
                // requests table): a dispatch writes uuid.txt WITH dispatch_ts.txt; a land
                // writes it with land_uuid.txt. A uuid with NO dispatch stamp was never
                // dispatched for THIS page — storm-borrowed foreign state; trusting it
                // re-lands another page's crit forever. Unlink; a real dispatch re-mints.
                if (!@is_readable(WPS_IC_CRITICAL . $url_key . '/dispatch_ts.txt')) {
                    @unlink($wpc_uf);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('uuid-no-dispatch-stamp', (string) $url_key, '', []);
                    }
                } elseif ((time() - (int) @filemtime($wpc_uf)) > 6 * 3600
                    && !@is_readable(WPS_IC_CRITICAL . $url_key . '/land_uuid.txt')) {
                    // A2: TTL — a uuid older than 6h whose gen never landed is dead weight
                    // pinning /status polls on artifacts that may have expired server-side.
                    @unlink($wpc_uf);
                } else {
                    $wpc_disk_uuid = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) @file_get_contents($wpc_uf)));
                    if ($wpc_disk_uuid !== '') {
                        $pendingUuid = $wpc_disk_uuid;
                    }
                }
            }
        }


        // Visitor renders may carry at most ONE 3s poll per URL per minute — under a cold-cache
        // convoy every queued render otherwise pays this inline, and 2s renders become 60s queues.
        // Background lanes (cron/ajax/warm) poll freely; they hold one budgeted worker.
        $wpc_bg178 = (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_CRON') && DOING_CRON)
            || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']);
        if ($pendingUuid && !$wpc_bg178) {
            // Visitor renders carry ZERO artifact HTTP; background lanes own the poll.
            // Site-wide kick budget: post-update every URL is pending — per-URL gates alone
            // fan one loopback per URL into an FPM stampede.
            if (function_exists('wpc_pipeline_admission_ok') && !wpc_pipeline_admission_ok()) {
                $pendingUuid = false;
            } elseif (!get_transient('wpc_stpoll_' . md5($url_key)) && !get_transient('wpc_kick_budget30')) {
                set_transient('wpc_kick_budget30', 1, 30);
                set_transient('wpc_stpoll_' . md5($url_key), 1, 60);
                if (function_exists('wpc_repull_kick_now')) {
                    wpc_repull_kick_now($url_key);
                }
            }
            $pendingUuid = false;
        }

        if ($pendingUuid) {
            $statusUrl = str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=' . urlencode($pendingUuid);
            $response  = wp_remote_get($statusUrl, ['timeout' => 3]);

            if (!is_wp_error($response)) {
                if (wp_remote_retrieve_response_code($response) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($response), true);

                    if (!empty($data['status']) && $data['status'] === 'success') {
                        $criticalCSS = new wps_criticalCss();
                        $saveResult = $criticalCSS->saveCriticalCss($url_key, [
                            'url' => [
                                'desktop' => $data['desktop_url'],
                                'mobile'  => $data['mobile_url'],
                            ],


                            'lcp_url' => !empty($data['lcp_url']) ? $data['lcp_url'] : '',
                            'lcp_src' => 'poll',

                            // Root of "manifest emitted but never consumed": both callers built this
                            // array by hand and dropped delay_url, so the stash+fetch never armed.
                            'delay_url' => !empty($data['delay_url']) ? $data['delay_url'] : '',


                            'used_css_url' => !empty($data['used_css_url']) ? $data['used_css_url'] : '',
                            'tpl_key'      => !empty($data['tpl_key']) ? $data['tpl_key'] : '',
                        ], 'meta', $url);


                        if (function_exists('wpc_consume_fonts_artifact')) {
                            $wpc_poll_fonts = (!empty($data['fonts']) && is_array($data['fonts'])) ? $data['fonts'] : [];
                            if (empty($wpc_poll_fonts) && !empty($data['fonts_url'])) {
                                if (defined('WPS_IC_CRITICAL')) { wpc_crit_meta_write(rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/fonts_url.txt', trim((string) $data['fonts_url'])); }
                                // Inline fetch only in background lanes; visitor renders leave the
                                // stashed fonts_url.txt to the cron repull.
                                if ($wpc_bg178) {
                                    $wpc_ff = wp_remote_get((string) $data['fonts_url'], ['timeout' => 6]);
                                    if (!is_wp_error($wpc_ff) && wp_remote_retrieve_response_code($wpc_ff) === 200) {
                                        $wpc_fj = json_decode(wp_remote_retrieve_body($wpc_ff), true);
                                        if (is_array($wpc_fj)) {
                                            $wpc_poll_fonts = (!empty($wpc_fj['fonts']) && is_array($wpc_fj['fonts'])) ? $wpc_fj['fonts'] : $wpc_fj;
                                        }
                                    }
                                }
                            }
                            if (!empty($wpc_poll_fonts)) {
                                wpc_consume_fonts_artifact($wpc_poll_fonts, $url_key);
                            }
                        }


                        if (defined('WPS_IC_CRITICAL')
                            && !@is_readable(rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/font-subsets.css')) {
                            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                                && !wp_next_scheduled('wpc_combine_fonts_fetch', [$url_key, 1])) {
                                wpc_pl_sched(time() + 50, 'wpc_combine_fonts_fetch', [$url_key, 1]);
                            }
                            wpc_spawn_cron();
                        }

                        $pageUrlKey = (new wps_ic_url_key())->setup($url);


                        if (function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()
                            && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                            function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($pageUrlKey, $url, 'crit-land-poll') : wps_ic_cache_integrations::purgeUrlHtml($pageUrlKey, $url, ['context' => 'crit-land-poll']);
                        } else {


                            wps_ic_cache_integrations::purgeCacheFiles($pageUrlKey);

                            // 2. Kinsta edge cache — per-URL if available, full if not
                            if (isset($GLOBALS['kinsta_cache']) && !empty($GLOBALS['kinsta_cache']->kinsta_cache_purge)) {
                                if (method_exists($GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_url')) {
                                    $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_url($url);
                                } else {
                                    $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
                                }
                            }

                            // 3. Other hosts (WP Engine, SiteGround, Cloudflare, Varnish, etc.)
                            wpc_foreign_purge610($pageUrlKey, 'crit-v2');
                        }

                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                        return false;
                    }

                    if (!empty($data['status']) && $data['status'] === 'not_found') {
                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                        // uuid.txt too — the disk fallback re-hydrates a dead uuid otherwise.
                        // JOURNAL IT: dropping a pending uuid is how a FINISHED gen silently
                        // disappears (busy: /status returned success for c7c999c8, then uuid.txt
                        // vanished while land_uuid stayed on the previous gen and lcp.json never
                        // changed). Without this line the only evidence is an absence.
                        if (defined('WPS_IC_CRITICAL')) {
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('uuid-cleared-not-found', (string) $url_key, '', [
                                    'uuid' => substr((string) @file_get_contents(WPS_IC_CRITICAL . $url_key . '/uuid.txt'), 0, 40),
                                    'had_land' => @is_readable(WPS_IC_CRITICAL . $url_key . '/land_uuid.txt') ? 1 : 0,
                                ]);
                            }
                            @unlink(WPS_IC_CRITICAL . $url_key . '/uuid.txt');
                        }
                        if (function_exists('wpc_repull_kick_now')) {
                            wpc_repull_kick_now($url_key);
                        }
                    }

                    if (!empty($data['status']) && $data['status'] === 'failed') {
                        if (!empty($data['error_type']) && $data['error_type'] === 'fetch_blocked') {
                            $needsPush = true;
                            $domain = parse_url($url, PHP_URL_HOST);
                            if ($domain) {
                                set_transient('wpc_push_domain_' . $domain, true, 86400 * 7);
                            }
                        }
                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                    }
                }
            }
        }


        // (it used to sit at the top of initCritical, which skipped polling entirely inside a backoff


        if (function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active()) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-backoff-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical']);
            }
            return true;
        }


        if (empty($_GET['forceCritical']) && function_exists('wpc_gen_landless_parked') && wpc_gen_landless_parked((string) $url_key)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-parked-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical']);
            }
            return true;
        }


        if (empty($_GET['forceCritical']) && function_exists('wpc_land_cooldown_active') && wpc_land_cooldown_active($url_key)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-cooldown-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical']);
            }
            return true;
        }

        $transient_name = 'wpc_critical_key_' . $url_key; // Safe, short, unique.
        $critTransient = get_transient($transient_name);

        if (!empty($critTransient) && empty($_GET['forceCritical'])) {
            // Die, already running!
            return true;
        }


        if (apply_filters('wpc_gen_single_flight', true) && empty($_GET['forceCritical'])) {
            // flock front: add_option is check-then-insert (racy under true simultaneity, and
            // the transient fast-shed above dies with the object cache) — the file lock is the
            // atomic gate that caps concurrent gen work at 1 per URL. Held for the request.
            if (defined('WPS_IC_CRITICAL')) {
                static $wpc_genlk = [];
                $wpc_lkf = rtrim(WPS_IC_CRITICAL, '/') . '/.genlock-' . md5((string) $url_key) . '.lock';
                if (!isset($wpc_genlk[$wpc_lkf])) {
                    $wpc_fh = @fopen($wpc_lkf, 'c');
                    if ($wpc_fh) {
                        if (!@flock($wpc_fh, LOCK_EX | LOCK_NB)) {
                            @fclose($wpc_fh);
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('gen-flock-busy', $url_key, '', []);
                            }
                            return true;
                        }
                        $wpc_genlk[$wpc_lkf] = $wpc_fh; // held until process exit releases it
                    }
                }
            }
            $wpc_sf = 'wpc_gen_sf_' . $url_key;
            if (!add_option($wpc_sf, time(), '', 'no')) {
                $wpc_sf_at = (int) get_option($wpc_sf);
                if ($wpc_sf_at && (time() - $wpc_sf_at) < 120) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('gen-single-flight', $url_key, '', ['skip' => 1]);
                    }
                    return true;
                }
                update_option($wpc_sf, time());
            }


            $wpc_crit_here123 = false;
            if (defined('WPS_IC_CRITICAL') && $url_key) {
                $wpc_cd123 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/';
                $wpc_crit_here123 = (@filesize($wpc_cd123 . 'critical_desktop.css') > 5) || (@filesize($wpc_cd123 . 'critical_mobile.css') > 5);
            }
            $wpc_gsp = (int) apply_filters('wpc_gen_global_spacing', 45);
            if ($wpc_gsp > 0 && $wpc_crit_here123) {
                if (!add_option('wpc_gen_sf_global', time(), '', 'no')) {
                    $wpc_gsf_at = (int) get_option('wpc_gen_sf_global');
                    if ($wpc_gsf_at && (time() - $wpc_gsf_at) < $wpc_gsp) {
                        delete_option($wpc_sf);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('gen-deferred-global', $url_key, '', ['age' => time() - $wpc_gsf_at]);
                        }
                        return true;
                    }
                    update_option('wpc_gen_sf_global', time());
                }
            }
        }

        // Make transient expire after 30 mins
        set_transient($transient_name, true, 60 * 5);

        $uuid     = wp_generate_uuid4();
        $uuid_key = 'wpc_critical_uuid_' . $url_key;
        set_transient($uuid_key, $uuid, 60 * 5);


        // "real loop-sustainer". Disk survives object-cache loss; the poll reads this back (transient →
        // DB → this file). Sanitized identically to the land-time writer (see saveCriticalCss uuid.txt).
        if (defined('WPS_IC_CRITICAL')) {
            $wpc_udir = WPS_IC_CRITICAL . $url_key . '/';
            if (!is_dir($wpc_udir)) { @mkdir($wpc_udir, 0777, true); }
            wpc_crit_meta_write($wpc_udir . 'uuid.txt', preg_replace('/[^A-Za-z0-9-]/', '', $uuid));
            wpc_crit_meta_write($wpc_udir . 'dispatch_ts.txt', (string) time());
        }

        $options = get_option(WPS_IC_OPTIONS);
        $apikey  = $options['api_key'] ?? '';
        $forcePull = isset($_GET['pushMode']) && sanitize_key($_GET['pushMode']) === 'false';

        // Build args — matches existing flow (cdn-rewrite.php:2968, ajax.class.php:464)
        $args = [
            'url'     => (function_exists('wpc_canon_url609') ? wpc_canon_url609($url) : $url) . '?criticalCombine=true&testCompliant=true',
            'source'  => 'crit-v2',
            'uuid'    => $uuid,
            'version' => (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : (class_exists('wps_ic') ? wps_ic::$version : '')),
            'async'   => 'false',
            'dbg'     => 'true',
            'hash'    => time() . mt_rand(100, 9999),
            'apikey'  => $apikey,
        ];
        if (function_exists('wpc_sanity_escalate622')) {
            $args = wpc_sanity_escalate622($args, $url);
        }


        // (entries[].key full paths, has_form, atf_bg, lcp_element…) are emitted only for
        // capability-mode requests; canary/bridge regens still produce the v1 flat shape, which


        $wpc_v3set = get_option(WPS_IC_SETTINGS);
        if (is_array($wpc_v3set) && !empty($wpc_v3set['delay-js-v2']) && $wpc_v3set['delay-js-v2'] == '1'
            && (!isset($wpc_v3set['delay-js-v3']) || $wpc_v3set['delay-js-v3'] != '0')
            && apply_filters('wpc_delay_manifest_capability', true)) {
            $args['capabilities'] = ['delay_manifest' => 1, 'consolidated_callback' => 1, 'delay_inline' => 1, 'lcp_inline' => 1, 'manifest' => 1];
        }


        $wpc_ucss_armed = false;
        if (function_exists('wpc_used_css_apply_demand')) {
            $wpc_ucss_armed = wpc_used_css_apply_demand($args, $url_key);
        }


        if (empty($args['tpl_key']) && function_exists('wpc_dispatch_tpl_key')
            && apply_filters('wpc_send_tpl_key_always', true)) {
            $wpc_dtk = wpc_dispatch_tpl_key($url_key);
            if ($wpc_dtk !== '') { $args['tpl_key'] = $wpc_dtk; }
        }
        // (Phase B per-page cache) content-version for the service's lcp/oversized cache — singular
        // posts/pages only (helper omits homepage/archive/dynamic). Inert until Phase B reads it.
        if (function_exists('wpc_dispatch_post_mtime') && apply_filters('wpc_send_post_modified', true)) {
            $wpc_pm = wpc_dispatch_post_mtime($postID, $url);
            if ($wpc_pm !== '') { $args['post_modified'] = $wpc_pm; }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('gen-dispatch', (string) $url_key, (string) $url, [
                'path'     => 'initCritical',
                'caps'     => isset($args['capabilities']) && is_array($args['capabilities']) ? implode(',', array_keys($args['capabilities'])) : '',
                'used_css' => $wpc_ucss_armed ? 1 : 0,
                'tpl'      => !empty($args['tpl_key']) ? 1 : 0,
            ]);
        }

        if (function_exists('wpc_gen_landless_note')) {
            wpc_gen_landless_note((string) $url_key);
        }


        if (!$forcePull && ($needsPush || wp_doing_ajax())) {
            // Durable (options, not transient — the 24h back-off must survive object-cache
            // loss or every render fires a full-page loopback). Ajax bypass = admins only.
            $pushFailedKey = 'wpc_push_nope2_' . $url_key;
            $wpc_pushAdmin = wp_doing_ajax() && function_exists('current_user_can') && current_user_can('manage_options');
            $wpc_pushNopeAt = (int) get_option($pushFailedKey, 0);
            if ($wpc_pushAdmin || (time() - $wpc_pushNopeAt) > 86400) {
                $html = $this->fetchCriticalCombineHtml($url);
                if ($html) {
                    $args['html'] = $html;
                    if (apply_filters('wpc_push_css_corpus', true)) {
                        $wpc_css763 = $this->fetchCriticalCombineCss($html);
                        if ($wpc_css763 !== '') {
                            $args['css'] = $wpc_css763;
                        }
                    }
                }
                if (function_exists('wpc_font_localizer_faces')) {
                    $wpc_ff794 = wpc_font_localizer_faces();
                    if (!empty($wpc_ff794)) { $args['fonts'] = $wpc_ff794; }
                } elseif (!$wpc_pushAdmin) {
                    // Push recovery failed — don't penalize visitors for 24h
                    update_option($pushFailedKey, time(), false);
                }
            }
        }

        // v7.10.496 — an EXPLICIT regenerate must bypass the service's GEN_DEBOUNCE_S (300s).
        // A debounced /generate returns 200 with the EXISTING crit_uuid — the stale artifact —
        // so a regenerate issued inside 5 minutes of any prior gen silently re-copies the old
        // crit and is indistinguishable from success. Routine/auto dispatches deliberately do
        // NOT force: the debounce is the service's only protection against gen storms.
        if (!empty($GLOBALS['wpc_gen_force496']) || apply_filters('wpc_gen_force', false, $url_key)) {
            $args['force'] = 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-force', (string) $url_key, (string) $url, ['why' => 'explicit-regenerate']);
            }
        }

        // POST to API — fire-and-forget
        if (!empty($args['css']) && function_exists('gzencode')) {
            $wpc_gzbody763 = gzencode(wp_json_encode($args));
            if ($wpc_gzbody763 !== false && strlen($wpc_gzbody763) < 10 * 1024 * 1024) {
                $call = wp_remote_post(self::$API_URL, [
                    'timeout'  => 2,
                    'blocking' => false,
                    'body'     => $wpc_gzbody763,
                    'headers'  => ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
                ]);
            } else {
                unset($args['css']);
                $requests = new wps_ic_requests();
                $call = $requests->POST(self::$API_URL, $args, [
                    'timeout'  => 2,
                    'blocking' => false,
                    'headers'  => ['Content-Type' => 'application/json'],
                ]);
            }
        } else {
            $requests = new wps_ic_requests();
            $call = $requests->POST(self::$API_URL, $args, [
                'timeout'  => 2,
                'blocking' => false,
                'headers'  => ['Content-Type' => 'application/json'],
            ]);
        }

        // B2: dispatch owns its pickup — collection must never depend on future traffic.
        if (function_exists('wpc_crit_collector_arm')) {
            wpc_crit_collector_arm((string) $url_key);
        }

        return;
    }


    private function fetchCriticalCombineHtml($url) {
        // Adaptive timeout: AJAX 5s (admin click), auto 1.5s (visitor page load)
        // v7.10.547 — the 5s tier said "admin click" but tested wp_doing_ajax(), a TRANSPORT
        // fact. wpc_repull_kick is registered on wp_ajax_nopriv, so any anonymous caller took
        // the 5s branch: receipted at 5,429ms per hit, unauthenticated, on a public endpoint.
        // Price the wait on who is actually waiting - a logged-in admin, not merely "an AJAX".
        $wpc_human547 = function_exists('wp_doing_ajax') && wp_doing_ajax()
            && function_exists('is_user_logged_in') && is_user_logged_in()
            && function_exists('current_user_can') && current_user_can('manage_options');
        $timeout = $wpc_human547 ? 5 : 1.5;

        $response = wp_remote_get($url . '?criticalCombine=true', [
            'timeout'    => $timeout,
            'cookies'    => [],
            'user-agent' => 'WP-Compress/CriticalCSS-Push',
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // A CF-fronted origin challenges its OWN loopback (public DNS resolves through the
            // edge) — exactly the sites push mode exists for. Go direct to self: same URL at
            // SERVER_ADDR with the Host header, TLS unverified (cert is for the hostname)
            $wpc_ip771 = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '';
            $wpc_host771 = (string) parse_url($url, PHP_URL_HOST);
            if ($wpc_ip771 !== '' && $wpc_host771 !== '' && filter_var($wpc_ip771, FILTER_VALIDATE_IP)
                && apply_filters('wpc_push_direct_loopback', true)) {
                $wpc_durl771 = preg_replace('#^(https?://)' . preg_quote($wpc_host771, '#') . '#i', '${1}' . $wpc_ip771, $url);
                $response = wp_remote_get($wpc_durl771 . '?criticalCombine=true', [
                    'timeout'    => $timeout,
                    'cookies'    => [],
                    'sslverify'  => false,
                    'user-agent' => 'WP-Compress/CriticalCSS-Push',
                    'headers'    => ['Host' => $wpc_host771],
                ]);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('push-html-direct', '', (string) $url, [
                        'code' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                    ]);
                }
            }
        }

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('push-html-failed', '', (string) $url, [
                    'code' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                ]);
            }
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }

        // Strip scripts and blank images — not needed for CSS generation
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/(<img[^>]+)\bsrc=["\'][^"\']*["\']/i', '$1src=""', $html);

        return $html;
    }


    private function fetchCriticalCombineCss($html) {
        if (!defined('WPS_IC_COMBINE') || !is_string($html) || $html === '') {
            return '';
        }
        if (!preg_match('#href=["\'][^"\']*/([A-Za-z0-9_\-]+)/css/wps_combined\.css[^"\']*["\']#i', $html, $wpc_m763)) {
            return '';
        }
        $wpc_path763 = rtrim(WPS_IC_COMBINE, '/') . '/' . $wpc_m763[1] . '/css/wps_combined.css';
        if (!@is_readable($wpc_path763)) {
            return '';
        }
        $wpc_max763 = (int) apply_filters('wpc_push_css_corpus_max', 8 * 1024 * 1024);
        $wpc_size763 = (int) @filesize($wpc_path763);
        if ($wpc_size763 < 1 || $wpc_size763 > $wpc_max763) {
            return '';
        }
        $wpc_css763 = (string) @file_get_contents($wpc_path763);
        if ($wpc_css763 === '' || stripos(ltrim($wpc_css763), '<!doctype') === 0 || stripos(ltrim($wpc_css763), '<html') === 0) {
            return '';
        }
        return $wpc_css763;
    }


    // ?apiGenerateCritical/?apiPreload endpoints — every one holds an FPM worker for the full


    public function sendCriticalUrl($realUrl = '', $postID = 0, $timeout = 20)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        ob_start();
        $type = 'meta';

        if (empty($realUrl)) {
            if ($postID === 'home' || !$postID || $postID == 0) {

                $homePage = get_option('page_on_front');
                $blogPage = get_option('page_for_posts');

                if (!$homePage) {
                    $url = home_url();
                } else {
                    $url = get_permalink($homePage);
                }

                $pages[$postID] = urldecode($url);

                if ($blogPage !== 0 && $blogPage !== '0' && $blogPage !== $homePage) {
                    $url = get_permalink($blogPage);
                }

                $pages[$postID] = urldecode($url);
            } else {
                $url = get_permalink($postID);
                $pages[$postID] = urldecode($url);
            }

            $url_key = $this->url_key_class->setup($url);
        } else {
            $pages[$postID] = urldecode($realUrl);
            $url_key = $this->url_key_class->setup($realUrl);
            $url = $realUrl;
        }

        if ($this->criticalExists()) {
            wp_send_json_success('Exists');
        }

        $url = rtrim($url, '?');
        $this->initCritical($postID, $url, $url_key, $type, $pages);
    }


    public function saveBenchmark($urlKey, $uuid)
    {

        $this->debugPageSpeed('start benchmark inside');

        $parsedData = [];
        $jobStatus = [];
        $critical_path = WPS_IC_CRITICAL . $urlKey . '/';
        $cache = new wps_ic_cache_integrations();

        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $stats = get_option(WPS_IC_TESTS);
        $attempt = 0;
        $psiPending = false;

        $this->debugPageSpeed(WPS_IC_PAGESPEED_RESULTS_HOME . $uuid);

        do {
            $results = wp_remote_get(WPS_IC_PAGESPEED_RESULTS_HOME . $uuid, [
                'headers' => ['user-agent' => WPS_IC_API_USERAGENT]
            ]);

            $this->debugPageSpeed(print_r($results,true));

            if (is_wp_error($results)) {
                $jobStatus['benchmark-failed'] = true;
                break;
            }

            $body = wp_remote_retrieve_body($results);
            $data = json_decode($body, true);

            $this->debugPageSpeed('----');
            $this->debugPageSpeed(print_r($data,true));

            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
                $jobStatus['benchmark-failed'] = true;
                break;
            }


            $psiStatus = isset($data['status']) ? (string) $data['status'] : 'complete';
            if ($psiStatus !== 'complete') {
                $jobStatus['benchmark-pending'] = $psiStatus;
                if ($attempt === 0 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_psi_poll', [$urlKey, $uuid])) {
                    wp_schedule_single_event(time() + 45, 'wpc_psi_poll', [$urlKey, $uuid]);
                }
                $psiPending = true;
                $jobStatus['benchmark-pending-rescheduled'] = true;
                break;
            }

            // Parse Desktop
            $parsedData['desktop']['before']['performanceScore'] = $data['desktop']['beforeScore'];
            $parsedData['desktop']['after']['performanceScore'] = $data['desktop']['afterScore'];

            $parsedData['desktop']['before']['pageSize'] = $data['desktop']['beforePageSize'];
            $parsedData['desktop']['after']['pageSize'] = $data['desktop']['afterPageSize'];

            $parsedData['desktop']['before']['requests'] = $data['desktop']['beforeRequests'];
            $parsedData['desktop']['after']['requests'] = $data['desktop']['afterRequests'];

            $parsedData['desktop']['before']['ttfb'] = $data['desktop']['beforeTTFB'];
            $parsedData['desktop']['after']['ttfb'] = $data['desktop']['afterTTFB'];

            // Parse Mobile
            $parsedData['mobile']['before']['performanceScore'] = $data['mobile']['beforeScore'];
            $parsedData['mobile']['after']['performanceScore'] = $data['mobile']['afterScore'];

            $parsedData['mobile']['before']['pageSize'] = $data['mobile']['beforePageSize'];
            $parsedData['mobile']['after']['pageSize'] = $data['mobile']['afterPageSize'];

            $parsedData['mobile']['before']['requests'] = $data['mobile']['beforeRequests'];
            $parsedData['mobile']['after']['requests'] = $data['mobile']['afterRequests'];

            $parsedData['mobile']['before']['ttfb'] = $data['mobile']['beforeTTFB'];
            $parsedData['mobile']['after']['ttfb'] = $data['mobile']['afterTTFB'];

            $this->debugPageSpeed(print_r($parsedData,true));

            // Check if parsedData was populated
            if (!empty($parsedData)) {
                $stats['home'] = $parsedData;
                update_option(WPS_IC_TESTS, $stats);
                $jobStatus['benchmark-success'] = true;
                delete_transient('wpc_initial_test');
                break;
            }

            // If parsedData is empty, re-poll via event — never sleep a worker
            if ($attempt === 0 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_psi_poll', [$urlKey, $uuid])) {
                wp_schedule_single_event(time() + 45, 'wpc_psi_poll', [$urlKey, $uuid]);
            }
            $jobStatus['benchmark-rescheduled'] = true;
            break;

        } while ($attempt <= 3);


        if (!$psiPending) {
            update_option(WPS_IC_LITE_GPS, ['result' => $parsedData, 'failed' => empty($parsedData), 'lastRun' => time()]);
        }
        return $jobStatus;
    }


    public function debugPageSpeed($message)
    {
        if (get_option('wps_ps_debug') == 'true') {
            $log_file = WPS_IC_LOG . 'pagespeed-log-' . date('d-m-Y') . '.txt';
            $time = current_time('mysql');

            if (!touch($log_file)) {
                error_log("Failed to create log file: $log_file");
            }

            $log = file_get_contents($log_file);
            $log .= '[' . $time . '] - ' . $message . "\r\n";
            file_put_contents($log_file, $log);
        }
    }

    public function saveLCP($urlKey, $LCP = array())
    {
        $jobStatus = [];
        $critical_path = WPS_IC_CRITICAL . $urlKey . '/';
        $cache = new wps_ic_cache_integrations();

        if (is_array($LCP)) {
            $json = $LCP;
        } else {
            $json = json_decode($LCP, true);
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }


        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('land-rx', (string) $urlKey, (string) $pageUrl, ['type' => (string) $type]);
        }

        $desktop = wp_remote_get($json['url']['desktop'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);
        $mobile = wp_remote_get($json['url']['mobile'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);

        // If fetching remote files is ERROR stop process
        if (is_wp_error($desktop)) {
            // No Desktop LCP
            $preloadsLcp = get_option('wps_ic_preloads');
            $preloadsLcp['lcp'] = '';
            update_option('wps_ic_preloads', $preloadsLcp);
            $jobStatus['lcp-mobile-fail'] = true;
        } else {
            $body = wp_remote_retrieve_body($desktop);
            $data = json_decode($body, true);
            $lcp = isset($data['lcp']) ? $data['lcp'] : [];
            $preloadsLcp = get_option('wps_ic_preloads');
            $preloadsLcp['lcp'] = $lcp;
            update_option('wps_ic_preloads', $preloadsLcp);
            $jobStatus['lcp-desktop-success'] = true;
        }

        // If fetching remote files is ERROR stop process
        if (is_wp_error($mobile)) {
            // No Mobile LCP
            $preloadsLcp = get_option('wps_ic_preloadsMobile');
            $preloadsLcp['lcp'] = '';
            update_option('wps_ic_preloadsMobile', $preloadsLcp);
            $jobStatus['lcp-mobile-fail'] = true;
        } else {
            $body = wp_remote_retrieve_body($mobile);
            $data = json_decode($body, true);
            $lcp = isset($data['lcp']) ? $data['lcp'] : [];
            $preloadsLcp = get_option('wps_ic_preloadsMobile');
            $preloadsLcp['lcp'] = $lcp;
            update_option('wps_ic_preloadsMobile', $preloadsLcp);
            $jobStatus['lcp-mobile-success'] = true;
        }


        $wpc_lcp_url = '';
        if (!empty($json['lcp_url'])) {
            $wpc_lcp_url = (string) $json['lcp_url'];
        } elseif (!empty($json['url']['lcp'])) {
            $wpc_lcp_url = (string) $json['url']['lcp'];
        } elseif (!empty($json['uuid']) && !empty($json['url']['desktop'])) {
            $wpc_lcp_url = dirname((string) $json['url']['desktop']) . '/' . (string) $json['uuid'] . '.lcp.json';
        }
        if ($wpc_lcp_url !== '') {
            $wpc_lcp_resp = wp_remote_get($wpc_lcp_url, ['headers' => ['user-agent' => WPS_IC_API_USERAGENT], 'timeout' => 5]);
            if (!is_wp_error($wpc_lcp_resp) && (int) wp_remote_retrieve_response_code($wpc_lcp_resp) === 200) {
                $wpc_lcp_body = wp_remote_retrieve_body($wpc_lcp_resp);
                if (is_string($wpc_lcp_body) && $wpc_lcp_body !== '' && json_decode($wpc_lcp_body) !== null) {
                    if (!is_dir($critical_path)) { wp_mkdir_p($critical_path); }
                    if (function_exists('wpc_lcp_write_preserve781')) {
                        wpc_lcp_write_preserve781($critical_path, $wpc_lcp_body);
                    } else {
                        file_put_contents($critical_path . 'lcp.json', $wpc_lcp_body);
                    }
                    $jobStatus['lcp-hint-saved'] = true;


                    if (function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()
                        && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                        function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'lcp-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'lcp-land']);
                    } elseif (class_exists('wps_ic_cache_integrations')) {
                        wps_ic_cache_integrations::purgeAll($urlKey, false, true, false);
                    }
                }
            }
        }

        return $jobStatus;
    }

    public function criticalExistsAjax($url = '')
    {

        if (!empty($url)) {
            $this->urlKey = $this->url_key_class->setup($url);
        }

        if (file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')) {
            return WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css';
        } else {
            return false;
        }
    }

    public function sendCriticalUrlGetAssets($url = '', $postID = 0)
    {
        global $post;
        $type = 'post_meta';

        if ($postID === 'home') {
            $url = home_url();
            $type = 'option';
        } elseif (!$postID || $postID == 0) {

            $homePage = get_option('page_on_front');
            $blogPage = get_option('page_for_posts');

            if (!$homePage) {
                $post['post_name'] = 'Home';
                $post = (object)$post;
                $url = home_url();
            } else {
                $post = get_post($homePage);
                $url = get_permalink($homePage);
            }

            if ($blogPage !== 0 && $blogPage !== '0' && $blogPage !== $homePage) {
                $post = get_post($blogPage);
                $url = get_permalink($blogPage);
            }
        } else {
            $post = get_post($postID);
            $url = get_permalink($postID);
        }


        $args = ['url' => $url];

        $wpc_interactive2 = function_exists('wp_doing_ajax') && wp_doing_ajax()
            && empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
            && function_exists('current_user_can') && current_user_can('manage_options');
        // v7.10.524 — SAME DEAD HOST, SECOND CALLER. .515 bounded and breakered the assets
        // call in criticalCss.php (v1) and I called it done; the CDN team pointed out v2 is
        // the file actually loaded. The timeout here was already sane (.508: 45s interactive /
        // 12s background) but without the breaker EVERY attempt re-pays it against a host that
        // black-holes — DNS resolves, the connect never completes. One shared breaker key, so
        // whichever file is loaded, one failure silences both for 15 minutes.
        if (get_transient('wpc_v1_assets_down515')) {
            return false;
        }
        $call = wp_remote_post(self::$API_ASSETS_URL, ['timeout' => (int) apply_filters('wpc_gen_dispatch_timeout', $wpc_interactive2 ? 45 : 12), 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (is_wp_error($call)) {
            set_transient('wpc_v1_assets_down515', 1, (int) apply_filters('wpc_v1_assets_breaker_s', 900));
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('v2-assets-unreachable', '', '', ['err' => substr((string) $call->get_error_message(), 0, 80)]);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($call);
        if (!empty($body)) {

            if ($type == 'post_meta') {
                update_post_meta($post->ID, 'wpc_critical_assets', $body);
            } else {
                update_option('wpc_critical_assets_home', $body);
            }

            return $body;
        } else {

            if ($type == 'post_meta') {
                update_post_meta($post->ID, 'wpc_critical_assets', 'unable');
            } else {
                update_option('wpc_critical_assets_home', 'unable');
            }

            return json_encode(['img' => 0, 'js' => 0, 'css' => 0]);
        }
    }

    public function generateCriticalAjax($sync = false)
    {


        if (function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active()) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-backoff-deferred', (string) $this->urlKey, '', ['path' => 'kick']);
            }
            return;
        }


        if (empty($_GET['forceCritical']) && function_exists('wpc_gen_landless_parked') && wpc_gen_landless_parked((string) $this->urlKey)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-parked-deferred', (string) $this->urlKey, '', ['path' => 'kick']);
            }
            return;
        }


        if (empty($_GET['forceCritical']) && function_exists('wpc_land_cooldown_active') && wpc_land_cooldown_active($this->urlKey)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-cooldown-deferred', (string) $this->urlKey, '', ['path' => 'kick']);
            }
            return;
        }


        $wpc_crit_here123b = false;
        if (defined('WPS_IC_CRITICAL') && !empty($this->urlKey)) {
            $wpc_cd123b = rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/';
            $wpc_crit_here123b = (@filesize($wpc_cd123b . 'critical_desktop.css') > 5) || (@filesize($wpc_cd123b . 'critical_mobile.css') > 5);
        }
        $wpc_gsp2 = (int) apply_filters('wpc_gen_global_spacing', 45);
        if ($wpc_gsp2 > 0 && $wpc_crit_here123b && apply_filters('wpc_gen_single_flight', true) && empty($_GET['forceCritical'])) {
            if (!add_option('wpc_gen_sf_global', time(), '', 'no')) {
                $wpc_gsf2 = (int) get_option('wpc_gen_sf_global');
                if ($wpc_gsf2 && (time() - $wpc_gsf2) < $wpc_gsp2) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('gen-deferred-global', (string) $this->urlKey, '', ['path' => 'kick', 'age' => time() - $wpc_gsf2]);
                    }
                    return;
                }
                update_option('wpc_gen_sf_global', time());
            }
        }


        if (apply_filters('wpc_gen_single_flight', true) && empty($_GET['forceCritical']) && !empty($this->urlKey)) {
            $wpc_ksf = 'wpc_gen_sf_' . $this->urlKey;
            if (!add_option($wpc_ksf, time(), '', 'no')) {
                $wpc_ksf_at = (int) get_option($wpc_ksf);
                if ($wpc_ksf_at && (time() - $wpc_ksf_at) < 120) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('gen-single-flight', (string) $this->urlKey, '', ['path' => 'kick', 'skip' => 1]);
                    }
                    return;
                }
                update_option($wpc_ksf, time());
            }
        }
        $args = ['url' => urldecode($this->serverRequest), 'version' => (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : (class_exists('wps_ic') ? wps_ic::$version : ''))];
        $wpc_opt151 = get_option(WPS_IC_OPTIONS);
        if (is_array($wpc_opt151) && !empty($wpc_opt151['api_key'])) {
            $args['apikey'] = (string) $wpc_opt151['api_key'];
        }
        // Operator "Pull Latest" (resync) → sync=1 tells the service to SKIP its template
        // cache and grind a genuinely fresh gen carrying every current-schema field
        // (ceiling, hints, verified-unique prescriptions). Threaded per-dispatch from the
        // redispatch lane — NEVER a shared transient (that leaked onto visitor kicks; the
        // param only reaches this call on the operator's own resync dispatch). (v7.10.357)
        if ($sync) {
            $args['sync'] = 1;
        }
        // v7.10.623 — the seam's detection-moment kick lands HERE: a marked key must get
        // its template-cache-busting generation on the FIRST kick, not wait for the next
        // visitor's page-load dispatch. Same helper, same 30min rate limit.
        if (function_exists('wpc_sanity_escalate622')) {
            $args = wpc_sanity_escalate622($args, urldecode($this->serverRequest));
        }


        $wpc_v3s2 = get_option(WPS_IC_SETTINGS);
        if (is_array($wpc_v3s2) && !empty($wpc_v3s2['delay-js-v2']) && $wpc_v3s2['delay-js-v2'] == '1'
            && (!isset($wpc_v3s2['delay-js-v3']) || $wpc_v3s2['delay-js-v3'] != '0')
            && apply_filters('wpc_delay_manifest_capability', true)) {
            $args['capabilities']    = ['delay_manifest' => 1, 'consolidated_callback' => 1, 'delay_inline' => 1, 'lcp_inline' => 1, 'manifest' => 1];
            $args['delay_manifest']  = '1';
        }


        $wpc_ucss_armed = false;
        if (function_exists('wpc_used_css_apply_demand') && !empty($this->urlKey)) {
            $wpc_ucss_armed = wpc_used_css_apply_demand($args, $this->urlKey);
        }
        // (Phase B) same tpl_key broadening as initCritical — send it whenever we have it, so the
        // service's observation cache covers delay/fonts-only sites, not just used-css ones.
        if (empty($args['tpl_key']) && !empty($this->urlKey) && function_exists('wpc_dispatch_tpl_key')
            && apply_filters('wpc_send_tpl_key_always', true)) {
            $wpc_dtk = wpc_dispatch_tpl_key($this->urlKey);
            if ($wpc_dtk !== '') { $args['tpl_key'] = $wpc_dtk; }
        }
        // (Phase B per-page cache) content-version — no $postID in the ajax path, so resolve the URL
        // to a post id inside the helper (url_to_postid → 0 for homepage/archive → omitted).
        if (function_exists('wpc_dispatch_post_mtime') && apply_filters('wpc_send_post_modified', true)) {
            $wpc_pm2 = wpc_dispatch_post_mtime(0, urldecode($this->serverRequest));
            if ($wpc_pm2 !== '') { $args['post_modified'] = $wpc_pm2; }
        }


        // 45s (to outwait a DB-degraded grind for the ack uuid) ONLY when a real admin clicked
        // and is watching — never on a warm loopback / render-kick / cron, where blocking 45s is
        // the contention we just saw; those get 12s and rely on the async DB-free HEAD-loop.
        // v7.10.508 — "is an admin watching?" cannot be inferred from wp_doing_ajax() + a capability.
        // wpc_repull_kick IS admin-ajax carrying the admin's cookie, so a BACKGROUND kick classified
        // as interactive and took the 45s BLOCKING branch. Receipt: an 8,839ms request with boot 139ms
        // and tpl 164ms, http_ms 8,565 and worst=crit-push.zapwp.net/generate:6020ms — the worker was
        // parked on HTTP, not computing. Purges make it worse because a purge triggers a repull kick.
        //
        // Intent is only knowable if the entry point DECLARES it, so reuse the explicit flag the
        // human-initiated paths already set (.496/.507): the Regenerate button and apiGenerateCritical.
        // Everything else — repull kicks, render kicks, warm loopbacks, cron — is background.
        $wpc_interactive = !empty($GLOBALS['wpc_gen_force496'])
            && function_exists('wp_doing_ajax') && wp_doing_ajax()
            && empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
            && function_exists('current_user_can') && current_user_can('manage_options');
        // Background dispatch must never park a worker for long: the ack is optional there because
        // the artifact lands via callback/webhook regardless (the dispatch stamp is written above).
        $wpc_dtimeout = (int) apply_filters('wpc_gen_dispatch_timeout', $wpc_interactive ? 45 : 5);
        // Dispatch stamp at POST time, not ack time — a held-connection gen that outlives
        // this timeout still lands via callback/webhook and must still measure (B7).
        if (!empty($this->urlKey) && defined('WPS_IC_CRITICAL') && function_exists('wpc_crit_meta_write')) {
            $wpc_sd218 = WPS_IC_CRITICAL . $this->urlKey . '/';
            if (!is_dir($wpc_sd218)) { @mkdir($wpc_sd218, 0777, true); }
            wpc_crit_meta_write($wpc_sd218 . 'dispatch_ts.txt', (string) time());
        }
        // Corpus rides THIS path too: of the two dispatch paths only initCritical attached
        // html/css, so every repull/kick/cron dispatch arrived corpus-less and the service fell
        // back to fetching our sheets from its ASN — CF walls that => css_stub/fetch_blocked.
        if (empty($args['html']) && apply_filters('wpc_push_corpus_ajax', true)) {
            $wpc_curl794 = !empty($args['url']) ? (string) $args['url'] : urldecode((string) $this->serverRequest);
            $wpc_h794 = $this->fetchCriticalCombineHtml(strtok($wpc_curl794, '?'));
            if ($wpc_h794) {
                $args['html'] = $wpc_h794;
                if (apply_filters('wpc_push_css_corpus', true)) {
                    $wpc_c794 = $this->fetchCriticalCombineCss($wpc_h794);
                    if ($wpc_c794 !== '') { $args['css'] = $wpc_c794; }
                }
            }
        }
        if (empty($args['fonts']) && function_exists('wpc_font_localizer_faces')) {
            $wpc_ff794 = wpc_font_localizer_faces();
            if (!empty($wpc_ff794)) { $args['fonts'] = $wpc_ff794; }
        }
        $wpc_gzb794 = false;
        if (!empty($args['css']) && function_exists('gzencode')) {
            $wpc_gzb794 = gzencode(wp_json_encode($args));
        }
        if ($wpc_gzb794 !== false && is_string($wpc_gzb794) && strlen($wpc_gzb794) < 10 * 1024 * 1024) {
            $call = wp_remote_post(self::$API_URL, [
                'timeout'    => $wpc_dtimeout,
                'body'       => $wpc_gzb794,
                'sslverify'  => false,
                'user-agent' => WPS_IC_API_USERAGENT,
                'headers'    => ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
            ]);
        } else {
            unset($args['css']);
            $call = wp_remote_post(self::$API_URL, ['timeout' => $wpc_dtimeout, 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        }

        $body = wp_remote_retrieve_body($call);

        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('gen-dispatch', (string) $this->urlKey, '', [
                'path'     => 'generateCriticalAjax',
                'code'     => is_wp_error($call) ? 0 : (int) wp_remote_retrieve_response_code($call),
                'len'      => strlen((string) $body),
                'head'     => substr((string) $body, 0, 60),
                'caps'     => isset($args['capabilities']) && is_array($args['capabilities']) ? implode(',', array_keys($args['capabilities'])) : '',
                'used_css' => $wpc_ucss_armed ? 1 : 0,
                'tpl'      => !empty($args['tpl_key']) ? 1 : 0,
            ]);
        }

        if (function_exists('wpc_gen_landless_note')) {
            wpc_gen_landless_note((string) $this->urlKey);
        }


        // UNREACHABLE backend (connect/DNS/SSL WP_Error — NOT a timeout) or a real 5xx backs off now;
        // the async timeout rides the status-poll / kick / repull recovery instead.
        if (function_exists('wpc_gen_note_failure')) {
            $wpc_dcode = is_wp_error($call) ? 0 : (int) wp_remote_retrieve_response_code($call);
            $wpc_is_timeout = false;
            if (is_wp_error($call)) {
                $wpc_emsg = strtolower((string) $call->get_error_message());
                $wpc_is_timeout = (strpos($wpc_emsg, 'timed out') !== false
                    || strpos($wpc_emsg, 'timeout') !== false
                    || strpos($wpc_emsg, 'too slow') !== false);
            }
            if ($wpc_dcode >= 500 || (is_wp_error($call) && !$wpc_is_timeout)) {
                // The wire fact (receipted 2026-08-06): a per-URL generation failure comes back
                // as HTTP 500 with a JSON body naming error_type (css_stub / fetch_blocked), and
                // a saturated edge can answer with a contract-less HTML 5xx. Neither is backend
                // death. Arm the SITE-WIDE breaker only when the body affirms it: JSON that
                // either says arm_backoff truthy or carries no refusal contract at all.
                $wpc_arm794 = true;
                if (!is_wp_error($call)) {
                    $wpc_jb794 = json_decode((string) $body, true);
                    if (!is_array($wpc_jb794)) {
                        $wpc_arm794 = false; // non-JSON 5xx (edge HTML 504): retryable, never arm
                    } elseif (array_key_exists('arm_backoff', $wpc_jb794)) {
                        $wpc_arm794 = !empty($wpc_jb794['arm_backoff']);
                    } elseif (!empty($wpc_jb794['error_type'])
                        && in_array((string) $wpc_jb794['error_type'], ['css_stub', 'fetch_blocked', 'server_busy'], true)) {
                        $wpc_arm794 = false; // per-URL gen failure riding a 500 status
                    }
                }
                if ($wpc_arm794) {
                    wpc_gen_note_failure('dispatch-' . ($wpc_dcode ?: 'wperr'));
                } elseif (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('dispatch-noarm', (string) $this->urlKey, '', [
                        'code' => $wpc_dcode,
                        'type' => is_array(json_decode((string) $body, true))
                            ? substr((string) (json_decode((string) $body, true)['error_type'] ?? ''), 0, 20) : 'non-json',
                    ]);
                }
            }
            // B5: a failed dispatch schedules its own retry per the service's retry_after
            // (bounded 60..3600; 500→300 default) — one lost packet must never cost an hour.
            if (($wpc_dcode >= 500 || $wpc_dcode === 429 || (is_wp_error($call) && !$wpc_is_timeout))
                && !empty($this->urlKey) && function_exists('wpc_pl_sched')
                && function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_crit_redispatch', [(string) $this->urlKey])) {
                $wpc_ra179 = is_wp_error($call) ? 0 : (int) wp_remote_retrieve_header($call, 'retry-after');
                if ($wpc_ra179 <= 0 && !is_wp_error($call)) {
                    // v2 spec: retry_after also rides the 500-body JSON today (pre fast-ack).
                    $wpc_rb179 = json_decode((string) wp_remote_retrieve_body($call), true);
                    if (is_array($wpc_rb179) && !empty($wpc_rb179['retry_after'])) {
                        $wpc_ra179 = (int) $wpc_rb179['retry_after'];
                    }
                }
                wpc_pl_sched(time() + min(3600, max(60, $wpc_ra179 ?: 300)), 'wpc_crit_redispatch', [(string) $this->urlKey]);
                wpc_spawn_cron();
            }
        }


        // A dispatch that got no usable ack (code=0/5xx, empty body) must NOT hold the full
        // 120s single-flight lock — an admin waiting on a fresh purge, or the land watchdog,
        // needs to retry within ~20s. Backdate the lock instead of clearing it (clearing would
        // let every visitor hammer a down backend); success clears it fully in saveCriticalCss.
        $wpc_dcode2 = is_wp_error($call) ? 0 : (int) wp_remote_retrieve_response_code($call);
        if ($wpc_interactive && !empty($this->urlKey) && ($wpc_dcode2 === 0 || $wpc_dcode2 >= 500) && strlen((string) $body) < 32) {
            $wpc_ksf2 = 'wpc_gen_sf_' . $this->urlKey;
            if (get_option($wpc_ksf2) !== false) {
                update_option($wpc_ksf2, time() - 100); // ~20s until the <120s gate releases
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-lock-shortened', (string) $this->urlKey, '', ['code' => $wpc_dcode2]);
            }
        }


        $body = trim((string) $body);
        // A dispatch ack (fresh or X-WPC-Debounced) names the service-known uuid — the poll
        // must track THAT one; a locally assumed uuid the service never minted polls forever.
        $wpc_ack218 = $body !== '' && $body[0] === '{' ? json_decode($body, true) : null;
        $wpc_srvu218 = is_array($wpc_ack218) && !empty($wpc_ack218['uuid'])
            ? preg_replace('/[^A-Za-z0-9-]/', '', (string) $wpc_ack218['uuid']) : '';
        if ($wpc_srvu218 !== '' && !empty($this->urlKey)) {
            set_transient('wpc_critical_uuid_' . $this->urlKey, $wpc_srvu218, 60 * 5);
            if (defined('WPS_IC_CRITICAL')) {
                $wpc_ud218 = WPS_IC_CRITICAL . $this->urlKey . '/';
                if (!is_dir($wpc_ud218)) {
                    @mkdir($wpc_ud218, 0777, true);
                }
                wpc_crit_meta_write($wpc_ud218 . 'uuid.txt', $wpc_srvu218);
                wpc_crit_meta_write($wpc_ud218 . 'dispatch_ts.txt', (string) time());
            }
            if ((string) wp_remote_retrieve_header($call, 'x-wpc-debounced') !== '' && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-debounced-repoint', (string) $this->urlKey, '', ['uuid' => substr($wpc_srvu218, 0, 8)]);
            }
        }
        if ($body !== '' && $body[0] !== '{'
            && preg_match('#([0-9a-f]{4})/([0-9a-f-]{16,})-(?:desktop|mobile)\.css#i', $body, $wpc_pm)) {
            $wpc_up  = $wpc_pm[1];
            $wpc_uid = $wpc_pm[2];
            $wpc_base = 'https://critical-css-mc.b-cdn.net/' . $wpc_up . '/' . $wpc_uid;
            $this->saveCriticalCss($this->urlKey, [
                'url'  => ['desktop' => $wpc_base . '-desktop.css', 'mobile' => $wpc_base . '-mobile.css'],
                'uuid' => $wpc_uid,
            ], 'meta', urldecode((string) $this->serverRequest));
        } elseif (!empty($body) && strlen($body) > 128) {
            $this->saveCriticalCss($this->urlKey, $body);
        }

        // B2: no sync land in the ack (202 fast-ack / timeout / still grinding) → the
        // dispatch owns its pickup; this lane is already a background worker.
        if (!empty($this->urlKey) && defined('WPS_IC_CRITICAL') && function_exists('wpc_crit_collector_arm')) {
            $wpc_cd218 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/';
            $wpc_landed218 = @filesize($wpc_cd218 . 'critical_desktop.css') > 64
                && trim((string) @file_get_contents($wpc_cd218 . 'land_uuid.txt')) !== ''
                && trim((string) @file_get_contents($wpc_cd218 . 'land_uuid.txt')) === trim((string) @file_get_contents($wpc_cd218 . 'uuid.txt'));
            if (!$wpc_landed218) {
                wpc_crit_collector_arm((string) $this->urlKey);
            }
        }
    }

    // DB-free storage pointer: resolves crit_uuid without any service DB when we hold no
    // uuid. The pointer is overwritten in place, so ?t= is a mandatory cache-bust. url_key
    // per the service spec = host (www folded) + path, no scheme, lowercased ("perkzilla.com/").
    private function fetchLatestPointerUuid($urlKey)
    {
        if (!function_exists('get_option') || !defined('WPS_IC_OPTIONS')) {
            return '';
        }
        $opts   = get_option(WPS_IC_OPTIONS);
        $apikey = is_array($opts) && !empty($opts['api_key']) ? (string) $opts['api_key'] : '';
        if ($apikey === '') {
            return '';
        }
        $u = defined('WPS_IC_CRITICAL') ? trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/url.txt')) : '';
        if ($u === '' && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey')) {
            $u = (string) wps_ic_url_key::getUrlFromKey($urlKey);
        }
        if ($u === '' && function_exists('home_url')) {
            // A8 law, pointer edition (staging.wpcompress.com /pricing receipt): the home
            // fallback ONLY for the home key — for any other key it resolved the HOMEPAGE
            // pointer and landed home crit into subpage dirs. Unresolvable key = no pull.
            if (ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/') === ltrim((string) $urlKey, '/')) {
                $u = home_url('/');
            } else {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('pointer-unresolvable-key', (string) $urlKey, '', []);
                }
                return '';
            }
        }
        // url.txt is stored SCHEME-LESS — parse_url without a scheme yields no host and
        // this lane silently died (brightvibes receipt: artifacts on the shelf, pointer never GET)
        if ($u !== '' && strpos($u, '://') === false) {
            $u = 'https://' . ltrim($u, '/');
        }
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        if ($host === '') {
            return '';
        }
        $path = (string) parse_url($u, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $canon = $host . $path;
        $ptr = 'https://critical-css-mc.b-cdn.net/latest/' . substr(sha1($apikey), 0, 16) . '/' . substr(sha1($canon), 0, 16) . '.json?t=' . time();
        $r = wp_remote_get($ptr, ['timeout' => 3, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            return '';
        }
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        if (!is_array($j)) {
            return '';
        }
        // v7.10.524 — capture the service's generator epoch floor. Two dials by the crit
        // team's design, and theirs is better than the single integer I proposed: CRIT_EPOCH
        // stamps every artifact, CRIT_EPOCH_MIN invalidates, and the floor ships at 0 so it is
        // inert. Bump the stamp, let natural regeneration carry it, THEN raise the floor —
        // collapsing them would invalidate the whole fleet in one step, and crit is ~82% of
        // their render cost. We only ever read the floor; we never invent one.
        if (isset($j['crit_epoch_min'])) {
            $wpc_fl524 = (int) $j['crit_epoch_min'];
            if ($wpc_fl524 !== (int) get_option('wpc_crit_epoch_min', 0)) {
                update_option('wpc_crit_epoch_min', $wpc_fl524, false);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-epoch-floor', (string) $urlKey, '', ['min' => $wpc_fl524]);
                }
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-pointer-resolve', (string) $urlKey, '', ['ready' => (int) ($j['ready'] ?? 0)]);
        }
        // §6 near_expiry rides the pointer: arm a regen (helper fail-opens when nothing
        // usable is on disk — the pull below proceeds regardless; expiry is a floor).
        if (!empty($j['near_expiry']) && function_exists('wpc_near_expiry_regen')) {
            wpc_near_expiry_regen((string) $urlKey);
        }
        // v7.20.19 — crit-push v3.198.68 answers expired:true for a ready row whose objects
        // aged out of the 14-day retention. The uuid is a corpse: pulling by it only spends
        // 404s, so ask for a regen and hand back nothing (the caller's no-uuid path is the
        // same one a never-generated page takes). Their retry_after paces the next look.
        if (!empty($j['expired'])) {
            if (function_exists('wpc_used_css_expired_dispatch19')) {
                wpc_used_css_expired_dispatch19((string) $urlKey, 'resolver-expired');
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-pointer-expired', (string) $urlKey, '', ['retry' => (int) ($j['retry_after'] ?? 0)]);
            }
            return '';
        }
        return preg_replace('/[^a-f0-9-]/i', '', (string) ($j['crit_uuid'] ?? ''));
    }

    // DB-free landing: artifact URLs are derivable from the uuid alone
    // (critical-css-mc.b-cdn.net/{uuid:0:4}/{uuid}-{device}.css), and saveCriticalCss pulls
    // them straight from Bunny CDN — no /status, no service DB. Immune to the admission-DB
    // outage class: if the gen finished its grind, the files exist regardless of DB state.
    public function pullDerivedArtifacts($urlKey = '', $uuid = '', $force = false)
    {
        if (empty($urlKey)) {
            $urlKey = (string) $this->urlKey;
        }
        if (empty($urlKey) || !defined('WPS_IC_CRITICAL')) {
            return false;
        }
        $cd = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
        // stale.txt = serve-stale copy on disk; a replacement pull must overwrite it.
        if (@is_file($cd . 'stale.txt')) {
            $force = true;
        }
        if (!$force && @filesize($cd . 'critical_desktop.css') > 64 && @filesize($cd . 'critical_mobile.css') > 64) {
            return true; // already landed
        }
        if (empty($uuid)) {
            $uuid = (string) get_transient('wpc_critical_uuid_' . $urlKey);
            if ($uuid === '') {
                // IDENTITY BELT: a disk uuid without this dir's own dispatch stamp is
                // storm-borrowed foreign state — never pull by it (initCritical twin).
                if (@is_readable($cd . 'dispatch_ts.txt')) {
                    $uuid = trim((string) @file_get_contents($cd . 'uuid.txt'));
                } elseif (@is_readable($cd . 'uuid.txt')) {
                    @unlink($cd . 'uuid.txt');
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('uuid-no-dispatch-stamp', (string) $urlKey, '', ['path' => 'pull']);
                    }
                }
            }
        }
        $uuid = preg_replace('/[^a-f0-9-]/i', '', (string) $uuid);
        if (strlen($uuid) < 8) {
            // No uuid in hand (census-miss / lost pointer) — resolve via the DB-free storage
            // pointer (/latest/{sha1(apikey)[:16]}/{sha1(url_key)[:16]}.json, v3.61.0+).
            $uuid = $this->fetchLatestPointerUuid($urlKey);
            if (strlen($uuid) < 8) {
                return false;
            }
        }
        // Forced pull of a uuid that already landed (and isn't stale-marked) is a no-op.
        if ($force && !@is_file($cd . 'stale.txt')
            && @filesize($cd . 'critical_desktop.css') > 64 && @filesize($cd . 'critical_mobile.css') > 64
            && trim((string) @file_get_contents($cd . 'land_uuid.txt')) === $uuid) {
            return true;
        }
        // Serve-stale regen intent with no newer ack (dispatch timeout ate the uuid): the
        // POINTER decides — a fresh publish (pointer ≠ landed) collects anyway; only when
        // the pointer still names the landed artifact is there truly nothing new to land.
        if (@is_file($cd . 'stale.txt') && trim((string) @file_get_contents($cd . 'land_uuid.txt')) === $uuid) {
            $wpc_p325 = $this->fetchLatestPointerUuid($urlKey);
            if ($wpc_p325 === '' || $wpc_p325 === $uuid) {
                // v7.10.682 — MOOT-STALE SELF-HEAL. The pointer CONFIRMS the landed artifact is
                // the latest published AND no dispatch is in flight: this stale mark can never be
                // satisfied (the rebuild-press outage shape: stale-marked, the follow-up dispatch
                // died, and every kick refused right here for ~70 minutes while pages rendered
                // critless). The landed artifact IS current — clear the mark so serving resumes,
                // and arm ONE redispatch so the regen intent still lands fresh in the background.
                if ($wpc_p325 === $uuid
                    && (time() - (int) @file_get_contents($cd . 'dispatch_ts.txt')) > (int) apply_filters('wpc_stale_moot_after_s', 600)) {
                    @unlink($cd . 'stale.txt');
                    if (function_exists('wp_next_scheduled') && function_exists('wpc_pl_sched')
                        && !wp_next_scheduled('wpc_crit_redispatch', [(string) $urlKey])) {
                        wpc_pl_sched(time() + 30, 'wpc_crit_redispatch', [(string) $urlKey]);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('stale-moot-heal', (string) $urlKey, '', ['uuid' => substr($uuid, 0, 8)]);
                    }
                    return true;
                }
                return false;
            }
            $uuid = $wpc_p325;
        }
        // NOT backoff-gated: these are CDN GETs (the artifacts' shelf), not backend calls —
        // the breaker stops dispatch, never pickup (brightvibes receipt: artifacts waited
        // on the shelf while a failed re-gen's backoff blocked collection)
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return false;
        }
        $base = 'https://critical-css-mc.b-cdn.net/' . substr($uuid, 0, 4) . '/' . $uuid;
        // HEAD first — cheap existence probe while the gen may still be grinding.
        // ?t= cache-bust throughout (joint spec v2 S3): Bunny edge may negative-cache a
        // premature 404, and a repeat-probing collector would otherwise never see the 200.
        $wpc_cb179 = '?t=' . time();
        $head = wp_remote_head($base . '-mobile.css' . $wpc_cb179, ['timeout' => 3, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (is_wp_error($head) || (int) wp_remote_retrieve_response_code($head) !== 200) {
            // A2 (incident report 2026-07-20): a held uuid can be EXPIRED server-side while a
            // newer publish exists — the pointer twin is authoritative; a dead uuid must
            // never block collection (staging b8ef631a loop: dead uuid rehydrated forever).
            $wpc_ptr179 = $this->fetchLatestPointerUuid($urlKey);
            if ($wpc_ptr179 === '' || $wpc_ptr179 === $uuid) {
                return false;
            }
            $base = 'https://critical-css-mc.b-cdn.net/' . substr($wpc_ptr179, 0, 4) . '/' . $wpc_ptr179;
            $head = wp_remote_head($base . '-mobile.css' . $wpc_cb179, ['timeout' => 3, 'user-agent' => WPS_IC_API_USERAGENT]);
            if (is_wp_error($head) || (int) wp_remote_retrieve_response_code($head) !== 200) {
                return false;
            }
            $uuid = $wpc_ptr179;
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-db-free-pull', (string) $urlKey, '', ['uuid' => substr($uuid, 0, 8)]);
        }
        $this->saveCriticalCss($urlKey, [
            'url'  => ['desktop' => $base . '-desktop.css' . $wpc_cb179, 'mobile' => $base . '-mobile.css' . $wpc_cb179],
            'uuid' => $uuid,
        ], 'meta');
        return @filesize($cd . 'critical_desktop.css') > 64;
    }

    public function saveCriticalCss($urlKey, $CSS, $type = 'meta', $pageUrl = '')
    {


        if (function_exists('delete_option') && !empty($urlKey)) {
            delete_option('wpc_gen_sf_' . $urlKey);
        }
        $jobStatus = [];
        $critical_path = WPS_IC_CRITICAL . $urlKey . '/';
        $cache = new wps_ic_cache_integrations();


        if (!empty($pageUrl) && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'persistKeyUrl')) {
            wps_ic_url_key::persistKeyUrl($urlKey, $pageUrl);
        }

        if (is_array($CSS)) {
            $json = $CSS;
        } else {
            $json = json_decode($CSS, true);
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        if (!empty($json['server'])) {
            echo $json['server'];
        }

        if (!empty($json['hostname'])) {
            echo $json['hostname'];
        }

        $desktop = wp_remote_get($json['url']['desktop'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);

        $mobile = wp_remote_get($json['url']['mobile'], ['headers' => ['user-agent' => WPS_IC_API_USERAGENT]]);


        $wpc_land_fail = function ($why, $detail) use ($urlKey, $pageUrl) {
            // A3 (incident report 2026-07-20): a per-URL land failure (404/wrong-type = THIS
            // artifact) must never arm the SITE-WIDE backoff — one stale-uuid page halted
            // every URL's generation for the whole staging site. Only 'fetch' (CDN itself
            // unreachable — genuinely global) escalates globally; the per-URL landless park
            // and the scheduled repull below already own the per-page consequence.
            if ($why === 'fetch' && function_exists('wpc_gen_note_failure')) {
                wpc_gen_note_failure('land-' . $why);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-fail-' . $why, (string) $urlKey, (string) $pageUrl, ['d' => substr((string) $detail, 0, 120)]);
            }
            if (!empty($urlKey) && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                wpc_pl_sched(time() + 60, 'wpc_lcp_repull', [$urlKey, 1]);
            }
        };
        if (is_wp_error($desktop) || is_wp_error($mobile)) {
            $wpc_land_fail('fetch', is_wp_error($desktop) ? $desktop->get_error_message() : $mobile->get_error_message());
            return ['critical-failed' => array('desktop' => is_wp_error($desktop), 'mobile' => is_wp_error($mobile))];
        }

        $response_code = wp_remote_retrieve_response_code($desktop);
        if ($response_code !== 200) {
            $wpc_land_fail('http', 'desktop=' . $response_code);
            return ['critical-failed' => array('desktop' => '404')];
        }

        $response_code = wp_remote_retrieve_response_code($mobile);
        if ($response_code !== 200) {
            $wpc_land_fail('http', 'mobile=' . $response_code);
            return ['critical-failed' => array('mobile' => '404')];
        }

        $content_type = wp_remote_retrieve_header( $desktop, 'content-type' );
        if ( strpos( $content_type, 'text/css' ) === false ) {
            $wpc_land_fail('ctype', 'desktop=' . $content_type);
            return ['critical-failed' => array('desktop' => 'not-css')];
        }

        $content_type = wp_remote_retrieve_header( $mobile, 'content-type' );
        if ( strpos( $content_type, 'text/css' ) === false ) {
            $wpc_land_fail('ctype', 'mobile=' . $content_type);
            return ['critical-failed' => array('desktop' => 'not-css')];
        }


        if (!file_exists($critical_path)) {
            mkdir($critical_path, 0777, true);
        }
        foreach ([['critical_desktop.css', wp_remote_retrieve_body($desktop)], ['critical_mobile.css', wp_remote_retrieve_body($mobile)]] as $wpc_w) {
            $wpc_tmp = $critical_path . $wpc_w[0] . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 6);
            if (wpc_crit_meta_write($wpc_tmp, $wpc_w[1]) !== false) {
                if (!@rename($wpc_tmp, $critical_path . $wpc_w[0])) {
                    @unlink($wpc_tmp);
                }
            }
        }

        // The render PREFERS critical_combined.css, but only the callback JSON path ever
        // wrote it — pull-landed sites served a stale combined forever (hawkeye receipt:
        // fresh device files on disk, page still inlining the old combined). Combined must
        // never outlive the device files it wraps: refresh from the shelf when published,
        // remove when not — the two-blob wrap serves as the fallback.
        $wpc_cmb338 = '';
        if (!empty($json['url']['desktop']) && is_string($json['url']['desktop'])) {
            $wpc_cmu338 = str_replace('-desktop.css', '-combined.css', (string) $json['url']['desktop']);
            if ($wpc_cmu338 !== (string) $json['url']['desktop']) {
                $wpc_cmr338 = wp_remote_get($wpc_cmu338 . (strpos($wpc_cmu338, '?') === false ? '?' : '&') . 't=' . time(),
                    ['headers' => ['user-agent' => WPS_IC_API_USERAGENT], 'timeout' => 6]);
                if (!is_wp_error($wpc_cmr338) && (int) wp_remote_retrieve_response_code($wpc_cmr338) === 200) {
                    $wpc_cmb338 = (string) wp_remote_retrieve_body($wpc_cmr338);
                }
            }
        }
        if (strlen($wpc_cmb338) > 1024 && stripos($wpc_cmb338, '@media') !== false
            && stripos($wpc_cmb338, '<script') === false && stripos($wpc_cmb338, '</style') === false) {
            $wpc_cmt338 = $critical_path . 'critical_combined.css.tmp.' . getmypid();
            if (wpc_crit_meta_write($wpc_cmt338, $wpc_cmb338) !== false && !@rename($wpc_cmt338, $critical_path . 'critical_combined.css')) {
                @unlink($wpc_cmt338);
            }
        } else {
            @unlink($critical_path . 'critical_combined.css');
        }


        if (file_exists($critical_path . 'lcp_url.txt'))   { @unlink($critical_path . 'lcp_url.txt'); }
        if (file_exists($critical_path . 'lcp_src.txt'))   { @unlink($critical_path . 'lcp_src.txt'); }
        if (file_exists($critical_path . 'lcp_heal.json')) { @unlink($critical_path . 'lcp_heal.json'); }


        if (file_exists($critical_path . 'delay_url.txt')) { @unlink($critical_path . 'delay_url.txt'); }
        if (file_exists($critical_path . 'fonts_url.txt')) { @unlink($critical_path . 'fonts_url.txt'); }
        $wpc_uid_fresh = !empty($json['uuid']) ? (string) $json['uuid'] : (string) get_transient('wpc_critical_uuid_' . $urlKey);
        if ($wpc_uid_fresh !== '') { wpc_crit_meta_write($critical_path . 'uuid.txt', preg_replace('/[^A-Za-z0-9-]/', '', $wpc_uid_fresh)); }


        if (file_exists(WPS_IC_COMBINE . $urlKey)) {
            $files = scandir(WPS_IC_COMBINE . $urlKey);
            if (!empty($files)) {
                foreach ($files as $file) {
                    if ($file != "." && $file != "..") {
                        $subdir = WPS_IC_COMBINE . $urlKey . "/" . $file;
                        if (is_dir($subdir) && strpos($file, "criticalCombine") !== false) {
                            $this->removeDirectory($subdir);
                        }
                    }
                }
            }
        }

        // Check if file really exists and file size is bigger than 5
        // v7.10.554 — a LANDING clears the landless counter, and one variant is a landing.
        // The clear used to sit inside the desktop-AND-mobile pair check, so a desktop-only gen
        // (or a racing mobile write) left gen_fails incrementing forever despite success:
        // receipted as n:2 on the flagship while crit-land fired. The counter asks "did this
        // dispatch produce anything?" - the pair check answers "did it produce both?".
        if (function_exists('wpc_gen_landless_clear')
            && ((@filesize($critical_path . 'critical_desktop.css') > 5)
                || (@filesize($critical_path . 'critical_mobile.css') > 5))) {
            wpc_gen_landless_clear((string) $urlKey);
        }
        if (file_exists($critical_path . 'critical_desktop.css') && filesize($critical_path . 'critical_desktop.css') > 5) {
            if (file_exists($critical_path . 'critical_mobile.css') && filesize($critical_path . 'critical_mobile.css') > 5) {


                @unlink($critical_path . 'stale.txt');

                if (function_exists('wpc_gen_landless_clear')) {
                    wpc_gen_landless_clear((string) $urlKey);
                }
                if ($type == 'meta') {
                    update_post_meta(sanitize_title($urlKey), 'wpc_critical_css', $critical_path . 'critical.css');
                }
                // Dropped the 'wps_critical_css_<key>' option write: autoload=yes, one
                // permanent row per URL, zero readers. Purge Critical CSS cleans old rows.

                $jobStatus['critical-css'] = 'success';

                if (function_exists('wpc_gen_note_success')) {
                    wpc_gen_note_success();
                }

                if (function_exists('wpc_land_cooldown_stamp')) {
                    wpc_land_cooldown_stamp($urlKey);
                }

                // B7 land receipt: dispatch→land seconds is the SLO's plugin half.
                // land_uuid.txt = what actually landed (uuid.txt is overwritten at dispatch,
                // so it cannot serve as the landed marker — webhook dedupe reads this).
                $wpc_dts179 = (int) @file_get_contents($critical_path . 'dispatch_ts.txt');
                wpc_crit_meta_write($critical_path . 'land_ts.txt', (string) time());
                // The epoch travels INSIDE the css (/*wpc-epoch:N*/, v3.116.1 — it survives the
                // combiner). Persist it next to the artifact so freshness is a property of the
                // artifact itself, not of anything we can lose. Absent = pre-epoch gen = 0.
                $wpc_ep524 = 0;
                foreach (['critical_desktop.css', 'critical_mobile.css', 'critical_combined.css'] as $wpc_ef524) {
                    $wpc_eh524 = (string) @file_get_contents($critical_path . $wpc_ef524, false, null, 0, 128);
                    if ($wpc_eh524 !== '' && preg_match('~/\*wpc-epoch:(\d+)\*/~', $wpc_eh524, $wpc_em524)) {
                        $wpc_ep524 = (int) $wpc_em524[1];
                        break;
                    }
                }
                wpc_crit_meta_write($critical_path . 'crit_epoch.txt', (string) $wpc_ep524);
                if ($wpc_uid_fresh !== '') {
                    wpc_crit_meta_write($critical_path . 'land_uuid.txt', preg_replace('/[^A-Za-z0-9-]/', '', $wpc_uid_fresh));
                }
                if ($wpc_dts179 > 0 && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-land-latency', (string) $urlKey, (string) $pageUrl, ['s' => max(0, time() - $wpc_dts179)]);
                }
                // (inv2) fresh artifact landed → drop this URL's fingerprint keys so the next
                // render adopts against the NEW crit. No-op while the engine is off.
                if (function_exists('wpc_inv2_on_land')) {
                    wpc_inv2_on_land($urlKey);
                }


                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('land-saved', (string) $urlKey, (string) $pageUrl, []);
                }
                if (function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()
                    && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                    function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, $pageUrl, 'crit-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, $pageUrl, ['context' => 'crit-land']);
                } else {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('land-purge-legacy', (string) $urlKey, (string) $pageUrl, []);
                    }
                    $cache::purgeAll($urlKey, false, true, false);
                }


                $wpc_lcp_url_stash = '';
                if (!empty($json['lcp_url']))        { $wpc_lcp_url_stash = (string) $json['lcp_url']; }
                elseif (!empty($json['url']['lcp']))  { $wpc_lcp_url_stash = (string) $json['url']['lcp']; }


                if (!empty($json['delay_url']) && is_string($json['delay_url'])) {
                    wpc_crit_meta_write($critical_path . 'delay_url.txt', trim($json['delay_url']));


                    if (!@is_readable($critical_path . 'delay.json')) {
                        $wpc_dresp = wp_remote_get(trim($json['delay_url']), ['headers' => ['user-agent' => WPS_IC_API_USERAGENT], 'timeout' => 5]);
                        $wpc_dbody = (!is_wp_error($wpc_dresp) && (int) wp_remote_retrieve_response_code($wpc_dresp) === 200) ? wp_remote_retrieve_body($wpc_dresp) : '';
                        if (is_string($wpc_dbody) && $wpc_dbody !== '' && is_array(json_decode($wpc_dbody, true))) {
                            wpc_crit_meta_write($critical_path . 'delay.json', $wpc_dbody);


                            delete_option('wpc_delay_v3_manifest_off');
                            delete_option('wpc_delay_v3_promoted');
                            if (function_exists('wpc_delay_aggr_rearm')) {
                                wpc_delay_aggr_rearm();
                            }
                        } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                            && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                            wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
                        }
                    }
                }


                // AUTO-100 §4: prescriptions ride the land. URL meta from the callback when
                // present (else /v2/latest artifacts{} lands it); fetch + poll-#2 arm in one
                // helper — the +45s repull leg is the miss backstop.
                if (!empty($json['prescriptions_url']) && is_string($json['prescriptions_url'])
                    && preg_match('#^https?://#i', trim($json['prescriptions_url']))) {
                    wpc_crit_meta_write($critical_path . 'prescriptions_url.txt', trim($json['prescriptions_url']));
                }
                if (function_exists('wpc_presc_on_land')) {
                    wpc_presc_on_land($urlKey);
                }


                // .470: record the echo BEFORE the pointer test — "asked and got nothing" is
                // exactly the case with no used_css_url, so gating on one loses the other.
                if (function_exists('wpc_used_css_echo_note')) {
                    wpc_used_css_echo_note('callback', $json);
                }
                if (!empty($json['used_css_url']) && is_string($json['used_css_url'])
                    && !empty($json['tpl_key']) && function_exists('wpc_used_css_key_valid')
                    && wpc_used_css_key_valid((string) $json['tpl_key'])) {
                    wpc_crit_meta_write($critical_path . 'used_css_url.txt', trim($json['used_css_url']));
                    wpc_crit_meta_write($critical_path . 'used_tpl.txt', (string) $json['tpl_key']);


                    if (function_exists('wpc_r2_on_artifact_land')) { wpc_r2_on_artifact_land(); }


                    if (function_exists('wpc_autopurge_on_land')) { wpc_autopurge_on_land($critical_path); }


                    if (!empty($json['used_css_sheets']) && is_array($json['used_css_sheets']) && function_exists('wpc_used_css_store_sheets')) {
                        wpc_used_css_store_sheets((string) $json['tpl_key'], $json['used_css_sheets']);
                    }
                    if (!empty($json['used_css_sheets_url']) && is_string($json['used_css_sheets_url'])) {
                        wpc_crit_meta_write($critical_path . 'used_css_sheets_url.txt', trim($json['used_css_sheets_url']));
                    }
                    if (!empty($json['crit_combined_url']) && is_string($json['crit_combined_url'])) {
                        wpc_crit_meta_write($critical_path . 'crit_combined_url.txt', trim($json['crit_combined_url']));
                        @unlink($critical_path . 'crit_combined_src.txt');
                    }
                    if (!empty($json['crit_combined']) && is_string($json['crit_combined'])
                        && strlen($json['crit_combined']) > 1024 && stripos($json['crit_combined'], '@media') !== false
                        && stripos($json['crit_combined'], '<script') === false && stripos($json['crit_combined'], '</style') === false) {
                        @file_put_contents($critical_path . 'critical_combined.css', (string) $json['crit_combined'], LOCK_EX);
                    }


                    foreach (['mobile', 'desktop'] as $wpc_dv18c) {
                        $wpc_k18c = 'used_css_' . $wpc_dv18c . '_url';
                        if (!empty($json[$wpc_k18c]) && is_string($json[$wpc_k18c])) {
                            wpc_crit_meta_write($critical_path . $wpc_k18c . '.txt', trim($json[$wpc_k18c]));
                        }
                    }


                    $wpc_ucss_set36 = get_option(WPS_IC_SETTINGS);
                    $wpc_ucss_on36  = is_array($wpc_ucss_set36) && !empty($wpc_ucss_set36['used-css']) && $wpc_ucss_set36['used-css'] == '1';
                    if ($wpc_ucss_on36 && function_exists('wpc_used_css_fetch')
                        && wpc_used_css_fetch(trim($json['used_css_url']), (string) $json['tpl_key'])) {


                        $wpc_us51 = get_option(WPS_IC_SETTINGS);
                        if (is_array($wpc_us51) && !empty($wpc_us51['used-css']) && $wpc_us51['used-css'] == '1'
                            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                            // Hit-rate law (.325): used.css is tpl-keyed — purge that template's
                            // pages only; the site-wide wipe is the bounded fallback.
                            if (!function_exists('wpc_used_css_scoped_purge')
                                || !wpc_used_css_scoped_purge((string) $json['tpl_key'])) {
                                try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                            }
                        }
                    } elseif (function_exists('wpc_used_css_fetch')
                        && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                        && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {

                        wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
                    }
                }
                if ($wpc_lcp_url_stash !== '') {
                    wpc_crit_meta_write($critical_path . 'lcp_url.txt', $wpc_lcp_url_stash);

                    wpc_crit_meta_write($critical_path . 'lcp_src.txt', !empty($json['lcp_src']) ? (string) $json['lcp_src'] : 'unknown');
                }


                $wpc_pu = !empty($json['uuid']) ? (string) $json['uuid'] : (string) get_transient('wpc_critical_uuid_' . $urlKey);
                if ($wpc_pu !== '' && ($wpc_lcp_url_stash === '' || (empty($json['fonts']) && empty($json['fonts_url'])))
                    && defined('WPS_IC_CRITICAL_API_URL') && apply_filters('wpc_status_poll_rescue', true)) {
                    $wpc_su  = str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=' . urlencode($wpc_pu);
                    $wpc_sr  = wp_remote_get($wpc_su, ['timeout' => 5]);
                    if (!is_wp_error($wpc_sr) && (int) wp_remote_retrieve_response_code($wpc_sr) === 200) {
                        $wpc_sd = json_decode((string) wp_remote_retrieve_body($wpc_sr), true);
                        if (is_array($wpc_sd)) {
                            if ($wpc_lcp_url_stash === '' && !empty($wpc_sd['lcp_url'])) {
                                wpc_crit_meta_write($critical_path . 'lcp_url.txt', trim((string) $wpc_sd['lcp_url']));
                                wpc_crit_meta_write($critical_path . 'lcp_src.txt', 'status-poll');
                                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                                    && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                                    wpc_pl_sched(time() + 30, 'wpc_lcp_repull', [$urlKey, 1]);
                                }
                            }
                            if (empty($json['fonts']) && !empty($wpc_sd['fonts_url']) && function_exists('wpc_consume_fonts_artifact')) {
                                wpc_crit_meta_write($critical_path . 'fonts_url.txt', trim((string) $wpc_sd['fonts_url']));
                                $wpc_ff = wp_remote_get((string) $wpc_sd['fonts_url'], ['timeout' => 6]);
                                if (!is_wp_error($wpc_ff) && (int) wp_remote_retrieve_response_code($wpc_ff) === 200) {
                                    $wpc_fj = json_decode((string) wp_remote_retrieve_body($wpc_ff), true);
                                    $wpc_fa = (is_array($wpc_fj) && !empty($wpc_fj['fonts']) && is_array($wpc_fj['fonts'])) ? $wpc_fj['fonts'] : (is_array($wpc_fj) ? $wpc_fj : []);
                                    if (!empty($wpc_fa)) { wpc_consume_fonts_artifact($wpc_fa, $urlKey); }
                                }
                            }

                            if (!empty($wpc_sd['delay_url']) && !@is_readable($critical_path . 'delay.json')) {
                                wpc_crit_meta_write($critical_path . 'delay_url.txt', trim((string) $wpc_sd['delay_url']));
                            }
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('status-poll-rescue', $urlKey, '', ['lcp' => !empty($wpc_sd['lcp_url']) ? 1 : 0, 'fonts' => !empty($wpc_sd['fonts_url']) ? 1 : 0]);
                            }


                            if (apply_filters('wpc_sync_complete_land', true) && function_exists('wpc_lcp_repull_handler')
                                && (!@is_readable($critical_path . 'lcp.json') || !@is_readable($critical_path . 'delay.json') || !@is_readable($critical_path . 'font-subsets.css'))) {
                                wpc_lcp_repull_handler($urlKey, 1);
                            }
                        }
                    }
                }
            }
        }

        return $jobStatus;
    }

    public static function removeDirectory($path)
    {
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }

}

// PSI results poll — bounded event chain replacing the in-request sleep(30) loop
add_action('wpc_psi_poll', function ($urlKey, $uuid) {
    $wpc_pn = (int) get_transient('wpc_psi_poll_n_' . md5((string) $uuid));
    if ($wpc_pn >= 4 || !class_exists('wps_criticalCss')) {
        return;
    }
    set_transient('wpc_psi_poll_n_' . md5((string) $uuid), $wpc_pn + 1, HOUR_IN_SECONDS);
    try {
        (new wps_criticalCss())->saveBenchmark((string) $urlKey, (string) $uuid);
    } catch (\Throwable $e) {
    }
}, 10, 2);
