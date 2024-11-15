<?php

//Used for combine and critical, cache handles this in its own class


class wps_ic_url_key
{

  public $urlKey;
  public $url;
  public $trp_active;

  public function __construct()
  {
    $this->trp_active = 0;

    if (class_exists('TRP_Translate_Press')) {
      $this->trp_active = 1;
      $this->trp_settings = get_option('trp_settings');
    }

  }

  public function setup($url = '')
  {

		if ($url == ''){
			$url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}

	  $url = str_replace(['https://', 'http://'], '', $url);
	  $url = rtrim($url, '?');
	  $url = rtrim($url, '/');
	  $url = str_replace('wpc_visitor_mode=true', '', $url);
	  $url = str_replace('?remote_generate_critical=true', '', $url);
	  $url = str_replace('dbgCache=true', '', $url);
	  $url = preg_replace('/&?forceRecombine=true.*/', '', $url);
	  $url = rtrim($url, '?');
	  $url = rtrim($url, '/');

    $url = str_replace(['?'], '', $url);
    $url = str_replace(['='], '-', $url);
	  $url = str_replace(['&'], '_', $url);

    $this->urlKey = $this->createUrlKey($url);

	  return $this->urlKey;

  }

	public function is_external($url){

    if (empty($url)) {
      return false;
    }

		$site_url = home_url();
		$url = str_replace(['https://', 'http://'], '', $url);
		$site_url = str_replace(['https://', 'http://'], '', $site_url);

		if (strpos($url, '/') === 0 && strpos($url, '//') === false ){
			// Image on site
			return false;
		} else if (strpos($url, $site_url) === false || strpos($url, '//') === 0) {
			// Image not on site
			return true;
		} else {
			// Image on site
			return false;
		}
	}

	public function removeUrl($url)
	{
		$siteUrl = home_url();
		$noUrl = str_replace($siteUrl, '', $url);

		//remove our remote trigger from url
		$noUrl = str_replace('&remote_generate_critical=1', '', $noUrl);
		$noUrl = str_replace('&apikey=' . get_option(WPS_IC_OPTIONS)['api_key'], '', $noUrl);

		//TranslatePress language remove from url
		//we have to remove only the first occurrence
		if ($this->trp_active) {
			global $TRP_LANGUAGE;

			if ($TRP_LANGUAGE == $this->trp_settings['default-language']) {

				if (isset($this->trp_settings['add-subdirectory-to-default-language']) && $this->trp_settings['add-subdirectory-to-default-language'] == 'yes') {
					//if default language is set to be displayed, do replace
					$pos = strpos($noUrl, $this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/');
					if ($pos !== false) {
						$noUrl = substr_replace($noUrl, '', $pos, strlen($this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/'));
					}
				}
			} else {
				//replace for non default languages
				$pos = strpos($noUrl, $this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/');
				if ($pos !== false) {
					$noUrl = substr_replace($noUrl, '', $pos, strlen($this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/'));
				}
			}
		}

		return $noUrl;
	}


  public function createUrlKey($url)
  {
    $url = str_replace(['http://', 'https://'], '', $url);

    if (strpos($url, '?testCritical') !== false) {
      $url = explode('?', $url);
      $url = $url[0];
    }

    if (strpos($url, '?dbgCache') !== false) {
      $url = explode('?', $url);
      $url = $url[0];
    }

    if (strpos($url, '?dbg_') !== false) {
      $url = explode('?', $url);
      $url = $url[0];
    }

    return urldecode(rtrim($url, '/'));
  }

	public function get_allowed_params(){
		$allowed_params = ['lang', 'wpc_visitor_mode'];
		return $allowed_params;
	}

}