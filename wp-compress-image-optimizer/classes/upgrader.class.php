<?php


class wps_ic_upgrader extends wps_ic
{
  public static $options;
  
  function __construct()
  {
    if ( ! $this->is_latest() || ! empty($_GET['force_update'])) {
      self::$options = get_option(WPS_IC_OPTIONS);
      
      //$this->setup_default_options();
      if (file_exists(WPS_IC_DIR.'local_script_decode.txt')) {
        unlink(WPS_IC_DIR.'local_script_decode.txt');
      }
      
      if (file_exists(WPS_IC_DIR.'local_script_encode_2.txt')) {
        unlink(WPS_IC_DIR.'local_script_encode_2.txt');
      }
      
      // Purge CDN
      $this->purge_cdn();
      
      // Upgrade CDN
      $this->update_to_latest();
      
      // Notify API
      $this->api_notify();
    }
  }
  
  
  public function api_notify()
  {
    $apikey = self::$options['api_key'];
    $siteurl = urlencode(site_url());
    $zone_name = get_option('ic_cdn_zone_name');
    $site_type = is_multisite() ? 'multisite' : 'single';
    
    // Setup URI
    $uri = WPS_IC_KEYSURL.'?action=upgrade_notify&apikey='.$apikey.'&site_type='.$site_type.'&domain='.$siteurl.'&zone_name='.$zone_name.'&hash='.md5(time()).'&time_hash='.time();
    
    // Verify API Key is our database and user has is confirmed getresponse
    $get = wp_remote_get($uri, ['timeout' => 60, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:20.0) Gecko/20100101 Firefox/20.0']);
    
    if (wp_remote_retrieve_response_code($get) == 200) {
      $body = wp_remote_retrieve_body($get);
      $body = json_decode($body);
      $zonename = $body->data;
      
      if ($body->success) {
        if ( ! empty($zonename) && $zonename != '') {
          #update_option('ic_cdn_zone_name', $zonename);
        }
      }
    }
  }
  
  
  public function upgrade()
  {
    return;
    $old_settings = get_option(WPS_IC_SETTINGS);
    $default_Settings = [
        'js'                     => '0',
        'css'                    => '0',
        'css_image_urls'         => '0',
        'external-url'           => '0',
        'replace-all-link'       => '0',
        'emoji-remove'           => '0',
        'disable-oembeds'        => '0',
        'disable-gutenber'       => '0',
        'disable-dashicons'      => '0',
        'on-upload'              => '0',
        'defer-js'               => '0',
        'serve'                  => ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1'],
        'search-through'         => 'html',
        'preserve-exif'          => '0',
        'background-sizing'      => '0',
        'remove-render-blocking' => '0',
        'minify-css'             => '0',
        'minify-js'              => '0',
        'fonts'                  => '0',
    ];
    
    foreach ($default_Settings as $name => $defaultValue) {
      if ( ! isset($old_settings[$name]) || empty($old_settings[$name])) {
        $old_settings[$name] = $defaultValue;
      }
    }
    
    update_option(WPS_IC_SETTINGS, $old_settings);
  }
  
  
  public function setup_default_options()
  {
    $old_settings = get_option(WPS_IC_SETTINGS);
    $default_Settings = [
        'js'                => '0',
        'css'               => '0',
        'css_image_urls'    => '0',
        'external-url'      => '0',
        'replace-all-link'  => '0',
        'emoji-remove'      => '1',
        'disable-oembeds'   => '1',
        'disable-gutenber'  => '0',
        'disable-dashicons' => '0',
        'on-upload'         => '0',
        'defer-js'          => '0',
        'serve'             => ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1'],
        'search-through'    => 'html'
    ];
    
    foreach ($default_Settings as $name => $defaultValue) {
      if ( ! isset($old_settings[$name]) || empty($old_settings[$name])) {
        $old_settings[$name] = $defaultValue;
      }
    }
    
    update_option(WPS_IC_SETTINGS, $old_settings);
  }
  
  
  public function update_to_latest()
  {
    $options = get_option(WPS_IC_OPTIONS);
    
    if ( ! empty(parent::$version)) {
      $options['css_hash'] = parent::$version;
    }
    else {
      $options['css_hash'] = mt_rand(100, 999);
    }
    
    update_option(WPS_IC_OPTIONS, $options);
    
    update_option('wpc_version', parent::$version);
  }
  
  
  public function is_latest()
  {
    $plugin_version = get_option('wpc_version');
    
    if (empty($plugin_version) || version_compare($plugin_version, parent::$version, '<')) {
      // Must Upgrade
      return false;
    }
    else {
      return true;
    }
  }
  
  
  public function purge_cdn()
  {
    self::purgeBreeze();
    self::purge_cache_files();

    //Clear preloaded pages list
    update_option('wpc_preloaded_status', []);
    
    // Clear cache.
    if (function_exists('rocket_clean_domain')) {
      rocket_clean_domain();
    }
    
    // Lite Speed
    if (defined('LSCWP_V')) {
      do_action('litespeed_purge_all');
    }
    
    // HummingBird
    if (defined('WPHB_VERSION')) {
      do_action('wphb_clear_page_cache');
    }
  }
  
  
  public static function purgeBreeze()
  {
    if (defined('BREEZE_VERSION')) {
      global $wp_filesystem;
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      
      WP_Filesystem();
      
      $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
      $wp_filesystem->rmdir(untrailingslashit($cache_path), true);
      
      if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
      }
    }
  }
  
  
  public static function purge_cache_files()
  {
    $cache_dir = WPS_IC_CACHE;
    
    self::removeDirectory($cache_dir);
    
    return true;
  }
  
  
  public static function removeDirectory($path)
  {
    $path = rtrim($path,'/');
    $files = glob($path.'/*');
    foreach ($files as $file) {
      is_dir($file) ? self::removeDirectory($file) : unlink($file);
    }
    
    return;
  }
  
  
  public function upgrade_cdn()
  {
    $url = 'https://keys.wpmediacompress.com/?action=updateCDN&apikey='.self::$options['api_key'].'&site='.site_url();
    
    $call = wp_remote_get($url, [
        'timeout'    => 10,
        'sslverify'  => 'false',
        'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:20.0) Gecko/20100101 Firefox/20.0'
    ]);
  }
  
  
}