<?php
/**
 * WP Compress v7.02.0 — capability probe + 24h cache + v1/v2 auto-select (Day 4).
 *
 * Exposes:
 *   wpc_use_v2_protocol()                — bool. Authoritative gate. Plugin code
 *                                          checks this before deciding whether to
 *                                          call WPS_LocalV2 or the v1 path.
 *   wpc_probe_orchestrator_capabilities() — array. Live probe of /capabilities,
 *                                          24h cached. Force-refresh via ?force=1.
 *   wpc_v2_canary_cohort_active()         — bool. crc32(apikey) % 100 < canary_pct.
 *
 * Decision tree for wpc_use_v2_protocol():
 *
 *   wpc_protocol_version (site option):
 *     'v1'    → false (default — no flip until v7.02 GA on this site)
 *     'v2'    → true (force-on for canary or internal testing)
 *     'shadow'→ false (v1 still ships bytes; v2 client runs in parallel for
 *                      telemetry-only — not exposing variants to customer)
 *     'auto'  → check capability probe + canary cohort
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_use_v2_protocol')) {

// `define()` not `const` because PHP rejects `const` inside an if-block (only
// allowed at namespace/class scope). Functional equivalence, runtime resolution.
if (!defined('WPC_V2_CAPS_CACHE_KEY'))     define('WPC_V2_CAPS_CACHE_KEY',     'wpc_v2_capabilities');
if (!defined('WPC_V2_CAPS_TTL'))           define('WPC_V2_CAPS_TTL',           86400);   // 24h
if (!defined('WPC_V2_CANARY_OPTION_KEY'))  define('WPC_V2_CANARY_OPTION_KEY',  'wpc_v2_canary_pct');
if (!defined('WPC_V2_CANARY_DEFAULT_PCT')) define('WPC_V2_CANARY_DEFAULT_PCT', 0);       // 0 until staged rollout begins

/**
 * Canonical api_key resolver. The plugin stores api_key in the
 * `wps_ic` option (WPS_IC_OPTIONS constant in defines.php). Earlier v2
 * code mistakenly read 'wps_ic_options' (a different constant,
 * WPS_IC_OPTIONS_V2, that maps to a migration-staging option, NOT the
 * live key). On fresh installs that option doesn't exist → every Phase B
 * callback rejected with `plugin_no_apikey` even though Phase A succeeded
 * (Phase A reads the static `parent::$api_key` populated from `wps_ic`).
 *
 * Reads from `wps_ic` (canonical), with fallbacks to the migration option
 * `wps_ic_options` and the settings array `wps_ic_settings.api_key` for
 * hosts where one of those happens to be populated.
 *
 * Returns: (string) — the api_key, or empty string if all sources are empty.
 */
if (!function_exists('wpc_v2_get_apikey')) {
    function wpc_v2_get_apikey()
    {
        // 1) Canonical — `wps_ic` option (WPS_IC_OPTIONS).
        $canon = get_option('wps_ic');
        if (is_array($canon) && !empty($canon['api_key'])) {
            return (string) $canon['api_key'];
        }
        // 2) Migration-staging option `wps_ic_options` (WPS_IC_OPTIONS_V2).
        $migration = get_option('wps_ic_options');
        if (is_array($migration) && !empty($migration['api_key'])) {
            return (string) $migration['api_key'];
        }
        // 3) Settings option `wps_ic_settings` — `api_key` field is rarely
        //    populated there but check as last resort.
        $settings = get_option('wps_ic_settings');
        if (is_array($settings) && !empty($settings['api_key'])) {
            return (string) $settings['api_key'];
        }
        return '';
    }
}

/**
 * Authoritative gate. Returns true iff the calling code should use the v2
 * protocol for outbound POSTs + accept callbacks at /wpc/v2/bg_swap.
 */
function wpc_use_v2_protocol()
{
    static $cached = null;
    if ($cached !== null) return $cached;

    // Default flipped to 'v2' once the service team retired the v1 /optimize
    // endpoint — fresh installs must land on the v2 contract or every click 404s.
    $mode = get_option('wpc_protocol_version', 'v2');

    if ($mode === 'v1' || $mode === 'shadow') {
        $cached = false;
        return $cached;
    }
    if ($mode === 'v2') {
        $cached = true;
        return $cached;
    }
    // 'auto' — probe + canary cohort gate
    $caps = wpc_probe_orchestrator_capabilities();
    if (empty($caps['v2_optimize'])) {
        $cached = false;
        return $cached;
    }
    $cached = wpc_v2_canary_cohort_active();
    return $cached;
}

/**
 * Probe GET /capabilities on the configured orchestrator. 24h cached. Force-
 * refresh by passing $force=true. Returns:
 *   { v1_optimize: bool, v2_optimize: bool, v2_callback_endpoint: string,
 *     max_inline_bytes: int, max_callback_bytes: int, max_callbacks_per_second: int,
 *     probed_at: int }
 *
 * On probe failure (timeout, 5xx, malformed), returns last cached value or
 * a safe v1-only fallback. Caller checks `v2_optimize` to know if upgrade
 * is available.
 */
