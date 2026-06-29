<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_cloudflare extends wps_ic_integrations {

    public function is_active() {
        $cfSettings = get_option(WPS_IC_CF);
        return !empty($cfSettings) && !empty($cfSettings['token']);
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    public function add_admin_hooks() {
        return [
            'wps_ic_purge_all_cache' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache($url_key = false) {
        $cfSettings = get_option(WPS_IC_CF);

        if (empty($cfSettings) || empty($cfSettings['zone']) || empty($cfSettings['token'])) {
            return;
        }

        $zone  = $cfSettings['zone'];
        $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
        if (!$cfapi) {
            return;
        }

        // (v7.03.51) A per-PAGE HTML purge (save_post, comment, image variant-landing, per-page
        // critical-CSS — these pass the page permalink as $url_key) must NOT flush the whole CF zone.
        // purgeCacheAsync() = purge_everything, which wipes every edge-cached IMAGE → a fleet-wide
        // cold-miss storm (the same failure the plugin-update purge had, fixed in v7.03.50). CF usually
        // doesn't even cache HTML, so a full-zone purge for one page is all cost, no benefit. Scope it to
        // the URL(s) instead (purgeFilesAsync, ≤30 files, fire-and-forget). Only a TRUE full-cache intent
        // ($url_key empty/'all' — delivery-mode flips, the manual "Purge CDN" button, zone changes) still
        // purges the whole zone. wpc_customer_purge('all') / the manual button call CF purge_everything on
        // their own paths, so genuine full purges are unaffected.
        $urls = [];
        foreach ((array) $url_key as $u) {
            if (is_string($u) && $u !== '' && $u !== 'all' && filter_var($u, FILTER_VALIDATE_URL)) {
                $urls[] = $u;
            }
        }
        if (!empty($urls)) {
            $cfapi->purgeFilesAsync($zone, $urls);
            return;
        }

        // Full-cache intent (no specific URL): purge the whole zone.
        $cfapi->purgeCacheAsync($zone);
    }

}
