<?php

/**
 * The one answer to "which images would a bulk run compress" and "how far has the run got".
 *
 *   population()   the attachment ids a bulk run compresses, newest first (admission included)
 *   counts()       the splash's numbers from the same scan: ['population' => n, 'compressed' => n,
 *                  'missing' => n] (images left out because their file is not on disk)
 *   source_state() whether an attachment's source file is on disk
 *   missing_ids()  the images counted as `missing`, for the missing-files view
 *   seen_source()  / file_deleted(): what the missing-files view reports (since when, removed by us)
 *   progress()     the running bulk run, from its ledger only; null when no run exists
 *
 * Three places counted the library and three tallied a run, and they disagreed (review
 * 2026-09-26, A-D6): the splash deduplicated by file for "uncompressed" but not "compressed",
 * the queue neither deduplicated nor asked admission, so it held more images than the splash
 * promised; the run was tallied in `wps_ic_BulkStatus`, in the ledger and in the service's run
 * summary. An "all parked" pre-flight, a dead-flag snapshot with an hourly recount and a
 * reconcile call against the service each covered one symptom of that.
 *
 * ADMISSION. An image is in the population when its mime type is one the service optimizes
 * (wpc_optimizable_mimes), it carries no `wps_ic_exclude_live`, and it is not compressed: no
 * `ic_stats` (v1), no `ic_status = compressed` (the variant-set owner's mark), and no landed
 * variant in its set (wps_ic_image_variants::get(), asked only for images that have a set). Files
 * attached more than once count once (the lowest id), and not at all when any copy is compressed.
 * An image whose file is not on disk (source_state() 'missing') is left out and counted as
 * `missing`: a run cannot compress it and would only count it as failed. Parked and backing-off images stay in: the dispatch door refuses them one by one and the
 * ledger records each refusal, so the run shows them instead of refusing to start.
 */
class wps_ic_image_library
{
    /** Postmeta: when this site first saw the image's source file missing (see seen_source()). */
    const MISSING_SINCE = 'wpc_source_missing_since';

    /** Postmeta: the files of the image this plugin deleted (see file_deleted()). */
    const DELETED_LOG = 'wpc_file_deleted_log';

    /** @return int[] */
    public static function population()
    {
        return self::scan()['population'];
    }

    /** @return array{population:int, compressed:int, missing:int} */
    public static function counts()
    {
        $scan = self::scan();
        return ['population' => count($scan['population']), 'compressed' => $scan['compressed'], 'missing' => $scan['missing']];
    }

    /**
     * The run's state per the ledger (`wpc_bulk_run_ledger`, written by the bulk handlers):
     * counts per state, `total` (images in the run), `finished` (verified + parked + skipped)
     * and `verified_ids`; and what happened: `dispatched` (sent to the service), `landed`
     * (verified), `refused` ({reason: n} for images not sent), `deduplicated` (sent and answered
     * by the service as already encoded; counted in `dispatched`), `awaiting` (sent, not landed and
     * not a duplicate answer: its files are still to arrive) and `running` (the run flag is
     * set). A run is `ended` when the flag is gone and every image is either dispatched or
     * refused; a run stopped before its queue was asked is not. Null when no run has a ledger.
     */
    public static function progress()
    {
        $ledger = get_option('wpc_bulk_run_ledger');
        if (!is_array($ledger) || empty($ledger['run_id'])) {
            return null;
        }
        $out = ['queued' => 0, 'inflight' => 0, 'verified' => 0, 'parked' => 0, 'skipped' => 0];
        $verified_ids = [];
        $dispatched = 0;
        $deduplicated = 0;
        $refused = [];
        $awaiting = 0;
        foreach ((array) ($ledger['images'] ?? []) as $id => $row) {
            $st = is_array($row) && isset($row['st']) ? (string) $row['st'] : '';
            if (isset($out[$st])) {
                $out[$st]++;
            }
            if ($st === 'verified') {
                $verified_ids[] = (int) $id;
            }
            if ($st === 'verified' || !empty($row['sent'])) {
                $dispatched++;
                if (!empty($row['dedup'])) {
                    $deduplicated++;
                } elseif ($st !== 'verified') {
                    $awaiting++;
                }
            } elseif (($st === 'skipped' || $st === 'parked') && isset($row['why']) && $row['why'] !== '') {
                $refused[(string) $row['why']] = ($refused[(string) $row['why']] ?? 0) + 1;
            }
        }
        $out['total'] = $out['queued'] + $out['inflight'] + $out['verified'] + $out['parked'] + $out['skipped'];
        $out['finished'] = $out['verified'] + $out['parked'] + $out['skipped'];
        $out['verified_ids'] = $verified_ids;
        $out['dispatched'] = $dispatched;
        $out['landed'] = $out['verified'];
        $out['refused'] = $refused;
        $out['deduplicated'] = $deduplicated;
        $out['awaiting'] = $awaiting;
        $out['running'] = (bool) get_option('wps_ic_bulk_process');
        $out['ended'] = !$out['running'] && $dispatched + array_sum($refused) === $out['total'];
        $out['run_id'] = (string) $ledger['run_id'];
        $out['started'] = (int) ($ledger['started'] ?? 0);
        $out['at'] = time();
        return $out;
    }

