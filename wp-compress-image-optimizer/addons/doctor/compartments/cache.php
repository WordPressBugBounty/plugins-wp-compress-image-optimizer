<?php
/*
 * The cache compartment: "page not cached", "served old", "always MISS", "no-store".
 *
 * The store verdict itself runs only inside a render (it needs the rendered buffer), so for a URL
 * this reads its outcome: the copies on disk per device and family (through the copy owner's own
 * names), the page cache setting, whether the URL's query may be cached at all, the update window
 * (read, never healed), a live warm run, and the last store-verdict receipt for the key. The opt-in
 * probe asks the site for the page as a logged-out visitor and reads the headers it answers with.
 */
if (!class_exists('wps_ic_doctor_cache')) {
    class wps_ic_doctor_cache extends wps_ic_doctor_compartment
    {
        const NAME = 'cache';
        const TITLE = 'Page cache';
        const SYMPTOMS = 'page not cached; served old; always MISS; no-store';
        const EVENTS = ['store-verdict', 'copy-', 'mirror-', 'stale-serve-', 'warm-', 'hit-kick', 'cc-headers-sent', 'wpcache-verdict',
            'purge-soft-', 'purge-page'];

        /** The device prefix the copy writer and readers use. */
        const DEVICE_PREFIX = ['desktop' => '', 'mobile' => 'mobile_'];

        /** The headers a probe reports: the store verdict, the cache layers and the update window. */
        const PROBE_HEADERS = ['x-wpc-cc', 'cache-control', 'server-timing', 'x-cache-by', 'x-wpc-cache', 'x-wpc-update-window', 'x-wpc-cache-save', 'age', 'cf-cache-status'];

        /** The user agents a probe sends per device. */
        const PROBE_AGENTS = [
            'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 wpc-doctor',
            'mobile'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 wpc-doctor',
        ];

        public function collect(wps_ic_doctor_context $ctx)
        {
            $facts = [];
            $artifacts = [];
            $now = $ctx->now;
            $facts[] = self::fact('url key', $ctx->key === '' ? 'unresolved' : $ctx->key, 'fn:wps_ic_url_key::setup');
            $cacheOn = !empty($ctx->settings['cache']['advanced']) && (string) $ctx->settings['cache']['advanced'] !== '0';
            $facts[] = self::fact('page cache (setting)', $cacheOn ? 'on' : 'off', 'option:' . WPS_IC_SETTINGS . '.cache.advanced');

            $query = (string) parse_url($ctx->url, PHP_URL_QUERY);
            $queryOk = class_exists('wps_ic_url_key') ? wps_ic_url_key::queryIsCacheable($query, 'read') : null;
            $facts[] = self::fact('query may be cached', $query === '' ? 'no query' : ($queryOk === null ? self::unavailable('wps_ic_url_key') : $queryOk), 'fn:wps_ic_url_key::queryIsCacheable');
            $facts[] = self::fact('cache by cookie', ['option' => get_option('wps_ic_cache_cookies', []), 'baked' => defined('WPC_CACHE_COOKIES') ? WPC_CACHE_COOKIES : 'not baked'], 'option:wps_ic_cache_cookies, const:WPC_CACHE_COOKIES');
            $facts[] = self::fact('cache by query parameter', class_exists('wps_ic_url_key') ? wps_ic_url_key::cacheQueryParams() : self::unavailable('wps_ic_url_key'), 'fn:wps_ic_url_key::cacheQueryParams');

            // The copies, per device: the family a reader serves and the files it consists of.
            $copies = [];
            $haveCopy = false;
            $localOnly = [];
            $staleOnly = [];
            if ($ctx->cache_dir === '' || !function_exists('wpc_copy_names')) {
                $facts[] = self::fact('copies', $ctx->cache_dir === '' ? 'no url key' : self::unavailable('wpc_copy_names'), 'fn:wpc_copy_names');
            } else {
                foreach ($ctx->devices as $device) {
                    $prefix = self::DEVICE_PREFIX[$device];
                    $family = wpc_copy_family_on_disk($ctx->cache_dir, $prefix);
                    $files = [];
                    foreach (wpc_copy_names($ctx->cache_dir, $prefix, $family) as $role => $path) {
                        $state = self::file_state($path, $now);
                        if ($state !== null) {
                            $files[$role] = $state;
                            $artifacts[] = $state;
                        }
                    }
                    $fresh = isset($files['html']) || isset($files['gzip']);
                    $stale = isset($files['stale_html']) || isset($files['stale_gzip']);
                    $haveCopy = $haveCopy || $fresh || $stale;
                    if (($fresh || $stale) && $family !== '') {
                        $localOnly[] = $device;
                    }
                    if ($stale && !$fresh) {
                        $staleOnly[] = $device;
                    }
                    $copies[$device] = ['family' => $family === '' ? 'public' : 'local-only', 'fresh' => $fresh, 'stale' => $stale,
                        'age_s' => isset($files['gzip']) ? $files['gzip']['age_s'] : (isset($files['html']) ? $files['html']['age_s'] : null)];
                }
                $facts[] = self::fact('copies', $copies, 'fn:wpc_copy_family_on_disk + wpc_copy_names under ' . self::relative($ctx->cache_dir));
            }

            // The static mirror Apache serves, when the mirror is on.
            $mirrorOn = function_exists('apply_filters') ? (bool) apply_filters('wpc_static_serve', defined('WPC_STATIC_SERVE') && WPC_STATIC_SERVE) : false;
            $mirror = 'off';
            if ($mirrorOn && defined('WPS_IC_CACHE')) {
                $host = (string) parse_url(function_exists('home_url') ? home_url() : $ctx->url, PHP_URL_HOST);
                $path = rtrim((string) parse_url($ctx->url, PHP_URL_PATH), '/');
                $mirror = [];
                if ($host !== '' && strpos($path, '..') === false) {
                    foreach ($ctx->devices as $device) {
                        $state = self::file_state(rtrim(WPS_IC_CACHE, '/') . '/' . $host . $path . '/' . self::DEVICE_PREFIX[$device] . 'index.html_gzip', $now);
                        $mirror[$device] = $state === null ? 'none' : $state;
                    }
                }
            }
            $facts[] = self::fact('static mirror', $mirror, 'filter:wpc_static_serve, file:wp-cio/<host><path>/index.html_gzip');

            $windowUntil = (int) get_option('wpc_update_window_until', 0);
            $window = function_exists('wpc_update_window_state') ? wpc_update_window_state($windowUntil, $now) : self::unavailable('wpc_update_window_state');
            $facts[] = self::fact('update window', ['state' => $window, 'until' => self::at($windowUntil)], 'option:wpc_update_window_until + fn:wpc_update_window_state');
            $facts[] = self::fact('warm run', function_exists('wpc_warm_run_status') ? wpc_warm_run_status() : self::unavailable('wpc_warm_run_status'), 'fn:wpc_warm_run_status');
            $lastVerdict = self::last_entry($ctx, 'store-verdict');
            $facts[] = self::fact('last store verdict', $lastVerdict === null ? 'none in the window (sampled once per page per 120 s)' : ['at' => self::at((int) ($lastVerdict['t'] ?? 0)), 'layers' => $lastVerdict['layers'] ?? []], 'receipt:store-verdict');

            if (!$cacheOn) {
                $verdict = self::verdict('fail', 'cache-off', 'the page cache is off in the settings');
            } elseif ($queryOk === false) {
                $verdict = self::verdict('fail', 'query-not-cacheable', 'this URL\'s query string is never stored or served from cache: a parameter is neither a tracking parameter nor on the site\'s lists');
            } elseif ($window === 'open') {
                $verdict = self::verdict('warn', 'update-window', 'inside the update window until ' . self::at($windowUntil) . ': renders are stored only when armed');
            } elseif (!$haveCopy) {
                $reason = $lastVerdict !== null ? wpc_doctor_cell($lastVerdict['layers'] ?? []) : 'no store-verdict receipt in the window';
                $verdict = self::verdict('warn', 'no-copy', 'no copy on disk; last store verdict: ' . $reason);
            } elseif ($localOnly) {
                $verdict = self::verdict('warn', 'local-only', 'the copy is local-only on ' . implode(', ', $localOnly) . ' (crit-less: private header, no edge copy)');
            } elseif ($staleOnly) {
                $verdict = self::verdict('warn', 'stale', 'only a stale-marked copy on ' . implode(', ', $staleOnly) . ': served while it rewarms');
            } else {
                $verdict = self::verdict('ok', 'cached', 'a public copy on ' . implode(', ', array_keys($copies)));
            }
            return ['verdict' => $verdict, 'facts' => $facts, 'artifacts' => $artifacts];
        }

        /**
         * One cookie-less GET per device as a logged-out visitor, then a second to see whether the
         * first stored a copy. X-WPC-Diag marks it a diagnostic render, so it never asks for crit;
         * otherwise it is a visit, and it can store a copy exactly as a visit would.
         */
        public function probe(wps_ic_doctor_context $ctx)
        {
            return self::fetch_pages($ctx, $ctx->url, true);
        }

        /** The self-fetch both page probes share: status, the listed headers, size; $twice asks again. */
        public static function fetch_pages(wps_ic_doctor_context $ctx, $url, $twice)
        {
            $started = time();
            $out = ['note' => 'a logged-out render of this site; it may store a page copy and start WP-Cron exactly as a visit would', 'started' => gmdate('Y-m-d H:i:s\Z', $started)];
            if (!function_exists('wp_remote_get')) {
                $out['error'] = 'unavailable: wp_remote_get not loaded';
                return $out;
            }
            foreach ($ctx->devices as $device) {
                $rounds = [];
                foreach ($twice ? [1, 2] : [1] as $round) {
                    $row = self::fetch_once($url, $device);
                    // Page HTML never enters a report: headers, status and size only.
                    unset($row['body']);
                    $rounds[] = $row;
                }
                $out[$device] = $rounds;
            }
            $out['ended'] = gmdate('Y-m-d H:i:s\Z');
            $out['receipts_since_start'] = self::receipts_since($ctx, $started);
            return $out;
        }

        /**
         * The journal entries for this page (or site-wide) written since $since, by event with a
         * count: what the probe's own renders wrote, told apart by time. A read of the log.
         */
        public static function receipts_since(wps_ic_doctor_context $ctx, $since)
        {
            $events = [];
            if (!function_exists('wpc_cflog_lines')) {
                return 'unavailable: wpc_cflog_lines not loaded';
            }
            foreach (wpc_cflog_lines(500, (int) $since) as $line) {
                $entry = json_decode((string) $line, true);
                if (is_array($entry) && $ctx->entry_is_mine($entry)) {
                    $event = (string) ($entry['event'] ?? '');
                    $events[$event] = ($events[$event] ?? 0) + 1;
                }
            }
            return $events;
        }

        /**
         * One GET, through the render guard's one exemption for a fetch a person is waiting on. The
         * row carries the body for the caller to read; the caller drops it before it is reported.
         */
        public static function fetch_once($url, $device)
        {
            $args = ['timeout' => 10, 'redirection' => 0, 'sslverify' => false, 'cookies' => [],
                'headers' => ['X-WPC-Diag' => '1', 'User-Agent' => self::PROBE_AGENTS[$device] ?? self::PROBE_AGENTS['desktop'], 'Cache-Control' => 'no-cache']];
            $began = microtime(true);
            if (function_exists('wpc_render_guard_self_fetch')) {
                wpc_render_guard_self_fetch($url);
            }
            try {
                $response = wp_remote_get($url, $args);
            } finally {
                if (function_exists('wpc_render_guard_self_fetch')) {
                    wpc_render_guard_self_fetch('');
                }
            }
            $row = ['ms' => round((microtime(true) - $began) * 1000)];
            if (function_exists('is_wp_error') && is_wp_error($response)) {
                $row['error'] = $response->get_error_message();
                return $row;
            }
            $row['status'] = (int) wp_remote_retrieve_response_code($response);
            $row['bytes'] = strlen((string) wp_remote_retrieve_body($response));
            $headers = [];
            foreach (self::PROBE_HEADERS as $name) {
                $value = wp_remote_retrieve_header($response, $name);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                if ((string) $value !== '') {
                    $headers[$name] = (string) $value;
                }
            }
            $row['headers'] = $headers;
            // A file hit answers with the drop-in's or the reader's hit marker.
            $row['hit'] = stripos($headers['server-timing'] ?? '', 'desc=hit') !== false || isset($headers['x-cache-by']);
            $row['body'] = (string) wp_remote_retrieve_body($response);
            return $row;
        }
    }
}
