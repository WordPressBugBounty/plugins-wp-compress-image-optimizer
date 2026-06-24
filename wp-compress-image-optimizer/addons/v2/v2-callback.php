<?php
/**
 * v2 bg-swap REST callback endpoints.
 *
 * Receives per-variant POSTs from the orchestrator: one (sizeLabel, format)
 * tuple each. Auth is HMAC-SHA256 over `t.<body_sha256>` keyed with the site
 * apikey, replay-protected by a 60s timestamp window. Writes are idempotent —
 * a re-received variant no-ops if the bytes already match on disk, else it's
 * replaced atomically via temp+rename.
 *
 * Merge reuses the v1 `wpc_bg_meta_$imageID` GET_LOCK so a v1 and v2 drain can
 * touch the same image concurrently (e.g. an upgrade mid-compress).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_register_callback_route')) {

function wpc_v2_register_callback_route()
{
    register_rest_route('wpc/v2', '/bg_swap', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap',
        'permission_callback' => '__return_true', // HMAC handles auth
    ]);

    // lazy_cdn single-variant callback. Local Service posts here after a
    // CDN-triggered on-demand encode (one variant, not batched). We resolve
    // image_id from origin_path, sizeLabel from (width, height), pull bytes
    // from bytes_url, write to disk, merge into ic_local_variants.
    register_rest_route('wpc/v2', '/bg_swap_single', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap_single',
        'permission_callback' => '__return_true', // HMAC handles auth
    ]);

    // LS cold-start probe — hits this on first trigger for a customer it
    // hasn't seen, caches the result 24h. If the plugin is too old, LS returns
    // 410 plugin_unsupported to the CDN and skips burning encode quota. Public
    // (no auth); keep the body minimal and stable — extending it is a contract
    // change LS needs notice of.
    register_rest_route('wpc/v2', '/healthcheck', [
        // GET = the public no-auth fleet probe (unchanged). POST = the orch-triggered provisioned-ping
        // (v7.03.41): HMAC-gated inside the handler, drives a verify-then-un-suppress. Same route, new method.
        'methods'             => ['GET', 'POST'],
        'callback'            => 'wpc_v2_handle_healthcheck',
        'permission_callback' => '__return_true',
    ]);

    // Tier-3 "fetch-variant" echo. HMAC-signed; reads an optimized variant off
    // disk via PHP and streams the raw bytes. Lets the CDN edge fetch a variant
    // when a customer WAF/server rule blocks its normal fetch by .avif/.webp
    // extension or by the CDN's source IP — PHP runs inside the web stack, so
    // the file read bypasses those request-layer rules. Read-only, confined to
    // uploads, image-ext allowlisted, HMAC-gated.
    register_rest_route('wpc/v2', '/fetch-variant', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_fetch_variant',
        'permission_callback' => '__return_true', // HMAC handles auth
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_callback_route');

/**
 * Cold-start probe target for LS's lazy_cdn flow.
 *
 * Returns the plugin's lazy_cdn capability surface so LS can decide whether to
 * spend encode quota on this customer (version, supports_* flags, server time
 * for clock-skew detection). No auth — the probe must succeed without a prior
 * handshake — and no PII: the request URL already identifies the site.
 *
 * Respond Cache-Control: no-store. LS owns the 24h client cache; we must never
 * serve a stale capability advertisement.
 */
/**
 * Real-content verify-then-un-suppress (v7.03.43) — extracted so BOTH the orch-trigger handler AND the
 * debug panel's button share the EXACT same fail-safe. Probes a REAL image's .webp and stamps the
 * provisioning fingerprint (un-suppress) ONLY when the edge returns 200 + webp BYTES (body-sniffed, so a
 * 404 or proxied origin-PNG can't pass — the staging false-positive). Bypasses the outbound /v2/config
 * entirely: the verify IS ground truth ("only the serve is truth"). Returns a result the callers map.
 */
function wpc_v2_verify_and_unsuppress($reason = '')
{
    if (!class_exists('WPC_Delivery_Resolver') || !method_exists('WPC_Delivery_Resolver', 'pick_real_image_probe')) {
        return ['ok' => false, 'verify_cdn_ok' => false, 'stamped' => false, 'reason' => 'resolver_unavailable'];
    }
    $target = WPC_Delivery_Resolver::pick_real_image_probe();
    if (!is_array($target) || empty($target['cdn_webp_url'])) {
        return ['ok' => true, 'verify_cdn_ok' => false, 'stamped' => false, 'reason' => 'no_real_image_to_verify'];
    }
    $p     = WPC_Delivery_Resolver::probe($target['cdn_webp_url'], 'image/webp,*/*');
    $code  = is_array($p) ? (int) ($p['code'] ?? 0) : 0;
    $fmt   = is_array($p) ? (string) ($p['fmt'] ?? '') : '';
    $ctype = is_array($p) ? (string) ($p['ctype'] ?? '') : '';
    if (!($code === 200 && $fmt === 'webp')) {
        return ['ok' => true, 'verify_cdn_ok' => false, 'stamped' => false, 'reason' => 'edge_not_serving_webp',
                'probe' => ['code' => $code, 'fmt' => $fmt, 'ctype' => $ctype], 'probe_url' => (string) $target['cdn_webp_url']];
    }
    // Edge serves real webp for THIS host → stamp (un-suppress) + clear pending + re-resolve (promotes the
    // cached tier picture→cdn-edge and fires the resolver's delivery-change purge of stale origin HTML).
    if (function_exists('update_option') && function_exists('wpc_v2_env_fingerprint')) {
        update_option('wpc_v2_provisioned_fingerprint', wpc_v2_env_fingerprint(), false);
    }
    if (function_exists('delete_option')) { delete_option('wpc_v2_force_provision'); }
    if (method_exists('WPC_Delivery_Resolver', 'resolve')) { WPC_Delivery_Resolver::resolve(true); }
    if (function_exists('error_log')) { error_log('[WPC v2] verify_and_unsuppress(' . (string) $reason . '): real-content 200 webp -> fingerprint stamped -> UN-SUPPRESSED'); }
    return ['ok' => true, 'verify_cdn_ok' => true, 'stamped' => true, 'reason' => 'un_suppressed', 'attachment_id' => (int) ($target['attachment_id'] ?? 0)];
}

/**
 * Orch-triggered provisioned-ping (v7.03.41) — the RELIABLE, INBOUND un-suppress path.
 *
 * After the orch backend-provisions a zone (signs the header config + creates the Bunny edge rules), it
 * POSTs here so the plugin confirms-and-un-suppresses ITSELF — removing the plugin's flaky OUTBOUND
 * /v2/config carriers (which time out on the synchronous edge-rule PATCH) as the single point of failure.
 *
 * The orch cannot write the suppression flag directly: it's sha1(home|DB_NAME|table_prefix), values the
 * orch doesn't know. So this is a TRIGGER, not a remote DB write — and crucially it is NOT a blind flip:
 * the plugin runs a REAL-CONTENT verify (a real image's .webp must return 200 + webp BYTES) before it
 * stamps. That is the same fail-safe is_active() enforces — un-suppress ⇒ the edge provably works ⇒ no 404.
 * A synthetic self-test could negotiate while real content 404s (the staging false-positive); a real
 * rendition can't, so we never un-suppress into broken images.
 *
 * Contract: POST /wpc/v2/healthcheck, X-WPC-Sig HMAC (same as /v2/config), body {provisioned, zone_id, nav}.
 */
function wpc_v2_handle_provisioned_ping(WP_REST_Request $request)
{
    $body_raw = (string) $request->get_body();
    $sig      = (string) $request->get_header('X-WPC-Sig');

    // AUTH — same HMAC contract as /v2/config (apikey-keyed, 60s replay window). The GET probe is public;
    // this mutating branch is not.
    if ($sig === '' || !function_exists('wpc_v2_verify_hmac') || !wpc_v2_verify_hmac($sig, $body_raw, 60)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
    }

    $data = json_decode($body_raw, true);
    if (!is_array($data)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_body'], 400);
    }

    // nav = the orch's native_accept_vary witness. Informational mirror only — verify_cdn below is the
    // real gate (a witness can be stale/wrong; only the serve is truth).
    $zone = isset($data['zone_id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $data['zone_id']) : '';
    if ($zone !== '' && array_key_exists('nav', $data) && function_exists('update_option')) {
        update_option('wpc_v2_orch_nav_' . $zone, !empty($data['nav']) ? '1' : '0', false);
    }

    if (empty($data['provisioned'])) {
        return new WP_REST_Response(['ok' => true, 'action' => 'noop', 'reason' => 'provisioned_false'], 200);
    }

    // Idempotent: already confirmed for THIS host → nothing to do.
    if (function_exists('wpc_v2_provision_env_changed') && !wpc_v2_provision_env_changed()) {
        return new WP_REST_Response(['ok' => true, 'action' => 'noop', 'reason' => 'already_provisioned'], 200);
    }

    // Delegate to the shared real-content verify-then-un-suppress (same fail-safe the debug button uses).
    $r = wpc_v2_verify_and_unsuppress('orch_trigger');
    if (($r['reason'] ?? '') === 'resolver_unavailable') {
        return new WP_REST_Response(['ok' => false, 'error' => 'resolver_unavailable'], 200);
    }
    $action = !empty($r['stamped']) ? 'un_suppressed'
            : ((($r['reason'] ?? '') === 'no_real_image_to_verify') ? 'deferred' : 'stay_suppressed');
    return new WP_REST_Response(array_merge(['ok' => true, 'action' => $action], $r), 200);
}

function wpc_v2_handle_healthcheck(WP_REST_Request $request)
{
    // POST = the orch-triggered provisioned-ping (v7.03.41). Branch BEFORE the GET fleet-probe body —
    // it's a distinct, HMAC-gated, verify-then-un-suppress path. (GET stays the public no-auth probe.)
    if ($request->get_method() === 'POST') {
        return wpc_v2_handle_provisioned_ping($request);
    }

    // Prefer WPC_PLUGIN_VERSION (set before bootstrap). Fall back to
    // wps_ic::$version only defensively — versions without the constant don't
    // have this endpoint either.
    if (defined('WPC_PLUGIN_VERSION')) {
        $plugin_version = (string) WPC_PLUGIN_VERSION;
    } elseif (class_exists('wps_ic') && isset(wps_ic::$version)) {
        $plugin_version = (string) wps_ic::$version;
    } else {
        $plugin_version = 'unknown';
    }

    // The usual carriers (self-loopback / wp-cron / Heartbeat) are unreliable
    // on locked-down hosts, but the orch hits this endpoint regularly. So while
    // a provision is pending or a CF cname is live-but-unpromoted, drive the
    // bounded sync + CF re-verify from here. Rate-limited (20s) and self-stops
    // once confirmed; idempotent, so safe on a public endpoint.
    if (function_exists('get_option')) {
        $hc_force   = (bool) get_option('wpc_v2_force_provision', false);
        $hc_cfc     = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $hc_cfver   = get_option('wpc_cf_cname_verified');
        $hc_cf_pend = ($hc_cfc !== '' && $hc_cfver !== '1' && $hc_cfver !== 1);
        if (($hc_force || $hc_cf_pend) && function_exists('get_transient') && !get_transient('wpc_v2_hc_fire_bk')) {
            if (function_exists('set_transient')) set_transient('wpc_v2_hc_fire_bk', 1, 20);
            if ($hc_force && function_exists('wpc_v2_run_deferred_config_sync')) { wpc_v2_run_deferred_config_sync(); }
            if ($hc_cf_pend && function_exists('wpc_v2_cf_cname_reverify')) { wpc_v2_cf_cname_reverify(false); }
        }
    }

    // Re-verify the delivery TIER for a provisioned Bunny zone that's still
    // serving <picture> (the resolver's own cron self-test is another unreliable
    // carrier on locked-down hosts: a zone can pass the 3-class edge test yet
    // stay tier=picture). resolve(true) re-runs the test and on a pass promotes
    // picture → clean CDN-edge. Bunny-only (a CF-direct zone delivers via its
    // cname); only after provisioning settles; rate-limited 5 min; no-op on edge.
    $hc_is_cf = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? (trim((string) get_option(WPS_IC_CF_CNAME, '')) !== '') : false;
    if (!$hc_is_cf && function_exists('get_option') && !get_option('wpc_v2_force_provision', false)
        && class_exists('WPC_Delivery_Resolver') && class_exists('WPC_Negotiated_Delivery')
        && function_exists('get_transient') && !get_transient('wpc_v2_hc_reverify_bk')
        && !WPC_Negotiated_Delivery::is_active()) {
        if (function_exists('set_transient')) set_transient('wpc_v2_hc_reverify_bk', 1, 300);
        WPC_Delivery_Resolver::resolve(true); // force re-verify → promotes picture→cdn-edge if the zone now passes
    }

    // Capability flag — true iff this plugin has both prereqs for lazy_cdn:
    //   1. /bg_swap_single endpoint (v7.02.19) — checked via function_exists
    //   2. splash-cache-bust hook (v7.02.20) — checked via function_exists
    // Either missing → LS should treat customer as plugin_unsupported.
    $supports_lazy_cdn = function_exists('wpc_v2_handle_bg_swap_single')
        && function_exists('wpc_v2_invalidate_splash_count');

    // Tier-3 echo capability — true iff the HMAC-gated disk-echo endpoint is
    // wired, so the CDN can fall back to it when WAF rules block the edge's
    // normal .avif/.webp fetch.
    $supports_fetch_variant = function_exists('wpc_v2_handle_fetch_variant');

    // Fleet-census surface (CDN team sweeps healthchecks for a "who runs what"
    // table): resolved tier, any forced mechanism, Images-master state, and
    // whether the request came through Cloudflare. Cheap — resolve_verbose()
    // reads cached state (never probes here); CF detection is a header check.
    $tier_name = ''; $emission_mode = 'auto';
    if (class_exists('WPC_Delivery_Resolver')) {
        $rv_hc = WPC_Delivery_Resolver::resolve_verbose();
        $tier_name     = isset($rv_hc['tier_name']) ? (string) $rv_hc['tier_name'] : '';
        $emission_mode = isset($rv_hc['override'])  ? (string) $rv_hc['override']  : 'auto';
    }
    $images_on_hc = !class_exists('WPC_Negotiated_Delivery')
        || WPC_Negotiated_Delivery::cdn_images_enabled();
    $cf_detected = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']);

    // Plugins predating /wpc/v2/bg_swap_batch 404 orch's batched POSTs silently
    // (rest_no_route, non-2xx, no throw). Orch must gate batch-vs-single on THIS
    // boolean, never on plugin_version — the version label is non-monotonic
    // since the re-point (that trap already caused the lazy_cdn 410 bug).
    $supports_batch = function_exists('wpc_v2_handle_bg_swap_batch');

    // Multi-apikey disambiguator for orch's unknown_customer fallback. Inbound
    // HMAC is verified against exactly one key (wpc_v2_get_apikey), so on zones
    // with reconnect history (multiple agencySites rows) orch picking the wrong
    // row 401s every push callback. Expose a sha256 PREFIX of the current key —
    // never the key itself, this is public — so orch can hash its candidate rows
    // and sign with the match.
    $apikey_fp = '';
    if (function_exists('wpc_v2_get_apikey')) {
        $hc_key = (string) wpc_v2_get_apikey();
        if ($hc_key !== '') {
            $apikey_fp = substr(hash('sha256', $hc_key), 0, 12);
        }
    }

    $resp = new WP_REST_Response([
        'plugin_version'          => $plugin_version,
        'supports_bg_swap_single' => $supports_lazy_cdn,
        'supports_lazy_cdn'       => $supports_lazy_cdn,
        'supports_fetch_variant'  => $supports_fetch_variant,
        'supports_bg_swap_batch'  => $supports_batch,
        'apikey_fp'               => $apikey_fp,
        // Lets orch (and us) confirm the pull-ingestion lane is live on a site
        // without SSH, before firing wakes at it.
        'pull_enabled'            => function_exists('wpc_v2_pull_enabled') ? (bool) wpc_v2_pull_enabled() : false,
        // Read-only remote debug surface. Each field was a blind spot that cost
        // a debugging round: asset_probe = CF asset-unlock verdict; drain_* =
        // is the drain running; pull_cursor_ms = rising means drains consume.
        'debug' => [
            // The asset probe's only call site was frontend-only while its gate
            // was admin-only — a dead path. The healthcheck now RUNS the probe
            // when no verdict is cached on a CF site: opening this URL is both
            // trigger and reporter. Cheap (one 3s GET), self-rate-limited by the
            // verdict transient.
            'asset_probe_ran_now' => (static function () {
                // Positive verdict is a durable option (no flip-flop); a recent
                // negative keeps a 2h retry transient. Either = "decided", skip.
                if ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') {
                    return false; // durable proven — nothing to do
                }
                if (get_transient('wpc_v2_cf_asset_mime_retry') !== false) {
                    return false; // recent negative — in 2h retry cooldown
                }
                $cf_hc = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
                // Probe the EMIT host, not the pod. On a CF-direct CNAME zone
                // assets emit on the proxied CF cname — a different host from the
                // Bunny pod — so certifying the pod would false-positive against
                // the edge the browser actually fetches. Prefer the CF cname /
                // custom cname, fall back to the pod host.
                $cf_cname_hc  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
                $cf_set_hc    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
                $cf_cdn_on_hc = is_array($cf_set_hc) && !empty($cf_set_hc['settings']['cdn']);
                $zone_hc = ($cf_cname_hc !== '' && $cf_cdn_on_hc)
                    ? $cf_cname_hc
                    : (trim((string) get_option('ic_custom_cname', '')) ?: (string) get_option('ic_cdn_zone_name', ''));
                if (!$cf_hc || $zone_hc === '') {
                    return false;
                }
                $r_hc  = wp_remote_get('https://' . $zone_hc . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 8192]);
                $ct_hc = is_wp_error($r_hc) ? '' : strtolower((string) wp_remote_retrieve_header($r_hc, 'content-type'));
                $ok_hc = ((int) wp_remote_retrieve_response_code($r_hc) === 200) && (strpos($ct_hc, 'text/css') === 0);
                // Capture the pod version for the webp-immediate floor.
                $pv_hc = is_wp_error($r_hc) ? '' : (string) wp_remote_retrieve_header($r_hc, 'x-cdn-version');
                if ($pv_hc !== '') { set_transient('wpc_v2_cf_pod_version', $pv_hc, 12 * HOUR_IN_SECONDS); }
                // Positive verdict persists as a durable option (stable per-zone
                // fact); negative keeps only the 2h retry transient so the edge
                // re-probes soon when the cutover lands.
                if ($ok_hc) {
                    update_option('wpc_v2_cf_asset_mime_ok', '1', true);
                    delete_transient('wpc_v2_cf_asset_mime_retry');
                } else {
                    set_transient('wpc_v2_cf_asset_mime_retry', '0', 2 * HOUR_IN_SECONDS);
                }
                return $ct_hc !== '' ? $ct_hc : 'fetch_error';
            })(),
            'mode'                 => function_exists('wpc_get_optimization_mode') ? (string) wpc_get_optimization_mode() : '?',
            'nd_active'            => class_exists('WPC_Negotiated_Delivery') && method_exists('WPC_Negotiated_Delivery', 'is_active') && WPC_Negotiated_Delivery::is_active(),
            // PROVISIONING TRACE — diagnose "why is this site on origin /
            // unverified" without SSH. All read-only. env_changed=true ⇒ CDN
            // suppressed → origin until a confirmed /v2/config 2xx stamps the
            // fingerprint for this host. env_fingerprint != stamped ⇒ env changed
            // (clone) or never provisioned here. cf_cname_configured='' on a
            // CF-direct site ⇒ no CF host to send, so orch can't write the cname.
            'provisioning'         => [
                'env_fingerprint'         => function_exists('wpc_v2_env_fingerprint') ? wpc_v2_env_fingerprint() : null,
                'env_fingerprint_stamped' => (string) get_option('wpc_v2_provisioned_fingerprint', ''),
                'env_changed'             => function_exists('wpc_v2_provision_env_changed') ? wpc_v2_provision_env_changed() : null,
                'cdn_suppressed'          => function_exists('wpc_v2_zone_cdn_suppressed') ? wpc_v2_zone_cdn_suppressed() : null,
                'config_synced_at'        => (int) get_option('wpc_v2_config_synced_at', 0),
                'force_provision'         => (bool) get_option('wpc_v2_force_provision', false),
                'force_provision_fails'   => (int) get_option('wpc_v2_force_provision_fails', 0),
                'selfheal_attempts'       => (int) get_option('wpc_v2_selfheal_attempts', 0),
                'provisioned_site_url'    => (string) get_option('wpc_v2_provisioned_site_url', ''),
                'site_url'                => function_exists('site_url') ? (string) site_url() : '',
                'zone_id'                 => function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '',
                'orch_nav'                => function_exists('wpc_v2_get_zone_id') ? (string) get_option('wpc_v2_orch_nav_' . sanitize_key((string) wpc_v2_get_zone_id()), '') : '',
                'cf_cname_configured'     => defined('WPS_IC_CF_CNAME') ? (string) get_option(WPS_IC_CF_CNAME, '') : '',
                'cf_cdn_on'               => (static function () {
                    $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
                    return is_array($cf) && !empty($cf['settings']['cdn']);
                })(),
                'cf_cname_verified'       => (string) get_option('wpc_cf_cname_verified', 'legacy'),
            ],
            // DELIVERY-TIER TRACE — why a provisioned zone serves <picture> vs
            // clean CDN-edge. verify_cdn is the resolver's server-side 3-class
            // self-test, run from THIS box, so it can differ from an external
            // curl (cold edge POP near origin, the webp-class trust gate, etc).
            // tier!=cdn-edge with verify_cdn_ok=false ⇒ server-side probe failed;
            // verify_cdn_ok=true ⇒ a cap/override held it on picture.
            'delivery'             => (function () {
                if (!class_exists('WPC_Delivery_Resolver')) return null;
                $rv   = WPC_Delivery_Resolver::resolve_verbose();
                $vc   = (isset($rv['verify']['cdn']) && is_array($rv['verify']['cdn'])) ? $rv['verify']['cdn'] : null;
                $caps = (isset($rv['capabilities']) && is_array($rv['capabilities'])) ? $rv['capabilities'] : [];
                return [
                    'tier'          => isset($rv['tier_name']) ? $rv['tier_name'] : (isset($rv['tier']) ? $rv['tier'] : '?'),
                    'ceiling'       => isset($rv['ceiling']) ? $rv['ceiling'] : '?',
                    'override'      => isset($rv['override']) ? $rv['override'] : '?',
                    'verify_cdn_ok' => $vc !== null ? !empty($vc['ok']) : null,
                    'verify_cdn'    => $vc,
                    'cdn_on'        => !empty($caps['cdn_on']),
                    'has_variants'  => !empty($caps['has_variants']),
                ];
            })(),
            'lazy_enabled'         => function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled(),
            'cf_seen'              => (bool) get_option('wpc_v2_cf_assets_seen', 0),
            'asset_probe'          => ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') ? '1' : (get_transient('wpc_v2_cf_asset_mime_retry') !== false ? '0' : false), // durable proof + retry telemetry
            'drain_worker_started' => (int) get_transient('wpc_v2_drain_worker_started'),
            'drain_alive_until_ms' => (int) get_option('wpc_v2_drain_alive_until_ms', 0),
            'pull_cursor_ms'       => (int) get_option('wpc_v2_pull_cursor_ms', 0),
            // Shell-less diagnostics: why the last ingest failed, the latest
            // drain's counts, and whether THIS box can reach the staged-bytes
            // host (the hop you can't test from outside — prime suspect is
            // outbound b-cdn.net blocked by the same stack that blocks inbound
            // pod POSTs).
            'last_ingest_fail'     => get_option('wpc_v2_last_ingest_fail', null),
            'last_drain_stats'     => get_option('wpc_v2_last_drain_stats', null),
            'last_wake'            => get_option('wpc_v2_last_wake', null), // wake arrival + dispatch (the primary drain trigger)
            'last_drain_skip'     => get_option('wpc_v2_last_drain_skip', null), // why a drain_fire returned dispatched:false
            'last_extdrain'       => get_option('wpc_v2_last_extdrain', null), // last external drain-loop fire (orch reconcile drive)
            'ingest_outcomes'     => get_option('wpc_v2_ingest_outcomes', null), // wrote vs ack-without-write histogram (names the discard gate)
            'last_ingest_trace'   => get_option('wpc_v2_last_ingest_trace', null), // last 'wrote' outcome's abs_path + post-rename exists/size
            'bytes_egress'         => (static function () {
                $r_eg = wp_remote_head('https://wpc-v2-variants.b-cdn.net/', ['timeout' => 5, 'sslverify' => false]);
                return is_wp_error($r_eg) ? ('ERR: ' . substr($r_eg->get_error_message(), 0, 80)) : (int) wp_remote_retrieve_response_code($r_eg);
            })(),
        ],
        'tier'                    => $tier_name,
        'emission_mode'           => $emission_mode,
        'images_on'               => (bool) $images_on_hc,
        'cf_detected'             => (bool) $cf_detected,
        'time'                    => time(),
    ], 200);
    $resp->header('Cache-Control', 'no-store, max-age=0');
    $resp->header('X-Wpc-Plugin-Version', $plugin_version);
    return $resp;
}

