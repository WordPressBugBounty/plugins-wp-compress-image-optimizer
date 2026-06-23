<?php
/**
 * wp-cli commands.
 *
 * Loaded only under WP_CLI context. Each command handler explicitly
 * require_once's wp-compress-core.php — wp-compress.php's main bootstrap
 * (line 22) gates out the core load under WP_CLI to avoid running
 * page-load init code during cli sessions, so handlers must opt back in.
 */

if (!defined('WP_CLI') || !WP_CLI) return;
if (!class_exists('WP_CLI_Command')) return;

class WPC_CLI_Command extends WP_CLI_Command
{
    /**
     * Backfill missing AVIF variants for already-compressed images.
     *
     * Detects width slots in ic_local_variants that have WebP and/or JPEG
     * entries but no matching AVIF — the result of a lazy ladder that ran
     * before v7.01.24 hardened avif=1 in the body fields. Resizes the parent
     * locally (using the v7.01.25 unscaled-source probe) and POSTs each
     * missing-AVIF tuple as an AVIF-only request via the standard lazy-fill
     * endpoint.
     *
     * ## OPTIONS
     *
     * [--id=<id>]
     * : Single attachment ID. Mutually exclusive with --all.
     *
     * [--all]
     * : Run on every attachment with ic_status=compressed.
     *
     * ## EXAMPLES
     *
     *     wp wpcompress backfill-avif --id=122
     *     wp wpcompress backfill-avif --all
     */
    public function backfill_avif($args, $assoc)
    {
        // Bootstrap the plugin's core under wp-cli (normally gated out by wp-compress.php:22)
        if (!function_exists('wpc_backfill_missing_avif')) {
            $core = __DIR__ . '/wp-compress-core.php';
            if (file_exists($core)) {
                if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
                require_once $core;
            }
        }
        if (!function_exists('wpc_backfill_missing_avif')) {
            WP_CLI::error('wpc_backfill_missing_avif() not loaded — core bootstrap failed');
        }

        $id  = isset($assoc['id'])  ? (int) $assoc['id']  : 0;
        $all = !empty($assoc['all']);

        if (!$id && !$all) {
            WP_CLI::error('Provide --id=<N> or --all');
        }
        if ($id && $all) {
            WP_CLI::error('--id and --all are mutually exclusive');
        }

        $ids = $id ? [$id] : self::collect_compressed_ids();
        if (empty($ids)) {
            WP_CLI::warning('No matching attachments');
            return;
        }

        WP_CLI::log(sprintf('Processing %d attachment(s)…', count($ids)));
        $totals = ['queued' => 0, 'all_covered' => 0, 'no_variants' => 0, 'no_parent' => 0, 'no_meta' => 0, 'errors' => 0];

        foreach ($ids as $aid) {
            $result = wpc_backfill_missing_avif($aid);
            $reason = $result['reason'] ?? 'unknown';
            $queued = (int) ($result['queued'] ?? 0);

            if ($queued > 0) {
                $totals['queued'] += $queued;
                WP_CLI::log(sprintf('  id=%d queued=%d targets=%s', $aid, $queued, implode(',', $result['targets'] ?? [])));
            } elseif ($reason === 'all-covered') {
                $totals['all_covered']++;
            } elseif ($reason === 'no-variants') {
                $totals['no_variants']++;
            } elseif ($reason === 'no-parent') {
                $totals['no_parent']++;
                WP_CLI::warning(sprintf('  id=%d skipped: no parent file on disk', $aid));
            } elseif ($reason === 'no-meta') {
                $totals['no_meta']++;
                WP_CLI::warning(sprintf('  id=%d skipped: no attachment metadata', $aid));
            } else {
                $totals['errors']++;
                WP_CLI::warning(sprintf('  id=%d reason=%s skipped=%s', $aid, $reason, implode(',', $result['skipped'] ?? [])));
            }
        }

        WP_CLI::success(sprintf(
            'Done. queued=%d all-covered=%d no-variants=%d no-parent=%d no-meta=%d errors=%d',
            $totals['queued'], $totals['all_covered'], $totals['no_variants'],
            $totals['no_parent'], $totals['no_meta'], $totals['errors']
        ));
    }

