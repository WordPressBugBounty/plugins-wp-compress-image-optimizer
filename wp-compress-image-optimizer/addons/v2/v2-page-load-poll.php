<?php
/**
 * WP Compress — Page-load opportunistic pull-drain trigger.
 *
 * Defense layer 4 of the lazy_cdn delivery architecture (per spec v2.0.2).
 * Load-bearing for ~10-30% of the customer fleet running Imunify360 /
 * Cloudways edge / similar managed-host WAFs that block POSTs from Bunny
 * orch pods. Without this layer, lazy_cdn manifest entries sit unfetched
 * forever on those sites because:
 *
 *   1. Wake-ping POSTs from orch get 415'd at the edge proxy (confirmed
 *      empirically against staging-1's Cloudways/Imunify360 layer on
 *      2026-05-27 02:43:13 UTC).
 *   2. WordPress cron is unreliable on shared hosts and may be disabled.
 *   3. No other path triggers wpc_v2_pull_drain_fire() server-initiated.
 *
 * This hook fires on every WP init (admin pages, front-end pages, REST
 * requests, etc. — anywhere init runs). 5-minute throttle via wp_options
 * keeps fire rate bounded. Drain itself has its own 15s transient lock
 * (wpc_v2_drain_running) so concurrent page loads don't multiply work.
 *
 * Latency model on Imunify360 sites:
 *   - Active site (regular admin/front-end traffic): variants land within
 *     ~5 minutes of CDN cache miss
 *   - Idle site: variants land on next admin login (could be hours-days)
 *   - All paths: manifest TTL 8 days backstops; CDN re-trigger self-heals
 *
 * Spec reference: LAZY_CDN_FINAL_SPEC v2.0.2, defense layer 4.
 * Revert: delete this file + remove require_once from v2-bootstrap.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_page_load_drain_tick')) {
    function wpc_v2_page_load_drain_tick()
    {
        // Skip on AJAX (would fire on every admin-ajax heartbeat = excessive),
        // wp-cron (already runs scheduled tasks separately), WP-CLI (CLI
        // operations shouldn't trigger background drains), REST API requests
        // (avoid recursion: /wake and /healthcheck could trigger this hook
        // and we don't want a drain fire per REST call).
        if (function_exists('wp_doing_ajax') && wp_doing_ajax())  return;
        if (function_exists('wp_doing_cron') && wp_doing_cron())  return;
        if (defined('WP_CLI') && WP_CLI)                          return;
        if (defined('REST_REQUEST') && REST_REQUEST)              return;

        // Pull infrastructure must be loaded (v2-pull-manifest.php). If
        // the plugin's pull layer isn't active for any reason (filter, env,
        // dormant install) we bail silently.
        if (!function_exists('wpc_v2_pull_drain_fire')) return;

        // Plugin must have an apikey configured — no point firing drain if
        // we can't authenticate the manifest GET.
        if (function_exists('wpc_v2_get_apikey')) {
            $apikey = wpc_v2_get_apikey();
            if ($apikey === '') return;
        }

        // 5-minute throttle. Last-fired timestamp lives in wp_options
        // (not transient) because we want this to survive object-cache
        // flushes — the throttle is correctness for keeping fleet load
        // bounded, not performance.
        //
        // Atomic check-and-set caveat: two simultaneous page loads can
        // both pass the gate before either writes the new timestamp. Both
        // call wpc_v2_pull_drain_fire(), which has its OWN transient lock
        // (wpc_v2_drain_running, 15s TTL) — second caller is a no-op.
        // Defense-in-depth.
        $now_ms = (int) round(microtime(true) * 1000);
        // (v7.03.82) FLEET RESOURCE SAFETY. Fire the drain ONLY when a recent optimize actually left async
        // variants pending — i.e. wpc_v2_drain_alive_until_ms (set by the optimize-trigger, extended by the
        // wake + the drain loop while it has work) is still in the future. On an idle site this returns
        // immediately: no loopback fire, no manifest long-poll, no FPM-worker hold — so the page-load drain
        // can't add load across the fleet's 10k shared hosts. During an active window, throttle to 20s (vs the
        // 5-min default) so the backup drains promptly when a wake was missed; the wake stays the real-time
        // primary. NOTE: the tick no longer SETS the deadline (below) — that made every idle tick keep the
        // drain loop alive for nothing; only the real backlog sources (optimize/wake) set it now.
        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        if ((int) get_option('wpc_v2_drain_alive_until_ms', 0) <= $now_ms) return;
        $last   = (int) get_option('wpc_v2_last_pull_check_ms', 0);
        $window = (int) apply_filters('wpc_v2_page_load_active_throttle_ms', 20000);
        if (($now_ms - $last) < $window) return;

        // Write the new timestamp BEFORE firing the drain. Order matters:
        // if drain itself fails to dispatch, we still want to throttle the
        // next attempt so we don't hammer the host with retries on every
        // page load.
        update_option('wpc_v2_last_pull_check_ms', $now_ms, false);

        // (v7.03.82) The deadline is NO LONGER set/extended here. We only reach this point when it is already
        // in the future (the gate above), set by the real backlog sources — the optimize-trigger and the wake.
        // The drain loop self-extends (+30s/poll) while it has work, so it stays alive until the manifest is
        // drained; once it expires, the next idle tick no-ops instead of reviving a loop with nothing to pull.

        // Fire the drain AFTER the response flushes, not inline on init: the
        // loopback connect (up to ~0.6s on a 3-rung local-vhost fallback) was
        // holding the visitor's render worker pre-render, adding to TTFB. Defer
        // via register_shutdown_function + fastcgi_finish_request (see
        // wpc_v2_deferred_pull_drain_fire). Best-effort; if it fails, the next
        // page load (5 min later) retries.
        if (function_exists('wpc_v2_register_deferred_pull_drain')) {
            wpc_v2_register_deferred_pull_drain();
        } else {
            wpc_v2_pull_drain_fire();
        }
    }
}

// Priority 99 = fire late in the init chain so all other plugins have
// had a chance to register. Ensures wpc_v2_pull_drain_fire is available
// even on installs where v2 bootstraps after other plugins.
add_action('init', 'wpc_v2_page_load_drain_tick', 99);
