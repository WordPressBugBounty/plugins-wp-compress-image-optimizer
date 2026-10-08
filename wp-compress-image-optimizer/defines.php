<?php
include_once __DIR__ . '/addons/cache/wpc-fs.php';
define('WPS_IC_TEXTDOMAIN', 'wp-compress-image-optimizer');

if (!function_exists('wpc_crit_meta_write')) {
    /**
     * Atomic pointer/state write (incident report B1+B3): temp-and-rename so a
     * concurrent reader never sees a truncated/empty pointer, and failures journal
     * ('meta-write-fail') instead of vanishing behind @. Drop-in for two-arg
     * @file_put_contents; existing .tmp.-based writers and LOCK_EX content writes
     * keep their own handling.
     */
    function wpc_crit_meta_write($path, $value)
    {
        try {
            $wpc_mt = $path . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 6);
            if (wpc_fs_put($wpc_mt, (string) $value) === false) {
                // Parent dir may have been purged out from under us (rm-raced land legs
                // journaled exactly this) — one repair attempt, then fail loudly.
                $wpc_md = dirname((string) $path);
                if (!is_dir($wpc_md)) {
                    @mkdir($wpc_md, 0777, true);
                }
                if (wpc_fs_put($wpc_mt, (string) $value) === false) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('meta-write-fail', basename($wpc_md), '', ['f' => basename((string) $path)]);
                    }
                    return false;
                }
            }
            if (!@rename($wpc_mt, $path)) {
                @unlink($wpc_mt);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('meta-write-fail', basename(dirname((string) $path)), '', ['f' => basename((string) $path), 'op' => 'rename']);
                }
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_crit_meta_files')) {
    /**
     * Canonical crit-dir pointer vocabulary (incident report B4) — the ONE list.
     * NOTE: consumers that keep/wipe SUBSETS (purge-preserve, the .317 used_tpl
     * unlink) are semantic choices, not drift — do not blanket-rewire them; new
     * pointer files get added HERE first so GC/debug/tooling see them.
     */
    function wpc_crit_meta_files()
    {
        return ['url.txt', 'uuid.txt', 'land_uuid.txt', 'dispatch_ts.txt', 'land_ts.txt',
            'stale.txt', 'tpl.txt', 'used_tpl.txt', 'lcp_url.txt', 'lcp_src.txt',
            'delay_url.txt', 'fonts_url.txt', 'used_css_url.txt', 'used_css_mobile_url.txt',
            'used_css_desktop_url.txt', 'used_css_sheets_url.txt', 'crit_combined_url.txt',
            'crit_combined_src.txt', 'font-preload.txt', 'corpus.txt', 'land_corpus.txt', 'dom_ids54.txt',
            'dispatch_record.json'];
    }
}

define('WPS_IC_MAXWIDTH', 3000);
define('WPS_IC_QUEUE_EXECUTION_TIME', 360);
define('WPS_IC_LOCAL_V', 4);

/*
 * Persisted keys: option and transient names, and cron hook names, that are written to the
 * database or to WordPress's schedule and therefore cannot change value. The name is the
 * constant; the string stays exactly what already sits in every installed site's options
 * table and cron array. Changing one of these values orphans live rows and scheduled events.
 */
define('WPC_UPLOADS_HARDEN_VERSION_OPTION', 'wpc_uploads_harden649');
define('WPC_RUM_SAMPLE_RATE_OPTION', 'wpc_rum_sample46');
define('WPC_BULK_INFLIGHT_OPTION', 'wpc_bulk_inflight199');
define('WPC_LANDED_PURGE_LOCK_OPTION', 'wpc_landed_purge_lock88');
define('WPC_LANDED_PURGE_DRAIN_HOOK', 'wpc_landed_purge_drain88');
define('WPC_POLICY_OPTION', 'wpc_policy23');
define('WPC_POLICY_LOCAL_OPTION', 'wpc_policy23_local');
define('WPC_POLICY_MATERIALIZE_HOOK', 'wpc_policy23_materialize');
define('WPC_POLICY_RESYNC_HOOK', 'wpc_policy23_resync');
define('WPC_VARIANT_FORGET_HOOK', 'wpc_v2_variant_forget23');
define('WPC_TWIN_BYTES_OPTION', 'wpc_twin_bytes29');
define('WPC_SIZED_TRIGGER_DRAIN_HOOK', 'wpc_szt_drain79');
define('WPC_THUMB_REGEN_SWEEP_HOOK', 'wpc_regen_sweep12');
define('WPC_CF_VARY_IMAGES_OPTION', 'wpc_cf_vary_images_ok');
// A key inside WPS_IC_SETTINGS, not an option of its own: the settings page, the v4 save and the
// agency relay all carry it with the rest of the settings row.
define('WPC_NEGOTIATED_PUBLIC_SETTING', 'htaccess-negotiated-public');
if (empty($_GET['min_debug'])) {
  define('WPS_IC_MIN', '.min'); // .min => script.min.js
} else {
  define('WPS_IC_MIN', ''); // .min => script.min.js
}

define('WPS_IC_CF', 'wps-ic-cf');
define('WPS_IC_CF_CNAME', 'wps-ic-cf-cname');
define('WPS_IC_GB', 1000000000);
define('WPC_IC_CACHE_EXPIRE', 86400); // 24 hours
define('WPS_IC_ACCOUNT_STATUS_MEMORY', 60*60); // 1 hour

// Fonts Scan API
define('WPS_IC_FONTS_SCAN', 'https://google-fonts.zapwp.net/scan');
define('WPS_IC_FONTS_DIR', WP_CONTENT_DIR . '/cache/wp-cio-fonts/');
define('WPS_IC_FONTS_URL', function_exists('content_url') ? content_url('cache/wp-cio-fonts/') : WP_CONTENT_URL . '/cache/wp-cio-fonts/');
define('WPS_IC_FONTS_MAP', 'wps_ic_fonts_map');

// Local API
define('WPS_IC_API_USERAGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36');

define('WPS_IC_APIURL', 'https://legacy-eu.wpcompress.com/');
define('WPS_IC_KEYSURL', 'https://keys.wpmediacompress.com/');

// Real URL
define('WPS_IC_CRITICAL_API_URL', 'https://crit-push.zapwp.net/generate');
define('WPS_IC_CRITICAL_API_URL_HOME', 'https://crit-push.zapwp.net/generate');
define('WPS_IC_PAGESPEED_API_URL_HOME', 'http://pagespeed.zapwp.net/run-pagespeed');
define('WPS_IC_PAGESPEED_RESULTS_HOME', 'http://pagespeed.zapwp.net/get-results/');


if (!defined('WPS_IC_OPTIMIZE_API_URL')) {


    define('WPS_IC_OPTIMIZE_API_URL', 'https://pagespeed.zapwp.net/optimize');
}
if (!defined('WPS_IC_OPTIMIZE_STATUS_API_URL')) {
    define('WPS_IC_OPTIMIZE_STATUS_API_URL', 'https://pagespeed.zapwp.net/optimize-status');
}

// Others
define('WPS_IC_PAGESPEED_API_URL', 'http://pagespeed.zapwp.net/run-pagespeed');
define('WPS_IC_PAGESPEED_RESULTS', 'http://pagespeed.zapwp.net/get-results/');
define('WPS_IC_JOB_TRANSIENT', 'wps_ic_job_transient');

define('WPS_IC_CRITICAL_API_ASSETS_URL', 'https://loadbalancer-critical.zapwp.net/assets.php');
define('WPS_IC_PRELOADER_API_URL', 'https://preloader.wpcompress.com/v2/index.php');

define('WPS_IC_IN_BULK', 'wps_ic_in_bulk');
define('WPS_IC_MU_SETTINGS', 'wps_ic_mu_settings');


// How many tests can fail before it's marked as failuire?
define('WPS_IC_TEST_FAILURES', 80);


define('WPS_IC_TESTS', 'wpc-tests');
define('WPS_IC_LITE_GPS_HISTORY', 'wps_ic_initial_gps_history');
define('WPS_IC_LITE_GPS', 'wps_ic_initial_gps');
define('WPS_IC_GUI', 'wps_ic_gui');
define('WPS_IC_SETTINGS', 'wps_ic_settings');
if (!defined('WPS_IC_CACHE')) {
	define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');
}

define('WPS_IC_CSS', WP_CONTENT_DIR . '/cache/wp-cio/css');
define('WPS_IC_CSS_URL', WP_CONTENT_URL . '/cache/wp-cio/css');


define('WPS_IC_CACHE_URL', WP_CONTENT_URL . '/cache/wp-cio/');

define('WPS_IC_PRESET', 'wps_ic_preset_setting');
define('WPS_IC_OPTIONS', 'wps_ic');
define('WPS_IC_OPTIONS_V2', 'wps_ic_options');

define('WPS_IC_BULK', 'wps_ic_bulk');

$plugin_dir = str_replace(site_url('/', 'https'), '', WP_PLUGIN_URL);
$plugin_dir = str_replace(site_url('/', 'http'), '', $plugin_dir);

// Guarded like WPS_IC_CACHE above: a caller that already knows where the plugin lives may set
// these before this file loads (the test bootstrap does, so a suite that never loads defines.php
// still has them). On a site nothing else defines them, so the value is unchanged.
if (!defined('WPS_IC_URI')) {
	define('WPS_IC_URI', plugin_dir_url(__FILE__));
}
if (!defined('WPS_IC_DIR')) {
	define('WPS_IC_DIR', realpath(plugin_dir_path(__FILE__)) . '/');
}
define('WPS_IC_ASSETS', WPS_IC_URI . 'assets');

// IP Whitelisting
define('WPC_API_WHITELIST', WPS_IC_DIR . 'whitelist-ip.txt');

define('WPS_IC_IMAGES', $plugin_dir . '/wp-compress-image-optimizer/assets/images');
define('WPS_IC_TEMPLATES', plugin_dir_path(__FILE__) . 'templates/');

define('WPS_IC_UPLOADS_DIR', WP_CONTENT_DIR . '/uploads');

define('WPS_IC_CRITICAL', WP_CONTENT_DIR . '/cache/critical/');
define('WPS_IC_CRITICAL_URL', WP_CONTENT_URL . '/cache/critical/');

define('WPS_IC_COMBINE', WP_CONTENT_DIR . '/cache/combine/');
define('WPS_IC_COMBINE_URL', WP_CONTENT_URL . '/cache/combine/');

define('WPS_IC_LOG', WP_CONTENT_DIR . '/cache/logs/');
define('WPS_IC_LOG_URL', WP_CONTENT_URL . '/cache/logs/');
define('WPC_WARMUP_LOG_SETTING', 'wps_ic_warmup_log');

if (!file_exists(WP_CONTENT_DIR . '/cache')) {
  mkdir(WP_CONTENT_DIR . '/cache');
}

if (!file_exists(rtrim(WPS_IC_CACHE, '/'))) {
  mkdir(rtrim(WPS_IC_CACHE, '/'));
}

if (!file_exists(rtrim(WPS_IC_CRITICAL, '/'))) {
  mkdir(rtrim(WPS_IC_CRITICAL, '/'));
}

if (!file_exists(rtrim(WPS_IC_LOG, '/'))) {
  mkdir(rtrim(WPS_IC_LOG, '/'));
}

// Stats v2
define('WPS_IC_STATS_BULK_FILES', 'wps_ic_stats_bulk_files');
define('WPS_IC_STATS_BULK_TOTAL_FILES', 'wps_ic_stats_bulk_total_files');
define('WPS_IC_STATS_BULK_SAVINGS', 'wps_ic_stats_bulk_savings');
define('WPS_IC_STATS_BULK_AVG', 'wps_ic_stats_bulk_avg');
define('WPS_IC_STATS_FILES', 'wps_ic_files_processed');
define('WPS_IC_STATS_BYTES', 'wps_ic_bytes_saved');
define('WPS_IC_STATS_AVG_REDUCTION', 'wps_ic_avg_reduction');
// ── Worker-safe advisory lock (v7.10.406, DB-scoped .409) ─────────────────────
// Non-blocking GET_LOCK with a bounded micro-retry. Replaces blocking
// GET_LOCK(name, 5..15): a busy lock now costs a few ms of retry, never a
// 5-15s pinned/convoyed FPM worker.
//
// MySQL GET_LOCK names are SERVER-global, not per-database — so on shared hosting
// two tenants locking the same logical name (e.g. wpc_bg_meta_5) would false-share.
// wpc_lock_name() folds DB_NAME + table prefix into a fixed 22-char hash so every
// site gets its own lock space (and we never hit the 64-char lock-name limit).
//
// CONTRACT: wpc_worker_lock() and wpc_worker_unlock() BOTH derive the name via
// wpc_lock_name() — one shared derivation, so acquire and release can never
// disagree. Never call raw GET_LOCK/RELEASE_LOCK on a name you locked via these.
// ── OPcache refresh gateway (v7.10.514) ───────────────────────────────────────
// opcache_reset() is POOL-WIDE: WP core and every other plugin recompile on their
// next request. Only OUR files change on a plugin update, so on a 4-worker host a
// full reset makes the entire pool pay for a change that belongs to one directory.
// Invalidate our own tree instead and leave the rest of the pool warm.
if (!function_exists('wpc_opcache_refresh')) {
    function wpc_opcache_refresh($ctx = '') {
        if (function_exists('apply_filters') && apply_filters('wpc_opcache_full_reset', false, $ctx)) {
            $wpc_or = function_exists('opcache_reset') ? (int) (bool) @opcache_reset() : 0;
            if (function_exists('wpc_purge_record')) {
                wpc_purge_record('opcache', 'reset_full', 'pool', 1, (bool) $wpc_or, (string) $ctx);
            }
            return $wpc_or;
        }
        if (!function_exists('opcache_invalidate') || !defined('WPS_IC_DIR')) {
            return 0;
        }
        $invalidated_count = 0;
        $invalidate_cap = function_exists('apply_filters')
            ? (int) apply_filters('wpc_opcache_invalidate_cap', 1200) : 1200;
        try {
            $file_iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(WPS_IC_DIR, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($file_iterator as $file_info) {
                if ($invalidated_count >= $invalidate_cap) {
                    break;
                }
                $file_path = (string) $file_info;
                if (substr($file_path, -4) !== '.php') {
                    continue;
                }
                @opcache_invalidate($file_path, true);
                $invalidated_count++;
            }
        } catch (\Throwable $e) {
        }
        // The advanced-cache drop-in is a COPY outside our tree; miss it and wp-content
        // keeps serving the old bytecode after an update.
        if (defined('WP_CONTENT_DIR') && @is_file(WP_CONTENT_DIR . '/advanced-cache.php')) {
            @opcache_invalidate(WP_CONTENT_DIR . '/advanced-cache.php', true);
            $invalidated_count++;
        }
        // v7.21.277 — the fast-404 MU drop-in is the same class (columbus receipt: .276
        // installed, sync rewrote the file, the OLD opcode kept answering 404s — hosts
        // with validate_timestamps=0 and no PHP-restart access stay stale forever).
        if (function_exists('wpc_v2_fast404_file')) {
            $fast404_file = (string) wpc_v2_fast404_file();
            if ($fast404_file !== '' && @is_file($fast404_file)) {
                @opcache_invalidate($fast404_file, true);
                $invalidated_count++;
            }
        }
        return $invalidated_count;
    }
}
// ── Diagnostic sleep gateway (v7.10.514) ──────────────────────────────────────
// The CF/DNS diagnostics sleep to let propagation settle — harmless on an idle box,
// fatal on a busy 4-worker one where the selftest's 1+2+3+1+2 = 9s of pure sleep is
// a quarter of the pool held doing nothing. A per-call cap is not enough because the
// damage is the SUM, so this also carries a per-request cumulative budget, and sheds
// the wait entirely when the box is already under pressure or in Safe Mode.
if (!function_exists('wpc_diag_sleep')) {
    function wpc_diag_sleep($seconds, $ctx = '') {
        $seconds = (float) $seconds;
        if ($seconds <= 0) {
            return 0.0;
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return 0.0;
        }
        if (function_exists('wpc_safe_mode') && wpc_safe_mode()) {
            return 0.0;
        }
        $per_call_cap = function_exists('apply_filters')
            ? (float) apply_filters('wpc_diag_sleep_cap_s', 3.0, $ctx) : 3.0;
        $request_budget = function_exists('apply_filters')
            ? (float) apply_filters('wpc_diag_sleep_budget_s', 4.0, $ctx) : 4.0;
        if ($per_call_cap <= 0 || $request_budget <= 0) {
            return 0.0;
        }
        $used_seconds = isset($GLOBALS['wpc_diagnostic_sleep_seconds_used']) ? (float) $GLOBALS['wpc_diagnostic_sleep_seconds_used'] : 0.0;
        $remaining_seconds = $request_budget - $used_seconds;
        if ($remaining_seconds <= 0) {
            return 0.0;
        }
        $sleep_seconds = min($seconds, $per_call_cap, $remaining_seconds);
        if ($sleep_seconds <= 0) {
            return 0.0;
        }
        $GLOBALS['wpc_diagnostic_sleep_seconds_used'] = $used_seconds + $sleep_seconds;
        usleep((int) round($sleep_seconds * 1000000));
        return $sleep_seconds;
    }
}
// ── Object-cache flush gateway (v7.10.510) ────────────────────────────────────
// wp_cache_flush() empties the ENTIRE object cache, and on a persistent backend
// (Redis/Memcached — Cloudways default) that includes WP's own 'doing_cron'
// transient. That transient is the ONLY throttle holding spawn_cron() to one run
// per WP_CRON_LOCK_TIMEOUT, so flushing it means every subsequent request spawns
// wp-cron.php. Measured on wpcompress.com: four spawns inside two seconds after a
// purge, then four concurrent 62s renders at load 1.2 (blocked, not computing).
//
// The page cache is FILES + CDN. The object cache holds WP's own state and other
// plugins' — purging pages never required emptying it. Preserve the cron lock
// across any flush; wpc_object_cache_flush_on => false disables flushing outright.
if (!function_exists('wpc_object_cache_flush')) {
    function wpc_object_cache_flush($ctx = '') {
        if (!function_exists('wp_cache_flush')) { return false; }
        if (function_exists('apply_filters') && !apply_filters('wpc_object_cache_flush_on', true, $ctx)) {
            return false;
        }
        $cron_lock = function_exists('get_transient') ? get_transient('doing_cron') : false;
        $flushed = (bool) @wp_cache_flush();
        if ($cron_lock !== false && function_exists('set_transient')) {
            set_transient('doing_cron', $cron_lock);
        }
        if (function_exists('wpc_purge_record')) {
            wpc_purge_record('object-cache', 'flush', 'site', 1, $flushed, (string) $ctx);
        }
        return $flushed;
    }
}
if (!function_exists('wpc_lock_name')) {
    function wpc_lock_name($logical) {
        global $wpdb;
        $prefix = (isset($wpdb) && is_object($wpdb)) ? $wpdb->prefix : '';
        $db     = defined('DB_NAME') ? DB_NAME : '';
        return 'wl' . substr(md5($db . '|' . $prefix . '|' . (string) $logical), 0, 20);
    }
}
if (!function_exists('wpc_worker_lock')) {
    function wpc_worker_lock($logical, $budget_ms = null) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return false; }
        if ($budget_ms === null) {
            $budget_ms = function_exists('apply_filters') ? (int) apply_filters('wpc_worker_lock_budget_ms', 500) : 500;
        }
        $name = wpc_lock_name($logical);
        $t0 = microtime(true);
        $deadline = $t0 + max(0, (int) $budget_ms) / 1000;
        for (;;) {
            $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $name));
            if ($got === '1' || $got === 1) {
                if (function_exists('wpc_prof_mark')) { wpc_prof_mark('lock:' . $logical, $t0); }
                return true;
            }
            if (microtime(true) >= $deadline) {
                // A CONTENDED lock is the finding, not the acquisition — label it separately.
                if (function_exists('wpc_prof_mark')) { wpc_prof_mark('LOCKFAIL:' . $logical, $t0); }
                return false;
            }
            usleep(30000); // 30ms between non-blocking attempts; common case never reaches here
        }
    }
}
if (!function_exists('wpc_worker_unlock')) {
    function wpc_worker_unlock($logical) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", wpc_lock_name($logical)));
    }
}

if (!function_exists('wpc_css_is_icon_font')) {
    /**
     * Icon-font families must never get font-display:swap — swap paints the raw codepoint in the
     * fallback face (Divi's menu arrow is content:"3", so it shows a literal "3"), then reflows to
     * the glyph. Block keeps it invisible until correct. 'ETmodules' (Divi/Elegant Themes) matched
     * none of the historic patterns, so Divi's icon font was being treated as text.
     */
    function wpc_css_is_icon_font($family)
    {
        $f = strtolower(trim((string) $family, " \t\"'"));
        if ($f === '') { return false; }
        return (bool) preg_match(
            '/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple\.?line|etmodules|et-?modules|divi/i',
            $f
        );
    }
}

if (!function_exists('wpc_font_remote_range_auto_key')) {
    /**
     * The remote-range map key for a fonts.json entry the service marked `weight_auto`: a variable
     * font the theme declared without a font-weight, whose subset carries the font's own range.
     * 'family|auto=300 700|style', or '' for any other entry (those keep their weight key).
     * The weightless theme face looks this key up and declares the same range
     * (wps_cdn_rewrite::font_face_range_gate); both map writers key it here.
     */
    function wpc_font_remote_range_auto_key($fontEntry)
    {
        if (!is_array($fontEntry) || empty($fontEntry['weight_auto']) || empty($fontEntry['family'])) { return ''; }
        $span = trim(preg_replace('/\s+/', ' ', preg_replace('/[^0-9 ]/', ' ', (string) ($fontEntry['weight'] ?? ''))));
        if (!preg_match('/^(\d{2,4}) (\d{2,4})$/', $span, $spanMatch) || (int) $spanMatch[1] >= (int) $spanMatch[2]) { return ''; }
        $family = strtolower(trim(str_replace(["'", '"', '\\', "\r", "\n", '<', '>'], '', (string) $fontEntry['family'])));
        if ($family === '') { return ''; }
        $style = (strtolower((string) ($fontEntry['style'] ?? 'normal')) === 'italic') ? 'italic' : 'normal';
        return $family . '|auto=' . $spanMatch[1] . ' ' . $spanMatch[2] . '|' . $style;
    }
}

if (!function_exists('wpc_ua_is_mobile')) {
    // Single source of truth for the mobile-UA test. The crit-choice detector
    // (wps_rewriteLogic::isMobile) and the cache-BUCKET detectors
    // (wps_cacheHtml/wps_cdn_rewrite::is_mobile) all delegate here, so the device the
    // page is RENDERED for can never disagree with the bucket it is STORED in. The .663
    // regression was three broadened bucket copies against one un-broadened crit copy;
    // one function makes that drift impossible. Superset of the old 7-keyword crit list.
    function wpc_ua_is_mobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }
        return isset($_SERVER['HTTP_USER_AGENT']) && wpc_ua_mobile_match((string) $_SERVER['HTTP_USER_AGENT']);
    }
}

/**
 * v7.10.530 — chain-level breaker for the render-kick loop.
 * Receipted on the flagship: kick-rx{have:0} -> pointer-unresolvable-key -> kick-unresolvable ->
 * render-kick, repeating on a 3 s cadence and never able to succeed. Every ceiling in that path
 * (wpc_kick_fire_*, wpc_repull_kick_*, wpc_kick_budget30) is a TRANSIENT, so when the object cache
 * sheds under load every breaker opens at once and the loop feeds the exhaustion that evicted it.
 * These two gates live in an OPTION and therefore survive object-cache loss.
 */
function wpc_kick_dead_mark($key)
{
    $key = (string) $key;
    if ($key === '' || !function_exists('get_option')) {
        return;
    }
    $dead = get_option('wpc_kick_dead');
    $dead = is_array($dead) ? $dead : [];
    $dead[md5($key)] = time();
    if (count($dead) > 64) {
        asort($dead);
        $dead = array_slice($dead, -64, null, true);
    }
    update_option('wpc_kick_dead', $dead, true);
}

/** Resolvability is deterministic — a key that resolved to nothing cannot resolve by retrying. */
function wpc_kick_is_dead($key)
{
    $key = (string) $key;
    if ($key === '' || !function_exists('get_option')) {
        return false;
    }
    $dead = get_option('wpc_kick_dead');
    if (!is_array($dead) || empty($dead[md5($key)])) {
        return false;
    }
    // 6 h amnesty so a genuinely new permalink is never blacklisted permanently.
    return (time() - (int) $dead[md5($key)]) < 21600;
}

/** When the dead mark on $key runs out (unix time), or 0 when the key is not marked dead. */
function wpc_kick_dead_until($key)
{
    if (!wpc_kick_is_dead($key)) {
        return 0;
    }
    $dead = get_option('wpc_kick_dead');
    return (int) $dead[md5((string) $key)] + 21600;
}

/**
 * Forget the dead marks of the keys a removal or an update touches: 'all' or one url key.
 * Every wipe and every plugin update clears them: a mark written after one unresolvable kick
 * kept two pages of a test site without crit for a whole evening, refusing each of their kicks
 * silently through Remove Critical, a hard purge, settings saves and a plugin update.
 * Returns how many marks went.
 */
function wpc_kick_dead_clear($scope, $reason = '')
{
    if (!function_exists('get_option')) {
        return 0;
    }
    $dead = get_option('wpc_kick_dead');
    if (!is_array($dead) || $dead === []) {
        return 0;
    }
    $scope = (string) $scope === 'all' ? 'all' : ltrim((string) $scope, '/');
    if ($scope === 'all') {
        $cleared = count($dead);
        delete_option('wpc_kick_dead');
    } else {
        if (!isset($dead[md5($scope)])) {
            return 0;
        }
        unset($dead[md5($scope)]);
        $cleared = 1;
        update_option('wpc_kick_dead', $dead, true);
    }
    if (function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('kick-dead-cleared', $scope === 'all' ? '' : $scope, '', ['scope' => $scope, 'n' => $cleared, 'reason' => (string) $reason]);
    }
    return $cleared;
}

/*
 * THE TWO WARM BUTTONS. Purge & Preload warms a short list after its purge (filter
 * wpc_warm_batch_max): the pages come back by visits, and a long speculative rewarm is a render
 * storm on a small box. Start Optimization warms every uncached page at the warm queue's pace,
 * for at most WPC_WARM_RUN_MAX_HOURS; a run still unfinished then ends and keeps its list.
 * Both can be set in wp-config.php.
 */
if (!defined('WPC_WARM_BATCH_MAX')) {
    define('WPC_WARM_BATCH_MAX', 6);
}
if (!defined('WPC_WARM_RUN_MAX_HOURS')) {
    define('WPC_WARM_RUN_MAX_HOURS', 24);
}

/*
 * THE SERVICE'S HOLDS.
 *
 * A refusal from the crit service names how far its answer reaches (hold_scope): 'request' (that
 * request only), 'url' (that page), 'domain' or 'key' (every dispatch this site makes), 'version'
 * (every dispatch until the plugin version changes). From a service that does not send the field
 * the reach is read from the refusal type (wpc_gen_hold_scope). A hold ends at the refusal's
 * retry_after, clamped to 60 s .. 2 days (300 s when it names none); a version hold ends when
 * WPC_PLUGIN_VERSION changes, and an unrenderable_url hold after 30 days.
 *
 * Two rules narrow a site hold: one learned on an interior page does not hold the homepage (one
 * learned on the homepage holds every page), and a refusal that needs_corpus holds only
 * dispatches that do not carry both the page and its CSS. Automatic dispatches wait; a person's
 * click is never held. A page's demand_deferred hold does not hold a kick a visitor's browser
 * asked for (wpc_gen_human_evidence): the service lifts that deferral on the next dispatch that
 * carries a human view, so holding exactly that dispatch kept the page crit-less for the whole
 * retry_after (webdesign4u.com.au, 2026-09-28: /about-us/ and /business-website-hosting/ refused
 * every hit, beacon and interaction kick for 24 h). Kill switch: define WPC_GEN_SERVICE_HOLD
 * false (a domain or key refusal then holds only its own page).
 */
/* Transient prefixes (the stored names are unchanged). The service's served hold for one page (its
 * "nothing new for this input" answer), the kick receiver's pressure back-off for one page, and the
 * once-a-minute used-CSS backfill after a DB-free pull. */
if (!defined('WPC_GEN_SERVED_HOLD_PREFIX')) {
    define('WPC_GEN_SERVED_HOLD_PREFIX', 'wpc_gen_hold61_');
}
if (!defined('WPC_KICK_PRESSURE_BACKOFF_PREFIX')) {
    define('WPC_KICK_PRESSURE_BACKOFF_PREFIX', 'wpc_kick_pressure360_');
}
if (!defined('WPC_USED_CSS_BACKFILL_ONCE_PREFIX')) {
    define('WPC_USED_CSS_BACKFILL_ONCE_PREFIX', 'wpc_ucbf188_');
}

function wpc_gen_service_hold_enabled()
{
    return !defined('WPC_GEN_SERVICE_HOLD') || WPC_GEN_SERVICE_HOLD;
}

/** The reach of a refusal: its hold_scope, else its type's; a type not listed holds its page. */
function wpc_gen_hold_scope($errorType, $holdScope = '')
{
    if (in_array((string) $holdScope, ['request', 'url', 'domain', 'key', 'version'], true)) {
        return (string) $holdScope;
    }
    $byScope = [
        'request' => ['bad_request', 'push_optimized_html', 'server_busy', 'degraded', 'epoch_superseded', 'epoch_raise_throttled'],
        'domain'  => ['blocked_domain', 'domain_circuit', 'domain_capped', 'fetch_blocked', 'crit_limited', 'crit_wall_limited',
                      'not_consuming', 'interior_allowance'],
        'key'     => ['apikey_blocked', 'apikey_saturated', 'rate_limited', 'refusal_storm'],
        'version' => ['bad_version'],
    ];
    foreach ($byScope as $scope => $types) {
        if (in_array((string) $errorType, $types, true)) {
            return $scope;
        }
    }
    return 'url';
}

/**
 * Whether a refusal holds only dispatches without the page and its CSS: it says needs_corpus, or it
 * comes from a service that sends no hold_scope yet and is one that service lets such a dispatch past.
 */
function wpc_gen_hold_passable($errorType, array $answer)
{
    if (!empty($answer['needs_corpus'])) {
        return true;
    }
    return !isset($answer['hold_scope']) && in_array((string) $errorType, ['blocked_url', 'blocked_domain', 'domain_circuit'], true);
}

/** Whether $urlKey is the homepage's url key, resolved the way the dispatch paths resolve it. */
function wpc_gen_service_is_home_key($urlKey)
{
    $urlKey = ltrim((string) $urlKey, '/');
    if ($urlKey === '' || !function_exists('home_url') || !class_exists('wps_ic_url_key')) {
        return false;
    }
    static $homeKeys = [];
    $home = (string) home_url('/');
    if (!isset($homeKeys[$home])) {
        $homeKeys[$home] = ltrim((string) (new wps_ic_url_key())->setup($home), '/');
    }
    return $homeKeys[$home] !== '' && $homeKeys[$home] === $urlKey;
}

/**
 * Record the hold a refusal answered for $urlKey asks for, from its error_type, retry_after and
 * body ($answer: hold_scope, needs_corpus, retry_after_s). Returns the scope it was read as, ''
 * for an answer with no type. Receipt: gen-hold-set.
 */
function wpc_gen_hold_learn($urlKey, $errorType, $retryAfter, array $answer = [])
{
    $errorType = substr((string) $errorType, 0, 32);
    $urlKey = ltrim((string) $urlKey, '/');
    if ($errorType === '' || !function_exists('update_option')) {
        return '';
    }
    $scope = wpc_gen_hold_scope($errorType, isset($answer['hold_scope']) ? $answer['hold_scope'] : '');
    if (($scope === 'domain' || $scope === 'key') && !wpc_gen_service_hold_enabled()) {
        $scope = 'url';
    }
    if ($scope === 'request' || ($scope === 'url' && $urlKey === '')) {
        return $scope;
    }
    $retryAfter = (int) $retryAfter;
    if ($retryAfter <= 0 && isset($answer['retry_after_s'])) {
        $retryAfter = (int) $answer['retry_after_s'];
    }
    $wait = ($retryAfter <= 0 && $errorType === 'unrenderable_url') ? 30 * 86400 : min(172800, max(60, $retryAfter > 0 ? $retryAfter : 300));
    $until = time() + $wait;
    $home = $urlKey !== '' && wpc_gen_service_is_home_key($urlKey);
    $pass = wpc_gen_hold_passable($errorType, $answer);
    if ($scope === 'version') {
        if (!defined('WPC_PLUGIN_VERSION')) {
            return $scope;
        }
        update_option('wpc_gen_version_hold', ['version' => (string) WPC_PLUGIN_VERSION, 'type' => $errorType, 't' => time()], false);
        $until = 0;
    } elseif ($scope === 'url') {
        set_transient('wpc_gen_url_hold_' . md5($urlKey), ['until' => $until, 'type' => $errorType, 'pass' => $pass ? 1 : 0,
            'human' => wpc_gen_hold_human_passable($errorType) ? 1 : 0], $wait);
    } else {
        $holds = get_option('wpc_gen_site_holds', []);
        $holds = is_array($holds) ? $holds : [];
        $slot = ($home ? 'home' : 'interior') . ($pass ? '-corpus' : '');
        if (isset($holds[$slot]['until']) && (int) $holds[$slot]['until'] > $until) {
            $until = (int) $holds[$slot]['until'];
        } else {
            $holds[$slot] = ['until' => $until, 'type' => $errorType, 'scope' => $scope, 'home' => $home ? 1 : 0, 'pass' => $pass ? 1 : 0];
            update_option('wpc_gen_site_holds', $holds, false);
        }
    }
    if (function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('gen-hold-set', $urlKey, '', ['scope' => $scope, 'type' => $errorType, 'ra' => $retryAfter,
            'until' => $until, 'home' => $home ? 1 : 0, 'corpus' => $pass ? 1 : 0]);
    }
    return $scope;
}

/**
 * Whether a page hold of this refusal type lets a dispatch with human evidence through. Only
 * demand_deferred: crit-push defers a first-ever inner page until a real visitor is seen and lifts
 * the deferral on the next dispatch that says so (server.js new-URL budget, demandVerdict in
 * intake-policy.js: visitor=human with this view counted, or a RUM beacon for the page). Every
 * other page refusal is about the page itself and holds whoever asks.
 */
function wpc_gen_hold_human_passable($errorType)
{
    return (string) $errorType === 'demand_deferred';
}

/**
 * Whether this request carries a visitor's evidence for $urlKey: set by the kick admission when a
 * browser asked for the page (wpc_kick_admit), carried to the receiver on the signed loopback.
 */
function wpc_gen_human_evidence($urlKey)
{
    $urlKey = ltrim((string) $urlKey, '/');
    return $urlKey !== '' && !empty($GLOBALS['wpc_gen_human_evidence'][$urlKey]);
}

/**
 * The holds on an automatic dispatch for $urlKey (null: any interior page), each ['scope', 'type',
 * 'until', 'pass', 'human']. pass: a dispatch carrying the page and its CSS is not held by it.
 * human: a dispatch with a visitor's evidence is not held by it, and while this request carries
 * that evidence for $urlKey the hold is not listed. A version hold's until is PHP_INT_MAX.
 */
function wpc_gen_holds_for($urlKey = null)
{
    $holds = [];
    if (!function_exists('get_option')) {
        return $holds;
    }
    $now = time();
    $version = get_option('wpc_gen_version_hold');
    if (is_array($version) && defined('WPC_PLUGIN_VERSION') && (string) ($version['version'] ?? '') === (string) WPC_PLUGIN_VERSION) {
        $holds[] = ['scope' => 'version', 'type' => (string) ($version['type'] ?? ''), 'until' => PHP_INT_MAX, 'pass' => false];
    }
    $home = $urlKey !== null && wpc_gen_service_is_home_key($urlKey);
    if (wpc_gen_service_hold_enabled()) {
        $site = get_option('wpc_gen_site_holds', []);
        foreach (is_array($site) ? $site : [] as $hold) {
            if (!is_array($hold) || (int) ($hold['until'] ?? 0) <= $now || ($home && empty($hold['home']))) {
                continue;
            }
            $holds[] = ['scope' => (string) ($hold['scope'] ?? 'domain'), 'type' => (string) ($hold['type'] ?? ''),
                'until' => (int) $hold['until'], 'pass' => !empty($hold['pass'])];
        }
    }
    $urlKey = ltrim((string) $urlKey, '/');
    if ($urlKey !== '' && function_exists('get_transient')) {
        $hold = get_transient('wpc_gen_url_hold_' . md5($urlKey));
        if (is_array($hold) && (int) ($hold['until'] ?? 0) > $now && !(!empty($hold['human']) && wpc_gen_human_evidence($urlKey))) {
            $holds[] = ['scope' => 'url', 'type' => (string) ($hold['type'] ?? ''), 'until' => (int) $hold['until'], 'pass' => !empty($hold['pass']),
                'human' => !empty($hold['human'])];
        }
    }
    return $holds;
}

/**
 * Until when automatic dispatches for $urlKey wait, or 0 when nothing holds them; PHP_INT_MAX for
 * a version hold. $withPassable also counts the holds a dispatch carrying the page and its CSS passes.
 */
function wpc_gen_service_hold_until($urlKey = null, $withPassable = false)
{
    $until = 0;
    foreach (wpc_gen_holds_for($urlKey) as $hold) {
        if ($withPassable || !$hold['pass']) {
            $until = max($until, (int) $hold['until']);
        }
    }
    return $until;
}

function wpc_gen_service_hold_active($urlKey = null)
{
    return wpc_gen_service_hold_until($urlKey) > 0;
}

/** The hold that holds a dispatch of $args for $urlKey, or null: a passable one holds a body without html and css. */
function wpc_gen_hold_on_body($urlKey, array $args)
{
    foreach (wpc_gen_holds_for($urlKey) as $hold) {
        if (!$hold['pass'] || empty($args['html']) || empty($args['css'])) {
            return $hold;
        }
    }
    return null;
}

/** Receipt for a dispatch a hold withheld, sampled once per key per 120 s. */
function wpc_gen_service_hold_note($urlKey, $via, $hold = null)
{
    $sample = 'wpc_gen_held_' . md5((string) $urlKey);
    if (!function_exists('wpc_cache_first_log') || (function_exists('get_transient') && get_transient($sample))) {
        return;
    }
    if (function_exists('set_transient')) {
        set_transient($sample, 1, 120);
    }
    if (!is_array($hold)) {
        foreach (wpc_gen_holds_for($urlKey) as $candidate) {
            if (!$candidate['pass'] && (!is_array($hold) || $candidate['until'] > $hold['until'])) {
                $hold = $candidate;
            }
        }
    }
    wpc_cache_first_log('gen-service-hold', (string) $urlKey, '', ['via' => substr((string) $via, 0, 32),
        'scope' => is_array($hold) ? $hold['scope'] : '', 'type' => is_array($hold) ? $hold['type'] : '',
        'until' => is_array($hold) ? min((int) $hold['until'], 2147483647) : 0]);
}

/** An answer that was not a refusal: its page is no longer held by one. */
function wpc_gen_hold_forget_url($urlKey)
{
    $urlKey = ltrim((string) $urlKey, '/');
    if ($urlKey !== '' && function_exists('delete_transient')) {
        delete_transient('wpc_gen_url_hold_' . md5($urlKey));
    }
}

/**
 * A removal of crit forgets the demand_deferred holds of the pages it removes: the person asked for
 * new crit, and the service's contract for that refusal is retryable, backoff_scope none, "re-
 * dispatch after a real visitor is seen" (crit-push refusal.js), so the hold only paces
 * automatic dispatches and must not outlive the removal (webdesign4u.com.au, 2026-09-28: the
 * /about-us/ hold survived Remove Critical). Holds about the page itself (unrenderable_url and the
 * other retryable:false refusals) stay. $scope 'all' also sweeps the options table, since a
 * deferred page may have no crit folder. Returns how many went. Receipt: gen-hold-forgotten.
 */
function wpc_gen_hold_forget_deferred($scope, array $keys, $reason = '')
{
    if (!function_exists('get_transient') || !function_exists('delete_transient')) {
        return 0;
    }
    $names = [];
    foreach ($keys as $key) {
        $key = ltrim((string) $key, '/');
        if ($key !== '') {
            $names[] = 'wpc_gen_url_hold_' . md5($key);
        }
    }
    global $wpdb;
    if ((string) $scope === 'all' && isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_col') && isset($wpdb->options)) {
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_wpc_gen_url_hold_') . '%')) as $optionName) {
            $names[] = substr((string) $optionName, strlen('_transient_'));
        }
    }
    $gone = 0;
    foreach (array_unique($names) as $name) {
        $hold = get_transient($name);
        if (is_array($hold) && wpc_gen_hold_human_passable($hold['type'] ?? '') && delete_transient($name)) {
            $gone++;
        }
    }
    if ($gone > 0 && function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('gen-hold-forgotten', (string) $scope === 'all' ? '' : (string) $scope, '', ['n' => $gone, 'type' => 'demand_deferred',
            'reason' => substr((string) $reason, 0, 32)]);
    }
    return $gone;
}

/**
 * A plugin update: every hold an earlier version kept goes (its version hold, and the per-version
 * gate and site-wide waits of the builds before 7.24.33). Returns how many options went.
 */
function wpc_gen_holds_upgrade()
{
    if (!function_exists('delete_option')) {
        return 0;
    }
    $names = ['wpc_gen_service_wait_until', 'wpc_gen_service_wait_home_until'];
    $previous = (string) get_option('wpc_core_version', '');
    if ($previous !== '') {
        $names[] = 'wpc_gen_unretryable_' . $previous;
    }
    global $wpdb;
    if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_col') && isset($wpdb->options)) {
        $names = array_merge($names, (array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('wpc_gen_unretryable_') . '%')));
    }
    $version = get_option('wpc_gen_version_hold');
    if (is_array($version) && (!defined('WPC_PLUGIN_VERSION') || (string) ($version['version'] ?? '') !== (string) WPC_PLUGIN_VERSION)) {
        $names[] = 'wpc_gen_version_hold';
    }
    $gone = 0;
    foreach (array_unique($names) as $name) {
        if (get_option($name) !== false && delete_option($name)) {
            $gone++;
        }
    }
    if ($gone > 0 && function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('gen-holds-upgrade', '', '', ['n' => $gone, 'from' => substr($previous, 0, 16)]);
    }
    return $gone;
}

/**
 * v7.10.530 — HOST-INDEPENDENT CONCURRENCY CAP on the rewrite chain.
 * The rewrite is the most expensive thing the plugin does: 190-212 MB peak per render on a 724 KB
 * page (our own mem: instrument, flagship). BELT4 (.520) sheds on pressure, but pressure is
 * REACTIVE — by the time it trips, N x 190 MB is already in flight. A host tuned to 50 workers
 * therefore exhausts memory no matter what the plugin does afterwards, and customers do not tune
 * pm.max_children. This caps concurrent heavy renders from inside the plugin so the ceiling holds
 * on any host. Over the cap the page is served UNREWRITTEN — correct HTML, just unoptimised,
 * the same fail-open path BELT4 already proves safe.
 * Budget 0: never spin. A slot probe that blocks would defeat the purpose.
 */
function wpc_render_slot_acquire()
{
    if (!function_exists('wpc_worker_lock')) {
        return true; // fail OPEN — never block a render on the limiter itself
    }
    $cores = function_exists('wpc_box_cores') ? max(1, (int) wpc_box_cores()) : 4;
    $n = (int) (function_exists('apply_filters') ? apply_filters('wpc_render_slots', min(4, $cores)) : min(4, $cores));
    if ($n <= 0) {
        return true;
    }
    for ($i = 1; $i <= $n; $i++) {
        if (wpc_worker_lock('rslot' . $i, 0)) {
            $GLOBALS['wpc_render_slot_index'] = $i;
            // GET_LOCK is per-connection and frees on close, but persistent connections exist.
            if (function_exists('register_shutdown_function')) {
                register_shutdown_function(function () use ($i) {
                    if (function_exists('wpc_worker_unlock')) {
                        wpc_worker_unlock('rslot' . $i);
                    }
                });
            }
            return true;
        }
    }
    return false;
}

/**
 * v7.10.530 — pages not worth the pipeline.
 * The crit path had NO attachment check anywhere (nor warm.php / rewriteLogic / cache.class).
 * Receipted on the flagship: 19,955 files under cache/wp-cio/critical and still growing, while
 * the actual page cache held 20 — because every media item mints its own crit dir AND its own
 * render-kick (/wp-compress-logo-med/, /features/arrow-up-right-from-square-sha in the log).
 * Attachment pages are noindex by default and carry effectively no human traffic, so the whole
 * cost is waste. Cheap boolean, evaluated per render — no queries.
 */
function wpc_is_low_value_page()
{
    if (function_exists('is_attachment') && is_attachment()) {
        return true;
    }
    if (function_exists('is_search') && is_search()) {
        return true;
    }
    if (function_exists('is_feed') && is_feed()) {
        return true;
    }
    if (function_exists('is_trackback') && is_trackback()) {
        return true;
    }
    if (function_exists('is_robots') && is_robots()) {
        return true;
    }
    if (function_exists('is_favicon') && is_favicon()) {
        return true;
    }
    // v7.10.603 — A 404 IS THE MOST COMMON PAGE ON THE INTERNET. Credential scanners sweep
    // /.aws/config, /secrets.json, /.vite/manifest.json, /console continuously on every site;
    // each was paying a full rewrite (measured 414-511ms of OBCHAIN per probe on hawkeye at
    // load 11) plus atf-scope-open and lcp-preload-no-stem-match log WRITES, and was dressed
    // with the HOME PAGE's crit — that is where a 404 stem-matching play_720p.mp4 came from.
    // rewriteLogic:2770 already guarded the sentinel for this exact reason ("22.8s and 11.5s
    // on consecutive hits, measured"); its siblings never got the guard. Nothing a 404 renders
    // can be described by another URL's critical CSS.
    if (function_exists('is_404') && function_exists('did_action')
        && did_action('template_redirect') && is_404()
        && (!function_exists('apply_filters') || apply_filters('wpc_low_value_404', true))) {
        return true;
    }
    return (bool) (function_exists('apply_filters') ? apply_filters('wpc_low_value_page', false) : false);
}

/**
 * v7.10.530 — PLUGIN-LEVEL PROFILER.
 * Apache says 61 s, our shutdown markers say the 61 s sits between shutdown priority 0 and 1
 * (= wp_ob_end_flush_all = our rewrite chain), and the FPM slowlog says no request executed
 * for 10 s. Those cannot all be true, and none of them name a FUNCTION. This does: cumulative
 * wall-time per labelled pass, plus explicit accounting for the two things that block without
 * burning CPU - advisory locks and DB time. Costs one microtime() per call; no allocation.
 */
function wpc_prof_mark($label, $start)
{
    if (!isset($GLOBALS['wpc_profiler_pass_totals'])) {
        $GLOBALS['wpc_profiler_pass_totals'] = [];
    }
    $ms = (microtime(true) - (float) $start) * 1000;
    if (!isset($GLOBALS['wpc_profiler_pass_totals'][$label])) {
        $GLOBALS['wpc_profiler_pass_totals'][$label] = ['ms' => 0.0, 'n' => 0];
    }
    $GLOBALS['wpc_profiler_pass_totals'][$label]['ms'] += $ms;
    $GLOBALS['wpc_profiler_pass_totals'][$label]['n']++;
    return $ms;
}

/** Compact "label:ms/n" string, worst-first — only passes worth reading. */
function wpc_prof_dump($min_ms = 25)
{
    // Flush the still-open checkpoint, else the LAST span is silently lost — and the last span
    // is exactly where an unclosed-tag regex would spend its time.
    if (isset($GLOBALS['wpc_profiler_checkpoint_start'], $GLOBALS['wpc_profiler_checkpoint_label'])) {
        wpc_prof_mark($GLOBALS['wpc_profiler_checkpoint_label'], $GLOBALS['wpc_profiler_checkpoint_start']);
        unset($GLOBALS['wpc_profiler_checkpoint_start'], $GLOBALS['wpc_profiler_checkpoint_label']);
    }
    if (empty($GLOBALS['wpc_profiler_pass_totals']) || !is_array($GLOBALS['wpc_profiler_pass_totals'])) {
        return '';
    }
    $rows = $GLOBALS['wpc_profiler_pass_totals'];
    uasort($rows, function ($a, $b) {
        return ($b['ms'] == $a['ms']) ? 0 : (($b['ms'] < $a['ms']) ? -1 : 1);
    });
    $out = [];
    foreach ($rows as $k => $v) {
        if ($v['ms'] < $min_ms) {
            continue;
        }
        $out[] = $k . ':' . (int) $v['ms'] . '/' . (int) $v['n'];
        if (count($out) >= 12) {
            break;
        }
    }
    return implode(' ', $out);
}

/**
 * v7.10.530b — a span that ends when the FUNCTION returns.
 * The first cut of this bracket marked from callback entry to register_shutdown_function, which
 * fires after the whole WP shutdown — it would have reported the full request no matter where the
 * time went, and "confirmed" the rewrite chain wrongly. PHP destroys locals at function exit, so
 * __destruct is the honest end-of-callback signal.
 */
class Wpc_Profiler_Span
{
    private $label;
    private $t0;
    public function __construct($label)
    {
        $this->label = $label;
        $this->t0    = microtime(true);
    }
    public function __destruct()
    {
        if (function_exists('wpc_prof_mark')) {
            wpc_prof_mark($this->label, $this->t0);
        }
    }
}

/**
 * v7.10.531 — OUR OWN REQUESTS MUST NOT SPAWN CRON.
 * DISABLE_WP_CRON is a wp-config edit no customer will make, so the plugin has to solve this
 * itself. The amplification is ours: every warm loopback boots full WP, wp_cron() on init
 * spawns a self-POST to wp-cron.php, and that competes for the same workers and locks as the
 * render it was meant to help (12 self-POSTs in an 83-line window on the flagship). Visitor
 * requests still spawn cron exactly as before — only OUR internal loopbacks stand down, and
 * only when they are positively identified by the header we set ourselves.
 */
function wpc_suppress_self_cron()
{
    if (empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
        return false; // never change behaviour for a real visitor
    }
    if (!function_exists('remove_action') || !function_exists('apply_filters')) {
        return false;
    }
    if (!apply_filters('wpc_suppress_self_cron_enabled', true)) {
        return false;
    }
    remove_action('init', 'wp_cron');
    // Belt: if anything downstream still calls spawn_cron(), make it a no-op for this request.
    add_filter('pre_transient_doing_cron', function ($v) {
        return ($v === false || $v === null) ? microtime(true) : $v;
    }, 9999);
    return true;
}

// plugins_loaded fires before init, so remove_action('init','wp_cron') still lands.
if (function_exists('add_action')) {
    add_action('plugins_loaded', 'wpc_suppress_self_cron', 0);
}

/** v7.10.533 — checkpoint: records elapsed since the previous checkpoint in this request. */
function wpc_prof_cp($label)
{
    $now = microtime(true);
    if (isset($GLOBALS['wpc_profiler_checkpoint_start'])) {
        wpc_prof_mark($GLOBALS['wpc_profiler_checkpoint_label'], $GLOBALS['wpc_profiler_checkpoint_start']);
    }
    $GLOBALS['wpc_profiler_checkpoint_start']      = $now;
    $GLOBALS['wpc_profiler_checkpoint_label'] = 'rx:' . $label;
}

/**
 * v7.10.543 — GLOBAL CEILING ON ACCIDENTAL URL STREAMS.
 * PHP's filesystem functions (file_get_contents/filesize/file_exists/is_readable/filemtime) open a
 * NETWORK stream when handed a URL, inheriting default_socket_timeout - 60s on most hosts. They
 * bypass the WP HTTP API, so our http_n counter reads 0, and they run past fastcgi_finish_request
 * where the FPM slowlog cannot see them. Two such reads cost this site 18-42s per render and hid
 * from every instrument for a full night. Individual sites are guarded; this bounds the ones not
 * yet found. Restored on shutdown so nothing outside our render is affected.
 */
function wpc_bound_socket_timeout()
{
    if (!function_exists('ini_get') || !function_exists('ini_set')) {
        return;
    }
    $cur = (int) @ini_get('default_socket_timeout');
    $max = (int) apply_filters('wpc_socket_timeout_ceiling', 5);
    if ($cur <= $max || $max <= 0) {
        return;
    }
    @ini_set('default_socket_timeout', (string) $max);
    // THE AMPLIFIER, KILLED GLOBALLY: file_get_contents() FOLLOWS REDIRECTS by default. A missing
    // artifact 302s to the page, so a 20-byte text read renders a full 708KB WordPress page that
    // re-enters this same plugin chain. follow_location=0 turns that back into a tiny 302 body.
    // This bounds every URL-shaped filesystem read in the codebase, including ones not yet found.
    if (function_exists('stream_context_set_default')) {
        @stream_context_set_default([
            'http'  => ['timeout' => $max, 'follow_location' => 0, 'max_redirects' => 0,
                        'ignore_errors' => true],
            'https' => ['timeout' => $max, 'follow_location' => 0, 'max_redirects' => 0,
                        'ignore_errors' => true],
        ]);
    }
    if (function_exists('register_shutdown_function')) {
        register_shutdown_function(function () use ($cur) {
            @ini_set('default_socket_timeout', (string) $cur);
        });
    }
}
// v7.10.548 — CALL IT DIRECTLY, DO NOT TRUST THE HOOK. defines.php is include_once'd from
// wp-compress-core.php:115; if that include happens at or after 'plugins_loaded', a callback
// registered on that action never fires and this whole layer is inert. Field-flagged as absent
// on the flagship despite being present in the shipped bytes. ini_set/stream defaults need no
// WordPress state, so there is nothing to wait for. The hook stays only as a late belt for the
// case where this file is somehow loaded before ini_set exists.
wpc_bound_socket_timeout();
if (function_exists('add_action')) {
    add_action('plugins_loaded', 'wpc_bound_socket_timeout', 0);
}

if (!function_exists('wpc_atf_glyphs_read')) {
    /**
     * THE reader for delay.json's ATF glyph map (v7.10.566).
     *
     * Lives here because defines.php is include_once'd unconditionally from wp-compress-core.php:115,
     * so every consumer can reach it with no load-order coupling. .562 routed the fonts splitter
     * through wps_rewriteLogic::wpc_read_atf_glyphs() to kill a duplicate implementation — right
     * instinct, wrong home: rewriteLogic.php is included ONLY from addons/cdn/cdn-rewrite.php:43,
     * so on any render that does not load the CDN addon the class was absent, the splitter failed
     * open, and every Roboto face went back inline. Field receipt on the flagship: a forced render
     * split correctly (0 b inline) while the copy that populated the page cache 38 min earlier did
     * not (6,232 b inline) — same version, same URL, same UA.
     *
     * Handles both artifact shapes: top-level `atf_glyphs`, and the live per-device nesting
     * (delay.json -> desktop|mobile -> atf_glyphs). Returns [] when nothing is readable, which
     * every caller treats as "unknown" and fails open on. With $device ('desktop' or 'mobile')
     * the answer is that device's map, or a top-level one; another device's map is not an
     * answer for it.
     */
    function wpc_atf_glyphs_read($critDir, $device = null)
    {
        $dir = rtrim((string) $critDir, '/') . '/';
        foreach (['delay.json', 'lcp.json'] as $wpc_fn) {
            if (!@is_readable($dir . $wpc_fn)) {
                continue;
            }
            $j = json_decode((string) @file_get_contents($dir . $wpc_fn), true);
            if (!is_array($j)) {
                continue;
            }
            if ($device !== null) {
                $wpc_device = (string) $device;
                if (isset($j[$wpc_device]['atf_glyphs']) && is_array($j[$wpc_device]['atf_glyphs']) && !empty($j[$wpc_device]['atf_glyphs'])) {
                    return $j[$wpc_device]['atf_glyphs'];
                }
                if (isset($j['atf_glyphs']) && is_array($j['atf_glyphs']) && !empty($j['atf_glyphs'])) {
                    return $j['atf_glyphs'];
                }
                continue;
            }
            if (isset($j['atf_glyphs']) && is_array($j['atf_glyphs']) && !empty($j['atf_glyphs'])) {
                return $j['atf_glyphs'];
            }
            foreach ($j as $wpc_v) {
                if (is_array($wpc_v) && isset($wpc_v['atf_glyphs']) && is_array($wpc_v['atf_glyphs'])
                    && !empty($wpc_v['atf_glyphs'])) {
                    return $wpc_v['atf_glyphs'];
                }
            }
        }
        return [];
    }
}


if (!function_exists('wpc_default_cdn_excludes')) {
    /**
     * THE hardcoded CDN default-exclude list (v7.22.72) — one array, two consumers.
     *
     * wps_cdn_rewrite::$default_excluded_list and wps_rewriteLogic::$defaultExcludedList were two
     * hand-maintained copies of the same list, and they had drifted: 'wp-admin' was added to the
     * former in 2ca023c3 (2025-07-10) and never to the latter, which has been byte-identical since
     * the initial commit. wpc_zone_delayed_js_url() lives in wps_rewriteLogic, so every guard in its
     * ladder read the copy WITHOUT 'wp-admin' — and the .804 delayed lane, live from .811, zoned
     * /wp-admin/js/editor.min.js onto the edge, which answers 403 there (namaum.com). The .48 path
     * guard fixed the symptom in that one function; this fixes the divergence that produced it.
     *
     * Lives here for the same reason wpc_atf_glyphs_read() does: defines.php is include_once'd
     * unconditionally from wp-compress-core.php, so neither consumer depends on load order. That
     * matters concretely — wps_rewriteLogic is constructed at cdn-rewrite.php:7713 and populates
     * this list from its own constructor, 35 lines BEFORE the owner assigns its copy at 7748, so a
     * cross-class static read would see null on every render.
     *
     * NOT the operator list. wpc_cdn_excludes filters self::$excludes['cdn'] at cdn-rewrite.php:467
     * and is applied ONCE by its owner (t806) — this array is never filtered, so sharing it cannot
     * re-open that double-application. Per-class additions stay per-class: 'elementor/css/' is
     * appended to wps_cdn_rewrite's copy only, under wpc_elementor_css_same_origin.
     */
    function wpc_default_cdn_excludes()
    {
        return ['wp-admin', 'redditstatic', 'ai-uncode', 'gtm', 'instagram.com', 'fbcdn.net', 'twitter', 'google', 'coinbase', 'cookie', 'schema', 'recaptcha', 'data:image', 'stats.jpg'];
    }
}


// ─── PURGE LEDGER ────────────────────────────────────────────────────────────────
// Every purge is a self-inflicted cache MISS, measured at 1.587s TTFB against 0.096s
// on a HIT. Before this the only local record was wpc_purge_debug_log — 20 entries,
// written almost entirely on FAILURE paths — so a succeeding-but-far-too-frequent
// pattern left no trace and had to be read off Cloudflare's own audit log, which can
// never say which of OUR lines asked for it.
//
// Records EVERY purge surface (CF, orchestrator, object cache, opcache, local disk),
// with the file:line that triggered it and the WordPress hook stack that explains WHY.
// JSONL on disk so 24h of history survives without bloating the options table; falls
// back to an option ring buffer when the log dir is not writable.
if (!function_exists('wpc_purge_ledger_file')) {
    function wpc_purge_ledger_file()
    {
        $wpc_dir = defined('WPS_IC_LOG') ? WPS_IC_LOG : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/logs/' : '');
        if ($wpc_dir === '') {
            return '';
        }
        if (!is_dir($wpc_dir)) {
            @mkdir($wpc_dir, 0755, true);
        }
        return is_dir($wpc_dir) && is_writable($wpc_dir) ? rtrim($wpc_dir, '/') . '/purge-ledger.jsonl' : '';
    }
}

// The causal half. "A purge happened" is not actionable; "save_post on ID 412 called
// cache.class.php:839" is. Kept to a bounded, allocation-cheap shape — this runs
// immediately before a network call, so its cost is noise, but it must never throw.
if (!function_exists('wpc_purge_ledger_why')) {
    function wpc_purge_ledger_why()
    {
        $wpc_w = ['at' => '', 'by' => '', 'stack' => '', 'hook' => '', 'src' => 'web', 'ref' => ''];
        try {
            if (function_exists('wp_doing_cron') && wp_doing_cron()) {
                $wpc_w['src'] = 'cron';
            } elseif (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
                $wpc_w['src'] = 'ajax';
                $wpc_w['ref'] = isset($_REQUEST['action']) ? substr((string) $_REQUEST['action'], 0, 40) : '';
            } elseif (defined('WP_CLI') && WP_CLI) {
                $wpc_w['src'] = 'cli';
            } elseif (defined('REST_REQUEST') && REST_REQUEST) {
                $wpc_w['src'] = 'rest';
            }
            // The WP hook stack IS the reason: save_post, upgrader_process_complete,
            // a cron action, or an admin-post handler. Last three are enough to name it.
            if (!empty($GLOBALS['wp_current_filter']) && is_array($GLOBALS['wp_current_filter'])) {
                $wpc_hooks = array_slice($GLOBALS['wp_current_filter'], -3);
                $wpc_w['hook'] = substr(implode('>', $wpc_hooks), 0, 90);
            }
            if ($wpc_w['ref'] === '' && !empty($_SERVER['REQUEST_URI'])) {
                $wpc_w['ref'] = substr((string) $_SERVER['REQUEST_URI'], 0, 60);
            }
            if (!function_exists('debug_backtrace')) {
                return $wpc_w;
            }
            $wpc_bt    = @debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
            $wpc_names = [];
            foreach ((array) $wpc_bt as $wpc_f) {
                $wpc_file = isset($wpc_f['file']) ? (string) $wpc_f['file'] : '';
                $wpc_fn   = isset($wpc_f['function']) ? (string) $wpc_f['function'] : '';
                // Skip the recorder's own frames and the transport: neither is the reason.
                // debug_backtrace() puts wpc_purge_ledger_* at frame 0 and their names
                // contain "purge", so without this the ledger blames itself every time.
                // Name-based, not file-based: the recorder frames must be skipped even when
                // the file check cannot see them (eval'd code, opcache paths, a future move
                // out of defines.php). Missing this makes the ledger blame ITSELF.
                if (stripos($wpc_fn, 'ledger') !== false
                    || $wpc_fn === 'wpc_purge_record'
                    || $wpc_fn === 'wpc_purge_ledger_add') {
                    continue;
                }
                if (!empty($wpc_f['class']) && stripos($wpc_f['class'], 'CloudflareAPI') !== false) {
                    continue;
                }
                if ($wpc_file !== '' && basename($wpc_file) === 'defines.php') {
                    continue;
                }
                $wpc_names[] = (!empty($wpc_f['class']) ? $wpc_f['class'] . '::' : '') . $wpc_fn;
                if ($wpc_w['at'] === '' && $wpc_file !== '') {
                    // frame['file']:frame['line'] is where THIS function was called from —
                    // i.e. the exact line in our code that asked for the purge.
                    $wpc_w['at'] = basename($wpc_file) . ':' . (isset($wpc_f['line']) ? (int) $wpc_f['line'] : 0);
                    $wpc_w['by'] = end($wpc_names);
                }
            }
            $wpc_w['stack'] = substr(implode('<', array_slice($wpc_names, 0, 5)), 0, 140);
        } catch (\Throwable $e) {
        }
        return $wpc_w;
    }
}

// ─── SETTINGS LEDGER (v7.10.594) ────────────────────────────────────────────────
// Two toggles verified ACTIVE at ~15:40 were OFF by ~16:20 with nobody touching them, and
// after clearing two candidates on receipts (wpc_apply_link_preset is set-if-unset and cannot
// turn '1' into '0'; connectWithKey's preset was fenced to fresh connects by .412) there was
// no third theory worth having. Two disproven theories is the signal to stop theorising and
// print actual state, so this names the writer instead of guessing at it.
//
// THE FIELD THAT ANSWERS THE QUESTION IS `gone`. A targeted write changes keys; only a
// wholesale replace makes keys DISAPPEAR, because the replacement source never contained
// them. So gone>0 identifies the verb — no need to recognise which function did it.
//
// Doubles as the provenance source auto-mode needs: "tweak, don't override" is only
// enforceable if we can say who last wrote each key.
if (!function_exists('wpc_settings_ledger_file')) {
    function wpc_settings_ledger_file()
    {
        $wpc_dir = defined('WPS_IC_LOG') ? WPS_IC_LOG : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/logs/' : '');
        if ($wpc_dir === '') {
            return '';
        }
        if (!is_dir($wpc_dir)) {
            @mkdir($wpc_dir, 0755, true);
        }
        return is_dir($wpc_dir) && is_writable($wpc_dir) ? rtrim($wpc_dir, '/') . '/settings-ledger.jsonl' : '';
    }
}

if (!function_exists('wpc_settings_diff')) {
    /** Changed / added / gone key names between two settings arrays. Scalars compared as strings. */
    function wpc_settings_diff($old, $new)
    {
        $wpc_d = ['changed' => [], 'added' => [], 'gone' => []];
        if (!is_array($old) || !is_array($new)) {
            return $wpc_d;
        }
        $wpc_flat = function ($wpc_v) {
            if (is_scalar($wpc_v) || $wpc_v === null) {
                return (string) $wpc_v;
            }
            return @json_encode($wpc_v);
        };
        foreach ($new as $wpc_k => $wpc_v) {
            if (!array_key_exists($wpc_k, $old)) {
                $wpc_d['added'][] = (string) $wpc_k;
            } elseif ($wpc_flat($old[$wpc_k]) !== $wpc_flat($wpc_v)) {
                $wpc_d['changed'][] = (string) $wpc_k;
            }
        }
        foreach ($old as $wpc_k => $wpc_v) {
            if (!array_key_exists($wpc_k, $new)) {
                $wpc_d['gone'][] = (string) $wpc_k;
            }
        }
        return $wpc_d;
    }
}

if (!function_exists('wpc_settings_ledger_record')) {
    function wpc_settings_ledger_record($new, $old, $option = '')
    {
        try {
            if (!apply_filters('wpc_settings_ledger', true)) {
                return $new;
            }
            $wpc_d = wpc_settings_diff($old, $new);
            // A write that changes nothing is noise, and update_option() does many of them.
            if (!$wpc_d['changed'] && !$wpc_d['added'] && !$wpc_d['gone']) {
                return $new;
            }
            $wpc_why = function_exists('wpc_purge_ledger_why')
                ? wpc_purge_ledger_why()
                : ['at' => '', 'by' => '', 'stack' => '', 'hook' => '', 'src' => '', 'ref' => ''];
            $wpc_row = [
                't' => time(),
                'opt' => (string) $option,
                'at' => $wpc_why['at'],
                'by' => $wpc_why['by'],
                'hook' => $wpc_why['hook'],
                'src' => $wpc_why['src'],
                'ref' => $wpc_why['ref'],
                'stack' => $wpc_why['stack'],
                'n_old' => is_array($old) ? count($old) : -1,
                'n_new' => is_array($new) ? count($new) : -1,
                // gone>0 = a wholesale replace. This is the smoking gun, so it is never trimmed.
                'gone' => array_slice($wpc_d['gone'], 0, 40),
                'changed' => array_slice($wpc_d['changed'], 0, 25),
                'added' => array_slice($wpc_d['added'], 0, 25),
                'verb' => $wpc_d['gone'] ? 'REPLACE' : 'set',
            ];
            // Old->new values for the levers that keep reverting, so the row is self-explaining.
            $wpc_watch = (array) apply_filters('wpc_settings_ledger_watch',
                ['emoji-remove', 'force-delay-jquery', 'delay_js', 'critical', 'cache', 'preset', 'mode']);
            foreach ($wpc_watch as $wpc_wk) {
                if (in_array($wpc_wk, $wpc_d['changed'], true) || in_array($wpc_wk, $wpc_d['gone'], true)) {
                    $wpc_ov = (is_array($old) && array_key_exists($wpc_wk, $old)) ? $old[$wpc_wk] : null;
                    $wpc_nv = (is_array($new) && array_key_exists($wpc_wk, $new)) ? $new[$wpc_wk] : null;
                    $wpc_row['w'][$wpc_wk] = substr((string) (is_scalar($wpc_ov) ? $wpc_ov : @json_encode($wpc_ov)), 0, 24)
                        . '=>' . substr((string) (is_scalar($wpc_nv) ? $wpc_nv : @json_encode($wpc_nv)), 0, 24);
                }
            }
            $wpc_f = wpc_settings_ledger_file();
            if ($wpc_f !== '') {
                // Bounded: truncate rather than grow without limit. Settings writes are admin
                // actions, not per-render, so this is never hot.
                if (@filesize($wpc_f) > (int) apply_filters('wpc_settings_ledger_max', 262144)) {
                    wpc_fs_put($wpc_f, '');
                }
                wpc_fs_put($wpc_f, @json_encode($wpc_row) . "\n", FILE_APPEND | LOCK_EX);
            }
            if ($wpc_d['gone'] && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('settings-wholesale-replace', (string) $wpc_row['at'], '', [
                    'gone' => count($wpc_d['gone']),
                    'by' => $wpc_row['by'],
                    'hook' => $wpc_row['hook'],
                    'src' => $wpc_row['src'],
                ]);
            }
        } catch (\Throwable $e) {
        }
        return $new;
    }
}

// v7.10.596 — THREE HOOKS, BECAUSE ONE VERB IS NOT ENOUGH.
// pre_update_option_* is the only place that sees BOTH values, and it must return $new
// untouched — this records, it never adjudicates. But it fires ONLY on update: WordPress
// routes a first-time write through add_option and a removal through delete_option, so a
// wholesale replace implemented as delete-then-add would be invisible to the very ledger
// built to catch a wholesale replace. Same absence-of-a-negative shape as .588/.590/.591.
// add_option has no old value (that is the point — it means the row was gone), and
// delete_option is recorded because it is half of that pattern and explains the add that
// follows it.
if (function_exists('add_filter') && defined('WPS_IC_SETTINGS')) {
    add_filter('pre_update_option_' . WPS_IC_SETTINGS, 'wpc_settings_ledger_record', 10, 3);
    if (function_exists('add_action')) {
        add_action('add_option_' . WPS_IC_SETTINGS, function ($option_name, $option_value) {
            wpc_settings_ledger_record($option_value, [], (string) $option_name . ':add');
        }, 10, 2);
        add_action('delete_option', function ($option_name) {
            if ((string) $option_name !== WPS_IC_SETTINGS) {
                return;
            }
            $current_settings = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
            wpc_settings_ledger_record([], is_array($current_settings) ? $current_settings : [],
                (string) $option_name . ':delete');
        }, 10, 1);
    }
}


// Tag <-> URL map. CF cache tags are truncated md5s, so without this the ledger can only
// ever say "wpc-u-16c852ca19f7af40a420" — true, and useless when the question is WHICH page
// got purged. Bounded and request-scoped; a purge and the tag that names it happen in the
// same request, so nothing needs to persist.
if (!function_exists('wpc_purge_tag_remember')) {
    function wpc_purge_tag_remember($tag, $url)
    {
        if (!isset($GLOBALS['wpc_purge_tagmap'])) {
            $GLOBALS['wpc_purge_tagmap'] = [];
        }
        if (count($GLOBALS['wpc_purge_tagmap']) > 200) {
            $GLOBALS['wpc_purge_tagmap'] = array_slice($GLOBALS['wpc_purge_tagmap'], -100, null, true);
        }
        $GLOBALS['wpc_purge_tagmap'][(string) $tag] = substr((string) $url, 0, 120);
    }
}

if (!function_exists('wpc_purge_tag_resolve')) {
    function wpc_purge_tag_resolve($sample)
    {
        $wpc_s = (string) $sample;
        if ($wpc_s === '' || empty($GLOBALS['wpc_purge_tagmap'])) {
            return $wpc_s;
        }
        $wpc_out = [];
        foreach (explode(' ', $wpc_s) as $wpc_one) {
            $wpc_out[] = isset($GLOBALS['wpc_purge_tagmap'][$wpc_one])
                ? $GLOBALS['wpc_purge_tagmap'][$wpc_one] . ' (' . $wpc_one . ')'
                : $wpc_one;
        }
        return implode(' ', $wpc_out);
    }
}

// The one entry point. Any purge surface calls this — CF, orchestrator, object cache,
// opcache, local disk — so the audit is complete by construction rather than by memory.
if (!function_exists('wpc_purge_record')) {
    function wpc_purge_record($surface, $method, $scope = '', $count = 1, $ok = true, $sample = '')
    {
        try {
            $wpc_w   = wpc_purge_ledger_why();
            $wpc_row = [
                't'   => time(),
                'sf'  => (string) $surface,
                'm'   => (string) $method,
                'sc'  => (string) $scope,
                'n'   => (int) $count,
                'ok'  => $ok ? 1 : 0,
                'src' => $wpc_w['src'],
                'at'  => $wpc_w['at'],
                'by'  => substr($wpc_w['by'], 0, 48),
                'hk'  => $wpc_w['hook'],
                'st'  => $wpc_w['stack'],
                'rf'  => $wpc_w['ref'],
                'u'   => substr(wpc_purge_tag_resolve($sample), 0, 200),
                'pid' => (isset($_REQUEST['post']) && is_scalar($_REQUEST['post'])) ? (int) $_REQUEST['post'] : 0,
            ];
            $wpc_f = wpc_purge_ledger_file();
            if ($wpc_f !== '') {
                // Rotate BEFORE appending so the file can never exceed the cap mid-write.
                if (@filesize($wpc_f) > 2097152) {
                    @rename($wpc_f, $wpc_f . '.1');
                }
                wpc_fs_put($wpc_f, wp_json_encode($wpc_row) . "\n", FILE_APPEND | LOCK_EX);
                return;
            }
            if (!function_exists('get_option')) {
                return;
            }
            $wpc_led = get_option('wpc_purge_ledger', []);
            if (!is_array($wpc_led)) {
                $wpc_led = [];
            }
            $wpc_led[] = $wpc_row;
            update_option('wpc_purge_ledger', array_slice($wpc_led, -300), false);
        } catch (\Throwable $e) {
        }
    }
}

// Kept for the CF SDK's existing call shape.
if (!function_exists('wpc_purge_ledger_add')) {
    function wpc_purge_ledger_add($method, $scope, $count, $ok, $sample = '')
    {
        wpc_purge_record('cf', $method, $scope, $count, $ok, $sample);
    }
}

if (!function_exists('wpc_purge_ledger_rows')) {
    function wpc_purge_ledger_rows($limit = 2000)
    {
        $wpc_f = wpc_purge_ledger_file();
        if ($wpc_f === '' || !@is_readable($wpc_f)) {
            $wpc_o = get_option('wpc_purge_ledger', []);
            return is_array($wpc_o) ? $wpc_o : [];
        }
        $wpc_rows = [];
        $wpc_h    = @fopen($wpc_f, 'r');
        if (!$wpc_h) {
            return [];
        }
        while (($wpc_l = fgets($wpc_h)) !== false) {
            $wpc_d = json_decode(trim($wpc_l), true);
            if (is_array($wpc_d)) {
                $wpc_rows[] = $wpc_d;
            }
        }
        fclose($wpc_h);
        return array_slice($wpc_rows, -$limit);
    }
}

// Rolls the raw rows into the shape the question needs. A list of 2,000 lines does not
// answer "are we over-purging" — rate, duplication and origin do.
if (!function_exists('wpc_purge_ledger_report')) {
    function wpc_purge_ledger_report()
    {
        $wpc_led = wpc_purge_ledger_rows();
        if (empty($wpc_led)) {
            return ['rows' => 0];
        }
        $wpc_now = time();
        $wpc_1h  = 0;
        $wpc_24h = 0;
        $wpc_fail = 0;
        $wpc_meth = $wpc_by = $wpc_src = $wpc_sf = $wpc_hk = $wpc_at = [];
        $wpc_gaps = [];
        $wpc_prev = 0;
        $wpc_secs = [];
        foreach ($wpc_led as $wpc_r) {
            $wpc_t = isset($wpc_r['t']) ? (int) $wpc_r['t'] : 0;
            if ($wpc_now - $wpc_t <= 3600)  { $wpc_1h++; }
            if ($wpc_now - $wpc_t <= 86400) { $wpc_24h++; }
            if (empty($wpc_r['ok'])) { $wpc_fail++; }
            $wpc_bump = function (&$arr, $key) {
                $key = ($key === '' || $key === null) ? '(none)' : $key;
                $arr[$key] = (isset($arr[$key]) ? $arr[$key] : 0) + 1;
            };
            $wpc_bump($wpc_sf,   isset($wpc_r['sf']) ? $wpc_r['sf'] : '?');
            $wpc_bump($wpc_meth, (isset($wpc_r['sf']) ? $wpc_r['sf'] : '?') . ':' . (isset($wpc_r['m']) ? $wpc_r['m'] : '?'));
            $wpc_bump($wpc_by,   isset($wpc_r['by']) ? $wpc_r['by'] : '');
            $wpc_bump($wpc_at,   isset($wpc_r['at']) ? $wpc_r['at'] : '');
            $wpc_bump($wpc_src,  isset($wpc_r['src']) ? $wpc_r['src'] : '?');
            $wpc_bump($wpc_hk,   isset($wpc_r['hk']) ? $wpc_r['hk'] : '');
            $wpc_secs[$wpc_t] = (isset($wpc_secs[$wpc_t]) ? $wpc_secs[$wpc_t] : 0) + 1;
            if ($wpc_prev && $wpc_t > $wpc_prev) {
                $wpc_gaps[] = $wpc_t - $wpc_prev;
            }
            $wpc_prev = $wpc_t;
        }
        $wpc_dupe = 0;
        foreach ($wpc_secs as $wpc_c) {
            if ($wpc_c > 1) { $wpc_dupe += $wpc_c - 1; }
        }
        arsort($wpc_meth); arsort($wpc_by); arsort($wpc_at); arsort($wpc_hk); arsort($wpc_sf);
        sort($wpc_gaps);
        return [
            'rows'         => count($wpc_led),
            'last_1h'      => $wpc_1h,
            'last_24h'     => $wpc_24h,
            'failed'       => $wpc_fail,
            'same_second'  => $wpc_dupe,
            'median_gap_s' => $wpc_gaps ? $wpc_gaps[intdiv(count($wpc_gaps), 2)] : 0,
            'by_surface'   => $wpc_sf,
            'by_method'    => array_slice($wpc_meth, 0, 10, true),
            'by_line'      => array_slice($wpc_at, 0, 10, true),
            'by_caller'    => array_slice($wpc_by, 0, 10, true),
            'by_hook'      => array_slice($wpc_hk, 0, 10, true),
            'by_trigger'   => $wpc_src,
            'oldest'       => isset($wpc_led[0]['t']) ? (int) $wpc_led[0]['t'] : 0,
            'file'         => wpc_purge_ledger_file(),
        ];
    }
}


// ─── THE CDN HOSTNAME HAS FIVE RESOLVERS AND ONE OF THEM WAS INCOMPLETE (v7.10.600) ──
// Receipted from the crit team's node table for wpcompress.com: 21 of 22 request nodes sit on
// cdn.wpcompress.com, and exactly one — the delay-v3 loader, 11.8 KB — sits on the RAW BUNNY
// ZONE host wpcompresscomfa428.zapwp.com. A second origin for a single file means a fresh
// DNS + TCP + TLS handshake nothing else on the page pays: 409 ms against the document's own
// 147 ms, and ~70% of the 584 ms of request-node time standing between mobile and 100.
//
// Cause: js_delay_v3 resolved the host from TWO sources (ic_custom_cname, then the zone),
// while combine_css.class.php:83, combine_js.class.php:55 and enqueues.class.php:103 all check
// a THIRD first — the Cloudflare-provisioned cname. On a CF-connected site ic_custom_cname is
// empty, so the loader fell through to the zone while every other asset used the CF cname.
// Four correct implementations of one predicate and a fifth that knew two thirds of it: the
// same shape as .588, .590, .591 and .596, and the reason this is now ONE function.
//
// Order is CF cname (behind the fail-open verified gate, so a mid-change '0' does not emit a
// half-provisioned host) > ic_custom_cname > raw zone. Returns '' when none is configured,
// which callers must read as "leave the origin URL alone".
if (!function_exists('wpc_cdn_host')) {
    function wpc_cdn_host()
    {
        if (!function_exists('get_option')) {
            return '';
        }
        try {
            $cf_option = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            $cf_cname = (string) get_option(defined('WPS_IC_CF_CNAME') ? WPS_IC_CF_CNAME : 'wps-ic-cf-cname');
            $cname_verified = (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok());
            if (is_array($cf_option) && !empty($cf_option['settings']['cdn'])
                && $cf_cname !== '' && $cname_verified) {
                return trim($cf_cname);
            }
            $custom_cname = trim((string) get_option('ic_custom_cname'));
            if ($custom_cname !== '') {
                return $custom_cname;
            }
            return trim((string) get_option('ic_cdn_zone_name'));
        } catch (\Throwable $e) {
            return '';
        }
    }
}


// ─── low-value URL test for contexts where template_redirect never fires ────────
// wpc_is_low_value_page() reads the global query, so it is only meaningful during a
// render. Off the render path (admin-ajax Rebuild, cron, CLI) the .530 guard was
// skipped entirely and "unknown" read as "fine" — attachment permalinks minted a
// crit dir each. Resolve from the URL instead. Fails OPEN: unknown is never dropped.
if (!function_exists('wpc_url_is_low_value')) {
    /**
     * $lookupPost false skips the attachment lookup (url_to_postid, a query), for a caller that
     * runs on every request and answers the attachment question from the resolved query itself
     * (wpc_request_is_not_page).
     */
    function wpc_url_is_low_value($wpc_url, $lookupPost = true)
    {
        $wpc_url = (string) $wpc_url;
        if ($wpc_url === '') {
            return false;
        }
        static $wpc_seen = [];
        $wpc_memo = ($lookupPost ? '1|' : '0|') . $wpc_url;
        if (isset($wpc_seen[$wpc_memo])) {
            return $wpc_seen[$wpc_memo];
        }
        $wpc_low = false;
        $wpc_path = (string) parse_url($wpc_url, PHP_URL_PATH);
        $wpc_qs   = (string) parse_url($wpc_url, PHP_URL_QUERY);
        if ($wpc_path !== '' && preg_match('#/(?:feed|embed|trackback)/?$#i', $wpc_path)) {
            $wpc_low = true;
        }
        // v7.10.590 — ENDPOINTS ARE NOT PAGES. Every test below this point asks a question
        // about a post ("is it an attachment?"), so a URL that resolves to no post at all fell
        // through as "not low value" and minted a crit dir plus a generation dispatch. Receipted
        // on staging: cache/critical/staging-wpcompress-comwp-adminadmin-ajax-php. None of these
        // can ever render a front-end document, so there is nothing for critical CSS to describe.
        // v7.10.607 — A STATIC FILE IS NOT A PAGE. Dispatching one costs the service 5-6 full
        // Chromium renders and can never produce critical CSS: receipted service-side as
        // Complaint_Form.pdf burning 10.4s of Chromium before failing. .590 gated endpoints and
        // .603 gated 404s and GraphQL, but neither named file extensions, so any uploads/media
        // URL that reached the pipeline was dispatched. Service now answers HTTP 400
        // unrenderable_url for these; refusing them here means we never spend the round trip.
        if (!$wpc_low && $wpc_path !== ''
            && preg_match('/\.(?:pdf|zip|rar|7z|tar|gz|tgz'
                . '|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?'
                . '|mp4|webm|mov|avi|mkv|mp3|wav|ogg|m4a'
                . '|css|js|mjs|map|woff2?|ttf|otf|eot'
                . '|docx?|xlsx?|pptx?|csv|rtf|psd|ai|eps)$/i', $wpc_path)) {
            $wpc_low = true;
        }
        // v7.10.603 — /graphql, /api/graphql and /v1/graphql are REGISTERED routes, so is_404()
        // never fires for them and the .590 endpoint list did not name them: receipted on
        // hawkeye running the full OBCHAIN plus atf-scope-open and lcp-preload-no-stem-match
        // against the home page's stem. A GraphQL response is not a document either.
        if (!$wpc_low && $wpc_path !== '' && preg_match('#(?:^|/)graphql/?$#i', $wpc_path)) {
            $wpc_low = true;
        }
        if (!$wpc_low && $wpc_path !== ''
            && preg_match('#(?:^|/)wp-(?:admin|json|content|includes)/'
                . '|(?:^|/)(?:wp-login|wp-cron|wp-signup|wp-activate|wp-trackback|wp-comments-post|xmlrpc)\.php$'
                . '|\.(?:xml|txt|json)$#i', $wpc_path)) {
            $wpc_low = true;
        }
        if (!$wpc_low && $wpc_qs !== '') {
            parse_str($wpc_qs, $wpc_q);
            // rest_route is the REST API on a site without pretty permalinks (or any site that is
            // asked that way): the same JSON endpoint as /wp-json/.
            if (isset($wpc_q['s']) || isset($wpc_q['attachment_id']) || !empty($wpc_q['feed']) || isset($wpc_q['rest_route'])) {
                $wpc_low = true;
            }
        }
        if (!$wpc_low && $lookupPost && function_exists('url_to_postid') && function_exists('get_post_type')) {
            $wpc_pid = (int) url_to_postid($wpc_url);
            if ($wpc_pid > 0 && get_post_type($wpc_pid) === 'attachment') {
                $wpc_low = true;
            }
        }
        $wpc_low = (bool) (function_exists('apply_filters') ? apply_filters('wpc_url_low_value', $wpc_low, $wpc_url) : $wpc_low);
        if (count($wpc_seen) > 500) {
            $wpc_seen = [];
        }
        return $wpc_seen[$wpc_memo] = $wpc_low;
    }
}

if (!function_exists('wpc_request_is_not_page')) {
    /**
     * Whether THIS request answered something other than a front-end page, for the crit lanes
     * that run at shutdown on every request (the pending pull, the pointer tick). Asked from the
     * two existing owners: the URL test (wpc_url_is_low_value: REST, admin, cron, login, xmlrpc,
     * feeds, .xml/.txt/.json such as sitemaps and robots.txt, static files) without its post
     * lookup, and, once the query is resolved, the page test (wpc_is_low_value_page: attachment,
     * search, feed, trackback, robots, favicon, 404). A REST request is also known by
     * REST_REQUEST, and any other endpoint by a response that is not text/html. Those lanes
     * read the crit folder of the request's own url key, and on webdesign4u.com.au (2026-09-28)
     * they did it for CleanTalk's /wp-json/cleantalk-antispam/v1/alt_sessions, which the
     * plugin's error log recorded 50 times as a missing dispatch_ts.txt.
     */
    function wpc_request_is_not_page()
    {
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return true;
        }
        if (function_exists('headers_list')) {
            foreach ((array) headers_list() as $header) {
                if (stripos((string) $header, 'content-type:') === 0) {
                    return stripos((string) $header, 'text/html') === false;
                }
            }
        }
        $requestUrl = class_exists('wps_ic_url_key') ? (string) wps_ic_url_key::requestUrl()
            : (isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '');
        if ($requestUrl !== '' && wpc_url_is_low_value('https://' . preg_replace('#^https?://#i', '', $requestUrl), false)) {
            return true;
        }
        return function_exists('did_action') && did_action('template_redirect') && wpc_is_low_value_page();
    }
}


// ─── font-cache TTL: ONE writer, because two disagreed ──────────────────────────
// Three call sites wrote this file; the narrowest (woff2|ttf only) landed first and
// the widest was !file_exists-guarded, so it became dead code and every localized
// site served its font CSS with no Cache-Control at all.
if (!function_exists('wpc_fonts_htaccess_body')) {
    function wpc_fonts_htaccess_body()
    {
        return "<IfModule mod_headers.c>\n<FilesMatch \"\\.(css|woff2?|ttf)$\">\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n</IfModule>\n";
    }

    function wpc_fonts_htaccess_ensure($wpc_dir)
    {
        $wpc_dir = rtrim((string) $wpc_dir, '/');
        if ($wpc_dir === '' || !@is_dir($wpc_dir) || !@is_writable($wpc_dir)) {
            return false;
        }
        $wpc_body = wpc_fonts_htaccess_body();
        // Body in the transient key: editing the rule re-writes every site's copy.
        // Keyed on the path alone, a wrong rule froze fleet-wide for good.
        $wpc_key = 'wpc_fonts_ht_' . md5($wpc_dir . '|' . $wpc_body);
        if (function_exists('get_transient') && get_transient($wpc_key)) {
            return false;
        }
        if (function_exists('set_transient')) {
            set_transient($wpc_key, 1, defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
        }
        $wpc_file = $wpc_dir . '/.htaccess';
        if (@file_get_contents($wpc_file) === $wpc_body) {
            return false;
        }
        return (bool) wpc_fs_put($wpc_file, $wpc_body);
    }

    /**
     * Both directories the pages serve localized woff2 from get the rule once per plugin
     * version (checkPluginVersion), and the localizer writes it when it adds a family. A site
     * localized before the rule existed never re-runs the localizer, so without the version
     * pass its font CSS shipped with no Cache-Control (v7.10.583); the render path does not
     * re-check it.
     */
    function wpc_fonts_htaccess_ensure_all()
    {
        $dirs = [];
        if (defined('WPS_IC_FONTS_DIR')) {
            $dirs[] = WPS_IC_FONTS_DIR;
        }
        if (function_exists('wp_get_upload_dir')) {
            $uploads = wp_get_upload_dir();
            if (!empty($uploads['basedir'])) {
                $dirs[] = rtrim((string) $uploads['basedir'], '/') . '/elementor/google-fonts';
            }
        }
        foreach ($dirs as $dir) {
            wpc_fonts_htaccess_ensure($dir);
        }
    }
}

/**
 * v7.10.602 — FONT DECLARATION CARRIER.
 *
 * The inline crit is the only thing declaring the theme's real @font-face on Elementor-class
 * sites, and it is not emitted for logged-in renders (cdn-rewrite:4195 disable-logged-in-opt,
 * cacheHtml:14 logged-in). The sheet that used to carry those faces is still deferred or
 * replaced, so the family ends up with ZERO live faces and matching falls to Helvetica —
 * measured on staging.wpcompress.com/pricing/ while logged in. wpc_emit_critless_font_subsets() was
 * built for exactly this but is called from inside addCriticalCSS, the method those renders
 * never reach: the belt sits on the skipped path.
 *
 * The healthy logged-out render RECORDS its declarations; any render the crit cannot reach
 * REPLAYS them. A live @font-face costs document bytes and zero requests — the file is fetched
 * only when a glyph actually matches — so replaying the union across pages is safe.
 */
if (!function_exists('wpc_font_carrier_file')) {
    function wpc_font_carrier_file()
    {
        // v7.10.618 — the carrier lives OUTSIDE the purge blast radius. The cache-dir copy
        // died in the same purge that wiped the crit artifacts (2026-07-30), taking the
        // brand fonts down with the lane it existed to back up. uploads/wpc-assets provably
        // survives purges (the versioned loader files did). One-time migration copies the
        // old cache-dir store forward if it still exists.
        try {
            if (function_exists('wp_upload_dir')) {
                $upload_dir = wp_upload_dir(null, false);
                if (empty($upload_dir['error']) && !empty($upload_dir['basedir'])) {
                    $assets_dir = rtrim($upload_dir['basedir'], '/') . '/wpc-assets/';
                    $carrier_path = $assets_dir . 'font-carrier.css';
                    if (!@is_file($carrier_path) && defined('WPS_IC_CACHE')
                        && @is_file(WPS_IC_CACHE . 'font-carrier.css')) {
                        if (!is_dir($assets_dir)) {
                            @mkdir($assets_dir, 0755, true);
                        }
                        @copy(WPS_IC_CACHE . 'font-carrier.css', $carrier_path);
                    }
                    if (is_dir($assets_dir)) {
                        return $carrier_path;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return defined('WPS_IC_CACHE') ? WPS_IC_CACHE . 'font-carrier.css' : '';
    }
}

if (!function_exists('wpc_font_carrier_key')) {
    function wpc_font_carrier_key($block)
    {
        $fam = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $block, $m)
            ? strtolower(trim($m[1], " \t\"'")) : '';
        if ($fam === '') { return ''; }
        $w = preg_match('/font-weight\s*:\s*([^;}]+)/i', $block, $m2) ? strtolower(trim($m2[1])) : '400';
        $s = preg_match('/font-style\s*:\s*(italic|oblique)/i', $block) ? 'i' : 'n';
        $r = preg_match('/unicode-range\s*:\s*([^;}]+)/i', $block, $m3) ? strtolower(trim($m3[1])) : '';
        return $fam . '|' . $w . '|' . $s . '|' . substr(md5($r), 0, 8);
    }
}

// v7.22.22 — THE CARRIER MAY NOT TURN A CONSENT-GATED FACE INTO A SITE CONSTANT. It records
// every @font-face a render emits and replays them on pages that lack them. On columbus the
// homepage declares no Poppins face for consent-less visitors (CookieYes gates the Google
// Fonts link); one consented render taught the carrier a fonts.gstatic.com face and every
// visitor got it — a different typeface than the site serves and a third-party fetch the
// site withheld. Faces served from the site itself (origin, zone, custom CNAME) carry; a
// url() on any other host is dropped at record AND at emit (existing carrier files).
if (!function_exists('wpc_font_carrier_host_allowed')) {
    function wpc_font_carrier_host_allowed($url)
    {
        $h = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($h === '') { return true; }
        static $ok = null;
        if ($ok === null) {
            $ok = [];
            if (function_exists('home_url')) {
                $ok[strtolower((string) parse_url(home_url('/'), PHP_URL_HOST))] = 1;
            }
            if (function_exists('get_option')) {
                foreach (['ic_custom_cname', 'ic_cdn_zone_name'] as $k) {
                    $v = strtolower(trim((string) get_option($k)));
                    if ($v !== '') { $ok[$v] = 1; }
                }
            }
            unset($ok['']);
        }
        if (isset($ok[$h]) || substr($h, -10) === '.zapwp.com') { return true; }
        if (isset($ok['www.' . $h]) || (strpos($h, 'www.') === 0 && isset($ok[substr($h, 4)]))) { return true; }
        return (bool) apply_filters('wpc_font_carrier_thirdparty', false, $h);
    }
}
if (!function_exists('wpc_font_carrier_drop_foreign_faces')) {
    function wpc_font_carrier_drop_foreign_faces($css)
    {
        if (!is_string($css) || $css === '' || stripos($css, 'url(') === false) { return $css; }
        $dropped = 0;
        $out = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use (&$dropped) {
            if (preg_match_all('/url\(\s*["\']?(https?:\/\/[^)"\'\s]+)/i', $m[0], $u)) {
                foreach ($u[1] as $one) {
                    if (!wpc_font_carrier_host_allowed($one)) {
                        $dropped++;
                        if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $f)) {
                            $GLOBALS['wpc_dropped_third_party_font_families'][strtolower(trim($f[1]))] = 1;
                        }
                        return '';
                    }
                }
            }
            return $m[0];
        }, $css);
        if (!is_string($out)) { return $css; }
        // A face on a third-party host is refused: the carrier records whatever the crit output
        // carries, and a foreign host fails CORS or disappears. Sampled: the same stored faces
        // are refused on every render of the page.
        if ($dropped > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('font-carrier-thirdparty-dropped', ['n' => $dropped], true);
        }
        return $out;
    }
}

if (!function_exists('wpc_font_carrier_record')) {
    function wpc_font_carrier_record($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, '@font-face') === false) { return false; }
            if (function_exists('wpc_font_carrier_drop_foreign_faces')) {
                $css = wpc_font_carrier_drop_foreign_faces($css);
                if (!is_string($css) || stripos($css, '@font-face') === false) { return false; }
            }
            $file = wpc_font_carrier_file();
            if ($file === '') { return false; }
            if (!apply_filters('wpc_font_carrier_record', true)) { return false; }
            if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $m)) { return false; }
            $have = @is_readable($file) ? (string) @file_get_contents($file) : '';
            $seen = [];
            if ($have !== '' && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $have, $hm)) {
                foreach ($hm[0] as $hb) {
                    $k = wpc_font_carrier_key($hb);
                    if ($k !== '') { $seen[$k] = $hb; }
                }
            }
            $added = 0;
            $cap = (int) apply_filters('wpc_font_carrier_cap', 98304);
            foreach ($m[0] as $blk) {
                $k = wpc_font_carrier_key($blk);
                if ($k === '' || isset($seen[$k])) { continue; }
                $seen[$k] = $blk;
                $added++;
            }
            if ($added === 0) { return false; }
            $out = '';
            foreach ($seen as $blk) {
                if (strlen($out) + strlen($blk) > $cap) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('font-carrier-capped', '', '', ['cap' => $cap]);
                    }
                    break;
                }
                $out .= $blk;
            }
            if ($out === '') { return false; }
            if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_heal_css_host_twin')) {
                $healed = (string) wps_cdn_rewrite::wpc_heal_css_host_twin($out);
                // The store lives outside every purge, so a cloned site's old domain is rewritten
                // before it is kept. Sampled: a migrated site's crit carries it on every render.
                if ($healed !== $out && function_exists('wpc_render_belt_note')) {
                    wpc_render_belt_note('font-carrier-host-healed', $healed === '' ? ['record' => 1, 'emptied' => 1] : ['record' => 1], true);
                }
                $out = $healed;
                if ($out === '') { return false; }
            }
            $tmp = $file . '.tmp' . getmypid();
            if (wpc_fs_put($tmp, $out, LOCK_EX) === false) { return false; }
            if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('font-carrier-recorded', '', '', ['added' => $added, 'bytes' => strlen($out)]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_font_carrier_is_needed')) {
    function wpc_font_carrier_is_needed()
    {
        if (is_admin()
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('is_feed') && is_feed())) {
            return false;
        }
        // v7.10.619 — the carrier emits on EVERY front-end render. Fonts are a site
        // constant: any state-conditional gate (logged-in .602, critless .617) leaves
        // some cached HTML variant without a declarer, and tonight proved every such
        // window ends in Helvetica. ~2KB of url() faces, deduped, purge-proof store,
        // seeded from Elementor's own kit css. Subsets still win the cascade when
        // crit is present (they sit later in <head>).
        return (bool) apply_filters('wpc_font_carrier_force', true);
    }
}

if (!function_exists('wpc_font_preload_postpaint_tag')) {
    /**
     * v7.10.689 — a static <link rel="preload" as="font"> discovered before first paint
     * render-holds Chrome 150 (the PSI capture build AND headed): measured on wpcompress,
     * observed FCP 2,250ms with the three carrier preloads vs 234ms without — CORS,
     * URL-to-face match and display:swap all correct, the TAG ITSELF is the gate. Same
     * links, injected after the first frame commits (rAF -> setTimeout(0), the post-paint
     * hook: a link created inside the rAF callback is still pre-paint and re-arms the
     * hold). The swap window still closes ~300ms after paint and the metric-matched
     * fallbacks (.620's shift belt) cover it. Fail-open: no JS => CSS discovery fetches
     * the face exactly as before .620. Entries: url string, or [url, mime]. JSON keeps
     * slashes unescaped so downstream stripos($output, esc_url($u)) dedupe still matches.
     */
    function wpc_font_preload_postpaint_tag($entries, $marker = '')
    {
        if (!apply_filters('wpc_font_preload_postpaint', true)) {
            return '';
        }
        $list = [];
        foreach ((array) $entries as $e) {
            $u = is_array($e) ? (string) ($e[0] ?? '') : (string) $e;
            $t = is_array($e) && !empty($e[1]) ? (string) $e[1] : 'font/woff2';
            // v7.22.32 — an entry may carry its FAMILY as [url, mime, family]; the injector then
            // preloads it only when some painted element actually resolves to that family.
            // A face nobody uses is a wasted request at best and a 404 at worst
            // (lawyerscolumbusohio: theme declares SourceSerif4Variable-Roman.ttf.woff2, file
            // absent, page never uses the family — we alone fetched it).
            $f = is_array($e) && !empty($e[2]) ? strtolower(trim((string) $e[2], " \t\"'")) : '';
            if ($u === '' || stripos($u, 'http') !== 0) { continue; }
            $list[$u] = $f !== '' ? [$u, $t, $f] : [$u, $t];
        }
        if (!$list) { return ''; }
        $json = wp_json_encode(array_values(array_slice($list, 0, 6)), JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') { return ''; }
        $json = str_replace('</', '<\/', $json);
        // $marker keeps existing strpos re-entry guards working (they grep the buffer
        // for the old static id); it rides as an inert data attribute.
        // v7.10.690 — data-nodefer: BOTH delay engines honour it (v2:554, v3:526/555). Without
        // it the delay pass placeholdered this injector into the registry on the live flagship
        // (fires on interaction = never before LCP) — the preloads silently stopped existing.
        $mk = $marker !== '' ? ' data-wpc-fpm="' . esc_attr($marker) . '"' : '';
        // v7.10.716 — the lane is decided SERVER-SIDE at emission, never sniffed at runtime.
        // .714 read window.wpcDelayV3Cfg inside the frame-1 rAF callback, but the registry is a
        // FOOTER script (~byte 567K of a 595K doc): at frame 1 the parser has not reached it on
        // essentially every render, so the read returned undefined and the fast path fired the
        // warm at wall-clock ~150-190ms — inside the paint mark on slow-paint runs (the pin
        // 97s). The emitter already knows whether this install runs the delay engine; a page
        // that does gets an UNCONDITIONAL load+delay schedule with no detection to lose.
        $delay_engine_on = false;
        try {
            if (class_exists('wps_ic_js_delay_v3') && function_exists('get_option') && defined('WPS_IC_SETTINGS')
                && is_callable(['wps_ic_js_delay_v3', 'wpc_delay_master_on'])) {
                $delay_engine_on = (bool) wps_ic_js_delay_v3::wpc_delay_master_on(get_option(WPS_IC_SETTINGS));
            }
        } catch (\Throwable $e) {
            $delay_engine_on = false;
        }
        $warm_script = 'var g=function(){window.__wpcFPB=window.__wpcFPB||{};'
            . 'var used=null,uf=function(f){if(used===null){used={};try{var els=document.querySelectorAll("body *"),n=0;for(var k=0;k<els.length&&n<400;k++){var r=els[k].getBoundingClientRect();if(!r.width||r.top>innerHeight*2)continue;n++;var ff=getComputedStyle(els[k]).fontFamily.split(",")[0].replace(/["\']/g,"").trim().toLowerCase();if(ff)used[ff]=1}}catch(x){used=false}}return used===false||!f||!!used[f]};'
            . 'for(var i=0;i<u.length;i++){if(window.__wpcFPB[u[i][0]])continue;if(!uf(u[i][2]))continue;window.__wpcFPB[u[i][0]]=1;'
            . 'var l=document.createElement("link");l.rel="preload";l.as="font";l.type=u[i][1];'
            . 'l.crossOrigin="anonymous";l.href=u[i][0];document.head.appendChild(l)}};';
        if ($delay_engine_on) {
            // v7.21.249 — the warm rides GESTURE on delay-engine pages (this timer was the
            // 2.9s four-row wave, CDP-initiator-proven after every declarer was parked).
            return '<script data-nodefer="1" data-wpc-fpb="1"' . $mk . '>(function(){var u=' . $json . ';'
                . $warm_script
                . 'var hu=0,hf=function(){if(!hu){hu=1;setTimeout(g,50)}};'
                . '["pointerdown","keydown","touchstart","wheel","scroll","mousemove"].forEach(function(e){window.addEventListener(e,hf,{once:true,passive:true,capture:true})});'
                . '})();</script>';
        }
        // No delay engine: faces are eager and the early warm is the whole point — the exact
        // .689 post-paint hook (a static as=font tag render-holds Chrome 150).
        return '<script data-nodefer="1" data-wpc-fpb="1"' . $mk . '>(function(){var u=' . $json . ';'
            . $warm_script
            . 'requestAnimationFrame(function(){setTimeout(function(){g()},0)})})();</script>';
    }
}

if (!function_exists('wpc_unify_fallback_face_locals')) {
    /**
     * Make every twin face of a family shadow the SAME real font.
     *
     * A "<Family> Fallback" set is one metric-matched stand-in split across weight/style
     * descriptors. When its faces disagree on src:local(), the page swaps category mid-render —
     * a serif headline painting in Arial until the real face arrives. Disagreement inside one
     * family is always a generator artifact, never intent, so it is repairable from the data:
     * Arial is the no-information default, so any other name in the set is the measured signal
     * and governs the whole family.
     *
     * Operates on emitted CSS, which also repairs a carrier store recorded before the fix.
     */
    function wpc_unify_fallback_face_locals($css)
    {
        if (!is_string($css) || $css === '' || stripos($css, 'Fallback') === false) {
            return is_string($css) ? $css : '';
        }
        if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $face_blocks)) {
            return $css;
        }
        $local_counts = [];
        foreach ($face_blocks[0] as $face_block) {
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+?)\s*["\']?\s*;/i', $face_block, $family_match)) {
                continue;
            }
            $family = trim($family_match[1]);
            if (substr(strtolower($family), -9) !== ' fallback') {
                continue;
            }
            if (!preg_match('/src\s*:\s*local\(\s*["\']?([^"\')]+?)["\']?\s*\)/i', $face_block, $local_match)) {
                continue;
            }
            $local_name = trim($local_match[1]);
            if ($local_name === '' || strcasecmp($local_name, 'Arial') === 0 || strcasecmp($local_name, 'Helvetica') === 0) {
                continue;
            }
            $family_key = strtolower($family);
            if (!isset($local_counts[$family_key])) {
                $local_counts[$family_key] = [];
            }
            if (!isset($local_counts[$family_key][$local_name])) {
                $local_counts[$family_key][$local_name] = 0;
            }
            $local_counts[$family_key][$local_name]++;
        }
        if (!$local_counts) {
            return $css;
        }
        $winning_locals = [];
        foreach ($local_counts as $family_key => $counts) {
            arsort($counts);
            $names = array_keys($counts);
            $winning_locals[$family_key] = $names[0];
        }
        // A row's metric overrides are only valid against the ruler they were measured on. Swapping
        // a mismatched row's local() would keep Arial-derived size-adjust/ascent numbers under a
        // serif face — trading a visible font flash for a box that is the wrong size. So the row is
        // dropped, not repaired, and the descriptor-less face (correct local AND correct metrics)
        // serves that weight. A family cannot be emptied by this: the winning name is only ever
        // taken FROM a face that carries it, so at least one face of every family always survives.
        $unifiedAway = 0;
        $unified_css = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($face_match) use ($winning_locals, &$unifiedAway) {
            $block = $face_match[0];
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+?)\s*["\']?\s*;/i', $block, $family_match_inner)) {
                return $block;
            }
            $family_key = strtolower(trim($family_match_inner[1]));
            if (!isset($winning_locals[$family_key])) {
                return $block;
            }
            if (!preg_match('/src\s*:\s*local\(\s*["\']?([^"\')]+?)["\']?\s*\)/i', $block, $local_name)) {
                return $block;
            }
            if (strcasecmp(trim($local_name[1]), $winning_locals[$family_key]) === 0) {
                return $block;
            }
            $unifiedAway++;
            return '';
        }, $css);
        // Two writers (the carrier replay and the metric overrides) mint "... Fallback" faces that
        // disagree on local(); the minority rows are dropped. Sampled: the same stored faces
        // disagree on every render.
        if (is_string($unified_css) && $unifiedAway > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('fallback-faces-unified', ['dropped' => $unifiedAway], true);
        }
        return is_string($unified_css) ? $unified_css : $css;
    }
}

if (!function_exists('wpc_font_carrier_emit')) {
    function wpc_font_carrier_emit()
    {
        static $done = false;
        try {
            if ($done || !wpc_font_carrier_is_needed()) { return; }
            $done = true;
            if (function_exists('wpc_font_carrier_seed')) {
                wpc_font_carrier_seed();
            }
            $file = wpc_font_carrier_file();
            if ($file === '' || !@is_readable($file)) { return; }
            $css = (string) @file_get_contents($file);
            if ($css === '' || stripos($css, '@font-face') === false) { return; }
            if (function_exists('wpc_font_carrier_drop_foreign_faces')) {
                $css = (string) wpc_font_carrier_drop_foreign_faces($css);
                // Every stored face was third-party: the render gets no carrier, so a crit-less
                // page has no metric fallback faces. Sampled: the store answers the same each render.
                if ($css === '' || stripos($css, '@font-face') === false) {
                    if (function_exists('wpc_render_belt_note')) {
                        wpc_render_belt_note('font-carrier-skipped', ['why' => 'all-foreign'], true);
                    }
                    return;
                }
            }
            if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_heal_css_host_twin')) {
                $healed = (string) wps_cdn_rewrite::wpc_heal_css_host_twin($css);
                // A store recorded before a host move still names the old domain; it is rewritten
                // on the way out. Sampled: it repeats on every render until the store is rewritten.
                if ($healed !== $css && function_exists('wpc_render_belt_note')) {
                    wpc_render_belt_note('font-carrier-host-healed', $healed === '' ? ['emit' => 1, 'emptied' => 1] : ['emit' => 1], true);
                }
                $css = $healed;
                if ($css === '') { return; }
            }
            // v7.10.619 — unconditional emission stays small: when the store carries
            // base64 subsets recorded from crit output, prefer the url() faces (the
            // subsets already ride the crit lane itself).
            if (strlen($css) > 16384) {
                $url_faces_css = preg_replace('/@font-face\s*\{[^{}]*data:[^{}]*\}/is', '', $css, -1, $dataFacesDropped);
                if (is_string($url_faces_css) && stripos($url_faces_css, '@font-face') !== false) {
                    $css = $url_faces_css;
                    // An oversized store sheds its base64 subsets (they ride the crit lane).
                    // Sampled: the store is the same on every render.
                    if ($dataFacesDropped > 0 && function_exists('wpc_render_belt_note')) {
                        wpc_render_belt_note('font-carrier-trimmed', ['data_faces' => (int) $dataFacesDropped], true);
                    }
                }
            }
            // Icon families must never get swap: it paints the raw codepoint in the fallback
            // face first, then reflows to the glyph. Block keeps it invisible until correct.
            $settings = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
            $font_display_off = is_array($settings) && !empty($settings['font-display'])
                && strtolower((string) $settings['font-display']) === 'off';
            $css = (string) preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use ($font_display_off) {
                if (stripos($m[0], 'font-display') !== false) { return $m[0]; }
                $icon = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $f)
                    && function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($f[1]);
                if (!$icon && $font_display_off) { return $m[0]; }
                return preg_replace('/\{/', '{font-display:' . ($icon ? 'block' : 'swap') . ';', $m[0], 1);
            }, $css);
            if ($css === '' || $css === null) { return; }
            if (function_exists('wpc_unify_fallback_face_locals')) {
                $css = wpc_unify_fallback_face_locals($css);
            }
            // No preload for the carrier faces: they are declared in <head>, so the browser already
            // requests every face the first paint uses, by unicode-range, before the post-paint
            // injector could run. The injector's first-four pick preloaded unused subsets instead
            // (metropol-security.de: Barlow Condensed vietnamese + latin-ext, "preloaded but not used").
            echo "\n" . '<style id="wpc-font-carrier">' . $css . '</style>' . "\n";
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_fc602_log')) {
                set_transient('wpc_fc602_log', 1, 3600);
                wpc_cache_first_log('font-carrier-emitted', '', '', ['bytes' => strlen($css)]);
            }
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('add_action')) {
        add_action('wp_head', 'wpc_font_carrier_emit', 2);
    }
}

/**
 * v7.21.32 — HOST-MIGRATION SELF-HEAL.
 *
 * heroictravel.com receipt (2026-08-16): site migrated off heroictravel.wp1.host; DB fully
 * clean (56 tables, 0 rows matching), yet regenerated critical CSS + font-carrier.css kept
 * re-introducing the dev hostname, and its webfonts then failed CORS on the live origin.
 * The reservoir is OUR OWN cache bytes: localized-gfonts sheets under cache/wp-cio-fonts/
 * carry absolute URLs stamped with WP_CONTENT_URL at write time; the renderer renders the
 * live page, re-ingests those faces, and every fresh crit + carrier faithfully re-emits the
 * dead host. Deleting the output files just regenerates them from the same stale inputs.
 *
 * Detection is the natural-origin invariant: wp-cio-fonts URLs are NEVER zoneified
 * (rewriteLogic keeps them on the origin so they match the inline crit), so any
 * cache/wp-cio-fonts/ URL whose host differs from home_url()'s host is migration residue —
 * a CDN zone host can never false-positive this. First boot byte-scans the sheets + carrier
 * once, then a host stamp makes every later boot one option read; a future host change hits
 * the stamp path with no scan at all. On a hit: hard-delete the fonts sheets (they re-localize
 * on demand with the new host), BOTH carrier copies (the uploads copy is purge-proof by .618
 * design, so it must be deleted explicitly or it replays the dead faces forever), the v1
 * root critical files, the used-css store; soft-stale the per-URL crit (never blanks a page)
 * and purge cached HTML (it embeds the poisoned inline bytes). Multisite skips the byte scan
 * (the fonts dir is shared across subsites, so a mapped-domain sibling would false-positive)
 * and relies on the per-site stamp. A known-hosts ledger keeps WPML-class domain-per-language
 * setups (home_url filtered per request) from purge-storming: a host we have ever stamped
 * never triggers the stamp-path heal, and stamp-path heals are capped at one per 6h.
 * Fail-open, filter-killable.
 */
if (!function_exists('wpc_heal_changed_home_host')) {
    function wpc_heal_changed_home_host()
    {
        try {
            if (!function_exists('home_url') || !function_exists('get_option')
                || !function_exists('update_option') || !defined('WPS_IC_FONTS_DIR')) {
                return;
            }
            if (!apply_filters('wpc_host_heal', true)) {
                return;
            }
            $home_host = strtolower((string) parse_url((string) home_url('/'), PHP_URL_HOST));
            if ($home_host === '') {
                return;
            }
            $stamped_host = (string) get_option('wpc_home_host32', '');
            if ($stamped_host === $home_host) {
                return;
            }
            $host_changed = false;
            $reason = '';
            $known_hosts = get_option('wpc_home_hosts32', []);
            if (!is_array($known_hosts)) {
                $known_hosts = [];
            }
            if ($stamped_host !== '') {
                if (!in_array($home_host, $known_hosts, true)
                    && time() - (int) get_option('wpc_host_heal_ts32', 0) > 21600) {
                    $host_changed = true;
                    $reason = 'stamp:' . $stamped_host;
                }
            } elseif (!(function_exists('is_multisite') && is_multisite())) {
                $scan_files = [];
                $font_sheets = @glob(rtrim(WPS_IC_FONTS_DIR, '/') . '/*/*.css');
                if (is_array($font_sheets)) {
                    $scan_files = array_slice($font_sheets, 0, 40);
                }
                $carrier_file = function_exists('wpc_font_carrier_file') ? (string) wpc_font_carrier_file() : '';
                if ($carrier_file !== '' && @is_file($carrier_file)) {
                    $scan_files[] = $carrier_file;
                }
                foreach ($scan_files as $scan_file) {
                    $file_bytes = @file_get_contents($scan_file, false, null, 0, 262144);
                    if (!is_string($file_bytes) || $file_bytes === '') {
                        continue;
                    }
                    if (preg_match_all('~https?://([a-z0-9.\-]+)(?::\d+)?/[^"\')\s]*cache/wp-cio-fonts/~i', $file_bytes, $host_matches)) {
                        foreach ($host_matches[1] as $font_host) {
                            if (strtolower($font_host) !== $home_host) {
                                $host_changed = true;
                                $reason = 'bytes:' . strtolower($font_host);
                                break 2;
                            }
                        }
                    }
                }
            }
            if ($host_changed) {
                $deleted_count = 0;
                $fonts_root = rtrim(WPS_IC_FONTS_DIR, '/');
                $font_dirs = @glob($fonts_root . '/*', GLOB_ONLYDIR);
                foreach (is_array($font_dirs) ? $font_dirs : [] as $font_dir) {
                    if (@is_link($font_dir)) {
                        wpc_fs_remove_link($font_dir);
                        continue;
                    }
                    $dir_entries = @glob($font_dir . '/*');
                    foreach (is_array($dir_entries) ? $dir_entries : [] as $entry) {
                        if (is_file($entry) && @unlink($entry)) {
                            $deleted_count++;
                        }
                        if ($deleted_count > 4000) {
                            break 2;
                        }
                    }
                    @rmdir($font_dir);
                }
                $loose_entries = @glob($fonts_root . '/*');
                foreach (is_array($loose_entries) ? $loose_entries : [] as $loose_entry) {
                    if (is_file($loose_entry) && @unlink($loose_entry)) {
                        $deleted_count++;
                    }
                }
                if (defined('WPS_IC_CACHE') && @is_file(WPS_IC_CACHE . 'font-carrier.css')) {
                    @unlink(WPS_IC_CACHE . 'font-carrier.css');
                }
                if (function_exists('wpc_font_carrier_file')) {
                    $carrier_path = (string) wpc_font_carrier_file();
                    if ($carrier_path !== '' && @is_file($carrier_path)) {
                        @unlink($carrier_path);
                    }
                }
                if (defined('WPS_IC_CRITICAL')) {
                    $crit_files = @glob(rtrim(WPS_IC_CRITICAL, '/') . '/critical_*.css');
                    foreach (is_array($crit_files) ? $crit_files : [] as $crit_file) {
                        @unlink($crit_file);
                    }
                    $used_css_files = @glob(rtrim(WPS_IC_CRITICAL, '/') . '/used-css/*');
                    foreach (is_array($used_css_files) ? $used_css_files : [] as $used_css_file) {
                        if (is_file($used_css_file)) {
                            @unlink($used_css_file);
                        }
                    }
                }
                if (function_exists('wpc_crit_invalidate')) {
                    wpc_crit_invalidate('all', 'host-heal', 'stale');
                }
                if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeAllCache')) {
                    wps_ic_cache::purgeAllCache();
                }
                update_option('wpc_host_heal_ts32', time(), false);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('host-heal', $home_host, '', ['why' => $reason, 'files' => $deleted_count]);
                }
            }
            if (!in_array($home_host, $known_hosts, true)) {
                $known_hosts[] = $home_host;
                update_option('wpc_home_hosts32', array_slice($known_hosts, -8), false);
            }
            update_option('wpc_home_host32', $home_host, true);
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('add_action')) {
        add_action('init', 'wpc_heal_changed_home_host', 99);
    }
}

/**
 * v7.10.604 — PURGE ADMISSION GATE.
 *
 * The purge ledger on wpcompress.com shows 438 cf-purge and 209 cf-purge-allhtml in ~63h — a
 * full edge HTML wipe every ~18 minutes — and the `rf` field names the deciding request:
 * `heartbeat` (WP admin heartbeat, every 15-60s while a tab is open), `pys_get_pbid`
 * (PixelYourSite's ajax endpoint), `/meta.json` and other scanner probes, and plain views of
 * plugin-install.php. `rf` and `src` are trustworthy here because register_shutdown_function
 * runs in the SAME request that decided to purge; only at/by/hk describe the closure.
 *
 * None of those requests can change what a page renders, so none of them has any business
 * invalidating an edge cache. With HIT measured at 0.104s against MISS at 1.024s, a wipe every
 * 18 minutes means the edge never accumulates hits — the plugin was manufacturing its own
 * cache-miss rate.
 *
 * Fails OPEN in the safe direction: a blocked purge only means the edge keeps serving what it
 * already has, and the real content-change paths (save_post, crit published, orch landings)
 * are unaffected.
 */
if (!function_exists('wpc_purge_request_allowed')) {
    function wpc_purge_request_allowed($what = '')
    {
        try {
            // The WP heartbeat cannot change page content, and it repeats every 15-60s for as
            // long as any admin tab stays open — the single largest source in the ledger.
            $action = isset($_REQUEST['action']) ? strtolower((string) $_REQUEST['action']) : '';
            if ($action === 'heartbeat') {
                return (bool) apply_filters('wpc_purge_allow_heartbeat', false, $what);
            }
            // A THIRD-PARTY ajax action is not our event. pys_get_pbid (PixelYourSite) was
            // purging this zone repeatedly. Ours are namespaced wpc_/wps_ic_.
            if ($action !== ''
                && strpos($action, 'wpc') !== 0
                && strpos($action, 'wps_ic') !== 0
                && strpos($action, 'ic_') !== 0) {
                return (bool) apply_filters('wpc_purge_allow_foreign_ajax', false, $what);
            }
            // A 404 or low-value request renders nothing an edge cache holds. Scanner sweeps
            // (/meta.json, /.git/config, /secrets.json) hit these constantly on every site.
            if (function_exists('did_action') && did_action('template_redirect')) {
                if ((function_exists('is_404') && is_404())
                    || (function_exists('wpc_is_low_value_page') && wpc_is_low_value_page())) {
                    return (bool) apply_filters('wpc_purge_allow_low_value', false, $what);
                }
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('wpc_log_purge_gate')) {
    function wpc_log_purge_gate($what)
    {
        if (!function_exists('wpc_cache_first_log')) {
            return;
        }
        $wpc_act = isset($_REQUEST['action']) ? substr((string) $_REQUEST['action'], 0, 40) : '';
        if ($wpc_act === '' && !empty($_SERVER['REQUEST_URI'])) {
            $wpc_act = substr((string) $_SERVER['REQUEST_URI'], 0, 60);
        }
        wpc_cache_first_log('purge-gated', '', '', ['what' => (string) $what, 'rf' => $wpc_act]);
    }
}

/**
 * v7.10.604 — prune pagespeed-log-DD-MM-YYYY.txt. Two writers create one file per day
 * (wp-compress-core:3776, criticalCss-v2:984) and nothing has ever removed them: 50+ files
 * dating back to July 2025 were found alongside the live logs.
 */
if (!function_exists('wpc_prune_pagespeed_logs')) {
    function wpc_prune_pagespeed_logs()
    {
        try {
            if (!defined('WPS_IC_LOG')) {
                return 0;
            }
            $wpc_keep = (int) apply_filters('wpc_pagespeed_log_keep_days', 14);
            if ($wpc_keep < 1) {
                return 0;
            }
            $wpc_cut = time() - ($wpc_keep * DAY_IN_SECONDS);
            $wpc_n   = 0;
            $wpc_cap = (int) apply_filters('wpc_pagespeed_log_prune_cap', 200);
            foreach ((array) @glob(rtrim(WPS_IC_LOG, '/') . '/pagespeed-log-*.txt') as $wpc_f) {
                if ($wpc_n >= $wpc_cap) {
                    break;
                }
                $wpc_mt = (int) @filemtime($wpc_f);
                if ($wpc_mt > 0 && $wpc_mt < $wpc_cut && @unlink($wpc_f)) {
                    $wpc_n++;
                }
            }
            if ($wpc_n > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('pagespeed-log-pruned', '', '', ['n' => $wpc_n, 'keep' => $wpc_keep]);
            }
            return $wpc_n;
        } catch (\Throwable $e) {
            return 0;
        }
    }
    if (function_exists('add_action')) {
        add_action('wpc_autopurge_sweep', 'wpc_prune_pagespeed_logs', 30);
    }
}

/**
 * v7.10.605 — USED-CSS PROVENANCE GATE ON THE SERVE PATH.
 *
 * The used.css FILENAME is keyed on the CURRENT template hash, so the renderer only ever loads a
 * current-looking file. used_tpl.txt is a different fact: the template the service actually
 * GENERATED that content from ($json['tpl_key'], criticalCss-v2:1884). When those disagree the
 * bytes describe another template, so applying them strips rules the live template needs —
 * receipted on staging.wpcompress.com/quick-start/ as a black section background that corrected
 * itself once the rest bundle landed, plus a dead homepage menu.
 *
 * The check already existed and its remedy was exactly right — it unlinks the five pointer files
 * a human would delete by hand — but it lives in wpc_lcp_repull_handler(), so it only runs when a
 * repull happens. Between a bad land and the next repull, every visitor is served CSS built for a
 * different template. That is why deleting those five files fixed it instantly and why other
 * pages "sometimes" worked: their provenance matched, or a repull had already cleaned them.
 *
 * Fails OPEN toward CORRECTNESS: no used_tpl.txt, an unreadable one, or an empty tpl key all
 * return true and change nothing. A mismatch costs the used-css optimisation for that render and
 * serves the full stylesheet — slower, never broken.
 */
if (!function_exists('wpc_used_css_provenance_ok')) {
    function wpc_used_css_provenance_ok($dir, $current_template)
    {
        try {
            if (!apply_filters('wpc_used_css_provenance_gate', true)) {
                return true;
            }
            $dir = (string) $dir;
            $current_template = trim((string) $current_template);
            if ($dir === '' || $current_template === '') {
                return true;
            }
            $template_file = rtrim($dir, '/') . '/used_tpl.txt';
            if (!@is_readable($template_file)) {
                return true;
            }
            $stored_template = trim((string) @file_get_contents($template_file));
            if ($stored_template === '' || $stored_template === $current_template) {
                return true;
            }
            // A bundle generated from another template is refused on every render until it is
            // regenerated. Not sampled: the refusal is the service's answer for the wrong template,
            // and one line per refused render (with the page's key) is the evidence. Outside a
            // render (no belt journal loaded) it keeps the site-wide 10-minute line.
            if (function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('used-css-provenance-mismatch', [
                    'used_tpl' => substr($stored_template, 0, 24),
                    'cur_tpl'  => substr($current_template, 0, 24),
                ]);
            } elseif (function_exists('wpc_cache_first_log') && !get_transient('wpc_ucssprov_log')) {
                set_transient('wpc_ucssprov_log', 1, 600);
                wpc_cache_first_log('used-css-provenance-mismatch', '', '', [
                    'used_tpl' => substr($stored_template, 0, 24),
                    'cur_tpl'  => substr($current_template, 0, 24),
                ]);
            }
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

/**
 * v7.10.606 — BODY-SCOPE GUARD against a nested-document reset leaking into page-level used-css.
 *
 * Measured on staging.wpcompress.com/quick-start/: line ~60 of tpl-8f01017a3717945d.atf.css
 * carries body{font:12px Roboto,Arial,sans-serif;background-color:#000;color:#fff;height:100%;
 * width:100%;position:absolute;margin:0;padding:0} — a full-viewport reset written for a nested
 * document, merged into the PAGE bundle. The loader appends used-css last on purpose ("used-css
 * always last", attach() in delay-v3-loader) so it wins the cascade, which is what let one
 * foreign rule turn the page black and strip its layout.
 *
 * position:absolute on a page's body is never correct — that half is unambiguous and is guarded
 * unconditionally. The COLOUR half is not safely recoverable here: the critical CSS declares no
 * body background at all on this theme, so there is no correct value to restore to and forcing
 * transparent would break any theme that sets one in a late sheet. wpc_body_bg_guard is the
 * escape hatch for that symptom; the real fix is the generator not emitting the rule.
 */
if (!function_exists('wpc_body_scope_guard_css')) {
    function wpc_body_scope_guard_css($crit_css = '', $used_css_on = true)
    {
        try {
            if (!apply_filters('wpc_body_scope_guard', true)) {
                return '';
            }
            // v7.10.612 — BLAST RADIUS. The foreign rule arrives via the used-css bundle, so a
            // document with no used-css lane has nothing to guard against and gets nothing. And
            // body{position:relative} is a legitimate theme pattern (it anchors absolutely
            // positioned children), so forcing static would break those sites from any sheet.
            // Restore what the crit declares; only neutralise to static when it declares nothing,
            // where static is already the browser default and can only outrank a foreign rule.
            if (!$used_css_on) {
                return '';
            }
            $guard_css = '';
            $crit_position = '';
            if (is_string($crit_css) && $crit_css !== ''
                && preg_match_all('/(?<![\w.#\[-])body\s*\{([^{}]{0,600})\}/i', $crit_css, $body_rules)) {
                foreach ($body_rules[1] as $body_rule) {
                    if (preg_match('/(?<![-\w])position\s*:\s*(static|relative|absolute|fixed|sticky)/i', $body_rule, $position_match)) {
                        $crit_position = strtolower($position_match[1]);
                    }
                }
            }
            // FORCED: used-css is appended last by design, so only !important can outrank it.
            if (apply_filters('wpc_body_position_guard', true)) {
                $guard_css .= 'body{position:' . ($crit_position !== '' ? $crit_position : 'static') . '!important}';
            }
            // Restore a body background ONLY when the critical CSS actually declares one — then
            // the value is known and the guard is a restore, not a guess.
            $crit_background = '';
            if (is_string($crit_css) && $crit_css !== ''
                && preg_match_all('/(?<![\w.#\[-])body\s*\{([^{}]{0,600})\}/i', $crit_css, $background_rules)) {
                foreach ($background_rules[1] as $background_rule) {
                    if (preg_match('/background(?:-color)?\s*:\s*([^;!}]+)/i', $background_rule, $background_match)) {
                        $background_value = trim($background_match[1]);
                        if ($background_value !== '' && stripos($background_value, 'url(') === false) {
                            $crit_background = $background_value;
                        }
                    }
                }
            }
            $crit_background = (string) apply_filters('wpc_body_bg_guard', $crit_background, $crit_css);
            if ($crit_background !== '' && !preg_match('/[<>{}"\']/', $crit_background)) {
                $guard_css .= 'body{background-color:' . $crit_background . '!important}';
            }
            if ($guard_css === '') {
                return '';
            }
            return "\r\n" . '<style id="wpc-body-guard">' . $guard_css . '</style>';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

/**
 * v7.10.609 — CANONICAL LOOPBACK URLs (dispatch contract §2).
 *
 * Our own warms fetched the non-canonical form: the field log on hawkeye.design shows two cron
 * loopbacks to https://www.hawkeye.design/about-us with NO trailing slash, 2049ms + 2005ms in one
 * cron run. WordPress answers a canonical 301, so every one of those boots WordPress TWICE.
 * Service-side the same mistake cost 13.1s + 1.4s against a 15s timeout and blocked that site's
 * generations for 21 hours (their v3.142 learns canonicals for the same reason).
 *
 * NOT a blind trailing-slash append — that would inflict the mirror bug on every site whose
 * permalinks canonicalise the other way. Two sources, in order:
 *   1. what a fetch OBSERVABLY landed on, remembered per URL;
 *   2. user_trailingslashit(), which is WordPress reporting the SITE'S OWN permalink_structure.
 * Same-host only: adopting a cross-host redirect would generate crit for a different site.
 */
if (!function_exists('wpc_canon_is_same_host')) {
    function wpc_canon_is_same_host($url_a, $url_b)
    {
        $host_a = strtolower((string) parse_url((string) $url_a, PHP_URL_HOST));
        $host_b = strtolower((string) parse_url((string) $url_b, PHP_URL_HOST));
        if ($host_a === '' || $host_b === '') { return false; }
        $strip_www = function ($wpc_h) { return strpos($wpc_h, 'www.') === 0 ? substr($wpc_h, 4) : $wpc_h; };
        return $strip_www($host_a) === $strip_www($host_b);
    }
}

if (!function_exists('wpc_canonical_url')) {
    function wpc_canonical_url($url)
    {
        try {
            $url = trim((string) $url);
            if ($url === '' || !function_exists('get_option')) { return $url; }
            if (!apply_filters('wpc_canonical_loopback', true)) { return $url; }
            $canon_map = get_option('wpc_canon_map', []);
            if (is_array($canon_map)) {
                $map_key = md5($url);
                if (!empty($canon_map[$map_key]['u'])
                    && wpc_canon_is_same_host($url, $canon_map[$map_key]['u'])) {
                    return (string) $canon_map[$map_key]['u'];
                }
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);
            // Only a page-like path can be canonicalised this way. A file has an extension and a
            // query-bearing URL is not a permalink.
            if ($path === '' || $query !== '' || preg_match('/\.[A-Za-z0-9]{1,8}$/', $path)) {
                return $url;
            }
            if (!function_exists('user_trailingslashit')) { return $url; }
            $slashed_path = user_trailingslashit($path);
            if (!is_string($slashed_path) || $slashed_path === '' || $slashed_path === $path) { return $url; }
            return str_replace($path, $slashed_path, $url);
        } catch (\Throwable $e) {
            return (string) $url;
        }
    }
}

if (!function_exists('wpc_crit_pointer_key')) {
    /** The service's pointer key (crit-push latest-artifacts.js canonicalizeForKey): https forced, host
     *  lowercased and www-folded, trailing slash folded on any non-root path. Scheme-less input accepted.
     *  Every site that builds the key by hand folds it differently; this is the one that matches. */
    function wpc_crit_pointer_key($pageUrl)
    {
        $u = trim((string) $pageUrl);
        if ($u === '') { return ''; }
        if (strpos($u, '://') === false) { $u = 'https://' . ltrim($u, '/'); }
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        if ($host === '') { return ''; }
        $path = (string) parse_url($u, PHP_URL_PATH);
        if ($path === '') { $path = '/'; }
        if (strlen($path) > 1 && substr($path, -1) === '/') { $path = substr($path, 0, -1); }
        return $host . $path;
    }
}

if (!function_exists('wpc_crit_pointer_url')) {
    /** The storage pointer object for one page: latest/{sha1(apikey):16}/{sha1(key):16}.json. The
     *  pointer is overwritten in place, so ?t= is a mandatory cache-bust. Empty when either half
     *  is unresolvable, and the caller then has no pointer to ask for. */
    function wpc_crit_pointer_url($apikey, $pageUrl)
    {
        $key = wpc_crit_pointer_key($pageUrl);
        if ($key === '' || (string) $apikey === '') { return ''; }
        return 'https://critical-css-mc.b-cdn.net/latest/' . substr(sha1((string) $apikey), 0, 16)
            . '/' . substr(sha1($key), 0, 16) . '.json?t=' . time();
    }
}

if (!function_exists('wpc_canon_learn_redirect')) {
    function wpc_canon_learn_redirect($from_url, $to_url)
    {
        try {
            $from_url = trim((string) $from_url);
            $to_url   = trim((string) $to_url);
            if ($from_url === '' || $to_url === '' || $from_url === $to_url) { return false; }
            if (!function_exists('get_option') || !wpc_canon_is_same_host($from_url, $to_url)) { return false; }
            $canon_map = get_option('wpc_canon_map', []);
            if (!is_array($canon_map)) { $canon_map = []; }
            $map_key = md5($from_url);
            if (isset($canon_map[$map_key]['u']) && $canon_map[$map_key]['u'] === $to_url) { return false; }
            $now = time();
            foreach ($canon_map as $entry_key => $entry) {
                if (!is_array($entry) || ($now - (int) ($entry['t'] ?? 0)) > 7 * DAY_IN_SECONDS) {
                    unset($canon_map[$entry_key]);
                }
            }
            $map_cap = (int) apply_filters('wpc_canon_map_cap', 200);
            if (count($canon_map) >= $map_cap) { $canon_map = array_slice($canon_map, -($map_cap - 1), null, true); }
            $canon_map[$map_key] = ['u' => $to_url, 't' => $now];
            update_option('wpc_canon_map', $canon_map, false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('canon-url-learned', '', $from_url, ['to' => substr($to_url, 0, 90)]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_response_final_url')) {
    /** Final URL of a wp_remote_* response, when Requests exposes it. */
    function wpc_response_final_url($response)
    {
        try {
            if (!is_array($response) || empty($response['http_response'])
                || !is_object($response['http_response'])
                || !method_exists($response['http_response'], 'get_response_object')) {
                return '';
            }
            $response_object = $response['http_response']->get_response_object();
            return (is_object($response_object) && !empty($response_object->url)) ? (string) $response_object->url : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

/**
 * v7.10.610 — ONE GATEWAY FOR THE FOREIGN-CACHE PURGE.
 *
 * do_action('wps_ic_purge_all_cache') has 10 call sites and its listeners include
 * litespeed_purge_all, rocket_clean_domain and the Varnish/Breeze/SG fan-out — i.e. it empties
 * the HOST'S OWN cache, not ours. Exactly ONE of those ten was rate limited: the single-post
 * branch at cache.class.php:339, whose own comment says a purge "must not nuke
 * LiteSpeed/Nginx/Varnish more than once per 2 min". The FULL-SITE branch beside it, and the
 * eight others, had no limit at all — the throttle sat on the narrow purge and not on the
 * destructive one. On a LiteSpeed host a repeatedly-emptied LSCache means PHP absorbs traffic
 * LiteSpeed was serving for free, which is the shape of "fast and fine without us".
 *
 * A RATE LIMIT, never a removal: .298-.302 deliberately RESTORED this fan-out because
 * under-purging caused stale-content bugs. The first purge in a window always passes; only the
 * repeats inside it are dropped, and a purge is never deferred into never-happening.
 *
 * Also the instrument: the ledger had no entry for this lane at all, so the one purge class we
 * most suspected was the one we could not see. One choke point means one wpc_purge_record().
 */
/**
 * v7.10.664 — NON-CF device-blind foreign-cache detection (R2 of the device-split finalization).
 * A foreign page cache that stores ONE copy per URL for ALL devices, in front of device-split crit,
 * serves the first device's optimized page to everyone (mobile gets a desktop page or vice versa) —
 * the same first-device-wins hazard a device-blind CF edge has. wpc_combined_crit_on() consults
 * wpc_foreign_device_blind_cache() as a non-CF safety floor and stays device-universal when it
 * cannot PROVE the front cache varies by device. WPC's own cache is device-keyed (mobile_ prefix)
 * and is exempt. wpc_device_blind_decide() is the PURE, unit-tested decision.
 */
if (!function_exists('wpc_device_blind_decide')) {
    function wpc_device_blind_decide($signals, $breeze, $rocket)
    {
        $signals = is_array($signals) ? $signals : [];
        // Breeze: device-blind when desktop cache on and mobile cache off.
        if (!empty($signals['breeze'])) {
            $desk = is_array($breeze) && !empty($breeze['breeze-desktop-cache']) && $breeze['breeze-desktop-cache'] == '1';
            $mob  = is_array($breeze) && !empty($breeze['breeze-mobile-cache']) && $breeze['breeze-mobile-cache'] == '1';
            if ($desk && !$mob) { return true; }
        }
        // WP Rocket: device-aware ONLY with separate mobile-file caching on.
        if (!empty($signals['wp-rocket'])) {
            $aware = is_array($rocket) && !empty($rocket['cache_mobile']) && !empty($rocket['do_caching_mobile_files']);
            if (!$aware) { return true; }
        }
        // The rest: a live presence is device-blind for our purposes (per-device variance cannot be
        // proven from a constant), fail-safe toward the SAFE combined mode.
        foreach (['litespeed', 'w3tc', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler', 'comet-cache'] as $wpc_k) {
            if (!empty($signals[$wpc_k])) { return true; }
        }
        return false;
    }
}
if (!function_exists('wpc_foreign_device_blind_cache')) {
    function wpc_foreign_device_blind_cache()
    {
        if (!function_exists('get_option')) { return false; }
        $signals = [
            'breeze'           => defined('BREEZE_VERSION'),
            'wp-rocket'        => defined('WP_ROCKET_VERSION'),
            'litespeed'        => defined('LSCWP_V'),
            'w3tc'             => defined('W3TC'),
            'wp-super-cache'   => defined('WPCACHEHOME'),
            'wp-fastest-cache' => defined('WPFC_MAIN_PATH'),
            'cache-enabler'    => defined('CACHE_ENABLER_VERSION'),
            'comet-cache'      => defined('COMET_CACHE_PLUGIN_FILE'),
        ];
        $breeze = !empty($signals['breeze']) ? get_option('breeze_basic_settings') : [];
        $rocket = !empty($signals['wp-rocket']) ? get_option('wp_rocket_settings') : [];
        $blind  = wpc_device_blind_decide($signals, is_array($breeze) ? $breeze : [], is_array($rocket) ? $rocket : []);
        return (bool) apply_filters('wpc_foreign_device_blind_cache', $blind);
    }
}
if (!function_exists('wpc_cf_fronted_html')) {
    /**
     * Did our HTML arrive through Cloudflare? Twin of wpc_foreign_device_blind_cache() for the edge
     * that is not a WordPress plugin. Deliberately not zone_is_cf(): that also answers yes for a CF
     * asset CNAME, which says nothing about what caches the document. Only the edge-added request
     * headers prove the HTML path.
     *
     * The answer is remembered because the renders that MINT cached HTML include cron and CLI
     * passes carrying no request headers at all — a detector that forgot between requests would let
     * exactly those renders mint device-split bytes onto a device-blind edge.
     */
    function wpc_cf_fronted_html()
    {
        $cf_headers_seen = (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']));
        if ($cf_headers_seen) {
            if (function_exists('get_option') && function_exists('update_option')
                && !get_option('wpc_cf_fronted_seen', 0)) {
                update_option('wpc_cf_fronted_seen', 1, false);
            }
            return (bool) apply_filters('wpc_cf_fronted_html', true);
        }
        $cf_seen_before = function_exists('get_option') ? !empty(get_option('wpc_cf_fronted_seen', 0)) : false;
        return (bool) apply_filters('wpc_cf_fronted_html', $cf_seen_before);
    }
}
if (!function_exists('wpc_devblind_edge')) {
    /**
     * Is there an HTML cache in front of us that cannot be PROVEN to key per device?
     *
     * Single source of truth for two callers that must never disagree: the crit decision
     * (wpc_combined_crit_on) and the crit emission gate (wpc_combined_both_blobs_required). A
     * decision of combined that the emission gate does not honour still writes one device's blob.
     *
     * .682 allowlist: split is unlocked only by a stamp that POSITIVELY names a deploy readback.
     */
    function wpc_devblind_edge()
    {
        if (!function_exists('wpc_cf_fronted_html') || !wpc_cf_fronted_html()) {
            return false;
        }
        // v7.21.243 — A READBACK STAMP DIES WITH ITS INTEGRATION. The stamp certifies a
        // device-key rule WE deployed; with the CF integration disconnected (no token/zone)
        // that rule cannot be ours and cannot be re-verified — yet the stamp survived and
        // unlocked device-split forever (bestexteriorsinc: phone viewport served the
        // desktop crit under cf-cache-status HIT; rosario's 0.5-CLS twin). CF-fronted with
        // no connected integration = device-blind, stamp or no stamp.
        $cf_integration = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
        if (!(is_array($cf_integration) && !empty($cf_integration['token']) && !empty($cf_integration['zone']))) {
            return true;
        }
        // v7.21.243b — AND A STAMP AGES EVEN WITH ITS INTEGRATION ALIVE. bestexteriorsinc:
        // integration connected, readback stamp present — and the edge STILL served the
        // desktop crit to a phone (James: "cloudflare is no longer doing mobile and desktop
        // separate"). The edge changed under a truthful-once stamp. Behind Cloudflare the
        // default is now device-universal COMBINED, always; a zone that re-proves its device
        // key opts back into split via filter wpc_cf_devkey_trust.
        if (!apply_filters('wpc_cf_devkey_trust', false)) {
            return true;
        }
        $devkey_stamp = function_exists('get_option') ? get_option('wpc_cf_devkey_verified') : false;
        return !is_array($devkey_stamp) || empty($devkey_stamp['devkey'])
            || (isset($devkey_stamp['src']) ? (string) $devkey_stamp['src'] : '') !== 'readback';
    }
}

if (!function_exists('wpc_purge_foreign_caches')) {
    function wpc_purge_foreign_caches($purge_arg = false, $ctx = '')
    {
        try {
            if (!function_exists('do_action')) { return false; }
            if (!apply_filters('wpc_foreign_purge_enabled', true, $ctx)) { return false; }
            // Scope is the key: a single-URL purge and a whole-site purge are different events
            // and must not throttle one another.
            $purge_scope = ($purge_arg === false || $purge_arg === null || $purge_arg === '')
                ? 'site' : 'url:' . md5((string) $purge_arg);
            $throttle_window = (int) apply_filters('wpc_foreign_purge_window_s', 120, $purge_scope, $ctx);
            if ($throttle_window > 0 && function_exists('get_transient')) {
                $throttle_key = 'wpc_fp610_' . md5($purge_scope);
                if (get_transient($throttle_key)) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('foreign-purge-throttled', '', '', [
                            'scope' => $purge_scope, 'ctx' => (string) $ctx, 'win' => $throttle_window,
                        ]);
                    }
                    return false;
                }
                set_transient($throttle_key, 1, $throttle_window);
            }
            if (function_exists('wpc_purge_record')) {
                wpc_purge_record('foreign', 'purge_all', $purge_scope, 1, true, (string) $ctx);
            }
            do_action('wps_ic_purge_all_cache', $purge_arg);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/**
 * v7.10.611 — DISPATCH DISCIPLINE (contract §3 budgets/jitter, §4 response handling).
 *
 * The fleet runs ~5,900 generations/hour and ~16% fail, and every dispatched URL costs the
 * service 5-6 full Chromium renders. We had no per-site budget, no concurrency cap, no jitter,
 * and no response classification: a 400 that can never succeed was retried like a 503.
 *
 * §4's classes matter because they are NOT interchangeable. A 400 unrenderable_url is our bug and
 * must be dropped forever, never counting against the site. A 429/503 is capacity and must honour
 * Retry-After exactly. fetch_blocked / css_stub / css_empty arm a per-site backoff. server_error
 * and generation_failed are theirs and must NEVER arm backoff against the site — treating those
 * as our fault is how a healthy site talks itself into a block.
 */
if (!function_exists('wpc_dispatch_day_key')) {
    function wpc_dispatch_day_key() { return 'wpc_disp611_' . gmdate('Ymd'); }
}

if (!function_exists('wpc_dispatch_is_allowed')) {
    function wpc_dispatch_is_allowed($url, $burst = false)
    {
        try {
            if (!function_exists('get_option')) { return true; }
            if (!apply_filters('wpc_dispatch_budget', true, $url)) { return true; }
            $url = (string) $url;
            // A URL the service has permanently refused, or that failed 3x on this content
            // version, is dead until something changes.
            $dead_urls = get_option('wpc_disp_dead611', []);
            if (is_array($dead_urls) && !empty($dead_urls[md5($url)])) {
                wpc_dispatch_log('dispatch-skip-dead', $url, []);
                return false;
            }
            $in_flight = (int) get_transient('wpc_disp_conc611');
            $max_concurrent = (int) apply_filters('wpc_dispatch_max_concurrent', 2);
            if ($max_concurrent > 0 && $in_flight >= $max_concurrent) {
                wpc_dispatch_log('dispatch-skip-concurrent', $url, ['n' => $in_flight]);
                return false;
            }
            $daily_cap = (int) apply_filters('wpc_dispatch_daily_cap', $burst ? 100 : 20, $burst);
            $used_today = (int) get_option(wpc_dispatch_day_key(), 0);
            if ($daily_cap > 0 && $used_today >= $daily_cap) {
                wpc_dispatch_log('dispatch-skip-budget', $url, ['used' => $used_today, 'cap' => $daily_cap]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('wpc_dispatch_log')) {
    function wpc_dispatch_log($event, $url, $extra = [])
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log((string) $event, '', (string) $url, (array) $extra);
        }
    }
}

if (!function_exists('wpc_dispatch_count_against_budget')) {
    /** Count a dispatch against the day's budget and the concurrency window. */
    function wpc_dispatch_count_against_budget($url)
    {
        try {
            if (!function_exists('get_option')) { return; }
            $day_key = wpc_dispatch_day_key();
            update_option($day_key, ((int) get_option($day_key, 0)) + 1, false);
            $concurrency_hold = (int) apply_filters('wpc_dispatch_concurrency_hold_s', 120);
            set_transient('wpc_disp_conc611', ((int) get_transient('wpc_disp_conc611')) + 1, max(10, $concurrency_hold));
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_dispatch_jitter_seconds')) {
    /** Seconds of spread so a fleet-wide cron tick does not become a synchronized wave. */
    function wpc_dispatch_jitter_seconds($max_seconds = 0)
    {
        $max_seconds = (int) ($max_seconds > 0 ? $max_seconds : apply_filters('wpc_dispatch_jitter_s', 180));
        if ($max_seconds < 1) { return 0; }
        // Deterministic per SITE, so one site does not re-roll into a neighbour's slot every tick.
        $seed = function_exists('home_url') ? home_url('/') : (string) ($_SERVER['HTTP_HOST'] ?? 'x');
        return (int) (hexdec(substr(md5((string) $seed), 0, 6)) % ($max_seconds + 1));
    }
}

if (!function_exists('wpc_dispatch_classify_response')) {
    /**
     * Classify a dispatch response. Returns:
     *   drop       — never dispatch this URL again until something changes (our bug)
     *   cool_s     — seconds to wait before ANY dispatch (honours Retry-After)
     *   site_fault — whether this counts against the site's standing
     *   retry      — whether re-dispatching can ever succeed
     */
    function wpc_dispatch_classify_response($url, $code, $body = '', $headers = [])
    {
        $verdict = ['drop' => false, 'cool_s' => 0, 'site_fault' => false, 'retry' => true, 'class' => ''];
        try {
            $code = (int) $code;
            $body_lower = strtolower((string) $body);
            $retry_after = 0;
            if (is_array($headers)) {
                foreach ($headers as $header_name => $header_value) {
                    if (strtolower((string) $header_name) === 'retry-after') {
                        $retry_after = max(0, min(3600, (int) $header_value));
                    }
                }
            }
            if ($code === 400 || strpos($body_lower, 'unrenderable_url') !== false) {
                // Impossible by construction. Ours to fix, and it must never touch the site's
                // standing or be retried.
                $verdict['drop'] = true; $verdict['retry'] = false;
                $verdict['class'] = 'unrenderable';
                if (function_exists('get_option')) {
                    $dead_urls = get_option('wpc_disp_dead611', []);
                    if (!is_array($dead_urls)) { $dead_urls = []; }
                    if (count($dead_urls) > 500) { $dead_urls = array_slice($dead_urls, -400, null, true); }
                    $dead_urls[md5((string) $url)] = time();
                    update_option('wpc_disp_dead611', $dead_urls, false);
                }
            } elseif ($code === 429 || $code === 503) {
                $verdict['cool_s'] = $retry_after > 0 ? $retry_after : 60;
                $verdict['class'] = 'busy';
            } elseif (strpos($body_lower, 'fetch_blocked') !== false
                || strpos($body_lower, 'css_stub') !== false || strpos($body_lower, 'css_empty') !== false) {
                $verdict['site_fault'] = true;
                $verdict['cool_s'] = $retry_after > 0 ? $retry_after : 300;
                $verdict['class'] = 'origin';
            } elseif (strpos($body_lower, 'server_error') !== false
                || strpos($body_lower, 'generation_failed') !== false || $code >= 500) {
                // Theirs. Retry is fine; it must NEVER arm backoff against this site.
                $verdict['cool_s'] = $retry_after > 0 ? $retry_after : 30;
                $verdict['class'] = 'service';
            } elseif ($code >= 200 && $code < 300) {
                $verdict['class'] = 'accepted';
            }
            if ($verdict['cool_s'] > 0 && function_exists('set_transient')) {
                set_transient('wpc_disp_cool611', 1, $verdict['cool_s']);
            }
            wpc_dispatch_log('dispatch-note', (string) $url, [
                'code' => $code, 'class' => $verdict['class'],
                'drop' => $verdict['drop'] ? 1 : 0, 'cool' => $verdict['cool_s'],
                'site_fault' => $verdict['site_fault'] ? 1 : 0,
            ]);
            return $verdict;
        } catch (\Throwable $e) {
            return $verdict;
        }
    }
}

// ─── PHP 7.4 POLYFILLS (v7.10.612) ──────────────────────────────────────────────
// readme.txt declares "Requires PHP: 7.4" and the advanced-cache drop-in admits anything >= 7.2,
// but nine str_contains/str_starts_with/str_ends_with calls ship across advancedCache.php,
// fonts.class.php, rewriteLogic.php and integrations/elementor.php with no polyfill anywhere.
// Those are PHP 8.0+. On 7.4 they are a fatal — and in advancedCache.php that fatal happens in
// the drop-in, before WordPress loads, on EVERY request: a white screen, not a degraded page.
// Defined in BOTH defines.php and traits/url_key.php because the drop-in loads url_key.php and
// never loads defines.php. function_exists-guarded, so double definition is impossible.
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strpos((string) $haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strncmp((string) $haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}

/**
 * v7.10.613 — LANE-SPLIT DETECTOR.
 *
 * The delay feature has exactly two lanes: sync (runs at parse) and delayed (held, released on
 * the human signal). A dependency graph split across both breaks in two ways:
 *   HARD  — a dependent runs BEFORE its dependency: undefined errors.
 *   QUIET — a dependent arrives AFTER its dependency's one-shot init already fired, so its
 *           handlers never attach. Nothing errors. Receipted on staging.wpcompress.com/giveaway/:
 *           Elementor core sync, elementor-pro + jquery.smartmenus delayed, menu simply dead.
 *
 * We found that by looking at two pages. Across 10k+ sites the binding constraint is not which
 * fix to apply, it is not KNOWING WHICH SITES ARE AFFECTED — every threshold picked without that
 * number is a guess. So: measure first, then choose how aggressively to fix.
 *
 * COSTS NOTHING ON A VISITOR RENDER, BY CONSTRUCTION. It runs only on a warm or cron request,
 * at most once per day per site, never touches the buffer, and returns void. A visitor request
 * exits at the first condition.
 */
if (!function_exists('wpc_lane_handle_from_id')) {
    function wpc_lane_handle_from_id($element_id)
    {
        $element_id = (string) $element_id;
        return substr($element_id, -3) === '-js' ? substr($element_id, 0, -3) : $element_id;
    }
}

if (!function_exists('wpc_lane_is_library')) {
    /**
     * A plain LIBRARY exposes an API and fires no one-shot init, so a delayed script depending on
     * a sync one is normal and correct — jQuery being sync is the usual arrangement. Without this
     * the QUIET check fires on essentially every site and the signal is worthless. The HARD check
     * deliberately does NOT use it: a sync script running before a DELAYED jQuery is broken either
     * way. Filterable, because the honest way to tune this is from field data.
     */
    function wpc_lane_is_library($handle)
    {
        $handle = strtolower((string) $handle);
        $libraries = (array) apply_filters('wpc_lane_libraries', [
            'jquery', 'jquery-core', 'jquery-migrate', 'jquery-ui-core', 'underscore', 'backbone',
            'wp-polyfill', 'wp-hooks', 'wp-i18n', 'wp-util', 'lodash', 'moment', 'react', 'react-dom',
        ]);
        foreach ($libraries as $library) {
            if ($handle === strtolower((string) $library)) { return true; }
        }
        return strpos($handle, 'jquery-ui-') === 0;
    }
}

if (!function_exists('wpc_lane_detect_split')) {
    function wpc_lane_detect_split($html)
    {
        try {
            // Visitor renders leave immediately — this is the whole no-slowdown guarantee.
            $is_warm_render = !empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
                || (defined('DOING_CRON') && DOING_CRON);
            if (!$is_warm_render) { return; }
            if (!apply_filters('wpc_lane_split_detect', true)) { return; }
            if (!is_string($html) || strlen($html) < 1024) { return; }
            if (!function_exists('get_transient') || get_transient('wpc_lane_split613')) { return; }
            if (!function_exists('wp_scripts')) { return; }
            $scripts_registry = wp_scripts();
            if (!is_object($scripts_registry) || empty($scripts_registry->registered)) { return; }
            set_transient('wpc_lane_split613', 1, (int) apply_filters('wpc_lane_split_period_s', DAY_IN_SECONDS));

            // DELAYED lane: ids preserved inside the delayed-scripts payload.
            $delayed_handles = [];
            if (preg_match_all('/"id"\s*:\s*"([A-Za-z0-9_\-]+)"/', $html, $delayed_id_matches)) {
                foreach ($delayed_id_matches[1] as $delayed_id) { $delayed_handles[wpc_lane_handle_from_id($delayed_id)] = 1; }
            }
            // v7.10.615 — THERE ARE THREE LANES, NOT TWO. A tag carrying data-wpc-defer="1" is
            // DEFERRED (async fetch, in-order execution after parse), not sync — it is what the
            // wpc_delay_v3_promoted list produces. Counting it as sync reported a split on every
            // site using the defer lane. Field markup:
            //   <script fetchpriority="low" defer data-wpc-defer="1" id="jquery-core-js" src="...">
            // Only a tag with NEITHER the defer marker nor payload membership is genuinely sync.
            $sync_handles = [];
            if (preg_match_all('/<script\b[^>]*>/i', $html, $script_tags)) {
                foreach ($script_tags[0] as $script_tag) {
                    if (stripos($script_tag, ' src=') === false) { continue; }
                    if (stripos($script_tag, 'data-wpc-defer') !== false) { continue; }
                    if (stripos($script_tag, 'wpc-delay') !== false) { continue; }
                    if (!preg_match('/\bid=["\']([A-Za-z0-9_\-]+)["\']/i', $script_tag, $id_match)) { continue; }
                    $sync_handles[wpc_lane_handle_from_id($id_match[1])] = 1;
                }
            }
            // A handle in the delayed payload is NOT sync, whatever a leftover tag says.
            foreach (array_keys($delayed_handles) as $delayed_handle) { unset($sync_handles[$delayed_handle]); }
            if (empty($delayed_handles) || empty($sync_handles)) { return; }

            $quiet_splits = [];
            $hard_splits  = [];
            foreach ($scripts_registry->registered as $handle => $script) {
                $deps = (is_object($script) && !empty($script->deps) && is_array($script->deps))
                    ? $script->deps : [];
                if (empty($deps)) { continue; }
                foreach ($deps as $dep) {
                    // QUIET: dependency sync, dependent delayed — the missed one-shot init.
                    if (isset($delayed_handles[$handle]) && isset($sync_handles[$dep])
                        && !wpc_lane_is_library($dep)) {
                        $quiet_splits[] = $handle . '<' . $dep;
                    }
                    // HARD: dependent sync, dependency delayed — runs before what it needs.
                    if (isset($sync_handles[$handle]) && isset($delayed_handles[$dep])) {
                        $hard_splits[] = $handle . '<' . $dep;
                    }
                }
            }
            if (empty($quiet_splits) && empty($hard_splits)) { return; }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('lane-split', '', (string) ($_SERVER['REQUEST_URI'] ?? ''), [
                    'quiet' => count($quiet_splits),
                    'hard'  => count($hard_splits),
                    'q'     => substr(implode(',', array_slice(array_unique($quiet_splits), 0, 6)), 0, 200),
                    'h'     => substr(implode(',', array_slice(array_unique($hard_splits), 0, 6)), 0, 200),
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
}

// The Elementor sections a page opens with, and whether a CSS artifact names any of them. One
// reader, the crit coverage receipt. It decides nothing: that an artifact fits its page is the
// crit service's decision, for the crit and for the used-CSS bundle alike.
if (!function_exists('wpc_atf_section_ids')) {
    function wpc_atf_section_ids($html)
    {
        if (!is_string($html) || $html === '') {
            return [];
        }
        // Content sections only: the header template styles pass on broken artifacts
        // too (measured 2026-07-30), so the discriminator is the wp-page/post wrapper.
        $content_pos = stripos($html, 'data-elementor-type="wp-p');
        if ($content_pos === false) {
            return [];
        }
        if (!preg_match_all('/data-id="([a-f0-9]{6,8})"/', substr($html, $content_pos, 60000), $matches)) {
            return [];
        }
        // v7.10.630 — 12, not 3. Unstyled containers are ORDINARY (receipt /pricing/: 4 of 29
        // sections carry no rule in ANY of the page's own CSS), so a 3-id sample can land
        // entirely on them and condemn a perfect artifact.
        return array_slice(array_values(array_unique($matches[1])), 0, 12);
    }
}
if (!function_exists('wpc_artifact_covers_atf')) {
    function wpc_artifact_covers_atf($css, $ids)
    {
        // Can only judge substantial artifacts on pages with identifiable content
        // sections; anything else passes (fail open to serving).
        if (!is_string($css) || strlen($css) < 1024 || !is_array($ids) || count($ids) < 2) {
            return true;
        }
        // Judge the WHOLE sample: one hit proves the artifact addresses this page, and only a
        // total miss is evidence of a foreign one. Slicing to 2 made an unstyled pair fatal.
        foreach ($ids as $section_id) {
            if (strpos($css, (string) $section_id) !== false) {
                return true;
            }
        }
        return false;
    }
}
// v7.24.08 — THE DISPATCH RECORDS THE CORPUS IT PUSHED (belt cluster 4).
//
// One question: is the CSS surface this render sees the surface the critical artifact was
// built from? It used to be inferred at render time by three cooperating proxies, each with
// its own state — a global, two transient families, a per-lane list of up to sixteen
// historical keys and a file per lane — because the dispatch recorded no identity of what it
// pushed. Deleted, all of it. The answer is now a single id, computed the same way on both
// sides: by the push render that hands the service its corpus, and by every visitor render
// that serves the result.
if (!function_exists('wpc_crit_own_hosts')) {
    /** The hosts this site's own files are linked from: the home host, the CDN zone and the custom cname. */
    function wpc_crit_own_hosts()
    {
        $hosts = [];
        if (function_exists('home_url')) {
            $homeHost = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            if ($homeHost !== '') {
                $hosts[$homeHost] = 1;
            }
        }
        if (class_exists('wps_rewriteLogic') && !empty(wps_rewriteLogic::$zoneName)) {
            $hosts[strtolower((string) wps_rewriteLogic::$zoneName)] = 1;
        }
        foreach (['ic_custom_cname', 'ic_cdn_zone_name'] as $zoneOption) {
            $zoneHost = function_exists('get_option') ? strtolower(trim((string) get_option($zoneOption))) : '';
            if ($zoneHost !== '') {
                $hosts[$zoneHost] = 1;
            }
        }
        return array_keys($hosts);
    }
}
// The corpus id's format: 'h' = content hashes per sheet. Bump when the tuple changes meaning.
if (!defined('WPC_CRIT_CORPUS_ID_FORMAT')) {
    define('WPC_CRIT_CORPUS_ID_FORMAT', 'h');
}
if (!function_exists('wpc_crit_corpus_id')) {
    /**
     * Identity of the CSS surface in a PRISTINE buffer: the site's own stylesheet links,
     * resolved to files under ABSPATH, as `path|mtime|size` tuples, sorted, sha1'd, with the
     * tuple count appended. When the render carries a combined bundle its tuple joins the set.
     *
     * The host a link names is not part of the identity: a sheet counts when its path resolves
     * to a file under ABSPATH, whatever host serves it (origin, CDN zone, custom cname, the
     * Cloudflare CDN host), and a foreign CDN's sheet counts only if its path happens to exist
     * here, which costs at worst one regeneration. Rule: the id must not depend on where it is
     * computed. It is recorded by a dispatch outside any render (kick receiver, admin-ajax,
     * cron) and compared by every visitor render, and the two resolved "this site's hosts"
     * differently: the host set read wps_rewriteLogic::$zoneName, which only a render sets.
     * Observed failure: wpcompress.com/pricing 2026-09-29, sheets on the Cloudflare CDN host
     * cdn.wpcompress.com; the dispatch recorded `desktop|…:3` (the three origin-host sheets),
     * every desktop render counted 19, so every desktop render read drift, nothing parked, and
     * each drift stale-marked a regeneration that recorded `:3` again.
     *
     * Only /wp-content and /wp-includes count, and our
     * own derived artifacts (crit, used-css, hostfix, wpc-assets) are excluded — they are
     * OUTPUTS of the pipeline, so counting them would make every land drift against itself.
     * A processed copy under cache/wp-cio/css counts as the SOURCE sheet it was built from
     * (wpc_processed_copy_source_path), never as itself. The copy's name and bytes are outputs
     * too: its name hashes pipeline state that a land moves (the unicode-range families read
     * from the page's own font-subsets.css, which a land can create or change),
     * so the render right after a land linked a different set of copies than the push render
     * that recorded corpus.txt. Counted as themselves, every copy read as changed and the fresh
     * crit was stale-marked as corpus drift (greenvalleytint /services/ 2026-09-24: land 09:09:19,
     * drift 09:09:56, all 15 copies renamed, the re-dispatch answered `unchanged`).
     *
     * The tuple is the sheet's content hash (wpc_crit_corpus_tuple), so a sheet rewritten with
     * identical bytes keeps the id. Ids carry the format prefix WPC_CRIT_CORPUS_ID_FORMAT: a
     * stamp written by an earlier release (mtime tuples) has no prefix, and the verdict reads a
     * stamp of another format as 'unknown', never as drift.
     *
     * Rel-agnostic by design: the same page rendered blind, parked or combined must produce the
     * same id, and the rel attribute is exactly what those lanes rewrite.
     *
     * @return string <format>:sha1:count, or '' when no local sheet resolves (no identity, no verdict).
     */
    function wpc_crit_corpus_id($html)
    {
        try {
            if (!is_string($html) || $html === '' || !preg_match_all('/<link\b[^>]*>/i', $html, $linkTags)) {
                return '';
            }
            $docRoot = defined('ABSPATH') ? rtrim(ABSPATH, '/') : '';
            $tuples = [];
            foreach ($linkTags[0] as $linkTag) {
                if (!preg_match('/\bhref=(["\'])([^"\']+)\1/i', $linkTag, $hrefMatch)) {
                    continue;
                }
                $href = html_entity_decode((string) $hrefMatch[2]);
                $hrefParts = parse_url($href);
                if (!is_array($hrefParts)) {
                    continue;
                }
                $sheetPath = isset($hrefParts['path']) ? (string) $hrefParts['path'] : '';
                if (substr(strtolower($sheetPath), -4) !== '.css') {
                    continue;
                }
                if (!preg_match('#^/(?:wp-content|wp-includes)/#', $sheetPath) || strpos($sheetPath, '..') !== false) {
                    continue;
                }
                if (preg_match('#/cache/(?:critical|wpc-hostfix)/|/wpc-assets/|/used-css/#', $sheetPath)) {
                    continue;
                }
                if ($docRoot === '') {
                    continue;
                }
                $sheetFile = $docRoot . $sheetPath;
                if (strpos($sheetPath, '/cache/wp-cio/css/') !== false) {
                    $copySource = wpc_processed_copy_source_path($sheetFile);
                    if ($copySource !== '') {
                        $sheetFile = $copySource;
                    }
                }
                wpc_crit_corpus_tuple($sheetFile, $tuples);
            }
            if (empty($tuples)) {
                return '';
            }
            ksort($tuples);
            return WPC_CRIT_CORPUS_ID_FORMAT . ':' . sha1(implode("\n", $tuples)) . ':' . count($tuples);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
if (!function_exists('wpc_processed_copy_source_trailer')) {
    /**
     * The comment a processed copy (cache/wp-cio/css) ends with, naming the sheet it was built
     * from as a path under ABSPATH. The corpus identity reads it back through
     * wpc_processed_copy_source_path(); the writer and the reader share this one format.
     *
     * It is a trailer, not a header: a comment before a sheet's `@charset` makes the browser
     * ignore that rule. '' when the source is not a plain relative path.
     */
    function wpc_processed_copy_source_trailer($sourceRelativePath)
    {
        $sourceRelativePath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) $sourceRelativePath), '/');
        if ($sourceRelativePath === '' || strpos($sourceRelativePath, '..') !== false
            || !preg_match('#^[A-Za-z0-9_./@+~-]+\.css$#', $sourceRelativePath)) {
            return '';
        }
        return "\n/*wpc-source:" . $sourceRelativePath . "*/\n";
    }
}
if (!function_exists('wpc_processed_copy_source_path')) {
    /**
     * The absolute path of the sheet a processed copy was built from, read from the copy's own
     * trailer; '' when the copy carries none (a copy written before the trailer existed) or the
     * named source is not a file. The copy's name cannot be inverted (it is an md5 of the build
     * inputs), and the style registry is not available where corpus.txt is written from a
     * saved page (the kick receiver runs in admin-ajax), so the copy has to say it.
     */
    function wpc_processed_copy_source_path($copyAbsolutePath)
    {
        if (!defined('ABSPATH') || !@is_file($copyAbsolutePath)) {
            return '';
        }
        $handle = @fopen($copyAbsolutePath, 'rb');
        if (!$handle) {
            return '';
        }
        $size = (int) @filesize($copyAbsolutePath);
        if ($size > 512) {
            @fseek($handle, -512, SEEK_END);
        }
        $tail = (string) @fread($handle, 512);
        @fclose($handle);
        if (!preg_match('#/\*wpc-source:([A-Za-z0-9_./@+~-]+\.css)\*/\s*$#', $tail, $sourceMatch)
            || strpos($sourceMatch[1], '..') !== false) {
            return '';
        }
        $sourcePath = rtrim(ABSPATH, '/') . '/' . ltrim($sourceMatch[1], '/');
        return @is_file($sourcePath) ? $sourcePath : '';
    }
}
if (!function_exists('wpc_crit_corpus_tuple')) {
    /**
     * One `path|<sha1 of the bytes>` tuple into $tuples, keyed on the resolved path so a sheet
     * reached twice (link scan and bundle clause) counts once. A path that is not a file is
     * skipped: an href we cannot resolve is not evidence of anything, and counting it as zero
     * would make an unrelated server move read as a corpus change.
     *
     * The bytes, not the mtime: a builder regeneration or a plugin update rewrites most sheets
     * with the same bytes and a new mtime, and under the mtime tuple every such page read as
     * drift and regenerated once for nothing (Denis, 2026-10-01). The hash is remembered per
     * (path, mtime, size) by wpc_crit_sheet_hash(), so a sheet is read once per change, ever.
     */
    function wpc_crit_corpus_tuple($absolutePath, &$tuples)
    {
        if (isset($tuples[$absolutePath]) || !@is_file($absolutePath)) {
            return;
        }
        $hash = wpc_crit_sheet_hash($absolutePath);
        if ($hash === '') {
            return;
        }
        $tuples[$absolutePath] = $absolutePath . '|' . $hash;
    }
}
if (!function_exists('wpc_crit_sheet_hash')) {
    /**
     * sha1 of a stylesheet's bytes, remembered under its (mtime, size) so the file is read only
     * when it changed. The memory is the option `wpc_crit_sheet_hashes` (path => "mtime|size|sha1",
     * not autoloaded, at most WPC_CRIT_SHEET_HASHES_MAX entries, the oldest dropped first) and a
     * per-request copy; it is written once per request, and only when a sheet was read.
     * '' when the file cannot be read.
     */
    function wpc_crit_sheet_hash($absolutePath)
    {
        static $known = null, $dirty = false, $registered = false;
        $mtime = (int) @filemtime($absolutePath);
        $size = (int) @filesize($absolutePath);
        if ($known === null) {
            $known = function_exists('get_option') ? get_option('wpc_crit_sheet_hashes', []) : [];
            if (!is_array($known)) {
                $known = [];
            }
        }
        $key = str_replace(DIRECTORY_SEPARATOR, '/', (string) $absolutePath);
        if (isset($known[$key]) && is_string($known[$key])) {
            $parts = explode('|', $known[$key], 3);
            if (count($parts) === 3 && (int) $parts[0] === $mtime && (int) $parts[1] === $size && $parts[2] !== '') {
                return $parts[2];
            }
        }
        $hash = @sha1_file($absolutePath);
        if (!is_string($hash) || $hash === '') {
            return '';
        }
        unset($known[$key]);
        $known[$key] = $mtime . '|' . $size . '|' . $hash;
        $max = defined('WPC_CRIT_SHEET_HASHES_MAX') ? (int) WPC_CRIT_SHEET_HASHES_MAX : 600;
        while (count($known) > $max) {
            reset($known);
            unset($known[key($known)]);
        }
        $dirty = true;
        if (!$registered && function_exists('update_option')) {
            $registered = true;
            $save = function () use (&$known, &$dirty) {
                if ($dirty) {
                    $dirty = false;
                    update_option('wpc_crit_sheet_hashes', $known, false);
                }
            };
            if (function_exists('add_action')) {
                add_action('shutdown', $save, 0);
            } else {
                $save();
            }
        }
        return $hash;
    }
}
if (!function_exists('wpc_crit_corpus_device')) {
    /** The device class whose stylesheet set this render sees. The same two names the crit
     *  artifacts and the budget stamps use, so one vocabulary covers all three. */
    function wpc_crit_corpus_device()
    {
        return (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
    }
}
if (!function_exists('wpc_crit_push_params')) {
    /**
     * The query parameters that select the crit push RENDER (?criticalCombine=true
     * &testCompliant=true). They are no part of the page's identity: nothing may key on them, and
     * the dispatch door strips them from the URL it sends (wpc_gen_dispatch_url).
     */
    function wpc_crit_push_params()
    {
        return ['criticalcombine', 'testcompliant'];
    }
}
if (!function_exists('wpc_crit_push_url_key')) {
    /**
     * The URL key of the PAGE a crit push render is rendering.
     *
     * The push is fetched at `<page url>?criticalCombine=true[&testCompliant=true]`, and
     * wps_ic_url_key::setup() keeps every parameter it does not know about (its strip list is
     * tracking params plus a handful of debug ones). Keyed as it arrives, the loopback render's
     * own key reads `<page key>criticalcombine-true_testcompliant-true` — a directory that has
     * never existed — while initCritical stamped uuid.txt and dispatch_ts.txt under the page's
     * key. The envelope's corpus.txt was therefore written nowhere and every land answered
     * 'absent'.
     *
     * Strip the push's own parameters and nothing else — a page whose real URL carries a query
     * still keys the way initCritical keyed it — then key what is left.
     *
     * $pageUrl is a page URL to key. Empty means key THIS request, which is all the render itself
     * has.
     */
    function wpc_crit_push_url_key($pageUrl = '')
    {
        try {
            if (!class_exists('wps_ic_url_key')) {
                return '';
            }
            $pageUrl = (string) $pageUrl;
            if ($pageUrl === '' && method_exists('wps_ic_url_key', 'requestUrl')) {
                $pageUrl = (string) wps_ic_url_key::requestUrl();
            }
            if ($pageUrl === '') {
                return '';
            }
            $urlParts = explode('?', $pageUrl, 2);
            $query = isset($urlParts[1]) ? (string) $urlParts[1] : '';
            if ($query !== '') {
                $queryArgs = [];
                parse_str($query, $queryArgs);
                foreach (array_keys($queryArgs) as $name) {
                    if (in_array(strtolower((string) $name), wpc_crit_push_params(), true)) {
                        unset($queryArgs[$name]);
                    }
                }
                $query = http_build_query($queryArgs);
            }
            $pageUrl = $urlParts[0] . ($query !== '' ? '?' . $query : '');

            return ltrim((string) (new wps_ic_url_key())->setup($pageUrl), '/');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
if (!function_exists('wpc_saved_page_read')) {
    /*
     * THE SAVED PAGE: the page as anonymous visitors are served it, captured before the pipeline
     * rewrites it (the pristine buffer), kept per device in the page's crit folder as
     * page.html_gzip and page_mobile.html_gzip. A crit dispatch sends it as html (html_mobile when
     * the mobile one differs), and no dispatch leaves without it.
     *
     * A render writes it only when it asks for crit (it fires a kick: the page has none, or its
     * crit is stale), and only for an anonymous GET of the page's clean URL. WPC's own purges and
     * crit lands keep it. A change to WordPress's output retires every saved page
     * (wpc_saved_pages_retire: a post published, updated or unpublished, a site change, an
     * update), and one older than 7 days is not sent (filter wpc_saved_page_max_age).
     */

    /** The saved page's file for $urlKey and one device. */
    function wpc_saved_page_file($urlKey, $mobile = false)
    {
        return rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/' . ($mobile ? 'page_mobile' : 'page') . '.html_gzip';
    }

    /** Whether a saved page written at $mtime may still be sent. */
    function wpc_saved_page_current($mtime)
    {
        $mtime = (int) $mtime;
        $maxAge = (int) apply_filters('wpc_saved_page_max_age', 7 * 86400);
        return $mtime > 0 && $mtime > (int) get_option('wpc_saved_pages_retired_at', 0)
            && ($maxAge <= 0 || time() - $mtime <= $maxAge);
    }

    /** $urlKey's saved page for one device, or '' when there is none that may be sent. */
    function wpc_saved_page_read($urlKey, $mobile = false)
    {
        try {
            $urlKey = ltrim((string) $urlKey, '/');
            if ($urlKey === '' || strpos($urlKey, '..') !== false || !defined('WPS_IC_CRITICAL') || !function_exists('gzdecode')) {
                return '';
            }
            $file = wpc_saved_page_file($urlKey, $mobile);
            if (!wpc_saved_page_current((int) @filemtime($file))) {
                return '';
            }
            $html = (string) @gzdecode((string) @file_get_contents($file));
            if (stripos($html, '<body') === false || stripos($html, '</html>') === false) {
                return '';
            }
            return $html;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Keep this render's page as $urlKey's saved page, for the visitor's device. Only a render of
     * that page, an anonymous GET of its clean URL under the site's own host that no personal or
     * excluded cookie shapes and that WordPress lets be cached (DONOTCACHEPAGE), and at most once a
     * minute per device. The
     * template key is kept beside it when the page has none. Receipt: page-saved. Returns true
     * when it was written.
     */
    function wpc_saved_page_keep($urlKey)
    {
        try {
            $urlKey = ltrim((string) $urlKey, '/');
            $html = isset($GLOBALS['wpc_pristine_buffer_html']) ? $GLOBALS['wpc_pristine_buffer_html'] : '';
            if ($urlKey === '' || strpos($urlKey, '..') !== false || !is_string($html) || strlen($html) < 1024
                || stripos($html, '<body') === false || stripos($html, '</html>') === false
                || strlen($html) > (int) apply_filters('wpc_push_corpus_cap', 8388608)
                || !defined('WPS_IC_CRITICAL') || !function_exists('gzencode') || !class_exists('wps_ic_url_key')) {
                return false;
            }
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
            if ($method !== 'GET' || (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE)
                || (function_exists('wpc_request_host_is_home') && !wpc_request_host_is_home())
                || (function_exists('is_user_logged_in') && is_user_logged_in())
                || (function_exists('wpc_pipeline_admission_ok') && !wpc_pipeline_admission_ok())
                || ltrim((string) (new wps_ic_url_key())->setup(''), '/') !== $urlKey) {
                return false;
            }
            if (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'wpc_matched_personal_cookie')
                && wps_cacheHtml::wpc_matched_personal_cookie() !== '') {
                return false;
            }
            if (defined('WPC_EXCLUDE_COOKIES') && is_array(WPC_EXCLUDE_COOKIES)) {
                foreach (array_keys((array) $_COOKIE) as $cookieName) {
                    foreach (WPC_EXCLUDE_COOKIES as $excludedCookie) {
                        $matches = substr((string) $excludedCookie, -1) === '_'
                            ? stripos((string) $cookieName, (string) $excludedCookie) === 0
                            : strcasecmp((string) $cookieName, (string) $excludedCookie) === 0;
                        if ($matches) {
                            return false;
                        }
                    }
                }
            }
            $mobile = function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile();
            $file = wpc_saved_page_file($urlKey, $mobile);
            $written = (int) @filemtime($file);
            if ($written > 0 && time() - $written < 60 && wpc_saved_page_current($written)) {
                return false;
            }
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                return false;
            }
            $tmp = $file . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 8);
            if (@file_put_contents($tmp, gzencode($html, 6)) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            if (!@is_file($dir . '/tpl.txt') && function_exists('wpc_compute_tpl_key') && function_exists('wpc_crit_meta_write')) {
                $tplKey = trim((string) wpc_compute_tpl_key());
                if ($tplKey !== '' && (!function_exists('wpc_used_css_key_valid') || wpc_used_css_key_valid($tplKey))) {
                    wpc_crit_meta_write($dir . '/tpl.txt', $tplKey);
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('page-saved', $urlKey, '', ['device' => $mobile ? 'mobile' : 'desktop', 'bytes' => strlen($html)]);
            }
            // The page has a saved copy again: the dispatch door's no-page mark goes with it.
            @unlink(wpc_saved_page_missing_file($urlKey));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A change to WordPress's output: no saved page written before now is sent. */
    function wpc_saved_pages_retire($why)
    {
        if (!function_exists('update_option')) {
            return;
        }
        update_option('wpc_saved_pages_retired_at', time(), false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('saved-pages-retired', '', '', ['why' => substr((string) $why, 0, 32)]);
        }
    }

    /** A post of a public type or a template (wps_ic_cache::wpc_template_post_types) published, updated while published, or unpublished. */
    function wpc_saved_pages_on_post_status($newStatus, $oldStatus, $post)
    {
        if (($newStatus !== 'publish' && $oldStatus !== 'publish') || !is_object($post) || !isset($post->post_type)) {
            return;
        }
        $template = class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'wpc_template_post_types')
            && in_array((string) $post->post_type, (array) wps_ic_cache::wpc_template_post_types(), true);
        if (!$template && function_exists('is_post_type_viewable') && !is_post_type_viewable($post->post_type)) {
            return;
        }
        wpc_saved_pages_retire('post-' . $newStatus);
    }

    /**
     * A dispatch for $urlKey found no saved page: the page's next visit is made to render it. Its
     * stored copy is stale-marked (the visit is served that copy and re-renders the page behind it)
     * and a page with crit is stale-marked too, so that render asks for crit and saves the page.
     * At most once per page per 10 minutes. Receipt: page-wanted.
     */
    function wpc_saved_page_wanted($urlKey)
    {
        try {
            $urlKey = ltrim((string) $urlKey, '/');
            if ($urlKey === '' || strpos($urlKey, '..') !== false
                || (function_exists('get_transient') && get_transient('wpc_page_wanted_' . md5($urlKey)))) {
                return;
            }
            if (function_exists('set_transient')) {
                set_transient('wpc_page_wanted_' . md5($urlKey), 1, 600);
            }
            $copies = 0;
            if (defined('WPS_IC_CACHE') && class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'wpc_stale_mark_page_dir')) {
                $copies = (int) wps_cacheHtml::wpc_stale_mark_page_dir(WPS_IC_CACHE . $urlKey);
            }
            $crit = defined('WPS_IC_CRITICAL') && @is_file(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/critical_desktop.css');
            if ($crit && function_exists('wpc_crit_invalidate')) {
                wpc_crit_invalidate($urlKey, 'no-page', 'stale');
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('page-wanted', $urlKey, '', ['copies' => $copies, 'crit' => $crit ? 1 : 0]);
            }
        } catch (\Throwable $e) {
        }
    }

    /*
     * THE NO-PAGE MARK. The dispatch door refused a dispatch for $urlKey because the page had no
     * saved copy (gen-dispatch-refused {why:no-page}, the receipt of that decision). Until a render
     * saves the page again, every kick for it can only be refused the same way, so the init
     * watchdog does not kick it and a kick schedules no backstop repull for it. Observed on
     * staging (2026-09-26/27): the watchdog re-kicked /features/ and /one-time-req/ every 2–4
     * minutes, each kick refused no-page, each backstop repull holding the site's 45 s repull
     * mutex while a real page's repull was skipped. One file per page under .kicklocks/, like the
     * kick locks; wpc_saved_page_keep() removes it when it writes the page.
     */
    function wpc_saved_page_missing_file($urlKey)
    {
        if (!defined('WPS_IC_CRITICAL') || ltrim((string) $urlKey, '/') === '') {
            return '';
        }
        return rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/np_' . md5(ltrim((string) $urlKey, '/'));
    }

    /** The dispatch door found no saved page for $urlKey: mark it until one is saved. */
    function wpc_saved_page_missing_mark($urlKey)
    {
        $file = wpc_saved_page_missing_file($urlKey);
        if ($file === '') {
            return;
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }
        @touch($file);
    }

    /** Whether the last dispatch for $urlKey was refused no-page and no page was saved since. */
    function wpc_saved_page_missing($urlKey)
    {
        $file = wpc_saved_page_missing_file($urlKey);
        return $file !== '' && @is_file($file);
    }

    if (function_exists('add_action')) {
        add_action('transition_post_status', 'wpc_saved_pages_on_post_status', 10, 3);
        add_action('activated_plugin', function () { wpc_saved_pages_retire('plugin-activated'); });
    }
}
if (!function_exists('wpc_crit_dispatch_record_read')) {
    /**
     * The dispatch record of one page: which generation this site last asked for, when, and —
     * when the service answered with a generation it already had instead — which one it served.
     * ['uuid' => minted uuid, 'at' => unix time, 'served' => uuid the answer named (optional)],
     * or [] when the page has none (never dispatched by this release).
     *
     * Written by the dispatch door alone (wpc_gen_dispatch: the stamp before the POST, the served
     * uuid after it) and never rewritten afterwards. The service adopts the uuid we mint as the
     * generation id (crit-push /generate: `uuid = clientUuid` when it is a valid v4), so the
     * minted uuid IS the id of the generation built from the corpus pushed with it.
     */
    function wpc_crit_dispatch_record_read($critDir)
    {
        $file = rtrim((string) $critDir, '/') . '/dispatch_record.json';
        $record = @is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($record) || empty($record['uuid']) || !is_string($record['uuid'])) {
            return [];
        }
        return $record;
    }
}
if (!function_exists('wpc_crit_dispatch_record_served')) {
    /**
     * Add the uuid the service answered with to the dispatch record of $mintedUuid, when the
     * answer named a generation other than the one we asked for (served debounced / unchanged /
     * converged with the pointer's crit_uuid). The record keeps its uuid and its time: the rule
     * is that a dispatch is recorded once. The ack handler used to re-stamp dispatch_ts.txt here
     * instead, which moved the dispatch past the corpus pushed with it, and the land of the
     * served generation then removed land_corpus.txt as 'stale' (greenvalleytint /services/
     * 2026-09-24 09:11:23 UTC: an 'unchanged' answer naming 319adb3d switched corpus-drift
     * detection off for the page until its next fresh generation).
     */
    function wpc_crit_dispatch_record_served($critDir, $mintedUuid, $servedUuid)
    {
        $servedUuid = preg_replace('/[^A-Za-z0-9-]/', '', (string) $servedUuid);
        $record = wpc_crit_dispatch_record_read($critDir);
        if ($servedUuid === '' || $record === [] || $record['uuid'] !== (string) $mintedUuid || $servedUuid === $record['uuid']) {
            return false;
        }
        $record['served'] = $servedUuid;
        return wpc_crit_meta_write(rtrim((string) $critDir, '/') . '/dispatch_record.json', json_encode($record));
    }
}
if (!function_exists('wpc_crit_corpus_stamp_parts')) {
    /** Split a corpus stamp `<device>|<id>[|<generation uuid>]` into its parts; [] when malformed. */
    function wpc_crit_corpus_stamp_parts($stamp)
    {
        $parts = explode('|', trim((string) $stamp), 3);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return [];
        }
        return ['device' => $parts[0], 'id' => $parts[1], 'uuid' => isset($parts[2]) ? $parts[2] : ''];
    }
}
if (!function_exists('wpc_crit_corpus_record')) {
    /**
     * Write this render's corpus id to the URL's crit dir as corpus.txt, as `<device>|<id>|<uuid>`.
     *
     * The device travels with the id because the CSS surface can be device-conditional — a
     * mobile-only enqueue, minimal-mobile-css, a theme that drops a sheet under a breakpoint —
     * while there is one corpus.txt per URL key. Without it a push rendered on one device would
     * read as a permanent drift on the other: one crit-corpus-drift and one stale mark every
     * 120 s, per URL, forever. With it, a render on the device that did not push answers
     * 'unknown', which is silence.
     *
     * Called only on PRISTINE html in the two places that hand a corpus to the service: the
     * saved page a dispatch carries (wpc_push_corpus, always the desktop copy, so $device names
     * it) and a criticalCombine render's envelope. Both run while a dispatch is being made — the
     * door writes the page's dispatch record BEFORE it builds the request — so the stamp also
     * names the generation it was pushed for, `<device>|<id>|<uuid>`, and the land pairs the two
     * by that uuid. A page with no dispatch record (a dispatch made before this release) gets
     * the two-part stamp and its land falls back to comparing times.
     */
    function wpc_crit_corpus_record($urlKey, $html, $device = '')
    {
        try {
            $urlKey = (string) $urlKey;
            if ($urlKey === '' || !defined('WPS_IC_CRITICAL')) {
                return '';
            }
            $corpusId = wpc_crit_corpus_id($html);
            if ($corpusId === '') {
                return '';
            }
            $critDir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/';
            if (!@is_dir($critDir)) {
                return '';
            }
            $stamp = ($device !== '' ? (string) $device : wpc_crit_corpus_device()) . '|' . $corpusId;
            $dispatchRecord = wpc_crit_dispatch_record_read($critDir);
            if ($dispatchRecord !== []) {
                $stamp .= '|' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $dispatchRecord['uuid']);
            }
            wpc_crit_meta_write($critDir . 'corpus.txt', $stamp);
            return $stamp;
        } catch (\Throwable $e) {
            return '';
        }
    }
}
if (!function_exists('wpc_crit_corpus_land')) {
    /**
     * Claim (or refuse) a corpus for the generation that has just landed. Returns
     * ['by' => 'uuid'|'kept'|'time'|'', 'why' => ''|'absent'|'stale'|'served'|'uuid'].
     * On a refusal land_corpus.txt is removed, so an artifact never carries a corpus from an
     * earlier one: no corpus is silence at render ('unknown'), never a drift.
     *
     * The rule: a land pairs with a corpus by the generation uuid, never by time.
     *   'uuid' — corpus.txt names the landing uuid: it was pushed with the dispatch the service
     *            adopted as this generation. It becomes land_corpus.txt, with the uuid.
     *   'kept' — land_corpus.txt already names the landing uuid: the same generation landing
     *            again (a sync or status re-land, an answer that re-served it, an in-place
     *            amendment). Its corpus was claimed at its first land and stays.
     *   refused 'served' — the landing uuid is the one the service answered our last dispatch
     *            with (debounced / unchanged / converged): a generation built from an earlier
     *            corpus that this site did not record, or recorded under a land that is gone.
     *   refused 'uuid'   — any other generation: not the one corpus.txt was pushed for.
     *   'time' — only when no uuid is known: the landing uuid is empty, or corpus.txt carries no
     *            uuid (written before the dispatch record existed). Then corpus.txt belongs to the
     *            land when it is not older than dispatch_ts.txt, as before.
     *
     * Pairing by time is what failed: the ack handler re-stamped dispatch_ts.txt after the POST
     * whenever the answer named a uuid, so the corpus pushed with the dispatch read as older
     * than the dispatch, and the land removed land_corpus.txt as 'stale' (greenvalleytint
     * /services/ 2026-09-24 09:11:23 UTC), which switched drift detection off for the page.
     */
    function wpc_crit_corpus_land($critDir, $landingUuid)
    {
        $critDir = rtrim((string) $critDir, '/') . '/';
        $landingUuid = preg_replace('/[^A-Za-z0-9-]/', '', (string) $landingUuid);
        $corpusFile = $critDir . 'corpus.txt';
        $landedFile = $critDir . 'land_corpus.txt';
        $corpusPresent = @is_file($corpusFile);
        $corpus = $corpusPresent ? wpc_crit_corpus_stamp_parts((string) @file_get_contents($corpusFile)) : [];
        $claim = function ($parts) use ($landedFile, $landingUuid) {
            wpc_crit_meta_write($landedFile, $parts['device'] . '|' . $parts['id'] . ($landingUuid !== '' ? '|' . $landingUuid : ''));
        };
        if ($landingUuid !== '') {
            if ($corpus !== [] && $corpus['uuid'] === $landingUuid) {
                $claim($corpus);
                return ['by' => 'uuid', 'why' => ''];
            }
            $landed = @is_file($landedFile) ? wpc_crit_corpus_stamp_parts((string) @file_get_contents($landedFile)) : [];
            if ($landed !== [] && $landed['uuid'] === $landingUuid) {
                return ['by' => 'kept', 'why' => ''];
            }
            if ($corpus !== [] && $corpus['uuid'] !== '') {
                @unlink($landedFile);
                $record = wpc_crit_dispatch_record_read($critDir);
                return ['by' => '', 'why' => (isset($record['served']) && $record['served'] === $landingUuid) ? 'served' : 'uuid'];
            }
        }
        $dispatchedAt = (int) @file_get_contents($critDir . 'dispatch_ts.txt');
        if ($corpus !== [] && (int) @filemtime($corpusFile) >= $dispatchedAt) {
            $claim($corpus);
            return ['by' => 'time', 'why' => ''];
        }
        @unlink($landedFile);

        return ['by' => '', 'why' => $corpusPresent ? 'stale' : 'absent'];
    }
}
if (!function_exists('wpc_crit_corpus_verdict')) {
    /**
     * Does this render's CSS surface match the one the landed artifact was built from?
     *
     * 'match'   — land_corpus.txt names this device and equals this render's id.
     * 'drift'   — same device, different id: the artifact describes CSS this page no longer
     *             serves.
     * 'unknown' — no land_corpus.txt (never landed, or a land that could not be paired with a
     *             dispatch), a stamp from the OTHER device, a stamp in the pre-device format or
     *             in an earlier id format (mtime tuples), or nothing resolved. We say nothing on
     *             it: what we cannot compare is not evidence of drift.
     *
     * Memoised per request in $GLOBALS['wpc_crit_corpus_verdict'], keyed by URL key so a request
     * that renders more than one URL cannot reuse the first answer.
     */
    function wpc_crit_corpus_verdict($urlKey, $html)
    {
        $urlKey = (string) $urlKey;
        if (isset($GLOBALS['wpc_crit_corpus_verdict'][$urlKey])) {
            return (string) $GLOBALS['wpc_crit_corpus_verdict'][$urlKey];
        }
        $verdict = 'unknown';
        try {
            $landedFile = ($urlKey !== '' && defined('WPS_IC_CRITICAL'))
                ? rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/land_corpus.txt' : '';
            $landed = ($landedFile !== '' && @is_file($landedFile)) ? trim((string) @file_get_contents($landedFile)) : '';
            // `<device>|<id>|<generation uuid>`; the uuid is for the land's pairing, the verdict
            // compares the device and the id only (a stamp without the uuid reads the same).
            $landedParts = wpc_crit_corpus_stamp_parts($landed);
            $landedId = ($landedParts !== [] && $landedParts['device'] === wpc_crit_corpus_device())
                ? $landedParts['id'] : '';
            // A stamp of another format (the mtime tuples before 7.24.60) cannot be compared
            // with this render's id: silence until the page's next land re-stamps it.
            if ($landedId !== '' && strpos($landedId, WPC_CRIT_CORPUS_ID_FORMAT . ':') !== 0) {
                $landedId = '';
            }
            $renderId = $landedId === '' ? '' : wpc_crit_corpus_id($html);
            if ($landedId !== '' && $renderId !== '') {
                $verdict = $renderId === $landedId ? 'match' : 'drift';
                $GLOBALS['wpc_crit_corpus_ids'] = ['was' => $landedId, 'now' => $renderId];
            }
        } catch (\Throwable $e) {
            $verdict = 'unknown';
        }
        if (!isset($GLOBALS['wpc_crit_corpus_verdict']) || !is_array($GLOBALS['wpc_crit_corpus_verdict'])) {
            $GLOBALS['wpc_crit_corpus_verdict'] = [];
        }
        $GLOBALS['wpc_crit_corpus_verdict'][$urlKey] = $verdict;
        return $verdict;
    }
}
if (!function_exists('wpc_crit_corpus_drift_report')) {
    /**
     * Journal a corpus drift and ask for a regeneration — once per 120 s per URL, and never
     * while a dispatch is already in flight (dispatch_ts newer than land_ts and under 15
     * minutes old): an in-flight generation IS the answer, and a second stale mark only
     * re-queues work the service is already doing. Both guards are the .52 behaviour, kept
     * because they are rate limits on an outbound request, not belts on a wrong answer.
     */
    function wpc_crit_corpus_drift_report($urlKey)
    {
        try {
            $urlKey = (string) $urlKey;
            if ($urlKey === '' || !defined('WPS_IC_CRITICAL')) {
                return false;
            }
            $critDir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/';
            $dispatchedAt = (int) @file_get_contents($critDir . 'dispatch_ts.txt');
            $landedAt = (int) @file_get_contents($critDir . 'land_ts.txt');
            if ($dispatchedAt > 0 && $dispatchedAt >= $landedAt && (time() - $dispatchedAt) < 900) {
                return false;
            }
            $reportGate = 'wpc_cssdrift52_' . md5($urlKey);
            if (function_exists('get_transient') && get_transient($reportGate)) {
                return false;
            }
            if (function_exists('set_transient')) {
                set_transient($reportGate, 1, 120);
            }
            if (function_exists('wpc_cache_first_log')) {
                $ids = isset($GLOBALS['wpc_crit_corpus_ids']) ? $GLOBALS['wpc_crit_corpus_ids'] : ['was' => '', 'now' => ''];
                wpc_cache_first_log('crit-corpus-drift', $urlKey, '', [
                    'was' => substr((string) $ids['was'], 0, 12),
                    'now' => substr((string) $ids['now'], 0, 12),
                ]);
            }
            if (function_exists('wpc_crit_invalidate')) {
                wpc_crit_invalidate($urlKey, 'corpus-drift', 'stale');
            }
            if (function_exists('wpc_kick_once_per_request')) {
                wpc_kick_once_per_request($urlKey, 'corpus-drift');
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
// v7.23.25 — ONE ANSWER TO "IS THE BACKGROUND LCP PRELOADED?". Two lanes emit that preload:
// the pin loop as id="wpc-lcp-bg-preload[-d|700]" and the lcp.json hints lane as its
// device-pinned twin id="wpc-lcp-hero-preload" — and the pin loop skips its own tag as a
// duplicate when the twin is already in the output. Checks that tested only the -bg- id
// read a correctly preloaded page as missing its preload: the saveCache gate refused to
// store every such page (every visit a full render), the admission and self-heal probes
// flagged a gap that was not there, and critBgPreload added a second preload.
if (!function_exists('wpc_lcp_bg_preloaded')) {
    // With $pictureUrl (the measured LCP image) and $device, the answer is by PICTURE, not by
    // id: whether the document preloads that image for that device under any id and any host
    // form (wps_ic_image_preload_set::preloadedPictures / imageKey). Since the preload set owns
    // every image preload the measured background can win under another writer's id, and the
    // id test refused a correctly preloaded page: sharvvi.com (2026-09-29) preloaded its hero as
    // wpc-lcp-img-preload and was refused a copy on every render. The store verdict and the
    // copy-admission hero check ask this way. Without a URL (critBgPreload's in-render skip), or
    // when the render lane is not loaded, the two lanes' ids are the only evidence there is.
    function wpc_lcp_bg_preloaded($html, $pictureUrl = null, $device = null)
    {
        if (!is_string($html)) {
            return false;
        }
        if ($pictureUrl !== null && class_exists('wps_ic_image_preload_set', false)) {
            $key = wps_ic_image_preload_set::imageKey((string) $pictureUrl);
            $preloaded = wps_ic_image_preload_set::preloadedPictures($html);
            return $key !== '' && isset($preloaded[$device === 'mobile' ? 'mobile' : 'desktop'][$key]);
        }
        return strpos($html, 'wpc-lcp-bg-preload') !== false || strpos($html, 'wpc-lcp-hero-preload') !== false;
    }
}

// ─── v7.10.619 — SITE FONTS ARE A CONSTANT, NOT AN ARTIFACT ──────────────────────
// The brand faces' source of truth is Elementor's own kit stylesheet — a plain file
// Elementor regenerates itself whenever fonts change. Seed the carrier from it
// directly (url() faces only, ~2KB) so the store exists from the first render with
// ZERO dependence on crit, combine, generation or purges; emit on every front-end
// render. Crit's base64 subsets remain a later-in-head progressive enhancement that
// wins the cascade when present.
if (!function_exists('wpc_font_carrier_seed')) {
    function wpc_font_carrier_seed()
    {
        try {
            if (!function_exists('wp_upload_dir') || !function_exists('get_option')) {
                return;
            }
            $upload_dir = wp_upload_dir(null, false);
            if (!empty($upload_dir['error']) || empty($upload_dir['basedir'])) {
                return;
            }
            $source_sheets = [];
            $kit_id = (int) get_option('elementor_active_kit', 0);
            if ($kit_id > 0) {
                $source_sheets[] = rtrim($upload_dir['basedir'], '/') . '/elementor/css/post-' . $kit_id . '.css';
            }
            $source_sheets[] = rtrim($upload_dir['basedir'], '/') . '/elementor/css/custom-fonts.css';
            $newest_mtime = 0;
            foreach ($source_sheets as $source_sheet) {
                if (@is_file($source_sheet)) {
                    $newest_mtime = max($newest_mtime, (int) @filemtime($source_sheet));
                }
            }
            if ($newest_mtime === 0) {
                return;
            }
            $carrier_path = function_exists('wpc_font_carrier_file') ? wpc_font_carrier_file() : '';
            // Stamp-and-compare: reseed only when a source outdates the store.
            if ($carrier_path === ''
                || (@is_file($carrier_path) && (int) @filemtime($carrier_path) >= $newest_mtime)) {
                return;
            }
            $face_blocks = '';
            foreach ($source_sheets as $source_sheet) {
                $sheet_css = @is_file($source_sheet) ? (string) @file_get_contents($source_sheet) : '';
                if ($sheet_css === '' || stripos($sheet_css, '@font-face') === false) {
                    continue;
                }
                if (preg_match_all('/@font-face\s*\{[^{}]*\}/is', $sheet_css, $face_matches)) {
                    foreach ($face_matches[0] as $face_block) {
                        // url() faces only — base64 subsets belong to the crit lane.
                        if (stripos($face_block, 'data:') === false) {
                            $face_blocks .= $face_block . "\n";
                        }
                    }
                }
            }
            if ($face_blocks !== '' && function_exists('wpc_font_carrier_record')) {
                wpc_font_carrier_record($face_blocks);
                @touch($carrier_path);
            }
        } catch (\Throwable $e) {
        }
    }
}


if (!function_exists('wpc_gen_apply_served_hold')) {
    function wpc_gen_apply_served_hold($urlKey, $call, $body)
    {
        try {
            if ((string) $urlKey === '' || (function_exists('is_wp_error') && is_wp_error($call))) {
                return 0;
            }
            $answerJson = json_decode((string) $body, true);
            if (!is_array($answerJson)) {
                return 0;
            }
            $served = strtolower(trim((string) (isset($answerJson['served']) ? $answerJson['served'] : (isset($answerJson['status']) ? $answerJson['status'] : ''))));
            if ($served !== 'debounced' && $served !== 'unchanged') {
                return 0;
            }
            $holdSeconds = isset($answerJson['retry_after']) ? (int) $answerJson['retry_after'] : 0;
            if ($holdSeconds <= 0 && function_exists('wp_remote_retrieve_header')) {
                $holdSeconds = (int) wp_remote_retrieve_header($call, 'retry-after');
            }
            if ($holdSeconds <= 0) {
                return 0;
            }
            $holdSeconds = min(172800, max(60, $holdSeconds));
            if (function_exists('set_transient')) {
                set_transient(WPC_GEN_SERVED_HOLD_PREFIX . md5((string) $urlKey), time() + $holdSeconds, $holdSeconds);
            }
            if ($served === 'debounced' && function_exists('wpc_pl_sched') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_crit_collect', [(string) $urlKey, 9])) {
                wpc_pl_sched(time() + $holdSeconds, 'wpc_crit_collect', [(string) $urlKey, 9]);
                if (function_exists('wpc_spawn_cron')) {
                    wpc_spawn_cron();
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('dispatch-served-hold', (string) $urlKey, '', ['served' => $served, 'ra' => $holdSeconds]);
            }
            return $holdSeconds;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
if (!function_exists('wpc_gen_served_hold_remaining')) {
    function wpc_gen_served_hold_remaining($urlKey)
    {
        if ((string) $urlKey === '' || !function_exists('get_transient')) {
            return 0;
        }
        $holdUntil = (int) get_transient(WPC_GEN_SERVED_HOLD_PREFIX . md5((string) $urlKey));
        return $holdUntil > time() ? $holdUntil - time() : 0;
    }
}

// ─── v7.10.625 — USED-CSS UNION GATE — RETIRED IN v7.10.630 ──────────────────────
// RETIRED, and the reason is the whole lesson: the gate asked "does the bundle mention
// every section?" when the only sound question is "does the bundle omit a section the
// page actually has RULES for?" — and it had no access to the page's stylesheets, so it
// could never ask that. Measured on the very page it was built for (/pricing/, staging,
// 2026-07-31): 29 sections, 25 styled by the site's own CSS (13.6KB inline + 1.01MB
// across 33 sheets), 25 mentioned in the bundle, and the set the site styles minus the
// set the bundle covers was EMPTY. The bundle was a perfect match; the 4 "missing"
// sections carry no rule anywhere on the page. The gate condemned it at 4/29 = 13.8%
// and blanked the tpl key, so /pricing/ emitted ZERO used-css links — which is exactly
// the fallback configuration (inline crit + deferred origin sheets) that produced the
// black shape-divider band it was written to prevent.
// The staleness it targeted was a SERVICE-side template-cache serving an old-shape
// artifact, fixed at the source in crit v3.152.0 with a shape fingerprint. The sound
// plugin-side successor is stamp-and-compare against that fingerprint, not a heuristic.
// Kept as a no-op so no caller can resurrect the false positive.
if (!function_exists('wpc_ucss_union_is_ok')) {
    function wpc_ucss_union_is_ok($html, $dir, $atfPath)
    {
        return true;
    }
}

// ─── v7.10.631 — PICTURE FIDELITY SCAN ───────────────────────────────────────────
// <picture> wrapping re-parents <img>, which silently unmatches every selector that
// addressed the img by its POSITION among siblings (`+`, `~`, `>` adjacent to the
// final compound). Receipted on thepttv.net: `.repeat-control .active-item +
// .img-repeat{opacity:1}` — the Repeat toggle stuck at 0.38 the moment next-gen
// wrapped the icon. Descendant-combinator rules are untouched by wrapping and are
// deliberately NOT collected here.
// The scan reads the page's OWN stylesheets (local files + inline blocks) and returns:
//   cls  — class names used as a positional final compound → mirrored onto the wrapper
//          at the fidelity pass, so those rules match again at first paint, no JS.
//   tags — positional selectors whose subject is a TYPE img (no attribute we put on a
//          <picture> can ever satisfy those), rewritten with the img compound
//          substituted by picture.wpc-picture — a sound transform because the wrapper
//          occupies the img's exact old tree position. The pic-guard inline tests them
//          in the real browser and unwraps only proven matches.
// Per-sheet results cached by (path,size,mtime) — the artifact's content is in the key.
if (!function_exists('wpc_picture_scan_css_selectors')) {

    function wpc_picture_scan_css_selectors($css)
    {
        $out = ['cls' => [], 'tags' => []];
        try {
            if (!is_string($css) || $css === '' || strlen($css) > 1572864) {
                return $out;
            }
            $css = (string) preg_replace('#/\*.*?\*/#s', ' ', $css);
            $stack = [];
            $off = 0;
            $n = strlen($css);
            $blocks = 0;
            while ($off < $n && $blocks < 6000) {
                $ob = strpos($css, '{', $off);
                $cb = strpos($css, '}', $off);
                if ($cb !== false && ($ob === false || $cb < $ob)) {
                    array_pop($stack);
                    $off = $cb + 1;
                    continue;
                }
                if ($ob === false) {
                    break;
                }
                $pre = trim(substr($css, $off, $ob - $off));
                $off = $ob + 1;
                $blocks++;
                if ($pre !== '' && $pre[0] === '@') {
                    $stack[] = (stripos($pre, '@media') === 0) ? ['m', trim(substr($pre, 6))] : ['b', ''];
                    continue;
                }
                $stack[] = ['b', ''];
                $media = '';
                foreach ($stack as $frame) {
                    if ($frame[0] === 'm' && $frame[1] !== '') {
                        $media = ($media === '') ? $frame[1] : $media . ' and ' . $frame[1];
                    }
                }
                foreach (explode(',', $pre) as $sel) {
                    $sel = trim($sel);
                    if ($sel === '' || strlen($sel) > 240 || stripos($sel, 'picture') !== false
                        || stripos($sel, ':has(') !== false) {
                        continue;
                    }
                    // flatten [attr] / (args) to same-length padding so combinator chars
                    // inside them can't lie about structure, and positions stay aligned
                    $flat = (string) preg_replace_callback('/\[[^\]]*\]|\([^)]*\)/', function ($m) {
                        return str_repeat('_', strlen($m[0]));
                    }, $sel);
                    if (strpbrk($flat, '+~>') === false) {
                        continue;
                    }
                    // final compound + the combinator that binds it
                    if (!preg_match('/([+~>\s])\s*([^\s+~>]+)\s*$/', $flat, $fm, PREG_OFFSET_CAPTURE)) {
                        continue;
                    }
                    $comb = $fm[1][0];
                    $cstart = $fm[2][1];
                    $compound = substr($sel, $cstart);
                    $isTagImg = preg_match('/^img(?![\w-])/i', $compound) === 1;
                    if ($comb !== '+' && $comb !== '~' && $comb !== '>') {
                        // descendant-bound final compound: wrapping cannot break it —
                        // UNLESS the subject is a type img and an EARLIER img token sits
                        // on a sibling combinator (img + b img is still safe; skip all).
                        continue;
                    }
                    if ($isTagImg) {
                        // classes inside the img compound must live on the wrapper too
                        if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $compound, $ccm)) {
                            foreach ($ccm[1] as $c) {
                                if (strpos($c, 'wpc') !== 0) {
                                    $out['cls'][] = $c;
                                }
                            }
                        }
                        $sub = substr($sel, 0, $cstart) . 'picture.wpc-picture' . substr($compound, 3);
                        $variants = [$sub];
                        // earlier img tokens (a wrapped sibling): all-substituted variant
                        $all = (string) preg_replace('/(?<![\w.#\'"-])img(?![\w-])/i', 'picture.wpc-picture', $sel);
                        if ($all !== $sub && strpos($all, 'picture.wpc-picture') !== false) {
                            $variants[] = $all;
                        }
                        foreach (array_slice($variants, 0, 2) as $v) {
                            // structure-only test: user-action states are false at parse
                            $v = (string) preg_replace('/:(hover|focus-within|focus-visible|focus|active)(?![\w-])/i', '', $v);
                            if ($v !== '' && strlen($v) <= 300) {
                                $out['tags'][] = ['s' => $v, 'm' => $media];
                            }
                        }
                    } elseif ($compound[0] === '.') {
                        if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $compound, $ccm)) {
                            foreach ($ccm[1] as $c) {
                                if (strpos($c, 'wpc') !== 0) {
                                    $out['cls'][] = $c;
                                }
                            }
                        }
                    }
                }
            }
            $out['cls'] = array_values(array_unique($out['cls']));
            if (count($out['cls']) > 200) {
                $out['cls'] = array_slice($out['cls'], 0, 200);
            }
            if (count($out['tags']) > 40) {
                $out['tags'] = array_slice($out['tags'], 0, 40);
            }
        } catch (\Throwable $e) {
            return ['cls' => [], 'tags' => []];
        }
        return $out;
    }

    function wpc_picture_css_local_path($url)
    {
        $path = (string) parse_url(html_entity_decode((string) $url), PHP_URL_PATH);
        if ($path === '' || substr((string) strtok($path, '?'), -4) !== '.css') {
            return '';
        }
        if (($i = strpos($path, '/wp-content/')) !== false && defined('WP_CONTENT_DIR')) {
            $f = WP_CONTENT_DIR . substr($path, $i + 11);
        } elseif (($i = strpos($path, '/wp-includes/')) !== false && defined('ABSPATH')) {
            $f = rtrim(ABSPATH, '/') . substr($path, $i);
        } else {
            return '';
        }
        if (strpos($f, '..') !== false) {
            return '';
        }
        return @is_file($f) ? $f : '';
    }

    function wpc_picture_scan_sheet($path)
    {
        static $memo = [];
        $sz = (int) @filesize($path);
        if ($sz <= 0 || $sz > 1572864) {
            return ['cls' => [], 'tags' => []];
        }
        $key = md5($path . '|' . $sz . '|' . (int) @filemtime($path));
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $store = function_exists('get_option') ? get_option('wpc_pic_scan631', []) : [];
        if (!is_array($store)) {
            $store = [];
        }
        if (isset($store[$key]) && is_array($store[$key]) && isset($store[$key]['cls'], $store[$key]['tags'])) {
            return $memo[$key] = $store[$key];
        }
        $r = wpc_picture_scan_css_selectors((string) @file_get_contents($path));
        if (function_exists('update_option')) {
            if (count($store) >= 80) {
                $store = [];
            }
            $store[$key] = $r;
            update_option('wpc_pic_scan631', $store, false);
        }
        return $memo[$key] = $r;
    }

    function wpc_picture_scan_page($html)
    {
        // v7.10.632 — memo keyed by BUFFER IDENTITY. Both ob chains can run in one
        // request; an unkeyed first-caller-wins memo let a partial buffer poison the
        // scan for the final one (live receipt: guard carried linked-sheet selectors
        // only — the inline block holding .img-repeat was never seen by the memoized
        // result, so the mirror silently stood down while the contract shipped).
        static $memo = [];
        $mk = strlen((string) $html) . ':' . md5(substr((string) $html, 0, 32768));
        if (isset($memo[$mk])) {
            return $memo[$mk];
        }
        $res = ['cls' => [], 'tags' => [], 'capped' => 0];
        try {
            if (!apply_filters('wpc_picture_fidelity', true)) {
                return $memo[$mk] = $res;
            }
            // v7.10.634 — INLINE FIRST: page-specific styles (widget templates) must
            // survive any cap ahead of generic linked-sheet classes (thepttv receipt:
            // cls=80 saturated by 48 linked sheets, img-repeat truncated out silently).
            // v7.10.633 — tag-anchored UNROLLED-LOOP regex: linear time (no lazy-dot
            // backtrack budget risk on 700KB documents) AND desync-proof (a '<style'
            // literal inside a JS string sent the strpos walk hunting a '</style>' that
            // could sit past real blocks, silently skipping them — the pre-pass buffer's
            // script content differs from any post-pass copy, which is why the miss only
            // reproduced on the server). Body pattern consumes each char exactly once.
            $style_count = preg_match_all(
                '#<style\b([^>]*)>([^<]*(?:<(?!/style)[^<]*)*)</style>#i',
                (string) $html, $style_matches, PREG_SET_ORDER
            );
            if ($style_count !== false && is_array($style_matches)) {
                $style_index = 0;
                foreach ($style_matches as $style_match) {
                    if ($style_index++ >= 200) {
                        break;
                    }
                    if (preg_match('/id=["\']wpc-/i', $style_match[1])) {
                        continue;
                    }
                    if (strlen($style_match[2]) > 262144 || strpos($style_match[2], '{') === false) {
                        continue;
                    }
                    $r = wpc_picture_scan_css_selectors($style_match[2]);
                    $res['cls'] = array_merge($res['cls'], $r['cls']);
                    foreach ($r['tags'] as $t) {
                        $res['tags'][] = $t;
                    }
                }
            }
            if (preg_match_all('/<link\b[^>]{0,600}?href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i', (string) $html, $lm)) {
                $seen = [];
                foreach (array_slice($lm[1], 0, 60) as $u) {
                    $p = wpc_picture_css_local_path($u);
                    if ($p === '' || isset($seen[$p])) {
                        continue;
                    }
                    $seen[$p] = 1;
                    $r = wpc_picture_scan_sheet($p);
                    $res['cls'] = array_merge($res['cls'], $r['cls']);
                    foreach ($r['tags'] as $t) {
                        $res['tags'][] = $t;
                    }
                }
            }
            $res['cls'] = array_values(array_unique($res['cls']));
            // no silent caps: 400 covers plugin-heavy sites (measured 80+ on thepttv from
            // linked sheets alone); saturation is FLAGGED so the field probe names it.
            $res['capped'] = 0;
            if (count($res['cls']) > 400) {
                $res['cls'] = array_slice($res['cls'], 0, 400);
                $res['capped'] = 1;
            }
            $unique_tags = [];
            foreach ($res['tags'] as $t) {
                $unique_tags[$t['s'] . '|' . $t['m']] = $t;
            }
            $res['tags'] = array_slice(array_values($unique_tags), 0, 40);
        } catch (\Throwable $e) {
            $res = ['cls' => [], 'tags' => [], 'capped' => 0];
        }
        if (count($memo) > 8) {
            $memo = [];
        }
        return $memo[$mk] = $res;
    }
}

// v7.10.642 — LIVENESS-BY-TOUCH for in-place artifact stores. File age cannot prove
// deadness when a healthy site's live artifact sits byte-stable for months, so the
// serve path refreshes mtime (at most once/day per file) and the retention sweep in
// warm.php may then collect anything untouched for 60 days. One stat + rare touch.
if (!function_exists('wpc_store_touch_if_stale')) {
    function wpc_store_touch_if_stale($path)
    {
        try {
            if (is_string($path) && $path !== '' && @is_file($path)
                && (int) @filemtime($path) < time() - 86400) {
                @touch($path);
            }
        } catch (\Throwable $e) {
        }
    }
}

// v7.10.649 — CVE-2026-18518 (unauth RCE chain, link 1: SECRET DISCLOSURE).
// The `dbg=direct&custom_server=` debug path appended the canonical api_key to the
// CDN zone name, which the output rewriter then printed into PUBLIC page HTML — and
// that same key is the HMAC secret authorizing the public bg_swap callback. Any
// unauthenticated visitor could read the key off a page and sign callbacks with it.
// The debug path survives for administrators only, and an admin's debug render is
// marked uncacheable so a key-bearing page can never be stored and served to others.
if (!function_exists('wpc_cdn_debug_is_allowed')) {
    function wpc_cdn_debug_is_allowed()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return false;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        return true;
    }
}

// v7.10.741 — ONE GATE FOR THE PERF DIAGNOSTIC. The ajax report already required a capability or
// WPC_PERF_DEBUG_TOKEN and 403'd otherwise, but three in-page emitters answered a bare
// isset($_GET['wpc_perf_debug']) and printed internal state — template key, artifact paths and
// byte sizes, census element URLs, crit age, throttle-lock state — to any anonymous visitor.
//
// The line is WHO CAN SET IT. A constant can only be set by someone with filesystem access to
// wp-config, so WPC_PERF_DEBUG stands as a deliberate server-owner opt-in. A query parameter can
// be set by anyone, so ?wpc_perf_debug must carry a capability or the shared token. Same contract
// and the SAME constant as the ajax report — one token, not two.
// ─────────────────────────────────────────────────────────────────────────────────────────────
// PERF DIAGNOSTIC — PRE-PRODUCTION ONLY. REMOVE BEFORE GENERAL RELEASE.
// Everything for this feature lives at exactly the sites below, and nothing else references them:
//   defines.php                    wpc_perf_debug_token(), wpc_perf_debug_is_allowed()
//   addons/cdn/rewriteLogic.php    2 emitters, both `if (function_exists('wpc_perf_debug_is_allowed') && …)`
//   addons/fonts/fonts.class.php   1 emitter, same shape
//   addons/cache/warm.php          wpc_perf_debug_report() + its two wp_ajax hooks
//   traits/url_key.php             'wpc_perf_debug' in controlParams()
//   templates/admin/partials/v4/optimize-advisory.php   the ghost button + .wpc-oa-bench styles
// Delete those and the feature is gone. Until then define('WPC_PERF_DEBUG_DISABLE', true) in
// wp-config turns the whole thing off without a deploy — t744 proves that kill is total.
// ─────────────────────────────────────────────────────────────────────────────────────────────
if (!function_exists('wpc_perf_debug_token')) {
    /**
     * The diagnostic token, minted on first use so a fresh install needs no wp-config edit.
     *
     * A secret an anonymous party can derive is not a secret, so this cannot be printed into a
     * page or computed from anything public — it is random, stored non-autoloaded, and read back
     * only through the admin-gated report. A wp-config constant still wins when present.
     */
    function wpc_perf_debug_token($mint = true)
    {
        if (defined('WPC_PERF_DEBUG_TOKEN') && (string) WPC_PERF_DEBUG_TOKEN !== '') {
            return (string) WPC_PERF_DEBUG_TOKEN;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $token = (string) get_option('wpc_perf_debug_token', '');
        if ($token !== '' || !$mint) {
            return $token;
        }
        if (function_exists('random_bytes')) {
            try { $token = bin2hex(random_bytes(16)); } catch (\Throwable $e) { $token = ''; }
        }
        if ($token === '' && function_exists('wp_generate_password')) {
            $token = (string) wp_generate_password(32, false, false);
        }
        if ($token === '') {
            return '';
        }
        if (function_exists('update_option')) {
            update_option('wpc_perf_debug_token', $token, false);
        }
        return $token;
    }
}
if (!function_exists('wpc_perf_debug_is_allowed')) {
    function wpc_perf_debug_is_allowed()
    {
        if (defined('WPC_PERF_DEBUG_DISABLE') && WPC_PERF_DEBUG_DISABLE) {
            return false;
        }
        if (defined('WPC_PERF_DEBUG') && WPC_PERF_DEBUG) {
            return true;
        }
        if (!isset($_GET['wpc_perf_debug'])) {
            return false;
        }
        $allowed = (function_exists('current_user_can') && current_user_can('manage_options'));
        if (!$allowed && isset($_GET['t']) && function_exists('wpc_perf_debug_token')) {
            // Never mint on an unauthenticated request: that would let anyone create the secret
            // by asking for it. Only a caller that already passed a capability check mints.
            $token = wpc_perf_debug_token(false);
            $allowed = ($token !== '' && hash_equals($token, (string) $_GET['t']));
        }
        if (!$allowed) {
            return false;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        return true;
    }
}

// v7.22.39 — RENDER NET GUARD. A visitor render does zero outbound network: every WP HTTP
// call made from plugin code while a front-end render is in flight is refused, journaled and
// queued; the queue drains after the response is released (fastcgi/litespeed). Hosts with no
// detach observe only. Kill: define WPC_RENDER_NET_GUARD_OFF or filter wpc_render_net_guard.
if (!function_exists('wpc_finish_request')) {
    function wpc_finish_request()
    {
        if (function_exists('fastcgi_finish_request')) {
            $GLOBALS['wpc_response_released'] = true;
            @fastcgi_finish_request();
            return true;
        }
        if (function_exists('litespeed_finish_request')) {
            $GLOBALS['wpc_response_released'] = true;
            try {
                while (ob_get_level() > 0) {
                    if (@ob_end_flush() === false) {
                        break;
                    }
                }
                @flush();
            } catch (\Throwable $e) {
            }
            @litespeed_finish_request();
            return true;
        }
        return false;
    }
}
if (!function_exists('wpc_render_self_started')) {
    /**
     * Which of the plugin's own fetches this front-end render is, or '' for a visitor's: a warm
     * render (X-WPC-Cache-Warm, set by the warm queue, Purge & Preload, the fixture exporter and
     * the loopbacks that carry it) or a diagnostic render (X-WPC-Diag). The plugin never asks
     * for critical CSS from a render it started: crit is demanded by real visits only (policy
     * P-W5). The kick receiver and cron carry the warm header too but are not renders, so they
     * answer ''.
     */
    function wpc_render_self_started()
    {
        if ((defined('DOING_CRON') && DOING_CRON) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return '';
        }
        foreach (['HTTP_X_WPC_CACHE_WARM' => 'warm', 'HTTP_X_WPC_DIAG' => 'diag'] as $header => $marker) {
            if (!empty($_SERVER[$header])) {
                return $marker;
            }
        }
        return '';
    }
}
if (!function_exists('wpc_render_guard_lane')) {
    function wpc_render_guard_lane($sapi = null)
    {
        if (($sapi === null ? PHP_SAPI : (string) $sapi) === 'cli' || (defined('WP_CLI') && WP_CLI)) {
            return '';
        }
        if ((defined('DOING_CRON') && DOING_CRON) || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_INSTALLING') && WP_INSTALLING)) {
            return '';
        }
        if (function_exists('is_admin') && is_admin()) {
            return '';
        }
        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return '';
        }
        $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($m !== 'GET' && $m !== 'HEAD') {
            return '';
        }
        $u = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($u !== '' && preg_match('#/(?:wp-json|wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|admin-ajax\.php)(?:/|\?|$)#', $u)) {
            return '';
        }
        return 'visitor';
    }
}
if (!function_exists('wpc_render_guard_active')) {
    function wpc_render_guard_active()
    {
        return !empty($GLOBALS['wpc_render_net_guard']) && empty($GLOBALS['wpc_response_released']);
    }
}
if (!function_exists('wpc_net_defer')) {
    function wpc_net_defer($id, $fn)
    {
        if (!isset($GLOBALS['wpc_net_defer_queue']) || !is_array($GLOBALS['wpc_net_defer_queue'])) {
            $GLOBALS['wpc_net_defer_queue'] = [];
        }
        $id = (string) $id;
        if (count($GLOBALS['wpc_net_defer_queue']) >= 16 && !isset($GLOBALS['wpc_net_defer_queue'][$id])) {
            return false;
        }
        $GLOBALS['wpc_net_defer_queue'][$id] = $fn;
        return true;
    }
}
if (!function_exists('wpc_net_defer_on_render_guard')) {
    function wpc_net_defer_on_render_guard($resp, $id, $fn)
    {
        if (!is_object($resp) || !is_a($resp, 'WP_Error') || $resp->get_error_code() !== 'wpc_render_guard') {
            return false;
        }
        wpc_net_defer($id, $fn);
        return true;
    }
}
if (!function_exists('wpc_render_guard_own_frame')) {
    function wpc_render_guard_own_frame($trace)
    {
        static $dirs = null, $foreign = null;
        if ($dirs === null) {
            $norm = function ($p) { $p = rtrim((string) $p, '/'); $r = @realpath($p); $o = [$p . '/']; if (is_string($r) && $r !== '' && $r !== $p) { $o[] = rtrim($r, '/') . '/'; } return $o; };
            $dirs = $norm(__DIR__);
            if (defined('WPS_IC_DIR') && (string) WPS_IC_DIR !== '') {
                $dirs = array_values(array_unique(array_merge($dirs, $norm(WPS_IC_DIR))));
            }
            $foreign = [];
            foreach (['WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR'] as $c) {
                if (defined($c) && (string) constant($c) !== '') {
                    $foreign = array_merge($foreign, $norm(constant($c)));
                }
            }
            if (defined('WP_CONTENT_DIR') && (string) WP_CONTENT_DIR !== '') {
                $foreign = array_merge($foreign, $norm(rtrim((string) WP_CONTENT_DIR, '/') . '/themes'));
            }
        }
        $n = is_array($trace) ? count($trace) : 0;
        for ($i = 0; $i < $n; $i++) {
            if (empty($trace[$i]['file'])) {
                continue;
            }
            $f = (string) $trace[$i]['file'];
            foreach ($dirs as $d) {
                if (strpos($f, $d) !== 0) {
                    continue;
                }
                $fn = isset($trace[$i + 1]['function']) ? (string) $trace[$i + 1]['function'] : '';
                if (($fn === '' || $fn === '{closure}') && isset($trace[$i + 2]['function'])) {
                    $fn = (string) $trace[$i + 2]['function'];
                }
                return basename($f) . ':' . (int) (isset($trace[$i]['line']) ? $trace[$i]['line'] : 0) . ' ' . $fn;
            }
            foreach ($foreign as $d) {
                if (strpos($f, $d) === 0) {
                    return '';
                }
            }
        }
        return '';
    }
}
if (!function_exists('wpc_render_guard_self_fetch')) {
    /**
     * The one exemption from the guard: a blocking fetch of our own site that a human asked for
     * on this very request and is waiting on, so its body IS the answer and there is nothing to
     * defer it past. The caller publishes the exact URL immediately before the call and clears
     * it however the call ends, so the pass covers that one call and every other outbound call
     * in the same request is still refused. The gate is a PHP static and the match is on the
     * whole URL: nothing arriving from outside — header, cookie or parameter — can set one.
     * Called with a URL it sets, called with nothing it reads.
     */
    function wpc_render_guard_self_fetch($url = null)
    {
        static $allowed = '';
        if ($url !== null) {
            $allowed = (string) $url;
        }

        return $allowed;
    }
}
if (!function_exists('wpc_render_guard_filter')) {
    function wpc_render_guard_filter($pre, $args, $url)
    {
        if ($pre !== false || !wpc_render_guard_active()) {
            return $pre;
        }
        $a = is_array($args) ? $args : [];
        if (isset($a['blocking']) && $a['blocking'] === false && isset($a['timeout']) && (float) $a['timeout'] <= 0.1) {
            return $pre;
        }
        $allowed = wpc_render_guard_self_fetch();
        if ($allowed !== '' && (string) $url === $allowed) {
            return $pre;
        }
        $own = wpc_render_guard_own_frame(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 18));
        if ($own === '') {
            return $pre;
        }
        $g = is_array($GLOBALS['wpc_render_net_guard']) ? $GLOBALS['wpc_render_net_guard'] : ['mode' => 'block', 'n' => 0, 'seen' => []];
        $g['n'] = (isset($g['n']) ? (int) $g['n'] : 0) + 1;
        $host = (string) parse_url((string) $url, PHP_URL_HOST);
        $sig = $own . '|' . $host;
        if (empty($g['seen'][$sig])) {
            $g['seen'][$sig] = 1;
            $tk = 'wpc_rg39_' . md5($sig);
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient($tk)) {
                set_transient($tk, 1, 600);
                wpc_cache_first_log($g['mode'] === 'block' ? 'render-net-blocked' : 'render-net-seen', $own, $host,
                    ['blocking' => isset($a['blocking']) ? (int) (bool) $a['blocking'] : 1, 'timeout' => isset($a['timeout']) ? (float) $a['timeout'] : 0]);
            }
        }
        $GLOBALS['wpc_render_net_guard'] = $g;
        if (isset($GLOBALS['wpc_sr_http']) && is_array($GLOBALS['wpc_sr_http'])) {
            $GLOBALS['wpc_sr_http']['blocked'] = (isset($GLOBALS['wpc_sr_http']['blocked']) ? (int) $GLOBALS['wpc_sr_http']['blocked'] : 0) + 1;
        }
        if ($g['mode'] !== 'block') {
            return $pre;
        }
        return new WP_Error('wpc_render_guard', 'WP Compress: outbound call deferred past the response');
    }
}
if (!function_exists('wpc_render_guard_arm')) {
    function wpc_render_guard_arm($sapi = null)
    {
        try {
            if (!empty($GLOBALS['wpc_render_net_guard'])) {
                return;
            }
            if (defined('WPC_RENDER_NET_GUARD_OFF') && WPC_RENDER_NET_GUARD_OFF) {
                return;
            }
            if (function_exists('apply_filters') && !apply_filters('wpc_render_net_guard', true)) {
                return;
            }
            if (wpc_render_guard_lane(is_string($sapi) ? $sapi : null) !== 'visitor') {
                return;
            }
            $mode = (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) ? 'block' : 'observe';
            if (function_exists('apply_filters')) {
                $mode = (string) apply_filters('wpc_render_net_guard_mode', $mode);
            }
            $GLOBALS['wpc_render_net_guard'] = ['mode' => $mode === 'observe' ? 'observe' : 'block', 'n' => 0, 'seen' => []];
            add_filter('pre_http_request', 'wpc_render_guard_filter', -2147483647, 3);
            add_action('shutdown', 'wpc_render_guard_release', 2);
        } catch (\Throwable $e) {
        }
    }
}
if (!function_exists('wpc_net_ceiling_filter')) {
    // v7.22.57 — PER-REQUEST OUTBOUND CEILING, every lane (the render guard covers visitor renders
    // only). A retry window that spun at ~90ms per blocking fetch made 334 identical calls in one
    // request (platformtraining, .56). No legitimate lane repeats one path more than a few dozen
    // times per request (manifest pagination ≤20, retry windows ≤30) or makes more than a few
    // hundred calls in total (a bulk batch downloads ~27 variants per image). Past the ceiling the
    // call is refused with a WP_Error the callers already handle, journaled once per request.
    function wpc_net_ceiling_filter($pre, $args, $url)
    {
        if ($pre !== false) {
            return $pre;
        }
        if (defined('WPC_NET_CEILING_OFF') && WPC_NET_CEILING_OFF) {
            return $pre;
        }
        $a = is_array($args) ? $args : [];
        if (isset($a['blocking']) && $a['blocking'] === false && isset($a['timeout']) && (float) $a['timeout'] <= 0.1) {
            return $pre;
        }
        $own = function_exists('wpc_render_guard_own_frame') ? wpc_render_guard_own_frame(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 18)) : '';
        if ($own === '') {
            return $pre;
        }
        $u = (string) $url;
        $path = (string) parse_url($u, PHP_URL_HOST) . (string) parse_url($u, PHP_URL_PATH);
        $c = (isset($GLOBALS['wpc_network_ceiling_counters']) && is_array($GLOBALS['wpc_network_ceiling_counters'])) ? $GLOBALS['wpc_network_ceiling_counters'] : ['n' => 0, 'p' => [], 'hit' => 0];
        $c['n'] = (int) $c['n'] + 1;
        $c['p'][$path] = (isset($c['p'][$path]) ? (int) $c['p'][$path] : 0) + 1;
        $same  = (int) apply_filters('wpc_net_ceiling_same_path', 120);
        $total = (int) apply_filters('wpc_net_ceiling_total', 1000);
        $over = ($same > 0 && $c['p'][$path] > $same) ? 'same-path' : (($total > 0 && $c['n'] > $total) ? 'total' : '');
        if ($over === '') {
            $GLOBALS['wpc_network_ceiling_counters'] = $c;
            return $pre;
        }
        if (empty($c['hit'])) {
            $c['hit'] = 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('net-ceiling-hit', $own, $path, ['why' => $over, 'same' => (int) $c['p'][$path], 'total' => (int) $c['n']]);
            }
            error_log('[WPC NetCeiling] ' . $over . ' frame=' . $own . ' path=' . $path . ' same=' . (int) $c['p'][$path] . ' total=' . (int) $c['n']);
        }
        $GLOBALS['wpc_network_ceiling_counters'] = $c;
        return new WP_Error('wpc_net_ceiling', 'WP Compress: per-request outbound ceiling reached (' . $over . ')');
    }
    function wpc_net_ceiling_arm()
    {
        if (!function_exists('add_filter') || (defined('WPC_NET_CEILING_OFF') && WPC_NET_CEILING_OFF)) {
            return;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_net_ceiling', true)) {
            return;
        }
        add_filter('pre_http_request', 'wpc_net_ceiling_filter', -2147483646, 3);
    }
    if (function_exists('add_action')) {
        add_action('init', 'wpc_net_ceiling_arm', -2147483646);
    }
}
if (!function_exists('wpc_render_guard_release')) {
    function wpc_render_guard_release()
    {
        if (empty($GLOBALS['wpc_render_net_guard'])) {
            return;
        }
        $g = $GLOBALS['wpc_render_net_guard'];
        $q = (isset($GLOBALS['wpc_net_defer_queue']) && is_array($GLOBALS['wpc_net_defer_queue'])) ? $GLOBALS['wpc_net_defer_queue'] : [];
        $GLOBALS['wpc_net_defer_queue'] = [];
        if (!$q) {
            $GLOBALS['wpc_render_net_guard'] = false;
            return;
        }
        $rel = wpc_finish_request();
        $GLOBALS['wpc_render_net_guard'] = false;
        if (!$rel) {
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_rg39_undrained')) {
                set_transient('wpc_rg39_undrained', 1, 600);
                wpc_cache_first_log('render-net-undrained', '', '', ['n' => count($q), 'ids' => implode(',', array_slice(array_keys($q), 0, 6))]);
            }
            return;
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        $n = 0;
        foreach ($q as $id => $fn) {
            if (++$n > 8) {
                break;
            }
            try {
                if (is_callable($fn)) {
                    call_user_func($fn);
                }
            } catch (\Throwable $e) {
            }
        }
        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_rg39_drained')) {
            set_transient('wpc_rg39_drained', 1, 600);
            wpc_cache_first_log('render-net-drained', '', '', ['n' => $n, 'blocked' => isset($g['n']) ? (int) $g['n'] : 0, 'ids' => implode(',', array_slice(array_keys($q), 0, 6))]);
        }
    }
}
if (function_exists('add_action')) {
    add_action('init', 'wpc_render_guard_arm', -2147483647);
}

// v7.10.649 — CVE-2026-18518 defence in depth. The RCE only lands where PHP executes
// inside uploads. The chain is closed above at three independent points; this closes
// the blast radius for any future write primitive. Non-destructive: an existing
// .htaccess is edited only through WP's own marker API, never clobbered, and nginx
// hosts (which ignore .htaccess) are unaffected either way.
if (!function_exists('wpc_uploads_harden')) {
    function wpc_uploads_harden()
    {
        try {
            if (!apply_filters('wpc_uploads_harden', true) || !function_exists('wp_get_upload_dir')) {
                return;
            }
            if (get_option(WPC_UPLOADS_HARDEN_VERSION_OPTION) === '7.10.649') {
                return;
            }
            $dir = wp_get_upload_dir();
            $base = isset($dir['basedir']) ? (string) $dir['basedir'] : '';
            if ($base === '' || !@is_dir($base) || !@is_writable($base)) {
                return;
            }
            $file = rtrim($base, '/') . '/.htaccess';
            $lines = [
                '<Files ~ "\.(php|php3|php4|php5|php7|php8|phtml|phar)$">',
                '  <IfModule mod_authz_core.c>',
                '    Require all denied',
                '  </IfModule>',
                '  <IfModule !mod_authz_core.c>',
                '    Order allow,deny',
                '    Deny from all',
                '  </IfModule>',
                '</Files>',
            ];
            if (!@file_exists($file)) {
                wpc_fs_put($file, "# BEGIN WP Compress Security\n" . implode("\n", $lines) . "\n# END WP Compress Security\n");
            } else {
                if (!function_exists('insert_with_markers') && defined('ABSPATH') && @is_readable(ABSPATH . 'wp-admin/includes/misc.php')) {
                    require_once ABSPATH . 'wp-admin/includes/misc.php';
                }
                if (function_exists('insert_with_markers')) {
                    @insert_with_markers($file, 'WP Compress Security', $lines);
                }
            }
            update_option(WPC_UPLOADS_HARDEN_VERSION_OPTION, '7.10.649', false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('uploads-hardened', '', '', ['exists' => @file_exists($file) ? 1 : 0]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_uploads_harden', 60);
}

if (!function_exists('wpc_device_hidden_image_set')) {
    // v7.10.717 - images inside subtrees the markup itself declares hidden on the
    // CURRENT device must never consume an eager slot, never receive promotion
    // (fetchpriority high / loading eager), and must stay lazy: display:none plus
    // native lazy means the browser never fetches them at all. Keyed per occurrence
    // with a visible-wins rule: a URL that ALSO appears outside every hidden
    // subtree is not suppressed. Fail-open: any parse doubt returns an empty set,
    // which leaves serve behavior unchanged.
    function wpc_device_hidden_image_set($html, $is_mobile)
    {
        $set = [];
        try {
            if (!is_string($html) || $html === '' || strlen($html) > 3000000) {
                return $set;
            }
            $cls = ($is_mobile ? 'elementor-hidden-(?:mobile|phone)' : 'elementor-hidden-desktop') . '|mega-sub-menu|sub-menu|elementor-nav-menu--dropdown|dropdown-menu';
            $rx  = '/<(div|section|main|article|aside|header|footer|figure|ul|li|nav)\b[^>]*class="[^"]*\b(?:' . $cls . ')\b[^"]*"[^>]*>/i';
            if (function_exists('apply_filters')) {
                $rx = (string) apply_filters('wpc_device_hidden_container_regex', $rx, $is_mobile);
            }
            if (!preg_match_all($rx, $html, $m, PREG_OFFSET_CAPTURE) || count($m[0]) > 120) {
                return $set;
            }
            $ranges = [];
            foreach ($m[0] as $k => $hit) {
                $tag = strtolower($m[1][$k][0]);
                $pos = $hit[1] + strlen($hit[0]);
                $depth = 1;
                $cap = min(strlen($html), $pos + 400000);
                $cur = $pos;
                while ($depth > 0 && $cur < $cap) {
                    $o = stripos($html, '<' . $tag, $cur);
                    $c = stripos($html, '</' . $tag, $cur);
                    if ($c === false) {
                        $depth = -1;
                        break;
                    }
                    if ($o !== false && $o < $c) {
                        $nx = $o + 1 + strlen($tag);
                        $ch = isset($html[$nx]) ? $html[$nx] : '';
                        if ($ch === ' ' || $ch === '>' || $ch === "\t" || $ch === "\n" || $ch === '/') {
                            $depth++;
                        }
                        $cur = $o + 1;
                    } else {
                        $nx = $c + 2 + strlen($tag);
                        $ch = isset($html[$nx]) ? $html[$nx] : '';
                        if ($ch === '>' || $ch === ' ' || $ch === "\t" || $ch === "\n") {
                            $depth--;
                        }
                        $cur = $c + 1;
                    }
                }
                // unbalanced subtree: drop this range only, keep the rest (fail-open)
                if ($depth === 0) {
                    $ranges[] = [$hit[1], $cur];
                }
            }
            if (empty($ranges)) {
                return $set;
            }
            $seen_hidden = [];
            $seen_visible = [];
            if (preg_match_all('/<(?:img|source)\b[^>]*>/i', $html, $im, PREG_OFFSET_CAPTURE)) {
                foreach ($im[0] as $tagHit) {
                    $at = $tagHit[1];
                    $in = false;
                    foreach ($ranges as $r) {
                        if ($at >= $r[0] && $at < $r[1]) {
                            $in = true;
                            break;
                        }
                    }
                    if (!preg_match_all('/(?:src|data-src|data-cp-src|srcset|data-srcset)="([^"]+)"/i', $tagHit[0], $am)) {
                        continue;
                    }
                    foreach ($am[1] as $val) {
                        foreach (preg_split('/\s*,\s*/', $val) as $cand) {
                            $u = trim(preg_replace('/\s+\d+(?:\.\d+)?[wx]$/', '', trim($cand)));
                            if ($u === '' || strpos($u, 'data:') === 0) {
                                continue;
                            }
                            $bn = basename((string) (parse_url($u, PHP_URL_PATH) ?: $u));
                            if ($in) {
                                $seen_hidden[$u] = 1;
                                if ($bn !== '') {
                                    $seen_hidden['b:' . $bn] = 1;
                                }
                            } else {
                                $seen_visible[$u] = 1;
                                if ($bn !== '') {
                                    $seen_visible['b:' . $bn] = 1;
                                }
                            }
                        }
                    }
                }
            }
            foreach ($seen_hidden as $k2 => $one) {
                if (!isset($seen_visible[$k2])) {
                    $set[$k2] = 1;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $set;
    }
}

if (!function_exists('wpc_device_hidden_has')) {
    function wpc_device_hidden_has($set, $url)
    {
        if (empty($set) || !is_array($set) || !is_string($url) || $url === '') {
            return false;
        }
        if (isset($set[$url])) {
            return true;
        }
        $bn = basename((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        return $bn !== '' && isset($set['b:' . $bn]);
    }
}

if (!function_exists('wpc_unzone_url')) {
    // v7.10.718 - MODE TRANSLATION AT CONSUMPTION. Service artifacts (crit css, lcp hints,
    // preload lists) bake whatever delivery-mode URLs were live at GENERATION time; when the
    // CDN lane is off they kept pointing at the zone until a full regeneration. Translate at
    // every consumption point instead: a transform URL unwraps to the original it embeds
    // after /u:, and a mirrored library path host-swaps to this site ONLY when the file
    // provably exists on local disk. Fail-open everywhere - an untouched zone URL keeps one
    // cross-origin ref; a wrong swap would mint a 404.
    function wpc_unzone_url($url)
    {
        try {
            if (!is_string($url) || $url === '' || strpos($url, 'data:') === 0) {
                return $url;
            }
            if (preg_match('#/u:(https?://.+)$#i', $url, $zone_match)) {
                $url = $zone_match[1];
            }
            $home = function_exists('home_url') ? (string) home_url() : '';
            if ($home === '') {
                return $url;
            }
            $home_host = strtolower((string) parse_url($home, PHP_URL_HOST));
            $url_host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($url_host === '' || $home_host === '') {
                return $url;
            }
            $strip_www = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
            if ($strip_www($url_host) === $strip_www($home_host)) {
                return $url;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '' || (strpos($path, '/wp-content/') !== 0 && strpos($path, '/wp-includes/') !== 0)) {
                return $url;
            }
            if (!defined('ABSPATH') || !@file_exists(rtrim(ABSPATH, '/') . $path)) {
                return $url;
            }
            $query = (string) parse_url($url, PHP_URL_QUERY);
            return rtrim($home, '/') . $path . ($query !== '' ? '?' . $query : '');
        } catch (\Throwable $e) {
            return $url;
        }
    }
}

if (!function_exists('wpc_svg_inline_data')) {
    // v7.10.718 - an eager-lane SVG under the size floor inlines as a data: URI: the fetch
    // (and on the zone, the whole second-origin chain) disappears from the graph. Existence,
    // extension, and content are proven before the swap; anything less returns empty and the
    // tag ships unchanged.
    function wpc_svg_inline_data($url)
    {
        static $memo = [];
        try {
            if (!is_string($url) || $url === '' || strpos($url, 'data:') === 0) {
                return '';
            }
            if (isset($memo[$url])) {
                return $memo[$url];
            }
            $memo[$url] = '';
            $u = function_exists('wpc_unzone_url') ? wpc_unzone_url($url) : $url;
            $wpc_home = function_exists('home_url') ? strtolower((string) parse_url((string) home_url(), PHP_URL_HOST)) : '';
            $wpc_uh = strtolower((string) parse_url($u, PHP_URL_HOST));
            $wpc_sw = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
            if ($wpc_home === '' || $wpc_uh === '' || $wpc_sw($wpc_uh) !== $wpc_sw($wpc_home)) {
                return '';
            }
            $p = (string) parse_url($u, PHP_URL_PATH);
            if (substr(strtolower($p), -4) !== '.svg'
                || (strpos($p, '/wp-content/') !== 0 && strpos($p, '/wp-includes/') !== 0)) {
                return '';
            }
            if (!defined('ABSPATH')) {
                return '';
            }
            $f = rtrim(ABSPATH, '/') . $p;
            // v7.10.721 - default cap 8192: the site-logo class of files sits just above 4KB
            // raw (flagship logo: 5,059B disk / ~3.1KB gz) and its eager fetch is both a
            // racer and a visible header re-layout (0-wide box until arrival).
            $max = 8192;
            if (function_exists('apply_filters')) {
                $max = max(0, (int) apply_filters('wpc_svg_inline_max_bytes', 8192));
            }
            if ($max < 1 || !@is_readable($f) || (int) @filesize($f) > $max) {
                return '';
            }
            $b = (string) @file_get_contents($f);
            if ($b === '' || stripos($b, '<svg') === false || stripos($b, '<script') !== false
                || stripos($b, 'onload=') !== false || stripos($b, 'javascript:') !== false) {
                return '';
            }
            $memo[$url] = 'data:image/svg+xml;base64,' . base64_encode($b);
            return $memo[$url];
        } catch (\Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('wpc_unzone_css')) {
    function wpc_unzone_css($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'url(') === false) {
                return $css;
            }
            if (function_exists('apply_filters') && !apply_filters('wpc_unzone_enabled', true)) {
                return $css;
            }
            $out = preg_replace_callback('#url\(\s*(["\']?)(https?://[^)"\']+)\1\s*\)#i', function ($m) {
                return 'url(' . $m[1] . wpc_unzone_url($m[2]) . $m[1] . ')';
            }, $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }
}

if (!function_exists('wpc_inject_before_body_close')) {
    function wpc_inject_before_body_close($html, $payload)
    {
        if (!is_string($html) || $html === '' || !is_string($payload) || $payload === '') {
            return $html;
        }
        $body_close_pos = strripos($html, '</body>');
        if ($body_close_pos === false) {
            return $html . $payload;
        }
        return substr($html, 0, $body_close_pos) . $payload . substr($html, $body_close_pos);
    }
}

if (!function_exists('wpc_legacy_levers')) {
    function wpc_legacy_levers()
    {
        return [
            'remove-srcset'             => 'Remove srcset',
            'add-image-sizes'           => 'Add Image Sizes',
            'force-natural'             => 'Force Natural URLs',
            'fold-split'                => 'Fold Split',
            'emit-src-hints-always'     => 'Always Emit Source Hints',
            'disable-trigger-dom-event' => 'Disable onLoad Event',
            'force-delay-captcha'       => 'Force Delay reCAPTCHA (test)',
            'force-delay-jquery'        => 'Force Delay jQuery (test)',
            'minimal-mobile-css'        => 'Minimal Mobile CSS (Beta)',
            'maximum-mobile'            => 'Maximum Optimization — Interaction-Only (Beta)',
        ];
    }
}

if (!function_exists('wpc_legacy_lever_active')) {
    function wpc_legacy_lever_active($key)
    {
        $levers = wpc_legacy_levers();
        if (!isset($levers[$key]) || !function_exists('get_option') || !defined('WPS_IC_SETTINGS')) {
            return false;
        }
        $settings = get_option(WPS_IC_SETTINGS);
        return is_array($settings) && !empty($settings[$key]) && (string) $settings[$key] === '1';
    }
}

if (!function_exists('wpc_legacy_lever_states')) {
    function wpc_legacy_lever_states()
    {
        $states = [];
        foreach (wpc_legacy_levers() as $lever_key => $label) {
            $states[$lever_key] = ['label' => $label, 'on' => wpc_legacy_lever_active($lever_key)];
        }
        return $states;
    }
}

// v7.21.54 — THE V3 ENGINE IS THE DOCTRINE; A STALE v2-ONLY STATE IS MIGRATED ONCE.
// dev.platformtraining: delay-js-v2='1' with delay-js-v3='0' — a pair the coupled UI door
// (.825/.927) can no longer produce (full-off zeroes both), so it is always a pre-coupling
// leftover. The legacy v2 loader is a CDN-published artifact the plugin cannot patch, so a
// v2-engine site never receives the jQuery replay gate and races foreign-deferred jQuery.
// v2 on + v3 explicitly '0' => promote to '1'. It runs once per version from the upgrade
// pass (wpc_doctrine_reconcile, called by checkPluginVersion); it ran from init on every
// request, an option read per visitor for a pair no current writer produces. Support opts a
// site out with the wpc_delay_v3_auto filter.
if (!function_exists('wpc_delay_v3_autoheal')) {
    function wpc_delay_v3_autoheal()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) {
            return;
        }
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings) || !isset($settings['delay-js-v2']) || (string) $settings['delay-js-v2'] !== '1') {
            return;
        }
        if (!isset($settings['delay-js-v3']) || (string) $settings['delay-js-v3'] !== '0') {
            return;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_delay_v3_auto', true)) {
            return;
        }
        $settings['delay-js-v3'] = '1';
        update_option(WPS_IC_SETTINGS, $settings);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('delay-v3-autoheal', '', '', array());
        }
    }
}

// THE DELAY JS EXCLUDE LIST IS ONE LIST, stored under `delay_js_v3` in `wpc-excludes`.
// It used to be two: the agency portal (and the legacy v2 engine's box) wrote `delay_js_v2`,
// the site's own Excludes popup wrote `delay_js_v3`, and the reader served their union. Adding
// worked from either box; removing did not, because the entry lived on in the other copy, and
// neither box showed what was served (acrystalglass.com, ticket 12056: `jquery` sat in both
// and removing it took two edits in two places). So: every writer lands in `delay_js_v3`, and
// a stored `delay_js_v2` is folded into it once and deleted.
//
// wpc_delay_excludes_key() maps a sub-key a caller names to the one it is stored under. Both
// excludes save paths and both getters ask it, so a portal that still sends `delay_js_v2`
// writes and reads the one list with no portal change.
if (!function_exists('wpc_delay_excludes_key')) {
    function wpc_delay_excludes_key($subset)
    {
        return ($subset === 'delay_js_v2' || $subset === 'delay_js_v3') ? 'delay_js_v3' : (string) $subset;
    }
}

// wpc_delay_excludes_fold() folds a `delay_js_v2` list into `delay_js_v3`: v3's entries first,
// then each v2 entry v3 does not already carry (compared trimmed and case-blind, the way the
// matcher compares them), then `delay_js_v2` is removed. That union is exactly what the old
// reader served, so the fold changes no site's effective list. Deleting v2 rather than keeping
// it as a backup: after the fold v3 already holds every v2 entry, so a backup restores nothing,
// and a plugin rolled back to the union reader would read a kept v2 and bring back every entry
// removed since. Returns the array unchanged when it holds no `delay_js_v2` key.
if (!function_exists('wpc_delay_excludes_fold')) {
    function wpc_delay_excludes_fold($excludes)
    {
        if (!is_array($excludes) || !array_key_exists('delay_js_v2', $excludes)) {
            return $excludes;
        }
        $folded = [];
        $seen = [];
        $sources = [
            isset($excludes['delay_js_v3']) && is_array($excludes['delay_js_v3']) ? $excludes['delay_js_v3'] : [],
            is_array($excludes['delay_js_v2']) ? $excludes['delay_js_v2'] : [],
        ];
        foreach ($sources as $list) {
            foreach ($list as $pattern) {
                $pattern = trim((string) $pattern);
                $pattern_key = strtolower($pattern);
                if ($pattern === '' || isset($seen[$pattern_key])) {
                    continue;
                }
                $seen[$pattern_key] = true;
                $folded[] = $pattern;
            }
        }
        $excludes['delay_js_v3'] = $folded;
        unset($excludes['delay_js_v2']);
        return $excludes;
    }
}

// The stored fold. It runs from init on every request (front end, cron and the comms door
// included), not only from the upgrade pass, because sites are updated by upload or auto-update
// and some never open wp-admin; one autoloaded option read per request, and nothing is written
// once the v2 key is gone, so it is idempotent. An import of an older export that still carries
// `delay_js_v2` is folded on the next request the same way.
if (!function_exists('wpc_delay_excludes_migrate')) {
    function wpc_delay_excludes_migrate()
    {
        if (!function_exists('get_option')) {
            return;
        }
        $excludes = get_option('wpc-excludes');
        if (!is_array($excludes) || !array_key_exists('delay_js_v2', $excludes)) {
            return;
        }
        $before = isset($excludes['delay_js_v3']) && is_array($excludes['delay_js_v3']) ? count($excludes['delay_js_v3']) : 0;
        $v2_count = is_array($excludes['delay_js_v2']) ? count($excludes['delay_js_v2']) : 0;
        $unfolded = $excludes;
        $excludes = wpc_delay_excludes_fold($excludes);
        // The fold changes how the list is stored, not what is served, so it must not look like a
        // configuration change: written as it stood, the first request after the update flipped the
        // delivery config version (site-wide soft purge, ?icv= rotation, homepage edge purge;
        // release review 2026-09-28). When the recorded version names the pre-fold row, it is
        // carried forward to the folded row before the write, and the flip finds nothing new. A
        // version that was already stale flips as it would have anyway.
        if (function_exists('wpc_delivery_config_signature')
            && (string) get_option('wpc_dcv') === wpc_delivery_config_signature($unfolded)) {
            update_option('wpc_dcv', wpc_delivery_config_signature($excludes), false);
        }
        update_option('wpc-excludes', $excludes);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('delay-excl-fold', '', '', array('v2' => $v2_count, 'v3_before' => $before, 'n' => count($excludes['delay_js_v3'])));
        }
    }
    if (function_exists('add_action')) {
        add_action('init', 'wpc_delay_excludes_migrate', 4);
    }
}

if (!function_exists('wpc_ucss_divi5_guard')) {
    // v7.21.321 — DIVI 5 USED-CSS GUARD (spec R-C, falknerei tpl-df72d547): Divi 5 hands one
    // template hash to every page, so the tpl-keyed used-css store holds ONE per-URL union and
    // serves it site-wide — whichever page mints first after a purge wins, every other page is
    // missing its own rules (nondeterministic post-purge traffic race). Until the store is
    // per-URL keyed or merge-on-write (R-A), used-css must not serve on Divi 5. This exact
    // belt state measured 0.0% pixel diff on 5/6 falknerei pages. Kill: wpc_ucss_divi5_guard.
    function wpc_ucss_divi5_guard()
    {
        static $is_divi5 = null;
        if ($is_divi5 === null) {
            $is_divi5 = false;
            try {
                if (function_exists('wp_get_theme') && function_exists('get_template')) {
                    $theme = wp_get_theme(get_template());
                    if ($theme && stripos((string) $theme->get('Name'), 'divi') !== false
                        && (int) $theme->get('Version') >= 5) {
                        $is_divi5 = true;
                    }
                }
            } catch (\Throwable $e) {
                $is_divi5 = false;
            }
        }
        return (bool) apply_filters('wpc_ucss_divi5_guard', $is_divi5);
    }
    if (function_exists('add_action')) {
        add_action('after_switch_theme', function () {
        try {
            if (!wpc_ucss_divi5_guard()) { return; }
            $settings = get_option(WPS_IC_SETTINGS);
            if (is_array($settings) && !empty($settings['used-css']) && $settings['used-css'] == '1'
                && function_exists('wpc_r2_rollback')) {
                wpc_r2_rollback('divi5-store-guard-themeswitch');
            }
        } catch (\Throwable $e) {
        }
        });
    }
}
// v7.22.11 — crit hand-off §2: dispatch fields. visitor classifies the request that
// TRIGGERED the dispatch (known bot UAs -> bot; no cookies and no Accept-Language ->
// bot; background lanes — cron, warm loopback, kick — -> bot; else human). views_24h is
// a transient-backed per-URL counter of human front-end views; precision is not the
// contract, magnitude is.
// v7.22.17 — NEVER EMIT A HOST WE HAVE NOT PROVEN. sproduce.com: a custom CDN CNAME
// (cdn.sproduce.com) verified once, then absent from public DNS — yet every asset kept
// being rewritten to it: the render-blocking Divi sheet failed after ~780ms on every
// visit, its styles never arrived, CDN-hosted images were broken for real users, and
// PSI still said 95 because Lighthouse only counts the failed request's time. The verdict
// below is an hourly DNS resolve of the chosen custom host (cached; zero network on the
// happy path beyond one lookup per 6h); an unresolvable host falls back to the zone name
// for this serve, and the first flip to 'down' purges HTML once so the dead host leaves
// the cached pages. Fail-open: no DNS functions, or the probe disabled, = host trusted.
if (!function_exists('wpc_cdn_cname_is_reachable')) {
    function wpc_cdn_cname_is_reachable($host)
    {
        try {
            $host = strtolower(trim((string) preg_replace('#^https?://#', '', (string) $host)));
            $host = trim((string) strtok($host, '/'));
            if ($host === '' || !apply_filters('wpc_cdn_cname_probe', true)
                || (!function_exists('dns_get_record') && !function_exists('gethostbyname'))
                || !function_exists('get_transient')) {
                return true;
            }
            $k = 'wpc_cname117_' . substr(md5($host), 0, 16);
            $v = get_transient($k);
            if ($v === 'ok') { return true; }
            if ($v === 'down') { return false; }
            $ok = false;
            if (function_exists('dns_get_record')) {
                $recs = @dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME);
                $ok = is_array($recs) && count($recs) > 0;
            }
            if (!$ok && function_exists('gethostbyname')) {
                $ip = @gethostbyname($host);
                $ok = is_string($ip) && $ip !== '' && $ip !== $host;
            }
            set_transient($k, $ok ? 'ok' : 'down', $ok ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
            if (!$ok) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('cdn-cname-unreachable', '', '', ['host' => $host]);
                }
                // the first 'down' verdict per host purges HTML once: cached pages carry the
                // dead host baked in, and the fallback only helps renders after this one
                if (function_exists('get_option') && get_option('wpc_cname117_purged') !== $host) {
                    update_option('wpc_cname117_purged', $host, false);
                    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeAllCache')) {
                        wps_ic_cache::purgeAllCache();
                    }
                }
            }
            return $ok;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('wpc_visitor_kind')) {
    function wpc_visitor_kind($urlKey = null)
    {
        try {
            // The kick receiver runs on a loopback, which reads as a background lane; when the
            // kick carries a visitor's evidence for THIS page (wpc_gen_human_evidence, set by the
            // admission per url key) the dispatch it makes was asked for by that visitor, and the
            // service's deferral of a first-ever page lifts only on a human one. Keyed to the
            // page being dispatched: a request that dispatched a second page must not lend it the
            // first page's visitor (release review, 2026-09-28). Without $urlKey only the
            // request's own headers count.
            if ($urlKey !== null && function_exists('wpc_gen_human_evidence') && wpc_gen_human_evidence($urlKey)) {
                return 'human';
            }
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
                || (function_exists('wp_doing_cron') && wp_doing_cron())
                || (defined('WP_CLI') && WP_CLI)) {
                return 'bot';
            }
            if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|headless|curl\/|python-|wget|go-http|java\/|httpclient|libwww|semrush|ahrefs|mj12|bingpreview|wp-compress/i', $ua)) {
                return 'bot';
            }
            if (empty($_COOKIE) && empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                return 'bot';
            }
            return 'human';
        } catch (\Throwable $e) {
            return 'bot';
        }
    }
}
if (!function_exists('wpc_views_24h_key')) {
    function wpc_views_24h_key($url_key)
    {
        return 'wpc_v24_' . substr(md5((string) $url_key), 0, 20);
    }
}
if (!function_exists('wpc_views_24h_bump')) {
    function wpc_views_24h_bump($url_key)
    {
        try {
            if ((string) $url_key === '' || !function_exists('get_transient')) {
                return;
            }
            $is_human = !(function_exists('wpc_visitor_kind') && wpc_visitor_kind() !== 'human');
            if (function_exists('wpc_site_views_bump')) {
                wpc_site_views_bump($is_human);
            }
            if (!$is_human) {
                return;
            }
            $k = wpc_views_24h_key($url_key);
            $n = (int) get_transient($k);
            // the window is approximate by design: a fresh key lives 24h from its first
            // human view; never re-extended, so a burst cannot pin a URL "hot" forever
            set_transient($k, $n + 1, $n > 0 ? 0 : DAY_IN_SECONDS);
        } catch (\Throwable $e) {
        }
    }
}
if (!function_exists('wpc_site_views_bump')) {
    function wpc_site_views_bump($human)
    {
        try {
            if (!function_exists('get_transient')) {
                return;
            }
            $d = gmdate('Ymd');
            foreach ($human ? ['wpc_site_v46_' . $d, 'wpc_site_h46_' . $d] : ['wpc_site_v46_' . $d] as $k) {
                $n = (int) get_transient($k);
                set_transient($k, $n + 1, $n > 0 ? 0 : 2 * DAY_IN_SECONDS);
            }
        } catch (\Throwable $e) {
        }
    }

    function wpc_site_counts()
    {
        $t = gmdate('Ymd');
        $y = gmdate('Ymd', time() - DAY_IN_SECONDS);
        return [
            'site_views_24h'  => (int) get_transient('wpc_site_v46_' . $t) + (int) get_transient('wpc_site_v46_' . $y),
            'site_humans_24h' => (int) get_transient('wpc_site_h46_' . $t) + (int) get_transient('wpc_site_h46_' . $y),
        ];
    }

    function wpc_site_counts_due_today()
    {
        $d = gmdate('Ymd');
        if ((string) get_option('wpc_site_counts_sent46', '') === $d) {
            return false;
        }
        update_option('wpc_site_counts_sent46', $d, false);
        return true;
    }

    function wpc_rum_sample_store($data)
    {
        if (!is_array($data) || !isset($data['rum_sample']) || !function_exists('update_option')) {
            return false;
        }
        $n = (int) $data['rum_sample'];
        if ($n < 1 || $n > 100000) {
            return false;
        }
        if ((int) get_option(WPC_RUM_SAMPLE_RATE_OPTION, 0) !== $n) {
            update_option(WPC_RUM_SAMPLE_RATE_OPTION, $n, false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rum-sample-landed', '', '', ['n' => $n]);
            }
        }
        return true;
    }

    function wpc_stored_rum_sample_rate()
    {
        $n = function_exists('get_option') ? (int) get_option(WPC_RUM_SAMPLE_RATE_OPTION, 0) : 0;
        return $n >= 1 ? $n : 50;
    }
}
if (!function_exists('wpc_views_24h')) {
    function wpc_views_24h($url_key)
    {
        try {
            if ((string) $url_key === '' || !function_exists('get_transient')) {
                return 0;
            }
            return (int) get_transient(wpc_views_24h_key($url_key));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_doctrine_reconcile')) {
    function wpc_ucss_invalidate()
    {
        if (!defined('WPS_IC_CRITICAL')) { return 0; }
        $deleted_count = 0;
        $crit_root = rtrim(WPS_IC_CRITICAL, '/');
        foreach ((array) @glob($crit_root . '/used-css/*') as $file) {
            if (is_file($file) && @unlink($file)) { $deleted_count++; }
        }
        foreach ((array) @glob($crit_root . '/*/used_css*_url.txt') as $file) {
            if (!@is_link(dirname($file)) && @unlink($file)) { $deleted_count++; }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('ucss-invalidate', (string) $deleted_count, '', []);
        }
        return $deleted_count;
    }

    function wpc_doctrine_reconcile($force = false)
    {
        $done_steps = [];
        if (!apply_filters('wpc_doctrine_reconcile', true)) {
            return $done_steps;
        }
        if (function_exists('wpc_delay_v3_autoheal')) {
            wpc_delay_v3_autoheal();
            $done_steps[] = 'v3-pair';
        }
        // v7.21.215 — PILLAR RIDER HEAL. A pillar toggle owns rider keys (.825/.148), but a
        // site saved before a rider existed carries the pillar ON with the rider UNSET — the
        // feature silently never arms (bestexteriorsinc: Optimize CSS on, used-css absent,
        // 40 originals restoring forever; found by the wire doctor). Heal: for each pillar
        // that is ON, set ONLY its ABSENT rider keys to the pillar value. An explicit '0'
        // is a user choice and is never touched. Runs once per version from the upgrade pass
        // (checkPluginVersion), so drift dies on update with zero clicks.
        if (function_exists('wpc_heal_pillar_riders')) {
            $healed_riders = wpc_heal_pillar_riders();
            if ($healed_riders > 0) {
                $done_steps[] = 'pillar-riders:' . $healed_riders;
            }
        }
        if (function_exists('wpc_delay_excludes_migrate')) {
            wpc_delay_excludes_migrate();
            $done_steps[] = 'delay-excl-fold';
        }
        if (function_exists('wpc_embed_facade_migrate') && wpc_embed_facade_migrate()) {
            $done_steps[] = 'embed-facade-to-iframe-lazy';
        }
        if (function_exists('wpc_reset_parked_attempts')) {
            $parked_reset = (int) wpc_reset_parked_attempts();
            if ($parked_reset > 0) {
                $done_steps[] = 'park-reset:' . $parked_reset;
            }
        }
        if (function_exists('wpc_delay_aggr_rearm')) {
            wpc_delay_aggr_rearm();
            $done_steps[] = 'aggr-rearm';
        }
        // v7.21.321 — Divi 5 store guard (spec R-C): the tpl-keyed used-css store serves one
        // page's union site-wide on Divi 5; roll used-css off + freeze R2 until R-A lands.
        if (function_exists('wpc_ucss_divi5_guard') && wpc_ucss_divi5_guard()) {
            $settings = get_option(WPS_IC_SETTINGS);
            if (is_array($settings) && !empty($settings['used-css']) && $settings['used-css'] == '1') {
                if (function_exists('wpc_r2_rollback')) {
                    wpc_r2_rollback('divi5-store-guard');
                } else {
                    $settings['used-css'] = '0';
                    update_option(WPS_IC_SETTINGS, $settings);
                    if (class_exists('wps_ic_cache_integrations')
                        && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
                        wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
                    }
                }
                $done_steps[] = 'divi5-ucss-guard';
            }
        }
        if ($force) {
            delete_option('wpc_delay_aggr_off');
            delete_option('wpc_delay_v3_bootfails');
            update_option('wpc_delay_aggr_fails', 0, false);
            delete_option('wpc_delay_v3_manifest_off');
            // v7.21.142 — the css-passthrough support lever is a kill-switch too: left on
            // (staging.wpcompress.com: 39 blocking sheets, crit verdict ok, the crit-team's
            // "what else gates parking?" question) it silently defeats the entire parking
            // lane with no surface. Force = the operator says "give me the doctrine state".
            delete_option('wpc_css_passthrough');
            delete_option('wpc_loader_probe159');
            delete_transient('wpc_loader_probe_lock159');
            $done_steps[] = 'kill-switches-cleared';
            if (function_exists('wpc_ucss_invalidate')) {
                $done_steps[] = 'ucss-invalidated:' . wpc_ucss_invalidate();
            }
            // v7.21.160 — REFRESH MARKS CRIT STALE FOR LANDING (rosariospadaro: force purge
            // cleared caches but valid-lossy artifacts kept serving, so no render ever
            // re-dispatched; the only levers were icons buried in the legacy advanced view).
            // Soft purge = stale.txt per URL store, serve-stale until each regen overwrites
            // (never-blank), then one kick dispatches the repull push-mode.
            if (function_exists('wpc_crit_invalidate') && function_exists('wpc_repull_kick_now')) {
                $stale_marked = (int) wpc_crit_invalidate('all', 'doctrine-reconcile', 'stale');
                wpc_repull_kick_now('', false, 'refresh');
                $done_steps[] = 'crit-stale-marked:' . $stale_marked;
                // v7.21.166 — the loopback kick dies on hosts whose security layer masks
                // unauthenticated admin-ajax (beucomply: external nopriv admin-ajax = 404, so
                // the plugin's own kick 404s before the receiver runs and the refresh never
                // dispatches). The operator is HERE, in-process, holding the request open —
                // dispatch the homepage gen directly through the redispatch handler, no HTTP
                // between intent and POST. The loopback still fires above as the belt for
                // hosts where it works (it also covers non-home URLs on render kicks).
                if (function_exists('do_action') && function_exists('home_url') && class_exists('wps_ic_url_key')) {
                    $home_key = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                    if ($home_key !== '') {
                        do_action('wpc_crit_redispatch', $home_key, 0);
                        $done_steps[] = 'home-gen-inline';
                    }
                }
            }
            if (class_exists('wps_ic_cache_integrations')
                && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
                wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
                $done_steps[] = 'purge-forced';
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('doctrine-reconcile', $force ? 'force' : 'auto', '', ['did' => implode(',', $done_steps)]);
        }
        return $done_steps;
    }
    // v7.21.233 — WP-EMOJI OFF THE WIRE when the delay lane governs the page. The core
    // emoji loader costs TWO lab rows (wp-emoji-release.min.js + its blob: support-test
    // worker) and every optimizer removes it; ours must too. Frontend removals only,
    // gated on the delay master so plain sites keep core behavior.
    function wpc_disable_emoji()
    {
        if (is_admin() || !apply_filters('wpc_disable_emoji', true)
            || (function_exists('wpc_request_excluded_from_plugin') && wpc_request_excluded_from_plugin() !== false)) {
            return;
        }
        $settings = function_exists('get_option') && defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        $delay_on = class_exists('wps_ic_js_delay_v3') && method_exists('wps_ic_js_delay_v3', 'wpc_delay_master_on')
            ? (bool) wps_ic_js_delay_v3::wpc_delay_master_on(is_array($settings) ? $settings : [])
            : (is_array($settings) && !empty($settings['delay-js']) && $settings['delay-js'] == '1');
        if (!$delay_on) {
            return;
        }
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        add_filter('emoji_svg_url', '__return_false');
        add_filter('wp_resource_hints', 'wpc_drop_emoji_resource_hints', 10, 2);
    }
    function wpc_drop_emoji_resource_hints($urls, $relation)
    {
        if ($relation === 'dns-prefetch' && is_array($urls)) {
            foreach ($urls as $index => $url) {
                if (is_string($url) && strpos($url, 's.w.org') !== false) {
                    unset($urls[$index]);
                }
            }
        }
        return $urls;
    }
    if (function_exists('add_action')) {
        add_action('init', 'wpc_disable_emoji', 5);
    }
    function wpc_refresh_auto_mode_handler()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden', 403);
        }
        if (!check_ajax_referer('wps_ic_nonce_action', 'nonce', false)) {
            wp_send_json_error('bad_nonce', 403);
        }
        wp_send_json_success(['did' => wpc_doctrine_reconcile(true)]);
    }
    if (function_exists('add_action')) {
        add_action('wp_ajax_wpc_refresh_auto_mode', 'wpc_refresh_auto_mode_handler');
    }
}

if (!function_exists('wpc_logged_in_debug_receipt')) {
    // v7.21.69 — LOGGED-IN RENDER RECEIPT. A logged-in render stands the pipeline down (.928),
    // which makes "is it us?" undecidable from view-source: absence of markers is silence.
    // ?wpc_li_debug=1 for a logged-in admin prints ONE comment naming what actually ran this
    // request (gate state, cache serve, key settings), so a broken logged-in page can be
    // attributed from the page itself. Admins only, param-gated, logged-out untouched.
    function wpc_logged_in_debug_receipt()
    {
        if (empty($_GET['wpc_li_debug'])
            || !function_exists('is_user_logged_in') || !is_user_logged_in()
            || !function_exists('current_user_can')
            || (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings'))) {
            return;
        }
        $s = function_exists('get_option') && defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        $s = is_array($s) ? $s : [];
        $gate = isset($GLOBALS['wpc_logged_in_gate_state']) ? (string) $GLOBALS['wpc_logged_in_gate_state'] : 'none';
        $bits = [
            'ver='   . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?'),
            'gate='  . $gate,
            'bypass_filter=' . (function_exists('apply_filters') && (bool) apply_filters('wpc_logged_in_bypass', true) ? '1' : '0'),
            'cache_serve=' . (isset($GLOBALS['wpc_cc_skip']) ? (string) $GLOBALS['wpc_cc_skip'] : 'n/a'),
            'live-cdn='      . (isset($s['live-cdn']) ? (string) $s['live-cdn'] : 'unset'),
            'used-css='      . (isset($s['used-css']) ? (string) $s['used-css'] : 'unset'),
            'crit='          . (isset($s['critical']['css']) ? (string) $s['critical']['css'] : 'unset'),
            'delay-v3='      . (isset($s['delay-js-v3']) ? (string) $s['delay-js-v3'] : 'unset'),
            'cache.advanced=' . (isset($s['cache']['advanced']) ? (string) $s['cache']['advanced'] : 'unset'),
            'replace-fonts=' . (isset($s['replace-fonts']) ? (string) $s['replace-fonts'] : 'unset'),
            'lane='          . (string) get_option('wpc_delivery_lane', 'cdn'),
            'css-passthrough=' . (string) get_option('wpc_css_passthrough', '0'),
        ];
        if (function_exists('admin_url') && function_exists('wp_create_nonce')) {
            $passthrough_next = get_option('wpc_css_passthrough', '0') === '1' ? '0' : '1';
            $bits[] = 'toggle-passthrough: ' . admin_url('admin-post.php?action=wpc_css_passthrough_toggle&on=' . $passthrough_next
                . '&_wpnonce=' . wp_create_nonce('wpc_css_passthrough_toggle'));
        }
        echo "\n<!-- wpc-li-debug " . esc_html(implode(' | ', $bits)) . " -->\n";
        // v7.21.77 — ?wpc_li_debug=2: the deep receipt. One paste answers "why is this
        // site rendering raw" — arming state, breakers, per-URL artifact presence, and
        // the journal tail — without server access. Admin-gated by the checks above.
        if ((string) $_GET['wpc_li_debug'] !== '2' && (string) $_GET['wpc_li_debug'] !== '3') {
            return;
        }
        $safe = function ($v) { return str_replace('--', '- -', esc_html((string) $v)); };
        $state_bits = [];
        foreach (['wpc_update_window_until', 'wpc_delay_aggr_off', 'wpc_delay_aggr_fails',
            'wpc_artifact_refresh_v', 'wpc_delay_v3_auto', 'delay_js_v3', 'delay_js_v2'] as $optionName) {
            $state_bits[] = $optionName . '=' . var_export(get_option($optionName, 'unset'), true);
        }
        if (function_exists('get_transient')) {
            $state_bits[] = 'mc_down=' . var_export(get_transient('wpc_mc_down'), true);
        }
        if (function_exists('wpc_v2_zone_cdn_suppressed')) {
            $state_bits[] = 'zone_suppressed=' . (wpc_v2_zone_cdn_suppressed() ? '1' : '0');
        }
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_natural_assets_reason')) {
            $state_bits[] = 'nat=' . wps_rewriteLogic::wpc_natural_assets_reason();
        }
        $url_key = '';
        if (class_exists('wps_ic_url_key')) {
            $request_url = function_exists('home_url') ? home_url(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?')) : '';
            $url_key = ltrim((string) (new wps_ic_url_key())->setup($request_url), '/');
        }
        $state_bits[] = 'urlKey=' . $url_key;
        if ($url_key !== '' && defined('WPS_IC_CRITICAL')) {
            $critDir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/';
            $crit_listing = [];
            foreach (array_slice((array) @glob($critDir . '*'), 0, 20) as $critFile) {
                $crit_listing[] = basename($critFile) . ':' . (int) @filesize($critFile);
            }
            $state_bits[] = 'critDir=' . (is_dir($critDir) ? (empty($crit_listing) ? 'EMPTY' : implode(',', $crit_listing)) : 'MISSING');
        }
        $journal_tail = '';
        if (defined('WPS_IC_CACHE')) {
            $journal_tail = function_exists('wpc_cflog_tail') ? trim(wpc_cflog_tail(8192)) : '';
            if ($journal_tail !== '') {
                $journal_tail = implode("\n", array_slice(explode("\n", $journal_tail), -30));
            }
        }
        echo "<!-- wpc-li-debug2\n" . $safe(implode(' | ', $state_bits)) . "\nJOURNAL TAIL:\n" . $safe($journal_tail) . "\n-->\n";
        // v7.21.81 — ?wpc_li_debug=3: the PAGE PROBE. Logged-in renders bypass the pipeline
        // (.928), so the receipt above can never see what the PUBLIC copy serves. This
        // fetches the site's own homepage server-side — the cached copy AND a cache-busted
        // fresh mint — and prints the forensic panel: crit present, loader, parked
        // links (et-cache / vars2-chain flagged), face carriers, declared-vs-used family
        // census, metric-fallback coverage. One paste = the whole picture. Admin-gated.
        if ((string) $_GET['wpc_li_debug'] !== '3' || !function_exists('wp_remote_get') || !function_exists('home_url')) {
            return;
        }
        $probe_page = function ($url) use ($safe) {
            $r = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false,
                'headers' => ['user-agent' => 'Mozilla/5.0 (wpc-li-debug3)']]);
            if (is_wp_error($r)) { return 'FETCH-ERR: ' . $r->get_error_message(); }
            $h = (string) wp_remote_retrieve_body($r);
            $o = [];
            $o[] = 'http=' . wp_remote_retrieve_response_code($r) . ' bytes=' . strlen($h)
                 . ' st=' . implode(';', (array) wp_remote_retrieve_header($r, 'server-timing'))
                 . ' cc=' . (string) wp_remote_retrieve_header($r, 'x-wpc-cc');
            $o[] = 'loader=' . (preg_match('/delay-v3-loader\.min\.js\?v=([0-9.]+)/', $h, $lm) ? $lm[1] : 'ABSENT');
            $o[] = 'crit=' . (strpos($h, 'wpc-critical-css') !== false ? 'yes' : 'NO')
                 . ' max-crit=' . (strpos($h, 'wpc-max-crit-served:') !== false ? 'served' : '-');
            $o[] = 'carriers: late-faces=' . (int) (strpos($h, 'wpc-late-faces') !== false)
                 . ' crit-faces=' . (int) (strpos($h, 'wpc-crit-faces') !== false)
                 . ' faces76=' . (int) (strpos($h, 'wpc-live-faces76') !== false)
                 . ' missing75=' . (int) (strpos($h, 'wpc-missing-faces75') !== false);
            $parked = [];
            if (preg_match_all('/<link\b[^>]*rel=["\x27]wpc-[a-z-]*stylesheet["\x27][^>]*href=["\x27]([^"\x27]+)["\x27]/i', $h, $pm)) {
                foreach (array_slice((array) $pm[1], 0, 10) as $pu) {
                    $bn = basename((string) parse_url(html_entity_decode($pu), PHP_URL_PATH));
                    $flag = '';
                    if (strpos($pu, '/et-cache/') !== false) { $flag .= '[ET-CACHE!]'; }
                    if (substr_count($bn, '.vars2.css') > 0) { $flag .= '[VARS2-CHAIN x' . substr_count($bn, '.vars2.css') . '!]'; }
                    $parked[] = $bn . $flag;
                }
            }
            $o[] = 'parked(' . count($parked) . '): ' . implode(', ', $parked);
            $dec = [];
            if (preg_match_all('/@font-face\s*\{[^}]*?font-family\s*:\s*["\x27]?([^;"\x27}]+)/is', $h, $dm)) {
                foreach ((array) $dm[1] as $d) { $dec[strtolower(trim($d))] = 1; }
            }
            $fb = [];
            foreach (array_keys($dec) as $d) {
                if (substr($d, -9) === ' fallback') { $fb[] = substr($d, 0, -9); unset($dec[$d]); }
            }
            $used = [];
            if (preg_match_all('/--[a-z0-9_-]*font[a-z0-9_-]*\s*:\s*["\x27]([^;"\x27}]{3,40})["\x27]/i', $h, $um)) {
                foreach ((array) $um[1] as $u) { $used[strtolower(trim($u))] = 1; }
            }
            $miss = array_keys(array_diff_key($used, $dec));
            $nofb = array_diff(array_keys($dec), $fb, ['fontawesome', 'font awesome 5 free', 'etmodules', 'phosphor', 'dashicons']);
            $o[] = 'faces: declared=' . implode(',', array_slice(array_keys($dec), 0, 8))
                 . ' | metric-fallback=' . implode(',', array_slice($fb, 0, 8))
                 . ' | UNDECLARED-USED=' . ($miss ? implode(',', $miss) : 'none')
                 . ' | NO-FALLBACK=' . ($nofb ? implode(',', array_slice($nofb, 0, 6)) : 'none');
            return implode("\n", array_map($safe, $o));
        };
        echo "<!-- wpc-li-debug3 PAGE PROBE\nCACHED COPY (" . $safe(home_url('/')) . "):\n" . $probe_page(home_url('/'))
           . "\nFRESH MINT (cache-busted):\n" . $probe_page(home_url('/?wpcdbg3=' . time())) . "\n-->\n";
    }
    if (function_exists('add_action')) {
        add_action('wp_footer', 'wpc_logged_in_debug_receipt', PHP_INT_MAX);

// v7.21.88 — CSS passthrough toggle: cap+nonce-gated (the .30 law), no nopriv. The
// li-debug receipt prints the ready-made nonce URL so an admin can flip it from
// view-source without dashboard UI.
// v7.21.145 — NO NOTICE UI FOR THE PASSTHROUGH LEVER (James). It is support
// instrumentation: only a person with this nonce URL turns it on, so the fleet never enters
// this state on its own and a customer-visible surface for it is UI creep. The support
// surfaces are the pt:1 mint token and the perf-debug report.

if (!function_exists('wpc_css_passthrough_toggle')) {
    function wpc_css_passthrough_toggle()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')
            || empty($_GET['_wpnonce']) || !wp_verify_nonce((string) $_GET['_wpnonce'], 'wpc_css_passthrough_toggle')) {
            wp_die('denied');
        }
        $passthrough_value = (isset($_GET['on']) && $_GET['on'] === '1') ? '1' : '0';
        update_option('wpc_css_passthrough', $passthrough_value, false);
        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
        }
        wp_die('wpc_css_passthrough=' . $passthrough_value . ' — caches purged. Reload the site.');
    }
    if (function_exists('add_action')) {
        add_action('admin_post_wpc_css_passthrough_toggle', 'wpc_css_passthrough_toggle');


    }
}


// v7.21.86 — FIRST-FRAME RECORDER (?wpc_ff_debug=1): the decisive instrument runs in the
// VIEWER'S browser, where the breakage lives. From parse start it samples computed
// type state per probe element every 50ms (transitions only), font events, layout
// shifts, and which carriers applied — then shows a copy button. One paste = the
// viewer's exact frames. Param-gated, read-only, nothing user-supplied is echoed.
if (!function_exists('wpc_first_frame_recorder_script')) {
    function wpc_first_frame_recorder_script()
    {
        if (empty($_GET['wpc_ff_debug']) || (function_exists('is_admin') && is_admin())) {
            return;
        }
        $plugin_version = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?';
        echo '<script id="wpc-ff-debug">(function(){if(window.top!==window)return;var T0=performance.now(),S=[],EV=[],DONE=0;'
            . 'function probe(sel){var el=document.querySelector(sel);if(!el)return null;var cs=getComputedStyle(el);var w=0;'
            . 'try{var tw=document.createTreeWalker(el,NodeFilter.SHOW_TEXT),n;while((n=tw.nextNode())){if(n.textContent.trim().length>3){var r=document.createRange();r.selectNodeContents(n);w=Math.round(r.getBoundingClientRect().width*10)/10;break;}}}catch(e){}'
            . 'return[cs.fontSize,cs.fontFamily.split(",")[0].replace(/"/g,""),cs.fontWeight,w,el.offsetWidth];}'
            . 'var SELS=["h1","h2",".et_pb_text p, p",".et_pb_button, a.rs-btn","#top-menu a, nav a"];'
            . 'function carriers(){var W="wpc-",ids=[W+"critical-css",W+"late-faces",W+"crit-faces",W+"missing-faces75"],o={};'
            . 'for(var i=0;i<ids.length;i++){o[ids[i]]=!!document.getElementById(ids[i]);}'
            . 'o["faces76"]=!!document.querySelector("."+W+"live-faces76");o["used-css"]=!!document.querySelector("#"+W+"used-css-rest,[data-"+W+"ucss],[id^="+W+"used-css]");'
            . 'var pk=0,lv=0,ls=document.querySelectorAll("link[rel]");for(var j=0;j<ls.length;j++){var rl=ls[j].getAttribute("rel")||"";if(rl.indexOf("wpc-")===0)pk++;else if(rl==="stylesheet")lv++;}'
            . 'o.parked=pk;o.live=lv;return o;}'
            . 'var iv=setInterval(function(){var t=Math.round(performance.now());var row={t:t,p:SELS.map(probe)};'
            . 'var prev=S[S.length-1];if(!prev||JSON.stringify(prev.p)!==JSON.stringify(row.p))S.push(row);'
            . 'if(t-T0>10000){clearInterval(iv);DONE=1;render();}},50);'
            . 'try{new PerformanceObserver(function(l){l.getEntries().forEach(function(e){if(!e.hadRecentInput&&e.value>0.001){var src=[];try{(e.sources||[]).forEach(function(sc){var n=sc.node;if(!n)return;var d=(n.tagName||"?")+(n.id?"#"+n.id:"")+(n.className&&n.className.baseVal===undefined?"."+String(n.className).trim().split(/\\s+/).slice(0,2).join("."):"");var pr=sc.previousRect,cr=sc.currentRect;src.push(d+"["+pr.top+","+pr.height+"->"+cr.top+","+cr.height+"]");});}catch(x){}EV.push({t:Math.round(e.startTime),ls:Math.round(e.value*1000)/1000,src:src.slice(0,4)});}});}).observe({type:"layout-shift",buffered:true});}catch(e){}'
            . 'try{document.fonts.addEventListener("loadingdone",function(e){EV.push({t:Math.round(performance.now()),fonts:e.fontfaces.map(function(f){return f.family+"/"+f.weight+"/"+f.status;}).slice(0,8)});});}catch(e){}'
            . 'function render(){var rep={ver:"' . esc_js($plugin_version) . '",url:location.pathname+location.search,ua:navigator.userAgent.slice(0,60),carriers:carriers(),frames:S,events:EV};'
            . 'var d=document.createElement("div");d.style.cssText="position:fixed;bottom:8px;right:8px;z-index:2147483647;background:#111;color:#0f0;font:11px monospace;padding:10px;max-width:420px;max-height:45vh;overflow:auto;border:1px solid #0f0;border-radius:6px";'
            . 'var btn=document.createElement("button");btn.textContent="COPY WPC FIRST-FRAME REPORT";btn.style.cssText="display:block;margin-bottom:6px;padding:6px;cursor:pointer";'
            . 'var pre=document.createElement("pre");pre.style.whiteSpace="pre-wrap";pre.textContent=JSON.stringify(rep);'
            . 'btn.onclick=function(){try{navigator.clipboard.writeText(pre.textContent);btn.textContent="COPIED — send to support";}catch(e){}};'
            . 'd.appendChild(btn);d.appendChild(pre);document.body.appendChild(d);window.__wpcFF=rep;}'
            . '})();</script>' . "\n";
    }
    if (function_exists('add_action')) {
        add_action('wp_head', 'wpc_first_frame_recorder_script', -2147483647);
    }
}

        add_action('admin_footer', 'wpc_logged_in_debug_receipt', PHP_INT_MAX);
    }
}


if (!function_exists('wpc_purge_human_save')) {
    /**
     * The save actions a person triggers, in one list: the admin-ajax actions (each one a
     * registered wp_ajax_ handler) and the agency relay's comms_action names (each one a
     * wps_ic_comms method). t1614 D25 fails when a name here is neither, so a renamed or deleted
     * handler cannot leave a dead entry behind.
     */
    function wpc_purge_human_save_actions()
    {
        return [
            'ajax' => [
                // the settings screens: the batch save and its purge request, the single toggles
                'wps_ic_ajax_v2_checkbox_batch', 'wps_ic_ajax_v2_checkbox', 'wps_ic_ajax_checkbox',
                'wps_ic_purge_after_save', 'wps_ic_settings_change', 'wpc_delivery_save',
                'wpc_legacy_lever', 'wpc_natural_force',
                // excludes and per-page settings
                'wps_ic_save_excludes_settings', 'wps_ic_save_per_page_settings',
                'wps_ic_save_page_excludes_popup', 'wps_ic_save_optimization_status',
                // mode and preset
                'wps_ic_save_mode', 'wpc_ic_set_mode', 'wpc_auto_mode_set', 'wpc_auto_mode_revert',
                'wpc_link_safe_mode', 'wps_ic_import_settings', 'wps_ic_set_default_settings',
                // cache lists and purge rules
                'wps_ic_save_cache_cookies_settings', 'wps_ic_save_cache_query_params_settings',
                'wps_ic_save_purge_hooks_settings',
                // Cloudflare and CDN switches that change the page
                'wps_ic_save_cf_cdn', 'wpc_ic_setupCF', 'wpc_ic_refreshCFConnection',
                'wpc_ic_checkCFDisconnect', 'wps_ic_cname_add', 'wps_ic_remove_cname',
            ],
            'relay' => [
                'saveSettings', 'change_setting', 'save_excludes', 'saveExcludes', 'save_mode',
                'importSettings', 'saveCacheCookies', 'saveCacheQueryParams', 'saveCFOption',
                'reEnableCDN', 'disableCDN', 'cnameAdd', 'cnameRemove', 'autoModeSet',
                'autoModeRevert', 'linkSafeMode',
            ],
        ];
    }

    /**
     * Runs $work as a person's save that the request itself cannot name: the post editor's meta
     * box, whose request is a post save, where only the box knows that a value of its own
     * changed. The scope ends with $work, whatever it throws, so every later purge in the request
     * is judged by the request again. Returns what $work returned.
     */
    function wpc_purge_as_human_save($origin, callable $work)
    {
        $previous = wpc_purge_human_save_scope();
        wpc_purge_human_save_scope((string) $origin);
        try {
            return $work();
        } finally {
            wpc_purge_human_save_scope($previous);
        }
    }

    /** The origin wpc_purge_as_human_save() is running, or ''. Set only by it. */
    function wpc_purge_human_save_scope($set = null)
    {
        static $scope = '';
        if ($set !== null) {
            $scope = (string) $set;
        }
        return $scope;
    }

    /**
     * Whether this request is a person saving a setting, and which save: '' when it is not.
     * Rule (Denis, 2026-09-28): a human settings save purges the stored HTML hard, so the person
     * who saved sees the result on the next request. Observed failure (greenvalleytint.com,
     * 2026-09-28 09:5x UTC): Denis removed a Critical CSS exclusion; the save's purge was soft,
     * so the homepage kept serving the copy stored before the save as `x-wpc-cache:
     * stale-rewarm` (desktop stored 09:50, mobile 09:34) while a `?disable_cache=` render already
     * had the change, and PageSpeed measured the stale copy.
     *
     * The request's origin decides, not the call site: a save reaches the purge through its own
     * handler, through the delivery-config flip its update_option() fires, and through
     * removeHtmlCacheFiles() / wpc_r2_purge_html_layers(), and every one of them asks
     * wpc_purge_is_soft(). The answer is computed from the request on every call and nothing
     * sticks to the request. The origins:
     *   - admin-ajax: one of the save actions (wpc_purge_human_save_actions()), by a user who may
     *     manage the plugin;
     *   - a POST to the plugin's own settings page (the main, Lite and debug-tool forms);
     *   - the agency relay: a relay save action carrying this site's API key;
     *   - the per-page meta box, only while it runs inside wpc_purge_as_human_save(), so an
     *     ordinary post save stays an automatic, soft purge.
     * Automatic triggers (cron, content saves, the update window, integrations, config sync, R2,
     * the land) never match, so they keep serving stale while they rewarm. Only the page copies
     * change mode: critical CSS is invalidated by each save exactly as before.
     */
    function wpc_purge_human_save()
    {
        $scope = wpc_purge_human_save_scope();
        if ($scope !== '') {
            return $scope;
        }
        try {
            $actions = wpc_purge_human_save_actions();
            $relay_action = isset($_POST['comms_action']) ? $_POST['comms_action'] : (isset($_GET['comms_action']) ? $_GET['comms_action'] : '');
            if (is_string($relay_action) && in_array($relay_action, $actions['relay'], true)) {
                $relay_key = isset($_POST['apikey']) ? $_POST['apikey'] : (isset($_GET['apikey']) ? $_GET['apikey'] : '');
                $site_options = function_exists('get_option') ? get_option(defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic') : [];
                $site_key = (is_array($site_options) && !empty($site_options['api_key'])) ? (string) $site_options['api_key'] : '';
                return ($site_key !== '' && is_string($relay_key) && hash_equals($site_key, $relay_key)) ? 'relay:' . $relay_action : '';
            }
            if (!function_exists('current_user_can')
                || !(current_user_can('manage_wpc_settings') || current_user_can('manage_options'))) {
                return '';
            }
            $doing_ajax = function_exists('wp_doing_ajax') ? wp_doing_ajax() : (defined('DOING_AJAX') && DOING_AJAX);
            if ($doing_ajax) {
                $action = (isset($_REQUEST['action']) && is_string($_REQUEST['action'])) ? $_REQUEST['action'] : '';
                return in_array($action, $actions['ajax'], true) ? 'ajax:' . $action : '';
            }
            $settings_slug = (class_exists('wps_ic') && !empty(wps_ic::$slug)) ? (string) wps_ic::$slug : 'wpcompress';
            if (function_exists('is_admin') && is_admin()
                && (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST')
                && isset($_GET['page']) && in_array((string) $_GET['page'], [$settings_slug, 'wpcompress'], true)) {
                return 'settings-page';
            }
        } catch (\Throwable $e) {
        }
        return '';
    }
}

// DELIVERY-CONFIG VERSION (dcv). Every writer of the config options flips the dcv, and the
// flip is the one owner of "the settings changed, every stored page is stale": a purge of every
// layer. Automatic purges are soft; Purge Cache, Remove Critical (Denis 2026-09-21) and every
// human settings save (wpc_purge_human_save(), Denis 2026-09-28) purge hard. A stale copy keeps
// serving (stale-rewarm, no-cache) until its rewarm replaces it, at most 4 serves or 24 h.
// There is no second opinion at serve time: the readers used to unlink every file older than
// dcv.txt, which turned each soft settings purge into a hard one.
if (!function_exists('wpc_builder_file_clear_hooks')) {
    /**
     * The builder hooks that mean "the generated files the stored pages link were just deleted".
     * Each is owned by one of the functions below, which purges the page copies hard after the
     * response: a copy that keeps serving links a stylesheet that answers 404 until that page
     * re-renders, because every builder writes the file back only at render. The generic purge
     * rule (wps_ic_cache::purgeHooks) skips these hooks, so the soft purge every other site
     * change takes never reaches them.
     *
     * Observed failure (toulouse.catholique.fr, 7.24.04, 2026-10-01): Beaver Builder regenerated
     * its asset cache after a plugin update and a theme edit; `fl_builder_cache_cleared` purged
     * soft, every article was served `stale-rewarm` with its old `-layout` bundle link, and a
     * 404 monitor counted 114 missing bb-plugin/cache files in a day. Deleting wp-cio by hand
     * (the hard purge) fixed it at once.
     *
     * crit: whether the hook can change the CSS a page's crit was built from. Beaver's clear
     * follows a layout or global settings change, so its crit is marked stale and keeps serving
     * until the page regenerates. Elementor and Spectra rewrite the same block CSS from stored
     * attributes, so the crit is left alone; a sheet whose bytes did change is caught per page
     * by the corpus drift check on that page's next render (wpc_crit_corpus_verdict).
     */
    function wpc_builder_file_clear_hooks()
    {
        return [
            'elementor/core/files/clear_cache' => ['callback' => 'wpc_elementor_css_cleared', 'reason' => 'elementor-css-clear', 'crit' => false],
            'fl_builder_cache_cleared'         => ['callback' => 'wpc_beaver_cache_cleared', 'reason' => 'beaver-cache-cleared', 'crit' => true],
            'uagb_delete_uag_asset_dir'        => ['callback' => 'wpc_spectra_assets_cleared', 'reason' => 'spectra-assets-cleared', 'crit' => false],
        ];
    }
}

if (!function_exists('wpc_purge_is_soft')) {
    function wpc_purge_is_soft($explicit = null)
    {
        try {
            if ($explicit === 'hard') {
                return false;
            }
            // A person saved a setting and must see it on the next request (greenvalleytint.com,
            // 2026-09-28: the save served the pre-save copy as stale-rewarm). See wpc_purge_human_save().
            if (function_exists('wpc_purge_human_save') && wpc_purge_human_save() !== '') {
                return false;
            }
            if (defined('WPC_PURGE_SERVE_STALE') && !WPC_PURGE_SERVE_STALE) {
                return false;
            }
            if (function_exists('apply_filters') && !apply_filters('wpc_purge_serve_stale', true)) {
                return false;
            }
            if (!defined('WPC_PLUGIN_VERSION') || !function_exists('get_option')
                || (string) get_option('wpc_stale43_ver', '') !== (string) WPC_PLUGIN_VERSION) {
                return false;
            }
            return defined('WPS_IC_CACHE') && @is_dir(WPS_IC_CACHE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    function wpc_mark_site_stale()
    {
        if (!defined('WPS_IC_CACHE')) {
            return false;
        }
        return wpc_fs_put(rtrim(WPS_IC_CACHE, '/') . '/wpc-stale43.txt', (string) time()) !== false;
    }

    function wpc_purge_all_should_coalesce()
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        if (defined('WPC_PURGE_COALESCE_OFF') && WPC_PURGE_COALESCE_OFF) {
            return false;
        }
        // A click (wps_ic_nonce) or a human settings save is never folded into an earlier purge:
        // a second save inside 90 s would otherwise be only a stale mark, and the person who
        // saved would see the copy stored before it (greenvalleytint.com, 2026-09-28).
        if (!empty($_REQUEST['wps_ic_nonce'])
            || (function_exists('wpc_purge_human_save') && wpc_purge_human_save() !== '')) {
            update_option('wpc_purge_all_at10', time(), false);
            return false;
        }
        $win = (int) apply_filters('wpc_purge_all_coalesce_window', 90);
        $last = (int) get_option('wpc_purge_all_at10', 0);
        $now = time();
        if ($win > 0 && $last > 0 && ($now - $last) < $win) {
            wpc_mark_site_stale();
            if (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'wpc_stale_walk_after_response')) {
                wps_cacheHtml::wpc_stale_walk_after_response();
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-local-all-coalesced', '', '', ['ago' => $now - $last, 'win' => $win]);
            }
            return true;
        }
        update_option('wpc_purge_all_at10', $now, false);
        return false;
    }

    /**
     * Runs at the end of the only two site-wide purges that delete every page copy, fresh and
     * stale: the hard branches of wps_ic_cache_integrations::purgeCacheFiles() and
     * wps_cacheHtml::removeCacheFiles('all').
     */
    function wpc_stale_hard_purge_done()
    {
        if (defined('WPS_IC_CACHE')) {
            @unlink(rtrim(WPS_IC_CACHE, '/') . '/wpc-stale43.txt');
        }
        wpc_delete_combined_bundles();
        if (defined('WPC_PLUGIN_VERSION') && function_exists('update_option')
            && (string) get_option('wpc_stale43_ver', '') !== (string) WPC_PLUGIN_VERSION) {
            update_option('wpc_stale43_ver', (string) WPC_PLUGIN_VERSION, false);
        }
    }

    /**
     * The combined parked-CSS bundles (critical/combined/cmb-<md5>.css) are linked from the stored
     * page copies, and a copy has no fallback to the sheets the bundle absorbed. Rule: a bundle
     * is deleted only in the call that deletes every copy that can link it, fresh or stale, so
     * this runs only from wpc_stale_hard_purge_done(). A serve-stale purge keeps every bundle.
     * Observed failure (rig, 2026-09-24 09:08:42 UTC): a Delay JS exclude save ran
     * purge-local-all {mode:stale} and deleted critical/combined in the same call; / was then
     * served stale-rewarm linking cmb-63bc….css, which answered 404, so everything below the
     * inlined crit was unstyled. A rewarm that is refused a store leaves such a copy serving
     * for up to 4 serves or 24 h.
     * The store stays bounded: the key is path + mtime of the absorbed sheets (no icv), so a
     * new name appears only when a source changes, and every hard purge (Purge Cache, plugin,
     * theme or core update, Elementor's CSS clear, Remove Critical) empties it.
     */
    function wpc_delete_combined_bundles()
    {
        if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_cache_integrations')) {
            return 0;
        }
        $bundleDir = rtrim(WPS_IC_CRITICAL, '/') . '/combined';
        if (!@is_dir($bundleDir)) {
            return 0;
        }
        $bundleFiles = @glob($bundleDir . '/cmb-*.css');
        $bundleCount = is_array($bundleFiles) ? count($bundleFiles) : 0;
        wps_ic_cache_integrations::removeDirectory($bundleDir);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('combined-bundles-deleted', '', '', ['n' => $bundleCount]);
        }
        return $bundleCount;
    }
}

if (!function_exists('wpc_delivery_config_signature')) {
    /**
     * The configuration a stored page was rendered under: the settings row, the excludes and the
     * CSS passthrough switch. The plugin version is not part of it: a plugin update is purged
     * hard by its own owner (wpc_upgrader_purge, and the version lane for the updates the
     * upgrader never sees). The processed stylesheet copies are not named with this signature
     * (it moves on any setting); their key is wps_cdn_rewrite::processed_copy_config_digest().
     *
     * $excludes, when given, is signed verbatim in place of the stored excludes; the Delay JS
     * list fold passes the row as it was before the fold, which is how the old signature read it.
     */
    function wpc_delivery_config_signature($excludes = null)
    {
        $configParts = [
            function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings') : null,
            $excludes !== null ? $excludes : (function_exists('get_option') ? get_option('wpc-excludes') : null),
            function_exists('get_option') ? get_option('wpc_css_passthrough') : null,
        ];
        return substr(md5(serialize($configParts)), 0, 12);
    }
}
if (!function_exists('wpc_delivery_config_version_flip')) {
    function wpc_delivery_config_version_flip()
    {
        try {
            $config_signature = wpc_delivery_config_signature();
            if ((string) get_option('wpc_dcv') === $config_signature) {
                return;
            }
            update_option('wpc_dcv', $config_signature, false);
            if (class_exists('wps_ic_cache_integrations')) {
                wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
            }
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls') && function_exists('home_url')) {
                wps_ic_cache::purgeEdgeHtmlUrls([home_url('/'), home_url()]);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('dcv-flip', $config_signature, '', []);
            }
            // The copies built under the previous configuration keep their names and are
            // collected once that configuration has been unseen for two days.
            if (function_exists('wpc_processed_copy_gc_schedule')) {
                wpc_processed_copy_gc_schedule(time() + 2 * 86400 + 600);
            }
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('add_action')) {
        add_action('update_option_' . (defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings'), 'wpc_delivery_config_version_flip', 20, 0);
        add_action('add_option_' . (defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings'), 'wpc_delivery_config_version_flip', 20, 0);
        add_action('update_option_wpc-excludes', 'wpc_delivery_config_version_flip', 20, 0);
        add_action('add_option_wpc-excludes', 'wpc_delivery_config_version_flip', 20, 0);
        add_action('update_option_wpc_css_passthrough', 'wpc_delivery_config_version_flip', 20, 0);
        add_action('add_option_wpc_css_passthrough', 'wpc_delivery_config_version_flip', 20, 0);
        add_action('delete_option_wpc_css_passthrough', 'wpc_delivery_config_version_flip', 20, 0);
    }
}

if (!function_exists('wpc_processed_copy_tag_seen')) {
    /**
     * Records that a render built or linked processed copies (cache/wp-cio/css) under the config
     * tag $tag (the last four hex of the copy name, see process_css_for_fonts). The collector
     * treats a tag seen in the last two days as current and never deletes its copies, whatever
     * their age: before, the only collector judged by write time, and a live copy a stored page
     * still linked went after 14 days. One option write per tag per six hours.
     */
    function wpc_processed_copy_tag_seen($tag)
    {
        static $recorded = [];
        $tag = strtolower((string) $tag);
        if (!preg_match('/^[a-f0-9]{4}$/', $tag) || isset($recorded[$tag]) || !function_exists('get_option')) {
            return;
        }
        $recorded[$tag] = true;
        $tags = get_option('wpc_processed_copy_tags', []);
        $tags = is_array($tags) ? $tags : [];
        $now = time();
        if (isset($tags[$tag]) && $now - (int) $tags[$tag] < 6 * 3600) {
            return;
        }
        $tags[$tag] = $now;
        foreach ($tags as $knownTag => $seenAt) {
            if ($now - (int) $seenAt > 30 * 86400) {
                unset($tags[$knownTag]);
            }
        }
        update_option('wpc_processed_copy_tags', $tags, true);
    }
}

if (!function_exists('wpc_processed_copy_gc')) {
    /**
     * The one collector of the content-addressed asset stores under cache/wp-cio (css/, js/).
     * Rule: a processed stylesheet copy whose config tag is current (seen in the last two days)
     * is never deleted; a copy whose tag is not current goes once it is older than two days; any
     * other file (js/, a name without a tag) goes once it is older than 14 days, the floor for
     * long-TTL foreign caches that still link it. At most 500 unlinks per run.
     * Failure it prevents: 7.24.33's bd1 stopped settings saves from deleting cache/wp-cio
     * wholesale and renamed copies per configuration instead, so every configuration change
     * left an orphan set that only the 14-day sweep after the next plugin update collected
     * (review of 2026-09-25: one set per save, no bound). The configuration change schedules
     * this run (wpc_delivery_config_version_flip); the post-update housekeeping calls it too.
     */
    function wpc_processed_copy_gc()
    {
        if (!defined('WPS_IC_CACHE')) {
            return;
        }
        $now = time();
        $currentWindow = 2 * 86400;
        $tags = function_exists('get_option') ? get_option('wpc_processed_copy_tags', []) : [];
        $currentTags = [];
        $nextRunAt = 0;
        foreach ((is_array($tags) ? $tags : []) as $knownTag => $seenAt) {
            if ($now - (int) $seenAt <= $currentWindow) {
                $currentTags[(string) $knownTag] = true;
                // A tag not seen for six hours may be a configuration that is gone: look again
                // when its window ends, so its copies do not wait for the next plugin update.
                if ($now - (int) $seenAt > 6 * 3600) {
                    $dueAt = (int) $seenAt + $currentWindow + 600;
                    $nextRunAt = $nextRunAt ? min($nextRunAt, $dueAt) : $dueAt;
                }
            }
        }
        $removed = 0;
        $kept = 0;
        $current = 0;
        $capped = false;
        $root = rtrim(WPS_IC_CACHE, '/');
        foreach (['css', 'js'] as $store) {
            $storeDir = $root . '/' . $store;
            if (!is_dir($storeDir)) {
                continue;
            }
            foreach (array_merge((array) @glob($storeDir . '/*'), (array) @glob($storeDir . '/*/*')) as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if ($removed >= 500) {
                    $capped = true;
                    break 2;
                }
                $floor = 14 * 86400;
                $isTaggedCopy = $store === 'css' && preg_match('/-[a-f0-9]{6}([a-f0-9]{4})\.css$/i', basename($file), $nameMatch);
                if ($isTaggedCopy) {
                    if (isset($currentTags[strtolower($nameMatch[1])])) {
                        $current++;
                        continue;
                    }
                    $floor = $currentWindow;
                }
                $modifiedAt = (int) @filemtime($file);
                if ($now - $modifiedAt > $floor && @unlink($file)) {
                    $removed++;
                    continue;
                }
                $kept++;
                if ($isTaggedCopy) {
                    $dueAt = $modifiedAt + $floor + 600;
                    $nextRunAt = $nextRunAt ? min($nextRunAt, $dueAt) : $dueAt;
                }
            }
        }
        if ($capped) {
            $nextRunAt = $now + 3600;
        }
        if ($nextRunAt > 0) {
            wpc_processed_copy_gc_schedule($nextRunAt);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('wpcio-css-gc', '', '', ['removed' => $removed, 'kept' => $kept, 'current' => $current]);
        }
    }
}

if (!function_exists('wpc_processed_copy_gc_schedule')) {
    /** One pending collector run, at the earliest time asked for. */
    function wpc_processed_copy_gc_schedule($runAt)
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        $runAt = max(time() + 60, (int) $runAt);
        $pendingAt = (int) wp_next_scheduled('wpc_processed_copy_gc');
        if ($pendingAt && $pendingAt <= $runAt) {
            return;
        }
        if ($pendingAt && function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('wpc_processed_copy_gc');
        }
        wp_schedule_single_event($runAt, 'wpc_processed_copy_gc');
    }
    if (function_exists('add_action')) {
        add_action('wpc_processed_copy_gc', 'wpc_processed_copy_gc');
    }
}


// v7.21.148 — LITE PILLAR RIDERS. The simple ("Quick Optimizations") panel does not submit
// its form: lite.js preventDefaults the Save button and posts its changes over AJAX.
// Each simple toggle is a PILLAR — a summary flag over several real keys —
// and lite_settings.php RE-DERIVES the summary from those real keys on every page load.
// A writer that stores only the bare summary key therefore (a) changes nothing about how
// the site is optimised and (b) gets its own write undone on the next refresh: untick
// Images, save, refresh, it is on again — because generate_webp/retina/generate_adaptive
// were never lowered. The expansion had been hand-rolled in two places (.825 in the AJAX
// writer, .927 in the POST path) and drifted twice; it lives here once now, and both
// writers call it. Pure array in / array out — no WP, no side effects.
if (!function_exists('wpc_lite_apply_pillar_riders')) {
    function wpc_lite_apply_pillar_riders($settings, $key, $value)
    {
        if (!is_array($settings)) {
            $settings = [];
        }

        $on = ($value === '1' || $value === 1 || $value === true);
        $v  = $on ? '1' : '0';

        // Nested pillars arrive as ['critical','css'] / ['cache','advanced'].
        if (is_array($key)) {
            if (count($key) < 2) {
                return $settings;
            }
            $settings[$key[0]][$key[1]] = $v;

            // Optimize CSS carries Used CSS Delivery (v7.10.825).
            if ($key[0] === 'critical' && $key[1] === 'css') {
                $settings['used-css'] = $v;
            }

            return $settings;
        }

        $settings[$key] = $v;

        switch ($key) {
            case 'imagesPreset':
                // Writing generate_webp alone made ceiling_from_settings() derive 'webp'
                // (defaults ship picture_avif=1 → fresh install = avif), so next-gen is
                // stated explicitly in both directions.
                $settings['retina']            = $v;
                $settings['generate_adaptive'] = $v;
                $settings['generate_webp']     = $v;
                $settings['picture_webp']      = $v;
                $settings['picture_avif']      = $v;
                $settings['wpc_nextgen']       = $on ? 'auto' : 'off';
                break;

            case 'cdnAll':
                $settings['live-cdn'] = $v;
                $settings['serve']    = ['jpg' => $v, 'gif' => $v, 'png' => $v, 'svg' => $v];
                $settings['css']      = $v;
                $settings['js']       = $v;
                $settings['fonts']    = $v;
                if ($on) {
                    // Only restored on the way up: turning the CDN off must not silently
                    // rewrite a quality level the user picked in Advanced.
                    $settings['qualityLevel'] = 'intelligent';
                }
                break;

            case 'delay-js-v2':
                // Optimize JavaScript carries the legacy engine and the v3 ordered-replay
                // engine (v7.10.825 / .927) — one toggle per pillar, in both directions.
                $settings['delay-js']    = $v;
                $settings['delay-js-v3'] = $v;
                break;
        }

        return $settings;
    }
}

if (!function_exists('wpc_heal_pillar_riders')) {
    function wpc_heal_pillar_riders()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) {
            return 0;
        }
        $set = get_option(WPS_IC_SETTINGS);
        if (!is_array($set)) {
            return 0;
        }
        $healed = 0;
        $map = [];
        $divi5_guard = function_exists('wpc_ucss_divi5_guard') && wpc_ucss_divi5_guard();
        if (!empty($set['critical']['css']) && $set['critical']['css'] == '1' && !$divi5_guard) {
            $map['used-css'] = '1';
        }
        if (!empty($set['imagesPreset']) && $set['imagesPreset'] == '1') {
            $map += ['retina' => '1', 'generate_adaptive' => '1', 'generate_webp' => '1',
                'picture_webp' => '1', 'picture_avif' => '1'];
            if (!isset($set['wpc_nextgen'])) {
                $map['wpc_nextgen'] = 'auto';
            }
        }
        if (!empty($set['cdnAll']) && $set['cdnAll'] == '1') {
            $map += ['live-cdn' => '1', 'css' => '1', 'js' => '1', 'fonts' => '1'];
        }
        if (!empty($set['delay-js-v2']) && $set['delay-js-v2'] == '1') {
            $map += ['delay-js' => '1', 'delay-js-v3' => '1'];
        }
        foreach ($map as $k => $v) {
            if (!isset($set[$k])) {
                $set[$k] = $v;
                $healed++;
            }
        }
        if ($healed > 0) {
            $GLOBALS['wpc_used_css_flip_automatic'] = true;
            update_option(WPS_IC_SETTINGS, $set);
            unset($GLOBALS['wpc_used_css_flip_automatic']);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('pillar-rider-heal', '', '', ['n' => $healed]);
            }
        }
        return $healed;
    }
}

if (!function_exists('wpc_embed_facade_migrate')) {
    /**
     * Once per site, from the upgrade pass (wpc_doctrine_reconcile): a stored `embed-facade` '1',
     * the retired Embed Facades option that nothing reads, becomes iframe Lazy Load. iframe-lazy is
     * set to '1' and `embed-facade` to '0' in one write through update_option(WPS_IC_SETTINGS),
     * the writer a settings save uses (its hooks purge the stored pages through the
     * delivery-config flip, softly for a write no person made); no key leaves the row, so the
     * settings ledger records a change, not a replace. `embed-facade-migrated {iframe_lazy_was}`
     * is logged. Option wpc_embed_facade_migrated records the first run, whatever it found, so a
     * later run, or an import that brings '1' back, changes nothing. Returns true when it wrote.
     */
    function wpc_embed_facade_migrate()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS') || get_option('wpc_embed_facade_migrated', false) !== false) {
            return false;
        }
        $settings = get_option(WPS_IC_SETTINGS);
        $wrote = false;
        if (is_array($settings) && isset($settings['embed-facade']) && (string) $settings['embed-facade'] === '1') {
            $lazyWas = isset($settings['iframe-lazy']) ? (string) $settings['iframe-lazy'] : '';
            $settings['embed-facade'] = '0';
            $settings['iframe-lazy'] = '1';
            update_option(WPS_IC_SETTINGS, $settings);
            $wrote = true;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('embed-facade-migrated', '', '', ['iframe_lazy_was' => $lazyWas]);
            }
        }
        update_option('wpc_embed_facade_migrated', time(), false);
        return $wrote;
    }
}

// v7.21.149 — which keys wpc_lite_apply_pillar_riders() actually owns. The batch settings writer
// carries non-boolean values too (replace-fonts=local, wpc_nextgen=webp, qualityLevel=3,
// backup=cloud); the riders normalise their value to '1'/'0', so they must only ever be handed
// a key that really is a boolean pillar. Accepts the same key shapes as the riders.
if (!function_exists('wpc_lite_is_pillar')) {
    function wpc_lite_is_pillar($key)
    {
        if (is_array($key)) {
            return count($key) >= 2 && $key[0] === 'critical' && $key[1] === 'css';
        }

        return in_array($key, ['imagesPreset', 'cdnAll', 'delay-js-v2'], true);
    }
}

// The Cloudflare CDN host has one writer. Every path that learns it (Connect, Refresh, the cname
// save, the cname link, the DNS-record helper, the agency relay, the slow-keys retry, the 6.10.13
// migration) hands it here, and nothing else writes WPS_IC_CF_CNAME.
// Rule: storing the host and telling the backend are one act. The CDN pods map cdn.<domain> to a
// site only through agencySites.cname, and that column is filled only by a /v2/config sync that
// carries the host. Six paths used to write the option and arm no sync, so the row kept no cname
// and the pods answered "Site not found" or bounced every asset to the origin (glass-inspirations
// 2026-09-18; ticket 11975, exsile: keys' updateCFCname took 30 s, the setup's sync had already
// gone out without the host, and the retry that stored it synced nothing).
// An empty host is refused: the cname save once wrote a keys answer that omitted cfName and
// blanked a working host.
// WPC_CNAME_OWNER_OFF=1 makes this a plain option write again: no record, no flag, no sync.
if (!function_exists('wpc_cf_cname_persist')) {
    /**
     * @param string $cname  the CDN host to emit (cdn.example.com)
     * @param string $source the caller, for the receipt
     * @return bool whether the stored host changed
     */
    function wpc_cf_cname_persist($cname, $source)
    {
        $cname    = trim((string) $cname);
        $previous = trim((string) get_option(WPS_IC_CF_CNAME, ''));
        $changed  = ($cname !== $previous);
        if ($cname === '') {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cname-persisted', '', '', ['source' => (string) $source, 'cname' => '', 'changed' => 0, 'refused' => 'empty']);
            }
            return false;
        }
        update_option(WPS_IC_CF_CNAME, $cname);
        if ($changed) {
            // The emit gate's verdict was about the previous host.
            update_option('wpc_cf_cname_verified', '0', false);
        }
        if (defined('WPC_CNAME_OWNER_OFF') && WPC_CNAME_OWNER_OFF) {
            return $changed;
        }
        update_option('wpc_cf_cname_written', ['t' => time(), 'cname' => $cname, 'source' => (string) $source], false);
        update_option('wpc_v2_force_provision', 1, false);
        $scheduled = function_exists('wpc_v2_schedule_config_sync');
        if ($scheduled) {
            wpc_v2_schedule_config_sync();
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cname-persisted', '', '', ['source' => (string) $source, 'cname' => $cname,
                'changed' => $changed ? 1 : 0, 'scheduled' => $scheduled ? 1 : 0]);
        }
        return $changed;
    }
}

// The host a /v2/config sync has to carry: the CF cname while Cloudflare delivers, else none
// (with Cloudflare delivery off the payload's cname is ic_custom_cname, not this host).
if (!function_exists('wpc_cf_cname_emit_host')) {
    function wpc_cf_cname_emit_host()
    {
        $cname = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $cf    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        return ($cname !== '' && is_array($cf) && !empty($cf['settings']['cdn'])) ? $cname : '';
    }
}

// Rule: the provisioning flag stays armed until a successful sync has carried the current host
// (wpc_cf_cname_synced, written on the sync's success path). A setup whose sync went out while
// keys was still answering had no host to carry, the success cleared the flag anyway, and the
// row kept no cname for good (ticket 11975).
if (!function_exists('wpc_cf_cname_sync_owed')) {
    function wpc_cf_cname_sync_owed()
    {
        $host = wpc_cf_cname_emit_host();
        if ($host === '') {
            return false;
        }
        $synced = get_option('wpc_cf_cname_synced');
        return !is_array($synced) || (string) ($synced['cname'] ?? '') !== $host;
    }
}

// WPC_CF_CNAME_GATE_LEGACY=1 restores, for one release, the emit gate as it was before it read
// the orchestrator's witness only: an unset verdict passes, the site's own probe through
// Cloudflare promotes, and the orchestrator never demotes.
if (!function_exists('wpc_cf_cname_gate_legacy')) {
    function wpc_cf_cname_gate_legacy()
    {
        return defined('WPC_CF_CNAME_GATE_LEGACY') && WPC_CF_CNAME_GATE_LEGACY;
    }
}

if (!function_exists('wpc_record_key_removal')) {
    /**
     * Records why this site's API key was emptied. Call it right before the key is cleared.
     *
     * Six paths empty the key (the URL-change check, the Disconnect button, the daily key cron and
     * the settings-page stats call on a 401, the portal's two deactivate actions, the multisite
     * disconnect) and only the URL-change check left a trace. On amamiespresso.com the key was
     * lost in September 2026 and nothing on the site could say when or by which path.
     *
     * Kept: the last 20 entries in `wps_ic_key_removal_log` (not autoloaded), shown on the
     * debug tab, plus a `key-removed` cflog receipt. Nothing is recorded when there was no key.
     */
    function wpc_record_key_removal($path, $context = [])
    {
        try {
            $options = get_option(WPS_IC_OPTIONS);
            if (!is_array($options) || empty($options['api_key'])) {
                return;
            }
            $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
            $entry = [
                'ts'          => gmdate('Y-m-d H:i:s') . ' UTC',
                'path'        => (string) $path,
                'key'         => substr((string) $options['api_key'], 0, 6) . '…',
                'user'        => ($user && !empty($user->ID)) ? $user->user_login . ' (#' . $user->ID . ')' : '',
                'cron'        => (defined('DOING_CRON') && DOING_CRON) ? 1 : 0,
                'ajax'        => (defined('DOING_AJAX') && DOING_AJAX) ? 1 : 0,
                'request_uri' => isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 300) : '',
                'version'     => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            ];
            if (!empty($context) && is_array($context)) {
                $entry['context'] = $context;
            }
            $log = get_option('wps_ic_key_removal_log', []);
            if (!is_array($log)) {
                $log = [];
            }
            $log[] = $entry;
            update_option('wps_ic_key_removal_log', array_slice($log, -20), false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('key-removed', '', '', ['path' => $entry['path'], 'user' => $entry['user'], 'cron' => $entry['cron']]);
            }
        } catch (\Throwable $e) {
        }
    }
}
