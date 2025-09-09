<?php


class wps_ic_oEmbed
{
  
  public function __construct() {}
  
  
  public function run()
  {
    /* @var WP $wp */
    global $wp;
    
    // Remove the embed query var.
    $wp->public_query_vars = array_diff($wp->public_query_vars, [
        'embed',
    ]);
    
    add_filter('rest_endpoints', [$this, 'removeEndpoint']);
    add_filter('oembed_response_data', [$this, 'filterResponseData']);
    add_filter('embed_oembed_discover', '__return_false');
    
    remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
    
    add_filter('tiny_mce_plugins', [$this, 'tineMceRemove']);
    add_filter('rewrite_rules_array', [$this, 'embedsRewrites']);
    add_action('wp_default_scripts', [$this, 'removeScriptDeps']);
  }
  
  
  public function embedsRewrites($rules)
  {
    foreach ($rules as $rule => $rewrite) {
      if (false !== strpos($rewrite, 'embed=true')) {
        unset($rules[$rule]);
      }
    }
    
    return $rules;
  }
  
  
  public function removeScriptDeps($scripts)
  {
    if ( ! empty($scripts->registered['wp-edit-post'])) {
      $scripts->registered['wp-edit-post']->deps = array_diff(
          $scripts->registered['wp-edit-post']->deps,
          ['wp-embed']
      );
    }
  }
  
  
  public function tineMceRemove($plugins)
  {
    return array_diff($plugins, ['wpembed']);
  }
  
  
  public function removeEndpoint($endpoints)
  {
    unset($endpoints['/oembed/1.0/embed']);
    
    return $endpoints;
  }
  
  
  public function filterResponseData($data)
  {
    if (defined('REST_REQUEST') && REST_REQUEST) {
      return false;
    }
    
    return $data;
  }
  
}