<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/inline_css.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */


class wps_ic_inline_css
{

  public static $excludes;
  


  public $patterns;

  public function __construct()
  {
    self::$excludes = new wps_ic_excludes();
    $this->patterns = [
      '/<link[^>]*rel=[\"|\']stylesheet[\"|\'][^>]*>/si',
      '/(?<!<noscript>)<style\b[^>]*\>(.*?)<\/style\>?/si',
      '/<link\b[^>](.*?)onload=[\"|\']this.rel=[\"|\']stylesheet[\"|\'][\"|\'](.*?)>/'
    ];
  }

  public function doInline($html)
  {


    $html = preg_replace_callback('/<link\s[^>]*href=(["\'])(.*?)\1[^>]*>/si', [$this, 'doSearch'], $html);

    return $html;
  }


  public function doSearch($html) {
    if (preg_match('/\.css(\?.*)?$/i', $html[0])) {
      $html = $this->inlineCSS($html[0]);
      return $html;
    }

    return $html[0];
  }


  public function inlineCSS($tag)
  {
    preg_match('/href=(["\'])(.*?)\1/is', $tag, $matches);

    
    if (!$matches || empty($matches[2])) return $tag;

    
    $src = $matches[2];

    
    if (empty($src)) return $tag;

    
    if (strpos($src, 'ie9') !== false) {
      return $tag;
    }

    if (strpos($src, '10764') === false) {
      return $tag;
    }

    
    $src = explode('?', $src);
    $src = $src[0];

    preg_match('/a:(.*?)(\?|$)/', $src, $match);
    $src = trim($match[1]);

    $path = wp_make_link_relative($src);

    
    

    $check = wp_http_validate_url($src);
    if ($check) {
      $content = $this->getLocalContent($src);
    } else {
      $content = $this->getLocalContent(get_home_url() . $src);
    }

    return '<style class="wps_inline" type="text/css">' . $content . '</style>';
  }


  public function getRemoteContent($url)
  {
    if (strpos($url, '//') === 0) {
      $url = 'https:' . $url;
    }

    $data = wp_remote_get($url, array('timeout' => (int) apply_filters('wpc_combine_fetch_timeout', 3)));


    if (is_wp_error($data)) {
      return false;
    }

    return wp_remote_retrieve_body($data);
  }

  public function getLocalContent($url)
  {
    if ($this->hmwpReplace) {

      foreach ($this->hmwp_rewrite->_replace['to'] as $key => $value) {
        $replace = $this->hmwp_rewrite->_replace['from'][$key];
        $url = str_replace($value, $replace, $url);
      }
    }

    if (strpos($url, $this->zone_name) !== false) {
      preg_match('/a:(.*?)(\?|$)/', $url, $match);
      $url = trim($match[1]);
    }

    $url = preg_replace('/\?.*/', '', $url);

    $path = wp_make_link_relative($url);
    $path = ltrim($path, '/');


    $last_abspath = basename(ABSPATH);
    $first_path = explode('/', $path)[0];
    if ($last_abspath == $first_path) {
      $path = substr($path, strlen($first_path));
      $path = ltrim($path, '/');
    }

    $content = file_get_contents(ABSPATH . $path);

    if (!$content) {
      return false;
    }

    return $content;
  }
}