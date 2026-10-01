<?php


if (!defined('WP_CLI') || !WP_CLI) return;
if (!class_exists('WP_CLI_Command')) return;

class WPC_CLI_Command extends WP_CLI_Command
{

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
     * Print the plugin log's raw lines (JSONL, oldest first).
     *
     * ## OPTIONS
     *
     * [--since=<unix-time>]
     * : Only entries written at or after this time.
     *
     * [--lines=<n>]
     * : At most this many, the newest. Default 500.
     *
     * The log is wp-content/cache/wp-cio/wpc-cflog.php, which answers nothing over HTTP.
     */
    public function cflog($args, $assoc)
    {
        if (!function_exists('wpc_cflog_lines')) {
            WP_CLI::error('The log reader is not loaded.');
        }
        foreach (wpc_cflog_lines(isset($assoc['lines']) ? (int) $assoc['lines'] : 500, isset($assoc['since']) ? (int) $assoc['since'] : 0) as $line) {
            WP_CLI::line($line);
        }
    }

    /**
     * What each part of the plugin decided for a page, and why, read from the state it keeps and
     * the receipts it writes. Writes nothing; no network call without --probe.
     *
     * ## OPTIONS
     *
     * [<compartment>...]
     * : site, cache, crit, render, logs. Default: all of them.
     *
     * [--url=<url>]
     * : A page of this site, absolute or as a path. Default: the homepage.
     *
     * [--probe]
     * : Also fetch the page as a logged-out visitor. It may store a page copy exactly as a visit would.
     *
     * [--hours=<n>]
     * : The journal window in hours. Default 24.
     *
     * [--device=<device>]
     * : desktop or mobile. Default: both.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * [--bundle]
     * : A ticket bundle on stdout (JSON): every compartment plus every journal entry of the window.
     */
    public function doctor($args, $assoc)
    {
        // Under WP-CLI the plugin core is gated out (wp-compress.php) and the cron file loads
        // defines.php, warm.php and the url key class. Booting the core here would run its
        // request-time boot, which can write; the doctor loads declarations only: its own files
        // and rewriteLogic.php (a class file that runs nothing at load) for the crit payload check.
        // Any other owner that is missing reads "unavailable" in the report.
        if (!class_exists('wps_ic_url_key') && file_exists(__DIR__ . '/traits/url_key.php')) {
            require_once __DIR__ . '/traits/url_key.php';
        }
        if (!class_exists('wps_rewriteLogic') && file_exists(__DIR__ . '/addons/cdn/rewriteLogic.php')) {
            require_once __DIR__ . '/addons/cdn/rewriteLogic.php';
        }
        if (!function_exists('wpc_doctor_run')) {
            require_once __DIR__ . '/addons/doctor/doctor.php';
        }
        // --url is WP-CLI's own global parameter: WP-CLI takes it before the command sees its
        // arguments (on the rig, --url=/about/ reached the command as nothing and the report was
        // the homepage's), so the page is read from WP-CLI's config.
        $assoc = (array) $assoc;
        if (!isset($assoc['url'])) {
            $assoc['url'] = (string) WP_CLI::get_config('url');
        }
        wpc_doctor_cli((array) $args, $assoc);
    }

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


if (!class_exists('WPC_CLI_V2_Command')) {

class WPC_CLI_V2_Command extends WP_CLI_Command
{

    public function v2_capabilities($args, $assoc)
    {
        if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
        require_once __DIR__ . '/wp-compress-core.php';


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
            WP_CLI::error('v2 capabilities probe not loaded — addons/v2/v2-capabilities.php is missing');
        }
        $caps = wpc_probe_orchestrator_capabilities(true);
        WP_CLI::log(json_encode($caps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (empty($caps['v2_optimize'])) {
            WP_CLI::warning('Orchestrator does NOT advertise v2_optimize:true. Plugin would fall back to v1.');
        } else {
            WP_CLI::success('v2_optimize: true — plugin would use v2 path.');
        }
    }


    public function v2_test($args, $assoc)
    {
        if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
        require_once __DIR__ . '/wp-compress-core.php';
        if (!defined('WPC_V2_LOADED')) {
            require_once __DIR__ . '/addons/v2/v2-bootstrap.php';
        }

        $imageID = isset($args[0]) ? (int) $args[0] : 0;
        if (!$imageID) WP_CLI::error('Usage: wp wpcompress v2-test <id>');

        if (!empty($assoc['orchestrator'])) {
            $url = rtrim((string) $assoc['orchestrator'], '/');
            add_filter('wpc_v2_orchestrator_url', function () use ($url) { return $url; });
            WP_CLI::log('Using orchestrator: ' . $url);
        }

        // The same door the Compress button uses, so the test sends the envelope production sends.
        $options = ['triggerContext' => 'wpcli-v2-test'];
        if (!empty($assoc['level'])) {
            $options['level'] = (string) $assoc['level'];
        }
        if (isset($assoc['source-mode']) && $assoc['source-mode'] === 'url') {
            $options['force_url_source'] = true;
        }

        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);

        $result = wps_ic_image_optimize::dispatch($imageID, 'cli', $options);
        $wall_ms = (int) ($result['wall_ms'] ?? 0);

        WP_CLI::log('Phase A wall: ' . $wall_ms . ' ms');
        WP_CLI::log('Result: ' . wp_json_encode([
            'ok'    => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
            'jobId' => $result['jobId'] ?? null,
            'variants_written' => $result['variants_written'] ?? [],
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
     * The image doctor, read-only: why an image is or is not compressed, and what this site's
     * folders, orchestrator and loopback answer (classes/image_doctor.class.php).
     *
     * ## OPTIONS
     *
     * [--ids=<ids>]
     * : Comma-separated attachment ids.
     *
     * [--parked]
     * : The images on the parked list.
     *
     * [--status=<status>]
     * : The images whose ic_status is this value.
     *
     * [--limit=<n>]
     * : At most this many images. Default 200.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * [--site-only]
     * : Only the site block.
     *
     * [--export]
     * : The image lane's state for the selected images as one JSON document.
     */
    public function image_doctor($args, $assoc)
    {
        if (!defined('WPC_CC_PLUGIN_FILE')) define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
        require_once __DIR__ . '/wp-compress-core.php';
        if (!defined('WPC_V2_LOADED')) {
            require_once __DIR__ . '/addons/v2/v2-bootstrap.php';
        }
        wps_ic_image_doctor::cli($args, $assoc);
    }

    public function fpm_stats($args, $assoc)
    {


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
WP_CLI::add_command('wpcompress image-doctor',    ['WPC_CLI_V2_Command', 'image_doctor']);

}


WP_CLI::add_command('wpcompress', 'WPC_CLI_Command');
WP_CLI::add_command('wpcompress backfill-avif', ['WPC_CLI_Command', 'backfill_avif']);
WP_CLI::add_command('wpcompress purge-variants', ['WPC_CLI_Command', 'purge_variants']);
WP_CLI::add_command('wpcompress cflog', ['WPC_CLI_Command', 'cflog']);
WP_CLI::add_command('wpcompress doctor', ['WPC_CLI_Command', 'doctor']);