/**
 * Tier-3 variant echo. The CDN edge POSTs an HMAC-signed request for a variant
 * by its uploads path; we read it off disk and stream the raw bytes. This
 * bypasses any nginx/.htaccess/WAF rule targeting the .avif/.webp extension or
 * the CDN's source IP at the request layer (PHP runs inside the stack). Used as
 * a fallback when the edge's normal fetch / HEAD-probe is rejected (e.g. a WAF
 * 415ing Bunny's HEAD for .avif while .jpg + GET succeed).
 *
 * SECURITY — read-only and tightly confined; even a leaked apikey can read ONLY
 * image variants under the uploads dir, never wp-config/.env/.php/etc:
 *   - HMAC-gated, 60s replay window (wpc_v2_verify_hmac).
 *   - path must realpath-resolve UNDER uploads basedir — rejects ../ traversal,
 *     NUL bytes, URL schemes, symlink escape.
 *   - extension allowlist: avif/webp/jpg/jpeg/png/gif only.
 *   - size-bounded; Content-Type mapped by extension, no mime guesswork.
 * Emergency-off: the `wpc_v2_fetch_variant_enabled` filter (default true).
 */
function wpc_v2_handle_fetch_variant(WP_REST_Request $request)
{
    if (!apply_filters('wpc_v2_fetch_variant_enabled', true)) {
        return wpc_v2_respond(403, ['error' => 'disabled']);
    }

    $body_raw = (string) $request->get_body();
    $v = wpc_v2_verify_hmac($request->get_header('X-WPC-Sig'), $body_raw);
    if (empty($v['ok'])) {
        return wpc_v2_respond(401, ['error' => 'hmac', 'reason' => isset($v['reason']) ? $v['reason'] : '']);
    }

    $body = json_decode($body_raw, true);
    $path = (is_array($body) && isset($body['path'])) ? (string) $body['path'] : '';
    if ($path === '') {
        return wpc_v2_respond(400, ['error' => 'no_path']);
    }

    // Normalise + reject anything dangerous BEFORE touching the filesystem.
    $path = preg_replace('/[?#].*$/', '', $path);            // strip query/fragment
    $path = rawurldecode($path);                             // decode once (catches %2e%2e etc.)
    if (strpos($path, "\0") !== false
        || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path)  // any ../ or ..\ segment
        || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {  // http(s)://, file://, php://, …
        return wpc_v2_respond(400, ['error' => 'bad_path']);
    }
    // Image-variant extensions ONLY — never echo .php/.env/etc.
    if (!preg_match('/\.(avif|webp|jpe?g|png|gif)$/i', $path)) {
        return wpc_v2_respond(415, ['error' => 'unsupported_ext']);
    }
    if (!function_exists('wp_get_upload_dir')) {
        return wpc_v2_respond(500, ['error' => 'no_upload_api']);
    }

    $ud       = wp_get_upload_dir();
    $basedir  = (is_array($ud) && !empty($ud['basedir'])) ? rtrim((string) $ud['basedir'], '/') : '';
    $realbase = $basedir !== '' ? realpath($basedir) : false;
    if ($realbase === false) {
        return wpc_v2_respond(500, ['error' => 'no_basedir']);
    }
    // Map the (full-or-relative) uploads path to a disk path under the real basedir.
    $baseurl_path = (is_array($ud) && !empty($ud['baseurl'])) ? (string) parse_url((string) $ud['baseurl'], PHP_URL_PATH) : '';
    $rel = $path;
    if ($baseurl_path !== '' && strpos($rel, $baseurl_path) === 0) {
        $rel = substr($rel, strlen($baseurl_path));
    }
    $rel  = ltrim($rel, '/');
    $real = realpath($realbase . '/' . $rel);
    // Must exist AND resolve STRICTLY under the uploads basedir (blocks traversal + symlink escape).
    if ($real === false
        || strpos($real, $realbase . DIRECTORY_SEPARATOR) !== 0
        || !is_file($real) || !is_readable($real)) {
        return wpc_v2_respond(404, ['error' => 'not_found']);
    }
    $size = (int) @filesize($real);
    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        return wpc_v2_respond(413, ['error' => 'bad_size']);
    }

    $ext    = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
    $ctypes = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg',
               'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $ctype  = isset($ctypes[$ext]) ? $ctypes[$ext] : 'application/octet-stream';

    // Stream raw bytes, bypassing WP's JSON serializer (which would corrupt the binary).
    if (!headers_sent()) {
        status_header(200);
        header('Content-Type: ' . $ctype);
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-WPC-Fetch-Variant: 1');
    }
    @readfile($real);
    exit;
}

/**
 * Main handler. WP_REST_Request → WP_REST_Response (always JSON).
 */
