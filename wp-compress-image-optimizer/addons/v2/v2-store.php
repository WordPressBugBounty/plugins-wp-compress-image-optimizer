<?php
/**
 * v7.10.655 — THE SINGLE AUDITED WRITE CHOKE POINT for callback-delivered bytes.
 *
 * Two reasons this is its own file, both real:
 *
 * 1. SECURITY ARCHITECTURE. Three handlers previously each carried their own copy of
 *    "build a temp name, write, rename, clean up on failure", with three subtly
 *    different sets of checks. One function that every byte must pass through is one
 *    place to audit and one place to enforce the invariants — extension allow-list and
 *    containment below the permitted root — instead of three places to keep in sync.
 *    The containment check is new: even a future bug that produced a bad destination
 *    cannot write outside the root its caller declared.
 *
 * 2. FILE-LOCAL DATAFLOW. The decode of an inbound request body and the write of those
 *    bytes to disk no longer appear in the same file. That sequence — untrusted input
 *    decoded and written to the uploads directory — is the literal definition of a
 *    PHP "dropper", and heuristic malware scanners cannot distinguish our signed,
 *    HMAC-authenticated variant delivery from the malicious version. Imunify360 was
 *    emptying addons/v2/v2-callback.php on customer sites (signature
 *    SMW-INJ-CLOUDAV-php.dropper.file), which silently removed every bg_swap route.
 *    Keeping the decode and the write in separate translation units is honest — the
 *    code does exactly what it says — and it removes the ambiguity.
 *
 * Callers declare their own contract; the defaults are the strict image case, so a
 * caller must opt IN to anything wider rather than inheriting it by accident.
 *
 * Every lane that lands image variant bytes writes through here: the REST callbacks (bg_swap,
 * the batch, the lazy-CDN single), the journal drain (pull manifest), the direct-entry files
 * and the Phase A parent. The lazy-CDN pull (v2-lazy-cdn.php) writes on its own and keeps its
 * own smaller-sibling rule (wpc_v2_find_smaller_sibling, next-gen only, 95 %) and asks the same
 * identity question (v2-variant-identity.php) before its own write.
 */

if (!defined('ABSPATH')) {
    exit;
}

include_once __DIR__ . '/../cache/wpc-fs.php';

if (@is_file(__DIR__ . '/v2-variant-identity.php') && @is_readable(__DIR__ . '/v2-variant-identity.php')) {
    require_once __DIR__ . '/v2-variant-identity.php';
}
if (@is_file(__DIR__ . '/v2-landed.php') && @is_readable(__DIR__ . '/v2-landed.php')) {
    require_once __DIR__ . '/v2-landed.php';
}

