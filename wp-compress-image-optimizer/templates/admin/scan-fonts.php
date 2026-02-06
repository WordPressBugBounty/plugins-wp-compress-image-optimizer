<?php
global $wps_ic, $wpdb;
?>
<table id="information-table" class="wp-list-table widefat fixed striped posts">
    <thead>
    </thead>
    <tbody>
    <tr>
        <td>Scan fonts</td>
        <td colspan="3">
            <form method="GET" action="<?php echo admin_url('options-general.php?page=wpcompress#scan-fonts'); ?>">
                <input type="text" name="scanUrl" value="" size="64"/>
                <input type="hidden" name="page" value="wpcompress"/>
                <input type="submit" name="submit" value="scan"/>
            </form>
            <?php

            if (!empty($_GET['showFonts'])) {
                var_dump(get_option(WPS_IC_FONTS_MAP));
            } else {
                if (!empty($_GET['scanUrl'])) {
                    $site = $_GET['scanUrl'];


                    $fonts = new wps_ic_fonts();
                    $response = $fonts->callAPI($site);

                    $found = $fonts->scanForFonts($response);
                    var_dump($found);

                    if (!empty($found)) {

                        $findFontLinks = $fonts->readGoogleStylesheet($found);
                        var_dump($findFontLinks);

                    }
                }
            }

            ?>
        </td>
    </tr>
    </tbody>
</table>

<div class="wpc-tab-content-box" style="margin-top:20px;">
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
                <div class="wpc-dropdown-row">
                    <div class="wpc-dropdown-row-header">
                        <div class="wpc-dropdown-row-left-side" style="max-width: 65%;">
                            <div class="wpc-circle-status">
                                <div class="wpc-critical-circle wpc-done"></div>
                            </div>
                            <?php echo $foundFont; ?>
                        </div>
                        <div class="wpc-dropdown-row-right-side">
                            <a href="#">Remove</a>
                        </div>
                    </div>
                </div>
        <?php
            }
        }
        ?>
    </div>
</div>