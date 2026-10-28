<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('WPC_Delivery_Resolver')) return;

$rv = WPC_Delivery_Resolver::resolve_verbose();


$wpc_ngd_pending = is_array($rv['warnings'] ?? null)
    && (in_array('cdn_pending_verify', $rv['warnings'], true) || in_array('htaccess_pending_verify', $rv['warnings'], true));


$wpc_ngd_just_enabled = (function_exists('get_transient') && get_transient('wpc_ngd_just_enabled'));
if (($wpc_ngd_pending || $wpc_ngd_just_enabled) && !get_transient('wpc_delivery_card_verify_rl')) {
    set_transient('wpc_delivery_card_verify_rl', 1, 2 * MINUTE_IN_SECONDS);
    if ($wpc_ngd_just_enabled && function_exists('delete_transient')) delete_transient('wpc_ngd_just_enabled');
    $rv = WPC_Delivery_Resolver::resolve_verbose(true);
}
$tier     = isset($rv['tier']) ? (int) $rv['tier'] : WPC_Delivery_Resolver::TIER_JPEG;
$ceiling  = isset($rv['ceiling']) ? $rv['ceiling'] : 'off';
$override = isset($rv['override']) ? $rv['override'] : 'auto';
$warnings = isset($rv['warnings']) && is_array($rv['warnings']) ? $rv['warnings'] : [];
$caps     = isset($rv['capabilities']) && is_array($rv['capabilities']) ? $rv['capabilities'] : [];


$images_on = !class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled();

$has = function ($needle) use ($warnings) {
    foreach ($warnings as $w) { if (strpos($w, $needle) === 0) return true; }
    return false;
};

// ── Derive the card STATE + plain-language copy (never overclaim) ─────────────
$pending    = $has('cdn_pending_verify') || $has('htaccess_pending_verify');
$optimizing = $has('cdn_pending_orch');
$degraded   = $has('cdn_verify_failed') || $has('htaccess_verify_failed') || $has('override_');
$jpeg_forced_off = $has('next_gen_disabled_jpeg_only');
$wpc_ngd_cf_closed = in_array('cdn_edge_cloudflare_unkeyed', $warnings, true);

// The sub-line describes the METHOD (the "how"), not the value prop — the hero already states
// "best format per browser", so repeating it here is just noise.
if ($ceiling === 'off') {
    $state = 'off';   $badge = 'Off';        $method = 'Next-gen images are off'; $sub = 'Delivering optimized JPEG/PNG to every visitor.';
} elseif ($tier === WPC_Delivery_Resolver::TIER_CDN_EDGE) {
    $rt = isset($rv['redirect_target']) ? (string) $rv['redirect_target'] : 'samehost';
    if (!$images_on) {

        // CDN; next-gen is served from origin (path A <picture>). Don't claim CDN/fastest (a lie).
        $state = 'ok';    $badge = '✓ Active';   $method = 'Served from origin';   $sub = 'Images delivery is off — next-gen images are served from your origin, not the CDN.';
    } elseif ($rt === 'origin') {


        $wpc_cf_fronted = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']);
        $state = 'ok';    $badge = '✓ Verified'; $method = 'Edge negotiate';
        $sub = $wpc_cf_fronted
            ? 'Next-gen on clean URLs, no picture tags — the edge picks the format, Cloudflare serves the bytes.'
            : 'Next-gen on clean URLs, no picture tags — the edge picks the format, your origin serves the bytes.';
    } elseif ($override === 'edge') {

        // Automatic (CDN), but keep the CHOSEN mode visible so the radio and the status agree.
        $state = 'ok';    $badge = '✓ Verified'; $method = 'Edge negotiate';
        $sub = 'Next-gen on clean URLs, no picture tags — delivered & cached at the edge.';
    } else {
        $state = 'ok';    $badge = '✓ Verified'; $method = 'Automatic (CDN)';          $sub = 'Delivered & cached at the edge — fastest.';
    }
} elseif ($tier === WPC_Delivery_Resolver::TIER_HTACCESS) {
    $state = 'ok';    $badge = '✓ Verified'; $method = 'Automatic (server-level)'; $sub = 'Served by your server on clean URLs.';
} elseif ($tier === WPC_Delivery_Resolver::TIER_PICTURE) {


    $state  = 'ok';
    $badge  = '✓ Active';
    $method = 'Universal';
    $sub    = $optimizing
        ? 'Generating optimized versions in the background — switches to faster CDN delivery automatically once ready.'
        : 'Broadly compatible with every theme.';
} else { // JPEG floor
    $state  = $jpeg_forced_off ? 'warn' : 'off';
    $badge  = $jpeg_forced_off ? '⚠ Heads up' : 'Off';
    $method = $jpeg_forced_off ? 'Next-gen unavailable for visitors' : 'Optimized JPEG/PNG';
    $sub    = $jpeg_forced_off ? 'Variants exist but no delivery path is enabled — visitors get JPEG only.'
                               : 'No next-gen variants yet — they appear as images are optimized.';
}


