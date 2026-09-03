<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-bootstrap.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPC_V2_DIR')) {
    define('WPC_V2_DIR', __DIR__);
}


$wpc_v2_mode = get_option('wpc_protocol_version', 'v2');

if ($wpc_v2_mode === 'v1') {
    
    return;
}


require_once WPC_V2_DIR . '/v2-capabilities.php';
require_once WPC_V2_DIR . '/v2-client.php';
require_once WPC_V2_DIR . '/v2-store.php';
require_once WPC_V2_DIR . '/v2-callback.php';
require_once WPC_V2_DIR . '/v2-sse.php';
require_once WPC_V2_DIR . '/v2-trigger-scanner.php';
require_once WPC_V2_DIR . '/v2-rung-intercept.php';
require_once WPC_V2_DIR . '/v2-fast-404.php';
require_once WPC_V2_DIR . '/v2-sized-trigger.php';










if (!function_exists('wpc_v2_selfcheck654')) {
    function wpc_v2_selfcheck654()
    {
        $missing = [];
        foreach ([
            'wpc_v2_handle_bg_swap'        => 'v2-callback.php',
            'wpc_v2_handle_bg_swap_batch'  => 'v2-callback.php',
            'wpc_v2_verify_hmac'           => 'v2-callback.php',
            'wpc_v2_store_bytes655'        => 'v2-store.php',
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
    add_action('init', 'wpc_v2_selfcheck654', 1);

    add_action('admin_notices', function () {
        $g = get_option('wpc_v2_gutted654');
        if (empty($g) || !is_array($g) || !function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>WP Compress:</strong> a security scanner on this server has emptied '
            . 'plugin file(s): <code>' . esc_html(implode(', ', (array) $g['files'])) . '</code>. '
            . 'Image optimization callbacks cannot be received, so no optimized images can land. '
            . 'Ask your host to allow-list these files (they are signed, authenticated plugin code), then reinstall WP Compress.</p></div>';
    });
}


require_once WPC_V2_DIR . '/v2-direct-entry.php';


require_once WPC_V2_DIR . '/v2-recovery.php';


require_once WPC_V2_DIR . '/v2-concurrency.php';


require_once WPC_V2_DIR . '/v2-journal.php';


require_once WPC_V2_DIR . '/v2-pull.php';


require_once WPC_V2_DIR . '/v2-telemetry.php';



require_once WPC_V2_DIR . '/v2-pull-manifest.php';


require_once WPC_V2_DIR . '/v2-lazy-cdn.php';
require_once WPC_V2_DIR . '/v2-wake.php';



require_once WPC_V2_DIR . '/v2-page-load-poll.php';


require_once WPC_V2_DIR . '/v2-lcp-health.php';


require_once WPC_V2_DIR . '/v2-lcp-nocache.php';



require_once WPC_V2_DIR . '/v2-shutdown-drain.php';


require_once WPC_V2_DIR . '/v2-config-sync.php';


require_once WPC_V2_DIR . '/origin-reach.php';


require_once WPC_V2_DIR . '/v2-signed-header.php';


require_once WPC_V2_DIR . '/v2-html-cache-purge.php';





require_once WPC_V2_DIR . '/v2-customer-purge.php';


require_once WPC_V2_DIR . '/v2-lazy-test-setup.php';


require_once WPC_V2_DIR . '/v2-rendered-width-beacon.php';


if (!defined('WPC_V2_LOADED')) {
    define('WPC_V2_LOADED', true);
}
