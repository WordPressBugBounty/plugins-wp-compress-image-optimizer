<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-recovery.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_pull_recover')) {
    





    function wpc_v2_pull_recover($mode = 'resync')
    {
        $mode = ($mode === 'fresh') ? 'fresh' : 'resync';
        $out  = ['mode' => $mode];


        
        
        update_site_option('wpc_v2_pull_enabled', 1);
        $out['pull_flag'] = 'enabled (wpc_v2_pull_enabled=1)';

        

        if (function_exists('wpc_v2_journal_list_files')) {
            $deleted = 0;
            foreach ((array) wpc_v2_journal_list_files(100000) as $f) {
                if (is_string($f) && @file_exists($f) && @unlink($f)) {
                    $deleted++;
                }
            }
            $out['journal_files_deleted'] = $deleted;
        }

        
        delete_option('wpc_compress_queue');

        
        
        global $wpdb;
        $out['pending_transients_deleted'] = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
              WHERE option_name LIKE '\\_transient\\_wpc\\_v2\\_pending\\_%'
                 OR option_name LIKE '\\_transient\\_timeout\\_wpc\\_v2\\_pending\\_%'"
        );

        
        if ($mode === 'fresh') {
            
            

            $now_ms = (int) round(microtime(true) * 1000);
            update_option('wpc_v2_pull_cursor_ms', $now_ms, false);
            $out['cursor'] = 'advanced_to_now';
            $out['cursor_ms'] = $now_ms;
            $out['next'] = 'run Bulk Optimize to re-optimize the library clean';
        } else {


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

        
        $fetch = wpc_v2_pull_manifest_fetch($cursor, 50, 0);
        $out['manifest_GET'] = [
            'ok'            => !empty($fetch['ok']),
            'error'         => isset($fetch['error']) ? $fetch['error'] : null,
            'entries'       => isset($fetch['variants']) ? count((array) $fetch['variants']) : 0,
            'pages_fetched' => isset($fetch['pages_fetched']) ? (int) $fetch['pages_fetched'] : 0,
            'high_water_ms' => isset($fetch['cursor_high_water_ms']) ? (int) $fetch['cursor_high_water_ms'] : 0,
        ];


        if (!empty($fetch['variants'][0]) && is_array($fetch['variants'][0])) {
            $v0 = $fetch['variants'][0];
            $out['first_entry_raw'] = $v0;

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

    function wpc_v2_pull_drainrun()
    {
        $out = ['ran' => 'inline pipeline (tick + journal drain, no loopback)'];


        $out['pull_flag_before'] = (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled()) ? 'on' : 'OFF (this was the bug)';
        update_site_option('wpc_v2_pull_enabled', 1);
        $out['pull_flag_now'] = 'enabled (wpc_v2_pull_enabled=1)';

        $out['drain_running_transient'] = get_transient('wpc_v2_drain_running') ?: 'none';
        $out['cursor_before'] = (int) get_option('wpc_v2_pull_cursor_ms', 0);

        
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

        
        $out['tick_result'] = function_exists('wpc_v2_pull_manifest_tick')
            ? wpc_v2_pull_manifest_tick(50, 0)
            : '(wpc_v2_pull_manifest_tick missing)';

        
        if (function_exists('wpc_v2_journal_drain_run')) {
            wpc_v2_journal_drain_run();
            $out['journal_drain'] = 'ran inline';
        } else {
            $out['journal_drain'] = '(wpc_v2_journal_drain_run missing)';
        }

        
        $out['sample_target_after'] = $target !== ''
            ? (file_exists($target) ? 'PLACED OK' : 'STILL MISSING')
            : '(n/a)';
        $out['cursor_after'] = (int) get_option('wpc_v2_pull_cursor_ms', 0);

        error_log('[WPC V2 DrainRun] ' . wp_json_encode($out));
        return $out;
    }
}


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


add_action('template_redirect', function () {
    if (empty($_GET['wpc_cdn_debug'])) { return; }
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) { return; }


    
    
    
    
    
    
    
    if ($_GET['wpc_cdn_debug'] === 'fix') {
        if (empty($_GET['wps_ic_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['wps_ic_nonce'])), 'wpc_cdn_fix')) {
            wp_die('WPC: that link expired — reload the debug page and click the button again.');
        }
        $steps = array();

        
        delete_transient('wpc_mc_down');
        delete_transient('wpc_v2_deferred_sync_lock');
        delete_option('wpc_v2_selfheal_attempts');
        delete_transient('wpc_v2_selfheal_backoff');
        delete_transient('wpc_v2_config_force_backoff');
        delete_transient('wpc_cf_reverify_bk');
        update_option('wpc_v2_force_provision', 1, false);
        $steps['gates cleared'] = 'breaker, sync lock, selfheal backoffs, reverify throttle — all reset; force-provision armed';

        
        $orch = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        if ($orch !== '') {
            $tp = wp_remote_get(rtrim($orch, '/') . '/v2/config', ['timeout' => 8, 'sslverify' => true]);
            $steps['orchestrator reachable'] = is_wp_error($tp)
                ? 'NO — ' . $tp->get_error_message() . '  <- outbound blocked at this host (security plugin / firewall / DNS)'
                : 'yes (HTTP ' . (int) wp_remote_retrieve_response_code($tp) . ')';
        } else {
            $steps['orchestrator reachable'] = '(no orchestrator URL configured)';
        }
        $wpc_skew16 = null;
        $tt = wp_remote_get('https://www.cloudflare.com/cdn-cgi/trace', ['timeout' => 5]);
        if (!is_wp_error($tt) && preg_match('/^ts=(\d+)/m', (string) wp_remote_retrieve_body($tt), $tm)) {
            $wpc_skew16 = abs(time() - (int) $tm[1]);
            $steps['server clock skew'] = $wpc_skew16 . 's' . ($wpc_skew16 > 120 ? '  <- BAD CLOCK: signed syncs 401 (ts_skew) — fix the server time' : ' (fine)');
        }

        
        $zid = '';
        if (function_exists('wpc_v2_get_zone_id')) { $zid = (string) wpc_v2_get_zone_id(); }
        if ($zid === '') { $zid = trim((string) get_option('ic_custom_cname')); }
        if ($zid === '') { $zid = trim((string) get_option('ic_cdn_zone_name')); }
        if ($zid !== '' && function_exists('wpc_v2_config_sync_lazy_enabled')) {
            $sr = wpc_v2_config_sync_lazy_enabled($zid, function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : false);
            $steps['config sync'] = !empty($sr['ok'])
                ? 'LANDED (HTTP ' . (int) ($sr['http_code'] ?? 0) . ') — the service has this site\'s current version + cname now'
                : 'FAILED — http_code=' . (int) ($sr['http_code'] ?? 0) . ' reason=' . (string) ($sr['reason'] ?? '?')
                  . '  <- this is the exact thing to fix; nothing downstream can work until it lands';
        } else {
            $steps['config sync'] = 'SKIPPED — no zone id or cname configured on this site';
        }

        
        if (function_exists('wpc_v2_cf_cname_reverify')) {
            $rv = wpc_v2_cf_cname_reverify(false);
            $v16 = get_option('wpc_cf_cname_verified');
            $steps['cname verification'] = ($v16 === '1' || $v16 === 1)
                ? 'VERIFIED' . ($rv ? ' (by this probe)' : ' (already, or via the service witness on the sync above)')
                : 'not verified yet' . (is_array(get_option('wpc_cf_verify_challenged2114')) ? '  <- Cloudflare CHALLENGED the probe — see the notice / trips table' : '');
        }

        
        $after = function_exists('wpc_v2_zone_cdn_suppressed') ? (wpc_v2_zone_cdn_suppressed() ? 'STILL SUPPRESSED — read the trips table for the remaining trip' : 'SERVING — suppression lifted') : '(n/a)';
        if (strpos($after, 'SERVING') === 0 && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
            $after .= '; HTML cache purged';
        }
        $steps['RESULT'] = $after;

        $back = esc_url(add_query_arg(array('wpc_cdn_debug' => '1'), home_url('/')));
        $body16 = '';
        foreach ($steps as $k => $v) { $body16 .= '<tr><td style="padding:6px 14px 6px 0;white-space:nowrap;vertical-align:top;"><strong>' . esc_html($k) . '</strong></td><td style="padding:6px 0;">' . esc_html($v) . '</td></tr>'; }
        wp_die('<h2>WP Compress — one-click fix, step by step</h2><table style="font:13px/1.5 -apple-system,sans-serif;border-collapse:collapse;">' . $body16 . '</table><p style="margin-top:16px;"><a href="' . $back . '">&larr; Back to the debug panel</a></p>', 'WPC — fix report', array('response' => 200));
    }

    
    
    
    if ($_GET['wpc_cdn_debug'] === 'cf') {
        $cf19 = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if (!is_array($cf19) || empty($cf19['token']) || empty($cf19['zone'])) {
            wp_die('WPC: no Cloudflare integration credentials stored on this site — the inspector needs the CF token the plugin uses to write rules.');
        }
        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) { @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php'; }
        $cfc19  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $api19  = new WPC_CloudflareAPI((string) $cf19['token']);
        $ins19  = method_exists($api19, 'wpc_cf_inspect2119') ? $api19->wpc_cf_inspect2119((string) $cf19['zone'], $cfc19) : ['errors' => ['sdk too old']];
        $dump19 = "WP COMPRESS - CLOUDFLARE INSPECTOR (read-only)\nzone: " . $cf19['zone'] . "  cdn host: " . ($cfc19 === '' ? '(none)' : $cfc19) . "\n\n";
        $dump19 .= "-- CUSTOM RULES, DEPLOYED ORDER (a skip only protects what runs AFTER it) --\n";
        if (is_array($ins19['rules']) && $ins19['rules']) {
            foreach ($ins19['rules'] as $r19) {
                $dump19 .= sprintf("%s #%d %s [%s]%s%s%s\n",
                    $r19['ours'] ? '>' : ' ', $r19['pos'], $r19['desc'], $r19['action'],
                    $r19['enabled'] ? '' : ' DISABLED',
                    $r19['ruleset'] === 'current' ? ' ruleset:current' : ($r19['ours'] && $r19['action'] === 'skip' ? ' NO-RULESET <- stale shape' : ''),
                    $r19['phases'] ? ' phases:' . $r19['phases'] : '');
            }
        } elseif (is_array($ins19['rules'])) {
            $dump19 .= "(no custom rules deployed — the Optimizer rules are MISSING; press Refresh Connection)\n";
        } else {
            $dump19 .= "(unreadable)\n";
        }
        $dump19 .= "\n-- BOT FIGHT / SBFM --\n" . (is_array($ins19['bot']) ? wp_json_encode($ins19['bot']) : '(unreadable — token may lack Bot Management read)') . "\n";
        $dump19 .= "\n-- SECURITY LEVEL --\n" . ($ins19['seclevel'] !== null ? $ins19['seclevel'] : '(unreadable)') . "\n";
        $dump19 .= "\n-- LAST FIREWALL EVENTS FOR THE CDN HOST (6h; 'source' NAMES the challenger) --\n";
        if (is_array($ins19['events']) && $ins19['events']) {
            foreach ($ins19['events'] as $e19) {
                $dump19 .= sprintf("%s  %s  source=%s rule=%s  %s  [%s]\n",
                    isset($e19['datetime']) ? $e19['datetime'] : '?', isset($e19['action']) ? $e19['action'] : '?',
                    isset($e19['source']) ? $e19['source'] : '?', !empty($e19['ruleId']) ? $e19['ruleId'] : '-',
                    isset($e19['clientRequestPath']) ? $e19['clientRequestPath'] : '', isset($e19['clientCountryName']) ? $e19['clientCountryName'] : '');
            }
            $dump19 .= "\nsource legend: botFight = free-plan Bot Fight Mode (no rule can exempt it — disable in Security > Bots)\n"
                     . "firewallCustom = a custom rule (the rule id above; ours must sit at #0) · securityLevel/bic = covered products\n";
        } elseif (is_array($ins19['events'])) {
            $dump19 .= "(no events in the window — trigger the failing fetch first, then reload this page)\n";
        } else {
            $dump19 .= "(unreadable — token may lack Analytics/Logs read)\n";
        }
        if (!empty($ins19['errors'])) {
            $dump19 .= "\n-- API ERRORS --\n" . implode("\n", array_map('strval', (array) $ins19['errors'])) . "\n";
        }
        if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
        echo '<!doctype html><meta charset="utf-8"><title>WPC CF Inspector</title>'
           . '<body style="font:13px -apple-system,BlinkMacSystemFont,sans-serif;max-width:900px;margin:28px auto;padding:0 16px;color:#1d2327;">'
           . '<h2 style="color:#19335b;">WP Compress — Cloudflare Inspector</h2>'
           . '<pre style="background:#f6f7f7;padding:14px;border:1px solid #ccd0d4;border-radius:6px;white-space:pre-wrap;font:12px/1.5 monospace;">' . esc_html($dump19) . '</pre>'
           . '<p><a href="' . esc_url(add_query_arg(array('wpc_cdn_debug' => '1'), home_url('/'))) . '">&larr; Back to the debug panel</a></p></body>';
        exit;
    }

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
        'plugin version'               => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '(?)',
    );

    
    
    
    $wpc_al2115  = function_exists('get_option') ? get_option('wps_ic_allow_live', 'unset') : 'unset';
    $wpc_env2115 = function_exists('wpc_v2_provision_env_changed') ? wpc_v2_provision_env_changed() : null;
    $wpc_prf2115 = function_exists('wpc_v2_zone_origin_proved') ? wpc_v2_zone_origin_proved() : null;
    $wpc_cfc2115 = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
    $wpc_cfs2115 = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
    $wpc_ver2115 = get_option('wpc_cf_cname_verified', '(unset=legacy, passes)');
    $wpc_cfw2115 = ($wpc_cfc2115 !== '' && is_array($wpc_cfs2115) && !empty($wpc_cfs2115['settings']['cdn'])
        && $wpc_ver2115 !== '1' && $wpc_ver2115 !== 1);
    $wpc_for2115 = function_exists('wpc_cdn_zone_is_foreign') ? wpc_cdn_zone_is_foreign() : null;
    $wpc_dis2115 = function_exists('wpc_v2_zone_cdn_disabled') ? wpc_v2_zone_cdn_disabled() : null;
    $wpc_aut2115 = function_exists('wpc_v2_zone_auto_disabled') ? wpc_v2_zone_auto_disabled() : null;
    $wpc_rsn2115 = function_exists('wpc_v2_cdn_suppression_reason') ? wpc_v2_cdn_suppression_reason() : '';
    $wpc_wit2115 = get_option('wpc_cf_verify_challenged2114');
    $wpc_eco2115 = get_option('wpc_v2_last_echo2115');
    $wpc_yn2115  = function ($v) { return $v === null ? '(n/a)' : ($v ? 'YES <- TRIP' : 'no'); };
    $rows += array(
        '-- SUPPRESSION TRIPS (in evaluation order; first YES wins) --' => '',
        'UMBRELLA verdict'         => function_exists('wpc_v2_zone_cdn_suppressed') ? (wpc_v2_zone_cdn_suppressed() ? 'SUPPRESSED' : 'serving') : '(n/a)',
        '1 account gate trips'     => ($wpc_al2115 !== 'unset' && !$wpc_al2115) ? 'YES <- TRIP' : 'no',
        '  wps_ic_allow_live raw'  => var_export($wpc_al2115, true),
        '2 env-changed trips'      => ($wpc_env2115 === true && $wpc_prf2115 !== true) ? 'YES <- TRIP' : 'no',
        '  env fingerprint changed' => $wpc_env2115 === null ? '(n/a)' : var_export($wpc_env2115, true),
        '  origin proof live'      => $wpc_prf2115 === null ? '(n/a)' : var_export($wpc_prf2115, true),
        '3 cfwait trips'           => $wpc_cfw2115 ? 'YES <- TRIP' : 'no',
        '  cf cname'               => $wpc_cfc2115 === '' ? '(none)' : $wpc_cfc2115,
        '  cf settings[cdn]'       => is_array($wpc_cfs2115) ? var_export(isset($wpc_cfs2115['settings']['cdn']) ? $wpc_cfs2115['settings']['cdn'] : '(unset)', true) : '(no cf option)',
        '  wpc_cf_cname_verified'  => var_export($wpc_ver2115, true),
        '4 foreign zone trips'     => $wpc_yn2115($wpc_for2115),
        '5 orch cdn_disabled trips' => $wpc_yn2115($wpc_dis2115),
        '  auto_disabled trips'    => $wpc_yn2115($wpc_aut2115),
        '  suppression reason'     => is_array($wpc_rsn2115) ? wp_json_encode($wpc_rsn2115) : '(none)',
        '-- CF VERIFICATION STATE --' => '',
        'challenge witness'        => is_array($wpc_wit2115) ? wp_json_encode($wpc_wit2115) : '(none recorded)',
        'bypass token cached'      => get_option('wpc_cf_bypass_tok2114', '') !== '' ? 'yes' : 'no',
        '-- LAST SERVICE ECHO (/v2/config, what actually arrived) --' => '',
        'last echo'                => is_array($wpc_eco2115) ? wp_json_encode($wpc_eco2115) : '(no sync recorded since 7.21.15)',
        'zone id'                  => function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '(n/a)',
    );

    $dump = "WP COMPRESS - CDN STATE DEBUG\n=============================\n\n";
    foreach ($rows as $k => $v) {
        if ($v === '') { $dump .= "\n" . $k . "\n"; continue; }
        $dump .= str_pad($k, 30) . ' : ' . (is_string($v) ? $v : wp_json_encode($v)) . "\n";
    }

    $nonce     = wp_create_nonce('wpc_cdn_clear_overrides');
    $clear_url = esc_url(add_query_arg(array('wpc_cdn_debug' => 'clear', '_wpcnonce' => $nonce), home_url('/')));
    $fix_url   = esc_url(add_query_arg(array('wpc_cdn_debug' => 'fix', 'wps_ic_nonce' => wp_create_nonce('wpc_cdn_fix')), home_url('/')));

    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><title>WPC CDN Debug</title>';
    echo '<body style="font:13px -apple-system,BlinkMacSystemFont,sans-serif;max-width:800px;margin:28px auto;padding:0 16px;color:#1d2327;">';
    echo '<h2 style="color:#19335b;">WP Compress — CDN State Debug</h2>';
    echo '<pre style="background:#f6f7f7;padding:14px;border:1px solid #ccd0d4;border-radius:6px;white-space:pre-wrap;font:12px/1.5 monospace;">' . esc_html($dump) . '</pre>';
    echo '<p><a href="' . $fix_url . '" style="display:inline-block;padding:10px 18px;border-radius:5px;background:#2271b1;color:#fff;text-decoration:none;font-weight:600;margin-right:10px;">Run one-click fix (sync now + verify + report each step)</a>'
       . '<a href="' . esc_url(add_query_arg(array('wpc_cdn_debug' => 'cf'), home_url('/'))) . '" style="display:inline-block;padding:10px 18px;border-radius:5px;background:#50575e;color:#fff;text-decoration:none;font-weight:600;">Cloudflare inspector (rules · bot mode · who challenged)</a></p>';
    echo '<p><a href="' . $clear_url . '" style="display:inline-block;padding:10px 18px;border-radius:5px;background:#d63638;color:#fff;text-decoration:none;font-weight:600;" '
       . 'onclick="return confirm(\'Clear ALL per-page CDN overrides? Every page will then follow the GLOBAL CDN setting. This removes stale per-page include/exclude rules (including the cdn=1 that forced the CDN back on).\')">Clear all per-page CDN overrides</a></p>';
    echo '</body>';
    exit;
});