function wpc_probe_orchestrator_capabilities($force = false)
{
    $cached = get_site_transient(WPC_V2_CAPS_CACHE_KEY);
    if (is_array($cached) && !$force) {
        return $cached;
    }

    $orchestrator_url = wpc_v2_orchestrator_url();
    if ($orchestrator_url === '') {
        return wpc_v2_safe_fallback_caps('no_orchestrator_url');
    }

    $response = wp_remote_get($orchestrator_url . '/capabilities', [
        'timeout' => 5,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($response)) {
        error_log('[WPC V2Caps] probe transport failure: ' . $response->get_error_message());
        return is_array($cached) ? $cached : wpc_v2_safe_fallback_caps('transport_error');
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($body)) {
        error_log('[WPC V2Caps] probe non-200: code=' . $code);
        return is_array($cached) ? $cached : wpc_v2_safe_fallback_caps('non_200');
    }

    $caps = [
        'v1_optimize'              => !empty($body['v1_optimize']),
        'v2_optimize'              => !empty($body['v2_optimize']),
        'v2_callback_endpoint'     => isset($body['v2_callback_endpoint']) ? (string) $body['v2_callback_endpoint'] : '/wpc/v2/bg_swap',
        'max_inline_bytes'         => isset($body['max_inline_bytes']) ? (int) $body['max_inline_bytes'] : 26214400,
        'max_callback_bytes'       => isset($body['max_callback_bytes']) ? (int) $body['max_callback_bytes'] : 4194304,
        'max_callbacks_per_second' => isset($body['max_callbacks_per_second']) ? (int) $body['max_callbacks_per_second'] : 10,
        // Capability fields added in v2.2.0. Defaults match the v0.4-deferred state per the service team.
        'status_poll_supported'    => !empty($body['status_poll_supported']),
        'source_cache_enabled'     => !empty($body['source_cache_enabled']),
        'signed_urls_supported'    => !empty($body['signed_urls_supported']),
        'redeliver_supported'      => !empty($body['redeliver_supported']),
        'probed_at'                => time(),
        'probe_source'             => 'live',
    ];

    set_site_transient(WPC_V2_CAPS_CACHE_KEY, $caps, WPC_V2_CAPS_TTL);
    return $caps;
}

/**
 * Canary cohort check. Returns true iff this site is in the staged-rollout
 * percentile. Stable per-apikey: deterministic hash, no random/clock state.
 */
function wpc_v2_canary_cohort_active()
{
    $apikey = wpc_v2_get_apikey();
    if ($apikey === '') return false;

    $canary_pct = (int) get_option(WPC_V2_CANARY_OPTION_KEY, WPC_V2_CANARY_DEFAULT_PCT);
    if ($canary_pct <= 0) return false;
    if ($canary_pct >= 100) return true;

    return (crc32($apikey) % 100) < $canary_pct;
}

/**
 * Resolve the orchestrator URL for /optimize-v2 + /capabilities.
 *
 * Resolution order (first non-empty wins):
 *   1. WPC_V2_ORCHESTRATOR_URL constant (wp-config.php override; preferred for
 *      staging / canary). Example for v2.2.0 staging:
 *        define('WPC_V2_ORCHESTRATOR_URL', 'http://local-mc-v2.zapwp.net:443');
 *      Note: scheme + port included verbatim. Service is currently HTTP on :443
 *      because Bunny anycast isn't doing SSL termination yet; PHP wp_remote_post
 *      handles this fine. Edge SSL lands later; same paths, just https://.
 *   2. wpc_v2_orchestrator_url filter (programmatic override, e.g. test suites).
 *   3. wps_ic_geo_locate_v2 option (v1 geolocation result — what the v1 path
 *      uses today). Gets normalized to https:// since v1 geo URLs assume TLS.
 *   4. Hard fallback to https://frankfurt.zapwp.net.
 */
function wpc_v2_orchestrator_url()
{
    // 1) Explicit constant override (rarely used — for QA/canary cohorts).
    if (defined('WPC_V2_ORCHESTRATOR_URL') && WPC_V2_ORCHESTRATOR_URL !== '') {
        return rtrim((string) WPC_V2_ORCHESTRATOR_URL, '/');
    }

    // 2) Filter override.
    $override = apply_filters('wpc_v2_orchestrator_url', '');
    if ($override !== '') return rtrim((string) $override, '/');

    // 3) Geo-locate option ONLY if it points to a known v2-valid host. The
    //    legacy geo_locate API at cdn.zapwp.net is a v1-era artifact that
    //    returns regional URLs (frankfurt.zapwp.net etc.) which serve a
    //    marketing HTML page on /optimize-v2 — NOT the JSON API. v2 uses a
    //    single centralized orchestrator at local-mc.zapwp.net regardless
    //    of region (service-team confirmed final on 2026-05-23).
    //
    //    We honour the option only when it explicitly names the v2-valid
    //    host (or any host whitelisted via the
    //    `wpc_v2_orchestrator_valid_hosts` filter); otherwise we fall
    //    through to the v2 default to avoid the dead regional URL.
    $valid_hosts = apply_filters('wpc_v2_orchestrator_valid_hosts', [
        'local-mc.zapwp.net',
    ]);
    $geo = get_option('wps_ic_geo_locate_v2');
    if (is_array($geo) && !empty($geo['server'])) {
        $server = trim((string) $geo['server'], '/');
        // Strip scheme for the whitelist check; preserve original for return.
        $host_only = preg_replace('#^https?://#i', '', $server);
        if (in_array($host_only, $valid_hosts, true)) {
            if (preg_match('#^https?://#i', $server)) return $server;
            return 'https://' . $server;
        }
        // Stored geo value is a known-bad legacy host (e.g. frankfurt.zapwp.net
        // returning HTML). Skip + fall through to the v2 default.
    }

    // 4) Hard fallback — the working v2 orchestrator.
    return 'https://local-mc.zapwp.net';
}

/**
 * Safe fallback when probe fails AND no prior cache exists. Marks v2 as
 * unavailable so wpc_use_v2_protocol() falls back to v1.
 */
function wpc_v2_safe_fallback_caps($reason)
{
    return [
        'v1_optimize'              => true,
        'v2_optimize'              => false,
        'v2_callback_endpoint'     => '/wpc/v2/bg_swap',
        'max_inline_bytes'         => 26214400,
        'max_callback_bytes'       => 4194304,
        'max_callbacks_per_second' => 10,
        'status_poll_supported'    => false,
        'source_cache_enabled'     => false,
        'signed_urls_supported'    => false,
        'redeliver_supported'      => false,
        'probed_at'                => time(),
        'probe_source'             => 'fallback',
        'fallback_reason'          => $reason,
    ];
}

/**
 * Admin-side hook: force-refresh on plugin upgrade. Add to upgrader_process_complete.
 */
function wpc_v2_invalidate_caps_on_upgrade($upgrader_object, $options)
{
    if (!is_array($options) || empty($options['action']) || $options['action'] !== 'update') return;
    if (empty($options['type']) || $options['type'] !== 'plugin') return;
    if (empty($options['plugins']) || !is_array($options['plugins'])) return;
    foreach ($options['plugins'] as $plugin) {
        if (strpos((string) $plugin, 'wp-compress') !== false) {
            delete_site_transient(WPC_V2_CAPS_CACHE_KEY);
            break;
        }
    }
}
add_action('upgrader_process_complete', 'wpc_v2_invalidate_caps_on_upgrade', 10, 2);

/**
 * "Eager compressed flip" gate.
 *
 * Default (OFF): adaptive mode. Card stays in "Optimizing" until Phase A's
 * promote_to_compressed() flips ic_compressing.status. Phase B callbacks
 * before Phase A are silent state-wise — heartbeat mirrors the current
 * status. Refresh logic kicks in once Phase A is the canonical "done" signal.
 *
 * ON: eager mode. The FIRST variant that lands (Phase A or any Phase B
 * callback — whichever arrives first) flips ic_compressing.status to
 * 'compressed' immediately. Subsequent variants additively refresh the chip
 * count via the unified heartbeat. Works naturally with lazy backfill — the
 * single on-demand variant is already a complete "compressed" event.
 *
 * Toggle: site option `wpc_v2_eager_compressed_flip` (1/0). Also filterable
 * via the `wpc_v2_eager_compressed_flip` filter for runtime overrides.
 */
function wpc_v2_use_eager_compressed_flip()
{
    $opt = get_site_option('wpc_v2_eager_compressed_flip', false);
    return (bool) apply_filters('wpc_v2_eager_compressed_flip', !empty($opt));
}

/**
 * Optimization mode (when to encode variants).
 *
 *   'legacy'     — Default. Pre-encode all variants at upload OR explicit Compress
 *                  click. Current behavior, zero change for existing installs.
 *   'lazy_full'  — Skip on-upload entirely. First front-end emission of an
 *                  unprocessed image triggers the SAME /optimize-v2 full-compress
 *                  flow that today's Compress button uses (via the existing
 *                  wpc_maybe_trigger_optimize → wp-cron worker → singleCompressV4
 *                  path). End-state identical to legacy after warm. Never-viewed
 *                  images cost 0 encodes / 0 storage.
 *   'lazy_smart' — Width-trimmed lazy encode (since v7.02.15). Plugin parses each
 *                  page's <img srcset> to extract the widths actually rendered,
 *                  then fires ONE /optimize-v2 POST per image containing ONLY
 *                  the sub-sizes whose width matches the page's needs (±15px
 *                  tolerance). scaled (2560w) and original parents are SKIPPED
 *                  when the page doesn't display at those widths — saves ~6
 *                  encodes per image (3 formats × 2 parents). On a 20-image
 *                  page that's 120 fewer wasted encodes vs lazy_full.
 *                  Fallback safety: modern-delivery's srcset emission handles
 *                  missing-variant widths by trimming the srcset OR falling
 *                  back to on-demand CDN proxy URLs — never emits a 404.
 *   'lazy_cdn'   — [BETA, requires CDN coordination] Plugin emits CDN proxy URLs
 *                  in srcset for missing widths. CDN cache-miss → service encodes
 *                  on-demand → serves to browser AND callbacks plugin for local
 *                  persist. Best visitor experience (no first-render fallback).
 *
 * Mode is read-only from this helper; settings UI writes the option. Filterable
 * via `wpc_optimization_mode` for runtime override (e.g. canary cohort selection).
 */
if (!function_exists('wpc_get_optimization_mode')) {
    function wpc_get_optimization_mode()
    {
        // Stored inside wps_ic_settings (same place as every other plugin setting)
        // so the existing saveSettings handler picks it up via the form's
        // options[wpc_optimization_mode] field. Fallback chain handles the
        // upgrade case where the option doesn't yet exist.
        $settings = get_option(WPS_IC_SETTINGS, []);
        $mode = is_array($settings) && !empty($settings['wpc_optimization_mode'])
            ? (string) $settings['wpc_optimization_mode']
            : (string) get_option('wpc_optimization_mode', 'legacy');
        $valid = ['manual', 'legacy', 'lazy_full', 'lazy_smart', 'lazy_cdn'];
        if (!in_array($mode, $valid, true)) {
            $mode = 'legacy';
        }
        return (string) apply_filters('wpc_optimization_mode', $mode);
    }
}

/**
 * True when a `lazy_*` mode is active (lazy_full, lazy_smart, lazy_cdn).
 * Used to gate the lazy first-view trigger in modern-delivery.
 * Manual + Legacy modes return FALSE here — neither does lazy first-view encoding.
 */
if (!function_exists('wpc_lazy_mode_active')) {
    function wpc_lazy_mode_active()
    {
        return strpos(wpc_get_optimization_mode(), 'lazy_') === 0;
    }
}

/**
 * True when auto-on-upload should be disabled. Any mode other than 'legacy'
 * means the customer opted out of upload-time encoding (manual = nothing
 * auto; lazy_* = encode on view instead of upload).
 */
if (!function_exists('wpc_auto_encoding_disabled')) {
    function wpc_auto_encoding_disabled()
    {
        return wpc_get_optimization_mode() !== 'legacy';
    }
}

/**
 * Lazy_cdn: should the plugin emit the UNSCALED original as the CDN
 * `u:` parameter, or the WP-attached file (which is `-scaled.jpg` for
 * images >2560px)?
 *
 * Default ON: use unscaled original → Local Service re-encodes from highest
 *             possible quality → variants are minimum size + maximum quality.
 *             Catches the double-compression bug (WP makes -scaled at q82;
 *             re-encoding from -scaled would compound the loss).
 *
 * Default OFF: keep using $meta['file'] (current behavior, what staging is
 *              doing). For customers who have manually edited individual
 *              thumbnails via WP's "Edit Image" (which saves -{timestamp}
 *              suffix files), going to original loses those edits. Such
 *              customers can flip this OFF.
 *
 * Per-attachment override: postmeta `_wpc_lazy_use_sub_size = 'yes'` forces
 * sub-size emission for that specific image regardless of the global toggle.
 *
 * Filter: `wpc_v2_lazy_cdn_use_original` for runtime control.
 */
if (!function_exists('wpc_v2_lazy_cdn_use_original')) {
    function wpc_v2_lazy_cdn_use_original($attachment_id = 0)
    {
        // Per-attachment override (advanced — for hero/hand-edited images).
        if ($attachment_id > 0) {
            $override = get_post_meta($attachment_id, '_wpc_lazy_use_sub_size', true);
            if ($override === 'yes') {
                return (bool) apply_filters('wpc_v2_lazy_cdn_use_original', false, $attachment_id);
            }
        }
        // Global toggle: default ON (best quality).
        $enabled = ((int) get_option('wpc_v2_lazy_cdn_use_original', 1) === 1);
        return (bool) apply_filters('wpc_v2_lazy_cdn_use_original', $enabled, $attachment_id);
    }
}

/**
 * Lazy first-view trigger (v2 path).
 *
 * Modern-delivery's first-emission detector calls this when the image has no
 * variants AND lazy mode is active. Schedules a wp-cron event so the page
 * render returns immediately. Cron handler dispatches the FULL /optimize-v2
 * flow that the manual Compress button uses (run_v2_optimize) — NOT the v1
 * lazy ladder path which has its own POST /optimize and was failing with
 * HTTP 0 in current staging tests.
 *
 * Dedupe: short transient lock prevents storm if 100 visitors hit the page
 * before the cron fires. Same idempotency contract as wpc_atomic_queue_gate.
 *
 * Idempotent: subsequent emissions of the same image while compress is
 * in-flight (ic_local_variants still empty + lock held) skip cleanly.
 */
if (!function_exists('wpc_lazy_trigger_v2')) {
    /**
     * @param int   $attachment_id
     * @param array $needed_widths Phase 2 smart-lazy: optional list of pixel
     *              widths actually rendered on the page (from srcset). When
     *              non-empty, prepare_v2_optimize trims the envelope to only
     *              meta sizes matching these widths — saves encoder/storage
     *              cost. Empty array = encode full ladder (legacy lazy_full).
     */
    function wpc_lazy_trigger_v2($attachment_id, array $needed_widths = [], $upgrade_partial_lazy = false)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return false;

        // Gate 0: restore-in-flight micro-lock. A render racing a mid-flight
        // restore must not queue a compress — run_v2_optimize clears the restore marker at
        // start, which would reopen the parent-overwrite window WHILE files are being
        // deleted. Released the moment the restore completes (cleanRestoreMeta); the next
        // render triggers freely.
        if (get_transient('wpc_restoring_' . $attachment_id)) {
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 0 (restore in flight)');
            return false;
        }

        // Gate 1: already compressed (variants exist).
        // EXCEPTION: the CDN-off backfill may pass $upgrade_partial_lazy=true for an
        // image whose ONLY variants are lazy_cdn entries (the partial "0J 0W 1A" chip state: one
        // on-demand avif from a previous CDN-driven session). Such partials can never complete
        // themselves with the CDN off, so treat them as uncompressed and run the FULL compress
        // (run_v2_optimize merges under GET_LOCK; existing entries survive). Never used in
        // CDN-on lazy mode, where partials are by design.
        $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (is_array($variants) && !empty($variants)) {
            // The upgrade flag now TRUSTS the caller (live catch: the format-delta
            // trickle's force bailed here because this branch re-required the all-lazy
            // condition). Exactly two call sites pass true, both deliberate + self-guarded:
            // the all-lazy partial upgrade (scanner verifies all_lazy first) and the
            // format-fill admit (scanner verifies the format gap + 24h sig guard).
            if (!$upgrade_partial_lazy) {
                error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 1 (variants exist count=' . count($variants) . ')');
                return false;
            }
            // Mark for the drain's race-protection re-check (separate request) so it also
            // admits this upgrade instead of skipping on "variants present".
            set_transient('wpc_lazy_v2_full_' . $attachment_id, 1, 600);
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' UPGRADE admit (variants=' . count($variants) . ') — full compress queued');
        }

        // Gate 2: short-lived trigger lock prevents storm. 10-minute TTL covers
        // the full /optimize-v2 Phase A + Phase B drain window.
        //
        // Stale-lock self-heal: if the lock is held but no ic_compressing
        // post_meta exists (worker crashed mid-drain, transient survived but
        // no work is actually in flight), clear and proceed. Without this the
        // image stays stuck "queued" for the full 10-min TTL.
        $lock_key = 'wpc_lazy_v2_trigger_' . $attachment_id;
        if (get_transient($lock_key)) {
            $compressing = get_post_meta($attachment_id, 'ic_compressing', true);
            if (!is_array($compressing) || empty($compressing['status'])) {
                delete_transient($lock_key);
                error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' cleared stale Gate 2 lock (no ic_compressing — orphaned)');
                // fall through and proceed
            } else {
                error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 2 (lock held, ic_compressing=' . (string) $compressing['status'] . ')');
                return false;
            }
        }
        set_transient($lock_key, time(), 600);

        // Mark this image as "optimizing" so the Media Library shows the
        // in-flight state (instead of leaving it as "uncompressed"). The
        // heartbeat poller picks this up and renders the Optimizing card.
        update_post_meta($attachment_id, 'ic_compressing', [
            'status' => 'optimizing',
            'time'   => time(),
            'source' => 'lazy_v2',
        ]);
        set_transient('wps_ic_compress_' . $attachment_id, [
            'imageID' => $attachment_id,
            'status'  => 'compressing',
            'time'    => time(),
        ], 300);

        // Phase 2 smart-lazy: stash needed widths so the loopback drain (which
        // runs in a separate PHP-FPM worker) can read them and pass through to
        // prepare_v2_optimize. 600 s TTL — longer than Phase A typically takes
        // so the drain always finds it. Sanitize to positive ints.
        $widths_clean = [];
        foreach ($needed_widths as $w) {
            $w = (int) $w;
            if ($w > 0) $widths_clean[] = $w;
        }
        if (!empty($widths_clean)) {
            $widths_clean = array_values(array_unique($widths_clean));
            set_transient('wpc_lazy_v2_widths_' . $attachment_id, $widths_clean, 600);
        } else {
            // Clear any stale per-image widths if this trigger doesn't have any
            // (avoid a previous trigger's widths leaking into a new lazy run).
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
        }

        error_log('[WPC LazyV2] queued image=' . $attachment_id . ' mode=' . wpc_get_optimization_mode() . ' smart_widths=' . (empty($widths_clean) ? 'all' : implode(',', $widths_clean)));

        // Lazy means lazy: page render NEVER blocks on encoding.
        //
        // Async dispatch: fire a non-blocking HTTP request to admin-ajax.php
        // with our drain action. WordPress loopback HTTP is the most reliable
        // async pattern across hosts — admin-ajax loads in a SEPARATE PHP-FPM
        // worker, processes the request, and runs run_v2_optimize there. The
        // current page render returns to the browser immediately (timeout
        // 0.01s + blocking=false = fire-and-forget).
        //
        // Auth: we sign the request with the API key so admin-ajax.php can
        // verify it's a legit loopback before running an expensive encode.
        $options  = get_option(WPS_IC_OPTIONS);
        $apikey   = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $nonce    = substr(hash('sha256', $apikey . '|' . $attachment_id . '|' . floor(time() / 60)), 0, 32);
        $ajax_url = admin_url('admin-ajax.php');

        // Local-vhost loopback. This used to be wp_remote_post(blocking=false) to admin_url()'s PUBLIC
        // host = the CDN/WAF edge on a datacenter-IP site → truthy but the drain self-POST never lands on
        // local PHP-FPM, so lazy_cdn variants didn't fire from the render trigger). Connect-only via the
        // proven shared helper; cookieless apikey-derived nonce + body UNCHANGED. Cron/external wake stays the backstop.
        $lzp = wp_parse_url($ajax_url);
        if (!empty($lzp['host'])) {
            $lz_https = (!empty($lzp['scheme']) && $lzp['scheme'] === 'https');
            $lz_port  = !empty($lzp['port']) ? (int) $lzp['port'] : ($lz_https ? 443 : 80);
            $lz_host  = (string) $lzp['host'];
            $lz_path  = (!empty($lzp['path']) ? $lzp['path'] : '/') . '?action=wpc_lazy_v2_drain';
            $lz_body  = http_build_query(['attachment_id' => $attachment_id, 'nonce' => $nonce]);
            $lz_req   = "POST {$lz_path} HTTP/1.1\r\nHost: {$lz_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($lz_body) . "\r\nConnection: close\r\nUser-Agent: WPCLazyDrain/1.0\r\n\r\n" . $lz_body;
            $lz_fp = wps_ic_ajax::wpc_loopback_open_socket($lz_host, $lz_port, $lz_https, 0.2);
            if ($lz_fp) { @stream_set_timeout($lz_fp, 0, 100000); @fwrite($lz_fp, $lz_req); @fclose($lz_fp); }
        }

        return true;
    }
}

