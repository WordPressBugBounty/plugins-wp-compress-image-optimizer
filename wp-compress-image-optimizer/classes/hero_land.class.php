<?php

/**
 * Hero-first land: the AVIF twin of a page's hero is dispatched on demand, proven on disk, and named by the page only while the proof holds.
 *
 * Two per-site cohorts, each off by default and each read through wpc_policy_cohort():
 *
 *   hero_land_first    when a page's lcp.json lands, every device's hero (lcp_element, and the atf_images rows stamped role hero) is
 *                      resolved to its attachment and rendition; a rendition whose twin is not landed is sent through the one image
 *                      dispatch door (wps_ic_image_optimize::dispatch($id, 'hero')); the manifest is pulled within a minute; the twin
 *                      is proven; a twin that is not proven is sent again on a bounded schedule. Nothing is scheduled, sent or
 *                      written while the cohort is off.
 *   hero_same_origin   rule 8: the hero's <img>, its preload and the CSS that paints it name the site-domain twin while its receipt
 *                      says landed and the file on disk is still the file that was proven (sameOriginPass(), twinFor()).
 *
 * THE RECEIPT. Post meta `hero_land` on the attachment, a map twin-file-name => {state, at, sha, size, file}: `size` is the size label
 * of the rendition, `file` the twin's file name, `sha` the sha256 of the file on disk when the state was written. `state` is
 *   landed    all four checks passed
 *   pending   sent, nothing proven yet
 *   missing   no file on disk
 *   fake      a file that is not AVIF (bytes 4..12 are not ftypavif or ftypavis), or the same-origin HEAD did not answer 200 image/avif
 *   mismatch  the file's sha256 is not the sha256 the manifest announced (ic_local_variants[key].bytes_sha256)
 *   larger    the file is bigger than the CDN variant the page serves today (lcp_element fetched_bytes)
 *   unproven  every check that could be made passed, one could not be made (no recorded manifest sha, no HEAD, no served bytes)
 * Only `landed` is ever read as a reason to change a page. The retry book, post meta `hero_land_try`, a map file => {n, last, next,
 * why, gave_up, addr, served, key}, is the land report's list of tries and give-ups.
 *
 * THE IDEAL WIDTH. A hero is drawn at the width the service measured (`need_px`, its own device's, read through
 * wps_ic_atf_observation::needPxOf). When no size on disk has the upload's aspect and is at least that wide, the request also asks for one
 * ideal-width rendition: the need rounded up to 64 px, never past the width of the upload, named by wpc_v2_adaptive_variant_suffix() (the
 * name every read derives), sent as the per-variant entry of the envelope (`extra_variants`, AVIF only). A need that rounds to the upload's
 * own width asks for the upload itself (the scaled or unscaled file). The ideal rendition has its own receipt and its own proof.
 *
 * THE PROOF is the order file on disk, manifest sha, same-origin HEAD, size. The HEAD is a raw loopback to the site's own web server
 * (wps_ic_ajax::wpc_loopback_open_socket) that follows no redirect: a missing .avif that the plugin's fallbacks answer with a 302 to the
 * .jpg is not a landed file (classes/htaccess.class.php Missing Variant Fallback, addons/cdn/fast404-guard.php, addons/v2/v2-fast-404.php,
 * addons/v2/v2-rung-intercept.php), and a sibling rung streamed under the requested name has no file at the path.
 */
final class wps_ic_hero_land
{
    const META = 'hero_land';
    const META_TRY = 'hero_land_try';
    const SCAN_EVENT = 'wpc_hero_land_scan';
    const TICK_EVENT = 'wpc_hero_land_tick';
    const SCAN_DEBOUNCE = 300;
    const SCAN_DELAY = 10;
    const PULL_AFTER = 45;
    const PROVE_AFTER = 100;
    const RETRY_DELAYS = [300, 1800, 7200, 43200];
    const RETRY_DAILY = 86400;
    const MAX_TRIES = 10;
    const MAX_DEFERS = 12;
    const GIVE_UP_HOLD = 604800;
    const MAX_ROWS = 4;
    const LCP_BYTES_MAX = 1048576;

    private static $shaMemo = [];

