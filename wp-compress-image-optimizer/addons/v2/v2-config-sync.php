<?php
/**
 * Plugin → orch /v2/config sync.
 *
 * Endpoint: POST {orchestrator_url}/v2/config
 *   Body:    {"apikey":"...","zones":[{"zone_id":"...","lazy_enabled":bool}]}
 *   Header:  X-WPC-Sig: t=<unix_seconds>,v1=<hmac-sha256-hex>
 *   HMAC:    hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey)
 *
 * Orch persists to agencySites.lazy_cdn_active; the CDN reads it via apikeyCache
 * (60s TTL). Default OFF, so accidental rollout is structurally impossible.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fold the stored quality value to the orch's canonical enum {lossless,
 * intelligent, ultra}. The `optimization` field may hold the long form, the
 * short transform codes (l/i/u), or the legacy 'maximum' alias for the top
 * tier — normalize so the natural-URL backfill `level:` matches the transform
 * path. Unknown → intelligent (matches the transform switch's default).
 *
 * @param mixed $raw  Stored optimization value.
 * @return string     One of {lossless, intelligent, ultra}.
 */
if (!function_exists('wpc_v2_normalize_quality')) {
    function wpc_v2_normalize_quality($raw)
    {
        switch (strtolower(trim((string) $raw))) {
            case 'lossless':
            case 'l':
                return 'lossless';
            case 'ultra':
            case 'u':
            case 'maximum':   // legacy alias
                return 'ultra';
            case 'intelligent':
            case 'i':
            default:
                return 'intelligent';
        }
    }
}

/**
 * This site's STANDING image-delivery config for the zone payload — feeds
 * orch's natural-URL AVIF/WebP backfill.
 *
 * max_width must be the static wps_ic_settings['maxWidth'] (the standing
 * ceiling), NOT getCurrentMaxWidth() — that's the per-request adaptive
 * resolver (400 on mobile, 1 on excluded), wrong for a zone-wide config.
 *
 * @return array  ['local_quality' => string, 'local_max_width' => int]
 */
if (!function_exists('wpc_v2_local_image_config')) {
    function wpc_v2_local_image_config()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings)) {
            $settings = [];
        }

        $quality = wpc_v2_normalize_quality($settings['optimization'] ?? 'intelligent');

        $max_width = (int) ($settings['maxWidth'] ?? 0);
        if ($max_width <= 0) {
            $max_width = 2560; // plugin default
        }

        return [
            'local_quality'   => $quality,
            'local_max_width' => $max_width,
        ];
    }
}

if (!function_exists('wpc_v2_delivery_config')) {
    /**
     * Delivery + edge-config fields synced to orch so it can provision the right per-customer
     * Edge Rule set (the signed x-wpc-config header-injection contract). The edge doesn't
     * negotiate format — it strips visitor identity headers and injects the trusted x-wpc-config;
     * cdn-mc reads Accept + that blob and serves the right format. The plugin's only job here is
     * to advertise WHICH rule set this customer needs.
     *
     *   - delivery_mode: natural_url (clean URLs + cdn-mc Accept-negotiation) vs transform_url
     *     (legacy /m:N/a: URLs). Mirrors the plugin's actual emission path.
     *   - host_lock_mode / host_lock_allow: which Host(s) the signed x-wpc-config is honoured for
     *     (anti cross-customer replay). Always advertised so the contract is stable; defaults off.
     */
    function wpc_v2_delivery_config()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings)) {
            $settings = [];
        }

        $natural = (class_exists('WPC_Negotiated_Delivery')
            && method_exists('WPC_Negotiated_Delivery', 'emission_ready')
            && WPC_Negotiated_Delivery::emission_ready());

        $hl_mode = isset($settings['host_lock_mode']) ? sanitize_key((string) $settings['host_lock_mode']) : 'off';
        if (!in_array($hl_mode, ['off', 'lock', 'allow'], true)) {
            $hl_mode = 'off';
        }
        $hl_allow = isset($settings['host_lock_allow']) ? sanitize_text_field((string) $settings['host_lock_allow']) : '';

        // Byte-source intent for the edge (REDIRECT_TARGET). 'origin' = edge does only the
        // per-Accept 302 and the local origin serves the bytes (no CDN bandwidth); 'samehost'
        // = the CDN/CF serves them. Gated on the wpc_edge_origin_bytes opt-in AND a zone being
        // present. Plain get_option (no resolver-class dep) so it's safe in REST/cron.
        $edge_origin = !empty($settings['wpc_edge_origin_bytes']) && (string) $settings['wpc_edge_origin_bytes'] === '1';
        $has_zone    = function_exists('get_option')
                       && (!empty(get_option('ic_cdn_zone_name')) || !empty(get_option('ic_custom_cname')));
        $redirect_target = ($edge_origin && $has_zone) ? 'origin' : 'samehost';

        // Per-site analytics/credits surface.
        //   nextgen / images_on — user INTENT (billing-grade; both feed the maybe_sync
        //     change-sig so a flip re-syncs immediately).
        //   tier — the resolver's current verified mechanism (advisory: it flaps around
        //     re-verifies, so never bill on it — bill on intent).
        //   cf_detected — sticky 7-day "CF seen on an inbound request". The deferred sync
        //     runs in cron context where CF headers can be absent even on CF-fronted sites,
        //     so a request-time check would flap false.
        $nextgen = isset($settings['wpc_nextgen']) ? (string) $settings['wpc_nextgen'] : 'auto';
        if (!in_array($nextgen, ['auto', 'webp', 'off'], true)) $nextgen = 'auto';
        $images_on = !class_exists('WPC_Negotiated_Delivery')
            || !method_exists('WPC_Negotiated_Delivery', 'cdn_images_enabled')
            || WPC_Negotiated_Delivery::cdn_images_enabled($settings);
        $tier = '';
        if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
            $rv_dc = WPC_Delivery_Resolver::resolve_verbose(); // cached read — no probe
            $tier  = isset($rv_dc['tier_name']) ? (string) $rv_dc['tier_name'] : '';
        }
        if (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR'])) {
            update_option('wpc_v2_cf_seen_ts', time(), false);
        }
        $cf_seen_ts  = (int) get_option('wpc_v2_cf_seen_ts', 0);
        $cf_detected = $cf_seen_ts > 0 && (time() - $cf_seen_ts) < 7 * DAY_IN_SECONDS;

        // CDN probe hints: zone-inventory facts the plugin already knows, relayed via the
        // signed blob so a cold rung skips ~2.5-3s of origin inventory discovery. Advisory
        // — a wrong/stale hint costs one extra round-trip (the pod's hint-distrust pass),
        // never a 404. Value-gated on the pod; inert until pods advertise probe_hints:1.
        $hints = wpc_v2_compute_probe_hints();

        // CF-CNAME host→site identity. A CF-fronted custom CNAME passes the customer's CDN host
        // through to the pod, which resolves Host→agencySites.cname. There's no signed x-wpc-config
        // blob to carry identity on this path, so without the cname row the pod returns
        // "Site not found" and natural URLs 404. The orch extracts zones[].cname → UPDATEs the row.
        //
        // The cname MUST be the host the plugin actually EMITS (byte-identical to cdn-rewrite's
        // zone_name): a CF-fronted zone (CF cdn ON + a CF cname) emits via WPS_IC_CF_CNAME, NOT
        // ic_custom_cname. Reading the CF-blind chain on a CF zone writes the zapwp host into the
        // cname row and the real Host never matches.
        //
        // DELIBERATELY no ic_cdn_zone_name fallback: a Bunny-PZ zone name is not a valid cname.
        // Send a cname ONLY for a real custom/CF host; a plain Bunny zapwp zone resolves by
        // zone_name (orch has it) and sends '' (orch skips an empty value, leaving zone_name intact).
        $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
        $cfs = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if ($cfc !== '' && is_array($cfs) && !empty($cfs['settings']['cdn'])) {
            $cname = $cfc;                                   // CF-delivered → the real CF emit host
        } else {
            $cname = trim((string) get_option('ic_custom_cname')); // a real custom CNAME, else ''
        }

        // writes_variants: does this zone LAND optimized variants on origin disk? Smart Delivery (lazy_cdn,
        // backfill) + user local-compress (local) do; cdn/legacy/resize-on-demand do not. The edge gates the
        // landed-sibling probe on this — false → skip the probe entirely → straight to OTF (no futile slow-404
        // that trips the breaker). Per-zone; wpsz/lzf below refine it per-width/format INSIDE a writing zone.
        $opt_mode = function_exists('get_option') ? (string) get_option('wpc_optimization_mode', '') : '';
        $writes_variants = in_array($opt_mode, ['lazy_cdn', 'local'], true);

        return [
            'site_url'        => function_exists('site_url') ? site_url() : '',
            'cname'           => $cname,             // CF-CNAME identity (orch: z.cname → agencySites.cname)
            'delivery_mode'   => $natural ? 'natural_url' : 'transform_url',
            'writes_variants' => $writes_variants,   // zone lands optimized variants (lazy_cdn|local) → edge probes the landed sibling; false → skip → OTF
            'host_lock_mode'  => $hl_mode,
            'host_lock_allow' => $hl_allow,
            'redirect_target' => $redirect_target,   // samehost | origin
            'nextgen'         => $nextgen,           // off | webp | auto (user intent)
            'tier'            => $tier,              // resolved mechanism (advisory)
            'images_on'       => (bool) $images_on,  // Images tile master switch (intent)
            'cf_detected'     => (bool) $cf_detected, // CF-fronted (sticky 7d)
            'srcx'            => $hints['srcx'],     // source-original exts (csv; '' = offload, fail-fast)
            'wpsz'            => $hints['wpsz'],     // registered WP subsize widths (csv) — width∉set ⇒ no sized sibling
            'lzf'             => $hints['lzf'],      // formats lazy backfill writes to disk (csv)
        ];
    }
}

if (!function_exists('wpc_v2_compute_probe_hints')) {
    /**
     * Returns ['srcx'=>csv, 'wpsz'=>csv, 'lzf'=>csv]. Zero scanning — every value is a
     * known config/registration fact. Filterable so a non-standard-storage host can override.
     * (Empty srcx is allowed and meaningful — see below.)
     */
    function wpc_v2_compute_probe_hints()
    {
        // wpsz — registered WP subsize WIDTHS. A requested width NOT in this set is a
        // plugin-invented (slot×DPR) rung → no sized jpg/png sibling can exist → pod skips
        // exact-sized source rungs.
        $widths = [];
        if (function_exists('wp_get_registered_image_subsizes')) {
            foreach ((array) wp_get_registered_image_subsizes() as $sz) {
                if (!empty($sz['width'])) $widths[] = (int) $sz['width'];
            }
        } elseif (function_exists('get_intermediate_image_sizes')) {
            // Pre-5.3 fallback — derive widths from the global size table.
            global $_wp_additional_image_sizes;
            foreach ((array) get_intermediate_image_sizes() as $name) {
                $w = (int) get_option($name . '_size_w');
                if ($w <= 0 && isset($_wp_additional_image_sizes[$name]['width'])) {
                    $w = (int) $_wp_additional_image_sizes[$name]['width'];
                }
                if ($w > 0) $widths[] = $w;
            }
        }
        $widths = array_values(array_unique(array_filter($widths)));
        sort($widths, SORT_NUMERIC);

        // srcx — source-original extensions on this origin (safe superset, zero scan).
        // Offloaded/remote uploads → empty, so the pod fails fast instead of walking a
        // relay that can't reach the originals. Detected via a non-local uploads host.
        $srcx = 'jpg,png';
        if (function_exists('wp_get_upload_dir') && function_exists('home_url')) {
            $ud = wp_get_upload_dir();
            $up_host  = !empty($ud['baseurl']) ? wp_parse_url($ud['baseurl'], PHP_URL_HOST) : '';
            $org_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($up_host && $org_host && strcasecmp($up_host, $org_host) !== 0) {
                $srcx = ''; // offloaded originals — relay can't fetch them; fail fast
            }
        }

        // lzf — formats the lazy backfill actually WRITES to disk. The consolidated Images
        // tile no longer stores per-format serve flags; the envelope's format SET is what the
        // backfill encodes (both webp + avif when modern formats are on). The effective
        // ceiling narrows it to webp-only when avif is gated off.
        $set = function_exists('get_option') && defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        $set = is_array($set) ? $set : [];
        $lzf = [];
        $formats_on = (string) get_option('wpc_envelope_formats_v2', '1') === '1';
        $images_on  = empty($set['serve']) || !is_array($set['serve'])
            ? false
            : (!empty($set['serve']['jpg']) || !empty($set['serve']['png']));
        if ($formats_on && $images_on) {
            $lzf[] = 'webp'; // the envelope always writes webp when modern formats are on
            // avif unless an explicit ceiling forbids it.
            $ceiling = '';
            if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'effective_ceiling')) {
                $ceiling = (string) WPC_Delivery_Resolver::effective_ceiling($set);
            }
            if ($ceiling !== 'webp') $lzf[] = 'avif';
        }
        $lzf = array_values(array_unique($lzf));

        $out = [
            'srcx' => (string) $srcx,
            'wpsz' => implode(',', $widths),
            'lzf'  => implode(',', $lzf),
        ];
        $out = (array) apply_filters('wpc_v2_probe_hints', $out, $set);

        // Producer-side cap discipline. The blob packs srcx into a 32-char field, and an
        // overflow silently drops the LAST token on the orch side — a quietly-wrong hint.
        // srcx is a safe-superset, so on overflow send EMPTY (one hint-distrust walk, never
        // a 404), not a truncated list. Today's emitter only sends 'jpg,png' so this never
        // fires; it keeps any future full-ext-list emission cap-safe by construction.
        $srcx_cap = (int) apply_filters('wpc_v2_srcx_cap', 32);
        if (isset($out['srcx']) && strlen((string) $out['srcx']) > $srcx_cap) {
            $out['srcx'] = ''; // overflow → safe-superset empty, not a truncated half-list
        }
        return $out;
    }
}

if (!function_exists('wpc_v2_upload_base_paths')) {
    /**
     * The site's image base path(s) as root-relative prefixes (e.g. /wp-content/uploads, or a
     * remapped /storage). Lets the orch/edge resolve "is a u:<origin> path one of this site's
     * image roots?" without a probe, and where a custom store lives. Derived from
     * wp_get_upload_dir() (reflects a remapped uploads dir); always also includes the standard
     * /wp-content/uploads so theme/plugin assets resolve even when uploads are remapped.
     * Filterable for offload/multisite stacks.
     */
    function wpc_v2_upload_base_paths()
    {
        $paths = [];
        if (function_exists('wp_get_upload_dir')) {
            $ud = wp_get_upload_dir();
            $baseurl = (is_array($ud) && !empty($ud['baseurl'])) ? (string) $ud['baseurl'] : '';
            if ($baseurl !== '' && function_exists('wp_parse_url')) {
                $p = (string) wp_parse_url($baseurl, PHP_URL_PATH);
                if ($p !== '') $paths[] = '/' . trim($p, '/');
            }
        }
        $paths[] = '/wp-content/uploads'; // standard fallback (covers themes/plugins even if uploads are remapped)
        $paths[] = '/storage';            // common page-builder / Cloudways offloaded-media base (filterable below)
        $paths = array_values(array_unique(array_filter($paths)));
        return (array) apply_filters('wpc_v2_upload_paths', $paths);
    }
}

/**
 * Cheap managed-host detection for fleet segmentation. Mirrors the canonical checks in
 * integrations/hosting/*.php but WITHOUT instantiating the full ~30-class conflict sweep — every
 * lookup is a constant/class/global check (no I/O, no plugin load). Returns a short slug or ''.
 */
if (!function_exists('wpc_v2_detect_host')) {
    function wpc_v2_detect_host()
    {
        if (class_exists('WpeCommon')) return 'wpengine';
        if (isset($GLOBALS['kinsta_cache'])) return 'kinsta';
        if (defined('FLYWHEEL_CONFIG_DIR')) return 'flywheel';
        if (function_exists('pantheon_wp_clear_edge_all')) return 'pantheon';
        if (class_exists('\\WPaas\\Plugin')) return 'godaddy';
        $ab = defined('ABSPATH') ? ABSPATH : '';
        $dr = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        if (strpos($ab, 'cloudwaysapps.com') !== false || strpos($dr, 'cloudwaysapps.com') !== false) return 'cloudways';
        if (function_exists('is_plugin_active') && is_plugin_active('sg-cachepress/sg-cachepress.php')) return 'siteground';
        return '';
    }
}

/**
 * Fire-and-forget activate/deactivate signal to the orch, riding the existing /v2/config endpoint +
 * HMAC auth (no separate metrics endpoint/key needed). NON-BLOCKING + short timeout so it never delays
 * the admin action. No-op without an apikey (a freshly-installed, unlinked site has no identity to
 * attribute). The orch reads body.event (same whitelist pattern as plugin_version) — harmless/ignored
 * until it does.
 *
 * @param string $event 'activated' | 'deactivated'
 */
if (!function_exists('wpc_v2_report_lifecycle_event')) {
    function wpc_v2_report_lifecycle_event($event)
    {
        if ($event !== 'activated' && $event !== 'deactivated') return;
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch_url === '') return;

        $body_payload = ['apikey' => $apikey, 'event' => $event];
        if (defined('WPC_PLUGIN_VERSION')) {
            $body_payload['plugin_version'] = (string) WPC_PLUGIN_VERSION;
        }
        $body_raw = wp_json_encode($body_payload);
        if ($body_raw === false) return;
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);

        // No ?sync=1 (don't await provisioning — it's just an event). blocking=false so activate/deactivate
        // return instantly even if the orch is slow/down (we just removed a blocking admin call — never add one).
        wp_remote_post(rtrim($orch_url, '/') . '/v2/config', [
            'timeout'     => 2,
            'blocking'    => false,
            'redirection' => 0,
            'sslverify'   => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.1'),
            ],
            'body' => $body_raw,
        ]);
    }
}