/**
 * AJAX drain handler — runs in a SEPARATE PHP-FPM worker spawned by the
 * non-blocking loopback HTTP request from wpc_lazy_trigger_v2. Validates
 * the signed nonce, then invokes run_v2_optimize (~3-5s Phase A + ~30s
 * Phase B drain). The original page render is long done by the time this
 * worker even starts.
 */
if (!function_exists('wpc_v2_variants_all_lazy')) {
    /**
     * TRUE when every ic_local_variants entry is a lazy_cdn ingest (the partial
     * "0J 0W 1A" state: on-demand avif(s) only, no Phase-A jpeg parents). Distinguishes a
     * lazy partial (upgrade-eligible under CDN-off backfill) from a real compress (never touch).
     */
    function wpc_v2_variants_all_lazy($variants)
    {
        if (!is_array($variants) || empty($variants)) return false;
        foreach ($variants as $entry) {
            if (!is_array($entry) || empty($entry['lazy_cdn'])) return false;
        }
        return true;
    }
}

if (!function_exists('wpc_lazy_v2_drain_ajax')) {
    function wpc_lazy_v2_drain_ajax()
    {
        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        $nonce         = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if ($attachment_id <= 0 || $nonce === '') {
            wp_die('invalid', 400);
        }

        // Verify signed nonce (same construction as the trigger). The
        // floor(time()/60) window means a nonce is valid for up to ~60s —
        // plenty for the loopback HTTP request to arrive.
        $options = get_option(WPS_IC_OPTIONS);
        $apikey  = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $now_min = floor(time() / 60);
        $valid = false;
        foreach ([$now_min, $now_min - 1] as $bucket) {
            $expected = substr(hash('sha256', $apikey . '|' . $attachment_id . '|' . $bucket), 0, 32);
            if (hash_equals($expected, $nonce)) { $valid = true; break; }
        }
        if (!$valid) {
            wp_die('bad nonce', 403);
        }

        // Re-check variants (race protection — another worker may have already
        // encoded this image).
        // Partial-lazy upgrade exception: the trigger marked this image for a FULL
        // compress over its lazy-only variants (CDN-off backfill). Admit it once; the flag is
        // single-use so normal race protection is preserved for everything else.
        $existing = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (is_array($existing) && !empty($existing)) {
            $wpc_full_flag = get_transient('wpc_lazy_v2_full_' . $attachment_id);
            // The single-use upgrade marker now stands on its own (live catch: the
            // format-fill upgrade's marker was set by the trigger, then ignored here because
            // this branch ALSO re-required the all-lazy condition — the same double-check that
            // blocked Gate 1). The marker is only ever set by the trigger's two self-guarded
            // admit paths (all-lazy partial, format-fill) and is consumed once; normal race
            // protection is untouched for everything else.
            if ($wpc_full_flag) {
                delete_transient('wpc_lazy_v2_full_' . $attachment_id);
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' UPGRADE admitted (variants=' . count($existing) . ')');
            } else {
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' skipped — variants already present');
                wp_die('ok', 200);
            }
        }

        @ignore_user_abort(true);
        @set_time_limit(180);

        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' run_v2_optimize unavailable');
            delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            wp_die('handler unavailable', 500);
        }

        // Backup parity with the manual + bulk paths. Without this, Phase B
        // callbacks overwrite sub-size files with no pristine copy in
        // wpc-backups/ — Restore can still recover via cloud_bkp or thumbnail
        // regen, but the fast-path is missing. backup_all_sizes is idempotent
        // (file_exists guards inside) so calling it on every drain is cheap.
        if (class_exists('wps_local_compress')) {
            $compress = new wps_local_compress();
            if (method_exists($compress, 'backup_all_sizes')) {
                $compress->backup_all_sizes($attachment_id);
            }
        }

        // Phase 2 smart-lazy: pick up the per-image needed widths the trigger
        // stashed (from this page's srcset). prepare_v2_optimize filters the
        // envelope's sub-sizes to ONLY these widths — encoder + storage win.
        // Transient is cleared after read so it doesn't leak across runs.
        $needed_widths = get_transient('wpc_lazy_v2_widths_' . $attachment_id);
        if (is_array($needed_widths) && !empty($needed_widths)) {
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
            $option_overrides = ['needed_widths' => array_values(array_map('intval', $needed_widths))];
        } else {
            $option_overrides = [];
        }

        $t_start = microtime(true);
        $result  = wps_ic_ajax::run_v2_optimize($attachment_id, $option_overrides);
        $wall_ms = (int) round((microtime(true) - $t_start) * 1000);
        error_log(sprintf(
            '[WPC LazyV2 drain] image=%d result=%s wall_ms=%d %s',
            $attachment_id,
            !empty($result['ok']) ? 'SUCCESS' : 'FAILED',
            $wall_ms,
            !empty($result['error']) ? 'error=' . $result['error'] : ''
        ));

        // On already_in_flight: another Phase A is running for this
        // image (likely from a concurrent lazy trigger or manual/bulk call).
        // DON'T clear state — the other run will complete and update heartbeat
        // normally. Clearing would let a future emission re-fire and clobber
        // the in-flight run.
        //
        // On other failures: clear the lock + ic_compressing so retries can happen.
        if (empty($result['ok'])) {
            if (!empty($result['error']) && $result['error'] === 'already_in_flight') {
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' bailed: already_in_flight — preserving state for in-flight run');
            } else {
                delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
                delete_post_meta($attachment_id, 'ic_compressing');
                delete_transient('wps_ic_compress_' . $attachment_id);
            }
        }

        wp_die('done', 200);
    }
}
// Register both authenticated AND anonymous endpoints — the loopback HTTP
// request doesn't include cookies (we strip them in the trigger to keep it
// stateless), so it arrives as nopriv. The signed nonce is the auth.
add_action('wp_ajax_wpc_lazy_v2_drain',        'wpc_lazy_v2_drain_ajax');
add_action('wp_ajax_nopriv_wpc_lazy_v2_drain', 'wpc_lazy_v2_drain_ajax');

