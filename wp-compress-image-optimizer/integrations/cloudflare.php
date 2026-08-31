<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/cloudflare.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_cloudflare extends wps_ic_integrations {

    public function is_active() {
        $cfSettings = get_option(WPS_IC_CF);
        return !empty($cfSettings) && !empty($cfSettings['token']);
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
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


        $urls = [];
        $wpc_had_slug = false;
        foreach ((array) $url_key as $u) {
            if (is_string($u) && $u !== '' && $u !== 'all' && filter_var($u, FILTER_VALIDATE_URL)) {
                $urls[] = $u;
            } elseif (is_string($u) && $u !== '' && $u !== 'all') {


                if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey')) {
                    $resolved = wps_ic_url_key::getUrlFromKey($u);
                    if (!empty($resolved) && filter_var($resolved, FILTER_VALIDATE_URL)) {
                        $urls[] = $resolved;
                        continue;
                    }
                }
                $wpc_had_slug = true;
            }
        }
        if (!empty($urls)) {


            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {
                wps_ic_cache::purgeEdgeHtmlUrls($urls);
            } else {
                $cfapi->purgeFilesAsync($zone, $urls);
            }
            return;
        }


        if ($wpc_had_slug) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cf-skip-unresolvable', is_string($url_key) ? $url_key : '', '', []);
            }
            return;
        }


        if (!apply_filters('wpc_cf_purge_html_full', false)
            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
            wps_ic_cache::cfPurgeAllHtml();
            return;
        }
        if (!apply_filters('wpc_cf_purge_html_full', false)
            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {

            wps_ic_cache::purgeEdgeHtmlUrls([home_url('/'), home_url()]);
            return;
        }
        $cfapi->purgeCacheAsync($zone);
    }

}
