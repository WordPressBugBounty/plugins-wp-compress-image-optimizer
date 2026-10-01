<?php
/**
 * The missing-files view (options-general.php?page=wpcompress&view=missing-files): one row per
 * image the bulk run leaves out because neither its file nor its unscaled original is on disk
 * (wps_ic_image_library::missing_ids(), the same images the "no file on disk" warning counts).
 * Read-only. For each: title, file, upload date, whether WP Compress deleted a file of it
 * (wps_ic_image_library::file_deleted()), whether a backup copy is on this site, and the links
 * to restore or edit it. Only for a user who can manage options.
 * No "missing since" column: the stamp (wps_ic_image_library::seen_source(), meta
 * wpc_source_missing_since) starts at the first run on this version, so on a site upgrading today
 * every row read today's date and told the admin nothing. The stamp stays for the image doctor.
 */

if (!current_user_can('manage_options')) {
    return;
}

global $wps_ic;

$wpc_missing_ids = wps_ic_image_library::missing_ids();
$wpc_missing_cap = 1000;
$wpc_missing_date = function ($ts) {
    $ts = (int) $ts;
    return function_exists('wp_date') ? wp_date('Y-m-d H:i', $ts) : gmdate('Y-m-d H:i', $ts) . ' UTC';
};
$wpc_bulk_url = admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk');
?>
<div class="wrap wpc-missing-files">
    <h1><?php esc_html_e('Images with no file on disk', WPS_IC_TEXTDOMAIN); ?></h1>
    <p><?php esc_html_e('These media library entries point to files that are not on the server. WP Compress skips them; it never counts them as failures. You can delete the entries or re-upload the files.', WPS_IC_TEXTDOMAIN); ?></p>
    <p class="description"><?php esc_html_e('WP Compress records every file of an image it deletes, from this release on. "No — no deletion recorded" means no deletion by WP Compress was recorded; a file that went missing before this release has no record either way.', WPS_IC_TEXTDOMAIN); ?></p>
    <p><a href="<?php echo esc_url($wpc_bulk_url); ?>">&larr; <?php esc_html_e('Back to Bulk Optimization', WPS_IC_TEXTDOMAIN); ?></a></p>

    <?php if (empty($wpc_missing_ids)) { ?>
        <p><?php esc_html_e('Every image in the media library has its file on disk.', WPS_IC_TEXTDOMAIN); ?></p>
    <?php } else { ?>
        <table class="widefat striped wpc-missing-files-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Title', WPS_IC_TEXTDOMAIN); ?></th>
                <th><?php esc_html_e('File', WPS_IC_TEXTDOMAIN); ?></th>
                <th><?php esc_html_e('Uploaded', WPS_IC_TEXTDOMAIN); ?></th>
                <th><?php esc_html_e('Removed by WP Compress', WPS_IC_TEXTDOMAIN); ?></th>
                <th><?php esc_html_e('Backup copy', WPS_IC_TEXTDOMAIN); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($wpc_missing_ids, 0, $wpc_missing_cap) as $wpc_id) {
                $wpc_post = get_post($wpc_id);
                $wpc_file = (string) get_post_meta($wpc_id, '_wp_attached_file', true);
                $wpc_log = get_post_meta($wpc_id, wps_ic_image_library::DELETED_LOG, true);
                $wpc_last = is_array($wpc_log) && !empty($wpc_log) ? end($wpc_log) : null;
                $wpc_backup = wps_ic_image_doctor::backup($wpc_id);
                $wpc_title = is_object($wpc_post) && (string) $wpc_post->post_title !== '' ? (string) $wpc_post->post_title : sprintf(__('Image #%d', WPS_IC_TEXTDOMAIN), $wpc_id);
                $wpc_edit = admin_url('post.php?post=' . (int) $wpc_id . '&action=edit');
                ?>
                <tr data-id="<?php echo (int) $wpc_id; ?>">
                    <td><?php echo esc_html($wpc_title); ?></td>
                    <td><code><?php echo esc_html($wpc_file); ?></code></td>
                    <td><?php echo is_object($wpc_post) && !empty($wpc_post->post_date) ? esc_html(substr((string) $wpc_post->post_date, 0, 16)) : '&mdash;'; ?></td>
                    <td><?php
                        if (is_array($wpc_last)) {
                            echo esc_html(sprintf(__('Yes, %1$s, %2$s', WPS_IC_TEXTDOMAIN), $wpc_missing_date($wpc_last['ts'] ?? 0), (string) ($wpc_last['op'] ?? '')));
                        } else {
                            esc_html_e('No — no deletion recorded', WPS_IC_TEXTDOMAIN);
                        } ?></td>
                    <td><?php
                        if (!empty($wpc_backup['exists'])) {
                            echo esc_html__('Yes', WPS_IC_TEXTDOMAIN) . ' &mdash; <a href="' . esc_url(admin_url('upload.php?mode=list&s=' . rawurlencode(pathinfo($wpc_file, PATHINFO_FILENAME)))) . '">' . esc_html__('Restore', WPS_IC_TEXTDOMAIN) . '</a>';
                        } else {
                            esc_html_e('No', WPS_IC_TEXTDOMAIN);
                        } ?></td>
                    <td><a href="<?php echo esc_url($wpc_edit); ?>"><?php esc_html_e('Edit', WPS_IC_TEXTDOMAIN); ?></a></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if (count($wpc_missing_ids) > $wpc_missing_cap) { ?>
            <p><?php echo esc_html(sprintf(__('Showing the first %1$d of %2$d.', WPS_IC_TEXTDOMAIN), $wpc_missing_cap, count($wpc_missing_ids))); ?></p>
        <?php } ?>
    <?php } ?>
</div>
