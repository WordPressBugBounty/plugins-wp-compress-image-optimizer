<div id="purge-settings" style="display: none;">
    <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

        <div class="cdn-popup-loading">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
        </div>

        <div class="cdn-popup-content" style="display: none;">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-icon">
                        <img src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    </div>
                    <div class="inline-heading-text">
                        <h3>Cache Purge Settings</h3>
                        <p>Fine tune the purging of cache files.</p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">

                        <h4 style="
                            text-align: start;
                            padding-left: 40px;
                            margin-bottom: 0;">
                            Rules for Post Publish/Update
                        </h4>

                        <div class="wps-default-excludes-container">
                            <div class="wps-default-excludes-enabled-checkbox-container">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-all-pages">
                                <p>All Pages</p>
                            </div>
                            <div class="wps-default-excludes-enabled-checkbox-container">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-home-page">
                                <p>Home Page</p>
                            </div>
                            <div class="wps-default-excludes-enabled-checkbox-container">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-recent-posts-widget">
                                <p>Pages with Recent Posts Widget</p>
                            </div>

                            <div class="wps-default-excludes-enabled-checkbox-container">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-archive-pages">
                                <p>Archive Pages</p>
                            </div>
                        </div>

                        <div style="display:flex;padding-left:40px;padding-right:80px;justify-content: space-between;">

                            <h4 style="text-align: start;margin-bottom: 0;width:300px;">
                                List of hooks to purge All Pages
                            </h4>

                            <h4 style="text-align: end;margin-bottom: 0;width:90px;">
                                Defaults
                            </h4>

                        </div>

                        <div style="display:flex;padding-left:40px;padding-right:40px;justify-content: space-between;">

                            <textarea name="wpc-purge-hooks" class="hooks-list-textarea-value" style="font-size:13px;line-height:1.5;padding-top:0px;"></textarea>

                            <div class="wps-example-list" style="display:flex;">
                                <div>
                                    <div>
                                        <p> switch_theme<br>
                                            add_link<br>
                                            edit_link<br>
                                            delete_link<br>
                                            update_option_sidebars_widgets<br>
                                            update_option_category_base<br>
                                            update_option_tag_base<br>
                                            wp_update_nav_menu<br>
                                            permalink_structure_changed<br>
                                            customize_save<br>
                                            <?php echo 'update_option_theme_mods_' . get_option( 'stylesheet'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="wps-empty-row">&nbsp;</div>

                            <h4 style="
                            text-align: start;
                            padding-left: 40px;
                            margin-bottom: 0;">
                                Scheduled Purge
                            </h4>

                        <div class="wps-default-excludes-container">
                            Purge all cache every day at <input type="time" class="wps-scheduled-purge" style="max-height:30px;margin-left:5px;margin-right:5px;">
                            <p> (Current server time is <?php echo date_i18n('H:i'); ?>)</p>
                        </div>

                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
            </form>
        </div>

    </div>
</div>
