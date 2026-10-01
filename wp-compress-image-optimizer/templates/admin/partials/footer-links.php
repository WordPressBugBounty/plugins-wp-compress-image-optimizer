<?php global $wps_ic; ?>
<div class="wp-compress-settings-footer">
  <div class="wp-compress-separator"></div>
  <ul>
    <li>
      <a href="https://wpcompress.com/pricing/"><?php esc_html_e('Get More Credits', WPS_IC_TEXTDOMAIN); ?></a>
    </li>
    <li>
      <a href="https://wpcompress.com/quick-start/"><?php esc_html_e('Getting Started Guide', WPS_IC_TEXTDOMAIN); ?></a>
    </li>
    <li>
      <a href="https://go.crisp.chat/chat/embed/?website_id=afb69c89-31ce-4a64-abc8-6b11e22e3a10"><?php esc_html_e('Chat with Support', WPS_IC_TEXTDOMAIN); ?></a>
    </li>
    <li>
      <a href="<?php
      echo admin_url('options-general.php?page='.$wps_ic::$slug.'&view=debug_tool'); ?>"><?php esc_html_e('Debug Tool', WPS_IC_TEXTDOMAIN); ?></a>
    </li>
    <li>
      <a href="<?php
      echo admin_url('options-general.php?page='.$wps_ic::$slug.'&check_account=true'); ?>"><?php esc_html_e('Clear Account Cache', WPS_IC_TEXTDOMAIN); ?></a>
    </li>
  </ul>
</div>