    private static function collect_compressed_ids()
    {
        global $wpdb;
        $ids = $wpdb->get_col("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = 'ic_status' AND meta_value = 'compressed'
            ORDER BY post_id ASC
        ");
        return array_map('intval', $ids ?: []);
    }

    /**
     * Surgical purge of variant tracking post_meta WITHOUT restoring
     * disk bytes from backup.
     *
     * ## OPTIONS
     *
     * <id>
     * : Attachment ID to purge variant post_meta for.
     *
     * ## EXAMPLES
     *
     *     wp wpcompress purge-variants 113
     *     wp wpcompress purge-variants 122
     */
    public function purge_variants($args, $assoc)
    {
        // Bootstrap the plugin's core under wp-cli (gated out by wp-compress.php:22)
        if (!function_exists('wpc_purge_variants_for_image')) {
            $core = __DIR__ . '/wp-compress-core.php';
            if (file_exists($core)) {
                if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
                require_once $core;
            }
        }
        if (!function_exists('wpc_purge_variants_for_image')) {
            WP_CLI::error('wpc_purge_variants_for_image() not loaded — core bootstrap failed');
        }

        $imageID = isset($args[0]) ? (int) $args[0] : 0;
        if (!$imageID) {
            WP_CLI::error('Usage: wp wpcompress purge-variants <id>');
        }

        $result = wpc_purge_variants_for_image($imageID);
        if (!empty($result['error'])) {
            WP_CLI::error('purge failed: ' . $result['error'] . ' (imageID=' . $imageID . ')');
        }

        $cleared = $result['cleared'] ?? [];
        if (empty($cleared)) {
            WP_CLI::log(sprintf('image=%d: nothing to clear (no variant meta present)', $imageID));
        } else {
            WP_CLI::log(sprintf('image=%d cleared: %s', $imageID, implode(', ', $cleared)));
        }
        WP_CLI::success(sprintf(
            'Purged %d post_meta key(s) for image %d. Disk files preserved.',
            count($cleared), $imageID
        ));
    }
}

// v2 protocol smoke + staging tests. Adds CLI surface for
// driving WPS_LocalV2 directly without the wp-admin UI (which is Day 5-6
// work). Lets us verify the v2.2.0 staging orchestrator end-to-end before
// the Settings UI lands.
if (!class_exists('WPC_CLI_V2_Command')) {

class WPC_CLI_V2_Command extends WP_CLI_Command
{
    /**
     * Probe the orchestrator's /capabilities endpoint and print the parsed
     * capability response. Force-refreshes the cache.
     *
     * ## OPTIONS
     *
     * [--orchestrator=<url>]
     * : Override the orchestrator URL (e.g. http://local-mc-v2.zapwp.net:443).
     *   Without this, uses WPC_V2_ORCHESTRATOR_URL constant or geolocation.
     *
     * ## EXAMPLES
     *
     *     wp wpcompress v2-capabilities
     *     wp wpcompress v2-capabilities --orchestrator=http://local-mc-v2.zapwp.net:443
     */
    public function v2_capabilities($args, $assoc)
    {
        if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
        require_once __DIR__ . '/wp-compress-core.php';

        // Force-load v2 files DIRECTLY. The normal flow goes through
        // v2-bootstrap.php which early-returns when wpc_protocol_version='v1',
        // and PHP's require_once won't re-fire the bootstrap after WP's boot
        // already touched it. Bypass: load the three v2 files in order.
        if (!defined('WPC_V2_LOADED')) {
            require_once __DIR__ . '/addons/v2/v2-capabilities.php';
            require_once __DIR__ . '/addons/v2/v2-client.php';
            require_once __DIR__ . '/addons/v2/v2-callback.php';
            if (!defined('WPC_V2_LOADED')) define('WPC_V2_LOADED', true);
        }

        if (!empty($assoc['orchestrator'])) {
            $url = rtrim((string) $assoc['orchestrator'], '/');
            add_filter('wpc_v2_orchestrator_url', function () use ($url) { return $url; });
            WP_CLI::log('Using orchestrator: ' . $url);
        }

        if (!function_exists('wpc_probe_orchestrator_capabilities')) {
            WP_CLI::error('v2 capabilities probe not loaded — check wpc_protocol_version setting');
        }
        $caps = wpc_probe_orchestrator_capabilities(true);
        WP_CLI::log(json_encode($caps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (empty($caps['v2_optimize'])) {
            WP_CLI::warning('Orchestrator does NOT advertise v2_optimize:true. Plugin would fall back to v1.');
        } else {
            WP_CLI::success('v2_optimize: true — plugin would use v2 path.');
        }
    }

    /**
     * Fire a v2 /optimize-v2 POST against the configured orchestrator. Use
     * this for staging verification before the wp-admin Settings UI lands.
     *
     * Reuses the same compress flow scaffold as wps_ic_compress_live (sets
     * the in-flight transient, calls backup_all_sizes, POSTs to /optimize-v2,
     * applies Phase A response, reports timing). Phase B drains via the
     * /wp-json/wpc/v2/bg_swap callbacks as usual; this command returns the
     * moment Phase A response is parsed — bg-swaps continue asynchronously.
     *
     * ## OPTIONS
     *
     * <id>
     * : Attachment ID to compress.
     *
     * [--orchestrator=<url>]
     * : Override orchestrator URL.
     *
     * [--level=<level>]
     * : intelligent | intelligent+ | lossless | lossless+ | ultra (default: intelligent).
     *
     * [--source-mode=<mode>]
     * : inline | url (default: inline for files <=18 MB raw).
     *
     * ## EXAMPLES
     *
     *     wp wpcompress v2-test 113 --orchestrator=http://local-mc-v2.zapwp.net:443
     */
    public function v2_test($args, $assoc)
    {
        if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
        require_once __DIR__ . '/wp-compress-core.php';
        if (!defined('WPC_V2_LOADED')) {
            update_option('wpc_protocol_version', 'auto');
            require_once __DIR__ . '/addons/v2/v2-bootstrap.php';
        }

        $imageID = isset($args[0]) ? (int) $args[0] : 0;
        if (!$imageID) WP_CLI::error('Usage: wp wpcompress v2-test <id>');

        if (!empty($assoc['orchestrator'])) {
            $url = rtrim((string) $assoc['orchestrator'], '/');
            add_filter('wpc_v2_orchestrator_url', function () use ($url) { return $url; });
            WP_CLI::log('Using orchestrator: ' . $url);
        }

        $opts = get_option('wps_ic_options');
        $apikey = is_array($opts) && !empty($opts['api_key']) ? (string) $opts['api_key'] : '';
        if ($apikey === '') WP_CLI::error('No apikey configured in wps_ic_options');

        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($orch_url === '') WP_CLI::error('Could not resolve orchestrator URL');
        WP_CLI::log('Orchestrator: ' . $orch_url);

        $client = new WPS_LocalV2($apikey, $orch_url);

        // Variants — WP default sub-sizes + scaled + original. One marked parent.
        $meta = wp_get_attachment_metadata($imageID);
        $variants = [];
        if (is_array($meta) && !empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $label => $info) {
                $variants[] = [
                    'sizeLabel' => (string) $label,
                    'maxWidth'  => isset($info['width']) ? (int) $info['width'] : 0,
                    'maxHeight' => isset($info['height']) ? (int) $info['height'] : 0,
                    'crop'      => ($label === 'thumbnail'),
                ];
            }
        }
        $variants[] = ['sizeLabel' => 'scaled', 'maxWidth' => 2560, 'crop' => false, 'parent' => true];
        $variants[] = ['sizeLabel' => 'original', 'maxWidth' => null, 'crop' => false];

        $options = [
            'level'          => $assoc['level'] ?? 'intelligent',
            'formats'        => ['jpeg', 'webp', 'avif'],
            'triggerContext' => 'wpcli-v2-test',
            'callback_url'   => rest_url('wpc/v2/bg_swap'),
        ];
        if (isset($assoc['source-mode']) && $assoc['source-mode'] === 'url') {
            $options['force_url_source'] = true;
        }

        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);

        $t0 = microtime(true);
        $result = $client->optimize($imageID, $variants, $options);
        $wall_ms = (int) round((microtime(true) - $t0) * 1000);

        WP_CLI::log('Phase A wall: ' . $wall_ms . ' ms');
        WP_CLI::log('Result: ' . wp_json_encode([
            'ok'    => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
            'jobId' => $result['jobId'] ?? null,
            'variants_written' => isset($result['write']['variants_written']) ? $result['write']['variants_written'] : [],
        ], JSON_PRETTY_PRINT));

        if (empty($result['ok'])) {
            WP_CLI::warning('v2 optimize failed: ' . ($result['error'] ?? 'unknown'));
            return;
        }

        $cnt = count(get_post_meta($imageID, 'ic_local_variants', true) ?: []);
        $sav = get_post_meta($imageID, 'ic_savings', true);
        $status = get_post_meta($imageID, 'ic_status', true);
        WP_CLI::success(sprintf(
            'imageID=%d status=%s variants=%d savings=%s%% jobId=%s — bg-swap drain continues async',
            $imageID, $status, $cnt, $sav, $result['jobId'] ?? '-'
        ));
        WP_CLI::log('Tail debug.log for [WPC V2BgSwap ACK] entries to watch Phase B drain.');
    }

    /**
     * Print FPM telemetry stats — heartbeat + bg_swap batch handler timings
     * captured in the rolling 200-entry transient. Use `enable` and `disable`
     * subcommands to toggle capture. `clear` empties the buffer.
     *
     * ## OPTIONS
     *
     * [<subcommand>]
     * : enable | disable | clear | show (default)
     *
     * ## EXAMPLES
     *
     *     wp wpcompress fpm-stats enable
     *     wp wpcompress fpm-stats
     *     wp wpcompress fpm-stats clear
     */
    public function fpm_stats($args, $assoc)
    {
        // In wp-cli context, wp-compress-core.php is NOT loaded
        // (wp-compress.php only includes it for non-cron/non-CLI/non-REST).
        // That means v2-bootstrap.php → v2-telemetry.php isn't pulled in
        // automatically. Force-load on demand so this command works in CLI
        // without requiring an admin/REST request first.
        $telemetry_file = __DIR__ . '/addons/v2/v2-telemetry.php';
        if (!function_exists('wpc_v2_telemetry_stats') && is_readable($telemetry_file)) {
            require_once $telemetry_file;
        }

        $sub = isset($args[0]) ? (string) $args[0] : 'show';

        if ($sub === 'enable') {
            update_option('wpc_v2_telemetry_enabled', 1);
            WP_CLI::success('FPM telemetry capture: ENABLED. Next heartbeat + bg_swap batch will be recorded.');
            return;
        }
        if ($sub === 'disable') {
            update_option('wpc_v2_telemetry_enabled', 0);
            WP_CLI::success('FPM telemetry capture: DISABLED.');
            return;
        }
        if ($sub === 'clear') {
            if (function_exists('wpc_v2_telemetry_clear')) {
                wpc_v2_telemetry_clear();
                WP_CLI::success('FPM telemetry buffer cleared.');
            } else {
                WP_CLI::warning('Telemetry helper not loaded — plugin file missing?');
            }
            return;
        }
        // show (default)
        if (!function_exists('wpc_v2_telemetry_stats')) {
            WP_CLI::warning('Telemetry helper not loaded — plugin file missing?');
            return;
        }
        $stats = wpc_v2_telemetry_stats();
        WP_CLI::log(wpc_v2_telemetry_format_stats($stats));
    }
}

WP_CLI::add_command('wpcompress v2-capabilities', ['WPC_CLI_V2_Command', 'v2_capabilities']);
WP_CLI::add_command('wpcompress v2-test',         ['WPC_CLI_V2_Command', 'v2_test']);
WP_CLI::add_command('wpcompress fpm-stats',       ['WPC_CLI_V2_Command', 'fpm_stats']);

}

// Register the class so `wp wpcompress` shows the subcommand list,
// and add explicit hyphenated aliases so `wp wpcompress <subcommand>` works
// (PHP method names can't have hyphens, so the default mapping is underscore).
WP_CLI::add_command('wpcompress', 'WPC_CLI_Command');
WP_CLI::add_command('wpcompress backfill-avif', ['WPC_CLI_Command', 'backfill_avif']);
WP_CLI::add_command('wpcompress purge-variants', ['WPC_CLI_Command', 'purge_variants']);
