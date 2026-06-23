<?php
/**
 * WP Compress — Shutdown-function drain trigger + WP-cron belt.
 *
 * Replaces the brittle "wait for orch wake-ping to reach us" assumption with
 * a server-side trigger that runs on every request. The wake-ping path stays
 * (orch still POSTs to /wp-json/wpc/v2/wake when WAF allows it), but we no
 * longer DEPEND on it — drain fires reliably regardless of whether the
 * customer's WAF blocks Bunny pods.
 *
 * Why server-side, not browser sendBeacon:
 *   - sendBeacon can be CSP-blocked, AdBlock-blocked, ITP-throttled,
 *     console-error-noisy. Customer ops teams notice. Hostile environments.
 *   - Cookies + GDPR — sendBeacon sends cookies; some customers' compliance
 *     posture requires opt-in. Server-side trigger sidesteps entirely.
 *   - Works at 100% of PHP-running hosts (vs ~95% for sendBeacon).
 *   - Zero browser dependency = zero distribution overhead, zero failure
 *     modes from front-end variance.
 *
 * Two layers ship here:
 *
 *   LAYER 1 — `register_shutdown_function` on every request
 *     ↳ Fires AFTER the response is sent (via fastcgi_finish_request when
 *       PHP-FPM is available; behind-the-scenes on mod_php). Visitor never
 *       waits. 30s transient lock prevents thrashing under high traffic.
 *     ↳ Latency: ~100ms from "any visitor pageload" → "drain dispatched"
 *
 *   LAYER 2 — `wpc_v2_shutdown_drain_tick` recurring WP-cron (2 min)
 *     ↳ Belt-and-suspenders for sites with sparse frontend traffic. WP-cron
 *       fires on any page load that arrives after the interval window. On
 *       servers with system cron pointed at wp-cron.php it fires reliably
 *       every 2 min regardless of traffic.
 *     ↳ Coverage: no-traffic sites still drain within 2 min of variants
 *       being ready.
 *
 * Layers 3 (orch wake-ping) + 4 (admin-side 5-min page-load poll) stay in
 * place as additional triggers; with this module they're bonus speed, not
 * load-bearing.
 *
 * Cost per request:
 *   - 1 transient read (`wpc_v2_shutdown_drain_lock`) — sub-1ms on Redis,
 *     ~0.5ms on wp_options
 *   - When lock is free: 1 transient write + 1 fsockopen loopback. Both
 *     non-blocking. ~1-2ms attributable to this module before the response
 *     is even sent. After response: drain dispatch, but visitor already gone.
 *
 * Revert: delete this file + remove the require_once in v2-bootstrap.php +
 * `wp_clear_scheduled_hook('wpc_v2_shutdown_drain_tick')` to clean cron.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decide whether this request should attempt to trigger a drain on shutdown.
 *
 * Excludes:
 *   - WP-CLI runs (cron-style invocations have their own drain triggers)
 *   - WP cron events (avoid recursion — the cron tick itself fires drain)
 *   - admin-ajax requests (they have specific handlers; drain fires from
 *     within those handlers when relevant — adding shutdown layer would
 *     thrash on heartbeat/autosave/etc.)
 *   - REST API requests (same reason — REST handlers manage their own
 *     drain triggers if needed; e.g. /wpc/v2/wake explicitly calls drain)
 *
 * Allows:
 *   - Frontend page loads (the high-volume real-time trigger)
 *   - Admin page loads outside of AJAX/cron (catches admin-only sites)
 */
if (!function_exists('wpc_v2_shutdown_drain_should_trigger')) {
    function wpc_v2_shutdown_drain_should_trigger()
    {
        if (defined('WP_CLI') && WP_CLI) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return false;
        // Allow customer-side override (e.g. for low-memory hosts that
        // want to disable the shutdown trigger entirely)
        if (!apply_filters('wpc_v2_shutdown_drain_enabled', true)) return false;
        return true;
    }
}

/**
 * Register the shutdown function on init. The function itself does the
 * transient-lock + drain-fire dance AFTER the response is sent.
 */
if (!function_exists('wpc_v2_shutdown_drain_register')) {
    function wpc_v2_shutdown_drain_register()
    {
        if (!wpc_v2_shutdown_drain_should_trigger()) return;

        // Only fire when lazy_cdn is actually enabled — no point spinning
        // pull-drain on sites that don't use lazy_cdn delivery. The check
        // is a single get_site_option read, sub-millisecond.
        if (function_exists('wpc_v2_get_lazy_enabled') && !wpc_v2_get_lazy_enabled()) return;

        register_shutdown_function('wpc_v2_shutdown_drain_fire');
    }
}
add_action('init', 'wpc_v2_shutdown_drain_register', 99);