/**
 * Cron handler for the v2 lazy trigger. Calls the same self-contained v2
 * optimize path the manual Compress button uses (wps_ic_ajax::run_v2_optimize).
 * The result is returned synchronously to the cron worker — Phase A's parents
 * write to disk, Phase B callbacks land asynchronously via /wpc/v2/bg_swap.
 */
if (!function_exists('wpc_lazy_v2_compress_handler')) {
    function wpc_lazy_v2_compress_handler($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return;

        // Sanity re-check: variants may have landed via another path in the
        // ~1s between trigger queue and cron fire.
        $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (is_array($variants) && !empty($variants)) {
            error_log('[WPC LazyV2] skipped image=' . $attachment_id . ' — variants now present');
            delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            return;
        }

        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            error_log('[WPC LazyV2] failed image=' . $attachment_id . ' — run_v2_optimize unavailable');
            delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            return;
        }

        // Backup parity with the AJAX sibling (wpc_lazy_v2_drain_ajax, line ~646).
        // run_v2_optimize → process_response does an IN-PLACE destructive overwrite of the parent
        // original/scaled JPEG; without a prior backup the customer's pristine file is destroyed and
        // restore is impossible. This handler is registered (add_action 'wpc_lazy_v2_compress') and
        // was the ONE lazy path missing the backup its sibling already takes. backup_all_sizes is
        // idempotent (file_exists-guarded copies) so this is a fast no-op when a backup exists.
        if (class_exists('wps_local_compress')) {
            $compress = new wps_local_compress();
            if (method_exists($compress, 'backup_all_sizes')) {
                $compress->backup_all_sizes($attachment_id);
            }
        }

        // Phase 2 smart-lazy: pick up cron-context needed widths too.
        $needed_widths = get_transient('wpc_lazy_v2_widths_' . $attachment_id);
        if (is_array($needed_widths) && !empty($needed_widths)) {
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
            $option_overrides = ['needed_widths' => array_values(array_map('intval', $needed_widths))];
        } else {
            $option_overrides = [];
        }

        $t_start = microtime(true);
        $result  = wps_ic_ajax::run_v2_optimize($attachment_id, $option_overrides);
        $wall_ms = (int) round((microtime(true) - $t_start) * 1000);

        error_log(sprintf(
            '[WPC LazyV2] image=%d result=%s wall_ms=%d %s',
            $attachment_id,
            !empty($result['ok']) ? 'SUCCESS' : 'FAILED',
            $wall_ms,
            !empty($result['error']) ? 'error=' . $result['error'] : ''
        ));

        // On already_in_flight: don't clear the lock; another Phase A is
        // running and will update state normally. Other failures clear the lock
        // for retry. On success, the lock TTL handles natural expiry.
        if (empty($result['ok'])) {
            if (!empty($result['error']) && $result['error'] === 'already_in_flight') {
                error_log('[WPC LazyV2] image=' . $attachment_id . ' bailed: already_in_flight');
            } else {
                delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            }
        }
    }
}
add_action('wpc_lazy_v2_compress', 'wpc_lazy_v2_compress_handler', 10, 1);

