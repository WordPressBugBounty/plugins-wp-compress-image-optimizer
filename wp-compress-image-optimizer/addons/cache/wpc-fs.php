<?php

if (!function_exists('wpc_fs_put')) {
    /**
     * Drop-in for file_put_contents($path, $data, $flags) with the same open/lock/truncate
     * order PHP uses: LOCK_EX opens without truncating, takes the lock, THEN truncates, so a
     * failed lock never leaves a 0-byte file. Accepts a string, an array (joined) or a stream
     * resource. Returns the byte count, or false when nothing or only part could be written.
     */
    function wpc_fs_put($path, $data, $flags = 0)
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }
        $append = (bool) ((int) $flags & FILE_APPEND);
        $lock   = (bool) ((int) $flags & LOCK_EX);
        $mode   = $append ? 'ab' : ($lock ? 'cb' : 'wb');
        $fh = @fopen($path, $mode);
        if (!$fh) {
            return false;
        }
        if ($lock && !@flock($fh, LOCK_EX)) {
            @fclose($fh);
            return false;
        }
        if ($lock && !$append) {
            if (!@ftruncate($fh, 0)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
                return false;
            }
            @rewind($fh);
        }
        if (is_resource($data)) {
            $done = @stream_copy_to_stream($data, $fh);
            $len  = $done;
        } else {
            $data = is_array($data) ? implode('', $data) : (string) $data;
            $len  = strlen($data);
            $done = 0;
            while ($done < $len) {
                $n = @fwrite($fh, substr($data, $done));
                if ($n === false || $n === 0) {
                    break;
                }
                $done += $n;
            }
        }
        if ($lock) {
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
        return ($done !== false && $done === $len) ? $done : false;
    }
}

if (!function_exists('wpc_fs_update')) {
    /**
     * Locked read-modify-write: open without truncating, take LOCK_EX (LOCK_NB when
     * $nonblock), hand the current bytes to $cb, write back what it returns. $cb may return
     * null to leave the file untouched. Returns [ok(bool), result(mixed)] where result is
     * $cb's second return value; ok is false when the lock or the write could not be had.
     */
    function wpc_fs_update($path, $cb, $nonblock = false)
    {
        $path = (string) $path;
        if ($path === '' || !is_callable($cb)) {
            return [false, null];
        }
        $fh = @fopen($path, 'c+b');
        if (!$fh) {
            return [false, null];
        }
        if (!@flock($fh, $nonblock ? (LOCK_EX | LOCK_NB) : LOCK_EX)) {
            @fclose($fh);
            return [false, null];
        }
        $cur = (string) @stream_get_contents($fh);
        $out = $cb($cur);
        $new = is_array($out) ? (isset($out[0]) ? $out[0] : null) : $out;
        $res = is_array($out) && array_key_exists(1, $out) ? $out[1] : null;
        $ok  = true;
        if ($new !== null) {
            $new = (string) $new;
            if (!@ftruncate($fh, 0) || !@rewind($fh)) {
                $ok = false;
            } else {
                $len = strlen($new);
                $done = 0;
                while ($done < $len) {
                    $n = @fwrite($fh, substr($new, $done));
                    if ($n === false || $n === 0) {
                        break;
                    }
                    $done += $n;
                }
                $ok = ($done === $len);
            }
        }
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return [$ok, $res];
    }
}

if (!function_exists('wpc_fs_remove_link')) {
    /** Removes a symbolic link itself, whatever it points at (a directory link on Windows needs rmdir). */
    function wpc_fs_remove_link($path)
    {
        return @unlink($path) || @rmdir($path);
    }
}

