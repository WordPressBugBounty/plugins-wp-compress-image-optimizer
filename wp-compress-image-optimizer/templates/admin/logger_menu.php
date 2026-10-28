<?php
if (!current_user_can('manage_options')) {
    return;
}
global $wps_ic, $wpdb;
wpc_log_combine_request($_GET);
$wpc_logger_nonce = wp_create_nonce('wps_ic_nonce_action');
?>
<table id="information-table" class="wp-list-table widefat fixed striped posts">
    <thead>
    </thead>
    <tbody>
    <tr>
        <td>Log criticalCombine progress</td>
        <td colspan="3">
            <p>
              <?php
              $log_critCombine = get_option('wps_log_critCombine');

              if (empty($log_critCombine) || $log_critCombine == 'false') {
                  echo '<a href="' . esc_url(wpc_debug_tool_base_url($wps_ic::$slug) . '&view=debug_tool&log_critCombine=true&wps_ic_nonce=' . $wpc_logger_nonce) . '" class="button-primary" style="margin-right:20px;">Enable</a>';
              } else {
                  echo '<a href="' . esc_url(wpc_debug_tool_base_url($wps_ic::$slug) . '&view=debug_tool&log_critCombine=false&wps_ic_nonce=' . $wpc_logger_nonce) . '" class="button-primary" style="margin-right:20px;">Disable</a>';
              }
              ?>
                This will log combine progress to a file.
            </p>
        </td>
    </tr>
    <tr>
        <td>Log Files</td>
        <td colspan="3">
            <div class="log-browser">
                <h3>Log Directory</h3>
              <?php
              $logs_dir = WPS_IC_LOG;

              // Create logs directory if it doesn't exist
              if (!file_exists($logs_dir)) {
                  mkdir($logs_dir, 0755, true);
              }

              if (!function_exists('list_directory_contents')) {
              function list_directory_contents($dir, $base_url) {
                  $output = '<ul class="ul-disc">';
                  $items = scandir($dir);

                  foreach ($items as $item) {
                      if ($item == '.' || $item == '..') continue;

                      $path = $dir . '/' . $item;
                      $relative_path = str_replace(ABSPATH, '', $path);
                      $file_url = site_url($relative_path);

                      if (is_dir($path)) {
                          // It's a directory
                        $output .= '<li>';
                        $output .= '<strong>' . esc_html($item) . '</strong>';
                        $output .= list_directory_contents($path, $base_url);
                        $output .= '</li>';
                      } else {
                          // It's a file
                        $file_size = size_format(filesize($path));

                        $output .= '<li>';
                        $output .= '<a href="' . esc_url($file_url) . '" target="_blank">' . esc_html($item) . '</a>';
                        $output .= ' <span class="description">(' . esc_html($file_size) . ')</span>';
                        $output .= '</li>';
                      }
                  }

                  $output .= '</ul>';
                  return $output;
              }
              }

              // Get the site URL for direct file access
              $site_url = site_url();

              // Display the directory contents
              echo list_directory_contents($logs_dir, $site_url);
              ?>
            </div>
        </td>
    </tr>
    </tbody>
</table>