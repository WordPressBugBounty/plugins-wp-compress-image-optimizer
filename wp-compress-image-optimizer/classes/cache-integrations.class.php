<?php


class wps_ic_cache_integrations
{


  public function __construct()
  {
  }


  public static function purgeAll($url_key = false, $varnish = false)
  {
    self::purgeBreeze();
    self::purgeCacheFiles($url_key);

    // Clear cache.
    if (function_exists('rocket_clean_domain')) {
      rocket_clean_domain();
    }

    // WP Optimize
    if (class_exists('WP_Optimize')) {
      WP_Optimize()->get_page_cache()->purge();
    }

    // Purge Combined
    // When plugins have a simple method, add them to the array ('Plugin Name' => 'method_name')
    $others = array(
      'WP Super Cache' => 'wp_cache_clear_cache',
      'W3 Total Cache' => 'w3tc_pgcache_flush',
      'WP Fastest Cache' => 'wpfc_clear_all_cache',
      'WP Rocket' => 'rocket_clean_domain',
      'Cachify' => 'cachify_flush_cache',
      'Comet Cache' => array('comet_cache', 'clear'),
      'SG Optimizer' => 'sg_cachepress_purge_cache',
      'Pantheon' => 'pantheon_wp_clear_edge_all',
      'Zen Cache' => array('zencache', 'clear'),
      'Breeze' => array('Breeze_PurgeCache', 'breeze_cache_flush'),
      'Swift Performance' => array('Swift_Performance_Cache', 'clear_all_cache'),
    );

    foreach ($others as $plugin => $method) {
      if (is_callable($method)) {
        call_user_func($method);
      }
    }

    // Lite Speed
    if (defined('LSCWP_V')) {
      do_action('litespeed_purge_all');
      if (is_callable(array('LiteSpeed_Cache_Tags', 'add_purge_tag'))) {
        LiteSpeed_Cache_Tags::add_purge_tag('*');
      }
    }

    // HummingBird
    if (defined('WPHB_VERSION')) {
      do_action('wphb_clear_page_cache');
    }

    // SG Cache
    if (function_exists('sg_cachepress_purge_cache')) {
      // Purge Everything
      sg_cachepress_purge_cache();
    }

    // Breeze Purge
    if (function_exists('breeze_cache_flush')) {
      Breeze_PurgeCache::breeze_cache_flush();
    }

    // Purge Autoptimize
    if (class_exists('autoptimizeCache')) {
      autoptimizeCache::clearall();
    }

	  // Lite Speed
	  if (defined('LSCWP_V')) {
		  do_action('litespeed_purge_all');
	  }

	  // HummingBird
	  if (defined('WPHB_VERSION')) {
		  do_action('wphb_clear_page_cache');
	  }

    // Purge W3 Cache
    if( class_exists('W3_Plugin_TotalCacheAdmin') ) {
      $plugin_totalcacheadmin = & w3_instance('W3_Plugin_TotalCacheAdmin');
      $plugin_totalcacheadmin->flush_all();
    }

    // Varnish
    if ($varnish) {
      self::purgeVarnish();
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

  public static function purgeCacheFiles($url_key = false)
  {
    $cache_dir = WPS_IC_CACHE;

		//whenever we delete cache we want to delete tests results
		$test_results = get_option('wpc-tests', []);

    if (!$url_key) {
      self::removeDirectory($cache_dir);
			$test_results = [];
    } else {
	    self::removeFiles($cache_dir.$url_key);
			unset($test_results[$url_key]);
    }

		update_option('wpc-tests', $test_results);
    return true;
  }

	public static function purgeCombinedFiles($url_key = false)
	{
		$cache_dir = WPS_IC_COMBINE;

		if (!$url_key) {
			self::removeDirectory($cache_dir);
		} else {
			self::removeDirectory($cache_dir.$url_key);
		}

		$options = get_option(WPS_IC_OPTIONS);
		$options['css_hash'] = substr(md5(microtime(true)), 0, 6);
		$options['js_hash'] = substr(md5(microtime(true)), 0, 6);
		update_option(WPS_IC_OPTIONS, $options);
		return true;
	}

	public static function purgeCriticalFiles($url_key = false)
	{
		$cache_dir = WPS_IC_CRITICAL;

		if (!$url_key) {
			self::removeDirectory($cache_dir);
		} else {
			self::removeFiles($cache_dir.$url_key);
		}
		return true;
	}

	public static function removeFiles($path)
	{
		$path = rtrim($path, '/');
		$files = glob($path . '/*');
		if (!empty($files)) {
			foreach ($files as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}
		}
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

    if (is_dir($path)) {
      rmdir($path);
    }
  }

  // TODO: Maybe it will cause errors with non SSL sites?
  public static function purgeVarnish($post_id=0)
  {
    global $wpdb, $current_blog;
		if ($post_id!=0){
			$parseUrl = parse_url(get_permalink($post_id));
		} else {
			$parseUrl = parse_url(site_url());
		}

    if (empty($parseUrl['path'])) {
      $parseUrl['path'] = '';
    }

    if (empty($parseUrl['host'])) {
      return;
    }


    // Determine the schema
    $schema = 'http://';
    if ( isset( $parseUrl['scheme'] ) ) {
      $schema = $parseUrl['scheme'] . '://';
    }

    // Flush original WP domain
    $call = wp_remote_request($schema . $parseUrl['host'] . $parseUrl['path'] . '/', array('method' => 'PURGE', 'headers' => array('host' => $parseUrl['host'], 'X-Purge-Method' => 'default')));
    /*
    // Get table name for mapping
    $wpdb->dmtable = (isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix) . 'domain_mapping';

    // Flush all mapped domains
    $blog_domains = $wpdb->get_col($wpdb->prepare("
			SELECT dm.domain
			FROM $wpdb->blogs AS b
			INNER JOIN $wpdb->dmtable AS dm ON b.blog_id = dm.blog_id
			WHERE b.blog_id = %d
			", $current_blog->blog_id));

    if (empty($blog_domains)) {
      return false;
    }

    foreach ($blog_domains as $blog_domain) {
      wp_remote_request($schema . $blog_domain . '/', array('method' => 'PURGE', 'headers' => array('host' => $blog_domain, 'X-Purge-Method' => 'default')));
    }*/

    return true;
  }


}