<?php
global $wps_ic, $wpdb;

// Vars
$tab = '';
if (!empty($_GET['tab'])) {
  $tab = $_GET['tab'];
}

// Menu configuration
$menu_items = array();
$menu_items[] = array('url' => network_admin_url('admin.php?page=' . $wps_ic::$slug . '-mu&tab=default-settings'), 'tab' => 'default-settings', 'title' => 'Default Settings', 'class' => 'wp-mu-default-settings');
$menu_items[] = array('url' => network_admin_url('admin.php?page=' . $wps_ic::$slug . '-mu&tab=manage-websites'), 'tab' => 'manage-websites', 'title' => 'Manage Websites', 'class' => 'wp-mu-manage-websites');

?>
<div class="wrap">
    <div class="wps_ic_wrap wps_ic_mu_page">
        <div class="wp-compress-pre-wrapper">
            <div class="wp-compress-mu-inner-wrapper">
                <div class="wp-compress-mu-sidebar">
                    <div class="wp-ic-logo-container">
                        <div class="wp-compress-logo">
                            <img src="<?php
                            echo WPS_IC_URI; ?>assets/images/main-logo.svg"/>
                        </div>
                    </div>
                    <ul class="wp-compress-mu-menu">
                      <?php
                      foreach ($menu_items as $i => $item) {
                        $active = '';

                        if ($item['tab'] == $tab || (empty($tab) && $item['tab'] == 'default-settings')) {
                          $active = 'active';
                        }

                        echo '<li><a href="' . $item['url'] . '" class="' . $item['class'] . ' ' . $active . '"><span class="wp-mu-icon"></span> ' . $item['title'] . '</a></li>';
                      }
                      ?>
                    </ul>
                    <div class="wp-compress-mu-thin-sep">&nbsp;</div>
                    <ul class="wp-compress-mu-site-list">
                      <?php
                      $sites = get_sites();

                      if ($sites) {
                        foreach ($sites as $site) {
                          switch_to_blog($site->blog_id);
                          $options = get_option(WPS_IC_OPTIONS);
                          $apikey = $options['api_key'];
                          $current_blog_details = get_blog_details(array('blog_id' => $site->blog_id));
                          $options = get_option(WPS_IC_OPTIONS);
                          $siteUrl = admin_url('options-general.php?page=wpcompress');

                          $connected_class = ' wps-ic-mu-not-connected';
                          if (!empty($options['api_key']) && !empty($options['response_key'])) {
                            $connected_class = ' wps-ic-mu-connected';
                          }

                          if (empty($tab) || $tab !== 'manage-websites') {
                            echo '<li><a href="' . network_admin_url('admin.php?page=' . $wps_ic::$slug . '-mu&tab=manage-websites#mu-' . $site->blog_id) . '" class="wpc-ic-mu-site-list-item-' . $site->blog_id . ' wpc-ic-mu-ignore ' . $connected_class . '" data-site-id="' . $site->blog_id . '">' . $current_blog_details->blogname . '</a></li>';
                          } else {
                            #echo '<li><a href="' . network_admin_url('admin.php?page=' . $wps_ic::$slug . '-mu&tab=manage-websites#mu-' . $site->blog_id) . '" class="wpc-ic-mu-site-list-item-' . $site->blog_id . ' wp-mu-site-' . $site->blog_id . ' ' . $connected_class . '" data-site-id="' . $site->blog_id . '">' . $current_blog_details->blogname . '</a></li>';
                            echo '<li><a href="' . $siteUrl . '" target="_blank" class="wpc-ic-mu-site-list-item-' . $site->blog_id . '">' . $current_blog_details->blogname . '</a></li>';
                          }

                        } ?>
                        <?php
                      } ?>
                    </ul>
                </div>
                <div class="wp-compress-mu-content">

                    <div class="wps-ic-mu-site-saving" style="display: none;">
                        <div class="wps-ic-mu-site-saving-logo">
                            <img src="<?php
                            echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
                            <img src="<?php
                            echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
                        </div>
                    </div>

                    <div class="wp-compress-mu-content-overlay" style="display: none;">
                        <div class="wp-compress-mu-content-overlay-inner">
                            <div class="wps-ic-mu-site-saving-logo">
                                <img src="<?php
                                echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
                                <img src="<?php
                                echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
                            </div>
                        </div>

                      <?php
                      include WPS_IC_TEMPLATES . '/mu/tabs/overlay-settings.php'; ?>
                    </div>

                    <div class="wp-compress-mu-content-inner">
                      <?php
                      if (empty($tab)) {
                        include WPS_IC_TEMPLATES . '/mu/tabs/default-settings.php';
                      } else {
                        switch ($tab) {
                          case 'manage-websites':
                            include WPS_IC_TEMPLATES . '/mu/tabs/bulk-connect-list.php';
                            break;
                          case 'default-settings':
                            include WPS_IC_TEMPLATES . '/mu/tabs/default-settings.php';
                            break;
                          case 'bulk-configure-settings';
                            include WPS_IC_TEMPLATES . '/mu/tabs/bulk-configure-settings.php';
                            break;
                          case 'bulk-connect':
                            include WPS_IC_TEMPLATES . '/mu/tabs/bulk-connect.php';
                            break;
                          case 'bulk-connect-list':
                            include WPS_IC_TEMPLATES . '/mu/tabs/bulk-connect-list.php';
                            break;
                        }

                      } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>