/**
 * WPC RUM collector glue . Rides AFTER the vendored web-vitals IIFE (Apache-2.0,
 * GoogleChrome/web-vitals v5) in the same inline block — CrUX-exact metric semantics (session-
 * window CLS, interaction-grouped INP, bfcache/prerender handling) come from the library, never
 * hand-rolled. Privacy by architecture: NO cookie, NO storage, NO identifier, NO IP anywhere —
 * one sendBeacon per pageview carrying only metric values + device/engine/cache-layer flags.
 * Config arrives via window.wpcVitals {u: endpoint, t: token = sha1(salt), s: sample
 * denominator, m: mint epoch frozen into the copy at emit time}.
 * Client-side sampling (Math.random) so sampled-out views cost the server NOTHING.
 */
(function () {
    'use strict';
    var C = window.wpcVitals;
    if (!C || !C.u || !C.t || !window.webVitals || !navigator.sendBeacon) { return; }
    // Automated sessions are not users: navigator.webdriver is the W3C-standard flag automation
    // tools set about THEMSELVES (WebDriver spec: "so that alternate code paths can be triggered
    // during automation"). Keeps lab/monitor traffic out of the field panel without inspecting
    // anything about the client — no UA reads, no tool detection, same page for everyone.
    if (navigator.webdriver === true) { return; }
    var s = Number(C.s) || 1;
    if (s > 1 && Math.random() >= 1 / s) { return; } // sampled out — zero cost
    var M = {}, sent = false;

    function nav() {
        try { return performance.getEntriesByType('navigation')[0] || null; } catch (e) { return null; }
    }
    // cache-layer flag, three signals in priority order:
    //  1. wpc-cache;desc=hit — origin cache serves say so directly (PHP drop-in, cacheHtml,
    //     static mirror .htaccess). Survives CF edge: stored headers replay.
    //  2. wpc-mint;desc=<epoch> — every edge-cacheable RENDER freezes its mint time into the
    //     stored copy's headers. A replay whose mint is older than 300s is a shared-cache
    //     serve even though the copy was minted by a render. A fresh header mint is a render
    //     verdict — return 0 there, never fall through.
    //  3. C.m — the same mint epoch frozen INTO the copy's bytes beside this collector.
    //     WebKit never shipped PerformanceServerTiming (all of iOS reads n.serverTiming as
    //     undefined), and some proxies strip Server-Timing — the copy's own age is the one
    //     signal every browser sees on every serve path. 300s absorbs clock skew; the error
    //     direction is undercount (a young cached copy reads rendered), never overclaim.
    function cacheHit() {
        try {
            var n = nav();
            if (n && n.serverTiming && n.serverTiming.length) {
                var mint = 0;
                for (var i = 0; i < n.serverTiming.length; i++) {
                    var st = n.serverTiming[i];
                    if (st.name === 'wpc-cache') { return st.description === 'hit' ? 1 : 0; }
                    if (st.name === 'wpc-mint') { mint = Number(st.description) || 0; }
                }
                if (mint > 0) { return (Date.now() / 1000) - mint > 300 ? 1 : 0; }
            }
            var m = Number(C.m) || 0;
            if (m > 0 && (Date.now() / 1000) - m > 300) { return 1; }
        } catch (e) { }
        return 0;
    }
    function engine() {
        var ua = navigator.userAgent;
        if (/Firefox\//.test(ua)) { return 'f'; }
        if (/Chrome\/|Chromium\/|Edg\//.test(ua)) { return 'c'; }
        if (/Safari\//.test(ua) && /Version\//.test(ua)) { return 's'; } // real Safari (buggy INP — segmented server-side)
        return 'o';
    }
    var reg = function (n) { return function (m) { M[n] = m.value; }; };
    try {
        webVitals.onLCP(reg('lcp'));
        webVitals.onCLS(reg('cls'));
        webVitals.onINP(reg('inp'));
        webVitals.onTTFB(reg('ttfb'));
        webVitals.onFCP(reg('fcp'));
    } catch (e) { }

    function flush() {
        if (sent) { return; }
        sent = true;
        try {
            var p = 'v=1&t=' + encodeURIComponent(C.t)
                + '&d=' + (Math.min(window.innerWidth || 1024, window.screen && screen.width || 1024) < 768 ? 'm' : 'd')
                + '&e=' + engine()
                + '&h=' + cacheHit();
            if (M.lcp != null) { p += '&lcp=' + Math.round(M.lcp); }
            if (M.cls != null) { p += '&cls=' + Math.round(M.cls * 1000); }
            if (M.inp != null) { p += '&inp=' + Math.round(M.inp); }
            if (M.ttfb != null) { p += '&ttfb=' + Math.round(M.ttfb); }
            if (M.fcp != null) { p += '&fcp=' + Math.round(M.fcp); }
            navigator.sendBeacon(C.u, new Blob([p], { type: 'text/plain' }));
        } catch (e) { }
    }
    // hidden = THE reliable mobile+desktop flush point; pagehide = the belt. bfcache restore
    // re-arms for a fresh view (web-vitals re-reports its metrics with new values).
    addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { flush(); } }, true);
    addEventListener('pagehide', flush, true);
    addEventListener('pageshow', function (ev) { if (ev.persisted) { sent = false; M = {}; } }, true);
})();
