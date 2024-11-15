<?php


/**
 * Class - Config
 * Handles Configuration Files
 */
class wps_ic_config
{

  private $configPath;
  private $cacheConfigFile;

  public function __construct()
  {
    $this->configPath = WP_CONTENT_DIR . '/wp-cio-config';
    $this->cacheConfigFile = $this->configPath . '/cache.config.php';
  }


  public function getConfigPath() {
    return $this->cacheConfigFile;
  }


  public function generateConfigContent() {
    $output  = "<?php\n";
    $output .= "defined( 'ABSPATH' ) || exit;\n\n";

    $output .= '$wpcio_cookie_hash = \'' . COOKIEHASH . "';\n";
    $output .= '$wpcio_logged_in_cookie = \'' . LOGGED_IN_COOKIE . "';\n";
    return $output;
  }


  public function generateCacheConfig(){
    if (!$this->exists($this->configPath)) {
      mkdir($this->configPath, 0777, true);
    }

    if (!$this->exists($this->cacheConfigFile)) {
      return $this->fileSystem()->put_contents($this->cacheConfigFile, $this->generateConfigContent(), 0644);
    } else {
      unlink($this->cacheConfigFile);
      return $this->fileSystem()->put_contents($this->cacheConfigFile, $this->generateConfigContent(), 0644);
    }
  }


  public function fileSystem()
  {
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
    return new WP_Filesystem_Direct(new stdClass());
  }

  public function exists($path)
  {
    if ($this->fileSystem()->exists($path)) {
      return true;
    }

    return false;
  }

  public function isWriteable($path)
  {
    if ($this->fileSystem()->is_writable($path)) {
      return true;
    }

    return false;
  }

  public function isReadble($path)
  {
    if ($this->fileSystem()->is_readable($path)) {
      return true;
    }

    return false;
  }



}