<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPC_V2_DIR')) {
    define('WPC_V2_DIR', __DIR__);
}


// The v2 orchestrator is the only image protocol: always load the capability probe, the
// client and the callback handlers. A `wpc_protocol_version` of 'v1' used to return here and
// left the site on the retired v1 service, whose every route answers 404.
require_once WPC_V2_DIR . '/v2-inbound.php';
require_once WPC_V2_DIR . '/v2-capabilities.php';
require_once WPC_V2_DIR . '/v2-level.php';
require_once WPC_V2_DIR . '/v2-client.php';
require_once WPC_V2_DIR . '/v2-store.php';
require_once WPC_V2_DIR . '/v2-callback.php';
require_once WPC_V2_DIR . '/v2-sse.php';
require_once WPC_V2_DIR . '/v2-trigger-scanner.php';
require_once WPC_V2_DIR . '/v2-rung-intercept.php';
require_once WPC_V2_DIR . '/v2-fast-404.php';
require_once WPC_V2_DIR . '/v2-sized-trigger.php';
require_once WPC_V2_DIR . '/v2-policy.php';

// v7.10.654 — SELF-INTEGRITY: never assume our own code is still on disk.
// Receipt (thepttv, 2026-07-31): a host malware scanner (Imunify-class, realtime/root)
// repeatedly flagged v2-callback.php — whose legitimate job is base64_decode() of a
// signed body followed by wpc_fs_put() into uploads, i.e. byte-for-byte the shape
// of a webshell dropper — and "cleaned" it to ZERO BYTES. require_once on an empty file
// SUCCEEDS SILENTLY, so the REST routes simply never registered, every callback 404'd,
// and the site looked healthy from the inside for six weeks while no optimized image
// could ever land. The service team spent that time hunting a service-side cause.
// A missing handler is now a loud, visible, reportable state.
if (!function_exists('wpc_v2_selfcheck_required_functions')) {
    function wpc_v2_selfcheck_required_functions()
    {
        $missing = [];
        foreach ([
            'wpc_v2_handle_bg_swap'        => 'v2-callback.php',
            'wpc_v2_handle_bg_swap_batch'  => 'v2-callback.php',
            'wpc_v2_verify_hmac'           => 'v2-inbound.php',
            'wpc_v2_store_bytes'        => 'v2-store.php',
            'wpc_v2_get_apikey'            => 'v2-capabilities.php',
        ] as $fn => $file) {
            if (!function_exists($fn)) {
                $missing[$fn] = $file;
            }
        }
        if (empty($missing)) {
            if (get_option('wpc_v2_gutted654')) {
                delete_option('wpc_v2_gutted654');
            }
            return;
        }
        $files = array_values(array_unique(array_values($missing)));
        update_option('wpc_v2_gutted654', ['at' => time(), 'files' => $files], false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('plugin-file-gutted', '', '', ['files' => implode(',', $files)]);
        }
    }
    add_action('init', 'wpc_v2_selfcheck_required_functions', 1);

    add_action('admin_init', function () {
        if (!function_exists('wpc_set_state_notice')) { return; }
        $g = get_option('wpc_v2_gutted654');
        if (empty($g) || !is_array($g)) { wpc_clear_state_notice('files_emptied'); return; }
        wpc_set_state_notice('files_emptied', 'error', sprintf(__('A security scanner on this server emptied %s. Optimized images cannot be received until the file is restored: reinstall the plugin, and allow-list these files in the scanner.', 'wp-compress-image-optimizer'), implode(', ', (array) $g['files'])));
    }, 30);
}


require_once WPC_V2_DIR . '/v2-direct-entry.php';
// Phase-B drain recovery + diagnostics (deleted-DB / stranded-drain "drain=null").
// wp wpc-v2-recover <fresh|resync|status>; or ?wpc_v2_pull_recover=… (admin+nonce).
require_once WPC_V2_DIR . '/v2-recovery.php';


require_once WPC_V2_DIR . '/v2-concurrency.php';


require_once WPC_V2_DIR . '/v2-journal.php';


require_once WPC_V2_DIR . '/v2-pull.php';


require_once WPC_V2_DIR . '/v2-telemetry.php';
// Pull manifest. The plugin polls GET /optimize-v2/manifest from the customer


require_once WPC_V2_DIR . '/v2-pull-manifest.php';


require_once WPC_V2_DIR . '/v2-lazy-cdn.php';
require_once WPC_V2_DIR . '/v2-wake.php';
require_once WPC_V2_DIR . '/v2-lcp-health.php';


require_once WPC_V2_DIR . '/v2-lcp-nocache.php';
require_once WPC_V2_DIR . '/v2-config-sync.php';


require_once WPC_V2_DIR . '/origin-reach.php';


require_once WPC_V2_DIR . '/v2-signed-header.php';


require_once WPC_V2_DIR . '/v2-html-cache-purge.php';


// "restored/deleted but visitors still see the optimized variant" case: restore


require_once WPC_V2_DIR . '/v2-customer-purge.php';


require_once WPC_V2_DIR . '/v2-rendered-width-beacon.php';

// Mark that v2 is at least loaded so other plugin code can branch on it.
if (!defined('WPC_V2_LOADED')) {
    define('WPC_V2_LOADED', true);
}
