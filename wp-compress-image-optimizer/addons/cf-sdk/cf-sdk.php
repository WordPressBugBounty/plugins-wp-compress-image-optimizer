<?php
if (!function_exists('wpc_cf_permission_rows')) {
    /**
     * v7.10.504 — THE single source of truth for the CF token permission table. It previously lived as
     * an inline array inside advanced_settings_v4.php, so the connect-result panel could not name a
     * missing permission and fell back to "usually an API-token permission or a zone setting" — a guess
     * printed over an answer the same handler had already stored in wpc_cf_privileges.
     *
     * tier:  'req'     -> blocks. Nothing CF-related works without it.
     *        'risk'    -> proceed, but a SETTING can misbehave; say which.
     *        'feature' -> proceed, a named feature is simply unavailable.
     *
     * Keys must match checkPrivileges()'s $permissionTests keys exactly.
     */
    function wpc_cf_permission_rows()
    {
        $t = defined('WPS_IC_TEXTDOMAIN') ? WPS_IC_TEXTDOMAIN : 'default';
        return [
            [
                'key' => 'Zone Read', 'action' => __('Read Zones', $t),
                'path' => 'Zone → Zone', 'recipe' => 'Zone → Zone → Read', 'tier' => 'req',
                'feature' => '',
                'why'    => __('Lets WP Compress find and verify your Cloudflare zone.', $t),
                'impact' => __('Without it we cannot identify your zone, so no Cloudflare feature can work.', $t),
            ],
            [
                'key' => 'Cache Purge', 'action' => __('Purge Cache', $t),
                'path' => 'Zone → Cache Purge', 'recipe' => 'Zone → Cache Purge → Purge', 'tier' => 'req',
                'feature' => '',
                'why'    => __('Clears specific pages from Cloudflare instantly after content or optimization updates.', $t),
                'impact' => __('Without it Cloudflare keeps serving old HTML after every edit, update and optimization — including after plugin updates.', $t),
            ],
            [
                'key' => 'Zone Settings Edit', 'action' => __('Edit Zone Settings', $t),
                'path' => 'Zone → Zone Settings', 'recipe' => 'Zone → Zone Settings → Edit', 'tier' => 'risk',
                'feature' => __('Rocket Loader conflict handling', $t),
                'why'    => __('Detects and resolves Rocket Loader conflicts automatically.', $t),
                'impact' => __('If Rocket Loader is enabled on this zone we cannot turn it off, and it will conflict with JS delay and optimization.', $t),
            ],
            [
                'key' => 'Firewall Services Edit', 'action' => __('Edit Firewall Services', $t),
                'path' => 'Zone → Firewall Services', 'recipe' => 'Zone → Firewall Services → Edit', 'tier' => 'risk',
                'feature' => __('firewall & access rules', $t),
                'why'    => __('Whitelists our optimization servers so they are never blocked.', $t),
                'impact' => __('Our optimization servers may be challenged or blocked by your firewall, so critical CSS and warmup can fail intermittently.', $t),
            ],
            [
                'key' => 'Cache Rules Edit', 'action' => __('Edit Cache Rules', $t),
                'path' => 'Zone → Cache Rules', 'recipe' => 'Zone → Cache Rules → Edit', 'tier' => 'feature',
                'feature' => __('edge-cache optimization rules', $t),
                'why'    => __('Creates the edge rules that serve your pages from Cloudflare\'s global network.', $t),
                'impact' => __('Pages will not be served from Cloudflare\'s edge; everything still works, just from your origin.', $t),
            ],
            [
                'key' => 'DNS Edit', 'action' => __('Edit DNS', $t),
                'path' => 'Zone → DNS', 'recipe' => 'Zone → DNS → Edit', 'tier' => 'feature',
                'feature' => __('automatic CNAME setup', $t),
                'why'    => __('Sets up the CDN hostname (CNAME) for you automatically.', $t),
                'impact' => __('You will have to create the CDN CNAME record yourself instead of us doing it.', $t),
            ],
            [
                'key' => 'Analytics Read', 'action' => __('Read Analytics', $t),
                'path' => 'Zone → Analytics', 'recipe' => 'Zone → Analytics → Read', 'tier' => 'feature',
                'feature' => __('the Cloudflare analytics panel', $t),
                'why'    => __('Powers the Cloudflare traffic panel in your dashboard.', $t),
                'impact' => __('The Cloudflare traffic panel stays empty. Nothing else is affected.', $t),
            ],
            [
                'key' => 'Zone WAF Edit', 'action' => __('Edit Zone WAF', $t),
                'path' => 'Zone → Zone WAF', 'recipe' => 'Zone → Zone WAF → Edit', 'tier' => 'feature',
                'feature' => __('the security-bypass rule', $t),
                'why'    => __('Writes the WAF custom rule that tells Cloudflare never to challenge our optimizer or the asset host. Firewall Services is the legacy API and does not cover it.', $t),
                'impact' => __('Cloudflare may challenge our optimizer on some plans; the rule is skipped and logged. Everything else works.', $t),
            ],
        ];
    }
}

if (!function_exists('wpc_cf_permission_row_lines')) {
    /**
     * The two lines every permission row shows under its name, granted or missing: what the
     * permission lets WP Compress do, and what fails without it. The panel's server render and
     * the connect-error list in tabs.js both print these strings, so a row reads the same in both.
     */
    function wpc_cf_permission_row_lines($row)
    {
        $t = defined('WPS_IC_TEXTDOMAIN') ? WPS_IC_TEXTDOMAIN : 'default';
        return [
            'allows'  => sprintf(__('Allows: %s', $t), (string) ($row['why'] ?? '')),
            'missing' => sprintf(__('If missing: %s', $t), (string) ($row['impact'] ?? '')),
        ];
    }
}

if (!function_exists('wpc_cf_store_privileges')) {
    /**
     * The one writer of wpc_cf_privileges: {t, privs, token}. token is the identity checkPrivileges()
     * read ({id, status, t}), kept beside the permission result so the panel names the token it
     * checked; it never holds the token value.
     */
    function wpc_cf_store_privileges($privs)
    {
        $token = (is_array($privs) && isset($privs['token']) && is_array($privs['token'])) ? $privs['token'] : null;
        if (is_array($privs)) {
            unset($privs['token']);
        }
        update_option('wpc_cf_privileges', ['t' => time(), 'privs' => $privs, 'token' => $token], false);
    }
}

if (!function_exists('wpc_cf_permission_verdict')) {
    /**
     * Classify a stored $tests map against the row table. Returns
     * ['checked'=>bool,'missing'=>[rows],'req_missing'=>[rows],'soft_missing'=>[rows],'can_proceed'=>bool].
     * can_proceed is TRUE when every 'req' row is granted — the rest are adaptable.
     */
    function wpc_cf_permission_verdict($tests)
    {
        $tests = is_array($tests) ? $tests : [];
        $out = ['checked' => (bool) $tests, 'missing' => [], 'req_missing' => [], 'soft_missing' => []];
        foreach (wpc_cf_permission_rows() as $row) {
            $res = isset($tests[$row['key']]) ? (string) $tests[$row['key']] : '';
            if ($res === '' || strpos($res, 'OK') === 0) {
                continue;
            }
            $out['missing'][] = $row;
            if ($row['tier'] === 'req') {
                $out['req_missing'][] = $row;
            } else {
                $out['soft_missing'][] = $row;
            }
        }
        $out['can_proceed'] = $out['checked'] && !$out['req_missing'];
        return $out;
    }
}



// Rule identifiers for WP Compress plugin
const WPC_BYPASS_RULE_REF = 'wpc-bypass-cache';
const WPC_STATIC_RULE_REF = 'wpc-static-assets';
const WPC_HOMEPAGE_RULE_REF = 'wpc-homepage-html';
const WPC_FULLHTML_RULE_REF = 'wpc-full-html';
const WPC_CONFIG_INJECT_RULE_REF = 'wpc-config-inject'; // CF Piece 2 (signed x-wpc-config), scaffold
const WPC_ROBOTS_RULE_REF = 'wpc-robots-sitemap';

// The rule definitions live in their owner; the SDK is loaded alone by cron and by several
// admin paths, so it brings the owner with it.
if (!class_exists('wps_ic_cf_rules') && defined('WPS_IC_DIR')) {
    require_once WPS_IC_DIR . 'classes/cf_rules.class.php';
}


class WPC_CloudflareAPI
{
    private $apiToken;
    private $apiBase = 'https://api.cloudflare.com/client/v4/';

    /**
     * Constructor to initialize the API token
     *
     * @param string $apiToken Your Cloudflare API token
     */
    public function __construct($apiToken = '')
    {

        if (empty($apiToken)) {
            // Nothing
            return false;
        }

        $this->apiToken = $apiToken;
    }


    public function configureCF($htmlCacheMode, $staticAssetsEnabled)
    {
        $requests = new wps_ic_requests();

        $cfSettings = get_option(WPS_IC_CF);
        $zoneInput = $cfSettings['zone'];
        $token = $cfSettings['token'];

        $options = get_option(WPS_IC_OPTIONS);
        $apikey = $options['api_key'];

        $siteUrl = site_url();
        $zoneName = str_replace(array('http://', 'https://', '/'), '', $siteUrl);

        // Rule: a refusal is not an answer. keys d9b24cde (hub ask 044) answers an empty token with
        // {success:false, data:{code:'cf-token-missing', cfName}}; the old `!empty($body)` read
        // that as the config answer. keys() returns data only on success and logs
        // `keys-call-failed {action, why}` for every other outcome; both callers (the CF panel
        // save and the upgrade pass) read only error fields of the result, so false keeps them as
        // they were.
        $keys = $requests->keys('updateCFConfig', ['token' => $token, 'zone' => $zoneInput, 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'apikey' => $apikey, 'time' => microtime(true), 'staticAssets' => $staticAssetsEnabled, 'htmlCache' => $htmlCacheMode], (int) apply_filters('wpc_cf_keys_timeout', 15));

        return $keys['ok'] ? (array) $keys['data'] : false;
    }


    /**
     * Check Rocket Loader Status
     *
     * @return array|WP_Error List of zones or WP_Error
     */
    public function checkRocketLoader($zoneId)
    {
        $rlResp = $this->getRequest("zones/$zoneId/settings/rocket_loader");

        if (is_wp_error($rlResp)) {
            // Store per-zone error but keep going for other zones
            $results[$zoneId] = new WP_Error('cloudflare_api_error', "Failed to fetch Rocket Loader " . $rlResp->get_error_message());

            return 'failed to fetch rocket loader';
        }

        // Cloudflare returns: { result: { id, value, editable, modified_on, ... } }
        if (!empty($rlResp['result']) && isset($rlResp['result']['value'])) {
            $results[$zoneId] = ['value' => $rlResp['result']['value'],       // 'on' | 'off'
                'modified_on' => $rlResp['result']['modified_on'] ?? null, 'editable' => $rlResp['result']['editable'] ?? null,];

            return $results;
        } else {
            $results[$zoneId] = new WP_Error('cloudflare_api_error', "Unexpected response while fetching Rocket Loader");

            return false;
        }
    }


    private function getRequest($endpoint, $query = [])
    {
        $url = add_query_arg($query, $this->apiBase . $endpoint);

        $response = wp_remote_get($url, ['headers' => $this->getHeaders(), 'timeout' => (int) apply_filters('wpc_cf_api_timeout', 8)]);


        return $this->processResponse($response);
    }

    /**
     * Get standard headers for the API requests
     *
     * @return array
     */
    private function getHeaders()
    {
        return ['Authorization' => 'Bearer ' . $this->apiToken, 'Content-Type' => 'application/json',];
    }

