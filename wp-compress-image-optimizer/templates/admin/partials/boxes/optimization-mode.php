<div class="autopilot-box">
    <h3>Optimization Mode</h3>
    <div class="checkbox-container-custom-pause wps-ic-ajax-checkbox-cdn ajax-change-span wps-ic-live-cdn-ajax" data-leftover="<?php
    echo $user_credits->bytes->leftover; ?>" style="padding-top: 0px;">
        <input type="checkbox" id="wp-ic-setting[live-cdn]" data-on-text="Live CDN" data-off-text="Local" value="1"
               name="wp-ic-setting[live-cdn]" data-setting_name="live-cdn" data-setting_value="1" <?php
        if (isset($wps_ic::$settings['live-cdn']) && !empty($wps_ic::$settings['live-cdn'])) {
            echo 'checked="checked"';
        } ?>/>
        <div>
            <label for="wp-ic-setting[live-cdn]" class=""></label>
            <?php
            if (isset ($wps_ic::$settings['live-cdn']) && $wps_ic::$settings['live-cdn'] == '1') { ?>
                <span>Live CDN</span>
                <?php
            } else { ?>
                <span>Local</span>
                <?php
            } ?>
        </div>
    </div>
</div>