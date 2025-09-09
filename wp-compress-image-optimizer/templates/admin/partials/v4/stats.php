<?php

global $wps_ic;

$user_credits = self::$user_credits;
$stats_live = self::$stats_live;
$stats_local = self::$stats_local;
$stats_local_sum = self::$stats_local_sum;

/**
 * Quick fix for PHP undefined notices
 */
$wps_ic_active_settings['optimization']['lossless'] = '';
$wps_ic_active_settings['optimization']['intelligent'] = '';
$wps_ic_active_settings['optimization']['ultra'] = '';

/**
 * Decides which setting is active
 */
if (!empty($wps_ic::$settings['optimization'])) {
  if ($wps_ic::$settings['optimization'] == 'lossless') {
    $wps_ic_active_settings['optimization']['lossless'] = 'class="current"';
  } elseif ($wps_ic::$settings['optimization'] == 'intelligent') {
    $wps_ic_active_settings['optimization']['intelligent'] = 'class="current"';
  } else {
    $wps_ic_active_settings['optimization']['ultra'] = 'class="current"';
  }
} else {
  $wps_ic_active_settings['optimization']['intelligent'] = 'class="current"';
}


?>
<div class="wp-compress-settings">

    <div class="wp-compress-settings-row-blank">

        <div class="col-9 text-left white-box margin-right">
            <div class="pre-inner">
                <div class="inner inner-flex">
                  <?php

                        if (!empty($stats_live)) {
                          if ($user_credits->bytes->bandwidth_savings > 0) {
                            $savings = true;
                            $donut_size = $user_credits->bytes->bandwidth_savings / 100;
                            $donut_size = number_format($donut_size, 1);
                            $donut_text = $user_savings = $user_credits->formatted->bandwidth_savings;
                          } else {
                            $savings = true;
                            $user_savings = 0;
                            $donut_text = '0';
                          }
                        } else {
                          $donut_size = 1;
                          $donut_text = 0;
                        }

                        $donut_text = number_format($donut_text, 1);
                        ?>

                          <div class="left-side-box">
                              <div class="user-account-circle">
                                  <div id="circle-big" data-value="<?php
                                  echo $donut_size; ?>"></div>
                                  <div class="dashboard-account-circle-text">
                                      <h5><?php
                                        echo $donut_text; ?>%</h5>
                                      <h4>Savings</h4>
                                  </div>
                                  <!-- -35s == 35% -->
                              </div>
                              <div class="youve-saved">
                                <?php
                                if (!empty($stats_live)) { ?>
                                    <h3>You've Saved</h3>
                                    <h4><?php echo $user_credits->formatted->bandwidth_savings_bytes; ?></h4>
                                  <?php
                                } else { ?>
                                    <h3>You've Saved</h3>
                                    <h4><?php echo 0 . ' MB'; ?></h4>
                                  <?php
                                } ?>
                                  <div class="image-credits-remaining">
                                        <a href="https://wpcompress.com/pricing" target="_blank"
                                           class="button button-primary requests-left">
                                            <h5><?php
                                              echo self::$accountQuota['live']; ?></h5>
                                        </a>
                                  </div>
                              </div>
                          </div>

                          <div class="right-side-box">


                              <div class="stats-boxes">

                                  <div class="stats-box-single">
                                      <div class="stats-box-icon-holder">
                                          <img src="<?php echo WPS_IC_URI; ?>/assets/images/icon-original-size.svg"/>
                                      </div>
                                      <div class="stats-box-text-holder">
                                          <h5>Original</h5>
                                          <h3><?php echo $user_credits->formatted->original_bandwidth; ?></h3>
                                      </div>
                                  </div>

                                  <div class="stats-box-single">
                                      <div class="stats-box-icon-holder">
                                          <img src="<?php
                                          echo WPS_IC_URI; ?>/assets/images/icon-total-images.svg"/>
                                      </div>
                                      <div class="stats-box-text-holder">
                                          <h5>Optimized</h5>
                                          <h3><?php echo $user_credits->formatted->cdn_bandwidth; ?></h3>
                                      </div>
                                  </div>

                                  <div class="stats-box-single">
                                      <div class="stats-box-icon-holder">
                                          <img src="<?php echo WPS_IC_URI; ?>/assets/images/icon-after-optimization.svg"/>
                                      </div>
                                      <div class="stats-box-text-holder">
                                          <h5>Assets Served</h5>
                                          <h3><?php echo $user_credits->formatted->cdn_requests; ?></h3>
                                      </div>
                                  </div>


                              </div>
                          </div>
                    </div>
                </div>
            </div>
        </div>


    </div>