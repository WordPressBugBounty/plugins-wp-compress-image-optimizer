<?php
if (!defined('ABSPATH')) exit;


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


        if (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'natural_assets_on')) return;
        if (!wps_rewriteLogic::natural_assets_on()) return;
        ob_start('wpc_v2_natural_url_buffer_cb');
    }
}


add_action('plugins_loaded', 'wpc_v2_natural_url_buffer_start', 0);
