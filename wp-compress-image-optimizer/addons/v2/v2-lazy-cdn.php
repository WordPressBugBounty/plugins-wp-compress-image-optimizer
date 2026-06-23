<?php
/**
 * Lazy-CDN ingest handler.
 *
 * Handles manifest entries with tags.source='lazycdn'. Unlike the journal-merge
 * path (keyed on a real attachment ID, writes ic_local_variants postmeta), these:
 *
 *   - Aren't tied to a WP attachment — origin_url is the only handle
 *   - Are pure cache-fill writes to disk (postmeta written separately, see below)
 *   - Derive their dest filename from origin_url + sizeLabel + format, e.g.
 *       https://site.com/.../uploads/2025/01/foo.jpg + "w300h200" + "avif"
 *       → wp-content/uploads/2025/01/foo-300x200.avif
 *
 * Revert: delete this file + drop the lazycdn routing in
 * wpc_v2_pull_manifest_queue_for_drain (v2-pull-manifest.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sha256 dedup transient. The same variant can arrive via push (bg_swap_single)
 * AND pull (manifest); a short-TTL transient keyed on the sha256 prefix gives us
 * single-process semantics across both. 10-minute window matches the LS contract.
 * (Transients route to Redis when an object cache is present, else wp_options.)
 */
if (!function_exists('wpc_v2_sha256_dedup_seen')) {
    function wpc_v2_sha256_dedup_seen($sha256)
    {
        if (!is_string($sha256) || strlen($sha256) < 16) return false;
        return (bool) get_transient('wpc_v2_dedup_' . substr($sha256, 0, 16));
    }
}
if (!function_exists('wpc_v2_sha256_dedup_mark')) {
    function wpc_v2_sha256_dedup_mark($sha256, $ttl_s = 600)
    {
        if (!is_string($sha256) || strlen($sha256) < 16) return;
        $ttl = (int) apply_filters('wpc_v2_sha256_dedup_ttl_s', $ttl_s);
        set_transient('wpc_v2_dedup_' . substr($sha256, 0, 16), 1, max(60, $ttl));
    }
}

/**
 * Sweep all wpc_v2_dedup_<sha> transients. Called on restoreV4 so a restore
 * immediately allows re-ingest of the same bytes — otherwise the same sha256
 * stays blocked for up to 10 minutes. Idempotent.
 *
 * Direct DB query because delete_transient can't do wildcards, and iterating
 * every option to prefix-filter is slower than one LIKE delete.
 */
if (!function_exists('wpc_v2_lazy_cdn_clear_dedup_transients')) {
    function wpc_v2_lazy_cdn_clear_dedup_transients()
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return 0;
        $like_value = $wpdb->esc_like('_transient_wpc_v2_dedup_') . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_wpc_v2_dedup_') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted_value = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_value
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted_timeout = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_timeout
        ));
        return $deleted_value;
    }
}

if (!function_exists('wpc_v2_adaptive_variant_suffix')) {
    /**
     * Single source of truth for an ADAPTIVE (non-registered) variant's filename suffix.
     * The WRITE derive (wpc_v2_lazy_cdn_derive_abs_path) and the four READ srcset
     * derivations (rewriteLogic.php picture + universal × avif + webp) ALL call this, so
     * the on-disk name and the requested URL stay symmetric by construction. Drift here
     * means a valid variant 404s and silently degrades to a lower format — the bug class
     * this area has repeatedly hit. (Co-located with the write because this file loads in
     * the callback context where rewriteLogic isn't, as well as on the front-end render.)
     *
     * Returns "-{W}x{H}", H from the source aspect ratio, matching WP's {W}x{H} sub-size
     * convention so the service can map files to dimensions. It's an identifier only —
     * orch's actual encoded height may be ±1px, harmless since every READ is file_exists-
     * gated (named by this fn on write, looked up by it on read). Falls back to legacy
     * "-{W}w" only when no aspect is available; write+read pass the SAME $meta so they
     * degrade identically — still symmetric, never a mismatch.
     *
     * @param int        $width  adaptive width (srcset descriptor / orch label width)
     * @param array|bool $meta   wp_get_attachment_metadata() for the source attachment
     * @return string            e.g. "-1366x1855", or "-1366w" if aspect unknown
     */
    function wpc_v2_adaptive_variant_suffix($width, $meta)
    {
        $width = (int) $width;
        if ($width <= 0) return '';
        $mw = (is_array($meta) && !empty($meta['width']))  ? (int) $meta['width']  : 0;
        $mh = (is_array($meta) && !empty($meta['height'])) ? (int) $meta['height'] : 0;
        if ($mw > 0 && $mh > 0) {
            $h = (int) round($width * $mh / $mw);
            if ($h > 0) return '-' . $width . 'x' . $h;
        }
        return '-' . $width . 'w';
    }
}

if (!function_exists('wpc_v2_lazy_purge_enqueue')) {
    // Coalesced variant-landed CDN purge. Each landed URL is enqueued; on shutdown the
    // queue flushes as ONE deduped, chunked, BLOCKING wpc_customer_purge — replacing ~22
    // per-image fire-and-forget purges that hammered CF+orch un-throttled and could fail to
    // flush if the drain/cron process ended first (interim stayed cached until its 60s TTL).
    // Blocking is safe: the drain runs past fastcgi_finish_request (no user latency), each
    // POST is timeout-bounded, and the flush extends its own time budget + caps chunks so it
    // can't hang or be killed mid-flush. Reason 'variant_landed' is orch-allowlisted.
    // $flush=true drains+fires; otherwise pass a URL to enqueue.
    function wpc_v2_lazy_purge_enqueue($url = null, $flush = false)
    {
        static $queue  = [];
        static $hooked = false;
        if ($flush) {
            if (empty($queue) || !function_exists('wpc_customer_purge') || !function_exists('wpc_v2_get_apikey')) {
                $queue = [];
                return;
            }
            $urls = array_values(array_unique($queue));
            $queue = [];
            $key = (string) wpc_v2_get_apikey();
            if ($key === '') {
                return;
            }
            // Chunk so one pathological burst can't build a megabyte payload; 200/chunk
            // matches the CF chunking floor. Extend our own time budget so a multi-chunk burst
            // under 429 backoff isn't killed mid-flush; cap chunks as a runaway backstop — any
            // overflow self-heals on the next landing's purge or the interim's own TTL.
            if (function_exists('set_time_limit')) { @set_time_limit(120); }
            $chunks = array_chunk($urls, 200);
            if (count($chunks) > 8) {
                error_log('[WPC LazyCDN] purge flush capped: ' . count($chunks) . ' chunks -> 8 (' . count($urls) . ' urls)');
                $chunks = array_slice($chunks, 0, 8);
            }
            foreach ($chunks as $chunk) {
                wpc_customer_purge($key, 'urls', $chunk, 'variant_landed', true);
            }
            return;
        }
        if (!is_string($url) || $url === '') {
            return;
        }
        $queue[] = $url;
        if (!$hooked && function_exists('add_action')) {
            $hooked = true;
            // Priority 9 so it runs before the late drain-stat/option writers; the drain has
            // finished its disk work by shutdown anyway.
            add_action('shutdown', function () { wpc_v2_lazy_purge_enqueue(null, true); }, 9);
        }
    }
}

