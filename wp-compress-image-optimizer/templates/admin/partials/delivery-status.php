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
        $sub = 'Next-gen on clean URLs, no picture tags — delivered &amp; cached at the edge.';
    } else {
        $state = 'ok';    $badge = '✓ Verified'; $method = 'Automatic (CDN)';          $sub = 'Delivered &amp; cached at the edge — fastest.';
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

  <div class="wpc-ngd-status">

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

    <div class="wpc-ngd-row">
      <span class="wpc-ngd-method"><?php echo esc_html($method); ?></span>
      <span class="wpc-ngd-badge"><?php echo esc_html($badge); ?></span>
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
    if ($override !== 'auto' && !$wpc_ngd_on_edge && $wpc_ngd_edge_ok) :
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

  <script>
  (function () {
    var card = document.getElementById('wpc-ngd-card');
    if (!card || card.dataset.bound) return; card.dataset.bound = '1';
    var nonce = card.getAttribute('data-nonce');
    var sp = card.querySelector('.wpc-ngd-spinner');
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
      hero: (function (h) { return h ? h.style.display : ''; })(card.querySelector('.wpc-ngd-hero')),
      fmts: (function (f) { return f ? f.innerHTML : ''; })(card.querySelector('.wpc-ngd-formats')),
      m:    (card.querySelector('.wpc-ngd-method') || {}).textContent || '',
      b:    (card.querySelector('.wpc-ngd-badge')  || {}).textContent || '',
      s:    (card.querySelector('.wpc-ngd-sub')    || {}).textContent || ''
    } : null;
    function preview(on) {
      var hero = card.querySelector('.wpc-ngd-hero');
      var fmts = card.querySelector('.wpc-ngd-formats');
      var meth = card.querySelector('.wpc-ngd-method');
      var badge = card.querySelector('.wpc-ngd-badge');
      var sub = card.querySelector('.wpc-ngd-sub');
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
      var f = card.querySelector('.wpc-ngd-formats');
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
      var meth  = card.querySelector('.wpc-ngd-method');
      var badge = card.querySelector('.wpc-ngd-badge');
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
        var selLab = card.querySelector('.wpc-ngd-ov.is-active');
        var ovLabel = selLab ? (selLab.textContent || '').trim() : ovNow;
        meth.textContent = (ovLabel || ovNow) + ' — not saved yet';
      }
      badge.textContent = 'Unsaved';
    }
    card.querySelectorAll('input[name="wpc_ngd_override"]').forEach(function (r) {
      r.addEventListener('change', function () {
        if (!r.checked) return;
        if (ovField) ovField.value = r.value;
        // Move the selected pill highlight to the chosen radio (mirrors the server is-active class).
        card.querySelectorAll('.wpc-ngd-ov').forEach(function (lbl) { lbl.classList.remove('is-active'); });
        var lab = r.closest('.wpc-ngd-ov'); if (lab) lab.classList.add('is-active');
        refreshOverridePreview();
        if (window.checkUnsavedChanges) window.checkUnsavedChanges();
        else if (window.showSaveButton) window.showSaveButton();
      });
    });
    var rc = card.querySelector('.wpc-ngd-recheck');
    if (rc) rc.addEventListener('click', function () { post({action: 'wpc_delivery_recheck'}); });
  })();
  </script>
</div>
