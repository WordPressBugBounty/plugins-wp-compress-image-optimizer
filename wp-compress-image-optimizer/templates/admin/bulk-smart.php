<?php

$wpc_sd_paused = wpc_sd_state() === 'paused';
$wpc_sd_data = wpc_sd_page_data();
$wpc_sd_totals = $wpc_sd_data['totals'];
$wpc_sd_rows = $wpc_sd_data['rows'];
$wpc_sd_hero = $wpc_sd_data['hero'];
$wpc_sd_now = time();
$wpc_sd_restorable = (int) $compressedImages;
$wpc_sd_remaining = function_exists('wpc_restore_all_remaining') ? wpc_restore_all_remaining() : 0;
$wpc_sd_logged_in_bypass = (bool) apply_filters('wpc_logged_in_bypass', true);
$wpc_sd_has_data = $wpc_sd_rows || (int) $wpc_sd_totals['images'] > 0;
$wpc_sd_first = !$wpc_sd_paused && !$wpc_sd_has_data;
$wpc_sd_stale = !empty($wpc_sd_totals['stale']);
$wpc_sd_icons = [
    'bolt' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
    'power' => '<path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/>',
    'fit' => '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>',
    'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
    'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'percent' => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'close' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    'chev' => '<path d="M9 18l6-6-6-6"/>',
    'out' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
];
$wpc_sd_icon = function ($name) use ($wpc_sd_icons) {
    return '<svg class="wpc-sd-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $wpc_sd_icons[$name] . '</svg>';
};
$wpc_sd_benefit_items = function () {
    return [
        ['power', __('Nothing to run, ever', WPS_IC_TEXTDOMAIN), __('No bulk jobs, no pre-generation, no queue. Images optimize themselves the moment visitors see them.', WPS_IC_TEXTDOMAIN)],
        ['fit', __('Pixel-perfect on every screen', WPS_IC_TEXTDOMAIN), __("Every visitor gets an image cut to the exact size it's shown at. No oversized downloads.", WPS_IC_TEXTDOMAIN)],
        ['box', __('Next-gen AVIF & WebP', WPS_IC_TEXTDOMAIN), __('Dramatically smaller files at the same visual quality, delivered automatically.', WPS_IC_TEXTDOMAIN)],
        ['database', __('Zero wasted storage', WPS_IC_TEXTDOMAIN), __('Only the sizes your visitors actually use are created, so your server stays lean.', WPS_IC_TEXTDOMAIN)],
    ];
};
$wpc_sd_benefits = function ($variant) use ($wpc_sd_icon, $wpc_sd_benefit_items) {
    $h = '<div class="wpc-sd-benefits wpc-sd-benefits--' . esc_attr($variant) . '">';
    foreach ($wpc_sd_benefit_items() as $item) {
        $h .= '<div class="wpc-sd-benefit"><span class="wpc-sd-benefit-icon">' . $wpc_sd_icon($item[0]) . '</span><div class="wpc-sd-benefit-text"><h4>' . esc_html($item[1]) . '</h4>' . ($variant === 'lead' ? '<p>' . esc_html($item[2]) . '</p>' : '') . '</div></div>';
    }
    return $h . '</div>';
};
$wpc_sd_how_cards = function () use ($wpc_sd_icon, $wpc_sd_benefit_items) {
    $h = '<div class="wpc-sd-how-grid">';
    foreach ($wpc_sd_benefit_items() as $item) {
        $h .= '<div class="wpc-sd-how-card"><span class="wpc-sd-how-icon">' . $wpc_sd_icon($item[0]) . '</span><h4>' . esc_html($item[1]) . '</h4><p>' . esc_html($item[2]) . '</p></div>';
    }
    return $h . '</div>';
};
$wpc_sd_tile = function ($key, $label, $value, $count, $skeleton, $icon) use ($wpc_sd_icon) {
    if ($skeleton) {
        return '<div class="wpc-stat-tile wpc-sd-tile--skel"><span class="wpc-sd-tile-icon">' . $wpc_sd_icon($icon) . '</span><div class="wpc-sd-tile-text"><div class="wpc-stat-value" data-stat="' . esc_attr($key) . '"><span class="wpc-sd-skel-bar wpc-sd-skel-value"></span></div><div class="wpc-stat-label" data-label="' . esc_attr($label) . '"><span class="wpc-sd-skel-bar wpc-sd-skel-label"></span></div></div></div>';
    }
    if ($count !== null) {
        $inner = '<span class="wpc-sd-num" data-count-to="' . (int) $count . '"' . ($value !== '' ? ' data-suffix="' . esc_attr($value) . '"' : '') . '>' . esc_html(number_format_i18n((int) $count) . $value) . '</span>';
    } else {
        $inner = esc_html("\u{2014}");
    }
    return '<div class="wpc-stat-tile"><span class="wpc-sd-tile-icon">' . $wpc_sd_icon($icon) . '</span><div class="wpc-sd-tile-text"><div class="wpc-stat-value" data-stat="' . esc_attr($key) . '">' . $inner . '</div><div class="wpc-stat-label">' . esc_html($label) . '</div></div></div>';
};
$wpc_sd_skel_rows = str_repeat(wpc_sd_skeleton_row_html(), 3);
$wpc_sd_kept = $wpc_sd_restorable > 0 ? sprintf(_n('%s optimized image still delivered', '%s optimized images still delivered', $wpc_sd_restorable, WPS_IC_TEXTDOMAIN), number_format_i18n($wpc_sd_restorable)) : '';
$wpc_sd_paused_at = $wpc_sd_paused ? (int) get_option(WPC_SD_PAUSED_AT_OPTION, 0) : 0;
$wpc_sd_paused_line = $wpc_sd_paused_at > 0 ? sprintf(__('Paused on %s when you restored all images. New images wait until you resume', WPS_IC_TEXTDOMAIN), wp_date(get_option('date_format', 'F j, Y'), $wpc_sd_paused_at)) : __('New images wait until you resume', WPS_IC_TEXTDOMAIN);
?>
<div class="wpc-sd-page wpc-prep-skel-v2" id="bulk-start-container" style="<?php echo esc_attr($bulk); ?>"
     data-wpc-sd="<?php echo $wpc_sd_paused ? 'paused' : 'active'; ?>"<?php echo $wpc_sd_first ? ' data-wpc-first="1"' : ''; ?>
     data-newest="<?php echo (int) $wpc_sd_data['newest']; ?>" data-now="<?php echo (int) $wpc_sd_now; ?>" data-today="<?php echo esc_attr(wp_date('Y-m-d', $wpc_sd_now)); ?>" data-visible="<?php echo (int) WPC_SD_FEED_VISIBLE; ?>">

    <?php $wpc_sd_skel_stats = $wpc_sd_stale || $wpc_sd_first; ?>
    <div class="wpc-sd-card<?php echo $wpc_sd_paused ? ' is-paused' : ''; ?>">
    <div class="wpc-sd-strip wpc-bulk-ribbon<?php echo $wpc_sd_paused ? ' wpc-sd-strip--paused' : ''; ?>">
            <span class="wpc-sd-pill<?php echo $wpc_sd_paused ? ' wpc-sd-pill--paused' : ($wpc_sd_first ? ' wpc-sd-pill--ready' : ''); ?>"<?php echo $wpc_sd_first ? ' data-live="' . esc_attr__('Live', WPS_IC_TEXTDOMAIN) . '"' : ''; ?>><span class="wpc-sd-pill-dot" aria-hidden="true"></span><span class="wpc-sd-pill-label"><?php echo $wpc_sd_paused ? esc_html__('Paused', WPS_IC_TEXTDOMAIN) : ($wpc_sd_first ? esc_html__('Ready', WPS_IC_TEXTDOMAIN) : esc_html__('Live', WPS_IC_TEXTDOMAIN)); ?></span></span>
            <span class="wpc-sd-strip-text wpc-bulk-ribbon-sentence"<?php echo $wpc_sd_first ? ' data-live="' . esc_attr__('Smart Delivery is on', WPS_IC_TEXTDOMAIN) . '"' : ''; ?>><?php echo $wpc_sd_paused ? esc_html($wpc_sd_paused_line) : ($wpc_sd_first ? esc_html__('Waiting for the first visitor', WPS_IC_TEXTDOMAIN) : esc_html__('Smart Delivery is on', WPS_IC_TEXTDOMAIN)); ?></span>
            <?php if ($wpc_sd_paused && $wpc_sd_kept !== '') { ?>
                <span class="wpc-sd-strip-meta"><?php echo esc_html($wpc_sd_kept); ?></span>
            <?php } ?>
            <span class="wpc-sd-strip-end">
                <?php if ($wpc_sd_paused) { ?>
                    <form class="wpc-sd-resume" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpc_sd_resume">
                        <?php wp_nonce_field('wpc_sd_resume'); ?>
                        <button type="submit" class="wpc-sd-btn"><?php esc_html_e('Resume Smart Delivery', WPS_IC_TEXTDOMAIN); ?></button>
                    </form>
                <?php } ?>
                <button type="button" class="wpc-sd-how-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="wpc-sd-how-modal" aria-label="<?php echo esc_attr__('How it works', WPS_IC_TEXTDOMAIN); ?>">
                    <?php echo $wpc_sd_icon('help'); ?><span class="wpc-sd-how-label"><?php esc_html_e('How it works', WPS_IC_TEXTDOMAIN); ?></span>
                </button>
            </span>
        </div>
        <div class="wpc-sd-hero-slot">
            <?php if ($wpc_sd_hero !== null) { ?>
                <?php echo wpc_sd_hero_html($wpc_sd_hero); ?>
            <?php } elseif (!$wpc_sd_first && !$wpc_sd_paused) { ?>
                <?php echo wpc_sd_hero_skeleton_html(); ?>
            <?php } elseif ($wpc_sd_first) { ?>
                <div class="wpc-sd-hero wpc-sd-hero--first">
                    <span class="wpc-sd-ph" aria-hidden="true"><?php echo $wpc_sd_icon('image'); ?></span>
                    <div class="wpc-bulk-hero-body">
                        <p class="wpc-sd-cta-text"><?php esc_html_e('Open any page to optimize its images', WPS_IC_TEXTDOMAIN); ?></p>
                        <div class="wpc-sd-cta">
                            <a class="wpc-sd-btn" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open my homepage', WPS_IC_TEXTDOMAIN); ?><?php echo $wpc_sd_icon('chev'); ?></a>
                            <?php if ($wpc_sd_logged_in_bypass) { ?>
                                <span class="wpc-sd-note"><?php echo esc_html__("Logged-in views aren't optimized \u{00B7} use a private window", WPS_IC_TEXTDOMAIN); ?></span>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>

        <div class="wpc-bulk-summary-body wpc-sd-stats">
            <div class="wpc-bulk-summary-stats">
                <?php echo $wpc_sd_tile('images', __('Images', WPS_IC_TEXTDOMAIN), '', (int) $wpc_sd_totals['images'], $wpc_sd_skel_stats, 'image'); ?>
                <?php echo $wpc_sd_tile('sizes', __('Variants', WPS_IC_TEXTDOMAIN), '', (int) $wpc_sd_totals['sizes'], $wpc_sd_skel_stats, 'layers'); ?>
                <?php echo $wpc_sd_tile('smaller', __('Average savings', WPS_IC_TEXTDOMAIN), '%', $wpc_sd_totals['smaller'], $wpc_sd_skel_stats, 'percent'); ?>
            </div>
        </div>
        <div class="wpc-bulk-summary-progress-track wpc-sd-accent<?php echo !$wpc_sd_paused && !$wpc_sd_first ? ' wpc-sd-accent--live' : ''; ?>" aria-hidden="true"><div class="wpc-bulk-summary-progress-fill"></div></div>
    </div>

    <div class="wpc-sd-table-head">
        <div class="wpc-bulk-completion-header"><?php esc_html_e('Recently optimized', WPS_IC_TEXTDOMAIN); ?></div>
    </div>
    <div class="wpc-bulk-feed-table">
        <div class="wpc-bulk-feed-thead">
            <span><?php esc_html_e('Image', WPS_IC_TEXTDOMAIN); ?></span>
            <span><?php esc_html_e('Format', WPS_IC_TEXTDOMAIN); ?></span>
            <span><?php esc_html_e('Original', WPS_IC_TEXTDOMAIN); ?></span>
            <span><?php esc_html_e('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
            <span><?php esc_html_e('Savings', WPS_IC_TEXTDOMAIN); ?></span>
        </div>
        <div class="wpc-bulk-completion-list">
            <div class="wpc-bulk-feed-inner" id="wpc-sd-rows" aria-live="off"><?php echo $wpc_sd_first ? $wpc_sd_skel_rows : wpc_sd_rows_html($wpc_sd_rows, $wpc_sd_now); ?></div>
        </div>
        <div class="wpc-sd-table-foot"<?php echo $wpc_sd_first ? ' hidden' : ''; ?>>
            <button type="button" class="wpc-sd-more" data-wpc-more<?php echo count($wpc_sd_rows) > WPC_SD_FEED_VISIBLE ? '' : ' hidden'; ?>><?php esc_html_e('Show more', WPS_IC_TEXTDOMAIN); ?></button>
            <span class="wpc-sd-foot-links">
                <?php if ($wpc_sd_restorable > 0 || $wpc_sd_first) { ?>
                    <?php if ($wpc_sd_remaining > 0) { ?>
                        <span class="wpc-sd-foot-off"><?php echo esc_html(sprintf(__("Restore all images \u{00B7} available in %s", WPS_IC_TEXTDOMAIN), wpc_restore_all_hours($wpc_sd_remaining))); ?></span>
                    <?php } else { ?>
                        <a href="#" class="wpc-sd-restore-link" data-wpc-restore-count="<?php echo (int) $wpc_sd_restorable; ?>" data-wpc-restore-sd="<?php echo $wpc_sd_paused ? '0' : '1'; ?>"><?php esc_html_e("Restore all images", WPS_IC_TEXTDOMAIN); ?></a>
                        <a href="#" class="button-start-bulk-restore" style="display:none" aria-hidden="true" tabindex="-1"></a>
                    <?php } ?>
                <?php } ?>
            </span>
        </div>
    </div>

    <?php if ($wpc_sd_first) { echo $wpc_sd_benefits('lead'); } ?>

    <div class="wpc-sd-modal" id="wpc-sd-how-modal" hidden>
        <div class="wpc-sd-modal-backdrop" data-wpc-how-close></div>
        <div class="wpc-sd-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wpc-sd-how-title" tabindex="-1">
            <div class="wpc-sd-modal-head">
                <h3 id="wpc-sd-how-title"><?php esc_html_e('How it works', WPS_IC_TEXTDOMAIN); ?></h3>
                <button type="button" class="wpc-sd-modal-close" data-wpc-how-close aria-label="<?php echo esc_attr__('Close', WPS_IC_TEXTDOMAIN); ?>"><?php echo $wpc_sd_icon('close'); ?></button>
            </div>
            <?php echo $wpc_sd_how_cards(); ?>
            <div class="wpc-sd-tip" role="note">
                <span class="wpc-sd-tip-icon"><?php echo $wpc_sd_icon('info'); ?></span>
                <div class="wpc-sd-tip-body">
                    <p class="wpc-sd-tip-lead"><?php echo $wpc_sd_paused ? esc_html__("Paused when you restored all images \u{2014} images still optimized keep being served; new ones wait until you resume.", WPS_IC_TEXTDOMAIN) : esc_html__("Optimized on demand as visitors view them \u{2014} the exact size and format each browser needs.", WPS_IC_TEXTDOMAIN); ?></p>
                    <p class="wpc-sd-tip-note"><?php esc_html_e('Savings compare each copy with the WordPress file it replaces: the smallest generated size at least as wide, or the full file when none is.', WPS_IC_TEXTDOMAIN); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
