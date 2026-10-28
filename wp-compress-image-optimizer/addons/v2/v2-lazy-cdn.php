<?php
if (!function_exists('wpc_v2_att')) {
    function wpc_v2_att($u)
    {
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id')) {
            return (int) wps_rewriteLogic::wpc_att_id($u);
        }
        return function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($u) : 0;
    }
}


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


if (!function_exists('wpc_v2_lazy_cdn_clear_dedup_transients')) {
    function wpc_v2_lazy_cdn_clear_dedup_transients()
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return 0;
        $like_value = $wpdb->esc_like('_transient_wpc_v2_dedup_') . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_wpc_v2_dedup_') . '%';

        $deleted_value = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_value
        ));

        $deleted_timeout = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_timeout
        ));
        return $deleted_value;
    }
}

if (!function_exists('wpc_v2_same_aspect')) {
    // w x h has the aspect of mw x mh when either side, scaled from the other, lands within 1.5 px. Any side <= 0 is no answer and counts as the same.
    function wpc_v2_same_aspect($w, $h, $mw, $mh)
    {
        $w = (int) $w; $h = (int) $h; $mw = (int) $mw; $mh = (int) $mh;
        if ($w <= 0 || $h <= 0 || $mw <= 0 || $mh <= 0) return true;
        return abs($h - $w * $mh / $mw) <= 1.5 || abs($w - $h * $mw / $mh) <= 1.5;
    }
}

if (!function_exists('wpc_v2_wp_made_suffix')) {

    function wpc_v2_wp_made_suffix($width, $meta)
    {
        $width = (int) $width;
        if ($width <= 0 || !is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) return '';
        $mw = !empty($meta['width'])  ? (int) $meta['width']  : 0;
        $mh = !empty($meta['height']) ? (int) $meta['height'] : 0;
        foreach ($meta['sizes'] as $size) {
            if (!is_array($size) || empty($size['file']) || empty($size['width']) || (int) $size['width'] !== $width) continue;
            $sh = !empty($size['height']) ? (int) $size['height'] : 0;
            if (!wpc_v2_same_aspect($width, $sh, $mw, $mh)) continue;
            $name = (string) preg_replace('/\.[^.]+$/', '', basename((string) $size['file']));
            if (preg_match('/-(\d+)x(\d+)$/', $name, $box) && (int) $box[1] === $width) return $box[0];
        }
        return '';
    }
}

