<div class="wps-ic-mu-popup-empty-cname" style="display: none;">
    <div id="cdn-popup-empty-sites-inner" class="ajax-settings-popup bottom-border custom-cname-popup-empty-sites cdn-popup-inner">

        <div style="padding-bottom:30px;">
            <div class="wps-ic-mu-popup-select-sites">
                <img src="<?php
                echo WPS_IC_URI; ?>assets/images/projected-alert.svg" style="width:160px;"/>
            </div>
            <h3>You need to insert your CNAME!</h3>
        </div>
    </div>
</div>
<div id="custom-cdn" style="display: none;">
    <div id="cdn-popup-inner" class="ajax-settings-popup bottom-border custom-cname-popup cdn-popup-inner">

        <div class="cdn-popup-loading" style="display: none;">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
            <h4 style="margin-top: 0px;margin-bottom: 46px;display:none;">We are setting up your DNS, this can take up to 30 seconds...</h4>
        </div>

        <div class="cdn-popup-content">
            <div class="custom-cdn-steps">
                <div class="custom-cdn-step-1">
                    <div class="cdn-popup-top">
                        <h3>Custom CDN Domain</h3>
                        <img class="popup-icon" src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-custom-cdn.svg"/>
                    </div>
                  <?php
                  $zone_name = get_option('ic_cdn_zone_name');
                  ?>
                    <ul>
                        <li>1. Create a subdomain or domain that you wish to use, It can take up to 24h to propagate globally.</li>
                        <li>2. Edit the DNS records for the domain to create a new CNAME pointed at
                            <strong><?php
                              echo $zone_name; ?></strong>
                        </li>
                        <li>3. Enter the url you've pointed below:</li>
                    </ul>
                    <p class="wpc-error-text wpc-dns-error-text" style="display: none;">
                        <span class="icon-container close-toggle"><i class="icon-cancel"></i></span>According to our tests it seems like your DNS is either incorrect or still propagating globally. This standard process can take 24-48 hours to be globally available. If you link too soon you may have downtime in unpropagated regions.
                    </p>
                    <div class="custom-cdn-error-message wpc-error-text" style="display: none;">
                        &nbsp;
                    </div>
                    <form method="post" action="#" class="wpc-form-inline">
                      <?php
                      $custom_cname = get_option('ic_custom_cname');
                      ?>
                        <input type="text" name="custom-cdn" placeholder="Example: cdn.mysite.com" value="<?php
                        echo $custom_cname; ?>"/>
                        <input type="submit" value="Save" name="save"/>
                    </form>

                </div>
                <div class="custom-cdn-step-2" style="display: none;">
                    <img class="custom-cdn-step-2-img" src="<?php echo WPS_IC_URI; ?>assets/images/fireworks.svg"/>
                    <h3>Custom Domain Configuration</h3>
                    <p>If you can see the celebration the image on following link your custom domain is working!</p>
                    <a href="{DNSCHECKIMG}" target="_blank" class="wpc-check-cdn-link">Check DNS</a>
                    <p class="wpc-error-text wpc-dns-error-text" style="display: none;">
                        <span class="icon-container close-toggle"><i class="icon-cancel"></i></span>According to our tests it seems like your DNS is either incorrect or still propagating globally. This standard process can take 24-48 hours to be globally available. If you link too soon you may have downtime in unpropagated regions.
                    </p>
                    <div class="wps-empty-row"></div>
                    <a href="#" class="btn btn-primary btn-i-cant-see btn-cdn-config">I can't see the above Image</a>
                    <a href="#" class="btn btn-primary btn-active btn-close btn-cdn-config">All Good to Go!</a>
                </div>
                <div class="custom-cdn-step-1-retry" style="display: none;">
                    <div class="cdn-popup-top">
                        <h3>Custom CDN Domain</h3>
                        <img class="popup-icon" src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-custom-cdn.svg"/>
                    </div>
                  <?php
                  $zone_name = get_option('ic_cdn_zone_name');
                  ?>
                    <ul>
                        <li>1. Create a subdomain or domain that you wish to use, It can take up to 24h to propagate globally.</li>
                        <li>2. Edit the DNS records for the domain to create a new CNAME pointed at
                            <strong><?php
                              echo $zone_name; ?></strong>
                        </li>
                        <li>3. Enter the url you've pointed below:</li>
                    </ul>
                    <div class="custom-cdn-error-message wpc-error-text" style="display: none;">
                        &nbsp;
                    </div>
                    <form method="post" action="#" class="wpc-form-inline">
                      <?php
                      $custom_cname = get_option('ic_custom_cname');
                      ?>
                        <input type="text" name="custom-cdn" placeholder="Example: cdn.mysite.com" value="<?php
                        echo $custom_cname; ?>"/>
                        <input type="submit" value="Save" name="save"/>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<div id="remove-custom-cdn" style="display: none;">
    <div id="cdn-popup-inner" class="ajax-settings-popup bottom-border remove-cname-popup cdn-popup-inner">

        <div class="cdn-popup-loading" style="display: none;">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
        </div>
    </div>
</div>
