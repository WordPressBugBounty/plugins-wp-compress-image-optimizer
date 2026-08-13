<?php

if (function_exists('apply_filters') && !apply_filters('wpc_optimize_advisory_card', true)) {
    return;
}
if (!function_exists('current_user_can') || !current_user_can('manage_wpc_settings')) {
    return;
}
$wpc_oa_nonce   = wp_create_nonce('wps_ic_nonce_action');
$wpc_oa_ajaxurl = admin_url('admin-ajax.php');


$wpc_oa_apikey = '';
if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {


    if (function_exists('get_query_var')) {
        $wpc_oa_apikey = (string) sanitize_text_field(get_query_var('apikey'));
    }
    if ($wpc_oa_apikey === '') {
        global $wps_ic;
        if (!empty($wps_ic) && method_exists($wps_ic, 'extractApiKey')) {
            $wpc_oa_apikey = (string) $wps_ic->extractApiKey();
        }
    }


    if ($wpc_oa_apikey === '') {
        return;
    }
}
?>
<div class="wpc-optimize-advisory" id="wpc-optimize-advisory">
    <div class="wpc-oa-head">
        <div class="wpc-oa-titlewrap">
            <h3 class="wpc-oa-title"><span class="wpc-oa-ic" aria-hidden="true"><svg width="16" height="13" viewBox="0 0 640 512" xmlns="http://www.w3.org/2000/svg"><!--! Font Awesome Pro 7.2.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2026 Fonticons, Inc. --><path fill="currentColor" d="M288 112l-24-56-56-24 56-24 24-56 24 56 56 24-56 24-24 56zM492-21.9c2.1 2.1 31.8 31.8 89 89l17 17-17 17-416 416-17 17c-2.1-2.1-31.8-31.8-89-89l-17-17 17-17 416-416 17-17zM109.9 428L148 466.1 386.1 228 348 189.9 109.9 428zM492 45.9L381.9 156 420 194.1 530.1 84 492 45.9zM96 96l32-80 32 80 80 32-80 32-32 80-32-80-80-32 80-32zM384 400l80-32 32-80 32 80 80 32-80 32-32 80-32-80-80-32z"/></svg></span><?php echo esc_html__('Auto Mode', WPS_IC_TEXTDOMAIN); ?>
                <span class="wpc-oa-beta">BETA</span></h3>
        </div>
        <div class="wpc-oa-headctas">
            <label class="wpc-oa-autowrap" title="<?php echo esc_attr__('Measure, apply safe recommendations (Used CSS, local fonts), verify, re-measure — hands-free. Every change is journaled and one click reverts them all.', WPS_IC_TEXTDOMAIN); ?>">
                <span class="wpc-oa-autolabel" style="display:none"><?php echo esc_html__('Auto Mode', WPS_IC_TEXTDOMAIN); ?></span>
                <span class="wpc-oa-switch"><input type="checkbox" id="wpc-oa-auto" <?php checked(function_exists('wpc_auto_mode_on') ? wpc_auto_mode_on() : get_option('wpc_auto_mode') === '1'); ?>><span class="wpc-oa-slider"></span></span>
            </label>
            <button type="button" class="wpc-oa-run" id="wpc-oa-run" style="display:none">
                <span class="wpc-oa-run-label"><?php echo esc_html__('Run advisory scan', WPS_IC_TEXTDOMAIN); ?></span>
            </button>
            <?php if (defined('WPC_PERF_DEBUG_UI') && WPC_PERF_DEBUG_UI) : ?>
            <a class="wpc-oa-bench" id="wpc-oa-bench" target="_blank" rel="noopener noreferrer"
               href="<?php echo esc_url(admin_url('admin-ajax.php?action=wpc_perf_debug&url=' . rawurlencode(home_url('/')))); ?>"
               title="<?php echo esc_attr__('Opens the performance diagnostic in a new tab. Mints this site\'s benchmark key, arms cacheable tier arms, and prints ready-made tier URLs.', WPS_IC_TEXTDOMAIN); ?>">
                <?php echo esc_html__('Perf diagnostic', WPS_IC_TEXTDOMAIN); ?>
            </a>
            <?php endif; ?>
            <?php if (get_option('wpc_link_preset_applied')) : ?>
            <button type="button" class="wpc-oa-safemode" id="wpc-oa-safemode"
                title="<?php echo esc_attr__('Revert the Link-and-Go preset (Critical CSS, Used CSS, JS Delay, Advanced Cache, local fonts) to conservative values and purge the cache. Your other settings are untouched.', WPS_IC_TEXTDOMAIN); ?>">
                <?php echo esc_html__('Safe mode', WPS_IC_TEXTDOMAIN); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="wpc-oa-auto-strip" id="wpc-oa-auto-strip" style="display:none;"></div>
    <div class="wpc-oa-body">
        <div class="wpc-oa-idle" id="wpc-oa-idle" style="display:none">
            <?php echo esc_html__('Run a scan to measure mobile PageSpeed and see exactly what will move the score — and who owns each fix.', WPS_IC_TEXTDOMAIN); ?>
        </div>
        <div class="wpc-oa-status" id="wpc-oa-status" style="display:none;"></div>
        <div class="wpc-oa-results" id="wpc-oa-results" style="display:none;"></div>
    </div>
    <div class="wpc-oa-toast" id="wpc-oa-toast" style="display:none;"></div>