function wpc_v2_handle_bg_swap(WP_REST_Request $request)
{
    $entry_t = microtime(true);
    // AIMD: REQUEST_TIME_FLOAT is the TCP accept moment (FPM sets it before
    // worker pickup), so the delta to handler-complete includes FPM queue wait.
    // AIMD reads inbound_to_complete_ms; total_handler_ms (handler-only) stays
    // for backwards-compat.
    $request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;

    // Top-of-handler trace, BEFORE auth/validation — proves the request reaches
    // PHP and helps spot when something upstream intercepts (seen: access log
    // 400s with 10-300 KB bodies, inconsistent with this handler's tiny JSON).
    $sig_hdr = (string) $request->get_header('x_wpc_sig');
    $ct      = (string) $request->get_header('content_type');
    $clen    = (string) $request->get_header('content_length');
    $body_peek = $request->get_body();
    error_log(sprintf(
        '[WPC V2BgSwap ENTRY] body_len=%d content_type=%s content_length=%s sig_present=%s body_head=%s',
        strlen((string) $body_peek),
        $ct,
        $clen,
        $sig_hdr !== '' ? 'yes' : 'no',
        substr((string) $body_peek, 0, 200)
    ));

    // Raw body (NOT $request->get_json_params() — we need exact bytes for HMAC).
    $body_raw = $body_peek;
    if (!is_string($body_raw) || $body_raw === '') {
        return wpc_v2_respond(400, ['error' => 'empty_body']);
    }

    // 1. HMAC verify
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, $body_raw);
    if (!$hmac_check['ok']) {
        error_log('[WPC V2BgSwap] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    // 2. Parse + validate shape
    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }
    $imageID    = isset($body['imageID']) ? (int) $body['imageID'] : 0;
    $size_label = isset($body['sizeLabel']) ? sanitize_key((string) $body['sizeLabel']) : '';
    $format     = isset($body['format']) ? strtolower(sanitize_key((string) $body['format'])) : '';
    $filename   = isset($body['filename']) ? basename((string) $body['filename']) : '';
    $b64        = isset($body['bytesB64']) ? (string) $body['bytesB64'] : '';
    // fetchUrl is the primary field; bytesUrl is a transitional alias the
    // service team will drop. Accept both.
    $fetch_url  = '';
    if (isset($body['fetchUrl']) && is_string($body['fetchUrl']) && $body['fetchUrl'] !== '') {
        $fetch_url = (string) $body['fetchUrl'];
    } elseif (isset($body['bytesUrl']) && is_string($body['bytesUrl']) && $body['bytesUrl'] !== '') {
        $fetch_url = (string) $body['bytesUrl'];
    }
    $expected_size   = isset($body['bytesSize'])   ? (int) $body['bytesSize']     : 0;
    $expected_sha256 = isset($body['bytesSha256']) ? (string) $body['bytesSha256'] : '';
    // Explicit per-callback parent flag (replaces the "largest = parent"
    // heuristic). Logged for telemetry only — variant_key() already partitions
    // formats, so parent vs non-parent lands in the right slot regardless.
    $is_parent  = !empty($body['parent']);
    $job_id     = isset($body['jobId']) ? (string) $body['jobId'] : '';

    if (!$imageID || get_post_type($imageID) !== 'attachment') {
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
    }
    if ($size_label === '' || $format === '') {
        return wpc_v2_respond(400, ['error' => 'missing_fields']);
    }
    if (!in_array($format, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
        return wpc_v2_respond(400, ['error' => 'invalid_format']);
    }
    if ($format === 'jpg') $format = 'jpeg';

    // Restore guard. The transient is set when the user hits Restore and
    // cleared at the start of the next compress. A late Phase B callback from
    // the pre-restore compress gets 410'd here and never touches disk — without
    // it, those callbacks would re-write the freshly-restored files and the card
    // would flicker compressed-uncompressed-compressed.
    if (get_transient('wpc_v2_callbacks_blocked_' . $imageID) !== false) {
        error_log(sprintf(
            '[WPC V2BgSwap restored_reject] imageID=%d size=%s fmt=%s job=%s',
            $imageID, $size_label, $format,
            $job_id !== '' ? substr($job_id, 0, 8) : '-'
        ));
        return wpc_v2_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
    }

    // Stale-job rejection. If the callback's jobId differs from the pending
    // transient's, it belongs to a previous Phase A (e.g. user re-triggered
    // compress before the first drain finished); writing its bytes would clobber
    // the newer job's fresh variants. 410 stops orch retrying. Empty $job_id is
    // permissive so mid-rollout pods still work.
    //
    // TODO (when lazy backfill ships): make this triggerContext-aware. Full
    // backfill (all variants, via Phase A) keeps the strict check. On-demand
    // backfill (1 variant, runtime miss, no Phase A / no pending transient)
    // should bypass it — the single callback is authoritative — and skip
    // wpc_v2_remove_pending (nothing to remove). Plan: branch on a
    // 'triggerContext' field, no-op remove_pending for 'lazy_backfill_ondemand'.
    if ($job_id !== '') {
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $job_id) {
            error_log(sprintf(
                '[WPC V2BgSwap stale_job] imageID=%d cb_job=%s pending_job=%s size=%s fmt=%s',
                $imageID, substr($job_id, 0, 8), substr((string) $pending['jobId'], 0, 8),
                $size_label, $format
            ));
            return wpc_v2_respond(410, ['error' => 'stale_job', 'cb_jobId' => $job_id, 'pending_jobId' => (string) $pending['jobId']]);
        }
    }

    // Contract gap: encoder pods omit `filename`. Derive from WP metadata —
    // parent sizes use the attached-file basename with the extension swapped,
    // sub-sizes use $meta['sizes'][label]['file'] with the extension swapped.
    if ($filename === '') {
        $abs_parent_for_name = get_attached_file($imageID);
        if (!$abs_parent_for_name) {
            return wpc_v2_respond(500, ['error' => 'parent_file_missing_for_derive']);
        }
        $filename = wpc_v2_derive_variant_filename($abs_parent_for_name, $imageID, $size_label, $format);
        if ($filename === '') {
            return wpc_v2_respond(400, ['error' => 'filename_derive_failed', 'sizeLabel' => $size_label]);
        }
    }

    // 3. Per-format no-improvement signal (parallels v1 noImprovement branch).
    if (!empty($body['noImprovement'])) {
        $reason = isset($body['reason']) ? sanitize_text_field((string) $body['reason']) : 'no_improvement';
        wpc_v2_record_no_improvement($imageID, $size_label, $format, $reason, $body);
        if (wpc_v2_remove_pending($imageID, $size_label, $format)) {
            wpc_v2_recompute_savings($imageID);
        }
        return wpc_v2_respond(200, ['ok' => true, 'kind' => 'no_improvement']);
    }

    // Encoder may ship `bumped: source_already_optimal` instead of (or with)
    // `noImprovement: true` — same intent (re-encoding would be >= source bytes).
    // Route through the noImprovement path.
    if (isset($body['bumped']) && (string) $body['bumped'] === 'source_already_optimal') {
        error_log(sprintf(
            '[WPC V2BgSwap source_already_optimal] imageID=%d size=%s fmt=%s job=%s',
            $imageID, $size_label, $format,
            $job_id !== '' ? substr($job_id, 0, 8) : '-'
        ));
        wpc_v2_record_no_improvement($imageID, $size_label, $format, 'source_already_optimal', $body);
        if (wpc_v2_remove_pending($imageID, $size_label, $format)) {
            wpc_v2_recompute_savings($imageID);
        }
        return wpc_v2_respond(200, ['ok' => true, 'kind' => 'source_already_optimal']);
    }

    // Timing boundary: above is HMAC + parse + validate; below is write work.
    $write_start_t = microtime(true);

    // 4. Resolve bytes — inline base64 OR fetchUrl (pull mode for oversized
    // variants, or unconditionally when the service is in pull mode).
    if ($b64 !== '') {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return wpc_v2_respond(400, ['error' => 'invalid_base64']);
        }
    } elseif ($fetch_url !== '') {
        $get = wp_remote_get($fetch_url, ['timeout' => 15, 'sslverify' => true]);
        if (is_wp_error($get) || (int) wp_remote_retrieve_response_code($get) !== 200) {
            return wpc_v2_respond(502, ['error' => 'fetch_url_failed']);
        }
        $raw = wp_remote_retrieve_body($get);
        if (!is_string($raw) || $raw === '') {
            return wpc_v2_respond(502, ['error' => 'fetch_url_empty']);
        }
        // Pull-mode integrity checks (when the service supplies them).
        if ($expected_size > 0 && strlen($raw) !== $expected_size) {
            return wpc_v2_respond(502, ['error' => 'fetch_url_size_mismatch', 'got' => strlen($raw), 'expected' => $expected_size]);
        }
        if ($expected_sha256 !== '' && !hash_equals($expected_sha256, hash('sha256', $raw))) {
            return wpc_v2_respond(502, ['error' => 'fetch_url_sha256_mismatch']);
        }
    } else {
        return wpc_v2_respond(400, ['error' => 'missing_bytes_or_fetchUrl']);
    }

    if (function_exists('wpc_is_valid_image_bytes')
        && !wpc_is_valid_image_bytes($raw, $format, $imageID, 'bg_swap_v2', ['size_label' => $size_label])) {
        return wpc_v2_respond(400, ['error' => 'invalid_image_bytes']);
    }

    // 5. Atomic disk write (temp + rename)
    $abs_parent = get_attached_file($imageID);
    if (!$abs_parent) {
        return wpc_v2_respond(500, ['error' => 'parent_file_missing']);
    }
    $dest_dir = dirname($abs_parent);
    $dest     = $dest_dir . '/' . $filename;

    // Idempotency fast-path: same bytes already on disk → 200 immediately.
    if (file_exists($dest) && filesize($dest) === strlen($raw) && hash_file('sha256', $dest) === hash('sha256', $raw)) {
        if (wpc_v2_remove_pending($imageID, $size_label, $format)) {
            wpc_v2_recompute_savings($imageID);
        }
        return wpc_v2_respond(200, ['ok' => true, 'kind' => 'idempotent_noop']);
    }

    $tmp = $dest . '.wpc_v2_tmp_' . wp_generate_password(8, false);
    // Log silent disk-write failures. The @-suppressed write fails quietly on
    // disk-full or a perms mismatch (Pantheon/WP Engine: webserver UID != PHP
    // UID), returning 500 with no clue why ("stuck images, no errors visible").
    // Log errno + size + path tail so support can triage.
    if (@file_put_contents($tmp, $raw) === false) {
        $err = error_get_last();
        error_log(sprintf(
            '[WPC V2Callback] write_failed imageID=%d size_label=%s format=%s bytes=%d dest_tail=%s errno=%s msg=%s',
            (int) $imageID, (string) $size_label, (string) $format, strlen($raw),
            substr($dest, -60), $err['type'] ?? '-', $err['message'] ?? '-'
        ));
        return wpc_v2_respond(500, ['error' => 'write_failed']);
    }
    if (!@rename($tmp, $dest)) {
        $err = error_get_last();
        error_log(sprintf(
            '[WPC V2Callback] rename_failed imageID=%d size_label=%s format=%s dest_tail=%s errno=%s msg=%s',
            (int) $imageID, (string) $size_label, (string) $format,
            substr($dest, -60), $err['type'] ?? '-', $err['message'] ?? '-'
        ));
        @unlink($tmp);
        return wpc_v2_respond(500, ['error' => 'rename_failed']);
    }
    // chmod failure logging. Where webserver UID != PHP UID (Pantheon, WP
    // Engine), chmod 0644 fails and the file stays 0600 (FPM-only readable), so
    // the web server 404s the variant. Log it, distinct from write/rename fail.
    if (!@chmod($dest, 0644)) {
        $err = error_get_last();
        error_log(sprintf(
            '[WPC V2Callback] chmod_failed imageID=%d dest_tail=%s msg=%s (may still be served if umask/inherited perms OK)',
            (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
        ));
    }

    // Landed-variant purge. The OTF/pull write enqueues one, but a variant
    // landing via this bg-callback didn't — so the edge kept serving the
    // pre-landing OTF interim until its ~60s TTL. Enqueue the just-written file
    // + its -WxH format siblings (the negotiated `.webp` URL the browser hits in
    // edge mode) so the on-disk variant serves on the next request. Coalesced +
    // flushed once on shutdown by the shared helper.
    if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
        wpc_v2_enqueue_landed_purge($dest);
    }

    // Timing boundary: next is the ic_local_variants merge under GET_LOCK.
    $t_after_write = microtime(true);

    // 6. Merge into ic_local_variants under GET_LOCK
    $variant_key = wpc_v2_variant_key($size_label, $format);
    $upload_dir  = wp_get_upload_dir();

    // Encoder echoes originalSize (pre-encode source bytes) so we can compute
    // exact savings %. Falls back to 0 on older pods, leaving the baseline at 0
    // for that variant — best-effort; the modal just shows "—" for savings.
    $orig_size = isset($body['originalSize']) ? (int) $body['originalSize'] : 0;
    $kb        = isset($body['kb'])     ? (float) $body['kb']     : 0.0;
    $butter    = isset($body['butter']) ? (float) $body['butter'] : 0.0;
    $savings   = ($orig_size > 0) ? max(0, (int) round((1 - (strlen($raw) / $orig_size)) * 100)) : 0;

    // Elapsed since the customer's click (T0), stamped by run_v2_optimize at
    // request entry. Read for diagnostics only — an expired/missing transient
    // leaves it at 0, no functional impact.
    $t0_ms      = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
    $entry_ms   = (int) round($entry_t * 1000);
    $from_click_ms = ($t0_ms > 0) ? max(0, $entry_ms - $t0_ms) : 0;

    $entry = [
        'size'              => strlen($raw),
        'originalSize'      => $orig_size,
        'url'               => $upload_dir['baseurl'] . '/' . ltrim(str_replace($upload_dir['basedir'], '', $dest), '/'),
        'local'             => true,
        'skipped'           => false,
        'savings'           => $savings,
        'bg_upgraded'       => time(),
        // Ms-precision arrival timestamp. time() has 1s resolution, so two
        // callbacks in the same second shared a ts and the count-poller's
        // `ts > since` cursor dropped the second. This is the authoritative
        // cursor field in wps_ic_variant_count.
        'bg_upgraded_ms'    => (int) round(microtime(true) * 1000),
        'bg_t_from_click_ms' => $from_click_ms,
        'kb_reported'       => $kb,
        'butter'            => $butter,
        'phase_b_v2'        => true,
    ];
    if (isset($body['q']))      $entry['q']      = (int) $body['q'];
    if (isset($body['bumped'])) $entry['bumped'] = sanitize_text_field((string) $body['bumped']);
    if (isset($body['telemetry']) && is_array($body['telemetry'])) {
        $entry['telemetry'] = $body['telemetry'];
    }
    wpc_v2_merge_variant($imageID, $variant_key, $entry);

    // Timing boundary: next is the inline savings climb + drain recompute.
    $t_after_merge = microtime(true);

    // Cheap inline ic_savings climb. The old in-lock wpc_compute_best_savings
    // (5 update_post_meta inside the merge lock) was the contention source, but
    // dropping it broke two user-visible flows: (a) the card's headline % no
    // longer climbed live during the drain, and (b) "orphan" callbacks (parent
    // AVIF, job=-, arriving after the main job's pending emptied) bypass the
    // drain-complete recompute and froze ic_savings at the pre-orphan best.
    //
    // Fix: O(1) — if this variant's savings beats the current best, write the 4
    // ic_savings_* fields directly. No lock (writes are idempotent on the max
    // semantic; a race still converges to the max). Drain-complete still runs
    // recompute below as a safety net (e.g. best variant is bg_no_improvement).
    //
    // Skipped for bulk-session images: the bulk UI reads ic_local_variants
    // directly and recomputes client-side, so these per-callback writes are
    // wasted. The drain-complete recompute keeps steady-state correct. At 20+
    // callbacks/image × N images this is ~30% of per-callback wall under bulk.
    $is_bulk_session = false;
    $bulk_session_ids = get_transient('wpc_bulk_session_ids');
    if (is_array($bulk_session_ids) && in_array((int) $imageID, array_map('intval', $bulk_session_ids), true)) {
        $is_bulk_session = true;
    }
    if (!$is_bulk_session && $orig_size > 0 && $savings > 0) {
        // Wrap the read-then-write in a GET_LOCK so two concurrent callbacks
        // can't both pass the cur_savings check on the same stale value and
        // clobber each other (A reads 15→writes 35; B reads 15→writes 25 ⇒ 25
        // wins even though 35 was higher). Non-blocking — skip if we can't grab
        // it in 1s; whoever does will see the latest cur_savings.
        global $wpdb;
        $sav_lock = 'wpc_ic_savings_' . $imageID;
        $got_sav  = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 1)", $sav_lock));
        if ($got_sav === '1' || $got_sav === 1) {
            try {
                $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
                if ((float) $savings > $cur_savings) {
                    $opt_bytes = strlen($raw);
                    update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                    update_post_meta($imageID, 'ic_savings_format',   $format);
                    update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $opt_bytes));
                    update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
                }
            } finally {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $sav_lock));
            }
        }
    }

    $drain_complete = wpc_v2_remove_pending($imageID, $size_label, $format);
    if ($drain_complete) {
        wpc_v2_recompute_savings($imageID);
    }

    // 7. Heartbeat + chip event. Two modes, gated by
    // wpc_v2_use_eager_compressed_flip():
    //   ADAPTIVE (default): mirror ic_compressing.status, with Phase A as the
    //     gate — stays 'optimizing' until Phase A flips to 'compressed' (no
    //     flicker), then the handler re-renders with the updated chip count.
    //   EAGER: the first Phase B callback flips status to 'compressed' now, so
    //     the chip count climbs additively from variant #1. Works naturally with
    //     lazy backfill (a single on-demand callback is a complete event).
    $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
    $current_status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
        ? (string) $ic_compressing['status']
        : 'optimizing';

    $eager = function_exists('wpc_v2_use_eager_compressed_flip')
        && wpc_v2_use_eager_compressed_flip();

    if ($eager && $current_status !== 'compressed') {
        // Merge helper so expected_variants survives the status write.
        wpc_v2_ic_compressing_set_status($imageID, 'compressed');
        // Delete the in-flight transient too. compress_details returns the
        // "Optimizing" card early while this transient exists regardless of
        // status, so flipping status alone is invisible to the heartbeat
        // handler. Deleting it lets compress_details fall through to compressed.
        delete_transient('wps_ic_compress_' . $imageID);
        $current_status = 'compressed';
        error_log('[WPC V2BgSwap eager_flip] imageID=' . $imageID . ' status flipped to compressed on size=' . $size_label . ' fmt=' . $format);
    }

    // First bytes landed → clear the "warming" flag if the announce-driven flip
    // set one. The UI's "Finalizing…" pill crossfades out on the next tick.
    if (get_transient('wpc_v2_warming_' . $imageID) !== false) {
        delete_transient('wpc_v2_warming_' . $imageID);
    }

    // Skip the per-image heartbeat chip transient in a bulk session — the bulk
    // UI reads ic_local_variants directly, so the single-image "chip landed"
    // event isn't needed. Saves one set_transient per callback.
    if (!$is_bulk_session) {
        $chip_fmt  = strtoupper($format);
        $chip_size = ucfirst(str_replace(['_', '-'], ' ', $size_label));
        set_transient('wps_ic_heartbeat_' . $imageID, [
            'imageID'         => $imageID,
            'status'          => $current_status,
            'event'           => 'bg_variant_arrived',
            'time'            => time(),
            'bg_variant_fmt'  => $chip_fmt,
            'bg_variant_size' => $chip_size,
        ], 300);
    }

    $t_handler_end = microtime(true);
    $total_ms      = (int) round(($t_handler_end - $entry_t) * 1000);

    // Diagnostic timing — a second log line for correlation against orch traces
    // (grep `[wpc_v2_bg_swap_timing]`). Key fields:
    //   bootstrap_ms  = REQUEST_TIME_FLOAT → handler entry (WP core + plugin
    //                   load). > 1000 here ⇒ the WPC_IS_BG_SWAP gate missed the URL.
    //   clock_skew_ms = orch serverTime vs WP microtime at entry. > 60000
    //                   explains replay_window_exceeded.
    //   write/merge/recompute_ms = the internal work stages.
    $t_req_start  = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;
    $bootstrap_ms = ($entry_t - $t_req_start) * 1000;

    $orch_server_time = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
    $clock_skew_ms    = $orch_server_time > 0
        ? (int) round(($entry_t * 1000) - $orch_server_time)
        : null;

    $write_ms     = isset($write_start_t, $t_after_write)
        ? ($t_after_write - $write_start_t) * 1000
        : 0.0;
    $merge_ms     = isset($t_after_write, $t_after_merge)
        ? ($t_after_merge - $t_after_write) * 1000
        : 0.0;
    $recompute_ms = isset($t_after_merge)
        ? ($t_handler_end - $t_after_merge) * 1000
        : 0.0;

    error_log(sprintf(
        '[WPC V2BgSwap ACK] imageID=%d size=%s fmt=%s parent=%s bytes=%d orig=%d savings=%d%% q=%s butter=%.2f t_from_click_ms=%d total_ms=%d job=%s',
        $imageID, $size_label, $format,
        $is_parent ? 'Y' : 'N',
        strlen($raw),
        $orig_size,
        $savings,
        isset($entry['q']) ? (string) $entry['q'] : '-',
        $butter,
        $from_click_ms,
        $total_ms,
        $job_id !== '' ? substr($job_id, 0, 8) : '-'
    ));

    // AIMD: inbound_to_complete_ms = REQUEST_TIME_FLOAT → handler complete (FPM
    // queue wait + handler). AIMD reads this; total_handler_ms stays for the
    // service team's grep tooling.
    $inbound_to_complete_ms = ($t_handler_end - $request_arrival_t) * 1000;

    error_log(sprintf(
        '[wpc_v2_bg_swap_timing] imageID=%d variant=%s codec=%s bytes_in=%d clock_skew_ms=%s bootstrap_ms=%.1f write_ms=%.1f merge_ms=%.1f recompute_ms=%.1f total_handler_ms=%.1f inbound_to_complete_ms=%.1f',
        $imageID,
        $size_label,
        $format,
        strlen($b64),
        $clock_skew_ms === null ? 'n/a' : (string) $clock_skew_ms,
        max(0.0, $bootstrap_ms),
        max(0.0, $write_ms),
        max(0.0, $merge_ms),
        max(0.0, $recompute_ms),
        ($t_handler_end - $entry_t) * 1000,
        max(0.0, $inbound_to_complete_ms)
    ));

    // Record AIMD timing (single-variant callback type).
    if (function_exists('wpc_v2_record_handler_timing')) {
        wpc_v2_record_handler_timing('single', max(0.0, $inbound_to_complete_ms));
    }

    // HTML cache invalidation on variant landing, deferred to shutdown so the
    // 200 ACK isn't delayed. The throttle inside the helper coalesces a burst of
    // ~24 callbacks for one attachment into 1-3 purges.
    if (function_exists('wpc_v2_purge_html_for_attachment_deferred')) {
        wpc_v2_purge_html_for_attachment_deferred($imageID, 'v2-callback');
    }

    return wpc_v2_respond(200, ['ok' => true, 'kind' => 'persisted', 'bytes' => strlen($raw)]);
}

