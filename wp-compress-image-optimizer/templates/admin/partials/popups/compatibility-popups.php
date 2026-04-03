<div id="lazy-compatibility-popup" style="display: none;">
    <div id="cdn-popup-inner" class="ic-compress-all-popup ic-lazy-compatibility-popup">

        <div class="cdn-popup-top">
            <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/images/compatibility.svg"/>
        </div>

        <div class="cdn-popup-content">
            <h3><?php echo esc_html__('Please Confirm Compatibility', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('Advanced features such as serving images with lazy load may conflict with your active themes, plugins or environment. If any issues occur after activating, you can simply toggle it off.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

    </div>
</div>

<div id="hide_compress-popup" style="display: none;">
    <div class="wpc-saas-popup">

        <div class="wpc-saas-popup-icon">
            <img src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/compatibility-icon.svg" alt="" width="32" height="32"/>
        </div>

        <h3><?php echo esc_html__('Hide Plugin from Admin Area', WPS_IC_TEXTDOMAIN); ?></h3>
        <p><?php echo esc_html__('This will remove WP Compress from the WordPress admin menu. To restore access, add the following query parameter to any wp-admin URL:', WPS_IC_TEXTDOMAIN); ?></p>

        <div class="wpc-copy-chip" onclick="(function(el){var t=document.createElement('textarea');t.value='?show_optimizer=true';t.style.position='absolute';t.style.left='-9999px';document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);el.classList.add('copied');setTimeout(function(){el.classList.remove('copied')},2000)})(this)">
            <code>?show_optimizer=true</code>
            <span class="wpc-copy-chip-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                </svg>
            </span>
            <span class="wpc-copy-chip-feedback"><?php echo esc_html__('Copied!', WPS_IC_TEXTDOMAIN); ?></span>
        </div>

    </div>
</div>

<div id="generate_adaptive-compatibility-popup" style="display: none;">
    <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
            <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/images/compatibility.svg"/>
        </div>

        <div class="cdn-popup-content">
            <h3><?php echo esc_html__('Please Confirm Compatibility', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('Advanced features such as serving Adaptive Images may conflict with your active themes, plugins or environment. If any issues occur after activating, you can simply toggle it off.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

    </div>
</div>

<div id="delay-js-popup" style="display: none;">
    <div id="cdn-popup-inner" class="wpc-compatibility-popup">

        <div class="cdn-popup-top-left">
            <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/compatibility-icon.svg"/>
        </div>

        <div class="cdn-popup-content">
            <h3><?php echo esc_html__('Delaying the Javascript of certain theme or plugin files may break the functionality or design on your site.', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('If any issues occur, simply deactivating this setting will return your site to normal.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

        <div class="cdn-popup-buttons">
            <button class="wpc-get-support wpc-popup-cancel">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <?php echo esc_html__('Get Expert Support', WPS_IC_TEXTDOMAIN); ?>
            </button>
            <button class="wpc-popup-confirm">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
                <?php echo esc_html__('Proceed with Caution', WPS_IC_TEXTDOMAIN); ?>
            </button>
        </div>

    </div>
    <div class="cdn-popup-footer-info">
        <a href="https://help.wpcompress.com/en-us/article/how-can-i-exclude-an-image-asset-or-script-1r1vqwu/" target="_blank">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <?php echo esc_html__('Learn how to exclude problematic theme or plugin files from site optimization', WPS_IC_TEXTDOMAIN); ?>
        </a>
    </div>
</div>

<div id="connectivity-popup" style="display: none;">
    <div id="cdn-popup-inner" class="wpc-compatibility-popup">

        <div class="cdn-popup-top-left">
            <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/compatibility-icon.svg"/>
        </div>

        <div class="cdn-popup-content">
            <h3><?php echo esc_html__('Some features are getting blocked!', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('It seems that parts of the optimization process are currently being blocked by external security measures. This can happen if there are automated traffic protection systems on your website or server.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

        <div class="cdn-popup-buttons">
            <button class="wpc-get-support wpc-popup-cancel">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                </div>
                <?php echo esc_html__('View Guide to Whitelist Services', WPS_IC_TEXTDOMAIN); ?>
            </button>
        </div>

    </div>
</div>

<div id="combine-js-popup" style="display: none;">
    <div id="cdn-popup-inner" class="wpc-compatibility-popup">

        <div class="cdn-popup-top-left">
            <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/compatibility-icon.svg"/>
        </div>

        <div class="cdn-popup-content">
            <h3><?php echo esc_html__('Combining the Javascript of certain theme or plugin files may break the functionality or design on your site.', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('If any issues occur, simply deactivating this setting will return your site to normal.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

        <div class="cdn-popup-buttons">
            <button class="wpc-get-support wpc-popup-cancel">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <?php echo esc_html__('Get Expert Support', WPS_IC_TEXTDOMAIN); ?>
            </button>
            <button class="wpc-popup-confirm">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
                <?php echo esc_html__('Proceed with Caution', WPS_IC_TEXTDOMAIN); ?>
            </button>
        </div>

    </div>
    <div class="cdn-popup-footer-info">
        <a href="https://help.wpcompress.com/en-us/article/how-can-i-exclude-an-image-asset-or-script-1r1vqwu/" target="_blank">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <?php echo esc_html__('Learn how to exclude problematic theme or plugin files from site optimization', WPS_IC_TEXTDOMAIN); ?>
        </a>
    </div>
</div>


<div id="combine-css-popup" style="display: none;">
    <div id="cdn-popup-inner" class="wpc-compatibility-popup">

        <div class="cdn-popup-top-left">
            <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/compatibility-icon.svg"/>
        </div>

        <div class="cdn-popup-content">
            <h3><?php echo esc_html__('Combining the CSS of certain theme or plugin files may break the functionality or design on your site.', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('If any issues occur, simply deactivating this setting will return your site to normal.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>

        <div class="cdn-popup-buttons">
            <button class="wpc-get-support wpc-popup-cancel">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <?php echo esc_html__('Get Expert Support', WPS_IC_TEXTDOMAIN); ?>
            </button>
            <button class="wpc-popup-confirm">
                <div class="wpc-button-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
                <?php echo esc_html__('Proceed with Caution', WPS_IC_TEXTDOMAIN); ?>
            </button>
        </div>

    </div>
    <div class="cdn-popup-footer-info">
        <a href="https://help.wpcompress.com/en-us/article/how-can-i-exclude-an-image-asset-or-script-1r1vqwu/" target="_blank">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <?php echo esc_html__('Learn how to exclude problematic theme or plugin files from site optimization', WPS_IC_TEXTDOMAIN); ?>
        </a>
    </div>
</div>