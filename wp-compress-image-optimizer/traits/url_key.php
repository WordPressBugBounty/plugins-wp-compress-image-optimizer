<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: traits/url_key.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */










if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strpos((string) $haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strncmp((string) $haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}


class wps_ic_url_key
{
    public $urlKey;
    public $url;
    public $trp_active;
    public $trp_settings;
	private static $url_mappings = [];

    





    private static $captured_request_url = null;

    




    public static function captureRequestUrl()
    {
        if (self::$captured_request_url === null) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri  = $_SERVER['REQUEST_URI'] ?? '';
            self::$captured_request_url = $host . $uri;
        }
    }

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
      if (empty($url)) {
          
          
          if (self::$captured_request_url !== null) {
              $url = self::$captured_request_url;
          } else {
              $host = $_SERVER['HTTP_HOST'] ?? '';
              $uri  = $_SERVER['REQUEST_URI'] ?? '';
              $url = $host . $uri;
          }
      }

	    $original_url = $url;

        $url = str_replace(['https://', 'http://'], '', $url);
        $url = rtrim($url, '?');
        $url = rtrim($url, '/');
        $url = str_replace('wpc_visitor_mode=true', '', $url);
        $url = str_replace('?remote_generate_critical=true', '', $url);
        $url = str_replace('dbgCache=true', '', $url);
        $url = str_replace('forceCritical=true', '', $url);
        $url = str_replace('removeCritical=true', '', $url);
        $url = preg_replace('/&?forceRecombine=true.*/', '', $url);

        $url = $this->removeTrackingParams($url);

        $url = rtrim($url, '?');
        $url = rtrim($url, '/');

        $url = str_replace(['?'], '', $url);
        $url = str_replace(['='], '-', $url);
        $url = str_replace(['&'], '_', $url);

        $this->urlKey = $this->createUrlKey($url);

	    
	    self::$url_mappings[$this->urlKey] = $original_url;

        return $this->urlKey;
    }

    






    public static function trackingParams()
    {
        return ['disable_cache', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_expid', 'utm_term', 'utm_content', 'mtm_source', 'mtm_medium', 'mtm_campaign', 'mtm_keyword', 'mtm_cid', 'mtm_content', 'pk_source', 'pk_medium', 'pk_campaign', 'pk_keyword', 'pk_cid', 'pk_content', 'fb_action_ids', 'fb_action_types', 'fb_source', 'fbclid', 'campaignid', 'adgroupid', 'adid', 'gclid', 'age-verified', 'ao_noptimize', 'usqp', 'cn-reloaded', '_ga', 'sscid', 'gclsrc', '_gl', 'mc_cid', 'mc_eid', '_bta_tid', '_bta_c', 'trk_contact', 'trk_msg', 'trk_module', 'trk_sid', 'gdfms', 'gdftrk', 'gdffi', '_ke', 'redirect_log_mongo_id', 'redirect_mongo_id', 'sb_referer_host', 'mkwid', 'pcrid', 'ef_id', 's_kwcid', 'msclkid', 'dm_i', 'epik', 'pp', 'gbraid', 'wbraid', 'utm_id', 'wpc_key'];
    }

    














    public static function controlParams()
    {
        return ['disable_cache', 'ao_noptimize', 'age-verified', 'cn-reloaded', 'usqp',
            'disablewpc', 'wpc_visitor_mode', 'remote_generate_critical', 'dbgcache',
            'forcecritical', 'removecritical', 'forcerecombine', 'crit', 'cdn', 'nocache',
            'wpc_no_buffer', 'wpc_smoke', 'wpcdoc', 'wpc_perf_debug', 'wpc_img_debug',
            'wpc_tier', 'wpc_key'];
    }

    public static function tierNames()
    {
        return ['control', 'free', 'local', 'edge'];
    }

    








    public static function tierKey($wpc_mint743 = true)
    {
        if (defined('WPC_TIER_KEY') && (string) WPC_TIER_KEY !== '') {
            return (string) WPC_TIER_KEY;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $wpc_k743 = (string) get_option('wpc_tier_key', '');
        if ($wpc_k743 !== '' || !$wpc_mint743) {
            return $wpc_k743;
        }
        if (function_exists('random_bytes')) {
            try { $wpc_k743 = bin2hex(random_bytes(16)); } catch (\Throwable $e) { $wpc_k743 = ''; }
        }
        if ($wpc_k743 === '' && function_exists('wp_generate_password')) {
            $wpc_k743 = (string) wp_generate_password(32, false, false);
        }
        if ($wpc_k743 !== '' && function_exists('update_option')) {
            update_option('wpc_tier_key', $wpc_k743, false);
        }
        return $wpc_k743;
    }

    





    public static function tierCacheOn()
    {
        
        
        
        return defined('WPC_TIER_CACHE') && WPC_TIER_CACHE;
    }

    






    public static function tierWriteBlocked()
    {
        $wpc_qs739 = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($wpc_qs739 === '' || stripos($wpc_qs739, 'wpc_tier') === false) {
            return false;
        }
        return !(defined('WPC_TIER_ACTIVE') && WPC_TIER_ACTIVE);
    }

    













    public static function queryIsCacheable($queryString, $wpc_ctx739 = 'read')
    {
        $queryString = (string) $queryString;
        if (trim($queryString) === '') {
            return true;
        }
        $wpc_ctrl598 = array_flip(self::controlParams());
        $wpc_ok598 = array_flip(array_map('strtolower', self::trackingParams()));
        
        
        foreach (['dclid', 'ref', 'igshid', 'ttclid'] as $wpc_lg598) {
            $wpc_ok598[$wpc_lg598] = 1;
        }
        $wpc_q598 = [];
        parse_str($queryString, $wpc_q598);
        
        
        
        
        
        
        if (self::tierCacheOn()) {
            $wpc_tk739 = '';
            foreach ((array) $wpc_q598 as $wpc_qk739 => $wpc_qv739) {
                if (strtolower((string) $wpc_qk739) === 'wpc_tier') { $wpc_tk739 = (string) $wpc_qv739; }
            }
            if ($wpc_tk739 !== '') {
                $wpc_tn739 = strtolower(preg_replace('/[^A-Za-z]/', '', $wpc_tk739));
                if (!in_array($wpc_tn739, self::tierNames(), true)) {
                    return false;
                }
                if ($wpc_ctx739 === 'write' && !(defined('WPC_TIER_ACTIVE') && WPC_TIER_ACTIVE)) {
                    return false;
                }
                foreach (array_keys((array) $wpc_q598) as $wpc_qk739) {
                    if (in_array(strtolower((string) $wpc_qk739), ['wpc_tier', 'wpc_key'], true)) {
                        unset($wpc_q598[$wpc_qk739]);
                    }
                }
            }
        }
        foreach (array_keys((array) $wpc_q598) as $wpc_k598) {
            $wpc_k598 = strtolower((string) $wpc_k598);
            if (isset($wpc_ctrl598[$wpc_k598])) {
                return false;
            }
            
            
            if (strpos($wpc_k598, 'utm_') === 0 || strpos($wpc_k598, 'mtm_') === 0
                || strpos($wpc_k598, 'pk_') === 0) {
                continue;
            }
            if (!isset($wpc_ok598[$wpc_k598])) {
                return false;
            }
        }
        return true;
    }

    public function removeTrackingParams($url)
    {
        if (empty($url) || !$url) return;


        $trackingParams = self::trackingParams();

        $parts = parse_url($url);

        if (!isset($parts['query'])) {
            return $url; 
        }

        parse_str($parts['query'], $query);

        foreach ($trackingParams as $param) {
            unset($query[$param]);
        }

        $queryString = http_build_query($query);

        
        $cleanUrl = '';

        if (isset($parts['scheme'])) {
            $cleanUrl .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $cleanUrl .= $parts['user'];
            if (isset($parts['pass'])) {
                $cleanUrl .= ':' . $parts['pass'];
            }
            $cleanUrl .= '@';
        }

        if (isset($parts['host'])) {
            $cleanUrl .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $cleanUrl .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            $cleanUrl .= $parts['path'];
        }

        if ($queryString) {
            $cleanUrl .= '?' . $queryString;
        }

        if (isset($parts['fragment'])) {
            $cleanUrl .= '#' . $parts['fragment'];
        }

        return $cleanUrl;
    }

    public function createUrlKey($url)
    {
        $url = str_replace(['http://', 'https://'], '', $url);

        if (strpos($url, '?testCritical') !== false) {
            $url = explode('?', $url)[0];
        }

        if (strpos($url, '?dbgCache') !== false) {
            $url = explode('?', $url)[0];
        }

        if (strpos($url, '?dbg_') !== false) {
            $url = explode('?', $url)[0];
        }

        return $this->sanitize_title(urldecode(rtrim($url, '/')));
    }

    private function sanitize_title($title, $fallback_title = '', $context = 'save')
    {
        $raw_title = $title;

        if ('save' === $context) {
            $title = $this->remove_accents($title);
        }

        $title = $this->sanitize_title_with_dashes($title);

        if ('' === $title || false === $title) {
            $title = $fallback_title;
        }

        return $title;
    }

    private function remove_accents($string)
    {
        if (!preg_match('/[\x80-\xff]/', $string)) {
            return $string;
        }

        if ($this->seems_utf8($string)) {
            return strtr($string, $this->utf8_char_map());
        } else {
            return $string; 
        }
    }

    private function seems_utf8($str)
    {
        $this->mbstring_binary_safe_encoding();
        $length = strlen($str);
        $this->reset_mbstring_encoding();
        for ($i = 0; $i < $length; $i++) {
            $c = ord($str[$i]);
            if ($c < 0x80) {
                $n = 0;
            } elseif (($c & 0xE0) == 0xC0) {
                $n = 1;
            } elseif (($c & 0xF0) == 0xE0) {
                $n = 2;
            } elseif (($c & 0xF8) == 0xF0) {
                $n = 3;
            } elseif (($c & 0xFC) == 0xF8) {
                $n = 4;
            } elseif (($c & 0xFE) == 0xFC) {
                $n = 5;
            } else {
                return false;
            }
            for ($j = 0; $j < $n; $j++) {
                if ((++$i == $length) || ((ord($str[$i]) & 0xC0) != 0x80)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function mbstring_binary_safe_encoding($reset = false)
    {
        static $encodings = [];
        static $overloaded = null;

        if (is_null($overloaded)) {
            $overloaded = function_exists('mb_internal_encoding');
        }

        if (false === $overloaded) {
            return;
        }

        if (!$reset) {
            $encoding = mb_internal_encoding();
            array_push($encodings, $encoding);
            mb_internal_encoding('ISO-8859-1');
        }

        if ($reset && $encodings) {
            $encoding = array_pop($encodings);
            mb_internal_encoding($encoding);
        }
    }

    private function reset_mbstring_encoding()
    {
        $this->mbstring_binary_safe_encoding(true);
    }

    private function utf8_char_map()
    {
        return ['À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'TH', 'ß' => 's', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y'
        ];
    }

    private function sanitize_title_with_dashes($title, $context = 'display')
    {
        $title = strip_tags($title);

        $title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title);
        $title = str_replace('%', '', $title);
        $title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title);

        if ($this->seems_utf8($title)) {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title, 'UTF-8');
            }
            $title = $this->utf8_uri_encode($title, 200);
        }

        $title = strtolower($title);

        if ('save' === $context) {
            $title = str_replace(['%c2%a0', '%e2%80%93', '%e2%80%94'], '-', $title);
            $title = str_replace(['&nbsp;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;'], '-', $title);
            $title = str_replace('/', '-', $title);

            $title = str_replace(['%c2%ad', '%c2%a1', '%c2%bf', '%c2%ab', '%c2%bb', '%e2%80%b9', '%e2%80%ba', '%e2%80%98', '%e2%80%99', '%e2%80%9c', '%e2%80%9d', '%e2%80%9a', '%e2%80%9b', '%e2%80%9e', '%e2%80%9f', '%e2%80%a2', '%c2%a9', '%c2%ae', '%c2%b0', '%e2%80%a6', '%e2%84%a2', '%c2%b4', '%cb%8a', '%cc%81', '%cd%81', '%cc%80', '%cc%84', '%cc%8c'], '', $title);

            $title = str_replace('%c3%97', 'x', $title);
        }

        $title = preg_replace('/&.+?;/', '', $title);
        $title = str_replace('.', '-', $title);

        $title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);
        $title = preg_replace('|-+|', '-', $title);
        $title = trim($title, '-');

        return $title;
    }

    private function utf8_uri_encode($utf8_string, $length = 0)
    {
        $unicode = '';
        $values = [];
        $num_octets = 1;
        $unicode_length = 0;

        $this->mbstring_binary_safe_encoding();
        $string_length = strlen($utf8_string);
        $this->reset_mbstring_encoding();

        for ($i = 0; $i < $string_length; $i++) {
            $value = ord($utf8_string[$i]);

            if ($value < 128) {
                if ($length && ($unicode_length >= $length)) {
                    break;
                }
                $unicode .= chr($value);
                $unicode_length++;
            } else {
                if (count($values) == 0) {
                    if ($value < 224) {
                        $num_octets = 2;
                    } elseif ($value < 240) {
                        $num_octets = 3;
                    } else {
                        $num_octets = 4;
                    }
                }

                $values[] = $value;

                if ($length && ($unicode_length + ($num_octets * 3)) > $length) {
                    break;
                }
                if (count($values) == $num_octets) {
                    foreach ($values as $val) {
                        $unicode .= '%' . dechex($val);
                    }
                    $unicode_length += $num_octets * 3;
                    $values = [];
                    $num_octets = 1;
                }
            }
        }

        return $unicode;
    }

    public function is_external($url)
    {
        if (empty($url)) {
            return false;
        }

        $site_url = home_url();
        $url = str_replace(['https://', 'http://'], '', $url);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($url, '/') === 0 && strpos($url, '//') === false) {
            
            return false;
        } elseif (strpos($url, $site_url) === false || strpos($url, '//') === 0) {
            return true;
        } else {
            return false;
        }
    }

    public function removeUrl($url)
    {
        $siteUrl = home_url();
        $noUrl = str_replace($siteUrl, '', $url);

        $noUrl = str_replace('&remote_generate_critical=1', '', $noUrl);
        $noUrl = str_replace('&apikey=' . get_option(WPS_IC_OPTIONS)['api_key'], '', $noUrl);

        if ($this->trp_active) {
            global $TRP_LANGUAGE;

            if ($TRP_LANGUAGE == $this->trp_settings['default-language']) {
                if (isset($this->trp_settings['add-subdirectory-to-default-language']) && $this->trp_settings['add-subdirectory-to-default-language'] == 'yes') {
                    $pos = strpos($noUrl, $this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/');
                    if ($pos !== false) {
                        $noUrl = substr_replace($noUrl, '', $pos, strlen($this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/'));
                    }
                }
            } else {
                $pos = strpos($noUrl, $this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/');
                if ($pos !== false) {
                    $noUrl = substr_replace($noUrl, '', $pos, strlen($this->trp_settings['url-slugs'][$TRP_LANGUAGE] . '/'));
                }
            }
        }

        return $noUrl;
    }

    public function get_allowed_params()
    {
        return ['lang', 'wpc_visitor_mode'];
    }

	public static function getUrlFromKey($url_key)
	{
		if (isset(self::$url_mappings[$url_key])) {
			return self::$url_mappings[$url_key];
		}


		if (!empty($url_key) && is_string($url_key) && strpos($url_key, '/') === false && strpos($url_key, '..') === false) {
			$candidates = [];
			if (defined('WPS_IC_CRITICAL')) {
				$candidates[] = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/url.txt';
			}
			if (defined('WPS_IC_CACHE')) {
				$candidates[] = rtrim(WPS_IC_CACHE, '/') . '/' . $url_key . '/url.txt';
			}
			foreach ($candidates as $file) {
				if (@is_readable($file)) {
					$clean = self::sanitizeSameHostUrl((string) @file_get_contents($file));
					if ($clean !== '') {
						self::$url_mappings[$url_key] = $clean;
						return $clean;
					}
				}
			}
		}
		return '';
	}


	







	public static function isPageUrl($url)
	{
		$path = (string) parse_url((string) $url, PHP_URL_PATH);
		if ($path === '') {
			return true;
		}
		return !preg_match(
			'#(?:^|/)(?:wp-admin(?:/|$)|wp-json(?:/|$)|wp-login\.php|wp-cron\.php|xmlrpc\.php'
			. '|admin-ajax\.php|wp-comments-post\.php|wp-signup\.php|wp-activate\.php'
			. '|wp-trackback\.php|wp-mail\.php|wp-links-opml\.php)#i',
			$path
		);
	}

	public static function persistKeyUrl($url_key, $url)
	{
		if (empty($url_key) || !is_string($url_key) || empty($url) || !defined('WPS_IC_CRITICAL')
			|| strpos($url_key, '/') !== false || strpos($url_key, '..') !== false) {
			return false;
		}
		$clean = self::sanitizeSameHostUrl($url);
		if ($clean === '' || !self::isPageUrl($clean)) {
			return false;
		}
		$dir  = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/';
		$file = $dir . 'url.txt';
		if (@is_readable($file) && trim((string) @file_get_contents($file)) === $clean) {
			return true;
		}
		if (!is_dir($dir)) {
			if (!function_exists('wp_mkdir_p') || !wp_mkdir_p($dir)) {
				return false;
			}
		}
		$tmp = $file . '.' . uniqid('tmp', true);
		if (@file_put_contents($tmp, $clean) === false) {
			return false;
		}
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	





	public static function sanitizeSameHostUrl($url)
	{
		$url = trim((string) $url);
		if ($url === '' || !function_exists('home_url')) {
			return '';
		}
		if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
			$url = 'https://' . ltrim($url, '/');
		}
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return '';
		}
		$parts = parse_url($url);
		if (empty($parts['host'])) {
			return '';
		}
		$homeHost = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
		$urlHost  = strtolower($parts['host']);
		$stripWww = function ($h) {
			return (strpos($h, 'www.') === 0) ? substr($h, 4) : $h;
		};
		if ($homeHost === '' || $stripWww($homeHost) !== $stripWww($urlHost)) {
			return '';
		}
		$homeScheme = (string) parse_url(home_url(), PHP_URL_SCHEME);
		$scheme     = ($homeScheme === 'http') ? 'http' : 'https';
		$path       = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
		return $scheme . '://' . $urlHost . $path;
	}
}