if (!function_exists('wpc_v2_enqueue_landed_purge')) {
    /**
     * Enqueue the landed-variant CDN purge for an on-disk variant AND its format siblings.
     *
     * The page references a NATURAL URL the edge negotiates by Accept: in edge mode the <img>
     * src is the `.webp` URL (Bunny OTF-upgrades it to avif for avif-Accept); in <picture> mode
     * the avif <source> is `.avif`. So when the `.avif` lands on disk, the visitor is still hitting
     * the `.webp` URL whose avif-bucket the edge has cached as the PRE-landing OTF interim —
     * purging only the exact landed path leaves that stale and the visitor waits out the full edge
     * TTL (~60s). Purging the `-WxH` format siblings (avif + webp, plus the landed file) clears the
     * negotiated URL so the landed variant serves on the next request. Idempotent: the enqueue
     * coalesces + flushes ONCE on shutdown (wpc_v2_lazy_purge_enqueue).
     *
     * @param string $abs_path absolute path of the just-landed variant file (under uploads).
     */
    function wpc_v2_enqueue_landed_purge($abs_path)
    {
        if (!function_exists('wpc_v2_lazy_purge_enqueue') || !function_exists('wp_get_upload_dir')) return;
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl']) || strpos((string) $abs_path, (string) $up['basedir']) !== 0) return;
        $base_rel = function_exists('wp_make_link_relative')
            ? wp_make_link_relative($up['baseurl'])
            : (string) wp_parse_url($up['baseurl'], PHP_URL_PATH);
        $rel = $base_rel . substr($abs_path, strlen($up['basedir']));
        if ($rel === '' || !preg_match('/\.(avif|webp|jpe?g|png)$/i', $rel)) return;
        // The landed file + the next-gen format siblings of the same -WxH (the negotiated URL the
        // browser hits). Same-ext jpg/png is unaffected by an avif/webp landing, so it's left alone.
        $targets = [$rel];
        foreach (['avif', 'webp'] as $ext) {
            $sib = preg_replace('/\.(avif|webp|jpe?g|png)$/i', '.' . $ext, $rel);
            if ($sib && $sib !== $rel) $targets[] = $sib;
        }
        foreach (array_unique($targets) as $t) {
            wpc_v2_lazy_purge_enqueue($t);
        }
    }
}

if (!function_exists('wpc_v2_lazy_resolve_attachment')) {
    // Robust attachment resolver. attachment_url_to_postid() is flaky (guid/_wp_attached_file
    // dependent) and returns 0 for some un-scaled origins → no $meta → the derive can't find
    // the REGISTERED sub-size → falls to the DEAD `-{W}w` scheme, landing e.g. `-768w.avif`
    // which the READ side NEVER requests (it emits the WP-registered `-768x503`) → bytes on
    // disk, wrong name, perpetual 404. So: try url_to_postid (clean → -scaled), then a direct
    // _wp_attached_file query on the year/month relative path so meta loads and the
    // registered-name lookup fires.
    function wpc_v2_lazy_resolve_attachment($origin_url, $relative = '')
    {
        if (!function_exists('attachment_url_to_postid')) return 0;
        $clean = preg_replace('/\?.*$/', '', (string) $origin_url);
        $id = (int) attachment_url_to_postid($clean);
        if ($id > 0) return $id;
        // -scaled counterpart (WP stores -scaled as the attached file when scaled at upload)
        $scaled = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean);
        if ($scaled !== $clean) {
            $id = (int) attachment_url_to_postid($scaled);
            if ($id > 0) return $id;
        }
        // Direct query on _wp_attached_file (the canonical stored relative path) — robust
        // where url_to_postid's guid/cache path misses. Also try the -scaled relative.
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && $relative !== '') {
            $rel_clean = preg_replace('/\?.*$/', '', $relative);
            foreach ([$rel_clean, preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $rel_clean)] as $cand) {
                $pid = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
                    $cand
                ));
                if ($pid > 0) return $pid;
            }
        }
        return 0;
    }
}

if (!function_exists('wpc_v2_lazy_ensure_dims')) {
    // Guarantee width/height so the adaptive-suffix helper emits `-{W}x{H}` (the live READ
    // scheme), NEVER the dead `-{W}w`. When $meta lacks dims, read them off the on-disk
    // source (always present). Write path only — one getimagesize on a cache-fill, not render.
    function wpc_v2_lazy_ensure_dims($meta, $src_abs)
    {
        if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) return $meta;
        if ($src_abs && @is_file($src_abs)) {
            $d = @getimagesize($src_abs);
            if (is_array($d) && !empty($d[0]) && !empty($d[1])) {
                $m = is_array($meta) ? $meta : [];
                $m['width']  = (int) $d[0];
                $m['height'] = (int) $d[1];
                return $m;
            }
        }
        return $meta;
    }
}