    /**
     * Process the API response
     *
     * @param array|WP_Error $response API response
     * @return array|WP_Error Parsed response or WP_Error
     */
    private function processResponse($response)
    {
        if (is_wp_error($response)) {
            return $response;
        }

        // v7.10.667 — check rate limiting FIRST, before the body parse / non-json guard: a 429 can
        // arrive as an HTML challenge/edge page, which the non-json guard would otherwise mislabel as
        // a generic api_error and defeat the .665 retry-guards.
        if ((int) wp_remote_retrieve_response_code($response) === 429) {
            return new WP_Error('cloudflare_rate_limited', 'rate limited (http 429)', ['retry_after' => wp_remote_retrieve_header($response, 'retry-after')]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Non-JSON body (challenge page / proxy 5xx) must surface as an error — callers
        // treat an empty result as "rule missing" and would create duplicate rules
        if (!is_array($data)) {
            return new WP_Error('cloudflare_api_error', 'non-json response (http ' . (int) wp_remote_retrieve_response_code($response) . ')');
        }

        if (!empty($data['errors'])) {
            $error_messages = array_map(function ($error) {
                return $error['message']; // Extract error messages
            }, $data['errors']);

            $error_message = implode(', ', $error_messages); // Combine multiple messages if needed

            return new WP_Error('cloudflare_api_error', $error_message, $data['errors']);
        }

        return $data;
    }


    public static function classifyResult($result)
    {
        if ($result === true) {
            return ['ok' => true, 'mode' => 'ok', 'detail' => 'OK'];
        }
        if (is_array($result)) {
            if (array_key_exists('success', $result) && !$result['success']) {
                $m = !empty($result['errors'][0]['message']) ? (string) $result['errors'][0]['message'] : 'Cloudflare reported failure';
                return ['ok' => false, 'mode' => 'unknown', 'detail' => $m];
            }
            return ['ok' => true, 'mode' => 'ok', 'detail' => 'OK'];
        }
        if (is_wp_error($result)) {
            $code = (string) $result->get_error_code();
            $msg  = (string) $result->get_error_message();
            // (1) transport-level: we NEVER heard back (timeout / DNS / connection refused).
            if ($code === 'http_request_failed'
                || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false
                || stripos($msg, 'could not resolve') !== false || stripos($msg, 'failed to connect') !== false
                || stripos($msg, 'connection') !== false || stripos($msg, 'resolve host') !== false) {
                return ['ok' => false, 'mode' => 'unreachable',
                    'detail' => 'Could not reach Cloudflare (' . ($msg !== '' ? $msg : 'no response') . '). This is usually transient — try Reconnect again.'];
            }
            // (2) CF answered with an error body: inspect the preserved CF error codes.
            $data  = $result->get_error_data();
            $codes = [];
            if (is_array($data)) {
                foreach ($data as $e) {
                    if (is_array($e) && isset($e['code'])) $codes[] = (int) $e['code'];
                }
            }
            $permCodes = [10000, 9109, 9106, 9103, 1000];
            $zoneCodes = [7003, 7000, 1001, 1049, 1061];
            foreach ($codes as $c) {
                if (in_array($c, $permCodes, true)) {
                    return ['ok' => false, 'mode' => 'permission',
                        'detail' => 'Cloudflare rejected it — your API token is missing a permission for this call (rule writes need Zone → Zone WAF → Edit; cache rules need Cache Rules → Edit; purges need Cache Purge). CF said: ' . $msg];
                }
            }
            foreach ($codes as $c) {
                if (in_array($c, $zoneCodes, true)) {
                    return ['ok' => false, 'mode' => 'misconfig',
                        'detail' => 'Cloudflare zone looks misconfigured (' . $msg . '). Check the zone is active and the token is scoped to it.'];
                }
            }
            if (stripos($msg, 'authentication') !== false || stripos($msg, 'unauthor') !== false || stripos($msg, 'permission') !== false) {
                return ['ok' => false, 'mode' => 'permission',
                    'detail' => 'Cloudflare rejected it — token permissions. CF said: ' . $msg];
            }
            return ['ok' => false, 'mode' => 'unknown', 'detail' => $msg !== '' ? $msg : 'Cloudflare error'];
        }

        return ['ok' => false, 'mode' => 'unknown',
            'detail' => 'Could not complete — no Cloudflare response captured (likely a token or connection issue). Try Reconnect again.'];
    }

    /**
     * Retrieve the list of zones
     *
     * @return array|WP_Error List of zones or WP_Error
     */
    public function listZones($page = 1)
    {
        return $this->getRequest('zones', ['per_page' => 50, 'page' => $page]);
    }

    /**
     * Purge all cache for a specific zone
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return array|WP_Error The API response or WP_Error
     */
    public function purgeCache($zoneId)
    {
        $wpc_r = $this->postRequest("zones/$zoneId/purge_cache", ['purge_everything' => true,]);
        $this->wpc_ledger('purge_everything', 'zone', 1, $wpc_r, '');
        return $wpc_r;
    }


    public function purgeCacheAsync($zoneId)
    {
        $url = $this->apiBase . "zones/$zoneId/purge_cache";
        // v7.10.667 — BLOCKING by default (real API result → purge ledger, CF doctor and the
        // escalation decision stay honest). The purge already runs post-response (shutdown +
        // fastcgi_finish_request), so blocking does NOT slow the frontend/admin — it only holds the
        // FPM worker for the bounded, coalesced round-trip. Opt into true fire-and-forget for extreme
        // purge volumes via wpc_cf_purge_blocking=false (which trades observability for zero hold).
        $wpc_blk = (bool) apply_filters('wpc_cf_purge_blocking', true);
        $response = wp_remote_post($url, [
            'headers'  => $this->getHeaders(),
            'body'     => json_encode(['purge_everything' => true]),
            'timeout'  => $wpc_blk ? (int) apply_filters('wpc_cf_async_purge_timeout', 3) : 1,
            'blocking' => $wpc_blk,
        ]);
        $wpc_r = $wpc_blk ? $this->processResponse($response) : ['success' => true, 'fire_and_forget' => true];
        $this->wpc_ledger('purge_everything', 'zone', 1, $wpc_r, '');
        return $wpc_r;
    }


    public function purgeFilesAsync($zoneId, $files)
    {
        if (empty($files) || !is_array($files)) return null;
        $url = $this->apiBase . "zones/$zoneId/purge_cache";

        // 30-slice here silently DROPPED entries 31-100 of every chunk — purges reported success
        // while most of the list never reached CF.
        $wpc_blk = (bool) apply_filters('wpc_cf_purge_blocking', true); // v7.10.667 — BLOCKING default (real result → ledger/doctor/escalation honest); fire-and-forget is opt-in
        $response = wp_remote_post($url, [
            'headers'  => $this->getHeaders(),
            'body'     => json_encode(['files' => array_values(array_slice($files, 0, 100))]),
            'timeout'  => $wpc_blk ? (int) apply_filters('wpc_cf_async_purge_timeout', 3) : 1,
            'blocking' => $wpc_blk,
        ]);
        $wpc_r = $wpc_blk ? $this->processResponse($response) : ['success' => true, 'fire_and_forget' => true];
        $this->wpc_ledger('files', 'url', count($files), $wpc_r, implode(' ', array_slice(array_values($files), 0, 3)));
        return $wpc_r;
    }


    public function purgeByTags($zoneId, $tags)
    {
        $tags = array_values(array_unique(array_filter(array_map('strval', (array) $tags), 'strlen')));
        if (empty($tags)) return null;
        $response = wp_remote_post($this->apiBase . "zones/$zoneId/purge_cache", [
            'headers' => $this->getHeaders(),
            'body'    => json_encode(['tags' => array_slice($tags, 0, 100)]), // v7.10.665 25->100 (CF max)
            'timeout' => (int) apply_filters('wpc_cf_async_purge_timeout', 3),
        ]);
        // Tag purge STAYS blocking: its success flag drives the host-escalation decision in
        // purgeEdgeHtmlUrls / cfPurgeAllHtml. One ~3s call, post-response — bounded.
        $wpc_r = $this->processResponse($response);
        $this->wpc_ledger('tags', 'tag', count($tags), $wpc_r, implode(' ', array_slice($tags, 0, 3)));
        return $wpc_r;
    }


    public function purgeByPrefixes($zoneId, $prefixes)
    {
        $prefixes = array_values(array_unique(array_filter(array_map('strval', (array) $prefixes), 'strlen')));
        if (empty($prefixes)) return null;
        $wpc_blk = (bool) apply_filters('wpc_cf_purge_blocking', true); // v7.10.667 — BLOCKING default (real result → ledger/doctor/escalation honest); fire-and-forget is opt-in
        $response = wp_remote_post($this->apiBase . "zones/$zoneId/purge_cache", [
            'headers'  => $this->getHeaders(),
            'body'     => json_encode(['prefixes' => array_slice($prefixes, 0, 30)]), // v7.10.667 CF prefix cap = 30/request
            'timeout'  => $wpc_blk ? (int) apply_filters('wpc_cf_async_purge_timeout', 3) : 1,
            'blocking' => $wpc_blk,
        ]);
        $wpc_r = $wpc_blk ? $this->processResponse($response) : ['success' => true, 'fire_and_forget' => true];
        $this->wpc_ledger('prefixes', 'prefix', count($prefixes), $wpc_r, implode(' ', array_slice($prefixes, 0, 3)));
        return $wpc_r;
    }


    public function purgeByHosts($zoneId, $hosts)
    {
        $hosts = array_values(array_unique(array_filter(array_map('strval', (array) $hosts), 'strlen')));
        if (empty($hosts)) return null;
        $wpc_r = $this->postRequest("zones/$zoneId/purge_cache", ['hosts' => array_slice($hosts, 0, 25)]);
        $this->wpc_ledger('hosts', 'host', count($hosts), $wpc_r, implode(' ', array_slice($hosts, 0, 3)));
        return $wpc_r;
    }

    // Single recorder for all six. Sits in the SDK because that is the one point every
    // purge must pass through — instrumenting call sites misses whichever one nobody
    // remembers, which is exactly how the doubled purges went unseen locally.
    private function wpc_ledger($method, $scope, $count, $response, $sample)
    {
        if (!function_exists('wpc_purge_ledger_add')) {
            return;
        }
        // v7.10.667 — a fire-and-forget dispatch (opt-in) never sees the real result; mark it ':async'
        // so the ledger never records a CONFIRMED success it could not observe.
        if (is_array($response) && !empty($response['fire_and_forget'])) { $method .= ':async'; }
        $wpc_ok = !is_wp_error($response) && is_array($response) && !empty($response['success']);
        wpc_purge_ledger_add($method, $scope, $count, $wpc_ok, $sample);
    }


    private function postRequest($endpoint, $body = [])
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_post($url, ['headers' => $this->getHeaders(), 'body' => json_encode($body), 'timeout' => (int) apply_filters('wpc_cf_api_timeout', 8)]);

        return $this->processResponse($response);
    }


    public function purgeFiles($zoneId, $files)
    {
        $wpc_r = $this->postRequest("zones/$zoneId/purge_cache", ['files' => $files,]);
        $this->wpc_ledger('files', 'url', is_array($files) ? count($files) : 1, $wpc_r, is_array($files) ? (string) reset($files) : '');
        return $wpc_r;
    }


    public function purgeScoped($zoneId, $hosts)
    {
        $hosts = array_values(array_filter(array_unique(array_map('strval', (array) $hosts)), 'strlen'));
        if (!empty($hosts)) {
            $res = $this->postRequest("zones/$zoneId/purge_cache", ['hosts' => $hosts]);
            $this->wpc_ledger('hosts', 'host', count($hosts), $res, (string) reset($hosts));
            if (!is_wp_error($res) && !empty($res['success'])) {
                return ['scoped' => true, 'hosts' => $hosts, 'result' => $res];
            }
        }


        return ['scoped' => false, 'hosts' => $hosts, 'result' => $this->purgeCache($zoneId)];
    }


    public function verifyCfCnameLive($cfCname, $tries = 3, $timeout = 8)
    {
        $cfCname = trim((string) $cfCname);
        if ($cfCname === '' || !function_exists('wp_remote_get')) {
            return false;
        }
        // Probe a stable uploads path + a cache-buster so a stale edge bucket can't mask resolution.
        $probe = 'https://' . $cfCname . '/wp-content/uploads/wpc-cname-verify.png?cb=' . (function_exists('wp_rand') ? wp_rand() : 1);
        // v7.21.14 — THE VERIFIER IS A BOT BY CF'S DEFINITION: a tokenless datacenter-IP
        // fetch through the customer's own orange-clouded proxy is exactly what their bot
        // detection challenges, so a site could be fully provisioned and still never verify
        // (verified never flips -> cfwait suppresses every lane forever, green toggles lying).
        // The probe now rides the sanctioned door: x-origin-auth, exempted by the skip rule.
        $probeHeaders = [];
        $bypassToken = function_exists('get_option') ? trim((string) get_option('wpc_cf_bypass_tok2114', '')) : '';
        if ($bypassToken === '') {
            $bypassToken = (string) $this->getCdnBypassToken();
        }
        if ($bypassToken !== '' && apply_filters('wpc_cf_verify_rides_token', true)) {
            $probeHeaders['x-origin-auth'] = $bypassToken;
        }
        $challengeWitness = null;
        $probe_code = 0;
        // The CDN (cdn-mc) sets X-Redirect-Reason on every response since 2026-09-28
        // (shed_overloaded, never404_*, circuit_open_no_stale, ...), and it names why an answer was
        // not the asset; before it the record held only code and content type.
        $probe_rr = '';
        for ($i = 0; $i < max(1, (int) $tries); $i++) {
            $r = wp_remote_get($probe, ['timeout' => max(2, (int) $timeout), 'sslverify' => false, 'redirection' => 0, 'headers' => $probeHeaders]);
            if (!is_wp_error($r)) {
                $code    = (int) wp_remote_retrieve_response_code($r);
                $probe_code = $code;
                $probe_rr = substr((string) wp_remote_retrieve_header($r, 'x-redirect-reason'), 0, 64);
                $body    = (string) wp_remote_retrieve_body($r);
                $cfRay   = wp_remote_retrieve_header($r, 'cf-ray');
                $ctype   = (string) wp_remote_retrieve_header($r, 'content-type');
                $cfMitigated = (string) wp_remote_retrieve_header($r, 'cf-mitigated');
                $through_cf = !empty($cfRay);
                $site_not_found = (stripos($body, 'Site not found') !== false) || (stripos($body, '"hasApikey":false') !== false);


                $resolved_status = ($code === 404) || ($code === 200 && stripos($ctype, 'image/') !== false) || in_array($code, [301, 302, 307, 308], true);
                if ($through_cf && !$site_not_found && $resolved_status) {
                    if (function_exists('delete_option')) {
                        delete_option('wpc_cf_verify_challenged2114');
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('cname-selfprobe', '', '', ['host' => $cfCname, 'ok' => 1, 'code' => $probe_code, 'rr' => $probe_rr]);
                    }
                    return true;
                }
                if ($through_cf && ($cfMitigated === 'challenge' || $code === 403)) {
                    $challengeWitness = ['t' => time(), 'code' => $code, 'mitigated' => $cfMitigated, 'tokened' => ($probeHeaders !== [])];
                }
            }
            if ($i + 1 < $tries) {
                wpc_diag_sleep(2, 'cf-cname-verify');
            }
        }
        // A challenged verification is a NAMED state, never a silent one — the admin notice
        // reads this witness. Only written on definitive challenge evidence, cleared on success.
        if ($challengeWitness !== null && function_exists('update_option')) {
            update_option('wpc_cf_verify_challenged2114', $challengeWitness, false);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cname-selfprobe', '', '', ['host' => $cfCname, 'ok' => 0, 'code' => $probe_code, 'rr' => $probe_rr]);
        }
        return false;
    }

    /**
     * Fetch the CDN bypass token from WPC API.
     * Auto-generated server-side if it doesn't exist yet.
     *
     * @return string|false The 64-char hex token, or false on failure
     */
    public function getCdnBypassToken() {
        $options = get_option(WPS_IC_OPTIONS);
        if (empty($options['api_key'])) {
            error_log('[WPC] getCdnBypassToken: no api_key in options');
            return false;
        }

        $response = wp_remote_get(
            WPS_IC_KEYSURL . '?action=get_cf_bypass_token&apikey=' . $options['api_key'],
            ['timeout' => 15, 'sslverify' => false]
        );

        if (is_wp_error($response)) {
            error_log('[WPC] getCdnBypassToken: wp_remote_get error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success']) || empty($body['data']['token'])) {
            error_log('[WPC] getCdnBypassToken: unexpected response: ' . wp_remote_retrieve_body($response));
            return false;
        }

        // v7.21.14 — cache so the cname reverify probe can ride the token without a keys
        // round-trip on every attempt. Refreshed on every successful fetch and on rotate.
        if (function_exists('update_option')) {
            update_option('wpc_cf_bypass_tok2114', (string) $body['data']['token'], false);
        }
        return $body['data']['token'];
    }