/**
 * Clock-skew probe. Hits the orchestrator's GET /clock endpoint and compares
 * server unix_ms against local time(). The 60s HMAC replay window means a
 * skew > 60s causes silent 401 on every callback — this surfaces it as a log
 * line so support can spot it before customers do. Service-side confirms all
 * orchestrator pods sync to the same Bunny NTP source, so one probe per host
 * is sufficient.
 *
 * Returns ['ok' => bool, 'skew_s' => float, 'reason' => string].
 */
function wpc_v2_probe_orchestrator_clock()
{
    $orchestrator_url = wpc_v2_orchestrator_url();
    if ($orchestrator_url === '') {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'no_orchestrator_url'];
    }

    $r = wp_remote_get(rtrim($orchestrator_url, '/') . '/clock', [
        'timeout'   => 5,
        'sslverify' => false,
    ]);
    if (is_wp_error($r)) {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'transport:' . $r->get_error_message()];
    }
    if ((int) wp_remote_retrieve_response_code($r) !== 200) {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'http_' . wp_remote_retrieve_response_code($r)];
    }

    $body = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($body) || empty($body['unix_ms'])) {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'malformed_clock_response'];
    }

    $skew_s = abs(((float) $body['unix_ms'] / 1000.0) - (float) time());
    return ['ok' => true, 'skew_s' => $skew_s, 'reason' => ''];
}

