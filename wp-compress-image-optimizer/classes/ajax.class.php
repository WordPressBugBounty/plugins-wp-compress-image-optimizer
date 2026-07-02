<?php

/**
 * Class - Ajax
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

            // Sync live-cdn from actual state: CF CDN or any CDN file type on
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

            // GUI switch — must be available even without API key (lite CF banner uses it)
            $this->add_ajax('wpsChangeGui');
            // Read-only admin diagnostics (manage_options + nonce). Registered outside the
            // api_key gate so the no-key case is still diagnosable.
            $this->add_ajax('wpc_v2_diag');

            // Agency portal: register settings/mode/excludes save handlers regardless of local api_key
            // (apikey is sent per-request via $_POST['apikey'] in agency mode)
            if ($this->isAgencyPortal()) {
                $this->add_ajax('wps_ic_ajax_v2_checkbox_batch');
                $this->add_ajax('wps_ic_save_mode');
                $this->add_ajax('wps_ic_save_excludes_settings');
                $this->add_ajax('wps_ic_get_setting');
                // Font tab
                $this->add_ajax('wpsScanFonts');
                $this->add_ajax('wpsRemoveFont');
                $this->add_ajax('wpsPurgeFontCache');
                // Cloudflare handlers (api_key comes per-request via $_POST['apikey'])
                $this->add_ajax('wpc_ic_checkCFToken');
                $this->add_ajax('wpc_ic_checkCFConnect');
                $this->add_ajax('wpc_ic_checkCFDisconnect');
                $this->add_ajax('wpc_ic_refreshCFConnection');
                $this->add_ajax('wpc_ic_setupCF');
                $this->add_ajax('wps_ic_save_cf_cdn');
                $this->add_ajax('wps_ic_get_cf_cdn');
                $this->add_ajax('wps_ic_get_purge_rules');
                $this->add_ajax('wps_ic_save_purge_hooks_settings');
                $this->add_ajax('wps_ic_get_cache_cookies');
                $this->add_ajax('wps_ic_save_cache_cookies_settings');
                $this->add_ajax('wps_ic_purge_after_save');
                $this->add_ajax('wpc_ic_ajax_set_preset');
                // Export / Import / Reset
                $this->add_ajax('wps_ic_export_settings');
                $this->add_ajax('wps_ic_import_settings');
                $this->add_ajax('wps_ic_set_default_settings');
            }

            if (!empty(parent::$api_key)) {
                // Pull Stats
                $this->add_ajax('wps_fetchInitialTest');
                $this->add_ajax('wps_ic_pull_stats');

                // Scan Fonts
                $this->add_ajax('wpsRemoveFont');
                $this->add_ajax('wpsScanFonts');

                // Cloudflare
                $this->add_ajax('wpc_ic_checkCFToken');
                $this->add_ajax('wpc_ic_checkCFConnect');
                $this->add_ajax('wpc_ic_checkCFDisconnect');
                $this->add_ajax('wpc_ic_refreshCFConnection');
                $this->add_ajax('wpc_ic_setupCF');

                // Critical CSS
                $this->add_ajax('wps_ic_critical_get_assets');
                $this->add_ajax('wps_ic_critical_run');
                $this->add_ajax('wps_ic_get_setting');
                $this->add_ajax('wps_ic_saveSetting');
                $this->add_ajax('wps_ic_save_excludes_settings');

                // GeoLocation for Popups
                $this->add_ajax('wps_ic_remove_key');
                $this->add_ajax('wpc_ic_set_mode');
                $this->add_ajax('wpc_ic_ajax_set_preset');
                $this->add_ajax('wps_ic_cname_add');
                $this->add_ajax('wps_ic_cname_retry');
                $this->add_ajax('wps_ic_remove_cname');
                $this->add_ajax('wps_ic_exclude_list');
                $this->add_ajax('wps_ic_geolocation');
                $this->add_ajax('wps_ic_geolocation_force');

                // Bulk Actions
                $this->add_ajax('wps_ic_StopBulk');
                $this->add_ajax('wps_ic_getBulkStats');
                $this->add_ajax('wps_ic_bulkCompressHeartbeat');
                $this->add_ajax('wps_ic_bulkRestoreHeartbeat');
                $this->add_ajax('wps_ic_isBulkRunning');
                $this->add_ajax('wpc_ic_start_bulk_restore');
                $this->add_ajax('wpc_ic_start_bulk_compress');
                $this->add_ajax('wps_ic_doBulkCompress');
                $this->add_ajax('wpc_bulk_v2_drain');             // compress drain chain
                $this->add_ajax('wpc_bulk_v2_restore_drain');     // restore drain chain
                $this->add_ajax('wpc_bulk_v2_restore_drain_loop');       // HMAC restore self-chain (priv + nopriv)
                $this->add_ajax_nopriv('wpc_bulk_v2_restore_drain_loop');
                $this->add_ajax('wpc_bulk_v2_drain_loop');               // HMAC compress self-chain (priv + nopriv)
                $this->add_ajax_nopriv('wpc_bulk_v2_drain_loop');
                $this->add_ajax('wps_ic_bulkCompressCleanup');    // tally session cleanup
                $this->add_ajax('wps_ic_bulkRestoreCleanup');     // restore grace-window cleanup
                $this->add_ajax('wps_ic_media_library_bulk_heartbeat');
                $this->add_ajax('wps_ic_doBulkRestore');
                $this->add_ajax('wps_ic_RestoreFinished');

                $this->add_ajax('wps_ic_media_library_heartbeat');
                $this->add_ajax('wps_ic_compress_live');
                $this->add_ajax('wps_ic_restore_live');
                // Async loopback dispatch. Click handlers return in ~300ms; the heavy work
                // (Phase A POST / restore regen) runs in a separate FPM worker via loopback.
                // nopriv is required (loopback carries no auth cookie) — auth is a one-shot
                // transient token verified in the handler, so external callers can't trigger.
                $this->add_ajax('wpc_async_phase_a');
                $this->add_ajax_nopriv('wpc_async_phase_a');
                $this->add_ajax('wpc_async_restore_regen');
                $this->add_ajax_nopriv('wpc_async_restore_regen');
                // Async delivery re-verify worker (one-shot token). Can't ride wp-cron:
                // core doesn't load under DOING_CRON so the scheduled handler doesn't exist
                // there (same reason every v2 worker is an admin-ajax loopback).
                $this->add_ajax('wpc_delivery_verify_async');
                $this->add_ajax_nopriv('wpc_delivery_verify_async');
                // Background variant retry. Fired by the bulk heartbeat when it early-advances
                // an image with accounted < expected. Pulls the manifest for the missing keys,
                // queues them for drain, loops up to 30s. Token-verified.
                $this->add_ajax('wpc_async_image_bg_retry');
                $this->add_ajax_nopriv('wpc_async_image_bg_retry');
                $this->add_ajax('wps_ic_get_card');  // JS polling fallback
                $this->add_ajax('wps_ic_variant_count');  // tiny JSON for 1-by-1 chip climb
                $this->add_ajax('wps_ic_check_customer_activity');  // HEAD-poll splash freshness signal
                $this->add_ajax('wps_ic_pull_manifest');  // pull-architecture tick (orch ↔ plugin)
                // Autonomous drain loop. NOT user-auth gated — uses HMAC (apikey + timestamp).
                // priv + nopriv so the loopback from the click-handler can hit it cookieless.
                add_action('wp_ajax_wpc_v2_pull_drain_loop',        'wpc_v2_pull_drain_loop_handler');
                add_action('wp_ajax_nopriv_wpc_v2_pull_drain_loop', 'wpc_v2_pull_drain_loop_handler');
                $this->add_ajax('wpc_bulk_clear_stuck_compressing');  // bulk loop ceiling cleanup
                $this->add_ajax('wpc_purge_variants');  // ops/QA surgical purge
                $this->add_ajax('wps_ic_exclude_live');
                $this->add_ajax('wps_ic_image_stats');
                $this->add_ajax('wps_ic_get_default_settings');

                $this->add_ajax('wps_ic_ajax_v2_checkbox');
                $this->add_ajax('wps_ic_ajax_v2_checkbox_batch');
                $this->add_ajax('wps_ic_purge_after_save');
                $this->add_ajax('wps_ic_ajax_checkbox');

                $this->add_ajax('wps_ic_purge_cdn');
                $this->add_ajax('wps_ic_purge_html');
                $this->add_ajax('wps_ic_purge_critical_css');
                $this->add_ajax('wps_ic_preload_page');
                $this->add_ajax('wps_ic_generate_critical_css');

                $this->add_ajax('wps_ic_dismiss_notice');
                $this->add_ajax('wps_ic_fix_notice');
                $this->add_ajax('wps_ic_save_mode');
                $this->add_ajax('wps_ic_get_optimization_status_pages');
                $this->add_ajax('wps_ic_save_optimization_status');

                $this->add_ajax('wps_ic_get_page_excludes_popup_html');
                $this->add_ajax('wps_ic_save_page_excludes_popup');
                $this->add_ajax('wps_ic_resetTest');
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

                // Live Start

                // First Run Variable
                $this->add_ajax('wps_ic_count_uncompressed_images');

                // Change Setting
                $this->add_ajax('wps_ic_settings_change');

                // Next-gen delivery (auto-verified resolver): one-option save + manual re-check
                $this->add_ajax('wpc_delivery_save');
                $this->add_ajax('wpc_delivery_recheck');

                // Exclude Image from Compress
                $this->add_ajax('wps_ic_simple_exclude_image');
                $this->add_ajax('wps_lite_connect');
                $this->add_ajax('wps_ic_live_connect');
            } else {
                // Connect
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
            $this->add_ajax('wps_ic_purge_cdn');
            $this->add_ajax('wps_ic_purge_critical_css');
            $this->add_ajax('wps_ic_preload_page');
            $this->add_ajax('wps_ic_generate_critical_css');
        }

        // Gravity Forms nonce refresh (cached-page safe). GF mints a per-session nonce into the form; on a
        // cached page (incl. any downstream CDN/Cloudflare edge cache that honours our public Cache-Control)
        // that nonce freezes, so a later/different session submits a stale nonce -> "Nonce expired". This
        // endpoint returns a fresh, CURRENT-session nonce that the front-end JS injects on first form
        // interaction, so the page can stay fully cached. priv + nopriv (runs for logged-in and logged-out).
        $this->add_ajax('wpc_gf_refresh_nonce');
        $this->add_ajax_nopriv('wpc_gf_refresh_nonce');

        // Clear error debug log action
        add_action('admin_post_wpc_clear_error_log', function () {
            check_admin_referer('wpc_clear_error_log');
            if (!current_user_can('manage_options')) {
                wp_die('Forbidden.');
            }
            delete_option('wpc_error_debug_log');
            wp_redirect(admin_url('options-general.php?page=wpcompress&view=debug_tool'));
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

    /**
     * Return a fresh, current-session nonce for a Gravity Forms form so a cached page never submits a
     * stale one ("Nonce expired"). The front-end JS (enqueues::gfNonceRefresh) injects the returned nonce
     * into the form on first interaction. No capability gate: a nonce is per-session and harmless to mint,
     * and GF still validates it on submit — this only hands the visitor the value their own session needs.
     * Field name + nonce action are filterable in case a GF build uses non-standard names.
     */
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

    /** Shared guard for the delivery endpoints. */
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

    /**
     * Save the ONE next-gen control (mode = auto|webp|off) + optional advanced override, derive
     * the legacy keys for back-compat, force a fresh verify, and return the resolved state so the
     * status card re-renders immediately.
     */
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
            // Derive the legacy keys so the ~65 existing readers stay correct.
            $ceiling = ($mode === 'auto') ? 'avif' : $mode;
            foreach (WPC_Delivery_Resolver::settings_for_ceiling($ceiling) as $k => $v) {
                $settings[$k] = $v;
            }
        }

        if (isset($_POST['override'])) {
            $ov = strtolower(sanitize_text_field(wp_unslash($_POST['override'])));
            if (!in_array($ov, ['auto', 'picture', 'htaccess', 'cdn', 'edge'], true)) $ov = 'auto';
            $settings[WPC_Delivery_Resolver::OVERRIDE_OPTION] = $ov;
            // "Edge negotiate" implies the edge-origin byte-source opt-in (Mode-B: CDN-bytes OFF,
            // edge 302-negotiates, ORIGIN serves). Mirror it so edge_usable()/edge_redirect_target()
            // see the intent; self-cleans on deselect. Overwrites any hand-set value — fine, no UI
            // wrote this option before this radio existed.
            $settings[WPC_Delivery_Resolver::EDGE_ORIGIN_OPTION] = ($ov === 'edge') ? '1' : '0';
        }

        update_option(WPS_IC_SETTINGS, $settings);
        // A Next-Gen / override change alters the emitted markup (picture/transform/natural/edge
        // URLs), so cached HTML goes stale. Purge HTML (+ the CF/WPE integration fan-out) so the
        // new delivery shows immediately. forcePurge bypasses the cache-OFF/dev-mode guard.
        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
        }
        wp_send_json_success(WPC_Delivery_Resolver::resolve_verbose(true)); // force fresh verify
    }

    /** Manual "Re-check" button → force a fresh loopback verify, return resolved state. */
    public function wpc_delivery_recheck()
    {
        self::wpc_delivery_guard();
        // Also re-probe the CSS/JS/font asset-MIME gate: the resolver verify below proves the IMAGE
        // lane only; natural_assets_on() (rewriteLogic.php) gates CSS/JS on a SEPARATE per-zone MIME
        // proof stored as a durable permanent option (no low-traffic flip-flop). Re-check is an
        // explicit re-verify event, so invalidate that proof (option + 2h negative-retry transient +
        // in-flight lock) so the next render (in wp-admin) re-probes the live edge from scratch.
        if (function_exists('delete_option'))    delete_option('wpc_v2_cf_asset_mime_ok');
        if (function_exists('delete_transient')) {
            delete_transient('wpc_v2_cf_asset_mime_retry');
            delete_transient('wpc_v2_asset_probe_inflight');
        }
        // Run the probe NOW (we're in admin) so the verdict is fresh before we return —
        // natural_assets_on() probes-and-caches when called from an admin request with the proof cleared.
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')) {
            wps_rewriteLogic::natural_assets_on();
        }
        // Re-check also ENSURES Bunny provisioning (AVIF Vary + edge rules), not just the verdict:
        // a clone/un-provisioned zone can show "verified" delivery yet 404 .avif at the edge. We're
        // in a background ajax request (not a render), so the bounded sync is safe. Reset the
        // self-heal counter + backoff first so a manual Re-check always re-attempts.
        if (function_exists('wpc_v2_provision_ensure_bg')) {
            if (function_exists('delete_option'))    delete_option('wpc_v2_selfheal_attempts');
            if (function_exists('delete_transient')) delete_transient('wpc_v2_selfheal_backoff');
            // FORCE the sync: set the pending flag so ensure_bg fires even when the witness mirror is
            // stale (e.g. a clone that copied NAV='1'). The orch's /v2/config response overwrites the
            // mirror with the TRUE state, so a wrongly-"provisioned" clone self-corrects on one click.
            if (function_exists('update_option')) update_option('wpc_v2_force_provision', 1, false);
            wpc_v2_provision_ensure_bg('recheck');
        }
        wp_send_json_success(WPC_Delivery_Resolver::resolve_verbose(true));
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

        // Local teardown runs FIRST and unconditionally — the disconnect must take effect on the front
        // end immediately, so none of it can be skipped by a slow keys-server notification below.
        if ($isAgency) {
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->deleteRemoteCFOption($apikey);
            }
        } else {
            delete_option(WPS_IC_CF);
            delete_transient('wpc_cdn_backup');
        }
        // CF is gone, so the asset-MIME proof AND the delivery verdict are stale. Drop both and queue a
        // re-verify: delivery re-resolves to the Bunny zone (or origin) on proof — no manual Re-check.
        // Verify-gated, so it only promotes on a real probe pass; until then it holds at a safe tier.
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
        // Purge the page cache so cached CF-proxied cdn.* URLs don't survive the disconnect (and the
        // re-cache re-resolves against the now-cleared verdict above).
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        }

        // Notify the keys server last, with a capped timeout — its response is unused, so it must never
        // be able to hold up (or, on a slow host, time out) the teardown above.
        if (!empty($token) && !empty($zoneInput)) {
            $cfsdk = new WPC_CloudflareAPI($token);
            $cfsdk->removeCdnBypassRule($zoneInput);
        }
        $requests->GET(WPS_IC_KEYSURL, ['action' => 'disconnectCF', 'token' => $token, 'zone' => $zoneInput, 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 15]);

        wp_send_json_success();
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
            // e.g. no zone provided — can't run the per-zone permission probes
            wp_send_json_error(['msg' => $check->get_error_message()]);
        }
        // (v7.10.04.9) Only CRITICAL scopes (Zone Read + Cache Purge) block the connection. Optional
        // scopes gate features and come back as a NON-blocking warning, so the user can still connect
        // now and add the rest later. Either way we tell them exactly what's missing (and why).
        if (is_array($check) && !empty($check['critical_missing'])) {
            wp_send_json_error([
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
        $zones = $cfapi->listZones();

        if (is_wp_error($zones)) {
            wp_send_json_error($zones->get_error_message());
        } else {
            $zonesOutput = [];
            foreach ($zones['result'] as $zone) {
                $zonesOutput[$zone['id']] = $zone['name'];
            }

            for ($i = 2; $i <= 20; $i++) {
                $zones = $cfapi->listZones($i);
                if (!empty($zones['result'])) {
                    foreach ($zones['result'] as $zone) {
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
            wp_send_json_success(array_merge($save, ['optional_missing' => $wpc_optional_missing]));
        }

        wp_send_json_error(print_r($zones, true));
    }

    public static function wpc_ic_checkCFToken()
    {
        $isAgency = defined('WPS_IC_AGENCY') && WPS_IC_AGENCY;
        if ((!$isAgency && !current_user_can('manage_wpc_settings')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $token = sanitize_text_field($_POST['token']);
        $cfapi = new WPC_CloudflareAPI($token);
        $zones = $cfapi->listZones();

        if (is_wp_error($zones)) {

            $error = 'Unkown error.';
            if ($zones->get_error_message() == 'Invalid request headers') {
                $error = 'Invalid request headers - Invalid API Token.';
            } else if ($zones->get_error_message() == 'Invalid access token') {
                $error = 'Invalid access token - Token format is correct, but the API Token is invalid.';
            } else {
                $error = $zones->get_error_message();
            }

            wp_send_json_error($error);
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
                    $zones = $cfapi->listZones($i);
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
            wp_send_json_error('We were unable to connect with Cloudflare API, seems your token is missing required privileges.');
        } else {
            wp_send_json_error('unknown-error');
        }
    }

    public static function isFeatureEnabled($featureName)
    {
        $feature = get_transient($featureName . 'Enabled');
        if (!$feature || $feature == '0') {
            return false;
        }

        return true;
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

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'refreshCF', 'token' => $token, 'zone' => $zoneInput, 'siteUrl' => $siteUrl, 'zoneName' => $zoneName, 'staticAssets' => $cf['settings']['assets'] ?? '1', 'htmlCache' => $cf['settings']['edge-cache'] ?? 'all', 'cdn' => $cf['settings']['cdn'] ?? '1', 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 30]); // 30s cap (was 120) so a transient keys blip can't pin an FPM worker for 2 min.

        if (!empty($body)) {
            $data    = (array)$body->data;
            $cfCname = $data['cfName'];
            $prevCfCname = (string) get_option(WPS_IC_CF_CNAME); // change-detect (don't demote an unchanged working host)

            if ($this->isAgencyPortal()) {
                if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                    $api::$comms->saveRemoteCFOption($apikey, null, $cfCname, $cf['settings'] ?? []);
                }
            } else {
                update_option(WPS_IC_CF_CNAME, $cfCname);
                self::$options = get_option(WPS_IC_SETTINGS);
                self::$options['cf'] = $cf['settings'];
                update_option(WPS_IC_SETTINGS, self::$options);
                // Re-arm the verified-gate ONLY when the cname actually changed, so an orphaned '0'
                // from an unrelated earlier change can't suppress a fresh working cname — without
                // demoting an unchanged, already-live host on a routine Refresh click. On a real
                // change we stage '0' (emit-gate keeps serving the current host) then promote below /
                // via the heartbeat once edge-live.
                if (!empty($cfCname) && (string) $cfCname !== $prevCfCname) {
                    update_option('wpc_cf_cname_verified', '0', false);
                }
            }

            $cfsdk = new WPC_CloudflareAPI($token);
            // Capture EVERY rule result so we can report per-component status (which piece failed +
            // why: permission / misconfig / unreachable) instead of a bare pass/fail.
            $cf_bypass = $cfsdk->addCdnBypassRule($zoneInput);
            $cf_white  = $cfsdk->whitelistIPs($zoneInput);
            $cf_static = $cfsdk->patchStaticAssetsRespectOrigin($zoneInput); // respect-origin TTL (un-pin the 30d static rule)

            // Promote now if the new host is already edge-live (1 try / 5s, no long block).
            $cf_cname_live = (!empty($cfCname) && !$this->isAgencyPortal() && method_exists($cfsdk, 'verifyCfCnameLive')
                && $cfsdk->verifyCfCnameLive($cfCname, 1, 5));
            if ($cf_cname_live) {
                update_option('wpc_cf_cname_verified', 1, false);
            }

            // Provisioning must NOT run inline on Reconnect: an inline /v2/config POST (~8s) was part
            // of a worker-pin cascade that 503'd the site. Set force_provision + SCHEDULE the sync via
            // the non-blocking reliable-fire (loopback/cron) so the button returns fast and can't hang
            // on /v2/config. Re-provision still happens + self-retries until a 2xx.
            if (!$this->isAgencyPortal()) {
                update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                }
            }

            // Per-component diagnostic. classifyResult() reads the CF error codes processResponse()
            // preserves, so each leg reports ok | permission | misconfig | unreachable with a detail
            // string. The two load-bearing rules (bypass + static) gate pass/fail; cname + v2-sync
            // are async so they're reported, not gating.
            $cf_report = [
                'bypass_rule' => WPC_CloudflareAPI::classifyResult($cf_bypass),
                'static_rule' => WPC_CloudflareAPI::classifyResult($cf_static),
                'whitelist'   => WPC_CloudflareAPI::classifyResult($cf_white),
                'cname'       => ['ok' => (bool) $cf_cname_live, 'mode' => $cf_cname_live ? 'ok' : 'pending',
                                  'detail' => $cf_cname_live ? 'Resolving (edge-live)' : 'Not yet edge-live — promotes automatically via the heartbeat once it propagates'],
                'v2_sync'     => ['ok' => true, 'mode' => 'scheduled',
                                  'detail' => 'Scheduled (non-blocking) — re-provisions out-of-band; confirms on the next heartbeat'],
            ];
            $cf_failed = [];
            foreach (['bypass_rule', 'static_rule'] as $cf_k) {
                if (empty($cf_report[$cf_k]['ok'])) $cf_failed[$cf_k] = $cf_report[$cf_k];
            }
            if (!empty($cf_failed)) {
                wp_send_json_error(['message' => 'cf-rules-incomplete', 'report' => $cf_report, 'failed' => $cf_failed]);
            }

            wp_send_json_success(['message' => 'cf-refreshed-successfully', 'report' => $cf_report]);
        }

        wp_send_json_error();
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

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'setupCF', 'token' => $token, 'zone' => $zoneInput, 'siteUrl' => $siteUrl, 'zoneName' => $cf['zoneName'] ?? $zoneName, 'staticAssets' => '1', 'htmlCache' => 'all', 'cdn' => '1', 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 30]); // 30s cap (was 120), parity with refreshCF.

        if (!empty($body)) {
            $data    = (array)$body->data;
            $cfCname = $data['cfName'];
            $prevCfCname = (string) get_option(WPS_IC_CF_CNAME); // change-detect (don't demote an unchanged working host)

            $cf['custom_cname'] = $cfCname;
            $cf['settings']     = ['assets' => '1', 'edge-cache' => 'all', 'cdn' => '1'];

            if ($this->isAgencyPortal()) {
                if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                    $api::$comms->saveRemoteCFOption($apikey, $cf, $cfCname);
                }
            } else {
                update_option(WPS_IC_CF, $cf);
                update_option(WPS_IC_CF_CNAME, $cfCname);
                // Re-arm the verified-gate ONLY when the cname actually changed, so an orphaned '0'
                // can't suppress a fresh working cname, without demoting an unchanged already-live
                // host on a reconnect. Stage '0'; promote below if edge-live, else heartbeat.
                if (!empty($cfCname) && (string) $cfCname !== $prevCfCname) {
                    update_option('wpc_cf_cname_verified', '0', false);
                }
            }

            $cfsdk = new WPC_CloudflareAPI($token);
            // Capture every rule result for the per-component diagnostic (see refreshCF).
            $cf_bypass = $cfsdk->addCdnBypassRule($zoneInput);
            $cf_white  = $cfsdk->whitelistIPs($zoneInput);
            $cf_static = $cfsdk->patchStaticAssetsRespectOrigin($zoneInput); // respect-origin TTL (un-pin the 30d static rule)

            // Promote now if the new host is already edge-live (1 try / 5s, no long block).
            $cf_cname_live = (!empty($cfCname) && !$this->isAgencyPortal() && method_exists($cfsdk, 'verifyCfCnameLive')
                && $cfsdk->verifyCfCnameLive($cfCname, 1, 5));
            if ($cf_cname_live) {
                update_option('wpc_cf_cname_verified', 1, false);
            }

            // Schedule the provisioning sync non-blocking instead of an inline /v2/config POST, so
            // Connect returns fast and the orch round-trip can't pin the worker. Provision still
            // happens + self-retries until a 2xx.
            if (!$this->isAgencyPortal()) {
                update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                }
            }

            // Per-component diagnostic (see refreshCF): each leg reports ok | permission | misconfig |
            // unreachable with a detail; bypass + static gate pass/fail.
            $cf_report = [
                'bypass_rule' => WPC_CloudflareAPI::classifyResult($cf_bypass),
                'static_rule' => WPC_CloudflareAPI::classifyResult($cf_static),
                'whitelist'   => WPC_CloudflareAPI::classifyResult($cf_white),
                'cname'       => ['ok' => (bool) $cf_cname_live, 'mode' => $cf_cname_live ? 'ok' : 'pending',
                                  'detail' => $cf_cname_live ? 'Resolving (edge-live)' : 'Not yet edge-live — promotes automatically via the heartbeat once it propagates'],
                'v2_sync'     => ['ok' => true, 'mode' => 'scheduled',
                                  'detail' => 'Scheduled (non-blocking) — provisions out-of-band; confirms on the next heartbeat'],
            ];
            $cf_failed = [];
            foreach (['bypass_rule', 'static_rule'] as $cf_k) {
                if (empty($cf_report[$cf_k]['ok'])) $cf_failed[$cf_k] = $cf_report[$cf_k];
            }
            if (!empty($cf_failed)) {
                wp_send_json_error(['message' => 'cf-connect-incomplete', 'report' => $cf_report, 'failed' => $cf_failed]);
            }

            wp_send_json_success(['message' => 'cf-connected-successfully', 'report' => $cf_report]);
        }

        wp_send_json_error('error');
    }


    public function wpc_send_critical_remote() {
        $criticalCSS = new wps_criticalCss();

        $realUrl = urldecode($_POST['realUrl']);
        $realUrl = sanitize_text_field($realUrl);
        $postID = sanitize_text_field($_POST['postID']);

        /**
         * Check does http/s exist if not add it
         */
        if (strpos($realUrl, 'https://') === false && strpos($realUrl, 'http://') === false) {
            $realUrl = 'https://' . $realUrl;
        }

        /**
         * Only keep allowed params in url
         */
        $keys = new wps_ic_url_key();

        $allowed_params = $keys->get_allowed_params();
        $parsed_url = parse_url($realUrl);
        parse_str($parsed_url['query'], $query_params);

        // Keep only the allowed parameters
        $filtered_params = array_intersect_key($query_params, array_flip($allowed_params));

        // Check if there are any disallowed parameters
        $disallowed_params = array_diff_key($query_params, array_flip($allowed_params));

        if (!empty($disallowed_params)) {
            wp_send_json_success('skipped');
        }

        // Build the new query string
        $new_query = http_build_query($filtered_params);

        // Reconstruct the URL
        $realUrl = $parsed_url['host'] . (isset($parsed_url['path']) ? $parsed_url['path'] : '') . '?' . $new_query;
        $realUrl = rtrim($realUrl, '?');
        $realUrl = rtrim($realUrl, '/');

        /**
         * Does Critical Already Exist?
         */
        $criticalCSSExists = $criticalCSS->criticalExistsAjax($realUrl);
        if (!empty($criticalCSSExists)) {
            wp_send_json_success(['exists', $realUrl, $criticalCSSExists]);
        }

        /**
         * Is Critical Ajax Already Running?
         */
        $ccss_debug = get_option('ccss_debug');
        if (empty($ccss_debug) || $ccss_debug == 'false') {
            $running = get_transient('wpc_critical_ajax_' . $postID);
            if (!empty($running) && $running == 'true') {
                wp_send_json_success(['already-running', $realUrl]);
            }
        }

        // is home
        $home = false;
        $home_url = rtrim(home_url(), '/');
        $realUrl_stripped = preg_replace('#^https?://#', '', $realUrl);
        $home_url_stripped = preg_replace('#^https?://#', '', $home_url);

        if ($home_url_stripped == $realUrl_stripped) {
            $home = true;
        }

        // Set as Running
        set_transient('wpc_critical_ajax_' . $postID, 'true', 60);

        $criticalCSS->sendCriticalUrl($realUrl, 0);

        wp_send_json_success(array('sent', $realUrl));
    }


    public function wpc_send_critical_remote_old()
    {
        $criticalCSS = new wps_criticalCss();

        $realUrl = urldecode($_POST['realUrl']);
        $realUrl = sanitize_text_field($realUrl);
        $postID = sanitize_text_field($_POST['postID']);

        /**
         * Only keep allowed params in url
         */
        $keys = new wps_ic_url_key();

        $allowed_params = $keys->get_allowed_params();
        $parsed_url = parse_url($realUrl);
        parse_str($parsed_url['query'], $query_params);

        // Keep only the allowed parameters
        $filtered_params = array_intersect_key($query_params, array_flip($allowed_params));

        // Check if there are any disallowed parameters
        $disallowed_params = array_diff_key($query_params, array_flip($allowed_params));

        if (!empty($disallowed_params)) {
            wp_send_json_success('skipped');
        }

        // Build the new query string
        $new_query = http_build_query($filtered_params);

        // Reconstruct the URL
        $realUrl = $parsed_url['host'] . (isset($parsed_url['path']) ? $parsed_url['path'] : '') . '?' . $new_query;
        $realUrl = rtrim($realUrl, '?');
        $realUrl = rtrim($realUrl, '/');

        /**
         * Does Critical Already Exist?
         */
        $criticalCSSExists = $criticalCSS->criticalExistsAjax($realUrl);
        if (!empty($criticalCSSExists)) {
            wp_send_json_success(['exists', $realUrl, $criticalCSSExists]);
        }


        /**
         * Is Critical Ajax Already Running?
         */
        $ccss_debug = get_option('ccss_debug');
        if (empty($ccss_debug) || $ccss_debug == 'false') {
            $running = get_transient('wpc_critical_ajax_' . $postID);
            if (!empty($running) && $running == 'true') {
                wp_send_json_success(['already-running', $realUrl]);
            }
        }

        // is home
        $home = false;
        $home_url = rtrim(home_url(), '/');
        $realUrl_stripped = preg_replace('#^https?://#', '', $realUrl);
        $home_url_stripped = preg_replace('#^https?://#', '', $home_url);

        if ($home_url_stripped == $realUrl_stripped) {
            $home = true;
        }

        // Set as Running
        set_transient('wpc_critical_ajax_' . $postID, 'true', 60);

        $requests = new wps_ic_requests();

        if (!empty($home)) {
            $args = ['url' => $realUrl . '?criticalCombine=true&testCompliant=true', 'version' => self::$version, 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
            $call = $requests->GET(self::$CRITICAL_URL_HOME, $args, ['timeout' => 2, 'blocking' => false]);
        } else {
            $args = ['url' => $realUrl . '?criticalCombine=true&testCompliant=true', 'home' => $home_url, 'version' => self::$version, 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
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
                // Recursively merge nested arrays
                $result[$key] = $this->custom_merge($result[$key], $value);
            } elseif (!isset($result[$key])) {
                // Add keys from $array2 only if they don't exist in $array1
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Change Settings Value
     */
    public function wps_ic_settings_change()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // Check user capabilities
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
                // Enabline Live, clear local queue
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
            // Purge CSS/JS Cache
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

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $setting_name = sanitize_text_field($_POST['setting_name']);
        $setting_value = sanitize_text_field($_POST['value']);
        $setting_checked = sanitize_text_field($_POST['checked']);

        $settings = get_option(WPS_IC_SETTINGS);

        $value = ($setting_checked == 'false') ? '0' : '1';

        // Parse "options[key]" or "options[key1][key2]" format from HTML name attribute
        preg_match_all('/\[([^\]]+)\]/', $setting_name, $matches);
        $keys = !empty($matches[1]) ? $matches[1] : [$setting_name];

        if (count($keys) === 2) {
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

        // Clear cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        wp_send_json_success(['new_value' => $value, 'setting_name' => $setting_name, 'value' => $setting_value]);
    }

    /**
     * @return void
     */
    public static function purgeBreeze()
    {
        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
    }

    /**
     * @return bool
     */
    public static function purge_cache_files()
    {
        $cache_dir = WPS_IC_CACHE;

        self::removeDirectory($cache_dir);

        return true;
    }

    /**
     * TODO: Remove?
     *
     * @param $path
     *
     * @return void
     */
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

    /**
     * @return void
     */
    public function wps_ic_get_setting()
    {
        // Verify nonce for security
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

        // Agency mode: fetch from the remote site
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

        // Agency mode: relay to remote site. sendSiteExcludes() terminates via wp_send_json_*.
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
            //To be used in excluding url from an optimization option
            $excludes = $_POST['excludes'];
            $excludes = rtrim($excludes, "\n");
            $excludes = explode("\n", $excludes);


            $wpc_excludes = get_option($setting_group);
            $wpc_excludes[$setting_name] = $excludes;

            $updated = update_option($setting_group, $wpc_excludes);
        } elseif ($setting_group == 'wpc-excludes' || $setting_group == 'wpc-inline') {
            $excludes = $_POST['excludes'];
            $excludes = rtrim($excludes, "\n");
            $excludes = explode("\n", $excludes);
            $excludes = array_filter($excludes, 'trim');

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

            // JS Delay popup saves both tabs at once: excludes + configure (lastLoadScript + deferScript)
            if (isset($_POST['lastLoadScript'])) {
                $lastLoad = sanitize_textarea_field($_POST['lastLoadScript']);
                $lastLoad = rtrim($lastLoad, "\n");
                $lastLoad = explode("\n", $lastLoad);
                $lastLoad = array_filter($lastLoad, 'trim');
                $wpc_excludes['lastLoadScript'] = $lastLoad;
            }

            if (isset($_POST['deferScript'])) {
                $defer = sanitize_textarea_field($_POST['deferScript']);
                $defer = rtrim($defer, "\n");
                $defer = explode("\n", $defer);
                $defer = array_filter($defer, 'trim');
                $wpc_excludes['deferScript'] = $defer;
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
                $cache::purgeCriticalFiles();
            }


        }


        wp_send_json_success($wpc_excludes);

    }

    /**
     * @return void
     */
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

    /**
     * @return void
     */
    public function wps_ic_critical_get_assets()
    {
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $criticalCSS = new wps_criticalCss();
        $count = $criticalCSS->sendCriticalUrlGetAssets('', $_POST['pageID']);
        wp_send_json_success($count);
    }

    /**
     * @return void
     */
    public function wps_ic_ajax_v2_checkbox()
    {
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $options = get_option(WPS_IC_SETTINGS);
        $wpc_livecdn_before = isset($options['live-cdn']) ? (string) $options['live-cdn'] : ''; // detect a live-cdn flip on this per-key save (path fired no purges before)

        $optionName = sanitize_text_field($_POST['optionName']);
        $optionValue = sanitize_text_field($_POST['optionValue']);

        $optionName = explode(',', $optionName);

        // CF settings are stored in WPS_IC_CF['settings'], not WPS_IC_SETTINGS
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

            // Recalculate live-cdn when any serve option changes.
            // This per-key serve handler intentionally does NOT mirror serve[jpg]→png/gif/svg (the
            // consolidated "Images" model): it's unreachable for the Images tile, which saves via the
            // BATCH handler (which mirrors). If an instant-save Images variant is ever added, mirror
            // jpg→png/gif/svg here too. (admin_init reconcile in wp-compress-core.php also heals on load.)
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

            // Auto-scan homepage when Local font hosting is first enabled
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

            // Recalculate live-cdn when css, js, or fonts changes
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

        // A live-cdn flip re-routes every image URL (origin <img> ⇄ CDN <picture>/transform). This
        // per-key save fired no purge before, so the page kept serving stale cached markup until a
        // manual ?v= bust. Fire the full fleet purge on a flip.
        $wpc_livecdn_after = isset($options['live-cdn']) ? (string) $options['live-cdn'] : $wpc_livecdn_before;
        if ($wpc_livecdn_before !== $wpc_livecdn_after) {
            self::wpc_fleet_frontend_purge('per-key save');
        }

        wp_send_json_success(['newValue' => $newValue, 'optionName' => $optionName]);
    }

    /**
     * Batch save — applies all checkbox changes in a single DB read/write cycle.
     * Fixes race condition where parallel per-checkbox AJAX calls overwrite each other.
     */
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

        // Agency mode: start empty so only changed keys are sent to the user site.
        // Using the portal's own WPS_IC_SETTINGS as the base would flood the payload
        // with the portal's unrelated values (e.g. serve.jpg=0) and overwrite the
        // user site's real settings for every untouched key.
        $options = $this->isAgencyPortal() ? [] : get_option(WPS_IC_SETTINGS);
        $cf = null;
        $serveChanged = false;
        $assetChanged = false;
        $imagesChanged = false; // did the consolidated "Images" tile (serve,jpg) change this save?
        $nextgenChanged = false; // did the Next-Gen switch (options[wpc_nextgen]) change this save?
        $overrideChanged = false; // did the Advanced mechanism override (options[wpc_delivery_override]) change this save?

        foreach ($changes as $change) {
            $optionName = explode(',', sanitize_text_field($change['name']));
            $optionValue = sanitize_text_field($change['value']);

            if (count($optionName) > 1 && $optionName[0] === 'cf') {
                // CF settings stored in WPS_IC_CF['settings']
                if ($cf === null) {
                    $cf = get_option(WPS_IC_CF);
                    if (!empty($cf) && !isset($cf['settings'])) {
                        $cf['settings'] = ['assets' => '1', 'edge-cache' => 'all', 'cdn' => '1'];
                    }
                }
                if (!empty($cf)) {
                    $cf['settings'][$optionName[1]] = $optionValue;
                }
            } elseif (count($optionName) > 1) {
                $options[$optionName[0]][$optionName[1]] = $optionValue;
                if ($optionName[0] === 'serve') {
                    $serveChanged = true;
                    if (isset($optionName[1]) && $optionName[1] === 'jpg') $imagesChanged = true; // the consolidated "Images" tile
                }
            } else {
                $name = $optionName[0];
                $options[$name] = $optionValue;
                if (in_array($name, ['css', 'js', 'fonts'])) $assetChanged = true;
                if ($name === 'wpc_nextgen') $nextgenChanged = true; // Next-Gen card saves natively through this batch
                if (class_exists('WPC_Delivery_Resolver') && $name === WPC_Delivery_Resolver::OVERRIDE_OPTION) $overrideChanged = true; // Advanced override saves natively through this batch

                // Auto-scan homepage when Local font hosting is first enabled
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

        // The single "Images" tile binds to serve[jpg] but represents ALL image formats. Mirror
        // jpg → png/gif/svg whenever it changes so one toggle drives every type (the CDN rewriter
        // checks each format separately). Runs BEFORE the live-cdn recalc so it sees the synced keys.
        if ($imagesChanged && isset($options['serve']) && is_array($options['serve'])) {
            $imgVal = (!empty($options['serve']['jpg']) && $options['serve']['jpg'] == '1') ? '1' : '0';
            $options['serve']['png'] = $imgVal;
            $options['serve']['gif'] = $imgVal;
            $options['serve']['svg'] = $imgVal;
        }

        // The Next-Gen card saves natively through this batch (options[wpc_nextgen]). Validate the
        // enum + derive the legacy ceiling keys (generate_webp/picture_webp/picture_avif) exactly
        // like wpc_delivery_save does, so the ~65 legacy readers stay consistent with the ONE control.
        // Idempotent when wpc_nextgen is present-but-unchanged.
        if ($nextgenChanged && class_exists('WPC_Delivery_Resolver')) {
            $m = strtolower((string) ($options['wpc_nextgen'] ?? ''));
            if (!in_array($m, ['auto', 'webp', 'off'], true)) $m = 'auto';
            $options['wpc_nextgen'] = $m;
            // One-shot: on enabling Next-Gen, force the next card render to run a FULL verify so it
            // auto-lands on the best verified tier instead of the safe fallback a not-yet-probed save
            // resolves to (the auto-check the user otherwise had to trigger via Re-check). Consumed by
            // the card render.
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

        // The Advanced mechanism override saves natively through this batch
        // (options[wpc_delivery_override]). Validate the enum + replicate wpc_delivery_save's
        // EDGE_ORIGIN_OPTION coupling: "edge" opts into edge-origin byte-serving (Mode-B); any other
        // value clears it. The forced re-verify below (gated on $overrideChanged) makes a forced
        // clean-URL tier actually probe + promote, since the override is intentionally NOT part of
        // env_signature() (so it never causes fleet churn).
        if ($overrideChanged && class_exists('WPC_Delivery_Resolver')) {
            $ov = strtolower((string) ($options[WPC_Delivery_Resolver::OVERRIDE_OPTION] ?? 'auto'));
            if (!in_array($ov, ['auto', 'picture', 'htaccess', 'cdn', 'edge'], true)) $ov = 'auto';
            $options[WPC_Delivery_Resolver::OVERRIDE_OPTION]    = $ov;
            $options[WPC_Delivery_Resolver::EDGE_ORIGIN_OPTION] = ($ov === 'edge') ? '1' : '0';
        }

        // Recalculate live-cdn ONCE with all changes applied
        if ($serveChanged || $assetChanged) {
            $cdnOn = false;
            // Only check image serve keys (css/js/fonts in serve array are stale legacy values)
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

        // Derive serve['fonts'] from the flat Fonts toggle. The live per-key save never wrote
        // serve['fonts'] — it stayed a stale legacy value, so the CDN font rewriter in combine_css
        // (gated on serve['fonts']) was unreachable: flipping Fonts ON silently did nothing for
        // @font-face inside external CSS (Elementor, theme CSS). Deriving it here drives the existing
        // rewriter, and (since $options is relayed verbatim to the agency portal below) also fixes the
        // agency-relay path. Idempotent + self-healing. Flag-gated (default OFF).
        if (apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))) {
            if (!isset($options['serve']) || !is_array($options['serve'])) {
                $options['serve'] = isset($options['serve']) ? (array) $options['serve'] : [];
            }
            $options['serve']['fonts'] = (!empty($options['fonts']) && $options['fonts'] == '1') ? '1' : '0';
        }

        // Sync qualityLevel -> optimization (CDN URL reads 'optimization', UI saves 'qualityLevel')
        if (isset($options['qualityLevel'])) {
            $qualityMap = ['1' => 'lossless', '2' => 'intelligent', '3' => 'ultra'];
            $options['optimization'] = $qualityMap[$options['qualityLevel']] ?? 'intelligent';
        }

        // Agency mode: relay to remote site instead of saving locally.
        if ($this->isAgencyPortal()) {
            global $api;
            $apikey = sanitize_text_field($_POST['apikey'] ?? '');
            if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                $api::$comms->sendSiteSettings($apikey, ['apikey' => $apikey, 'options' => $options]);
            }
            wp_send_json_success(['saved' => count($changes)]);
        }

        // Phase 1 — detect Modern Image Delivery toggle flip to ON → fire async pre-warm
        $prevSettings = get_option(WPS_IC_SETTINGS, []);
        $prevModern = !empty($prevSettings['modern_image_delivery']) && $prevSettings['modern_image_delivery'] == '1';
        $newModern = !empty($options['modern_image_delivery']) && $options['modern_image_delivery'] == '1';
        $modernFlippedOn = !$prevModern && $newModern;
        $wpc_livecdn_before_b = isset($prevSettings['live-cdn']) ? (string) $prevSettings['live-cdn'] : ''; // detect live-cdn flip on batch save

        update_option(WPS_IC_SETTINGS, $options);

        // A live-cdn flip re-routes every image URL; the batch save's purge (wps_ic_purge_after_save)
        // gates on $htmlPurgeKeys, which does NOT include live-cdn, so a flip via serve/css/js could
        // leave the CF edge stale. Fire the full fleet purge directly on a transition (request-guarded
        // → no double purge if wps_ic_purge_after_save also runs).
        if ($wpc_livecdn_before_b !== (isset($options['live-cdn']) ? (string) $options['live-cdn'] : $wpc_livecdn_before_b)) {
            self::wpc_fleet_frontend_purge('batch save');
        }

        // Seamless re-verify on CDN-tile changes. Flipping serve/css/js/fonts changes the resolver's
        // env-signature, which (promote-on-proof) drops delivery to the safe tier until a re-verify
        // proves the new state — otherwise that wait is the transform-<picture> interim window.
        // Schedule the canonical verify NOW + spawn cron: non-blocking, the promote (+ its one-time
        // page-cache purge) lands seconds later, no manual Re-check. On hosts where the cron loopback
        // can't fire (Basic-Auth staging), the scheduled event runs on the next natural cron tick.
        // $nextgenChanged is included belt-and-braces: the blocking verify below is the primary path
        // for Next-Gen flips, but if it dies mid-request (slow edge vs PHP max_execution) the state is
        // left unverified with no retry → the site serves the legacy-transform fallback indefinitely.
        // The scheduled verify self-heals that (idempotent when the blocking verify already succeeded).
        if (($serveChanged || $assetChanged || $nextgenChanged || $overrideChanged) && class_exists('WPC_Delivery_Resolver')) {
            if (!wp_next_scheduled('wpc_delivery_verify')) {
                wp_schedule_single_event(time(), 'wpc_delivery_verify');
            }
            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
            // Primary seamless leg: non-blocking admin-ajax loopback (full core loads there, unlike
            // wp-cron). One-shot token; the worker runs the verify ~1-2s after the save so the promote
            // (+ page-cache purge) lands before the next reload. The scheduled event above is the
            // fallback for hosts where loopback is unreliable.
            $vtok = function_exists('wp_generate_password') ? wp_generate_password(20, false, false) : md5(uniqid('', true));
            set_transient('wpc_delivery_verify_tok', $vtok, 120);
            // Local-vhost loopback (kills the "fake success behind a CDN/WAF edge" class: a blocking=false
            // POST to admin_url()'s PUBLIC host connects to the edge on a datacenter-IP site → truthy but
            // never lands on local PHP-FPM). Connect-only via the shared helper (127.0.0.1→localhost→public
            // host, Host:/SNI = public host); cookieless token auth; fire-and-forget. wp-cron stays backstop.
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

        // A Next-Gen flip changes the resolver env-signature. Verify BLOCKING here (only this case)
        // so the native post-save reload renders the FINAL promoted state. Also fires the one-time
        // promote purge. $overrideChanged joins it: a forced verify is REQUIRED for an override change
        // because the override is intentionally NOT part of env_signature() — so a forced clean-URL
        // tier (cdn/edge/htaccess) would otherwise reuse a stale cached verify and never probe/promote.
        // resolve_verbose(true)'s fingerprint-gated maybe_purge_on_delivery_change() purges HTML/edge
        // only when the emitted markup actually changes. Degrade-safe: a wrong/unverified forced
        // mechanism warns and falls through to the Auto ladder, never breaks.
        if (($nextgenChanged || $overrideChanged) && class_exists('WPC_Delivery_Resolver')) {
            WPC_Delivery_Resolver::resolve_verbose(true);
        }

        if ($modernFlippedOn) {
            // Skip loopback pre-warm on Basic-Auth sites (staging/dev) — would 401.
            // Queue still fills from normal renders; shutdown hook drains.
            $skip_loopback = function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth();

            if (!$skip_loopback) {
                // Fire non-blocking pre-warm worker so it doesn't block the save response. Local-vhost
                // loopback (a blocking=false POST to the PUBLIC host = the CDN/WAF edge on a datacenter-IP
                // site → truthy, never lands on local PHP-FPM). Cookieless: wpc_modern_delivery_prewarm is
                // nopriv + runs no user/nonce check, so dropping cookies is behaviour-preserving. Shutdown
                // hook drains as the backstop.
                $pw_parts = wp_parse_url(admin_url('admin-ajax.php'));
                if (!empty($pw_parts['host'])) {
                    $pw_https = (!empty($pw_parts['scheme']) && $pw_parts['scheme'] === 'https');
                    $pw_port  = !empty($pw_parts['port']) ? (int) $pw_parts['port'] : ($pw_https ? 443 : 80);
                    $pw_host  = (string) $pw_parts['host'];
                    $pw_path  = (!empty($pw_parts['path']) ? $pw_parts['path'] : '/') . '?action=wpc_modern_delivery_prewarm';
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

    /**
     * Purge all caches — called as separate AJAX request after settings are saved.
     */
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

        // Settings that affect cached HTML output
        $htmlPurgeKeys = [
            // Fonts
            'replace-fonts', 'font-display', 'icon-font-display',
            'preload-crit-fonts', 'fontawesome-lazy',
            // CDN file types
            'css', 'js', 'fonts', 'lazy', 'nativeLazy',
            'serve,jpg', 'serve,png', 'serve,gif', 'serve,svg',
            // Image optimization (changes CDN URL parameters in HTML)
            'generate_adaptive', 'generate_webp', 'picture_webp', 'picture_avif',
            'retina', 'background-sizing', 'optimize-lcp', 'modern_image_delivery',
            'qualityLevel', 'local_qualityLevel', 'local_optimization',
            'maxWidth', 'lazySkipCount',
            // These two also alter the emitted HTML and were missing, so flipping them alone (e.g.
            // Safe mode sets both to 0) changed the markup WITHOUT purging the CF edge → stale HTML.
            // 'avif-natural-source' picks natural -WxH.avif vs the wp:2 transform <source>;
            // 'fetchpriority-high' adds/removes fetchpriority="high" on the LCP img + preload.
            'avif-natural-source', 'fetchpriority-high', 'single-url-image-format',
            // Performance
            'critical,css', 'delay,js', 'delay-js-v2',
            'minify,html', 'minify,css', 'minify,js',
            // CF / CDN routing
            'cf,cdn', 'cf,assets', 'eu-routing',
        ];
        // Settings that affect Critical CSS content (CSS-only changes)
        $critPurgeKeys = ['replace-fonts', 'font-display', 'icon-font-display', 'preload-crit-fonts', 'css', 'fonts', 'minify,css', 'critical,css'];

        $changedKeys = !empty($_POST['changed_keys']) ? array_map('sanitize_text_field', (array) $_POST['changed_keys']) : [];

        $needsHtmlPurge = !empty($changedKeys) && !empty(array_intersect($changedKeys, $htmlPurgeKeys));
        $needsCritPurge = !empty($changedKeys) && !empty(array_intersect($changedKeys, $critPurgeKeys));

        if ($needsHtmlPurge) {
            // Purge WPC HTML cache + bump ICV hashes
            delete_transient('wps_ic_css_cache');
            delete_option('wps_ic_modified_css_cache');
            delete_option('wps_ic_css_combined_cache');
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll(false, true, false, false, true);
            $cache::purgeCombinedFiles();

            // Purge third-party caches
            self::purgeBreeze();
            self::purge_cache_files();
            if (function_exists('rocket_clean_domain')) rocket_clean_domain();
            if (defined('LSCWP_V')) do_action('litespeed_purge_all');
            if (defined('WPHB_VERSION')) do_action('wphb_clear_page_cache');
        }

        if ($needsCritPurge) {
            // Purge WPC Critical CSS
            global $wpdb;
            $options_table = $wpdb->options;
            $wpdb->query($wpdb->prepare("DELETE FROM $options_table WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_wpc_critical_key_') . '%', $wpdb->esc_like('_transient_timeout_wpc_critical_key_') . '%'));
            if (!isset($cache)) $cache = new wps_ic_cache_integrations();
            $cache::purgeCriticalFiles();
        }

        // Font-display change → re-bake the localized font cache (wp-cio-fonts) IN PLACE so it stops
        // disagreeing with the new setting immediately. The directive is baked into the cache .css at
        // localization time; without this the cache keeps its old value, overrides the inline/crit faces
        // when the deferred stylesheet lands, and FOUTs until a manual Purge & Rescan. No re-download — just
        // rewrites font-display in the cached stylesheets. Try/catch so a re-bake error can never break the save.
        if (!empty($changedKeys) && in_array('font-display', $changedKeys, true) && class_exists('wps_ic_fonts')) {
            try {
                $wpc_fonts_rebake = new wps_ic_fonts();
                if (method_exists($wpc_fonts_rebake, 'rebakeFontDisplay')) {
                    $wpc_fonts_rebake->rebakeFontDisplay();
                }
            } catch (\Throwable $e) {
                // A font re-bake error must never break the settings save.
            }
        }

        // Operator rule: when CF is connected, ANY settings save must purge the CF edge HTML —
        // unconditionally, regardless of which setting changed or whether the CF optimization toggles
        // are on. The page HTML is cached at the edge, so any markup/hash change leaves stale HTML
        // until TTL. The $needsHtmlPurge gate above is KEY-SCOPED and its CF leg rides purgeAll's
        // integration hook — neither guarantees a CF edge purge on every save. So fire a direct CF
        // zone purge whenever WPS_IC_CF is configured. Fire-and-forget (purgeCacheAsync, no hang),
        // try/catch-guarded so a CF API error can't break the save. No-op when CF isn't connected.
        $wpc_cf_live   = get_option(WPS_IC_CF);
        $wpc_cf_active = !empty($wpc_cf_live) && !empty($wpc_cf_live['token']) && !empty($wpc_cf_live['zone']);
        // WP Engine parity. The always-on block below was CF-only, leaving WPE to the registration-
        // lagged `wps_ic_purge_all_cache` fan-out (which only fires if WpeCommon was is_active() at the
        // last integrations::init() rebuild) — so on a WPE-only site saving a non-$htmlPurgeKeys key,
        // nothing purged. Fire WPE DIRECTLY here, independent of the fan-out's registration state, and
        // run the block whenever EITHER integration is live.
        $wpc_wpe_active = class_exists('WpeCommon');
        if ($wpc_cf_active || $wpc_wpe_active) {
            // Local HTML cache must invalidate together with the edge/host cache so a re-render can't
            // re-cache stale HTML. removeHtmlCacheFiles('all') also fires do_action('wps_ic_purge_all_cache')
            // → Varnish + the CF/WPE/third-party fan-out, so the registered legs still run as a backstop.
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            }

            // WP Engine — direct, registration-independent flush (idempotent; safe to double-fire vs the
            // fan-out leg). Each call is method-guarded so a partial WpeCommon surface can't fatal the save.
            if ($wpc_wpe_active) {
                try {
                    if (method_exists('WpeCommon', 'purge_memcached'))     { WpeCommon::purge_memcached(); }
                    if (method_exists('WpeCommon', 'purge_varnish_cache')) { WpeCommon::purge_varnish_cache(); }
                    if (method_exists('WpeCommon', 'clear_cdn_cache'))     { WpeCommon::clear_cdn_cache(); }
                } catch (\Throwable $e) {
                    // A WPE flush error must never break the settings save.
                }
            }

            // Cloudflare edge — fire-and-forget (purgeCacheAsync, no hang), try/catch-guarded.
            if ($wpc_cf_active) {
                if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR') && file_exists(WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php')) {
                    @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                if (class_exists('WPC_CloudflareAPI')) {
                    try {
                        $wpc_cfapi_save = new WPC_CloudflareAPI($wpc_cf_live['token']);
                        if ($wpc_cfapi_save) {
                            if (method_exists($wpc_cfapi_save, 'purgeCacheAsync')) {
                                $wpc_cfapi_save->purgeCacheAsync($wpc_cf_live['zone']);
                            } else {
                                $wpc_cfapi_save->purgeCache($wpc_cf_live['zone']);
                            }
                        }
                    } catch (\Throwable $e) {
                        // A CF API error must never break the settings save.
                    }
                }
            }
        }

        wp_send_json_success();
    }

    /**
     * @return void
     * @since 5.20.01
     */
    public function wps_ic_generate_critical_css()
    {
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        $criticalCSS = new wps_criticalCss($_SERVER['HTTP_REFERER']);
        $criticalCSS->generateCriticalAjax();

        wp_send_json_success();
    }

    /**
     * @return void
     * @since 5.20.01
     */
    public function wps_ic_preload_page()
    {
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        $url = WPS_IC_PRELOADER_API_URL;

        self::$Requests->POST($url, ['single_url' => $_SERVER['HTTP_REFERER'], 'apikey' => $options['api_key']]);

        // Dropped a cosmetic sleep(3): the preload POST above already completed, so the stall only
        // pinned the worker for 3s — a worker-exhaustion footgun under concurrency.
        wp_send_json_success();
    }

    /**
     * @return void
     * @since 5.20.01
     */
    public function wps_ic_purge_html()
    {
        if ((!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll(false, true, false, false, true);

        // Todo: maybe remove?
        $cache::purgeCombinedFiles();

        // "Purge HTML cache" must also flush the front-end HTML caches (WP Engine page cache + CF
        // edge). purgeAll()'s do_action('wps_ic_purge_all_cache') fan-out only reaches CF/WPE if each
        // was is_active() at the last integrations::init() rebuild (gated WPC admin paths) — so the
        // saved option lags a freshly-connected CF token / just-loaded WpeCommon and silently misses
        // them. Fire both DIRECTLY here regardless of registration state. Idempotent with the fan-out;
        // logged to wpc_purge_debug_log.
        //
        // CF purgeCacheAsync = purge_everything (zone-wide). The image CDN host bypasses CF cache, so
        // this clears the cached HTML; a strictly HTML-host-scoped purge would need a by-host SDK method.
        $wpc_purged = [];
        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached'))     { WpeCommon::purge_memcached();     $wpc_purged[] = 'wpe-memcached'; }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) { WpeCommon::purge_varnish_cache(); $wpc_purged[] = 'wpe-varnish'; }
            if (method_exists('WpeCommon', 'clear_cdn_cache'))     { WpeCommon::clear_cdn_cache();     $wpc_purged[] = 'wpe-cdn'; }
        }
        $cfSettings = function_exists('get_option') ? get_option(WPS_IC_CF) : false;
        if (!empty($cfSettings['token']) && !empty($cfSettings['zone'])) {
            if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
            }
            if (class_exists('WPC_CloudflareAPI')) {
                $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
                if ($cfapi) {
                    // BLOCKING purge (purgeCache, not purgeCacheAsync): purgeCacheAsync's 10ms timeout
                    // can't complete a TLS handshake to the external CF API, so it frequently never
                    // lands — a real reason CF wasn't clearing. A blocking call (~300-500ms, 5s ceiling)
                    // returns a result we can verify in the debug log.
                    $cfRes = $cfapi->purgeCache($cfSettings['zone']);
                    $wpc_purged[] = is_wp_error($cfRes) ? 'cf-edge-FAIL' : 'cf-edge';
                }
            }
        }
        if (!empty($wpc_purged)) {
            $plog = get_option('wpc_purge_debug_log', []);
            $plog[] = date('Y-m-d H:i:s') . ' | Purge HTML (direct): ' . implode(', ', $wpc_purged);
            update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
        }

        // Dropped a cosmetic sleep(3): the purge above already completed synchronously, so blocking
        // the worker for 3s only added latency + exhaustion risk.
        delete_transient('wps_ic_purging_cdn');
        wp_send_json_success();
    }

    /**
     * Fleet front-end purge: WPC HTML + Varnish + WP Engine + CF edge, fired DIRECTLY
     * (not via the registration-lagged `wps_ic_purge_all_cache` action fan-out, which misses CF on a
     * just-changed state, the cause of "needed many manual ?v= strings"). Call on ANY live-cdn
     * on/off transition: it re-routes every emitted image URL, so cached HTML/edge must die or the
     * page keeps serving stale (broken) markup. Request-guarded (one fleet purge per request);
     * every external dependency is class/method-guarded → safe in admin-ajax. CF purge is BLOCKING
     * (~300-500ms) like the proven wps_ic_purge_html idiom.
     */
    public static function wpc_fleet_frontend_purge($reason = '', $skip_cf = false)
    {
        static $done = false;
        if ($done) return; // one fleet purge per request (CF/WPE legs are direct, not purgeAll-deduped)
        $done = true;

        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, false, true); // forcePurge + Varnish
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

        // $skip_cf lets a caller that ALREADY purged the CF zone (e.g. wps_ic_purge_cdn via
        // wpc_customer_purge(mode='all') = CF full-zone purge_everything) skip a second identical
        // zone-wide CF purge here — same token+zone, byte-identical duplicate.
        $cf = function_exists('get_option') ? get_option(WPS_IC_CF) : false;
        if (!$skip_cf && !empty($cf['token']) && !empty($cf['zone'])) {
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
        }

        $plog = get_option('wpc_purge_debug_log', []);
        if (!is_array($plog)) $plog = [];
        $plog[] = date('Y-m-d H:i:s') . ' | live-cdn flip (' . $reason . '): ' . implode(', ', $purged);
        update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
    }

    public function wpc_purgeCF($return = false)
    {
        $cfSettings = get_option(WPS_IC_CF);

        // Guard the keys, not just the array: a partially-populated WPS_IC_CF would otherwise throw
        // an undefined-array-key warning on ['zone']/['token'].
        if (!empty($cfSettings) && !empty($cfSettings['zone']) && !empty($cfSettings['token'])) {
            $zone = $cfSettings['zone'];
            $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
            if ($cfapi) {
                $cfapi->purgeCache($zone);
                // Dropped sleep(6): CF purge is async edge-side regardless, so the block did nothing
                // for propagation — pure exhaustion risk.
            }
        }

        if ($return) {
            return true;
        }

        wp_send_json_success();
    }

    /**
     * @return void
     * @since 5.20.01
     */
    public function wps_ic_purge_critical_css()
    {
        if ((!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        // Delete Transient for Critical Lock
        global $wpdb;

        // Get the correct options table name with prefix
        $options_table = $wpdb->options;

        // Delete transient values
        $wpdb->query($wpdb->prepare("DELETE FROM $options_table WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_wpc_critical_key_') . '%', $wpdb->esc_like('_transient_timeout_wpc_critical_key_') . '%'));

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        $cache = new wps_ic_cache_integrations();
        $cache::purgeCriticalFiles();
        // (v7.03.91) Purge the PAGE caches too, or this is a no-op that creates a permanent wall: the crit
        // files get deleted but the cached HTML keeps serving (Varnish/WPC/3rd-party HIT) → WordPress never
        // re-renders → crit never regenerates. $varnish=true (2nd arg) fires purgeVarnish (full-site, since
        // url_key=false); $forcePurge=true (5th arg) bypasses the cache-off/purge-hooks/dev-mode early-return
        // so an explicit admin purge ALWAYS clears the page cache + the integration fan-out.
        $cache::purgeAll(false, true, false, false, true);
        // (v7.03.91) Object cache: the crit-gen LOCK ('wpc_critical_key_*') is a 5-min transient; under an
        // external object cache (Redis/Memcached) it lives there, NOT the options table — so the raw SQL
        // DELETE above misses it and the surviving lock would throttle the regen for up to 5 min. Flush the
        // object cache so the lock can't outlive the purge.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        // (v7.03.91) Arm the crit-regen-pending flag so v2-lcp-nocache.php emits no-store on crit-LESS renders
        // until crit comes back — so a re-render during the crit-gen window can't re-cache a hint-less page and
        // re-wall the regen. Bounded TTL: self-clears if crit never returns (e.g. producer down).
        if (function_exists('set_transient')) {
            set_transient('wpc_crit_regen_pending', 1, 10 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60));
        }

        // Dropped a cosmetic sleep(3): purge already completed synchronously above.
        delete_transient('wps_ic_purging_cdn');
        wp_send_json_success();
    }

    /**
     * @return void
     * @since 5.20.01
     */
    public function wps_ic_purge_cdn()
    {
        if ((!current_user_can('manage_wpc_settings') && !current_user_can('manage_wpc_purge')) || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        // --- LOCAL caches (unchanged): html/css/js + critical ---
        $cache = new wps_ic_cache_integrations();
        $cache::purgeCriticalFiles();
        $cache::purgeAll();

        // --- css/js hash rotation (unchanged) ---
        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;
        $options['js_hash'] = $JSHash;

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        $log = new wps_ic_log();
        $log->logCachePurging($oldOptions, $options, 'wps_ic_purge_cdn');

        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        set_transient('wps_ic_purging_cdn', 'true', 30);

        // Real CDN-object eviction. The local purge + hash rotation above never touched the Bunny
        // pod-fs / CF-edge image layer, so a poisoned object (e.g. a corrupt OTF-encoded logo.avif
        // cached at max-age=31536000) survived this button — only a ?query cache-key change appeared
        // to fix it. wpc_customer_purge(mode='all') fans out to CF full-zone purge_everything + orch
        // /v2/customer-purge (customer Bunny PZ + cdn-mc PZ + pod-fs LRU fleet). apikey '' self-resolves;
        // reason 'manual_purge' is the valid default enum. BLOCKING (admin-initiated, user-waiting) and
        // every remote leg is timeout-capped. Bump the PHP time limit to 60s so a pathological tail
        // can't fatal the request, then report both verdicts.
        $cdn_report = ['attempted' => false];
        if (function_exists('wpc_customer_purge')) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(60);
            }
            $purge = wpc_customer_purge('', 'all', [], 'manual_purge', true); // '' → this site's apikey; blocking
            $cdn_report = [
                'attempted'   => true,
                'ok'          => !empty($purge['ok']),
                'duration_ms' => isset($purge['duration_ms']) ? (int) $purge['duration_ms'] : 0,
                'layers'      => isset($purge['layers']) ? $purge['layers'] : [],
            ];
        }

        // Also drop the front-end HTML/edge layer so visitors
        // re-fetch the freshly-purged image instead of re-priming CF from
        // cached markup. Complementary to the image-object purge above;
        // request-guarded and fully class/method-guarded (safe in admin-ajax).
        if (method_exists(__CLASS__, 'wpc_fleet_frontend_purge')) {
            // Skip CF in the fleet purge ONLY if wpc_customer_purge actually ran above (it does the CF
            // full-zone purge as part of mode='all'). If that function is unavailable, the CF purge never
            // fired — so DON'T skip it here, or the button would silently leave the CF edge stale.
            self::wpc_fleet_frontend_purge('manual purge button', function_exists('wpc_customer_purge'));
        }

        // Legacy keys-server trigger, kept for back-compat (a no-op for Bunny
        // but may invalidate keys-server-side caches). Fire it non-blocking:
        // Requests->GET defaults to a 30s BLOCKING wp_remote_get (and its
        // timeout==0→30 guard means we must pass 1, not 0), which would re-add
        // up to 30s of admin-AJAX wall time on top of the real purge above. Its
        // result was already dead code, so fire-and-forget it.
        self::$Requests->GET(
            WPS_IC_KEYSURL,
            ['action' => 'cdn_purge', 'apikey' => $options['api_key']],
            ['timeout' => 1, 'blocking' => false, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]
        );

        delete_transient('wps_ic_purging_cdn');

        // Always success: the LOCAL purge genuinely completed. Surface the CDN
        // result as a sub-object so the UI can show a partial CDN failure
        // (e.g. orch unreachable) instead of either lying or falsely erroring.
        wp_send_json_success([
            'local' => true,
            'cdn'   => $cdn_report,
        ]);
    }

    /**
     * Exclude the image
     * @since 4.0.0
     */
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

        // Get scaled file size
        $filesize = filesize($filedata);
        $wpScaledFilesize = wps_ic_format_bytes($filesize, null, null, false);

        // Get original filesize
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

        // Return updated card HTML using the same render method as page load
        $output = $wps_ic->media_library->compress_details($attachment_id);
        wp_send_json_success(['html' => $output]);
    }

    /**
     * Exclude the image
     * @since 4.0.0
     */
    public function wps_ic_simple_exclude_image()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;
        $wps_ic = new wps_ic_compress();
        $wps_ic->simple_exclude($_POST, 'html');
    }

    /**
     * Connect Multsites With API
     */
    public function wps_ic_api_mu_connect()
    {
        global $wps_ic;

        // Is localhost?
        $sites = get_sites();

        // API Key
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


    /**
     * Lite Connect
     */
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


    /**
     * Connect With API
     */
    public function wps_ic_live_connect()
    {
        $connect = new wps_ic_connect();
        $call = $connect->connect();
    }

    /**
     * Deauthorize site with remote api
     */
    public function wps_ic_deauthorize_api()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;

        // Vars
        $site = site_url();
        $options = new wps_ic_options();
        $apikey = $options->get_option('api_key');

        // Verify API Key is our database and user has is confirmed getresponse
        self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'disconnect', 'apikey' => $apikey, 'site' => urlencode($site)]);

        $options->set_option('api_key', '');
        $options->set_option('response_key', '');
        $options->set_option('orp', '');
    }

    /**
     * Heartbeat
     */
    public function wps_ic_media_library_heartbeat()
    {
        global $wps_ic, $wpdb;
        $html = [];

        // FPM telemetry — capture REQUEST_TIME_FLOAT so we measure total wall (FPM queue wait +
        // handler work). The "chip stagnation" symptom under bulk compress is FPM saturation.
        $hb_request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        // Fast-path early return for the empty-heartbeat case (no active images + no pending heartbeat
        // transients): skips the LIKE-on-options query + compress_details renders when users are just
        // sitting on the media library page with nothing in flight (~50-150ms → ~2ms).
        $active_raw_fast = isset($_POST['active']) ? $_POST['active'] : [];
        $has_active = is_array($active_raw_fast) && !empty($active_raw_fast);
        if (!$has_active) {
            // Quick existence check: LIMIT 1 = single-row scan (~0.5ms) vs the full LIKE scan below.
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

        // Stage 2: heartbeat-driven chip + savings + recent[] updates. Client sends `active=[ids]`
        // listing cards mid-compress; for each we compute chip/savings/recent/warming/status from
        // current meta. One heartbeat serves ALL active images (replaces the per-image count poller
        // + SSE FPM cost). IDs sanitized to positive ints, capped at 50, fresh per-call meta read,
        // merged with transient-driven $html[$imageID] when both paths fire for the same image.
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
                    // Merge — transient path already set html/event/status.
                    // Active-list adds chip/savings/recent/warming without
                    // overwriting those fields.
                    foreach (['chip', 'savings_pct', 'recent', 'warming'] as $k) {
                        if (isset($aug[$k])) $html[$imageID][$k] = $aug[$k];
                    }
                } else {
                    // Active-list-only: image is active but no transient was
                    // set (no callback fired recently). Returns chip/savings/
                    // recent/warming/status (no html, so JS doesn't swap).
                    $html[$imageID] = $aug;
                }
            }
        }

        // FPM telemetry — record handler wall (including FPM queue wait). queue_wait isn't separable
        // here (no $hb_entry_t before WP's admin-ajax bootstrap); $hb_request_arrival_t is FPM accept,
        // so total wall is the right number to report.
        if (function_exists('wpc_v2_telemetry_record')) {
            $hb_ms = (int) round((microtime(true) - $hb_request_arrival_t) * 1000);
            $hb_active_count = is_array($_POST['active'] ?? null) ? count($_POST['active']) : 0;
            wpc_v2_telemetry_record('heartbeat', $hb_ms, [
                'active_count' => $hb_active_count,
                'image_count'  => count($html),
            ]);
        }

        // Heartbeat-triggered journal drain (secondary safety net). Primary trigger is the inline
        // shutdown_function drain in v2-callback.php; this picks up journal files left un-drained if
        // both the loopback and inline drain failed. ~10ms overhead when the journal dir is empty.
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
                    // Run drain BEFORE wp_send_json so the heartbeat response
                    // already reflects any newly-merged variants. GET_LOCK in
                    // drain_run prevents concurrent runs with the inline path.
                    wpc_v2_journal_drain_run();
                }
            }
        }

        if (empty($html)) {
            wp_send_json_error();
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Compute chip+savings+recent payload for one active image.
     * Used by the heartbeat handler's active-list path. Returns an array with
     * keys: status, chip, savings_pct, recent, warming. Returns [] if the
     * image isn't a valid attachment.
     *
     * SAFE TO CALL FREQUENTLY: ~5-15 ms cost per call (4 get_post_meta + 1
     * get_transient + foreach over ic_local_variants). Multi-image heartbeat
     * pays this cost once per active image per tick instead of one full
     * admin-ajax round-trip per image.
     */
    private function wpc_compute_heartbeat_payload($imageID)
    {
        if ($imageID <= 0) return [];
        // Defensive: bail on non-attachment IDs (avoids confusing render on
        // wrong post types if client active-list got polluted).
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

        // (Former count>=22 ML safety-net removed: a magic number that didn't generalize. status
        // ='compressed' is now set reliably by all canonical Phase A/B success paths, and ML renders
        // from that postmeta — no fallback needed.)

        // Announced-but-not-yet-persisted items — only emit when status=compressed
        // (eager_flip already happened) so chips don't fire on a still-Optimizing card.
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

        // Stable order: oldest → newest so the client animates landings in arrival order.
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

        // Always include rendered card HTML when status is compressed, even if no bytes have
        // persisted yet: eager_flip flips status BEFORE bytes land and ic_savings is seeded from
        // announce metadata, so compress_details renders a valid Compressed card with $variants empty.
        // Returning html ensures the card SWAPS to Compressed before chip data applies — otherwise the
        // chip + badges fire on the still-Optimizing card.
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
        // This poll fires every ~3s from the browser while a restore is live (v2 OR
        // legacy). Bump the liveness heartbeat here so an in-progress restore is never
        // mistaken for an orphan; if the tab closes it lapses and the flag self-heals.
        if ($bulkProcess && !$isDone && function_exists('wpc_bulk_heartbeat_touch')) {
            wpc_bulk_heartbeat_touch();
        }

        // Liveness watchdog: the drain worker stamps wpc_bulk_restore_last_tick before each image.
        // If the queue still has work but the stamp is >75s stale, the slice died/wedged — re-fire
        // the loopback. Idempotent (the worker's GET_LOCK rejects a double-fire if a slice is alive),
        // and the wedged image was popped before its restore began, so revival continues with the NEXT
        // image (skip semantics). 60s cooldown stamp prevents refiring on every 3s poll.
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

            // Mirror compress's grace-window pattern: the 'done' payload returns the SAME stat fields
            // as the working payload (bytes_restored / elapsed / avg) so the success screen has them.
            // Pre-fix it omitted these and the screen showed nothing past the image count.
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
            // Freeze elapsed at the completion moment. Using microtime(true) - started_ms kept
            // growing on every grace-window tick (success screen's "Total Time" climbed 17s → 18s …).
            // Stash the completion ms ONCE on the first done tick, reuse it on every poll. Cleared by
            // wps_ic_bulkRestoreCleanup.
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

            // Do NOT delete wps_ic_bulk_process here. The JS grace-poll continues for 60s; without
            // bulk_process the next heartbeat hits a different branch and zeros out the payload. The
            // bulk_done transient (60s TTL) keeps the 'done' branch live for the grace window;
            // bulk_process is cleaned up by the cleanup AJAX at end of grace.

            // Bulk restore complete. Phase A finished for all images; their thumb-regen markers are
            // queued in _wpc_pending_thumb_regen. Fire ONE loopback to kick off the cap=1 self-chaining
            // drain — each finishing regen worker picks up the next pending image (no cron, no overload).
            if (function_exists('wpc_chain_next_pending_regen')) {
                wpc_chain_next_pending_regen(0);
            }

            wp_send_json_success($output);
        }

        // Total Images in Restore Queue
        $imagesInRestoreQueue = !empty($bulkStatus['foundImageCount']) ? $bulkStatus['foundImageCount'] : 0;
        $imagesRestored = !empty($bulkStatus['restoredImageCount']) ? $bulkStatus['restoredImageCount'] : 0;


        // Not ready for output, nothing is done yet
        if (empty($parsedImages)) {
            wp_send_json_success(['status' => 'parsing', 'message' => 'We have found ' . $imagesInRestoreQueue . ($imagesInRestoreQueue == 1 ? ' image' : ' images') . ' to restore...']);
        }

        $progressBar = ($imagesInRestoreQueue > 0) ? round(($imagesRestored / $imagesInRestoreQueue) * 100) : 0;

        // Visual Patch so that user can see some progress
        if ($progressBar == 0) {
            $progressBar = 3;
        }

        // Bugfix, remove total index
        $onlyImages = $parsedImages;
        unset($onlyImages['total']);

        $lastID = null;
        if (!empty($onlyImages)) {
            $lastID = array_key_last($onlyImages);
        }

        // If no images processed yet, return parsing status
        if ($lastID === null) {
            wp_send_json_success(['status' => 'parsing', 'message' => 'Restoring images...', 'finished' => $imagesRestored, 'total' => $imagesInRestoreQueue, 'progress' => $progressBar]);
        }

        $lastProgress = isset($_POST['lastProgress']) ? $_POST['lastProgress'] : 0;

        // Build a 'current' object so the field-level UI (WPCRestore) can update the card without
        // replacing innerHTML (preserves CSS crossfade + slide animations between polls).
        $currentThumb = wp_get_attachment_url($lastID);
        $currentFile  = get_attached_file($lastID);
        $currentPath  = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($lastID) : $currentFile;
        $currentSize  = ($currentPath && file_exists($currentPath)) ? @filesize($currentPath) : 0;

        // Cumulative bytes restored: sum the on-disk size of every
        // already-restored image's original. Cached in the parsed_images
        // option so we don't stat the filesystem N times per poll.
        $bytes_restored = 0;
        foreach ($parsedImages as $pid => $meta) {
            if ($pid === 'total') continue;
            if (isset($meta['bytes'])) {
                $bytes_restored += (int) $meta['bytes'];
                continue;
            }
            // Stat-once: populate the cache so subsequent polls skip the filesystem.
            $p = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($pid) : get_attached_file($pid);
            $b = ($p && file_exists($p)) ? (int) @filesize($p) : 0;
            $parsedImages[$pid]['bytes'] = $b;
            $bytes_restored += $b;
        }
        if (count($parsedImages) > 1) {
            // Persist the bytes cache (skip if only 'total' key is present)
            update_option('wps_ic_parsed_images', $parsedImages, false);
        }

        // ETA from rolling average per-image wall time. parsed_images
        // entries include 'restored_at' timestamps set by the drain handler.
        $eta_seconds = null;
        $recent_stamps = [];
        foreach ($parsedImages as $pid => $meta) {
            if ($pid === 'total') continue;
            if (!empty($meta['restored_at'])) $recent_stamps[] = (int) $meta['restored_at'];
        }
        if (count($recent_stamps) >= 2) {
            sort($recent_stamps);
            $tail = array_slice($recent_stamps, -5); // rolling window
            $avg  = (end($tail) - reset($tail)) / max(1, count($tail) - 1);
            $remaining = max(0, $imagesInRestoreQueue - $imagesRestored);
            if ($avg > 0 && $remaining > 0) $eta_seconds = (int) round($avg * $remaining);
        }

        $output = [];
        $output['status']        = 'working';
        $output['parsedImages']  = $parsedImages;
        // Legacy HTML kept for backwards-compat with any consumer that still
        // reads response.data.html. New WPCRestore uses the JSON fields below.
        $output['html']          = $this->bulkRestoreHtml($lastID, $lastProgress);
        $output['finished']      = $imagesRestored;
        $output['total']         = $imagesInRestoreQueue;
        $output['progress']      = $progressBar;
        $output['parsedImage']   = isset($parsedImages[$lastID]) ? $parsedImages[$lastID] : [];

        // JSON fields for WPCRestore (field-level updates + animations)
        $output['driver']        = 'v2';
        $output['current']       = [
            'id'    => (int) $lastID,
            'name'  => $currentThumb ? basename($currentThumb) : ('Image #' . (int) $lastID),
            'size'  => $currentSize,
            'size_h'=> $currentSize > 0 ? wps_ic_format_bytes($currentSize, null, null, false) : '',
            'url'   => (string) $currentThumb,
        ];
        $output['eta_seconds']      = $eta_seconds;
        // Elapsed time since the bulk restore started.
        $started_ms = (int) get_option('wpc_bulk_restore_started_ms', 0);
        $output['elapsed_seconds'] = $started_ms > 0
            ? (int) round((microtime(true) * 1000 - $started_ms) / 1000)
            : 0;

        // Total bytes restored (cumulative). Showing disk reclaimed lets
        // the user see concrete progress, not just a count.
        $output['bytes_restored']   = (int) $bytes_restored;
        $output['bytes_restored_h'] = $bytes_restored > 0
            ? wps_ic_format_bytes($bytes_restored, null, null, false)
            : '';

        // Average wall-clock per image (rolling 5-window). Falls
        // back to elapsed/finished if not enough stamps yet. Drives the
        // "avg X.Xs/image" sub-line under ETA.
        $avg_seconds = null;
        if (count($recent_stamps) >= 2) {
            sort($recent_stamps);
            $tail = array_slice($recent_stamps, -5);
            $avg_seconds = round((end($tail) - reset($tail)) / max(1, count($tail) - 1), 1);
        } elseif ($imagesRestored > 0 && $output['elapsed_seconds'] > 0) {
            $avg_seconds = round($output['elapsed_seconds'] / $imagesRestored, 1);
        }
        $output['avg_seconds_per_image'] = $avg_seconds;

        // How long the CURRENT file has been processing (seconds since
        // the most recent parsed_images entry's restored_at, OR the avg
        // baseline if the current entry hasn't been timestamped yet).
        $file_started = null;
        if (!empty($recent_stamps)) {
            $file_started = max($recent_stamps);
        }
        $output['file_elapsed_seconds'] = $file_started ? max(0, time() - (int) $file_started) : 0;

        // Recent strip: last 8 restored images (newest-first).
        // Enriched payload (was id + name only) so the new
        // table-row feed can show thumb + restored bytes + relative time.
        // Source label is derived from per-image post_meta: wpc_backup_path
        // (new /wpc-backups/) → "Cloud", ic_backup_images (legacy) → "Local",
        // else "Auto". 8 rows (was 5) since table rows are denser than
        // chip bubbles — more on-screen at the same vertical footprint.
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

            // Backup source from wpc_backup_mode post_meta (the
            // authoritative flag set by save_local_backup at compress time).
            //   'cloud'              → service-side Bunny CDN backup, no local file
            //   'local'/'full'/etc.  → /wp-content/wpc-backups/<path> local copy
            //   'local-cloud'        → both (label as "Cloud" since cloud is the
            //                          stricter/more durable signal for the user)
            //   'off' or unset       → fall back to ic_backup_images (legacy local)
            //                          or wpc_backup_path presence (any local copy)
            $mode = (string) get_post_meta($rid, 'wpc_backup_mode', true);
            $source = 'auto';
            if ($mode === 'cloud' || $mode === 'local-cloud') {
                $source = 'cloud';
            } elseif ($mode && in_array($mode, ['local', 'full', 'originals'], true)) {
                $source = 'local';
            } elseif (get_post_meta($rid, 'wpc_backup_path', true)) {
                $source = 'local';   // path exists → local backup happened
            } elseif (get_post_meta($rid, 'ic_backup_images', true)) {
                $source = 'local';   // pre-v7 legacy local backup meta
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

        // Up-next queue preview. Read the restore queue transient
        // (set when bulk restore kicks off), filter out anything already in
        // parsed_images (= already done), return the next 4 names. Hard-
        // capped + lightweight: just IDs + names, no bytes/dimensions.
        $queue_t = get_transient('wps_ic_restore_queue');
        $queue   = (is_array($queue_t) && !empty($queue_t['queue'])) ? $queue_t['queue'] : [];
        $done_ids = array_flip(array_filter($recent_ids, function ($k) { return $k !== 'total'; }));
        // Also exclude the currently-processing ID + everything in parsedImages.
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
        // Restore card. Layout:
        //   ┌────────────────────────────────────────────────────────┐
        //   │ [thumb] [✓ Restored chip] [722 KB ghost chip]   10 / 12│
        //   │         filename.jpg                            83%    │
        //   │ ═══════════════════════════════ progress (gradient)    │
        //   └────────────────────────────────────────────────────────┘
        // Legacy `.bulk-restore-status-top-right>h3` class preserved so the
        // existing JS line that writes "X / Y" into it still works.

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

        // Thumbnail
        $output .=     '<div class="wpc-restore-thumb">';
        if ($thumbUrl) {
            $output .= '<div class="wpc-restore-thumb-img" style="background-image:url(' . esc_url($thumbUrl) . ');"></div>';
        } else {
            $output .= '<div class="wpc-restore-thumb-img wpc-restore-thumb-empty">';
            $output .=   '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
            $output .= '</div>';
        }
        $output .=     '</div>';

        // Meta (chips + filename)
        $output .=     '<div class="wpc-restore-meta">';
        $output .=       '<div class="wpc-chip-row">';
        $output .=         '<span class="wpc-chip wpc-chip-success">&#10003; ' . esc_html__('Restored', WPS_IC_TEXTDOMAIN) . '</span>';
        $output .=         '<span class="wpc-chip wpc-chip-ghost">' . esc_html($sizeDisplay) . '</span>';
        $output .=       '</div>';
        $output .=       '<h3 class="wpc-restore-filename" title="' . esc_attr($image_full_filename) . '">' . esc_html($image_full_filename) . '</h3>';
        $output .=     '</div>';

        // Counter + percentage badge (right column)
        $output .=     '<div class="wpc-restore-counter bulk-restore-status-top-right">';
        $output .=       '<h3 class="wpc-counter-main">' . $restoredCount . '<span class="wpc-counter-divider">/</span>' . $totalCount . '</h3>';
        $output .=       '<div class="wpc-counter-label">' . esc_html__('Images Restored', WPS_IC_TEXTDOMAIN) . '</div>';
        $output .=       '<span class="wpc-percent-badge">' . $pct . '% ' . esc_html__('complete', WPS_IC_TEXTDOMAIN) . '</span>';
        $output .=     '</div>';

        $output .=   '</div>'; // .wpc-restore-card-body

        // Full-width gradient progress bar at the bottom of the card
        $output .=   '<div class="wpc-restore-progress-track bulk-status-progress-bar">';
        $output .=     '<div class="wpc-restore-progress-fill progress-bar-inner" style="width:' . $pct . '%;"></div>';
        $output .=   '</div>';

        $output .= '</div>'; // .wpc-restore-card
        $output .= '</div>'; // .wps-ic-bulk-html-wrapper

        return $output;
    }

    public function wps_ic_bulkCompressHeartbeat()
    {
        // Branch on driver. v2 = new contract (rolling tally +
        // completion list + active titles). v1 = legacy fields (preserved
        // verbatim below) so older v1 customers aren't disrupted.
        $bulkRunning = get_option('wps_ic_bulk_process');

        // Bulk option deleted (Stop, completion, or never started) — return
        // a terminal payload the v2 JS poller will treat as "done" within
        // its 2-poll grace window. Avoids the legacy 'parsing' branch which
        // shows misleading "we have found 0 images" copy and burns DB reads.
        if (empty($bulkRunning)) {
            // Live final-stats reconstruction during the 60s
            // post-terminal grace window. Reads FRESH from session_ids /
            // ic_local_variants so late-arriving variants (bg_retry
            // pulling stragglers, encoder finishing last 1-2 AVIFs)
            // are reflected in the success-screen counters as they
            // land. The original version returned hard-coded zeros; a later
            // one read from completed_cache (a snapshot taken at terminal
            // time, never updated). This version walks the DB so counters keep climbing.
            $final_sess = get_transient('wpc_bulk_session_ids') ?: [];
            $f_orig = 0; $f_now = 0; $f_variants = 0;
            $f_count = is_array($final_sess) ? count($final_sess) : 0;
            // Per-image avg accumulator: each image contributes its own pct.
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

        // Route 'sequential' driver to the same v2 reply shape.
        // The JS tally + feed + active-titles all read the v2 fields
        // (processed, total, variants_total, new_variants, active, etc.).
        // Without this branch sequential bulks fell through to the legacy
        // V1 reply which only emits status='parsing' with a stale-state
        // message, so JS saw total=0 forever even though the queue was
        // populated and the loop was dispatching images.
        if ($driver === 'v2' || $driver === 'sequential') {
            $this->bulkCompressHeartbeat_v2($bulkRunning);
            return;
        }

        $bulkStatus = get_option('wps_ic_BulkStatus');
        $parsedImages = get_option('wps_ic_parsed_images');

        // Total Images Found
        $totalImagesFound = $bulkStatus['foundImageCount'];
        $totalThumbsFound = $bulkStatus['foundThumbCount'];
        $compressedImages = $bulkStatus['compressedImageCount'];

        // Bugfix, remove total index
        $onlyImages = $parsedImages;
        unset($onlyImages['total']); // remove total

        // Nothing done yet
        if (empty($onlyImages)) {
            wp_send_json_success(['status' => 'parsing', 'message' => 'We have found ' . $totalImagesFound . ($totalImagesFound == 1 ? ' image' : ' images') . ' to optimize...']);
        }

        if (!empty($onlyImages)) {
            $lastID = array_key_last($onlyImages);
        }

        // Stats for the last optimized image
        $stats = get_post_meta($lastID, 'ic_stats', true);
        $original_filesize = isset($stats['original']['original']['size']) ? $stats['original']['original']['size'] : 0;
        $compressed_filesize = isset($stats['original']['compressed']['size']) ? $stats['original']['compressed']['size'] : 0;

        // Check if negative savings
        $savedKB = wps_ic_format_bytes($original_filesize - $compressed_filesize) . ' Saved';
        if ($original_filesize <= $compressed_filesize) {
            $savedKB = 'No savings';
        }

        // HTML for the Last Optimized image
        $status = '<ul class="wps-icon-list">';
        $status .= '<li><i class="wps-icon saved"></i>' . $savedKB . '</li>';
        $status .= '<li><i class="wps-icon quality"></i> ' . ucfirst(self::$settings['optimization']) . ' Mode</li>';
        if (self::$settings['generate_webp'] == '1') {
            $status .= '<li><i class="wps-icon webp"></i> WebP Generated</li>';
        }
        $status .= '</ul>';

        // ProgressBar for overall Optimization (compressed images / total images to compress)
        $progressBar = ($totalImagesFound > 0) ? round(($compressedImages / $totalImagesFound) * 100) : 0;

        // Full Image Name
        $full = wp_get_original_image_url($lastID);
        $imageFileName = $full ? basename($full) : ('Image #' . $lastID);

        // Savings Calc
        $originalSize = isset($parsedImages['total']['original']) ? $parsedImages['total']['original'] : 0;
        $compressedSize = isset($parsedImages['total']['compressed']) ? $parsedImages['total']['compressed'] : 0;
        $imagesAndThumbs = (!empty($bulkStatus['compressedImageCount']) ? $bulkStatus['compressedImageCount'] : 0) + (!empty($bulkStatus['compressedThumbs']) ? $bulkStatus['compressedThumbs'] : 0);

        // Avg Savings
        $avgReduction = ($originalSize > 0 && $imagesAndThumbs > 0) ? (1 - ($compressedSize / $originalSize)) * 100 : 0;
        $avgReduction = number_format($avgReduction, 1);
        $avgReductionHTML = '<h3>' . $avgReduction . '%</h3><h5>Average Savings</h5>';

        // Total Savings
        $bulkSavings = wps_ic_format_bytes($originalSize - $compressedSize, null, null, false);
        $bulkSavingsHTML = '<h3>' . $bulkSavings . '</h3><h5>Total Savings</h5>';

        // Compressed Images
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

    /**
     * Heartbeat for server-side v2 drain chain. Walks the session-ids
     * transient, classifies each image as ACTIVE (Phase B still draining) or
     * COMPLETED (all Phase B variants landed), builds the rolling tally +
     * completion list payload the JS UI consumes.
     *
     * Field name verified against v2-callback.php:272: ic_local_variants entries
     * are written with 'size' (encoded bytes) + 'originalSize' (echoed from
     * envelope). Pending transient shape verified against v2-client.php:657:
     * payload is ['jobId', 'pending' => [variant_key => ...], 'recorded_at'];
     * when fully drained, the transient is DELETED entirely (v2-callback.php:485).
     */
    protected function bulkCompressHeartbeat_v2($bulkRunning)
    {
        // Audit hardening (P3-52): heartbeat memory + wall-time
        // telemetry. The bulk heartbeat is O(N×M) (session_ids × variants).
        // On 100-image bulks at 1.5s cadence, this scans ~2400 entries per
        // tick. Without telemetry, we can't see when a real customer's site
        // is approaching the 64MB shared-host limit. Sampled 1/10 ticks to
        // keep log noise bounded (~1 line per 15s on a typical bulk).
        // Revert: remove $_wpc_hb_start_t + the bottom telemetry log line.
        $_wpc_hb_start_t = microtime(true);
        $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
        $queue_data  = get_transient('wps_ic_compress_queue') ?: ['queue' => [], 'total_images' => 0];
        $total       = (int) ($queue_data['total_images'] ?? (count($session_ids) + count($queue_data['queue'])));

        $cumulative_orig = 0;
        $cumulative_now  = 0;
        $variants_total  = 0;   // total variant rows across all session imageIDs
        // Per-image avg accumulator: each image contributes its own pct, then
        // we divide by the number of images that have at least 1 byte of orig.
        // Exposed as savings_pct_avg alongside the byte-weighted savings_pct so
        // the success screen can display both for comparison.
        $img_pct_sum = 0.0;
        $img_pct_n   = 0;
        $active    = [];
        $completed = [];
        // Count of completed[] entries where accounted < expected.
        // These are early-advanced images with Phase B variants still
        // arriving. JS uses processed+pending_drain to decide terminal.
        $pending_drain_in_completed = 0;

        // Perf: cache completed-image rows. Once Phase B fully drains
        // for an image, its ic_local_variants is stable; re-reading meta for
        // every completed image every 1 s heartbeat is what was generating
        // sustained DB pressure under load (a 50-image bulk with 47 done →
        // 47 × 2 DB ops per poll just to recompute the same numbers).
        // Cleared on start_bulk_compress + wps_ic_StopBulk. 2 h TTL.
        $cache_key = 'wpc_bulk_completed_cache';
        $completed_cache = get_transient($cache_key);
        if (!is_array($completed_cache)) $completed_cache = [];
        $cache_dirty = false;

        foreach ($session_ids as $id) {
            $id = (int) $id;

            // Hot path: already cached as completed → zero DB reads for this id.
            // Evict cache when accounted < expected_variants so
            // stragglers from the 30s bg retry update the cumulative stats.
            // Without this, an image early-advanced at 22/24 stays frozen
            // at 22 in the cumulative tally even after the remaining 2
            // variants land via bg retry. Once accounted catches up to
            // expected, the cache freezes (no more re-reads).
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
                // Partial completion — evict and re-read so stats stay honest
                // as bg retry fills the missing variants.
                unset($completed_cache[$id]);
                $cache_dirty = true;
            }
            // since_ms cursor will skip pre-cursor variants below; no need to
            // bypass cache here.

            // Cache flush before reads. bulkCompressHeartbeat_v2
            // runs in a long-lived AJAX request; other workers (drain,
            // journal merge) update ic_local_variants + ic_compressing
            // concurrently. Without flush, this worker sees stale snapshot
            // and reports image as still active even after ML shows it
            // compressed. Matches the same pattern used by
            // wpc_compute_heartbeat_payload (line ~1972).
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

            // Completion detection tightened. The old check used only
            // "pending transient empty + variants > 0", which trips
            // prematurely if pending transient hasn't been written yet (Phase A
            // response still in flight) OR briefly empties during a merge
            // race. The completion cache then FROZE incomplete data.
            //
            // Now: require ic_compressing.status === 'compressed' AND pending
            // drained. Status flips ONLY via Phase A promote_to_compressed,
            // drain_complete in journal_merge, or eager-flip.
            //
            // Defensive fallback: if status='compressed' AND
            // variant_count >= 24 (full set landed), accept completion even if
            // pending has stale entries. Without this, a single stale pending
            // key (e.g., from a no_improvement variant that didn't decrement)
            // could trap bulk on this image until 600s pending TTL expires.
            // Variants are the ground truth — pending is a tracking artifact.
            $ic_st = get_post_meta($id, 'ic_compressing', true);
            $img_status = (is_array($ic_st) && !empty($ic_st['status']))
                ? (string) $ic_st['status']
                : 'optimizing';
            $expected = is_array($ic_st) ? (int) ($ic_st['expected_variants'] ?? 0) : 0;

            // Local helpers retained for downstream logging blocks.
            $pending = get_transient('wpc_v2_pending_' . $id);
            $still_draining = !empty($pending) && !empty($pending['pending']);
            $phase_a_done = (bool) get_transient('wpc_v2_phase_a_done_' . $id);

            // Expected-count gate. Ground truth =
            // ic_compressing.expected_variants (saved at dispatch from
            // count(WP sub-sizes)+1 (original) × format count, refined by
            // prep_v2_optimize with the actual variants list). is_completed
            // = full set accounted for + status flipped.
            //
            // Why this design:
            //   - Works for ANY sub-size count (5, 8, 12, 30) without magic
            //     thresholds.
            //   - Postmeta-based (permanent), not transient — survives
            //     hours-long bulk runs, FPM restarts, cache flushes.
            //   - Independent of phase_a_done (kept as JS chip-timing
            //     signal but no longer gates completion — async dispatch
            //     can succeed OR fail, doesn't matter; variants on disk
            //     are truth).
            //   - Independent of pending transient (skipped variants and
            //     stale pending entries don't block).
            //
            // Accounted = entries with actual bytes OR explicit no-
            // improvement marker. Both count as "this slot is decided."
            $accounted = 0;
            if (is_array($variants)) {
                foreach ($variants as $v) {
                    if (!is_array($v)) continue;
                    if (!empty($v['size']) || !empty($v['bg_no_improvement'])) {
                        $accounted++;
                    }
                }
            }

            // Early-advance threshold. Real-world observation:
            // 1-2 variants per image often arrive 10-30s after the bulk of
            // the set lands (encoder tail latency on the AVIF cascade).
            // Holding the entire bulk back for those stragglers made the UI
            // feel laggy. Advance the counter when ≥90% of expected variants
            // are accounted for, and fire a background retry loop to fill
            // the remaining 1-2 from the orchestrator pull manifest. Small
            // images (expected ≤10) still require 100% — they finish fast
            // anyway and a percent-based threshold rounds too aggressively.
            $threshold_pct = (float) apply_filters('wpc_v2_early_advance_pct', 0.90);
            $early_threshold = $expected > 10
                ? max(1, (int) ceil($expected * $threshold_pct))
                : $expected;

            $is_completed_full  = $expected > 0 && $accounted >= $expected;
            $is_completed_early = $expected > 0 && $accounted >= $early_threshold;
            $is_completed = ($img_status === 'compressed') && $is_completed_early;

            // Legacy fallback: images dispatched before expected_variants
            // existed don't carry it in meta. Use the prior pending-based gate so
            // already-in-flight bulks don't get stuck on upgrade.
            if (!$is_completed && $expected === 0 && !empty($variants)) {
                $is_completed = $phase_a_done
                    && !$still_draining
                    && ($img_status === 'compressed');
                $is_completed_full = $is_completed;
            }

            // Fire background retry for the missing variants when
            // we advance an image with accounted < expected. One-shot per
            // image (guarded by transient) so we don't hammer the orch on
            // every poll. The retry endpoint runs a 30s loop pulling from
            // the manifest; stragglers land in ic_local_variants and the
            // completed_cache eviction logic below picks them up.
            if ($is_completed && !$is_completed_full && function_exists('wpc_v2_fire_image_bg_retry')) {
                $retry_fired_key = 'wpc_v2_bg_retry_fired_' . $id;
                if (!get_transient($retry_fired_key)) {
                    set_transient($retry_fired_key, time(), 60);
                    wpc_v2_fire_image_bg_retry($id);
                }
            }

            // Use medium_large so hero card renders crisp on retina;
            // small UI rows downscale with no quality loss.
            $thumb = (string) wp_get_attachment_image_url($id, 'medium_large');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($id, 'medium');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($id, 'thumbnail');
            if (!$thumb) $thumb = (string) wp_get_attachment_image_url($id, 'full');
            // Use filename (with -1/-2/-3 dedup suffix) so
            // bulk UI can distinguish multiple uploads of the same image.
            // Strip the -scaled suffix WP auto-adds AND the file
            // extension for a cleaner display. Result e.g. "name-4" not
            // "name-4-scaled.jpg". Still distinguishable by the -N dedup.
            $file_path = get_attached_file($id);
            $file_name = $file_path ? basename($file_path) : '';
            if ($file_name !== '') {
                $file_name = preg_replace('/\.[a-zA-Z0-9]+$/', '', $file_name);
                $file_name = preg_replace('/-scaled$/', '', $file_name);
            }
            $title = $file_name !== '' ? $file_name : get_the_title($id);

            if ($is_completed) {
                // Compute per-format chip counts for completed
                // entries too. Previously only active[] entries carried chip
                // data, so when an image early-advanced at 90% (say 22/24)
                // its chips went blank. Use ML's exact computation as the
                // single source of truth across both buckets, with no risk of divergence.
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
                // If early-advanced (accounted < expected), this
                // image still has Phase B variants in flight. Count it toward
                // pending_drain so JS terminal doesn't fire while encoders
                // are still producing stragglers for this image.
                if ($expected > 0 && $accounted < $expected) {
                    $pending_drain_in_completed++;
                }
                // Only cache when image has a near-complete variant
                // set (>=20). Without this gate, an image force-flipped early
                // (e.g., time-based unstick with only 4 variants on disk) gets
                // its low count permanently cached. Subsequent stragglers land
                // in ic_local_variants but cumulative stats keep reading the
                // stale cached 4 → bulk shows "13 Total Images" when reality
                // is 60+. By re-reading until count>=20, cumulative stats stay
                // honest. Slight perf cost (extra DB reads per poll for
                // partial completions) is acceptable for correctness.
                // Always cache, but track expected so we can
                // detect partial completions (early-advanced at 22/24) and
                // re-read on next poll until stragglers fill the gap. Cache
                // freezes only when accounted >= expected (the eviction
                // check above).
                // The entry now carries chip counts (jpeg/webp/avif)
                // so the cached path also delivers live per-format data to
                // JS as stragglers land between polls.
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
                // Diagnostic logging gated on
                //   add_filter('wpc_bulk_debug_chip', '__return_true');
                // or wp option update wpc_bulk_debug_chip 1. Logs each
                // active image's read state so we can verify whether
                // the chip-counter discrepancy is server-side or JS-side.
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

                // Fire bg retry for ANY active image with
                // partial variants, not just early-advanced ones. We saw
                // bulk stuck at "15 · 7J 8W 0A": AVIFs landing
                // slowly on the encoder side, no JS stall fired yet, no early
                // advance threshold reached. Previously no retry path was
                // active for this image, so bulk drain blocked on it until
                // wait_max timeout. With retry firing now, the long-poll
                // grabs straggling AVIFs as soon as orch produces them, so
                // the image reaches threshold and advances naturally.
                // 60s one-shot lock prevents re-firing every poll.
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
            // Refresh TTL on every write. Bulks that run longer
            // than 2 h (large catalogs on slow hosts) would lose their
            // completed_cache mid-run, resetting cumulative stats to zero
            // until each cached image was re-read. Refresh-on-write keeps
            // the cache alive as long as the bulk is actively progressing.
            set_transient($cache_key, $completed_cache, 6 * HOUR_IN_SECONDS);
        }

        // Per-variant landing stream. JS sends a since_ms cursor; we
        // return variants whose bg_upgraded_ms is newer. Powers the live
        // "variants delivered" badge ticker in the bulk UI. Cap to 30 newest
        // to keep response light. Only scan ACTIVE images (completed-cache
        // images have all their variants already landed pre-cursor).
        $since_ms = isset($_POST['since_ms']) ? (int) $_POST['since_ms'] : 0;
        $new_variants = [];
        // Initial poll (since_ms=0): also walk the completed cache to seed
        // the variant stream UI. Subsequent polls (since_ms>0): cursor
        // implicitly excludes cached images (their variants land < since_ms).
        $is_initial = ($since_ms === 0);
        foreach ($session_ids as $id) {
            $id = (int) $id;
            $cached_variants = null;
            // Three cases:
            //   (a) image not in completed_cache → always read fresh from DB
            //   (b) image in cache AND fully complete (accounted >= expected)
            //       → use cached snapshot on initial, skip on subsequent
            //   (c) image in cache but PARTIAL (early-advanced at 90%,
            //       stragglers still landing) → read fresh every poll so
            //       late AVIFs that land AFTER the image moved to
            //       completed bucket still appear in the feed stream.
            //
            // The earlier version did (a) and (b) only, so partial completions
            // silently dropped any late variants from the "Recently Optimized" table
            // forever (they landed for ML but not for bulk).
            // Always fresh-read for the feed stream walk. The earlier version,
            // for fully-cached images, used the cached variant_data snapshot;
            // any variants landing AFTER caching (rare: encoder produces an
            // extra variant beyond expected, OR cached early at threshold)
            // were silently dropped from the feed table. Fresh read costs
            // one get_post_meta per image per poll (~1ms) — negligible vs
            // the correctness gain.
            wp_cache_delete($id, 'post_meta');
            $variants = get_post_meta($id, 'ic_local_variants', true);
            if (!is_array($variants)) continue;
            // Lookup parent metadata once per image (not per variant).
            // Prefer medium_large so the bulk hero card (160×110 @ 2× DPI = 320×220)
            // renders crisp; small thumbnail (150×150) looked pixelated on retina.
            // Feed table rows downscale to 36×36 with no visible quality loss.
            $p_thumb = (string) wp_get_attachment_image_url($id, 'medium_large');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'medium');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'thumbnail');
            if (!$p_thumb) $p_thumb = (string) wp_get_attachment_image_url($id, 'full');
            // Use filename for distinguishable titles (matches
            // bulk active/completed entries above so the variant-stream rows
            // align with the hero card name).
            // Strip -scaled + extension so variant-stream titles
            // (which feed the hero card filename) match the cleaner format
            // used by active/completed entries. This spot was missed when the
            // active/completed entries first got that treatment.
            $p_file_path = get_attached_file($id);
            $p_file_name = $p_file_path ? basename($p_file_path) : '';
            if ($p_file_name !== '') {
                $p_file_name = preg_replace('/\.[a-zA-Z0-9]+$/', '', $p_file_name);
                $p_file_name = preg_replace('/-scaled$/', '', $p_file_name);
            }
            $p_title = $p_file_name !== '' ? $p_file_name : get_the_title($id);
            foreach ($variants as $key => $v) {
                if (!is_array($v)) continue;
                // Match ML reader's fallback (ajax.class.php:1998-2002):
                // if bg_upgraded_ms is missing, derive from bg_upgraded (seconds)
                // × 1000. Defends against any legacy/v1 path that wrote only the
                // seconds field — without this, those entries get ms=0 and are
                // perma-dropped once since_ms > 0.
                if (!empty($v['bg_upgraded_ms'])) {
                    $ms = (int) $v['bg_upgraded_ms'];
                } elseif (!empty($v['bg_upgraded'])) {
                    $ms = (int) $v['bg_upgraded'] * 1000;
                } else {
                    $ms = 0;
                }
                if ($ms <= $since_ms) continue;
                // Canonical-key-aware parse. JPEG sub-size variants now store
                // under canonical keys (jpeg = no
                // suffix, e.g. "medium" instead of "medium-jpeg"). The old
                // "skip if no dash" logic dropped EVERY canonical JPEG, so the bulk
                // chip showed "0J 6W 0A" because all JPEGs were filtered out
                // at this row. Now a dash-less key means canonical JPEG.
                $last_dash = strrpos($key, '-');
                if ($last_dash === false) {
                    // Canonical key — bare size label means JPEG (or jpg).
                    $size_label = $key;
                    $format     = 'jpeg';
                } else {
                    $size_label = substr($key, 0, $last_dash);
                    $format     = substr($key, $last_dash + 1);
                }
                $format = strtolower($format);
                if ($format === 'jpg') $format = 'jpeg';
                // Only accept known formats. Anything else is malformed and
                // would render an empty pill in the bulk UI.
                if (!in_array($format, ['jpeg', 'webp', 'avif'], true)) continue;
                // Zero bytes = not a real landed variant; skip.
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
        // Lite announce-stream merge. /wpc/v2/bg_swap_announce writes
        // per-image transients (wpc_v2_announced_$id) with display-state-only
        // entries (no bytes). We layer them in here as pending:true variants so
        // the bulk UI feed lights up within ~250 ms of variant encode-complete
        // instead of waiting for the bytes batch (~5-10 s). When the batch lands
        // and the variant appears in ic_local_variants, the same variant_key
        // gets skipped here on the next tick, and JS updates the row in-place
        // (removes pending class, updates savings).
        //
        // Rules:
        //   • Skip cached images (their variants already persisted, no pending)
        //   • Skip variant_keys already in the persisted set for this image
        //     (the bytes batch beat the announce, so persisted wins)
        //   • Skip expired entries (per-entry 30 s TTL, a SPEC §6 refinement)
        //   • Cursor still applies (announced_ms > since_ms)
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
            // Per-image parent metadata lookup (same pattern as persisted loop).
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
                    // saved on pending entries is an estimate from encoder-reported
                    // kb + originalSize. Matches the batch endpoint's math, so when
                    // the persisted version replaces this row the number won't jump.
                    'saved'         => (int) ($a['originalSize'] ?? 0) - (int) ($a['bytes_est'] ?? 0),
                    'pct'           => (int) ($a['savings'] ?? 0),
                    'ms'            => $ann_ms,
                    'pending'       => true,
                    'noImprovement' => !empty($a['noImprovement']),
                ];
            }
        }

        // Sort newest-first, cap to 200. 30 was too aggressive for re-
        // bulks where all 120 variants of a 5-image session can have valid
        // ms timestamps from the previous run, and the initial poll's
        // since_ms=0 was emitting only the 30 newest, leaving 90 variants
        // missing from the "Recently Optimized" table forever. 200 lets
        // a full 8-image bulk's variant set through in one poll without
        // bloating the payload (~3-4 KB per variant).
        usort($new_variants, function ($a, $b) { return $b['ms'] - $a['ms']; });
        $new_variants = array_slice($new_variants, 0, 200);

        // Newest-first for completion list (last appended to session = newest).
        $completed = array_reverse($completed);
        $bytes_saved = max(0, $cumulative_orig - $cumulative_now);
        $savings_pct = $cumulative_orig > 0
            ? round(100 * $bytes_saved / $cumulative_orig, 1)
            : 0;
        // Per-image average: mean of each image's individual pct. Differs
        // from savings_pct (byte-weighted) when images have very different
        // sizes: a 1 MB image at 80% saved plus a 100 KB image at 10% saved
        // gives byte-weighted ~73.6%, per-image avg = 45%.
        $savings_pct_avg = $img_pct_n > 0 ? round($img_pct_sum / $img_pct_n, 1) : 0;

        // Terminal detection: queue drained server-side (option deleted) AND
        // no pending Phase B drains. JS uses this + a 2-poll grace window to
        // fire the final reveal.
        // Also declare done when every session image is in the
        // completed bucket (count(completed) >= total) regardless of drain
        // chain state. The drain chain only sets bulk_done after its next
        // self-loopback iteration sees an empty queue; on a sequential bulk
        // with the wait_gate just-released for the last image, this can lag
        // 5-15s. Heartbeat had count(completed)=5/5 but status still
        // 'compressing' because bulk_process option hadn't been deleted yet.
        // is_completed already gates on accounted >= early_threshold so
        // "all images in completed" is a strong terminal signal.
        $queue_empty = empty($queue_data['queue']);
        // Terminal fires when 5/5 reaches completed bucket (early-
        // advance threshold), which keeps the UX fast. JS then runs a 60s grace-poll
        // window: stats keep climbing as stragglers land in DB and the
        // success-screen counters update in real time. This is better than
        // waiting for true 120/120 server-side because (a) UX is snappy, and
        // (b) if a variant is truly stuck, the grace window has a hard
        // ceiling so the success screen never hangs forever.
        $all_in_completed = ($total > 0) && (count($completed) >= $total) && empty($active);
        $bulk_done   = ($queue_empty && empty($bulkRunning)) || $all_in_completed;
        if ($all_in_completed) {
            // Side-effect: persist the canonical bulk_done transient so the
            // legacy poller (wps_ic_bulkCompressHeartbeat path) and any other
            // consumer sees the same terminal state. Idempotent.
            set_transient('wps_ic_bulk_done', true, 60);
            // Also synthesize queue_empty=true in the heartbeat
            // response. JS terminal check at media-library-bulk.js:710 is
            // `d.queue_empty && d.pending_drain === 0` — uses queue_empty
            // (this response field), NOT the bulk_done transient. Without
            // this, the drain chain's wait_max=25s per image gates the
            // queue-empty transition for the LAST image, so completion
            // screen lagged 5-25s after 5/5 even when every image was
            // functionally done. Setting queue_empty here makes terminal
            // fire on the next 2 polls (~500ms total).
            $queue_empty = true;
            // Mirror Stop's per-image cleanup. When the success
            // screen fires, ensure EVERY session image is fully flipped to
            // compressed state so ML rows (even stale tabs) reflect done.
            // Previously completion fired but some images still had
            // `wps_ic_compress_` transient or `ic_status` not set, so ML
            // initial render showed "Optimizing" until user reloaded.
            // One-shot guard prevents re-running on subsequent polls.
            if (!get_transient('wpc_bulk_completion_cleanup_done')) {
                set_transient('wpc_bulk_completion_cleanup_done', time(), 300);
                // Clear the bulk-running state options the
                // dashboard reads to decide whether to show "Pause Local
                // Optimization" (active bulk) vs "Start Optimization"
                // (idle). Previously heartbeat-driven completion cleared
                // per-image state but left wps_ic_bulk_process /
                // wps_ic_bulk_running set, so the dashboard's optimizer
                // pill stayed in the "Pause" state forever after bulk
                // finished. drain chain's queue-empty branch normally
                // clears these (line 3918+), but with the synthesized
                // queue_empty path the drain chain might still be
                // mid-wait when we declare done.
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
                    // Always set ic_status (canonical "ic_status='compressed'"
                    // is what compress_details + bulk progress / archive
                    // pages read) AND the phase_a_done JS-signal transient.
                    if (get_post_meta($cid, 'ic_status', true) !== 'compressed') {
                        update_post_meta($cid, 'ic_status', 'compressed');
                        // Refresh the path-A optimized-ids cache (see wp-compress-core.php).
                        if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
                    }
                    set_transient('wpc_v2_phase_a_done_' . $cid, time(), 3600);
                    // Clear "still in flight" markers so ML's compress_details
                    // doesn't render the Optimizing card on next poll.
                    delete_transient('wps_ic_compress_' . $cid);
                    delete_transient('wpc_v2_pending_' . $cid);
                    delete_transient('wpc_v2_warming_' . $cid);
                    wp_cache_delete($cid, 'post_meta');
                }
                error_log(sprintf('[WPC BulkHB] all_in_completed cleanup fired for %d session images', count($session_ids)));
            }
        }

        // Up-next preview. Next 3 queued image IDs + their thumbs
        // so the JS can render a cascading-opacity "queue preview" when
        // active is empty during the 8s drain gap. Beats "Loading next
        // batch…" text — user actually sees what's coming.
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

        // Log telemetry BEFORE wp_send_json_success (which calls
        // wp_die and halts the script). Sampled 1/10 ticks to bound log size.
        $this->wpc_log_bulk_heartbeat_telemetry(
            $_wpc_hb_start_t, count($session_ids), $variants_total,
            count($active), count($completed), count($new_variants)
        );

        wp_send_json_success([
            // v2 contract
            'driver'          => 'v2',
            'total'           => $total,
            'processed'       => count($completed),
            // pending_drain now includes BOTH active images AND
            // completed images whose Phase B hasn't fully drained (early-
            // advanced at 90% with stragglers still in flight). It used to be
            // count($active) only, so the JS terminal could fire while the drain
            // chain was still pulling AVIFs for an early-advanced
            // image: the completion screen appeared with variants still
            // landing in the background, never reflected in the UI again.
            'pending_drain'   => count($active) + $pending_drain_in_completed,
            'cumulative_orig' => $cumulative_orig,
            'cumulative_now'  => $cumulative_now,
            'bytes_saved'     => $bytes_saved,
            'savings_pct'     => $savings_pct,
            'savings_pct_avg' => $savings_pct_avg,
            'variants_total'  => $variants_total,
            // This was array_slice(-3). For a sequential bulk with
            // session=[18,17,16,15,12] processing in array order, $active
            // accumulates [entry18, entry17, ...]. The CURRENTLY-PROCESSING
            // image (18) is at the START, and slice(-3) drops it. The JS hero then
            // can't see it, so the chip stays stuck at 0 for whichever non-processing
            // image happens to be in the last 3. Now send all active
            // entries; for typical 5-50 image bulks the payload stays
            // well under 10 KB. JS picks the entry with most variants =
            // the actually-processing one.
            'active'          => $active,
            'completed'       => $completed,
            'new_variants'    => $new_variants,
            'queue_empty'     => $queue_empty,
            // Used by Radar mode: full queue + active + completed
            // IDs so JS can render a grid of tile-per-image and light them
            // up as their state changes.
            'queue_ids'       => array_map('intval', $queue_data['queue'] ?? []),
            'active_ids'      => array_map(function($a){ return (int) $a['id']; }, $active),
            'completed_ids'   => array_map(function($c){ return (int) $c['id']; }, $completed),
            'up_next'         => $up_next,

            // Legacy fields kept during transition so the existing
            // bulkCompressV2Poll callsites that haven't been updated yet still
            // render something useful instead of blanking out.
            'progress'                 => $total > 0 ? round(100 * count($completed) / $total) : 0,
            'progressCompressedImages' => '<h3>' . count($completed) . '/' . $total . '</h3><h5>Original Images</h5>',
            'progressTotalSavings'     => '<h3>' . wps_ic_format_bytes($bytes_saved, null, null, false) . '</h3><h5>Total Savings</h5>',
            'progressAvgReduction'     => '<h3>' . $savings_pct . '%</h3><h5>Average Savings</h5>',
            'progressCompressedThumbs' => '',
            'status'                   => ($bulk_done && empty($active)) ? 'done' : 'compressing',
        ]);
        // Telemetry log (sampled). Unreachable because of the
        // wp_send_json_success above, left intentionally for documentation:
        // the actual sampling happens INSIDE the success payload assembly
        // path via a register_shutdown_function approach. See below.
    }

    /**
     * Audit hardening (P3-52): heartbeat telemetry helper.
     * Called from bulkCompressHeartbeat_v2 just before wp_send_json_success
     * via the shutdown-function pattern (since wp_send_json halts execution).
     * Sampled 1/10 ticks via wpc_v2_telemetry_counter site-option counter.
     * Logs peak memory, wall time, session size, variant count so 10K-site
     * shared-host operators can detect heartbeat regressions early.
     *
     * Revert: delete this method + the register_shutdown_function call
     * added in bulkCompressHeartbeat_v2.
     */
    private function wpc_log_bulk_heartbeat_telemetry($start_t, $session_count, $variants_total, $active_count, $completed_count, $new_variants_count)
    {
        // Sample 1-in-10 ticks. Counter wraps at PHP_INT_MAX (no overflow).
        $counter = (int) get_site_option('wpc_v2_telemetry_counter', 0);
        update_site_option('wpc_v2_telemetry_counter', $counter + 1);
        if ($counter % 10 !== 0) return;

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

        // Use the same savings data as single image (unscaled baseline → best variant)
        $ic_savings = get_post_meta($imageID, 'ic_savings', true);
        $ic_baseline = get_post_meta($imageID, 'ic_savings_baseline', true);
        $ic_saved_bytes = get_post_meta($imageID, 'ic_savings_bytes', true);

        // Before = unscaled original, After = baseline minus saved bytes
        if ($ic_baseline > 0) {
            $original_filesize = wps_ic_format_bytes($ic_baseline, null, null, false);
            $after_size = $ic_baseline - $ic_saved_bytes;
            $compressed_filesize = wps_ic_format_bytes($after_size, null, null, false);
            $savings = floatval($ic_savings);
        } else {
            // Fallback to ic_stats if ic_savings not yet available
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

        // Original image URL for the before preview
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

        // Target: <300 ms total. The previous version made a blocking
        // wp_remote_get to apiv3.wpcompress.com mid-handler, which could push
        // total response time into seconds under remote-API latency. We:
        //   1. Set the stop-signal transient FIRST so the drain chain bails
        //      within one iter (≤8 s wall budget).
        //   2. Wipe ALL local state needed for the page render to show the
        //      start screen on reload (options + transients).
        //   3. Release the chain lock.
        //   4. Send JSON success — JS reloads immediately.
        //   5. Fire the remote `stop` notify async (non-blocking POST, ignored
        //      response) so the API gets the signal without holding the user.
        @ignore_user_abort(true);

        set_transient('wpc_bulk_stop_signal', time(), 60);

        global $wpdb;

        $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
        // Block late Phase B callbacks (10 min TTL matches service-side
        // asyncPending GC window).
        foreach ($session_ids as $sid) {
            set_transient('wpc_v2_callbacks_blocked_' . (int) $sid, time(), 600);
            delete_transient('wpc_v2_pending_' . (int) $sid);
        }

        // Wipe local state FIRST so the next page render sees a clean slate.
        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_BulkStatus');
        delete_option('wps_ic_bulk_process');
        set_transient('wps_ic_bulk_done', true, 60);

        delete_transient('wps_ic_compress_queue');
        delete_transient('wps_ic_restore_queue');
        delete_transient('wpc_bulk_session_ids');
        delete_transient('wps_ic_bulk_running');
        delete_transient('wpc_bulk_completed_cache');
        delete_transient('wpc_bulk_library_counts');

        // Legacy `wps_ic_compress_*` direct options.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wps_ic_compress_') . '%'));

        // Release drain chain locks.
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'wpc_bulk_v2_chain'));
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'wpc_bulk_v2_restore_chain'));

        // Hard-stop the v2 lazy pipeline too. The drain/tick now check
        // wpc_bulk_stop_signal (set above) and stand down, but also kill the deadline
        // + running-lock so an in-flight drain worker exits on its next iteration and
        // no new worker re-fires. Without this the lazy drain kept ingesting +
        // self-chaining after Stop (Stop only halted the frontend + ajax bulk loop).
        delete_option('wpc_v2_drain_alive_until_ms');
        delete_transient('wpc_v2_drain_running');

        // Bounded settle for in-flight writes. AVIF Phase B callbacks
        // are slow (5-30s wall) and the callbacks_blocked transient set above
        // only blocks NEW callbacks; anything mid-flight inside an FPM worker
        // continues to its update_post_meta. The previous 220 ms left a wide
        // window where the splash render reported counts that were stale by
        // the time the user blinked. We poll a per-write timestamp option
        // (updated by the same meta hook that busts wpc_bulk_library_counts)
        // and exit when the system goes quiet for $quiet_ms OR we hit $max_ms.
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

        // Compute fresh counts so the JS can render the splash with 100%
        // correct numbers immediately, bypassing any object-cache race
        // between this AJAX response and the subsequent in-place update.
        $counts = wps_ic_local::countLibraryImages();

        // Emit the JSON response now (JS reloads immediately). Then ask the
        // FPM worker to flush + close the client connection so the rest of the
        // handler doesn't keep the browser waiting.
        wp_send_json_success([
            'uncompressed' => count($counts['uncompressed']),
            'compressed'   => count($counts['compressed']),
        ]);
        // NOTE: wp_send_json_success() ends with wp_die() → unreachable below.
        // The remote `stop` notify to apiv3 is intentionally dropped here —
        // the service-side asyncPending GC + per-image callbacks_blocked
        // transient handle late-callback rejection cleanly; the user-facing
        // Stop is what matters, not telling the API about it.
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

            // Show stats for compress completion
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

            // Show stats for restore completion
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

    /**
     * HEAD-poll for /admin/customer-activity freshness signal.
     *
     * Belt-and-suspenders on top of the updated_post_meta hook.
     * The orch publishes the latest async callback timestamp via a HEAD
     * response header on /admin/customer-activity. When that timestamp
     * advances past our cached value, we bust wpc_bulk_library_counts so
     * the splash count snaps to the new post-callback library state on
     * the next render — without having to wait the 60s transient TTL.
     *
     * Default-OFF behind wpc_v2_head_poll_enabled(). When disabled the
     * handler returns enabled:false and does nothing else. When enabled
     * it sends a HEAD to the orch, reads the header, and busts the
     * transient on advance. JS poll cadence: 5s while bulk is active,
     * 30s on the splash, off otherwise (handler is cheap either way).
     */
    public function wps_ic_check_customer_activity()
    {
        // Post-audit fix: nonce check matches the convention of every
        // other admin AJAX in this class. Without it the endpoint was reachable
        // CSRF-style from a logged-in admin's other tabs/sites; low blast radius
        // (only busts a transient + HEADs orch) but the pattern was wrong.
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
        // Post-audit fix: use the canonical wpc_v2_get_apikey() resolver.
        // The previous get_option('wps_ic_apikey') read a non-canonical key that
        // doesn't exist in production; the real apikey lives in wps_ic['api_key']
        // (WPS_IC_OPTIONS). Same bug pattern seen before; wpc_v2_get_apikey
        // was created specifically to prevent recurrence.
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

        // Orch contract: timestamp ms epoch via X-WPC-Last-Callback-At header.
        // Be defensive: if absent, treat as inactive (don't bust the cache).
        $last_ts = (int) wp_remote_retrieve_header($resp, 'x-wpc-last-callback-at');
        $seen    = (int) get_option('wpc_v2_last_customer_activity_at', 0);
        $busted  = false;

        if ($last_ts > 0 && $last_ts > $seen) {
            delete_transient('wpc_bulk_library_counts');
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

    /**
     * Pull-manifest tick. Plugin polls orch's
     * GET /optimize-v2/manifest, queues new variants into the journal, and
     * kicks the existing drain pipeline. Replaces orch's PUSH model so we
     * don't depend on inbound POSTs from a fixed outbound IP that managed-WP
     * hosts rate-limit.
     *
     * Phase 1: dormant (wpc_v2_pull_enabled flag defaults off).
     * Phase 2: enabled alongside push; dedupe-by-sha256 means whichever lands
     *          first wins.
     * Phase 3: push disabled per-site; pull-only.
     *
     * Triggered by:
     *   - v2-head-poll.js when activity timestamp advances
     *   - WP-cron fallback (slow heartbeat)
     */
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

        // Long-poll support. JS passes wait_ms (0-25000); orch
        // holds the connection until a variant lands OR wait_ms elapses.
        // Plugin's continuous-loop client re-fires on every response so the
        // end-to-end latency floor drops from 5s tick cadence to ~50ms
        // transport + orch's encode time. Push-equivalent.
        $wait_ms = isset($_POST['wait_ms']) ? (int) $_POST['wait_ms'] : 0;
        $limit   = isset($_POST['limit'])   ? (int) $_POST['limit']   : 100;

        $result = wpc_v2_pull_manifest_tick($limit, $wait_ms);
        $result['enabled'] = true;
        wp_send_json_success($result);
    }

    /**
     * @return void
     * @since v6
     */
    public function wps_ic_isBulkRunning()
    {
        $bulkRunning = get_option('wps_ic_bulk_process');
        if (!$bulkRunning || empty($bulkRunning['status'])) {
            wp_send_json_error('not-running');
        }

        $status = ($bulkRunning['status'] === 'compressing') ? 'compressing' : 'restoring';
        $driver = !empty($bulkRunning['driver']) ? (string) $bulkRunning['driver'] : 'v1';

        // When the driver is 'sequential', the JS-driven per-image
        // loop owns dispatch. On page-load resume the loop wasn't running
        // anywhere, so the bulk would sit idle forever ("blank for 60+ s").
        // Return the remaining queue so the resume path can pick it up and
        // continue dispatching from where the prior session left off.
        if ($driver === 'sequential') {
            $queue_data = get_transient('wps_ic_compress_queue');
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

        // Backwards-compatible response shape. Old cached JS does
        // `response.data == 'compressing'` (string compare). New JS reads
        // `response.driver` as a sibling of `data`. Sending the object form
        // {status, driver} as `data` breaks the old string compare, so it falls
        // through to fetchRestoreData() and shows the wrong UI. wp_send_json
        // directly so we can place 'driver' alongside the WP success envelope.
        wp_send_json([
            'success' => true,
            'data'    => $status,
            'driver'  => $driver,
        ]);
    }

    /**
     * @return void
     * @since v6
     */
    public function wpc_ic_start_bulk_restore()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }
        // Performance Lab - generate webp on upload
        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }

        // Audit hardening (P0-4 server-side, REVISED). Same
        // story as wpc_ic_start_bulk_compress: the earlier session-
        // presence check false-positived on stale state. Switch to a
        // short-lived in-flight marker that only catches true concurrent
        // starts within ~10s. Revert: delete this if-block + set_transient.
        $inflight = get_transient('wpc_restore_start_inflight');
        if (!empty($inflight)) {
            status_header(409);
            wp_send_json_error(['msg' => 'bulk-start-inflight']);
        }
        set_transient('wpc_restore_start_inflight', time(), 10);

        // Restore-start timing probe. The debug.log showed restore "never
        // starts" while a compress was draining variants; suspect the start request
        // either waits for an FPM worker (pool saturated by drains) OR prepareRestoreImages
        // is slow. Stamp entry, prepareRestoreImages duration, and total wall to find out.
        $rs_t0 = microtime(true);
        error_log('[WPC RestoreStart] handler ENTERED at ' . gmdate('H:i:s'));

        // Delete previously parsed images
        delete_transient('wps_ic_bulk_done');
        delete_option('wps_ic_parsed_images');

        $local = new wps_ic_local();
        $prep_t0 = microtime(true);
        $imagesToRestore = $local->prepareRestoreImages();
        error_log(sprintf('[WPC RestoreStart] prepareRestoreImages took %dms (found %d compressed)',
            (int) round((microtime(true) - $prep_t0) * 1000),
            is_array($imagesToRestore['compressed'] ?? null) ? count($imagesToRestore['compressed']) : 0
        ));

        // Queue + drain-chain rewrite. The legacy path looped ALL compressed
        // images calling restoreV4() synchronously in one request, which hit
        // max_execution_time on libraries with 20+ images (Cloudways default is
        // 60s; each restoreV4 takes 2-5s incl. thumb regen). Now: queue all IDs,
        // fire non-blocking loopback to wpc_bulk_v2_restore_drain, return
        // immediately. The drain chain processes 1 image at a time (K=1, since it's local
        // disk I/O with no need for curl_multi), self-chains every ~25s, survives
        // tab close. Legacy bulkRestoreHeartbeat reads the same BulkStatus +
        // parsed_images options the drain populates per restore — UI unchanged.
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
            // Clear leftover stop-signal from a previous run.
            delete_transient('wpc_bulk_stop_signal');
            // Stamp start time (ms) so the completion view can show
            // "Completed in X seconds", which is more reliable than the
            // file-size-on-disk metric that races with restoreV4 writes.
            update_option('wpc_bulk_restore_started_ms', (int) round(microtime(true) * 1000), false);

            // Fire the drain chain. Non-blocking — returns immediately.
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

            // Send restore call
            $local = new wps_ic_local();

            // Send the call to API
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

            // Remove Generated Images
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

            // Restore only full
            $restore_image_path = $backup_images['full'];

            // If backup file exists
            if (file_exists($restore_image_path)) {
                unlink($path_to_image);

                // Restore from local backups
                $copy = copy($restore_image_path, $path_to_image);

                // Delete the backup
                unlink($restore_image_path);
            }

            clearstatcache();

            wp_update_attachment_metadata($imageID, wp_generate_attachment_metadata($imageID, $path_to_image));

            delete_transient('wps_ic_compress_' . $imageID);
            delete_post_meta($imageID, 'ic_bulk_running');

            // Remove meta tags
            delete_post_meta($imageID, 'ic_stats');
            delete_post_meta($imageID, 'ic_compressed_images');
            delete_post_meta($imageID, 'ic_compressed_thumbs');
            delete_post_meta($imageID, 'ic_backup_images');
            update_post_meta($imageID, 'ic_status', 'restored');

            return true;
        }

        return false;
    }

    /**
     * @return void
     * @since v6
     */
    public function wpc_ic_start_bulk_compress()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }

        // Audit hardening (P0-4 server-side, REVISED). The
        // earlier implementation checked `wpc_bulk_session_ids` (2h TTL)
        // as the "is a bulk running?" signal, which false-positived on
        // stale state. Symptom: user did a bulk, didn't get a clean
        // cleanup-AJAX (closed tab, browser crashed, network drop), then
        // tried to start a new bulk within 2h and got blocked.
        //
        // New approach: short-lived in-flight marker. It only catches TRUE
        // concurrent starts (within ~10s of each other), which is the
        // race we actually wanted to protect against (browser/network
        // glitch causing two AJAX requests to hit the server at once).
        // It doesn't false-positive on stale sessions.
        //
        // The marker has a 10s TTL. We set it BEFORE doing the actual bulk
        // setup so a second concurrent call sees it. If the first
        // setup completes faster than 10s the marker still expires
        // naturally; it's only a race-window block, not a session lock.
        //
        // Revert: delete this entire if-block + the set_transient below.
        $inflight = get_transient('wpc_bulk_start_inflight');
        if (!empty($inflight)) {
            status_header(409);
            wp_send_json_error(['msg' => 'bulk-start-inflight']);
        }
        set_transient('wpc_bulk_start_inflight', time(), 10);

        // Clean up previous bulk state
        delete_transient('wps_ic_bulk_done');
        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_bulk_counter');

        // Build queue of uncompressed image IDs
        $compress = new wps_local_compress();
        $image_ids = $compress->getAllImageIDs();
        $total = count($image_ids);

        if ($total === 0) {
            wp_send_json_error(['msg' => 'no-images-found']);
        }

        // Store queue in transient (mirrors restore pattern)
        set_transient('wps_ic_compress_queue', [
            'queue' => array_values($image_ids),
            'total_images' => $total,
        ], 3600);

        // Reset bulk stats
        update_option('wps_ic_BulkStatus', [
            'foundImageCount' => $total,
            'compressedImageCount' => 0,
            'compressedThumbs' => 0,
            'total' => ['original' => ['size' => 0], 'compressed' => ['size' => 0]],
        ]);

        // When v2 protocol is active, switch bulk to the server-side
        // drain chain (wpc_bulk_v2_drain loopback). JS polls heartbeat only;
        // no per-image admin-ajax loop. Survives tab close. Lighter on PHP
        // worker pool (2 connections vs K=3 JS dispatch).
        $is_v2 = function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol() && class_exists('WPS_LocalV2');
        // Sequential JS-driven bulk (default on). Replaces the
        // server-side v2 drain chain with a JS loop that calls
        // wps_ic_compress_live for each image, the exact same code path as
        // clicking Optimize on a single image, just iterated. Wall-time per
        // image now matches single-image timing (~2-4s Phase A, ~12s total)
        // instead of the drain chain's ~30s+ per image (loopback bootstrap
        // 1-2s + wait-gate up to 25s + lock acquire). Toggle off with:
        //   wp option update wpc_bulk_sequential 0
        $sequential = (bool) get_option('wpc_bulk_sequential', 1);
        $driver = $sequential ? 'sequential' : ($is_v2 ? 'v2' : 'v1');

        update_option('wps_ic_bulk_process', [
            'date'   => date('y-m-d H:i:s'),
            'status' => 'compressing',
            'driver' => $driver,
        ]);
        set_transient('wps_ic_bulk_running', date('y-m-d H:i:s'), 3600);
        // Liveness heartbeat — bumped per image / per drain slice below. If the
        // driver dies mid-run the orphaned flag self-heals (wpc_bulk_process_active).
        if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }

        if ($driver === 'sequential') {
            // Pre-populate session_ids with the full queue so the heartbeat
            // tally + completion list pick up everything that lands.
            set_transient('wpc_bulk_session_ids', array_values(array_map('intval', $image_ids)), 2 * HOUR_IN_SECONDS);
            delete_transient('wpc_bulk_stop_signal');
            delete_transient('wpc_bulk_completed_cache');
            delete_transient('wpc_bulk_library_counts');
            // Audit fix: also clear the completion-cleanup one-shot
            // guard. Otherwise bulk-A completes, sets the 5-min guard, bulk-B
            // starts within 5 min, and completion-cleanup is skipped, so ML rows
            // for bulk-B images may show stale 'Optimizing' state.
            delete_transient('wpc_bulk_completion_cleanup_done');
            // No loopback fire — JS owns the iteration.

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
            // Clear the one-shot completion-cleanup guard so a
            // fresh bulk can fire its per-image cleanup at terminal state.
            delete_transient('wpc_bulk_completion_cleanup_done');
            self::wpc_bulk_v2_fire_loopback();
        }

        wp_send_json([
            'success' => true,
            'data'    => ['status' => 'success', 'total' => $total],
            'driver'  => $driver,
        ]);
    }

    /**
     * Process ONE image from the bulk compress queue (called by JS in a loop).
     *
     * When wpc_use_v2_protocol() is true, routes through run_v2_optimize
     * (same path as manual button + lazy first-view). Falls back to v1
     * singleCompressV4 on v1 sites or when v2 transport fails.
     */
    public function wps_ic_doBulkCompress()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // Sequential bulk is driven by this JS-called loop — each call is a unit of
        // progress, so bump the liveness heartbeat. If the tab closes mid-run the
        // heartbeat lapses and the orphaned flag self-heals (wpc_bulk_process_active).
        if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }

        // When the server-side v2 drain chain is active, the JS-driven
        // per-image loop is redundant: wpc_bulk_v2_drain owns the queue.
        // Return finished=true immediately so any cached legacy JS
        // (bulkCompressHeartbeat → wps_ic_doBulkCompress recursive loop)
        // stops on the first response. Prevents the drain chain + JS loop
        // both dequeuing the same imageIDs (race condition).
        // When the server-side v2 drain chain owns the queue, the JS-driven
        // per-image loop is redundant. We must NOT return finished:true
        // unconditionally: old cached legacy JS reads that as "done!" and
        // shows the post-bulk summary card prematurely (with zeros, since v2
        // drain populates session_ids not the legacy compressedImageCount).
        // Only signal finished when the v2 chain has actually finished
        // (queue empty AND no Phase B drains in flight).
        $bulkRunning = get_option('wps_ic_bulk_process');
        if (!empty($bulkRunning['driver']) && $bulkRunning['driver'] === 'v2') {
            $queue = get_transient('wps_ic_compress_queue');
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

        $queue_data = get_transient('wps_ic_compress_queue');
        if (empty($queue_data['queue'])) {
            // Queue empty — bulk is done
            delete_option('wps_ic_bulk_process');
            delete_transient('wps_ic_bulk_running');
            set_transient('wps_ic_bulk_done', true, 60);
            wp_send_json_success(['finished' => true]);
        }

        // Take next image from queue
        $imageID = intval($queue_data['queue'][0]);
        unset($queue_data['queue'][0]);
        $queue_data['queue'] = array_values($queue_data['queue']);
        set_transient('wps_ic_compress_queue', $queue_data, 3600);

        // Backup all sizes before compression, then compress.
        // Bulk passes allowRetry=false: failed images skip to next instead of scheduling 100+ cron retries.
        // Users can re-run bulk later or let lazy backfill pick them up.
        $compress = new wps_local_compress();
        $backupOk = $compress->backup_all_sizes($imageID);
        if (!$backupOk) {
            error_log('[WPC Bulk] SKIPPED image=' . $imageID . ' — backup failed');
        } else {
            // Route through v2 when active (same entry point as manual
            // button + lazy first-view). Falls back to v1 singleCompressV4 on
            // v1 sites or when v2 transport fails, as defense in depth.
            $used_v2 = false;
            if (function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol() && class_exists('WPS_LocalV2')) {
                $v2_result = self::run_v2_optimize($imageID);
                if (!empty($v2_result['ok'])) {
                    $used_v2 = true;
                } elseif (!empty($v2_result['error']) && $v2_result['error'] === 'already_in_flight') {
                    // Another Phase A is in flight for this image (manual click
                    // or lazy trigger). Skip cleanly — don't double-fire and
                    // don't fall through to v1.
                    error_log('[WPC Bulk] SKIPPED image=' . $imageID . ' — already_in_flight');
                    $used_v2 = true;
                }
            }
            if (!$used_v2) {
                $compress->singleCompressV4($imageID, 'silent', false, 'bulk');
            }
        }

        // Check if compression actually succeeded
        $status = get_post_meta($imageID, 'ic_status', true);
        $total = $queue_data['total_images'];
        $leftover = count($queue_data['queue']);

        $bulkStatus = get_option('wps_ic_BulkStatus');

        if ($status === 'compressed') {
            // Success — update counters
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

    /**
     * Fire a non-blocking loopback POST to wpc_bulk_v2_drain. Used by
     * wpc_ic_start_bulk_compress to kick off the chain AND by wpc_bulk_v2_drain
     * itself to self-chain after each ~25 s slice. Cookies are forwarded so
     * the handler runs as the originating user (current_user_can passes).
     */
    /**
     * Shared LOCAL-VHOST loopback connect helper (connect-ONLY).
     *
     * Mirrors the single-op fix's connect technique (dispatch_async_loopback) for reuse
     * by the BULK drain self-chains, which previously fsockopen'd the PUBLIC host ($parts['host']),
     * the CDN/WAF edge on a datacenter-IP site (wpcompress.com behind cdn-cgi). The edge accepts
     * the TCP connect → fsockopen truthy → the cookieless nopriv self-POST is challenged/dropped at
     * the edge and never reaches local PHP-FPM, so the bulk drain self-chain silently dies (live:
     * zone 881159 restore wedged at status=restoring 10+ min).
     *
     * Connects to a LOCAL address (127.0.0.1 → localhost → public host) while keeping the caller's
     * Host: header + TLS SNI/peer_name = the public $host so the right vhost answers on the box,
     * bypassing the edge. Returns the FIRST open stream (or false if every rung fails to connect).
     *
     * CONNECT-ONLY by design: does NOT read, does NOT write, carries NO token logic — bulk auths
     * via HMAC in its own request body, and is fire-and-forget. The caller fwrite()s its own request
     * and fclose()s; re-entrancy/landing are handled by the caller's GET_LOCK + redrain marker. The
     * single-op token/confirm logic stays exclusively in dispatch_async_loopback (NOT routed here).
     *
     * Chain is filterable via the SAME 'wpc_loopback_connect_host' filter the single-op fix uses, so
     * a unix-socket / split-horizon override applies uniformly to both subsystems.
     *
     * @param string $host           Public vhost — Host: header + TLS SNI/peer name, NOT the connect target.
     * @param int    $port           Connect port (caller passes the same $port it computed: 443/80).
     * @param bool   $is_https       Whether to wrap the socket in TLS (tls://) with self-loopback SNI.
     * @param float  $connect_budget Per-rung connect timeout in seconds (caller passes 0.2 to match the
     *                               old fsockopen budget; clamped to >=0.05).
     * @return resource|false        An open stream socket (caller owns it: must fwrite + fclose), or
     *                               false if NO rung connected.
     */
    // Visibility widened from private to public (it was private static before). The CF
    // header-provisioning self-loopback (addons/v2/v2-config-sync.php) hit the SAME "fsockopen the
    // public host == fake-connect to the CDN/WAF edge" fake-success class fixed earlier for
    // the single-op + bulk drains. It now reuses THIS proven connect-only helper instead of duplicating
    // the connect chain. Widening private→public strictly broadens access; the two existing self::
    // callers (bulk-compress drain @~5130, bulk-restore drain @~5521) are unaffected; there are no other
    // callers. Cross-class call: wps_ic_ajax::wpc_loopback_open_socket(...). Still CONNECT-ONLY (no
    // read/write/token here); the caller owns fwrite + fclose and its own auth.
    public static function wpc_loopback_open_socket($host, $port, $is_https, $connect_budget = 0.2)
    {
        $host = (string) $host;
        if ($host === '') { return false; }
        $port           = (int) $port;
        $is_https       = (bool) $is_https;
        $connect_budget = max(0.05, (float) $connect_budget);

        // SAME filter + default order as the single-op fix. String or ordered-array return both
        // accepted; de-duped (localhost often == 127.0.0.1; public host may equal a literal). Args
        // mirror the single-op signature so ONE filter covers both subsystems.
        $connect_chain = apply_filters('wpc_loopback_connect_host', ['127.0.0.1', 'localhost', $host], $host, $is_https, $port);
        if (is_string($connect_chain) && $connect_chain !== '') { $connect_chain = [$connect_chain]; }
        if (!is_array($connect_chain) || empty($connect_chain)) { $connect_chain = ['127.0.0.1', 'localhost', $host]; }
        $connect_chain = array_values(array_unique(array_filter(array_map('strval', $connect_chain))));
        if (empty($connect_chain)) { return false; }

        // TLS-on-loopback: tls://127.0.0.1 makes the local terminator present the DOMAIN cert; peer
        // name 127.0.0.1 != domain so verification would always fail. Pin peer_name=$host (correct
        // SNI → right vhost cert) and DISABLE peer verification — this is a connection to OURSELVES
        // on the box, so cert-identity checking is neither meaningful nor desirable (no MITM surface
        // on loopback). Identical to dispatch_async_loopback's $ssl_ctx.
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
                return $sock; // FIRST successful rung wins; caller writes its HMAC body + closes.
            }
            $miss[] = $chost . '(' . $errno . ')';
        }
        // Single line on total failure (avoid per-fire log spam on split-container hosts where all rungs miss).
        error_log('[WPC LoopbackOpen] all_rungs_miss port=' . $port . ' tries=' . implode(',', $miss));
        return false; // caller deletes its draining marker + returns false (Layer-2 tab-kick carries)
    }

    public static function wpc_bulk_v2_fire_loopback()
    {
        // WORK-GATE — only fire when the compress queue actually has work.
        $q = get_transient('wps_ic_compress_queue');
        if (empty($q['queue'])) { return false; }
        // RISK #5 — skip if a worker is already draining; flag a redrain so a tick
        // arriving mid-slice is never dropped (mirror v2-pull-manifest.php:773-784).
        if (($lock_ts = (int) get_transient('wpc_bulk_compress_draining')) > 0) {
            set_transient('wpc_bulk_compress_redrain_pending', time(), 60);
            return false;
        }
        set_transient('wpc_bulk_compress_draining', time(), 15);
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($apikey === '') { delete_transient('wpc_bulk_compress_draining'); return false; } // degrade-safe → Layer 2/watchdog carry
        $ts  = time();
        $sig = hash_hmac('sha256', 'wpc_bulk_compress_drain.' . $ts, $apikey); // DISTINCT namespace
        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);                                           // RISK #2 — loopback/origin host, NOT edge
        if (empty($parts['host'])) { delete_transient('wpc_bulk_compress_draining'); return false; }
        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        $port = $is_https ? 443 : 80;
        $host = (string) $parts['host'];
        $path = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_bulk_v2_drain_loop';
        $body = http_build_query(['t' => $ts, 'sig' => $sig]);
        $req  = "POST {$path} HTTP/1.1\r\nHost: {$host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($body) . "\r\nConnection: close\r\nUser-Agent: WPCBulkCompressDrain/1.0\r\n\r\n" . $body;
        // Connect to the LOCAL vhost (127.0.0.1→localhost→public host) via the shared helper,
        // keeping Host:/SNI = the public $host so the box's right vhost answers, NOT the CDN/WAF edge
        // (which the old public-host fsockopen fake-connected to, dropping the cookieless nopriv
        // self-POST and silently wedging the drain). Connect-only; the HMAC body + fire-and-forget
        // write below are unchanged. The 0.2s budget matches the old fsockopen (no added stall on a
        // broken-loopback host: same single-attempt cost order, then the Layer-2 tab-kick carries).
        $fp = self::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
        if (!$fp) { delete_transient('wpc_bulk_compress_draining'); error_log('[WPC BulkCompressDrain] loopback_connect_failed host=' . $host . ' port=' . $port); return false; }
        @stream_set_timeout($fp, 0, 100000); @fwrite($fp, $req); @fclose($fp);
        delete_transient('wpc_bulk_compress_redrain_pending');
        return true;
    }

    /**
     * Server-side bulk drain chain. Single chain enforced via MySQL
     * GET_LOCK('wpc_bulk_v2_chain', 0); redundant loopback fires bail at the
     * lock (other chain is already running). Loops dequeue + Phase A
     * dispatch up to ~25 s wall, then self-chains the next loopback so we
     * stay well under typical PHP exec_time.
     *
     * Within each iteration, K=3 images are dispatched in parallel via
     * curl_multi (graceful fallback to sequential when curl_multi unavailable).
     * Phase B callbacks land asynchronously from encoder pods as separate
     * inbound requests — not throttled by this chain.
     */
    public function wpc_bulk_v2_drain()
    {
        // PRIV (tab poll / watchdog) — guard UNCHANGED. Auth: requires admin cookies.
        if (!current_user_can('manage_wpc_settings')) {
            wp_die('', '', ['response' => 403]);
        }
        $this->run_compress_drain_slice();
        wp_die('', '', ['response' => 200]);
    }

    /**
     * L0+L1 COMPRESS NOPRIV LOOP HANDLER. Cookieless fsockopen self-chain
     * target. Auth ENTIRELY on HMAC (distinct namespace), then close the client
     * connection (FPM/LiteSpeed) and run the shared slice detached.
     */
    public function wpc_bulk_v2_drain_loop()
    {
        // RISK #1 — auth ENTIRELY on HMAC, copied verbatim from v2-pull-manifest.php:870-881, namespace swapped.
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $ts     = isset($_POST['t']) ? (int) $_POST['t'] : 0;
        $sig    = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
        if ($apikey === '' || $ts <= 0 || $sig === '' || abs(time() - $ts) > 60) { http_response_code(401); exit('auth'); }
        $expected = hash_hmac('sha256', 'wpc_bulk_compress_drain.' . $ts, $apikey); // DISTINCT namespace
        if (!hash_equals($expected, $sig)) { http_response_code(401); exit('sig'); }
        // LAYER 0 — close the client connection then run detached (FPM/LiteSpeed only; mod_php falls through).
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @ignore_user_abort(true);
        @set_time_limit(60); // RISK #6 advisory — the 8s wall in the slice is the REAL bound
        $this->run_compress_drain_slice(); // shared body does its OWN GET_LOCK + self-chain
        exit;
    }

    private function run_compress_drain_slice()
    {
        delete_transient('wpc_bulk_compress_draining'); // clear the ms-dispatch marker now the worker booted (GET_LOCK is the real mutex)
        if (!get_option('wps_ic_bulk_process')) {
            // No active bulk — nothing to do (e.g. user hit Stop between fires).
            return;
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '60');
        @ignore_user_abort(true);

        global $wpdb;
        // Acquire chain lock with timeout=0 (immediate). If held, another
        // chain is already draining — silent bail.
        $got = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", 'wpc_bulk_v2_chain', 0));
        if (!$got) {
            return;
        }

        $batch_K       = defined('WPC_BULK_K') ? max(1, (int) WPC_BULK_K) : 3;
        // Reduced from 25s to 8s. Smaller wall budget means faster worker
        // turnover under FPM pressure, more responsive Stop, and concurrent
        // requests (page loads, bg_swap callbacks) wait less for a slot.
        $wall_budget_s = 8.0;

        // Sequential mode. Default-ON for simpler debug + tracking.
        // Pins K=1 (one image dispatched per iteration) AND inserts an inner
        // wait-gate so the next iteration only proceeds after the dispatched
        // image's ic_status flips to 'compressed' or 'failed' (i.e. Phase B
        // variants fully landed). Result: image N+1 doesn't start until N
        // is end-to-end done, giving predictable wall-time per image with no overlapping
        // Phase B from prior images polluting the active state on screen.
        // Flip with: wp option update wpc_bulk_sequential 0 (back to K=3 + concurrent).
        $sequential = (bool) get_option('wpc_bulk_sequential', 1);
        if ($sequential) {
            $batch_K = 1;
            // The wait-gate below can take up to 25 s waiting for ic_status
            // to flip. The default 8 s wall budget would cut it short and the
            // chain would restart with the next image while Phase B for the
            // previous one is still landing, defeating the sequential
            // guarantee. Bump to 30 s so one full image (Phase A dispatch +
            // bounded Phase B wait + book-keeping) fits inside a single
            // loopback iteration.
            $wall_budget_s = 30.0;
        }
        $started       = microtime(true);
        $iter_count    = 0;

        try {
            while ((microtime(true) - $started) < $wall_budget_s) {
                // Responsive Stop: check a dedicated stop-signal
                // transient (cheap auto-loaded option lookup) OR the deleted
                // bulk_process option. Either short-circuits mid-loop so the
                // chain releases its worker within one iteration of Stop click.
                if (get_transient('wpc_bulk_stop_signal') || !get_option('wps_ic_bulk_process')) {
                    error_log('[WPC Bulk] drain paused mid-iteration');
                    break;
                }
                // Liveness heartbeat — server chain is alive and advancing.
                if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }
                // RISK #3 — memory guard. Exit the loop (→ finally RELEASE_LOCK →
                // self-chain re-fires a fresh worker; queue still non-empty so work advances).
                $mem_limit = wp_convert_hr_to_bytes((string) @ini_get('memory_limit'));
                if ($mem_limit > 0 && memory_get_usage(true) > (int) ($mem_limit * 0.8)) {
                    error_log('[WPC Bulk] drain slice mem-cap break usage=' . memory_get_usage(true) . ' limit=' . $mem_limit);
                    break;
                }
                $queue_data = get_transient('wps_ic_compress_queue');
                if (empty($queue_data['queue'])) {
                    // Queue drained — mark bulk process complete. Phase B
                    // callbacks may still be in flight; tally poller will
                    // finalize once they drain.
                    delete_option('wps_ic_bulk_process');
                    delete_transient('wps_ic_bulk_running');
                    delete_transient('wpc_bulk_library_counts'); // splash freshness
                    set_transient('wps_ic_bulk_done', true, 60);
                    break;
                }

                // Atomic dequeue of K imageIDs
                $batch = array_map('intval', array_slice($queue_data['queue'], 0, $batch_K));
                $queue_data['queue'] = array_values(array_slice($queue_data['queue'], count($batch)));
                set_transient('wps_ic_compress_queue', $queue_data, HOUR_IN_SECONDS);

                // Append to session-ids for the rolling-tally + completion-list UI
                $session_ids = get_transient('wpc_bulk_session_ids') ?: [];
                foreach ($batch as $id) {
                    $session_ids[] = $id;
                }
                set_transient('wpc_bulk_session_ids', $session_ids, 2 * HOUR_IN_SECONDS);

                // Per-image backup (disk I/O — fast, sequential is fine)
                $preps = [];
                $compress = new wps_local_compress();
                foreach ($batch as $id) {
                    // Re-check exclusion per image. getAllImageIDs() filters
                    // out wps_ic_exclude_live images at queue-BUILD time, but a user can
                    // exclude an image AFTER bulk start (queue is built once, drained over
                    // many requests). Without this re-check the excluded image is still
                    // backed up + dispatched + flipped to ic_compressing='optimizing',
                    // and since no Phase B ever renders it "compressed", its media-library
                    // card was stuck on "Optimizing 0J 0W 0A" forever. (The renderer-side
                    // precedence fix at media_library_live.class.php now also short-circuits
                    // to Excluded, but skipping the dispatch here stops the wasted encode +
                    // the stale optimizing meta at the source.)
                    if (get_post_meta($id, 'wps_ic_exclude_live', true) === 'true') {
                        error_log('[WPC Bulk] SKIPPED image=' . $id . ' — excluded mid-bulk');
                        continue;
                    }
                    $backupOk = $compress->backup_all_sizes($id);
                    if (!$backupOk) {
                        error_log('[WPC Bulk] SKIPPED image=' . $id . ' — backup failed');
                        continue;
                    }
                    $prep = self::prepare_v2_optimize($id, ['triggerContext' => 'bulk-compress']);
                    if (!empty($prep['ok'])) {
                        $preps[$id] = $prep;
                    } else {
                        error_log('[WPC Bulk] PREP_FAILED image=' . $id . ' — ' . ($prep['error'] ?? 'unknown'));
                    }
                }

                if (!empty($preps)) {
                    self::wpc_bulk_v2_dispatch_batch($preps);

                    // Match single-click behavior. compress_live
                    // extends drain_alive_until_ms by 60s AND fires the pull
                    // drain loop per dispatch (ajax.class.php:5311-5320).
                    // Bulk previously skipped this step, so pull drain went
                    // idle between images: AVIFs piled up server-side with
                    // no one fetching them, eager_flip never fired, and
                    // bulk stuck at "Optimizing 0J 0W 0A" until Stop. Now
                    // every batch (1 image in sequential, K in concurrent)
                    // refreshes the drain deadline + kicks the worker, so
                    // pull mode behaves identically to single-click bulks.
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

                    // Populate legacy wps_ic_BulkStatus counters so
                    // the post-bulk summary card (wps_ic_getBulkStats / the
                    // legacy heartbeat path) shows real numbers. The new v2
                    // poller computes its own tally from session_ids; this is
                    // strictly for the final summary card + old cached JS
                    // fallback paths.
                    $bulkStatus = get_option('wps_ic_BulkStatus') ?: [];
                    foreach (array_keys($preps) as $id) {
                        $status = get_post_meta($id, 'ic_status', true);
                        if ($status === 'compressed') {
                            $bulkStatus['compressedImageCount'] = ($bulkStatus['compressedImageCount'] ?? 0) + 1;
                            $variants = get_post_meta($id, 'ic_local_variants', true);
                            $bulkStatus['compressedThumbs'] = ($bulkStatus['compressedThumbs'] ?? 0) + (is_array($variants) ? count($variants) : 0);
                            // Field names: v2 callback writes 'size' (encoded) + 'originalSize' (echoed).
                            if (is_array($variants)) {
                                foreach ($variants as $v) {
                                    if (!is_array($v)) continue;
                                    $bulkStatus['total']['original']['size'] = ($bulkStatus['total']['original']['size'] ?? 0) + (int) ($v['originalSize'] ?? 0);
                                    $bulkStatus['total']['compressed']['size'] = ($bulkStatus['total']['compressed']['size'] ?? 0) + (int) ($v['size'] ?? 0);
                                }
                            }
                        }
                    }
                    update_option('wps_ic_BulkStatus', $bulkStatus);
                }
                $iter_count++;

                // Sequential mode wait-gate. Block until the dispatched
                // image's ic_status flips to 'compressed' or 'failed' (Phase B
                // fully done), OR until we run out of wall budget. The metadata
                // hook + Phase B callbacks toggle ic_status; we re-read via
                // wp_cache_delete to bypass alloptions cache from a different
                // FPM worker. Tick every 250ms, max-wait 25s per image (orch
                // p99 Phase B is ~17s for parents-only; family AVIFs add 5–10s).
                if ($sequential && !empty($preps)) {
                    $wait_img_id    = (int) array_keys($preps)[0];
                    $wait_start     = microtime(true);
                    $wait_max_s     = 25.0;
                    while (true) {
                        // Wall budget guard — let the chain re-fire for this same image.
                        if ((microtime(true) - $started) >= $wall_budget_s) break;
                        if ((microtime(true) - $wait_start) >= $wait_max_s) break;
                        if (get_transient('wpc_bulk_stop_signal')) break;

                        wp_cache_delete($wait_img_id, 'post_meta');
                        // Exit on EITHER ic_status (set by
                        // promote_to_compressed when Phase A returns) OR
                        // ic_compressing.status (set earlier by eager_flip
                        // when first Phase B variant lands via push/pull).
                        // This previously only watched ic_status, so drain
                        // blocked up to 25s per image waiting for Phase A
                        // to return even after pull-manifest had delivered
                        // every variant. Bulk visually advanced 5/5 via the
                        // heartbeat's early-advance gate but the drain
                        // chain stayed busy, so the bulk_done transient never set
                        // and the completion screen never showed.
                        $status = get_post_meta($wait_img_id, 'ic_status', true);
                        if ($status === 'compressed' || $status === 'failed') break;
                        $ic_c = get_post_meta($wait_img_id, 'ic_compressing', true);
                        $ic_c_status = is_array($ic_c) ? ($ic_c['status'] ?? '') : '';
                        if ($ic_c_status === 'compressed') break;

                        usleep(250000); // 250 ms
                    }
                }
            }
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'wpc_bulk_v2_chain'));
        }

        // Self-chain if more work remains. Loopback after lock release so the
        // next chain doesn't bail at the lock acquire. RISK #5 — redrain-aware:
        // a tick that arrived mid-slice (flagged the redrain transient) is never dropped.
        $queue_data = get_transient('wps_ic_compress_queue');
        $redrain    = (int) get_transient('wpc_bulk_compress_redrain_pending') > 0;
        if ($redrain) { delete_transient('wpc_bulk_compress_redrain_pending'); }
        if (!empty($queue_data['queue']) || $redrain) {
            self::wpc_bulk_v2_fire_loopback();
        }

        error_log(sprintf('[WPC Bulk] drain slice complete iters=%d K=%d wall=%.2fs',
            $iter_count, $batch_K, microtime(true) - $started));
    }

    /**
     * Dispatch one batch of prepared Phase A POSTs in parallel via
     * curl_multi. Falls back to sequential blocking POSTs if curl_multi is
     * disabled (rare, since most hosts ship curl_multi with the curl extension).
     *
     * $preps: [imageID => ['ok'=>true, 'client'=>WPS_LocalV2, 'variants'=>[], 'options'=>[], 't0'=>float]]
     */
    public static function wpc_bulk_v2_dispatch_batch(array $preps)
    {
        if (empty($preps)) return;

        // Fast path: curl_multi parallel dispatch
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

            // Drive the multi loop
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.5);
            } while ($running > 0);

            // Process responses
            foreach ($handles as $id => $h) {
                $body_raw  = curl_multi_getcontent($h['ch']);
                $http_code = (int) curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
                $wall_ms   = (int) round((microtime(true) - $h['t0']) * 1000);
                $result    = $h['client']->process_response($id, $http_code, $body_raw);
                if (empty($result['ok'])) {
                    error_log(sprintf('[WPC Bulk] FAILED image=%d wall=%dms err=%s',
                        $id, $wall_ms, $result['error'] ?? 'unknown'));
                }
                curl_multi_remove_handle($mh, $h['ch']);
                curl_close($h['ch']);
            }
            curl_multi_close($mh);
            return;
        }

        // Slow path: sequential (curl_multi unavailable OR single-image batch).
        foreach ($preps as $id => $p) {
            $result = $p['client']->optimize($id, $p['variants'], $p['options']);
            if (empty($result['ok'])) {
                error_log('[WPC Bulk] FAILED image=' . $id . ' — ' . ($result['error'] ?? 'unknown'));
            }
        }
    }

    /**
     * Fire a non-blocking loopback POST to wpc_bulk_v2_restore_drain.
     * Mirrors wpc_bulk_v2_fire_loopback (compress side). Cookies forwarded so
     * the handler runs as the originating user.
     */
    public static function wpc_bulk_v2_restore_fire_loopback()
    {
        // WORK-GATE — only fire when the restore queue actually has work.
        $q = get_transient('wps_ic_restore_queue');
        if (empty($q['queue'])) { return false; }
        // RISK #5 — skip if a worker is already draining; flag a redrain so a tick
        // arriving mid-slice is never dropped (mirror v2-pull-manifest.php:773-784).
        if (($lock_ts = (int) get_transient('wpc_bulk_restore_draining')) > 0) {
            set_transient('wpc_bulk_restore_redrain_pending', time(), 60);
            return false;
        }
        set_transient('wpc_bulk_restore_draining', time(), 15);
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($apikey === '') { delete_transient('wpc_bulk_restore_draining'); return false; } // degrade-safe → Layer 2/watchdog carry
        $ts  = time();
        $sig = hash_hmac('sha256', 'wpc_bulk_restore_drain.' . $ts, $apikey); // DISTINCT namespace
        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);                                           // RISK #2 — loopback/origin host, NOT edge
        if (empty($parts['host'])) { delete_transient('wpc_bulk_restore_draining'); return false; }
        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        $port = $is_https ? 443 : 80;
        $host = (string) $parts['host'];
        $path = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_bulk_v2_restore_drain_loop';
        $body = http_build_query(['t' => $ts, 'sig' => $sig]);
        $req  = "POST {$path} HTTP/1.1\r\nHost: {$host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($body) . "\r\nConnection: close\r\nUser-Agent: WPCBulkRestoreDrain/1.0\r\n\r\n" . $body;
        // Connect to the LOCAL vhost (127.0.0.1→localhost→public host) via the shared helper,
        // keeping Host:/SNI = the public $host so the box's right vhost answers, NOT the CDN/WAF edge.
        // This is the bulk-RESTORE wedge fix (zone 881159: status=restoring 10+ min, public-host
        // fsockopen fake-connected to the edge). Connect-only; HMAC body + fire-and-forget write below
        // are unchanged. The 0.2s budget matches the old fsockopen.
        $fp = self::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
        if (!$fp) { delete_transient('wpc_bulk_restore_draining'); error_log('[WPC BulkRestoreDrain] loopback_connect_failed host=' . $host . ' port=' . $port); return false; }
        @stream_set_timeout($fp, 0, 100000); @fwrite($fp, $req); @fclose($fp);
        delete_transient('wpc_bulk_restore_redrain_pending');
        return true;
    }

    /**
     * Server-side bulk restore drain chain. Mirrors wpc_bulk_v2_drain
     * (compress side) but processes K=1 image at a time, since restoreV4 is local
     * disk I/O + thumb regen, no orchestrator round-trip, no benefit from
     * curl_multi parallelism. Bounded by ~25s wall budget per slice, then
     * self-chains via non-blocking loopback. Populates wps_ic_BulkStatus +
     * wps_ic_parsed_images per restore so the legacy bulkRestoreHeartbeat
     * (unchanged) shows correct progress.
     */
    public function wpc_bulk_v2_restore_drain()
    {
        // PRIV (tab poll / watchdog) — guard UNCHANGED.
        if (!current_user_can('manage_wpc_settings')) {
            wp_die('', '', ['response' => 403]);
        }
        $this->run_restore_drain_slice();
        wp_die('', '', ['response' => 200]);
    }

    /**
     * L0+L1 RESTORE NOPRIV LOOP HANDLER. Cookieless fsockopen self-chain
     * target. Auth ENTIRELY on HMAC (distinct namespace), then close the client
     * connection (FPM/LiteSpeed) and run the shared slice detached.
     */
    public function wpc_bulk_v2_restore_drain_loop()
    {
        // RISK #1 — auth ENTIRELY on HMAC, copied verbatim from v2-pull-manifest.php:870-881, namespace swapped.
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $ts     = isset($_POST['t']) ? (int) $_POST['t'] : 0;
        $sig    = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
        if ($apikey === '' || $ts <= 0 || $sig === '' || abs(time() - $ts) > 60) { http_response_code(401); exit('auth'); }
        $expected = hash_hmac('sha256', 'wpc_bulk_restore_drain.' . $ts, $apikey); // DISTINCT namespace
        if (!hash_equals($expected, $sig)) { http_response_code(401); exit('sig'); }
        // LAYER 0 — close the client connection then run detached (FPM/LiteSpeed only; mod_php falls through).
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @ignore_user_abort(true);
        @set_time_limit(60); // RISK #6 advisory — the 8s wall in the slice is the REAL bound
        $this->run_restore_drain_slice(); // shared body does its OWN GET_LOCK + self-chain
        exit;
    }

    private function run_restore_drain_slice()
    {
        delete_transient('wpc_bulk_restore_draining'); // clear the ms-dispatch marker now the worker booted (GET_LOCK is the real mutex)
        if (!get_option('wps_ic_bulk_process')) {
            return;
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '60');
        @ignore_user_abort(true);

        global $wpdb;
        $got = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", 'wpc_bulk_v2_restore_chain', 0));
        if (!$got) {
            return;
        }

        try {
            $started = microtime(true);
            // 8s wall budget (was 25s). restoreV4 ~2-5s per image,
            // so 2-3 restores per slice. Self-chain after for more.
            $wall_budget_s = 8.0;
            $compress = new wps_local_compress();
            $processed = 0;

            while ((microtime(true) - $started) < $wall_budget_s) {
                // Responsive Stop signal + bulk_process check.
                if (get_transient('wpc_bulk_stop_signal') || !get_option('wps_ic_bulk_process')) {
                    error_log('[WPC Bulk Restore] drain paused mid-iteration');
                    break;
                }
                // Liveness heartbeat — server chain is alive and advancing.
                if (function_exists('wpc_bulk_heartbeat_touch')) { wpc_bulk_heartbeat_touch(); }
                // RISK #3 — memory guard. Exit the loop (→ finally RELEASE_LOCK →
                // self-chain re-fires a fresh worker; queue still non-empty so work advances).
                $mem_limit = wp_convert_hr_to_bytes((string) @ini_get('memory_limit'));
                if ($mem_limit > 0 && memory_get_usage(true) > (int) ($mem_limit * 0.8)) {
                    error_log('[WPC Bulk Restore] drain slice mem-cap break usage=' . memory_get_usage(true) . ' limit=' . $mem_limit);
                    break;
                }
                $queue = get_transient('wps_ic_restore_queue');
                if (empty($queue['queue'])) {
                    // Queue drained — mark bulk done.
                    delete_option('wps_ic_bulk_process');
                    delete_transient('wps_ic_bulk_running');
                    delete_transient('wpc_bulk_library_counts'); // splash freshness
                    delete_transient('wps_ic_restore_queue');    // don't leave an empty-queue transient lingering (2h TTL)
                    set_transient('wps_ic_bulk_done', true, 60);
                    // Customer Purge v1 — bulk restore complete: ONE fleet-wide purge
                    // (CloudFlare + both Bunny PZs + cdn-mc pod-fs fleet), fire-and-forget.
                    // Flag-gated (default off). This is the REAL bulk completion point (the
                    // v2 drain the JS actually fires); wps_ic_RestoreFinished is legacy/uncalled.
                    // The GET_LOCK + wps_ic_bulk_process guard above make it fire once.
                    if (function_exists('wpc_purge_compat')) {
                        // Blocking and result-logged. The bulk-done signal
                        // (wps_ic_bulk_done transient) is already set above, so blocking this one
                        // mode=all purge at drain-completion is invisible to the user's "done"
                        // perception and surfaces the orch outcome instead of swallowing it.
                        // A Throwable must never 500 the restore response.
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

                // Chain armor, so one broken image doesn't hang the
                // whole process. (a) Progress tick BEFORE the restore: the heartbeat
                // watchdog uses staleness of this stamp to detect a dead/wedged slice and
                // re-fire the loopback — and because the image was already popped above,
                // revival = skip-and-continue with the NEXT image, exactly the wanted
                // semantics. (b) A Throwable from one image logs + continues, never kills
                // the drain.
                update_option('wpc_bulk_restore_last_tick', time(), false);
                try {
                    $compress->restoreV4($imageID);
                } catch (\Throwable $e) {
                    error_log('[WPC Bulk Restore] image=' . $imageID . ' threw (' . $e->getMessage() . ') — skipped, chain continues');
                }

                // Populate parsed_images so heartbeat picks the last restored
                // imageID as $lastID. Also stash the original file size so
                // the cumulative "bytes restored" stat doesn't re-stat the
                // filesystem on every heartbeat poll.
                $parsed = get_option('wps_ic_parsed_images') ?: [];
                $orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : get_attached_file($imageID);
                $orig_bytes = ($orig_path && file_exists($orig_path)) ? (int) @filesize($orig_path) : 0;
                $parsed[$imageID] = [
                    'restored_at' => time(),
                    'bytes'       => $orig_bytes,
                ];
                update_option('wps_ic_parsed_images', $parsed, false);

                // Update restoredImageCount for the progress bar.
                $bulkStatus = get_option('wps_ic_BulkStatus') ?: [];
                $bulkStatus['restoredImageCount'] = ($bulkStatus['restoredImageCount'] ?? 0) + 1;
                update_option('wps_ic_BulkStatus', $bulkStatus);

                $processed++;

                // Inter-restore I/O breather. restoreV4 fires
                // wpc_chain_next_pending_regen($imageID) at its tail; that
                // kicks off the thumb-regen worker chain. Without a sleep we
                // immediately queue the NEXT restoreV4 (which marks ANOTHER
                // pending regen), leaving multiple concurrent regen workers
                // contending for disk + DB.
                //
                // Default 1250ms (down from 2000). Per-
                // iteration wall (3.4s avg restore + 1.25s sleep ≈ 4.65s)
                // still keeps 1-2 iterations per 8s slice, so 1000 images
                // ≈ 600-1000 slices × ~8.15s wall ≈ 80-130 min total. The
                // sweet spot between regen headroom (kept) and total wall
                // time (not doubled like 2000ms was).
                // Filter: wpc_bulk_restore_iteration_sleep_ms (clamp 0..5000).
                $sleep_ms = (int) apply_filters('wpc_bulk_restore_iteration_sleep_ms', 1250);
                $sleep_ms = max(0, min(5000, $sleep_ms));
                if ($sleep_ms > 0) {
                    usleep($sleep_ms * 1000);
                }
            }
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'wpc_bulk_v2_restore_chain'));
        }

        // Self-chain if more work remains. RISK #5 — redrain-aware: a tick that
        // arrived mid-slice (flagged the redrain transient) is never dropped.
        $queue   = get_transient('wps_ic_restore_queue');
        $redrain = (int) get_transient('wpc_bulk_restore_redrain_pending') > 0;
        if ($redrain) { delete_transient('wpc_bulk_restore_redrain_pending'); }
        if (!empty($queue['queue']) || $redrain) {
            self::wpc_bulk_v2_restore_fire_loopback();
        }

        error_log(sprintf('[WPC Bulk Restore] drain slice complete processed=%d wall=%.2fs',
            $processed, microtime(true) - $started));
    }

    /**
     * JS calls this after the rolling-tally poller observes 2
     * consecutive polls of fully drained state. Wipes the session list so
     * the next bulk starts fresh. Fallback: 2h transient TTL auto-expires.
     */
    public function wps_ic_bulkCompressCleanup()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('forbidden');
        }
        // Audit hardening (P1-26/27, P2-50): per-image transient
        // cleanup. This previously only deleted the SESSION-level transients (session
        // ids, done flag, library counts). Per-image transients (announced,
        // bg_retry_fired, phase_a_done, pending) lingered until their own
        // TTLs (5 min / 1 h / 10 min / 10 min respectively). On a 10K-image
        // bulk that's up to 40K leaked transients accumulating in the
        // options table.
        //
        // Snapshot the session_ids BEFORE deleting them so the foreach can
        // iterate. Each delete_transient is O(1) on a sane MySQL; even at
        // 10K calls this finishes in <1s on a healthy DB.
        //
        // Revert: remove the snapshot + foreach block; revert to the 3
        // delete_transient lines that existed before this hardening.
        $session_snapshot = get_transient('wpc_bulk_session_ids') ?: [];
        delete_option('wps_ic_bulk_process');
        delete_transient('wps_ic_bulk_running');
        delete_transient('wpc_bulk_session_ids');
        delete_transient('wps_ic_bulk_done');
        delete_transient('wpc_bulk_library_counts');
        // Per-image transients written during a bulk run. None of these are
        // load-bearing after bulk-done (they're all "in-flight" markers).
        foreach ($session_snapshot as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            delete_transient('wpc_v2_announced_'       . $id);  // 5min TTL, written by /bg_swap_announce
            delete_transient('wpc_v2_bg_retry_fired_'  . $id);  // 60s TTL, heartbeat retry guard
            delete_transient('wpc_v2_phase_a_done_'    . $id);  // 1h TTL, drain-complete marker
            delete_transient('wpc_v2_pending_'         . $id);  // 10min TTL, Phase B pending list
            delete_transient('wps_ic_compress_'        . $id);  // legacy compress lock
            delete_transient('wps_ic_heartbeat_'       . $id);  // 5min TTL, ML chip animation
            delete_transient('wpc_v2_t0_ms_'           . $id);  // 1h TTL, click-time stamp
            delete_transient('wpc_v2_warming_'         . $id);  // ML "warming" indicator
            delete_transient('wpc_v2_callbacks_blocked_' . $id); // restore-in-progress guard
        }
        wp_send_json_success(['ok' => true, 'cleaned' => count($session_snapshot)]);
    }

    /**
     * Restore grace-window cleanup. JS calls this at the end of the
     * 60s grace-poll window (mirrors the compress pattern). Tears down the
     * restore-session transients so the splash + dashboard reflect post-
     * restore reality.
     *
     * Why deferred (not at terminal): the JS poll keeps reading bytes/avg/
     * elapsed for 60s after the first 'done' so stragglers (any late-arriving
     * restored_at stamps from in-flight drain workers) are reflected in the
     * success counters. Deleting wps_ic_bulk_process at terminal would route
     * subsequent polls to a different branch with zeroed-out stats, the same bug
     * compress hit before its own deferred-cleanup fix.
     */
    public function wps_ic_bulkRestoreCleanup()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error('forbidden');
        }
        // Audit hardening (P1-26/27, P2-50): per-image transient
        // cleanup for restore. Same rationale as compress cleanup above;
        // see wps_ic_bulkCompressCleanup for the full comment.
        //
        // Restore's session list lives in `wps_ic_restore_queue` (not
        // wpc_bulk_session_ids: different name, same shape). Snapshot
        // it BEFORE the delete_transient so the foreach can iterate.
        //
        // Revert: remove the snapshot + foreach; restore the earlier
        // delete sequence.
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
            delete_transient('wpc_v2_callbacks_blocked_' . $id);  // restore guard — clean post-restore
            delete_transient('wps_ic_heartbeat_'         . $id);  // ML chip animation
        }
        wp_send_json_success(['ok' => true, 'cleaned' => count($restore_ids)]);
    }

    public function wps_ic_remove_cname()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $cname_class = new wps_ic_cname();
        $cname_class->remove();
    }

    public function wps_ic_cname_retry()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
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
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $cname_input = !empty($_POST['cname']) ? $_POST['cname'] : null;

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
            $lazyExcludeList = rtrim($lazyExcludeList, "\n");
            $lazyExcludeList = explode("\n", $lazyExcludeList);
            update_option('wpc-ic-lazy-exclude', $lazyExcludeList);
        } else {
            delete_option('wpc-ic-lazy-exclude');
        }

        if (!empty($delayExcludeList)) {
            $delayExcludeList = rtrim($delayExcludeList, "\n");
            $delayExcludeList = explode("\n", $delayExcludeList);
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

        $count = absint($_POST['count'] ?? 0) . ' of ' . absint($_POST['count'] ?? 0); // (v7.10.04) SECURITY: was raw $_POST into HTML

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

        $lastProgress = $_POST['lastProgress'];
        $bulkStats = get_transient('wps_ic_bulk_stats');
        $compressed_images_queue = get_transient('wps_ic_restore_queue');

        if (empty($bulkStats['images_restored'])) {
            $bulkStats['images_restored'] = 0;
        }

        if ($compressed_images_queue['queue']) {
            $attID = $compressed_images_queue['queue'][0];

            // First Image
            set_transient('wps_ic_restore_' . $attID, ['imageID' => $attID, 'status' => 'restoring'], 300);

            // M1 fix: switch to restoreV4 so bulk restore runs through the cap=1 regen
            // queue, deferred thumbnail regen, bulk-aware deferral, and Tier 3 verification.
            // Legacy restore() at compress.php:5842 predates all of these safety rails and would
            // fire 12× concurrent wp_generate_attachment_metadata under bulk load → OOM on 64M shared.
            self::$local->restoreV4($attID);

            set_transient('wps_ic_restore_' . $attID, ['imageID' => $attID, 'status' => 'restored'], 300);

            unset($compressed_images_queue['queue'][0]);
            $compressed_images_queue['queue'] = array_values($compressed_images_queue['queue']);

            // Sleep so that it takes longer
            sleep(2);

            /**
             * Calculate Progress
             */
            $leftover_images = count($compressed_images_queue['queue']);
            $total_images = $compressed_images_queue['total_images'];
            $done_images = $total_images - $leftover_images;
            $progress_percent = round(($done_images / $total_images) * 100);

            // Bulk Stats
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

    /**
     * Live Compress
     */
    public function wps_ic_restore_live()
    {
        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }
        @set_time_limit(120);

        // FPM telemetry: capture the FPM accept moment for wall + queue-wait.
        $restore_request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $imageID = absint($_POST['attachment_id']);

        // Durable "restoring" signal so a refresh during the restore window shows
        // the is-restoring card, not a stale compressed-state. compress_details reads this
        // transient; restoreV4 → cleanRestoreMeta deletes it on completion.
        set_transient('wps_ic_compress_' . $imageID, [
            'imageID' => $imageID,
            'status'  => 'restoring',
            'time'    => time(),
        ], 120);

        // Try async loopback dispatch. restoreV4 + thumbnail regen
        // takes ~3-5s in the synchronous path (mean=3944ms, max=5397ms in our
        // staging telemetry, where 100% of restores were ≥2s and 25% were ≥5s).
        // Bulk-restore of 4 images = 16 worker-seconds, pool-saturating on
        // shared hosts. Async dispatch frees the click worker in ~300ms;
        // restoreV4 runs in a separate worker. wpcWatchCard (12s cadence)
        // picks up the ic_status transition. Filter `wpc_v2_async_dispatch_enabled`
        // to disable. Falls through to sync on dispatch failure.
        //
        // Skip the compress_details() render in the dispatch return
        // (it was ~1.5-2s, dominating the click wall after async dispatch).
        // The JS click handler already painted the is-restoring skeleton
        // BEFORE the AJAX fired (line 884 area); wpcWatchCard polls every
        // 12s and picks up the real state when regen completes. Click drops
        // from 2.2s to ~300ms (just transient + token + loopback fire).
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

        // DOUBLE-RUN GUARD. dispatch_async_loopback() returned false = "unconfirmed." restoreV4()
        // deletes optimized files + restores from backup + regenerates thumbs; on the LEGACY-BACKUP path it
        // does @copy → @unlink($legacyPath) → unconditional delete_post_meta('ic_backup_images')
        // (compress.php), and restoreV4 has NO re-entrancy guard of its own. Two overlapping restores there =
        // a destructive double-restore (run B unlinks the backup run A still needs, then wipes the backup
        // meta) → a half/degraded-restored image. The one-shot token is authoritative: claim it (present → no
        // worker → safe; absent → a worker owns the restore → stand down, wpcWatchCard polls ic_status to
        // completion). NOTE: a re-entrancy guard is ALSO added at the top of restoreV4 itself (compress.php)
        // as belt-and-suspenders for any other caller that reaches it concurrently.
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

        // Capture purge URLs BEFORE the files are deleted (same post-delete
        // glob-sweeps-empty-floor bug as the async worker; see that path's comment).
        $purge_urls_pre_sync = function_exists('wpc_customer_purge_attachment_urls')
            ? wpc_customer_purge_attachment_urls($imageID) : [];
        self::$local->restoreV4($imageID);

        // Verify restore succeeded
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

        // Customer Purge v1 — sync-fallthrough restore SUCCEEDED (this path only
        // runs when async dispatch failed). Fire-and-forget unified CDN purge so it
        // can't block the click (flag-gated, default off).
        if (function_exists('wpc_purge_compat')) {
            $purge_urls = $purge_urls_pre_sync; // captured pre-delete above
            if (!empty($purge_urls)) {
                // Stays NON-BLOCKING: this is the on-click sync fallthrough (a
                // wp_send_json success response follows), so it must not wait on the orch.
                // Log the dispatch so the attempt is traceable; a fire-and-forget call can't
                // capture the outcome. A Throwable must never 500 the restore response.
                try {
                    wpc_purge_compat('urls', $purge_urls, 'restore_image', '', false);
                    error_log('[WPC Purge] restore_image dispatched (non-blocking, on-click sync path) urls=' . count($purge_urls));
                } catch (\Throwable $e) {
                    error_log('[WPC Purge] restore_image purge error: ' . $e->getMessage());
                }
            }
        }

        // Return updated column HTML directly — no heartbeat needed
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

    /**
     * Surgical purge of variant tracking post_meta WITHOUT restoring
     * disk bytes from backup. For ops/QA to reset variant registry state and
     * force a clean re-compress to repopulate, while preserving the already-
     * optimized disk files.
     *
     * Auth:
     *   - manage_options capability (admin-only)
     *   - WP nonce check via check_ajax_referer
     *
     * Cleared/preserved details: see wpc_purge_variants_for_image() helper
     * documentation in addons/legacy/compress.php.
     */
    public function wpc_purge_variants()
    {
        // Auth: admin-only
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        // Nonce: standard WP AJAX nonce check
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

    /**
     * Lightweight JS-polling endpoint. Returns the current `compress_details`
     * card HTML for one attachment so the JS `wpcWatchCard` poller can refresh stuck
     * cards without depending on the heartbeat transient mechanism. Used as a defense-
     * in-depth fallback for any case where the AJAX response from
     * wps_ic_compress_live / wps_ic_restore_live shows a still-pending state and the
     * heartbeat tick happens to miss the regen-completion transient (60s TTL).
     *
     * Caller responsibilities: pass attachment_id; the poller stops on its own once the
     * returned HTML no longer carries an "is-restoring" or "is-compressing" class.
     *
     * Cost: one get_post_meta + one render of the same compress_details() the heartbeat
     * already calls. ~1ms hot-cached.
     */
    /**
     * Minimal endpoint that returns ONLY the variant count + format
     * breakdown + status. Used by the JS chip-climb poller (200-300 ms cadence)
     * so the chip number increments in near real time without paying for a
     * full compress_details render each tick.
     *
     * Response: { count, jpeg, webp, avif, status }. ~80 bytes total.
     */
    public function wps_ic_variant_count()
    {
        $imageID = absint($_POST['attachment_id'] ?? 0);
        if ($imageID <= 0) {
            wp_send_json_error(['msg' => 'invalid-id']);
        }
        // Force a fresh DB read — this AJAX is invoked from a new FPM request
        // each tick so the cache is normally fresh, but a defensive flush is
        // cheap and makes mid-drain races impossible.
        wp_cache_delete($imageID, 'post_meta');

        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        $count = 0; $jpeg = 0; $webp = 0; $avif = 0;
        // `since` is the highest bg_upgraded timestamp the client has
        // already badged. Any variant newer than that gets returned in `recent`
        // so the client can fire a per-variant badge animation for EACH landing
        // (not just the last one). Without this, when 3 variants land between
        // poll ticks, only the last gets visually flagged and the rest fly by
        // silently while the chip number climbs.
        // The cursor is now millisecond-precision (since_ms). Falls back
        // to legacy `since` if a stale JS still sends seconds. Server reads
        // bg_upgraded_ms preferentially; older entries without the ms field
        // are upgraded inline by multiplying bg_upgraded * 1000.
        $since  = isset($_POST['since']) ? (int) $_POST['since'] : 0;
        $recent = [];

        // Read status FIRST so we can gate the announced-chip
        // emission below on it. Chips for announced-but-not-yet-persisted
        // variants are ONLY emitted when status=compressed (the eager_flip
        // already happened). Without this gate, a concurrent poll could
        // catch the announce-handler mid-flight and return chips while
        // status is still optimizing, firing them on the un-swapped
        // Optimizing card.
        $ic_early = get_post_meta($imageID, 'ic_compressing', true);
        $status_early = (is_array($ic_early) && !empty($ic_early['status']))
            ? (string) $ic_early['status']
            : 'optimizing';

        // Read the announce transient ONCE up front. We use it for
        // two things below:
        //   (a) ts-override for persisted variants — if a variant was
        //       announced before bytes landed, use the EARLIER announced_ms
        //       as the chip's ts so the cursor passes it on the announce
        //       firing, not again when bytes land (no double-chipping).
        //   (b) announced-only variants — entries that have been announced
        //       but whose bytes haven't landed yet still emit a chip so
        //       the user sees the ultra-early hint.
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
                // (a) — if this variant was announced earlier, anchor its
                // chip ts to the announce time so the cursor has already
                // passed it by the time bytes land.
                if (isset($announced[$vkey]['announced_ms'])) {
                    $ts = (int) $announced[$vkey]['announced_ms'];
                }
                if ($ts > $since) {
                    // Derive size label from variant key (e.g. "medium-webp" → "medium").
                    // Strip every known format suffix, not just -avif/-webp.
                    // Legacy/edge writers can produce "-jpg"/"-jpeg"/"-png" suffixes
                    // that previously slipped through and rendered as "Large Jpg".
                    // Chip already shows the format separately via item.fmt; the
                    // size label must be format-free.
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

        // (b) — announced-only chips. Each entry whose variant_key is NOT
        // yet in persisted ic_local_variants gets a chip with ts =
        // announced_ms. When its bytes land later, the persisted entry
        // takes over but uses the same ts (per the override above) so the
        // cursor already passed it → no double-chip.
        //
        // GATE: $status_early must be 'compressed'. If status is still
        // 'optimizing', the card hasn't flipped yet → emitting chips now
        // would fire them on the un-swapped Optimizing card. The very next
        // poll (after eager_flip propagates) will catch them all in one
        // batch.
        if (!empty($announced) && $status_early === 'compressed') {
            foreach ($announced as $vkey => $aentry) {
                if (isset($variants[$vkey])) continue;          // already in persisted loop
                if (!empty($aentry['noImprovement'])) continue;  // no chip for skips
                $ts = isset($aentry['announced_ms']) ? (int) $aentry['announced_ms'] : 0;
                if ($ts <= $since) continue;
                $fmt_lower = isset($aentry['format']) ? (string) $aentry['format'] : '';
                if ($fmt_lower === 'jpg') $fmt_lower = 'jpeg';
                $fmt_up = strtoupper($fmt_lower);
                $size_label = isset($aentry['sizeLabel']) ? (string) $aentry['sizeLabel'] : '';
                // Belt-and-suspenders: strip any format suffix the encoder may
                // have included in sizeLabel (chip shows format separately).
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

        // Stable order: oldest → newest so the client animates landings in arrival order.
        usort($recent, function ($a, $b) { return $a['ts'] - $b['ts']; });
        // Reuse the early status read — meta hasn't changed mid-handler.
        $status = $status_early;

        // Live headline savings %. The card flips to Compressed on the
        // FIRST Phase B landing, so ic_savings at that moment reflects just one
        // variant (e.g. 60% JPEG). As better variants (AVIF Original at 88%)
        // land, ic_savings is recomputed in wpc_v2_merge_variant → here we
        // surface it so the JS can climb the headline % in real time. Without
        // this, the card's headline drifts out of sync with the modal's per-
        // variant table.
        $ic_savings = get_post_meta($imageID, 'ic_savings', true);
        $savings_pct = is_numeric($ic_savings) ? (float) $ic_savings : 0.0;

        // Set by run_v2_optimize() right after Phase A SUCCESS so
        // the client knows the sync round-trip has landed. JS gates win-pips
        // on this so we don't fire badges during the encoder's tight
        // T+2.0 → 3.5s first burst.
        $phase_a_done = (bool) get_transient('wpc_v2_phase_a_done_' . $imageID);

        // Warming sub-state. Set by the announce handler when it does
        // an eager_flip (status=compressed) while no bytes are on disk yet;
        // cleared by /bg_swap or /bg_swap_batch on first persist. JS renders
        // a quiet "Finalizing…" pill while warming=true so the card flip at
        // T0+~1s doesn't read as dishonest.
        $warming = (bool) get_transient('wpc_v2_warming_' . $imageID);

        // phase_b_done requires all three completion signals
        // to filter the eager_flip race. Bulk JS won't advance to the next image
        // until the image is GENUINELY done. Stuck images are handled by
        // wpc_bulk_clear_stuck_compressing at the JS ceiling.
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

        // When status is compressed AND caller wants the HTML for an
        // instant card swap (no second AJAX roundtrip), include the rendered
        // compress_details. JS swaps the card immediately at chip count=1
        // when this lands, instead of waiting for heartbeat's separate fetch.
        if ($status === 'compressed' && !empty($_POST['want_html'])) {
            global $wps_ic;
            if ($wps_ic && $wps_ic->media_library) {
                // Cache flush before render — Phase B callbacks ran in other
                // workers; this worker's local cache may still see stale meta.
                wp_cache_delete('_transient_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete('_transient_timeout_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete($imageID, 'post_meta');
                $payload['html'] = $wps_ic->media_library->compress_details($imageID);
            }
        }

        wp_send_json_success($payload);
    }

    /**
     * Clear stuck ic_compressing meta for an image the bulk loop
     * gave up on. Called from the JS waitForPhaseB ceiling branch with
     * { action: 'wpc_bulk_clear_stuck_compressing', imageID, nonce }.
     *
     * Conservative: only clears if ic_compressing.status is queueing|optimizing
     * AND no variants have landed AND no pending Phase B callbacks are expected.
     * If variants are present (Phase B is just slow), we leave the meta alone —
     * the eager-flip / heartbeat will advance it normally.
     */
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

        // The JS waitForPhaseB ceiling means bulk gave up waiting for
        // genuine completion. This handler is the SAFETY NET that flips the
        // image to compressed so bulk can advance + ML chip swaps. Three
        // sub-cases:
        //
        //   (a) variants landed but pending stuck → force-flip status,
        //       clear stale pending. Image is essentially done; just had
        //       a tracking gap (e.g., 1 missing AVIF per LS observation).
        //   (b) zero variants landed → genuine failure. Clear ic_compressing
        //       entirely so card returns to "Compress" CTA. (existing logic)
        //   (c) Phase B genuinely still in flight (early ceiling fire) →
        //       wait a beat. Existing 'phase-b-pending' return.
        //
        // Old logic refused ANY action when variants>0 OR pending exists,
        // leaving stuck images permanently in 'optimizing' state. ML chip
        // never swapped. Bulk count never advanced.

        // Case (c): genuinely in flight + no variants yet → don't interfere.
        if ($has_pending && $variant_count === 0) {
            wp_send_json_success(['cleared' => false, 'reason' => 'phase-b-pending-no-variants-yet']);
        }

        // Case (a): variants landed → force-flip to compressed regardless
        // of pending state. BEFORE flipping, fire a t0-based retry pull
        // to grab any stragglers (e.g., the LS-observed missing AVIF
        // Thumbnail at +10.1s). Also list which specific variants are
        // missing for visibility.
        if ($variant_count > 0) {
            // Per-variant gap telemetry + t0-based retry pull.
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

            // Fire t0-based retry pull if anything missing. Synchronous
            // because we want it to land before we ack the cleanup.
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
            delete_transient('wpc_v2_pending_' . $imageID);  // stale pending
            delete_transient('wpc_v2_warming_' . $imageID);
            // Set phase_a_done so bulkCompressHeartbeat_v2's
            // is_completed gate passes. Without this, status flips but
            // bulk counter stays 0/N because the gate requires this transient.
            set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
            error_log(sprintf(
                '[WPC BulkCleanup] imageID=%d force_flipped_at_ceiling variants=%d missing=[%s] retry_queued=%d',
                $imageID, $variant_count, implode(', ', $missing_keys), $retry_queued
            ));

            // Background retry for missing variants. Bulk advances
            // immediately on the ack; this loop continues retrying for up to
            // 30s after detach so any stragglers land in ic_local_variants
            // even after the bulk counter has moved on. Detach via
            // fastcgi_finish_request so the client doesn't wait.
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
                    // Re-read variants to see which are still missing.
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
                    // Long-poll. Orch holds the connection open
                    // until a NEW variant lands OR 5s elapses. Picks up
                    // stragglers the instant they're produced rather than
                    // waiting up to 5s between short polls.
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
                    // Long-poll already consumed up to 5s — no extra sleep.
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

        // Case (b): zero variants, no pending → genuine failure. Clear entirely.
        delete_post_meta($imageID, 'ic_compressing');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wpc_v2_warming_' . $imageID);
        error_log(sprintf('[WPC BulkCleanup] imageID=%d ic_compressing cleared (no variants, no pending) after bulk ceiling', $imageID));
        wp_send_json_success(['cleared' => true, 'imageID' => $imageID]);
    }

    public function wps_ic_get_card()
    {
        $imageID = absint($_POST['attachment_id'] ?? 0);
        if ($imageID <= 0) {
            wp_send_json_error(['msg' => 'invalid-id']);
        }
        global $wps_ic;
        if (!$wps_ic || !$wps_ic->media_library) {
            wp_send_json_error(['msg' => 'not-ready']);
        }
        $html = $wps_ic->media_library->compress_details($imageID);

        // Hint flags so the poller knows whether to keep polling without parsing HTML.
        $pending = (
            (strpos($html, 'is-restoring') !== false) ||
            (strpos($html, 'is-compressing') !== false) ||
            !empty(get_transient('wps_ic_compress_' . $imageID)) ||
            !empty(get_post_meta($imageID, '_wpc_pending_thumb_regen', true))
        );

        wp_send_json_success([
            'html'      => $html,
            'pending'   => $pending,
            'imageID'   => $imageID,
            'ic_status' => get_post_meta($imageID, 'ic_status', true),
        ]);
    }

    public function wps_ic_compress_live()
    {
        if (function_exists('webp_uploads_create_sources_property')) {
            wp_send_json_error(['msg' => 'performance-lab-compatibility']);
        }
        @set_time_limit(120); // defensive for shared hosts

        // FPM telemetry: capture the FPM accept moment. compress_live
        // is the dominant FPM hog during bulk (holds worker through full
        // Phase A POST, 3-20s). shutdown function records on any exit path.
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
            // Include the current card HTML so JS can immediately swap
            // to the real state. Without this the card sits at "Queueing…"
            // indefinitely because no heartbeat transient is set for an already-
            // compressed image and the heartbeat poll returns no data for it.
            global $wps_ic;
            $html = (isset($wps_ic) && isset($wps_ic->media_library))
                ? $wps_ic->media_library->compress_details($imageID)
                : '';
            wp_send_json_error(['msg' => 'file-already-compressed', 'html' => $html]);
        }

        // wait_for_regen MOVED to the async handler (wpc_async_phase_a).
        // It was blocking the click for up to 15s if a stale _wpc_pending_thumb_regen
        // marker existed from a previous restore, defeating the async dispatch's
        // FPM relief (compress_live wall observed 12s + async_phase_a 14-20s on a
        // post-restore bulk, so 30s+ per image). With it moved into async, click
        // returns in ~300ms regardless of regen state; async worker waits and
        // proceeds with Phase A. See wpc_async_phase_a handler below.
        //
        // Sync-fallback path still needs the guard, so we run it only if async
        // dispatch isn't going to fire (filter disabled OR loopback unhealthy).
        $async_will_fire = apply_filters('wpc_v2_async_dispatch_enabled', true)
            && function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol()
            && class_exists('WPS_LocalV2');
        if (!$async_will_fire && method_exists(self::$local, 'wait_for_regen_or_clear_stale')) {
            self::$local->wait_for_regen_or_clear_stale($imageID, 15);
        }

        // Pre-empt any pending ladder backfill for this image. The full compress
        // will deliver every variant the ladder would have backfilled, so the ladder fire
        // is redundant. Saves one wasted service hit.
        if (method_exists('wps_local_compress', 'preempt_ladder_for')) {
            wps_local_compress::preempt_ladder_for($imageID);
        }

        // Blocking inline AJAX. Since rc10.3f service-side response is ~2-4s total and
        // Phase B downloads are deferred to async workers, the full AJAX round-trip is ~3-6s,
        // well within browser/proxy timeout budgets. Fire-and-forget + heartbeat-polling is no
        // longer needed for the happy path; heartbeat remains as a safety net if this AJAX times out.
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);

        // backup_all_sizes MOVED to the async handler when dispatch
        // will fire. File-copies ~7 sub-sizes to backup location (~1-2s, IO-bound).
        // It was running in the click handler, dominating the remaining ~2s of
        // click wall after the wait_for_regen move. With it in async, click drops
        // to ~300ms. Edge case: if user clicks Restore between click-return
        // (~300ms) and async-fire (~50ms later), backup won't exist yet — but
        // the JS Restore click handler can't fire that fast (action-pending
        // guard + AJAX round-trip). Sync fallback still does backup inline.
        if (!$async_will_fire) {
            $backupOk = self::$local->backup_all_sizes($imageID);
            if (!$backupOk) {
                wp_send_json_error(['msg' => 'backup-failed']);
            }
        }

        // v2 protocol routing. When wpc_use_v2_protocol() returns true
        // (Day 4 capability probe + canary gate), POST to /optimize-v2 via
        // WPS_LocalV2 instead of v1's singleCompressV4. Phase B drains via the
        // existing /wp-json/wpc/v2/bg_swap REST route. Falls back to v1 path on
        // ANY v2 transport/parse failure, as defense in depth so a v2 outage
        // doesn't break customer-facing compresses on canary-enrolled sites.
        if (function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol() && class_exists('WPS_LocalV2')) {
            // Try async loopback dispatch FIRST. Phase A POST blocks
            // an FPM worker for 9-14s in the synchronous path; on a 2-4 worker
            // shared host, 4 simultaneous clicks saturate the pool and starve
            // heartbeat. Async dispatch frees the click worker in ~300ms; the
            // Phase A POST runs in a separate FPM worker via wp_remote_post
            // loopback. Total worker-seconds same, but smeared over time
            // instead of bursting in a 12s spike. Filterable kill-switch:
            // add_filter('wpc_v2_async_dispatch_enabled', '__return_false').
            if (self::dispatch_async_loopback('wpc_async_phase_a', $imageID)) {
                // Mark as queueing so heartbeat knows work is in flight.
                // Also seed expected_variants from WP metadata so
                // bulk's is_completed gate has a ground-truth count to compare
                // landed variants against. Async worker refines this with the
                // actual Phase A variant list when prep_v2_optimize runs.
                $expected_seed = function_exists('wpc_v2_compute_expected_variants')
                    ? wpc_v2_compute_expected_variants($imageID)
                    : 0;
                update_post_meta($imageID, 'ic_compressing', [
                    'status'            => 'queueing',
                    'expected_variants' => $expected_seed,
                    'time'              => time(),
                ]);
                // Set drain deadline = now+60s, then fire drain
                // worker. Deadline-based design: drain runs until now >
                // deadline. Click extends by 60s; each variant arrival
                // extends by 30s. Simple, race-free, no "is more coming?"
                // guessing.
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
            // Async dispatch unavailable/disabled, so fall through to synchronous
            // Phase A. Same behavior as the pre-async-dispatch versions.
            //
            // DATA-LOSS GUARD (ships ATOMICALLY with the fsockopen transport swap
            // in dispatch_async_loopback). Before the swap, wp_remote_post(blocking=false)
            // never returned false for a loopback, so this sync fallback was effectively
            // UNREACHABLE on a default install (async always "succeeded"). The fsockopen swap
            // RESURRECTS this path on a refused/slow local connect (saturated FPM) — exactly
            // what it exists to detect. But the inline backup above only runs when
            // !$async_will_fire, which is FALSE on every default install (wpc_use_v2_protocol()
            // defaults to 'v2'), so on this path NO backup has been taken — and run_v2_optimize()
            // → process_response() does an IN-PLACE destructive overwrite of the customer's
            // original/scaled JPEG (v2-client.php). Without this guard the pre-optimization
            // original is destroyed and restore is permanently impossible. backup_all_sizes()
            // is a fast no-op when a backup already exists (copies only if !file_exists($dest))
            // and 'off'/'cloud' modes early-return true, so this is safe to double-fire.
            // DOUBLE-RUN GUARD. dispatch_async_loopback() returned false = "unconfirmed": EITHER no
            // worker ran (loopback genuinely failed) OR a worker DID start but we couldn't read its 'queued'
            // confirmation (mod_php can't echo early / slow FPM flush). run_v2_optimize() → process_response()
            // does an IN-PLACE destructive overwrite of the original (v2-client.php), so running it while a
            // worker is ALSO running it would double-optimize. The one-shot token is authoritative: claim it
            // (present → no worker → safe to run; absent → a worker owns it → stand down and let the heartbeat/
            // count-poller track that worker's Phase B drain to completion).
            if (!self::claim_async_token_for_sync('wpc_async_phase_a', $imageID)) {
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
            $v2_result = self::run_v2_optimize($imageID);
            if (!empty($v2_result['ok'])) {
                global $wps_ic;

                // Force-flush this PHP-FPM worker's in-memory caches for
                // ic_compressing post_meta + wps_ic_compress_ transient BEFORE
                // calling compress_details. The blocking Phase A POST ran for
                // ~10-16 s in this same process — during that time, Phase B
                // callbacks in OTHER processes ran eager_flip (deleted the
                // wps_ic_compress_ transient + set ic_compressing.status to
                // compressed) but their delete/update only invalidated their
                // OWN in-memory caches. This worker's cache still has the
                // original values from the start of the request.
                //
                // Without an external object cache (EXT_OBJECT_CACHE=N here),
                // transients live as wp_options rows cached in the 'options'
                // group with key '_transient_<name>'. Invalidate those two
                // option keys + the alloptions cache, and the post_meta cache
                // for ic_compressing.
                wp_cache_delete('_transient_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete('_transient_timeout_wps_ic_compress_' . $imageID, 'options');
                wp_cache_delete($imageID, 'post_meta');

                $html = $wps_ic->media_library->compress_details($imageID);
                // Mark Phase A as done so the count-poller can tell
                // the JS to start firing win-pips. JS gates badges on this
                // flag to avoid spamming pips during the encoder's tight
                // first-burst window (T+2.0 → 3.5s).
                set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
                error_log('[WPC V2Route] imageID=' . $imageID . ' SUCCESS wall_ms=' . ((int) ($v2_result['wall_ms'] ?? 0)) . ' jobId=' . substr((string) ($v2_result['jobId'] ?? ''), 0, 8));
                wp_send_json_success(['html' => $html, 'immediate' => true, 'v2' => true, 'jobId' => $v2_result['jobId'] ?? null]);
            }
            // Another Phase A POST is already in flight for this image
            // (lazy trigger, bulk run, or a second manual click landing during
            // the first's drain window). Don't re-fire and don't fall through
            // to v1 — that would clobber the in-flight run's pending_jobId.
            // Return the current card HTML; the heartbeat poller will track
            // chip events from the in-flight run and swap to Compressed when
            // its Phase B drain completes.
            if (!empty($v2_result['error']) && $v2_result['error'] === 'already_in_flight') {
                global $wps_ic;
                wp_cache_delete($imageID, 'post_meta');
                $html = (isset($wps_ic) && isset($wps_ic->media_library))
                    ? $wps_ic->media_library->compress_details($imageID)
                    : '';
                error_log('[WPC V2Route] imageID=' . $imageID . ' already_in_flight — JS will heartbeat-poll for completion');
                wp_send_json_success(['html' => $html, 'immediate' => false, 'v2' => true, 'in_flight' => true]);
            }
            // v2 failed — log and fall through to v1 for graceful degradation
            error_log(sprintf(
                '[WPC V2Route] imageID=%d FAILED error=%s http_code=%d detail=%s wall_ms=%d — falling through to v1',
                $imageID,
                $v2_result['error']  ?? 'unknown',
                (int) ($v2_result['http_code'] ?? 0),
                substr((string) ($v2_result['detail'] ?? ''), 0, 200),
                (int) ($v2_result['wall_ms'] ?? 0)
            ));

            // If eager_flip already promoted this image to 'compressed'
            // mid-flight (Phase B callbacks won the race vs Phase A POST), the
            // v2 drain is genuinely working — encoder pods are delivering
            // bytes via /wpc/v2/bg_swap independent of the Phase A response.
            // Falling through to v1's singleCompressV4 here would re-set
            // wps_ic_compress_ transient + duplicate work + render the card
            // as "Optimizing" again, regressing the UI the user just saw flip
            // to Compressed. Skip v1 and respond with the current compressed
            // HTML; remaining Phase B callbacks update the chip count via SSE
            // and heartbeat polling.
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

        // Blocking call: singleCompressV4 returns after Phase A completes (service round-trip + metadata write).
        // Phase B (local disk writes) fires asynchronously via wpc_fire_download_worker + WP Cron + admin_init.
        self::$local->singleCompressV4($imageID, 'silent', true, 'single');

        $newStatus = get_post_meta($imageID, 'ic_status', true);
        global $wps_ic;
        $html = $wps_ic->media_library->compress_details($imageID);

        if ($newStatus === 'compressed') {
            wp_send_json_success(['html' => $html, 'immediate' => true]);
        }
        // Transient 5xx from service → singleCompressV4 scheduled retry via wpc_retry_compress cron.
        // UI stays on "is-compressing"; heartbeat burst catches up when retry succeeds.
        if (wp_next_scheduled('wpc_retry_compress', [$imageID])) {
            wp_send_json_success(['html' => $html, 'retry_scheduled' => true]);
        }
        wp_send_json_error(['msg' => 'unable-to-contact-api', 'html' => $html]);
    }

    /**
     * Route a click-driven compress through the v2 `/optimize-v2`
     * endpoint via `WPS_LocalV2`. Builds the variants list from WP attachment
     * metadata + the scaled/original parent pair, fires the blocking POST,
     * returns the parsed result.
     *
     * Returns:
     *   ['ok' => true,  'jobId' => '...', 'wall_ms' => 1234, 'variants_written' => [...]]
     *   ['ok' => false, 'error' => 'config|optimize|...', 'detail' => '...']
     *
     * On 'ok' => false, caller MUST fall through to the v1 path so the customer
     * sees a successful compress even if v2 is misconfigured / unreachable.
     */
    // Public so the lazy-mode handler (wpc_lazy_trigger_v2 in
    // addons/v2/v2-capabilities.php) can reuse the proven v2 envelope-build
    // + POST path (instead of duplicating it OR falling back to v1
    // `singleCompressV4` like the legacy wpc_maybe_trigger_optimize queue
    // worker does). Callers outside the AJAX context get the same
    // [ok, error, ...] return shape; only wps_ic_compress_live wraps it with
    // wp_send_json_* responses. Class name is wps_ic_ajax (extends wps_ic).
    public static function run_v2_optimize($imageID, array $option_overrides = [])
    {
        $imageID = (int) $imageID;

        // Per-image in-flight lock. Prevents concurrent Phase A POSTs
        // for the same imageID from clobbering each other's pending_jobId
        // (which would cause callbacks from the first run to be stale_job-
        // rejected when the second run overwrites the pending transient).
        //
        // Atomic across all PHP-FPM workers via add_option's wp_options UNIQUE
        // KEY constraint on option_name — losing INSERTs return FALSE. Stale-
        // cleanup at 5 min covers the case where a worker crashed mid-Phase-A
        // before the finally{} could release the lock.
        $lock_opt = 'wpc_v2_inflight_' . $imageID;
        if (!add_option($lock_opt, time(), '', 'no')) {
            $existing = (int) get_option($lock_opt, 0);
            if ($existing > 0 && (time() - $existing) > 300) {
                // Stale lock — previous worker crashed. Clear and retry.
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

            // Refine expected_variants with the ACTUAL prepared
            // variants list × actual format count. compress_live seeded an
            // estimate from WP metadata; this overrides with the truth
            // (needed_widths smart-lazy filtering, format setting, etc.).
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

    /**
     * Phase A preparation: validate, build variants, run pre-flight
     * transient clears, return the constructed WPS_LocalV2 client + variants
     * + options ready for dispatch. Extracted from run_v2_optimize so the bulk
     * drain handler can curl_multi K=3 envelopes in parallel without firing
     * the blocking optimize() one at a time.
     *
     * Returns:
     *   ['ok' => true, 'client' => WPS_LocalV2, 'variants' => [...], 'options' => [...], 't0' => microtime(true)]
     *   ['ok' => false, 'error' => '...']
     */
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

        // Plugin's user-facing "Max Dimension" setting (Settings → Image Optimization).
        // Defaults to 2560 to match WP's BIG_IMAGE_SIZE_THRESHOLD. Customer can raise
        // (e.g. 3840 for 4K retina) or lower (e.g. 1920 for tighter compression).
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

        // Build variants from WP-registered sub-sizes + scaled + original.
        // Mark `scaled` as the parent (Phase A returns its jpg+webp inline; rest
        // arrive via /wpc/v2/bg_swap callbacks).
        //
        // Per the v3.0.5 contract, include `filenames` (per-format map) + `originalBytes`
        // per variant. Service echoes these into each callback so the plugin can
        // skip filename derivation and compute accurate ic_savings_baseline.
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

        // Phase 2 smart-lazy: when caller passes a needed_widths list (extracted
        // from the page's srcset by v2-trigger-scanner), filter $meta['sizes']
        // to only those sub-sizes whose width is in the requested set. parents
        // (scaled + original) are ALWAYS included regardless because they
        // anchor the cascade and downstream lazy backfill may need them.
        //
        // Width matching uses a small tolerance window: a srcset entry like
        // "768w" should match a registered size with width=768 OR width=755
        // (some sizes resize-down to fit aspect ratio). ±15 px tolerance keeps
        // the match generous without catching unintended sizes.
        $needed_widths = [];
        if (isset($option_overrides['needed_widths']) && is_array($option_overrides['needed_widths'])) {
            foreach ($option_overrides['needed_widths'] as $w) {
                $w = (int) $w;
                if ($w > 0) $needed_widths[] = $w;
            }
        }
        $size_in_needed = function ($size_width) use ($needed_widths) {
            if (empty($needed_widths)) return true; // legacy: no filter = encode all
            foreach ($needed_widths as $need) {
                if (abs((int) $size_width - $need) <= 15) return true;
            }
            return false;
        };

        // DEMAND-AWARE CEILING: encode only what's actually used.
        // When the unified envelope is on AND the image has demand memory (real page-slot
        // widths from renders), registered SUB-SIZES above max(demand)×1.3 are dropped from
        // the envelope entirely, so a 221px-slot image gets no 1132/1510 next-gen work it can
        // never serve. Parents (scaled/original) always stay (source anchor + og:image /
        // direct-fetch fallback). No demand data yet (fresh upload, never rendered) = full
        // ladder, conservative. The 1.3 headroom covers DPR drift; a new larger placement
        // updates the memory and the next compress extends the ladder automatically.
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
                    continue; // smart-lazy: this width isn't rendered on the page
                }
                if ($iw_ceiling > 0 && $size_w > $iw_ceiling) {
                    $smart_skipped[] = $label . '(' . $size_w . 'w>ceiling' . $iw_ceiling . ')';
                    continue; // demand ceiling: the page never picks above this width
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
        // `original` is now the Phase A parent (not `scaled`). WP's -scaled.jpg
        // is already q≈85 compressed so re-encoding yields 0% savings (or worse,
        // inflates). The un-scaled original is q95-100 so Phase A re-encode delivers
        // 80%+ savings. `scaled` stays in the variants array as a non-parent — encoder
        // either ships an optimized version or signals bumped: source_already_optimal.
        // For parent variants, send maxWidth AND maxHeight (both = plugin_max_width)
        // so the encoder treats the dimensions as a longest-edge bounding box, not
        // a width-only cap. Sub-sizes already have both. Without maxHeight, encoders
        // observed treating maxWidth=2560 literally → portrait images come back at
        // 2560×3472 instead of 1887×2560.
        // Smart-lazy parent trim. When needed_widths is set (smart
        // lazy mode), only include scaled + original if the page actually
        // displays at those large widths. Page that shows images at 768w
        // doesn't need scaled (2560w) or original (2560w+) encoded.
        // Verified safe: modern-delivery.php's build_gapfill_srcset (line 773+)
        // skips widths without disk variants OR falls back to on-demand CDN
        // proxy URLs — never emits a 404. `<img>` fallback uses WP-native URL
        // which always exists. Reduces lazy_smart encode work by ~6 encodes
        // per image (3 formats × 2 dropped parents). On a 20-image page that's
        // 120 fewer wasted encodes.
        // LANE UNIFICATION: the image's
        // DEMAND MEMORY (wpc_ideal_widths, the real page-slot widths recorded at render) rides
        // the compress envelope NATIVELY as extra WxH variants. One source fetch per image
        // (the WAF-proof economics), the proven
        // callback path, immediate meta/chips. Labels are '{W}x{H}', the exact adaptive
        // convention every emitter, the modal, and the intercept already read, and
        // filenames are EXPLICIT so the service derives nothing. Demand entries bypass the
        // smart-trim filter by definition (they ARE the page's demand). Flag-gated until
        // orch confirms label passthrough; flip = one option.
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
                $env_added = 0; // hard cap: ≤6 demand entries per envelope (≤18 encodes at 3 formats)
                $iw_env = is_array($iw_env) ? array_map('intval', $iw_env) : [];
                // LARGEST FIRST under the cap: a 7-width stash once dropped
                // 1240 (the LCP's primary rung, ~17KB/view wasted) while keeping 289, whose
                // need a neighboring rung covers for pennies. Byte impact scales with width;
                // when the cap bites, it must bite the smallest.
                rsort($iw_env, SORT_NUMERIC);
                foreach ($iw_env as $w_env) {
                    if ($env_added >= 6) break;
                    $w_env = (int) $w_env;
                    if ($w_env < 200 || $w_env >= $nat_w_env) continue;          // natural cap
                    $near_env = false;                                            // asymmetric dedupe:
                    foreach ($variants as $ve) {                                  // a rung AT/ABOVE within
                        $vw = isset($ve['maxWidth']) ? (int) $ve['maxWidth'] : 0; // 8% satisfies the need
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
                    // Per-variant format override (orch field confirmed pending):
                    // demand rungs are AVIF-only, since the registered ladder already carries all
                    // three formats for legacy browsers; tripling demand encodes serves the
                    // <5% slice a nearest-rung already covers. B-class: 27 to ~21 variants.
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

        // If smart-lazy dropped 'original' (the canonical parent),
        // promote the LARGEST remaining sub-size variant to `parent: true`.
        // Phase A response carries inline bytes for parent variants only —
        // without one, orch delivers everything via Phase B callbacks (3
        // extra round-trips, ~150-600ms wall). Marking the largest as parent
        // gives us the inline-bytes optimization for the variant the user
        // is most likely to see first (largest = above-the-fold hero image).
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

        // Stamp compress start so the v1 race-guard in wpc_handle_bg_swap_callback
        // accepts callbacks belonging to THIS compress, not stale ones from a
        // previous attempt. Same convention as v1's singleCompressV4.
        update_post_meta($imageID, '_wpc_compress_started_at', time());

        // Clear the pending transient from any previous compress BEFORE
        // Phase A starts. The new jobId is only known after the orchestrator's
        // Phase A response — but encoder pods start firing Phase B callbacks
        // during the Phase A POST window. The stale-job guard in v2-callback.php
        // compares each callback's jobId against this transient's jobId; if the
        // transient still holds the OLD job's jobId, all NEW-job callbacks land
        // mid-Phase-A get rejected as stale (cb=new, pending=old → 410). By
        // clearing here, the guard runs in permissive mode (no pending_jobId)
        // during the Phase A window; once Phase A returns, record_pending_variants
        // writes the new transient and strict mode resumes.
        delete_transient('wpc_v2_pending_' . $imageID);

        // Clear the restore-blocked marker if present. If the user
        // restored then immediately clicked Compress, the callback-block flag
        // set in compress.php restore handler would otherwise reject this new
        // compress's Phase B callbacks too. Compress click is a "let new
        // variants land" signal.
        delete_transient('wpc_v2_callbacks_blocked_' . $imageID);

        // Reset ic_compressing.status to 'optimizing' for the fresh
        // compress. Without this, the previous run's 'compressed' status
        // carries over and our eager-mode check (`current_status !== 'compressed'`)
        // never trips: eager_flip is silently a no-op because status is
        // already compressed before the first Phase B callback arrives.
        //
        // Seed expected_variants from the ACTUAL prepared variants
        // list × format count. This is the single canonical dispatch path,
        // covering compress_live (single-click), wpc_bulk_v2_drain (bulk batch),
        // and any other entry. Bulk's is_completed gate reads this to know
        // when each image's full set has landed.
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
        // Persist T0 (click time, ms) so Phase B callbacks can compute
        // and log their elapsed time from the original click.
        // TTL bumped 180s to 1800s. Pull-architecture variants can land
        // 10-60s after Phase A returns (manifest fetch + drain pull), and we
        // want the modal's T+ column to populate for those too. Worst-case
        // drain is bounded by 7d manifest TTL on orch side; 30 min plugin-side
        // TTL covers all realistic pull-arrival windows. Expired transient is
        // still benign — modal shows "—" for that variant, no functional impact.
        set_transient('wpc_v2_t0_ms_' . $imageID, (int) round($t0 * 1000), 1800);
        // Clear previous run's Phase A marker so the JS doesn't see a stale
        // "done" flag and start badging Phase B callbacks before this new
        // Phase A has actually returned.
        delete_transient('wpc_v2_phase_a_done_' . $imageID);
        // Reset bg retry attempt counter + one-shot lock so a
        // fresh compress gets the full 3 × 30s retry budget (otherwise a
        // re-compress of an image that already burned 3 attempts last run
        // would skip retries entirely).
        delete_transient('wpc_v2_bg_retry_count_' . $imageID);
        delete_transient('wpc_v2_bg_retry_fired_' . $imageID);

        // FORMAT INTELLIGENCE: envelope formats derive from
        // DELIVERY NEED > USER INTENT instead of the historic all-3 hardcode. Need = the
        // Next-Gen effective ceiling (what delivery actually serves); intent = the Local
        // card toggles (generate_webp / picture_avif). This closes the observed
        // incoherence (toggles OFF yet webp+avif generated). Next-Gen-off + toggles-off
        // sites now get JPEG-ONLY envelopes, and their demand-width entries inherit it,
        // producing the sized .jpg rungs that jpeg-mode delivery actually serves. jpeg
        // always (the core product). Flag-gated for the cutover; avif-ceiling sites are
        // byte-identical with the flag on (need dominates), so no pilot regression.
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
        ], $option_overrides);

        return [
            'ok'       => true,
            'client'   => $client,
            'variants' => $variants,
            'options'  => $dispatch_options,
            't0'       => $t0,
        ];
    }

    /**
     * Compute actual dimensions string ("WxH") for a variant key,
     * for the Dimensions column in the Optimization Results modal. Resolves:
     *   - wpc_1920 / 1920w     → "1920×2620" (width × proportional height from source aspect)
     *   - 1536x1536            → metadata['sizes']['1536x1536']['width' & 'height']
     *   - medium / large / etc → metadata['sizes'][$base]['width' & 'height']
     *   - scaled               → metadata['width']/['height'] (scaled dims)
     *   - original             → source dims CONSTRAINED to Max Image Size (the encoded master's real dims, not the pristine source)
     *   - thumb (variant alias) → metadata['sizes']['thumbnail'] if present, else thumb
     *   - unknown              → "" (empty; caller renders blank)
     *
     * Defensive: never fatal. Returns empty string on any lookup failure.
     */
    private static function compute_variant_dimensions_string($key, $imageID, $cached_meta = null)
    {
        $key = (string) $key;
        $imageID = (int) $imageID;
        if ($imageID <= 0) return '';

        // Strip format suffix
        $base = preg_replace('/-(avif|webp|png|jpe?g)$/i', '', $key);

        // Cache attachment metadata across calls in the same render pass
        if ($cached_meta === null) {
            $cached_meta = wp_get_attachment_metadata($imageID);
        }
        if (!is_array($cached_meta)) $cached_meta = [];

        // Plugin ladder widths: width is in the key, compute proportional height
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

        // WP-named sub-sizes (medium, large, medium_large, thumbnail, 1536x1536, 2048x2048)
        if (!empty($cached_meta['sizes'][$base]['width']) && !empty($cached_meta['sizes'][$base]['height'])) {
            return (int) $cached_meta['sizes'][$base]['width'] . '×' . (int) $cached_meta['sizes'][$base]['height'];
        }

        // (v7.03.58) Service-delivered AVIF responsive-ladder widths key on the literal "W x H"
        // (e.g. "800x1085") — not a WP-named size, so the lookup above misses. Parse the dims straight
        // from the key. AFTER the named-size check so 1536x1536 / 2048x2048 keep their metadata dims.
        if (preg_match('/^(\d+)x(\d+)$/i', $base, $m)) {
            return (int) $m[1] . '×' . (int) $m[2];
        }

        // "original" / "unscaled" → the encoded master's dims, NOT the pristine source.
        // (v7.03.99) The source-build resizes any master that exceeds Max Image Size (or the
        // byte/MP caps) down to maxWidth before sending, and the orchestrator returns nothing
        // larger — so a 5979×3986 upload comes back as a 2560×1707 master, byte-identical to
        // 'scaled'. The modal previously read the pristine unscaled file via getimagesize() and
        // printed 5979×3986 against a 2560 encode. Fix: read source dims, then CONSTRAIN to Max
        // Image Size using WP's own constrain math (exact parity with the scaled main).
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
            // Manual constrain fallback (longest edge → maxWidth, aspect preserved).
            $longest = max($sw, $sh);
            if ($longest > $maxw) {
                $scale = $maxw / $longest;
                $sw = (int) round($sw * $scale);
                $sh = (int) round($sh * $scale);
            }
            return $sw . '×' . $sh;
        }

        // "scaled" → top-level metadata dims (the scaled-down dims, post big_image_size_threshold)
        if ($base_lower === 'scaled') {
            if (!empty($cached_meta['width']) && !empty($cached_meta['height'])) {
                return (int) $cached_meta['width'] . '×' . (int) $cached_meta['height'];
            }
            return '';
        }

        // "thumb" → fallback to "thumbnail" sub-size if it exists (legacy alias)
        if ($base_lower === 'thumb' && !empty($cached_meta['sizes']['thumbnail']['width'])) {
            return (int) $cached_meta['sizes']['thumbnail']['width'] . '×' . (int) $cached_meta['sizes']['thumbnail']['height'];
        }

        // Unknown — empty
        return '';
    }

    /**
     * Helper for compute_variant_dimensions_string: derive proportional height for
     * a target ladder width based on source aspect ratio. Tries unscaled-original
     * first (most accurate), falls back to metadata top-level dims (still preserves
     * aspect ratio since WP scaling is proportional). Returns 0 if unresolvable.
     */
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

    /**
     * Resolve a variant key to a brand-free display label for the
     * Optimization Results modal.
     *
     * Strategy: rename ONLY the brand-prefixed ladder widths. WP-named sizes
     * (medium, large, scaled, original, thumbnail, etc.) keep their names so
     * customers see what they're used to. Plugin-internal "wpc_N" keys become
     * "Nw" (standard srcset descriptor vocabulary).
     *
     *   wpc_1920          → "1920w"
     *   wpc_480-avif      → "480w"   (format suffix stripped, shown via badge)
     *   1920w             → "1920w"  (the newer descriptor form, no change)
     *   1920w-avif        → "1920w"
     *   medium            → "Medium"
     *   medium-avif       → "Medium"
     *   medium_large      → "Medium large"
     *   1536x1536         → "1536x1536"  (kept; WP's own naming convention)
     *   scaled            → "Scaled"
     *   original          → "Original"
     *   thumbnail         → "Thumbnail"
     *   thumb             → "Thumb"
     *
     * Format suffix (-avif/-webp/-png/-jpg) is always stripped — format is
     * already shown via the colored badge to the left of the variant name.
     */
    private static function format_variant_dimensions($key, $imageID)
    {
        $key = (string) $key;

        // Strip format suffix — format already shown in the badge
        $base = preg_replace('/-(avif|webp|png|jpe?g)$/i', '', $key);

        // Plugin-internal ladder widths: rename to srcset descriptor form
        if (preg_match('/^wpc_(\d+)$/i', $base, $m)) {
            return $m[1] . 'w';
        }

        // Already in srcset-descriptor form, pass through
        if (preg_match('/^(\d+)w$/i', $base)) {
            return $base;
        }

        // WP's '1536x1536' / '2048x2048' sub-size names are
        // misleading: they're max-dimension caps, not literal square outputs.
        // For portrait/landscape images the actual file is e.g. 1510×2048 or
        // 2048×1366 (proportionally fitted inside the square box). Rename to
        // a non-misleading max-dim form so the variant column doesn't claim
        // a square that doesn't exist. The "Dimensions" column still shows
        // the true output dimensions.
        // Show the ACTUAL output dimensions (e.g. '1132x1536'), matching the
        // adaptive rows' naming, instead of the 'Max NNNN' cap form. Falls
        // back to the cap form if the registered size is missing from metadata.
        if ($base === '1536x1536' || $base === '2048x2048') {
            $meta_fd = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $imageID) : false;
            if (is_array($meta_fd) && !empty($meta_fd['sizes'][$base]['width']) && !empty($meta_fd['sizes'][$base]['height'])) {
                return (int) $meta_fd['sizes'][$base]['width'] . 'x' . (int) $meta_fd['sizes'][$base]['height'];
            }
            return $base === '1536x1536' ? 'Max 1536' : 'Max 2048';
        }

        // Everything else: humanize the WP-native key
        // ('medium_large' becomes 'Medium large'; 'scaled' becomes 'Scaled'.)
        return ucfirst(str_replace(['_', '-'], ' ', $base));
    }

    /**
     * Get per-image optimization stats for the modal.
     */
    public function wps_ic_image_stats()
    {
        // Nonce check relaxed. Handler is admin-only via add_ajax
        // (no nopriv), so logged-in admin auth is enforced by WP core. The
        // nonce was defense-in-depth that started breaking after long admin
        // sessions (>12h nonce_tick rollover with the wpc_ajaxVar.nonce
        // localized at page-load — page lives longer than the nonce). For
        // a read-only stats endpoint, current_user_can('upload_files') is
        // the right gate; CSRF on a read endpoint is low-risk.
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized');
        }

        $imageID = absint($_POST['attachment_id'] ?? 0);
        if (!$imageID) wp_send_json_error();

        $variants   = get_post_meta($imageID, 'ic_local_variants', true);

        if (empty($variants) || !is_array($variants)) {
            wp_send_json_error(['msg' => 'No variant data available']);
        }

        // Transient cache for the rendered stats HTML. Keyed by the
        // variants hash so any callback that updates ic_local_variants (size,
        // savings, new variant landed) auto-invalidates the cache. After all
        // Phase B callbacks settle, the hash is stable, so subsequent modal
        // opens hit the cache and return in <50ms instead of doing 24×
        // canonical_original_size disk lookups (the bottleneck).
        // Cache key: hash only the FIELDS THAT AFFECT DISPLAY (size,
        // savings, originalSize per variant), NOT timestamps like
        // bg_upgraded_ms which update on every callback without changing
        // visible content. This keeps the cache hot across the entire Phase B
        // drain window (was invalidating on every callback before, causing
        // every Details click to do a cold render).
        $display_signature = '';
        foreach ($variants as $vkey => $vdata) {
            $display_signature .= $vkey . ':'
                . (int) ($vdata['size'] ?? 0) . ':'
                . (int) ($vdata['originalSize'] ?? 0) . ':'
                . (int) ($vdata['savings'] ?? 0) . ':'
                . (!empty($vdata['bg_no_improvement']) ? '1' : '0') . ';';
        }
        $variants_hash = md5($display_signature);
        // Bump v12 to v13 to evict caches that pre-date the
        // format_variant_dimensions fix at line 6481+ (which maps WP's
        // misleading '2048x2048' / '1536x1536' sub-size names to 'Max 2048'
        // / 'Max 1536'). The variants_hash alone doesn't change on plugin
        // code updates — only on per-variant data changes — so stale labels
        // would otherwise persist across upgrades until the variant data
        // happens to change. Bump = forced one-time re-render per image.
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

        // Build quality grade from AI data
        $quality_grade = '';
        if (!empty($ic_ai['ssim'])) {
            $ssim = floatval($ic_ai['ssim']);
            if ($ssim >= 0.999) $quality_grade = 'A+';
            elseif ($ssim >= 0.997) $quality_grade = 'A';
            elseif ($ssim >= 0.995) $quality_grade = 'A-';
            elseif ($ssim >= 0.99) $quality_grade = 'B+';
            else $quality_grade = 'B';
        }

        // Brand icon
        $brand_svg = class_exists('whtlbl_whitelabel_plugin')
            ? '<svg width="28" height="28" viewBox="0 0 640 512" fill="currentColor"><path d="M528-16l-32 0 0 64-64 0 0 32 64 0 0 64 32 0 0-64 64 0 0-32-64 0 0-64zM288 320c80.6-35.8 128.6-57.2 144-64-15.4-6.8-63.4-28.2-144-64-35.8-80.6-57.2-128.6-64-144-6.8 15.4-28.2 63.4-64 144-80.6 35.8-128.6 57.2-144 64 15.4 6.8 63.4 28.2 144 64 35.8 80.6 57.2 128.6 64 144 6.8-15.4 28.2-63.4 64-144zm-64 65.2l-34.8-78.2-5-11.2-11.2-5-78.2-34.8 78.2-34.8 11.2-5 5-11.2 34.8-78.2 34.8 78.2 5 11.2 11.2 5 78.2 34.8-78.2 34.8-11.2 5-5 11.2-34.8 78.2zM496 384l0-16-32 0 0 64-64 0 0 32 64 0 0 64 32 0 0-64 64 0 0-32-64 0 0-48z"/></svg>'
            : '<svg width="28" height="28" viewBox="0 0 512 512" fill="currentColor"><path d="M322.4 192C358.9 59.4 379.4-15.3 384-32L340.9 3.9 38.4 256 0 288 198.4 288 189.6 320c-36.5 132.6-57 207.3-61.6 224l43.1-35.9 302.5-252.1 38.4-32-198.4 0 8.8-32zm101.2 64L185.9 454.1c34.3-124.6 52.4-190.6 54.5-198.1l-152 0 237.7-198.1C291.8 182.5 273.7 248.5 271.6 256l152 0z"/></svg>';

        // Pre-compute canonical originalSize lookup context. Stored originalSize
        // in ic_local_variants drifts because the plugin overwrites WP-disk JPEG sub-sizes
        // during compression — the next compress's variant entry records the post-overwrite
        // size, not the WP-regen baseline. Read from WP metadata (refreshed by
        // wpc_regen_thumbs_hook → wp_generate_attachment_metadata) and the WPC backup dir
        // (pristine pre-compress state) for stable values that don't shift between modal
        // opens. Computed once per modal render (not per variant) for efficiency.
        $wp_meta      = wp_get_attachment_metadata($imageID);
        $wp_orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        $backup_dir   = WP_CONTENT_DIR . '/wpc-backups/';
        $attached_rel = is_array($wp_meta) && !empty($wp_meta['file']) ? $wp_meta['file'] : '';

        // Delegate to the shared canonical helper so card and modal use
        // the SAME 4-tier lookup chain (this was duplicated logic before the helper existed).
        // The helper does all 4 tiers (backup file → WP meta → stored originalSize
        // → sibling-derive); the local Tier 3 + Tier 4 fallback below becomes dead
        // code (helper already covered them) but is left in place for safety.
        $canonical_orig = function ($base) use ($imageID, $wp_meta, $variants) {
            if (class_exists('WPC_Modern_Delivery')
                && method_exists('WPC_Modern_Delivery', 'canonical_original_size')) {
                return WPC_Modern_Delivery::canonical_original_size($imageID, $base, $wp_meta, $variants);
            }
            return 0;
        };

        // (v7.03.58) Full original-image size — the "before" we show for AVIF responsive-ladder widths that
        // carry NO per-variant original (service-delivered, originalSize=0, no same-width sibling). Resolve
        // via the same canonical helper (pre-compression, consistent with the Original column), then fall
        // back to the on-disk original file. Used only as the last-tier fallback in the loop below.
        $full_orig = 0;
        foreach (['scaled', 'full', 'original'] as $fb) { // (v7.03.59) prefer SCALED: the full original inflates the % savings
            $full_orig = (int) $canonical_orig($fb);
            if ($full_orig > 0) break;
        }
        if ($full_orig <= 0 && function_exists('get_attached_file')) { // the -scaled main file WP serves = fair "before"
            $af = get_attached_file($imageID);
            if (is_string($af) && $af !== '' && @file_exists($af)) $full_orig = (int) @filesize($af);
        }
        if ($full_orig <= 0 && function_exists('wp_get_original_image_path')) { // true pre-scale original = last resort
            $op = wp_get_original_image_path($imageID);
            if (is_string($op) && $op !== '' && @file_exists($op)) $full_orig = (int) @filesize($op);
        }

        // Separate optimized from skipped, sort optimized by savings desc
        $optimized_rows = [];
        $skipped_rows = [];
        foreach ($variants as $label => $data) {
            $opt        = intval($data['size'] ?? 0);
            $is_skipped = !empty($data['skipped']);

            // Canonical originalSize lookup. Strip -avif/-webp/-png suffix to get
            // the base size name, then resolve via WP-meta + WPC-backup chain. Each tier in
            // $canonical_orig is ordered by authority (backup dir > WP meta > nothing).
            $base = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $label);
            $orig = $canonical_orig($base);

            // Tier 3: stored ic_local_variants originalSize. May be stale (drifts across
            // compress cycles per the service-team diagnostic), but it's what we have if WP
            // metadata + WPC backup both miss (e.g., custom theme size, cloud-only backup
            // mode with no local copy).
            if ($orig <= 0) {
                $orig = intval($data['originalSize'] ?? 0);
            }

            // Tier 4: sibling-fallback for bg-swap-delivered entries that have
            // originalSize=0 AND aren't in WP metadata. Service's bg-swap callback POST body
            // sends bytes/bgKb/etc but NOT originalSize, so the bg-swap handler stores
            // originalSize=0 for new entries. Without this fallback, ALL bg-swap-only AVIF
            // entries that aren't WP-registered sizes (rare — usually custom theme sizes)
            // get filtered out by the defensive guard below. Mirrors the same pattern used
            // in the live-update savings recompute at compress.php:~1241.
            if ($orig <= 0 && $opt > 0) {
                foreach ($variants as $sib_key => $sib_data) {
                    $sib_base = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $sib_key);
                    if ($sib_base === $base && (int) ($sib_data['originalSize'] ?? 0) > 0) {
                        $orig = (int) $sib_data['originalSize'];
                        break;
                    }
                }
            }

            // Tier 5 (v7.03.58): still no per-variant original (AVIF ladder width) → show the FULL original
            // image size as the "before" instead of "—" (user request). Flagged so the degenerate ≥99.9%
            // guard below doesn't drop a legitimately-tiny ladder width measured against the big original.
            $orig_is_full = false;
            if ($orig <= 0 && $full_orig > 0) { $orig = $full_orig; $orig_is_full = true; }

            // Always recompute savings from current orig/opt at render time. The stored
            // `savings` field drifts when the variant entry is partially rewritten (e.g. service
            // returns updated `size` but service-side image_sizes row has stale originalSize from
            // an older compression era; or bg-swap callback updates `size` without touching
            // `savings`). Rendering live math from the same orig/opt values shown in the table
            // guarantees the % column matches the visible numbers — never displays a stale
            // percentage that contradicts the row's own size columns.
            $pct = ($orig > 0 && $opt > 0 && $opt < $orig)
                ? round((1 - $opt / $orig) * 100, 2)
                : 0;

            // (v7.03.56) Show EVERY generated variant as a row so the table matches the chip count — incl.
            // the AVIF-only responsive-ladder widths that carry no recorded original (service-delivered,
            // originalSize=0, no same-width JPEG/WebP sibling). Drop only a truly-broken row (no optimized
            // output); the ≥99.9% degenerate filter applies only when there IS an original. A row with
            // orig<=0 renders "—" for Original/Savings (the optimized "after" still shows). The brief
            // mid-write bg-swap race the old guard covered self-settles on the next modal refresh.
            if ($opt <= 0) continue;
            if ($orig > 0 && !$orig_is_full && $pct >= 99.9) continue;

            $fmt_class = 'wpc-fmt-jpeg';
            $fmt_label = 'JPEG';
            if (strpos($label, 'webp') !== false) { $fmt_class = 'wpc-fmt-webp'; $fmt_label = 'WebP'; }
            elseif (strpos($label, 'avif') !== false) { $fmt_class = 'wpc-fmt-avif'; $fmt_label = 'AVIF'; }
            elseif (strpos($label, 'png') !== false) { $fmt_class = 'wpc-fmt-png'; $fmt_label = 'PNG'; }

            // Brand-free labels for plugin-internal ladder widths.
            // "Wpc 1920" → "1920w" (srcset descriptor form). WP-native names
            // (Medium, Large, Scaled, Original, etc.) keep their names so
            // customers see what they're used to.
            $display_label = self::format_variant_dimensions($label, $imageID);

            // Actual W×H for the new Dimensions column. Pass already-cached
            // attachment metadata (read by canonical_orig closure above) so we don't
            // re-query post_meta per row. Empty string when unresolvable; renders blank.
            $dimensions_str = self::compute_variant_dimensions_string($label, $imageID, $wp_meta);

            // Per-variant T+ since T0 click. Read from the stored
            // bg_t_from_click_ms field (Phase A & Phase B both write it).
            // Older variants compressed before this field existed render '—'.
            $t_ms = isset($data['bg_t_from_click_ms']) ? (int) $data['bg_t_from_click_ms'] : 0;

            $row = ['orig' => $orig, 'opt' => $opt, 'pct' => $pct, 'fmt_class' => $fmt_class, 'fmt_label' => $fmt_label, 'display_label' => $display_label, 'dimensions' => $dimensions_str, 't_ms' => $t_ms];
            if ($is_skipped) { $skipped_rows[] = $row; } else { $optimized_rows[] = $row; }
        }
        usort($optimized_rows, function($a, $b) { return $b['pct'] <=> $a['pct']; });

        // Build HTML
        // Scoped popup styles. Adds breathable top/bottom padding,
        // caps height at 85vh with internal scroll, and styles the scrollbar
        // minimally (6 px thumb, near-transparent track). Selectors target
        // the swal2 popup wrapper (.wpc-stats-swal) AND the inner content so
        // scroll happens inside the popup, not on the body.
        $html  = '<style>';
        // Outer popup: padding + push-down from WP admin toolbar.
        // High-specificity (body + compound) to beat SweetAlert2 defaults.
        // animation:none — the global wpcModalFadeIn keyframe applies
        //   transform: translateY(...) to .swal2-popup, and transform on an
        //   ancestor BREAKS position:sticky on descendants (CSS containing-block
        //   rule). Removing the entrance animation for THIS popup is the cost
        //   of getting sticky thead to work. SwAL2's default backdrop fade is
        //   still visible.
        $html .= 'body .swal2-popup.wpc-stats-swal,body .wpc-stats-swal.swal2-popup{';
        $html .= 'padding:30px 28px 24px !important;border-radius:14px !important;';
        $html .= 'max-height:calc(100vh - 80px) !important;margin:40px auto !important;max-width:calc(100% - 32px) !important;';
        $html .= 'overflow:hidden !important;animation:none !important;transform:none !important;}';
        // (v7.03.58) RESPECT the WP admin sidebar: offset the whole swal CONTAINER to start at the menu's
        // right edge (left=menu width) so the popup lives ENTIRELY in the content area — never under the
        // fixed admin menu (z-index:9990, far above swal). The popup also caps to max-width:calc(100% - 32px)
        // so it fits the content area on smaller screens. Menu widths: 160 normal, 36 folded/auto-fold,
        // 0 off-canvas (<783px). :has() is progressive — older browsers center as before (no worse).
        $html .= '.swal2-container:has(.wpc-stats-swal){left:160px !important;right:0 !important;width:auto !important;}';
        $html .= 'body.folded .swal2-container:has(.wpc-stats-swal),body.auto-fold .swal2-container:has(.wpc-stats-swal){left:36px !important;}';
        $html .= '@media screen and (max-width:782px){.swal2-container:has(.wpc-stats-swal){left:0 !important;}}';
        // Inner content: scroll. Both possible class names (post-v9 = html-container, pre-v9 = content).
        $html .= 'body .wpc-stats-swal .swal2-html-container,body .wpc-stats-swal .swal2-content{';
        $html .= 'max-height:calc(85vh - 120px) !important;overflow-y:auto !important;';
        // (v7.03.57) Push the scrollbar OUT to the popup's right-padding gap (≈10px from the edge) so it
        // clears the close ✕ at right:16px instead of running through it. margin-right:-18px extends the
        // scroll box into the popup's 28px right padding; padding-right:18px keeps the content inset where
        // it was (net content position unchanged) — only the scrollbar moves right, clear of the ✕.
        $html .= 'margin:0 -18px 0 0 !important;padding:0 18px 0 0 !important;text-align:left !important;}';
        // Minimal scrollbar
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
        // Close X
        $html .= '.wpc-stats-swal .swal2-close{top:12px !important;right:16px !important;font-size:24px !important;}';
        // Inner modal layout
        $html .= '.wpc-stats-modal{padding:0;}';
        // Modal header scrolls normally. Only the table thead is sticky.
        $html .= '.wpc-stats-modal-header{padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid #eef1f5;}';
        // border-collapse:separate is REQUIRED for sticky-cells-in-tables to
        // work in Chrome/Safari. The browser default is `separate` already but
        // some WP admin CSS resets force `collapse` — set it explicitly with
        // !important to be safe.
        $html .= '.wpc-stats-swal .wpc-stats-grid{border-collapse:separate !important;border-spacing:0 !important;}';
        // Background on TH (not thead) — required on cells for table-sticky.
        // Box-shadow gives a clean 1px line under the pinned thead.
        // Extra-high specificity (body + .wpc-stats-swal compound) to beat any
        // global table-cell rules.
        $html .= 'body .wpc-stats-swal .wpc-stats-grid thead th,body .wpc-stats-swal .wpc-stats-grid thead tr th{';
        $html .= 'position:sticky !important;top:0 !important;background:#fff !important;z-index:4 !important;';
        $html .= 'box-shadow:0 1px 0 #eef1f5 !important;}';
        // (v7.03.58) Hide the T+ (arrival-time) column — testing aid, not for customers.
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
        // Dimensions column (testing aid). Hide later via CSS:
        //   .wpc-th-dimensions, .wpc-td-dimensions { display: none !important; }
        $html .= '<th class="wpc-th-dimensions">' . esc_html__('Dimensions', 'wp-compress-image-optimizer') . '</th>';
        // Arrival time since click. Testing aid. Hide via CSS:
        //   .wpc-th-tplus, .wpc-td-tplus { display: none !important; }
        // (v7.03.107) Inline display:none — the <style> hide (above) is stripped by
        // SweetAlert2's HTML sanitizer, so the column showed despite the CSS rule.
        // Inline styles survive sanitization, so this actually hides it. Testing aid only.
        $html .= '<th class="wpc-th-tplus" style="display:none">T+</th>';
        $html .= '<th class="wpc-th-orig">' . esc_html__('Original', 'wp-compress-image-optimizer') . '</th>';
        $html .= '<th class="wpc-th-opt">' . esc_html__('Optimized', 'wp-compress-image-optimizer') . '</th>';
        $html .= '<th class="wpc-th-savings wpc-th-active-sort">' . esc_html__('Savings', 'wp-compress-image-optimizer') . ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></th>';
        $html .= '</tr></thead><tbody>';

        // Format helper for T+ column. ms → "2.07s" / "12.45s" / "—" when 0.
        $fmt_tplus = function ($ms) {
            if (!$ms || $ms <= 0) return '—';
            return number_format($ms / 1000, 2) . 's';
        };

        // Optimized rows
        foreach ($optimized_rows as $r) {
            $html .= '<tr class="wpc-row-enter">';
            $html .= '<td class="wpc-td-variant"><div class="wpc-cell-variant"><span class="wpc-format-badge ' . esc_attr($r['fmt_class']) . '">' . esc_html($r['fmt_label']) . '</span><span class="wpc-variant-name">' . esc_html($r['display_label']) . '</span></div></td>';
            $html .= '<td class="wpc-td-dimensions wpc-size-muted">' . esc_html($r['dimensions']) . '</td>';
            $html .= '<td class="wpc-td-tplus wpc-size-muted" style="display:none">' . esc_html($fmt_tplus($r['t_ms'])) . '</td>';
            $html .= '<td class="wpc-size-muted">' . ($r['orig'] > 0 ? esc_html(wps_ic_format_bytes($r['orig'])) : '&mdash;') . '</td>';
            $html .= '<td class="wpc-size-opt">' . esc_html(wps_ic_format_bytes($r['opt'])) . '</td>';
            // orig<=0 (responsive-ladder width with no recorded baseline) → "—" savings, no bar.
            if ($r['orig'] > 0) {
                $html .= '<td class="wpc-td-savings"><div class="wpc-cell-savings"><span class="wpc-savings-pct">' . esc_html(number_format($r['pct'], 1)) . '%</span><div class="wpc-bar-track"><div class="wpc-bar-fill" data-target="' . $r['pct'] . '"></div></div></div></td>';
            } else {
                $html .= '<td class="wpc-td-savings"><div class="wpc-cell-savings"><span class="wpc-savings-pct wpc-size-muted">&mdash;</span></div></td>';
            }
            $html .= '</tr>';
        }

        // Skipped accordion toggle
        if (!empty($skipped_rows)) {
            $html .= '<tr class="wpc-skipped-toggle-row"><td colspan="6">';
            $html .= '<button class="wpc-skipped-toggle-btn" onclick="(function(b){var r=document.querySelectorAll(\'.wpc-skipped-row\'),s=b.querySelector(\'span\'),v=b.classList.toggle(\'is-active\');r.forEach(function(el,i){if(v){setTimeout(function(){el.classList.add(\'is-visible\')},i*30)}else{el.classList.remove(\'is-visible\')}});s.textContent=v?\'Hide skipped variants\':\'Show ' . count($skipped_rows) . ' skipped variants\'})(this)">';
            $html .= '<span>' . sprintf(esc_html__('Show %d skipped variants', 'wp-compress-image-optimizer'), count($skipped_rows)) . '</span>';
            $html .= '<svg class="wpc-skipped-toggle-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
            $html .= '</button></td></tr>';

            // Skipped rows (hidden by default)
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

        // Cache rendered HTML for the duration of the variants hash.
        // 5-min TTL is generous; the hash key invalidates automatically the
        // moment any callback updates ic_local_variants. Subsequent modal
        // opens hit cache and return in <50ms. Telemetry log: surfaces the
        // cold-render duration so we know if any image hits 1s+ render times.
        if (isset($variants_hash, $cache_key) && isset($_render_start)) {
            $_render_ms = (int) round((microtime(true) - $_render_start) * 1000);
            set_transient($cache_key, $html, 300);
            // Log EVERY cold render so we can see real-world timings.
            // Cache hits don't log; only the first build of a unique variants
            // signature surfaces here. If this fires >5 times for the same
            // imageID in quick succession, the cache key isn't stable enough.
            error_log('[WPC StatsModal] imageID=' . $imageID . ' cold_render_ms=' . $_render_ms . ' variants=' . count($variants) . ' html_kb=' . round(strlen($html) / 1024));
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Count Uncompressed Images
     */
    public function wps_ic_count_uncompressed_images()
    {
        global $wpdb;

        $args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_query' => ['relation' => 'AND', ['key' => 'wps_ic_data', 'compare' => 'NOT EXISTS'], ['key' => 'wps_ic_exclude', 'compare' => 'NOT EXISTS']]];

        $uncompressed_attachments = new WP_Query($args);
        $total_file_size = 0;
        if ($uncompressed_attachments->have_posts()) {
            while ($uncompressed_attachments->have_posts()) {
                $uncompressed_attachments->the_post();
                $postID = get_the_ID();

                $filesize = filesize(get_attached_file($postID));
                $total_file_size += $filesize;
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
            // ITEM 2 coherence hardening (twin of comms.class.php): the preset ships picture_avif=1,
            // but make the next-gen ceiling coherent independent of preset contents so this Connect path
            // can never de-sync (single Next-Gen control reads ON=avif).
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

        // Agency mode: relay to remote site. save_mode() terminates via wp_send_json_*.
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


        update_option(WPS_IC_SETTINGS, $settings);
        update_option(WPS_IC_PRESET, $preset);

        // Preload Page
        $cacheLogic = new wps_ic_cache();

        // Remove generateCriticalCSS Options
        delete_option('wps_ic_gen_hp_url');

        if (!class_exists('wps_ic_htaccess')) {
            include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }

        $htaccess = new wps_ic_htaccess();

        if ($preset == 'safe') {
            $htaccess->removeHtaccessRules();
            $htaccess->removeAdvancedCache();
            $htaccess->setWPCache(false);
        } else {
            // Setup Advanced Caching
            // Add WP_CACHE to wp-config.php
            $htaccess->setWPCache(true);
            $htaccess->setAdvancedCache();
        }

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();

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

        // Start building the HTML
        $html = '<div class="cdn-popup-loading" style="display: none;">';
        $html .= '<div class="wpc-popup-saving-logo-container">';
        $html .= '<div class="wpc-popup-saving-preparing-logo">';
        $html .= '<img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>';
        $html .= '<span class="wpc-ic-popup-logo-saving-loader" aria-hidden="true"></span>'; // CSS brand ring (was preparing.svg <img>)
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


        // Return the HTML as an AJAX response
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

        // Update the 'wpc-excludes' option with the new data
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

        // Fetch the data from 'wpc-excludes' option
        $wpc_excludes = get_option('wpc-excludes', []);
        $excludes = isset($wpc_excludes['page_excludes_files'][$id]) ? $wpc_excludes['page_excludes_files'][$id] : [];

        if (!empty($excludes[$setting])) {
            $current_excludes = implode("\n", $excludes[$setting]);
        } else {
            $current_excludes = '';
        }

        $setting_name = ['cdn' => esc_html__('CDN', WPS_IC_TEXTDOMAIN), 'adaptive' => esc_html__('Adaptive Images', WPS_IC_TEXTDOMAIN), 'advanced_cache' => esc_html__('Advanced Cache', WPS_IC_TEXTDOMAIN), 'critical_css' => esc_html__('Critical CSS', WPS_IC_TEXTDOMAIN), 'delay_js' => esc_html__('JavaScript', WPS_IC_TEXTDOMAIN), 'delay_js_v2' => esc_html__('JavaScript', WPS_IC_TEXTDOMAIN)];

        // Start building the HTML
        $html = '<div class="cdn-popup-loading" style="display: none;">';
        $html .= '<div class="wpc-popup-saving-logo-container">';
        $html .= '<div class="wpc-popup-saving-preparing-logo">';
        $html .= '<img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>';
        $html .= '<span class="wpc-ic-popup-logo-saving-loader" aria-hidden="true"></span>'; // CSS brand ring (was preparing.svg <img>)
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
        $html .= esc_textarea($current_excludes); // (v7.10.04) SECURITY: escape stored excludes into the textarea
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


        // Return the HTML as an AJAX response
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
        $excludes = rtrim($excludes, "\n");
        $excludes = explode("\n", $excludes);

        // Fetch the entire 'wpc-excludes' option
        $wpc_excludes = get_option('wpc-excludes', []);

        // Create 'page_excludes_files' key if it doesn't exist
        if (!isset($wpc_excludes['page_excludes_files'])) {
            $wpc_excludes['page_excludes_files'] = [];
        }

        if (empty($wpc_excludes['page_excludes_files'][$id])) {
            $wpc_excludes['page_excludes_files'][$id] = [];
        }

        $wpc_excludes['page_excludes_files'][$id][$setting] = $excludes;

        // Update the 'wpc-excludes' option with the new data
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
            //To get statuses, we have to process all posts
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
            }

            $cache::purgeAll($url_key);
        } else if ($setting_action == 'generate' && $setting_name == 'critical_css') {
            $critical = new wps_criticalCss();
            $critical->generateCriticalCSS($id, true);
        } else {

            $wpc_excludes = get_option('wpc-excludes', []);

            // If 'page_excludes' doesn't exist within 'wpc-excludes', initialize it as an empty array
            if (!isset($wpc_excludes['page_excludes'])) {
                $wpc_excludes['page_excludes'] = [];
            }


            // Ensure each $post_id is an array within 'page_excludes'
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

                // Update the 'wpc-excludes' option with the modified data
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

        // Only purge HTML cache for the tested page — preserves CDN cache,
        // Critical CSS, and CSS/JS hashes so visitors aren't affected
        $cache = new wps_ic_cache_integrations();
        $cache::purgeCacheFiles($url_key);

        $requests = new wps_ic_requests();

        $tests = get_option(WPS_IC_TESTS);
        unset($tests['home']);
        update_option(WPS_IC_TESTS, $tests);

        // Save history of tests
        $history = get_option(WPS_IC_LITE_GPS_HISTORY);
        if (empty($history)) {
            $history = [];
        }
        $history[time()] = get_option(WPS_IC_LITE_GPS);
        update_option(WPS_IC_LITE_GPS_HISTORY, $history);

        // Delete data
        delete_transient('wpc_test_running');
        delete_transient('wpc_initial_test');
        delete_option(WPS_IC_LITE_GPS);
        delete_option(WPC_WARMUP_LOG_SETTING);

        set_transient('wpc_initial_test', 'running', 5 * 60);

        // Test
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
            // (v7.03.92) $varnish=true + $forcePurge=true: cache-cookie rules change which responses are
            // cacheable; stale host-Varnish entries must be evicted or they survive with no purge at all.
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
            #$warmup_class->doTestLCP($id, true);
        } else {
            $warmup_class->optimizeSingle($id);
        }
    }

    public function wps_ic_check_optimization_status()
    {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // Agency mode: relay to remote site
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
            //we are not on our settings page
        } elseif (isset($_POST['optimize']) && $_POST['optimize'] == 'do-not-optimize') {
            update_option('wpc-warmup-selector', 'do-not-optimize');
        } else {
            delete_option('wpc-warmup-selector');
        }

        $warmup_class = new wps_ic_preload_warmup();
        $pages = $warmup_class->getPagesToOptimize();

        $status = $warmup_class->get_optimization_status();
        //local addition
        if (!empty($status['mode']) && $status['mode'] == 'local') {
            $next_page = reset($pages['pages']);
            if ($next_page !== false) {
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
        //end local addition

        if ($pages['unoptimized'] == 0) {
            $check = get_transient('wpc-page-optimizations-status-check');
            if ($check === false) {
                //wait a minute maybe we are still testing
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

        $updated = update_option('wps_ic_purge_rules', $purge_rules);

        if ($updated) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll(false, false, false, false);
        }

        wp_send_json_success();
    }

    public function wps_ic_get_purge_rules()
    {
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities — bypassed in agency portal
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
                update_option('wps_ic_purge_rules', $purge_rules);
            }
        }

        $post_publish = $purge_rules['post-publish'];

        //Checkboxes for post publish purge
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
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $settings       = sanitize_text_field($_POST['settings'] ?? '');
        $excludes       = sanitize_text_field($_POST['excludes'] ?? '');
        $cache          = sanitize_text_field($_POST['cache'] ?? '');
        $cache_cookies  = sanitize_text_field($_POST['cookies'] ?? '');
        $apikey         = sanitize_text_field($_POST['apikey'] ?? '');

        // In agency mode, inject the remote site's settings so get_option()
        // calls below return the client site's values, not the local agency ones.
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
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        // Get data
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

        // In agency mode, relay the import to the remote client site.
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
            update_option('wps_ic_purge_rules', $import_data['cache']);
        }

        // Import cache cookies
        if (isset($import_data['cache_cookies'])) {
            update_option('wps_ic_cache_cookies', $import_data['cache_cookies']);
        }

        $cache = new wps_ic_cache_integrations();
        $cache::purgeCriticalFiles();
        $cache::purgeAll();

        wp_send_json_success(['msg' => 'Settings imported successfully']);
    }


    public function wps_ic_set_default_settings()
    {
        // Verify nonce for security
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities
        if (!current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        $options = new wps_ic_options();
        $purge_rules = $options->get_preset('purge_rules');
        update_option('wps_ic_purge_rules', $purge_rules);

        $configuration = $options->get_preset('aggressive');
        update_option(WPS_IC_SETTINGS, $configuration);
        update_option(WPS_IC_PRESET, 'aggressive');

        delete_option('wpc-excludes');

        $cache = new wps_ic_cache_integrations();
        $cache::purgeCriticalFiles();
        $cache::purgeAll(false, false, false, false);

        wp_send_json_success();
    }

    public function wps_ic_save_cf_cdn()
    {
        if (!isset($_POST['wps_ic_nonce']) || !check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            wp_die();
        }

        // Check user capabilities — bypassed in agency portal
        if (!$this->isAgencyPortal() && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
            wp_die();
        }

        // A CF (re)connect/save can change the emit host + the edge's MIME behavior, so
        // invalidate the durable asset-MIME proof; the next render re-verifies the live CF edge.
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
            $prevCfCname = (string) get_option(WPS_IC_CF_CNAME); // change-detect for the orch sync
        }

        if (empty($cf)) {
            wp_send_json_error(['message' => 'Cloudflare not connected']);
            wp_die();
        }

        $zoneName = str_replace(['http://', 'https://', '/'], '', $siteUrl);

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'updateCFCname', 'apikey' => $apikey, 'cname' => $cname, 'token' => $cf['token'], 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'zone' => $cf['zone'], 'time' => microtime(true)], ['timeout' => 120]);

        if (!empty($body)) {
            $data    = (array)$body->data;
            $cfCname = $data['cfName'];

            if ($this->isAgencyPortal()) {
                if (!empty($apikey) && !empty($api) && !empty($api::$comms)) {
                    $api::$comms->saveRemoteCFOption($apikey, null, $cfCname);
                }
            } else {
                update_option(WPS_IC_CF_CNAME, $cfCname);

                // Push the new cname to the orch SYNCHRONOUSLY on save. The orch resolves a
                // CF request by Host → agencySites.cname; the legacy updateCFCname keys-call above does
                // NOT update that, and the reliable-fire only triggers on activation/update/
                // heartbeat, NOT on a cname change, so a changed domain returned "Site not found"
                // until a MANUAL /v2/config sync (the reported sicilianproductsonline gap). Fire it now,
                // on a GENUINE change only. Blocking is fine here: this save already does a 120s keys
                // round-trip + a full purge, and a synchronous POST has no loopback/cron/next-pageview
                // dependency (exactly the auto-fire paths that don't fire for a cname change). The
                // payload's cname source reads the just-written WPS_IC_CF_CNAME → sends the correct new
                // host. force_provision is the backstop: on a failed POST the admin_init heartbeat
                // re-fires until a confirmed 2xx (cleared only on 2xx).
                if ((string) $cfCname !== $prevCfCname) {
                    update_option('wpc_v2_force_provision', 1, false);
                    // PROVISION-THEN-PROMOTE. The new cname is UNVERIFIED until proven
                    // edge-live, so clear the verified flag: the cdn-rewrite emit-gate then keeps
                    // serving the CURRENT working host (the zapwp Bunny zone via ic_cdn_zone_name),
                    // never the unprovisioned new host, during the propagation window. No broken assets.
                    update_option('wpc_cf_cname_verified', '0', false); // EXPLICIT '0' (not delete): the fail-open gate distinguishes a mid-change suppress ('0') from a never-set legacy zone (emit). Promoted to 1 below / by the heartbeat once live.
                    if (function_exists('wpc_v2_config_sync_lazy_enabled') && function_exists('wpc_v2_get_zone_id')) {
                        $wpc_zid = (string) wpc_v2_get_zone_id();
                        if ($wpc_zid === '' || !ctype_digit($wpc_zid)) {
                            $wpc_zid = (string) $cfCname; // CF-CNAME zone (no numeric PZ) → orch resolves by apikey + the cname field
                        }
                        wpc_v2_config_sync_lazy_enabled(
                            $wpc_zid,
                            function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : false
                        );
                    } elseif (function_exists('wpc_v2_schedule_config_sync')) {
                        wpc_v2_schedule_config_sync(); // fallback (older bundle): the reliable-fire loopback/cron
                    }
                    // PROMOTE: now the orch has the cname, verify the new host is edge-live +
                    // orch-resolved (not "Site not found"), then mark it verified so the emit-gate
                    // switches to it. On verify-fail it stays unverified → the working host keeps serving
                    // and the admin_init heartbeat (wpc_cf_cname_reverify) promotes it once it goes live —
                    // no manual re-save needed.
                    if (class_exists('WPC_CloudflareAPI') && !empty($cf['token'])) {
                        $cfapi_verify = new WPC_CloudflareAPI($cf['token']);
                        if ($cfapi_verify && $cfapi_verify->verifyCfCnameLive($cfCname, 1, 5)) { // 1 try / 5s: the just-changed cname usually isn't edge-live yet during the save, so don't add ~28s of blocking wait; the re-verify heartbeat is the designed promoter for the propagation window
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

        // Check user capabilities — bypassed in agency portal
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

    /**
     * READ-ONLY admin diagnostics endpoint. wp_ajax_ ONLY (no nopriv).
     *
     * Answers "why isn't this image / the queue going?" by READING (never writing)
     * the exact meta + transient + option keys the compress / restore / drain
     * pipeline uses. Pass image_id=NNN for per-image detail; omit for queue-wide only.
     *
     * HARD GUARANTEES:
     *   - manage_options + nonce required (clean JSON 403 otherwise); priv-only.
     *   - ZERO state mutation: no update_*, set_transient, delete_*, wp_cache_delete,
     *     dispatch, or purge anywhere in this method or the helpers it calls.
     *     (We deliberately do NOT call wpc_v2_active_restore_count() — it issues a
     *     wp_cache_delete; we read wps_ic_bulk_process directly instead.)
     *   - Never fatals: every read is null-safe; every optional helper is
     *     function_exists-guarded.
     *   - Never echoes secrets (apikey reported as a bool; one-shot tokens as
     *     existence-only booleans, never their value).
     */
    public function wpc_v2_diag()
    {
        // ── AUTH: capability + nonce, fail-closed with JSON 403 before any read. ──
        // wp_ajax_ registration (no nopriv) already blocks logged-out callers.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden_capability'], 403);
        }
        // Nonce action 'wps_ic_nonce_action' under the 'wps_ic_nonce' key — the
        // house convention, already localized as wpc_ajaxVar.nonce on every WPC
        // admin page. $die=false → we emit clean JSON instead of die(-1).
        if (!check_ajax_referer('wps_ic_nonce_action', 'wps_ic_nonce', false)) {
            wp_send_json_error(['error' => 'bad_nonce'], 403);
        }

        // ── NULL-SAFE READ HELPERS (closures — add no class surface, never write). ──
        $meta = function ($id, $key) {
            if ($id <= 0) return null;
            $v = get_post_meta((int) $id, $key, true);
            return ($v === '' || $v === false) ? null : $v;
        };
        $opt = function ($key, $default = null) {
            return get_option($key, $default);
        };
        $trans = function ($key) {
            // get_transient() returns false when absent OR stored-false; for
            // diagnostics we treat both as "absent" (null).
            $v = get_transient($key);
            return ($v === false) ? null : $v;
        };
        $trans_exists = function ($key) {
            // Existence WITHOUT leaking the value (one-shot dispatch tokens).
            return get_transient($key) !== false;
        };

        $now    = time();
        $now_ms = (int) round(microtime(true) * 1000);

        $image_id = 0;
        if (isset($_REQUEST['image_id']))          $image_id = (int) $_REQUEST['image_id'];
        elseif (isset($_REQUEST['attachment_id'])) $image_id = (int) $_REQUEST['attachment_id'];

        // parent::$version is the human-readable dotted form ('7.01.94').
        // self::$version is the dot-stripped variant used for API args — don't use it here.
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

        // ─────────────────────────── PER-IMAGE ───────────────────────────
        if ($image_id > 0) {
            $ic_compressing = $meta($image_id, 'ic_compressing');
            $variants_raw   = $meta($image_id, 'ic_local_variants');

            // Per-format breakdown from the variant_key shape (wpc_v2_variant_key):
            // jpeg = bare "<size_label>", webp/avif = "<size_label>-webp" / "-avif".
            // Entries also carry a 'format' field — prefer it, fall back to suffix.
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
                        $fmt = 'jpeg'; // bare key = jpeg (no -jpg/-jpeg suffix is ever emitted)
                    }
                    if (isset($fmt_counts[$fmt])) $fmt_counts[$fmt]++; else $fmt_counts['other']++;

                    // Size label = key with any trailing -webp/-avif stripped.
                    $sl = $vkey;
                    if (substr($sl, -5) === '-avif' || substr($sl, -5) === '-webp') {
                        $sl = substr($sl, 0, -5);
                    }
                    $size_labels[$sl] = true;
                }
            }
            $variants_serialized_bytes = is_array($variants_raw) ? strlen(@serialize($variants_raw)) : 0;

            // Heartbeat ({imageID,status,event,time,bg_variant_fmt,bg_variant_size}).
            $hb = $trans('wps_ic_heartbeat_' . $image_id);
            $hb_out = is_array($hb) ? [
                'status'          => isset($hb['status']) ? $hb['status'] : null,
                'event'           => isset($hb['event']) ? $hb['event'] : null,
                'time'            => isset($hb['time']) ? (int) $hb['time'] : null,
                'age_s'           => isset($hb['time']) ? max(0, $now - (int) $hb['time']) : null,
                'bg_variant_fmt'  => isset($hb['bg_variant_fmt']) ? $hb['bg_variant_fmt'] : null,
                'bg_variant_size' => isset($hb['bg_variant_size']) ? $hb['bg_variant_size'] : null,
            ] : null;

            // compress_state is EITHER the string 'sent-to-api' OR an array — surface both shapes.
            $cmp = $trans('wps_ic_compress_' . $image_id);

            // t0 click stamp (ms).
            $t0  = $trans('wpc_v2_t0_ms_' . $image_id);
            $t0i = ($t0 !== null) ? (int) $t0 : null;

            // Drain-complete marker ("Phase A fully landed").
            $pad = $trans('wpc_v2_phase_a_done_' . $image_id);

            // Pending variants awaiting drain-to-disk. New shape { jobId, pending:{...},
            // recorded_at }; legacy shape is a flat { key => ... } map.
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

            // restore_state ({imageID,status:'restoring'|'restored'}).
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
                    // Echo the raw payload only when small, to keep the JSON sane.
                    'raw'                   => ($variants_serialized_bytes > 0 && $variants_serialized_bytes <= 8192) ? $variants_raw : null,
                    'raw_omitted_too_large' => ($variants_serialized_bytes > 8192),
                ],

                // One-shot async dispatch tokens — EXISTENCE only (never the value).
                // A token present here = a loopback was ARMED but never consumed
                // (the worker never landed). Key shape mirrors dispatch_async_loopback():
                // 'wpc_async_token_' . $action . '_' . $imageID, TTL 60s.
                'async_tokens_armed' => [
                    'wpc_async_phase_a'        => $trans_exists('wpc_async_token_wpc_async_phase_a_' . $image_id),
                    'wpc_async_restore_regen'  => $trans_exists('wpc_async_token_wpc_async_restore_regen_' . $image_id),
                    'wpc_async_image_bg_retry' => $trans_exists('wpc_async_token_wpc_async_image_bg_retry_' . $image_id),
                ],

                'heartbeat'         => $hb_out,
                'compress_state'    => $cmp,                         // 'sent-to-api' | {imageID,status,time} | null
                'restore_state'     => is_array($rst) ? ['status' => isset($rst['status']) ? $rst['status'] : null] : $rst,
                'restoring_guard'   => $trans_exists('wpc_restoring_' . $image_id), // bool — restore in flight (blocks re-optimize)
                't0_ms'             => $t0i,
                't0_age_ms'         => ($t0i !== null) ? max(0, $now_ms - $t0i) : null,
                'phase_a_done'      => ($pad !== null) ? (int) $pad : null,
                'pending'           => $pending_out,
            ];
        }

        // ─────────────────────────── DRAIN / QUEUE ───────────────────────────
        $drain_running = $trans('wpc_v2_drain_running'); // unix ts when lock taken | null
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
            // Durable option receipts (small arrays) — returned as-is.
            'last_drain_skip'    => $opt('wpc_v2_last_drain_skip'),   // {t,reason,...}
            'last_extdrain'      => $opt('wpc_v2_last_extdrain'),     // {t,src,ua}
            'last_drain_stats'   => $opt('wpc_v2_last_drain_stats'),  // {t,ingested,failed,...}
        ];

        // ─────────────────────────── BULK ───────────────────────────
        // active_restore: read wps_ic_bulk_process DIRECTLY (read-only). We do NOT
        // call wpc_v2_active_restore_count() — it does a wp_cache_delete (a write).
        $bp = $opt('wps_ic_bulk_process', null);
        $cq = $trans('wps_ic_compress_queue'); // { queue:[], total_images }
        $rq = $trans('wps_ic_restore_queue');  // { queue:[], total_images } — the bulk RESTORE queue
        // Surface the restore queue + the BULK-DRAIN chain markers (distinct from the pull-drain
        // markers in $out['drain'] above). These tell apart "bulk drain self-loopback not landing" (markers
        // idle + queue not shrinking) from "stuck on one image" (chain marker held + a head image) — the
        // exact ambiguity that left a v2 bulk restore wedged at status=restoring on a WAF-fronted host where
        // the public-host self-chain (wpc_bulk_v2_restore_fire_loopback) can't land and there is NO external
        // wake-proxy backstop for it (the wake proxy drives only the pull drain).
        $rq_queue = (is_array($rq) && isset($rq['queue']) && is_array($rq['queue'])) ? array_map('intval', $rq['queue']) : [];
        $out['bulk'] = [
            'stop_signal'         => $trans_exists('wpc_bulk_stop_signal'),
            'bulk_process'        => is_array($bp) ? $bp : $bp,
            'bulk_process_status' => is_array($bp) && isset($bp['status']) ? $bp['status'] : null,
            'active_restore'      => (is_array($bp) && isset($bp['status']) && $bp['status'] === 'restoring'),
            // BULK-DRAIN chain markers (NOT the pull drain). draining = a slice claimed the ms-dispatch
            // marker; redrain_pending = a kick arrived mid-slice. A draining=false + non-empty queue +
            // status=restoring == the self-chain isn't re-firing (loopback not landing).
            'compress_draining'        => $trans_exists('wpc_bulk_compress_draining'),
            'compress_redrain_pending' => $trans_exists('wpc_bulk_compress_redrain_pending'),
            'restore_draining'         => $trans_exists('wpc_bulk_restore_draining'),
            'restore_redrain_pending'  => $trans_exists('wpc_bulk_restore_redrain_pending'),
            'restore_queue'       => is_array($rq)
                ? [
                    'present'      => true,
                    'total_images' => isset($rq['total_images']) ? (int) $rq['total_images'] : null,
                    'queue_len'    => count($rq_queue),
                    'head_ids'     => array_slice($rq_queue, 0, 5), // the HEAD is the image it's stuck on
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

        // ─────────────────────────── ACCOUNT / CDN ───────────────────────────
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : (isset(self::$apikey) ? self::$apikey : '');
        $live_cdn = isset(self::$settings['live-cdn']) ? (string) self::$settings['live-cdn'] : null;
        $out['account'] = [
            'apikey_present'         => (is_string($apikey) && $apikey !== ''), // BOOL ONLY — never the key
            'live_cdn'               => ($live_cdn === '1'),
            'live_cdn_raw'           => $live_cdn,
            'zone_id'                => function_exists('wpc_v2_get_zone_id') ? wpc_v2_get_zone_id() : null,
            'cf_asset_mime_ok'       => ((string) $opt('wpc_v2_cf_asset_mime_ok', '') === '1'),
            'cf_asset_mime_retry'    => $trans_exists('wpc_v2_cf_asset_mime_retry'),
            // Reading this filter is side-effect-free.
            'async_dispatch_enabled' => (bool) apply_filters('wpc_v2_async_dispatch_enabled', true),
        ];

        // ─────────────────────────── TELEMETRY ───────────────────────────
        // wpc_v2_telemetry_stats() is a pure reader of the wpc_v2_fpm_telemetry ring
        // buffer (get_option + get_transient only; no writes). Reports enabled:false if off.
        if (function_exists('wpc_v2_telemetry_stats')) {
            $stats = wpc_v2_telemetry_stats();
            $out['telemetry'] = is_array($stats) ? $stats : null;
        }

        wp_send_json_success($out);
    }

    /**
     * Async loopback dispatcher. Fires a NON-BLOCKING raw fsockopen
     * self-POST (this was wp_remote_post(blocking=false), which returned
     * fake success on a saturated pool / off-box edge) so the heavy work
     * (Phase A POST, restoreV4 + regen) runs in a SEPARATE FPM worker. The
     * originating click AJAX returns to the user in ~300 ms; the loopback runs
     * concurrently in a different worker
     * and completes the work in the background.
     *
     * Authentication: one-shot transient token. We generate a random 32-char
     * token, store it keyed by imageID in a 60s transient, and POST it in the
     * loopback body. The async handler reads + verifies + deletes the token
     * (single-use). Prevents external callers from triggering the endpoint.
     *
     * Returns: bool — true if dispatch likely succeeded, false on failure
     * (caller falls back to synchronous execution).
     */
    public static function dispatch_async_loopback($action, $imageID)
    {
        if (!function_exists('wp_remote_post')) return false;
        // Filterable kill-switch for hosts where loopback is unreliable.
        // Default true; admins can disable via add_filter('wpc_v2_async_dispatch_enabled', '__return_false').
        if (!apply_filters('wpc_v2_async_dispatch_enabled', true)) return false;

        $imageID = (int) $imageID;
        if ($imageID <= 0) return false;

        $token = wp_generate_password(32, false, false);
        $token_key = 'wpc_async_token_' . $action . '_' . $imageID;
        set_transient($token_key, $token, 60); // 60s TTL — covers Phase A worst-case + slack

        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            // Can't derive a loopback target — free the token (so a delayed request can't double-run after
            // the caller goes inline) and tell the caller to run synchronously.
            delete_transient($token_key);
            error_log('[WPC AsyncDispatch] loopback bad_admin_url action=' . $action . ' imageID=' . $imageID . ' url=' . $url);
            return false;
        }

        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        // Honor an explicit non-standard port from admin_url (e.g. proxied/dev/staging :8443), else the
        // scheme's standard port.
        $port = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
        $host = (string) $parts['host']; // PUBLIC vhost — used ONLY as Host: header + TLS SNI/peer name, NOT as the connect target
        // Default to /admin-ajax.php (NOT bare '/') — a homepage hit would 200 without running any handler,
        // which is the same silent-no-op class this fix exists to kill.
        $path = (!empty($parts['path']) ? $parts['path'] : '/admin-ajax.php');

        // ── LOCAL-VHOST LOOPBACK + CONNECT-AND-CONFIRM (kills "fake success" behind a CDN/WAF) ──
        // ROOT CAUSE of the earlier fake-success: the socket connected to the PUBLIC host ($parts['host']). On a CDN/WAF-
        // fronted, datacenter-IP site (wpcompress.com behind cdn-cgi/challenge-platform) that host is the
        // EDGE, not the box: the TCP connect to the edge SUCCEEDS → fsockopen truthy → dispatcher returned
        // true → caller skipped its sync fallback → the unauthenticated nopriv self-POST was challenged/
        // dropped at the edge and NEVER reached local PHP-FPM. Live proof (zone 881159, image 19855, 11s
        // post-click): async_tokens_armed.wpc_async_phase_a = TRUE/unconsumed (verify_async_token deletes the
        // token on the worker's FIRST line, so a present token == worker never started). Only the EXTERNAL
        // wake-proxy (ua=WPC-WakeProxy/v1.0.8) lands on the nopriv endpoints today; self-loopbacks to the
        // public host do not.
        //
        // FIX, three parts:
        //   (1) CONNECT to a LOCAL address (127.0.0.1, then localhost), keeping Host: + TLS SNI = the public
        //       $host so the right vhost answers on the box, bypassing the public edge entirely.
        //   (2) CONFIRM landing per candidate: the handlers echo 'queued' AFTER verify_async_token() has
        //       already consumed the one-shot token, so reading 'queued' is RACE-FREE proof both that a worker
        //       started AND that the token is already gone. We read INSIDE the per-host loop so a candidate
        //       that connects-but-doesn't-confirm (wrong vhost / edge / no PHP) falls through to the NEXT
        //       candidate — making the public-$host rung a REAL last resort, not dead code.
        //   (3) DECIDE double-run safety from the TOKEN (not the wire) on any unconfirmed exit: the caller's
        //       claim_async_token_for_sync() is authoritative. We return true ONLY on a confirmed 'queued'.
        //
        // TLS-on-loopback: tls://127.0.0.1 makes the local terminator present the DOMAIN cert; peer name
        // 127.0.0.1 != domain so verification would always fail. We pin peer_name=$host (correct SNI → right
        // vhost cert) and DISABLE peer verification — this is a connection to OURSELVES on the box, so cert-
        // identity checking is neither meaningful nor desirable (no MITM surface on loopback).
        //
        // Connect chain is filterable (string or ordered array) for unix-socket / custom-bind / split-horizon
        // hosts: add_filter('wpc_loopback_connect_host', fn($chain,$host,$https,$port)=>['127.0.0.1'], 10, 4).
        $connect_chain = apply_filters('wpc_loopback_connect_host', ['127.0.0.1', 'localhost', $host], $host, $is_https, $port);
        if (is_string($connect_chain) && $connect_chain !== '') $connect_chain = [$connect_chain];
        if (!is_array($connect_chain) || empty($connect_chain)) $connect_chain = ['127.0.0.1', 'localhost', $host];
        // De-dupe, preserve order (localhost often == 127.0.0.1; public host may equal one of them).
        $connect_chain = array_values(array_unique(array_filter(array_map('strval', $connect_chain))));

        // On mod_php (NEITHER fastcgi_finish_request NOR litespeed_finish_request exists) the handler can't
        // detach before its 9-14s work, so 'queued' may not arrive within any sane read budget even though the
        // worker IS running. There, skip the confirmation read (it would impose a flat ~read-budget tax on a
        // path that previously returned in ~300ms) and trust the token re-check below.
        $can_detach   = (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
        $confirm_want = $can_detach && ((float) apply_filters('wpc_loopback_confirm_timeout', 2.5, $action, $imageID) > 0);

        // ONE wall-clock deadline across ALL connect+confirm attempts so a sick box can't stack
        // (connect_timeout × N) + read on top of each other. Default 2.5s total; filterable.
        $total_budget   = (float) apply_filters('wpc_loopback_total_budget', 2.5, $action, $imageID);
        $hard_deadline  = microtime(true) + max(0.3, $total_budget);
        $connect_budget = $is_https ? 0.7 : 0.4; // https rung gets more room for the cold TLS handshake on the box

        $ssl_ctx = $is_https ? stream_context_create(['ssl' => [
            'peer_name'         => $host,
            'SNI_enabled'       => true,
            'verify_peer'       => false,  // self-connection: cert is for $host, we dial 127.0.0.1 — verify can't pass and isn't meaningful
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]) : stream_context_create();

        $landed    = false;
        $used_host = '';
        foreach ($connect_chain as $chost) {
            if ($chost === '') continue;
            if (microtime(true) >= $hard_deadline) break; // out of total budget

            $is_loopback_literal = ($chost === '127.0.0.1' || $chost === '::1' || strtolower($chost) === 'localhost');

            // SECURITY: the request body carries the authed one-shot token. Only ever send it to a LOCAL
            // literal. On the public-$host rung (or any non-loopback filter entry) the connect could land on
            // the CDN/WAF edge / a split-horizon IP — send a TOKENLESS body there. A tokenless POST can't
            // pass verify_async_token (it 403s harmlessly), so the public rung can only CONFIRM a landing when
            // it reaches our real local PHP through that name; it can never run work, and the token never
            // egresses the box.
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

            // fwrite can short-write / fail on a peer that RST-closes after accept (a WAF that 302/challenges).
            // Treat a failed/partial write as a miss → next candidate.
            $written = @fwrite($sock, $req);
            if ($written === false || $written < strlen($req)) {
                @fclose($sock);
                error_log('[WPC AsyncDispatch] loopback write_fail action=' . $action . ' imageID=' . $imageID
                    . ' try=' . $chost . ' wrote=' . var_export($written, true));
                continue;
            }

            if (!$confirm_want || !$send_token) {
                // Confirmation disabled (mod_php / filtered off) OR this is the tokenless public rung (which
                // can never run work) → don't read. Close and let the token re-check below decide.
                @fclose($sock);
                $used_host = $chost;
                if ($send_token) break; // bytes delivered to a local worker; stop trying further rungs
                continue;               // tokenless rung delivered nothing runnable; keep trying
            }

            // ── LANDING CONFIRMATION (non-spinning): stream_select sleeps until data or sub-deadline, so a
            //    silent/slow worker costs ZERO cpu. Parse the HTTP STATUS LINE for 2xx (not a substring match,
            //    which false-positives on headers).
            @stream_set_blocking($sock, false);
            $buf = '';
            while (microtime(true) < $hard_deadline && strlen($buf) < 512) {
                $slice = $hard_deadline - microtime(true);
                if ($slice <= 0) break;
                $r = [$sock]; $w = null; $e = null;
                $sec = (int) floor($slice);
                $usec = (int) round(($slice - $sec) * 1000000);
                $ready = @stream_select($r, $w, $e, $sec, $usec);
                if ($ready === false) break;          // select error
                if ($ready === 0) break;              // sub-deadline elapsed with no data → unconfirmed
                $chunk = @fread($sock, 512);
                if ($chunk === '' || $chunk === false) {
                    if (feof($sock)) break;           // peer closed
                    continue;                          // select said ready but read empty: loop (select gates cpu)
                }
                $buf .= $chunk;
                // Confirmed iff the STATUS LINE is 2xx (handler emits 200 + 'queued' AFTER consuming the
                // token). Also accept the literal 'queued' body marker as a secondary witness.
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
            // CONFIRMED: a worker reached verify_async_token, consumed the token (delete precedes the echo),
            // and is running the work in its own FPM slot. Click returns fast; this read cost tens of ms.
            error_log('[WPC AsyncDispatch] loopback CONFIRMED action=' . $action . ' imageID=' . $imageID . ' via=' . $used_host);
            return true;
        }

        // ── UNCONFIRMED → resolve via the TOKEN (single source of truth) ─────────────────────────────────
        // No 'queued' confirmation. Either (a) a worker DID start (mod_php couldn't echo early / slow flush /
        // we delivered to a local worker without reading) → token GONE; or (b) nothing ran (every candidate
        // missed, wrote-then-RST, or hit a wrong vhost / edge that dropped the POST) → token PRESENT.
        //   token GONE    → a worker owns the destructive work → returning false here is STILL correct: the
        //                   caller's claim_async_token_for_sync() will ALSO see it gone and STAND DOWN.
        //   token PRESENT → nobody ran → caller's claim_…() atomically deletes (claims) it and runs INLINE.
        // We do NOT delete the token here (the caller's claim is the single atomic decision point — deleting
        // here would race a late local worker that is about to consume it).
        $still_armed = (get_transient($token_key) === $token);
        error_log('[WPC AsyncDispatch] loopback UNCONFIRMED action=' . $action . ' imageID=' . $imageID
            . ' via=' . $used_host . ' token_' . ($still_armed ? 'present_caller_will_sync' : 'consumed_worker_owns_it'));
        return false;
    }

    /**
     * Verify the one-shot token from a loopback request. Returns the imageID
     * on success, false on failure. Deletes the token on success (single-use).
     */
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

    /**
     * Double-run-safe claim for the sync fallback.
     *
     * Called by wps_ic_compress_live / wps_ic_restore_live ONLY when
     * dispatch_async_loopback() returned false (= "unconfirmed landing"). The
     * dispatcher armed a one-shot transient (wpc_async_token_<action>_<id>) that
     * a real worker DELETES the instant it starts (verify_async_token, BEFORE
     * the worker echoes 'queued'). So the token is the single source of truth:
     *
     *   - token PRESENT → no worker ever consumed it → SAFE to run inline. We
     *                     delete it here (ATOMIC claim) so a late local socket
     *                     can't ALSO consume it and double-run, then return true
     *                     ("you own it — run synchronously").
     *   - token ABSENT  → a worker already consumed it and is mid-flight on the
     *                     DESTRUCTIVE work (in-place optimize / delete-then-
     *                     restore). Running inline now would DOUBLE-RUN. Return
     *                     false ("stand down — the heartbeat/wpcWatchCard poller
     *                     tracks that worker to completion").
     *
     * The delete here is the SAME atomic op verify_async_token uses, so exactly
     * one of {worker, this caller} wins the row delete and the loser stands down.
     */
    public static function claim_async_token_for_sync($action, $imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return true; // no token was ever armed for a bad id → nothing to collide with; run inline
        $token_key = 'wpc_async_token_' . $action . '_' . $imageID;
        if (get_transient($token_key) === false) {
            error_log('[WPC AsyncDispatch] sync_fallback STAND_DOWN action=' . $action
                . ' imageID=' . $imageID . ' token_consumed_by_worker');
            return false; // a worker owns the destructive work — do NOT sync-run
        }
        delete_transient($token_key); // atomic claim — a late socket's verify_async_token now fails → 403
        error_log('[WPC AsyncDispatch] sync_fallback CLAIMED action=' . $action
            . ' imageID=' . $imageID . ' running_inline');
        return true;
    }

    /**
     * Async Phase A handler. Called via loopback from
     * wps_ic_compress_live. Runs the Phase A POST (which can take 9-14s in
     * the synchronous path) without blocking the user's click AJAX.
     *
     * The existing run_v2_optimize() path already:
     *   - sets ic_compressing.status appropriately
     *   - merges Phase A response variants into ic_local_variants
     *   - sets heartbeat transients so the JS heartbeat picks up the state
     *
     * So this handler is just: validate token, call run_v2_optimize, exit.
     * Telemetry captures wall time + outcome for observability.
     */
    public function wpc_async_phase_a()
    {
        $arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $imageID = self::verify_async_token('wpc_async_phase_a');
        if (!$imageID) {
            // No telemetry record — failed-auth requests shouldn't show up as
            // legitimate handler time. Just 403 and exit.
            status_header(403);
            exit;
        }

        // fsockopen loopback detach (pairs with dispatch_async_loopback). ignore_user_abort
        // keeps Phase A (9-14s) alive past the dispatcher's socket close on non-FPM/mod_php; echo+finish
        // frees FPM's network slot at t≈0. (No data-corruption hazard here — backup is a COPY not a delete,
        // unlike restore — so this is worker-slot economy + non-FPM survival, not a correctness fix.)
        // Mirrors the house idiom at lines ~5089-5091. set_time_limit(120) = generous slack over ~14s worst case.
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @set_time_limit(120);

        // backup_all_sizes MOVED here from the click handler. IO-
        // bound file copy of ~7 WP sub-sizes to backup location (~1-2s). It was
        // dominating the remaining click wall after the wait_for_regen move.
        // Must run BEFORE wait_for_regen (the regen marker may be from this
        // image's own pending regen; we want the backup of the CURRENT
        // sub-sizes, not the post-regen ones).
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
                // Clear queueing state so heartbeat doesn't loop on a dead job.
                update_post_meta($imageID, 'ic_compressing', [
                    'status' => 'backup_failed',
                    'time'   => time(),
                ]);
                status_header(200);
                exit;
            }
        }

        // wait_for_regen MOVED here from the click handler. If a
        // recent restore left a _wpc_pending_thumb_regen marker, wait for it
        // to clear (or 15s stale-clear) BEFORE Phase A. Now the user's click
        // returns in ~300ms regardless of regen state; this background worker
        // absorbs the wait.
        if (isset(self::$local) && method_exists(self::$local, 'wait_for_regen_or_clear_stale')) {
            self::$local->wait_for_regen_or_clear_stale($imageID, 15);
        }

        // Run Phase A synchronously in THIS worker. The click AJAX has already
        // returned to the user; this worker can take its time.
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

        // Loopback caller used blocking=false so the response body is ignored.
        // Just exit cleanly to free the FPM worker.
        status_header(200);
        exit;
    }

    /**
     * Async restore regen handler. Called via loopback from
     * wps_ic_restore_live. Runs restoreV4() + thumbnail regen (3-5s in the
     * synchronous path) without blocking the user's click AJAX.
     */
    /**
     * Async delivery re-verify worker (the seamless-save loopback leg).
     * One-shot token (120s transient, consumed on use); runs the canonical resolver
     * verify so the post-save promote lands seconds after a tile/Next-Gen save.
     */
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

        // fsockopen loopback detach (pairs with dispatch_async_loopback). The dispatcher
        // fclose()'d its socket without reading, so ignore_user_abort FIRST guarantees the 3-5s restore
        // + the BLOCKING orch purge below survive the peer's disappearance on non-FPM/mod_php (was absent
        // under blocking=false → without it a raw-socket close can abort restore AFTER backup-delete but
        // BEFORE regen = half-restored image — the one true production hazard the review flagged). Then
        // echo+finish frees FPM's network side at t≈0 so the worker doesn't hold a connection slot for the
        // full restore + up-to-25s orch hang. Mirrors the house idiom at lines ~5089-5091; set_time_limit(120)
        // covers restore + the documented orch hang.
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request'))       { http_response_code(200); echo 'queued'; @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { http_response_code(200); echo 'queued'; @litespeed_finish_request(); }
        @set_time_limit(120);

        // Existing path — performs the file ops + regen + sets ic_status.
        // wpcWatchCard polls every 12s and picks up the state transition.
        $outcome = 'unknown';
        if (isset(self::$local) && method_exists(self::$local, 'restoreV4')) {
            // Capture the purge URL list BEFORE restoreV4 deletes the files.
            // The list was previously built post-delete, so the C15 disk-glob swept an
            // empty floor: adaptive -WxH URLs were never purged and stale AVIFs survived
            // on visitors' POPs for the full cache TTL. urls=24 in every restore log was
            // the tell (registered-only, no adaptives).
            $purge_urls_pre = function_exists('wpc_customer_purge_attachment_urls')
                ? wpc_customer_purge_attachment_urls($imageID) : [];
            self::$local->restoreV4($imageID);
            $status = get_post_meta($imageID, 'ic_status', true);
            $outcome = ($status === 'restored') ? 'success' : 'failed_' . ((string) $status);

            // Customer Purge v1 — single-image restore SUCCEEDED: fire-and-forget
            // unified CDN purge (flag-gated, default off). Runs in this detached
            // async worker, so it never touches the click; non-blocking regardless.
            if ($outcome === 'success' && function_exists('wpc_purge_compat')) {
                $purge_urls = $purge_urls_pre;
                if (!empty($purge_urls)) {
                    // Blocking and result-logged. This is the detached async restore
                    // worker: ic_status is already set above by restoreV4 (the JS polls that via
                    // wpcWatchCard, not this response), so waiting on the orch here is invisible
                    // to the user — and it lets us SEE the purge outcome. The prior fire-and-forget
                    // cut (timeout=1, no read) made the orch hang silent, which is exactly how the
                    // "everything stays stale" hang went unnoticed. A Throwable must never 500 it.
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

    /**
     * Background variant retry. Fired by bulkCompressHeartbeat_v2
     * when it early-advances an image (accounted ≥ 90% of expected). Runs
     * for up to 30s pulling from the orch manifest for any variants still
     * missing from ic_local_variants. Each landed variant updates savings
     * and the completed_cache picks up the fresh count on next eviction.
     *
     * Self-contained: re-reads variants per iteration, recomputes the
     * missing list, stops early when nothing is missing. Idempotent — safe
     * to fire from multiple bulk polls because the bulk handler guards with
     * a 60s transient lock per image.
     */
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

        // Honor bulk STOP. BGRetry self-chains (fires the next retry
        // attempt); before this it kept retrying after the user hit Stop. Bail now
        // and do NOT chain another attempt.
        if (get_transient('wpc_bulk_stop_signal')) {
            error_log('[WPC BGRetry] imageID=' . $imageID . ' stop_signal — aborting retry chain');
            status_header(200);
            exit;
        }

        // Reverted an earlier ic_status='restored' bail. It was a
        // band-aid for the BGRetry hammer that the now-reverted discard guard caused
        // (variants discarded → never landed → BGRetry retried forever). With the
        // discard gone, a re-compress's variants land normally, so BGRetry no longer
        // hammers, and bailing on 'restored' could wrongly stop a legit re-compress's
        // retries during the Phase-B-before-Phase-A race window. Removed.

        // Yield to an active bulk restore (foreground). BGRetry self-
        // chains loopback FPM requests; during a restore that starves it. Resumes
        // naturally once the restore clears. (Kept; this is sound.)
        if (function_exists('wpc_v2_active_restore_count') && wpc_v2_active_restore_count() > 0) {
            error_log('[WPC BGRetry] imageID=' . $imageID . ' yield_to_restore — aborting retry chain');
            status_header(200);
            exit;
        }

        $t0_ms = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
        if ($t0_ms <= 0) {
            // No t0 → can't address-by-time on the manifest. Bail clean.
            status_header(200);
            exit;
        }

        // Read expected_variants + current variants to compute missing keys.
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

            // Build missing list (size×format combos that aren't on disk and
            // aren't recorded as no-improvement).
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
                // Long-poll wait_ms=5000 so orch holds the
                // connection open until a NEW variant lands (or 5s elapses),
                // instead of short-polling and re-trying every 5s. Catches
                // stragglers the instant they're produced server-side
                // rather than waiting up to 5s between polls.
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
            // Long-poll already waited 0-5s; no extra sleep needed. Loop
            // immediately so we re-check landed state + re-arm long-poll.
        }
        // Chained retry. If we hit the 30s deadline and the
        // image is still incomplete, re-fire ourselves up to 3 total
        // attempts (90s of cumulative long-poll coverage per image).
        // Attempt count tracked in a 10-min transient so it spans the
        // chained dispatches but resets for the next compress click.
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
                // Clear the bulk-side one-shot lock so the chained dispatch
                // can re-acquire it from this worker. Without this clear,
                // the lock from bulk handler blocks our chained loopback.
                delete_transient('wpc_v2_bg_retry_fired_' . $imageID);
                if (function_exists('wpc_v2_fire_image_bg_retry')) {
                    wpc_v2_fire_image_bg_retry($imageID);
                }
            } else if ($still_incomplete) {
                // Retry exhausted (3 attempts × 30s = 90s of
                // long-poll coverage) AND variants still missing. Service
                // genuinely isn't producing them (encoder may have skipped
                // silently for these specific size×format combos). Mark
                // them as bg_no_improvement: 'retry_exhausted' so they
                // count toward accounted — image reaches the completion
                // gate honestly instead of sitting at 22/24 forever in
                // active. User can re-compress if they want to try again.
                // Recovers also keeps the per-image expected count
                // consistent with reality, so completed_cache freezes
                // cleanly (accounted >= expected once these are filled).
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
    /**
     * Public wrapper: dispatch a background variant retry for one
     * image via async loopback. Caller is the bulk heartbeat handler when it
     * early-advances an image. Fire-and-forget; the retry worker runs in
     * a separate FPM worker for up to 30s.
     */
    function wpc_v2_fire_image_bg_retry($imageID)
    {
        if (!class_exists('wps_ic_ajax')) return false;
        return wps_ic_ajax::dispatch_async_loopback('wpc_async_image_bg_retry', $imageID);
    }
}