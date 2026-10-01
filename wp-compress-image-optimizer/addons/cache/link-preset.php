<?php
if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('wpc_detect_foreign_page_cache')) {


    function wpc_detect_foreign_page_cache()
    {
        $sigs = [
            'wp-rocket'        => 'WP_ROCKET_VERSION',
            'litespeed-cache'  => 'LSCWP_V',
            'w3-total-cache'   => 'W3TC',
            'wp-super-cache'   => 'WPCACHEHOME',
            'breeze'           => 'BREEZE_VERSION',
            'wp-fastest-cache' => 'WPFC_MAIN_PATH',
            'cache-enabler'    => 'CACHE_ENABLER_VERSION',
            'comet-cache'      => 'COMET_CACHE_PLUGIN_FILE',
            'hummingbird'      => 'WPHB_VERSION',
            'wp-optimize'      => 'WPO_VERSION',
            'swift-performance' => 'SWIFT_PERFORMANCE_VER',
            'nitropack'        => 'NITROPACK_VERSION',
        ];
        foreach ($sigs as $name => $const) {
            if (defined($const)) {
                return $name;
            }
        }
        if (class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher')) {
            return 'sg-optimizer';
        }
        // A non-WPC advanced-cache.php drop-in = another full-page cache owns the drop-in.
        if (defined('WP_CACHE') && WP_CACHE && defined('WP_CONTENT_DIR') && @is_readable(WP_CONTENT_DIR . '/advanced-cache.php')) {
            $head = @file_get_contents(WP_CONTENT_DIR . '/advanced-cache.php', false, null, 0, 4096);
            if (is_string($head) && $head !== ''
                && stripos($head, 'wp-compress') === false
                && stripos($head, 'wps_ic') === false
                && stripos($head, 'wpc_') === false
                && stripos($head, 'advancedCache') === false) {
                return 'foreign-advanced-cache';
            }
        }
        return false;
    }
}

if (!function_exists('wpc_drop_preset_advanced_cache_if_foreign')) {
    // Never two page caches: every preset-shaped settings array passes through here before
    // being written. A foreign page cache owning the site drops cache.advanced to 0; the
    // user's explicit Advanced Cache toggle is untouched (it does not route through presets).
    function wpc_drop_preset_advanced_cache_if_foreign($settings)
    {
        if (!apply_filters('wpc_preset_cache_gate', true)) {
            return $settings;
        }
        if (!is_array($settings) || empty($settings['cache']['advanced'])) {
            return $settings;
        }
        $foreign_cache = wpc_detect_foreign_page_cache();
        if ($foreign_cache === false) {
            return $settings;
        }
        $settings['cache']['advanced'] = 0;
        if (function_exists('wpc_link_preset_journal')) {
            wpc_link_preset_journal('cache-gate', ['foreign' => $foreign_cache]);
        }
        return $settings;
    }
}

