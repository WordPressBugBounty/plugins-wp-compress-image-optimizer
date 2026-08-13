<?php

if (!defined('ABSPATH')) exit;

class WPC_Delivery_Resolver
{
    const TIER_CDN_EDGE = 0;
    const TIER_HTACCESS = 1;
    const TIER_PICTURE  = 2;
    const TIER_JPEG     = 3;

    /** Cached resolution state: ['sig'=>string,'verify'=>array,'at'=>int,'fails'=>int]. */
    const STATE_OPTION = 'wpc_delivery_state';
    /** Re-verify after this many seconds even if the signature is unchanged. */
    const VERIFY_TTL = 43200; // 12h
    /**
     * Demote-hysteresis: a proven clean-URL tier must not fall back to <picture> on a single
     * failed re-verify — those are usually transient (cold POP, loopback 502, probe timeout) and
     * the visitor never sees them. Keep serving the last verified-good tier through this many
     * CONSECUTIVE failures before demoting. Promote fast, demote slow.
     */
    const VERIFY_FAIL_GRACE = 3;
    // Cold-miss (no-200 = OTF rendition not warm yet) re-verify attempts before settling to picture.
    const VERIFY_COLD_GRACE = 6;

    /** The ONE user-facing control: 'auto' (best — AVIF→WebP→JPEG) | 'webp' | 'off'. Default auto. */
    const NEXTGEN_OPTION = 'wpc_nextgen';
    /** Advanced, optional: force a mechanism — 'auto' | 'picture' | 'htaccess' | 'cdn'. */
    const OVERRIDE_OPTION = 'wpc_delivery_override';
    /**
     * Byte-source opt-in — decouples "edge negotiates" from "CDN serves bytes". When '1' AND a
     * zone/CNAME exists but live-cdn is OFF, the resolver still promotes TIER_CDN_EDGE with
     * redirect_target='origin': the edge does only the per-Accept 302, the local origin serves
     * the bytes (no CDN bandwidth). Default off → inert (edge stays gated on cdn_on).
     */
    const EDGE_ORIGIN_OPTION = 'wpc_edge_origin_bytes';

    public static function tier_name($tier)
    {
        $n = [
            self::TIER_CDN_EDGE => 'cdn-edge',
            self::TIER_HTACCESS => 'htaccess',
            self::TIER_PICTURE  => 'picture',
            self::TIER_JPEG     => 'jpeg',
        ];
        return isset($n[$tier]) ? $n[$tier] : 'unknown';
    }


    //  PURE CORE — no IO, no globals. The brain. Fully unit-testable.


    /**
     * Classify an image by its leading magic bytes. $head = raw bytes (>= 12 recommended).
     * @return 'avif'|'webp'|'jpeg'|'png'|'gif'|'unknown'
     */
    public static function classify_format($head)
    {
        if (!is_string($head) || $head === '') return 'unknown';
        $hex = strtoupper(bin2hex(substr($head, 0, 16)));
        if (strncmp($hex, 'FFD8FF', 6) === 0) return 'jpeg';
        if (strncmp($hex, '89504E47', 8) === 0) return 'png';
        if (strncmp($hex, '47494638', 8) === 0) return 'gif';                       // GIF8
        if (strncmp($hex, '52494646', 8) === 0 && strpos($hex, '57454250') !== false) return 'webp'; // RIFF…WEBP
        // ISO-BMFF: bytes 4..7 = 'ftyp', then a brand. AVIF brands: avif / avis / mif1 / msf1.
        if (strpos($hex, '66747970') !== false) {
            foreach (['61766966', '61766973', '6D696631', '6D736631'] as $brand) {
                if (strpos($hex, $brand) !== false) return 'avif';
            }
        }
        return 'unknown';
    }


    private static function verify_soft_degrade_ok($caps, $verify)
    {
        if (!self::edge_usable(is_array($caps) ? $caps : array())) return false;
        if (!is_array($caps) || (isset($caps['orch_native_accept_vary']) ? $caps['orch_native_accept_vary'] : null) !== true) return false;
        $v = (is_array($verify) && isset($verify['cdn'])) ? $verify['cdn'] : null;
        if (!is_array($v) || !empty($v['ok'])) return false;
        $d = isset($v['detail']) ? (string) $v['detail'] : '';
        if ($d === '') return false;
        if (strpos($d, 'no-200') !== false || strpos($d, 'loop') !== false
            || strpos($d, 'error') !== false || strpos($d, 'pending') !== false) return false;


        return (strpos($d, 'got-png') !== false) || (strpos($d, 'got-jpeg') !== false) || (strpos($d, 'got-jpg') !== false);
    }

