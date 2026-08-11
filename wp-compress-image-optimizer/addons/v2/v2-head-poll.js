(function () {
    "use strict";
    if (typeof window === "undefined" || !window.jQuery) return;
    var $ = window.jQuery;
    var ajaxurl = (window.wpc_ajaxVar && window.wpc_ajaxVar.ajaxurl) || window.ajaxurl;
    var nonce = (window.wpc_ajaxVar && window.wpc_ajaxVar.nonce) || "";
    if (!ajaxurl) return;

    
    
    function isFatalStatus(xhr) {
        var s = (xhr && xhr.status) || 0;
        return s === 400 || s === 401 || s === 403 || s === 404 || s === 410;
    }
    
    
    var MAX_FAILS = 6;
    var BACKOFF_BASE_MS = 5e3;
    var BACKOFF_CAP_MS = 3e5;
    function backoffMs(n) {
        return Math.min(BACKOFF_CAP_MS, BACKOFF_BASE_MS * Math.pow(2, Math.max(0, n - 1)));
    }
    function surfaceVisible() {
        var $s = $(".wpc-bulk-v2-surface, .wpc-restore-surface");
        return !!($s.length && $s.filter(":visible").length);
    }

    
    
    
    
    
    
    
    var LEASE_KEY = "wpc_hp_lease", BCAST_KEY = "wpc_hp_bcast";
    var LEASE_TTL_MS = 35e3;    
    var LEASE_RETRY_MS = 1e4;   
    var TAB_ID = String(Math.random()).slice(2) + "." + Date.now();
    var lsOk = (function () {
        try {
            window.localStorage.setItem("wpc_hp_probe", "1");
            window.localStorage.removeItem("wpc_hp_probe");
            return true;
        } catch (e) { return false; }
    })();
    function leaseHeld() {
        if (!lsOk) return true;
        try {
            var now = Date.now();
            var raw = window.localStorage.getItem(LEASE_KEY);
            var cur = raw ? JSON.parse(raw) : null;
            if (cur && cur.id !== TAB_ID && (now - (cur.t || 0)) < LEASE_TTL_MS) return false;
            window.localStorage.setItem(LEASE_KEY, JSON.stringify({ id: TAB_ID, t: now }));
            
            
            var back = JSON.parse(window.localStorage.getItem(LEASE_KEY) || "{}");
            return back.id === TAB_ID;
        } catch (e) { return true; }
    }
    function leaseDrop() {
        if (!lsOk) return;
        try {
            var raw = window.localStorage.getItem(LEASE_KEY);
            if (raw && JSON.parse(raw).id === TAB_ID) window.localStorage.removeItem(LEASE_KEY);
        } catch (e) {}
    }
    function fire(name, detail) {
        try { window.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {}
    }
    
    var bcastSeq = 0;
    function publish(names, detail) {
        for (var i = 0; i < names.length; i++) fire(names[i], detail);
        if (!lsOk) return;
        try {
            window.localStorage.setItem(BCAST_KEY, JSON.stringify({
                s: ++bcastSeq, id: TAB_ID, t: Date.now(), names: names, detail: detail
            }));
        } catch (e) {}
    }
    if (lsOk) {
        
        $(window).on("storage", function (e) {
            var ev = e.originalEvent || e;
            if (!ev || ev.key !== BCAST_KEY || !ev.newValue) return;
            var m = null;
            try { m = JSON.parse(ev.newValue); } catch (x) { return; }
            if (!m || m.id === TAB_ID || !m.names || !m.names.length) return;
            for (var i = 0; i < m.names.length; i++) fire(m.names[i], m.detail || {});
        });
    }

    
    var TICK_ACTIVE_MS = 5e3, TICK_IDLE_MS = 3e4;
    var actStopped = false, actFails = 0, actTimer = null;
    function actSchedule(ms) {
        if (actStopped) return;
        if (actTimer) clearTimeout(actTimer);
        actTimer = setTimeout(actTick, ms);
    }
    function actTick() {
        if (actStopped) return;
        
        if (!leaseHeld()) { actSchedule(LEASE_RETRY_MS); return; }
        $.ajax({
            url: ajaxurl, type: "POST", timeout: 6e3,
            data: { action: "wps_ic_check_customer_activity", wps_ic_nonce: nonce },
            success: function (resp) {
                var d = (resp && resp.data) || {};
                if (d.enabled === false) { actStopped = true; return; }
                actFails = 0;
                if (d.busted) {
                    publish(["wpc:bulk-counts-busted"], d);
                    pullOnce(0);
                }
                actSchedule(surfaceVisible() ? TICK_ACTIVE_MS : TICK_IDLE_MS);
            },
            error: function (xhr) {
                if (isFatalStatus(xhr)) { actStopped = true; return; }
                actFails++;
                if (actFails >= MAX_FAILS) { actStopped = true; return; }
                actSchedule(backoffMs(actFails));
            }
        });
    }

    
    
    
    var PULL_WAIT_MS = 2.5e3, PULL_DRAIN_MS = 8e2, PULL_IDLE_MS = 1.2e4;
    var pullStopped = false, pullFails = 0, pullTimer = null, pullInFlight = false;
    function pullSchedule(ms) {
        if (pullStopped) return;
        if (pullTimer) clearTimeout(pullTimer);
        pullTimer = setTimeout(function () { pullRun(PULL_WAIT_MS); }, ms);
    }
    function pullOnce(wait) { if (!pullStopped && !pullInFlight) pullRun(wait); }
    function pullRun(wait) {
        if (pullStopped || pullInFlight) return;
        if (!leaseHeld()) { pullSchedule(LEASE_RETRY_MS); return; }
        pullInFlight = true;
        $.ajax({
            url: ajaxurl, type: "POST", timeout: (wait || 0) + 6e3,
            data: { action: "wps_ic_pull_manifest", wps_ic_nonce: nonce, wait_ms: wait || 0, limit: 100 },
            success: function (resp) {
                pullInFlight = false;
                var d = (resp && resp.data) || {};
                if (d.enabled === false) { pullStopped = true; return; }
                pullFails = 0;
                var queued = d.queued || 0;
                if (queued > 0) {
                    publish(["wpc:pull-manifest-landed", "wpc:bulk-counts-busted"], d);
                }
                pullSchedule(queued > 0 ? PULL_DRAIN_MS : PULL_IDLE_MS);
            },
            error: function (xhr) {
                pullInFlight = false;
                if (isFatalStatus(xhr)) { pullStopped = true; return; }
                pullFails++;
                if (pullFails >= MAX_FAILS) { pullStopped = true; return; }
                pullSchedule(backoffMs(pullFails));
            }
        });
    }

    $(function () {
        actSchedule(2e3);
        pullSchedule(3e3);
    });
    
    
    $(window).on("beforeunload pagehide", function () {
        actStopped = true; pullStopped = true;
        if (actTimer) clearTimeout(actTimer);
        if (pullTimer) clearTimeout(pullTimer);
        leaseDrop();
    });
})();
