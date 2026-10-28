<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/v2-report-stamp.php';
require_once __DIR__ . '/../cdn/delivery-resolver.php';

if (!defined('WPC_SIZED_SPOOL_LIMIT')) {
    define('WPC_SIZED_SPOOL_LIMIT', 200);
}
if (!defined('WPC_SIZED_SPOOL_TRIES')) {
    define('WPC_SIZED_SPOOL_TRIES', 3);
}
if (!defined('WPC_SIZED_NOOP_HOLDS')) {
    define('WPC_SIZED_NOOP_HOLDS', 3);
}
if (!defined('WPC_SIZED_TOMB_OPTION')) {
    define('WPC_SIZED_TOMB_OPTION', 'wpc_szt_tomb');
}
if (!defined('WPC_SIZED_TOMB_DAYS')) {
    define('WPC_SIZED_TOMB_DAYS', 1);
}
if (!defined('WPC_SIZED_TOMB_CAP')) {
    define('WPC_SIZED_TOMB_CAP', 500);
}
if (!defined('WPC_SIZED_NOOP_OPTION')) {
    define('WPC_SIZED_NOOP_OPTION', 'wpc_szt_noop');
}
if (!defined('WPC_SIZED_REPORT_PROOF_OPTION')) {
    define('WPC_SIZED_REPORT_PROOF_OPTION', 'wpc_szr_proof');
}
if (!defined('WPC_SIZED_REPORT_PROOF_DAYS')) {
    define('WPC_SIZED_REPORT_PROOF_DAYS', 14);
}

if (!function_exists('wpc_sized_trigger_spool_live')) {
    /** The entries of a stored spool that are still worth sending: arrays queued within the last hour. */
    function wpc_sized_trigger_spool_live($spool, $now = 0)
    {
        $now = (int) $now > 0 ? (int) $now : time();
        $live = [];
        foreach (is_array($spool) ? $spool : [] as $k => $e) {
            if (is_array($e) && ($now - (int) (isset($e['t']) ? $e['t'] : 0)) <= HOUR_IN_SECONDS) {
                $live[$k] = $e;
            }
        }
        return $live;
    }
}

if (!function_exists('wpc_sized_trigger_spool_commit')) {
    /** Writes a drain's result back: only the entries it settled leave and only the ones it changed are replaced, so an item spooled meanwhile stays. Answers the spool as stored. */
    function wpc_sized_trigger_spool_commit(array $before, array $after)
    {
        $current = get_option('wpc_szt_spool79', []);
        $current = is_array($current) ? $current : [];
        foreach ($before as $k => $entry) {
            if (!array_key_exists($k, $after)) {
                unset($current[$k]);
            } elseif ($after[$k] !== $entry && isset($current[$k])) {
                $current[$k] = $after[$k];
            }
        }
        update_option('wpc_szt_spool79', $current, false);
        return $current;
    }
}

if (!function_exists('wpc_sized_trigger_entry_held')) {
    /** True for a spool entry the service answered with a no-op and that waits for its `next` time. */
    function wpc_sized_trigger_entry_held($entry, $now = 0)
    {
        $now = (int) $now > 0 ? (int) $now : time();
        return is_array($entry) && isset($entry['next']) && (int) $entry['next'] > $now;
    }
}

if (!function_exists('wpc_sized_trigger_spool_room')) {
    /** How many more items the spool takes: a held entry gives way to a new item, so it holds no room. */
    function wpc_sized_trigger_spool_room()
    {
        $now = time();
        $live = wpc_sized_trigger_spool_live(get_option('wpc_szt_spool79', []), $now);
        $held = 0;
        foreach ($live as $e) {
            if (wpc_sized_trigger_entry_held($e, $now)) {
                $held++;
            }
        }
        return max(0, WPC_SIZED_SPOOL_LIMIT - (count($live) - $held));
    }
}

if (!function_exists('wpc_sized_trigger_tombstone_version')) {
    /** The policy version a tombstone is judged against; null when the policy reader is not loaded. */
    function wpc_sized_trigger_tombstone_version()
    {
        return function_exists('wpc_policy_version') ? (int) wpc_policy_version() : null;
    }
}

if (!function_exists('wpc_sized_trigger_tombstones_raw')) {
    /** The unexpired stored tombstones: md5 of an origin_url => ['u' => lapse time, 'v' => policy version seen or null, 'r' => reason]. */
    function wpc_sized_trigger_tombstones_raw($now = 0)
    {
        $now = (int) $now > 0 ? (int) $now : time();
        $held = get_option(WPC_SIZED_TOMB_OPTION, []);
        $live = [];
        foreach (is_array($held) ? $held : [] as $k => $e) {
            $until = is_array($e) ? (int) (isset($e['u']) ? $e['u'] : 0) : (int) $e;
            if ($until > $now) {
                $live[(string) $k] = [
                    'u' => $until,
                    'v' => is_array($e) && isset($e['v']) ? (int) $e['v'] : null,
                    'r' => is_array($e) && isset($e['r']) && is_string($e['r']) ? $e['r'] : '',
                ];
            }
        }
        return $live;
    }
}

if (!function_exists('wpc_sized_trigger_tombstones')) {
    /** The tombstones in force: md5 of an origin_url => the time it lapses. One stored under another policy version than the current is void. */
    function wpc_sized_trigger_tombstones($now = 0)
    {
        $current = wpc_sized_trigger_tombstone_version();
        $live = [];
        foreach (wpc_sized_trigger_tombstones_raw($now) as $k => $e) {
            if ($e['v'] === null || $current === null || $e['v'] === $current) {
                $live[$k] = $e['u'];
            }
        }
        return $live;
    }
}

if (!function_exists('wpc_sized_trigger_tombstoned')) {
    /** True when the service answered a no-op for this origin_url often enough that it was dropped, within the last day and under the current policy version. */
    function wpc_sized_trigger_tombstoned($originUrl, $tombs = null)
    {
        $tombs = is_array($tombs) ? $tombs : wpc_sized_trigger_tombstones();
        return isset($tombs[md5((string) $originUrl)]);
    }
}

if (!function_exists('wpc_sized_trigger_tombstone_add')) {
    /** Tombstones origin_urls for a day with the reason and the policy version seen, keeping the newest WPC_SIZED_TOMB_CAP. Each entry is a URL or ['url' => , 'reason' => ]. */
    function wpc_sized_trigger_tombstone_add($originUrls)
    {
        $now = time();
        $version = wpc_sized_trigger_tombstone_version();
        $tombs = wpc_sized_trigger_tombstones_raw($now);
        foreach ((array) $originUrls as $item) {
            $url = is_array($item) ? (string) (isset($item['url']) ? $item['url'] : '') : (string) $item;
            if ($url !== '') {
                $reason = is_array($item) && isset($item['reason']) && is_string($item['reason']) ? substr($item['reason'], 0, 64) : '';
                $tombs[md5($url)] = ['u' => $now + WPC_SIZED_TOMB_DAYS * DAY_IN_SECONDS, 'v' => $version, 'r' => $reason];
            }
        }
        if (count($tombs) > WPC_SIZED_TOMB_CAP) {
            uasort($tombs, function ($a, $b) { return $a['u'] <=> $b['u']; });
            $tombs = array_slice($tombs, -WPC_SIZED_TOMB_CAP, null, true);
        }
        update_option(WPC_SIZED_TOMB_OPTION, $tombs, false);
    }
}

