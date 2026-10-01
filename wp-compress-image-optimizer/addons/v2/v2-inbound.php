<?php
/**
 * The inbound verifier: every request the image service sends this site (the REST callbacks in
 * v2-callback.php and the direct-entry files under api/v2/) passes wpc_v2_inbound_verify() before
 * its handler reads a field.
 *
 * Observed (review 2026-09-26, B-F2): each handler carried its own subset of the checks. The
 * direct-entry files verified with the apikey only, never the dedicated callback secret; no route
 * capped the body size; the direct-entry files and the lazy-CDN single callback wrote bytes without
 * the image check; and a signed fetchUrl was pulled from any host the body named.
 *
 *   wpc_v2_inbound_verify($route, $sig, $body, $opts)   size cap, signature, fetch hosts (the request)
 *   wpc_v2_inbound_image_ok($raw, $format, $id, $route) the image check, per item, in each handler
 *   wpc_v2_inbound_fetch_host_ok($url)                   the host allowlist
 *   wpc_v2_verify_hmac(...)                              the signature (cb_secret, then the apikey until hardened)
 *   wpc_is_valid_image_bytes(...)                        magic bytes, executable markers, decode
 *
 * Loaded by v2-bootstrap.php and, standalone, by api/v2/_shared.php under SHORTINIT: nothing here
 * may need a WordPress function outside the options, plugin and formatting APIs without a
 * function_exists() guard. Refusals leave `v2-callback-refused {route, reason}` where the cache-first
 * log is loaded.
 */