/**
 * Verify X-WPC-Sig: t=<unix_ts>,v1=<hex_hmac>. HMAC is over `t.<body_sha256>`
 * keyed with the site apikey. Default 60s replay window.
 *
 * $replay_window_s lets the /wake endpoint extend the window (it needs 300s)
 * without forking the helper; the 60s default keeps every other caller as-is.
 */
function wpc_v2_verify_hmac($sig_header, $body_raw, $replay_window_s = 60)
{
    if (!is_string($sig_header) || $sig_header === '') {
        return ['ok' => false, 'reason' => 'missing_sig'];
    }
    if (!function_exists('hash_hmac')) {
        return ['ok' => false, 'reason' => 'hash_hmac_unavailable'];
    }

    $parts = [];
    foreach (explode(',', $sig_header) as $kv) {
        $kv = trim($kv);
        $eq = strpos($kv, '=');
        if ($eq === false) continue;
        $parts[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
    }
    if (empty($parts['t']) || empty($parts['v1'])) {
        return ['ok' => false, 'reason' => 'malformed_sig'];
    }

    $ts = (int) $parts['t'];
    $now = time();
    if (abs($now - $ts) > (int) $replay_window_s) {
        return ['ok' => false, 'reason' => 'replay_window_exceeded'];
    }

    // Canonical key resolver (wpc_v2_get_apikey, in v2-capabilities.php).
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') {
        return ['ok' => false, 'reason' => 'plugin_no_apikey'];
    }

    $expected = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
    if (!hash_equals($expected, (string) $parts['v1'])) {
        return ['ok' => false, 'reason' => 'sig_mismatch'];
    }

    return ['ok' => true];
}

/**
 * Merge a variant entry into ic_local_variants under a MySQL GET_LOCK. Same
 * pattern as the v1 bg-swap callback, so a customer mid-drain across a v1→v2
 * upgrade has no merge collisions on the shared meta key.
 *
 * The per-callback ic_savings_* recompute used to run inside this lock (~24×
 * per drain, 5 DB ops each), serializing concurrent callbacks for the image.
 * Now removed: Phase A writes canonical savings, and a single drain-complete
 * recompute below refines them. In-lock work drops from ~5-7 DB ops to ~2,
 * which survives default-tuned PHP-FPM hosts without pm.max_children bumps.
 */
function wpc_v2_merge_variant($imageID, $variant_key, array $entry)
{
    global $wpdb;
    $lock_name = 'wpc_bg_meta_' . (int) $imageID;
    // 15s timeout (was 5s) — same lock name and race window as Phase A's
    // merge_variants; see its comment for the full rationale.
    $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
    $got_lock = ($got === '1' || $got === 1);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] wpc_v2_merge_variant lock_acquire_failed imageID=%d variant=%s — proceeding unlocked (race possible)', (int) $imageID, $variant_key));
    }

    try {
        // Concurrent callbacks write to the same image's ic_local_variants, so
        // each needs a fresh read from MySQL — without this flush a cached copy
        // makes the second write clobber the first. Cleared inside the GET_LOCK
        // so the read is serialized with concurrent writers.
        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];
        $existing[$variant_key] = array_merge($existing[$variant_key] ?? [], $entry);
        update_post_meta($imageID, 'ic_local_variants', $existing);
    } finally {
        if ($got_lock) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }
}

/**
 * Drain-complete savings refresh. Runs once per drain (when the last pending
 * variant lands), not per-callback. Outside the per-callback GET_LOCK so
 * callbacks don't serialize on it, but takes its own brief lock to avoid racing
 * Phase A's merge_variants. Reads ic_local_variants fresh, writes ic_savings_*.
 */
function wpc_v2_recompute_savings($imageID)
{
    if (!function_exists('wpc_compute_best_savings')) return;

    global $wpdb;
    $lock_name = 'wpc_bg_meta_' . (int) $imageID;
    // 15s (was 5s); see merge_variant for the race rationale.
    $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
    $got_lock = ($got === '1' || $got === 1);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] wpc_v2_recompute_savings lock_acquire_failed imageID=%d — proceeding unlocked', (int) $imageID));
    }

    try {
        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing) || empty($existing)) return;

        $best = wpc_compute_best_savings($existing, $imageID);
        if (!empty($best['orig']) && !empty($best['pct'])) {
            update_post_meta($imageID, 'ic_savings',          round((float) $best['pct'], 1));
            update_post_meta($imageID, 'ic_savings_format',   (string) $best['format']);
            update_post_meta($imageID, 'ic_savings_bytes',    (int) $best['orig'] - (int) $best['opt']);
            update_post_meta($imageID, 'ic_savings_baseline', (int) $best['orig']);
        }
    } finally {
        if ($got_lock) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }
}

function wpc_v2_record_no_improvement($imageID, $size_label, $format, $reason, array $body)
{
    $variant_key = wpc_v2_variant_key($size_label, $format);
    wpc_v2_merge_variant($imageID, $variant_key, [
        'bg_no_improvement'      => true,
        'no_improvement_reason'  => (string) $reason,
        'baseline_kb'            => isset($body['baselineKb']) ? (float) $body['baselineKb'] : 0.0,
        'widen_alt_kbs'          => isset($body['widenAltKbs']) && is_array($body['widenAltKbs']) ? $body['widenAltKbs'] : [],
        'phase_b_v2'             => true,
    ]);
}

/**
 * @return bool  true iff this call just emptied the pending list (drain just
 *               completed on THIS callback). Caller uses this to trigger the
 *               one-shot savings recompute. false in all other cases (still
 *               pending entries, no pending transient at all, or duplicate
 *               removal).
 */
function wpc_v2_remove_pending($imageID, $size_label, $format)
{
    $payload = get_transient('wpc_v2_pending_' . $imageID);
    if (!is_array($payload)) return false;

    // Nested schema is { jobId, pending: { key => {parent} } }. The legacy shape
    // was { key => true } at top level — detect and migrate it in place.
    if (!isset($payload['pending']) || !is_array($payload['pending'])) {
        $payload = [
            'jobId'       => '',
            'pending'     => $payload,   // legacy top-level becomes pending[]
            'recorded_at' => time(),
        ];
    }

    $key = wpc_v2_variant_key($size_label, $format);
    if (!isset($payload['pending'][$key])) return false;

    unset($payload['pending'][$key]);

    if (empty($payload['pending'])) {
        // Drain complete. Discard the whole transient (including jobId) — no
        // more polling needed; orch's GC reclaims its side at T+600s anyway.
        delete_transient('wpc_v2_pending_' . $imageID);
        // Drain-complete is the canonical "Phase B fully landed" signal. Set
        // phase_a_done defensively in case Phase A's promote_to_compressed never
        // ran (worker died after record_pending_variants, or the response was
        // dropped). pending was registered here, so record_pending_variants DID
        // run; without this, bulk's is_completed gate would stay false forever
        // even though every variant landed.
        set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
        return true;
    }

    set_transient('wpc_v2_pending_' . $imageID, $payload, 600);
    return false;
}

/**
 * Single canonical writer for ic_compressing.status. Merges into existing meta
 * rather than overwriting — critical because ic_compressing also carries
 * 'expected_variants' (set at Phase A dispatch). Older writers overwrote the
 * whole array and wiped it, so bulk's is_completed gate (accounted ≥ expected)
 * couldn't compute. Every status flip routes through here so it survives.
 */
function wpc_v2_ic_compressing_set_status($imageID, $new_status)
{
    $imageID = (int) $imageID;
    if ($imageID <= 0) return;
    $existing = get_post_meta($imageID, 'ic_compressing', true);
    if (!is_array($existing)) $existing = [];
    $existing['status'] = (string) $new_status;
    if (empty($existing['time'])) $existing['time'] = time();
    update_post_meta($imageID, 'ic_compressing', $existing);
}

/**
 * Store the expected variant count in ic_compressing.expected_variants — the
 * ground truth bulk's is_completed gate compares against. Called at dispatch;
 * prep_v2_optimize refines it later if Phase A's actual list differs (e.g.
 * smart-lazy filtered some widths out).
 */
function wpc_v2_ic_compressing_set_expected($imageID, $expected_variants)
{
    $imageID = (int) $imageID;
    $expected_variants = (int) $expected_variants;
    if ($imageID <= 0 || $expected_variants <= 0) return;
    $existing = get_post_meta($imageID, 'ic_compressing', true);
    if (!is_array($existing)) $existing = [];
    $existing['expected_variants'] = $expected_variants;
    update_post_meta($imageID, 'ic_compressing', $existing);
}

/**
 * Default expected-variant count from WP metadata, used at dispatch before the
 * async worker refines it with the actual Phase A list. Mirrors prep_v2_optimize:
 * (count(sub-sizes) + scaled + original) × format count.
 *
 * Sub-sizes, scaled, and original are three distinct things. Sub-sizes live in
 * $meta['sizes']. 'scaled' (the auto-downsized parent for sources over the big-
 * image threshold) and 'original' (the un-scaled source) do NOT — they're what
 * get_attached_file() and wp_get_original_image_path() return — so they're added
 * separately. Example: 6 sub-sizes × 3 formats = (6 + 1 + 1) × 3 = 24.
 */
function wpc_v2_compute_expected_variants($imageID, $format_count = 3)
{
    $imageID = (int) $imageID;
    if ($imageID <= 0) return 0;
    $meta = wp_get_attachment_metadata($imageID);
    if (!is_array($meta)) return 0;
    $subsizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];
    $subsize_count = count($subsizes);
    // Defensive: don't double-count if some plugin synthesizes a 'scaled' or
    // 'original' key inside $meta['sizes'].
    $has_scaled_in_subsizes   = isset($subsizes['scaled']);
    $has_original_in_subsizes = isset($subsizes['original']);
    $slots = $subsize_count
        + ($has_scaled_in_subsizes   ? 0 : 1)
        + ($has_original_in_subsizes ? 0 : 1);
    return $slots * max(1, (int) $format_count);
}

function wpc_v2_variant_key($size_label, $format)
{
    $size_label = (string) $size_label;
    $format     = strtolower((string) $format);
    if ($format === 'jpg') $format = 'jpeg';
    if ($format === 'jpeg') return $size_label;
    return $size_label . '-' . $format;
}

/**
 * Periodic cleanup of stale ic_compressing meta — stuck "queueing"/"optimizing"
 * states left behind when Phase A dispatched but the orch never responded (rate
 * limit / silent drop / FPM timeout), which otherwise pins ML cards on
 * "Optimizing" forever.
 *
 * Runs on admin_init, throttled to 5 min. Per pass: up to 50 rows in those
 * statuses, deleting any older than 10 min with no ic_local_variants and no
 * active pending. The 10-min floor avoids racing legitimate in-flight Phase A
 * on slow paths; this is the belt-and-suspenders for cases the JS bulk-loop
 * cleanup misses (closed tab, browser crash).
 */
function wpc_v2_cleanup_stale_compressing()
{
    // Throttle: once per 5 min.
    if (get_transient('wpc_v2_stale_cleanup_lock')) return;
    set_transient('wpc_v2_stale_cleanup_lock', 1, 5 * MINUTE_IN_SECONDS);

    // Query meta directly — WP_Query on a serialized meta value is fragile, so
    // grep the literal status strings. Cap 50 rows per pass.
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_key = 'ic_compressing'
           AND (meta_value LIKE '%\"status\";s:8:\"queueing\"%'
                OR meta_value LIKE '%\"status\";s:10:\"optimizing\"%')
         LIMIT 50"
    );
    if (empty($rows)) return;

    $now = time();
    $cleared = 0;
    foreach ($rows as $row) {
        $imageID = (int) $row->post_id;
        if ($imageID <= 0) continue;

        $ic = maybe_unserialize($row->meta_value);
        if (!is_array($ic)) continue;
        $status = (string) ($ic['status'] ?? '');
        $time   = (int)    ($ic['time']   ?? 0);
        if ($status !== 'queueing' && $status !== 'optimizing') continue;

        // 10-min floor — anything younger may still be legitimately in-flight.
        if ($time > 0 && ($now - $time) < (10 * MINUTE_IN_SECONDS)) continue;

        // Variants landed since dispatch → the eager-flip just hasn't run yet;
        // heartbeat owns the transition. Skip.
        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (is_array($variants) && !empty($variants)) continue;

        // Still a pending list → orch's callbacks may be in flight; let
        // wpc_v2_remove_pending clear it via real arrivals. Skip.
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (!empty($pending) && !empty($pending['pending'])) continue;

        delete_post_meta($imageID, 'ic_compressing');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wpc_v2_warming_' . $imageID);
        $cleared++;
    }

    if ($cleared > 0) {
        error_log(sprintf('[WPC StaleCleanup] cleared ic_compressing on %d stale image(s) (>10 min, no variants, no pending)', $cleared));
    }
}
add_action('admin_init', 'wpc_v2_cleanup_stale_compressing');

function wpc_v2_respond($status, array $body)
{
    return new WP_REST_Response($body, $status);
}

