<?php
/**
 * WP Compress v7.02.0 — v2 bootstrap loader.
 *
 * Single entry point for the v2 protocol implementation. Included
 * unconditionally from wp-compress-core.php but guarded internally — files
 * are loaded only when `wpc_protocol_version` site option is NOT 'v1'. Default
 * is 'v1' so customers on v7.01.49 see zero behavioural change on plugin
 * upgrade to v7.02.0 unless they (or canary cohort gate) opt in.
 *
 * Load order:
 *   1. v2-capabilities.php — exports wpc_use_v2_protocol() (auto-select gate)
 *   2. v2-client.php       — exports WPS_LocalV2 class
 *   3. v2-callback.php     — registers /wp-json/wpc/v2/bg_swap REST route
 *
 * The capability probe + REST route are loaded on EVERY mode except 'v1' so
 * that:
 *   - 'shadow' mode can run the v2 client in parallel for telemetry collection
 *     while the v1 client still ships variants to the customer (zero risk).
 *   - 'auto' mode can probe + canary-cohort-gate without blocking on the gate.
 *   - 'v2' mode is full upgrade.
 *
 * Anything that registers a hook (REST routes, upgrade invalidation) lives
 * inside the loaded file so PHP doesn't pay any cost when mode === 'v1'.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPC_V2_DIR')) {
    define('WPC_V2_DIR', __DIR__);
}

// Mode resolution, read once per request. The default is now 'v2': the
// service team retired the v1 /optimize endpoint, so legacy installs without
// an explicitly-set option would otherwise hit 404 ENDPOINT_GONE on every
// click. Fresh installs land directly on the working v2 contract.
$wpc_v2_mode = get_option('wpc_protocol_version', 'v2');

if ($wpc_v2_mode === 'v1') {
    // Fast path: customer is on the legacy contract. Don't load anything.
    return;
}

// 'shadow', 'v2', or 'auto': load the capability probe, client, and callback handlers.
require_once WPC_V2_DIR . '/v2-capabilities.php';
require_once WPC_V2_DIR . '/v2-client.php';
require_once WPC_V2_DIR . '/v2-callback.php';
require_once WPC_V2_DIR . '/v2-sse.php';
require_once WPC_V2_DIR . '/v2-trigger-scanner.php';
require_once WPC_V2_DIR . '/v2-rung-intercept.php'; // missing -WxH variant 302-redirects to nearest rung (Pixel-Optimal Strategy 1)
require_once WPC_V2_DIR . '/v2-fast-404.php';       // auto-managed mu-plugin: instant 404 for genuinely-missing images (skips full WP boot)
require_once WPC_V2_DIR . '/v2-sized-trigger.php';  // plugin-signed sized-trigger client (Phase 2; soft-fails until orch v3.22.21)
// Direct PHP entry point system: probe, URL selection helper, and the
// journal drain that consolidates direct-entry callback writes into
// ic_local_variants postmeta. The /api/v2/*.php direct-entry files run in
// their OWN SHORTINIT-only PHP context; this file lives in regular WP
// context for the plugin-side wiring.
// See SPEC-direct_entry.md
require_once WPC_V2_DIR . '/v2-direct-entry.php';
// Adaptive concurrency control. The plugin self-measures FPM capacity via
// TCP-style AIMD on callback handler timings (measured from REQUEST_TIME_FLOAT
// so FPM queue wait is captured), advertises a per-type cap in the /optimize-v2
// envelope, and orch's BatchedCallbackOutbox respects it. Eliminates FPM queue
// contention on every host class, from Cloudways shared to dedicated metal.
// See SPEC-adaptive_concurrency.md
require_once WPC_V2_DIR . '/v2-concurrency.php';
// REST journal. When wpc_v2_rest_journal_enabled() the batch REST handler
// writes variant entries to a per-image .jsonl journal file instead of doing
// the inline ic_local_variants merge. The drain side (registered in
// v2-direct-entry.php as wpc_v2_journal_drain_handler / wpc_v2_journal_drain_run)
// consolidates entries into postmeta on its own cadence under a single
// GET_LOCK per image. Drops batch handler time from 4-9 s of postmeta
// contention to ~30-50 ms (disk write + journal append + 200 response).
require_once WPC_V2_DIR . '/v2-journal.php';
// Pull delivery. The service team (from v3.18.27 on) uploads encoded variant
// bytes to BunnyCDN and ships URL-only callbacks. wpc_v2_parallel_pull()
// curl_multi-fetches the bytes back in parallel inside the batch handler.
// Trims the POST payload 200× (1 MB to 5 KB) and drops per-variant network
// overhead via HTTP/2 keep-alive. Flag-gated via wpc_v2_pull_delivery_enabled.
// See addons/v2/v2-pull.php and the service team's SPEC-pull-delivery.md.
require_once WPC_V2_DIR . '/v2-pull.php';
// FPM telemetry: a rolling 200-entry transient capture of heartbeat handler
// and bg_swap batch handler wall times. Cheap (one transient read/write per
// record), disabled by default. Surface stats via `wp wpc fpm-stats` and the
// admin widget. See addons/v2/v2-telemetry.php.
require_once WPC_V2_DIR . '/v2-telemetry.php';
// Pull manifest. The plugin polls GET /optimize-v2/manifest from the customer
// host instead of orch POSTing /wpc/v2/bg_swap_batch from a fixed outbound IP.
// Eliminates managed-WP-host WAF blocks at 10K+ scale. Phase 1 ships dormant
// (flag wpc_v2_pull_enabled defaults off); Phase 2 enables it alongside push
// for dedupe-by-sha256 defense in depth.
// See ~/Downloads/wpc-v704-v705-plugin-driven-architecture-spec.md v1.1.
require_once WPC_V2_DIR . '/v2-pull-manifest.php';

// Lazy-CDN: tags.source='lazycdn' entries arrive via the manifest path (orch's
// wake-ping infrastructure, deliveryMode='ping_pull'). The plugin receives them
// and writes directly to disk WITHOUT updating postmeta — these are pure CDN
// cache-fill writes, not customer-visible inventory.
// See LAZY_CDN_FINAL_SPEC v2.0 (orch v3.18.74+, CDN v2.81+).
// v2-lazy-cdn.php provides the ingest handler; v2-wake.php provides the
// /wp-json/wpc/v2/wake REST endpoint that orch's dispatcher pings to trigger
// an immediate pull rather than waiting for the next scheduled tick.
require_once WPC_V2_DIR . '/v2-lazy-cdn.php';
require_once WPC_V2_DIR . '/v2-wake.php';
// Page-load opportunistic drain trigger (defense layer 4 of the lazy_cdn
// delivery architecture). Load-bearing for ~10-30% of the fleet on managed
// hosts running Imunify360-style edge WAFs that block wake-ping POSTs from
// Bunny orch pods. Fires on init with a 5-min throttle.
require_once WPC_V2_DIR . '/v2-page-load-poll.php';
// Shutdown-function drain trigger (real-time, server-side) plus a WP-cron
// 2-min belt. Replaces reliance on orch wake-ping (which gets WAF-blocked on
// some hosts) with a trigger that runs on every request AFTER the response is
// sent. See the file header for the full architecture.
require_once WPC_V2_DIR . '/v2-shutdown-drain.php';
// /v2/config sync helper, WP-CLI command, and admin notice toggle. The
// customer opt-in path: the toggle enables agencySites.lazy_cdn_active=1 on
// orch, and CDN reads that within 60s via apikeyCache.
require_once WPC_V2_DIR . '/v2-config-sync.php';
// CF Piece 2 scaffold: signed x-wpc-config header injection wiring. Inert until orch
// ships POST /v2/signed-header AND the `wpc_v2_cf_header_injection` flag is on (default off).
require_once WPC_V2_DIR . '/v2-signed-header.php';
// HTML cache invalidation on variant landing. When AVIF/WebP variants land on
// disk, page HTML that references the attachment is stale: picture sources
// point at transform URLs because the variant didn't exist at render time.
// This module fires a clean_post_cache cascade across all major cache layers
// (Varnish, Breeze, W3TC, WP Rocket, etc.) for posts that embed the attachment.
require_once WPC_V2_DIR . '/v2-html-cache-purge.php';
// Customer Purge v1: unified fleet cache invalidation (CloudFlare, Bunny PZs,
// and the cdn-mc pod-fs fleet) via one orch /v2/customer-purge call. Fixes the
// "restored/deleted but visitors still see the optimized variant" case: restore
// deletes origin-disk variants but, since v7.08.31, purges no cache layer, and
// the `?v=` buster never reaches the path-keyed pod-fs LRU. Flag-gated
// (wpc_unified_purge_enabled, default off); it defines functions only, with no
// behavior until a site opts in and a restore handler calls wpc_purge_compat().
require_once WPC_V2_DIR . '/v2-customer-purge.php';
// v2-natural-url-buffer.php was a v7.06 experiment: an outermost ob_start that
// regex-converted transform URLs back to natural URLs. It was removed in v7.07
// in favor of a source-level fix: wpc_inject_picture_tags marks its wrapped
// <img> with data-wpc-handled="1" and the legacy wrappers (cdn-rewrite.php
// local_image_tags + rewriteLogic.php replaceImageTagsDoSlash) early-bail when
// they see that marker. No post-processing buffer needed.
// Lazy-CDN support tools (apikey-gated nopriv endpoints): opcache reset,
// postmeta backfill, disk inspector, force-drain, config sync, force-miss.
// Production diagnostic and recovery utilities used by support workflows. See
// the file header for the full endpoint list.
require_once WPC_V2_DIR . '/v2-lazy-test-setup.php';

// Rendered-width measurement beacon (telemetry, off by default). Defines the flag helper and
// the admin-ajax receiver (registered only while enabled). The front-end script is enqueued in
// classes/enqueues.class.php for all visitors when the flag is on.
require_once WPC_V2_DIR . '/v2-rendered-width-beacon.php';

// Mark that v2 is at least loaded so other plugin code can branch on it.
if (!defined('WPC_V2_LOADED')) {
    define('WPC_V2_LOADED', true);
}