/**
 * Daily cron — surface excessive clock skew. >30s warns (HMAC may flake under
 * load); >60s errors (callbacks WILL 401). Logs to debug.log only; admin
 * notice is a future-session deliverable.
 */
function wpc_v2_clock_check_cron()
{
    $result = wpc_v2_probe_orchestrator_clock();
    if (!$result['ok']) {
        error_log('[WPC V2Clock] probe failed reason=' . $result['reason']);
        return;
    }
    $skew = (float) $result['skew_s'];
    if ($skew > 60) {
        error_log(sprintf('[WPC V2Clock ERROR] skew=%.1fs exceeds 60s HMAC window — callbacks WILL be rejected', $skew));
    } elseif ($skew > 30) {
        error_log(sprintf('[WPC V2Clock WARN] skew=%.1fs approaching 60s HMAC window', $skew));
    }
    // Cache last good probe for diagnostics endpoint.
    set_site_transient('wpc_v2_clock_last', [
        'skew_s'  => $skew,
        'checked' => time(),
    ], DAY_IN_SECONDS * 2);
}
add_action('wpc_v2_clock_check', 'wpc_v2_clock_check_cron');

/**
 * Schedule the daily cron if not already armed. Hooks `init` so it lands on
 * any admin request and self-heals if the cron was cleared.
 */