// Lifecycle hooks: report activate/deactivate once, at the moment it happens (precise net activations
// for the fleet digest). Both no-op without an apikey. register_*_hook is safe from this addon — it's
// loaded on the normal admin request that processes activation/deactivation (same pattern as
// v2-direct-entry.php's existing deactivation hook).
if (defined('WPC_CC_PLUGIN_FILE')) {
    register_activation_hook(WPC_CC_PLUGIN_FILE, function () {
        wpc_v2_report_lifecycle_event('activated');
    });
    register_deactivation_hook(WPC_CC_PLUGIN_FILE, function () {
        wpc_v2_report_lifecycle_event('deactivated');
    });
}

/**
 * Sync the lazy_cdn enable state for one or more zones to the orch.
 *
 * @param array $zones  Array of ['zone_id' => string, 'lazy_enabled' => bool]
 *                      entries. Single-zone caller typically passes one entry.
 * @return array  ['ok' => bool, 'http_code' => int, 'reason' => string?]
 */
if (!function_exists('wpc_v2_config_sync_zones')) {
    function wpc_v2_config_sync_zones(array $zones)
    {
        if (empty($zones)) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'no_zones_provided'];
        }

        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch_url === '') {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'plugin_misconfigured'];
        }

        // Standing image-delivery config rides on every zone. It's site-global, so read it
        // once and stamp the same values onto each entry. Orch reads local_quality +
        // local_max_width for natural-URL backfill (falls back to intelligent/2560 if absent).
        $img_cfg = wpc_v2_local_image_config();
        $del_cfg = wpc_v2_delivery_config(); // delivery_mode + host_lock + site_url

        // Normalize entries (zone_id required, lazy_enabled → strict bool) and reject
        // malformed ones BEFORE signing, so we never sign garbage.
        $clean = [];
        foreach ($zones as $z) {
            if (!is_array($z) || empty($z['zone_id'])) continue;
            $entry = [
                'zone_id'         => (string) $z['zone_id'],
                'site_url'        => $del_cfg['site_url'],
                'lazy_enabled'    => !empty($z['lazy_enabled']),
                'local_quality'   => $img_cfg['local_quality'],
                'local_max_width' => $img_cfg['local_max_width'],
                'delivery_mode'   => $del_cfg['delivery_mode'],   // natural_url|transform_url
                'writes_variants' => $del_cfg['writes_variants'], // lands optimized variants (lazy_cdn|local) → edge probes landed sibling; else skip→OTF
                'host_lock_mode'  => $del_cfg['host_lock_mode'],
                'host_lock_allow' => $del_cfg['host_lock_allow'],
                'redirect_target' => $del_cfg['redirect_target'], // samehost|origin (edge byte-source)
                'nextgen'         => $del_cfg['nextgen'],         // user intent off|webp|auto
                'tier'            => $del_cfg['tier'],            // resolved mechanism (advisory — don't bill on it)
                'images_on'       => $del_cfg['images_on'],       // Images tile master switch (intent)
                'cf_detected'     => $del_cfg['cf_detected'],     // CF-fronted, sticky 7-day
                // Probe hints: let the orch/edge resolve custom image base paths + the origin format
                // without a probe. srcx/wpsz/lzf come from $del_cfg; upload_paths is the site's real
                // image base path(s). Additive; a pre-receiver orch ignores unknown keys.
                'srcx'         => isset($del_cfg['srcx']) ? $del_cfg['srcx'] : '', // source-original exts (jpg,png | '' offloaded)
                'wpsz'         => isset($del_cfg['wpsz']) ? $del_cfg['wpsz'] : '', // registered WP subsize widths (csv)
                'lzf'          => isset($del_cfg['lzf']) ? $del_cfg['lzf'] : '',   // formats lazy backfill writes (csv)
                'upload_paths' => function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : [],
            ];
            // CF-CNAME identity. Include ONLY when non-empty: the orch writes any defined value,
            // so sending '' would CLEAR a good cname. Omitting the key → orch skips the write,
            // preserving zone_name resolution for Bunny zones.
            if ($del_cfg['cname'] !== '') {
                $entry['cname'] = $del_cfg['cname'];
            }
            $clean[] = $entry;
        }
        if (empty($clean)) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'no_valid_zones'];
        }
        if (count($clean) > 100) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'too_many_zones'];
        }

        // Advertise the wake-ping URL explicitly so orch routes lazy_cdn manifest
        // notifications to the correct REST endpoint (the Phase B bg_swap callback URL it
        // otherwise reused expects a different payload shape). Falls back to a derived URL if absent.
        $wake_url = function_exists('rest_url') ? rest_url('wpc/v2/wake') : '';

        // Sign the EXACT bytes that get transmitted — re-encoding after signing breaks the HMAC.
        $body_payload = [
            'apikey' => $apikey,
            'zones'  => $clean,
        ];
        // Service-stats / fleet signals (v7.03.32). Added BEFORE signing so the HMAC covers them.
        // The orch whitelists each field in its /v2/config parser; unknown-to-orch fields are ignored
        // until whitelisted. plugin_version is REQUIRED (keeps agencySites.plugin_version fresh on every
        // sync — activation/settings-save/heartbeat all route through here); php/wp are segmentation.
        if (defined('WPC_PLUGIN_VERSION')) {
            $body_payload['plugin_version'] = (string) WPC_PLUGIN_VERSION;
        }
        $body_payload['php_version'] = PHP_VERSION;
        if (function_exists('get_bloginfo')) {
            $body_payload['wp_version'] = (string) get_bloginfo('version');
        }
        $wpc_host = function_exists('wpc_v2_detect_host') ? wpc_v2_detect_host() : '';
        if ($wpc_host !== '') {
            $body_payload['host'] = $wpc_host;   // managed-host segmentation (nice-to-have)
        }
        if ($wake_url !== '') {
            $body_payload['wake_url'] = $wake_url;
        }
        $body_raw = wp_json_encode($body_payload);
        if ($body_raw === false) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'json_encode_failed'];
        }
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);

        // ?sync=1: AWAIT provisioning so the orch's native_accept_vary witness echoes on THIS
        // call — it only populates on the synchronous path (an async POST returns before the
        // edge rules + AVIF Vary land, so the witness comes back null and the avif/webp natural
        // gate correctly won't flip). This runs on the deferred cron, off the admin request, so
        // awaiting costs nothing extra. A pre-receiver orch ignores the unknown query param.
        $url = rtrim($orch_url, '/') . '/v2/config?sync=1';
        $resp = wp_remote_post($url, [
            // 30s ceiling for the orch's synchronous Bunny Edge-Rule PATCH. But CAP to 8s when NOT on
            // real wp-cron: the force-provision heartbeat can run this INLINE on a pageview, and a
            // hanging orch holding an FPM worker 30s per load exhausts the pool → 503. Real wp-cron
            // (off the request) stays patient at 30s.
            'timeout'   => (defined('DOING_CRON') && DOING_CRON) ? 30 : 8,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.1'),
            ],
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) {
            error_log('[WPC ConfigSync] http_error: ' . $resp->get_error_message());
            return ['ok' => false, 'http_code' => 0, 'reason' => 'http_error'];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        // Orch may return 202 Accepted ({queued, status_url}) when its Edge-Rule PATCH queue is
        // deep. The BunnyDB write is the source of truth and is already committed, so 202 IS
        // success: fall through to the mirror write, don't block on a status poll.
        if ($code === 202) {
            $b202 = json_decode((string) wp_remote_retrieve_body($resp), true);
            error_log(sprintf(
                '[WPC ConfigSync] 202 queued=%s status_url=%s (edge propagation async; DB committed)',
                is_array($b202) && isset($b202['queued']) ? (string) $b202['queued'] : '?',
                is_array($b202) && isset($b202['status_url']) ? (string) $b202['status_url'] : '?'
            ));
        }
        if ($code < 200 || $code >= 300) {
            error_log(sprintf(
                '[WPC ConfigSync] http_%d resp=%s',
                $code, substr((string) wp_remote_retrieve_body($resp), 0, 200)
            ));
            return ['ok' => false, 'http_code' => $code, 'reason' => 'http_non_2xx'];
        }

        // A 2xx is "request accepted", NOT "all zones provisioned" — orch reports per-zone outcomes
        // in failed[]. A db_error_* reason means orch DEFERRED (Edge Rules NOT provisioned). Treat
        // any non-empty failed[] as not-synced: do NOT cache the mirror (else its sig matches and
        // the next sync SKIPS, stranding the customer un-provisioned), and on a db_error arm a
        // pending flag so an admin load re-fires once orch recovers.
        $rbody = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (is_array($rbody) && !empty($rbody['failed']) && is_array($rbody['failed'])) {
            $reasons = [];
            $db_deferred = false;
            foreach ($rbody['failed'] as $f) {
                $reason = is_array($f) ? (string) ($f['reason'] ?? $f['error'] ?? $f['code'] ?? '') : (string) $f;
                if ($reason !== '') $reasons[] = $reason;
                if (stripos($reason, 'db_error') === 0) $db_deferred = true;
            }
            error_log(sprintf(
                '[WPC ConfigSync] http_%d but failed=[%s] — NOT caching mirror (%s).',
                $code, implode(',', $reasons), $db_deferred ? 'deferred, will retry' : 'reported failure'
            ));
            if ($db_deferred && function_exists('update_option')) {
                // Persistent pending flag (an option, not a transient) so it survives across admin
                // loads — a customer who saved mid-outage still re-syncs once orch recovers. The
                // admin_init retry (rate-limited) re-fires until a clean success clears it.
                update_option('wpc_v2_config_sync_pending', 1, false);
            }
            return ['ok' => false, 'http_code' => $code, 'reason' => ($db_deferred ? 'deferred:' : 'failed:') . implode(',', $reasons)];
        }

        // Mirror the orch's per-zone native_accept_vary confirmation (the resolver's second
        // witness). The resolver reads wpc_v2_orch_nav_<zone> to flag drift when its own loopback
        // probe contradicts the orch's "this zone is direct-to-Bunny / Vary-200-safe" claim. Orch
        // echoes zones:[{zone_id, native_accept_vary}]; ABSENT for a zone → delete the mirror → inert.
        $nav_by_zone = [];
        if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && !empty($rz['zone_id']) && array_key_exists('native_accept_vary', $rz)) {
                    $nav_by_zone[(string) $rz['zone_id']] = !empty($rz['native_accept_vary']);
                }
            }
        }

        // Mirror the orch's per-zone cdn_disabled master kill. Same shape + inert-on-absence
        // contract as native_accept_vary above; rides the SAME zones[] array.
        $cdn_disabled_by_zone = [];
        if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && !empty($rz['zone_id']) && array_key_exists('cdn_disabled', $rz)) {
                    $cdn_disabled_by_zone[(string) $rz['zone_id']] = !empty($rz['cdn_disabled']);
                }
            }
        }

        // Mirror the orch's per-zone emit_src_hints toggle (the ?src probe-storm hint). Same shape +
        // inert-on-absence contract as native_accept_vary / cdn_disabled; rides the SAME zones[] array.
        $src_hints_by_zone = [];
        if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && !empty($rz['zone_id']) && array_key_exists('emit_src_hints', $rz)) {
                    $src_hints_by_zone[(string) $rz['zone_id']] = !empty($rz['emit_src_hints']);
                }
            }
        }

        // On success, mirror the zone state locally so the admin UI renders without a round-trip.
        // One option per zone — a typical install has 1, so the option count stays bounded.
        $cdn_disabled_changed = false; // did any zone's cdn_disabled flip this sync?
        foreach ($clean as $z) {
            $zid = (string) $z['zone_id'];
            update_option(
                'wpc_v2_lazy_enabled_' . sanitize_key($zid),
                $z['lazy_enabled'] ? '1' : '0',
                false
            );
            // Two-witness mirror. RESILIENT MATCH: the orch echoes the RESOLVED numeric Bunny PZ in
            // zones[].zone_id even when we SENT a non-numeric CNAME (a pure-CF-direct zone). An exact
            // match then misses, and the else-branch would silently DELETE a true witness. When BOTH
            // the sent and echoed sets are exactly one zone, the pairing is unambiguous → adopt it.
            // GUARDED to single-zone so a multi-zone/agency site can never cross-apply a witness to
            // the wrong zone. Numeric-PZ zones match on the first branch and never reach this.
            $nav_key = 'wpc_v2_orch_nav_' . sanitize_key($zid);
            $nav_written = null;
            $nav_old     = function_exists('get_option') ? get_option($nav_key, null) : null; // for the promote-on-flip below
            if (array_key_exists($zid, $nav_by_zone)) {
                $nav_written = $nav_by_zone[$zid] ? '1' : '0';
                update_option($nav_key, $nav_written, false);
            } elseif (count($clean) === 1 && count($nav_by_zone) === 1) {
                $nav_written = reset($nav_by_zone) ? '1' : '0';
                update_option($nav_key, $nav_written, false);
            } elseif (function_exists('delete_option')) {
                delete_option($nav_key); // orch didn't report → no second witness
            }
            // A CONFIRMED witness (NAV='1') is the convergence point for the provisioning self-heal:
            // reset the attempt counter + stamp the provisioned site_url (the clone/host-change
            // baseline). ONLY on a true witness — a 2xx with NAV≠'1' must not reset the counter, else
            // a never-confirming zone retries forever.
            if ($nav_written === '1') {
                if (function_exists('delete_option')) delete_option('wpc_v2_selfheal_attempts');
                if (function_exists('site_url'))      update_option('wpc_v2_provisioned_site_url', (string) site_url(), false);
                // nav just flipped to confirmed → schedule a delivery re-verify so the zone
                // auto-promotes to cdn-edge without waiting for an admin pageview or the 12h TTL.
                if ((string) $nav_old !== '1'
                    && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_delivery_verify')) {
                    wp_schedule_single_event(time() + 20, 'wpc_delivery_verify');
                }
            }
            // cdn_disabled master-kill mirror. Same write/delete-on-absence pattern + single-zone
            // resilient match as the nav block above; read by wpc_v2_zone_cdn_disabled(). Track value
            // CHANGES so we can purge the page cache once below (a flip alters the emitted markup).
            $cdn_key = 'wpc_v2_orch_cdn_disabled_' . sanitize_key($zid);
            $cdn_old = get_option($cdn_key, null);
            if (array_key_exists($zid, $cdn_disabled_by_zone)) {
                $cdn_new = $cdn_disabled_by_zone[$zid] ? '1' : '0';
                if ((string) $cdn_old !== $cdn_new) { $cdn_disabled_changed = true; }
                update_option($cdn_key, $cdn_new, false);
            } elseif (count($clean) === 1 && count($cdn_disabled_by_zone) === 1) {
                $cdn_new = reset($cdn_disabled_by_zone) ? '1' : '0';
                if ((string) $cdn_old !== $cdn_new) { $cdn_disabled_changed = true; }
                update_option($cdn_key, $cdn_new, false);
            } elseif (function_exists('delete_option')) {
                if ($cdn_old !== null && $cdn_old !== false) { $cdn_disabled_changed = true; }
                delete_option($cdn_key); // orch didn't report → not disabled
            }
            // emit_src_hints mirror (orch's per-zone ?src toggle). Same write/delete-on-absence +
            // single-zone resilient match as cdn_disabled above; read by src_hint_enabled() to append
            // ?src=<ext> to not-on-disk natural URLs so the edge skips the slow-origin format-probe.
            // Non-keying + self-healing, so a flip needs no cache purge.
            $sh_key = 'wpc_v2_orch_src_hints_' . sanitize_key($zid);
            if (array_key_exists($zid, $src_hints_by_zone)) {
                update_option($sh_key, $src_hints_by_zone[$zid] ? '1' : '0', false);
            } elseif (count($clean) === 1 && count($src_hints_by_zone) === 1) {
                update_option($sh_key, reset($src_hints_by_zone) ? '1' : '0', false);
            } elseif (function_exists('delete_option')) {
                delete_option($sh_key); // orch didn't report → off (default)
            }
        }

        // Ops visibility: log per-zone cdn_disabled values when the field is present.
        if (!empty($cdn_disabled_by_zone)) {
            error_log('[WPC cdn_disabled] /v2/config echoed ' . count($cdn_disabled_by_zone)
                . ' zone(s): ' . wp_json_encode($cdn_disabled_by_zone));
        }
        // Ops visibility: log per-zone emit_src_hints values when the field is present (joint-test signal).
        if (!empty($src_hints_by_zone)) {
            error_log('[WPC emit_src_hints] /v2/config echoed ' . count($src_hints_by_zone)
                . ' zone(s): ' . wp_json_encode($src_hints_by_zone));
        }

        // A cdn_disabled FLIP changes the emitted markup (CDN URLs ↔ origin), so page-cached HTML
        // must regenerate immediately — otherwise the kill (or its reversal) only takes effect as
        // caches organically expire. Canonical full purge, same as the settings-save path. Runs in
        // the deferred-sync (cron/loopback) context, never a front-end pageview, so no stampede risk;
        // and only on an actual value change, so a steady-state sync never purges.
        if ($cdn_disabled_changed) {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            } elseif (function_exists('do_action')) {
                do_action('wps_ic_purge_all_cache');
            }
        }

        // Mirror the config we just pushed so the settings-save trigger can detect "already
        // in sync" and skip redundant POSTs. Site-global, one option.
        update_option(
            'wpc_v2_synced_image_config',
            $img_cfg['local_quality'] . '|' . $img_cfg['local_max_width']
                . '|' . $del_cfg['delivery_mode'] . '|' . $del_cfg['host_lock_mode'] . '|' . $del_cfg['host_lock_allow']
                . ($del_cfg['redirect_target'] === 'origin' ? '|origin' : ''), // only perturb the sig for the non-default (origin) intent → no fleet re-sync wave
            false
        );

        // Sync succeeded (2xx, no failed[]) — clear any pending-retry flag from a prior deferral.
        if (function_exists('delete_option')) {
            delete_option('wpc_v2_config_sync_pending');
        }

        // Stamp the last SUCCESSFUL provisioning sync. The admin_init heartbeat reads this to
        // self-provision a never-synced zone on first admin visit, and to re-fire once the last sync
        // ages past the 6-month window (= TTL/2 of the blob's 1-year HMAC), renewing the signed
        // x-wpc-config well before expiry. Only on a real 2xx-no-failed[] — a db_error deferral
        // returns earlier, so a deferred zone stays stale and keeps retrying.
        update_option('wpc_v2_config_synced_at', time(), false);
        // Stamp the environment fingerprint on a confirmed 2xx — the witness a staging clone CANNOT
        // inherit-fake (DB name/prefix differ even after a URL search-replace), so the self-heal
        // re-provisions for the new host. Stamping here (not at the NAV='1' confirm) also settles
        // CF-direct zones, which never set a Bunny NAV witness.
        if (function_exists('wpc_v2_env_fingerprint')) {
            update_option('wpc_v2_provisioned_fingerprint', wpc_v2_env_fingerprint(), false);
        }

        return ['ok' => true, 'http_code' => $code];
    }
}