    public static function evaluate_cdn_probes($probes)
    {
        $out = ['ok' => false, 'classes' => [], 'vary' => false, 'detail' => '', 'pending_orch' => false];
        $want = ['avif' => ['avif', 'webp', 'jpeg'], 'webp' => ['webp', 'jpeg'], 'legacy' => ['jpeg', 'png']];
        if (!is_array($probes)) { $out['detail'] = 'no-probes'; return $out; }
        // Note the interim-AVIF signal (additive — does NOT change pass/fail). Lets the UI
        // distinguish "edge is generating AVIF in the background" from a genuine miss.
        foreach (['avif', 'webp', 'legacy'] as $pc) {
            if (isset($probes[$pc]['avif_source']) && strpos((string) $probes[$pc]['avif_source'], 'pending') !== false) {
                $out['pending_orch'] = true; break;
            }
        }


        $mode_b = false;
        foreach (['avif', 'webp', 'legacy'] as $pc) {
            if (!empty($probes[$pc]['natural_mode'])) { $mode_b = true; break; }
        }


        if (!$mode_b) {
            $is_neg_redirect = function ($p) {
                $c = is_array($p) ? (int) (isset($p['code']) ? $p['code'] : 0) : 0;
                return ($c >= 300 && $c < 400) && !empty($p['location']);
            };
            if ($is_neg_redirect(isset($probes['avif']) ? $probes['avif'] : null)
                && $is_neg_redirect(isset($probes['webp']) ? $probes['webp'] : null)) {
                $mode_b = true;
            }
        }
        if ($mode_b) {


            $want_b = ['avif' => ['avif', 'webp'], 'webp' => ['webp'], 'legacy' => ['jpeg', 'png']];
            foreach (['avif', 'webp', 'legacy'] as $cls) {
                $p = isset($probes[$cls]) ? $probes[$cls] : null;
                $code = is_array($p) ? (int) (isset($p['code']) ? $p['code'] : 0) : 0;
                // Accept a direct 200 (e.g. legacy .jpg served inline) OR a 3xx negotiate redirect.
                $ok_code = ($code === 200) || ($code >= 300 && $code < 400);
                if (!is_array($p) || !$ok_code) {
                    $out['classes'][$cls] = 'no-200-no-redirect';
                    $out['detail'] = $cls . ':no-200-no-redirect (302-negotiate)';
                    return $out;
                }
                // Reject a same-URL redirect (Location == request URL, sans query/fragment):
                // an edge that 302s a URL to itself loops the browser.
                if ($code >= 300 && $code < 400) {
                    $loc = isset($p['location']) ? preg_replace('/[?#].*$/', '', (string) $p['location']) : '';
                    $req = isset($p['url']) ? preg_replace('/[?#].*$/', '', (string) $p['url']) : '';
                    if ($loc !== '' && $req !== '' && $loc === $req) {
                        $out['detail'] = $cls . ':same-url-302-loop';
                        return $out;
                    }
                }
                $fmt = isset($p['fmt']) ? $p['fmt'] : 'unknown';
                $out['classes'][$cls] = $fmt;


                $allow = $want_b[$cls];
                if ($cls === 'webp' && isset($out['classes']['avif']) && $out['classes']['avif'] === 'avif') {
                    $allow = ['webp', 'jpeg'];
                }
                if (!in_array($fmt, $allow, true)) {
                    $out['detail'] = $cls . ':got-' . $fmt . ' (302-negotiate)';
                    return $out;
                }
            }
            $rank_b = ['avif' => 3, 'webp' => 2, 'jpeg' => 1, 'png' => 1, 'unknown' => 0];
            if ((isset($rank_b[$out['classes']['avif']]) ? $rank_b[$out['classes']['avif']] : 0)
                < (isset($rank_b[$out['classes']['webp']]) ? $rank_b[$out['classes']['webp']] : 0)) {
                $out['detail'] = 'avif-class-worse-than-webp (302-negotiate)';
                return $out;
            }
            $out['vary'] = true;
            $out['mode_b'] = true;
            $out['ok'] = true;
            $out['detail'] = 'ok-302-negotiate';
            return $out;
        }

        $vary_seen = false;
        foreach (['avif', 'webp', 'legacy'] as $cls) {
            $p = isset($probes[$cls]) ? $probes[$cls] : null;
            if (!is_array($p) || (int) ($p['code'] ?? 0) !== 200) {
                $out['classes'][$cls] = 'no-200';
                $out['detail'] = $cls . ':no-200';
                return $out;
            }
            $fmt = isset($p['fmt']) ? $p['fmt'] : 'unknown';
            $out['classes'][$cls] = $fmt;
            if (!in_array($fmt, $want[$cls], true)) {
                $out['detail'] = $cls . ':got-' . $fmt;
                return $out;
            }
            if (!empty($p['vary'])) $vary_seen = true;
        }
        // Must improve with capability: avif class should not serve a WORSE format than webp class.
        $rank = ['avif' => 3, 'webp' => 2, 'jpeg' => 1, 'png' => 1, 'unknown' => 0];
        if (($rank[$out['classes']['avif']] ?? 0) < ($rank[$out['classes']['webp']] ?? 0)) {
            $out['detail'] = 'avif-class-worse-than-webp';
            return $out;
        }


        if (!$vary_seen) {
            $distinct_fmts = count(array_unique(array_values($out['classes'])));


            $cfc_w    = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $cf_set_w = (defined('WPS_IC_CF') && function_exists('get_option')) ? get_option(WPS_IC_CF) : false;
            $is_cf    = ($cfc_w !== '' && is_array($cf_set_w) && !empty($cf_set_w['settings']['cdn']));
            if ($distinct_fmts > 1 && !$is_cf) {
                $vary_seen = true;
                $out['vary_via'] = 'content-type'; // Bunny strips Vary:Accept; per-Accept Content-Type is the witness
            }
        }
        $out['vary'] = $vary_seen;
        if (!$vary_seen) { $out['detail'] = 'no-vary'; return $out; }
        $out['ok'] = true;
        $out['detail'] = isset($out['vary_via']) ? 'ok-content-type-vary' : 'ok';
        return $out;
    }


    public static function evaluate_htaccess_probes($webp_probe, $jpeg_probe)
    {
        $out = ['ok' => false, 'served_webp' => null, 'served_legacy' => null, 'vary' => false, 'detail' => ''];
        foreach ([['webp_probe', $webp_probe], ['jpeg_probe', $jpeg_probe]] as $pair) {
            if (!is_array($pair[1]) || (int) ($pair[1]['code'] ?? 0) !== 200) {
                $out['detail'] = $pair[0] . ':no-200';
                return $out;
            }
        }
        $out['served_webp']   = isset($webp_probe['fmt']) ? $webp_probe['fmt'] : 'unknown';
        $out['served_legacy'] = isset($jpeg_probe['fmt']) ? $jpeg_probe['fmt'] : 'unknown';
        $out['vary'] = !empty($webp_probe['vary']);


        if (!empty($webp_probe['cf_polish'])) {
            $out['detail'] = 'cf-polish-not-htaccess';
            return $out;
        }
        if ($out['served_webp'] !== 'webp' && $out['served_webp'] !== 'avif') {
            $out['detail'] = 'webp-accept-got-' . $out['served_webp'];
            return $out;
        }
        if ($out['served_legacy'] !== 'jpeg' && $out['served_legacy'] !== 'png') {
            $out['detail'] = 'legacy-accept-got-' . $out['served_legacy'];
            return $out;
        }
        if (!$out['vary']) { $out['detail'] = 'no-vary'; return $out; }
        $out['ok'] = true;
        $out['detail'] = 'ok';
        return $out;
    }