// Formats the visitor gets when On — the best-first cascade AVIF → WebP → JPEG.


$formats = ($ceiling === 'off') ? ['JPEG'] : (($ceiling === 'webp') ? ['WebP', 'JPEG'] : ['AVIF', 'WebP', 'JPEG']);


$sel = ($ceiling === 'off') ? 'off' : 'on';
$nonce = wp_create_nonce('wps_ic_nonce_action');

$wpc_io_edge = ($tier === WPC_Delivery_Resolver::TIER_CDN_EDGE);
$wpc_io_floor = ($ceiling === 'off' || $tier >= WPC_Delivery_Resolver::TIER_JPEG);
$wpc_io_origin_bytes = ($wpc_io_edge && isset($rv['redirect_target']) && (string) $rv['redirect_target'] === 'origin');
$wpc_io_cdn_lane = $images_on && empty($caps['cdn_disabled'])
    && (!empty($caps['cdn_on']) || ($wpc_io_edge && !$wpc_io_origin_bytes));
$wpc_io_state = function_exists('wpc_sd_state') ? wpc_sd_state() : 'other';
$wpc_io_mode = function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : 'legacy';
$wpc_io_sd_on = ($wpc_io_mode === 'lazy_cdn');
$wpc_io_agency = defined('WPS_IC_AGENCY') && WPS_IC_AGENCY;

if ($wpc_io_floor) {
    $wpc_io_line = __('Visitors get your optimized JPEG and PNG files.', WPS_IC_TEXTDOMAIN);
} elseif ($wpc_io_cdn_lane) {
    $wpc_io_line = __('Visitors get AVIF or WebP from the CDN, sized for their screen.', WPS_IC_TEXTDOMAIN);
} elseif ($wpc_io_sd_on && !$wpc_io_agency) {
    $wpc_io_line = __('Visitors get AVIF or WebP from your server, sized as they view.', WPS_IC_TEXTDOMAIN);
} else {
    $wpc_io_line = __('Visitors get AVIF or WebP for your optimized images.', WPS_IC_TEXTDOMAIN);
}

if ($wpc_io_state === 'paused') {
    $wpc_io_paused_at = (int) get_option(WPC_SD_PAUSED_AT_OPTION, 0);
    $wpc_io_pill = ' is-warn';
    $wpc_io_pill_text = __('Paused', WPS_IC_TEXTDOMAIN);
} elseif ($ceiling === 'off') {
    $wpc_io_pill = ' is-off';
    $wpc_io_pill_text = __('Off', WPS_IC_TEXTDOMAIN);
} elseif ($wpc_io_floor) {
    $wpc_io_pill = ' is-warn';
    $wpc_io_pill_text = __('Limited', WPS_IC_TEXTDOMAIN);
} else {
    $wpc_io_pill = '';
    $wpc_io_pill_text = __('On', WPS_IC_TEXTDOMAIN);
}

$wpc_io_format_label = __('Format', WPS_IC_TEXTDOMAIN);
if ($wpc_io_floor) {
    $wpc_io_format = __('JPEG / PNG', WPS_IC_TEXTDOMAIN);
} elseif ($ceiling === 'webp') {
    $wpc_io_format = __('WebP', WPS_IC_TEXTDOMAIN);
} else {
    $wpc_io_format = __('Next-Gen', WPS_IC_TEXTDOMAIN);
    $wpc_io_format_label = __('Formats', WPS_IC_TEXTDOMAIN);
}

