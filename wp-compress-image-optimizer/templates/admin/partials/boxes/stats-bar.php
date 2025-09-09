<?php
/**
 * Is Live OFF?
 */
if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {
$donut_size = 1;
$savings    = false;



if (isset ($user_credits->bytes->local_compressed) && $user_credits->bytes->local_compressed > 0 &&
    isset ($user_credits->bytes->local_original) && $user_credits->bytes->local_original > 0) {
    $savings      = true;
    $donut_size   = 1 - ($user_credits->bytes->local_compressed / $user_credits->bytes->local_original);
    $donut_size   = number_format($donut_size, 2);
    $user_savings = $donut_size * 100;
} else {
    $savings      = true;
    $user_savings = 0;
}

if (empty($user_savings) || $user_savings == '') {
    $user_savings = '0';
}

?>

<div class="left-side-box">
    <div class="user-account-circle">
        <div id="circle-big" data-value="<?php
        echo $donut_size; ?>"></div>
        <div class="dashboard-account-circle-text">
            <?php
            if ($savings) { ?>
                <h5><?php
                    echo $user_savings.'%'; ?></h5>
                <h4>Savings</h4>
                <?php
            } ?>
        </div>
        <!-- -35s == 35% -->
    </div>
</div>

<div class="right-side-box">
    <div class="youve-saved">
        <?php
        /**
         * Live Stats Exist OR  Stats Local Exists
         */
        if ( ! empty($stats_live) || ! empty($stats_local)) { ?>
            <?php
            if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {

                if (isset ($user_credits->bytes->local_compressed) && $user_credits->bytes->local_compressed > 0 && isset ($user_credits->bytes->local_original) && $user_credits->bytes->local_original > 0) {
                    echo '<h3>You\'ve Saved</h3>';

                    $savings = $user_credits->formatted->local_savings;
                    if ($savings<=0) {
                      echo '<h3 style="padding-right:20px;">No Savings Yet!</h3>';
                    } else {
                      echo '<h4>'.$savings.'</h4>';
                    }
                } else {
                    echo '<h3 style="padding-right:20px;">No Savings Yet!</h3>';
                }

            } else { ?>
                <h3>You've Saved</h3>
                <h4><?php
                    echo $user_credits->formatted->bandwidth_savings_bytes; ?></h4>
                <?php
            } ?>
            <div class="image-credits-remaining">
                <?php
                $local_requests_left = '';
                $requests_left       = '';
                if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {
                    $requests_left = 'display:none;';
                } else {
                    $local_requests_left = 'display:none;';
                }
                ?>
                <a href="https://wpcompress.com/pricing" target="_blank" class="button button-primary local-requests-left" style="<?php
                echo $local_requests_left; ?>">
                    <h5><?php echo $accountQuota['local']; ?></h5>
                </a>
                <a href="https://wpcompress.com/pricing" target="_blank" class="button button-primary requests-left" style="<?php
                echo $requests_left; ?>">
                    <h5><?php echo $accountQuota['live']; ?></h5>
                </a>

            </div>
            <?php
        } else {
            ?>
            <h3>You have not yet optimized any images!</h3>
            <a href="<?php
            echo admin_url('options-general.php?page='.$wps_ic::$slug.'&view=bulk'); ?>" class="button-primary button btn-start-optimization" style="margin-left: 10px;margin-right:10px;"><?php echo $accountQuota['live']; ?></a>
            <?php
        } ?>
    </div>

    <?php
    if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {
    ?>
    <div class="stats-boxes smaller">
        <?php
        } else { ?>
        <div class="stats-boxes">
            <?php
            } ?>
            <div class="stats-box-single">
                <div class="stats-box-icon-holder">
                    <img src="<?php
                    echo WPS_IC_URI; ?>/assets/images/icon-total-images.svg"/>
                </div>
                <div class="stats-box-text-holder">
                    <?php
                    if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {
                        $thumbs = get_intermediate_image_sizes();
                        ?>
                        <h3><?php
                            if (isset ($user_credits->bytes->local_requests)) {
                                echo $user_credits->bytes->local_requests;
                            } ?></h3>
                        <h5>Images</h5>
                        <?php
                    } else { ?>
                        <h3><?php
                            if (isset ($user_credits->formatted->cdn_requests)) {
                                echo $user_credits->formatted->cdn_requests;
                            } ?></h3>
                        <h5>Total Images</h5>
                        <?php
                    } ?>
                </div>
            </div>

            <div class="stats-box-single">
                <div class="stats-box-icon-holder">
                    <img src="<?php
                    echo WPS_IC_URI; ?>/assets/images/icon-original-size.svg"/>
                </div>
                <div class="stats-box-text-holder">
                    <?php
                    if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {
                        ?>
                        <h3>
                            <?php
                            if (empty($user_credits->formatted->local_original) || $user_credits->formatted->local_original == '') {
                                echo '0';
                            } else {
                                echo $user_credits->formatted->local_original;
                            }
                            ?>
                        </h3>
                        <h5>Original Size</h5>
                        <?php
                    } else { ?>
                        <h3>
                            <?php
                            if (empty($user_credits->formatted->original_bandwidth) || $user_credits->formatted->original_bandwidth == '') {
                                echo '0';
                            } else {
                                echo $user_credits->formatted->original_bandwidth;
                            }
                            ?></h3>
                        <h5>Original</h5>
                        <?php
                    } ?>
                </div>
            </div>

            <div class="stats-box-single">
                <div class="stats-box-icon-holder">
                    <img src="<?php
                    echo WPS_IC_URI; ?>/assets/images/icon-after-optimization.svg"/>
                </div>
                <div class="stats-box-text-holder">
                    <?php
                    if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') {
                        ?>
                        <h3>
                            <?php
                            if (empty($user_credits->formatted->local_compressed) || $user_credits->formatted->local_compressed == '') {
                                echo '0';
                            } else {
                                echo $user_credits->formatted->local_compressed;
                            }
                            ?>
                        </h3>
                        <h5>Optimized</h5>
                        <?php
                    } else { ?>
                        <h3>
                            <?php
                            if (empty($user_credits->formatted->cdn_bandwidth) || $user_credits->formatted->cdn_bandwidth == '') {
                                echo '0';
                            } else {
                                echo $user_credits->formatted->cdn_bandwidth;
                            }
                            ?></h3>
                        <h5>Optimized</h5>
                        <?php
                    } ?>
                </div>
            </div>

        </div>
    </div>
    <?php
    }