    /**
     * What the completion card says about a run, from the ledger only: images `sent`
     * (dispatched), `landed`, `deduplicated`, `refused` ({reason: n}), `awaiting` (sent, files
     * still to arrive) and `variants`, the variants recorded for the images this run landed, read
     * through the variant owner (entries with bytes). Null when no run has a ledger.
     * Observed (ticket 12006, finde-online.de): a run of 16 that landed 15 (one duplicate answer)
     * ended on "Successfully optimized 0 original images, generating 0 modern variants": the card
     * read the heartbeat's `processed`, a ledger count that only a drain slice moves, and
     * `variants_total`, counted over the session list that the page's cleanup deletes at the
     * reveal. The caller counts landings first (wps_ic_ajax::wpc_bulk_status_count()).
     */
    public static function completion()
    {
        $run = self::progress();
        if ($run === null) {
            return null;
        }
        $variants = 0;
        foreach ($run['verified_ids'] as $id) {
            foreach (wps_ic_image_variants::get($id) as $entry) {
                if (is_array($entry) && !empty($entry['size'])) {
                    $variants++;
                }
            }
        }
        return [
            'sent'         => $run['dispatched'],
            'landed'       => $run['landed'],
            'deduplicated' => $run['deduplicated'],
            'refused'      => $run['refused'],
            'awaiting'     => $run['awaiting'],
            'variants'     => $variants,
        ];
    }