function wpc_v2_clock_check_schedule()
{
    if (!wp_next_scheduled('wpc_v2_clock_check')) {
        wp_schedule_event(time() + 60, 'daily', 'wpc_v2_clock_check');
    }
}
add_action('init', 'wpc_v2_clock_check_schedule');

/**
 * Splash-count cache invalidation on variant landings (since v7.02.20).
 *
 * The `wpc_bulk_library_counts` transient (60s TTL) caches COUNT(*) tallies
 * for the bulk-page "55 ready / 22 can be restored" splash. Pre-7.02.20 it
 * was only invalidated at a handful of explicit points (post-compress, bulk
 * drain complete, bulk stop — see ajax.class.php:2931, 3309, 3536, 3754,
 * 3817). The `/wpc/v2/bg_swap_single` callback handler shipped in 7.02.19
 * did NOT invalidate, so lazy-CDN single-variant landings could leave the
 * splash stale up to 60s after a variant arrived.
 *
 * Hook on `updated_post_meta` catches EVERY write through update_post_meta —
 * which is every plugin path that lands a variant (Phase A inline parents,
 * Phase B callbacks via wpc_v2_merge_variant, bg_swap_single, eager-flip).
 * Confirmed in plugin audit 2026-05-24: all variant writes go through
 * update_post_meta (none use raw $wpdb for ic_local_variants / ic_status).
 *
 * Cost: ~5µs per meta write. Negligible vs the WP postmeta write itself.
 */
