









(function () {
    'use strict';
    var C = window.wpcVitals;
    if (!C || !C.u || !C.t || !window.webVitals || !navigator.sendBeacon) { return; }
    
    
    
    
    if (navigator.webdriver === true) { return; }
    var s = Number(C.s) || 1;
    if (s > 1 && Math.random() >= 1 / s) { return; } 
    var M = {}, sent = false;
    
    
    
    
    var HU = false;
    ['pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll', 'mousemove'].forEach(function (ev) {
        addEventListener(ev, function () { HU = true; }, { once: true, passive: true, capture: true });
    });

    function nav() {
        try { return performance.getEntriesByType('navigation')[0] || null; } catch (e) { return null; }
    }
    
    
    
    
    
    
    
    
    
    
    
    
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
        if (/Safari\//.test(ua) && /Version\//.test(ua)) { return 's'; } 
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
        if (!HU) { return; }
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
    
    
    addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { flush(); } }, true);
    addEventListener('pagehide', flush, true);
    addEventListener('pageshow', function (ev) { if (ev.persisted) { sent = false; M = {}; } }, true);
})();
