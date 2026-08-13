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
        ];
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


#define('WPC_CF_TOKEN', 'vPn-BuupnJ3VmJUAVPt0V7BaeWvFID_ljh_2UMoz');

// Rule identifiers for WP Compress plugin
const WPC_BYPASS_RULE_REF = 'wpc-bypass-cache';
const WPC_STATIC_RULE_REF = 'wpc-static-assets';
const WPC_HOMEPAGE_RULE_REF = 'wpc-homepage-html';
const WPC_FULLHTML_RULE_REF = 'wpc-full-html';
const WPC_CONFIG_INJECT_RULE_REF = 'wpc-config-inject'; // CF Piece 2 (signed x-wpc-config), scaffold
const WPC_ROBOTS_RULE_REF = 'wpc-robots-sitemap';


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

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'updateCFConfig', 'token' => $token, 'zone' => $zoneInput, 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'apikey' => $apikey, 'time' => microtime(true), 'staticAssets' => $staticAssetsEnabled, 'htmlCache' => $htmlCacheMode], ['timeout' => (int) apply_filters('wpc_cf_keys_timeout', 15)]);

        if (!empty($body)) {
            $data = (array)$body->data;
            return $data;
        }

        return false;
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
                        'detail' => 'Cloudflare rejected it — your API token is missing a required permission (needs Firewall Services: Edit, Cache Rules: Edit, and Cache Purge). CF said: ' . $msg];
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
        for ($i = 0; $i < max(1, (int) $tries); $i++) {
            $r = wp_remote_get($probe, ['timeout' => max(2, (int) $timeout), 'sslverify' => false, 'redirection' => 0]);
            if (!is_wp_error($r)) {
                $code    = (int) wp_remote_retrieve_response_code($r);
                $body    = (string) wp_remote_retrieve_body($r);
                $cfRay   = wp_remote_retrieve_header($r, 'cf-ray');
                $ctype   = (string) wp_remote_retrieve_header($r, 'content-type');
                $through_cf = !empty($cfRay);
                $site_not_found = (stripos($body, 'Site not found') !== false) || (stripos($body, '"hasApikey":false') !== false);


                $resolved_status = ($code === 404) || ($code === 200 && stripos($ctype, 'image/') !== false) || in_array($code, [301, 302, 307, 308], true);
                if ($through_cf && !$site_not_found && $resolved_status) {
                    return true;
                }
            }
            if ($i + 1 < $tries) {
                wpc_diag_sleep(2, 'cf-cname-verify');
            }
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

        return $body['data']['token'];
    }


    // The crit renderer runs real-browser UAs by design, so CF bot products can only admit it
    // by SOURCE NETWORK. All crit-push pods egress from one ASN (Datacamp); pod IPs churn on
    // redeploys, so the rule keys on the ASN, never an IP list. Skip is scoped to
    // bot-protection products, NOT the full WAF. Known limit: free-plan plain Bot Fight Mode
    // honors no exceptions — that zone needs BFM toggled off by hand.
    public function addBrowserTtlRules($zoneId)
    {
        if (empty($zoneId) || !apply_filters('wpc_cf_browser_ttl', true)) {
            return false;
        }
        if (get_transient('wpc_cf_bttl_done_' . $zoneId)) {
            return true;
        }
        // The out-of-the-box fix for header-less origins (nginx ignores .htaccess): browser
        // TTL set at the edge. Immutable-content media gets a year; css/js a week (matches
        // the htaccess rules); HTML is never touched — neither expression can reach it
        $wpc_rules772 = [
            [
                'action'            => 'set_cache_settings',
                'description'       => 'WPC Browser TTL Media [DO NOT EDIT]',
                'enabled'           => true,
                'expression'        => 'http.request.uri.path.extension in {"jpg" "jpeg" "png" "gif" "svg" "webp" "avif" "ico" "woff" "woff2" "ttf" "otf"}',
                'action_parameters' => ['browser_ttl' => ['mode' => 'override_origin', 'default' => 31536000]],
            ],
            [
                'action'            => 'set_cache_settings',
                'description'       => 'WPC Browser TTL Static [DO NOT EDIT]',
                'enabled'           => true,
                'expression'        => 'http.request.uri.path.extension in {"css" "js"}',
                'action_parameters' => ['browser_ttl' => ['mode' => 'override_origin', 'default' => 604800]],
            ],
        ];
        $wpc_have772 = [];
        $existing = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_cache_settings/entrypoint");
        if (!is_wp_error($existing) && !empty($existing['result']['rules'])) {
            foreach ($existing['result']['rules'] as $rule) {
                if (!empty($rule['description'])) {
                    $wpc_have772[$rule['description']] = 1;
                }
            }
        }
        $ruleset = $this->getRequest("zones/$zoneId/rulesets");
        $rulesetId = '';
        if (!is_wp_error($ruleset) && !empty($ruleset['result'])) {
            foreach ($ruleset['result'] as $rs) {
                if (isset($rs['phase']) && $rs['phase'] === 'http_request_cache_settings' && isset($rs['kind']) && $rs['kind'] === 'zone') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }
        $wpc_ok772 = true;
        foreach ($wpc_rules772 as $wpc_r772) {
            if (isset($wpc_have772[$wpc_r772['description']])) {
                continue;
            }
            if ($rulesetId !== '') {
                $result = $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $wpc_r772);
            } else {
                $result = $this->postRequest("zones/$zoneId/rulesets", ['name' => 'WPC Cache Rules', 'kind' => 'zone', 'phase' => 'http_request_cache_settings', 'rules' => [$wpc_r772]]);
                if (!is_wp_error($result) && !empty($result['result']['id'])) {
                    $rulesetId = $result['result']['id'];
                }
            }
            if (is_wp_error($result) || empty($result['success'])) {
                $wpc_ok772 = false;
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($wpc_ok772 ? 'cf-browser-ttl' : 'cf-browser-ttl-failed', '', '', [
                'zone' => substr((string) $zoneId, 0, 12),
            ]);
        }
        if ($wpc_ok772) {
            set_transient('wpc_cf_bttl_done_' . $zoneId, 1, 12 * HOUR_IN_SECONDS);
        }
        return $wpc_ok772;
    }

    public function addRendererAllowRule($zoneId)
    {
        if (empty($zoneId) || !apply_filters('wpc_cf_renderer_allow', true)) {
            return false;
        }
        if (get_transient('wpc_cf_rr_done_' . $zoneId)) {
            return true;
        }
        $wpc_asn760 = (int) apply_filters('wpc_crit_renderer_asn', 60068);
        $wpc_desc760 = 'WPC Renderer Allow [DO NOT EDIT]';
        $wafRules = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_firewall_custom/entrypoint");
        if (!is_wp_error($wafRules) && !empty($wafRules['result']['rules'])) {
            foreach ($wafRules['result']['rules'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === $wpc_desc760) {
                    set_transient('wpc_cf_rr_done_' . $zoneId, 1, 12 * HOUR_IN_SECONDS);
                    return true;
                }
            }
        }
        $wpc_rule760 = [
            'action'            => 'skip',
            'description'       => $wpc_desc760,
            'enabled'           => true,
            'expression'        => 'ip.src.asnum eq ' . $wpc_asn760,
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
            $result = $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $wpc_rule760);
        } else {
            $result = $this->postRequest("zones/$zoneId/rulesets", ['name' => 'WPC Firewall Rules', 'kind' => 'zone', 'phase' => 'http_request_firewall_custom', 'rules' => [$wpc_rule760]]);
        }
        $wpc_ok760 = !is_wp_error($result) && !empty($result['success']);
        if (!$wpc_ok760) {
            // Some plans reject the sbfm phase / scoped products — the ASN Access allow is the
            // older API with the broadest token acceptance. Admits the whole ASN but only as
            // an ALLOW, never a WAF skip.
            $result = $this->postRequest("zones/$zoneId/firewall/access_rules/rules", [
                'mode'          => 'whitelist',
                'configuration' => ['target' => 'asn', 'value' => 'AS' . $wpc_asn760],
                'notes'         => $wpc_desc760,
            ]);
            $wpc_ok760 = !is_wp_error($result) && !empty($result['success']);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($wpc_ok760 ? 'cf-renderer-allow' : 'cf-renderer-allow-failed', '', '', [
                'zone' => substr((string) $zoneId, 0, 12),
                'asn'  => $wpc_asn760,
                'note' => $wpc_ok760 ? '' : 'both writes refused; if free-plan Bot Fight Mode is ON it honors no exceptions',
            ]);
        }
        if ($wpc_ok760) {
            set_transient('wpc_cf_rr_done_' . $zoneId, 1, 12 * HOUR_IN_SECONDS);
        }
        return $wpc_ok760 ? true : $result;
    }

    public function addCdnBypassRule($zoneId) {
        try {
            $this->addRendererAllowRule($zoneId);
        } catch (\Throwable $e) {
        }
        try {
            $this->addBrowserTtlRules($zoneId);
        } catch (\Throwable $e) {
        }
        $token = $this->getCdnBypassToken();
        if (!$token) {
            error_log('[WPC] addCdnBypassRule: failed to get bypass token');
            return false;
        }

        $expression = 'any(http.request.headers["x-origin-auth"][*] == "' . $token . '")';


        // "firewallrules.api.maintenance_mode ... no longer accepts modifications"), so writes


        // Already provisioned under either API?
        $wafRules = $this->getRequest("zones/$zoneId/rulesets/phases/http_request_firewall_custom/entrypoint");
        if (!is_wp_error($wafRules) && !empty($wafRules['result']['rules'])) {
            foreach ($wafRules['result']['rules'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === 'Optimizer Bypass [DO NOT EDIT]') {
                    return true;
                }
            }
        }
        $legacy = $this->getRequest('zones/' . $zoneId . '/firewall/rules');
        if (!is_wp_error($legacy) && !empty($legacy['result'])) {
            foreach ($legacy['result'] as $rule) {
                if (!empty($rule['description']) && $rule['description'] === 'Optimizer Bypass [DO NOT EDIT]') {
                    return true;
                }
            }
        }

        $wpc_skip_rule = [
            'action'            => 'skip',
            'description'       => 'Optimizer Bypass [DO NOT EDIT]',
            'enabled'           => true,
            'expression'        => $expression,
            'action_parameters' => [
                'products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],
            ],
        ];

        // Entry-point ruleset for the phase: add to it when it exists, create it otherwise.
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
            $result = $this->postRequest("zones/$zoneId/rulesets/$rulesetId/rules", $wpc_skip_rule);
        } else {
            $result = $this->postRequest("zones/$zoneId/rulesets", ['name' => 'WPC Firewall Rules', 'kind' => 'zone', 'phase' => 'http_request_firewall_custom', 'rules' => [$wpc_skip_rule]]);
        }

        if (is_wp_error($result)) {
            error_log('[WPC] addCdnBypassRule CF API error: ' . $result->get_error_message());
            return $result; // WP_Error carries the CF code → classifyResult names permission vs misconfig vs unreachable
        }

        return $result;
    }

    public function wpc_find_bypass_rule740($zoneId)
    {
        $wpc_out740 = ['ruleset' => '', 'rule' => '', 'expression' => '', 'legacy' => false, 'shape' => []];
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
                            $wpc_out740['ruleset']    = (string) $rs['id'];
                            $wpc_out740['rule']       = (string) $rule['id'];
                            $wpc_out740['expression'] = isset($rule['expression']) ? (string) $rule['expression'] : '';
                            $wpc_out740['shape']      = $rule;
                            return $wpc_out740;
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
                    $wpc_out740['legacy'] = true;
                    break;
                }
            }
        }
        return $wpc_out740;
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
    public function rotateCdnBypassToken($zoneId, $wpc_tighten740 = true)
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
        $wpc_loc740 = $this->wpc_find_bypass_rule740($zoneId);
        if ($wpc_loc740['rule'] === '') {
            if ($wpc_loc740['legacy']) {
                error_log('[WPC] rotateCdnBypassToken: rule exists only under the deprecated firewall/rules API — not rotating');
                return 'legacy-rule';
            }
            return $this->addCdnBypassRule($zoneId);
        }

        $wpc_r740 = wp_remote_get(
            WPS_IC_KEYSURL . '?action=rotate_cf_bypass_token&apikey=' . urlencode((string) $options['api_key']),
            ['timeout' => (int) apply_filters('wpc_cf_keys_timeout', 20), 'sslverify' => false]
        );
        if (is_wp_error($wpc_r740)) {
            error_log('[WPC] rotateCdnBypassToken: keys request error: ' . $wpc_r740->get_error_message());
            return false;
        }
        if ((int) wp_remote_retrieve_response_code($wpc_r740) === 429) {
            error_log('[WPC] rotateCdnBypassToken: rate limited (1/apikey/hour)');
            return 'rate-limited';
        }
        $wpc_b740 = json_decode(wp_remote_retrieve_body($wpc_r740), true);
        if (empty($wpc_b740['success']) || empty($wpc_b740['data']['token'])) {
            error_log('[WPC] rotateCdnBypassToken: unexpected response: ' . wp_remote_retrieve_body($wpc_r740));
            return false;
        }
        $wpc_new740  = (string) $wpc_b740['data']['token'];
        $wpc_expr740 = !empty($wpc_b740['data']['expression'])
            ? (string) $wpc_b740['data']['expression']
            : 'any(http.request.headers["x-origin-auth"][*] == "' . $wpc_new740 . '")';

        $wpc_union740 = $wpc_expr740;
        if ($wpc_loc740['expression'] !== ''
            && strpos($wpc_loc740['expression'], $wpc_new740) === false) {
            $wpc_union740 = '(' . $wpc_expr740 . ') or (' . $wpc_loc740['expression'] . ')';
        }

        $wpc_patch740 = $this->wpc_write_bypass_expression740($zoneId, $wpc_loc740, $wpc_union740);
        if ($wpc_patch740 === false || is_wp_error($wpc_patch740)) {
            error_log('[WPC] rotateCdnBypassToken: WAF re-key failed — the OLD token is still live, so the lane is not broken');
            return false;
        }

        if ($wpc_tighten740 && function_exists('wp_schedule_single_event')) {
            $wpc_delay740 = (int) apply_filters('wpc_cf_bypass_tighten_delay', 20 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time() + $wpc_delay740, 'wpc_cf_bypass_tighten', [(string) $zoneId, $wpc_expr740]);
        }

        return ['rotated' => true, 'union' => $wpc_union740, 'expression' => $wpc_expr740,
                'bunnydb_synced' => !empty($wpc_b740['data']['bunnydb_synced'])];
    }

    public function wpc_write_bypass_expression740($zoneId, $wpc_loc740, $wpc_expression740)
    {
        if (empty($wpc_loc740['ruleset']) || empty($wpc_loc740['rule']) || $wpc_expression740 === '') {
            return false;
        }
        $wpc_body740 = [
            'action'      => 'skip',
            'description' => 'Optimizer Bypass [DO NOT EDIT]',
            'enabled'     => true,
            'expression'  => $wpc_expression740,
        ];
        $wpc_body740['action_parameters'] = (!empty($wpc_loc740['shape']['action_parameters']))
            ? $wpc_loc740['shape']['action_parameters']
            : ['products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf']];
        return $this->patchRequest(
            'zones/' . $zoneId . '/rulesets/' . $wpc_loc740['ruleset'] . '/rules/' . $wpc_loc740['rule'],
            $wpc_body740
        );
    }

    /**
     * Scheduled follow-up: drop the superseded token once the edge pods have turned over.
     * Re-reads the deployed rule, so a rotation that happened in between is not clobbered.
     */
    public function tightenCdnBypassRule($zoneId, $wpc_expression740)
    {
        $wpc_loc740 = $this->wpc_find_bypass_rule740($zoneId);
        if ($wpc_loc740['rule'] === '' || $wpc_loc740['expression'] === $wpc_expression740) {
            return false;
        }
        if (strpos($wpc_loc740['expression'], $wpc_expression740) === false) {
            error_log('[WPC] tightenCdnBypassRule: deployed rule no longer contains the token we minted — a newer rotation won, standing down');
            return false;
        }
        return $this->wpc_write_bypass_expression740($zoneId, $wpc_loc740, $wpc_expression740);
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
    public function htmlRuleKeyState($zoneId)
    {
        $out = ['rules' => [], 'devkey' => false, 'anykey' => false, 'found' => 0];
        foreach ([WPC_HOMEPAGE_RULE_REF => 'homepage', WPC_FULLHTML_RULE_REF => 'fullhtml'] as $ref => $label) {
            $rule = $this->findCacheRuleByRef($zoneId, $ref);
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

    private function getBypassRule()
    {
        return ['ref' => WPC_BYPASS_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Bypass cache for admin/login/commerce', 'enabled' => true, 'expression' => '(http.request.method ne "GET" and http.request.method ne "HEAD") or (starts_with(http.request.uri.path, "/wp-admin") or http.request.uri.path eq "/wp-login.php" or http.request.uri.path contains "/wp-cron.php" or http.request.uri.path contains "/xmlrpc.php" or starts_with(http.request.uri.path, "/wp-json/") or http.request.uri.path contains "/admin-ajax.php" or ends_with(http.request.uri.path, "/cart/") or ends_with(http.request.uri.path, "/checkout/") or starts_with(http.request.uri.path, "/my-account")) or (http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "edd_") or (lower(http.request.uri.query) contains "nocache=" or lower(http.request.uri.query) contains "no-cache=" or lower(http.request.uri.query) contains "wc-ajax=" or lower(http.request.uri.query) contains "edd_action=" or lower(http.request.uri.query) contains "preview=")', 'action_parameters' => ['cache' => false]];
    }


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

    private function getStaticAssetsRule()
    {


        return ['ref' => WPC_STATIC_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Static assets cache', 'enabled' => true, 'expression' => '(http.request.method in {"GET" "HEAD"}) and lower(http.request.uri.path.extension) in {"css" "js" "mjs" "json" "map" "jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "ico" "ttf" "otf" "woff" "woff2" "eot" "mp4" "webm" "ogg"} and not starts_with(http.request.uri.path, "/cdn-cgi/")', 'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'respect_origin', 'default' => (int) apply_filters('wpc_cf_static_edge_default', 2592000)], 'browser_ttl' => ['mode' => 'override_origin', 'default' => (int) apply_filters('wpc_cf_static_browser_ttl', 31536000)], 'cache_key' => ['ignore_query_strings_order' => true]]];
    }


    private function getRobotsSitemapRule()
    {
        // robots.txt/sitemaps are PHP-generated on most WP sites — every crawler request
        // hits origin, and an origin micro-stall makes PSI/SEO fail the robots fetch.
        // One hour at the edge ends that failure class; staleness is harmless here.
        return ['ref' => WPC_ROBOTS_RULE_REF, 'action' => 'set_cache_settings',
            'description' => '[DO NOT EDIT] Robots + sitemap edge cache', 'enabled' => true,
            'expression' => '(http.request.method in {"GET" "HEAD"}) and (http.request.uri.path eq "/robots.txt"'
                . ' or ends_with(http.request.uri.path, "sitemap.xml") or ends_with(http.request.uri.path, "sitemap_index.xml"))',
            'action_parameters' => [
                'cache'       => true,
                'edge_ttl'    => ['mode' => 'override_origin', 'default' => (int) apply_filters('wpc_cf_robots_edge_ttl', 3600)],
                'browser_ttl' => ['mode' => 'respect_origin'],
            ]];
    }

    public function patchStaticAssetsRespectOrigin($zoneId)
    {
        if (empty($zoneId)) {
            return new WP_Error('no_zone', 'No zone id');
        }
        if (apply_filters('wpc_cf_robots_rule', true) && !$this->findCacheRuleByRef($zoneId, WPC_ROBOTS_RULE_REF)) {
            $this->logCacheRuleResult('create-robots', $zoneId, $this->addCacheRule($zoneId, $this->getRobotsSitemapRule()));
        }
        $rule = $this->findCacheRuleByRef($zoneId, WPC_STATIC_RULE_REF);
        if (!$rule) {
            // Not provisioned yet → create with the respect_origin definition.
            $created = $this->addCacheRule($zoneId, $this->getStaticAssetsRule());
            $this->logCacheRuleResult('create', $zoneId, $created);
            return $created;
        }
        if (!isset($rule['action_parameters']) || !is_array($rule['action_parameters'])) {
            $rule['action_parameters'] = [];
        }
        // Flip ONLY the TTL modes; leave cache/expression/accumulated-domains untouched.
        // Browser TTL is overridden: statics on hosts with no expires config (nginx ignores
        // .htaccess) otherwise reach browsers with no Cache-Control at all.
        $rule['action_parameters']['cache']       = true;
        $rule['action_parameters']['edge_ttl']    = ['mode' => 'respect_origin', 'default' => (int) apply_filters('wpc_cf_static_edge_default', 2592000)];
        $rule['action_parameters']['browser_ttl'] = ['mode' => 'override_origin', 'default' => (int) apply_filters('wpc_cf_static_browser_ttl', 31536000)];
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);
        if (is_wp_error($rulesetId)) {
            return $rulesetId;
        }
        $resp = $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/{$rule['id']}", $rule);


        $this->logCacheRuleResult('patch', $zoneId, $resp);
        return $resp;
    }

    /** Log a static-rule API result (WP_Error or CF non-success) for diagnosis. */
    private function logCacheRuleResult($op, $zoneId, $resp)
    {
        $msg = '';
        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
        } elseif (is_array($resp) && array_key_exists('success', $resp) && !$resp['success']) {
            $msg = (string) wp_json_encode($resp['errors'] ?? $resp);
        }
        if ($msg !== '') {
            error_log('[WPC CF] static-rule ' . $op . ' failed (zone ' . $zoneId . '): ' . $msg);
            if (function_exists('wpc_auto_journal')) {
                wpc_auto_journal('cf-static-rule-' . $op . '-failed', ['zone' => $zoneId, 'err' => substr($msg, 0, 300)]);
            }
        }
    }


    public function patchHtmlRulesRespectOrigin($zoneId, $combinedOverride = null, $createMissing = false)
    {
        if (empty($zoneId) || !apply_filters('wpc_cf_html_respect_origin', true)) {
            return null;
        }


        // v7.10.669 — the DEPLOYED rule's mode is the DESIRE (wpc_deploy_combined: no floors), NEVER
        // wpc_combined_crit_on() (floor-gated). Floor-gating the deploy is the circular trap: .668
        // forces combined until a readback sees the device key, but THIS deploy is what puts the key
        // there — so a floor-gated deploy strips it, the readback sees none, and Refresh Connection
        // never bootstraps the edge. Device-key the edge whenever split is desired; the readback below
        // + the render floors then gate the RENDER on the observed effect.
        $wpc_combined57 = is_bool($combinedOverride)
            ? $combinedOverride
            : (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_deploy_combined')
                ? wps_rewriteLogic::wpc_deploy_combined()
                : (class_exists('wps_rewriteLogic')
                    && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on')
                    && wps_rewriteLogic::wpc_combined_crit_on()));


        // SAFE-FRESHNESS target (respect_origin: origin's max-age=60 governs; SWU keeps hot pages
        // edge-fast) — the 24h crown is opt-in via option wpc_cf_purge_verified='1' (set it after


        // Cache-Tag purge → MISS, verified at the edge). Accept the earned form: any zone holding


        // Edge TTL stays respect_origin ALWAYS: with an override the edge caches any 200
        // regardless of origin Cache-Control, so a mangled/error body can get pinned for the
        // full TTL. The crown's long edge TTL is granted via s-maxage from the origin instead
        // (wpc_edge_smaxage) — only on responses that passed the body floor. The 60s default
        // caps how long a header-less response (request died before the guard armed) can live
        // at the edge — without it CF's zone default pins such bodies for ~2h.
        $wpc_edge_target = ['mode' => 'respect_origin', 'default' => (int) apply_filters('wpc_cf_html_edge_default', 60)];
        $out = [];
        foreach ([WPC_HOMEPAGE_RULE_REF, WPC_FULLHTML_RULE_REF] as $ref) {
            $rule = $this->findCacheRuleByRef($zoneId, $ref);
            if (!$rule && $createMissing && apply_filters('wpc_cf_html_ensure_rules', true)) {


                $mk = ($ref === WPC_HOMEPAGE_RULE_REF) ? $this->getHomepageHTMLRule() : $this->getFullHTMLRule();
                $crt = $this->addCacheRule($zoneId, $mk);
                $this->logCacheRuleResult('create-html-' . $ref, $zoneId, $crt);
                $rule = $this->findCacheRuleByRef($zoneId, $ref);
            }
            if (!$rule) {
                continue;
            }
            $ap = (isset($rule['action_parameters']) && is_array($rule['action_parameters']))
                ? $rule['action_parameters'] : [];
            $edgeMode    = isset($ap['edge_ttl']['mode']) ? $ap['edge_ttl']['mode'] : '';
            $edgeDefault = isset($ap['edge_ttl']['default']) ? (int) $ap['edge_ttl']['default'] : 0;
            $hasDevKey  = !empty($ap['cache_key']['cache_by_device_type']);


            $hasAnyKey  = !empty($ap['cache_key']);
            $wpc_key_ok100 = $wpc_combined57 ? !$hasAnyKey : $hasDevKey;
            // Expression drift heals too: a deployed rule still carrying the tk_ai cookie
            // carve-out excludes every real browser after the first page (analytics JS sets
            // it site-wide) — analytics cookies never change the HTML and must not gate caching.
            $wpc_expr_stale197 = stripos((string) ($rule['expression'] ?? ''), 'tk_ai') !== false;
            if (!$wpc_expr_stale197
                && $edgeMode === $wpc_edge_target['mode'] && $edgeDefault === (int) $wpc_edge_target['default'] && $wpc_key_ok100
                && isset($ap['browser_ttl']['mode']) && $ap['browser_ttl']['mode'] === 'respect_origin') {
                continue;
            }
            if ($wpc_expr_stale197) {
                $wpc_tpl197 = ($ref === WPC_HOMEPAGE_RULE_REF) ? $this->getHomepageHTMLRule() : $this->getFullHTMLRule();
                $rule['expression'] = $wpc_tpl197['expression'];
            }
            $rule['action_parameters'] = $ap;
            $rule['action_parameters']['cache']       = true;
            $rule['action_parameters']['edge_ttl']    = $wpc_edge_target;
            $rule['action_parameters']['browser_ttl'] = ['mode' => 'respect_origin'];
            $rule['action_parameters']['serve_stale'] = ['disable_stale_while_updating' => false];
            if ($wpc_combined57) {
                unset($rule['action_parameters']['cache_key']);
            } else {
                $rule['action_parameters']['cache_key'] = ['cache_by_device_type' => true];
            }
            $rulesetId = $this->getCacheRulesRulesetId($zoneId);
            if (is_wp_error($rulesetId)) {
                return $rulesetId;
            }
            $resp = $this->patchRequest("zones/$zoneId/rulesets/$rulesetId/rules/{$rule['id']}", $rule);
            $this->logCacheRuleResult('patch-html-' . $ref, $zoneId, $resp);
            $out[$ref] = $resp;
        }


        // v7.10.568 — READ BACK WHAT CF ACTUALLY ACCEPTED. cache_by_device_type is an
        // Enterprise-only field; a zone without it keeps the old rule shape and returns success
        // on the rest, so "we sent the patch" is not evidence the edge keys per device. Stamp the
        // observed state so wpc_combined_crit_on() can refuse device-divergent HTML on a
        // device-blind edge (one copy per URL for every device = first device to warm it wins).
        try {
            $wpc_ks568 = $this->htmlRuleKeyState($zoneId);
            $out['key_state'] = $wpc_ks568;
            if (function_exists('update_option')) {
                update_option('wpc_cf_devkey_verified', [
                    't'      => time(),
                    'devkey' => !empty($wpc_ks568['devkey']) ? 1 : 0,
                    // v7.10.682 — this IS a deploy readback (htmlRuleKeyState on the live rules);
                    // stamp it as one. The .682 floor is an allowlist on src='readback', and this
                    // writer's src-less stamp was both unlocking split without naming its evidence
                    // AND would otherwise pin every fleet site combined after the allowlist.
                    'src'    => 'readback',
                    'found'  => (int) ($wpc_ks568['found'] ?? 0),
                    'want'   => $wpc_combined57 ? 'combined' : 'split',
                ], false);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cf-devkey-readback', '', '', [
                    'devkey' => !empty($wpc_ks568['devkey']) ? 1 : 0,
                    'found'  => (int) ($wpc_ks568['found'] ?? 0),
                    'want'   => $wpc_combined57 ? 'combined' : 'split',
                ]);
            }
        } catch (\Throwable $e) {
        }

        // Statics get their browser TTL via patchStaticAssetsRespectOrigin (the version-change
        // reassert calls it right before this method).

        // Deployed bypass rules carrying the tk_ai carve-out heal here too.
        $wpc_bp197 = $this->findCacheRuleByRef($zoneId, WPC_BYPASS_RULE_REF);
        if ($wpc_bp197 && stripos((string) ($wpc_bp197['expression'] ?? ''), 'tk_ai') !== false && !empty($wpc_bp197['id'])) {
            $wpc_bt197 = $this->getBypassRule();
            $wpc_bp197['expression'] = $wpc_bt197['expression'];
            $wpc_brs197 = $this->getCacheRulesRulesetId($zoneId);
            if (!is_wp_error($wpc_brs197)) {
                $wpc_bresp197 = $this->patchRequest("zones/$zoneId/rulesets/$wpc_brs197/rules/{$wpc_bp197['id']}", $wpc_bp197);
                $this->logCacheRuleResult('patch-bypass-tkai', $zoneId, $wpc_bresp197);
                $out[WPC_BYPASS_RULE_REF] = $wpc_bresp197;
            }
        }

        // Tiered caching is EARNED, never assumed: the selftest enables it only after
        // eviction re-verifies with tiered active (crown method 'url+tiered'); everywhere
        // else it stays off. The historical un-purgeable state was custom cache keys —
        // which the reconcile above strips — not the tiers themselves.
        $wpc_pvt197 = function_exists('get_option') ? get_option('wpc_cf_purge_verified') : false;
        $wpc_tiered_earned197 = is_array($wpc_pvt197)
            && strpos((string) ($wpc_pvt197['method'] ?? ''), 'tiered') !== false
            && !empty($wpc_pvt197['t']) && (time() - (int) $wpc_pvt197['t']) < 8 * DAY_IN_SECONDS;
        if (!$wpc_tiered_earned197 && method_exists($this, 'disableTieredCache')) {
            $out['tiered_off'] = $this->disableTieredCache($zoneId);
        }
        return $out;
    }

    private function getHomepageHTMLRule()
    {
        $domain = parse_url(get_site_url(), PHP_URL_HOST);

        // Handle both www and non-www versions
        if (strpos($domain, 'www.') === 0) {
            $base_domain = substr($domain, 4);
            $host_list = '"' . $domain . '" "' . $base_domain . '"';
        } else {
            $www_domain = 'www.' . $domain;
            $host_list = '"' . $domain . '" "' . $www_domain . '"';
        }

        $expression = '(http.host in {' . $host_list . '}) and (http.request.method in {"GET" "HEAD"}) and http.request.uri.path eq "/" and not starts_with(http.request.uri.path, "/cdn-cgi/") and not (http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "edd_")';


        // Override pinned even no-store pages for 60 min AND device-keyed entries are un-purgeable
        // by URL on non-Enterprise plans; origin's max-age=60 must-revalidate governs instead.
        return ['ref' => WPC_HOMEPAGE_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Homepage HTML cache', 'enabled' => true, 'expression' => $expression, 'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'respect_origin'], 'browser_ttl' => ['mode' => 'respect_origin'], 'serve_stale' => ['disable_stale_while_updating' => false], 'cache_key' => ['cache_by_device_type' => true, 'ignore_query_strings_order' => true]]];
    }

    private function getFullHTMLRule()
    {
        $domain = parse_url(get_site_url(), PHP_URL_HOST);

        // Handle both www and non-www versions
        if (strpos($domain, 'www.') === 0) {
            $base_domain = substr($domain, 4);
            $host_list = '"' . $domain . '" "' . $base_domain . '"';
        } else {
            $www_domain = 'www.' . $domain;
            $host_list = '"' . $domain . '" "' . $www_domain . '"';
        }

        $expression = '(http.host in {' . $host_list . '}) and (http.request.method in {"GET" "HEAD"}) and not starts_with(http.request.uri.path, "/cdn-cgi/") and not starts_with(http.request.uri.path, "/wp-admin") and http.request.uri.path ne "/wp-login.php" and not starts_with(http.request.uri.path, "/wp-json/") and (http.request.uri.path.extension eq "" or lower(http.request.uri.path.extension) in {"html" "htm" "xhtml"}) and not (http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "edd_")';


        // (distant visitors on lukewarm pages paying an origin round-trip).
        return ['ref' => WPC_FULLHTML_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Full HTML cache', 'enabled' => true, 'expression' => $expression, 'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'respect_origin'], 'browser_ttl' => ['mode' => 'respect_origin'], 'serve_stale' => ['disable_stale_while_updating' => false], 'cache_key' => ['cache_by_device_type' => true, 'ignore_query_strings_order' => true]]];
    }


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
			update_option(WPS_IC_CF_CNAME, $cdn_subdomain);
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
        ];
        $featureFor = [
            'Zone - Zone Settings - Edit'     => 'auto Rocket-Loader conflict handling',
            'Zone - Firewall Services - Edit' => 'firewall / access rules',
            'Zone - DNS - Edit'               => 'automatic CNAME setup',
            'Zone - Analytics - Read'         => 'the Cloudflare analytics panel',
            'Zone - Cache Rules - Edit'       => 'edge-cache optimization rules',
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

        return [
            'ok'               => empty($critical_missing),
            'critical_missing' => $critical_missing,
            'optional_missing' => $optional_missing,
            'tests'            => $permissionTests,
        ];
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
    add_action('wpc_cf_bypass_tighten', function ($wpc_zone740 = '', $wpc_expr740 = '') {
        if ($wpc_zone740 === '' || $wpc_expr740 === '' || !class_exists('WPC_CloudflareAPI')
            || !defined('WPS_IC_CF') || !function_exists('get_option')) {
            return;
        }
        $wpc_cf740 = get_option(WPS_IC_CF);
        if (empty($wpc_cf740['token'])) {
            return;
        }
        $wpc_sdk740 = new WPC_CloudflareAPI($wpc_cf740['token']);
        $wpc_sdk740->tightenCdnBypassRule((string) $wpc_zone740, (string) $wpc_expr740);
    }, 10, 2);
}
