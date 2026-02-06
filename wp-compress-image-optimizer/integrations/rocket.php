<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_rocket extends wps_ic_integrations
{

  public function is_active()
  {
    return function_exists('get_rocket_option');
  }

  public function getConflictsList()
  {
    $rocket_settings = get_option('wp_rocket_settings');
    $conflict = [];
    if ($this->wps_settings['delay-js-v2'] && !empty($rocket_settings['delay_js']) && $rocket_settings['delay_js']) {
      $conflict[] = 'delay-js-v2';
    }

    if ($this->wps_settings['lazy'] && !empty($rocket_settings['lazyload']) && $rocket_settings['lazyload']) {
      $conflict[] = 'lazy';
    }

    return $conflict;
  }

  public function do_checks()
  {
    // Logic to check for conflicts
    $rocket_settings = get_option('wp_rocket_settings');
    $updated = false;

    if (!empty($this->wps_settings['delay-js-v2']) && $this->wps_settings['delay-js-v2'] == 1 && !empty($rocket_settings['delay_js']) && $rocket_settings['delay_js']) {
      $rocket_settings = get_option('wp_rocket_settings');
      $rocket_settings['delay_js'] = 0;
      $updated = true;

      /*
			$this->notices_class->show_notice( 'WPCompress - Delay JS conflict detected',
				'Click "Fix" to use WPCompress and disable WP Rocket\'s setting, or "Dismiss" to continue.',
				'warning', true, 'wpc_rocket_delay_js_dismiss_tag', [ 'plugin' => 'rocket', 'setting' => 'delay_js' ] );
      */
    }

    if (!empty($this->wps_settings['lazy']) && $this->wps_settings['lazy'] == 1 && !empty($rocket_settings['lazyload']) && $rocket_settings['lazyload']) {
      $rocket_settings['lazyload'] = 0;
      $updated = true;

      /*
			$this->notices_class->show_notice( 'WPCompress - Lazy Load conflict detected',
				'Click "Fix" to use WPCompress and disable WP Rocket\'s setting, or "Dismiss" to continue.',
				'warning', true, 'wpc_rocket_lazyload_dismiss_tag',[ 'plugin' => 'rocket', 'setting' => 'lazyload' ] );

      */

    }

    if (!empty($this->wps_settings['iframe-lazy']) && $this->wps_settings['iframe-lazy'] == 1 && !empty($rocket_settings['lazyload_iframes']) && $rocket_settings['lazyload_iframes']) {
      $rocket_settings['lazyload_iframes'] = 0;
      $updated = true;
    }

    if ($updated) {
      update_option('wp_rocket_settings', $rocket_settings);
      $cache = new wps_ic_cache_integrations();
      $cache->purgeAll(false, false, false, false);
    }
  }

  public function fix_setting($setting)
  {
    $rocket_settings = get_option('wp_rocket_settings');

    if ($setting == 'delay_js') {
      $rocket_settings['delay_js'] = 0;
    } else if ($setting == 'lazyload') {
      $rocket_settings['lazyload'] = 0;
    }

    return update_option('wp_rocket_settings', $rocket_settings);
  }

  public function add_admin_hooks()
  {
    return [
      'wps_ic_purge_all_cache' => [
        'callback' => 'purge_cache',
        'priority' => 10,
        'args' => 1
      ]
    ];
  }

  public function purge_cache($url_key = false)
  {
    if (function_exists('rocket_clean_domain')) {
      rocket_clean_domain();
    }
  }


}