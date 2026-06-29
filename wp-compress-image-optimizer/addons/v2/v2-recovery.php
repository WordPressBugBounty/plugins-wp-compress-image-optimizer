<?php
/**
 * v2-recovery.php — Phase-B drain recovery + diagnostics (v7.03.100)
 *
 * Recovers the Phase-B pull/drain path when a site's local WPC state has been
 * wiped or has drifted out of sync with the orchestrator (e.g. a DB-cleanup
 * plugin cleared the ic_* meta, or the pull cursor advanced past the manifest
 * so the drain pulls nothing — the welliathome "only Phase A lands / drain=null"
 * signature). No reconnect required: the API key + WP attachments are the only
 * things that must survive — everything below is rebuildable from them.
 *
 * Two recovery modes:
 *
 *   'fresh'  (recovery B): SKIP the orch backlog. Advances the pull cursor to ~now
 *            and clears local pull-state (journal, pending transients, on-upload
 *            queue), so the site re-optimizes clean instead of downloading a large
 *            STALE backlog. Pair with the orch flushing its manifest. Then run a
 *            normal Bulk Optimize to rebuild.
 *
 *   'resync' (recovery A): RE-PULL everything the orch still has. Resets the cursor
 *            to 0 and fires the drain, recovering already-encoded variants WITHOUT
 *            re-encoding (dedups against on-disk ic_local_variants).
 *
 * Triggers (no WP-CLI required):
 *   - Admin URL (admin-gated): just visit /wp-admin/?wpc_v2_pull_recover=status — it
 *     prints the pull-state AND one-click "Run Fresh" / "Run Resync" buttons with the
 *     nonce baked in. 'status' is read-only/nonce-free; 'fresh'+'resync' mutate and use
 *     the button's nonce.
 *   - WP-CLI (if available): wp wpc-v2-recover <fresh|resync|status>
 *
 * Read-only diagnostic ('status') dumps the local pull-state so the first failing
 * step is obvious without a separate debug build: cursor, journal file count,
 * pending-transient count, on-upload queue depth, and curl_multi availability.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_pull_recover')) {
    /**
     * Clear local pull-state and re-point the cursor.
     *
     * @param string $mode 'fresh' (skip backlog) | 'resync' (re-pull all)
     * @return array Summary of what was cleared/changed.
     */
    function wpc_v2_pull_recover($mode = 'resync')
    {
        $mode = ($mode === 'fresh') ? 'fresh' : 'resync';
        $out  = ['mode' => $mode];

        // (v7.03.106) Re-enable the pull flag — the welliathome root cause: the tick bails
        // 'flag_off' when wpc_v2_pull_enabled() is false (DB wipe cleared the site option /
        // its zone+cdn inputs). Without this the drain never runs no matter the cursor.
        update_site_option('wpc_v2_pull_enabled', 1);
        $out['pull_flag'] = 'enabled (wpc_v2_pull_enabled=1)';

        // 1) File-based drain journal (queued-for-drain entries). Stale entries
        //    here would try to place bytes the orch has flushed — clear them.
        if (function_exists('wpc_v2_journal_list_files')) {
            $deleted = 0;
            foreach ((array) wpc_v2_journal_list_files(100000) as $f) {
                if (is_string($f) && @file_exists($f) && @unlink($f)) {
                    $deleted++;
                }
            }
            $out['journal_files_deleted'] = $deleted;
        }

        // 2) On-upload compress queue (wpc_compress_queue).
        delete_option('wpc_compress_queue');

        // 3) Pending-variant transients (best effort — they also TTL out, and on
        //    an external object cache they aren't in the options table).
        global $wpdb;
        $out['pending_transients_deleted'] = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
              WHERE option_name LIKE '\\_transient\\_wpc\\_v2\\_pending\\_%'
                 OR option_name LIKE '\\_transient\\_timeout\\_wpc\\_v2\\_pending\\_%'"
        );

        // 4) The cursor — the gate that decides what the NEXT drain pulls.
        if ($mode === 'fresh') {
            // Jump forward so GET /optimize-v2/manifest?since=<now> returns only
            // entries created AFTER this reset → the stale backlog is skipped even
            // if the orch flush is partial/in-flight.
            $now_ms = (int) round(microtime(true) * 1000);
            update_option('wpc_v2_pull_cursor_ms', $now_ms, false);
            $out['cursor'] = 'advanced_to_now';
            $out['cursor_ms'] = $now_ms;
            $out['next'] = 'run Bulk Optimize to re-optimize the library clean';
        } else {
            // Back to 0 → re-pull the full manifest (wpc_v2_pull_get_cursor()
            // defaults to 0 when the option is absent). set_cursor() bails on <=0,
            // so we delete the option rather than set it.
            delete_option('wpc_v2_pull_cursor_ms');
            $out['cursor'] = 'reset_to_0';
            if (function_exists('wpc_v2_pull_drain_fire')) {
                wpc_v2_pull_drain_fire();
                $out['drain'] = 'fired';
            }
        }

        error_log('[WPC V2 Recovery] ' . wp_json_encode($out));
        return $out;
    }
}