if (!function_exists('wpc_v2_lazy_outcome')) {
    // Record the exact ingest outcome so the drain can tell REAL disk writes from
    // ack-without-write. The drain's `ingested` counter increments on ANY truthy return, but
    // several returns ack WITHOUT writing (consumer/multisite/restored/dedup) — so ingested:N
    // never meant N files on disk. Surfaced as debug.last_ingest_outcome.
    function wpc_v2_lazy_outcome($reason)
    {
        $h = get_option('wpc_v2_ingest_outcomes', []);
        if (!is_array($h)) $h = [];
        $h[$reason] = (isset($h[$reason]) ? (int) $h[$reason] : 0) + 1;
        $h['_last'] = $reason; $h['_t'] = time();
        update_option('wpc_v2_ingest_outcomes', $h, false);
        return $reason;
    }
}
if (!function_exists('wpc_v2_lazy_cdn_derive_abs_path')) {
    /**
     * Derive the on-disk absolute path for a lazy_cdn variant.
     * Returns ['ok'=>bool, 'abs_path'=>string, 'reason'=>string?].
     *
     * @param string     $origin_url URL of the source JPG, under uploads.
     * @param string     $size_label orch's size label (shapes below).
     * @param string     $format     'avif' | 'webp' | 'jpeg' | 'jpg' | 'png'.
     * @param array      $entry      full manifest entry; if it carries 'filename'
     *                               we prefer that over deriving from size_label.
     *
     * Supported size_label shapes, tried in order:
     *   1. entry['filename'] present   use it directly as basename
     *   2. "original" / ""             no dimension suffix
     *   3. "scaled"                    -scaled
     *   4. "w{W}h{H}" / "{W}x{H}"      -{W}x{H}
     *   5. "{N}w" (browser srcset)     match a WP sub-size by width; fall back to
     *                                  -scaled if width == $meta['width']; else reject
     *   6. Known WP sub-size names     use $meta['sizes'][name]['file'] basename
     *
     * Path-traversal defense: origin_url must sit inside the uploads basedir, the
     * size_label must parse, and the COMPOSED path must start with the basedir's
     * realpath. We can't realpath the target (it doesn't exist yet — we're creating
     * it), so we compose manually and check the dir's realpath instead.
     */
    function wpc_v2_lazy_cdn_derive_abs_path($origin_url, $size_label, $format, $entry = [])
    {
        if (!is_string($origin_url) || $origin_url === '') {
            return ['ok' => false, 'reason' => 'missing_origin_url'];
        }
        if (!is_string($format) || !in_array($format, ['avif', 'webp', 'jpeg', 'jpg', 'png'], true)) {
            return ['ok' => false, 'reason' => 'invalid_format'];
        }

        $upload = wp_get_upload_dir();
        if (empty($upload['basedir'])) {
            return ['ok' => false, 'reason' => 'no_upload_basedir'];
        }

        // Parse origin_url → relative path under site
        $parsed = wp_parse_url($origin_url);
        if (empty($parsed['path'])) {
            return ['ok' => false, 'reason' => 'unparsable_origin_url'];
        }

        // Verify host belongs to this site OR the CDN zone (defends against a forged
        // origin_url). The cdn-zone host is legitimate: rewriteLogic emits picture-source
        // srcset with `u:cdn-zone/...` (fixes 302→origin from orch pods that can't reach the
        // customer origin), and orch then stores cdn-zone as the entry's origin_url. Both
        // hosts resolve to the same physical path under uploads — the zone is a passthrough
        // pull-zone. (This used to hard-reject host_mismatch and the entries piled up.)
        $site_host   = wp_parse_url(site_url(), PHP_URL_HOST);
        $origin_host = isset($parsed['host']) ? (string) $parsed['host'] : '';
        if ($origin_host !== '' && $site_host !== '' && strcasecmp($origin_host, $site_host) !== 0) {
            // Accept ALL of this site's zone identities, not either-or: a custom cname can
            // coexist with a legacy zapwp host, and entries can carry either. The host check
            // is belt-and-suspenders anyway (entries arrive over the HMAC-signed pull channel
            // keyed to this site's apikey); the real write-path defense is the basedir prefix
            // check below, which is untouched.
            $zone_ok = false;
            if (function_exists('get_option')) {
                // Accept the SAME zone host the rewriter EMITS. On CF-integration sites that
                // cname lives in WPS_IC_CF_CNAME, not ic_custom_cname — so without it the
                // derive rejected the very host the plugin writes into its own markup, stranding
                // the whole backfill. Normalize each candidate (strip scheme/path) so a stored
                // "https://host/" still matches the bare host.
                $wpc_zone_cands = [(string) get_option('ic_custom_cname'), (string) get_option('ic_cdn_zone_name')];
                if (defined('WPS_IC_CF_CNAME')) {
                    $wpc_zone_cands[] = (string) get_option(WPS_IC_CF_CNAME);
                }
                foreach ($wpc_zone_cands as $zh) {
                    if ($zh === '') {
                        continue;
                    }
                    $zh_host = (string) wp_parse_url(strpos($zh, '//') === false ? 'https://' . $zh : $zh, PHP_URL_HOST);
                    if ($zh_host !== '' && strcasecmp($origin_host, $zh_host) === 0) {
                        $zone_ok = true;
                        break;
                    }
                }
            }
            // Legacy/rotated zapwp zone hosts (reconnect history) — same passthrough class;
            // orch derives origin_url from whichever host the zone registered under at encode time.
            if (!$zone_ok && preg_match('/\.zapwp\.com$/i', $origin_host)) {
                $zone_ok = true;
            }
            if (!$zone_ok) {
                return ['ok' => false, 'reason' => 'host_mismatch', 'origin_host' => $origin_host];
            }
            // Matched the cdn-zone — normalize origin_url back to the site host so downstream
            // attachment_url_to_postid lookups (which compare against WP-stored, site-host URLs)
            // don't resolve to 0 → empty ic_local_variants writes. Path is identical under both
            // hosts so the swap is byte-exact; rtrim guards a trailing-slash site_url filter.
            $origin_url = preg_replace('#^https?://[^/]+#', rtrim(site_url(), '/'), $origin_url);
            // Re-parse so $parsed reflects the swap (only $parsed['path'] is used below).
            $parsed = wp_parse_url($origin_url);
            if (empty($parsed['path'])) {
                return ['ok' => false, 'reason' => 'unparsable_origin_url_post_normalize'];
            }
        }

        // Place the file under basedir, mirroring the URL path after baseurl's path.
        $baseurl_path = (string) wp_parse_url($upload['baseurl'], PHP_URL_PATH);
        $baseurl_path = trim($baseurl_path, '/');  // e.g. "wp-content/uploads"
        $url_path     = trim((string) $parsed['path'], '/');  // e.g. "wp-content/uploads/2025/01/foo.jpg"

        // origin_url MUST sit inside uploads basedir's URL space.
        if ($baseurl_path !== '' && strpos($url_path, $baseurl_path) !== 0) {
            return ['ok' => false, 'reason' => 'origin_outside_uploads'];
        }
        // Strip the baseurl prefix → "2025/01/foo.jpg"
        $relative = $baseurl_path !== ''
            ? ltrim(substr($url_path, strlen($baseurl_path)), '/')
            : $url_path;

        if ($relative === '') {
            return ['ok' => false, 'reason' => 'empty_relative_path'];
        }

        // Reject path traversal (defense-in-depth; wp_parse_url should already normalize this)
        if (strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
            return ['ok' => false, 'reason' => 'path_traversal_attempt'];
        }

        // Split into dir + basename(no-ext)
        $dir      = ltrim(dirname($relative), '/.');  // "" if no subdir
        $basename = basename($relative);
        $base_no_ext = preg_replace('/\.[^.]+$/', '', $basename);
        if ($base_no_ext === '' || $base_no_ext === null) {
            return ['ok' => false, 'reason' => 'empty_basename'];
        }

        // Prefer an explicit basename from the entry over deriving from size_label: it
        // sidesteps size_label parsing ambiguity and lets orch dictate the canonical sub-size
        // name (so the picture filter's natural-URL lookup finds it). Accept either `filename`
        // or `rendition_filename` (CDN's exact requested basename + ext, correct for scaled +
        // hard-crops), at top level OR in tags. First non-empty wins.
        $explicit_filename = '';
        $fn_candidate = '';
        foreach (['filename', 'rendition_filename'] as $fk) {
            if (is_array($entry) && !empty($entry[$fk])) { $fn_candidate = (string) $entry[$fk]; break; }
            if (is_array($entry) && isset($entry['tags'][$fk]) && $entry['tags'][$fk] !== '') { $fn_candidate = (string) $entry['tags'][$fk]; break; }
        }
        if ($fn_candidate !== '') {
            $candidate = basename((string) $fn_candidate); // strip any directory
            $expected_ext = ($format === 'jpeg') ? 'jpg' : $format;
            // We write entry.filename to disk verbatim, so this HMAC-verified value is treated
            // as hostile/buggy. A naive "ends in .avif" check admits the double-extension class
            // (shell.php.avif → mod_mime handler-walk RCE / stored XSS) and arbitrary-basename
            // overwrite inside uploads (wp-config.avif, index.avif). Contract: a real rendition
            // is ALWAYS {originbase} (natural) or {originbase}-{W}x{H} (sized), so we compute
            // the remainder AFTER the origin base — a legit suffix ('-1024x448' or '') is
            // dot-free, while any appended extension puts a dot in the remainder and is rejected.
            // The origin base's own dots (timestamps) sit outside the remainder, so they pass —
            // this beats an ext denylist because it blocks ALL appended segments, not just named
            // ones. Plus: no traversal/null/slash, last segment IS the format ext, the prefix
            // shares the origin base (anti-overwrite), not a Windows reserved stem. Anything
            // malformed → leave explicit_filename empty → fall to the safe derive below.
            $dot  = strrpos($candidate, '.');
            $stem = $dot !== false ? substr($candidate, 0, $dot) : '';
            $rem  = null; // the part of the stem after the origin base, or '' for natural
            if ($stem === $base_no_ext) {
                $rem = '';
            } elseif ($base_no_ext !== '' && strpos($stem, $base_no_ext . '-') === 0) {
                $rem = substr($stem, strlen($base_no_ext)); // e.g. "-1024x448"
            }
            if ($candidate !== ''
                && strpos($candidate, '..') === false
                && strpos($candidate, "\0") === false
                && strpos($candidate, '/') === false
                && strpos($candidate, '\\') === false
                && $dot !== false && $dot > 0
                && strcasecmp(substr($candidate, $dot + 1), $expected_ext) === 0       // last segment IS the format ext
                && $rem !== null                                                       // stem shares the origin base (anti-overwrite)
                && strpos($rem, '.') === false                                         // suffix dot-free → kills appended *.php.avif
                && !preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $stem)) {
                $explicit_filename = $candidate;
            }
        }

        $out_ext = ($format === 'jpeg') ? 'jpg' : $format;

        // SIZED origin_url passthrough: when the origin basename ALREADY carries -{W}x{H} and
        // the label is that same width's {N}w, the origin IS the target — append nothing.
        // Otherwise the {N}w branch's registered-size lookup (which can't know sized names)
        // degrades to -{N}w and produces double-suffixed orphans (…-1008x1368-1008w.avif).
        // Rides the explicit-filename lane so the common compose + basedir checks still apply.
        if ($explicit_filename === ''
            && preg_match('/-(\d{2,4})x(\d{2,4})$/', $base_no_ext, $szm)
            && is_string($size_label)
            && preg_match('/^(?:w)?(\d{2,4})w?$/i', trim($size_label), $szl)
            && (int) $szl[1] === (int) $szm[1]) {
            $explicit_filename = $base_no_ext . '.' . $out_ext;
        }

        if ($explicit_filename !== '') {
            $filename = $explicit_filename;
        } else {
            // Derive the dimensions suffix from sizeLabel (shapes documented on the function).
            $suffix = '';
            $label_lc = is_string($size_label) ? strtolower(trim($size_label)) : '';
            if ($label_lc === '' || $label_lc === 'original') {
                $suffix = '';
            } elseif ($label_lc === 'scaled') {
                $suffix = '-scaled';
            } elseif (preg_match('/^w(\d+)h(\d+)$/i', $size_label, $m) || preg_match('/^(\d+)x(\d+)$/', $size_label, $m)) {
                // Width-AND-height shape. Match against WP metadata FIRST so canonical
                // filenames are used (e.g. a scaled main becomes -scaled, which the picture
                // filter can pick up — emitting the literal -{W}x{H} for a scaled dim that
                // isn't in $meta['sizes'] would never be re-derived on read). Literal suffix
                // only as the last resort.
                $w = (int) $m[1];
                $h = (int) $m[2];
                $attachment_id = wpc_v2_lazy_resolve_attachment($origin_url, $relative);
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;
                // When the attachment can't be resolved, populate width/height from the on-disk
                // source so the main-width / scaled-parent matches below still fire → the full-size
                // image gets the NATURAL name the page requests (no-srcset images request the bare
                // .avif), not a -{W}x{H} that 404s forever. No 'file'/'sizes' keys, so only the
                // dim-based matches run; sub-sizes fall to the formula helper.
                if (!is_array($meta)) {
                    $dd = wpc_v2_lazy_ensure_dims([], $upload['basedir'] . '/' . $relative);
                    $meta = !empty($dd['width']) ? $dd : false;
                }
                $resolved = false;
                if (is_array($meta)) {
                    // Match against main file (scaled or original)
                    if (isset($meta['width'], $meta['height']) && (int) $meta['width'] === $w && (int) $meta['height'] === $h) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        if ($main_file && stripos($main_file, '-scaled.') !== false) {
                            $suffix = '-scaled';
                        }
                        // else: empty suffix (variant of un-scaled main)
                        $resolved = true;
                    }
                    // Match against sub-sizes
                    if (!$resolved && isset($meta['sizes']) && is_array($meta['sizes'])) {
                        foreach ($meta['sizes'] as $sz) {
                            if (!is_array($sz) || empty($sz['file']) || empty($sz['width']) || empty($sz['height'])) continue;
                            if ((int) $sz['width'] === $w && (int) $sz['height'] === $h) {
                                $sub_base = basename((string) $sz['file']);
                                $sub_no_ext = preg_replace('/\.[^.]+$/', '', $sub_base);
                                if ($sub_no_ext !== '' && $sub_no_ext !== null) {
                                    $base_no_ext = $sub_no_ext;
                                    $suffix = '';
                                    $resolved = true;
                                    break;
                                }
                            }
                        }
                    }
                    // Scaled-PARENT recognition. If neither exact-dims nor a sub-size matched but
                    // the label meets or exceeds the scaled main in EITHER axis, it can only be the
                    // parent — nothing on disk is larger than the scaled main (the READ ladder clamps
                    // adaptive widths to it). OR (not AND) also catches an EXIF-rotation axis swap
                    // (orch "2560x1887" vs meta "1887x2560"). Name it to match the main file so
                    // WRITE == READ, not an unservable literal -{W}x{H} the READ path never derives.
                    if (!$resolved && isset($meta['width'], $meta['height'])
                        && (int) $meta['width'] > 0 && (int) $meta['height'] > 0
                        && ($w >= (int) $meta['width'] || $h >= (int) $meta['height'])) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        $suffix = ($main_file !== '' && stripos($main_file, '-scaled.') !== false) ? '-scaled' : '';
                        $resolved = true;
                    }
                }
                if (!$resolved) {
                    // Adaptive {W}x{H}: derive H from W via the shared helper (NOT the label's own
                    // height), so it matches the READ side, which only ever has the width descriptor.
                    // (The label H is discarded for the NAME; content is orch's actual, file_exists-
                    // gated, so ±-naming is harmless.) ensure_dims first so it can never fall to the
                    // dead, READ-abandoned -{W}w.
                    $meta = wpc_v2_lazy_ensure_dims($meta, $upload['basedir'] . '/' . $relative);
                    $suffix = wpc_v2_adaptive_variant_suffix($w, $meta);
                }
            } elseif (preg_match('/^w(\d+)$/i', $size_label, $m) || preg_match('/^(\d+)w$/i', $size_label, $m)) {
                // Width-only descriptor (browser srcset format). Accept both suffix-w (`1887w`)
                // and prefix-w (`w1887`) — orch ships prefix-w on staging-2 though the spec was
                // suffix-w; $m[1] is the width either way. Resolution priority:
                //   1. match $meta['width'] → -scaled (if main file is scaled)
                //   2. match a sub-size width → use its basename (keeps the crop dims)
                //   3. neither → accept anyway with -{N}w, so any width the CDN was asked to
                //      encode is cached under a stable name; future picture renders can pick
                //      these up for srcset entries beyond the WP sub-size ladder.
                $width = (int) $m[1];
                $attachment_id = wpc_v2_lazy_resolve_attachment($origin_url, $relative);
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;
                // Disk-dims fallback (see {W}x{H} branch): un-resolvable attachment → populate
                // dims so the main-width match names the full image naturally; sub-sizes → helper.
                if (!is_array($meta)) {
                    $dd = wpc_v2_lazy_ensure_dims([], $upload['basedir'] . '/' . $relative);
                    $meta = !empty($dd['width']) ? $dd : false;
                }
                $resolved = false;
                if (is_array($meta)) {
                    // Match against the main file's width (scaled or original)
                    if (isset($meta['width']) && (int) $meta['width'] === $width) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        if ($main_file && stripos($main_file, '-scaled.') !== false) {
                            $suffix = '-scaled';
                        }
                        $resolved = true;
                    }
                    // Match against sub-sizes
                    if (!$resolved && isset($meta['sizes']) && is_array($meta['sizes'])) {
                        foreach ($meta['sizes'] as $sz) {
                            if (!is_array($sz) || empty($sz['file']) || empty($sz['width'])) continue;
                            if ((int) $sz['width'] === $width) {
                                $sub_base = basename((string) $sz['file']);
                                $sub_no_ext = preg_replace('/\.[^.]+$/', '', $sub_base);
                                if ($sub_no_ext !== '' && $sub_no_ext !== null) {
                                    $base_no_ext = $sub_no_ext;
                                    $suffix = '';
                                    $resolved = true;
                                    break;
                                }
                            }
                        }
                    }
                    // Scaled-PARENT recognition. Orch labels the scaled parent by its maxWidth
                    // TARGET (e.g. "2560w" = big_image_size_threshold), NOT its actual output width
                    // (1887 for a portrait capped at 2560). So the exact width match above fails and
                    // we'd write -2560w.avif, which the READ path never derives (it ext-swaps the main
                    // src → -scaled.avif) → a valid AVIF never served. Nothing on disk is wider than
                    // the scaled main, so a label width >= $meta['width'] can ONLY be the parent →
                    // name it to match the main file. Genuine adaptive widths are all smaller and fall
                    // through to the -{N}w fallback below.
                    if (!$resolved && isset($meta['width']) && (int) $meta['width'] > 0 && $width >= (int) $meta['width']) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        $suffix = ($main_file !== '' && stripos($main_file, '-scaled.') !== false) ? '-scaled' : '';
                        $resolved = true;
                    }
                }
                // Adaptive-maximizing fallback: even a width that matches no WP sub-size is a
                // useful encoded variant, so cache it under a deterministic name for future
                // same-width requests via CDN passthrough.
                if (!$resolved) {
                    // Adaptive width → "-{W}x{H}" via the shared helper (the same fn the READ
                    // srcset derivations call), write==read by construction. ensure_dims first so
                    // this can never fall to the dead "-{W}w" (-{W}w on disk is a guaranteed 404).
                    $meta = wpc_v2_lazy_ensure_dims($meta, $upload['basedir'] . '/' . $relative);
                    $suffix = wpc_v2_adaptive_variant_suffix($width, $meta);
                }
            } elseif (preg_match('/^[a-z][a-z0-9_]*$/i', $size_label)) {
                // WP sub-size name (medium, large, thumbnail, medium_large, 1536x1536-ish stripped)
                $attachment_id = 0;
                if (function_exists('attachment_url_to_postid')) {
                    $attachment_id = (int) attachment_url_to_postid(preg_replace('/\?.*$/', '', $origin_url));
                }
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;
                if (is_array($meta) && isset($meta['sizes'][$size_label]['file'])) {
                    $sub_base = basename((string) $meta['sizes'][$size_label]['file']);
                    $sub_no_ext = preg_replace('/\.[^.]+$/', '', $sub_base);
                    if ($sub_no_ext !== '' && $sub_no_ext !== null) {
                        $base_no_ext = $sub_no_ext;
                        $suffix = '';
                    } else {
                        return ['ok' => false, 'reason' => 'sub_size_empty_file'];
                    }
                } else {
                    return ['ok' => false, 'reason' => 'unknown_sub_size_name'];
                }
            } else {
                // Truly unparsable — reject with diagnostic
                return ['ok' => false, 'reason' => 'unparsable_size_label', 'sizeLabel' => (string) $size_label];
            }
            // Guard against "-scaled-scaled": if the callback passed origin_url AS the -scaled
            // file (base_no_ext already ends in "-scaled"), appending '-scaled' again writes
            // foo-scaled-scaled.avif, which the READ path (foo-scaled.avif) never finds. Drop
            // the redundant suffix so WRITE == READ regardless of which origin form we got.
            if ($suffix === '-scaled' && strlen($base_no_ext) >= 7 && substr($base_no_ext, -7) === '-scaled') {
                $suffix = '';
            }
            $filename = $base_no_ext . $suffix . '.' . $out_ext;
        }

        // Degenerate-dimension write floor. A w:1 transform (the JS clamps sliders/logos/
        // adaptive-off to w:1 as orch's "serve natural" sentinel) must NEVER persist a 1px
        // variant. It can arrive via the derive (-1x{H}) OR an explicit filename=…-1x1, so
        // guard the FINAL assembled name — the one chokepoint both share. Reject only a true
        // degenerate -{W}x{H} (W<=2 or H<=2); a real small icon (-64x64) is untouched. Not a
        // helper floor: an empty suffix means "full-size", which would misroute the 1px bytes
        // onto the natural name (worse). The READ side never derives a sub-3px width, so
        // write==read holds; <=2 matches the read-side reject exactly — both must agree.
        if (preg_match('/-(\d+)x(\d+)\.[a-z0-9]+$/i', $filename, $degm)
            && ((int) $degm[1] <= 2 || (int) $degm[2] <= 2)) {
            return ['ok' => false, 'reason' => 'degenerate_variant_dimensions', 'filename' => $filename];
        }

        // Compose the final path with basedir as the root of trust.
        $abs_path = rtrim($upload['basedir'], '/\\') . '/' . ($dir !== '' ? $dir . '/' : '') . $filename;

        // Security boundary: the composed path must start with basedir's realpath. The dest
        // file doesn't exist yet, so check the dir's realpath instead.
        $basedir_real = realpath($upload['basedir']);
        if ($basedir_real !== false) {
            $abs_real_prefix = rtrim($basedir_real, '/\\');
            $dest_dir = dirname($abs_path);
            $dest_dir_real = realpath($dest_dir);
            if ($dest_dir_real !== false && strpos($dest_dir_real, $abs_real_prefix) !== 0) {
                return ['ok' => false, 'reason' => 'composed_path_outside_basedir'];
            }
        }

        return ['ok' => true, 'abs_path' => $abs_path];
    }
}

