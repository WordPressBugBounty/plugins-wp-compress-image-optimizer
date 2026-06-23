<?php

$support_url = function_exists('wpc_get_whitelabel_url') ? wpc_get_whitelabel_url() : 'https://www.wpcompress.com/';

$warn_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

$error_template = function($id, $title, $desc = '') use ($warn_icon, $support_url) {
    $html = '<div id="' . esc_attr($id) . '" style="display:none;">
      <div class="wpc-error-popup">
        <div class="wpc-error-popup-icon">' . $warn_icon . '</div>
        <h3>' . esc_html($title) . '</h3>';
    if ($desc) {
        $html .= '<p>' . esc_html($desc) . '</p>';
    }
    $html .= '<a href="' . esc_url($support_url) . '" target="_blank" class="wpc-error-popup-btn">' . esc_html__('Contact Support', WPS_IC_TEXTDOMAIN) . '</a>
      </div>
    </div>';
    echo $html;
};

$error_template('performance-lab-compatibility',
    __('Compatibility Error', WPS_IC_TEXTDOMAIN),
    __('This feature is not compatible with Performance Lab\'s WebP Uploads option. Please disable it to continue.', WPS_IC_TEXTDOMAIN)
);

$error_template('unknown-error',
    __('Something went wrong', WPS_IC_TEXTDOMAIN),
    __('An unexpected error occurred. Please try again or contact support if the issue persists.', WPS_IC_TEXTDOMAIN)
);

$error_template('unable-to-contact-api',
    __('Unable to reach the optimization service', WPS_IC_TEXTDOMAIN),
    __('The request timed out. This can happen with multiple simultaneous requests. Please try again.', WPS_IC_TEXTDOMAIN)
);

$error_template('failed-to-get-site-images',
    __('Unable to communicate with your site', WPS_IC_TEXTDOMAIN),
    __('We couldn\'t retrieve your image list. Please check your site\'s connectivity and try again.', WPS_IC_TEXTDOMAIN)
);

$error_template('bulk-process-failed',
    __('Bulk process failed to start', WPS_IC_TEXTDOMAIN),
    __('The bulk optimization couldn\'t be started. Please try again or use the single-image optimizer.', WPS_IC_TEXTDOMAIN)
);

$error_template('bulk-process-bad-apikey',
    __('Invalid API Key', WPS_IC_TEXTDOMAIN),
    __('Your API key could not be verified. Please reconnect the plugin or contact support.', WPS_IC_TEXTDOMAIN)
);
