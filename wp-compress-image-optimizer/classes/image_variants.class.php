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
 *   twin_files($id) / delete_twins($id)  the AVIF and WebP files beside an attachment's own files, matched by whole stem (restore, delete, purge)
 *   landed_stems($id)  the sizes an attachment holds, adaptive widths included; the service's delete names exactly these (wpc_v2_attachment_paths)
 *   oversized_twins($id) / drop_oversized($id, $src)  the twins no smaller than the file they stand for; remove them with their set entries
 *   landing_refused($id)                  true while a restore runs or a restored image has no fresh wpc_reopt_intent
 *   restore_held($id)                     true while a restored image is held: always outside Smart Delivery, inside its 300 s cool-off under it
 *   stamp_restore($id)                    the restore doors' one write of wpc_restored_at
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
     * The width in pixels a stored variant stands for, 0 when it cannot be told. `$label` is the
     * set key (wpc_v2_variant_key) and `$file` the variant's file name. Apart from the original, a `-WxH` name carries its
     * width; `scaled` and a `-scaled` file are the attached file (its metadata width); `unscaled`
     * is the attached file when WordPress did not scale it; `original` (and the pre-scale file's
     * own name beside a scaled attachment) is the pre-scale original encoded inside the site's
     * maxWidth box, so a 4000 px original reads as the width its encode has.
     */
    public static function width($id, $label, $file)
    {
        $file = basename((string) $file);
        $id = (int) $id;
        $meta = $id > 0 && function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
        $meta = is_array($meta) ? $meta : [];
        $meta_w = !empty($meta['width']) ? (int) $meta['width'] : 0;
        $base = strtolower((string) preg_replace('/-(avif|webp|jpe?g|png)$/i', '', (string) $label));
        $stem = (string) preg_replace('/\.[^.]+$/', '', $file);

        $is_original = $base === 'original'
            || (!empty($meta['original_image']) && $stem !== '' && $stem === (string) preg_replace('/\.[^.]+$/', '', basename((string) $meta['original_image'])));
        if ($is_original) {
            $path = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
            $size = $path !== '' && @is_file($path) ? @getimagesize($path) : false;
            if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                $w = (int) $size[0];
                $h = (int) $size[1];
            } elseif (empty($meta['original_image'])) {
                $w = $meta_w;
                $h = !empty($meta['height']) ? (int) $meta['height'] : $meta_w;
            } else {
                return 0;
            }
            if ($w <= 0 || $h <= 0) {
                return 0;
            }
            $options = get_option(defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic');
            $max = is_array($options) && !empty($options['maxWidth']) ? (int) $options['maxWidth'] : 2560;
            if ($max < 1) {
                $max = 2560;
            }
            if ($w > $max || $h > $max) {
                $w = (int) round($w * min($max / $w, $max / $h));
            }
            return $w;
        }
        if (preg_match('/-(\d+)x\d+\.\w+$/', $file, $dims)) {
            return (int) $dims[1];
        }
        if ($base === 'scaled' || preg_match('/-scaled\.\w+$/', $file)) {
            return $meta_w > 0 ? $meta_w : 2560;
        }
        if ($base === 'unscaled') {
            return $meta_w > 0 ? $meta_w : 4000;
        }
        return 0;
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
        if (self::landing_refused($id)) {
            self::landing_refused_receipt($id, $src);
            return self::get($id);
        }
        $lock = 'wpc_bg_meta_' . $id;
        $locked = function_exists('wpc_worker_lock') ? wpc_worker_lock($lock) : false;
        try {
            $set = self::get($id);
            $first = !empty($opts['first']);
            $landed = [];
            foreach ($entries as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!empty($entry['size']) && empty($entry['skipped']) && (int) ($set[$key]['size'] ?? 0) !== (int) $entry['size']) {
                    $landed[$key] = $entry;
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
        if ($landed && function_exists('wpc_sd_feed_note')) {
            wpc_sd_feed_note($id, $landed);
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

    /** Whether a landing for this image must be refused: a restore is running, or it reads restored with no fresh optimise intent. */
    public static function landing_refused($id)
    {
        if (!function_exists('wpc_v2_landing_refused') && is_file(dirname(__DIR__) . '/addons/v2/v2-store.php')) {
            require_once dirname(__DIR__) . '/addons/v2/v2-store.php';
        }
        return function_exists('wpc_v2_landing_refused') && wpc_v2_landing_refused((int) $id);
    }

    /** Whether the image reads restored and is still held (the owner of the restore gate, wpc_v2_restore_held). */
    public static function restore_held($id)
    {
        if (!function_exists('wpc_v2_restore_held') && is_file(dirname(__DIR__) . '/addons/v2/v2-store.php')) {
            require_once dirname(__DIR__) . '/addons/v2/v2-store.php';
        }
        return function_exists('wpc_v2_restore_held') && wpc_v2_restore_held((int) $id);
    }

    /** Record the moment of a restore: every restore door's one write of wpc_restored_at; a second restore may tell the service again. */
    public static function stamp_restore($id)
    {
        if (!function_exists('wpc_v2_restore_stamp') && is_file(dirname(__DIR__) . '/addons/v2/v2-store.php')) {
            require_once dirname(__DIR__) . '/addons/v2/v2-store.php';
        }
        if (function_exists('wpc_v2_restore_stamp')) {
            wpc_v2_restore_stamp((int) $id);
        }
        delete_transient('wpc_forget_done14_' . (int) $id);
    }

    /** The AVIF and WebP files beside this attachment's own files, as absolute paths; never its own files. */
    public static function twin_files($id)
    {
        $id = (int) $id;
        $attached = $id > 0 ? (string) get_attached_file($id) : '';
        if ($attached === '') {
            return [];
        }
        $dir = dirname($attached);
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        $own = [basename($attached) => true];
        $exact = [(string) pathinfo($attached, PATHINFO_FILENAME) => true];
        $bases = [preg_replace('/-scaled$/', '', (string) pathinfo($attached, PATHINFO_FILENAME)) => true];
        if ($original !== '') {
            $own[basename($original)] = true;
            $exact[(string) pathinfo($original, PATHINFO_FILENAME)] = true;
            $bases[preg_replace('/-scaled$/', '', (string) pathinfo($original, PATHINFO_FILENAME))] = true;
        }
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
        $listed = [];
        if (is_array($meta)) {
            foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $size) {
                $listed[] = is_array($size) && !empty($size['file']) ? (string) $size['file'] : '';
            }
            $listed[] = !empty($meta['original_image']) ? (string) $meta['original_image'] : '';
        }
        $backups = get_post_meta($id, '_wp_attachment_backup_sizes', true);
        foreach (is_array($backups) ? $backups : [] as $size) {
            $listed[] = is_array($size) && !empty($size['file']) ? (string) $size['file'] : '';
        }
        foreach ($listed as $file) {
            if ($file !== '') {
                $own[basename($file)] = true;
                $exact[(string) pathinfo($file, PATHINFO_FILENAME)] = true;
            }
        }
        $mime = (string) get_post_mime_type($id);
        $exts = [];
        foreach (['avif', 'webp'] as $ext) {
            if ($mime !== 'image/' . $ext) {
                $exts[] = $ext;
            }
        }
        $alt = implode('|', array_map(function ($base) {
            return preg_quote((string) $base, '/');
        }, array_keys($bases)));
        $found = [];
        foreach ($exts as $ext) {
            foreach (array_keys($exact) as $stem) {
                if (is_file($dir . '/' . $stem . '.' . $ext)) {
                    $found[$dir . '/' . $stem . '.' . $ext] = true;
                }
            }
            foreach (array_keys($bases) as $base) {
                $pattern = preg_replace('/([*?\[\]{}\\\\])/', '[$1]', $dir . '/' . $base) . '*.' . $ext;
                foreach ((array) glob($pattern, GLOB_NOESCAPE) as $file) {
                    if (preg_match('/^(?:' . $alt . ')(?:-scaled)?(?:-\d+x\d+|-\d+w)?\.' . $ext . '$/i', basename((string) $file))) {
                        $found[(string) $file] = true;
                    }
                }
            }
        }
        $candidates = [];
        foreach (array_keys($found) as $file) {
            $name = (string) pathinfo($file, PATHINFO_FILENAME);
            if (!isset($own[basename($file)]) && !isset($exact[$name])) {
                $candidates[$name] = true;
            }
        }
        $held = self::held_by_others($id, array_keys($candidates));
        $twins = [];
        foreach (array_keys($found) as $file) {
            if (!isset($own[basename($file)]) && !isset($held[(string) pathinfo($file, PATHINFO_FILENAME)])) {
                $twins[] = $file;
            }
        }
        sort($twins);
        return $twins;
    }

    /** Which of these file names (no extension, in this attachment's month directory) another attachment holds as its own upload or its -scaled copy; asks at most 40 names, and a name past that counts as held. Answers [name => true]. */
    private static function held_by_others($id, array $names)
    {
        global $wpdb;
        $held = [];
        if (empty($names) || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return $held;
        }
        $rel = (string) get_post_meta((int) $id, '_wp_attached_file', true);
        if ($rel === '') {
            $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
            $base = !empty($up['basedir']) ? rtrim(str_replace('\\', '/', (string) $up['basedir']), '/') . '/' : '';
            $attached = str_replace('\\', '/', (string) get_attached_file((int) $id));
            $rel = $base !== '' && strpos($attached, $base) === 0 ? substr($attached, strlen($base)) : '';
        }
        if ($rel === '') {
            return $held;
        }
        $prefix = strpos($rel, '/') !== false ? substr($rel, 0, strrpos($rel, '/') + 1) : '';
        $asked = 0;
        foreach ($names as $name) {
            if (++$asked > 40) {
                $held[(string) $name] = true;
                continue;
            }
            $rels = [];
            foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'] as $ext) {
                $rels[] = $prefix . $name . '.' . $ext;
                $rels[] = $prefix . $name . '-scaled.' . $ext;
            }
            $marks = implode(',', array_fill(0, count($rels), '%s'));
            $hit = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($marks) AND post_id <> %d LIMIT 1", ...array_merge($rels, [(int) $id])));
            if (!empty($hit)) {
                $held[(string) $name] = true;
            }
        }
        return $held;
    }

    /** The file names, without extension, of every size this attachment's landed variants carry: the set's keys and urls, the ideal widths asked, and the twin files on disk; only names of this attachment's own stem. */
    public static function landed_stems($id)
    {
        $id = (int) $id;
        $attached = $id > 0 ? (string) get_attached_file($id) : '';
        if ($attached === '') {
            return [];
        }
        $primary = preg_replace('/-scaled$/', '', (string) pathinfo($attached, PATHINFO_FILENAME));
        $bases = [$primary => true];
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        if ($original !== '') {
            $bases[preg_replace('/-scaled$/', '', (string) pathinfo($original, PATHINFO_FILENAME))] = true;
        }
        $names = [];
        foreach (self::get($id) as $key => $entry) {
            if (preg_match('/^(\d+)(?:x(\d+)|w)(?:-[a-z0-9]+)?$/i', (string) $key, $m)) {
                $names[$primary . '-' . $m[1] . (isset($m[2]) && $m[2] !== '' ? 'x' . $m[2] : 'w')] = true;
            }
            $url = is_array($entry) && !empty($entry['url']) ? basename((string) preg_replace('/\?.*$/', '', (string) $entry['url'])) : '';
            if ($url !== '') {
                $names[(string) preg_replace('/\.[^.]+$/', '', $url)] = true;
            }
        }
        $asked = get_post_meta($id, 'wpc_ideal_widths', true);
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
        foreach (is_array($asked) && function_exists('wpc_v2_adaptive_variant_suffix') ? $asked : [] as $width) {
            $suffix = wpc_v2_adaptive_variant_suffix((int) $width, $meta);
            if ($suffix !== '') {
                $names[$primary . $suffix] = true;
            }
        }
        $twin_names = [];
        foreach (self::twin_files($id) as $file) {
            $twin_names[(string) pathinfo($file, PATHINFO_FILENAME)] = true;
        }
        $alt = implode('|', array_map(function ($base) {
            return preg_quote((string) $base, '/');
        }, array_keys($bases)));
        $shaped = [];
        foreach (array_keys($names) as $name) {
            if (preg_match('/^(?:' . $alt . ')(?:-scaled)?(?:-\d+x\d+|-\d+w)?$/i', (string) $name)) {
                $shaped[] = (string) $name;
            }
        }
        $held = self::held_by_others($id, array_values(array_filter($shaped, function ($name) use ($primary, $twin_names) {
            return $name !== $primary && !isset($twin_names[$name]);
        })));
        $out = array_keys($twin_names);
        foreach ($shaped as $name) {
            if (!isset($held[$name])) {
                $out[] = $name;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** Remove the files twin_files() names; with a reason, the service is told first which sizes this attachment holds. Answers how many were removed. */
    public static function delete_twins($id, $reason = '')
    {
        $removed = 0;
        if ($reason !== '' && function_exists('wpc_v2_forget_variants_queue')) {
            wpc_v2_forget_variants_queue((int) $id, (string) $reason);
        }
        foreach (self::twin_files($id) as $file) {
            $bytes = (int) @filesize($file);
            if (@unlink($file)) {
                $removed++;
                if (function_exists('wpc_add_twin_bytes')) {
                    wpc_add_twin_bytes(-$bytes);
                }
            }
        }
        return $removed;
    }

    /** The twins no smaller than the file they stand for: [twin path => ['source' => path, 'twin' => bytes, 'source_bytes' => bytes]]. */
    public static function oversized_twins($id)
    {
        $id = (int) $id;
        $attached = $id > 0 ? (string) get_attached_file($id) : '';
        $twins = $attached === '' ? [] : self::twin_files($id);
        if (empty($twins) || !function_exists('wp_get_upload_dir') || !function_exists('wpc_v2_variant_identity_sweep_referenced')) {
            return [];
        }
        $up = wp_get_upload_dir();
        $base = !empty($up['basedir']) ? rtrim(str_replace('\\', '/', (string) $up['basedir']), '/') . '/' : '';
        if ($base === '') {
            return [];
        }
        $names = [$attached];
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        if ($original !== '') {
            $names[] = $original;
        }
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
        foreach (is_array($meta) && is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $size) {
            $names[] = is_array($size) && !empty($size['file']) ? (string) $size['file'] : '';
        }
        $exts = [];
        foreach ($names as $name) {
            $ext = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
            if ($ext !== '') {
                $exts[$ext] = true;
            }
        }
        $dir = dirname($attached);
        $over = [];
        foreach ($twins as $twin) {
            if (strpos(str_replace('\\', '/', $twin), $base) !== 0) {
                continue;
            }
            $stem = $dir . '/' . pathinfo($twin, PATHINFO_FILENAME);
            $twin_ext = strtolower((string) pathinfo($twin, PATHINFO_EXTENSION));
            $twin_bytes = (int) @filesize($twin);
            foreach (array_keys($exts) as $ext) {
                $source = $stem . '.' . $ext;
                if ($ext === $twin_ext || !is_file($source)) {
                    continue;
                }
                $source_bytes = (int) @filesize($source);
                if ($source_bytes > 0 && $twin_bytes >= $source_bytes && !wpc_v2_variant_identity_sweep_referenced($twin)) {
                    $over[$twin] = ['source' => $source, 'twin' => $twin_bytes, 'source_bytes' => $source_bytes];
                }
                break;
            }
        }
        return $over;
    }

    /** Remove the oversized twins and their set entries. Answers ['paths' => removed files, 'bytes' => bytes freed, 'keys' => entries dropped]. */
    public static function drop_oversized($id, $src)
    {
        $id = (int) $id;
        $out = ['paths' => [], 'bytes' => 0, 'keys' => 0];
        $names = [];
        foreach (self::oversized_twins($id) as $file => $row) {
            if (@unlink($file)) {
                $out['paths'][] = $file;
                $out['bytes'] += (int) $row['twin'];
                $names[basename($file)] = true;
                if (function_exists('wpc_add_twin_bytes')) {
                    wpc_add_twin_bytes(-(int) $row['twin']);
                }
            }
        }
        if (empty($names)) {
            return $out;
        }
        $keys = [];
        foreach (self::get($id) as $key => $entry) {
            $url = is_array($entry) && !empty($entry['url']) ? basename((string) preg_replace('/\?.*$/', '', (string) $entry['url'])) : '';
            if ($url !== '' && isset($names[$url])) {
                $keys[] = $key;
            }
        }
        $out['keys'] = self::forget($id, $keys, $src);
        return $out;
    }

    /** The receipt of a landing the restored image refused. */
    public static function landing_refused_receipt($id, $src)
    {
        if (function_exists('wpc_v2_landing_refused_receipt')) {
            wpc_v2_landing_refused_receipt($id, $src);
        }
    }

    /** Promote the image to compressed: the status every library count and the render lanes read. */
    public static function mark_compressed($id, $src)
    {
        $id = (int) $id;
        if (self::landing_refused($id)) {
            self::landing_refused_receipt($id, $src);
            return;
        }
        update_post_meta($id, 'ic_status', 'compressed');
        delete_post_meta($id, 'wpc_reopt_intent');
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