/**
 * The actual shutdown handler. Three steps:
 *   1. Flush response to browser (PHP-FPM only; mod_php falls through with
 *      the worker still attached but the drain dispatch is itself
 *      non-blocking so the wait is sub-10ms).
 *   2. Check the transient lock — if last drain fire was <30s ago, bail.
 *   3. Set the lock + fire wpc_v2_pull_drain_fire (which itself has an
 *      internal 15s lock via wpc_v2_drain_running, so double-fire from
 *      concurrent workers is harmless).
 */
if (!function_exists('wpc_v2_shutdown_drain_fire')) {
    function wpc_v2_shutdown_drain_fire()
    {
        // PHP-FPM: send response to browser NOW. Subsequent code runs
        // detached from the visitor. mod_php / litespeed have their own
        // mechanisms; absence of fastcgi_finish_request isn't fatal — the
        // dispatch below is non-blocking either way.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        // Sanity: drain helper must exist. If it doesn't, this module has
        // been required before v2-pull-manifest.php somehow — abort cleanly.
        if (!function_exists('wpc_v2_pull_drain_fire')) return;

        // 30s transient lock. The lock's TTL is intentionally longer than
        // the drain's own internal lock (15s) so a fresh shutdown call
        // won't queue a redundant drain while one is mid-flight.
        // Configurable via filter for high-traffic operators who want to
        // tune density (lower) or back off (higher).
        $lock_ttl = (int) apply_filters('wpc_v2_shutdown_drain_lock_ttl_s', 30);
        if ($lock_ttl < 5)   $lock_ttl = 5;
        if ($lock_ttl > 300) $lock_ttl = 300;

        if (get_transient('wpc_v2_shutdown_drain_lock')) return;
        set_transient('wpc_v2_shutdown_drain_lock', 1, $lock_ttl);

        wpc_v2_pull_drain_fire();
    }
}

/**
 * LAYER 2 — WP-cron belt for no-traffic sites.
 *
 * Uses self-scheduling SINGLE events rather than a recurring schedule.
 * Reason: empirically (v7.08.1 first deploy) WP's recurring-event
 * `wp_reschedule_event()` failed with `invalid_schedule` for our custom
 * `wpc_v2_2min` schedule — likely because the `cron_schedules` filter
 * application timing inside wp_cron's reschedule path doesn't always see
 * late-registered plugins' schedules. Single events sidestep entirely:
 * the handler queues the next single event at the end of each run.
 *
 * Concurrency safety: wp_schedule_single_event is deduped by hook+args+time
 * within a 10-min window. If two requests both try to schedule "the next
 * tick", WP keeps only the earliest. wpc_v2_pull_drain_fire has its own
 * internal lock (wpc_v2_drain_running, 15s TTL) so concurrent shutdown +
 * cron firing causes at most one drain in flight at a time.
 */
if (!function_exists('wpc_v2_shutdown_drain_schedule_event')) {
    function wpc_v2_shutdown_drain_schedule_event()
    {
        $enabled = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
        $hook    = 'wpc_v2_shutdown_drain_tick';

        // Migration: an earlier v7.08.1 deploy scheduled this hook as a
        // RECURRING event with our custom 'wpc_v2_2min' schedule, which
        // empirically fails to reschedule (invalid_schedule log spam).
        // If we find an existing event with a non-empty `schedule` field
        // (i.e. recurring), clear all instances and let the single-event
        // chain take over. wp_get_scheduled_event returns a stdClass for
        // existing events; the `schedule` property is the cron schedule
        // name for recurring events, or false for single events.
        if (function_exists('wp_get_scheduled_event')) {
            $existing = wp_get_scheduled_event($hook);
            if (is_object($existing) && !empty($existing->schedule)) {
                wp_clear_scheduled_hook($hook);
            }
        }

        if ($enabled && !wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + 120, $hook);
        }
        if (!$enabled && wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
add_action('init', 'wpc_v2_shutdown_drain_schedule_event', 100);

if (!function_exists('wpc_v2_shutdown_drain_tick_handler')) {
    function wpc_v2_shutdown_drain_tick_handler()
    {
        if (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }
        // Self-reschedule next tick. Single-event chain replaces a recurring
        // schedule (see file header). If lazy_cdn was disabled between now
        // and the next tick, the next handler invocation will see it and
        // simply not re-queue.
        $enabled = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
        if ($enabled && !wp_next_scheduled('wpc_v2_shutdown_drain_tick')) {
            wp_schedule_single_event(time() + 120, 'wpc_v2_shutdown_drain_tick');
        }
    }
}
add_action('wpc_v2_shutdown_drain_tick', 'wpc_v2_shutdown_drain_tick_handler');