    public static function landOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('hero_land_first') === 1;
    }

    public static function sameOriginOn()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('hero_same_origin') === 1;
    }

    /**
     * An lcp.json landed in $dir: one scan event, five minutes apart at most per page. Called by the one crit file writer
     * (wpc_crit_meta_write), so every writer of lcp.json lands here; nothing but the cohort read happens when the cohort is off.
     */
    public static function onLcpLanded($dir)
    {
        if (!self::landOn() || !function_exists('wp_schedule_single_event')) {
            return false;
        }
        $key = basename(rtrim((string) $dir, '/'));
        if ($key === '' || strpos($key, '..') !== false) {
            return false;
        }
        $held = 'wpc_hero_land_scan_' . md5($key);
        if (function_exists('get_transient') && get_transient($held)) {
            return false;
        }
        if (function_exists('set_transient')) {
            set_transient($held, 1, self::SCAN_DEBOUNCE);
        }
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::SCAN_EVENT, [$key])) {
            return false;
        }
        return (bool) wp_schedule_single_event(time() + self::SCAN_DELAY, self::SCAN_EVENT, [$key]);
    }

    /** The cron handler of the scan event: every hero of the page's lcp.json, each job taken one step. Answers how many were stepped. */
    public static function scan($key)
    {
        if (!self::landOn() || !defined('WPS_IC_CRITICAL')) {
            return 0;
        }
        $key = ltrim((string) $key, '/');
        if ($key === '' || strpos($key, '..') !== false) {
            return 0;
        }
        $document = self::document($key);
        $stepped = 0;
        if ($document === null) {
            self::log('hero-land-skip', $key, ['why' => 'no-lcp']);
            return 0;
        }
        foreach (self::heroes($document) as $job) {
            if (self::allLanded($job['targets'])) {
                self::surface($key, $job['targets']);
                continue;
            }
            $book = self::book($job['res']['id'], $job['res']['file']);
            if ($book !== []) {
                if (!empty($book['gave_up'])) {
                    if ((time() - (int) $book['gave_up']) < self::GIVE_UP_HOLD) {
                        continue;
                    }
                    self::clearBook($job['res']['id'], $job['res']['file']);
                } elseif ((int) $book['next'] > time()) {
                    continue;
                }
            }
            self::advance($job, $key);
            $stepped++;
        }
        self::log('hero-land-scan', $key, ['n' => $stepped]);
        return $stepped;
    }

    /** The cron handler of the tick event: phase 'pull' fires the manifest pull, 'prove' proves, 'retry' proves and sends again. */
    public static function tick($id, $file, $phase)
    {
        if (!self::landOn()) {
            return false;
        }
        $id = (int) $id;
        $file = (string) $file;
        $book = self::book($id, $file);
        if ($id <= 0 || $file === '' || $book === []) {
            return false;
        }
        $job = self::jobOf($book);
        if ($job === [] || $job['res']['file'] !== $file) {
            return false;
        }
        if (self::allLanded($job['targets'])) {
            return true;
        }
        if ($phase === 'pull') {
            if (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled() && function_exists('wpc_v2_pull_drain_fire')) {
                wpc_v2_pull_drain_fire();
            }
            return true;
        }
        if ($phase === 'prove') {
            $landed = true;
            foreach ($job['targets'] as $target) {
                $verdict = self::prove($target, $job['served']);
                self::record($target, $verdict);
                $landed = $landed && $verdict['state'] === 'landed';
            }
            if ($landed) {
                self::clearBook($id, $file);
                self::surface((string) $book['key'], $job['targets']);
            }
            return true;
        }
        if (!empty($book['gave_up']) || (int) $book['next'] > time() + 5) {
            return false;
        }
        self::advance($job, (string) $book['key']);
        return true;
    }

    /** The job a retry book describes: the hero's rendition, the ideal rendition it asked for, the rungs that cover each device's need, what the page serves. [] when the address no longer resolves. */
    private static function jobOf(array $book)
    {
        $res = self::resolve((string) $book['addr']);
        if ($res === []) {
            return [];
        }
        $ideal = (int) $book['ideal'] > 0 ? self::idealFor($res, (int) $book['need']) : null;
        $needs = $book['needs'] !== [] ? $book['needs'] : ((int) $book['need'] > 0 ? [(int) $book['need']] : []);
        return ['res' => $res, 'ideal' => $ideal, 'targets' => self::targetsOf($res, $ideal, $needs), 'served' => (int) $book['served'], 'addr' => (string) $book['addr'], 'need' => (int) $book['need'], 'needs' => $needs];
    }

    /** The renditions a hero job is about: the hero's own, the ideal-width one when no rung covers the need, and for each device's need the rung that covers it (coveringRung()). */
    private static function targetsOf(array $res, $ideal, array $needs)
    {
        $targets = [$res];
        if ($ideal !== null) {
            $targets[] = $ideal;
        }
        foreach (array_unique(array_map('intval', $needs)) as $need) {
            $rung = self::coveringRung($res, $need);
            if ($rung === null) {
                continue;
            }
            $known = false;
            foreach ($targets as $target) {
                $known = $known || $target['file'] === $rung['file'];
            }
            if (!$known) {
                $targets[] = $rung;
            }
        }
        return $targets;
    }

    private static function allLanded(array $targets)
    {
        foreach ($targets as $target) {
            $rec = self::receipt($target['id'], $target['file']);
            if ($rec === [] || $rec['state'] !== 'landed' || !self::stillLanded($target, $rec)) {
                return false;
            }
        }
        return true;
    }

    /**
     * One step for a job whose twins are not all landed: prove each, and send what is not proven (or wait, or give up).
     * Answers 'landed', 'sent', 'wait' or 'gave_up'. One send carries every rendition of the attachment that needs one.
     */
    private static function advance(array $job, $key)
    {
        $res = $job['res'];
        $id = (int) $res['id'];
        $file = (string) $res['file'];
        $old = self::book($id, $file);
        $n = (int) ($old['n'] ?? 0);
        $open = [];
        $larger = 0;
        $why = '';
        foreach ($job['targets'] as $target) {
            $verdict = self::prove($target, $job['served']);
            self::record($target, $verdict);
            if ($verdict['state'] === 'landed') {
                continue;
            }
            if ($verdict['state'] === 'larger') {
                $larger++;
                continue;
            }
            $open[] = ['res' => $target, 'verdict' => $verdict];
            $why = $why !== '' ? $why : (string) $verdict['why'];
        }
        $book = [
            'n' => $n, 'defers' => (int) ($old['defers'] ?? 0), 'last' => (int) ($old['last'] ?? 0), 'next' => 0, 'why' => $why, 'gave_up' => 0,
            'addr' => substr((string) $job['addr'], 0, 400), 'served' => (int) $job['served'], 'key' => (string) $key,
            'ideal' => $job['ideal'] === null ? 0 : (int) $job['ideal']['width'], 'need' => (int) $job['need'], 'needs' => array_values(array_map('intval', $job['needs'])),
        ];
        if ($open === []) {
            if ($larger === 0) {
                self::clearBook($id, $file);
                self::surface($key, $job['targets']);
                return 'landed';
            }
            self::surface($key, $job['targets']);
            return self::giveUp($res, $book, 'larger');
        }
        if ($n >= self::MAX_TRIES) {
            return self::giveUp($res, $book, 'tries');
        }
        $send = [];
        foreach ($open as $row) {
            $state = $row['verdict']['state'];
            if ($state !== 'unproven' || $row['verdict']['why'] === 'no-manifest-sha') {
                $send[] = $row['res'];
            }
        }
        if ($send === []) {
            $book['n'] = $n + 1;
            return self::wait($res, $book, self::delayAfter($book['n']), $why);
        }
        $sent = self::send($id, $send);
        self::log('hero-land-dispatch', (string) $id, ['id' => $id, 'file' => $file, 'ok' => $sent['ok'] ? 1 : 0, 'why' => (string) $sent['why'], 'n' => $n + 1, 'targets' => count($send)]);
        if (!$sent['ok'] && (int) $sent['defer'] > 0) {
            $book['defers'] = (int) ($old['defers'] ?? 0) + 1;
            if ($book['defers'] > self::MAX_DEFERS) {
                return self::giveUp($res, $book, 'deferred');
            }
            return self::wait($res, $book, (int) $sent['defer'], (string) $sent['why']);
        }
        $book['n'] = $n + 1;
        $book['last'] = time();
        $book['why'] = (string) $sent['why'];
        if (!$sent['ok']) {
            return self::wait($res, $book, self::delayAfter($book['n']), (string) $sent['why']);
        }
        foreach ($send as $target) {
            self::record($target, ['state' => 'pending', 'sha' => self::fileSha((string) $target['twin_abs']), 'why' => '']);
        }
        $book['next'] = time() + self::delayAfter($book['n']);
        self::saveBook($id, $file, $book);
        self::schedule(self::PULL_AFTER, $id, $file, 'pull');
        self::schedule(self::PROVE_AFTER, $id, $file, 'prove');
        self::schedule(self::delayAfter($book['n']), $id, $file, 'retry');
        return 'sent';
    }

    private static function wait(array $res, array $book, $seconds, $why)
    {
        $seconds = max(30, (int) $seconds);
        $book['next'] = time() + $seconds;
        $book['why'] = (string) $why;
        self::saveBook($res['id'], $res['file'], $book);
        self::schedule($seconds, $res['id'], $res['file'], 'retry');
        return 'wait';
    }

    private static function giveUp(array $res, array $book, $why)
    {
        $book['gave_up'] = time();
        $book['next'] = 0;
        $book['why'] = (string) $why;
        self::saveBook($res['id'], $res['file'], $book);
        self::log('hero-land-gaveup', (string) $res['id'], ['id' => (int) $res['id'], 'file' => (string) $res['file'], 'n' => (int) $book['n'], 'why' => (string) $why]);
        return 'gave_up';
    }

    /** Seconds from the n-th try to the next: 5 min, 30 min, 2 h, 12 h, then once a day. */
    public static function delayAfter($n)
    {
        $n = max(1, (int) $n);
        return $n <= count(self::RETRY_DELAYS) ? (int) self::RETRY_DELAYS[$n - 1] : self::RETRY_DAILY;
    }

    private static function schedule($seconds, $id, $file, $phase)
    {
        if (!function_exists('wp_schedule_single_event')) {
            return false;
        }
        return (bool) wp_schedule_single_event(time() + max(1, (int) $seconds), self::TICK_EVENT, [(int) $id, (string) $file, (string) $phase]);
    }

    /**
     * Send the renditions to the one dispatch door as one job. The reason is the local `hero`; the request reports trigger `prewarm` (the value
     * the service already takes from the Compress button) and carries `priority: hero`, which is the lane the orchestrator is asked to read.
     * Only AVIF is asked for, and only these sizes (the smart-lazy needed_widths; an ideal-width rendition rides as an extra variant).
     * Answers {ok, why, defer}: `defer` is the seconds a back-off still has to run (the attempt admission), which is a wait and not a try.
     */
    private static function send($id, array $targets)
    {
        $id = (int) $id;
        if (!class_exists('wps_ic_image_optimize')) {
            return ['ok' => false, 'why' => 'no-door', 'defer' => 0];
        }
        if (function_exists('wpc_v2_is_store_broken_active') && wpc_v2_is_store_broken_active()) {
            return ['ok' => false, 'why' => 'store-broken', 'defer' => 0];
        }
        $gate = wps_ic_image_optimize::refusal($id, 'hero');
        if ($gate !== null) {
            return ['ok' => false, 'why' => (string) $gate, 'defer' => $gate === 'backoff_wait' ? self::backoffLeft($id) : 0];
        }
        if (class_exists('wps_local_compress')) {
            try {
                $compress = new wps_local_compress();
                if (method_exists($compress, 'backup_all_sizes')) {
                    $compress->backup_all_sizes($id);
                }
            } catch (\Throwable $e) {
            }
        }
        $options = function_exists('get_option') && defined('WPS_IC_OPTIONS') ? get_option(WPS_IC_OPTIONS) : [];
        $maxWidth = (is_array($options) && !empty($options['maxWidth'])) ? (int) $options['maxWidth'] : 2560;
        $widths = [];
        $extra = [];
        foreach ($targets as $target) {
            $width = !empty($target['parent']) ? $maxWidth : (int) $target['width'];
            if ($width > 0) {
                $widths[] = $width;
            }
            if (!empty($target['variant'])) {
                $extra[] = $target['variant'];
            }
        }
        $overrides = [
            'formats'         => ['avif'],
            'resubmit_reason' => 'missing_variant',
            'priority'        => 'hero',
            'triggerContext'  => 'hero-land',
        ];
        if ($widths !== []) {
            $overrides['needed_widths'] = array_values(array_unique($widths));
        }
        if ($extra !== []) {
            $overrides['extra_variants'] = $extra;
        }
        $result = wps_ic_image_optimize::dispatch($id, 'hero', $overrides);
        if (!empty($result['ok'])) {
            return ['ok' => true, 'why' => (($result['outcome'] ?? '') === 'deduplicated') ? 'deduplicated' : '', 'defer' => 0];
        }
        $why = !empty($result['refused']) ? (string) ($result['reason'] ?? 'refused') : (string) ($result['error'] ?? 'failed');
        return ['ok' => false, 'why' => $why, 'defer' => $why === 'backoff_wait' ? self::backoffLeft($id) : 0];
    }

    private static function backoffLeft($id)
    {
        $a = get_post_meta((int) $id, 'ic_v2_attempts', true);
        $n = (is_array($a) && isset($a['n'])) ? (int) $a['n'] : 0;
        $last = (is_array($a) && isset($a['last'])) ? (int) $a['last'] : 0;
        $spacing = (array) apply_filters('wpc_v2_attempt_spacing', [600, 1800, 7200]);
        $wait = isset($spacing[$n - 1]) ? (int) $spacing[$n - 1] : 7200;
        return max(30, $wait - (time() - $last));
    }

    // ─── the proof ───────────────────────────────────────────────────────────────────────────

    /**
     * The four checks, in order: the file is on disk and is AVIF; its sha256 is the one the manifest announced; the site's own web
     * server answers a HEAD for its address 200 image/avif without a redirect; it is not larger than the CDN variant the page serves
     * (`$served` bytes, lcp_element fetched_bytes). Answers {state, sha, why}.
     */
    public static function prove(array $res, $served)
    {
        $file = (string) $res['twin_abs'];
        clearstatcache(true, $file);
        $bytes = @is_file($file) ? (int) @filesize($file) : 0;
        if ($bytes <= 0) {
            return ['state' => 'missing', 'sha' => '', 'why' => 'no-file'];
        }
        $head = '';
        $handle = @fopen($file, 'rb');
        if ($handle) {
            $head = (string) fread($handle, 32);
            fclose($handle);
        }
        $sha = (string) @hash_file('sha256', $file);
        if (!self::isAvifHead($head)) {
            return ['state' => 'fake', 'sha' => $sha, 'why' => 'not-avif-bytes'];
        }
        $unproven = '';
        $recorded = self::manifestSha((int) $res['id'], $res);
        if ($recorded === '') {
            $unproven = 'no-manifest-sha';
        } elseif ($sha === '' || !hash_equals($recorded, $sha)) {
            return ['state' => 'mismatch', 'sha' => $sha, 'why' => 'sha'];
        }
        $answer = self::head((string) $res['twin_url']);
        if ((int) $answer['status'] === 0) {
            $unproven = $unproven !== '' ? $unproven : 'head-' . (string) $answer['why'];
        } elseif ((int) $answer['status'] !== 200) {
            return ['state' => 'fake', 'sha' => $sha, 'why' => 'head-' . (int) $answer['status']];
        } elseif ((string) $answer['type'] !== 'image/avif') {
            return ['state' => 'fake', 'sha' => $sha, 'why' => 'head-type'];
        }
        $served = !empty($res['served_bytes']) ? (int) $res['served_bytes'] : (int) $served;
        if ($served > 0 && $bytes > $served) {
            return ['state' => 'larger', 'sha' => $sha, 'why' => 'bytes'];
        }
        if ($served <= 0 && $unproven === '') {
            $unproven = 'no-served-bytes';
        }
        if ($unproven !== '') {
            return ['state' => 'unproven', 'sha' => $sha, 'why' => $unproven];
        }
        return ['state' => 'landed', 'sha' => $sha, 'why' => ''];
    }

    /** True for the ISO base media header of an AVIF: `ftyp` at byte 4 and the brand avif or avis (major or compatible) in the next 24 bytes. */
    public static function isAvifHead($head)
    {
        $head = (string) $head;
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }
        $brands = substr($head, 8, 24);
        return strpos($brands, 'avif') !== false || strpos($brands, 'avis') !== false;
    }

    /**
     * A HEAD to the site's own web server for an address on the site, through the loopback helper (the order of hosts the plugin's
     * other loopbacks use). No redirect is followed. Answers {status, type, why}: status 0 when no answer could be had.
     */
    public static function head($url)
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url((string) $url) : parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return ['status' => 0, 'type' => '', 'why' => 'bad-url'];
        }
        $host = (string) $parts['host'];
        $path = (string) $parts['path'] . ((isset($parts['query']) && $parts['query'] !== '') ? '?' . $parts['query'] : '');
        if (preg_match('/[^A-Za-z0-9.\-\[\]]/', $host) || preg_match('/[\s"\'\x00-\x1f]/', $path)) {
            return ['status' => 0, 'type' => '', 'why' => 'bad-url'];
        }
        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
            return ['status' => 0, 'type' => '', 'why' => 'no-helper'];
        }
        $https = isset($parts['scheme']) && strtolower((string) $parts['scheme']) === 'https';
        $port = !empty($parts['port']) ? (int) $parts['port'] : ($https ? 443 : 80);
        $socket = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $https, 0.5);
        if (!$socket) {
            return ['status' => 0, 'type' => '', 'why' => 'no-socket'];
        }
        @stream_set_timeout($socket, 3);
        $request = 'HEAD ' . $path . " HTTP/1.1\r\nHost: " . $host . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '')
            . "\r\nAccept: image/avif,image/webp,*/*\r\nUser-Agent: WPCHeroLand/1.0\r\nConnection: close\r\n\r\n";
        @fwrite($socket, $request);
        $raw = '';
        $deadline = microtime(true) + 3.0;
        while (strlen($raw) < 8192 && strpos($raw, "\r\n\r\n") === false && microtime(true) < $deadline && !feof($socket)) {
            $chunk = @fread($socket, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        @fclose($socket);
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $raw, $statusMatch) ? (int) $statusMatch[1] : 0;
        $type = preg_match('/^content-type:[ \t]*([^;\r\n]+)/im', $raw, $typeMatch) ? strtolower(trim($typeMatch[1])) : '';
        return ['status' => $status, 'type' => $type, 'why' => $status === 0 ? 'no-answer' : ''];
    }

    /** The sha256 the manifest announced for the file: ic_local_variants[key].bytes_sha256 of the entry whose url is the twin's address. '' when none is recorded. */
    private static function manifestSha($id, array $res)
    {
        wp_cache_delete((int) $id, 'post_meta');
        $set = get_post_meta((int) $id, 'ic_local_variants', true);
        if (!is_array($set)) {
            return '';
        }
        $tail = ($res['dir'] !== '' ? '/' . $res['dir'] : '') . '/' . $res['file'];
        foreach ($set as $entry) {
            if (!is_array($entry) || empty($entry['bytes_sha256']) || empty($entry['url'])) {
                continue;
            }
            $path = rawurldecode((string) parse_url((string) $entry['url'], PHP_URL_PATH));
            if ($path !== '' && substr($path, -strlen($tail)) === $tail) {
                return strtolower(trim((string) $entry['bytes_sha256']));
            }
        }
        return '';
    }

    /** True while the receipt's file is on disk and is still the file that was proven. */
    private static function stillLanded(array $res, array $rec)
    {
        $sha = self::fileSha((string) $res['twin_abs']);
        return $sha !== '' && $rec['sha'] !== '' && hash_equals((string) $rec['sha'], $sha);
    }

    private static function fileSha($path)
    {
        $path = (string) $path;
        if (!@is_file($path)) {
            return '';
        }
        $memo = $path . '|' . (int) @filemtime($path) . '|' . (int) @filesize($path);
        if (!isset(self::$shaMemo[$memo])) {
            if (count(self::$shaMemo) > 32) {
                self::$shaMemo = [];
            }
            self::$shaMemo[$memo] = (string) @hash_file('sha256', $path);
        }
        return self::$shaMemo[$memo];
    }

    // ─── what a page's lcp.json names ────────────────────────────────────────────────────────

    private static function document($key)
    {
        $file = rtrim((string) WPS_IC_CRITICAL, '/') . '/' . $key . '/lcp.json';
        if (!@is_readable($file) || (int) @filesize($file) > self::LCP_BYTES_MAX) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The heroes of one decoded lcp.json: for each measured device, the LCP element (lcp_element, then lcp) when it is an image or a
     * background, and the atf_images rows stamped role hero. Each is resolved from the address the service's browser fetched (the
     * rendition the page serves today), then src, url, entry_url. Answers one job per rendition: {device, res, ideal, targets, served,
     * addr, need, needs}. `served` is the smallest fetched_bytes of the devices naming it, `need` the largest need_px of their own devices,
     * `needs` each device's own, `ideal` the ideal-width rendition to ask for (idealFor()) or null, `targets` the renditions whose twins the job
     * is about: the hero's own, the ideal one, and for each device's need the on-disk rung that covers it (coveringRung(); the rung the page names
     * when the preload is the cheapest rung at least as wide as the device needs, which need not be the file the service's browser fetched).
     */
    public static function heroes(array $document, array $devices = ['mobile', 'desktop'])
    {
        $out = [];
        foreach ($devices as $device) {
            if (class_exists('wps_ic_atf_observation') && wps_ic_atf_observation::documentHolds($document, $device)) {
                continue;
            }
            $leg = (isset($document['leg_status'][$device]) && is_array($document['leg_status'][$device])) ? $document['leg_status'][$device] : [];
            if (array_key_exists('measured', $leg) && empty($leg['measured'])) {
                continue;
            }
            $need = class_exists('wps_ic_atf_observation') ? (int) wps_ic_atf_observation::needPxOf($document, $device, true) : 0;
            $rows = [];
            foreach (['lcp_element', 'lcp'] as $field) {
                if (isset($document[$field][$device]) && is_array($document[$field][$device])) {
                    $rows[] = $document[$field][$device];
                    break;
                }
            }
            if (isset($document['atf_images'][$device]) && is_array($document['atf_images'][$device])) {
                foreach ($document['atf_images'][$device] as $row) {
                    if (is_array($row) && isset($row['role']) && $row['role'] === 'hero') {
                        $rows[] = $row;
                    }
                }
            }
            foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
                $type = isset($row['type']) && is_string($row['type']) ? strtolower($row['type']) : '';
                if ($type === 'video' || $type === 'text' || $type === 'none') {
                    continue;
                }
                foreach (['fetched_url', 'src', 'url', 'entry_url'] as $field) {
                    if (empty($row[$field]) || !is_string($row[$field])) {
                        continue;
                    }
                    $res = self::resolve($row[$field]);
                    if ($res === []) {
                        continue;
                    }
                    $served = ($field === 'fetched_url' && isset($row['fetched_bytes']) && is_numeric($row['fetched_bytes'])) ? max(0, (int) $row['fetched_bytes']) : 0;
                    $seen = false;
                    foreach ($out as $at => $known) {
                        if ($known['res']['id'] === $res['id'] && $known['res']['file'] === $res['file']) {
                            $seen = true;
                            if ($served > 0 && ($known['served'] <= 0 || $served < $known['served'])) {
                                $out[$at]['served'] = $served;
                            }
                            $out[$at]['need'] = max($known['need'], $need);
                            if ($need > 0 && !in_array($need, $known['needs'], true)) {
                                $out[$at]['needs'][] = $need;
                            }
                        }
                    }
                    if (!$seen) {
                        $out[] = ['device' => $device, 'res' => $res, 'served' => $served, 'addr' => $row[$field], 'need' => $need, 'needs' => $need > 0 ? [$need] : []];
                    }
                    break;
                }
            }
        }
        foreach ($out as $at => $job) {
            $ideal = $job['need'] > 0 ? self::idealFor($job['res'], $job['need']) : null;
            $out[$at]['ideal'] = $ideal;
            $out[$at]['targets'] = self::targetsOf($job['res'], $ideal, $job['needs']);
        }
        return $out;
    }

    /**
     * The ideal-width rendition a hero of $need device pixels is to be given, or null when none is to be asked for. Null when no width is
     * known, when the upload's dimensions are not, and when some rung on disk (a -WxH file beside the upload, in any format) has the
     * upload's aspect (within 2 percent) and is at least $need wide. Otherwise the width is $need rounded up to the next 64 px, and a width
     * that reaches the upload's own is the upload itself (the scaled or unscaled file): the answer is then that rendition's resolve() row
     * (null when it is the hero's own). Below it the answer is a row for the rendition `<stem><wpc_v2_adaptive_variant_suffix(w)>` with
     * `variant`, the per-variant envelope entry (AVIF only, h on the upload's aspect), and `ideal` true.
     */
    public static function idealFor(array $res, $need)
    {
        $need = (int) $need;
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $res['id']) : [];
        $mw = (is_array($meta) && !empty($meta['width'])) ? (int) $meta['width'] : 0;
        $mh = (is_array($meta) && !empty($meta['height'])) ? (int) $meta['height'] : 0;
        if ($need < 64 || $mw < 1 || $mh < 1 || empty($meta['file']) || !function_exists('wpc_v2_adaptive_variant_suffix')) {
            return null;
        }
        $upload = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        if (!is_array($upload) || empty($upload['baseurl']) || empty($upload['basedir']) || !empty($upload['error'])) {
            return null;
        }
        $file = (string) $meta['file'];
        $dir = (strpos($file, '/') !== false) ? substr($file, 0, (int) strrpos($file, '/')) : '';
        $bare = (string) preg_replace('/\.[^.]+$/', '', basename($file));
        $stem = (string) preg_replace('/-scaled$/', '', $bare);
        $folder = rtrim((string) $upload['basedir'], '/') . '/' . ($dir !== '' ? $dir . '/' : '');
        $width = (int) (ceil($need / 64) * 64);
        if ($width >= $mw) {
            if (self::sizeOnDisk($folder, $stem, $mw, $mh, $need, '')) {
                return null;
            }
            $parent = self::resolve(rtrim((string) $upload['baseurl'], '/') . '/' . $file);
            return ($parent === [] || $parent['file'] === $res['file']) ? null : $parent;
        }
        $suffix = wpc_v2_adaptive_variant_suffix($width, $meta);
        if ($suffix === '') {
            return null;
        }
        $height = (int) round($width * $mh / $mw);
        $label = preg_match('/^-(\d+)w$/', $suffix, $nw) ? $nw[1] . 'w' : ltrim($suffix, '-');
        $name = $stem . $suffix;
        if (self::sizeOnDisk($folder, $stem, $mw, $mh, $need, $name)) {
            return null;
        }
        $segments = [];
        foreach (explode('/', ($dir !== '' ? $dir . '/' : '') . $name . '.avif') as $segment) {
            $segments[] = rawurlencode($segment);
        }
        $variant = [
            'sizeLabel'     => $label,
            'maxWidth'      => $width,
            'maxHeight'     => $height,
            'crop'          => false,
            'filenames'     => ['jpeg' => $name . '.jpg', 'webp' => $name . '.webp', 'avif' => $name . '.avif'],
            'originalBytes' => 0,
        ];
        if (apply_filters('wpc_envelope_per_variant_formats', (string) get_option('wpc_envelope_per_variant_formats', '1') === '1')) {
            $variant['formats'] = ['avif'];
        }
        return [
            'id'       => (int) $res['id'],
            'size'     => $label,
            'name'     => $name,
            'dir'      => $dir,
            'ext'      => $res['ext'],
            'file'     => $name . '.avif',
            'twin_abs' => $folder . $name . '.avif',
            'twin_url' => rtrim((string) $upload['baseurl'], '/') . '/' . implode('/', $segments),
            'width'    => $width,
            'parent'   => false,
            'ideal'    => true,
            'variant'  => $variant,
        ];
    }

    /**
     * The rung a hero of $need device pixels may be served from when it is not the rendition $res names: the smallest -WxH file of the attachment on
     * disk (any format) at least $need wide with the aspect of $res (within 2 percent), as its resolve() row, with `served_bytes` the bytes of the file the
     * CDN would deliver for it (the WebP beside it, else the JPEG) and `covering` true. Null when $res already is the smallest rung that covers the need,
     * when no rung does (idealFor() asks for one then) and when the rung has no file a rendition can be resolved from.
     */
    public static function coveringRung(array $res, $need)
    {
        $need = (int) $need;
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $res['id']) : [];
        $mw = (is_array($meta) && !empty($meta['width'])) ? (int) $meta['width'] : 0;
        $mh = (is_array($meta) && !empty($meta['height'])) ? (int) $meta['height'] : 0;
        if ($need < 64 || $mw < 1 || $mh < 1 || empty($meta['file'])) {
            return null;
        }
        $upload = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        if (!is_array($upload) || empty($upload['baseurl']) || empty($upload['basedir']) || !empty($upload['error'])) {
            return null;
        }
        $file = (string) $meta['file'];
        $dir = (strpos($file, '/') !== false) ? substr($file, 0, (int) strrpos($file, '/')) : '';
        $stem = (string) preg_replace('/-scaled$/', '', (string) preg_replace('/\.[^.]+$/', '', basename($file)));
        $folder = rtrim((string) $upload['basedir'], '/') . '/' . ($dir !== '' ? $dir . '/' : '');
        $aspect = $mw / $mh;
        if (preg_match('/-(\d{2,5})x(\d{2,5})$/', (string) $res['name'], $own) && (int) $own[2] > 0) {
            $aspect = (int) $own[1] / (int) $own[2];
        }
        $best = null;
        foreach ((array) @glob($folder . preg_replace('/([\[\]*?\\\\])/', '\\\\$1', $stem) . '-*') as $path) {
            $name = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($path));
            if ($name === (string) $res['name'] || !preg_match('/^' . preg_quote($stem, '/') . '-(\d{2,5})x(\d{2,5})$/', $name, $dims)) {
                continue;
            }
            $width = (int) $dims[1];
            $height = (int) $dims[2];
            if ($height < 1 || $width < $need || abs($width / $height - $aspect) > 0.02 * $aspect) {
                continue;
            }
            if ($best === null || $width < $best['width'] || ($width === $best['width'] && strcmp($name, $best['name']) < 0)) {
                $best = ['name' => $name, 'width' => $width];
            }
        }
        if ($best === null || ((int) $res['width'] >= $need && $best['width'] >= (int) $res['width'])) {
            return null;
        }
        $ext = '';
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $candidate) {
            if (@is_file($folder . $best['name'] . '.' . $candidate)) {
                $ext = $candidate;
                break;
            }
        }
        if ($ext === '') {
            return null;
        }
        $row = self::resolve(rtrim((string) $upload['baseurl'], '/') . '/' . ($dir !== '' ? $dir . '/' : '') . $best['name'] . '.' . $ext);
        if ($row === [] || $row['file'] === $res['file']) {
            return null;
        }
        $bytes = 0;
        foreach (['webp', 'jpg', 'jpeg', 'png'] as $candidate) {
            if (@is_file($folder . $best['name'] . '.' . $candidate)) {
                $bytes = (int) @filesize($folder . $best['name'] . '.' . $candidate);
                break;
            }
        }
        $row['served_bytes'] = $bytes;
        $row['covering'] = true;
        return $row;
    }

    /** True when a -WxH rung of the attachment, in any format, has the upload's aspect (within 2 percent) and is at least $need wide. The upload itself is the ceiling, not a rung, and so is the ideal rendition's own name ($except), which a landed twin of the job must not disqualify. */
    private static function sizeOnDisk($folder, $stem, $mw, $mh, $need, $except)
    {
        $reference = $mw / $mh;
        $names = [];
        foreach ((array) @glob($folder . preg_replace('/([\[\]*?\\\\])/', '\\\\$1', $stem) . '-*') as $path) {
            $names[(string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($path))] = true;
        }
        foreach (array_keys($names) as $name) {
            if ($name === $except || !preg_match('/^' . preg_quote($stem, '/') . '-(\d{2,5})x(\d{2,5})$/', $name, $dims)) {
                continue;
            }
            $width = (int) $dims[1];
            $height = (int) $dims[2];
            if ($width >= $need && $height > 0 && abs($width / $height - $reference) <= 0.02 * $reference) {
                return true;
            }
        }
        return false;
    }

    /**
     * An image address of this site (the zone's spelling, a transform wrapper, the origin's, a next-gen twin or the upload itself) resolved to
     * the rendition it names: {id, size, name, dir, ext, file, twin_abs, twin_url, width, parent}, or [] for an address that is not an upload of
     * this install with an attachment. `file` is the twin's file name (the rendition's name with .avif), `size` the rendition's size label.
     */
    public static function resolve($address)
    {
        $address = html_entity_decode(trim((string) $address), ENT_QUOTES);
        if ($address === '' || stripos($address, 'data:') === 0) {
            return [];
        }
        $wrapped = strrpos($address, '/u:http');
        if ($wrapped !== false) {
            $address = substr($address, $wrapped + 3);
        }
        if (preg_match('#^https?://[^/]+/m:0/a:(https?://.+)$#i', $address, $found)) {
            $address = $found[1];
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($address) : parse_url($address);
        if (!is_array($parts) || empty($parts['path'])) {
            return [];
        }
        $path = rawurldecode((string) $parts['path']);
        if (!preg_match('/\.(?:jpe?g|png|webp|avif)$/i', $path)) {
            return [];
        }
        $hint = '';
        if (!empty($parts['query']) && preg_match('/(?:^|&)src=(jpe?g|png|webp)(?:&|$)/i', (string) $parts['query'], $hintMatch)) {
            $hint = strtolower($hintMatch[1]);
        }
        $upload = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        if (!is_array($upload) || empty($upload['baseurl']) || empty($upload['basedir']) || !empty($upload['error'])) {
            return [];
        }
        $basePath = rtrim((string) parse_url((string) $upload['baseurl'], PHP_URL_PATH), '/');
        $rel = '';
        if ($basePath !== '' && strpos($path, $basePath . '/') === 0) {
            $rel = substr($path, strlen($basePath) + 1);
        } elseif (($at = strpos($path, '/wp-content/uploads/')) !== false) {
            $rel = substr($path, $at + strlen('/wp-content/uploads/'));
        }
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return [];
        }
        $dir = (strpos($rel, '/') !== false) ? substr($rel, 0, (int) strrpos($rel, '/')) : '';
        $name = (string) preg_replace('/(?:\.(?:avif|webp|jpe?g|png))+$/i', '', basename($rel));
        if ($name === '') {
            return [];
        }
        $basedir = rtrim((string) $upload['basedir'], '/');
        $folder = $basedir . '/' . ($dir !== '' ? $dir . '/' : '');
        $stem = (string) preg_replace('/-\d{2,5}x\d{2,5}$/', '', $name);
        $own = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $exts = array_values(array_unique(array_filter([$hint, in_array($own, ['jpg', 'jpeg', 'png'], true) ? $own : '', 'jpg', 'jpeg', 'png', 'webp'])));
        $ext = '';
        foreach ($exts as $candidate) {
            $beside = $folder . $name . '.' . $candidate;
            $parent = $folder . $stem . '.' . $candidate;
            if (@is_file($beside) || @is_file($parent) || @is_file($folder . $stem . '-scaled.' . $candidate)) {
                $ext = $candidate;
                break;
            }
        }
        if ($ext === '') {
            return [];
        }
        $prefix = rtrim((string) $upload['baseurl'], '/') . '/' . ($dir !== '' ? $dir . '/' : '');
        $id = 0;
        foreach (array_unique([$stem, $name]) as $candidate) {
            $candidateUrl = $prefix . $candidate . '.' . $ext;
            $candidateRel = ($dir !== '' ? $dir . '/' : '') . $candidate . '.' . $ext;
            $found = function_exists('wpc_v2_lazy_resolve_attachment') ? (int) wpc_v2_lazy_resolve_attachment($candidateUrl, $candidateRel)
                : (function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($candidateUrl) : 0);
            if ($found > 0) {
                $id = $found;
                break;
            }
        }
        if ($id <= 0) {
            return [];
        }
        $size = self::sizeOf($id, $name);
        $segments = [];
        foreach (explode('/', ($dir !== '' ? $dir . '/' : '') . $name . '.avif') as $segment) {
            $segments[] = rawurlencode($segment);
        }
        return [
            'id'       => $id,
            'size'     => $size['label'],
            'name'     => $name,
            'dir'      => $dir,
            'ext'      => $ext,
            'file'     => $name . '.avif',
            'twin_abs' => $folder . $name . '.avif',
            'twin_url' => rtrim((string) $upload['baseurl'], '/') . '/' . implode('/', $segments),
            'width'    => $size['width'],
            'parent'   => $size['parent'],
        ];
    }

    /** The size label, width and parent flag of the rendition called $name on an attachment (the labels the lazy lane records: scaled, unscaled, original, a WordPress size, WxH). */
    private static function sizeOf($id, $name)
    {
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $id) : [];
        $meta = is_array($meta) ? $meta : [];
        $bare = static function ($file) {
            return (string) preg_replace('/\.[^.]+$/', '', basename((string) $file));
        };
        $width = !empty($meta['width']) ? (int) $meta['width'] : 0;
        if (!empty($meta['file']) && $bare($meta['file']) === $name) {
            return ['label' => stripos($name, '-scaled') !== false ? 'scaled' : 'unscaled', 'width' => $width, 'parent' => true];
        }
        if (!empty($meta['original_image']) && $bare($meta['original_image']) === $name) {
            return ['label' => 'original', 'width' => $width, 'parent' => true];
        }
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $label => $info) {
                if (is_array($info) && !empty($info['file']) && $bare($info['file']) === $name) {
                    return ['label' => (string) $label, 'width' => !empty($info['width']) ? (int) $info['width'] : 0, 'parent' => false];
                }
            }
        }
        if (preg_match('/-(\d{2,5})x(\d{2,5})$/', $name, $dims)) {
            return ['label' => $dims[1] . 'x' . $dims[2], 'width' => (int) $dims[1], 'parent' => false];
        }
        if (preg_match('/-(\d{2,5})w$/', $name, $dims)) {
            return ['label' => $dims[1] . 'w', 'width' => (int) $dims[1], 'parent' => false];
        }
        return ['label' => $name, 'width' => $width, 'parent' => true];
    }

    // ─── the receipt and the retry book ──────────────────────────────────────────────────────

    /** The receipt for one twin file, or [] when none. */
    public static function receipt($id, $file)
    {
        $map = self::map((int) $id, self::META);
        if (!isset($map[$file]) || !is_array($map[$file])) {
            return [];
        }
        $r = $map[$file];
        return [
            'state' => isset($r['state']) ? (string) $r['state'] : '',
            'at'    => isset($r['at']) ? (int) $r['at'] : 0,
            'sha'   => isset($r['sha']) ? (string) $r['sha'] : '',
            'size'  => isset($r['size']) ? (string) $r['size'] : '',
            'file'  => (string) $file,
        ];
    }

    private static function record(array $res, array $verdict)
    {
        $map = self::map((int) $res['id'], self::META);
        $map[(string) $res['file']] = [
            'state' => (string) $verdict['state'],
            'at'    => time(),
            'sha'   => (string) $verdict['sha'],
            'size'  => (string) $res['size'],
            'file'  => (string) $res['file'],
        ];
        update_post_meta((int) $res['id'], self::META, $map);
        self::log('hero-land-proof', (string) $res['id'], ['id' => (int) $res['id'], 'file' => (string) $res['file'], 'state' => (string) $verdict['state'], 'why' => (string) $verdict['why']]);
    }

    public static function book($id, $file)
    {
        $map = self::map((int) $id, self::META_TRY);
        if (!isset($map[$file]) || !is_array($map[$file])) {
            return [];
        }
        $b = $map[$file];
        return [
            'n'       => isset($b['n']) ? (int) $b['n'] : 0,
            'defers'  => isset($b['defers']) ? (int) $b['defers'] : 0,
            'last'    => isset($b['last']) ? (int) $b['last'] : 0,
            'next'    => isset($b['next']) ? (int) $b['next'] : 0,
            'why'     => isset($b['why']) ? (string) $b['why'] : '',
            'gave_up' => isset($b['gave_up']) ? (int) $b['gave_up'] : 0,
            'addr'    => isset($b['addr']) ? (string) $b['addr'] : '',
            'served'  => isset($b['served']) ? (int) $b['served'] : 0,
            'key'     => isset($b['key']) ? (string) $b['key'] : '',
            'ideal'   => isset($b['ideal']) ? (int) $b['ideal'] : 0,
            'need'    => isset($b['need']) ? (int) $b['need'] : 0,
            'needs'   => (isset($b['needs']) && is_array($b['needs'])) ? array_slice(array_values(array_filter(array_map('intval', $b['needs']), static function ($n) { return $n > 0; })), 0, 4) : [],
        ];
    }

    private static function saveBook($id, $file, array $book)
    {
        $map = self::map((int) $id, self::META_TRY);
        $map[(string) $file] = $book;
        update_post_meta((int) $id, self::META_TRY, $map);
    }

    private static function clearBook($id, $file)
    {
        $map = self::map((int) $id, self::META_TRY);
        if (isset($map[(string) $file])) {
            unset($map[(string) $file]);
            update_post_meta((int) $id, self::META_TRY, $map);
        }
    }

    private static function map($id, $key)
    {
        if ($id <= 0) {
            return [];
        }
        wp_cache_delete($id, 'post_meta');
        $value = get_post_meta($id, $key, true);
        return is_array($value) ? $value : [];
    }

    private static function log($event, $key, array $layers)
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($event, (string) $key, '', $layers);
        }
    }

    /**
     * The land report's line for one page: each hero's twin, its state, the tries made and whether it gave up. [] while the cohort is
     * off or the page has no measured hero.
     */
    public static function report($key)
    {
        if (!self::landOn() || !defined('WPS_IC_CRITICAL')) {
            return [];
        }
        $document = self::document(ltrim((string) $key, '/'));
        if ($document === null) {
            return [];
        }
        $rows = [];
        foreach (self::heroes($document) as $job) {
            $book = self::book($job['res']['id'], $job['res']['file']);
            foreach ($job['targets'] as $target) {
                $rec = self::receipt($target['id'], $target['file']);
                $rows[] = [
                    'd' => substr((string) $job['device'], 0, 1),
                    'f' => substr((string) $target['file'], 0, 60),
                    's' => $rec === [] ? 'none' : $rec['state'],
                    'n' => (int) ($book['n'] ?? 0),
                    'g' => empty($book['gave_up']) ? 0 : 1,
                    'w' => substr((string) ($book['why'] ?? ''), 0, 24),
                ];
            }
        }
        $rows = array_slice($rows, 0, 6);
        return $rows;
    }

    // ─── rule 8 ──────────────────────────────────────────────────────────────────────────────

    /**
     * A landed twin is on disk for a page: with rule 8 on, the page's stored copy is purged once for this file (this sha), so the next copy names it.
     * The purge is the coalesced soft purge of a land (wpc_land_purge_coalesced); nothing happens while hero_same_origin is off.
     */
    private static function surface($key, array $targets)
    {
        $key = (string) $key;
        if ($key === '' || !self::sameOriginOn() || !function_exists('wpc_land_purge_coalesced') || !function_exists('get_transient')) {
            return false;
        }
        $names = [];
        foreach ($targets as $target) {
            $rec = self::receipt($target['id'], $target['file']);
            if ($rec !== [] && $rec['state'] === 'landed') {
                $names[] = $target['file'] . ':' . $rec['sha'];
            }
        }
        if ($names === []) {
            return false;
        }
        $held = 'wpc_hero_land_applied_' . md5($key . '|' . implode(',', $names));
        if (get_transient($held)) {
            return false;
        }
        set_transient($held, 1, 7 * DAY_IN_SECONDS);
        wpc_land_purge_coalesced($key, '', 'hero-land');
        self::log('hero-land-surface', $key, ['n' => count($names)]);
        return true;
    }

    /**
     * The site-domain twin an address may be renamed to under rule 8, or '' to leave it as it is: the cohort is on, the address names an
     * upload of this install, the attachment's receipt for that rendition says landed, and the file on disk is still the file that was
     * proven (its sha256 is the receipt's). No receipt, a receipt in any other state, a missing file and a changed file are all ''.
     */
    public static function twinFor($address)
    {
        if (!self::sameOriginOn()) {
            return '';
        }
        $res = self::resolve($address);
        return $res === [] ? '' : self::twinOfRes($res);
    }

    /** The twin's address when the rendition's receipt says landed and the file is the one proven, else ''. */
    public static function twinOfRes(array $res)
    {
        $rec = self::receipt($res['id'], $res['file']);
        if ($rec === [] || $rec['state'] !== 'landed' || !self::stillLanded($res, $rec)) {
            return '';
        }
        return (string) $res['twin_url'];
    }

    /**
     * RULE 8 over the finished document of the CDN lane. Every address of a hero rendition whose twin is landed and proven (twinOfRes) is renamed to
     * the twin on the site's domain, wherever the document paints it, in one pass over the final markup, so the preload and the paint are one URL:
     *   - <link rel=preload as=image>: href and the imagesrcset entries, with type="image/avif" (a browser without AVIF skips the preload);
     *   - <img> outside a <picture>, eager only: src and srcset entries, with the image's own fallback (data-wpc-fb and onerror) kept or added,
     *     so a browser that cannot decode AVIF loads the file the tag named before;
     *   - <source type="image/avif"> inside a <picture>: srcset entries (the type gate is the fallback; the <img> and the other arms are untouched);
     *   - background declarations of <style> blocks and style attributes: the declaration is kept and followed by the same declaration with the
     *     url() in image-set(url(twin) type("image/avif"), url(old)), so a browser that does not read it keeps the old declaration;
     *   - the preload set's written hero ($set->retargetWritten), which the after-hero starters are built from.
     * Only a plain spelling on the zone or the site's host is renamed (not a /u: or /w:N/ transform: that is another file). A stylesheet the document
     * links that names a rendition would paint the old address over the new, so it is dealt with first (sheetPlan()): one of the plugin's own sheets is
     * copied with the same rename inside the copy and the link is swapped to the copy (the sheet itself is never touched); a sheet that is not ours holds
     * the rendition back. The whole document is left as it was when any use of a renamed rendition cannot be renamed (a background is the only use that can).
     * A phone's copy whose background source the service replaced (bg_mobile_src) is left to that cohort. Answers the document.
     * $document is the page's lcp.json decoded; null reads the page's own file.
     */
    public static function sameOriginPass($html, array $devices, $set = null, $document = null)
    {
        if (!is_string($html) || $html === '' || !self::sameOriginOn() || !class_exists('wps_ic_image_preload_set') || !class_exists('wps_ic_atf_observation')) {
            return $html;
        }
        $note = static function ($state, array $fields = []) {
            if (function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('hero-same-origin', ['state' => $state] + $fields);
            }
        };
        try {
            if (!is_array($document)) {
                $file = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_page_lcp_json_file')) ? (string) wps_rewriteLogic::wpc_page_lcp_json_file() : '';
                if ($file === '' || !@is_readable($file) || (int) @filesize($file) > self::LCP_BYTES_MAX) {
                    return $html;
                }
                $document = json_decode((string) @file_get_contents($file), true);
            }
            if (!is_array($document)) {
                return $html;
            }
            $rows = [];
            foreach (self::heroes($document, $devices) as $job) {
                if ($job['device'] === 'mobile' && class_exists('wps_ic_atf_observation') && wps_ic_atf_observation::bgMobileSource('mobile') !== []) {
                    $note('kept', ['why' => 'phone-bg-source']);
                    continue;
                }
                foreach ($job['targets'] as $target) {
                    $twin = self::twinOfRes($target);
                    if ($twin !== '') {
                        $rec = self::receipt($target['id'], $target['file']);
                        $rows[$target['file']] = ['res' => $target, 'twin' => $twin, 'sha' => (string) $rec['sha']];
                    }
                }
            }
            if ($rows === []) {
                return $html;
            }
            $held = [];
            $heldAt = [];
            $plan = ['swaps' => [], 'copies' => 0];
            for ($round = 0; $round < 3 && $rows !== []; $round++) {
                $plan = self::sheetPlan($html, $rows);
                $fresh = array_diff_key($plan['holds'], $held);
                $held += $plan['holds'];
                $heldAt += $plan['sheets'];
                foreach ($plan['holds'] as $name => $why) {
                    unset($rows[$name]);
                }
                if ($fresh === []) {
                    break;
                }
            }
            $heldFields = $held === [] ? [] : ['why' => implode(',', array_unique($held))] + ($heldAt === [] ? [] : ['sheet' => (string) reset($heldAt)]);
            if ($rows === []) {
                $note('kept', $heldFields);
                return $html;
            }
            $stats = ['preloads' => 0, 'imgs' => 0, 'sources' => 0, 'css' => 0, 'attrs' => 0, 'left' => 0, 'sheets' => 0];
            $out = self::renamePictures($html, $rows, $stats, $pictures);
            $out = self::renamePreloads($out, $rows, $stats);
            $out = self::renameImages($out, $rows, $stats);
            $out = self::renameCss($out, $rows, $stats);
            foreach ($pictures as $at => $block) {
                $out = str_replace("\x01P" . $at . "\x02", $block, $out);
            }
            if ($stats['left'] > 0) {
                $note('kept', ['why' => 'residual', 'left' => $stats['left']]);
                return $html;
            }
            $out = self::swapSheets($out, $plan['swaps'], $stats);
            $changed = $stats['preloads'] + $stats['imgs'] + $stats['sources'] + $stats['css'] + $stats['attrs'] + $stats['sheets'];
            $note($changed > 0 ? 'applied' : 'unapplied', $stats + ['heroes' => count($rows), 'held' => count($held), 'copies' => (int) $plan['copies']]
                + ($heldFields !== [] ? $heldFields : ($changed > 0 ? [] : ['why' => 'nothing-named'])));
            if ($changed === 0) {
                return $html;
            }
            if ($set !== null && is_object($set) && method_exists($set, 'retargetWritten')) {
                $set->retargetWritten(static function ($address) use ($rows) {
                    return self::matchTwin(html_entity_decode((string) $address, ENT_QUOTES), $rows);
                });
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * What the linked stylesheets of the document do to the renditions in $rows: {holds: file => why, swaps: original href => the copy's href, copies: how many
     * copies this plan wrote or found}. A sheet that names a rendition is one of three things.
     *   - Ours (a file of the plugin's own cache: the combined and sidecar sheets and the used-css under cache/critical, the processed copies under cache/wp-cio/css, the copies under
     *     cache/wpc-bgset, wpc-hostfix and wpc-fontsheet): a copy of it is written to the content-addressed store under cache/wp-cio/css with the proven renditions renamed inside it (sheetCopy()),
     *     and the link is swapped to the copy. The sheet itself is never touched; the copy's name carries the sha of every receipt it renames, so a receipt that changes names another copy.
     *     A copy is never deleted here: the store's one collector (wpc_processed_copy_gc) removes it when no page copy this server can still serve links it.
     *   - Not ours (the site's own file, another plugin's cache, a host that is not the site's or its zone's): the rendition is held back unless an eager <img> paints it and no CSS in
     *     the document does (the sheet then paints some other element with the same file).
     *   - A sheet of ours that cannot be copied with certainty (a reference that cannot be rebased, a pinned integrity hash, a use of the file outside a background declaration, a write that
     *     failed): the rendition it names is held back in the same way.
     */
    private static function sheetPlan($html, array $rows)
    {
        $plan = ['holds' => [], 'swaps' => [], 'copies' => 0, 'sheets' => []];
        $names = [];
        foreach ($rows as $file => $row) {
            $names[$file] = (string) $row['res']['name'];
        }
        $sheets = self::linkedSheets($html);
        if ($sheets === []) {
            return $plan;
        }
        $paints = null;
        foreach ($sheets as $href => $tags) {
            $info = self::sheetInfo($href);
            if ($info === null) {
                continue;
            }
            $named = self::sheetNames($info, $names);
            if ($named === []) {
                continue;
            }
            $why = '';
            $copy = $info['ours'] ? self::sheetCopy($info, $href, $tags[0], array_intersect_key($rows, array_flip($named)), $why) : null;
            if ($copy !== null) {
                $plan['swaps'][$href] = $copy['url'];
                $plan['copies']++;
                continue;
            }
            foreach ($named as $file) {
                if ($paints === null) {
                    $paints = self::documentPaints($html, $names);
                }
                $row = $paints[$file] ?? ['markup' => false, 'css' => false];
                if ($info['ours'] || !$row['markup'] || $row['css']) {
                    $plan['holds'][$file] = $info['ours'] ? ($why !== '' ? 'sheet-' . $why : 'sheet') : 'sheet';
                    $plan['sheets'][$file] = self::sheetRelative($info);
                }
            }
        }
        return $plan;
    }

    /** The sheet's path under wp-content (no host, no query), at most 120 characters: what a receipt names a sheet by. */
    private static function sheetRelative(array $info)
    {
        $path = (string) $info['path'];
        $at = strpos($path, '/wp-content/');
        if ($at !== false) {
            return substr(substr($path, $at + strlen('/wp-content/')), 0, 120);
        }
        $disk = str_replace('\\', '/', (string) $info['disk']);
        $root = defined('WP_CONTENT_DIR') ? rtrim(str_replace('\\', '/', (string) WP_CONTENT_DIR), '/') . '/' : '';
        return substr(($root !== '' && strpos($disk, $root) === 0) ? substr($disk, strlen($root)) : basename($disk), 0, 120);
    }

    /** Per rendition file: whether an eager <img> of the document names it (markup) and whether a <style> block of the document does (css). */
    private static function documentPaints($html, array $names)
    {
        $out = [];
        foreach ($names as $file => $name) {
            $out[$file] = ['markup' => false, 'css' => false];
        }
        if (preg_match_all('/<img\b[^>]*>/i', $html, $imgs)) {
            foreach ($imgs[0] as $tag) {
                if (preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $tag)) {
                    continue;
                }
                foreach ($names as $file => $name) {
                    if (stripos($tag, $name . '.') !== false) {
                        $out[$file]['markup'] = true;
                    }
                }
            }
        }
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $styles)) {
            foreach ($styles[1] as $body) {
                foreach ($names as $file => $name) {
                    if (stripos($body, $name . '.') !== false) {
                        $out[$file]['css'] = true;
                    }
                }
            }
        }
        return $out;
    }

    /** The stylesheet addresses the document links, each with the <link> tags that carry it (href, and data-wpc-rest and data-wpc-lf-href of a parked sheet), at most 40. */
    private static function linkedSheets($html)
    {
        $found = [];
        if (!preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            return $found;
        }
        foreach ($tags[0] as $tag) {
            $sheet = preg_match('/\brel\s*=\s*["\']?[a-z-]*stylesheet/i', $tag)
                || (preg_match('/\brel\s*=\s*["\']?preload/i', $tag) && preg_match('/\bas\s*=\s*["\']?style/i', $tag))
                || preg_match('/\sdata-wpc-(?:rest|lf-href)\s*=/i', $tag);
            if (!$sheet) {
                continue;
            }
            foreach (['href', 'data-wpc-rest', 'data-wpc-lf-href'] as $attribute) {
                if (preg_match('/(?<![\w:-])' . preg_quote($attribute, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $value)) {
                    $address = html_entity_decode($value[2], ENT_QUOTES);
                    if (preg_match('/\.css(?:[?#]|$)/i', $address) && (isset($found[$address]) || count($found) < 40)) {
                        $found[$address][] = $tag;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Where a linked stylesheet is on this install: {disk, path, host, scheme, onZone, ours, size, mtime, stem}, or null for an address that is not
     * a readable file of this site (another host, a relative address, a file that is not there or is empty or over the cap).
     */
    private static function sheetInfo($href)
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($href) : parse_url($href);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '';
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($path === '' || $path[0] !== '/' || strpos($path, '..') !== false || strpos($path, '/wp-') === false || ($host !== '' && !wps_ic_atf_observation::ownHost($host))) {
            return null;
        }
        $sitePath = function_exists('site_url') ? rtrim((string) parse_url((string) site_url('/'), PHP_URL_PATH), '/') : '';
        $relative = ($sitePath !== '' && strpos($path, $sitePath . '/') === 0) ? substr($path, strlen($sitePath)) : $path;
        $disk = function_exists('wpc_install_file_real') ? wpc_install_file_real(rtrim((string) ABSPATH, '/') . $relative) : rtrim((string) ABSPATH, '/') . $relative;
        if ($disk === '' || !@is_file($disk)) {
            return null;
        }
        $size = (int) @filesize($disk);
        if ($size < 1 || $size > self::LCP_BYTES_MAX) {
            return null;
        }
        $zones = [];
        foreach (['ic_cdn_zone_name', 'ic_custom_cname'] as $option) {
            $zoneHost = function_exists('get_option') ? strtolower((string) strtok((string) get_option($option), '/')) : '';
            if ($zoneHost !== '') {
                $zones[$zoneHost] = true;
            }
        }
        $stem = (string) preg_replace('/\.css$/i', '', basename($path));
        while (preg_match('/^(.+)-[0-9a-f]{10}$/', $stem, $parent)) {
            $stem = $parent[1];
        }
        return [
            'disk' => $disk, 'path' => $path, 'host' => $host, 'size' => $size, 'mtime' => (int) @filemtime($disk), 'stem' => $stem,
            'scheme' => (is_array($parts) && isset($parts['scheme'])) ? strtolower((string) $parts['scheme']) : '',
            'onZone' => $host !== '' && isset($zones[$host]), 'ours' => self::ourSheet($disk),
        ];
    }

    /** True for a file the plugin writes: its cache folders for combined, sidecar, used-css, processed and bg-source sheets. */
    private static function ourSheet($disk)
    {
        $disk = str_replace('\\', '/', (string) $disk);
        $folders = [];
        foreach ([defined('WPS_IC_CRITICAL') ? WPS_IC_CRITICAL : '', defined('WPS_IC_CSS') ? WPS_IC_CSS : ''] as $folder) {
            if ($folder !== '') {
                $folders[] = rtrim(str_replace('\\', '/', (string) $folder), '/') . '/';
            }
        }
        if (defined('WP_CONTENT_DIR')) {
            foreach (['wpc-bgset', 'wpc-hostfix', 'wpc-fontsheet'] as $folder) {
                $folders[] = rtrim(str_replace('\\', '/', (string) WP_CONTENT_DIR), '/') . '/cache/' . $folder . '/';
            }
        }
        foreach ($folders as $folder) {
            if (strpos($disk, $folder) === 0) {
                return true;
            }
        }
        return false;
    }

    /** The files (keys of $names) whose rendition name the sheet's bytes carry as `<name>.`; remembered under the sheet's path, size and time. */
    private static function sheetNames(array $info, array $names)
    {
        static $memo = [];
        $key = md5($info['disk'] . '|' . $info['mtime'] . '|' . $info['size'] . '|' . implode(',', $names));
        if (!isset($memo[$key])) {
            $known = function_exists('get_transient') ? get_transient('wpc_heroset_n_' . $key) : false;
            if (is_array($known)) {
                $memo[$key] = $known;
            } else {
                $css = (string) @file_get_contents($info['disk']);
                $memo[$key] = [];
                foreach ($names as $file => $name) {
                    if (strpos($css, $name . '.') !== false) {
                        $memo[$key][] = $file;
                    }
                }
                if (function_exists('set_transient')) {
                    set_transient('wpc_heroset_n_' . $key, $memo[$key], DAY_IN_SECONDS);
                }
            }
        }
        return $memo[$key];
    }

    /**
     * The copy of one of our sheets with the renditions in $rows renamed inside it: {name, url}, or null with $why. The copy lives in the content-addressed store under cache/wp-cio/css as
     * `<sheet>.hero-<12 hex>.css`; the 12 hex are the hash of the sheet's path, size and time and of the file name and sha256 of every receipt the copy renames, so a sheet
     * that changes or a receipt that comes or goes names another copy, and an existing copy is the proof its build passed. A copy is made only when every use of a renamed
     * rendition in the sheet is a background declaration (renameDeclarations() leaves none behind) and every reference of the sheet can be rebased to where the copy is served.
     */
    private static function sheetCopy(array $info, $href, $tag, array $rows, &$why)
    {
        $why = '';
        if (!class_exists('wps_cdn_rewrite') || !method_exists('wps_cdn_rewrite', 'wpc_rebase_css_refs') || !defined('WPS_IC_CSS') || !function_exists('content_url')) {
            $why = 'writer';
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $info['stem']) !== 1) {
            $why = 'name';
            return null;
        }
        $shas = [];
        foreach ($rows as $file => $row) {
            $shas[] = $file . ':' . $row['sha'];
        }
        sort($shas);
        $key = md5(implode('|', [$info['disk'], $info['mtime'], $info['size'], implode(',', $shas), 'hero1']));
        $name = $info['stem'] . '.hero-' . substr($key, 0, 12) . '.css';
        $url = self::copyUrl($info, $name, $href);
        if ($url === '') {
            $why = 'url';
            return null;
        }
        $dir = rtrim((string) WPS_IC_CSS, '/');
        $file = $dir . '/' . $name;
        clearstatcache(true, $file);
        if (@is_file($file) && (int) @filesize($file) > 0) {
            if (time() - (int) @filemtime($file) > 86400) {
                @touch($file);
            }
            return ['name' => $name, 'url' => $url];
        }
        if (function_exists('wpc_tag_pins_integrity') && wpc_tag_pins_integrity($tag)) {
            $why = 'integrity';
            return null;
        }
        $css = (string) @file_get_contents($info['disk']);
        if ($css === '') {
            $why = 'read';
            return null;
        }
        $scheme = $info['scheme'] !== '' ? $info['scheme'] : 'https';
        $host = $info['host'] !== '' ? $info['host'] : strtolower((string) parse_url((string) home_url(), PHP_URL_HOST));
        $based = wps_cdn_rewrite::wpc_rebase_css_refs($css, $info['path'], $scheme . '://' . $host);
        if (!is_string($based)) {
            $why = 'rebase';
            return null;
        }
        $count = 0;
        $left = 0;
        $renamed = self::renameDeclarations($based, $rows, $count, $left);
        if ($count === 0 || $left > 0) {
            $why = $count === 0 ? 'unrenamed' : 'residual';
            return null;
        }
        if (!@is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $file . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 8);
        if (!function_exists('wpc_fs_put') || wpc_fs_put($tmp, $renamed) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            $why = 'write';
            return null;
        }
        if (function_exists('wpc_processed_copy_gc_schedule')) {
            wpc_processed_copy_gc_schedule(time() + 14 * 86400 + 600);
        }
        return ['name' => $name, 'url' => $url];
    }

    /** The address the copy is linked at, in the form the sheet was linked in: the zone's or the site's host (with the sheet's scheme) or a root-relative path. */
    private static function copyUrl(array $info, $name, $href)
    {
        $copy = (string) content_url('cache/wp-cio/css/' . $name);
        $path = (string) parse_url($copy, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }
        if ($info['host'] === '') {
            return $path;
        }
        return ($info['scheme'] !== '' ? $info['scheme'] . ':' : '') . '//' . $info['host'] . $path;
    }

    /** $html with the sheet addresses of $swaps (href, data-wpc-rest, data-wpc-lf-href of a <link>) pointing at their copies. */
    private static function swapSheets($html, array $swaps, array &$stats)
    {
        if ($swaps === []) {
            return $html;
        }
        $out = preg_replace_callback('/<link\b[^>]*>/i', static function ($link) use ($swaps, &$stats) {
            $changed = 0;
            $new = preg_replace_callback('/(?<![\w:-])(href|data-wpc-rest|data-wpc-lf-href)(\s*=\s*)(["\'])(.*?)\3/is', static function ($attr) use ($swaps, &$changed) {
                $value = html_entity_decode($attr[4], ENT_QUOTES);
                if (!isset($swaps[$value])) {
                    return $attr[0];
                }
                $changed++;
                return $attr[1] . $attr[2] . $attr[3] . self::encode($swaps[$value]) . $attr[3];
            }, $link[0]);
            if (!is_string($new) || $changed === 0) {
                return $link[0];
            }
            $stats['sheets'] += $changed;
            return $new;
        }, $html);
        return is_string($out) ? $out : $html;
    }

    /**
     * The twin of $address when it is a plain spelling of one of the renditions in $rows on the zone or the site's host, else ''.
     * A /u: wrapper or a /w:N/ transform is another file; an address that already is the twin is not renamed.
     */
    private static function matchTwin($address, array $rows)
    {
        $address = trim((string) $address);
        if ($address === '' || stripos($address, 'data:') === 0 || strpos($address, '/u:http') !== false) {
            return '';
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($address) : parse_url($address);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path']) || !wps_ic_atf_observation::ownHost($parts['host'])
            || preg_match('#/(?:q|r|wp|w|h|m|ar):[^/]+/#', (string) $parts['path']) || !preg_match('/\.(?:jpe?g|png|webp|avif)$/i', (string) $parts['path'])) {
            return '';
        }
        foreach ($rows as $row) {
            if (!wps_ic_image_preload_set::sameRendition($address, $row['twin'])) {
                continue;
            }
            $twin = wp_parse_url($row['twin']);
            if (strcasecmp((string) $parts['host'], (string) $twin['host']) === 0 && (string) $parts['path'] === (string) $twin['path']) {
                return '';
            }
            return (string) $row['twin'];
        }
        return '';
    }

    /** A srcset-shaped list with each plain address of a rendition renamed; $count is how many. */
    private static function renameList($list, array $rows, &$count)
    {
        $parts = [];
        foreach (preg_split('/\s*,\s*/', html_entity_decode((string) $list, ENT_QUOTES)) as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $descriptor = '';
            if (preg_match('/^(\S+)(\s+\d+(?:\.\d+)?[wx])$/', $candidate, $found)) {
                $candidate = $found[1];
                $descriptor = $found[2];
            }
            $twin = self::matchTwin($candidate, $rows);
            if ($twin !== '') {
                $count++;
                $candidate = $twin;
            }
            $parts[] = $candidate . $descriptor;
        }
        return implode(', ', $parts);
    }

    private static function encode($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /** <picture> blocks are lifted out of the document (placeholders in $pictures) after their AVIF arms are renamed, so the <img> inside stays the arm-less fallback it is. */
    private static function renamePictures($html, array $rows, array &$stats, &$pictures)
    {
        $pictures = [];
        $out = preg_replace_callback('#<picture\b[^>]*>.*?</picture>#is', static function ($block) use ($rows, &$stats, &$pictures) {
            $text = preg_replace_callback('/<source\b[^>]*>/i', static function ($source) use ($rows, &$stats) {
                $tag = $source[0];
                if (!preg_match('/\stype\s*=\s*(["\'])image\/avif\1/i', $tag)) {
                    return $tag;
                }
                $new = preg_replace_callback('/(\ssrcset\s*=\s*)(["\'])(.*?)\2/is', static function ($attr) use ($rows, &$stats) {
                    $count = 0;
                    $list = self::renameList($attr[3], $rows, $count);
                    if ($count === 0) {
                        return $attr[0];
                    }
                    $stats['sources'] += $count;
                    return $attr[1] . '"' . self::encode($list) . '"';
                }, $tag);
                return is_string($new) ? $new : $tag;
            }, $block[0]);
            $at = count($pictures);
            $pictures[$at] = is_string($text) ? $text : $block[0];
            return "\x01P" . $at . "\x02";
        }, $html);
        return is_string($out) ? $out : $html;
    }

    private static function renamePreloads($html, array $rows, array &$stats)
    {
        $out = preg_replace_callback('/<link\b[^>]*>/i', static function ($link) use ($rows, &$stats) {
            $tag = $link[0];
            if (!preg_match('/\brel\s*=\s*(["\']?)preload\1/i', $tag) || !preg_match('/\bas\s*=\s*(["\']?)image\1/i', $tag)) {
                return $tag;
            }
            $count = 0;
            $new = preg_replace_callback('/(?<![\w:.-])(href|imagesrcset)(\s*=\s*)(["\'])(.*?)\3/is', static function ($attr) use ($rows, &$count) {
                $changed = 0;
                $list = self::renameList($attr[4], $rows, $changed);
                if ($changed === 0) {
                    return $attr[0];
                }
                $count += $changed;
                return $attr[1] . $attr[2] . $attr[3] . self::encode($list) . $attr[3];
            }, $tag);
            if (!is_string($new) || $count === 0) {
                return $tag;
            }
            $stats['preloads'] += $count;
            if (!preg_match('/\stype\s*=/i', $new)) {
                $new = preg_replace('/^<link\b/i', '<link type="image/avif"', $new, 1);
            }
            return is_string($new) ? $new : $tag;
        }, $html);
        return is_string($out) ? $out : $html;
    }

    private static function renameImages($html, array $rows, array &$stats)
    {
        $fallback = "this.onerror=null;this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
        $out = preg_replace_callback('/<img\b[^>]*>/i', static function ($img) use ($rows, &$stats, $fallback) {
            $tag = $img[0];
            if (preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $tag) || stripos($tag, 'data-wpc-qw-src') !== false) {
                return $tag;
            }
            $count = 0;
            $new = preg_replace_callback('/(\s(?:src|srcset)\s*=\s*)(["\'])(.*?)\2/is', static function ($attr) use ($rows, &$count) {
                $changed = 0;
                $list = self::renameList($attr[3], $rows, $changed);
                if ($changed === 0) {
                    return $attr[0];
                }
                $count += $changed;
                return $attr[1] . '"' . self::encode($list) . '"';
            }, $tag);
            if (!is_string($new) || $count === 0) {
                return $tag;
            }
            $stats['imgs'] += $count;
            $was = preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $tag, $src) ? html_entity_decode($src[2], ENT_QUOTES) : '';
            if ($was !== '' && stripos($was, 'data:') !== 0 && stripos($new, ' onerror=') === false && stripos($new, ' data-wpc-fb=') === false) {
                $new = preg_replace('/^<img\b/i', '<img data-wpc-fb="' . self::encode($was) . '" onerror="' . $fallback . '"', $new, 1);
            }
            return is_string($new) ? $new : $tag;
        }, $html);
        return is_string($out) ? $out : $html;
    }

    private static function renameCss($html, array $rows, array &$stats)
    {
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['res']['name'];
        }
        $has = static function ($text) use ($names) {
            foreach ($names as $name) {
                if (stripos((string) $text, $name) !== false) {
                    return true;
                }
            }
            return false;
        };
        $out = preg_replace_callback('#(<style\b[^>]*>)(.*?)(</style>)#is', static function ($block) use ($rows, &$stats, $has) {
            if (!$has($block[2])) {
                return $block[0];
            }
            $count = 0;
            $css = self::renameDeclarations($block[2], $rows, $count, $stats['left']);
            $stats['css'] += $count;
            return $block[1] . $css . $block[3];
        }, $html);
        if (!is_string($out)) {
            return $html;
        }
        $out = preg_replace_callback('/(\sstyle\s*=\s*)(?:"([^"<>]*)"|\'([^\'<>]*)\')/i', static function ($attr) use ($rows, &$stats, $has) {
            $quote = (isset($attr[3]) && $attr[3] !== '') ? "'" : '"';
            $value = html_entity_decode(isset($attr[3]) && $attr[3] !== '' ? $attr[3] : $attr[2], ENT_QUOTES);
            if (!$has($value)) {
                return $attr[0];
            }
            $count = 0;
            $css = self::renameDeclarations($value, $rows, $count, $stats['left']);
            if ($count === 0) {
                return $attr[0];
            }
            $stats['attrs'] += $count;
            return $attr[1] . $quote . self::encode($css) . $quote;
        }, $out);
        return is_string($out) ? $out : $html;
    }

    /**
     * $css with each background declaration that names a renamed address followed by the same declaration with that url() as
     * image-set(url(twin) type("image/avif"), url(old)); inside an image-set already, the AVIF arm alone is renamed in place. $count is how many.
     */
    private static function renameDeclarations($css, array $rows, &$count, &$left = 0)
    {
        $saved = [];
        $protected = preg_replace_callback('/url\(\s*(?:"[^"]*"|\'[^\']*\'|[^)]*)\)|"[^"]*"|\'[^\']*\'/i', static function ($m) use (&$saved) {
            $saved[] = $m[0];
            return "\x01" . (count($saved) - 1) . "\x02";
        }, (string) $css);
        if (!is_string($protected)) {
            return $css;
        }
        $restore = static function ($text) use (&$saved) {
            return preg_replace_callback('/\x01(\d+)\x02/', static function ($m) use (&$saved) {
                return $saved[(int) $m[1]];
            }, $text);
        };
        $tokens = preg_split('/([{};])/', $protected, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($tokens as $token) {
            if ($token === '{' || $token === '}' || $token === ';' || strpos($token, "\x01") === false) {
                $out .= $token;
                continue;
            }
            if (!preg_match('/^\s*(?:-webkit-)?background(?:-image)?\s*:/i', $token)) {
                if (preg_match_all('/\x01(\d+)\x02/', $token, $holders)) {
                    foreach ($holders[1] as $holder) {
                        if (preg_match('/^url\(\s*(["\']?)(.*?)\1\s*\)$/is', $saved[(int) $holder], $url) && self::matchTwin(html_entity_decode($url[2], ENT_QUOTES), $rows) !== '') {
                            $left++;
                        }
                    }
                }
                $out .= $token;
                continue;
            }
            $changed = 0;
            $text = $restore($token);
            if (stripos($text, 'image-set(') !== false) {
                $renamed = preg_replace_callback('/(url\(\s*)(["\']?)([^"\')\s]+)\2(\s*\)\s*type\(\s*["\']image\/avif["\']\s*\))/i', static function ($m) use ($rows, &$changed) {
                    $twin = self::matchTwin(html_entity_decode($m[3], ENT_QUOTES), $rows);
                    if ($twin === '') {
                        return $m[0];
                    }
                    $changed++;
                    return $m[1] . $m[2] . $twin . $m[2] . $m[4];
                }, $text);
                $count += $changed;
                if ($changed === 0 && preg_match_all('/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i', $text, $arms)) {
                    foreach ($arms[2] as $arm) {
                        if (self::matchTwin(html_entity_decode($arm, ENT_QUOTES), $rows) !== '') {
                            $left++;
                            break;
                        }
                    }
                }
                $out .= ($changed > 0 && is_string($renamed)) ? $renamed : $token;
                continue;
            }
            $dup = preg_replace_callback('/url\(\s*(["\']?)(.*?)\1\s*\)/is', static function ($m) use ($rows, &$changed) {
                $twin = self::matchTwin(html_entity_decode($m[2], ENT_QUOTES), $rows);
                if ($twin === '') {
                    return $m[0];
                }
                $changed++;
                return 'image-set(url(' . $m[1] . $twin . $m[1] . ') type("image/avif"),' . $m[0] . ')';
            }, $text);
            if ($changed === 0 || !is_string($dup)) {
                $out .= $token;
                continue;
            }
            $count += $changed;
            $out .= $token . ';' . $dup;
        }
        return $restore($out);
    }
}