$wpc_io_count = isset($optimizedImages) ? (int) $optimizedImages : 0;
$wpc_io_avg = null;
$wpc_io_sizes = null;
if ($wpc_io_state !== 'other' && function_exists('wpc_sd_totals')) {
    $wpc_io_totals = wpc_sd_totals();
    $wpc_io_count = (int) $wpc_io_totals['images'];
    $wpc_io_avg = $wpc_io_totals['smaller'];
    $wpc_io_sizes = isset($wpc_io_totals['sizes']) ? (int) $wpc_io_totals['sizes'] : null;
} elseif ($wpc_io_count > 0 && isset($wpdb)) {
    if (function_exists('wpc_sd_totals')) {
        $wpc_io_legacy_totals = wpc_sd_totals();
        $wpc_io_sizes = !empty($wpc_io_legacy_totals['sizes']) ? (int) $wpc_io_legacy_totals['sizes'] : null;
    }
    $wpc_io_avg_raw = (float) $wpdb->get_var("SELECT AVG(CAST(meta_value AS DECIMAL(5,1))) FROM {$wpdb->postmeta} WHERE meta_key = 'ic_savings' AND meta_value > 0");
    $wpc_io_avg = $wpc_io_avg_raw > 0 ? (int) round($wpc_io_avg_raw) : null;
}

if ($wpc_io_state === 'paused') {
    $wpc_io_line = ($wpc_io_paused_at > 0
        ? sprintf(__('Paused on %s when you restored all images.', WPS_IC_TEXTDOMAIN), wp_date(get_option('date_format', 'F j, Y'), $wpc_io_paused_at))
        : __('Paused when you restored all images.', WPS_IC_TEXTDOMAIN))
        . ' ' . ($wpc_io_count > 0
        ? sprintf(_n('%s optimized image is still delivered; new images wait until you resume.', '%s optimized images are still delivered; new images wait until you resume.', $wpc_io_count, WPS_IC_TEXTDOMAIN), number_format_i18n($wpc_io_count))
        : __('Visitors get your original images; new images wait until you resume.', WPS_IC_TEXTDOMAIN));
}

$wpc_io_bulk_url = admin_url('options-general.php?page=' . (class_exists('wps_ic') && isset(wps_ic::$slug) ? wps_ic::$slug : 'wpcompress') . '&view=bulk');
?>
<div class="wpc-ngd-card wpc-ngd-state-<?php echo esc_attr($state); ?>" id="wpc-ngd-card" data-nonce="<?php echo esc_attr($nonce); ?>">

  <div class="wpc-ngd-control">
    <span class="wpc-ngd-title"><div class="circle-check<?php echo $sel === 'on' ? ' active' : ''; ?>" aria-hidden="true"></div><?php echo esc_html__('Next-Gen Images', WPS_IC_TEXTDOMAIN); ?></span>
    <label class="wpc-switch wpc-ngd-switch">
      <input type="checkbox" class="wpc-ngd-switch-input"<?php checked($sel === 'on'); ?> aria-label="<?php echo esc_attr__('Next-Gen Images', WPS_IC_TEXTDOMAIN); ?>">
      <span class="wpc-switch-slider wpc-switch-round"></span>
    </label>
  </div>

  <?php


  $gw = $ceiling !== 'off';
  $pw = $ceiling !== 'off';
  $pa = $ceiling === 'avif';
  ?>
  <div class="wpc-ngd-fields" style="display:none" aria-hidden="true">
    <?php


    ?>
    <input type="checkbox" class="wpc-ic-settings-v4-checkbox" name="options[generate_webp]" value="1" <?php checked($gw); ?>>
    <input type="checkbox" class="wpc-ic-settings-v4-checkbox" name="options[picture_webp]"  value="1" <?php checked($pw); ?>>
    <input type="checkbox" class="wpc-ic-settings-v4-checkbox" name="options[picture_avif]"  value="1" <?php checked($pa); ?>>
    <?php


    $wpc_saved_ng = strtolower((string) (get_option(WPS_IC_SETTINGS)['wpc_nextgen'] ?? ''));
    $wpc_ng_field = ($sel === 'off') ? 'off' : ($wpc_saved_ng === 'webp' ? 'webp' : 'auto');
    ?>
    <input type="hidden"   name="options[wpc_nextgen]"   value="<?php echo esc_attr($wpc_ng_field); ?>">
    <?php


    ?>
    <input type="hidden"   name="options[wpc_delivery_override]" value="<?php echo esc_attr($override); ?>">
  </div>