if (!function_exists('wpc_sized_trigger_tombstones_clear')) {
    /** Lifts the tombstones of the given origin_urls. Returns how many were lifted. */
    function wpc_sized_trigger_tombstones_clear($originUrls)
    {
        $held = get_option(WPC_SIZED_TOMB_OPTION, []);
        if (!is_array($held) || empty($held)) {
            return 0;
        }
        $lifted = 0;
        foreach ((array) $originUrls as $url) {
            $k = md5((string) $url);
            if ((string) $url !== '' && isset($held[$k])) {
                unset($held[$k]);
                $lifted++;
            }
        }
        if ($lifted > 0) {
            update_option(WPC_SIZED_TOMB_OPTION, $held, false);
        }
        return $lifted;
    }
}

if (!function_exists('wpc_sized_trigger_tombstones_clear_attachment')) {
    /** Lifts the tombstones on every file URL of an attachment whose metadata was regenerated or edited: the full file, the original and each size of $meta. */
    function wpc_sized_trigger_tombstones_clear_attachment($attachmentId, $meta = null)
    {
        $held = get_option(WPC_SIZED_TOMB_OPTION, []);
        if (!is_array($held) || empty($held) || !function_exists('wp_get_upload_dir')) {
            return 0;
        }
        $meta = is_array($meta) ? $meta : (function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $attachmentId) : []);
        if (!is_array($meta) || empty($meta['file']) || !is_string($meta['file'])) {
            return 0;
        }
        $up = wp_get_upload_dir();
        if (empty($up['baseurl'])) {
            return 0;
        }
        $base = rtrim((string) $up['baseurl'], '/') . '/';
        $dir = strpos($meta['file'], '/') !== false ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
        $urls = [$base . $meta['file']];
        if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
            $urls[] = $base . $dir . $meta['original_image'];
        }
        foreach (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : [] as $size) {
            if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
                $urls[] = $base . $dir . $size['file'];
            }
        }
        return wpc_sized_trigger_tombstones_clear($urls);
    }
}

if (!function_exists('wpc_sized_trigger_spool_add')) {
    function wpc_sized_trigger_spool_add($items, &$taken = null)
    {
        $now = time();
        $taken = [];
        $spool = wpc_sized_trigger_spool_live(get_option('wpc_szt_spool79', []), $now);
        $tombs = wpc_sized_trigger_tombstones($now);
        foreach ((array) $items as $it) {
            if (!is_array($it) || empty($it['origin_url'])) {
                continue;
            }
            $k = md5((string) $it['origin_url']);
            $webp = isset($it['format']) && $it['format'] === 'webp';
            $slot = $webp ? md5((string) $it['origin_url'] . '|webp') : $k;
            if (isset($spool[$slot])) {
                $taken[] = (string) $it['origin_url'];
                continue;
            }
            if (isset($tombs[$k])) {
                continue;
            }
            if (count($spool) >= WPC_SIZED_SPOOL_LIMIT) {
                $evict = null;
                foreach ($spool as $held_key => $held_entry) {
                    if (wpc_sized_trigger_entry_held($held_entry, $now)
                        && ($evict === null || (int) (isset($held_entry['t']) ? $held_entry['t'] : 0) < (int) (isset($spool[$evict]['t']) ? $spool[$evict]['t'] : 0))) {
                        $evict = $held_key;
                    }
                }
                if ($evict === null) {
                    break;
                }
                unset($spool[$evict]);
            }
            $spool[$slot] = [
                'origin_url' => (string) $it['origin_url'],
                'sizeLabel'  => isset($it['sizeLabel']) ? (string) $it['sizeLabel'] : '',
                'slot_w'     => isset($it['slot_w']) ? (int) $it['slot_w'] : 0,
                'display_w'  => isset($it['display_w']) ? (int) $it['display_w'] : 0,
                't'          => $now,
            ] + (!empty($it['att']) ? ['att' => (int) $it['att']] : []) + ($webp ? ['format' => 'webp'] : []);
            $taken[] = (string) $it['origin_url'];
        }
        update_option('wpc_szt_spool79', $spool, false);
        return count($spool);
    }
}

if (!function_exists('wpc_sized_trigger_outcomes')) {
    /**
     * What the service's /v2/sized-trigger answer says of each item sent: [index => ['done'] |
     * ['noop', reason] | ['fail']], read from results[] and never from the top-level ok (the door
     * answers 200 ok:true whatever happened to the items). An entry that carries origin_url is matched
     * to its item by it, otherwise by position; an entry whose sizeLabel disagrees with the item at its
     * position, a count that differs, or an item with no entry is 'mismatch'. A body with no results[]
     * is 'no-results'. accepted:true (dedup_source included) is done; accepted:false with no_op:true,
     * or the shape a no-op had before no_op existed (status 200, error http_200, reason 'unknown'), is
     * a no-op; any other accepted:false is a failure.
     */
    function wpc_sized_trigger_outcomes($body, array $chunk)
    {
        $json = json_decode((string) $body, true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
            return 'no-results';
        }
        $results = array_values($json['results']);
        $classify = function ($r) {
            if (!empty($r['accepted'])) {
                return ['done'];
            }
            if (!empty($r['no_op'])) {
                $reason = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower(is_scalar($r['reason'] ?? null) ? (string) $r['reason'] : '')), 0, 32);
                return ['noop', $reason === '' ? 'unknown' : $reason];
            }
            if ((int) ($r['status'] ?? 0) === 200 && ($r['error'] ?? '') === 'http_200') {
                return ['noop', 'unknown'];
            }
            return ['fail'];
        };
        $byUrl = !empty($results);
        foreach ($results as $r) {
            if (!is_array($r) || !isset($r['origin_url']) || !is_string($r['origin_url'])) {
                $byUrl = false;
                break;
            }
        }
        $out = [];
        if ($byUrl) {
            $map = [];
            foreach ($results as $r) {
                $map[$r['origin_url']] = $r;
            }
            foreach (array_values($chunk) as $i => $item) {
                if (!isset($map[$item['origin_url']])) {
                    return 'mismatch';
                }
                $out[$i] = $classify($map[$item['origin_url']]);
            }
            return $out;
        }
        if (count($results) !== count($chunk)) {
            return 'mismatch';
        }
        foreach (array_values($chunk) as $i => $item) {
            $r = $results[$i];
            if (!is_array($r) || (isset($r['sizeLabel']) && (string) $r['sizeLabel'] !== (string) $item['sizeLabel'])) {
                return 'mismatch';
            }
            $out[$i] = $classify($r);
        }
        return $out;
    }
}

if (!function_exists('wpc_sized_trigger_noop_hold')) {
    /**
     * One no-op answer for a spool entry: its own counter nn goes up (never n, which counts failures),
     * and it waits 10 minutes after the first, 30 after the second; the WPC_SIZED_NOOP_HOLDS-th removes
     * it. Returns 'held' or 'dropped'; the caller tombstones what was dropped.
     */
    function wpc_sized_trigger_noop_hold(array &$spool, $key, $now)
    {
        $nn = (isset($spool[$key]['nn']) ? (int) $spool[$key]['nn'] : 0) + 1;
        if ($nn >= WPC_SIZED_NOOP_HOLDS) {
            unset($spool[$key]);
            return 'dropped';
        }
        $wait = [600, 1800];
        $spool[$key]['nn'] = $nn;
        $spool[$key]['t'] = $now;
        $spool[$key]['next'] = $now + $wait[$nn - 1];
        return 'held';
    }
}

