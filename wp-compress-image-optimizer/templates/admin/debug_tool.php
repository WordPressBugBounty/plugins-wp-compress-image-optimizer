<?php
global $wps_ic, $wpdb;

// ─── CDN Provisioning & Delivery — status + guaranteed sync (v7.03.42) ───────────────────
// Operator window into the suppression/provisioning state with NO SSH/DB needed: answers
// "is THIS zone provisioned, and if it's serving origin, exactly why?" + a one-click force
// provision-and-re-verify. The un-suppress itself stays verify-gated (the orch-trigger path),
// so this can never flip a zone live into broken images — it only nudges + reports.
$wpcProvMsg = '';
if (isset($_POST['wpc_prov_action']) && $_POST['wpc_prov_action'] === 'sync'
    && current_user_can('manage_options') && check_admin_referer('wpc_prov_diag')) {
    if (function_exists('delete_option'))    { delete_option('wpc_v2_selfheal_attempts'); }
    if (function_exists('delete_transient')) { delete_transient('wpc_v2_selfheal_backoff'); delete_transient('wpc_v2_config_force_backoff'); delete_transient('wpc_v2_zone_reresolve_bk'); }
    if (function_exists('update_option'))    { update_option('wpc_v2_force_provision', 1, false); }
    // Heal a stale/ghost cached zone_id FIRST (a clone can inherit a deleted PZ), re-resolving from the
    // apikey's live zone via /v2/zone — then provision + verify against the REAL zone.
    if (function_exists('wpc_v2_resolve_zone_id'))         { wpc_v2_resolve_zone_id(true); }
    if (function_exists('wpc_v2_run_deferred_config_sync')) { wpc_v2_run_deferred_config_sync(); }
    if (function_exists('wpc_v2_cf_cname_reverify'))        { wpc_v2_cf_cname_reverify(false); }
    $vr = function_exists('wpc_v2_verify_and_unsuppress') ? wpc_v2_verify_and_unsuppress('admin_button') : ['stamped' => false, 'reason' => 'handler_missing'];
    $vz = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '?';
    if (!empty($vr['stamped'])) {
        $wpcProvMsg = 'Real-content verify PASSED (edge served 200 image/webp) on zone ' . $vz . ' → fingerprint stamped → CDN UN-SUPPRESSED. Reload the front-end: images + CSS/JS should now be on the CDN.';
    } elseif (($vr['reason'] ?? '') === 'edge_not_serving_webp') {
        $wpcProvMsg = 'Edge did NOT serve a real .webp on zone ' . $vz . ' (probe: HTTP ' . (string) ($vr['probe']['code'] ?? '?') . ', format "' . (string) ($vr['probe']['fmt'] ?? '?') . '"). Staying on ORIGIN (safe). If zone ' . $vz . ' is still a dead PZ, the orch must provision it. Probe URL: ' . (string) ($vr['probe_url'] ?? '');
    } elseif (($vr['reason'] ?? '') === 'no_real_image_to_verify') {
        $wpcProvMsg = 'No real image found to verify against — upload an image to the media library, then retry.';
    } else {
        $wpcProvMsg = 'Provision fired; un-suppress still pending (reason: ' . (string) ($vr['reason'] ?? 'unknown') . ').';
    }
}
if (current_user_can('manage_options')) {
    $pEnvUnconf  = function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed();
    $pSuppressed = function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed();
    $pApikey     = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
    $pZone       = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
    $pCname      = trim((string) get_option('ic_custom_cname', '')); if ($pCname === '') { $pCname = trim((string) get_option('ic_cdn_zone_name', '')); }
    $pSynced     = (int) get_option('wpc_v2_config_synced_at', 0);
    $pForce      = (bool) get_option('wpc_v2_force_provision', false);
    $pAttempts   = (int) get_option('wpc_v2_selfheal_attempts', 0);
    $pPoison     = trim((string) get_option('wpc_v2_cdn_poison_reason', ''));
    $pCompeting  = trim((string) get_option('wpc_v2_foreign_cdn', ''));
    $pTier = '(resolver unavailable)';
    if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
        $rv = WPC_Delivery_Resolver::resolve_verbose(false);
        if (is_array($rv)) { $pTier = (string) ($rv['reason'] ?? ($rv['tier'] ?? '?')); }
    }
    $pWhy = [];
    if ($pEnvUnconf) { $pWhy[] = 'env unconfirmed (fresh install / staging clone / migration — not yet verified for THIS host)'; }
    if (function_exists('wpc_v2_zone_cdn_disabled')  && wpc_v2_zone_cdn_disabled())  { $pWhy[] = 'orch master-kill (cdn_disabled)'; }
    if (function_exists('wpc_v2_zone_auto_disabled') && wpc_v2_zone_auto_disabled()) { $pWhy[] = 'liveness auto-disable'; }
    if (function_exists('wpc_cdn_zone_is_foreign') && wpc_cdn_zone_is_foreign()) { $pWhy[] = 'CDN zone hostname belongs to a DIFFERENT site (clone healed the id, not the hostname) — auto re-resolving this PZ\'s real hostname via /v2/zone'; }
    $pNavKey = ($pZone !== '') ? ('wpc_v2_orch_nav_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $pZone)) : '';
    $pNav    = ($pNavKey !== '') ? get_option($pNavKey, '(not echoed)') : '(no zone)';

    $yes = '<span style="color:#166534;font-weight:600;">YES</span>';
    $no  = '<span style="color:#991b1b;font-weight:600;">NO</span>';
    echo '<div style="margin:14px 0;padding:14px 16px;border:1px solid #c5d3e3;border-radius:8px;background:#f1f5f9;">';
    echo '<h3 style="margin:0 0 8px;color:#19335b;font-size:15px;">CDN Provisioning &amp; Delivery</h3>';
    if ($wpcProvMsg !== '') { echo '<div style="margin-bottom:10px;padding:8px 10px;background:#effbf1;border:1px solid #22b73a;border-radius:6px;color:#166534;font-size:12px;">' . esc_html($wpcProvMsg) . '</div>'; }
    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
    $prow = function ($k, $v) { echo '<tr><td style="padding:4px 8px;color:#314b72;width:290px;vertical-align:top;">' . $k . '</td><td style="padding:4px 8px;font-family:monospace;">' . $v . '</td></tr>'; };
    $prow('Connected (API key present)', $pApikey !== '' ? $yes : $no);
    $prow('Zone identity', $pZone !== '' ? (esc_html($pZone) . (ctype_digit($pZone) ? ' (Bunny PZ)' : '')) : ($pCname !== '' ? (esc_html($pCname) . ' (CNAME)') : '<span style="color:#991b1b;">none</span>'));
    $pZoneHost  = strtolower(trim((string) get_option('ic_cdn_zone_name', '')));
    $pZoneStamp = strtolower(trim((string) get_option('ic_cdn_zone_name_host', '')));
    $pForeign   = function_exists('wpc_cdn_zone_is_foreign') && wpc_cdn_zone_is_foreign();
    $pHomeHost  = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
    if ($pZoneHost !== '') { $prow('CDN zone hostname (builds image URLs)', esc_html($pZoneHost)); }
    $prow('Zone &harr; site affinity', $pForeign
        ? ($no . ' &rarr; FOREIGN (this site is <code>' . esc_html($pHomeHost) . '</code>) &mdash; serving ORIGIN + healing via /v2/zone')
        : ($yes . ($pZoneStamp !== '' ? ' (confirmed for ' . esc_html($pZoneStamp) . ')' : '')));
    $prow('Provisioning confirmed for THIS host', $pEnvUnconf ? ($no . ' — fingerprint not stamped') : $yes);
    $prow('CDN suppressed right now', $pSuppressed ? ($yes . ' &rarr; serving ORIGIN (fail-safe, no 404)') : ($no . ' &rarr; CDN live'));
    if ($pSuppressed && !empty($pWhy)) { $prow('&hellip;why suppressed', esc_html(implode('; ', $pWhy))); }
    $prow('Resolved delivery tier', esc_html($pTier));
    $prow('orch native_accept_vary witness', esc_html((string) $pNav));
    $prow('Last /v2/config sync', $pSynced > 0 ? esc_html(human_time_diff($pSynced) . ' ago (' . date('Y-m-d H:i:s', $pSynced) . ')') : '<span style="color:#991b1b;">never</span>');
    $prow('Provision pending (force flag)', $pForce ? $yes : $no);
    $prow('Self-heal attempts', (string) $pAttempts);
    $prow('CDN content-verify', $pPoison !== ''
        ? '<span style="color:#991b1b;font-weight:600;">STOOD DOWN</span> &rarr; origin served <code>' . esc_html($pPoison) . '</code> to the CDN (page protected; serving origin)'
        : '<span style="color:#166534;">edge serves real images</span>');
    $prow('Competing CDN in front of origin', $pCompeting !== ''
        ? '<span style="color:#991b1b;font-weight:600;">' . esc_html($pCompeting) . '</span> &rarr; double-CDN; can conflict with WPC&rsquo;s zone (poisoned pulls / cache fights)'
        : 'none detected');
    echo '</table>';
    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('wpc_prov_diag');
    echo '<input type="hidden" name="wpc_prov_action" value="sync">';
    echo '<button type="submit" style="background:#4273f0;color:#fff;border:0;padding:8px 16px;border-radius:6px;font-size:12px;cursor:pointer;">Force provision + re-verify now</button>';
    echo '<span style="margin-left:10px;color:#64748b;font-size:11px;">Fires /v2/config + a delivery re-verify (may take a few seconds). Un-suppress stays gated on a real-content verify.</span>';
    echo '</form>';
    echo '</div>';
}

// ─── URL Exclusions diagnostic + force-fix panel ─────────────────────
// Lets admins verify the "Exclude from Plugin" feature end-to-end without SSH.
// Handles 3 actions: regen advanced-cache.php, purge all caches, test a URL against the matcher.

