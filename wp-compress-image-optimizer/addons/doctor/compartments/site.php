<?php
/*
 * The site compartment: where every ticket starts. "Which build, which host, which theme and
 * builder, is the page cache wired in, can the plugin write its folders, is the box under load."
 *
 * The build and what the site runs (wpc_site_facts(), the same reader getSettings() answers the
 * agency portal with); whether the page cache is wired: WP_CACHE at runtime, what the wp-config.php
 * WordPress loads says about it (its path from wpc_wp_config_path(), the file matched for the one
 * line and never printed), the last verdict of the WP_CACHE writer, and whether
 * wp-content/advanced-cache.php is present and ours; whether the cache and critical roots are
 * writable; safe mode and the warm pause; the box: cores, load, memory, opcache, the WP-Cron
 * backlog, the object cache. Everything is read; the pressure governor itself is not called,
 * because it writes a receipt when the box is hot, so its rule is applied here to what it reads.
 */
if (!class_exists('wps_ic_doctor_site')) {
    class wps_ic_doctor_site extends wps_ic_doctor_compartment
    {
        const NAME = 'site';
        const TITLE = 'Site';
        const SYMPTOMS = 'which build and host; is the page cache wired in; can the plugin write its folders; is the box under load';
        const EVENTS = ['plugin-file-gutted', 'opcache-full', 'meta-write-fail', 'mc-breaker-trip', 'pressure-', 'rail-', 'wpcache-verdict'];

        /** The marker the plugin's drop-in defines (templates/samples/advancedCacheSample.php). */
        const DROPIN_MARKER = 'WP_COMPRESS_ADVANCED_CACHE';

        public function collect(wps_ic_doctor_context $ctx)
        {
            $facts = [];
            $now = $ctx->now;

            $facts[] = self::fact('plugin', [
                'version'     => defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : 'unknown',
                'folder'      => defined('WPS_IC_DIR') ? basename(rtrim(str_replace('\\', '/', WPS_IC_DIR), '/')) : 'unknown',
                'white_label' => class_exists('whtlbl_whitelabel_plugin'),
                'brand'       => function_exists('wpc_brand_name') ? wpc_brand_name() : 'WP Compress',
            ], 'const:WPC_PLUGIN_VERSION, WPS_IC_DIR; class:whtlbl_whitelabel_plugin');
            $facts[] = self::fact('site', [
                'home'      => function_exists('home_url') ? (string) home_url('/') : '',
                'multisite' => function_exists('is_multisite') && is_multisite(),
                'sapi'      => PHP_SAPI,
            ], 'fn:home_url, is_multisite');
            $siteFacts = function_exists('wpc_site_facts') ? wpc_site_facts(true) : null;
            if ($siteFacts === null) {
                $facts[] = self::fact('runs', self::unavailable('wpc_site_facts'), 'fn:wpc_site_facts');
            } else {
                $facts[] = self::fact('server', $siteFacts['server_info'], 'fn:wpc_site_facts');
                $facts[] = self::fact('theme', $siteFacts['active_theme'], 'fn:wpc_site_facts');
                $facts[] = self::fact('active plugins', $siteFacts['active_plugins'], 'fn:wpc_site_facts (option:active_plugins)');
            }

            // The page cache wiring.
            $cacheOn = !empty($ctx->settings['cache']['advanced']) && (string) $ctx->settings['cache']['advanced'] !== '0';
            $wpCacheRuntime = defined('WP_CACHE') && WP_CACHE;
            $configPath = function_exists('wpc_wp_config_path') ? wpc_wp_config_path() : null;
            $configSays = self::config_says($configPath);
            $dropinPath = defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/') . '/advanced-cache.php' : '';
            $dropin = self::file_state($dropinPath, $now);
            $dropinOurs = $dropin !== null && strpos(self::read_text($dropinPath, 4096), self::DROPIN_MARKER) !== false;
            $verdictRecord = get_option('wpc_wp_cache_constant_verdict', []);
            $facts[] = self::fact('page cache (setting)', $cacheOn ? 'on' : 'off', 'option:' . WPS_IC_SETTINGS . '.cache.advanced');
            $facts[] = self::fact('WP_CACHE at runtime', $wpCacheRuntime, 'const:WP_CACHE');
            $facts[] = self::fact('wp-config.php', [
                'path'     => $configPath === null ? self::unavailable('wpc_wp_config_path') : ($configPath === '' ? 'none found where WordPress looks' : $configPath),
                'wp_cache' => $configSays,
            ], 'fn:wpc_wp_config_path; file matched for the WP_CACHE line only');
            $facts[] = self::fact('last WP_CACHE writer verdict', is_array($verdictRecord) && $verdictRecord ? [
                'verdict' => (string) ($verdictRecord['verdict'] ?? ''),
                'path'    => (string) ($verdictRecord['path'] ?? ''),
                'at'      => self::at((int) ($verdictRecord['at'] ?? 0)),
            ] : 'none recorded', 'option:wpc_wp_cache_constant_verdict');
            $facts[] = self::fact('advanced-cache.php', $dropin === null ? 'absent' : [
                'bytes'  => $dropin['bytes'],
                'mtime'  => self::at($dropin['mtime']),
                'ours'   => $dropinOurs,
                'loaded' => defined(self::DROPIN_MARKER),
            ], 'file:advanced-cache.php (ours = defines ' . self::DROPIN_MARKER . ')');

            // The folders the plugin writes.
            $folders = [];
            $unwritable = [];
            foreach (['cache' => defined('WPS_IC_CACHE') ? WPS_IC_CACHE : '', 'critical' => defined('WPS_IC_CRITICAL') ? WPS_IC_CRITICAL : ''] as $name => $dir) {
                $exists = $dir !== '' && @is_dir($dir);
                $writable = $exists && @is_writable($dir);
                $folders[$name] = ['path' => self::relative($dir), 'exists' => $exists, 'writable' => $writable];
                if (!$writable) {
                    $unwritable[] = $name . ($exists ? '' : ' (missing)');
                }
            }
            $facts[] = self::fact('plugin folders', $folders, 'const:WPS_IC_CACHE, WPS_IC_CRITICAL; fn:is_writable');

            // The operator levers.
            $safeMode = function_exists('wpc_safe_mode') ? wpc_safe_mode() : (bool) get_option('wpc_safe_mode', 0);
            $safeSince = (int) get_option('wpc_safe_mode_at', 0);
            $facts[] = self::fact('safe mode (every background lane stands down, every render is shed)', $safeMode ? ['on' => true, 'since' => self::at($safeSince), 'source' => function_exists('wpc_safe_mode_source') ? wpc_safe_mode_source() : ''] : false, 'fn:wpc_safe_mode, wpc_safe_mode_source; option:wpc_safe_mode_at');
            $warmOn = (bool) get_option('wpc_url_warm_on_purge', 1);
            $facts[] = self::fact('warm scheduler', $warmOn ? 'enabled' : ['paused' => true, 'since' => self::at((int) get_option('wpc_warm_paused_at', 0))], 'option:wpc_url_warm_on_purge, wpc_warm_paused_at');

            // The box.
            $box = self::box($now);
            $facts[] = self::fact('box', $box['box'], 'fn:wpc_box_cores, wpc_is_core_count_known, sys_getloadavg, wpc_memory_pressure');
            $facts[] = self::fact('opcache', $box['opcache'], 'ini:opcache.*; fn:opcache_get_status');
            $facts[] = self::fact('WP-Cron backlog', $box['cron'], 'fn:_get_cron_array; const:DISABLE_WP_CRON');
            $facts[] = self::fact('object cache', function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() ? 'external' : 'none: every transient is an options-table row', 'fn:wp_using_ext_object_cache');
            $facts[] = self::fact('job rail', function_exists('wpc_rail_on') ? (wpc_rail_on() ? 'on' : 'off') : self::unavailable('wpc_rail_on'), 'fn:wpc_rail_on');
            $gutted = self::last_entry($ctx, 'plugin-file-gutted');
            $facts[] = self::fact('plugin file gutted', $gutted === null ? 'none in the window' : ['at' => self::at((int) ($gutted['t'] ?? 0)), 'layers' => $gutted['layers'] ?? []], 'receipt:plugin-file-gutted');

            if ($gutted !== null) {
                $verdict = self::verdict('fail', 'plugin-file-gutted', 'a plugin file was found emptied at ' . self::at((int) ($gutted['t'] ?? 0)) . ': reinstall the plugin');
            } elseif ($cacheOn && (!$wpCacheRuntime || $dropin === null || !$dropinOurs)) {
                $why = !$wpCacheRuntime ? 'WP_CACHE is not true (the wp-config.php WordPress loads: ' . ($configPath ? 'WP_CACHE ' . $configSays : 'none found') . ')'
                    : ($dropin === null ? 'advanced-cache.php is missing' : 'advanced-cache.php is not the plugin\'s');
                $verdict = self::verdict('fail', 'cache-not-wired', 'the page cache is on but ' . $why . ': cached pages are served only after WordPress and every plugin load, or not at all');
            } elseif ($unwritable) {
                $verdict = self::verdict('fail', 'folder-not-writable', 'the plugin cannot write ' . implode(', ', $unwritable) . ': no page copy or critical CSS can be stored');
            } elseif ($safeMode) {
                $verdict = self::verdict('warn', 'safe-mode', 'safe mode is on' . ($safeSince > 0 ? ' since ' . self::at($safeSince) : '') . ': every background lane stands down and every render is shed');
            } elseif ($box['hot'] !== '') {
                $verdict = self::verdict('warn', 'pressure', $box['hot'] . ': background work defers and renders may be shed');
            } elseif ($box['opcache']['state'] !== 'on') {
                $verdict = self::verdict('warn', 'opcache-' . $box['opcache']['state'], 'opcache is ' . $box['opcache']['state'] . ': every request compiles every PHP file');
            } else {
                $verdict = self::verdict('ok', 'ok', 'WP Compress ' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '') . ($cacheOn ? ', page cache wired' : ', page cache off') . ', folders writable, no pressure');
            }
            return ['verdict' => $verdict, 'facts' => $facts, 'artifacts' => array_values(array_filter([$dropin]))];
        }

        /**
         * What a wp-config.php says about WP_CACHE: 'true', 'false', 'absent', 'unreadable' or
         * 'no file'. Only the define is matched; nothing of the file enters the report.
         */
        private static function config_says($path)
        {
            if ((string) $path === '' || !@is_file($path)) {
                return 'no file';
            }
            $contents = @file_get_contents($path, false, null, 0, 262144);
            if (!is_string($contents)) {
                return 'unreadable';
            }
            if (!preg_match('/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\)/i', $contents, $m)) {
                return 'absent';
            }
            return strtolower($m[1]);
        }

        /**
         * The box as wpc_box_debug_report() reads it, bounded: cores and load, memory, opcache,
         * the WP-Cron backlog. 'hot' names the governor's rule when it holds (memory near the
         * ceiling, or load above the threshold times known cores), '' otherwise.
         */
        private static function box($now)
        {
            $cores = function_exists('wpc_box_cores') ? (int) wpc_box_cores() : 0;
            $coresKnown = function_exists('wpc_is_core_count_known') ? (bool) wpc_is_core_count_known() : false;
            $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
            $memoryHot = function_exists('wpc_memory_pressure') ? (bool) wpc_memory_pressure() : false;
            $threshold = (float) apply_filters('wpc_pressure_threshold', 1.5);
            $hot = '';
            if ($memoryHot) {
                $hot = 'PHP is near its memory ceiling';
            } elseif (is_array($load) && isset($load[0]) && $coresKnown && $cores > 0 && (float) $load[0] > $cores * $threshold) {
                $hot = sprintf('load %.2f on %d cores (over %.1f per core)', (float) $load[0], $cores, $threshold);
            }
            $box = [
                'cores'        => $cores > 0 ? $cores : 'unknown',
                'cores_known'  => $coresKnown,
                'load'         => is_array($load) ? array_map(function ($value) { return round((float) $value, 2); }, array_slice($load, 0, 3)) : 'unavailable',
                'memory_limit' => (string) ini_get('memory_limit'),
                'memory_used'  => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
                'memory_hot'   => $memoryHot,
            ];

            $extension = extension_loaded('Zend OPcache') || extension_loaded('opcache');
            $enabled = in_array(strtolower((string) ini_get('opcache.enable')), ['1', 'on'], true);
            $opcache = ['state' => $extension ? ($enabled ? 'on' : 'disabled') : 'absent', 'restrict_api' => (string) ini_get('opcache.restrict_api') !== ''];
            if ($extension && function_exists('opcache_get_status')) {
                $status = @opcache_get_status(false);
                if (is_array($status)) {
                    $opcache['cache_full'] = !empty($status['cache_full']);
                    $opcache['oom_restarts'] = (int) ($status['opcache_statistics']['oom_restarts'] ?? 0);
                    $opcache['used_mb'] = (int) round((int) ($status['memory_usage']['used_memory'] ?? 0) / 1048576);
                    $opcache['free_mb'] = (int) round((int) ($status['memory_usage']['free_memory'] ?? 0) / 1048576);
                }
            }

            $events = 0;
            $overdue = 0;
            $oursOverdue = 0;
            $oldest = 0;
            $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
            foreach (is_array($crons) ? $crons : [] as $time => $hooks) {
                foreach (is_array($hooks) ? $hooks : [] as $hook => $instances) {
                    $n = is_array($instances) ? count($instances) : 1;
                    $events += $n;
                    if ((int) $time <= $now) {
                        $overdue += $n;
                        if (strpos((string) $hook, 'wpc') === 0 || strpos((string) $hook, 'wps_ic') === 0) {
                            $oursOverdue += $n;
                        }
                        $oldest = $oldest === 0 ? (int) $time : min($oldest, (int) $time);
                    }
                }
            }
            $cron = [
                'events'           => $events,
                'overdue'          => $overdue,
                'overdue_ours'     => $oursOverdue,
                'oldest_overdue_s' => $oldest > 0 ? $now - $oldest : 0,
                'disable_wp_cron'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            ];
            return ['box' => $box, 'opcache' => $opcache, 'cron' => $cron, 'hot' => $hot];
        }
    }
}
