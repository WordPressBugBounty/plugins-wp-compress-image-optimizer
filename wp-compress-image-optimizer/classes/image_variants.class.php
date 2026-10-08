<?php

/**
 * The one writer of an attachment's variant set (`ic_local_variants`), the savings the media
 * library shows for it (`ic_savings`, `_format`, `_bytes`, `_baseline`) and its `ic_status`
 * promotion to compressed.
 *
 * Twenty-one places wrote the set, eleven wrote the savings. They disagreed: only the Phase A
 * writer kept an entry a callback had already refined (`bg_upgraded`), the retry give-up wrote
 * without the lock, and each savings block computed its own number (the variant just landed,
 * the byte count the service announced, the best of the set) under its own lock or none, so the
 * figure on the card depended on which lane landed last (review 2026-09-26, B-F4).
 *
 *   get($id)                              the set as stored (meta only; disk is not consulted)
 *   record($id, $entries, $src, $opts)    merge entries into the set, then the savings, under one lock,
 *                                         then the compressed promotion (below)
 *   note_provisional_savings(...)         a saving announced before its bytes are in the set
 *   mark_compressed($id, $src)            ic_status = compressed (a promotion with no bytes to record)
 *   clear($id, $reason)                   drop the set, its savings and its journal files (restore, purge)
 *   forget($id, $keys, $src)              drop entries whose file left the disk, then the savings of what is left
 *
 * THE MERGE RULE. An entry merges over the one stored under its key (array_merge: fields the new
 * entry does not carry are kept). With `first` set (the dispatch's own Phase A answer) an entry
 * replaces the stored one outright, except an entry a callback already refined (`bg_upgraded`),
 * which a first answer never touches: the Phase A response of a re-dispatch arrives after the
 * previous run's refined bytes, and overwriting them put the coarse bytes back.
 *
 * THE SAVINGS are always the best of the whole set (wpc_compute_best_savings), written after the
 * merge in the same locked step, and only when the set has a saving to show.
 *
 * THE PROMOTION is decided here, not by the lane that landed the bytes. An image is compressed as
 * soon as a record carries its full-size encode: an entry with bytes (`size` > 0) under a parent
 * key, the size label `scaled`, `original` or `unscaled` (the lazy lane's name for an unscaled
 * main file), bare for jpeg or with the format suffix (wpc_v2_variant_key); a `first` record is
 * the Phase A parent answer whatever label the service named, and promotes on every answer.
 * Whichever source
 * recorded it: only Phase A, the journal drain and the lazy lane promoted, so variants that came
 * back through the manifest pull or a bg-swap batch (the path the service's duplicate answer
 * takes) left a restored image reading `restored` over its landed set, and every later bulk run
 * sent it again and got the duplicate answer again. A lane that treats any landed variant as the
 * image compressed passes `any_variant` (the lazy lane encodes size by size on demand; the
 * journal drain lands a dispatch's own Phase B): with no dispatch in flight (`ic_compressing` not
 * optimizing/queueing) any entry with a `size` promotes it and sets `ic_compressing` to
 * compressed, as those lanes did at their call sites.
 *
 * Receipt: `image-variants-recorded {id, src, n}`; a write that could not take the lock is still
 * made (the entries would otherwise be lost) and says so with `lock: 0`.
 */
class wps_ic_image_variants
{
    const SAVINGS_KEYS = ['ic_savings', 'ic_savings_format', 'ic_savings_bytes', 'ic_savings_baseline'];

    /** The stored set, read fresh. */
    public static function get($id)
    {
        $id = (int) $id;
        wp_cache_delete($id, 'post_meta');
        $set = get_post_meta($id, 'ic_local_variants', true);
        return is_array($set) ? $set : [];
    }