/**
 * Multisite guard — single-site only for now (multisite path resolution for
 * /wp-content/uploads/sites/N/... isn't handled yet). Returns true on multisite
 * so the caller acks and drains the entry; retrying it would never help.
 */
if (!function_exists('wpc_v2_lazy_cdn_should_skip_multisite')) {
    function wpc_v2_lazy_cdn_should_skip_multisite()
    {
        return function_exists('is_multisite') && is_multisite();
    }
}

/**
 * Write a minimal ic_local_variants entry for a lazy_cdn-landed file (without it,
 * the bytes land on disk but the ML row's "compressed N variants" chip stays empty).
 *
 * Maps the saved filename back to its WP-canonical sub-size name and writes via
 * wpc_v2_merge_variant (atomic — MySQL GET_LOCK + array_merge — so concurrent
 * ingests and Phase B callbacks for the same image can't clobber each other).
 * Adaptive-maximized widths (-{N}w / -{W}x{H}) get a literal label. Source-JPG
 * filesize populates originalSize + savings only when a sub-size match exists;
 * otherwise those keys are omitted so array_merge keeps any prior Phase-A precision.
 *
 * Called from both the post-rename path and the dedup fast-path (so files that
 * landed under older code get retroactively registered on the next orch re-send).
 *
 * @param string   $origin_url    Canonical site-host origin URL (already normalized).
 * @param string   $abs_path      Absolute on-disk path of the variant file.
 * @param int      $size_bytes    Variant file size (strlen of fresh bytes, or filesize()).
 * @param string   $format        'avif' / 'webp' / 'jpeg' / 'png'.
 * @param int|null $attachment_id Pre-resolved attachment ID; 0/null → resolve via URL.
 * @return bool                   true if postmeta was written, false if unresolvable.
 */
