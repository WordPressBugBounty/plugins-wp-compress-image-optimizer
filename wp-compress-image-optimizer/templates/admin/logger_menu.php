<?php
global $wps_ic, $wpdb;
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
              if (!empty($_GET['log_critCombine'])) {
                  if ($_GET['log_critCombine'] === 'true') {
                      update_option('wps_log_critCombine', sanitize_text_field($_GET['log_critCombine']));
                  } else {
                      delete_option('wps_log_critCombine');
                  }
              }

              $log_critCombine = get_option('wps_log_critCombine');

              if (empty($log_critCombine) || $log_critCombine == 'false') {
                  echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&log_critCombine=true') . '" class="button-primary" style="margin-right:20px;">Enable</a>';
              } else {
                  echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&log_critCombine=false') . '" class="button-primary" style="margin-right:20px;">Disable</a>';
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
              $logs_dir = WPS_IC_DIR . '/logs';

              // Create logs directory if it doesn't exist
              if (!file_exists($logs_dir)) {
                  mkdir($logs_dir, 0755, true);
              }

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