    /**
     * Merge $entries (variant key => entry) into the set and refresh the savings, under the
     * image's meta lock, then promote the image (see the promotion rule). Options: `first` =>
     * true for the dispatch's Phase A answer (see the merge rule); `any_variant` => true for a
     * lane that treats any landed variant as the image compressed. Answers the set as written.
     */
    public static function record($id, array $entries, $src, array $opts = [])
    {
        $id = (int) $id;
        if ($id <= 0 || empty($entries)) {
            return self::get($id);
        }
        $lock = 'wpc_bg_meta_' . $id;
        $locked = function_exists('wpc_worker_lock') ? wpc_worker_lock($lock) : false;
        try {
            $set = self::get($id);
            $first = !empty($opts['first']);
            foreach ($entries as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if ($first) {
                    if (!empty($set[$key]['bg_upgraded'])) {
                        continue;
                    }
                    $set[$key] = $entry;
                } else {
                    $set[$key] = array_merge(isset($set[$key]) && is_array($set[$key]) ? $set[$key] : [], $entry);
                }
            }
            update_post_meta($id, 'ic_local_variants', $set);
            self::write_savings($id, $set);
        } finally {
            if ($locked) {
                wpc_worker_unlock($lock);
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-variants-recorded', (string) $id, '', ['id' => $id, 'src' => (string) $src, 'n' => count($entries), 'lock' => $locked ? 1 : 0]);
        }
        self::promote($id, $entries, $src, $opts);
        return $set;
    }

    /** The promotion rule (class docblock), for the entries one record() carried. */
    private static function promote($id, array $entries, $src, array $opts)
    {
        if (!empty($opts['any_variant'])) {
            $lands_any = false;
            foreach ($entries as $entry) {
                if (is_array($entry) && array_key_exists('size', $entry)) {
                    $lands_any = true;
                    break;
                }
            }
            if ($lands_any && get_post_meta($id, 'ic_status', true) !== 'compressed') {
                $compressing = get_post_meta($id, 'ic_compressing', true);
                $compressing_status = (is_array($compressing) && !empty($compressing['status'])) ? (string) $compressing['status'] : '';
                if ($compressing_status !== 'optimizing' && $compressing_status !== 'queueing') {
                    self::mark_compressed($id, $src);
                    if ($compressing_status !== 'compressed') {
                        update_post_meta($id, 'ic_compressing', ['status' => 'compressed']);
                    }
                }
            }
        }
        $lands_parent = false;
        foreach ($entries as $key => $entry) {
            // A first answer is the Phase A parent itself, whatever size label the service named.
            if (is_array($entry) && !empty($entry['size'])
                && (!empty($opts['first']) || preg_match('/^(scaled|original|unscaled)(-[a-z0-9]+)?$/', (string) $key))) {
                $lands_parent = true;
                break;
            }
        }
        // Phase A promotes on every answer, as its own call did; any other source only once.
        if ($lands_parent && (!empty($opts['first']) || get_post_meta($id, 'ic_status', true) !== 'compressed')) {
            self::mark_compressed($id, $src);
        }
    }

    /** Promote the image to compressed: the status every library count and the render lanes read. */
    public static function mark_compressed($id, $src)
    {
        $id = (int) $id;
        update_post_meta($id, 'ic_status', 'compressed');
        // The path-A optimized-ids cache names which images get the origin <picture> upgrade;
        // refresh it so the next render sees this one instead of waiting out its 300 s TTL.
        if (function_exists('wpc_invalidate_local_cache')) {
            wpc_invalidate_local_cache();
        }
    }

    /**
     * A variant the service announced, or whose bytes are journaled but not yet merged into the
     * set: raise the card's figure when this one is higher, so the first poll that sees the image
     * compressed already shows a saving. The next record() writes the set's own best over it.
     */
    public static function note_provisional_savings($id, $format, $orig, $opt)
    {
        $id = (int) $id;
        $orig = (int) $orig;
        $opt = (int) $opt;
        if ($id <= 0 || $orig <= 0 || $opt <= 0 || $opt >= $orig) {
            return;
        }
        $pct = round((1 - $opt / $orig) * 100, 1);
        if ($pct <= (float) get_post_meta($id, 'ic_savings', true)) {
            return;
        }
        update_post_meta($id, 'ic_savings', $pct);
        update_post_meta($id, 'ic_savings_format', (string) $format);
        update_post_meta($id, 'ic_savings_bytes', $orig - $opt);
        update_post_meta($id, 'ic_savings_baseline', $orig);
    }

    /**
     * Drop the set, its savings and the image's callbacks still waiting in the landing journal
     * (a restore or purge is a clean slate: no earlier outcome is left unaccounted, so the next
     * dispatch is not refused for it). Answers how many journal files were dropped.
     */
    public static function clear($id, $reason)
    {
        $id = (int) $id;
        $journal_dropped = function_exists('wpc_v2_journal_drop_image') ? (int) wpc_v2_journal_drop_image($id) : 0;
        $lock = 'wpc_bg_meta_' . $id;
        $locked = function_exists('wpc_worker_lock') ? wpc_worker_lock($lock) : false;
        try {
            delete_post_meta($id, 'ic_local_variants');
            foreach (self::SAVINGS_KEYS as $key) {
                delete_post_meta($id, $key);
            }
        } finally {
            if ($locked) {
                wpc_worker_unlock($lock);
            }
        }
        return $journal_dropped;
    }

    /**
     * Take entries out of the set under the image's meta lock: variants whose file was removed from
     * the disk (the renderer emits a <source> for a recorded entry, and a recorded entry no file
     * backs would 404 where the origin serves it). The savings are re-derived from what is left.
     * Answers how many entries went.
     */
    public static function forget($id, array $keys, $src)
    {
        $id = (int) $id;
        if ($id <= 0 || empty($keys)) {
            return 0;
        }
        $lock = 'wpc_bg_meta_' . $id;
        $locked = function_exists('wpc_worker_lock') ? wpc_worker_lock($lock) : false;
        $gone = 0;
        try {
            $set = self::get($id);
            foreach ($keys as $key) {
                if (isset($set[$key])) {
                    unset($set[$key]);
                    $gone++;
                }
            }
            if ($gone > 0) {
                foreach (self::SAVINGS_KEYS as $savings_key) {
                    delete_post_meta($id, $savings_key);
                }
                if (empty($set)) {
                    delete_post_meta($id, 'ic_local_variants');
                } else {
                    update_post_meta($id, 'ic_local_variants', $set);
                    self::write_savings($id, $set);
                }
            }
        } finally {
            if ($locked) {
                wpc_worker_unlock($lock);
            }
        }
        if ($gone > 0 && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-variants-forgotten', (string) $id, '', ['id' => $id, 'src' => (string) $src, 'n' => $gone]);
        }
        return $gone;
    }

    private static function write_savings($id, array $set)
    {
        if (!function_exists('wpc_compute_best_savings')) {
            return;
        }
        $best = wpc_compute_best_savings($set, $id);
        if (empty($best['orig']) || empty($best['pct'])) {
            return;
        }
        update_post_meta($id, 'ic_savings', round((float) $best['pct'], 1));
        update_post_meta($id, 'ic_savings_format', (string) $best['format']);
        update_post_meta($id, 'ic_savings_bytes', (int) $best['orig'] - (int) $best['opt']);
        update_post_meta($id, 'ic_savings_baseline', (int) $best['orig']);
    }
}
