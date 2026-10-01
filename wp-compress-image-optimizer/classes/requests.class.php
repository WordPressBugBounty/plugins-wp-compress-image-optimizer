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
    $urlParams = ['body' => wp_json_encode($urlParams)];
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


  /**
   * One keys-server call with a typed answer. GET() answers false for every non-200 and the raw
   * string for a non-JSON 200, and no caller can tell a broken keys from a slow one: on
   * 2026-09-24 keys answered "Error: File not found - WPC_API_WHITELIST" as a 200 text body and
   * every Refresh Connection reported "provisioning server answering slowly". Connect and
   * Refresh use this; the other GET callers migrate when touched.
   *
   * A refusal (`success:false`) never hands back its data: since keys d9b24cde (hub ask 044,
   * 2026-09-28) the four Cloudflare doors answer an empty token with
   * `{success:false, data:{code:'cf-token-missing', cfName:<the row's own host>}}`, and a caller
   * that read `data->cfName` stored the old host back as if it were the new one (the portal's
   * "change Cloudflare host" kept the old host with no message). The refusal's `code` and, for
   * `cdn_setcname`, its `step` (`zone_lookup`, `cname_invalid`, `registered_on_other_zone`,
   * `verify_failed`; keys wrote nothing) are returned on their own so the caller can say why.
   *
   * @return array ['ok' => bool, 'data' => object|null, 'why' => ''|'timeout'|'transport:<msg>'|'http:<code>'|'non-json'|'no-data'|'refused:<code|step|msg>', 'code' => string, 'step' => string, 'ms' => int]
   */
  public function keys($action, array $params, $timeout = 8) {
    $t0   = microtime(true);
    $url  = add_query_arg(array_merge(['action' => $action], $params), WPS_IC_KEYSURL);
    $call = wp_remote_get($url, ['timeout' => (int) $timeout, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    $out  = ['ok' => false, 'data' => null, 'why' => '', 'code' => '', 'step' => '', 'ms' => 0];
    if (is_wp_error($call)) {
      $msg = $call->get_error_message();
      $out['why'] = (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) ? 'timeout' : 'transport:' . substr($msg, 0, 60);
    } else {
      $code = (int) wp_remote_retrieve_response_code($call);
      if ($code !== 200) {
        $out['why'] = 'http:' . $code;
      } else {
        $body = json_decode(wp_remote_retrieve_body($call));
        if (!is_object($body)) {
          $out['why'] = 'non-json';
        } elseif (isset($body->success) && $body->success === false) {
          $refusal = $body->data ?? null;
          if (is_object($refusal)) {
            $out['code'] = isset($refusal->code) && is_scalar($refusal->code) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $refusal->code), 0, 60) : '';
            $out['step'] = isset($refusal->step) && is_scalar($refusal->step) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $refusal->step), 0, 60) : '';
          }
          $named = $out['code'] !== '' ? $out['code'] : $out['step'];
          $out['why'] = 'refused:' . ($named !== '' ? $named : (is_string($refusal) ? substr($refusal, 0, 80) : 'no reason'));
        } elseif (!isset($body->data)) {
          $out['why'] = 'no-data';
        } else {
          $out['ok']   = true;
          $out['data'] = $body->data;
        }
      }
    }
    $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
    if (!$out['ok'] && function_exists('wpc_cache_first_log')) {
      wpc_cache_first_log('keys-call-failed', '', '', ['action' => (string) $action, 'why' => $out['why'], 'ms' => $out['ms']]);
    }
    return $out;
  }


  /**
   * The per-part verdict keys attaches to a setupCF / refreshCF answer (keys d9b24cde, hub ask
   * 044): `data.cf = {ip_allow, cache_rules, dns}`, each `{ok:true}` or `{ok:false, error}` with
   * Cloudflare's message. Keys kept the success shape so old plugins still store the host; a part
   * that Cloudflare refused shows only here. Before this every answer read as "Registered", also
   * when Cloudflare had refused keys' allow-list or DNS write. Answers without the block (older
   * keys) read as parts:[] and change nothing. Logs `keys-cf-parts` when the block is present.
   *
   * @return array ['parts' => [name => ['ok' => bool, 'error' => string]], 'failed' => string[], 'detail' => string]
   */
  public static function keys_cf_parts($data, $action = '') {
    $block = is_object($data) && isset($data->cf) ? $data->cf : (is_array($data) && isset($data['cf']) ? $data['cf'] : null);
    $block = is_object($block) ? (array) $block : (is_array($block) ? $block : []);
    $labels = ['ip_allow' => 'IP allow list', 'cache_rules' => 'cache rules', 'dns' => 'DNS record'];
    $out = ['parts' => [], 'failed' => [], 'detail' => ''];
    $said = [];
    foreach ($labels as $name => $label) {
      if (!isset($block[$name])) {
        continue;
      }
      $part = is_object($block[$name]) ? (array) $block[$name] : (is_array($block[$name]) ? $block[$name] : []);
      $ok = !empty($part['ok']);
      $error = (!$ok && isset($part['error']) && is_scalar($part['error'])) ? substr(trim(strip_tags((string) $part['error'])), 0, 160) : '';
      $out['parts'][$name] = ['ok' => $ok, 'error' => $error];
      if (!$ok) {
        $out['failed'][] = $name;
      }
      $said[] = $label . ' ' . ($ok ? 'ok' : 'failed' . ($error !== '' ? ' (' . $error . ')' : ''));
    }
    if ($said) {
      $out['detail'] = 'Cloudflare writes by the provisioning server: ' . implode(', ', $said);
      if (function_exists('wpc_cache_first_log')) {
        $fields = ['action' => (string) $action];
        foreach ($out['parts'] as $name => $part) {
          $fields[$name] = $part['ok'] ? 'ok' : ('failed' . ($part['error'] !== '' ? ':' . substr($part['error'], 0, 80) : ''));
        }
        wpc_cache_first_log('keys-cf-parts', '', '', $fields);
      }
    }
    return $out;
  }


}