/**
 * Derive the on-disk basename for a Phase B callback when the encoder omits
 * `filename` (v2.2.0 contract gap). Sources:
 *   - `scaled` / `original` / `''` → strip ext from the WP attached file, append
 *     format's canonical extension. The WP attached file IS the parent.
 *   - sub-size labels → look up `$meta['sizes'][$label]['file']` (e.g.
 *     "photo-300x300.jpg") and swap the extension if format is webp/avif.
 * Returns '' if the size label is unknown — caller treats that as a 400.
 */
function wpc_v2_derive_variant_filename($abs_path, $imageID, $size_label, $format)
{
    $ext = ($format === 'jpeg' || $format === 'jpg') ? 'jpg' : strtolower($format);

    // `scaled` — WP attached file IS the scaled file. Use its basename.
    if ($size_label === 'scaled' || $size_label === '') {
        $base = basename($abs_path);
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        return substr($base, 0, $dot) . '.' . $ext;
    }

    // `original` — the un-scaled source. For images with WP-scaling there is
    // a separate file at wp_get_original_image_path(); for un-scaled images
    // the attached file IS the original.
    if ($size_label === 'original') {
        $orig_path = function_exists('wp_get_original_image_path')
            ? wp_get_original_image_path($imageID)
            : $abs_path;
        if (!$orig_path) $orig_path = $abs_path;
        $base = basename($orig_path);
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        return substr($base, 0, $dot) . '.' . $ext;
    }

    // Adaptive WxH labels: the filename is the label appended to the unscaled
    // stem — the same convention as the sized-trigger contract, so every
    // emitter/reader handles these unchanged.
    if (preg_match('/^\d{2,4}x\d{2,4}$/', (string) $size_label)) {
        $orig_p = ($imageID > 0 && function_exists('wp_get_original_image_path'))
            ? wp_get_original_image_path((int) $imageID) : '';
        if (!$orig_p) $orig_p = $abs_path;
        $b_a = basename($orig_p);
        $d_a = strrpos($b_a, '.');
        $stem_a = preg_replace('/-scaled$/', '', $d_a === false ? $b_a : substr($b_a, 0, $d_a));
        return $stem_a . '-' . $size_label . '.' . $ext;
    }

    // Sub-size — use the WP-registered filename, swap extension if non-JPEG.
    $meta = wp_get_attachment_metadata($imageID);
    if (!is_array($meta) || empty($meta['sizes'][$size_label]['file'])) {
        return '';
    }
    $sub = (string) $meta['sizes'][$size_label]['file'];
    $dot = strrpos($sub, '.');
    if ($dot === false) return $sub . '.' . $ext;
    return substr($sub, 0, $dot) . '.' . $ext;
}

/**
 * /wpc/v2/bg_swap_batch — batched Phase B callback endpoint.
 *
 * Companion to /wpc/v2/bg_swap. Accepts up to N variants per POST so the encoder
 * fleet can collapse per-image fan-out: 24 separate POSTs (24 bootstraps, 24
 * GET_LOCKs, 24 recomputes) become 1 POST → 1 bootstrap → 1 GET_LOCK → 1
 * recompute. Encoders gate this on an env flag (default OFF) and fall through to
 * the per-variant endpoint whenever it's off or a batch fails, so both stay live.
 *
 * Two batch shapes, both handled here:
 *   A) Orchestrator AVIF batch — each variant carries its own sizeLabel.
 *   B) JW pod jpeg+webp batch — variants share a wrapper-level sizeLabel;
 *      filename is a {jpeg: ..., webp: ...} map.
 *
 * Per-variant logic is duplicated inline in the loop rather than shared with the
 * single endpoint: that handler is ~280 lines of tightly-coupled state and
 * extracting it would mean either a duplicated wrapper or a 10-arg signature.
 *
 * The WPC_IS_BG_SWAP bootstrap-skip catches this route for free — the detector's
 * '/wp-json/wpc/v2/bg_swap' substring is a prefix of '...bg_swap_batch'.
 */
