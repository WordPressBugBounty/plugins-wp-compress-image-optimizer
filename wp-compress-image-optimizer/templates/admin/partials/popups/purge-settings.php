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
                        <h3><?php echo esc_html__('Cache Purge Settings', WPS_IC_TEXTDOMAIN); ?></h3>
                        <p><?php echo esc_html__('Fine tune the purging of cache files.', WPS_IC_TEXTDOMAIN); ?></p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">

                        <h4 class="wpc-section-header wpc-section-header-centered"><?php echo esc_html__('Rules for Post Publish/Update', WPS_IC_TEXTDOMAIN); ?></h4>

                        <div class="wpc-checkbox-grid">
                            <label class="wpc-checkbox-card">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-all-pages">
                                <p><?php echo esc_html__('All Pages', WPS_IC_TEXTDOMAIN); ?></p>
                            </label>
                            <label class="wpc-checkbox-card">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-home-page">
                                <p><?php echo esc_html__('Home Page', WPS_IC_TEXTDOMAIN); ?></p>
                            </label>
                            <label class="wpc-checkbox-card">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-recent-posts-widget">
                                <p><?php echo esc_html__('Pages with Recent Posts Widget', WPS_IC_TEXTDOMAIN); ?></p>
                            </label>
                            <label class="wpc-checkbox-card">
                                <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-archive-pages">
                                <p><?php echo esc_html__('Archive Pages', WPS_IC_TEXTDOMAIN); ?></p>
                            </label>
                        </div>

                        <hr class="wpc-section-divider">

                        <div class="wpc-section-header-split">
                            <h4 class="wpc-section-header"><?php echo esc_html__('List of hooks to purge all pages', WPS_IC_TEXTDOMAIN); ?></h4>
                            <h4 class="wpc-section-header"><?php echo esc_html__('Defaults', WPS_IC_TEXTDOMAIN); ?></h4>
                        </div>

                        <div class="wpc-hooks-container">
                            <div class="wpc-hooks-textarea-wrap">
                                <textarea name="wpc-purge-hooks" class="hooks-list-textarea-value" spellcheck="false"></textarea>
                            </div>
                            <div class="wpc-hooks-defaults-wrap">
                                <button type="button" class="wpc-copy-defaults-btn" title="<?php echo esc_attr__('Copy all defaults', WPS_IC_TEXTDOMAIN); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    <span><?php echo esc_html__('Copy all', WPS_IC_TEXTDOMAIN); ?></span>
                                </button>
                                <div class="wpc-hooks-defaults-box">switch_theme
add_link
edit_link
delete_link
update_option_sidebars_widgets
update_option_category_base
update_option_tag_base
wp_update_nav_menu
permalink_structure_changed
customize_save
<?php echo 'update_option_theme_mods_' . get_option('stylesheet') . "\n"; ?>elementor/core/files/clear_cache
uagb_delete_uag_asset_dir
uagb_delete_page_assets
et_core_static_resources_removed
fl_builder_cache_cleared
bricks/settings/after_save</div>
                            </div>
                        </div>

                        <hr class="wpc-section-divider">
                        <h4 class="wpc-section-header wpc-section-header-centered"><?php echo esc_html__('Scheduled Purge', WPS_IC_TEXTDOMAIN); ?></h4>

                        <div class="wpc-scheduled-purge-box">
                            <span><?php echo esc_html__('Purge all cache every day at', WPS_IC_TEXTDOMAIN); ?></span>
                            <select class="wps-scheduled-purge wpc-time-select">
                                <option value=""><?php echo esc_html__('Disabled', WPS_IC_TEXTDOMAIN); ?></option>
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?php echo sprintf('%02d:00', $h); ?>"><?php echo date('g:00 A', mktime($h, 0)); ?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="wpc-server-time"><?php echo esc_html__('Your time:', WPS_IC_TEXTDOMAIN); ?> <span class="wpc-local-time"></span></span>
                        </div>

                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
            </form>
        </div>

    </div>
</div>
