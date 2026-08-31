<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/perfmatters.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_perfmatters extends wps_ic_integrations
{

  public function is_active()
  {
    return function_exists('perfmatters_version_check');
  }

  public function getConflictList()
  {
    $perfmatters_options = get_option('perfmatters_options');
    $conflict = [];

    if ($this->wps_settings['delay-js'] && !empty($perfmatters_options['assets']['delay_js']) && $perfmatters_options['assets']['delay_js']) {
      $conflict[] = 'delay-js';
    }

    if ($this->wps_settings['lazy'] && !empty($perfmatters_options['lazyload']['lazy_loading']) &&
      $perfmatters_options['lazyload']['lazy_loading']) {
      $conflict[] = 'lazy';
    }

    return $conflict;
  }

  public function do_checks()
  {
    
    $perfmatters_options = get_option('perfmatters_options');
    $updated = false;

    if (!empty($this->wps_settings['delay-js']) && $this->wps_settings['delay-js'] == 1 && !empty($perfmatters_options['assets']['delay_js']) && $perfmatters_options['assets']['delay_js']) {
      $perfmatters_options['assets']['delay_js'] = 0;
      $updated = true;


    }

    if (!empty($this->wps_settings['lazy']) && $this->wps_settings['lazy'] == 1 && !empty($perfmatters_options['lazyload']['lazy_loading']) && $perfmatters_options['lazyload']['lazy_loading']) {
      $perfmatters_options['lazyload']['lazy_loading'] = 0;
      $updated = true;


    }

    if ($updated) {
      $cache = new wps_ic_cache_integrations();
      $cache->purgeAll(false, false, false, false);
      update_option('perfmatters_options', $perfmatters_options);
    }

  }

  public function fix_setting($setting)
  {
    $perfmatters_options = get_option('perfmatters_options');

    if ($setting == 'delay_js') {
      $perfmatters_options['assets']['delay_js'] = 0;
    } else if ($setting == 'lazyload') {
      $perfmatters_options['lazyload']['lazy_loading'] = 0;
    }

    return update_option('perfmatters_options', $perfmatters_options);
  }

}