if (!function_exists('wpc_v2_pull_status')) {
    /**
     * Read-only snapshot of the local pull-state (diagnostic).
     * @return array
     */
    function wpc_v2_pull_status()
    {
        global $wpdb;
        $queue  = get_option('wpc_compress_queue', []);
        $cursor = (int) get_option('wpc_v2_pull_cursor_ms', 0);
        return [
            'pull_enabled'       => (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled()) ? 'on' : 'OFF — drain bails flag_off; click Run drain inline to fix',
            'cursor_ms'          => $cursor,
            'cursor_human'       => $cursor > 0 ? gmdate('Y-m-d H:i:s', (int) ($cursor / 1000)) . ' UTC' : 'unset (0)',
            'journal_files'      => function_exists('wpc_v2_journal_list_files')
                                        ? count((array) wpc_v2_journal_list_files(100000))
                                        : null,
            'pending_transients' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                  WHERE option_name LIKE '\\_transient\\_wpc\\_v2\\_pending\\_%'"
            ),
            'compress_queue'     => is_array($queue) ? count($queue) : 0,
            'curl_multi_init'    => function_exists('curl_multi_init'),
        ];
    }
}

if (!function_exists('wpc_v2_pull_draintest')) {
    /**
     * (v7.03.103) Verbose one-shot drain diagnostic — runs the live manifest GET
     * (since=cursor) + an egress HEAD on the first returned variant, reporting each
     * step. Read-only: no placement, no cursor change. Surfaces the exact failing
     * step in a joint debug session with no WP_DEBUG_LOG / file access needed.
     */
    function wpc_v2_pull_draintest()
    {
        $cursor = (int) get_option('wpc_v2_pull_cursor_ms', 0);
        $out = [
            'pull_enabled'   => [
                'result'   => (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled()) ? 'ON' : 'OFF — tick bails flag_off (THE BUG)',
                'option'    => get_site_option('wpc_v2_pull_enabled', '(absent -> default = zone && live-cdn)'),
                'zone_ok'  => (function_exists('wpc_v2_get_zone_id') && wpc_v2_get_zone_id()) ? 'yes' : 'NO',
                'live_cdn' => (defined('WPS_IC_SETTINGS') && is_array($s = get_option(WPS_IC_SETTINGS)) && !empty($s['live-cdn']) && (string) $s['live-cdn'] === '1') ? 'yes' : 'NO',
            ],
            'cursor_ms'      => $cursor,
            'cursor_human'   => $cursor > 0 ? gmdate('Y-m-d H:i:s', (int) ($cursor / 1000)) . ' UTC' : 'unset (0)',
            'orch_url'       => function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '(unresolved)',
            'apikey_present' => function_exists('wpc_v2_get_apikey') ? (wpc_v2_get_apikey() !== '' ? 'yes' : 'NO') : '(unknown)',
            'curl_multi'     => function_exists('curl_multi_init'),
        ];

        if (!function_exists('wpc_v2_pull_manifest_fetch')) {
            $out['manifest_GET'] = 'ERROR: wpc_v2_pull_manifest_fetch() missing';
            error_log('[WPC V2 DrainTest] ' . wp_json_encode($out));
            return $out;
        }

        // Step 1 — the live manifest GET (since=cursor). Most likely failure point.
        $fetch = wpc_v2_pull_manifest_fetch($cursor, 50, 0);
        $out['manifest_GET'] = [
            'ok'            => !empty($fetch['ok']),
            'error'         => isset($fetch['error']) ? $fetch['error'] : null,
            'entries'       => isset($fetch['variants']) ? count((array) $fetch['variants']) : 0,
            'pages_fetched' => isset($fetch['pages_fetched']) ? (int) $fetch['pages_fetched'] : 0,
            'high_water_ms' => isset($fetch['cursor_high_water_ms']) ? (int) $fetch['cursor_high_water_ms'] : 0,
        ];

        // Step 2 — dump the first entry's FULL shape + run the SAME 6-field validation
        // the drain uses (v2-pull-manifest.php:413-420), so the exact skip reason is
        // on-page. A failing field here = every entry skipped = drain=null.
        if (!empty($fetch['variants'][0]) && is_array($fetch['variants'][0])) {
            $v0 = $fetch['variants'][0];
            $out['first_entry_raw'] = $v0; // full shape — see the orch's ACTUAL field names

            $check = [
                'imageID>0' => isset($v0['imageID'])   && (int) $v0['imageID'] > 0,
                'sizeLabel' => isset($v0['sizeLabel']) && (string) $v0['sizeLabel'] !== '',
                'format'    => isset($v0['format'])    && (string) $v0['format'] !== '',
                'fetchUrl'  => isset($v0['fetchUrl'])  && (string) $v0['fetchUrl'] !== '',
                'bytes>0'   => isset($v0['bytes'])     && (int) $v0['bytes'] > 0,
                'sha256'    => isset($v0['sha256'])    && (string) $v0['sha256'] !== '',
            ];
            $failing = array_keys(array_filter($check, function ($ok) { return !$ok; }));
            $out['validation'] = [
                'required_fields' => $check,
                'would_skip'      => !empty($failing),
                'failing'         => !empty($failing) ? $failing : '(none — entry is valid; the break is downstream at placement)',
            ];

            // Egress test, now with the CORRECT key.
            $url = isset($v0['fetchUrl']) ? (string) $v0['fetchUrl'] : '';
            if ($url !== '') {
                $head = wp_remote_head($url, ['timeout' => 8]);
                $out['egress_test'] = is_wp_error($head)
                    ? ['ok' => false, 'error' => $head->get_error_message()]
                    : ['ok' => true, 'http' => (int) wp_remote_retrieve_response_code($head), 'content_length' => wp_remote_retrieve_header($head, 'content-length')];
            }
        } elseif (!empty($out['manifest_GET']['ok']) && $out['manifest_GET']['entries'] === 0) {
            $out['note'] = 'GET ok but 0 entries — orch has nothing newer than the cursor. Re-optimize one image first, then re-run this.';
        }

        error_log('[WPC V2 DrainTest] ' . wp_json_encode($out));
        return $out;
    }
}

