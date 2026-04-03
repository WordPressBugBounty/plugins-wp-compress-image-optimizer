<?php
global $wps_ic, $wpdb;
$gui = new wpc_gui_v4();
$fontSettings = get_option(WPS_IC_SETTINGS);
$fontsMode = !empty($fontSettings['replace-fonts']) ? $fontSettings['replace-fonts'] : 'off';

// Handle purge font cache before any output
if (!empty($_GET['purgeFontCache'])) {
    delete_option(WPS_IC_FONTS_MAP);

    $fonts = new wps_ic_fonts();
    $response = $fonts->callAPI(site_url());
    $found = $fonts->scanForFonts($response);

    $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);
    if ($hasGoogleFonts) {
        $fonts->readGoogleStylesheet($found);
    }

    echo '<script>window.location.href = "' . esc_url(admin_url('options-general.php?page=' . $wps_ic::$slug . '#scan-fonts')) . '";</script>';
    die();
}
?>

<!-- Section 1: Font Display -->
<div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="font-display-settings">
    <?php
    echo $gui::checkboxTabTitle(esc_html__('Font Display', WPS_IC_TEXTDOMAIN), esc_html__('Controls how fonts render while loading. Swap shows text immediately using a fallback font — recommended for PageSpeed.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path fill="currentColor" d="M24 32l-24 0 0 128 48 0 0-80 88 0 0 352-72 0 0 48 192 0 0-48-72 0 0-352 88 0 0 80 48 0 0-128-296 0zM280 224l-24 0 0 128 48 0 0-80 88 0 0 160-72 0 0 48 192 0 0-48-72 0 0-160 88 0 0 80 48 0 0-128-296 0z"/></svg>', '', ''); ?>

    <div class="wpc-perf-grid">
        <?php echo $gui::dropdown('font-display', esc_html__('Text Font Display', WPS_IC_TEXTDOMAIN), esc_html__('How browsers handle text font loading. Swap shows fallback text immediately — fixes the PageSpeed font-display warning. Icon fonts automatically use a separate display setting to prevent garbled characters — configure in Icon Fonts below.', WPS_IC_TEXTDOMAIN), array(
            'swap'     => esc_html__('Swap', WPS_IC_TEXTDOMAIN),
            'off'      => esc_html__('Off', WPS_IC_TEXTDOMAIN),
            'auto'     => esc_html__('Auto (Browser Default)', WPS_IC_TEXTDOMAIN),
            'block'    => esc_html__('Block (FOIT)', WPS_IC_TEXTDOMAIN),
            'fallback' => esc_html__('Fallback', WPS_IC_TEXTDOMAIN),
            'optional' => esc_html__('Optional', WPS_IC_TEXTDOMAIN),
        ), 'swap');

        echo $gui::checkboxDescription_v4(
            esc_html__('Preload Critical Fonts', WPS_IC_TEXTDOMAIN),
            esc_html__('Preload above-the-fold fonts for faster first paint. Limited to 4 to avoid bloat. Requires Critical CSS.', WPS_IC_TEXTDOMAIN),
            false, '0', 'preload-crit-fonts', false, 'right', ''
        ); ?>
    </div>
</div>

<!-- Section 2: Icon Fonts -->
<div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="icon-font-settings">
    <?php
    echo $gui::checkboxTabTitle(esc_html__('Icon Fonts', WPS_IC_TEXTDOMAIN), esc_html__('Optimize loading of icon font libraries like Font Awesome, Material Icons, and other icon sets.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M91.7 96C106.3 86.8 116 70.5 116 52 116 23.3 92.7 0 64 0S12 23.3 12 52c0 16.7 7.8 31.5 20 41l0 419 48 0 0-64 416 0 0-32-7.1-16-56.9-128 56.9-128 7.1-16 0-32-404.3 0zM80 400l0-256 356.4 0-48.2 108.5-8.7 19.5 8.7 19.5 48.2 108.5-356.4 0z"/></svg>', '', ''); ?>

    <div class="wpc-perf-grid wpc-perf-grid-single">
        <?php
        echo $gui::dropdown('icon-font-display', esc_html__('Icon Font Display', WPS_IC_TEXTDOMAIN), esc_html__('How browsers handle icon font loading. Block (recommended) keeps icons invisible until loaded — prevents garbled characters that appear with swap. Override this if you prefer a different strategy.', WPS_IC_TEXTDOMAIN), array(
            'block'    => esc_html__('Block', WPS_IC_TEXTDOMAIN),
            'swap'     => esc_html__('Swap', WPS_IC_TEXTDOMAIN),
            'auto'     => esc_html__('Auto (Browser Default)', WPS_IC_TEXTDOMAIN),
            'fallback' => esc_html__('Fallback', WPS_IC_TEXTDOMAIN),
            'optional' => esc_html__('Optional', WPS_IC_TEXTDOMAIN),
        ), 'block');
        ?>
    </div>

    <div class="wpc-perf-grid">
        <?php
        echo $gui::checkboxDescription_v4(esc_html__('Lazy Load Icon Fonts', WPS_IC_TEXTDOMAIN), esc_html__('Load icon font stylesheets asynchronously to prevent render-blocking. Icons appear after a brief invisible flash instead of blocking page paint.', WPS_IC_TEXTDOMAIN), false, '0', 'fontawesome-lazy', false, 'right', '');

        echo $gui::checkboxDescription_v4(esc_html__('Remove Duplicate Icon Fonts', WPS_IC_TEXTDOMAIN), esc_html__('Detect and remove duplicate Font Awesome stylesheets when multiple versions are loaded by different plugins.', WPS_IC_TEXTDOMAIN), false, '0', 'remove-duplicated-fontawesome', false, 'right', '');
        ?>
    </div>
</div>

<!-- Section 3: Google Fonts -->
<div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="font-configuration">
    <?php
    echo $gui::checkboxTabTitle(esc_html__('Google Fonts', WPS_IC_TEXTDOMAIN), esc_html__('Control how Google Fonts are loaded. Self-host for faster load times, better privacy and GDPR compliance.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M500 261.8C500 403.3 403.1 504 260 504 122.8 504 12 393.2 12 256S122.8 8 260 8c66.8 0 123 24.5 166.3 64.9l-67.5 64.9c-88.3-85.2-252.5-21.2-252.5 118.2 0 86.5 69.1 156.6 153.7 156.6 98.2 0 135-70.4 140.8-106.9l-140.8 0 0-85.3 236.1 0c2.3 12.7 3.9 24.9 3.9 41.4z"/></svg>', '', ''); ?>

    <div class="wpc-perf-grid">
        <?php
        echo $gui::dropdown('replace-fonts', esc_html__('Self-Host Google Fonts', WPS_IC_TEXTDOMAIN), esc_html__('Off: Fonts load from Google servers — may share visitor data with Google. Local: Downloads and serves fonts from your own server for full GDPR compliance. Bunny Fonts (Recommended): Free, privacy-first Google Fonts alternative powered by bunny.net global CDN — lightning-fast delivery, zero tracking, fully GDPR compliant.', WPS_IC_TEXTDOMAIN), array(
            'off'   => esc_html__('Off', WPS_IC_TEXTDOMAIN),
            'local' => esc_html__('Local Fonts (GDPR)', WPS_IC_TEXTDOMAIN),
            'bunny' => esc_html__('Bunny Fonts (GDPR + CDN)', WPS_IC_TEXTDOMAIN),
        ));

        echo $gui::buttonAction(esc_html__('Purge & Rescan Font Cache', WPS_IC_TEXTDOMAIN), esc_html__('Clear the font cache and re-scan your homepage to detect newly added or removed fonts.', WPS_IC_TEXTDOMAIN), esc_html__('Purge', WPS_IC_TEXTDOMAIN), '', admin_url('options-general.php?page=' . $wps_ic::$slug . '&purgeFontCache=true'));
        ?>
    </div>
</div>

<!-- Section 4: Google Fonts Scanner -->
<div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="font-scan">
    <?php
    echo $gui::checkboxTabTitle(esc_html__('Google Fonts Scanner', WPS_IC_TEXTDOMAIN), esc_html__('Scan a page to detect and download Google Fonts for self-hosting. Most sites only need the homepage scanned.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path fill="currentColor" d="M400.4 208a160 160 0 1 0 -320 0 160 160 0 1 0 320 0zM369.6 371.1c-35.5 28.1-80.3 44.9-129.1 44.9-114.9 0-208-93.1-208-208s93.1-208 208-208 208 93.1 208 208c0 48.8-16.8 93.7-44.9 129.1l133.9 133.9 17 17-33.9 33.9-17-17-133.9-133.9z"/></svg>', '', ''); ?>

    <div class="wpc-font-scan-form">
        <div class="wpc-scan-input-group">
            <input type="text" name="scanUrl" id="wpc-scan-url" value="<?php echo esc_url(site_url()); ?>" placeholder="<?php echo esc_attr__('https://yoursite.com/page-to-scan', WPS_IC_TEXTDOMAIN); ?>" />
            <button type="button" class="wpc-scan-btn" id="wpc-scan-trigger">
                <svg class="wpc-scan-icon" width="16" height="16" viewBox="0 0 512 512" fill="currentColor"><path d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376c-34.4 25.2-76.8 40-122.7 40C93.1 416 0 322.9 0 208S93.1 0 208 0S416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"/></svg>
                <span class="wpc-scan-label"><?php echo esc_html__('Scan', WPS_IC_TEXTDOMAIN); ?></span>
            </button>
        </div>
        <?php if (!empty($_GET['fontScanResult']) && $_GET['fontScanResult'] === 'found') { ?>
            <div class="wpc-scan-result wpc-scan-result-found">
                <svg width="14" height="14" viewBox="0 0 512 512" fill="#22b73a"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209L241 337c-9.4 9.4-24.6 9.4-33.9 0l-64-64c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l47 47L335 175c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9z"/></svg>
                <?php echo esc_html__('Google Fonts detected! See results below.', WPS_IC_TEXTDOMAIN); ?>
            </div>
            <script>setTimeout(function(){ document.querySelector('.wpc-scan-result-found').style.opacity='0'; setTimeout(function(){ var el = document.querySelector('.wpc-scan-result-found'); if(el) el.remove(); }, 400); }, 5000);</script>
        <?php } ?>
    </div>
</div>

<!-- Section 5: Detected Google Fonts -->
<?php
$fonts = new wps_ic_fonts();
$listFonts = $fonts->listFoundFonts();
if (!empty($listFonts)) {
?>
<div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="found-fonts">
    <div class="tab-title-checkbox">
        <div class="wpc-checkbox-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path fill="currentColor" d="M172.1 69.8l13.8-19.7-39.3-27.5c-4.9 7-22.7 32.4-53.4 76.2-13.6-13.6-25.9-25.9-36.7-36.7l-33.9 33.9 57 57 20.2 20.2c4.3-6.2 28.5-40.7 72.4-103.4zm0 160l13.8-19.7-39.3-27.5c-4.9 7-22.7 32.4-53.4 76.2-13.6-13.6-25.9-25.9-36.7-36.7L22.5 256c7.5 7.5 26.5 26.5 57 57l20.2 20.2c4.3-6.2 28.5-40.7 72.4-103.4zM224.5 72l0 48 320 0 0-48-320 0zm0 160l0 48 320 0 0-48-320 0zm-32 160l0 48 352 0 0-48-352 0zm-64 24a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z"/></svg>
        </div>
        <div class="wpc-checkbox-description">
            <h4><?php echo esc_html__('Detected Google Fonts', WPS_IC_TEXTDOMAIN); ?></h4>
            <p><?php echo esc_html__('These are the fonts and weights detected on your site.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>
    </div>

    <div class="wpc-font-list">
        <?php
        foreach ($listFonts as $foundFont => $savedLocally) {
            $fontName = $foundFont;
            $weights = [];
            $decoded = urldecode($foundFont);

            if (preg_match('/family=([^&]+)/', $decoded, $m)) {
                $familyPart = str_replace('+', ' ', $m[1]);
                if (strpos($familyPart, ':') !== false) {
                    list($fontName, $weightStr) = explode(':', $familyPart, 2);
                    $weights = explode(',', $weightStr);
                } else {
                    $fontName = $familyPart;
                }
            } elseif (strpos($decoded, ':') !== false) {
                list($fontName, $weightStr) = explode(':', $decoded, 2);
                $weights = explode(',', $weightStr);
            }

            $weights = array_map(function($w) { return str_replace('italic', 'i', trim($w)); }, $weights);
            $weights = array_filter($weights);
            ?>
            <div class="wpc-font-item" data-font-row="<?php echo esc_attr($foundFont); ?>">
                <div class="wpc-font-item-name">
                    <div class="circle-check active"></div>
                    <span class="wpc-font-name"><?php echo esc_html($fontName); ?></span>
                    <?php if (!empty($weights)) : ?>
                    <div class="wpc-font-weights">
                        <?php foreach ($weights as $w) : ?>
                        <span class="wpc-font-weight-chip"><?php echo esc_html($w); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <a href="#" class="wpc-remove-fonts wpc-font-remove-btn" data-font-id="<?php echo esc_attr($foundFont); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <?php echo esc_html__('Remove', WPS_IC_TEXTDOMAIN); ?>
                </a>
            </div>
            <?php
        }
        ?>
    </div>
</div>
<?php } // end if listFonts ?>
