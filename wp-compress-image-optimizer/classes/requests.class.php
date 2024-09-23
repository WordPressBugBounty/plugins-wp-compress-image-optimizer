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
//    if (wp_remote_retrieve_response_code($call) == 200) {
//      // Successful response
//      $body = wp_remote_retrieve_body($call);
//      $bodyDecoded = json_decode($body);
//
//      if (empty($bodyDecoded)) {
//        return $body;
//      } else {
//        return $bodyDecoded;
//      }
//
//    } else {
//      return false;
//    }
  }

  public function GET($baseUrl, $params, $configParams = ['timeout' => 30, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:20.0) Gecko/20100101 Firefox/20.0']) {

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