if (!function_exists('wpc_v2_pull_drainrun')) {
    /**
     * (v7.03.105) Run the REAL drain pipeline INLINE (synchronous) and report whether
     * bytes land — bypasses the loopback/dispatch guards so a blocked loopback or a
     * stuck dispatch transient can't hide the result. Mutating: places variants +
     * advances the cursor. Both the definitive placement test AND a recovery when the
     * pipeline is healthy (the case the draintest now points to on welliathome).
     */
    function wpc_v2_pull_drainrun()
    {
        $out = ['ran' => 'inline pipeline (tick + journal drain, no loopback)'];

        // (v7.03.106) ROOT CAUSE: the tick bails 'flag_off' when wpc_v2_pull_enabled() is
        // false. On welliathome the DB wipe cleared the `wpc_v2_pull_enabled` site option
        // (and/or its zone+cdn default inputs), so the drain never ran. Re-enable it
        // explicitly so the pipeline actually executes (and stays on for the live triggers).
        $out['pull_flag_before'] = (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled()) ? 'on' : 'OFF (this was the bug)';
        update_site_option('wpc_v2_pull_enabled', 1);
        $out['pull_flag_now'] = 'enabled (wpc_v2_pull_enabled=1)';

        $out['drain_running_transient'] = get_transient('wpc_v2_drain_running') ?: 'none';
        $out['cursor_before'] = (int) get_option('wpc_v2_pull_cursor_ms', 0);

        // Resolve the first entry's on-disk target BEFORE, to confirm placement after.
        $target = '';
        if (function_exists('wpc_v2_pull_manifest_fetch')) {
            $f = wpc_v2_pull_manifest_fetch($out['cursor_before'], 5, 0);
            if (!empty($f['variants'][0]['imageID']) && !empty($f['variants'][0]['filename'])) {
                $abs = function_exists('get_attached_file') ? get_attached_file((int) $f['variants'][0]['imageID']) : '';
                if ($abs) { $target = dirname($abs) . '/' . (string) $f['variants'][0]['filename']; }
            }
        }
        $out['sample_target']        = $target !== '' ? $target : '(could not resolve imageID -> path)';
        $out['sample_target_before'] = ($target !== '' && file_exists($target)) ? 'exists' : 'missing';

        // 1) Tick — GET + queue + journal write (also fires a loopback; harmless here).
        $out['tick_result'] = function_exists('wpc_v2_pull_manifest_tick')
            ? wpc_v2_pull_manifest_tick(50, 0)
            : '(wpc_v2_pull_manifest_tick missing)';

        // 2) Journal drain INLINE — the actual fetch + write, bypassing the loopback.
        if (function_exists('wpc_v2_journal_drain_run')) {
            wpc_v2_journal_drain_run();
            $out['journal_drain'] = 'ran inline';
        } else {
            $out['journal_drain'] = '(wpc_v2_journal_drain_run missing)';
        }

        // 3) Did the sample file land?
        $out['sample_target_after'] = $target !== ''
            ? (file_exists($target) ? 'PLACED OK' : 'STILL MISSING')
            : '(n/a)';
        $out['cursor_after'] = (int) get_option('wpc_v2_pull_cursor_ms', 0);

        error_log('[WPC V2 DrainRun] ' . wp_json_encode($out));
        return $out;
    }
}

