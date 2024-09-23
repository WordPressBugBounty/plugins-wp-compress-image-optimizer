<div id="exclude-advanced-caching-popup" style="display: none;">
  <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

    <div class="cdn-popup-loading" style="display: none;">
      <div class="wpc-popup-saving-logo-container">
        <div class="wpc-popup-saving-preparing-logo">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
          <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
        </div>
      </div>
    </div>

    <div class="cdn-popup-content">
    <div class="cdn-popup-top">
      <div class="inline-heading">
        <div class="inline-heading-icon">
          <img src="<?php
          echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
        </div>
        <div class="inline-heading-text">
          <h3>Exclude from Advanced Caching</h3>
          <p>Add excluded files or paths as desired as we use wildcard searching.</p>
        </div>
      </div>
    </div>

    <form method="post" class="wpc-save-popup-data" action="#">
      <div class="cdn-popup-content-full">
        <div class="cdn-popup-content-inner">
          <?php
          $excludes = get_option('wpc-excludes');
          if ( ! empty($excludes['cache'])) {
            $excludes['cache'] = implode("\n", $excludes['cache']);
          } else {
	          $excludes['cache'] = '';
          }
          
          ?>
          <textarea name="wpc-excludes[cache]" data-setting-name="wpc-excludes" data-setting-subset="cache" class="exclude-list-textarea-value" placeholder="e.g. www.example.com example.com example.com/page1"><?php echo $excludes['cache']; ?></textarea>

          <div class="wps-empty-row">&nbsp;</div>

        </div>
      </div>
      <div class="wps-example-list">
        <div>
            <h3>Examples:</h3>
            <div>
                <p>www.siteurl.com/page to exclude just the page</p>
                <p>www.siteurl.com/page/subpage to exclude just the subpage</p>
            </div>
        </div>
      </div>
      <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
    </form>
    </div>

  </div>
</div>