    public static function resolve_tier_from($caps, $verify, $override = 'auto')
    {
        $caps_merged = array_merge([
            'cdn_on' => false, 'is_apache' => false, 'htaccess_writable' => false,
            'has_variants' => false, 'picture_allowed' => true, 'generate_webp' => true,
            'orch_native_accept_vary' => null,
            'cdn_disabled' => false,
            'auto_disabled' => false,
        ], is_array($caps) ? $caps : []);
        $verify_merged = array_merge(['cdn' => null, 'htaccess' => null], is_array($verify) ? $verify : []);
        $warnings = [];


        if (!empty($caps_merged['cdn_disabled'])) {
            if (!empty($caps_merged['has_variants']) && !empty($caps_merged['picture_allowed'])) {
                return self::result(self::TIER_PICTURE, 'cdn_disabled (orch master kill)', $warnings);
            }
            return self::result(self::TIER_JPEG, 'cdn_disabled (orch master kill)', $warnings);
        }


        if (!empty($caps_merged['auto_disabled'])) {
            if (!empty($caps_merged['has_variants']) && !empty($caps_merged['picture_allowed'])) {
                return self::result(self::TIER_PICTURE, 'auto_disabled (cdn liveness)', $warnings);
            }
            return self::result(self::TIER_JPEG, 'auto_disabled (cdn liveness)', $warnings);
        }


        if (isset($caps_merged['ceiling']) && $caps_merged['ceiling'] === 'off') {
            return self::result(self::TIER_JPEG, 'next-gen off (ceiling)', $warnings);
        }


        if ($override === 'picture') {
            if ($caps_merged['has_variants']) return self::result(self::TIER_PICTURE, 'forced: picture', $warnings);
            $warnings[] = 'override_picture_unavailable';
        } elseif ($override === 'htaccess') {
            if ($caps_merged['is_apache'] && $caps_merged['htaccess_writable'] && is_array($verify_merged['htaccess']) && !empty($verify_merged['htaccess']['ok'])) {
                return self::result(self::TIER_HTACCESS, 'forced: htaccess (verified)', $warnings);
            }
            $warnings[] = 'override_htaccess_unavailable';
        } elseif ($override === 'cdn') {
            if (self::edge_usable($caps_merged) && is_array($verify_merged['cdn']) && !empty($verify_merged['cdn']['ok'])
                && self::redirect_target_ready(self::edge_redirect_target($caps_merged), $verify_merged['cdn'])) {
                return self::result(self::TIER_CDN_EDGE, 'forced: cdn (verified)', $warnings, self::edge_redirect_target($caps_merged));
            }


            if (self::verify_soft_degrade_ok($caps_merged, $verify_merged)) {
                $warnings[] = 'forced_cdn_orch_witness_soft_degrade';
                return self::result(self::TIER_CDN_EDGE, 'forced: cdn (orch-witness, canary soft-degrade)', $warnings, self::edge_redirect_target($caps_merged));
            }
            $warnings[] = 'override_cdn_unavailable';
        } elseif ($override === 'edge') {


            if (self::edge_usable($caps_merged) && is_array($verify_merged['cdn']) && !empty($verify_merged['cdn']['ok'])
                && self::redirect_target_ready(self::edge_redirect_target($caps_merged), $verify_merged['cdn'])) {
                return self::result(self::TIER_CDN_EDGE, 'forced: edge (verified)', $warnings, self::edge_redirect_target($caps_merged));
            }


            if (self::verify_soft_degrade_ok($caps_merged, $verify_merged)) {
                $warnings[] = 'forced_edge_orch_witness_soft_degrade';
                return self::result(self::TIER_CDN_EDGE, 'forced: edge (orch-witness, canary soft-degrade)', $warnings, self::edge_redirect_target($caps_merged));
            }
            $warnings[] = 'override_edge_unavailable';
        }


        if (self::edge_usable($caps_merged)) {


            if ($caps_merged['orch_native_accept_vary'] === true && is_array($verify_merged['cdn'])) {
                if (!empty($verify_merged['cdn']['mode_b'])) {
                    $warnings[] = 'orch_nav_disagreement:edge-302-but-orch-direct';
                } elseif (empty($verify_merged['cdn']['ok'])) {
                    $warnings[] = 'orch_nav_disagreement:probe-failed-but-orch-direct';
                }
            }
            $rt = self::edge_redirect_target($caps_merged);
            if (is_array($verify_merged['cdn']) && !empty($verify_merged['cdn']['ok']) && self::redirect_target_ready($rt, $verify_merged['cdn'])) {
                return self::result(self::TIER_CDN_EDGE, 'cdn-edge verified', $warnings, $rt);
            }
            // Verified edge, but redirect_target='origin' needs a Mode-B edge this probe didn't
            // prove → can't origin-redirect yet; fall through (safe).
            if ($rt === 'origin' && is_array($verify_merged['cdn']) && !empty($verify_merged['cdn']['ok']) && empty($verify_merged['cdn']['mode_b'])) {
                $warnings[] = 'edge_origin_needs_mode_b';
            } elseif (is_array($verify_merged['cdn'])) {
                $warnings[] = 'cdn_verify_failed:' . (isset($verify_merged['cdn']['detail']) ? $verify_merged['cdn']['detail'] : '?');
            } else {
                $warnings[] = 'cdn_pending_verify';
            }
            if (is_array($verify_merged['cdn']) && !empty($verify_merged['cdn']['pending_orch'])) $warnings[] = 'cdn_pending_orch';
        }

        // TIER 1 — origin .htaccess negotiation. Same promote-on-proof rule.
        if ($caps_merged['is_apache'] && $caps_merged['htaccess_writable']) {
            if (is_array($verify_merged['htaccess']) && !empty($verify_merged['htaccess']['ok'])) {
                return self::result(self::TIER_HTACCESS, 'htaccess negotiation verified', $warnings);
            }
            if (is_array($verify_merged['htaccess'])) $warnings[] = 'htaccess_verify_failed:' . (isset($verify_merged['htaccess']['detail']) ? $verify_merged['htaccess']['detail'] : '?');
            else $warnings[] = 'htaccess_pending_verify';
        }

        // TIER 2 — <picture>. Universal, needs no verification. The next-gen floor.
        if ($caps_merged['has_variants'] && $caps_merged['picture_allowed']) {
            return self::result(self::TIER_PICTURE, 'picture (universal, browser-picks)', $warnings);
        }

        // TIER 3 — optimized jpg/png. Guard against SILENT next-gen loss.
        if ($caps_merged['has_variants'] && !$caps_merged['picture_allowed']) {
            // Variants exist but the only universal path (picture) is disabled and no verified
            // clean-URL path is available → visitors get jpg-only. Never silent — warn loudly.
            $warnings[] = 'next_gen_disabled_jpeg_only';
        } elseif (!$caps_merged['generate_webp']) {
            $warnings[] = 'next_gen_not_generated';
        } elseif (!$caps_merged['has_variants']) {
            $warnings[] = 'no_variants_on_disk';
        }
        return self::result(self::TIER_JPEG, 'jpeg floor', $warnings);
    }

