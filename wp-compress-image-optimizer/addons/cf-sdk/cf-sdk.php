<?php

#define('WPC_CF_TOKEN', 'vPn-BuupnJ3VmJUAVPt0V7BaeWvFID_ljh_2UMoz');


class WPC_CloudflareAPI {
    private $apiToken;
    private $apiBase = 'https://api.cloudflare.com/client/v4/';

    /**
     * Constructor to initialize the API token
     *
     * @param string $apiToken Your Cloudflare API token
     */
    public function __construct($apiToken = '') {

        if (empty($apiToken)) {
            // Nothing
            return false;
        }

        $this->apiToken = $apiToken;
    }

    /**
     * Send a GET request to the Cloudflare API
     *
     * @param string $endpoint API endpoint
     * @param array $query Optional query parameters
     * @return array|WP_Error The API response or WP_Error
     */
    private function getRequest($endpoint, $query = []) {
        $url = add_query_arg($query, $this->apiBase . $endpoint);

        $response = wp_remote_get($url, [
            'headers' => $this->getHeaders(),
        ]);

        return $this->processResponse($response);
    }

    /**
     * Send a POST request to the Cloudflare API
     *
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @return array|WP_Error The API response or WP_Error
     */
    private function postRequest($endpoint, $body = []) {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_post($url, [
            'headers' => $this->getHeaders(),
            'body'    => json_encode($body),
        ]);

        return $this->processResponse($response);
    }

    /**
     * Get standard headers for the API requests
     *
     * @return array
     */
    private function getHeaders() {
        return [
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Process the API response
     *
     * @param array|WP_Error $response API response
     * @return array|WP_Error Parsed response or WP_Error
     */
    private function processResponse($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['errors'])) {
            $error_messages = array_map(function($error) {
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
    public function listZones($page = 1) {
        return $this->getRequest('zones', ['per_page' => 50, 'page' => $page]);
    }

    /**
     * Purge all cache for a specific zone
     *
     * @param string $zoneId Cloudflare Zone ID
     * @return array|WP_Error The API response or WP_Error
     */
    public function purgeCache($zoneId) {
        return $this->postRequest("zones/$zoneId/purge_cache", [
            'purge_everything' => true,
        ]);
    }

    /**
     * Purge specific files from the cache
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $files List of file URLs to purge
     * @return array|WP_Error The API response or WP_Error
     */
    public function purgeFiles($zoneId, $files) {
        return $this->postRequest("zones/$zoneId/purge_cache", [
            'files' => $files,
        ]);
    }

    /**
     * Whitelist IPs in the Cloudflare Firewall
     *
     * @param string $zoneId Cloudflare Zone ID
     * @param array $ipList List of IPs or IP ranges to whitelist
     * @return array|WP_Error The API response or WP_Error
     */
    public function whitelistIPs($zoneId) {
        if (!file_exists(WPC_API_WHITELIST)) {
            die("Error: File not found - WPC_API_WHITELIST");
        }

        $errors = false;
        $contents = file_get_contents(WPC_API_WHITELIST);
        $ipList = array_filter(array_map('trim', explode("\n", $contents)));

        foreach ($ipList as $ip) {
            if (strpos($ip, ':') !== false) {
                #echo "Adding IPv6 range $ip to firewall rules.\n";
                $success = $this->addIpAccessRule($zoneId, $ip);
            } else {
                #echo "Adding IP $ip to access rules.\n";
                $success = $this->addIpAccessRule($zoneId, $ip);
            }

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



    public function expandIPv6($ip) {
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



    public function removeFirewallRule($zoneId, $ip) {
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




    public function addFirewallRule($zoneId, $ip) {
        $url = 'zones/' . $zoneId . '/firewall/rules';
        $body = [
            "action" => "allow",
            "description" => "WP Compress API - IPv6 Range",
            "filter" => [
                "expression" => "ip.src in {\"$ip\"}",
                "paused" => false
            ]
        ];

        $response = $this->postRequest($url, $body);
        var_dump($response);
    }


    public function addIpAccessRule($zoneId, $ip)
    {
        $url = 'zones/'.$zoneId."/firewall/access_rules/rules";

        $body = [
            "mode" => 'whitelist',
            "configuration" => [
                "target" => "ip",
                "value" => $ip,
            ],
            "notes" => 'WP Compress API Endpoint'
        ];

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



    public function removeIpAccessRule($zoneId, $ip) {
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
                    var_dump($r);
                    #$r = $this->deleteRequest('zones/' . $zoneId . '/firewall/access_rules/rules/' . $ruleId);
                    return $r;
                }
            }
        }
    }


    public function deleteRequest($endpoint) {
        $url = $this->apiBase . $endpoint;

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => $this->getHeaders(),
        ]);

        return $this->processResponse($response);
    }

}