<?php global $wps_ic; ?>
<div class="wp-compress-settings-footer">
  <div class="wp-compress-separator"></div>
  <ul>
    <li>
      <a href="https://wpcompress.com/pricing/">Get More Credits</a>
    </li>
    <li>
      <a href="https://wpcompress.com/quick-start/">Getting Started Guide</a>
    </li>
    <li>
      <a href="https://go.crisp.chat/chat/embed/?website_id=afb69c89-31ce-4a64-abc8-6b11e22e3a10">Chat with Support</a>
    </li>
    <li>
      <a href="<?php
      echo admin_url('options-general.php?page='.$wps_ic::$slug.'&view=debug_tool'); ?>">Debug Tool</a>
    </li>
    <li>
      <a href="<?php
      echo admin_url('options-general.php?page='.$wps_ic::$slug.'&check_account=true'); ?>">Clear Account Cache</a>
    </li>
  </ul>
</div>