/**
 * Convenience: sync a single zone's lazy_cdn enable state.
 *
 * @param string $zone_id     Plugin's zone identifier (from wp_options).
 * @param bool   $enabled     True to enable lazy_cdn for this zone.
 * @return array  Pass-through from wpc_v2_config_sync_zones.
 */
if (!function_exists('wpc_v2_config_sync_lazy_enabled')) {
    function wpc_v2_config_sync_lazy_enabled($zone_id, $enabled)
    {
        // Capture the PRIOR Smart-Delivery state BEFORE the sync runs — config_sync_zones()
        // overwrites the per-zone mirror with the value being SENT, so reading it afterwards would
        // mask the transition. The marker is absent on existing installs; reading absence as "off"
        // would misfire a fleet-wide purge on the first post-upgrade sync of every already-SD-ON
        // zone, so seed the prior state from the still-old mirror — only a genuine off→on purges.
        $sd_marker = 'wpc_v2_lazy_synced_on_' . sanitize_key((string) $zone_id);
        $sd_seen   = get_option($sd_marker, null);
        if ($sd_seen === null) {
            $sd_mirror = (string) get_option('wpc_v2_lazy_enabled_' . sanitize_key((string) $zone_id), '');
            $sd_was_on = ($sd_mirror === '1');
        } else {
            $sd_was_on = (bool) $sd_seen;
        }

        $result = wpc_v2_config_sync_zones([
            ['zone_id' => (string) $zone_id, 'lazy_enabled' => (bool) $enabled],
        ]);

        // Smart-Delivery-ENABLE purge coupling. On a lazy_cdn 0→1 flip the CDN still holds the old
        // zoneCtx (lazy_cdn_active=0) in its apikey cache AND any long-cached SD-off interims. A
        // zone-wide `config_changed` customer-purge does both at once: clears the interims AND
        // invalidates the apikey cache so lazy_cdn_active=1 takes effect immediately instead of
        // waiting out the ~60s TTL. Fires once per genuine off→on, only on a successful sync.
        if (!empty($result['ok'])
            && function_exists('wpc_customer_purge')
            && function_exists('wpc_v2_get_apikey')) {
            if ((bool) $enabled && !$sd_was_on) {
                update_option($sd_marker, 1, false);
                $sd_apikey = (string) wpc_v2_get_apikey();
                if ($sd_apikey !== '') {
                    // reason MUST be the whitelisted `config_changed` (NOT `config_change`),
                    // which also fires apikey-cache invalidation on the CDN.
                    wpc_customer_purge($sd_apikey, 'all', [], 'config_changed', false);
                }
            } elseif (!(bool) $enabled && $sd_was_on) {
                update_option($sd_marker, 0, false); // reset marker on disable
            }
        }

        return $result;
    }
}

/**
 * Schedule the /v2/config sync to run in the BACKGROUND (wp-cron), never inline. The POST blocks
 * up to 30s (orch may do a synchronous Bunny Edge-Rule PATCH), so running it on a settings-SAVE or
 * admin load hangs the UI ("Saving…" stuck) when the orchestrator is slow. The cron handler does
 * the real sync with full mirror/pending/retry bookkeeping; the request returns immediately.
 * Idempotent: at most one event queued, and the handler re-reads the latest zone + lazy state.
 */
if (!function_exists('wpc_v2_schedule_config_sync')) {
    function wpc_v2_schedule_config_sync()
    {
        $have_cron = function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event');
        if ($have_cron) {
            if (!wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
            }
        }

        // The wp-cron event above only runs when wp-cron TICKS (a site visit or system cron). A
        // traffic-starved fresh zone, or DISABLE_WP_CRON with no system cron, can wait indefinitely
        // and header provisioning would silently never fire. So ALSO kick a non-blocking self-loopback
        // that runs the sync as a real HTTP request, off the cron entirely. A one-time 60s token
        // authenticates the logged-out self-call; a 30s debounce caps a heartbeat+activation race to
        // one loopback. Cron + the admin_init heartbeat remain backstops if the loopback is blocked.
        if (function_exists('admin_url')
            && function_exists('set_transient') && function_exists('get_transient')
            && !get_transient('wpc_v2_provision_loopback_sent')) {
            set_transient('wpc_v2_provision_loopback_sent', 1, 30);
            // CSPRNG token only — never a hash of predictable inputs. wp_generate_password() is
            // CSPRNG-backed; random_bytes() is the fallback; the final branch is an unreachable
            // last resort. One-time (consumed on first verify) + per-site, so it can't be replayed.
            if (function_exists('wp_generate_password')) {
                $token = wp_generate_password(32, false);
            } elseif (function_exists('random_bytes')) {
                $token = bin2hex(random_bytes(16));
            } else {
                $token = sha1(uniqid('', true) . (function_exists('wp_rand') ? wp_rand() : mt_rand()));
            }
            set_transient('wpc_v2_provision_token', $token, 60);

            // RAW LOCAL-VHOST SELF-POST — kills the "fake success behind a CDN/WAF" class. A plain
            // wp_remote_post(admin_url(), blocking=false) connects to the PUBLIC host = the CF/WAF edge
            // on a datacenter-IP site: it returns truthy before any worker is reached, and the cookieless
            // nopriv self-POST is dropped at the edge, so it never hits local PHP-FPM. Instead connect to
            // a LOCAL address (127.0.0.1 → localhost → public host) via the shared connect-only helper
            // while keeping Host:/TLS-SNI = the public $host, so the box's vhost answers and the edge is
            // bypassed. Fire-and-forget: connect, fwrite, fclose, no read — the handler runs detached
            // (GET_LOCK-guarded downstream). On total loopback failure we do NOT run inline here
            // (provisioning must not block the admin request); cron + the admin_init heartbeat carry it.
            $sent = false;
            if (class_exists('wps_ic_ajax')
                && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')
                && function_exists('wp_parse_url')) {
                $url   = admin_url('admin-ajax.php');
                $parts = wp_parse_url($url);
                if (is_array($parts) && !empty($parts['host'])) {
                    $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
                    // Honor an explicit non-standard port from admin_url (proxied/dev/staging), else
                    // the scheme's standard port.
                    $port = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
                    $host = (string) $parts['host']; // PUBLIC vhost — Host:/SNI only, NOT the connect target
                    // Default to /admin-ajax.php (NOT bare '/') — a homepage hit would 200 without
                    // running any handler, the silent-no-op class this fix exists to kill.
                    $path = (!empty($parts['path']) ? $parts['path'] : '/admin-ajax.php');

                    $body = http_build_query(['action' => 'wpc_v2_provision_now', 'token' => $token]);
                    $req  = "POST {$path} HTTP/1.1\r\n"
                          . "Host: {$host}\r\n"
                          . "Content-Type: application/x-www-form-urlencoded\r\n"
                          . "Content-Length: " . strlen($body) . "\r\n"
                          . "Connection: close\r\n"
                          . "User-Agent: WPCProvisionNow/1.0\r\n"
                          . "\r\n"
                          . $body;

                    // Connect-only LOCAL-vhost chain (127.0.0.1 → localhost → public host).
                    $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
                    if ($fp) {
                        @stream_set_timeout($fp, 0, 100000); // non-blocking write budget; we never read
                        @fwrite($fp, $req);
                        @fclose($fp);                         // fire-and-forget — handler runs detached
                        $sent = true;
                    } else {
                        error_log('[WPC ProvisionNow] loopback_connect_failed host=' . $host . ' port=' . $port . ' — cron+heartbeat backstops carry');
                    }
                }
            }
            // Loopback delivered OR best-effort attempted — cron + the admin_init heartbeat remain
            // the backstops. Return without running the sync inline on this admin request.
            if ($sent) {
                return;
            }
            // Helper/URL unavailable or every local rung missed — fall through to the inline
            // last-resort below (only runs when wp-cron is also unavailable).
        }

        // No cron API AND no loopback path — run inline so the sync isn't lost (last resort).
        if (!$have_cron) {
            wpc_v2_run_deferred_config_sync();
        }
    }
}

// Loopback receiver for the reliable-fire path above. Runs the deferred provisioning sync as a
// real request (no wp-cron dependency). Authenticated by the one-time 60s token only — no
// cookies/nonce, since the firing context is logged-out from the loopback's perspective.
// Idempotent + self-throttled downstream (the deferred-sync lock).
if (!function_exists('wpc_v2_provision_now_handler')) {
    function wpc_v2_provision_now_handler()
    {
        $token    = isset($_POST['token']) ? (string) (function_exists('wp_unslash') ? wp_unslash($_POST['token']) : $_POST['token']) : '';
        $expected = function_exists('get_transient') ? get_transient('wpc_v2_provision_token') : false;
        if ($token === '' || $expected === false || !hash_equals((string) $expected, $token)) {
            if (function_exists('status_header')) status_header(403);
            exit;
        }
        if (function_exists('delete_transient')) delete_transient('wpc_v2_provision_token'); // one-time use
        if (function_exists('wpc_v2_run_deferred_config_sync')) {
            wpc_v2_run_deferred_config_sync();
        }
        if (function_exists('status_header')) status_header(200);
        exit;
    }
    add_action('wp_ajax_wpc_v2_provision_now', 'wpc_v2_provision_now_handler');
    add_action('wp_ajax_nopriv_wpc_v2_provision_now', 'wpc_v2_provision_now_handler');
}
if (!function_exists('wpc_v2_run_deferred_config_sync')) {
    function wpc_v2_run_deferred_config_sync()
    {
        if (!function_exists('wpc_v2_get_zone_id') || !function_exists('wpc_v2_config_sync_lazy_enabled')) {
            return;
        }
        // Lock so a racing cron-tick + self-loopback (both fire the same event) don't double-POST.
        // The TTL must OUTLIVE the work it guards: the sync's POST has a 30s timeout, so a 30s lock
        // could expire mid-POST. 60s = the 30s ceiling + headroom. A dead lock-holder auto-expires.
        if (function_exists('get_transient') && get_transient('wpc_v2_deferred_sync_lock')) {
            return;
        }
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_deferred_sync_lock', 1, 60);
        }

        $zone_id     = (string) wpc_v2_get_zone_id();
        $has_numeric = ($zone_id !== '' && ctype_digit($zone_id));

        // Fire on apikey, not "numeric Bunny PZ only": a CF-fronted custom CNAME may have no
        // numeric PZ but a cname the orch resolves by apikey. Identify by the numeric id when
        // present, else the cname (zones[].cname is the canonical write source either way). Bail
        // only when NEITHER identity exists.
        $cname = trim((string) get_option('ic_custom_cname'));
        if ($cname === '') {
            $cname = trim((string) get_option('ic_cdn_zone_name'));
        }
        $zid = $has_numeric ? $zone_id : $cname;
        if ($zid === '') {
            return;
        }

        // Track success via synced_at (re-stamped only on a 2xx) so we clear force-provision
        // exactly when the provisioning actually landed.
        $before = (int) get_option('wpc_v2_config_synced_at', 0);

        wpc_v2_config_sync_lazy_enabled(
            $zid,
            function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : false
        );

        // Clear force-provision ONLY on a confirmed 2xx. On failure it persists, so the admin_init
        // heartbeat re-fires on the next pageview (paced by the short 2min force backoff) — a blocked
        // loopback + a dead cron can't strand it.
        if ((int) get_option('wpc_v2_config_synced_at', 0) > $before) {
            delete_option('wpc_v2_force_provision');
            delete_option('wpc_v2_force_provision_fails'); // landed → reset the breaker
            // The self-heal counter reset + site_url stamp happen at the CONFIRMED-witness (NAV='1')
            // point inside config_sync_zones(), NOT here — a 2xx alone (NAV may still be null) must
            // not reset the bounded retry counter.
        } elseif (get_option('wpc_v2_force_provision')) {
            // CIRCUIT-BREAKER. A hanging orch makes this sync time out on every fire; because
            // force_provision only clears on a 2xx, the heartbeat would re-fire the blocking sync
            // every ~2min forever, holding an FPM worker each time → pool exhaustion → 503. After N
            // consecutive non-2xx, STOP re-arming the inline retry: drop force_provision (cron + a
            // manual CF refresh / settings save still retry, just not on every pageview). Bounds the
            // worst case to N attempts; resets to 0 on the next confirmed landing.
            $fp_fails = (int) get_option('wpc_v2_force_provision_fails', 0) + 1;
            if ($fp_fails >= 3) {
                delete_option('wpc_v2_force_provision');
                delete_option('wpc_v2_force_provision_fails');
                error_log('[WPC v2] force-provision circuit-breaker tripped after ' . $fp_fails . ' failed /v2/config attempts — backing off the inline admin_init retry (cron + manual refresh still retry).');
            } else {
                update_option('wpc_v2_force_provision_fails', $fp_fails, false);
            }
        }
    }
}
add_action('wpc_v2_deferred_config_sync', 'wpc_v2_run_deferred_config_sync');

// ─── WITNESS-GATED, NON-BLOCKING provisioning self-heal ──────────────────────────────────────────
//
// Fixes a staging CLONE that has the latest plugin + admin activity but never provisioned: the clone's
// DB was copied AFTER the source provisioned, so force_provision was cleared and config_synced_at was
// recent → the admin_init heartbeat early-returns on every pageview, and the loopback/cron are never
// armed (they're triggered by force/maybe_sync, which a clone doesn't fire). Every flag-gated trigger
// is a no-op.
//
// FIX PRINCIPLE: trigger on the ACTUAL provisioned state (the orch native_accept_vary witness), not on
// force/synced_at flags — and deliver it NON-BLOCKING over a path that survives a self-loopback/WAF
// block. A blocking POST on the render path 503s the FPM pool and is banned here. The reliable carrier
// is the browser-driven WP Heartbeat: a real browser→server request (bypasses the self-request WAF
// block) that is async (never blocks render), firing every 15-60s while any admin page is open.

if (!function_exists('wpc_v2_zone_unprovisioned')) {
    // True iff this is a numeric Bunny PZ whose orch native_accept_vary witness has NOT come back true.
    // CF-direct (non-numeric cname) zones have no Bunny AVIF-Vary field → never flagged here (they use
    // the cname-verified lane), so they can't loop.
    function wpc_v2_zone_unprovisioned()
    {
        if (!function_exists('wpc_v2_get_zone_id') || !function_exists('get_option')) return false;
        $zid = (string) wpc_v2_get_zone_id();
        if ($zid === '' || !ctype_digit($zid)) return false; // numeric Bunny PZ only
        return get_option('wpc_v2_orch_nav_' . sanitize_key($zid)) !== '1';
    }
}

if (!function_exists('wpc_v2_provision_host_changed')) {
    // True iff the live site_url no longer matches the one we last successfully provisioned — a host
    // migration / staging clone. Drives a re-provision against the CURRENT site_url. Inert until the
    // first provision stamps wpc_v2_provisioned_site_url, so it never false-fires on a fresh install.
    function wpc_v2_provision_host_changed()
    {
        if (!function_exists('site_url') || !function_exists('get_option')) return false;
        $confirmed = (string) get_option('wpc_v2_provisioned_site_url', '');
        if ($confirmed === '') return false;
        // Scheme-stable compare: site_url() returns http/https by request context, so a raw compare
        // false-fires "host changed" between an http cron stamp and an https render.
        return preg_replace('#^https?://#i', '', $confirmed) !== preg_replace('#^https?://#i', '', (string) site_url());
    }
}