    private static function result($tier, $reason, $warnings, $redirect_target = 'samehost')
    {


        return ['tier' => $tier, 'tier_name' => self::tier_name($tier), 'reason' => $reason, 'warnings' => $warnings, 'redirect_target' => $redirect_target];
    }

    /**
     * Is the CDN edge usable for negotiation? True when byte-serving CDN is on, OR a zone exists
     * and the edge-origin opt-in is set (the "negotiate at the edge, serve bytes from origin"
     * decouple). Inert by default (opt-in off → same as the cdn_on-only gate).
     */
    private static function edge_usable($caps)
    {
        if (!empty($caps['cdn_on'])) return true;
        return !empty($caps['edge_available']) && !empty($caps['edge_origin_opt_in']);
    }


    /** Where the edge tier serves bytes: 'samehost' (CDN/CF serves) or 'origin' (origin serves). */
    private static function edge_redirect_target($caps)
    {
        return !empty($caps['cdn_on']) ? 'samehost' : 'origin';
    }


    private static function redirect_target_ready($redirect_target, $verify_cdn)
    {
        if ($redirect_target !== 'origin') return true;
        return is_array($verify_cdn) && !empty($verify_cdn['mode_b']);
    }


    public static function ceiling_from_settings($settings)
    {
        $s = is_array($settings) ? $settings : [];
        $gw = !empty($s['generate_webp']) && (string) $s['generate_webp'] === '1';
        if (!$gw) return 'off';
        $av = !empty($s['picture_avif']) && (string) $s['picture_avif'] === '1';
        return $av ? 'avif' : 'webp';
    }

    /**
     * The legacy keys to WRITE for a chosen ceiling, so every existing reader stays correct.
     * 'picture_webp' stays on whenever next-gen is on (the universal fallback the resolver leans
     * on). 'modern_image_delivery' is intentionally NOT set here — it's mechanism-owned by the
     * resolver, so we leave its stored value untouched for its existing readers.
     */
    public static function settings_for_ceiling($ceiling)
    {
        switch ($ceiling) {
            case 'avif':
                return ['generate_webp' => '1', 'picture_webp' => '1', 'picture_avif' => '1'];
            case 'webp':
                return ['generate_webp' => '1', 'picture_webp' => '1', 'picture_avif' => '0'];
            case 'off':
            default:
                return ['generate_webp' => '0', 'picture_avif' => '0'];
        }
    }


    public static function effective_ceiling($settings)
    {
        $s = is_array($settings) ? $settings : [];
        if (isset($s[self::NEXTGEN_OPTION]) && $s[self::NEXTGEN_OPTION] !== '') {
            $m = strtolower((string) $s[self::NEXTGEN_OPTION]);
            if ($m === 'auto') return 'avif';
            if ($m === 'webp') return 'webp';   // DELIBERATE webp-only pin — honored forever
            if ($m === 'off')  return 'off';
        }
        // Unset: next-gen ON ⇒ 'avif', OFF ⇒ 'off' (derived from generate_webp). NOT
        // ceiling_from_settings() — see the docblock (that was the de-sync root cause).
        $gw = !empty($s['generate_webp']) && (string) $s['generate_webp'] === '1';
        return $gw ? 'avif' : 'off';
    }

    /** The advanced mechanism override; 'auto' (resolver decides) unless explicitly forced. */
    public static function override_mechanism($settings)
    {
        $s = is_array($settings) ? $settings : [];
        $o = isset($s[self::OVERRIDE_OPTION]) ? strtolower((string) $s[self::OVERRIDE_OPTION]) : 'auto';
        return in_array($o, ['picture', 'htaccess', 'cdn', 'edge'], true) ? $o : 'auto';
    }

    /** Stable signature of the inputs that affect tiering → re-verify when it changes. */
    public static function env_signature($caps)
    {
        $c = is_array($caps) ? $caps : [];


        $parts = [
            'cdn'  => !empty($c['cdn_on']) ? 1 : 0,
            'gw'   => !empty($c['generate_webp']) ? 1 : 0,
            'pic'  => !empty($c['picture_allowed']) ? 1 : 0,
            'host' => isset($c['host']) ? (string) $c['host'] : '',
        ];


        if (!empty($c['edge_origin_opt_in'])) {
            $parts['edge'] = (!empty($c['edge_available']) ? '1' : '0') . '1';
        }
        return substr(md5(wp_json_encode($parts)), 0, 16);
    }


    //  IO LAYER — capability detection + live loopback probes. Thin; smoke-tested.


    /** Gather environment capabilities (no network). */
    public static function gather_capabilities()
    {
        self::capture_server_software();
        $settings = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        if (!is_array($settings)) $settings = [];
        $host = function_exists('parse_url') ? (string) parse_url(self::site_url(), PHP_URL_HOST) : '';
        // ONE control drives format: ceiling = off | webp | avif. Mechanism is resolver-owned.
        $ceiling = self::effective_ceiling($settings);
        $nextgen_on = ($ceiling !== 'off');
        return [
            'cdn_on'            => self::is_cdn_on($settings),
            'edge_available'    => self::is_edge_available(),
            'edge_origin_opt_in'=> (!empty($settings[self::EDGE_ORIGIN_OPTION]) && (string) $settings[self::EDGE_ORIGIN_OPTION] === '1'),
            'orch_native_accept_vary' => self::orch_nav_signal(),
            'cdn_disabled'      => (function_exists('wpc_v2_zone_cdn_disabled') && wpc_v2_zone_cdn_disabled()),
            'auto_disabled'     => (function_exists('wpc_v2_zone_auto_disabled') && wpc_v2_zone_auto_disabled()),
            'is_apache'         => self::is_apache(),
            'htaccess_writable' => self::htaccess_writable(),
            'has_variants'      => $nextgen_on,


            'picture_allowed'   => apply_filters(
                'wpc_picture_allowed',
                $nextgen_on && (string) get_option('wpc_picture_compat', '1') !== '0'
            ),
            'generate_webp'     => $nextgen_on,
            'ceiling'           => $ceiling,
            'avif_enabled'      => ($ceiling === 'avif'),
            'host'              => $host,
        ];
    }

