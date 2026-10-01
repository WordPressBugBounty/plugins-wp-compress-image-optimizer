<?php

/**
 * The image doctor: what the image lane knows about each image and about this site, read
 * without changing anything, so support can see why an image is not compressed.
 *
 *   images($opts)   one row per selected attachment: its files on disk, status, attempts, park,
 *                   backup, variants, journal, and a one-word verdict
 *   site()          plugin version, capabilities cache age, the upload and backup folders, PHP
 *                   limits, the orchestrator's /capabilities, and a connect probe of every host
 *                   a loopback to this site is tried against
 *   export($ids)    the image lane's own state for the ids (their meta, the options and lists
 *                   that name them) as one JSON document, to save before an install and diff after
 *
 * Reached three ways, admin or CLI only: `wp wpcompress image-doctor`, the admin-ajax action
 * `wpc_image_doctor` (manage_options + the plugin's nonce) and its `export=1` mode. Nothing here
 * writes an option, a meta row or a file; the only traffic is a GET of the orchestrator's
 * /capabilities and a connect (no request) to each loopback host. The apikey and every value
 * under a key that names a key, token, secret or password are redacted from what it answers.
 * Observed need (ticket 12006): a customer's bulk log showed only "backup failed" for 39 images,
 * and telling a missing file from a failed copy, a parked image from one in back-off, or a
 * refused loopback host from a working one took an afternoon of guessing over email.
 *
 * VERDICTS, the first that holds: `not-attachment` (the id is no attachment), `source-missing`
 * (neither the scaled nor the unscaled file is on disk), `in-flight` (a dispatch holds the lock
 * or the service owes an answer), `parked` (on the parked list, or at the attempt cap),
 * `backoff` (inside the retry wait after a failed attempt), `compressed`, `ok` (the lane has
 * nothing to do: excluded, or a type the service does not optimize), `not-compressed`.
 */
class wps_ic_image_doctor
{
    /** The post meta the image lane writes, read by name (a prefix scan adds the rest). */
    const OWNED_META = [
        'ic_status', 'ic_stats', 'ic_local_variants', 'ic_savings', 'ic_savings_format', 'ic_savings_bytes',
        'ic_savings_baseline', 'ic_compressing', 'ic_v2_attempts', 'wpc_backup_mode', 'wpc_backup_path',
        'wps_ic_exclude_live', 'wpc_source_missing_since', 'wpc_file_deleted_log',
    ];

    /** Meta key prefixes the lane owns; anything else on the attachment is WordPress's or another plugin's. */
    const OWNED_PREFIXES = ['ic_', 'wpc_', 'wps_ic', 'wpci_'];

    /** Keys whose values never leave the site. */
    const SECRET_KEY_PATTERN = '/(api_?key|apikey|token|secret|pass|auth|sig|nonce|cookie)/i';

    const DEFAULT_LIMIT = 200;

    // ─── the three doors ───────────────────────────────────────────────────────────────────

