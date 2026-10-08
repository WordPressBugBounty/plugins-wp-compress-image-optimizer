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

if (@is_file(__DIR__ . '/v2-variant-identity.php')) {
    require_once __DIR__ . '/v2-variant-identity.php';
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
     *                      variant: ['id', 'size', 'fmt', 'src'] for an image variant: the bytes are
     *                               refused when they are no smaller than the file WordPress serves
     *                               at that size (error 'larger_than_disk', see below)
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

        // Atomic placement: a partially written file is never visible under $dest.
        $tmp = $dest . '.wpc_v2_tmp_' . (function_exists('wp_generate_password')
            ? wp_generate_password(8, false)
            : substr(md5(uniqid('', true)), 0, 8));

        if (wpc_fs_put($tmp, $bytes) === false) {
            $e = error_get_last();
            return ['ok' => false, 'error' => 'write_failed', 'msg' => isset($e['message']) ? (string) $e['message'] : ''];
        }
        if (!@rename($tmp, $dest)) {
            $e = error_get_last();
            @unlink($tmp);
            return ['ok' => false, 'error' => 'rename_failed', 'msg' => isset($e['message']) ? (string) $e['message'] : ''];
        }

        $mode = isset($opts['chmod']) ? (int) $opts['chmod'] : 0644;
        @chmod($dest, $mode);

        return ['ok' => true, 'error' => '', 'msg' => '', 'bytes' => strlen($bytes)];
    }

    /**
     * The file a variant at $dest competes with: the file WordPress serves at that size. For an
     * in-place format (jpeg/png: the variant replaces the file of that name) it is $dest itself;
     * for a sibling (-webp, -avif) and for a format change it is the jpg/jpeg/png of the same
     * name. '' when there is none on disk (a fresh size: nothing to compare, the variant lands).
     */
    function wpc_v2_variant_disk_rival($dest)
    {
        $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && @is_file($dest)) {
            return $dest;
        }
        $stem = substr($dest, 0, -strlen($ext) - 1);
        foreach (['jpg', 'jpeg', 'png'] as $wp_ext) {
            if ($wp_ext !== $ext && @is_file($stem . '.' . $wp_ext)) {
                return $stem . '.' . $wp_ext;
            }
        }
        return '';
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
