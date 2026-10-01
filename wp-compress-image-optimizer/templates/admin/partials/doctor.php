<?php
/*
 * The doctor's card in the Debug tab: one card per compartment, filled from admin-ajax wpc_doctor.
 *
 * The Debug tab body renders on every settings page, hidden or not, so this partial prints markup
 * only and reads nothing: no report is asked for until a button is pressed (assets/js/admin/
 * doctor.js). The inputs carry no name, so saving the settings form never submits them. In the
 * agency portal the page is the client site's (view-site/<apikey>): the apikey travels with each
 * request and the ajax handler forwards it to that site.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!function_exists('wpc_doctor_registry')) {
    require_once WPS_IC_DIR . 'addons/doctor/doctor.php';
}

$wpc_doctor_apikey = '';
if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
    if (function_exists('get_query_var')) {
        $wpc_doctor_apikey = (string) sanitize_text_field(get_query_var('apikey'));
    }
    if ($wpc_doctor_apikey === '') {
        global $wps_ic;
        if (!empty($wps_ic) && method_exists($wps_ic, 'extractApiKey')) {
            $wpc_doctor_apikey = (string) $wps_ic->extractApiKey();
        }
    }
}
$wpc_doctor_compartments = [];
foreach (wpc_doctor_registry() as $wpc_doctor_name => $wpc_doctor_class) {
    $wpc_doctor_compartments[$wpc_doctor_name] = ['title' => $wpc_doctor_class::TITLE, 'symptoms' => $wpc_doctor_class::SYMPTOMS];
}
?>
<div class="wpc-tab-content-box wpc-doctor" id="wpc-doctor"
     data-ajaxurl="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
     data-nonce="<?php echo esc_attr(wp_create_nonce('wps_ic_nonce_action')); ?>"
     data-apikey="<?php echo esc_attr($wpc_doctor_apikey); ?>"
     data-host="<?php echo esc_attr((string) wp_parse_url(home_url(), PHP_URL_HOST)); ?>">
    <style>
        .wpc-doctor h3{margin:0 0 4px;font-size:16px}
        .wpc-doctor .wpc-doctor-intro{margin:0 0 12px;color:#555}
        .wpc-doctor .wpc-doctor-controls{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin-bottom:12px}
        .wpc-doctor .wpc-doctor-controls input[type=text]{min-width:260px;max-width:100%}
        .wpc-doctor .wpc-doctor-picks{display:flex;flex-wrap:wrap;gap:4px 14px;margin-bottom:12px}
        .wpc-doctor .wpc-doctor-status{margin:8px 0;color:#555;min-height:1.2em}
        .wpc-doctor .wpc-doctor-card{border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:10px 0;background:#fff}
        .wpc-doctor .wpc-doctor-head{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}
        .wpc-doctor .wpc-doctor-level{font-weight:600;font-size:11px;padding:1px 6px;border-radius:3px;color:#fff;background:#8c8f94}
        .wpc-doctor .wpc-doctor-level.ok{background:#00a32a}.wpc-doctor .wpc-doctor-level.warn{background:#dba617}
        .wpc-doctor .wpc-doctor-level.fail{background:#d63638}
        .wpc-doctor .wpc-doctor-code{color:#777;font-size:12px}
        .wpc-doctor dl{display:grid;grid-template-columns:minmax(120px,max-content) 1fr;gap:2px 12px;margin:8px 0;font-size:12px}
        .wpc-doctor dt{font-weight:600}.wpc-doctor dd{margin:0;overflow-wrap:anywhere}
        .wpc-doctor dd pre,.wpc-doctor details pre{white-space:pre-wrap;margin:0;font-size:11px}
        .wpc-doctor .wpc-doctor-source{color:#888}
        .wpc-doctor details{margin:4px 0;font-size:12px}
    </style>
    <h3><?php esc_html_e('Doctor', 'wp-compress-image-optimizer'); ?></h3>
    <p class="wpc-doctor-intro"><?php esc_html_e('What each part of the plugin decided for a page, and why, read from the state it keeps. Reading writes nothing; nothing is read until you press a button. "Run with probes" also fetches the page as a logged-out visitor, which may store a page copy as a visit would.', 'wp-compress-image-optimizer'); ?></p>
    <?php if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY && $wpc_doctor_apikey === '') { ?>
        <p><?php esc_html_e('Open a site to run the doctor for it.', 'wp-compress-image-optimizer'); ?></p>
    <?php } else { ?>
        <div class="wpc-doctor-controls">
            <label><?php esc_html_e('Page', 'wp-compress-image-optimizer'); ?>
                <input type="text" class="wpc-doctor-url" placeholder="<?php esc_attr_e('the homepage, or a path such as /about/', 'wp-compress-image-optimizer'); ?>" autocomplete="off">
            </label>
            <button type="button" class="button wpc-doctor-run" data-probe="0"><?php esc_html_e('Run', 'wp-compress-image-optimizer'); ?></button>
            <button type="button" class="button wpc-doctor-run" data-probe="1"><?php esc_html_e('Run with probes', 'wp-compress-image-optimizer'); ?></button>
            <button type="button" class="button wpc-doctor-bundle"><?php esc_html_e('Download ticket bundle (24 h)', 'wp-compress-image-optimizer'); ?></button>
        </div>
        <div class="wpc-doctor-picks">
            <?php foreach ($wpc_doctor_compartments as $wpc_doctor_name => $wpc_doctor_card) { ?>
                <label title="<?php echo esc_attr($wpc_doctor_card['symptoms']); ?>">
                    <input type="checkbox" class="wpc-doctor-pick" value="<?php echo esc_attr($wpc_doctor_name); ?>" checked>
                    <?php echo esc_html($wpc_doctor_card['title']); ?>
                </label>
            <?php } ?>
        </div>
        <div class="wpc-doctor-status" aria-live="polite"></div>
        <div class="wpc-doctor-cards"></div>
    <?php } ?>
</div>
<script src="<?php echo esc_url(WPS_IC_URI . 'assets/js/admin/doctor.js?ver=' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '')); ?>" defer></script>
