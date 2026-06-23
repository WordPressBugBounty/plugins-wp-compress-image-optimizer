<?php
if (!defined('ABSPATH')) exit;

/**
 * Outermost natural-URL buffer.
 *
 * The CDN rewriter (cdnRewriter, ob_start in checkCache_plugins_loaded at plugins_loaded:1)
 * naturalizes css/js/font transform URLs in the page body. But some stacks call flush() mid-page
 * (logged-in / certain Breeze + theme combos), which sends cdnRewriter's partial buffer to the
 * client early; anything printed AFTER that — WP 6.5+ script-module import-maps, interactivity
 * data, Breeze prefetch JSON, Query Monitor's asset dump — is emitted OUTSIDE cdnRewriter's buffer
 * and ships the /m:N/a: transform form (the persistent "m:0" URLs seen only when logged-in; real
 * visitors get the page-cached, fully-processed output and are unaffected).
 *
 * This registers the OUTERMOST front-end buffer (plugins_loaded:0, before cdnRewriter at :1) so it
 * (a) absorbs any mid-page flush() — keeping cdnRewriter's buffer intact over the whole page — and
 * (b) re-naturalizes the complete final output as a belt. It is INERT unless negotiated delivery is
 * GA (emission_ready), and the callback is a cheap no-op (strpos fast-path) when there's nothing to
 * convert. naturalize_asset_urls touches ONLY the /m:N/a: + /font:true/a: asset forms (never image
 * transforms) and is idempotent on already-natural URLs.
 */
if (!function_exists('wpc_v2_natural_url_buffer_cb')) {
    function wpc_v2_natural_url_buffer_cb($html)
    {
        if (!is_string($html) || $html === '') return $html;
        $out = $html;
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && wps_rewriteLogic::natural_assets_on()) {
            if (strpos($html, '/a:') !== false) {
                $n = wps_rewriteLogic::naturalize_asset_urls($html);
                if (is_string($n)) $out = $n;
            }
            // Regime-C: apply the onerror->origin failover even when there is no /a: to strip
            // (the tags may already be natural from the cdnRewriter pass). Idempotent via data-wpc-fb.
            if (method_exists('wps_cdn_rewrite', 'add_asset_failover')) {
                $fb = wps_cdn_rewrite::add_asset_failover($out);
                if (is_string($fb) && $fb !== '') $out = $fb;
            }
        }
        return $out;
    }
}

if (!function_exists('wpc_v2_natural_url_buffer_start')) {
    function wpc_v2_natural_url_buffer_start()
    {
        if (is_admin()) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (defined('WP_CLI') && WP_CLI) return;
        if (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP) return;
        if (!empty($_GET['wpc_no_buffer'])) return;
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return;
        // Regime-C: engage on ANY natural-assets zone (not just negotiated-GA), widened from
        // emission_ready() to natural_assets_on() so the belt re-naturalizes + hosts the onerror->origin
        // failover on CDN-on-but-Next-Gen-OFF zones too (matches the cdnRewriter tail gate). The cb keeps
        // its own natural_assets_on() guard, so this is the same population the floor unlocks.
        if (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'natural_assets_on')) return;
        if (!wps_rewriteLogic::natural_assets_on()) return;
        ob_start('wpc_v2_natural_url_buffer_cb');
    }
}

// plugins_loaded:0 — strictly before checkCache_plugins_loaded (plugins_loaded:1) so this buffer
// is OUTER to cdnRewriter and runs last on flush.
add_action('plugins_loaded', 'wpc_v2_natural_url_buffer_start', 0);