if (!function_exists('wpc_font_localizer_present')) {


    function wpc_font_localizer_present()
    {
        static $localizer = null;
        if ($localizer !== null) {
            return apply_filters('wpc_font_localizer_present', $localizer);
        }
        $localizer = false;
        try {
            $localizer_slugs = apply_filters('wpc_font_localizer_slugs', [
                'omgf'               => 'host-webfonts-local',
                'local-google-fonts' => 'local-google-fonts',
                'embed-google-fonts' => 'embed-google-fonts',
                'omgf-pro'           => 'omgf-pro',
                'dp-divi-dsgvo'      => 'dp-divi-dsgvo',
            ]);
            $active_plugins = (array) get_option('active_plugins', []);
            if (function_exists('get_site_option')) {
                $active_plugins = array_merge($active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
            }
            foreach ($localizer_slugs as $localizer_name => $plugin_dir) {
                foreach ($active_plugins as $active_plugin) {
                    if (strpos((string) $active_plugin, (string) $plugin_dir . '/') === 0) {
                        $localizer = (string) $localizer_name;
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $localizer = false;
        }
        return apply_filters('wpc_font_localizer_present', $localizer);
    }
}

if (!function_exists('wpc_font_localizer_sheet')) {


    function wpc_font_localizer_sheet($tag)
    {
        if (!is_string($tag) || $tag === '') {
            return false;
        }
        if (!function_exists('wpc_font_localizer_present') || wpc_font_localizer_present() === false) {
            return false;
        }
        $sheet_tokens = apply_filters('wpc_font_localizer_sheet_tokens',
            ['omgf', 'local-google-fonts', 'embed-google-fonts', 'gfonts_local', 'dp-divi-dsgvo']);
        foreach ((array) $sheet_tokens as $token) {
            if ($token !== '' && stripos($tag, (string) $token) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_font_localizer_faces')) {

    // Option B of the font-map contract (service-accepted 2026-08-06): send the localizer's
    // @font-face inventory beside html/css in the dispatch. Match key on their side is
    // family + weight + style (+ unicode-range when present); same-origin remap only.
    function wpc_font_localizer_faces()
    {
        static $faces = null;
        if ($faces !== null) {
            return $faces;
        }
        $faces = [];
        try {
            if (!function_exists('wpc_font_localizer_present') || wpc_font_localizer_present() === false
                || !function_exists('wp_upload_dir') || !function_exists('home_url')) {
                return $faces;
            }
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir']) || empty($upload_dir['baseurl'])) {
                return $faces;
            }
            $home_host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            $localizer_dirs = apply_filters('wpc_font_localizer_dirs', ['omgf', 'local-google-fonts', 'embed-google-fonts']);
            $sheets = [];
            foreach ((array) $localizer_dirs as $localizer_dir) {
                $globbed = glob(rtrim((string) $upload_dir['basedir'], '/') . '/' . $localizer_dir . '/{*,*/*}.css', GLOB_BRACE);
                if (is_array($globbed)) {
                    $sheets = array_merge($sheets, array_slice($globbed, 0, 20));
                }
            }
            foreach (array_slice($sheets, 0, 20) as $sheet) {
                $css = (string) @file_get_contents($sheet);
                if ($css === '' || !preg_match_all('/@font-face\s*\{[^}]*\}/i', $css, $face_blocks)) {
                    continue;
                }
                foreach ($face_blocks[0] as $face_block) {
                    if (count($faces) >= (int) apply_filters('wpc_font_localizer_faces_max', 50)) {
                        break 2;
                    }
                    if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $face_block, $family_match)
                        || !preg_match('/src\s*:[^;}]*url\(\s*["\']?([^"\')\s]+)/i', $face_block, $src_match)) {
                        continue;
                    }
                    $font_url = (string) $src_match[1];
                    if (strpos($font_url, '//') === false) {
                        // Sheet-relative path -> absolute uploads URL beside the sheet.
                        $relative_dir = ltrim((string) substr(dirname($sheet), strlen(rtrim((string) $upload_dir['basedir'], '/'))), '/');
                        $font_url = rtrim((string) $upload_dir['baseurl'], '/') . '/' . ($relative_dir !== '' ? $relative_dir . '/' : '') . ltrim($font_url, './');
                    }
                    $font_host = strtolower((string) parse_url($font_url, PHP_URL_HOST));
                    if ($font_host !== '' && $font_host !== $home_host) {
                        continue; // same-origin only, per the contract
                    }
                    $face = ['family' => trim($family_match[1]), 'src' => $font_url];
                    if (preg_match('/font-weight\s*:\s*([^;}]+)/i', $face_block, $weight_match)) {
                        $face['weight'] = trim($weight_match[1]);
                    }
                    if (preg_match('/font-style\s*:\s*([a-z]+)/i', $face_block, $style_match)) {
                        $face['style'] = trim($style_match[1]);
                    }
                    if (preg_match('/unicode-range\s*:\s*([^;}]+)/i', $face_block, $range_match)) {
                        $face['unicode_range'] = trim($range_match[1]);
                    }
                    $faces[] = $face;
                }
            }
        } catch (\Throwable $e) {
            $faces = [];
        }
        return $faces;
    }
}

if (!function_exists('wpc_link_preset_journal')) {
    function wpc_link_preset_journal($event, $data = [])
    {
        $j = get_option('wpc_link_preset_journal');
        if (!is_array($j)) {
            $j = [];
        }
        $j[] = array_merge(['t' => time(), 'event' => $event], is_array($data) ? $data : ['data' => $data]);
        update_option('wpc_link_preset_journal', array_slice($j, -30), false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('link-preset', '', '', ['ev' => $event]);
        }
    }
}

if (!function_exists('wpc_link_preset_levers')) {


    function wpc_link_preset_levers()
    {
        return apply_filters('wpc_link_preset_levers', [
            'flat' => [
                'used-css'           => '1',
                'delay-js-v2'        => 1,
                'delay-js-v3'        => 1,
                'replace-fonts'      => 'local',
                'preload-crit-fonts' => '1',
                'font-display'       => 'smart',
            ],
            'critical_css' => 1,
            'advanced_cache' => 1,
        ]);
    }
}

if (!function_exists('wpc_apply_link_preset')) {
    // Non-destructive: sets each lever ONLY when it is currently unset/blank. Existing user values
    // always win. Returns ['applied'=>[], 'skipped'=>[]] or false if settings unavailable.
    function wpc_apply_link_preset($ctx = 'link')
    {
        if (!defined('WPS_IC_SETTINGS') || !function_exists('get_option')) {
            return false;
        }


        $wpc_last_apply = (int) get_option('wpc_link_preset_applied');
        if ($wpc_last_apply && (time() - $wpc_last_apply) < 120) {
            return ['applied' => [], 'skipped' => ['debounced' => 1]];
        }
        $wpc_fresh_link = ($wpc_last_apply === 0);
        $s = get_option(WPS_IC_SETTINGS);
        if (!is_array($s)) {
            $s = [];
        }
        $levers = wpc_link_preset_levers();
        $applied = [];
        $skipped = [];

        // Flat levers — set-if-unset.
        foreach ((array) $levers['flat'] as $k => $v) {
            if ($k === 'replace-fonts' && function_exists('wpc_font_localizer_present')) {
                $localizer = wpc_font_localizer_present();
                if ($localizer !== false) {
                    $skipped[$k] = 'localizer:' . $localizer;
                    continue;
                }
            }
            if (!isset($s[$k]) || $s[$k] === '' || $s[$k] === null) {
                $s[$k] = $v;
                $applied[$k] = $v;
            } else {
                $skipped[$k] = $s[$k];
            }
        }

        // Critical CSS (nested critical.css) — set the sub-key without touching siblings.
        if (!isset($s['critical']) || !is_array($s['critical'])) {
            $s['critical'] = [];
        }
        if (!isset($s['critical']['css'])) {
            $s['critical']['css'] = $levers['critical_css'];
            $applied['critical.css'] = $levers['critical_css'];
        } else {
            $skipped['critical.css'] = $s['critical']['css'];
        }

        // Advanced Cache (nested cache.advanced) — gated on NO foreign page cache. Never two caches.
        if (!isset($s['cache']) || !is_array($s['cache'])) {
            $s['cache'] = [];
        }
        if (!isset($s['cache']['advanced'])) {
            $foreign = wpc_detect_foreign_page_cache();
            if ($foreign === false) {
                $s['cache']['advanced'] = $levers['advanced_cache'];
                $applied['cache.advanced'] = $levers['advanced_cache'];
            } else {
                $skipped['cache.advanced'] = 'foreign:' . $foreign;
            }
        } else {
            $skipped['cache.advanced'] = $s['cache']['advanced'];
        }

        update_option(WPS_IC_SETTINGS, $s);
        update_option('wpc_settings_initialized', '1', false); // P4 latch
        update_option('wpc_link_preset_applied', time(), false);


        if (isset($applied['cache.advanced'])) {
            try {
                if (!class_exists('wps_ic_htaccess') && defined('WPS_IC_DIR')) {
                    include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                }
                if (class_exists('wps_ic_htaccess')) {
                    $wpc_h = new wps_ic_htaccess();
                    $wpc_h->setWPCache(true);
                    $wpc_h->setAdvancedCache();
                }
            } catch (\Throwable $e) {
            }
        }


        if ($wpc_fresh_link && get_option('wpc_install_fresh') === '1'
            && get_option('wpc_auto_mode') === false
            && apply_filters('wpc_link_auto_mode', true)) {
            update_option('wpc_auto_mode', '1', false);
            if (function_exists('wpc_auto_state') && function_exists('wpc_auto_state_save')) {
                $wpc_ast = wpc_auto_state();
                $wpc_ast['status'] = 'starting';
                $wpc_ast['arm_tries'] = 0; $wpc_ast['next_tick_at'] = 0;
                wpc_auto_state_save($wpc_ast);
            }
            if (function_exists('wpc_auto_journal')) {
                wpc_auto_journal('enabled', ['at' => 'link']);
            }
            if (function_exists('wpc_auto_schedule')) {
                wpc_auto_schedule('wpc_auto_mode_tick', 30, [1]);
            }
            if (function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }
        }

        if (function_exists('wpc_cohort_beacon')) {
            wpc_cohort_beacon('linked', ['ctx' => (string) $ctx]); // T0 for the tracker Δt columns
        }

        wpc_link_preset_journal('preset-apply', ['ctx' => (string) $ctx, 'applied' => $applied, 'skipped' => $skipped]);
        if (function_exists('wpc_cohort_beacon')) {
            wpc_cohort_beacon('preset_applied', ['applied' => array_keys($applied), 'skipped' => array_keys($skipped)]);
        }

        // Immediate gen dispatch — inline, no cron. One warm fire = one render = one crit dispatch.
        if (function_exists('wpc_warm_url_fire') && function_exists('home_url')) {
            try {
                wpc_warm_url_fire(home_url('/'));
                if (function_exists('wpc_cohort_beacon')) {
                    wpc_cohort_beacon('gen_dispatched');
                }
            } catch (\Throwable $e) {
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }
}

if (!function_exists('wpc_link_preset_safe_mode')) {
    // Safe-mode: revert ONLY the preset-managed keys to conservative (off/unset) values, purge once,
    // journal {from-preset}. NOT a full-blob replace (never wps_ic_set_default_settings).
    function wpc_link_preset_safe_mode()
    {
        if (!defined('WPS_IC_SETTINGS')) {
            return false;
        }
        $s = get_option(WPS_IC_SETTINGS);
        if (!is_array($s)) {
            return false;
        }
        $from = [];
        $revert = ['used-css' => '0', 'delay-js-v2' => 0, 'delay-js-v3' => 0, 'replace-fonts' => ''];
        foreach ($revert as $k => $v) {
            $from[$k] = $s[$k] ?? null;
            $s[$k] = $v;
        }
        if (isset($s['critical']) && is_array($s['critical'])) {
            $from['critical.css'] = $s['critical']['css'] ?? null;
            $s['critical']['css'] = 0;
        }
        if (isset($s['cache']) && is_array($s['cache'])) {
            $from['cache.advanced'] = $s['cache']['advanced'] ?? null;
            $s['cache']['advanced'] = 0;
        }
        update_option(WPS_IC_SETTINGS, $s);
        update_option('wpc_link_safe_mode', '1', false);


        if (get_option('wpc_auto_mode') === '1') {
            update_option('wpc_auto_mode', '0', false);
            if (function_exists('wp_unschedule_hook')) {
                wp_unschedule_hook('wpc_auto_mode_tick');
                wp_unschedule_hook('wpc_auto_mode_poll');
            }
            if (function_exists('wpc_auto_journal')) {
                wpc_auto_journal('disabled', ['by' => 'safe-mode']);
            }
        }
        wpc_link_preset_journal('safe-mode', ['from-preset' => $from]);

        if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
            try {
                wps_ic_cache_integrations::purgeAll(false, true, false, true, true, true);
            } catch (\Throwable $e) {
            }
        }
        return true;
    }

    add_action('wp_ajax_wpc_link_safe_mode', function () {
        if (!current_user_can('manage_wpc_settings')
            || !wp_verify_nonce(isset($_POST['nonce']) ? (string) $_POST['nonce'] : '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        if (function_exists('wpc_agency_forward_json')) {
            wpc_agency_forward_json('linkSafeMode');
        }
        $ok = wpc_link_preset_safe_mode();
        wp_send_json_success(['safe_mode' => (bool) $ok, 'journal' => array_reverse(array_slice((array) get_option('wpc_link_preset_journal', []), -6))]);
    });
}