function wpc_v2_invalidate_splash_count($meta_id, $object_id, $meta_key)
{
    if ($meta_key !== 'ic_local_variants' && $meta_key !== 'ic_status') return;

    // Performance fix: both writes below are debounced. Without debouncing, a
    // single bulk run fires hundreds of these per minute (one per landed
    // variant). Each used to delete_transient + update_option synchronously,
    // and the next splash render had to recount the entire library because
    // the cache was just nuked. Net effect: every admin-page reload during a
    // bulk was paying a full library count.
    //
    // Now:
    //   - splash cache bust: at most once every 2 s (reads stay fresh within
    //     2 s, which is well under perceptual freshness on a 60 s TTL)
    //   - meta-write timestamp: at most once every 500 ms (more than enough
    //     granularity for the StopBulk bounded-settle poll's 150 ms ticks)
    //
    // Both gates use get_option, which is object-cached after the first read
    // per request — net cost is ~one cached read per meta write.
    $now_ms = (int) (microtime(true) * 1000);

    $last_bust_ms = (int) get_option('wpc_v2_last_splash_bust_at', 0);
    if (($now_ms - $last_bust_ms) >= 2000) {
        delete_transient('wpc_bulk_library_counts');
        update_option('wpc_v2_last_splash_bust_at', $now_ms, false);
    }

    $last_write_ms = (int) get_option('wpc_v2_last_meta_write_at', 0);
    if (($now_ms - $last_write_ms) >= 500) {
        update_option('wpc_v2_last_meta_write_at', $now_ms, false);
    }
}
add_action('updated_post_meta', 'wpc_v2_invalidate_splash_count', 10, 3);
add_action('added_post_meta',   'wpc_v2_invalidate_splash_count', 10, 3);

/* ═════════════════════════════════════════════════════════════════════════
 *  Feature flags for the bulk UX / dispatch upgrades (v7.03.0)
 *
 *  All four flags ship DEFAULT OFF. Code paths exist but are dormant until
 *  a site owner runs `wp option update wpc_v2_<flag> 1` (or a filter says
 *  yes). This lets us deploy + soak the code in production before any
 *  behavior change goes live.
 *
 *  Filter overrides take precedence over the option (return non-null to
 *  win). Used by automated tests + staging environments.
 *
 *  See v7.03.0 CHANGELOG for the activation playbook.
 * ═════════════════════════════════════════════════════════════════════════ */

/**
 * Per-variant formats[] consumer.
 * When on: the dispatch envelope sent to /optimize-v2 includes per-variant
 * formats[] overrides (paired with orch v3.18.65). When off: legacy global
 * formats array (current behavior).
 */
function wpc_v2_formats_consumer_enabled()
{
    $override = apply_filters('wpc_v2_formats_consumer_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_formats_consumer_enabled', 0);
}

/**
 * AVIF predictor consumer.
 * When on: the Phase A response handler parses `phaseA.avifPrediction`
 * (added in orch v3.18.65) and stashes it on the image transient. Future
 * dispatches can use the topK / mode / maxProb to inform per-variant
 * format selection. When off: predictor data (if present) is ignored.
 */
function wpc_v2_predictor_consumer_enabled()
{
    $override = apply_filters('wpc_v2_predictor_consumer_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_predictor_consumer_enabled', 0);
}

/**
 * AIMD ceiling tuning (50→12).
 * When on: WPC_V2_AC_INITIAL_CAP=8, CEILING=12 (replaces 3, 50 defaults).
 * Pairs with orch ADAPTIVE_CONCURRENCY_ENABLED=1 — until orch flips that,
 * the plugin's cap is advertised but ignored fleet-side. When off: legacy
 * 3/2/50 defaults.
 */
function wpc_v2_aimd_tuned_enabled()
{
    $override = apply_filters('wpc_v2_aimd_tuned_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_aimd_tuned_enabled', 0);
}

/**
 * HEAD-poll for /admin/customer-activity freshness signal.
 * When on: JS polls the orch endpoint (5s active / 30s splash-idle / off
 * otherwise) and busts `wpc_bulk_library_counts` transient when the
 * timestamp advances past the cached value. Belt-and-suspenders on top
 * of the updated_post_meta hook — catches edge cases where Phase B
 * callbacks landed via a path the hook missed. When off: meta hook alone.
 */
function wpc_v2_head_poll_enabled()
{
    $override = apply_filters('wpc_v2_head_poll_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_head_poll_enabled', 0);
}

} // function_exists guard