if (!function_exists('wpc_fs_plugin_roots')) {
    /** The plugin's own root directories: the page cache, critical, combine, fonts and preload folders. */
    function wpc_fs_plugin_roots()
    {
        $roots = [defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/wp-preload' : ''];
        foreach (['WPS_IC_CACHE', 'WPS_IC_CRITICAL', 'WPS_IC_COMBINE', 'WPS_IC_FONTS_DIR'] as $constant) {
            $roots[] = defined($constant) ? (string) constant($constant) : '';
        }
        $out = [];
        foreach ($roots as $root) {
            if ($root !== '') {
                $out[] = rtrim($root, '/');
            }
        }
        return $out;
    }
}

if (!function_exists('wpc_fs_is_plugin_root')) {
    /**
     * Whether $dir is one of the plugin's own root directories (the page cache, critical, combine,
     * fonts and preload folders). A host may have put one of them elsewhere behind a symbolic link,
     * so a delete handed one of these follows it; it never follows any other link.
     */
    function wpc_fs_is_plugin_root($dir)
    {
        return in_array(rtrim((string) $dir, '/'), wpc_fs_plugin_roots(), true);
    }
}

if (!function_exists('wpc_fs_leaves_root')) {
    /**
     * Whether $dir, which sits under one of the plugin's root directories by name, resolves
     * outside it: a component between the root and $dir is a symbolic link to somewhere else.
     * A link to a place under the same root, and a root the host linked elsewhere, are not.
     */
    function wpc_fs_leaves_root($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        $under = '';
        foreach (wpc_fs_plugin_roots() as $root) {
            $root = str_replace('\\', '/', $root);
            if (strpos($dir, $root . '/') === 0 && strlen($root) > strlen($under)) {
                $under = $root;
            }
        }
        if ($under === '') {
            return false;
        }
        $real = @realpath($dir);
        $realRoot = @realpath($under);
        if ($real === false || $realRoot === false) {
            return false;
        }
        return strpos(str_replace('\\', '/', $real) . '/', rtrim(str_replace('\\', '/', $realRoot), '/') . '/') !== 0;
    }
}

if (!function_exists('wpc_fs_link_below')) {
    /** Whether a component of $path below $root (the root itself not counted) is a symbolic link. */
    function wpc_fs_link_below($root, $path)
    {
        $root = rtrim((string) $root, '/');
        $path = rtrim((string) $path, '/');
        if ($root === '' || strpos($path, $root . '/') !== 0) {
            return false;
        }
        $walk = $root;
        foreach (explode('/', substr($path, strlen($root) + 1)) as $part) {
            if ($part === '') {
                continue;
            }
            $walk .= '/' . $part;
            if (@is_link($walk)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_fs_remove_tree')) {
    /**
     * Deletes everything under $dir, then $dir itself once it is empty. Inside a directory that
     * is being deleted every entry goes, dotfiles included; directly in $dir, entries whose name
     * starts with a dot stay. A symbolic link inside the tree, to a file or to a directory, is
     * removed itself: nothing is read or deleted through it. $dir itself a link: with $nested (it
     * is an entry of a tree being deleted) the link is removed; handed as the root of a delete it
     * is followed only when it is one of the plugin's own root directories
     * (wpc_fs_is_plugin_root), otherwise it is left as it is, nothing is deleted, the answer is
     * false and `purge-link-refused` is journaled once per request. The same for a root that
     * sits under one of the plugin's root directories by name and resolves outside it, a
     * symbolic link between them (wpc_fs_leaves_root). A .purging-* directory is
     * never entered at any depth. As the root of the cache folder it keeps the flag files and the
     * marker directory (wpc_flag_entries). Stops between entries once
     * $GLOBALS['wpc_tombstone_drain_deadline'] has passed. True when $dir is gone.
     */
    function wpc_fs_remove_tree($dir, $nested = false)
    {
        static $refusal_logged = false;
        $dir = rtrim((string) $dir, '/');
        if ($dir === '') {
            return false;
        }
        if (@is_link($dir) && ($nested || !wpc_fs_is_plugin_root($dir))) {
            if ($nested) {
                return wpc_fs_remove_link($dir);
            }
            if (!$refusal_logged && function_exists('wpc_cache_first_log')) {
                $refusal_logged = true;
                wpc_cache_first_log('purge-link-refused', '', $dir, []);
            }
            return false;
        }
        if (!$nested && wpc_fs_leaves_root($dir)) {
            if (!$refusal_logged && function_exists('wpc_cache_first_log')) {
                $refusal_logged = true;
                wpc_cache_first_log('purge-link-refused', '', $dir, []);
            }
            return false;
        }
        $keep = (!$nested && defined('WPS_IC_CACHE') && $dir === rtrim((string) WPS_IC_CACHE, '/') && function_exists('wpc_flag_entries')) ? wpc_flag_entries() : [];
        foreach ((array) @scandir($dir) as $name) {
            $name = (string) $name;
            if ($name === '' || $name === '.' || $name === '..' || strpos($name, '.purging-') === 0 || (!$nested && $name[0] === '.') || in_array($name, $keep, true)) {
                continue;
            }
            if (!empty($GLOBALS['wpc_tombstone_drain_deadline']) && microtime(true) > $GLOBALS['wpc_tombstone_drain_deadline']) {
                return false;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? wpc_fs_remove_tree($path, true) : @unlink($path);
        }
        return @is_dir($dir) ? @rmdir($dir) : true;
    }
}

if (!function_exists('wpc_belt_rx_admit')) {
    /**
     * The one sampling gate for belt receipts. Of the sampled receipts in $events that a caller
     * is about to write for one page, answers which may be written now: those whose line is
     * missing from the page's file or was written 3600 s ago or more. It records the new time
     * for exactly those. Returns ['admit' => [events], 'sampled' => bool]; 'sampled' false means
     * no sample could be kept, every event is admitted, and the lines must not claim `sampled`.
     *
     * The sample lives in one small file per page, WPS_IC_CACHE/belt-rx/<md5 of the url key, or
     * of the path without the query when the key is empty>: one `<event> <unix time>` line per
     * receipt. Not a transient: one row per receipt and page an hour on a thousand-page site is
     * thousands of wp_options rows that WordPress clears only on its daily cron. One read per
     * call, and one locked write (wpc_fs_put) when something is admitted; the other lines are kept.
     * It fails open: a missing or unreadable file admits the receipt. A site-wide purge that
     * empties wp-cio takes the folder with it, which only restarts the hour. Without WPS_IC_CACHE
     * there is nowhere to keep it, so everything is admitted unsampled.
     */
    function wpc_belt_rx_admit(array $events, $key, $uri = '')
    {
        $events = array_values(array_unique(array_map('strval', $events)));
        if (!defined('WPS_IC_CACHE')) {
            return ['admit' => $events, 'sampled' => false];
        }
        if ($events === []) {
            return ['admit' => [], 'sampled' => true];
        }
        $page = (string) $key !== '' ? (string) $key : (string) strtok((string) $uri, '?');
        $dir = rtrim((string) WPS_IC_CACHE, '/') . '/belt-rx';
        $file = $dir . '/' . md5($page);
        $lastWritten = [];
        foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) === 2 && $parts[0] !== '' && ctype_digit($parts[1])) {
                $lastWritten[$parts[0]] = (int) $parts[1];
            }
        }
        $now = time();
        $admit = [];
        foreach ($events as $event) {
            if (isset($lastWritten[$event]) && ($now - $lastWritten[$event]) < 3600) {
                continue;
            }
            $lastWritten[$event] = $now;
            $admit[] = $event;
        }
        if ($admit !== []) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $body = '';
            foreach ($lastWritten as $event => $at) {
                $body .= $event . ' ' . $at . "\n";
            }
            wpc_fs_put($file, $body, LOCK_EX);
        }
        return ['admit' => $admit, 'sampled' => true];
    }
}

// ─── Stored page copies: the two families and the response headers ───────────────────────────
// This file is loaded by the advanced-cache drop-in as well as by the plugin, so everything the
// drop-in's reader shares with the plugin's writer lives here: one spelling of every copy name,
// one probe order, one header emitter.
//
// A page copy is stored in one of two families. The public family (`index.html…`) may be held
// by any cache in front of the origin. The local-only family (`localonly_index.html…`) is a
// complete render that must never be held at the edge — a crit-less page, stored so visitors get
// a file hit while its critical CSS is generated, but served `private` so no shared cache
// freezes the unoptimised snapshot. The family travels in the file name, written in the same
// atomic rename as the bytes, so the second request (served from the file) knows it without
// asking anything else. A local-only copy has no brotli twin and no static-mirror twin: both are
// served without PHP, and neither could carry the private header.

if (!defined('WPC_COPY_FAMILY_LOCALONLY')) {
    define('WPC_COPY_FAMILY_LOCALONLY', 'localonly_');
}
// Cache-Control values the page cache sends for a render it refuses to store and for a copy it
// stores for this origin only.
if (!defined('WPC_CC_NO_STORE')) {
    define('WPC_CC_NO_STORE', 'no-store, max-age=0');
}
if (!defined('WPC_CC_LOCAL_ONLY')) {
    define('WPC_CC_LOCAL_ONLY', 'private, max-age=0, must-revalidate');
}

if (!function_exists('wpc_image_source_exts')) {
    /**
     * The source extensions of an image stem, in the order the writer gives a stem's AVIF and WebP twin
     * to the first one on disk: png, then jpg, then jpeg, then gif, each in lower case and then upper.
     * Every reader that asks which source owns a next-gen name or which sources are rivals for it
     * (wpc_v2_source_beside, wpc_nextgen_rival_source, the fast-404 mu template) takes this order.
     */
    function wpc_image_source_exts()
    {
        return ['png', 'PNG', 'jpg', 'JPG', 'jpeg', 'JPEG', 'gif', 'GIF'];
    }
}

if (!function_exists('wpc_send_header')) {
    /** Every header the page cache decides goes out through this one call. */
    function wpc_send_header($line, $replace = true)
    {
        header((string) $line, (bool) $replace);
    }
}

if (!function_exists('wpc_localonly_copies_enabled')) {
    /**
     * Kill switch: define WPC_LOCALONLY_COPIES as false in wp-config.php and a crit-less render
     * is refused a copy again (no local-only file is written). Copies already on disk are still
     * read, so switching it off never serves a public header for a local-only file.
     */
    function wpc_localonly_copies_enabled()
    {
        return !defined('WPC_LOCALONLY_COPIES') || (bool) WPC_LOCALONLY_COPIES;
    }
}

if (!function_exists('wpc_copy_family')) {
    /** The family a store verdict writes: '' (public) or the local-only prefix. */
    function wpc_copy_family(array $verdict)
    {
        return (!empty($verdict['local']) && empty($verdict['edge'])) ? WPC_COPY_FAMILY_LOCALONLY : '';
    }

    /**
     * Every file name one stored copy consists of, as full paths. $device is the variant prefix
     * the writer and readers already use ('' or 'mobile_'), $family is '' or the local-only
     * prefix; the family always sits between the two, so 'mobile_localonly_index.html_gzip'.
     */
    function wpc_copy_names($dir, $device, $family)
    {
        $base = rtrim((string) $dir, '/') . '/' . (string) $device . (string) $family;
        return [
            'html'       => $base . 'index.html',
            'gzip'       => $base . 'index.html_gzip',
            'br'         => $base . 'index.html_br',
            'md5'        => $base . 'index.html_md5',
            'stale_html' => $base . 'stale.html',
            'stale_gzip' => $base . 'stale.html_gzip',
            'stale_br'   => $base . 'stale.html_br',
            'rewarm'     => $base . 'wpc-rewarm43.txt',
            'reason'     => $base . 'reason.txt',
            'cohort'     => $base . 'cohort.txt',
            'links'      => $base . 'links.txt',
            'head'       => $base . 'head.json',
        ];
    }

    /**
     * What a writer does after the rename of one copy: the reason a local-only copy is private is kept beside it (and a public copy has none),
     * its stale copy and rewarm counter go, and so does every file of the other family for the same device, so the page is exactly one family
     * and a reader never finds a copy that is both. Every writer of a page copy calls this once its rename has landed.
     */
    function wpc_copy_settle($dir, $device, $family, $reason = '', $cohort = '', $linkHeader = false)
    {
        $names = wpc_copy_names($dir, $device, $family);
        if ((string) $family !== '' && preg_match('/^[a-z0-9-]{1,40}$/', (string) $reason)) {
            wpc_fs_put($names['reason'], (string) $reason);
        } else {
            @unlink($names['reason']);
        }
        if ((string) $family === '' && preg_match('/^[0-9a-f]{12}$/', (string) $cohort)) {
            wpc_fs_put($names['cohort'], (string) $cohort . ($linkHeader === true ? '+lh' : ''));
        } else {
            @unlink($names['cohort']);
        }
        foreach (['rewarm', 'stale_br', 'stale_gzip', 'stale_html'] as $copyKey) {
            @unlink($names[$copyKey]);
        }
        foreach (wpc_copy_names($dir, $device, (string) $family === '' ? WPC_COPY_FAMILY_LOCALONLY : '') as $other) {
            @unlink($other);
        }
    }

    /** The file prefix a device's copies carry ('mobile' is 'mobile_', 'desktop' is ''); null for anything that is not a device. */
    function wpc_copy_device_prefix($device)
    {
        if ($device === 'mobile') {
            return 'mobile_';
        }
        return $device === 'desktop' ? '' : null;
    }

    /**
     * The record of what a stored copy links (the 'links' name of wpc_copy_names, written by
     * wpc_copy_links_record): ['complete' => whether the writer listed every file of the generated
     * stores the page links (the record opens with `v:2`; an older record lists none of them),
     * 'tokens' => the other lines], or null when there is no record to read. A last line the
     * writer did not finish is not read.
     */
    function wpc_copy_links_read($file)
    {
        $raw = @file_get_contents((string) $file, false, null, 0, 262144);
        $end = is_string($raw) ? strrpos($raw, "\n") : false;
        if ($end === false) {
            return null;
        }
        $record = ['complete' => false, 'tokens' => []];
        foreach (explode("\n", substr($raw, 0, $end)) as $line) {
            $line = trim($line);
            if ($line === 'v:2') {
                $record['complete'] = true;
            } elseif ($line !== '') {
                $record['tokens'][] = $line;
            }
        }
        return $record;
    }

    /**
     * The page-cache safety checks (policy.cache_p1) as the drop-in sees them: it runs before
     * WordPress and reads no option, so the plugin keeps a flag file in the cache root that says
     * the switch is on (wpc_cache_p1_sync writes and removes it, the policy ingest is the caller).
     */
    function wpc_cache_p1_flag()
    {
        return defined('WPS_IC_CACHE') && wpc_flag_fresh(rtrim(WPS_IC_CACHE, '/') . '/wpc-cache-p1.txt');
    }

    /** How long a blog's marker, and the flag file made from the markers, counts without being written again: three days, well past the daily sync. */
    function wpc_flag_bound()
    {
        return 3 * 86400;
    }

    /** Whether a flag file is there and was written within the bound: the one test the code that runs before WordPress applies to a flag. */
    function wpc_flag_fresh($file)
    {
        $written = @filemtime($file);
        return $written !== false && @is_file($file) && (time() - (int) $written) <= wpc_flag_bound();
    }

    /** The flag files that mean on only while every active blog says on (they relax a protection); the others mean on while any blog says on. */
    function wpc_flag_intersection_files()
    {
        return ['wpc-off-warm-sig.txt'];
    }

    /**
     * Makes a flag file in the cache root (the drop-in reads it, it reads no option) say what the
     * policies say. The cache root is one directory for a whole network and a policy is one blog's,
     * so each blog keeps a marker, wpc-flags/<file>/<blog id>, which every sync of that blog writes
     * again at most once an hour. A marker not written within the bound is dropped, and so is that of
     * a blog that no longer exists. A union flag (a switch that makes the site safer) is present while
     * any marker is; an intersection flag (wpc_flag_intersection_files) keeps a marker of 1 or 0 for
     * every blog that syncs it and is present while there is a 1 and no 0, and on a network, while
     * every active site has a marker. On a single site the one marker is the site's.
     */
    function wpc_flag_sync($file, $on)
    {
        if (!defined('WPS_IC_CACHE') || !@is_dir(rtrim(WPS_IC_CACHE, '/'))) {
            return false;
        }
        $now = time();
        $net = rtrim(WPS_IC_CACHE, '/') . '/' . $file;
        $dir = rtrim(WPS_IC_CACHE, '/') . '/wpc-flags/' . $file;
        $mine = $dir . '/' . (function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1);
        $intersection = in_array($file, wpc_flag_intersection_files(), true);
        $state = $on ? '1' : '0';
        if ($intersection || $on) {
            $written = @filemtime($mine);
            if ($written === false || ($now - (int) $written) > 3600 || ($intersection && (string) @file_get_contents($mine) !== $state)) {
                @mkdir($dir, 0777, true);
                if (wpc_fs_put($mine, $state) === false) {
                    return false;
                }
            }
        } elseif (@is_file($mine)) {
            @unlink($mine);
        }
        $multisite = function_exists('is_multisite') && is_multisite();
        $fresh = 0;
        $ones = 0;
        $zeros = 0;
        foreach (@is_dir($dir) ? (array) @scandir($dir) : [] as $marker) {
            if (!ctype_digit((string) $marker)) {
                continue;
            }
            $path = $dir . '/' . $marker;
            if (($now - (int) @filemtime($path)) > wpc_flag_bound() || ($multisite && function_exists('get_site') && get_site((int) $marker) === null)) {
                @unlink($path);
                continue;
            }
            $fresh++;
            if ($intersection && (string) @file_get_contents($path) === '0') {
                $zeros++;
            } else {
                $ones++;
            }
        }
        $present = $ones > 0;
        if ($intersection) {
            $active = $multisite && function_exists('get_sites') ? (int) get_sites(['count' => true, 'archived' => 0, 'spam' => 0, 'deleted' => 0]) : 0;
            $present = $present && $zeros === 0 && $fresh >= $active;
        }
        if ($present) {
            $written = @filemtime($net);
            return ($written !== false && ($now - (int) $written) <= 3600 && @is_file($net)) || wpc_fs_put($net, (string) $now) !== false;
        }
        return !@is_file($net) || @unlink($net);
    }

    /** The flag files and the marker directory a purge of the cache root keeps. */
    function wpc_flag_entries()
    {
        $entries = ['wpc-flags', 'wpc-cache-p1.txt'];
        foreach (wpc_off_flag_names() as $name) {
            $entries[] = 'wpc-' . str_replace('_', '-', $name) . '.txt';
        }
        return $entries;
    }

    /** Makes the p1 flag file say what the policies say (wpc_flag_sync). */
    function wpc_cache_p1_sync($on)
    {
        return wpc_flag_sync('wpc-cache-p1.txt', $on);
    }

    /** Whether the checks are on for this site: the policy switch as last received. The flag file the drop-in reads follows it. */
    function wpc_cache_p1_on()
    {
        $on = function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('cache_p1') === 1;
        wpc_cache_p1_sync($on);
        return $on;
    }

    /**
     * Of the tokens of a links record, the generated-store files that are gone: `w:` tokens
     * (cache/wp-cio/css and js) and `k:` tokens (the combined sheets and parked-CSS bundles, under
     * wp-content/cache). Up to three, as the tokens. A token that names no file (`w:*`, the other
     * kinds) says nothing.
     */
    function wpc_copy_tokens_missing(array $tokens)
    {
        $missing = [];
        if (!defined('WPS_IC_CACHE') || !defined('WP_CONTENT_DIR')) {
            return $missing;
        }
        foreach ($tokens as $token) {
            if (strncmp($token, 'w:', 2) === 0 && $token !== 'w:*') {
                $file = rtrim(WPS_IC_CACHE, '/') . '/' . substr($token, 2);
            } elseif (strncmp($token, 'k:', 2) === 0) {
                $file = rtrim(WP_CONTENT_DIR, '/') . '/cache/' . substr($token, 2);
            } else {
                continue;
            }
            if (strpos($file, '..') === false && !@is_file($file)) {
                $missing[] = $token;
                if (count($missing) >= 3) {
                    break;
                }
            }
        }
        return $missing;
    }

    /** The generated-store files a stored copy links that are gone, from the record beside it ([] when it has none). */
    function wpc_copy_assets_missing($dir, $prefix = '')
    {
        $record = wpc_copy_links_read(rtrim((string) $dir, '/') . '/' . (string) $prefix . 'links.txt');
        return $record === null ? [] : wpc_copy_tokens_missing($record['tokens']);
    }

    /**
     * The store verdict's reason a local-only copy was written under, for the X-WPC-CC header of
     * a file serve: the writer keeps it beside the copy ('[mobile_]localonly_reason.txt'), so the
     * drop-in reads one small file and no option. Rule: the header names the real reason.
     * Observed failure (webdesign4u.com.au, 2026-09-28): the page had crit and its copy was
     * private because of the device-mix hold; the render said local-only-device-mix, every file
     * serve said local-only-critless, and that pointed Denis at the wrong cause. A copy written
     * before the reason was kept has no file and keeps the old label, 'critless'.
     */
    function wpc_copy_reason_label($copy_path)
    {
        $reason_path = preg_replace('/(?:index|stale)\.html(?:_gzip|_br)?$/', 'reason.txt', (string) $copy_path);
        if ($reason_path !== null && $reason_path !== (string) $copy_path && @is_file($reason_path)) {
            $reason = trim((string) @file_get_contents($reason_path, false, null, 0, 64));
            if (preg_match('/^[a-z0-9-]{1,40}$/', $reason)) {
                return $reason;
            }
        }
        return 'critless';
    }

    /**
     * The render cohort stamp of the render in progress: the first 12 hex characters of
     * wpc_policy_render_signature(), or '' when no render-affecting cohort or lever is on or the
     * signature is not available.
     */
    function wpc_cohort_stamp_now()
    {
        if (!function_exists('wpc_policy_render_signature')) {
            return '';
        }
        $stamp = substr(strtolower((string) wpc_policy_render_signature()), 0, 12);
        return preg_match('/^[0-9a-f]{12}$/', $stamp) === 1 ? $stamp : '';
    }

    /**
     * The stamp a public copy was stored with, for the Server-Timing wpc-coh header of a file serve:
     * the writer keeps it beside the copy ('[mobile_]cohort.txt'). A copy stored before the stamp was
     * kept, or under no cohort, has no file and answers ''.
     */
    function wpc_copy_cohort_stamp($copy_path)
    {
        $record = wpc_copy_cohort_record($copy_path);
        return $record['stamp'];
    }

    /**
     * What a copy's cohort file holds: ['stamp' => the 12 hex or '', 'lh' => whether the copy was rendered with
     * cohort lcp_link_header on and carries its marked preload (the file reads '<stamp>+lh')].
     */
    function wpc_copy_cohort_record($copy_path)
    {
        $record = ['stamp' => '', 'lh' => false];
        $stamp_path = preg_replace('/(?:index|stale)\.html(?:_gzip|_br)?$/', 'cohort.txt', (string) $copy_path);
        if ($stamp_path !== null && $stamp_path !== (string) $copy_path && @is_file($stamp_path)) {
            $raw = trim((string) @file_get_contents($stamp_path, false, null, 0, 32));
            if (preg_match('/^([0-9a-f]{12})(\+lh)?$/', $raw, $m) === 1) {
                $record['stamp'] = $m[1];
                $record['lh'] = isset($m[2]);
            }
        }
        return $record;
    }

    /** Whether a render's document is stored as one the Link header is built from: cohort lcp_link_header is on and the document carries its marked preload. */
    function wpc_copy_link_header_stored($html)
    {
        return is_string($html) && strpos($html, 'data-wpc-lh') !== false
            && class_exists('wps_rewriteLogic') && wps_rewriteLogic::wpc_lcp_link_header_on();
    }

    /**
     * The family a reader serves for one device: local-only when that family holds a copy (fresh
     * or stale-marked), public otherwise. The writer removes the other family after every
     * rename, so both are present only for the instant between a rename and that unlink, and
     * then the local-only one wins: a private header on a public copy costs one origin hit, a
     * public header on a local-only copy hands the edge a page it must not hold.
     */
    function wpc_copy_family_on_disk($dir, $device)
    {
        $names = wpc_copy_names($dir, $device, WPC_COPY_FAMILY_LOCALONLY);
        foreach (['stale_gzip', 'stale_html', 'gzip', 'html'] as $name) {
            if (@is_file($names[$name]) && (int) @filesize($names[$name]) > 0) {
                return WPC_COPY_FAMILY_LOCALONLY;
            }
        }
        return '';
    }

    /** The gzip copy a side reader should read for one device, whichever family holds it, or ''. */
    function wpc_copy_present_gzip($dir, $device = '')
    {
        $names = wpc_copy_names($dir, $device, wpc_copy_family_on_disk($dir, $device));
        return @is_file($names['gzip']) ? $names['gzip'] : '';
    }
}

if (!function_exists('wpc_served_head')) {
    /**
     * The byte sizes of the head of the copy a page is served now, for one device ('mobile' or
     * 'desktop'): the record the store keeps beside the copy (wpc_served_head_record, the 'head'
     * name of wpc_copy_names). Reads that one small file and never the copy. Answers null when no
     * copy is stored for the device, when the copy has no record (it was stored before the record
     * existed, or its head could not be measured), or when the copy is not the one the record
     * measured: the record's stored_at is the copy's modification time, which a stale mark keeps and
     * a new copy changes. Answers the ten fields: raw_b, gz6_b (the whole head), crit_b, crit_gz6_b,
     * wpc_b, wpc_gz6_b, other_b, other_gz6_b, device, stored_at.
     */
    function wpc_served_head($urlKey, $device)
    {
        try {
            $urlKey = ltrim((string) $urlKey, '/');
            if ($urlKey === '' || strpos($urlKey, '..') !== false || !defined('WPS_IC_CACHE')
                || ($device !== 'mobile' && $device !== 'desktop')) {
                return null;
            }
            $dir = rtrim(WPS_IC_CACHE, '/') . '/' . $urlKey . '/';
            $prefix = $device === 'mobile' ? 'mobile_' : '';
            $names = wpc_copy_names($dir, $prefix, wpc_copy_family_on_disk($dir, $prefix));
            $copy = '';
            foreach (['gzip', 'stale_gzip'] as $role) {
                clearstatcache(true, $names[$role]);
                if ((int) @filesize($names[$role]) > 0) {
                    $copy = $names[$role];
                    break;
                }
            }
            if ($copy === '') {
                return null;
            }
            $record = json_decode((string) @file_get_contents($names['head'], false, null, 0, 4096), true);
            if (!is_array($record) || ($record['device'] ?? '') !== $device
                || (int) ($record['stored_at'] ?? 0) !== (int) @filemtime($copy)) {
                return null;
            }
            $head = [];
            foreach (['raw_b', 'gz6_b', 'crit_b', 'crit_gz6_b', 'wpc_b', 'wpc_gz6_b', 'other_b', 'other_gz6_b'] as $field) {
                if (!isset($record[$field]) || !is_int($record[$field]) || $record[$field] < 0) {
                    return null;
                }
                $head[$field] = $record[$field];
            }
            $head['device'] = $device;
            $head['stored_at'] = (int) $record['stored_at'];
            return $head;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The head record a crit dispatch carries for a page: the handset's when a handset copy has
     * one (PageSpeed measures the handset), else the desktop's, else null (the field is left out).
     */
    function wpc_served_head_for_dispatch($urlKey)
    {
        $head = wpc_served_head($urlKey, 'mobile');
        return $head !== null ? $head : wpc_served_head($urlKey, 'desktop');
    }
}

if (!function_exists('wpc_link_header_lines')) {
    /** The bytes of a document's head that carry the marked tags: a copy is read this far, a fresh render is cut here. */
    function wpc_link_header_scan_bytes()
    {
        return 32768;
    }

    /** False once the constant WPC_LCP_LINK_HEADER_OFF or the filter wpc_lcp_link_header turns the header off at a serve door, and for a signed-in visitor or an admin request. */
    function wpc_link_header_serve_on()
    {
        if (defined('WPC_LCP_LINK_HEADER_OFF') && WPC_LCP_LINK_HEADER_OFF) {
            return false;
        }
        if ((function_exists('is_user_logged_in') && is_user_logged_in()) || (function_exists('is_admin') && is_admin())) {
            return false;
        }
        return !function_exists('apply_filters') || (bool) apply_filters('wpc_lcp_link_header', true);
    }

    /** One attribute of a tag, entity-decoded, or null when the tag has none. */
    function wpc_link_header_attr($tag, $name)
    {
        if (!preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', (string) $tag, $m)) {
            return null;
        }
        $raw = (isset($m[2]) && $m[2] !== '') ? $m[2] : $m[1];
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** An absolute http(s) address that can ride inside <...> of a Link header: visible ASCII, none of " , ; < > \. */
    function wpc_link_header_url($value)
    {
        if (!is_string($value) || preg_match('#^https?://[\x21\x23-\x2b\x2d-\x3a\x3d\x3f-\x5b\x5d-\x7e]+$#i', $value) !== 1) {
            return false;
        }
        $host = parse_url($value, PHP_URL_HOST);
        return is_string($host) && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host) === 1;
    }

    /** A value that can ride inside quotes of a Link header parameter: visible ASCII or a space, no " and no \. */
    function wpc_link_header_text($value)
    {
        return is_string($value) && preg_match('/^[\x20\x21\x23-\x5b\x5d-\x7e]+$/', $value) === 1;
    }

    /**
     * The Link header lines a document asks for: the head's tag marked data-wpc-lh (the preload the
     * render wrote for its LCP, "1", or "zone" when that picture is on the zone) as a rel=preload
     * line carrying the tag's own href, fetchpriority, imagesrcset, imagesizes, media and type, and
     * for "zone" a rel=preconnect line for the picture's origin. One image line and one preconnect
     * line at most; a tag whose values cannot ride in a header, or a preload line over 2048 bytes,
     * adds no line. Only the first wpc_link_header_scan_bytes() bytes are read.
     */
    function wpc_link_header_lines($html)
    {
        $lines = [];
        if (!is_string($html) || strpos($html, 'data-wpc-lh') === false) {
            return $lines;
        }
        $head = substr($html, 0, wpc_link_header_scan_bytes());
        $end = stripos($head, '</head');
        if ($end !== false) {
            $head = substr($head, 0, $end);
        }
        $head = preg_replace('#<!--.*?(?:-->|$)|<(script|style|noscript|template)\b[^>]*>.*?(?:</\1\s*>|$)#is', '', $head);
        if (!is_string($head) || !preg_match_all('/<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $head, $tags)) {
            return $lines;
        }
        foreach ($tags[0] as $tag) {
            $mark = wpc_link_header_attr($tag, 'data-wpc-lh');
            if ($mark !== '1' && $mark !== 'zone') {
                continue;
            }
            $rel = wpc_link_header_attr($tag, 'rel');
            $as = wpc_link_header_attr($tag, 'as');
            if ($rel === null || $as === null || strcasecmp(trim($rel), 'preload') !== 0 || strcasecmp(trim($as), 'image') !== 0) {
                continue;
            }
            $href = trim((string) wpc_link_header_attr($tag, 'href'));
            $srcset = trim((string) wpc_link_header_attr($tag, 'imagesrcset'));
            if ($href === '' && $srcset !== '') {
                $first = preg_split('/[\s,]+/', $srcset);
                $href = (string) $first[0];
            }
            if (!wpc_link_header_url($href)) {
                continue;
            }
            $line = '<' . $href . '>; rel=preload; as=image';
            $priority = strtolower(trim((string) wpc_link_header_attr($tag, 'fetchpriority')));
            if ($priority === 'high' || $priority === 'low') {
                $line .= '; fetchpriority=' . $priority;
            }
            $ok = true;
            foreach (['imagesrcset', 'imagesizes', 'media', 'type'] as $param) {
                $value = wpc_link_header_attr($tag, $param);
                if ($value === null || trim($value) === '') {
                    continue;
                }
                if (!wpc_link_header_text(trim($value))) {
                    $ok = false;
                    break;
                }
                $line .= '; ' . $param . '="' . trim($value) . '"';
            }
            if ($ok && strlen($line) <= 2048) {
                $lines[] = 'Link: ' . $line;
            }
            if ($mark === 'zone') {
                $parts = parse_url($href);
                $lines[] = 'Link: <' . strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . '>; rel=preconnect';
            }
            break;
        }
        return $lines;
    }

    /** The first wpc_link_header_scan_bytes() bytes of a stored copy's document, or '' (a Brotli blob is read through its gzip or plain pair). */
    function wpc_link_header_scan_copy($copy_path)
    {
        $path = (string) $copy_path;
        if (substr($path, -3) === '_br') {
            $base = substr($path, 0, -3);
            $path = @is_file($base . '_gzip') ? $base . '_gzip' : $base;
        }
        if (!@is_readable($path)) {
            return '';
        }
        if (substr($path, -5) === '_gzip') {
            if (!function_exists('gzopen')) {
                return '';
            }
            $gz = @gzopen($path, 'rb');
            if (!$gz) {
                return '';
            }
            $bytes = @gzread($gz, wpc_link_header_scan_bytes());
            @gzclose($gz);
            return is_string($bytes) ? $bytes : '';
        }
        $bytes = @file_get_contents($path, false, null, 0, wpc_link_header_scan_bytes());
        return is_string($bytes) ? $bytes : '';
    }

    /**
     * Sends the Link lines a document asks for (wpc_link_header_lines) unless headers are gone or
     * the serve switch is off, and keeps what it sent in $GLOBALS['wpc_link_header_sent'] so the
     * stored header set leaves them out. Answers the number of lines sent.
     */
    function wpc_link_headers_send($html)
    {
        try {
            if (headers_sent() || !wpc_link_header_serve_on()) {
                return 0;
            }
            $lines = wpc_link_header_lines($html);
            foreach ($lines as $line) {
                wpc_send_header($line, false);
                $GLOBALS['wpc_link_header_sent'][] = substr($line, 6);
            }
            return count($lines);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** wpc_link_headers_send() for the copy a serve door is about to send. */
    function wpc_link_headers_send_copy($copy_path)
    {
        if (headers_sent() || !wpc_link_header_serve_on() || !wpc_copy_cohort_record($copy_path)['lh']) {
            return 0;
        }
        return wpc_link_headers_send(wpc_link_header_scan_copy($copy_path));
    }
}

if (!function_exists('wpc_ua_mobile_patterns')) {
    /**
     * A handset or tablet user agent, as three regex alternations: 'contains' and 'tokens' match
     * anywhere in the agent, 'prefix' at its start. wpc_ua_mobile_match() is the PHP test (the
     * render's wpc_ua_is_mobile() and the drop-in's bucket choice), and wps_ic_htaccess prints the
     * same alternations into Apache's static-serve rule, so every layer hands a device the copy
     * rendered for it.
     */
    function wpc_ua_mobile_patterns()
    {
        return [
            'contains' => 'ipad|tablet|windows phone|mobile',
            'tokens'   => '2.0\ MMP|240x320|400X240|mobile|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800',
            'prefix'   => 'w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-',
        ];
    }

    /** Whether $agent is a handset or tablet (wpc_ua_mobile_patterns). */
    function wpc_ua_mobile_match($agent)
    {
        $agent = strtolower((string) $agent);
        if ($agent === '') {
            return false;
        }
        $patterns = wpc_ua_mobile_patterns();
        return preg_match('#' . $patterns['contains'] . '|' . $patterns['tokens'] . '#i', $agent) === 1
            || preg_match('#^(?:' . $patterns['prefix'] . ')#i', $agent) === 1;
    }
}

if (!function_exists('wpc_cflog_path')) {
    /*
     * THE CACHE-FIRST LOG: wp-content/cache/wp-cio/wpc-cflog.php, the header WPC_CFLOG_HEADER and
     * then one JSON entry per line. The header is PHP that exits, so a request for the file over
     * HTTP answers with nothing on a server that runs PHP there, whatever it makes of .htaccess.
     * wpc_cflog_append() is its one writer: it writes the header before the first line, rewrites
     * a file that does not start with it, keeps the last 1 MB once the file passes 4 MB, and masks
     * API keys and IPv4 addresses (loopback aside) in every entry.
     */
    if (!defined('WPC_CFLOG_HEADER')) {
        define('WPC_CFLOG_HEADER', "<?php exit; __halt_compiler();\n");
    }

    /** The log's path, or '' before the cache root is defined. */
    function wpc_cflog_path()
    {
        return defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.php' : '';
    }

    /** $value with API key values and IPv4 addresses (loopback aside) masked, at any depth. */
    function wpc_cflog_mask($value)
    {
        if (is_array($value)) {
            foreach ($value as $name => $item) {
                if (is_string($name) && is_string($item) && preg_match('/^(?:url|uri|request_uri|ref|referr?er)$/i', $name)) {
                    $item = (string) preg_replace('/[?#].*$/s', '', $item);
                }
                $value[$name] = is_string($name) && preg_match('/^(?:api_?key|ip|server_addr|remote_addr)$/i', $name)
                    && is_scalar($item) && (string) $item !== '' ? '[masked]' : wpc_cflog_mask($item);
            }
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $value = (string) preg_replace('/(api_?key(?:=|%3D|["\']?\s*[:=]\s*["\']?))[^&"\'\s,;}]+/i', '$1[masked]', $value);
        return (string) preg_replace_callback('/(?<![\d.]|[A-Za-z]\/)(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?![\d.])/', function ($octets) {
            return (int) $octets[1] === 127 || max((int) $octets[1], (int) $octets[2], (int) $octets[3], (int) $octets[4]) > 255
                ? $octets[0] : '[ip]';
        }, $value);
    }

    /** Append one entry to the log. True when the line was written. */
    function wpc_cflog_append(array $entry)
    {
        try {
            $file = wpc_cflog_path();
            $line = $file !== '' ? json_encode(wpc_cflog_mask($entry), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : false;
            if (!is_string($line)) {
                return false;
            }
            if (!@is_dir(dirname($file))) {
                @mkdir(dirname($file), 0777, true);
            }
            $handle = @fopen($file, 'c+b');
            if (!$handle) {
                return false;
            }
            if (!@flock($handle, LOCK_EX)) {
                @fclose($handle);
                return false;
            }
            $stat = @fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;
            $head = $size > 0 ? (string) @fread($handle, strlen(WPC_CFLOG_HEADER)) : '';
            if ($head !== WPC_CFLOG_HEADER || $size > 4194304) {
                $kept = '';
                if ($head === WPC_CFLOG_HEADER) {
                    @fseek($handle, $size - 1048576);
                    $tail = (string) @stream_get_contents($handle);
                    $newline = strpos($tail, "\n");
                    $kept = $newline === false ? '' : substr($tail, $newline + 1);
                }
                @ftruncate($handle, 0);
                @rewind($handle);
                @fwrite($handle, WPC_CFLOG_HEADER . $kept);
                wpc_cflog_retire_public_file();
            }
            @fseek($handle, 0, SEEK_END);
            $written = @fwrite($handle, $line . "\n");
            @fflush($handle);
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return $written === strlen($line) + 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The log's last $bytes bytes, header excluded, cut to whole lines; '' when there is none. */
    function wpc_cflog_tail($bytes = 65536)
    {
        $file = wpc_cflog_path();
        $size = $file !== '' ? (int) @filesize($file) : 0;
        if ($size <= strlen(WPC_CFLOG_HEADER)) {
            return '';
        }
        $from = max(strlen(WPC_CFLOG_HEADER), $size - max(1, (int) $bytes));
        $tail = (string) @file_get_contents($file, false, null, $from);
        if ($from > strlen(WPC_CFLOG_HEADER)) {
            $newline = strpos($tail, "\n");
            $tail = $newline === false ? '' : substr($tail, $newline + 1);
        }
        return $tail;
    }

    /** One log entry per element, oldest first, from the last $bytes bytes of the log. */
    function wpc_cflog_entries($bytes = 65536)
    {
        $entries = [];
        foreach (explode("\n", wpc_cflog_tail($bytes)) as $line) {
            $entry = $line !== '' ? json_decode($line, true) : null;
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * The log's raw lines, oldest first: the last $lines entries written at or after $since (a unix
     * time; 0 = any). The one reader behind the admin-ajax wpc_cflog_tail and `wp wpcompress cflog`:
     * the log is wpc-cflog.php so HTTP answers nothing, and support and the proof workflow still
     * need whole entries, not the vitals panel's tail of 40.
     */
    function wpc_cflog_lines($lines = 500, $since = 0)
    {
        $lines = max(1, min(20000, (int) $lines));
        $since = max(0, (int) $since);
        $kept = [];
        foreach (explode("\n", wpc_cflog_tail(4194304 + 65536)) as $line) {
            if ($line === '') {
                continue;
            }
            if ($since > 0) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || (int) ($entry['t'] ?? 0) < $since) {
                    continue;
                }
            }
            $kept[] = $line;
        }
        return array_slice($kept, -$lines);
    }

    /** The log as it was named before 7.24.33, readable over HTTP: deleted whenever the log is started or cut, and when an update settles. */
    function wpc_cflog_retire_public_file()
    {
        if (defined('WPS_IC_CACHE') && @is_file(rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl')) {
            @unlink(rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl');
        }
    }
}

// ─── The cache-first log's entry point (last 40 events in the option fallback) ───────────────
// Defined here, beside its writer, because this file is loaded before every early exit a request
// can take: wp-compress-core.php includes it at the top, ahead of the WPC_IS_BG_SWAP return that
// the image service's REST callbacks (healthcheck, bg_swap*) take, and the direct-entry files
// api/v2/*.php include it first. It used to live in addons/cache/warm.php, which only the full
// boot includes, so every receipt those requests logged behind function_exists() was dropped
// (a junk-signature healthcheck answered 403 and left no v2-ping-refused line on the docker rig).
// Its only hard dependencies are this file's writer and apply_filters/get_option; the rest is
// behind function_exists() and joins in when the full plugin is loaded.
if (!function_exists('wpc_cache_first_log')) {
    function wpc_cache_first_log($event, $key = '', $url = '', $layers = [])
    {
        // v7.10.518 — breadcrumb for the 61.5s renders. The phase timeline proves the whole
        // stall sits between shutdown pri 0 and pri 999, but 16 of our callbacks live in that
        // band, so knowing the LAST thing we logged (and when) names the one that then hung.
        // Set before any work so a hang inside this function still leaves the crumb.
        $GLOBALS['wpc_last_cache_log_event'] = [(string) $event, microtime(true)];
        try {
            // The page-cache drop-in calls this before WordPress has loaded plugin.php and the
            // options API (wpc_fs_log routes through here), so the filter and the option fallback
            // are asked only when they exist; the file write needs neither.
            if (function_exists('apply_filters') && !apply_filters('wpc_cflog_verbose', true)
                && preg_match('/^(warm-rx|warm-wrote|warm-fired|warm-coalesced|warm-cron|kick-rx|second-wave)$/', (string) $event)) {
                return;
            }


            $entry = ['t' => time(), 'event' => (string) $event, 'key' => (string) $key, 'url' => (string) $url, 'layers' => $layers];
            $written = wpc_cflog_append($entry) || wpc_cflog_append($entry);
            if (!$written && function_exists('get_option')) {
                static $optionFallbackWrites = 0;
                if ($optionFallbackWrites < 3) {
                    $optionFallbackWrites++;
                    $log = get_option('wpc_cache_first_log', []);
                    if (!is_array($log)) {
                        $log = [];
                    }
                    $log[] = function_exists('wpc_cflog_mask') ? wpc_cflog_mask($entry) : $entry;
                    update_option('wpc_cache_first_log', array_slice($log, -40), false);
                }
            }


            // (auto toggle → 3-min floor → 25s throttle → single-flight), so this choke point
            // costs one boolean per event when Auto Mode is off.
            if (function_exists('wpc_auto_chain_maybe')
                && preg_match('/^(land-saved|fonts-landed|kick-rx|warm-rx)$/', (string) $event)) {
                wpc_auto_chain_maybe((string) $event);
            }


            if (function_exists('wpc_cohort_beacon_on') && wpc_cohort_beacon_on()) {
                if ($event === 'land-saved') {
                    wpc_cohort_beacon('crit_landed', ['key' => (string) $key]);
                } elseif ($event === 'fonts-landed') {
                    wpc_cohort_beacon('fonts_landed');
                } elseif ($event === 'warm-wrote' && defined('WPS_IC_CACHE') && defined('WPS_IC_CRITICAL')) {
                    $wpc_bl = rtrim(WPS_IC_CACHE, '/') . '/.beacon_armed';
                    if (!@file_exists($wpc_bl) && (string) $key !== '') {
                        $wpc_bc = WPS_IC_CRITICAL . $key . '/critical_desktop.css';
                        if (@is_readable($wpc_bc) && @filesize($wpc_bc) > 5) {
                            @touch($wpc_bl);
                            wpc_cohort_beacon('armed', ['key' => (string) $key]);
                        }
                    }
                }
            }
            // land-report beacon into the joint delivery ledger — land-saved is inline/reliable,
            // land-finalize is the settled point; throttled once per key|uuid, idempotent server-side.
            if (function_exists('wpc_land_report') && ($event === 'land-finalize' || $event === 'land-saved')) {
                wpc_land_report((string) $key);
            }
        } catch (\Throwable $e) {

        }
    }
}

if (!function_exists('wpc_fs_log')) {
    /**
     * One receipt line in the cache-first log from code that may run before WordPress (the
     * drop-in's stale re-warm and hit kick). wpc_cache_first_log() is defined above in this same
     * file and runs before WordPress, so this is its url-less form.
     */
    function wpc_fs_log($event, $key, array $layers)
    {
        wpc_cache_first_log((string) $event, (string) $key, '', $layers);
    }
}

if (!function_exists('wpc_belt_receipt')) {
    /**
     * The receipt of a belt that acted (repaired, dropped, withheld, refused, promoted): one line
     * per request per event, so a pass that runs twice in a render still writes once.
     *
     * $sampled true adds at most one line an hour per event and page through the shared gate
     * wpc_belt_rx_admit() (a small file per page under WPS_IC_CACHE/belt-rx/, no options rows);
     * the line carries `sampled:1` only when the gate kept the sample. Only a belt that acts on
     * most renders of a site by design samples; a belt that caught an owner's defect passes
     * false, because each of its lines is the evidence.
     * $key null keys the page this request renders (the crit url key, else the request path),
     * '' is site-wide (one sample for the whole site).
     * The events already written this request are held in $GLOBALS['wpc_belt_receipts_written'].
     */
    function wpc_belt_receipt($event, array $fields = [], $sampled = false, $key = null)
    {
        try {
            $event = (string) $event;
            if (isset($GLOBALS['wpc_belt_receipts_written'][$event])) {
                return false;
            }
            $GLOBALS['wpc_belt_receipts_written'][$event] = 1;
            $siteWide = ($key === '');
            if ($key === null) {
                $key = function_exists('wpc_crit_push_url_key') ? (string) wpc_crit_push_url_key() : '';
            }
            $key = (string) $key;
            if ($sampled) {
                $gate = wpc_belt_rx_admit([$event], $key, $siteWide ? '' : (isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : ''));
                if (!in_array($event, $gate['admit'], true)) {
                    return false;
                }
                if (!empty($gate['sampled'])) {
                    $fields['sampled'] = 1;
                }
            }
            $url = isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 200) : '';
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log($event, $key, $url, $fields);
            } else {
                wpc_cflog_append(['t' => time(), 'event' => $event, 'key' => $key, 'url' => $url, 'layers' => $fields]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_loopback_send')) {
    /*
     * LOOPBACK REQUESTS: ONE PLACE BUILDS THE TARGET, FROM THE SITE'S OWN URL.
     *
     * Rule: a loopback names the page's real URL path, with the install's subdirectory, and the
     * site's host, both taken from the site's own URLs (home_url() for a page, site_url() for a
     * WordPress file such as admin-ajax.php or wp-cron.php) whenever WordPress is loaded; before
     * WordPress (the advanced-cache drop-in) from WP_SITEURL, then from where ABSPATH sits under
     * the document root. A path assembled from '/' plus a file name lands on the parent site of
     * a subdirectory install: the drop-in's cron heartbeat asked https://noktaltema.com/wp-cron.php
     * for the install at /tibet/teknikservis/ (found by reading, 2026-09-24).
     *
     * Rule: a local rung (127.0.0.1, localhost) that answers with a redirect or a 4xx is not the
     * site, it is the web server's default host for that address, so the request goes on to the
     * next rung and the receipt names the answer. Observed on noktaltema.com/tibet/teknikservis
     * (cPanel, the site's vhost bound to its public IP), 2026-09-24 08:07 to 09:07 UTC: every
     * stale re-warm connected to 127.0.0.1, sent the right path with the right Host and SNI, got
     * `HTTP/1.1 404 Not Found` from the default vhost and was counted as fired, so the
     * soft-purged homepage was never re-stored by its re-warm (five receipts, `rung:127.0.0.1`,
     * `tries:""`), while the kick and the fixture exporter, which go through wp_remote_get() and
     * so through DNS to the public address, reached the site. A 5xx is the site itself failing
     * and is not retried on another address (it would only run the failing render twice).
     */

    /** True for a GET request: the only kind a stored copy's re-warm or hit kick answers for. */
    function wpc_loopback_request_is_get()
    {
        return !isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'GET';
    }

    /**
     * nonget-refused {what, method}: a non-GET request (our own Varnish `PURGE /.*` to 127.0.0.1
     * when no Varnish is in front) reached a stored copy and was refused the re-warm or the hit
     * kick a visit would have fired. Each one is logged: it means purgeVarnish sent a purge
     * nothing was there to receive. Once per request per kind.
     */
    function wpc_loopback_nonget_receipt($what, $urlKey)
    {
        static $written = [];
        if (isset($written[$what])) {
            return;
        }
        $written[$what] = 1;
        $method = isset($_SERVER['REQUEST_METHOD']) ? substr(strtoupper((string) $_SERVER['REQUEST_METHOD']), 0, 16) : '';
        wpc_fs_log('nonget-refused', (string) $urlKey, ['what' => (string) $what, 'method' => $method]);
    }

    /** Whether the request this code answers arrived over TLS, directly or behind a proxy. */
    function wpc_loopback_request_is_https()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /** scheme://host[:port] of the request this code answers, '' without a Host header. */
    function wpc_loopback_request_origin()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        return $host === '' ? '' : (wpc_loopback_request_is_https() ? 'https://' : 'http://') . $host;
    }

    /** scheme://host[:port] of an absolute URL, '' when it has no host. */
    function wpc_loopback_url_origin($url)
    {
        $parts = parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        return (isset($parts['scheme']) && strtolower($parts['scheme']) === 'https' ? 'https://' : 'http://')
            . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * The WordPress folder's place on this host, worked out without WordPress: '/' for a root
     * install, '/wp/' for WordPress in its own directory or a subdirectory install, '/' when the
     * document root and ABSPATH do not nest.
     */
    function wpc_loopback_install_path_from_disk()
    {
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? @realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        $wordpressRoot = defined('ABSPATH') ? @realpath(ABSPATH) : false;
        $base = '/';
        if ($documentRoot && $wordpressRoot) {
            $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
            $wordpressRoot = rtrim(str_replace('\\', '/', $wordpressRoot), '/');
            if (strpos($wordpressRoot . '/', $documentRoot . '/') === 0) {
                $inside = trim(substr($wordpressRoot, strlen($documentRoot)), '/');
                $base = $inside === '' ? '/' : '/' . $inside . '/';
            }
        }
        return $base;
    }

    /**
     * The absolute URL of a file in the WordPress folder ('wp-admin/admin-ajax.php',
     * 'wp-cron.php'): site_url() when WordPress is loaded, else WP_SITEURL, else the request's
     * origin plus the folder's place under the document root. '' when none is known.
     */
    function wpc_loopback_install_url($relative)
    {
        $relative = ltrim((string) $relative, '/');
        if (function_exists('site_url')) {
            return rtrim((string) site_url('/'), '/') . '/' . $relative;
        }
        if (defined('WP_SITEURL') && wpc_loopback_url_origin(WP_SITEURL) !== '') {
            return rtrim((string) WP_SITEURL, '/') . '/' . $relative;
        }
        $origin = wpc_loopback_request_origin();
        return $origin === '' ? '' : $origin . wpc_loopback_install_path_from_disk() . $relative;
    }

    /**
     * The absolute URL of the page this request asked for, without its query: home_url()'s
     * origin when WordPress is loaded (the request's own origin before it), and the request's
     * path, which already carries the install's subdirectory.
     */
    function wpc_loopback_page_url()
    {
        $origin = function_exists('home_url') ? wpc_loopback_url_origin(home_url('/')) : '';
        if ($origin === '') {
            $origin = wpc_loopback_request_origin();
        }
        $path = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
        return $origin === '' ? '' : $origin . $path;
    }

    /**
     * What a loopback to $url connects to and asks for: {https, host (with any port), path (with
     * any query)}, or [] when the URL has no host or carries anything a request line or a Host
     * header may not.
     */
    function wpc_loopback_target($url)
    {
        $parts = parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host'])) {
            return [];
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $path = (isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/')
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        if (preg_match('/[^A-Za-z0-9.\-:\[\]]/', $host) || preg_match('/[\s"\'\x00-\x1f]/', $path)) {
            return [];
        }
        return ['https' => isset($parts['scheme']) && strtolower($parts['scheme']) === 'https', 'host' => $host, 'path' => $path];
    }

    /** The request a loopback writes: GET {path} with the site's Host and $headers, then closes. */
    function wpc_loopback_request(array $target, array $headers)
    {
        $request = 'GET ' . $target['path'] . " HTTP/1.1\r\nHost: " . $target['host'] . "\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . preg_replace('/[\r\n]+/', ' ', (string) $value) . "\r\n";
        }
        return $request . "Connection: close\r\n\r\n";
    }

    /**
     * Send one request to this site after the response is out, and forget it: the visitor has
     * the page before any socket opens. Connects to 127.0.0.1, then localhost, then the site's
     * own host name; with the response released it reads the status line (so the receiver has
     * the request before the socket closes) and goes on to the next rung when a local address
     * answers with a redirect or a 4xx (see the rule above); otherwise it writes and closes at
     * once. Returns what a receipt carries: {rung, fin, status, tries}; `tries` lists every rung
     * passed over, `127.0.0.1(111)` for a refused connect, `127.0.0.1(http404)` for an answer
     * that was not the site.
     */
    function wpc_loopback_send(array $target, array $headers)
    {
        $released = false;
        if (function_exists('wpc_finish_request')) {
            $released = (bool) wpc_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            $released = true;
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
            if (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
                $released = true;
            }
        }
        $request = wpc_loopback_request($target, $headers);
        $https = !empty($target['https']);
        $hostName = (string) preg_replace('/:\d+$/', '', (string) $target['host']);
        $port = $https ? 443 : 80;
        if (preg_match('/:(\d+)$/', (string) $target['host'], $portMatch)) {
            $port = (int) $portMatch[1];
        }
        $context = $https ? stream_context_create(['ssl' => [
            'peer_name' => $hostName, 'SNI_enabled' => true,
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]) : stream_context_create();
        $tries = [];
        foreach (['127.0.0.1', 'localhost', $hostName] as $rung) {
            $errorNumber = 0;
            $errorText = '';
            $socket = @stream_socket_client(($https ? 'tls://' : 'tcp://') . $rung . ':' . $port, $errorNumber, $errorText, $released ? 1.0 : 0.3, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                $tries[] = $rung . '(' . (int) $errorNumber . ')';
                continue;
            }
            @stream_set_timeout($socket, 0, 200000);
            @fwrite($socket, $request);
            $status = '';
            if ($released) {
                @stream_set_timeout($socket, 3, 0);
                $status = trim((string) @fgets($socket));
            }
            @fclose($socket);
            $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $status, $codeMatch) ? (int) $codeMatch[1] : 0;
            if ($rung !== $hostName && $code >= 300 && $code < 500) {
                $tries[] = $rung . '(http' . $code . ')';
                continue;
            }
            return ['rung' => $rung, 'fin' => $released ? 1 : 0, 'status' => substr($status, 0, 40), 'tries' => implode(',', $tries)];
        }
        return ['rung' => '', 'fin' => $released ? 1 : 0, 'status' => '', 'tries' => implode(',', $tries)];
    }
}

if (!function_exists('wpc_warm_request')) {
    /** The key the warm header is made with: the site's salts and its database credentials, which the drop-in has before WordPress loads and which every node of a site that shares one wp-config.php has alike. Read once per process. */
    function wpc_warm_secret()
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }
        $parts = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $constant) {
            if (defined($constant)) {
                $parts .= (string) constant($constant);
            }
        }
        $secret = hash('sha256', 'wpc-warm|' . $parts);
        return $secret;
    }

    /**
     * A host in the form it travels in: lower case, no port, and punycode for a name that is not
     * ASCII (wp_remote_get sends the Host header and the request line in punycode, while the site's
     * own URL, which the sender signs, is Unicode). A browser or a client that sends the Unicode
     * name is read the same way.
     */
    function wpc_warm_host($host)
    {
        $host = (string) preg_replace('/:\d+$/', '', (string) $host);
        $host = function_exists('mb_strtolower') ? mb_strtolower($host, 'UTF-8') : strtolower($host);
        if ($host === '' || preg_match('/^[\x00-\x7F]*$/', $host) === 1) {
            return $host;
        }
        if (function_exists('idn_to_ascii')) {
            $ascii = defined('INTL_IDNA_VARIANT_UTS46') ? @idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46) : @idn_to_ascii($host);
            if (is_string($ascii) && $ascii !== '') {
                return strtolower($ascii);
            }
        }
        foreach (['WpOrg\\Requests\\IdnaEncoder', 'Requests_IDNAEncoder'] as $class) {
            if (class_exists($class) && method_exists($class, 'encode')) {
                try {
                    return strtolower((string) $class::encode($host));
                } catch (\Throwable $e) {
                }
            }
        }
        return $host;
    }

    /** The keyed hash of a host, a path (no query, percent-encoding in its normal form: wp_remote_get rewrites a request's path that way) and a time: what the header carries beside the time. $kind 'diag' is the diagnostic header's, which a warm header cannot stand in for. */
    function wpc_warm_mac($host, $path, $time, $kind = 'warm')
    {
        $host = wpc_warm_host($host);
        $path = (string) explode('?', (string) $path, 2)[0];
        $path = (string) preg_replace_callback('/%([0-9a-fA-F]{2})/', function ($found) {
            $character = chr(hexdec($found[1]));
            return preg_match('/^[A-Za-z0-9._~-]$/', $character) === 1 ? $character : '%' . strtoupper($found[1]);
        }, $path);
        $path = (string) preg_replace_callback('/[\x80-\xFF]/', function ($found) {
            return '%' . strtoupper(bin2hex($found[0]));
        }, $path);
        return substr(hash_hmac('sha256', ($kind === 'diag' ? 'diag' : 'warm') . '|' . $host . '|' . $path . '|' . (int) $time, wpc_warm_secret()), 0, 20);
    }

    /** The value of X-WPC-Cache-Warm (or, with $kind 'diag', X-WPC-Diag) for a request the plugin sends to $url: the time and the keyed hash of the host, the path and the time. */
    function wpc_warm_header_for($url, $kind = 'warm')
    {
        $parts = parse_url((string) $url);
        $time = time();
        return $time . '.' . wpc_warm_mac(isset($parts['host']) ? (string) $parts['host'] : '', isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/', $time, $kind);
    }

    /** The same for a loopback target (wpc_loopback_target(): host, path with its query, https). */
    function wpc_warm_header_for_target(array $target)
    {
        return wpc_warm_header_for((!empty($target['https']) ? 'https' : 'http') . '://' . (string) ($target['host'] ?? '') . (string) ($target['path'] ?? '/'));
    }

    /**
     * A header that was sent and did not verify, once an hour for the whole site through the belt
     * receipts' sampling gate: which check refused it and the lengths of the host and the path this
     * request carried (a rewritten Host, an absolute-form request line and percent-encoding in the
     * path are what make the plugin's own fetch look like a visitor's). The header's value, the
     * host, the path and anything of the visitor are not written.
     */
    function wpc_warm_unsigned_note($kind, $why)
    {
        try {
            if (!function_exists('wpc_belt_rx_admit') || !function_exists('wpc_cache_first_log') || isset($GLOBALS['wpc_belt_receipts_written']['warm-unsigned'])) {
                return;
            }
            $GLOBALS['wpc_belt_receipts_written']['warm-unsigned'] = 1;
            $gate = wpc_belt_rx_admit(['warm-unsigned'], '', '');
            if (!in_array('warm-unsigned', $gate['admit'], true)) {
                return;
            }
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
            $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            wpc_cache_first_log('warm-unsigned', '', '', [
                'kind' => $kind === 'diag' ? 'diag' : 'warm', 'why' => (string) $why,
                'host_len' => strlen($host), 'path_len' => strlen($path),
                'absolute' => preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 ? 1 : 0,
                'percent' => strpos($path, '%') !== false ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Whether this request is one of the plugin's own warm fetches (X-WPC-Cache-Warm), or with $kind
     * 'diag' one of its diagnostic fetches (X-WPC-Diag): the header names a time within thirty
     * minutes of now and the keyed hash of this request's host, path and that time. The one reader of
     * either header; a request that carries the bare header, or a hash for another address, is a
     * visitor's, unless the service put the site back on the bare header (policy.off_warm_sig).
     */
    function wpc_warm_request($kind = 'warm')
    {
        $name = $kind === 'diag' ? 'HTTP_X_WPC_DIAG' : 'HTTP_X_WPC_CACHE_WARM';
        $value = isset($_SERVER[$name]) ? (string) $_SERVER[$name] : '';
        if ($value === '') {
            return false;
        }
        $why = 'format';
        if (preg_match('/^(\d{9,11})\.([a-f0-9]{20})$/', $value, $found) === 1) {
            $why = 'window';
            if (abs(time() - (int) $found[1]) <= 1800) {
                $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
                $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
                if (hash_equals(wpc_warm_mac($host, $path, (int) $found[1], $kind), $found[2])) {
                    return true;
                }
                $why = 'hash';
            }
        }
        if (wpc_off_flag('off_warm_sig')) {
            return !empty($_SERVER[$name]);
        }
        wpc_warm_unsigned_note($kind, $why);
        return false;
    }
}

if (!function_exists('wpc_address_identity')) {
    /** A page's address reduced to what its key stands for: host without www, path without its outer slashes; no scheme, port, query or fragment. */
    function wpc_address_identity($address)
    {
        $address = trim((string) $address);
        if ($address === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) !== 1) {
            $address = 'http://' . ltrim($address, '/');
        }
        $parts = parse_url($address);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return (strpos($host, 'www.') === 0 ? substr($host, 4) : $host) . '/' . trim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * Whether the address stored for a page folder (its url.txt, the page folder's first, then the
     * critical folder's) names another page than $url. Two addresses can share a key, since the key
     * drops slashes (/pri/cing/ keys as /pricing/), so a 404 at one of them says nothing about the
     * copies of the other. A folder with no stored address says nothing either way.
     */
    function wpc_page_address_differs($dir, $url)
    {
        $dir = rtrim((string) $dir, '/');
        if ($dir === '') {
            return false;
        }
        $files = [$dir . '/url.txt'];
        if (defined('WPS_IC_CRITICAL')) {
            $files[] = rtrim(WPS_IC_CRITICAL, '/') . '/' . basename($dir) . '/url.txt';
        }
        foreach ($files as $file) {
            if (@is_readable($file)) {
                $stored = wpc_address_identity((string) @file_get_contents($file));
                if ($stored !== '') {
                    return $stored !== wpc_address_identity($url);
                }
            }
        }
        return false;
    }
}

if (!function_exists('wpc_crit_canonical_key')) {
    /** The crit service's key for a page address (canonicalizeForKey in crit-push latest-artifacts.js): https, the host lower-cased without a leading www. and without its port, the path as sent with a non-root trailing slash folded, the query's page parameters decoded and sorted by UTF-16 code unit as JavaScript compares strings, no fragment. Null when the address is not one; a caller comparing two keys must treat null as no match. */
    function wpc_crit_canonical_key($url)
    {
        if (!is_string($url) || preg_match('//u', $url) !== 1) {
            return null;
        }
        $space = '[\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
        $u = preg_replace('/^' . $space . '+|' . $space . '+$/u', '', $url);
        if (!is_string($u) || $u === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $u) !== 1) {
            $u = 'https://' . preg_replace('#^(?://|:\/\/)#', '', $u);
        }
        $u = preg_replace('#^http://#i', 'https://', $u);
        $u = str_replace(array("\t", "\n", "\r"), '', trim($u, "\x00..\x20"));
        $rest = ltrim((string) preg_replace('#^https?:#i', '', $u), "/\\");
        $authorityEnd = strcspn($rest, "/\\?#");
        $authority = (string) substr($rest, 0, $authorityEnd);
        $tail = (string) substr($rest, $authorityEnd);
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : (string) substr($authority, $at + 1);
        if ($hostPort === '' || $hostPort[0] === '[') {
            return null;
        }
        $colon = strpos($hostPort, ':');
        $host = $colon === false ? $hostPort : (string) substr($hostPort, 0, $colon);
        $port = $colon === false ? '' : (string) substr($hostPort, $colon + 1);
        if ($port !== '' && (!ctype_digit($port) || strlen(ltrim($port, '0')) > 5 || (int) $port > 65535)) {
            return null;
        }
        $host = rawurldecode($host);
        if ($host === '' || preg_match('//u', $host) !== 1 || preg_match('/[\x00-\x20#\/:<>?@\[\\\\\]^|%\x7f]/', $host) === 1) {
            return null;
        }
        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii) || $ascii === '') {
                return null;
            }
            $host = $ascii;
        }
        $host = strtolower($host);
        $parts = explode('.', $host);
        $last = (string) end($parts);
        if ($last === '' && count($parts) > 1) {
            $last = (string) $parts[count($parts) - 2];
        }
        if ($last !== '' && (ctype_digit($last) || preg_match('/^0x[0-9a-f]*$/D', $last) === 1)) {
            if (count($parts) > 1 && end($parts) === '') {
                array_pop($parts);
            }
            if (count($parts) > 4) {
                return null;
            }
            $numbers = array();
            foreach ($parts as $part) {
                if ($part === '') {
                    return null;
                }
                if (preg_match('/^0x([0-9a-f]*)$/D', $part, $hex) === 1) {
                    $number = $hex[1] === '' ? 0 : hexdec($hex[1]);
                } elseif (strlen($part) > 1 && $part[0] === '0') {
                    if (preg_match('/^[0-7]+$/D', $part) !== 1) {
                        return null;
                    }
                    $number = octdec($part);
                } elseif (ctype_digit($part)) {
                    $number = (float) $part;
                } else {
                    return null;
                }
                $numbers[] = $number;
            }
            $final = array_pop($numbers);
            foreach ($numbers as $number) {
                if ($number > 255) {
                    return null;
                }
            }
            if ($final >= pow(256, 5 - count($parts))) {
                return null;
            }
            $address = (float) $final;
            foreach ($numbers as $position => $number) {
                $address += $number * pow(256, 3 - $position);
            }
            $host = (int) ($address / 16777216) % 256 . '.' . (int) ($address / 65536) % 256 . '.' . (int) ($address / 256) % 256 . '.' . (int) $address % 256;
        }
        if (strpos($host, 'www.') === 0) {
            $host = (string) substr($host, 4);
        }
        if ($host === '' || strpos($host, '.') === false || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            return null;
        }

        $fragmentAt = strpos($tail, '#');
        if ($fragmentAt !== false) {
            $tail = (string) substr($tail, 0, $fragmentAt);
        }
        $queryAt = strpos($tail, '?');
        $query = $queryAt === false ? '' : (string) substr($tail, $queryAt + 1);
        $path = str_replace('\\', '/', $queryAt === false ? $tail : (string) substr($tail, 0, $queryAt));
        $path = (string) preg_replace_callback('/[\x00-\x20"<>^`{}\x7f-\xff]/', function ($m) {
            return '%' . strtoupper(bin2hex($m[0]));
        }, $path);
        $segments = array();
        $pieces = $path === '' ? array() : explode('/', (string) substr($path, 1));
        foreach ($pieces as $at => $piece) {
            $dots = strtolower($piece);
            $isLast = $at === count($pieces) - 1;
            if ($dots === '..' || $dots === '.%2e' || $dots === '%2e.' || $dots === '%2e%2e') {
                array_pop($segments);
                if ($isLast) {
                    $segments[] = '';
                }
            } elseif ($dots === '.' || $dots === '%2e') {
                if ($isLast) {
                    $segments[] = '';
                }
            } else {
                $segments[] = $piece;
            }
        }
        $path = '/' . implode('/', $segments);
        if (strlen($path) > 1 && substr($path, -1) === '/') {
            $path = (string) substr($path, 0, -1);
        }

        $service = array(
            'criticalcombine' => 1, 'disablewpc' => 1, 'forcecritical' => 1, 'testcompliant' => 1,
            'wpcprobe' => 1, 'nocache' => 1, 'disable_cache' => 1, 'ab' => 1,
            'source' => 1, 'sync' => 1, 'force' => 1, 'version' => 1, 'tpl_key' => 1, 'post_modified' => 1,
            'adaptive_q' => 1, 'verify' => 1, 'target_butter' => 1, 'crit' => 1, 'uv' => 1, 'icv' => 1,
        );
        $decode = function ($raw) {
            $text = urldecode($raw);
            if (preg_match('//u', $text) !== 1) {
                if (!function_exists('mb_substitute_character') || !function_exists('mb_convert_encoding')) {
                    return null;
                }
                $was = mb_substitute_character();
                mb_substitute_character(0xFFFD);
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
                mb_substitute_character($was);
            }
            return $text;
        };
        $kept = array();
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $equals = strpos($pair, '=');
            $name = $decode($equals === false ? $pair : (string) substr($pair, 0, $equals));
            $value = $decode($equals === false ? '' : (string) substr($pair, $equals + 1));
            if ($name === null || $value === null) {
                return null;
            }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (isset($service[$lower]) || strncmp($lower, 'wpc', 3) === 0) {
                continue;
            }
            $kept[] = array($name, $value);
        }
        $order = function ($text) {
            return (string) preg_replace('/[\xF0-\xF4][\x80-\xBF]{3}/', "\xED" . '$0', $text);
        };
        usort($kept, function ($a, $b) use ($order) {
            $byName = strcmp($order($a[0]), $order($b[0]));
            return $byName !== 0 ? $byName : strcmp($order($a[1]), $order($b[1]));
        });
        $pairs = array();
        foreach ($kept as $entry) {
            $pairs[] = $entry[0] . '=' . $entry[1];
        }
        return $host . $path . ($pairs ? '?' . implode('&', $pairs) : '');
    }
}

if (!function_exists('wpc_crit_key_binds')) {
    /** Whether a url_key the service signed names the page it was sent for: its canonical key (wpc_crit_canonical_key) is the canonical key of any address the page is known by, or the signed key without its query keys to $pageKey, the page's own folder key (wps_ic_url_key::setup of its address without the query). */
    function wpc_crit_key_binds($signedKey, array $pageAddresses, $pageKey)
    {
        $signedKey = (string) $signedKey;
        $signedCanon = wpc_crit_canonical_key($signedKey);
        if ($signedCanon !== null) {
            foreach ($pageAddresses as $address) {
                if (wpc_crit_canonical_key((string) $address) === $signedCanon) {
                    return true;
                }
            }
        }
        $pageKey = ltrim((string) $pageKey, '/');
        if ($pageKey === '' || !class_exists('wps_ic_url_key')) {
            return false;
        }
        return ltrim((string) (new wps_ic_url_key())->setup((string) strtok($signedKey, '?')), '/') === $pageKey;
    }
}

if (!function_exists('wpc_cache_phase_on')) {
    /**
     * Whether the orchestrator switched a phase of the page-cache plan on for this site: the flat
     * policy flag `cache_<phase>` ('p2', 'p3', 'p4'), read through wpc_policy_cohort(). Off when the
     * policy carries none. (Phase p1 has its own reader, wpc_cache_p1_on(), which also keeps the
     * flag file the drop-in reads.)
     */
    function wpc_cache_phase_on($phase)
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('cache_' . (string) $phase) === 1;
    }
}

if (!function_exists('wpc_off_flag')) {
    /** The policy switches that turn a shipped behaviour off and are read where WordPress is not loaded (the drop-in): each has a flag file in the cache root. */
    function wpc_off_flag_names()
    {
        return ['off_wire_gzip', 'off_gone_drop', 'off_warm_sig'];
    }

    /** The flag file of one such switch. */
    function wpc_off_flag_file($name)
    {
        $root = defined('WPS_IC_CACHE') ? WPS_IC_CACHE : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/wp-cio/' : '');
        return $root === '' ? '' : rtrim($root, '/') . '/wpc-' . str_replace('_', '-', (string) $name) . '.txt';
    }

    /** Makes the flag file of one switch say what the policies say (wpc_flag_sync). */
    function wpc_off_flag_sync($name, $on)
    {
        return wpc_flag_sync('wpc-' . str_replace('_', '-', (string) $name) . '.txt', $on);
    }

    /**
     * Whether the service turned a shipped behaviour off for this site (policy.<name>, absent or 0
     * keeps it): the policy of this blog as last received where WordPress runs, with this blog's
     * marker made to follow it; the flag file in the drop-in, which wpc_flag_sync makes the union of
     * the blogs' policies (the intersection for the switches in wpc_flag_intersection_files) and
     * which counts only while it was written within the bound. The one reader of each switch in
     * wpc_off_flag_names().
     */
    function wpc_off_flag($name)
    {
        if (function_exists('wpc_policy_cohort')) {
            $on = (int) wpc_policy_cohort((string) $name) === 1;
            wpc_off_flag_sync($name, $on);
            return $on;
        }
        $file = wpc_off_flag_file($name);
        return $file !== '' && wpc_flag_fresh($file);
    }
}

if (!function_exists('wpc_purge_scope_old')) {
    /**
     * Whether the service put this site back on the purge scope 7.25.50 had (policy.off_scope_narrow,
     * absent or 0 keeps the narrower scope): the digits of a post id and 0 are not read as a post
     * and the scope names are compared loosely. The one reader of the switch.
     */
    function wpc_purge_scope_old()
    {
        return function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('off_scope_narrow') === 1;
    }

    /** Whether a purge scope names the whole site. */
    function wpc_purge_scope_all($scope)
    {
        return wpc_purge_scope_old() ? $scope == 'all' : $scope === 'all';
    }
}

if (!function_exists('wpc_wire_gzip_ok')) {
    /**
     * Whether this response may carry the stored gzip bytes as they are, with `Content-Encoding:
     * gzip`: the request accepts gzip (`Accept-Encoding` read as a list, `x-gzip` too, `gzip;q=0`
     * refused), it did not come through Cloudflare (which re-encodes the plain body itself and is
     * left on that path), no header has gone out, and nothing after PHP is compressing already
     * (`zlib.output_compression`, an `ob_gzhandler` or the zlib handler in the buffer list). The
     * constant WPC_WIRE_GZIP false turns it off. The drop-in loads this file, so it reads no option
     * and calls no filter.
     */
    function wpc_wire_gzip_ok()
    {
        if (defined('WPC_WIRE_GZIP') && !WPC_WIRE_GZIP) {
            return false;
        }
        if (wpc_off_flag('off_wire_gzip')) {
            return false;
        }
        $accept = strtolower((string) (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : ''));
        $accepted = false;
        foreach (explode(',', $accept) as $coding) {
            $parts = explode(';', $coding);
            $name = trim($parts[0]);
            if ($name !== 'gzip' && $name !== 'x-gzip') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $found)) {
                    $quality = (float) $found[1];
                }
            }
            $accepted = $quality > 0;
        }
        if (!$accepted || isset($_SERVER['HTTP_CF_RAY']) || headers_sent()) {
            return false;
        }
        $zlib = strtolower(trim((string) ini_get('zlib.output_compression')));
        if ($zlib !== '' && $zlib !== '0' && $zlib !== 'off') {
            return false;
        }
        foreach ((array) ob_list_handlers() as $handler) {
            if ($handler === 'ob_gzhandler' || $handler === 'zlib output compression') {
                return false;
            }
        }
        return true;
    }

    /**
     * Sends the stored gzip file as the response body when wpc_wire_gzip_ok() says the bytes may go
     * as they are: the output buffers that run a callback are dropped first, from the innermost to
     * the outermost of them (the drop-in's own buffer would hand gzip bytes to the page store); a
     * buffer with no callback passes the bytes through. Then `Content-Encoding: gzip` and the file. Answers whether it sent
     * the body; false leaves the response untouched for the plain path.
     */
    function wpc_send_gzip_copy($file)
    {
        if (!wpc_wire_gzip_ok() || !@is_readable($file) || (int) @filesize($file) <= 0) {
            return false;
        }
        $lowest = null;
        foreach (array_values((array) ob_list_handlers()) as $level => $handler) {
            if ($handler !== 'default output handler') {
                $lowest = $level;
                break;
            }
        }
        while ($lowest !== null && ob_get_level() > $lowest && @ob_end_clean()) {
        }
        if ($lowest !== null && ob_get_level() > $lowest) {
            return false;
        }
        wpc_send_header('Content-Encoding: gzip');
        return @readfile($file) !== false;
    }
}

if (!function_exists('wpc_serve_stale_copy')) {
    /*
     * THE STALE SERVE AND ITS RE-WARM, ONE COPY FOR BOTH READERS (the advanced-cache drop-in and
     * the plugin's own serve load this file; each carried its own twin before).
     *
     * Rule: only a GET is a visit to the page. Observed on staging 2026-09-24 07:02:13 UTC: right
     * after a site-wide soft purge, `stale-fire {key:"/.*", status:"HTTP/1.1 302 Moved
     * Temporarily"}`. The purge's Varnish step sends `PURGE /.*` (X-Purge-Method: regex) to
     * 127.0.0.1 with the site's Host; with no Varnish in front it reached the drop-in, which
     * lets anything but POST and HEAD through, and the cache key drops the `.*`, so the purge
     * request was served the homepage's stale copy, used up one of the homepage's four re-warm
     * tries (and its 120 s spacing) and fired a re-warm for the path `/.*`, which WordPress
     * redirects. A non-GET request now neither counts nor fires; the copy it is served is
     * unchanged.
     */
    function wpc_serve_stale_copy($dir, $prefix = '', $private = false)
    {
        try {
            if (!wpc_loopback_request_is_get()) {
                wpc_loopback_nonget_receipt('rewarm', '');
                return false;
            }
            if (function_exists('wpc_lever_test_active') && wpc_lever_test_active()) {
                return false;
            }
            wpc_send_header('Cache-Control: ' . ($private ? 'private, ' : '') . 'no-cache, max-age=0, must-revalidate');
            wpc_send_header('Expires: ' . gmdate('D, d M Y H:i:s', time() - 60) . ' GMT');
            wpc_send_header('X-WPC-Cache: stale-rewarm');
            $dir = rtrim((string) $dir, '/') . '/';
            $rewarmCounterFile = $dir . $prefix . 'wpc-rewarm43.txt';
            $staleSince = (int) @file_get_contents(rtrim(WPS_IC_CACHE, '/') . '/wpc-stale43.txt');
            foreach (['stale.html_gzip', 'stale.html'] as $staleCopyName) {
                $staleCopyTime = (int) @filemtime($dir . $prefix . $staleCopyName);
                if ($staleCopyTime > $staleSince) {
                    $staleSince = $staleCopyTime;
                }
            }
            $rewarmsFired = 0;
            if (@is_file($rewarmCounterFile) && (int) @filemtime($rewarmCounterFile) >= $staleSince) {
                if (time() - (int) @filemtime($rewarmCounterFile) < 120) {
                    return false;
                }
                $rewarmsFired = (int) @file_get_contents($rewarmCounterFile);
            }
            wpc_fs_put($rewarmCounterFile, (string) ($rewarmsFired + 1));
            $target = wpc_loopback_target(wpc_loopback_page_url());
            if ($target === []) {
                return false;
            }
            $userAgent = substr((string) preg_replace('/[\r\n]+/', ' ', isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0'), 0, 300);
            $accept = substr((string) preg_replace('/[\r\n]+/', ' ', isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : 'text/html'), 0, 200);
            register_shutdown_function('wpc_fire_stale_rewarm', $target, $userAgent, $accept, $dir, (string) $prefix);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The re-warm itself, after the response: the page's own URL, as the visitor's device. The
     * answer the site's own host gives is read: a page that answers 404 or 410 is gone, and its
     * stale copy is removed now (wpc_stale_unlink_copies) instead of being served until its window
     * ends or four rewarms have been spent on it. A status that was not read (the response could not
     * be released first) decides nothing.
     */
    function wpc_fire_stale_rewarm(array $target, $userAgent, $accept, $dir = '', $prefix = '')
    {
        try {
            $sent = wpc_loopback_send($target, ['User-Agent' => $userAgent, 'Accept' => $accept, 'X-WPC-Cache-Warm' => wpc_warm_header_for_target($target)]);
            wpc_fs_log('stale-fire', substr((string) $target['path'], 0, 120), $sent);
            if ($dir !== '' && $sent['rung'] !== '' && preg_match('#^HTTP/\S+\s+(404|410)\b#', (string) $sent['status'], $gone) === 1 && function_exists('wpc_stale_unlink_copies') && !wpc_off_flag('off_gone_drop')) {
                if (wpc_page_address_differs($dir, (!empty($target['https']) ? 'https' : 'http') . '://' . (string) $target['host'] . (string) $target['path'])) {
                    wpc_fs_log('stale-alias-kept', substr((string) $target['path'], 0, 120), ['status' => (int) $gone[1]]);
                } else {
                    wpc_stale_unlink_copies($dir, (string) $prefix);
                    wpc_fs_log('stale-dropped', substr((string) $target['path'], 0, 120), ['status' => (int) $gone[1]]);
                }
            }
            return $sent['rung'] !== '';
        } catch (\Throwable $e) {
        }
        return false;
    }
}

if (!function_exists('wpc_hit_kick_reason')) {
    /*
     * A HIT ON A STALE-MARKED PAGE ASKS FOR ITS NEW CRIT, LIKE A RENDER OF IT DOES.
     *
     * Rule: a visitor served from a stored copy is a real visit (policy P-W5), so when the page's
     * crit is stale-marked the hit kicks, as a render of the page would (rewriteLogic's stale
     * kick). A stale-marked page still has its crit, so its copy carries no arm sentinel and its
     * visitors' browsers never ask; a file hit runs no render, and the stale re-warm that
     * re-stores the copy is a warm render, which never kicks. So nothing asked at all. Staging
     * 2026-09-24 07:02: a menu save stale-marked every page (crit-invalidate {mode:stale, dirs:65})
     * and soft-purged the HTML; every page was re-warmed and served as a hit from then on, and
     * the only page that regenerated was /secret-deal/, which a visitor happened to render.
     *
     * A crit-less page is not asked for here: its local-only copy carries the arm sentinel, whose
     * beacon asks from every visitor's browser (3 s after load or on the first interaction), and
     * the rig replay of that beacon on a stranded page landed its crit in 20 s. Only a client that
     * runs no script (curl, most bots) never asks for it, by design (DEVELOPING.md section 4).
     *
     * Both readers call wpc_hit_kick() after they have found the copy they serve: the
     * advanced-cache drop-in (before WordPress: an unsigned loopback after the response, counted
     * by the receiver's throttle) and the plugin's own serve (through wpc_repull_kick_now(),
     * counted at the fire side). One kick per page per 120 s, the receiver's own dedupe window;
     * everything else (the per-minute throttle, the 45 s spacing, the service hold, the dead mark)
     * is the kick's own admission, unchanged.
     */

    /**
     * The one hit-kick entry point for both readers. With WordPress loaded (the plugin's own
     * serve) the kick goes through the render's kick door now; before WordPress (the drop-in) it
     * is one loopback to the kick receiver after the response.
     */
    function wpc_hit_kick($urlKey)
    {
        try {
            $urlKey = (string) $urlKey;
            // Only a GET is a visit (see wpc_serve_stale_copy: a Varnish `PURGE /.*` lands on the
            // homepage's key and would otherwise count as a hit on it).
            if (!wpc_loopback_request_is_get()) {
                if (wpc_hit_kick_reason($urlKey) !== '') {
                    wpc_loopback_nonget_receipt('hit-kick', $urlKey);
                }
                return;
            }
            if (function_exists('wpc_lever_test_active') && wpc_lever_test_active()) {
                return;
            }
            $reason = wpc_hit_kick_reason($urlKey);
            if ($reason === '') {
                return;
            }
            $inWordPress = function_exists('wpc_repull_kick_now');
            if (!wpc_hit_kick_claim($urlKey, $reason, $inWordPress ? 'reader' : 'dropin')) {
                return;
            }
            if ($inWordPress) {
                wpc_repull_kick_now($urlKey, false, 'hit');
            } else {
                wpc_hit_kick_after_response($urlKey);
            }
        } catch (\Throwable $e) {
        }
    }

    /** The page's critical folder, from the drop-in too (which runs before defines.php). */
    function wpc_hit_kick_crit_dir($urlKey)
    {
        $urlKey = (string) $urlKey;
        if ($urlKey === '' || $urlKey !== basename($urlKey) || $urlKey === '.' || $urlKey === '..') {
            return '';
        }
        $root = defined('WPS_IC_CRITICAL') ? WPS_IC_CRITICAL : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/critical/' : '');
        return $root === '' ? '' : rtrim($root, '/') . '/' . $urlKey . '/';
    }

    /**
     * Why the page a hit was served from should ask for its crit: 'stale' when the page's crit
     * folder holds a stale mark (it stands until a land clears it), '' otherwise. One stat.
     */
    function wpc_hit_kick_reason($urlKey)
    {
        $critDir = wpc_hit_kick_crit_dir($urlKey);
        return ($critDir !== '' && @is_file($critDir . 'stale.txt')) ? 'stale' : '';
    }

    /**
     * Claim the page's hit kick: true at most once per 120 s per page, stamped in a lock file
     * beside the kick's own locks, with the receipt `hit-kick {why, via}`.
     */
    function wpc_hit_kick_claim($urlKey, $reason, $via)
    {
        $critDir = wpc_hit_kick_crit_dir($urlKey);
        if ($critDir === '') {
            return false;
        }
        $lockDir = dirname(rtrim($critDir, '/')) . '/.kicklocks/';
        $lock = $lockDir . 'h_' . md5((string) $urlKey) . '.lock';
        if (@is_file($lock) && (time() - (int) @filemtime($lock)) < 120) {
            return false;
        }
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        if (!@touch($lock)) {
            return false;
        }
        wpc_fs_log('hit-kick', $urlKey, ['why' => (string) $reason, 'via' => (string) $via]);
        return true;
    }

    /**
     * The drop-in's hit kick, registered for shutdown: the receiver's URL under the WordPress
     * folder (wpc_loopback_install_url(), so a subdirectory install keeps its prefix), or nothing
     * when that URL is not one a request line may carry.
     */
    function wpc_hit_kick_after_response($urlKey)
    {
        $target = wpc_loopback_target(wpc_loopback_install_url('wp-admin/admin-ajax.php')
            . '?action=wpc_repull_kick&k=' . rawurlencode((string) $urlKey) . '&src=hit');
        if ($target === []) {
            return;
        }
        register_shutdown_function('wpc_hit_kick_send', $target, (string) $urlKey);
    }

    /**
     * The drop-in's transport: after the response, the same receiver request a render's kick
     * sends (admin-ajax.php?action=wpc_repull_kick), named src=hit and unsigned. The drop-in
     * cannot compute the admission token (it needs wp_salt()) and took no admission on the fire
     * side, so the receiver counts it against the per-minute throttle, as it counts a beacon.
     */
    function wpc_hit_kick_send(array $target, $urlKey)
    {
        try {
            $sent = wpc_loopback_send($target, ['User-Agent' => 'WPCompress-HitKick', 'X-WPC-Cache-Warm' => wpc_warm_header_for_target($target)]);
            if ($sent['rung'] === '') {
                wpc_fs_log('hit-kick-unsent', $urlKey, $sent);
            }
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_wp_config_path')) {
    /**
     * The wp-config.php WordPress itself loads, by core's own rule in wp-load.php: ABSPATH first,
     * then the directory above ABSPATH when that directory is not another WordPress install (no
     * wp-settings.php there). '' when neither holds. Every reader and writer of the WP_CACHE line
     * goes through this one resolver: looking only in ABSPATH left the page cache off on
     * greenvalleytint.com (2026-09-22/24, "only the home URL caches"), whose wp-config.php sits
     * one directory above the web root, while the drop-in was installed and nothing said so.
     */
    function wpc_wp_config_path()
    {
        if (!defined('ABSPATH')) {
            return '';
        }
        $inWordPressRoot = ABSPATH . 'wp-config.php';
        if (@file_exists($inWordPressRoot)) {
            return $inWordPressRoot;
        }
        $parentDirectory = dirname(ABSPATH);
        if (@file_exists($parentDirectory . '/wp-config.php') && !@file_exists($parentDirectory . '/wp-settings.php')) {
            return $parentDirectory . '/wp-config.php';
        }
        return '';
    }
}

if (!function_exists('wpc_request_body_head')) {
    /** The first $max bytes of this request's body; '' when it has none or it cannot be read. */
    function wpc_request_body_head($max)
    {
        return (string) @file_get_contents('php://input', false, null, 0, max(0, (int) $max));
    }
}