if (!function_exists('wpc_v2_inbound_verify')) {
    /**
     * Answers ['ok' => true, 'via' => cb_secret|apikey_legacy, 'json' => decoded body or null] or
     * ['ok' => false, 'status' => int, 'reason' => string, 'detail' => string].
     *
     * Options: scope ('write' for routes that write files; see wpc_v2_verify_hmac), window (replay
     * seconds, 60), auth_status (the status a bad signature answers, 403).
     */
    function wpc_v2_inbound_verify($route, $sig, $body, array $opts = [])
    {
        $route = (string) $route;
        $body = (string) $body;
        // Size first: the signature hashes the whole body, and the service declares the most it sends.
        $cap = wpc_v2_inbound_max_bytes();
        if (strlen($body) > $cap) {
            return wpc_v2_inbound_refuse($route, 413, 'too-large', strlen($body) . '>' . $cap);
        }
        $v = wpc_v2_verify_hmac((string) $sig, $body, isset($opts['window']) ? (int) $opts['window'] : 60, isset($opts['scope']) ? (string) $opts['scope'] : '');
        if (empty($v['ok'])) {
            return wpc_v2_inbound_refuse($route, isset($opts['auth_status']) ? (int) $opts['auth_status'] : 403, 'bad-signature', (string) ($v['reason'] ?? ''));
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => true, 'via' => (string) ($v['via'] ?? ''), 'json' => null];
        }
        $items = [$json];
        foreach (['variants', 'items'] as $list) {
            if (isset($json[$list]) && is_array($json[$list])) {
                foreach ($json[$list] as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }
        }
        foreach ($items as $item) {
            foreach (['fetchUrl', 'bytesUrl', 'bytes_url', 'fetch_url'] as $field) {
                if (isset($item[$field]) && is_string($item[$field]) && $item[$field] !== ''
                    && !wpc_v2_inbound_fetch_host_ok($item[$field])) {
                    $refusedHost = (string) parse_url($item[$field], PHP_URL_HOST);
                    return wpc_v2_inbound_refuse($route, 400, 'foreign-fetch-host', $refusedHost, ['host' => $refusedHost]);
                }
            }
        }
        // The image check is not made here: it is per item, in each handler that writes bytes
        // (wpc_v2_inbound_image_ok). Observed: made here for the whole request, one bad variant
        // answered 422 for a batch of up to 25 and the good ones were lost, where the batch
        // handler had always rejected only that item (review 2026-09-26, finding 3).
        return ['ok' => true, 'via' => (string) ($v['via'] ?? ''), 'json' => $json];
    }

    /** The image check a handler makes on each item's bytes, inline or pulled from a verified fetchUrl. */
    function wpc_v2_inbound_image_ok($raw, $format, $imageID, $route)
    {
        $format = strtolower((string) $format);
        if ($format === 'jpg') {
            $format = 'jpeg';
        }
        if (wpc_is_valid_image_bytes((string) $raw, $format, (int) $imageID, 'inbound_' . $route)) {
            return true;
        }
        wpc_v2_inbound_refuse($route, 422, 'not-image', $format);
        return false;
    }

    /**
     * A fetchUrl may name the image service's own hosts (the orchestrator, its variant store, the
     * variants host its capabilities declare, the zapwp domains its zones live on) or this site.
     * Observed: the handlers pulled whatever host a signed body named, so a holder of the apikey
     * could make the site fetch from its internal network.
     */
    function wpc_v2_inbound_fetch_host_ok($url)
    {
        $p = parse_url((string) $url);
        if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) {
            return false;
        }
        if (!in_array(strtolower($p['scheme']), ['https', 'http'], true)) {
            return false;
        }
        $host = strtolower(trim($p['host'], '[]'));
        foreach (wpc_v2_inbound_fetch_hosts() as $allowed) {
            if ($host === $allowed) {
                return true;
            }
        }
        return (bool) preg_match('/(^|\.)zapwp\.(com|net)$/', $host);
    }

    /**
     * The exact hosts a fetchUrl may name besides the zapwp domains: the service's variants host
     * (wpc_v2_variants_host()), the orchestrator and this site. Exact hosts, not *.b-cdn.net,
     * which any Bunny customer can own.
     */
    function wpc_v2_inbound_fetch_hosts()
    {
        $hosts = [wpc_v2_variants_host()];
        $orch = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        foreach ([$orch, (string) get_option('home'), (string) get_option('siteurl')] as $u) {
            if ($u === '') {
                continue;
            }
            $h = strpos($u, '://') === false ? $u : (string) parse_url($u, PHP_URL_HOST);
            if ($h !== '') {
                $hosts[] = strtolower($h);
            }
        }
        return array_values(array_unique((array) apply_filters('wpc_v2_inbound_fetch_hosts', $hosts)));
    }

    /**
     * The host the image service stages variant bytes on (its storage pull zone), the one owner:
     * the `variants_host` its /capabilities declares (orchestrator v3.24.146, contract
     * orchestrator-capabilities), from the cached capabilities. Read by the fetch-host allowlist
     * and the healthcheck's egress probe.
     *
     * Rule: the service names its own host. Observed: the host was written into the plugin twice
     * (the allowlist and the healthcheck probe) because the service declared none, and every
     * lazy-CDN batched and pull-mode callback staged there was refused as foreign-fetch-host
     * until it was listed (review 2026-09-26, finding 2; hub ask 047, item 7). The default below
     * is the host the service declared live on 28 Sep 2026 and is used only while no capabilities
     * are cached (a new site, or a probe that has never answered): refusing every landing then
     * would drop deliveries the service already made.
     */
    function wpc_v2_variants_host()
    {
        $caps = function_exists('get_site_transient') && defined('WPC_V2_CAPS_CACHE_KEY') ? get_site_transient(WPC_V2_CAPS_CACHE_KEY) : false;
        $declared = is_array($caps) ? trim((string) ($caps['variants_host'] ?? '')) : '';
        if ($declared !== '') {
            $host = strpos($declared, '://') === false ? $declared : (string) parse_url($declared, PHP_URL_HOST);
            $host = strtolower(trim($host, "/ 	"));
            if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) {
                return $host;
            }
        }
        return 'wpc-v2-variants.b-cdn.net';
    }

    /** The body cap: the service's declared max_callback_bytes from the cached capabilities. */
    function wpc_v2_inbound_max_bytes()
    {
        $caps = function_exists('get_site_transient') && defined('WPC_V2_CAPS_CACHE_KEY') ? get_site_transient(WPC_V2_CAPS_CACHE_KEY) : false;
        $cap = is_array($caps) && !empty($caps['max_callback_bytes']) ? (int) $caps['max_callback_bytes'] : 0;
        return $cap > 0 ? $cap : 4194304;
    }

    function wpc_v2_inbound_refuse($route, $status, $reason, $detail = '', array $extra = [])
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('v2-callback-refused', '', '', ['route' => (string) $route, 'reason' => (string) $reason, 'detail' => substr((string) $detail, 0, 80)] + $extra);
        }
        error_log('[WPC V2Inbound] refused route=' . $route . ' reason=' . $reason . ($detail !== '' ? ' detail=' . substr((string) $detail, 0, 80) : ''));
        return ['ok' => false, 'status' => (int) $status, 'reason' => (string) $reason, 'detail' => (string) $detail];
    }
}