if (!function_exists('wpc_v2_env_fingerprint')) {
    // Environment fingerprint: changes on a staging clone / host migration even when site_url was
    // search-replaced to match the new host, because a staging copy runs on a different DB name /
    // table prefix. The "have I provisioned in THIS exact environment?" witness — one a copied
    // orch_nav mirror or provisioned_site_url cannot inherit-fake.
    function wpc_v2_env_fingerprint()
    {
        global $wpdb;
        // Scheme-stable host: home_url() returns http or https by request context, so a fingerprint
        // stamped from an http cron request would never match the https front-end check → env_changed
        // stuck true → CDN suppressed → whole site stuck on origin. Strip the scheme so it's identical
        // across cron / admin-ajax / front-end.
        $home = function_exists('get_option') ? (string) get_option('home') : '';
        if ($home === '' && function_exists('home_url')) $home = (string) home_url();
        $url = preg_replace('#^https?://#i', '', $home);
        $db  = defined('DB_NAME') ? (string) DB_NAME : '';
        $pfx = (isset($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : '';
        return sha1($url . '|' . $db . '|' . $pfx);
    }
}
if (!function_exists('wpc_v2_provision_env_changed')) {
    // True iff we have NOT recorded a successful sync in the current environment: a fresh install, a
    // staging clone, or a host migration. Drives a forced re-provision so the orch learns this host's
    // identity. The fingerprint is stamped only on a confirmed 2xx, so this stays true until the sync
    // lands for THIS host — it can't be satisfied by flags copied from the source DB.
    function wpc_v2_provision_env_changed()
    {
        if (!function_exists('get_option')) return false;
        return (string) get_option('wpc_v2_provisioned_fingerprint', '') !== wpc_v2_env_fingerprint();
    }
}
if (!function_exists('wpc_v2_provision_reset_for_env')) {
    // Wipe the clone-inherited provisioning witnesses so the self-heal stops trusting a copied DB's
    // "already provisioned" state, and arm force_provision. Pure option/transient writes (no HTTP),
    // safe on any path, idempotent. The fingerprint is NOT stamped here (only on a confirmed 2xx),
    // so this keeps re-arming until the sync lands for the new host.
    function wpc_v2_provision_reset_for_env()
    {
        if (!function_exists('get_option')) return;
        if (function_exists('wpc_v2_get_zone_id') && function_exists('delete_option')) {
            $zid = (string) wpc_v2_get_zone_id();
            if ($zid !== '') delete_option('wpc_v2_orch_nav_' . sanitize_key($zid)); // copied witness
        }
        if (function_exists('delete_option')) {
            delete_option('wpc_v2_provisioned_site_url'); // copied host-change baseline
            delete_option('wpc_v2_selfheal_attempts');    // un-cap a copied attempt count
        }
        // FAIL-SAFE the CF cname: set '0' (explicit "don't emit"), NOT delete. Deleting fail-OPENS
        // the emit-gate (default → emit the CF cname), so an unprovisioned clone would emit a natural
        // URL to a cname the pod can't resolve yet → "Site not found" → broken images. '0' makes the
        // gate fall back to the zapwp host until the verify heartbeat re-promotes after re-provision.
        if (function_exists('update_option')) update_option('wpc_cf_cname_verified', '0', false);
        if (function_exists('delete_transient')) {
            delete_transient('wpc_v2_selfheal_backoff');
            delete_transient('wpc_v2_config_force_backoff');
        }
        if (function_exists('update_option')) update_option('wpc_v2_force_provision', 1, false);
    }
}

if (!function_exists('wpc_v2_provision_ensure_bg')) {
    // The bounded, BACKGROUND "ensure provisioned" routine. Call ONLY from background contexts (the WP
    // Heartbeat ajax, the Re-check ajax) — NEVER the render path — so its wpc_v2_run_deferred_config_sync()
    // (a bounded blocking POST) runs in a request the user isn't waiting on. No-op for healthy zones
    // (site_url matches + NAV='1'): the cheap, fleet-wide common path. Bounded by a DEDICATED attempt
    // counter (separate from the .100 inline breaker, which resets on trip) so a zone that genuinely
    // can't confirm won't poll forever; the counter resets the instant the witness reads provisioned.
    function wpc_v2_provision_ensure_bg($reason)
    {
        if (!function_exists('wpc_v2_run_deferred_config_sync') || !function_exists('wpc_v2_get_zone_id')) {
            return false;
        }
        // ALSO fire on the explicit force_provision flag, not just the witness: a clone COPIES the
        // wpc_v2_orch_nav witness ('1' from the source), so wpc_v2_zone_unprovisioned() is blind to it
        // — but a re-install/update sets force_provision, and this WAF-immune Heartbeat path is the
        // reliable carrier for it. The orch's /v2/config response then overwrites the stale mirror with
        // the true current NAV (self-correcting). run_deferred clears force on success, so this stays bounded.
        $force  = (bool) get_option('wpc_v2_force_provision');
        $unprov = wpc_v2_zone_unprovisioned();
        $moved  = wpc_v2_provision_host_changed();
        if (!$force && !$unprov && !$moved) return false; // healthy → no-op

        $attempts = (int) get_option('wpc_v2_selfheal_attempts', 0);
        if ($attempts >= 12) return false; // gave up self-healing; cron + config-change still retry

        // Pace: at most one self-heal sync per 2 min (run_deferred's own 60s lock also guards races).
        if (function_exists('get_transient') && get_transient('wpc_v2_selfheal_backoff')) return false;
        if (function_exists('set_transient')) set_transient('wpc_v2_selfheal_backoff', 1, 120);

        if ($moved) {
            // Host migration/clone: clear the stale confirmed-site stamp so wpc_v2_run_deferred_config_sync
            // re-POSTs against the CURRENT site_url and the orch re-resolves the PZ for this host. The orch
            // echoes the resolved zone in zones[].zone_id; the single-zone resilient-match in
            // wpc_v2_config_sync_zones adopts it.
            if (function_exists('delete_option')) delete_option('wpc_v2_provisioned_site_url');
        }
        update_option('wpc_v2_force_provision', 1, false);
        update_option('wpc_v2_selfheal_attempts', $attempts + 1, false);
        error_log(sprintf('[WPC v2 selfheal] ensure_bg reason=%s force=%d unprov=%d moved=%d attempt=%d',
            (string) $reason, $force ? 1 : 0, $unprov ? 1 : 0, $moved ? 1 : 0, $attempts + 1));

        wpc_v2_run_deferred_config_sync(); // bounded blocking POST — OK: background ajax, not the render path

        // On a CONFIRMED witness (NAV='1') the sync (wpc_v2_config_sync_zones) resets the attempt counter
        // + stamps the provisioned site_url; a 2xx-without-NAV leaves the counter intact so it stays bounded.
        return true;
    }
}

// Browser-driven delivery (WAF-immune + non-blocking). heartbeat_received fires in an admin-ajax
// request the BROWSER makes, so it bypasses the self-loopback/WAF block that strands the server-side
// loopback, and it never blocks a page render. Gated to admin-capable users + a no-op for healthy
// zones (one cached get_option), so the fleet-wide cost is negligible.
if (function_exists('add_filter')) {
    add_filter('heartbeat_received', function ($response, $data) {
        if (function_exists('current_user_can') && current_user_can('manage_options')
            && function_exists('wpc_v2_provision_ensure_bg')) {
            wpc_v2_provision_ensure_bg('heartbeat');
        }
        return $response;
    }, 10, 2);
}

// Probe the CF custom cname; if live, PROMOTE the emit-gate (wpc_cf_cname_verified=1) so the front-end
// switches from the zapwp fallback to the CF cname. Callable from multiple carriers (browser-AJAX,
// orch-polled healthcheck) since the admin_init heartbeat alone is unreliable on locked-down hosts.
// Bounded: 1 probe, 3s, throttled 2 min, no-op once verified.
if (!function_exists('wpc_v2_cf_cname_reverify')) {
    function wpc_v2_cf_cname_reverify($throttle = true)
    {
        if (!function_exists('get_option')) return false;
        $v = get_option('wpc_cf_cname_verified');
        if ($v === '1' || $v === 1) return false; // already promoted
        $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
        if ($cfc === '') return false;
        $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if (!is_array($cf) || empty($cf['settings']['cdn'])) return false; // CF-delivered zones; token NOT required — verifyCfCnameLive is a pure HTTP probe
        if ($throttle && function_exists('get_transient') && get_transient('wpc_cf_reverify_bk')) return false;
        if (function_exists('set_transient')) set_transient('wpc_cf_reverify_bk', 1, 120);
        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) { @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php'; }
        if (!class_exists('WPC_CloudflareAPI')) return false;
        $api = new WPC_CloudflareAPI(isset($cf['token']) ? $cf['token'] : '');
        if ($api && $api->verifyCfCnameLive($cfc, 1, 3)) {
            update_option('wpc_cf_cname_verified', 1, false); // PROMOTE — emit-gate now serves the CF cname
            if (function_exists('site_url')) update_option('wpc_v2_provisioned_site_url', (string) site_url(), false);
            // Purge on CF-cname PROMOTE: the emitted markup just flipped origin→cname. This fires off the
            // resolver tier path, so maybe_purge_on_delivery_change never sees it — without this the cached
            // HTML keeps serving origin URLs until a manual purge. Canonical full purge (page cache +
            // Varnish + the wps_ic_purge_all_cache fan-out).
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            } elseif (function_exists('do_action')) {
                do_action('wps_ic_purge_all_cache');
            }
            return true;
        }
        return false;
    }
}

// Reliable provisioning delivery via a real browser AJAX. The cron / self-loopback / WP-Heartbeat carriers
// are all unreliable on locked-down hosts (WAF blocks the loopback; wp-cron may be disabled; the Heartbeat
// needs an admin tab left open) — a zone can sit force_provision=1 with NO carrier ever firing the sync, so
// it stays unprovisioned (CDN suppressed → origin). This fires from the BROWSER on any wp-admin page load
// while a provision is pending: an authenticated admin-ajax POST (WAF-immune, off the render path → no 503,
// keepalive so it survives navigation) running the bounded sync in its own request. Self-limiting: the script
// is emitted ONLY while the env is unconfirmed AND force_provision is set, so it stops once the 2xx stamps
// the fingerprint.
if (function_exists('add_action')) {
    add_action('wp_ajax_wpc_v2_force_provision_now', function () {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')
            || !function_exists('check_ajax_referer') || !check_ajax_referer('wpc_v2_force_provision_now', '_n', false)) {
            wp_send_json_error('forbidden');
        }
        // Reset the bounded self-heal gates (a copied/exhausted counter must not block delivery), arm
        // force, then run the sync synchronously IN THIS BACKGROUND AJAX (the 8s non-cron cap applies).
        if (function_exists('delete_option'))    { delete_option('wpc_v2_selfheal_attempts'); }
        if (function_exists('delete_transient')) { delete_transient('wpc_v2_selfheal_backoff'); delete_transient('wpc_v2_config_force_backoff'); }
        if (function_exists('update_option'))    { update_option('wpc_v2_force_provision', 1, false); }
        if (function_exists('wpc_v2_run_deferred_config_sync')) { wpc_v2_run_deferred_config_sync(); }
        if (function_exists('wpc_v2_cf_cname_reverify')) { wpc_v2_cf_cname_reverify(false); } // promote the CF cname if it's now live
        wp_send_json_success([
            'env_changed' => function_exists('wpc_v2_provision_env_changed') ? wpc_v2_provision_env_changed() : null,
            'synced_at'   => (int) get_option('wpc_v2_config_synced_at', 0),
            'force'       => (bool) get_option('wpc_v2_force_provision', false),
        ]);
    });
    add_action('admin_footer', function () {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) return;
        if (!function_exists('wpc_v2_provision_env_changed') || !wpc_v2_provision_env_changed()) return; // env confirmed → nothing to do
        if (!(bool) get_option('wpc_v2_force_provision', false)) return;                                 // no provision pending
        if (!function_exists('wp_create_nonce') || !function_exists('admin_url')) return;
        $nonce = wp_create_nonce('wpc_v2_force_provision_now');
        $ajax  = admin_url('admin-ajax.php');
        echo "\n<script>(function(){try{var f=new FormData();f.append('action','wpc_v2_force_provision_now');f.append('_n'," . wp_json_encode($nonce) . ");fetch(" . wp_json_encode($ajax) . ",{method:'POST',body:f,credentials:'same-origin',keepalive:true});}catch(e){}})();</script>\n";
    });
}

/**
 * Read this plugin's zone_id. Stored at activation / onboarding by the
 * existing API-key hand-off flow. If missing (older install pre-zone-id),
 * returns empty string and caller should display a "not configured" state.
 *
 * NOTE: For v7.05.1 we just READ from wp_options. The onboarding code that
 * WRITES this option is out of scope for this release — covered by the
 * service-team-managed sign-up flow.
 *
 * @return string  Zone identifier or '' if not configured.
 */
if (!function_exists('wpc_v2_get_zone_id')) {
    function wpc_v2_get_zone_id()
    {
        // Primary: dedicated v2 zone_id option. Set by orch's activation
        // response handler when the customer connects their API key.
        $z = (string) get_option('wpc_v2_zone_id', '');
        if ($z !== '') return $z;

        // Fallback chain — older installs may have stored zone info under
        // legacy keys. These are read-only fallbacks; new installs use the
        // primary above.
        $legacy_keys = ['wpc_zone_id', 'wpc_cdn_zone_id', 'wps_ic_zone_id'];
        foreach ($legacy_keys as $k) {
            $v = (string) get_option($k, '');
            if ($v !== '') return $v;
        }

        return '';
    }
}

/**
 * Read-side witness key fallback (Part B; scoped — does NOT touch wpc_v2_get_zone_id()).
 *
 * On a pure-CF-CNAME zone with NO resolved numeric Bunny PZ, wpc_v2_get_zone_id() returns '' (no
 * cname in its fallback chain — and changing that would alter zone_id SEMANTICS for ~20 consumers,
 * incl. the ctype_digit gate in v2-customer-purge.php and the signed send at :777). So the witness
 * mirror (wpc_v2_orch_nav_<key> / wpc_v2_orch_cdn_disabled_<key>) is WRITTEN by v2-config-sync under
 * sanitize_key($zid_SENT) where $zid_SENT is the CNAME the SEND path transmitted as zones[].zone_id
 * — but the READ side (orch_nav_signal / wpc_v2_zone_cdn_disabled) keys off wpc_v2_get_zone_id() and
 * misses it. This returns the EXACT cname strings a send path could have used as that zone_id, so a
 * reader can try them as a SECOND key ONLY when the primary (numeric/legacy) key is absent.
 *
 * Must mirror BOTH send paths byte-for-byte (sanitize_key is applied by the caller, identically to
 * the write at v2-config-sync.php:531/544):
 *   - deferred sync  (wpc_v2_run_deferred_config_sync :786-790): ic_custom_cname ?: ic_cdn_zone_name
 *   - CF cname save  (ajax.class.php :8814-8816):                WPS_IC_CF_CNAME
 * Order: CF cname FIRST (the cdn-rewrite emit host on a CF-fronted zone), then the deferred-sync
 * chain. A reader stops at the FIRST candidate that has a stored witness, so order only matters if
 * two different keys both hold a value (rare; both would carry the same orch nav truth anyway).
 *
 * Returns a de-duplicated, non-empty list of RAW (un-sanitized) cname strings, or [] when none apply
 * (e.g. a Bunny zapwp zone with a numeric PZ — there the primary key already matched and this is
 * never consulted). SITE-SCOPED: every source is a per-install wp_option, so on a multi-zone/agency
 * site this can only ever name THIS install's own cname — never another zone's witness.
 *
 * @return string[]
 */
if (!function_exists('wpc_v2_orch_witness_cname_keys')) {
    function wpc_v2_orch_witness_cname_keys()
    {
        if (!function_exists('get_option')) return [];
        $cands = [];

        // CF cname-save identity — the verified CF emit host.
        if (defined('WPS_IC_CF_CNAME')) {
            $cf = trim((string) get_option(WPS_IC_CF_CNAME));
            if ($cf !== '') $cands[] = $cf;
        }
        // Deferred-sync identity (wpc_v2_run_deferred_config_sync).
        $cc = trim((string) get_option('ic_custom_cname'));
        if ($cc !== '') {
            $cands[] = $cc;
        } else {
            $zn = trim((string) get_option('ic_cdn_zone_name'));
            if ($zn !== '') $cands[] = $zn;
        }

        return array_values(array_unique(array_filter($cands, 'strlen')));
    }
}

/**
 * Per-zone "kill ALL CDN involvement" signal. Mirrored from the orch's
 * /v2/config response (zones[].cdn_disabled) into wpc_v2_orch_cdn_disabled_<zone> by
 * wpc_v2_config_sync_zones() — same shape + inert-on-absence contract as the
 * wpc_v2_orch_nav_<zone> two-witness mirror.
 *
 * When true (operator flipped "CDN off" for this customer via the manager V6 Sites menu),
 * the plugin emits ZERO CDN URLs for this zone: the resolver returns <picture>/jpeg (origin
 * only) and cdn-rewrite + rewriteLogic suppress the runtime zone so every other emitter
 * (images/CSS/JS/fonts/favicon/preconnect) falls back to origin. Absent/false → no-op,
 * fully inert until orch v3.22.13 echoes the field. Reversible by the next /v2/config.
 *
 * @return bool
 */