if (!function_exists('wpc_sized_trigger_noop_count')) {
    /** Adds [reason => n] to the running count the health check reports: {at, reasons: {reason: count}}. */
    function wpc_sized_trigger_noop_count(array $reasons)
    {
        if (empty($reasons)) {
            return;
        }
        $held = get_option(WPC_SIZED_NOOP_OPTION, []);
        $counts = is_array($held) && isset($held['reasons']) && is_array($held['reasons']) ? $held['reasons'] : [];
        foreach ($reasons as $reason => $n) {
            $counts[(string) $reason] = (isset($counts[(string) $reason]) ? (int) $counts[(string) $reason] : 0) + (int) $n;
        }
        update_option(WPC_SIZED_NOOP_OPTION, ['at' => time(), 'reasons' => array_slice($counts, 0, 20, true)], false);
    }
}

if (!function_exists('wpc_sized_trigger_next_delay')) {
    /** Seconds until a spool next has something to send: 60 when an entry is ready now, else the wait of the earliest held one. */
    function wpc_sized_trigger_next_delay(array $spool, $now = 0)
    {
        $now = (int) $now > 0 ? (int) $now : time();
        $earliest = 0;
        foreach ($spool as $e) {
            if (!wpc_sized_trigger_entry_held($e, $now)) {
                return 60;
            }
            $earliest = $earliest === 0 ? (int) $e['next'] : min($earliest, (int) $e['next']);
        }
        return max(60, min(3600, $earliest - $now));
    }
}

if (!function_exists('wpc_encode_on_view_decide')) {
    /**
     * Whether a site is in the encode-on-view cohort, from the four facts that decide it: the policy's
     * cohorts, a Lite plan, a multisite network and the zone name. The one answer for the plugin
     * (wpc_encode_on_view_on) and for the report door (api/v2/sized_report.php), which boots no plugin
     * and so hands it the stored values.
     */
    function wpc_encode_on_view_decide($cohorts, $lite, $multisite, $zone)
    {
        return is_array($cohorts) && isset($cohorts['encode_on_view']) && (int) $cohorts['encode_on_view'] === 1
            && !$lite && !$multisite && trim((string) $zone) !== '';
    }
}

if (!function_exists('wpc_encode_on_view_format')) {
    /**
     * The one format an encode-on-view site's files are made in: the lower of the person's next-gen
     * ceiling (WPC_Delivery_Resolver::effective_ceiling over the stored settings) and the service's
     * policy.formats ('avif' when it names none), as 'avif', 'webp' or 'off'. The trigger, the report
     * door (which boots no plugin and is handed the stored values) and the page script's gate all ask
     * this; a policy that asks for more than the person allows does not widen it.
     */
    function wpc_encode_on_view_format($settings, $policy_formats)
    {
        $rank = ['off' => 0, 'webp' => 1, 'avif' => 2];
        $ceiling = (string) WPC_Delivery_Resolver::effective_ceiling($settings);
        $formats = (string) $policy_formats;
        $ceiling = isset($rank[$ceiling]) ? $ceiling : 'avif';
        $formats = isset($rank[$formats]) ? $formats : 'avif';
        return $rank[$formats] < $rank[$ceiling] ? $formats : $ceiling;
    }
}

