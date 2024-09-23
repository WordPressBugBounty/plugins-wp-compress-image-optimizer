<?php
global $current_screen;
$mode = get_user_option( 'media_library_mode', get_current_user_id() ) ? get_user_option( 'media_library_mode', get_current_user_id() ) : 'grid';

if (!empty($_GET['hide_ic_notice'])) {
  update_option('wps_ic_list_mode_notice', 'hide');
}

$notice_trans = get_option('wps_ic_list_mode_notice');
if (!$notice_trans) {
  if ($mode == 'grid' && $current_screen->base == 'upload') {
    ?>
    <div class="notice wp-compress-notice wp-compress-error">
      <div class="wp-compress-logo" style="padding-right:0; ">
        <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" style="margin-right: 0px"/>
      </div>
      <div class="wp-compress-message-list-mode">
        <p style="margin-bottom: 0px !important;display: inline-block; line-height: 55px;">Switch to list mode to compress single images.</p>
        <a href="<?php echo admin_url('upload.php?mode=list'); ?>" class="button button-primary button-smaller" style="display: inline-block;padding: 0px 10px;margin-left: 20px;margin-top: 22px;">Go to List Mode</a>
        <a href="<?php echo admin_url('upload.php?mode=grid&hide_ic_notice=true'); ?>" class="button button-primary button-smaller" style="display: inline-block;padding: 0px 10px;margin-left: 20px;margin-top: 22px;">Hide permanently</a>
      </div>
    </div>
  <?php }
}?>