</div>

<style>
/* Scoped Performance Advisory card. Design-system colors (CLAUDE.md var reference), no leakage. */
.wpc-optimize-advisory{position:relative;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;
  box-shadow:0 4px 6px -1px rgba(0,0,0,.02);padding:22px 24px;margin:0;
  font-family:'proxima_regular',sans-serif;color:#1e293b}
.wpc-optimize-advisory *{box-sizing:border-box}
.wpc-oa-head{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}
.wpc-oa-titlewrap{display:flex;align-items:center}
.wpc-oa-title{font-family:'proxima_bold',sans-serif;font-size:18px;line-height:1.2;margin:0;color:#1e293b;
  display:flex;align-items:center;gap:10px}
.wpc-oa-ic{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:10px;
  background:linear-gradient(135deg,#59a8e3,#4273f0);color:#ffffff;flex:0 0 auto}
.wpc-oa-headctas{display:flex;align-items:center}

.wpc-oa-beta{font-family:'proxima_semibold',sans-serif;font-size:10px;letter-spacing:.4px;color:#4c89eb;
  background:#eff7ff;border:1px solid #bfdbfe;border-radius:30px;padding:2px 8px;line-height:1.4}
.wpc-oa-sub{font-size:13px;color:#64748b;margin:0;max-width:640px}
.wpc-oa-run{flex:0 0 auto;cursor:pointer;border:none;border-radius:8px;padding:11px 20px;
  font-family:'proxima_semibold',sans-serif;font-size:14px;color:#fff;
  background:linear-gradient(90deg,#4c89eb,#4273f0);transition:all .2s ease;min-height:44px}
.wpc-oa-run:hover{filter:brightness(1.05)}
.wpc-oa-run[disabled]{opacity:.6;cursor:default;filter:none}
.wpc-oa-safemode{flex:0 0 auto;cursor:pointer;border:1px solid #e2e8f0;border-radius:8px;padding:11px 16px;
  font-family:'proxima_semibold',sans-serif;font-size:13px;color:#64748b;background:#ffffff;
  transition:all .2s ease;min-height:44px}
.wpc-oa-safemode:hover{color:#991b1b;border-color:#fecaca;background:#fef2f2}
.wpc-oa-bench{flex:0 0 auto;cursor:pointer;border:1px solid #e2e8f0;border-radius:8px;padding:11px 16px;
  font-family:'proxima_semibold',sans-serif;font-size:13px;color:#64748b;background:#ffffff;
  transition:all .2s ease;min-height:44px;display:inline-flex;align-items:center;
  text-decoration:none;box-sizing:border-box}
.wpc-oa-bench:hover,.wpc-oa-bench:focus{color:#4273f0;border-color:#bfdbfe;background:#eff7ff;text-decoration:none}
.wpc-oa-bench:focus-visible{outline:2px solid #4273f0;outline-offset:2px}
.wpc-oa-body{margin-top:0}
.wpc-oa-body > div{margin-top:16px}
.wpc-oa-idle{font-size:13px;color:#64748b;background:#f1f5f9;border:1px dashed #c5d3e3;border-radius:8px;
  padding:16px 18px}
.wpc-oa-status{display:flex;align-items:center;gap:12px;font-size:14px;color:#475569;
  background:#eff7ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px}
.wpc-oa-status.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.wpc-oa-spin{width:18px;height:18px;border:2px solid #bfdbfe;border-top-color:#4c89eb;border-radius:50%;
  animation:wpcoaspin .7s linear infinite;flex:0 0 auto}
@keyframes wpcoaspin{to{transform:rotate(360deg)}}
/* score header */
.wpc-oa-scorewrap{display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin-bottom:6px}
.wpc-oa-score{display:flex;flex-direction:column;align-items:center;justify-content:center;
  width:96px;height:96px;border-radius:50%;flex:0 0 auto}
.wpc-oa-score b{font-family:'proxima_bold',sans-serif;font-size:34px;line-height:1}
.wpc-oa-score span{font-size:11px;letter-spacing:.5px;text-transform:uppercase;margin-top:3px;opacity:.85}
.wpc-oa-chiprow{display:flex;flex-wrap:wrap;gap:8px;flex:1 1 260px}
.wpc-oa-chip{font-size:12px;font-family:'proxima_semibold',sans-serif;border-radius:8px;padding:7px 11px;
  border:1px solid #e2e8f0;background:#fafafa;color:#374151;display:flex;flex-direction:column;min-width:64px}
.wpc-oa-chip small{font-size:10px;font-family:'proxima_regular',sans-serif;color:#94a3b8;text-transform:uppercase;
  letter-spacing:.4px;margin-bottom:2px}
.wpc-oa-chip.g{border-color:#22b73a;background:#effbf1;color:#137a24}
.wpc-oa-chip.a{border-color:#fbae40;background:#fef7ed;color:#9a6400}
.wpc-oa-chip.r{border-color:#ef5a5a;background:#fef2f2;color:#991b1b}
/* recommendation + residual rows */
.wpc-oa-sechead{font-family:'proxima_semibold',sans-serif;font-size:13px;color:#1e293b;margin:18px 0 8px}
.wpc-oa-row{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid #e2e8f0;
  border-radius:8px;margin-bottom:8px;background:#fff}
.wpc-oa-row.resid{background:#fafafa;opacity:.85}
.wpc-oa-badge{flex:0 0 auto;font-family:'proxima_semibold',sans-serif;font-size:11px;color:#4c89eb;
  background:#eff7ff;border:1px solid #bfdbfe;border-radius:8px;padding:5px 8px;text-align:center;min-width:56px}
.wpc-oa-row.resid .wpc-oa-badge{color:#6b7280;background:#eff1f4;border-color:#d1d5db}
.wpc-oa-rowmain{flex:1 1 auto;min-width:0}
.wpc-oa-action{font-family:'proxima_semibold',sans-serif;font-size:13px;color:#1e293b;margin:0 0 2px;
  line-height:1.35}
.wpc-oa-evi{font-size:12px;color:#64748b;margin:0}
.wpc-oa-cta{flex:0 0 auto;align-self:center}
.wpc-oa-apply{cursor:pointer;border:none;border-radius:8px;padding:8px 14px;font-family:'proxima_semibold',sans-serif;
  font-size:13px;color:#fff;background:#4c89eb;transition:all .2s ease;min-height:36px}
.wpc-oa-apply:hover{filter:brightness(1.05)}
.wpc-oa-owner{font-size:11px;font-family:'proxima_semibold',sans-serif;padding:5px 10px;border-radius:30px;
  white-space:nowrap}
.wpc-oa-owner.plugin{color:#4c89eb;background:#eff7ff;border:1px solid #bfdbfe}
.wpc-oa-owner.hosting{color:#9a6400;background:#fef7ed;border:1px solid #fbae40}
.wpc-oa-notes{margin:14px 0 0;padding:0;list-style:none}
.wpc-oa-notes li{font-size:12px;color:#64748b;padding:4px 0 4px 16px;position:relative}
.wpc-oa-notes li:before{content:"•";position:absolute;left:2px;color:#a0aab6}
.wpc-oa-empty{font-size:12px;color:#94a3b8;padding:2px 0}
.wpc-oa-stamp{font-size:11px;color:#94a3b8;margin-top:12px}
/* toast */
.wpc-oa-toast{position:absolute;left:24px;right:24px;bottom:14px;background:#1e293b;color:#fff;font-size:13px;
  padding:11px 16px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.18);z-index:5}
/* Auto Mode toggle + strip */
.wpc-oa-headctas{display:flex;align-items:center;gap:16px;flex:0 0 auto;flex-wrap:wrap}
.wpc-oa-autowrap{display:flex;align-items:center;gap:9px;cursor:pointer;user-select:none}
.wpc-oa-autolabel{font-family:'proxima_semibold',sans-serif;font-size:13px;color:#1e293b;display:flex;
  align-items:center;gap:6px}
.wpc-oa-switch{position:relative;display:inline-block;width:44px;height:24px;flex:0 0 auto}
.wpc-oa-switch input{opacity:0;width:0;height:0;position:absolute}
.wpc-oa-slider{position:absolute;inset:0;background:#c5d3e3;border-radius:30px;transition:all .2s ease}
.wpc-oa-slider:before{content:"";position:absolute;left:3px;top:3px;width:18px;height:18px;background:#fff;
  border-radius:50%;transition:all .2s ease;box-shadow:0 1px 2px rgba(0,0,0,.15)}
.wpc-oa-switch input:checked + .wpc-oa-slider{background:linear-gradient(90deg,#4c89eb,#4273f0)}
.wpc-oa-switch input:checked + .wpc-oa-slider:before{transform:translateX(20px)}
.wpc-optimize-advisory{width:100%;max-width:100%}
.wpc-settings-content-inner:has(> .wpc-optimize-advisory),
.wpc-settings-content-inner:has(#wpc-optimize-advisory){width:100%!important;max-width:100%!important;flex:1 1 100%!important}
.wpc-oa-auto-strip ul,.wpc-oa-auto-strip ol,.wpc-oa-auto-strip li{display:none!important}
.wpc-oa-auto-strip{display:none!important}
.wpc-oa-toast{display:none!important}
.wpc-oa-auto-strip{margin-top:14px;border:1px solid #bfdbfe;background:#eff7ff;border-radius:8px;
  padding:12px 16px;font-size:12px;color:#475569}
.wpc-oa-auto-strip .wpc-oa-astate{font-family:'proxima_semibold',sans-serif;font-size:13px;color:#1e293b;
  display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.wpc-oa-auto-strip.done{border-color:#22b73a;background:#effbf1}
.wpc-oa-auto-strip.err{border-color:#fecaca;background:#fef2f2}
.wpc-oa-ajournal{margin:8px 0 0;padding:0;list-style:none}
.wpc-oa-ajournal li{padding:3px 0 3px 14px;position:relative;color:#64748b}
.wpc-oa-ajournal li:before{content:"›";position:absolute;left:2px;color:#a0aab6}
.wpc-oa-arevert{color:#991b1b;font-family:'proxima_semibold',sans-serif;cursor:pointer;
  text-decoration:underline;margin-left:auto}
@media screen and (max-width:640px){
  .wpc-oa-run{width:100%}
  .wpc-oa-bench{width:100%;justify-content:center}
  .wpc-oa-row{flex-wrap:wrap}
  .wpc-oa-cta{width:100%}
  .wpc-oa-apply{width:100%}
}
</style>

<script>
(function () {
    var CFG = {
        ajaxurl: <?php echo wp_json_encode($wpc_oa_ajaxurl); ?>,
        nonce:   <?php echo wp_json_encode($wpc_oa_nonce); ?>,
        // Agency portal only. Every action below mutates site state, so without this the
        // portal would run Auto Mode / safe mode against ITSELF instead of the client site.
        apikey:  <?php echo wp_json_encode($wpc_oa_apikey); ?>,
        pollMs: 4000,
        maxPolls: 30 // ~2 min ceiling; a real run finishes in ~15-20s
    };
    // rule id -> the wp-admin setting the operator flips (owner:auto only). The tab is DERIVED from the
    // DOM (walk to .wpc-tab-content), so no brittle tab map — if the setting isn't on the page we toast.
    var RULE_SETTING = { R2: 'used-css', R3: 'replace-fonts' };

    var root = document.getElementById('wpc-optimize-advisory');
    if (!root) return;
    var btn    = document.getElementById('wpc-oa-run');
    var idle   = document.getElementById('wpc-oa-idle');
    var statusEl = document.getElementById('wpc-oa-status');
    var results  = document.getElementById('wpc-oa-results');
    var toastEl  = document.getElementById('wpc-oa-toast');
    var toastT;

    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function ms(v){ v = Number(v)||0; return v >= 1000 ? (v/1000).toFixed(1)+'s' : Math.round(v)+'ms'; }
    function band(v,g,a){ v = Number(v); return v <= g ? 'g' : (v <= a ? 'a' : 'r'); }
    function scoreColors(s){
        s = Number(s)||0;
        if (s >= 90) return {bg:'#effbf1', fg:'#137a24', bd:'#22b73a'};
        if (s >= 50) return {bg:'#fef7ed', fg:'#9a6400', bd:'#fbae40'};
        return {bg:'#fef2f2', fg:'#991b1b', bd:'#ef5a5a'};
    }
    function toast(msg){
        toastEl.textContent = msg; toastEl.style.display = 'block';
        clearTimeout(toastT); toastT = setTimeout(function(){ toastEl.style.display = 'none'; }, 4200);
    }
    function showStatus(html, isErr){
        idle.style.display = 'none'; results.style.display = 'none';
        statusEl.className = 'wpc-oa-status' + (isErr ? ' err' : '');
        statusEl.innerHTML = html; statusEl.style.display = 'flex';
    }
    function setRunning(on){
        btn.disabled = !!on;
        btn.querySelector('.wpc-oa-run-label').textContent = on ? 'Scanning…' : 'Run advisory scan';
    }
    function post(action, extra){
        var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(CFG.nonce);
        if (CFG.apikey) body += '&apikey=' + encodeURIComponent(CFG.apikey);
        for (var k in (extra||{})) body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
        return fetch(CFG.ajaxurl, {
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body
        }).then(function(r){ return r.json(); });
    }

    function start(){
        setRunning(true);
        showStatus('<span class="wpc-oa-spin"></span><span>Starting advisory scan…</span>', false);
        post('wps_ic_optimize_start', { desktop: 0 }).then(function(res){
            if (res && res.success && res.data && res.data.run_id){
                poll(res.data.run_id, 0);
            } else {
                fail((res && res.data && res.data.msg) ? res.data.msg : 'Could not start the scan.');
            }
        }).catch(function(){ fail('Network error starting the scan.'); });
    }

    function poll(runId, n){
        if (n >= CFG.maxPolls){ fail("Couldn't measure in time — please retry."); return; }
        showStatus('<span class="wpc-oa-spin"></span><span>Measuring… (' + (n+1) + ')</span>', false);
        post('wps_ic_optimize_status', { run_id: runId }).then(function(res){
            if (!res || !res.success){
                // transient/network hiccup → keep trying unless the run is truly gone
                if (res && res.data && res.data.gone){ fail(res.data.msg || 'This run expired — start a new scan.'); return; }
                return setTimeout(function(){ poll(runId, n+1); }, CFG.pollMs);
            }
            var d = res.data || {};
            var state = d.state || (d.status === 'not_found' ? 'pending' : '');
            if (state === 'done'){ setRunning(false); render(d.report || d); return; }
            if (state === 'failed'){ fail("Couldn't measure this page" + (d.step ? ' (' + esc(d.step) + ')' : '') + ' — please retry.'); return; }
            setTimeout(function(){ poll(runId, n+1); }, CFG.pollMs); // pending | running
        }).catch(function(){ setTimeout(function(){ poll(runId, n+1); }, CFG.pollMs); });
    }

    function fail(msg){
        setRunning(false);
        showStatus('<span>⚠ ' + esc(msg) + '</span>', true); // never render a fake 0
    }

    function chip(label, val, cls){
        return '<div class="wpc-oa-chip ' + cls + '"><small>' + esc(label) + '</small>' + esc(val) + '</div>';
    }

    function render(report){
        report = report || {};
        var scores = report.scores || {};
        var m = report.metrics || {};
        var recs = report.recommendations || [];
        var resids = report.residuals || [];
        var notes = report.notes || [];
        var html = '';

        // score + metrics header
        var sc = scoreColors(scores.mobile);
        html += '<div class="wpc-oa-scorewrap">';
        html += '<div class="wpc-oa-score" style="background:' + sc.bg + ';border:3px solid ' + sc.bd + ';color:' + sc.fg + '">'
              + '<b>' + (scores.mobile != null ? Math.round(scores.mobile) : '—') + '</b><span>Mobile</span></div>';
        if (scores.desktop != null){
            var sd = scoreColors(scores.desktop);
            html += '<div class="wpc-oa-score" style="background:' + sd.bg + ';border:3px solid ' + sd.bd + ';color:' + sd.fg + '">'
                  + '<b>' + Math.round(scores.desktop) + '</b><span>Desktop</span></div>';
        }
        html += '<div class="wpc-oa-chiprow">';
        if (m.fcp  != null) html += chip('FCP',  ms(m.fcp),  band(m.fcp,1800,3000));
        if (m.lcp  != null) html += chip('LCP',  ms(m.lcp),  band(m.lcp,2500,4000));
        if (m.tbt  != null) html += chip('TBT',  ms(m.tbt),  band(m.tbt,200,600));
        if (m.cls  != null) html += chip('CLS',  (Number(m.cls) < 0.001 ? '0' : Number(m.cls).toFixed(3)), band(m.cls,0.1,0.25));
        if (m.si   != null) html += chip('SI',   ms(m.si),   band(m.si,3400,5800));
        if (m.ttfb != null) html += chip('TTFB', ms(m.ttfb), band(m.ttfb,800,1800));
        html += '</div></div>';

        // recommendations
        html += '<div class="wpc-oa-sechead">Recommendations</div>';
        if (!recs.length){
            html += '<div class="wpc-oa-empty">Nothing actionable — this page is already well optimized. 🎉</div>';
        } else {
            recs.forEach(function(r){
                var owner = (r.owner || '').toLowerCase();
                html += '<div class="wpc-oa-row">';
                html += '<div class="wpc-oa-badge">' + esc(r.rule || '') + '<br>' + esc(r.est_pts || '') + '</div>';
                html += '<div class="wpc-oa-rowmain"><p class="wpc-oa-action">' + esc(r.action || '') + '</p>'
                      + (r.evidence ? '<p class="wpc-oa-evi">' + esc(r.evidence) + '</p>' : '') + '</div>';
                if (owner === 'auto'){
                    html += '<div class="wpc-oa-cta"><button type="button" class="wpc-oa-apply" '
                          + 'data-rule="' + esc(r.rule || '') + '" data-action="' + esc(r.action || '') + '">Apply</button></div>';
                } else if (owner === 'hosting'){
                    html += '<div class="wpc-oa-cta"><span class="wpc-oa-owner hosting">Your host</span></div>';
                } else {
                    html += '<div class="wpc-oa-cta"><span class="wpc-oa-owner plugin">Plugin — auto</span></div>';
                }
                html += '</div>';
            });
        }

        // residuals
        if (resids.length){
            html += '<div class="wpc-oa-sechead">Residuals — owned, not one-click</div>';
            resids.forEach(function(r){
                html += '<div class="wpc-oa-row resid">';
                html += '<div class="wpc-oa-badge">' + esc(r.rule || '') + (r.est_pts ? '<br>' + esc(r.est_pts) : '') + '</div>';
                html += '<div class="wpc-oa-rowmain"><p class="wpc-oa-action">' + esc(r.detail || r.action || '') + '</p></div>';
                html += '<div class="wpc-oa-cta"><span class="wpc-oa-owner ' + (((r.owner||'').toLowerCase()==='hosting')?'hosting':'plugin') + '">'
                      + esc(r.owner || 'owned') + '</span></div>';
                html += '</div>';
            });
        }

        if (notes.length){
            html += '<ul class="wpc-oa-notes">';
            notes.forEach(function(n){ html += '<li>' + esc(n) + '</li>'; });
            html += '</ul>';
        }
        if (report.generated_at){
            html += '<div class="wpc-oa-stamp">Measured ' + esc(report.generated_at) + (report.mode ? ' · ' + esc(report.mode) : '') + '</div>';
        }

        idle.style.display = 'none'; statusEl.style.display = 'none';
        results.innerHTML = html; results.style.display = 'block';
    }

    // [Apply] — advisory only: deep-link the operator to the named setting (P1 never auto-flips).
    function applyRule(rule, action){
        var name = RULE_SETTING[rule];
        var el = name ? (document.querySelector('[name="' + name + '"]') || document.getElementById(name)) : null;
        if (el){
            var tabPane = el.closest ? el.closest('.wpc-tab-content') : null;
            if (tabPane && tabPane.id){
                var nav = document.querySelector('a[data-tab="' + tabPane.id + '"]');
                if (nav) nav.click();
            }
            setTimeout(function(){
                var target = el.closest ? (el.closest('.wpc-card-row, .wpc-ic-settings-v4-row, .wpc-settings-row') || el) : el;
                if (target.scrollIntoView) target.scrollIntoView({behavior:'smooth', block:'center'});
                var flash = target.style ? target : el;
                var prev = flash.style.boxShadow;
                flash.style.transition = 'box-shadow .3s ease';
                flash.style.boxShadow = '0 0 0 3px rgba(59,130,246,.35)';
                setTimeout(function(){ flash.style.boxShadow = prev || ''; }, 2200);
            }, 260);
            toast('Opened the setting — ' + action);
        } else {
            toast(action + ' — find it in WP Compress settings.');
        }
    }

    btn.addEventListener('click', start);
    results.addEventListener('click', function(e){
        var b = e.target.closest ? e.target.closest('.wpc-oa-apply') : null;
        if (b) applyRule(b.getAttribute('data-rule'), b.getAttribute('data-action') || '');
    });

    // ── Auto Mode  toggle + status strip + journal + revert ──
    var autoCb   = document.getElementById('wpc-oa-auto');
    var strip    = document.getElementById('wpc-oa-auto-strip');
    var autoT;
    var STATE_TEXT = {
        starting:  'Starting — arming the page…',
        arming:    'Arming — generating critical CSS…',
        measuring: 'Measuring PageSpeed…',
        settling:  'Applied — waiting for caches to settle, then re-measuring…',
        converged: 'Converged',
        done:      'Finished',
        failed:    'Stopped',
        idle:      'Waiting…'
    };
    function autoRender(d){
        if (!d || !d.on){ strip.style.display = 'none'; return; }
        var st = d.state || {}, j = d.journal || [];
        var cls = (st.status === 'converged' || st.status === 'done') ? ' done' : (st.status === 'failed' ? ' err' : '');
        var head = STATE_TEXT[st.status] || esc(st.status || '');
        if (st.status === 'converged' || st.status === 'done'){
            head += ' — mobile ' + (st.last_score != null ? Math.round(st.last_score) : '—')
                  + (st.baseline != null && st.last_score != null && st.baseline !== st.last_score
                      ? ' (from ' + Math.round(st.baseline) + ')' : '')
                  + ' · ' + esc(st.msg || '') + ' · cycle ' + (st.cycle || 0) + '/3';
        } else if (st.status === 'failed'){
            head += ' — ' + esc(st.msg || '') + ' (toggle off/on to retry)';
        } else if (st.cycle){
            head += ' · cycle ' + st.cycle + '/3';
        }
        var html = '<div class="wpc-oa-astate"><span>🤖 ' + head + '</span>';
        var hasApplies = j.some(function(e){ return e.event === 'auto-apply'; });
        if (hasApplies) html += '<span class="wpc-oa-arevert" id="wpc-oa-arevert">Revert Auto changes</span>';
        html += '</div>';
        if (j.length){
            html += '<ul class="wpc-oa-ajournal">';
            j.slice(0, 8).forEach(function(e){
                var when = e.t ? new Date(e.t * 1000).toLocaleTimeString() : '';
                var line = e.event;
                if (e.event === 'auto-apply')  line = 'Applied ' + e.rule + ' (' + e.key + ' → ' + e.to + ')' + (e.score != null ? ' at score ' + Math.round(e.score) : '');
                if (e.event === 'auto-revert') line = 'Reverted ' + e.rule + ' (score ' + Math.round(e.score) + ' vs ' + Math.round(e.was) + ') — frozen';
                if (e.event === 'converged')   line = 'Converged at ' + (e.score != null ? Math.round(e.score) : '—') + (e.baseline != null ? ' (baseline ' + Math.round(e.baseline) + ')' : '') + ' after ' + e.cycles + ' cycle(s)';
                if (e.event === 'measure-start') line = 'Measuring (cycle ' + e.cycle + ')…';
                html += '<li>' + esc(when) + ' — ' + esc(line) + '</li>';
            });
            html += '</ul>';
        }
        strip.className = 'wpc-oa-auto-strip' + cls;
        strip.innerHTML = html; strip.style.display = 'block';
        var rv = document.getElementById('wpc-oa-arevert');
        if (rv) rv.addEventListener('click', function(){
            if (!window.confirm('Revert every setting Auto Mode changed and turn it off?')) return;
            post('wpc_auto_mode_revert', {}).then(function(res){
                toast(res && res.success ? ('Reverted ' + res.data.restored + ' setting(s). Auto Mode off.') : 'Revert failed.');
                autoCb.checked = false; autoPoll();
            });
        });
        // keep polling while the loop is live
        clearTimeout(autoT);
        if (['starting','arming','measuring','settling','idle'].indexOf(st.status) !== -1){
            autoT = setTimeout(autoPoll, 15000);
        }
    }
    function autoPoll(){
        post('wpc_auto_mode_status', {}).then(function(res){
            if (res && res.success) autoRender(res.data);
        }).catch(function(){});
    }
    if (autoCb){
        autoCb.addEventListener('change', function(){
            var on = autoCb.checked;
            post('wpc_auto_mode_set', { on: on ? 1 : 0 }).then(function(res){
                if (!res || !res.success){ autoCb.checked = !on; toast('Could not update Auto Mode.'); return; }
                toast(on ? 'Auto Mode on — measuring in the background. Leave this page open or come back later.' : 'Auto Mode off.');
                autoPoll();
            }).catch(function(){ autoCb.checked = !on; toast('Network error.'); });
        });
        if (autoCb.checked) autoPoll();
    }
    var safeBtn = document.getElementById('wpc-oa-safemode');
    if (safeBtn) safeBtn.addEventListener('click', function(){
        if (!window.confirm('Safe mode reverts the Link-and-Go preset (Critical CSS, Used CSS, JS Delay, Advanced Cache, local fonts) to conservative values, turns Auto Mode off and purges the cache. Your other settings are untouched. Continue?')) return;
        safeBtn.disabled = true;
        post('wpc_link_safe_mode', {}).then(function(res){
            safeBtn.disabled = false;
            if (res && res.success && res.data && res.data.safe_mode){
                toast('Safe mode applied — preset levers reverted, cache purged.');
                if (autoCb) autoCb.checked = false;
                autoPoll();
            } else {
                toast('Safe mode failed — check the journal.');
            }
        }).catch(function(){ safeBtn.disabled = false; toast('Safe mode request failed.'); });
    });
})();
</script>
