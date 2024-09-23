<?php

global $wps_ic;

if (!empty($_GET['reset'])) {
  delete_option('wps_ic_bulk_process');
}

$live_cdn = false;
if (!empty($wps_ic::$settings['live-cdn']) && $wps_ic::$settings['live-cdn'] == '1') {
  $live_cdn = true;
}

?>
<div class="wrap">
    <div class="wps_ic_wrap wps_ic_settings_page wps_ic_live">

        <div class="wp-compress-header">
            <div class="wp-ic-logo-container">
                <div class="wp-compress-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/main-logo.svg"/>
                </div>
            </div>
            <div class="wp-ic-header-buttons-container">
                <ul>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>" class="wps-ic-stop-bulk-compress" style="display:none;"><i class="icon-pause"></i> Pause Optimization</a>
                    </li>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>" class="wps-ic-stop-bulk-restore" style="display:none;"><i class="icon-pause"></i> Pause Restore</a>
                    </li>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . ''); ?>" class="wpc-btn-return">Return to Dashboard</a>
                    </li>
                </ul>
            </div>
            <div class="clearfix"></div>
        </div>


        <div class="wp-compress-pre-wrapper-no-shadow">

            <div class="wp-compress-bulk-area">
              <?php
              /**
               * Find uncompressed images
               */
              $local = new wps_ic_local();

              $libraryStatus = $local->prepareImages('', 'count');
              $uncompressedImages = count($libraryStatus['uncompressed']);
              $compressedImages = count($libraryStatus['compressed']);


              $bulkProcess = get_option('wps_ic_bulk_process');

              $prepare_compress = 'display:none;';
              $prepare_restore = 'display:none;';
              $bulk = '';
              $show_bulk = 'display:none;';
              $compress_bulk_4boxes = 'display: flex;';
              $prepare_restore = 'display:none;';

              if (!empty($bulkProcess['status'])) {
                  if ($bulkProcess['status'] == 'compressing') {
                    $prepare_compress = 'display:block;';
                    $prepare_restore = 'display:none;';
                    $bulk = 'display:none;';
                    $show_bulk = 'display:block;';
                    $compress_bulk_4boxes = 'display: none;';
                    $prepare_restore = 'display:none;';
                  } else {
                    $prepare_compress = 'display:none;';
                    $prepare_restore = 'display:block;';
                    $bulk = 'display:none;';
                    $show_bulk = 'display:block;';
                    $compress_bulk_4boxes = 'display: none;';
                    $prepare_restore = 'display:none;';
                  }
              }

              ?>

                <!-- Initial Bulk Screen IF Nothing is running ! -->
                <div class="wp-compress-bulk-split" id="bulk-start-container" style="<?php echo $bulk; ?>">
                    <div class="bulk-split-side" style="margin-right: 20px;">
                        <div class="compress-bulk-start" style="padding:25px;">

                            <div class="wps-ic-bulk-html-wrapper">
                                <div class="wps-ic-bulk-header">
                                    <div class="wps-ic-bulk-logo">
                                        <div class="logo-holder" style="padding-top: 0px">
                                            <img src="<?php echo WPS_IC_URI; ?>assets/images/bulk-compress-icon.svg">
                                        </div>
                                    </div>
                                </div>
                            </div>
                          <?php
                          if ($uncompressedImages > 0) {
                            echo '<h3>You have ' . $uncompressedImages . ' images ready to be optimized.</h3>';
                          } else {
                            echo '<h3>You have 0 images to optimize.</h3>';
                          }
                          ?>
                          <?php if ($uncompressedImages > 0) { ?>
                              <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&action=compress'); ?>" class="button button-primary button-start-bulk-compress">Compress Images</a>
                          <?php } else { ?>
                              <a href="#" class="button button-primary button-disabled ic-tooltip" title="You have no more images to compress!">Compress Images</a>
                          <?php } ?>
                        </div>
                    </div>
                    <div class="bulk-split-side">
                        <div class="restore-bulk-start" style="padding:25px;">

                            <div class="wps-ic-bulk-html-wrapper">
                                <div class="wps-ic-bulk-header">
                                    <div class="wps-ic-bulk-logo">

                                        <div class="logo-holder" style="padding-top: 0px">
                                            <img src="<?php echo WPS_IC_URI; ?>assets/images/bulk-restore-icon.svg">
                                        </div>

                                    </div>
                                </div>
                            </div>
                          <?php
                          if ($compressedImages > 0) {
                            echo '<h3>You have ' . $compressedImages . ' images that can be restored.</h3>';
                          } else {
                            echo '<h3>You have 0 images that can be restored.</h3>';
                          }
                          ?>

                          <?php if ($compressedImages > 0) { ?>
                              <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&action=restore'); ?>" class="button button-primary button-start-bulk-restore">Restore Images</a>
                          <?php } else { ?>
                              <a href="#" class="button button-primary button-disabled ic-tooltip" title="You have no more images to restore!">Restore Images</a>
                          <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- Bulk Area When something is running -->
                <div class="bulk-area-inner" style="<?php echo $show_bulk; ?>">
                    <div>
                        <div class="bulk-finished" style="display:none;text-align: center;"></div>
                        <div class="bulk-preparing-optimize" style="<?php echo $prepare_compress; ?>text-align: center;margin-bottom:30px;">
                            <div class="wps-ic-bulk-preparing-logo-container">
                                <div class="wps-ic-bulk-preparing-logo">
                                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
                                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
                                </div>
                            </div>
                            <h3>Preparing to Optimize</h3>
                            <div class="wpc-ic-thin-placeholder" style="width:300px;"></div>
                            <div class="bulk-preparing-placholders" style="margin-top:60px;">
                                <div class="left-side">
                                    <div class="wpc-ic-thick-placeholder" style="width:200px;"></div>
                                </div>
                                <div class="right-side">
                                    <div class="wpc-ic-thick-placeholder" style="width:200px;"></div>
                                </div>
                            </div>
                            <div class="bulk-preparing-placholders">
                                <div class="full-width">
                                    <div class="wpc-ic-thick-placeholder" style="width:100%;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="bulk-preparing-restore" style="<?php echo $prepare_restore; ?>text-align: center;">
                            <div class="wps-ic-bulk-preparing-logo-container">
                                <div class="wps-ic-bulk-preparing-logo">
                                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
                                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
                                </div>
                            </div>
                            <h3>Preparing to Restore</h3>
                            <div class="wpc-ic-thin-placeholder" style="width:300px;"></div>
                        </div>
                        <div class="bulk-status" style="display: none;"></div>
                        <div class="bulk-status-settings" style="display: none;"></div>
                        <div class="bulk-status-progress-bar" style="display: none;">
                            <div class="bulk-process-file-name"></div>
                            <div class="bulk-process-status"></div>
                            <div class="progress-bar-outer">
                                <div class="progress-bar-inner" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="bulk-restore-status-progress" style="display: none;">
                            <div class="bulk-images-restored">
                                <h3>0/0</h3>
                                <h5>Images Restored</h5>
                            </div>
                        </div>
                        <div class="bulk-restore-status-container" style="display: none;">
                            <h4>Image Restore Complete!</h4>
                            <span>We have successfully restored all of your images.</span>
                            <div class="bulk-status-progress-bar">
                                <div class="progress-bar-outer">
                                    <div class="progress-bar-inner" style="width: 100%;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="bulk-compress-status-progress" style="display: none;">
                            <div class="bulk-images-compressed">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0/0</h3>
                                    <h5>Original Images</h5>
                                </div>
                            </div>
                            <div class="bulk-thumbs-compressed">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0/0</h3>
                                    <h5>Total Images</h5>
                                </div>
                            </div>
                            <div class="bulk-total-savings">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0.0MB</h3>
                                    <h5>Total Savings</h5>
                                </div>
                            </div>
                            <div class="bulk-avg-reduction">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0%</h3>
                                    <h5>Average Savings</h5>
                                </div>
                            </div>
                        </div>
                        <div class="bulk-compress-status-progress-prepare" style="<?php echo $compress_bulk_4boxes; ?>">
                            <div class="bulk-images-compressed">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                            <div class="bulk-thumbs-compressed">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                            <div class="bulk-total-savings">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                            <div class="bulk-avg-reduction">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


            </div>

        </div>

      <?php
      // TODO: Bottom bar with hidden message about bulk optimization
      ?>

      <?php include 'partials/popups/bulk/popups.php'; ?>
    </div>
</div>