if (!function_exists('wpc_encode_on_view_format_now')) {
    /** wpc_encode_on_view_format() over this site's stored settings and the policy it holds. */
    function wpc_encode_on_view_format_now()
    {
        return wpc_encode_on_view_format(get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings', []), function_exists('wpc_policy_formats') ? wpc_policy_formats() : 'avif');
    }
}

if (!function_exists('wpc_encode_on_view_format_for')) {
    /**
     * The format one browser's view is encoded in: the site's format ($ceiling, from wpc_encode_on_view_format) when the
     * browser did not say or says avif, 'webp' when it says webp, and 'off' when the site's format is off. Anything else
     * the browser sends is no answer.
     */
    function wpc_encode_on_view_format_for($ceiling, $browser)
    {
        if ($ceiling !== 'avif' && $ceiling !== 'webp') {
            return 'off';
        }
        return $browser === 'webp' ? 'webp' : $ceiling;
    }
}

if (!function_exists('wpc_sized_encode_done')) {
    /**
     * True when a file has nothing to encode: its format is 'off', or the twin of that format ('.avif' or
     * '.webp') is already beside it. $path_no_ext is the file's path without its extension.
     */
    function wpc_sized_encode_done($path_no_ext, $format)
    {
        if ($format === 'off') {
            return true;
        }
        return @file_exists((string) $path_no_ext . ($format === 'webp' ? '.webp' : '.avif'));
    }
}

if (!function_exists('wpc_sized_report_query_selects_content')) {
    /**
     * True when a request's query string picks which content the path shows, so the path alone does not
     * name the page: any key WordPress itself reads as a query variable ($wp->public_query_vars: p,
     * page_id, s, cat, paged, a registered taxonomy or post type, a plugin's own). Tracking and cache-bust
     * keys (utm_*, fbclid, ver) do not. Without the list every key counts.
     */
    function wpc_sized_report_query_selects_content($keys, $public_vars)
    {
        $keys = array_map('strval', array_keys(array_flip((array) $keys)));
        if (!$keys) {
            return false;
        }
        return !is_array($public_vars) || count(array_intersect($keys, array_map('strval', $public_vars))) > 0;
    }
}

if (!function_exists('wpc_sized_report_page_ok')) {
    /** True for a page path a report may name: rooted, at most 200 characters, in the characters a WordPress path is made of, no dot segment. */
    function wpc_sized_report_page_ok($page)
    {
        return is_string($page) && $page !== '' && strlen($page) <= 200 && preg_match('#^/[A-Za-z0-9/_.%~-]*$#', $page) === 1 && strpos($page, '..') === false;
    }
}

if (!function_exists('wpc_sized_report_stamp')) {
    /**
     * The stamp a page's report script carries for one file it shows: a keyed hash of the page's path
     * and the file's path under the site's stamp secret (wpc_report_stamp_door_key). It has no date: a
     * stored page never expires, and a stamp that did would strand the images only that page shows.
     * Verified by wpc_sized_report_stamp_valid().
     */
    function wpc_sized_report_stamp($page, $file)
    {
        $mac = wpc_report_stamp_mac('sized-report|' . (string) $page . '|' . (string) $file, true);
        return $mac === '' ? '' : substr($mac, 0, 12);
    }
}

if (!function_exists('wpc_sized_report_stamp_valid')) {
    /** True when $given is the stamp of this page and file under the site's stamp secret. */
    function wpc_sized_report_stamp_valid($page, $file, $given)
    {
        if (!is_string($given) || $given === '') {
            return false;
        }
        $want = wpc_sized_report_stamp($page, $file);
        return $want !== '' && hash_equals($want, $given);
    }
}

if (!function_exists('wpc_sized_report_proof_key')) {
    /** A short fingerprint of the stamp secret the proof was earned under; '' when there is none. */
    function wpc_sized_report_proof_key()
    {
        $key = wpc_report_stamp_door_key();
        return $key === '' ? '' : substr(hash('sha256', 'sized-proof|' . $key), 0, 12);
    }
}

if (!function_exists('wpc_sized_report_proof_note')) {
    /** The door records that a report for a real file, carrying a stamp, reached it: {at, k, d}, the time, the secret's fingerprint and the door ('ajax' or 'direct'); written at most once an hour per secret and door. */
    function wpc_sized_report_proof_note($door = 'direct')
    {
        $key = wpc_sized_report_proof_key();
        if ($key === '') {
            return;
        }
        $door = $door === 'ajax' ? 'ajax' : 'direct';
        $held = get_option(WPC_SIZED_REPORT_PROOF_OPTION, []);
        $now = time();
        if (is_array($held) && ($held['k'] ?? '') === $key && ($held['d'] ?? 'direct') === $door && $now - (int) ($held['at'] ?? 0) < HOUR_IN_SECONDS) {
            return;
        }
        update_option(WPC_SIZED_REPORT_PROOF_OPTION, ['at' => $now, 'k' => $key, 'd' => $door], false);
        if (function_exists('wpc_sized_report_arm_settle')) {
            wpc_sized_report_arm_settle();
        }
    }
}

if (!function_exists('wpc_sized_report_proven')) {
    /**
     * True when the door answered a stamped report within WPC_SIZED_REPORT_PROOF_DAYS under the site's
     * current stamp secret: the page-to-door path works on this host and the stamps the stored pages carry
     * still verify. A new secret voids it, because every stored page's stamp is then refused.
     */
    function wpc_sized_report_proven()
    {
        $held = get_option(WPC_SIZED_REPORT_PROOF_OPTION, []);
        $key = wpc_sized_report_proof_key();
        return is_array($held) && $key !== '' && ($held['k'] ?? '') === $key && time() - (int) ($held['at'] ?? 0) <= WPC_SIZED_REPORT_PROOF_DAYS * DAY_IN_SECONDS;
    }
}

if (!defined('WPC_SIZED_REPORT_HOURLY')) {
    define('WPC_SIZED_REPORT_HOURLY', 120);
}
if (!defined('WPC_SIZED_REPORT_PAGE_HOURLY')) {
    define('WPC_SIZED_REPORT_PAGE_HOURLY', 30);
}
if (!defined('WPC_SIZED_REPORT_FILES')) {
    define('WPC_SIZED_REPORT_FILES', 6);
}
if (!defined('WPC_SIZED_REPORT_PAGES')) {
    define('WPC_SIZED_REPORT_PAGES', 5);
}
if (!defined('WPC_SIZED_REPORT_ASKED')) {
    define('WPC_SIZED_REPORT_ASKED', 300);
}
if (!defined('WPC_SIZED_STAMP_SEEN_OPTION')) {
    define('WPC_SIZED_STAMP_SEEN_OPTION', 'wpc_szr_stamp_seen');
}
if (!defined('WPC_SIZED_STAMP_SEEN_ROWS')) {
    define('WPC_SIZED_STAMP_SEEN_ROWS', 5);
}
if (!defined('WPC_SIZED_SLO_OPTION')) {
    define('WPC_SIZED_SLO_OPTION', 'wpc_szr_slo');
}
if (!defined('WPC_SIZED_LEDGER_OPTION')) {
    define('WPC_SIZED_LEDGER_OPTION', 'wpc_szr_ledger');
}
if (!defined('WPC_SIZED_LEDGER_CAP')) {
    define('WPC_SIZED_LEDGER_CAP', 200);
}
if (!defined('WPC_SIZED_RETRY_S')) {
    define('WPC_SIZED_RETRY_S', 600);
}
if (!defined('WPC_SIZED_STUCK_S')) {
    define('WPC_SIZED_STUCK_S', 1800);
}
if (!defined('WPC_SIZED_RETRY_PER_TICK')) {
    define('WPC_SIZED_RETRY_PER_TICK', 20);
}
if (!defined('WPC_SIZED_FALLBACK_PER_TICK')) {
    define('WPC_SIZED_FALLBACK_PER_TICK', 5);
}

if (!function_exists('wpc_sized_report_end')) {
    /** Answers a report with its verdict as the body and header, never cached, and ends the request. */
    function wpc_sized_report_end($status, $verdict)
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-WPC-Sized: ' . $verdict);
        echo $verdict;
        exit;
    }
}

if (!function_exists('wpc_sized_report_enabled')) {
    function wpc_sized_report_enabled()
    {
        $policy = get_option('wpc_policy23', []);
        $options = get_option('wps_ic', []);
        $cohorts = is_array($policy) && isset($policy['cohorts']) ? $policy['cohorts'] : [];
        if ((!is_array($policy) || $policy === []) && function_exists('wpc_policy_builtin_cohorts')) {
            $cohorts = wpc_policy_builtin_cohorts();
        }
        return wpc_encode_on_view_decide(
            $cohorts,
            is_array($options) && isset($options['version']) && (string) $options['version'] === 'lite',
            function_exists('is_multisite') && is_multisite(),
            get_option('ic_cdn_zone_name', '')
        );
    }
}

if (!function_exists('wpc_sized_report_format')) {
    function wpc_sized_report_format()
    {
        $policy = get_option('wpc_policy23', []);
        return wpc_encode_on_view_format(get_option('wps_ic_settings', []), is_array($policy) && isset($policy['formats']) ? $policy['formats'] : 'avif');
    }
}

if (!function_exists('wpc_sized_report_same_origin')) {
    function wpc_sized_report_same_origin()
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || $origin === 'null') {
            return strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'same-origin';
        }
        $theirs = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $ours = [strtolower((string) parse_url((string) get_option('home', ''), PHP_URL_HOST)), strtolower((string) parse_url((string) get_option('siteurl', ''), PHP_URL_HOST))];
        return $theirs !== '' && in_array($theirs, $ours, true);
    }
}

if (!function_exists('wpc_sized_report_request')) {
    function wpc_sized_report_request()
    {
        $field = isset($_POST['d']) && is_string($_POST['d']) ? $_POST['d'] : '';
        if ($field === '') {
            $raw = (string) file_get_contents('php://input', false, null, 0, 4097);
            parse_str(strlen($raw) > 4096 ? '' : $raw, $parsed);
            $field = isset($parsed['d']) && is_string($parsed['d']) ? $parsed['d'] : '';
        }
        $json = $field === '' ? false : base64_decode($field, true);
        $body = is_string($json) && strlen($json) <= 2048 ? json_decode($json, true) : null;
        if (!is_array($body) || empty($body['f']) || !is_array($body['f'])) {
            return null;
        }
        $page = isset($body['p']) && wpc_sized_report_page_ok($body['p']) ? $body['p'] : '';
        $stamps = isset($body['s']) && is_array($body['s']) ? array_values($body['s']) : [];
        $files = [];
        foreach (array_slice(array_values($body['f']), 0, WPC_SIZED_REPORT_FILES) as $at => $path) {
            $files[] = ['path' => $path, 'stamp' => isset($stamps[$at]) ? $stamps[$at] : ''];
        }
        return ['files' => $files, 'page' => $page, 'browser' => isset($body['fm']) && in_array($body['fm'], ['avif', 'webp'], true) ? $body['fm'] : ''];
    }
}