if (!function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
    function wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, $size_bytes, $format, $attachment_id = 0)
    {
        if (!function_exists('wpc_v2_merge_variant') || !function_exists('wpc_v2_variant_key')) {
            return false;
        }
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 && function_exists('attachment_url_to_postid')) {
            $clean_origin = preg_replace('/\?.*$/', '', (string) $origin_url);
            $attachment_id = (int) attachment_url_to_postid($clean_origin);
            if ($attachment_id <= 0) {
                $scaled_origin = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean_origin);
                if ($scaled_origin !== $clean_origin) {
                    $attachment_id = (int) attachment_url_to_postid($scaled_origin);
                }
            }
        }
        if ($attachment_id <= 0) return false;

        $meta = function_exists('wp_get_attachment_metadata')
            ? wp_get_attachment_metadata($attachment_id)
            : false;

        $saved_filename = basename((string) $abs_path);
        $saved_no_ext   = (string) preg_replace('/\.[^.]+$/', '', $saved_filename);

        $resolved_label  = '';
        $source_jpg_path = '';

        if (is_array($meta)) {
            // (1) Match main file (scaled or un-scaled original)
            if (!empty($meta['file'])) {
                $main_no_ext = preg_replace('/\.[^.]+$/', '', basename((string) $meta['file']));
                if ($main_no_ext === $saved_no_ext) {
                    $main_file = basename((string) $meta['file']);
                    $resolved_label = (stripos($main_file, '-scaled.') !== false) ? 'scaled' : 'unscaled';
                    $source_jpg_path = function_exists('get_attached_file')
                        ? (string) get_attached_file($attachment_id)
                        : '';
                }
            }
            // (2) Match a WP sub-size's basename
            if ($resolved_label === '' && !empty($meta['sizes']) && is_array($meta['sizes'])) {
                $upload_dir_meta = wp_get_upload_dir();
                $main_dir = !empty($meta['file']) ? dirname((string) $meta['file']) : '';
                foreach ($meta['sizes'] as $sz_name => $sz_data) {
                    if (empty($sz_data['file'])) continue;
                    $sz_no_ext = preg_replace('/\.[^.]+$/', '', basename((string) $sz_data['file']));
                    if ($sz_no_ext === $saved_no_ext) {
                        $resolved_label = (string) $sz_name;
                        $sub_rel = ($main_dir !== '' && $main_dir !== '.')
                            ? ($main_dir . '/' . basename((string) $sz_data['file']))
                            : basename((string) $sz_data['file']);
                        $source_jpg_path = rtrim((string) $upload_dir_meta['basedir'], '/') . '/' . ltrim($sub_rel, '/');
                        break;
                    }
                }
            }
        }
        // (3) Adaptive-maximized fallback
        if ($resolved_label === '' && $saved_no_ext !== '') {
            if (preg_match('/-(\d+)w$/', $saved_no_ext, $sm)) {
                $resolved_label = $sm[1] . 'w';
            } elseif (preg_match('/-(\d+)x(\d+)$/', $saved_no_ext, $sm)) {
                $resolved_label = $sm[1] . 'x' . $sm[2];
            } else {
                $resolved_label = $saved_no_ext;
            }
        }
        if ($resolved_label === '') return false;

        // Baseline picker for adaptive widths (-Nw / -WxH labels with no WP sub-size match):
        // pick the smallest WP sub-size whose width is >= the requested width — what the
        // browser would have downloaded at this srcset slot without WPC (closest entry >=
        // required width, matching the srcset selection algorithm). Gives honest savings vs
        // the actual pre-WPC bytes for that slot, falling back to the largest if none qualify.
        $is_adaptive_label = (bool) preg_match('/^\d+w$/', $resolved_label)
                            || (bool) preg_match('/^\d+x\d+$/', $resolved_label);
        if ($is_adaptive_label && $source_jpg_path === '' && is_array($meta)) {
            $target_w = 0;
            if (preg_match('/^(\d+)w$/', $resolved_label, $wm)) {
                $target_w = (int) $wm[1];
            } elseif (preg_match('/^(\d+)x\d+$/', $resolved_label, $wm)) {
                $target_w = (int) $wm[1];
            }
            if ($target_w > 0) {
                $upload_dir_meta = wp_get_upload_dir();
                $main_dir = !empty($meta['file']) ? dirname((string) $meta['file']) : '';
                $best_w = PHP_INT_MAX;
                $best_file = '';
                $largest_w = 0;
                $largest_file = '';
                // Iterate WP sub-sizes
                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $sz_data) {
                        if (empty($sz_data['file']) || empty($sz_data['width'])) continue;
                        $sw = (int) $sz_data['width'];
                        if ($sw >= $target_w && $sw < $best_w) {
                            $best_w = $sw;
                            $best_file = basename((string) $sz_data['file']);
                        }
                        if ($sw > $largest_w) {
                            $largest_w = $sw;
                            $largest_file = basename((string) $sz_data['file']);
                        }
                    }
                }
                // Also consider main file (scaled or un-scaled) as candidate
                if (!empty($meta['file']) && !empty($meta['width'])) {
                    $sw = (int) $meta['width'];
                    $f  = basename((string) $meta['file']);
                    if ($sw >= $target_w && $sw < $best_w) {
                        $best_w = $sw;
                        $best_file = $f;
                    }
                    if ($sw > $largest_w) {
                        $largest_w = $sw;
                        $largest_file = $f;
                    }
                }
                // Prefer best (smallest ≥ target); fall back to largest available.
                $pick_file = $best_file !== '' ? $best_file : $largest_file;
                if ($pick_file !== '') {
                    $sub_rel = ($main_dir !== '' && $main_dir !== '.')
                        ? $main_dir . '/' . $pick_file
                        : $pick_file;
                    $source_jpg_path = rtrim((string) $upload_dir_meta['basedir'], '/') . '/' . ltrim($sub_rel, '/');
                }
            }
        }

        $upload_dir_for_url = wp_get_upload_dir();
        $variant_url = '';
        if (!empty($upload_dir_for_url['baseurl']) && !empty($upload_dir_for_url['basedir'])) {
            $rel = ltrim(str_replace(
                rtrim($upload_dir_for_url['basedir'], '/'),
                '',
                $abs_path
            ), '/');
            $variant_url = rtrim($upload_dir_for_url['baseurl'], '/') . '/' . $rel;
        }

        $variant_key = wpc_v2_variant_key($resolved_label, $format);
        $now_ms      = (int) round(microtime(true) * 1000);
        $size_bytes  = (int) $size_bytes;

        $variant_entry = [
            'size'           => $size_bytes,
            'url'            => $variant_url,
            'local'          => true,
            'skipped'        => false,
            'bg_upgraded'    => time(),
            'bg_upgraded_ms' => $now_ms,
            'phase_b_v2'     => true,
            'lazy_cdn'       => true,
        ];
        if ($source_jpg_path !== '' && @is_file($source_jpg_path)) {
            $orig_size = (int) @filesize($source_jpg_path);
            if ($orig_size > 0) {
                $variant_entry['originalSize'] = $orig_size;
                $variant_entry['savings'] = ($size_bytes > 0 && $size_bytes < $orig_size)
                    ? max(0, (int) round((1 - $size_bytes / $orig_size) * 100))
                    : 0;
            }
        }

        wpc_v2_merge_variant($attachment_id, $variant_key, $variant_entry);

        if (function_exists('wpc_v2_recompute_savings')) {
            wpc_v2_recompute_savings($attachment_id);
        }

        // Flip ic_status / ic_compressing to 'compressed' so the ML row shows the "compressed"
        // chip — otherwise the postmeta is populated but the row reads "not compressed". Only
        // flip if not already compressed (avoid spurious writes), and never clobber an active
        // 'optimizing'/'queueing' state — that would race an in-flight Phase A click.
        $current_status = get_post_meta($attachment_id, 'ic_status', true);
        if ($current_status !== 'compressed') {
            $current_compressing = get_post_meta($attachment_id, 'ic_compressing', true);
            $compressing_status  = (is_array($current_compressing) && !empty($current_compressing['status']))
                ? (string) $current_compressing['status']
                : '';
            if ($compressing_status !== 'optimizing' && $compressing_status !== 'queueing') {
                update_post_meta($attachment_id, 'ic_status', 'compressed');
                // Refresh the path-A optimized-ids cache (see wp-compress-core.php).
                if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
                if ($compressing_status !== 'compressed') {
                    update_post_meta($attachment_id, 'ic_compressing', ['status' => 'compressed']);
                }
            }
        }

        return true;
    }
}