if (!function_exists('wpc_v2_zone_cdn_disabled')) {
    function wpc_v2_zone_cdn_disabled()
    {
        if (!function_exists('get_option') || !function_exists('wpc_v2_get_zone_id')) return false;
        $sk = function_exists('sanitize_key');
        // PRIMARY: numeric Bunny PZ / legacy zone_id. A numeric-PZ zone matches here and NEVER reaches
        // the cname fallback. Read returns the stored disabled value.
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone !== '') {
            $v = get_option('wpc_v2_orch_cdn_disabled_' . ($sk ? sanitize_key($zone) : $zone), null);
            // A present primary witness is authoritative (incl. an explicit '0') — do NOT fall through.
            if ($v !== null) {
                return ($v === '1' || $v === 1 || $v === true);
            }
        }
        // FALLBACK: pure-CF-CNAME zone where wpc_v2_get_zone_id() is '' (or its key has no witness). The
        // send path stored the witness under sanitize_key(sent-cname), so read it back under the identical
        // sanitize_key of the same cname source. First candidate with a value wins; none → false (inert).
        if (function_exists('wpc_v2_orch_witness_cname_keys')) {
            foreach (wpc_v2_orch_witness_cname_keys() as $cn) {
                $v = get_option('wpc_v2_orch_cdn_disabled_' . ($sk ? sanitize_key($cn) : $cn), null);
                if ($v !== null) {
                    return ($v === '1' || $v === 1 || $v === true);
                }
            }
        }
        return false;
    }
}

if (!function_exists('wpc_v2_zone_src_hints')) {
    /**
     * Per-zone emit_src_hints toggle, mirrored from /v2/config (zones[].emit_src_hints) into
     * wpc_v2_orch_src_hints_<zone> by wpc_v2_config_sync_zones(). Same dual-key resolution as
     * wpc_v2_zone_cdn_disabled (primary numeric PZ → CF-CNAME fallback). Returns true/false when the
     * orch has echoed it for this zone, or NULL when it hasn't (caller falls back to the legacy global
     * wpc_src_hint_enabled option + filter). Read by wps_rewriteLogic::src_hint_enabled().
     *
     * @return bool|null
     */
    function wpc_v2_zone_src_hints()
    {
        if (!function_exists('get_option') || !function_exists('wpc_v2_get_zone_id')) return null;
        $sk = function_exists('sanitize_key');
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone !== '') {
            $v = get_option('wpc_v2_orch_src_hints_' . ($sk ? sanitize_key($zone) : $zone), null);
            if ($v !== null) return ($v === '1' || $v === 1 || $v === true);
        }
        if (function_exists('wpc_v2_orch_witness_cname_keys')) {
            foreach (wpc_v2_orch_witness_cname_keys() as $cn) {
                $v = get_option('wpc_v2_orch_src_hints_' . ($sk ? sanitize_key($cn) : $cn), null);
                if ($v !== null) return ($v === '1' || $v === 1 || $v === true);
            }
        }
        return null; // orch hasn't echoed for this zone → caller uses the legacy global + filter
    }
}

/* ============================================================================
 * AUTO-DISABLE (CDN liveness resilience).
 *
 * Self-heals a sustained CDN/edge outage: a lightweight LIVENESS probe (HEAD to a real
 * customer image on the zone, off the render path via loopback-to-self) drives a per-zone
 * up/down state machine. On "down" the plugin emits ZERO CDN URLs (reusing the cdn_disabled
 * suppression surfaces + purge) so every path — incl. legacy Mode-C + <picture>, which have
 * no per-image onerror net — falls back to origin. Flag-gated (wpc_cdn_auto_disable),
 * DEFAULT OFF → fully inert (the template_redirect trigger + every helper early-return).
 *
 * NOT the resolver's 12h capability-verify (that's "can this zone negotiate?", stable). This is
 * a separate fast liveness signal that OVERRIDES the resolved tier — same effect as cdn_disabled,
 * different trigger. Conservative thresholds (3 fails→demote; 2 ok + 5min floor→re-promote) +
 * a once-per-flip cache purge prevent flap-storm purges.
 * ========================================================================== */

if (!function_exists('wpc_v2_auto_disable_enabled')) {
    function wpc_v2_auto_disable_enabled()
    {
        // Hard opt-out for resource-constrained hosts (tiny VPS, low max_children) — define in
        // wp-config: define('WPC_DISABLE_AUTO_RESILIENCE', true); kills the probe + override entirely.
        if (defined('WPC_DISABLE_AUTO_RESILIENCE') && WPC_DISABLE_AUTO_RESILIENCE) return false;
        if (!function_exists('get_site_option')) return false;
        return (bool) apply_filters('wpc_cdn_auto_disable', (bool) get_site_option('wpc_cdn_auto_disable', false));
    }
}

if (!function_exists('wpc_v2_zone_auto_disabled')) {
    /** True when the liveness state machine has demoted this zone to origin-only ("down"). */
    function wpc_v2_zone_auto_disabled()
    {
        if (!wpc_v2_auto_disable_enabled() || !function_exists('wpc_v2_get_zone_id')) return false;
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone === '') return false;
        $key = 'wpc_v2_auto_disable_' . (function_exists('sanitize_key') ? sanitize_key($zone) : $zone);
        return get_option($key, 'up') === 'down';
    }
}

if (!function_exists('wpc_v2_zone_cdn_suppressed')) {
    /**
     * Unified "emit ZERO CDN URLs for this zone" gate — the single concept every emission
     * surface checks. TRUE when EITHER the orch master-kill (cdn_disabled) OR the liveness
     * auto-disable says so. Default-off for both → no behavior change.
     */
    function wpc_v2_zone_cdn_suppressed()
    {
        // FAIL-SAFE on an UNCONFIRMED environment (a staging clone, or the window after a plugin update
        // before its re-provision lands): emit ZERO CDN URLs → serve origin (always works) until the
        // /v2/config sync confirms provisioning for THIS host (fingerprint stamped on a 2xx). A clone's
        // own zapwp/CF host may itself be unprovisioned, so origin — not zapwp — is the only safe floor.
        // Riding the master-kill covers CF + Bunny + transform + fonts on front end + admin in one line.
        // Cached per request (the fingerprint flips only on a 2xx, in a separate request).
        static $env = null;
        if ($env === null) {
            $env = (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed());
        }
        if ($env) return true;
        // A CF-direct zone (customer's CDN is their own cdn.* Cloudflare cname) whose cname is not yet
        // verified-live must emit ORIGIN, not the internal *.zapwp.com pull-zone host they never configured
        // as a delivery host. Suppress until wpc_v2_cf_cname_reverify() promotes it. Net: origin → CF, never Bunny.
        static $cfwait = null;
        if ($cfwait === null) {
            $cfc = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $cf  = (defined('WPS_IC_CF') && function_exists('get_option')) ? get_option(WPS_IC_CF) : false;
            $ver = function_exists('get_option') ? get_option('wpc_cf_cname_verified') : '1';
            $cfwait = ($cfc !== '' && is_array($cf) && !empty($cf['settings']['cdn']) && $ver !== '1' && $ver !== 1);
        }
        if ($cfwait) return true;
        return (function_exists('wpc_v2_zone_cdn_disabled') && wpc_v2_zone_cdn_disabled())
            || (function_exists('wpc_v2_zone_auto_disabled') && wpc_v2_zone_auto_disabled());
    }
}

if (!function_exists('wpc_v2_cdn_canary_url')) {
    /**
     * A real same-site image, transformed onto the zone, used as the liveness probe target.
     * (NOT a healthcheck endpoint — those can 200 while image-serving is broken: different code
     * path.) Cached in an option so we don't query attachments on every probe. Uses the RAW zone
     * option (never the suppressed runtime static) so the probe keeps testing the real edge even
     * while auto-disabled, to detect recovery. Returns '' when no zone or no image exists.
     */
    function wpc_v2_cdn_canary_url()
    {
        $cached = get_option('wpc_v2_cdn_canary', '');
        if (is_string($cached) && $cached !== '') return $cached;
        $zone = (string) get_option('ic_cdn_zone_name', '');
        if ($zone === '' || !function_exists('get_posts')) return '';
        // Pick the OLDEST images first — they're the most stable (least likely to be deleted) — and
        // require the file to actually exist ON DISK (a verified, servable target). Scan a small batch
        // so a few missing files don't defeat selection.
        $ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 20, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true]);
        foreach ((array) $ids as $id) {
            $path = function_exists('get_attached_file') ? get_attached_file($id) : '';
            if ($path && @is_file($path)) {
                $url = wp_get_attachment_url($id);
                if ($url) {
                    $canary = 'https://' . $zone . '/m:0/a:' . $url;
                    update_option('wpc_v2_cdn_canary', $canary, false);
                    return $canary;
                }
            }
        }
        return '';
    }
}

if (!function_exists('wpc_v2_auto_disable_purge')) {
    /** Reuse WPC's canonical full purge — runs in the admin-ajax handler context. */
    function wpc_v2_auto_disable_purge()
    {
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            do_action('wps_ic_purge_all_cache');
        }
    }
}

if (!function_exists('wpc_v2_record_liveness')) {
    /**
     * Liveness state machine. Conservative by design: 3 consecutive failures demote to "down";
     * 2 consecutive successes AND a 5-min floor since the last flip re-promote to "up". Each flip
     * purges the HTML cache ONCE (the 5-min floor + thresholds bound purge frequency → no storm).
     */
    function wpc_v2_record_liveness($zone, $ok)
    {
        $zk = function_exists('sanitize_key') ? sanitize_key($zone) : $zone;
        $state_key   = 'wpc_v2_auto_disable_' . $zk;
        $fail_key    = 'wpc_v2_auto_disable_fails_' . $zk;
        $ok_key      = 'wpc_v2_auto_disable_oks_' . $zk;
        $flip_key    = 'wpc_v2_auto_disable_flipped_' . $zk;
        $lastfail_key = 'wpc_v2_auto_disable_lastfail_' . $zk; // drives both the demote min-spacing
        $state = get_option($state_key, 'up');                //   and the re-promote recency guard
        $now = time();

        if ($ok) {
            update_option($fail_key, 0, false);
            if ($state === 'down') {
                $oks = (int) get_option($ok_key, 0) + 1;
                update_option($ok_key, $oks, false);
                $since_flip = $now - (int) get_option($flip_key, 0);
                $since_fail = $now - (int) get_option($lastfail_key, 0);
                // Re-promote requires CONFIRMED stability: 2 consecutive OKs (oks reset on any fail
                // below) AND ≥5 min since the demote (floor) AND ≥5 min since the LAST failure of any
                // kind (so an intermittent CDN can't re-promote 60s after a blip). Idempotent re-read
                // of state guards against a concurrent double-flip / double-purge.
                if ($oks >= 2 && $since_flip >= 300 && $since_fail >= 300 && get_option($state_key, 'up') === 'down') {
                    update_option($state_key, 'up', false);
                    update_option($flip_key, $now, false);
                    update_option($ok_key, 0, false);
                    error_log('[WPC auto-disable] zone ' . $zone . ' RE-PROMOTED (CDN recovered)');
                    wpc_v2_auto_disable_purge();
                }
            }
        } else {
            update_option($ok_key, 0, false);
            $prev_fail = (int) get_option($lastfail_key, 0);
            update_option($lastfail_key, $now, false); // every failure (counted or not) → recency clock
            // Min 60s spacing between COUNTED failures → a demote needs SUSTAINED failure across time,
            // not 3 probes bunched into a second (which a transient-lockout miss + concurrency could
            // otherwise produce). Also collapses concurrent same-window failures to a single count.
            if ($state === 'up' && ($now - $prev_fail) >= 60) {
                $fails = (int) get_option($fail_key, 0) + 1;
                update_option($fail_key, $fails, false);
                if ($fails >= 3 && get_option($state_key, 'up') === 'up') { // idempotent re-read
                    update_option($state_key, 'down', false);
                    update_option($flip_key, $now, false);
                    update_option($fail_key, 0, false);
                    error_log('[WPC auto-disable] zone ' . $zone . ' DEMOTED to origin (CDN unreachable x3, spaced)');
                    wpc_v2_auto_disable_purge();
                }
            }
        }
    }
}

if (!function_exists('wpc_v2_maybe_probe_cdn_liveness')) {
    /**
     * template_redirect trigger (front-end only; never admin/ajax/cron). When the flag is on and
     * the per-zone liveness check is stale (>5 min), fire a NON-BLOCKING loopback to the admin-ajax
     * probe handler — the render finishes immediately; only the handler does the blocking HEAD.
     * The "checking" transient is set FIRST (5-min TTL) as both the staleness gate and the
     * thundering-herd lockout. Default-off → returns on the first line.
     */
    function wpc_v2_maybe_probe_cdn_liveness()
    {
        if (!wpc_v2_auto_disable_enabled() || !function_exists('wpc_v2_get_zone_id')) return;
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone === '' || (string) get_option('ic_cdn_zone_name', '') === '') return;
        // ONLY probe when the CDN is the active delivery path — there's nothing to health-check on a
        // site that isn't serving via the CDN, so no ping fires there. (live-cdn stays '1' while
        // auto-disabled — the runtime zone is blanked, not the setting — so recovery is still detected.)
        $s = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') return;
        $zk = function_exists('sanitize_key') ? sanitize_key($zone) : $zone;
        if (get_transient('wpc_v2_cdn_liveness_check_' . $zk) !== false) return; // fresh / locked
        // Lockout TTL bounds probe frequency (≤1 probe per window). Base is filterable (raise it on a
        // many-site / small box to cut overhead), and we add 0-120s JITTER so probes across many sites
        // sharing one host don't all sync to the same instant during a sustained outage (no thundering
        // herd of simultaneous 2s timeouts). Default ≈5-7 min.
        $ttl = max(60, (int) apply_filters('wpc_liveness_lockout_ttl', 300)) + mt_rand(0, 120);
        set_transient('wpc_v2_cdn_liveness_check_' . $zk, time(), $ttl);
        // Local-vhost loopback: a wp_remote_post to admin_url()'s PUBLIC host hits the CDN/WAF edge on a
        // datacenter-IP site → returns truthy but the probe self-POST never reaches local PHP-FPM, so the
        // state machine never samples. Connect-only via the shared wps_ic_ajax:: helper; cookieless nonce
        // auth. Flag-gated + fail-safe (no sample → stays 'up').
        $lvp = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($lvp['host'])) {
            $lv_https = (!empty($lvp['scheme']) && $lvp['scheme'] === 'https');
            $lv_port  = !empty($lvp['port']) ? (int) $lvp['port'] : ($lv_https ? 443 : 80);
            $lv_host  = (string) $lvp['host'];
            $lv_path  = (!empty($lvp['path']) ? $lvp['path'] : '/') . '?action=wpc_cdn_liveness_probe';
            $lv_body  = http_build_query(['zone_id' => $zone, 'nonce' => wp_create_nonce('wpc_cdn_liveness')]);
            $lv_req   = "POST {$lv_path} HTTP/1.1\r\nHost: {$lv_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($lv_body) . "\r\nConnection: close\r\nUser-Agent: WPCLiveness/1.0\r\n\r\n" . $lv_body;
            $lv_fp = wps_ic_ajax::wpc_loopback_open_socket($lv_host, $lv_port, $lv_https, 0.2);
            if ($lv_fp) { @stream_set_timeout($lv_fp, 0, 100000); @fwrite($lv_fp, $lv_req); @fclose($lv_fp); }
        }
    }
    add_action('template_redirect', 'wpc_v2_maybe_probe_cdn_liveness', 99);
}