if (!function_exists('wpc_sized_report_file')) {
    function wpc_sized_report_file($path, $uploads)
    {
        if (!is_string($path) || strlen($path) > 300 || strpos($path, '..') !== false || preg_match('~[\x00-\x1f?#\\\\]~', $path)) {
            return null;
        }
        $base = rtrim((string) parse_url($uploads['baseurl'], PHP_URL_PATH), '/') . '/';
        if (strpos($path, $base) !== 0 || !preg_match('~\.(jpe?g|png)$~i', $path)) {
            return null;
        }
        $rel = rawurldecode(substr($path, strlen($base)));
        if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return null;
        }
        $abs = rtrim($uploads['basedir'], '/\\') . '/' . $rel;
        $root = realpath($uploads['basedir']);
        $real = realpath($abs);
        if ($root === false || $real === false || strpos($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }
        $size = @getimagesize($real);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return null;
        }
        $dir = dirname($rel) === '.' ? '' : dirname($rel) . '/';
        $name = basename($rel);
        if (preg_match('~^(.+)-(\d{2,4})x(\d{2,4})\.(jpe?g|png)$~i', $name, $m)) {
            if (abs((int) $m[2] - (int) $size[0]) > 1 || abs((int) $m[3] - (int) $size[1]) > 1) {
                return null;
            }
            $attached = [$rel, $dir . $m[1] . '.' . $m[4], $dir . preg_replace('~-scaled$~', '', $m[1]) . '-scaled.' . $m[4], $dir . preg_replace('~-scaled$~', '', $m[1]) . '.' . $m[4]];
        } elseif (preg_match('~^(.+)\.(jpe?g|png)$~i', $name, $m)) {
            $attached = [$rel];
        } else {
            return null;
        }
        return ['rel' => $rel, 'abs' => $real, 'width' => (int) $size[0], 'attached' => array_values(array_unique($attached)), 'stem' => $dir . preg_replace('~\.[^.]+$~', '', $name)];
    }
}

if (!function_exists('wpc_sized_report_attachment')) {
    function wpc_sized_report_attachment($candidates)
    {
        global $wpdb;
        foreach ($candidates as $candidate) {
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $candidate));
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }
}

if (!function_exists('wpc_sized_report_budget')) {
    function wpc_sized_report_budget()
    {
        $budget = get_option('wpc_szr_budget', []);
        $hour = (int) floor(time() / 3600);
        if (!is_array($budget) || !isset($budget['h'], $budget['n']) || (int) $budget['h'] !== $hour) {
            return ['h' => $hour, 'n' => 0, 'p' => []];
        }
        return ['h' => $hour, 'n' => (int) $budget['n'], 'p' => isset($budget['p']) && is_array($budget['p']) ? $budget['p'] : []];
    }
}

if (!function_exists('wpc_sized_report_budget_left')) {
    function wpc_sized_report_budget_left($page)
    {
        $budget = wpc_sized_report_budget();
        $used = isset($budget['p'][md5($page)]) ? (int) $budget['p'][md5($page)] : 0;
        return ['site' => WPC_SIZED_REPORT_HOURLY - $budget['n'], 'page' => WPC_SIZED_REPORT_PAGE_HOURLY - $used];
    }
}

if (!function_exists('wpc_sized_report_spend')) {
    function wpc_sized_report_spend($page)
    {
        $budget = wpc_sized_report_budget();
        $key = md5($page);
        $budget['p'][$key] = (isset($budget['p'][$key]) ? (int) $budget['p'][$key] : 0) + 1;
        $budget['n']++;
        update_option('wpc_szr_budget', $budget, false);
    }
}

if (!function_exists('wpc_sized_report_remember_page')) {
    function wpc_sized_report_remember_page($stem, $page)
    {
        $asked = get_option('wpc_szt_asked', []);
        $asked = is_array($asked) ? $asked : [];
        $now = time();
        foreach ($asked as $key => $row) {
            if (!is_array($row) || ($now - (int) ($row['t'] ?? 0)) > DAY_IN_SECONDS) {
                unset($asked[$key]);
            }
        }
        if (!isset($asked[$stem]) && count($asked) >= WPC_SIZED_REPORT_ASKED) {
            return;
        }
        $pages = isset($asked[$stem]['pages']) && is_array($asked[$stem]['pages']) ? $asked[$stem]['pages'] : [];
        $listed = in_array($page, $pages, true);
        if (isset($asked[$stem]) && ($listed || count($pages) >= WPC_SIZED_REPORT_PAGES) && ($now - (int) ($asked[$stem]['t'] ?? 0)) < HOUR_IN_SECONDS) {
            return;
        }
        if (!$listed && count($pages) < WPC_SIZED_REPORT_PAGES) {
            $pages[] = $page;
        }
        $asked[$stem] = ['pages' => $pages, 't' => $now];
        update_option('wpc_szt_asked', $asked, false);
    }
}

if (!function_exists('wpc_sized_report_stamp_seen_note')) {
    /** Keeps up to WPC_SIZED_STAMP_SEEN_ROWS stamps a report carried that verified under the current stamp secret, per secret fingerprint, for the last three secrets: {fingerprint: {md5(page|file): stamp}}. A stamp is public in the page that carries it, so what is kept is what a rotated secret can be told apart by, never the secret. */
    function wpc_sized_report_stamp_seen_note($page, $file, $stamp)
    {
        $key = wpc_sized_report_proof_key();
        if ($key === '' || !is_string($page) || !is_string($file) || !is_string($stamp) || $stamp === '') {
            return;
        }
        $seen = get_option(WPC_SIZED_STAMP_SEEN_OPTION, []);
        $seen = is_array($seen) ? $seen : [];
        $rows = isset($seen[$key]) && is_array($seen[$key]) ? $seen[$key] : [];
        $at = md5($page . '|' . $file);
        if (isset($rows[$at]) || count($rows) >= WPC_SIZED_STAMP_SEEN_ROWS) {
            return;
        }
        $rows[$at] = $stamp;
        unset($seen[$key]);
        update_option(WPC_SIZED_STAMP_SEEN_OPTION, array_slice([$key => $rows] + $seen, 0, 3, true), false);
    }
}

