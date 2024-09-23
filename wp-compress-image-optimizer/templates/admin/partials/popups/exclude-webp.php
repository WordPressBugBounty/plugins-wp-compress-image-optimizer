<div id="exclude-webp-popup" style="display: none;">
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
                        <h3>Exclude from Webp</h3>
                        <p>Add excluded files or paths as desired as we use wildcard searching.</p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">
                      <?php
                      $excludes = get_option('wpc-excludes');
                      if ( ! empty($excludes['webp'])) {
                        $excludes['webp'] = implode("\n", $excludes['webp']);
                      } else {
	                      $excludes['webp'] = '';
                      }

                      ?>
                        <textarea name="wpc-excludes[webp]" data-setting-name="wpc-excludes"
                                  data-setting-subset="webp"  class="exclude-list-textarea-value"
                                  placeholder="e.g. plugin-name/js/script.js, scripts.js, anyimage.jpg"><?php echo
                          $excludes['webp']; ?></textarea>

                        <div class="wps-empty-row">&nbsp;</div>

                    </div>
                </div>
                <div class="wps-example-list">
                    <div>
                        <h3>Examples:</h3>
                        <div>
                            <p>.svg would exclude all assets with that extension</p>
                            <p>imagename would exclude any file with that name</p>
                            <p>/myplugin/image.jpg would exclude that specific file</p>
                            <p>/wp-content/myplugin/ would exclude everything using that path</p>
                            <p>#classname would exclude all files with that class</p>
                        </div>
                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
            </form>
        </div>

    </div>
</div>
