<?php
global $wps_ic, $wpdb;
$gui = new wpc_gui_v4();
?>

<div class="wpc-tab-content-box" style="">
    <?php
    echo $gui::checkboxTabTitle('Font Configuration', 'Tailor font settings.', 'tab-icons/ux-settings.svg', '', ''); ?>
    <div class="wpc-spacer"></div>

    <div class="wpc-items-list-row mb-20">

        <?php
        echo $gui::buttonAction('Purge & Rescan Font Cache', 'Purge Cache of found fonts & Rescan the Home Page.', 'Purge', '', admin_url('options-general.php?page=wpcompress&purgeFontCache=true')); ?>

        <?php
        echo $gui::dropdown('replace-fonts', 'Replace with locally hosted fonts', 'Serve fonts from your server.', array('off' => 'Off', 'local' => 'Local Fonts')); ?>

    </div>
</div>

<div class="wpc-tab-content-box" style="">
    <?php
    echo $gui::checkboxTabTitle('Font Scan', 'Insert URL of page which you wish to scan for fonts.', 'tab-icons/ux-settings.svg', '', ''); ?>
    <div class="wpc-spacer"></div>

    <div class="wpc-items-list-row mb-20">

        <form method="POST" action="<?php echo admin_url('options-general.php?page=wpcompress#scan-fonts'); ?>">
            <input type="text" name="scanUrl" value="" size="64"/>
            <input type="hidden" name="page" value="wpcompress"/>
            <input type="hidden" name="wpc_settings_save_nonce" value="<?php echo wp_create_nonce('wpc_settings_save'); ?>"/>
            <input type="submit" name="submit" value="scan"/>
        </form>
        <?php

        if (!empty($_GET['purgeFontCache'])) {
            delete_option(WPS_IC_FONTS_MAP);

            // Scan Home Page
            $fonts = new wps_ic_fonts();
            $response = $fonts->callAPI(site_url());
            $found = $fonts->scanForFonts($response);

            if (!empty($found)) {
                $findFontLinks = $fonts->readGoogleStylesheet($found);
            }

            wp_safe_redirect(admin_url('options-general.php?page=wpcompress#scan-fonts'));
            die();
        }

        if (!empty($_POST['scanUrl'])) {
            $site = sanitize_url($_POST['scanUrl']);

            $fonts = new wps_ic_fonts();
            $response = $fonts->callAPI($site);

            $found = $fonts->scanForFonts($response);

            if (!empty($found)) {
                $findFontLinks = $fonts->readGoogleStylesheet($found);
            }

        }

        ?>

    </div>
</div>

<div class="wpc-tab-content-box" style="">
    <div class="wpc-fonts-status" style="display:flex;align-items:center;border:none;">

        <div class="d-flex align-items-top gap-3 tab-title-checkbox" style="width:100%; padding-right:20px">
            <div class="wpc-checkbox-description" style="z-index:2">
                <div style="display:flex">
                    <h4 class="fs-500 text-dark-300 fw-500 p-inline wpc-smart-fonts-title">Found Google Fonts</h4>
                </div>
                <p class="wpc-smart-fonts-text" style="margin: 7px 0px 4px">We have found the following fonts on your site.</p>
            </div>

        </div>
    </div>
    <div class="wpc-spacer"></div>
    <div class="wpc-items-list-row mb-20">
        <?php
        $fonts = new wps_ic_fonts();
        $listFonts = $fonts->listFoundFonts();
        if (!empty($listFonts)) {
            foreach ($listFonts as $foundFont => $savedLocally) {
                ?>
                <div class="wpc-dropdown-row" data-font-row="<?php echo $foundFont; ?>">
                    <div class="wpc-dropdown-row-header">
                        <div class="wpc-dropdown-row-left-side" style="max-width: 65%;">
                            <div class="wpc-circle-status">
                                <div class="wpc-critical-circle wpc-done"></div>
                            </div>
                            <?php echo $foundFont; ?>
                        </div>
                        <div class="wpc-dropdown-row-right-side">
                            <a href="#" class="wpc-remove-fonts" data-font-id="<?php echo $foundFont; ?>">Remove</a>
                        </div>
                    </div>
                </div>
                <?php
            }
        }
        ?>
    </div>
</div>