if (isset($_POST['wpc_excl_action']) && current_user_can('manage_options') && check_admin_referer('wpc_excl_diag')) {
    $action = sanitize_text_field($_POST['wpc_excl_action']);
    $exclMessages = [];

    if ($action === 'regen') {
        if (!class_exists('wps_ic_htaccess')) {
            @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }
        if (class_exists('wps_ic_htaccess')) {
            $h = new wps_ic_htaccess();
            $h->setWPCache(true);
            $h->setAdvancedCache();

            // Force opcache to drop the stale wp-config.php bytecode, otherwise PHP-FPM
            // keeps serving the OLD constant value even after we wrote the new one.
            if (function_exists('opcache_invalidate')) {
                $cfgPath = ABSPATH . 'wp-config.php';
                if (!file_exists($cfgPath) && file_exists(dirname(ABSPATH) . '/wp-config.php')) {
                    $cfgPath = dirname(ABSPATH) . '/wp-config.php';
                }
                @opcache_invalidate($cfgPath, true);
                @opcache_invalidate(ABSPATH . 'wp-content/advanced-cache.php', true);
            }

            $exclMessages[] = ['ok', 'Regenerated advanced-cache.php, re-asserted WP_CACHE in wp-config.php, and invalidated opcache.'];
        } else {
            $exclMessages[] = ['err', 'wps_ic_htaccess class not found.'];
        }
    }

    if ($action === 'purge') {
        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
            $exclMessages[] = ['ok', 'Purged page cache, object cache, and CDN.'];
        }
        wp_cache_flush();
        // Also wipe disk cache directly
        $cacheDirs = [
            WP_CONTENT_DIR . '/cache/wpc',
            WP_CONTENT_DIR . '/wpc-content/cache',
        ];
        foreach ($cacheDirs as $cd) {
            if (is_dir($cd)) {
                $files = glob($cd . '/*');
                if ($files) {
                    foreach ($files as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                }
            }
        }
        $exclMessages[] = ['ok', 'Wiped on-disk cache directories.'];
    }
}

$urlExcludes = get_option('wpc-url-excludes', []);
$pluginPatterns = (is_array($urlExcludes) && !empty($urlExcludes['exclude-url-from-all'])) ? $urlExcludes['exclude-url-from-all'] : [];
$cacheExcludes = get_option('wpc-excludes', []);
$cachePatterns = (is_array($cacheExcludes) && !empty($cacheExcludes['cache'])) ? $cacheExcludes['cache'] : [];

$advCachePath = ABSPATH . 'wp-content/advanced-cache.php';
$advCacheExists = file_exists($advCachePath);
$advCacheHasWildcard = false;
$advCacheModified = '—';
if ($advCacheExists) {
    $advCacheBody = @file_get_contents($advCachePath);
    $advCacheHasWildcard = ($advCacheBody !== false && strpos($advCacheBody, 'wpc-url-excludes') !== false);
    $advCacheModified = date('Y-m-d H:i:s', filemtime($advCachePath));
}

$wpConfigPath = ABSPATH . 'wp-config.php';
if (!file_exists($wpConfigPath) && file_exists(dirname(ABSPATH) . '/wp-config.php')) {
    $wpConfigPath = dirname(ABSPATH) . '/wp-config.php';
}
$wpConfigWritable = file_exists($wpConfigPath) && is_writable($wpConfigPath);

// Pull all WP_CACHE-related lines from wp-config.php so we can SEE what's there
$wpCacheLines = [];
if (file_exists($wpConfigPath)) {
    $cfgBody = @file_get_contents($wpConfigPath);
    if ($cfgBody !== false) {
        $allLines = preg_split('/\r\n|\r|\n/', $cfgBody);
        foreach ($allLines as $i => $line) {
            if (stripos($line, 'WP_CACHE') !== false) {
                $wpCacheLines[] = ($i + 1) . ': ' . $line;
            }
        }
    }
}

// Detect opcache so we can warn if it's likely caching wp-config.php
// Many hosts (WP Engine, Kinsta, others) restrict the opcache API via `restrict_api`
// configuration. The check below tolerates that case without emitting warnings.
$opcacheActive = false;
if (function_exists('opcache_get_status')) {
    try {
        $prevLevel = error_reporting(0);
        $status = @opcache_get_status(false);
        error_reporting($prevLevel);
        $opcacheActive = ($status !== false);
    } catch (\Throwable $e) {
        $opcacheActive = false;
    }
}

// URL tester runs entirely in the browser (see JS at bottom of panel) so WAFs
// don't block POSTs containing URL-shaped values.

echo '<div style="background:#fffbeb;border:2px solid #fbbf24;padding:18px 22px;margin:10px 0;border-radius:8px;font-family:-apple-system,sans-serif;font-size:13px;">';
echo '<strong style="font-size:15px;color:#92400e;">URL Exclusions Diagnostic</strong>';
echo '<div style="color:#78350f;font-size:11px;margin-top:2px;">Use this to verify "Exclude from Plugin" / "Exclude from Cache" are wired correctly.</div>';
echo '<br>';

if (!empty($exclMessages)) {
    foreach ($exclMessages as $m) {
        $bg = $m[0] === 'ok' ? '#dcfce7' : '#fee2e2';
        $bd = $m[0] === 'ok' ? '#86efac' : '#fca5a5';
        $cl = $m[0] === 'ok' ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . ';border:1px solid ' . $bd . ';color:' . $cl . ';padding:8px 12px;border-radius:6px;margin-bottom:8px;font-size:12px;">' . esc_html($m[1]) . '</div>';
    }
}

echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
echo '<tr><td style="padding:5px 8px;color:#78350f;width:260px;">Plugin exclude patterns saved:</td><td style="padding:5px 8px;font-family:monospace;">' . (empty($pluginPatterns) ? '<span style="color:#991b1b;">(none — feature inactive)</span>' : '<span style="color:#166534;">' . count($pluginPatterns) . ' pattern(s): ' . esc_html(implode(', ', $pluginPatterns)) . '</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">Cache exclude patterns saved:</td><td style="padding:5px 8px;font-family:monospace;">' . (empty($cachePatterns) ? '<span style="color:#6b7280;">(none)</span>' : '<span style="color:#166534;">' . count($cachePatterns) . ' pattern(s): ' . esc_html(implode(', ', $cachePatterns)) . '</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">advanced-cache.php exists:</td><td style="padding:5px 8px;font-family:monospace;">' . ($advCacheExists ? '<span style="color:#166534;">YES</span>' : '<span style="color:#991b1b;">NO</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">…has wildcard URL exclusion code:</td><td style="padding:5px 8px;font-family:monospace;">' . ($advCacheHasWildcard ? '<span style="color:#166534;">YES (new template)</span>' : '<span style="color:#991b1b;">NO — drop-in is stale, click "Regenerate" below</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">…last modified:</td><td style="padding:5px 8px;font-family:monospace;">' . esc_html($advCacheModified) . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">WP_CACHE constant:</td><td style="padding:5px 8px;font-family:monospace;">' . (defined('WP_CACHE') && WP_CACHE ? '<span style="color:#166534;">true</span>' : '<span style="color:#991b1b;">false &mdash; advanced-cache.php is ignored! Click "Regenerate" below or add <code>define(\'WP_CACHE\', true);</code> to wp-config.php manually.</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">wp-config.php writable:</td><td style="padding:5px 8px;font-family:monospace;">' . ($wpConfigWritable ? '<span style="color:#166534;">YES</span>' : '<span style="color:#991b1b;">NO &mdash; we cannot auto-set WP_CACHE; you must add <code>define(\'WP_CACHE\', true);</code> manually</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">wp-config.php path:</td><td style="padding:5px 8px;font-family:monospace;font-size:11px;">' . esc_html($wpConfigPath) . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;vertical-align:top;">WP_CACHE lines in wp-config.php:</td><td style="padding:5px 8px;font-family:monospace;font-size:11px;">';
if (empty($wpCacheLines)) {
    echo '<span style="color:#991b1b;">(none found &mdash; setWPCache() never wrote, or wrote to wrong file)</span>';
} else {
    foreach ($wpCacheLines as $ln) {
        echo '<div>' . esc_html($ln) . '</div>';
    }
    if (count($wpCacheLines) > 1) {
        echo '<div style="color:#991b1b;margin-top:4px;">\u26A0 Multiple definitions found &mdash; the LAST one wins. Make sure none of them say <code>false</code>.</div>';
    }
}
echo '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">PHP opcache active:</td><td style="padding:5px 8px;font-family:monospace;">' . ($opcacheActive ? '<span style="color:#92400e;">YES &mdash; new wp-config.php may be cached. Click "Regenerate" (now invalidates opcache too) then refresh this page.</span>' : '<span style="color:#6b7280;">no</span>') . '</td></tr>';
echo '<tr><td style="padding:5px 8px;color:#78350f;">wpc_url_is_excluded() helper:</td><td style="padding:5px 8px;font-family:monospace;">' . (function_exists('wpc_url_is_excluded') ? '<span style="color:#166534;">loaded</span>' : '<span style="color:#991b1b;">MISSING</span>') . '</td></tr>';
echo '</table>';

echo '<form method="post" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">';
wp_nonce_field('wpc_excl_diag');
echo '<button type="submit" name="wpc_excl_action" value="regen" style="background:#ca8a04;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;cursor:pointer;">Regenerate advanced-cache.php</button>';
echo '<button type="submit" name="wpc_excl_action" value="purge" style="background:#dc2626;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;cursor:pointer;">Purge ALL caches</button>';
echo '</form>';

echo '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #fbbf24;">';
echo '<strong style="color:#92400e;">Test a URL against your saved patterns:</strong>';
echo '<div style="color:#78350f;font-size:11px;margin-top:2px;">Runs entirely in your browser — no server request, no WAF involved.</div>';
echo '<div style="margin-top:8px;display:flex;gap:6px;">';
echo '<input type="text" id="wpc-excl-test-input" placeholder="https://yoursite.com/offer/di-premium/?source=foo" style="flex:1;padding:8px 10px;border:1px solid #fbbf24;border-radius:6px;font-family:monospace;font-size:12px;">';
echo '<button type="button" id="wpc-excl-test-btn" style="background:#0f172a;color:#fff;border:0;padding:8px 16px;border-radius:6px;font-size:12px;cursor:pointer;">Test</button>';
echo '</div>';
echo '<div id="wpc-excl-test-result" style="display:none;margin-top:10px;padding:10px 12px;background:#fff;border:1px solid #fde68a;border-radius:6px;font-family:monospace;font-size:12px;"></div>';
echo '</div>';

// Inject patterns for the in-browser tester (matcher logic lives in clean heredoc below)
$jsPluginPatterns = wp_json_encode(array_values($pluginPatterns));
$jsCachePatterns  = wp_json_encode(array_values($cachePatterns));
?>
<script>
(function(){
    var WPC_PLUGIN_PATTERNS = <?php echo $jsPluginPatterns; ?> || [];
    var WPC_CACHE_PATTERNS  = <?php echo $jsCachePatterns; ?> || [];

    // Mirrors wpc_url_matches_pattern() from wp-compress-core.php — char-by-char to avoid
    // any string-escape nightmares with regex metacharacters.
    function wpcMatches(url, pattern) {
        pattern = (pattern || '').trim();
        if (!pattern || pattern.charAt(0) === '#') return false;
        pattern = pattern.replace(/^\/+/, '');
        // No wildcards → case-insensitive substring match
        if (pattern.indexOf('*') === -1 && pattern.indexOf('?') === -1) {
            return url.toLowerCase().indexOf(pattern.toLowerCase()) !== -1;
        }
        // Has wildcards → build regex character-by-character
        var rx = '';
        var meta = '.+^${}()|[]\\';
        for (var i = 0; i < pattern.length; i++) {
            var c = pattern.charAt(i);
            if (c === '*') {
                if (pattern.charAt(i + 1) === '*') { rx += '.*'; i++; }
                else { rx += '[^/]*'; }
            } else if (c === '?') {
                rx += '.';
            } else if (meta.indexOf(c) !== -1) {
                rx += '\\' + c;
            } else {
                rx += c;
            }
        }
        try { return new RegExp(rx, 'i').test(url); } catch (e) { return false; }
    }

    function wpcFirstMatch(url, list) {
        for (var i = 0; i < list.length; i++) {
            if (wpcMatches(url, list[i])) return list[i];
        }
        return false;
    }

    function wpcEsc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    var btn = document.getElementById('wpc-excl-test-btn');
    var input = document.getElementById('wpc-excl-test-input');
    var box = document.getElementById('wpc-excl-test-result');

    if (btn && input && box) {
        btn.addEventListener('click', function() {
            var raw = input.value.trim();
            if (!raw) return;
            var normalized = raw.replace(/^https?:\/\//i, '').split('?')[0];
            var p = wpcFirstMatch(normalized, WPC_PLUGIN_PATTERNS);
            var c = wpcFirstMatch(normalized, WPC_CACHE_PATTERNS);
            var html = '<div style="color:#6b7280;">Normalized URL: <code>' + wpcEsc(normalized) + '</code></div>';
            html += p
                ? '<div style="color:#16a34a;margin-top:6px;">\u2713 <strong>EXCLUDED FROM PLUGIN</strong> \u2014 matched pattern: <code>' + wpcEsc(p) + '</code> \u2192 no optimizations will run on this URL.</div>'
                : '<div style="color:#dc2626;margin-top:6px;">\u2717 Not excluded from plugin (no matching pattern in <code>wpc-url-excludes</code>)</div>';
            html += c
                ? '<div style="color:#16a34a;margin-top:4px;">\u2713 Excluded from cache \u2014 matched: <code>' + wpcEsc(c) + '</code></div>'
                : '<div style="color:#6b7280;margin-top:4px;">\u2014 Not excluded from cache</div>';
            box.style.display = 'block';
            box.innerHTML = html;
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                btn.click();
            }
        });
    }
})();
</script>
<?php

echo '<details style="margin-top:14px;font-size:11px;color:#78350f;"><summary style="cursor:pointer;">If patterns ARE saved but page still shows optimizations, check this</summary>';
echo '<ol style="margin:8px 0;padding-left:20px;line-height:1.7;">';
echo '<li><strong>Click "Regenerate advanced-cache.php"</strong> above (if "has wildcard code" shows NO).</li>';
echo '<li><strong>Click "Purge ALL caches"</strong> — old optimized HTML may still be cached on disk/CDN.</li>';
echo '<li>If on Cloudflare → also purge from CF dashboard, or wait ~30s for edge to expire.</li>';
echo '<li>Hit the URL with a fresh browser (incognito) to bypass browser cache.</li>';
echo '<li>If still optimized → check the URL above with the live tester. If it says "Not excluded from plugin", your pattern doesn\'t actually match — adjust it.</li>';
echo '<li>If pattern matches but page is still optimized → there\'s a host-level cache (Cloudways Varnish, LiteSpeed, etc.) serving the old HTML. Purge from hosting panel.</li>';
echo '</ol>';
echo '</details>';

echo '</div>';

// Critical CSS debug transients removed in 7.00.05 — disk scanner below is retained for support

// ─── Critical CSS on-disk scanner ─────────────────────
$critDir = defined('WPS_IC_CRITICAL') ? WPS_IC_CRITICAL : WP_CONTENT_DIR . '/cache/critical/';
if (is_dir($critDir)) {
    $dirs = glob($critDir . '*/critical_desktop.css');
    echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:16px 20px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
    echo '<strong style="font-size:14px;">Critical CSS Files on Disk (' . count($dirs) . ' pages)</strong><br><br>';
    if (empty($dirs)) {
        echo '<em>No critical CSS files found on disk.</em>';
    } else {
        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<tr style="text-align:left;border-bottom:1px solid #d1fae5;"><th style="padding:4px 8px;">Page Key</th><th style="padding:4px 8px;">Desktop</th><th style="padding:4px 8px;">Mobile</th></tr>';
        foreach ($dirs as $file) {
            $dirName = basename(dirname($file));
            $desktopSize = file_exists($file) ? round(filesize($file) / 1024, 1) . 'KB' : '—';
            $mobileFile = dirname($file) . '/critical_mobile.css';
            $mobileSize = file_exists($mobileFile) ? round(filesize($mobileFile) / 1024, 1) . 'KB' : '—';
            echo '<tr style="border-bottom:1px solid #f0fdf4;">';
            echo '<td style="padding:4px 8px;max-width:400px;overflow:hidden;text-overflow:ellipsis;">' . esc_html($dirName) . '</td>';
            echo '<td style="padding:4px 8px;color:#16a34a;">' . $desktopSize . '</td>';
            echo '<td style="padding:4px 8px;color:#16a34a;">' . $mobileSize . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
} else {
    echo '<div style="background:#fef2f2;border:1px solid #fecaca;padding:12px 16px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
    echo '<strong>Critical CSS directory does not exist:</strong> ' . esc_html($critDir);
    echo '</div>';
}

// ─── 7.01.0 Modern Image Delivery Status Panel ────────────────────────────
$wpc_settings = get_option(WPS_IC_SETTINGS);
$modernOn = !empty($wpc_settings['modern_image_delivery']) && $wpc_settings['modern_image_delivery'] == '1';
global $wpdb;
$queuedCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpc_queued_%'");
$failedCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpc_failed_%'");
$attemptsCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpc_optimize_attempts'");
$queueOption = get_option('wpc_compress_queue', []);
$queueDepth = is_array($queueOption) ? count($queueOption) : 0;

$panelBg = $modernOn ? '#ecfdf5' : '#f9fafb';
$panelBorder = $modernOn ? '#bbf7d0' : '#e5e7eb';
$statusColor = $modernOn ? '#15803d' : '#6b7280';
$statusText = $modernOn ? 'ACTIVE — HTML emission via native <picture>' : 'OFF — legacy pipeline in use (default)';

echo '<div style="background:' . $panelBg . ';border:1px solid ' . $panelBorder . ';padding:16px 20px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
echo '<strong style="font-size:14px;color:#065f46;">7.01.0 Modern Image Delivery (BETA) Status</strong><br>';
echo '<div style="margin-top:8px;"><strong>Mode:</strong> <span style="color:' . $statusColor . ';font-weight:bold;">' . $statusText . '</span></div>';
echo '<div style="margin-top:4px;"><strong>Lazy-gen queue depth:</strong> ' . $queueDepth . ' attachment(s) awaiting worker</div>';
echo '<div><strong>Concurrent-visitor dedup transients:</strong> ' . $queuedCount . ' active (30-min window)</div>';
echo '<div><strong>Failed cooldowns:</strong> ' . $failedCount . ' attachment(s) in 24h cooldown (3-strike ceiling)</div>';
echo '<div><strong>Attachments with retry counter:</strong> ' . $attemptsCount . ' (cleared on successful compress)</div>';
echo '<div style="margin-top:8px;font-size:11px;color:#065f46;">Zero-JS image delivery. Toggle in Settings &rsaquo; Image Optimization &rsaquo; Optimize Images. Default OFF on upgrade.</div>';
echo '</div>';

// ─── Purge Debug Log (stored in DB, works on all hosts) ─────────────────────
$purgeLog = get_option('wpc_purge_debug_log', []);
echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px 20px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
echo '<strong style="font-size:14px;">Purge Debug Log (last 20 events)</strong><br>';
echo '<div style="color:#1e40af;font-size:11px;margin:4px 0 8px 0;line-height:1.5;">';
echo '<strong>Note:</strong> WP Engine and CloudFlare-backed CDNs take <strong>30&ndash;60 seconds</strong> to propagate purges at the edge. If HTML still looks stale right after saving settings, wait ~1 minute and retest, or append a cache-busting query string (e.g. <code>?v=123</code>) to bypass the edge cache entirely.';
echo '</div>';
if (empty($purgeLog)) {
    echo '<em>No purge events yet. Change a setting and save to see entries.</em>';
} else {
    echo '<div style="max-height:300px;overflow:auto;white-space:pre-wrap;line-height:1.8;">';
    foreach (array_reverse($purgeLog) as $line) {
        $color = '#334155';
        if (strpos($line, 'html=YES') !== false || strpos($line, 'HTML purge') !== false) $color = '#2563eb';
        if (strpos($line, 'WPE=YES') !== false) $color = '#16a34a';
        if (strpos($line, 'html=NO') !== false) $color = '#f59e0b';
        echo '<div style="color:' . $color . ';">' . esc_html($line) . '</div>';
    }
    echo '</div>';
}
echo '</div>';

// ─── 7.00.08 Diagnostic Log (LCP BETA + cookie-plugin interactions + vars preservation) ─────────────────────
// Clear action
if (isset($_GET['wpc_clear_diagnostic_log']) && current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wpc_clear_diagnostic_log')) {
    delete_option('wpc_diagnostic_log');
    echo '<div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:8px 12px;border-radius:6px;margin:10px 0;font-size:12px;">Diagnostic log cleared.</div>';
}

$diagLog = get_option('wpc_diagnostic_log', []);
if (!is_array($diagLog)) $diagLog = [];

// Summarize by tag so users see what's active at a glance
$tagCounts = [];
foreach ($diagLog as $line) {
    if (preg_match('/\| ([A-Z_]+) \|/', $line, $m)) {
        $tagCounts[$m[1]] = ($tagCounts[$m[1]] ?? 0) + 1;
    }
}

echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px 20px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
echo '<strong style="font-size:14px;color:#1e40af;">7.00.08 Tracking Log (last 100 events)</strong>';
echo ' <a href="' . esc_url(add_query_arg(['wpc_clear_diagnostic_log' => '1', '_wpnonce' => wp_create_nonce('wpc_clear_diagnostic_log')])) . '" style="margin-left:12px;font-size:11px;color:#dc2626;">Clear Log</a>';
echo '<br>';
echo '<div style="color:#1e3a8a;font-size:11px;margin-top:2px;">Records when new 7.00.08 features fire: LCP BETA srcset generation, cookie-plugin detection, WPC inline-vars preservation. Browse the frontend to collect entries.</div>';
echo '<br>';

// Legend showing which features are actually firing
echo '<div style="background:#fff;border:1px solid #dbeafe;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-size:11px;">';
$legend = [
    'LCP_BETA' => ['Optimize LCP Images (BETA) — device-independent srcset stamped on a lazy-skipped image', $tagCounts['LCP_BETA'] ?? 0],
    'COOKIE_PLUGIN_DETECTED' => ['Cookie-consent plugin detected — jQuery ecosystem excluded from delay', $tagCounts['COOKIE_PLUGIN_DETECTED'] ?? 0],
    'VARS_PRESERVED' => ['WPC inline vars (wp_localize_script output) preserved from delay — script runs at parse time', $tagCounts['VARS_PRESERVED'] ?? 0],
    'DELAY_EXCLUDE_JQ' => ['jQuery/WooCommerce/blockUI script excluded from delay — avoids jQuery-is-not-defined race', $tagCounts['DELAY_EXCLUDE_JQ'] ?? 0],
    // 7.01.0 Modern Image Delivery events
    'QUEUED_LAZY_GEN' => ['Modern Image Delivery queued lazy-gen for an attachment with missing variants', $tagCounts['QUEUED_LAZY_GEN'] ?? 0],
    'DOWNLOAD_FAIL' => ['Variant download failed — retry counter incremented (3 strikes = 24h cooldown)', $tagCounts['DOWNLOAD_FAIL'] ?? 0],
    'RETRY_CEILING_HIT' => ['Attachment hit 3-attempt ceiling — permanent-fail cooldown for 24h', $tagCounts['RETRY_CEILING_HIT'] ?? 0],
    'FILENAME_MISMATCH' => ['Local MC returned variant with unexpected filename — write aborted (L1)', $tagCounts['FILENAME_MISMATCH'] ?? 0],
];
foreach ($legend as $tag => [$desc, $count]) {
    $color = $count > 0 ? '#16a34a' : '#6b7280';
    $badge = $count > 0 ? '✓' : '○';
    echo '<div style="padding:2px 0;color:' . $color . ';">' . $badge . ' <strong>' . $tag . '</strong> (' . $count . ')&nbsp;— ' . esc_html($desc) . '</div>';
}
echo '</div>';

if (empty($diagLog)) {
    echo '<em style="color:#6b7280;">No events captured yet. Visit the frontend (or toggle Optimize LCP Images BETA and reload) to populate this log.</em>';
} else {
    echo '<div style="max-height:400px;overflow:auto;white-space:pre-wrap;line-height:1.8;">';
    foreach (array_reverse($diagLog) as $line) {
        $color = '#334155';
        if (strpos($line, 'LCP_BETA') !== false) $color = '#0891b2';
        if (strpos($line, 'COOKIE_PLUGIN_DETECTED') !== false) $color = '#ca8a04';
        if (strpos($line, 'VARS_PRESERVED') !== false) $color = '#16a34a';
        if (strpos($line, 'DELAY_EXCLUDE_JQ') !== false) $color = '#2563eb';
        if (strpos($line, 'QUEUED_LAZY_GEN') !== false) $color = '#7c3aed';
        if (strpos($line, 'DOWNLOAD_FAIL') !== false) $color = '#dc2626';
        if (strpos($line, 'RETRY_CEILING_HIT') !== false) $color = '#b91c1c';
        if (strpos($line, 'FILENAME_MISMATCH') !== false) $color = '#a16207';
        echo '<div style="color:' . $color . ';border-bottom:1px solid #eff6ff;padding:2px 0;">' . esc_html($line) . '</div>';
    }
    echo '</div>';
}
echo '</div>';

// ─── PHP Error Log (WPC errors only) ─────────────────────
$errorLog = get_option('wpc_error_debug_log', []);
echo '<div style="background:#fef2f2;border:1px solid #fecaca;padding:16px 20px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
echo '<strong style="font-size:14px;">PHP Errors (last 50, WPC files only)</strong>';
echo ' <a href="' . esc_url(admin_url('admin-post.php?action=wpc_clear_error_log&_wpnonce=' . wp_create_nonce('wpc_clear_error_log'))) . '" style="margin-left:12px;font-size:11px;color:#dc2626;">Clear Log</a>';
echo '<br><br>';
if (empty($errorLog)) {
    echo '<em style="color:#16a34a;">No PHP errors captured. Browse the site to trigger error collection.</em>';
} else {
    echo '<div style="max-height:400px;overflow:auto;white-space:pre-wrap;line-height:1.8;">';
    foreach (array_reverse($errorLog) as $line) {
        $color = '#991b1b';
        if (strpos($line, 'NOTICE') !== false) $color = '#92400e';
        if (strpos($line, 'DEPRECATED') !== false) $color = '#6b21a8';
        echo '<div style="color:' . $color . ';border-bottom:1px solid #fee2e2;padding:2px 0;">' . esc_html($line) . '</div>';
    }
    echo '</div>';
}
echo '</div>';

// ─── Current optimization setting ─────────────────────
echo '<div style="background:#f5f3ff;border:1px solid #ddd6fe;padding:12px 16px;margin:10px 0;border-radius:8px;font-family:monospace;font-size:12px;">';
$_s = get_option(WPS_IC_SETTINGS);
echo '<strong>qualityLevel:</strong> ' . esc_html($_s['qualityLevel'] ?? 'not set');
echo ' &nbsp;|&nbsp; <strong>optimization:</strong> ' . esc_html($_s['optimization'] ?? 'not set');
echo ' &nbsp;|&nbsp; <strong>local_qualityLevel:</strong> ' . esc_html($_s['local_qualityLevel'] ?? 'not set');
echo ' &nbsp;|&nbsp; <strong>local_optimization:</strong> ' . esc_html($_s['local_optimization'] ?? 'not set');
echo '</div>';

if (!empty($_POST['wps_settings'])) {
    $settings = stripslashes($_POST['wps_settings']);
    $settings = json_decode($settings, true, JSON_UNESCAPED_SLASHES);
    if (is_array($settings)) {
        update_option(WPS_IC_SETTINGS, $settings);
    }
}

$settings = get_option(WPS_IC_SETTINGS);
if (!empty($_POST['cache_refresh_time'])) {
    $settings['cache_refresh_time'] = sanitize_text_field($_POST['cache_refresh_time']);
    update_option(WPS_IC_SETTINGS, $settings);
}

if (!isset($settings['cache_refresh_time'])) {
    $settings['cache_refresh_time'] = 60;
}

if (!empty($_GET['delete_option'])) {
    delete_option($_GET['delete_option']);
}

if (!empty($_GET['debug_img'])) {
    $imageID = $_GET['debug_img'];
    $debug = get_post_meta($imageID, 'ic_debug', true);
    if (!empty($debug)) {
        foreach ($debug as $i => $msg) {
            echo $msg . '<br/>';
        }
    }
    die();
}

if (!empty($_POST['elementor_skip_sections'])) {
	$skipSections = array(
		'desktop' => intval($_POST['elementor_skip_desktop']),
		'mobile' => intval($_POST['elementor_skip_mobile'])
	);
	update_option('wps_ic_elementor_skip_sections', $skipSections);
}

//list of api endpoints
$servers = ['auto' => 'Auto', 'vancouver.zapwp.net' => 'Canada', 'nyc.zapwp.net' => 'New York', 'la2.zapwp.net' => 'LA2', 'singapore.zapwp.net' => 'Singapore', 'dallas.zapwp.net' => 'Dallas', 'sydney.zapwp.net' => 'Sydney', 'india.zapwp.net' => 'India', 'frankfurt.zapwp.net' => 'Germany'];

if (!empty($_POST['local_server'])) {
    $local_server = $_POST['local_server'];
    update_option('wps_ic_force_local_server', $local_server);
} else {
    $local_server = get_option('wps_ic_force_local_server');
    if ($local_server === false || empty($local_server)) {
        $local_server = 'auto';
    }
}


if (isset($_POST['savePreloads'])) {
    if (empty($_POST['preloads'])) {
        $preloadsLcp = get_option('wps_ic_preloads', []);
        unset($preloadsLcp['custom']);
        update_option('wps_ic_preloads', $preloadsLcp);
    }

    if (empty($_POST['preloadsMobile'])) {
        $preloadsLcp = get_option('wps_ic_preloadsMobile', []);
        unset($preloadsLcp['custom']);
        update_option('wps_ic_preloadsMobile', $preloadsLcp);
    }

    if (empty($_POST['preloads_lcp'])) {
        $preloadsLcp = get_option('wps_ic_preloads', []);
        $preloadsLcp['lcp'] = '';
        update_option('wps_ic_preloads', $preloadsLcp);
    }

    if (empty($_POST['preloadsMobile_lcp'])) {
        $preloadsLcp = get_option('wps_ic_preloadsMobile', []);
        $preloadsLcp['lcp'] = '';
        update_option('wps_ic_preloadsMobile', $preloadsLcp);
    }

}

if (!empty($_POST['preloads_lcp'])) {
	$preloadsLcp = get_option('wps_ic_preloads', []);
	$preloadsLcp['lcp'] = [$_POST['preloads_lcp']]; // Wrap in array
	update_option('wps_ic_preloads', $preloadsLcp);
}

if (!empty($_POST['preloadsMobile_lcp'])) {
	$preloadsLcp = get_option('wps_ic_preloadsMobile', []);
	$preloadsLcp['lcp'] = [$_POST['preloadsMobile_lcp']]; // Wrap in array
	update_option('wps_ic_preloadsMobile', $preloadsLcp);
}

if (!empty($_POST['preloads'])) {
	$preloadsLcp = get_option('wps_ic_preloads', []);
	$preloadsArray = explode("\n", $_POST['preloads']);
	$preloadsArray = array_map('trim', $preloadsArray);
	$preloadsLcp['custom'] = $preloadsArray;
	update_option('wps_ic_preloads', $preloadsLcp);
}

$preloads = get_option('wps_ic_preloads');
if (!empty($_POST['preloadsMobile'])) {
	$preloadsLcp = get_option('wps_ic_preloadsMobile', []);
	$preloadsArray = explode("\n", $_POST['preloadsMobile']);
	$preloadsArray = array_map('trim', $preloadsArray);
	$preloadsLcp['custom'] = $preloadsArray;
	update_option('wps_ic_preloadsMobile', $preloadsLcp);
}

if (!empty($_POST['remove_fonts'])) {
    $removeFonts = [$_POST['remove_fonts']]; // Wrap in array
    update_option('wps_ic_remove_fonts', $removeFonts);
}

$preloadsMobile = get_option('wps_ic_preloadsMobile');
?>

<div style="display: none;" id="compress-test-results" class="ic-test-results">
    <textarea id="compress-test-results-textarea" style="visibility: hidden;opacity: none;"></textarea>
    <div class="results-inner">
        <span class="ic-terminal-dot blink"><span></span></span>
    </div>
    <a href="#" class="copy-debug"><?php esc_html_e('Copy Debug Results', WPS_IC_TEXTDOMAIN); ?></a>
</div>

<table id="information-table" class="wp-list-table widefat fixed striped posts">
    <thead>
    <tr>
        <th><?php esc_html_e('Check Name', WPS_IC_TEXTDOMAIN); ?></th>
        <th><?php esc_html_e('Value', WPS_IC_TEXTDOMAIN); ?></th>
        <th><?php esc_html_e('Status', WPS_IC_TEXTDOMAIN); ?></th>
        <th><?php esc_html_e('Action', WPS_IC_TEXTDOMAIN); ?></th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><?php esc_html_e('Use OLD Critical API', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['wps_ic_critical_mc'])) {
                    if ($_GET['wps_ic_critical_mc'] === 'true') {
                        $settings = get_option(WPS_IC_SETTINGS);
                        $settings['mcCriticalCSS'] = 'mc';
                        update_option(WPS_IC_SETTINGS, $settings);
                        #update_option('wps_ic_critical_mc', sanitize_text_field($_GET['wps_ic_critical_mc']));
                    } else {
                        $settings = get_option(WPS_IC_SETTINGS);
                        $settings['mcCriticalCSS'] = 'api';
                        update_option(WPS_IC_SETTINGS, $settings);
                        #delete_option('wps_ic_critical_mc');
                    }
                }

                $cdn_critical_mc = get_option(WPS_IC_SETTINGS);


                if (empty($settings['mcCriticalCSS']) || $settings['mcCriticalCSS'] == 'mc') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_critical_mc=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable Old API', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_critical_mc=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable New API', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('Enable Bunny Critical CSS API.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('New CDN API Test', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['wps_ic_cdn_mc'])) {
                    if ($_GET['wps_ic_cdn_mc'] === 'true') {
                        update_option('wps_ic_cdn_mc', sanitize_text_field($_GET['wps_ic_cdn_mc']));

                        $oldZone = get_option('ic_cdn_zone_name');
                        update_option('ic_cdn_zone_name_old', $oldZone);
                        update_option('ic_cdn_zone_name', 'mc-enutpvy18x.bunny.run');

                    } else {
                        $oldZone = get_option('ic_cdn_zone_name_old');
                        delete_option('ic_cdn_zone_name_old');
                        update_option('ic_cdn_zone_name', $oldZone);

                        delete_option('wps_ic_cdn_mc');
                    }
                }

                $cdn_mc = get_option('wps_ic_cdn_mc');

                if (empty($cdn_mc) || $cdn_mc == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_cdn_mc=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_cdn_mc=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('Enable Bunny MC API.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('New DelayJS DEBUG', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
					    <?php
					    if (!empty($_GET['wps_ic_delay_v2_debug'])) {
						    if ($_GET['wps_ic_delay_v2_debug'] === 'true') {
							    update_option('wps_ic_delay_v2_debug', sanitize_text_field($_GET['wps_ic_delay_v2_debug']));
						    } else {
							    delete_option('wps_ic_delay_v2_debug');
						    }
					    }

					    $v2_debug = get_option('wps_ic_delay_v2_debug');

					    if (empty($v2_debug) || $v2_debug == 'false') {
						    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_delay_v2_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
					    } else {
						    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_delay_v2_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
					    }
					    ?>
                <?php esc_html_e('Enable console log debug.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Remove OptimizeJS', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['optimizejs_remove'])) {
                    if ($_GET['optimizejs_remove'] === 'true') {
                        update_option('wps_optimizejs_remove', sanitize_text_field($_GET['optimizejs_remove']));
                    } else {
                        delete_option('wps_optimizejs_remove');
                    }
                }

                $optimizejs_remove = get_option('wps_optimizejs_remove');

                if (empty($optimizejs_remove) || $optimizejs_remove == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_remove=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_remove=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with optimize.js this will give you the debug version.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable OptimizeJS Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['optimizejs_debug'])) {
                    update_option('wps_optimizejs_debug', sanitize_text_field($_GET['optimizejs_debug']));
                }

                $optimizejs_debug = get_option('wps_optimizejs_debug');

                if (empty($optimizejs_debug) || $optimizejs_debug == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with optimize.js this will give you the debug version.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Debug Log', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['wps_ic_debug_log'])) {
                    update_option('wps_ic_debug_log', sanitize_text_field($_GET['wps_ic_debug_log']));
                }

                $development = get_option('wps_ic_debug_log');

                if (empty($development) || $development == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_debug_log=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_debug_log=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Plugin Development Mode', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['php_development'])) {
                    update_option('wps_ic_development', sanitize_text_field($_GET['php_development']));
                }

                $development = get_option('wps_ic_development');

                if (empty($development) || $development == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_development=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_development=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable Critical CSS Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['ccss_debug'])) {
                    update_option('wps_ccss_debug', sanitize_text_field($_GET['ccss_debug']));
                }

                $ccss_debug = get_option('ccss_debug');

                if (empty($ccss_debug) || $ccss_debug == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ccss_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ccss_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with critical CSS.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable PageSpeed & Critical Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['ps_debug'])) {
                    update_option('wps_ps_debug', sanitize_text_field($_GET['ps_debug']));
                }

                $debugPhp = get_option('wps_ps_debug');

                if (empty($debugPhp) || $debugPhp == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ps_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ps_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with our plugin, enabling this option will give you some basic debug output in Console log of your browser.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable PHP Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['php_debug'])) {
                    update_option('wps_ic_debug', sanitize_text_field($_GET['php_debug']));
                }

                $debugPhp = get_option('wps_ic_debug');

                if (empty($debugPhp) || $debugPhp == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with our plugin, enabling this option will give you some basic debug output in Console log of your browser.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable JavaScript Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['js_debug'])) {
                    update_option('wps_ic_js_debug', sanitize_text_field($_GET['js_debug']));
                }

                if (get_option('wps_ic_js_debug') == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&js_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&js_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with our plugin, enabling this option will give you some basic debug output in Console log of your browser.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>


    <tr>
        <td><?php esc_html_e('Site Url', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                echo esc_html__('Site URL:', WPS_IC_TEXTDOMAIN) . ' ' . site_url();
                ?>
            </p>
            <p>
                <?php
                echo esc_html__('Get site url:', WPS_IC_TEXTDOMAIN) . ' ' . get_site_url();
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Plugin Configuration', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $allowLive = get_option('wps_ic_allow_live');
                $allowLocal = get_option('wps_ic_allow_local');
                echo '<h3>' . esc_html__('Allow live:', WPS_IC_TEXTDOMAIN) . '</h3>' .$allowLive;
                echo '<h3>' . esc_html__('Allow local:', WPS_IC_TEXTDOMAIN) . '</h3>' .$allowLocal;
                echo '<h3>' . esc_html__('Account Status:', WPS_IC_TEXTDOMAIN) . '</h3>' . var_dump(get_transient('wps_ic_account_status'));
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Get JobID For Crit', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $jobID = get_transient(WPS_IC_JOB_TRANSIENT);
                var_dump($jobID);
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Generate Ajax Params', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $locate = get_option('wps_ic_geo_locate_v2');
                echo print_r($locate,true);
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Generate Ajax Params', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $parameters = get_option(WPS_IC_SETTINGS);
                $translatedParameters = [];
                if (isset($parameters['generate_webp'])) {
                    $translatedParameters['webp'] = $parameters['generate_webp'];
                }

                if (isset($parameters['retina'])) {
                    $translatedParameters['retina'] = $parameters['retina'];
                }

                if (isset($parameters['qualityLevel'])) {
                    $translatedParameters['quality'] = $parameters['qualityLevel'];
                }

                if (isset($parameters['preserve_exif'])) {
                    $translatedParameters['exif'] = $parameters['preserve_exif'];
                }

                if (isset($parameters['max_width'])) {
                    $translatedParameters['max_width'] = $parameters['max_width'];
                } else {
                    $translatedParameters['max_width'] = WPS_IC_MAXWIDTH;
                }

                echo json_encode($translatedParameters);
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Thumbnails', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $sizes = get_intermediate_image_sizes();
            echo sprintf(esc_html__('Total Thumbs: %d', WPS_IC_TEXTDOMAIN), count($sizes));
            echo print_r($sizes, true);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Paths', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            echo esc_html__('Debug Log:', WPS_IC_TEXTDOMAIN) . ' ' . WPS_IC_LOG . 'debug-log-' . date('d-m-Y') . '.txt';
            echo '<br/>' . esc_html__('Debug Log URI:', WPS_IC_TEXTDOMAIN) . ' <a href="' . WPS_IC_URI . 'debug-log-' . date('d-m-Y') . '.txt">' . WPS_IC_URI . 'debug-log-' . date('d-m-Y') . '.txt' . '</a>';
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Excluded List', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $excluded = get_option('wps_ic_exclude_list');
            echo print_r($excluded, true);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('API Key', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $options = get_option(WPS_IC_OPTIONS);
            echo esc_html($options['api_key']);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('CDN Zone Name', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            echo esc_html(get_option('ic_cdn_zone_name'));
            ?>
        </td>
        <td>
            <a href="<?php
            echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=debug_tool&delete_option=ic_cdn_zone_name'); ?>"><?php esc_html_e('Delete', WPS_IC_TEXTDOMAIN); ?></a>
        </td>
        <td></td>
    </tr>
    <tr>
        <td><?php esc_html_e('Custom CDN Zone Name', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            echo esc_html(get_option('ic_custom_cname'));
            ?>
        </td>
        <td>
            <a href="<?php
            echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=debug_tool&delete_option=ic_custom_cname'); ?>"><?php esc_html_e('Delete', WPS_IC_TEXTDOMAIN); ?></a>
        </td>
        <td></td>
    </tr>

    <tr>
        <td><?php esc_html_e('Plugin Activated', WPS_IC_TEXTDOMAIN); ?></td>
        <td><?php
            if (is_plugin_active('wp-compress-image-optimizer/wp-compress.php')) {
                echo 'Yes';
                $status = 'OK';
            } else {
                echo 'No';
                $status = 'BAD';
            }
            ?></td>
        <td><?php
            echo $status; ?></td>
        <td><?php esc_html_e('None', WPS_IC_TEXTDOMAIN); ?></td>
    </tr>
    <tr>
        <td><?php esc_html_e('PHP Version', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            $version = phpversion();
            echo $version;
            if (version_compare($version, '7.0', '>=')) {
                $status = 'OK';
            } else {
                $status = 'BAD';
            }
            ?>
        </td>
        <td><?php
            echo $status; ?></td>
        <td><?php esc_html_e('None', WPS_IC_TEXTDOMAIN); ?></td>
    </tr>
    <tr>
        <td><?php esc_html_e('WP Version', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            $wp_version = get_bloginfo('version');
            echo $wp_version;
            if (version_compare($wp_version, '5.0', '>=')) {
                $status = 'OK';
            } else {
                $status = 'BAD';
            }
            ?>
        </td>
        <td>
            <?php
            echo $status;
            ?>
        </td>
        <td>
            <?php esc_html_e('None', WPS_IC_TEXTDOMAIN); ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Options', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="wps_copy_button button-primary" data-field="options" style="float:right"><?php esc_html_e('Copy text', WPS_IC_TEXTDOMAIN); ?></button>
            <textarea id="wps_options_field" style="width:100%"><?php
                echo json_encode(get_option(WPS_IC_OPTIONS));
                ?>
          </textarea>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Settings', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="wps_copy_button button-primary" data-field="settings" style="float:right"><?php esc_html_e('Copy text', WPS_IC_TEXTDOMAIN); ?></button>
            <?php
            echo json_encode(get_option(WPS_IC_SETTINGS));
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Delivery Diagnostic', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="wps_copy_button button-primary" data-field="delivery" style="float:right"><?php esc_html_e('Copy text', WPS_IC_TEXTDOMAIN); ?></button>
            <?php
            // WPC delivery-resolver diagnostic (v7.03.36). Read-only by default (cached verdict + the recorded
            // verify detail + the forcing gates). Append &wpc_dprobe=1 to THIS page's URL to ALSO run the live
            // origin->edge loopback probe + a forced re-verify (makes outbound HTTP; otherwise nothing here does).
            if (!class_exists('WPC_Delivery_Resolver') && defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'addons/cdn/delivery-resolver.php')) {
                @require_once WPS_IC_DIR . 'addons/cdn/delivery-resolver.php';
            }
            if (!class_exists('WPC_Negotiated_Delivery') && defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php')) {
                @require_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
            }
            $wpc_dd   = ['resolver_loaded' => class_exists('WPC_Delivery_Resolver')];
            $wpc_dd['wpc_delivery_state']    = get_option('wpc_delivery_state', null);     // cached verdict + verify.cdn.detail/error/fails
            $wpc_dd['wpc_delivery_override'] = get_option('wpc_delivery_override', null);
            $wpc_dd_s = get_option(WPS_IC_SETTINGS);
            $wpc_dd_g = function ($k) use ($wpc_dd_s) { return (is_array($wpc_dd_s) && isset($wpc_dd_s[$k])) ? $wpc_dd_s[$k] : '(unset)'; };
            $wpc_dd['gates'] = [
                'live-cdn' => $wpc_dd_g('live-cdn'), 'cdnAll' => $wpc_dd_g('cdnAll'),
                'picture_webp' => $wpc_dd_g('picture_webp'), 'picture_avif' => $wpc_dd_g('picture_avif'),
                'generate_webp' => $wpc_dd_g('generate_webp'), 'generate_adaptive' => $wpc_dd_g('generate_adaptive'),
                'wpc_optimization_mode' => $wpc_dd_g('wpc_optimization_mode'),
                'single-url-image-format' => $wpc_dd_g('single-url-image-format'),
                'zone_id' => get_option('wpc_v2_zone_id', '(unset)'),
            ];
            if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'pick_test_image')) {
                $wpc_dd['pick_test_image'] = WPC_Delivery_Resolver::pick_test_image();
            }
            if (!empty($_GET['wpc_dprobe']) && class_exists('WPC_Delivery_Resolver')) {
                if (method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
                    $wpc_dd['forced_reverify'] = WPC_Delivery_Resolver::resolve_verbose(true);
                }
                $wpc_dd_t   = isset($wpc_dd['pick_test_image']) ? $wpc_dd['pick_test_image'] : null;
                $wpc_dd_url = (is_array($wpc_dd_t) && !empty($wpc_dd_t['cdn_webp_url'])) ? (string) $wpc_dd_t['cdn_webp_url'] : '';
                if ($wpc_dd_url !== '' && method_exists('WPC_Delivery_Resolver', 'probe')) {
                    $wpc_dd_pr = [];
                    foreach (['avif' => 'image/avif,image/webp,*/*', 'webp' => 'image/webp,*/*', 'legacy' => 'image/*,*/*'] as $wpc_dd_c => $wpc_dd_a) {
                        $wpc_dd_t0 = microtime(true);
                        $wpc_dd_p  = WPC_Delivery_Resolver::probe($wpc_dd_url, $wpc_dd_a);
                        if (is_array($wpc_dd_p)) { $wpc_dd_p['_ms'] = (int) round((microtime(true) - $wpc_dd_t0) * 1000); unset($wpc_dd_p['body']); }
                        $wpc_dd_pr[$wpc_dd_c] = $wpc_dd_p;
                    }
                    $wpc_dd['live_loopback_probes'] = $wpc_dd_pr;
                    if (method_exists('WPC_Delivery_Resolver', 'evaluate_cdn_probes')) {
                        $wpc_dd['live_evaluate'] = WPC_Delivery_Resolver::evaluate_cdn_probes($wpc_dd_pr);
                    }
                }
            } else {
                $wpc_dd['_hint'] = 'Append &wpc_dprobe=1 to this page URL, reload, and re-open this tab to ALSO run the live origin->edge loopback probe + a forced re-verify.';
            }
            ?>
            <textarea id="wps_delivery_field" style="width:100%;min-height:340px;font-family:monospace"><?php echo esc_textarea(wp_json_encode($wpc_dd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Local server API', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <label for="server"><?php esc_html_e('Server:', WPS_IC_TEXTDOMAIN); ?></label>
                <select id="server" name="local_server">
                    <?php
                    foreach ($servers as $value => $label) {
                        $selected = ($local_server == $value) ? 'selected' : '';
                        echo '<option value="' . $value . '" ' . $selected . '>' . $label . '</option>';
                    }
                    ?>
                </select>
                <input type="submit" value="<?php esc_attr_e('Save Server', WPS_IC_TEXTDOMAIN); ?>" class="button-primary" style="float:right">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Preloads Debug - Last Warmup', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $lastLog = get_option('wps_ic_last_warmpup');
            echo print_r($lastLog,true);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Preloads Desktop', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <h3><?php esc_html_e('Automatic Preloads found by API (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloads_lcp" style="width:100%;height:150px;"><?php
                    if (!empty($preloads['lcp'])) {
                        echo implode("\n", $preloads['lcp']);
                    }
                    ?></textarea>
                <h3><?php esc_html_e('Manual Desktop Preloads (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloads" style="width:100%;height:150px;"><?php
                    if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                        echo implode("\n", $preloads['custom']);
                    }
                    ?></textarea>

                <h3><?php esc_html_e('Automatic Mobile Preloads found by API (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloadsMobile_lcp" style="width:100%;height:150px;"><?php
                if (!empty($preloadsMobile['lcp'])) {
                    echo implode("\n", $preloadsMobile['lcp']);
                }
                    ?></textarea>
                <h3><?php esc_html_e('Manual Mobile Preloads (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloadsMobile" style="width:100%;height:150px;"><?php
                    if (!empty($preloadsMobile['custom']) && is_array($preloadsMobile['custom'])) {
                        echo implode("\n", $preloadsMobile['custom']);
                    }
                    ?></textarea>
                <input type="submit" value="<?php esc_attr_e('Save Preloads', WPS_IC_TEXTDOMAIN); ?>" name="savePreloads" class="button-primary"
                       style="float:right">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Cache refresh time (minutes)', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <input type="text" name="cache_refresh_time" value="<?php echo
                $settings['cache_refresh_time']; ?>">
                <input type="submit" value="<?php esc_attr_e('Save cache refresh', WPS_IC_TEXTDOMAIN); ?>" name="save" class="button-primary"
                       style="float:right">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Elementor Skip Sections', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
			    <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <p><?php esc_html_e('Configure how many Elementor sections to skip before applying lazy loading/optimization.', WPS_IC_TEXTDOMAIN); ?></p>

			    <?php $skipSections = get_option('wps_ic_elementor_skip_sections', []); ?>

                <label for="elementor_skip_desktop"><?php esc_html_e('Desktop Skip Count:', WPS_IC_TEXTDOMAIN); ?></label>
                <input type="number" id="elementor_skip_desktop" name="elementor_skip_desktop"
                       value="<?php echo isset($skipSections['desktop']) ? $skipSections['desktop'] : 5; ?>"
                       min="0" max="20" style="width: 80px;">


                <label for="elementor_skip_mobile"><?php esc_html_e('Mobile Skip Count:', WPS_IC_TEXTDOMAIN); ?></label>
                <input type="number" id="elementor_skip_mobile" name="elementor_skip_mobile"
                       value="<?php echo isset($skipSections['mobile']) ? $skipSections['mobile'] : 5; ?>"
                       min="0" max="20" style="width: 80px;">

                <input type="submit" name="elementor_skip_sections" value="<?php esc_attr_e('Save Skip Settings', WPS_IC_TEXTDOMAIN); ?>" class="button-primary" style="float:right;">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Fonts', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
			    <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce');
                $gui = new wpc_gui_v4();
                echo $gui->font_dropdown('Fonts', 'Description');
                ?>
                <input type="submit" name="fonts" value="<?php esc_attr_e('Save Fonts', WPS_IC_TEXTDOMAIN); ?>" class="button-primary" style="float:right;">
            </form>
        </td>
    </tr>
    <tr>
    <tr>
        <td><?php esc_html_e('Remove fonts from critical', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <textarea name="remove_fonts" style="width:100%;height:150px;"><?php
                    $removeFonts = get_option('wps_ic_remove_fonts', []);
                    echo implode("\n", $removeFonts);
                    ?></textarea>
                <input type="submit" value="<?php esc_attr_e('Save', WPS_IC_TEXTDOMAIN); ?>" class="button-primary"
                       style="float:right">
            </form>
        </td>
    </tr>
    </tbody>
</table>


<?php
// ─── Pipeline Overview ────────────────────────────────────────────────
// 7.01.7 — at-a-glance side-by-side comparison of all three pipelines (compress,
// restore, ladder backfill). Per-pipeline deep-dive panels remain below for
// source attribution + recent samples + raw event log.
$wpcOverviewStats = [
    'compress' => get_option('wpc_compress_stats', []),
    'restore'  => get_option('wpc_restore_stats',  []),
    'ladder'   => get_option('wpc_ladder_stats',   []),
];
$wpcOverviewMeta = [
    'compress' => ['label' => 'Compress',         'fired' => 'total_compresses_fired',  'success' => 'total_compresses_succeeded',  'last' => 'last_compress_at',  'p95' => 'wpc_compress_stats_p95'],
    'restore'  => ['label' => 'Restore',          'fired' => 'total_restores_fired',    'success' => 'total_restores_succeeded',    'last' => 'last_restore_at',   'p95' => 'wpc_restore_stats_p95'],
    'ladder'   => ['label' => 'Ladder backfill',  'fired' => 'total_backfills_fired',   'success' => 'total_backfills_succeeded',   'last' => 'last_backfill_at',  'p95' => 'wpc_ladder_stats_p95'],
];
?>
<h2>Pipeline Overview</h2>
<table class="wp-list-table widefat fixed striped" style="max-width:880px;">
    <thead>
        <tr>
            <th style="width:25%;">Pipeline</th>
            <th style="width:15%;">Fired</th>
            <th style="width:18%;">Success rate</th>
            <th style="width:18%;">p95 (ms)</th>
            <th style="width:24%;">Last activity</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($wpcOverviewMeta as $key => $meta): ?>
            <?php
                $stats   = $wpcOverviewStats[$key];
                $fleet   = isset($stats['fleet']) && is_array($stats['fleet']) ? $stats['fleet'] : [];
                $fired   = (int) ($fleet[$meta['fired']]   ?? 0);
                $success = (int) ($fleet[$meta['success']] ?? 0);
                $rate    = $fired > 0 ? round(($success / $fired) * 100, 1) : 0;
                $p95     = function_exists($meta['p95']) ? (int) call_user_func($meta['p95'], $stats) : 0;
                $last_ts = (int) ($fleet[$meta['last']] ?? 0);
                $last_ago = $last_ts > 0 ? human_time_diff($last_ts) . ' ago' : '—';
                $rate_color = $fired === 0 ? '#888' : ($rate >= 95 ? '#22b73a' : ($rate >= 80 ? '#fbae40' : '#ef5a5a'));
            ?>
            <tr>
                <td><strong><?php echo esc_html($meta['label']); ?></strong></td>
                <td><?php echo number_format_i18n($fired); ?></td>
                <td>
                    <?php if ($fired > 0): ?>
                        <span style="color:<?php echo esc_attr($rate_color); ?>;font-weight:600;"><?php echo esc_html($rate); ?>%</span>
                        <span style="color:#888;font-size:11px;">(<?php echo number_format_i18n($success); ?>/<?php echo number_format_i18n($fired); ?>)</span>
                    <?php else: ?>
                        <span style="color:#888;">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $p95 > 0 ? number_format_i18n($p95) . ' ms' : '<span style="color:#888;">—</span>'; ?></td>
                <td><?php echo esc_html($last_ago); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
// ─── Modern Delivery Ladder Status ────────────────────────────────────
// Telemetry for the Phase 1 lazy-fill + multi-layer trigger worker.
// Shows queue depth, cumulative stats, trigger attribution, p95 timing, and the recent event ring buffer.
$wpcLadderStats = get_option('wpc_ladder_stats', []);
$wpcLadderQueue = get_option('wpc_ladder_gen_queue', []);
if (!is_array($wpcLadderQueue)) $wpcLadderQueue = [];
$wpcLadderGenLog = get_option('wpc_variant_gen_log', []);
if (!is_array($wpcLadderGenLog)) $wpcLadderGenLog = [];
$wpcLadderPrewarm = get_option('wpc_prewarm_status', []);
if (!is_array($wpcLadderPrewarm)) $wpcLadderPrewarm = [];

$wpcLadderFleet    = isset($wpcLadderStats['fleet']) && is_array($wpcLadderStats['fleet']) ? $wpcLadderStats['fleet'] : [];
$wpcLadderTiming   = isset($wpcLadderStats['timing']) && is_array($wpcLadderStats['timing']) ? $wpcLadderStats['timing'] : [];
$wpcLadderQueueStat = isset($wpcLadderStats['queue']) && is_array($wpcLadderStats['queue']) ? $wpcLadderStats['queue'] : [];
$wpcLadderTriggers = isset($wpcLadderStats['triggers']) && is_array($wpcLadderStats['triggers']) ? $wpcLadderStats['triggers'] : [];

$wpcLadderSamples = (int) ($wpcLadderTiming['samples'] ?? 0);
$wpcLadderAvgMs   = $wpcLadderSamples > 0 ? (int) round(($wpcLadderTiming['sum_ms'] ?? 0) / $wpcLadderSamples) : 0;
$wpcLadderP95Ms   = function_exists('wpc_ladder_stats_p95') ? wpc_ladder_stats_p95($wpcLadderStats) : 0;
$wpcLadderMaxMs   = (int) ($wpcLadderTiming['max_ms'] ?? 0);

$wpcLadderTotalFired     = (int) ($wpcLadderFleet['total_backfills_fired'] ?? 0);
$wpcLadderTotalSucceeded = (int) ($wpcLadderFleet['total_backfills_succeeded'] ?? 0);
$wpcLadderTotalFailed    = (int) ($wpcLadderFleet['total_backfills_failed'] ?? 0);
?>
<h2 style="margin-top:40px;">Modern Delivery Ladder Status</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
    <tr>
        <th style="width:260px;">Metric</th>
        <th>Value</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>Current queue depth</strong></td>
        <td><?php echo count($wpcLadderQueue); ?> attachment(s) — <?php echo get_option('wpc_ladder_gen_queue_has_items') ? 'worker has work' : 'idle'; ?></td>
    </tr>
    <tr>
        <td><strong>Max queue depth ever</strong></td>
        <td>
            <?php echo (int) ($wpcLadderQueueStat['max_depth_ever'] ?? 0); ?>
            <?php if (!empty($wpcLadderQueueStat['max_depth_at'])): ?>
                (last reached <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcLadderQueueStat['max_depth_at'])); ?> UTC)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Backfills fired</strong></td>
        <td>
            <?php echo $wpcLadderTotalFired; ?> total
            — <span style="color:#2a7a2a;"><?php echo $wpcLadderTotalSucceeded; ?> succeeded</span>
            / <span style="color:#a00;"><?php echo $wpcLadderTotalFailed; ?> failed</span>
        </td>
    </tr>
    <tr>
        <td><strong>Variants generated</strong></td>
        <td>
            <?php echo (int) ($wpcLadderFleet['total_variants_avif'] ?? 0); ?> AVIF
            + <?php echo (int) ($wpcLadderFleet['total_variants_webp'] ?? 0); ?> WebP
            <?php if (!empty($wpcLadderFleet['total_variants_jpg'])): ?>
                + <?php echo (int) $wpcLadderFleet['total_variants_jpg']; ?> JPG
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Backfill duration</strong></td>
        <td>
            avg <?php echo $wpcLadderAvgMs; ?>ms
            · p95 <?php echo $wpcLadderP95Ms; ?>ms
            · max <?php echo $wpcLadderMaxMs; ?>ms
            <?php if ($wpcLadderSamples > 0): ?>
                (<?php echo $wpcLadderSamples; ?> samples)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Trigger attribution</strong></td>
        <td>
            <?php
            $triggerOrder = ['loopback', 'shutdown', 'admin', 'cron', 'manual', 'prewarm', 'cli-force', 'unknown'];
            $parts = [];
            foreach ($triggerOrder as $trig) {
                $count = (int) ($wpcLadderTriggers[$trig] ?? 0);
                if ($count > 0) $parts[] = $trig . ' <strong>' . $count . '</strong>';
            }
            echo $parts ? implode(' · ', $parts) : '<em>no fires yet</em>';
            ?>
        </td>
    </tr>
    <tr>
        <td><strong>Last backfill</strong></td>
        <td>
            <?php if (!empty($wpcLadderFleet['last_backfill_at'])): ?>
                <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcLadderFleet['last_backfill_at'])); ?> UTC
                (<?php echo human_time_diff((int) $wpcLadderFleet['last_backfill_at'], time()); ?> ago)
            <?php else: ?>
                <em>never fired</em>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Activation pre-warm</strong></td>
        <td>
            <?php if (!empty($wpcLadderPrewarm['state'])): ?>
                state=<?php echo esc_html($wpcLadderPrewarm['state']); ?>
                · prewarmed=<?php echo (int) ($wpcLadderPrewarm['prewarmed'] ?? 0); ?>
                <?php if (!empty($wpcLadderPrewarm['completed_at'])): ?>
                    · done <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcLadderPrewarm['completed_at'])); ?> UTC
                <?php endif; ?>
                <?php if (!empty($wpcLadderPrewarm['failed_pages'])): ?>
                    · failed_pages=<?php echo (int) $wpcLadderPrewarm['failed_pages']; ?>
                <?php endif; ?>
            <?php else: ?>
                <em>not yet fired</em>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>

<?php if (!empty($wpcLadderQueue)): ?>
    <h3 style="margin-top:30px;">Current queue (<?php echo count($wpcLadderQueue); ?>)</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th style="width:120px;">Attachment ID</th><th>Widths pending</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($wpcLadderQueue, 0, 25, true) as $aid => $widths): ?>
            <tr>
                <td><?php echo (int) $aid; ?></td>
                <td><?php echo esc_html(implode(', ', array_map('intval', (array) $widths))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($wpcLadderQueue) > 25): ?>
        <p><em>… and <?php echo count($wpcLadderQueue) - 25; ?> more. Showing first 25.</em></p>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($wpcLadderGenLog)): ?>
    <h3 style="margin-top:30px;">Recent backfill events (last 10)</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th style="width:150px;">Time (UTC)</th>
            <th style="width:90px;">Attachment</th>
            <th>Widths</th>
            <th style="width:110px;">Formats</th>
            <th style="width:90px;">Duration</th>
            <th style="width:90px;">Trigger</th>
            <th style="width:70px;">OK?</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($wpcLadderGenLog, -10) as $entry): ?>
            <?php
            $widthsDisplay = '';
            if (!empty($entry['widths_delivered']) && is_array($entry['widths_delivered'])) {
                $widthsDisplay = implode(', ', array_map('intval', $entry['widths_delivered']));
            } elseif (is_array($entry['w'] ?? null)) {
                $widthsDisplay = implode(', ', array_map('intval', $entry['w']));
            } elseif (!empty($entry['w'])) {
                $widthsDisplay = (string) (int) $entry['w'];
            }

            $formatsDisplay = '';
            if (!empty($entry['formats_delivered']) && is_array($entry['formats_delivered'])) {
                $fmtParts = [];
                foreach ($entry['formats_delivered'] as $fmt => $cnt) {
                    if ((int) $cnt > 0) $fmtParts[] = $fmt . '×' . (int) $cnt;
                }
                $formatsDisplay = implode(' ', $fmtParts);
            } elseif (!empty($entry['f'])) {
                $formatsDisplay = is_array($entry['f']) ? implode(',', $entry['f']) : (string) $entry['f'];
            }

            $dur = isset($entry['duration_ms']) ? ((int) $entry['duration_ms']) . 'ms' : '—';
            $trg = isset($entry['trigger_source']) ? (string) $entry['trigger_source'] : (isset($entry['ctx']) ? (string) $entry['ctx'] : '—');
            $ok  = !isset($entry['success']) ? '—' : ($entry['success'] ? '✓' : '✗');
            ?>
            <tr>
                <td><?php echo esc_html(date('Y-m-d H:i:s', (int) ($entry['t'] ?? 0))); ?></td>
                <td>#<?php echo (int) ($entry['aid'] ?? 0); ?></td>
                <td><?php echo esc_html($widthsDisplay); ?></td>
                <td><?php echo esc_html($formatsDisplay); ?></td>
                <td><?php echo esc_html($dur); ?></td>
                <td><?php echo esc_html($trg); ?></td>
                <td><?php echo $ok; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
// ─── Compress Status ───────────────────────────────────────────────────
// End-to-end singleCompressV4 timings with source attribution.
$wpcCompressStats = get_option('wpc_compress_stats', []);
if (!is_array($wpcCompressStats)) $wpcCompressStats = [];
$wpcCompressFleet   = isset($wpcCompressStats['fleet']) && is_array($wpcCompressStats['fleet']) ? $wpcCompressStats['fleet'] : [];
$wpcCompressTiming  = isset($wpcCompressStats['timing']) && is_array($wpcCompressStats['timing']) ? $wpcCompressStats['timing'] : [];
$wpcCompressSources = isset($wpcCompressStats['sources']) && is_array($wpcCompressStats['sources']) ? $wpcCompressStats['sources'] : [];

$wpcCompressSamples = (int) ($wpcCompressTiming['samples'] ?? 0);
$wpcCompressAvgMs   = $wpcCompressSamples > 0 ? (int) round(($wpcCompressTiming['sum_ms'] ?? 0) / $wpcCompressSamples) : 0;
$wpcCompressP95Ms   = function_exists('wpc_compress_stats_p95') ? wpc_compress_stats_p95($wpcCompressStats) : 0;
$wpcCompressMaxMs   = (int) ($wpcCompressTiming['max_ms'] ?? 0);

$wpcCompressFired     = (int) ($wpcCompressFleet['total_compresses_fired'] ?? 0);
$wpcCompressSucceeded = (int) ($wpcCompressFleet['total_compresses_succeeded'] ?? 0);
$wpcCompressFailed    = (int) ($wpcCompressFleet['total_compresses_failed'] ?? 0);
?>
<h2 style="margin-top:40px;">Compress Status</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
    <tr>
        <th style="width:260px;">Metric</th>
        <th>Value</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>Compresses fired</strong></td>
        <td>
            <?php echo $wpcCompressFired; ?> total
            — <span style="color:#2a7a2a;"><?php echo $wpcCompressSucceeded; ?> succeeded</span>
            / <span style="color:#a00;"><?php echo $wpcCompressFailed; ?> failed</span>
        </td>
    </tr>
    <tr>
        <td><strong>Source attribution</strong></td>
        <td>
            <?php
            $compressSourceOrder = ['upload', 'single', 'bulk', 'retry', 'unknown'];
            $cParts = [];
            foreach ($compressSourceOrder as $src) {
                $count = (int) ($wpcCompressSources[$src] ?? 0);
                if ($count > 0) $cParts[] = esc_html($src) . ' <strong>' . $count . '</strong>';
            }
            echo $cParts ? implode(' · ', $cParts) : '<em>no compresses yet</em>';
            ?>
        </td>
    </tr>
    <tr>
        <td><strong>Compress duration</strong></td>
        <td>
            avg <?php echo $wpcCompressAvgMs; ?>ms
            · p95 <?php echo $wpcCompressP95Ms; ?>ms
            · max <?php echo $wpcCompressMaxMs; ?>ms
            <?php if ($wpcCompressSamples > 0): ?>
                (<?php echo $wpcCompressSamples; ?> samples)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Last compress</strong></td>
        <td>
            <?php if (!empty($wpcCompressFleet['last_compress_at'])): ?>
                <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcCompressFleet['last_compress_at'])); ?> UTC
                (<?php echo human_time_diff((int) $wpcCompressFleet['last_compress_at'], time()); ?> ago)
            <?php else: ?>
                <em>never fired</em>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>

