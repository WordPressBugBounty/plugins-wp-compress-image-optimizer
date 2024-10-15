<?php

define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');

class wps_advancedCache
{

  private $siteUrl;
  private $urlKey;
  private $cacheExists = false;
  private $cachedHtml = '';

  private $host;
  private $cachePath;
  private $url_key_class;

  public function __construct()
  {
    if (!file_exists(WPS_IC_CACHE)) {
      mkdir(rtrim(WPS_IC_CACHE, '/'));
    }

    $this->url_key_class = new wps_ic_url_key();
    $this->urlKey = $this->url_key_class->setup();
    $this->cachePath = WPS_IC_CACHE . $this->urlKey . '/';
  }


  public function init()
  {
    return '';
  }


  public function cacheEnabled()
  {
    return true;
  }

  /**
   * FrontEnd Editors Detection for various page builders
   * @return bool
   */
  public static function isPageBuilder()
  {
    $page_builders = ['run_compress', //wpc
      'run_restore', //wpc
      'elementor-preview', //elementor
      'fl_builder', //beaver builder
      'et_fb', //divi
      'preview', //WP Preview
      'builder', //builder
      'brizy', //brizy
      'fb-edit', //avada
      'bricks', //bricks
      'ct_template', //ct_template
      'ct_builder', //ct_builder
      'cs-render', //cornerstone
      'tatsu', //tatsu
      'trp-edit-translation', //thrive
      'brizy-edit-iframe', //brizy
      'ct_builder', //oxygen
      'livecomposer_editor', //livecomposer
      'tatsu', //tatsu
      'tatsu-header', //tatsu-header
      'tatsu-footer', //tatsu-footer
      'tve' //thrive
    ];

    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
      return true;
    }

    if (!empty($page_builders)) {
        foreach ($page_builders as $page_builder) {
            if (isset($_GET[$page_builder])) {
                return true;
            }
        }
    }