    /**
     * Whether the file an attachment's compression reads is on disk: 'present' when the file
     * WordPress serves (the scaled one, `_wp_attached_file`) or the unscaled original exists,
     * 'missing' when neither does, 'not-attachment' for an id that is no attachment (deleted, or
     * another post type). The one answer the dispatch door, the backup, the bulk count and the
     * image doctor ask. Observed: a site whose 2024 uploads were gone from disk (every size
     * answering 404) retried them on every bulk run as "backup failed" until each was parked
     * for seven days (ticket 12006).
     *
     * $attached_file, when the caller already read `_wp_attached_file` (the library scan), saves
     * the attachment lookup; relative to the uploads directory as WordPress stores it.
     */
    public static function source_state($id, $attached_file = null)
    {
        $id = (int) $id;
        if ($attached_file === null && ($id <= 0 || get_post_type($id) !== 'attachment')) {
            return 'not-attachment';
        }
        $paths = [];
        if ($attached_file !== null) {
            $file = (string) $attached_file;
            if ($file !== '' && !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $file)) {
                $up = wp_upload_dir();
                $file = rtrim((string) ($up['basedir'] ?? ''), '/\\') . '/' . $file;
            }
            $paths[] = $file;
        } else {
            $paths[] = (string) get_attached_file($id);
        }
        foreach ($paths as $path) {
            if ($path !== '' && @is_file($path)) {
                return 'present';
            }
        }
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        return ($original !== '' && @is_file($original)) ? 'present' : 'missing';
    }

    /** One pass over the library's images; the admission rule is applied here, in PHP. */
    private static function scan()
    {
        global $wpdb;
        $mimes = function_exists('wpc_optimizable_mimes') ? (array) wpc_optimizable_mimes() : ['image/jpeg', 'image/png', 'image/gif'];
        $rows = $wpdb->get_results("
            SELECT p.ID AS id, p.post_mime_type AS mime, f.meta_value AS file,
                EXISTS (SELECT 1 FROM {$wpdb->postmeta} ex WHERE ex.post_id = p.ID AND ex.meta_key = 'wps_ic_exclude_live') AS excluded,
                EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID
                        AND (m.meta_key = 'ic_stats' OR (m.meta_key = 'ic_status' AND m.meta_value = 'compressed'))) AS marked,
                EXISTS (SELECT 1 FROM {$wpdb->postmeta} v WHERE v.post_id = p.ID AND v.meta_key = 'ic_local_variants') AS has_set
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE 'image/%'
            ORDER BY p.ID DESC
        ", ARRAY_A);

        $by_file = [];
        $compressed = 0;
        foreach ((array) $rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || !in_array((string) ($row['mime'] ?? ''), $mimes, true) || !empty($row['excluded'])) {
                continue;
            }
            $done = !empty($row['marked']) || (!empty($row['has_set']) && self::has_landed_variant($id));
            if ($done) {
                $compressed++;
            }
            $file = (string) ($row['file'] ?? '');
            $group = $file !== '' ? $file : '#' . $id;
            if (!isset($by_file[$group])) {
                $by_file[$group] = ['id' => $id, 'done' => $done, 'file' => $file];
            } else {
                $by_file[$group]['id'] = min($by_file[$group]['id'], $id);
                $by_file[$group]['done'] = $by_file[$group]['done'] || $done;
            }
        }
        $stamped = [];
        foreach ((array) $wpdb->get_results("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::MISSING_SINCE . "'", ARRAY_A) as $row) {
            $stamped[(int) ($row['post_id'] ?? 0)] = true;
        }
        $population = [];
        $missing_ids = [];
        foreach ($by_file as $g) {
            if ($g['done']) {
                continue;
            }
            $state = self::source_state($g['id'], $g['file']);
            self::seen_source($g['id'], $state, isset($stamped[$g['id']]));
            if ($state === 'missing') {
                $missing_ids[] = $g['id'];
            } else {
                $population[] = $g['id'];
            }
        }
        rsort($population);
        rsort($missing_ids);
        return ['population' => $population, 'compressed' => $compressed, 'missing' => count($missing_ids), 'missing_ids' => $missing_ids];
    }

    /** @return int[] the images left out of the population because their file is not on disk (counts()['missing'] of them) */
    public static function missing_ids()
    {
        return self::scan()['missing_ids'];
    }

    /**
     * Remember when this site first saw an image's source file missing, and forget it when the
     * file is back: postmeta `wpc_source_missing_since` (unix time), written once. The library
     * scan, the dispatch door and the bulk drain's backup report what they read here; the image
     * doctor and the missing-files view only read it. Observed (ticket 12006): 39 images whose
     * files were gone, and nothing on the site could say since when.
     */
    public static function seen_source($id, $state, $stamped = null)
    {
        $id = (int) $id;
        if ($id <= 0 || ($state !== 'missing' && $state !== 'present')) {
            return;
        }
        $has = $stamped !== null ? (bool) $stamped : ((int) get_post_meta($id, self::MISSING_SINCE, true) > 0);
        if ($state === 'missing' && !$has) {
            update_post_meta($id, self::MISSING_SINCE, time());
        } elseif ($state === 'present' && $has) {
            delete_post_meta($id, self::MISSING_SINCE);
        }
    }

    /**
     * Record that this plugin deleted a file of an attachment: its original or a WordPress size,
     * never a webp/avif variant or a temp file. Appends {ts, file, op} to postmeta
     * `wpc_file_deleted_log` (the last 20) and logs `image-file-deleted {id, file, op}`, so the
     * missing-files view can say whether WP Compress removed a file. Rule: every plugin path that
     * unlinks such a file calls this first. Observed (ticket 12006): a customer's originals and
     * sizes were gone, and only a code audit could say the plugin had not deleted them.
     */
    public static function file_deleted($id, $file, $op)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }
        $up = wp_upload_dir();
        $base = rtrim(str_replace('\\', '/', (string) ($up['basedir'] ?? '')), '/') . '/';
        $rel = str_replace('\\', '/', (string) $file);
        if ($base !== '/' && strpos($rel, $base) === 0) {
            $rel = substr($rel, strlen($base));
        }
        $log = get_post_meta($id, self::DELETED_LOG, true);
        $log = is_array($log) ? $log : [];
        $log[] = ['ts' => time(), 'file' => $rel, 'op' => (string) $op];
        update_post_meta($id, self::DELETED_LOG, array_slice($log, -20));
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-file-deleted', (string) $id, '', ['id' => $id, 'file' => $rel, 'op' => (string) $op]);
        }
    }

    private static function has_landed_variant($id)
    {
        foreach (wps_ic_image_variants::get($id) as $entry) {
            if (is_array($entry) && !empty($entry['size'])) {
                return true;
            }
        }
        return false;
    }
}