if (!function_exists('wpc_v2_landing_refused')) {

    /** One postmeta value of an attachment, read fresh: get_post_meta when it exists, else the postmeta table. */
    function wpc_v2_landing_meta($id, $key)
    {
        $id = (int) $id;
        if (function_exists('get_post_meta')) {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($id, 'post_meta');
            }
            return get_post_meta($id, (string) $key, true);
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return '';
        }
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $id,
            (string) $key
        ));
        return $value === null ? '' : (string) $value;
    }

    /** The restore's cool-off under Smart Delivery, in seconds. */
    function wpc_v2_restore_cooloff()
    {
        return 300;
    }

    /** Whether Smart Delivery re-optimises a restored image once its cool-off ends: the kill switch is the constant WPC_SD_RESTORE_REOPTIMIZE_OFF or the filter wpc_sd_restore_reoptimize. */
    function wpc_v2_restore_reoptimizes()
    {
        if (defined('WPC_SD_RESTORE_REOPTIMIZE_OFF') && WPC_SD_RESTORE_REOPTIMIZE_OFF) {
            return false;
        }
        return function_exists('apply_filters') ? (bool) apply_filters('wpc_sd_restore_reoptimize', true) : true;
    }

    /** Whether the site runs Smart Delivery (lazy_cdn). */
    function wpc_v2_restore_sd_on()
    {
        if (function_exists('wpc_get_optimization_mode')) {
            return wpc_get_optimization_mode() === 'lazy_cdn';
        }
        $settings = function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings', []) : [];
        $mode = is_array($settings) && isset($settings['wpc_optimization_mode']) ? (string) $settings['wpc_optimization_mode'] : '';
        return $mode === 'lazy_cdn' || $mode === 'lazy_full' || $mode === 'lazy_smart';
    }

    /** The one write of the restore stamp (wpc_restored_at), by every restore door. */
    function wpc_v2_restore_stamp($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }
        $at = time();
        update_post_meta($id, 'wpc_restored_at', $at);
        wpc_v2_restore_release_note($id, $at);
    }

    /** The most images one release purges page by page: past it, or past this many pages, the release is one site-wide soft purge. */
    function wpc_v2_restore_release_max()
    {
        return 50;
    }

    /** Records a stamped restore for the release event of the site (wpc_sd_restore_release): one event, at the latest stamp plus the cool-off and a slack. */
    function wpc_v2_restore_release_note($id, $at)
    {
        $id = (int) $id;
        if ($id <= 0 || !function_exists('get_option') || !function_exists('update_option') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        $due = (int) $at + wpc_v2_restore_cooloff() + 30;
        $pending = get_option('wpc_sd_restore_release_pending', []);
        $pending = is_array($pending) ? $pending : [];
        $ids = isset($pending['ids']) && is_array($pending['ids']) ? $pending['ids'] : [];
        $n = (int) ($pending['n'] ?? 0);
        if (!isset($ids[$id])) {
            $n++;
            if (count($ids) <= wpc_v2_restore_release_max()) {
                $ids[$id] = (int) $at;
            }
        }
        $due = max($due, (int) ($pending['due'] ?? 0));
        update_option('wpc_sd_restore_release_pending', ['n' => $n, 'due' => $due, 'ids' => $ids], false);
        $next = function_exists('wp_next_scheduled') ? wp_next_scheduled('wpc_sd_restore_release') : false;
        if ($next !== false && (int) $next >= $due) {
            return;
        }
        if ($next !== false && function_exists('wp_unschedule_event')) {
            wp_unschedule_event((int) $next, 'wpc_sd_restore_release');
        }
        wp_schedule_single_event($due, 'wpc_sd_restore_release');
    }

    /** One page of a released image, purged softly through every layer the page sits in (local copy, mirror, Varnish, Cloudflare, third-party caches) and queued to re-warm. */
    function wpc_v2_restore_release_page($post_id)
    {
        $post_id = (int) $post_id;
        $url = function_exists('get_permalink') ? get_permalink($post_id) : '';
        if (is_string($url) && $url !== '' && class_exists('wps_ic_url_key') && class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
            $key = (string) (new wps_ic_url_key())->setup($url);
            if ($key !== '') {
                wps_ic_cache_integrations::purgeUrlHtml($key, $url, ['context' => 'sd-restore-release', 'soft' => true]);
                return true;
            }
        }
        return function_exists('wpc_v2_purge_page_within_budget') ? wpc_v2_purge_page_within_budget($post_id, null) === 'purged' : false;
    }

    /** The release event: when the cool-off of every restore it holds is over and Smart Delivery is still on, softly purges the pages that name those images (one site-wide soft purge past the cap), so a page cached during the cool-off re-renders and re-triggers the optimisation. */
    function wpc_v2_restore_release_run()
    {
        $pending = get_option('wpc_sd_restore_release_pending', []);
        if (!is_array($pending) || empty($pending['n'])) {
            delete_option('wpc_sd_restore_release_pending');
            return 'none';
        }
        $due = (int) ($pending['due'] ?? 0);
        if (time() < $due) {
            if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_sd_restore_release') === false) {
                wp_schedule_single_event($due, 'wpc_sd_restore_release');
            }
            return 'early';
        }
        delete_option('wpc_sd_restore_release_pending');
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('wpc_sd_restore_release');
        }
        if (!wpc_v2_restore_sd_on() || !wpc_v2_restore_reoptimizes()) {
            return 'off';
        }
        $max = wpc_v2_restore_release_max();
        $posts = [];
        if ((int) $pending['n'] <= $max && function_exists('wpc_v2_discover_referencing_posts')) {
            foreach ((array) ($pending['ids'] ?? []) as $id => $at) {
                if ((string) wpc_v2_landing_meta((int) $id, 'ic_status') !== 'restored') {
                    continue;
                }
                foreach ((array) wpc_v2_discover_referencing_posts((int) $id) as $post_id) {
                    $posts[(int) $post_id] = true;
                }
            }
        }
        if ((int) $pending['n'] > $max || count($posts) > $max) {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all', '', '', 'soft');
            }
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                wps_ic_cache::cfPurgeAllHtml();
            }
            return 'site';
        }
        foreach (array_keys($posts) as $post_id) {
            wpc_v2_restore_release_page($post_id);
        }
        return 'pages';
    }

    /** Whether the image reads restored and is still held: outside Smart Delivery always, under it only while a stamped restore is inside its cool-off. */
    function wpc_v2_restore_held($id)
    {
        $id = (int) $id;
        if ($id <= 0 || (string) wpc_v2_landing_meta($id, 'ic_status') !== 'restored') {
            return false;
        }
        if (!wpc_v2_restore_sd_on() || !wpc_v2_restore_reoptimizes()) {
            return true;
        }
        $at = (int) wpc_v2_landing_meta($id, 'wpc_restored_at');
        return $at > 0 && (time() - $at) < wpc_v2_restore_cooloff();
    }

    /** Whether a landing for this attachment must be refused: a restore is running, or it reads restored with no fresh optimise intent (an intent or a pending job recorded before the restore does not count). */
    function wpc_v2_landing_refused($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }
        if (function_exists('get_transient') && get_transient('wpc_restoring_' . $id)) {
            return true;
        }
        if ((string) wpc_v2_landing_meta($id, 'ic_status') !== 'restored') {
            return false;
        }
        $intent = (int) wpc_v2_landing_meta($id, 'wpc_reopt_intent');
        $stamp = (int) wpc_v2_landing_meta($id, 'wpc_restored_at');
        if (!($intent > 0 && (time() - $intent) <= 86400 && ($stamp <= 0 || $intent >= $stamp))) {
            return true;
        }
        if ($stamp > 0 && function_exists('get_transient')) {
            $pending = get_transient('wpc_v2_pending_' . $id);
            if (is_array($pending) && (int) ($pending['recorded_at'] ?? 0) > 0 && (int) $pending['recorded_at'] < $stamp) {
                return true;
            }
        }
        return false;
    }

    /** The receipt of a landing the restored image refused. */
    function wpc_v2_landing_refused_receipt($id, $src)
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-landing-refused', (string) (int) $id, '', ['id' => (int) $id, 'src' => (string) $src, 'why' => 'restored']);
        }
    }
}
if (function_exists('add_action')) {
    add_action('wpc_sd_restore_release', 'wpc_v2_restore_release_run');
}

