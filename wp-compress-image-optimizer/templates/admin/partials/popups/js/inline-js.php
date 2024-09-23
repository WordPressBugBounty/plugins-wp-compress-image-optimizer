<div id="inline-js" style="display: none;">
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
                        <h3>Inline JavaScript</h3>
                        <p>Add ID tags of scripts to be inlined.</p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">
                      <?php
                      $excludes = get_option('wpc-inline');
                      if ( ! empty($excludes['inline_js'])) {
                        $excludes['inline_js'] = implode("\n", $excludes['inline_js']);
                      } else {
                        $excludes['inline_js'] = '';
                      }

                      ?>
                        <textarea name="wpc-excludes[inline_js]" data-setting-name="wpc-inline"
                                  data-setting-subset="inline_js" class="exclude-list-textarea-value" placeholder="e.g. jquery-core-js"><?php echo $excludes['inline_js']; ?></textarea>

                        <div class="wps-empty-row">&nbsp;</div>

                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
            </form>
        </div>

    </div>
</div>