    /** admin-ajax `wpc_image_doctor`: POST/GET ids, parked, status, limit, site_only, export. */
    public static function ajax()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden_capability'], 403);
        }
        $nonce = isset($_REQUEST['wps_ic_nonce']) ? (string) $_REQUEST['wps_ic_nonce'] : (isset($_REQUEST['nonce']) ? (string) $_REQUEST['nonce'] : '');
        if (!wp_verify_nonce($nonce, 'wps_ic_nonce_action')) {
            wp_send_json_error(['error' => 'bad_nonce'], 403);
        }
        $opts = self::options_from($_REQUEST);
        if (!empty($opts['export'])) {
            wp_send_json_success(self::export(self::select_ids($opts)));
        }
        wp_send_json_success(self::report($opts));
    }

    /**
     * `wp wpcompress image-doctor [--ids=1,2] [--parked] [--status=<s>] [--limit=200]
     * [--format=table|json] [--site-only] [--export]`
     */
    public static function cli($args, $assoc)
    {
        $opts = self::options_from((array) $assoc);
        if (!empty($opts['export'])) {
            WP_CLI::line((string) wp_json_encode(self::export(self::select_ids($opts)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $report = self::report($opts);
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach (self::flatten($report['site']) as $k => $v) {
            WP_CLI::line(str_pad($k, 34) . ' ' . $v);
        }
        if (empty($opts['site_only'])) {
            $rows = [];
            foreach ($report['images'] as $r) {
                $rows[] = [
                    'id'       => $r['id'],
                    'verdict'  => $r['verdict'] . ($r['until'] > 0 ? ' until ' . gmdate('H:i', $r['until']) . 'Z' : ''),
                    'status'   => $r['ic_status'],
                    'file'     => $r['attached_file'],
                    'on_disk'  => implode(' ', array_map(function ($f) { return $f['role'] . ':' . ($f['bytes'] === null ? 'MISSING' : $f['bytes']); }, $r['files'])),
                    'attempts' => $r['attempts'] ? $r['attempts']['n'] . ' ' . $r['attempts']['reason'] : '',
                    'backup'   => $r['backup']['mode'] . ($r['backup']['exists'] === null ? '' : ($r['backup']['exists'] ? ' ok' : ' missing')),
                    'variants' => implode(' ', array_map(function ($f, $n) { return $f . ':' . $n; }, array_keys($r['variants']), $r['variants'])),
                    'journal'  => $r['journal_pending'],
                    'jobs'     => implode(' ', $r['job_ids']),
                ];
            }
            if ($rows) {
                \WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
            }
            WP_CLI::line('verdicts: ' . wp_json_encode($report['verdicts']));
        }
    }

    /** The site block plus, unless site_only, one row per selected image and the verdict tally. */
    public static function report(array $opts)
    {
        $out = ['site' => self::site()];
        if (empty($opts['site_only'])) {
            $out['images'] = self::images($opts);
            $tally = [];
            foreach ($out['images'] as $r) {
                $tally[$r['verdict']] = ($tally[$r['verdict']] ?? 0) + 1;
            }
            $out['verdicts'] = $tally;
        }
        return self::redact($out);
    }

    // ─── images ───────────────────────────────────────────────────────────────────────────

    /** @return array[] one row per selected id (see image()). */
    public static function images(array $opts)
    {
        $parked = self::parked_ids();
        $rows = [];
        foreach (self::select_ids($opts) as $id) {
            $rows[] = self::image($id, $parked);
        }
        return $rows;
    }

    public static function image($id, $parked = null)
    {
        $id = (int) $id;
        $parked = $parked === null ? self::parked_ids() : $parked;
        $source = wps_ic_image_library::source_state($id);
        $row = [
            'id'              => $id,
            'mime'            => '',
            'attached_file'   => '',
            'source'          => $source,
            'files'           => [],
            'ic_status'       => '',
            'attempts'        => null,
            'parked'          => in_array($id, $parked, true),
            'backup'          => ['mode' => '', 'path' => '', 'exists' => null],
            'variants'        => [],
            'journal_pending' => 0,
            'in_flight'       => false,
            'job_ids'         => [],
            'until'           => 0,
            'missing_since'   => 0,
            'deleted_by_plugin' => [],
            'excluded'        => false,
        ];
        if ($source === 'not-attachment') {
            $row['verdict'] = 'not-attachment';
            return $row;
        }
        $row['missing_since'] = (int) get_post_meta($id, wps_ic_image_library::MISSING_SINCE, true);
        $deleted = get_post_meta($id, wps_ic_image_library::DELETED_LOG, true);
        $row['deleted_by_plugin'] = is_array($deleted) ? array_values($deleted) : [];
        $row['mime'] = (string) get_post_mime_type($id);
        $row['attached_file'] = (string) get_post_meta($id, '_wp_attached_file', true);
        $row['files'] = self::files($id);
        $row['ic_status'] = (string) get_post_meta($id, 'ic_status', true);
        $row['excluded'] = (string) get_post_meta($id, 'wps_ic_exclude_live', true) !== '';
        $row['attempts'] = self::attempts($id);
        $row['backup'] = self::backup($id);
        $row['variants'] = self::variants($id);
        $row['journal_pending'] = self::journal_count($id);
        $row['in_flight'] = self::in_flight($id);
        $row['job_ids'] = self::job_ids($id);
        $row['verdict'] = self::verdict($row);
        if (($row['verdict'] === 'backoff' || $row['verdict'] === 'parked') && is_array($row['attempts'])) {
            $row['until'] = (int) $row['attempts']['until'];
        }
        return $row;
    }

    private static function verdict(array $row)
    {
        if ($row['source'] === 'missing') {
            return 'source-missing';
        }
        if ($row['in_flight']) {
            return 'in-flight';
        }
        $a = $row['attempts'];
        if ($row['parked'] || ($a && $a['state'] === 'parked')) {
            return 'parked';
        }
        if ($a && $a['state'] === 'backoff') {
            return 'backoff';
        }
        $landed = array_sum($row['variants']) > 0;
        if ($row['ic_status'] === 'compressed' || $landed || get_post_meta($row['id'], 'ic_stats', true)) {
            return 'compressed';
        }
        $mimes = function_exists('wpc_optimizable_mimes') ? (array) wpc_optimizable_mimes() : ['image/jpeg', 'image/png', 'image/gif'];
        if ($row['excluded'] || !in_array($row['mime'], $mimes, true)) {
            return 'ok';
        }
        return 'not-compressed';
    }

    /** The unscaled original, the scaled file and every registered size: path under uploads and bytes (null = not on disk). */
    private static function files($id)
    {
        $scaled = (string) get_attached_file($id);
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        $files = [];
        if ($original !== '' && $original !== $scaled) {
            $files[] = self::file_row('original', $original);
        }
        if ($scaled !== '') {
            $files[] = self::file_row('scaled', $scaled);
        }
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes']) && $scaled !== '') {
            foreach ($meta['sizes'] as $size => $info) {
                if (!empty($info['file'])) {
                    $files[] = self::file_row((string) $size, dirname($scaled) . '/' . $info['file']);
                }
            }
        }
        return $files;
    }

    private static function file_row($role, $path)
    {
        $there = @is_file($path);
        return ['role' => $role, 'file' => self::under_uploads($path), 'bytes' => $there ? (int) @filesize($path) : null];
    }

    /** The attempt record with what the admission would answer now, computed here (the admission itself writes). */
    private static function attempts($id)
    {
        $a = get_post_meta($id, 'ic_v2_attempts', true);
        $n = is_array($a) ? (int) ($a['n'] ?? 0) : 0;
        if ($n <= 0) {
            return null;
        }
        $last = (int) ($a['last'] ?? 0);
        $state = 'admissible';
        $until = 0;
        if ((time() - $last) <= 7 * DAY_IN_SECONDS) {
            $cap = (int) apply_filters('wpc_v2_attempt_cap', 4);
            $spacing = apply_filters('wpc_v2_attempt_spacing', [600, 1800, 7200]);
            $wait = isset($spacing[$n - 1]) ? (int) $spacing[$n - 1] : 7200;
            if ($n >= $cap) {
                $state = 'parked';
                $until = $last + 7 * DAY_IN_SECONDS;
            } elseif ((time() - $last) < $wait) {
                $state = 'backoff';
                $until = $last + $wait;
            }
        }
        return ['n' => $n, 'reason' => (string) ($a['reason'] ?? ''), 'last' => $last, 'state' => $state, 'until' => $until];
    }

    public static function backup($id)
    {
        $mode = (string) get_post_meta($id, 'wpc_backup_mode', true);
        $path = (string) get_post_meta($id, 'wpc_backup_path', true);
        $exists = null;
        if ($path !== '' && $mode !== '' && $mode !== 'cloud' && $mode !== 'off') {
            $exists = @is_file(WP_CONTENT_DIR . '/wpc-backups/' . ltrim($path, '/'));
        }
        return ['mode' => $mode, 'path' => $path, 'exists' => $exists];
    }

    /** Landed variants (an entry with bytes) counted by format. */
    private static function variants($id)
    {
        $set = get_post_meta($id, 'ic_local_variants', true);
        $by = [];
        foreach (is_array($set) ? $set : [] as $key => $entry) {
            if (!is_array($entry) || empty($entry['size'])) {
                continue;
            }
            $format = !empty($entry['format']) ? (string) $entry['format'] : (string) substr(strrchr('-' . $key, '-'), 1);
            $by[$format] = ($by[$format] ?? 0) + 1;
        }
        ksort($by);
        return $by;
    }

    private static function journal_count($id)
    {
        // The journal's own directory helper creates the folder; asked only when it exists.
        if (!is_dir(self::uploads_base() . '/wpci-journal') || !function_exists('wpc_v2_journal_image_files')) {
            return 0;
        }
        return count(wpc_v2_journal_image_files($id));
    }

    /**
     * A job is really in flight: a dispatch holds the image's lock, or the service owes it
     * variants (the pending state). Rule: the "Optimizing" marker (ic_compressing) alone is not a
     * job; it is written before the request and outlives a refused one. Observed (ticket 12006,
     * finde-online.de): 17 images the service refused, waiting out their 10-minute back-off,
     * read `in-flight` on their marker.
     */
    private static function in_flight($id)
    {
        $held = (int) get_option('wpc_v2_inflight_' . $id, 0);
        if ($held > 0 && (time() - $held) <= 300) {
            return true;
        }
        $pending = get_transient('wpc_v2_pending_' . $id);
        return is_array($pending) && !empty($pending['pending']);
    }

    /**
     * Every service job id this site holds for the image: the pending state's, and those of the
     * callbacks waiting in the journal (named `<id>-<job>-<ms>-<rand>.jsonl` by the REST fast
     * path; `jobId` in each entry of the other layout). With one, the service can be asked
     * `GET /optimize-v2/status/{id}?jobId=…` while it keeps the job (10 minutes).
     */
    private static function job_ids($id)
    {
        $ids = [];
        $pending = get_transient('wpc_v2_pending_' . $id);
        if (is_array($pending) && !empty($pending['jobId'])) {
            $ids[] = (string) $pending['jobId'];
        }
        if (self::journal_count($id) > 0) {
            foreach (array_slice(wpc_v2_journal_image_files($id), 0, 20) as $file) {
                $name = basename((string) $file);
                if (preg_match('/^' . (int) $id . '-([A-Za-z0-9_]+)-\d+-/', $name, $m)) {
                    $ids[] = $m[1];
                    continue;
                }
                $head = (string) @file_get_contents($file, false, null, 0, 4096);
                if (preg_match('/"jobId"\s*:\s*"([A-Za-z0-9_-]+)"/', $head, $m)) {
                    $ids[] = $m[1];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    // ─── site ─────────────────────────────────────────────────────────────────────────────

    public static function site()
    {
        $base = self::uploads_base();
        $backups = WP_CONTENT_DIR . '/wpc-backups';
        $caps = get_site_transient(defined('WPC_V2_CAPS_CACHE_KEY') ? WPC_V2_CAPS_CACHE_KEY : 'wpc_v2_capabilities');
        $settings = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $unreliable = (int) get_option('wpc_v2_async_unreliable', 0);
        return [
            'plugin_version'   => defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '',
            'capabilities_age' => is_array($caps) && !empty($caps['probed_at']) ? time() - (int) $caps['probed_at'] : null,
            'uploads'          => ['path' => $base, 'writable' => $base !== '' && is_dir($base) && is_writable($base)],
            'backups'          => ['path' => $backups, 'exists' => is_dir($backups), 'writable' => is_dir($backups) ? is_writable($backups) : is_writable(WP_CONTENT_DIR)],
            'settings'         => [
                'backup'       => is_array($settings) ? (string) ($settings['backup'] ?? '') : '',
                'optimization' => is_array($settings) ? (string) ($settings['optimization'] ?? '') : '',
                'live-cdn'     => is_array($settings) ? (string) ($settings['live-cdn'] ?? '') : '',
            ],
            'php'              => [
                'version'            => PHP_VERSION,
                'memory_limit'       => (string) ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
            ],
            'orchestrator'     => self::probe_orchestrator(),
            'loopback'         => self::probe_loopback(),
            'async_unreliable_since' => $unreliable > 0 ? $unreliable : null,
            'parked_count'     => count(self::parked_ids()),
            // The service's site-wide hold (429 capacity, 401 unknown key), or null.
            'service_hold'     => class_exists('wps_ic_image_optimize') ? wps_ic_image_optimize::service_hold() : null,
        ];
    }

    private static function probe_orchestrator()
    {
        $url = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        if ($url === '') {
            return ['url' => '', 'status' => 0, 'ms' => 0, 'error' => 'no orchestrator url'];
        }
        $t0 = microtime(true);
        $res = wp_remote_get($url . '/capabilities', ['timeout' => 5, 'headers' => ['Accept' => 'application/json']]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_wp_error($res)) {
            return ['url' => $url, 'status' => 0, 'ms' => $ms, 'error' => $res->get_error_message()];
        }
        return ['url' => $url, 'status' => (int) wp_remote_retrieve_response_code($res), 'ms' => $ms, 'error' => ''];
    }

    /** A connect (no request written) to every host the loopback list names, in its order. */
    private static function probe_loopback()
    {
        $parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!is_array($parts) || empty($parts['host'])) {
            return ['error' => 'no admin url host'];
        }
        $https = ($parts['scheme'] ?? '') === 'https';
        $port = !empty($parts['port']) ? (int) $parts['port'] : ($https ? 443 : 80);
        $host = (string) $parts['host'];
        $ctx = $https
            ? stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]])
            : stream_context_create();
        $targets = [];
        foreach (wps_ic_ajax::loopback_connect_chain($host, $https, $port) as $target) {
            $errno = 0;
            $errstr = '';
            $t0 = microtime(true);
            $sock = wpc_loopback_connect(($https ? 'tls://' : 'tcp://') . $target . ':' . $port, $errno, $errstr, 1.0, $ctx);
            $targets[] = ['host' => $target, 'connected' => (bool) $sock, 'errno' => (int) $errno, 'error' => (string) $errstr, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
            if ($sock) {
                @fclose($sock);
            }
        }
        return [
            'host'       => $host,
            'port'       => $port,
            'targets'    => $targets,
            'remembered' => (string) get_transient('wpc_loopback_host'),
            'refused'    => wps_ic_ajax::loopback_refused_hosts(),
            'order'      => wps_ic_ajax::loopback_order(wps_ic_ajax::loopback_connect_chain($host, $https, $port)),
        ];
    }

    // ─── export ───────────────────────────────────────────────────────────────────────────

    /** The lane's state for $ids: their owned meta and journal files, and the options and lists that name them. */
    public static function export(array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $settings = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $ledger = get_option('wpc_bulk_run_ledger');
        $queue = get_option('wpc_bulk_queue199');
        $images = [];
        foreach ($ids as $id) {
            $images[$id] = [
                'meta'      => self::owned_meta($id),
                'lock'      => get_option('wpc_v2_inflight_' . $id, null),
                'pending'   => get_transient('wpc_v2_pending_' . $id),
                'job_ids'   => self::job_ids($id),
                'restoring' => get_transient('wpc_restoring_' . $id),
                'journal'   => self::journal_count($id) > 0 ? array_map('basename', wpc_v2_journal_image_files($id)) : [],
                'ledger'    => is_array($ledger) ? ($ledger['images'][$id] ?? null) : null,
                'queued'    => is_array($queue) && in_array($id, array_map('intval', (array) ($queue['queue'] ?? [])), true),
            ];
        }
        $doc = [
            'generated_at'   => time(),
            'plugin_version' => defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '',
            'settings'       => [
                'backup'       => is_array($settings) ? ($settings['backup'] ?? null) : null,
                'optimization' => is_array($settings) ? ($settings['optimization'] ?? null) : null,
                'live-cdn'     => is_array($settings) ? ($settings['live-cdn'] ?? null) : null,
            ],
            'parked'         => self::parked_ids(),
            'options'        => [
                'wpc_bulk_run'           => is_array($ledger) ? ['run_id' => $ledger['run_id'] ?? '', 'started' => $ledger['started'] ?? 0, 'images' => count((array) ($ledger['images'] ?? []))] : null,
                'wpc_bulk_queue199'      => is_array($queue) ? ['total_images' => $queue['total_images'] ?? null, 'queued' => count((array) ($queue['queue'] ?? []))] : null,
                'wpc_bulk_inflight199'   => get_option(defined('WPC_BULK_INFLIGHT_OPTION') ? WPC_BULK_INFLIGHT_OPTION : 'wpc_bulk_inflight199', null),
                'wps_ic_bulk_process'    => get_option('wps_ic_bulk_process', null),
                'wpc_v2_async_unreliable' => get_option('wpc_v2_async_unreliable', null),
                'wpc_park_reset52_v'     => get_option('wpc_park_reset52_v', null),
            ],
            'images'         => $images,
        ];
        return self::redact($doc);
    }

    private static function owned_meta($id)
    {
        $out = [];
        $all = get_post_meta($id);
        foreach (is_array($all) ? $all : [] as $key => $values) {
            foreach (self::OWNED_PREFIXES as $prefix) {
                if (strpos((string) $key, $prefix) === 0) {
                    $v = is_array($values) && array_key_exists(0, $values) ? $values[0] : $values;
                    $out[$key] = function_exists('maybe_unserialize') ? maybe_unserialize($v) : $v;
                    break;
                }
            }
        }
        foreach (self::OWNED_META as $key) {
            if (!array_key_exists($key, $out)) {
                $v = get_post_meta($id, $key, true);
                if ($v !== '' && $v !== null && $v !== false) {
                    $out[$key] = $v;
                }
            }
        }
        ksort($out);
        return $out;
    }

    // ─── shared ───────────────────────────────────────────────────────────────────────────

    /** The ids the options select: --ids, else the parked list (--parked), else by ic_status (--status), else the newest images. */
    public static function select_ids(array $opts)
    {
        $limit = max(1, (int) ($opts['limit'] ?? self::DEFAULT_LIMIT));
        if (!empty($opts['ids'])) {
            return array_slice($opts['ids'], 0, $limit);
        }
        if (!empty($opts['parked'])) {
            return array_slice(self::parked_ids(), 0, $limit);
        }
        global $wpdb;
        if (!isset($wpdb)) {
            return [];
        }
        if (!empty($opts['status'])) {
            $sql = $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ic_status' AND meta_value = %s ORDER BY post_id DESC LIMIT %d", (string) $opts['status'], $limit);
        } else {
            $sql = $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' ORDER BY ID DESC LIMIT %d", $limit);
        }
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    private static function options_from(array $in)
    {
        $ids = [];
        if (isset($in['ids']) && $in['ids'] !== '') {
            $ids = array_values(array_filter(array_map('intval', is_array($in['ids']) ? $in['ids'] : explode(',', (string) $in['ids']))));
        }
        $flag = function ($k) use ($in) {
            return isset($in[$k]) && $in[$k] !== '0' && $in[$k] !== false && $in[$k] !== 'false';
        };
        return [
            'ids'       => $ids,
            'parked'    => $flag('parked'),
            'status'    => isset($in['status']) ? sanitize_key((string) $in['status']) : '',
            'limit'     => isset($in['limit']) ? (int) $in['limit'] : self::DEFAULT_LIMIT,
            'site_only' => $flag('site-only') || $flag('site_only'),
            'export'    => $flag('export'),
        ];
    }

    /** The parked list, read without the list helper (which creates its folder when missing). */
    private static function parked_ids()
    {
        if (!is_dir(self::uploads_base() . '/wpc-cache') || !function_exists('wpc_v2_parked_list')) {
            return [];
        }
        return array_map('intval', (array) wpc_v2_parked_list());
    }

    private static function uploads_base()
    {
        $up = wp_upload_dir();
        return rtrim((string) ($up['basedir'] ?? ''), '/\\');
    }

    private static function under_uploads($path)
    {
        $base = self::uploads_base();
        $path = (string) $path;
        return ($base !== '' && strpos($path, $base . '/') === 0) ? substr($path, strlen($base) + 1) : $path;
    }

    /** Drops every value under a secret-looking key and every occurrence of the site's apikey. */
    private static function redact($value, $key = '')
    {
        static $apikey = null;
        if ($apikey === null) {
            $o = get_option('wps_ic');
            $apikey = is_array($o) && !empty($o['api_key']) ? (string) $o['api_key'] : '';
        }
        if ($key !== '' && !is_int($key) && preg_match(self::SECRET_KEY_PATTERN, (string) $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, $k);
            }
            return $out;
        }
        if (is_string($value) && $apikey !== '' && strpos($value, $apikey) !== false) {
            return str_replace($apikey, '[redacted]', $value);
        }
        return $value;
    }

    /** site() as dotted key => scalar lines for the CLI table view. */
    private static function flatten(array $a, $prefix = '')
    {
        $out = [];
        foreach ($a as $k => $v) {
            $name = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $name);
            } else {
                $out[$name] = is_bool($v) ? ($v ? 'yes' : 'no') : ($v === null ? '-' : (string) $v);
            }
        }
        return $out;
    }
}
