<?php
switch_to_blog(1);
$mu_settings = get_option(WPS_IC_MU_SETTINGS);

if (empty($mu_settings['autoconnect'])) {
  $mu_settings['autoconnect'] = '0';
}

?>

<div class="wpc-ic-mu-site-container ic-advanced-settings-v2">

  <div class="wpc-ic-mu-bulk-site-list-container">
    <div class="wpc-ic-mu-site-header">
      <div class="wpc-ic-mu-site-left-side left-side-smaller">
        <div class="wpc-ic-mu-site-name-manage-websites">
          <h3><?php echo 'Manage Websites'; ?></h3>
          <div class="setting-option wpc-checkbox">
            <label style="color:#7e7e7e;" for="wpc-ic-mu-autoconnect">Auto-connect new sites</label>
            <input type="checkbox" id="wpc-ic-mu-autoconnect" class="wpc-ic-mu-sites-checkbox wpc-ic-mu-setting-checkbox" name="wpc-ic-mu-setting[autoconnect]" data-setting="autoconnect" value="1" <?php echo checked($mu_settings['autoconnect'], '1'); ?> />
          </div>
        </div>
      </div>
      <div class="wpc-ic-mu-site-right-side">
        <a href="#" class="wpc-ic-mu-button wps-ic-mu-bulk-configure hvr-grow"><i class="icon-cog"></i> Configure</a>
        <a href="#" class="wpc-ic-mu-button wps-ic-mu-bulk-connect-all hvr-grow"><i class="icon-link"></i> Connect All</a>
        <a href="#" class="wpc-ic-mu-button wps-ic-mu-bulk-disconnect-all hvr-grow"><i class="icon-cancel"></i> Disconnect All</a>
      </div>
    </div>
    <div class="wpc-ic-mu-separator"></div>

    <div class="wps-ic-mu-bulk-saving" style="display: none;">
      <div class="wps-ic-mu-site-saving-logo">
        <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
        <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
      </div>
    </div>

    <div class="wps-ic-mu-bulk-saving-done" style="display: none;">
      <h3>We have successfully done something...</h3>
      <a href="<?php echo network_admin_url('admin.php?page=' . $wps_ic::$slug . '-mu&tab=manage-websites'); ?>">Click here to return</a>
    </div>

    <div class="wp-compress-settings-row wps-ic-mu-bulk-site-list" style="margin-top:0px;">
      <div class="text-center" style="width: 100%;display: flex;">
        <table class="wpc-ic-mu-list-table">
          <thead>
          <tr>
            <th style="text-align: center;">
              <div class="setting-option wpc-checkbox"><input type="checkbox" name="wpc-ic-mu-select-all" value="all"/></div>
            </th>
            <th style="text-align: center;">Status</th>
            <th style="text-align: left;">Title</th>
            <th style="text-align: left;">URL</th>
            <th style="text-align: right;"></th>
          </tr>
          </thead>
          <tbody>
					<?php
					$sites = get_sites();

					if ($sites) {
						foreach ($sites as $site) {
							switch_to_blog($site->blog_id);
							$options              = get_option(WPS_IC_OPTIONS);
							$settings             = get_option(WPS_IC_SETTINGS);
							$apikey               = $options['api_key'];
							$current_blog_details = get_blog_details(array('blog_id' => $site->blog_id));

							$site_status_tag = 'wps-ic-mu-tag-not-connected';
							$connected_class = ' wps-ic-mu-not-connected';
							if ( ! empty($options['api_key']) && ! empty($options['response_key'])) {
								$connected_class = ' wps-ic-mu-connected';
								$site_status_tag = 'wps-ic-mu-tag-connected';
							}

							echo '<tr class="wpc-ic-mu-row-site-' . $site->blog_id . '">
                      <td class="wpc-ic-mu-list-checkbox">';

							echo '<div class="setting-option wpc-checkbox">';
							//<label for="js-toggle">JavaScript via CDN</label>

							if ( ! empty($options['api_key']) && ! empty($options['response_key'])) {
								echo '<input type="checkbox" class="wpc-ic-mu-sites-checkbox" name="wpc-ic-mu-sites[]" data-status="connected" value="' . $site->blog_id . '" />';
							}
							else {
								echo '<input type="checkbox" class="wpc-ic-mu-sites-checkbox" name="wpc-ic-mu-sites[]" data-status="disconnected" value="' . $site->blog_id . '" />';
							}

							echo '</div>';

							echo '</td>
							        <td class="wpc-ic-mu-list-actions"><span class="' . $site_status_tag . '"></span></td>
                      <td class="wpc-ic-mu-list-title">' . $current_blog_details->blogname . '</td>
                      <td class="wpc-ic-mu-list-url">
                      <a href="' . network_admin_url('admin.php?page=' . $wps_ic::$slug . '-mu&tab=link-websites#mu-' . $site->blog_id) . '" class="wpc-ic-mu-ignore ' . $connected_class . '" data-site-id="' . $site->blog_id . '">' . $current_blog_details->siteurl . '</a>
                      </td>
                      <td class="wps-ic-mu-list-change-status">';


							echo '<div class="wps-ic-mu-status-actions">';
							if ( ! empty($options['api_key']) && ! empty($options['response_key'])) {
								echo '<a href="#" class="wps-ic-mu-configure ic-tooltip hvr-grow" data-site-id="' . $site->blog_id . '" title="Configure"><i class="icon icon-cog"></i></a>';
								echo '<a href="#" class="wps-ic-mu-disconnect wpc-mu-individual-disconnect-bulk ic-tooltip hvr-grow" data-site-id="' . $site->blog_id . '" title="Disconnect"><i class="icon icon-cancel"></i></a>';
							}
							else {
								echo '<a href="#" class="wps-ic-mu-connect wpc-mu-individual-connect-bulk hvr-grow" data-site-id="' . $site->blog_id . '"><i class="icon icon-link"></i> Connect</a>';
							}
							echo '</div>';

							echo '<div class="wps-ic-mu-status-loading" style="display:none;">
                      <div class="wps-ic-mu-logo">
                        <img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
                        <img src="' . WPS_IC_URI . 'assets/preparing.svg" class="wpc-ic-mu-preparing"/>
                      </div>
                      <div class="wps-ic-mu-status-text">Working...</div>
                      </div>';

							echo '</td>
                  </tr>';

						}
					}
					?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="wps-ic-mu-bulk-disconnect-all" style="display: none;">
    <div id="disconnect-popup-inner" class="swal-popup-inner bottom-border">

      <div class="cdn-popup-top">
        <img src="<?php echo WPS_IC_URI; ?>assets/mu/images/disconnect.svg" class="disconnect-logo"/>
        <h3>Are you sure you want to disconnect?</h3>
        <p style="font-size:14px;">You may reconnect at any time. If you are in live mode, image optimization and delivery will stop immediately. If you've locally optimized images, images will remain in their current state after deactivation.</p>
      </div>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

    </div>
  </div>

  <div class="wps-ic-mu-bulk-reconfiguring-outer" style="display: none;">
  <div class="wps-ic-mu-bulk-reconfiguring">
    <div class="wps-ic-mu-site-saving-logo">
      <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
      <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
    </div>
  </div>
  </div>

  <div class="wps-ic-mu-bulk-reconfigure-settings" style="display: none;">
    <div class="wpc-ic-mu-bulk-connect-settings">
			<?php
			include WPS_IC_TEMPLATES . '/mu/tabs/bulk-configure-settings.php';
			?>
    </div>
  </div>

  <div class="wps-ic-mu-bulk-configure-settings" style="display: none;">
    <div class="wpc-ic-mu-bulk-connect-settings">
			<?php
			include WPS_IC_TEMPLATES . '/mu/tabs/bulk-default-settings.php';
			?>
    </div>
  </div>

  <div class="wps-ic-mu-bulk-connecting" style="display: none;">
    <div class="wpc-ic-mu-bulk-connecting-inner">
			<?php
			include WPS_IC_TEMPLATES . '/mu/tabs/bulk-connecting-process.php';
			?>
    </div>
  </div>

  <div class="wps-ic-mu-popup-empty-sites" style="display:none;">
    <div id="disconnect-popup-inner" class="swal-popup-inner bottom-border">

      <div class="cdn-popup-top">
        <div class="wps-ic-mu-popup-select-sites">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/projected-alert.svg" style="width:160px;" />
        </div>
        <div class="wps-ic-mu-popup-all-sites-connected" style="display: none;">
          <h3>Selected sites are already connected!</h3>
        </div>
        <div class="wps-ic-mu-popup-all-sites-disconnected" style="display: none;">
          <h3>Selected sites are already disconnected</h3>
        </div>
      </div>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

    </div>
  </div>

  <div class="wps-ic-mu-popup-select-sites" style="display:none;">
    <div id="disconnect-popup-inner" class="swal-popup-inner bottom-border">

      <div class="cdn-popup-top">
        <div class="wps-ic-mu-popup-select-sites">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/projected-alert.svg" style="width:160px;" />
          <h3 style="margin-bottom:60px;">Please select sites which you would like to configure.</h3>
        </div>
      </div>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

    </div>
  </div>

</div>