if (!function_exists('wpc_v2_cdn_liveness_probe_handler')) {
    /**
     * admin-ajax handler — runs OFF the visitor render path, so the blocking HEAD (2s cap) never
     * touches anyone's LCP. HEADs the canary; 5xx/error/timeout = a failure for the state machine.
     */
    function wpc_v2_cdn_liveness_probe_handler()
    {
        if (!wpc_v2_auto_disable_enabled()) wp_die('', '', ['response' => 200]);
        if (!check_ajax_referer('wpc_cdn_liveness', 'nonce', false)) wp_die('', '', ['response' => 200]);
        // Reaching here PROVES PHP-originated loopback to admin-ajax works on this host. Some hardened
        // hosts block it (then auto-disable stays inert = fail-safe); this positive flag lets ops confirm
        // loopback CAN work — its absence on an enabled+probing site means loopback is blocked.
        if (get_option('wpc_v2_loopback_ok', '0') !== '1') { update_option('wpc_v2_loopback_ok', '1', false); }
        $zone = isset($_POST['zone_id']) ? sanitize_text_field(wp_unslash($_POST['zone_id'])) : '';
        if ($zone === '') wp_die('', '', ['response' => 200]);
        $canary = wpc_v2_cdn_canary_url();
        if ($canary === '') wp_die('', '', ['response' => 200]); // can't probe (no image/zone) → no-op
        // Default 1s (a healthy edge HEADs in <100ms; this ceiling is only paid when the CDN is
        // actually down/slow). Filterable: many-site shared hosts can drop to 0.5s to cut the
        // worker-occupancy of a synchronized-outage probe; min-spacing+3-fail guards a slow-but-up edge.
        $timeout = (float) apply_filters('wpc_liveness_probe_timeout', 1.0);
        if ($timeout < 0.3) $timeout = 0.3;
        $r = wp_remote_head($canary, ['timeout' => $timeout, 'sslverify' => false, 'redirection' => 0]);
        $code = (int) wp_remote_retrieve_response_code($r);
        $down = is_wp_error($r) || $code >= 500 || $code === 0;
        // A 404 means the canary file was deleted (the edge IS up: it answered). Treat as alive, but
        // re-pick the canary after 3 consecutive 404s so the target self-heals.
        if (!$down && $code === 404) {
            $n404 = (int) get_option('wpc_v2_cdn_canary_404', 0) + 1;
            if ($n404 >= 3) { delete_option('wpc_v2_cdn_canary'); delete_option('wpc_v2_cdn_canary_404'); }
            else { update_option('wpc_v2_cdn_canary_404', $n404, false); }
        } elseif (!$down) {
            delete_option('wpc_v2_cdn_canary_404');
        }
        wpc_v2_record_liveness($zone, !$down);
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_nopriv_wpc_cdn_liveness_probe', 'wpc_v2_cdn_liveness_probe_handler');
    add_action('wp_ajax_wpc_cdn_liveness_probe', 'wpc_v2_cdn_liveness_probe_handler');
}

if (!function_exists('wpc_v2_asset_mime_probe_run')) {
    /**
     * Run the CF asset-MIME proof: GET one known zone CSS URL and confirm it returns 200 text/css.
     * Single source of truth for the proof — shared by the admin/cron inline path
     * (wps_rewriteLogic::maybe_probe_asset_mime, which passes the live emit host) and the non-blocking
     * loopback handler below (which passes nothing, so the zone is derived from config server-side —
     * client input is never trusted, closing SSRF on the nopriv endpoint). Writes the durable verdict
     * wpc_v2_cf_asset_mime_ok='1' on success, else a 2h retry transient, and clears the in-flight lock.
     */
    function wpc_v2_asset_mime_probe_run($probe_zone = '')
    {
        $probe_zone = preg_replace('#/.*$#', '', trim((string) $probe_zone));
        if ($probe_zone === '') {
            // No server-supplied zone (loopback handler path) → derive from config: CF cname (when CF
            // CDN on) → custom cname → pod zone. Same chain the render path resolves to.
            $cf_cname  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $cf_set    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
            $cf_cdn_on = is_array($cf_set) && !empty($cf_set['settings']['cdn']);
            $probe_zone = ($cf_cname !== '' && $cf_cdn_on)
                ? $cf_cname
                : (trim((string) get_option('ic_custom_cname', '')) ?: (string) get_option('ic_cdn_zone_name', ''));
            $probe_zone = preg_replace('#/.*$#', '', trim((string) $probe_zone));
        }
        if ($probe_zone === '') { delete_transient('wpc_v2_asset_probe_inflight'); return false; }
        // GET (not HEAD — pod HEAD handling unverified). 200 + text/css = a converged edge; image/css /
        // 403 / error = not yet. Body capped at 8KB.
        $probe_r  = wp_remote_get('https://' . $probe_zone . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 8192]);
        $probe_ct = is_wp_error($probe_r) ? '' : strtolower((string) wp_remote_retrieve_header($probe_r, 'content-type'));
        $probe_ok = ((int) wp_remote_retrieve_response_code($probe_r) === 200) && (strpos($probe_ct, 'text/css') === 0);
        delete_transient('wpc_v2_asset_probe_inflight');
        if ($probe_ok) {
            update_option('wpc_v2_cf_asset_mime_ok', '1', true);
            delete_transient('wpc_v2_cf_asset_mime_retry');
        } else {
            set_transient('wpc_v2_cf_asset_mime_retry', '0', 2 * HOUR_IN_SECONDS);
        }
        error_log('[WPC NaturalAssets] cf_asset_mime_probe ct=' . ($probe_ct !== '' ? $probe_ct : 'error') . ' ok=' . (int) $probe_ok);
        return (bool) $probe_ok;
    }
}

if (!function_exists('wpc_v2_asset_mime_probe_handler')) {
    /**
     * admin-ajax handler for the asset-MIME proof — runs in its OWN request (off the visitor render
     * path), so the ≤3s GET never touches anyone's TTFB. Fired by the non-blocking loopback in
     * wps_rewriteLogic::fire_asset_mime_probe_loopback() on a cold front-end render. The zone is derived
     * server-side inside the run fn; no client input is trusted.
     */
    function wpc_v2_asset_mime_probe_handler()
    {
        if (!check_ajax_referer('wpc_asset_mime', 'nonce', false)) wp_die('', '', ['response' => 200]);
        // Reaching here proves PHP-originated loopback to admin-ajax works on this host (ops signal).
        if (get_option('wpc_v2_loopback_ok', '0') !== '1') { update_option('wpc_v2_loopback_ok', '1', false); }
        wpc_v2_asset_mime_probe_run();
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_nopriv_wpc_asset_mime_probe', 'wpc_v2_asset_mime_probe_handler');
    add_action('wp_ajax_wpc_asset_mime_probe', 'wpc_v2_asset_mime_probe_handler');
}

/**
 * Read the local mirror of the lazy_cdn enable state for the plugin's
 * primary zone. Used by admin UI to render the toggle's current state.
 *
 * Returns true only if BOTH:
 *   - A zone_id is configured (otherwise toggle is meaningless)
 *   - The mirror option for that zone is '1'
 */
if (!function_exists('wpc_v2_get_lazy_enabled')) {
    function wpc_v2_get_lazy_enabled()
    {
        // SINGLE SOURCE OF TRUTH. lazy_cdn ("Smart Delivery") is enabled iff that Optimization
        // Strategy is selected AND a zone is configured. Deriving from the strategy (not a separate
        // per-zone flag that could contradict the radio → the "backfill when off" bug) keeps every
        // consumer consistent: delivery emission, orch /v2/config sync, shutdown-drain, and the
        // trigger-scanner kill-switch.
        //
        // MUST be `=== 'lazy_cdn'`, NOT wpc_lazy_mode_active() (also true for lazy_full / lazy_smart):
        // this means lazy_cdn SPECIFICALLY — the trigger-scanner gates "scanner OFF" on it (other lazy_*
        // keep the scanner ON as a fallback), so broadening the predicate would silently disable that.
        if (!function_exists('wpc_get_optimization_mode')
            || wpc_get_optimization_mode() !== 'lazy_cdn') {
            return false;
        }
        $zone_id = function_exists('wpc_v2_get_zone_id') ? wpc_v2_get_zone_id() : '';
        return $zone_id !== '';
    }
}

/**
 * AJAX endpoint for the admin toggle. Accepts {enabled: 0|1, nonce: ...},
 * syncs to orch via /v2/config, returns updated state.
 *
 * Capability gate: manage_wpc_settings (same as other plugin admin AJAX).
 * Nonce: standard wps_ic_nonce_action (matches plugin's existing pattern).
 */
if (!function_exists('wpc_v2_ajax_lazy_cdn_toggle')) {
    function wpc_v2_ajax_lazy_cdn_toggle()
    {
        if (!current_user_can('manage_wpc_settings')
            || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }

        $zone_id = wpc_v2_get_zone_id();
        if ($zone_id === '') {
            wp_send_json_error([
                'msg' => 'No zone_id configured for this site. Reconnect your API key in Settings → Connect to register the zone.',
                'reason' => 'no_zone_id',
            ], 400);
        }

        $enabled = !empty($_POST['enabled']) && $_POST['enabled'] !== '0';

        // Couple lazy_cdn ON to pull-infrastructure ON: the page-load drain trigger and
        // wpc_v2_pull_drain_fire both bail unless wpc_v2_pull_enabled is true, so without this
        // a customer enabling lazy_cdn sees zero variants land (dormant pull plumbing). Only flip
        // ON, never OFF (other features — push+pull dedupe, bulk fallback — may depend on it).
        if ($enabled) {
            $current_pull_enabled = (bool) get_site_option('wpc_v2_pull_enabled', false);
            if (!$current_pull_enabled) {
                update_site_option('wpc_v2_pull_enabled', 1);
                error_log('[WPC ConfigSync] auto-enabled wpc_v2_pull_enabled because lazy_cdn was turned on for zone=' . $zone_id);
            }
        }

        $result  = wpc_v2_config_sync_lazy_enabled($zone_id, $enabled);

        if (empty($result['ok'])) {
            wp_send_json_error([
                'msg' => sprintf(
                    'Could not sync to orchestrator (%s). Please retry; if it persists, contact support.',
                    isset($result['reason']) ? $result['reason'] : 'unknown'
                ),
                'http_code' => isset($result['http_code']) ? $result['http_code'] : 0,
            ], 502);
        }

        wp_send_json_success([
            'zone_id' => $zone_id,
            'enabled' => $enabled,
        ]);
    }
}
// Toggle AJAX UNHOOKED: left registered it would be an orphaned authenticated endpoint writing the
// dead per-zone flag + POSTing a lazy_enabled value the strategy radio doesn't agree with — a back-door
// to the inconsistency this removes. The function stays defined (harmless) but nothing reaches it;
// lazy_cdn is controlled solely by the Optimization Strategy setting now.
// add_action('wp_ajax_wpc_v2_lazy_cdn_toggle', 'wpc_v2_ajax_lazy_cdn_toggle');

/**
 * WP-CLI command: `wp wpc lazy-cdn enable` / `wp wpc lazy-cdn disable` /
 * `wp wpc lazy-cdn status`. For operators + canary customers + E2E testing
 * before the UI lands fully.
 */
if (defined('WP_CLI') && WP_CLI && !class_exists('WPC_V2_LazyCDN_CLI')) {
    class WPC_V2_LazyCDN_CLI
    {
        /**
         * Enable lazy_cdn delivery for this site's zone.
         *
         * ## EXAMPLES
         *     wp wpc lazy-cdn enable
         */
        public function enable()
        {
            $this->_toggle(true);
        }

        /**
         * Disable lazy_cdn delivery for this site's zone.
         */
        public function disable()
        {
            $this->_toggle(false);
        }

        /**
         * Show current state.
         */
        public function status()
        {
            $zone = wpc_v2_get_zone_id();
            $enabled = wpc_v2_get_lazy_enabled();
            \WP_CLI::log('zone_id:      ' . ($zone !== '' ? $zone : '(not configured)'));
            \WP_CLI::log('lazy_enabled: ' . ($enabled ? 'YES' : 'no'));
        }

        private function _toggle($enable)
        {
            $zone = wpc_v2_get_zone_id();
            if ($zone === '') {
                \WP_CLI::error('No zone_id configured. Reconnect your API key first.');
                return;
            }
            // lazy_cdn is the Optimization Strategy setting now — set THAT, not the dead per-zone
            // flag. The update_option_<settings> hook then does the orch /v2/config sync + flips
            // wpc_v2_pull_enabled, so CLI, settings UI, and the radio all drive the same path.
            // Disable only downgrades when currently lazy_cdn, so it never clobbers manual/legacy.
            if (!defined('WPS_IC_SETTINGS')) {
                \WP_CLI::error('WPS_IC_SETTINGS not defined.');
                return;
            }
            $settings = get_option(WPS_IC_SETTINGS, []);
            if (!is_array($settings)) {
                $settings = [];
            }
            $was_lazy_cdn = (($settings['wpc_optimization_mode'] ?? '') === 'lazy_cdn');
            if ($enable) {
                $settings['wpc_optimization_mode'] = 'lazy_cdn';
            } elseif ($was_lazy_cdn) {
                $settings['wpc_optimization_mode'] = 'legacy';
            }
            update_option(WPS_IC_SETTINGS, $settings); // fires maybe_sync (orch + pull)

            $now = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
            \WP_CLI::success(
                ($enable ? 'Enabled' : 'Disabled') . ' lazy_cdn (Optimization Strategy) for zone '
                . $zone . ' — lazy_enabled now ' . ($now ? 'YES' : 'no')
                . '. Orch sync + pull handled by the settings hook.'
            );
        }
    }
    \WP_CLI::add_command('wpc lazy-cdn', 'WPC_V2_LazyCDN_CLI');
}

/**
 * Admin notice: customer opt-in toggle for lazy_cdn. Scoped to general WP
 * admin screens (Dashboard, Plugins list) where it's a natural discovery
 * surface — NOT shown on plugin's own admin pages where it would intrude
 * on task-focused workflows. Markup uses stacked vertical layout so the
 * title doesn't collapse on narrow admin chrome.
 *
 * Customer flow: click "Enable Lazy Backfill" → AJAX wpc_v2_lazy_cdn_toggle
 * → orch /v2/config UPDATE agencySites.lazy_cdn_active=1 → CDN picks up
 * within 60s via apikeyCache → lazy_cdn live.
 *
 * Per-user dismiss is respected (user_meta).
 *
 * Revert: change $allowed_screens to empty array or delete the
 * add_action('admin_notices', ...) line below.
 */
if (!function_exists('wpc_v2_lazy_cdn_admin_notice')) {
    function wpc_v2_lazy_cdn_admin_notice()
    {
        if (!current_user_can('manage_wpc_settings')) return;

        // Scope to ONLY Dashboard + Plugins-list screens (general WP admin, full content width, a
        // natural discovery surface) — NOT the plugin's own pages, where it would intrude on task
        // workflows. Revert: change $allowed_screens or delete this function body.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id)) return;
        $allowed_screens = ['dashboard', 'plugins'];
        if (!in_array((string) $screen->id, $allowed_screens, true)) return;

        // Suppress if user has dismissed
        $dismissed = (int) get_user_meta(get_current_user_id(), 'wpc_v2_lazy_cdn_notice_dismissed', true);
        if ($dismissed) return;

        $zone_id = wpc_v2_get_zone_id();
        $enabled = wpc_v2_get_lazy_enabled();
        $nonce   = wp_create_nonce('wps_ic_nonce_action');

        $status_label = $enabled ? 'Enabled' : 'Disabled';
        $action_label = $enabled ? 'Disable' : 'Enable';
        $next_state   = $enabled ? '0' : '1';
        $css_class    = $enabled ? 'notice-success' : 'notice-info';

        $not_configured = ($zone_id === '');

        // White-label-aware brand string: wpc_get_plugin_name() respects a whitelabel override, so the
        // hardcoded 'WP Compress' branding doesn't leak to WL'd agencies' customers. Falls back to 'WP Compress'.
        $brand_name = function_exists('wpc_get_plugin_name')
            ? wpc_get_plugin_name()
            : __('WP Compress', 'wp-compress-image-optimizer');

        // Stacked layout (not flex-row) so the title stays width-resilient on narrow admin chrome.
        ?>
        <div class="notice <?php echo esc_attr($css_class); ?> is-dismissible" data-wpc-v2-lazy-cdn-notice style="padding:14px 16px;">
            <div style="margin:0 0 6px;font-size:14px;">
                <strong style="font-size:14px;"><?php echo esc_html($brand_name); ?> — Lazy Backfill</strong>
                <span style="color:#646970;margin-left:8px;">Status: <strong style="color:<?php echo $enabled ? '#00855a' : '#646970'; ?>;"><?php echo esc_html($status_label); ?></strong></span>
            </div>
            <p style="margin:0 0 10px;color:#3c434a;">
                Optimized variants are generated on first browser request and cached for everyone. Saves storage on unused image sizes.
            </p>
            <p style="margin:0;">
                <?php if ($not_configured) : ?>
                    <em style="color:#646970;">Not configured — please reconnect your API key in <strong>Settings → Connect</strong> to register this site's zone.</em>
                <?php else : ?>
                    <button type="button"
                            class="button button-primary"
                            data-wpc-v2-lazy-cdn-toggle
                            data-next-state="<?php echo esc_attr($next_state); ?>"
                            data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php echo esc_html($action_label); ?> Lazy Backfill
                    </button>
                <?php endif; ?>
            </p>
        </div>
        <script>
        (function(){
            var btn = document.querySelector('[data-wpc-v2-lazy-cdn-toggle]');
            if (!btn) return;
            btn.addEventListener('click', function(e){
                e.preventDefault();
                btn.disabled = true;
                var orig = btn.textContent;
                btn.textContent = 'Saving…';
                var fd = new FormData();
                fd.append('action', 'wpc_v2_lazy_cdn_toggle');
                fd.append('nonce', btn.dataset.nonce);
                fd.append('enabled', btn.dataset.nextState);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        if (j && j.success) {
                            // Reload so notice re-renders with new state
                            window.location.reload();
                        } else {
                            var msg = (j && j.data && j.data.msg) ? j.data.msg : 'Toggle failed. Please retry.';
                            alert(msg);
                            btn.disabled = false;
                            btn.textContent = orig;
                        }
                    })
                    .catch(function(){
                        alert('Network error. Please retry.');
                        btn.disabled = false;
                        btn.textContent = orig;
                    });
            });
            // Dismiss → remember in user meta via AJAX
            var notice = document.querySelector('[data-wpc-v2-lazy-cdn-notice]');
            if (notice) {
                notice.addEventListener('click', function(e){
                    if (e.target && e.target.classList && e.target.classList.contains('notice-dismiss')) {
                        var fd = new FormData();
                        fd.append('action', 'wpc_v2_lazy_cdn_notice_dismiss');
                        fd.append('nonce', btn ? btn.dataset.nonce : '');
                        fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    }
                });
            }
        })();
        </script>
        <?php
    }
}
// Lazy Backfill admin notice REMOVED — the Optimization Strategy setting is the only control now
// (a separate notice + toggle could contradict the radio = the "backfill when off" bug). The render
// fn + its toggle/dismiss AJAX stay defined (unhooked) so any in-flight dismiss POST still 200s.
// Revert: re-add the add_action line below.
// add_action('admin_notices', 'wpc_v2_lazy_cdn_admin_notice');

/**
 * AJAX endpoint for dismissing the lazy_cdn admin notice. Per-user
 * dismissal stored in user_meta so future page loads don't re-show.
 */
