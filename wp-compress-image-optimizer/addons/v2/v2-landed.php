<?php
/**
 * The landed receipt: what is on this site's disk for a variant the image service delivered.
 *
 * Every lane that lands image bytes reports here after the file is on disk, with the facts of the
 * file as it stands (its SHA-256, size and mtime read back from disk, never the hash the service
 * declared). The receipts queue in a file spool under uploads/wpci-journal/landed/ and go to the
 * service in batches:
 *
 *   wpc_v2_landed_receipt($id, $size, $fmt, $dest, $lane, $job)      a file that is on disk
 *   wpc_v2_landed_failure($id, $size, $fmt, $dest, $lane, $job, $e)  a store write that failed
 *   wpc_v2_landed_from_store($variant, $dest, $error)                the store's one call
 *   wpc_v2_landed_flush()                                            send the spool, retry later
 *
 * Loaded by v2-store.php, so the direct-entry files under SHORTINIT (api/v2/) have it too: nothing
 * here may need a WordPress function outside the options, plugin and formatting APIs without a
 * function_exists() guard, and the sender falls back to raw curl where wp_remote_post is absent.
 * Nothing here ever throws or fails a landing: a receipt that cannot be queued is dropped.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (@is_file(__DIR__ . '/v2-service.php') && @is_readable(__DIR__ . '/v2-service.php')) {
    require_once __DIR__ . '/v2-service.php';
}

if (!function_exists('wpc_v2_landed_limits')) {

    function wpc_v2_landed_limits()
    {
        $limits = [
            'batch_entries' => 100,
            'batch_bytes'   => 204800,
            'spool_bytes'   => 1048576,
            'max_age'       => 259200,
            'posts'         => 3,
            'seconds'       => 8,
            'gap'           => 10,
            'backoff_base'  => 60,
            'backoff_max'   => 3600,
        ];
        if (function_exists('apply_filters')) {
            $given = apply_filters('wpc_v2_landed_limits', $limits);
            if (is_array($given)) {
                $limits = $given + $limits;
            }
        }
        return $limits;
    }

    function wpc_v2_landed_note($event, array $fields)
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log((string) $event, '', '', $fields);
        }
    }

    function wpc_v2_landed_dir($create = true)
    {
        if (!function_exists('wpc_v2_journal_root')) {
            return '';
        }
        $root = wpc_v2_journal_root($create);
        if ($root === '') {
            return '';
        }
        $dir = $root . '/landed';
        if (!is_dir($dir)) {
            if (!$create || (!wpc_v2_journal_mkdir($dir) && !is_dir($dir))) {
                return '';
            }
        }
        if ($create) {
            wpc_v2_journal_guard($dir);
        }
        return is_writable($dir) ? $dir : '';
    }

    /**
     * Whether the service takes receipts: its /v2/config answer carried `landed_receipts: 1`, which
     * wpc_v2_landed_ingest() recorded as the file `enabled` in the spool's directory. The file, not
     * the options table, is what every sender reads, so the SHORTINIT lane asks the same question
     * the full-WordPress lanes do. Off until the service says otherwise.
     */
    function wpc_v2_landed_enabled()
    {
        $dir = wpc_v2_landed_dir(false);
        return $dir !== '' && @is_file($dir . '/enabled');
    }

    /** True while the service takes receipts, some wait in the spool and the site has a key to send them with: a sender has work. */
    function wpc_v2_landed_pending()
    {
        $dir = wpc_v2_landed_dir(false);
        if ($dir === '' || !@is_file($dir . '/enabled')) {
            return false;
        }
        clearstatcache(true, $dir . '/spool.jsonl');
        if (!@is_file($dir . '/spool.jsonl') || (int) @filesize($dir . '/spool.jsonl') === 0) {
            return false;
        }
        return wpc_v2_landed_target() !== null;
    }

    /** A receipt was queued: when the service takes them, makes sure the 5-minute event exists to send them even where the response cannot be released. */
    function wpc_v2_landed_wake()
    {
        if (function_exists('wpc_v2_pull_cron_ensure') && wpc_v2_landed_pending()) {
            wpc_v2_pull_cron_ensure();
        }
    }

    /** Turns the flag on or off (the file `enabled`) and journals `landed-receipts {state}` when the answer to "does the service take receipts" changed. */
    function wpc_v2_landed_set_enabled($on)
    {
        $was = wpc_v2_landed_enabled();
        if ($on) {
            $dir = wpc_v2_landed_dir(true);
            if ($dir !== '') {
                wpc_fs_put($dir . '/enabled', "1\n");
            }
        } else {
            $dir = wpc_v2_landed_dir(false);
            if ($dir !== '') {
                @unlink($dir . '/enabled');
            }
        }
        $now = wpc_v2_landed_enabled();
        if ($was !== $now) {
            wpc_v2_landed_note('landed-receipts', ['state' => $now ? 'on' : 'off']);
        } elseif ($on && !$now) {
            wpc_v2_landed_note('landed-receipts', ['state' => 'unwritable']);
        }
        return $now;
    }

    /**
     * Reads `landed_receipts` from a /v2/config answer (the key at the top of the answer, else in its
     * policy object or a zone's): the integer 1 or the string "1" turns the flag on, the integer 0,
     * the string "0" or false turns it off. A key that is there with any other value (true, 1.0,
     * "yes", null, an array) is a value this plugin does not know how to read: the flag is left as
     * it was and `landed-receipts {state: unrecognised, type, value}` is journalled. An answer that
     * has the key at none of the three places says nothing about receipts (a 202 `{queued,
     * status_url}` is one): the flag is left as it was, as the other fields of this answer are. Not an
     * answer (not an array): left alone.
     */
    function wpc_v2_landed_ingest($rbody)
    {
        try {
            if (!is_array($rbody)) {
                return null;
            }
            $value = null;
            $found = false;
            if (array_key_exists('landed_receipts', $rbody)) {
                $value = $rbody['landed_receipts'];
                $found = true;
            } elseif (isset($rbody['policy']) && is_array($rbody['policy']) && array_key_exists('landed_receipts', $rbody['policy'])) {
                $value = $rbody['policy']['landed_receipts'];
                $found = true;
            } elseif (!empty($rbody['zones']) && is_array($rbody['zones'])) {
                foreach ($rbody['zones'] as $zone) {
                    if (is_array($zone) && isset($zone['policy']) && is_array($zone['policy']) && array_key_exists('landed_receipts', $zone['policy'])) {
                        $value = $zone['policy']['landed_receipts'];
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                return null;
            }
            if ($value === 1 || $value === '1') {
                return wpc_v2_landed_set_enabled(true);
            }
            if ($value === 0 || $value === '0' || $value === false) {
                return wpc_v2_landed_set_enabled(false);
            }
            $shown = is_scalar($value) ? substr((string) json_encode($value), 0, 24) : '';
            wpc_v2_landed_note('landed-receipts', ['state' => 'unrecognised', 'type' => gettype($value), 'value' => $shown]);
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    function wpc_v2_landed_entry($imageID, $sizeLabel, $format, $path, $sha, $bytes, $writtenAt, $lane, $jobId, $error)
    {
        $entry = [
            'imageID'     => wpc_v2_service_image_id($imageID),
            'sizeLabel'   => (string) $sizeLabel,
            'format'      => strtolower((string) $format),
            'path'        => (string) $path,
            'sha256_disk' => (string) $sha,
            'bytes'       => (int) $bytes,
            'written_at'  => (int) $writtenAt,
            'lane'        => (string) $lane,
        ];
        if ((string) $jobId !== '') {
            $entry['jobId'] = (string) $jobId;
        }
        $entry['result'] = (string) $error === '' ? 'written' : 'failed';
        $entry['error']  = (string) $error;
        return $entry;
    }

    /**
     * Appends one entry to the spool under the file's lock. A spool past its byte bound loses its
     * oldest entries first, and one `landed-dropped {reason:spool-full, n}` receipt counts them.
     */
    function wpc_v2_landed_append(array $entry)
    {
        $dir = wpc_v2_landed_dir();
        if ($dir === '') {
            return wpc_v2_landed_unwritable();
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($line) || $line === '') {
            return false;
        }
        $limits = wpc_v2_landed_limits();
        $fh = @fopen($dir . '/spool.jsonl', 'c+');
        if (!$fh) {
            return wpc_v2_landed_unwritable();
        }
        if (!@flock($fh, LOCK_EX)) {
            @fclose($fh);
            return wpc_v2_landed_unwritable();
        }
        $dropped = 0;
        $stat = @fstat($fh);
        $size = is_array($stat) ? (int) $stat['size'] : 0;
        if ($size + strlen($line) + 1 > (int) $limits['spool_bytes']) {
            @rewind($fh);
            $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($fh)), 'strlen'));
            $total = 0;
            foreach ($lines as $held) {
                $total += strlen($held) + 1;
            }
            $room = (int) ($limits['spool_bytes'] * 0.75);
            while (!empty($lines) && $total + strlen($line) + 1 > $room) {
                $total -= strlen(array_shift($lines)) + 1;
                $dropped++;
            }
            @ftruncate($fh, 0);
            @rewind($fh);
            if (!empty($lines)) {
                @fwrite($fh, implode("\n", $lines) . "\n");
            }
        }
        @fseek($fh, 0, SEEK_END);
        $written = @fwrite($fh, $line . "\n");
        @fflush($fh);
        @flock($fh, LOCK_UN);
        @fclose($fh);
        if ($dropped > 0) {
            wpc_v2_landed_note('landed-dropped', ['reason' => 'spool-full', 'n' => $dropped]);
        }
        return $written !== false ? true : wpc_v2_landed_unwritable();
    }

    /** A receipt could not be put in the spool (no directory, the file refused to open, lock or write): one `landed-dropped {reason: spool-unwritable}` per request. Answers false. */
    function wpc_v2_landed_unwritable()
    {
        if (empty($GLOBALS['wpc_v2_landed_unwritable_noted'])) {
            $GLOBALS['wpc_v2_landed_unwritable_noted'] = true;
            wpc_v2_landed_note('landed-dropped', ['reason' => 'spool-unwritable', 'n' => 1]);
        }
        return false;
    }

    function wpc_v2_landed_queue(array $entry)
    {
        $queued = wpc_v2_landed_append($entry);
        if ($queued) {
            wpc_v2_landed_arm();
            wpc_v2_landed_wake();
        }
        return $queued;
    }

    /**
     * A file older than the service takes receipts for (its mtime is past the age limit) is not hashed
     * or spooled: the flush would drop it as aged. Counted, and journalled once per request as
     * `landed-dropped {reason: too-old-at-queue, n}` when the request ends. Answers false.
     */
    function wpc_v2_landed_too_old()
    {
        if (empty($GLOBALS['wpc_v2_landed_too_old_n'])) {
            $GLOBALS['wpc_v2_landed_too_old_n'] = 0;
            register_shutdown_function('wpc_v2_landed_too_old_report');
        }
        $GLOBALS['wpc_v2_landed_too_old_n']++;
        return false;
    }

    function wpc_v2_landed_too_old_report()
    {
        $n = (int) ($GLOBALS['wpc_v2_landed_too_old_n'] ?? 0);
        $GLOBALS['wpc_v2_landed_too_old_n'] = 0;
        if ($n > 0) {
            wpc_v2_landed_note('landed-dropped', ['reason' => 'too-old-at-queue', 'n' => $n]);
        }
    }

    /** Queues a landed twin whose asking pages are purged by a later request (a direct-entry lane, or a landing past the per-request cap): wpc_landed_pages_drain() takes it. */
    function wpc_v2_landed_pages_defer($abs_path)
    {
        $abs_path = (string) $abs_path;
        $ext = strtolower((string) pathinfo($abs_path, PATHINFO_EXTENSION));
        if (($ext !== 'avif' && $ext !== 'webp') || !function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        return wpc_v2_landed_pages_hold($abs_path);
    }

    /** Queues one page's purge ("post:<id>:hard|soft") for wpc_landed_pages_drain(): the pages of an image past this request's page budget. */
    function wpc_v2_landed_pages_defer_post($post_id, $mode = null)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        return wpc_v2_landed_pages_hold('post:' . $post_id . ':' . ($mode === 'hard' ? 'hard' : 'soft'));
    }

    /** Holds one key in wpc_landed_pages_pending and arms the one event that drains it; past 1000 entries the oldest soft and plain-path keys go first, a hard key is never dropped, and the drops are counted in a note. */
    function wpc_v2_landed_pages_hold($key)
    {
        $held = get_option('wpc_landed_pages_pending', []);
        $held = is_array($held) ? $held : [];
        $held[(string) $key] = time();
        $dropped = 0;
        if (count($held) > 1000) {
            foreach (array_keys($held) as $name) {
                if (count($held) <= 1000) {
                    break;
                }
                if (substr((string) $name, -5) !== ':hard') {
                    unset($held[$name]);
                    $dropped++;
                }
            }
        }
        update_option('wpc_landed_pages_pending', $held, false);
        if ($dropped > 0) {
            wpc_v2_landed_note('landed-dropped', ['reason' => 'pending-cap', 'n' => $dropped]);
        }
        wpc_v2_landed_pages_arm_drain();
        return true;
    }

    /** Schedules the single event that runs wpc_landed_pages_drain() when none is pending. */
    function wpc_v2_landed_pages_arm_drain($delay = 60)
    {
        if (!defined('WPC_LANDED_PAGES_DRAIN_HOOK') || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event') || wp_next_scheduled(WPC_LANDED_PAGES_DRAIN_HOOK)) {
            return false;
        }
        wp_schedule_single_event(time() + (int) $delay, WPC_LANDED_PAGES_DRAIN_HOOK);
        return true;
    }

    /**
     * A file the service delivered is on disk at $dest: queues its receipt from the file as it
     * stands (hash, size and mtime read back now). Nothing is queued for a path outside the uploads
     * directory, a file that cannot be read, a file last written more than the age limit ago
     * (three days: see wpc_v2_landed_too_old()), or an entry that carries no image id (the policy
     * store's materialize lane builds its manifest entries without one).
     */
    function wpc_v2_landed_receipt($imageID, $sizeLabel, $format, $dest, $lane, $jobId = '')
    {
        try {
            $dest = (string) $dest;
            if (!@is_file($dest)) {
                return false;
            }
            if (function_exists('wpc_sized_ledger_landed')) {
                wpc_sized_ledger_landed($dest);
            }
            $rel = function_exists('wpc_v2_uploads_relative') ? wpc_v2_uploads_relative($dest) : '';
            if ((string) $imageID === '' || (string) $imageID === '0' || $rel === '') {
                return false;
            }
            if (function_exists('wpc_v2_landed_pages_purge')) {
                wpc_v2_landed_pages_purge($dest);
            } else {
                wpc_v2_landed_pages_defer($dest);
            }
            clearstatcache(true, $dest);
            $at = @filemtime($dest);
            if ($at === false) {
                return false;
            }
            if ((int) $at < time() - (int) wpc_v2_landed_limits()['max_age']) {
                return wpc_v2_landed_too_old();
            }
            $sha = @hash_file('sha256', $dest);
            $bytes = @filesize($dest);
            if ($sha === false || $bytes === false) {
                return false;
            }
            return wpc_v2_landed_queue(wpc_v2_landed_entry($imageID, $sizeLabel, $format, $rel, $sha, $bytes, $at, $lane, $jobId, ''));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The store could not write the file ($error is its error code): queues the failed receipt. */
    function wpc_v2_landed_failure($imageID, $sizeLabel, $format, $dest, $lane, $jobId, $error)
    {
        try {
            $rel = function_exists('wpc_v2_uploads_relative') ? wpc_v2_uploads_relative((string) $dest) : '';
            if ((string) $imageID === '' || (string) $imageID === '0' || $rel === '' || (string) $error === '') {
                return false;
            }
            return wpc_v2_landed_queue(wpc_v2_landed_entry($imageID, $sizeLabel, $format, $rel, '', 0, time(), $lane, $jobId, $error));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The one call wpc_v2_store_bytes() makes after an attempt to place a variant: the variant's
     * identity is $variant (id, size, fmt, src, job); $error is '' for a file that landed and the
     * store's error code for one that could not be written.
     */
    function wpc_v2_landed_from_store(array $variant, $dest, $error = '')
    {
        $imageID = (int) ($variant['id'] ?? 0);
        $size = (string) ($variant['size'] ?? '');
        $format = (string) ($variant['fmt'] ?? '');
        if ($imageID <= 0 || $size === '' || $format === '') {
            return false;
        }
        $lane = (string) ($variant['src'] ?? '');
        $job = (string) ($variant['job'] ?? '');
        return (string) $error === ''
            ? wpc_v2_landed_receipt($imageID, $size, $format, $dest, $lane, $job)
            : wpc_v2_landed_failure($imageID, $size, $format, $dest, $lane, $job, $error);
    }

    function wpc_v2_landed_arm()
    {
        static $armed = false;
        if ($armed) {
            return;
        }
        $armed = true;
        register_shutdown_function('wpc_v2_landed_shutdown');
    }

    /** True when the request in flight is an administrator's page (not an ajax call, cron or the CLI). */
    function wpc_v2_landed_page_response()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        return function_exists('is_admin') && is_admin();
    }

    /**
     * True when no client waits for this process: WP-CLI, a cron run, or a request whose response
     * is already released. It releases nothing itself, so it is safe to ask in the middle of a
     * request that still has an answer to give.
     */
    function wpc_v2_landed_detached()
    {
        return (defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON) || !empty($GLOBALS['wpc_response_released']);
    }

    /** True when no client waits for this process any more: detached, or released now (shutdown only: this finishes the response). */
    function wpc_v2_landed_released()
    {
        if (wpc_v2_landed_detached()) {
            return true;
        }
        if (function_exists('wpc_finish_request')) {
            return (bool) wpc_finish_request();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return true;
        }
        return false;
    }

    function wpc_v2_landed_shutdown()
    {
        try {
            if (wpc_v2_landed_page_response() || !wpc_v2_landed_released()) {
                return;
            }
            @ignore_user_abort(true);
            wpc_v2_landed_flush(true);
        } catch (\Throwable $e) {
        }
    }

    function wpc_v2_landed_target()
    {
        $apikey = function_exists('wpc_v2_get_apikey')
            ? (string) wpc_v2_get_apikey()
            : (function_exists('wpc_v2_read_apikey') ? (string) wpc_v2_read_apikey() : '');
        $orch = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch === '') {
            return null;
        }
        return ['url' => rtrim($orch, '/') . '/optimize-v2/landed?apikey=' . rawurlencode($apikey), 'apikey' => $apikey];
    }

    /** WordPress's bundled CA file when it is there, else '' (curl then uses the system store). */
    function wpc_v2_landed_ca_bundle()
    {
        $bundle = defined('ABSPATH') && defined('WPINC') ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';
        return ($bundle !== '' && is_readable($bundle)) ? $bundle : '';
    }

    /** The curl options of the raw sender. */
    function wpc_v2_landed_curl_options(array $headers, $body_raw)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body_raw,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        $bundle = wpc_v2_landed_ca_bundle();
        if ($bundle !== '') {
            $options[CURLOPT_CAINFO] = $bundle;
        }
        return $options;
    }

    /** True when the sender is raw curl: SHORTINIT, or WordPress's HTTP API is not loaded. */
    function wpc_v2_landed_raw_lane()
    {
        return (defined('SHORTINIT') && SHORTINIT) || !function_exists('wp_remote_post');
    }

    /**
     * POSTs $body_raw (signed as the bytes it is) and answers the HTTP status, 0 when nothing
     * answered. wp_remote_post in a full WordPress request; raw curl under SHORTINIT, where
     * WordPress's HTTP API is not loaded.
     */
    function wpc_v2_landed_post(array $target, $body_raw)
    {
        $headers = ['Content-Type' => 'application/json'] + wpc_v2_service_sign($target['apikey'], $body_raw);
        if (defined('WPC_PLUGIN_VERSION')) {
            $headers['X-WPC-Plugin-Version'] = (string) WPC_PLUGIN_VERSION;
        }
        if (wpc_v2_landed_raw_lane()) {
            return wpc_v2_landed_post_raw($target['url'], $headers, $body_raw);
        }
        $resp = wp_remote_post($target['url'], ['timeout' => 4, 'redirection' => 0, 'headers' => $headers, 'body' => $body_raw]);
        return is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
    }

    /** The spool's lines, read under a shared lock. */
    function wpc_v2_landed_read($spool)
    {
        $fh = @fopen($spool, 'r');
        if (!$fh) {
            return [];
        }
        @flock($fh, LOCK_SH);
        $all = (string) stream_get_contents($fh);
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return array_values(array_filter(explode("\n", $all), 'strlen'));
    }

    /** Removes from the spool every line whose md5 is a key of $gone; lines appended meanwhile stay. */
    function wpc_v2_landed_remove($spool, array $gone)
    {
        if (empty($gone)) {
            return;
        }
        $fh = @fopen($spool, 'c+');
        if (!$fh) {
            return;
        }
        if (@flock($fh, LOCK_EX)) {
            $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($fh)), 'strlen'));
            $kept = [];
            foreach ($lines as $held) {
                if (!isset($gone[md5($held)])) {
                    $kept[] = $held;
                }
            }
            @ftruncate($fh, 0);
            @rewind($fh);
            if (!empty($kept)) {
                @fwrite($fh, implode("\n", $kept) . "\n");
            }
            @fflush($fh);
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
    }

    /**
     * Sends the spool in batches to POST /optimize-v2/landed. Answers ['sent', 'posts', 'left',
     * 'skipped']. Nothing is sent, and no wait is started or changed, until the service has said it
     * takes receipts (wpc_v2_landed_enabled(); skipped `off`): the spool fills as ever, within its
     * bound, and goes out when the flag turns on. A flush asked for by a request's shutdown ($coalesce) waits until the last POST
     * is ten seconds old, so a burst of callbacks leaves one batch, not one POST each; the
     * drain and the 5-minute tick always send. At most one flush runs at a time. A POST that gets no
     * answer or a 5xx, 408 or 429 keeps its batch and waits (60 s doubling to an hour, a state file
     * per sender so a local curl failure never holds the other); any other 4xx is the service
     * refusing that batch for good: it is dropped and counted (`rejected`) and the next batch goes,
     * after a 413 split in two once. An entry older than three days is dropped
     * (the service takes none later), and so is a written receipt whose file has been removed since
     * (a restore, a purge) or no longer has the size and mtime it was queued with (`file-changed`: a
     * newer write queued its own receipt): the service is never told a variant is landed that is not.
     * The same pass runs on a site with no key, which sends nothing (`no-target`), so its spool ages
     * out. Entries leave the spool only once a 2xx has taken them.
     */
    function wpc_v2_landed_flush($coalesce = false)
    {
        $out = ['sent' => 0, 'posts' => 0, 'left' => 0, 'skipped' => ''];
        try {
            $dir = wpc_v2_landed_dir(false);
            if ($dir !== '' && !@is_file($dir . '/enabled')) {
                $out['skipped'] = 'off';
                return $out;
            }
            $spool = $dir === '' ? '' : $dir . '/spool.jsonl';
            if ($spool !== '') {
                clearstatcache(true, $spool);
            }
            if ($spool === '' || !@is_file($spool) || (int) @filesize($spool) === 0) {
                $out['skipped'] = 'empty';
                return $out;
            }
            $now = time();
            if ($coalesce) {
                $last = (int) @filemtime($dir . '/flush.at');
                if ($last > 0 && $now - $last < (int) wpc_v2_landed_limits()['gap']) {
                    $out['skipped'] = 'recent';
                    return $out;
                }
            }
            $state_file = $dir . '/state-' . (wpc_v2_landed_raw_lane() ? 'raw' : 'wp') . '.json';
            $state = json_decode((string) @file_get_contents($state_file), true);
            $state = is_array($state) ? $state : [];
            if ((int) ($state['until'] ?? 0) > $now) {
                $out['skipped'] = 'backoff';
                return $out;
            }
            $target = wpc_v2_landed_target();
            $lock = @fopen($dir . '/flush.lock', 'c');
            if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
                if ($lock) {
                    @fclose($lock);
                }
                $out['skipped'] = 'busy';
                return $out;
            }
            try {
                $limits = wpc_v2_landed_limits();
                $gone = [];
                $aged = 0;
                $corrupt = 0;
                $vanished = 0;
                $changed = 0;
                $fresh = [];
                $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
                $base = isset($up['basedir']) ? rtrim((string) $up['basedir'], '/\\') : '';
                foreach (wpc_v2_landed_read($spool) as $held) {
                    $hash = md5($held);
                    $entry = json_decode($held, true);
                    if (!is_array($entry) || !isset($entry['written_at'])) {
                        $gone[$hash] = true;
                        $corrupt++;
                    } elseif ((int) $entry['written_at'] < $now - (int) $limits['max_age']) {
                        $gone[$hash] = true;
                        $aged++;
                    } elseif (($entry['result'] ?? '') === 'written' && $base !== '') {
                        $file = $base . '/' . ltrim((string) ($entry['path'] ?? ''), '/');
                        clearstatcache(true, $file);
                        if (!@is_file($file)) {
                            $gone[$hash] = true;
                            $vanished++;
                        } elseif ((int) @filesize($file) !== (int) ($entry['bytes'] ?? -1) || (int) @filemtime($file) !== (int) $entry['written_at']) {
                            $gone[$hash] = true;
                            $changed++;
                        } else {
                            $fresh[$hash] = [$entry, strlen($held)];
                        }
                    } else {
                        $fresh[$hash] = [$entry, strlen($held)];
                    }
                }
                $note_drops = function () use (&$aged, &$corrupt, &$vanished, &$changed) {
                    if ($aged > 0) {
                        wpc_v2_landed_note('landed-dropped', ['reason' => 'aged', 'n' => $aged]);
                    }
                    if ($corrupt > 0) {
                        wpc_v2_landed_note('landed-dropped', ['reason' => 'unreadable', 'n' => $corrupt]);
                    }
                    if ($vanished > 0) {
                        wpc_v2_landed_note('landed-dropped', ['reason' => 'file-gone', 'n' => $vanished]);
                    }
                    if ($changed > 0) {
                        wpc_v2_landed_note('landed-dropped', ['reason' => 'file-changed', 'n' => $changed]);
                    }
                };
                if ($target === null) {
                    wpc_v2_landed_remove($spool, $gone);
                    $out['left'] = count(wpc_v2_landed_read($spool));
                    $note_drops();
                    $out['skipped'] = 'no-target';
                    return $out;
                }
                $batches = [];
                $batch = [];
                $batch_bytes = 0;
                foreach ($fresh as $hash => $pair) {
                    if (!empty($batch) && (count($batch) >= (int) $limits['batch_entries'] || $batch_bytes + $pair[1] > (int) $limits['batch_bytes'])) {
                        $batches[] = $batch;
                        $batch = [];
                        $batch_bytes = 0;
                    }
                    $batch[$hash] = $pair[0];
                    $batch_bytes += $pair[1];
                }
                if (!empty($batch)) {
                    $batches[] = $batch;
                }
                $started = microtime(true);
                $failed_http = null;
                $rejected = [];
                $queue = [];
                foreach ($batches as $members) {
                    $queue[] = [$members, false];
                }
                while (!empty($queue)) {
                    if ($out['posts'] >= (int) $limits['posts'] || (microtime(true) - $started) > (int) $limits['seconds']) {
                        break;
                    }
                    list($members, $halved) = array_shift($queue);
                    $body_raw = function_exists('wp_json_encode') ? wp_json_encode(['receipts' => array_values($members)]) : json_encode(['receipts' => array_values($members)]);
                    if (!is_string($body_raw) || $body_raw === '') {
                        foreach ($members as $hash => $unused) {
                            $gone[$hash] = true;
                            $corrupt++;
                        }
                        continue;
                    }
                    $code = wpc_v2_landed_post($target, $body_raw);
                    $out['posts']++;
                    @touch($dir . '/flush.at');
                    if ($code >= 200 && $code < 300) {
                        foreach ($members as $hash => $unused) {
                            $gone[$hash] = true;
                        }
                        $out['sent'] += count($members);
                    } elseif ($code === 413 && !$halved && count($members) > 1) {
                        $halves = array_chunk($members, (int) ceil(count($members) / 2), true);
                        array_unshift($queue, [$halves[1], true]);
                        array_unshift($queue, [$halves[0], true]);
                    } elseif ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
                        foreach ($members as $hash => $unused) {
                            $gone[$hash] = true;
                        }
                        $rejected[$code] = (isset($rejected[$code]) ? $rejected[$code] : 0) + count($members);
                    } else {
                        $failed_http = $code;
                        break;
                    }
                }
                wpc_v2_landed_remove($spool, $gone);
                $out['left'] = count(wpc_v2_landed_read($spool));
                if ($failed_http !== null) {
                    $fails = (int) ($state['fails'] ?? 0) + 1;
                    $wait = min((int) $limits['backoff_max'], (int) $limits['backoff_base'] * (int) pow(2, min($fails - 1, 12)));
                    wpc_fs_put($state_file, json_encode(['fails' => $fails, 'until' => $now + $wait]));
                } elseif ($out['sent'] > 0) {
                    @unlink($state_file);
                }
                foreach ($rejected as $status => $n) {
                    wpc_v2_landed_note('landed-dropped', ['reason' => 'rejected', 'n' => $n, 'http' => (int) $status]);
                }
                $note_drops();
                if ($out['posts'] > 0) {
                    wpc_v2_landed_note('landed-flush', ['sent' => $out['sent'], 'posts' => $out['posts'], 'left' => $out['left'], 'ok' => $failed_http === null ? 1 : 0, 'http' => (int) $failed_http]);
                }
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        } catch (\Throwable $e) {
            $out['skipped'] = 'error';
        }
        return $out;
    }
}

if (!function_exists('wpc_v2_landed_post_raw')) {

    function wpc_v2_landed_post_raw($url, array $headers, $body_raw)
    {
        if (!function_exists('curl_init')) {
            return 0;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, wpc_v2_landed_curl_options($headers, $body_raw));
        @curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        return $code;
    }
}
