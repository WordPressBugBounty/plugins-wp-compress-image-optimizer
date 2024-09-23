<div id="inline-css" style="display: none;">
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
                        <h3>Inline CSS</h3>
                        <p>Add ID tags of scripts to be inlined.</p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">
                      <?php
                      $excludes = get_option('wpc-inline');
                      if ( ! empty($excludes['inline_css'])) {
                        $excludes['inline_css'] = implode("\n", $excludes['inline_css']);
                      } else {
                        $excludes['inline_css'] = '';
                      }

                      ?>
                        <textarea name="wpc-excludes[inline_css]" data-setting-name="wpc-inline"
                                  data-setting-subset="inline_css" class="exclude-list-textarea-value" placeholder="e.g. theme-style-css"><?php echo $excludes['inline_css']; ?></textarea>

                        <div class="wps-empty-row">&nbsp;</div>

                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
            </form>
        </div>

    </div>
</div>