// Record the LAST ingest failure for the healthcheck debug block — shell-less sites
// can't read error_log, so this surfaces the reason in the browser.
if (!function_exists('wpc_v2_lazy_fail_note')) {
    function wpc_v2_lazy_fail_note($reason, $detail = '')
    {
        update_option('wpc_v2_last_ingest_fail', ['t' => time(), 'reason' => (string) $reason, 'detail' => substr((string) $detail, 0, 120)], false);
    }
}
if (!function_exists('wpc_v2_lazy_cdn_ingest')) {
    function wpc_v2_lazy_cdn_ingest($entry)
    {
        if (!is_array($entry)) return false;

        // Gate: only ingest lazy_cdn variants when lazy_cdn ("Smart Delivery") is the selected
        // strategy. Uses wpc_v2_get_lazy_enabled() — the ONE lazy_cdn predicate shared with
        // delivery emission, the orch /v2/config sync, the shutdown-drain and the trigger-scanner
        // — so ingest can't drift from them. (lazy_full / lazy_smart do local first-view encoding
        // via a different path, so gating on lazy_cdn rather than the broad mode check is correct.)
        // With lazy_cdn off, delivery stops emitting CDN URLs and no new entries are produced;
        // this is the backstop for one still reaching ingest (a stale/in-flight encode, or a
        // cached CDN URL). ACK so the manifest drains and orch stops re-sending — we just decline
        // to WRITE it (same ack-without-ingest contract as the multisite skip).
        if (function_exists('wpc_v2_get_lazy_enabled') && !wpc_v2_get_lazy_enabled()) {
            // Consumer-aware decline. Under negotiated delivery (nd) the on-disk next-gen
            // variants ARE the serving source, so an unconditional decline here silently
            // ACK-consumed every staged AVIF on nd sites (triggers fired, orch staged, the
            // drain GET worked — and this line threw the bytes away while telling orch
            // "consumed"). So decline ONLY when no consumer exists (neither lazy_cdn nor nd).
            $wpc_nd_consumes = class_exists('WPC_Negotiated_Delivery')
                && method_exists('WPC_Negotiated_Delivery', 'is_active')
                && WPC_Negotiated_Delivery::is_active();
            // Picture mode consumes too: its avif/webp <source> sets are disk-gated and emit
            // precisely the variants this ingest writes (CF-chain sites run picture by
            // prescription). Consumer test = any next-gen ceiling at all.
            $wpc_pic_consumes = false;
            if (!$wpc_nd_consumes && class_exists('WPC_Delivery_Resolver')
                && method_exists('WPC_Delivery_Resolver', 'effective_ceiling')) {
                $wpc_ceiling = (string) WPC_Delivery_Resolver::effective_ceiling(get_option('wps_ic_settings'));
                $wpc_pic_consumes = in_array($wpc_ceiling, ['webp', 'avif'], true);
            }
            if (!$wpc_nd_consumes && !$wpc_pic_consumes) {
                error_log('[WPC LazyCDN] ingest declined: lazy_cdn off, no nd consumer (mode='
                    . (function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : '?') . ')');
                wpc_v2_lazy_outcome('ack_consumer_gate'); return true;
            }
        }

        if (wpc_v2_lazy_cdn_should_skip_multisite()) {
            error_log('[WPC LazyCDN] skipped: multisite not supported in v7.05.0');
            wpc_v2_lazy_outcome('ack_multisite'); return true;  // ack so manifest drains; retry wouldn't help
        }

        $sha256 = isset($entry['sha256']) ? (string) $entry['sha256'] : '';
        if ($sha256 === '' || strlen($sha256) < 16) {
            wpc_v2_lazy_fail_note('invalid_sha_field'); error_log('[WPC LazyCDN] reject: missing or invalid sha256');
            return false;
        }

        // origin_url may be at top level (spec) OR inside tags (orch's actual shape). Check both.
        $origin_url = '';
        if (isset($entry['origin_url'])) {
            $origin_url = (string) $entry['origin_url'];
        } elseif (isset($entry['tags']['origin_url'])) {
            $origin_url = (string) $entry['tags']['origin_url'];
        }

        // Normalize cdn-zone host → site host once, up front. Orch may have stored the
        // cdn-zone variant of origin_url (rewriteLogic's picture-source srcset uses
        // `u:cdn-zone/...`), but everything downstream — path resolution under uploads,
        // attachment_url_to_postid, HTML cache purge — expects WP's canonical site-host URLs.
        if ($origin_url !== '' && function_exists('get_option')) {
            $origin_host_for_norm = wp_parse_url($origin_url, PHP_URL_HOST);
            $site_host_for_norm   = wp_parse_url(site_url(), PHP_URL_HOST);
            if ($origin_host_for_norm && $site_host_for_norm
                && strcasecmp((string) $origin_host_for_norm, (string) $site_host_for_norm) !== 0) {
                // Match the SAME zone-host candidates the derive accepts, incl. WPS_IC_CF_CNAME
                // (the host the rewriter emits on CF sites), normalized. Without this, a CF-cname
                // origin_url was swapped by the derive but NOT here → attachment_url_to_postid
                // kept the cdn host → ML chip never registered (bytes landed, postmeta didn't).
                $wpc_norm_cands = [(string) get_option('ic_custom_cname'), (string) get_option('ic_cdn_zone_name')];
                if (defined('WPS_IC_CF_CNAME')) {
                    $wpc_norm_cands[] = (string) get_option(WPS_IC_CF_CNAME);
                }
                $wpc_norm_match = false;
                foreach ($wpc_norm_cands as $zh) {
                    if ($zh === '') {
                        continue;
                    }
                    $zh_host = (string) wp_parse_url(strpos($zh, '//') === false ? 'https://' . $zh : $zh, PHP_URL_HOST);
                    if ($zh_host !== '' && strcasecmp((string) $origin_host_for_norm, $zh_host) === 0) {
                        $wpc_norm_match = true;
                        break;
                    }
                }
                if ($wpc_norm_match) {
                    // rtrim guards a site_url filter that appends a trailing slash — a double
                    // slash breaks the strict-match attachment_url_to_postid lookups below.
                    $origin_url = preg_replace('#^https?://[^/]+#', rtrim(site_url(), '/'), $origin_url);
                }
            }
        }

        $size_label = isset($entry['sizeLabel']) ? (string) $entry['sizeLabel'] : '';
        $format     = isset($entry['format'])    ? strtolower((string) $entry['format']) : '';
        $fetch_url  = isset($entry['fetchUrl'])  ? (string) $entry['fetchUrl']  : '';

        if ($origin_url === '' || $format === '' || $fetch_url === '') {
            error_log(sprintf(
                '[WPC LazyCDN] reject: missing required fields. origin_url=%s format=%s fetchUrl=%s imageID=%s entry_keys=%s',
                $origin_url === '' ? '(missing)' : 'set',
                $format === '' ? '(missing)' : $format,
                $fetch_url === '' ? '(missing)' : ('set,len=' . strlen($fetch_url)),
                isset($entry['imageID']) ? (string) $entry['imageID'] : '(missing)',
                is_array($entry) ? implode(',', array_keys($entry)) : 'not-array'
            ));
            // Name the failure so last_ingest_fail isn't silent (a drain can fail with no reason).
            wpc_v2_lazy_fail_note('missing_required_fields', sprintf('origin=%s fmt=%s fetch=%s', $origin_url === '' ? 'missing' : 'set', $format === '' ? 'missing' : $format, $fetch_url === '' ? 'missing' : 'set'));
            return false;
        }

        // Pass full $entry so the derive can prefer entry['filename'] over size_label parsing.
        $derived = wpc_v2_lazy_cdn_derive_abs_path($origin_url, $size_label, $format, $entry);
        if (empty($derived['ok'])) {
            error_log(sprintf(
                '[WPC LazyCDN] reject reason=%s origin_url=%s sizeLabel=%s format=%s',
                isset($derived['reason']) ? $derived['reason'] : 'unknown',
                $origin_url, $size_label, $format
            ));
            // Surface the derive reason (host_mismatch / origin_outside_uploads / ...) into
            // last_ingest_fail so a stuck drain is diagnosable from the healthcheck, not just log.
            wpc_v2_lazy_fail_note('derive_' . (isset($derived['reason']) ? $derived['reason'] : 'failed'), $origin_url);
            return false;
        }
        $abs_path = $derived['abs_path'];

        // Narrow restore guard. The pull lane writes sized next-gen DERIVATIVES, byte-equivalent
        // whether encoded before or after a restore (same pristine source) — blocking those
        // post-restore only delayed rebuilds. So while the restore marker holds, decline ONLY a
        // landing that would overwrite the user's base files (attached/original/scaled basenames);
        // everything else flows.
        if (function_exists('attachment_url_to_postid')) {
            $rg_clean = preg_replace('/\?.*$/', '', (string) $origin_url);
            $rg_base  = preg_replace('/-\d{2,4}x\d{2,4}(\.\w+)$/', '$1', $rg_clean);
            $rg_id    = (int) attachment_url_to_postid($rg_clean);
            if ($rg_id <= 0 && $rg_base !== $rg_clean) $rg_id = (int) attachment_url_to_postid($rg_base);
            if ($rg_id <= 0) $rg_id = (int) attachment_url_to_postid(preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $rg_base));
            if ($rg_id > 0 && get_transient('wpc_v2_callbacks_blocked_' . $rg_id) !== false) {
                $rg_target = basename($abs_path);
                $rg_protected = [];
                $rg_att = get_attached_file($rg_id);
                if ($rg_att) $rg_protected[] = basename($rg_att);
                $rg_orig = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($rg_id) : '';
                if ($rg_orig) $rg_protected[] = basename($rg_orig);
                if (in_array($rg_target, $rg_protected, true)) {
                    error_log('[WPC LazyCDN] restored_reject (parent overwrite) imageID=' . $rg_id . ' target=' . $rg_target);
                    wpc_v2_lazy_outcome('ack_restored_reject'); return true; // ack-decline — never overwrite base files post-restore
                }
            }
        }

        // Dedup check — but only trust the transient when the file ACTUALLY exists on disk.
        // A restore deletes the .avif files yet does NOT clear the dedup transient (10min TTL),
        // so a transient-only short-circuit returned a silent "already processed" while nothing
        // was written ("I restored, retried, nothing landed"). A "seen" transient with a missing
        // file is stale → fall through and re-ingest.
        if (wpc_v2_sha256_dedup_seen($sha256) && @file_exists($abs_path)) {
            wpc_v2_lazy_outcome('ack_dedup_ondisk'); return true;
        }

        // Idempotent fast-path: bytes already on disk with a matching sha256 → skip re-download,
        // but STILL attempt the postmeta write so files that landed under older code (no postmeta)
        // get retroactively registered on the next re-send. write_postmeta is idempotent (merge_
        // variant's GET_LOCK + array_merge), so re-firing on every duplicate is safe.
        if (file_exists($abs_path)) {
            $existing_size = filesize($abs_path);
            $expected_size = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;
            if ($expected_size > 0 && $existing_size === $expected_size) {
                $on_disk_sha = @hash_file('sha256', $abs_path);
                if ($on_disk_sha !== false && hash_equals($on_disk_sha, $sha256)) {
                    wpc_v2_sha256_dedup_mark($sha256);
                    if (function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
                        wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, (int) $existing_size, $format);
                    }
                    wpc_v2_lazy_outcome('ack_idempotent_ondisk'); return true;
                }
            }
        }

        // Ensure the dest dir exists (a sub-size whose dir isn't created yet — rare, but
        // possible on fresh uploads).
        $dest_dir = dirname($abs_path);
        if (!is_dir($dest_dir)) {
            if (!wp_mkdir_p($dest_dir)) {
                wpc_v2_lazy_fail_note('mkdir_failed', $dest_dir); error_log('[WPC LazyCDN] reject: mkdir_failed dir=' . $dest_dir);
                return false;
            }
        }

        // Data-safety: lazy_cdn delivers NEXT-GEN only (avif/webp). A .avif/.webp dest can
        // never share a path with a jpg/png/gif/svg original or sub-size (different extension),
        // so refusing any non-next-gen output here makes "overwrite the customer's original"
        // structurally impossible. (Classic jpg/png that rewrites originals is the JOURNAL path,
        // never this sink — ack-skip a stray jpg/png entry so orch drops it instead of looping.)
        $wpc_out_ext = strtolower((string) pathinfo($abs_path, PATHINFO_EXTENSION));
        if (!in_array($wpc_out_ext, ['avif', 'webp'], true)) {
            wpc_v2_lazy_outcome('ack_non_nextgen');
            error_log('[WPC LazyCDN] ack-skip: non-next-gen variant ext=' . $wpc_out_ext . ' (lazy writes avif/webp only) ' . substr($abs_path, -50));
            return true;
        }

        // Fetch bytes from LS staging
        $resp = wp_remote_get($fetch_url, [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => ['User-Agent' => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.0')],
        ]);
        if (is_wp_error($resp)) {
            wpc_v2_lazy_fail_note('bytes_fetch_error', $resp->get_error_message()); error_log('[WPC LazyCDN] fetch_error: ' . $resp->get_error_message());
            return false;
        }
        $http_code = (int) wp_remote_retrieve_response_code($resp);
        if ($http_code !== 200) {
            wpc_v2_lazy_fail_note('bytes_fetch_non_200', 'code=' . $http_code); error_log('[WPC LazyCDN] fetch_non_200 code=' . $http_code . ' url_tail=' . substr($fetch_url, -50));
            return false;
        }
        $bytes = wp_remote_retrieve_body($resp);
        if (!is_string($bytes) || $bytes === '') {
            wpc_v2_lazy_fail_note('bytes_fetch_empty'); error_log('[WPC LazyCDN] fetch_empty_body url_tail=' . substr($fetch_url, -50));
            return false;
        }

        // Sha256 integrity verify — manifest entry's sha256 is the truth.
        // If bytes don't match, refuse to write (potential storage corruption
        // or man-in-the-middle). Don't ack — let next pull retry.
        $bytes_sha = hash('sha256', $bytes);
        if (!hash_equals($bytes_sha, $sha256)) {
            wpc_v2_lazy_fail_note('sha_mismatch', substr($sha256, 0, 12));
            error_log(sprintf(
                '[WPC LazyCDN] sha256_mismatch expected=%s got=%s url_tail=%s',
                substr($sha256, 0, 12), substr($bytes_sha, 0, 12), substr($fetch_url, -50)
            ));
            return false;
        }

        // Atomic write: temp file + rename. Matches the pattern in
        // v2-callback.php / v2-direct-entry.php so we get consistent disk
        // safety + the same v7.04.70 logged-failure surface.
        $tmp = $abs_path . '.wpc_lazycdn_tmp_' . wp_generate_password(8, false);
        if (@file_put_contents($tmp, $bytes) === false) {
            $err = error_get_last();
            wpc_v2_lazy_fail_note('disk_write_failed', substr($abs_path, -60)); // note reflects THIS entry so a cursor-skip never reads a stale prior note
            error_log(sprintf(
                '[WPC LazyCDN] write_failed bytes=%d dest_tail=%s msg=%s',
                strlen($bytes), substr($abs_path, -60), $err['message'] ?? '-'
            ));
            return false;
        }
        if (!@rename($tmp, $abs_path)) {
            $err = error_get_last();
            wpc_v2_lazy_fail_note('disk_rename_failed', substr($abs_path, -60)); // note reflects THIS entry so a cursor-skip never reads a stale prior note
            error_log(sprintf(
                '[WPC LazyCDN] rename_failed dest_tail=%s msg=%s',
                substr($abs_path, -60), $err['message'] ?? '-'
            ));
            @unlink($tmp);
            return false;
        }
        if (!@chmod($abs_path, 0644)) {
            $err = error_get_last();
            error_log(sprintf(
                '[WPC LazyCDN] chmod_failed dest_tail=%s msg=%s (may still be readable)',
                substr($abs_path, -60), $err['message'] ?? '-'
            ));
        }

        wpc_v2_sha256_dedup_mark($sha256);
        wpc_v2_lazy_outcome('wrote'); // REAL disk write landed (bytes on disk, sha-verified)
        // Post-rename trace: the path the rename targeted + whether the file is present there
        // immediately after. If a URL still 404s after outcome 'wrote', this distinguishes file
        // present (path↔URL/docroot mismatch) from absent (write vanished — tmpfs / wrong volume).
        update_option('wpc_v2_last_ingest_trace', [
            't'            => time(),
            'outcome'      => 'wrote',
            'origin_url'   => substr((string) $origin_url, 0, 160),
            'abs_path'     => substr((string) $abs_path, -90),
            'exists_after' => (int) @file_exists($abs_path),
            'size_after'   => (int) (@file_exists($abs_path) ? @filesize($abs_path) : 0),
            'expect_bytes' => (int) (isset($entry['bytes']) ? (int) $entry['bytes'] : strlen($bytes)),
        ], false);

        // Resolve attachment_id ONCE — reused for both ic_local_variants write
        // (so ML chip shows "compressed N variants") AND for HTML cache purge.
        // Strip query/fragment; try -scaled counterpart when origin_url is
        // un-scaled (WP's attachment URL is the -scaled when scaling occurred
        // at upload time).
        $attachment_id = 0;
        $clean_origin  = preg_replace('/\?.*$/', '', $origin_url);
        // SIZED origin_urls (orch echoes our `…-1005x1363.jpg` origin verbatim): attachment_url_to_postid
        // only knows registered names, so resolution must ALSO try the -WxH-stripped base (+ its -scaled
        // form). Without this, sized deliveries land on disk but never register (no ic_local_variants,
        // no landed purge).
        if (function_exists('attachment_url_to_postid')) {
            $resolve_candidates = [$clean_origin];
            $scaled_origin = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean_origin);
            if ($scaled_origin !== $clean_origin) $resolve_candidates[] = $scaled_origin;
            $base_origin = preg_replace('/-\d{2,4}x\d{2,4}(\.\w+)$/', '$1', $clean_origin);
            if ($base_origin !== $clean_origin) {
                $resolve_candidates[] = $base_origin;
                $base_scaled = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $base_origin);
                if ($base_scaled !== $base_origin) $resolve_candidates[] = $base_scaled;
            }
            foreach ($resolve_candidates as $rc) {
                $attachment_id = (int) attachment_url_to_postid($rc);
                if ($attachment_id > 0) break;
            }
        }

        // ic_local_variants write. Without this, entries land on disk but never register with the
        // Media Library row, so the "compressed N variants" chip stays empty. Reusable helper so the
        // dedup fast-path can also call it to retroactively backfill older files on orch re-send.
        if (function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
            wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, strlen($bytes), $format, $attachment_id);
        }

        // Variant-landed cache purge. The variant just hit disk, but every upstream cache (customer
        // PZ, endpoint PZ, pod fs, the edge's known-missing filter) still believes it doesn't exist —
        // visitors keep the on-the-fly downgrade until the edge TTL. ENQUEUE the landed URL;
        // wpc_v2_lazy_purge_enqueue flushes ONCE on shutdown as a single deduped, chunked, BLOCKING
        // purge — coalescing the ~22 per-image purges into one reliable call (fire-and-forget could
        // fail to flush if the drain process ended first). Blocking is safe: the drain runs past
        // fastcgi_finish_request (no user latency) and the purge is timeout-bounded.
        if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
            // Purges the landed file AND its -WxH format siblings (the negotiated URL the browser
            // actually hits) so the on-disk variant serves on the NEXT request, not after the edge TTL.
            wpc_v2_enqueue_landed_purge($abs_path);
        }

        // HTML cache invalidation. Page HTML referencing this attachment was emitting the wp:N CDN
        // transform URL for this (size, format); now the file exists, the next render emits the natural
        // URL. Purge so the customer doesn't need ?bust= or to wait out Varnish TTL. The throttle
        // inside wpc_v2_purge_html_for_attachment coalesces bursts when several sub-sizes land at once.
        if ($attachment_id > 0 && function_exists('wpc_v2_purge_html_for_attachment')) {
            wpc_v2_purge_html_for_attachment($attachment_id, 'lazy-cdn-ingest');
        } elseif ($attachment_id <= 0) {
            // No matching attachment — variant landed for an external image
            // or URL outside the uploads dir. Skip purge (nothing to invalidate).
            error_log('[WPC LazyCDN] purge_skip no_attachment_for_origin=' . substr($clean_origin, -80));
        }

        return true;
    }
}