else {
        // Live is ON
        if ( ! empty($stats_live)) {
            if ($user_credits->bytes->bandwidth_savings > 0) {
                $savings    = true;
                $donut_size = $user_credits->bytes->bandwidth_savings / 100;
                $donut_size = number_format($donut_size, 2);
                $donut_text = $user_savings = $user_credits->formatted->bandwidth_savings;
            } else {
                $savings      = true;
                $user_savings = 0;
                $donut_text = '0';
            }
        } else {
            $donut_size = 1;
            $donut_text = 0;
        }
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
        </div>

        <div class="right-side-box">
            <div class="youve-saved">
                <?php
                if ( ! empty($stats_live)) { ?>
                    <h3>You've Saved</h3>
                    <h4><?php
                        echo $user_credits->formatted->bandwidth_savings_bytes; ?></h4>
                    <?php
                } else { ?>
                    <h3>You've Saved</h3>
                    <h4><?php
                        echo 0 .' MB'; ?></h4>
                    <?php
                } ?>
                <div class="image-credits-remaining">

                    <?php
                    if (empty($wps_ic::$settings['live-cdn']) || $wps_ic::$settings['live-cdn'] == '0') { ?>
                        <a href="https://wpcompress.com/pricing" target="_blank" class="button button-primary local-requests-left">
                            <h5><?php
                                echo $accountQuota['local']; ?></h5>
                        </a>
                        <a href="https://wpcompress.com/pricing" target="_blank" class="button button-primary requests-left" style="display: none;">
                            <h5><?php
                                echo $accountQuota['live']; ?></h5>
                        </a>
                        <?php
                    } else { ?>
                        <a href="https://wpcompress.com/pricing" target="_blank" class="button button-primary local-requests-left" style="display: none;">
                            <h5><?php
                                echo $accountQuota['local']; ?></h5>
                        </a>
                        <a href="https://wpcompress.com/pricing" target="_blank" class="button button-primary requests-left">
                            <h5><?php
                                echo $accountQuota['live']; ?></h5>
                        </a>
                        <?php
                    } ?>
                </div>
            </div>

            <div class="stats-boxes">

                <div class="stats-box-single">
                    <div class="stats-box-icon-holder">
                        <img src="<?php
                        echo WPS_IC_URI; ?>/assets/images/icon-original-size.svg"/>
                    </div>
                    <div class="stats-box-text-holder">
                        <h3><?php
                            echo $user_credits->formatted->original_bandwidth; ?></h3>
                        <h5>Original</h5>
                    </div>
                </div>

                <div class="stats-box-single">
                    <div class="stats-box-icon-holder">
                        <img src="<?php
                        echo WPS_IC_URI; ?>/assets/images/icon-total-images.svg"/>
                    </div>
                    <div class="stats-box-text-holder">
                        <h3><?php
                            echo $user_credits->formatted->cdn_bandwidth; ?></h3>
                        <h5>Optimized</h5>
                    </div>
                </div>

                <div class="stats-box-single">
                    <div class="stats-box-icon-holder">
                        <img src="<?php
                        echo WPS_IC_URI; ?>/assets/images/icon-after-optimization.svg"/>
                    </div>
                    <div class="stats-box-text-holder">
                        <h3><?php
                            echo $user_credits->formatted->cdn_requests; ?></h3>
                        <h5>Assets Served</h5>
                    </div>
                </div>


            </div>
        </div>
        <?php
    }