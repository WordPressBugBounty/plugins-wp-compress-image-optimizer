<?php

global $whtlbl;
$support_url = (isset($whtlbl) && property_exists($whtlbl, 'author_url'))
	? $whtlbl->author_url
	: 'https://www.wpcompress.com/';

echo '<div id="performance-lab-compatibility" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>' . esc_html__('Compatibility Error!', WPS_IC_TEXTDOMAIN) . '</h3>
          <p>' . esc_html__('This feature is not compatible with Performance Lab\'s WebP Uploads option. Please disable it to use this feature.', WPS_IC_TEXTDOMAIN) . '</p>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
        </div>

      </div>
    </div>';

echo '<div id="unknown-error" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>' . esc_html__('Unknown error occurred!', WPS_IC_TEXTDOMAIN) . '</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
        </div>

      </div>
    </div>';

echo '<div id="unable-to-contact-api" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>' . esc_html__('We were unable to contact WP Compress API!', WPS_IC_TEXTDOMAIN) . '</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
        </div>

      </div>
    </div>';

echo '<div id="failed-to-get-site-images" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>' . esc_html__('We were unable to communicate with your site!', WPS_IC_TEXTDOMAIN) . '</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
        </div>

      </div>
    </div>';

echo '<div id="bulk-process-failed" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>' . esc_html__('We were unable to start bulk process on your site!', WPS_IC_TEXTDOMAIN) . '</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
        </div>

      </div>
    </div>';

echo '<div id="bulk-process-bad-apikey" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>' . esc_html__('We were unable to start bulk process on your site because of Bad ApiKey!', WPS_IC_TEXTDOMAIN) . '</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
        </div>

      </div>
    </div>';