if (!function_exists('wpc_sized_report_stamp_rotated')) {
    /** True when this page and file carried, under a stamp secret the site no longer holds, exactly the stamp the report carries now: the one proof a refused stamp is a rotated secret and not a guess. False when the site holds no stamp secret or the pair was never recorded. */
    function wpc_sized_report_stamp_rotated($page, $file, $given)
    {
        $key = wpc_sized_report_proof_key();
        if ($key === '' || !is_string($page) || $page === '' || !is_string($file) || !is_string($given) || $given === '') {
            return false;
        }
        $seen = get_option(WPC_SIZED_STAMP_SEEN_OPTION, []);
        if (!is_array($seen)) {
            return false;
        }
        $at = md5($page . '|' . $file);
        foreach ($seen as $old => $rows) {
            if ((string) $old !== $key && is_array($rows) && isset($rows[$at]) && is_string($rows[$at]) && hash_equals($rows[$at], $given)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_sized_report_stamp_refused_note')) {
    /** Records that the door refused a stamp that verified under a stamp secret the site no longer holds (wpc_sized_report_stamp_rotated), at most once an hour. A refusal with no such proof writes nothing. */
    function wpc_sized_report_stamp_refused_note()
    {
        $now = time();
        if ($now - (int) get_option('wpc_szr_stamp_refused_at', 0) < HOUR_IN_SECONDS) {
            return;
        }
        update_option('wpc_szr_stamp_refused_at', $now, false);
    }
}

if (!function_exists('wpc_sized_report_process')) {
    /**
     * One visitor's report, checked and spooled, answered as [status, verdict]: reads $_POST and $_SERVER, writes the spool, the
     * duplicate guards, the asked pages, the budget, the proof and the ledger; sends nothing out and ends nothing.
     */
    function wpc_sized_report_process($door)
    {
        $format = wpc_sized_report_format();
        if (!wpc_sized_report_enabled() || $format === 'off') {
            return [200, 'refused:off'];
        }
        if (!wpc_sized_report_same_origin()) {
            return [200, 'refused:origin'];
        }
        $report = wpc_sized_report_request();
        if ($report === null) {
            return [200, 'refused:shape'];
        }
        $uploads = wp_upload_dir(null, false);
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return [200, 'refused:uploads'];
        }

        $format = wpc_encode_on_view_format_for($format, $report['browser']);
        $queued = 0;
        $requested = [];
        $verdict = 'refused:stamp';
        foreach ($report['files'] as $row) {
            $path = $row['path'];
            if ($report['page'] === '' || !is_string($path) || !wpc_sized_report_stamp_valid($report['page'], $path, $row['stamp'])) {
                if (wpc_sized_report_stamp_rotated($report['page'], $path, $row['stamp'])) {
                    wpc_sized_report_stamp_refused_note();
                }
                continue;
            }
            $verdict = 'refused:file';
            $file = wpc_sized_report_file($path, $uploads);
            if ($file === null) {
                continue;
            }
            wpc_sized_report_proof_note($door);
            wpc_sized_report_stamp_seen_note($report['page'], $path, $row['stamp']);
            if (wpc_sized_encode_done(preg_replace('~\.[^.]+$~', '', $file['abs']), $format)) {
                wpc_sized_report_remember_page($file['stem'], $report['page']);
                $verdict = 'refused:exists';
                continue;
            }
            $att = wpc_sized_report_attachment($file['attached']);
            if ($att <= 0) {
                $verdict = 'refused:attachment';
                continue;
            }
            $guard = 'wpc_szt_' . $att . '_' . $file['width'] . ($format === 'webp' ? '_webp' : '');
            if (get_transient($guard)) {
                $verdict = 'refused:duplicate';
                continue;
            }
            $left = wpc_sized_report_budget_left($report['page']);
            if ($left['site'] <= 0) {
                $verdict = 'refused:budget';
                break;
            }
            if ($left['page'] <= 0) {
                $verdict = 'refused:page-budget';
                break;
            }
            if (wpc_sized_trigger_spool_room() <= 0) {
                $verdict = 'refused:full';
                break;
            }
            $origin_url = rtrim($uploads['baseurl'], '/') . '/' . $file['rel'];
            if (wpc_sized_trigger_tombstoned($origin_url)) {
                $verdict = 'refused:no-op';
                continue;
            }
            set_transient($guard, 1, 15 * MINUTE_IN_SECONDS);
            $taken = [];
            wpc_sized_trigger_spool_add([[
                'origin_url' => $origin_url,
                'sizeLabel'  => $file['width'] . 'w',
                'slot_w'     => $file['width'],
                'display_w'  => $file['width'],
                'att'        => $att,
                'format'     => $format,
            ]], $taken);
            if (!in_array($origin_url, $taken, true)) {
                delete_transient($guard);
                $verdict = 'refused:full';
                break;
            }
            wpc_sized_report_remember_page($file['stem'], $report['page']);
            wpc_sized_report_spend($report['page']);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('sized-report', '', '', ['att' => $att, 'w' => $file['width']]);
            }
            $requested[] = ['p' => preg_replace('~\.[^.]+$~', '', $file['abs']), 'u' => $origin_url, 'f' => $format === 'webp' ? 'webp' : 'avif', 'a' => $att, 'w' => $file['width']];
            $queued++;
        }
        wpc_sized_ledger_add($requested);
        return [$queued > 0 ? 202 : 200, $queued > 0 ? 'queued:' . $queued : $verdict];
    }
}

if (!function_exists('wpc_sized_report_handle')) {
    /** The report door's one body, called by the admin-ajax entry and by api/v2/sized_report.php: answers [status, verdict] and counts the verdict for the SLO block. */
    function wpc_sized_report_handle($door)
    {
        $out = wpc_sized_report_process($door === 'ajax' ? 'ajax' : 'direct');
        $verdict = (string) $out[1];
        if (strpos($verdict, 'queued:') === 0) {
            $kind = 'queued';
        } elseif ($verdict === 'refused:exists') {
            $kind = 'exists';
        } elseif ($verdict === 'refused:budget' || $verdict === 'refused:page-budget') {
            $kind = 'budget';
        } elseif (in_array($verdict, ['refused:off', 'refused:origin', 'refused:shape', 'refused:uploads', 'refused:stamp'], true)) {
            $kind = '';
        } else {
            $kind = 'other';
        }
        if ($kind !== '') {
            wpc_sized_slo_note($kind);
        }
        return $out;
    }
}

if (!function_exists('wpc_sized_report_ajax')) {
    /** The report door on admin-ajax for logged-in and logged-out visitors: a POST of at most 4096 bytes, answered from the spool with no request out. */
    function wpc_sized_report_ajax()
    {
        while (ob_get_level()) {
            @ob_end_clean();
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wpc_sized_report_end(405, 'refused:method');
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
            wpc_sized_report_end(413, 'refused:size');
        }
        $out = wpc_sized_report_handle('ajax');
        if ($out[0] === 202 && function_exists('wpc_sized_trigger_after_spool')) {
            wpc_sized_trigger_after_spool();
        }
        if ($out[0] === 202 && function_exists('wpc_v2_pull_assist')) {
            wpc_v2_pull_assist('report');
        }
        wpc_sized_report_end($out[0], $out[1]);
    }
}

if (!function_exists('wpc_sized_slo_buffer')) {
    /** The counts and landing times this request adds to the SLO option, written once when it ends. */
    function &wpc_sized_slo_buffer()
    {
        static $buffer = ['c' => [], 'v' => [], 'hooked' => false];
        return $buffer;
    }
}

if (!function_exists('wpc_sized_slo_keys')) {
    /** The counters of the SLO option: the report verdicts, then items requested and items landed. */
    function wpc_sized_slo_keys()
    {
        return ['queued', 'exists', 'budget', 'other', 'req', 'land'];
    }
}

if (!function_exists('wpc_sized_slo_hook')) {
    function wpc_sized_slo_hook(&$buffer)
    {
        if (!$buffer['hooked']) {
            $buffer['hooked'] = true;
            register_shutdown_function('wpc_sized_slo_flush');
        }
    }
}

if (!function_exists('wpc_sized_slo_note')) {
    /** Adds $n to one counter; the option is written once, when the request ends. */
    function wpc_sized_slo_note($key, $n = 1)
    {
        if (!in_array($key, wpc_sized_slo_keys(), true)) {
            return;
        }
        $buffer =& wpc_sized_slo_buffer();
        $buffer['c'][$key] = (isset($buffer['c'][$key]) ? $buffer['c'][$key] : 0) + (int) $n;
        wpc_sized_slo_hook($buffer);
    }
}

if (!function_exists('wpc_sized_slo_latency')) {
    /** Records one landing's time from the ask to the file, in seconds. */
    function wpc_sized_slo_latency($seconds)
    {
        $buffer =& wpc_sized_slo_buffer();
        $buffer['v'][] = max(0, (int) $seconds);
        wpc_sized_slo_hook($buffer);
    }
}

if (!function_exists('wpc_sized_slo_flush')) {
    /** Folds this request's counts into the one SLO option (hourly counts for 24 hours, the last 50 landing times): one write, none when there is nothing to add. */
    function wpc_sized_slo_flush()
    {
        $buffer =& wpc_sized_slo_buffer();
        if (!$buffer['c'] && !$buffer['v']) {
            return false;
        }
        $held = get_option(WPC_SIZED_SLO_OPTION, []);
        $held = is_array($held) ? $held : [];
        $hour = (int) floor(time() / 3600);
        $hours = isset($held['h']) && is_array($held['h']) ? $held['h'] : [];
        foreach ($hours as $at => $row) {
            if (!is_array($row) || (int) $at <= $hour - 24) {
                unset($hours[$at]);
            }
        }
        $row = isset($hours[$hour]) && is_array($hours[$hour]) ? $hours[$hour] : [];
        foreach ($buffer['c'] as $key => $n) {
            $row[$key] = (isset($row[$key]) ? (int) $row[$key] : 0) + (int) $n;
        }
        $hours[$hour] = $row;
        $landings = isset($held['v']) && is_array($held['v']) ? array_values($held['v']) : [];
        foreach ($buffer['v'] as $seconds) {
            $landings[] = (int) $seconds;
        }
        $buffer['c'] = [];
        $buffer['v'] = [];
        update_option(WPC_SIZED_SLO_OPTION, ['h' => $hours, 'v' => array_map('intval', array_slice($landings, -50))], false);
        return true;
    }
}

if (!function_exists('wpc_sized_slo_percentile')) {
    /** The nearest-rank percentile ($p from 1 to 100) of a list of numbers; null for an empty list. */
    function wpc_sized_slo_percentile($values, $p)
    {
        $values = array_values(array_map('intval', (array) $values));
        if (!$values) {
            return null;
        }
        sort($values);
        return $values[max(0, (int) ceil(count($values) * $p / 100) - 1)];
    }
}

if (!function_exists('wpc_sized_ledger')) {
    /** The items the report door asked the service for and has not seen land: {md5(origin_url|format): {p: path without extension, u: origin url, f: format, a: attachment, w: width, t: asked at, r: asked again at, x: fell back at}}. */
    function wpc_sized_ledger()
    {
        $ledger = get_option(WPC_SIZED_LEDGER_OPTION, []);
        return is_array($ledger) ? $ledger : [];
    }
}

if (!function_exists('wpc_sized_ledger_add')) {
    /** Writes the rows the door just spooled ({p, u, f, a, w}) into the ledger: one write, at most WPC_SIZED_LEDGER_CAP rows (the oldest give way), none older than a day. */
    function wpc_sized_ledger_add($rows)
    {
        if (!$rows) {
            return;
        }
        $now = time();
        $ledger = wpc_sized_ledger();
        foreach ($ledger as $key => $row) {
            if (!is_array($row) || $now - (int) ($row['t'] ?? 0) > DAY_IN_SECONDS) {
                unset($ledger[$key]);
            }
        }
        foreach ($rows as $row) {
            $key = md5($row['u'] . '|' . $row['f']);
            if (isset($ledger[$key])) {
                continue;
            }
            while (count($ledger) >= WPC_SIZED_LEDGER_CAP) {
                reset($ledger);
                unset($ledger[key($ledger)]);
            }
            $ledger[$key] = $row + ['t' => $now];
            wpc_sized_slo_note('req');
        }
        update_option(WPC_SIZED_LEDGER_OPTION, $ledger, false);
    }
}

if (!function_exists('wpc_sized_ledger_landed')) {
    /**
     * The one place a landing is counted when it happens: a twin written at $dest that the report door asked for leaves the ledger,
     * counts as landed, and its time from the ask to the file is recorded. wpc_v2_landed_receipt() calls it for every file any lane
     * lands; the pull-cron tick still counts the ones that appeared by another route (a row leaves the ledger once, so no landing counts twice).
     * Answers whether a ledger row matched.
     */
    function wpc_sized_ledger_landed($dest)
    {
        $dest = (string) $dest;
        $format = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if ($format !== 'avif' && $format !== 'webp') {
            return false;
        }
        $ledger = wpc_sized_ledger();
        if (!$ledger) {
            return false;
        }
        $rel = function ($path) {
            $path = str_replace('\\', '/', (string) $path);
            $inside = function_exists('wpc_v2_uploads_relative') ? wpc_v2_uploads_relative($path) : '';
            return $inside !== '' ? $inside : $path;
        };
        $want = $rel($dest);
        foreach ($ledger as $key => $row) {
            if (!is_array($row) || empty($row['p']) || empty($row['t'])) {
                continue;
            }
            if ((isset($row['f']) && $row['f'] === 'webp' ? 'webp' : 'avif') !== $format) {
                continue;
            }
            if ($rel($row['p'] . '.' . $format) !== $want) {
                continue;
            }
            clearstatcache(true, $dest);
            $mtime = (int) @filemtime($dest);
            wpc_sized_slo_latency(($mtime > 0 ? $mtime : time()) - (int) $row['t']);
            wpc_sized_slo_note('land');
            unset($ledger[$key]);
            update_option(WPC_SIZED_LEDGER_OPTION, $ledger, false);
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_sized_ledger_tick')) {
    /**
     * Reads the ledger against the disk on the 5-minute pull cron, before the spool drains. A twin on disk is a landing (its time from
     * the ask to the file is counted). One not landed 10 minutes after the ask is asked for once more (back in the spool); one still
     * not landed 30 minutes after the ask and 10 minutes after its second ask falls back to wpc_v2_sized_trigger_queue's first-render dispatch for that size and is not
     * asked again. At most WPC_SIZED_RETRY_PER_TICK re-asks and WPC_SIZED_FALLBACK_PER_TICK fallbacks a tick; a row older than a day
     * is dropped. Answers [landed, asked again, fell back].
     */
    function wpc_sized_ledger_tick()
    {
        $ledger = wpc_sized_ledger();
        if (!$ledger) {
            return [0, 0, 0];
        }
        $now = time();
        $armed = function_exists('wpc_sized_report_armed') && wpc_sized_report_armed();
        $landed = 0;
        $again = [];
        $fallbacks = [];
        foreach ($ledger as $key => $row) {
            if (!is_array($row) || empty($row['p']) || empty($row['t'])) {
                unset($ledger[$key]);
                continue;
            }
            $format = isset($row['f']) && $row['f'] === 'webp' ? 'webp' : 'avif';
            if (wpc_sized_encode_done($row['p'], $format)) {
                $mtime = (int) @filemtime($row['p'] . '.' . $format);
                wpc_sized_slo_latency(($mtime > 0 ? $mtime : $now) - (int) $row['t']);
                wpc_sized_slo_note('land');
                unset($ledger[$key]);
                $landed++;
                continue;
            }
            $age = $now - (int) $row['t'];
            if ($age > DAY_IN_SECONDS) {
                unset($ledger[$key]);
                continue;
            }
            if (!$armed) {
                continue;
            }
            if (empty($row['r']) && $age >= WPC_SIZED_RETRY_S && count($again) < WPC_SIZED_RETRY_PER_TICK) {
                $again[$key] = $row;
            } elseif (!empty($row['r']) && empty($row['x']) && $age >= WPC_SIZED_STUCK_S && $now - (int) $row['r'] >= WPC_SIZED_RETRY_S && count($fallbacks) < WPC_SIZED_FALLBACK_PER_TICK) {
                $fallbacks[$key] = $row;
            }
        }
        if ($again) {
            $items = [];
            foreach ($again as $key => $row) {
                $ledger[$key]['r'] = $now;
                delete_transient('wpc_szt_' . (int) $row['a'] . '_' . (int) $row['w'] . ($row['f'] === 'webp' ? '_webp' : ''));
                $items[] = ['origin_url' => $row['u'], 'sizeLabel' => (int) $row['w'] . 'w', 'slot_w' => (int) $row['w'], 'display_w' => (int) $row['w'], 'att' => (int) $row['a'], 'format' => $row['f']];
            }
            $taken = [];
            wpc_sized_trigger_spool_add($items, $taken);
        }
        foreach ($fallbacks as $key => $row) {
            $ledger[$key]['x'] = $now;
            if (function_exists('wpc_v2_sized_trigger_queue')) {
                wpc_v2_sized_trigger_queue((int) $row['a'], (int) $row['w'], (int) $row['w'], true, $row['f'] === 'webp' ? 'webp' : 'avif');
            }
        }
        update_option(WPC_SIZED_LEDGER_OPTION, $ledger, false);
        return [$landed, count($again), count($fallbacks)];
    }
}

if (!function_exists('wpc_sized_slo_block')) {
    /**
     * The healthcheck's encode_on_view block, the one reader of the SLO counters and the ledger: armed (and why not), the door the last
     * proof came through and when, the age of the last pull tick, the live spool, the last 24 hours' report verdicts and requested and
     * landed items, the p50 and p95 of the last landing times, and the items asked 30 minutes ago and not landed.
     */
    function wpc_sized_slo_block()
    {
        $now = time();
        $held = get_option(WPC_SIZED_SLO_OPTION, []);
        $hours = is_array($held) && isset($held['h']) && is_array($held['h']) ? $held['h'] : [];
        $sum = array_fill_keys(wpc_sized_slo_keys(), 0);
        foreach ($hours as $at => $row) {
            if (is_array($row) && (int) $at > (int) floor($now / 3600) - 24) {
                foreach ($sum as $key => $n) {
                    $sum[$key] = $n + (isset($row[$key]) ? (int) $row[$key] : 0);
                }
            }
        }
        $landings = is_array($held) && isset($held['v']) && is_array($held['v']) ? $held['v'] : [];
        $proof = get_option(WPC_SIZED_REPORT_PROOF_OPTION, []);
        $stuck = 0;
        foreach (wpc_sized_ledger() as $row) {
            if (is_array($row) && $now - (int) ($row['t'] ?? 0) >= WPC_SIZED_STUCK_S) {
                $stuck++;
            }
        }
        $reason = function_exists('wpc_sized_report_state') ? wpc_sized_report_state() : 'cohort';
        $pull = function_exists('wpc_v2_pull_state') ? wpc_v2_pull_state() : [];
        $tick = isset($pull['cron']['t']) ? (int) $pull['cron']['t'] : 0;
        return [
            'armed'           => $reason === '',
            'dispatch_held'   => $reason === '' || $reason === 'awaiting_proof',
            'reason'          => $reason,
            'door'            => is_array($proof) && ($proof['d'] ?? '') === 'direct' ? 'direct' : 'ajax',
            'proof_at'        => is_array($proof) && !empty($proof['at']) ? (int) $proof['at'] : null,
            'stamp_refused_at' => (int) get_option('wpc_szr_stamp_refused_at', 0) > 0 ? (int) get_option('wpc_szr_stamp_refused_at', 0) : null,
            'pull_tick_age_s' => $tick > 0 ? max(0, $now - $tick) : null,
            'spool_live'      => count(wpc_sized_trigger_spool_live(get_option('wpc_szt_spool79', []), $now)),
            'reports_24h'     => ['queued' => $sum['queued'], 'exists' => $sum['exists'], 'budget' => $sum['budget'], 'other' => $sum['other']],
            'requested_24h'   => $sum['req'],
            'landed_24h'      => $sum['land'],
            'view_to_land_s'  => ['p50' => wpc_sized_slo_percentile($landings, 50), 'p95' => wpc_sized_slo_percentile($landings, 95), 'n' => count($landings)],
            'stuck'           => $stuck,
        ];
    }
}

if (!function_exists('wpc_sized_variants_stats')) {
    /** How many ideal widths the images carry (post meta wpc_ideal_widths): one query of at most 500 rows, kept for an hour. */
    function wpc_sized_variants_stats()
    {
        $held = get_transient('wpc_sd_variants');
        if (is_array($held)) {
            return $held;
        }
        global $wpdb;
        $rows   = (array) $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d", 'wpc_ideal_widths', 500));
        $counts = [];
        foreach ($rows as $raw) {
            $list = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : $raw;
            if (is_array($list) && count($list) > 0) {
                $counts[] = count($list);
            }
        }
        sort($counts);
        $out = [
            'images_with_ideal'   => count($counts),
            'ideal_per_image_max' => $counts ? (int) end($counts) : 0,
            'ideal_per_image_p50' => $counts ? (int) $counts[(int) floor((count($counts) - 1) / 2)] : 0,
            'capped'              => count($rows) >= 500 ? 1 : 0,
        ];
        set_transient('wpc_sd_variants', $out, HOUR_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('wpc_sized_health_echo')) {
    /** The landing health the policy sync carries: the SLO block's own figures, the loopback verdict and the variation counts. */
    function wpc_sized_health_echo()
    {
        $b = wpc_sized_slo_block();
        return [
            'sd_health' => [
                'armed'           => (bool) $b['armed'],
                'reason'          => (string) $b['reason'],
                'door'            => (string) $b['door'],
                'pull_tick_age_s' => $b['pull_tick_age_s'],
                'loopback'        => function_exists('wpc_v2_pull_loopback') ? wpc_v2_pull_loopback() : 'unknown',
                'requested_24h'   => (int) $b['requested_24h'],
                'landed_24h'      => (int) $b['landed_24h'],
                'view_to_land_s'  => $b['view_to_land_s'],
                'stuck'           => (int) $b['stuck'],
            ],
            'variants'  => wpc_sized_variants_stats() + ['triggers_24h' => (int) $b['reports_24h']['queued']],
        ];
    }
}

if (function_exists('add_action')) {
    add_action('wp_ajax_nopriv_wpc_sized_report', 'wpc_sized_report_ajax');
    add_action('wp_ajax_wpc_sized_report', 'wpc_sized_report_ajax');
    add_action('wpc_v2_pull_cron', 'wpc_sized_ledger_tick', 15);
}
