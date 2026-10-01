<?php
/*
 * The render compartment: "the page looks wrong", "an optimization did not apply on this page",
 * "logged in it looks fine".
 *
 * What decides how this page is rendered before the stage table runs: the page's own settings,
 * whether the URL matches an exclude pattern (through the plugin's own matcher), whether logged-in
 * visitors bypass the pipeline, the legacy levers, and the settings the lane is chosen from (the
 * lane itself is chosen inside the rewriter's constructor, which has no reader of its own, so the
 * inputs are shown and the probe's trace answers what ran). From the journal: the last slow-render
 * profile for the key and the render's shed, breaker and never-blank receipts. The opt-in probe
 * renders the page with ?wpc_stages=1 and reads the stage trace back as rows.
 */
if (!class_exists('wps_ic_doctor_render')) {
    class wps_ic_doctor_render extends wps_ic_doctor_compartment
    {
        const NAME = 'render';
        const TITLE = 'Render';
        const SYMPTOMS = 'the page looks wrong; an optimization did not apply on this page; logged in it looks fine';
        const EVENTS = ['render-', 'rewrite-', 'script-fetch-', 'never-blank-', 'pl-sched-', 'net-ceiling-'];

        public function collect(wps_ic_doctor_context $ctx)
        {
            $facts = [];
            $facts[] = self::fact('page', $ctx->page_id === 0 ? 'no post resolves this URL' : $ctx->page_id, 'fn:url_to_postid (home by url key)');
            $facts[] = self::fact('page settings', $ctx->page_excludes === [] ? 'none' : $ctx->page_excludes, 'option:wpc-excludes.page_excludes.' . $ctx->page_id);

            $urlExcludes = get_option('wpc-url-excludes');
            $patterns = is_array($urlExcludes) && !empty($urlExcludes['exclude-url-from-all']) ? $urlExcludes['exclude-url-from-all'] : [];
            $matched = false;
            if (!$patterns) {
                $facts[] = self::fact('URL exclude match', 'no patterns', 'option:wpc-url-excludes.exclude-url-from-all');
            } elseif (!function_exists('wpc_url_is_excluded')) {
                $facts[] = self::fact('URL exclude match', self::unavailable('wpc_url_is_excluded'), 'fn:wpc_url_is_excluded');
            } else {
                // The rewriter matches host + path without the query (wps_cdn_rewrite::dontRunif).
                $hostPath = (string) parse_url($ctx->url, PHP_URL_HOST) . (string) parse_url($ctx->url, PHP_URL_PATH);
                $matched = wpc_url_is_excluded($hostPath, $patterns);
                $facts[] = self::fact('URL exclude match', $matched === false ? 'none' : $matched, 'fn:wpc_url_is_excluded');
            }
            $safeMode = function_exists('wpc_safe_mode') ? wpc_safe_mode() : null;
            $facts[] = self::fact('safe mode (every render is shed)', $safeMode === null ? self::unavailable('wpc_safe_mode') : $safeMode, 'fn:wpc_safe_mode');
            $facts[] = self::fact('logged-in visitors bypass the pipeline', (bool) apply_filters('wpc_logged_in_bypass', true), 'filter:wpc_logged_in_bypass');
            $facts[] = self::fact('legacy levers', function_exists('wpc_legacy_lever_states') ? wpc_legacy_lever_states() : self::unavailable('wpc_legacy_lever_states'), 'fn:wpc_legacy_lever_states');

            $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : [];
            $facts[] = self::fact('lane inputs', [
                'live-cdn'        => $ctx->settings['live-cdn'] ?? '-',
                'allow_live'      => (bool) get_option('wps_ic_allow_live'),
                'cf_settings'     => is_array($cf) && isset($cf['settings']) ? $cf['settings'] : 'none',
                'zone'            => (string) get_option('ic_cdn_zone_name', ''),
                'custom_cname'    => (string) get_option('ic_custom_cname', ''),
                'page_cdn'        => $ctx->page_excludes['cdn'] ?? '-',
            ], 'options read by wps_cdn_rewrite (the lane is chosen there; the probe trace names what ran)');

            $slow = null;
            foreach ($ctx->journal as $entry) {
                if (($entry['event'] ?? '') === 'auto-mode' && ($entry['layers']['ev'] ?? '') === 'slow-render' && $ctx->entry_is_mine($entry)) {
                    $slow = $entry;
                }
            }
            $facts[] = self::fact('last slow render', $slow === null ? 'none in the window' : ['at' => self::at((int) ($slow['t'] ?? 0)), 'layers' => $slow['layers']], 'receipt:auto-mode {ev:slow-render}');
            $counts = [];
            foreach (['never-blank-restore', 'rewrite-shed', 'render-budget-shed', 'render-breaker-tripped', 'rewrite-skip-lowvalue'] as $event) {
                $counts[$event] = self::count_entries($ctx, $event);
            }
            $facts[] = self::fact('render receipts in the window', $counts, 'receipt:never-blank-restore, rewrite-shed, render-budget-shed, render-breaker-tripped, rewrite-skip-lowvalue');

            if ($matched !== false) {
                $verdict = self::verdict('warn', 'url-excluded', 'the URL matches the exclude pattern "' . $matched . '": the plugin leaves this page alone');
            } elseif ($safeMode === true) {
                $verdict = self::verdict('warn', 'safe-mode', 'safe mode is on: every render is shed and pages are served as WordPress produced them');
            } elseif ($counts['never-blank-restore'] > 0) {
                $verdict = self::verdict('fail', 'never-blank', sprintf('a render threw and the untouched page was served %d times in the window', $counts['never-blank-restore']));
            } elseif ($counts['rewrite-shed'] + $counts['render-budget-shed'] + $counts['render-breaker-tripped'] > 0) {
                $verdict = self::verdict('warn', 'shed', 'renders were shed under pressure in the window: some visitors got the page with passes skipped');
            } elseif ($ctx->page_excludes !== []) {
                $verdict = self::verdict('ok', 'page-settings', 'this page has its own settings: ' . wpc_doctor_cell($ctx->page_excludes));
            } else {
                $verdict = self::verdict('ok', 'defaults', 'no page settings, no exclude match: the site settings decide');
            }
            return ['verdict' => $verdict, 'facts' => $facts, 'artifacts' => []];
        }

        /**
         * One cookie-less GET of <url>?wpc_stages=1 per device, the stage trace read back as rows.
         * A diagnostic render (X-WPC-Diag) never asks for crit; it can store a copy as a visit would.
         * No trace means the render bailed or left in the envelope (or a cached copy answered); the
         * status and size are reported and nothing further is guessed.
         */
        public function probe(wps_ic_doctor_context $ctx)
        {
            $started = time();
            $out = ['note' => 'a logged-out render of this site; it may store a page copy and start WP-Cron exactly as a visit would', 'started' => gmdate('Y-m-d H:i:s\Z', $started)];
            if (!function_exists('wp_remote_get')) {
                $out['error'] = 'unavailable: wp_remote_get not loaded';
                return $out;
            }
            $url = $ctx->url . (strpos($ctx->url, '?') === false ? '?' : '&') . 'wpc_stages=1';
            foreach ($ctx->devices as $device) {
                $row = wps_ic_doctor_cache::fetch_once($url, $device);
                $rows = self::stage_rows(isset($row['body']) ? (string) $row['body'] : '');
                unset($row['body']);
                if ($rows === null) {
                    $row['trace'] = 'none: the render bailed or left in the envelope, or a cached copy answered';
                } else {
                    $ran = 0;
                    $delta = 0;
                    foreach ($rows as $stage) {
                        if ($stage['skip'] === '') {
                            $ran++;
                        }
                        $delta += $stage['bytes'];
                    }
                    $row['trace'] = ['stages' => count($rows), 'ran' => $ran, 'bytes' => $delta, 'rows' => $rows];
                }
                $out[$device] = $row;
            }
            $out['ended'] = gmdate('Y-m-d H:i:s\Z');
            // A missing trace is explained by what the render wrote (rewrite-shed, a bail's line).
            $out['receipts_since_start'] = wps_ic_doctor_cache::receipts_since($ctx, $started);
            return $out;
        }

        /**
         * The <!-- wpc-stages: ... --> comment of a page as rows [stage, bytes, ms, skip], or null
         * when the page carries none. The runner spaces out every double dash inside the comment
         * ('- -'), which a skip reason undoes here.
         */
        public static function stage_rows($html)
        {
            $start = strrpos((string) $html, '<!-- wpc-stages:');
            if ($start === false) {
                return null;
            }
            $end = strpos($html, '-->', $start);
            $body = substr($html, $start + 16, $end === false ? null : $end - $start - 16);
            $rows = [];
            foreach (explode("\n", (string) $body) as $line) {
                if (preg_match('/^(\S+)\s+([+-]?\d+)\s+([\d.]+)ms\s?(.*)$/', rtrim($line), $m)) {
                    $rows[] = ['stage' => $m[1], 'bytes' => (int) $m[2], 'ms' => (float) $m[3], 'skip' => str_replace('- -', '--', trim($m[4]))];
                }
            }
            return $rows;
        }
    }
}