    private static function site_url()
    {
        return function_exists('site_url') ? site_url() : '';
    }

    private static function is_cdn_on($settings)
    {
        if (!empty($settings['live-cdn']) && $settings['live-cdn'] == '1') return true;
        return false;
    }

    /**
     * Is a CDN edge available for NEGOTIATION (independent of byte-serving)? True when a Bunny
     * zone name or custom CNAME is configured — what makes "edge negotiates, origin serves bytes"
     * possible with live-cdn off.
     */
    private static function is_edge_available()
    {
        if (!function_exists('get_option')) return false;
        return !empty(get_option('ic_cdn_zone_name')) || !empty(get_option('ic_custom_cname'));
    }


    public static function orch_nav_signal()
    {
        if (!function_exists('get_option') || !function_exists('wpc_v2_get_zone_id')) return null;
        $sk = function_exists('sanitize_key');


        $zone = (string) wpc_v2_get_zone_id();
        if ($zone !== '') {
            $v = get_option('wpc_v2_orch_nav_' . ($sk ? sanitize_key($zone) : $zone), null);
            if ($v !== null && $v !== false && $v !== '') {
                return ($v === '1' || $v === 1 || $v === true);
            }
        }


        if (function_exists('wpc_v2_orch_witness_cname_keys')) {
            foreach (wpc_v2_orch_witness_cname_keys() as $cn) {
                $v = get_option('wpc_v2_orch_nav_' . ($sk ? sanitize_key($cn) : $cn), null);
                if ($v !== null && $v !== false && $v !== '') {
                    return ($v === '1' || $v === 1 || $v === true);
                }
            }
        }
        return null;
    }

    private static function is_apache()
    {
        $sw = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';


        if ($sw === '' && function_exists('get_option')) {
            $sw = strtolower((string) get_option('wpc_server_software', ''));
        }
        // LiteSpeed honors .htaccess/mod_rewrite, so it counts as "apache-like" here.
        if (strpos($sw, 'apache') !== false || strpos($sw, 'litespeed') !== false || strpos($sw, 'lsws') !== false) {
            return true;
        }
        // Some hosts hide SERVER_SOFTWARE; presence of apache_get_modules is a positive signal.
        if (function_exists('apache_get_modules')) return true;
        return false;
    }

    /** Persist SERVER_SOFTWARE from a real web request so CLI/cron can detect the server. */
    private static function capture_server_software()
    {
        if (empty($_SERVER['SERVER_SOFTWARE'])) return;                 // CLI/cron → nothing to capture
        if (defined('WP_CLI') && WP_CLI) return;
        if (!function_exists('get_option') || !function_exists('update_option')) return;
        $sw = (string) $_SERVER['SERVER_SOFTWARE'];
        if (get_option('wpc_server_software', '') !== $sw) update_option('wpc_server_software', $sw, false);
    }

    private static function htaccess_writable()
    {
        $root = defined('ABSPATH') ? ABSPATH : '';
        if ($root === '') return false;
        $path = rtrim($root, '/\\') . '/.htaccess';
        if (file_exists($path)) return is_writable($path);
        return is_writable(rtrim($root, '/\\'));
    }

    /**
     * Probe one URL with a given Accept header. Returns a normalized result the PURE
     * evaluators understand. Uses wp_remote_get (honors WP HTTP stack / proxies).
     */
    public static function probe($url, $accept)
    {
        $res = ['code' => 0, 'ctype' => '', 'vary' => false, 'fmt' => 'unknown', 'error' => '', 'avif_source' => '', 'location' => '', 'natural_mode' => false, 'url' => (string) $url];
        if (!function_exists('wp_remote_get')) { $res['error'] = 'no-http'; return $res; }
        $resp = wp_remote_get($url, [
            'timeout'     => 5,
            'redirection' => 0,
            'sslverify'   => false,
            'headers'     => ['Accept' => $accept],
        ]);
        if (function_exists('is_wp_error') && is_wp_error($resp)) {
            $res['error'] = $resp->get_error_message();
            return $res;
        }
        $res['code']  = (int) wp_remote_retrieve_response_code($resp);
        $res['ctype'] = strtolower((string) wp_remote_retrieve_header($resp, 'content-type'));
        $vary         = strtolower((string) wp_remote_retrieve_header($resp, 'vary'));
        $xvary        = strtolower((string) wp_remote_retrieve_header($resp, 'x-vary-mode'));
        $res['vary']  = (strpos($vary, 'accept') !== false) || ($xvary !== '');


        $res['avif_source'] = strtolower((string) wp_remote_retrieve_header($resp, 'x-avif-source'));


        $res['cf_polish'] = (string) wp_remote_retrieve_header($resp, 'cf-polished');
        $body         = (string) wp_remote_retrieve_body($resp);
        $res['fmt']   = self::classify_format($body);


        if ($res['code'] >= 300 && $res['code'] < 400) {
            $res['location']     = (string) wp_remote_retrieve_header($resp, 'location');
            $reason              = strtolower((string) wp_remote_retrieve_header($resp, 'x-redirect-reason'));
            $res['natural_mode'] = ((string) wp_remote_retrieve_header($resp, 'x-natural-mode') !== '')
                                   || (strpos($reason, 'redirect_to_local_') !== false);
            if ($res['location'] !== '' && $res['fmt'] === 'unknown') {
                $res['fmt'] = self::classify_format_by_ext($res['location']);
            }
        }
        return $res;
    }

    /**
     * Classify image format by URL extension (for 302-negotiate redirect targets,
     * where there's no body to sniff). Strips any query string first.
     */
    private static function classify_format_by_ext($url)
    {
        $u = strtolower((string) $url);
        $u = preg_replace('/[?#].*$/', '', $u);
        if (substr($u, -5) === '.avif') return 'avif';
        if (substr($u, -5) === '.webp') return 'webp';
        if (substr($u, -4) === '.jpg' || substr($u, -5) === '.jpeg') return 'jpeg';
        if (substr($u, -4) === '.png') return 'png';
        return 'unknown';
    }

