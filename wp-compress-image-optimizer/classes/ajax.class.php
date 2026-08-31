<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/ajax.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */





class wps_ic_ajax extends wps_ic
{

    public static $API_URL = WPS_IC_CRITICAL_API_URL;
    public static $PAGESPEED_URL = WPS_IC_PAGESPEED_API_URL;
    public static $PAGESPEED_URL_HOME = WPS_IC_PAGESPEED_API_URL_HOME;
    public static $CRITICAL_URL_HOME = WPS_IC_CRITICAL_API_URL_HOME;


    public static $local;
    public static $options;
    public static $settings;
    public static $accountStatus;

    public static $logo_compressed;
    public static $logo_uncompressed;
    public static $logo_excluded;
    public static $count_thumbs;

    public static $cacheIntegrations;

    public static $version;
    public static $Requests;
    public static $apikey;

    public function __construct()
    {
        self::$Requests = new wps_ic_requests();

        if (is_admin()) {
            self::$version = str_replace('.', '', parent::$version);
            self::$cacheIntegrations = new wps_ic_cache_integrations();
            self::$settings = get_option(WPS_IC_SETTINGS);
            self::$options = get_option(WPS_IC_OPTIONS);


            if (empty(self::$settings['live-cdn']) || self::$settings['live-cdn'] != '1') {
                $cfSettings = get_option(WPS_IC_CF);
                if (!empty($cfSettings['settings']['cdn']) && $cfSettings['settings']['cdn'] == '1') {
                    self::$settings['live-cdn'] = '1';
                } else {
                    $cdnOn = false;
                    if (!empty(self::$settings['serve'])) {
                        foreach (self::$settings['serve'] as $v) {
                            if ($v == '1') { $cdnOn = true; break; }
                        }
                    }
                    if (!$cdnOn && (!empty(self::$settings['css']) && self::$settings['css'] == '1')) $cdnOn = true;
                    if (!$cdnOn && (!empty(self::$settings['js']) && self::$settings['js'] == '1')) $cdnOn = true;
                    if (!$cdnOn && (!empty(self::$settings['fonts']) && self::$settings['fonts'] == '1')) $cdnOn = true;
                    if ($cdnOn) self::$settings['live-cdn'] = '1';
                }
            }

            self::$apikey = parent::$api_key;
            self::$count_thumbs = count(get_intermediate_image_sizes());
            self::$local = parent::$local;
            self::$logo_compressed = WPS_IC_URI . 'assets/images/legacy/logo-compressed.svg';
            self::$logo_uncompressed = WPS_IC_URI . 'assets/images/legacy/logo-not-compressed.svg';
            self::$logo_excluded = WPS_IC_URI . 'assets/images/legacy/logo-excluded.svg';

            
            $this->add_ajax('wpsChangeGui');
            
            
            $this->add_ajax('wpc_v2_diag');

            
            
            if ($this->isAgencyPortal()) {
                $this->add_ajax('wps_ic_ajax_v2_checkbox_batch');
                $this->add_ajax('wps_ic_save_mode');
                $this->add_ajax('wps_ic_save_excludes_settings');
                $this->add_ajax('wps_ic_get_setting');
                
                $this->add_ajax('wpsScanFonts');
                $this->add_ajax('wpsRemoveFont');
                $this->add_ajax('wpsPurgeFontCache');
                
                $this->add_ajax('wpc_ic_checkCFToken');
                $this->add_ajax('wpc_ic_checkCFConnect');
                $this->add_ajax('wpc_cf_check_permissions');
                $this->add_ajax('wpc_natural_diag');
                $this->add_ajax('wpc_legacy_lever');
                $this->add_ajax('wpc_natural_force');
                $this->add_ajax('wpc_img_diag');
                $this->add_ajax('wpc_ic_checkCFDisconnect');
                $this->add_ajax('wpc_ic_refreshCFConnection');
                $this->add_ajax('wpc_ic_setupCF');
                $this->add_ajax('wps_ic_save_cf_cdn');
                $this->add_ajax('wps_ic_get_cf_cdn');
                
                
                
                
                $this->add_ajax('wps_ic_cname_add');
                $this->add_ajax('wps_ic_cname_retry');
                $this->add_ajax('wps_ic_remove_cname');
                $this->add_ajax('wps_ic_get_purge_rules');
                $this->add_ajax('wps_ic_save_purge_hooks_settings');
                $this->add_ajax('wps_ic_get_cache_cookies');
                $this->add_ajax('wps_ic_save_cache_cookies_settings');
                $this->add_ajax('wps_ic_purge_after_save');
                $this->add_ajax('wpc_ic_ajax_set_preset');
                
                $this->add_ajax('wps_ic_export_settings');
                $this->add_ajax('wps_ic_import_settings');
                $this->add_ajax('wps_ic_set_default_settings');


                $this->add_ajax('wps_ic_optimize_start');
                $this->add_ajax('wps_ic_optimize_status');
            }

            if (!empty(parent::$api_key)) {
                
                $this->add_ajax('wps_fetchInitialTest');
                $this->add_ajax('wps_ic_pull_stats');

                
                $this->add_ajax('wpsRemoveFont');
                $this->add_ajax('wpsScanFonts');
                $this->add_ajax('wpsPurgeFontCache');

                
                $this->add_ajax('wpc_ic_checkCFToken');
                $this->add_ajax('wpc_ic_checkCFConnect');
                $this->add_ajax('wpc_cf_check_permissions');
                $this->add_ajax('wpc_natural_diag');
                $this->add_ajax('wpc_legacy_lever');
                $this->add_ajax('wpc_natural_force');
                $this->add_ajax('wpc_img_diag');
                $this->add_ajax('wpc_ic_checkCFDisconnect');
                $this->add_ajax('wpc_ic_refreshCFConnection');
                $this->add_ajax('wpc_ic_setupCF');

                
                $this->add_ajax('wps_ic_critical_get_assets');
                $this->add_ajax('wps_ic_critical_run');
                $this->add_ajax('wps_ic_get_setting');
                $this->add_ajax('wps_ic_saveSetting');
                $this->add_ajax('wps_ic_save_excludes_settings');

                
                $this->add_ajax('wps_ic_remove_key');
                $this->add_ajax('wpc_ic_set_mode');
                $this->add_ajax('wpc_ic_ajax_set_preset');
                $this->add_ajax('wps_ic_cname_add');
                $this->add_ajax('wps_ic_cname_retry');
                $this->add_ajax('wps_ic_remove_cname');
                $this->add_ajax('wps_ic_exclude_list');
                $this->add_ajax('wps_ic_geolocation');
                $this->add_ajax('wps_ic_geolocation_force');

                
                $this->add_ajax('wps_ic_StopBulk');
                $this->add_ajax('wps_ic_getBulkStats');
                $this->add_ajax('wps_ic_bulkCompressHeartbeat');
                $this->add_ajax('wps_ic_bulkRestoreHeartbeat');
                $this->add_ajax('wps_ic_isBulkRunning');
                $this->add_ajax('wpc_ic_start_bulk_restore');
                $this->add_ajax('wpc_ic_start_bulk_compress');
                $this->add_ajax('wps_ic_doBulkCompress');
                $this->add_ajax('wpc_bulk_v2_drain');
                $this->add_ajax('wpc_bulk_v2_restore_drain');
                $this->add_ajax('wpc_bulk_v2_restore_drain_loop');       
                $this->add_ajax_nopriv('wpc_bulk_v2_restore_drain_loop');
                $this->add_ajax('wpc_bulk_v2_drain_loop');               
                $this->add_ajax_nopriv('wpc_bulk_v2_drain_loop');
                $this->add_ajax('wpc_bulk_ledger_dump');
                $this->add_ajax('wpc_v2_compress_probe');
                $this->add_ajax('wpc_v2_full_debug');
                $this->add_ajax('wpc_wire_doctor');
                $this->add_ajax('wps_ic_bulkCompressCleanup');
                $this->add_ajax('wps_ic_bulkRestoreCleanup');
                $this->add_ajax('wps_ic_media_library_bulk_heartbeat');
                $this->add_ajax('wps_ic_doBulkRestore');
                $this->add_ajax('wps_ic_RestoreFinished');

                $this->add_ajax('wps_ic_media_library_heartbeat');
                $this->add_ajax('wps_ic_compress_live');
                $this->add_ajax('wps_ic_restore_live');


                $this->add_ajax('wpc_async_phase_a');
                $this->add_ajax_nopriv('wpc_async_phase_a');
                $this->add_ajax('wpc_async_restore_regen');
                $this->add_ajax_nopriv('wpc_async_restore_regen');


                $this->add_ajax('wpc_delivery_verify_async');
                $this->add_ajax_nopriv('wpc_delivery_verify_async');


                $this->add_ajax('wpc_async_image_bg_retry');
                $this->add_ajax_nopriv('wpc_async_image_bg_retry');
                $this->add_ajax('wps_ic_get_card');  
                $this->add_ajax('wps_ic_variant_count');
                $this->add_ajax('wps_ic_check_customer_activity');  
                $this->add_ajax('wps_ic_pull_manifest');
                
                
                
                add_action('wp_ajax_nopriv_wps_ic_check_customer_activity', function () { wp_send_json_success(['enabled' => false]); });
                add_action('wp_ajax_nopriv_wps_ic_pull_manifest', function () { wp_send_json_success(['enabled' => false]); });
                
                
                add_action('wp_ajax_wpc_v2_pull_drain_loop',        'wpc_v2_pull_drain_loop_handler');
                add_action('wp_ajax_nopriv_wpc_v2_pull_drain_loop', 'wpc_v2_pull_drain_loop_handler');
                $this->add_ajax('wpc_bulk_clear_stuck_compressing');
                $this->add_ajax('wpc_purge_variants');
                $this->add_ajax('wps_ic_exclude_live');
                $this->add_ajax('wps_ic_purge_local_variants');
                $this->add_ajax('wps_ic_image_stats');
                $this->add_ajax('wps_ic_get_default_settings');

                $this->add_ajax('wps_ic_ajax_v2_checkbox');
                $this->add_ajax('wps_ic_ajax_v2_checkbox_batch');
                $this->add_ajax('wps_ic_purge_after_save');
                $this->add_ajax('wps_ic_ajax_checkbox');

                $this->add_ajax('wps_ic_purge_cdn');
                $this->add_ajax('wps_ic_purge_html');
                $this->add_ajax('wpc_purge_receipts');
                $this->add_ajax('wpc_cf_doctor');
                $this->add_ajax('wps_ic_purge_critical_css');
            $this->add_ajax('wps_ic_crit_land_check');
                $this->add_ajax('wps_ic_preload_page');
                $this->add_ajax('wps_ic_generate_critical_css');
                $this->add_ajax('wps_ic_rebuild_optimizations');

                $this->add_ajax('wps_ic_dismiss_notice');
                $this->add_ajax('wps_ic_fix_notice');
                $this->add_ajax('wps_ic_save_mode');
                $this->add_ajax('wps_ic_get_optimization_status_pages');
                $this->add_ajax('wps_ic_save_optimization_status');

                $this->add_ajax('wps_ic_get_page_excludes_popup_html');
                $this->add_ajax('wps_ic_save_page_excludes_popup');
                $this->add_ajax('wps_ic_resetTest');
                $this->add_ajax('wps_ic_optimize_start');
                $this->add_ajax('wps_ic_optimize_status');
                $this->add_ajax('wps_ic_run_tests');
                $this->add_ajax('wps_ic_start_optimizations');
                $this->add_ajax('wps_ic_stop_optimizations');
                $this->add_ajax('wpsRunQuickTest');
                $this->add_ajax('wps_ic_run_single_optimization');
                $this->add_ajax('wps_ic_get_per_page_settings_html');
                $this->add_ajax('wps_ic_save_per_page_settings');
                $this->add_ajax('wps_ic_save_purge_hooks_settings');
                $this->add_ajax('wps_ic_save_cache_cookies_settings');
                $this->add_ajax('wps_ic_get_cache_cookies');
                $this->add_ajax('wps_ic_get_purge_rules');
                $this->add_ajax('wps_ic_export_settings');
                $this->add_ajax('wps_ic_import_settings');
                $this->add_ajax('wps_ic_set_default_settings');
                $this->add_ajax('wps_ic_save_cf_cdn');
                $this->add_ajax('wps_ic_get_cf_cdn');

                

                
                $this->add_ajax('wps_ic_count_uncompressed_images');

                
                $this->add_ajax('wps_ic_settings_change');

                
                $this->add_ajax('wpc_delivery_save');
                $this->add_ajax('wpc_delivery_recheck');

                
                $this->add_ajax('wps_ic_simple_exclude_image');
                $this->add_ajax('wps_lite_connect');
                $this->add_ajax('wps_ic_live_connect');
            } else {
                
                $this->add_ajax('wps_lite_connect');
                $this->add_ajax('wps_ic_live_connect');
            }

            $this->add_ajax('wps_ic_check_optimization_status');
            $this->add_ajax('wpc_send_critical_remote');
            $this->add_ajax_nopriv('wpc_send_critical_remote');
        } else {
            $this->add_ajax('wpc_ic_set_mode');
            $this->add_ajax('wpc_send_critical_remote');
            $this->add_ajax_nopriv('wpc_send_critical_remote');
            $this->add_ajax('wps_ic_purge_html');
            $this->add_ajax('wpc_purge_receipts');
            $this->add_ajax('wpc_cf_doctor');
            $this->add_ajax('wps_ic_purge_cdn');
            $this->add_ajax('wps_ic_purge_critical_css');
            $this->add_ajax('wps_ic_preload_page');
            $this->add_ajax('wps_ic_generate_critical_css');
            $this->add_ajax('wps_ic_rebuild_optimizations');
        }


        $this->add_ajax('wpc_gf_refresh_nonce');
        $this->add_ajax_nopriv('wpc_gf_refresh_nonce');

        
        add_action('admin_post_wpc_clear_error_log', function () {
            check_admin_referer('wpc_clear_error_log');
            if (!current_user_can('manage_options')) {
                wp_die('Forbidden.');
            }
            delete_option('wpc_error_debug_log');
            wp_redirect(wpc_settings_page_url('&view=debug_tool'));
            exit;
        });
    }

    public function add_ajax($hook)
    {
        add_action('wp_ajax_' . $hook, [$this, $hook]);
    }

    public function add_ajax_nopriv($hook)
    {
        add_action('wp_ajax_nopriv_' . $hook, [$this, $hook]);
    }


    public function wps_ic_crit_land_check()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            wp_send_json_error(['e' => 'cap']);
        }
        $out = ['landed' => false, 'nudged' => false];
        try {
            
            
            if (function_exists('_get_cron_array') && function_exists('wp_unschedule_event')
                && !(function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active())
                && !(function_exists('wpc_under_pressure') && wpc_under_pressure())) {
                $wpc_cr = _get_cron_array();
                $wpc_ran = 0;
                if (is_array($wpc_cr)) {
                    foreach ($wpc_cr as $wpc_ts => $wpc_hooks) {
                        if ($wpc_ts > time() || $wpc_ran >= 3) {
                            break;
                        }
                        foreach ((array) $wpc_hooks as $wpc_h => $wpc_evs) {
                            if (strpos($wpc_h, 'wpc_') !== 0 && strpos($wpc_h, 'wps_ic_') !== 0) {
                                continue;
                            }
                            
                            
                            if (in_array($wpc_h, ['wpc_lcp_repull', 'wpc_url_warm'], true)) {
                                continue;
                            }
                            foreach ((array) $wpc_evs as $wpc_ev) {
                                if ($wpc_ran >= 3) {
                                    break 3;
                                }
                                if (!empty($wpc_ev['schedule'])) {
                                    continue; 
                                }
                                $wpc_args = isset($wpc_ev['args']) ? (array) $wpc_ev['args'] : [];
                                wp_unschedule_event($wpc_ts, $wpc_h, $wpc_args);
                                do_action_ref_array($wpc_h, $wpc_args);
                                $wpc_ran++;
                            }
                        }
                    }
                }
                $out['ran'] = $wpc_ran;
            }
            $wpc_home = home_url('/');
            $wpc_uk = class_exists('wps_ic_url_key') ? (new wps_ic_url_key())->setup($wpc_home) : '';
            if ($wpc_uk !== '' && defined('WPS_IC_CRITICAL')) {
                $wpc_d = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_uk . '/critical_desktop.css';
                $wpc_m = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_uk . '/critical_mobile.css';
                $wpc_since = (int) get_option('wpc_crit_soft_purge_at');
                $wpc_ok = @filesize($wpc_d) > 64 && @filesize($wpc_m) > 64;
                $wpc_mt = max((int) @filemtime($wpc_d), (int) @filemtime($wpc_m));
                if ($wpc_ok && ($wpc_since === 0 || $wpc_mt >= $wpc_since)) {
                    $out['landed'] = true;
                    $out['age'] = max(0, time() - $wpc_mt);
                    
                    if (!get_transient('wpc_landck_purged') && class_exists('wps_ic_cache_integrations')
                        && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                        set_transient('wpc_landck_purged', 1, 120);
                        wps_ic_cache_integrations::purgeUrlHtml($wpc_uk, '', ['context' => 'land-check']);
                    }
                } else {
                    
                    
                    
                    if (class_exists('wps_criticalCss')) {
                        try {
                            $wpc_cc = new wps_criticalCss();
                            if (method_exists($wpc_cc, 'pullDerivedArtifacts') && $wpc_cc->pullDerivedArtifacts($wpc_uk)) {
                                $out['landed'] = true;
                                $out['db_free'] = true;
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                    if (empty($out['landed']) && function_exists('wpc_warm_url_queue')) {
                        
                        $out['nudged'] = (bool) wpc_warm_url_queue($wpc_home, 'land-check');
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        wp_send_json_success($out);
    }

    public function wpc_gf_refresh_nonce()
    {
        $form_id = isset($_REQUEST['form_id']) ? absint($_REQUEST['form_id']) : 0;
        if ($form_id < 1) {
            wp_send_json_error();
        }
        $action = apply_filters('wpc_gf_nonce_action', 'gform_submit_' . $form_id, $form_id);
        $field  = apply_filters('wpc_gf_nonce_field', '_gform_submit_nonce_' . $form_id, $form_id);
        wp_send_json_success(['field' => (string) $field, 'nonce' => wp_create_nonce($action)]);
    }

    
    private static function wpc_delivery_guard()
    {
        $nonce = isset($_POST['wps_ic_nonce']) ? $_POST['wps_ic_nonce'] : '';
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($nonce, 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }
        if (!class_exists('WPC_Delivery_Resolver')) {
            wp_send_json_error('resolver-unavailable');
        }
    }

    




    public function wpc_delivery_save()
    {
        self::wpc_delivery_guard();
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings)) $settings = [];

        if (isset($_POST['mode'])) {
            $mode = strtolower(sanitize_text_field(wp_unslash($_POST['mode'])));
            if (!in_array($mode, ['auto', 'webp', 'off'], true)) {
                wp_send_json_error('bad-mode');
            }
            $settings[WPC_Delivery_Resolver::NEXTGEN_OPTION] = $mode;
            
            $ceiling = ($mode === 'auto') ? 'avif' : $mode;
            foreach (WPC_Delivery_Resolver::settings_for_ceiling($ceiling) as $k => $v) {
                $settings[$k] = $v;
            }
        }

        if (isset($_POST['override'])) {
            $ov = strtolower(sanitize_text_field(wp_unslash($_POST['override'])));
            if (!in_array($ov, ['auto', 'picture', 'htaccess', 'cdn', 'edge'], true)) $ov = 'auto';
            $settings[WPC_Delivery_Resolver::OVERRIDE_OPTION] = $ov;


            $settings[WPC_Delivery_Resolver::EDGE_ORIGIN_OPTION] = ($ov === 'edge') ? '1' : '0';
        }

        update_option(WPS_IC_SETTINGS, $settings);


        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
        }
        wp_send_json_success(WPC_Delivery_Resolver::resolve_verbose(true));
    }

    
    public function wpc_delivery_recheck()
    {
        self::wpc_delivery_guard();


        if (function_exists('delete_option'))    delete_option('wpc_v2_cf_asset_mime_ok');
        if (function_exists('delete_transient')) {
            delete_transient('wpc_v2_cf_asset_mime_retry');
            delete_transient('wpc_v2_asset_probe_inflight');
        }


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')) {
            wps_rewriteLogic::natural_assets_on();
        }


        if (function_exists('wpc_v2_provision_ensure_bg')) {
            if (function_exists('delete_option'))    delete_option('wpc_v2_selfheal_attempts');
            if (function_exists('delete_transient')) delete_transient('wpc_v2_selfheal_backoff');


            if (function_exists('update_option')) update_option('wpc_v2_force_provision', 1, false);
            wpc_v2_provision_ensure_bg('recheck');
        }
        wp_send_json_success(WPC_Delivery_Resolver::resolve_verbose(true));
    }

    
    
    
    
    
    public static function wpc_split_patterns19($raw)
    {
        if (is_array($raw)) {
            $raw = implode("\n", $raw);
        }
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        if (!is_array($parts)) {
            return [];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    public static function wpc_ic_checkCFDisconnect()
    {
        $isAgency = defined('WPS_IC_AGENCY') && WPS_IC_AGENCY;
        if ((!$isAgency && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $requests = new wps_ic_requests();

        if ($isAgency) {
            global $api;
            $apikey    = sanitize_text_field($_POST['apikey'] ?? '');
            $cfSettings = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getRemoteCFOption($apikey) : [];
            $siteUrl   = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getSiteUrl($apikey) : site_url();
        } else {
            $cfSettings = get_option(WPS_IC_CF);
            $options    = get_option(WPS_IC_OPTIONS);
            $apikey     = $options['api_key'];
            $siteUrl    = site_url();
        }

        $zoneInput = $cfSettings['zone'] ?? '';
        $token     = $cfSettings['token'] ?? '';
        $zoneName  = str_replace(['http://', 'https://', '/'], '', $siteUrl);

        
        
        if ($isAgency) {
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->deleteRemoteCFOption($apikey);
            }
        } else {
            delete_option(WPS_IC_CF);
            delete_transient('wpc_cdn_backup');
        }


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'invalidate_asset_mime_proof')) {
            wps_rewriteLogic::invalidate_asset_mime_proof();
        }
        if (class_exists('WPC_Delivery_Resolver') && function_exists('delete_option')) {
            delete_option(WPC_Delivery_Resolver::STATE_OPTION);
        }
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_delivery_verify')) {
            wp_schedule_single_event(time() + 5, 'wpc_delivery_verify');
        }


        try {
            if (get_option('ic_custom_cname')) {
                if (!class_exists('wps_ic_cname')) {
                    @include_once WPS_IC_DIR . 'classes/cname.class.php';
                }
                if (class_exists('wps_ic_cname')) {
                    (new wps_ic_cname())->remove(false);
                }
            }
            if (defined('WPS_IC_CF_CNAME')) {
                delete_option(WPS_IC_CF_CNAME);
            }
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
            }
        } catch (\Throwable $e) {

        }


        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        }

        
        
        if (!empty($token) && !empty($zoneInput)) {
            $cfsdk = new WPC_CloudflareAPI($token);
            $cfsdk->removeCdnBypassRule($zoneInput);
        }
        $requests->GET(WPS_IC_KEYSURL, ['action' => 'disconnectCF', 'token' => $token, 'zone' => $zoneInput, 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 15]);

        wp_send_json_success();
    }


    private static function wpc_cf_error_payload($result, $token = '')
    {
        $class = class_exists('WPC_CloudflareAPI')
            ? WPC_CloudflareAPI::classifyResult($result)
            : ['ok' => false, 'mode' => 'unknown', 'detail' => ''];
        $detail = (string) $class['detail'];


        $wpc_tok_kind = '';
        if (is_string($token) && $token !== '') {
            if (strpos($token, 'cfat_') === 0) { $wpc_tok_kind = 'account'; }
            elseif (strpos($token, 'cfut_') === 0) { $wpc_tok_kind = 'user'; }
        }
        $codes  = [];
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            if (is_array($data)) {
                foreach ($data as $e) {
                    if (is_array($e) && isset($e['code'])) {
                        $codes[] = (int) $e['code'];
                    }
                }
            }
        }
        if ($class['mode'] === 'unreachable') {
            $msg = 'Your server could not reach the Cloudflare API (' . $detail . '). This is a hosting/network issue'
                . ' — your token may be perfectly fine. We already retried once automatically; try again in a minute,'
                . ' and if it keeps happening ask your host to allow outbound HTTPS to api.cloudflare.com.';
        } elseif (in_array(6003, $codes, true)) {
            $msg = 'Cloudflare says the token value is malformed (code 6003). Re-copy the token from Cloudflare'
                . ' — watch for leading/trailing spaces or a truncated paste.';
        } elseif (in_array(9109, $codes, true) || in_array(10000, $codes, true) || in_array(1000, $codes, true)) {
            $serverIp = !empty($_SERVER['SERVER_ADDR']) ? sanitize_text_field($_SERVER['SERVER_ADDR']) : '';
            $msg = 'Cloudflare rejected the token as invalid or unauthorized (' . $detail . '). Check, in this order:'
                . ' (1) the token was not rolled or deleted, (2) its TTL window has not expired,'
                . ' (3) Client IP Address Filtering on the token — these API calls come from your SERVER'
                . ($serverIp !== '' ? ' (this server reports IP ' . $serverIp . '; the outbound IP can differ on some hosts)' : '')
                . ', not your browser, so an IP filter set to your office/home IP blocks them. Remove the filter or include the server IP.';
            if ($wpc_tok_kind === 'account') {
                $msg .= ' Note: this is an ACCOUNT-owned token (cfat_) — also confirm its Account Resources'
                    . ' include the account that owns this zone, and its Zone Resources include this site.';
            }
        } elseif ($class['mode'] === 'permission') {
            $msg = $detail;
        } else {
            $msg = 'Cloudflare error: ' . ($detail !== '' ? $detail : 'no detail returned') . '.';
        }
        return ['msg' => $msg, 'kind' => $class['mode'], 'cf_codes' => $codes];
    }


    private static function wpc_cf_list_zones_retry($cfapi, $page = 1)
    {
        $zones = $cfapi->listZones($page);
        if (is_wp_error($zones) && class_exists('WPC_CloudflareAPI')) {
            $class = WPC_CloudflareAPI::classifyResult($zones);
            if (!empty($class['mode']) && $class['mode'] === 'unreachable') {
                usleep(700000);
                $zones = $cfapi->listZones($page);
            }
        }
        return $zones;
    }

    public static function wpc_ic_checkCFConnect()
    {
        $isAgency = defined('WPS_IC_AGENCY') && WPS_IC_AGENCY;
        if ((!$isAgency && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $token = sanitize_text_field($_POST['token']);
        $zoneInput = sanitize_text_field($_POST['zone']);

        $cfapi = new WPC_CloudflareAPI($token);
        $check = $cfapi->checkPrivileges($zoneInput);
        if (is_wp_error($check)) {
            
            wp_send_json_error(['msg' => $check->get_error_message(), 'kind' => 'misconfig']);
        }


        if (is_array($check) && !empty($check['critical_missing'])) {
            update_option('wpc_cf_privileges', ['t' => time(), 'privs' => $check], false);
            wp_send_json_error([
                'privileges'       => $check,
                'msg'              => 'Your Cloudflare API token is missing required permission(s): '
                    . implode(', ', $check['critical_missing'])
                    . '. In Cloudflare go to My Profile → API Tokens → edit this token, add the permission(s) above'
                    . ' (each is a "Permissions" row: Zone → [Group] → [Access]), Save, then reconnect here.'
                    . (!empty($check['optional_missing'])
                        ? ' Optional (not required to connect — each unlocks a feature): ' . implode('; ', $check['optional_missing']) . '.'
                        : ''),
                'critical_missing' => $check['critical_missing'],
                'optional_missing' => $check['optional_missing'],
                'tests'            => $check['tests'],
            ]);
        }
        $wpc_optional_missing = (is_array($check) && !empty($check['optional_missing'])) ? $check['optional_missing'] : [];
        $zones = self::wpc_cf_list_zones_retry($cfapi);

        if (is_wp_error($zones)) {

            wp_send_json_error(self::wpc_cf_error_payload($zones, $token));
        } else {
            $zonesOutput = [];
            foreach ($zones['result'] as $zone) {
                $zonesOutput[$zone['id']] = $zone['name'];
            }

            for ($i = 2; $i <= 20; $i++) {
                $zonesPage = self::wpc_cf_list_zones_retry($cfapi, $i);
                if (!is_wp_error($zonesPage) && !empty($zonesPage['result'])) {
                    foreach ($zonesPage['result'] as $zone) {
                        $zonesOutput[$zone['id']] = $zone['name'];
                    }
                } else {
                    break;
                }
            }
        }

        if (!empty($zonesOutput) && !empty($zonesOutput[$zoneInput])) {
            $save = ['token' => $token, 'zone' => $zoneInput, 'zoneName' => $zonesOutput[$zoneInput]];
            if ($isAgency) {
                global $api;
                $apikey = sanitize_text_field($_POST['apikey'] ?? '');
                if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                    $api::$comms->saveRemoteCFOption($apikey, $save);
                }
            } else {
                update_option(WPS_IC_CF, $save);
            }


            if (is_array($check)) {
                update_option('wpc_cf_privileges', ['t' => time(), 'privs' => $check], false);
            }
            wp_send_json_success(array_merge($save, ['optional_missing' => $wpc_optional_missing, 'privileges' => $check]));
        }


        if (empty($zonesOutput)) {
            wp_send_json_error([
                'msg'  => 'The token authenticated with Cloudflare but sees NO zones. In the token settings'
                    . ' (Cloudflare → My Profile → API Tokens → edit), under Zone Resources choose'
                    . ' Include → Specific zone → your domain (or All zones), Save, then reconnect.',
                'kind' => 'zone-visibility',
            ]);
        }
        wp_send_json_error([
            'msg'  => 'The token authenticated but cannot see the selected zone. It can see: '
                . implode(', ', array_values($zonesOutput))
                . '. Re-select your zone here, or fix the token\'s Zone Resources to include it, then reconnect.',
            'kind' => 'zone-mismatch',
        ]);
    }

    public static function wpc_ic_checkCFToken()
    {
        $isAgency = defined('WPS_IC_AGENCY') && WPS_IC_AGENCY;
        if ((!$isAgency && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $token = sanitize_text_field($_POST['token']);
        $cfapi = new WPC_CloudflareAPI($token);
        $zones = self::wpc_cf_list_zones_retry($cfapi);

        if (is_wp_error($zones)) {


            wp_send_json_error(self::wpc_cf_error_payload($zones, $token));
        } else {
            $zonesOutput = [];

            foreach ($zones['result'] as $zone) {
                $zonesOutput[$zone['id']] = $zone['name'];
            }

            if (!empty($zonesOutput)) {
                $zonesDropdown = '';

                foreach ($zonesOutput as $zoneID => $zoneName) {
                    $zonesDropdown .= '<div data-selected-zone="' . $zoneName . '" data-selected-zone-id="' . $zoneID . '">' . $zoneName . '</div>';
                }

                for ($i = 2; $i <= 20; $i++) {


                    $zones = self::wpc_cf_list_zones_retry($cfapi, $i);
                    if (!empty($zones['result'])) {
                        foreach ($zones['result'] as $zone) {
                            $zonesDropdown .= '<div data-selected-zone="' . $zone['name'] . '" data-selected-zone-id="' . $zone['id'] . '">' . $zone['name'] . '</div>';
                        }
                    } else {
                        break;
                    }
                }

                wp_send_json_success($zonesDropdown);
            }
        }

        if (empty($zones['result'])) {


            wp_send_json_error([
                'msg'  => 'The token authenticated with Cloudflare but sees NO zones. In the token settings'
                    . ' (Cloudflare → My Profile → API Tokens → edit) make sure it has Zone → Zone → Read,'
                    . ' and under Zone Resources choose Include → Specific zone → your domain (or All zones).'
                    . ' Save, then try again.',
                'kind' => 'zone-visibility',
            ]);
        } else {
            wp_send_json_error(['msg' => 'Unexpected Cloudflare response — please try again.', 'kind' => 'unknown']);
        }
    }

    public static function isFeatureEnabled($featureName)
    {
        
        if (function_exists('wpc_caps_enabled')) {
            return wpc_caps_enabled($featureName);
        }
        $feature = get_transient($featureName . 'Enabled');
        return !(!$feature || $feature == '0');
    }


    public function wpc_ic_refreshCFConnection()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $requests = new wps_ic_requests();

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey   = sanitize_text_field($_POST['apikey'] ?? '');
            $cf       = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getRemoteCFOption($apikey) : [];
            $siteUrl  = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getSiteUrl($apikey) : site_url();
        } else {
            $options  = get_option(WPS_IC_OPTIONS);
            $apikey   = $options['api_key'];
            $cf       = get_option(WPS_IC_CF);
            $siteUrl  = site_url();
        }

        $token     = sanitize_text_field($cf['token'] ?? '');
        $zoneInput = sanitize_text_field($cf['zone'] ?? '');
        $zoneName  = str_replace(['http://', 'https://', '/'], '', $siteUrl);

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'refreshCF', 'token' => $token, 'zone' => $zoneInput, 'siteUrl' => $siteUrl, 'zoneName' => $zoneName, 'staticAssets' => $cf['settings']['assets'] ?? '1', 'htmlCache' => $cf['settings']['edge-cache'] ?? 'all', 'cdn' => $cf['settings']['cdn'] ?? '1', 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 30]); 

        if (!empty($body) && isset($body->data)) {
            $data    = (array) $body->data;
            $cfCname = $data['cfName'] ?? '';
            $prevCfCname = (string) get_option(WPS_IC_CF_CNAME);

            if ($this->isAgencyPortal()) {
                if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                    $api::$comms->saveRemoteCFOption($apikey, null, $cfCname, $cf['settings'] ?? []);
                }
            } else {
                update_option(WPS_IC_CF_CNAME, $cfCname);
                self::$options = get_option(WPS_IC_SETTINGS);
                self::$options['cf'] = $cf['settings'];
                update_option(WPS_IC_SETTINGS, self::$options);


                if (!empty($cfCname) && (string) $cfCname !== $prevCfCname) {
                    update_option('wpc_cf_cname_verified', '0', false);
                }
            }

            $cfsdk = new WPC_CloudflareAPI($token);
            
            
            $cf_bypass = $cfsdk->addCdnBypassRule($zoneInput);
            $cf_white  = $cfsdk->whitelistIPs($zoneInput);
            $cf_static = $cfsdk->patchStaticAssetsRespectOrigin($zoneInput);
            if (method_exists($cfsdk, 'patchHtmlRulesRespectOrigin')) { $cfsdk->patchHtmlRulesRespectOrigin($zoneInput, null, true); }

            
            $cf_cname_live = (!empty($cfCname) && !$this->isAgencyPortal() && method_exists($cfsdk, 'verifyCfCnameLive')
                && $cfsdk->verifyCfCnameLive($cfCname, 1, 5));
            if ($cf_cname_live) {
                update_option('wpc_cf_cname_verified', 1, false);
            }


            if (!$this->isAgencyPortal()) {
                update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                }
            }


            $cf_report = [
                'bypass_rule' => WPC_CloudflareAPI::classifyResult($cf_bypass),
                'static_rule' => WPC_CloudflareAPI::classifyResult($cf_static),
                'whitelist'   => WPC_CloudflareAPI::classifyResult($cf_white),
                'cname'       => ['ok' => (bool) $cf_cname_live, 'mode' => $cf_cname_live ? 'ok' : 'pending',
                                  'detail' => $cf_cname_live ? 'Resolving (edge-live)' : 'Not yet edge-live — promotes automatically via the heartbeat once it propagates'],
                'v2_sync'     => ['ok' => true, 'mode' => 'scheduled',
                                  'detail' => 'Scheduled (non-blocking) — re-provisions out-of-band; confirms on the next heartbeat'],
            ];
            
            
            
            if (empty($cf_report['static_rule']['ok']) && ($cf_report['static_rule']['mode'] ?? '') === 'unreachable') {
                $cf_static = $cfsdk->patchStaticAssetsRespectOrigin($zoneInput);
                $cf_report['static_rule'] = WPC_CloudflareAPI::classifyResult($cf_static);
            }

            if (empty($cf_report['bypass_rule']['ok']) && ($cf_report['bypass_rule']['mode'] ?? '') === 'permission') {
                $cf_report['bypass_rule']['mode'] = 'pending';
                $cf_report['bypass_rule']['detail'] = 'Optional — add "Zone → Zone WAF → Edit" to the token to enable the security-bypass rule (guarantees our optimization fetches are never blocked by Cloudflare security). Everything else works without it.';
            }


            
            
            
            
            
            
            if (!$this->isAgencyPortal()) {
                try {
                    $wpc_nat820 = ['ok' => false, 'mode' => 'pending', 'detail' => 'Not verified'];
                    if (function_exists('wpc_v2_asset_mime_probe_run')) {
                        
                        
                        
                        
                        delete_transient('wpc_v2_cf_asset_mime_retry');
                        delete_transient('wpc_v2_asset_probe_inflight');
                        $wpc_hadproof820 = ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1');
                        $wpc_np820 = wpc_v2_asset_mime_probe_run();
                        $wpc_nl820 = get_option('wpc_v2_cf_asset_mime_last', []);
                        $wpc_nc820 = is_array($wpc_nl820) && isset($wpc_nl820['code']) ? (int) $wpc_nl820['code'] : 0;
                        $wpc_nt820 = is_array($wpc_nl820) && !empty($wpc_nl820['ct']) ? (string) $wpc_nl820['ct'] : '-';
                        if ($wpc_np820) {
                            $wpc_nat820 = ['ok' => true, 'mode' => 'ok',
                                'detail' => 'Natural asset URLs verified — the zone answered 200 + text/css; CSS/JS serve clean URLs'];
                            
                            
                            if (!$wpc_hadproof820) {
                                if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                                    wps_ic_cache::removeHtmlCacheFiles('all');
                                }
                                if (function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('natural-converged', '', '', ['src' => 'refresh']);
                                }
                            }
                        } else {
                            $wpc_nat820 = ['ok' => false, 'mode' => 'pending',
                                'detail' => 'Zone is not serving plain asset paths yet (HTTP ' . $wpc_nc820 . ', ' . $wpc_nt820
                                    . ') — needs natural-static routing enabled on the zone; re-checked hourly and applied automatically once it answers 200 + text/css'];
                        }
                    }
                    $cf_report['natural_assets'] = $wpc_nat820;
                } catch (\Throwable $e) {
                }
                try {
                    if (function_exists('wpc_corp_guard_tick')) {
                        $wpc_corp823 = wpc_corp_guard_tick(true);
                        if ($wpc_corp823 !== null) { $cf_report['corp_guard'] = $wpc_corp823; }
                    }
                } catch (\Throwable $e) {
                }
            }

            $wpc_privs88 = null;
            try {
                if (isset($cfsdk) && method_exists($cfsdk, 'checkPrivileges')) {
                    $wpc_privs88 = $cfsdk->checkPrivileges($zoneInput);
                    if (is_array($wpc_privs88)) {
                        update_option('wpc_cf_privileges', ['t' => time(), 'privs' => $wpc_privs88], false);
                    }
                }
            } catch (\Throwable $e) {
            }
            wp_send_json_success(['message' => 'cf-refreshed-successfully', 'report' => $cf_report, 'privileges' => $wpc_privs88]);
        }


        if ((string) get_option(WPS_IC_CF_CNAME) === '' && !$this->isAgencyPortal()) {
            update_option('wpc_cf_setup_pending', ['t' => time()], false);
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_cf_setup_retry', [1])) {
                wp_schedule_single_event(time() + 60, 'wpc_cf_setup_retry', [1]);
            }
            wpc_spawn_cron();
        }
        wp_send_json_success(['message' => 'cf-refresh-cname-pending', 'report' => [
            'cname' => ['ok' => false, 'mode' => 'pending',
                        'detail' => 'Our provisioning server is answering slowly — re-sync continues in the background automatically (no action needed)'],
        ]]);
    }


    public function wpc_cf_check_permissions()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings'))
            || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.'], 403);
        }
        $cf = get_option(WPS_IC_CF);
        if (empty($cf['token']) || empty($cf['zone'])) {
            wp_send_json_error(['msg' => 'Cloudflare is not connected on this site.', 'kind' => 'misconfig']);
        }
        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR') && file_exists(WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php')) {
            @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
        }
        if (!class_exists('WPC_CloudflareAPI')) {
            wp_send_json_error(['msg' => 'Cloudflare SDK unavailable.', 'kind' => 'unknown']);
        }
        try {
            $sdk   = new WPC_CloudflareAPI($cf['token']);
            $privs = $sdk->checkPrivileges($cf['zone']);
        } catch (\Throwable $e) {
            wp_send_json_error(['msg' => 'Permission check failed: ' . substr($e->getMessage(), 0, 120), 'kind' => 'unknown']);
        }
        if (is_wp_error($privs)) {
            wp_send_json_error(['msg' => $privs->get_error_message(), 'kind' => 'misconfig']);
        }
        update_option('wpc_cf_privileges', ['t' => time(), 'privs' => $privs], false);


        wp_send_json_success(['t' => time(), 'privileges' => $privs, 'purge_verified' => get_option('wpc_cf_purge_verified')]);
    }

    public function wpc_legacy_lever()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.'], 403);
        }
        $wpc_key846 = isset($_POST['lever']) ? sanitize_key((string) $_POST['lever']) : '';
        $wpc_on846  = (isset($_POST['on']) && (string) $_POST['on'] === '1') ? '1' : '0';
        $wpc_reg846 = function_exists('wpc_legacy_levers') ? wpc_legacy_levers() : [];
        if ($wpc_key846 === '' || !isset($wpc_reg846[$wpc_key846])) {
            wp_send_json_error(['msg' => 'Unknown lever.']);
        }
        if ($wpc_on846 === '1' && !(defined('WPC_LAB_UI') && WPC_LAB_UI)) {
            wp_send_json_error(['msg' => 'Enabling requires WPC_LAB_UI.']);
        }
        $wpc_s846 = get_option(WPS_IC_SETTINGS);
        if (!is_array($wpc_s846)) { $wpc_s846 = []; }
        $wpc_s846[$wpc_key846] = $wpc_on846;
        update_option(WPS_IC_SETTINGS, $wpc_s846);
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('legacy-flip', '', '', ['k' => $wpc_key846, 'on' => $wpc_on846]);
        }
        wp_send_json_success(['lever' => $wpc_key846, 'on' => $wpc_on846]);
    }

    public function wpc_natural_diag()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.'], 403);
        }
        $s     = get_option(WPS_IC_SETTINGS);
        $zone  = '';
        if (class_exists('wps_rewriteLogic') && !empty(wps_rewriteLogic::$zoneName)) {
            $zone = preg_replace('#/.*$#', '', trim((string) wps_rewriteLogic::$zoneName));
        }
        if ($zone === '') {
            $cf_cname = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $zone = $cf_cname !== '' ? $cf_cname : trim((string) get_option('ic_custom_cname', ''));
            if ($zone === '') { $zone = trim((string) get_option('ic_cdn_zone_name', '')); }
            $zone = preg_replace('#/.*$#', '', $zone);
        }
        $gates = [
            'live_cdn'   => is_array($s) && !empty($s['live-cdn']) && (string) $s['live-cdn'] === '1',
            'zone'       => $zone !== '',
            'suppressed' => function_exists('wpc_v2_zone_cdn_suppressed') ? (bool) wpc_v2_zone_cdn_suppressed() : false,
            'forced'     => (string) get_option('wpc_natural_force', '') === '1',
            'proven'     => (string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1',
        ];
        if (function_exists('wpc_v2_asset_mime_probe_run')) {
            delete_transient('wpc_v2_cf_asset_mime_retry');
            delete_transient('wpc_v2_asset_probe_inflight');
            $gates['proven'] = (bool) wpc_v2_asset_mime_probe_run($zone);
        }
        wp_send_json_success([
            'gates'  => $gates,
            'levers' => function_exists('wpc_legacy_lever_states') ? wpc_legacy_lever_states() : [],
            'zone'   => $zone,
            'canary' => $zone !== '' ? 'https://' . $zone . '/wp-includes/css/dist/block-library/style.min.css' : '',
            'last'   => get_option('wpc_v2_cf_asset_mime_last', []),
        ]);
    }

    public function wpc_img_diag()
    {
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['msg' => 'Forbidden.'], 403);
        }
        $id  = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        $url = isset($_REQUEST['url']) ? esc_url_raw((string) $_REQUEST['url']) : '';
        if ($id <= 0 && $url !== '' && function_exists('attachment_url_to_postid')) {
            $wpc_clean845 = (string) preg_replace('/\?.*$/', '', $url);
            $id = (int) attachment_url_to_postid($wpc_clean845);
            if ($id <= 0) {
                $id = (int) attachment_url_to_postid((string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $wpc_clean845));
            }
        }
        if ($id <= 0) {
            wp_send_json_error(['msg' => 'Pass ?id=<attachment id> or ?url=<full image url>.']);
        }
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : false;
        $file = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
        $sizes = [];
        if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sk => $sv) {
                $sizes[$sk] = (isset($sv['width']) ? (int) $sv['width'] : 0) . 'x' . (isset($sv['height']) ? (int) $sv['height'] : 0)
                    . (empty($sv['file']) ? ' NO-FILE' : '');
            }
        }
        wp_send_json_success([
            'id'             => $id,
            'file'           => $file,
            'file_exists'    => $file !== '' && @file_exists($file),
            'mime'           => function_exists('get_post_mime_type') ? (string) get_post_mime_type($id) : '',
            'meta_ok'        => is_array($meta) && !empty($meta['file']),
            'full'           => is_array($meta) ? ((int) ($meta['width'] ?? 0)) . 'x' . ((int) ($meta['height'] ?? 0)) . ' ' . (string) ($meta['file'] ?? '') : null,
            'sizes'          => $sizes,
            'processable'    => (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'is_processable'))
                ? WPC_Modern_Delivery::is_processable($id) : null,
            'offloaded'      => (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'is_offloaded'))
                ? WPC_Modern_Delivery::is_offloaded($id) : null,
            'ic_status'      => function_exists('get_post_meta') ? get_post_meta($id, 'ic_status', true) : null,
            'url'            => function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '',
            'cdn_exclude_hit' => call_user_func(function () use ($id) {
                $u = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';
                $x = get_option('wpc-excludes', []);
                $l = (is_array($x) && !empty($x['cdn']) && is_array($x['cdn'])) ? $x['cdn'] : [];
                foreach ($l as $t) {
                    if ((string) $t !== '' && $u !== '' && strpos($u, (string) $t) !== false) { return (string) $t; }
                }
                return '';
            }),
            'cdn_excludes'   => call_user_func(function () {
                $x = get_option('wpc-excludes', []);
                return (is_array($x) && !empty($x['cdn']) && is_array($x['cdn'])) ? array_slice(array_values($x['cdn']), 0, 50) : [];
            }),
            'local_variants' => function_exists('get_post_meta') ? get_post_meta($id, 'ic_local_variants', true) : null,
        ]);
    }

    public function wpc_natural_force()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.'], 403);
        }
        $on = isset($_POST['on']) && (string) $_POST['on'] === '1';
        if ($on) {
            update_option('wpc_natural_force', '1', true);
        } else {
            delete_option('wpc_natural_force');
        }
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('natural-forced', '', '', ['on' => $on ? 1 : 0]);
        }
        wp_send_json_success(['forced' => $on]);
    }

    public function wpc_ic_setupCF()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $requests  = new wps_ic_requests();
        $token     = sanitize_text_field($_POST['token']);
        $zoneInput = sanitize_text_field($_POST['zone']);

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey  = sanitize_text_field($_POST['apikey'] ?? '');
            $cf      = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getRemoteCFOption($apikey) : [];
            $siteUrl = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getSiteUrl($apikey) : site_url();
        } else {
            $options = get_option(WPS_IC_OPTIONS);
            $apikey  = $options['api_key'];
            $cf      = get_option(WPS_IC_CF);
            $siteUrl = site_url();
        }

        $zoneName = str_replace(['http://', 'https://', '/'], '', $siteUrl);

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'setupCF', 'token' => $token, 'zone' => $zoneInput, 'siteUrl' => $siteUrl, 'zoneName' => $cf['zoneName'] ?? $zoneName, 'staticAssets' => '1', 'htmlCache' => 'all', 'cdn' => '1', 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 30]); 


        $wpc_keys_ok = (!empty($body) && isset($body->data));
        $data        = $wpc_keys_ok ? (array) $body->data : [];
        $cfCname     = $wpc_keys_ok ? (string) ($data['cfName'] ?? '') : '';
        $prevCfCname = (string) get_option(WPS_IC_CF_CNAME);

        $cf['settings'] = ['assets' => '1', 'edge-cache' => 'all', 'cdn' => '1'];
        if ($cfCname !== '') {
            $cf['custom_cname'] = $cfCname;
        }

        if ($this->isAgencyPortal()) {
            if ($wpc_keys_ok && !empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->saveRemoteCFOption($apikey, $cf, $cfCname);
            }
        } else {
            update_option(WPS_IC_CF, $cf);
            if ($cfCname !== '') {
                update_option(WPS_IC_CF_CNAME, $cfCname);


                if ((string) $cfCname !== $prevCfCname) {
                    update_option('wpc_cf_cname_verified', '0', false);
                }
            }
        }

        $cfsdk = new WPC_CloudflareAPI($token);
        
        $cf_bypass = $cfsdk->addCdnBypassRule($zoneInput);
        $cf_white  = $cfsdk->whitelistIPs($zoneInput);
        $cf_static = $cfsdk->patchStaticAssetsRespectOrigin($zoneInput);
        if (method_exists($cfsdk, 'patchHtmlRulesRespectOrigin')) { $cfsdk->patchHtmlRulesRespectOrigin($zoneInput, null, true); }

        
        $cf_cname_live = ($cfCname !== '' && !$this->isAgencyPortal() && method_exists($cfsdk, 'verifyCfCnameLive')
            && $cfsdk->verifyCfCnameLive($cfCname, 1, 5));
        if ($cf_cname_live) {
            update_option('wpc_cf_cname_verified', 1, false);
        }

        


        if (!$this->isAgencyPortal()) {
            update_option('wpc_v2_force_provision', 1, false);
            if (function_exists('wpc_v2_schedule_config_sync')) {
                wpc_v2_schedule_config_sync();
            }
            if (!$wpc_keys_ok) {

                update_option('wpc_cf_setup_pending', ['t' => time(), 'zone' => $zoneInput], false);
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_cf_setup_retry', [1])) {
                    wp_schedule_single_event(time() + 60, 'wpc_cf_setup_retry', [1]);
                }
                if (function_exists('spawn_cron')) {
                    wpc_spawn_cron();
                }
            } else {
                delete_option('wpc_cf_setup_pending');
            }
        }

        
        
        $cf_report = [
            'bypass_rule' => WPC_CloudflareAPI::classifyResult($cf_bypass),
            'static_rule' => WPC_CloudflareAPI::classifyResult($cf_static),
            'whitelist'   => WPC_CloudflareAPI::classifyResult($cf_white),
            'cname'       => $wpc_keys_ok
                ? ['ok' => (bool) $cf_cname_live, 'mode' => $cf_cname_live ? 'ok' : 'pending',
                   'detail' => $cf_cname_live ? 'Resolving (edge-live)' : 'Not yet edge-live — promotes automatically via the heartbeat once it propagates']
                : ['ok' => false, 'mode' => 'pending',
                   'detail' => 'Our provisioning server is answering slowly — the CDN hostname is being finished in the background automatically (no action needed)'],
            'v2_sync'     => ['ok' => true, 'mode' => 'scheduled',
                              'detail' => 'Scheduled (non-blocking) — provisions out-of-band; confirms on the next heartbeat'],
        ];
        
        
        
        
        
        
        
        
        if (empty($cf_report['static_rule']['ok']) && ($cf_report['static_rule']['mode'] ?? '') === 'unreachable') {
            $cf_static = $cfsdk->patchStaticAssetsRespectOrigin($zoneInput);
            $cf_report['static_rule'] = WPC_CloudflareAPI::classifyResult($cf_static);
        }

        if (empty($cf_report['bypass_rule']['ok']) && ($cf_report['bypass_rule']['mode'] ?? '') === 'permission') {
            $cf_report['bypass_rule']['mode'] = 'pending';
            $cf_report['bypass_rule']['detail'] = 'Optional — add "Zone → Zone WAF → Edit" to the token to enable the security-bypass rule (guarantees our optimization fetches are never blocked by Cloudflare security). Everything else works without it.';
        }

        wp_send_json_success(['message' => $wpc_keys_ok ? 'cf-connected-successfully' : 'cf-connected-cname-pending', 'report' => $cf_report]);
    }


    public function wpc_send_critical_remote() {
        
        
        $wpc_src330 = !empty($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
        $wpc_sh330  = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $wpc_oh330  = strtolower((string) parse_url($wpc_src330, PHP_URL_HOST));
        $wpc_strip330 = function ($h) { return strpos((string) $h, 'www.') === 0 ? substr($h, 4) : (string) $h; };
        if ($wpc_oh330 === '' || $wpc_strip330($wpc_oh330) !== $wpc_strip330($wpc_sh330)) {
            wp_send_json_error('bad-origin');
        }

        $criticalCSS = new wps_criticalCss();

        $realUrl = urldecode($_POST['realUrl']);
        $realUrl = sanitize_text_field($realUrl);
        $postID = sanitize_text_field($_POST['postID']);

        


        if (strpos($realUrl, 'https://') === false && strpos($realUrl, 'http://') === false) {
            $realUrl = 'https://' . $realUrl;
        }

        


        $keys = new wps_ic_url_key();

        $allowed_params = $keys->get_allowed_params();
        $parsed_url = parse_url($realUrl);
        parse_str((string) ($parsed_url['query'] ?? ''), $query_params);

        
        $filtered_params = array_intersect_key($query_params, array_flip($allowed_params));

        
        $disallowed_params = array_diff_key($query_params, array_flip($allowed_params));

        if (!empty($disallowed_params)) {
            wp_send_json_success('skipped');
        }

        
        $new_query = http_build_query($filtered_params);

        
        $wpc_rh330 = strtolower((string) ($parsed_url['host'] ?? ''));
        if ($wpc_rh330 === '' || $wpc_strip330($wpc_rh330) !== $wpc_strip330($wpc_sh330)) {
            wp_send_json_success('skipped');
        }

        
        $realUrl = $parsed_url['host'] . (isset($parsed_url['path']) ? $parsed_url['path'] : '') . '?' . $new_query;
        $realUrl = rtrim($realUrl, '?');
        $realUrl = rtrim($realUrl, '/');

        


        $criticalCSSExists = $criticalCSS->criticalExistsAjax($realUrl);
        if (!empty($criticalCSSExists)) {
            wp_send_json_success(['exists', $realUrl, $criticalCSSExists]);
        }

        


        $ccss_debug = get_option('ccss_debug');
        if (empty($ccss_debug) || $ccss_debug == 'false') {
            
            $running = get_transient('wpc_critical_ajax_' . md5($realUrl));
            if (!empty($running) && $running == 'true') {
                wp_send_json_success(['already-running', $realUrl]);
            }
        }


        $home = false;
        $home_url = rtrim(home_url(), '/');
        $realUrl_stripped = preg_replace('#^https?://#', '', $realUrl);
        $home_url_stripped = preg_replace('#^https?://#', '', $home_url);

        if ($home_url_stripped == $realUrl_stripped) {
            $home = true;
        }

        
        set_transient('wpc_critical_ajax_' . md5($realUrl), 'true', 60);

        $criticalCSS->sendCriticalUrl($realUrl, 0);

        wp_send_json_success(array('sent', $realUrl));
    }


    public function wpc_send_critical_remote_old()
    {
        $criticalCSS = new wps_criticalCss();

        $realUrl = urldecode($_POST['realUrl']);
        $realUrl = sanitize_text_field($realUrl);
        $postID = sanitize_text_field($_POST['postID']);

        


        $keys = new wps_ic_url_key();

        $allowed_params = $keys->get_allowed_params();
        $parsed_url = parse_url($realUrl);
        parse_str((string) ($parsed_url['query'] ?? ''), $query_params);

        
        $filtered_params = array_intersect_key($query_params, array_flip($allowed_params));

        
        $disallowed_params = array_diff_key($query_params, array_flip($allowed_params));

        if (!empty($disallowed_params)) {
            wp_send_json_success('skipped');
        }

        
        $new_query = http_build_query($filtered_params);

        
        $realUrl = $parsed_url['host'] . (isset($parsed_url['path']) ? $parsed_url['path'] : '') . '?' . $new_query;
        $realUrl = rtrim($realUrl, '?');
        $realUrl = rtrim($realUrl, '/');

        


        $criticalCSSExists = $criticalCSS->criticalExistsAjax($realUrl);
        if (!empty($criticalCSSExists)) {
            wp_send_json_success(['exists', $realUrl, $criticalCSSExists]);
        }


        


        $ccss_debug = get_option('ccss_debug');
        if (empty($ccss_debug) || $ccss_debug == 'false') {
            $running = get_transient('wpc_critical_ajax_' . $postID);
            if (!empty($running) && $running == 'true') {
                wp_send_json_success(['already-running', $realUrl]);
            }
        }


        $home = false;
        $home_url = rtrim(home_url(), '/');
        $realUrl_stripped = preg_replace('#^https?://#', '', $realUrl);
        $home_url_stripped = preg_replace('#^https?://#', '', $home_url);

        if ($home_url_stripped == $realUrl_stripped) {
            $home = true;
        }

        
        set_transient('wpc_critical_ajax_' . $postID, 'true', 60);

        $requests = new wps_ic_requests();

        if (!empty($home)) {
            $args = ['url' => (function_exists('wpc_canon_url609') ? wpc_canon_url609($realUrl) : $realUrl) . '?criticalCombine=true&testCompliant=true', 'source' => 'admin-ajax', 'version' => self::$version, 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
        } else {
            $args = ['url' => (function_exists('wpc_canon_url609') ? wpc_canon_url609($realUrl) : $realUrl) . '?criticalCombine=true&testCompliant=true', 'source' => 'admin-ajax', 'home' => $home_url, 'version' => self::$version, 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
        }
        if (function_exists('wpc_sanity_escalate622')) {
            $args = wpc_sanity_escalate622($args, $realUrl);
        }


        $wpc_set108 = get_option(WPS_IC_SETTINGS);
        if (is_array($wpc_set108) && (!isset($wpc_set108['delay-js-v2']) || $wpc_set108['delay-js-v2'] == '1')
            && (!isset($wpc_set108['delay-js-v3']) || $wpc_set108['delay-js-v3'] != '0')
            && apply_filters('wpc_delay_manifest_capability', true)) {
            $args['capabilities'] = ['delay_manifest' => 1, 'consolidated_callback' => 1];
        }
        try {
            $wpc_keys108   = new wps_ic_url_key();
            $wpc_urlkey108 = $wpc_keys108->setup($realUrl);
            if (!empty($wpc_urlkey108)) {
                if (function_exists('wpc_used_css_apply_demand')) {
                    wpc_used_css_apply_demand($args, $wpc_urlkey108);
                }
                if (empty($args['tpl_key']) && function_exists('wpc_dispatch_tpl_key')
                    && apply_filters('wpc_send_tpl_key_always', true)) {
                    $wpc_dtk108 = wpc_dispatch_tpl_key($wpc_urlkey108);
                    if ($wpc_dtk108 !== '') { $args['tpl_key'] = $wpc_dtk108; }
                }
            }
        } catch (\Throwable $wpc_e108) {

        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('gen-dispatch', isset($wpc_urlkey108) ? (string) $wpc_urlkey108 : '', (string) $realUrl, [
                'path' => 'runCriticalAjax',
                'caps' => isset($args['capabilities']) && is_array($args['capabilities']) ? implode(',', array_keys($args['capabilities'])) : '',
            ]);
        }

        if (!empty($home)) {
            $call = $requests->GET(self::$CRITICAL_URL_HOME, $args, ['timeout' => 2, 'blocking' => false]);
        } else {
            $call = $requests->GET(self::$API_URL, $args, ['timeout' => 3, 'blocking' => false]);
        }

        wp_send_json_success('sent');
    }

    public function wps_fetchInitialTest()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);
        if (!empty($initialPageSpeedScore) && !empty($initialPageSpeedScore['result'])) {
            wp_send_json_success('done');
        }

        wp_send_json_error('not-done ' . print_r($initialPageSpeedScore, true));
    }


    public function custom_merge(array $array1, array $array2)
    {
        $result = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                
                $result[$key] = $this->custom_merge($result[$key], $value);
            } elseif (!isset($result[$key])) {
                
                $result[$key] = $value;
            }
        }

        return $result;
    }

    


    public function wps_ic_settings_change()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        global $wps_ic;

        $what = sanitize_text_field($_POST['what']);
        $value = sanitize_text_field($_POST['value']);
        $checked = sanitize_text_field($_POST['checked']);
        $checkbox = sanitize_text_field($_POST['checkbox']);


        $options = new wps_ic_options();
        $settings = $options->get_settings();

        if ($what == 'thumbnails') {
            if (!isset($value) || empty($value)) {
                $settings['thumbnails'] = [];
            } else {
                $settings['thumbnails'] = [];
                $value = rtrim($value, ',');
                $value = explode(',', $value);
                foreach ($value as $i => $thumb_size) {
                    $settings['thumbnails'][$thumb_size] = 1;
                }
            }
        } else {
            if ($what == 'autopilot') {
                if ($checked == 'checked') {
                } else {
                    $settings['otto'] = 'automated';
                }
            }

            if ($checkbox == 'true') {
                if ($checked === 'false') {
                    $settings[$what] = 0;
                } else {
                    $settings[$what] = 1;
                }
            } else {
                $settings[$what] = $value;
            }
        }

        if ($what == 'live_autopilot') {
            if ($value == '1') {
                
                delete_option('wps_ic_bg_stop');
                delete_option('wps_ic_bg_process_stop');
                delete_option('wps_ic_bg_stopping');
                delete_option('wps_ic_bg_process');
                delete_option('wps_ic_bg_process_done');
                delete_option('wps_ic_bg_process_running');
                delete_option('wps_ic_bg_process_stats');
                delete_option('wps_ic_bg_last_run_compress');
                delete_option('wps_ic_bg_last_run_restore');
            }
        } elseif ($what == 'css' || $what == 'js') {
            
            $this->purge_cdn_assets();
        }

        self::$cacheIntegrations->purgeAll();

        update_option(WPS_IC_SETTINGS, $settings);

        wp_send_json_success();
    }

    public function purge_cdn_assets()
    {
        $options = get_option(WPS_IC_OPTIONS);

        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'domain' => site_url(), 'apikey' => $options['api_key']]);

        if (!empty($call)) {
            if ($call->success == 'true') {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function wps_ic_ajax_checkbox()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $setting_name = sanitize_text_field($_POST['setting_name']);
        $setting_value = sanitize_text_field($_POST['value']);
        $setting_checked = sanitize_text_field($_POST['checked']);

        $settings = get_option(WPS_IC_SETTINGS);

        $value = ($setting_checked == 'false') ? '0' : '1';

        
        preg_match_all('/\[([^\]]+)\]/', $setting_name, $matches);
        $keys = !empty($matches[1]) ? $matches[1] : [$setting_name];

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $wpc_pillar148 = (count($keys) === 2) ? [$keys[0], $keys[1]] : $keys[0];
        if (function_exists('wpc_lite_pillar_riders148')) {
            $settings = wpc_lite_pillar_riders148($settings, $wpc_pillar148, $value);
        } elseif (count($keys) === 2) {
            $settings[$keys[0]][$keys[1]] = $value;
        } else {
            $settings[$keys[0]] = $value;
        }

        if ($settings['live-cdn'] == '0') {
            $settings['js'] = '0';
            $settings['css'] = '0';
        }

        update_option(WPS_IC_SETTINGS, $settings);


        self::purgeBreeze();
        self::purge_cache_files();

        
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        wp_send_json_success(['new_value' => $value, 'setting_name' => $setting_name, 'value' => $setting_value]);
    }

    


    public static function purgeBreeze()
    {
        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
            }
        }
    }

    


    public static function purge_cache_files()
    {
        $cache_dir = WPS_IC_CACHE;

        self::removeDirectory($cache_dir);

        return true;
    }


    public static function removeDirectory($path)
    {
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }
    }

    public function wps_ic_dismiss_notice()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $notice_dismiss_info = get_option('wps_ic_notice_info');
        $tag = sanitize_text_field($_POST['id']);

        if (!empty ($tag)) {
            $notice_dismiss_info[$tag] = 0;
            update_option('wps_ic_notice_info', $notice_dismiss_info);
            wp_send_json_success();
        }
        wp_send_json_error();

    }

    public function wps_ic_fix_notice()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $plugin = sanitize_text_field($_POST['plugin']);
        $setting = sanitize_text_field($_POST['setting']);

        if (!empty($plugin) && !empty($setting)) {
            $integrations = new wps_ic_integrations();
            $fix = $integrations->fix($plugin, $setting);

            if ($fix) {
                wp_send_json_success();
            }
        }
        wp_send_json_error();

    }

    


    public function wps_ic_get_setting()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $option_name   = sanitize_text_field($_POST['name']);
        $option_subset = sanitize_text_field($_POST['subset']);

        if (!in_array($option_name, ['wpc-excludes', 'wpc-inline', 'wpc-url-excludes'])) {
            wp_send_json_error('Forbidden.');
        }

        
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $data = $api::$comms->getRemoteExcludes($apikey, $option_name, $option_subset);
                wp_send_json_success($data);
            }
            wp_send_json_error('missing-apikey');
        }

        $option = get_option($option_name);
        $value = $option[$option_subset];
        $default_excludes = $option[$option_subset . '_default_excludes_disabled'];
        $exclude_themes = $option[$option_subset . '_exclude_themes'];
        $exclude_plugins = $option[$option_subset . '_exclude_plugins'];
        $exclude_wp = $option[$option_subset . '_exclude_wp'];
        $exclude_third = $option[$option_subset . '_exclude_third'];
        $min_mobile_width = get_option('wpc-min-mobile-width');

        if (empty($value)) {
            $value = '';
        } else {
            $value = implode("\n", $value);
        }

        wp_send_json_success(['value' => $value, 'default_excludes' => $default_excludes, 'exclude_themes' => $exclude_themes, 'exclude_plugins' => $exclude_plugins, 'exclude_wp' => $exclude_wp, 'exclude_third' => $exclude_third, 'min_mobile_width' => $min_mobile_width]);
    }

    public function wps_ic_save_excludes_settings()
    {

        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $setting_name = sanitize_text_field($_POST['setting_name']);
        $setting_group = sanitize_text_field($_POST['group_name']);

        
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $data = [
                    'groupName'        => $setting_group,
                    'settingName'      => $setting_name,
                    'value'            => $_POST['excludes'] ?? '',
                    'default_enabled'  => sanitize_text_field($_POST['default_enabled']  ?? '0'),
                    'exclude_themes'   => sanitize_text_field($_POST['exclude_themes']   ?? '0'),
                    'exclude_plugins'  => sanitize_text_field($_POST['exclude_plugins']  ?? '0'),
                    'exclude_wp'       => sanitize_text_field($_POST['exclude_wp']       ?? '0'),
                    'exclude_third'    => sanitize_text_field($_POST['exclude_third']    ?? '0'),
                    'min_mobile_width' => sanitize_text_field($_POST['min_mobile_width'] ?? 'false'),
                ];
                $api::$comms->sendSiteExcludes($apikey, $data);
            }
            wp_send_json_success();
        }

        if ($setting_group == 'wpc-url-excludes') {
            
            $excludes = $_POST['excludes'];
            $excludes = rtrim($excludes, "\n");
            $excludes = explode("\n", $excludes);


            $wpc_excludes = get_option($setting_group);
            $wpc_excludes[$setting_name] = $excludes;

            $updated = update_option($setting_group, $wpc_excludes);
        } elseif ($setting_group == 'wpc-excludes' || $setting_group == 'wpc-inline') {
            $excludes = self::wpc_split_patterns19($_POST['excludes']);

            $default_enabled = sanitize_text_field($_POST['default_enabled']);
            $exclude_themes = sanitize_text_field($_POST['exclude_themes']);
            $exclude_plugins = sanitize_text_field($_POST['exclude_plugins']);
            $exclude_wp = sanitize_text_field($_POST['exclude_wp']);
            $exclude_third = sanitize_text_field($_POST['exclude_third']);
            $min_mobile_width = sanitize_text_field($_POST['min_mobile_width']);


            $wpc_excludes = get_option($setting_group);
            $wpc_excludes[$setting_name] = $excludes;
            $wpc_excludes[$setting_name . '_default_excludes_disabled'] = $default_enabled;
            $wpc_excludes[$setting_name . '_exclude_themes'] = $exclude_themes;
            $wpc_excludes[$setting_name . '_exclude_plugins'] = $exclude_plugins;
            $wpc_excludes[$setting_name . '_exclude_wp'] = $exclude_wp;
            $wpc_excludes[$setting_name . '_exclude_third'] = $exclude_third;

            
            if (isset($_POST['lastLoadScript'])) {
                $wpc_excludes['lastLoadScript'] = self::wpc_split_patterns19(sanitize_textarea_field($_POST['lastLoadScript']));
            }

            if (isset($_POST['deferScript'])) {
                $wpc_excludes['deferScript'] = self::wpc_split_patterns19(sanitize_textarea_field($_POST['deferScript']));
            }

            if ($min_mobile_width !== 'false') {
                $updated1 = update_option('wpc-min-mobile-width', $min_mobile_width);
            }

            $updated2 = update_option($setting_group, $wpc_excludes);

            $updated = $updated1 || $updated2;
        } else {
            wp_send_json_error('Forbidden.');
        }


        if ($updated) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();

            if ($setting_name == 'combine_js' || $setting_name == 'css_combine' || $setting_name == 'delay_js') {
                $cache::purgeCombinedFiles();
            }

            if ($setting_name == 'critical_css') {
                
                
                if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead()) {
                    $cache::purgeCriticalFiles();
                }
            }


        }


        wp_send_json_success($wpc_excludes);

    }

    


    public function wps_ic_critical_run()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $criticalCSS = new wps_criticalCss();
        $criticalCSS->sendCriticalUrl('', $_POST['pageID']);
        wp_send_json_success();
    }

    public function wps_ic_pull_stats()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = get_option(WPS_IC_OPTIONS);

        self::$Requests->GET(WPS_IC_KEYSURL, ['apikey' => $options['api_key'], 'action' => 'pullStats']);
        wp_send_json_success();
    }

    


    public function wps_ic_critical_get_assets()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $criticalCSS = new wps_criticalCss();
        $count = $criticalCSS->sendCriticalUrlGetAssets('', $_POST['pageID']);
        wp_send_json_success($count);
    }

    


    public function wps_ic_ajax_v2_checkbox()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $options = get_option(WPS_IC_SETTINGS);
        $wpc_livecdn_before = isset($options['live-cdn']) ? (string) $options['live-cdn'] : '';

        $optionName = sanitize_text_field($_POST['optionName']);
        $optionValue = sanitize_text_field($_POST['optionValue']);

        $optionName = explode(',', $optionName);

        
        if (is_array($optionName) && count($optionName) > 1 && $optionName[0] === 'cf') {
            $cf = get_option(WPS_IC_CF);
            if (!empty($cf)) {
                if (!isset($cf['settings'])) {
                    $cf['settings'] = ['assets' => '1', 'edge-cache' => 'all', 'cdn' => '1'];
                }
                $newValue = $cf['settings'][$optionName[1]] = $optionValue;
                update_option(WPS_IC_CF, $cf);
            } else {
                $newValue = $optionValue;
            }
        } elseif (is_array($optionName) && count($optionName) > 1) {
            $newValue = $options[$optionName[0]][$optionName[1]] = $optionValue;


            if ($optionName[0] === 'serve') {
                $cdnOn = false;
                $imageServeKeys = ['jpg', 'png', 'gif', 'svg'];
                if (isset($options['serve'])) {
                    foreach ($imageServeKeys as $k) {
                        if (!empty($options['serve'][$k]) && $options['serve'][$k] == '1') { $cdnOn = true; break; }
                    }
                }
                if (!$cdnOn && !empty($options['css']) && $options['css'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($options['js']) && $options['js'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($options['fonts']) && $options['fonts'] == '1') $cdnOn = true;
                $options['live-cdn'] = $cdnOn ? '1' : '0';
            }

            update_option(WPS_IC_SETTINGS, $options);
        } else {
            $optionName = $optionName[0];
            $newValue = $options[$optionName] = $optionValue;

            
            if ($optionName === 'replace-fonts' && $newValue === 'local') {
                $fontsMap = get_option(WPS_IC_FONTS_MAP);
                if (empty($fontsMap)) {
                    $fonts = new wps_ic_fonts();
                    $response = $fonts->callAPI(site_url());
                    $found = $fonts->scanForFonts($response);
                    $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);
                    if ($hasGoogleFonts) {
                        $fonts->readGoogleStylesheet($found);
                    }
                }
            }

            
            if (in_array($optionName, ['css', 'js', 'fonts'])) {
                $cdnOn = false;
                $imageServeKeys = ['jpg', 'png', 'gif', 'svg'];
                if (!empty($options['serve'])) {
                    foreach ($imageServeKeys as $k) {
                        if (!empty($options['serve'][$k]) && $options['serve'][$k] == '1') { $cdnOn = true; break; }
                    }
                }
                if (!$cdnOn && !empty($options['css']) && $options['css'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($options['js']) && $options['js'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($options['fonts']) && $options['fonts'] == '1') $cdnOn = true;
                $options['live-cdn'] = $cdnOn ? '1' : '0';
            }

            update_option(WPS_IC_SETTINGS, $options);
        }


        $wpc_livecdn_after = isset($options['live-cdn']) ? (string) $options['live-cdn'] : $wpc_livecdn_before;
        if ($wpc_livecdn_before !== $wpc_livecdn_after) {
            self::wpc_fleet_frontend_purge('per-key save');
        }

        wp_send_json_success(['newValue' => $newValue, 'optionName' => $optionName]);
    }

    



    public function wps_ic_ajax_v2_checkbox_batch()
    {
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        if (!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $changes = json_decode(stripslashes($_POST['changes']), true);
        if (empty($changes) || !is_array($changes)) {
            wp_send_json_error(['message' => 'No changes provided']);
            wp_die();
        }


        $options = $this->isAgencyPortal() ? [] : get_option(WPS_IC_SETTINGS);
        $cf = null;
        $serveChanged = false;
        $assetChanged = false;
        $imagesChanged = false;
        $nextgenChanged = false;
        $overrideChanged = false;

        foreach ($changes as $change) {
            $optionName = explode(',', sanitize_text_field($change['name']));
            $optionValue = sanitize_text_field($change['value']);

            if (count($optionName) > 1 && $optionName[0] === 'cf') {
                
                if ($cf === null) {
                    $cf = get_option(WPS_IC_CF);
                    if (!empty($cf) && !isset($cf['settings'])) {
                        $cf['settings'] = ['assets' => '1', 'edge-cache' => 'all', 'cdn' => '1'];
                    }
                }
                if (!empty($cf)) {
                    $cf['settings'][$optionName[1]] = $optionValue;
                }
            } elseif (count($optionName) > 1 && function_exists('wpc_lite_is_pillar148')
                      && wpc_lite_is_pillar148([$optionName[0], $optionName[1]])) {
                
                
                
                
                $options = wpc_lite_pillar_riders148($options, [$optionName[0], $optionName[1]], $optionValue);
            } elseif (count($optionName) > 1) {
                $options[$optionName[0]][$optionName[1]] = $optionValue;
                if ($optionName[0] === 'serve') {
                    $serveChanged = true;
                    if (isset($optionName[1]) && $optionName[1] === 'jpg') $imagesChanged = true;
                }
            } elseif (function_exists('wpc_lite_is_pillar148') && wpc_lite_is_pillar148($optionName[0])) {
                $options = wpc_lite_pillar_riders148($options, $optionName[0], $optionValue);
                if ($optionName[0] === 'cdnAll') {
                    
                    
                    $serveChanged = true;
                    $assetChanged = true;
                }
                if ($optionName[0] === 'imagesPreset') {
                    $nextgenChanged = true; 
                }
            } else {
                $name = $optionName[0];
                $options[$name] = $optionValue;
                if (in_array($name, ['css', 'js', 'fonts'])) $assetChanged = true;
                if ($name === 'wpc_nextgen') $nextgenChanged = true; 
                if (class_exists('WPC_Delivery_Resolver') && $name === WPC_Delivery_Resolver::OVERRIDE_OPTION) $overrideChanged = true; 

                
                if ($name === 'replace-fonts' && $optionValue === 'local') {
                    $fontsMap = get_option(WPS_IC_FONTS_MAP);
                    if (empty($fontsMap)) {
                        $fonts = new wps_ic_fonts();
                        $response = $fonts->callAPI(site_url());
                        $found = $fonts->scanForFonts($response);
                        $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);
                        if ($hasGoogleFonts) {
                            $fonts->readGoogleStylesheet($found);
                        }
                    }
                }
            }
        }


        if ($imagesChanged && isset($options['serve']) && is_array($options['serve'])) {
            $imgVal = (!empty($options['serve']['jpg']) && $options['serve']['jpg'] == '1') ? '1' : '0';
            $options['serve']['png'] = $imgVal;
            $options['serve']['gif'] = $imgVal;
            $options['serve']['svg'] = $imgVal;
        }


        if ($nextgenChanged && class_exists('WPC_Delivery_Resolver')) {
            $m = strtolower((string) ($options['wpc_nextgen'] ?? ''));
            if (!in_array($m, ['auto', 'webp', 'off'], true)) $m = 'auto';
            $options['wpc_nextgen'] = $m;


            if ($m !== 'off' && function_exists('set_transient')) {
                set_transient('wpc_ngd_just_enabled', 1, defined('MINUTE_IN_SECONDS') ? 5 * MINUTE_IN_SECONDS : 300);
            }
            $ceiling = ($m === 'auto') ? 'avif' : $m;
            if (method_exists('WPC_Delivery_Resolver', 'settings_for_ceiling')) {
                foreach (WPC_Delivery_Resolver::settings_for_ceiling($ceiling) as $k => $v) {
                    $options[$k] = $v;
                }
            }
        }


        if ($overrideChanged && class_exists('WPC_Delivery_Resolver')) {
            $ov = strtolower((string) ($options[WPC_Delivery_Resolver::OVERRIDE_OPTION] ?? 'auto'));
            if (!in_array($ov, ['auto', 'picture', 'htaccess', 'cdn', 'edge'], true)) $ov = 'auto';
            $options[WPC_Delivery_Resolver::OVERRIDE_OPTION]    = $ov;
            $options[WPC_Delivery_Resolver::EDGE_ORIGIN_OPTION] = ($ov === 'edge') ? '1' : '0';
        }

        
        if ($serveChanged || $assetChanged) {
            $cdnOn = false;
            
            $imageServeKeys = ['jpg', 'png', 'gif', 'svg'];
            if (!empty($options['serve'])) {
                foreach ($imageServeKeys as $k) {
                    if (!empty($options['serve'][$k]) && $options['serve'][$k] == '1') { $cdnOn = true; break; }
                }
            }
            if (!$cdnOn && !empty($options['css']) && $options['css'] == '1') $cdnOn = true;
            if (!$cdnOn && !empty($options['js']) && $options['js'] == '1') $cdnOn = true;
            if (!$cdnOn && !empty($options['fonts']) && $options['fonts'] == '1') $cdnOn = true;
            $options['live-cdn'] = $cdnOn ? '1' : '0';
        }


        if (apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))) {
            if (!isset($options['serve']) || !is_array($options['serve'])) {
                $options['serve'] = isset($options['serve']) ? (array) $options['serve'] : [];
            }
            $options['serve']['fonts'] = (!empty($options['fonts']) && $options['fonts'] == '1') ? '1' : '0';
        }

        
        if (isset($options['qualityLevel'])) {
            $qualityMap = ['1' => 'lossless', '2' => 'intelligent', '3' => 'ultra'];
            $options['optimization'] = $qualityMap[$options['qualityLevel']] ?? 'intelligent';
        }

        
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->sendSiteSettings($apikey, ['apikey' => $apikey, 'options' => $options]);
            }
            wp_send_json_success(['saved' => count($changes)]);
        }

        
        $prevSettings = get_option(WPS_IC_SETTINGS, []);
        $prevModern = !empty($prevSettings['modern_image_delivery']) && $prevSettings['modern_image_delivery'] == '1';
        $newModern = !empty($options['modern_image_delivery']) && $options['modern_image_delivery'] == '1';
        $modernFlippedOn = !$prevModern && $newModern;
        $wpc_livecdn_before_b = isset($prevSettings['live-cdn']) ? (string) $prevSettings['live-cdn'] : '';

        update_option(WPS_IC_SETTINGS, $options);

        
        


        if ($wpc_livecdn_before_b !== (isset($options['live-cdn']) ? (string) $options['live-cdn'] : $wpc_livecdn_before_b)) {
            self::wpc_fleet_frontend_purge('batch save');
        }


        if (($serveChanged || $assetChanged || $nextgenChanged || $overrideChanged) && class_exists('WPC_Delivery_Resolver')) {
            if (!wp_next_scheduled('wpc_delivery_verify')) {
                wp_schedule_single_event(time(), 'wpc_delivery_verify');
            }
            if (function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }


            $vtok = function_exists('wp_generate_password') ? wp_generate_password(20, false, false) : md5(uniqid('', true));
            set_transient('wpc_delivery_verify_tok', $vtok, 120);


            $dv_parts = wp_parse_url(admin_url('admin-ajax.php'));
            if (!empty($dv_parts['host'])) {
                $dv_https = (!empty($dv_parts['scheme']) && $dv_parts['scheme'] === 'https');
                $dv_port  = !empty($dv_parts['port']) ? (int) $dv_parts['port'] : ($dv_https ? 443 : 80);
                $dv_host  = (string) $dv_parts['host'];
                $dv_path  = (!empty($dv_parts['path']) ? $dv_parts['path'] : '/') . '?action=wpc_delivery_verify_async';
                $dv_body  = http_build_query(['tok' => $vtok]);
                $dv_req   = "POST {$dv_path} HTTP/1.1\r\nHost: {$dv_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                          . "Content-Length: " . strlen($dv_body) . "\r\nConnection: close\r\nUser-Agent: WPCDeliveryVerify/1.0\r\n\r\n" . $dv_body;
                $dv_fp = wps_ic_ajax::wpc_loopback_open_socket($dv_host, $dv_port, $dv_https, 0.2);
                if ($dv_fp) { @stream_set_timeout($dv_fp, 0, 100000); @fwrite($dv_fp, $dv_req); @fclose($dv_fp); }
            }
        }


        if (($nextgenChanged || $overrideChanged) && class_exists('WPC_Delivery_Resolver')) {
            WPC_Delivery_Resolver::resolve_verbose(true);
        }

        if ($modernFlippedOn) {
            
            
            $skip_loopback = function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth();

            if (!$skip_loopback) {


                $pw_parts = wp_parse_url(admin_url('admin-ajax.php'));
                if (!empty($pw_parts['host']) && function_exists('wpc_loopback_token_mint')) {
                    $pw_https = (!empty($pw_parts['scheme']) && $pw_parts['scheme'] === 'https');
                    $pw_port  = !empty($pw_parts['port']) ? (int) $pw_parts['port'] : ($pw_https ? 443 : 80);
                    $pw_host  = (string) $pw_parts['host'];
                    $pw_path  = (!empty($pw_parts['path']) ? $pw_parts['path'] : '/') . '?action=wpc_modern_delivery_prewarm&t=' . rawurlencode(wpc_loopback_token_mint('prewarm'));
                    $pw_req   = "POST {$pw_path} HTTP/1.1\r\nHost: {$pw_host}\r\nContent-Length: 0\r\nConnection: close\r\nUser-Agent: WPCPrewarm/1.0\r\n\r\n";
                    $pw_fp = wps_ic_ajax::wpc_loopback_open_socket($pw_host, $pw_port, $pw_https, 0.2);
                    if ($pw_fp) { @stream_set_timeout($pw_fp, 0, 100000); @fwrite($pw_fp, $pw_req); @fclose($pw_fp); }
                }
            }
        }

        if ($cf !== null) {
            update_option(WPS_IC_CF, $cf);
        }

        wp_send_json_success(['saved' => count($changes)]);
    }

    


    public function wps_ic_purge_after_save()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error('Forbidden.');
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey      = sanitize_text_field($_POST['apikey'] ?? '');
            $changedKeys = !empty($_POST['changed_keys']) ? array_map('sanitize_text_field', (array) $_POST['changed_keys']) : [];
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->purgeRemoteCaches($apikey, $changedKeys);
            }
            wp_send_json_success();
        }

        
        $htmlPurgeKeys = [
            
            'replace-fonts', 'font-display', 'icon-font-display',
            'preload-crit-fonts', 'fontawesome-lazy',
            
            'css', 'js', 'fonts', 'lazy', 'nativeLazy',
            'serve,jpg', 'serve,png', 'serve,gif', 'serve,svg',
            
            'generate_adaptive', 'generate_webp', 'picture_webp', 'picture_avif',
            'retina', 'background-sizing', 'optimize-lcp', 'modern_image_delivery',
            'qualityLevel', 'local_qualityLevel', 'local_optimization',
            'maxWidth', 'lazySkipCount',


            'avif-natural-source', 'fetchpriority-high', 'single-url-image-format',
            
            'critical,css', 'delay,js', 'delay-js-v2',
            'minify,html', 'minify,css', 'minify,js',
            
            'cf,cdn', 'cf,assets', 'eu-routing',
            
            
            
            
            'imagesPreset', 'cdnAll', 'cache,advanced',
        ];
        
        $critPurgeKeys = ['replace-fonts', 'font-display', 'icon-font-display', 'preload-crit-fonts', 'css', 'fonts', 'minify,css', 'critical,css',
            'imagesPreset', 'cdnAll'];

        $changedKeys = !empty($_POST['changed_keys']) ? array_map('sanitize_text_field', (array) $_POST['changed_keys']) : [];

        $needsHtmlPurge = !empty($changedKeys) && !empty(array_intersect($changedKeys, $htmlPurgeKeys));
        $needsCritPurge = !empty($changedKeys) && !empty(array_intersect($changedKeys, $critPurgeKeys));

        if ($needsHtmlPurge) {
            
            delete_transient('wps_ic_css_cache');
            delete_option('wps_ic_modified_css_cache');
            delete_option('wps_ic_css_combined_cache');
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll(false, true, false, false, true);
            $cache::purgeCombinedFiles();

            
            self::purgeBreeze();
            self::purge_cache_files();
            if (function_exists('rocket_clean_domain')) rocket_clean_domain();
            if (defined('LSCWP_V')) do_action('litespeed_purge_all');
            if (defined('WPHB_VERSION')) do_action('wphb_clear_page_cache');
        }

        if ($needsCritPurge) {
            
            global $wpdb;
            $options_table = $wpdb->options;
            $wpdb->query($wpdb->prepare("DELETE FROM $options_table WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_wpc_critical_key_') . '%', $wpdb->esc_like('_transient_timeout_wpc_critical_key_') . '%'));
            if (!isset($cache)) $cache = new wps_ic_cache_integrations();
            
            if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead()) {
                $cache::purgeCriticalFiles();
            }
        }


        if (!empty($changedKeys) && in_array('font-display', $changedKeys, true) && class_exists('wps_ic_fonts')) {
            try {
                $wpc_fonts_rebake = new wps_ic_fonts();
                if (method_exists($wpc_fonts_rebake, 'rebakeFontDisplay')) {
                    $wpc_fonts_rebake->rebakeFontDisplay();
                }
            } catch (\Throwable $e) {
                
            }
        }


        $wpc_cf_live   = get_option(WPS_IC_CF);
        $wpc_cf_active = !empty($wpc_cf_live) && !empty($wpc_cf_live['token']) && !empty($wpc_cf_live['zone']);


        $wpc_wpe_active = class_exists('WpeCommon');
        if ($wpc_cf_active || $wpc_wpe_active) {


            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            }

            
            
            if ($wpc_wpe_active) {
                try {
                    if (method_exists('WpeCommon', 'purge_memcached'))     { WpeCommon::purge_memcached(); }
                    if (method_exists('WpeCommon', 'purge_varnish_cache')) { WpeCommon::purge_varnish_cache(); }
                    if (method_exists('WpeCommon', 'clear_cdn_cache'))     { WpeCommon::clear_cdn_cache(); }
                } catch (\Throwable $e) {
                    
                }
            }


            if ($wpc_cf_active) {
                try {
                    if (apply_filters('wpc_cf_purge_html_full', false)) {

                        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR') && file_exists(WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php')) {
                            @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                        }
                        if (class_exists('WPC_CloudflareAPI')) {
                            $wpc_cfapi_save = new WPC_CloudflareAPI($wpc_cf_live['token']);
                            if ($wpc_cfapi_save) {
                                $wpc_cfapi_save->purgeCache($wpc_cf_live['zone']);
                            }
                        }
                    } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {


                        wps_ic_cache::cfPurgeAllHtml();
                    } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {

                        wps_ic_cache::purgeEdgeHtmlUrls([home_url('/'), home_url()]);
                    }
                } catch (\Throwable $e) {
                    
                }
            }
        }

        wp_send_json_success();
    }

    



    public function wps_ic_generate_critical_css()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        
        
        
        
        
        $GLOBALS['wpc_gen_force496'] = 1;
        $criticalCSS = new wps_criticalCss($_SERVER['HTTP_REFERER']);
        $criticalCSS->generateCriticalAjax();
        unset($GLOBALS['wpc_gen_force496']);

        
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
            try { wps_ic_cache::cfPurgeAllHtml(true, true); } catch (\Throwable $e) {}
        }

        wp_send_json_success();
    }

    



    













    
    
    
    private function wpc_page_target_725($raw)
    {
        if (!class_exists('wps_ic_url_key') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'traits/url_key.php';
        }
        $wpc_clean725 = class_exists('wps_ic_url_key') ? wps_ic_url_key::sanitizeSameHostUrl((string) $raw) : '';
        if ($wpc_clean725 === '' || !wps_ic_url_key::isPageUrl($wpc_clean725)) {
            wp_send_json_error(['message' => 'That URL is not a page on this site.'], 400);
        }
        $wpc_key725 = ltrim((string) (new wps_ic_url_key())->setup($wpc_clean725), '/');
        if ($wpc_key725 === '') {
            wp_send_json_error(['message' => 'Could not resolve this page.'], 400);
        }
        return [$wpc_clean725, $wpc_key725];
    }

    
    
    
    
    
    private function wpc_rebuild_page_725($raw)
    {
        $wpc_set725 = get_option(WPS_IC_SETTINGS);
        if (empty($wpc_set725['critical']['css']) || $wpc_set725['critical']['css'] != '1') {
            wp_send_json_error(['message' => 'Critical CSS is not enabled.'], 400);
        }
        list($wpc_clean725, $wpc_key725) = $this->wpc_page_target_725($raw);
        $wpc_dir725 = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_key725 . '/' : '';
        $wpc_dsp725 = $wpc_dir725 !== '' ? (int) @filemtime($wpc_dir725 . 'dispatch_ts.txt') : 0;
        $wpc_lnd725 = $wpc_dir725 !== '' ? (int) @filemtime($wpc_dir725 . 'land_ts.txt') : 0;
        if ($wpc_dsp725 > 0 && $wpc_dsp725 > $wpc_lnd725 && (time() - $wpc_dsp725) < 180 && empty($_POST['anyway'])) {
            wp_send_json_success(['situation' => 'already-regenerating', 'scope' => 'page',
                'message' => 'A fresh version of this page is already being generated — it applies automatically when it lands.']);
        }
        if (empty($_POST['anyway']) && function_exists('wpc_land_cooldown_active') && wpc_land_cooldown_active($wpc_key725)) {
            wp_send_json_success(['situation' => 'cooldown', 'scope' => 'page',
                'message' => 'This page was rebuilt moments ago. Give the new version a minute to settle — if it still looks wrong after that, rebuild again.']);
        }
        $wpc_did725 = [];
        if ($wpc_dir725 !== '' && function_exists('wpc_crit_meta_write')
            && (@is_file($wpc_dir725 . 'critical_desktop.css') || @is_file($wpc_dir725 . 'critical_mobile.css'))) {
            wpc_crit_meta_write($wpc_dir725 . 'stale.txt', (string) time());
            $wpc_did725[] = 'stale-marked:page';
            
            
            
            if (function_exists('wpc_crit_bypass_page_start') && wpc_crit_bypass_page_start($wpc_key725)) {
                $wpc_did725[] = 'bypass:page';
            }
            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
                try {
                    wps_ic_cache_integrations::purgeAll($wpc_key725, false, false, false);
                    $wpc_did725[] = 'purged:page-html';
                } catch (\Throwable $e) {
                }
            }
        }
        $GLOBALS['wpc_gen_force496'] = 1;
        if (!class_exists('wps_criticalCss')) {
            @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        }
        if (class_exists('wps_criticalCss')) {
            try {
                $wpc_cc725 = new wps_criticalCss($wpc_clean725);
                $wpc_cc725->generateCriticalAjax(true);
                $wpc_did725[] = 'regen-dispatched(force+sync)';
            } catch (\Throwable $e) {
                $wpc_did725[] = 'regen-failed';
            }
        }
        unset($GLOBALS['wpc_gen_force496']);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('rebuild-page', $wpc_key725, $wpc_clean725, ['did' => implode(' ', $wpc_did725)]);
        }
        wp_send_json_success(['situation' => 'rebuilding-page', 'scope' => 'page', 'did' => $wpc_did725,
            'message' => 'Rebuilding this page. It shows plain theme styling right away and switches to the fresh optimized version automatically (usually under a minute).']);
    }

    public function wps_ic_rebuild_optimizations()
    {
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }
        if (!current_user_can('manage_wpc_purge') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        if (!empty($_POST['page_url'])) {
            return $this->wpc_rebuild_page_725((string) wp_unslash($_POST['page_url']));
        }

        
        
        $wpc_arts516 = 0;
        $wpc_pend516 = 0;
        if (defined('WPS_IC_CRITICAL')) {
            $wpc_root516 = rtrim(WPS_IC_CRITICAL, '/');
            $wpc_it516 = @scandir($wpc_root516);
            if (is_array($wpc_it516)) {
                foreach ($wpc_it516 as $wpc_e516) {
                    if ($wpc_e516 === '.' || $wpc_e516 === '..' || $wpc_e516 === 'used-css') {
                        continue;
                    }
                    $wpc_d516 = $wpc_root516 . '/' . $wpc_e516;
                    if (!@is_dir($wpc_d516)) {
                        continue;
                    }
                    if (@is_file($wpc_d516 . '/critical_desktop.css') || @is_file($wpc_d516 . '/critical_mobile.css')) {
                        $wpc_arts516++;
                    }
                    $wpc_dsp516 = (int) @filemtime($wpc_d516 . '/dispatch_ts.txt');
                    $wpc_lnd516 = (int) @filemtime($wpc_d516 . '/land_ts.txt');
                    if ($wpc_dsp516 > 0 && $wpc_dsp516 > $wpc_lnd516 && (time() - $wpc_dsp516) < 180) {
                        $wpc_pend516++;
                    }
                }
            }
        }
        $wpc_html516 = false;
        if (defined('WPS_IC_CACHE') && @is_dir(WPS_IC_CACHE)) {
            $wpc_c516 = @scandir(WPS_IC_CACHE);
            $wpc_html516 = is_array($wpc_c516) && count($wpc_c516) > 2;
        }
        $wpc_shed516 = (function_exists('wpc_under_pressure') && wpc_under_pressure())
            || (function_exists('wpc_safe_mode') && wpc_safe_mode());

        
        
        if ($wpc_pend516 > 0 && empty($_POST['anyway'])) {
            wp_send_json_success([
                'situation' => 'already-regenerating',
                'pending'   => $wpc_pend516,
                'did'       => [],
                'message'   => 'A fresh generation is already in flight (' . (int) $wpc_pend516
                    . ' pending). Pages keep serving while it lands — nothing else to do.',
            ]);
        }

        $wpc_did516 = [];

        if ($wpc_arts516 > 0) {
            if (function_exists('wpc_crit_soft_purge_all')) {
                $wpc_did516[] = 'stale-marked:' . (int) wpc_crit_soft_purge_all();
            }
            
            
            if (function_exists('wpc_delay_manifest_reset')) {
                $wpc_dm519 = wpc_delay_manifest_reset('rebuild');
                $wpc_did516[] = 'delay-reset:' . (int) $wpc_dm519['files'] . 'f/' . (int) $wpc_dm519['options'] . 'o';
            }
            
            
            if (function_exists('wpc_crit_bypass_start')) {
                wpc_crit_bypass_start();
                update_option('wpc_crit_soft_purge_at', time(), false);
                $wpc_did516[] = 'bypass-window';
            }
            if (!$wpc_html516) {
                $wpc_did516[] = 'html-purge-skipped(nothing-cached)';
            } elseif ($wpc_shed516) {
                $wpc_did516[] = 'html-purge-deferred(under-pressure)';
            } elseif (function_exists('wpc_r2_purge_html_layers')) {
                wpc_r2_purge_html_layers();
                $wpc_did516[] = 'purged(html+varnish+edge-html)';
            }
        } else {
            
            
            $wpc_did516[] = 'no-artifacts(purge-skipped)';
        }

        
        
        
        $GLOBALS['wpc_gen_force496'] = 1;
        if (!class_exists('wps_criticalCss')) {
            @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        }
        if (class_exists('wps_criticalCss')) {
            try {
                
                
                
                $wpc_cc516 = new wps_criticalCss(home_url('/'));
                $wpc_cc516->generateCriticalAjax(true);
                $wpc_did516[] = 'regen-dispatched(force+sync)';
            } catch (\Throwable $e) {
                $wpc_did516[] = 'regen-failed';
            }
        }
        unset($GLOBALS['wpc_gen_force496']);

        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('rebuild-optimizations', '', '', [
                'arts' => $wpc_arts516, 'html' => $wpc_html516 ? 1 : 0,
                'shed' => $wpc_shed516 ? 1 : 0, 'did' => implode(' ', $wpc_did516),
            ]);
        }

        wp_send_json_success([
            'situation' => $wpc_arts516 > 0 ? ($wpc_shed516 ? 'rebuilt-deferred' : 'rebuilt') : 'first-run',
            'artifacts' => $wpc_arts516,
            'did'       => $wpc_did516,
            'message'   => $wpc_arts516 > 0
                ? ($wpc_shed516
                    ? 'Fresh generation dispatched. The cache drop was deferred because the server is under load — it lands with the new version.'
                    : 'Rebuilding. Pages render with full theme CSS (correct, slightly slower) until the fresh version lands, then speed up automatically. Images and the CDN were not touched.')
                : 'No existing optimizations to replace — a first generation has been dispatched. Nothing was purged.',
        ]);
    }

    public function wps_ic_preload_page()
    {
        
        
        
        
        
        if (!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) {
            wp_send_json_error('Forbidden.');
        }
        if (!function_exists('check_ajax_referer')
            || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error('Forbidden.');
        }

        
        $wpc_ref508  = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string) $_SERVER['HTTP_REFERER']) : '';
        $wpc_host508 = wp_parse_url(home_url(), PHP_URL_HOST);
        $wpc_rh508   = $wpc_ref508 !== '' ? wp_parse_url($wpc_ref508, PHP_URL_HOST) : '';
        if ($wpc_ref508 === '' || !$wpc_rh508 || strcasecmp((string) $wpc_rh508, (string) $wpc_host508) !== 0) {
            wp_send_json_error('Preload needs a same-site page reference.');
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        $url = WPS_IC_PRELOADER_API_URL;

        self::$Requests->POST($url, ['single_url' => $wpc_ref508, 'apikey' => $options['api_key']]);

        
        
        wp_send_json_success();
    }

    



    public function wps_ic_purge_html()
    {
        if ((!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        
        
        
        
        if (!empty($_POST['page_url'])) {
            list($wpc_pclean725, $wpc_pkey725) = $this->wpc_page_target_725((string) wp_unslash($_POST['page_url']));
            $wpc_thr725 = 'wpc_pgref_' . md5($wpc_pkey725);
            if (get_transient($wpc_thr725) && empty($_POST['anyway'])) {
                wp_send_json_success(['scope' => 'page', 'situation' => 'just-refreshed',
                    'message' => 'This page was refreshed less than a minute ago — the newest copy is already being prepared.']);
            }
            set_transient($wpc_thr725, 1, MINUTE_IN_SECONDS);
            $wpc_layers725 = [];
            if (class_exists('wps_ic_cache_integrations')) {
                $wpc_layers725 = wps_ic_cache_integrations::purgeUrlHtml($wpc_pkey725, $wpc_pclean725, ['context' => 'adminbar-page']);
            }
            if (function_exists('wpc_pl_sched')) {
                wpc_pl_sched(time() + 5, 'wpc_url_warm', [$wpc_pclean725, 1]);
            } elseif (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 5, 'wpc_url_warm', [$wpc_pclean725, 1]);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-page', $wpc_pkey725, $wpc_pclean725, ['layers' => array_keys(array_filter((array) $wpc_layers725))]);
            }
            wp_send_json_success(['scope' => 'page', 'situation' => 'refreshed',
                'layers' => array_keys(array_filter((array) $wpc_layers725)),
                'message' => 'This page\'s cache was refreshed — a fresh copy is being prepared right now.']);
        }

        
        
        
        
        try {
        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');


        $wpc_warm_list114 = [];
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'wpcHtmlUrlList')) {
            $wpc_warm_list114 = array_slice((array) wps_ic_cache::wpcHtmlUrlList(12), 0, 6);
        }
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll(false, true, false, false, true);

        
        $cache::purgeCombinedFiles();


        $wpc_purged = [];
        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached'))     { WpeCommon::purge_memcached();     $wpc_purged[] = 'wpe-memcached'; }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) { WpeCommon::purge_varnish_cache(); $wpc_purged[] = 'wpe-varnish'; }
            if (method_exists('WpeCommon', 'clear_cdn_cache'))     { WpeCommon::clear_cdn_cache();     $wpc_purged[] = 'wpe-cdn'; }
        }
        $cfSettings = function_exists('get_option') ? get_option(WPS_IC_CF) : false;
        if (!empty($cfSettings['token']) && !empty($cfSettings['zone'])) {


            if (time() - (int) get_option('wpc_cf_rules_normalized_at', 0) > 6 * HOUR_IN_SECONDS) {
                update_option('wpc_cf_rules_normalized_at', time(), false);
                try {
                    if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
                        @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
                    }
                    if (class_exists('WPC_CloudflareAPI')) {
                        $wpc_norm_sdk98 = new WPC_CloudflareAPI($cfSettings['token']);
                        if (method_exists($wpc_norm_sdk98, 'patchHtmlRulesRespectOrigin')) {
                            $wpc_norm_res98 = $wpc_norm_sdk98->patchHtmlRulesRespectOrigin($cfSettings['zone']);


                            $wpc_norm_rules105 = is_array($wpc_norm_res98) ? array_diff_key($wpc_norm_res98, ['tiered_on' => 1]) : [];
                            $wpc_purged[] = 'cf-rule-normalize:' . (!empty($wpc_norm_rules105) ? 'patched-' . count($wpc_norm_rules105) : 'in-target');
                        }
                    }
                } catch (\Throwable $wpc_norm_e98) {
                    $wpc_purged[] = 'cf-rule-normalize-skip';
                }
            }


            if (apply_filters('wpc_cf_purge_html_full', false)) {

                if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
                    @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
                }
                if (class_exists('WPC_CloudflareAPI')) {
                    $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
                    if ($cfapi) {
                        
                        
                        $cfRes = $cfapi->purgeCache($cfSettings['zone']);
                        $wpc_purged[] = is_wp_error($cfRes) ? 'cf-edge-FAIL' : 'cf-edge';
                    }
                }
            } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {


                $wpc_cf_res105 = method_exists('wps_ic_cache', 'cfUntaggedServesPossible')
                    ? wps_ic_cache::cfPurgeAllHtml(true, true)
                    : wps_ic_cache::cfPurgeAllHtml(true);
                $wpc_purged[]  = $wpc_cf_res105 ? ('cf-html-' . $wpc_cf_res105) : 'cf-html-FAIL';


                if ($wpc_cf_res105) {
                    wpc_diag_sleep(2, 'purge-html');
                    $wpc_vr106 = wp_remote_get(home_url('/'), [
                        'timeout' => 8, 'redirection' => 0,
                        'headers' => ['Accept' => 'text/html'],
                        'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
                    ]);
                    $wpc_cfst106 = is_wp_error($wpc_vr106) ? '' : strtoupper((string) wp_remote_retrieve_header($wpc_vr106, 'cf-cache-status'));
                    $wpc_age106  = is_wp_error($wpc_vr106) ? 0 : (int) wp_remote_retrieve_header($wpc_vr106, 'age');
                    if ($wpc_cfst106 === '') {
                        $wpc_purged[] = 'cf-verify:skipped(no-cf-header)';
                    } elseif ($wpc_cfst106 === 'HIT' && $wpc_age106 > 15) {
                        $wpc_purged[] = 'cf-verify-FAIL:still-cached(age=' . $wpc_age106 . 's)';
                    } else {
                        $wpc_purged[] = 'cf-verify:purged(' . strtolower($wpc_cfst106) . ($wpc_age106 ? ',age=' . $wpc_age106 . 's' : '') . ')';
                    }
                }
            } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {

                $wpc_cf_ok = wps_ic_cache::purgeEdgeHtmlUrls([home_url('/'), home_url()], true);
                $wpc_purged[] = ($wpc_cf_ok ? 'cf-edge-home:' : 'cf-edge-home-FAIL:') . '2urls';
            }
        }
        if (!empty($wpc_purged)) {
            $plog = get_option('wpc_purge_debug_log', []);
            $plog[] = date('Y-m-d H:i:s') . ' | Purge HTML (direct): ' . implode(', ', $wpc_purged);
            update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
        }


        
        if (function_exists('wpc_warm_url_queue')) {
            if (empty($wpc_warm_list114)) { $wpc_warm_list114 = [home_url('/')]; }
            foreach ($wpc_warm_list114 as $wpc_wu114) {
                wpc_warm_url_queue((string) $wpc_wu114, 'purge-html-button');
            }
            $wpc_purged[] = 'prewarm:' . count($wpc_warm_list114);
        }
        
        
        if (function_exists('wpc_purge_rewarm_hot_set')) {
            $wpc_purged[] = 'rewarm:' . (int) wpc_purge_rewarm_hot_set('purge-html-button');
        }

        
        
        delete_transient('wps_ic_purging_cdn');


        $wpc_plog97 = get_option('wpc_purge_debug_log', []);
        wp_send_json_success([
            'layers' => $wpc_purged,
            'log'    => array_slice(is_array($wpc_plog97) ? $wpc_plog97 : [], -6),
        ]);
        } catch (\Throwable $wpc_pe826) {
            $wpc_last826 = isset($wpc_purged) && is_array($wpc_purged) && !empty($wpc_purged)
                ? (string) end($wpc_purged) : 'pre-layers';
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-html-throw', '', '', [
                    'ex' => get_class($wpc_pe826), 'after' => $wpc_last826,
                    'msg' => substr((string) $wpc_pe826->getMessage(), 0, 140),
                ]);
            }
            wp_send_json_error('Purge threw ' . get_class($wpc_pe826) . ' after layer [' . $wpc_last826 . ']: '
                . substr((string) $wpc_pe826->getMessage(), 0, 160)
                . ' — layers before it completed; details in the purge journal.');
        }
    }


    public function wpc_purge_receipts()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('Forbidden.');
        }
        $plog = get_option('wpc_purge_debug_log', []);
        $cfl  = get_option('wpc_cache_first_log', []);
        wp_send_json_success([
            'version'    => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'purge_log'  => is_array($plog) ? array_slice($plog, -20) : [],
            'cf_events'  => is_array($cfl) ? array_slice($cfl, -12) : [],
        ]);
    }


    private function wpcCfControlTest($sdk, $zone, $probe)
    {
        
        
        
        
        
        
        $wpc_cands573 = [home_url('/')];
        if (!empty($_POST['probe_url'])) {
            array_unshift($wpc_cands573, esc_url_raw((string) $_POST['probe_url']));
        }
        $url = $wpc_cands573[0];
        $hit = ['cf' => ''];
        $wpc_why573 = '';
        foreach ($wpc_cands573 as $wpc_u573) {
            $url = $wpc_u573;
            for ($i = 0; $i < 5; $i++) {
                $hit = $probe($url);
                if (strtoupper((string) ($hit['cf'] ?? '')) === 'HIT') { break; }
                usleep(400000);
            }
            if (strtoupper((string) ($hit['cf'] ?? '')) === 'HIT') { $wpc_why573 = ''; break; }
            $wpc_cf573 = strtoupper((string) ($hit['cf'] ?? ''));
            $wpc_cc573 = strtolower((string) ($hit['cc'] ?? ''));
            $wpc_why573 = 'never reached HIT (cf=' . ($wpc_cf573 ?: 'none') . ')'
                . (strpos($wpc_cc573, 'no-store') !== false
                    ? ' — origin sent no-store, so this URL is deliberately uncacheable' : '');
        }
        
        
        
        
        
        
        
        
        $method = 'url';
        $purge  = method_exists($sdk, 'purgeFilesAsync') ? $sdk->purgeFilesAsync($zone, [$url]) : null;
        if (function_exists('wpc_cf_url_tag') && method_exists($sdk, 'purgeByTags')) {
            $method .= '+tag:' . wpc_cf_url_tag($url);
            $sdk->purgeByTags($zone, [wpc_cf_url_tag($url)]);
        }
        
        
        
        
        
        $wpc_op179 = ['hook' => false, 'sg' => ''];
        if (function_exists('wpc_foreign_purge610')) {
            
            
            $wpc_op179['hook'] = (bool) wpc_foreign_purge610(false, 'cf-doctor-control');
        }
        if (class_exists('wps_ic_siteground')) {
            try {
                $wpc_sg179 = new wps_ic_siteground();
                $wpc_op179['sg'] = $wpc_sg179->is_active()
                    ? (string) $wpc_sg179->purge_cache()
                    : 'inactive';
            } catch (\Throwable $wpc_e179) {
                $wpc_op179['sg'] = 'err:' . substr($wpc_e179->getMessage(), 0, 60);
            }
        }
        
        
        
        
        
        
        
        $wpc_s0575 = (string) ($hit['stamp'] ?? '');
        $post = $hit;
        $wpc_waits575 = 0;
        for ($wpc_i575 = 0; $wpc_i575 < 15; $wpc_i575++) {
            wpc_diag_sleep(3, 'cf-controltest');
            $wpc_waits575++;
            $post = $probe($url);
            $wpc_c575 = strtoupper((string) ($post['cf'] ?? ''));
            $wpc_t575 = (string) ($post['stamp'] ?? '');
            
            if ($wpc_c575 !== 'HIT' || ($wpc_t575 !== '' && $wpc_t575 !== $wpc_s0575)) {
                break;
            }
        }
        
        
        
        
        
        
        $was     = strtoupper((string) ($hit['cf'] ?? '')) === 'HIT';
        $still   = strtoupper((string) ($post['cf'] ?? '')) === 'HIT';
        $s0      = (string) ($hit['stamp'] ?? '');
        $s1      = (string) ($post['stamp'] ?? '');
        
        
        
        
        
        
        $s2 = ''; $wpc_tries572 = 0;
        if ($was && !$still) {
            for ($wpc_i572 = 0; $wpc_i572 < 6; $wpc_i572++) {
                $wpc_tries572++;
                $wpc_r572 = $probe($url);
                $s2 = (string) ($wpc_r572['stamp'] ?? '');
                if ($s2 !== '' && $s2 !== $s0) { break; }
                if ($wpc_i572 < 5) { wpc_diag_sleep(3, 'cf-controltest-poll'); }
            }
        }
        $moved   = ($s0 !== '' && (($s1 !== '' && $s1 !== $s0) || ($s2 !== '' && $s2 !== $s0)));
        if (!$was) {
            $verdict = 'INCONCLUSIVE — ' . ($wpc_why573 !== '' ? $wpc_why573 : 'the probe URL never cached')
                . '. Nothing was proven and nothing was changed.';
        } elseif ($still) {
            $verdict = 'NOT EVICTED after ' . ($wpc_waits575 * 3) . 's of polling';
        } elseif (!$moved) {
            $verdict = 'STALE-REFILL — status cleared but the bytes did not change (upper tier still holds it)';
        } else {
            $verdict = 'EVICTED';
        }
        if ($was && !$still && $moved) {
            $wpc_tier570 = '';
            try {
                if (method_exists($sdk, 'getTieredCacheState')) {
                    $wpc_tier570 = !empty($sdk->getTieredCacheState($zone)) ? '+tiered' : '';
                }
            } catch (\Throwable $e) {
            }
            update_option('wpc_cf_purge_verified', ['t' => time(), 'method' => $method . $wpc_tier570], false);
        }
        return [
            'url'          => $url,
            'origin_purge' => $wpc_op179,
            'stamp_before' => $s0,
            'stamp_after'  => $s1,
            'stamp_refill' => $s2,
            'refill_probes' => $wpc_tries572,
            'purge_waits'  => $wpc_waits575,
            'waited_s'     => $wpc_waits575 * 3,
            'probe_note'   => $wpc_why573,
            'content_moved' => $moved ? 'yes' : 'NO',
            'purge_method' => $method,
            'purge_api'    => is_wp_error($purge) ? ('ERROR: ' . $purge->get_error_message()) : (!empty($purge['success']) ? 'ok' : 'fail'),
            'cached'       => $hit,
            'after_purge'  => $post,
            'verdict'      => $verdict,
        ];
    }

    public function wpc_cf_doctor()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings'))
            || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.'], 403);
        }
        $cf = get_option(WPS_IC_CF);
        if (empty($cf['token']) || empty($cf['zone'])) {
            wp_send_json_error(['msg' => 'Cloudflare is not connected on this site.']);
        }
        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
        }
        if (!class_exists('WPC_CloudflareAPI')) {
            wp_send_json_error(['msg' => 'Cloudflare SDK unavailable.']);
        }
        $sdk  = new WPC_CloudflareAPI($cf['token']);
        $mode = isset($_POST['mode']) ? sanitize_key((string) $_POST['mode']) : 'doctor';
        $out  = ['version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '', 'zone' => $cf['zone'], 'mode' => $mode];


        $probe = function ($url = '') {
            $r = wp_remote_get($url !== '' ? $url : home_url('/'), [
                'timeout'     => 10,
                'redirection' => 0,
                'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
                'headers'     => ['Accept' => 'text/html'],
            ]);
            if (is_wp_error($r)) { return ['error' => $r->get_error_message()]; }
            return [
                'http'     => (int) wp_remote_retrieve_response_code($r),
                'cf'       => (string) wp_remote_retrieve_header($r, 'cf-cache-status'),
                'age'      => (string) wp_remote_retrieve_header($r, 'age'),
                'cc'       => (string) wp_remote_retrieve_header($r, 'cache-control'),
                'colo'     => (string) preg_replace('/^.*-/', '', (string) wp_remote_retrieve_header($r, 'cf-ray')),
                'location' => (string) wp_remote_retrieve_header($r, 'location'),
                
                
                
                
                'stamp'    => (function ($b) {
                    if (preg_match('/<!-- wpc [0-9.]+ r:(\d+)/', $b, $m)) { return 'r' . $m[1]; }
                    return 'h' . substr(md5($b), 0, 10);
                })((string) wp_remote_retrieve_body($r)),
            ];
        };

        try {
            if ($mode === 'everything') {
                $res = $sdk->purgeCache($cf['zone']);
                $out['purge_everything'] = is_wp_error($res) ? ('ERROR: ' . $res->get_error_message()) : (!empty($res['success']) ? 'ok' : 'fail');
                wpc_diag_sleep(3, 'cf-doctor');
                $out['probe_after'] = $probe();
                $out['verdict'] = 'purge_everything sent — reload the site and check cf-cache-status.';
                wp_send_json_success($out);
            }
            if ($mode === 'tiered-off') {


                @set_time_limit(90);
                $out['tiered_disable'] = method_exists($sdk, 'disableTieredCache') ? $sdk->disableTieredCache($cf['zone']) : 'n/a';
                $out['control'] = $this->wpcCfControlTest($sdk, $cf['zone'], $probe);
                $out['verdict'] = 'Control with tiered OFF: ' . $out['control']['verdict']
                    . ' — note: v7.10.105 policy keeps tiered ON fleet-wide (normalize/link re-enable it); use Enable Tiered + Test to restore now.';
                wp_send_json_success($out);
            }
            if ($mode === 'tiered-on') {


                
                
                
                
                
                @set_time_limit(240);
                $GLOBALS['wpc_tiered_armed572'] = true;
                register_shutdown_function(function () use ($sdk, $cf) {
                    if (empty($GLOBALS['wpc_tiered_armed572'])) {
                        return;
                    }
                    try {
                        if (method_exists($sdk, 'disableTieredCache')) {
                            $sdk->disableTieredCache($cf['zone']);
                        }
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('cf-tiered-reverted', '', '', ['verdict' => 'request-died-before-verdict']);
                        }
                    } catch (\Throwable $e) {
                    }
                });
                $out['tiered_enable'] = method_exists($sdk, 'enableTieredCache') ? $sdk->enableTieredCache($cf['zone']) : 'n/a';
                $out['control'] = $this->wpcCfControlTest($sdk, $cf['zone'], $probe);
                
                
                
                $wpc_ok570 = ($out['control']['verdict'] === 'EVICTED');
                
                
                $GLOBALS['wpc_tiered_armed572'] = false;
                if (!$wpc_ok570 && method_exists($sdk, 'disableTieredCache')) {
                    $out['tiered_reverted'] = $sdk->disableTieredCache($cf['zone']);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('cf-tiered-reverted', '', '', ['verdict' => (string) $out['control']['verdict']]);
                    }
                }
                $out['verdict'] = $wpc_ok570
                    ? 'EARNED — eviction proven with tiers ACTIVE and the body stamp moved ('
                        . $out['control']['stamp_before'] . ' -> '
                        . ($out['control']['stamp_refill'] !== '' ? $out['control']['stamp_refill'] : $out['control']['stamp_after'])
                        . '). Tiered caching left ON: origin shielding plus a purge that reaches the upper tier.'
                    : 'NOT EARNED (' . $out['control']['verdict'] . ') — tiered caching has been turned back OFF automatically. '
                        . 'content_moved=' . $out['control']['content_moved']
                        . '. A STALE-REFILL here means the purge cleared the edge but not the upper tier, which is the un-purgeable state; do not force it on.';
                wp_send_json_success($out);
            }
            if ($mode === 'normalize') {
                update_option('wpc_cf_rules_normalized_at', time(), false);
                $res = method_exists($sdk, 'patchHtmlRulesRespectOrigin') ? $sdk->patchHtmlRulesRespectOrigin($cf['zone'], null, true) : null;
                $out['normalize_raw'] = is_wp_error($res) ? ('ERROR: ' . $res->get_error_message()) : $res;
                $wpc_norm_rules = is_array($res) ? array_diff_key($res, ['tiered_on' => 1]) : [];
                $out['verdict'] = is_wp_error($res)
                    ? 'Rule normalize FAILED — see normalize_raw (a missing Zone > Cache Rules > Edit scope shows here verbatim).'
                    : (empty($wpc_norm_rules) ? 'Rules already in target state (no writes needed).' : 'Rules patched — old-keyed HTML entries are now orphaned; test Purge HTML.');
                wp_send_json_success($out);
            }


            @set_time_limit(90);
            
            
            
            
            
            if ($mode === 'report') {
                $ks = method_exists($sdk, 'htmlRuleKeyState') ? $sdk->htmlRuleKeyState($cf['zone']) : ['devkey' => null];
                
                
                
                
                $cap = method_exists($sdk, 'probeDeviceKeySupport')
                    ? $sdk->probeDeviceKeySupport($cf['zone']) : ['supported' => null, 'detail' => 'sdk too old'];
                
                
                
                
                
                
                if (function_exists('update_option')) {
                    update_option('wpc_cf_devkey_verified', [
                        't'      => time(),
                        'devkey' => (is_array($ks) && !empty($ks['devkey'])) ? 1 : 0,
                        'src'    => 'readback',
                        'found'  => (int) (is_array($ks) ? ($ks['found'] ?? 0) : 0),
                    ], false);
                    if (isset($cap['supported']) && $cap['supported'] !== null) {
                        update_option('wpc_cf_devkey_capable', [
                            't'       => time(),
                            'capable' => !empty($cap['supported']) ? 1 : 0,
                            'detail'  => (string) ($cap['detail'] ?? ''),
                        ], false);
                    }
                }
                $set = function_exists('get_option') && defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
                $eff = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on')
                    ? wps_rewriteLogic::wpc_combined_crit_on() : null;
                $dk  = get_option('wpc_cf_devkey_verified');
                $pv  = get_option('wpc_cf_purge_verified');
                $key = (class_exists('wps_ic_url_key') && function_exists('home_url'))
                    ? (new wps_ic_url_key())->setup(home_url('/')) : '';
                $dir = ($key !== '' && defined('WPS_IC_CRITICAL')) ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/' : '';
                $sz  = function ($f) use ($dir) { return ($dir !== '' && @is_file($dir . $f)) ? (int) @filesize($dir . $f) : 0; };
                $mob = $sz('critical_mobile.css'); $des = $sz('critical_desktop.css'); $cmb = $sz('critical_combined.css');
                
                $peek = function ($ua) {
                    $r = wp_remote_get(home_url('/'), ['timeout' => 12, 'redirection' => 0, 'user-agent' => $ua,
                        'headers' => ['Accept' => 'text/html']]);
                    if (is_wp_error($r)) { return ['error' => $r->get_error_message()]; }
                    $b = (string) wp_remote_retrieve_body($r);
                    $n = 0;
                    if (preg_match('/<style[^>]*id="wpc-critical-css"[^>]*>(.*?)<\/style>/s', $b, $m)) { $n = strlen($m[1]); }
                    return [
                        'http'     => (int) wp_remote_retrieve_response_code($r),
                        'cf'       => (string) wp_remote_retrieve_header($r, 'cf-cache-status'),
                        'age'      => (string) wp_remote_retrieve_header($r, 'age'),
                        'cc'       => (string) wp_remote_retrieve_header($r, 'cache-control'),
                        'combined' => (strpos($b, 'wpc-critical-css-combined') !== false) ? 1 : 0,
                        'crit_b'   => $n,
                        'ver'      => preg_match('/<!-- wpc ([0-9.]+)/', $b, $mv) ? $mv[1] : '',
                    ];
                };
                $uaM = 'Mozilla/5.0 (Linux; Android 11; moto g power) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Mobile Safari/537.36';
                $uaD = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36';
                $pm = $peek($uaM); $pd = $peek($uaD);
                
                $devkeyOk = !empty($cap['supported']);
                $wants = (is_array($set) && !empty($set['minimal-mobile-css']) && $set['minimal-mobile-css'] == '1') ? 'split' : 'combined';
                if (is_array($set) && isset($set['combined-crit']) && $set['combined-crit'] !== '') {
                    $wants = ($set['combined-crit'] === '1') ? 'combined (forced)' : 'split (forced)';
                }
                $blocked = (strpos($wants, 'split') === 0) && !$devkeyOk;
                $out['report'] = [
                    'plugin'          => $out['version'],
                    'devkey_supported' => $devkeyOk ? 'YES' : 'NO',
                    'devkey_probe'    => $cap,
                    'rule_state'      => $ks,
                    'rules_missing'   => array_values(array_diff(['homepage', 'fullhtml'],
                        array_keys(array_filter((array) ($ks['rules'] ?? []), function ($r) { return !empty($r['present']); })))),
                    'devkey_stamp'    => $dk ?: 'never-read',
                    'purge_crown'     => $pv ?: 'none',
                    'setting_wants'   => $wants,
                    'effective_mode'  => ($eff === null) ? 'unknown' : ($eff ? 'combined' : 'split'),
                    'safety_floor'    => $blocked ? 'ENGAGED — split refused, edge cannot key per device' : 'not engaged',
                    'artifacts'       => ['mobile' => $mob, 'desktop' => $des, 'combined' => $cmb,
                        'mobile_saving_vs_combined' => ($cmb && $mob) ? ($cmb - $mob) : 0],
                    'live_mobile'     => $pm,
                    'live_desktop'    => $pd,
                    'cross_device_ok' => (isset($pm['combined'], $pd['combined']) && $pm['combined'] === $pd['combined'])
                        ? 'consistent' : 'MISMATCH — one device is getting the other shape',
                ];
                $L = [];
                $L[] = 'WPC ' . $out['version'] . '  zone ' . $cf['zone'];
                $L[] = 'device-key SUPPORTED (probe)      : ' . ($devkeyOk ? 'YES' : 'NO')
                     . '   [' . (string) ($cap['detail'] ?? '') . ']';
                $L[] = 'device-key deployed on live rules : ' . (!empty($ks['devkey']) ? 'yes' : 'no')
                     . '   (expected NO while combined — combined strips cache_key by design)';
                $wpc_miss571 = array_values(array_diff(['homepage', 'fullhtml'],
                    array_keys(array_filter((array) ($ks['rules'] ?? []), function ($r) { return !empty($r['present']); }))));
                $L[] = 'html rules found                  : ' . (int) ($ks['found'] ?? 0) . '/2'
                     . (empty($wpc_miss571) ? '' : '   MISSING: ' . implode(', ', $wpc_miss571));
                $L[] = 'setting wants                     : ' . $wants;
                $L[] = 'EFFECTIVE mode                    : ' . (($eff === null) ? 'unknown' : ($eff ? 'COMBINED' : 'SPLIT'));
                $L[] = 'safety floor                      : ' . ($blocked ? 'ENGAGED (split refused)' : 'not engaged');
                $L[] = 'purge crown                       : ' . (is_array($pv) ? ('verified, method=' . ($pv['m'] ?? $pv['method'] ?? '?')) : 'none');
                $L[] = 'artifacts  mobile/desktop/combined: ' . $mob . ' / ' . $des . ' / ' . $cmb . ' b';
                $L[] = 'potential mobile saving           : ' . (($cmb && $mob) ? ($cmb - $mob) : 0) . ' b raw';
                $L[] = 'live mobile   : v' . ($pm['ver'] ?? '?') . '  crit=' . ($pm['crit_b'] ?? 0) . 'b  combined=' . ($pm['combined'] ?? '?') . '  cf=' . ($pm['cf'] ?? '?') . '  age=' . ($pm['age'] ?? '?');
                $L[] = 'live desktop  : v' . ($pd['ver'] ?? '?') . '  crit=' . ($pd['crit_b'] ?? 0) . 'b  combined=' . ($pd['combined'] ?? '?') . '  cf=' . ($pd['cf'] ?? '?') . '  age=' . ($pd['age'] ?? '?');
                $L[] = 'cross-device  : ' . $out['report']['cross_device_ok'];
                $out['text'] = implode("\n", $L);
                $out['verdict'] = $devkeyOk
                    ? 'Zone CAN key per device (probe confirmed) — Minimal Mobile CSS is safe to test here.'
                    : 'Zone CANNOT key per device: ' . (string) ($cap['detail'] ?? '') . '. The safety floor will refuse split, '
                        . 'so enabling Minimal Mobile CSS changes nothing and cannot serve one device the other device\'s page.';
                wp_send_json_success($out);
            }
            $out['zone_state'] = method_exists($sdk, 'getZoneCacheState') ? $sdk->getZoneCacheState($cf['zone']) : 'n/a';


            $out['probe_before'] = $probe();
            $wpc_h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $wpc_alt = (strpos($wpc_h, 'www.') === 0) ? substr($wpc_h, 4) : ('www.' . $wpc_h);
            $wpc_home_urls = [home_url('/'), home_url(),
                str_replace('://' . $wpc_h, '://' . $wpc_alt, home_url('/')),
                str_replace('://' . $wpc_h, '://' . $wpc_alt, home_url())];
            $out['purged_urls'] = $wpc_home_urls;
            $res  = $sdk->purgeFilesAsync($cf['zone'], $wpc_home_urls);
            $out['plain_purge'] = is_wp_error($res) ? ('ERROR: ' . $res->get_error_message())
                : (!empty($res['success']) ? 'ok' : ('fail: ' . (isset($res['errors'][0]['message']) ? $res['errors'][0]['message'] : 'unknown')));
            wpc_diag_sleep(3, 'cf-doctor');
            $out['probe_after'] = $probe();
            $wpc_b = isset($out['probe_before']['cf']) ? strtoupper((string) $out['probe_before']['cf']) : '';
            $wpc_a = isset($out['probe_after']['cf']) ? strtoupper((string) $out['probe_after']['cf']) : '';
            $out['url_purge_arm'] = ($wpc_b !== 'HIT')
                ? 'INCONCLUSIVE — homepage not cached (cf=' . $wpc_b . ') at probe time'
                : (($wpc_a === 'HIT' && (int) ($out['probe_after']['age'] ?? 0) >= (int) ($out['probe_before']['age'] ?? 0))
                    ? 'SURVIVED — expected: non-Enterprise zones no longer honor per-URL file purges; the tag control below is the operative mechanism'
                    : 'EVICTED — this zone still honors per-URL file purges (bonus, not relied upon)');


            $out['control'] = $this->wpcCfControlTest($sdk, $cf['zone'], $probe);
            if ($out['control']['verdict'] === 'EVICTED') {
                $out['verdict'] = 'EVICTED — Cache-Tag purge works on this zone (crown stored: wpc_cf_purge_verified).';
            } elseif ($out['control']['verdict'] === 'NOT EVICTED') {
                $out['verdict'] = 'NOT EVICTED — tag purge failed: check tag_emission below (origin must send Cache-Tag) and rules_snapshot; a fill served by a header-stripping page cache carries no tag.';
            } elseif (strpos((string) $out['control']['verdict'], 'STALE-REFILL') === 0) {
                $out['verdict'] = 'STALE-REFILL — Cloudflare purged but the ORIGIN re-served old bytes: a hosting-layer cache (see control.origin_purge for which purge strategies fired). On SiteGround, verify the socket purge armed; otherwise purge the hosting panel cache and re-run.';
            } else {
                $out['verdict'] = 'INCONCLUSIVE — control URL did not cache (rule/bypass matched it, or the self-fetch bypassed Cloudflare — no cf-cache-status). Test from a browser and re-run.';
            }
            
            $out['tag_emission'] = [
                'hook_registered'     => function_exists('wpc_cf_emit_html_tags') && (bool) has_action('send_headers', 'wpc_cf_emit_html_tags'),
                'homepage_tag'        => function_exists('wpc_cf_url_tag') ? wpc_cf_url_tag(home_url('/')) : 'n/a (warm.php not loaded)',
                'untagged_serve_risk' => (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfUntaggedServesPossible')) ? wps_ic_cache::cfUntaggedServesPossible() : null,
            ];
            $out['purge_verified'] = get_option('wpc_cf_purge_verified');
            
            
            
            
            $out['fetch_skip'] = (function () use ($sdk, $cf) {
                try {
                    if (!method_exists($sdk, 'wpc_find_bypass_rule740')) {
                        return 'n/a (sdk)';
                    }
                    $wpc_loc189 = $sdk->wpc_find_bypass_rule740($cf['zone']);
                    if (empty($wpc_loc189['rule'])) {
                        return $wpc_loc189['legacy']
                            ? 'LEGACY-API rule only — press Refresh Auto Mode to re-provision the phased skip'
                            : 'MISSING — service fetches face challenges; press Refresh Auto Mode to re-provision';
                    }
                    $wpc_ap189 = isset($wpc_loc189['shape']['action_parameters']) && is_array($wpc_loc189['shape']['action_parameters'])
                        ? $wpc_loc189['shape']['action_parameters'] : [];
                    return [
                        'rule'    => 'present',
                        'phases'  => !empty($wpc_ap189['phases']) ? $wpc_ap189['phases'] : 'MISSING (products-only skip: managed challenges still fire)',
                        'ruleset' => !empty($wpc_ap189['ruleset']) ? $wpc_ap189['ruleset'] : 'MISSING (customer custom rules still run first)',
                        'header'  => strpos((string) $wpc_loc189['expression'], 'x-origin-auth') !== false ? 'x-origin-auth' : 'UNEXPECTED-EXPRESSION',
                    ];
                } catch (\Throwable $e) {
                    return 'ERROR: ' . $e->getMessage();
                }
            })();
            
            $wpc_rules = method_exists($sdk, 'listCacheRules') ? $sdk->listCacheRules($cf['zone']) : null;
            if (is_wp_error($wpc_rules)) {
                $out['rules_snapshot'] = 'ERROR: ' . $wpc_rules->get_error_message();
            } else {
                $out['rules_snapshot'] = array_map(function ($r) {
                    return [
                        'ref'        => isset($r['ref']) ? $r['ref'] : '(foreign)',
                        'desc'       => isset($r['description']) ? substr((string) $r['description'], 0, 60) : '',
                        'enabled'    => !empty($r['enabled']),
                        'expression' => isset($r['expression']) ? substr((string) $r['expression'], 0, 120) : '',
                        'edge_ttl'   => isset($r['action_parameters']['edge_ttl']) ? $r['action_parameters']['edge_ttl'] : null,
                        'cache_key'  => isset($r['action_parameters']['cache_key']) ? $r['action_parameters']['cache_key'] : '(default)',
                    ];
                }, is_array($wpc_rules) ? $wpc_rules : []);
            }
            $wpc_pr = method_exists($sdk, 'listPageRules') ? $sdk->listPageRules($cf['zone']) : null;
            $out['page_rules'] = is_wp_error($wpc_pr) ? ('ERROR: ' . $wpc_pr->get_error_message())
                : array_map(function ($p) {
                    return ['status' => $p['status'] ?? '', 'targets' => isset($p['targets'][0]['constraint']['value']) ? $p['targets'][0]['constraint']['value'] : '', 'actions' => array_map(function ($a) { return ($a['id'] ?? '') . (isset($a['value']) && is_scalar($a['value']) ? '=' . $a['value'] : ''); }, isset($p['actions']) && is_array($p['actions']) ? $p['actions'] : [])];
                }, (is_array($wpc_pr) && isset($wpc_pr['result']) && is_array($wpc_pr['result'])) ? $wpc_pr['result'] : (is_array($wpc_pr) ? $wpc_pr : []));
            
            $wpc_privs = $sdk->checkPrivileges($cf['zone']);
            $out['privileges'] = is_wp_error($wpc_privs) ? ('ERROR: ' . $wpc_privs->get_error_message()) : $wpc_privs;
            wp_send_json_success($out);
        } catch (\Throwable $e) {
            $out['fatal'] = substr($e->getMessage(), 0, 200);
            wp_send_json_error($out);
        }
    }


    public static function wpc_fleet_frontend_purge($reason = '', $skip_cf = false)
    {
        static $done = false;
        if ($done) return;
        $done = true;


        
        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
        }
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        }

        $purged = [];
        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached'))     { WpeCommon::purge_memcached();     $purged[] = 'wpe-memcached'; }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) { WpeCommon::purge_varnish_cache(); $purged[] = 'wpe-varnish'; }
            if (method_exists('WpeCommon', 'clear_cdn_cache'))     { WpeCommon::clear_cdn_cache();     $purged[] = 'wpe-cdn'; }
        }


        $cf = function_exists('get_option') ? get_option(WPS_IC_CF) : false;
        if (!$skip_cf && !empty($cf['token']) && !empty($cf['zone'])) {
            if (apply_filters('wpc_cf_purge_html_full', false)) {

                if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
                    @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
                }
                if (class_exists('WPC_CloudflareAPI')) {
                    $cfapi = new WPC_CloudflareAPI($cf['token']);
                    if ($cfapi) {
                        $r = $cfapi->purgeCache($cf['zone']);
                        $purged[] = (function_exists('is_wp_error') && is_wp_error($r)) ? 'cf-edge-FAIL' : 'cf-edge';
                    }
                }
            } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {


                $wpc_fleet_res105 = wps_ic_cache::cfPurgeAllHtml(true);
                $purged[] = $wpc_fleet_res105 ? ('cf-html-' . $wpc_fleet_res105) : 'cf-html-FAIL';
            } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {

                $wpc_fleet_ok = wps_ic_cache::purgeEdgeHtmlUrls([home_url('/'), home_url()], true);
                $purged[] = ($wpc_fleet_ok ? 'cf-edge-home:' : 'cf-edge-home-FAIL:') . '2urls';
            }
        }

        $plog = get_option('wpc_purge_debug_log', []);
        if (!is_array($plog)) $plog = [];
        $plog[] = date('Y-m-d H:i:s') . ' | live-cdn flip (' . $reason . '): ' . implode(', ', $purged);
        update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
    }

    public function wpc_purgeCF($return = false)
    {
        $cfSettings = get_option(WPS_IC_CF);

        
        
        if (!empty($cfSettings) && !empty($cfSettings['zone']) && !empty($cfSettings['token'])) {
            $zone = $cfSettings['zone'];
            $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
            if ($cfapi) {
                $cfapi->purgeCache($zone);
                
                
            }
        }

        if ($return) {
            return true;
        }

        wp_send_json_success();
    }

    



    public function wps_ic_purge_critical_css()
    {
        
        
        
        $wpc_pdbg343 = [
            'caps' => (current_user_can('manage_wpc_settings') ? 's' : '') . (current_user_can('manage_wpc_purge') ? 'p' : ''),
            'hard' => !empty($_POST['hard_purge']) ? 1 : 0,
            'nonce' => isset($_POST['wps_ic_nonce']) ? (wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action') ? 'ok' : 'bad') : 'absent',
        ];
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('purge-click', 'crit', '', $wpc_pdbg343);
        }
        try {
            return $this->wps_ic_purge_critical_css_run($wpc_pdbg343);
        } catch (\Throwable $e) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-click-fatal', 'crit', '', ['msg' => substr($e->getMessage(), 0, 160), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            }
            wp_send_json_error('Purge error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    private function wps_ic_purge_critical_css_run($wpc_pdbg343 = [])
    {
        if ((!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        
        
        
        $wpc_hard73 = !empty($_POST['hard_purge']) || apply_filters('wpc_crit_purge_hard', false);
        
        
        
        $wpc_nuke391 = $wpc_hard73 && apply_filters('wpc_purge_nuclear', false);

        
        
        $wpc_detached111 = false;
        if (function_exists('fastcgi_finish_request') && !apply_filters('wpc_purge_crit_sync', false)) {
            @ignore_user_abort(true);
            @set_time_limit(120);
            status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['success' => true, 'data' => ['mode' => $wpc_hard73 ? 'hard-deleted:detached' : 'stale-marked:detached']]);
            @fastcgi_finish_request();
            $wpc_detached111 = true;
        }

        
        global $wpdb;

        
        $options_table = $wpdb->options;

        
        
        
        do {
            $wpc_del391 = $wpdb->query($wpdb->prepare("DELETE FROM $options_table WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s LIMIT 500", $wpdb->esc_like('_transient_wpc_critical_key_') . '%', $wpdb->esc_like('_transient_timeout_wpc_critical_key_') . '%', $wpdb->esc_like('wps_critical_css_') . '%'));
            if ($wpc_del391 >= 500) {
                usleep(50000);
            }
        } while ($wpc_del391 >= 500);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        $cache = new wps_ic_cache_integrations();

        $wpc_purge_mode111 = 'hard-deleted';
        if (!$wpc_hard73 && function_exists('wpc_crit_soft_purge_all')) {
            $wpc_soft_n111 = wpc_crit_soft_purge_all();
            update_option('wpc_crit_soft_purge_at', time(), false);
            
            
            $wpc_purge_mode111 = ($wpc_soft_n111 > 0) ? 'stale-marked:' . (int) $wpc_soft_n111 : 'nothing-to-mark';
        } elseif ($wpc_hard73 && !$wpc_nuke391 && function_exists('wpc_crit_delete_files196')) {
            
            
            
            
            
            
            
            $wpc_del196 = wpc_crit_delete_files196();
            set_transient('wpc_ptick_hold196', 1, 600);
            update_option('wpc_crit_soft_purge_at', time(), false);
            
            
            
            if (function_exists('wpc_r2_purge_html_layers')) {
                wpc_r2_purge_html_layers();
            }
            if (function_exists('wpc_delay_manifest_reset')) {
                wpc_delay_manifest_reset('crit-hard-purge');
            }
            $wpc_purge_mode111 = 'hard-deleted:files:' . (int) $wpc_del196;
        } else {
            $cache::purgeCriticalFiles();
            delete_option('wpc_crit_soft_purge_at');
        }


        if (function_exists('wpc_inv2_reset')) {
            wpc_inv2_reset('manual-purge-button');
        }


        if ($wpc_nuke391 || $wpc_purge_mode111 === 'hard-deleted') {
            $cache::purgeAll(false, true, false, false, true);


            if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_flush')) {
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('purge-hard'); } else { @wp_cache_flush(); }
            }
        }
        
        if ($wpc_hard73 && function_exists('wpc_purge_rewarm_hot_set')) {
            wpc_purge_rewarm_hot_set($wpc_nuke391 ? 'purge-critical-hard' : 'purge-critical-bypass');
        }


        if (function_exists('set_transient')) {
            set_transient('wpc_crit_regen_pending', 1, 10 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60));
        }


        
        
        if (function_exists('wpc_crit_purge_redispatch')) {
            wpc_crit_purge_redispatch();
        }

        if (function_exists('wpc_warm_url_queue')) {
            wpc_warm_url_queue(home_url('/'), 'purge-critical-button');
        }

        
        
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_cf_selftest')) {
            wp_schedule_single_event(time() + 90, 'wpc_cf_selftest');
        }

        
        delete_transient('wps_ic_purging_cdn');

        
        
        
        
        
        if (apply_filters('wpc_crit_purge_regenerates', true)) {
            $GLOBALS['wpc_gen_force496'] = 1;
            if (!class_exists('wps_criticalCss')) {
                @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (class_exists('wps_criticalCss')) {
                try {
                    $wpc_cc509 = new wps_criticalCss();
                    $wpc_cc509->generateCriticalCSS('home', true);
                } catch (\Throwable $e) {
                }
            }
            unset($GLOBALS['wpc_gen_force496']);
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                try { wps_ic_cache::cfPurgeAllHtml(true, true); } catch (\Throwable $e) {}
            }
        }

        if ($wpc_detached111) {
            exit; 
        }
        
        wp_send_json_success(['mode' => $wpc_purge_mode111]);
    }

    



    public function wps_ic_purge_cdn()
    {
        if ((!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        
        $cache = new wps_ic_cache_integrations();
        

        if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead()) {
            $cache::purgeCriticalFiles();
        }
        $cache::purgeAll();
        
        if (function_exists('wpc_purge_rewarm_hot_set')) {
            wpc_purge_rewarm_hot_set('purge-cdn-button');
        }

        
        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;
        $options['js_hash'] = $JSHash;

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'wps_ic_purge_cdn');
        }

        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        set_transient('wps_ic_purging_cdn', 'true', 30);

        


        $cdn_report = ['attempted' => false];
        if (function_exists('wpc_customer_purge')) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(60);
            }
            $purge = wpc_customer_purge('', 'all', [], 'manual_purge', true); 
            $cdn_report = [
                'attempted'   => true,
                'ok'          => !empty($purge['ok']),
                'duration_ms' => isset($purge['duration_ms']) ? (int) $purge['duration_ms'] : 0,
                'layers'      => isset($purge['layers']) ? $purge['layers'] : [],
            ];
        }


        if (method_exists(__CLASS__, 'wpc_fleet_frontend_purge')) {


            self::wpc_fleet_frontend_purge('manual purge button', function_exists('wpc_customer_purge'));
        }


        self::$Requests->GET(
            WPS_IC_KEYSURL,
            ['action' => 'cdn_purge', 'apikey' => $options['api_key']],
            ['timeout' => 1, 'blocking' => false, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]
        );

        delete_transient('wps_ic_purging_cdn');

        
        

        wp_send_json_success([
            'local' => true,
            'cdn'   => $cdn_report,
        ]);
    }

    



    public function wps_ic_purge_local_variants()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }
        $imageID = (int) ($_POST['attachment_id'] ?? 0);
        if (!$imageID || get_post_type($imageID) !== 'attachment') {
            wp_send_json_error('invalid_image');
        }
        $mime = (string) get_post_mime_type($imageID);
        $attachedFile = get_attached_file($imageID);
        $removed = 0;
        if ($attachedFile) {
            $dir = dirname($attachedFile);
            $baseName = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
            $glob_base = preg_replace('/([*?\[\]{}])/', '[$1]', $dir . '/' . $baseName);
            
            
            if ($mime !== 'image/avif') {
                foreach ((array) @glob($glob_base . '*.avif') as $f) { if ($f && @unlink($f)) { $removed++; } }
            }
            if ($mime !== 'image/webp') {
                foreach ((array) @glob($glob_base . '*.webp') as $f) { if ($f && @unlink($f)) { $removed++; } }
            }
        }
        if (function_exists('wpc_purge_variants_for_image')) {
            wpc_purge_variants_for_image($imageID);
        }
        wp_send_json_success(['removed' => $removed]);
    }

    public function wps_ic_exclude_live()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;

        $output = '';
        $action = sanitize_text_field($_POST['do_action']);
        $attachment_id = sanitize_text_field($_POST['attachment_id']);
        $filedata = get_attached_file($attachment_id);
        $basename = sanitize_title(basename($filedata));
        $exclude_list = get_option('wps_ic_exclude_list');

        if (!$exclude_list) {
            $exclude_list = [];
        }

        $exclude = get_post_meta($attachment_id, 'wps_ic_exclude_live', true);

        $filedata = get_attached_file($attachment_id);

        
        $filesize = filesize($filedata);
        $wpScaledFilesize = wps_ic_format_bytes($filesize, null, null, false);

        
        $originalFilepath = wp_get_original_image_path($attachment_id);
        $originalFilesize = filesize($originalFilepath);
        $filesize = wps_ic_format_bytes($originalFilesize, null, null, false);

        if ($action == 'exclude') {
            $exclude_list[$attachment_id] = $basename;
            update_post_meta($attachment_id, 'wps_ic_exclude_live', 'true');
        } else {
            unset($exclude_list[$attachment_id]);
            delete_post_meta($attachment_id, 'wps_ic_exclude_live');
        }

        update_option('wps_ic_exclude_list', $exclude_list);

        
        $output = $wps_ic->media_library->compress_details($attachment_id);
        wp_send_json_success(['html' => $output]);
    }

    



    public function wps_ic_simple_exclude_image()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;
        $wps_ic = new wps_ic_compress();
        $wps_ic->simple_exclude($_POST, 'html');
    }

    


    public function wps_ic_api_mu_connect()
    {
        global $wps_ic;

        
        $sites = get_sites();

        
        $apikey = sanitize_text_field($_POST['apikey']);
        $affiliate_code = get_option('wps_ic_affiliate_code');

        if ($sites && is_multisite()) {
            $error = false;

            foreach ($sites as $key => $site) {

                $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'connect', 'apikey' => $apikey, 'site' => urlencode($site->domain . $site->path), 'affiliate_code' => $affiliate_code]);

                if (!empty($call)) {

                    if ($call->success && $call->data->api_key != '' && $call->data->response_key != '') {
                        $options = new wps_ic_options();
                        $options->set_option('api_key', $call->data->api_key);
                        $options->set_option('response_key', $call->data->response_key);
                        $options->set_option('orp', $call->data->orp);

                        $settings = get_option(WPS_IC_SETTINGS);

                        $sizes = get_intermediate_image_sizes();
                        foreach ($sizes as $key => $value) {
                            $settings['thumbnails'][$value] = 1;
                        }

                        update_option(WPS_IC_SETTINGS, $settings);
                    }
                } else {
                    $error = true;
                }
            }

            if ($error) {
                wp_send_json_error();
            } else {
                wp_send_json_success();
            }
        }

        wp_send_json_error('0');
    }


    


    public function wps_lite_connect()
    {
        $connect = new wps_ic_connect();
        $call = $connect->connectLite();
    }


    public function wpsRemoveFont()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $fontId = sanitize_text_field($_POST['fontId']);

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->sendSiteRemoveFont($apikey, $fontId);
            }
            wp_send_json_success();
        }

        $font = new wps_ic_fonts();
        $font->removeFont($fontId);

        wp_send_json_success();
    }


    public function wpsScanFonts()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $url = sanitize_url($_POST['scanUrl']);
        if (empty($url)) {
            wp_send_json_error('No URL provided.');
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->sendSiteScanFonts($apikey, $url);
            }
            wp_send_json_success(['found' => false]);
        }

        $fonts = new wps_ic_fonts();
        $response = $fonts->callAPI($url);
        $found = $fonts->scanForFonts($response);

        $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);

        if ($hasGoogleFonts) {
            $fonts->readGoogleStylesheet($found);
        }

        wp_send_json_success(['found' => $hasGoogleFonts]);
    }


    public function wpsPurgeFontCache()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->sendSitePurgeFontCache($apikey);
            }
            wp_send_json_success();
        }

        delete_option(WPS_IC_FONTS_MAP);

        $fonts = new wps_ic_fonts();
        $response = $fonts->callAPI(site_url());
        $found = $fonts->scanForFonts($response);

        $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);
        if ($hasGoogleFonts) {
            $fonts->readGoogleStylesheet($found);
        }

        wp_send_json_success(['found' => $hasGoogleFonts]);
    }

    public function wpsChangeGui()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $view = sanitize_text_field($_POST['view']);
        update_option(WPS_IC_GUI, $view);
        update_option('wpsShowAdvanced', 'true');
        wp_send_json_success();
    }


    


    public function wps_ic_live_connect()
    {
        $connect = new wps_ic_connect();
        $call = $connect->connect();
    }

    


    public function wps_ic_deauthorize_api()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;

        
        $site = site_url();
        $options = new wps_ic_options();
        $apikey = $options->get_option('api_key');

        
        self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'disconnect', 'apikey' => $apikey, 'site' => urlencode($site)]);

        $options->set_option('api_key', '');
        $options->set_option('response_key', '');
        $options->set_option('orp', '');
    }

    


    public function wps_ic_media_library_heartbeat()
    {
        global $wps_ic, $wpdb;
        $html = [];

        
        
        $hb_request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);


        $active_raw_fast = isset($_POST['active']) ? $_POST['active'] : [];
        $has_active = is_array($active_raw_fast) && !empty($active_raw_fast);
        
        
        
        if ($has_active && method_exists(__CLASS__, 'wpc_v2_stale_compress_watchdog')) {
            foreach ((array) $active_raw_fast as $wpc_aw314) {
                $wpc_aid314 = is_array($wpc_aw314) ? (int) ($wpc_aw314['id'] ?? 0) : (int) $wpc_aw314;
                if ($wpc_aid314 > 0) { self::wpc_v2_stale_compress_watchdog($wpc_aid314); }
            }
        }
        if (!$has_active) {
            
            $like_fast = $wpdb->esc_like('_transient_wps_ic_heartbeat_') . '%';
            $any_hb = $wpdb->get_var($wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
                $like_fast
            ));
            if (!$any_hb) {
                if (function_exists('wpc_v2_telemetry_record')) {
                    $hb_ms_fast = (int) round((microtime(true) - $hb_request_arrival_t) * 1000);
                    wpc_v2_telemetry_record('heartbeat', $hb_ms_fast, [
                        'active_count' => 0,
                        'image_count'  => 0,
                        'fast_path'    => true,
                    ]);
                }
                wp_send_json_error();
            }
        }

        $like = $wpdb->esc_like('_transient_wps_ic_heartbeat_') . '%';

        $heartbeatData = $wpdb->get_results($wpdb->prepare("SELECT *
         FROM {$wpdb->options}
         WHERE option_name LIKE %s", $like));

        if ($heartbeatData) {
            foreach ($heartbeatData as $transient) {
                $data = maybe_unserialize($transient->option_value);
                $imageID = isset($data['imageID']) ? (int) $data['imageID'] : 0;
                if ($imageID <= 0) continue;
                $status = isset($data['status']) ? (string) $data['status'] : '';

                if ($status == 'compressed') {
                    $html_str = $wps_ic->media_library->compress_details($imageID);
                    $is_compressed_render = (strpos($html_str, 'wpc-ml-card--compressed') !== false);

                    if (!$is_compressed_render) {
                        delete_transient('wps_ic_heartbeat_' . $imageID);
                        continue;
                    }

                    $event = $data['event'] ?? null;
                    $html[$imageID] = [
                        'html'            => $html_str,
                        'status'          => 'compressed',
                        'event'           => $event,
                        'bg_variant_fmt'  => $data['bg_variant_fmt']  ?? null,
                        'bg_variant_size' => $data['bg_variant_size'] ?? null,
                    ];
                    delete_transient('wps_ic_compress_' . $imageID);
                    delete_transient('wps_ic_heartbeat_' . $imageID);
                } elseif ($status == 'restored') {
                    $html[$imageID] = ['html' => $wps_ic->media_library->compress_details($imageID), 'status' => 'restored'];
                    delete_transient('wps_ic_compress_' . $imageID);
                    delete_transient('wps_ic_heartbeat_' . $imageID);
                }
            }
        }


        $active_raw = isset($_POST['active']) ? $_POST['active'] : [];
        if (!is_array($active_raw)) $active_raw = [];
        $active = [];
        foreach ($active_raw as $aid) {
            $aid = (int) $aid;
            if ($aid > 0 && !in_array($aid, $active, true)) $active[] = $aid;
            if (count($active) >= 50) break;
        }
        if (!empty($active)) {
            foreach ($active as $imageID) {
                $aug = $this->wpc_compute_heartbeat_payload($imageID);
                if (empty($aug)) continue;
                if (isset($html[$imageID])) {


                    foreach (['chip', 'savings_pct', 'recent', 'warming'] as $k) {
                        if (isset($aug[$k])) $html[$imageID][$k] = $aug[$k];
                    }
                } else {


                    $html[$imageID] = $aug;
                }
            }
        }


        if (function_exists('wpc_v2_telemetry_record')) {
            $hb_ms = (int) round((microtime(true) - $hb_request_arrival_t) * 1000);
            $hb_active_count = is_array($_POST['active'] ?? null) ? count($_POST['active']) : 0;
            wpc_v2_telemetry_record('heartbeat', $hb_ms, [
                'active_count' => $hb_active_count,
                'image_count'  => count($html),
            ]);
        }


        if (function_exists('wpc_v2_journal_dir') && function_exists('wpc_v2_journal_drain_run')) {
            $hb_journal_dir = wpc_v2_journal_dir();
            if ($hb_journal_dir !== '' && is_dir($hb_journal_dir)) {
                $hb_has_files = false;
                if ($hb_dh = @opendir($hb_journal_dir)) {
                    while (($hb_f = readdir($hb_dh)) !== false) {
                        if (substr($hb_f, -6) === '.jsonl') { $hb_has_files = true; break; }
                    }
                    closedir($hb_dh);
                }
                if ($hb_has_files) {


                    wpc_v2_journal_drain_run();
                }
            }
        }

        if (empty($html)) {
            wp_send_json_error();
        }

        wp_send_json_success(['html' => $html]);
    }


    private function wpc_compute_heartbeat_payload($imageID)
    {
        if ($imageID <= 0) return [];
        
        
        $post_type = get_post_type($imageID);
        if ($post_type !== 'attachment') return [];

        wp_cache_delete($imageID, 'post_meta');
        $variants  = get_post_meta($imageID, 'ic_local_variants', true);
        $ic        = get_post_meta($imageID, 'ic_compressing', true);
        $status    = (is_array($ic) && !empty($ic['status'])) ? (string) $ic['status'] : 'optimizing';
        $announced = get_transient('wpc_v2_announced_' . $imageID);
        if (!is_array($announced)) $announced = [];

        $count = 0; $jpeg = 0; $webp = 0; $avif = 0;
        $recent = [];

        if (is_array($variants)) {
            foreach ($variants as $vkey => $ventry) {
                if (!empty($ventry['bg_no_improvement'])) continue;
                if (empty($ventry['size'])) continue;
                $count++;
                if (strpos((string) $vkey, '-avif') !== false)      { $avif++; $fmt = 'AVIF'; }
                elseif (strpos((string) $vkey, '-webp') !== false)  { $webp++; $fmt = 'WEBP'; }
                else                                                 { $jpeg++; $fmt = 'JPEG'; }

                if (!empty($ventry['bg_upgraded_ms'])) {
                    $ts = (int) $ventry['bg_upgraded_ms'];
                } else {
                    $ts = isset($ventry['bg_upgraded']) ? ((int) $ventry['bg_upgraded']) * 1000 : 0;
                }
                if (isset($announced[$vkey]['announced_ms'])) {
                    $ts = (int) $announced[$vkey]['announced_ms'];
                }

                $size_label = (string) $vkey;
                foreach (['-avif', '-webp', '-jpeg', '-jpg', '-png'] as $suffix) {
                    if (substr($size_label, -strlen($suffix)) === $suffix) {
                        $size_label = substr($size_label, 0, -strlen($suffix));
                        break;
                    }
                }
                $recent[] = [
                    'fmt'       => $fmt,
                    'size'      => ucfirst(str_replace(['_', '-'], ' ', $size_label)),
                    'ts'        => $ts,
                    'savings'   => isset($ventry['savings']) ? (int) $ventry['savings'] : 0,
                    'is_parent' => ($size_label === 'original'),
                ];
            }
        }


        
        
        if ($status === 'compressed' && !empty($announced)) {
            foreach ($announced as $vkey => $aentry) {
                if (isset($variants[$vkey])) continue;
                if (!empty($aentry['noImprovement'])) continue;
                $ts = isset($aentry['announced_ms']) ? (int) $aentry['announced_ms'] : 0;
                if ($ts <= 0) continue;
                $fmt_lower = isset($aentry['format']) ? (string) $aentry['format'] : '';
                if ($fmt_lower === 'jpg') $fmt_lower = 'jpeg';
                $fmt_up = strtoupper($fmt_lower);
                $size_label = isset($aentry['sizeLabel']) ? (string) $aentry['sizeLabel'] : '';
                foreach (['-avif', '-webp', '-jpeg', '-jpg', '-png'] as $suffix) {
                    if (substr($size_label, -strlen($suffix)) === $suffix) {
                        $size_label = substr($size_label, 0, -strlen($suffix));
                        break;
                    }
                }
                if ($size_label === '' || $fmt_up === '') continue;
                $recent[] = [
                    'fmt'       => $fmt_up,
                    'size'      => ucfirst(str_replace(['_', '-'], ' ', $size_label)),
                    'ts'        => $ts,
                    'savings'   => isset($aentry['savings']) ? (int) $aentry['savings'] : 0,
                    'is_parent' => ($size_label === 'original'),
                ];
            }
        }

        
        usort($recent, function ($a, $b) { return $a['ts'] - $b['ts']; });

        $ic_savings  = get_post_meta($imageID, 'ic_savings', true);
        $savings_pct = is_numeric($ic_savings) ? (float) $ic_savings : 0.0;
        $warming     = (bool) get_transient('wpc_v2_warming_' . $imageID);

        $payload = [
            'status'      => $status,
            'chip'        => ['count' => $count, 'jpeg' => $jpeg, 'webp' => $webp, 'avif' => $avif],
            'savings_pct' => $savings_pct,
            'recent'      => $recent,
            'warming'     => $warming,
        ];


        if ($status === 'compressed') {
            global $wps_ic;
            if (isset($wps_ic) && isset($wps_ic->media_library)
                && method_exists($wps_ic->media_library, 'compress_details')) {
                $payload['html'] = $wps_ic->media_library->compress_details($imageID);
            }
        }

        return $payload;
    }

    public function wps_ic_bulkRestoreHeartbeat()
    {
        $isDone = get_transient('wps_ic_bulk_done');
        $parsedImages = get_option('wps_ic_parsed_images');
        $bulkStatus = get_option('wps_ic_BulkStatus');

        $bulkProcess = get_option('wps_ic_bulk_process');
        if ($bulkProcess && $bulkProcess['status'] != 'restoring') {
            wp_send_json_error(['msg' => 'bulk-process-failed']);
        }
        
        

        if ($bulkProcess && !$isDone && function_exists('wpc_bulk_heartbeat_touch')) {
            wpc_bulk_heartbeat_touch();
        }


        if (!$isDone) {
            $wd_queue = get_transient('wps_ic_restore_queue');
            $wd_tick  = (int) get_option('wpc_bulk_restore_last_tick', 0);
            $wd_fired = (int) get_option('wpc_bulk_restore_wd_fired', 0);
            if (!empty($wd_queue['queue']) && $wd_tick && (time() - $wd_tick) > 75 && (time() - $wd_fired) > 60) {
                update_option('wpc_bulk_restore_wd_fired', time(), false);
                error_log('[WPC Bulk Restore] WATCHDOG: no progress for ' . (time() - $wd_tick) . 's with '
                    . count($wd_queue['queue']) . ' queued — re-firing drain loopback');
                if (method_exists($this, 'wpc_bulk_v2_restore_fire_loopback')) {
                    self::wpc_bulk_v2_restore_fire_loopback();
                }
            }
        }


        if ($isDone) {
            $output = [];
            $bulkStatus = get_option('wps_ic_BulkStatus');
            $imagesInRestoreQueue = !empty($bulkStatus['foundImageCount']) ? $bulkStatus['foundImageCount'] : 0;
            $imagesRestored = !empty($bulkStatus['restoredImageCount']) ? $bulkStatus['restoredImageCount'] : 0;
            $progressBar = ($imagesInRestoreQueue > 0) ? round(($imagesRestored / $imagesInRestoreQueue) * 100) : 100;

            $output['status'] = 'done';
            $output['finished'] = $imagesRestored;
            $output['total'] = $imagesInRestoreQueue;
            $output['progress'] = $progressBar;


            $bytes_restored_done = 0;
            $finished_stamps = [];
            if (is_array($parsedImages)) {
                foreach ($parsedImages as $pid => $meta) {
                    if ($pid === 'total') continue;
                    if (isset($meta['bytes'])) {
                        $bytes_restored_done += (int) $meta['bytes'];
                    } else {
                        $p = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($pid) : get_attached_file($pid);
                        $b = ($p && file_exists($p)) ? (int) @filesize($p) : 0;
                        $bytes_restored_done += $b;
                    }
                    if (!empty($meta['restored_at'])) $finished_stamps[] = (int) $meta['restored_at'];
                }
            }
            $started_ms_done = (int) get_option('wpc_bulk_restore_started_ms', 0);


            $done_ms_done = (int) get_option('wpc_bulk_restore_done_ms', 0);
            if ($done_ms_done <= 0) {
                $done_ms_done = (int) round(microtime(true) * 1000);
                update_option('wpc_bulk_restore_done_ms', $done_ms_done, false);
            }
            $elapsed_done    = ($started_ms_done > 0 && $done_ms_done > $started_ms_done)
                ? (int) round(($done_ms_done - $started_ms_done) / 1000)
                : 0;
            $avg_done = null;
            if (count($finished_stamps) >= 2) {
                sort($finished_stamps);
                $tail_done = array_slice($finished_stamps, -5);
                $avg_done  = round((end($tail_done) - reset($tail_done)) / max(1, count($tail_done) - 1), 1);
            } elseif ($imagesRestored > 0 && $elapsed_done > 0) {
                $avg_done = round($elapsed_done / $imagesRestored, 1);
            }
            $output['bytes_restored']       = $bytes_restored_done;
            $output['bytes_restored_h']     = $bytes_restored_done > 0
                ? wps_ic_format_bytes($bytes_restored_done, null, null, false)
                : '';
            $output['elapsed_seconds']      = $elapsed_done;
            $output['avg_seconds_per_image']= $avg_done;


            if (function_exists('wpc_chain_next_pending_regen')) {
                wpc_chain_next_pending_regen(0);
            }

            wp_send_json_success($output);
        }

        
        $imagesInRestoreQueue = !empty($bulkStatus['foundImageCount']) ? $bulkStatus['foundImageCount'] : 0;
        $imagesRestored = !empty($bulkStatus['restoredImageCount']) ? $bulkStatus['restoredImageCount'] : 0;


        
        if (empty($parsedImages)) {
            wp_send_json_success(['status' => 'parsing', 'message' => 'We have found ' . $imagesInRestoreQueue . ($imagesInRestoreQueue == 1 ? ' image' : ' images') . ' to restore...']);
        }

        $progressBar = ($imagesInRestoreQueue > 0) ? round(($imagesRestored / $imagesInRestoreQueue) * 100) : 0;

        
        if ($progressBar == 0) {
            $progressBar = 3;
        }

        
        $onlyImages = $parsedImages;
        unset($onlyImages['total']);

        $lastID = null;
        if (!empty($onlyImages)) {
            $lastID = array_key_last($onlyImages);
        }

        
        if ($lastID === null) {
            wp_send_json_success(['status' => 'parsing', 'message' => 'Restoring images...', 'finished' => $imagesRestored, 'total' => $imagesInRestoreQueue, 'progress' => $progressBar]);
        }

        $lastProgress = isset($_POST['lastProgress']) ? $_POST['lastProgress'] : 0;

        
        
        $currentThumb = wp_get_attachment_url($lastID);
        $currentFile  = get_attached_file($lastID);
        $currentPath  = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($lastID) : $currentFile;
        $currentSize  = ($currentPath && file_exists($currentPath)) ? @filesize($currentPath) : 0;


        $bytes_restored = 0;
        foreach ($parsedImages as $pid => $meta) {
            if ($pid === 'total') continue;
            if (isset($meta['bytes'])) {
                $bytes_restored += (int) $meta['bytes'];
                continue;
            }
            
            $p = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($pid) : get_attached_file($pid);
            $b = ($p && file_exists($p)) ? (int) @filesize($p) : 0;
            $parsedImages[$pid]['bytes'] = $b;
            $bytes_restored += $b;
        }
        if (count($parsedImages) > 1) {
            
            update_option('wps_ic_parsed_images', $parsedImages, false);
        }

        
        
        $eta_seconds = null;
        $recent_stamps = [];
        foreach ($parsedImages as $pid => $meta) {
            if ($pid === 'total') continue;
            if (!empty($meta['restored_at'])) $recent_stamps[] = (int) $meta['restored_at'];
        }
        if (count($recent_stamps) >= 2) {
            sort($recent_stamps);
            $tail = array_slice($recent_stamps, -5);
            $avg  = (end($tail) - reset($tail)) / max(1, count($tail) - 1);
            $remaining = max(0, $imagesInRestoreQueue - $imagesRestored);
            if ($avg > 0 && $remaining > 0) $eta_seconds = (int) round($avg * $remaining);
        }

        $output = [];
        $output['status']        = 'working';
        $output['parsedImages']  = $parsedImages;
        
        
        $output['html']          = $this->bulkRestoreHtml($lastID, $lastProgress);
        $output['finished']      = $imagesRestored;
        $output['total']         = $imagesInRestoreQueue;
        $output['progress']      = $progressBar;
        $output['parsedImage']   = isset($parsedImages[$lastID]) ? $parsedImages[$lastID] : [];

        
        $output['driver']        = 'v2';
        $output['current']       = [
            'id'    => (int) $lastID,
            'name'  => $currentThumb ? basename($currentThumb) : ('Image #' . (int) $lastID),
            'size'  => $currentSize,
            'size_h'=> $currentSize > 0 ? wps_ic_format_bytes($currentSize, null, null, false) : '',
            'url'   => (string) $currentThumb,
        ];
        $output['eta_seconds']      = $eta_seconds;
        
        $started_ms = (int) get_option('wpc_bulk_restore_started_ms', 0);
        $output['elapsed_seconds'] = $started_ms > 0
            ? (int) round((microtime(true) * 1000 - $started_ms) / 1000)
            : 0;

        
        
        $output['bytes_restored']   = (int) $bytes_restored;
        $output['bytes_restored_h'] = $bytes_restored > 0
            ? wps_ic_format_bytes($bytes_restored, null, null, false)
            : '';


        $avg_seconds = null;
        if (count($recent_stamps) >= 2) {
            sort($recent_stamps);
            $tail = array_slice($recent_stamps, -5);
            $avg_seconds = round((end($tail) - reset($tail)) / max(1, count($tail) - 1), 1);
        } elseif ($imagesRestored > 0 && $output['elapsed_seconds'] > 0) {
            $avg_seconds = round($output['elapsed_seconds'] / $imagesRestored, 1);
        }
        $output['avg_seconds_per_image'] = $avg_seconds;


        $file_started = null;
        if (!empty($recent_stamps)) {
            $file_started = max($recent_stamps);
        }
        $output['file_elapsed_seconds'] = $file_started ? max(0, time() - (int) $file_started) : 0;


        $recent_titles = [];
        $recent_ids = array_keys($parsedImages);
        $recent_ids = array_filter($recent_ids, function($k) { return $k !== 'total'; });
        $recent_ids = array_slice(array_reverse($recent_ids), 0, 8);
        foreach ($recent_ids as $rid) {
            $rid = (int) $rid;
            $url   = wp_get_attachment_url($rid);
            $thumb = (string) wp_get_attachment_image_url($rid, 'thumbnail');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($rid, 'medium');
            if (!$thumb && $url) $thumb = $url;
            $bytes = isset($parsedImages[$rid]['bytes']) ? (int) $parsedImages[$rid]['bytes'] : 0;
            $stamp = isset($parsedImages[$rid]['restored_at']) ? (int) $parsedImages[$rid]['restored_at'] : 0;

            
            


            $mode = (string) get_post_meta($rid, 'wpc_backup_mode', true);
            $source = 'auto';
            if ($mode === 'cloud' || $mode === 'local-cloud') {
                $source = 'cloud';
            } elseif ($mode && in_array($mode, ['local', 'full', 'originals'], true)) {
                $source = 'local';
            } elseif (get_post_meta($rid, 'wpc_backup_path', true)) {
                $source = 'local';
            } elseif (get_post_meta($rid, 'ic_backup_images', true)) {
                $source = 'local';
            }

            $recent_titles[] = [
                'id'      => $rid,
                'name'    => $url ? basename($url) : ('Image #' . $rid),
                'thumb'   => $thumb,
                'bytes'   => $bytes,
                'bytes_h' => $bytes > 0 ? wps_ic_format_bytes($bytes, null, null, false) : '',
                'source'  => $source,
                'ms'      => $stamp * 1000,
            ];
        }
        $output['recent'] = $recent_titles;


        $queue_t = get_transient('wps_ic_restore_queue');
        $queue   = (is_array($queue_t) && !empty($queue_t['queue'])) ? $queue_t['queue'] : [];
        $done_ids = array_flip(array_filter($recent_ids, function ($k) { return $k !== 'total'; }));
        
        $done_ids[(int) $lastID] = true;
        foreach (array_keys($parsedImages) as $pid) {
            if ($pid === 'total') continue;
            $done_ids[(int) $pid] = true;
        }
        $up_next = [];
        foreach ($queue as $qid) {
            $qid = (int) $qid;
            if (isset($done_ids[$qid])) continue;
            $url = wp_get_attachment_url($qid);
            $up_next[] = [
                'id'   => $qid,
                'name' => $url ? basename($url) : ('Image #' . $qid),
            ];
            if (count($up_next) >= 4) break;
        }
        $output['up_next'] = $up_next;

        if ($imagesRestored >= $imagesInRestoreQueue) {
            delete_option('wps_ic_bulk_process');
            set_transient('wps_ic_bulk_done', true, 60);
            $output['status'] = 'done';
        }

        wp_send_json_success($output);
    }

    public function bulkRestoreHtml($imageID, $lastProgress = '')
    {
        


        
        

        $thumbUrl = wp_get_attachment_url($imageID);
        if (empty($thumbUrl)) $thumbUrl = '';
        $image_full_filename = $thumbUrl ? basename($thumbUrl) : ('Image #' . (int) $imageID);

        $originalPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : '';
        $original_filesize = ($originalPath && file_exists($originalPath)) ? @filesize($originalPath) : 0;
        $sizeDisplay = $original_filesize > 0 ? wps_ic_format_bytes($original_filesize, null, null, false) : 'Restoring…';

        $bulkStatus    = get_option('wps_ic_BulkStatus');
        $restoredCount = !empty($bulkStatus['restoredImageCount']) ? (int) $bulkStatus['restoredImageCount'] : 0;
        $totalCount    = !empty($bulkStatus['foundImageCount']) ? (int) $bulkStatus['foundImageCount'] : 0;
        $pct = ($totalCount > 0) ? min(100, (int) round(100 * $restoredCount / $totalCount)) : 0;

        $output  = '<div class="wps-ic-bulk-html-wrapper">';
        $output .= '<div class="wpc-restore-card">';
        $output .=   '<div class="wpc-restore-card-body">';

        
        $output .=     '<div class="wpc-restore-thumb">';
        if ($thumbUrl) {
            $output .= '<div class="wpc-restore-thumb-img" style="background-image:url(' . esc_url($thumbUrl) . ');"></div>';
        } else {
            $output .= '<div class="wpc-restore-thumb-img wpc-restore-thumb-empty">';
            $output .=   '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
            $output .= '</div>';
        }
        $output .=     '</div>';

        
        $output .=     '<div class="wpc-restore-meta">';
        $output .=       '<div class="wpc-chip-row">';
        $output .=         '<span class="wpc-chip wpc-chip-success">&#10003; ' . esc_html__('Restored', WPS_IC_TEXTDOMAIN) . '</span>';
        $output .=         '<span class="wpc-chip wpc-chip-ghost">' . esc_html($sizeDisplay) . '</span>';
        $output .=       '</div>';
        $output .=       '<h3 class="wpc-restore-filename" title="' . esc_attr($image_full_filename) . '">' . esc_html($image_full_filename) . '</h3>';
        $output .=     '</div>';

        
        $output .=     '<div class="wpc-restore-counter bulk-restore-status-top-right">';
        $output .=       '<h3 class="wpc-counter-main">' . $restoredCount . '<span class="wpc-counter-divider">/</span>' . $totalCount . '</h3>';
        $output .=       '<div class="wpc-counter-label">' . esc_html__('Images Restored', WPS_IC_TEXTDOMAIN) . '</div>';
        $output .=       '<span class="wpc-percent-badge">' . $pct . '% ' . esc_html__('complete', WPS_IC_TEXTDOMAIN) . '</span>';
        $output .=     '</div>';

        $output .=   '</div>'; 

        
        $output .=   '<div class="wpc-restore-progress-track bulk-status-progress-bar">';
        $output .=     '<div class="wpc-restore-progress-fill progress-bar-inner" style="width:' . $pct . '%;"></div>';
        $output .=   '</div>';

        $output .= '</div>'; 
        $output .= '</div>'; 

        return $output;
    }

    public function wps_ic_bulkCompressHeartbeat()
    {


        $bulkRunning = get_option('wps_ic_bulk_process');


        if (empty($bulkRunning)) {


            $final_sess = get_transient('wpc_bulk_session_ids') ?: [];
            $f_orig = 0; $f_now = 0; $f_variants = 0;
            $f_count = is_array($final_sess) ? count($final_sess) : 0;
            
            $f_pct_sum = 0.0; $f_pct_n = 0;
            if (is_array($final_sess)) {
                foreach ($final_sess as $fid) {
                    $fid = (int) $fid;
                    if ($fid <= 0) continue;
                    wp_cache_delete($fid, 'post_meta');
                    $fv = get_post_meta($fid, 'ic_local_variants', true);
                    if (!is_array($fv)) continue;
                    $img_o = 0; $img_n = 0;
                    foreach ($fv as $vv) {
                        if (!is_array($vv)) continue;
                        $f_variants++;
                        $img_o += (int) ($vv['originalSize'] ?? 0);
                        $img_n += (int) ($vv['size'] ?? 0);
                    }
                    $f_orig += $img_o;
                    $f_now  += $img_n;
                    if ($img_o > 0) {
                        $f_pct_sum += (100.0 * max(0, $img_o - $img_n) / $img_o);
                        $f_pct_n++;
                    }
                }
            }
            $f_saved   = max(0, $f_orig - $f_now);
            $f_pct     = $f_orig > 0 ? round(100 * $f_saved / $f_orig, 1) : 0;
            $f_pct_avg = $f_pct_n > 0 ? round($f_pct_sum / $f_pct_n, 1) : 0;
            wp_send_json_success([
                'driver'          => 'v2',
                'status'          => 'done',
                'total'           => $f_count,
                'processed'       => $f_count,
                'pending_drain'   => 0,
                'queue_empty'     => true,
                'cumulative_orig' => $f_orig,
                'cumulative_now'  => $f_now,
                'bytes_saved'     => $f_saved,
                'savings_pct'     => $f_pct,
                'savings_pct_avg' => $f_pct_avg,
                'variants_total'  => $f_variants,
                'active'          => [],
                'completed'       => [],
                'new_variants'    => [],
            ]);
        }

        $driver = !empty($bulkRunning['driver']) ? (string) $bulkRunning['driver'] : 'v1';


        if ($driver === 'v2' || $driver === 'sequential') {
            $this->bulkCompressHeartbeat_v2($bulkRunning);
            return;
        }

        $bulkStatus = get_option('wps_ic_BulkStatus');
        $parsedImages = get_option('wps_ic_parsed_images');

        
        $totalImagesFound = $bulkStatus['foundImageCount'];
        $totalThumbsFound = $bulkStatus['foundThumbCount'];
        $compressedImages = $bulkStatus['compressedImageCount'];

        
        $onlyImages = $parsedImages;
        unset($onlyImages['total']);

        
        if (empty($onlyImages)) {
            wp_send_json_success(['status' => 'parsing', 'message' => 'We have found ' . $totalImagesFound . ($totalImagesFound == 1 ? ' image' : ' images') . ' to optimize...']);
        }

        if (!empty($onlyImages)) {
            $lastID = array_key_last($onlyImages);
        }

        
        $stats = get_post_meta($lastID, 'ic_stats', true);
        $original_filesize = isset($stats['original']['original']['size']) ? $stats['original']['original']['size'] : 0;
        $compressed_filesize = isset($stats['original']['compressed']['size']) ? $stats['original']['compressed']['size'] : 0;

        
        $savedKB = wps_ic_format_bytes($original_filesize - $compressed_filesize) . ' Saved';
        if ($original_filesize <= $compressed_filesize) {
            $savedKB = 'No savings';
        }

        
        $status = '<ul class="wps-icon-list">';
        $status .= '<li><i class="wps-icon saved"></i>' . $savedKB . '</li>';
        $status .= '<li><i class="wps-icon quality"></i> ' . ucfirst(self::$settings['optimization']) . ' Mode</li>';
        if (self::$settings['generate_webp'] == '1') {
            $status .= '<li><i class="wps-icon webp"></i> WebP Generated</li>';
        }
        $status .= '</ul>';

        
        $progressBar = ($totalImagesFound > 0) ? round(($compressedImages / $totalImagesFound) * 100) : 0;

        
        $full = wp_get_original_image_url($lastID);
        $imageFileName = $full ? basename($full) : ('Image #' . $lastID);

        
        $originalSize = isset($parsedImages['total']['original']) ? $parsedImages['total']['original'] : 0;
        $compressedSize = isset($parsedImages['total']['compressed']) ? $parsedImages['total']['compressed'] : 0;
        $imagesAndThumbs = (!empty($bulkStatus['compressedImageCount']) ? $bulkStatus['compressedImageCount'] : 0) + (!empty($bulkStatus['compressedThumbs']) ? $bulkStatus['compressedThumbs'] : 0);

        
        $avgReduction = ($originalSize > 0 && $imagesAndThumbs > 0) ? (1 - ($compressedSize / $originalSize)) * 100 : 0;
        $avgReduction = number_format($avgReduction, 1);
        $avgReductionHTML = '<h3>' . $avgReduction . '%</h3><h5>Average Savings</h5>';

        
        $bulkSavings = wps_ic_format_bytes($originalSize - $compressedSize, null, null, false);
        $bulkSavingsHTML = '<h3>' . $bulkSavings . '</h3><h5>Total Savings</h5>';

        
        $CompressedImagesHTML = '<h3>' . $compressedImages . '/' . $totalImagesFound . '</h3><h5>Original Images</h5>';
        $CompressedThumbsHTML = '<h3>' . $imagesAndThumbs . '/' . $totalThumbsFound . '</h3><h5>Total Images</h5>';

        $output['html'] = $this->bulkCompressHtml($lastID);
        $output['status'] = $status;
        $output['progress'] = $progressBar;
        $output['parsedImage'] = $parsedImages[$lastID];
        $output['lastFileName'] = $imageFileName;
        $output['progressAvgReduction'] = $avgReductionHTML;
        $output['progressTotalSavings'] = $bulkSavingsHTML;
        $output['progressCompressedImages'] = $CompressedImagesHTML;
        $output['progressCompressedThumbs'] = $CompressedThumbsHTML;

        if ($compressedImages >= $totalImagesFound) {
            delete_option('wps_ic_bulk_process');
            set_transient('wps_ic_bulk_done', true, 60);
        }

        $isDone = get_transient('wps_ic_bulk_done');
        if ($isDone) {
            $output = [];
            $output['status'] = 'done';
            delete_option('wps_ic_bulk_process');
            delete_transient('wps_ic_stuck_check');
            delete_option('wps_ic_bulk_counter');
            wp_send_json_success($output);
        }

        wp_send_json_success($output);
    }


    protected function bulkCompressHeartbeat_v2($bulkRunning)
    {


        $_wpc_hb_start_t = microtime(true);
        $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
        $queue_data  = self::wpc_bulk_queue_read199() ?: ['queue' => [], 'total_images' => 0];
        $total       = (int) ($queue_data['total_images'] ?? (count($session_ids) + count($queue_data['queue'])));

        $cumulative_orig = 0;
        $cumulative_now  = 0;
        $variants_total  = 0;


        $img_pct_sum = 0.0;
        $img_pct_n   = 0;
        $active    = [];
        $completed = [];


        $pending_drain_in_completed = 0;


        $cache_key = 'wpc_bulk_completed_cache';
        $completed_cache = get_transient($cache_key);
        if (!is_array($completed_cache)) $completed_cache = [];
        $cache_dirty = false;

        foreach ($session_ids as $id) {
            $id = (int) $id;


            if (isset($completed_cache[$id])) {
                $c = $completed_cache[$id];
                $cached_full = isset($c['expected']) && isset($c['variants'])
                    && (int) $c['expected'] > 0
                    && (int) $c['variants'] >= (int) $c['expected'];
                if ($cached_full) {
                    $completed[]      = $c['entry'];
                    $cumulative_orig += (int) $c['orig'];
                    $cumulative_now  += (int) $c['now'];
                    $variants_total  += (int) $c['variants'];
                    $c_orig = (int) $c['orig'];
                    $c_now  = (int) $c['now'];
                    if ($c_orig > 0) {
                        $img_pct_sum += (100.0 * max(0, $c_orig - $c_now) / $c_orig);
                        $img_pct_n++;
                    }
                    continue;
                }
                
                
                unset($completed_cache[$id]);
                $cache_dirty = true;
            }


            wp_cache_delete($id, 'post_meta');
            $variants = get_post_meta($id, 'ic_local_variants', true);
            if (!is_array($variants)) $variants = [];
            $variant_count = count($variants);
            $variants_total += $variant_count;

            $img_orig = 0;
            $img_now  = 0;
            foreach ($variants as $v) {
                if (!is_array($v)) continue;
                $img_orig += (int) ($v['originalSize'] ?? 0);
                $img_now  += (int) ($v['size'] ?? 0);
            }
            $cumulative_orig += $img_orig;
            $cumulative_now  += $img_now;
            if ($img_orig > 0) {
                $img_pct_sum += (100.0 * max(0, $img_orig - $img_now) / $img_orig);
                $img_pct_n++;
            }


            $ic_st = get_post_meta($id, 'ic_compressing', true);
            $img_status = (is_array($ic_st) && !empty($ic_st['status']))
                ? (string) $ic_st['status']
                : 'optimizing';
            $expected = is_array($ic_st) ? (int) ($ic_st['expected_variants'] ?? 0) : 0;

            
            $pending = get_transient('wpc_v2_pending_' . $id);
            $still_draining = !empty($pending) && !empty($pending['pending']);
            $phase_a_done = (bool) get_transient('wpc_v2_phase_a_done_' . $id);


            $accounted = 0;
            if (is_array($variants)) {
                foreach ($variants as $v) {
                    if (!is_array($v)) continue;
                    if (!empty($v['size']) || !empty($v['bg_no_improvement'])) {
                        $accounted++;
                    }
                }
            }


            $threshold_pct = (float) apply_filters('wpc_v2_early_advance_pct', 0.90);
            $early_threshold = $expected > 10
                ? max(1, (int) ceil($expected * $threshold_pct))
                : $expected;

            $is_completed_full  = $expected > 0 && $accounted >= $expected;
            $is_completed_early = $expected > 0 && $accounted >= $early_threshold;
            $is_completed = ($img_status === 'compressed') && $is_completed_early;


            if (!$is_completed && $expected === 0 && !empty($variants)) {
                $is_completed = $phase_a_done
                    && !$still_draining
                    && ($img_status === 'compressed');
                $is_completed_full = $is_completed;
            }

            
            


            if ($is_completed && !$is_completed_full && function_exists('wpc_v2_fire_image_bg_retry')) {
                $retry_fired_key = 'wpc_v2_bg_retry_fired_' . $id;
                if (!get_transient($retry_fired_key)) {
                    set_transient($retry_fired_key, time(), 60);
                    wpc_v2_fire_image_bg_retry($id);
                }
            }

            
            
            $thumb = (string) wp_get_attachment_image_url($id, 'medium_large');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($id, 'medium');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($id, 'thumbnail');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($id, 'full');


            $file_path = get_attached_file($id);
            $file_name = $file_path ? basename($file_path) : '';
            if ($file_name !== '') {
                $file_name = preg_replace('/\.[a-zA-Z0-9]+$/', '', $file_name);
                $file_name = preg_replace('/-scaled$/', '', $file_name);
            }
            $title = $file_name !== '' ? $file_name : get_the_title($id);

            if ($is_completed) {


                $ml_payload_c = $this->wpc_compute_heartbeat_payload($id);
                $c_jpeg = (int) ($ml_payload_c['chip']['jpeg'] ?? 0);
                $c_webp = (int) ($ml_payload_c['chip']['webp'] ?? 0);
                $c_avif = (int) ($ml_payload_c['chip']['avif'] ?? 0);
                $entry = [
                    'id'    => $id,
                    'title' => $title,
                    'orig'  => $img_orig,
                    'now'   => $img_now,
                    'pct'   => (float) ($ml_payload_c['savings_pct'] ?? 0),
                    'thumb' => $thumb,
                    'count' => $c_jpeg + $c_webp + $c_avif,
                    'jpeg'  => $c_jpeg,
                    'webp'  => $c_webp,
                    'avif'  => $c_avif,
                ];
                $completed[] = $entry;


                if ($expected > 0 && $accounted < $expected) {
                    $pending_drain_in_completed++;
                }


                $completed_cache[$id] = [
                    'entry'        => $entry,
                    'orig'         => $img_orig,
                    'now'          => $img_now,
                    'variants'     => $accounted,
                    'expected'     => $expected,
                    'variant_data' => $variants,
                ];
                $cache_dirty = true;
            } else {
                $ml_payload = $this->wpc_compute_heartbeat_payload($id);
                $a_jpeg = (int) ($ml_payload['chip']['jpeg'] ?? 0);
                $a_webp = (int) ($ml_payload['chip']['webp'] ?? 0);
                $a_avif = (int) ($ml_payload['chip']['avif'] ?? 0);
                $a_savings = (float) ($ml_payload['savings_pct'] ?? 0);
                $active[] = [
                    'id'    => $id,
                    'title' => $title,
                    'thumb' => $thumb,
                    'count' => $a_jpeg + $a_webp + $a_avif,
                    'jpeg'  => $a_jpeg,
                    'webp'  => $a_webp,
                    'avif'  => $a_avif,
                    'savings_pct' => $a_savings,
                ];


                if (apply_filters('wpc_bulk_debug_chip', (bool) get_option('wpc_bulk_debug_chip', false))) {
                    error_log(sprintf(
                        '[WPC BulkHB DEBUG] active imageID=%d status=%s expected=%d accounted=%d ml_chip=J%d/W%d/A%d ml_pct=%.1f ml_count=%d variant_count=%d raw_pending=%s',
                        $id,
                        $img_status,
                        $expected,
                        $accounted,
                        $a_jpeg, $a_webp, $a_avif,
                        $a_savings,
                        (int) ($ml_payload['chip']['count'] ?? -1),
                        $variant_count,
                        json_encode($pending)
                    ));
                }


                if ($accounted > 0 && $accounted < $expected
                    && function_exists('wpc_v2_fire_image_bg_retry')) {
                    $retry_fired_key = 'wpc_v2_bg_retry_fired_' . $id;
                    if (!get_transient($retry_fired_key)) {
                        set_transient($retry_fired_key, time(), 60);
                        wpc_v2_fire_image_bg_retry($id);
                        error_log(sprintf(
                            '[WPC BulkHB] imageID=%d active_bg_retry_fired accounted=%d expected=%d',
                            $id, $accounted, $expected
                        ));
                    }
                }
            }
        }

        if ($cache_dirty) {


            set_transient($cache_key, $completed_cache, 6 * HOUR_IN_SECONDS);
        }


        $since_ms = isset($_POST['since_ms']) ? (int) $_POST['since_ms'] : 0;
        $new_variants = [];


        $is_initial = ($since_ms === 0);
        foreach ($session_ids as $id) {
            $id = (int) $id;
            $cached_variants = null;


            wp_cache_delete($id, 'post_meta');
            $variants = get_post_meta($id, 'ic_local_variants', true);
            if (!is_array($variants)) continue;


            $p_thumb = (string) wp_get_attachment_image_url($id, 'medium_large');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'medium');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'thumbnail');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'full');


            $p_file_path = get_attached_file($id);
            $p_file_name = $p_file_path ? basename($p_file_path) : '';
            if ($p_file_name !== '') {
                $p_file_name = preg_replace('/\.[a-zA-Z0-9]+$/', '', $p_file_name);
                $p_file_name = preg_replace('/-scaled$/', '', $p_file_name);
            }
            $p_title = $p_file_name !== '' ? $p_file_name : get_the_title($id);
            foreach ($variants as $key => $v) {
                if (!is_array($v)) continue;


                if (!empty($v['bg_upgraded_ms'])) {
                    $ms = (int) $v['bg_upgraded_ms'];
                } elseif (!empty($v['bg_upgraded'])) {
                    $ms = (int) $v['bg_upgraded'] * 1000;
                } else {
                    $ms = 0;
                }
                if ($ms <= $since_ms) continue;


                $last_dash = strrpos($key, '-');
                if ($last_dash === false) {
                    
                    $size_label = $key;
                    $format     = 'jpeg';
                } else {
                    $size_label = substr($key, 0, $last_dash);
                    $format     = substr($key, $last_dash + 1);
                }
                $format = strtolower($format);
                if ($format === 'jpg') $format = 'jpeg';
                
                
                if (!in_array($format, ['jpeg', 'webp', 'avif'], true)) continue;
                
                if (empty($v['size'])) continue;
                $bytes_v    = (int) ($v['size'] ?? 0);
                $orig_v     = (int) ($v['originalSize'] ?? 0);
                $saved_v    = max(0, $orig_v - $bytes_v);
                $pct_v      = $orig_v > 0 ? (int) round(100 * $saved_v / $orig_v) : 0;
                $new_variants[] = [
                    'id'         => $id,
                    'title'      => $p_title,
                    'thumb'      => $p_thumb,
                    'key'        => $key,
                    'format'     => strtolower($format),
                    'size_label' => $size_label,
                    'bytes'      => $bytes_v,
                    'saved'      => $saved_v,
                    'pct'        => $pct_v,
                    'ms'         => $ms,
                ];
            }
        }


        
        $persisted_keys_by_image = [];
        foreach ($new_variants as $nv) {
            $persisted_keys_by_image[$nv['id']][$nv['key']] = true;
        }
        $now_s = time();
        foreach ($session_ids as $id) {
            $id = (int) $id;
            if (isset($completed_cache[$id])) continue;
            $announced = get_transient('wpc_v2_announced_' . $id);
            if (!is_array($announced) || empty($announced)) continue;
            
            $p_thumb = (string) wp_get_attachment_image_url($id, 'thumbnail');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'full');
            $p_title = get_the_title($id);
            foreach ($announced as $key => $a) {
                if (!is_array($a)) continue;
                if (isset($persisted_keys_by_image[$id][$key])) continue;
                $exp = (int) ($a['expires_at'] ?? 0);
                if ($exp > 0 && $exp < $now_s) continue;
                $ann_ms = (int) ($a['announced_ms'] ?? 0);
                if ($ann_ms <= $since_ms) continue;
                $new_variants[] = [
                    'id'            => $id,
                    'title'         => $p_title,
                    'thumb'         => $p_thumb,
                    'key'           => (string) $key,
                    'format'        => (string) ($a['format'] ?? ''),
                    'size_label'    => (string) ($a['sizeLabel'] ?? ''),
                    'bytes'         => (int) ($a['bytes_est'] ?? 0),


                    'saved'         => (int) ($a['originalSize'] ?? 0) - (int) ($a['bytes_est'] ?? 0),
                    'pct'           => (int) ($a['savings'] ?? 0),
                    'ms'            => $ann_ms,
                    'pending'       => true,
                    'noImprovement' => !empty($a['noImprovement']),
                ];
            }
        }


        usort($new_variants, function ($a, $b) { return $b['ms'] - $a['ms']; });
        $new_variants = array_slice($new_variants, 0, 200);

        
        $completed = array_reverse($completed);
        $bytes_saved = max(0, $cumulative_orig - $cumulative_now);
        $savings_pct = $cumulative_orig > 0
            ? round(100 * $bytes_saved / $cumulative_orig, 1)
            : 0;


        $savings_pct_avg = $img_pct_n > 0 ? round($img_pct_sum / $img_pct_n, 1) : 0;


        $queue_empty = empty($queue_data['queue']);


        $all_in_completed = ($total > 0) && (count($completed) >= $total) && empty($active);
        $bulk_done   = ($queue_empty && empty($bulkRunning)) || $all_in_completed;
        if ($all_in_completed) {


            set_transient('wps_ic_bulk_done', true, 60);


            $queue_empty = true;


            if (!get_transient('wpc_bulk_completion_cleanup_done')) {
                set_transient('wpc_bulk_completion_cleanup_done', time(), 300);


                delete_option('wps_ic_bulk_process');
                delete_transient('wps_ic_bulk_running');
                delete_transient('wpc_bulk_library_counts');
                foreach ($session_ids as $cid) {
                    $cid = (int) $cid;
                    if ($cid <= 0) continue;
                    $cic = get_post_meta($cid, 'ic_compressing', true);
                    $cstatus = is_array($cic) ? ($cic['status'] ?? '') : '';
                    if ($cstatus !== 'compressed') {
                        if (function_exists('wpc_v2_ic_compressing_set_status')) {
                            wpc_v2_ic_compressing_set_status($cid, 'compressed');
                        } else {
                            $new = is_array($cic) ? $cic : [];
                            $new['status'] = 'compressed';
                            update_post_meta($cid, 'ic_compressing', $new);
                        }
                    }


                    if (get_post_meta($cid, 'ic_status', true) !== 'compressed') {
                        update_post_meta($cid, 'ic_status', 'compressed');
                        
                        if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
                    }
                    set_transient('wpc_v2_phase_a_done_' . $cid, time(), 3600);
                    
                    
                    delete_transient('wps_ic_compress_' . $cid);
                    delete_transient('wpc_v2_pending_' . $cid);
                    delete_transient('wpc_v2_warming_' . $cid);
                    wp_cache_delete($cid, 'post_meta');
                }
                error_log(sprintf('[WPC BulkHB] all_in_completed cleanup fired for %d session images', count($session_ids)));
            }
        }


        $up_next = [];
        if (!empty($queue_data['queue'])) {
            $upcoming = array_slice(array_map('intval', $queue_data['queue']), 0, 3);
            foreach ($upcoming as $uid) {
                $u_thumb = (string) wp_get_attachment_image_url($uid, 'medium_large');
                if (!$u_thumb) $u_thumb = (string) wp_get_attachment_image_url($uid, 'thumbnail');
                if (!$u_thumb) $u_thumb = (string) wp_get_attachment_image_url($uid, 'full');
                $up_next[] = [
                    'id'    => $uid,
                    'thumb' => $u_thumb,
                    'title' => get_the_title($uid),
                ];
            }
        }

        
        
        $this->wpc_log_bulk_heartbeat_telemetry(
            $_wpc_hb_start_t, count($session_ids), $variants_total,
            count($active), count($completed), count($new_variants)
        );

        if (!empty($queue_data['queue']) && empty(self::wpc_bulk_inflight199())
            && !get_transient('wpc_bulk_stop_signal')
            && !get_transient('wpc_bulk_compress_draining')
            && !(function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197())) {
            self::wpc_bulk_v2_fire_loopback();
        }
        $wpc_led204 = self::wpc_bulk_ledger_summary202();
        $wpc_total204 = $wpc_led204
            ? ($wpc_led204['queued'] + $wpc_led204['inflight'] + $wpc_led204['verified'] + $wpc_led204['parked'] + $wpc_led204['skipped'])
            : 0;
        $wpc_slots204 = self::wpc_bulk_inflight199();
        if ($wpc_led204 && !empty($wpc_slots204) && !empty($active)) {
            $active = array_values(array_filter($active, function ($a) use ($wpc_slots204) {
                return is_array($a) && isset($a['id']) && isset($wpc_slots204[(int) $a['id']]);
            }));
        }

        wp_send_json_success([
            
            'driver'          => 'v2',
            'total'           => $wpc_total204 > 0 ? $wpc_total204 : $total,
            'processed'       => $wpc_led204 ? (int) $wpc_led204['verified'] : count($completed),


            'pending_drain'   => count($active) + $pending_drain_in_completed,
            'inflight'        => count(self::wpc_bulk_inflight199()),
            'ledger'          => self::wpc_bulk_ledger_summary202(),
            'halt'            => [
                'stop'         => (bool) get_transient('wpc_bulk_stop_signal'),
                'store_broken' => function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197(),
            ],
            'reconcile'       => self::wpc_bulk_reconcile202(),
            'generated_at'    => time(),
            'cumulative_orig' => $cumulative_orig,
            'cumulative_now'  => $cumulative_now,
            'bytes_saved'     => $bytes_saved,
            'savings_pct'     => $savings_pct,
            'savings_pct_avg' => $savings_pct_avg,
            'variants_total'  => $variants_total,


            'active'          => $active,
            'completed'       => $completed,
            'new_variants'    => $new_variants,
            'queue_empty'     => $queue_empty,


            'queue_ids'       => array_map('intval', $queue_data['queue'] ?? []),
            'active_ids'      => array_map(function($a){ return (int) $a['id']; }, $active),
            'completed_ids'   => array_map(function($c){ return (int) $c['id']; }, $completed),
            'up_next'         => $up_next,


            'progress'                 => $total > 0 ? round(100 * count($completed) / $total) : 0,
            'progressCompressedImages' => '<h3>' . count($completed) . '/' . $total . '</h3><h5>Original Images</h5>',
            'progressTotalSavings'     => '<h3>' . wps_ic_format_bytes($bytes_saved, null, null, false) . '</h3><h5>Total Savings</h5>',
            'progressAvgReduction'     => '<h3>' . $savings_pct . '%</h3><h5>Average Savings</h5>',
            'progressCompressedThumbs' => '',
            'status'                   => ($bulk_done && empty($active)) ? 'done' : 'compressing',
        ]);


    }


    private function wpc_log_bulk_heartbeat_telemetry($start_t, $session_count, $variants_total, $active_count, $completed_count, $new_variants_count)
    {


        if (mt_rand(1, 10) !== 1) return;

        $wall_ms = (int) round((microtime(true) - $start_t) * 1000);
        $peak_mb = round(memory_get_peak_usage(true) / 1048576, 1);
        $mem_mb  = round(memory_get_usage(true)      / 1048576, 1);
        $limit   = (string) ini_get('memory_limit');
        error_log(sprintf(
            '[WPC BulkHB] tele wall_ms=%d peak_mb=%s used_mb=%s limit=%s session=%d variants=%d active=%d completed=%d new_variants=%d',
            $wall_ms, $peak_mb, $mem_mb, $limit,
            (int) $session_count, (int) $variants_total,
            (int) $active_count,  (int) $completed_count, (int) $new_variants_count
        ));
    }

    public function bulkCompressHtml($imageID)
    {
        $output = '';

        $thumbnail = wp_get_attachment_image_src($imageID, 'large');
        $full = wp_get_attachment_image_src($imageID, 'full');

        $image_filename = basename($thumbnail[0]);
        $image_full_filename = basename($full[0]);

        
        $ic_savings = get_post_meta($imageID, 'ic_savings', true);
        $ic_baseline = get_post_meta($imageID, 'ic_savings_baseline', true);
        $ic_saved_bytes = get_post_meta($imageID, 'ic_savings_bytes', true);

        
        if ($ic_baseline > 0) {
            $original_filesize = wps_ic_format_bytes($ic_baseline, null, null, false);
            $after_size = $ic_baseline - $ic_saved_bytes;
            $compressed_filesize = wps_ic_format_bytes($after_size, null, null, false);
            $savings = floatval($ic_savings);
        } else {
            
            $stats = get_post_meta($imageID, 'ic_stats', true);
            if (empty($stats)) {
                $uploadfile = get_attached_file($imageID);
                $stats['original']['original']['size'] = @filesize($uploadfile) ?: 0;
                $stats['original']['compressed']['size'] = 0;
            }
            $original_filesize = wps_ic_format_bytes($stats['original']['original']['size'], null, null, false);
            $compressed_filesize = wps_ic_format_bytes($stats['original']['compressed']['size'], null, null, false);
            $savings = ($stats['original']['original']['size'] > 0 && $stats['original']['compressed']['size'] > 0)
                ? round((1 - ($stats['original']['compressed']['size'] / $stats['original']['original']['size'])) * 100, 1)
                : 0;
        }

        
        $backup_images = get_post_meta($imageID, 'ic_backup_images', true);
        if (!empty($backup_images['full']) && !file_exists($backup_images['full'])) {
            $original_image = $thumbnail[0];
        } else {
            $original_image = $full[0];
        }

        $savingsHTML = '';
        if ($savings <= 0.9) {
            $savingsHTML = 'No further savings';
        } else {
            $savingsHTML = $savings . '% Savings';
        }

        $output .= '<div class="wps-ic-bulk-html-wrapper">';

        $output .= '<div class="wps-ic-bulk-header">';
        $output .= '<div class="wps-ic-bulk-before">';
        $output .= '<div class="image-holder">';

        $output .= '<div class="image-holder-inner">';
        $output .= '<div style="background-image:url(' . $original_image . ');" class="image-holder-bg"></div>';
        $output .= '</div>';

        $output .= '<div class="image-info-holder">';
        $output .= '<h4>Before</h4>';
        $output .= '<h3>' . $original_filesize . '</h3>';
        $output .= '</div>';

        $output .= '</div>';
        $output .= '</div>';
        $output .= '<div class="wps-ic-bulk-logo">';
        $output .= '<div class="logo-holder">';
        $output .= '<div class="wps-ic-bulk-preparing-logo-container">
        <div class="wps-ic-bulk-preparing-logo">
          <img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
          <svg class="bulk-preparing" xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid"><circle cx="50" cy="50" r="30" stroke="var(--wpc-brand-bg, #eff7ff)" stroke-width="5" fill="none"></circle><circle cx="50" cy="50" r="30" stroke="var(--wpc-brand-primary, #4c89eb)" stroke-width="3" stroke-linecap="round" fill="none"><animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="2s" values="0 50 50;180 50 50;720 50 50" keyTimes="0;0.5;1"></animateTransform><animate attributeName="stroke-dasharray" repeatCount="indefinite" dur="2s" values="18.85 169.65;94.25 94.25;18.85 169.65" keyTimes="0;0.5;1"></animate></circle></svg>
        </div>
      </div>';
        $output .= '</div>';
        $output .= '<div class="wps-ic-percent-savings">';
        $output .= '<h3>' . $savingsHTML . '</h3>';
        $output .= '</div>';
        $output .= '<div class="wps-ic-bulk-loading">';
        $output .= '';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '<div class="wps-ic-bulk-after">';
        $output .= '<div class="image-holder">';

        $output .= '<div class="image-holder-inner">';
        $output .= '<div style="background-image:url(' . $thumbnail[0] . ');" class="image-holder-bg"></div>';
        $output .= '</div>';

        $output .= '<div class="image-info-holder">';
        $output .= '<h4>After</h4>';
        $output .= '<h3>' . $compressed_filesize . '</h3>';
        $output .= '</div>';

        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '</div>';

        return $output;
    }

    public function wps_ic_StopBulk()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }


        @ignore_user_abort(true);

        set_transient('wpc_bulk_stop_signal', time(), 60);

        global $wpdb;

        $session_ids = get_transient('wpc_bulk_session_ids') ?: [];


        foreach ($session_ids as $sid) {
            set_transient('wpc_v2_callbacks_blocked_' . (int) $sid, time(), 600);
            delete_transient('wpc_v2_pending_' . (int) $sid);
        }

        self::wpc_bulk_ledger_close202();
        
        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_BulkStatus');
        delete_option('wps_ic_bulk_process');
        set_transient('wps_ic_bulk_done', true, 60);

        self::wpc_bulk_queue_write199(null);
        delete_option('wpc_bulk_inflight199');
        delete_option('wpc_bulk_counted201');
        delete_transient('wps_ic_restore_queue');
        delete_transient('wpc_bulk_session_ids');
        delete_transient('wps_ic_bulk_running');
        delete_transient('wpc_bulk_completed_cache');
        delete_transient('wpc_bulk_library_counts');

        
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wps_ic_compress_') . '%'));

        
        wpc_worker_unlock('wpc_bulk_v2_chain');
        wpc_worker_unlock('wpc_bulk_v2_restore_chain');


        delete_option('wpc_v2_drain_alive_until_ms');
        delete_transient('wpc_v2_drain_running');


        $settle_start    = microtime(true);
        $settle_max_ms   = 2500;
        $settle_quiet_ms = 600;
        $settle_tick_ms  = 150;
        $last_seen_write = (int) get_option('wpc_v2_last_meta_write_at', 0);
        $last_change_at  = microtime(true);
        while (((microtime(true) - $settle_start) * 1000) < $settle_max_ms) {
            usleep($settle_tick_ms * 1000);
            wp_cache_delete('wpc_v2_last_meta_write_at', 'options');
            wp_cache_delete('alloptions', 'options');
            $now_val = (int) get_option('wpc_v2_last_meta_write_at', 0);
            if ($now_val !== $last_seen_write) {
                $last_seen_write = $now_val;
                $last_change_at  = microtime(true);
                continue;
            }
            if (((microtime(true) - $last_change_at) * 1000) >= $settle_quiet_ms) {
                break;
            }
        }


        $counts = wps_ic_local::countLibraryImages();


        wp_send_json_success([
            'uncompressed' => count($counts['uncompressed']),
            'compressed'   => count($counts['compressed']),
        ]);


    }

    public function wps_ic_getBulkStats()
    {
        $bulkStatus = get_option('wps_ic_BulkStatus');
        $parsedImages = get_option('wps_ic_parsed_images');

        $output = '<div class="wps-ic-bulk-html-wrapper">';
        $output .= '<div class="wps-ic-bulk-header">';
        $output .= '<div class="wps-ic-bulk-logo">';

        $output .= '<div class="logo-holder">';
        $output .= '<img src="' . WPS_IC_URI . 'assets/images/bulk/compress-complete.svg' . '">';
        $output .= '</div>';

        if ($_POST['type'] == 'compress') {
            $output .= '<div class="wps-ic-percent-savings">';
            $output .= '<h2>Image Compression Complete!</h2>';
            $output .= '</div>';

            
            if (!empty($bulkStatus)) {
                $totalImages = !empty($bulkStatus['compressedImageCount']) ? $bulkStatus['compressedImageCount'] : 0;
                $totalThumbs = !empty($bulkStatus['compressedThumbs']) ? $bulkStatus['compressedThumbs'] : 0;
                $originalSize = !empty($bulkStatus['total']['original']['size']) ? $bulkStatus['total']['original']['size'] : 0;
                $compressedSize = !empty($bulkStatus['total']['compressed']['size']) ? $bulkStatus['total']['compressed']['size'] : 0;
                $savings = $originalSize - $compressedSize;
                $avgReduction = ($originalSize > 0 && $totalThumbs > 0) ? round((1 - ($compressedSize / $originalSize)) * 100, 1) : 0;

                $output .= '<div class="wpc-completion-stats">';

                $output .= '<div class="wpc-completion-stat">';
                $output .= '<div class="wpc-completion-icon"><img src="' . WPS_IC_URI . 'assets/icons/bulk/original-images.svg" /></div>';
                $output .= '<h3>' . $totalImages . '</h3><h5>Original Images</h5>';
                $output .= '</div>';

                $output .= '<div class="wpc-completion-stat">';
                $output .= '<div class="wpc-completion-icon"><img src="' . WPS_IC_URI . 'assets/icons/bulk/total-images.svg" /></div>';
                $output .= '<h3>' . $totalThumbs . '</h3><h5>Total Images</h5>';
                $output .= '</div>';

                $output .= '<div class="wpc-completion-stat">';
                $output .= '<div class="wpc-completion-icon"><img src="' . WPS_IC_URI . 'assets/icons/bulk/total-savings.svg" /></div>';
                $output .= '<h3>' . wps_ic_format_bytes($savings, null, null, false) . '</h3><h5>Total Savings</h5>';
                $output .= '</div>';

                $output .= '<div class="wpc-completion-stat">';
                $output .= '<div class="wpc-completion-icon"><img src="' . WPS_IC_URI . 'assets/icons/bulk/average-savings.svg" /></div>';
                $output .= '<h3>' . $avgReduction . '%</h3><h5>Average Savings</h5>';
                $output .= '</div>';

                $output .= '</div>';
            }
        } else {
            $output .= '<div class="wps-ic-percent-savings" style="margin-bottom:40px;">';
            $output .= '<h2>Image Restore Complete</h2>';
            $output .= '</div>';

            
            if (!empty($bulkStatus)) {
                $restoredCount = !empty($bulkStatus['restoredImageCount']) ? $bulkStatus['restoredImageCount'] : 0;
                $output .= '<div class="bulk-restore-status-progress" style="display:flex;justify-content:center;margin-top:10px;">';
                $output .= '<div class="bulk-images-restored" style="text-align:center;padding:20px 40px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">';
                $output .= '<div style="margin-bottom:8px;"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22b73a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>';
                $output .= '<h3 style="font-size:28px;font-weight:700;margin:0 0 4px;">' . $restoredCount . '</h3>';
                $output .= '<h5 style="margin:0;color:#64748b;font-size:13px;">Images Restored</h5>';
                $output .= '</div>';
                $output .= '</div>';
            }
        }

        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_BulkStatus');
        delete_option('wps_ic_bulk_process');
        set_transient('wps_ic_bulk_done', true, 60);

        wp_send_json_success(['html' => $output]);
    }


    public function wps_ic_check_customer_activity()
    {


        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden');
        }
        if (!function_exists('wpc_v2_head_poll_enabled') || !wpc_v2_head_poll_enabled()) {
            wp_send_json_success(['enabled' => false]);
        }
        if (!function_exists('wpc_v2_orchestrator_url')) {
            wp_send_json_success(['enabled' => true, 'error' => 'orchestrator_url_resolver_missing']);
        }

        $orch_url = wpc_v2_orchestrator_url();


        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || empty($apikey)) {
            wp_send_json_success(['enabled' => true, 'error' => 'no_orchestrator_or_apikey']);
        }

        $url = rtrim($orch_url, '/') . '/admin/customer-activity?apikey=' . rawurlencode($apikey);

        $resp = wp_remote_request($url, [
            'method'  => 'HEAD',
            'timeout' => 4,
            'headers' => ['Authorization' => 'Bearer ' . $apikey],
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_success(['enabled' => true, 'error' => 'http_error', 'detail' => $resp->get_error_message()]);
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            wp_send_json_success(['enabled' => true, 'error' => 'http_status', 'code' => $code]);
        }


        
        $last_ts = (int) wp_remote_retrieve_header($resp, 'x-wpc-last-callback-at');
        $seen    = (int) get_option('wpc_v2_last_customer_activity_at', 0);
        $busted  = false;

        if ($last_ts > 0 && $last_ts > $seen) {
            delete_transient('wpc_bulk_library_counts');
            
            
            $wpc_snap_ba = get_option('wpc_bulk_library_counts_d');
            if (is_array($wpc_snap_ba)) {
                $wpc_snap_ba['t'] = 0;
                update_option('wpc_bulk_library_counts_d', $wpc_snap_ba, false);
            }
            update_option('wpc_v2_last_customer_activity_at', $last_ts, false);
            $busted = true;
        }

        wp_send_json_success([
            'enabled'        => true,
            'lastCallbackAt' => $last_ts,
            'seen'           => $seen,
            'busted'         => $busted,
        ]);
    }


    public function wps_ic_pull_manifest()
    {
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden');
        }
        if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
            wp_send_json_success(['enabled' => false]);
        }
        if (!function_exists('wpc_v2_pull_manifest_tick')) {
            wp_send_json_success(['enabled' => true, 'error' => 'pull_manifest_module_missing']);
        }


        $wait_ms = isset($_POST['wait_ms']) ? (int) $_POST['wait_ms'] : 0;
        $limit   = isset($_POST['limit'])   ? (int) $_POST['limit']   : 100;

        
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) { $wait_ms = 0; }
        $wait_ms = max(0, min($wait_ms, 30000));

        $wpc_lp_t0751 = microtime(true);
        $result = wpc_v2_pull_manifest_tick($limit, $wait_ms);
        
        
        if ($wait_ms > 0) {
            $wpc_lp_held751 = (int) round((microtime(true) - $wpc_lp_t0751) * 1000);
            $GLOBALS['wpc_sr_waived_ms'] = (isset($GLOBALS['wpc_sr_waived_ms']) ? (int) $GLOBALS['wpc_sr_waived_ms'] : 0)
                + min($wpc_lp_held751, $wait_ms);
        }
        $result['enabled'] = true;
        wp_send_json_success($result);
    }

    



    public function wps_ic_isBulkRunning()
    {
        $bulkRunning = get_option('wps_ic_bulk_process');
        if (!$bulkRunning || empty($bulkRunning['status'])) {
            wp_send_json_error('not-running');
        }

        $status = ($bulkRunning['status'] === 'compressing') ? 'compressing' : 'restoring';
        $driver = !empty($bulkRunning['driver']) ? (string) $bulkRunning['driver'] : 'v1';


        if ($driver === 'sequential') {
            $queue_data = self::wpc_bulk_queue_read199();
            $remaining = (is_array($queue_data) && !empty($queue_data['queue']))
                ? array_values(array_map('intval', $queue_data['queue']))
                : [];
            wp_send_json([
                'success' => true,
                'data'    => $status,
                'driver'  => $driver,
                'queue'   => $remaining,
            ]);
        }


        wp_send_json([
            'success' => true,
            'data'    => $status,
            'driver'  => $driver,
        ]);
    }

    



    public function wpc_ic_start_bulk_restore()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }
        
        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }


        $inflight = get_transient('wpc_restore_start_inflight');
        if (!empty($inflight)) {
            status_header(409);
            wp_send_json_error(['msg' => 'bulk-start-inflight']);
        }
        set_transient('wpc_restore_start_inflight', time(), 10);


        $rs_t0 = microtime(true);
        error_log('[WPC RestoreStart] handler ENTERED at ' . gmdate('H:i:s'));

        
        delete_transient('wps_ic_bulk_done');
        delete_option('wps_ic_parsed_images');

        $local = new wps_ic_local();
        $prep_t0 = microtime(true);
        $imagesToRestore = $local->prepareRestoreImages();
        error_log(sprintf('[WPC RestoreStart] prepareRestoreImages took %dms (found %d compressed)',
            (int) round((microtime(true) - $prep_t0) * 1000),
            is_array($imagesToRestore['compressed'] ?? null) ? count($imagesToRestore['compressed']) : 0
        ));

        
        


        if (!empty($imagesToRestore['compressed'])) {
            $image_ids = array_keys($imagesToRestore['compressed']);
            $total     = count($image_ids);

            set_transient('wps_ic_restore_queue', [
                'queue' => array_values($image_ids),
                'total' => $total,
            ], 2 * HOUR_IN_SECONDS);

            update_option('wps_ic_BulkStatus', [
                'foundImageCount'    => $total,
                'restoredImageCount' => 0,
            ]);

            update_option('wps_ic_bulk_process', [
                'date'   => date('y-m-d H:i:s'),
                'status' => 'restoring',
                'driver' => 'v2',
            ]);
            set_transient('wps_ic_bulk_running', date('y-m-d H:i:s'), 2 * HOUR_IN_SECONDS);
            if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }
            
            delete_transient('wpc_bulk_stop_signal');


            update_option('wpc_bulk_restore_started_ms', (int) round(microtime(true) * 1000), false);

            
            self::wpc_bulk_v2_restore_fire_loopback();

            error_log(sprintf('[WPC RestoreStart] returning bulk-restored — total handler wall %dms (queued %d images)',
                (int) round((microtime(true) - $rs_t0) * 1000), $total));
            wp_send_json_success('bulk-restored');
        }

        $send = $local->sendToAPI('restore');

        if ($send['status'] == 'success') {
            update_option('wps_ic_bulk_process', ['date' => date('y-m-d H:i:s'), 'status' => 'restoring']);
            set_transient('wps_ic_bulk_running', date('y-m-d H:i:s'), 60 * 5);
            if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }

            
            $local = new wps_ic_local();

            
            $send = $local->sendBulkRestoreToApi();

            if ($send['status'] == 'failed') {

                $reason = $send['reason'];

                if ($reason == 'bad-apikey') {
                    $reason = 'bulk-process-bad-apikey';
                }

                wp_send_json_error(['msg' => $reason, 'send' => print_r($send, true)]);

            } elseif ($send['status'] == 'success') {

                update_option('wps_ic_bulk_process', ['date' => date('y-m-d H:i:s'), 'status' => 'restoring']);
                set_transient('wps_ic_bulk_running', date('y-m-d H:i:s'), 60 * 5);
                if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }
                wp_send_json_success($send);
            } else {
                wp_send_json_error($send);
            }


            wp_send_json_success($send);
        } else {
            wp_send_json_error($send);
        }
    }

    public function olderBackup($imageID)
    {
        return false;
        $backup_images = get_post_meta($imageID, 'ic_backup_images', true);

        if (!empty($backup_images) && is_array($backup_images)) {
            $compressed_images = get_post_meta($imageID, 'ic_compressed_images', true);

            
            if (!empty($compressed_images)) {

                foreach ($compressed_images as $index => $path) {
                    if (strpos($index, 'webp') !== false) {
                        if (file_exists($path)) {
                            unlink($path);
                        }
                    }
                }

            }

            $upload_dir = wp_get_upload_dir();
            $sizes = get_intermediate_image_sizes();
            foreach ($sizes as $i => $size) {
                clearstatcache();
                $image = image_get_intermediate_size($imageID, $size);
                if ($image['path']) {
                    $path = $upload_dir['basedir'] . '/' . $image['path'];
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            $path_to_image = get_attached_file($imageID);

            
            $restore_image_path = $backup_images['full'];

            
            if (file_exists($restore_image_path)) {
                unlink($path_to_image);

                
                $copy = copy($restore_image_path, $path_to_image);

                
                unlink($restore_image_path);
            }

            clearstatcache();

            wp_update_attachment_metadata($imageID, wp_generate_attachment_metadata($imageID, $path_to_image));

            delete_transient('wps_ic_compress_' . $imageID);
            delete_post_meta($imageID, 'ic_bulk_running');

            
            delete_post_meta($imageID, 'ic_stats');
            delete_post_meta($imageID, 'ic_compressed_images');
            delete_post_meta($imageID, 'ic_compressed_thumbs');
            delete_post_meta($imageID, 'ic_backup_images');
            update_post_meta($imageID, 'ic_status', 'restored');

            return true;
        }

        return false;
    }

    



    public function wpc_ic_start_bulk_compress()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }


        $inflight = get_transient('wpc_bulk_start_inflight');
        if (!empty($inflight)) {
            status_header(409);
            wp_send_json_error(['msg' => 'bulk-start-inflight']);
        }
        set_transient('wpc_bulk_start_inflight', time(), 10);

        
        delete_transient('wps_ic_bulk_done');
        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_bulk_counter');

        
        $compress = new wps_local_compress();
        $image_ids = $compress->getAllImageIDs();
        $total = count($image_ids);

        if ($total === 0) {
            wp_send_json_error(['msg' => 'no-images-found']);
        }

        if (function_exists('wpc_v2_attempts_admit197')) {
            $wpc_adm314 = false;
            foreach ($image_ids as $wpc_id314) {
                if (wpc_v2_attempts_admit197((int) $wpc_id314) === true) {
                    $wpc_adm314 = true;
                    break;
                }
            }
            if (!$wpc_adm314) {
                delete_transient('wpc_bulk_start_inflight');
                error_log('[WPC Bulk] start refused - none of ' . $total . ' remaining image(s) admissible (parked/backoff)');
                wp_send_json_error(['msg' => 'all-parked', 'count' => $total]);
            }
        }

        self::wpc_bulk_queue_write199([
            'queue' => array_values($image_ids),
            'total_images' => $total,
        ]);
        delete_option('wpc_bulk_inflight199');
        delete_option('wpc_bulk_counted201');
        self::wpc_bulk_ledger_init202(array_values($image_ids));

        
        update_option('wps_ic_BulkStatus', [
            'foundImageCount' => $total,
            'compressedImageCount' => 0,
            'compressedThumbs' => 0,
            'total' => ['original' => ['size' => 0], 'compressed' => ['size' => 0]],
        ]);


        $is_v2 = function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol() && class_exists('WPS_LocalV2');


        $sequential = (bool) get_option('wpc_bulk_sequential', 0);
        $driver = $sequential ? 'sequential' : ($is_v2 ? 'v2' : 'v1');

        update_option('wps_ic_bulk_process', [
            'date'   => date('y-m-d H:i:s'),
            'status' => 'compressing',
            'driver' => $driver,
        ]);
        set_transient('wps_ic_bulk_running', date('y-m-d H:i:s'), 3600);


        if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }

        if ($driver === 'sequential') {
            
            
            set_transient('wpc_bulk_session_ids', array_values(array_map('intval', $image_ids)), 2 * HOUR_IN_SECONDS);
            delete_transient('wpc_bulk_stop_signal');
            delete_transient('wpc_bulk_completed_cache');
            delete_transient('wpc_bulk_library_counts');


            delete_transient('wpc_bulk_completion_cleanup_done');
            

            wp_send_json([
                'success' => true,
                'data'    => [
                    'status' => 'success',
                    'total'  => $total,
                    'queue'  => array_values(array_map('intval', $image_ids)),
                ],
                'driver'  => $driver,
            ]);
        }

        if ($is_v2) {
            set_transient('wpc_bulk_session_ids', [], 2 * HOUR_IN_SECONDS);
            delete_transient('wpc_bulk_stop_signal');
            delete_transient('wpc_bulk_completed_cache');
            delete_transient('wpc_bulk_library_counts');
            
            
            delete_transient('wpc_bulk_completion_cleanup_done');
            self::wpc_bulk_v2_fire_loopback();
        }

        wp_send_json([
            'success' => true,
            'data'    => ['status' => 'success', 'total' => $total],
            'driver'  => $driver,
        ]);
    }


    public function wps_ic_doBulkCompress()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }


        if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }


        $bulkRunning = get_option('wps_ic_bulk_process');
        if (!empty($bulkRunning['driver']) && $bulkRunning['driver'] === 'v2') {
            $queue = self::wpc_bulk_queue_read199();
            $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
            $any_active = false;
            foreach ($session_ids as $sid) {
                $pending = get_transient('wpc_v2_pending_' . (int) $sid);
                if (!empty($pending['pending'])) {
                    $any_active = true;
                    break;
                }
            }
            $truly_finished = empty($queue['queue']) && !$any_active;
            wp_send_json_success([
                'finished'             => $truly_finished,
                'driver_v2_owns_queue' => true,
                'progress'             => 0,
                'finished_count'       => count($session_ids),
                'leftover'             => empty($queue['queue']) ? 0 : count($queue['queue']),
                'savings'              => '0',
                'title'                => '',
                'skipped'              => true,
            ]);
        }

        ini_set('memory_limit', '2024M');
        ini_set('max_execution_time', '180');

        $queue_data = self::wpc_bulk_queue_read199();
        if (empty($queue_data['queue'])) {
            
            self::wpc_bulk_ledger_close202();
            delete_option('wps_ic_bulk_process');
            delete_transient('wps_ic_bulk_running');
            set_transient('wps_ic_bulk_done', true, 60);
            wp_send_json_success(['finished' => true]);
        }

        
        $imageID = intval($queue_data['queue'][0]);
        unset($queue_data['queue'][0]);
        $queue_data['queue'] = array_values($queue_data['queue']);
        self::wpc_bulk_queue_write199($queue_data);


        $compress = new wps_local_compress();
        $backupOk = $compress->backup_all_sizes($imageID);
        if (!$backupOk) {
            error_log('[WPC Bulk] SKIPPED image=' . $imageID . ' — backup failed');
        } else {


            $used_v2 = false;
            if (function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol() && class_exists('WPS_LocalV2')) {
                $v2_result = self::run_v2_optimize($imageID);
                if (!empty($v2_result['ok'])) {
                    $used_v2 = true;
                } elseif (!empty($v2_result['error']) && $v2_result['error'] === 'already_in_flight') {


                    error_log('[WPC Bulk] SKIPPED image=' . $imageID . ' — already_in_flight');
                    $used_v2 = true;
                }
            }
            if (!$used_v2) {
                $compress->singleCompressV4($imageID, 'silent', false, 'bulk');
            }
        }

        
        $status = get_post_meta($imageID, 'ic_status', true);
        $total = $queue_data['total_images'];
        $leftover = count($queue_data['queue']);

        $bulkStatus = get_option('wps_ic_BulkStatus');

        if ($status === 'compressed') {
            
            $bulkStatus['compressedImageCount'] = ($bulkStatus['compressedImageCount'] ?? 0) + 1;

            $variants = get_post_meta($imageID, 'ic_local_variants', true);
            $bulkStatus['compressedThumbs'] = ($bulkStatus['compressedThumbs'] ?? 0) + (is_array($variants) ? count($variants) : 0);

            $baseline = intval(get_post_meta($imageID, 'ic_savings_baseline', true));
            $bytes_saved = intval(get_post_meta($imageID, 'ic_savings_bytes', true));
            $bulkStatus['total']['original']['size'] = ($bulkStatus['total']['original']['size'] ?? 0) + $baseline;
            $bulkStatus['total']['compressed']['size'] = ($bulkStatus['total']['compressed']['size'] ?? 0) + ($baseline - $bytes_saved);
        }

        update_option('wps_ic_BulkStatus', $bulkStatus);

        $done = $bulkStatus['compressedImageCount'];
        $progress = $total > 0 ? round(($done / $total) * 100) : 100;

        wp_send_json_success([
            'done' => $imageID,
            'skipped' => ($status !== 'compressed'),
            'progress' => $progress,
            'finished_count' => $done,
            'leftover' => $leftover,
            'total' => $total,
            'savings' => get_post_meta($imageID, 'ic_savings', true),
            'title' => get_the_title($imageID),
        ]);
    }

    







    public static function wpc_loopback_open_socket($host, $port, $is_https, $connect_budget = 0.2)
    {
        $host = (string) $host;
        if ($host === '') { return false; }
        $port           = (int) $port;
        $is_https       = (bool) $is_https;
        $connect_budget = max(0.05, (float) $connect_budget);


        $connect_chain = apply_filters('wpc_loopback_connect_host', ['127.0.0.1', 'localhost', $host], $host, $is_https, $port);
        if (is_string($connect_chain) && $connect_chain !== '') { $connect_chain = [$connect_chain]; }
        if (!is_array($connect_chain) || empty($connect_chain)) { $connect_chain = ['127.0.0.1', 'localhost', $host]; }
        $connect_chain = array_values(array_unique(array_filter(array_map('strval', $connect_chain))));
        if (empty($connect_chain)) { return false; }


        $ssl_ctx = $is_https ? stream_context_create(['ssl' => [
            'peer_name'         => $host,
            'SNI_enabled'       => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]) : stream_context_create();

        $miss = [];
        foreach ($connect_chain as $chost) {
            if ($chost === '') { continue; }
            $errno  = 0;
            $errstr = '';
            $remote = ($is_https ? 'tls://' : 'tcp://') . $chost . ':' . $port;
            $sock   = @stream_socket_client($remote, $errno, $errstr, $connect_budget, STREAM_CLIENT_CONNECT, $ssl_ctx);
            if ($sock) {
                return $sock; 
            }
            $miss[] = $chost . '(' . $errno . ')';
        }
        
        error_log('[WPC LoopbackOpen] all_rungs_miss port=' . $port . ' tries=' . implode(',', $miss));
        return false;
    }

    public static function wpc_bulk_v2_fire_loopback()
    {
        
        $q = self::wpc_bulk_queue_read199();
        if (empty($q['queue'])
            && !(get_option('wps_ic_bulk_process') && !empty(self::wpc_bulk_inflight199()))) { return false; }
        
        
        if (($lock_ts = (int) get_transient('wpc_bulk_compress_draining')) > 0) {
            set_transient('wpc_bulk_compress_redrain_pending', time(), 60);
            return false;
        }
        set_transient('wpc_bulk_compress_draining', time(), 15);
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($apikey === '') { delete_transient('wpc_bulk_compress_draining'); return false; }
        $ts  = time();
        $sig = hash_hmac('sha256', 'wpc_bulk_compress_drain.' . $ts, $apikey); 
        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);                                           
        if (empty($parts['host'])) { delete_transient('wpc_bulk_compress_draining'); return false; }
        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        $port = $is_https ? 443 : 80;
        $host = (string) $parts['host'];
        $path = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_bulk_v2_drain_loop';
        $body = http_build_query(['t' => $ts, 'sig' => $sig]);
        $req  = "POST {$path} HTTP/1.1\r\nHost: {$host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($body) . "\r\nConnection: close\r\nUser-Agent: WPCBulkCompressDrain/1.0\r\n\r\n" . $body;


        $fp = self::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
        if (!$fp) { delete_transient('wpc_bulk_compress_draining'); error_log('[WPC BulkCompressDrain] loopback_connect_failed host=' . $host . ' port=' . $port); return false; }
        @stream_set_timeout($fp, 0, 100000); @fwrite($fp, $req); @fclose($fp);
        delete_transient('wpc_bulk_compress_redrain_pending');
        return true;
    }


    public function wpc_bulk_v2_drain()
    {
        
        if (!current_user_can('manage_wpc_settings')) {
            wp_die('', '', ['response' => 403]);
        }
        $this->run_compress_drain_slice();
        wp_die('', '', ['response' => 200]);
    }

    




    public function wpc_bulk_v2_drain_loop()
    {
        
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $ts     = isset($_POST['t']) ? (int) $_POST['t'] : 0;
        $sig    = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
        if ($apikey === '' || $ts <= 0 || $sig === '' || abs(time() - $ts) > 60) { http_response_code(401); exit('auth'); }
        $expected = hash_hmac('sha256', 'wpc_bulk_compress_drain.' . $ts, $apikey); 
        if (!hash_equals($expected, $sig)) { http_response_code(401); exit('sig'); }
        
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @ignore_user_abort(true);
        @set_time_limit(60); 
        $this->run_compress_drain_slice();
        exit;
    }

    private function run_compress_drain_slice()
    {
        delete_transient('wpc_bulk_compress_draining');
        if (!get_option('wps_ic_bulk_process')) {
            
            return;
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '60');
        @ignore_user_abort(true);

        global $wpdb;
        
        
        $got = wpc_worker_lock('wpc_bulk_v2_chain', 0) ? 1 : 0;
        if (!$got) {
            return;
        }

        $wpc_depth199 = max(1, (int) apply_filters('wpc_bulk_depth', defined('WPC_BULK_DEPTH') ? WPC_BULK_DEPTH : 4));
        $batch_K       = defined('WPC_BULK_K') ? max(1, (int) WPC_BULK_K) : $wpc_depth199;


        $wall_budget_s = 45.0;


        $sequential = (bool) get_option('wpc_bulk_sequential', 0);
        if ($sequential) {
            $batch_K = 1;


            $wall_budget_s = 30.0;
        }
        $started       = microtime(true);
        $iter_count    = 0;

        try {
            while ((microtime(true) - $started) < $wall_budget_s) {


                if (get_transient('wpc_bulk_stop_signal') || !get_option('wps_ic_bulk_process')) {
                    error_log('[WPC Bulk] drain paused mid-iteration');
                    break;
                }
                
                if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }
                
                
                $mem_limit = wp_convert_hr_to_bytes((string) @ini_get('memory_limit'));
                if ($mem_limit > 0 && memory_get_usage(true) > (int) ($mem_limit * 0.8)) {
                    error_log('[WPC Bulk] drain slice mem-cap break usage=' . memory_get_usage(true) . ' limit=' . $mem_limit);
                    break;
                }
                if (function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197()) {
                    set_transient('wpc_bulk_stop_signal', 1, 600);
                    error_log('[WPC Bulk] HALT — broken-store flag armed (DB writes not persisting); bulk stopped, admin notice live');
                    break;
                }
                $queue_data = self::wpc_bulk_queue_read199();
                if (empty($queue_data['queue'])) {

                    if (!$sequential && !empty(self::wpc_bulk_inflight199())) {
                        if (!self::wpc_bulk_pipeline_drain199()) {
                            break;
                        }
                        continue;
                    }
                    self::wpc_bulk_status_count201();
                    self::wpc_bulk_ledger_close202();
                    delete_option('wps_ic_bulk_process');
                    delete_transient('wps_ic_bulk_running');
                    delete_transient('wpc_bulk_library_counts');
                    delete_option('wpc_bulk_inflight199');
                    delete_option('wpc_bulk_counted201');
                    set_transient('wps_ic_bulk_done', true, 60);
                    break;
                }

                $wpc_slots199 = $batch_K;
                if (!$sequential) {
                    $wpc_slots199 = $wpc_depth199 - count(self::wpc_bulk_inflight199());
                    if ($wpc_slots199 <= 0) {
                        if (!self::wpc_bulk_pipeline_drain199()) {
                            break;
                        }
                        continue;
                    }
                }
                
                $batch = array_map('intval', array_slice($queue_data['queue'], 0, min($batch_K, $wpc_slots199)));
                $queue_data['queue'] = array_values(array_slice($queue_data['queue'], count($batch)));
                self::wpc_bulk_queue_write199($queue_data);

                
                $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
                foreach ($batch as $id) {
                    $session_ids[] = $id;
                }
                set_transient('wpc_bulk_session_ids', $session_ids, 2 * HOUR_IN_SECONDS);

                
                $preps = [];
                $compress = new wps_local_compress();
                foreach ($batch as $id) {


                    if (get_post_meta($id, 'wps_ic_exclude_live', true) === 'true') {
                        error_log('[WPC Bulk] SKIPPED image=' . $id . ' — excluded mid-bulk');
                        self::wpc_bulk_ledger_mark202($id, 'skipped');
                        continue;
                    }
                    $wpc_adm199 = function_exists('wpc_v2_attempts_admit197') ? wpc_v2_attempts_admit197($id) : true;
                    if ($wpc_adm199 !== true) {
                        error_log('[WPC Bulk] SKIPPED image=' . $id . ' — ' . $wpc_adm199 . ' (queue flows around it)');
                        if ($wpc_adm199 === 'parked_attempt_cap') {
                            self::wpc_bulk_ledger_mark202($id, 'parked');
                        } else {
                            self::wpc_bulk_ledger_mark202($id, 'skipped');
                        }
                        continue;
                    }
                    wp_cache_delete($id, 'post_meta');
                    $wpc_pend309 = get_transient('wpc_v2_pending_' . $id);
                    $wpc_icc309  = get_post_meta($id, 'ic_compressing', true);
                    $wpc_iccs309 = is_array($wpc_icc309) && !empty($wpc_icc309['status']) ? (string) $wpc_icc309['status'] : '';
                    $wpc_icct309 = is_array($wpc_icc309) ? (int) ($wpc_icc309['time'] ?? 0) : 0;
                    if (!empty($wpc_pend309['pending'])
                        || (($wpc_iccs309 === 'optimizing' || $wpc_iccs309 === 'queueing')
                            && $wpc_icct309 > 0 && (time() - $wpc_icct309) < (int) apply_filters('wpc_bulk_inflight_grace', 900))) {
                        error_log('[WPC Bulk] SKIPPED image=' . $id . ' — already in flight server-side (no attempt burned; results land via pull)');
                        self::wpc_bulk_ledger_mark202($id, 'skipped');
                        continue;
                    }
                    $backupOk = $compress->backup_all_sizes($id);
                    if (!$backupOk) {
                        error_log('[WPC Bulk] SKIPPED image=' . $id . ' — backup failed');
                        if (function_exists('wpc_v2_attempts_bump197')) { wpc_v2_attempts_bump197($id, 'backup_failed'); }
                        self::wpc_bulk_ledger_mark202($id, 'skipped');
                        continue;
                    }
                    $wpc_att199 = function_exists('wpc_v2_attempts_bump197') ? wpc_v2_attempts_bump197($id, 'new') : 1;
                    $wpc_led202 = self::wpc_bulk_ledger_get202();
                    $prep = self::prepare_v2_optimize($id, [
                        'triggerContext'  => 'bulk-compress',
                        'resubmit_reason' => $wpc_att199 > 1 ? 'retry_' . $wpc_att199 : 'new',
                        'attempt'         => $wpc_att199,
                        'run_id'          => $wpc_led202 !== false ? (string) $wpc_led202['run_id'] : '',
                    ]);
                    if (!empty($prep['ok'])) {
                        $preps[$id] = $prep;
                    } else {
                        error_log('[WPC Bulk] PREP_FAILED image=' . $id . ' — ' . ($prep['error'] ?? 'unknown'));
                        self::wpc_bulk_ledger_mark202($id, 'skipped');
                    }
                }

                if (!empty($preps)) {
                    $wpc_sent309 = self::wpc_bulk_v2_dispatch_batch($preps);
                    if (!is_array($wpc_sent309)) { $wpc_sent309 = array_map('intval', array_keys($preps)); }
                    $wpc_im199 = get_option('wpc_bulk_inflight199');
                    if (!is_array($wpc_im199)) { $wpc_im199 = []; }
                    foreach (array_keys($preps) as $wpc_ii199) {
                        if (!in_array((int) $wpc_ii199, $wpc_sent309, true)) {
                            self::wpc_bulk_ledger_mark202((int) $wpc_ii199, 'skipped');
                            continue;
                        }
                        $wpc_im199[(int) $wpc_ii199] = time();
                        self::wpc_bulk_ledger_mark202((int) $wpc_ii199, 'inflight');
                    }
                    update_option('wpc_bulk_inflight199', $wpc_im199, false);


                    if (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled()) {
                        $drain_deadline = (int) (microtime(true) * 1000) + 60000;
                        $current_deadline = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
                        if ($drain_deadline > $current_deadline) {
                            update_option('wpc_v2_drain_alive_until_ms', $drain_deadline, false);
                        }
                        if (function_exists('wpc_v2_pull_drain_fire')) {
                            wpc_v2_pull_drain_fire();
                        }
                    }


                    self::wpc_bulk_status_count201();
                }
                $iter_count++;


                if (!$sequential && !empty($preps)) {
                    self::wpc_bulk_pipeline_drain199();
                }
                
                if ($sequential && !empty($preps)) {
                    $wait_img_id    = (int) array_keys($preps)[0];
                    $wait_start     = microtime(true);
                    $wait_max_s     = 25.0;
                    while (true) {
                        
                        if ((microtime(true) - $started) >= $wall_budget_s) break;
                        if ((microtime(true) - $wait_start) >= $wait_max_s) break;
                        if (get_transient('wpc_bulk_stop_signal')) break;

                        wp_cache_delete($wait_img_id, 'post_meta');


                        $status = get_post_meta($wait_img_id, 'ic_status', true);
                        if ($status === 'compressed' || $status === 'failed') break;
                        $ic_c = get_post_meta($wait_img_id, 'ic_compressing', true);
                        $ic_c_status = is_array($ic_c) ? ($ic_c['status'] ?? '') : '';
                        if ($ic_c_status === 'compressed') break;

                        usleep(250000); 
                    }
                }
            }
        } finally {
            wpc_worker_unlock('wpc_bulk_v2_chain');
        }


        $queue_data = self::wpc_bulk_queue_read199();
        $redrain    = (int) get_transient('wpc_bulk_compress_redrain_pending') > 0;
        if ($redrain) { delete_transient('wpc_bulk_compress_redrain_pending'); }


        $wpc_bchain75_ok = true;
        if (!empty($queue_data['queue']) && is_array($queue_data['queue'])) {
            $wpc_bsig75 = md5((string) $queue_data['queue'][0] . ':' . count($queue_data['queue'])
                . ':' . count(self::wpc_bulk_inflight199()));
            $wpc_bsf75  = '';
            $wpc_bup75  = wp_get_upload_dir();
            if (!empty($wpc_bup75['basedir'])) {
                $wpc_bsd75 = rtrim($wpc_bup75['basedir'], '/\\') . '/wpci-journal';
                if (!is_dir($wpc_bsd75)) { @wp_mkdir_p($wpc_bsd75); }
                $wpc_bsf75 = is_dir($wpc_bsd75) ? $wpc_bsd75 . '/.bulk_state' : '';
            }
            if ($wpc_bsf75 !== '') {
                $wpc_bst75 = json_decode((string) @file_get_contents($wpc_bsf75), true);
                $wpc_brep75 = (is_array($wpc_bst75) && isset($wpc_bst75['sig']) && $wpc_bst75['sig'] === $wpc_bsig75)
                    ? (int) $wpc_bst75['reps'] + 1 : 1;
                @file_put_contents($wpc_bsf75, json_encode(['sig' => $wpc_bsig75, 'reps' => $wpc_brep75]));
                if ($wpc_brep75 >= 3) {
                    $wpc_bchain75_ok = false;
                    error_log(sprintf('[WPC Bulk] no-progress x%d (queue head/len unchanged) — self-chain halted; admin poll resumes on demand', $wpc_brep75));
                }
            }
        }
        $wpc_tail199 = empty($queue_data['queue']) && get_option('wps_ic_bulk_process')
            && !empty(self::wpc_bulk_inflight199());
        if (($wpc_bchain75_ok && !empty($queue_data['queue'])) || $redrain || $wpc_tail199) {
            self::wpc_bulk_v2_fire_loopback();
        }

        error_log(sprintf('[WPC Bulk] drain slice complete iters=%d K=%d wall=%.2fs',
            $iter_count, $batch_K, microtime(true) - $started));
    }


    public static function wpc_bulk_queue_read199()
    {
        $o = get_option('wpc_bulk_queue199');
        if (is_array($o) && isset($o['queue'])) {
            return $o;
        }
        $t = get_transient('wps_ic_compress_queue');
        return (is_array($t) && isset($t['queue'])) ? $t : false;
    }

    public static function wpc_bulk_queue_write199($data)
    {
        if (!is_array($data)) {
            delete_option('wpc_bulk_queue199');
            delete_transient('wps_ic_compress_queue');
            return;
        }
        update_option('wpc_bulk_queue199', $data, false);
        set_transient('wps_ic_compress_queue', $data, HOUR_IN_SECONDS);
    }

    public static function wpc_bulk_inflight199()
    {
        $m = get_option('wpc_bulk_inflight199');
        if (!is_array($m)) {
            $m = [];
        }
        $out = [];
        foreach ($m as $id => $ts) {
            $id = (int) $id;
            if ($id <= 0 || (time() - (int) $ts) > (int) apply_filters('wpc_bulk_slot_ttl', 120)) {
                continue;
            }
            wp_cache_delete($id, 'post_meta');
            $st = get_post_meta($id, 'ic_compressing', true);
            $sv = is_array($st) && !empty($st['status']) ? (string) $st['status'] : '';
            if ($sv !== 'optimizing' && $sv !== 'queueing') {
                continue;
            }
            $exp = is_array($st) ? (int) ($st['expected_variants'] ?? 0) : 0;
            if ($exp > 0) {
                $vs = get_post_meta($id, 'ic_local_variants', true);
                $acc = 0;
                if (is_array($vs)) {
                    foreach ($vs as $v) {
                        if (is_array($v) && (!empty($v['size']) || !empty($v['bg_no_improvement']))) $acc++;
                    }
                }
                if ($acc >= $exp) {
                    update_post_meta($id, 'ic_compressing', ['status' => 'compressed']);
                    update_post_meta($id, 'ic_status', 'compressed');
                    error_log('[WPC Bulk] slot released on accounting imageID=' . $id . ' acc=' . $acc . '/' . $exp . ' (status flip was transient-held)');
                    continue;
                }
            }
            $out[$id] = (int) $ts;
        }
        if ($out !== $m) {
            update_option('wpc_bulk_inflight199', $out, false);
        }
        return $out;
    }

    public static function wpc_bulk_ledger_get202()
    {
        $l = get_option('wpc_bulk_run_ledger');
        return (is_array($l) && !empty($l['run_id'])) ? $l : false;
    }

    public static function wpc_bulk_ledger_init202(array $ids)
    {
        $l = [
            'run_id'  => 'r' . dechex(time()) . substr(md5(uniqid('', true)), 0, 6),
            'started' => time(),
            'images'  => [],
        ];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $l['images'][$id] = ['st' => 'queued', 'ts' => time()];
            }
        }
        update_option('wpc_bulk_run_ledger', $l, false);
        if (defined('WPC_BULK_DEBUG') && WPC_BULK_DEBUG) {
            error_log('[WPC BulkLedger] init run=' . $l['run_id'] . ' images=' . count($l['images']));
        }
        return $l['run_id'];
    }

    public static function wpc_bulk_ledger_mark202($id, $st)
    {
        $id = (int) $id;
        if ($id <= 0 || !in_array($st, ['queued', 'inflight', 'verified', 'parked', 'skipped'], true)) {
            return;
        }
        $l = self::wpc_bulk_ledger_get202();
        if ($l === false) {
            return;
        }
        $prev = isset($l['images'][$id]['st']) ? (string) $l['images'][$id]['st'] : '';
        if ($prev === $st || $prev === 'verified') {
            return;
        }
        $l['images'][$id] = ['st' => $st, 'ts' => time()];
        update_option('wpc_bulk_run_ledger', $l, false);
        if (defined('WPC_BULK_DEBUG') && WPC_BULK_DEBUG) {
            error_log('[WPC BulkLedger] image=' . $id . ' ' . ($prev === '' ? '(new)' : $prev) . '->' . $st . ' run=' . $l['run_id']);
        }
    }

    public static function wpc_bulk_ledger_summary202()
    {
        $l = self::wpc_bulk_ledger_get202();
        if ($l === false) {
            return null;
        }
        $c = ['queued' => 0, 'inflight' => 0, 'verified' => 0, 'parked' => 0, 'skipped' => 0];
        foreach ($l['images'] as $row) {
            $st = is_array($row) && isset($row['st']) ? (string) $row['st'] : '';
            if (isset($c[$st])) {
                $c[$st]++;
            }
        }
        $c['run_id']  = (string) $l['run_id'];
        $c['started'] = (int) $l['started'];
        $c['at']      = time();
        return $c;
    }

    public static function wpc_bulk_ledger_close202()
    {
        $l = self::wpc_bulk_ledger_get202();
        if ($l === false) {
            return 0;
        }
        $n = 0;
        foreach ($l['images'] as $lid => $row) {
            $lst = is_array($row) && isset($row['st']) ? (string) $row['st'] : '';
            if ($lst === 'queued' || $lst === 'inflight') {
                $l['images'][(int) $lid] = ['st' => 'skipped', 'ts' => time()];
                $n++;
            }
        }
        if ($n > 0) {
            update_option('wpc_bulk_run_ledger', $l, false);
            error_log('[WPC BulkLedger] run closed — ' . $n . ' unfinished image(s) marked skipped (retry on next bulk run)');
        }
        return $n;
    }

    public static function wpc_bulk_run_summary_fetch202($run_id)
    {
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $run_id   = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $run_id);
        if ($orch_url === '' || $apikey === '' || $run_id === '') {
            return ['ok' => false, 'reason' => 'no_orch_or_run'];
        }
        $canonical = 'apikey=' . $apikey . '&run=' . $run_id;
        $resp = wp_remote_get(rtrim($orch_url, '/') . '/optimize-v2/run-summary?apikey=' . rawurlencode($apikey) . '&run=' . rawurlencode($run_id), [
            'timeout' => 6,
            'headers' => [
                'Accept'          => 'application/json',
                'X-WPC-Sig'       => hash_hmac('sha256', $canonical, $apikey),
                'X-WPC-Timestamp' => (string) time(),
            ],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return ['ok' => false, 'reason' => is_wp_error($resp) ? 'http_error' : ('http_' . wp_remote_retrieve_response_code($resp))];
        }
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($j)) {
            return ['ok' => false, 'reason' => 'bad_json'];
        }
        return ['ok' => true, 'summary' => $j];
    }

    public static function wpc_bulk_reconcile202()
    {
        $led = self::wpc_bulk_ledger_summary202();
        if ($led === null) {
            return null;
        }
        if ((time() - (int) get_option('wpc_bulk_recon_at202', 0)) < 30) {
            $last = get_option('wpc_bulk_recon_last202');
            return is_array($last) ? $last : null;
        }
        update_option('wpc_bulk_recon_at202', time(), false);
        $r = self::wpc_bulk_run_summary_fetch202($led['run_id']);
        if (empty($r['ok'])) {
            $out = ['sync' => 'unavailable', 'reason' => (string) ($r['reason'] ?? ''), 'at' => time()];
            update_option('wpc_bulk_recon_last202', $out, false);
            return $out;
        }
        $svc = $r['summary'];
        $svc_images = 0;
        if (isset($svc['image_count'])) {
            $svc_images = (int) $svc['image_count'];
        } elseif (isset($svc['images']) && is_array($svc['images'])) {
            $svc_images = count($svc['images']);
        }
        $mismatch = ($svc_images > 0 && $svc_images > ($led['verified'] + $led['inflight']));
        $out = ['sync' => $mismatch ? 'syncing' : 'ok', 'service_images' => $svc_images, 'at' => time()];
        if ($mismatch) {
            error_log('[WPC BulkLedger] reconcile mismatch run=' . $led['run_id']
                . ' service_images=' . $svc_images . ' ledger_verified=' . $led['verified'] . ' inflight=' . $led['inflight']);
        }
        update_option('wpc_bulk_recon_last202', $out, false);
        return $out;
    }

    public static function wpc_wire_doctor_report($urlKey, $html)
    {
        $lanes = [];
        $set = get_option(WPS_IC_SETTINGS);
        $set = is_array($set) ? $set : [];
        $cd = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/' : '';

        foreach (['desktop', 'mobile'] as $dv) {
            $crit_head = $cd !== '' ? (string) @file_get_contents($cd . 'critical_' . $dv . '.css', false, null, 0, 700) : '';
            if (!preg_match('/wpc-budget-final:\s*(?:mobile\s+|desktop\s+)?(over|ok)\s+out=(\d+)\s+cap=(\d+)[^*]*/', $crit_head, $cm)) {
                $lanes['crit_' . $dv] = ['verdict' => strlen($crit_head) > 64 ? 'WARN' : 'FAIL',
                    'detail' => strlen($crit_head) > 64 ? 'crit present, no stamp parsed' : 'no crit artifact on disk',
                    'fix' => 'Refresh Auto Mode / wait for land'];
                continue;
            }
            $stamp   = $cm[0];
            $cap     = max(1, (int) $cm[3]);
            $x       = preg_match('/\bx=([0-9.]+)/', $stamp, $xm) ? (float) $xm[1] : ((int) $cm[2] / $cap);
            $wok     = preg_match('/\bwave_ok=([01])\b/', $stamp, $wm) ? (int) $wm[1] : null;
            $newfmt  = strpos($stamp, 'fonts=') !== false;
            $markers = preg_match('/\b(?:still-over|rescope-abort|lossy)\b/', $stamp) === 1;
            $grace   = (float) apply_filters('wpc_forfeit_over_grace', 1.15);
            if ($cm[1] === 'ok') {
                $lossy = !$newfmt && (int) $cm[2] >= (int) floor($cap * (float) apply_filters('wpc_trim_forfeit_ratio', 0.97));
                $why   = $lossy ? 'ok-but-trimmed-to-fit (old format, out>=97% cap)' : 'ok';
            } elseif ($wok === 1) {
                $lossy = false; $why = 'over but WAVE-LICENSED (wave_ok=1) — parks per .172 ladder';
            } elseif ($wok === 0) {
                $lossy = true; $why = 'over, service says NOT wave-servable (wave_ok=0)';
            } else {
                $lossy = !($newfmt && !$markers && $x <= $grace);
                $why   = 'over, no wave token — graded: newfmt=' . (int) $newfmt . ' markers=' . (int) $markers . ' x=' . $x . ' grace=' . $grace;
            }
            $fst = json_decode((string) @file_get_contents($cd . 'forfeit_state_' . $dv . '.json'), true);
            $lanes['crit_' . $dv] = [
                'verdict' => $lossy ? 'FAIL' : 'PASS',
                'detail'  => $cm[1] . ' x=' . $x . ' wave_ok=' . ($wok === null ? 'absent' : $wok)
                    . ' | forfeit-computed=' . ($lossy ? 'STANDDOWN' : 'park') . ' (' . $why . ')'
                    . ' | state=' . (is_array($fst) && isset($fst['mode']) ? (string) $fst['mode'] : 'none'),
            ];
            if ($lossy) {
                $lanes['crit_' . $dv]['fix'] = $wok === 0 ? 'service: wave-license or trim' : 'service trim under cap OR ship wave_ok=1 on this leg';
            }
        }
        if (function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($urlKey)) {
            $lanes['crit_desktop']['detail'] = (isset($lanes['crit_desktop']['detail']) ? $lanes['crit_desktop']['detail'] : '') . ' | BYPASS WINDOW ACTIVE';
        }

        $ucs_on = !empty($set['used-css']) && $set['used-css'] == '1';
        $tpl = $cd !== '' ? trim((string) @file_get_contents($cd . 'used_tpl.txt')) : '';
        
        
        
        
        if ($tpl === '' && function_exists('wpc_dispatch_tpl_key')) {
            $tpl222 = (string) wpc_dispatch_tpl_key($urlKey);
            if ($tpl222 !== '' && function_exists('wpc_used_css_path') && @is_readable(wpc_used_css_path($tpl222))) {
                $tpl = $tpl222;
                if ($cd !== '' && function_exists('wpc_crit_meta_write')) {
                    wpc_crit_meta_write($cd . 'used_tpl.txt', $tpl);
                }
            }
        }
        $ucs_file = ($tpl !== '' && function_exists('wpc_used_css_path')) ? wpc_used_css_path($tpl) : '';
        $crit_on199 = !empty($set['critical']['css']) && $set['critical']['css'] == '1';
        if (!$ucs_on && $crit_on199 && !isset($set['used-css'])) {
            $lanes['used_css'] = ['verdict' => 'FAIL',
                'detail' => 'PILLAR DRIFT: Optimize CSS is ON but the used-css rider is unset (saved pre-.148)',
                'fix' => 'toggle Optimize CSS off/on (rewrites the .825 rider), then Refresh'];
        } elseif (!$ucs_on) {
            $lanes['used_css'] = ['verdict' => 'FAIL', 'detail' => 'setting OFF — originals restore post-paint (~30-40 rows)', 'fix' => 'enable Used CSS (Optimize CSS pillar) + Refresh'];
        } elseif ($ucs_file !== '' && @is_readable($ucs_file)) {
            $lanes['used_css'] = ['verdict' => 'PASS', 'detail' => 'tpl ' . $tpl . ' landed'];
            
            
            
            if (function_exists('wpc_css_host_twin_heal231')) {
                $th232 = 0; $tf232 = 0;
                foreach (['', 'desktop', 'mobile'] as $dv232) {
                    $fp232 = wpc_used_css_path($tpl, $dv232);
                    if ($fp232 === '' || !@is_readable($fp232)) { continue; }
                    $cb232 = (string) @file_get_contents($fp232);
                    if ($cb232 === '') { continue; }
                    $hb232 = wpc_css_host_twin_heal231($cb232);
                    if (function_exists('wpc_used_css_strip_faces245')) { $hb232 = wpc_used_css_strip_faces245($hb232); }
                    if (function_exists('wpc_used_css_bg_park255')) { $hb232 = wpc_used_css_bg_park255($hb232); }
                    if (is_string($hb232) && $hb232 !== $cb232
                        && @file_put_contents($fp232 . '.tmp', $hb232, LOCK_EX) !== false
                        && @rename($fp232 . '.tmp', $fp232)) {
                        $tf232++;
                        $th232 += substr_count($cb232, '://') - substr_count($hb232, '://');
                    }
                }
                if ($tf232 > 0) {
                    $lanes['used_css']['twin_heal'] = 'healed ' . $tf232 . ' stored file(s) - purge HTML cache so the mint links the new ?uv=';
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('doctor-twin-healed', (string) $urlKey, '', ['files' => $tf232, 'tpl' => $tpl]);
                    }
                } else {
                    $fr232 = 0;
                    foreach (['', 'desktop', 'mobile'] as $dv232) {
                        $fp232 = wpc_used_css_path($tpl, $dv232);
                        if ($fp232 !== '' && @is_readable($fp232)) {
                            $fb232 = (string) @file_get_contents($fp232);
                            if (preg_match_all('#url\(\s*["\']?https?://([^/"\')\s]+)/wp-content/#i', $fb232, $fm232)) {
                                foreach ($fm232[1] as $fh232) {
                                    if (strcasecmp($fh232, (string) parse_url(home_url('/'), PHP_URL_HOST)) !== 0) { $fr232++; }
                                }
                            }
                        }
                    }
                    $lanes['used_css']['twin_heal'] = $fr232 > 0
                        ? 'FOREIGN RESIDUE: ' . $fr232 . ' foreign-host urls remain (no local twin or serve-on)'
                        : 'clean (no foreign-host urls in stored css)';
                }
            }
            if (function_exists('wpc_used_css_sheets_land') && function_exists('wpc_used_css_load_sheets')) {
                if (!wpc_used_css_load_sheets($tpl)) {
                    $lanes['used_css']['sheets'] = wpc_used_css_sheets_land($cd, $tpl) ? 'landed-now (droplist arms on next mint - purge HTML cache)' : 'NO MANIFEST (originals will restore; droplist inert)';
                } else {
                    $sh225 = wpc_used_css_load_sheets($tpl);
                    $lanes['used_css']['sheets'] = count($sh225) < 3
                        ? 'THIN manifest (n=' . count($sh225) . ') - service sheets enumeration incomplete; droplist cannot drop; combine lane covers the restore'
                        : 'present (droplist active, n=' . count($sh225) . ')';
                }
            }
        } else {
            $lanes['used_css'] = ['verdict' => 'PENDING', 'detail' => 'setting on, no template landed (tpl=' . ($tpl === '' ? 'none' : $tpl) . ')',
                'fix' => 'pointer artifacts{} consume lands it on the next tick; check log pointer-usedcss-landed / pointer-artifacts-unmatched'];
            
            
            if (defined('WPS_IC_CRITICAL_API_URL') && function_exists('home_url')) {
                $opt217 = (array) get_option(defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic');
                if (!empty($opt217['api_key'])) {
                    $lr217 = wp_remote_get(str_replace('/generate', '/v2/latest', WPS_IC_CRITICAL_API_URL)
                        . '?url=' . urlencode(home_url('/')) . '&apikey=' . urlencode((string) $opt217['api_key']), ['timeout' => 6]);
                    $lc217 = !is_wp_error($lr217) ? (int) wp_remote_retrieve_response_code($lr217) : 0;
                    $ld217 = $lc217 === 200 ? json_decode((string) wp_remote_retrieve_body($lr217), true) : null;
                    if (is_array($ld217)) {
                        $a217 = isset($ld217['artifacts']) && is_array($ld217['artifacts']) ? $ld217['artifacts'] : [];
                        $lanes['used_css']['latest_probe'] = [
                            'ready'          => (int) !empty($ld217['ready']),
                            'artifact_keys'  => implode(',', array_slice(array_keys($a217), 0, 14)),
                            'used_css_url'   => !empty($a217['used_css']) ? 'present' : 'ABSENT',
                            'tpl_derivable'  => (!empty($a217['tpl_key']) || !empty($ld217['tpl_key'])
                                || (!empty($a217['used_css_mobile']) && strpos((string) $a217['used_css_mobile'], 'tpl-') !== false)) ? 'yes'
                                : ((function_exists('wpc_dispatch_tpl_key') && wpc_dispatch_tpl_key($urlKey) !== '') ? 'local-tpl' : 'NO-and-no-local-tpl-yet'),
                        ];
                        
                        
                        
                        
                        $du220 = '';
                        foreach ([$a217, $ld217] as $ds220) {
                            foreach (['used_css_url', 'used_css', 'usedCss'] as $dk220) {
                                if ($du220 === '' && !empty($ds220[$dk220]) && is_string($ds220[$dk220])
                                    && strpos($ds220[$dk220], 'http') === 0) {
                                    $du220 = trim($ds220[$dk220]);
                                }
                            }
                        }
                        $dt220 = function_exists('wpc_dispatch_tpl_key') ? (string) wpc_dispatch_tpl_key($urlKey) : '';
                        if ($du220 !== '' && $dt220 !== '' && function_exists('wpc_used_css_fetch')
                            && function_exists('wpc_used_css_path') && !@is_readable(wpc_used_css_path($dt220))) {
                            if (function_exists('wpc_crit_meta_write') && $cd !== '') {
                                wpc_crit_meta_write($cd . 'used_css_url.txt', $du220);
                                wpc_crit_meta_write($cd . 'used_tpl.txt', $dt220);
                            }
                            $dw221 = '';
                            $dl220 = wpc_used_css_fetch($du220, $dt220, '', $dw221);
                            if ($dl220 && function_exists('wpc_used_css_scoped_purge')) { wpc_used_css_scoped_purge($dt220); }
                            if ($dl220 && function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('doctor-usedcss-landed', (string) $urlKey, '', ['tpl' => $dt220]);
                            }
                            if ($dl220 && function_exists('wpc_used_css_sheets_land')) {
                                $dsu223 = '';
                                foreach ([$a217, $ld217] as $ds223) {
                                    if ($dsu223 === '' && !empty($ds223['used_css_sheets']) && is_string($ds223['used_css_sheets'])
                                        && strpos($ds223['used_css_sheets'], 'http') === 0) {
                                        $dsu223 = trim($ds223['used_css_sheets']);
                                    }
                                }
                                $lanes['used_css']['latest_probe']['sheets'] = wpc_used_css_sheets_land($cd, $dt220, $dsu223) ? 'landed' : 'no-manifest';
                            }
                            $lanes['used_css']['latest_probe']['landed_now'] = $dl220 ? 'yes'
                                : 'fetch-failed:' . ($dw221 !== '' ? $dw221 : 'unknown') . ' url=' . substr($du220, 0, 120);
                            if ($dl220) {
                                $lanes['used_css']['verdict'] = 'PASS';
                                $lanes['used_css']['detail']  = 'landed by doctor from /v2/latest (tpl ' . $dt220 . ')';
                                $lanes['used_css']['fix']     = '';
                            }
                        }
                    } else {
                        $lanes['used_css']['latest_probe'] = ['http' => $lc217, 'body' => 'not-json-or-error'];
                    }
                }
            }
        }

        
        
        
        try {
            $wpc_lf278 = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
            if ($wpc_lf278 !== '' && @is_readable($wpc_lf278)) {
                $wpc_sz278 = (int) @filesize($wpc_lf278);
                $wpc_fh278 = @fopen($wpc_lf278, 'r');
                $wpc_rows278 = [];
                if ($wpc_fh278) {
                    if ($wpc_sz278 > 65536) { @fseek($wpc_fh278, -65536, SEEK_END); @fgets($wpc_fh278); }
                    while (($wpc_ln278 = fgets($wpc_fh278)) !== false) {
                        if (strpos($wpc_ln278, 'critcombine-receipt') === false
                            && strpos($wpc_ln278, 'crit-sanity-') === false
                            && strpos($wpc_ln278, 'crit-blind-served') === false) { continue; }
                        $wpc_j278 = json_decode(trim($wpc_ln278), true);
                        if (is_array($wpc_j278)) {
                            $wpc_rows278[] = gmdate('H:i:s', (int) ($wpc_j278['t'] ?? 0)) . 'Z '
                                . json_encode($wpc_j278['layers'] ?? []);
                        }
                    }
                    @fclose($wpc_fh278);
                }
                if (!empty($wpc_rows278)) {
                    $lanes['critcombine'] = ['verdict' => 'INFO', 'receipts' => array_slice($wpc_rows278, -6)];
                }
            }
        } catch (\Throwable $e) {
        }

        $wpc_nl = !empty($set['nativeLazy']) && $set['nativeLazy'] == '1';
        $wpc_vl = !empty($set['lazy']) && $set['lazy'] == '1';
        $lanes['images_lazy'] = [
            'verdict' => ($wpc_nl || $wpc_vl) ? 'PASS' : 'FAIL',
            'detail'  => 'nativeLazy=' . (int) $wpc_nl . ' viewportLazy=' . (int) $wpc_vl
                . ($wpc_nl && !$wpc_vl ? ' — NOTE: native lazy still fetches near-viewport images in lab runs (huge Chrome threshold); Viewport mode parks BTF for the clean wire' : ''),
            'fix' => ($wpc_nl || $wpc_vl) ? '' : 'enable Native Lazy or Lazy by Viewport',
        ];

        $delay_on = class_exists('wps_ic_js_delay_v3') && is_callable(['wps_ic_js_delay_v3', 'wpc_delay_master_on'])
            ? (bool) wps_ic_js_delay_v3::wpc_delay_master_on($set) : false;
        $lanes['js_delay'] = ['verdict' => $delay_on ? 'PASS' : 'FAIL', 'detail' => $delay_on ? 'delay master on' : 'delay OFF'];

        
        
        
        
        try {
            $cdn226 = [];
            foreach (['lazy', 'live-cdn', 'css', 'js', 'fonts'] as $ck226) {
                $cdn226[$ck226] = isset($set[$ck226]) ? (string) $set[$ck226] : 'unset';
            }
            foreach (['jpg', 'png', 'gif', 'svg'] as $sk226) {
                $cdn226['serve.' . $sk226] = isset($set['serve'][$sk226]) ? (string) $set['serve'][$sk226] : 'unset';
            }
            $cdn226['ic_custom_cname'] = (string) get_option('ic_custom_cname', '');
            $cdn226['ic_cdn_zone_name'] = (string) get_option('ic_cdn_zone_name', '');
            $cdn226['suppressed'] = (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) ? 1 : 0;
            $mintApi226 = '';
            if (is_string($html) && $html !== '' && preg_match('/"api_url":"([^"]+)"/', $html, $am226)) {
                $mintApi226 = stripslashes($am226[1]);
            }
            $cdn226['mint_api_url'] = $mintApi226 !== '' ? $mintApi226 : 'absent';
            $servesOn226 = 0;
            foreach (['jpg', 'png', 'gif', 'svg'] as $sk226) {
                if (isset($set['serve'][$sk226]) && $set['serve'][$sk226] == '1') { $servesOn226++; }
            }
            $leak226 = ($cdn226['suppressed'] === 1 || $servesOn226 === 0) && $mintApi226 !== '';
            $lanes['cdn'] = [
                'verdict' => $leak226 ? 'FAIL' : 'INFO',
                'detail'  => ($leak226 ? 'CDN RESURRECTION: zone emitted while CDN is off - lazy runtime re-fetches images through the zone (dup downloads). ' : '')
                    . 'state: ' . json_encode($cdn226),
            ];
            if ($leak226) { $lanes['cdn']['fix'] = 'strip api_url when suppressed/all-serve-off (plugin) + purge; verify which key still reads on'; }
        } catch (\Throwable $e) {
        }
        if (is_string($html) && $html !== '') {
            if (preg_match('/delay-v3-loader-([0-9.]+)\.min\.js/', $html, $vm)) {
                $cur = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '';
                $lanes['cache_freshness'] = $vm[1] === $cur
                    ? ['verdict' => 'PASS', 'detail' => 'mint carries current loader ' . $vm[1]]
                    : ['verdict' => 'FAIL', 'detail' => 'STALE MINT: cached page carries loader ' . $vm[1] . ' vs installed ' . $cur, 'fix' => 'purge HTML cache'];
            } else {
                $lanes['cache_freshness'] = ['verdict' => 'WARN', 'detail' => 'no loader in cached copy'];
            }
            if (strpos($html, 'KGZ1bmN0aW9uKCl7dmFyIG5kPWZhbHNl') !== false) {
                $lanes['frozen_backstop'] = ['verdict' => 'FAIL',
                    'detail' => 'delay registry carries a FROZEN font backstop (pre-nodefer mint) — replays old font code every view',
                    'fix' => 'purge HTML cache'];
            } elseif (strpos($html, 'id="wpc-lf-flip924"') !== false
                && strpos($html, 'data-nodefer="1" id="wpc-lf-flip924"') === false) {
                $lanes['frozen_backstop'] = ['verdict' => 'FAIL', 'detail' => 'backstop tag without data-nodefer (pre-.212 mint)', 'fix' => 'purge HTML cache'];
            } else {
                $lanes['frozen_backstop'] = ['verdict' => 'PASS', 'detail' => 'no swallowed font scripts'];
            }
            if (preg_match('/wpcDelayV3Cfg=\{"timeout":(\d+),"aggr":(\d+)/', $html, $tm)) {
                $lanes['js_delay']['detail'] .= $tm[1] === '0'
                    ? ' | interaction-only (lab-clean)' : ' | timed fallback ' . $tm[1] . 's (unmeasured page)';
            }
            
            
            
            
            
            if (preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $wpc_sm262)) {
                $wpc_eag262 = [];
                foreach ($wpc_sm262[0] as $wpc_i262 => $wpc_st262) {
                    if (stripos($wpc_st262, 'wpc-delayed') !== false || stripos($wpc_st262, 'text/wpc') !== false) { continue; }
                    
                    
                    if (stripos($wpc_sm262[1][$wpc_i262], 'data:') === 0) { continue; }
                    $wpc_bn262 = basename((string) parse_url($wpc_sm262[1][$wpc_i262], PHP_URL_PATH));
                    if ($wpc_bn262 === '' || stripos($wpc_bn262, 'delay-v3-loader') !== false
                        || stripos($wpc_bn262, 'local-lazy') !== false) { continue; }
                    $wpc_eag262[] = $wpc_bn262;
                }
                if (!empty($wpc_eag262)) {
                    $lanes['js_delay']['eager_js'] = count($wpc_eag262) . ' eager: '
                        . implode(',', array_slice($wpc_eag262, 0, 14))
                        . (count($wpc_eag262) > 14 ? ',+' . (count($wpc_eag262) - 14) : '');
                    if (count($wpc_eag262) > 6) {
                        $lanes['js_delay']['fix'] = 'keep closure is broad - name the member that NEEDS eager and cut the rest';
                    }
                }
            }
            $wpc_imt227 = substr_count($html, '<img');
            $wpc_imp227 = substr_count($html, 'src="data:image/svg+xml');
            $lanes['images_lazy']['detail'] .= ' | mint imgs: ' . $wpc_imt227 . ', true-parked: ' . $wpc_imp227
                . ($wpc_imp227 === 0 && $wpc_imt227 > 12 ? ' (NOT PARKED - every src fetches in lab)' : '');
            $lanes['fonts'] = [
                'verdict' => 'INFO',
                'detail' => '@font-face in mint: ' . substr_count($html, '@font-face')
                    . ' | inline data: subsets: ' . substr_count($html, 'url(data:font')
                    . ' | late-faces: ' . (strpos($html, 'id="wpc-late-faces"') !== false ? 'present' : 'absent'),
            ];
        } else {
            
            
            $wpc_cw287 = [];
            $wpc_set287 = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
            
            
            $wpc_cw287[] = 'setting=' . ((is_array($wpc_set287) && !empty($wpc_set287['cache']['advanced']) && $wpc_set287['cache']['advanced'] == '1') ? 'on' : 'OFF');
            $wpc_cw287[] = 'wp_cache=' . ((defined('WP_CACHE') && WP_CACHE) ? '1' : '0');
            $wpc_ac287 = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/advanced-cache.php' : '';
            $wpc_cw287[] = 'dropin=' . (($wpc_ac287 !== '' && @is_file($wpc_ac287))
                ? ((strpos((string) @file_get_contents($wpc_ac287), 'wpc') !== false || strpos((string) @file_get_contents($wpc_ac287), 'WPC') !== false) ? 'ours' : 'FOREIGN')
                : 'MISSING');
            if (function_exists('wpc_cache_dir_for')) {
                $wpc_cd287 = (string) wpc_cache_dir_for($urlKey);
                $wpc_cw287[] = 'dir=' . ($wpc_cd287 !== '' && is_dir($wpc_cd287) ? (is_writable($wpc_cd287) ? 'writable' : 'READONLY') : 'absent');
            } elseif (defined('WPS_IC_CACHE')) {
                $wpc_cw287[] = 'dir=' . (is_dir(WPS_IC_CACHE) ? (is_writable(WPS_IC_CACHE) ? 'writable' : 'READONLY') : 'ABSENT');
            }
            
            
            
            $wpc_lw288 = function_exists('get_option') ? get_option('wpc_cache_lastwrite288') : null;
            $wpc_cw287[] = 'lastwrite=' . (is_array($wpc_lw288)
                ? ((time() - (int) $wpc_lw288['t']) . 's-ago ' . $wpc_lw288['variant'] . ' ' . $wpc_lw288['bytes'] . 'b [' . $wpc_lw288['key'] . ']')
                : 'NEVER');
            if (defined('WPS_IC_CACHE') && !empty($urlKey)) {
                foreach (['index.html', 'index.html_gzip', 'mobile_index.html', 'mobile_index.html_gzip'] as $wpc_pv288) {
                    $wpc_fp288 = WPS_IC_CACHE . $urlKey . '/' . $wpc_pv288;
                    $wpc_cw287[] = $wpc_pv288 . '=' . (@is_file($wpc_fp288)
                        ? ((int) @filesize($wpc_fp288) . 'b/' . (time() - (int) @filemtime($wpc_fp288)) . 's')
                        : 'ABSENT');
                }
                $wpc_cw287[] = 'key=' . $urlKey;
            }
            if ($wpc_ac287 !== '' && @is_file($wpc_ac287)
                && preg_match('/Version:\s*([0-9.]+)/', (string) @file_get_contents($wpc_ac287), $wpc_avm288)) {
                $wpc_cw287[] = 'dropin_v=' . $wpc_avm288[1];
            }
            $lanes['cache_freshness'] = ['verdict' => 'WARN',
                'detail' => 'no cached copy found (uncached or purged) | ' . implode(' ', $wpc_cw287)];
        }
        
        
        
        
        try {
            $rh238 = [];
            $rh238['under_pressure'] = (function_exists('wpc_under_pressure') && wpc_under_pressure()) ? 1 : 0;
            $rh238['loadavg'] = function_exists('sys_getloadavg') ? implode(',', array_map(function ($v) { return round($v, 2); }, (array) sys_getloadavg())) : 'n/a';
            $rh238['update_window_until'] = (int) get_option('wpc_update_window_until', 0) - time();
            $rh238['crit_bypass'] = (function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($urlKey)) ? 1 : 0;
            $rh238['kick_dead'] = (function_exists('wpc_kick_is_dead') && wpc_kick_is_dead($urlKey)) ? 1 : 0;
            $rh238['kick_budget_ok'] = (function_exists('wpc_kick_budget_ok') && !wpc_kick_budget_ok()) ? 0 : 1;
            foreach (['upgrade', 'all'] as $sk238) {
                $sv238 = (int) get_transient('wpc_crit_stale_' . md5($sk238));
                if ($sv238 > 0) { $rh238['stale_mark_' . $sk238] = (time() - $sv238) . 's ago'; }
            }
            if ($cd !== '') {
                foreach (['critical_desktop.css', 'critical_mobile.css', 'tpl.txt'] as $cf238) {
                    $rh238[$cf238] = @is_readable($cd . $cf238)
                        ? (int) @filesize($cd . $cf238) . 'b/' . (time() - (int) @filemtime($cd . $cf238)) . 's'
                        : 'ABSENT';
                }
            }
            
            
            
            
            $rh238['combined_on'] = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on'))
                ? (int) (bool) wps_rewriteLogic::wpc_combined_crit_on() : -1;
            $rh238['cf_fronted_seen'] = (int) !empty(get_option('wpc_cf_fronted_seen', 0));
            $dk242 = get_option('wpc_cf_devkey_verified');
            $rh238['devkey'] = is_array($dk242)
                ? (!empty($dk242['devkey']) ? 'yes/src=' . (isset($dk242['src']) ? (string) $dk242['src'] : '') : 'no')
                : 'absent';
            $rh238['combined_artifact'] = ($cd !== '' && @is_readable($cd . 'critical_combined.css'))
                ? (int) @filesize($cd . 'critical_combined.css') . 'b' : 'ABSENT';
            if (is_string($html) && $html !== '' && preg_match('/id="wpc-critical-css" class="([^"]+)"/', $html, $cc242)) {
                $rh238['mint_crit_class'] = $cc242[1];
            }
            if ($rh238['cf_fronted_seen'] === 1 && $rh238['devkey'] !== 'yes/src=readback'
                && $rh238['combined_on'] !== 1) {
                $rh238['VERDICT'] = 'DEVICE-SPLIT ON A DEVICE-BLIND EDGE - floor failed to hold';
            }
            $lanes['render_health'] = [
                'verdict' => ($rh238['under_pressure'] || $rh238['crit_bypass']) ? 'WARN' : 'INFO',
                'detail'  => json_encode($rh238),
            ];
        } catch (\Throwable $e) {
        }
        return $lanes;
    }

    public function wpc_wire_doctor()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden');
        }
        $urlKey = class_exists('wps_ic_url_key') ? (string) (new wps_ic_url_key())->setup(home_url('/')) : '';
        if (!empty($_GET['url']) && class_exists('wps_ic_url_key')) {
            $urlKey = (string) (new wps_ic_url_key())->setup(esc_url_raw((string) $_GET['url']));
        }
        $html = '';
        if (defined('WPS_IC_CACHE')) {
            foreach ([WPS_IC_CACHE . $urlKey . '/index.html', WPS_IC_CACHE . $urlKey . '/mobile_index.html'] as $f) {
                if (@is_readable($f)) { $html = (string) @file_get_contents($f); break; }
            }
            if ($html === '' && @is_readable(WPS_IC_CACHE . $urlKey . '/index.html_gzip') && function_exists('gzdecode')) {
                $html = (string) @gzdecode((string) @file_get_contents(WPS_IC_CACHE . $urlKey . '/index.html_gzip'));
            }
        }
        wp_send_json_success([
            'url_key' => $urlKey,
            'version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'lanes'   => self::wpc_wire_doctor_report($urlKey, $html),
        ]);
    }

    public function wpc_bulk_ledger_dump()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden');
        }
        wp_send_json_success([
            'ledger'    => self::wpc_bulk_ledger_get202(),
            'summary'   => self::wpc_bulk_ledger_summary202(),
            'reconcile' => get_option('wpc_bulk_recon_last202'),
            'inflight'  => self::wpc_bulk_inflight199(),
            'counted'   => get_option('wpc_bulk_counted201'),
        ]);
    }

    public static function wpc_bulk_status_count201()
    {
        $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
        if (!is_array($session_ids) || empty($session_ids)) {
            return;
        }
        $counted = get_option('wpc_bulk_counted201');
        if (!is_array($counted)) {
            $counted = [];
        }
        $bs = get_option('wps_ic_BulkStatus') ?: [];
        $new = 0;
        foreach (array_values(array_unique(array_map('intval', $session_ids))) as $id) {
            if ($id <= 0 || isset($counted[$id])) {
                continue;
            }
            wp_cache_delete($id, 'post_meta');
            if (get_post_meta($id, 'ic_status', true) !== 'compressed') {
                continue;
            }
            $counted[$id] = 1;
            $new++;
            self::wpc_bulk_ledger_mark202($id, 'verified');
            $bs['compressedImageCount'] = ($bs['compressedImageCount'] ?? 0) + 1;
            $variants = get_post_meta($id, 'ic_local_variants', true);
            if (is_array($variants)) {
                $bs['compressedThumbs'] = ($bs['compressedThumbs'] ?? 0) + count($variants);
                foreach ($variants as $v) {
                    if (!is_array($v)) continue;
                    $bs['total']['original']['size']   = ($bs['total']['original']['size'] ?? 0) + (int) ($v['originalSize'] ?? 0);
                    $bs['total']['compressed']['size'] = ($bs['total']['compressed']['size'] ?? 0) + (int) ($v['size'] ?? 0);
                }
            }
        }
        if ($new > 0) {
            update_option('wpc_bulk_counted201', $counted, false);
            update_option('wps_ic_BulkStatus', $bs);
            delete_transient('wpc_bulk_library_counts');
        }
    }

    public static function wpc_bulk_pipeline_drain199()
    {
        if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
            return false;
        }
        if (function_exists('wpc_v2_pull_manifest_tick')) {
            $wpc_wait199 = max(0, (int) apply_filters('wpc_bulk_longpoll_ms', 25000));
            wpc_v2_pull_manifest_tick(100, $wpc_wait199);
        }
        if (function_exists('wpc_v2_journal_drain_run')) {
            try { wpc_v2_journal_drain_run(); } catch (\Throwable $e) {}
        }
        self::wpc_bulk_status_count201();
        return true;
    }

    public static function wpc_bulk_v2_dispatch_batch(array $preps)
    {
        $wpc_sent309 = [];
        if (empty($preps)) return $wpc_sent309;

        
        if (function_exists('curl_multi_init') && function_exists('curl_multi_exec') && count($preps) > 1) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($preps as $id => $p) {
                $env = $p['client']->build_envelope($id, $p['variants'], $p['options']);
                if (empty($env['ok'])) {
                    error_log('[WPC Bulk] envelope_build_failed image=' . $id . ' — ' . ($env['error'] ?? 'unknown'));
                    continue;
                }
                $hdrs = [];
                foreach ($env['headers'] as $k => $v) {
                    $hdrs[] = $k . ': ' . $v;
                }
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $env['url'],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $env['body_json'],
                    CURLOPT_HTTPHEADER     => $hdrs,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT      => 'WPCompress/7.02 bulk-multi',
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = ['ch' => $ch, 'client' => $p['client'], 't0' => $p['t0']];
            }

            
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.5);
            } while ($running > 0);

            
            foreach ($handles as $id => $h) {
                $body_raw  = curl_multi_getcontent($h['ch']);
                $http_code = (int) curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
                $wall_ms   = (int) round((microtime(true) - $h['t0']) * 1000);
                $result    = $h['client']->process_response($id, $http_code, $body_raw);
                if (empty($result['ok'])) {
                    error_log(sprintf('[WPC Bulk] FAILED image=%d wall=%dms err=%s',
                        $id, $wall_ms, $result['error'] ?? 'unknown'));
                } else {
                    $wpc_sent309[] = (int) $id;
                }
                curl_multi_remove_handle($mh, $h['ch']);
                curl_close($h['ch']);
            }
            curl_multi_close($mh);
            return $wpc_sent309;
        }

        
        foreach ($preps as $id => $p) {
            $result = $p['client']->optimize($id, $p['variants'], $p['options']);
            if (empty($result['ok'])) {
                error_log('[WPC Bulk] FAILED image=' . $id . ' — ' . ($result['error'] ?? 'unknown'));
            } else {
                $wpc_sent309[] = (int) $id;
            }
        }
        return $wpc_sent309;
    }

    




    public static function wpc_bulk_v2_restore_fire_loopback()
    {
        
        $q = get_transient('wps_ic_restore_queue');
        if (empty($q['queue'])) { return false; }
        
        
        if (($lock_ts = (int) get_transient('wpc_bulk_restore_draining')) > 0) {
            set_transient('wpc_bulk_restore_redrain_pending', time(), 60);
            return false;
        }
        set_transient('wpc_bulk_restore_draining', time(), 15);
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($apikey === '') { delete_transient('wpc_bulk_restore_draining'); return false; }
        $ts  = time();
        $sig = hash_hmac('sha256', 'wpc_bulk_restore_drain.' . $ts, $apikey); 
        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);                                           
        if (empty($parts['host'])) { delete_transient('wpc_bulk_restore_draining'); return false; }
        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        $port = $is_https ? 443 : 80;
        $host = (string) $parts['host'];
        $path = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_bulk_v2_restore_drain_loop';
        $body = http_build_query(['t' => $ts, 'sig' => $sig]);
        $req  = "POST {$path} HTTP/1.1\r\nHost: {$host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($body) . "\r\nConnection: close\r\nUser-Agent: WPCBulkRestoreDrain/1.0\r\n\r\n" . $body;


        $fp = self::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
        if (!$fp) { delete_transient('wpc_bulk_restore_draining'); error_log('[WPC BulkRestoreDrain] loopback_connect_failed host=' . $host . ' port=' . $port); return false; }
        @stream_set_timeout($fp, 0, 100000); @fwrite($fp, $req); @fclose($fp);
        delete_transient('wpc_bulk_restore_redrain_pending');
        return true;
    }


    public function wpc_bulk_v2_restore_drain()
    {
        
        if (!current_user_can('manage_wpc_settings')) {
            wp_die('', '', ['response' => 403]);
        }
        $this->run_restore_drain_slice();
        wp_die('', '', ['response' => 200]);
    }

    




    public function wpc_bulk_v2_restore_drain_loop()
    {
        
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $ts     = isset($_POST['t']) ? (int) $_POST['t'] : 0;
        $sig    = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
        if ($apikey === '' || $ts <= 0 || $sig === '' || abs(time() - $ts) > 60) { http_response_code(401); exit('auth'); }
        $expected = hash_hmac('sha256', 'wpc_bulk_restore_drain.' . $ts, $apikey); 
        if (!hash_equals($expected, $sig)) { http_response_code(401); exit('sig'); }
        
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @ignore_user_abort(true);
        @set_time_limit(60); 
        $this->run_restore_drain_slice();
        exit;
    }

    private function run_restore_drain_slice()
    {
        delete_transient('wpc_bulk_restore_draining');
        if (!get_option('wps_ic_bulk_process')) {
            return;
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '60');
        @ignore_user_abort(true);

        global $wpdb;
        $got = wpc_worker_lock('wpc_bulk_v2_restore_chain', 0) ? 1 : 0;
        if (!$got) {
            return;
        }

        try {
            $started = microtime(true);
            
            
            $wall_budget_s = 8.0;
            $compress = new wps_local_compress();
            $processed = 0;

            while ((microtime(true) - $started) < $wall_budget_s) {
                
                if (get_transient('wpc_bulk_stop_signal') || !get_option('wps_ic_bulk_process')) {
                    error_log('[WPC Bulk Restore] drain paused mid-iteration');
                    break;
                }
                
                if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }
                
                
                $mem_limit = wp_convert_hr_to_bytes((string) @ini_get('memory_limit'));
                if ($mem_limit > 0 && memory_get_usage(true) > (int) ($mem_limit * 0.8)) {
                    error_log('[WPC Bulk Restore] drain slice mem-cap break usage=' . memory_get_usage(true) . ' limit=' . $mem_limit);
                    break;
                }
                $queue = get_transient('wps_ic_restore_queue');
                if (empty($queue['queue'])) {
                    
                    delete_option('wps_ic_bulk_process');
                    delete_transient('wps_ic_bulk_running');
                    delete_transient('wpc_bulk_library_counts');
                    delete_transient('wps_ic_restore_queue');
                    set_transient('wps_ic_bulk_done', true, 60);


                    if (function_exists('wpc_purge_compat')) {
                        
                        


                        
                        try {
                            $purge_res = wpc_purge_compat('all', [], 'restore_all', '', true);
                            $purge_ok  = is_array($purge_res) && !empty($purge_res['ok']);
                            error_log('[WPC Purge] restore_all (bulk drain) ok=' . ($purge_ok ? '1' : '0')
                                . ' result=' . (is_array($purge_res) ? wp_json_encode($purge_res) : 'n/a'));
                        } catch (\Throwable $e) {
                            error_log('[WPC Purge] restore_all purge error: ' . $e->getMessage());
                        }
                    }
                    break;
                }

                $imageID = (int) $queue['queue'][0];
                $queue['queue'] = array_values(array_slice($queue['queue'], 1));
                set_transient('wps_ic_restore_queue', $queue, 2 * HOUR_IN_SECONDS);


                update_option('wpc_bulk_restore_last_tick', time(), false);
                try {
                    $compress->restoreV4($imageID);
                } catch (\Throwable $e) {
                    error_log('[WPC Bulk Restore] image=' . $imageID . ' threw (' . $e->getMessage() . ') — skipped, chain continues');
                }


                $parsed = get_option('wps_ic_parsed_images') ?: [];
                $orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : get_attached_file($imageID);
                $orig_bytes = ($orig_path && file_exists($orig_path)) ? (int) @filesize($orig_path) : 0;
                $parsed[$imageID] = [
                    'restored_at' => time(),
                    'bytes'       => $orig_bytes,
                ];
                update_option('wps_ic_parsed_images', $parsed, false);

                
                $bulkStatus = get_option('wps_ic_BulkStatus') ?: [];
                $bulkStatus['restoredImageCount'] = ($bulkStatus['restoredImageCount'] ?? 0) + 1;
                update_option('wps_ic_BulkStatus', $bulkStatus);

                $processed++;


                $sleep_ms = (int) apply_filters('wpc_bulk_restore_iteration_sleep_ms', 1250);
                $sleep_ms = max(0, min(5000, $sleep_ms));
                if ($sleep_ms > 0) {
                    usleep($sleep_ms * 1000);
                }
            }
        } finally {
            wpc_worker_unlock('wpc_bulk_v2_restore_chain');
        }

        
        
        $queue   = get_transient('wps_ic_restore_queue');
        $redrain = (int) get_transient('wpc_bulk_restore_redrain_pending') > 0;
        if ($redrain) { delete_transient('wpc_bulk_restore_redrain_pending'); }
        if (!empty($queue['queue']) || $redrain) {
            self::wpc_bulk_v2_restore_fire_loopback();
        }

        error_log(sprintf('[WPC Bulk Restore] drain slice complete processed=%d wall=%.2fs',
            $processed, microtime(true) - $started));
    }

    




    public function wps_ic_bulkCompressCleanup()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('forbidden');
        }


        $session_snapshot = get_transient('wpc_bulk_session_ids') ?: [];
        delete_option('wps_ic_bulk_process');
        delete_transient('wps_ic_bulk_running');
        delete_transient('wpc_bulk_session_ids');
        delete_transient('wps_ic_bulk_done');
        delete_transient('wpc_bulk_library_counts');
        
        
        foreach ($session_snapshot as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            delete_transient('wpc_v2_announced_'       . $id);  
            delete_transient('wpc_v2_bg_retry_fired_'  . $id);  
            delete_transient('wpc_v2_phase_a_done_'    . $id);  
            delete_transient('wpc_v2_pending_'         . $id);  
            delete_transient('wps_ic_compress_'        . $id);
            delete_transient('wps_ic_heartbeat_'       . $id);  
            delete_transient('wpc_v2_t0_ms_'           . $id);  
            delete_transient('wpc_v2_warming_'         . $id);  
            delete_transient('wpc_v2_callbacks_blocked_' . $id);
        }
        wp_send_json_success(['ok' => true, 'cleaned' => count($session_snapshot)]);
    }


    public function wps_ic_bulkRestoreCleanup()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('forbidden');
        }


        $restore_queue   = get_transient('wps_ic_restore_queue') ?: [];
        $restore_ids     = is_array($restore_queue) ? ($restore_queue['queue'] ?? []) : [];
        delete_option('wps_ic_bulk_process');
        delete_option('wps_ic_parsed_images');
        delete_option('wpc_bulk_restore_started_ms');
        delete_option('wpc_bulk_restore_done_ms');
        delete_transient('wps_ic_bulk_done');
        delete_transient('wps_ic_restore_queue');
        delete_transient('wpc_bulk_library_counts');
        foreach ($restore_ids as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            delete_transient('wpc_v2_announced_'         . $id);
            delete_transient('wpc_v2_callbacks_blocked_' . $id);
            delete_transient('wps_ic_heartbeat_'         . $id);  
        }
        wp_send_json_success(['ok' => true, 'cleaned' => count($restore_ids)]);
    }

    








    private function agencyCnameRelay($method, $cname = '')
    {
        global $api;

        $apikey = sanitize_text_field($_POST['apikey'] ?? '');
        if (empty($apikey)) {
            wp_send_json_error(['msg' => 'No site selected for this action.']);
        }
        if (empty($api) || empty($api::$comms) || !method_exists($api::$comms, $method)) {
            wp_send_json_error(['msg' => 'Agency comms unavailable — update the agency API plugin.']);
        }

        if ($method === 'cnameRemove') {
            $api::$comms->cnameRemove($apikey);
        } else {
            $api::$comms->$method($apikey, $cname);
        }

        
        wp_send_json_error(['msg' => 'The site did not answer.']);
    }

    public function wps_ic_remove_cname()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings'))
            || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if ($this->isAgencyPortal()) {
            $this->agencyCnameRelay('cnameRemove');
        }

        $cname_class = new wps_ic_cname();
        $cname_class->remove();
    }

    public function wps_ic_cname_retry()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings'))
            || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if ($this->isAgencyPortal()) {
            
            
            $this->agencyCnameRelay('cnameRetry', sanitize_text_field($_POST['cname'] ?? ''));
        }

        $cname_class = new wps_ic_cname();
        $cname_class->retry();
    }

    public function wps_ic_remove_key()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $cache = new wps_ic_cache_integrations();
        $cache->remove_key();

        wp_send_json_success();
    }

    public function wpc_ic_set_mode()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = new wps_ic_options();
        $preset = sanitize_text_field($_POST['value']);
        $configuration = $options->get_preset($preset);
        if (function_exists('wpc_preset_cache_gate67')) {
            $configuration = wpc_preset_cache_gate67($configuration);
        }
        update_option(WPS_IC_SETTINGS, $configuration);
        wp_send_json_success($configuration);
    }

    public function wpc_ic_ajax_set_preset()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = new wps_ic_options();
        $preset = sanitize_text_field($_POST['value']);
        $configuration = $options->get_preset($preset);
        wp_send_json_success($configuration);
    }

    public function wps_ic_cname_add()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings'))
            || !wp_verify_nonce($_POST['wps_ic_nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $cname_input = !empty($_POST['cname']) ? $_POST['cname'] : null;

        if ($this->isAgencyPortal()) {
            $cname_remote = sanitize_text_field((string) $cname_input);
            
            
            
            if ($cname_remote === '') {
                wp_send_json_error(['msg' => 'Enter the domain you have pointed at the CDN.']);
            }
            $this->agencyCnameRelay('cnameAdd', $cname_remote);
        }

        $cname_class = new wps_ic_cname();
        $cname_class->add($cname_input);
    }


    public function wps_ic_exclude_list()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $excludeList = $_POST['excludeList'];
        $lazyExcludeList = $_POST['lazyExcludeList'];
        $delayExcludeList = $_POST['delayExcludeList'];

        if (!empty($excludeList)) {
            $excludeList = rtrim($excludeList, "\n");
            $excludeList = explode("\n", $excludeList);
            update_option('wpc-ic-external-url-exclude', $excludeList);
        } else {
            delete_option('wpc-ic-external-url-exclude');
        }

        if (!empty($lazyExcludeList)) {
            $lazyExcludeList = self::wpc_split_patterns19($lazyExcludeList);
            update_option('wpc-ic-lazy-exclude', $lazyExcludeList);
        } else {
            delete_option('wpc-ic-lazy-exclude');
        }

        if (!empty($delayExcludeList)) {
            $delayExcludeList = self::wpc_split_patterns19($delayExcludeList);
            update_option('wpc-ic-delay-js-exclude', $delayExcludeList);
        } else {
            delete_option('wpc-ic-delay-js-exclude');
        }

        wp_send_json_success();
    }

    public function wps_ic_geolocation_force()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;

        $post = $_POST['location'];

        if ($post == 'Automatic') {
            $geolocation = $this->geoLocateAjax();
            wp_send_json_success($geolocation);
        }

        $location_data = ['server' => 'frankfurt.zapwp.net', 'continent' => 'EU', 'continent_name' => 'Europe', 'country' => 'DE', 'country_name' => 'Germany'];

        switch ($post) {
            case 'EU':
                break;
            case 'US':
                $location_data = ['server' => 'nyc.zapwp.net', 'continent' => 'US', 'continent_name' => 'United States', 'country' => 'US', 'country_name' => 'United States'];
                break;
            case 'OC':
                $location_data = ['server' => 'sydney.zapwp.net', 'continent' => 'OC', 'continent_name' => 'Oceania', 'country' => 'AU', 'country_name' => 'Australia'];
                break;
            case 'AS':
                $location_data = ['server' => 'singapore.zapwp.net', 'continent' => 'AS', 'continent_name' => 'Asia', 'country' => 'Singapore', 'country_name' => 'Singapore'];
                break;
        }

        update_option('wpc-ic-force-location', $location_data);
        update_option('wps_ic_geo_locate_v2', $location_data);

        wp_send_json_success($location_data);
    }

    public function wps_ic_geolocation()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;
        $geolocation = $this->geoLocateAjax();
        wp_send_json_success($geolocation);
    }

    public function wps_ic_RestoreFinished()
    {
        global $wps_ic;

        $count = absint($_POST['count'] ?? 0) . ' of ' . absint($_POST['count'] ?? 0);

        $output = '<div class="wps-ic-bulk-html-wrapper">';
        $output .= '<div class="bulk-restore-container">';

        $output .= '<div class="bulk-restore-preview-container">';
        $output .= '<div class="bulk-restore-preview-inner">';
        $output .= '<div class="bulk-restore-preview-image-holder">';
        $output .= '<img src="' . WPS_IC_URI . 'assets/images/bulk/restore-completed-image_opt.png' . '">';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '<div class="bulk-restore-info">';

        $output .= '<div class="bulk-restore-status-top-left">';
        $output .= '<img src="' . WPS_IC_URI . 'assets/images/shield.svg' . '">';
        $output .= '<span class="badge">';
        $output .= '<i class="icon-check"></i> Restored';
        $output .= '</span>';
        $output .= '</div>';

        $output .= '<div class="bulk-restore-status-top-right">';
        $output .= '<h3>' . $count . '</h3>';
        $output .= '<h5>Images Restored</h5>';
        $output .= '</div>';

        $output .= '<div class="bulk-restore-status-container">';
        $output .= '<h4>Image Restore Complete!</h4>';
        $output .= '<span>We have successfully restored all of your images.</span>';
        $output .= '<div class="bulk-status-progress-bar">
              <div class="progress-bar-outer">
                <div class="progress-bar-inner" style="width: 100%;"></div>
              </div>
            </div>';
        $output .= '</div>';

        $output .= '</div>';

        $output .= '</div>';

        wp_send_json_success(['html' => $output]);
    }

    public function wps_ic_doBulkRestore()
    {
        global $wps_ic;

        if (!current_user_can('manage_options')) {
            wp_send_json_error('unauthorized');
        }

        $lastProgress = $_POST['lastProgress'];
        $bulkStats = get_transient('wps_ic_bulk_stats');
        $compressed_images_queue = get_transient('wps_ic_restore_queue');

        if (empty($bulkStats['images_restored'])) {
            $bulkStats['images_restored'] = 0;
        }

        if ($compressed_images_queue['queue']) {
            $attID = $compressed_images_queue['queue'][0];

            
            set_transient('wps_ic_restore_' . $attID, ['imageID' => $attID, 'status' => 'restoring'], 300);


            self::$local->restoreV4($attID);

            set_transient('wps_ic_restore_' . $attID, ['imageID' => $attID, 'status' => 'restored'], 300);

            unset($compressed_images_queue['queue'][0]);
            $compressed_images_queue['queue'] = array_values($compressed_images_queue['queue']);


            


            $leftover_images = count($compressed_images_queue['queue']);
            $total_images = $compressed_images_queue['total_images'];
            $done_images = $total_images - $leftover_images;
            $progress_percent = round(($done_images / $total_images) * 100);

            
            $bulkStats['images_restored'] += 1;

            set_transient('wps_ic_bulk_stats', $bulkStats, 1800);
            set_transient('wps_ic_restore_queue', $compressed_images_queue, 1800);

            wp_send_json_success(['done' => $attID, 'progress' => $progress_percent, 'finished' => $done_images, 'leftover' => $leftover_images, 'total' => $total_images, 'todo' => $compressed_images_queue, 'html' => $this->bulkRestoreHtml($attID, $lastProgress)]);
        }

        wp_send_json_error();
    }

    public function wps_ic_media_library_bulk_heartbeat()
    {
        global $wpdb, $wps_ic;
        $like_compress = $wpdb->esc_like('_transient_wps_ic_compress_') . '%';
        $like_restore  = $wpdb->esc_like('_transient_wps_ic_restore_') . '%';

        $heartbeat_query = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
         FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s",
                $like_compress,
                $like_restore
            )
        );

        $html = [];
        if ($heartbeat_query) {
            foreach ($heartbeat_query as $heartbeat_item) {
                $value = unserialize(untrailingslashit($heartbeat_item->option_value));

                if ($value['status'] == 'compressed' || $value['status'] == 'restored') {
                    $html[$value['imageID']] = $wps_ic->media_library->compress_details($value['imageID']);
                    delete_transient('wps_ic_compress_' . $value['imageID']);
                    delete_transient('wps_ic_restore_' . $value['imageID']);
                }
            }

            wp_send_json_success($html);
        }

        wp_send_json_error();
    }

    


    public function wps_ic_restore_live()
    {
        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }
        @set_time_limit(120);

        
        $restore_request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $imageID = absint($_POST['attachment_id']);


        set_transient('wps_ic_compress_' . $imageID, [
            'imageID' => $imageID,
            'status'  => 'restoring',
            'time'    => time(),
        ], 120);


        if (self::dispatch_async_loopback('wpc_async_restore_regen', $imageID)) {
            if (function_exists('wpc_v2_telemetry_record')) {
                wpc_v2_telemetry_record('restore', (int) round((microtime(true) - $restore_request_arrival_t) * 1000), [
                    'image_id' => $imageID,
                    'outcome'  => 'dispatched_async',
                ]);
            }
            error_log('[WPC AsyncDispatch] restore_live imageID=' . $imageID . ' dispatched_async — returning queued in ~300ms (no compress_details render)');
            wp_send_json_success([
                'queued'    => true,
                'immediate' => false,
            ]);
        }


        if (!self::claim_async_token_for_sync('wpc_async_restore_regen', $imageID)) {
            if (function_exists('wpc_v2_telemetry_record')) {
                wpc_v2_telemetry_record('restore', (int) round((microtime(true) - $restore_request_arrival_t) * 1000), [
                    'image_id' => $imageID,
                    'outcome'  => 'sync_fallback_stand_down',
                ]);
            }
            error_log('[WPC AsyncDispatch] restore_live imageID=' . $imageID . ' sync_fallback_stand_down — worker owns restore, wpcWatchCard will track');
            wp_send_json_success(['queued' => true, 'immediate' => false]);
        }

        
        
        $purge_urls_pre_sync = function_exists('wpc_customer_purge_attachment_urls')
            ? wpc_customer_purge_attachment_urls($imageID) : [];
        self::$local->restoreV4($imageID);

        
        $status = get_post_meta($imageID, 'ic_status', true);
        if ($status !== 'restored') {
            if (function_exists('wpc_v2_telemetry_record')) {
                wpc_v2_telemetry_record('restore', (int) round((microtime(true) - $restore_request_arrival_t) * 1000), [
                    'image_id' => $imageID,
                    'outcome'  => 'failed_no_backup',
                ]);
            }
            wp_send_json_error(['msg' => 'failed-to-get-backup']);
        }


        if (function_exists('wpc_purge_compat')) {
            $purge_urls = $purge_urls_pre_sync;
            if (!empty($purge_urls)) {


                
                
                try {
                    wpc_purge_compat('urls', $purge_urls, 'restore_image', '', false);
                    error_log('[WPC Purge] restore_image dispatched (non-blocking, on-click sync path) urls=' . count($purge_urls));
                } catch (\Throwable $e) {
                    error_log('[WPC Purge] restore_image purge error: ' . $e->getMessage());
                }
            }
        }

        
        global $wps_ic;
        $html = $wps_ic->media_library->compress_details($imageID);
        if (function_exists('wpc_v2_telemetry_record')) {
            wpc_v2_telemetry_record('restore', (int) round((microtime(true) - $restore_request_arrival_t) * 1000), [
                'image_id' => $imageID,
                'outcome'  => 'success',
            ]);
        }
        wp_send_json_success(['html' => $html, 'immediate' => true]);
    }


    public function wpc_purge_variants()
    {
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        
        check_ajax_referer('wpc_purge_variants', 'nonce');

        $imageID = isset($_REQUEST['imageID']) ? (int) $_REQUEST['imageID'] : 0;
        if (!$imageID || get_post_type($imageID) !== 'attachment') {
            wp_send_json_error(['msg' => 'invalid_image', 'imageID' => $imageID], 400);
        }

        if (!function_exists('wpc_purge_variants_for_image')) {
            wp_send_json_error(['msg' => 'helper_unavailable'], 500);
        }

        $result = wpc_purge_variants_for_image($imageID);
        wp_send_json_success($result);
    }


    public function wps_ic_variant_count()
    {
        $imageID = absint($_POST['attachment_id'] ?? 0);
        if ($imageID <= 0) {
            wp_send_json_error(['msg' => 'invalid-id']);
        }


        wp_cache_delete($imageID, 'post_meta');

        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        $count = 0; $jpeg = 0; $webp = 0; $avif = 0;


        $since  = isset($_POST['since']) ? (int) $_POST['since'] : 0;
        $recent = [];


        $ic_early = get_post_meta($imageID, 'ic_compressing', true);
        $status_early = (is_array($ic_early) && !empty($ic_early['status']))
            ? (string) $ic_early['status']
            : 'optimizing';


        $announced = get_transient('wpc_v2_announced_' . $imageID);
        if (!is_array($announced)) $announced = [];

        if (is_array($variants)) {
            foreach ($variants as $vkey => $ventry) {
                if (!empty($ventry['bg_no_improvement'])) continue;
                if (empty($ventry['size'])) continue;
                $count++;
                if (strpos((string) $vkey, '-avif') !== false)      { $avif++; $fmt = 'AVIF'; }
                elseif (strpos((string) $vkey, '-webp') !== false)  { $webp++; $fmt = 'WEBP'; }
                else                                                 { $jpeg++; $fmt = 'JPEG'; }

                if (!empty($ventry['bg_upgraded_ms'])) {
                    $ts = (int) $ventry['bg_upgraded_ms'];
                } else {
                    $ts = isset($ventry['bg_upgraded']) ? ((int) $ventry['bg_upgraded']) * 1000 : 0;
                }


                if (isset($announced[$vkey]['announced_ms'])) {
                    $ts = (int) $announced[$vkey]['announced_ms'];
                }
                if ($ts > $since) {


                    $size_label = (string) $vkey;
                    foreach (['-avif', '-webp', '-jpeg', '-jpg', '-png'] as $suffix) {
                        if (substr($size_label, -strlen($suffix)) === $suffix) {
                            $size_label = substr($size_label, 0, -strlen($suffix));
                            break;
                        }
                    }
                    $sv = isset($ventry['savings']) ? (int) $ventry['savings'] : 0;
                    $is_parent = ($size_label === 'original');
                    $recent[] = [
                        'fmt'       => $fmt,
                        'size'      => ucfirst(str_replace(['_', '-'], ' ', $size_label)),
                        'ts'        => $ts,
                        'savings'   => $sv,
                        'is_parent' => $is_parent,
                    ];
                }
            }
        }


        if (!empty($announced) && $status_early === 'compressed') {
            foreach ($announced as $vkey => $aentry) {
                if (isset($variants[$vkey])) continue;
                if (!empty($aentry['noImprovement'])) continue;
                $ts = isset($aentry['announced_ms']) ? (int) $aentry['announced_ms'] : 0;
                if ($ts <= $since) continue;
                $fmt_lower = isset($aentry['format']) ? (string) $aentry['format'] : '';
                if ($fmt_lower === 'jpg') $fmt_lower = 'jpeg';
                $fmt_up = strtoupper($fmt_lower);
                $size_label = isset($aentry['sizeLabel']) ? (string) $aentry['sizeLabel'] : '';
                
                
                foreach (['-avif', '-webp', '-jpeg', '-jpg', '-png'] as $suffix) {
                    if (substr($size_label, -strlen($suffix)) === $suffix) {
                        $size_label = substr($size_label, 0, -strlen($suffix));
                        break;
                    }
                }
                if ($size_label === '' || $fmt_up === '') continue;
                $recent[] = [
                    'fmt'       => $fmt_up,
                    'size'      => ucfirst(str_replace(['_', '-'], ' ', $size_label)),
                    'ts'        => $ts,
                    'savings'   => isset($aentry['savings']) ? (int) $aentry['savings'] : 0,
                    'is_parent' => ($size_label === 'original'),
                ];
            }
        }

        
        usort($recent, function ($a, $b) { return $a['ts'] - $b['ts']; });
        
        $status = $status_early;


        $ic_savings = get_post_meta($imageID, 'ic_savings', true);
        $savings_pct = is_numeric($ic_savings) ? (float) $ic_savings : 0.0;


        $phase_a_done = (bool) get_transient('wpc_v2_phase_a_done_' . $imageID);


        $warming = (bool) get_transient('wpc_v2_warming_' . $imageID);


        $pending = get_transient('wpc_v2_pending_' . $imageID);
        $still_draining = !empty($pending) && !empty($pending['pending']);
        $phase_b_done = $phase_a_done
            && ($status === 'compressed')
            && !$still_draining
            && $count > 0;

        $payload = [
            'count'        => $count,
            'jpeg'         => $jpeg,
            'webp'         => $webp,
            'avif'         => $avif,
            'status'       => $status,
            'warming'      => $warming,
            'savings_pct'  => $savings_pct,
            'phase_a_done' => $phase_a_done,
            'phase_b_done' => $phase_b_done,
            'recent'       => $recent,
        ];


        if ($status === 'compressed' && !empty($_POST['want_html'])) {
            global $wps_ic;
            if ($wps_ic && $wps_ic->media_library) {
                
                
                wp_cache_delete('_transient_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete('_transient_timeout_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete($imageID, 'post_meta');
                $payload['html'] = $wps_ic->media_library->compress_details($imageID);
            }
        }

        wp_send_json_success($payload);
    }


    public function wpc_bulk_clear_stuck_compressing()
    {
        if (!current_user_can('manage_wpc_settings') ||
            !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }
        $imageID = absint($_POST['imageID'] ?? 0);
        if ($imageID <= 0) {
            wp_send_json_error(['msg' => 'invalid-id']);
        }
        wp_cache_delete($imageID, 'post_meta');

        $ic = get_post_meta($imageID, 'ic_compressing', true);
        $status = is_array($ic) ? (string) ($ic['status'] ?? '') : '';
        if ($status !== 'queueing' && $status !== 'optimizing') {
            wp_send_json_success(['cleared' => false, 'reason' => 'not-stuck', 'status' => $status]);
        }

        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        $variant_count = is_array($variants) ? count($variants) : 0;
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        $has_pending = !empty($pending) && !empty($pending['pending']);


        
        if ($has_pending && $variant_count === 0) {
            wp_send_json_success(['cleared' => false, 'reason' => 'phase-b-pending-no-variants-yet']);
        }


        if ($variant_count > 0) {
            
            $expected_sizes = ['thumbnail','medium','medium_large','large','1536x1536','2048x2048','scaled','original'];
            $expected_fmts  = ['jpeg', 'webp', 'avif'];
            $missing_keys = [];
            foreach ($expected_sizes as $sz_label) {
                foreach ($expected_fmts as $fmt_label) {
                    $key = function_exists('wpc_v2_variant_key')
                        ? wpc_v2_variant_key($sz_label, $fmt_label)
                        : ($fmt_label === 'jpeg' ? $sz_label : $sz_label . '-' . $fmt_label);
                    if (!isset($variants[$key]) || !is_array($variants[$key]) || empty($variants[$key]['size'])) {
                        $missing_keys[] = $key;
                    }
                }
            }

            
            
            $retry_queued = 0;
            if (!empty($missing_keys)
                && function_exists('wpc_v2_pull_manifest_fetch')
                && function_exists('wpc_v2_pull_manifest_queue_for_drain')) {
                $t0_ms = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
                if ($t0_ms > 0) {
                    $retry = wpc_v2_pull_manifest_fetch(max(0, $t0_ms - 1000), 200, 0);
                    if (!empty($retry['ok']) && !empty($retry['variants'])) {
                        $my_variants = [];
                        foreach ($retry['variants'] as $v) {
                            if (isset($v['imageID']) && (int) $v['imageID'] === $imageID) {
                                $my_variants[] = $v;
                            }
                        }
                        if (!empty($my_variants)) {
                            $q = wpc_v2_pull_manifest_queue_for_drain($my_variants);
                            $retry_queued = (int) $q['queued'];
                            if ($retry_queued > 0 && function_exists('wpc_v2_journal_fire_loopback_fast')) {
                                wpc_v2_journal_fire_loopback_fast();
                            }
                        }
                    }
                }
            }

            $new_ic = is_array($ic) ? $ic : [];
            $new_ic['status'] = 'compressed';
            update_post_meta($imageID, 'ic_compressing', $new_ic);
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wpc_v2_pending_' . $imageID);
            delete_transient('wpc_v2_warming_' . $imageID);


            set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
            error_log(sprintf(
                '[WPC BulkCleanup] imageID=%d force_flipped_at_ceiling variants=%d missing=[%s] retry_queued=%d',
                $imageID, $variant_count, implode(', ', $missing_keys), $retry_queued
            ));


            $bg_missing = $missing_keys;
            $bg_t0_ms   = isset($t0_ms) ? (int) $t0_ms : (int) get_transient('wpc_v2_t0_ms_' . $imageID);
            if (!empty($bg_missing) && $bg_t0_ms > 0 && function_exists('wpc_v2_pull_manifest_fetch')) {
                $out = wp_json_encode([
                    'success' => true,
                    'data'    => [
                        'cleared'        => true,
                        'imageID'        => $imageID,
                        'force_flipped'  => true,
                        'variants'       => $variant_count,
                        'missing'        => $missing_keys,
                        'retry_queued'   => $retry_queued,
                        'bg_retry_30s'   => true,
                    ],
                ]);
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
                    header('Content-Length: ' . strlen($out));
                }
                echo $out;
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                ignore_user_abort(true);
                @set_time_limit(60);

                $bg_deadline = time() + 30;
                $bg_attempt  = 0;
                while (time() < $bg_deadline) {
                    $bg_attempt++;
                    
                    wp_cache_delete($imageID, 'post_meta');
                    $cur_variants = get_post_meta($imageID, 'ic_local_variants', true);
                    if (!is_array($cur_variants)) $cur_variants = [];
                    $still_missing = [];
                    foreach ($bg_missing as $miss_key) {
                        if (!isset($cur_variants[$miss_key])
                            || !is_array($cur_variants[$miss_key])
                            || empty($cur_variants[$miss_key]['size'])) {
                            $still_missing[] = $miss_key;
                        }
                    }
                    if (empty($still_missing)) {
                        error_log(sprintf(
                            '[WPC BulkCleanup] imageID=%d bg_retry_complete attempt=%d (all missing filled)',
                            $imageID, $bg_attempt
                        ));
                        break;
                    }


                    $retry = wpc_v2_pull_manifest_fetch(max(0, $bg_t0_ms - 1000), 200, 5000);
                    if (!empty($retry['ok']) && !empty($retry['variants'])) {
                        $my_variants = [];
                        foreach ($retry['variants'] as $v) {
                            if (isset($v['imageID']) && (int) $v['imageID'] === $imageID) {
                                $my_variants[] = $v;
                            }
                        }
                        if (!empty($my_variants) && function_exists('wpc_v2_pull_manifest_queue_for_drain')) {
                            $q = wpc_v2_pull_manifest_queue_for_drain($my_variants);
                            if ((int) $q['queued'] > 0 && function_exists('wpc_v2_journal_fire_loopback_fast')) {
                                wpc_v2_journal_fire_loopback_fast();
                            }
                        }
                    }
                    error_log(sprintf(
                        '[WPC BulkCleanup] imageID=%d bg_retry attempt=%d still_missing=[%s]',
                        $imageID, $bg_attempt, implode(', ', $still_missing)
                    ));
                    
                }
                if (time() >= $bg_deadline) {
                    error_log(sprintf(
                        '[WPC BulkCleanup] imageID=%d bg_retry_deadline_reached attempts=%d',
                        $imageID, $bg_attempt
                    ));
                }
                exit;
            }

            wp_send_json_success([
                'cleared'       => true,
                'imageID'       => $imageID,
                'force_flipped' => true,
                'variants'      => $variant_count,
                'missing'       => $missing_keys,
                'retry_queued'  => $retry_queued,
            ]);
        }

        
        delete_post_meta($imageID, 'ic_compressing');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wpc_v2_warming_' . $imageID);
        error_log(sprintf('[WPC BulkCleanup] imageID=%d ic_compressing cleared (no variants, no pending) after bulk ceiling', $imageID));
        wp_send_json_success(['cleared' => true, 'imageID' => $imageID]);
    }

    
    
    
    
    
    
    
    
    public static function wpc_v2_stale_compress_watchdog($imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return false;
        wp_cache_delete($imageID, 'post_meta');
        $ic = get_post_meta($imageID, 'ic_compressing', true);
        $st = is_array($ic) && !empty($ic['status']) ? (string) $ic['status'] : '';
        if ($st !== 'queueing' && $st !== 'optimizing') return false;
        $started = is_array($ic) ? (int) ($ic['time'] ?? 0) : 0;
        if ($started <= 0) return false;
        $deadline = (int) apply_filters('wpc_v2_compress_stall_seconds', 90, $imageID);
        if ((time() - $started) < $deadline) return false;
        
        if (!empty(get_transient('wpc_v2_pending_' . $imageID))) return false;
        $vs = get_post_meta($imageID, 'ic_local_variants', true);
        if (is_array($vs) && !empty($vs)) return false;
        
        delete_post_meta($imageID, 'ic_compressing');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wpc_v2_pending_' . $imageID);
        delete_transient('wpc_lazy_v2_trigger_' . $imageID);
        
        
        
        
        $worker_reached = !empty(get_transient('wpc_v2_worker_reached_' . $imageID));
        if (!$worker_reached) {
            update_option('wpc_v2_async_unreliable', time(), false);
            $reason = 'the background optimization worker did not survive on this host (dispatch never left the server) — future optimizes will run synchronously; re-optimize this image to complete it';
            error_log('[WPC Watchdog] imageID=' . $imageID . ' DETACH-DEATH (no worker breadcrumb) — async marked unreliable, sync forced site-wide');
        } else {
            $reason = 'no_variants_after_' . $deadline . 's — the worker dispatched but the service returned no result (service-side); re-optimize to retry';
            error_log('[WPC Watchdog] imageID=' . $imageID . ' worker reached dispatch but no variants — service-side, NOT blaming detach');
        }
        delete_transient('wpc_v2_worker_reached_' . $imageID);
        set_transient('wpc_v2_compress_failed_' . $imageID, $reason, 900);
        wp_cache_delete($imageID, 'post_meta');
        return true;
    }

    
    
    
    
    public function wpc_v2_compress_probe()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden');
        }
        $imageID = absint($_REQUEST['attachment_id'] ?? 0);
        $out = [
            'plugin_version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'orchestrator'   => function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '',
            'has_apikey'     => function_exists('wpc_v2_get_apikey') ? (wpc_v2_get_apikey() !== '') : null,
            'v2_protocol'    => function_exists('wpc_use_v2_protocol') ? (bool) wpc_use_v2_protocol() : null,
            'pull_enabled'   => function_exists('wpc_v2_pull_enabled') ? (bool) wpc_v2_pull_enabled() : null,
            'async_enabled'  => (bool) apply_filters('wpc_v2_async_dispatch_enabled', true),
            'can_detach'     => function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'),
            'store_broken'   => function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197(),
            'async_dispatch_ok' => self::wpc_v2_async_dispatch_ok(),
            'async_unreliable_since' => (int) get_option('wpc_v2_async_unreliable', 0),
            'last_dispatch_error' => get_option('wpc_v2_last_dispatch_error', null),
        ];
        
        if (function_exists('wpc_v2_pull_manifest_fetch')) {
            $t0 = microtime(true);
            $r  = wpc_v2_pull_manifest_fetch(0, 1, 0);
            $out['reachability'] = [
                'ok'      => !empty($r['ok']),
                'reason'  => isset($r['error']) ? (string) $r['error'] : (empty($r['ok']) ? 'unknown' : 'reached'),
                'wall_ms' => (int) round((microtime(true) - $t0) * 1000),
                'note'    => !empty($r['ok'])
                    ? 'outbound apikey-signed call reached the service — egress OK; the stall is NOT connectivity'
                    : 'outbound call did NOT reach the service — host egress/firewall/DNS to the orchestrator is the stall cause',
            ];
        } else {
            $out['reachability'] = ['ok' => null, 'reason' => 'manifest_fetch_unavailable'];
        }
        $out['post_reachability'] = self::wpc_v2_probe_post();
        
        $out['loopback'] = ['admin_ajax' => admin_url('admin-ajax.php')];
        if ($imageID > 0) {
            wp_cache_delete($imageID, 'post_meta');
            $ic = get_post_meta($imageID, 'ic_compressing', true);
            $out['image'] = [
                'id'             => $imageID,
                'ic_status'      => get_post_meta($imageID, 'ic_status', true),
                'ic_compressing' => is_array($ic) ? $ic : $ic,
                'attempts'       => get_post_meta($imageID, 'ic_v2_attempts', true),
                'pending'        => !empty(get_transient('wpc_v2_pending_' . $imageID)),
                'last_error'     => get_transient('wpc_v2_compress_failed_' . $imageID),
                'admit'          => function_exists('wpc_v2_attempts_admit197') ? wpc_v2_attempts_admit197($imageID) : null,
                'variants'       => is_array(get_post_meta($imageID, 'ic_local_variants', true)) ? count(get_post_meta($imageID, 'ic_local_variants', true)) : 0,
                'worker_reached' => !empty(get_transient('wpc_v2_worker_reached_' . $imageID)),
                'last_envelope'  => get_transient('wpc_v2_last_envelope_' . $imageID),
            ];
        }
        wp_send_json_success($out);
    }

    
    
    
    
    
    public static function wpc_v2_probe_post($force = false)
    {
        if (!function_exists('wpc_v2_orchestrator_url') || !function_exists('wp_remote_post')) {
            return ['ok' => null, 'reason' => 'unavailable'];
        }
        if (!$force) {
            $c = get_transient('wpc_v2_probe_small_cache');
            if (is_array($c)) { $c['cached'] = true; return $c; }
        }
        $url = rtrim((string) wpc_v2_orchestrator_url(), '/') . '/optimize-v2/manifest';
        $apikey = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
        $t0 = microtime(true);
        $resp = wp_remote_post($url, [
            'timeout'     => (int) apply_filters('wpc_v2_probe_post_timeout', 8),
            'redirection' => 0,
            'blocking'    => true,
            'headers'     => ['Content-Type' => 'application/json', 'Accept' => 'application/json',
                              'X-WPC-Probe' => '1', 'X-WPC-Apikey-Present' => ($apikey !== '' ? '1' : '0')],
            'body'        => wp_json_encode(['probe' => 1]),
        ]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_wp_error($resp)) {
            $out = ['ok' => false, 'http_code' => 0, 'wall_ms' => $ms,
                'error' => $resp->get_error_code() . ': ' . $resp->get_error_message(),
                'note'  => 'POST to the service FAILED (GET works, POST does not) — a proxy/WAF is holding or blocking outbound POST from this host; that is why the encode dispatch hangs'];
            set_transient('wpc_v2_probe_small_cache', $out, 300);
            return $out;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $out = ['ok' => true, 'http_code' => $code, 'wall_ms' => $ms,
            'note' => 'POST reached the service (HTTP ' . $code . ' in ' . $ms . 'ms) — outbound POST is NOT the blocker; any non-2xx here is just the endpoint rejecting a probe body'];
        set_transient('wpc_v2_probe_small_cache', $out, 300);
        return $out;
    }

    
    
    
    
    
    public static function wpc_v2_probe_post_large($force = false)
    {
        if (!function_exists('wpc_v2_orchestrator_url') || !function_exists('wp_remote_post')) {
            return ['ok' => null, 'reason' => 'unavailable'];
        }
        
        if (!$force) {
            $cached = get_transient('wpc_v2_probe_large_cache');
            if (is_array($cached)) { $cached['cached'] = true; return $cached; }
        }
        $url = rtrim((string) wpc_v2_orchestrator_url(), '/') . '/optimize-v2';
        $pad = str_repeat('x', 100 * 1024); 
        $body = wp_json_encode(['apikey' => 'invalid-probe-key-0000', 'imageID' => '0', 'probe' => 1, 'pad' => $pad]);
        $t0 = microtime(true);
        $resp = wp_remote_post($url, [
            'timeout'     => (int) apply_filters('wpc_v2_probe_post_timeout', 15),
            'redirection' => 0,
            'blocking'    => true,
            'headers'     => ['Content-Type' => 'application/json', 'X-WPC-Probe' => 'large'],
            'body'        => $body,
        ]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_wp_error($resp)) {
            
            
            
            $err_code = $resp->get_error_code();
            $timed_out = stripos((string) $resp->get_error_message(), 'timed out') !== false
                || stripos((string) $resp->get_error_message(), 'cURL error 28') !== false
                || $err_code === 'http_request_failed';
            if ($timed_out && !self::wpc_v2_prefer_source_url()) {
                update_option('wpc_v2_prefer_source_url', time(), false);
                error_log('[WPC ProbeLarge] large-body blackhole detected — armed source.url dispatch proactively (no 30s click-wait)');
            }
            $out = ['ok' => false, 'bytes' => strlen($body), 'wall_ms' => $ms,
                'error' => $err_code . ': ' . $resp->get_error_message(),
                'armed_source_url' => self::wpc_v2_prefer_source_url(),
                'note'  => 'LARGE POST body did NOT arrive (small probe does) — this host blackholes large outbound POST bodies (MSS/PMTUD or an outbound security appliance). THIS is why encode dispatch times out. Auto-armed source.url (~1KB envelope) so optimizes dodge it now; fix host-side to restore inline.'];
            set_transient('wpc_v2_probe_large_cache', $out, 300);
            return $out;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $out = ['ok' => true, 'http_code' => $code, 'bytes' => strlen($body), 'wall_ms' => $ms,
            'note' => 'large body arrived (HTTP ' . $code . ' in ' . $ms . 'ms) — the ~100KB path is NOT blackholed; the encode timeout is elsewhere'];
        set_transient('wpc_v2_probe_large_cache', $out, 300);
        return $out;
    }

    
    
    
    
    public function wpc_v2_full_debug()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden');
        }
        $ids = [];
        if (!empty($_REQUEST['ids'])) {
            foreach (explode(',', (string) $_REQUEST['ids']) as $wpc_id) {
                $wpc_id = (int) trim($wpc_id);
                if ($wpc_id > 0) $ids[] = $wpc_id;
            }
        }
        $reach = null;
        if (function_exists('wpc_v2_pull_manifest_fetch')) {
            $t0 = microtime(true);
            $r  = wpc_v2_pull_manifest_fetch(0, 1, 0);
            $reach = ['ok' => !empty($r['ok']), 'reason' => isset($r['error']) ? (string) $r['error'] : 'reached', 'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
        }
        $post_reach = self::wpc_v2_probe_post();
        $post_reach_large = self::wpc_v2_probe_post_large();
        $out = [
            'plugin_version'         => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'at'                     => time(),
            'orchestrator'           => function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '',
            'has_apikey'             => function_exists('wpc_v2_get_apikey') ? (wpc_v2_get_apikey() !== '') : null,
            'reachability'           => $reach,
            'post_reachability'      => $post_reach,
            'post_reachability_large'=> $post_reach_large,
            'dispatch' => [
                'v2_protocol'        => function_exists('wpc_use_v2_protocol') ? (bool) wpc_use_v2_protocol() : null,
                'pull_enabled'       => function_exists('wpc_v2_pull_enabled') ? (bool) wpc_v2_pull_enabled() : null,
                'async_enabled'      => (bool) apply_filters('wpc_v2_async_dispatch_enabled', true),
                'async_dispatch_ok'  => self::wpc_v2_async_dispatch_ok(),
                'async_unreliable_since' => (int) get_option('wpc_v2_async_unreliable', 0),
                'prefer_source_url'  => self::wpc_v2_prefer_source_url(),
                'prefer_source_url_since' => (int) get_option('wpc_v2_prefer_source_url', 0),
                'can_detach'         => function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'),
                'last_dispatch_error'=> get_option('wpc_v2_last_dispatch_error', null),
                'store_broken'       => function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197(),
                'store_broken_note'  => get_option('wps_ic', null) ? null : null,
            ],
            'bulk' => [
                'process'            => get_option('wps_ic_bulk_process', null),
                'heartbeat'          => (int) get_transient('wpc_bulk_heartbeat'),
                'queue_len'          => (function () { $q = self::wpc_bulk_queue_read199(); return is_array($q) && isset($q['queue']) ? count($q['queue']) : 0; })(),
                'inflight'           => self::wpc_bulk_inflight199(),
                'ledger'             => self::wpc_bulk_ledger_summary202(),
                'draining'           => (int) get_transient('wpc_bulk_compress_draining'),
                'stop_signal'        => (bool) get_transient('wpc_bulk_stop_signal'),
                'sequential'         => (bool) get_option('wpc_bulk_sequential', 0),
            ],
            'server' => [
                'php'                => PHP_VERSION,
                'memory_limit'       => (string) @ini_get('memory_limit'),
                'max_execution_time' => (string) @ini_get('max_execution_time'),
                'admin_ajax'         => admin_url('admin-ajax.php'),
                'curl'               => function_exists('curl_init'),
            ],
            'images' => [],
        ];
        foreach ($ids as $imageID) {
            wp_cache_delete($imageID, 'post_meta');
            self::wpc_v2_stale_compress_watchdog($imageID);
            wp_cache_delete($imageID, 'post_meta');
            $ic = get_post_meta($imageID, 'ic_compressing', true);
            $vs = get_post_meta($imageID, 'ic_local_variants', true);
            $out['images'][] = [
                'id'             => $imageID,
                'exists'         => get_post_type($imageID) === 'attachment',
                'mime'           => get_post_mime_type($imageID),
                'ic_status'      => get_post_meta($imageID, 'ic_status', true),
                'ic_compressing' => is_array($ic) ? $ic : $ic,
                'attempts'       => get_post_meta($imageID, 'ic_v2_attempts', true),
                'admit'          => function_exists('wpc_v2_attempts_admit197') ? wpc_v2_attempts_admit197($imageID) : null,
                'pending'        => !empty(get_transient('wpc_v2_pending_' . $imageID)),
                'worker_reached' => !empty(get_transient('wpc_v2_worker_reached_' . $imageID)),
                'last_error'     => get_transient('wpc_v2_compress_failed_' . $imageID),
                'last_envelope'  => get_transient('wpc_v2_last_envelope_' . $imageID),
                'variants'       => is_array($vs) ? count($vs) : 0,
                'excluded'       => get_post_meta($imageID, 'wps_ic_exclude_live', true) === 'true',
            ];
        }
        wp_send_json_success($out);
    }

    public function wps_ic_get_card()
    {
        $imageID = absint($_POST['attachment_id'] ?? 0);
        if ($imageID <= 0) {
            wp_send_json_error(['msg' => 'invalid-id']);
        }
        self::wpc_v2_stale_compress_watchdog($imageID);
        $wpc_fail315 = get_transient('wpc_v2_compress_failed_' . $imageID);
        global $wps_ic;
        if (!$wps_ic || !$wps_ic->media_library) {
            wp_send_json_error(['msg' => 'not-ready']);
        }
        $html = $wps_ic->media_library->compress_details($imageID);

        
        $pending = (
            (strpos($html, 'is-restoring') !== false) ||
            (strpos($html, 'is-compressing') !== false) ||
            !empty(get_transient('wps_ic_compress_' . $imageID)) ||
            !empty(get_post_meta($imageID, '_wpc_pending_thumb_regen', true))
        );

        if (!empty($wpc_fail315)) { $pending = false; }
        wp_send_json_success([
            'html'      => $html,
            'pending'   => $pending,
            'imageID'   => $imageID,
            'ic_status' => get_post_meta($imageID, 'ic_status', true),
            'failed'    => !empty($wpc_fail315),
            'error'     => $wpc_fail315 ? (string) $wpc_fail315 : '',
        ]);
    }

    public function wps_ic_compress_live()
    {
        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }
        @set_time_limit(120);


        $compress_request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);
        $compress_telemetry_image_id = absint($_POST['attachment_id'] ?? 0);
        register_shutdown_function(function () use ($compress_request_arrival_t, $compress_telemetry_image_id) {
            if (!function_exists('wpc_v2_telemetry_record')) return;
            $ms = (int) round((microtime(true) - $compress_request_arrival_t) * 1000);
            wpc_v2_telemetry_record('compress_live', $ms, [
                'image_id' => $compress_telemetry_image_id,
            ]);
        });

        $imageID = absint($_POST['attachment_id']);
        $status = get_post_meta($imageID, 'ic_status', true);
        if (!empty($status) && $status == 'compressed') {


            global $wps_ic;
            $html = (isset($wps_ic) && isset($wps_ic->media_library))
                ? $wps_ic->media_library->compress_details($imageID)
                : '';
            wp_send_json_error(['msg' => 'file-already-compressed', 'html' => $html]);
        }


        $async_will_fire = apply_filters('wpc_v2_async_dispatch_enabled', true)
            && function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol()
            && class_exists('WPS_LocalV2');
        if (!$async_will_fire && method_exists(self::$local, 'wait_for_regen_or_clear_stale')) {
            self::$local->wait_for_regen_or_clear_stale($imageID, 15);
        }


        if (method_exists('wps_local_compress', 'preempt_ladder_for')) {
            wps_local_compress::preempt_ladder_for($imageID);
        }


        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);


        if (!$async_will_fire) {
            $backupOk = self::$local->backup_all_sizes($imageID);
            if (!$backupOk) {
                wp_send_json_error(['msg' => 'backup-failed']);
            }
        }


        if (function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol() && class_exists('WPS_LocalV2')) {
            
            
            
            
            
            $wpc_async_ok314 = self::wpc_v2_async_dispatch_ok();

            if ($wpc_async_ok314 && self::dispatch_async_loopback('wpc_async_phase_a', $imageID)) {


                $expected_seed = function_exists('wpc_v2_compute_expected_variants')
                    ? wpc_v2_compute_expected_variants($imageID)
                    : 0;
                update_post_meta($imageID, 'ic_compressing', [
                    'status'            => 'queueing',
                    'expected_variants' => $expected_seed,
                    'time'              => time(),
                ]);


                if (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled()) {
                    $deadline = (int) (microtime(true) * 1000) + 60000;
                    $current  = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
                    if ($deadline > $current) {
                        update_option('wpc_v2_drain_alive_until_ms', $deadline, false);
                    }
                    if (function_exists('wpc_v2_pull_drain_fire')) {
                        wpc_v2_pull_drain_fire();
                    }
                }
                error_log('[WPC AsyncDispatch] compress_live imageID=' . $imageID . ' dispatched_async — returning queued in ~300ms instead of holding worker for Phase A wall');
                wp_send_json_success([
                    'queued'    => true,
                    'immediate' => false,
                    'imageID'   => $imageID,
                    'v2'        => true,
                ]);
            }


            if ($wpc_async_ok314 && !self::claim_async_token_for_sync('wpc_async_phase_a', $imageID)) {
                global $wps_ic;
                wp_cache_delete($imageID, 'post_meta');
                $inflight_html = (isset($wps_ic) && isset($wps_ic->media_library))
                    ? $wps_ic->media_library->compress_details($imageID)
                    : '';
                error_log('[WPC V2Route] imageID=' . $imageID . ' sync_fallback_stand_down — worker owns compress, heartbeat will track');
                wp_send_json_success(['html' => $inflight_html, 'immediate' => false, 'v2' => true, 'in_flight' => true]);
            }
            if (isset(self::$local) && method_exists(self::$local, 'backup_all_sizes')) {
                $backupOk_sync = self::$local->backup_all_sizes($imageID);
                if (!$backupOk_sync) {
                    wp_send_json_error(['msg' => 'backup-failed']);
                }
            }
            
            
            
            
            set_transient('wpc_v2_worker_reached_' . $imageID, time(), 600);
            $v2_result = self::run_v2_optimize($imageID, ['resubmit_reason' => 'user_recompress']);
            if (!empty($v2_result['ok'])) {
                global $wps_ic;


                wp_cache_delete('_transient_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete('_transient_timeout_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete($imageID, 'post_meta');

                $html = $wps_ic->media_library->compress_details($imageID);


                set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
                error_log('[WPC V2Route] imageID=' . $imageID . ' SUCCESS wall_ms=' . ((int) ($v2_result['wall_ms'] ?? 0)) . ' jobId=' . substr((string) ($v2_result['jobId'] ?? ''), 0, 8));
                wp_send_json_success(['html' => $html, 'immediate' => true, 'v2' => true, 'jobId' => $v2_result['jobId'] ?? null]);
            }


            if (!empty($v2_result['error']) && $v2_result['error'] === 'already_in_flight') {
                global $wps_ic;
                wp_cache_delete($imageID, 'post_meta');
                $html = (isset($wps_ic) && isset($wps_ic->media_library))
                    ? $wps_ic->media_library->compress_details($imageID)
                    : '';
                error_log('[WPC V2Route] imageID=' . $imageID . ' already_in_flight — JS will heartbeat-poll for completion');
                wp_send_json_success(['html' => $html, 'immediate' => false, 'v2' => true, 'in_flight' => true]);
            }
            
            error_log(sprintf(
                '[WPC V2Route] imageID=%d FAILED error=%s http_code=%d detail=%s wall_ms=%d — falling through to v1',
                $imageID,
                $v2_result['error']  ?? 'unknown',
                (int) ($v2_result['http_code'] ?? 0),
                substr((string) ($v2_result['detail'] ?? ''), 0, 200),
                (int) ($v2_result['wall_ms'] ?? 0)
            ));
            
            
            
            
            
            
            
            
            
            $wpc_transport317 = empty($v2_result['ok'])
                && (($v2_result['error'] ?? '') === 'transport'
                    || (int) ($v2_result['http_code'] ?? 0) === 0
                    || stripos((string) ($v2_result['detail'] ?? ''), 'timed out') !== false
                    || stripos((string) ($v2_result['detail'] ?? ''), 'cURL error 28') !== false);
            if ($wpc_transport317 && !self::wpc_v2_prefer_source_url()) {
                update_option('wpc_v2_prefer_source_url', time(), false);
                error_log('[WPC V2Route] imageID=' . $imageID . ' TRANSPORT TIMEOUT with inline body — host blackholes large POST; switched to source.url dispatch, retrying inline-free');
                $wpc_retry317 = self::run_v2_optimize($imageID, ['resubmit_reason' => 'source_url_retry', 'force_url_source' => true]);
                if (!empty($wpc_retry317['ok'])) {
                    global $wps_ic;
                    wp_cache_delete($imageID, 'post_meta');
                    $wpc_rhtml317 = (isset($wps_ic) && isset($wps_ic->media_library)) ? $wps_ic->media_library->compress_details($imageID) : '';
                    set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
                    error_log('[WPC V2Route] imageID=' . $imageID . ' source.url retry SUCCESS');
                    wp_send_json_success(['html' => $wpc_rhtml317, 'immediate' => true, 'v2' => true, 'source_url_retry' => true, 'jobId' => $wpc_retry317['jobId'] ?? null]);
                }
                $v2_result = $wpc_retry317; 
            }
            if (empty($v2_result['ok']) && ($v2_result['error'] ?? '') !== 'already_in_flight') {
                $wpc_de314 = 'dispatch to the optimization service failed: ' . (string) ($v2_result['error'] ?? 'unknown')
                    . ' (http ' . (int) ($v2_result['http_code'] ?? 0) . ')'
                    . (($v2_result['detail'] ?? '') !== '' ? ' — ' . substr((string) $v2_result['detail'], 0, 240) : '');
                set_transient('wpc_v2_compress_failed_' . $imageID, $wpc_de314, 900);
                update_option('wpc_v2_last_dispatch_error', ['id' => $imageID, 'err' => $wpc_de314, 't' => time()], false);
                $wpc_icn314 = get_post_meta($imageID, 'ic_compressing', true);
                if (is_array($wpc_icn314) && in_array(($wpc_icn314['status'] ?? ''), ['optimizing', 'queueing'], true)) {
                    delete_post_meta($imageID, 'ic_compressing');
                }
                delete_transient('wps_ic_compress_' . $imageID);
                error_log('[WPC V2Route] imageID=' . $imageID . ' dispatch error surfaced + optimizing state cleared: ' . $wpc_de314);
            }


            wp_cache_delete('_transient_wps_ic_compress_' . $imageID, 'options');
            wp_cache_delete('_transient_timeout_wps_ic_compress_' . $imageID, 'options');
            wp_cache_delete($imageID, 'post_meta');
            $ic_compressing_now = get_post_meta($imageID, 'ic_compressing', true);
            if (is_array($ic_compressing_now) && ($ic_compressing_now['status'] ?? '') === 'compressed') {
                global $wps_ic;
                $html = $wps_ic->media_library->compress_details($imageID);
                error_log('[WPC V2Route] imageID=' . $imageID . ' eager_already_compressed — skipping v1 fallthrough');
                wp_send_json_success(['html' => $html, 'immediate' => true, 'v2_eager_kept' => true]);
            }
        }


        self::$local->singleCompressV4($imageID, 'silent', true, 'single');

        $newStatus = get_post_meta($imageID, 'ic_status', true);
        global $wps_ic;
        $html = $wps_ic->media_library->compress_details($imageID);

        if ($newStatus === 'compressed') {
            wp_send_json_success(['html' => $html, 'immediate' => true]);
        }
        
        
        if (wp_next_scheduled('wpc_retry_compress', [$imageID])) {
            wp_send_json_success(['html' => $html, 'retry_scheduled' => true]);
        }
        wp_send_json_error(['msg' => 'unable-to-contact-api', 'html' => $html]);
    }


    public static function run_v2_optimize($imageID, array $option_overrides = [])
    {
        $imageID = (int) $imageID;


        $lock_opt = 'wpc_v2_inflight_' . $imageID;
        if (!add_option($lock_opt, time(), '', 'no')) {
            $existing = (int) get_option($lock_opt, 0);
            if ($existing > 0 && (time() - $existing) > 300) {
                
                delete_option($lock_opt);
                if (!add_option($lock_opt, time(), '', 'no')) {
                    return ['ok' => false, 'error' => 'already_in_flight', 'imageID' => $imageID];
                }
            } else {
                return ['ok' => false, 'error' => 'already_in_flight', 'imageID' => $imageID];
            }
        }

        try {
            $prep = self::prepare_v2_optimize($imageID, $option_overrides);
            if (empty($prep['ok'])) {
                return $prep;
            }


            $variants_count = is_array($prep['variants']) ? count($prep['variants']) : 0;
            $formats_arr    = isset($prep['options']['formats']) && is_array($prep['options']['formats'])
                ? $prep['options']['formats']
                : ['jpeg', 'webp', 'avif'];
            $formats_count  = max(1, count($formats_arr));
            if ($variants_count > 0 && function_exists('wpc_v2_ic_compressing_set_expected')) {
                wpc_v2_ic_compressing_set_expected($imageID, $variants_count * $formats_count);
            }

            $t0 = $prep['t0'];
            $result = $prep['client']->optimize($imageID, $prep['variants'], $prep['options']);
            $wall_ms = (int) round((microtime(true) - $t0) * 1000);

            if (empty($result['ok'])) {
                return [
                    'ok'       => false,
                    'error'    => $result['error']  ?? 'optimize_failed',
                    'detail'   => $result['detail'] ?? '',
                    'http_code'=> $result['http_code'] ?? 0,
                    'wall_ms'  => $wall_ms,
                ];
            }

            return [
                'ok'               => true,
                'jobId'            => $result['jobId'] ?? '',
                'wall_ms'          => $wall_ms,
                'variants_written' => $result['write']['variants_written'] ?? [],
            ];
        } finally {
            delete_option($lock_opt);
        }
    }


    public static function prepare_v2_optimize($imageID, array $option_overrides = [])
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0 || get_post_type($imageID) !== 'attachment') {
            return ['ok' => false, 'error' => 'invalid_imageID'];
        }

        $options = get_option(WPS_IC_OPTIONS);
        $apikey  = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        if ($apikey === '') {
            return ['ok' => false, 'error' => 'no_apikey'];
        }


        $plugin_max_width = is_array($options) && !empty($options['maxWidth'])
            ? (int) $options['maxWidth']
            : 2560;

        if (!function_exists('wpc_v2_orchestrator_url')) {
            return ['ok' => false, 'error' => 'orchestrator_url_resolver_missing'];
        }
        $orchestrator_url = wpc_v2_orchestrator_url();
        if ($orchestrator_url === '') {
            return ['ok' => false, 'error' => 'no_orchestrator_url'];
        }


        $meta = wp_get_attachment_metadata($imageID);
        $abs_parent = get_attached_file($imageID);
        $parent_dir = $abs_parent ? dirname($abs_parent) : '';

        $build_filenames = static function ($base_jpg) {
            $dot = strrpos($base_jpg, '.');
            $stem = ($dot === false) ? $base_jpg : substr($base_jpg, 0, $dot);
            return [
                'jpeg' => $stem . '.jpg',
                'webp' => $stem . '.webp',
                'avif' => $stem . '.avif',
            ];
        };


        $needed_widths = [];
        if (isset($option_overrides['needed_widths']) && is_array($option_overrides['needed_widths'])) {
            foreach ($option_overrides['needed_widths'] as $w) {
                $w = (int) $w;
                if ($w > 0) $needed_widths[] = $w;
            }
        }
        $size_in_needed = function ($size_width) use ($needed_widths) {
            if (empty($needed_widths)) return true;
            foreach ($needed_widths as $need) {
                if (abs((int) $size_width - $need) <= 15) return true;
            }
            return false;
        };


        $iw_ceiling = 0;
        $envelope_on_rv = apply_filters('wpc_envelope_ideal_widths', (string) get_option('wpc_envelope_ideal_widths', '1') === '1');
        if ($envelope_on_rv) {
            $iw_mem_rv = get_post_meta($imageID, 'wpc_ideal_widths', true);
            if (is_array($iw_mem_rv) && !empty($iw_mem_rv)) {
                $iw_ceiling = (int) round(max(array_map('intval', $iw_mem_rv)) * 1.3);
            }
        }

        $variants = [];
        $seen_labels = [];
        $smart_skipped = [];
        if (is_array($meta) && !empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $label => $info) {
                if (!is_string($label) || $label === '') continue;
                $size_w = isset($info['width']) ? (int) $info['width'] : 0;
                if (!$size_in_needed($size_w)) {
                    $smart_skipped[] = $label . '(' . $size_w . 'w)';
                    continue;
                }
                if ($iw_ceiling > 0 && $size_w > $iw_ceiling) {
                    $smart_skipped[] = $label . '(' . $size_w . 'w>ceiling' . $iw_ceiling . ')';
                    continue;
                }
                $sub_file  = isset($info['file']) ? (string) $info['file'] : '';
                $sub_path  = ($sub_file !== '' && $parent_dir !== '') ? $parent_dir . '/' . $sub_file : '';
                $sub_bytes = ($sub_path !== '' && is_file($sub_path)) ? (int) filesize($sub_path) : 0;
                $variants[] = [
                    'sizeLabel'     => (string) $label,
                    'maxWidth'      => $size_w,
                    'maxHeight'     => isset($info['height']) ? (int) $info['height'] : 0,
                    'crop'          => ($label === 'thumbnail'),
                    'filenames'     => $sub_file !== '' ? $build_filenames($sub_file) : null,
                    'originalBytes' => $sub_bytes,
                ];
                $seen_labels[$label] = true;
            }
        }
        if (!empty($needed_widths)) {
            error_log(sprintf(
                '[WPC PrepV2] imageID=%d smart_lazy needed_widths=[%s] kept_sub_sizes=%d skipped=[%s]',
                $imageID,
                implode(',', $needed_widths),
                count($variants),
                implode(',', $smart_skipped)
            ));
        }


        if (apply_filters('wpc_envelope_ideal_widths', (string) get_option('wpc_envelope_ideal_widths', '1') === '1')) {
            $iw_env = get_post_meta($imageID, 'wpc_ideal_widths', true);
            $nat_w_env = is_array($meta) && !empty($meta['width']) ? (int) $meta['width'] : 0;
            $nat_h_env = is_array($meta) && !empty($meta['height']) ? (int) $meta['height'] : 0;
            $stem_env = '';
            if ($abs_parent) {
                $b_env = basename($abs_parent);
                $d_env = strrpos($b_env, '.');
                $stem_env = preg_replace('/-scaled$/', '', $d_env === false ? $b_env : substr($b_env, 0, $d_env));
            }
            if ($nat_w_env > 0 && $nat_h_env > 0 && $stem_env !== '') {
                $env_added = 0;
                $iw_env = is_array($iw_env) ? array_map('intval', $iw_env) : [];


                rsort($iw_env, SORT_NUMERIC);
                foreach ($iw_env as $w_env) {
                    if ($env_added >= 6) break;
                    $w_env = (int) $w_env;
                    if ($w_env < 200 || $w_env >= $nat_w_env) continue;
                    $near_env = false;
                    foreach ($variants as $ve) {
                        $vw = isset($ve['maxWidth']) ? (int) $ve['maxWidth'] : 0; 
                        if ($vw >= $w_env && $vw > 0 && ($vw - $w_env) / $w_env < 0.08) { $near_env = true; break; }
                    }
                    if ($near_env) continue;
                    $h_env = (int) round($w_env * $nat_h_env / $nat_w_env);
                    $label_env = $w_env . 'x' . $h_env;
                    if (isset($seen_labels[$label_env])) continue;
                    $seen_labels[$label_env] = true;
                    $variant_env = [
                        'sizeLabel'     => $label_env,
                        'maxWidth'      => $w_env,
                        'maxHeight'     => $h_env,
                        'crop'          => false,
                        'filenames'     => $build_filenames($stem_env . '-' . $label_env . '.jpg'),
                        'originalBytes' => 0,
                    ];


                    if (apply_filters('wpc_envelope_per_variant_formats', (string) get_option('wpc_envelope_per_variant_formats', '1') === '1')) {
                        $variant_env['formats'] = ['avif'];
                    }
                    $variants[] = $variant_env;
                    $env_added++;
                }
            }
        }

        $is_smart_lazy_trim = !empty($needed_widths);
        $include_scaled = empty($seen_labels['scaled'])
            && (!$is_smart_lazy_trim || $size_in_needed($plugin_max_width));
        $include_original = empty($seen_labels['original'])
            && (!$is_smart_lazy_trim || $size_in_needed($plugin_max_width));

        if ($include_scaled) {
            $scaled_bytes = ($abs_parent && is_file($abs_parent)) ? (int) filesize($abs_parent) : 0;
            $variants[] = [
                'sizeLabel'     => 'scaled',
                'maxWidth'      => $plugin_max_width,
                'maxHeight'     => $plugin_max_width,
                'crop'          => false,
                'filenames'     => $abs_parent ? $build_filenames(basename($abs_parent)) : null,
                'originalBytes' => $scaled_bytes,
            ];
        }
        if ($include_original) {
            $orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $abs_parent;
            if (!$orig_path) $orig_path = $abs_parent;
            $orig_bytes = ($orig_path && is_file($orig_path)) ? (int) filesize($orig_path) : 0;
            $variants[] = [
                'sizeLabel'     => 'original',
                'maxWidth'      => $plugin_max_width,
                'maxHeight'     => $plugin_max_width,
                'crop'          => false,
                'parent'        => true,
                'filenames'     => $orig_path ? $build_filenames(basename($orig_path)) : null,
                'originalBytes' => $orig_bytes,
            ];
        }


        if ($is_smart_lazy_trim && !$include_original && !empty($variants)) {
            $has_parent = false;
            foreach ($variants as $v) {
                if (!empty($v['parent'])) { $has_parent = true; break; }
            }
            if (!$has_parent) {
                $largest_idx = 0;
                $largest_w = 0;
                foreach ($variants as $idx => $v) {
                    $w = isset($v['maxWidth']) ? (int) $v['maxWidth'] : 0;
                    if ($w > $largest_w) {
                        $largest_w = $w;
                        $largest_idx = $idx;
                    }
                }
                $variants[$largest_idx]['parent'] = true;
            }
        }


        update_post_meta($imageID, '_wpc_compress_started_at', time());

        


        delete_transient('wpc_v2_pending_' . $imageID);


        delete_transient('wpc_v2_callbacks_blocked_' . $imageID);


        $expected_now = 0;
        if (!empty($variants) && is_array($dispatch_options['formats'] ?? null)) {
            $expected_now = count($variants) * max(1, count($dispatch_options['formats']));
        }
        update_post_meta($imageID, 'ic_compressing', [
            'status'            => 'optimizing',
            'expected_variants' => $expected_now,
            'time'              => time(),
        ]);
        wp_cache_delete($imageID, 'post_meta');

        $t0 = microtime(true);


        
        
        set_transient('wpc_v2_t0_ms_' . $imageID, (int) round($t0 * 1000), 1800);


        delete_transient('wpc_v2_phase_a_done_' . $imageID);


        delete_transient('wpc_v2_bg_retry_count_' . $imageID);
        delete_transient('wpc_v2_bg_retry_fired_' . $imageID);


        $env_formats = ['jpeg', 'webp', 'avif'];
        if (apply_filters('wpc_envelope_formats_v2', (string) get_option('wpc_envelope_formats_v2', '1') === '1')) {
            $s_fmt    = get_option(WPS_IC_SETTINGS);
            $s_fmt    = is_array($s_fmt) ? $s_fmt : [];
            $ceil_fmt = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::effective_ceiling($s_fmt) : 'avif';
            $env_formats = ['jpeg'];
            if ($ceil_fmt === 'webp' || $ceil_fmt === 'avif' || !empty($s_fmt['generate_webp'])) $env_formats[] = 'webp';
            if ($ceil_fmt === 'avif' || !empty($s_fmt['picture_avif'])) $env_formats[] = 'avif';
        }

        $client = new WPS_LocalV2($apikey, $orchestrator_url);
        $dispatch_options = array_merge([
            'level'          => 'intelligent',
            'formats'        => $env_formats,
            'triggerContext' => 'media-library-click',
            'callback_url'   => rest_url('wpc/v2/bg_swap'),
            'force_url_source' => self::wpc_v2_prefer_source_url(),
        ], $option_overrides);

        return [
            'ok'       => true,
            'client'   => $client,
            'variants' => $variants,
            'options'  => $dispatch_options,
            't0'       => $t0,
        ];
    }


    private static function compute_variant_dimensions_string($key, $imageID, $cached_meta = null)
    {
        $key = (string) $key;
        $imageID = (int) $imageID;
        if ($imageID <= 0) return '';

        
        $base = preg_replace('/-(avif|webp|png|jpe?g)$/i', '', $key);

        
        if ($cached_meta === null) {
            $cached_meta = wp_get_attachment_metadata($imageID);
        }
        if (!is_array($cached_meta)) $cached_meta = [];

        
        if (preg_match('/^wpc_(\d+)$/i', $base, $m)) {
            $w = (int) $m[1];
            $h = self::compute_proportional_height_for_modal($imageID, $w, $cached_meta);
            return $h > 0 ? ($w . '×' . $h) : ($w . '×?');
        }
        if (preg_match('/^(\d+)w$/i', $base, $m)) {
            $w = (int) $m[1];
            $h = self::compute_proportional_height_for_modal($imageID, $w, $cached_meta);
            return $h > 0 ? ($w . '×' . $h) : ($w . '×?');
        }

        
        if (!empty($cached_meta['sizes'][$base]['width']) && !empty($cached_meta['sizes'][$base]['height'])) {
            return (int) $cached_meta['sizes'][$base]['width'] . '×' . (int) $cached_meta['sizes'][$base]['height'];
        }


        
        
        if (preg_match('/^(\d+)x(\d+)$/i', $base, $m)) {
            return (int) $m[1] . '×' . (int) $m[2];
        }

        


        $base_lower = strtolower($base);
        if ($base_lower === 'original' || $base_lower === 'unscaled') {
            $sw = 0; $sh = 0;
            if (function_exists('wp_get_original_image_path')) {
                $orig_path = wp_get_original_image_path($imageID);
                if ($orig_path && file_exists($orig_path)) {
                    $sz = @getimagesize($orig_path);
                    if (is_array($sz) && !empty($sz[0]) && !empty($sz[1])) {
                        $sw = (int) $sz[0]; $sh = (int) $sz[1];
                    }
                }
            }
            if (($sw <= 0 || $sh <= 0) && !empty($cached_meta['width']) && !empty($cached_meta['height'])) {
                $sw = (int) $cached_meta['width']; $sh = (int) $cached_meta['height'];
            }
            if ($sw <= 0 || $sh <= 0) return '';

            $wpsic_opts = get_option('wps_ic');
            $maxw = (is_array($wpsic_opts) && !empty($wpsic_opts['maxWidth'])) ? (int) $wpsic_opts['maxWidth'] : 2560;
            if ($maxw < 1) $maxw = 2560;

            if (function_exists('wp_constrain_dimensions')) {
                $cd = wp_constrain_dimensions($sw, $sh, $maxw, $maxw);
                if (is_array($cd) && !empty($cd[0]) && !empty($cd[1])) {
                    return (int) $cd[0] . '×' . (int) $cd[1];
                }
            }
            
            $longest = max($sw, $sh);
            if ($longest > $maxw) {
                $scale = $maxw / $longest;
                $sw = (int) round($sw * $scale);
                $sh = (int) round($sh * $scale);
            }
            return $sw . '×' . $sh;
        }

        
        if ($base_lower === 'scaled') {
            if (!empty($cached_meta['width']) && !empty($cached_meta['height'])) {
                return (int) $cached_meta['width'] . '×' . (int) $cached_meta['height'];
            }
            return '';
        }

        
        if ($base_lower === 'thumb' && !empty($cached_meta['sizes']['thumbnail']['width'])) {
            return (int) $cached_meta['sizes']['thumbnail']['width'] . '×' . (int) $cached_meta['sizes']['thumbnail']['height'];
        }

        
        return '';
    }

    





    private static function compute_proportional_height_for_modal($imageID, $width, $meta)
    {
        $width = (int) $width;
        if ($width <= 0) return 0;

        $src_w = 0; $src_h = 0;
        if (function_exists('wp_get_original_image_path')) {
            $orig = wp_get_original_image_path($imageID);
            if ($orig && file_exists($orig)) {
                $sz = @getimagesize($orig);
                if (is_array($sz) && !empty($sz[0]) && !empty($sz[1])) {
                    $src_w = (int) $sz[0]; $src_h = (int) $sz[1];
                }
            }
        }
        if ($src_w === 0 || $src_h === 0) {
            if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) {
                $src_w = (int) $meta['width']; $src_h = (int) $meta['height'];
            }
        }
        if ($src_w === 0 || $src_h === 0) return 0;
        return (int) round($width * ($src_h / $src_w));
    }


    private static function format_variant_dimensions($key, $imageID)
    {
        $key = (string) $key;

        
        $base = preg_replace('/-(avif|webp|png|jpe?g)$/i', '', $key);

        
        if (preg_match('/^wpc_(\d+)$/i', $base, $m)) {
            return $m[1] . 'w';
        }

        
        if (preg_match('/^(\d+)w$/i', $base)) {
            return $base;
        }


        if ($base === '1536x1536' || $base === '2048x2048') {
            $meta_fd = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $imageID) : false;
            if (is_array($meta_fd) && !empty($meta_fd['sizes'][$base]['width']) && !empty($meta_fd['sizes'][$base]['height'])) {
                return (int) $meta_fd['sizes'][$base]['width'] . 'x' . (int) $meta_fd['sizes'][$base]['height'];
            }
            return $base === '1536x1536' ? 'Max 1536' : 'Max 2048';
        }

        
        
        return ucfirst(str_replace(['_', '-'], ' ', $base));
    }

    


    public function wps_ic_image_stats()
    {


        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized');
        }

        $imageID = absint($_POST['attachment_id'] ?? 0);
        if (!$imageID) wp_send_json_error();

        $variants   = get_post_meta($imageID, 'ic_local_variants', true);

        if (empty($variants) || !is_array($variants)) {
            wp_send_json_error(['msg' => 'No variant data available']);
        }


        $display_signature = '';
        foreach ($variants as $vkey => $vdata) {
            $display_signature .= $vkey . ':'
                . (int) ($vdata['size'] ?? 0) . ':'
                . (int) ($vdata['originalSize'] ?? 0) . ':'
                . (int) ($vdata['savings'] ?? 0) . ':'
                . (!empty($vdata['bg_no_improvement']) ? '1' : '0') . ';';
        }
        $variants_hash = md5($display_signature);


        $cache_key     = 'wpc_stats_html_v13_' . $imageID . '_' . $variants_hash;
        $cached_html   = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            wp_send_json_success(['html' => $cached_html, 'cached' => true]);
        }

        $_render_start = microtime(true);

        $ic_savings = get_post_meta($imageID, 'ic_savings', true);
        $ic_base    = get_post_meta($imageID, 'ic_savings_baseline', true);
        $ic_bytes   = get_post_meta($imageID, 'ic_savings_bytes', true);
        $ic_format  = get_post_meta($imageID, 'ic_savings_format', true);
        $ic_ai      = get_post_meta($imageID, 'ic_ai_meta', true);
        $title      = get_the_title($imageID);

        if (empty($variants) || !is_array($variants)) {
            wp_send_json_error(['msg' => 'No variant data available']);
        }

        
        $quality_grade = '';
        if (!empty($ic_ai['ssim'])) {
            $ssim = floatval($ic_ai['ssim']);
            if ($ssim >= 0.999) $quality_grade = 'A+';
            elseif ($ssim >= 0.997) $quality_grade = 'A';
            elseif ($ssim >= 0.995) $quality_grade = 'A-';
            elseif ($ssim >= 0.99) $quality_grade = 'B+';
            else $quality_grade = 'B';
        }

        
        $brand_svg = class_exists('whtlbl_whitelabel_plugin')
            ? '<svg width="28" height="28" viewBox="0 0 640 512" fill="currentColor"><path d="M528-16l-32 0 0 64-64 0 0 32 64 0 0 64 32 0 0-64 64 0 0-32-64 0 0-64zM288 320c80.6-35.8 128.6-57.2 144-64-15.4-6.8-63.4-28.2-144-64-35.8-80.6-57.2-128.6-64-144-6.8 15.4-28.2 63.4-64 144-80.6 35.8-128.6 57.2-144 64 15.4 6.8 63.4 28.2 144 64 35.8 80.6 57.2 128.6 64 144 6.8-15.4 28.2-63.4 64-144zm-64 65.2l-34.8-78.2-5-11.2-11.2-5-78.2-34.8 78.2-34.8 11.2-5 5-11.2 34.8-78.2 34.8 78.2 5 11.2 11.2 5 78.2 34.8-78.2 34.8-11.2 5-5 11.2-34.8 78.2zM496 384l0-16-32 0 0 64-64 0 0 32 64 0 0 64 32 0 0-64 64 0 0-32-64 0 0-48z"/></svg>'
            : '<svg width="28" height="28" viewBox="0 0 512 512" fill="currentColor"><path d="M322.4 192C358.9 59.4 379.4-15.3 384-32L340.9 3.9 38.4 256 0 288 198.4 288 189.6 320c-36.5 132.6-57 207.3-61.6 224l43.1-35.9 302.5-252.1 38.4-32-198.4 0 8.8-32zm101.2 64L185.9 454.1c34.3-124.6 52.4-190.6 54.5-198.1l-152 0 237.7-198.1C291.8 182.5 273.7 248.5 271.6 256l152 0z"/></svg>';


        $wp_meta      = wp_get_attachment_metadata($imageID);
        $wp_orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        $backup_dir   = WP_CONTENT_DIR . '/wpc-backups/';
        $attached_rel = is_array($wp_meta) && !empty($wp_meta['file']) ? $wp_meta['file'] : '';


        $canonical_orig = function ($base) use ($imageID, $wp_meta, $variants) {
            if (class_exists('WPC_Modern_Delivery')
                && method_exists('WPC_Modern_Delivery', 'canonical_original_size')) {
                return WPC_Modern_Delivery::canonical_original_size($imageID, $base, $wp_meta, $variants);
            }
            return 0;
        };


        $full_orig = 0;
        foreach (['scaled', 'full', 'original'] as $fb) {
            $full_orig = (int) $canonical_orig($fb);
            if ($full_orig > 0) break;
        }
        if ($full_orig <= 0 && function_exists('get_attached_file')) {
            $af = get_attached_file($imageID);
            if (is_string($af) && $af !== '' && @file_exists($af)) $full_orig = (int) @filesize($af);
        }
        if ($full_orig <= 0 && function_exists('wp_get_original_image_path')) {
            $op = wp_get_original_image_path($imageID);
            if (is_string($op) && $op !== '' && @file_exists($op)) $full_orig = (int) @filesize($op);
        }

        
        $optimized_rows = [];
        $skipped_rows = [];
        foreach ($variants as $label => $data) {
            $opt        = intval($data['size'] ?? 0);
            $is_skipped = !empty($data['skipped']);


            $base = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $label);
            $orig = $canonical_orig($base);

            


            if ($orig <= 0) {
                $orig = intval($data['originalSize'] ?? 0);
            }


            if ($orig <= 0 && $opt > 0) {
                foreach ($variants as $sib_key => $sib_data) {
                    $sib_base = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $sib_key);
                    if ($sib_base === $base && (int) ($sib_data['originalSize'] ?? 0) > 0) {
                        $orig = (int) $sib_data['originalSize'];
                        break;
                    }
                }
            }


            $orig_is_full = false;
            if ($orig <= 0 && $full_orig > 0) { $orig = $full_orig; $orig_is_full = true; }

            
            


            $pct = ($orig > 0 && $opt > 0 && $opt < $orig)
                ? round((1 - $opt / $orig) * 100, 2)
                : 0;


            if ($opt <= 0) continue;
            if ($orig > 0 && !$orig_is_full && $pct >= 99.9) continue;

            $fmt_class = 'wpc-fmt-jpeg';
            $fmt_label = 'JPEG';
            if (strpos($label, 'webp') !== false) { $fmt_class = 'wpc-fmt-webp'; $fmt_label = 'WebP'; }
            elseif (strpos($label, 'avif') !== false) { $fmt_class = 'wpc-fmt-avif'; $fmt_label = 'AVIF'; }
            elseif (strpos($label, 'png') !== false) { $fmt_class = 'wpc-fmt-png'; $fmt_label = 'PNG'; }


            $display_label = self::format_variant_dimensions($label, $imageID);


            $dimensions_str = self::compute_variant_dimensions_string($label, $imageID, $wp_meta);


            $t_ms = isset($data['bg_t_from_click_ms']) ? (int) $data['bg_t_from_click_ms'] : 0;

            $row = ['orig' => $orig, 'opt' => $opt, 'pct' => $pct, 'fmt_class' => $fmt_class, 'fmt_label' => $fmt_label, 'display_label' => $display_label, 'dimensions' => $dimensions_str, 't_ms' => $t_ms];
            if ($is_skipped) { $skipped_rows[] = $row; } else { $optimized_rows[] = $row; }
        }
        usort($optimized_rows, function($a, $b) { return $b['pct'] <=> $a['pct']; });


        $html  = '<style>';


        $html .= 'body .swal2-popup.wpc-stats-swal,body .wpc-stats-swal.swal2-popup{';
        $html .= 'padding:30px 28px 24px !important;border-radius:14px !important;';
        $html .= 'max-height:calc(100vh - 80px) !important;margin:40px auto !important;max-width:calc(100% - 32px) !important;';
        $html .= 'overflow:hidden !important;animation:none !important;transform:none !important;}';


        $html .= '.swal2-container:has(.wpc-stats-swal){left:160px !important;right:0 !important;width:auto !important;}';
        $html .= 'body.folded .swal2-container:has(.wpc-stats-swal),body.auto-fold .swal2-container:has(.wpc-stats-swal){left:36px !important;}';
        $html .= '@media screen and (max-width:782px){.swal2-container:has(.wpc-stats-swal){left:0 !important;}}';
        
        $html .= 'body .wpc-stats-swal .swal2-html-container,body .wpc-stats-swal .swal2-content{';
        $html .= 'max-height:calc(85vh - 120px) !important;overflow-y:auto !important;';


        $html .= 'margin:0 -18px 0 0 !important;padding:0 18px 0 0 !important;text-align:left !important;}';
        
        $html .= '.wpc-stats-swal .swal2-html-container::-webkit-scrollbar,';
        $html .= '.wpc-stats-swal .swal2-content::-webkit-scrollbar{width:6px;}';
        $html .= '.wpc-stats-swal .swal2-html-container::-webkit-scrollbar-track,';
        $html .= '.wpc-stats-swal .swal2-content::-webkit-scrollbar-track{background:transparent;}';
        $html .= '.wpc-stats-swal .swal2-html-container::-webkit-scrollbar-thumb,';
        $html .= '.wpc-stats-swal .swal2-content::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.12);border-radius:3px;}';
        $html .= '.wpc-stats-swal .swal2-html-container::-webkit-scrollbar-thumb:hover,';
        $html .= '.wpc-stats-swal .swal2-content::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,0.22);}';
        $html .= '.wpc-stats-swal .swal2-html-container,.wpc-stats-swal .swal2-content{';
        $html .= 'scrollbar-width:thin;scrollbar-color:rgba(0,0,0,0.12) transparent;}';
        
        $html .= '.wpc-stats-swal .swal2-close{top:12px !important;right:16px !important;font-size:24px !important;}';
        
        $html .= '.wpc-stats-modal{padding:0;}';
        
        $html .= '.wpc-stats-modal-header{padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid #eef1f5;}';


        $html .= '.wpc-stats-swal .wpc-stats-grid{border-collapse:separate !important;border-spacing:0 !important;}';


        $html .= 'body .wpc-stats-swal .wpc-stats-grid thead th,body .wpc-stats-swal .wpc-stats-grid thead tr th{';
        $html .= 'position:sticky !important;top:0 !important;background:#fff !important;z-index:4 !important;';
        $html .= 'box-shadow:0 1px 0 #eef1f5 !important;}';

        $html .= '.wpc-th-tplus,.wpc-td-tplus{display:none !important;}';
        $html .= '</style>';
        $html .= '<div class="wpc-stats-modal">';
        $html .= '<div class="wpc-stats-modal-header"><div>';
        $html .= '<h2 class="wpc-stats-modal-title">' . esc_html__('Optimization Results', 'wp-compress-image-optimizer') . '</h2>';
        $html .= '<p class="wpc-stats-modal-sub">' . esc_html($title) . ' &middot; #' . (int) $imageID . ' &middot; ' . esc_html(count($variants)) . ' ' . esc_html__('variants', 'wp-compress-image-optimizer') . '</p>';
        $html .= '</div></div>';
        $html .= '<div class="wpc-stats-table-wrap"><table class="wpc-stats-grid" id="wpc-stats-table">';
        $html .= '<thead><tr>';
        $html .= '<th class="wpc-th-variant">' . esc_html__('Variant', 'wp-compress-image-optimizer') . '</th>';
        
        
        $html .= '<th class="wpc-th-dimensions">' . esc_html__('Dimensions', 'wp-compress-image-optimizer') . '</th>';
        
        

        
        
        $html .= '<th class="wpc-th-tplus" style="display:none">T+</th>';
        $html .= '<th class="wpc-th-orig">' . esc_html__('Original', 'wp-compress-image-optimizer') . '</th>';
        $html .= '<th class="wpc-th-opt">' . esc_html__('Optimized', 'wp-compress-image-optimizer') . '</th>';
        $html .= '<th class="wpc-th-savings wpc-th-active-sort">' . esc_html__('Savings', 'wp-compress-image-optimizer') . ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></th>';
        $html .= '</tr></thead><tbody>';

        
        $fmt_tplus = function ($ms) {
            if (!$ms || $ms <= 0) return '—';
            return number_format($ms / 1000, 2) . 's';
        };

        
        foreach ($optimized_rows as $r) {
            $html .= '<tr class="wpc-row-enter">';
            $html .= '<td class="wpc-td-variant"><div class="wpc-cell-variant"><span class="wpc-format-badge ' . esc_attr($r['fmt_class']) . '">' . esc_html($r['fmt_label']) . '</span><span class="wpc-variant-name">' . esc_html($r['display_label']) . '</span></div></td>';
            $html .= '<td class="wpc-td-dimensions wpc-size-muted">' . esc_html($r['dimensions']) . '</td>';
            $html .= '<td class="wpc-td-tplus wpc-size-muted" style="display:none">' . esc_html($fmt_tplus($r['t_ms'])) . '</td>';
            $html .= '<td class="wpc-size-muted">' . ($r['orig'] > 0 ? esc_html(wps_ic_format_bytes($r['orig'])) : '&mdash;') . '</td>';
            $html .= '<td class="wpc-size-opt">' . esc_html(wps_ic_format_bytes($r['opt'])) . '</td>';

            if ($r['orig'] > 0) {
                $html .= '<td class="wpc-td-savings"><div class="wpc-cell-savings"><span class="wpc-savings-pct">' . esc_html(number_format($r['pct'], 1)) . '%</span><div class="wpc-bar-track"><div class="wpc-bar-fill" data-target="' . $r['pct'] . '"></div></div></div></td>';
            } else {
                $html .= '<td class="wpc-td-savings"><div class="wpc-cell-savings"><span class="wpc-savings-pct wpc-size-muted">&mdash;</span></div></td>';
            }
            $html .= '</tr>';
        }

        
        if (!empty($skipped_rows)) {
            $html .= '<tr class="wpc-skipped-toggle-row"><td colspan="6">';
            $html .= '<button class="wpc-skipped-toggle-btn" onclick="(function(b){var r=document.querySelectorAll(\'.wpc-skipped-row\'),s=b.querySelector(\'span\'),v=b.classList.toggle(\'is-active\');r.forEach(function(el,i){if(v){setTimeout(function(){el.classList.add(\'is-visible\')},i*30)}else{el.classList.remove(\'is-visible\')}});s.textContent=v?\'Hide skipped variants\':\'Show ' . count($skipped_rows) . ' skipped variants\'})(this)">';
            $html .= '<span>' . sprintf(esc_html__('Show %d skipped variants', 'wp-compress-image-optimizer'), count($skipped_rows)) . '</span>';
            $html .= '<svg class="wpc-skipped-toggle-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
            $html .= '</button></td></tr>';

            
            foreach ($skipped_rows as $r) {
                $html .= '<tr class="wpc-skipped-row">';
                $html .= '<td class="wpc-td-variant"><div class="wpc-cell-variant"><span class="wpc-format-badge ' . esc_attr($r['fmt_class']) . '">' . esc_html($r['fmt_label']) . '</span><span class="wpc-variant-name">' . esc_html($r['display_label']) . '</span></div></td>';
                $html .= '<td class="wpc-td-dimensions wpc-size-muted">' . esc_html($r['dimensions']) . '</td>';
                $html .= '<td class="wpc-td-tplus wpc-size-muted" style="display:none">' . esc_html($fmt_tplus($r['t_ms'])) . '</td>';
                $html .= '<td class="wpc-size-muted">' . esc_html(wps_ic_format_bytes($r['orig'])) . '</td>';
                $html .= '<td class="wpc-size-muted">' . esc_html(wps_ic_format_bytes($r['orig'])) . '</td>';
                $html .= '<td class="wpc-td-savings"><div class="wpc-cell-savings"><span class="wpc-skipped-badge">' . esc_html__('Skipped — optimal', 'wp-compress-image-optimizer') . '</span></div></td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div></div>';


        if (isset($variants_hash, $cache_key) && isset($_render_start)) {
            $_render_ms = (int) round((microtime(true) - $_render_start) * 1000);
            set_transient($cache_key, $html, 300);


            error_log('[WPC StatsModal] imageID=' . $imageID . ' cold_render_ms=' . $_render_ms . ' variants=' . count($variants) . ' html_kb=' . round(strlen($html) / 1024));
        }

        wp_send_json_success(['html' => $html]);
    }

    


    public function wps_ic_count_uncompressed_images()
    {
        if (!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings') && !current_user_can('manage_options')) {
            wp_send_json_error('Forbidden.');
        }

        
        $args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids',
            'posts_per_page' => (int) apply_filters('wpc_uncompressed_count_cap', 2000),
            'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
            'meta_query' => ['relation' => 'AND', ['key' => 'wps_ic_data', 'compare' => 'NOT EXISTS'], ['key' => 'wps_ic_exclude', 'compare' => 'NOT EXISTS']]];

        $uncompressed_attachments = new WP_Query($args);
        $total_file_size = 0;
        foreach ((array) $uncompressed_attachments->posts as $postID) {
            $wpc_meta330 = wp_get_attachment_metadata($postID);
            if (is_array($wpc_meta330) && !empty($wpc_meta330['filesize'])) {
                $total_file_size += (int) $wpc_meta330['filesize'];
            } else {
                $wpc_fs330 = @filesize((string) get_attached_file($postID));
                $total_file_size += (int) $wpc_fs330;
            }
        }

        wp_send_json_success(['uncompressed' => $total_file_size, 'unit' => 'Bytes']);
    }

    public function wps_ic_save_mode()
    {
        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['nonce'], 'wpc_save_mode')) {
            wp_send_json_error('Forbidden.');
        }
        $preset = sanitize_text_field($_POST['mode']);
        $cdn = sanitize_text_field($_POST['cdn']);
        $options = new wps_ic_options();
        $settings = $options->get_preset($preset);


        if ($cdn == 'true') {
            $settings['live-cdn'] = '1';
            $settings['serve'] = ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1', 'fonts' => '1'];
            $settings['css'] = 1;
            $settings['js'] = 1;
            $settings['fonts'] = 1;
            $settings['generate_adaptive'] = 1;
            $settings['generate_webp'] = 1;


            $settings['picture_webp'] = 1;
            $settings['picture_avif'] = 1;
            $settings['wpc_nextgen'] = 'auto';
            $settings['retina'] = 1;
        } else {
            $settings['live-cdn'] = '0';
            $settings['serve'] = ['jpg' => '0', 'png' => '0', 'gif' => '0', 'svg' => '0', 'fonts' => '0'];
            $settings['css'] = 0;
            $settings['js'] = 0;
            $settings['fonts'] = 0;
            $settings['generate_adaptive'] = 0;
            $settings['generate_webp'] = 0;
            $settings['retina'] = 0;
        }

        
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->save_mode($apikey, $preset, $cdn);
            }
            wp_send_json_success();
        }

        $wpc_excludes = get_option('wpc-inline');
        $wpc_excludes['inline_js'] = explode(',', "jquery.min,adaptive,jquery-migrate,wp-includes");
        update_option('wpc-inline', $wpc_excludes);

        $wpc_excludes = get_option('wpc-excludes');
        $wpc_excludes['delay_js'] = [];
        update_option('wpc-excludes', $wpc_excludes);

        if (function_exists('wpc_preset_cache_gate67')) {
            $settings = wpc_preset_cache_gate67($settings);
        }

        update_option(WPS_IC_SETTINGS, $settings);
        update_option(WPS_IC_PRESET, $preset);

        
        $cacheLogic = new wps_ic_cache();

        
        delete_option('wps_ic_gen_hp_url');

        if (!class_exists('wps_ic_htaccess')) {
            include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }

        $htaccess = new wps_ic_htaccess();

        if ($preset == 'safe') {
            $htaccess->removeHtaccessRules();
            $htaccess->removeAdvancedCache();
            $htaccess->setWPCache(false);
        } elseif (!empty($settings['cache']['advanced'])) {
            
            
            $htaccess->setWPCache(true);
            $htaccess->setAdvancedCache();
        }

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll(false, true, false, true, true);

        if (!empty($_POST['activation']) && $_POST['activation']) {
            $warmup_class = new wps_ic_preload_warmup();
            $warmup_class->optimizeSingle('home');
        }

        wp_send_json_success();
    }

    public function wps_ic_get_per_page_settings_html()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $id = sanitize_text_field($_POST['id']);

        $wpc_excludes = get_option('wpc-excludes', []);
        $settings = isset($wpc_excludes['per_page_settings'][$id]) ? $wpc_excludes['per_page_settings'][$id] : [];

        if (isset($settings['skip_lazy'])) {
            $skip_lazy = $settings['skip_lazy'];
        } else {
            $skip_lazy = '';
        }

        if (isset($settings['purge_on_new_post'])) {
            $purge_on_new_post = 'checked';
        } else {
            $purge_on_new_post = '';
        }

        
        $html = '<div class="cdn-popup-loading" style="display: none;">';
        $html .= '<div class="wpc-popup-saving-logo-container">';
        $html .= '<div class="wpc-popup-saving-preparing-logo">';
        $html .= '<img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>';
        $html .= '<span class="wpc-ic-popup-logo-saving-loader" aria-hidden="true"></span>'; 
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="cdn-popup-content">';
        $html .= '<div class="cdn-popup-top">';
        $html .= '<div class="inline-heading">';
        $html .= '<div class="inline-heading-icon">';
        $html .= '<img src="' . WPS_IC_URI . 'assets/images/icon-exclude-from-cdn.svg"/>';
        $html .= '</div>';
        $html .= '<div class="inline-heading-text">';
        $html .= '<h3>Per Page Settings</h3>';
        $html .= '<p>These settings will apply only to the current page.</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<form method="post" class="wpc-save-popup-data" action="#">';
        $html .= '<div class="cdn-popup-content-full">';
        $html .= '<div class="cdn-popup-content-inner">';
        $html .= '<div class="wps-default-excludes-container">';

        $html .= '<div style="display:flex;align-items:baseline;">';
        $html .= '<strong>Skip Lazy Loading: &nbsp</strong>';
        $html .= '<p>Skip &nbsp</p> <input type="number" class="per_page_lazy_skip" min="0" max="99" value="' . $skip_lazy . '"/> <p>&nbsp Images</p>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="wps-default-excludes-container">';
        $html .= '<div class="wps-default-excludes-enabled-checkbox-container" style="padding-left: 0">';
        $html .= '<input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-purge-on-new-post" ' . $purge_on_new_post . '>';
        $html .= '<p>Purge cache on new post</p>';
        $html .= '</div>';


        $html .= '</div>';
        $html .= '<div class="wps-empty-row">&nbsp;</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<a href="#" class="btn btn-primary btn-active btn-save btn-exclude-pages-save">' . esc_html__('Save', WPS_IC_TEXTDOMAIN) . '</a>';
        $html .= '</form>';
        $html .= '</div>';


        
        wp_send_json_success(['html' => $html]);
    }

    public function wps_ic_save_per_page_settings()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (empty($_POST['id'])) {
            wp_send_json_error('Forbidden.');
        }

        $id = sanitize_text_field($_POST['id']);
        $skip_lazy = false;
        $purge_on_new_post = false;

        if (isset($_POST['skip_lazy'])) {
            $skip_lazy = sanitize_text_field($_POST['skip_lazy']);
        }

        if (isset($_POST['purge_on_new_post'])) {
            $purge_on_new_post = sanitize_text_field($_POST['purge_on_new_post']);
        }

        $wpc_excludes = get_option('wpc-excludes', []);

        if (!isset($wpc_excludes['per_page_settings'])) {
            $wpc_excludes['per_page_settings'] = [];
        }

        if (empty($wpc_excludes['per_page_settings'][$id])) {
            $wpc_excludes['per_page_settings'][$id] = [];
        }

        if ($purge_on_new_post != 'false') {
            $wpc_excludes['per_page_settings'][$id]['purge_on_new_post'] = $skip_lazy;
        } else {
            unset($wpc_excludes['per_page_settings'][$id]['purge_on_new_post']);
        }

        if ($skip_lazy !== false) {
            $wpc_excludes['per_page_settings'][$id]['skip_lazy'] = $skip_lazy;
        } else {
            unset($wpc_excludes['per_page_settings'][$id]['skip_lazy']);
        }

        
        update_option('wpc-excludes', $wpc_excludes);

        if ($id == 'home') {
            $url = site_url();
        } else {
            $url = get_permalink($id);
        }
        $keys = new wps_ic_url_key();
        $url_key = $keys->setup($url);

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll($url_key);


        wp_send_json_success($url_key);

    }

    public function wps_ic_get_page_excludes_popup_html()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $id = sanitize_text_field($_POST['id']);
        $setting = sanitize_text_field($_POST['setting']);

        
        $wpc_excludes = get_option('wpc-excludes', []);
        $excludes = isset($wpc_excludes['page_excludes_files'][$id]) ? $wpc_excludes['page_excludes_files'][$id] : [];

        if (!empty($excludes[$setting])) {
            $current_excludes = implode("\n", $excludes[$setting]);
        } else {
            $current_excludes = '';
        }

        $setting_name = ['cdn' => esc_html__('CDN', WPS_IC_TEXTDOMAIN), 'adaptive' => esc_html__('Adaptive Images', WPS_IC_TEXTDOMAIN), 'advanced_cache' => esc_html__('Advanced Cache', WPS_IC_TEXTDOMAIN), 'critical_css' => esc_html__('Critical CSS', WPS_IC_TEXTDOMAIN), 'delay_js' => esc_html__('JavaScript', WPS_IC_TEXTDOMAIN), 'delay_js_v2' => esc_html__('JavaScript', WPS_IC_TEXTDOMAIN)];

        
        $html = '<div class="cdn-popup-loading" style="display: none;">';
        $html .= '<div class="wpc-popup-saving-logo-container">';
        $html .= '<div class="wpc-popup-saving-preparing-logo">';
        $html .= '<img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>';
        $html .= '<span class="wpc-ic-popup-logo-saving-loader" aria-hidden="true"></span>'; 
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="cdn-popup-content">';
        $html .= '<div class="cdn-popup-top">';
        $html .= '<div class="inline-heading">';
        $html .= '<div class="inline-heading-icon">';
        $html .= '<img src="' . WPS_IC_URI . 'assets/images/icon-exclude-from-cdn.svg"/>';
        $html .= '</div>';
        $html .= '<div class="inline-heading-text">';
        $html .= '<h3>' . sprintf(esc_html__('Exclude from %s', WPS_IC_TEXTDOMAIN), $setting_name[$setting]) . '</h3>';
        $html .= '<p>' . esc_html__('List files or paths to exclude. Partial names work too — we match automatically.', WPS_IC_TEXTDOMAIN) . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<form method="post" class="wpc-save-popup-data" action="#">';
        $html .= '<div class="cdn-popup-content-full">';
        $html .= '<div class="cdn-popup-content-inner">';
        $html .= '<textarea name="exclude-pages" data-setting-name="' . $setting . '" data-page-id="' . $id . '" class="exclude-list-textarea-value" placeholder="' . esc_attr__('e.g. plugin-name/js/script.js, scripts.js, anyimage.jpg', WPS_IC_TEXTDOMAIN) . '">';
        $html .= esc_textarea($current_excludes);
        $html .= '</textarea>';
        $html .= '<div class="wps-empty-row">&nbsp;</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<a href="#" class="btn btn-primary btn-active btn-save btn-exclude-pages-save">' . esc_html__('Save', WPS_IC_TEXTDOMAIN) . '</a>';
        $html .= '<div class="wps-example-section">';
        $html .= '<button type="button" class="wps-example-toggle-btn">' . esc_html__('See Examples', WPS_IC_TEXTDOMAIN) . ' <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>';
        $html .= '<div class="wps-example-list" style="display:none;">';
        $html .= '<div><div>';
        $html .= '<p>' . esc_html__('.svg would exclude all assets with that extension', WPS_IC_TEXTDOMAIN) . '</p>';
        $html .= '<p>' . esc_html__('imagename would exclude any file with that name', WPS_IC_TEXTDOMAIN) . '</p>';
        $html .= '<p>' . esc_html__('/myplugin/image.jpg would exclude that specific file', WPS_IC_TEXTDOMAIN) . '</p>';
        $html .= '<p>' . esc_html__('/wp-content/myplugin/ would exclude everything using that path', WPS_IC_TEXTDOMAIN) . '</p>';
        $html .= '</div></div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';


        
        wp_send_json_success(['html' => $html]);
    }

    public function wps_ic_save_page_excludes_popup()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (empty($_POST['id']) || empty($_POST['setting'])) {
            wp_send_json_error('Forbidden.');
        }

        $id = sanitize_text_field($_POST['id']);
        $setting = sanitize_text_field($_POST['setting']);
        $excludes = $_POST['excludes'];
        $excludes = self::wpc_split_patterns19($excludes);

        
        $wpc_excludes = get_option('wpc-excludes', []);

        
        if (!isset($wpc_excludes['page_excludes_files'])) {
            $wpc_excludes['page_excludes_files'] = [];
        }

        if (empty($wpc_excludes['page_excludes_files'][$id])) {
            $wpc_excludes['page_excludes_files'][$id] = [];
        }

        $wpc_excludes['page_excludes_files'][$id][$setting] = $excludes;

        
        update_option('wpc-excludes', $wpc_excludes);

        if ($id == 'home') {
            $url = site_url();
        } else {
            $url = get_permalink($id);
        }
        $keys = new wps_ic_url_key();
        $url_key = $keys->setup(get_permalink($url));

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll($url_key);

        if ($setting == 'combine_js' || $setting == 'css_combine' || $setting == 'delay_js') {
            $cache::purgeCombinedFiles($url_key);
        }

        if ($setting == 'critical_css') {
            $cache::purgeCriticalFiles($url_key);
        }

        wp_send_json_success();
    }

    public function wps_ic_get_optimization_status_pages()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (isset($_POST['post_type']) && is_array($_POST['post_type'])) {
            $post_type = array_map('sanitize_text_field', $_POST['post_type']);
        } else {
            $post_type = ['page', 'post', 'product'];
        }

        $search = '';
        if (!empty($_POST['search'])) {
            $search = sanitize_text_field($_POST['search']);
        }

        $page = isset($_POST['page']) ? $_POST['page'] : 1;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = 10;

        $process_all = false;
        if (isset($_POST['post_status']) && is_array($_POST['post_status'])) {
            $post_status = array_map('sanitize_text_field', $_POST['post_status']);
            
            $process_all = true;
        } else {
            $post_status = ['optimized', 'skipped', 'unoptimized'];
        }

        $cf = get_option(WPS_IC_CF);
        $cfLive = false;
        if ($cf && isset($cf['settings'])) {
            $cfLive = ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0');
        }
        $allowLive = get_option('wps_ic_allow_live') && !$cfLive;
        if ($allowLive) {
            $allowLive = '1';
        }

        $warmup_class = new wps_ic_preload_warmup();
        if ($process_all) {
            $pages = $warmup_class->getPagesForFiltering($post_type, $post_status, $page, $offset, $search);
            $response = ['pages' => $pages['pages'], 'total_pages' => ceil($pages['total'] / 10), 'global_settings' => self::$settings, 'allow_live' => $allowLive];
        } else {
            $pages = $warmup_class->getOptimizationsStatus($post_type, $page, $offset, $limit, $search);

            wp_reset_postdata();
            $args = ['post_type' => $post_type, 'limit' => $limit, 'fields' => 'ids', 'post_status' => 'publish', 's' => $search];

            $query = new WP_Query($args);

            $response = ['pages' => $pages, 'total_pages' => $query->max_num_pages, 'global_settings' => self::$settings, 'allow_live' => $allowLive];
        }


        $locked = [];
        $locked['cdn'] = false;
        $locked['advanced_cache'] = false;
        $locked['adaptive'] = false;
        $locked['critical_css'] = false;
        $locked['delay_js'] = false;

        $response['locked'] = $locked;

        wp_send_json_success($response);
    }

    public function wps_ic_save_optimization_status()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $id = sanitize_text_field($_POST['id']);
        $setting_name = sanitize_text_field($_POST['setting_name']);
        $setting_action = sanitize_text_field($_POST['setting_action']);
        $changed = false;

        if ($setting_action == 'purge') {
            $keys = new wps_ic_url_key();
            if ($id == 'home') {
                $url_key = $keys->setup(home_url());
            } else {
                $url_key = $keys->setup(get_permalink($id));
            }

            $cache = new wps_ic_cache_integrations();

            if ($setting_name == 'combine_js' || $setting_name == 'css_combine' || $setting_name == 'delay_js') {
                $cache::purgeCombinedFiles($url_key);
            }
            if ($setting_name == 'critical_css') {
                
                
                
                
                
                
                $cache::purgeCriticalFiles($url_key);
                if (apply_filters('wpc_crit_purge_regenerates', true)) {
                    $GLOBALS['wpc_gen_force496'] = 1;
                    if (!class_exists('wps_criticalCss')) {
                        @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                    }
                    if (class_exists('wps_criticalCss')) {
                        try {
                            $wpc_cc507 = new wps_criticalCss();
                            $wpc_cc507->generateCriticalCSS($id, true);
                        } catch (\Throwable $e) {
                        }
                    }
                    unset($GLOBALS['wpc_gen_force496']);
                    
                    
                    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                        try { wps_ic_cache::cfPurgeAllHtml(true, true); } catch (\Throwable $e) {}
                    }
                }
            }

            $cache::purgeAll($url_key);
        } else if ($setting_action == 'generate' && $setting_name == 'critical_css') {
            
            $GLOBALS['wpc_gen_force496'] = 1;
            $critical = new wps_criticalCss();
            $critical->generateCriticalCSS($id, true);
            unset($GLOBALS['wpc_gen_force496']);
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                try { wps_ic_cache::cfPurgeAllHtml(true, true); } catch (\Throwable $e) {}
            }
        } else {

            $wpc_excludes = get_option('wpc-excludes', []);

            
            if (!isset($wpc_excludes['page_excludes'])) {
                $wpc_excludes['page_excludes'] = [];
            }


            
            if (!isset($wpc_excludes['page_excludes'][$id])) {
                $wpc_excludes['page_excludes'][$id] = [];
            }

            $current_value = isset($wpc_excludes['page_excludes'][$id][$setting_name]) ? $wpc_excludes['page_excludes'][$id][$setting_name] : null;
            if ($setting_action == 'force_on') {
                if ($current_value !== '1') {
                    $wpc_excludes['page_excludes'][$id][$setting_name] = '1';
                    $changed = true;
                }
            } elseif ($setting_action == 'force_off') {
                if ($current_value !== '0') {
                    $wpc_excludes['page_excludes'][$id][$setting_name] = '0';
                    $changed = true;
                }
            } elseif ($setting_action === 'global') {
                if ($current_value !== null) {
                    unset($wpc_excludes['page_excludes'][$id][$setting_name]);
                    $changed = true;
                }
            }


            if ($changed) {

                $keys = new wps_ic_url_key();
                if ($id == 'home') {
                    $url_key = $keys->setup(home_url());
                } else {
                    $url_key = $keys->setup(get_permalink($id));
                }

                $cache = new wps_ic_cache_integrations();

                if ($setting_name == 'combine_js' || $setting_name == 'css_combine' || $setting_name == 'delay_js') {
                    $cache::purgeCombinedFiles($url_key);
                }
                if ($setting_name == 'critical_css') {
                    $cache::purgeCriticalFiles($url_key);
                }

                $cache::purgeAll($url_key);

                
                update_option('wpc-excludes', $wpc_excludes);
            }
        }

        wp_send_json_success();
    }


    public function wpsRunQuickTest()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (empty(self::$options['api_key'])) {
            wp_send_json_error('not-connected');
        }

        if (get_transient('wpc_test_running')) {
            wp_send_json_error('already-running');
        }

        $id = sanitize_text_field($_POST['id']);
        $dash = true;

        set_transient('wpc_test_running', 'running', 5 * 60);

        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->optimizeSingle('home', true, $dash);
        wp_send_json_error('error');
    }


    public function wps_ic_run_single_optimization()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (empty(self::$options['api_key'])) {
            wp_send_json_error('not-connected');
        }

        $id = sanitize_text_field($_POST['id']);
        if (!empty($_POST['dash'])) {
            $dash = sanitize_text_field($_POST['dash']);
        } else {
            $dash = false;
        }


        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->optimizeSingle($id, true, $dash);
        wp_send_json_error('error');
    }


    public function wps_ic_resetTest()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $url = home_url();
        $url_key_class = new wps_ic_url_key();
        $url_key = $url_key_class->setup($url);

        
        
        $cache = new wps_ic_cache_integrations();
        $cache::purgeCacheFiles($url_key);

        $requests = new wps_ic_requests();

        $tests = get_option(WPS_IC_TESTS);
        unset($tests['home']);
        update_option(WPS_IC_TESTS, $tests);

        
        $history = get_option(WPS_IC_LITE_GPS_HISTORY);
        if (empty($history)) {
            $history = [];
        }
        $history[time()] = get_option(WPS_IC_LITE_GPS);
        update_option(WPS_IC_LITE_GPS_HISTORY, $history);

        
        delete_transient('wpc_test_running');
        delete_transient('wpc_initial_test');
        delete_option(WPS_IC_LITE_GPS);
        delete_option(WPC_WARMUP_LOG_SETTING);

        set_transient('wpc_initial_test', 'running', 5 * 60);

        
        $args = ['url' => home_url(), 'version' => self::$version, 'plugin_version' => self::$version, 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
        $response = $requests->POST(self::$PAGESPEED_URL_HOME, $args, ['timeout' => 5, 'blocking' => true, 'headers' => array('Content-Type' => 'application/json')]);

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['jobId'])) {
            $job_id = $data['jobId'];
            set_transient(WPS_IC_JOB_TRANSIENT, $job_id, 60 * 10);
            wp_send_json_success('started');
        } else {
            set_transient(WPS_IC_JOB_TRANSIENT, 'failed', 60 * 10);
        }

        wp_send_json_error();
    }


    public function wps_ic_optimize_start()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.']);
        }
        $desktop = (!empty($_POST['desktop']) && $_POST['desktop'] == '1') ? 1 : 0;


        if (function_exists('wpc_agency_forward_json')) {
            wpc_agency_forward_json('optimizeStart', ['desktop' => (string) $desktop]);
        }

        $res = self::optimizeStartRun($desktop);
        if (!empty($res['ok'])) {
            wp_send_json_success($res['data']);
        }
        wp_send_json_error($res['data']);
    }


    public static function optimizeStartRun($desktop)
    {
        $opts   = get_option(WPS_IC_OPTIONS);
        $apikey = (is_array($opts) && !empty($opts['api_key'])) ? $opts['api_key'] : '';
        if ($apikey === '') {
            return ['ok' => false, 'data' => ['msg' => 'Connect the site first — no API key found.']];
        }
        $desktop  = ((string) $desktop === '1') ? 1 : 0;
        $endpoint = defined('WPS_IC_OPTIMIZE_API_URL') ? WPS_IC_OPTIMIZE_API_URL : 'https://pagespeed.zapwp.net/optimize';
        $requests = new wps_ic_requests();
        $args = [
            'url'     => home_url('/'),
            'apikey'  => $apikey,
            'version' => str_replace('.', '', (string) wps_ic::$version),
            'desktop' => $desktop,
            'uuid'    => 'wpc-' . time() . '-' . mt_rand(1000, 9999),
        ];
        $response = $requests->POST($endpoint, $args, ['timeout' => 20, 'blocking' => true, 'headers' => ['Content-Type' => 'application/json']]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'data' => ['msg' => "Couldn't reach the optimizer service — " . $response->get_error_message()]];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($data) && !empty($data['run_id']) && (($data['status'] ?? '') === 'accepted')) {
            return ['ok' => true, 'data' => ['run_id' => (string) $data['run_id'], 'mode' => (string) ($data['mode'] ?? 'advisory_dry_run')]];
        }
        
        
        $raw = '';
        if (is_array($data)) {
            if (!empty($data['status']) && stripos((string) $data['status'], 'accepted') === false) {
                $raw = preg_replace('/^failed\s*-\s*/i', '', (string) $data['status']);
            } elseif (!empty($data['error'])) {
                $raw = (string) $data['error'];
            }
        }
        if ($raw === '') {
            $raw = 'http_' . $code;
        }
        return ['ok' => false, 'data' => ['msg' => self::wpc_optimize_error_text($raw), 'raw' => $raw]];
    }


    public function wps_ic_optimize_status()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'Forbidden.']);
        }
        $run_id = isset($_POST['run_id']) ? sanitize_text_field($_POST['run_id']) : '';

        if (function_exists('wpc_agency_forward_json')) {
            wpc_agency_forward_json('optimizeStatus', ['run_id' => $run_id]);
        }

        $res = self::optimizeStatusRun($run_id);
        if (!empty($res['ok'])) {
            wp_send_json_success($res['data']);
        }
        wp_send_json_error($res['data']);
    }


    public static function optimizeStatusRun($run_id)
    {
        $run_id = (string) $run_id;

        if ($run_id === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,120}$/', $run_id)) {
            return ['ok' => false, 'data' => ['msg' => 'Invalid run id.']];
        }
        $base     = defined('WPS_IC_OPTIMIZE_STATUS_API_URL') ? WPS_IC_OPTIMIZE_STATUS_API_URL : 'https://pagespeed.zapwp.net/optimize-status';
        $endpoint = add_query_arg(['run_id' => $run_id], $base);


        $response = wp_remote_get($endpoint, ['timeout' => 5, 'sslverify' => true, 'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'WPCompress']);
        if (is_wp_error($response)) {
            return ['ok' => false, 'data' => ['msg' => "Couldn't reach the optimizer — " . $response->get_error_message(), 'transient' => true]];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);


        if ($code === 404 || (is_array($data) && (($data['status'] ?? '') === 'not_found'))) {
            return ['ok' => true, 'data' => ['state' => 'pending']];
        }
        if ($code !== 200 || !is_array($data)) {
            return ['ok' => false, 'data' => ['msg' => 'Optimizer returned an unexpected response (HTTP ' . $code . ').', 'transient' => true]];
        }
        return ['ok' => true, 'data' => $data]; 
    }


    private static function wpc_optimize_error_text($raw)
    {
        $raw = strtolower(trim((string) $raw));
        $map = [
            'bad version provided' => 'The optimizer needs a newer plugin version — update WP Compress and retry.',
            'no api key provided'  => 'Connect the site first — no API key found.',
            'no url provided'      => 'Could not determine the site URL to scan.',
            'invalid url'          => 'The site URL looks invalid to the optimizer.',
            'blocked'              => 'This site is blocked from the optimizer — contact support.',
            'rate_limited'         => 'Daily advisory-scan limit reached — try again tomorrow.',
        ];
        foreach ($map as $needle => $text) {
            if (strpos($raw, $needle) !== false) {
                return $text;
            }
        }
        return 'The optimizer could not start this scan (' . esc_html($raw) . ').';
    }


    public function wps_ic_save_cache_cookies_settings()
    {

        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey          = sanitize_text_field($_POST['apikey'] ?? '');
            $cache_cookies   = array_values(array_filter(explode("\n", rtrim(sanitize_textarea_field($_POST['cache_cookies'] ?? ''), "\n"))));
            $exclude_cookies = array_values(array_filter(explode("\n", rtrim(sanitize_textarea_field($_POST['exclude_cookies'] ?? ''), "\n"))));
            $form            = ['cookies' => $cache_cookies, 'exclude_cookies' => $exclude_cookies];
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->saveRemoteCacheCookies($apikey, $form);
            }
            wp_send_json_success();
        }

        $cookies_setting = get_option('wps_ic_cache_cookies', []);

        $cache_cookies = sanitize_textarea_field($_POST['cache_cookies']);
        $cache_cookies = rtrim($cache_cookies, "\n");
        $cache_cookies = explode("\n", $cache_cookies);
        $cookies_setting['cookies'] = $cache_cookies;

        $exclude_cookies = sanitize_textarea_field($_POST['exclude_cookies']);
        $exclude_cookies = rtrim($exclude_cookies, "\n");
        $exclude_cookies = explode("\n", $exclude_cookies);
        $cookies_setting['exclude_cookies'] = $exclude_cookies;

        $updated = update_option('wps_ic_cache_cookies', $cookies_setting);

        if ($updated) {
            $cache = new wps_ic_cache_integrations();


            $cache::purgeAll(false, true, false, false, true);

            $settings = get_option(WPS_IC_SETTINGS);
            if (!empty($settings['cache']['advanced']) && $settings['cache']['advanced'] == '1') {
                if (!class_exists('wps_ic_htaccess')) {
                    include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                }

                $htaccess = new wps_ic_htaccess();
                $htaccess->setAdvancedCache();
            }
        }

        wp_send_json_success();
    }

    public function wps_ic_get_cache_cookies()
    {
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey          = sanitize_text_field($_POST['apikey'] ?? '');
            $cookies_setting = (!empty($apikey) && !empty($api) && !empty($api::$comms))
                               ? $api::$comms->getRemoteCacheCookies($apikey)
                               : [];
            $cache_cookies   = !empty($cookies_setting['cookies'])         ? implode("\n", $cookies_setting['cookies'])         : '';
            $exclude_cookies = !empty($cookies_setting['exclude_cookies'])  ? implode("\n", $cookies_setting['exclude_cookies'])  : '';
            wp_send_json_success(['cache_cookies' => $cache_cookies, 'exclude_cookies' => $exclude_cookies]);
        }

        $cookies_setting = get_option('wps_ic_cache_cookies');

        if ($cookies_setting === false) {
            $options = new wps_ic_options();
            $cookies_setting = $options->get_preset('cache_cookies');
            update_option('wps_ic_cache_cookies', $cookies_setting);
        }

        if (!empty($cookies_setting['cookies'])) {
            $cache_cookies = implode("\n", $cookies_setting['cookies']);
        }

        if (!empty($cookies_setting['exclude_cookies'])) {
            $exclude_cookies = implode("\n", $cookies_setting['exclude_cookies']);
        }

        wp_send_json_success(['cache_cookies' => $cache_cookies ?? '', 'exclude_cookies' => $exclude_cookies ?? '']);
    }


    public function wps_ic_run_tests()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        die();

        $id = sanitize_text_field($_POST['id']);
        $retest = sanitize_text_field($_POST['retest']);

        $warmup_class = new wps_ic_preload_warmup();
        if ($warmup_class->isOptimized($id) == '1') {
            $warmup_class->doTest($id, $retest, true);
            
        } else {
            $warmup_class->optimizeSingle($id);
        }
    }

    public function wps_ic_check_optimization_status()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (empty($apikey) || empty($api) || empty($api::$comms)) {
                wp_send_json_error('missing-apikey');
            }
            $data = $api::$comms->getRemoteOptimizationStatus($apikey);
            wp_send_json_success($data);
            return;
        }

        if (empty(self::$options['api_key'])) {
            wp_send_json_error('not-connected');
        }

        if (isset($_POST['optimize']) && is_array($_POST['optimize'])) {
            $optimize = array_map('sanitize_text_field', $_POST['optimize']);
            update_option('wpc-warmup-selector', $optimize);
        } elseif (isset($_POST['optimize']) && $_POST['optimize'] == 'false') {

        } elseif (isset($_POST['optimize']) && $_POST['optimize'] == 'do-not-optimize') {
            update_option('wpc-warmup-selector', 'do-not-optimize');
        } else {
            delete_option('wpc-warmup-selector');
        }

        $warmup_class = new wps_ic_preload_warmup();
        $pages = $warmup_class->getPagesToOptimize();

        $status = $warmup_class->get_optimization_status();

        $next_page = null;
        if (!empty($status['mode']) && $status['mode'] == 'local') {
            $next_page = reset($pages['pages']);
            if ($next_page !== false) {
                $count = 1;
                $transient = get_transient('wpc_last_optimised_page');
                if (!empty($transient)) {
                    if ($transient['id'] == $next_page['id'] && $transient['count'] == 2) {
                        $warmup_class->addError($next_page['id'], 'skip');
                    } else if ($transient['id'] == $next_page['id'] && $transient['count'] == 1) {
                        $count = 2;
                    } else {
                        $count = 1;
                    }
                }
                if ($warmup_class->isRedirected($next_page['link'])) {
                    $warmup_class->addError($next_page['id'], 'redirect');
                }
                $warmup_class->localCacheWarmup($next_page['link']);
                $status['id'] = $next_page['id'];
                $status['pageTitle'] = ($status['id'] === 'home') ? 'Home Page' : get_the_title($status['id']);
                $status['status'] = 'warmup';
                set_transient('wpc_last_optimised_page', ['id' => $next_page['id'], 'count' => $count]);
            }
        }


        if ($pages['unoptimized'] == 0) {
            $check = get_transient('wpc-page-optimizations-status-check');
            if ($check === false) {

                $transient = get_transient('wpc-page-optimizations-status');
                set_transient('wpc-page-optimizations-status', $transient, 60);
                set_transient('wpc-page-optimizations-status-check', 'true', 62);
            }
        }

        $response = ['optimizationStatus' => $status, 'optimized' => $pages['total'] - $pages['unoptimized'], 'total' => $pages['total'], 'connectivity' => true, $next_page, $pages['pages']];
        wp_send_json_success($response);
    }

    public function wps_ic_start_optimizations()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        delete_option('wpc-warmup-errors');
        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->startOptimizations();
    }

    public function wps_ic_stop_optimizations()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->stopOptimizations();
    }

    public function wps_ic_save_purge_hooks_settings()
    {

        if ((!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            $hooks  = sanitize_textarea_field($_POST['hooks'] ?? '');
            $hooks  = array_values(array_filter(explode("\n", rtrim($hooks, "\n"))));
            $form   = [
                'post-publish' => [
                    'all-pages'           => sanitize_text_field($_POST['all_pages'] ?? '0'),
                    'home-page'           => sanitize_text_field($_POST['home_page'] ?? '0'),
                    'recent-posts-widget' => sanitize_text_field($_POST['recent_posts_widget'] ?? '0'),
                    'archive-pages'       => sanitize_text_field($_POST['archive_pages'] ?? '0'),
                ],
                'hooks'     => $hooks,
                'scheduled' => sanitize_text_field($_POST['scheduled'] ?? ''),
            ];
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->saveRemotePurgeRules($apikey, $form);
            }
            wp_send_json_success();
        }

        $purge_rules = get_option('wps_ic_purge_rules', []);
        if (!isset($purge_rules['post_publish'])) {
            $purge_rules['post_publish'] = [];
        }

        $all_pages = sanitize_text_field($_POST['all_pages']);
        $home_page = sanitize_text_field($_POST['home_page']);
        $recent_posts_widget = sanitize_text_field($_POST['recent_posts_widget']);
        $archive_pages = sanitize_text_field($_POST['archive_pages']);
        $purge_rules['post-publish']['all-pages'] = $all_pages;
        $purge_rules['post-publish']['home-page'] = $home_page;
        $purge_rules['post-publish']['recent-posts-widget'] = $recent_posts_widget;
        $purge_rules['post-publish']['archive-pages'] = $archive_pages;

        $hooks = sanitize_textarea_field($_POST['hooks']);
        $hooks = rtrim($hooks, "\n");
        $hooks = explode("\n", $hooks);
        $purge_rules['hooks'] = $hooks;

        $scheduled = sanitize_text_field($_POST['scheduled']);
        $purge_rules['scheduled'] = $scheduled;

        $updated = update_option('wps_ic_purge_rules', $purge_rules, false);

        if ($updated) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll(false, false, false, false);
        }

        wp_send_json_success();
    }

    public function wps_ic_get_purge_rules()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey      = sanitize_text_field($_POST['apikey'] ?? '');
            $purge_rules = (!empty($apikey) && !empty($api) && !empty($api::$comms))
                           ? $api::$comms->getRemotePurgeRules($apikey)
                           : [];
        } else {
            $purge_rules = get_option('wps_ic_purge_rules');
            if (empty($purge_rules)) {
                $options = new wps_ic_options();
                $purge_rules = $options->get_preset('purge_rules');
                update_option('wps_ic_purge_rules', $purge_rules, false);
            }
        }

        $post_publish = $purge_rules['post-publish'];

        
        $all_pages = 0;
        $home_page = 0;
        $recent_posts_widget = 0;
        $archive_pages = 0;
        if (!empty($post_publish['all-pages']) && $post_publish['all-pages'] == '1') {
            $all_pages = 1;
        }
        if (!empty($post_publish['home-page']) && $post_publish['home-page'] == '1') {
            $home_page = 1;
        }
        if (!empty($post_publish['recent-posts-widget']) && $post_publish['recent-posts-widget'] == '1') {
            $recent_posts_widget = 1;
        }
        if (!empty($post_publish['archive-pages']) && $post_publish['archive-pages'] == '1') {
            $archive_pages = 1;
        }


        if (empty($purge_rules['hooks'])) {
            $hooks = '';
        } else {
            $hooks = implode("\n", $purge_rules['hooks']);
        }

        $scheduled = '';
        if (!empty($purge_rules['scheduled'])) {
            $scheduled = $purge_rules['scheduled'];
        }

        wp_send_json_success(['hooks' => $hooks, 'all_pages' => $all_pages, 'home_page' => $home_page, 'recent_posts_widget' => $recent_posts_widget, 'archive_pages' => $archive_pages, 'scheduled' => $scheduled]);
    }

    public function wps_ic_export_settings()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $settings       = sanitize_text_field($_POST['settings'] ?? '');
        $excludes       = sanitize_text_field($_POST['excludes'] ?? '');
        $cache          = sanitize_text_field($_POST['cache'] ?? '');
        $cache_cookies  = sanitize_text_field($_POST['cookies'] ?? '');
        $apikey         = sanitize_text_field($_POST['apikey'] ?? '');

        
        
        if ($apikey && $this->isAgencyPortal()) {
            global $api;
            $remoteSettings = $api::$comms->getRemoteSettings($apikey);
            $this->injectRemoteSettingsAsOptions($remoteSettings);
        }

        $json = [];

        if (!empty($settings)) {
            $json['settings'] = get_option(WPS_IC_SETTINGS);
        }

        if (!empty($excludes)) {
            $json['excludes'] = get_option('wpc-excludes', []);
        }

        if (!empty($cache)) {
            if ($apikey && $this->isAgencyPortal()) {
                global $api;
                $json['cache'] = $api::$comms->getRemotePurgeRules($apikey);
            } else {
                $json['cache'] = get_option('wps_ic_purge_rules', []);
            }
            unset($json['cache']['type-lists']);
        }

        if (!empty($cache_cookies)) {
            if ($apikey && $this->isAgencyPortal()) {
                global $api;
                $json['cache_cookies'] = $api::$comms->getRemoteCacheCookies($apikey);
            } else {
                $json['cache_cookies'] = get_option('wps_ic_cache_cookies', []);
            }
        }

        wp_send_json_success($json);
    }


    public function wps_ic_import_settings()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        
        $import_data = $_POST['importData'];
        $apikey      = sanitize_text_field($_POST['apikey'] ?? '');

        if (empty($import_data)) {
            wp_send_json_error(['msg' => 'No import data provided']);
        }

        if (is_string($import_data)) {
            $decoded_data = json_decode($import_data, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(['message' => 'JSON decode error: ' . json_last_error_msg()]);
            }

            $import_data = $decoded_data;
        }

        if (empty($import_data)) {
            wp_send_json_error(['msg' => 'No import data provided']);
        }

        
        if ($apikey && $this->isAgencyPortal()) {
            global $api;
            $call = $api::$comms->importRemoteSettings($apikey, $import_data);
            if (!$call || empty($call['success'])) {
                wp_send_json_error($call['data'] ?? 'Remote import failed.');
            }
            wp_send_json_success(['msg' => 'Settings imported to remote site successfully']);
        }

        $options_class = new wps_ic_options();

        if (isset($import_data['settings'])) {
            $import_data['settings'] = $options_class->setMissingSettings($import_data['settings']);
            update_option(WPS_IC_SETTINGS, $import_data['settings']);
        }

        if (isset($import_data['excludes'])) {
            update_option('wpc-excludes', $import_data['excludes']);
        }

        if (isset($import_data['cache'])) {
            update_option('wps_ic_purge_rules', $import_data['cache'], false);
        }

        
        if (isset($import_data['cache_cookies'])) {
            update_option('wps_ic_cache_cookies', $import_data['cache_cookies']);
        }

        $cache = new wps_ic_cache_integrations();


        if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead()) {
            $cache::purgeCriticalFiles();
        }
        $cache::purgeAll();

        wp_send_json_success(['msg' => 'Settings imported successfully']);
    }


    public function wps_ic_set_default_settings()
    {
        
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $options = new wps_ic_options();
        $purge_rules = $options->get_preset('purge_rules');
        update_option('wps_ic_purge_rules', $purge_rules, false);

        $configuration = $options->get_preset('aggressive');
        if (function_exists('wpc_preset_cache_gate67')) {
            $configuration = wpc_preset_cache_gate67($configuration);
        }
        update_option(WPS_IC_SETTINGS, $configuration);
        update_option(WPS_IC_PRESET, 'aggressive');

        delete_option('wpc-excludes');

        $cache = new wps_ic_cache_integrations();

        if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead()) {
            $cache::purgeCriticalFiles();
        }
        $cache::purgeAll(false, false, false, false);

        wp_send_json_success();
    }

    public function wps_ic_save_cf_cdn()
    {
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        
        
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'invalidate_asset_mime_proof')) {
            wps_rewriteLogic::invalidate_asset_mime_proof();
        }

        $cname = sanitize_text_field($_POST['cname'] ?? '');
        if (empty($cname)) {
            wp_send_json_error('Empty CNAME');
        }

        $requests = new wps_ic_requests();

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey  = sanitize_text_field($_POST['apikey'] ?? '');
            $cf      = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getRemoteCFOption($apikey) : [];
            $siteUrl = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getSiteUrl($apikey) : site_url();
        } else {
            $cf          = get_option(WPS_IC_CF);
            $siteUrl     = site_url();
            $options     = get_option(WPS_IC_OPTIONS);
            $apikey      = $options['api_key'];
            $prevCfCname = (string) get_option(WPS_IC_CF_CNAME);
        }

        if (empty($cf)) {
            wp_send_json_error(['message' => 'Cloudflare not connected']);
            wp_die();
        }

        $zoneName = str_replace(['http://', 'https://', '/'], '', $siteUrl);

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'updateCFCname', 'apikey' => $apikey, 'cname' => $cname, 'token' => $cf['token'], 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'zone' => $cf['zone'], 'time' => microtime(true)], ['timeout' => 30]);

        if (!empty($body)) {
            $data    = (array)$body->data;
            $cfCname = $data['cfName'];

            if ($this->isAgencyPortal()) {
                if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                    $api::$comms->saveRemoteCFOption($apikey, null, $cfCname);
                }
            } else {
                update_option(WPS_IC_CF_CNAME, $cfCname);


                if ((string) $cfCname !== $prevCfCname) {
                    update_option('wpc_v2_force_provision', 1, false);
                    
                    


                    update_option('wpc_cf_cname_verified', '0', false);
                    if (function_exists('wpc_v2_config_sync_lazy_enabled') && function_exists('wpc_v2_get_zone_id')) {
                        $wpc_zid = (string) wpc_v2_get_zone_id();
                        if ($wpc_zid === '' || !ctype_digit($wpc_zid)) {
                            $wpc_zid = (string) $cfCname;
                        }
                        wpc_v2_config_sync_lazy_enabled(
                            $wpc_zid,
                            function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : false
                        );
                    } elseif (function_exists('wpc_v2_schedule_config_sync')) {
                        wpc_v2_schedule_config_sync();
                    }


                    if (class_exists('WPC_CloudflareAPI') && !empty($cf['token'])) {
                        $cfapi_verify = new WPC_CloudflareAPI($cf['token']);
                        if ($cfapi_verify && $cfapi_verify->verifyCfCnameLive($cfCname, 1, 5)) {
                            update_option('wpc_cf_cname_verified', 1, false);
                        }
                    }
                }
            }
        } else {
            wp_send_json_error();
        }

        if (!$this->isAgencyPortal()) {
            $cache = new wps_ic_cache_integrations();
            $cache->purgeAll(false, false, false, false);
        }
        wp_send_json_success($body);
    }

    public function wps_ic_get_cf_cdn()
    {
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        
        if (!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            $cname  = (!empty($apikey) && !empty($api) && !empty($api::$comms)) ? $api::$comms->getRemoteCFCname($apikey) : '';
            wp_send_json_success(['cname' => $cname]);
        }

        $cfsdk = new WPC_CloudflareAPI();
        $cname = $cfsdk->getCfCname();

        wp_send_json_success(['cname' => $cname]);
    }


    public function wpc_v2_diag()
    {
        
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden_capability'], 403);
        }


        if (!check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['error' => 'bad_nonce'], 403);
        }

        
        $meta = function ($id, $key) {
            if ($id <= 0) return null;
            $v = get_post_meta((int) $id, $key, true);
            return ($v === '' || $v === false) ? null : $v;
        };
        $opt = function ($key, $default = null) {
            return get_option($key, $default);
        };
        $trans = function ($key) {


            $v = get_transient($key);
            return ($v === false) ? null : $v;
        };
        $trans_exists = function ($key) {
            
            return get_transient($key) !== false;
        };

        $now    = time();
        $now_ms = (int) round(microtime(true) * 1000);

        $image_id = 0;
        if (isset($_REQUEST['image_id']))          $image_id = (int) $_REQUEST['image_id'];
        elseif (isset($_REQUEST['attachment_id'])) $image_id = (int) $_REQUEST['attachment_id'];


        $version = isset(parent::$version) ? parent::$version : null;

        $out = [
            'ok'             => true,
            'generated_at'   => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            'now_unixtime'   => $now,
            'plugin_version' => is_string($version) ? $version : (string) $version,
            'image_id'       => $image_id,
            'image'          => null,
            'drain'          => [],
            'bulk'           => [],
            'account'        => [],
            'telemetry'      => null,
        ];


        if ($image_id > 0) {
            $ic_compressing = $meta($image_id, 'ic_compressing');
            $variants_raw   = $meta($image_id, 'ic_local_variants');


            $fmt_counts   = ['jpeg' => 0, 'webp' => 0, 'avif' => 0, 'other' => 0];
            $size_labels  = [];
            $variant_keys = [];
            if (is_array($variants_raw)) {
                foreach ($variants_raw as $vkey => $vval) {
                    $vkey = (string) $vkey;
                    $variant_keys[] = $vkey;

                    if (is_array($vval) && !empty($vval['format'])) {
                        $fmt = strtolower((string) $vval['format']);
                        if ($fmt === 'jpg') $fmt = 'jpeg';
                    } elseif (substr($vkey, -5) === '-avif') {
                        $fmt = 'avif';
                    } elseif (substr($vkey, -5) === '-webp') {
                        $fmt = 'webp';
                    } else {
                        $fmt = 'jpeg';
                    }
                    if (isset($fmt_counts[$fmt])) $fmt_counts[$fmt]++; else $fmt_counts['other']++;

                    
                    $sl = $vkey;
                    if (substr($sl, -5) === '-avif' || substr($sl, -5) === '-webp') {
                        $sl = substr($sl, 0, -5);
                    }
                    $size_labels[$sl] = true;
                }
            }
            $variants_serialized_bytes = is_array($variants_raw) ? strlen(@serialize($variants_raw)) : 0;

            
            $hb = $trans('wps_ic_heartbeat_' . $image_id);
            $hb_out = is_array($hb) ? [
                'status'          => isset($hb['status']) ? $hb['status'] : null,
                'event'           => isset($hb['event']) ? $hb['event'] : null,
                'time'            => isset($hb['time']) ? (int) $hb['time'] : null,
                'age_s'           => isset($hb['time']) ? max(0, $now - (int) $hb['time']) : null,
                'bg_variant_fmt'  => isset($hb['bg_variant_fmt']) ? $hb['bg_variant_fmt'] : null,
                'bg_variant_size' => isset($hb['bg_variant_size']) ? $hb['bg_variant_size'] : null,
            ] : null;


            $cmp = $trans('wps_ic_compress_' . $image_id);

            
            $t0  = $trans('wpc_v2_t0_ms_' . $image_id);
            $t0i = ($t0 !== null) ? (int) $t0 : null;

            
            $pad = $trans('wpc_v2_phase_a_done_' . $image_id);

            
            
            $pending = $trans('wpc_v2_pending_' . $image_id);
            if (is_array($pending)) {
                $pmap = (isset($pending['pending']) && is_array($pending['pending'])) ? $pending['pending'] : $pending;
                $pending_out = [
                    'jobId'       => isset($pending['jobId']) ? $pending['jobId'] : null,
                    'count'       => is_array($pmap) ? count($pmap) : 0,
                    'keys'        => is_array($pmap) ? array_map('strval', array_keys($pmap)) : [],
                    'recorded_at' => isset($pending['recorded_at']) ? (int) $pending['recorded_at'] : null,
                ];
            } else {
                $pending_out = ['count' => 0, 'keys' => []];
            }


            $rst = $trans('wps_ic_restore_' . $image_id);

            $out['image'] = [
                'image_id'                          => $image_id,
                'ic_status'                         => $meta($image_id, 'ic_status'),
                'ic_compressing'                    => $ic_compressing,
                'ic_compressing_status'             => is_array($ic_compressing) && isset($ic_compressing['status']) ? $ic_compressing['status'] : null,
                'ic_compressing_expected_variants'  => is_array($ic_compressing) && isset($ic_compressing['expected_variants']) ? (int) $ic_compressing['expected_variants'] : null,
                'ic_compressing_time'               => is_array($ic_compressing) && isset($ic_compressing['time']) ? (int) $ic_compressing['time'] : null,

                'ic_local_variants' => [
                    'count'                 => is_array($variants_raw) ? count($variants_raw) : 0,
                    'by_format'             => $fmt_counts,
                    'size_labels'           => array_keys($size_labels),
                    'variant_keys'          => $variant_keys,
                    'serialized_bytes'      => $variants_serialized_bytes,
                    
                    'raw'                   => ($variants_serialized_bytes > 0 && $variants_serialized_bytes <= 8192) ? $variants_raw : null,
                    'raw_omitted_too_large' => ($variants_serialized_bytes > 8192),
                ],


                'async_tokens_armed' => [
                    'wpc_async_phase_a'        => $trans_exists('wpc_async_token_wpc_async_phase_a_' . $image_id),
                    'wpc_async_restore_regen'  => $trans_exists('wpc_async_token_wpc_async_restore_regen_' . $image_id),
                    'wpc_async_image_bg_retry' => $trans_exists('wpc_async_token_wpc_async_image_bg_retry_' . $image_id),
                ],

                'heartbeat'         => $hb_out,
                'compress_state'    => $cmp,                         
                'restore_state'     => is_array($rst) ? ['status' => isset($rst['status']) ? $rst['status'] : null] : $rst,
                'restoring_guard'   => $trans_exists('wpc_restoring_' . $image_id),
                't0_ms'             => $t0i,
                't0_age_ms'         => ($t0i !== null) ? max(0, $now_ms - $t0i) : null,
                'phase_a_done'      => ($pad !== null) ? (int) $pad : null,
                'pending'           => $pending_out,
            ];
        }


        $drain_running = $trans('wpc_v2_drain_running');
        $cooloff       = $trans('wpc_v2_pull_cooloff');
        $out['drain'] = [
            'drain_running'      => ($drain_running !== null),
            'drain_running_ts'   => is_numeric($drain_running) ? (int) $drain_running : null,
            'drain_lock_age_s'   => is_numeric($drain_running) ? max(0, $now - (int) $drain_running) : null,
            'redrain_pending'    => $trans_exists('wpc_v2_redrain_pending'),
            'pull_cooloff_until' => is_numeric($cooloff) ? (int) $cooloff : null,
            'pull_cursor_ms'     => function_exists('wpc_v2_pull_get_cursor')
                                        ? (int) wpc_v2_pull_get_cursor()
                                        : (int) $opt('wpc_v2_pull_cursor_ms', 0),
            'pull_enabled'       => function_exists('wpc_v2_pull_enabled') ? (bool) wpc_v2_pull_enabled() : null,

            'last_drain_skip'    => $opt('wpc_v2_last_drain_skip'),   
            'last_extdrain'      => $opt('wpc_v2_last_extdrain'),     
            'last_drain_stats'   => $opt('wpc_v2_last_drain_stats'),  
        ];


        $bp = $opt('wps_ic_bulk_process', null);
        $cq = $trans('wps_ic_compress_queue'); 
        $rq = $trans('wps_ic_restore_queue');


        $rq_queue = (is_array($rq) && isset($rq['queue']) && is_array($rq['queue'])) ? array_map('intval', $rq['queue']) : [];
        $out['bulk'] = [
            'stop_signal'         => $trans_exists('wpc_bulk_stop_signal'),
            'bulk_process'        => is_array($bp) ? $bp : $bp,
            'bulk_process_status' => is_array($bp) && isset($bp['status']) ? $bp['status'] : null,
            'active_restore'      => (is_array($bp) && isset($bp['status']) && $bp['status'] === 'restoring'),


            'compress_draining'        => $trans_exists('wpc_bulk_compress_draining'),
            'compress_redrain_pending' => $trans_exists('wpc_bulk_compress_redrain_pending'),
            'restore_draining'         => $trans_exists('wpc_bulk_restore_draining'),
            'restore_redrain_pending'  => $trans_exists('wpc_bulk_restore_redrain_pending'),
            'restore_queue'       => is_array($rq)
                ? [
                    'present'      => true,
                    'total_images' => isset($rq['total_images']) ? (int) $rq['total_images'] : null,
                    'queue_len'    => count($rq_queue),
                    'head_ids'     => array_slice($rq_queue, 0, 5),
                  ]
                : ['present' => false],
            'compress_queue'      => is_array($cq)
                ? [
                    'present'      => true,
                    'total_images' => isset($cq['total_images']) ? (int) $cq['total_images'] : null,
                    'queue_len'    => (isset($cq['queue']) && is_array($cq['queue'])) ? count($cq['queue']) : 0,
                    'head_ids'     => (isset($cq['queue']) && is_array($cq['queue'])) ? array_slice(array_map('intval', $cq['queue']), 0, 5) : [],
                  ]
                : ['present' => false],
        ];


        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : (isset(self::$apikey) ? self::$apikey : '');
        $live_cdn = isset(self::$settings['live-cdn']) ? (string) self::$settings['live-cdn'] : null;
        $out['account'] = [
            'apikey_present'         => (is_string($apikey) && $apikey !== ''), 
            'live_cdn'               => ($live_cdn === '1'),
            'live_cdn_raw'           => $live_cdn,
            'zone_id'                => function_exists('wpc_v2_get_zone_id') ? wpc_v2_get_zone_id() : null,
            'cf_asset_mime_ok'       => ((string) $opt('wpc_v2_cf_asset_mime_ok', '') === '1'),
            'cf_asset_mime_retry'    => $trans_exists('wpc_v2_cf_asset_mime_retry'),
            
            'async_dispatch_enabled' => (bool) apply_filters('wpc_v2_async_dispatch_enabled', true),
        ];


        if (function_exists('wpc_v2_telemetry_stats')) {
            $stats = wpc_v2_telemetry_stats();
            $out['telemetry'] = is_array($stats) ? $stats : null;
        }

        wp_send_json_success($out);
    }


    public static function wpc_v2_prefer_source_url()
    {
        
        
        
        
        
        $t = (int) get_option('wpc_v2_prefer_source_url', 0);
        return $t > 0 && (time() - $t) < 7 * DAY_IN_SECONDS;
    }

    public static function wpc_v2_async_dispatch_ok()
    {
        if (!apply_filters('wpc_v2_async_dispatch_enabled', true)) return false;
        $un = (int) get_option('wpc_v2_async_unreliable', 0);
        if ($un > 0 && (time() - $un) < 7 * DAY_IN_SECONDS) return false; 
        return true;
    }

    public static function dispatch_async_loopback($action, $imageID)
    {
        if (!function_exists('wp_remote_post')) return false;
        
        
        if (!apply_filters('wpc_v2_async_dispatch_enabled', true)) return false;

        $imageID = (int) $imageID;
        if ($imageID <= 0) return false;

        $token = wp_generate_password(32, false, false);
        $token_key = 'wpc_async_token_' . $action . '_' . $imageID;
        set_transient($token_key, $token, 60); 

        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            
            
            delete_transient($token_key);
            error_log('[WPC AsyncDispatch] loopback bad_admin_url action=' . $action . ' imageID=' . $imageID . ' url=' . $url);
            return false;
        }

        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        
        
        $port = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
        $host = (string) $parts['host'];


        $path = (!empty($parts['path']) ? $parts['path'] : '/admin-ajax.php');


        $connect_chain = apply_filters('wpc_loopback_connect_host', ['127.0.0.1', 'localhost', $host], $host, $is_https, $port);
        if (is_string($connect_chain) && $connect_chain !== '') $connect_chain = [$connect_chain];
        if (!is_array($connect_chain) || empty($connect_chain)) $connect_chain = ['127.0.0.1', 'localhost', $host];
        
        $connect_chain = array_values(array_unique(array_filter(array_map('strval', $connect_chain))));


        $can_detach   = (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
        $confirm_want = $can_detach && ((float) apply_filters('wpc_loopback_confirm_timeout', 2.5, $action, $imageID) > 0);

        
        
        $total_budget   = (float) apply_filters('wpc_loopback_total_budget', 2.5, $action, $imageID);
        $hard_deadline  = microtime(true) + max(0.3, $total_budget);
        $connect_budget = $is_https ? 0.7 : 0.4;

        $ssl_ctx = $is_https ? stream_context_create(['ssl' => [
            'peer_name'         => $host,
            'SNI_enabled'       => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]) : stream_context_create();

        $landed    = false;
        $used_host = '';
        foreach ($connect_chain as $chost) {
            if ($chost === '') continue;
            if (microtime(true) >= $hard_deadline) break;

            $is_loopback_literal = ($chost === '127.0.0.1' || $chost === '::1' || strtolower($chost) === 'localhost');


            $send_token = $is_loopback_literal;
            $body = http_build_query(array_filter([
                'action'   => $action,
                'image_id' => $imageID,
                'token'    => $send_token ? $token : '',
            ], static function ($v) { return $v !== ''; }));
            $req = "POST {$path} HTTP/1.1\r\n"
                 . "Host: {$host}\r\n"
                 . "Content-Type: application/x-www-form-urlencoded\r\n"
                 . "Content-Length: " . strlen($body) . "\r\n"
                 . "Connection: close\r\n"
                 . "User-Agent: WPCAsyncDispatch/1.0\r\n"
                 . "\r\n"
                 . $body;

            $errno   = 0;
            $errstr  = '';
            $remote  = ($is_https ? 'tls://' : 'tcp://') . $chost . ':' . $port;
            $cbudget = min($connect_budget, max(0.05, $hard_deadline - microtime(true)));
            $sock    = @stream_socket_client($remote, $errno, $errstr, $cbudget, STREAM_CLIENT_CONNECT, $ssl_ctx);
            if (!$sock) {
                error_log('[WPC AsyncDispatch] loopback connect_miss action=' . $action . ' imageID=' . $imageID
                    . ' try=' . $chost . ' port=' . $port . ' errno=' . $errno . ' err=' . $errstr);
                continue;
            }


            $written = @fwrite($sock, $req);
            if ($written === false || $written < strlen($req)) {
                @fclose($sock);
                error_log('[WPC AsyncDispatch] loopback write_fail action=' . $action . ' imageID=' . $imageID
                    . ' try=' . $chost . ' wrote=' . var_export($written, true));
                continue;
            }

            if (!$confirm_want || !$send_token) {
                
                
                @fclose($sock);
                $used_host = $chost;
                if ($send_token) break;
                continue;
            }


            @stream_set_blocking($sock, false);
            $buf = '';
            while (microtime(true) < $hard_deadline && strlen($buf) < 512) {
                $slice = $hard_deadline - microtime(true);
                if ($slice <= 0) break;
                $r = [$sock]; $w = null; $e = null;
                $sec = (int) floor($slice);
                $usec = (int) round(($slice - $sec) * 1000000);
                $ready = @stream_select($r, $w, $e, $sec, $usec);
                if ($ready === false) break;
                if ($ready === 0) break;
                $chunk = @fread($sock, 512);
                if ($chunk === '' || $chunk === false) {
                    if (feof($sock)) break;
                    continue;
                }
                $buf .= $chunk;
                
                
                if (preg_match('#^HTTP/\d(?:\.\d)?\s+(2\d\d)\b#', $buf) || strpos($buf, "\r\n\r\nqueued") !== false) {
                    $landed    = true;
                    $used_host = $chost;
                    break;
                }
            }
            @fclose($sock);
            if ($landed) break;
            error_log('[WPC AsyncDispatch] loopback connected_no_confirm action=' . $action . ' imageID=' . $imageID
                . ' try=' . $chost . ' bytes=' . strlen($buf));
        }

        if ($landed) {
            
            
            error_log('[WPC AsyncDispatch] loopback CONFIRMED action=' . $action . ' imageID=' . $imageID . ' via=' . $used_host);
            return true;
        }


        $still_armed = (get_transient($token_key) === $token);
        error_log('[WPC AsyncDispatch] loopback UNCONFIRMED action=' . $action . ' imageID=' . $imageID
            . ' via=' . $used_host . ' token_' . ($still_armed ? 'present_caller_will_sync' : 'consumed_worker_owns_it'));
        return false;
    }

    



    private static function verify_async_token($action)
    {
        $imageID = isset($_POST['image_id']) ? (int) $_POST['image_id'] : 0;
        $token   = isset($_POST['token'])    ? (string) $_POST['token'] : '';
        if ($imageID <= 0 || $token === '') return false;
        $token_key = 'wpc_async_token_' . $action . '_' . $imageID;
        $stored = get_transient($token_key);
        if (!$stored || !hash_equals((string) $stored, $token)) return false;
        delete_transient($token_key);
        return $imageID;
    }


    public static function claim_async_token_for_sync($action, $imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return true;
        $token_key = 'wpc_async_token_' . $action . '_' . $imageID;
        if (get_transient($token_key) === false) {
            error_log('[WPC AsyncDispatch] sync_fallback STAND_DOWN action=' . $action
                . ' imageID=' . $imageID . ' token_consumed_by_worker');
            return false;
        }
        delete_transient($token_key);
        error_log('[WPC AsyncDispatch] sync_fallback CLAIMED action=' . $action
            . ' imageID=' . $imageID . ' running_inline');
        return true;
    }


    public function wpc_async_phase_a()
    {
        $arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $imageID = self::verify_async_token('wpc_async_phase_a');
        if (!$imageID) {
            
            
            status_header(403);
            exit;
        }


        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @set_time_limit(120);


        if (isset(self::$local) && method_exists(self::$local, 'backup_all_sizes')) {
            $backupOk_async = self::$local->backup_all_sizes($imageID);
            if (!$backupOk_async) {
                error_log('[WPC AsyncDispatch] async_phase_a imageID=' . $imageID . ' backup_failed — aborting Phase A');
                if (function_exists('wpc_v2_telemetry_record')) {
                    wpc_v2_telemetry_record('async_phase_a', (int) round((microtime(true) - $arrival_t) * 1000), [
                        'image_id' => $imageID,
                        'outcome'  => 'failed_backup',
                    ]);
                }
                
                update_post_meta($imageID, 'ic_compressing', [
                    'status' => 'backup_failed',
                    'time'   => time(),
                ]);
                status_header(200);
                exit;
            }
        }


        if (isset(self::$local) && method_exists(self::$local, 'wait_for_regen_or_clear_stale')) {
            self::$local->wait_for_regen_or_clear_stale($imageID, 15);
        }

        
        
        
        
        
        
        
        set_transient('wpc_v2_worker_reached_' . $imageID, time(), 600);
        $outcome = 'unknown';
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            $result = wps_ic_ajax::run_v2_optimize($imageID);
            $outcome = !empty($result['ok']) ? 'success' : ('failed_' . ($result['error'] ?? 'unknown'));
        } else {
            $outcome = 'no_v2_handler';
        }

        if (function_exists('wpc_v2_telemetry_record')) {
            $ms = (int) round((microtime(true) - $arrival_t) * 1000);
            wpc_v2_telemetry_record('async_phase_a', $ms, [
                'image_id' => $imageID,
                'outcome'  => $outcome,
            ]);
        }

        
        
        status_header(200);
        exit;
    }


    




    public function wpc_delivery_verify_async()
    {
        $tok    = isset($_POST['tok']) ? (string) $_POST['tok'] : '';
        $expect = (string) get_transient('wpc_delivery_verify_tok');
        if ($tok === '' || $expect === '' || !hash_equals($expect, $tok)) {
            wp_die('bad token', 403);
        }
        delete_transient('wpc_delivery_verify_tok');
        @ignore_user_abort(true);
        @set_time_limit(120);
        if (class_exists('WPC_Delivery_Resolver')) {
            WPC_Delivery_Resolver::resolve_verbose(true);
        }
        wp_die('ok', 200);
    }

    public function wpc_async_restore_regen()
    {
        $arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $imageID = self::verify_async_token('wpc_async_restore_regen');
        if (!$imageID) {
            status_header(403);
            exit;
        }


        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @set_time_limit(120);

        
        
        $outcome = 'unknown';
        if (isset(self::$local) && method_exists(self::$local, 'restoreV4')) {


            $purge_urls_pre = function_exists('wpc_customer_purge_attachment_urls')
                ? wpc_customer_purge_attachment_urls($imageID) : [];
            self::$local->restoreV4($imageID);
            $status = get_post_meta($imageID, 'ic_status', true);
            $outcome = ($status === 'restored') ? 'success' : 'failed_' . ((string) $status);


            if ($outcome === 'success' && function_exists('wpc_purge_compat')) {
                $purge_urls = $purge_urls_pre;
                if (!empty($purge_urls)) {


                    
                    try {
                        $purge_res = wpc_purge_compat('urls', $purge_urls, 'restore_image', '', true);
                        $purge_ok  = is_array($purge_res) && !empty($purge_res['ok']);
                        error_log('[WPC Purge] restore_image (async worker) ok=' . ($purge_ok ? '1' : '0')
                            . ' urls=' . count($purge_urls)
                            . ' result=' . (is_array($purge_res) ? wp_json_encode($purge_res) : 'n/a'));
                    } catch (\Throwable $e) {
                        error_log('[WPC Purge] restore_image purge error: ' . $e->getMessage());
                    }
                }
            }
        } else {
            $outcome = 'no_local_handler';
        }

        if (function_exists('wpc_v2_telemetry_record')) {
            $ms = (int) round((microtime(true) - $arrival_t) * 1000);
            wpc_v2_telemetry_record('async_restore', $ms, [
                'image_id' => $imageID,
                'outcome'  => $outcome,
            ]);
        }

        status_header(200);
        exit;
    }


    public function wpc_async_image_bg_retry()
    {
        $arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $imageID = self::verify_async_token('wpc_async_image_bg_retry');
        if (!$imageID) {
            status_header(403);
            exit;
        }

        @ignore_user_abort(true);
        @set_time_limit(60);


        if (get_transient('wpc_bulk_stop_signal')) {
            error_log('[WPC BGRetry] imageID=' . $imageID . ' stop_signal — aborting retry chain');
            status_header(200);
            exit;
        }


        


        if (function_exists('wpc_v2_active_restore_count') && wpc_v2_active_restore_count() > 0) {
            error_log('[WPC BGRetry] imageID=' . $imageID . ' yield_to_restore — aborting retry chain');
            status_header(200);
            exit;
        }

        $t0_ms = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
        if ($t0_ms <= 0) {
            
            status_header(200);
            exit;
        }

        
        $ic = get_post_meta($imageID, 'ic_compressing', true);
        $expected = is_array($ic) ? (int) ($ic['expected_variants'] ?? 0) : 0;
        if ($expected <= 0) {
            status_header(200);
            exit;
        }

        $expected_sizes = ['thumbnail','medium','medium_large','large','1536x1536','2048x2048','scaled','original'];
        $expected_fmts  = ['jpeg', 'webp', 'avif'];

        $deadline = time() + 30;
        $attempt  = 0;
        while (time() < $deadline) {
            $attempt++;
            wp_cache_delete($imageID, 'post_meta');
            $variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($variants)) $variants = [];

            $accounted = 0;
            foreach ($variants as $v) {
                if (!is_array($v)) continue;
                if (!empty($v['size']) || !empty($v['bg_no_improvement'])) $accounted++;
            }
            if ($accounted >= $expected) {
                error_log(sprintf(
                    '[WPC BGRetry] imageID=%d complete attempt=%d accounted=%d/%d',
                    $imageID, $attempt, $accounted, $expected
                ));
                break;
            }

            
            
            $missing = [];
            foreach ($expected_sizes as $sz) {
                foreach ($expected_fmts as $fmt) {
                    $key = function_exists('wpc_v2_variant_key')
                        ? wpc_v2_variant_key($sz, $fmt)
                        : ($fmt === 'jpeg' ? $sz : $sz . '-' . $fmt);
                    if (isset($variants[$key]) && is_array($variants[$key])) {
                        if (!empty($variants[$key]['size']) || !empty($variants[$key]['bg_no_improvement'])) continue;
                    }
                    $missing[] = $key;
                }
            }
            if (empty($missing)) break;

            if (function_exists('wpc_v2_pull_manifest_fetch')
                && function_exists('wpc_v2_pull_manifest_queue_for_drain')) {


                $retry = wpc_v2_pull_manifest_fetch(max(0, $t0_ms - 1000), 200, 5000);
                if (!empty($retry['ok']) && !empty($retry['variants'])) {
                    $my = [];
                    foreach ($retry['variants'] as $v) {
                        if (isset($v['imageID']) && (int) $v['imageID'] === $imageID) $my[] = $v;
                    }
                    if (!empty($my)) {
                        $q = wpc_v2_pull_manifest_queue_for_drain($my);
                        if ((int) ($q['queued'] ?? 0) > 0 && function_exists('wpc_v2_journal_fire_loopback_fast')) {
                            wpc_v2_journal_fire_loopback_fast();
                        }
                    }
                }
            }
            error_log(sprintf(
                '[WPC BGRetry] imageID=%d attempt=%d accounted=%d/%d still_missing=%d',
                $imageID, $attempt, $accounted, $expected, count($missing)
            ));
            
            
        }


        $deadline_hit = (time() >= $deadline);
        $still_incomplete = false;
        if ($deadline_hit) {
            wp_cache_delete($imageID, 'post_meta');
            $final_variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($final_variants)) $final_variants = [];
            $final_accounted = 0;
            foreach ($final_variants as $v) {
                if (!is_array($v)) continue;
                if (!empty($v['size']) || !empty($v['bg_no_improvement'])) $final_accounted++;
            }
            $still_incomplete = ($final_accounted < $expected);

            $cnt_key = 'wpc_v2_bg_retry_count_' . $imageID;
            $attempts_so_far = (int) get_transient($cnt_key);
            $attempts_so_far++;
            set_transient($cnt_key, $attempts_so_far, 600);

            if ($still_incomplete && $attempts_so_far < 3) {
                error_log(sprintf(
                    '[WPC BGRetry] imageID=%d deadline_reached attempts_so_far=%d accounted=%d/%d \xe2\x86\x92 chaining next retry',
                    $imageID, $attempts_so_far, $final_accounted, $expected
                ));


                delete_transient('wpc_v2_bg_retry_fired_' . $imageID);
                if (function_exists('wpc_v2_fire_image_bg_retry')) {
                    wpc_v2_fire_image_bg_retry($imageID);
                }
            } else if ($still_incomplete) {


                $expected_sizes_e = ['thumbnail','medium','medium_large','large','1536x1536','2048x2048','scaled','original'];
                $expected_fmts_e  = ['jpeg', 'webp', 'avif'];
                $marked = 0;
                foreach ($expected_sizes_e as $sz_e) {
                    foreach ($expected_fmts_e as $fmt_e) {
                        $key_e = function_exists('wpc_v2_variant_key')
                            ? wpc_v2_variant_key($sz_e, $fmt_e)
                            : ($fmt_e === 'jpeg' ? $sz_e : $sz_e . '-' . $fmt_e);
                        if (isset($final_variants[$key_e]) && is_array($final_variants[$key_e])) {
                            if (!empty($final_variants[$key_e]['size'])
                                || !empty($final_variants[$key_e]['bg_no_improvement'])) continue;
                        }
                        $final_variants[$key_e] = [
                            'bg_no_improvement'     => true,
                            'no_improvement_reason' => 'retry_exhausted',
                            'bg_upgraded'           => time(),
                            'bg_upgraded_ms'        => (int) round(microtime(true) * 1000),
                            'phase_b_v2'            => true,
                        ];
                        $marked++;
                    }
                }
                if ($marked > 0) {
                    update_post_meta($imageID, 'ic_local_variants', $final_variants);
                }
                error_log(sprintf(
                    '[WPC BGRetry] imageID=%d giving_up_after_3_attempts attempts_total=%d accounted=%d/%d marked_no_improvement=%d',
                    $imageID, $attempts_so_far, $final_accounted, $expected, $marked
                ));
            } else {
                error_log(sprintf(
                    '[WPC BGRetry] imageID=%d final_pass_filled attempts_total=%d accounted=%d/%d',
                    $imageID, $attempts_so_far, $final_accounted, $expected
                ));
            }
        }

        if (function_exists('wpc_v2_telemetry_record')) {
            $ms = (int) round((microtime(true) - $arrival_t) * 1000);
            wpc_v2_telemetry_record('image_bg_retry', $ms, [
                'image_id'         => $imageID,
                'attempts_in_loop' => $attempt,
                'deadline_hit'     => $deadline_hit,
                'still_incomplete' => $still_incomplete,
            ]);
        }
        status_header(200);
        exit;
    }

}

if (!function_exists('wpc_v2_fire_image_bg_retry')) {
    





    function wpc_v2_fire_image_bg_retry($imageID)
    {
        if (!class_exists('wps_ic_ajax')) return false;
        return wps_ic_ajax::dispatch_async_loopback('wpc_async_image_bg_retry', $imageID);
    }
}