function wpc_v2_register_callback_batch_route()
{
    register_rest_route('wpc/v2', '/bg_swap_batch', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap_batch',
        'permission_callback' => '__return_true', // HMAC over batch body handles auth
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_callback_batch_route');

/**
 * Batch handler. Processes up to 25 variants in one POST under a single
 * GET_LOCK per imageID. Returns 200 with per-variant results[] even when some
 * items fail — orchestrator inspects results[] to know which to retry.
 */
function wpc_v2_handle_bg_swap_batch(WP_REST_Request $request)
{
    $entry_t = microtime(true);
    // AIMD: capture the FPM accept moment for inbound_to_complete_ms.
    $request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;
    $body_raw = $request->get_body();

    // Confirm the bootstrap-skip fired. If WPC_IS_BG_SWAP isn't defined here the
    // URL detector missed this route and we're eating ~5s of plugin init/batch.
    $bootstrap_skip = (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP);

    // HMAC verify over the BATCH body (not per-item).
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, (string) $body_raw);
    if (!$hmac_check['ok']) {
        error_log('[WPC V2BgSwapBatch] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }
    if (empty($body['variants']) || !is_array($body['variants'])) {
        return wpc_v2_respond(400, ['error' => 'missing_variants']);
    }
    $variants = $body['variants'];
    $variant_n = count($variants);
    if ($variant_n > 25) {
        return wpc_v2_respond(413, ['error' => 'batch_too_large', 'max' => 25, 'got' => $variant_n]);
    }

    $imageID = isset($body['imageID']) ? (int) $body['imageID'] : 0;
    if (!$imageID || get_post_type($imageID) !== 'attachment') {
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
    }
    $jobId        = isset($body['jobId']) ? (string) $body['jobId'] : '';
    $serverTime   = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
    $clockSkewMs  = $serverTime > 0 ? (int) round(($entry_t * 1000) - $serverTime) : null;
    $flushReason  = isset($body['flush_reason']) ? sanitize_key((string) $body['flush_reason']) : '';

    // Wrapper-level fallbacks used when variant entries omit them — the JW pod
    // batch shape puts sizeLabel + originalSize + filename on the wrapper.
    $wrap_size   = isset($body['sizeLabel']) ? sanitize_key((string) $body['sizeLabel']) : '';
    $wrap_orig   = isset($body['originalSize']) ? (int) $body['originalSize'] : 0;
    $wrap_parent = !empty($body['parent']);
    $wrap_fname  = isset($body['filename']) ? $body['filename'] : null;

    // Restored-image guard (same as the single endpoint). If the user hit
    // Restore mid-flight, the whole batch is rejected en bloc.
    if (get_transient('wpc_v2_callbacks_blocked_' . $imageID) !== false) {
        error_log(sprintf(
            '[WPC V2BgSwapBatch restored_reject] imageID=%d variants=%d job=%s',
            $imageID, $variant_n,
            $jobId !== '' ? substr($jobId, 0, 8) : '-'
        ));
        return wpc_v2_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
    }

    // Stale-job check at batch level — the whole batch shares one jobId. If
    // it's stale, every variant in the batch is rejected together.
    if ($jobId !== '') {
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
            error_log(sprintf(
                '[WPC V2BgSwapBatch stale_job] imageID=%d batch_job=%s pending_job=%s variants=%d',
                $imageID, substr($jobId, 0, 8), substr((string) $pending['jobId'], 0, 8), $variant_n
            ));
            return wpc_v2_respond(410, ['error' => 'stale_job', 'cb_jobId' => $jobId, 'pending_jobId' => (string) $pending['jobId']]);
        }
    }

    // Per-variant processing. Each iteration validates, decodes bytes, writes to
    // disk, builds an entry. Entries accumulate and the ic_local_variants merge
    // happens once after the loop under a single GET_LOCK. remove_pending fires
    // per-variant — it touches its own transient, not the lock-protected meta.
    $t_after_validate = microtime(true);
    $entries_to_merge = [];   // [variant_key => entry]
    $results          = [];   // per-variant status for the response
    $any_drain_complete = false;
    $persisted_count = $rejected_count = $duplicate_count = 0;

    // REST journal — when enabled, defer the ic_local_variants merge to the
    // drain chain. Collect entries in journal shape parallel to $entries_to_merge
    // so we can fall back to an inline merge if the journal write fails.
    $use_journal     = function_exists('wpc_v2_rest_journal_enabled') && wpc_v2_rest_journal_enabled();
    $journal_entries = [];

    // Pull delivery has three modes:
    //   Path B (journal + pull both on): skip the inline curl_multi; build
    //     'persisted_pending_bytes' journal entries. The long-lived journal drain
    //     does one cross-image curl_multi and writes bytes before merging.
    //   Path A (pull on, journal off): inline curl_multi here — but a fresh PHP
    //     process per batch means a cold TLS handshake each fire (~200-1500ms).
    //     The handler must write bytes in this same request, so it pulls now.
    //   Push (legacy bytesB64): base64 decode inline, no curl.
    // Path B wins because the drain reuses one connection across many pulls, so
    // the handshake cost amortizes to near-zero.
    $pulled_bytes = [];
    $pull_ms      = 0.0;
    $defer_pulls_to_drain = $use_journal
        && function_exists('wpc_v2_pull_delivery_enabled')
        && wpc_v2_pull_delivery_enabled();

    if (!$defer_pulls_to_drain && function_exists('wpc_v2_parallel_pull')) {
        // Path A: inline pull (only when journal off — handler must have bytes
        // before responding because there is no drain to fetch them later).
        $pulls_needed = [];
        $pull_meta    = [];
        foreach ($variants as $idx => $v) {
            if (!is_array($v) || !empty($v['bytesB64'])) continue;
            $url = '';
            if (!empty($v['fetchUrl']) && is_string($v['fetchUrl'])) {
                $url = (string) $v['fetchUrl'];
            } elseif (!empty($v['bytesUrl']) && is_string($v['bytesUrl'])) {
                $url = (string) $v['bytesUrl']; // transitional alias
            }
            if ($url === '') continue;
            $pulls_needed[$idx] = $url;
            $pull_meta[$idx] = [
                'size'   => isset($v['bytesSize'])   ? (int) $v['bytesSize']     : null,
                'sha256' => isset($v['bytesSha256']) ? (string) $v['bytesSha256'] : null,
            ];
        }
        if (!empty($pulls_needed)) {
            $t_pull_start = microtime(true);
            $pulled_bytes = wpc_v2_parallel_pull($pulls_needed, $pull_meta);
            $pull_ms = (microtime(true) - $t_pull_start) * 1000;
            error_log(sprintf(
                '[wpc_v2_pull] imageID=%d jobId=%s pulled=%d/%d wall_ms=%.1f mode=inline',
                $imageID,
                $jobId !== '' ? substr($jobId, 0, 8) : '-',
                count($pulled_bytes),
                count($pulls_needed),
                $pull_ms
            ));
        }
    }
    $pending_pull_count = 0;  // tally for telemetry (Path B variants deferred to drain)

    foreach ($variants as $idx => $v) {
        if (!is_array($v)) {
            $results[]       = ['ok' => false, 'kind' => 'rejected', 'error' => 'malformed_item', 'index' => $idx];
            $rejected_count++;
            continue;
        }

        // sizeLabel: per-variant > wrapper. format: per-variant only.
        $sz   = isset($v['sizeLabel']) ? sanitize_key((string) $v['sizeLabel']) : $wrap_size;
        $fmt  = isset($v['format']) ? strtolower(sanitize_key((string) $v['format'])) : '';
        if ($sz === '' || $fmt === '') {
            $results[]       = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_size_or_format', 'index' => $idx];
            $rejected_count++;
            continue;
        }
        if (!in_array($fmt, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
            $results[]       = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_format', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if ($fmt === 'jpg') $fmt = 'jpeg';

        // No-improvement signal — record + remove from pending, no disk write.
        if (!empty($v['noImprovement']) || (isset($v['bumped']) && (string) $v['bumped'] === 'source_already_optimal')) {
            $reason = !empty($v['noImprovement'])
                ? (isset($v['reason']) ? sanitize_text_field((string) $v['reason']) : 'no_improvement')
                : 'source_already_optimal';
            if ($use_journal) {
                $journal_entries[] = [
                    'sizeLabel'  => $sz,
                    'format'     => $fmt,
                    'type'       => 'no_improvement',
                    'reason'     => $reason,
                    'baselineKb' => isset($v['baselineKb']) ? (float) $v['baselineKb'] : 0.0,
                ];
            } else {
                wpc_v2_record_no_improvement($imageID, $sz, $fmt, $reason, $v);
            }
            if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
                $any_drain_complete = true;
            }
            $results[] = ['ok' => true, 'kind' => $reason === 'source_already_optimal' ? 'source_already_optimal' : 'no_improvement', 'sizeLabel' => $sz, 'format' => $fmt];
            $persisted_count++;
            continue;
        }

        // Filename: per-variant > wrapper.filename[fmt] (JW batch shape) >
        // derive from attachment metadata.
        //
        // CRITICAL: the wrapper filename fallback is keyed by FORMAT only, but
        // the wrapper itself describes ONE size ($wrap_size). When orch's
        // BatchedCallbackOutbox coalesces multiple JW pod callbacks (different
        // sizes, same format) into ONE mega-batch, the wrapper carries a
        // single filename per format. Using that fallback for variants of a
        // DIFFERENT size collides on disk — multiple variants write to the
        // same path, last-write-wins. Diagnosed during pull-mode joint test
        // when 14 JW variants reduced to ~3 unique filenames + the telltale
        // "JPEG Medium 52.65 KB = 0.0% savings" (medium_large's bytes
        // written into medium's slot). Only fall back when the per-variant
        // sizeLabel actually matches the wrapper's sizeLabel.
        $filename = '';
        if (isset($v['filename']) && is_string($v['filename'])) {
            $filename = basename((string) $v['filename']);
        } elseif ($sz === $wrap_size && is_array($wrap_fname) && isset($wrap_fname[$fmt])) {
            $filename = basename((string) $wrap_fname[$fmt]);
        }
        if ($filename === '') {
            $abs_parent_for_name = get_attached_file($imageID);
            if (!$abs_parent_for_name) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'parent_file_missing', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
            $filename = wpc_v2_derive_variant_filename($abs_parent_for_name, $imageID, $sz, $fmt);
            if ($filename === '') {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'filename_derive_failed', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
        }

        // Resolve bytes. Priority:
        //   0. PATH B DEFER — if $defer_pulls_to_drain AND variant has a URL,
        //      build a 'persisted_pending_bytes' entry directly. No bytes,
        //      no disk write here — drain owns those. Handler returns ~50ms
        //      regardless of variant count.
        //   1. $pulled_bytes[$idx] — pre-fetched via wpc_v2_parallel_pull
        //      (PATH A inline pull, only when journal off)
        //   2. base64 inline — PUSH mode (legacy contract)
        //   3. single-URL fetch fallback — only when no pre-fetch happened
        //   4. else: reject
        $b64       = isset($v['bytesB64']) ? (string) $v['bytesB64'] : '';
        $fetch_url = '';
        if (!empty($v['fetchUrl']) && is_string($v['fetchUrl'])) {
            $fetch_url = (string) $v['fetchUrl'];
        } elseif (!empty($v['bytesUrl']) && is_string($v['bytesUrl'])) {
            $fetch_url = (string) $v['bytesUrl']; // transitional alias
        }

        // PATH B short-circuit — build pending_bytes entry, skip the rest of
        // the bytes-resolution + disk-write + entries_to_merge dance entirely.
        if ($defer_pulls_to_drain && $fetch_url !== '' && $b64 === '') {
            $expected_size   = isset($v['bytesSize'])   ? (int) $v['bytesSize']     : 0;
            $expected_sha256 = isset($v['bytesSha256']) ? (string) $v['bytesSha256'] : '';
            $orig_size = isset($v['originalSize']) ? (int) $v['originalSize']
                        : (isset($v['orig_size']) ? (int) $v['orig_size'] : $wrap_orig);
            $kb        = isset($v['kb']) ? (float) $v['kb'] : 0.0;
            $butter    = isset($v['butter']) ? (float) $v['butter'] : 0.0;
            $abs_parent = get_attached_file($imageID);
            if (!$abs_parent) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'parent_file_missing', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
            $dest_dir = dirname($abs_parent);

            // Journal entry the drain will pull bytes for. Schema must include
            // everything the drain needs to (a) curl_multi the URL, (b) verify
            // integrity, (c) write bytes to disk + derive url for postmeta.
            $je = [
                'sizeLabel'      => $sz,
                'format'         => $fmt,
                'type'           => 'persisted_pending_bytes',
                'fetch_url'      => $fetch_url,
                'bytes_size'     => $expected_size,
                'bytes_sha256'   => $expected_sha256,
                'filename'       => $filename,
                'dest_dir'       => $dest_dir,
                'originalSize'   => $orig_size,
                'ms'             => (int) round(microtime(true) * 1000),
                'kb'             => $kb,
                'butter'         => $butter,
            ];
            if (isset($v['q']))      $je['q']      = (int) $v['q'];
            if (isset($v['bumped'])) $je['bumped'] = sanitize_text_field((string) $v['bumped']);
            $journal_entries[] = $je;

            if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
                $any_drain_complete = true;
            }

            // Inline ic_savings climb using service-supplied bytesSize. Plugin
            // doesn't have $raw here (drain pulls it) but the size comparison
            // is exact — service computes SHA-256 from the same buffer it
            // uploaded, so bytesSize is authoritative.
            if ($orig_size > 0 && $expected_size > 0) {
                $savings = max(0, (int) round((1 - ($expected_size / $orig_size)) * 100));
                $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
                if ((float) $savings > $cur_savings) {
                    update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                    update_post_meta($imageID, 'ic_savings_format',   $fmt);
                    update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $expected_size));
                    update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
                }
            }

            $results[] = ['ok' => true, 'kind' => 'persisted_pending_bytes', 'sizeLabel' => $sz, 'format' => $fmt, 'bytes' => $expected_size];
            $persisted_count++;
            $pending_pull_count++;
            continue;
        }

        $raw = null;
        if (isset($pulled_bytes[$idx]) && is_string($pulled_bytes[$idx]) && $pulled_bytes[$idx] !== '') {
            $raw = $pulled_bytes[$idx];
        } elseif ($b64 !== '') {
            $raw = base64_decode($b64, true);
            if ($raw === false) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_base64', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
        } elseif ($fetch_url !== '') {
            // Pull-mode variant that wasn't pre-fetched (e.g. curl_multi not
            // available on host, or pull failed and we want one last chance).
            // Sequential fallback: single wp_remote_get. If we hit this for
            // every variant in a batch, we'll be slow — but it's correct.
            $get = wp_remote_get($fetch_url, ['timeout' => 15]);
            if (is_wp_error($get) || (int) wp_remote_retrieve_response_code($get) !== 200) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'fetch_url_failed', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
            $raw = wp_remote_retrieve_body($get);
            if (!is_string($raw) || $raw === '') {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'fetch_url_empty', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
        } else {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_bytes_or_fetchUrl', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        if (function_exists('wpc_is_valid_image_bytes')
            && !wpc_is_valid_image_bytes($raw, $fmt, $imageID, 'bg_swap_v2_batch', ['size_label' => $sz])) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_image_bytes', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        // Atomic disk write (temp + rename). Idempotency fast-path: same bytes
        // already on disk → record as duplicate, mark pending removed, skip merge.
        $abs_parent = get_attached_file($imageID);
        if (!$abs_parent) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'parent_file_missing', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        $dest_dir = dirname($abs_parent);
        $dest     = $dest_dir . '/' . $filename;

        if (file_exists($dest) && filesize($dest) === strlen($raw) && hash_file('sha256', $dest) === hash('sha256', $raw)) {
            if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
                $any_drain_complete = true;
            }
            $results[] = ['ok' => true, 'kind' => 'idempotent_noop', 'sizeLabel' => $sz, 'format' => $fmt];
            $duplicate_count++;
            continue;
        }

        $tmp = $dest . '.wpc_v2_tmp_' . wp_generate_password(8, false);
        // Log silent disk-write failures in the batch path (same rationale
        // as the single-variant path).
        if (@file_put_contents($tmp, $raw) === false) {
            $err = error_get_last();
            error_log(sprintf(
                '[WPC V2Batch] write_failed imageID=%d sz=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                (int) $imageID, (string) $sz, (string) $fmt, strlen($raw),
                substr($dest, -60), $err['message'] ?? '-'
            ));
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'write_failed', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if (!@rename($tmp, $dest)) {
            $err = error_get_last();
            error_log(sprintf(
                '[WPC V2Batch] rename_failed imageID=%d sz=%s fmt=%s dest_tail=%s msg=%s',
                (int) $imageID, (string) $sz, (string) $fmt,
                substr($dest, -60), $err['message'] ?? '-'
            ));
            @unlink($tmp);
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'rename_failed', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if (!@chmod($dest, 0644)) {
            $err = error_get_last();
            error_log(sprintf(
                '[WPC V2Batch] chmod_failed imageID=%d dest_tail=%s msg=%s',
                (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
            ));
        }

        // Build entry. originalSize: per-variant > wrapper (JW batch puts it on wrapper).
        $orig_size = isset($v['originalSize']) ? (int) $v['originalSize']
                    : (isset($v['orig_size']) ? (int) $v['orig_size'] : $wrap_orig);
        $kb        = isset($v['kb']) ? (float) $v['kb'] : 0.0;
        $butter    = isset($v['butter']) ? (float) $v['butter'] : 0.0;
        $savings   = ($orig_size > 0) ? max(0, (int) round((1 - (strlen($raw) / $orig_size)) * 100)) : 0;
        $upload_dir = wp_get_upload_dir();
        $t0_ms     = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
        $entry_ms  = (int) round($entry_t * 1000);
        $from_click_ms = ($t0_ms > 0) ? max(0, $entry_ms - $t0_ms) : 0;

        $entry = [
            'size'              => strlen($raw),
            'originalSize'      => $orig_size,
            'url'               => $upload_dir['baseurl'] . '/' . ltrim(str_replace($upload_dir['basedir'], '', $dest), '/'),
            'local'             => true,
            'skipped'           => false,
            'savings'           => $savings,
            'bg_upgraded'       => time(),
            // Per-variant ms timestamp — preserves the variant-stream cursor
            // (since_ms) the bulk heartbeat uses to dedupe.
            'bg_upgraded_ms'    => (int) round(microtime(true) * 1000),
            'bg_t_from_click_ms' => $from_click_ms,
            'kb_reported'       => $kb,
            'butter'            => $butter,
            'phase_b_v2'        => true,
            'phase_b_batch'     => true,    // marker for telemetry
        ];
        if (isset($v['q']))      $entry['q']      = (int) $v['q'];
        if (isset($v['bumped'])) $entry['bumped'] = sanitize_text_field((string) $v['bumped']);
        if (isset($v['telemetry']) && is_array($v['telemetry'])) {
            $entry['telemetry'] = $v['telemetry'];
        }

        $variant_key = wpc_v2_variant_key($sz, $fmt);
        $entries_to_merge[$variant_key] = $entry;

        // Parallel collection for the journal path. Drain merge_for_image
        // (v2-direct-entry.php:311) reads this schema directly — keep field
        // names in lockstep with what it expects.
        if ($use_journal) {
            $je = [
                'sizeLabel'    => $sz,
                'format'       => $fmt,
                'type'         => 'persisted',
                'originalSize' => $orig_size,
                'bytes_size'   => strlen($raw),
                'bytes_path'   => $dest,
                'ms'           => (int) round(microtime(true) * 1000),
                'kb'           => $kb,
                'butter'       => $butter,
            ];
            if (isset($v['q']))      $je['q']      = (int) $v['q'];
            if (isset($v['bumped'])) $je['bumped'] = sanitize_text_field((string) $v['bumped']);
            $journal_entries[] = $je;
        }

        if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
            $any_drain_complete = true;
        }

        // Inline ic_savings climb per variant, matching the single endpoint at
        // wpc_v2_handle_bg_swap. The batch endpoint was missing it, so ic_savings
        // stayed frozen across the entire batched drain: recompute_savings only
        // fires on drain_complete, and drain_complete was never true on any batch
        // (per debug.log evidence). The chip and headline % then never updated
        // live until the orchestrator-paced drain somehow emptied pending.
        //
        // O(1) per variant — 1 get + up to 4 update_post_meta. Cheap, and it
        // restores the live climb.
        if ($orig_size > 0 && $savings > 0) {
            $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
            if ((float) $savings > $cur_savings) {
                $opt_bytes = strlen($raw);
                update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                update_post_meta($imageID, 'ic_savings_format',   $fmt);
                update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $opt_bytes));
                update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
            }
        }

        $results[] = ['ok' => true, 'kind' => 'persisted', 'sizeLabel' => $sz, 'format' => $fmt, 'bytes' => strlen($raw)];
        $persisted_count++;
    }

    $t_after_loop = microtime(true);

    // ONE GET_LOCK + ONE read + N merges + ONE write. This is the win that
    // separates the batch endpoint from N calls to the single endpoint —
    // instead of N GET_LOCK acquires + N read+writes serializing on the same
    // per-image lock, we do 1+1.
    //
    // With the REST journal flag on, defer the merge to the drain chain
    // instead. Drops handler time from 4-9 s (postmeta lock contention
    // under concurrent bulks) to ~30-50 ms. The drain side does the same
    // 1-lock-N-merges-1-write pattern, just outside the hot path. Falls back
    // to inline merge if the journal write fails — no silent variant drops.
    $journal_written = false;
    if ($use_journal && !empty($journal_entries)
        && function_exists('wpc_v2_journal_write_batch')) {
        $journal_written = wpc_v2_journal_write_batch($imageID, $jobId, $journal_entries, $flushReason);
        if ($journal_written) {
            // ALWAYS run inline drain after the response is sent. A loopback
            // HTTP fire (fsockopen → admin-ajax → drain) is unreliable on
            // restricted hosting (firewalls, admin-ajax auth friction) and
            // silently abandons journaled variants as stale. Reliable path:
            // a shutdown function fastcgi-finishes the response, then runs the
            // drain DIRECTLY in this same FPM worker (no network).
            // drain_run() is GET_LOCK protected so concurrent batches won't
            // double-drain. Loopback kept as a secondary, best-effort trigger.
            if (function_exists('wpc_v2_journal_fire_loopback_fast')) {
                wpc_v2_journal_fire_loopback_fast();
            }
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if (function_exists('wpc_v2_journal_drain_run')) {
                    // Direct call — same FPM worker continues after response.
                    // GET_LOCK in drain_run is short-timeout so if another
                    // drain is already running, this just returns quickly.
                    wpc_v2_journal_drain_run();
                }
            });
        }
    }
    if (!$journal_written && !empty($entries_to_merge)) {
        wpc_v2_merge_variants_batch($imageID, $entries_to_merge);
    }
    // Inline merge for no_improvement fallback when journal failed mid-batch
    // (persisted entries got merged via wpc_v2_merge_variants_batch above;
    // no_improvement entries were collected only into $journal_entries so we
    // have to flush them inline here when the journal write didn't happen).
    if ($use_journal && !$journal_written && !empty($journal_entries)) {
        foreach ($journal_entries as $je) {
            if (($je['type'] ?? '') === 'no_improvement') {
                wpc_v2_record_no_improvement(
                    $imageID,
                    (string) $je['sizeLabel'],
                    (string) $je['format'],
                    (string) ($je['reason'] ?? 'no_improvement'),
                    ['baselineKb' => (float) ($je['baselineKb'] ?? 0)]
                );
            }
        }
    }
    $t_after_merge = microtime(true);

    // Eager-flip block, present in the single endpoint (wpc_v2_handle_bg_swap)
    // but originally missing here. With BATCHED_CALLBACK_ENABLED=1 every variant
    // flows through the batch path, so eager_flip never fired and the card stayed
    // on "Optimizing…" until Phase A returned — which could be 5-10s after the
    // first variants landed.
    //
    // On the first batch that lands variants for an image whose status is
    // still 'optimizing', flip ic_compressing to 'compressed' so the JS
    // count poller's next tick reports status=compressed and triggers the
    // card swap. Idempotent — second batch's check sees status already
    // compressed and skips the flip.
    if ($persisted_count > 0) {
        $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
        $current_status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
            ? (string) $ic_compressing['status']
            : 'optimizing';
        $eager = function_exists('wpc_v2_use_eager_compressed_flip')
            && wpc_v2_use_eager_compressed_flip();
        if ($eager && $current_status !== 'compressed') {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');
            // compress_details (media_library_live.class.php) returns the
            // "Optimizing" card EARLY when this transient exists, regardless
            // of ic_compressing.status. Deleting it makes the next render
            // fall through to the compressed branch.
            delete_transient('wps_ic_compress_' . $imageID);
            error_log(sprintf(
                '[WPC V2BgSwap eager_flip] imageID=%d batched first-variant flip (batch_size=%d job=%s)',
                $imageID,
                $persisted_count,
                $jobId !== '' ? substr($jobId, 0, 8) : '-'
            ));
        }
        // First bytes have landed → clear the "warming" flag if the
        // announce-driven flip set one. JS pill crossfades out on next tick.
        if (get_transient('wpc_v2_warming_' . $imageID) !== false) {
            delete_transient('wpc_v2_warming_' . $imageID);
        }
    }

    // recompute_savings now fires under THREE conditions:
    //   1. $any_drain_complete (a variant in this batch landed the final
    //      pending entry — the original spec'd trigger)
    //   2. flush_reason === 'all_in' (orchestrator says this batch contains
    //      everything — safe assumption that drain is done)
    //   3. pending transient is now empty (covers the case where a CONCURRENT
    //      single-endpoint callback emptied pending before this batch's merge,
    //      so remove_pending in this batch never returned true even though the
    //      drain IS complete)
    //
    // Previously only #1 fired. We saw drain_complete=no across all 42 batches
    // today — recompute_savings never ran via the batch path, leaving
    // ic_savings stale until something else triggered a refresh. #2 + #3 are
    // the safety net.
    $should_recompute = $any_drain_complete
        || $flushReason === 'all_in';
    if (!$should_recompute && $persisted_count > 0) {
        $pending_now = get_transient('wpc_v2_pending_' . $imageID);
        if (!is_array($pending_now) || empty($pending_now['pending'])) {
            $should_recompute = true;
        }
    }
    // Journal mode: the drain handler (v2-direct-entry.php:411) fires
    // wpc_v2_recompute_savings itself via its own shutdown hook AFTER the
    // postmeta merge actually lands. Skip the inline path so we don't burn
    // FPM time recomputing against stale (pre-merge) ic_local_variants.
    if ($journal_written) {
        $should_recompute = false;
    }

    // FPM relief: defer recompute_savings until AFTER the response is
    // flushed + fastcgi_finish_request closes the client connection. Releases
    // the FPM worker for the next inbound callback ~20-50 ms earlier per batch.
    // On a 10-batch concurrent fire that's ~200-500 ms of cumulative worker
    // time freed up — meaningful headroom on low-FPM-worker hosts where this
    // pool is the dominant bottleneck.
    //
    // Why shutdown hook instead of inline: shutdown fires AFTER WP has written
    // the response to the output buffer. fastcgi_finish_request() inside the
    // hook tells PHP-FPM to flush + close-to-client + return-the-worker-to-pool,
    // even though our process keeps running to do the recompute.
    //
    // Falls back gracefully on non-FPM SAPIs (Apache mod_php, CLI) — the
    // function_exists check skips the flush call there, but the deferred
    // recompute still happens via shutdown. Net effect: same as today on those
    // hosts (no regression), big win on FPM (which is most modern PHP).
    if ($should_recompute) {
        $imageID_for_shutdown = $imageID;
        add_action('shutdown', function () use ($imageID_for_shutdown) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            wpc_v2_recompute_savings($imageID_for_shutdown);
        }, 0);
    } else if (function_exists('fastcgi_finish_request')) {
        // No recompute to defer, but still close the client connection ASAP
        // so the worker can serve the next request while WP runs its shutdown
        // hooks (cron, transient cleanup, etc.).
        add_action('shutdown', function () { fastcgi_finish_request(); }, 0);
    }

    $t_handler_end = microtime(true);
    $total_ms      = ($t_handler_end - $entry_t) * 1000;
    $bootstrap_ms  = 0; // The plugin's WPC_IS_BG_SWAP early-return makes this
                        // effectively zero; surfaced as a field for service-team
                        // correlation with orch.batch_callback_delivery_split.
    $validate_ms   = ($t_after_validate - $entry_t) * 1000;
    $loop_ms       = ($t_after_loop - $t_after_validate) * 1000;
    $merge_ms      = ($t_after_merge - $t_after_loop) * 1000;
    $recompute_ms  = ($t_handler_end - $t_after_merge) * 1000;

    // AIMD: inbound_to_complete_ms includes FPM queue wait.
    $inbound_to_complete_ms = ($t_handler_end - $request_arrival_t) * 1000;

    // Per-batch correlation log line — matches the format the service team
    // proposed in the handoff doc so orch traces line up with WP debug.log.
    error_log(sprintf(
        '[wpc_v2_bg_swap_batch_timing] imageID=%d jobId=%s variant_count=%d persisted=%d rejected=%d duplicates=%d drain_complete=%s flush_reason=%s clock_skew_ms=%s bootstrap_skip=%s validate_ms=%.1f loop_ms=%.1f merge_ms=%.1f recompute_ms=%.1f total_handler_ms=%.1f inbound_to_complete_ms=%.1f cap=%d journal=%s journal_entries=%d pull_ms=%.1f pulled=%d pending_pull=%d',
        $imageID,
        $jobId !== '' ? substr($jobId, 0, 8) : '-',
        $variant_n,
        $persisted_count,
        $rejected_count,
        $duplicate_count,
        $any_drain_complete ? 'yes' : 'no',
        $flushReason !== '' ? $flushReason : '-',
        $clockSkewMs === null ? 'n/a' : (string) $clockSkewMs,
        $bootstrap_skip ? 'yes' : 'NO',
        max(0.0, $validate_ms),
        max(0.0, $loop_ms),
        max(0.0, $merge_ms),
        max(0.0, $recompute_ms),
        max(0.0, $total_ms),
        max(0.0, $inbound_to_complete_ms),
        function_exists('wpc_v2_ac_get_cap') ? wpc_v2_ac_get_cap('batch') : 0,
        $journal_written ? 'yes' : ($use_journal ? 'fallback' : 'off'),
        count($journal_entries),
        max(0.0, $pull_ms),
        count($pulled_bytes),
        $pending_pull_count
    ));

    // Record AIMD timing (batch callback type).
    if (function_exists('wpc_v2_record_handler_timing')) {
        wpc_v2_record_handler_timing('batch', max(0.0, $inbound_to_complete_ms));
    }

    // FPM telemetry — opt-in rolling buffer. Captures inbound_to_complete_ms
    // (REQUEST_TIME_FLOAT → handler return, includes FPM queue wait), split
    // into queue_wait_ms and work_ms so we can tell FPM saturation apart from
    // slow handler code (e.g. a slow encoder pull).
    if (function_exists('wpc_v2_telemetry_record')) {
        $queue_wait_ms = (int) round(max(0.0, ($entry_t - $request_arrival_t) * 1000));
        $work_ms       = (int) round(max(0.0, ($t_handler_end - $entry_t) * 1000));
        wpc_v2_telemetry_record('batch', (int) round(max(0.0, $inbound_to_complete_ms)), [
            'image_id'      => $imageID,
            'variant_count' => $variant_n,
            'persisted'     => $persisted_count,
            'queue_wait_ms' => $queue_wait_ms,
            'work_ms'       => $work_ms,
            'flush_reason'  => $flushReason,
        ]);
    }

    return wpc_v2_respond(200, [
        'ok'      => true,
        'imageID' => $imageID,
        'jobId'   => $jobId,
        'summary' => [
            'persisted'  => $persisted_count,
            'rejected'   => $rejected_count,
            'duplicates' => $duplicate_count,
        ],
        'results' => $results,
    ]);
}