if (!function_exists('wpc_v2_verify_hmac')) {
    /**
     * @param string $scope v7.10.652 — 'write' ONLY for the bg_swap* disk-write routes. The
     *   orchestrator correctly flagged that strict mode living in this SHARED verifier would
     *   also gate /wake (±300s) and /healthcheck — neither of which writes to disk, and both
     *   of which should keep using the identifier. Anything not explicitly 'write' is exempt
     *   from hardening and from the migration counter.
     */
    function wpc_v2_verify_hmac($sig_header, $body_raw, $replay_window_s = 60, $scope = '')
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

        $payload = $ts . '.' . hash('sha256', $body_raw);
        $given   = (string) $parts['v1'];

        // v7.10.650 — the DEDICATED callback secret is tried first. The api_key remains
        // accepted only until this site has observed the orchestrator signing with the
        // dedicated secret (see wpc_v2_is_callback_auth_strict), after which the identifier can
        // no longer authorize a write. Constant-time compare on every branch.
        $is_write_scope = ($scope === 'write');
        // Direct entry runs under SHORTINIT without v2-capabilities.php: read the same option.
        $secret = function_exists('wpc_v2_callback_secret') ? wpc_v2_callback_secret(false) : (string) get_option('wpc_cb_secret650', '');
        if ($secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $given)) {
            if ($is_write_scope && function_exists('wpc_v2_note_callback_secret_use')) {
                wpc_v2_note_callback_secret_use();
            }
            return ['ok' => true, 'via' => 'cb_secret'];
        }

        $strict = function_exists('wpc_v2_is_callback_auth_strict')
            ? wpc_v2_is_callback_auth_strict()
            : (!apply_filters('wpc_v2_hmac_allow_apikey_fallback', true) || get_option('wpc_cb_strict650') === '1');
        if ($is_write_scope && $strict) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cb-sig-strict-reject', '', '', []);
            }
            return ['ok' => false, 'reason' => 'sig_mismatch_strict'];
        }

        // Canonical key resolver (wpc_v2_get_apikey, in v2-capabilities.php).
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : (function_exists('wpc_v2_read_apikey') ? wpc_v2_read_apikey() : '');
        if ($apikey === '') {
            return ['ok' => false, 'reason' => 'plugin_no_apikey'];
        }

        if (!hash_equals(hash_hmac('sha256', $payload, $apikey), $given)) {
            return ['ok' => false, 'reason' => 'sig_mismatch'];
        }

        return ['ok' => true, 'via' => 'apikey_legacy'];
    }
}

