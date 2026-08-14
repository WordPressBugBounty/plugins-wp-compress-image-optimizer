<?php
if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('wpc_db_health_report')) {
    function wpc_db_health_gate()
    {
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }
        $key = isset($_GET['key']) ? (string) $_GET['key'] : (isset($_POST['key']) ? (string) $_POST['key'] : '');
        if ($key === '') {
            return false;
        }
        $opts = function_exists('get_option') ? get_option(WPS_IC_OPTIONS) : [];
        $api  = (is_array($opts) && !empty($opts['api_key'])) ? (string) $opts['api_key'] : '';
        return ($api !== '' && hash_equals($api, $key));
    }

    function wpc_db_health_report()
    {
        global $wpdb;
        $out = ['generated' => gmdate('c'), 'plugin_version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?'];

        
        $root = defined('ABSPATH') ? ABSPATH : '/';
        $tot  = @disk_total_space($root);
        $free = @disk_free_space($root);
        $used_pct = ($tot && $tot > 0) ? round(($tot - $free) / $tot * 100, 1) : null;
        $out['disk'] = [
            'total_gb'  => $tot ? round($tot / 1073741824, 1) : null,
            'free_gb'   => $free ? round($free / 1073741824, 2) : null,
            'used_pct'  => $used_pct,
        ];

        
        $gv = function ($name) use ($wpdb) {
            $v = $wpdb->get_var("SELECT @@GLOBAL." . preg_replace('/[^a-z_]/', '', $name));
            return ($v === null) ? null : (string) $v;
        };
        $log_bin = $gv('log_bin');
        $expire  = $gv('binlog_expire_logs_seconds');
        $binlogs = @$wpdb->get_results("SHOW BINARY LOGS", ARRAY_A);
        $bl_total = 0; $bl_count = 0; $bl_note = '';
        if (is_array($binlogs)) {
            $bl_count = count($binlogs);
            foreach ($binlogs as $b) { $bl_total += (int) ($b['File_size'] ?? 0); }
        } else {
            $bl_note = 'SHOW BINARY LOGS unavailable (DB user lacks REPLICATION CLIENT priv) — check size via ls /var/lib/mysql/binlog.*';
        }
        $out['binlog'] = [
            'enabled'               => (strtolower((string) $log_bin) === 'on' || $log_bin === '1'),
            'expire_logs_seconds'   => ($expire === null ? null : (int) $expire),
            'expire_days'           => ($expire === null || (int) $expire === 0) ? null : round((int) $expire / 86400, 1),
            'max_binlog_size'       => $gv('max_binlog_size'),
            'format'                => $gv('binlog_format'),
            'file_count'            => $bl_count,
            'total_gb'              => $bl_total ? round($bl_total / 1073741824, 2) : null,
            'note'                  => $bl_note,
        ];


        $dev    = get_option('wps_ic_development');
        $wpcver = get_option('wpc_version');
        $ver_is_ts = ($wpcver !== false) && preg_match('/^\d{9,}$/', (string) $wpcver);
        $out['plugin_drift'] = [
            'wps_ic_development' => ($dev === false ? '(unset)' : $dev),
            'wpc_version'        => ($wpcver === false ? '(unset)' : $wpcver),
            'version_is_timestamp' => (bool) $ver_is_ts,
            'settings_initialized' => (get_option('wpc_settings_initialized') === '1'),
        ];

        
        $schema = defined('DB_NAME') ? DB_NAME : '';
        $out['db_total_gb'] = round((float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=%s", $schema
        )) / 1073741824, 2);
        $out['top_tables'] = $wpdb->get_results($wpdb->prepare(
            "SELECT table_name, ROUND((data_length+index_length)/1048576,1) AS mb, table_rows
             FROM information_schema.tables WHERE table_schema=%s
             ORDER BY (data_length+index_length) DESC LIMIT 12", $schema
        ), ARRAY_A);

        
        $alloptions = (int) $wpdb->get_var("SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload='yes'");
        $preloaded  = get_option('wpc-ic-preloaded-pages');
        $out['options_bloat'] = [
            'alloptions_kb'    => round($alloptions / 1024, 1),
            'transients'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'"),
            'wpc_dedup_rows'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpc_v2_dedup%'"),
            'preloaded_pages'  => is_array($preloaded) ? count($preloaded) : 0,
        ];


        $reasons = [];
        $binlog_capped = (!$out['binlog']['enabled'])
            || ($out['binlog']['expire_logs_seconds'] !== null && $out['binlog']['expire_logs_seconds'] > 0 && $out['binlog']['expire_logs_seconds'] <= 7 * 86400);
        if (!$binlog_capped) {
            $reasons[] = 'Binary logs are ON with no (or too-long) expiry — they can pile up unbounded. Set binlog_expire_logs_seconds (≤3d) or skip-log-bin.';
        }
        $storming = ($dev === 'true') || $ver_is_ts;
        if ($storming) {
            $reasons[] = 'Plugin drift storm ACTIVE (dev flag on / version is a timestamp) — writing options on every request, feeding the binlogs. Fix: wp option delete wps_ic_development + install >=7.10.77.';
        }
        $disk_ok = ($used_pct !== null && $used_pct < 85);
        if (!$disk_ok) {
            $reasons[] = 'Disk is ' . ($used_pct === null ? 'unknown' : $used_pct . '%') . ' full — reclaim space (purge binlogs) before it hits 100% and crashes MySQL.';
        }
        $out['verdict'] = [
            'overall'        => ($binlog_capped && !$storming && $disk_ok) ? 'SAFE' : 'AT RISK',
            'binlog_capped'  => $binlog_capped,
            'plugin_storming' => $storming,
            'disk_ok'        => $disk_ok,
            'reasons'        => $reasons,
        ];

        return $out;
    }

    $wpc_db_health_handler = function () {
        if (!wpc_db_health_gate()) {
            status_header(403);
            header('Content-Type: application/json');
            echo wp_json_encode(['error' => 'forbidden — log in as admin or append &key=<WPC api key>']);
            exit;
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo wp_json_encode(wpc_db_health_report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    };
    add_action('wp_ajax_wpc_db_health', $wpc_db_health_handler);
    add_action('wp_ajax_nopriv_wpc_db_health', $wpc_db_health_handler);
}
