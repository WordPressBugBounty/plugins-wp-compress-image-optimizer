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
            return wpc_fs_put($path, (string) $value) !== false;
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
        $urlFromRequest = empty($url);
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
            if ($urlFromRequest
                && strpos($url, '?') !== false
                && !isset($_GET['wpc_nocrit'])
                && !(isset($_GET['crit']) && (string) $_GET['crit'] === '0')
                && apply_filters('wpc_unknown_param_crit', true)
                && defined('WPS_IC_CRITICAL')
                && !@file_exists(WPS_IC_CRITICAL . $this->urlKey . '/critical_desktop.css')) {
                parse_str((string) substr((string) strstr($url, '?'), 1), $queryParams);
                $contentParamDenylist = apply_filters('wpc_content_param_denylist', ['p', 'page_id', 's', 'cat', 'tag', 'm', 'paged', 'attachment_id', 'preview', 'preview_id', 'preview_nonce', 'elementor-preview', 'lang', 'product', 'post_type', 'name', 'author', 'currency', 'add-to-cart', 'orderby', 'min_price', 'max_price']);
                // A parameter WordPress itself reads as a content selector (pagename, category_name,
                // attachment, year ...) renders ANOTHER page under this path, so it never borrows:
                // /?pagename=sitemap answers 200 with the sitemap page, and with the homepage's
                // folder borrowed that page was painted and judged with the homepage's crit
                // (hawkeye.design, 2026-09-24). The list is WordPress's own, with every plugin's
                // additions.
                $contentQueryVars = (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && !empty($GLOBALS['wp']->public_query_vars))
                    ? (array) $GLOBALS['wp']->public_query_vars : [];
                $paramsSafe = !empty($queryParams);
                foreach (array_keys((array) $queryParams) as $paramKey) {
                    if (in_array(strtolower((string) $paramKey), (array) $contentParamDenylist, true)
                        || in_array((string) $paramKey, $contentQueryVars, true)
                        || stripos((string) $paramKey, 'preview') !== false
                        || stripos((string) $paramKey, 'filter') === 0) {
                        $paramsSafe = false;
                        break;
                    }
                }
                if ($paramsSafe) {
                    $canonicalKey = ltrim((string) (new wps_ic_url_key())->setup((string) strtok($url, '?')), '/');
                    if ($canonicalKey !== '' && $canonicalKey !== $this->urlKey
                        && @file_exists(WPS_IC_CRITICAL . $canonicalKey . '/critical_desktop.css')) {
                        $this->urlKey = $canonicalKey;
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

    public function generateCriticalCSS($postID = 0)
    {

        // The branch below resolves the front page for 'home' / falsy / 0, so the method must
        // not be guarded on a non-empty $postID: 0 is its own default argument.
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
            $this->initCritical($postID, $url, $url_key, 'meta');
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
        $epochMin = (int) get_option('wpc_crit_epoch_min', 0);
        if ($epochMin > 0 && defined('WPS_IC_CRITICAL')) {
            $epochFile = WPS_IC_CRITICAL . $this->urlKey . '/crit_epoch.txt';
            $artifactEpoch = @is_file($epochFile) ? (int) trim((string) @file_get_contents($epochFile)) : 0;
            if ($artifactEpoch < $epochMin) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-epoch-stale', (string) $this->urlKey, '', [
                        'have' => $artifactEpoch, 'min' => $epochMin,
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

    public function initCritical($postID, $url, $url_key, $type, $timeout = 120)
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
            $wpc_settings = get_option(WPS_IC_SETTINGS);
            $wpc_crit_enabled = is_array($wpc_settings) && !empty($wpc_settings['critical']['css'])
                && $wpc_settings['critical']['css'] == '1';
            if (!$wpc_crit_enabled) {
                $wpc_excludes = get_option('wpc-excludes');
                $wpc_page_id = ($postID === 'home' || empty($postID)) ? 'home' : $postID;
                if (is_array($wpc_excludes)
                    && isset($wpc_excludes['page_excludes'][$wpc_page_id]['critical_css'])
                    && $wpc_excludes['page_excludes'][$wpc_page_id]['critical_css'] == '1') {
                    $wpc_crit_enabled = true;
                }
            }
            if (!$wpc_crit_enabled) {
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

        if (method_exists('wps_ic_url_key', 'persistKeyUrl')) {
            wps_ic_url_key::persistKeyUrl($url_key, $url);
        }

        // Poll /status for any in-flight request for this URL
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
        $wpc_background_lane = (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_CRON') && DOING_CRON)
            || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']);
        if ($pendingUuid && !$wpc_background_lane) {
            // Visitor renders carry ZERO artifact HTTP; background lanes own the poll.
            // Site-wide kick budget: post-update every URL is pending — per-URL gates alone
            // fan one loopback per URL into an FPM stampede.
            if (function_exists('wpc_pipeline_admission_ok') && !wpc_pipeline_admission_ok()) {
                $pendingUuid = false;
            } elseif (!get_transient('wpc_stpoll_' . md5($url_key)) && !get_transient('wpc_kick_budget30')) {
                set_transient('wpc_kick_budget30', 1, 30);
                set_transient('wpc_stpoll_' . md5($url_key), 1, 60);
                if (function_exists('wpc_kick_once_per_request')) {
                    wpc_kick_once_per_request($url_key, 'pending-poll');
                }
            }
            $pendingUuid = false;
        }

        if ($pendingUuid) {
            $statusUrl = wpc_crit_service_read_url('/status', ['uuid' => (string) $pendingUuid], (string) $url_key);
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
                            'via'     => 'status',
                            'epoch'   => isset($data['epoch']) ? $data['epoch'] : null,

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
                                if ($wpc_background_lane) {
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

                        // The land decided whether the page's bytes changed (saveCriticalCss): a
                        // re-land of the same crit, or one it refused, purges nothing here either.
                        $landLeftServedAlone = is_array($saveResult) && isset($saveResult['purged']) && !$saveResult['purged'];

                        if ($landLeftServedAlone) {
                            // The copies already serve these bytes.
                        } elseif (function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()
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
                            wpc_purge_foreign_caches($pageUrlKey, 'crit-v2');
                        }

                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                        return false;
                    }

                    // A generation the service answers as removed, refused, unknown or failed will
                    // never land (wpc_gen_status_gone reads the answer): it is forgotten once (wpc_gen_forget_gone: uuid.txt too, or the disk
                    // fallback above re-reads it and every later ask polls it again) and nothing
                    // is sent for it here. An automatic ask stops: the page's next real visit asks
                    // afresh. It used to dispatch again in the same call, and the not-found branch
                    // kicked as well, so a never-made uuid read as superseded fed the next
                    // never-made uuid (staging, 2026-09-27). A click still dispatches below.
                    $goneAnswer = function_exists('wpc_gen_status_gone') ? wpc_gen_status_gone($data, (string) $url_key, (string) $pendingUuid) : '';
                    if ($goneAnswer !== '') {
                        $droppedNow = wpc_gen_forget_gone((string) $url_key, (string) $pendingUuid, $goneAnswer);
                        delete_transient($uuid_key);
                        delete_transient('wpc_critical_key_' . $url_key);
                        if ($droppedNow && function_exists('wpc_cache_first_log')) {
                            if ($goneAnswer === 'superseded') {
                                wpc_cache_first_log('crit-epoch-superseded', (string) $url_key, '', ['uuid' => substr((string) $pendingUuid, 0, 8), 'via' => 'status']);
                            } else {
                                wpc_cache_first_log('uuid-cleared-' . $goneAnswer, (string) $url_key, '', ['uuid' => substr((string) $pendingUuid, 0, 8), 'via' => 'status',
                                    'type' => substr((string) (is_array($data) ? ($data['error_type'] ?? ($data['reason'] ?? '')) : ''), 0, 32)]);
                            }
                        }
                        if (wpc_gen_dispatch_class() === 'automatic') {
                            return true;
                        }
                    }
                }
            }
        }


        if (function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active()) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-backoff-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical']);
            }
            return true;
        }


        // A HUMAN CLICK IS NOT A KICK — the initCritical twin of the .98 fix. An admin-ajax
        // POST never carries ?forceCritical; it declares intent through the
        // wpc_critical_generate_forced global, which the kick lane honours ($wpc_forced_by_human at
        // generateCriticalAjax) and these guards did not. So the forced regen that Remove
        // Critical CSS fires was swallowed here by the park / cooldown / serve-hold / running
        // transient, and click 2 of the button dispatched nothing at all
        // (land-cooldown-deferred {path:initCritical}, staging 2026-09-17). Single-flight below
        // is left on purpose: it is the double-click shield, not a deferral.
        $wpc_force_requested = !empty($_GET['forceCritical']) || !empty($GLOBALS['wpc_critical_generate_forced']);

        if (!$wpc_force_requested && function_exists('wpc_gen_landless_parked') && wpc_gen_landless_parked((string) $url_key)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-parked-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical']);
            }
            return true;
        }


        if (!$wpc_force_requested && function_exists('wpc_land_cooldown_active') && wpc_land_cooldown_active($url_key)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-cooldown-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical']);
            }
            return true;
        }
        if (!$wpc_force_requested && function_exists('wpc_gen_served_hold_remaining') && ($servedHoldLeft = wpc_gen_served_hold_remaining((string) $url_key)) > 0) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-hold-deferred', (string) $url_key, (string) $url, ['path' => 'initCritical', 'left' => $servedHoldLeft]);
            }
            return true;
        }

        $transient_name = 'wpc_critical_key_' . $url_key; // Safe, short, unique.
        $critTransient = get_transient($transient_name);

        if (!empty($critTransient) && !$wpc_force_requested) {
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
            // A forced (human) call is shielded only from the same person's double click: its
            // own 15 s mark. The page's 120 s single-flight is the automatic lanes' own; an
            // automatic dispatch holding it must not swallow the click (P-R3: the Remove click's
            // inline forced dispatch). The forced call takes the 120 s mark too, so the automatic
            // lanes still stand back from it.
            $wpc_sf = 'wpc_gen_sf_' . $url_key;
            $wpc_sf_human = 'wpc_gen_sf_human_' . $url_key;
            if ($wpc_force_requested) {
                $wpc_sfh_at = (int) get_transient($wpc_sf_human);
                if ($wpc_sfh_at && (time() - $wpc_sfh_at) < 15) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('gen-single-flight', $url_key, '', ['skip' => 1, 'human' => 1]);
                    }
                    return true;
                }
                set_transient($wpc_sf_human, time(), 15);
                update_option($wpc_sf, time(), false);
            } elseif (!add_option($wpc_sf, time(), '', 'no')) {
                $wpc_sf_at = (int) get_option($wpc_sf);
                if ($wpc_sf_at && (time() - $wpc_sf_at) < 120) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('gen-single-flight', $url_key, '', ['skip' => 1]);
                    }
                    return true;
                }
                update_option($wpc_sf, time());
            }
        }

        // The body is built only once the dispatch helper has admitted this POST: the corpus
        // fetch below is the expensive half of a dispatch.
        $wpc_build_init = function ($uuid) use ($url, $url_key, $postID, $transient_name) {
            set_transient($transient_name, true, 60 * 5);

            $options = get_option(WPS_IC_OPTIONS);
            $apikey  = $options['api_key'] ?? '';

            $args = [
                'url'     => function_exists('wpc_canonical_url') ? wpc_canonical_url($url) : $url,
                'source'  => 'crit-v2',
                'version' => (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : (class_exists('wps_ic') ? wps_ic::$version : '')),
                'apikey'  => $apikey,
            ];

            $wpc_v3set = get_option(WPS_IC_SETTINGS);
            if (is_array($wpc_v3set) && !empty($wpc_v3set['delay-js-v2']) && $wpc_v3set['delay-js-v2'] == '1'
                && (!isset($wpc_v3set['delay-js-v3']) || $wpc_v3set['delay-js-v3'] != '0')
                && apply_filters('wpc_delay_manifest_capability', true)) {
                // Read by the service at server.js:15727 (req.body.capabilities); a key not listed here has no reader.
                $args['capabilities'] = ['delay_manifest' => 1, 'consolidated_callback' => 1, 'delay_inline' => 1, 'lcp_inline' => 1];
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
            // visitor (human|bot) + views_24h let the service defer first-ever inner URLs nobody is
            // looking at; the homepage always generates.
            if (function_exists('wpc_visitor_kind') && apply_filters('wpc_send_visitor_fields', true)) {
                $args['visitor']   = wpc_visitor_kind((string) $url_key);
                $args['views_24h'] = function_exists('wpc_views_24h') ? wpc_views_24h((string) $url_key) : 0;
                if (function_exists('wpc_site_counts_due_today') && wpc_site_counts_due_today()) {
                    $args = array_merge($args, wpc_site_counts());
                }
            }

            $args = $this->wpc_push_corpus($args, (string) $url_key);
            if (!empty($args['html']) && function_exists('wpc_font_localizer_faces')) {
                $wpc_font_faces = wpc_font_localizer_faces();
                if (!empty($wpc_font_faces)) { $args['fonts'] = $wpc_font_faces; }
            }

            return [
                'args'      => $args,
                // fire-and-forget: the answer arrives by callback, webhook or the collector
                'transport' => ['timeout' => 2, 'blocking' => false],
                'receipt'   => [
                    'caps'     => isset($args['capabilities']) && is_array($args['capabilities']) ? implode(',', array_keys($args['capabilities'])) : '',
                    'used_css' => $wpc_ucss_armed ? 1 : 0,
                    'tpl'      => !empty($args['tpl_key']) ? 1 : 0,
                ],
            ];
        };

        // Force is decided inside the dispatch by who asked (wpc_gen_force_for_class): a human
        // click forces, so a debounced /generate cannot answer it with the EXISTING crit_uuid.
        $wpc_dispatch = wpc_gen_dispatch((string) $url_key, 'initCritical', $wpc_build_init);
        if (!$wpc_dispatch['sent']) {
            if (!empty($wpc_dispatch['built'])) {
                delete_transient($transient_name);
            }
            return true;
        }

        // B2: dispatch owns its pickup — collection must never depend on future traffic.
        if (function_exists('wpc_crit_collector_arm')) {
            wpc_crit_collector_arm((string) $url_key);
        }

        return;
    }


    /**
     * The corpus every dispatch carries, so the service never fetches the origin: the page's saved
     * copy as html (wpc_saved_page_read), the handset copy as html_mobile when it differs, and css,
     * the sheets that page links read from disk, complete or absent. The body carries no html when
     * the page has no saved copy, and the dispatch door then holds it ('no-page'). Receipt:
     * push-corpus.
     */
    private function wpc_push_corpus(array $args, $urlKey)
    {
        $urlKey = ltrim((string) $urlKey, '/');
        $html = function_exists('wpc_saved_page_read') ? wpc_saved_page_read($urlKey) : '';
        $cap = (int) apply_filters('wpc_push_corpus_cap', 8388608);
        $receipt = ['src' => 'saved', 'html_b' => strlen($html), 'html_m_b' => 0, 'css_b' => 0];
        if ($html === '' || strlen($html) > $cap) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('push-corpus', $urlKey, '', ['src' => '', 'html_b' => 0, 'miss' => $html === '' ? 'no-page' : 'cap']);
            }
            return $args;
        }
        $args['html'] = $html;
        $used = strlen($html);
        if (function_exists('wpc_crit_corpus_record')) {
            wpc_crit_corpus_record($urlKey, $html, 'desktop');
        }
        if (apply_filters('wpc_push_css_corpus', true) && class_exists('wps_ic_crit_corpus')) {
            $css = '';
            $sheets = [];
            try {
                $corpus = new wps_ic_crit_corpus();
                $css = (string) $corpus->corpus_from_html($html, min(4.0, $this->wpc_push_timeout()));
                $sheets = (array) $corpus->corpus_sheets;
            } catch (\Throwable $e) {
                $css = '';
            }
            foreach (['read', 'empty', 'missing', 'left-out'] as $state) {
                $receipt[$state === 'left-out' ? 'left_out' : $state] = count(array_keys($sheets, $state, true));
            }
            foreach (['missing', 'empty'] as $state) {
                $first = array_search($state, $sheets, true);
                if ($first !== false) {
                    $receipt[$state . '_first'] = substr((string) strtok((string) $first, '?'), 0, 160);
                }
            }
            if ($css !== '' && $used + strlen($css) <= $cap) {
                $args['css'] = $css;
                $used += strlen($css);
            }
        }
        $mobile = wpc_saved_page_read($urlKey, true);
        if ($mobile !== '' && $mobile !== $html && $used + strlen($mobile) <= $cap) {
            $args['html_mobile'] = $mobile;
        }
        if (function_exists('wpc_cache_first_log')) {
            $receipt['html_m_b'] = isset($args['html_mobile']) ? strlen($args['html_mobile']) : 0;
            $receipt['css_b'] = isset($args['css']) ? strlen((string) $args['css']) : 0;
            wpc_cache_first_log('push-corpus', $urlKey, '', $receipt);
        }
        return $args;
    }

    /** Seconds the corpus may spend fetching sheets from other hosts: 5 for a waiting admin, 15 for background lanes, 1.5 for a visitor's request. */
    private function wpc_push_timeout()
    {
        $humanAdminAjax = function_exists('wp_doing_ajax') && wp_doing_ajax()
            && function_exists('is_user_logged_in') && is_user_logged_in()
            && function_exists('current_user_can') && current_user_can('manage_options');
        $backgroundRequest = !empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
            || (function_exists('wp_doing_cron') && wp_doing_cron());
        return (float) apply_filters('wpc_push_html_timeout', $humanAdminAjax ? 5 : ($backgroundRequest ? 15 : 1.5));
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

            // The service answers an unknown or expired uuid with HTTP 404 {"error":"Results not found"}
            // and no status. Read as complete, that stored NULL scores as a finished result, which
            // stops every later poll and leaves the card on "Analyzing performance..." for good
            // (centralmotelgi.com.au, 2026-10-07). The run is lost: drop its uuid so the next
            // first-run cycle dispatches a fresh one, and store nothing.
            $psiStatus = isset($data['status']) ? (string) $data['status'] : 'complete';
            if ((int) wp_remote_retrieve_response_code($results) === 404 || isset($data['error'])
                || in_array($psiStatus, ['failed', 'error'], true)) {
                $this->forgetPsiUuid($uuid);
                $jobStatus['benchmark-lost'] = $psiStatus;
                break;
            }

            if ($psiStatus !== 'complete') {
                $jobStatus['benchmark-pending'] = $psiStatus;
                if ($attempt === 0 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_psi_poll', [$urlKey, $uuid])) {
                    wp_schedule_single_event(time() + 45, 'wpc_psi_poll', [$urlKey, $uuid]);
                }
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

            // A complete answer without four numeric scores is not a result either: storing it is
            // what pinned the card on the spinner. Same handling as a lost run.
            $scoresPresent = is_numeric($parsedData['desktop']['before']['performanceScore'])
                && is_numeric($parsedData['desktop']['after']['performanceScore'])
                && is_numeric($parsedData['mobile']['before']['performanceScore'])
                && is_numeric($parsedData['mobile']['after']['performanceScore']);
            if (!$scoresPresent) {
                $this->forgetPsiUuid($uuid);
                $jobStatus['benchmark-lost'] = 'no-scores';
                break;
            }

            $stats['home'] = $parsedData;
            update_option(WPS_IC_TESTS, $stats);
            delete_transient('wpc_initial_test');
            update_option(WPS_IC_LITE_GPS, ['result' => $parsedData, 'failed' => false, 'lastRun' => time()]);
            $jobStatus['benchmark-success'] = true;
            break;

        } while ($attempt <= 3);

        return $jobStatus;
    }

    /** Drop the stashed PageSpeed uuid when it names the run that was just found lost. */
    private function forgetPsiUuid($uuid)
    {
        if ((string) get_transient('wpc_psi_uuid') === (string) $uuid) {
            delete_transient('wpc_psi_uuid');
        }
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
            wpc_fs_put($log_file, $log);
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
                    if (function_exists('wpc_write_lcp_preserving_facts')) {
                        wpc_write_lcp_preserving_facts($critical_path, $wpc_lcp_body);
                    } else {
                        wpc_fs_put($critical_path . 'lcp.json', $wpc_lcp_body);
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

        $interactive = function_exists('wp_doing_ajax') && wp_doing_ajax()
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
        $call = wp_remote_post(self::$API_ASSETS_URL, ['timeout' => (int) apply_filters('wpc_gen_dispatch_timeout', $interactive ? 45 : 12), 'body' => $args, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

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

    /**
     * Dispatch one generation for this page. $via names the caller in the gen-dispatch receipt
     * ('warm-home' for the warm lanes' explicit homepage step); it decides nothing.
     *
     * Returns what the dispatch came to, for the ajax answer of a click:
     *   ['sent' => false, 'refused' => why]                      nothing left for the service
     *   ['sent' => true, 'uuid', 'answer' => wpc_gen_answer(), 'landed' => bool]
     * A click's answer must carry the real outcome (staging 2026-09-24 06:53: Generate was
     * answered 429 domain_capped and the handler answered a bare {"success":true}); automatic
     * callers ignore the value.
     */
    public function generateCriticalAjax($sync = false, $via = 'generateCriticalAjax')
    {
        // A HUMAN CLICK IS NOT A KICK. The Regenerate button declares intent via
        // wpc_critical_generate_forced; an admin-ajax POST never carries ?forceCritical. Declared
        // human intent bypasses the kick-path guards (the click would otherwise be swallowed by
        // backoff/parked/cooldown/single-flight and re-serve a crit whose artifacts were already
        // deleted); single-flight keeps a 15s double-click shield.
        $wpc_forced_by_human = !empty($_GET['forceCritical']) || !empty($GLOBALS['wpc_critical_generate_forced']);
        if (!$wpc_forced_by_human && function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active()) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-backoff-deferred', (string) $this->urlKey, '', ['path' => 'kick']);
            }
            return ['sent' => false, 'refused' => 'backoff'];
        }


        if (!$wpc_forced_by_human && function_exists('wpc_gen_landless_parked') && wpc_gen_landless_parked((string) $this->urlKey)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-parked-deferred', (string) $this->urlKey, '', ['path' => 'kick']);
            }
            return ['sent' => false, 'refused' => 'parked'];
        }


        if (!$wpc_forced_by_human && function_exists('wpc_land_cooldown_active') && wpc_land_cooldown_active($this->urlKey)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-cooldown-deferred', (string) $this->urlKey, '', ['path' => 'kick']);
            }
            return ['sent' => false, 'refused' => 'land-cooldown'];
        }
        if (!$wpc_forced_by_human && function_exists('wpc_gen_served_hold_remaining') && ($servedHoldLeft = wpc_gen_served_hold_remaining((string) $this->urlKey)) > 0) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-hold-deferred', (string) $this->urlKey, '', ['path' => 'kick', 'left' => $servedHoldLeft]);
            }
            return ['sent' => false, 'refused' => 'served-hold'];
        }


        if (apply_filters('wpc_gen_single_flight', true) && empty($_GET['forceCritical']) && !empty($this->urlKey)) {
            $wpc_ksf = 'wpc_gen_sf_' . $this->urlKey;
            if (!add_option($wpc_ksf, time(), '', 'no')) {
                $wpc_ksf_at = (int) get_option($wpc_ksf);
                if ($wpc_ksf_at && (time() - $wpc_ksf_at) < ($wpc_forced_by_human ? 15 : 120)) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('gen-single-flight', (string) $this->urlKey, '', ['path' => 'kick', 'skip' => 1]);
                    }
                    return ['sent' => false, 'refused' => 'single-flight'];
                }
                update_option($wpc_ksf, time());
            }
        }

        // "Is an admin watching?" cannot be inferred from wp_doing_ajax() + a capability: the
        // repull kick IS admin-ajax carrying the admin's cookie. Only the human-initiated paths
        // declare it (the Regenerate button and apiGenerateCritical); they wait up to 45s for
        // the ack. Everything else — repull kicks, render kicks, warm loopbacks, cron — is
        // background and must never park a worker for long: the artifact lands via
        // callback/webhook regardless, so 5s is enough to read a refusal. A timeout is counted
        // as a generation in progress (wpc_gen_answer): the service decides a refusal before it
        // renders, so an answer that has not come in 5 s is a render under way.
        $wpc_interactive = !empty($GLOBALS['wpc_critical_generate_forced'])
            && function_exists('wp_doing_ajax') && wp_doing_ajax()
            && empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
            && function_exists('current_user_can') && current_user_can('manage_options');
        $wpc_dtimeout = (int) apply_filters('wpc_gen_dispatch_timeout', $wpc_interactive ? 45 : 5);

        $wpc_build_ajax = function ($uuid) use ($sync, $wpc_dtimeout) {
            $args = ['url' => urldecode($this->serverRequest), 'version' => (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : (class_exists('wps_ic') ? wps_ic::$version : ''))];
            $wpc_options = get_option(WPS_IC_OPTIONS);
            if (is_array($wpc_options) && !empty($wpc_options['api_key'])) {
                $args['apikey'] = (string) $wpc_options['api_key'];
            }
            // Operator "Pull Latest" (resync) → sync=1 tells the service to SKIP its template
            // cache and grind a genuinely fresh gen carrying every current-schema field. Threaded
            // per-dispatch from the redispatch lane — NEVER a shared transient (that leaked onto
            // visitor kicks).
            if ($sync) {
                $args['sync'] = 1;
            }

            $delay_settings = get_option(WPS_IC_SETTINGS);
            if (is_array($delay_settings) && !empty($delay_settings['delay-js-v2']) && $delay_settings['delay-js-v2'] == '1'
                && (!isset($delay_settings['delay-js-v3']) || $delay_settings['delay-js-v3'] != '0')
                && apply_filters('wpc_delay_manifest_capability', true)) {
                // Read by the service at server.js:15727 (req.body.capabilities); a key not listed here has no reader.
                $args['capabilities'] = ['delay_manifest' => 1, 'consolidated_callback' => 1, 'delay_inline' => 1, 'lcp_inline' => 1];
            }

            $wpc_ucss_armed = false;
            if (function_exists('wpc_used_css_apply_demand') && !empty($this->urlKey)) {
                $wpc_ucss_armed = wpc_used_css_apply_demand($args, $this->urlKey);
            }
            // tpl_key rides every dispatch we have one for, so the service's observation cache
            // covers delay/fonts-only sites, not just used-css ones.
            if (empty($args['tpl_key']) && !empty($this->urlKey) && function_exists('wpc_dispatch_tpl_key')
                && apply_filters('wpc_send_tpl_key_always', true)) {
                $wpc_dtk = wpc_dispatch_tpl_key($this->urlKey);
                if ($wpc_dtk !== '') { $args['tpl_key'] = $wpc_dtk; }
            }
            // Content-version: no $postID in the ajax path, so the helper resolves the URL to a
            // post id (url_to_postid → 0 for homepage/archive → omitted).
            if (function_exists('wpc_dispatch_post_mtime') && apply_filters('wpc_send_post_modified', true)) {
                $post_mtime = wpc_dispatch_post_mtime(0, urldecode($this->serverRequest));
                if ($post_mtime !== '') { $args['post_modified'] = $post_mtime; }
            }
            // visitor (human|bot) + views_24h let the service defer first-ever inner URLs nobody
            // is looking at; the homepage always generates.
            if (function_exists('wpc_visitor_kind') && apply_filters('wpc_send_visitor_fields', true)) {
                $args['visitor']   = wpc_visitor_kind((string) $this->urlKey);
                $args['views_24h'] = function_exists('wpc_views_24h') ? wpc_views_24h((string) $this->urlKey) : 0;
                if (function_exists('wpc_site_counts_due_today') && wpc_site_counts_due_today()) {
                    $args = array_merge($args, wpc_site_counts());
                }
            }

            $args = $this->wpc_push_corpus($args, (string) $this->urlKey);
            if (empty($args['fonts']) && function_exists('wpc_font_localizer_faces')) {
                $wpc_font_faces = wpc_font_localizer_faces();
                if (!empty($wpc_font_faces)) { $args['fonts'] = $wpc_font_faces; }
            }
            return [
                'args'      => $args,
                'form'      => true,
                'transport' => ['timeout' => $wpc_dtimeout, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT],
                'receipt'   => [
                    'caps'     => isset($args['capabilities']) && is_array($args['capabilities']) ? implode(',', array_keys($args['capabilities'])) : '',
                    'used_css' => $wpc_ucss_armed ? 1 : 0,
                    'tpl'      => !empty($args['tpl_key']) ? 1 : 0,
                ],
            ];
        };

        $wpc_dispatch = wpc_gen_dispatch((string) $this->urlKey, (string) $via, $wpc_build_ajax);
        if (!$wpc_dispatch['sent']) {
            return ['sent' => false, 'refused' => (string) $wpc_dispatch['refused']];
        }
        $call = $wpc_dispatch['response'];
        $body = $wpc_dispatch['body'];


        // A dispatch that got no usable ack (code=0/5xx, empty body) must NOT hold the full
        // 120s single-flight lock — an admin waiting on a fresh purge, or the land watchdog,
        // needs to retry within ~20s. Backdate the lock instead of clearing it (clearing would
        // let every visitor hammer a down backend); success clears it fully in saveCriticalCss.
        $dispatch_code = is_wp_error($call) ? 0 : (int) wp_remote_retrieve_response_code($call);
        if ($wpc_interactive && !empty($this->urlKey) && ($dispatch_code === 0 || $dispatch_code >= 500) && strlen((string) $body) < 32) {
            $fail_lock_key = 'wpc_gen_sf_' . $this->urlKey;
            if (get_option($fail_lock_key) !== false) {
                update_option($fail_lock_key, time() - 100); // ~20s until the <120s gate releases
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-lock-shortened', (string) $this->urlKey, '', ['code' => $dispatch_code]);
            }
        }


        $body = trim((string) $body);
        $dispatchAck = $body !== '' && $body[0] === '{' ? json_decode($body, true) : null;
        // The envelope carries the artifact path the bare answer used to be.
        if (is_array($dispatchAck) && !empty($dispatchAck['artifact']) && is_string($dispatchAck['artifact'])) {
            $body = trim($dispatchAck['artifact']);
        }
        // The JSON ack names the generation the service is really working on: our own uuid on a
        // fresh answer, the pointer's on a reused one, pending_uuid on a 429 render_pending. The
        // poll must track THAT uuid — one the service never minted polls forever. Only the poll's
        // pointers move here: the dispatch was recorded once by the door (wpc_gen_dispatch_stamp,
        // and the served uuid in the same record). Re-stamping dispatch_ts.txt here made the
        // corpus pushed with the dispatch read as older than the dispatch, and the land of the
        // served generation dropped land_corpus.txt as 'stale' (greenvalleytint /services/
        // 2026-09-24 09:11:23 UTC), switching corpus-drift detection off for the page.
        $serviceUuid = '';
        foreach (['pending_uuid', 'crit_uuid', 'uuid'] as $ackKey) {
            if (is_array($dispatchAck) && !empty($dispatchAck[$ackKey])) {
                $serviceUuid = preg_replace('/[^A-Za-z0-9-]/', '', (string) $dispatchAck[$ackKey]);
                break;
            }
        }
        if ($serviceUuid !== '' && !empty($this->urlKey)) {
            set_transient('wpc_critical_uuid_' . $this->urlKey, $serviceUuid, 60 * 30);
            if (defined('WPS_IC_CRITICAL')) {
                $critDir = WPS_IC_CRITICAL . $this->urlKey . '/';
                if (!is_dir($critDir)) {
                    @mkdir($critDir, 0777, true);
                }
                wpc_crit_meta_write($critDir . 'uuid.txt', $serviceUuid);
            }
            if ((string) wp_remote_retrieve_header($call, 'x-wpc-debounced') !== '' && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-debounced-repoint', (string) $this->urlKey, '', ['uuid' => substr($serviceUuid, 0, 8)]);
            }
        }
        if ($body !== '' && $body[0] !== '{'
            && preg_match('#([0-9a-f]{4})/([0-9a-f-]{16,})-(?:desktop|mobile)\.css#i', $body, $wpc_pm)) {
            $wpc_up  = $wpc_pm[1];
            $wpc_uid = $wpc_pm[2];
            $wpc_base = 'https://critical-css-mc.b-cdn.net/' . $wpc_up . '/' . $wpc_uid;
            $wpc_sync_epoch = (string) wp_remote_retrieve_header($call, 'x-wpc-epoch');
            $this->saveCriticalCss($this->urlKey, [
                'url'   => ['desktop' => $wpc_base . '-desktop.css', 'mobile' => $wpc_base . '-mobile.css'],
                'uuid'  => $wpc_uid,
                'via'   => 'sync',
                'epoch' => $wpc_sync_epoch !== '' ? $wpc_sync_epoch : null,
            ], 'meta', urldecode((string) $this->serverRequest));
        } elseif (strlen($body) > 128 && ($body[0] !== '{' || (is_array($dispatchAck) && !empty($dispatchAck['url'])))) {
            $this->saveCriticalCss($this->urlKey, $body);
        }

        // B2: no sync land in the ack (fast-ack / timeout / still grinding) → the dispatch owns
        // its pickup; this lane is already a background worker.
        $dispatchLanded = false;
        if (!empty($this->urlKey) && defined('WPS_IC_CRITICAL')) {
            $collectDir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $this->urlKey . '/';
            $dispatchLanded = @filesize($collectDir . 'critical_desktop.css') > 64
                && trim((string) @file_get_contents($collectDir . 'land_uuid.txt')) !== ''
                && trim((string) @file_get_contents($collectDir . 'land_uuid.txt')) === trim((string) @file_get_contents($collectDir . 'uuid.txt'));
            if (!$dispatchLanded && function_exists('wpc_crit_collector_arm')) {
                wpc_crit_collector_arm((string) $this->urlKey);
            }
        }
        return [
            'sent'   => true,
            'uuid'   => $serviceUuid !== '' ? $serviceUuid : (string) $wpc_dispatch['uuid'],
            'answer' => (array) $wpc_dispatch['answer'],
            'landed' => $dispatchLanded,
        ];
    }

    // DB-free storage pointer: resolves crit_uuid without any service DB when we hold no uuid.
    // The key is whatever wpc_crit_pointer_key() computes — the service's own canonicalisation
    // (latest-artifacts.js canonicalizeForKey), which folds the trailing slash on a non-root
    // path. Building it by hand here asked for a pointer object no inner page ever had.
    public function fetchLatestPointerUuid($urlKey)
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
        // url.txt is stored SCHEME-LESS; the helper accepts that (brightvibes receipt: artifacts
        // on the shelf, pointer never GET, because parse_url without a scheme yields no host).
        $ptr = wpc_crit_pointer_url($apikey, $u);
        if ($ptr === '') {
            return '';
        }
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
            $epochFloor = (int) $j['crit_epoch_min'];
            if ($epochFloor !== (int) get_option('wpc_crit_epoch_min', 0)) {
                update_option('wpc_crit_epoch_min', $epochFloor, false);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-epoch-floor', (string) $urlKey, '', ['min' => $epochFloor]);
                }
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-pointer-resolve', (string) $urlKey, '', ['ready' => (int) ($j['ready'] ?? 0)]);
        }
        // The pointer body is storagePointerWrite (crit-push server.js:2063-2087) and carries
        // exactly: v, url_key, crit_uuid, obs_uuid, lcp_uuid, has_used_css, ready, bundle_url,
        // bundle_gen, bundle_complete, crit_epoch, crit_epoch_min, at. near_expiry, expired and
        // artifacts{} ride /v2/latest, never this object, and are handled in wpc_lcp_repull_handler.
        return preg_replace('/[^a-f0-9-]/i', '', (string) ($j['crit_uuid'] ?? ''));
    }

    // DB-free landing: artifact URLs are derivable from the uuid alone
    // (critical-css-mc.b-cdn.net/{uuid:0:4}/{uuid}-{device}.css), and saveCriticalCss pulls
    // them straight from Bunny CDN — no /status, no service DB. Immune to the admission-DB
    // outage class: if the gen finished its grind, the files exist regardless of DB state.
    public function pullDerivedArtifacts($urlKey = '', $uuid = '', $force = false, $rev = 0, array $channel = [])
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
        // Where the uuid came from, for the service's judgement before the land and its receipt:
        // the caller (an announce), the static storage pointer, or the page's own held dispatch.
        $uuidSource = (isset($channel['via']) && $channel['via'] === 'pointer') ? 'pointer' : 'caller';
        if (empty($uuid)) {
            $uuidSource = 'held';
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
            $uuidSource = 'pointer';
            if (strlen($uuid) < 8) {
                return false;
            }
        }
        // Forced pull of a uuid that already landed (and isn't stale-marked) is a no-op —
        // UNLESS the announce carries a HIGHER wire_rev than we landed (v7.21.156, crit-team
        // receipt: proof-trim rewrote the artifact post-upload under the same uuid; the
        // re-announce with wire_rev=2 was ACK'd and skipped here, so a corrected verdict
        // could never reach a page through the announce path). Same-uuid immutability is
        // being restored service-side (announce held until trim settles); this is the belt.
        if ($force && !@is_file($cd . 'stale.txt')
            && @filesize($cd . 'critical_desktop.css') > 64 && @filesize($cd . 'critical_mobile.css') > 64
            && trim((string) @file_get_contents($cd . 'land_uuid.txt')) === $uuid
            && (int) $rev <= max(1, (int) @file_get_contents($cd . 'land_rev.txt'))) {
            return true;
        }
        // Serve-stale regen intent with no newer ack (dispatch timeout ate the uuid): the
        // POINTER decides — a fresh publish (pointer ≠ landed) collects anyway; only when
        // the pointer still names the landed artifact is there truly nothing new to land.
        if (@is_file($cd . 'stale.txt') && trim((string) @file_get_contents($cd . 'land_uuid.txt')) === $uuid) {
            $pointerUuid = $this->fetchLatestPointerUuid($urlKey);
            if ($pointerUuid === '' || $pointerUuid === $uuid) {
                // v7.10.682 — MOOT-STALE SELF-HEAL. The pointer CONFIRMS the landed artifact is
                // the latest published AND no dispatch is in flight: this stale mark can never be
                // satisfied (the rebuild-press outage shape: stale-marked, the follow-up dispatch
                // died, and every kick refused right here for ~70 minutes while pages rendered
                // critless). The landed artifact IS current — clear the mark so serving resumes,
                // and arm ONE redispatch so the regen intent still lands fresh in the background.
                if ($pointerUuid === $uuid
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
            $uuid = $pointerUuid;
            $uuidSource = 'pointer';
        }
        // NOT backoff-gated: these are CDN GETs (the artifacts' shelf), not backend calls —
        // the breaker stops dispatch, never pickup (brightvibes receipt: artifacts waited
        // on the shelf while a failed re-gen's backoff blocked collection)
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return false;
        }
        // The CDN shelf and the storage pointer are static objects: they serve any uuid, and no
        // service-side removal can refuse a pull from them. Removal is the service's decision,
        // so every land this site starts from them asks the service's /status first (with the
        // site's apikey and removal epochs) and lands nothing it answers as removed, nothing while
        // it cannot be asked, and nothing while it has not acknowledged this site's last removal.
        // A webhook delivery is the service's own push and is not asked again. The plugin
        // compares no epoch.
        $askService = ($channel['via'] ?? '') !== 'webhook';
        if ($askService && $this->serviceWithholdsGeneration((string) $urlKey, $uuid, $uuidSource, $channel, $cd)) {
            return false;
        }
        $askedUuid = $uuid;
        $base = 'https://critical-css-mc.b-cdn.net/' . substr($uuid, 0, 4) . '/' . $uuid;
        // HEAD first — cheap existence probe while the gen may still be grinding.
        // ?t= cache-bust throughout (joint spec v2 S3): Bunny edge may negative-cache a
        // premature 404, and a repeat-probing collector would otherwise never see the 200.
        $cacheBust = '?t=' . time();
        $head = wp_remote_head($base . '-mobile.css' . $cacheBust, ['timeout' => 3, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (is_wp_error($head) || (int) wp_remote_retrieve_response_code($head) !== 200) {
            // A2 (incident report 2026-07-20): a held uuid can be EXPIRED server-side while a
            // newer publish exists — the pointer twin is authoritative; a dead uuid must
            // never block collection (staging b8ef631a loop: dead uuid rehydrated forever).
            $pointerUuidAfterMiss = $this->fetchLatestPointerUuid($urlKey);
            if ($pointerUuidAfterMiss === '' || $pointerUuidAfterMiss === $uuid) {
                return false;
            }
            $base = 'https://critical-css-mc.b-cdn.net/' . substr($pointerUuidAfterMiss, 0, 4) . '/' . $pointerUuidAfterMiss;
            $head = wp_remote_head($base . '-mobile.css' . $cacheBust, ['timeout' => 3, 'user-agent' => WPS_IC_API_USERAGENT]);
            if (is_wp_error($head) || (int) wp_remote_retrieve_response_code($head) !== 200) {
                return false;
            }
            $uuid = $pointerUuidAfterMiss;
            $uuidSource = 'pointer';
        }
        if ($askService && $uuid !== $askedUuid && $this->serviceWithholdsGeneration((string) $urlKey, $uuid, $uuidSource, $channel, $cd)) {
            return false;
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-db-free-pull', (string) $urlKey, '', ['uuid' => substr($uuid, 0, 8)]);
        }
        // $channel names who delivered this generation (via) and the epoch it reported, for the
        // land receipt only.
        $this->saveCriticalCss($urlKey, array_intersect_key($channel, ['via' => 1, 'epoch' => 1]) + [
            'url'  => ['desktop' => $base . '-desktop.css' . $cacheBust, 'mobile' => $base . '-mobile.css' . $cacheBust],
            'uuid' => $uuid,
            'wire_rev' => (int) $rev,
        ], 'meta');
        $landedDesktop = @filesize($cd . 'critical_desktop.css') > 64;
        if ($landedDesktop && !@is_readable($cd . 'used_tpl.txt')
            && function_exists('wpc_lcp_repull_handler') && function_exists('get_transient')
            && !get_transient(WPC_USED_CSS_BACKFILL_ONCE_PREFIX . md5((string) $urlKey))) {
            set_transient(WPC_USED_CSS_BACKFILL_ONCE_PREFIX . md5((string) $urlKey), 1, 60);
            try {
                wpc_lcp_repull_handler((string) $urlKey, 1);
            } catch (\Throwable $e) {
            }
        }
        return $landedDesktop;
    }

    /**
     * May this site land $uuid for $urlKey from a static object (the CDN shelf, the storage
     * pointer)? Those objects serve any uuid and no removal can refuse a pull from them, so the
     * site takes nothing from them while the service cannot vouch for it. True = withheld:
     *
     *   unacked-removal     this site raised a removal epoch the service has not acknowledged
     *                       (wpc_crit_removal_unacked): the pointer may still name the removed
     *                       generation, and nothing has told the service to withdraw it.
     *   status-unreachable  /status did not answer.
     *   superseded          the service answered the generation as removed (serviceRemovedGeneration).
     *
     * The first two write `crit-land-withheld {uuid, why, via}`. The page keeps what it has; the
     * next pointer tick, kick or collect asks again. An answer carrying the generation's epoch
     * fills $channel['epoch'] for the land receipt.
     */
    private function serviceWithholdsGeneration($urlKey, $uuid, $source, array &$channel, $critDir)
    {
        $why = '';
        if (function_exists('wpc_crit_removal_unacked') && wpc_crit_removal_unacked($urlKey)) {
            $why = 'unacked-removal';
        } else {
            $removed = $this->serviceRemovedGeneration($urlKey, $uuid, $source, $channel, $critDir);
            if ($removed !== null) {
                return $removed;
            }
            $why = 'status-unreachable';
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-land-withheld', $urlKey, '', ['uuid' => substr($uuid, 0, 8), 'why' => $why, 'via' => $source]);
        }
        return true;
    }

    /**
     * Ask the service's /status (with the apikey and the removal epochs) whether it removed $uuid
     * for $urlKey. null = no answer; false = not removed; true = removed: the held uuid and its
     * pending entry are dropped, `crit-land-refused-superseded {uuid, via, epoch_min}` is written,
     * and when $uuid is the generation on disk the page's crit is wiped, because the service has
     * withdrawn what the page serves.
     *
     * Removed means an explicit epoch_superseded answer. not_found, or any other answer, is not a
     * removal. Nor is a superseded answer that names no generation epoch for a uuid this site
     * dispatched itself ($source 'held'): that is the service not having recorded the generation
     * yet, and a removal forgets every held uuid, so a held one was dispatched after it.
     */
    private function serviceRemovedGeneration($urlKey, $uuid, $source, array &$channel, $critDir)
    {
        $statusAnswer = wp_remote_get(wpc_crit_service_read_url('/status', ['uuid' => $uuid], $urlKey), ['timeout' => 3]);
        $statusJson = (!is_wp_error($statusAnswer) && (int) wp_remote_retrieve_response_code($statusAnswer) === 200)
            ? json_decode((string) wp_remote_retrieve_body($statusAnswer), true) : null;
        if (!is_array($statusJson)) {
            return null;
        }
        $superseded = ($statusJson['error_type'] ?? '') === 'epoch_superseded' || ($statusJson['status'] ?? '') === 'epoch_superseded';
        $unrecorded = $source === 'held' && (int) ($statusJson['epoch'] ?? 0) === 0;
        if (!$superseded || $unrecorded) {
            if (isset($statusJson['epoch']) && is_numeric($statusJson['epoch']) && (int) $statusJson['epoch'] > 0 && !array_key_exists('epoch', $channel)) {
                $channel['epoch'] = (int) $statusJson['epoch'];
            }
            return false;
        }
        if ((string) get_transient('wpc_critical_uuid_' . $urlKey) === $uuid) {
            delete_transient('wpc_critical_uuid_' . $urlKey);
        }
        if (trim((string) @file_get_contents($critDir . 'uuid.txt')) === $uuid) {
            @unlink($critDir . 'uuid.txt');
        }
        if (function_exists('wpc_gen_pending_forget')) {
            wpc_gen_pending_forget($urlKey, $uuid);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-land-refused-superseded', $urlKey, '', ['uuid' => substr($uuid, 0, 8), 'via' => $source,
                'epoch_min' => isset($statusJson['epoch_min']) ? (int) $statusJson['epoch_min'] : 0]);
        }
        if (trim((string) @file_get_contents($critDir . 'land_uuid.txt')) === $uuid && function_exists('wpc_crit_invalidate')) {
            wpc_crit_invalidate($urlKey, 'service-removed', 'wipe');
            // The page's stored copies inline the withdrawn crit: they go with it.
            if (function_exists('wpc_land_purge_coalesced')) {
                wpc_land_purge_coalesced($urlKey, '', 'crit-service-removed');
            }
        }
        return true;
    }

    /**
     * The service said this page's current generation is gone (/v2/latest answered removed). The
     * answer names no uuid, so the generation on disk is asked about by name, and when the service
     * answers it as removed the page's crit is wiped (serviceRemovedGeneration). Anything else
     * leaves the page as it is. True when the page's crit was wiped.
     */
    public function dropWithdrawnGeneration($urlKey)
    {
        if (empty($urlKey) || !defined('WPS_IC_CRITICAL')) {
            return false;
        }
        $critDir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
        $landedUuid = preg_replace('/[^a-f0-9-]/i', '', trim((string) @file_get_contents($critDir . 'land_uuid.txt')));
        if (strlen($landedUuid) < 8) {
            return false;
        }
        $channel = [];
        return $this->serviceRemovedGeneration((string) $urlKey, $landedUuid, 'landed', $channel, $critDir) === true;
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

        // EVERY land comes through here (the /status collect, the DB-free derived pull, the
        // service callback and the v1 meta lane). Identity, in order: the field the caller sent,
        // the artifact URL itself ({uuid:0:4}/{uuid}-{device}.css — the /status collect sends no
        // uuid field at all), then the dispatch transient.
        $wpc_landing_uuid = !empty($json['uuid']) ? (string) $json['uuid'] : '';
        if ($wpc_landing_uuid === '' && !empty($json['url']['desktop']) && is_string($json['url']['desktop'])
            && preg_match('#/([A-Za-z0-9-]{8,})-(?:desktop|mobile|combined)\.css#i', (string) $json['url']['desktop'], $wpc_uidm)) {
            $wpc_landing_uuid = $wpc_uidm[1];
        }
        if ($wpc_landing_uuid === '' && function_exists('get_transient')) {
            $wpc_landing_uuid = (string) get_transient('wpc_critical_uuid_' . $urlKey);
        }
        // Removal is the service's: it never hands out a generation below the site's or the
        // page's removal epoch, so nothing here refuses a land. The epoch the channel reported
        // is recorded; one below what this site raised is the contract failing, and is logged
        // for the service to answer for — the land goes ahead.
        $wpc_land_via = !empty($json['via']) ? (string) $json['via'] : (!empty($json['lcp_src']) ? (string) $json['lcp_src'] : (string) $type);
        $wpc_land_epoch = (isset($json['epoch']) && is_numeric($json['epoch'])) ? (int) $json['epoch'] : null;
        if ($wpc_land_epoch !== null && function_exists('wpc_cache_first_log')) {
            $wpc_site_epoch = (int) get_option('wpc_crit_site_epoch', 0);
            $wpc_page_epoch = 0;
            if (function_exists('wpc_gen_landless_read')) {
                $wpc_page_record = wpc_gen_landless_read((string) $urlKey);
                $wpc_page_epoch = (int) ($wpc_page_record['kept']['page_epoch'] ?? 0);
            }
            if ($wpc_land_epoch < max($wpc_site_epoch, $wpc_page_epoch)) {
                wpc_cache_first_log('crit-land-epoch-mismatch', (string) $urlKey, (string) $pageUrl, [
                    'uuid' => substr(preg_replace('/[^A-Za-z0-9-]/', '', $wpc_landing_uuid), 0, 8), 'via' => $wpc_land_via,
                    'epoch' => $wpc_land_epoch, 'site' => $wpc_site_epoch, 'page' => $wpc_page_epoch,
                ]);
            }
        }

        // A generation lands once. The callback, the webhook, the collector and the pointer can
        // all deliver the same uuid; a second delivery of nothing new would re-download,
        // re-write and re-purge the key for bytes already on disk. Nothing new means: the uuid
        // land_uuid.txt names, no higher wire revision, not a reannounce (the service re-offers a
        // generation it rewrote in place, or one it saw the page serve other bytes for), no stale
        // mark, and none of the derived fields a later channel can carry that an earlier one did
        // not (manifests, used-css, fonts, LCP).
        $wpc_landed_uuid = trim((string) @file_get_contents($critical_path . 'land_uuid.txt'));
        $wpc_landing_clean = preg_replace('/[^A-Za-z0-9-]/', '', (string) $wpc_landing_uuid);
        $wpc_landing_rev = (int) (isset($json['wire_rev']) ? $json['wire_rev'] : (isset($json['rev']) ? $json['rev'] : 1));
        $wpc_landing_reannounce = isset($json['ready']) && (string) $json['ready'] === 'reannounce';
        $wpc_landing_carries = false;
        foreach (['delay_url', 'lcp_url', 'used_css_url', 'tpl_key', 'prescriptions_url', 'fonts', 'fonts_url', 'crit_combined', 'crit_combined_url'] as $wpc_field) {
            if (!empty($json[$wpc_field])) { $wpc_landing_carries = true; }
        }
        if ($wpc_landing_clean !== '' && $wpc_landed_uuid === $wpc_landing_clean && !$wpc_landing_carries && !$wpc_landing_reannounce
            && $wpc_landing_rev <= max(1, (int) @file_get_contents($critical_path . 'land_rev.txt'))
            && !@is_file($critical_path . 'stale.txt')
            && @filesize($critical_path . 'critical_desktop.css') > 5 && @filesize($critical_path . 'critical_mobile.css') > 5) {
            if (function_exists('wpc_gen_landed')) {
                wpc_gen_landed((string) $urlKey, $wpc_landing_clean);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-land-duplicate', (string) $urlKey, (string) $pageUrl, ['uuid' => substr($wpc_landing_clean, 0, 8)]);
            }
            return ['critical-css' => 'duplicate', 'purged' => 0];
        }

        // A land never replaces a newer generation of ours with an older one. Two generations of
        // one page can both be in flight (a Remove's forced dispatch and its redispatch), and each
        // is delivered more than once (webhook, callback, pointer, collect); an older one arriving
        // after the newer one landed would flip the page back and purge its copy each time. The
        // order is the site's own dispatch order; a generation this site has no record of (one the
        // service started, or one dispatched before a park reset) lands as it always has.
        if (function_exists('wpc_gen_dispatched_before') && wpc_gen_dispatched_before((string) $urlKey, $wpc_landing_clean, $wpc_landed_uuid)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-land-older-skipped', (string) $urlKey, (string) $pageUrl, [
                    'uuid' => substr($wpc_landing_clean, 0, 8), 'current' => substr($wpc_landed_uuid, 0, 8), 'via' => $wpc_land_via]);
            }
            return ['critical-css' => 'older-skipped', 'purged' => 0];
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
        // What the page serves before this land, for the purge decision below.
        $servedFingerprint = function () use ($critical_path) {
            $desktopCss = (string) @file_get_contents($critical_path . 'critical_desktop.css');
            $mobileCss = (string) @file_get_contents($critical_path . 'critical_mobile.css');
            if (strlen($desktopCss) <= 5 || strlen($mobileCss) <= 5) {
                return '';
            }
            return md5($desktopCss) . md5($mobileCss) . md5((string) @file_get_contents($critical_path . 'critical_combined.css'));
        };
        $servedBefore = $servedFingerprint();
        $servedWasStale = @is_file($critical_path . 'stale.txt');
        foreach ([['critical_desktop.css', wp_remote_retrieve_body($desktop)], ['critical_mobile.css', wp_remote_retrieve_body($mobile)]] as $wpc_w) {
            $wpc_tmp = $critical_path . $wpc_w[0] . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 6);
            if (wpc_crit_meta_write($wpc_tmp, $wpc_w[1]) !== false) {
                if (!@rename($wpc_tmp, $critical_path . $wpc_w[0])) {
                    @unlink($wpc_tmp);
                } elseif (hash('sha256', (string) @file_get_contents($critical_path . $wpc_w[0])) !== hash('sha256', (string) $wpc_w[1])) {
                    @unlink($critical_path . $wpc_w[0]);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('crit-save-sha-mismatch', (string) $urlKey, '', ['f' => $wpc_w[0]]);
                    }
                }
            }
        }

        // The render PREFERS critical_combined.css, but only the callback JSON path ever
        // wrote it — pull-landed sites served a stale combined forever (hawkeye receipt:
        // fresh device files on disk, page still inlining the old combined). Combined must
        // never outlive the device files it wraps: refresh from the shelf when published,
        // remove when not — the two-blob wrap serves as the fallback.
        $wpc_combined_css = '';
        if (!empty($json['url']['desktop']) && is_string($json['url']['desktop'])) {
            $wpc_combined_url = str_replace('-desktop.css', '-combined.css', (string) $json['url']['desktop']);
            if ($wpc_combined_url !== (string) $json['url']['desktop']) {
                $wpc_combined_response = wp_remote_get($wpc_combined_url . (strpos($wpc_combined_url, '?') === false ? '?' : '&') . 't=' . time(),
                    ['headers' => ['user-agent' => WPS_IC_API_USERAGENT], 'timeout' => 6]);
                if (!is_wp_error($wpc_combined_response) && (int) wp_remote_retrieve_response_code($wpc_combined_response) === 200) {
                    $wpc_combined_css = (string) wp_remote_retrieve_body($wpc_combined_response);
                }
            }
        }
        if (strlen($wpc_combined_css) > 1024 && stripos($wpc_combined_css, '@media') !== false
            && stripos($wpc_combined_css, '<script') === false && stripos($wpc_combined_css, '</style') === false) {
            $wpc_combined_tmp = $critical_path . 'critical_combined.css.tmp.' . getmypid();
            if (wpc_crit_meta_write($wpc_combined_tmp, $wpc_combined_css) !== false && !@rename($wpc_combined_tmp, $critical_path . 'critical_combined.css')) {
                @unlink($wpc_combined_tmp);
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
        if (function_exists('wpc_gen_landed')
            && ((@filesize($critical_path . 'critical_desktop.css') > 5)
                || (@filesize($critical_path . 'critical_mobile.css') > 5))) {
            wpc_gen_landed((string) $urlKey, preg_replace('/[^A-Za-z0-9-]/', '', (string) $wpc_landing_uuid));
        }
        if (file_exists($critical_path . 'critical_desktop.css') && filesize($critical_path . 'critical_desktop.css') > 5) {
            if (file_exists($critical_path . 'critical_mobile.css') && filesize($critical_path . 'critical_mobile.css') > 5) {


                @unlink($critical_path . 'stale.txt');

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
                $wpc_dispatch_ts = (int) @file_get_contents($critical_path . 'dispatch_ts.txt');
                wpc_crit_meta_write($critical_path . 'land_ts.txt', (string) time());
                // THE ARTIFACT NAMES THE CORPUS IT WAS BUILT FROM, paired by the generation uuid
                // (the rule is on wpc_crit_corpus_land); a land it refuses claims no corpus, and no
                // corpus is silence at render, never a drift. land-saved says how it paired.
                $corpusPairing = wpc_crit_corpus_land($critical_path, (string) $wpc_uid_fresh);
                if ($corpusPairing['why'] !== '' && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-land-corpus-unknown', (string) $urlKey, '', ['why' => $corpusPairing['why']]);
                }
                @unlink($critical_path . 'dom_ids54.txt');
                // The epoch travels INSIDE the css (/*wpc-epoch:N*/, v3.116.1 — it survives the
                // combiner). Persist it next to the artifact so freshness is a property of the
                // artifact itself, not of anything we can lose. Absent = pre-epoch gen = 0.
                $wpc_crit_epoch = 0;
                foreach (['critical_desktop.css', 'critical_mobile.css', 'critical_combined.css'] as $wpc_epoch_file) {
                    $wpc_epoch_head = (string) @file_get_contents($critical_path . $wpc_epoch_file, false, null, 0, 128);
                    if ($wpc_epoch_head !== '' && preg_match('~/\*wpc-epoch:(\d+)\*/~', $wpc_epoch_head, $wpc_epoch_match)) {
                        $wpc_crit_epoch = (int) $wpc_epoch_match[1];
                        break;
                    }
                }
                wpc_crit_meta_write($critical_path . 'crit_epoch.txt', (string) $wpc_crit_epoch);
                if ($wpc_uid_fresh !== '') {
                    wpc_crit_meta_write($critical_path . 'land_uuid.txt', preg_replace('/[^A-Za-z0-9-]/', '', $wpc_uid_fresh));
                }
                wpc_crit_meta_write($critical_path . 'land_rev.txt',
                    (string) max(1, (int) (isset($json['wire_rev']) ? $json['wire_rev'] : (isset($json['rev']) ? $json['rev'] : 1))));
                if ($wpc_dispatch_ts > 0 && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-land-latency', (string) $urlKey, (string) $pageUrl, ['s' => max(0, time() - $wpc_dispatch_ts)]);
                }


                // A land purges the page's copies only when it changed what the page serves. The
                // same crit bytes landed again (a status or sync re-land of the generation on disk,
                // or a re-delivery with artifacts) purged a copy written the same second on a test
                // site, so the next visit rendered live instead of hitting the file. A page that was
                // stale-marked is always purged: its copies were rendered in the stale shape.
                $landChangedServed = $servedWasStale || $servedBefore === '' || $servedFingerprint() !== $servedBefore;
                $jobStatus['purged'] = $landChangedServed ? 1 : 0;
                if (function_exists('wpc_cache_first_log')) {
                    $landLayers = ['via' => $wpc_land_via, 'epoch' => $wpc_land_epoch,
                        'uuid' => substr((string) $wpc_landing_clean, 0, 8), 'purged' => $landChangedServed ? 1 : 0,
                        'corpus' => $corpusPairing['by'] !== '' ? $corpusPairing['by'] : 'none'];
                    if (!$landChangedServed) {
                        $landLayers['why'] = 'unchanged';
                    }
                    wpc_cache_first_log('land-saved', (string) $urlKey, (string) $pageUrl, $landLayers);
                }
                // The evidence for deleting the init watchdog: a land nothing but its kick asked for.
                if (function_exists('wpc_watchdog_rescue_note')) {
                    wpc_watchdog_rescue_note((string) $urlKey, (int) $wpc_dispatch_ts);
                }
                if ($landChangedServed) {
                    if (function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()
                        && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                        function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, $pageUrl, 'crit-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, $pageUrl, ['context' => 'crit-land', 'warm' => true]);
                    } else {
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('land-purge-legacy', (string) $urlKey, (string) $pageUrl, []);
                        }
                        $cache::purgeAll($urlKey, false, true, false);
                    }
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
                        wpc_fs_put($critical_path . 'critical_combined.css', (string) $json['crit_combined'], LOCK_EX);
                    }


                    foreach (['mobile', 'desktop'] as $wpc_device) {
                        $wpc_used_css_key = 'used_css_' . $wpc_device . '_url';
                        if (!empty($json[$wpc_used_css_key]) && is_string($json[$wpc_used_css_key])) {
                            wpc_crit_meta_write($critical_path . $wpc_used_css_key . '.txt', trim($json[$wpc_used_css_key]));
                        }
                    }


                    $wpc_ucss_settings = get_option(WPS_IC_SETTINGS);
                    $wpc_ucss_enabled  = is_array($wpc_ucss_settings) && !empty($wpc_ucss_settings['used-css']) && $wpc_ucss_settings['used-css'] == '1';
                    if ($wpc_ucss_enabled && function_exists('wpc_used_css_fetch')
                        && wpc_used_css_fetch(trim($json['used_css_url']), (string) $json['tpl_key'])) {


                        $wpc_settings_for_purge = get_option(WPS_IC_SETTINGS);
                        if (is_array($wpc_settings_for_purge) && !empty($wpc_settings_for_purge['used-css']) && $wpc_settings_for_purge['used-css'] == '1'
                            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                            // Hit-rate law (.325): used.css is tpl-keyed — purge that template's
                            // pages only; the site-wide wipe is the bounded fallback.
                            if (!function_exists('wpc_used_css_scoped_purge')
                                || !wpc_used_css_scoped_purge((string) $json['tpl_key'])) {
                                try { wps_ic_cache::removeHtmlCacheFiles('all', '', '', 'soft'); } catch (\Throwable $e) {}
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
                    $wpc_su  = wpc_crit_service_read_url('/status', ['uuid' => $wpc_pu], (string) $urlKey);
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
        wpc_fs_remove_tree($path);
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