    /** Run the live verifications for whichever optimistic tiers are applicable. */
    public static function run_verifications($caps)
    {
        $verify = ['cdn' => null, 'htaccess' => null];
        $test = self::pick_test_image();
        if (!$test) return $verify;

        if (self::edge_usable(is_array($caps) ? $caps : []) && !empty($test['cdn_webp_url'])) {


            $probe_url = $test['cdn_webp_url'];


            if (self::edge_redirect_target(is_array($caps) ? $caps : []) === 'origin') {
                // Mode-B (edge-origin): constant tokens make the edge 302-negotiate per-request (stable key).
                $probe_url .= (strpos($probe_url, '?') === false ? '?' : '&') . '_wpc_m=r&_redirect_target=origin';
            }
            $verify['cdn'] = self::evaluate_cdn_probes([
                'avif'   => self::probe($probe_url, 'image/avif,image/webp,*/*'),
                'webp'   => self::probe($probe_url, 'image/webp,*/*'),
                'legacy' => self::probe($probe_url, 'image/*,*/*'),
            ]);


            if (empty($test['local_variant']) && is_array($verify['cdn']) && !empty($verify['cdn']['ok'])) {
                $wf = isset($verify['cdn']['classes']['webp']) ? $verify['cdn']['classes']['webp'] : '';
                if ($wf !== 'webp' && $wf !== 'avif') {
                    $verify['cdn'] = null;
                }
            }
        }
        if (!empty($caps['is_apache']) && !empty($caps['htaccess_writable']) && !empty($test['origin_jpg_url'])) {
            $verify['htaccess'] = self::evaluate_htaccess_probes(
                self::probe($test['origin_jpg_url'], 'image/webp,*/*'),
                self::probe($test['origin_jpg_url'], 'image/jpeg')
            );
        }
        return $verify;
    }

