<div class="wps-ic-mu-popup-empty-cname" style="display: none;">
    <div id="cdn-popup-empty-sites-inner" class="ajax-settings-popup bottom-border custom-cname-popup-empty-sites cdn-popup-inner">

        <div style="padding-bottom:30px;">
            <div class="wps-ic-mu-popup-select-sites">
                <img src="<?php
                echo WPS_IC_URI; ?>assets/images/projected-alert.svg" style="width:160px;"/>
            </div>
            <h3><?php echo esc_html__('You need to insert your CNAME!', WPS_IC_TEXTDOMAIN); ?></h3>
        </div>
    </div>
</div>
<div id="custom-cdn" style="display: none;">
    <div id="cdn-popup-inner" class="ajax-settings-popup bottom-border custom-cname-popup cdn-popup-inner">

        <div class="cdn-popup-loading" style="display: none;">
            <div class="wpc-cname-spinner"></div>
            <p class="wpc-cname-loading-text"><?php echo __('Setting up your DNS&hellip; this can take up to 30 seconds.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

        <div class="cdn-popup-content">
            <div class="custom-cdn-steps">
                <div class="custom-cdn-step-1">
                    <div class="cdn-popup-top">
                        <img class="popup-icon" src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-custom-cdn.svg"/>
                        <h3><?php echo esc_html__('Custom CDN Domain', WPS_IC_TEXTDOMAIN); ?></h3>
                    </div>
                  <?php
                  $zone_name = get_option('ic_cdn_zone_name');
                  ?>
                    <ul>
                        <li><?php echo __('<b>1. Create a subdomain</b> or domain that you wish to use. It can take up to 24h to propagate globally.', WPS_IC_TEXTDOMAIN); ?></li>
                        <li><?php echo __('<b>2. Edit the DNS records</b> for the domain to create a new CNAME pointed at', WPS_IC_TEXTDOMAIN); ?>
                            <strong class="wpc-copy-on-click" title="<?php echo esc_attr__('Click to copy', WPS_IC_TEXTDOMAIN); ?>"><?php
                              echo esc_html($zone_name); ?></strong>
                        </li>
                        <li><?php echo __('<b>3. Enter the URL</b> you\'ve pointed to below:', WPS_IC_TEXTDOMAIN); ?></li>
                    </ul>
                    <p class="wpc-error-text wpc-dns-error-text" style="display: none;">
                        <?php echo __('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span>According to our tests it seems like your DNS is either incorrect or still propagating globally. This standard process can take 24-48 hours to be globally available. If you link too soon you may have downtime in unpropagated regions.', WPS_IC_TEXTDOMAIN); ?>
                    </p>
                    <div class="custom-cdn-error-message wpc-error-text" style="display: none;">
                        &nbsp;
                    </div>
                    <form method="post" action="#" class="wpc-form-inline">
                      <?php
                      $custom_cname = get_option('ic_custom_cname');
                      ?>
                        <input type="text" name="custom-cdn" placeholder="<?php echo esc_attr__('Example: cdn.mysite.com', WPS_IC_TEXTDOMAIN); ?>" value="<?php
                        echo esc_attr($custom_cname); ?>"/>
                        <input type="submit" value="<?php echo esc_attr__('Save', WPS_IC_TEXTDOMAIN); ?>" name="save"/>
                    </form>

                </div>
                <div class="custom-cdn-step-2" style="display: none;">
                    <img class="custom-cdn-step-2-img" src="<?php echo WPS_IC_URI; ?>assets/images/fireworks.svg"/>
                    <h3><?php echo esc_html__('Custom Domain Configuration', WPS_IC_TEXTDOMAIN); ?></h3>
                    <p><?php echo esc_html__('If you can see the celebration image on the following link, your custom domain is working!', WPS_IC_TEXTDOMAIN); ?></p>
                    <a href="https://dnschecker.org/#CNAME/<?php echo esc_attr($custom_cname ?: $zone_name); ?>" target="_blank" class="wpc-check-cdn-link"><?php echo esc_html__('Check DNS', WPS_IC_TEXTDOMAIN); ?></a>
                    <p class="wpc-error-text wpc-dns-error-text" style="display: none;">
                        <?php echo __('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span>According to our tests it seems like your DNS is either incorrect or still propagating globally. This standard process can take 24-48 hours to be globally available. If you link too soon you may have downtime in unpropagated regions.', WPS_IC_TEXTDOMAIN); ?>
                    </p>
                    <div class="wps-empty-row"></div>
                    <a href="#" class="btn btn-primary btn-i-cant-see btn-cdn-config"><?php echo esc_html__("I can't see the above Image", WPS_IC_TEXTDOMAIN); ?></a>
                    <a href="#" class="btn btn-primary btn-active btn-close btn-cdn-config"><?php echo esc_html__('All Good to Go!', WPS_IC_TEXTDOMAIN); ?></a>
                </div>
                <div class="custom-cdn-step-1-retry" style="display: none;">
                    <div class="cdn-popup-top">
                        <img class="popup-icon" src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-custom-cdn.svg"/>
                        <h3><?php echo esc_html__('Custom CDN Domain', WPS_IC_TEXTDOMAIN); ?></h3>
                    </div>
                  <?php
                  $zone_name = get_option('ic_cdn_zone_name');
                  ?>
                    <ul>
                        <li><?php echo __('<b>1. Create a subdomain</b> or domain that you wish to use. It can take up to 24h to propagate globally.', WPS_IC_TEXTDOMAIN); ?></li>
                        <li><?php echo __('<b>2. Edit the DNS records</b> for the domain to create a new CNAME pointed at', WPS_IC_TEXTDOMAIN); ?>
                            <strong class="wpc-copy-on-click" title="<?php echo esc_attr__('Click to copy', WPS_IC_TEXTDOMAIN); ?>"><?php
                              echo esc_html($zone_name); ?></strong>
                        </li>
                        <li><?php echo __('<b>3. Enter the URL</b> you\'ve pointed to below:', WPS_IC_TEXTDOMAIN); ?></li>
                    </ul>
                    <div class="custom-cdn-error-message wpc-error-text" style="display: none;">
                        &nbsp;
                    </div>
                    <form method="post" action="#" class="wpc-form-inline">
                      <?php
                      $custom_cname = get_option('ic_custom_cname');
                      ?>
                        <input type="text" name="custom-cdn" placeholder="<?php echo esc_attr__('Example: cdn.mysite.com', WPS_IC_TEXTDOMAIN); ?>" value="<?php
                        echo esc_attr($custom_cname); ?>"/>
                        <input type="submit" value="<?php echo esc_attr__('Save', WPS_IC_TEXTDOMAIN); ?>" name="save"/>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<div id="remove-custom-cdn" style="display: none;">
    <div id="cdn-popup-inner" class="ajax-settings-popup bottom-border remove-cname-popup cdn-popup-inner">

        <div class="cdn-popup-loading" style="display: none;">
            <div class="wpc-cname-spinner"></div>
            <p class="wpc-cname-loading-text"><?php echo __('Removing custom domain&hellip;', WPS_IC_TEXTDOMAIN); ?></p>
        </div>
    </div>
</div>
