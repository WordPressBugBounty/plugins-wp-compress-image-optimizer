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

if (!function_exists('wpc_fs_is_plugin_root')) {
    /**
     * Whether $dir is one of the plugin's own root directories (the page cache, critical, combine,
     * fonts and preload folders). A host may have put one of them elsewhere behind a symbolic link,
     * so a delete handed one of these follows it; it never follows any other link.
     */
    function wpc_fs_is_plugin_root($dir)
    {
        $dir = rtrim((string) $dir, '/');
        $roots = [defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/wp-preload' : ''];
        foreach (['WPS_IC_CACHE', 'WPS_IC_CRITICAL', 'WPS_IC_COMBINE', 'WPS_IC_FONTS_DIR'] as $constant) {
            $roots[] = defined($constant) ? (string) constant($constant) : '';
        }
        foreach ($roots as $root) {
            if ($root !== '' && rtrim($root, '/') === $dir) {
                return true;
            }
        }
        return false;
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
     * false and `purge-link-refused` is journaled once per request. A .purging-* directory is
     * never entered at any depth. Stops between entries once
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
        foreach ((array) @scandir($dir) as $name) {
            $name = (string) $name;
            if ($name === '' || $name === '.' || $name === '..' || strpos($name, '.purging-') === 0 || (!$nested && $name[0] === '.')) {
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
            'links'      => $base . 'links.txt',
        ];
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
    function wpc_serve_stale_copy($dir, $prefix = '')
    {
        try {
            if (!wpc_loopback_request_is_get()) {
                wpc_loopback_nonget_receipt('rewarm', '');
                return false;
            }
            wpc_send_header('Cache-Control: no-cache, max-age=0, must-revalidate');
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
            register_shutdown_function('wpc_fire_stale_rewarm', $target, $userAgent, $accept);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The re-warm itself, after the response: the page's own URL, as the visitor's device. */
    function wpc_fire_stale_rewarm(array $target, $userAgent, $accept)
    {
        try {
            $sent = wpc_loopback_send($target, ['User-Agent' => $userAgent, 'Accept' => $accept, 'X-WPC-Cache-Warm' => '1']);
            wpc_fs_log('stale-fire', substr((string) $target['path'], 0, 120), $sent);
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
            $sent = wpc_loopback_send($target, ['User-Agent' => 'WPCompress-HitKick', 'X-WPC-Cache-Warm' => '1']);
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