if (!function_exists('wpc_is_valid_image_bytes')) {
    function wpc_is_valid_image_bytes($bytes, $format, $imageID = 0, $source = 'unknown', $context = [])
    {
        if (empty($bytes) || !is_string($bytes)) {
            return false;
        }
        $len = strlen($bytes);


        // URL, response body first 50 bytes, age-since-compress, source attribution.
        $build_log = function ($reason) use ($imageID, $format, $len, $source, $bytes, $context) {
            $size_label = isset($context['size_label']) ? (string) $context['size_label'] : '';
            $url        = isset($context['url']) ? (string) $context['url'] : '';
            $hex50      = bin2hex(substr($bytes, 0, 50));


            $age_sec = '?';
            if ($imageID > 0 && function_exists('get_post_meta')) {
                $stats = get_post_meta((int) $imageID, '_wpc_last_post_timing', true);
                if (is_array($stats) && !empty($stats['at'])) {
                    $age_sec = (string) max(0, time() - (int) $stats['at']);
                }
            }
            return '[WPC CorruptByte] image=' . (int) $imageID
                . ' size=' . $size_label
                . ' fmt=' . $format
                . ' bytes=' . $len
                . ' source=' . $source
                . ' reason=' . $reason
                . ' age_sec=' . $age_sec
                . ' url=' . $url
                . ' hex50=' . $hex50
                . ' — REJECTED';
        };

        // Minimum size — any real image at meaningful dimensions is at least ~500 bytes.
        // Observed corrupt placeholders at exactly 678 bytes; this rejects that and similar.
        if ($len < 500) {
            error_log($build_log('too-small'));
            return false;
        }

        $fmt = strtolower((string) $format);
        $ok = true;
        $reason = '';

        if ($fmt === 'webp') {
            $ok = (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP');
            $reason = 'invalid-webp-magic';
        } elseif ($fmt === 'avif') {
            $ftyp = substr($bytes, 4, 4);
            $brand = substr($bytes, 8, 4);
            $ok = ($ftyp === 'ftyp' && in_array($brand, ['avif', 'avis', 'mif1', 'heic', 'heix'], true));
            $reason = 'invalid-avif-magic';
        } elseif ($fmt === 'jpeg' || $fmt === 'jpg') {
            $ok = (substr($bytes, 0, 3) === "\xFF\xD8\xFF");
            $reason = 'invalid-jpeg-magic';
        } elseif ($fmt === 'png') {
            $ok = (substr($bytes, 0, 8) === "\x89PNG\r\n\x1A\n");
            $reason = 'invalid-png-magic';
        }

        if (!$ok) {
            error_log($build_log($reason));
            return false;
        }

        // v7.10.649 — CVE-2026-18518 (link 4: POLYGLOT BYTES). A 3-byte magic prefix is
        // not proof of an image: the PoC prefixed \xFF\xD8\xFF to a PHP payload and
        // passed. Executable markers are rejected outright, and raster formats must
        // additionally decode as a real image of their claimed type.
        // Only markers long enough to be impossible by chance: entropy-coded image data contains
        // every 2-byte sequence — "<%" appeared in 230 of 400 real image files (79% of those over
        // 50KB), so that test rejected most healthy optimized images at land, parked them after
        // four retries, and grew the queue (spessart-militaria, 2,200 queued). "<?=" is 3 bytes,
        // ~0.4% per 70KB image — the same failure at a slower rate. 5+ bytes: 0 of 400.
        if (stripos($bytes, '<?php') !== false || stripos($bytes, '<script') !== false) {
            error_log($build_log('embedded-executable-marker'));
            return false;
        }
        if (($fmt === 'jpeg' || $fmt === 'jpg' || $fmt === 'png' || $fmt === 'gif')
            && function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bytes);
            $want = ($fmt === 'png') ? IMAGETYPE_PNG : (($fmt === 'gif') ? IMAGETYPE_GIF : IMAGETYPE_JPEG);
            if (!is_array($info) || empty($info[2]) || (int) $info[2] !== $want) {
                error_log($build_log('decode-mismatch'));
                return false;
            }
        }

        return true;
    }
}