if (!function_exists('wpc_v2_ajax_lazy_cdn_notice_dismiss')) {
    function wpc_v2_ajax_lazy_cdn_notice_dismiss()
    {
        if (!current_user_can('manage_wpc_settings')
            || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        update_user_meta(get_current_user_id(), 'wpc_v2_lazy_cdn_notice_dismissed', 1);
        wp_send_json_success(['dismissed' => true]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_cdn_notice_dismiss', 'wpc_v2_ajax_lazy_cdn_notice_dismiss');

/**
 * Settings-save trigger for image-delivery config.
 *
 * Fires whenever the main settings option (wps_ic_settings) is updated. Pushes
 * the standing quality + max-width to orch's /v2/config ONLY when they differ
 * from the last value we successfully synced (mirror at
 * wpc_v2_synced_image_config) — covers both "changed since last push" and
 * "first push" (mirror empty). Reuses the exact same signed endpoint + HMAC as
 * the lazy_enabled path; the image config now rides on every payload.
 *
 * Gated on a configured zone_id — no-op on sites that haven't registered. The
 * sync itself is failure-tolerant (logs + returns on orch error); a settings
 * save never fails because of it. Idempotent: repeated saves with unchanged
 * quality/width short-circuit on the mirror compare.
 *
 * Revert: remove this function + the add_action below.
 */
if (!function_exists('wpc_v2_maybe_sync_image_config')) {
    function wpc_v2_maybe_sync_image_config($old_value = null, $new_value = null)
    {
        // Run ONLY in admin / WP-CLI / cron contexts: update_option(wps_ic_settings) can fire on a
        // FRONT-END request (fillMissingSettings filling defaults on a visitor pageview), and the sync
        // below does a BLOCKING wp_remote_post — never block a visitor on it. A real settings-save is
        // admin-ajax so it still syncs; a front-end touch defers to the next admin/cron sync.
        $admin_ctx = (function_exists('is_admin') && is_admin())
            || (defined('WP_CLI') && WP_CLI)
            || (defined('DOING_CRON') && DOING_CRON);
        if (!$admin_ctx) {
            return;
        }
        if (!function_exists('wpc_v2_get_zone_id')) {
            return;
        }
        $zone_id = wpc_v2_get_zone_id();
        if ($zone_id === '' && function_exists('wpc_v2_resolve_zone_id')) {
            $zone_id = wpc_v2_resolve_zone_id(); // self-provision from apikey (admin/cli/cron ctx)
        }
        // Orch PATCH-targets the literal numeric Bunny PZ id. A non-numeric value (stale test-mirror
        // seed / legacy label) targets the wrong/no PZ — skip rather than POST a value orch can't
        // provision; the admin_init /v2/zone resolver heals it on the next load.
        if ($zone_id === '' || !ctype_digit((string) $zone_id)) {
            if ($zone_id !== '') {
                error_log('[WPC ConfigSync] skip sync: zone_id not a numeric Bunny PZ (' . $zone_id . ')');
            } else {
                // Site-side marker for the zero-POST cohort (fleet census greps these two skip lines).
                error_log('[WPC ConfigSync] skip sync: zone_id empty (not provisioned — the /v2/zone healer resolves it on the next admin page load)');
            }
            return; // nothing valid to sync to yet
        }

        // update_option_* fires after the DB + cache write, so this reads the
        // freshly-saved settings.
        $cfg     = wpc_v2_local_image_config();
        $del     = wpc_v2_delivery_config(); // delivery_mode + host_lock ride in the sync sig too
        $new_sig = $cfg['local_quality'] . '|' . $cfg['local_max_width']
            . '|' . $del['delivery_mode'] . '|' . $del['host_lock_mode'] . '|' . $del['host_lock_allow']
            . ($del['redirect_target'] === 'origin' ? '|origin' : '') // perturb the sig only on the origin flip (no fleet churn for default samehost)
            // INTENT fields in the sig: a Next-Gen or Images-tile flip re-syncs immediately (billing-grade).
            // tier/cf_detected deliberately NOT in the sig (tier flaps on re-verify, cf is env-derived); they
            // ride along as advisory values on whatever sync fires next.
            . '|ng:' . $del['nextgen'] . '|img:' . ($del['images_on'] ? '1' : '0');
        $last    = (string) get_option('wpc_v2_synced_image_config', '');

        // Also force a sync when lazy_cdn was just toggled via the Optimization Strategy, even if the
        // image-config sig is unchanged: the sig does NOT carry the optimization mode (delivery_mode
        // comes from emission_ready(), not the mode), so a pure lazy_cdn on/off would otherwise
        // short-circuit here, leaving the orch's lazy_cdn_active stale. This makes the radio a complete switch.
        $lazy_was = is_array($old_value) && ((($old_value['wpc_optimization_mode'] ?? '')) === 'lazy_cdn');
        $lazy_now = is_array($new_value) && ((($new_value['wpc_optimization_mode'] ?? '')) === 'lazy_cdn');
        $lazy_changed = ($lazy_was !== $lazy_now);

        // A prior sync the orch DEFERRED (db_error_*) leaves wpc_v2_config_sync_pending set. For a
        // lazy-ONLY change the image-config mirror is already current ($new_sig === $last), so without
        // this the rate-limited retry hook (admin_init, below) would skip forever and the orch would
        // never learn the lazy_cdn state. Don't short-circuit while a deferral is outstanding — the
        // retry's maybe_sync() call then actually re-POSTs, and config_sync_zones clears pending on 2xx.
        $sync_pending = (bool) get_option('wpc_v2_config_sync_pending', false);

        if ($new_sig === $last && !$lazy_changed && !$sync_pending) {
            return; // image config in sync, lazy_cdn state unchanged, no deferred retry outstanding
        }

        // When lazy_cdn was just turned ON, flip the pull plumbing on immediately (the
        // page-load drain + wpc_v2_pull_drain_fire both bail without it) — the same
        // coupling the removed toggle performed. Only ON, never off (other features can
        // depend on pull). The admin_init backstop (priority 23) also enforces this.
        if ($lazy_now && !$lazy_was && !get_site_option('wpc_v2_pull_enabled', false)) {
            update_site_option('wpc_v2_pull_enabled', 1);
            error_log('[WPC ConfigSync] enabled wpc_v2_pull_enabled (lazy_cdn turned on via settings)');
        }

        // DEFER the orch sync to background cron: this runs on the admin-ajax settings-SAVE, and the
        // /v2/config POST blocks up to 30s, so doing it inline hung the "Saving…" spinner on a slow orch.
        // The scheduled cron performs the same sync (reading the freshly-saved state) so the save returns
        // immediately, keeping full mirror/pending/retry bookkeeping.
        wpc_v2_schedule_config_sync();
    }
}
if (defined('WPS_IC_SETTINGS')) {
    add_action('update_option_' . WPS_IC_SETTINGS, 'wpc_v2_maybe_sync_image_config', 20, 2);
}

if (!function_exists('wpc_v2_purge_html_on_delivery_change')) {
    /**
     * Purge the HTML/page cache when a DELIVERY-affecting setting changes.
     * Switching delivery mode/format (picture<->edge, WebP<->AVIF, CDN on/off, lazy_cdn,
     * next-gen ceiling, srcset/adaptive knobs) changes the emitted <img>/<picture> markup, so
     * page-cached HTML (Breeze, wp-cio, Varnish, WP Fastest Cache, nginx-helper, …) keeps
     * serving the OLD markup until it organically expires (the AVIF-fossil class of bug). Call
     * wps_ic_cache::removeHtmlCacheFiles('all') — WPC's canonical full purge (own page cache +
     * Varnish + fires the wps_ic_purge_all_cache integration fan-out once) — so fresh markup is
     * emitted immediately. Covers BOTH save paths (the per-checkbox AJAX and the legacy settings
     * form), since both update_option(WPS_IC_SETTINGS) and thus trigger this hook.
     */
    function wpc_v2_purge_html_on_delivery_change($old_value = null, $new_value = null)
    {
        // NEVER purge on a front-end request — update_option(WPS_IC_SETTINGS) can fire on a
        // visitor pageview (fillMissingSettings filling defaults); a full purge there would
        // cause a cache stampede. Real settings-saves are admin-ajax / WP-CLI / cron.
        $admin_ctx = (function_exists('is_admin') && is_admin())
            || (defined('WP_CLI') && WP_CLI)
            || (defined('DOING_CRON') && DOING_CRON);
        if (!$admin_ctx || !is_array($new_value)) {
            return;
        }
        $old = is_array($old_value) ? $old_value : [];

        // Keys whose change alters the emitted delivery markup.
        $delivery_keys = [
            'wpc_nextgen', 'picture_webp', 'picture_avif', 'generate_webp', 'generate_adaptive',
            'adaptive', 'live-cdn', 'wpc_optimization_mode', 'modern_image_delivery',
            'nativeLazy', 'lazySkipCount', 'lazy-load', 'maxWidth', 'retina', 'retina-in-srcset',
            'css', 'js', 'fonts', 'wpc_edge_origin_bytes', // byte-source opt-in
        ];
        $changed = false;
        foreach ($delivery_keys as $k) {
            $o = array_key_exists($k, $old) ? (is_scalar($old[$k]) ? (string) $old[$k] : wp_json_encode($old[$k])) : null;
            $n = array_key_exists($k, $new_value) ? (is_scalar($new_value[$k]) ? (string) $new_value[$k] : wp_json_encode($new_value[$k])) : null;
            if ($o !== $n) { $changed = true; break; }
        }
        // The 'serve' sub-array (jpg/png/gif/svg/css/js/fonts CDN file-type toggles).
        if (!$changed) {
            $os = isset($old['serve']) ? wp_json_encode($old['serve']) : null;
            $ns = isset($new_value['serve']) ? wp_json_encode($new_value['serve']) : null;
            if ($os !== $ns) { $changed = true; }
        }
        if (!$changed) {
            return;
        }

        // MF-1 — Full purge: WPC's OWN page cache (wps_cacheHtml) + Varnish + every active
        // third-party cache integration. A bare do_action('wps_ic_purge_all_cache') reaches ONLY
        // the third-party integration listeners (none registered in WPC core), so on a site using
        // WPC-native HTML caching with no cache plugin it would clear NOTHING — the AVIF-fossil bug
        // would survive. removeHtmlCacheFiles('all') removes WPC's page cache, fires purgeVarnish(0),
        // AND fires the integration action ONCE (its own once-per-request guard also dedupes the
        // comms.class.php save purge). Mirrors v2-html-cache-purge.php's end-of-bulk handler.
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            do_action('wps_ic_purge_all_cache');
        }
        error_log('[WPC DeliveryCachePurge] delivery setting changed -> full HTML cache purge');
    }
}
if (defined('WPS_IC_SETTINGS')) {
    add_action('update_option_' . WPS_IC_SETTINGS, 'wpc_v2_purge_html_on_delivery_change', 21, 2);
}

/**
 * Auto-resolve wpc_v2_zone_id from the apikey, so a site self-provisions
 * its zone WITHOUT a manual seed and WITHOUT depending on orch's activation handler
 * having fired at API-key connect (which some installs miss — e.g. DB-restored,
 * cloned, or reconnected sites). orch knows the canonical zone_id (= Bunny PZ ID)
 * for an apikey via getSiteByApikey; we ask for it (HMAC-signed, same scheme as
 * /v2/config) and cache it into the same wpc_v2_zone_id option every reader uses.
 *
 * SAFE BY DESIGN:
 *   - Admin/CLI/cron contexts only (hooked on admin_init) — NEVER a front-end network
 *     call on a visitor pageview.
 *   - Short-circuits the instant a zone_id exists (the common case → zero network).
 *   - Rate-limited via a backoff transient so a miss (orch endpoint not shipped yet,
 *     or no matching row) does not retry on every admin page.
 *   - Fully graceful no-op until orch ships the endpoint: a 404 / non-2xx / empty
 *     body just sets the backoff and returns '' — nothing breaks, legacy behavior holds.
 *
 * ORCH CONTRACT (service-side, ~1 endpoint): POST {orch}/v2/zone  body {"apikey":"…"}
 *   signed with X-WPC-Sig (t=…,v1=hmac) → 200 {"zone_id":"<pzid>"}  (or
 *   {"zones":[{"zone_id":"…"}]}). orch already resolves apikey→zone in /v2/config;
 *   this just exposes that mapping read-only. (Alternatively orch could echo the
 *   matched zone_id in an existing response and we'd read it there.)
 *
 * Revert: delete this function + the add_action below.
 *
 * @return string Resolved zone_id, or '' if not resolvable yet.
 */
if (!function_exists('wpc_v2_resolve_zone_id')) {
    function wpc_v2_resolve_zone_id($force = false)
    {
        // Already provisioned with a NUMERIC Bunny PZ id → nothing to do. A non-numeric value
        // (a staging test-mirror seed, a legacy label, a clone) is NOT a valid PZ — orch PATCH-targets
        // the literal numeric id — so we fall through and re-resolve the real PZ from /v2/zone.
        if (!$force && function_exists('wpc_v2_get_zone_id')) {
            $existing = wpc_v2_get_zone_id();
            if ($existing !== '' && ctype_digit((string) $existing)) return $existing;
        }

        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch_url === '') return '';

        // Back off after a miss so we don't hammer orch on every admin page.
        if (!$force && function_exists('get_transient') && get_transient('wpc_v2_zone_resolve_backoff')) {
            return '';
        }
        $backoff = function () {
            if (function_exists('set_transient')) {
                set_transient('wpc_v2_zone_resolve_backoff', 1, defined('HOUR_IN_SECONDS') ? 6 * HOUR_IN_SECONDS : 21600);
            }
        };

        $body_raw = wp_json_encode(['apikey' => $apikey]);
        if ($body_raw === false) { $backoff(); return ''; }
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);

        $resp = wp_remote_post(rtrim($orch_url, '/') . '/v2/zone', [
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.08.42'),
            ],
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) { $backoff(); return ''; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { $backoff(); return ''; } // incl. 404 until orch ships /v2/zone

        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        $zone_id = '';
        if (is_array($data)) {
            if (!empty($data['zone_id'])) {
                $zone_id = (string) $data['zone_id'];
            } elseif (!empty($data['zones'][0]['zone_id'])) {
                $zone_id = (string) $data['zones'][0]['zone_id'];
            }
        }
        // Sanitize to the same shape sanitize_key() would accept downstream.
        $zone_id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $zone_id);
        if ($zone_id === '') { $backoff(); return ''; }

        update_option('wpc_v2_zone_id', $zone_id, false);
        if (function_exists('delete_transient')) delete_transient('wpc_v2_zone_resolve_backoff');
        error_log('[WPC ConfigSync] auto-resolved zone_id=' . $zone_id . ' from apikey via /v2/zone');
        return $zone_id;
    }
}

// Post-update one-shot: make "update → heal → sync" deterministic. Runs ONCE per plugin version, at
// priority 19 — BEFORE the /v2/zone healer (priority 20):
//   (a) resets the healer's 6h backoff so a long-stuck legacy-zone_id site heals on THIS page load;
//   (b) schedules the deferred config sync (+30s, runs on the next cron spawn — after this request, so
//       it sees the zone_id the healer wrote at priority 20) so every updated site pushes its state to
//       orch without a manual re-save.
// The deferred handler self-gates on a numeric zone_id, so it's a safe no-op on sites the healer couldn't fix.
add_action('admin_init', function () {
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    $cur = defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '';
    if ($cur === '' || (string) get_option('wpc_v2_postupdate_sync_ver', '') === $cur) return;
    update_option('wpc_v2_postupdate_sync_ver', $cur, false);
    if (function_exists('delete_transient')) {
        delete_transient('wpc_v2_zone_resolve_backoff');
    }
    if (!wp_next_scheduled('wpc_v2_deferred_config_sync')) {
        wp_schedule_single_event(time() + 30, 'wpc_v2_deferred_config_sync');
    }
    // Flush the HTML page cache on update so new delivery markup regenerates without a manual purge.
    // Once per version (gated by wpc_v2_postupdate_sync_ver above), so it's not a repeated cost.
    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
        wps_ic_cache::removeHtmlCacheFiles('all');
    } elseif (function_exists('do_action')) {
        do_action('wps_ic_purge_all_cache');
    }
    // (v7.03.31) Clear the orphaned connectivity verdict. The blocking simpleConnectivityTest probe that
    // wrote it is gone and nothing live reads it anymore (crit moved to crit-push, which self-checks).
    // Drop the stale option once so legacy 'failed' sites stop showing local-mode status display.
    if (function_exists('delete_option')) {
        delete_option('wpc-connectivity-status');
    }
    // (v7.03.39) Source Hints baked ON: backfill emit-src-hints='1' where it was never set, so the "Source
    // Hints" checkbox honestly reflects the on-by-default state and a settings-save persists it (rather than
    // a default-unchecked box writing '0' and silently turning ?src off). Only fills UNSET — an explicit
    // '0' opt-out is preserved. The gate (wps_rewriteLogic::src_hint_enabled) already defaults ON regardless;
    // this just keeps the UI honest. One-time (version-gated above).
    if (function_exists('get_option') && function_exists('update_option') && defined('WPS_IC_SETTINGS')) {
        $wpc_sh_set = get_option(WPS_IC_SETTINGS);
        if (is_array($wpc_sh_set) && !array_key_exists('emit-src-hints', $wpc_sh_set)) {
            $wpc_sh_set['emit-src-hints'] = '1';
            update_option(WPS_IC_SETTINGS, $wpc_sh_set);
        }
    }
    error_log('[WPC ConfigSync] post-update one-shot (v' . $cur . '): healer backoff reset + deferred sync scheduled + html cache flushed + stale connectivity verdict cleared + src-hints baked-on backfill');
}, 19);

