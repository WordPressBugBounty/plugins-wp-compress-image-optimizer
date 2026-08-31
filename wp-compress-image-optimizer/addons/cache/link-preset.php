<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/link-preset.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

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

if (!function_exists('wpc_preset_cache_gate67')) {
    
    
    
    function wpc_preset_cache_gate67($settings)
    {
        if (!apply_filters('wpc_preset_cache_gate', true)) {
            return $settings;
        }
        if (!is_array($settings) || empty($settings['cache']['advanced'])) {
            return $settings;
        }
        $wpc_f67 = wpc_detect_foreign_page_cache();
        if ($wpc_f67 === false) {
            return $settings;
        }
        $settings['cache']['advanced'] = 0;
        if (function_exists('wpc_link_preset_journal')) {
            wpc_link_preset_journal('cache-gate', ['foreign' => $wpc_f67]);
        }
        return $settings;
    }
}

if (!function_exists('wpc_font_localizer_present')) {


    function wpc_font_localizer_present()
    {
        static $wpc_flp789 = null;
        if ($wpc_flp789 !== null) {
            return apply_filters('wpc_font_localizer_present', $wpc_flp789);
        }
        $wpc_flp789 = false;
        try {
            $wpc_sl789 = apply_filters('wpc_font_localizer_slugs', [
                'omgf'               => 'host-webfonts-local',
                'local-google-fonts' => 'local-google-fonts',
                'embed-google-fonts' => 'embed-google-fonts',
                'omgf-pro'           => 'omgf-pro',
                'dp-divi-dsgvo'      => 'dp-divi-dsgvo',
            ]);
            $wpc_ap789 = (array) get_option('active_plugins', []);
            if (function_exists('get_site_option')) {
                $wpc_ap789 = array_merge($wpc_ap789, array_keys((array) get_site_option('active_sitewide_plugins', [])));
            }
            foreach ($wpc_sl789 as $wpc_n789 => $wpc_d789) {
                foreach ($wpc_ap789 as $wpc_p789) {
                    if (strpos((string) $wpc_p789, (string) $wpc_d789 . '/') === 0) {
                        $wpc_flp789 = (string) $wpc_n789;
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $wpc_flp789 = false;
        }
        return apply_filters('wpc_font_localizer_present', $wpc_flp789);
    }
}

if (!function_exists('wpc_font_localizer_sheet')) {


    function wpc_font_localizer_sheet($wpc_tag789)
    {
        if (!is_string($wpc_tag789) || $wpc_tag789 === '') {
            return false;
        }
        if (!function_exists('wpc_font_localizer_present') || wpc_font_localizer_present() === false) {
            return false;
        }
        $wpc_tk789 = apply_filters('wpc_font_localizer_sheet_tokens',
            ['omgf', 'local-google-fonts', 'embed-google-fonts', 'gfonts_local', 'dp-divi-dsgvo']);
        foreach ((array) $wpc_tk789 as $wpc_t789) {
            if ($wpc_t789 !== '' && stripos($wpc_tag789, (string) $wpc_t789) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_font_localizer_faces')) {

    
    
    
    function wpc_font_localizer_faces()
    {
        static $wpc_faces794 = null;
        if ($wpc_faces794 !== null) {
            return $wpc_faces794;
        }
        $wpc_faces794 = [];
        try {
            if (!function_exists('wpc_font_localizer_present') || wpc_font_localizer_present() === false
                || !function_exists('wp_upload_dir') || !function_exists('home_url')) {
                return $wpc_faces794;
            }
            $wpc_ud794 = wp_upload_dir();
            if (empty($wpc_ud794['basedir']) || empty($wpc_ud794['baseurl'])) {
                return $wpc_faces794;
            }
            $wpc_home794 = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            $wpc_dirs794 = apply_filters('wpc_font_localizer_dirs', ['omgf', 'local-google-fonts', 'embed-google-fonts']);
            $wpc_sheets794 = [];
            foreach ((array) $wpc_dirs794 as $wpc_d794) {
                $wpc_g794 = glob(rtrim((string) $wpc_ud794['basedir'], '/') . '/' . $wpc_d794 . '/{*,*/*}.css', GLOB_BRACE);
                if (is_array($wpc_g794)) {
                    $wpc_sheets794 = array_merge($wpc_sheets794, array_slice($wpc_g794, 0, 20));
                }
            }
            foreach (array_slice($wpc_sheets794, 0, 20) as $wpc_s794) {
                $wpc_css794 = (string) @file_get_contents($wpc_s794);
                if ($wpc_css794 === '' || !preg_match_all('/@font-face\s*\{[^}]*\}/i', $wpc_css794, $wpc_fb794)) {
                    continue;
                }
                foreach ($wpc_fb794[0] as $wpc_b794) {
                    if (count($wpc_faces794) >= (int) apply_filters('wpc_font_localizer_faces_max', 50)) {
                        break 2;
                    }
                    if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $wpc_b794, $wpc_fam794)
                        || !preg_match('/src\s*:[^;}]*url\(\s*["\']?([^"\')\s]+)/i', $wpc_b794, $wpc_src794)) {
                        continue;
                    }
                    $wpc_u794 = (string) $wpc_src794[1];
                    if (strpos($wpc_u794, '//') === false) {
                        
                        $wpc_rel794 = ltrim((string) substr(dirname($wpc_s794), strlen(rtrim((string) $wpc_ud794['basedir'], '/'))), '/');
                        $wpc_u794 = rtrim((string) $wpc_ud794['baseurl'], '/') . '/' . ($wpc_rel794 !== '' ? $wpc_rel794 . '/' : '') . ltrim($wpc_u794, './');
                    }
                    $wpc_uh794 = strtolower((string) parse_url($wpc_u794, PHP_URL_HOST));
                    if ($wpc_uh794 !== '' && $wpc_uh794 !== $wpc_home794) {
                        continue; 
                    }
                    $wpc_e794 = ['family' => trim($wpc_fam794[1]), 'src' => $wpc_u794];
                    if (preg_match('/font-weight\s*:\s*([^;}]+)/i', $wpc_b794, $wpc_w794)) {
                        $wpc_e794['weight'] = trim($wpc_w794[1]);
                    }
                    if (preg_match('/font-style\s*:\s*([a-z]+)/i', $wpc_b794, $wpc_st794)) {
                        $wpc_e794['style'] = trim($wpc_st794[1]);
                    }
                    if (preg_match('/unicode-range\s*:\s*([^;}]+)/i', $wpc_b794, $wpc_ur794)) {
                        $wpc_e794['unicode_range'] = trim($wpc_ur794[1]);
                    }
                    $wpc_faces794[] = $wpc_e794;
                }
            }
        } catch (\Throwable $e) {
            $wpc_faces794 = [];
        }
        return $wpc_faces794;
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

        
        foreach ((array) $levers['flat'] as $k => $v) {
            if ($k === 'replace-fonts' && function_exists('wpc_font_localizer_present')) {
                $wpc_fl789 = wpc_font_localizer_present();
                if ($wpc_fl789 !== false) {
                    $skipped[$k] = 'localizer:' . $wpc_fl789;
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

        
        if (!isset($s['critical']) || !is_array($s['critical'])) {
            $s['critical'] = [];
        }
        if (!isset($s['critical']['css'])) {
            $s['critical']['css'] = $levers['critical_css'];
            $applied['critical.css'] = $levers['critical_css'];
        } else {
            $skipped['critical.css'] = $s['critical']['css'];
        }

        
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
        update_option('wpc_settings_initialized', '1', false); 
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
            wpc_cohort_beacon('linked', ['ctx' => (string) $ctx]); 
        }

        wpc_link_preset_journal('preset-apply', ['ctx' => (string) $ctx, 'applied' => $applied, 'skipped' => $skipped]);
        if (function_exists('wpc_cohort_beacon')) {
            wpc_cohort_beacon('preset_applied', ['applied' => array_keys($applied), 'skipped' => array_keys($skipped)]);
        }

        
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
