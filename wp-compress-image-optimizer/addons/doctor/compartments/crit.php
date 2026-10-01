<?php
/*
 * The crit compartment: "unstyled first paint", "crit missing, late or never lands", "Remove
 * Critical did nothing", "regenerates all the time".
 *
 * Every fact is read through the owner that keeps it: the dispatch record, the served generation,
 * the park and pending set (gen_fails.json, read without making the page's folder), the service
 * holds, the dead-kick mark, the saved page, the bypass window (read, never lifted), the three
 * places an epoch lives, the kick admissions. The verdict is the first rule that holds, each
 * reading one of those facts; a threshold the plugin owns is asked of its owner, never restated.
 */
if (!class_exists('wps_ic_doctor_crit')) {
    class wps_ic_doctor_crit extends wps_ic_doctor_compartment
    {
        const NAME = 'crit';
        const TITLE = 'Critical CSS';
        const SYMPTOMS = 'unstyled first paint; crit missing, late or never lands; Remove Critical did nothing; regenerates all the time';
        const EVENTS = ['gen-', 'saved-page', 'crit-', 'kick-', 'render-kick', 'land-', 'repull-', 'derived-', 'manifest-', 'wire-', 'whk-', 'watchdog-',
            'dispatch-', 'push-corpus', 'page-saved', 'page-wanted', 'bypass-', 'pointer-', 'uuid-', 'status-poll-', 'callback-',
            'selftest', 'v2-latest-', 'ucss-', 'used-css-', 'sheets-manifest-'];

        public function collect(wps_ic_doctor_context $ctx)
        {
            $facts = [];
            $now = $ctx->now;
            $key = $ctx->key;
            $dir = $ctx->crit_dir;
            if ($key === '' || $dir === '') {
                return ['verdict' => self::verdict('unknown', 'no-key', 'the url key could not be resolved here'), 'facts' => [], 'artifacts' => []];
            }

            $siteSetting = isset($ctx->settings['critical']['css']) ? (string) $ctx->settings['critical']['css'] : '';
            $facts[] = self::fact('critical css (site setting)', $siteSetting === '1' ? 'on' : 'off', 'option:' . WPS_IC_SETTINGS . '.critical.css');
            $facts[] = self::fact('page setting critical_css', $ctx->page_excludes['critical_css'] ?? '-', 'option:wpc-excludes.page_excludes.' . $ctx->page_id);
            $generationOff = function_exists('wpc_crit_generation_off') ? wpc_crit_generation_off($key) : null;
            $facts[] = self::fact('generation off for this page', $generationOff === null ? self::unavailable('wpc_crit_generation_off') : $generationOff, 'fn:wpc_crit_generation_off');
            $facts[] = self::fact('crit folder', is_dir($dir) ? self::relative($dir) : 'none', 'file:' . self::relative($dir));

            // The files a render inlines, per device, and whether each carries rules beyond its faces.
            $files = [];
            foreach ($ctx->devices as $device) {
                $path = $dir . 'critical_' . $device . '.css';
                $state = self::file_state($path, $now);
                if ($state !== null && $state['bytes'] <= 1048576) {
                    $state['payload'] = class_exists('wps_rewriteLogic')
                        ? wps_rewriteLogic::crit_payload_present((string) @file_get_contents($path))
                        : self::unavailable('wps_rewriteLogic');
                }
                $files[$device] = $state;
                $facts[] = self::fact('critical css ' . $device, $state === null ? 'none' : $state, 'file:' . self::relative($path));
            }

            $served = function_exists('wpc_crit_cur_uuid') ? wpc_crit_cur_uuid($dir) : self::unavailable('wpc_crit_cur_uuid');
            $facts[] = self::fact('served generation', $served === '' ? 'none' : $served, 'fn:wpc_crit_cur_uuid (land_uuid.txt, else uuid.txt)');
            $landTs = (int) self::read_text($dir . 'land_ts.txt', 32);
            $facts[] = self::fact('pointer files', [
                'land_uuid' => self::read_text($dir . 'land_uuid.txt', 64),
                'uuid'      => self::read_text($dir . 'uuid.txt', 64),
                'land_ts'   => self::at($landTs),
                'dispatch_ts' => self::at((int) self::read_text($dir . 'dispatch_ts.txt', 32)),
            ], 'file:' . self::relative($dir) . '{land_uuid,uuid,land_ts,dispatch_ts}.txt');
            $record = function_exists('wpc_crit_dispatch_record_read') ? wpc_crit_dispatch_record_read($dir) : null;
            $facts[] = self::fact('dispatch record', $record === null ? self::unavailable('wpc_crit_dispatch_record_read') : ($record === [] ? 'none' : $record), 'fn:wpc_crit_dispatch_record_read');
            $held = function_exists('wpc_manifest_mode') ? wpc_manifest_mode($key) : self::unavailable('wpc_manifest_mode');
            $facts[] = self::fact('manifest held generation', $held === '' ? 'none (legacy path)' : $held, 'fn:wpc_manifest_mode');

            $stale = self::file_state($dir . 'stale.txt', $now);
            $facts[] = self::fact('stale mark', $stale === null ? 'none' : ['since' => self::at((int) self::read_text($dir . 'stale.txt', 32)), 'age_s' => $stale['age_s']], 'file:' . self::relative($dir) . 'stale.txt');
            $bypass = function_exists('wpc_crit_bypass_read') ? wpc_crit_bypass_read($key, $now) : null;
            $facts[] = self::fact('bypass window', $bypass === null ? self::unavailable('wpc_crit_bypass_read')
                : ['state' => $bypass['state'], 'scope' => $bypass['scope'], 'armed' => self::at($bypass['armed_at']), 'age_s' => $bypass['armed_at'] > 0 ? $now - $bypass['armed_at'] : 0],
                'fn:wpc_crit_bypass_read');

            // The park and the pending set, read without making the folder (a page with no folder has no record).
            $landless = function_exists('wpc_gen_landless_read') ? wpc_gen_landless_read($key, false) : null;
            $parkUntil = 0;
            $pending = [];
            if ($landless === null) {
                $facts[] = self::fact('landless record', self::unavailable('wpc_gen_landless_read'), 'file:' . self::relative($dir) . 'gen_fails.json');
            } else {
                $pending = (array) $landless['pending'];
                $parkUntil = function_exists('wpc_gen_landless_is_parked') ? wpc_gen_landless_is_parked($landless, $now) : 0;
                $facts[] = self::fact('landless record', [
                    'failed'   => function_exists('wpc_gen_landless_count') ? wpc_gen_landless_count($landless, $now) : '-',
                    'pending'  => count($pending),
                    'terminal' => count((array) $landless['terminal']),
                    'park_until' => self::at($parkUntil),
                    'n'        => $landless['n'],
                    'dispatched' => isset($landless['kept']['dispatched']) ? $landless['kept']['dispatched'] : [],
                ], 'fn:wpc_gen_landless_read (no create) + wpc_gen_landless_is_parked');
                $facts[] = self::fact('park give-up filter', (bool) apply_filters('wpc_gen_landless_giveup', true), 'filter:wpc_gen_landless_giveup');
            }

            $holds = function_exists('wpc_gen_holds_for') ? wpc_gen_holds_for($key) : null;
            $holdUntil = function_exists('wpc_gen_service_hold_until') ? (int) wpc_gen_service_hold_until($key) : 0;
            $facts[] = self::fact('service holds', $holds === null ? self::unavailable('wpc_gen_holds_for') : ($holds === [] ? 'none' : $holds), 'fn:wpc_gen_holds_for');
            $deadUntil = function_exists('wpc_kick_dead_until') ? (int) wpc_kick_dead_until($key) : 0;
            $facts[] = self::fact('kick dead mark until', self::at($deadUntil), 'fn:wpc_kick_dead_until');

            // The saved page every dispatch carries, and the no-page lock.
            $saved = [];
            if (function_exists('wpc_saved_page_file')) {
                foreach ($ctx->devices as $device) {
                    $state = self::file_state(wpc_saved_page_file($key, $device === 'mobile'), $now);
                    if ($state !== null) {
                        // A page retired by a content change or older than the owner's age may not be sent.
                        $state['current'] = function_exists('wpc_saved_page_current') ? wpc_saved_page_current($state['mtime']) : self::unavailable('wpc_saved_page_current');
                    }
                    $saved[$device] = $state === null ? 'none' : $state;
                }
            }
            $facts[] = self::fact('saved page', function_exists('wpc_saved_page_file') ? $saved : self::unavailable('wpc_saved_page_file'), 'fn:wpc_saved_page_file');
            $noPageFile = function_exists('wpc_saved_page_missing_file') ? wpc_saved_page_missing_file($key) : '';
            $noPage = $noPageFile !== '' && @is_file($noPageFile);
            $facts[] = self::fact('no-page lock', $noPage ? self::file_state($noPageFile, $now) : 'none', 'fn:wpc_saved_page_missing_file');

            // The removal epochs, side by side; the doctor does not reconcile them.
            $siteAcked = get_option('wpc_crit_site_epoch_acked', null);
            $facts[] = self::fact('epochs', [
                'site_epoch'       => (int) get_option('wpc_crit_site_epoch', 0),
                'site_epoch_acked' => $siteAcked === null ? 'no record' : (int) $siteAcked,
                'page_epoch'       => isset($landless['kept']['page_epoch']) ? (int) $landless['kept']['page_epoch'] : 0,
                'page_epoch_acked' => isset($landless['kept']['page_epoch_acked']) ? (int) $landless['kept']['page_epoch_acked'] : 'no record',
                'epoch_min'        => (int) get_option('wpc_crit_epoch_min', 0),
                'crit_epoch_txt'   => self::read_text($dir . 'crit_epoch.txt', 32),
            ], 'option:wpc_crit_site_epoch{,_acked}, option:wpc_crit_epoch_min, gen_fails.json kept, file:crit_epoch.txt');

            $admitFile = function_exists('wpc_kick_admit_counter_file') ? wpc_kick_admit_counter_file(false) : '';
            $admitted = ($admitFile !== '' && function_exists('wpc_kick_admit_window')) ? count(wpc_kick_admit_window((string) @file_get_contents($admitFile))) : '-';
            $facts[] = self::fact('kick admissions in the last minute', $admitted, 'fn:wpc_kick_admit_window');
            $backoffFile = function_exists('wpc_gen_backoff_file') ? wpc_gen_backoff_file(false) : '';
            $backoff = self::read_json($backoffFile);
            $facts[] = self::fact('site failure back-off', $backoff === null ? 'none' : ['fails' => (int) ($backoff['fails'] ?? 0), 'until' => self::at((int) ($backoff['until'] ?? 0)), 'why' => (string) ($backoff['why'] ?? '')], 'file:critical/.kicklocks/genfail.json');
            $cooldownFile = function_exists('wpc_land_cooldown_file') ? wpc_land_cooldown_file($key, false) : '';
            $facts[] = self::fact('last land cooldown stamp', self::at((int) self::read_text($cooldownFile, 32)), 'file:critical/.kicklocks/land_<md5>.txt');

            // The verdict: the first rule that holds.
            $landedAt = max($landTs, isset($files['desktop']['mtime']) ? (int) $files['desktop']['mtime'] : 0);
            $haveCrit = !empty($files['desktop']) || !empty($files['mobile']);
            $newestPending = $pending ? max(array_map('intval', $pending)) : 0;
            if ($generationOff === true) {
                $verdict = self::verdict('warn', 'generation-off', 'critical CSS is off for this page: nothing is dispatched, a click included');
            } elseif ($holdUntil > $now) {
                $first = is_array($holds) && $holds ? $holds[0] : [];
                $verdict = self::verdict('warn', 'held', sprintf('the service holds automatic dispatches (%s, %s) until %s', $first['scope'] ?? '?', $first['type'] ?? '?', $holdUntil === PHP_INT_MAX ? 'the plugin version changes' : self::at($holdUntil)));
            } elseif ($parkUntil > $now) {
                $verdict = self::verdict('fail', 'parked', sprintf('parked after %d generations that never landed, until %s', function_exists('wpc_gen_landless_count') ? wpc_gen_landless_count($landless, $now) : 0, self::at($parkUntil)));
            } elseif ($newestPending > 0 && $newestPending >= $landedAt) {
                $verdict = self::verdict('warn', 'in-flight', sprintf('a generation dispatched %ds ago has not landed yet (%d pending)', $now - $newestPending, count($pending)));
            } elseif ($noPage && (!isset($saved['desktop']['current']) || $saved['desktop']['current'] !== true)) {
                $verdict = self::verdict('warn', 'no-page', 'the last dispatch was refused for want of a saved page; the next real visit saves it');
            } elseif ($deadUntil > $now) {
                $verdict = self::verdict('warn', 'kick-dead', 'kicks for this key are refused as unresolvable until ' . self::at($deadUntil));
            } elseif (!$haveCrit) {
                $verdict = self::verdict('fail', 'no-crit', 'no critical CSS on disk and nothing in flight: the next real visit asks for it');
            } elseif (isset($files['desktop']['payload']) && $files['desktop']['payload'] === false) {
                $verdict = self::verdict('warn', 'no-payload', 'the desktop crit carries nothing but @font-face: sheets are not parked behind it');
            } elseif ($bypass !== null && $bypass['state'] === 'active') {
                $verdict = self::verdict('warn', 'bypass', sprintf('a %s bypass window since %s: the page renders without crit until a land', $bypass['scope'], self::at($bypass['armed_at'])));
            } elseif ($stale !== null) {
                $verdict = self::verdict('warn', 'stale', 'serving; stale.txt asks for a regeneration since ' . self::at((int) self::read_text($dir . 'stale.txt', 32)));
            } else {
                $verdict = self::verdict('ok', 'serving', sprintf('serving generation %s, landed %s', $served === '' ? '?' : $served, self::at($landedAt)));
            }

            return ['verdict' => $verdict, 'facts' => $facts, 'artifacts' => self::list_dir($dir, $now)];
        }
    }
}