<?php
// ─── Restore Status ────────────────────────────────────────────────────
// Telemetry for the restore pipeline — mirrors the ladder stats panel structure.
$wpcRestoreStats = get_option('wpc_restore_stats', []);
if (!is_array($wpcRestoreStats)) $wpcRestoreStats = [];
$wpcRestoreFleet   = isset($wpcRestoreStats['fleet']) && is_array($wpcRestoreStats['fleet']) ? $wpcRestoreStats['fleet'] : [];
$wpcRestoreTiming  = isset($wpcRestoreStats['timing']) && is_array($wpcRestoreStats['timing']) ? $wpcRestoreStats['timing'] : [];
$wpcRestoreSources = isset($wpcRestoreStats['sources']) && is_array($wpcRestoreStats['sources']) ? $wpcRestoreStats['sources'] : [];

$wpcRestoreSamples = (int) ($wpcRestoreTiming['samples'] ?? 0);
$wpcRestoreAvgMs   = $wpcRestoreSamples > 0 ? (int) round(($wpcRestoreTiming['sum_ms'] ?? 0) / $wpcRestoreSamples) : 0;
$wpcRestoreP95Ms   = function_exists('wpc_restore_stats_p95') ? wpc_restore_stats_p95($wpcRestoreStats) : 0;
$wpcRestoreMaxMs   = (int) ($wpcRestoreTiming['max_ms'] ?? 0);