// Self-provision the zone on admin page loads (admin/admin-ajax context only — never a
// front-end visitor pageview). No-op the moment a zone_id exists; rate-limited on miss.
add_action('admin_init', function () {
    // Never run the (blocking) /v2/zone resolver on an admin-ajax request — it would hang the action.
    // It still self-provisions on a regular admin page load, and AJAX uses that already-resolved value.
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (!function_exists('wpc_v2_get_zone_id')) return;
    $z = (string) wpc_v2_get_zone_id();
    // Resolve when missing OR non-numeric: Bunny PZ ids are integers; a non-numeric value (test-mirror
    // seed / legacy label) would be PATCH-targeted by orch and fail → heal it.
    if ($z === '' || !ctype_digit($z)) {
        wpc_v2_resolve_zone_id();
    }
}, 20);

// Re-attempt a DEFERRED /v2/config sync (orch returned a db_error_* in failed[] — couldn't provision
// the Bunny Edge Rules yet). Rate-limited by the 15-min deferred transient; maybe_sync re-POSTs (the
// mirror was NOT cached on the deferred attempt) and clears the pending flag on success. Admin/cron only.
add_action('admin_init', function () {
    if (!function_exists('wpc_v2_maybe_sync_image_config')) return;
    // Persistent pending flag set by a prior db_error deferral; cleared on the next clean success.
    if (!get_option('wpc_v2_config_sync_pending', false)) return;
    // Rate-limit re-attempts — the pending flag persists across admin loads, so throttle to ~15 min
    // (the backoff transient expiring just lets the next admin load try again; the flag never expires).
    if (function_exists('get_transient') && get_transient('wpc_v2_config_sync_retry_backoff')) return;
    if (function_exists('set_transient')) {
        set_transient('wpc_v2_config_sync_retry_backoff', 1, defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900);
    }
    wpc_v2_maybe_sync_image_config(); // re-POSTs (mirror left stale on deferral); clears pending on success
}, 21);

// Header-provisioning HEARTBEAT. On an admin pageview, re-fire /v2/config — even when settings are
// UNCHANGED — when this zone has either (a) NEVER successfully synced → self-provision it now (the
// PRIMARY job: reach a zone the orch cron hasn't hit, the first time an admin logs in), or (b) not
// synced in 6 months → refresh. The signed blob's HMAC is valid 1 YEAR; refreshing at 6mo = TTL/2
// re-signs at the halfway point so it never nears expiry. orch re-signs the x-wpc-config blob +
// re-PATCHes the Bunny Edge Rule on each fire. Admin/CLI/cron only (never a visitor).
add_action('admin_init', function () {
    if (!function_exists('wpc_v2_config_sync_lazy_enabled') || !function_exists('wpc_v2_get_zone_id')) return;

    // Let the deferred-retry hook (priority 21) own the post-db_error case — don't double-POST.
    if (get_option('wpc_v2_config_sync_pending', false)) return;

    // Connected + a resolvable zone identity. Fire on apikey: a numeric Bunny PZ id OR a CF-fronted
    // custom CNAME (orch resolves the latter by apikey and writes agencySites.cname). A "numeric PZ
    // only" gate would strand CF-CNAME zones — which have no numeric PZ — un-provisioned. Bail only
    // when NEITHER identity is present.
    $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
    if ($apikey === '' || $orch_url === '') return;
    $zone_id     = (string) wpc_v2_get_zone_id();
    $has_numeric = ($zone_id !== '' && ctype_digit($zone_id));
    $hb_cname    = trim((string) get_option('ic_custom_cname'));
    if ($hb_cname === '') $hb_cname = trim((string) get_option('ic_cdn_zone_name'));
    if (!$has_numeric && $hb_cname === '') return;

    // CLONE / ENV-CHANGE DETECT (before the refresh-throttle early-return below). A staging clone copies
    // the source DB's "already provisioned" witnesses, so every flag-gated trigger believes the NEW host
    // is provisioned and never fires /v2/config for it. The env fingerprint (home_url|DB_NAME|prefix)
    // differs on a clone even after a URL search-replace, so this catches it: wipe the copied witnesses +
    // arm force_provision so the sync re-fires for the new host. Pure option-writes — no HTTP on the render path.
    if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
        && function_exists('wpc_v2_provision_reset_for_env')) {
        wpc_v2_provision_reset_for_env();
    }

    // PROVISION EVERY connected zone, not just natural-URL-intent ones: the Bunny Edge Rules + AVIF/WebP
    // Vary that /v2/config lays down let a zone serve next-gen correctly (vary-correct format buckets),
    // which a TRANSFORM-mode site needs just as much. No delivery_mode gate here (it would strand every
    // transform-mode zone un-provisioned). Cost stays bounded — the 6-month synced_at check + the backoff
    // transient below throttle re-fires to once-per-zone-then-twice-a-year.

    // Re-fire only when the last successful sync is older than the 6-month window (= TTL/2 of the 1-year
    // HMAC, renewing well before expiry). A NEVER-synced zone ($last == 0) is NOT skipped → it
    // self-provisions on the first admin visit.
    // FORCE-PROVISION: activation/update set wpc_v2_force_provision so the very next admin pageview
    // re-provisions immediately, bypassing the 6-month throttle below. The deferred sync clears the flag
    // on a 2xx; until then it re-fires each admin load (bounded by the backoff just under here) — so a
    // blocked loopback + a dead cron can't strand an update's re-provision.
    $force = (bool) get_option('wpc_v2_force_provision', false);

    $ttl  = defined('DAY_IN_SECONDS') ? 180 * DAY_IN_SECONDS : 15552000;
    $last = (int) get_option('wpc_v2_config_synced_at', 0);
    // Witness self-heal (unprovisioned / clone) is handled by the browser-driven WP-Heartbeat path
    // (wpc_v2_provision_ensure_bg), NOT here — keeping it off the render path avoids an unbounded
    // per-pageview loopback for a never-confirming zone. This heartbeat keeps its force/6-month-refresh role.
    if (!$force && $last > 0 && (time() - $last) < $ttl) return;

    // Throttle re-fires so a failing orch isn't hit every admin pageview (synced_at only advances on
    // success). MODE-SPLIT: a PENDING provision (force) must retry briskly until it lands, so it uses a
    // SHORT backoff (2min); the routine 6-month REFRESH uses the long 1h backoff. SEPARATE keys so a
    // stale long backoff can't block a pending provision, and a force retry can't extend the refresh cooldown.
    if ($force) {
        if (function_exists('get_transient') && get_transient('wpc_v2_config_force_backoff')) return;
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_config_force_backoff', 1, defined('MINUTE_IN_SECONDS') ? 2 * MINUTE_IN_SECONDS : 120);
        }
    } else {
        if (function_exists('get_transient') && get_transient('wpc_v2_config_heartbeat_backoff')) return;
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_config_heartbeat_backoff', 1, defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);
        }
    }

    // Force a re-POST (bypasses maybe_sync's unchanged-sig short-circuit). On 2xx it re-stamps
    // wpc_v2_config_synced_at, resetting the clock; orch re-provisions + re-signs the header.
    // NON-BLOCKING on the render path: a synchronous blocking POST here exhausted the FPM pool → 503 and
    // is banned. The reliable delivery for a WAF/cron-blocked host is the browser-driven WP Heartbeat
    // (wpc_v2_provision_ensure_bg), off the render path; here we only kick the non-blocking loopback + cron.
    wpc_v2_schedule_config_sync();
}, 22);

// CF-CNAME RE-VERIFY heartbeat. Auto-promotes a staged-unverified CF cname once the edge goes live, so
// the cdn-rewrite emit-gate switches to it WITHOUT a manual re-save — this is what makes the verified-gate
// work universally (not just the save-handler path). Bounded: 1 probe, 3s, throttled 2 min, and runs ONLY
// while there's an unverified pending cname (once verified it never runs again). Admin pageviews only.
add_action('admin_init', function () {
    // CF-clone self-heal: a staging clone copies wpc_cf_cname_verified=1, so this heartbeat would
    // early-return and the clone's NEW host would never re-register its cname. If the host changed since
    // we last provisioned, drop the verified flag so the cname is re-verified + re-synced for THIS host.
    if (function_exists('wpc_v2_provision_host_changed') && wpc_v2_provision_host_changed()
        && function_exists('delete_option')) {
        delete_option('wpc_cf_cname_verified');
    }
    if (get_option('wpc_cf_cname_verified')) return; // already promoted — nothing to do
    $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
    if ($cfc === '') return;
    $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
    if (!is_array($cf) || empty($cf['settings']['cdn']) || empty($cf['token'])) return; // only a CF-delivered zone
    if (function_exists('get_transient') && get_transient('wpc_cf_reverify_bk')) return;
    if (function_exists('set_transient')) {
        set_transient('wpc_cf_reverify_bk', 1, 120);
    }
    if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
        @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
    }
    if (!class_exists('WPC_CloudflareAPI')) return;
    $wpc_cf_api = new WPC_CloudflareAPI($cf['token']);
    if ($wpc_cf_api && $wpc_cf_api->verifyCfCnameLive($cfc, 1, 3)) {
        update_option('wpc_cf_cname_verified', 1, false); // PROMOTE — emit-gate now serves the CF cname
        update_option('wpc_v2_force_provision', 1, false);
        // Stamp the host baseline so a later clone/migration is detectable (CF-clone self-heal above).
        if (function_exists('site_url')) update_option('wpc_v2_provisioned_site_url', (string) site_url(), false);
        if (function_exists('wpc_v2_schedule_config_sync')) {
            wpc_v2_schedule_config_sync();
        }
        // Purge on CF-cname PROMOTE: the emitted markup flips origin→cname, so the cached HTML must be
        // invalidated or it keeps serving origin URLs.
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            do_action('wps_ic_purge_all_cache');
        }
    }
}, 23);

// RELIABLE post-provisioning delivery re-check (Bunny zones). The orch-polled healthcheck re-verify can be
// slow/absent on a brand-new zone, so a Bunny site provisions then sits on "Universal" (picture) until a
// MANUAL "Re-check now" even though it now negotiates fine. Drive the re-check from admin pageviews too (the
// most reliable carrier — the operator is on the settings page): while a provisioned, cdn-on Bunny zone is
// still below cdn-edge, fire ONE bounded re-verify per 60s AFTER the response (fastcgi_finish_request → no
// admin-page hang) until it promotes; the cdn-edge gates below then stop it. SAFE: it only re-runs the
// EXISTING promote-on-proof verify (no orch-trust shortcut); a zone that doesn't pass stays on Universal/picture.
add_action('admin_init', function () {
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!function_exists('get_option') || !class_exists('WPC_Delivery_Resolver')) return;
    if (get_option('wpc_v2_force_provision', false)) return;                          // still provisioning
    if (!function_exists('wpc_v2_get_zone_id') || !wpc_v2_get_zone_id()) return;      // not provisioned
    if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::zone_is_cf()) return;   // CF promotes via cname path
    if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) return; // already on edge
    if (function_exists('get_transient') && get_transient('wpc_v2_admin_reverify_bk')) return;   // 60s throttle
    if (method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {                  // cached read — already edge?
        $rv = WPC_Delivery_Resolver::resolve_verbose();
        if (isset($rv['tier_name']) && $rv['tier_name'] === 'cdn-edge') return;
    }
    if (function_exists('set_transient')) set_transient('wpc_v2_admin_reverify_bk', 1, 60);
    add_action('shutdown', function () {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); } // non-blocking: response already sent
        if (class_exists('WPC_Delivery_Resolver')) { WPC_Delivery_Resolver::resolve(true); } // promotes if the zone now passes
    });
}, 24);

// Keep the manifest-pull plumbing coupled to the strategy (the removed Lazy Backfill toggle used to do
// this). The page-load drain trigger AND wpc_v2_pull_drain_fire both bail unless wpc_v2_pull_enabled is
// true, so without this a fresh site that selects lazy_cdn would have dormant pull plumbing and never land
// a variant. Only turns pull ON (never off — push+pull dedupe + bulk fallback can depend on it). Idempotent.
add_action('admin_init', function () {
    if (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()
        && !get_site_option('wpc_v2_pull_enabled', false)) {
        update_site_option('wpc_v2_pull_enabled', 1);
        error_log('[WPC ConfigSync] enabled wpc_v2_pull_enabled (lazy_cdn strategy active)');
    }
}, 23);

// ONE-TIME UPGRADE MIGRATION (flag → strategy). Older installs turned lazy_cdn on via a notice/toggle that
// set the per-zone flag wpc_v2_lazy_enabled_<zone>='1' but NEVER wrote wpc_optimization_mode; now that
// wpc_v2_get_lazy_enabled() derives from the strategy, those sites would SILENTLY lose lazy delivery on
// upgrade. If the old flag is on but the strategy isn't lazy_cdn yet, adopt lazy_cdn so the site keeps its
// behavior (setting the option fires maybe_sync → re-syncs orch + flips pull). Runs at priority 21 — AFTER
// the zone resolver (20) so the zone exists, BEFORE the heartbeat (22). Does NOT stamp "done" until the
// zone is known, so a not-yet-provisioned site retries instead of migrating blind. One write, then never again.
add_action('admin_init', function () {
    if (get_option('wpc_v2_lazy_strategy_migrated', false)) return;
    if (!function_exists('wpc_v2_get_zone_id') || !function_exists('wpc_get_optimization_mode')
        || !defined('WPS_IC_SETTINGS')) {
        return;
    }
    $zone = (string) wpc_v2_get_zone_id();
    if ($zone === '') {
        return; // zone not resolved yet — retry next admin load, don't stamp done
    }
    $flag_on = (get_option('wpc_v2_lazy_enabled_' . sanitize_key($zone), '0') === '1');
    if ($flag_on && wpc_get_optimization_mode() !== 'lazy_cdn') {
        $settings = get_option(WPS_IC_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['wpc_optimization_mode'] = 'lazy_cdn';
        update_option(WPS_IC_SETTINGS, $settings); // fires maybe_sync → orch + pull
        error_log('[WPC ConfigSync] migrated legacy lazy flag → lazy_cdn strategy for zone ' . $zone);
    }
    update_option('wpc_v2_lazy_strategy_migrated', 1, false);
}, 21);

/* =============================================================================
 * TEMPORARY DIAGNOSTIC — render-timing (added v7.03.25; REMOVE after diagnosis).
 *
 * Pins where the post-connect dashboard render spends its ~30s on a NEW key link.
 * ENABLE: add   define('WPC_RENDER_TIMING', true);   to wp-config.php on the test
 * site, do ONE fresh key link, then read the PHP error log for "[WPC render-timing]"
 * — it lists every outbound HTTP request on that document render (duration + URL)
 * plus the total render wall time and the non-HTTP remainder. DISABLE by removing
 * the constant. Constant-gated → ZERO cost on any site without it (no DB read, no
 * hooks registered). Renders only (never ajax/cron), so the connect ajax is excluded.
 * ========================================================================== */
if (defined('WPC_RENDER_TIMING') && WPC_RENDER_TIMING
    && (!function_exists('wp_doing_ajax') || !wp_doing_ajax())
    && !(defined('DOING_CRON') && DOING_CRON)
    && function_exists('is_admin') && is_admin()
    && !defined('WPC_RTIME_BOOTED')) {

    define('WPC_RTIME_BOOTED', 1);
    $GLOBALS['wpc_rtime_t0']   = microtime(true);
    $GLOBALS['wpc_rtime_http'] = null;
    $GLOBALS['wpc_rtime_log']  = [];

    // PHP HTTP requests are synchronous + sequential (one in flight at a time), so a single start
    // stamp set in http_request_args and read in http_api_debug pairs each request to its duration.
    add_filter('http_request_args', function ($args, $url) {
        $GLOBALS['wpc_rtime_http'] = microtime(true);
        return $args;
    }, PHP_INT_MAX, 2);

    add_action('http_api_debug', function ($response, $type, $class, $args, $url) {
        if (!isset($GLOBALS['wpc_rtime_http']) || $GLOBALS['wpc_rtime_http'] === null) return;
        $ms = (int) round((microtime(true) - $GLOBALS['wpc_rtime_http']) * 1000);
        $GLOBALS['wpc_rtime_http'] = null;
        $err = (function_exists('is_wp_error') && is_wp_error($response)) ? ' [ERR]' : '';
        $GLOBALS['wpc_rtime_log'][] = sprintf('%7dms%s  %s', $ms, $err, is_string($url) ? $url : '(unknown url)');
    }, PHP_INT_MAX, 5);

    add_action('shutdown', function () {
        if (empty($GLOBALS['wpc_rtime_t0'])) return;
        $total = (int) round((microtime(true) - $GLOBALS['wpc_rtime_t0']) * 1000);
        $calls = isset($GLOBALS['wpc_rtime_log']) && is_array($GLOBALS['wpc_rtime_log']) ? $GLOBALS['wpc_rtime_log'] : [];
        $sum = 0;
        foreach ($calls as $c) { if (preg_match('/^\s*(\d+)ms/', $c, $m)) $sum += (int) $m[1]; }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $out = "[WPC render-timing] ===== " . $uri . "\n"
             . "[WPC render-timing] total render " . $total . "ms | " . count($calls)
             . " HTTP call(s) summing " . $sum . "ms | non-HTTP " . max(0, $total - $sum) . "ms";
        foreach ($calls as $c) { $out .= "\n[WPC render-timing]   " . $c; }
        error_log($out);
    }, PHP_INT_MAX);
}