</div>
<?php
$wpc_io_icons = [
    'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'percent' => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
];
$wpc_io_icon = function ($name) use ($wpc_io_icons) {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $wpc_io_icons[$name] . '</svg>';
};
ob_start();
?>
<div class="wpc-tab-content-box wpc-card-rows wpc-perf-section wpc-ngd-status-section wpc-ngd-state-<?php echo esc_attr($state); ?>" id="nextgen-status">
  <div class="d-flex align-items-top gap-3 tab-title-checkbox">
    <div class="wpc-checkbox-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
    <div class="wpc-checkbox-description">
      <h4 class="fs-500 text-dark-300 fw-500 p-inline"><?php echo esc_html__('Delivery Status', WPS_IC_TEXTDOMAIN); ?><span class="wpc-ngd-pill<?php echo esc_attr($wpc_io_pill); ?>"><?php echo esc_html($wpc_io_pill_text); ?></span></h4>
      <p class="wpc-ngd-lede">
        <span class="wpc-ngd-line"><?php echo esc_html($wpc_io_line); ?></span>
        <?php if ($wpc_io_state === 'paused') : ?>
          <a class="wpc-ngd-link" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpc_sd_resume'), 'wpc_sd_resume')); ?>"><?php echo esc_html__('Resume Smart Delivery', WPS_IC_TEXTDOMAIN); ?></a>
        <?php elseif ($wpc_io_sd_on) : ?>
          <a class="wpc-ngd-link" href="<?php echo esc_url($wpc_io_bulk_url . '#wpc-sd-activity'); ?>"><?php echo esc_html__('See optimized images', WPS_IC_TEXTDOMAIN); ?></a>
        <?php else : ?>
          <a class="wpc-ngd-link" href="<?php echo esc_url($wpc_io_bulk_url); ?>"><?php echo esc_html__('Optimize Media Library', WPS_IC_TEXTDOMAIN); ?></a>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="wpc-perf-grid wpc-perf-grid-single">
  <div class="wpc-grid-full-width">
  <div class="wpc-ngd-status" id="wpc-ngd-status">

    <div class="wpc-ngd-tiles<?php echo $wpc_io_sizes !== null ? ' wpc-ngd-tiles--4' : ''; ?>">
      <div class="wpc-stat-tile wpc-ngd-stat"><span class="wpc-ngd-tile-icon"><?php echo $wpc_io_icon('image'); ?></span><div class="wpc-ngd-tile-text"><span class="wpc-stat-value wpc-ngd-stat-num"><?php echo esc_html(number_format_i18n($wpc_io_count)); ?></span><span class="wpc-stat-label wpc-ngd-stat-label"><?php echo esc_html__('Images optimized', WPS_IC_TEXTDOMAIN); ?></span></div></div>
      <?php if ($wpc_io_sizes !== null) : ?>
      <div class="wpc-stat-tile wpc-ngd-stat"><span class="wpc-ngd-tile-icon"><?php echo $wpc_io_icon('layers'); ?></span><div class="wpc-ngd-tile-text"><span class="wpc-stat-value wpc-ngd-stat-num"><?php echo esc_html(number_format_i18n($wpc_io_sizes)); ?></span><span class="wpc-stat-label wpc-ngd-stat-label"><?php echo esc_html__('Variants', WPS_IC_TEXTDOMAIN); ?></span></div></div>
      <?php endif; ?>
      <div class="wpc-stat-tile wpc-ngd-stat"><span class="wpc-ngd-tile-icon"><?php echo $wpc_io_icon('percent'); ?></span><div class="wpc-ngd-tile-text"><span class="wpc-stat-value wpc-ngd-stat-num"><?php echo $wpc_io_avg === null ? "\u{2014}" : esc_html(number_format_i18n((int) $wpc_io_avg) . '%'); ?></span><span class="wpc-stat-label wpc-ngd-stat-label"><?php echo esc_html__('Average savings', WPS_IC_TEXTDOMAIN); ?></span></div></div>
      <div class="wpc-stat-tile wpc-ngd-stat"><span class="wpc-ngd-tile-icon"><?php echo $wpc_io_icon('sparkle'); ?></span><div class="wpc-ngd-tile-text"><span class="wpc-stat-value wpc-ngd-stat-num"><?php echo esc_html($wpc_io_format); ?></span><span class="wpc-stat-label wpc-ngd-stat-label"><?php echo esc_html($wpc_io_format_label); ?></span></div></div>
    </div>

    <?php // ── HERO — the value prop leads the section: best format per browser, automatically ──

    ?>
      <div class="wpc-ngd-hero"<?php echo $ceiling === 'off' ? ' style="display:none"' : ''; ?> title="<?php echo esc_attr__('Each visitor is served the single best format their browser supports; older browsers automatically fall back to the next one.', WPS_IC_TEXTDOMAIN); ?>">
        <span class="wpc-ngd-hero-label"><?php echo esc_html__('Every visitor gets the best format their browser supports', WPS_IC_TEXTDOMAIN); ?></span>
        <div class="wpc-ngd-formats">
          <?php foreach ($formats as $i => $f) : ?>
            <?php if ($i > 0) : ?><span class="wpc-ngd-arrow" aria-hidden="true">&rarr;</span><?php endif; ?>
            <span class="wpc-ngd-fmt<?php echo $i === 0 ? ' is-primary' : ''; ?>"><?php echo esc_html($f); ?></span>
          <?php endforeach; ?>
        </div>
      </div>

    <div class="wpc-ngd-method-card">
    <div class="wpc-ngd-row"<?php echo ($wpc_ngd_cf_closed && $tier === WPC_Delivery_Resolver::TIER_PICTURE) ? ' style="flex-wrap:wrap"' : ''; ?>>
      <span class="wpc-ngd-method"><?php echo esc_html($method); ?></span>
      <span class="wpc-ngd-badge"><?php echo esc_html($badge); ?></span>
      <?php if ($wpc_ngd_cf_closed && $tier === WPC_Delivery_Resolver::TIER_PICTURE) : ?>
        <span class="wpc-ngd-chip" style="display:inline-flex;align-items:center;font-family:'proxima_semibold',sans-serif;font-size:12px;line-height:1;padding:4px 10px;border-radius:30px;background:#f1f5f9;color:#64748b;" title="<?php echo esc_attr__('Cloudflare caches one copy per image URL, so each browser\'s best format is chosen with picture tags.', WPS_IC_TEXTDOMAIN); ?>"><?php echo esc_html__('Switched to Universal for Cloudflare', WPS_IC_TEXTDOMAIN); ?></span>
      <?php endif; ?>
    </div>
    <div class="wpc-ngd-sub"><?php echo esc_html($sub); ?></div>

    <?php if ($state === 'warn' && $jpeg_forced_off) : ?>
      <div class="wpc-ngd-fix">
        <?php echo esc_html__('No delivery method is active for next-gen images — open Advanced to pick one.', WPS_IC_TEXTDOMAIN); ?>
      </div>
    <?php endif; ?>

    <?php


    $wpc_ngd_caps    = (isset($rv['capabilities']) && is_array($rv['capabilities'])) ? $rv['capabilities'] : [];
    $wpc_ngd_edge_ok = !empty($rv['verify']['cdn']['ok']) && !empty($wpc_ngd_caps['edge_available']) && !empty($wpc_ngd_caps['cdn_on']);
    $wpc_ngd_on_edge = (isset($rv['tier_name']) && $rv['tier_name'] === 'cdn-edge');
    if ($override !== 'auto' && !$wpc_ngd_on_edge && $wpc_ngd_edge_ok && !$wpc_ngd_cf_closed) :
        $wpc_ngd_ov_lbls  = ['auto' => 'Auto (recommended)', 'picture' => 'Picture tags', 'htaccess' => 'Server-level', 'cdn' => 'CDN edge', 'edge' => 'Edge negotiate'];
        $wpc_ngd_ov_label = isset($wpc_ngd_ov_lbls[$override]) ? $wpc_ngd_ov_lbls[$override] : $override;
    ?>
      <div class="wpc-ngd-forced" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#eff7ff;border:1px solid #bfdbfe;color:#314b72;font-size:13px;line-height:1.55;">
        <strong><?php echo esc_html__('Pinned by an Advanced override.', WPS_IC_TEXTDOMAIN); ?></strong>
        <?php printf(
            esc_html__('This site is forced to “%s”, so it isn’t using single-URL CDN-edge delivery — even though the edge is verified and available here. “Re-check now” re-verifies but keeps your chosen method. To use the CDN edge, set Delivery Method (Advanced, below) to “Auto (recommended).”', WPS_IC_TEXTDOMAIN),
            esc_html($wpc_ngd_ov_label)
        ); ?>
      </div>
    <?php endif; ?>

    <div class="wpc-ngd-meta">
      <button type="button" class="wpc-ngd-recheck"><?php echo esc_html__('Re-check now', WPS_IC_TEXTDOMAIN); ?></button>
      <span class="wpc-ngd-spinner" hidden aria-hidden="true"></span>
    </div>
    </div>

    <details class="wpc-ngd-advanced">
      <summary><?php echo esc_html__('Advanced', WPS_IC_TEXTDOMAIN); ?></summary>
      <p class="wpc-ngd-adv-note"><?php echo esc_html__('Delivery method is chosen automatically and verified safe. Force one only if needed (it’s still verified before use):', WPS_IC_TEXTDOMAIN); ?></p>
      <div class="wpc-ngd-overrides">
        <?php foreach (['auto' => 'Auto (recommended)', 'picture' => 'Picture tags', 'htaccess' => 'Server-level', 'cdn' => 'CDN edge', 'edge' => 'Edge negotiate'] as $val => $lbl) : ?>
          <label class="wpc-ngd-ov<?php echo $override === $val ? ' is-active' : ''; ?>">
            <input type="radio" name="wpc_ngd_override" value="<?php echo esc_attr($val); ?>" <?php checked($override, $val); ?>>
            <?php echo esc_html($lbl); ?>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if ($override === 'edge') : ?>
        <p class="wpc-ngd-adv-note"><?php echo esc_html__('Edge negotiate: next-gen images, no picture tags required — works with CDN on or off. With CDN off, your server serves the bytes (no CDN bandwidth); the edge only picks each visitor\'s best format.', WPS_IC_TEXTDOMAIN); ?></p>
      <?php endif; ?>
      <div class="wpc-ngd-diag">
        <code><?php echo esc_html('tier=' . (isset($rv['tier_name']) ? $rv['tier_name'] : '?') . ' · ' . (isset($rv['reason']) ? $rv['reason'] : '')); ?></code>
        <?php if ($warnings) : ?><code><?php echo esc_html(implode(' · ', $warnings)); ?></code><?php endif; ?>
      </div>
    </details>
  </div>
  </div>
  </div>