$wpcRestoreFired     = (int) ($wpcRestoreFleet['total_restores_fired'] ?? 0);
$wpcRestoreSucceeded = (int) ($wpcRestoreFleet['total_restores_succeeded'] ?? 0);
$wpcRestoreFailed    = (int) ($wpcRestoreFleet['total_restores_failed'] ?? 0);
?>
<h2 style="margin-top:40px;">Restore Status</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
    <tr>
        <th style="width:260px;">Metric</th>
        <th>Value</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>Restores fired</strong></td>
        <td>
            <?php echo $wpcRestoreFired; ?> total
            — <span style="color:#2a7a2a;"><?php echo $wpcRestoreSucceeded; ?> succeeded</span>
            / <span style="color:#a00;"><?php echo $wpcRestoreFailed; ?> failed</span>
        </td>
    </tr>
    <tr>
        <td><strong>Source attribution</strong></td>
        <td>
            <?php
            $sourceOrder = ['local_bkp', 'cloud_bkp', 'service', 'unknown'];
            $sourceLabels = ['local_bkp' => 'local _bkp', 'cloud_bkp' => '/wpc-backups/', 'service' => 'service download', 'unknown' => 'unknown'];
            $sParts = [];
            foreach ($sourceOrder as $src) {
                $count = (int) ($wpcRestoreSources[$src] ?? 0);
                if ($count > 0) $sParts[] = esc_html($sourceLabels[$src] ?? $src) . ' <strong>' . $count . '</strong>';
            }
            echo $sParts ? implode(' · ', $sParts) : '<em>no restores yet</em>';
            ?>
        </td>
    </tr>
    <tr>
        <td><strong>Restore duration</strong></td>
        <td>
            avg <?php echo $wpcRestoreAvgMs; ?>ms
            · p95 <?php echo $wpcRestoreP95Ms; ?>ms
            · max <?php echo $wpcRestoreMaxMs; ?>ms
            <?php if ($wpcRestoreSamples > 0): ?>
                (<?php echo $wpcRestoreSamples; ?> samples)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Last restore</strong></td>
        <td>
            <?php if (!empty($wpcRestoreFleet['last_restore_at'])): ?>
                <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcRestoreFleet['last_restore_at'])); ?> UTC
                (<?php echo human_time_diff((int) $wpcRestoreFleet['last_restore_at'], time()); ?> ago)
            <?php else: ?>
                <em>never fired</em>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>

<script type="text/javascript">
    jQuery(document).ready(function ($) {

        $('.wps_copy_button').on('click', function () {
            var field = $(this).attr("data-field")
            console.log(field);
            var text = document.getElementById('wps_' + field + '_field');

            // Copy the text inside the text field
            navigator.clipboard.writeText(text.value);

            // Alert the copied text
            alert('<?php echo esc_js(__('Copied to Clipboard', WPS_IC_TEXTDOMAIN)); ?>');
        })

    });
</script>