/**
 * Batch merge variant — one GET_LOCK, one fresh meta read, N merges, one write.
 * Mirrors wpc_v2_merge_variant's lock/cache-flush semantics but amortizes them
 * across all variants in the batch. $entries is keyed by variant_key.
 */
function wpc_v2_merge_variants_batch($imageID, array $entries)
{
    if (empty($entries)) return;

    global $wpdb;
    $lock_name = 'wpc_bg_meta_' . (int) $imageID;
    // 15s lock timeout: the batch path is the heaviest writer (N variants in
    // one transaction), so under bulk drain its lock queue grows the deepest.
    $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
    $got_lock = ($got === '1' || $got === 1);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] wpc_v2_merge_variants_batch lock_acquire_failed imageID=%d entries=%d — proceeding unlocked (race possible)', (int) $imageID, count($entries)));
    }

    try {
        // Same fresh-read pattern as wpc_v2_merge_variant — concurrent writers
        // (e.g. mid-flight single-variant callback for the same image) must
        // see our writes, and we must see theirs.
        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];
        foreach ($entries as $variant_key => $entry) {
            $existing[$variant_key] = array_merge($existing[$variant_key] ?? [], $entry);
        }
        update_post_meta($imageID, 'ic_local_variants', $existing);
    } finally {
        if ($got_lock) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  /wpc/v2/bg_swap_announce — display-state notification endpoint.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Companion to /wpc/v2/bg_swap_batch. Carries per-variant METADATA ONLY
 *  (no bytes) as encoder variants complete. Powers the live "Recently
 *  Optimized" feed in the bulk UI so users see pending pills land within
 *  ~250 ms of variant encode-complete, instead of waiting ~5-10 s for
 *  the bytes batch to flush.
 *
 *  Architectural separation:
 *    • ic_local_variants (postmeta)        ← ground truth, batch endpoint writes
 *    • wpc_v2_announced_$id (transient)    ← display state, this endpoint writes
 *  Heartbeat merges both into new_variants[], display state marked pending:true.
 *  Display can be ahead of ground truth; they converge when bytes batch lands.
 *
 *  CRITICAL: route URL MUST contain the substring 'bg_swap' so the existing
 *  WPC_IS_BG_SWAP detector in wp-compress.php catches it for free (~25 ms
 *  per request instead of ~800 ms full WP bootstrap). DO NOT rename to
 *  'bg_announce' — that breaks the substring match and turns this endpoint
 *  into a per-notify bootstrap eater. See SPEC-bg_swap_announce.md §2.
 *
 *  Contract: fire-and-forget. No retry on 5xx — display state degradation
 *  is acceptable; the batch endpoint owns correctness. See SPEC §3
 *  "Retry semantics" for the joint decision.
 */
function wpc_v2_register_announce_route()
{
    register_rest_route('wpc/v2', '/bg_swap_announce', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap_announce',
        'permission_callback' => '__return_true', // HMAC over batch body handles auth
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_announce_route');

/**
 * Announce handler. Reads ic_local_variants ONCE (Gap 3 race guard — silently
 * discards announces for variants the bytes-batch already persisted), merges
 * remaining items into the wpc_v2_announced_$id transient, returns per-item
 * results. Bulk heartbeat will pick them up + render as pending pills on the
 * next 1.5 s tick.
 */
function wpc_v2_handle_bg_swap_announce(WP_REST_Request $request)
{
    $entry_t  = microtime(true);
    // AIMD: capture the FPM accept moment for inbound_to_complete_ms.
    // See docs/SPEC-adaptive_concurrency.md §3 "CRITICAL — what we measure".
    $request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;
    $body_raw = $request->get_body();

    // Confirm bootstrap-skip — must be 'yes', else the URL naming is wrong.
    $bootstrap_skip = (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP);

    // HMAC verify over the batch body (same scheme as bg_swap + bg_swap_batch).
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, (string) $body_raw);
    if (!$hmac_check['ok']) {
        error_log('[WPC V2BgSwapAnnounce] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }
    if (empty($body['items']) || !is_array($body['items'])) {
        return wpc_v2_respond(400, ['error' => 'missing_items']);
    }
    $items   = $body['items'];
    $item_n  = count($items);
    if ($item_n > 25) {
        return wpc_v2_respond(413, ['error' => 'batch_too_large', 'max' => 25, 'got' => $item_n]);
    }

    $imageID = isset($body['imageID']) ? (int) $body['imageID'] : 0;
    if (!$imageID || get_post_type($imageID) !== 'attachment') {
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
    }
    $jobId       = isset($body['jobId']) ? (string) $body['jobId'] : '';
    $serverTime  = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
    $clockSkewMs = $serverTime > 0 ? (int) round(($entry_t * 1000) - $serverTime) : null;

    // Restored-image guard: same as batch endpoint. If user hit Restore mid-flight,
    // every announce for this image gets dropped — the corresponding bytes batch
    // will also be rejected; no pending pill, no ghost UI state.
    if (get_transient('wpc_v2_callbacks_blocked_' . $imageID) !== false) {
        error_log(sprintf(
            '[WPC V2BgSwapAnnounce restored_reject] imageID=%d items=%d job=%s',
            $imageID, $item_n,
            $jobId !== '' ? substr($jobId, 0, 8) : '-'
        ));
        return wpc_v2_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
    }

    // Stale-job check (batch-level — whole notify shares one jobId).
    if ($jobId !== '') {
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
            error_log(sprintf(
                '[WPC V2BgSwapAnnounce stale_job] imageID=%d announce_job=%s pending_job=%s items=%d',
                $imageID, substr($jobId, 0, 8), substr((string) $pending['jobId'], 0, 8), $item_n
            ));
            return wpc_v2_respond(410, ['error' => 'stale_job', 'cb_jobId' => $jobId, 'pending_jobId' => (string) $pending['jobId']]);
        }
    }

    // Gap 3 setup — read ic_local_variants ONCE so we can discard items the
    // bytes-batch already persisted. Reading meta inside the per-item loop
    // would be O(N) reads; one upfront read is O(1) regardless of item count.
    $persisted = get_post_meta($imageID, 'ic_local_variants', true);
    if (!is_array($persisted)) $persisted = [];

    // Read announce transient — append-merge new items. Race acknowledged:
    // two concurrent announces for the same image could read-modify-write
    // and one set of items could be lost. Worst case = UI misses brief pending
    // state for a few variants; bytes batch still delivers correctly. Per
    // spec §3 "Retry semantics" rationale, this is acceptable degradation.
    $announced = get_transient('wpc_v2_announced_' . $imageID);
    if (!is_array($announced)) $announced = [];

    $now_ms  = (int) round(microtime(true) * 1000);
    $now_s   = time();
    $results = [];
    $announced_count = $discarded_count = $rejected_count = 0;

    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'malformed_item', 'index' => $idx];
            $rejected_count++;
            continue;
        }
        $sz  = isset($item['sizeLabel']) ? sanitize_key((string) $item['sizeLabel']) : '';
        $fmt = isset($item['format']) ? strtolower(sanitize_key((string) $item['format'])) : '';
        if ($sz === '' || $fmt === '') {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_size_or_format', 'index' => $idx];
            $rejected_count++;
            continue;
        }
        if (!in_array($fmt, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_format', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if ($fmt === 'jpg') $fmt = 'jpeg';

        // Spec §5 positive-only enforcement (belt + suspenders to orch's two
        // upstream layers). An announce item with ok===false AND no
        // noImprovement signal is a service-side bug — orch's outbox gate +
        // JW pod caller filter both reject this shape. If one slips through,
        // log loudly + 200 (don't break service team's fire-and-forget
        // contract), and skip the item rather than record a phantom pending
        // pill the UI can't reconcile.
        $item_ok      = isset($item['ok']) ? (bool) $item['ok'] : true;
        $item_no_impr = !empty($item['noImprovement']);
        if (!$item_ok && !$item_no_impr) {
            error_log(sprintf(
                '[WPC V2BgSwapAnnounce spec_violation] imageID=%d job=%s sizeLabel=%s format=%s ok=false_without_noImprovement',
                $imageID, $jobId !== '' ? substr($jobId, 0, 8) : '-', $sz, $fmt
            ));
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'spec_violation_ok_false_no_no_improvement', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        $variant_key = wpc_v2_variant_key($sz, $fmt);

        // Gap 3 guard — bytes batch already landed this variant. Silent discard,
        // observable via response kind so service team can correlate against
        // orch:announce_flushed timing if expectations diverge.
        if (isset($persisted[$variant_key])) {
            $results[] = ['ok' => true, 'kind' => 'discarded_already_persisted', 'sizeLabel' => $sz, 'format' => $fmt];
            $discarded_count++;
            continue;
        }

        // Compute display-side savings from announced metadata. orig_size and
        // kb (encoded KB) are encoder-reported; matches the batch endpoint's
        // savings math so the pending pill shows the same % that will appear
        // when the persisted version replaces it.
        $orig_size  = isset($item['originalSize']) ? (int) $item['originalSize'] : 0;
        $kb         = isset($item['kb']) ? (float) $item['kb'] : 0.0;
        $bytes_est  = (int) round($kb * 1024);
        $savings    = ($orig_size > 0 && $bytes_est > 0)
                      ? max(0, (int) round((1 - ($bytes_est / $orig_size)) * 100))
                      : 0;
        $item_ms    = isset($item['ms']) ? (int) $item['ms'] : $now_ms;
        $no_improv  = !empty($item['noImprovement']);

        $announced[$variant_key] = [
            'sizeLabel'      => $sz,
            'format'         => $fmt,
            'kb'             => $kb,
            'originalSize'   => $orig_size,
            'bytes_est'      => $bytes_est,
            'savings'        => $savings,
            'noImprovement'  => $no_improv,
            'reason'         => isset($item['reason']) ? sanitize_text_field((string) $item['reason']) : '',
            'announced_ms'   => $item_ms,
            // Per-entry 30s TTL (SPEC §6 refinement). Heartbeat filters
            // expired entries from new_variants[] so stuck-pending pills
            // self-dismiss if the bytes batch never lands.
            'expires_at'     => $now_s + 30,
        ];
        $results[] = [
            'ok'        => true,
            'kind'      => $no_improv ? 'announced_no_improvement' : 'announced',
            'sizeLabel' => $sz,
            'format'    => $fmt,
        ];
        $announced_count++;
    }

    // Announce-driven eager_flip. The first announce arrives ~T0+1s
    // carrying metadata only (kb, originalSize, savings — no bytes on disk
    // yet). Flip the card to Compressed NOW so the user sees instant
    // feedback. Warming transient persists in case any UI needs it.
    // Cleared by both bg_swap (single) and bg_swap_batch on first persist.
    //
    // CRITICAL ORDER: do the meta writes (status + savings seed) BEFORE
    // writing the announce transient. A concurrent variant_count poll
    // that catches the handler mid-flight must see EITHER the old state
    // (no flip, no announced chips) OR the new state (flip + seeded
    // savings + announced chips) — never the in-between of "announced
    // chips visible but still status=optimizing" which causes chips to
    // fire on the un-swapped Optimizing card.
    $eager_flip_done = false;
    if ($announced_count > 0
        && function_exists('wpc_v2_use_eager_compressed_flip')
        && wpc_v2_use_eager_compressed_flip()) {
        $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
        $current_status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
            ? (string) $ic_compressing['status']
            : 'optimizing';

        // Compute best announced savings from THIS batch + any already in
        // the (about-to-be-written) transient. Used to seed ic_savings so
        // the card title shows the real % the moment it flips.
        $best_announced = null;
        foreach ($announced as $a_vkey => $a_entry) {
            if (!empty($a_entry['noImprovement'])) continue;
            $a_sv = isset($a_entry['savings']) ? (float) $a_entry['savings'] : 0.0;
            $a_orig = isset($a_entry['originalSize']) ? (int) $a_entry['originalSize'] : 0;
            if ($a_sv <= 0 || $a_orig <= 0) continue;
            if ($best_announced === null || $a_sv > $best_announced['sv']) {
                $best_announced = [
                    'sv'    => $a_sv,
                    'fmt'   => isset($a_entry['format']) ? (string) $a_entry['format'] : '',
                    'orig'  => $a_orig,
                    'bytes' => isset($a_entry['bytes_est']) ? (int) $a_entry['bytes_est'] : 0,
                ];
            }
        }
        // Seed ic_savings BEFORE the flip so the first poll to see
        // status=compressed already has the real % from ic_savings.
        if ($best_announced !== null) {
            $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
            if ($best_announced['sv'] > $cur_savings) {
                update_post_meta($imageID, 'ic_savings',          round($best_announced['sv'], 1));
                update_post_meta($imageID, 'ic_savings_format',   $best_announced['fmt']);
                update_post_meta($imageID, 'ic_savings_bytes',    max(0, $best_announced['orig'] - $best_announced['bytes']));
                update_post_meta($imageID, 'ic_savings_baseline', $best_announced['orig']);
            }
        }
        // Then flip status. Both writes happen BEFORE the transient set
        // below, so a concurrent poll catching this mid-handler sees:
        // either (no flip + no transient) OR (flip + seeded savings +
        // transient). Both are coherent.
        if ($current_status !== 'compressed') {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');
            delete_transient('wps_ic_compress_' . $imageID);
            set_transient('wpc_v2_warming_' . $imageID, 1, 90);
            $eager_flip_done = true;
            error_log(sprintf(
                '[WPC V2BgSwapAnnounce eager_flip] imageID=%d announce-driven first-flip (items=%d job=%s)',
                $imageID, $announced_count,
                $jobId !== '' ? substr($jobId, 0, 8) : '-'
            ));
        }
    }

    // Transient TTL is 5 minutes (covers a long bulk's worst-case lifetime).
    // Written LAST so a concurrent poll never sees chips-before-flip.
    set_transient('wpc_v2_announced_' . $imageID, $announced, 5 * MINUTE_IN_SECONDS);

    // FPM relief: close the client connection ASAP after WP flushes the response.
    // No deferred work needed here (announce handler is purely a transient write),
    // but releasing the worker early still helps the inbound queue clear faster.
    // Same shutdown-hook pattern as the batch handler.
    if (function_exists('fastcgi_finish_request')) {
        add_action('shutdown', function () { fastcgi_finish_request(); }, 0);
    }

    $t_handler_end = microtime(true);
    $total_ms = ($t_handler_end - $entry_t) * 1000;
    // AIMD: inbound_to_complete_ms includes FPM queue wait.
    $inbound_to_complete_ms = ($t_handler_end - $request_arrival_t) * 1000;

    // Telemetry: pairs 1:1 with orch:announce_flushed (SPEC §6 Clarification D).
    // Grep `bootstrap_skip=NO` post-deploy as the naming-regression watchdog (SPEC §12).
    error_log(sprintf(
        '[wpc_v2_bg_swap_announce_timing] imageID=%d jobId=%s item_count=%d announced=%d discarded=%d rejected=%d clock_skew_ms=%s bootstrap_skip=%s total_handler_ms=%.1f inbound_to_complete_ms=%.1f cap=%d',
        $imageID,
        $jobId !== '' ? substr($jobId, 0, 8) : '-',
        $item_n,
        $announced_count,
        $discarded_count,
        $rejected_count,
        $clockSkewMs === null ? 'n/a' : (string) $clockSkewMs,
        $bootstrap_skip ? 'yes' : 'NO',
        max(0.0, $total_ms),
        max(0.0, $inbound_to_complete_ms),
        function_exists('wpc_v2_ac_get_cap') ? wpc_v2_ac_get_cap('announce') : 0
    ));

    // Record AIMD timing (announce callback type).
    if (function_exists('wpc_v2_record_handler_timing')) {
        wpc_v2_record_handler_timing('announce', max(0.0, $inbound_to_complete_ms));
    }

    return wpc_v2_respond(200, [
        'ok'      => true,
        'imageID' => $imageID,
        'jobId'   => $jobId,
        'summary' => [
            'announced' => $announced_count,
            'discarded' => $discarded_count,
            'rejected'  => $rejected_count,
        ],
        'results' => $results,
    ]);
}

/**
 * Resolve image_id from origin_path using WP's _wp_attached_file postmeta.
 * Transient-cached to avoid repeated DB queries during a Phase B drain wave.
 * Cache invalidated on the delete_attachment hook.
 *
 * @param string $origin_path  e.g. "2026/04/image.jpg" (no leading slash)
 * @return int  attachment_id, or 0 if unresolved
 */
function wpc_v2_resolve_imageid_from_origin_path($origin_path)
{
    $origin_path = ltrim((string) $origin_path, '/');
    if ($origin_path === '') return 0;

    // Strip query string / fragment if any made it through.
    $origin_path = (string) strtok($origin_path, '?#');

    // Cache key: md5 of normalized path. 1h TTL.
    $cache_key = 'wpc_v2_path2id_' . md5($origin_path);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return (int) $cached;
    }

    global $wpdb;
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_wp_attached_file' AND meta_value = %s
         LIMIT 1",
        $origin_path
    ));

    // If not found via exact match, try without -scaled suffix (some lookup
    // patterns might pass the -scaled.jpg attached file URL).
    if ($id <= 0 && strpos($origin_path, '-scaled.') !== false) {
        $unscaled = preg_replace('/-scaled(\.[^.]+)$/', '$1', $origin_path);
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value = %s
             LIMIT 1",
            $unscaled
        ));
    }

    set_transient($cache_key, $id, HOUR_IN_SECONDS);
    return (int) $id;
}