    // The crit renderer runs real-browser UAs by design, so CF bot products can only admit it
    // by SOURCE NETWORK. All crit-push pods egress from one ASN (Datacamp); pod IPs churn on
    // redeploys, so the rule keys on the ASN, never an IP list. Skip is scoped to
    // bot-protection products, NOT the full WAF. Known limit: free-plan plain Bot Fight Mode
    // honors no exceptions — that zone needs BFM toggled off by hand.
    public function addRendererAllowRule($zoneId)
    {
        if (empty($zoneId) || !apply_filters('wpc_cf_renderer_allow', true)) {
            return false;
        }
        if (get_transient('wpc_cf_rr_done_' . $zoneId)) {
            return true;
        }
        $rendererAsn = (int) apply_filters('wpc_crit_renderer_asn', 60068);
        $ruleDescription = 'WPC Renderer Allow [DO NOT EDIT]';
        $wafRules = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_firewall_custom/entrypoint");
        if (!is_wp_error($wafRules) && !empty($wafRules['result']['rules'])) {
            foreach ($wafRules['result']['rules'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === $ruleDescription) {
                    set_transient('wpc_cf_rr_done_' . $zoneId, 1, 12 * HOUR_IN_SECONDS);
                    return true;
                }
            }
        }
        $allowRule = [
            'action'            => 'skip',
            'description'       => $ruleDescription,
            'enabled'           => true,
            'expression'        => 'ip.src.asnum eq ' . $rendererAsn,
            'action_parameters' => [
                'phases'   => ['http_request_sbfm', 'http_request_firewall_managed'],
                'products' => ['bic', 'securityLevel', 'uaBlock', 'hot'],
            ],
        ];
        $ruleset = $this->getRequest("zones/$zoneId/rulesets");
        $rulesetId = '';
        if (!is_wp_error($ruleset) && !empty($ruleset['result'])) {
            foreach ($ruleset['result'] as $rs) {
                if (isset($rs['phase']) && $rs['phase'] === 'http_request_firewall_custom' && isset($rs['kind']) && $rs['kind'] === 'zone') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }
        if ($rulesetId !== '') {
            $result = $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $allowRule);
        } else {
            $result = $this->postRequest("zones/$zoneId/rulesets", ['name' => 'WPC Firewall Rules', 'kind' => 'zone', 'phase' => 'http_request_firewall_custom', 'rules' => [$allowRule]]);
        }
        $ruleWritten = !is_wp_error($result) && !empty($result['success']);
        if (!$ruleWritten) {
            // Some plans reject the sbfm phase / scoped products — the ASN Access allow is the
            // older API with the broadest token acceptance. Admits the whole ASN but only as
            // an ALLOW, never a WAF skip.
            $result = $this->postRequest("zones/$zoneId/firewall/access_rules/rules", [
                'mode'          => 'whitelist',
                'configuration' => ['target' => 'asn', 'value' => 'AS' . $rendererAsn],
                'notes'         => $ruleDescription,
            ]);
            $ruleWritten = !is_wp_error($result) && !empty($result['success']);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($ruleWritten ? 'cf-renderer-allow' : 'cf-renderer-allow-failed', '', '', [
                'zone' => substr((string) $zoneId, 0, 12),
                'asn'  => $rendererAsn,
                'note' => $ruleWritten ? '' : 'both writes refused; if free-plan Bot Fight Mode is ON it honors no exceptions',
            ]);
        }
        if ($ruleWritten) {
            set_transient('wpc_cf_rr_done_' . $zoneId, 1, 12 * HOUR_IN_SECONDS);
        }
        return $ruleWritten ? true : $result;
    }

    // v7.21.17 — NEVER CHALLENGE THE ASSET HOST. A challenge page can only be solved by a
    // top-level navigation; fonts and CSS-referenced SVGs are fetched in CORS-anonymous mode
    // (no cookies, so no cf_clearance rides along), so ANY challenge on the cdn.* cname is a
    // guaranteed-broken asset for every visitor — 403 with no ACAO, the abasingbakes fonts.
    // The host serves only pull-zone statics; exempting it from challenge phases is strictly
    // correct. Same shape doctrine as the bypass rule: phases + ruleset:current + FIRST.
    public function addCdnHostExemptRule($zoneId) {
        if (!apply_filters('wpc_cf_cdn_host_exempt', true)) {
            return false;
        }
        $cdnHost = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        if ($cdnHost === '' || strpos($cdnHost, '"') !== false) {
            return false; // only CF-integration cnames sit behind the customer's Cloudflare
        }
        $ruleDescription = 'Optimizer CDN Host Exempt [DO NOT EDIT]';
        $ruleExpression = 'http.host eq "' . $cdnHost . '"';
        $skipPhases = ['http_request_firewall_managed', 'http_ratelimit', 'http_request_sbfm'];

        $firstRuleId = '';
        $wafRules = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_firewall_custom/entrypoint");
        if (!is_wp_error($wafRules) && !empty($wafRules['result']['rules'])) {
            $firstRuleId = !empty($wafRules['result']['rules'][0]['id']) ? (string) $wafRules['result']['rules'][0]['id'] : '';
            foreach ($wafRules['result']['rules'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === $ruleDescription) {
                    return true; // presence-checked; the host never rotates, so no re-shape needed
                }
            }
        }

        $exemptRule = [
            'action'            => 'skip',
            'description'       => $ruleDescription,
            'enabled'           => true,
            'expression'        => $ruleExpression,
            'action_parameters' => [
                'products' => ['uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],
                'phases'   => $skipPhases,
                'ruleset'  => 'current',
            ],
        ];
        $ruleset = $this->getRequest("zones/$zoneId/rulesets");
        $rulesetId = '';
        if (!is_wp_error($ruleset) && !empty($ruleset['result'])) {
            foreach ($ruleset['result'] as $rs) {
                if (isset($rs['phase']) && $rs['phase'] === 'http_request_firewall_custom' && isset($rs['kind']) && $rs['kind'] === 'zone') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }
        $paramShapes = [
            $exemptRule['action_parameters'],
            ['products' => $exemptRule['action_parameters']['products'],
             'phases'   => array_values(array_diff($skipPhases, ['http_request_sbfm'])),
             'ruleset'  => 'current'],
            ['products' => $exemptRule['action_parameters']['products'], 'ruleset' => 'current'],
        ];
        $result = false;
        foreach ($paramShapes as $actionParams) {
            $exemptRule['action_parameters'] = $actionParams;
            if ($rulesetId !== '') {
                if ($firstRuleId !== '') {
                    $result = $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules",
                        $exemptRule + ['position' => ['before' => $firstRuleId]]);
                    if (!is_wp_error($result) && !empty($result['success'])) {
                        break;
                    }
                }
                $result = $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $exemptRule);
            } else {
                $result = $this->postRequest("zones/$zoneId/rulesets", ['name' => 'WPC Firewall Rules', 'kind' => 'zone', 'phase' => 'http_request_firewall_custom', 'rules' => [$exemptRule]]);
            }
            if (!is_wp_error($result) && !empty($result['success'])) {
                break;
            }
        }
        if (is_wp_error($result)) {
            self::wpc_log_cf_rule_error('addCdnHostExemptRule', $result);
            return $result;
        }
        return $result;
    }

