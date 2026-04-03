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

        <div class="wpc-debug-bar" style="background:#1e293b;color:#e2e8f0;padding:8px 12px;font-family:monospace;font-size:11px;display:flex;gap:6px;flex-wrap:wrap;border-radius:6px 6px 0 0;">
            <span style="color:#94a3b8;margin-right:4px;">Debug:</span>
            <button type="button" class="wpc-debug-btn" data-show="step-1" style="background:#334155;color:#93c5fd;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Step 1</button>
            <button type="button" class="wpc-debug-btn" data-show="loading" style="background:#334155;color:#93c5fd;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Loading</button>
            <button type="button" class="wpc-debug-btn" data-show="step-2" style="background:#334155;color:#93c5fd;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Step 2 (Success)</button>
            <button type="button" class="wpc-debug-btn" data-show="retry" style="background:#334155;color:#93c5fd;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Retry</button>
            <button type="button" class="wpc-debug-btn" data-show="err-dns" style="background:#334155;color:#fca5a5;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Err: DNS</button>
            <button type="button" class="wpc-debug-btn" data-show="err-api" style="background:#334155;color:#fca5a5;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Err: API</button>
            <button type="button" class="wpc-debug-btn" data-show="err-invalid" style="background:#334155;color:#fca5a5;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Err: Invalid</button>
            <button type="button" class="wpc-debug-btn" data-show="err-empty" style="background:#334155;color:#fca5a5;border:1px solid #475569;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:monospace;">Err: Empty</button>
        </div>
        <script>
        jQuery(document).on('click', '.wpc-debug-btn', function(e) {
            e.preventDefault(); e.stopPropagation();
            var popup = jQuery(this).closest('.custom-cname-popup');
            var s = jQuery(this).data('show');
            // Reset all
            popup.find('.cdn-popup-loading').hide();
            popup.find('.cdn-popup-content').show();
            popup.find('.cdn-popup-top').show();
            popup.find('.custom-cdn-step-1').hide();
            popup.find('.custom-cdn-step-2').hide();
            popup.find('.custom-cdn-step-1-retry').hide();
            popup.find('.custom-cdn-error-message').hide();
            popup.find('.wpc-dns-error-text').hide();
            popup.find('.error').remove();
            popup.find('[name="custom-cdn"]').removeClass('empty');

            if (s === 'step-1') { popup.find('.custom-cdn-step-1').show(); }
            else if (s === 'loading') { popup.find('.cdn-popup-content').hide(); popup.find('.cdn-popup-loading').show(); }
            else if (s === 'step-2') { popup.find('.custom-cdn-step-2').show(); popup.find('.btn-i-cant-see').html('I can\'t see the above image').removeClass('disabled'); }
            else if (s === 'retry') { popup.find('.custom-cdn-step-1-retry').show(); }
            else if (s === 'err-dns') { popup.find('.custom-cdn-step-1').show(); popup.find('.custom-cdn-error-message').html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS is not set correctly...').show(); popup.find('.wpc-dns-error-text').show(); }
            else if (s === 'err-api') { popup.find('.custom-cdn-step-1').show(); popup.find('.custom-cdn-error-message').html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS API is not working, please contact support...').show(); }
            else if (s === 'err-invalid') { popup.find('.custom-cdn-step-1').show(); popup.find('.custom-cdn-error-message').html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> This domain is invalid, please link a new domain...').show(); }
            else if (s === 'err-empty') { popup.find('.custom-cdn-step-1').show(); popup.find('[name="custom-cdn"]').addClass('empty'); popup.find('.custom-cdn-step-1 .custom-cdn-error-message').html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> You must fill out the CNAME.').show(); }
        });
        </script>

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
                              echo $zone_name; ?></strong>
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
                        echo $custom_cname; ?>"/>
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
                              echo $zone_name; ?></strong>
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
                        echo $custom_cname; ?>"/>
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