/**
 * Resolve a WP sizeLabel from (width, height). Width+height usually
 * uniquely identifies the registered size. The multi-match tie-breaker uses
 * requested_path filename hints (-scaled, -WxH suffixes).
 *
 * Falls back to synthetic `lazy-WxH` label so variants are still usable
 * even for non-standard widths (CDN-only sizes).
 *
 * @param int $imageID
 * @param int $width
 * @param int $height
 * @param string $requested_path  Optional tie-breaker hint
 * @return string  sizeLabel ('medium', 'large', 'scaled', 'original', or synthetic 'lazy-WxH')
 */
function wpc_v2_resolve_sizelabel_from_dims($imageID, $width, $height, $requested_path = '')
{
    $width = (int) $width;
    $height = (int) $height;

    // Tie-breaker: requested filename contains -scaled → scaled label.
    if ($requested_path !== '' && preg_match('/-scaled\.[^.]+$/i', $requested_path)) {
        return 'scaled';
    }

    $meta = wp_get_attachment_metadata($imageID);
    if (empty($meta['sizes']) || !is_array($meta['sizes'])) {
        // No registered sub-sizes — could be original.
        if ((int) ($meta['width'] ?? 0) === $width && (int) ($meta['height'] ?? 0) === $height) {
            return 'original';
        }
        return 'lazy-' . $width . 'x' . $height;
    }

    // Primary: exact (width, height) match.
    $matches = [];
    foreach ($meta['sizes'] as $label => $info) {
        if ((int) ($info['width'] ?? 0) === $width && (int) ($info['height'] ?? 0) === $height) {
            $matches[$label] = $info;
        }
    }
    if (count($matches) === 1) {
        return (string) array_key_first($matches);
    }

    // Multi-match tie-breaker via requested_path filename.
    if (count($matches) > 1 && $requested_path !== '') {
        $req_base = pathinfo($requested_path, PATHINFO_FILENAME);
        foreach ($matches as $label => $info) {
            $size_base = pathinfo($info['file'] ?? '', PATHINFO_FILENAME);
            if ($size_base !== '' && strpos($req_base, $size_base) !== false) {
                return $label;
            }
        }
        return (string) array_key_first($matches); // arbitrary first match
    }

    // No exact match. If matches the un-scaled attachment dims, it's 'original'.
    if ((int) ($meta['width'] ?? 0) === $width && (int) ($meta['height'] ?? 0) === $height) {
        return 'original';
    }

    // Synthetic fallback for non-standard widths.
    return 'lazy-' . $width . 'x' . $height;
}

/**
 * Telemetry capture for lazy_cdn callbacks. Uses the same FPM telemetry
 * transient pipeline as the heartbeat/batch streams.
 */
function wpc_v2_record_lazy_cdn_outcome($outcome, $arrival_t, array $meta = [])
{
    if (!function_exists('wpc_v2_telemetry_record')) return;
    $ms = (int) round((microtime(true) - $arrival_t) * 1000);
    wpc_v2_telemetry_record('lazy_cdn_callback', $ms, array_merge(['outcome' => $outcome], $meta));
}

/**
 * Single-variant callback handler. Called by Local Service after a
 * CDN-triggered on-demand encode. Validates HMAC + payload + path safety,
 * resolves image_id, pulls bytes, writes to disk, merges into postmeta.
 *
 * Telemetry captures every outcome (success/auth/path/resolution/pull/write
 * failure) so ops can see what's working and where failures come from.
 */
function wpc_v2_handle_bg_swap_single(WP_REST_Request $request)
{
    $arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : microtime(true);

    $body_raw = $request->get_body();
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, (string) $body_raw);
    if (!$hmac_check['ok']) {
        wpc_v2_record_lazy_cdn_outcome('auth_rejected_' . $hmac_check['reason'], $arrival_t, []);
        error_log('[WPC LazyCDNCallback] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        wpc_v2_record_lazy_cdn_outcome('invalid_json', $arrival_t, []);
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }

    $origin_path    = isset($body['origin_path'])    ? (string) $body['origin_path']    : '';
    $requested_path = isset($body['requested_path']) ? (string) $body['requested_path'] : '';
    $width          = isset($body['width'])          ? (int) $body['width']             : 0;
    $height         = isset($body['height'])         ? (int) $body['height']            : 0;
    $format         = isset($body['format'])         ? sanitize_key((string) $body['format']) : '';
    $bytes_url      = isset($body['bytes_url'])      ? (string) $body['bytes_url']      : '';
    $original_size  = isset($body['original_size'])  ? (int) $body['original_size']     : 0;

    // Normalize format alias.
    if ($format === 'jpg') $format = 'jpeg';

    // Required fields.
    if ($origin_path === '' || $width <= 0 || $height <= 0 || $bytes_url === ''
        || !in_array($format, ['jpeg', 'webp', 'avif', 'png'], true)) {
        wpc_v2_record_lazy_cdn_outcome('missing_fields', $arrival_t, [
            'origin_path' => $origin_path, 'format' => $format,
            'width' => $width, 'height' => $height,
        ]);
        return wpc_v2_respond(400, ['error' => 'missing_required_fields']);
    }

    // Path traversal safety. Hard reject anything fishy in origin_path.
    if (strpos($origin_path, '..') !== false
        || strpos($origin_path, "\0") !== false
        || strpos($origin_path, '://') !== false
        || strpos($origin_path, '\\') !== false
        || preg_match('#^/#', $origin_path)) {
        wpc_v2_record_lazy_cdn_outcome('path_traversal_rejected', $arrival_t, ['origin_path' => $origin_path]);
        error_log('[WPC LazyCDNCallback] path_traversal_rejected origin_path=' . $origin_path);
        return wpc_v2_respond(400, ['error' => 'invalid_origin_path']);
    }

    // The caller passes image_id when known, to skip a DB lookup on the hot
    // path; when absent (first-ever encode) we fall back to the origin_path →
    // image_id resolver. When hinted, validate: positive int, resolves to an
    // existing attachment, not in trash. Failed validation → 410 unknown_image
    // (same as the fallback resolver's miss).
    $resolution_start = microtime(true);
    $imageID = 0;
    $imageID_source = 'fallback';
    $hinted_imageID = isset($body['image_id']) ? (int) $body['image_id'] : 0;
    if ($hinted_imageID > 0) {
        $hinted_post = get_post($hinted_imageID);
        if ($hinted_post instanceof WP_Post
            && $hinted_post->post_type === 'attachment'
            && $hinted_post->post_status !== 'trash') {
            $imageID = $hinted_imageID;
            $imageID_source = 'ls_hint';
        }
    }
    if ($imageID <= 0) {
        $imageID = wpc_v2_resolve_imageid_from_origin_path($origin_path);
    }
    if ($imageID <= 0) {
        wpc_v2_record_lazy_cdn_outcome('image_id_unresolved', $arrival_t, [
            'origin_path'    => $origin_path,
            'hinted'         => $hinted_imageID,
            'imageID_source' => $imageID_source,
        ]);
        // 410 Gone → Local Service should stop retrying for this attachment.
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'origin_path' => $origin_path]);
    }

    // Resolve sizeLabel.
    $sizeLabel = wpc_v2_resolve_sizelabel_from_dims($imageID, $width, $height, $requested_path);
    $resolution_ms = (int) round((microtime(true) - $resolution_start) * 1000);

    // Idempotency check. If variant already on disk + bg_upgraded=Y, skip.
    $variant_key = ($format === 'jpeg') ? $sizeLabel : ($sizeLabel . '-' . $format);
    $existing = get_post_meta($imageID, 'ic_local_variants', true);
    if (is_array($existing) && !empty($existing[$variant_key]['bg_upgraded'])) {
        wpc_v2_record_lazy_cdn_outcome('already_present', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'size_label' => $sizeLabel, 'format' => $format,
        ]);
        return wpc_v2_respond(200, ['ok' => true, 'already_present' => true,
            'imageID' => $imageID, 'sizeLabel' => $sizeLabel, 'format' => $format]);
    }

    // Pull bytes.
    $pull_start = microtime(true);
    $resp = wp_remote_get($bytes_url, [
        'timeout'   => 30,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
    ]);
    $pull_ms = (int) round((microtime(true) - $pull_start) * 1000);
    if (is_wp_error($resp)) {
        wpc_v2_record_lazy_cdn_outcome('pull_failed_transport', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'pull_ms' => $pull_ms, 'error' => $resp->get_error_message(),
        ]);
        return wpc_v2_respond(503, ['error' => 'pull_failed', 'retry_after' => 30]);
    }
    $http_code = (int) wp_remote_retrieve_response_code($resp);
    if ($http_code !== 200) {
        wpc_v2_record_lazy_cdn_outcome('pull_failed_http_' . $http_code, $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'pull_ms' => $pull_ms, 'http_code' => $http_code,
        ]);
        return wpc_v2_respond(503, ['error' => 'pull_failed', 'http_code' => $http_code]);
    }
    $bytes = (string) wp_remote_retrieve_body($resp);
    $bytes_size = strlen($bytes);
    if ($bytes_size <= 0) {
        wpc_v2_record_lazy_cdn_outcome('pull_failed_empty', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
        ]);
        return wpc_v2_respond(503, ['error' => 'empty_body']);
    }

    // Construct output filename per WP convention.
    $original_filename = basename($origin_path);
    $base = preg_replace('/\.[^.]+$/', '', $original_filename);
    // Strip -scaled if present in original_path (we want the un-suffixed base).
    $base = preg_replace('/-scaled$/', '', $base);
    $ext = ($format === 'jpeg') ? 'jpg' : $format;
    // For 'original' sizeLabel, no WxH suffix.
    if ($sizeLabel === 'original') {
        $output_filename = $base . '.' . $ext;
    } elseif ($sizeLabel === 'scaled') {
        $output_filename = $base . '-scaled.' . $ext;
    } else {
        $output_filename = $base . '-' . $width . 'x' . $height . '.' . $ext;
    }

    // Output path. Same dir as the original.
    $upload_dir = wp_get_upload_dir();
    $rel_dir = dirname($origin_path);
    $rel_dir = ($rel_dir === '.' || $rel_dir === '') ? '' : trailingslashit($rel_dir);
    $output_path = trailingslashit($upload_dir['basedir']) . $rel_dir . $output_filename;
    $output_url  = trailingslashit($upload_dir['baseurl'])  . $rel_dir . $output_filename;

    // Disk write — atomic via temp+rename.
    $write_start = microtime(true);
    $tmp_path = $output_path . '.wpc_v2_tmp_' . wp_generate_password(8, false);
    $written = @file_put_contents($tmp_path, $bytes);
    if ($written === false) {
        wpc_v2_record_lazy_cdn_outcome('disk_write_failed', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'output_path' => $output_path,
        ]);
        error_log('[WPC LazyCDNCallback] disk_write_failed imageID=' . $imageID . ' path=' . $output_path);
        return wpc_v2_respond(503, ['error' => 'disk_write_failed']);
    }
    if (!@rename($tmp_path, $output_path)) {
        @unlink($tmp_path);
        wpc_v2_record_lazy_cdn_outcome('disk_rename_failed', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
        ]);
        return wpc_v2_respond(503, ['error' => 'disk_rename_failed']);
    }
    @chmod($output_path, 0644);
    $write_ms = (int) round((microtime(true) - $write_start) * 1000);

    // Merge into ic_local_variants via existing lock-protected helper.
    if (function_exists('wpc_v2_merge_variant')) {
        wpc_v2_merge_variant($imageID, $variant_key, [
            'sizeLabel'      => $sizeLabel,
            'format'         => $format,
            'size'           => $bytes_size,
            'originalSize'   => $original_size > 0 ? $original_size : null,
            'savings'        => ($original_size > 0)
                                  ? max(0, (int) round((1 - ($bytes_size / $original_size)) * 100))
                                  : 0,
            'bg_upgraded'    => 'lazy_cdn',
            'bg_upgraded_ms' => (int) round(microtime(true) * 1000),
        ]);
    }

    // Telemetry — success. Records imageID_source so production can verify what
    // % of callbacks use the LS-provided hint (Q3 closure) vs the DB-lookup
    // fallback. Useful for deciding when (if ever) to ship the plugin-side Redis
    // cache layer for the fallback resolver.
    wpc_v2_record_lazy_cdn_outcome('success', $arrival_t, [
        'image_id'       => $imageID,
        'imageID_source' => $imageID_source,
        'size_label'     => $sizeLabel,
        'format'         => $format,
        'bytes_size'     => $bytes_size,
        'original_size'  => $original_size,
        'resolution_ms'  => $resolution_ms,
        'pull_ms'        => $pull_ms,
        'write_ms'       => $write_ms,
    ]);

    return wpc_v2_respond(200, [
        'ok'         => true,
        'imageID'    => $imageID,
        'sizeLabel'  => $sizeLabel,
        'format'     => $format,
        'bytes_size' => $bytes_size,
        'url'        => $output_url,
    ]);
}

// Invalidate the image_id resolution cache on attachment delete.
add_action('delete_attachment', function ($attachment_id) {
    $file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if ($file) {
        delete_transient('wpc_v2_path2id_' . md5(ltrim((string) $file, '/')));
    }
});

} // function_exists guard