    /**
     * Find one real attachment that has the sibling files we need to probe against.
     * Returns ['origin_jpg_url'=>…, 'cdn_webp_url'=>…] or null. Best-effort, cached per request.
     */
    public static function pick_test_image()
    {
        static $cache = false;
        if ($cache !== false) return $cache;
        $cache = null;
        if (!function_exists('get_posts')) return $cache;


        if (!class_exists('WPC_Negotiated_Delivery') && defined('WPS_IC_DIR')
            && @file_exists(WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php')) {
            require_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
        }


        $st = self::wpc_ensure_selftest_image();
        if (is_array($st) && !empty($st['url'])
            && class_exists('WPC_Negotiated_Delivery') && method_exists('WPC_Negotiated_Delivery', 'build_natural_url')) {
            $st_webp = (string) WPC_Negotiated_Delivery::build_natural_url('wpc-selftest.png', ['file' => 'wpc-selftest.png']);
            if ($st_webp !== '' && $st_webp !== $st['url']) {
                $cache = ['attachment_id' => 0, 'origin_jpg_url' => $st['url'], 'cdn_webp_url' => $st_webp, 'local_variant' => false, 'selftest' => true];
                return $cache;
            }
        }

        $atts = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status'    => 'inherit',
            'posts_per_page' => 8,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        if (empty($atts)) return $cache;

        $fallback = null;
        foreach ($atts as $id) {
            $file = function_exists('get_attached_file') ? get_attached_file($id) : '';
            if (!$file || !file_exists($file)) continue;

            $jpg_url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($id) : '';
            if (!$jpg_url) continue;

            $cdn_webp = '';
            if (class_exists('WPC_Negotiated_Delivery') && method_exists('WPC_Negotiated_Delivery', 'build_natural_url')) {
                $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
                if (is_array($meta) && !empty($meta['file'])) {
                    $cdn_webp = WPC_Negotiated_Delivery::build_natural_url(basename((string) $meta['file']), $meta);
                }
            }
            if ($cdn_webp === '') continue;


            $has_local = file_exists($file . '.webp') || file_exists($file . '.avif')
                      || file_exists(preg_replace('/\.(jpe?g|png)$/i', '.webp', $file))
                      || file_exists(preg_replace('/\.(jpe?g|png)$/i', '.avif', $file));
            if (!$has_local) {
                $lv = function_exists('get_post_meta') ? get_post_meta($id, 'ic_local_variants', true) : '';
                if (is_array($lv) && function_exists('wp_get_upload_dir')) {
                    $ud   = wp_get_upload_dir();
                    $burl = !empty($ud['baseurl']) ? (string) $ud['baseurl'] : '';
                    $bdir = !empty($ud['basedir']) ? (string) $ud['basedir'] : '';
                    foreach ($lv as $vv) {
                        if (!is_array($vv) || empty($vv['local']) || !empty($vv['skipped'])) continue;
                        $vu = isset($vv['url']) ? (string) $vv['url'] : '';
                        if (!preg_match('/\.(avif|webp)(\?|$)/i', $vu)) continue;
                        if ($burl !== '' && $bdir !== '' && strpos($vu, $burl) === 0) {
                            $vpath = preg_replace('/\?.*$/', '', $bdir . substr($vu, strlen($burl)));
                            if (@file_exists($vpath)) { $has_local = true; break; }
                        }
                    }
                }
            }
            $entry = ['attachment_id' => (int) $id, 'origin_jpg_url' => $jpg_url, 'cdn_webp_url' => $cdn_webp, 'local_variant' => $has_local];
            if ($has_local) { $cache = $entry; return $cache; }


            if ($fallback === null) $fallback = $entry;
        }
        $cache = $fallback;
        return $cache;
    }


    /**
     * Up to $limit probe candidates, newest first. One un-landed upload must never be able to
     * decide the whole site's delivery — the verify passes if ANY candidate serves next-gen.
     */
    public static function pick_real_image_probes($limit = 3)
    {
        $out = [];
        if (!function_exists('get_posts')) return $out;
        if (!class_exists('WPC_Negotiated_Delivery') && defined('WPS_IC_DIR')
            && @file_exists(WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php')) {
            require_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
        }
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'build_natural_url')) {
            return $out;
        }
        $limit = max(1, min(5, (int) $limit));
        $atts = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status'    => 'inherit',
            'posts_per_page' => 12,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'suppress_filters' => true,
        ]);
        if (empty($atts)) return $out;
        foreach ($atts as $id) {
            $file = function_exists('get_attached_file') ? get_attached_file($id) : '';
            if (!$file || !@file_exists($file)) continue;
            $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
            if (!is_array($meta) || empty($meta['file'])) continue;
            $sub_file  = basename((string) $meta['file']);
            $best_area = PHP_INT_MAX;
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz) {
                    if (empty($sz['file']) || empty($sz['width']) || empty($sz['height'])) continue;
                    $area = (int) $sz['width'] * (int) $sz['height'];
                    if ($area > 0 && $area < $best_area) { $best_area = $area; $sub_file = (string) $sz['file']; }
                }
            }
            $cdn_webp = (string) WPC_Negotiated_Delivery::build_natural_url($sub_file, $meta);
            if ($cdn_webp === '') continue;
            $out[] = ['attachment_id' => (int) $id, 'cdn_webp_url' => $cdn_webp];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public static function pick_real_image_probe()
    {
        static $cache = false;
        if ($cache !== false) return $cache;
        $cache = null;
        if (!function_exists('get_posts')) return $cache;
        if (!class_exists('WPC_Negotiated_Delivery') && defined('WPS_IC_DIR')
            && @file_exists(WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php')) {
            require_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
        }
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'build_natural_url')) {
            return $cache;
        }
        $atts = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status'    => 'inherit',
            'posts_per_page' => 8,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        if (empty($atts)) return $cache;
        foreach ($atts as $id) {
            $file = function_exists('get_attached_file') ? get_attached_file($id) : '';
            if (!$file || !@file_exists($file)) continue;
            $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
            if (!is_array($meta) || empty($meta['file'])) continue;
            // Prefer the SMALLEST registered subsize (-WxH) — always inside the edge OTF budget — over
            // the full-size original. Fall back to the full natural URL if no subsizes are recorded.
            $sub_file  = basename((string) $meta['file']);
            $best_area = PHP_INT_MAX;
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz) {
                    if (empty($sz['file']) || empty($sz['width']) || empty($sz['height'])) continue;
                    $area = (int) $sz['width'] * (int) $sz['height'];
                    if ($area > 0 && $area < $best_area) { $best_area = $area; $sub_file = (string) $sz['file']; }
                }
            }
            $cdn_webp = (string) WPC_Negotiated_Delivery::build_natural_url($sub_file, $meta);
            if ($cdn_webp === '') continue;
            $cache = ['attachment_id' => (int) $id, 'cdn_webp_url' => $cdn_webp];
            return $cache;
        }
        return $cache;
    }


    public static function wpc_ensure_selftest_image()
    {
        if (!function_exists('wp_get_upload_dir')) return false;
        $ud = wp_get_upload_dir();
        if (!is_array($ud) || empty($ud['basedir']) || empty($ud['baseurl'])) return false;
        $path = rtrim((string) $ud['basedir'], '/\\') . '/wpc-selftest.png';
        $url  = rtrim((string) $ud['baseurl'], '/')   . '/wpc-selftest.png';
        if (@file_exists($path) && @filesize($path) > 0) return ['path' => $path, 'url' => $url];
        // Generate it. GD is a WP requirement on virtually all hosts; if absent, fall back to a real image.
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return false;
        if (!@is_writable((string) $ud['basedir'])) return false;
        $w = 400; $h = 300;
        $im = @imagecreatetruecolor($w, $h);
        if (!$im) return false;
        for ($x = 0; $x < $w; $x++) {
            $r   = (int) (255 * $x / $w);
            $col = imagecolorallocate($im, $r, 128, 255 - $r);
            imageline($im, $x, 0, $x, $h, $col);
        }
        $ok = @imagepng($im, $path);
        if (function_exists('imagedestroy')) imagedestroy($im);
        if (!$ok || !@file_exists($path) || @filesize($path) <= 0) return false;
        return ['path' => $path, 'url' => $url];
    }


    //  ORCHESTRATION — cache, re-verify on signature change, public API.


    /**
     * The resolved delivery tier for THIS site (cached). Safe-by-default: if the cache is stale
     * or the optimistic tiers aren't yet verified, returns the universal fallback and schedules
     * a verify, so we never serve via an unproven path.
     */
    public static function resolve($force = false)
    {
        $r = self::resolve_verbose($force);
        return $r['tier'];
    }

    public static function resolve_verbose($force = false)
    {
        $caps = self::gather_capabilities();
        $sig  = self::env_signature($caps);
        $state = function_exists('get_option') ? get_option(self::STATE_OPTION) : null;
        $fresh = is_array($state)
            && isset($state['sig']) && $state['sig'] === $sig
            && isset($state['at']) && (self::now() - (int) $state['at']) < self::VERIFY_TTL;

        if ($force || !$fresh) {


            $is_cron      = defined('DOING_CRON') && DOING_CRON;
            $cold_pending = is_array($state) && isset($state['sig']) && $state['sig'] === $sig && !empty($state['cold']);
            if ($force || (self::can_verify_inline() && ($is_cron || !$cold_pending))) {
                $fresh_verify = self::run_verifications($caps);
                // Demote-hysteresis: don't drop a proven tier on a single failed probe.
                $persist = self::persist_after_verify($sig, $state, $fresh_verify);
                $verify  = $persist['verify'];
                if (function_exists('update_option')) {
                    update_option(self::STATE_OPTION, $persist, false);
                }
            } else {
                self::schedule_verify();
                $verify = (is_array($state) && isset($state['sig']) && $state['sig'] === $sig && isset($state['verify']))
                    ? $state['verify'] : ['cdn' => null, 'htaccess' => null];
            }
        } else {
            $verify = isset($state['verify']) ? $state['verify'] : ['cdn' => null, 'htaccess' => null];
        }

        $settings = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        $override = self::override_mechanism(is_array($settings) ? $settings : []);
        $out = self::resolve_tier_from($caps, $verify, $override);
        $out['capabilities'] = $caps;
        $out['ceiling'] = isset($caps['ceiling']) ? $caps['ceiling'] : 'off';
        $out['override'] = $override;
        $out['verify'] = $verify;
        $out['signature'] = $sig;


        self::maybe_purge_on_delivery_change($out['tier'], $verify, $caps);
        return $out;
    }


    private static function maybe_purge_on_delivery_change($tier, $verify, $caps)
    {


        $admin_ctx = (function_exists('is_admin') && is_admin())
            || (defined('WP_CLI') && WP_CLI)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('REST_REQUEST') && REST_REQUEST);
        if (!$admin_ctx) return;
        if (function_exists('apply_filters') && !apply_filters('wpc_delivery_tier_purge_enabled', true)) return;
        if (!function_exists('get_option') || !function_exists('update_option')) return;

        $cdn_ok      = (isset($verify['cdn']['ok']) && $verify['cdn']['ok'] === true) ? '1' : '0';
        $ceiling     = isset($caps['ceiling']) ? (string) $caps['ceiling'] : 'off';
        $fingerprint = (string) $tier . ':' . $cdn_ok . ':' . $ceiling;

        $prev = get_option('wpc_delivery_applied_fp', null);
        if ($prev === null || $prev === false || $prev === '') {
            // First observation — record the current mode WITHOUT purging (nothing to invalidate yet).
            update_option('wpc_delivery_applied_fp', $fingerprint, false);
            return;
        }
        if ((string) $prev === $fingerprint) return;

        // Effective delivery mode genuinely changed → emitted markup differs → purge once + record.
        update_option('wpc_delivery_applied_fp', $fingerprint, false);
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            wpc_foreign_purge610(false, 'delivery-resolver');
        }
        if (function_exists('error_log')) {
            error_log(sprintf('[WPC DeliveryTierPurge] delivery mode %s -> %s — full HTML cache purge', (string) $prev, $fingerprint));
        }
    }


    private static function persist_after_verify($sig, $prior_state, $fresh_verify)
    {
        $prior_for_sig = is_array($prior_state)
            && isset($prior_state['sig']) && $prior_state['sig'] === $sig
            && isset($prior_state['verify']) && is_array($prior_state['verify']);

        $prior_proven = $prior_for_sig && (
            (isset($prior_state['verify']['cdn']['ok']) && $prior_state['verify']['cdn']['ok'] === true)
            || (isset($prior_state['verify']['htaccess']['ok']) && $prior_state['verify']['htaccess']['ok'] === true)
        );

        $fresh_proven = (isset($fresh_verify['cdn']['ok']) && $fresh_verify['cdn']['ok'] === true)
            || (isset($fresh_verify['htaccess']['ok']) && $fresh_verify['htaccess']['ok'] === true);


        if (!$prior_proven && !$fresh_proven) {
            $fcdn = (isset($fresh_verify['cdn']) && is_array($fresh_verify['cdn'])) ? $fresh_verify['cdn'] : null;
            $cdn_cold_miss = is_array($fcdn) && array_key_exists('ok', $fcdn) && $fcdn['ok'] === false
                && isset($fcdn['detail']) && strpos((string) $fcdn['detail'], 'no-200') !== false;
            if ($cdn_cold_miss) {
                $cold = (int) ($prior_for_sig && isset($prior_state['cold']) ? $prior_state['cold'] : 0) + 1;
                if ($cold < self::VERIFY_COLD_GRACE) {
                    return [
                        'sig'    => $sig,
                        'verify' => ['cdn' => null, 'htaccess' => (isset($fresh_verify['htaccess']) ? $fresh_verify['htaccess'] : null)],
                        'at'     => 0,
                        'fails'  => 0,
                        'cold'   => $cold,
                    ];
                }
                // Stayed cold across VERIFY_COLD_GRACE re-verifies → genuinely unservable; fall through.
            }
        }

        if ($prior_proven && !$fresh_proven) {


            $prior_cdn_ok = isset($prior_state['verify']['cdn']['ok']) && $prior_state['verify']['cdn']['ok'] === true;
            $prior_ht_ok  = isset($prior_state['verify']['htaccess']['ok']) && $prior_state['verify']['htaccess']['ok'] === true;
            $cdn_failed = isset($fresh_verify['cdn']) && is_array($fresh_verify['cdn'])
                && array_key_exists('ok', $fresh_verify['cdn']) && $fresh_verify['cdn']['ok'] === false;
            $ht_failed  = isset($fresh_verify['htaccess']) && is_array($fresh_verify['htaccess'])
                && array_key_exists('ok', $fresh_verify['htaccess']) && $fresh_verify['htaccess']['ok'] === false;
            $proven_conclusively_failed = ($prior_cdn_ok && $cdn_failed) || ($prior_ht_ok && $ht_failed);

            if (!$proven_conclusively_failed) {


                return [
                    'sig'          => $sig,
                    'verify'       => $prior_state['verify'],
                    'at'           => (int) (isset($prior_state['at']) ? $prior_state['at'] : 0),
                    'fails'        => (int) (isset($prior_state['fails']) ? $prior_state['fails'] : 0),
                    'held'         => 1,
                    'inconclusive' => 1,
                ];
            }

            // Conclusive failure of the proven clean-URL tier.
            $fails = (int) (isset($prior_state['fails']) ? $prior_state['fails'] : 0) + 1;
            if ($fails < self::VERIFY_FAIL_GRACE) {
                // Hold last-good; keep 'at' stale so we keep re-verifying soon (not after the TTL).
                return [
                    'sig'    => $sig,
                    'verify' => $prior_state['verify'],
                    'at'     => (int) (isset($prior_state['at']) ? $prior_state['at'] : 0),
                    'fails'  => $fails,
                    'held'   => 1,
                ];
            }
            // Confirmed broken across VERIFY_FAIL_GRACE consecutive PROBED checks → accept demotion.
            return ['sig' => $sig, 'verify' => $fresh_verify, 'at' => self::now(), 'fails' => $fails];
        }

        // Passing re-verify, first proof, or real env change → take fresh, reset the counter.
        return ['sig' => $sig, 'verify' => $fresh_verify, 'at' => self::now(), 'fails' => 0];
    }

    private static function can_verify_inline()
    {
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (function_exists('is_admin') && is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) return true;
        return false;
    }

    private static function schedule_verify()
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) return;
        if (!wp_next_scheduled('wpc_delivery_verify')) {
            wp_schedule_single_event(self::now() + 30, 'wpc_delivery_verify');
        }
    }

    /** Cron/manual hook target: force a fresh verification + cache write. */
    public static function cron_verify()
    {
        self::resolve_verbose(true);
    }

    private static function now()
    {
        return function_exists('current_time') ? (int) current_time('timestamp', true) : (defined('WPC_TEST_NOW') ? WPC_TEST_NOW : 0);
    }
}

if (function_exists('add_action')) {
    add_action('wpc_delivery_verify', ['WPC_Delivery_Resolver', 'cron_verify']);
    // Overdue single event: spawn on admin visits, throttled.
    add_action('admin_init', function () {
        $wpc_dvts = function_exists('wp_next_scheduled') ? wp_next_scheduled('wpc_delivery_verify') : false;
        if ($wpc_dvts && $wpc_dvts < time() - 600 && function_exists('spawn_cron') && !get_transient('wpc_dv_spawned')) {
            set_transient('wpc_dv_spawned', 1, 600);
            wpc_spawn_cron();
        }
    });
}
