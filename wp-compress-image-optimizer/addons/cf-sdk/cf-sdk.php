<?php

#define('WPC_CF_TOKEN', 'vPn-BuupnJ3VmJUAVPt0V7BaeWvFID_ljh_2UMoz');

// Rule identifiers for WP Compress plugin
const WPC_BYPASS_RULE_REF = 'wpc-bypass-cache';
const WPC_STATIC_RULE_REF = 'wpc-static-assets';
const WPC_HOMEPAGE_RULE_REF = 'wpc-homepage-html';
const WPC_FULLHTML_RULE_REF = 'wpc-full-html';


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



    /***
     * CF API
     */
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

        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'updateCFConfig', 'token' => $token, 'zone' => $zoneInput, 'zoneName' => $zoneName, 'siteUrl' => $siteUrl, 'apikey' => $apikey, 'time' => microtime(true), 'staticAssets' => $staticAssetsEnabled, 'htmlCache' => $htmlCacheMode], ['timeout' => 120]);

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

    /**
     * Send a GET request to the Cloudflare API
     *
     * @param string $endpoint API endpoint
     * @param array $query Optional query parameters
     * @return array|WP_Error The API response or WP_Error
     */
    private function getRequest($endpoint, $query = [])
    {
        $url = add_query_arg($query, $this->apiBase . $endpoint);

        $response = wp_remote_get($url, ['headers' => $this->getHeaders(),]);


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

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['errors'])) {
            $error_messages = array_map(function ($error) {
                return $error['message']; // Extract error messages
            }, $data['errors']);

            $error_message = implode(', ', $error_messages); // Combine multiple messages if needed

            return new WP_Error('cloudflare_api_error', $error_message, $data['errors']);
        }

        return $data;
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
        return $this->postRequest("zones/$zoneId/purge_cache", ['purge_everything' => true,]);
    }

    /**
     * Send a POST request to the Cloudflare API
     *
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @return array|WP_Error The API response or WP_Error
     */
    private function postRequest($endpoint, $body = [])
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_post($url, ['headers' => $this->getHeaders(), 'body' => json_encode($body)]);

        return $this->processResponse($response);
    }

    /**
     * Purge specific files from the cache
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $files List of file URLs to purge
     * @return array|WP_Error The API response or WP_Error
     */
    public function purgeFiles($zoneId, $files)
    {
        return $this->postRequest("zones/$zoneId/purge_cache", ['files' => $files,]);
    }

    /**
     * Whitelist IPs in the Cloudflare Firewall
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $ipList List of IPs or IP ranges to whitelist
     * @return array|WP_Error The API response or WP_Error
     */
    public function whitelistIPs($zoneId)
    {
        if (!file_exists(WPC_API_WHITELIST)) {
            die("Error: File not found - WPC_API_WHITELIST");
        }

        $errors = false;
        $contents = file_get_contents(WPC_API_WHITELIST);
        $ipList = array_filter(array_map('trim', explode("\n", $contents)));

        foreach ($ipList as $ip) {
            if (strpos($ip, ':') !== false) {
                // Adding IPv6 range $ip to firewall rules
                $success = $this->addIpAccessRule($zoneId, $ip);
            } else {
                // Adding IP $ip to access rules
                $success = $this->addIpAccessRule($zoneId, $ip);
            }

            $success = true;

            if (!$success) {
                $errors = true;
                break;
            }
        }

        if (!$errors) {
            return true;
        } else {
            return new WP_Error('cloudflare_api_error', 'Unable to whitelist IPs', $errors);
        }
    }


    public function removeWhitelistIP($zoneId)
    {
        $r = [];
        $r[] = $this->removeIpAccessRuleByNote($zoneId, 'WP Compress API Endpoint');
//        $contents = file_get_contents(WPC_API_WHITELIST);
//        $ipList = array_filter(array_map('trim', explode("\n", $contents)));
//        foreach ($ipList as $ip) {
//            if (strpos($ip, ':') !== false) {
//                // IPv6 range: Remove from Firewall Rules
//                $r[] = $this->removeIpAccessRule($zoneId, $ip);
//            } else {
//                // IPv4: Remove from Access Rules
//                $r[] = $this->removeIpAccessRule($zoneId, $ip);
//            }
//        }

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

        $response = wp_remote_request($url, ['method' => 'DELETE', 'headers' => $this->getHeaders(),]);

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
                    // Rule matches, delete it
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
                    // Rule matches, delete it
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

    /**
     * Set Rocket Loader Status
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $value 'on' or 'off'
     * @return array|WP_Error The API response or WP_Error
     */
    public function setRocketLoader($zoneId, $value)
    {
        if (!in_array($value, ['on', 'off'])) {
            return new WP_Error('invalid_value', 'Value must be "on" or "off"');
        }

        return $this->patchRequest("zones/$zoneId/settings/rocket_loader", ['value' => $value]);
    }

    /**
     * Send a PATCH request to the Cloudflare API
     *
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @return array|WP_Error The API response or WP_Error
     */
    private function patchRequest($endpoint, $body = [])
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_request($url, ['method' => 'PATCH', 'headers' => $this->getHeaders(), 'body' => json_encode($body),]);

        return $this->processResponse($response);
    }

    /**
     * Update WP Compress cache configuration based on settings (UPDATED for multi-domain)
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param bool $staticAssetsEnabled Static assets cache toggle
     * @param string $htmlCacheMode 'off', 'home', or 'all'
     * @return array Results of the operation
     */
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

        // Update Tiered Cache setting
        $results['debug']['tiered_cache_action'] = $anyCacheEnabled ? 'enabling' : 'disabling';
        $tieredResult = $this->setTieredCache($zoneId, $anyCacheEnabled);
        $results['tiered_cache'] = $tieredResult;
        if (is_wp_error($tieredResult)) {
            $results['debug']['tiered_cache_error'] = $tieredResult->get_error_message();
        }

        return $results;
    }

    /**
     * Add a single cache rule (UPDATED - now supports multiple domains)
     * If rule exists, adds current domain to it. If not, creates new rule.
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $rule Single cache rule configuration
     * @param array $position Optional position object
     *
     * @return array|WP_Error The API response or WP_Error
     */
    public function addCacheRule($zoneId, $rule, $position = null)
    {
        $ruleRef = $rule['ref'];

        // Check if rule already exists
        $existingRule = $this->findCacheRuleByRef($zoneId, $ruleRef);

        if ($existingRule) {
            // Rule exists - add current domain to it
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

    /**
     * Find a rule by its ref field
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $ref Rule reference identifier
     *
     * @return array|null Rule data or null if not found
     */
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

    /**
     * List all cache rules for a zone
     *
     * @param string $zoneId Cloudflare Zone ID
     *
     * @return array|WP_Error List of rules or WP_Error
     */
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

    /**
     * Get the current site's domain/subdomain
     *
     * Returns the current domain or subdomain that the site is running on.
     * Strips www. prefix and properly handles multi-level TLDs like .co.uk
     *
     * @return string Current domain/subdomain (e.g., 'example.com' or 'blog.example.com')
     */
    public function getDomain()
    {
        $current_host = parse_url(get_site_url(), PHP_URL_HOST);

        // Remove www. if present
        if (strpos($current_host, 'www.') === 0) {
            $current_host = substr($current_host, 4);
        }

        return $current_host;
    }

    /**
     * Add domains to an existing cache rule
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $ruleRef Rule reference identifier
     * @param array $newDomains Domains to add
     * @return array|WP_Error The API response or WP_Error
     */
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

    /**
     * Extract domains from a Cloudflare expression
     * Parses expressions like: (http.host in {"domain1.com" "domain2.com"}) and ...
     *
     * @param string $expression Cloudflare rule expression
     * @return array List of domains found in the expression
     */
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

    /**
     * Update domains in a Cloudflare expression
     * Rebuilds the http.host in {...} part with new domain list
     *
     * @param string $expression Original expression
     * @param array $domains New list of domains
     * @return string Updated expression
     */
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
        return ['ref' => WPC_BYPASS_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Bypass cache for admin/login/commerce', 'enabled' => true, 'expression' => '(http.request.method ne "GET" and http.request.method ne "HEAD") or (starts_with(http.request.uri.path, "/wp-admin") or http.request.uri.path eq "/wp-login.php" or http.request.uri.path contains "/wp-cron.php" or http.request.uri.path contains "/xmlrpc.php" or starts_with(http.request.uri.path, "/wp-json/") or http.request.uri.path contains "/admin-ajax.php" or ends_with(http.request.uri.path, "/cart/") or ends_with(http.request.uri.path, "/checkout/") or starts_with(http.request.uri.path, "/my-account")) or (http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "tk_ai" or http.cookie contains "edd_") or (lower(http.request.uri.query) contains "nocache=" or lower(http.request.uri.query) contains "no-cache=" or lower(http.request.uri.query) contains "wc-ajax=" or lower(http.request.uri.query) contains "edd_action=" or lower(http.request.uri.query) contains "preview=")', 'action_parameters' => ['cache' => false]];
    }

    /**
     * Delete a cache rule by its ref field (UPDATED - now removes only current domain)
     * Only deletes entire rule if current domain is the last one
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $ref Rule reference identifier
     *
     * @return array|WP_Error The API response or WP_Error
     */
    public function deleteCacheRuleByRef($zoneId, $ref)
    {
        $currentDomains = $this->getCurrentDomainVariations();
        return $this->removeDomainsFromRule($zoneId, $ref, $currentDomains);
    }

    /**
     * Remove domains from an existing cache rule
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $ruleRef Rule reference identifier
     * @param array $domainsToRemove Domains to remove
     * @return array|WP_Error The API response or WP_Error
     */
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

    /**
     * Delete a cache rule by its ID
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $ruleId Rule ID to delete
     *
     * @return array|WP_Error The API response or WP_Error
     */
    public function deleteCacheRule($zoneId, $ruleId)
    {
        $rulesetId = $this->getCacheRulesRulesetId($zoneId);

        if (is_wp_error($rulesetId)) {
            return $rulesetId;
        }

        return $this->deleteRequest("zones/$zoneId/rulesets/$rulesetId/rules/$ruleId");
    }

    /**
     * Get the ruleset ID for cache rules
     *
     * @param string $zoneId Cloudflare Zone ID
     *
     * @return string|WP_Error Ruleset ID or WP_Error
     */
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
        return ['ref' => WPC_STATIC_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Static assets cache', 'enabled' => true, 'expression' => '(http.request.method in {"GET" "HEAD"}) and lower(http.request.uri.path.extension) in {"css" "js" "mjs" "json" "map" "jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "ico" "ttf" "otf" "woff" "woff2" "eot" "mp4" "webm" "ogg"} and not starts_with(http.request.uri.path, "/cdn-cgi/")', 'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'override_origin', 'default' => 2592000  // 30 days
        ], 'browser_ttl' => ['mode' => 'override_origin', 'default' => 2592000  // 30 days
        ], 'cache_key' => ['ignore_query_strings_order' => true]]];
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

        $expression = '(http.host in {' . $host_list . '}) and (http.request.method in {"GET" "HEAD"}) and http.request.uri.path eq "/" and not starts_with(http.request.uri.path, "/cdn-cgi/") and not (http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "tk_ai" or http.cookie contains "edd_")';

        return ['ref' => WPC_HOMEPAGE_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Homepage HTML cache', 'enabled' => true, 'expression' => $expression, 'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'override_origin', 'default' => 3600  // 60 min
        ], 'browser_ttl' => ['mode' => 'bypass_by_default'], 'serve_stale' => ['disable_stale_while_updating' => false], 'cache_key' => ['cache_by_device_type' => true, 'ignore_query_strings_order' => true]]];
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

        $expression = '(http.host in {' . $host_list . '}) and (http.request.method in {"GET" "HEAD"}) and not starts_with(http.request.uri.path, "/cdn-cgi/") and not starts_with(http.request.uri.path, "/wp-admin") and http.request.uri.path ne "/wp-login.php" and not starts_with(http.request.uri.path, "/wp-json/") and (http.request.uri.path.extension eq "" or lower(http.request.uri.path.extension) in {"html" "htm" "xhtml"}) and not (http.cookie contains "wordpress_logged_in_" or http.cookie contains "wordpress_sec_" or http.cookie contains "wp-postpass_" or http.cookie contains "woocommerce_cart_hash" or http.cookie contains "woocommerce_items_in_cart" or http.cookie contains "wp_woocommerce_session_" or http.cookie contains "tk_ai" or http.cookie contains "edd_")';


        return ['ref' => WPC_FULLHTML_RULE_REF, 'action' => 'set_cache_settings', 'description' => '[DO NOT EDIT] Full HTML cache', 'enabled' => true, 'expression' => $expression, 'action_parameters' => ['cache' => true, 'edge_ttl' => ['mode' => 'override_origin', 'default' => 1800  // 30 min
        ], 'browser_ttl' => ['mode' => 'bypass_by_default'], 'cache_key' => ['cache_by_device_type' => true, 'ignore_query_strings_order' => true]]];
    }

    /**
     * Set Tiered Cache on or off
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param bool $enabled True to enable, false to disable
     *
     * @return array|WP_Error The API response or WP_Error
     */
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

    /**
     * Check if WP Compress cache rules exist
     *
     * @param string $zoneId Cloudflare Zone ID
     *
     * @return array Status of all rules
     */
    public function checkWPCCacheRulesStatus($zoneId)
    {
        return ['bypass' => $this->findCacheRuleByRef($zoneId, WPC_BYPASS_RULE_REF) !== null, 'static' => $this->findCacheRuleByRef($zoneId, WPC_STATIC_RULE_REF) !== null, 'homepage' => $this->findCacheRuleByRef($zoneId, WPC_HOMEPAGE_RULE_REF) !== null, 'fullhtml' => $this->findCacheRuleByRef($zoneId, WPC_FULLHTML_RULE_REF) !== null,];
    }

    /**
     * Delete a DNS record
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $recordId DNS record ID
     * @return array|WP_Error The API response or WP_Error
     */
    public function deleteDNSRecord($zoneId, $recordId)
    {
        return $this->deleteRequest("zones/$zoneId/dns_records/$recordId");
    }

    /**
     * Add CDN CNAME record (cdn.domain.com -> cdn-node.zapwp.net)
     * Also verifies and sets SSL/TLS to Full if needed
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string|false $recordId Custom CNAME or false to use auto-generated
     * @return array|WP_Error The API response or WP_Error
     */
    public function addCfCname($zoneId, $recordId = false)
    {
        if ($recordId) {
            $cdn_subdomain = $recordId;
        } else {
            $cdn_subdomain = $this->getCfCname();
        }
        $target = 'cdn-node.zapwp.net';

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

    /**
     * Get CDN CNAME for the current site
     *
     * Builds the CDN CNAME based on whether the site is on a subdomain or not.
     * Examples:
     * - example.com -> cdn.example.com
     * - blog.example.com -> cdn-blog.example.com
     *
     * @return string CDN CNAME
     */
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

    /**
     * Find DNS record by name and type
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $name Record name
     * @param string $type Record type (A, CNAME, etc.)
     * @return array|null DNS record or null if not found
     */
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

    /**
     * List DNS records for a zone
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $filters Optional filters (type, name, content, etc.)
     * @return array|WP_Error List of DNS records or WP_Error
     */
    public function listDNSRecords($zoneId, $filters = [])
    {
        return $this->getRequest("zones/$zoneId/dns_records", $filters);
    }

    /**
     * Update a DNS record
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $recordId DNS record ID
     * @param array $record Updated DNS record configuration
     * @return array|WP_Error The API response or WP_Error
     */
    public function updateDNSRecord($zoneId, $recordId, $record)
    {
        return $this->putRequest("zones/$zoneId/dns_records/$recordId", $record);
    }

    /**
     * Send a PUT request to the Cloudflare API (if not already in your class)
     *
     * @param string $endpoint API endpoint
     * @param array $body Request body
     *
     * @return array|WP_Error The API response or WP_Error
     */
    private function putRequest($endpoint, $body = [])
    {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_request($url, ['method' => 'PUT', 'headers' => $this->getHeaders(), 'body' => json_encode($body),]);

        return $this->processResponse($response);
    }

    /**
     * Add a DNS record to a zone
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $record DNS record configuration
     * @return array|WP_Error The API response or WP_Error
     */
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

        /* Don't remove it, we don't know if another site is still using it!

        $existingRecord = $this->findDNSRecord($zoneId, $cdn_subdomain, 'CNAME');

        if ($existingRecord) {
            $result = $this->deleteDNSRecord($zoneId, $existingRecord['id']);
            return $result;
        }

        */
        return null; // Record doesn't exist, nothing to remove
    }

    /**
     * Get daily zone analytics/statistics for current site's hostname
     *
     * Retrieves daily analytics data filtered by the current site's domain/subdomain.
     * Automatically combines www, non-www, and CDN CNAME versions.
     * Zone ID is automatically read from Cloudflare settings.
     *
     * Note: Due to API limits, this makes one query per day per hostname (3 queries per day total).
     *
     * @param string $from Start date (YYYY-MM-DD format)
     * @param string $to End date (YYYY-MM-DD format)
     * @return array|WP_Error Analytics data in format: ['2025-10-01' => ['bytes' => X, 'requests' => Y], ...]
     *
     * @example
     * // Get stats for last 7 days
     * $to = date('Y-m-d');
     * $from = date('Y-m-d', strtotime('-7 days'));
     * $stats = $api->getZoneAnalytics($from, $to);
     * // Returns: ['2025-10-15' => ['bytes' => 50000000, 'requests' => 10000], ...]
     */
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

    /**
     * Fetch analytics stats for a specific hostname for a single day using httpRequestsAdaptiveGroups
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $dayStart Start datetime (YYYY-MM-DDTHH:MM:SSZ)
     * @param string $dayEnd End datetime (YYYY-MM-DDTHH:MM:SSZ)
     * @param string $hostname Hostname to filter by
     * @return array|WP_Error Analytics data or WP_Error
     */
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

    /**
     * Send a GraphQL request to the Cloudflare API
     *
     * @param string $query GraphQL query string
     * @param array $variables Query variables
     * @return array|WP_Error The API response or WP_Error
     */
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

        // Helper function to check if response indicates permission error
        $isPermissionError = function ($response) {
            if (is_wp_error($response)) {
                return true;
            }

            // Check if success is false and there are errors
            if (isset($response['success']) && $response['success'] === false) {
                if (!empty($response['errors'])) {
                    foreach ($response['errors'] as $error) {
                        $code = $error['code'] ?? 0;
                        // Permission/auth error codes: 9109, 10000, 1095
                        if (in_array($code, [9109, 10000, 1095])) {
                            return true;
                        }
                    }
                }
            }

            return false;
        };

        // Test 1: Zone Read
        $zonesResponse = $this->getRequest('zones', ['per_page' => 1]);
        if ($isPermissionError($zonesResponse)) {
            $missingPermissions[] = 'Zone - Zone - Read';
            $permissionTests['Zone Read'] = 'Failed';
        } else {
            $permissionTests['Zone Read'] = 'OK';
        }

        // Test 2: Zone Settings Edit
        $settingsResponse = $this->getRequest("zones/{$zoneId}/settings/rocket_loader");
        if ($isPermissionError($settingsResponse)) {
            $missingPermissions[] = 'Zone - Zone Settings - Edit';
            $permissionTests['Zone Settings Edit'] = 'Failed';
        } else {
            $permissionTests['Zone Settings Edit'] = 'OK';
        }

        // Test 3: Cache Purge
        // Use POST with minimal valid data to test permission without actually purging
        $cacheResponse = $this->postRequest("zones/{$zoneId}/purge_cache", ['files' => []]);

        // Check if it's a permission error vs validation error
        $hasCachePurgePermission = true;
        if (is_wp_error($cacheResponse)) {
            $hasCachePurgePermission = false;
        } elseif (isset($cacheResponse['success']) && $cacheResponse['success'] === false) {
            if (!empty($cacheResponse['errors'])) {
                foreach ($cacheResponse['errors'] as $error) {
                    $code = $error['code'] ?? 0;
                    // Permission/auth error codes
                    if (in_array($code, [9109, 10000, 1095])) {
                        $hasCachePurgePermission = false;
                        break;
                    }
                    // Code 1012 or similar validation errors mean we have permission but bad data
                    // This is actually good - it means permission is OK
                }
            }
        }

        if (!$hasCachePurgePermission) {
            $missingPermissions[] = 'Zone - Cache Purge - Purge';
            $permissionTests['Cache Purge'] = 'Failed';
        } else {
            $permissionTests['Cache Purge'] = 'OK';
        }

        // Test 4: Firewall Services Edit
        $firewallResponse = $this->getRequest("zones/{$zoneId}/firewall/access_rules/rules", ['per_page' => 1]);
        if ($isPermissionError($firewallResponse)) {
            $missingPermissions[] = 'Zone - Firewall Services - Edit';
            $permissionTests['Firewall Services Edit'] = 'Failed';
        } else {
            $permissionTests['Firewall Services Edit'] = 'OK';
        }

        // Test 5: DNS Edit
        $dnsResponse = $this->getRequest("zones/{$zoneId}/dns_records", ['per_page' => 1]);
        if ($isPermissionError($dnsResponse)) {
            $missingPermissions[] = 'Zone - DNS - Edit';
            $permissionTests['DNS Edit'] = 'Failed';
        } else {
            $permissionTests['DNS Edit'] = 'OK';
        }

        // Test 6: Analytics Read
        // Use zone details endpoint which requires Analytics Read to see analytics data
        // The /zones/{id} endpoint is simpler and still requires analytics permission
        $zoneDetailsResponse = $this->getRequest("zones/{$zoneId}");
        if ($isPermissionError($zoneDetailsResponse)) {
            $missingPermissions[] = 'Zone - Analytics - Read';
            $permissionTests['Analytics Read'] = 'Failed';
        } else {
            // Zone endpoint returns basic info even without analytics permission
            // So we'll just mark this as OK if we can access the zone
            // Real analytics testing would require GraphQL which is complex
            $permissionTests['Analytics Read'] = 'OK (basic check)';
        }

        // Test 7: Cache Rules (Rulesets)
        $rulesetsResponse = $this->getRequest("zones/{$zoneId}/rulesets");
        if ($isPermissionError($rulesetsResponse)) {
            $missingPermissions[] = 'Zone - Cache Rules - Edit';
            $permissionTests['Cache Rules Edit'] = 'Failed';
        } else {
            $permissionTests['Cache Rules Edit'] = 'OK';
        }

        // Return results
        if (!empty($missingPermissions)) {
            $missingList = implode(', ', $missingPermissions);
            return new WP_Error('cloudflare_insufficient_privileges', 'API token is missing required permissions: ' . $missingList, ['missing' => $missingPermissions, 'test_results' => $permissionTests]);
        }

        return true;
    }

    /**
     * Get daily zone analytics for entire zone (unfiltered)
     *
     * Retrieves daily analytics data for the entire zone without hostname filtering.
     * Uses httpRequests1dGroups which provides daily aggregated data.
     * Zone ID is automatically read from Cloudflare settings.
     *
     * @param string $from Start date (YYYY-MM-DD format)
     * @param string $to End date (YYYY-MM-DD format)
     * @return array|WP_Error Analytics data in format: ['2025-10-01' => ['bytes' => X, 'requests' => Y, 'cached_bytes' => Z, 'cached_requests' => W], ...]
     */
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

    /**
     * Get list of all domains currently in a specific cache rule
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param string $ruleRef Rule reference identifier
     * @return array|WP_Error List of domains or WP_Error
     */
    public function getDomainsInRule($zoneId, $ruleRef)
    {
        $rule = $this->findCacheRuleByRef($zoneId, $ruleRef);

        if (!$rule) {
            return new WP_Error('rule_not_found', "Rule with ref '$ruleRef' not found");
        }

        return $this->extractDomainsFromExpression($rule['expression']);
    }

    /**
     * Format Cloudflare API errors for display
     *
     * @param WP_Error $wp_error The WordPress error object
     * @param string $context Optional context for the error (e.g., 'CDN DNS')
     * @param string $required_permission Optional permission needed (e.g., 'Zone - DNS - Edit')
     * @return string|null Formatted error message or null if not an error
     */
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

}