    /**
     * Provisions the WAF skip rule through its owner, wps_ic_cf_rules::converge_skip(), which also
     * runs the two companion rules (renderer ASN allow, CDN host exempt) first, as this method did.
     * Kept for the callers that act on the skip rule alone (rotation's missing-rule path, the
     * upgrade one-shot, the agency portal's Refresh). Answers true, the refused write's answer or
     * WP_Error for classifyResult(), or false when the rule is missing and no bypass token could be
     * fetched.
     */
    public function addCdnBypassRule($zoneId) {
        $skip = wps_ic_cf_rules::converge_skip($this, $zoneId);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cf-skip-rule', '', '', ['outcome' => $skip['outcome'], 'twins' => (int) ($skip['twins'] ?? 0)]);
        }
        if (is_wp_error($skip['result'])) {
            self::wpc_log_cf_rule_error('addCdnBypassRule', $skip['result']);
        }
        return $skip['result'];
    }

    // ── the WAF custom-rules phase, for the skip rule's owner (wps_ic_cf_rules) ──────────────────

    /**
     * The zone's http_request_firewall_custom entrypoint as ['ruleset' => id, 'rules' => [...]].
     * A zone that has no entrypoint yet answers ['ruleset' => '', 'rules' => []]; any other failure
     * is the WP_Error, so a failed read is never taken for "rule missing" (the duplicate-rule class).
     */
    public function getFirewallCustomEntrypoint($zoneId)
    {
        $answer = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_firewall_custom/entrypoint");
        if (is_wp_error($answer)) {
            $codes = array_map(function ($e) { return is_array($e) ? (int) ($e['code'] ?? 0) : 0; }, (array) $answer->get_error_data());
            if (in_array(10003, $codes, true) || stripos($answer->get_error_message(), 'could not find entrypoint') !== false) {
                return ['ruleset' => '', 'rules' => []];
            }
            return $answer;
        }
        return ['ruleset' => (string) ($answer['result']['id'] ?? ''),
            'rules' => isset($answer['result']['rules']) && is_array($answer['result']['rules']) ? $answer['result']['rules'] : []];
    }

    /** POST one rule into the entrypoint; with no entrypoint yet, create it holding the rule. */
    public function postFirewallCustomRule($zoneId, $rulesetId, array $rule)
    {
        if ($rulesetId === '') {
            return $this->postRequest("zones/$zoneId/rulesets", ['name' => 'WPC Firewall Rules', 'kind' => 'zone', 'phase' => 'http_request_firewall_custom', 'rules' => [$rule]]);
        }
        return $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $rule);
    }

    public function patchFirewallCustomRule($zoneId, $rulesetId, $ruleId, array $rule)
    {
        return $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/$ruleId", $rule);
    }

    public function deleteFirewallCustomRule($zoneId, $rulesetId, $ruleId)
    {
        return $this->deleteRequest("zones/$zoneId/rulesets/$rulesetId/rules/$ruleId");
    }

    /** True when the skip rule exists under the deprecated firewall/rules API (never rewritten there). */
    public function hasLegacyBypassRule($zoneId)
    {
        $legacy = $this->getRequest('zones/' . $zoneId . '/firewall/rules');
        if (is_wp_error($legacy) || empty($legacy['result'])) {
            return false;
        }
        foreach ($legacy['result'] as $rule) {
            if (!empty($rule['description']) && $rule['description'] === wps_ic_cf_rules::SKIP_DESCRIPTION) {
                return true;
            }
        }
        return false;
    }

    public function wpc_find_bypass_rule($zoneId)
    {
        $found = ['ruleset' => '', 'rule' => '', 'expression' => '', 'legacy' => false, 'shape' => []];
        $ruleset = $this->getRequest("zones/$zoneId/rulesets");
        if (!is_wp_error($ruleset) && !empty($ruleset['result'])) {
            foreach ($ruleset['result'] as $rs) {
                if (!isset($rs['phase']) || $rs['phase'] !== 'http_request_firewall_custom'
                    || !isset($rs['kind']) || $rs['kind'] !== 'zone') {
                    continue;
                }
                $detail = $this->getRequest("zones/$zoneId/rulesets/" . $rs['id']);
                if (!is_wp_error($detail) && !empty($detail['result']['rules'])) {
                    foreach ($detail['result']['rules'] as $rule) {
                        if (!empty($rule['description']) && $rule['description'] === 'Optimizer Bypass [DO NOT EDIT]' && !empty($rule['id'])) {
                            $found['ruleset']    = (string) $rs['id'];
                            $found['rule']       = (string) $rule['id'];
                            $found['expression'] = isset($rule['expression']) ? (string) $rule['expression'] : '';
                            $found['shape']      = $rule;
                            return $found;
                        }
                    }
                }
                break;
            }
        }
        $legacy = $this->getRequest('zones/' . $zoneId . '/firewall/rules');
        if (!is_wp_error($legacy) && !empty($legacy['result'])) {
            foreach ($legacy['result'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === 'Optimizer Bypass [DO NOT EDIT]') {
                    $found['legacy'] = true;
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Rotate the origin-auth bypass token and re-key the WAF Skip rule.
     *
     * The edge pods cache the token for up to 15 minutes, so a straight swap to the new value
     * 403s every in-flight origin fetch until their cache turns over. The rule is therefore
     * widened to old-OR-new first and tightened to new-only on a scheduled follow-up.
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return array|string|false Status array, 'rate-limited', 'legacy-rule', or false
     */
    public function rotateCdnBypassToken($zoneId, $tighten = true)
    {
        $options = get_option(WPS_IC_OPTIONS);
        if (empty($options['api_key'])) {
            error_log('[WPC] rotateCdnBypassToken: no api_key in options');
            return false;
        }
        if (empty($zoneId)) {
            error_log('[WPC] rotateCdnBypassToken: no zone');
            return false;
        }

        // The DEPLOYED rule is the truth for what is currently accepted at the edge — a token
        // stored on this site can have drifted from it (manual edit, restore, failed rotation).
        $deployedRule = $this->wpc_find_bypass_rule($zoneId);
        if ($deployedRule['rule'] === '') {
            if ($deployedRule['legacy']) {
                error_log('[WPC] rotateCdnBypassToken: rule exists only under the deprecated firewall/rules API — not rotating');
                return 'legacy-rule';
            }
            return $this->addCdnBypassRule($zoneId);
        }

        $keysResponse = wp_remote_get(
            WPS_IC_KEYSURL . '?action=rotate_cf_bypass_token&apikey=' . urlencode((string) $options['api_key']),
            ['timeout' => (int) apply_filters('wpc_cf_keys_timeout', 20), 'sslverify' => false]
        );
        if (is_wp_error($keysResponse)) {
            error_log('[WPC] rotateCdnBypassToken: keys request error: ' . $keysResponse->get_error_message());
            return false;
        }
        if ((int) wp_remote_retrieve_response_code($keysResponse) === 429) {
            error_log('[WPC] rotateCdnBypassToken: rate limited (1/apikey/hour)');
            return 'rate-limited';
        }
        $keysBody = json_decode(wp_remote_retrieve_body($keysResponse), true);
        if (empty($keysBody['success']) || empty($keysBody['data']['token'])) {
            error_log('[WPC] rotateCdnBypassToken: unexpected response: ' . wp_remote_retrieve_body($keysResponse));
            return false;
        }
        $newToken  = (string) $keysBody['data']['token'];
        if (function_exists('update_option')) {
            update_option('wpc_cf_bypass_tok2114', $newToken, false);
        }
        $newExpression = !empty($keysBody['data']['expression'])
            ? (string) $keysBody['data']['expression']
            : 'any(http.request.headers["x-origin-auth"][*] == "' . $newToken . '")';

        $unionExpression = $newExpression;
        if ($deployedRule['expression'] !== ''
            && strpos($deployedRule['expression'], $newToken) === false) {
            $unionExpression = '(' . $newExpression . ') or (' . $deployedRule['expression'] . ')';
        }

        $patchResult = $this->wpc_write_bypass_expression($zoneId, $deployedRule, $unionExpression);
        if ($patchResult === false || is_wp_error($patchResult)) {
            error_log('[WPC] rotateCdnBypassToken: WAF re-key failed — the OLD token is still live, so the lane is not broken');
            return false;
        }

        if ($tighten && function_exists('wp_schedule_single_event')) {
            $tightenDelay = (int) apply_filters('wpc_cf_bypass_tighten_delay', 20 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time() + $tightenDelay, 'wpc_cf_bypass_tighten', [(string) $zoneId, $newExpression]);
        }

        return ['rotated' => true, 'union' => $unionExpression, 'expression' => $newExpression,
                'bunnydb_synced' => !empty($keysBody['data']['bunnydb_synced'])];
    }

    public function wpc_write_bypass_expression($zoneId, $deployedRule, $expression)
    {
        if (empty($deployedRule['ruleset']) || empty($deployedRule['rule']) || $expression === '') {
            return false;
        }
        $ruleBody = [
            'action'      => 'skip',
            'description' => 'Optimizer Bypass [DO NOT EDIT]',
            'enabled'     => true,
            'expression'  => $expression,
        ];
        $ruleBody['action_parameters'] = (!empty($deployedRule['shape']['action_parameters']))
            ? $deployedRule['shape']['action_parameters']
            : ['products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf']];
        return $this->patchRequest(
            'zones/' . $zoneId . '/rulesets/' . $deployedRule['ruleset'] . '/rules/' . $deployedRule['rule'],
            $ruleBody
        );
    }

    /**
     * Scheduled follow-up: drop the superseded token once the edge pods have turned over.
     * Re-reads the deployed rule, so a rotation that happened in between is not clobbered.
     */
    public function tightenCdnBypassRule($zoneId, $expression)
    {
        $deployedRule = $this->wpc_find_bypass_rule($zoneId);
        if ($deployedRule['rule'] === '' || $deployedRule['expression'] === $expression) {
            return false;
        }
        if (strpos($deployedRule['expression'], $expression) === false) {
            error_log('[WPC] tightenCdnBypassRule: deployed rule no longer contains the token we minted — a newer rotation won, standing down');
            return false;
        }
        return $this->wpc_write_bypass_expression($zoneId, $deployedRule, $expression);
    }

    /**
     * Remove the CDN bypass WAF rule on CF disconnect.
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return array|false Result from CF API or false on failure
     */
    public function removeCdnBypassRule($zoneId) {
        $removed = false;

        $ruleset = $this->getRequest("zones/$zoneId/rulesets");
        if (!is_wp_error($ruleset) && !empty($ruleset['result'])) {
            foreach ($ruleset['result'] as $rs) {
                if (isset($rs['phase']) && $rs['phase'] === 'http_request_firewall_custom' && isset($rs['kind']) && $rs['kind'] === 'zone') {
                    $detail = $this->getRequest("zones/$zoneId/rulesets/" . $rs['id']);
                    if (!is_wp_error($detail) && !empty($detail['result']['rules'])) {
                        foreach ($detail['result']['rules'] as $rule) {
                            if (!empty($rule['description']) && $rule['description'] === 'Optimizer Bypass [DO NOT EDIT]' && !empty($rule['id'])) {
                                $this->deleteRequest("zones/$zoneId/rulesets/" . $rs['id'] . '/rules/' . $rule['id']);
                                $removed = true;
                            }
                        }
                    }
                    break;
                }
            }
        }

        $url = 'zones/' . $zoneId . '/firewall/rules';
        $existing = $this->getRequest($url);
        if (!is_wp_error($existing) && !empty($existing['result'])) {
            foreach ($existing['result'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === 'Optimizer Bypass [DO NOT EDIT]' && !empty($rule['id'])) {
                    $this->deleteRequest($url . '/' . $rule['id']);
                    $removed = true;
                }
            }
        }
        return $removed;
    }


    public function whitelistIPs($zoneId)
    {
        if (!file_exists(WPC_API_WHITELIST)) {
            error_log('[WPC] whitelistIPs: whitelist-ip.txt not found');
            return false;
        }

        $errors = false;
        $contents = file_get_contents(WPC_API_WHITELIST);
        $ipList = array_filter(array_map('trim', explode("\n", $contents)));


        $failed = [];
        foreach ($ipList as $ip) {
            $success = $this->addIpAccessRule($zoneId, $ip);
            if (is_wp_error($success) || $success === false) {
                $failed[] = $ip;
            }
        }
        if (empty($failed)) {
            return true;
        }
        return new WP_Error('cloudflare_api_error',
            'Unable to whitelist IPs: ' . count($failed) . ' of ' . count($ipList)
            . ' failed (' . implode(', ', array_slice($failed, 0, 3)) . ')', $failed);
    }


    public function removeWhitelistIP($zoneId)
    {
        $r = [];
        $r[] = $this->removeIpAccessRuleByNote($zoneId, 'WP Compress API Endpoint');


        return $r;
    }

    public function removeIpAccessRuleByNote($zoneId, $note)
    {
        $url = 'zones/' . $zoneId . '/firewall/access_rules/rules';
        $allRules = [];
        $page = 1;
        $perPage = 50; // Max allowed is 50

        do {
            // Fetch the current page
            $response = $this->getRequest($url . "?page=$page&per_page=$perPage");

            if (is_wp_error($response)) {
                return $response->get_error_message();
            }

            if (!empty($response['result'])) {
                $allRules = array_merge($allRules, $response['result']);
            }

            $page++;
        } while (!empty($response['result'])); // Continue until no more results

        if (!empty($allRules)) {
            foreach ($allRules as $rule) {
                if (!empty($rule['notes']) && $rule['notes'] === $note) {
                    $r = $this->deleteRequest('zones/' . $zoneId . '/firewall/access_rules/rules/' . $rule['id']);
                }
            }
            return true;
        }

        return false;
    }

    public function deleteRequest($endpoint)
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_request($url, ['method' => 'DELETE', 'headers' => $this->getHeaders(), 'timeout' => (int) apply_filters('wpc_cf_api_timeout', 8),]);

        return $this->processResponse($response);
    }

    public function removeFirewallRule($zoneId, $ip)
    {
        $url = 'zones/' . $zoneId . '/firewall/rules';

        // Fetch existing firewall rules
        $response = $this->getRequest($url);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        if (!empty($response['result'])) {
            $expectedExpression = "ip.src in {$ip}";

            foreach ($response['result'] as $rule) {
                if ($rule['filter']['expression'] === $expectedExpression) {

                    $ruleId = $rule['id'];
                    $this->deleteRequest('zones/' . $zoneId . '/firewall/rules/' . $ruleId);
                    return true;
                }
            }
        }
    }

    public function addFirewallRule($zoneId, $ip)
    {
        $url = 'zones/' . $zoneId . '/firewall/rules';
        $body = ["action" => "allow", "description" => "WP Compress API - IPv6 Range", "filter" => ["expression" => "ip.src in {\"$ip\"}", "paused" => false]];

        $response = $this->postRequest($url, $body);
    }

    public function addIpAccessRule($zoneId, $ip)
    {
        $url = 'zones/' . $zoneId . "/firewall/access_rules/rules";

        $body = ["mode" => 'whitelist', "configuration" => ["target" => "ip", "value" => $ip,], "notes" => 'WP Compress API Endpoint'];

        $response = $this->postRequest($url, $body);
        // Check if the request was successful
        if (is_wp_error($response)) {

            if ($response->get_error_message() == 'firewallaccessrules.api.duplicate_of_existing') {
                $error = 'Invalid request headers - Invalid API Token.';
                return true;
            }

            return false;
        } else {
            return true;
        }
    }

    public function removeIpAccessRule($zoneId, $ip)
    {
        $url = 'zones/' . $zoneId . '/firewall/access_rules/rules';

        // Fetch existing access rules
        $response = $this->getRequest($url);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        if (!empty($response['result'])) {
            foreach ($response['result'] as $rule) {
                if (strpos($ip, ':') !== false) {
                    $expandedIp = $this->expandIPv6($ip);
                }

                if ($rule['configuration']['value'] === $ip || (strpos($ip, ':') !== false && $rule['configuration']['value'] === $expandedIp)) {

                    $ruleId = $rule['id'];
                    $r = 'found ip ' . $ip . "\r\n";
                    #$r = $this->deleteRequest('zones/' . $zoneId . '/firewall/access_rules/rules/' . $ruleId);
                    return $r;
                }
            }
        }
    }

    public function expandIPv6($ip)
    {
        // Split the IPv6 address into segments
        $segments = explode(':', $ip);

        // Handle the "::" shorthand
        if (strpos($ip, '::') !== false) {
            $missingSegments = 8 - count($segments) + 1; // Calculate missing segments
            $expandedSegments = [];
            foreach ($segments as $segment) {
                if ($segment === '') {
                    // Insert missing zero segments
                    for ($i = 0; $i < $missingSegments; $i++) {
                        $expandedSegments[] = '0000';
                    }
                } else {
                    $expandedSegments[] = $segment;
                }
            }
            $segments = $expandedSegments;
        }

        // Pad each segment to ensure 4 digits
        foreach ($segments as &$segment) {
            $segment = str_pad($segment, 4, '0', STR_PAD_LEFT);
        }

        // Join the segments into the fully expanded IPv6 address
        return implode(':', $segments);
    }


    public function setRocketLoader($zoneId, $value)
    {
        if (!in_array($value, ['on', 'off'])) {
            return new WP_Error('invalid_value', 'Value must be "on" or "off"');
        }

        return $this->patchRequest("zones/$zoneId/settings/rocket_loader", ['value' => $value]);
    }


    private function patchRequest($endpoint, $body = [])
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_request($url, ['method' => 'PATCH', 'headers' => $this->getHeaders(), 'body' => json_encode($body), 'timeout' => (int) apply_filters('wpc_cf_api_timeout', 8),]);

        return $this->processResponse($response);
    }


    public function updateWPCCacheConfig($zoneId, $staticAssetsEnabled, $htmlCacheMode)
    {
        $results = [];
        $results['debug'] = [];

        // Log input parameters
        $results['debug']['input'] = ['zoneId' => $zoneId, 'staticAssetsEnabled' => $staticAssetsEnabled, 'htmlCacheMode' => $htmlCacheMode];

        // Determine if any caching is enabled
        $anyCacheEnabled = $staticAssetsEnabled || ($htmlCacheMode !== 'off');
        $results['debug']['anyCacheEnabled'] = $anyCacheEnabled;

        // BYPASS rule - add/update if any cache is enabled, remove current domain if all off
        if ($anyCacheEnabled) {
            $results['debug']['bypass_action'] = 'ensuring current domain is in rule';
            $bypassResult = $this->addCacheRule($zoneId, $this->getBypassRule(), ['index' => 1]);
            $results['bypass'] = $bypassResult;
            if (is_wp_error($bypassResult)) {
                $results['debug']['bypass_error'] = $bypassResult->get_error_message();
            }
        } else {
            $results['debug']['bypass_action'] = 'removing current domain from rule';
            $results['bypass'] = $this->deleteCacheRuleByRef($zoneId, WPC_BYPASS_RULE_REF);
        }

        // STATIC ASSETS rule
        if ($staticAssetsEnabled) {
            $results['debug']['static_action'] = 'ensuring current domain is in rule';
            $staticResult = $this->addCacheRule($zoneId, $this->getStaticAssetsRule());
            $results['static'] = $staticResult;
            if (is_wp_error($staticResult)) {
                $results['debug']['static_error'] = $staticResult->get_error_message();
            }
        } else {
            $results['debug']['static_action'] = 'removing current domain from rule';
            $results['static'] = $this->deleteCacheRuleByRef($zoneId, WPC_STATIC_RULE_REF);
        }

        // HOMEPAGE HTML rule
        if ($htmlCacheMode === 'home' || $htmlCacheMode === 'all') {
            $results['debug']['homepage_action'] = 'ensuring current domain is in rule';
            $homepageResult = $this->addCacheRule($zoneId, $this->getHomepageHTMLRule());
            $results['homepage'] = $homepageResult;
            if (is_wp_error($homepageResult)) {
                $results['debug']['homepage_error'] = $homepageResult->get_error_message();
            } else {
                $results['fullhtml'] = $this->deleteCacheRuleByRef($zoneId, WPC_FULLHTML_RULE_REF);
            }
        } else {
            $results['debug']['homepage_action'] = 'removing current domain from rule';
            $results['homepage'] = $this->deleteCacheRuleByRef($zoneId, WPC_HOMEPAGE_RULE_REF);
        }

        // FULL HTML rule
        if ($htmlCacheMode === 'all') {
            $results['debug']['fullhtml_action'] = 'ensuring current domain is in rule';
            $fullhtmlResult = $this->addCacheRule($zoneId, $this->getFullHTMLRule());
            $results['fullhtml'] = $fullhtmlResult;
            if (is_wp_error($fullhtmlResult)) {
                $results['debug']['fullhtml_error'] = $fullhtmlResult->get_error_message();
            } else {
                $results['homepage'] = $this->deleteCacheRuleByRef($zoneId, WPC_HOMEPAGE_RULE_REF);
            }
        } else {
            $results['debug']['fullhtml_action'] = 'removing current domain from rule';
            $results['fullhtml'] = $this->deleteCacheRuleByRef($zoneId, WPC_FULLHTML_RULE_REF);
        }


        $results['debug']['tiered_cache_action'] = 'enabling (tag purge is tier-agnostic)';
        $results['tiered_cache'] = $this->enableTieredCache($zoneId);

        return $results;
    }


    public function addCacheRule($zoneId, $rule, $position = null)
    {
        $ruleRef = $rule['ref'];

        // Check if rule already exists
        $existingRule = $this->findCacheRuleByRef($zoneId, $ruleRef);

        if ($existingRule) {

            $currentDomains = $this->getCurrentDomainVariations();
            return $this->addDomainsToRule($zoneId, $ruleRef, $currentDomains);
        }

        // Rule doesn't exist - create it (original logic below)
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);

        // If no ruleset exists, create one with this rule
        if (is_wp_error($rulesetId)) {
            return $this->postRequest("zones/$zoneId/rulesets", ['name' => 'Cache Rules', 'kind' => 'zone', 'phase' => 'http_request_cache_settings', 'rules' => [$rule]]);
        }

        // Add position to request body if specified
        $body = $rule;
        if ($position !== null) {
            $body['position'] = $position;
        }

        // Add rule to existing ruleset (SAFE - doesn't replace other rules)
        return $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $body);
    }

    /** PATCH one deployed cache rule by id; the owner (wps_ic_cf_rules) is the only caller. */
    public function patchCacheRule($zoneId, $ruleId, array $rule)
    {
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);
        if (is_wp_error($rulesetId)) { return $rulesetId; }
        return $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/$ruleId", $rule);
    }


    public function listPageRules($zoneId)
    {
        return $this->getRequest("zones/$zoneId/pagerules");
    }


    public function getZoneCacheState($zoneId)
    {
        $out = [];
        $cl = $this->getRequest("zones/$zoneId/settings/cache_level");
        $out['cache_level'] = is_wp_error($cl) ? ('ERR: ' . $cl->get_error_message()) : ($cl['result']['value'] ?? ($cl['value'] ?? 'unknown'));
        $apo = $this->getRequest("zones/$zoneId/settings/automatic_platform_optimization");
        $out['apo'] = is_wp_error($apo) ? ('ERR: ' . $apo->get_error_message()) : ($apo['result']['value'] ?? ($apo['value'] ?? 'unknown'));
        $cr = $this->getRequest("zones/$zoneId/cache/cache_reserve");
        $out['cache_reserve'] = is_wp_error($cr) ? ('ERR: ' . $cr->get_error_message()) : ($cr['result']['value'] ?? ($cr['value'] ?? 'unknown'));


        $tc = $this->getRequest("zones/$zoneId/argo/tiered_caching");
        $out['tiered_caching'] = is_wp_error($tc) ? ('ERR: ' . $tc->get_error_message()) : ($tc['result']['value'] ?? ($tc['value'] ?? 'unknown'));
        $st = $this->getRequest("zones/$zoneId/cache/tiered_cache_smart_topology_enable");
        $out['smart_tiered_topology'] = is_wp_error($st) ? ('ERR: ' . $st->get_error_message()) : ($st['result']['value'] ?? ($st['value'] ?? 'unknown'));


        $rt = $this->getRequest("zones/$zoneId/cache/regional_tiered_cache");
        $out['regional_tiered_cache'] = is_wp_error($rt) ? ('ERR: ' . $rt->get_error_message()) : ($rt['result']['value'] ?? ($rt['value'] ?? 'unknown'));
        $wr = $this->getRequest("zones/$zoneId/workers/routes");
        if (is_wp_error($wr)) {
            $out['worker_routes'] = 'ERR: ' . $wr->get_error_message();
        } else {
            $wrl = (isset($wr['result']) && is_array($wr['result'])) ? $wr['result'] : (is_array($wr) ? $wr : []);
            $out['worker_routes'] = array_map(function ($w) {
                return ['pattern' => $w['pattern'] ?? '', 'script' => $w['script'] ?? ($w['script_name'] ?? '(none)')];
            }, $wrl);
        }
        return $out;
    }


    public function enableTieredCache($zoneId)
    {
        $out = [];
        $r1 = $this->patchRequest("zones/$zoneId/cache/tiered_cache_smart_topology_enable", ['value' => 'on']);
        $out['smart_topology_on'] = is_wp_error($r1) ? ('ERR: ' . $r1->get_error_message()) : (!empty($r1['success']) ? 'ok' : ($r1['errors'][0]['message'] ?? 'fail'));
        $r2 = $this->patchRequest("zones/$zoneId/argo/tiered_caching", ['value' => 'on']);
        $out['tiered_caching_on'] = is_wp_error($r2) ? ('ERR: ' . $r2->get_error_message()) : (!empty($r2['success']) ? 'ok' : ($r2['errors'][0]['message'] ?? 'fail'));
        return $out;
    }

    /**
     * Can this zone key by device AT ALL, independent of what we currently deploy (v7.10.571)?
     *
     * htmlRuleKeyState() reads the LIVE rules, and combined mode deliberately strips cache_key —
     * so it reports "no device key" on every combined site, the floor then keeps them combined,
     * and the capability could never be observed. Circular: .568 made the toggle unreachable on
     * every site rather than only unsafe ones.
     *
     * Break it with a probe that touches no traffic: add a rule that is DISABLED and whose
     * expression cannot match anything, carrying cache_by_device_type. If Cloudflare accepts and
     * echoes the field back, the zone supports it. Delete it either way — including on every
     * failure path, so a rejected probe cannot leave litter in the customer's ruleset.
     */
    public function probeDeviceKeySupport($zoneId)
    {
        $ref = 'wpc-devkey-probe';
        $out = ['supported' => false, 'detail' => ''];
        try {
            // Never matches: a host that cannot exist. Disabled as well, belt and braces.
            $rule = [
                'ref'         => $ref,
                'action'      => 'set_cache_settings',
                'description' => '[DO NOT EDIT] WPC device-key capability probe (auto-removed)',
                'enabled'     => false,
                'expression'  => '(http.host eq "wpc-devkey-probe.invalid")',
                'action_parameters' => [
                    'cache'     => true,
                    'edge_ttl'  => ['mode' => 'respect_origin'],
                    'cache_key' => ['cache_by_device_type' => true],
                ],
            ];
            $add = $this->addCacheRule($zoneId, $rule);
            if (is_wp_error($add)) {
                $out['detail'] = 'add rejected: ' . $add->get_error_message();
                return $out;
            }
            if (is_array($add) && empty($add['success']) && !empty($add['errors'][0]['message'])) {
                $out['detail'] = 'add rejected: ' . $add['errors'][0]['message'];
                return $out;
            }
            $back = $this->findCacheRuleByRef($zoneId, $ref);
            if (!is_array($back)) {
                $out['detail'] = 'probe rule not found after create';
                return $out;
            }
            $ap = (isset($back['action_parameters']) && is_array($back['action_parameters']))
                ? $back['action_parameters'] : [];
            $out['supported'] = !empty($ap['cache_key']['cache_by_device_type']);
            $out['detail'] = $out['supported']
                ? 'zone echoed cache_by_device_type back'
                : 'zone accepted the rule but dropped cache_by_device_type';
            return $out;
        } catch (\Throwable $e) {
            $out['detail'] = 'threw: ' . substr($e->getMessage(), 0, 90);
            return $out;
        } finally {
            // Always clean up, on every path above including the returns.
            try { $this->deleteCacheRuleByRef($zoneId, $ref); } catch (\Throwable $e) {}
        }
    }

    /**
     * Is tiered caching actually ON right now (v7.10.570)?
     *
     * Read, never assume: the crown records whether an eviction was proven WITH tiers active, and
     * that claim is only worth anything if the state is observed at the moment of the proof.
     * Returns true only on an explicit 'on'; any error, absent field, or unreadable response
     * returns false, so an unknown zone can never mint a '+tiered' crown it did not earn.
     */
    public function getTieredCacheState($zoneId)
    {
        try {
            $r = $this->getRequest("zones/$zoneId/argo/tiered_caching");
            if (is_wp_error($r) || empty($r['success'])) {
                return false;
            }
            $v = isset($r['result']['value']) ? strtolower((string) $r['result']['value']) : '';
            return $v === 'on';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function disableTieredCache($zoneId)
    {
        $out = [];
        $r1 = $this->patchRequest("zones/$zoneId/cache/tiered_cache_smart_topology_enable", ['value' => 'off']);
        $out['smart_topology_off'] = is_wp_error($r1) ? ('ERR: ' . $r1->get_error_message()) : (!empty($r1['success']) ? 'ok' : ($r1['errors'][0]['message'] ?? 'fail'));
        $r2 = $this->patchRequest("zones/$zoneId/argo/tiered_caching", ['value' => 'off']);
        $out['tiered_caching_off'] = is_wp_error($r2) ? ('ERR: ' . $r2->get_error_message()) : (!empty($r2['success']) ? 'ok' : ($r2['errors'][0]['message'] ?? 'fail'));
        $r3 = $this->patchRequest("zones/$zoneId/cache/regional_tiered_cache", ['value' => 'off']);
        $out['regional_tiered_off'] = is_wp_error($r3) ? ('ERR: ' . $r3->get_error_message()) : (!empty($r3['success']) ? 'ok' : ($r3['errors'][0]['message'] ?? 'fail'));
        return $out;
    }

    /**
     * Ground truth for whether THIS zone can key HTML per device (v7.10.568).
     *
     * cache_key.cache_by_device_type is an Enterprise-only Cache Rules feature. Without it the
     * edge stores ONE copy of a URL for every device — which is fine while we emit
     * device-universal HTML, and a correctness break the moment we do not: the first device to
     * warm a URL decides what every other device sees. Read the deployed rules rather than
     * assuming the patch we sent was accepted; CF silently keeps the old shape on a rejected
     * field. Returns per-rule state plus a single `devkey` verdict for callers to gate on.
     */
    public function htmlRuleKeyState($zoneId, $deployedRules = null)
    {
        // The caller may pass the ruleset it already holds (converge passes the readback or the
        // ruleset its last write answered with); otherwise read it once for both refs.
        if (!is_array($deployedRules)) {
            $deployedRules = $this->listCacheRules($zoneId);
            if (is_wp_error($deployedRules)) { $deployedRules = []; }
        }
        $out = ['rules' => [], 'devkey' => false, 'anykey' => false, 'found' => 0];
        foreach ([WPC_HOMEPAGE_RULE_REF => 'homepage', WPC_FULLHTML_RULE_REF => 'fullhtml'] as $ref => $label) {
            $rule = null;
            foreach ($deployedRules as $deployedRule) {
                if (isset($deployedRule['ref']) && $deployedRule['ref'] === $ref) { $rule = $deployedRule; break; }
            }
            if (!is_array($rule)) {
                $out['rules'][$label] = ['present' => false];
                continue;
            }
            $ap = (isset($rule['action_parameters']) && is_array($rule['action_parameters']))
                ? $rule['action_parameters'] : [];
            $dev = !empty($ap['cache_key']['cache_by_device_type']);
            $out['rules'][$label] = [
                'present'   => true,
                'devkey'    => $dev,
                'anykey'    => !empty($ap['cache_key']),
                'edge_mode' => isset($ap['edge_ttl']['mode']) ? (string) $ap['edge_ttl']['mode'] : '',
                'edge_def'  => isset($ap['edge_ttl']['default']) ? (int) $ap['edge_ttl']['default'] : 0,
                'enabled'   => !isset($rule['enabled']) || !empty($rule['enabled']),
            ];
            $out['found']++;
            if (!empty($ap['cache_key'])) { $out['anykey'] = true; }
            if ($dev) { $out['devkey'] = true; }
        }
        // Every HTML rule we own must carry it — one device-blind rule is enough to break it.
        if ($out['found'] > 0) {
            $all = true;
            foreach ($out['rules'] as $r) {
                if (!empty($r['present']) && empty($r['devkey'])) { $all = false; break; }
            }
            $out['devkey'] = $all;
        } else {
            $out['devkey'] = false;
        }
        return $out;
    }

    /**
     * Stamp what Cloudflare actually accepted for the HTML rules' cache key. cache_by_device_type
     * is Enterprise-only: a zone without it answers success and keeps the old shape, so the render
     * must read this stamp, never "we sent the patch" (v7.10.568). src='readback' is the evidence
     * the v7.10.682 floor allowlists. $deployedRules: a ruleset Cloudflare answered after the last
     * write (or the readback when nothing was written); null reads the zone.
     */
    public function recordHtmlKeyState($zoneId, $combined, $deployedRules = null)
    {
        try {
            $state = $this->htmlRuleKeyState($zoneId, $deployedRules);
            update_option('wpc_cf_devkey_verified', ['t' => time(), 'devkey' => !empty($state['devkey']) ? 1 : 0, 'src' => 'readback',
                'found' => (int) ($state['found'] ?? 0), 'want' => $combined ? 'combined' : 'split'], false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cf-devkey-readback', '', '', ['devkey' => !empty($state['devkey']) ? 1 : 0, 'found' => (int) ($state['found'] ?? 0), 'want' => $combined ? 'combined' : 'split']);
            }
            return $state;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tiered caching is earned, never assumed: only a purge selftest that re-verified eviction
     * with tiers active (method contains 'tiered', < 8 days old) keeps it on. Everyone else turns
     * it off. Called after every converge and after every keys answer, because keys' refreshCF
     * switches it on for the whole zone (perkzilla 2026-09-24: on after a Refresh that timed out).
     */
    /**
     * Cloudflare's cache variants for the zone ("Vary for Images"): GET zones/{zone}/cache/variants.
     * The value maps a requested file extension to the MIME types the origin may answer it with
     * (developers.cloudflare.com/api/resources/cache/subresources/variants/methods/edit/).
     */
    public function getCacheVariants($zoneId)
    {
        return $this->getRequest("zones/$zoneId/cache/variants");
    }

    /** PATCH zones/{zone}/cache/variants with {"value": $value}; the answer carries the stored value. */
    public function patchCacheVariants($zoneId, array $value)
    {
        return $this->patchRequest("zones/$zoneId/cache/variants", ['value' => $value]);
    }

    public function applyTieredVerdict($zoneId)
    {
        $verified = get_option('wpc_cf_purge_verified');
        $earned = is_array($verified) && strpos((string) ($verified['method'] ?? ''), 'tiered') !== false
            && !empty($verified['t']) && (time() - (int) $verified['t']) < 8 * DAY_IN_SECONDS;
        if ($earned) { return 'earned'; }
        $answers = $this->disableTieredCache($zoneId);
        // Rule: tiered caching stays off until a purge probe proves it is purged with the page.
        // keys' setupCF/refreshCF switch it on, and this writes off without reading first, so every
        // write is logged with Cloudflare's answer per setting.
        if (function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('cf-tiered-off', ['zone' => substr((string) $zoneId, 0, 12),
                'smart' => substr((string) ($answers['smart_topology_off'] ?? ''), 0, 60), 'argo' => substr((string) ($answers['tiered_caching_off'] ?? ''), 0, 60),
                'regional' => substr((string) ($answers['regional_tiered_off'] ?? ''), 0, 60)], false, '');
        }
        return 'off';
    }

    public function findCacheRuleByRef($zoneId, $ref)
    {
        $rules = $this->listCacheRules($zoneId);

        if (is_wp_error($rules)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (isset($rule['ref']) && $rule['ref'] === $ref) {
                return $rule;
            }
        }

        return null;
    }


    public function listCacheRules($zoneId)
    {
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);

        if (is_wp_error($rulesetId)) {
            // If no ruleset exists yet, return empty array
            if ($rulesetId->get_error_code() === 'no_ruleset') {
                return [];
            }

            return $rulesetId;
        }

        $response = $this->getRequest("zones/$zoneId/rulesets/$rulesetId");

        if (is_wp_error($response)) {
            return $response;
        }

        return $response['result']['rules'] ?? [];
    }

    /**
     * Get current site's domain variations (www and non-www)
     *
     * @return array Array of domain variations for current site
     */
    private function getCurrentDomainVariations()
    {
        $domain = $this->getDomain();

        // Handle both www and non-www versions
        if (strpos($domain, 'www.') === 0) {
            $base_domain = substr($domain, 4);
            return [$domain, $base_domain];
        } else {
            $www_domain = 'www.' . $domain;
            return [$domain, $www_domain];
        }
    }


    public function getDomain()
    {
        $current_host = parse_url(get_site_url(), PHP_URL_HOST);

        // Remove www. if present
        if (strpos($current_host, 'www.') === 0) {
            $current_host = substr($current_host, 4);
        }

        return $current_host;
    }


    private function addDomainsToRule($zoneId, $ruleRef, $newDomains)
    {
        // Get existing rule
        $rule = $this->findCacheRuleByRef($zoneId, $ruleRef);
        if (!$rule) {
            return new WP_Error('rule_not_found', "Rule with ref '$ruleRef' not found");
        }

        // Extract current domains
        $currentDomains = $this->extractDomainsFromExpression($rule['expression']);

        // Merge and deduplicate
        $allDomains = array_unique(array_merge($currentDomains, $newDomains));

        // Update expression
        $rule['expression'] = $this->updateDomainsInExpression($rule['expression'], $allDomains);

        // Update the rule
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);
        if (is_wp_error($rulesetId)) {
            return $rulesetId;
        }

        return $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/{$rule['id']}", $rule);
    }


    private function extractDomainsFromExpression($expression)
    {
        // Match pattern: http.host in {"domain1" "domain2" ...}
        if (preg_match('/http\.host in \{([^}]+)\}/', $expression, $matches)) {
            $domainString = $matches[1];
            // Extract quoted strings
            preg_match_all('/"([^"]+)"/', $domainString, $domainMatches);
            return $domainMatches[1];
        }
        return [];
    }


    private function updateDomainsInExpression($expression, $domains)
    {
        // Build new domain list string
        $domainList = array_map(function ($domain) {
            return '"' . $domain . '"';
        }, $domains);
        $domainString = implode(' ', $domainList);

        // Replace the http.host in {...} part
        return preg_replace('/http\.host in \{[^}]+\}/', 'http.host in {' . $domainString . '}', $expression);
    }

    private function getBypassRule()        { return wps_ic_cf_rules::bypass_rule(); }


    public function deleteCacheRuleByRef($zoneId, $ref)
    {
        $currentDomains = $this->getCurrentDomainVariations();
        return $this->removeDomainsFromRule($zoneId, $ref, $currentDomains);
    }


    private function removeDomainsFromRule($zoneId, $ruleRef, $domainsToRemove)
    {
        // Get existing rule
        $rule = $this->findCacheRuleByRef($zoneId, $ruleRef);
        if (!$rule) {
            // Rule doesn't exist, nothing to remove
            return ['success' => true, 'message' => 'Rule not found, nothing to remove'];
        }

        // Extract current domains
        $currentDomains = $this->extractDomainsFromExpression($rule['expression']);

        // Remove specified domains
        $remainingDomains = array_diff($currentDomains, $domainsToRemove);

        // If no domains left, delete the entire rule
        if (empty($remainingDomains)) {
            return $this->deleteCacheRule($zoneId, $rule['id']);
        }

        // Update expression with remaining domains
        $rule['expression'] = $this->updateDomainsInExpression($rule['expression'], $remainingDomains);

        // Update the rule
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);
        if (is_wp_error($rulesetId)) {
            return $rulesetId;
        }

        return $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/{$rule['id']}", $rule);
    }


    public function deleteCacheRule($zoneId, $ruleId)
    {
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);

        if (is_wp_error($rulesetId)) {
            return $rulesetId;
        }

        return $this->deleteRequest("zones/$zoneId/rulesets/$rulesetId/rules/$ruleId");
    }


    private function getCacheRulesRulesetId($zoneId)
    {
        $response = $this->getRequest("zones/$zoneId/rulesets");

        if (is_wp_error($response)) {
            return $response;
        }

        // Find the http_request_cache_settings phase ruleset
        if (!empty($response['result'])) {
            foreach ($response['result'] as $ruleset) {
                if ($ruleset['phase'] === 'http_request_cache_settings') {
                    return $ruleset['id'];
                }
            }
        }

        return new WP_Error('no_ruleset', 'No cache rules ruleset found');
    }

    private function getStaticAssetsRule()  { return wps_ic_cf_rules::static_assets_rule(); }


    private function getHomepageHTMLRule()  { return wps_ic_cf_rules::homepage_html_rule(); }

    private function getFullHTMLRule()      { return wps_ic_cf_rules::full_html_rule(); }


    public function setTieredCache($zoneId, $enabled)
    {
        $value = $enabled ? 'on' : 'off';

        return $this->patchRequest("zones/$zoneId/argo/tiered_caching", ['value' => $value]);
    }

    /**
     * Remove all WP Compress cache rules from a zone
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return array Results of the operation
     */
    public function removeCacheRules($zoneId)
    {
        $results = [];

        // Get current status of all rules
        $status = $this->checkWPCCacheRulesStatus($zoneId);

        if (is_wp_error($status)) {
            return $status;
        }

        // Remove bypass rule if it exists
        if ($status['bypass']) {
            $results['bypass'] = $this->deleteCacheRuleByRef($zoneId, WPC_BYPASS_RULE_REF);
        }

        // Remove static assets rule if it exists
        if ($status['static']) {
            $results['static'] = $this->deleteCacheRuleByRef($zoneId, WPC_STATIC_RULE_REF);
        }

        // Remove homepage HTML rule if it exists
        if ($status['homepage']) {
            $results['homepage'] = $this->deleteCacheRuleByRef($zoneId, WPC_HOMEPAGE_RULE_REF);
        }

        // Remove full HTML rule if it exists
        if ($status['fullhtml']) {
            $results['fullhtml'] = $this->deleteCacheRuleByRef($zoneId, WPC_FULLHTML_RULE_REF);
        }

        return $results;
    }


    public function checkWPCCacheRulesStatus($zoneId)
    {
        return ['bypass' => $this->findCacheRuleByRef($zoneId, WPC_BYPASS_RULE_REF) !== null, 'static' => $this->findCacheRuleByRef($zoneId, WPC_STATIC_RULE_REF) !== null, 'homepage' => $this->findCacheRuleByRef($zoneId, WPC_HOMEPAGE_RULE_REF) !== null, 'fullhtml' => $this->findCacheRuleByRef($zoneId, WPC_FULLHTML_RULE_REF) !== null,];
    }


    public function deleteDNSRecord($zoneId, $recordId)
    {
        return $this->deleteRequest("zones/$zoneId/dns_records/$recordId");
    }


    public function addCfCname($zoneId, $recordId = false)
    {
        if ($recordId) {
            $cdn_subdomain = $recordId;
        } else {
            $cdn_subdomain = $this->getCfCname();
        }

        $target = 'cdn-mc.zapwp.net';

        // Check SSL/TLS setting first
        $sslCheck = $this->checkAndSetSSL($zoneId);
        if (is_wp_error($sslCheck)) {
            return $sslCheck;
        }

        // Check if record already exists in CF
        $existingRecord = $this->findDNSRecord($zoneId, $cdn_subdomain, 'CNAME');

        if ($existingRecord) {
            // Update existing record
            $result = $this->updateDNSRecord($zoneId, $existingRecord['id'], ['type' => 'CNAME', 'name' => $cdn_subdomain, 'content' => $target, 'ttl' => 1, // Automatic
                'proxied' => true]);
        } else {
            // Create new record
            $result = $this->addDNSRecord($zoneId, ['type' => 'CNAME', 'name' => $cdn_subdomain, 'content' => $target, 'ttl' => 1, // Automatic
                'proxied' => true]);
        }

        // If successful, save the CNAME to CF settings
        if (!is_wp_error($result) && !empty($result['success'])) {
			wpc_cf_cname_persist($cdn_subdomain, 'cf-dns-record');
        }

        return $result;
    }


    public function getCfCname()
    {
		$cfCname = get_option(WPS_IC_CF_CNAME);

        // Return custom CNAME if set
        if (!empty($cfCname)) {
            return $cfCname;
        }

        $current_host = $this->getDomain();
        $root_domain = $this->getRootDomain();

        // Check if current host is a subdomain of the root domain
        // e.g., staging.wpcompress.com is a subdomain of wpcompress.com
        if ($current_host !== $root_domain && strpos($current_host, '.' . $root_domain) !== false) {
            // Extract subdomain part (everything before .rootdomain)
            $subdomain = str_replace('.' . $root_domain, '', $current_host);
            $cdn_subdomain = 'cdn-' . $subdomain . '.' . $root_domain;
        } else {
            // No subdomain (or host equals root domain), use cdn.domain.tld
            $cdn_subdomain = 'cdn.' . $root_domain;
        }

        return $cdn_subdomain;
    }

    /**
     * Get the root domain from Cloudflare zone settings
     *
     * @return string Root domain from Cloudflare zone (e.g., 'example.com' or 'example.co.uk')
     */
    private function getRootDomain()
    {
        $cf = get_option(WPS_IC_CF);
        return $cf['zoneName']; // Always set, always accurate
    }

    /**
     * Check SSL/TLS mode and set to Full if needed
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return true|WP_Error True if SSL is correct or was successfully set, WP_Error on failure
     */
    private function checkAndSetSSL($zoneId)
    {
        // Get current SSL/TLS setting
        $response = $this->getRequest("zones/$zoneId/settings/ssl");

        if (is_wp_error($response)) {
            return new WP_Error('cloudflare_ssl_check_error', 'Failed to check SSL/TLS setting: ' . $response->get_error_message());
        }

        // Check if we got a valid response
        if (empty($response['result']) || !isset($response['result']['value'])) {
            return new WP_Error('cloudflare_ssl_check_error', 'Unexpected response while checking SSL/TLS setting');
        }

        $currentSslMode = $response['result']['value'];

        // If already set to 'full' or 'strict', we're good
        if (in_array($currentSslMode, ['full', 'strict'])) {
            return true;
        }

        // Try to set to 'full'
        $setResponse = $this->patchRequest("zones/$zoneId/settings/ssl", ['value' => 'full']);

        if (is_wp_error($setResponse)) {
            return new WP_Error('cloudflare_ssl_set_error', 'Failed to set SSL/TLS to Full: ' . $setResponse->get_error_message());
        }

        // Verify it was set successfully
        if (empty($setResponse['success'])) {
            return new WP_Error('cloudflare_ssl_set_error', 'Failed to set SSL/TLS to Full. Please set SSL/TLS encryption mode to "Full" in your Cloudflare dashboard under SSL/TLS settings.');
        }

        return true;
    }


    public function findDNSRecord($zoneId, $name, $type)
    {
        $response = $this->listDNSRecords($zoneId, ['name' => $name, 'type' => $type]);

        if (is_wp_error($response)) {
            return null;
        }

        if (!empty($response['result']) && is_array($response['result'])) {
            return $response['result'][0];
        }

        return null;
    }


    public function listDNSRecords($zoneId, $filters = [])
    {
        return $this->getRequest("zones/$zoneId/dns_records", $filters);
    }


    public function updateDNSRecord($zoneId, $recordId, $record)
    {
        return $this->putRequest("zones/$zoneId/dns_records/$recordId", $record);
    }


    private function putRequest($endpoint, $body = [])
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_request($url, ['method' => 'PUT', 'headers' => $this->getHeaders(), 'body' => json_encode($body),]);

        return $this->processResponse($response);
    }


    public function addDNSRecord($zoneId, $record)
    {
        // Validate required fields
        $required = ['type', 'name', 'content'];
        foreach ($required as $field) {
            if (empty($record[$field])) {
                return new WP_Error('missing_field', "Required field '$field' is missing");
            }
        }

        // Valid DNS record types
        $validTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];
        if (!in_array(strtoupper($record['type']), $validTypes)) {
            return new WP_Error('invalid_type', 'Invalid DNS record type');
        }

        // Set defaults
        $defaults = ['ttl' => 1, // 1 = automatic
            'proxied' => false];

        $record = array_merge($defaults, $record);

        return $this->postRequest("zones/$zoneId/dns_records", $record);
    }

    /**
     * Remove CDN CNAME record
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return array|WP_Error|null The API response or WP_Error
     */
    public function removeCfCname($zoneId)
    {
        $cfCname = get_option(WPS_IC_CF_CNAME);
        if (!empty($cfCname)) {
            $cdn_subdomain = $cfCname;
            delete_option(WPS_IC_CF_CNAME);
        } else {
            return null;
        }


        return null; // Record doesn't exist, nothing to remove
    }


    public function getZoneAnalytics($from, $to)
    {
        // Get zone ID from settings
        $cf = get_option(WPS_IC_CF);
        if (!$cf || empty($cf['zone'])) {
            return new WP_Error('missing_zone', 'Cloudflare zone ID not found in settings');
        }
        $zoneId = $cf['zone'];

        // Get current hostname and CDN CNAME
        $hostname = $this->getDomain();
        $cdnCname = $this->getCfCname();

        // Generate array of dates to query
        $fromDate = new DateTime($from, new DateTimeZone('UTC'));
        $toDate = new DateTime($to, new DateTimeZone('UTC'));
        $toDate->setTime(23, 59, 59); // End of day

        $combined = [];

        // Query each day individually (API limit is 24 hours per query)
        $currentDate = clone $fromDate;
        while ($currentDate <= $toDate) {
            $dayStart = $currentDate->format('Y-m-d') . 'T00:00:00Z';
            $dayEnd = $currentDate->format('Y-m-d') . 'T23:59:59Z';

            // Fetch for non-www, www, and CDN CNAME
            $nonWwwStats = $this->fetchHostnameStatsForDay($zoneId, $dayStart, $dayEnd, $hostname);
            $wwwStats = $this->fetchHostnameStatsForDay($zoneId, $dayStart, $dayEnd, 'www.' . $hostname);
            $cdnStats = $this->fetchHostnameStatsForDay($zoneId, $dayStart, $dayEnd, $cdnCname);


            if (is_wp_error($nonWwwStats)) {
                return $nonWwwStats;
            }

            if (is_wp_error($wwwStats)) {
                return $wwwStats;
            }

            if (is_wp_error($cdnStats)) {
                return $cdnStats;
            }

            // Combine stats for this day
            $date = $currentDate->format('Y-m-d');
            $combined[$date] = ['bytes' => 0, 'requests' => 0];

            // Add non-www stats
            if (!empty($nonWwwStats)) {
                foreach ($nonWwwStats as $stat) {
                    $combined[$date]['bytes'] += $stat['sum']['edgeResponseBytes'] ?? 0;
                    $combined[$date]['requests'] += $stat['count'] ?? 0;
                }
            }

            // Add www stats
            if (!empty($wwwStats)) {
                foreach ($wwwStats as $stat) {
                    $combined[$date]['bytes'] += $stat['sum']['edgeResponseBytes'] ?? 0;
                    $combined[$date]['requests'] += $stat['count'] ?? 0;
                }
            }

            // Add CDN CNAME stats
            if (!empty($cdnStats)) {
                foreach ($cdnStats as $stat) {
                    $combined[$date]['bytes'] += $stat['sum']['edgeResponseBytes'] ?? 0;
                    $combined[$date]['requests'] += $stat['count'] ?? 0;
                }
            }

            // Move to next day
            $currentDate->modify('+1 day');
        }

        ksort($combined);
        return $combined;
    }


    private function fetchHostnameStatsForDay($zoneId, $dayStart, $dayEnd, $hostname)
    {
        $query = <<<'GQL'
query(
  $zoneTag: String!,
  $datetimeStart: Time!,
  $datetimeEnd: Time!,
  $hostname: String!
) {
  viewer {
    zones(filter: { zoneTag: $zoneTag }) {
      httpRequestsAdaptiveGroups(
        limit: 1,
        filter: {
          datetime_geq: $datetimeStart,
          datetime_leq: $datetimeEnd,
          clientRequestHTTPHost: $hostname
        }
      ) {
        count
        sum {
          edgeResponseBytes
        }
      }
    }
  }
}
GQL;

        $variables = ['zoneTag' => $zoneId, 'datetimeStart' => $dayStart, 'datetimeEnd' => $dayEnd, 'hostname' => $hostname,];

        $response = $this->graphqlRequest($query, $variables);

        if (is_wp_error($response)) {
            return $response;
        }

        // Check for GraphQL errors
        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(function ($error) {
                return $error['message'] ?? 'Unknown GraphQL error';
            }, $response['errors']);

            return new WP_Error('cloudflare_graphql_error', implode(', ', $errorMessages), $response['errors']);
        }

        // Extract the data
        $series = $response['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [];

        return $series;
    }


    // v7.21.19 — CF INSPECTOR: everything support needs to name a challenge source, read
    // with the site's own stored token, rendered by the debug panel. Read-only by design.
    // "Which layer 403'd this font?" stops requiring dashboard access anybody may lack.
    public function wpc_cf_inspect_security_layers($zoneId, $cdnHost = '')
    {
        $out = ['rules' => null, 'bot' => null, 'seclevel' => null, 'events' => null, 'errors' => []];

        $ep = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_firewall_custom/entrypoint");
        if (is_wp_error($ep)) {
            $out['errors'][] = 'rules: ' . $ep->get_error_message();
        } elseif (!empty($ep['result']['rules'])) {
            $out['rules'] = [];
            foreach ($ep['result']['rules'] as $i => $r) {
                $out['rules'][] = [
                    'pos'     => $i,
                    'desc'    => isset($r['description']) ? (string) $r['description'] : '(none)',
                    'action'  => isset($r['action']) ? (string) $r['action'] : '?',
                    'enabled' => !isset($r['enabled']) || !empty($r['enabled']),
                    'ruleset' => !empty($r['action_parameters']['ruleset']) ? (string) $r['action_parameters']['ruleset'] : '',
                    'phases'  => !empty($r['action_parameters']['phases']) ? count((array) $r['action_parameters']['phases']) : 0,
                    'ours'    => isset($r['description']) && strpos((string) $r['description'], 'Optimizer') === 0,
                ];
            }
        } else {
            $out['rules'] = [];
        }

        $bot = $this->getRequest("zones/$zoneId/bot_management");
        if (is_wp_error($bot)) {
            $out['errors'][] = 'bot_management: ' . $bot->get_error_message();
        } elseif (isset($bot['result']) && is_array($bot['result'])) {
            $out['bot'] = array_intersect_key($bot['result'], array_flip(['fight_mode', 'enable_js', 'sbfm_definitely_automated', 'sbfm_likely_automated', 'sbfm_verified_bots']));
        }

        $sl = $this->getRequest("zones/$zoneId/settings/security_level");
        if (!is_wp_error($sl) && isset($sl['result']['value'])) {
            $out['seclevel'] = (string) $sl['result']['value'];
        }

        if ($cdnHost !== '') {
            $q = 'query($zoneTag: string, $start: Time, $end: Time, $host: string) { viewer { zones(filter: {zoneTag: $zoneTag}) {'
               . ' firewallEventsAdaptive(filter: {datetime_geq: $start, datetime_leq: $end, clientRequestHTTPHost: $host},'
               . ' limit: 12, orderBy: [datetime_DESC]) {'
               . ' action source ruleId clientRequestPath datetime clientCountryName userAgent } } } }';
            $vars = ['zoneTag' => (string) $zoneId, 'start' => gmdate('Y-m-d\TH:i:s\Z', time() - 6 * 3600),
                     'end' => gmdate('Y-m-d\TH:i:s\Z'), 'host' => $cdnHost];
            $ev = $this->graphqlRequest($q, $vars);
            if (is_wp_error($ev)) {
                $out['errors'][] = 'events: ' . $ev->get_error_message();
            } elseif (!empty($ev['errors'])) {
                $out['errors'][] = 'events: ' . (isset($ev['errors'][0]['message']) ? (string) $ev['errors'][0]['message'] : 'graphql error');
            } else {
                $out['events'] = isset($ev['data']['viewer']['zones'][0]['firewallEventsAdaptive'])
                    ? (array) $ev['data']['viewer']['zones'][0]['firewallEventsAdaptive'] : [];
            }
        }
        return $out;
    }

    private function graphqlRequest($query, $variables = [])
    {
        $url = 'https://api.cloudflare.com/client/v4/graphql';

        $response = wp_remote_post($url, ['headers' => $this->getHeaders(), 'body' => json_encode(['query' => $query, 'variables' => $variables]), 'timeout' => 30,]);

        return $this->processResponse($response);
    }

    /**
     * Check if API token has required privileges by testing actual API calls
     *
     * @param string $zoneId Cloudflare Zone ID to test permissions against
     * @return true|WP_Error True if all privileges work, WP_Error with missing privileges if not
     */
    public static function wpc_log_cf_rule_error($lane, $err)
    {
        $msg = is_wp_error($err) ? (string) $err->get_error_message() : (string) $err;
        $cls = self::classifyResult($err);
        $perm = is_array($cls) && ($cls['mode'] ?? '') === 'permission';
        $line = '[WPC] ' . $lane . ': ' . ($perm
            ? 'the Cloudflare token lacks Zone → Zone WAF → Edit, so the optional security-bypass rule is skipped (add the permission in Cloudflare and reconnect). CF said: '
            : 'CF API error: ') . $msg;
        if (function_exists('wpc_is_admin_lane_held') && function_exists('wpc_hold_admin_lane')) {
            if (wpc_is_admin_lane_held('wpc_cf_rule_log71_' . $lane)) {
                return;
            }
            wpc_hold_admin_lane('wpc_cf_rule_log71_' . $lane, DAY_IN_SECONDS);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cf-rule-' . ($perm ? 'unauthorized' : 'error'), '', '', ['lane' => $lane, 'msg' => substr($msg, 0, 120)]);
        }
        error_log($line);
    }

    public function checkPrivileges($zoneId = null)
    {
        // If no zone ID provided, try to get from settings
        if (!$zoneId) {
            $cf = get_option(WPS_IC_CF);
            $zoneId = $cf['zone'] ?? null;
        }

        if (!$zoneId) {
            return new WP_Error('cloudflare_missing_zone', 'Zone ID is required to check permissions');
        }

        $missingPermissions = [];
        $permissionTests = [];


        // v7.10.503 — POSITIVE PROOF ONLY. Every row scored "granted" as !isPermissionError(): the
        // ABSENCE of one of three codes [9109,10000,1095]. A deleted token returns 6003 with an
        // error_chain of 6111, and a 401 challenge page hits processResponse()'s non-json branch,
        // which builds a WP_Error with NO error data — so get_error_data() is null, is_array() fails,
        // and the row scored GRANTED. Receipted: key deleted, panel showed 4x "Granted" while
        // claiming "verified just now via live Cloudflare API checks".
        $cfOk = function ($response) {
            if (is_wp_error($response) || !is_array($response)) {
                return false;
            }
            return !isset($response['success']) || $response['success'] === true;
        };

        // An invalid/deleted token is not a permission problem — it makes every verdict unknowable.
        $authFail = function ($response) {
            if (!is_wp_error($response)) {
                return false;
            }
            $codes = [];
            foreach ((array) $response->get_error_data() as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $codes[] = (int) ($e['code'] ?? 0);
                foreach ((array) ($e['error_chain'] ?? []) as $c) {
                    if (is_array($c)) {
                        $codes[] = (int) ($c['code'] ?? 0);
                    }
                }
            }
            // 9109 ("unauthorized to access requested resource") and 1095 are PERMISSION denials,
            // deliberately excluded: a token merely missing one scope must read as that permission
            // missing, never as an invalid token. Only genuine credential rejections belong here.
            foreach ([6003, 6103, 6111, 9103, 9106, 10000] as $a) {
                if (in_array($a, $codes, true)) {
                    return true;
                }
            }
            // The non-json branch carries no error data at all; a 401 challenge lands there.
            return !$codes && strpos((string) $response->get_error_message(), 'non-json') !== false;
        };

        $isPermissionError = function ($response) {
            if (!is_wp_error($response)) {
                return false;
            }
            $data = $response->get_error_data();
            if (is_array($data)) {
                foreach ($data as $error) {
                    if (is_array($error) && in_array((int) ($error['code'] ?? 0), [9109, 10000, 1095], true)) {
                        return true;
                    }
                }
            }
            return false;
        };

        // Test 1: Zone Read
        $zonesResponse = $this->getRequest('zones', ['per_page' => 1]);
        if ($authFail($zonesResponse)) {
            return new WP_Error('cloudflare_invalid_token',
                'Cloudflare rejected the API token itself, so no permission can be verified. Re-enter a valid token, then re-check.');
        }

        if (!$cfOk($zonesResponse)) {
            $missingPermissions[] = 'Zone - Zone - Read';
            $permissionTests['Zone Read'] = 'Failed';
        } else {
            $permissionTests['Zone Read'] = 'OK';
        }

        // Test 2: Zone Settings Edit
        $settingsResponse = $this->getRequest("zones/{$zoneId}/settings/rocket_loader");
        if (!$cfOk($settingsResponse)) {
            $missingPermissions[] = 'Zone - Zone Settings - Edit';
            $permissionTests['Zone Settings Edit'] = 'Failed';
        } else {
            $permissionTests['Zone Settings Edit'] = 'OK';
        }

        // Test 3: Cache Purge
        // Use POST with minimal valid data to test permission without actually purging
        $cacheResponse = $this->postRequest("zones/{$zoneId}/purge_cache", ['files' => []]);


        // Deliberately malformed probe (files:[]) — success is impossible, so absence of a
        // permission error is the only signal. An auth failure still disqualifies it.
        $hasCachePurgePermission = !$isPermissionError($cacheResponse) && !$authFail($cacheResponse);

        if (!$hasCachePurgePermission) {
            $missingPermissions[] = 'Zone - Cache Purge - Purge';
            $permissionTests['Cache Purge'] = 'Failed';
        } else {
            $permissionTests['Cache Purge'] = 'OK';
        }

        // Test 4: Firewall Services Edit
        $firewallResponse = $this->getRequest("zones/{$zoneId}/firewall/access_rules/rules", ['per_page' => 1]);
        if (!$cfOk($firewallResponse)) {
            $missingPermissions[] = 'Zone - Firewall Services - Edit';
            $permissionTests['Firewall Services Edit'] = 'Failed';
        } else {
            $permissionTests['Firewall Services Edit'] = 'OK';
        }

        // Test 5: DNS Edit
        $dnsResponse = $this->getRequest("zones/{$zoneId}/dns_records", ['per_page' => 1]);
        if (!$cfOk($dnsResponse)) {
            $missingPermissions[] = 'Zone - DNS - Edit';
            $permissionTests['DNS Edit'] = 'Failed';
        } else {
            $permissionTests['DNS Edit'] = 'OK';
        }


        $zoneDetailsResponse = $this->getRequest("zones/{$zoneId}");
        if (!$cfOk($zoneDetailsResponse)) {
            $missingPermissions[] = 'Zone - Analytics - Read';
            $permissionTests['Analytics Read'] = 'Failed';
        } else {


            $permissionTests['Analytics Read'] = 'OK (basic check)';
        }

        // Test 7: Cache Rules (Rulesets)
        $rulesetsResponse = $this->getRequest("zones/{$zoneId}/rulesets");
        if (!$cfOk($rulesetsResponse)) {
            $missingPermissions[] = 'Zone - Cache Rules - Edit';
            $permissionTests['Cache Rules Edit'] = 'Failed';
        } else {
            $permissionTests['Cache Rules Edit'] = 'OK';
        }

        // v7.21.20 — the WAF phase needs its OWN scope: a token can list rulesets (Cache Rules
        // read) yet be "not authorized" on http_request_firewall_custom, so every Optimizer
        // Bypass / CDN Host Exempt write fails while this panel says all-green (bakes: months
        // of unauthorized rule writes behind an all-OK permissions screen — the .503 lesson
        // again). Positive proof: read the phase entrypoint; CF error 10003 ("could not find
        // entrypoint") is authorized-but-empty and counts as OK.
        $wafPhaseResponse = $this->getRequest("zones/{$zoneId}/rulesets/phases/http_request_firewall_custom/entrypoint");
        $wafPhaseOk = $cfOk($wafPhaseResponse);
        if (!$wafPhaseOk && is_wp_error($wafPhaseResponse)) {
            foreach ((array) $wafPhaseResponse->get_error_data() as $wafError) {
                if (is_array($wafError) && isset($wafError['code']) && (int) $wafError['code'] === 10003) {
                    $wafPhaseOk = true;
                    break;
                }
            }
        }
        if (!$wafPhaseOk) {
            $missingPermissions[] = 'Zone - Zone WAF - Edit';
            $permissionTests['Zone WAF Edit'] = 'Failed';
        } else {
            $permissionTests['Zone WAF Edit'] = 'OK';
        }


        $criticalRefs = ['Zone - Zone - Read', 'Zone - Cache Purge - Purge'];


        // Permissions row = [Zone] [Group] [Access]).
        $cfLabel = [
            'Zone - Zone - Read'              => 'Zone → Read',
            'Zone - Cache Purge - Purge'      => 'Cache Purge → Purge',
            'Zone - Zone Settings - Edit'     => 'Zone Settings → Edit',
            'Zone - Firewall Services - Edit' => 'Firewall Services → Edit',
            'Zone - DNS - Edit'               => 'DNS → Edit',
            'Zone - Analytics - Read'         => 'Analytics → Read',
            'Zone - Cache Rules - Edit'       => 'Cache Rules → Edit',
            'Zone - Zone WAF - Edit'          => 'Zone WAF → Edit',
        ];
        $featureFor = [
            'Zone - Zone Settings - Edit'     => 'auto Rocket-Loader conflict handling',
            'Zone - Firewall Services - Edit' => 'firewall / access rules',
            'Zone - DNS - Edit'               => 'automatic CNAME setup',
            'Zone - Analytics - Read'         => 'the Cloudflare analytics panel',
            'Zone - Cache Rules - Edit'       => 'edge-cache optimization rules',
            'Zone - Zone WAF - Edit'          => 'the Optimizer Bypass + CDN Host Exempt rules — without it Cloudflare may challenge our image servers and asset fetches',
        ];
        $critical_missing = [];
        $optional_missing = [];
        foreach ($missingPermissions as $perm) {
            $label = $cfLabel[$perm] ?? $perm;
            if (in_array($perm, $criticalRefs, true)) {
                $critical_missing[] = $label;
            } else {
                $optional_missing[] = $label . (isset($featureFor[$perm]) ? ' (enables ' . $featureFor[$perm] . ')' : '');
            }
        }

        // The token's identity is read here and nowhere else, so the panel can name the token it
        // checked without a Cloudflare call on every page load. An account-owned token (cfat_)
        // answers only on the account path, whose id the zone details carry.
        $accountId = (is_array($zoneDetailsResponse) && !empty($zoneDetailsResponse['result']['account']['id']))
            ? (string) $zoneDetailsResponse['result']['account']['id'] : '';

        return [
            'ok'               => empty($critical_missing),
            'critical_missing' => $critical_missing,
            'optional_missing' => $optional_missing,
            'tests'            => $permissionTests,
            'token'            => $this->verifyToken($accountId),
        ];
    }

    /**
     * The identity of the stored token: {id, status, t}. Only the id Cloudflare gives the token is
     * kept or logged, never the token value. A token Cloudflare will not describe reads as
     * status 'unverified' with an empty id, so the panel never shows an id it did not confirm.
     */
    public function verifyToken($accountId = '')
    {
        $paths = ['user/tokens/verify'];
        if ($accountId !== '') {
            $paths[] = 'accounts/' . rawurlencode($accountId) . '/tokens/verify';
        }
        $why = '';
        foreach ($paths as $path) {
            $answer = $this->getRequest($path);
            if (is_array($answer) && !empty($answer['result']['id'])) {
                $out = [
                    'id'     => (string) $answer['result']['id'],
                    'status' => (string) ($answer['result']['status'] ?? ''),
                    't'      => time(),
                ];
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('cf-token-verified', '', '', ['id_prefix' => substr($out['id'], 0, 6), 'status' => $out['status']]);
                }
                return $out;
            }
            $why = is_wp_error($answer) ? $answer->get_error_code() : 'no-id';
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cf-token-verify-failed', '', '', ['why' => $why, 'paths' => count($paths)]);
        }
        return ['id' => '', 'status' => 'unverified', 't' => time()];
    }


    public function getZoneAnalyticsUnfiltered($from, $to)
    {
        // Get zone ID from settings
        $cf = get_option(WPS_IC_CF);
        if (!$cf || empty($cf['zone'])) {
            return new WP_Error('missing_zone', 'Cloudflare zone ID not found in settings');
        }
        $zoneId = $cf['zone'];

        // Format dates for GraphQL
        $fromDate = new DateTime($from, new DateTimeZone('UTC'));
        $toDate = new DateTime($to, new DateTimeZone('UTC'));

        $dateStart = $fromDate->format('Y-m-d');
        $dateEnd = $toDate->format('Y-m-d');

        $query = <<<'GQL'
query(
  $zoneTag: String!,
  $dateStart: Date!,
  $dateEnd: Date!
) {
  viewer {
    zones(filter: { zoneTag: $zoneTag }) {
      httpRequests1dGroups(
        limit: 1000,
        filter: {
          date_geq: $dateStart,
          date_leq: $dateEnd
        }
      ) {
        dimensions {
          date
        }
        sum {
          requests
          bytes
          cachedBytes
          cachedRequests
        }
      }
    }
  }
}
GQL;

        $variables = ['zoneTag' => $zoneId, 'dateStart' => $dateStart, 'dateEnd' => $dateEnd,];

        $response = $this->graphqlRequest($query, $variables);

        if (is_wp_error($response)) {
            return $response;
        }

        // Check for GraphQL errors
        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(function ($error) {
                return $error['message'] ?? 'Unknown GraphQL error';
            }, $response['errors']);

            return new WP_Error('cloudflare_graphql_error', implode(', ', $errorMessages), $response['errors']);
        }

        // Extract and format the data
        $series = $response['data']['viewer']['zones'][0]['httpRequests1dGroups'] ?? [];

        $formatted = [];
        foreach ($series as $dataPoint) {
            $date = $dataPoint['dimensions']['date'] ?? null;
            if ($date) {
                // Extract INTEGER values directly, not arrays
                $formatted[$date] = ['bytes' => (int)($dataPoint['sum']['bytes'] ?? 0), 'requests' => (int)($dataPoint['sum']['requests'] ?? 0), 'cached_bytes' => (int)($dataPoint['sum']['cachedBytes'] ?? 0), 'cached_requests' => (int)($dataPoint['sum']['cachedRequests'] ?? 0),];
            }
        }

        ksort($formatted);
        return $formatted;
    }


    public function getDomainsInRule($zoneId, $ruleRef)
    {
        $rule = $this->findCacheRuleByRef($zoneId, $ruleRef);

        if (!$rule) {
            return new WP_Error('rule_not_found', "Rule with ref '$ruleRef' not found");
        }

        return $this->extractDomainsFromExpression($rule['expression']);
    }


    public function formatError($wp_error, $context = '', $required_permission = '')
    {
        if (!is_wp_error($wp_error)) {
            return null;
        }

        $error_data = $wp_error->get_error_data();
        $error_code = null;
        $error_message = '';

        // Extract error code and message
        if (!empty($error_data[0]['code'])) {
            $error_code = $error_data[0]['code'];
        }
        if (!empty($error_data[0]['message'])) {
            $error_message = $error_data[0]['message'];
        }

        // Check if it's a permission/authentication error
        $permission_codes = [9109, 10000, 1095, 9103];
        if (in_array($error_code, $permission_codes)) {
            $msg = $context ? "{$context}: API token is missing required permissions" : "API token is missing required permissions";
            if ($required_permission) {
                $msg .= " ({$required_permission})";
            }
            return $msg;
        }

        // For other errors, return the original message or a fallback
        if (empty($error_message)) {
            $error_message = $wp_error->get_error_message();
        }

        return $context ? "{$context}: {$error_message}" : $error_message;
    }


    public function ensureWpcConfigInjection($zoneId, $signedValue)
    {
        if (empty($signedValue) || !is_string($signedValue)) {
            return new WP_Error('wpc_no_signed_value', 'Refusing to write an empty x-wpc-config injection rule');
        }

        $cdnHost = $this->getCfCname();
        if (empty($cdnHost)) {
            return new WP_Error('wpc_no_cdn_host', 'No CDN CNAME resolved for this zone');
        }

        $rule = [
            'ref'         => WPC_CONFIG_INJECT_RULE_REF,
            'description' => '[DO NOT EDIT] WP Compress signed config injection',
            'expression'  => '(http.host eq "' . $cdnHost . '")',
            'action'      => 'rewrite',
            'enabled'     => true,
            'action_parameters' => [
                'headers' => [
                    'apikey'       => ['operation' => 'remove'],
                    'x-wpc-config' => ['operation' => 'set', 'value' => $signedValue],
                ],
            ],
        ];

        // Find-or-create the late_transform entrypoint ruleset, then update-or-add the rule by ref.
        $rulesetId = $this->getTransformRulesRulesetId($zoneId);
        if (is_wp_error($rulesetId)) {
            // No transform ruleset yet → create one carrying this single rule (SAFE — new ruleset).
            return $this->postRequest("zones/$zoneId/rulesets", [
                'name'  => 'WP Compress Transform Rules',
                'kind'  => 'zone',
                'phase' => 'http_request_late_transform',
                'rules' => [$rule],
            ]);
        }

        // Ruleset exists — update our rule in place if present (no duplicates), else append it.
        $existing = $this->findTransformRuleByRef($zoneId, $rulesetId, WPC_CONFIG_INJECT_RULE_REF);
        if ($existing && !empty($existing['id'])) {
            return $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/{$existing['id']}", $rule);
        }
        return $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $rule);
    }


    public function removeWpcConfigInjection($zoneId)
    {
        $rulesetId = $this->getTransformRulesRulesetId($zoneId);
        if (is_wp_error($rulesetId)) {
            return false;
        }

        $existing = $this->findTransformRuleByRef($zoneId, $rulesetId, WPC_CONFIG_INJECT_RULE_REF);
        if ($existing && !empty($existing['id'])) {
            return $this->deleteRequest("zones/$zoneId/rulesets/$rulesetId/rules/{$existing['id']}");
        }
        return false;
    }


    private function getTransformRulesRulesetId($zoneId)
    {
        $response = $this->getRequest("zones/$zoneId/rulesets");
        if (is_wp_error($response)) {
            return $response;
        }

        if (!empty($response['result'])) {
            foreach ($response['result'] as $ruleset) {
                if (isset($ruleset['phase']) && $ruleset['phase'] === 'http_request_late_transform') {
                    return $ruleset['id'];
                }
            }
        }

        return new WP_Error('no_ruleset', 'No http_request_late_transform ruleset found');
    }


    private function findTransformRuleByRef($zoneId, $rulesetId, $ref)
    {
        $response = $this->getRequest("zones/$zoneId/rulesets/$rulesetId");
        if (is_wp_error($response)) {
            return null;
        }

        $rules = $response['result']['rules'] ?? [];
        foreach ($rules as $rule) {
            if (isset($rule['ref']) && $rule['ref'] === $ref) {
                return $rule;
            }
        }

        return null;
    }

}
if (function_exists('add_action') && !has_action('wpc_cf_bypass_tighten')) {
    add_action('wpc_cf_bypass_tighten', function ($zoneId = '', $expression = '') {
        if ($zoneId === '' || $expression === '' || !class_exists('WPC_CloudflareAPI')
            || !defined('WPS_IC_CF') || !function_exists('get_option')) {
            return;
        }
        $cfSettings = get_option(WPS_IC_CF);
        if (empty($cfSettings['token'])) {
            return;
        }
        $cloudflare = new WPC_CloudflareAPI($cfSettings['token']);
        $cloudflare->tightenCdnBypassRule((string) $zoneId, (string) $expression);
    }, 10, 2);
}
