








(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var cfg = window.wpcRWBeacon || {};
    if (!cfg.ajaxurl) {
        return;
    }

    
    var rate = (typeof cfg.sample === 'number') ? cfg.sample : 1;
    if (rate < 1 && Math.random() > rate) {
        return;
    }

    function collect() {
        var imgs = document.querySelectorAll(
            'img.wps-ic-live-cdn, img.wps-ic-cdn, img[src*="/wp:"], img[src*="/wp-content/uploads/"]'
        );
        var dpr = window.devicePixelRatio || 1;
        var seen = {};
        var out = [];

        for (var i = 0; i < imgs.length && out.length < 50; i++) {
            var el = imgs[i];
            var rect = el.getBoundingClientRect();
            var rendered = Math.round(rect.width * dpr);
            if (rendered <= 0) {
                continue; 
            }
            var url = el.currentSrc || el.src || '';
            if (!url || seen[url]) {
                continue;
            }
            seen[url] = 1;
            out.push({ u: url, r: rendered, n: el.naturalWidth || 0, d: dpr });
        }
        return out;
    }

    function send() {
        var samples = collect();
        if (!samples.length) {
            return;
        }
        var payload = 'action=wpc_v2_rw_beacon' +
            '&nonce=' + encodeURIComponent(cfg.nonce || '') +
            '&samples=' + encodeURIComponent(JSON.stringify(samples));

        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(
                    cfg.ajaxurl,
                    new Blob([payload], { type: 'application/x-www-form-urlencoded' })
                );
                return;
            }
        } catch (e) {  }

        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.ajaxurl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send(payload);
        } catch (e2) {  }
    }

    
    function schedule() { setTimeout(send, 800); }
    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule);
    }
})();
