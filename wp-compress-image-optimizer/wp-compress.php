<?php
/*
 * Plugin name: WP Compress – Instant Performance & Speed Optimization
 * Plugin URI: https://www.wpcompress.com
 * Author: WP Compress
 * Author URI: https://www.wpcompress.com
 * Version: 6.21.05
 * Description: Automatically compress and optimize images to shrink image file size, improve  times and boost SEO ranks - all without lifting a finger after setup.
 * Text Domain: wp-compress-image-optimizer
 * Domain Path: /langs
 */

if (empty($_GET['disableWPC']) && !(defined('DOING_CRON') && DOING_CRON)) {
  // CRON fix for WPvivid scheduled backups
  if (get_option('pause_wpcompress_plugin')){
    add_action('admin_init', 'pause_wpcompress_plugin_deactivate_delete');
    delete_option('pause_wpcompress_plugin');
    delete_option('wps_ic');
    delete_option('wps_ic_mu_settings');
    require_once(ABSPATH . 'wp-includes/pluggable.php');
    wp_redirect(admin_url('plugins.php'));
  } else {
    define('WPC_PLUGIN_FILE', __FILE__);
    include_once 'wp-compress-core.php';
  }

  function pause_wpcompress_plugin_deactivate_delete(){
    deactivate_plugins('wp-compress-image-optimizer/wp-compress.php');

    delete_plugins(array('wp-compress-image-optimizer/wp-compress.php'));

    $active_plugins = get_option('active_plugins');
    $plugin_slug = 'wp-compress-image-optimizer/wp-compress.php';
    $key = array_search($plugin_slug, $active_plugins);
    if ($key !== false) {
      unset($active_plugins[$key]);
      update_option('active_plugins', $active_plugins);
    }
  }
}