</div>
<?php
$wpc_ngd_status_html = ob_get_clean();
ob_start();
?>
  <script>
  (function () {
    var card = document.getElementById('wpc-ngd-card');
    var st = document.getElementById('wpc-ngd-status');
    if (!card || !st || card.dataset.bound) return; card.dataset.bound = '1';
    var nonce = card.getAttribute('data-nonce');
    var sp = st.querySelector('.wpc-ngd-spinner');
    function post(data, cb) {
      if (sp) sp.hidden = false;
      data.nonce = nonce; data.action = data.action || 'wpc_delivery_save';
      var body = new URLSearchParams();
      body.append('action', data.action); body.append('wps_ic_nonce', nonce);
      if (data.mode) body.append('mode', data.mode);
      if (data.override) body.append('override', data.override);
      fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()})
        .then(function (r) { return r.json(); })
        .then(function () { if (!data.noReload) window.location.reload(); })
        .catch(function () { if (sp) sp.hidden = true; });
    }
    // Mirror the segmented choice onto the hidden options[...] checkboxes (source of truth for the
    // main settings save + the preset JS), fire native change so delegated handlers pick it up.
    function mirror(mode) {
      var f = card.querySelector('.wpc-ngd-fields');
      if (!f) return;
      var cb = f.querySelectorAll('input[type=checkbox]'); // [generate_webp, picture_webp, picture_avif]
      if (cb[0]) cb[0].checked = (mode !== 'off');
      if (cb[1]) cb[1].checked = (mode !== 'off');
      if (cb[2]) cb[2].checked = (mode === 'auto');
      var ng = f.querySelector('input[name="options[wpc_nextgen]"]'); if (ng) ng.value = mode;
      cb.forEach(function (c) { c.dispatchEvent(new Event('change', {bubbles: true})); });
      // Live dot state — identical contract to every other settings box (grey off / brand-blue on).
      var dot = card.querySelector('.circle-check');
      if (dot) dot.classList.toggle('active', mode !== 'off');
    }
    var sw = card.querySelector('.wpc-ngd-switch-input');
    // v7.01.22 — optimistic FULL-card preview. The switch used to update only the dot, leaving
    // the hero/status showing the old state until the post-save reload (a confusing mixed state).
    // Now the whole card flips to an honest "Unsaved" preview (we can't claim a verified method
    // until Save runs the verify), and restores the real rendered state if toggled back.
    var ngdInit = sw ? {
      on:   sw.checked,
      hero: (function (h) { return h ? h.style.display : ''; })(st.querySelector('.wpc-ngd-hero')),
      fmts: (function (f) { return f ? f.innerHTML : ''; })(st.querySelector('.wpc-ngd-formats')),
      m:    (st.querySelector('.wpc-ngd-method') || {}).textContent || '',
      b:    (st.querySelector('.wpc-ngd-badge')  || {}).textContent || '',
      s:    (st.querySelector('.wpc-ngd-sub')    || {}).textContent || ''
    } : null;
    function preview(on) {
      var hero = st.querySelector('.wpc-ngd-hero');
      var fmts = st.querySelector('.wpc-ngd-formats');
      var meth = st.querySelector('.wpc-ngd-method');
      var badge = st.querySelector('.wpc-ngd-badge');
      var sub = st.querySelector('.wpc-ngd-sub');
      if (ngdInit && on === ngdInit.on) { // back to the saved state → restore the real card
        if (hero) hero.style.display = ngdInit.hero;
        if (fmts) fmts.innerHTML = ngdInit.fmts;
        if (meth) meth.textContent = ngdInit.m;
        if (badge) badge.textContent = ngdInit.b;
        if (sub) sub.textContent = ngdInit.s;
        return;
      }
      if (hero) hero.style.display = on ? '' : 'none';
      // The chips were server-rendered for the SAVED state — a card saved Off shows only JPEG,
      // which contradicts the pending-On headline. Preview the real On cascade.
      if (on && fmts) {
        fmts.innerHTML = '<span class="wpc-ngd-fmt is-primary">AVIF</span>'
          + '<span class="wpc-ngd-arrow" aria-hidden="true">→</span><span class="wpc-ngd-fmt">WebP</span>'
          + '<span class="wpc-ngd-arrow" aria-hidden="true">→</span><span class="wpc-ngd-fmt">JPEG</span>';
      }
      if (meth) meth.textContent = on ? 'On — not saved yet' : 'Off — not saved yet';
      if (badge) badge.textContent = 'Unsaved';
      if (sub) sub.textContent = on
        ? 'Click Save to apply — the best delivery method is verified automatically.'
        : 'Click Save to apply — visitors will get optimized JPEG/PNG.';
    }
    // v7.01.22 — cascade entrance: the chips fill in sequentially (AVIF → arrow → WebP → …)
    // whenever the hero appears: on page load with the card On, and on every flip to On.
    function cascade() {
      var f = st.querySelector('.wpc-ngd-formats');
      if (!f) return;
      f.classList.remove('is-cascading');
      void f.offsetWidth; // reflow → restart the animation
      f.classList.add('is-cascading');
    }
    if (sw && sw.checked) cascade();
    if (sw) sw.addEventListener('change', function () {
      // NATIVE saving: the switch only mirrors onto the hidden options[...] fields; the standard
      // Save pill detects the diff, persists via the same checkbox-batch endpoint as every other
      // toggle, and reloads (wpc_nextgen is in its reload-keys list) to render the verified state.
      var mode = sw.checked ? 'auto' : 'off';
      mirror(mode);
      preview(sw.checked);
      if (sw.checked) cascade();
      if (window.checkUnsavedChanges) window.checkUnsavedChanges();
      else if (window.showSaveButton) window.showSaveButton();
    });
    // v7.01.91 — Advanced-override DEFERS to the Save bar (parity with the switch). On a radio
    // change we (1) mirror the value into the hidden options[wpc_delivery_override] field the v4
    // collector reads, (2) move the .is-active pill + show an "Unsaved" preview, (3) raise the
    // Save bar. NO auto-save: the standard batch save (wps_ic_ajax_v2_checkbox_batch) persists the
    // override, re-verifies, and reloads (wpc_delivery_override is in tabs.js cfReloadSettings) to
    // the freshly-verified state. The previous post()→wpc_delivery_save→instant-reload is gone.
    var ovField = card.querySelector('input[name="options[wpc_delivery_override]"]');
    var ovInit  = ovField ? ovField.value : 'auto';
    // v7.01.92 — STATE-CORRECT preview. The card's method/badge text must reflect the CURRENT
    // pending state, not the last click. The bug: clicking Picture tags then back to Auto left the
    // text stuck on "Picture tags — not saved yet" because the old revert branch was gated on the
    // switch being untouched. Now we recompute from scratch every change: if EITHER the override
    // OR the switch differs from its saved value → show the honest "<that control> — not saved yet"
    // / Unsaved preview; if BOTH are back at their saved values → restore the real server-rendered
    // method + badge. So returning to Auto (the saved override) with the switch untouched fully
    // clears the unsaved preview.
    function refreshOverridePreview() {
      var meth  = st.querySelector('.wpc-ngd-method');
      var badge = st.querySelector('.wpc-ngd-badge');
      if (!meth || !badge) return;
      var ovNow      = ovField ? ovField.value : ovInit;
      var ovDirty    = (ovNow !== ovInit);
      var switchDirty = !!(sw && ngdInit && sw.checked !== ngdInit.on);
      if (!ovDirty && !switchDirty) {
        // Nothing pending → restore the real verified card text.
        meth.textContent  = ngdInit ? ngdInit.m : meth.textContent;
        badge.textContent = ngdInit ? ngdInit.b : badge.textContent;
        return;
      }
      // Something is pending → honest unsaved preview. Prefer the override label if the override is
      // the dirty one; otherwise the switch handler's own preview text already set On/Off — leave it.
      if (ovDirty) {
        var selLab = st.querySelector('.wpc-ngd-ov.is-active');
        var ovLabel = selLab ? (selLab.textContent || '').trim() : ovNow;
        meth.textContent = (ovLabel || ovNow) + ' — not saved yet';
      }
      badge.textContent = 'Unsaved';
    }
    st.querySelectorAll('input[name="wpc_ngd_override"]').forEach(function (r) {
      r.addEventListener('change', function () {
        if (!r.checked) return;
        if (ovField) ovField.value = r.value;
        // Move the selected pill highlight to the chosen radio (mirrors the server is-active class).
        st.querySelectorAll('.wpc-ngd-ov').forEach(function (lbl) { lbl.classList.remove('is-active'); });
        var lab = r.closest('.wpc-ngd-ov'); if (lab) lab.classList.add('is-active');
        refreshOverridePreview();
        if (window.checkUnsavedChanges) window.checkUnsavedChanges();
        else if (window.showSaveButton) window.showSaveButton();
      });
    });
    var rc = st.querySelector('.wpc-ngd-recheck');
    if (rc) rc.addEventListener('click', function () { post({action: 'wpc_delivery_recheck'}); });
  })();
  </script>
<?php
$wpc_ngd_status_html .= ob_get_clean();
