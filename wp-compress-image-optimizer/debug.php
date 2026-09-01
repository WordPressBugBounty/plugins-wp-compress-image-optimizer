<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: debug.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

if (get_option('wps_ic_debug') == 'true') {
	ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

if (get_option('wps_ic_debug') == 'log') {
    define('WPS_IC_DEBUG', 'true');
}

if (get_option('wps_ic_debug_log') == 'true') {
    define('WPS_IC_DEBUG_LOG', true);
} else {
    define('WPS_IC_DEBUG_LOG', false);
}