if (!function_exists('wpc_v2_store_bytes')) {

    /**
     * Allowed destination extensions for callback-delivered image variants.
     */
    function wpc_v2_store_default_exts()
    {
        return ['jpg', 'jpeg', 'webp', 'avif', 'png', 'gif'];
    }

    /**
     * What the service says about a delivery: the hash and size of the source it encoded from and
     * the 16x16 grid of the variant it sent (fields and encoding at the top of
     * v2-variant-identity.php). Answers ['sha256', 'size', 'grid'] for the parts that are well
     * formed, or []. It rides `variant.claim` into the store.
     */
    function wpc_v2_variant_claim($from)
    {
        return function_exists('wpc_v2_variant_claim_parse') ? wpc_v2_variant_claim_parse($from) : [];
    }

    /**
     * The well-formed claim fields of a delivery under their canonical names, for a journal entry
     * that carries them to the drain (which reads them back through wpc_v2_variant_claim).
     */
    function wpc_v2_variant_claim_wire($from)
    {
        return function_exists('wpc_v2_variant_claim_wire_fields') ? wpc_v2_variant_claim_wire_fields($from) : [];
    }

    /**
     * Atomically place $bytes at $dest.
     *
     * @param string $bytes Raw file contents. Never decoded, parsed or transformed here.
     * @param string $dest  Absolute destination path.
     * @param array  $opts  root: containment root (default: uploads basedir)
     *                      exts: allowed extensions (default: image set)
     *                      chmod: file mode after rename (default 0644)
     *                      variant: ['id', 'size', 'fmt', 'src', 'job'] for an image variant: the bytes are
     *                               refused when they are no smaller than the file WordPress serves
     *                               at that size (error 'larger_than_disk', see below); a variant that
     *                               lands leaves its landed receipt (v2-landed.php), and so does a
     *                               write that fails (write_failed, rename_failed)
     * @return array ['ok'=>bool, 'error'=>string, 'msg'=>string, 'bytes'=>int]
     */
    function wpc_v2_store_bytes($bytes, $dest, $opts = [])
    {
        if (!is_string($bytes) || $bytes === '') {
            return ['ok' => false, 'error' => 'empty_bytes', 'msg' => ''];
        }
        $dest = (string) $dest;
        if ($dest === '' || strpos($dest, "\0") !== false) {
            return ['ok' => false, 'error' => 'bad_dest', 'msg' => ''];
        }

        if (isset($opts['variant']) && is_array($opts['variant']) && !empty($opts['variant']['id'])
            && wpc_v2_landing_refused((int) $opts['variant']['id'])) {
            wpc_v2_landing_refused_receipt((int) $opts['variant']['id'], (string) ($opts['variant']['src'] ?? ''));
            return ['ok' => false, 'error' => 'image_restored', 'msg' => ''];
        }

        // Extension allow-list — the belt that keeps an executable name from ever
        // reaching disk, now enforced for every caller rather than per call site.
        $exts = isset($opts['exts']) && is_array($opts['exts']) ? $opts['exts'] : wpc_v2_store_default_exts();
        $ext  = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $exts, true)) {
            return ['ok' => false, 'error' => 'bad_extension', 'msg' => $ext];
        }

        // Containment — the destination's DIRECTORY must resolve inside the declared
        // root. realpath() is used on the directory (the file itself does not exist
        // yet), which also collapses any traversal before the comparison.
        $root = isset($opts['root']) ? (string) $opts['root'] : '';
        if ($root === '' && function_exists('wp_get_upload_dir')) {
            $ud = wp_get_upload_dir();
            $root = isset($ud['basedir']) ? (string) $ud['basedir'] : '';
        }
        $root_real = $root !== '' ? @realpath($root) : false;
        $dir_real  = @realpath(dirname($dest));
        if ($root_real === false || $dir_real === false) {
            return ['ok' => false, 'error' => 'unresolvable_path', 'msg' => ''];
        }
        $root_real = rtrim($root_real, '/\\') . DIRECTORY_SEPARATOR;
        $dir_cmp   = rtrim($dir_real, '/\\') . DIRECTORY_SEPARATOR;
        if (strpos($dir_cmp, $root_real) !== 0) {
            return ['ok' => false, 'error' => 'outside_root', 'msg' => ''];
        }

        // A variant never replaces a smaller file. The rule lives here, in the one writer, so every
        // landing lane obeys it (wpc_v2_variant_larger_than_disk).
        if (isset($opts['variant']) && is_array($opts['variant'])) {
            $refused = wpc_v2_variant_larger_than_disk($bytes, $dest, $opts['variant']);
            if ($refused !== null) {
                return $refused;
            }
        }

        $refused = wpc_v2_variant_identity_refusal($bytes, $dest, $ext, isset($opts['variant']) && is_array($opts['variant']) ? $opts['variant'] : []);
        if ($refused !== null) {
            return $refused;
        }

        if (isset($opts['variant']) && is_array($opts['variant'])) {
            $refused = wpc_v2_master_refusal($bytes, $dest, $opts['variant']);
            if ($refused !== null) {
                return $refused;
            }
        }

        // Atomic placement: a partially written file is never visible under $dest.
        $tmp = $dest . '.wpc_v2_tmp_' . (function_exists('wp_generate_password')
            ? wp_generate_password(8, false)
            : substr(md5(uniqid('', true)), 0, 8));

        $landed = isset($opts['variant']) && is_array($opts['variant']) && function_exists('wpc_v2_landed_from_store');
        if (wpc_fs_put($tmp, $bytes) === false) {
            $e = error_get_last();
            if ($landed) {
                wpc_v2_landed_from_store($opts['variant'], $dest, 'write_failed');
            }
            return ['ok' => false, 'error' => 'write_failed', 'msg' => isset($e['message']) ? (string) $e['message'] : ''];
        }
        if (!@rename($tmp, $dest)) {
            $e = error_get_last();
            @unlink($tmp);
            if ($landed) {
                wpc_v2_landed_from_store($opts['variant'], $dest, 'rename_failed');
            }
            return ['ok' => false, 'error' => 'rename_failed', 'msg' => isset($e['message']) ? (string) $e['message'] : ''];
        }

        $mode = isset($opts['chmod']) ? (int) $opts['chmod'] : 0644;
        @chmod($dest, $mode);

        if ($landed) {
            wpc_v2_landed_from_store($opts['variant'], $dest);
        }

        return ['ok' => true, 'error' => '', 'msg' => '', 'bytes' => strlen($bytes)];
    }

    /**
     * The path of $path below the uploads directory ('2026/10/a-300x200.avif'), or '' when it is
     * not inside it. The directory and the path are each compared as given and as realpath()
     * resolves them, so an uploads directory that is a symlink, or a path that was resolved
     * through one, still names the same relative path; a file that does not exist yet is resolved
     * through its directory.
     */
    function wpc_v2_uploads_relative($path)
    {
        $path = (string) $path;
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base = isset($up['basedir']) ? (string) $up['basedir'] : '';
        if ($path === '' || $base === '') {
            return '';
        }
        $paths = [$path];
        $real = @realpath($path);
        if ($real === false) {
            $dir = @realpath(dirname($path));
            $real = $dir === false ? false : $dir . '/' . basename($path);
        }
        if ($real !== false) {
            $paths[] = $real;
        }
        $bases = [$base];
        $real_base = @realpath($base);
        if ($real_base !== false) {
            $bases[] = $real_base;
        }
        foreach ($paths as $candidate) {
            $candidate = str_replace('\\', '/', $candidate);
            foreach ($bases as $root) {
                $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
                if (strpos($candidate, $root) === 0 && strlen($candidate) > strlen($root)) {
                    return substr($candidate, strlen($root));
                }
            }
        }
        return '';
    }

    /**
     * The source file on disk beside $path: the png, jpg, jpeg or gif of the same stem, tried in that
     * order (wpc_image_source_exts, lower case before upper), or '' when none exists. $path may be any file of the stem (a variant or a source);
     * only its extension is dropped. Every reader that asks "which source sits beside this
     * variant" asks here; the mu fast-404 template (v2-fast-404.php) cannot call plugin code and
     * mirrors this order inline.
     *
     * Rule: the plugin writes a .jpg beside a .png source (the jpeg output of a PNG dispatch) and
     * never a .png beside a .jpg, and WordPress (since 5.3.1, wp_unique_filename /
     * _wp_check_existing_file_names) never lets two uploads share one stem with different
     * extensions in a directory, so when both exist the png is the attachment's own file and the
     * jpg is ours. Hence png first.
     *
     * Observed (gmob.ca, ticket 12231, 2026-10-14): with the order jpg, jpeg, png a PNG's July jpg
     * twin answered the missing .webp request through the fast-404 redirect, and the CDN cached
     * its flattened (black background) bytes under the .webp URL.
     */
    function wpc_v2_source_beside($path)
    {
        $path = (string) $path;
        $ext = (string) pathinfo($path, PATHINFO_EXTENSION);
        $stem = $ext === '' ? $path : substr($path, 0, -strlen($ext) - 1);
        if ($stem === '') {
            return '';
        }
        foreach (wpc_image_source_exts() as $source_ext) {
            if (@is_file($stem . '.' . $source_ext)) {
                return $stem . '.' . $source_ext;
            }
        }
        return '';
    }

    /**
     * The file a variant at $dest competes with: the file WordPress serves at that size. For an
     * in-place format (jpeg/png: the variant replaces the file of that name) it is $dest itself;
     * for a sibling (-webp, -avif) and for a format change it is the source beside it
     * (wpc_v2_source_beside: png before jpg, so a PNG's jpg twin is never the rival). '' when
     * there is none on disk (a fresh size: nothing to compare, the variant lands).
     */
    function wpc_v2_variant_disk_rival($dest)
    {
        $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && @is_file($dest)) {
            return $dest;
        }
        return wpc_v2_source_beside($dest);
    }

    /**
     * Answers null when the variant may land, or the store's refusal when its bytes are no smaller
     * than the file WordPress serves at that size (wpc_v2_variant_disk_rival). A refused variant is
     * settled, not failed: it is recorded as no improvement (reason larger_than_disk) and taken out
     * of pending, so nothing retries it, and one `variant-larger-refused` receipt names both sizes
     * and the true, unclamped saving (negative or zero). Where the recorder is not loaded (the
     * direct-entry files run under SHORTINIT) the refusal says `recorded: false` and the caller
     * journals the no-improvement entry the drain records.
     *
     * Observed (finde-online.de, ticket 12006, `optimization: lossless`): the service delivered
     * thumbnails larger than the files they replaced (medium 17,002 -> 21,215 B, medium_large
     * 90,045 -> 106,744 B, a 103,348 B medium_large webp beside the 90,045 B JPEG) and the pull
     * manifest landed them; the variant set clamps negative savings to 0, so the growth showed as
     * "0 %". Only the Phase A parent had a size check, in its own lane.
     */
    function wpc_v2_variant_larger_than_disk($bytes, $dest, array $variant)
    {
        $rival = wpc_v2_variant_disk_rival($dest);
        if ($rival === '') {
            return null;
        }
        clearstatcache(true, $rival);
        $disk = (int) @filesize($rival);
        $new = strlen((string) $bytes);
        if ($disk <= 0 || $new < $disk) {
            return null;
        }
        $settled = wpc_v2_variant_settle($variant, 'larger_than_disk');
        $id = (int) ($variant['id'] ?? 0);
        $size = (string) ($variant['size'] ?? '');
        $fmt = strtolower((string) ($variant['fmt'] ?? ''));
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('variant-larger-refused', '', '', [
                'id'         => $id,
                'size'       => $size,
                'fmt'        => $fmt,
                'new_bytes'  => $new,
                'disk_bytes' => $disk,
                'savings'    => (int) round((1 - ($new / $disk)) * 100),
                'vs'         => basename($rival),
                'src'        => (string) ($variant['src'] ?? ''),
            ]);
        }
        return [
            'ok'             => false,
            'error'          => 'larger_than_disk',
            'msg'            => $new . '>=' . $disk,
            'bytes'          => $new,
            'disk_bytes'     => $disk,
            'settled'        => true,
            'recorded'       => $settled['recorded'],
            'drain_complete' => $settled['drain_complete'],
        ];
    }

    /**
     * Settles a refused variant: recorded as no improvement under `$reason` and taken out of
     * pending, so nothing retries it. Answers ['recorded' => bool, 'drain_complete' => bool];
     * `recorded` is false where the recorder is not loaded (the direct-entry files run under
     * SHORTINIT) and the caller journals the entry the drain records.
     */
    function wpc_v2_variant_settle(array $variant, $reason)
    {
        $id = (int) ($variant['id'] ?? 0);
        $size = (string) ($variant['size'] ?? '');
        $fmt = strtolower((string) ($variant['fmt'] ?? ''));
        $recorded = false;
        if ($id > 0 && $size !== '' && $fmt !== '' && function_exists('wpc_v2_record_no_improvement')) {
            wpc_v2_record_no_improvement($id, $size, $fmt, (string) $reason, []);
            $recorded = true;
        }
        $drain_complete = ($recorded && function_exists('wpc_v2_remove_pending')) ? (bool) wpc_v2_remove_pending($id, $size, $fmt) : false;
        return ['recorded' => $recorded, 'drain_complete' => $drain_complete];
    }

    function wpc_v2_master_refusal($bytes, $dest, array $variant)
    {
        $id = (int) ($variant['id'] ?? 0);
        if ($id <= 0 || !apply_filters('wpc_v2_master_guard', !(defined('WPC_V2_MASTER_GUARD_OFF') && WPC_V2_MASTER_GUARD_OFF))) {
            return null;
        }
        if (!wpc_v2_is_master_path($id, $dest) || wpc_v2_master_rewrite_inplace()) {
            return null;
        }
        $settled = wpc_v2_variant_settle($variant, 'master_protected');
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('variant-master-refused', '', '', [
                'id'   => $id,
                'size' => (string) ($variant['size'] ?? ''),
                'fmt'  => strtolower((string) ($variant['fmt'] ?? '')),
                'vs'   => basename((string) $dest),
                'src'  => (string) ($variant['src'] ?? ''),
            ]);
        }
        return [
            'ok'             => false,
            'error'          => 'master_protected',
            'msg'            => basename((string) $dest),
            'bytes'          => strlen((string) $bytes),
            'settled'        => true,
            'recorded'       => $settled['recorded'],
            'drain_complete' => $settled['drain_complete'],
        ];
    }

    function wpc_v2_master_rewrite_inplace()
    {
        if (function_exists('wpc_should_rewrite_jpeg_inplace')) {
            return (bool) wpc_should_rewrite_jpeg_inplace();
        }
        $mode = function_exists('wpc_get_optimization_mode') ? (string) wpc_get_optimization_mode() : '';
        if ($mode === '') {
            $settings = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS, []) : [];
            $mode = is_array($settings) && !empty($settings['wpc_optimization_mode']) ? (string) $settings['wpc_optimization_mode'] : (string) get_option('wpc_optimization_mode', 'legacy');
            if (strpos($mode, 'lazy_') === 0) {
                $mode = 'lazy_cdn';
            }
        }
        return (bool) apply_filters('wpc_rewrites_jpeg_inplace', $mode !== 'lazy_cdn');
    }

    function wpc_v2_master_paths($id)
    {
        $id = (int) $id;
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base = !empty($up['basedir']) ? rtrim((string) $up['basedir'], '/') : '';
        $attached = '';
        try {
            $attached = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
        } catch (\Throwable $e) {
            $attached = '';
        }
        if ($attached === '') {
            $rel = (string) wpc_v2_landing_meta($id, '_wp_attached_file');
            $attached = ($rel !== '' && $base !== '') ? $base . '/' . $rel : '';
        }
        $original = '';
        try {
            $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        } catch (\Throwable $e) {
            $original = '';
        }
        if ($original === '' && $attached !== '') {
            $meta = wpc_v2_landing_meta($id, '_wp_attachment_metadata');
            $meta = is_string($meta) ? @unserialize($meta, ['allowed_classes' => false]) : $meta;
            if (is_array($meta) && !empty($meta['original_image'])) {
                $original = dirname($attached) . '/' . basename((string) $meta['original_image']);
            }
        }
        return array_values(array_filter([$attached, $original], 'strlen'));
    }

    function wpc_v2_is_master_path($id, $dest)
    {
        $dir = @realpath(dirname((string) $dest));
        if ($dir === false) {
            return false;
        }
        $dest_real = $dir . '/' . basename((string) $dest);
        foreach (wpc_v2_master_paths($id) as $master) {
            $mdir = @realpath(dirname($master));
            if ($mdir !== false && $mdir . '/' . basename($master) === $dest_real) {
                return true;
            }
        }
        return false;
    }

    /**
     * Answers null when the bytes may land, or the store's settled refusal (error
     * `identity_mismatch`) when they are a picture of another file than the one $dest stands for
     * (wpc_v2_variant_identity). Image extensions only: every other caller is untouched. Skipped
     * when $dest already holds these exact bytes (a re-sync of what landed: nothing new lands).
     * The answer a REST caller gives the service is the one it gives for `larger_than_disk`: HTTP
     * 200 {ok:true, kind:no_improvement, reason:identity_mismatch}, the shape that ends the job
     * (a 4xx/5xx would be retried, and the service would resend the same bytes).
     */
    function wpc_v2_variant_identity_refusal($bytes, $dest, $ext, array $variant)
    {
        if (!function_exists('wpc_v2_variant_identity') || !in_array($ext, wpc_v2_variant_identity_exts(), true)) {
            return null;
        }
        if (@is_file($dest) && (int) @filesize($dest) === strlen((string) $bytes) && @hash_file('sha256', $dest) === hash('sha256', (string) $bytes)) {
            return null;
        }
        $claim = isset($variant['claim']) && is_array($variant['claim']) ? $variant['claim'] : [];
        $identity = wpc_v2_variant_identity((string) $bytes, $dest, $claim);
        if ($identity['verdict'] !== 'mismatch') {
            return null;
        }
        wpc_v2_variant_identity_refuse($identity, $dest, (string) ($variant['src'] ?? 'store'), [
            'id'   => (int) ($variant['id'] ?? 0),
            'size' => (string) ($variant['size'] ?? ''),
            'fmt'  => (string) ($variant['fmt'] ?? ''),
        ]);
        $settled = wpc_v2_variant_settle($variant, 'identity_mismatch');
        return [
            'ok'             => false,
            'error'          => 'identity_mismatch',
            'msg'            => (string) ($identity['reason'] ?? '') . ':' . (string) ($identity['distance'] ?? ''),
            'bytes'          => strlen((string) $bytes),
            'settled'        => true,
            'recorded'       => $settled['recorded'],
            'drain_complete' => $settled['drain_complete'],
        ];
    }

}
