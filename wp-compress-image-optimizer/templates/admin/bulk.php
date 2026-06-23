<?php

global $wps_ic;

if (!empty($_GET['reset'])) {
    delete_option('wps_ic_bulk_process');
}

$live_cdn = false;
if (!empty($wps_ic::$settings['live-cdn']) && $wps_ic::$settings['live-cdn'] == '1') {
    $live_cdn = true;
}
// Also check CF CDN setting
if (!$live_cdn) {
    $cfSettings = get_option(WPS_IC_CF);
    if (!empty($cfSettings['settings']['cdn']) && $cfSettings['settings']['cdn'] == '1') {
        $live_cdn = true;
    }
}

?>
<div class="wrap">
    <div class="wps_ic_wrap wps_ic_settings_page wps_ic_live">

        <div class="wp-compress-header">
            <div class="wp-ic-logo-container">
                <div class="wp-compress-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/main-logo.svg"/>
                </div>
            </div>
            <div class="wp-ic-header-buttons-container">
                <ul>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>" class="wps-ic-stop-bulk-compress wpc-action-btn wpc-action-btn--stop"
                           style="display:none;">
                            <span class="wpc-action-btn-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="3" y="3" width="3" height="8" rx="1" fill="currentColor"/><rect x="8" y="3" width="3" height="8" rx="1" fill="currentColor"/></svg>
                            </span>
                            <span class="wpc-action-btn-label"><?php esc_html_e('Stop Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>" class="wps-ic-stop-bulk-restore wpc-action-btn wpc-action-btn--ghost wpc-action-btn--pause"
                           style="display:none;">
                            <span class="wpc-action-btn-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="3" y="3" width="3" height="8" rx="1" fill="currentColor"/><rect x="8" y="3" width="3" height="8" rx="1" fill="currentColor"/></svg>
                            </span>
                            <span class="wpc-action-btn-label"><?php esc_html_e('Pause Restore', WPS_IC_TEXTDOMAIN); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wpc-btn-return wpc-action-btn wpc-action-btn--primary">
                            <span class="wpc-action-btn-label"><?php esc_html_e('Return to Dashboard', WPS_IC_TEXTDOMAIN); ?></span>
                            <span class="wpc-action-btn-icon wpc-action-btn-icon--right" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="clearfix"></div>
        </div>


        <div class="wp-compress-pre-wrapper-no-shadow">

            <div class="wp-compress-bulk-area">
                <?php
                /**
                 * Find uncompressed images
                 */
                // v7.02 — Use the cheap COUNT-only path (60s cached) instead of the
                // heavy prepareImages() multi-JOIN query. The page only needs counts
                // here; the full ID list isn't needed until Start Bulk Compress fires.
                // Old call: $libraryStatus = $local->prepareImages('', 'count');
                //
                // v7.03 — When NO bulk is active, the splash is about to render and the
                // 60 s cache value is irrelevant (it only existed to dedupe queries
                // during an active bulk). Bust it so the splash always reflects the
                // post-bulk reality — otherwise users see stale counts for up to 60 s
                // after a Stop or natural completion (late Phase B callbacks can also
                // land in this window).
                if (empty(get_option('wps_ic_bulk_process'))) {
                    delete_transient('wpc_bulk_library_counts');
                }
                $libraryStatus = wps_ic_local::countLibraryImages();
                $uncompressedImages = count($libraryStatus['uncompressed']);
                $compressedImages = count($libraryStatus['compressed']);

                $bulkProcess = get_option('wps_ic_bulk_process');

                $prepare_compress = 'display:none;';
                $prepare_restore = 'display:none;';
                $bulk = '';
                $show_bulk = 'display:none;';
                $compress_bulk_4boxes = 'display: flex;';
                $prepare_restore = 'display:none;';

                if (!empty($bulkProcess['status'])) {
                    if ($bulkProcess['status'] == 'compressing') {
                        $prepare_compress = 'display:block;';
                        $prepare_restore = 'display:none;';
                        $bulk = 'display:none;';
                        $show_bulk = 'display:block;';
                        $compress_bulk_4boxes = 'display: none;';
                        $prepare_restore = 'display:none;';
                    } else {
                        $prepare_compress = 'display:none;';
                        $prepare_restore = 'display:block;';
                        $bulk = 'display:none;';
                        $show_bulk = 'display:block;';
                        $compress_bulk_4boxes = 'display: none;';
                        $prepare_restore = 'display:none;';
                    }
                }

                ?>

                <!-- v7.03 — Twin-cards splash. Compress = primary, Restore = destructive.
                     Single empty state when both counts are 0. #bulk-start-container ID and
                     .button-start-bulk-* class hooks kept for the existing JS bindings. -->
                <?php $bothEmpty = ($uncompressedImages === 0 && $compressedImages === 0); ?>
                <div class="<?php echo $bothEmpty ? 'wpc-bulk-splash-empty-wrap' : 'wpc-bulk-splash'; ?>" id="bulk-start-container" style="<?php echo $bulk; ?>">

                    <?php if ($bothEmpty) { ?>

                        <div class="wpc-bulk-splash-empty">
                            <div class="wpc-bulk-splash-empty-icon">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <h2 class="wpc-bulk-splash-empty-title"><?php esc_html_e('Your library is fully optimized', WPS_IC_TEXTDOMAIN); ?></h2>
                            <p class="wpc-bulk-splash-empty-subtitle"><?php esc_html_e('All your images have been compressed and there are no compressed images to restore. New uploads are optimized automatically — return here any time.', WPS_IC_TEXTDOMAIN); ?></p>
                            <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wpc-bulk-splash-empty-cta">
                                <?php esc_html_e('Return to Dashboard', WPS_IC_TEXTDOMAIN); ?>
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                        </div>

                    <?php } else { ?>

                        <!-- Compress card (primary) -->
                        <!-- v7.04.66 — Mockup "empty-state-as-badge": when count is 0, keep
                             the same card layout but add .is-empty so LESS transforms the
                             button into a dashed success badge ("All Images Optimized").
                             Visually consistent with the populated state — no jarring
                             layout shift between empty/populated cards. -->
                        <div class="wpc-bulk-splash-card wpc-bulk-splash-card--compress <?php echo $uncompressedImages > 0 ? '' : 'is-empty'; ?>">
                            <div class="wpc-bulk-splash-icon" aria-hidden="true">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            </div>
                            <div class="wpc-bulk-splash-eyebrow"><span class="wpc-bulk-splash-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e('Recommended', WPS_IC_TEXTDOMAIN); ?></div>
                            <h2 class="wpc-bulk-splash-title"><?php esc_html_e('Bulk Compress', WPS_IC_TEXTDOMAIN); ?></h2>

                            <div class="wpc-bulk-splash-count">
                                <span class="wpc-bulk-splash-count-num" <?php if ($uncompressedImages > 0) echo 'data-count-to="' . (int) $uncompressedImages . '"'; ?>>
                                    <?php echo $uncompressedImages > 0 ? '0' : '0'; ?>
                                </span>
                                <span class="wpc-bulk-splash-count-label">
                                    <?php if ($uncompressedImages > 0) {
                                        echo esc_html(_n('image ready to optimize', 'images ready to optimize', $uncompressedImages, WPS_IC_TEXTDOMAIN));
                                    } else {
                                        esc_html_e('images already optimized', WPS_IC_TEXTDOMAIN);
                                    } ?>
                                </span>
                            </div>
                            <div class="wpc-bulk-splash-meta">
                                <div class="wpc-bulk-splash-meta-row">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                    <?php esc_html_e('Modern AVIF / WebP variants generated alongside originals', WPS_IC_TEXTDOMAIN); ?>
                                </div>
                                <div class="wpc-bulk-splash-meta-row">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?php esc_html_e('Originals are kept — restore any time', WPS_IC_TEXTDOMAIN); ?>
                                </div>
                            </div>
                            <?php if ($uncompressedImages > 0) { ?>
                                <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&action=compress'); ?>"
                                   class="button button-primary button-start-bulk-compress">
                                    <span class="wpc-bulk-splash-btn-text"><?php esc_html_e('Start Bulk Compress', WPS_IC_TEXTDOMAIN); ?></span>
                                    <svg class="wpc-bulk-splash-btn-chevron" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </a>
                            <?php } else { ?>
                                <div class="wpc-bulk-splash-badge wpc-bulk-splash-badge--complete" aria-disabled="true">
                                    <svg class="wpc-bulk-splash-badge-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                                    <span><?php esc_html_e('All Images Optimized', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>
                            <?php } ?>
                        </div>

                        <!-- Restore card (destructive) -->
                        <!-- v7.04.66 — Same mockup empty-state-as-badge pattern as compress.
                             When count is 0, .is-empty toggles the button into a dashed
                             ghost badge ("Nothing to Restore") instead of swapping in a
                             different markup block — keeps layout heights consistent. -->
                        <div class="wpc-bulk-splash-card wpc-bulk-splash-card--restore <?php echo $compressedImages > 0 ? '' : 'is-empty'; ?>">
                            <div class="wpc-bulk-splash-icon" aria-hidden="true">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                            </div>
                            <div class="wpc-bulk-splash-eyebrow"><span class="wpc-bulk-splash-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e('Reverses optimization', WPS_IC_TEXTDOMAIN); ?></div>
                            <h2 class="wpc-bulk-splash-title"><?php esc_html_e('Bulk Restore', WPS_IC_TEXTDOMAIN); ?></h2>

                            <div class="wpc-bulk-splash-count">
                                <span class="wpc-bulk-splash-count-num" <?php if ($compressedImages > 0) echo 'data-count-to="' . (int) $compressedImages . '"'; ?>>
                                    <?php echo '0'; ?>
                                </span>
                                <span class="wpc-bulk-splash-count-label">
                                    <?php if ($compressedImages > 0) {
                                        echo esc_html(_n('image can be restored', 'images can be restored', $compressedImages, WPS_IC_TEXTDOMAIN));
                                    } else {
                                        esc_html_e('images to restore', WPS_IC_TEXTDOMAIN);
                                    } ?>
                                </span>
                            </div>
                            <div class="wpc-bulk-splash-meta">
                                <div class="wpc-bulk-splash-meta-row">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-9 9m0 0a9 9 0 0 1-9-9m9 9V3m9 9H3"/></svg>
                                    <?php esc_html_e('Original files returned to your media library', WPS_IC_TEXTDOMAIN); ?>
                                </div>
                                <div class="wpc-bulk-splash-meta-row">
                                    <svg class="is-warning" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    <?php esc_html_e('Compressed variants will be discarded', WPS_IC_TEXTDOMAIN); ?>
                                </div>
                            </div>
                            <?php if ($compressedImages > 0) { ?>
                                <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&action=restore'); ?>"
                                   class="button button-primary button-start-bulk-restore">
                                    <span class="wpc-bulk-splash-btn-text"><?php esc_html_e('Start Bulk Restore', WPS_IC_TEXTDOMAIN); ?></span>
                                    <svg class="wpc-bulk-splash-btn-chevron" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </a>
                            <?php } else { ?>
                                <div class="wpc-bulk-splash-badge wpc-bulk-splash-badge--ghost" aria-disabled="true">
                                    <span><?php esc_html_e('Nothing to Restore', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>
                            <?php } ?>
                        </div>

                    <?php } ?>

                </div>

                <!-- Splash count-up: tick the gradient numbers from 0 to their target.
                     ~900ms, eased — gives the splash a "live data" feel on first paint. -->
                <script>
                (function () {
                    var nodes = document.querySelectorAll('.wpc-bulk-splash-count-num[data-count-to]');
                    if (!nodes.length || !window.requestAnimationFrame) return;
                    nodes.forEach(function (el) {
                        var target = parseInt(el.getAttribute('data-count-to'), 10) || 0;
                        if (target < 1) { el.textContent = '0'; return; }
                        var start = null;
                        var duration = Math.min(1200, 500 + target * 8);
                        function ease(t) { return 1 - Math.pow(1 - t, 3); } // ease-out cubic
                        function step(ts) {
                            if (start === null) start = ts;
                            var p = Math.min(1, (ts - start) / duration);
                            var v = Math.floor(ease(p) * target);
                            el.textContent = v.toLocaleString();
                            if (p < 1) requestAnimationFrame(step);
                            else el.textContent = target.toLocaleString();
                        }
                        // Delay slightly so the count-in fade-up isn't fighting with the digit churn.
                        setTimeout(function () { requestAnimationFrame(step); }, 380);
                    });
                })();
                </script>

                <!-- Bulk Area When something is running -->
                <div class="bulk-area-inner" style="<?php echo $show_bulk; ?>">
                    <div>
                        <div class="bulk-finished" style="display:none;text-align: center;"></div>
                        <!-- v7.03 — Preparing screen (mockup-matched). Replaces the legacy
                             logo+placeholder layout with a gradient spinner ring around the
                             brand logo + skeleton (header row, main bar, 4 tile cards with
                             animated avatar spinners). Same outer class (.bulk-preparing-*)
                             so the existing JS show/hide hooks keep working. -->
                        <!-- v7.04.68 — World-class skeleton: mirrors the ACTUAL processing
                             view structure (summary card + stats grid + progress bar + feed
                             table) so when JS swaps preparing→processing on first heartbeat,
                             ZERO layout shift. Each data slot is a shimmer placeholder. The
                             "Preparing" chip with pulse-dot is a real signal element (not a
                             placeholder) so the user sees an active state immediately. -->
                        <div class="bulk-preparing-optimize wpc-prep-skel-v2" style="<?php echo $prepare_compress; ?>">
                            <!-- v7.04.68 — Hero card skeleton (mirrors .wpc-bulk-hero with
                                 the new status row + savings + filename layout). Real
                                 "• PREPARING" label sits where "• OPTIMIZING" will go on
                                 the live card — same pulse, same color, immediate
                                 active feedback while the encoder warms up. -->
                            <!-- v7.04.71 — Skeleton thumb is a PLAIN .wpc-prep-skel-thumb
                                 (not wrapped in .wpc-bulk-hero-thumb-wrap). The wrap has
                                 its own gradient + box-shadow at _bulk.less:128-137 that
                                 made the placeholder render as a "raised card" — visually
                                 inconsistent with the flat shimmering pills used by every
                                 OTHER placeholder on this page. Now the hero thumb skel
                                 uses the same shared shimmer mixin (_bulk.less:3707-3721)
                                 as all the line + thumb placeholders. Dimensions match
                                 the LIVE thumb wrap (160×110, radius @radius-md=8px) so
                                 first-heartbeat swap is zero-layout-shift. The thumb
                                 wrap appears live when WPCBulk.renderHero replaces the
                                 hero markup with the real .wpc-bulk-hero-thumb-wrap +
                                 inner .wpc-bulk-hero-thumb (with bg-image of the
                                 attachment thumbnail). -->
                            <div class="wpc-bulk-hero wpc-prep-skel-card" style="display:flex;">
                                <div class="wpc-prep-skel-thumb" style="width: 160px; height: 110px; border-radius: 8px; flex-shrink: 0;"></div>
                                <div class="wpc-bulk-hero-body">
                                    <div class="wpc-bulk-hero-status-row">
                                        <span class="wpc-bulk-hero-live-dot" aria-hidden="true"></span>
                                        <span class="wpc-bulk-hero-status-text"><?php esc_html_e('Preparing', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                    <div class="wpc-bulk-hero-headline">
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--counter" style="width: 140px; height: 36px;"></div>
                                    </div>
                                    <div class="wpc-bulk-hero-meta">
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--filename" style="width: 240px;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Summary card skeleton (mirrors .wpc-bulk-summary-card —
                                 Optimizing chip hidden in live view too, so the skeleton
                                 doesn't render one here either). -->
                            <div class="wpc-bulk-summary-card wpc-prep-skel-card">
                                <div class="wpc-bulk-summary-body">
                                    <div class="wpc-bulk-summary-status">
                                        <div class="wpc-bulk-now-processing">
                                            <span class="wpc-bulk-now-processing-label"><?php esc_html_e('Now processing', WPS_IC_TEXTDOMAIN); ?></span>
                                            <div class="wpc-bulk-now-processing-row">
                                                <div class="wpc-prep-skel-thumb-stack">
                                                    <div class="wpc-prep-skel-thumb"></div>
                                                    <div class="wpc-prep-skel-thumb"></div>
                                                    <div class="wpc-prep-skel-thumb"></div>
                                                </div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--title" style="width: 220px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="wpc-bulk-summary-stats">
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--value" style="width: 72px;"></div>
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 90px;"></div>
                                        </div>
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--value" style="width: 48px;"></div>
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 82px;"></div>
                                        </div>
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--value" style="width: 80px;"></div>
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 96px;"></div>
                                        </div>
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--value" style="width: 56px;"></div>
                                            <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 102px;"></div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Progress track skeleton — gradient shimmer slides along it -->
                                <div class="wpc-bulk-summary-progress-track">
                                    <div class="wpc-prep-skel-progress-shimmer"></div>
                                </div>
                            </div>

                            <!-- Feed table skeleton (mirrors .wpc-bulk-feed-table) -->
                            <div class="wpc-bulk-completion-header"><?php esc_html_e('Recently Optimized', WPS_IC_TEXTDOMAIN); ?></div>
                            <div class="wpc-bulk-feed-table wpc-prep-skel-feed">
                                <div class="wpc-bulk-feed-thead">
                                    <span><?php esc_html_e('Image', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Format', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Original', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Savings', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>
                                <div class="wpc-bulk-completion-list">
                                    <?php for ($_i = 0; $_i < 5; $_i++) : ?>
                                        <div class="wpc-prep-skel-feed-row">
                                            <div class="wpc-prep-skel-feed-cell wpc-prep-skel-feed-cell--image">
                                                <div class="wpc-prep-skel-thumb wpc-prep-skel-thumb--row"></div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--name"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell">
                                                <div class="wpc-prep-skel-badge"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell">
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--num"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell">
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--num"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell wpc-prep-skel-feed-cell--savings">
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--bar"></div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <!-- v7.04.68 — World-class restore preparing skeleton. Mirrors the
                             ACTUAL restore processing card (thumb + filename + counter +
                             ETA + progress + feed table) with shimmer placeholders. Zero
                             layout shift when JS swaps preparing→processing on the first
                             heartbeat. The "Preparing" chip is real (gives instant active
                             feedback); everything else is a shimmer placeholder. -->
                        <div class="bulk-preparing-restore wpc-prep-skel-v2 wpc-prep-skel-v2--restore" style="<?php echo $prepare_restore; ?>">
                            <!-- Hero card skeleton (mirrors .wpc-restore-card) -->
                            <div class="wpc-restore-card wpc-prep-skel-card">
                                <div class="wpc-restore-card-body">
                                    <!-- Thumb placeholder (matches .wpc-restore-thumb-wrap geometry) -->
                                    <div class="wpc-prep-skel-restore-thumb"></div>
                                    <!-- Filename + status placeholders -->
                                    <div class="wpc-prep-skel-restore-meta">
                                        <div class="wpc-chip-row">
                                            <span class="wpc-chip wpc-chip-active">
                                                <span class="wpc-chip-dot"></span>
                                                <?php esc_html_e('Preparing', WPS_IC_TEXTDOMAIN); ?>
                                            </span>
                                        </div>
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--filename" style="width: 280px;"></div>
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--meta" style="width: 180px;"></div>
                                    </div>
                                    <!-- Counter block placeholder -->
                                    <div class="wpc-prep-skel-restore-counter">
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--counter" style="width: 90px;"></div>
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 110px;"></div>
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--bytes" style="width: 70px;"></div>
                                    </div>
                                    <!-- ETA block placeholder -->
                                    <div class="wpc-prep-skel-restore-eta">
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--eta" style="width: 60px;"></div>
                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 32px;"></div>
                                    </div>
                                </div>
                                <div class="wpc-restore-progress-track">
                                    <div class="wpc-prep-skel-progress-shimmer"></div>
                                </div>
                            </div>

                            <!-- Feed table skeleton (mirrors restore feed table — 4 cols) -->
                            <div class="wpc-bulk-completion-header"><?php esc_html_e('Recently Restored', WPS_IC_TEXTDOMAIN); ?></div>
                            <div class="wpc-bulk-feed-table wpc-prep-skel-feed">
                                <div class="wpc-bulk-feed-thead wpc-bulk-feed-thead--restore">
                                    <span><?php esc_html_e('Image', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Source', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Restored', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Status', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>
                                <div class="wpc-bulk-completion-list">
                                    <?php for ($_i = 0; $_i < 5; $_i++) : ?>
                                        <div class="wpc-prep-skel-feed-row wpc-prep-skel-feed-row--restore">
                                            <div class="wpc-prep-skel-feed-cell wpc-prep-skel-feed-cell--image">
                                                <div class="wpc-prep-skel-thumb wpc-prep-skel-thumb--row"></div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--name"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell">
                                                <div class="wpc-prep-skel-badge"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell">
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--num"></div>
                                            </div>
                                            <div class="wpc-prep-skel-feed-cell wpc-prep-skel-feed-cell--status">
                                                <div class="wpc-prep-skel-badge wpc-prep-skel-badge--status"></div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <div class="bulk-status" style="display: none;"></div>

                        <!-- v7.02 — World-class restore UX. Three views (preparing / processing /
                             completed). Driven by WPCRestore (assets/js/admin/bulk-ui.js).
                             Heartbeat emits JSON fields (current, bytes_restored, eta_seconds,
                             recent[]) so the JS does field-level updates and CSS animations
                             actually run between polls (crossfade thumb, slide filename). -->
                        <div class="wpc-restore-surface" style="display: none;">

                            <!-- v7.04.68 — Preparing view now hosts the world-class skeleton
                                 (structure mirrors the processing card so swap is seamless).
                                 _restoreSwitchView('preparing') shows this; first heartbeat
                                 data triggers _restoreSwitchView('processing') which hides
                                 this view and reveals the live data card below. -->
                            <div class="wpc-restore-view is-active" data-view="preparing">
                                <div class="wpc-prep-skel-v2 wpc-prep-skel-v2--restore">
                                    <!-- Hero card skeleton (mirrors .wpc-restore-card) -->
                                    <div class="wpc-restore-card wpc-prep-skel-card">
                                        <div class="wpc-restore-card-body">
                                            <div class="wpc-prep-skel-restore-thumb"></div>
                                            <div class="wpc-prep-skel-restore-meta">
                                                <div class="wpc-chip-row">
                                                    <span class="wpc-chip wpc-chip-active">
                                                        <span class="wpc-chip-dot"></span>
                                                        <?php esc_html_e('Preparing', WPS_IC_TEXTDOMAIN); ?>
                                                    </span>
                                                </div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--filename" style="width: 280px;"></div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--meta" style="width: 180px;"></div>
                                            </div>
                                            <div class="wpc-prep-skel-restore-counter">
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--counter" style="width: 90px;"></div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 110px;"></div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--bytes" style="width: 70px;"></div>
                                            </div>
                                            <div class="wpc-prep-skel-restore-eta">
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--eta" style="width: 60px;"></div>
                                                <div class="wpc-prep-skel-line wpc-prep-skel-line--label" style="width: 32px;"></div>
                                            </div>
                                        </div>
                                        <div class="wpc-restore-progress-track">
                                            <div class="wpc-prep-skel-progress-shimmer"></div>
                                        </div>
                                    </div>

                                    <!-- Feed table skeleton -->
                                    <div class="wpc-bulk-completion-header"><?php esc_html_e('Recently Restored', WPS_IC_TEXTDOMAIN); ?></div>
                                    <div class="wpc-bulk-feed-table wpc-prep-skel-feed">
                                        <div class="wpc-bulk-feed-thead wpc-bulk-feed-thead--restore">
                                            <span><?php esc_html_e('Image', WPS_IC_TEXTDOMAIN); ?></span>
                                            <span><?php esc_html_e('Source', WPS_IC_TEXTDOMAIN); ?></span>
                                            <span><?php esc_html_e('Restored', WPS_IC_TEXTDOMAIN); ?></span>
                                            <span><?php esc_html_e('Status', WPS_IC_TEXTDOMAIN); ?></span>
                                        </div>
                                        <div class="wpc-bulk-completion-list">
                                            <?php for ($_i = 0; $_i < 5; $_i++) : ?>
                                                <div class="wpc-prep-skel-feed-row wpc-prep-skel-feed-row--restore">
                                                    <div class="wpc-prep-skel-feed-cell wpc-prep-skel-feed-cell--image">
                                                        <div class="wpc-prep-skel-thumb wpc-prep-skel-thumb--row"></div>
                                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--name"></div>
                                                    </div>
                                                    <div class="wpc-prep-skel-feed-cell">
                                                        <div class="wpc-prep-skel-badge"></div>
                                                    </div>
                                                    <div class="wpc-prep-skel-feed-cell">
                                                        <div class="wpc-prep-skel-line wpc-prep-skel-line--num"></div>
                                                    </div>
                                                    <div class="wpc-prep-skel-feed-cell wpc-prep-skel-feed-cell--status">
                                                        <div class="wpc-prep-skel-badge wpc-prep-skel-badge--status"></div>
                                                    </div>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="wpc-restore-view" data-view="processing">
                                <div class="wpc-restore-card">
                                    <div class="wpc-restore-card-body">
                                        <div class="wpc-restore-thumb-wrap">
                                            <div class="wpc-restore-thumb-halo" aria-hidden="true"></div>
                                            <div class="wpc-restore-thumb-img" data-field="thumb"></div>
                                        </div>
                                        <div class="wpc-restore-meta">
                                            <div class="wpc-restore-status-row">
                                                <span class="wpc-restore-live-dot" aria-hidden="true"></span>
                                                <span class="wpc-restore-status-text"><?php esc_html_e('Restoring', WPS_IC_TEXTDOMAIN); ?></span>
                                            </div>
                                            <h3 class="wpc-restore-filename" data-field="name"><?php esc_html_e('Initializing…', WPS_IC_TEXTDOMAIN); ?></h3>
                                            <div class="wpc-restore-metarow">
                                                <span data-field="size" class="wpc-restore-meta-size">—</span>
                                                <span class="wpc-restore-meta-sep" aria-hidden="true">·</span>
                                                <span data-field="file_elapsed" class="wpc-restore-meta-elapsed">—</span>
                                            </div>
                                        </div>
                                        <div class="wpc-restore-stats">
                                            <div class="wpc-restore-counter-row">
                                                <span class="wpc-counter-current" data-field="count">0</span>
                                                <span class="wpc-counter-of"><?php esc_html_e('of', WPS_IC_TEXTDOMAIN); ?></span>
                                                <span class="wpc-counter-total" data-field="total">0</span>
                                            </div>
                                            <div class="wpc-restore-counter-label"><?php esc_html_e('Images restored', WPS_IC_TEXTDOMAIN); ?></div>
                                            <div class="wpc-restore-bytes" data-field="bytes_restored">—</div>
                                        </div>
                                        <div class="wpc-restore-eta-block">
                                            <div class="wpc-restore-eta-value" data-field="eta">—</div>
                                            <div class="wpc-restore-eta-label"><?php esc_html_e('left', WPS_IC_TEXTDOMAIN); ?></div>
                                            <div class="wpc-restore-eta-avg" data-field="avg">—</div>
                                        </div>
                                    </div>
                                    <div class="wpc-restore-progress-track">
                                        <div class="wpc-restore-progress-fill" data-field="bar">
                                            <span class="wpc-restore-progress-shimmer" aria-hidden="true"></span>
                                        </div>
                                        <span class="wpc-restore-progress-label" data-field="pct">0%</span>
                                    </div>
                                </div>

                                <!-- v7.04.68 — Bottom feed now matches compress's "Recently
                                     Optimized" table-row design (was chip bubbles + up-next).
                                     Same .wpc-bulk-feed-table classes so the existing LESS
                                     covers both. JS at bulk-ui.js renders rows into
                                     .wpc-restore-feed-inner mirror of the compress feed. -->
                                <div class="wpc-bulk-completion-header"><?php esc_html_e('Recently Restored', WPS_IC_TEXTDOMAIN); ?></div>
                                <div class="wpc-bulk-feed-table">
                                    <div class="wpc-bulk-feed-thead wpc-bulk-feed-thead--restore">
                                        <span><?php esc_html_e('Image', WPS_IC_TEXTDOMAIN); ?></span>
                                        <span><?php esc_html_e('Source', WPS_IC_TEXTDOMAIN); ?></span>
                                        <span><?php esc_html_e('Restored', WPS_IC_TEXTDOMAIN); ?></span>
                                        <span><?php esc_html_e('Status', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                    <div class="wpc-bulk-completion-list" aria-live="polite">
                                        <div class="wpc-restore-feed-inner" data-field="recent"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="wpc-restore-view" data-view="completed">
                                <div class="wpc-restore-complete">
                                    <!-- Confetti — 16 particles burst outward from the icon -->
                                    <div class="wpc-confetti" aria-hidden="true">
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                        <span class="wpc-confetti-piece"></span><span class="wpc-confetti-piece"></span>
                                    </div>
                                    <div class="wpc-restore-complete-icon">
                                        <span class="wpc-icon-glow" aria-hidden="true"></span>
                                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M5 13l4 4L19 7" class="wpc-check-path"/>
                                        </svg>
                                    </div>
                                    <h2 class="wpc-restore-complete-title"><?php esc_html_e('Restore Complete!', WPS_IC_TEXTDOMAIN); ?></h2>
                                    <p class="wpc-restore-complete-subtitle">
                                        <?php
                                        /* translators: %s = N images restored */
                                        printf(
                                            esc_html__('Successfully restored all %s images to original quality.', WPS_IC_TEXTDOMAIN),
                                            '<strong data-field="final-count">0</strong>'
                                        );
                                        ?>
                                    </p>
                                    <!-- v7.04.68 — Positive-framing 2-stat. "Reclaimed" implies winning
                                         back space; restore actually ADDS bytes (originals are heavier
                                         than compressed variants), so the neutral "Total Bytes" is
                                         truthful. Image count is "Originals" since the subtitle's "5
                                         images" copy already provides context. -->
                                    <div class="wpc-bulk-complete-stats">
                                        <div class="wpc-bulk-complete-stat">
                                            <div class="wpc-bulk-complete-stat-value" data-field="final-count-stat">0</div>
                                            <div class="wpc-bulk-complete-stat-label"><?php esc_html_e('Originals', WPS_IC_TEXTDOMAIN); ?></div>
                                        </div>
                                        <div class="wpc-bulk-complete-stat">
                                            <div class="wpc-bulk-complete-stat-value" data-field="final-restored">0&nbsp;B</div>
                                            <div class="wpc-bulk-complete-stat-label"><?php esc_html_e('Total Bytes', WPS_IC_TEXTDOMAIN); ?></div>
                                        </div>
                                    </div>
                                    <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wpc-action-btn wpc-action-btn--primary wpc-restore-complete-cta">
                                        <span class="wpc-action-btn-label"><?php esc_html_e('Return to Dashboard', WPS_IC_TEXTDOMAIN); ?></span>
                                        <span class="wpc-action-btn-icon wpc-action-btn-icon--right" aria-hidden="true">
                                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="bulk-status-settings" style="display: none;"></div>
                        <div class="bulk-status-progress-bar" style="display: none;">
                            <div class="bulk-process-file-name"></div>
                            <div class="bulk-process-status"></div>
                            <div class="progress-bar-outer">
                                <div class="progress-bar-inner" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="bulk-restore-status-progress" style="display: none;">
                            <div class="bulk-images-restored">
                                <h3>0/0</h3>
                                <h5><?php esc_html_e('Images Restored', WPS_IC_TEXTDOMAIN); ?></h5>
                            </div>
                        </div>
                        <div class="bulk-restore-status-container" style="display: none;">
                            <h4><?php esc_html_e('Image Restore Complete!', WPS_IC_TEXTDOMAIN); ?></h4>
                            <span><?php esc_html_e('We have successfully restored all of your images.', WPS_IC_TEXTDOMAIN); ?></span>
                            <div class="bulk-status-progress-bar">
                                <div class="progress-bar-outer">
                                    <div class="progress-bar-inner" style="width: 100%;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="bulk-compress-status-progress" style="display: none;">
                            <div class="bulk-images-compressed">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0/0</h3>
                                    <h5><?php esc_html_e('Original Images', WPS_IC_TEXTDOMAIN); ?></h5>
                                </div>
                            </div>
                            <div class="bulk-thumbs-compressed">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0/0</h3>
                                    <h5><?php esc_html_e('Total Images', WPS_IC_TEXTDOMAIN); ?></h5>
                                </div>
                            </div>
                            <div class="bulk-total-savings">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0.0MB</h3>
                                    <h5><?php esc_html_e('Total Savings', WPS_IC_TEXTDOMAIN); ?></h5>
                                </div>
                            </div>
                            <div class="bulk-avg-reduction">
                                <div class="icon"></div>
                                <div class="data">
                                    <h3>0%</h3>
                                    <h5><?php esc_html_e('Average Savings', WPS_IC_TEXTDOMAIN); ?></h5>
                                </div>
                            </div>
                        </div>
                        <!-- v7.02 — World-class bulk progress card. Pulsing "Optimizing"
                             chip + "Now processing" line + 4 stat tiles + gradient progress.
                             Populated by WPCBulk.renderTally on each heartbeat tick.
                             Hides legacy .bulk-compress-status-progress on activation. -->
                        <div class="wpc-bulk-v2-surface" style="display: none;">

                          <!-- v7.03 — Two-view machine. "processing" holds the live hero +
                               summary + feed; "completed" holds the confetti celebration.
                               WPCBulk.compressCompleted(d) switches data-view + fills the
                               final stats. Mirrors the restore 3-view pattern. -->
                          <div class="wpc-bulk-view is-active" data-view="processing">

                            <!-- v7.03 — Breakdown ribbon. Live one-line summary: total saved
                                 so far, processing rate (img/s), ETA. Hidden until first
                                 variant lands. Populated by WPCBulk.renderTally each tick. -->
                            <!-- v7.04.68 — Single-sentence rotating ribbon. Replaces the 3-stat
                                 grid with one context-rich phrase that cycles through real-world
                                 impact framings every ~5s. JS rebuilds the sentence on each
                                 heartbeat from live data, fade-swaps to the next sentence on
                                 the timer. A small shield icon anchors the bar visually. -->
                            <div class="wpc-bulk-ribbon" style="display: none;">
                                <span class="wpc-bulk-ribbon-icon" aria-hidden="true">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                </span>
                                <span class="wpc-bulk-ribbon-sentence" data-field="ribbon-sentence">
                                    <?php esc_html_e('Optimizing your images…', WPS_IC_TEXTDOMAIN); ?>
                                </span>
                            </div>

                            <!-- v7.03 — Hero card. Mirrors the ML per-image compressed card
                                 (.wpc-ml-card--compressed). WPCBulk.renderHero rotates the
                                 displayed image to whichever just received a variant, ticks
                                 the per-image savings %, lands the +FORMAT chip in the
                                 headline, and pulses per-format letters in the count chip.
                                 Hidden until first persisted variant lands. -->
                            <div class="wpc-bulk-hero" style="display: none;">
                                <div class="wpc-bulk-hero-thumb-wrap">
                                    <div class="wpc-bulk-hero-thumb is-loading" data-field="hero-thumb" aria-hidden="true"></div>
                                    <div class="wpc-bulk-hero-thumb-glint" aria-hidden="true"></div>
                                </div>
                                <div class="wpc-bulk-hero-body">
                                    <!-- v7.04.68 — Final layout matches the restore card pattern:
                                         1. "• OPTIMIZING" status label (blue dot + uppercase word)
                                         2. "65.0% Saved" headline with rising +DELTA chip inline
                                         3. Filename below the savings
                                         The live-dot now lives in its own status row, not next
                                         to the filename. -->
                                    <div class="wpc-bulk-hero-status-row">
                                        <span class="wpc-bulk-hero-live-dot" aria-hidden="true"></span>
                                        <span class="wpc-bulk-hero-status-text"><?php esc_html_e('Optimizing', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                    <div class="wpc-bulk-hero-headline">
                                        <span class="wpc-bulk-hero-savings" data-field="hero-pct">0.0%</span>
                                        <span class="wpc-bulk-hero-saved-label"><?php esc_html_e('Saved', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                    <div class="wpc-bulk-hero-meta">
                                        <span class="wpc-bulk-hero-filename" data-field="hero-filename"><?php esc_html_e('Warming up…', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                    <!-- Legacy Optimizing row kept hidden — replaced by status row above -->
                                    <div class="wpc-bulk-hero-optimizing-row" style="display:none;">
                                        <span class="wpc-chip wpc-chip-active wpc-bulk-hero-optimizing-chip">
                                            <span class="wpc-chip-dot"></span>
                                            <?php esc_html_e('Optimizing', WPS_IC_TEXTDOMAIN); ?>
                                        </span>
                                    </div>
                                    <!-- Legacy variants-chip kept in DOM (data-fields preserved
                                         for any JS that reads them) but visually hidden — the
                                         chip is no longer rendered, the delta chip lives in the
                                         headline row now. -->
                                    <div class="wpc-bulk-hero-variants-chip" style="display:none;">
                                        <span class="wpc-vc-total" data-field="hero-count">0</span>
                                        <span class="wpc-vc-sep">·</span>
                                        <span class="wpc-vc-jpeg" data-field="hero-jpeg">0J</span>
                                        <span class="wpc-vc-webp" data-field="hero-webp">0W</span>
                                        <span class="wpc-vc-avif" data-field="hero-avif">0A</span>
                                    </div>
                                </div>
                            </div>

                            <div class="wpc-bulk-summary-card">
                                <div class="wpc-bulk-summary-body">
                                    <div class="wpc-bulk-summary-status">
                                        <!-- v7.04.68 — Optimizing chip moved to the hero card.
                                             Hidden here (display:none on the .wpc-chip-row)
                                             so we don't show two duplicates. -->
                                        <div class="wpc-chip-row" style="display:none;">
                                            <span class="wpc-chip wpc-chip-active">
                                                <span class="wpc-chip-dot"></span>
                                                <?php esc_html_e('Optimizing', WPS_IC_TEXTDOMAIN); ?>
                                            </span>
                                        </div>
                                        <div class="wpc-bulk-now-processing">
                                            <span class="wpc-bulk-now-processing-label"><?php esc_html_e('Now processing', WPS_IC_TEXTDOMAIN); ?></span>
                                            <div class="wpc-bulk-now-processing-row">
                                                <div class="wpc-bulk-active-thumbs" aria-hidden="true"></div>
                                                <span class="wpc-bulk-active-titles">—</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="wpc-bulk-summary-stats">
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-stat-value"><span class="wpc-bulk-counter">0 / 0</span></div>
                                            <div class="wpc-stat-label"><?php esc_html_e('Original Images', WPS_IC_TEXTDOMAIN); ?></div>
                                        </div>
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-stat-value"><span class="wpc-bulk-variants">0</span></div>
                                            <div class="wpc-stat-label"><?php esc_html_e('Total Images', WPS_IC_TEXTDOMAIN); ?></div>
                                        </div>
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-stat-value"><span class="wpc-bulk-savings-bytes">0 B</span></div>
                                            <div class="wpc-stat-label"><?php esc_html_e('Total Savings', WPS_IC_TEXTDOMAIN); ?></div>
                                        </div>
                                        <div class="wpc-stat-tile">
                                            <div class="wpc-stat-value"><span class="wpc-bulk-savings-pct">0%</span></div>
                                            <div class="wpc-stat-label"><?php esc_html_e('Average Savings', WPS_IC_TEXTDOMAIN); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="wpc-bulk-summary-progress-track">
                                    <div class="wpc-bulk-summary-progress-fill" style="width: 0%;"></div>
                                </div>
                            </div>

                            <div class="wpc-bulk-completion-header"><?php esc_html_e('Recently Optimized', WPS_IC_TEXTDOMAIN); ?></div>
                            <!-- v7.03 — Table layout matches the per-image ML "Optimization Results" modal.
                                 Same .wpc-format-badge palette, same purple→indigo savings bar.
                                 .wpc-bulk-completion-list = mask wrapper (overflow:hidden); the new
                                 .wpc-bulk-feed-inner is the transform target the JS slides on each
                                 row insert (scroll-up cascade — new at top, last fades out). -->
                            <div class="wpc-bulk-feed-table">
                                <div class="wpc-bulk-feed-thead">
                                    <span><?php esc_html_e('Image', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Format', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Original', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
                                    <span><?php esc_html_e('Savings', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>
                                <div class="wpc-bulk-completion-list" aria-live="polite">
                                    <div class="wpc-bulk-feed-inner"></div>
                                </div>
                            </div>

                          </div>
                          <!-- /processing view -->

                          <!-- v7.03 — Compress completed view. Mirrors the restore completion
                               pattern (confetti burst + drawn-check + final stats + primary
                               CTA). WPCBulk.compressCompleted(d) switches to this view and
                               fills the data-field slots. -->
                          <div class="wpc-bulk-view" data-view="completed">
                            <div class="wpc-bulk-complete">
                              <div class="wpc-confetti" aria-hidden="true">
                                <span></span><span></span><span></span><span></span>
                                <span></span><span></span><span></span><span></span>
                                <span></span><span></span><span></span><span></span>
                                <span></span><span></span><span></span><span></span>
                              </div>
                              <div class="wpc-bulk-complete-icon">
                                <span class="wpc-icon-glow" aria-hidden="true"></span>
                                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                  <path d="M5 13l4 4L19 7" class="wpc-bulk-complete-check"/>
                                </svg>
                              </div>
                              <h2 class="wpc-bulk-complete-title"><?php esc_html_e('Optimization Complete!', WPS_IC_TEXTDOMAIN); ?></h2>
                              <p class="wpc-bulk-complete-subtitle">
                                <?php
                                /* translators: 1: N images optimized, 2: N variants generated */
                                printf(
                                  esc_html__('Successfully optimized %1$s original images, generating %2$s modern variants.', WPS_IC_TEXTDOMAIN),
                                  '<strong data-field="final-count">0</strong>',
                                  '<strong data-field="final-variants">0</strong>'
                                );
                                ?>
                              </p>
                              <div class="wpc-bulk-complete-stats">
                                <div class="wpc-bulk-complete-stat">
                                  <div class="wpc-bulk-complete-stat-value" data-field="final-saved">0&nbsp;B</div>
                                  <div class="wpc-bulk-complete-stat-label"><?php esc_html_e('Total Saved', WPS_IC_TEXTDOMAIN); ?></div>
                                </div>
                                <div class="wpc-bulk-complete-stat">
                                  <div class="wpc-bulk-complete-stat-value" data-field="final-pct">0%</div>
                                  <div class="wpc-bulk-complete-stat-label"><?php esc_html_e('Total Reduction', WPS_IC_TEXTDOMAIN); ?></div>
                                </div>
                              </div>
                              <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wpc-action-btn wpc-action-btn--primary wpc-bulk-complete-cta">
                                <span class="wpc-action-btn-label"><?php esc_html_e('Return to Dashboard', WPS_IC_TEXTDOMAIN); ?></span>
                                <span class="wpc-action-btn-icon wpc-action-btn-icon--right" aria-hidden="true">
                                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                              </a>
                            </div>
                          </div>
                          <!-- /completed view -->

                        </div>

                        <div class="bulk-compress-status-progress-prepare" style="<?php echo $compress_bulk_4boxes; ?>">
                            <div class="bulk-images-compressed">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                            <div class="bulk-thumbs-compressed">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                            <div class="bulk-total-savings">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                            <div class="bulk-avg-reduction">
                                <div class="icon">
                                    <div class="inner"></div>
                                </div>
                                <div class="data">
                                    <div class="wpc-ic-small-thick-placeholder" style="width:60px;"></div>
                                    <div class="wpc-ic-small-thick-placeholder" style="width:120px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


            </div>

        </div>

        <?php
        // TODO: Bottom bar with hidden message about bulk optimization
        ?>

        <?php include WPS_IC_DIR . 'templates/admin/partials/popups/bulk/popups.php'; ?>
    </div>
</div>