// ── Admin URL trigger (manage_options + nonce) ──────────────────────────────
add_action('admin_init', function () {
    if (empty($_GET['wpc_v2_pull_recover'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $mode = sanitize_key(wp_unslash($_GET['wpc_v2_pull_recover']));
    if (!in_array($mode, ['fresh', 'resync', 'status', 'draintest', 'drainrun'], true)) {
        return;
    }
    // (v7.03.101/103) 'status' + 'draintest' are read-only → no nonce (just visit
    // the URL, admin-gated) so they're usable on hosts without WP-CLI. 'fresh'/
    // 'resync' mutate → require the nonce, which the page's own buttons supply.
    if ($mode === 'status') {
        $res   = wpc_v2_pull_status();
        $title = 'status';
    } elseif ($mode === 'draintest') {
        $res   = wpc_v2_pull_draintest();
        $title = 'drain test (verbose)';
    } else {
        if (empty($_GET['_wpcnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpcnonce'])), 'wpc_v2_pull_recover')) {
            wp_die('WPC recovery: that link expired. Go back to the status page and click the button again.');
        }
        if ($mode === 'drainrun') {
            $res   = wpc_v2_pull_drainrun();
            $title = 'drain run (inline)';
        } else {
            $res   = wpc_v2_pull_recover($mode);
            $title = 'recovery — ' . $mode;
        }
    }

    // Render status/result + one-click action buttons (the nonce is baked in).
    $base    = admin_url('index.php');
    $nonce   = wp_create_nonce('wpc_v2_pull_recover');
    $u_fresh  = esc_url(add_query_arg(['wpc_v2_pull_recover' => 'fresh',  '_wpcnonce' => $nonce], $base));
    $u_resync = esc_url(add_query_arg(['wpc_v2_pull_recover' => 'resync', '_wpcnonce' => $nonce], $base));
    $u_status = esc_url(add_query_arg(['wpc_v2_pull_recover' => 'status'], $base));
    $u_draintest = esc_url(add_query_arg(['wpc_v2_pull_recover' => 'draintest'], $base));
    $u_drainrun  = esc_url(add_query_arg(['wpc_v2_pull_recover' => 'drainrun', '_wpcnonce' => $nonce], $base));
    $btn = 'display:inline-block;margin:4px 10px 4px 0;padding:9px 16px;border-radius:4px;text-decoration:none;font:600 13px -apple-system,sans-serif;';

    $html  = '<h2>WP Compress — Phase-B drain: ' . esc_html($title) . '</h2>';
    $html .= '<pre style="background:#f6f7f7;padding:14px;border:1px solid #ccd0d4;border-radius:4px;font:13px monospace;white-space:pre-wrap;">'
           . esc_html(wp_json_encode($res, JSON_PRETTY_PRINT)) . '</pre>';
    $html .= '<p style="margin-top:16px;">';
    $html .= '<a href="' . $u_draintest . '" style="' . $btn . 'background:#00a32a;color:#fff;">&#9654; Run drain test (verbose)</a>';
    $html .= '<a href="' . $u_drainrun . '" style="' . $btn . 'background:#8c1d8c;color:#fff;" '
           . 'onclick="return confirm(\'Run the REAL drain inline now? Fetches + writes the queued variant bytes to disk (recovers them). Safe — it only places what the orch already has.\')">&#9654;&#9654; Run drain inline (place bytes)</a>';
    $html .= '<a href="' . $u_fresh . '" style="' . $btn . 'background:#d63638;color:#fff;" '
           . 'onclick="return confirm(\'Run FRESH recovery? Advances the cursor past the backlog + clears local pull-state. Re-optimize with Bulk afterwards.\')">Run Fresh (skip backlog)</a>';
    $html .= '<a href="' . $u_resync . '" style="' . $btn . 'background:#2271b1;color:#fff;" '
           . 'onclick="return confirm(\'Run RESYNC? Resets the cursor to 0 + re-pulls everything the orchestrator still has.\')">Run Resync (re-pull all)</a>';
    $html .= '<a href="' . $u_status . '" style="' . $btn . 'background:#f0f0f1;color:#1d2327;border:1px solid #c3c4c7;">Refresh status</a>';
    $html .= '</p>';
    wp_die($html, 'WPC Phase-B Recovery', ['response' => 200]);
});

// ── WP-CLI: wp wpc-v2-recover <fresh|resync|status> ─────────────────────────
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wpc-v2-recover', function ($args) {
        $mode = isset($args[0]) ? (string) $args[0] : 'status';
        if ($mode === 'status') {
            WP_CLI::log(wp_json_encode(wpc_v2_pull_status(), JSON_PRETTY_PRINT));
            return;
        }
        if ($mode === 'draintest') {
            WP_CLI::log(wp_json_encode(wpc_v2_pull_draintest(), JSON_PRETTY_PRINT));
            return;
        }
        if ($mode === 'drainrun') {
            WP_CLI::log(wp_json_encode(wpc_v2_pull_drainrun(), JSON_PRETTY_PRINT));
            return;
        }
        if (!in_array($mode, ['fresh', 'resync'], true)) {
            WP_CLI::error("Usage: wp wpc-v2-recover <fresh|resync|status|draintest|drainrun>");
        }
        $res = wpc_v2_pull_recover($mode);
        WP_CLI::success('Phase-B recovery (' . $mode . '): ' . wp_json_encode($res));
    });
}

