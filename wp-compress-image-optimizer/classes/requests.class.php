<?php


/**
 * Class - Requests
 * Handles WP Remote POST & GET Requests
 */
class wps_ic_requests
{

  public $responseCode;
  public $responseBody;

  public function __construct() {

  }


  public function getResponseCode($call) {
    $this->responseCode = wp_remote_retrieve_response_code($call);
    return $this->responseCode;
  }


  public function getResponseBody($call) {
    $this->responseBody = wp_remote_retrieve_body($call);
    return $this->responseBody;
  }

  public function getErrorMessage($call) {
    return $call->get_error_message();
  }


  public function POST($url, $urlParams, $configParams = ['timeout' => 30]) {
    $urlParams = ['body' => $urlParams];
    $params = array_merge($urlParams, $configParams);

    $call = wp_remote_post($url, $params);
    return $call;
  }


  public function GET($baseUrl, $params, $configParams = ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]) {

    // Append parameters to the URL
    $url = add_query_arg($params, $baseUrl);

    if (!isset($configParams['timeout']) || $configParams['timeout'] == '0') {
      $configParams['timeout'] = 30;
    }

    $call = wp_remote_get($url, $configParams);

    if (wp_remote_retrieve_response_code($call) == 200) {
      // Successful response
      $body = wp_remote_retrieve_body($call);
      $bodyDecoded = json_decode($body);

      if (empty($bodyDecoded)) {
        return $body;
      } else {
        return $bodyDecoded;
      }

    } else {
      return false;
    }
  }


}