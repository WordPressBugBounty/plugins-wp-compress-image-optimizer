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
            return new WP_Error('cloudflare_api_error', 'Cloudflare API returned errors.', $data['errors']);
        }

        return $data;
    }

    /**
     * Retrieve the list of zones
     *
     * @return array|WP_Error List of zones or WP_Error
     */
    public function listZones() {
        return $this->getRequest('zones');
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
}