    return false;
  }


  /**
   * FrontEnd Editors Detection for various page builders
   * @return bool
   */
  public static function isPageBuilderFE()
  {
    if (class_exists('BT_BB_Root')) {
      if (is_user_logged_in() && !is_admin()) {
        return true;
      }
    }

    return false;
  }


  public static function isFEBuilder()
  {
    if (!empty($_GET['trp-edit-translation']) || !empty($_GET['elementor-preview']) || !empty($_GET['tatsu']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['tve']) || !empty($_GET['et_fb']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['brizy-edit-iframe']) || !empty($_GET['brizy-edit']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
      return true;
    } else {
      return false;
    }
  }


  public function cacheValid($prefix = '')
  {
    return true;

    $cacheFile = $this->cachePath . $prefix . 'index.html';

    if ((!file_exists($cacheFile) || filesize($cacheFile) <= 0) && (!file_exists($cacheFile . '_gzip') || filesize($cacheFile . '_gzip') <= 0)) {
      return false;
    }

    return true;
  }


  public function cacheExpired($prefix = '')
  {
    // Does not work on nginx, kill it
    return false;

    if (!empty($prefix)) {
      $prefix = $prefix . '_';
    }

    $cacheFile = $this->cachePath . $prefix . 'index.html';

    if (!file_exists($cacheFile . '_gzip') && !file_exists($cacheFile)) {
      return true;
    }

    // Hours into minutes into seconds
    $expireInterval = $this->options['cache']['expire'] * 60 * 60;
    $fileModifiedTime = filemtime($cacheFile);

    if ($fileModifiedTime + $expireInterval < time()) {
      unlink($cacheFile);
      return true;
    } else {
      return false;
    }
  }


  public function cacheExists($prefix = '')
  {
    if (!empty($prefix)) {
      $prefix = $prefix . '_';
    }

    if (function_exists('gzencode')) {
      if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && filesize($this->cachePath . $prefix . 'index.html' . '_gzip') > 0) {
        return true;
      }
    }

    if (file_exists($this->cachePath . $prefix . 'index.html') && filesize($this->cachePath . $prefix . 'index.html') > 0) {
      return true;
    } else {
      return false;
    }
  }


  /**
   * Just verify it's not some page test as we don't want those to cache HTML
   * @return void
   */
  public function pageTest()
  {
    return false;
  }


  public function saveGzCache($buffer, $prefix)
  {
    if (!empty($_GET['disable_cache'])) {
      return true;
    }

    $fp = fopen($this->cachePath . $prefix . 'index.html' . '_gzip', 'w+');
    fwrite($fp, gzencode($buffer, 8));
    fclose($fp);


    return $buffer;
  }


  public function saveCache($buffer, $prefix = '')
  {
    if (!empty($_GET['disable_cache'])) {
      return true;
    }

    if (empty($buffer) || strlen($buffer) < 100) {
      return true;
    }

	  if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE){
		  global $post;
		  if ( ! empty( $post->ID ) ) {
			  $preload_warmup = new wps_ic_preload_warmup();
			  $preload_warmup->addError( $post->ID, 'DONOTCACHEPAGE' );
		  }
		  return $buffer;
	  }

    if (!empty($prefix)) {
      $prefix = $prefix . '_';
    }

	  $excludes = get_option('wpc-excludes');
	  $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	  if (!empty($excludes) && !empty($excludes['cache'])){
		  if (in_array($url, $excludes['cache'])) {
			  return $buffer;
		  }
	  }

	  if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
		  return $buffer;
	  }

	  // Disable cache for logged in users
	  if (is_user_logged_in()) {
		  return $buffer;
	  }


    if (!file_exists($this->cachePath)) {
      mkdir(rtrim($this->cachePath, '/'), 0777, true);
    }

    $fp = fopen($this->cachePath . $prefix . 'index.html', 'w+');
    fwrite($fp, $buffer);
    fclose($fp);

    if (function_exists('gzencode')) {
      $this->saveGzCache($buffer, $prefix);
    }

    return $buffer;
  }


  public function getCacheFilePath($prefix = '')
  {
    if (function_exists('readgzfile')) {
      return $this->cachePath . $prefix . '/index.html' . '_gzip';
    }

    return $this->cachePath . $prefix . '/index.html';
  }


  public function setupCacheHeaders($cache_filepath, $type = 'gzip')
  {
	  header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_filepath)) . ' GMT');

	  if (!empty($this->settings['cache_refresh_time']) && $this->settings['cache_refresh_time'] > 0) {
		  $cacheSeconds = $this->settings['cache_refresh_time'] * 60; // Convert minutes to seconds
		  header('Cache-Control: max-age=' . $cacheSeconds . ', public');
		  $expiresTime = time() + $cacheSeconds;
		  header('Expires: ' . gmdate('D, d M Y H:i:s', $expiresTime) . ' GMT');
	  } else {
		  header('Cache-Control: public, max-age=0');
		  header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
	  }

	  header('X-Cache-By: WP Compress - ' . $type);
  }


  public function getCache($prefix = '')
  {
    if (!empty($prefix)) {
      $prefix = $prefix . '_';
    }

    if (function_exists('readgzfile')) {
      if (file_exists($this->cachePath . $prefix . 'index.html' . '_gzip') && is_readable($this->cachePath . $prefix . 'index.html' . '_gzip')) {
        $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html' . '_gzip', 'gzip');
        // Nginx instantly echoes readgzfile instead of saving it to variable.
        readgzfile($this->cachePath . $prefix . 'index.html' . '_gzip');
        exit;
      }
    }

    if (file_exists($this->cachePath . $prefix . 'index.html') && is_readable($this->cachePath . $prefix . 'index.html')) {
      $this->setupCacheHeaders($this->cachePath . $prefix . 'index.html', 'html');
      readfile($this->cachePath . $prefix . 'index.html');
      exit;
    }
  }


  public function is_mobile()
  {
    if (!empty($_GET['simulate_mobile'])) {
      return true;
    }

    if (isset($_SERVER['HTTP_USER_AGENT'])) {
      $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
      if ((preg_match('#^.*(2.0\ MMP|240x320|400X240|mobile|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $agent) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($agent, 0, 4)))) {
        return true;
      }
    }

    return false;
  }


  public function removeCacheFiles($post_id)
  {
    if ($post_id == 'all') {
      self::removeDirectory(WPS_IC_CACHE);
      return;
    }

    if ($post_id != 0) {
      $url = get_permalink($post_id);
    } else {
      $url = home_url();
    }

    $urlKey = $this->url_key_class->setup($url);
    self::removeDirectory(WPS_IC_CACHE . $urlKey);
  }

  public function removeCombinedFiles($post_id)
  {
    if ($post_id == 'all') {
      self::removeDirectory(WPS_IC_COMBINE);
      return;
    }

    if ($post_id != 0) {
      $url = get_permalink($post_id);
    } else {
      $url = home_url();
    }

    $urlKey = $this->url_key_class->setup($url);
    self::removeDirectory(WPS_IC_COMBINE . $urlKey);
  }

  public function removeCriticalFiles($post_id)
  {
    if ($post_id == 'all') {
      self::removeDirectory(WPS_IC_CRITICAL);
      return;
    }

    if ($post_id != 0) {
      $url = get_permalink($post_id);
    } else {
      $url = home_url();
    }

    $urlKey = $this->url_key_class->setup($url);
    self::removeDirectory(WPS_IC_CRITICAL . $urlKey);
  }

  public static function removeDirectory($path)
  {
    $path = rtrim($path, '/');
    $files = glob($path . '/*');
    if (!empty($files)) {
      foreach ($files as $file) {
        is_dir($file) ? self::removeDirectory($file) : unlink($file);
      }
    }

	  $files = glob($path . '/*');
	  if (is_dir($path) && empty($files)) {
		  rmdir($path);
	  }
  }

  public function recursiveDelete($folder)
  {
    // Delete all the files in the folder
    $files = glob($folder . '/*');
    foreach ($files as $file) {
      if (is_file($file)) {
        unlink($file);
      } else {
        $this->recursiveDelete($file);
      }
    }

    // Delete the folder itself
    if (is_dir($folder)) rmdir($folder);
  }



}