// ── (v7.03.111) CDN STATE DEBUG — front-end, admin-gated ──────────────────────────────────────────
// Visit (logged in as admin):  https://SITE/?wpc_cdn_debug=1
// Answers "why is WPC rewriting assets to the zone when the CDN is OFF?" The DECISIVE test compares the
// CACHED settings (object cache) against the RAW DB row, so we can tell apart three very different causes:
//   (a) CACHE STALE      — cached != DB  → object cache serving old settings (.110/.111 fix territory)
//   (b) SAVE NEVER STUCK — DB itself = on → the OFF toggle never persisted (NOT a cache issue)
//   (c) OVERRIDE         — DB = off but an override (wpc_force_natural / WPC_FORCE_NATURAL) forces it
// Runs on the FRONT END so it reflects the rewriter's ACTUAL resolved state for a real visitor request.
add_action('template_redirect', function () {
    if (empty($_GET['wpc_cdn_debug'])) { return; }
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) { return; }

    // (v7.03.113) CLEAR action — strip ALL per-page CDN overrides (the stale cdn='1' includes that
    // forced the CDN back on, and any cdn='0' excludes). Mutating → nonce-protected. Pages then follow
    // the GLOBAL CDN setting. The .112 guard already neutralizes a stale include; this cleans the data.
    if ($_GET['wpc_cdn_debug'] === 'clear') {
        if (empty($_GET['_wpcnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpcnonce'])), 'wpc_cdn_clear_overrides')) {
            wp_die('WPC: that link expired — reload the debug page and click the button again.');
        }
        $ex = get_option('wpc-excludes');
        $cleared = 0;
        if (is_array($ex) && !empty($ex['page_excludes']) && is_array($ex['page_excludes'])) {
            foreach ($ex['page_excludes'] as $k => $entry) {
                if (is_array($entry) && array_key_exists('cdn', $entry)) { unset($ex['page_excludes'][$k]['cdn']); $cleared++; }
            }
            update_option('wpc-excludes', $ex);
        }
        // Take effect immediately: drop the autoloaded option bucket + the excludes key, purge HTML cache.
        if (function_exists('wp_cache_delete')) { wp_cache_delete('alloptions', 'options'); wp_cache_delete('wpc-excludes', 'options'); }
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) { wps_ic_cache::removeHtmlCacheFiles('all'); }
        $back = esc_url(add_query_arg(array('wpc_cdn_debug' => '1'), home_url('/')));
        wp_die('<h2>WP Compress — per-page CDN overrides cleared</h2><p>Removed the per-page <code>cdn</code> override from <strong>' . (int) $cleared . '</strong> page entr' . ($cleared === 1 ? 'y' : 'ies') . '. Every page now follows the GLOBAL CDN setting.</p><p><a href="' . $back . '">&larr; Back to CDN debug</a></p>', 'WPC — overrides cleared', array('response' => 200));
    }

    global $wpdb;
    $opt = defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings';

    $cached   = get_option($opt);
    $raw_row  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
    $raw      = is_string($raw_row) ? maybe_unserialize($raw_row) : null;
    $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $opt));
    $g  = function ($a, $k) { return (is_array($a) && array_key_exists($k, $a)) ? var_export($a[$k], true) : '(unset)'; };
    $on = function ($v) { return in_array($v, array("'1'", '1', 'true', "'on'"), true); };

    // Rewriter's RESOLVED state this request (public statics; may be unset if it didn't init this request).
    $rw_cdn  = (class_exists('wps_cdn_rewrite') && isset(wps_cdn_rewrite::$cdnEnabled)) ? var_export(wps_cdn_rewrite::$cdnEnabled, true) : '(not initialized this request)';
    $rw_set  = (class_exists('wps_cdn_rewrite') && isset(wps_cdn_rewrite::$settings) && is_array(wps_cdn_rewrite::$settings)) ? wps_cdn_rewrite::$settings : null;
    $rw_zone = (class_exists('wps_cdn_rewrite') && isset(wps_cdn_rewrite::$zone_name)) ? (wps_cdn_rewrite::$zone_name === '' ? '(empty/blanked)' : (string) wps_cdn_rewrite::$zone_name) : '(n/a)';

    if (($g($cached, 'live-cdn') !== $g($raw, 'live-cdn')) || ($g($cached, 'js') !== $g($raw, 'js'))) {
        $verdict = 'CACHE STALE -> cached != DB. Object cache is serving OLD settings; the .110/.111 cache-bust is the fix.';
    } elseif ($on($g($raw, 'live-cdn')) || $on($g($raw, 'js'))) {
        $verdict = 'DB ITSELF HAS CDN ON -> the OFF toggle NEVER PERSISTED to the DB. NOT a cache issue. (Deactivating WPC stops the live rewrite; purging cache cannot help because the DB says on.) Fix is the SAVE path -- OR an override below is forcing it.';
    } else {
        $verdict = 'DB says CDN OFF and cache matches. If zone URLs still emit, an OVERRIDE is forcing it -> check wpc_force_natural / WPC_FORCE_NATURAL below.';
    }

    $rows = array(
        '-- DECISIVE: cached (object cache) vs RAW DB --' => '',
        'live-cdn  cached' => $g($cached, 'live-cdn'),
        'live-cdn  RAW DB' => $g($raw, 'live-cdn'),
        'js        cached' => $g($cached, 'js'),
        'js        RAW DB' => $g($raw, 'js'),
        'css       cached' => $g($cached, 'css'),
        'css       RAW DB' => $g($raw, 'css'),
        'fonts     cached' => $g($cached, 'fonts'),
        'fonts     RAW DB' => $g($raw, 'fonts'),
        'option autoload'  => (string) $autoload,
        'VERDICT'          => $verdict,
        '-- rewriter resolved THIS request --' => '',
        'wps_cdn_rewrite::cdnEnabled'  => $rw_cdn,
        'rewriter settings[js]'        => is_array($rw_set) ? $g($rw_set, 'js') : '(not init)',
        'rewriter settings[live-cdn]'  => is_array($rw_set) ? $g($rw_set, 'live-cdn') : '(not init)',
        'wps_cdn_rewrite::zone_name'   => $rw_zone,
        '-- per-page CDN include (the .112 root cause) --' => '',
        'page_excludes[cdn]'           => (class_exists('wps_cdn_rewrite') && isset(wps_cdn_rewrite::$page_excludes) && is_array(wps_cdn_rewrite::$page_excludes) && array_key_exists('cdn', wps_cdn_rewrite::$page_excludes)) ? var_export(wps_cdn_rewrite::$page_excludes['cdn'], true) : '(unset)',
        'page_excludes (full)'         => (class_exists('wps_cdn_rewrite') && isset(wps_cdn_rewrite::$page_excludes) && wps_cdn_rewrite::$page_excludes) ? wp_json_encode(wps_cdn_rewrite::$page_excludes) : '(empty/none)',
        '-- overrides / forces --' => '',
        'wpc_force_natural()'          => function_exists('wpc_force_natural') ? var_export(wpc_force_natural(), true) : '(n/a)',
        'WPC_FORCE_NATURAL const'      => defined('WPC_FORCE_NATURAL') ? var_export(WPC_FORCE_NATURAL, true) : '(undefined)',
        'zone suppressed'              => function_exists('wpc_v2_zone_cdn_suppressed') ? (wpc_v2_zone_cdn_suppressed() ? 'yes' : 'no') : '(n/a)',
        'plugin version'               => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '(?)',
    );

    $dump = "WP COMPRESS - CDN STATE DEBUG\n=============================\n\n";
    foreach ($rows as $k => $v) {
        if ($v === '') { $dump .= "\n" . $k . "\n"; continue; }
        $dump .= str_pad($k, 30) . ' : ' . (is_string($v) ? $v : wp_json_encode($v)) . "\n";
    }

    $nonce     = wp_create_nonce('wpc_cdn_clear_overrides');
    $clear_url = esc_url(add_query_arg(array('wpc_cdn_debug' => 'clear', '_wpcnonce' => $nonce), home_url('/')));

    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><title>WPC CDN Debug</title>';
    echo '<body style="font:13px -apple-system,BlinkMacSystemFont,sans-serif;max-width:800px;margin:28px auto;padding:0 16px;color:#1d2327;">';
    echo '<h2 style="color:#19335b;">WP Compress — CDN State Debug</h2>';
    echo '<pre style="background:#f6f7f7;padding:14px;border:1px solid #ccd0d4;border-radius:6px;white-space:pre-wrap;font:12px/1.5 monospace;">' . esc_html($dump) . '</pre>';
    echo '<p><a href="' . $clear_url . '" style="display:inline-block;padding:10px 18px;border-radius:5px;background:#d63638;color:#fff;text-decoration:none;font-weight:600;" '
       . 'onclick="return confirm(\'Clear ALL per-page CDN overrides? Every page will then follow the GLOBAL CDN setting. This removes stale per-page include/exclude rules (including the cdn=1 that forced the CDN back on).\')">Clear all per-page CDN overrides</a></p>';
    echo '</body>';
    exit;
});