if (!function_exists('wpc_v2_adaptive_variant_suffix')) {

    function wpc_v2_adaptive_variant_suffix($width, $meta)
    {
        $width = (int) $width;
        if ($width <= 0) return '';
        $made = wpc_v2_wp_made_suffix($width, $meta);
        if ($made !== '') return $made;
        if (function_exists('wpc_image_names_nw') && wpc_image_names_nw()) return '-' . $width . 'w';
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
            if (function_exists('wpc_landed_purge_spool_add')) {
                wpc_landed_purge_spool_add($urls);
                if (function_exists('wpc_landed_purge_drain_on_shutdown')) {
                    wpc_landed_purge_drain_on_shutdown();
                }
                if (function_exists('wpc_landed_purge_drain')) {
                    wpc_landed_purge_drain();
                }
                return;
            }
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

if (!function_exists('wpc_landed_purge_acquire_lock')) {
    function wpc_landed_purge_acquire_lock()
    {
        if (!function_exists('add_option')) {
            return true;
        }
        for ($i = 0; $i < 6; $i++) {
            if (add_option(WPC_LANDED_PURGE_LOCK_OPTION, time(), '', 'no')) {
                return true;
            }
            $held = (int) get_option(WPC_LANDED_PURGE_LOCK_OPTION, 0);
            if ($held > 0 && time() - $held > 30) {
                delete_option(WPC_LANDED_PURGE_LOCK_OPTION);
                continue;
            }
            usleep(50000);
        }
        return false;
    }
    function wpc_landed_purge_release_lock()
    {
        if (function_exists('delete_option')) {
            delete_option(WPC_LANDED_PURGE_LOCK_OPTION);
        }
    }
}

if (!function_exists('wpc_landed_purge_spool_add')) {
    function wpc_landed_purge_spool_add($urls)
    {
        if (!apply_filters('wpc_landed_purge_spool88', true)) {
            return 0;
        }
        $locked = wpc_landed_purge_acquire_lock();
        $spool = get_option('wpc_landed_purge_spool88', []);
        if (!is_array($spool)) {
            $spool = [];
        }
        $now = time();
        foreach ($spool as $u => $ts) {
            if (!is_int($ts) || $now - $ts > DAY_IN_SECONDS) {
                unset($spool[$u]);
            }
        }
        $added = 0;
        foreach ((array) $urls as $u) {
            $u = (string) $u;
            if ($u === '') {
                continue;
            }
            if (!isset($spool[$u])) {
                $added++;
            }
            $spool[$u] = $now;
        }
        if (count($spool) > 2000) {
            asort($spool);
            $spool = array_slice($spool, -2000, null, true);
        }
        update_option('wpc_landed_purge_spool88', $spool, false);
        if ($locked) {
            wpc_landed_purge_release_lock();
        }
        if ($added > 0 && get_transient('wpc_landed_purge_draining88') && function_exists('wpc_landed_purge_schedule_drain')) {
            wpc_landed_purge_schedule_drain(60);
        }
        return $added;
    }
}

if (!function_exists('wpc_landed_purge_drain_on_shutdown')) {
    function wpc_landed_purge_drain_on_shutdown()
    {
        static $shutdown_armed = false;
        if ($shutdown_armed || !function_exists('add_action') || !function_exists('wpc_landed_purge_drain')) {
            return false;
        }
        $shutdown_armed = true;
        add_action('shutdown', function () {
            if (function_exists('wpc_finish_request') && empty($GLOBALS['wpc_response_released'])) {
                wpc_finish_request();
            }
            @ignore_user_abort(true);
            $GLOBALS['wpc_signed_wake_request'] = true;
            wpc_landed_purge_drain();
            unset($GLOBALS['wpc_signed_wake_request']);
        }, 98);
        return true;
    }
}
if (!function_exists('wpc_landed_purge_schedule_drain')) {
    function wpc_landed_purge_schedule_drain($delay = 60)
    {
        if (!function_exists('wp_next_scheduled') || wp_next_scheduled(WPC_LANDED_PURGE_DRAIN_HOOK)) {
            return false;
        }
        if (function_exists('wpc_pl_sched') && wpc_pl_sched(time() + (int) $delay, WPC_LANDED_PURGE_DRAIN_HOOK)) {
            return true;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + (int) $delay, WPC_LANDED_PURGE_DRAIN_HOOK);
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_landed_purge_drain')) {
    function wpc_landed_purge_drain()
    {
        if (!apply_filters('wpc_landed_purge_spool88', true)) {
            return 0;
        }
        if (function_exists('wpc_render_guard_active') && wpc_render_guard_active()) {
            wpc_landed_purge_schedule_drain(60);
            return 0;
        }
        if (!function_exists('wpc_customer_purge') || !function_exists('wpc_v2_get_apikey')) {
            return 0;
        }
        $key = (string) wpc_v2_get_apikey();
        if ($key === '') {
            return 0;
        }
        if (get_transient('wpc_landed_purge_draining88')) {
            wpc_landed_purge_schedule_drain(60);
            return 0;
        }
        set_transient('wpc_landed_purge_draining88', 1, 120);
        $spool = get_option('wpc_landed_purge_spool88', []);
        if (!is_array($spool) || empty($spool)) {
            delete_transient('wpc_landed_purge_draining88');
            return 0;
        }
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        $urls = array_keys($spool);
        $sent = 0;
        $done = [];
        $chunks = array_chunk($urls, 200);
        $deadline = microtime(true) + 60;
        foreach ($chunks as $i => $chunk) {
            if ($i >= 8 || microtime(true) > $deadline) {
                break;
            }
            $r = wpc_customer_purge($key, 'urls', $chunk, 'variant_landed', true);
            $orch_ok = is_array($r) && !empty($r['orch_ok']);
            if ($orch_ok) {
                foreach ($chunk as $u) {
                    unset($spool[$u]);
                    $done[$u] = 1;
                }
                $sent += count($chunk);
                continue;
            }
            $http = (is_array($r) && isset($r['orch_http'])) ? (int) $r['orch_http'] : 0;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('landed-purge-deferred', '', '', ['urls' => count($chunk), 'http' => $http, 'spool' => count($spool)]);
            }
            error_log('[WPC LazyCDN] landed purge deferred urls=' . count($chunk) . ' http=' . $http . ' spool=' . count($spool));
            break;
        }
        $locked = wpc_landed_purge_acquire_lock();
        $fresh = get_option('wpc_landed_purge_spool88', []);
        if (!is_array($fresh)) {
            $fresh = [];
        }
        foreach ($done as $u => $one) {
            unset($fresh[$u]);
        }
        update_option('wpc_landed_purge_spool88', $fresh, false);
        if ($locked) {
            wpc_landed_purge_release_lock();
        }
        delete_transient('wpc_landed_purge_draining88');
        if (!empty($fresh)) {
            wpc_landed_purge_schedule_drain(60);
        }
        return $sent;
    }
    add_action(WPC_LANDED_PURGE_DRAIN_HOOK, 'wpc_landed_purge_drain');
    add_action('wpc_v2_pull_cron', 'wpc_landed_purge_drain', 25);
}

if (!function_exists('wpc_v2_find_smaller_sibling')) {
    function wpc_v2_find_smaller_sibling($abs_path, $twin_len)
    {
        $twin_len = (int) $twin_len;
        if ($twin_len <= 0 || !apply_filters('wpc_keep_smallest', true) || !preg_match('/\.(avif|webp)$/i', (string) $abs_path, $m)) {
            return '';
        }
        $ratio = (float) apply_filters('wpc_keep_smallest_ratio', 0.95);
        $other = strtolower($m[1]) === 'avif' ? 'webp' : 'avif';
        foreach (['jpg', 'jpeg', 'png', $other] as $ext) {
            $cand = preg_replace('/\.(avif|webp)$/i', '.' . $ext, (string) $abs_path);
            $sz = @is_file($cand) ? (int) @filesize($cand) : 0;
            if ($sz > 0 && $twin_len > $sz * $ratio) {
                return $ext . ':' . $sz;
            }
        }
        return '';
    }
}
if (!function_exists('wpc_v2_purge_asking_pages')) {

    function wpc_v2_purge_asking_pages($abs_path, $flush = false)
    {
        static $queue = [];
        static $hooked = false;
        if ($flush) {
            $paths = array_keys($queue);
            $queue = [];
            if (!class_exists('wps_ic_url_key') || !class_exists('wps_ic_cache_integrations') || !function_exists('home_url')) {
                return 0;
            }
            $home = wp_parse_url(home_url());
            if (empty($home['scheme']) || empty($home['host'])) {
                return 0;
            }
            $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
            $purged = 0;
            foreach ($paths as $path) {
                $url = $origin . $path;
                $post_id = function_exists('url_to_postid') ? (int) url_to_postid($url) : 0;
                $gone = $post_id > 0 && get_post_status($post_id) !== 'publish';
                $url_key = (new wps_ic_url_key())->setup($url);
                if ($url_key === '') {
                    continue;
                }
                wps_ic_cache_integrations::purgeUrlHtml($url_key, $url, ['context' => 'sized-landed', 'soft' => !$gone, 'warm' => !$gone]);
                $purged++;
            }
            if ($purged > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('landed-pages-purged', '', '', ['n' => $purged]);
            }
            return $purged;
        }
        if (!function_exists('wp_get_upload_dir') || !function_exists('get_option')) {
            return 0;
        }
        $up = wp_get_upload_dir();
        $ext = strtolower((string) pathinfo((string) $abs_path, PATHINFO_EXTENSION));
        if (empty($up['basedir']) || strpos((string) $abs_path, (string) $up['basedir']) !== 0 || ($ext !== 'avif' && $ext !== 'webp')) {
            return 0;
        }
        $stem = ltrim(substr((string) preg_replace('/\.[^.]+$/', '', (string) $abs_path), strlen((string) $up['basedir'])), '/');
        $asked = get_option('wpc_szt_asked', []);
        if (!is_array($asked) || empty($asked[$stem]['pages']) || !is_array($asked[$stem]['pages'])) {
            return 0;
        }
        $done = isset($asked[$stem]['done']) && is_array($asked[$stem]['done']) ? $asked[$stem]['done'] : [];
        if (in_array($ext, $done, true)) {
            return 0;
        }
        $done[] = $ext;
        $pages = $asked[$stem]['pages'];
        if (count($done) >= 2) {
            unset($asked[$stem]);
        } else {
            $asked[$stem]['done'] = $done;
        }
        update_option('wpc_szt_asked', $asked, false);
        foreach ($pages as $page) {
            if (is_string($page) && preg_match('#^/[A-Za-z0-9/_.%~-]{0,200}$#', $page) && strpos($page, '..') === false) {
                $queue[$page] = true;
            }
        }
        if ($queue && !$hooked) {
            $hooked = true;
            if (function_exists('did_action') && did_action('shutdown')) {
                $hooked = false;
                return wpc_v2_purge_asking_pages('', true);
            }
            add_action('shutdown', function () use (&$hooked) {
                $hooked = false;
                wpc_v2_purge_asking_pages('', true);
            }, 20);
        }
        return count($pages);
    }
}

if (!function_exists('wpc_v2_landed_pages_purge')) {

    function wpc_v2_landed_pages_purge($abs_path)
    {
        if (!function_exists('wpc_encode_on_view_on') || !wpc_encode_on_view_on()) {
            return 0;
        }
        if (class_exists('wps_ic_asset_version')) {
            wps_ic_asset_version::forget((string) $abs_path);
        }
        if (wpc_v2_landed_pages_budget() <= 0) {
            if (function_exists('wpc_v2_landed_pages_defer')) {
                wpc_v2_landed_pages_defer((string) $abs_path);
            }
            return 0;
        }
        return wpc_v2_landed_pages_budget(wpc_v2_purge_asking_pages((string) $abs_path));
    }
}

if (!function_exists('wpc_v2_landed_pages_budget')) {

    /** The pages this request may still purge for landings (filter wpc_landed_pages_per_request, 120); a number passed in is spent from it first. */
    function wpc_v2_landed_pages_budget($spend = 0)
    {
        static $spent = 0;
        $spent += max(0, (int) $spend);
        return max(1, (int) apply_filters('wpc_landed_pages_per_request', 120)) - $spent;
    }
}

if (!function_exists('wpc_landed_pages_drain')) {

    /** Purges the pages of the landings that waited (direct-entry lanes, over the per-request cap) up to this request's budget; the rest stays queued. Answers how many landings it took. */
    function wpc_landed_pages_drain()
    {
        $held = get_option('wpc_landed_pages_pending', []);
        if (!is_array($held) || !$held) {
            return 0;
        }
        $done = [];
        foreach ($held as $path => $at) {
            if (wpc_v2_landed_pages_budget() <= 0) {
                break;
            }
            $done[] = $path;
            if (preg_match('/^post:(\d+):(hard|soft)$/', (string) $path, $page)) {
                if (function_exists('wpc_v2_purge_page_within_budget')) {
                    try {
                        wpc_v2_purge_page_within_budget((int) $page[1], $page[2] === 'hard' ? 'hard' : null);
                    } catch (\Throwable $e) {
                    }
                }
                continue;
            }
            wpc_v2_landed_pages_purge((string) $path);
        }
        $fresh = get_option('wpc_landed_pages_pending', []);
        $fresh = is_array($fresh) ? $fresh : [];
        foreach ($done as $path) {
            unset($fresh[$path]);
        }
        if ($fresh) {
            update_option('wpc_landed_pages_pending', $fresh, false);
            if (function_exists('wpc_v2_landed_pages_arm_drain')) {
                wpc_v2_landed_pages_arm_drain();
            }
        } else {
            delete_option('wpc_landed_pages_pending');
        }
        return count($done);
    }
    add_action('wpc_v2_pull_cron', 'wpc_landed_pages_drain', 26);
    if (defined('WPC_LANDED_PAGES_DRAIN_HOOK')) {
        add_action(WPC_LANDED_PAGES_DRAIN_HOOK, 'wpc_landed_pages_drain');
    }
}

if (!function_exists('wpc_v2_enqueue_landed_purge')) {

    function wpc_v2_enqueue_landed_purge($abs_path)
    {
        if (!function_exists('wpc_v2_lazy_purge_enqueue') || !function_exists('wp_get_upload_dir')) return;
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl']) || strpos((string) $abs_path, (string) $up['basedir']) !== 0) return;
        wpc_v2_landed_pages_purge((string) $abs_path);
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
        // The source beside the landed file, from its owner (png before jpg: a .jpg beside a .png
        // is our twin, ticket 12231).
        $source = function_exists('wpc_v2_source_beside') ? wpc_v2_source_beside((string) $abs_path) : '';
        $source_ext = $source !== '' ? strtolower((string) pathinfo($source, PATHINFO_EXTENSION)) : '';
        $plain_on = function_exists('wpc_image_plain_on') && wpc_image_plain_on();
        if ($plain_on && $source_ext === '' && function_exists('wpc_v2_source_beside')) {
            $plain_base = (string) preg_replace('/(?:-\d+x\d+|-\d+w)\.(?:avif|webp|jpe?g|png)$/i', '', (string) $abs_path);
            foreach (['', '-scaled'] as $plain_scaled) {
                $source = wpc_v2_source_beside($plain_base . $plain_scaled . '.png');
                if ($source !== '') {
                    $source_ext = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
                    break;
                }
            }
        }
        if ($plain_on && $source_ext !== '') {
            $targets[] = preg_replace('/\.(avif|webp|jpe?g|png)$/i', '.' . $source_ext, $rel);
        }
        if ($source_ext !== '') {
            foreach ($targets as $target) {
                if (preg_match('/\.(avif|webp)$/i', $target)) {
                    $targets[] = $target . '?src=' . $source_ext;
                }
            }
        }
        foreach (array_unique($targets) as $t) {
            wpc_v2_lazy_purge_enqueue($t);
        }
    }
}

if (!function_exists('wpc_v2_lazy_resolve_attachment')) {


    function wpc_v2_lazy_resolve_attachment($origin_url, $relative = '')
    {
        if (!function_exists('attachment_url_to_postid')) return 0;
        $clean = preg_replace('/\?.*$/', '', (string) $origin_url);
        $use_memoized_lookup = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id');
        $id = $use_memoized_lookup ? (int) wps_rewriteLogic::wpc_att_id($clean) : wpc_v2_att($clean);
        if ($id > 0) return $id;
        // -scaled counterpart (WP stores -scaled as the attached file when scaled at upload)
        $scaled = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean);
        if ($scaled !== $clean) {
            $id = $use_memoized_lookup ? (int) wps_rewriteLogic::wpc_att_id($scaled) : wpc_v2_att($scaled);
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


    function wpc_v2_lazy_outcome($reason)
    {
        $GLOBALS['wpc_v2_lazy_last_outcome'] = (string) $reason;
        $h = get_option('wpc_v2_ingest_outcomes', []);
        if (!is_array($h)) $h = [];
        $h[$reason] = (isset($h[$reason]) ? (int) $h[$reason] : 0) + 1;
        $h['_last'] = $reason; $h['_t'] = time();


        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()
            && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('ingest_outcomes', 15))) {
            update_option('wpc_v2_ingest_outcomes', $h, false);
        }
        return $reason;
    }
}
if (!function_exists('wpc_v2_ack_landed_only')) {

    function wpc_v2_ack_landed_only()
    {
        return (bool) apply_filters('wpc_v2_ack_landed_only', !(defined('WPC_V2_ACK_LANDED_ONLY_OFF') && WPC_V2_ACK_LANDED_ONLY_OFF));
    }
}
if (!function_exists('wpc_v2_lazy_cdn_ingest_state')) {

    function wpc_v2_lazy_cdn_ingest_state($entry)
    {
        $GLOBALS['wpc_v2_lazy_last_outcome'] = '';
        if (!wpc_v2_lazy_cdn_ingest($entry)) {
            return 'failed';
        }
        if (wpc_v2_ack_landed_only() && strpos((string) $GLOBALS['wpc_v2_lazy_last_outcome'], 'refused_') === 0) {
            return 'refused';
        }
        return 'landed';
    }
}
if (!function_exists('wpc_v2_lazy_cdn_derive_abs_path')) {

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


        $site_host   = wp_parse_url(site_url(), PHP_URL_HOST);
        $origin_host = isset($parsed['host']) ? (string) $parsed['host'] : '';
        $wildcard_host = '';
        if ($origin_host !== '' && $site_host !== '' && strcasecmp($origin_host, $site_host) !== 0) {
            // Accept ALL of this site's zone identities, not either-or: a custom cname can


            $zone_ok = false;
            if (function_exists('get_option')) {


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


            if (!$zone_ok && preg_match('/\.zapwp\.com$/i', $origin_host)) {
                $zone_ok = true;
                $wildcard_host = $origin_host;
            }
            if (!$zone_ok) {
                return ['ok' => false, 'reason' => 'host_mismatch', 'origin_host' => $origin_host];
            }


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


        $explicit_filename = '';
        $fn_candidate = '';
        foreach (['filename', 'rendition_filename'] as $fk) {
            if (is_array($entry) && !empty($entry[$fk])) { $fn_candidate = (string) $entry[$fk]; break; }
            if (is_array($entry) && isset($entry['tags'][$fk]) && $entry['tags'][$fk] !== '') { $fn_candidate = (string) $entry['tags'][$fk]; break; }
        }
        if ($fn_candidate !== '') {
            $candidate = basename((string) $fn_candidate);
            $expected_ext = ($format === 'jpeg') ? 'jpg' : $format;


            $dot  = strrpos($candidate, '.');
            $stem = $dot !== false ? substr($candidate, 0, $dot) : '';
            $rem  = null;
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
                && strcasecmp(substr($candidate, $dot + 1), $expected_ext) === 0
                && $rem !== null
                && strpos($rem, '.') === false
                && !preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $stem)) {
                $explicit_filename = $candidate;
            }
        }

        $out_ext = ($format === 'jpeg') ? 'jpg' : $format;


        // Rides the explicit-filename lane so the common compose + basedir checks still apply.
        if ($explicit_filename === ''
            && preg_match('/-(\d{2,4})x(\d{2,4})$/', $base_no_ext, $szm)
            && is_string($size_label)
            && preg_match('/^(?:w)?(\d{2,4})w?$/i', trim($size_label), $szl)
            && (int) $szl[1] === (int) $szm[1]) {
            $explicit_filename = $base_no_ext . '.' . $out_ext;
        }
        if ($explicit_filename === ''
            && preg_match('/-(\d{2,5})w$/', $base_no_ext, $nwm)
            && is_string($size_label)
            && preg_match('/^(?:w)?(\d{2,5})w?$/i', trim($size_label), $nwl)
            && (int) $nwl[1] === (int) $nwm[1]) {
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


                $w = (int) $m[1];
                $h = (int) $m[2];
                $attachment_id = wpc_v2_lazy_resolve_attachment($origin_url, $relative);
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;


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


                    $meta = wpc_v2_lazy_ensure_dims($meta, $upload['basedir'] . '/' . $relative);
                    $suffix = wpc_v2_adaptive_variant_suffix($w, $meta);
                }
            } elseif (preg_match('/^w(\d+)$/i', $size_label, $m) || preg_match('/^(\d+)w$/i', $size_label, $m)) {
                // Width-only descriptor (browser srcset format). Accept both suffix-w (`1887w`)


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


                    if (!$resolved && isset($meta['width']) && (int) $meta['width'] > 0 && $width >= (int) $meta['width']) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        $suffix = ($main_file !== '' && stripos($main_file, '-scaled.') !== false) ? '-scaled' : '';
                        $resolved = true;
                    }
                }


                if (!$resolved) {


                    $meta = wpc_v2_lazy_ensure_dims($meta, $upload['basedir'] . '/' . $relative);
                    $suffix = wpc_v2_adaptive_variant_suffix($width, $meta);
                }
            } elseif (preg_match('/^[a-z][a-z0-9_]*$/i', $size_label)) {
                // WP sub-size name (medium, large, thumbnail, medium_large, 1536x1536-ish stripped)
                $attachment_id = 0;
                if (function_exists('attachment_url_to_postid')) {
                    $attachment_id = wpc_v2_att(preg_replace('/\?.*$/', '', $origin_url));
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


            if ($suffix === '-scaled' && strlen($base_no_ext) >= 7 && substr($base_no_ext, -7) === '-scaled') {
                $suffix = '';
            }
            $filename = $base_no_ext . $suffix . '.' . $out_ext;
        }

        // Degenerate-dimension write floor. A w:1 transform (the JS clamps sliders/logos/


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

        return ['ok' => true, 'abs_path' => $abs_path] + ($wildcard_host !== '' ? ['foreign_host' => $wildcard_host] : []);
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


if (!function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
    function wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, $size_bytes, $format, $attachment_id = 0, $sha256 = '')
    {
        if (!function_exists('wpc_v2_variant_key')) {
            return false;
        }
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 && function_exists('attachment_url_to_postid')) {
            $clean_origin = preg_replace('/\?.*$/', '', (string) $origin_url);
            $attachment_id = wpc_v2_att($clean_origin);
            if ($attachment_id <= 0) {
                $scaled_origin = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean_origin);
                if ($scaled_origin !== $clean_origin) {
                    $attachment_id = wpc_v2_att($scaled_origin);
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
        if ((string) $sha256 !== '' && function_exists('wpc_policy_cohort') && (int) wpc_policy_cohort('hero_land_first') === 1) {
            $variant_entry['bytes_sha256'] = (string) $sha256;
        }
        if ($source_jpg_path !== '' && @is_file($source_jpg_path)) {
            $orig_size = (int) @filesize($source_jpg_path);
            if ($orig_size > 0) {
                $variant_entry['originalSize'] = $orig_size;
                $variant_entry['savings'] = ($size_bytes > 0 && $size_bytes < $orig_size)
                    ? max(0, (int) round((1 - $size_bytes / $orig_size) * 100))
                    : 0;
            }
        }

        // The owner promotes the image (any landed variant counts on this lane).
        wps_ic_image_variants::record($attachment_id, [$variant_key => $variant_entry], 'lazy-cdn', ['any_variant' => true]);
        if (function_exists('wpc_add_twin_bytes')) {
            wpc_add_twin_bytes((int) $size_bytes);
        }

        return true;
    }
}


// Record the LAST ingest failure for the healthcheck debug block — shell-less sites
// can't read error_log, so this surfaces the reason in the browser.
if (!function_exists('wpc_v2_lazy_fail_note')) {
    function wpc_v2_lazy_fail_note($reason, $detail = '')
    {


        $GLOBALS['wpc_v2_lif_mem'] = ['t' => time(), 'reason' => (string) $reason, 'detail' => substr((string) $detail, 0, 120)];
        // DIAGNOSTIC copy for the healthcheck: OFF by default (this was 522 UPDATEs / 2min into the
        // binlog on wpcompress.com's stuck drain — pure debug breadcrumb, nothing functional reads it).
        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()
            && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('ingest_fail', 15))) {
            update_option('wpc_v2_last_ingest_fail', $GLOBALS['wpc_v2_lif_mem'], false);
        }
    }
}
if (!function_exists('wpc_v2_lazy_cdn_ingest')) {
    function wpc_v2_lazy_cdn_ingest($entry)
    {
        if (!is_array($entry)) return false;


        if (function_exists('wpc_v2_get_lazy_enabled') && !wpc_v2_get_lazy_enabled()) {
            // Consumer-aware decline. Under negotiated delivery (nd) the on-disk next-gen
            // variants ARE the serving source, so an unconditional decline here silently


            // "consumed"). So decline ONLY when no consumer exists (neither lazy_cdn nor nd).
            $wpc_nd_consumes = class_exists('WPC_Negotiated_Delivery')
                && method_exists('WPC_Negotiated_Delivery', 'is_active')
                && WPC_Negotiated_Delivery::is_active();


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
            wpc_v2_lazy_outcome('ack_multisite'); return true;
        }

        $sha256 = isset($entry['sha256']) ? (string) $entry['sha256'] : '';
        if ($sha256 === '' || strlen($sha256) < 16) {
            wpc_v2_lazy_fail_note('invalid_sha_field'); error_log('[WPC LazyCDN] reject: missing or invalid sha256');
            return false;
        }


        $origin_url = '';
        if (isset($entry['origin_url'])) {
            $origin_url = (string) $entry['origin_url'];
        } elseif (isset($entry['tags']['origin_url'])) {
            $origin_url = (string) $entry['tags']['origin_url'];
        }


        if ($origin_url !== '' && function_exists('get_option')) {
            $origin_host_for_norm = wp_parse_url($origin_url, PHP_URL_HOST);
            $site_host_for_norm   = wp_parse_url(site_url(), PHP_URL_HOST);
            if ($origin_host_for_norm && $site_host_for_norm
                && strcasecmp((string) $origin_host_for_norm, (string) $site_host_for_norm) !== 0) {


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


        if (function_exists('attachment_url_to_postid')) {
            $rg_clean = preg_replace('/\?.*$/', '', (string) $origin_url);
            $rg_base  = preg_replace('/-\d{2,4}x\d{2,4}(\.\w+)$/', '$1', $rg_clean);
            $rg_id    = wpc_v2_att($rg_clean);
            if ($rg_id <= 0 && $rg_base !== $rg_clean) $rg_id = wpc_v2_att($rg_base);
            if ($rg_id <= 0) $rg_id = wpc_v2_att(preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $rg_base));
            if ($rg_id > 0 && function_exists('get_post_type') && get_post_type($rg_id) !== 'attachment') {
                $rg_id = 0;
            }
            if ($rg_id > 0 && function_exists('wpc_v2_landing_refused') && wpc_v2_landing_refused($rg_id)) {
                wpc_v2_landing_refused_receipt($rg_id, 'lazy-cdn');
                wpc_v2_lazy_outcome('ack_restored_reject'); return true;
            }
            if ($rg_id > 0 && get_transient('wpc_v2_callbacks_blocked_' . $rg_id) !== false) {
                $rg_target = basename($abs_path);
                $rg_protected = [];
                $rg_att = get_attached_file($rg_id);
                if ($rg_att) $rg_protected[] = basename($rg_att);
                $rg_orig = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($rg_id) : '';
                if ($rg_orig) $rg_protected[] = basename($rg_orig);
                if (in_array($rg_target, $rg_protected, true)) {
                    error_log('[WPC LazyCDN] restored_reject (parent overwrite) imageID=' . $rg_id . ' target=' . $rg_target);
                    wpc_v2_lazy_outcome('ack_restored_reject'); return true;
                }
            }
        }


        $rg_entry_id = isset($entry['imageID']) ? (int) $entry['imageID'] : 0;
        if ($rg_entry_id > 0 && function_exists('wpc_v2_landing_refused') && wpc_v2_landing_refused($rg_entry_id)) {
            wpc_v2_landing_refused_receipt($rg_entry_id, 'lazy-cdn');
            wpc_v2_lazy_outcome('ack_restored_reject'); return true;
        }

        $master_id = isset($rg_id) && $rg_id > 0 ? (int) $rg_id : $rg_entry_id;
        if ($master_id > 0 && function_exists('wpc_v2_master_refusal')
            && wpc_v2_master_refusal('', $abs_path, ['id' => $master_id, 'size' => $size_label, 'fmt' => $format, 'src' => 'lazy-cdn']) !== null) {
            wpc_v2_lazy_outcome('refused_master_protected');
            wpc_v2_lazy_fail_note('refused_master_protected', basename($abs_path));
            error_log('[WPC LazyCDN] refused_master_protected imageID=' . $master_id . ' dest_tail=' . substr($abs_path, -60));
            return true;
        }

        $landed_id  = isset($entry['imageID']) ? (string) $entry['imageID'] : '';
        $landed_job = isset($entry['jobId']) ? (string) $entry['jobId'] : '';
        if (wpc_v2_sha256_dedup_seen($sha256) && @file_exists($abs_path)) {
            if (function_exists('wpc_v2_landed_receipt')) {
                wpc_v2_landed_receipt($landed_id, $size_label, $format, $abs_path, 'lazy_cdn', $landed_job);
            }
            wpc_v2_lazy_outcome('ack_dedup_ondisk'); return true;
        }


        if (file_exists($abs_path)) {
            $existing_size = filesize($abs_path);
            $expected_size = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;
            if ($expected_size > 0 && $existing_size === $expected_size) {
                $on_disk_sha = @hash_file('sha256', $abs_path);
                if ($on_disk_sha !== false && hash_equals($on_disk_sha, $sha256)) {
                    wpc_v2_sha256_dedup_mark($sha256);
                    if (function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
                        wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, (int) $existing_size, $format, 0, $sha256);
                    }
                    if (function_exists('wpc_v2_landed_receipt')) {
                        wpc_v2_landed_receipt($landed_id, $size_label, $format, $abs_path, 'lazy_cdn', $landed_job);
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


        $wpc_out_ext = strtolower((string) pathinfo($abs_path, PATHINFO_EXTENSION));
        if (!in_array($wpc_out_ext, ['avif', 'webp'], true)) {
            wpc_v2_lazy_outcome('ack_non_nextgen');
            error_log('[WPC LazyCDN] ack-skip: non-next-gen variant ext=' . $wpc_out_ext . ' (lazy writes avif/webp only) ' . substr($abs_path, -50));
            return true;
        }

        if (!wpc_v2_pull_fetch_allowed($fetch_url)) {
            wpc_v2_lazy_outcome('refused_fetch_host');
            wpc_v2_lazy_fail_note('refused_fetch_host', (string) parse_url($fetch_url, PHP_URL_HOST));
            error_log('[WPC LazyCDN] refused_fetch_host host=' . substr((string) parse_url($fetch_url, PHP_URL_HOST), 0, 80));
            return true;
        }
        $max_bytes = wpc_v2_pull_max_bytes();
        // Fetch bytes from LS staging
        $resp = wpc_v2_pull_remote_get($fetch_url, [
            'timeout'   => 30,
            'sslverify' => true,
            'limit_response_size' => $max_bytes + 1,
            'headers'   => ['User-Agent' => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.0')],
        ]);
        if (is_wp_error($resp) && $resp->get_error_code() === 'wpc_pull_too_large') {
            wpc_v2_lazy_outcome('refused_too_large');
            wpc_v2_lazy_fail_note('refused_too_large', '>' . $max_bytes);
            error_log('[WPC LazyCDN] refused_too_large cap=' . $max_bytes . ' url_tail=' . substr($fetch_url, -50));
            return true;
        }
        if (is_wp_error($resp) && in_array($resp->get_error_code(), ['wpc_pull_foreign_host', 'wpc_pull_redirect'], true)) {
            wpc_v2_lazy_outcome('refused_fetch_host');
            wpc_v2_lazy_fail_note('refused_fetch_host', $resp->get_error_code());
            error_log('[WPC LazyCDN] refused_fetch_host ' . $resp->get_error_code() . ' url_tail=' . substr($fetch_url, -50));
            return true;
        }
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
        if (is_string($bytes) && strlen($bytes) > $max_bytes) {
            wpc_v2_lazy_outcome('refused_too_large');
            wpc_v2_lazy_fail_note('refused_too_large', strlen($bytes) . '>' . $max_bytes);
            error_log('[WPC LazyCDN] refused_too_large cap=' . $max_bytes . ' url_tail=' . substr($fetch_url, -50));
            return true;
        }
        if (!is_string($bytes) || $bytes === '') {
            wpc_v2_lazy_fail_note('bytes_fetch_empty'); error_log('[WPC LazyCDN] fetch_empty_body url_tail=' . substr($fetch_url, -50));
            return false;
        }


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

        $smaller_sibling = function_exists('wpc_v2_find_smaller_sibling') ? wpc_v2_find_smaller_sibling($abs_path, strlen($bytes)) : '';
        if ($smaller_sibling !== '') {
            $GLOBALS['wpc_kept_original_by_sha256'][$sha256] = $smaller_sibling;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('kept-original', basename($abs_path), '', ['twin' => strlen($bytes), 'vs' => $smaller_sibling]);
            }
            wpc_v2_lazy_outcome('kept_original');
            return true;
        }
        $foreign_host = isset($derived['foreign_host']) ? (string) $derived['foreign_host'] : '';
        $identity = function_exists('wpc_v2_variant_identity')
            ? wpc_v2_variant_identity($bytes, $abs_path, function_exists('wpc_v2_variant_claim') ? wpc_v2_variant_claim($entry) : [])
            : ['verdict' => 'unverified', 'reason' => 'no-owner', 'distance' => null, 'source' => '', 'claim' => 'none'];
        $identity_refused = function_exists('wpc_v2_variant_identity_refused')
            ? wpc_v2_variant_identity_refused($identity, $foreign_host)
            : $foreign_host !== '';
        if ($identity_refused) {
            if (function_exists('wpc_v2_variant_identity_refuse')) {
                wpc_v2_variant_identity_refuse($identity, $abs_path, 'lazy-cdn', $foreign_host !== '' ? ['host' => $foreign_host] : []);
            }
            wpc_v2_lazy_outcome('refused_identity');
            wpc_v2_lazy_fail_note('refused_identity', basename($abs_path) . ' ' . (string) $identity['verdict'] . ':' . (string) $identity['reason'] . ($foreign_host !== '' ? ' host=' . $foreign_host : ''));
            error_log('[WPC LazyCDN] refused_identity verdict=' . (string) $identity['verdict'] . ' reason=' . (string) $identity['reason'] . ' dest_tail=' . substr($abs_path, -60) . ($foreign_host !== '' ? ' host=' . $foreign_host : ''));
            return true;
        }
        $tmp = $abs_path . '.wpc_lazycdn_tmp_' . wp_generate_password(8, false);
        if (wpc_fs_put($tmp, $bytes) === false) {
            $err = error_get_last();
            if (function_exists('wpc_v2_landed_failure')) {
                wpc_v2_landed_failure($landed_id, $size_label, $format, $abs_path, 'lazy_cdn', $landed_job, 'write_failed');
            }
            wpc_v2_lazy_fail_note('disk_write_failed', substr($abs_path, -60));
            error_log(sprintf(
                '[WPC LazyCDN] write_failed bytes=%d dest_tail=%s msg=%s',
                strlen($bytes), substr($abs_path, -60), $err['message'] ?? '-'
            ));
            return false;
        }
        if (!@rename($tmp, $abs_path)) {
            $err = error_get_last();
            if (function_exists('wpc_v2_landed_failure')) {
                wpc_v2_landed_failure($landed_id, $size_label, $format, $abs_path, 'lazy_cdn', $landed_job, 'rename_failed');
            }
            wpc_v2_lazy_fail_note('disk_rename_failed', substr($abs_path, -60));
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

        if (function_exists('wpc_v2_landed_receipt')) {
            wpc_v2_landed_receipt($landed_id, $size_label, $format, $abs_path, 'lazy_cdn', $landed_job);
        }
        wpc_v2_sha256_dedup_mark($sha256);
        wpc_v2_lazy_outcome('wrote');


        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()
            && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('ingest_trace', 15)))
        update_option('wpc_v2_last_ingest_trace', [
            't'            => time(),
            'outcome'      => 'wrote',
            'origin_url'   => substr((string) $origin_url, 0, 160),
            'abs_path'     => substr((string) $abs_path, -90),
            'exists_after' => (int) @file_exists($abs_path),
            'size_after'   => (int) (@file_exists($abs_path) ? @filesize($abs_path) : 0),
            'expect_bytes' => (int) (isset($entry['bytes']) ? (int) $entry['bytes'] : strlen($bytes)),
        ], false);


        $attachment_id = 0;
        $clean_origin  = preg_replace('/\?.*$/', '', $origin_url);


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
                $attachment_id = wpc_v2_att($rc);
                if ($attachment_id > 0) break;
            }
        }


        if (function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
            wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, strlen($bytes), $format, $attachment_id, $sha256);
        }


        if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
            // Purges the landed file AND its -WxH format siblings (the negotiated URL the browser
            // actually hits) so the on-disk variant serves on the NEXT request, not after the edge TTL.
            wpc_v2_enqueue_landed_purge($abs_path);
        }


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
