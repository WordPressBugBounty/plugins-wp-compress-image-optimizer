!function() {
    "use strict";
    
    
    !function() {
        try {
            var mk = function(v) {
                return /maps\.googleapis\.com\/maps\/api\/js/.test(String(v)) && /[?&]key=(?:&|$)/.test(String(v));
            }, ce = document.createElement, sd = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, "src");
            
            
            
            
            
            
            var pp = function(v) {
                try {
                    var an = ce.call(document, "a");
                    an.href = String(v);
                    return an.pathname || "";
                } catch (z) {
                    return "";
                }
            };
            var pk = function(el, v) {
                try {
                    if (window.__wpcRel186) return false;
                    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.parkEscape === 0) return false;
                    var r = window.wpcScriptRegistry;
                    if (!Array.isArray(r) || !r.length) return false;
                    if (!window.__wpcPk186) {
                        var s = {}, i, x, p;
                        for (i = 0; i < r.length; i++) {
                            x = r[i] && r[i].src;
                            if (!x) continue;
                            if (r[i].encoded) {
                                try { x = atob(x); } catch (z2) { continue; }
                            }
                            p = pp(x);
                            if (p && p !== "/") s[p] = 1;
                        }
                        window.__wpcPk186 = s;
                        window.__wpcPkQ186 = [];
                        window.wpcParkFlush186 = function() {
                            window.__wpcRel186 = 1;
                            var q = window.__wpcPkQ186 || [];
                            window.__wpcPkQ186 = [];
                            for (var j = 0; j < q.length; j++) {
                                try { sd.set.call(q[j][0], q[j][1]); } catch (z3) {}
                            }
                        };
                    }
                    var p2 = pp(v);
                    if (p2 && p2 !== "/" && window.__wpcPk186[p2]) {
                        window.__wpcPkQ186.push([el, v]);
                        return true;
                    }
                } catch (z) {}
                return false;
            };
            document.createElement = function(tag) {
                var el = ce.apply(document, arguments);
                if (sd && String(tag).toLowerCase() === "script") try {
                    Object.defineProperty(el, "src", {
                        configurable: true,
                        get: function() { return sd.get.call(el); },
                        set: function(v) { if (!mk(v) && !pk(el, v)) sd.set.call(el, v); }
                    });
                    var sa = el.setAttribute;
                    el.setAttribute = function(n, v) {
                        if (String(n).toLowerCase() === "src" && (mk(v) || pk(el, v))) return;
                        return sa.apply(el, arguments);
                    };
                } catch (e) {}
                return el;
            };
        } catch (e) {}
    }();
    
    
    
    
    
    
    
    
    
    !function() {
        try {
            if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.rootGuard === 0) return;
            var g = document.createElement("style");
            g.id = "wpc-root-guard";
            g.textContent = 'html[style*="display: none"],html[style*="display:none"]{display:block!important}';
            (document.head || document.documentElement).appendChild(g);
        } catch (e) {}
    }();
    function e() {
        if ("undefined" != typeof DEBUG) try {
            console.log("%c[SCRIPT-DELAY]", "background:#f0ad4e;color:#000", [].slice.call(arguments).join(" "));
        } catch {}
    }
    function t(e, t) {
        if (!e) return e;
        if (!t) return e;
        try {
            for (var r = atob(e), n = new Uint8Array(r.length), a = 0; a < r.length; a++) n[a] = r.charCodeAt(a);
            return new TextDecoder("utf-8").decode(n);
        } catch (t) {
            return e;
        }
    }
    function r(e, t) {
        if (t) for (var r in t) try {
            e.setAttribute(r, t[r]);
        } catch (e) {}
    }
    function n(e) {
        return e && (!0 === e.async || e.attributes && "async" in e.attributes);
    }
    function a(e) {
        return e && e.tagName && ("SCRIPT" === e.tagName || "LINK" === e.tagName);
    }
    
    
    
    var wpcCdnH = "", wpcZoneDn = 0;
    try {
        wpcCdnH = window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.cdnHost ? String(window.wpcDelayV3Cfg.cdnHost) : "";
    } catch (e) {}
    try {
        wpcZoneDn = sessionStorage.getItem("wpcJsZoneDown") ? 1 : 0;
    } catch (e) {}
    function wpcOriginOf(u) {
        if (!wpcCdnH || !u || u.indexOf("//" + wpcCdnH) === -1) return "";
        var p = u.indexOf("//" + wpcCdnH) + 2 + wpcCdnH.length, rest = u.slice(p);
        
        
        
        var m = /^\/(?:m:[01]\/|font:true\/)?a:(.+)$/.exec(rest);
        if (m) {
            var o = m[1];
            if (o.indexOf("//") === 0) o = "https:" + o;
            if (o.indexOf("http") !== 0) o = location.origin + "/" + o.replace(/^\/+/, "");
            return o;
        }
        return location.origin + rest;
    }
    function wpcZoneFail() {
        wpcZoneDn = 1;
        try {
            sessionStorage.setItem("wpcJsZoneDown", "1");
        } catch (e) {}
    }
    function wpcJsDecode(s, en) {
        return t(s, en);
    }
    function wpcJsSrc(u) {
        if (wpcZoneDn) {
            var o = wpcOriginOf(u);
            if (o) return o;
        }
        return u;
    }
    function wpcFbErr(s, p) {
        return function() {
            var o = wpcOriginOf(s.src);
            if (!o || s.getAttribute("data-wpc-fb")) return p();
            wpcZoneFail();
            var n = s.cloneNode(false);
            
            
            n.async = s.async, n.defer = s.defer,
            n.src = o, n.setAttribute("data-wpc-fb", "1"),
            y.call(n, "load", p, { once: !0 }), y.call(n, "error", p, { once: !0 }),
            s.parentNode ? s.parentNode.replaceChild(n, s) : (document.head || document.documentElement).appendChild(n);
        };
    }
    var o = 0, c = !1, i = !1, l = "loading", d = !1, s = !1, wpcRp111 = !1, u = [], p = {
        load: [],
        DOMContentLoaded: [],
        readystatechange: [],
        pageshow: [],
        visibilitychange: []
    }, y = EventTarget.prototype.addEventListener, f = EventTarget.prototype.removeEventListener, h = EventTarget.prototype.dispatchEvent;
    document.readyState;
    
    
    
    
    
    
    
    
    
    var wpcRealRS = (function() {
        try {
            var rg = Object.getOwnPropertyDescriptor(Document.prototype, "readyState").get;
            rg.call(document);
            return function() { return rg.call(document); };
        } catch (e) {
            return function() { return l; };
        }
    })();
    Object.defineProperty(document, "readyState", {
        get: function() {
            return wpcRealRS();
        }
    });
    var g = window.jQuery, m = [], v = !1;
    if (window.WPC_STRICT_ORDER = !!window.WPC_STRICT_ORDER, function() {
        try {
            var e = document.write.bind(document);
            document.write = function() {
                return window.WPC_STRICT_ORDER = !0, e.apply(document, arguments);
            };
        } catch (e) {}
    }(), EventTarget.prototype.addEventListener = function(t, r, n) {
        if (("load" === t || "error" === t) && a(this)) return y.call(this, t, r, n);
        var o = !1;
        return ("DOMContentLoaded" !== t || d) && ("load" !== t || s) ? "readystatechange" === t && "complete" !== l ? o = !0 : ("pageshow" === t || "visibilitychange" === t) && !wpcRp111 && (o = !0) : o = !0,
        o && t in p ? (e("Intercepting event listener for:", t), void p[t].push({
            target: this,
            listener: r,
            options: n
        })) : y.call(this, t, r, n);
    }, EventTarget.prototype.dispatchEvent = function(t) {
        return "load" !== t.type && "error" !== t.type || !a(this) ? (-1 !== [ "load", "DOMContentLoaded", "readystatechange", "pageshow" ].indexOf(t.type) && (u.push({
            type: t.type,
            target: this,
            bubbles: !!t.bubbles,
            cancelable: !!t.cancelable,
            detail: t.detail || null
        }), e("Captured real event:", t.type, "on", this.constructor && this.constructor.name || "node")), 
        c || -1 === [ "load", "DOMContentLoaded", "readystatechange" ].indexOf(t.type) ? h.call(this, t) : (e("Suppressing event:", t.type), 
        !0)) : h.call(this, t);
    }, 1) {
        wpcTrapReady30();
    }
    
    
    
    
    
    
    
    function wpcTrapReady30() {
        var jq = window.jQuery;
        if (!jq || !jq.fn || !jq.fn.ready || jq.fn.__wpcReadyTrap30) return;
        g = jq;
        jq.fn.__wpcReadyTrap30 = 1;
        jq.fn.ready = function(t) {
            if (c && v) {
                try {
                    t(jq);
                } catch (x) {
                    e("Error in jQuery ready callback:", x);
                }
                return this;
            }
            return e("Capturing jQuery ready callback"), m.push(t), this;
        };
    }
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    function wpcReadyNow27() {
        if (!g || !m.length) return;
        var pre = m.splice(0, m.length), again = [];
        for (var i = 0; i < pre.length; i++) {
            try { pre[i](g); } catch (x) { again.push(pre[i]); e("ready cb deferred to replay end:", x); }
        }
        if (again.length) m = again.concat(m);
    }
    
    
    
    
    
    
    
    
    
    
    
    function wpcDclNow35() {
        try {
            if (!p.DOMContentLoaded.length) { return; }
            if (wpcRealRS() === "loading") {
                if (wpcDclNow35.w) { return; }
                wpcDclNow35.w = 1;
                y.call(document, "DOMContentLoaded", function() { wpcDclNow35(); }, { once: !0 });
                return;
            }
            var pre = p.DOMContentLoaded.splice(0, p.DOMContentLoaded.length), again = [];
            for (var i = 0; i < pre.length; i++) {
                try { pre[i].listener.call(pre[i].target, new Event("DOMContentLoaded")); } catch (x) { again.push(pre[i]); e("DCL listener deferred to replay end:", x); }
            }
            if (again.length) { p.DOMContentLoaded = again.concat(p.DOMContentLoaded); }
        } catch (z) {}
    }
    var wpcPlQ = [], wpcPlOn = false, wpcPlIn = 0;
    function wpcPlDone() {
        if (wpcPlIn > 0) { wpcPlIn--; }
        if (wpcPlQ.length) { wpcPlPump(); }
    }
    function wpcPlPump() {
        var c = window.wpcDelayV3Cfg || {};
        var cap = +c.preloadCap > 0 ? +c.preloadCap : 6;
        var gap = +c.preloadGapMs >= 0 ? +c.preloadGapMs : 120;
        
        
        
        
        while (wpcPlIn < cap && wpcPlQ.length) {
            wpcPlIn++;
            try { wpcPlQ.shift()(wpcPlDone, gap); } catch (e) { wpcPlDone(); }
        }
        if (!wpcPlQ.length && !wpcPlIn) { wpcPlOn = false; }
    }
    function w(e, t, r) {
        if (e) {
            e = wpcJsSrc(e);
            
            if (/maps\.googleapis\.com\/maps\/api\/js/.test(String(e)) && /[?&]key=(?:&|$)/.test(String(e))) return;
            wpcPlQ.push(function(done, gap) {
                var n = 'link[rel="' + ("module" === t ? "modulepreload" : "preload") + '"][href="' + e + '"]';
                if (document.querySelector(n)) { done(); return; }
                var a = document.createElement("link"), fired = 0;
                
                
                
                
                
                
                
                
                
                var fin = function() { if (!fired) { fired = 1; done(); } };
                setTimeout(fin, (+gap >= 0 ? +gap : 120) + 30000);
                a.rel = "module" === t ? "modulepreload" : "preload", "module" !== t && (a.as = "script"),
                a.onload = fin, a.onerror = fin,
                a.href = e, r && r.crossorigin && (a.crossOrigin = r.crossorigin), r && r.integrity && (a.integrity = r.integrity),
                r && r.referrerpolicy && (a.referrerPolicy = r.referrerpolicy), (document.head || document.documentElement).appendChild(a);
            });
            
            
            if (!wpcPlOn) { wpcPlOn = true; setTimeout(wpcPlPump, 0); }
        }
    }
    function b(e, r) {
        for (var n = 0; n < e.length; n++) {
            var a = e[n];
            a.src && w(t(a.src, !!a.encoded), r, a.attributes);
        }
    }
    function E(e) {
        var a = document.createElement("script");
        return a.type = e.type || "text/javascript", e.src ? (a.src = wpcJsSrc(t(e.src, !!e.encoded)),
        r(a, e.attributes), n(e) ? a.async = !0 : (a.async = !1, (e.defer || e.attributes && e.attributes.defer) && (a.defer = !0), 
        e.attributes && e.attributes.nomodule && (a.noModule = !0)), {
            el: a,
            inline: !1
        }) : (r(a, e.attributes), a.text = function(x) {
            
            
            
            
            try {
                new Function(x);
            } catch (err) {
                if (err && err.name === "SyntaxError") {
                    return "console.warn('[WPC] delayed inline script skipped (syntax error): '+" + JSON.stringify(String(e.id || "")) + "+' — '+" + JSON.stringify(String(err.message || "")) + ");";
                }
            }
            return x;
        }(t(e.content || "", !!e.encoded)), {
            el: a,
            inline: !0
        });
    }
    function C(e, t) {
        var r = document.querySelector('script[data-script-id="' + t + '"]');
        r && r.parentNode ? r.parentNode.replaceChild(e, r) : (document.head || document.body || document.documentElement).appendChild(e);
    }
    function wpcJqGate(t, r) {
        
        
        
        
        
        if (!/jQuery|\$\s*\(/.test(t || "")) return r(!1);
        if (!document.querySelector('script[src*="jquery"]')) return r(!1);
        var a = Date.now();
        !function o() {
            var i = window.jQuery;
            if (i && i.fn && i.fn.on) return r(!1);
            if (Date.now() - a > 1e4) return r(!0);
            setTimeout(o, 80);
        }();
    }
    function S(a) {
        return new Promise((function(c) {
            try {
                if ("importmap" === a.type) {
                    var i = function(e) {
                        var n = document.createElement("script");
                        return n.type = "importmap", e.src ? n.src = wpcJsSrc(t(e.src, !!e.encoded)) : n.text = t(e.content || "", !!e.encoded),
                        r(n, e.attributes), n;
                    }(a), l = function() {
                        o++, c();
                    };
                    return a.src && (y.call(i, "load", l, {
                        once: !0
                    }), y.call(i, "error", wpcFbErr(i, l), {
                        once: !0
                    })), C(i, a.id), void (a.src || l());
                }
                if ("module" !== a.type) {
                    var d = E(a), s = d.el, u = d.inline, p = function() {
                        o++, c();
                    };
                    if (u) {
                        
                        return void wpcJqGate(s.text, (function(k) {
                            k && (s.text = "console.warn('[WPC] delayed inline script skipped: jQuery never became capable — '+" + JSON.stringify(String(a.id || "")) + ");"),
                            C(s, a.id), p();
                        }));
                    }
                    return y.call(s, "load", p, {
                        once: !0
                    }), y.call(s, "error", wpcFbErr(s, p), {
                        once: !0
                    }), void C(s, a.id);
                }
                var f = function(e) {
                    var n = document.createElement("script");
                    return n.type = "module", n.src = wpcJsSrc(t(e.src, !!e.encoded)), r(n, e.attributes), n;
                }(a), h = !1, g = function() {
                    h || (h = !0, o++, c());
                };
                y.call(f, "load", g, {
                    once: !0
                }), y.call(f, "error", wpcFbErr(f, g), {
                    once: !0
                }), C(f, a.id);
            } catch (t) {
                e("Exception in L_parallel for:", a && a.id, t), o++, c();
            }
        }));
    }
    function R(e) {
        for (var t = [], r = [], a = [], o = [], c = [], i = 0; i < e.length; i++) {
            var l = e[i], d = (l.type || "text/javascript").toLowerCase();
            "importmap" !== d ? n(l) ? t.push(l) : l.src ? "module" !== d ? c.push(l) : (c.length && (a.push(c), 
            c = []), o.push(l)) : (c.length && (a.push(c), c = []), a.push([ l ])) : (c.length && (a.push(c), 
            c = []), r.push(l));
        }
        return c.length && a.push(c), {
            asyncFire: t,
            importMaps: r,
            classicBatches: a,
            moduleChain: o
        };
    }
    function L() {
        
        
        
        
        
        
        
        
        
        
        
        
        
        if (wpcRealRS() === "loading") {
            e("Native DCL pending - parking event replay until it fires");
            y.call(document, "DOMContentLoaded", function() { setTimeout(L, 0); }, { once: !0 });
            return;
        }
        if (e("Replaying captured events and restoring prototypes"), wpcRp111 = !0,
        EventTarget.prototype.removeEventListener = f, EventTarget.prototype.dispatchEvent = h,
        "loading" === l) {
            l = "interactive";
            var r = new Event("readystatechange");
            h.call(document, r), p.readystatechange.forEach((function(t) {
                try {
                    t.listener.call(t.target, r);
                } catch (t) {
                    e("readystatechange (interactive) error:", t);
                }
            }));
        }
        setTimeout((function() {
            d || (d = !0, u.filter((function(e) {
                return "DOMContentLoaded" === e.type;
            })).forEach((function(e) {
                var t = new Event("DOMContentLoaded", {
                    bubbles: e.bubbles,
                    cancelable: e.cancelable
                });
                try {
                    Object.defineProperty(t, "target", {
                        value: e.target,
                        writable: !1
                    });
                } catch (e) {}
                e.target.dispatchEvent(t);
            })), p.DOMContentLoaded.forEach((function(t) {
                try {
                    t.listener.call(t.target, new Event("DOMContentLoaded"));
                } catch (t) {
                    e("DOMContentLoaded listener error:", t);
                }
            })));
            setTimeout((function() {
                l = "complete";
                var r = new Event("readystatechange");
                h.call(document, r), p.readystatechange.forEach((function(t) {
                    try {
                        t.listener.call(t.target, r);
                    } catch (t) {
                        e("readystatechange (complete) error:", t);
                    }
                })), setTimeout((function() {
                    if (s) { return; }
                    s = !0;
                    u.filter((function(e) {
                        return "load" === e.type;
                    })).forEach((function(e) {
                        var t = new Event("load", {
                            bubbles: e.bubbles,
                            cancelable: e.cancelable
                        });
                        try {
                            Object.defineProperty(t, "target", {
                                value: e.target,
                                writable: !1
                            });
                        } catch (e) {}
                        e.target.dispatchEvent(t);
                    }));
                    
                    
                    
                    
                    
                    
                    var wpcLoadHop648 = function() {
                        p.load.forEach((function(t) {
                            try {
                                var r = new Event("load");
                                try {
                                    Object.defineProperty(r, "target", {
                                        value: t.target === window ? window : t.target,
                                        writable: !1
                                    });
                                } catch (e) {}
                                t.listener.call(t.target, r);
                            } catch (t) {
                                e("load listener error:", t);
                            }
                        }));
                    setTimeout((function() {
                        EventTarget.prototype.addEventListener = y;
                        var r = new Event("pageshow");
                        h.call(window, r), p.pageshow.forEach((function(t) {
                            try {
                                t.listener.call(t.target, r);
                            } catch (t) {
                                e("pageshow listener error:", t);
                            }
                        }));
                        var n = new Event("visibilitychange");
                        if (h.call(document, n), p.visibilitychange.forEach((function(t) {
                            try {
                                t.listener.call(t.target, n);
                            } catch (t) {
                                e("visibilitychange listener error:", t);
                            }
                        })), g && m.length && !v) {
                            if (v = !0, m.forEach((function(t) {
                                try {
                                    t(g);
                                } catch (t) {
                                    e("jQuery ready cb error:", t);
                                }
                            })), g.Deferred && g.ready && g.ready.promise) try {
                                var a = g.ready.promise();
                                a && "function" == typeof a.resolveWith && a.resolveWith(document, [ g ]);
                            } catch (t) {
                                e("jQuery ready promise resolve error:", t);
                            }
                            try {
                                g(document).trigger("ready");
                            } catch (t) {
                                e("jQuery trigger error:", t);
                            }
                        }
                        try {
                            if ((Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry : []).some((function(e) {
                                return e.src && -1 !== t(e.src, !!e.encoded).indexOf("wp-compress-image-optimizer");
                            }))) {
                                var o = new Event("WPCContentLoaded");
                                window.dispatchEvent(o), e("WPCContentLoaded dispatched");
                            }
                        } catch (t) {
                            e("WPCContentLoaded dispatch error:", t);
                        }
                        window.wpcSlAt364 = performance.now();
                        var c = new CustomEvent("wpc-scripts-loaded", {
                            detail: {
                                totalScripts: Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry.length : 0
                            }
                        });
                        if (window.dispatchEvent(c), e("Dispatched wpc-scripts-loaded"), "undefined" != typeof elementorFrontend && elementorFrontend.elements && elementorFrontend.elements.$window) {
                            window.wpcSafeResize365(function() { elementorFrontend.elements.$window.trigger("resize"); });
                            const e = new MutationObserver((function(e) {
                                let t = !1;
                                e.forEach((function(e) {
                                    e.addedNodes.forEach((function(e) {
                                        if (1 === e.nodeType) {
                                            const r = jQuery(e).find(".wpc-delay-elementor").addBack(".wpc-delay-elementor");
                                            r.length > 0 && (r.removeClass("wpc-delay-elementor"), t = !0);
                                        }
                                    }));
                                }));
                            }));
                            jQuery(document).ready((function() {
                                document.querySelectorAll(".elementor-loop-container").forEach((t => {
                                    e.observe(t, {
                                        childList: !0,
                                        subtree: !0
                                    });
                                }));
                            }));
                        }
                    }), 20);
                    };
                    if (window.requestAnimationFrame) {
                        requestAnimationFrame((function() {
                            setTimeout(wpcLoadHop648, 0);
                        }));
                    } else {
                        setTimeout(wpcLoadHop648, 0);
                    }
                }), 20);
            }), 20);
        }), 20);
    }
    var T = !1, O = null;
    function I() {
        try {
            if (!Array.isArray(window.wpcScriptRegistry)) return;
            var h = wpcScriptRegistry.filter((function(x) {
                return x && x.io && x.src;
            }));
            if (!h.length) return;
            wpcScriptRegistry = wpcScriptRegistry.filter((function(x) {
                return !(x && x.io);
            }));
            var g = [ "mousemove", "pointermove", "pointerdown", "wheel", "click", "keydown", "touchstart", "scroll" ], f = function() {
                if (window.wpcJqDef47 && !window.wpcJqDef47.r) {
                    window.wpcJqDef47.cb2 = f;
                    return;
                }
                g.forEach((function(v) {
                    document.removeEventListener(v, f, {
                        passive: !0
                    });
                }));
                h.forEach((function(x) {
                    try {
                        var s = document.createElement("script");
                        s.src = wpcJsSrc(t(x.src, !!x.encoded)), s.async = !0, r(s, x.attributes),
                        y.call(s, "error", wpcFbErr(s, function() {}), {
                            once: !0
                        }), (document.head || document.documentElement).appendChild(s);
                    } catch (z) {}
                }));
            };
            g.forEach((function(v) {
                document.addEventListener(v, f, {
                    passive: !0
                });
            }));
        } catch (z) {}
    }
    
    
    
    
    
    
    function wpcEfSnap33() {
        try {
            window.wpcEfPre33 = !!(window.jQuery && jQuery._data && window.elementorFrontend
                && elementorFrontend.elementsHandler && elementorFrontend.elementsHandler.runReadyTrigger);
            window.wpcEfH33 = [];
            if (window.wpcEfPre33) {
                var ev = jQuery._data(window, "events");
                var l = ev && ev["elementor/frontend/init"] ? ev["elementor/frontend/init"] : [];
                for (var i = 0; i < l.length; i++) {
                    if (l[i] && l[i].handler) { window.wpcEfH33.push(l[i].handler); }
                }
            }
        } catch (z) {
            window.wpcEfPre33 = false;
        }
    }
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    window.wpcSafeResize365 = function(fire) {
        var held = [];
        try {
            var togs = document.querySelectorAll(".elementor-menu-toggle.elementor-active");
            for (var i = 0; i < togs.length; i++) {
                var w = togs[i].closest ? togs[i].closest(".elementor-widget-nav-menu") : null;
                var dd = w ? w.querySelector(".elementor-nav-menu__container.elementor-nav-menu--dropdown") : null;
                if (!dd || dd.style.getPropertyValue("position") === "absolute") { continue; }
                var r = dd.getBoundingClientRect();
                if (r.width < 10) { continue; }
                dd.style.setProperty("width", Math.round(r.width) + "px");
                dd.style.setProperty("position", "absolute");
                held.push(dd);
            }
        } catch (z) {}
        try { fire(); } catch (z) {}
        for (var j = 0; j < held.length; j++) {
            try { held[j].style.removeProperty("position"); held[j].style.removeProperty("width"); } catch (z) {}
        }
    };
    
    
    
    
    
    
    
    
    
    
    (function() {
        try {
            var wpcHamDone365 = 0, wpcHamOpen365 = null, wpcHamSec365 = null, wpcHamQ365 = null;
            
            
            
            
            
            
            var wpcHamCssLive365 = function() {
                try {
                    if (document.documentElement.classList.contains("wpc-css-live")) { return true; }
                    return !document.querySelector('link[rel^="wpc-"], style[type^="wpc-"]');
                } catch (z) { return true; }
            };
            
            
            
            var wpcHamLog365 = [], wpcHamWhy365 = "";
            var wpcHamDbg365 = function(ev, info) {
                try {
                    var row = [Math.round(performance.now()), ev, info || ""];
                    wpcHamLog365.push(row); if (wpcHamLog365.length > 60) { wpcHamLog365.shift(); }
                    var on = false; try { on = localStorage.getItem("wpcHamDebug") === "1"; } catch (z) {}
                    if (on) { console.log("[wpcHam]", row[0] + "ms", ev, info || ""); }
                } catch (z) {}
            };
            window.wpcHamState365 = function() {
                try {
                    var tog = document.querySelector(".elementor-menu-toggle");
                    var dd = tog && tog.closest(".elementor-widget-nav-menu") ? tog.closest(".elementor-widget-nav-menu").querySelector(".elementor-nav-menu__container.elementor-nav-menu--dropdown") : null;
                    var sec = document.querySelector('[data-settings*="sticky"]');
                    var crit = document.getElementById("wpc-critical-css");
                    var r = dd ? dd.getBoundingClientRect() : null;
                    var jq = window.jQuery;
                    return {
                        standDown: !!wpcHamDone365, ownsOpen: wpcHamOpen365 === tog && !!tog, armed: !!wpcHamSec365, why: wpcHamWhy365, queued: !!wpcHamQ365, cssLive: wpcHamCssLive365(),
                        toggle: tog ? { open: tog.classList.contains("elementor-active"), aria: tog.getAttribute("aria-expanded"),
                            handlers: jq && jq._data ? ((jq._data(tog, "events") || {}).click || []).length : -1 } : null,
                        panel: r ? { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), pos: getComputedStyle(dd).position, inline: String(dd.getAttribute("style") || "").slice(0, 160) } : null,
                        section: sec ? { pos: getComputedStyle(sec).position, active: /elementor-sticky--active/.test(sec.className), inline: String(sec.getAttribute("style") || "").slice(0, 120) } : null,
                        crit: crit ? { bytes: crit.textContent.length } : "NONE",
                        cfg: window.wpcDelayV3Cfg ? { timeout: window.wpcDelayV3Cfg.timeout, aggr: window.wpcDelayV3Cfg.aggr } : null,
                        log: wpcHamLog365.slice()
                    };
                } catch (z) { return { error: String(z) }; }
            };
            var wpcHamSet365 = function(tog, dd, open) {
                tog.classList[open ? "add" : "remove"]("elementor-active");
                tog.setAttribute("aria-expanded", open ? "true" : "false");
                if (dd) {
                    dd.setAttribute("aria-hidden", open ? "false" : "true");
                    
                    
                    
                    
                    dd.style.setProperty("--menu-height", open ? dd.scrollHeight + "px" : "0px");
                    if (!open) {
                        dd.style.removeProperty("position");
                        dd.style.removeProperty("width");
                        dd.style.removeProperty("top");
                        dd.style.removeProperty("margin-top");
                    }
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    if (open) {
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        var wpcArm365 = function() {
                            if (wpcHamSec365) { return; }
                            var wpcEl365 = tog, wpcSec365 = null, wpcDs365;
                            while (wpcEl365 && wpcEl365 !== document.body) {
                                wpcDs365 = wpcEl365.getAttribute && wpcEl365.getAttribute("data-settings");
                                if (wpcDs365 && wpcDs365.indexOf('"sticky":"top"') !== -1) { wpcSec365 = wpcEl365; break; }
                                wpcEl365 = wpcEl365.parentElement;
                            }
                            if (wpcSec365 && !wpcHamCssLive365()) { wpcHamWhy365 = "css-not-live-waiting"; return; }
                            if (!wpcSec365) { wpcHamWhy365 = "no-sticky-declared"; }
                            else if (getComputedStyle(wpcSec365).position === "fixed") { wpcHamWhy365 = "native-already-fixed"; }
                            else if (!(wpcSec365.getBoundingClientRect().height > 0)) { wpcHamWhy365 = "section-height-0-retrying"; }
                            if (wpcSec365 && getComputedStyle(wpcSec365).position !== "fixed"
                                && wpcSec365.getBoundingClientRect().height > 0) {
                                wpcHamWhy365 = "armed"; wpcHamDbg365("sticky-armed", "h=" + Math.round(wpcSec365.getBoundingClientRect().height));
                                wpcSec365.style.setProperty("position", "fixed");
                                wpcSec365.style.setProperty("top", "0px");
                                wpcSec365.style.setProperty("left", "0px");
                                wpcSec365.style.setProperty("width", "100%");
                                wpcSec365.style.setProperty("margin-top", "0px");
                                wpcSec365.style.setProperty("margin-bottom", "0px");
                                wpcSec365.style.setProperty("z-index", "2000");
                                wpcHamSec365 = wpcSec365;
                            }
                        };
                        var wpcLw365 = -1, wpcLh365 = -1, wpcLb365 = -1;
                        var wpcFit365 = function() {
                            dd.style.removeProperty("position");
                            dd.style.removeProperty("width");
                            dd.style.removeProperty("top");
                            dd.style.removeProperty("margin-top");
                            var wpcW365 = Math.round(dd.getBoundingClientRect().width);
                            var wpcH365 = dd.scrollHeight;
                            var wpcTb365 = Math.round(tog.getBoundingClientRect().bottom);
                            if (wpcW365 >= 10) {
                                
                                
                                
                                
                                var wpcMt365 = parseFloat(getComputedStyle(dd).marginTop) || 0;
                                var wpcCbr365 = dd.offsetParent && dd.offsetParent.getBoundingClientRect ? dd.offsetParent.getBoundingClientRect() : null;
                                var wpcCb365 = wpcCbr365 ? wpcCbr365.top : 0;
                                dd.style.setProperty("width", wpcW365 + "px");
                                var wpcTop365 = Math.round(wpcTb365 + wpcMt365 - wpcCb365);
                                dd.style.setProperty("top", wpcTop365 + "px");
                                dd.style.setProperty("margin-top", "0px");
                                dd.style.setProperty("position", "absolute");
                                
                                
                                
                                var wpcLr365 = dd.getBoundingClientRect();
                                var wpcErr365 = Math.round(wpcLr365.top - (wpcTb365 + wpcMt365));
                                if (wpcErr365) { dd.style.setProperty("top", (wpcTop365 - wpcErr365) + "px"); }
                                if (wpcW365 !== wpcLw365 || wpcTb365 !== wpcLb365) { wpcHamDbg365("fit", "w=" + wpcW365 + " top=" + wpcTop365 + " err=" + wpcErr365 + " mt=" + wpcMt365); }
                            }
                            if (wpcH365 > 0 && wpcH365 !== wpcLh365) {
                                dd.style.setProperty("--menu-height", wpcH365 + "px");
                            }
                            wpcLw365 = wpcW365; wpcLh365 = wpcH365; wpcLb365 = wpcTb365;
                        };
                        wpcArm365();
                        wpcFit365();
                        var wpcTr365 = 0;
                        var wpcRaf365 = function() {
                            if (!tog.classList.contains("elementor-active")) { return; }
                            wpcArm365();
                            
                            var wpcPw365 = Math.round(dd.getBoundingClientRect().width);
                            var wpcPb365 = Math.round(tog.getBoundingClientRect().bottom);
                            if (wpcPw365 !== wpcLw365 || wpcPb365 !== wpcLb365 || dd.scrollHeight !== wpcLh365 || !wpcHamSec365) { wpcFit365(); }
                            if (wpcTr365++ < 600 && typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcRaf365); }
                        };
                        if (typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcRaf365); }
                    } else if (wpcHamSec365) {
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        var wpcNat365 = false;
                        try {
                            wpcNat365 = (" " + wpcHamSec365.className + " ").indexOf(" elementor-sticky--active ") !== -1
                                || !!document.querySelector(".elementor-sticky__spacer");
                        } catch (z) {}
                        wpcHamDbg365("release", wpcNat365 ? "native-owns-leave" : "restore-ours");
                        if (!wpcNat365) {
                            wpcHamSec365.style.removeProperty("position");
                            wpcHamSec365.style.removeProperty("top");
                            wpcHamSec365.style.removeProperty("left");
                            wpcHamSec365.style.removeProperty("width");
                            wpcHamSec365.style.removeProperty("margin-top");
                            wpcHamSec365.style.removeProperty("margin-bottom");
                            wpcHamSec365.style.removeProperty("z-index");
                        }
                        wpcHamSec365 = null;
                    }
                }
            };
            var wpcHamH365 = function(ev) {
                try {
                    if (wpcHamDone365) { return; }
                    if (window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.ef364 !== undefined
                        && +window.wpcDelayV3Cfg.ef364 === 0) { wpcHamDbg365("kill-switch"); return; }
                    var tog = ev.target && ev.target.closest ? ev.target.closest(".elementor-menu-toggle") : null;
                    if (!tog) { return; }
                    var wrap = tog.closest(".elementor-widget-nav-menu");
                    var dd = wrap ? wrap.querySelector(".elementor-nav-menu__container.elementor-nav-menu--dropdown") : null;
                    var jq = window.jQuery;
                    if (jq && jq._data) {
                        var evd = jq._data(tog, "events");
                        if (evd && evd.click && evd.click.length) {
                            
                            wpcHamDone365 = 1; wpcHamDbg365("stand-down", "real handlers=" + evd.click.length + " ownedOpen=" + (wpcHamOpen365 === tog));
                            document.removeEventListener("click", wpcHamH365, true);
                            if (wpcHamOpen365 === tog && tog.classList.contains("elementor-active")) {
                                
                                
                                
                                
                                wpcHamSet365(tog, dd, false);
                                wpcHamOpen365 = null;
                                ev.preventDefault();
                                ev.stopPropagation();
                            }
                            return;
                        }
                    }
                    var open = !tog.classList.contains("elementor-active");
                    if (wpcHamQ365) {
                        
                        wpcHamQ365 = null; wpcHamDbg365("queue-cancel"); ev.preventDefault(); return;
                    }
                    if (open && !wpcHamCssLive365()) {
                        wpcHamQ365 = tog; wpcHamDbg365("queue-open", "css not live");
                        var wpcQn365 = 0;
                        var wpcQraf365 = function() {
                            if (wpcHamQ365 !== tog) { return; }
                            if (wpcHamCssLive365() || wpcQn365 > 600) {
                                wpcHamQ365 = null;
                                if (wpcHamDone365) { return; }
                                wpcHamDbg365("queue-fire", wpcQn365 + " frames");
                                wpcHamSet365(tog, dd, true);
                                wpcHamOpen365 = tog;
                                return;
                            }
                            wpcQn365++;
                            if (typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcQraf365); }
                        };
                        if (typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcQraf365); }
                        ev.preventDefault();
                        return;
                    }
                    wpcHamDbg365(open ? "interim-open" : "interim-close", "dd=" + (dd ? 1 : 0));
                    wpcHamSet365(tog, dd, open);
                    wpcHamOpen365 = open ? tog : null;
                    ev.preventDefault();
                } catch (z) {}
            };
            document.addEventListener("click", wpcHamH365, true);
            
            
            
            
            
            function wpcHamPre34() {
                try {
                    var el = document.documentElement, v = el.getAttribute("data-wpc-tap34");
                    if (!v) { return; }
                    el.removeAttribute("data-wpc-tap34");
                    var parts = String(v).split(":"), age = performance.now() - (+parts[0]), idx = +parts[1] || 0;
                    if (!(age >= 0 && age <= 1500) || (window.pageYOffset || 0) > 0) { wpcHamDbg365("pre-tap-dropped", Math.round(age) + "ms"); return; }
                    var tog = document.querySelectorAll(".elementor-menu-toggle")[idx] || document.querySelector(".elementor-menu-toggle");
                    if (!tog) { return; }
                    wpcHamDbg365("pre-tap-replay", Math.round(age) + "ms");
                    wpcHamH365({ target: tog, preventDefault: function() {}, stopPropagation: function() {} });
                } catch (z) {}
            }
            wpcHamPre34();
        } catch (z) {}
    })();
    function wpcEfOff364() {
        return !!(window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.ef364 !== undefined
            && +window.wpcDelayV3Cfg.ef364 === 0);
    }
    function wpcEfArm364() {
        try {
            if (wpcEfOff364()) { return; }
            var reg = function(jq) {
                try {
                    if (!jq || !jq.fn || !jq.fn.on || !jq._data || jq.wpcEfSent364) { return; }
                    jq.wpcEfSent364 = 1;
                    window.wpcEfDone33 = window.wpcEfDone33 || [];
                    window.wpcEfMaybe33 = window.wpcEfMaybe33 || [];
                    var grab = function(into) {
                        var ev = jq._data(window, "events");
                        var l = ev && ev["elementor/frontend/init"] ? ev["elementor/frontend/init"] : [];
                        for (var i = 0; i < l.length; i++) {
                            var h = l[i] && l[i].handler;
                            if (h && !h.wpcSent364 && into.indexOf(h) === -1) { into.push(h); }
                        }
                    };
                    
                    
                    grab(window.wpcEfMaybe33);
                    var sent = function() {
                        window.wpcEfFired364 = 1;
                        grab(window.wpcEfDone33);
                    };
                    sent.wpcSent364 = 1;
                    jq(window).on("elementor/frontend/init", sent);
                } catch (z) {}
            };
            if (window.jQuery) { reg(window.jQuery); }
            if (window.wpcEfAcc364) { return; }
            var d = Object.getOwnPropertyDescriptor(window, "jQuery");
            if (!d || d.configurable) {
                window.wpcEfAcc364 = 1;
                var cur = window.jQuery;
                Object.defineProperty(window, "jQuery", {
                    configurable: true,
                    enumerable: true,
                    get: function() { return cur; },
                    set: function(v) { cur = v; reg(v); try { if (c) { wpcTrapReady30(); } } catch (x) {} }
                });
            }
        } catch (z) {}
    }
    
    
    
    
    
    
    
    window.wpcRtWrap364 = function() {
        try {
            var eh = window.elementorFrontend && elementorFrontend.elementsHandler;
            if (!eh || !eh.runReadyTrigger || eh.wpcRtW364) { return; }
            eh.wpcRtW364 = 1;
            var orig = eh.runReadyTrigger;
            eh.runReadyTrigger = function(el) {
                if (!window.wpcOurRt364) { window.wpcNativeRtAt364 = performance.now(); }
                return orig.apply(this, arguments);
            };
        } catch (z) {}
    };
    
    
    
    
    
    
    window.wpcRtWhen364 = function() {
        try {
            window.wpcRtWrap364();
            if (window.wpcNativeRtAt364 && window.wpcNativeRtAt364 > (window.wpcEfInvokeAt364 || 0)) { return -1; }
            if (window.wpcEfInvokeAt364) {
                var el364 = performance.now() - window.wpcEfInvokeAt364;
                if (el364 < 3000) { return Math.max(200, 3000 - el364); }
            }
            return 0;
        } catch (z) { return 0; }
    };
    window.wpcRtHeal364 = function(el) {
        try {
            if (wpcEfOff364()) { return false; }
            window.wpcRtWrap364();
            if (!el || !window.elementorFrontend || !elementorFrontend.elementsHandler
                || !elementorFrontend.elementsHandler.runReadyTrigger) { return false; }
            if (el.getAttribute && el.getAttribute("data-wpc-rt321") === "1") { return false; }
            if (window.wpcRtWhen364() !== 0) { return false; }
            if (el.setAttribute) { el.setAttribute("data-wpc-rt321", "1"); }
            window.wpcOurRt364 = 1;
            try { elementorFrontend.elementsHandler.runReadyTrigger(el); } catch (z) {}
            window.wpcOurRt364 = 0;
            return true;
        } catch (z) { window.wpcOurRt364 = 0; return false; }
    };
    window.wpcEfDiffFire33 = function() {
        var names = [];
        try {
            if (wpcEfOff364()) { return names; }
            window.wpcRtWrap364();
            var jq = window.jQuery;
            if (!jq || !jq._data || !window.elementorFrontend || !elementorFrontend.hooks
                || !elementorFrontend.elementsHandler || !elementorFrontend.elementsHandler.runReadyTrigger) {
                return names;
            }
            window.wpcEfDone33 = window.wpcEfDone33 || [];
            window.wpcEfMaybe33 = window.wpcEfMaybe33 || [];
            var done = window.wpcEfDone33, maybe = window.wpcEfMaybe33;
            var ev = jq._data(window, "events");
            var cur = ev && ev["elementor/frontend/init"] ? ev["elementor/frontend/init"].slice() : [];
            var late = [];
            for (var i = 0; i < cur.length; i++) {
                var h = cur[i] && cur[i].handler;
                if (!h || h.wpcSent364 || done.indexOf(h) !== -1) { continue; }
                
                
                if (!window.wpcEfFired364 && maybe.indexOf(h) !== -1 && !wpcEfMissed33(h)) { continue; }
                late.push(h);
            }
            if (!late.length) { return names; }
            var oa = elementorFrontend.hooks.addAction;
            elementorFrontend.hooks.addAction = function(n) {
                try {
                    if (String(n).indexOf("frontend/element_ready/") === 0) { names.push(String(n).slice(23)); }
                } catch (z) {}
                return oa.apply(this, arguments);
            };
            var evt = null;
            try { evt = jq.Event("elementor/frontend/init"); } catch (z) {}
            window.wpcEfInvokeAt364 = performance.now();
            for (var j = 0; j < late.length; j++) {
                done.push(late[j]);
                try { late[j].call(window, evt); } catch (z) {}
            }
            elementorFrontend.hooks.addAction = oa;
        } catch (z) {}
        return names;
    };
    
    
    
    
    
    
    
    
    
    
    
    
    function wpcEfMissed33(h) {
        try {
            return !!(window.wpcEfPreFire33 && h && h.name === "bound onElementorFrontendInit"
                && window.elementorProFrontend && elementorProFrontend.modules
                && !Object.keys(elementorProFrontend.modules).length);
        } catch (z) { return false; }
    }
    function wpcEfMark33() {
        try {
            if (window.wpcEfPreFire33 === undefined && window.elementorFrontend
                && elementorFrontend.elements && elementorFrontend.elementsHandler) {
                window.wpcEfPreFire33 = window.wpcEfFired364 ? 0 : 1;
            }
        } catch (z) {}
    }
    function wpcEfNamesPass33(names) {
        var tries = 0;
        var go = function() {
            try {
                var wv = window.wpcRtWhen364 ? window.wpcRtWhen364() : 0;
                if (wv === -1) { return; }
                if (wv > 0) { if (tries++ < 8) { setTimeout(go, wv); } return; }
                var seen = {};
                for (var k = 0; k < names.length; k++) {
                    var w = String(names[k]).split(".")[0];
                    if (!w || seen[w]) { continue; }
                    seen[w] = 1;
                    var els = document.querySelectorAll(".elementor-widget-" + w);
                    for (var m = 0; m < els.length; m++) {
                        try { window.wpcRtHeal364 && window.wpcRtHeal364(els[m]); } catch (z) {}
                    }
                }
            } catch (z) {}
        };
        setTimeout(go, 300);
    }
    function wpcEfBootHeal33() {
        try {
            if (wpcEfOff364()) { return; }
            wpcEfMark33();
            wpcEfArm364();
            var n = 0;
            var go = function() {
                try {
                    if (c) { return; }
                    wpcEfMark33();
                    if (!window.elementorFrontend || !elementorFrontend.elements || !window.jQuery || !jQuery._data) {
                        if (n++ < 40) { setTimeout(go, 250); }
                        return;
                    }
                    var names = window.wpcEfDiffFire33 ? window.wpcEfDiffFire33() : [];
                    if (names.length) { wpcEfNamesPass33(names); }
                } catch (z) {}
            };
            var start = function() { setTimeout(go, 250); };
            "complete" === document.readyState ? start() : y.call(window, "load", start, { once: true });
        } catch (z) {}
    }
    wpcEfBootHeal33();
    function D() {
        if (window.wpcJqDef47 && !window.wpcJqDef47.r) {
            window.wpcJqDef47.cb = D;
            return;
        }
        c ? e("Loading already started, ignoring duplicate call") : (O && (clearTimeout(O),
        O = null), c = !0, window.__wpcRel186 = 1, window.wpcParkFlush186 && window.wpcParkFlush186(),
        e("Triggered resource loading"), wpcEfSnap33(), wpcEfArm364(), wpcTrapReady30(), wpcDclNow35(), wpcReadyNow27(), async function() {
            if (i) e("Already loading resources, ignoring duplicate call"); else {
                i = !0;
                try {
                    if (Array.isArray(wpcScriptRegistry) && wpcScriptRegistry.length && wpcScriptRegistry.sort((function(e, t) {
                        var r = !0 === e.defer || e.attributes && e.attributes.defer, n = !0 === t.defer || t.attributes && t.attributes.defer, a = "module" === e.type, o = "module" === t.type, c = r || a, i = n || o;
                        return c && !i ? 1 : !c && i ? -1 : 0;
                    })), window.WPC_STRICT_ORDER) {
                        e("STRICT mode enabled (document.write detected or forced). Loading sequentially.");
                        for (var t = 0; t < wpcScriptRegistry.length; t++) await S(wpcScriptRegistry[t]);
                        return L(), void (i = !1);
                    }
                    
                    
                    
                    
                    var wpcZs = !!wpcCdnH && wpcScriptRegistry.some((function(x) {
                        return !!(x && x.src) && wpcJsDecode(x.src, !!x.encoded).indexOf("//" + wpcCdnH) > -1;
                    }));
                    for (var r = R(wpcScriptRegistry), n = 0; n < r.asyncFire.length; n++) S(r.asyncFire[n]);
                    for (var a = 0; a < r.classicBatches.length; a++) b(r.classicBatches[a].filter((function(e) {
                        return e.src && "module" !== (e.type || "text/javascript");
                    })), "script");
                    b(r.moduleChain, "module");
                    for (var c = 0; c < r.importMaps.length; c++) await S(r.importMaps[c]);
                    for (var l = 0; l < r.classicBatches.length; l++) {
                        await new Promise((function(qq) {
                            (window.requestAnimationFrame || setTimeout)((function() {
                                setTimeout(qq, 0);
                            }));
                        }));
                        var d = r.classicBatches[l];
                        if (wpcZs) {
                            
                            
                            
                            
                            
                            for (var zq = 0; zq < d.length; zq++) {
                                if (d[zq] && d[zq].src) { await S(d[zq]); }
                                else { var zi = E(d[zq]); C(zi.el, d[zq].id); o++; }
                            }
                            continue;
                        }
                        if (1 !== d.length || d[0].src) {
                            for (var s = [], u = 0; u < d.length; u++) {
                                var p = E(d[u]).el;
                                C(p, d[u].id), s.push(p);
                            }
                            await new Promise((function(e) {
                                var t = s[s.length - 1], r = !1, n = function() {
                                    r || (r = !0, e());
                                };
                                y.call(t, "load", n, {
                                    once: !0
                                }), y.call(t, "error", n, {
                                    once: !0
                                });
                            })), o += d.length;
                        } else await S(d[0]);
                    }
                    for (var f = 0; f < r.moduleChain.length; f++) await S(r.moduleChain[f]);
                    L();
                } catch (t) {
                    e("Error in resource loading sequence", t);
                } finally {
                    i = !1;
                }
            }
        }());
    }
    "undefined" != typeof DEBUG && (window.ScriptDelayDebug = {
        scriptRegistry: "undefined" != typeof wpcScriptRegistry ? wpcScriptRegistry : [],
        forceLoad: D,
        status: function() {
            var e = Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry.length : 0, t = Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry.filter((function(e) {
                return e.src;
            })).length : 0;
            return {
                loadedScripts: o,
                totalScripts: e,
                totalExternalScripts: t,
                isLoading: i,
                loadingStarted: c,
                readyState: l,
                domContentFired: d,
                windowLoadFired: s
            };
        }
    }), function() {
        try {
            var wpcWarmed = false;
            var wpcPreSweep = function() {
                if (wpcWarmed) {
                    return;
                }
                wpcWarmed = true;
                try {
                    for (var t = R(Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry : []), r = 0; r < t.classicBatches.length; r++) {
                        b(t.classicBatches[r].filter((function(e) {
                            return e.src && "module" !== (e.type || "text/javascript");
                        })), "script");
                    }
                    b(t.moduleChain, "module");
                } catch (e) {}
            };
            
            
            window.wpcWarmDelayed = wpcPreSweep;
            var wpcCfgHS = window.wpcDelayV3Cfg || {};
            if (wpcCfgHS.engagementSignals === 0 || wpcCfgHS.engagementSignals === false || wpcCfgHS.humanSignals === 0 || wpcCfgHS.humanSignals === false) {
                var wpcRafP = window.requestAnimationFrame ? window.requestAnimationFrame.bind(window) : function(f) {
                    setTimeout(f, 60);
                };
                wpcRafP((function() {
                    wpcRafP((function() {
                        setTimeout(wpcPreSweep, 900);
                    }));
                }));
            }
        } catch (e) {}
        
        
        
        if (n = typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0,
        a = typeof window.pageXOffset === "number" ? window.pageXOffset : (document.documentElement || {}).scrollLeft || 0,
        n > 0 || a > 0) return e("Page already scrolled; starting immediately"), T = !0,
        void D();
        var n, a, o = [ "mousemove", "mouseover", "pointermove", "pointerdown", "wheel", "click", "keydown", "touchstart", "scroll" ];
        function c(t) {
            T || (T = !0, o.forEach((function(e) {
                document.removeEventListener(e, c, {
                    passive: !0
                });
            })), e("User interaction detected:", t.type), D());
        }
        e("Waiting for user interaction to load resources"), o.forEach((function(e) {
            document.addEventListener(e, c, {
                passive: !0
            });
        })), function() {
            
            
            
            
            
            
            try {
                if (document.documentElement.classList && document.documentElement.classList.contains("wpc-bgl255")) {
                    var b = function() { c({ type: "wpc-bgl255" }); };
                    "loading" === document.readyState ? document.addEventListener("DOMContentLoaded", b) : b();
                }
            } catch (x) {}
        }(), window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.timeout > 0 && (O = setTimeout((function() {
            T || (e("Fallback timer reached; starting load"), T = !0, o.forEach((function(e) {
                document.removeEventListener(e, c, {
                    passive: !0
                });
            })), I(), D());
        }), +window.wpcDelayV3Cfg.timeout * 1e3));
    }();
    try {
        window.wpcStartDelayed = D;
        window.wpcPreloadDelayed = function() {
            try {
                if (Array.isArray(wpcScriptRegistry)) {
                    
                    for (var pi = 0; pi < wpcScriptRegistry.length; pi++) {
                        var px = wpcScriptRegistry[pi];
                        if (px && px.src) {
                            w(t(px.src, !!px.encoded), ((px.type || "") + "").toLowerCase() === "module" ? "module" : "", px.attributes);
                        }
                    }
                }
            } catch (e) {}
        };
    } catch (e) {}
}();

(function() {
    "use strict";
    var cfg = window.wpcDelayV3Cfg || {};
    if (!cfg.report) {
        return;
    }
    var errs = [], t0 = 0, booted = false, wdSent = false;
    window.addEventListener("wpc-scripts-loaded", (function() {
        booted = true;
        if (wdSent) {
            try {
                
                
                var rf = new FormData;
                rf.append("action", "wpc_delay_v3_report");
                rf.append("payload", JSON.stringify({
                    u: location.pathname.slice(0, 120),
                    b: 1
                }));
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(cfg.report, rf);
                }
            } catch (e) {}
        }
    }), {
        once: true
    });
    
    
    
    
    function wpcWatchdog() {
        if (booted || wdSent || !(window.wpcScriptRegistry || []).length) {
            return;
        }
        wdSent = true;
        try {
            var atf = 0, els = document.body ? document.body.children : [];
            for (var i = 0; i < els.length && i < 30; i++) {
                var r = els[i].getBoundingClientRect ? els[i].getBoundingClientRect() : 0;
                if (r && r.height > 40 && r.top < window.innerHeight) {
                    atf = 1;
                    break;
                }
            }
            var fd = new FormData;
            fd.append("action", "wpc_delay_v3_report");
            fd.append("payload", JSON.stringify({
                u: location.pathname.slice(0, 120),
                e: errs.slice(0, 3),
                b: 0,
                atf: atf,
                n: (window.wpcScriptRegistry || []).length
            }));
            if (navigator.sendBeacon) {
                navigator.sendBeacon(cfg.report, fd);
            }
        } catch (e) {}
    }
    [ "pointerdown", "pointermove", "keydown", "touchstart", "wheel", "scroll", "mousemove" ].forEach((function(ev) {
        window.addEventListener(ev, (function(e) {
            if (!t0 && e && e.isTrusted) {
                t0 = Date.now();
                var c = navigator.connection;
                if (+cfg.aggr === 1 && !(c && /(^|-)2g/.test(String(c.effectiveType || "")))) {
                    setTimeout(wpcWatchdog, Math.min(15e3 + 400 * (window.wpcScriptRegistry || []).length, 45e3));
                }
            }
        }), {
            once: true,
            passive: true,
            capture: true
        });
    }));
    function rec(ev) {
        if (errs.length >= 10) {
            return;
        }
        var m = String(ev && ev.message || "");
        if (!m) {
            return;
        }
        errs.push({
            m: m.slice(0, 180),
            f: String(ev && ev.filename || "").slice(0, 160)
        });
    }
    window.addEventListener("error", rec);
    window.addEventListener("wpc-scripts-loaded", (function() {
        setTimeout((function() {
            window.removeEventListener("error", rec);
            
            
            if (wdSent) {
                return;
            }
            if (!errs.length && !t0) {
                return;
            }
            try {
                var fd = new FormData;
                fd.append("action", "wpc_delay_v3_report");
                fd.append("payload", JSON.stringify({
                    u: location.pathname.slice(0, 120),
                    e: errs,
                    d: t0 ? Date.now() - t0 : 0,
                    n: (window.wpcScriptRegistry || []).length
                }));
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(cfg.report, fd);
                }
            } catch (e) {}
        }), 4e3);
    }), {
        once: true
    });
})();

(function() {
    "use strict";
    var done = false, pending = null;
    
    
    var wpcPtr617 = null, wpcTouch617 = false;
    try {
        document.addEventListener("mousemove", function(e) {
            wpcPtr617 = { x: e.clientX, y: e.clientY };
        }, { capture: true, passive: true });
        document.addEventListener("touchstart", function() {
            wpcTouch617 = true;
            wpcPtr617 = null;
        }, { capture: true, passive: true });
    } catch (e) {}
    
    
    
    
    
    
    
    
    
    
    var wpcTog565 = ".menu-item-has-children > a, li[aria-haspopup] > a, a[aria-haspopup], a[aria-expanded], .elementor-menu-toggle, [role=button][aria-expanded], button[aria-expanded], .menu-toggle, .navbar-toggler";
    
    
    
    
    var wpcDone639 = 0;
    var wpcSnap616 = function(t) {
        var s = (t.getAttribute("aria-expanded") || "") + "|" + (t.getAttribute("class") || "");
        try {
            var cid = t.getAttribute("aria-controls");
            var p = cid ? document.getElementById(cid) : t.nextElementSibling;
            if (p && p.nodeType === 1) {
                s += "|" + (p.getAttribute("class") || "") + "|" + (p.getAttribute("style") || "")
                   + "|" + (p.getAttribute("aria-hidden") || "") + (p.hidden ? "H" : "");
            }
        } catch (e) {}
        return s;
    };
    document.addEventListener("click", (function(ev) {
        if (done || ev.__wpcReplay) {
            return;
        }
        
        
        if (ev.button || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey || ev.defaultPrevented) {
            return;
        }
        var t = ev.target;
        if (!t || !t.closest) {
            return;
        }
        var tog = null;
        try { tog = t.closest(wpcTog565); } catch (e) {}
        
        
        
        var wpcAexp608 = tog ? tog.getAttribute("aria-expanded") : null;
        if (!tog && t.closest("a[href], input, textarea, select, label, [contenteditable]")) {
            return;
        }
        if (tog) {
            ev.preventDefault();
        }
        pending = {
            t: tog || t,
            x: ev.clientX,
            y: ev.clientY,
            tog: tog ? 1 : 0,
            a: wpcAexp608,
            s: tog ? wpcSnap616(tog) : "",
            c: tog ? (tog.getAttribute("class") || "") : "",
            
            
            
            
            
            
            
            
            
            j: wpcDone639,
            href: tog ? tog.getAttribute("href") : ""
        };
    }), true);
    window.addEventListener("wpc-scripts-loaded", (function() {
        wpcDone639 = 1;
        
        
        
        
        var fire = function() {
            done = true;
            if (!pending || !pending.t || !pending.t.isConnected) {
                pending = null;
                return;
            }
            
            
            
            if (!pending.tog && pending.j) {
                pending = null;
                return;
            }
            try {
                var ev = new MouseEvent("click", {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                    clientX: pending.x,
                    clientY: pending.y
                });
                ev.__wpcReplay = true;
                pending.t.dispatchEvent(ev);
            } catch (e) {}
            pending = null;
        };
        
        
        
        
        
        
        
        
        
        
        
        var wpcReps616 = [250, 450, 700, 1000, 1400, 1900, 2500, 3200];
        var wpcDispatch616 = function() {
            try {
                var ev = new MouseEvent("click", {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                    clientX: pending.x,
                    clientY: pending.y
                });
                ev.__wpcReplay = true;
                pending.t.dispatchEvent(ev);
            } catch (e) {}
        };
        
        
        
        
        
        
        var wpcRungMo635 = null;
        
        
        
        var wpcRung635 = function() {
            
            
            if (!pending || !pending.tog || !pending.t || !pending.t.isConnected) { return; }
            wpcVerify616(1);
        };
        try {
            wpcRungMo635 = new MutationObserver(function(ms) {
                if (!pending) { try { wpcRungMo635.disconnect(); } catch (e) {} return; }
                for (var a = 0; a < ms.length; a++) {
                    var ns = ms[a].addedNodes;
                    for (var b = 0; b < ns.length; b++) {
                        var n = ns[b];
                        if (n.tagName === "SCRIPT" && n.src) {
                            n.addEventListener("load", function() {
                                setTimeout(wpcRung635, 150);
                            }, { once: true });
                        }
                    }
                }
            });
            wpcRungMo635.observe(document.documentElement, { childList: true, subtree: true });
        } catch (e) {}
        var wpcVerify616 = function(i) {
            if (!pending || !pending.t || !pending.t.isConnected) {
                pending = null;
                return;
            }
            
            
            
            
            
            
            
            if (i > 0) {
                if (pending.a !== null) {
                    var wpcAv633v = pending.t.getAttribute("aria-expanded");
                    if ((wpcAv633v !== null && wpcAv633v !== pending.a)
                        || (pending.t.getAttribute("class") || "") !== pending.c) {
                        pending = null;
                        return;
                    }
                } else if (wpcSnap616(pending.t) !== pending.s) {
                    pending = null;
                    return;
                }
            }
            if (i >= wpcReps616.length) {
                pending = null;
                return;
            }
            
            
            pending.s = wpcSnap616(pending.t);
            pending.c = pending.t.getAttribute("class") || "";
            wpcDispatch616();
            setTimeout((function() {
                wpcVerify616(i + 1);
            }), wpcReps616[i]);
        };
        var wait = function(n) {
            if (!pending || !pending.tog) {
                setTimeout(fire, 60);
                return;
            }
            if (!pending.t || !pending.t.isConnected) {
                pending = null;
                done = true;
                return;
            }
            if (pending.a === null) {
                
                
                
                
                
                if (pending.t.getAttribute("aria-expanded") !== null) {
                    done = true;
                    wpcVerify616(0);
                    return;
                }
            } else {
                
                
                
                
                
                
                var wpcAv633 = pending.t.getAttribute("aria-expanded");
                if ((wpcAv633 !== null && wpcAv633 !== pending.a)
                    || (pending.t.getAttribute("class") || "") !== pending.c) {
                    done = true;
                    pending = null;
                    return;
                }
                if (n <= 18) {
                    
                    
                    
                    done = true;
                    wpcVerify616(0);
                    return;
                }
            }
            if (n <= 0) {
                
                
                
                var href = pending.href;
                pending = null;
                done = true;
                if (href && href.charAt(0) !== "#" && href.indexOf("javascript:") !== 0) {
                    try { location.href = href; } catch (e) {}
                }
                return;
            }
            setTimeout((function() { wait(n - 1); }), 50);
        };
        wait(30);

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        var wpcMenuReps618 = [400, 1200, 2500, 5000, 9000];
        var wpcMenu618 = function(i) {
            var stop = false;
            try {
                
                
                
                try { window.wpcRtWrap364 && window.wpcRtWrap364(); } catch (e0) {}
                var wpcW618 = window.wpcRtWhen364 ? window.wpcRtWhen364() : 0;
                if (wpcW618 === -1) { return; }
                if (wpcW618 > 0
                    || !window.wpcSlAt364 || (performance.now() - window.wpcSlAt364) < 1200) {
                    
                    
                    
                    if (!window.wpcMenuT618) { window.wpcMenuT618 = performance.now(); }
                    if (performance.now() - window.wpcMenuT618 > 30000) { return; }
                    if (i + 1 < wpcMenuReps618.length) {
                        setTimeout((function() { wpcMenu618(i + 1); }), wpcMenuReps618[i + 1] - wpcMenuReps618[i]);
                    } else {
                        setTimeout((function() { wpcMenu618(i); }), 1500);
                    }
                    return;
                }
                var navs = document.querySelectorAll(".elementor-widget-nav-menu");
                if (!navs.length || !window.jQuery || !jQuery.fn || !jQuery.fn.smartmenus
                    || !window.elementorFrontend || !elementorFrontend.hooks
                    || !elementorFrontend.elementsHandler || !elementorFrontend.elementsHandler.runReadyTrigger) {
                    stop = !navs.length;
                } else if (document.querySelector("ul.sm,[id^=sm-]")) {
                    stop = true;
                } else {
                    
                    
                    try { window.wpcEfDiffFire33 && window.wpcEfDiffFire33(); } catch (e) {}
                    setTimeout((function() {
                        try {
                            for (var k = 0; k < navs.length; k++) {
                                window.wpcRtHeal364 && window.wpcRtHeal364(navs[k]);
                            }
                        } catch (e) {}
                    }), 300);
                }
            } catch (e) {
                stop = true;
            }
            if (!stop && i + 1 < wpcMenuReps618.length) {
                setTimeout((function() { wpcMenu618(i + 1); }), wpcMenuReps618[i + 1] - wpcMenuReps618[i]);
            }
        };
        setTimeout((function() { wpcMenu618(0); }), wpcMenuReps618[0]);

        
        
        
        
        
        
        
        
        setTimeout((function() {
            try {
                
                
                
                
                
                var names33 = window.wpcEfDiffFire33 ? window.wpcEfDiffFire33() : [];
                if (!names33.length) { return; }
                
                
                var wpcQt33 = function() {
                    return window.wpcSlAt364 ? Math.max(60, 1200 - (performance.now() - window.wpcSlAt364)) : 1200;
                };
                var wpcTry33 = 0;
                var wpcGo33 = (function() {
                    try {
                        var wv33 = window.wpcRtWhen364 ? window.wpcRtWhen364() : 0;
                        if (wv33 === -1) { return; }
                        if (wv33 > 0) {
                            if (wpcTry33++ < 8) { setTimeout(wpcGo33, wv33); }
                            return;
                        }
                        var seen33 = {};
                        for (var k33 = 0; k33 < names33.length; k33++) {
                            var w33 = String(names33[k33]).split(".")[0];
                            if (!w33 || seen33[w33]) { continue; }
                            seen33[w33] = 1;
                            var els33 = document.querySelectorAll(".elementor-widget-" + w33);
                            for (var m33 = 0; m33 < els33.length; m33++) {
                                try { window.wpcRtHeal364 && window.wpcRtHeal364(els33[m33]); } catch (z) {}
                            }
                        }
                    } catch (z) {}
                });
                setTimeout(wpcGo33, wpcQt33());
            } catch (z) {}
        }), 120);

        
        
        
        
        
        
        
        
        var wpcReveal620 = function() {
            try {
                var els = document.querySelectorAll(".elementor-invisible");
                if (!els.length || !window.IntersectionObserver) { return; }
                var reveal = function(el) {
                    try {
                        if (!el.classList.contains("elementor-invisible")) { return; }
                        var anim = "";
                        try {
                            var ds = el.getAttribute("data-settings");
                            var m = ds && JSON.parse(ds);
                            anim = (m && (m._animation || m.animation || m._animation_mobile)) || "";
                        } catch (e) {}
                        if (!anim) {
                            var wa = el.getAttribute("wpc-elementor-animation") || "";
                            anim = wa.replace(/^animated\s+/, "");
                        }
                        el.classList.remove("elementor-invisible");
                        if (anim && anim !== "none") {
                            el.classList.add("animated");
                            el.classList.add(anim);
                        }
                        try { window.wpcPin342 && window.wpcPin342(el, !!(anim && anim !== "none")); } catch (e) {}
                        
                        
                        
                        
                        
                        
                        try { window.wpcAnimScrub335 && window.wpcAnimScrub335(el); } catch (e) {}
                    } catch (e) {
                        try { el.classList.remove("elementor-invisible"); } catch (e2) {}
                        try { window.wpcPin342 && window.wpcPin342(el, false); } catch (e2) {}
                    }
                };
                var io = new IntersectionObserver(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        var en = entries[i];
                        
                        if (en.isIntersecting || en.boundingClientRect.bottom < 0) {
                            (function(el) {
                                setTimeout(function() { reveal(el); }, 600);
                            })(en.target);
                            io.unobserve(en.target);
                        }
                    }
                }, { rootMargin: "0px 0px 10% 0px" });
                for (var k = 0; k < els.length; k++) { io.observe(els[k]); }
            } catch (e) {}
        };
        setTimeout(wpcReveal620, 1200);

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        var wpcReveal826 = function() {
            try {
                var els = document.querySelectorAll(".et-waypoint:not(.et-animated):not(.et_pb_animation_off),.et_animated");
                if (!els.length || !window.IntersectionObserver) { return; }
                var reveal = function(el) {
                    try {
                        if (el.classList.contains("et-waypoint")) {
                            if (el.classList.contains("et-animated")) { return; }
                            el.classList.add("et-animated");
                            return;
                        }
                        var an = "";
                        try { an = String(getComputedStyle(el).animationName || ""); } catch (e2) {}
                        if (an === "" || an === "none") { el.style.opacity = "1"; }
                    } catch (e) {}
                };
                var io = new IntersectionObserver(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        var en = entries[i];
                        if (en.isIntersecting || en.boundingClientRect.bottom < 0) {
                            (function(el) {
                                setTimeout(function() { reveal(el); }, 600);
                            })(en.target);
                            io.unobserve(en.target);
                        }
                    }
                }, { rootMargin: "0px 0px 10% 0px" });
                for (var k = 0; k < els.length; k++) { io.observe(els[k]); }
            } catch (e) {}
        };
        setTimeout(wpcReveal826, 1200);

        var wpcHovReps617 = [900, 1500, 2500];
        var wpcHover617 = function(i) {
            var stop = false;
            try {
                if (wpcTouch617 || !wpcPtr617 || !document.elementFromPoint) {
                    stop = true;
                } else {
                    var el = document.elementFromPoint(wpcPtr617.x, wpcPtr617.y);
                    var a = el && el.closest ? el.closest("li.menu-item-has-children > a, li[aria-haspopup] > a") : null;
                    if (!a) {
                        stop = true;
                    } else if (a.getAttribute("aria-expanded") === "true") {
                        stop = true;
                    } else {
                        var li = a.parentNode;
                        var sub = li && li.querySelector ? li.querySelector(".sub-menu, ul") : null;
                        if (sub) {
                            var cs = window.getComputedStyle(sub);
                            if (cs.display !== "none" && cs.visibility !== "hidden") {
                                stop = true;
                            }
                        }
                        if (!stop) {
                            var fire = function(type, bubbles, dx) {
                                var ev = new MouseEvent(type, {
                                    bubbles: bubbles,
                                    cancelable: true,
                                    view: window,
                                    clientX: wpcPtr617.x + dx,
                                    clientY: wpcPtr617.y,
                                    relatedTarget: document.body
                                });
                                a.dispatchEvent(ev);
                            };
                            fire("mousemove", true, 0);
                            fire("mousemove", true, 1);
                            fire("mousemove", true, 2);
                            fire("mouseover", true, 2);
                            fire("mouseenter", false, 2);
                        }
                    }
                }
            } catch (e) {
                stop = true;
            }
            if (!stop && i + 1 < wpcHovReps617.length) {
                setTimeout((function() { wpcHover617(i + 1); }), wpcHovReps617[i + 1]);
            }
        };
        setTimeout((function() { wpcHover617(0); }), wpcHovReps617[0]);
    }), {
        once: true
    });
})();

(function() {
    "use strict";
    try {
        window.addEventListener("wpc-scripts-loaded", (function() {
            try {
                localStorage.setItem("fresh", String(Date.now()));
            } catch (e) {}
        }), {
            once: true
        });
        var wpcEng = 0;
        try {
            wpcEng = +localStorage.getItem("fresh") || +localStorage.getItem("wpcEngaged") || 0;
            if (!wpcEng && sessionStorage.getItem("wpcEngaged") === "1") {
                wpcEng = Date.now();
            }
        } catch (e) {}
        
        
        
        
        
        
        var wpcEvi638 = false;
        try {
            var wpcNav638 = performance.getEntriesByType("navigation")[0] || {};
            wpcEvi638 = !!(document.referrer && document.referrer.length > 0)
                || wpcNav638.type === "back_forward"
                || /(^#|[#&])wpch\b/.test(location.hash || "");
        } catch (e) {}
        if ((wpcEng && Date.now() - wpcEng < 6048e5) || wpcEvi638) {
            var kick = function() {
                setTimeout((function() {
                    try {
                        window.wpcSwapLateBarrier && window.wpcSwapLateBarrier();
                    } catch (e) {}
                    try {
                        window.wpcStartDelayed && window.wpcStartDelayed();
                    } catch (e) {}
                    requestAnimationFrame((function() {
                        requestAnimationFrame((function() {
                            try {
                                document.dispatchEvent(new Event("scroll"));
                            } catch (e) {}
                        }));
                    }));
                }), 50);
            };
            
            var kickT0 = Date.now();
            var kickPoll = function() {
                var nav = null;
                try {
                    nav = performance.getEntriesByType("navigation")[0];
                } catch (e) {}
                if ((nav && nav.loadEventEnd > 0) || Date.now() - kickT0 > 6e3) {
                    try {
                        window.wpcPreloadDelayed && window.wpcPreloadDelayed();
                    } catch (e) {}
                    kick();
                    return;
                }
                setTimeout(kickPoll, 100);
            };
            setTimeout(kickPoll, 100);
        }
    } catch (e) {}
})();

(function() {
    "use strict";
    if (window.__wpcV3Native) {
        return;
    }
    window.__wpcV3Native = true;
    var wpcSweepArmed = false;
    try {
        
        
        window.addEventListener("wpc-scripts-loaded", (function() {
            window.__wpcEngaged = 1;
            try {
                window.wpcIconFaces && window.wpcIconFaces();
            } catch (e) {}
        }), {
            once: true
        });
    } catch (e) {}
    function wpcCritSweep() {
        if (wpcSweepArmed) {
            return;
        }
        
        
        
        
        
        
        if (!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.critSweepGesture === 0) && !window.__wpcCsg105) {
            if (!window.__wpcCsg105w) {
                window.__wpcCsg105w = 1;
                [ "pointerdown", "keydown", "touchstart", "wheel", "scroll" ].forEach(function (ev) {
                    window.addEventListener(ev, function () {
                        window.__wpcCsg105 = 1;
                        try { wpcCritSweep(); } catch (e) {}
                    }, { once: true, passive: true, capture: true });
                });
            }
            return;
        }
        wpcSweepArmed = true;
        
        
        var sheetOk = function(l) {
            
            
            
            var s = l.sheet;
            if (!s) {
                return false;
            }
            try {
                return s.cssRules.length > 0;
            } catch (e) {
                return true;
            }
        };
        var usedApplied = function() {
            var us = [].slice.call(document.querySelectorAll("link[data-wpc-ucss]"));
            for (var i = 0; i < us.length; i++) {
                var tm = us[i].getAttribute("data-wpc-ucss") || "";
                if (!tm) {
                    continue;
                }
                try {
                    if (window.matchMedia && !window.matchMedia(tm).matches) {
                        continue;
                    }
                } catch (e) {}
                if (us[i].getAttribute("media") !== tm || !sheetOk(us[i])) {
                    return false;
                }
            }
            return true;
        };
        
        
        
        
        var flippedSettled = function() {
            var fl = [].slice.call(document.querySelectorAll('link[data-wpc-flip]'));
            for (var i = 0; i < fl.length; i++) {
                var mq = fl[i].getAttribute("media") || "";
                if (mq && mq !== "all" && mq !== "print") {
                    try {
                        if (window.matchMedia && !window.matchMedia(mq).matches) {
                            continue;
                        }
                    } catch (e) {}
                }
                if (!sheetOk(fl[i])) {
                    return false;
                }
            }
            return true;
        };
        var tries = 0;
        var attempt = function() {
            if (!usedApplied() || !flippedSettled()) {
                if (tries++ < 150) {
                    setTimeout(attempt, 100);
                }
                return;
            }
            
            
            
if (!window.__wpcEngaged) {
                if (!window.__wpcSweepWait) {
                    window.__wpcSweepWait = 1;
                    var reHum = function() {
                        if (window.__wpcEngaged) {
                            attempt();
                            return;
                        }
                        setTimeout(reHum, 800);
                    };
                    setTimeout(reHum, 800);
                }
                return;
            }
            requestAnimationFrame((function() {
                requestAnimationFrame((function() {
                    var c = document.getElementById("wpc-critical-css");
                    var fs = document.getElementById("wpc-font-subsets");
                    
                    
                    if (fs && fs.getAttribute("data-wpc-v2") !== "1"
                        && fs.getAttribute("data-wpc-critless") !== "1") {
                        fs.remove();
                    }
                    if (!c) {
                        return;
                    }
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    var sig = function(el, s) {
                        s = s || getComputedStyle(el);
                        return s.display + "|" + s.visibility + "|" + s.backgroundColor + "|" + s.color + "|" + s.backgroundImage + "|" + s.fontFamily;
                    };
                    var watch = [], snap = [];
                    
                    
                    
                    
                    
                    
                    requestAnimationFrame((function () {
                    try {
                        
                        
                        
                        
                        var vh = (window.innerHeight || 800) * 1.1;
                        var all = document.body ? document.body.getElementsByTagName("*") : [];
                        var lim = Math.min(all.length, 1200);
                        for (var i = 0; i < lim && watch.length < 160; i++) {
                            var el = all[i];
                            var r = el.getBoundingClientRect();
                            var cs = null;
                            if (r.width > 0 && r.height > 0) {
                                if (r.top < vh) {
                                    cs = getComputedStyle(el);
                                }
                            } else {
                                cs = getComputedStyle(el);
                                if (cs.display !== "none") {
                                    cs = null;
                                }
                            }
                            if (cs) {
                                watch.push(el);
                                snap.push(sig(el, cs));
                            }
                        }
                    } catch (e) {}
                    
                    
                    
                    
                    try {
                        if (!document.getElementById("wpc-crit-faces")) {
                            var ff = String(c.textContent || "").match(/@font-face\s*\{[^}]*\}/gi);
                            if (ff && ff.length) {
                                var fst = document.createElement("style");
                                fst.id = "wpc-crit-faces";
                                fst.textContent = ff.join("");
                                (c.parentNode || document.head).insertBefore(fst, c);
                            }
                        }
                    } catch (e) {}
                    var par = c.parentNode;
                    
                    
                    
                    
                    
                    
                    var anc = c.nextSibling;
                    c.remove();
                    requestAnimationFrame((function() {
                        try {
                            for (var j = 0; j < watch.length; j++) {
                                if (watch[j].isConnected && sig(watch[j]) !== snap[j]) {
                                    var p = par || document.head;
                                    if (anc && anc.parentNode === p) {
                                        p.insertBefore(c, anc);
                                    } else {
                                        p.appendChild(c);
                                    }
                                    return;
                                }
                            }
                        } catch (e) {}
                    }));
                    }));
                }));
            }));
        };
        setTimeout(attempt, 100);
    }
    
    
    
    
    
    function wpcImgBelt238(all) {
        try {
            if (!all && window.__wpcPixelAlive) { return; }
            var picks269 = [];
            [].slice.call(document.querySelectorAll("img[data-src]")).forEach(function(im) {
                try {
                    if ((im.getAttribute("src") || "").indexOf("data:image/svg") !== 0) { return; }
                    if (!all) {
                        var r = im.getBoundingClientRect();
                        if (!(r.width > 0 && r.bottom >= -50 && r.top <= (window.innerHeight || 800) + 50 && getComputedStyle(im).display !== "none")) { return; }
                    }
                    picks269.push(im);
                } catch (e) {}
            });
            picks269.forEach(function(im) {
                try {
                    im.src = im.getAttribute("data-src");
                    if (im.getAttribute("data-srcset")) { im.srcset = im.getAttribute("data-srcset"); }
                    var pb254 = im.parentNode;
                    if (pb254 && pb254.tagName === "PICTURE") {
                        [].slice.call(pb254.querySelectorAll("source[data-srcset]")).forEach(function(sq) {
                            sq.setAttribute("srcset", sq.getAttribute("data-srcset"));
                            sq.removeAttribute("data-srcset");
                        });
                    }
                } catch (e) {}
            });
            
            
            
            
            if (all) {
                [].slice.call(document.querySelectorAll("picture source[data-srcset]")).forEach(function(sq) {
                    try {
                        
                        
                        
                        
                        if ((sq.getAttribute("data-srcset") || "").indexOf("data:") === 0) {
                            sq.parentNode.removeChild(sq);
                            return;
                        }
                        var pi297 = sq.parentNode ? sq.parentNode.querySelector("img") : null;
                        if (pi297 && (pi297.getAttribute("src") || "").indexOf("data:image/svg") === 0) { return; }
                        sq.setAttribute("srcset", sq.getAttribute("data-srcset"));
                        sq.removeAttribute("data-srcset");
                    } catch (e) {}
                });
                [].slice.call(document.querySelectorAll('picture source[srcset^="data:"]')).forEach(function(sq) {
                    try { sq.parentNode.removeChild(sq); } catch (e) {}
                });
            }
        } catch (e) {}
        
        
        
        
        if (all && document.readyState !== "complete") {
            setTimeout(function() { wpcImgBelt238(true); }, 900);
        }
    }
    setTimeout(function() { wpcImgBelt238(false); }, 5000);
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    window.wpcPin342 = window.wpcPin342 || function(el, played) {
        try {
            el.style.setProperty("opacity", "1", "important");
            el.style.setProperty("visibility", "visible", "important");
            var kill = function() { try { el.style.setProperty("animation", "none", "important"); } catch (e) {} };
            if (played) {
                var done = false, fin = function() { if (!done) { done = true; kill(); } };
                try { el.addEventListener("animationend", fin, { once: true }); } catch (e) {}
                setTimeout(fin, 5000);
            } else { kill(); }
        } catch (e) {}
    };
    window.wpcAnimScrub335 = window.wpcAnimScrub335 || function(el) {
        try {
            var ks = ["_animation", "_animation_tablet", "_animation_mobile", "animation",
             "animation_tablet", "animation_mobile", "_animation_delay", "animation_delay"];
            var ds = el.getAttribute("data-settings");
            if (ds && ds.indexOf("animation") !== -1) {
                var m = JSON.parse(ds);
                if (m && typeof m === "object") {
                    var hit = false;
                    ks.forEach(function(k) { if (k in m) { delete m[k]; hit = true; } });
                    if (hit) { el.setAttribute("data-settings", JSON.stringify(m)); }
                }
            }
            
            
            
            
            if (window.jQuery) {
                var jd = window.jQuery(el).data("settings");
                if (jd && typeof jd === "object") { ks.forEach(function(k) { try { delete jd[k]; } catch (e2) {} }); }
            }
        } catch (e) {}
    };
    function wpcAnimBelt283() {
        try {
            [].slice.call(document.querySelectorAll(".elementor-invisible")).forEach(function(el) {
                try {
                    if (getComputedStyle(el).visibility !== "hidden") { return; }
                    el.classList.remove("elementor-invisible");
                    el.style.visibility = "visible";
                    try { window.wpcPin342 && window.wpcPin342(el, false); } catch (e3) {}
                    try { window.wpcAnimScrub335(el); } catch (e3) {}
                } catch (e) {}
            });
        } catch (e) {}
    }
    setTimeout(wpcAnimBelt283, 3000);
    
    
    
    
    
    
    
    
    
    
    var wpcEpFired299 = false;
    var wpcWfFired300 = false;
    function wpcEpBelt299(revealOnly) {
        try {
            
            
            
            if (!revealOnly && !wpcWfFired300 && window.jQuery
                && document.querySelector(".wpforms-form input[type=tel]:not([name^='wpf-temp'])")) {
                wpcWfFired300 = true;
                try { window.jQuery(document).trigger("wpformsReady"); } catch (e) {}
            }
            
            
            
            
            
            try {
                if (!revealOnly && window.WPForms && window.WPForms.Analytics && window.WPForms.Analytics.getState) {
                    [].slice.call(document.querySelectorAll(".wpforms-form[data-formid]")).forEach(function(f) {
                        try {
                            var wpcSt301 = window.WPForms.Analytics.getState(parseInt(f.getAttribute("data-formid"), 10));
                            if (wpcSt301 && !wpcSt301.fields) { wpcSt301.fields = {}; }
                        } catch (e) {}
                    });
                }
            } catch (e) {}
            
            
            
            
            
            
            
            
            
            
            
            
            
            var wpcEfReady321 = function() {
                return !!(window.jQuery && window.elementorFrontend
                    && window.elementorFrontend.elementsHandler && window.elementorFrontend.elementsHandler.runReadyTrigger);
            };
            var wpcEfFire321 = function() {
                
                
                
                if (wpcEpFired299 || !wpcEfReady321()) { return; }
                wpcEpFired299 = true;
                try { window.wpcEfDiffFire33 && window.wpcEfDiffFire33(); } catch (e) {}
            };
            
            
            
            
            var wpcFam321 = [
                { sel: ".swiper:not(.swiper-initialized)", widget: ".elementor-widget", ok: function(w) { return true; } },
                { sel: ".elementor-widget-n-tabs:not([data-wpc-rt321])", widget: ".elementor-widget-n-tabs", ok: function(w) { return !w.querySelector('[role="tab"][aria-selected="true"]'); } },
                { sel: ".elementor-widget-tabs:not([data-wpc-rt321]) .elementor-tabs", widget: ".elementor-widget-tabs", ok: function(w) { return !w.querySelector(".elementor-tab-title.elementor-active"); } }
            ];
            var wpcRunTrig320 = function() {
                if (!wpcEfReady321()) { return -1; }
                wpcEfFire321();
                var n = 0;
                wpcFam321.forEach(function(fam) {
                    [].slice.call(document.querySelectorAll(fam.sel)).forEach(function(el) {
                        try {
                            var w = el.closest ? el.closest(fam.widget) : null;
                            if (!w || w.getAttribute("data-wpc-rt321") === "1" || !fam.ok(w)) { return; }
                            
                            
                            
                            
                            
                            try {
                                if (w.classList.contains("elementor-invisible")) { w.classList.remove("elementor-invisible"); }
                                window.wpcAnimScrub335 && window.wpcAnimScrub335(w);
                                window.wpcPin342 && window.wpcPin342(w, false);
                            } catch (e4) {}
                            if (window.wpcRtHeal364 && window.wpcRtHeal364(w)) { n++; }
                        } catch (e) {}
                    });
                });
                return n;
            };
            
            
            
            
            
            
            
            
            
            
            var wpcModHeal322 = function() {
                try {
                    if (!window.jQuery || !jQuery._data) { return; }
                    var mev = jQuery._data(document, "events");
                    if (!mev || !mev.modula_api_after_init) { return; }
                    [].slice.call(document.querySelectorAll(".modula-gallery-initialized")).forEach(function(g) {
                        try {
                            var gev = jQuery._data(g, "events");
                            if (gev && gev.click && gev.click.length) { return; }
                            var inst = jQuery(g).data("plugin_modulaGallery");
                            if (inst) { jQuery(document).trigger("modula_api_after_init", [inst]); }
                        } catch (e) {}
                    });
                } catch (e) {}
            };
            
            
            
            
            
            
            
            
            
            
            
            var wpcDiviFired324 = false;
            var wpcDiviHeal324 = function() {
                try {
                    if (wpcDiviFired324 || typeof window.et_pb_init_modules !== "function") { return; }
                    var gs = [].slice.call(document.querySelectorAll(".et_pb_gallery_grid"));
                    if (!gs.length) { return; }
                    var stranded = gs.some(function(g) {
                        var its = [].slice.call(g.querySelectorAll(".et_pb_gallery_item"));
                        return its.length > 0 && its.every(function(it) {
                            return getComputedStyle(it).display === "none";
                        });
                    });
                    if (!stranded) { return; }
                    wpcDiviFired324 = true;
                    window.et_pb_init_modules();
                } catch (e) {}
            };
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            var wpcGalHeal347 = function() {
                try {
                    [].slice.call(document.querySelectorAll(".e-gallery-image[data-thumbnail]")).forEach(function(el) {
                        try {
                            var bg = getComputedStyle(el).backgroundImage;
                            if (bg && bg !== "none") { return; }
                            var th = el.getAttribute("data-thumbnail");
                            if (!th || !/^https?:/i.test(th)) { return; }
                            el.style.backgroundImage = 'url("' + th.replace(/"/g, '%22') + '")';
                            el.classList.add("e-gallery-image-loaded");
                        } catch (e) {}
                    });
                } catch (e) {}
            };
            
            
            
            
            
            
            
            
            var wpcYtFired347 = false;
            var wpcYtTries347 = 0;
            var wpcYtHeal347 = function() {
                try {
                    if (wpcYtFired347 || wpcYtTries347++ > 15) { return; }
                    
                    if (!window.YT || window.YT.loaded !== 1 || typeof window.onYouTubeIframeAPIReady !== "function") {
                        setTimeout(wpcYtHeal347, 800);
                        return;
                    }
                    var empty = [].slice.call(document.querySelectorAll(".sby_player_wrap")).some(function(w) { return !w.querySelector("iframe,video"); });
                    if (!empty) { return; }
                    wpcYtFired347 = true;
                    window.onYouTubeIframeAPIReady();
                } catch (e) {}
            };
            var wpcPopHeal321 = function() {
                try {
                    var ef = window.elementorFrontend, pf = window.elementorProFrontend;
                    if (!ef || !ef.documentsManager || !ef.hooks || !pf || !pf.modules) { return; }
                    var dm = ef.documentsManager;
                    if (dm.documentClasses && dm.documentClasses.popup) { return; }
                    ef.hooks.doAction("elementor/frontend/documents-manager/init-classes", dm);
                    for (var k in pf.modules) {
                        var m = pf.modules[k];
                        if (m && typeof m.onFrontendComponentsInit === "function") {
                            try { m.onFrontendComponentsInit(); } catch (e) {}
                        }
                    }
                    var stuck = [].slice.call(document.querySelectorAll('[data-elementor-type="popup"]')).filter(function(e) {
                        return !e.closest(".elementor-popup-modal");
                    });
                    stuck.forEach(function(e) {
                        try { delete dm.documents[e.getAttribute("data-elementor-id")]; } catch (x) {}
                    });
                    if (stuck.length) { try { dm.attachDocumentsClasses(); } catch (e) {} }
                } catch (e) {}
            };
            
            if (!revealOnly) { wpcEfFire321(); wpcRunTrig320(); wpcPopHeal321(); wpcModHeal322(); wpcDiviHeal324(); }
            setTimeout(wpcGalHeal347, 1200);
            setTimeout(wpcYtHeal347, 1200);
            setTimeout(function() {
                if (!revealOnly) { wpcRunTrig320(); wpcPopHeal321(); wpcModHeal322(); wpcDiviHeal324(); } 
                wpcGalHeal347();
                [].slice.call(document.querySelectorAll(".swiper:not(.swiper-initialized)")).forEach(function(sw) {
                    try {
                        var cs = getComputedStyle(sw);
                        if (cs.visibility === "hidden" || cs.opacity === "0") { sw.style.visibility = "visible"; sw.style.opacity = "1"; }
                    } catch (e) {}
                });
            }, 1600);
            setTimeout(function() {
                
                
                
                if (!revealOnly) { wpcRunTrig320(); }
            }, 6200);
        } catch (e) {}
    }
    
    
    
    
    
    window.wpcOwnV312 = window.wpcOwnV312 || {};
    window.wpcOwn312 = window.wpcOwn312 || function(c) {
        window.wpcOwnV312[c] = 1;
        try { document.documentElement.classList.add(c); } catch (e) {}
    };
    try {
        if (!window.wpcOwnO312) {
            window.wpcOwnO312 = 1;
            new MutationObserver(function() {
                var de = document.documentElement;
                for (var c in window.wpcOwnV312) {
                    if (!de.classList.contains(c)) { de.classList.add(c); }
                }
            }).observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
        }
    } catch (e) {}
    try {
        window.addEventListener("wpc-scripts-loaded", function() {
            
            
            
            var wpcStamp303 = function() {
                try {
                    if (document.querySelector(".elementor-nav-menu li.menu-item-has-children:hover")) {
                        setTimeout(wpcStamp303, 250);
                        return;
                    }
                    wpcOwn312("wpc-js-live");
                } catch (e) {
                    try { wpcOwn312("wpc-js-live"); } catch (x) {}
                }
            };
            wpcStamp303();
            setTimeout(function() { wpcEpBelt299(false); }, 1200);
        });
    } catch (e) {}
    setTimeout(function() { if (!wpcG235) { wpcEpBelt299(true); } }, 4000);
    var wpcG235 = false, wpcGQ235 = [];
    function wpcOnGesture235(f) {
        if (wpcG235) { f(); return; }
        wpcGQ235.push(f);
    }
    (function() {
        var gl235 = [ "pointerdown", "keydown", "touchstart", "scroll", "mousemove", "wheel", "click" ];
        var fire235 = function() {
            if (wpcG235) { return; }
            wpcG235 = true;
            wpcOwn312("wpc-bgl255");
            gl235.forEach(function(ev) { try { window.removeEventListener(ev, fire235, true); } catch (e) {} });
            wpcGQ235.splice(0).forEach(function(f) { try { f(); } catch (e) {} });
            setTimeout(function() { wpcImgBelt238(true); }, 400);
            setTimeout(wpcAnimBelt283, 2500);
        };
        gl235.forEach(function(ev) { window.addEventListener(ev, fire235, { passive: true, capture: true }); });
        
        
        
        
        if ((typeof window.pageYOffset === "number" ? window.pageYOffset : 0) > 0
            || (document.documentElement.classList && document.documentElement.classList.contains("wpc-bgl255"))) { fire235(); }
    })();
    
    
    
    
    
    function wpcCssLive29() {
        try {
            if (!document.querySelector('link[data-wpc-rest]:not([href])')) { wpcOwn312("wpc-css-live"); return; }
            if (wpcCssLive29.w) { return; }
            wpcCssLive29.w = 1;
            var iv = setInterval(function() {
                if (!document.querySelector('link[data-wpc-rest]:not([href])')) { clearInterval(iv); wpcOwn312("wpc-css-live"); }
            }, 60);
            setTimeout(function() { clearInterval(iv); wpcOwn312("wpc-css-live"); }, 4000);
        } catch (e) { wpcOwn312("wpc-css-live"); }
    }
    
    
    
    
    
    
    
    
    
    var wpcUcssSeen235 = !!document.querySelector("link[data-wpc-ucss],link[data-wpc-ucss-rest]");
    function wpcUcssMark235() {
        if (!wpcUcssSeen235) {
            try { wpcUcssSeen235 = !!document.querySelector("link[data-wpc-ucss],link[data-wpc-ucss-rest]"); } catch (e) {}
        }
    }
    if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", wpcUcssMark235); } else { wpcUcssMark235(); }
    
    
    
    
    
    
    
    
    
    
    
    
    var wpcNoUcssBelt294 = false;
    function wpcNoUcssFlush295() {
        if (wpcNoUcssBelt294 || wpcG235 || wpcUcssSeen235) { return; }
        wpcUcssMark235();
        if (wpcUcssSeen235) { return; }
        wpcNoUcssBelt294 = true;
        wpcG235 = true;
        wpcOwn312("wpc-bgl255");
        wpcGQ235.splice(0).forEach(function(f) { try { f(); } catch (e) {} });
        setTimeout(function() { wpcImgBelt238(true); }, 400);
    }
    
    
    
    
    try {
        var wpcPrevVis295 = document.onvisibilitychange;
        document.onvisibilitychange = function(ev) {
            try { if (wpcPrevVis295) { wpcPrevVis295.call(this, ev); } } catch (e) {}
            if (document.visibilityState === "hidden") { wpcNoUcssFlush295(); }
        };
    } catch (e) {}
    if (+((window.wpcDelayV3Cfg || {}).noUcssBeltMs) > 0) {
        setTimeout(wpcNoUcssFlush295, +window.wpcDelayV3Cfg.noUcssBeltMs);
    }
    function wpcRestoreGesture235() {
        wpcUcssMark235();
        return !wpcG235 && (wpcUcssSeen235 || !wpcNoUcssBelt294)
            && !(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.restoreGesture === 0);
    }
    function swapStyles() {
        if (wpcRestoreGesture235()) {
            wpcOnGesture235(swapStyles);
            return;
        }
        try {
            var wpcFsub69 = document.getElementById("wpc-font-subsets");
            if (wpcFsub69 && wpcFsub69.getAttribute("data-wpc-v2") === "1" && document.body && wpcFsub69 !== document.body.lastElementChild) {
                document.body.appendChild(wpcFsub69);
            }
        } catch (e) {}
        var sel = '[rel="wpc-stylesheet"],[type="wpc-stylesheet"],[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]';
        var list = [].slice.call(document.querySelectorAll(sel));
        if (!list.length) {
            
            
            
            
            
            
            wpcCssLive29();
            if (document.querySelector("link[data-wpc-ucss]")) {
                wpcCritSweep();
            }
            return;
        }
        var okCount = 0, total = list.length;
        
        
        
        
        
        
        var wpcHeld = [], wpcRestored = 0, wpcHeldStyles = [];
        var wpcRestoreAll = function() {
            if (wpcRestored) {
                return;
            }
            wpcRestored = 1;
            for (var wi = 0; wi < wpcHeld.length; wi++) {
                try {
                    wpcHeld[wi][0].setAttribute("media", wpcHeld[wi][1]);
                } catch (e) {}
            }
            wpcHeld = [];
            
            
            for (var si = 0; si < wpcHeldStyles.length; si++) {
                try {
                    wpcHeldStyles[si].removeAttribute("data-wpc-hold-style");
                    wpcHeldStyles[si].setAttribute("type", "text/css");
                } catch (e) {}
            }
            wpcHeldStyles = [];
        };
        
        
        
        var wpcAtomic = !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.atomicCascade === 1);
        if (wpcAtomic) { setTimeout(wpcRestoreAll, 1e4); }
        
        
        
        
        
        
        var wpcHoldInline = wpcAtomic
            && !(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssBlockingFlip === 1)
            && list.some(function(el) {
                return el.tagName.toLowerCase() === "link" && !(el.id && el.id.indexOf("wpc-used-css") === 0);
            });
        var ps = list.map((function(el) {
            return new Promise((function(res) {
                var done = false;
                var finish = function(ok) {
                    if (done) {
                        return;
                    }
                    done = true;
                    if (ok) {
                        okCount++;
                    }
                    res();
                };
                el.addEventListener("load", (function() {
                    finish(true);
                }), {
                    once: true
                });
                el.addEventListener("error", (function() {
                    try {
                        var h = el.getAttribute("href") || "";
                        var ai = h.indexOf("/a:");
                        if (ai !== -1 && !el.__wpcFb) {
                            el.__wpcFb = 1;
                            var origin = h.substring(ai + 3);
                            if (origin.indexOf("http") === 0) {
                                var l2 = document.createElement("link");
                                l2.rel = "stylesheet";
                                l2.href = origin;
                                l2.setAttribute("data-wpc-flip", "1");
                                el.removeAttribute("data-wpc-flip");
                                l2.addEventListener("load", (function() {
                                    finish(true);
                                }), {
                                    once: true
                                });
                                l2.addEventListener("error", (function() {
                                    finish(false);
                                }), {
                                    once: true
                                });
                                (document.head || document.documentElement).appendChild(l2);
                                return;
                            }
                        }
                    } catch (e) {}
                    finish(false);
                }), {
                    once: true
                });
                if (el.id && el.id.indexOf("wpc-used-css") === 0) {
                    
                    
                    if (el.getAttribute("rel") !== "stylesheet") {
                        el.setAttribute("rel", "stylesheet");
                    }
                    el.setAttribute("type", "text/css");
                    setTimeout((function() {
                        finish(false);
                    }), 8e3);
                    return;
                }
                if (el.tagName.toLowerCase() === "link") {
                    el.setAttribute("data-wpc-flip", "1");
                    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssBlockingFlip === 1) {
                        el.setAttribute("rel", "stylesheet");
                    } else if (!el.__wpcMediaFlip) {
                        el.__wpcMediaFlip = 1;
                        var wpcRealMedia = el.getAttribute("media") || "all";
                        el.setAttribute("media", "print");
                        if (wpcAtomic) {
                            wpcHeld.push([ el, wpcRealMedia ]);
                        } else {
                            el.addEventListener("load", (function() {
                                try { el.setAttribute("media", wpcRealMedia); } catch (e) {}
                            }), { once: true });
                        }
                        el.setAttribute("rel", "stylesheet");
                    }
                } else if (document.querySelector('link[rel="wpc-late-stylesheet"], link[data-wpc-tm][media="print"]:not([data-wpc-tm="print"])')) {
                    
                    
                    
                    
                    
                    
                    
                    el.setAttribute("data-wpc-hold-style", "1");
                    finish(false);
                    return;
                } else if (wpcHoldInline) {
                    
                    
                    el.setAttribute("data-wpc-hold-style", "lane");
                    wpcHeldStyles.push(el);
                    finish(false);
                    return;
                }
                el.setAttribute("type", "text/css");
                setTimeout((function() {
                    finish(false);
                }), 6e3);
            }));
        }));
        Promise.all(ps).then((function() {
            
            
            wpcRestoreAll();
            wpcCssLive29();
            
            
            if (document.querySelector("link[data-wpc-ucss]") || okCount >= Math.ceil(total * .5)) {
                wpcCritSweep();
            }
        }));
    }
    function isHeavyEmbed(u) {
        var list = window.wpcDelayV3Cfg && Array.isArray(window.wpcDelayV3Cfg.heavyEmbeds) ? window.wpcDelayV3Cfg.heavyEmbeds : [];
        for (var i = 0; i < list.length; i++) {
            if (u.indexOf(list[i]) !== -1) {
                return true;
            }
        }
        return false;
    }
    var ambientQ = [], ambientArmed = false, ambientHuman = false;
    function isAmbientMedia(el) {
        var p = el.parentElement, t = p ? p.tagName.toLowerCase() : "";
        return (t === "video" || t === "audio") && p.hasAttribute("autoplay") && (p.muted || p.hasAttribute("muted"));
    }
    function armAmbient() {
        if (ambientArmed) {
            return;
        }
        ambientArmed = true;
        var evs = ["pointerdown", "touchstart", "keydown", "wheel", "touchmove", "mousemove"], fired = false;
        function go(e) {
            if (fired || (e && e.isTrusted === false)) {
                return;
            }
            fired = true;
            ambientHuman = true;
            evs.forEach(function(n) {
                try {
                    removeEventListener(n, go, true);
                } catch (x) {}
            });
            var q = ambientQ.slice();
            ambientQ = [];
            q.forEach(function(fn) {
                try {
                    fn();
                } catch (x) {}
            });
        }
        evs.forEach(function(n) {
            addEventListener(n, go, {
                capture: true,
                passive: true
            });
        });
    }
    function restoreFrame(el, u) {
        if (isAmbientMedia(el) && !ambientHuman) {
            ambientQ.push(function() {
                restoreFrame(el, u);
            });
            armAmbient();
            return;
        }
        el.setAttribute("src", u);
        el.removeAttribute("data-wpc-src");
        el.classList.remove("wpc-iframe-delay");
        if (el.hasAttribute("data-wpc-pe")) {
            el.style.pointerEvents = el.getAttribute("data-wpc-pe") === "1" ? "" : el.getAttribute("data-wpc-pe");
            el.removeAttribute("data-wpc-pe");
        }
        var p = el.parentElement, pt = p ? p.tagName.toLowerCase() : "";
        if (p && (pt === "video" || pt === "audio")) {
            var so = document.createElement("source");
            so.src = u;
            so.type = pt === "audio" ? "audio/mpeg" : "video/mp4";
            [].slice.call(p.querySelectorAll("source")).forEach((function(x) {
                x.remove();
            }));
            p.appendChild(so);
            p.load();
            if (p.hasAttribute("autoplay")) {
                var pr = p.play();
                if (pr && pr.catch) {
                    pr.catch((function() {}));
                }
            }
        }
    }
    function frames(heavyOnly) {
        [].slice.call(document.querySelectorAll(".wpc-iframe-delay")).forEach((function(el) {
            var u = el.getAttribute("data-wpc-src");
            if (!u || !u.trim()) {
                return;
            }
            u = u.trim();
            if (isHeavyEmbed(u) !== !!heavyOnly) {
                return;
            }
            restoreFrame(el, u);
        }));
    }
    
    
    
    
    var frameIO = null;
    function framesIO() {
        var els = [].slice.call(document.querySelectorAll(".wpc-iframe-delay"));
        if (!els.length || !window.IntersectionObserver) {
            return;
        }
        if (!frameIO) {
            frameIO = new IntersectionObserver((function(entries) {
                entries.forEach((function(en) {
                    if (!en.isIntersecting) {
                        return;
                    }
                    var el = en.target, u = el.getAttribute("data-wpc-src");
                    
                    
                    
                    
                    
                    
                    
                    
                    if (u && isHeavyEmbed(u.trim()) && !window.__wpcEngaged) {
                        var r = en.boundingClientRect;
                        if (!(r && r.top < (window.innerHeight || 0) && r.bottom > 0)) {
                            return;
                        }
                    }
                    frameIO.unobserve(el);
                    if (u && u.trim()) {
                        restoreFrame(el, u.trim());
                    }
                }));
            }), {
                rootMargin: "400px"
            });
        }
        els.forEach((function(el) {
            frameIO.observe(el);
        }));
    }
    var bgIO = null;
    function bgLazy() {
        var els = [].slice.call(document.querySelectorAll(".wpc-bgLazy"));
        if (!els.length) {
            return;
        }
        if (!("IntersectionObserver" in window)) {
            els.forEach((function(el) {
                el.classList.remove("wpc-bgLazy");
            }));
            return;
        }
        if (!bgIO) {
            bgIO = new IntersectionObserver((function(entries) {
                entries.forEach((function(en) {
                    if (en.isIntersecting) {
                        bgIO.unobserve(en.target);
                        en.target.classList.remove("wpc-bgLazy");
                    }
                }));
            }), {
                rootMargin: "200px 0px"
            });
        }
        els.forEach((function(el) {
            bgIO.observe(el);
        }));
    }
    function reveal() {
        [].slice.call(document.querySelectorAll(".wpc-delay-elementor")).forEach((function(el) {
            el.classList.remove("wpc-delay-elementor");
        }));
        bgLazy();
        atfAnimReveal();
        concealReveal();
    }
    function concealReveal() {
        if (animOff || window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.atfReveal === 0) {
            return;
        }
        var list = window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.conceal;
        if (!list || !list.length) {
            return;
        }
        list.forEach((function(cls) {
            [].slice.call(document.querySelectorAll("." + cls)).forEach((function(el) {
                try {
                    var live = false, at = el.attributes, i, s;
                    for (i = 0; i < at.length; i++) {
                        if (at[i].name === "class") {
                            continue;
                        }
                        s = (at[i].name + " " + at[i].value).toLowerCase();
                        if (s.indexOf("condition") !== -1) {
                            live = true;
                            break;
                        }
                    }
                    if (!live) {
                        el.classList.remove(cls);
                    }
                } catch (e) {}
            }));
        }));
    }
    var animIO = null, animOff = false, animBooted = false;
    function atfAnimReveal() {
        if (animOff || window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.atfReveal === 0) {
            return;
        }
        var els = [].slice.call(document.querySelectorAll(".elementor-invisible"));
        if (!els.length) {
            return;
        }
        var show = function(el) {
            el.classList.remove("elementor-invisible");
            try {
                var ds = el.getAttribute("data-settings");
                if (ds && ds.indexOf("nimation") !== -1) {
                    var o = JSON.parse(ds), ch = false;
                    [ "_animation", "animation", "_animation_mobile", "animation_mobile", "_animation_tablet", "animation_tablet" ].forEach((function(k) {
                        if (k in o) {
                            delete o[k];
                            ch = true;
                        }
                    }));
                    if (ch) {
                        el.setAttribute("data-settings", JSON.stringify(o));
                    }
                }
            } catch (e) {}
        };
        if (!("IntersectionObserver" in window)) {
            els.forEach(show);
            return;
        }
        if (!animIO) {
            animIO = new IntersectionObserver((function(entries) {
                entries.forEach((function(en) {
                    if (!en.isIntersecting) {
                        return;
                    }
                    animIO.unobserve(en.target);
                    if (!animBooted) {
                        show(en.target);
                        return;
                    }
                    setTimeout((function() {
                        try {
                            if (en.target.classList.contains("elementor-invisible")) {
                                show(en.target);
                            }
                        } catch (e) {}
                    }), 1e3);
                }));
            }), {
                rootMargin: "25% 0px"
            });
        }
        els.forEach((function(el) {
            animIO.observe(el);
        }));
    }
    var wpcPainted133 = false;
    function tick() {
        try {
            reveal();
            if (wpcPainted133) {
                swapStyles();
            }
            frames(false);
            framesIO();
        } catch (e) {}
    }
    tick();
    
    
    
    
    
    function wpcReleaseStyles528() {
        if (wpcPainted133) return;
        wpcPainted133 = true;
        try { swapStyles(); } catch (e) {}
    }
    try {
        var wpcLcpSeen528 = false;
        if (window.PerformanceObserver) {
            try {
                var po528 = new PerformanceObserver(function (l) {
                    if (l.getEntries().length) {
                        wpcLcpSeen528 = true;
                        try { po528.disconnect(); } catch (e) {}
                        requestAnimationFrame(function () { wpcReleaseStyles528(); });
                    }
                });
                po528.observe({ type: 'largest-contentful-paint', buffered: true });
            } catch (e) {}
        }
        
        ['pointerdown','keydown','touchstart','scroll'].forEach(function (ev) {
            window.addEventListener(ev, wpcReleaseStyles528, { once: true, passive: true });
        });
        
        setTimeout(wpcReleaseStyles528, (window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.styleHoldMs) || 3000);
        requestAnimationFrame((function() {
            requestAnimationFrame((function() {
                if (!window.PerformanceObserver || wpcLcpSeen528) { wpcReleaseStyles528(); }
            }));
        }));
    } catch (e) {
        wpcPainted133 = true;
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", tick);
    }
    setTimeout(tick, 1e3);
    setTimeout((function() {
        wpcPainted133 = true;
        tick();
    }), 3e3);
    
    
    
    
    (function() {
        var cvSel = "[data-wpc-cv], section.elementor-top-section, main.elementor-top-section, footer.elementor-top-section, .awb-cv-auto, .wpc-delay-avada";
        
        
        
        
        
        
        
        
        try {
            if (window.IntersectionObserver) {
                var cvIo = new IntersectionObserver(function(ents) {
                    for (var ci = 0; ci < ents.length; ci++) {
                        if (ents[ci].isIntersecting) {
                            try { ents[ci].target.style.contentVisibility = "visible"; } catch (e) {}
                            cvIo.unobserve(ents[ci].target);
                        }
                    }
                }, { rootMargin: "25% 0px" });
                [].slice.call(document.querySelectorAll(cvSel)).forEach((function(el) {
                    cvIo.observe(el);
                }));
            }
        } catch (e) {}
        var cvGone = false;
        var cvGo = function() {
            if (cvGone) return;
            cvGone = true;
            [ "scroll", "touchstart", "keydown", "pointerdown" ].forEach((function(ev) {
                window.removeEventListener(ev, cvGo, { passive: true });
            }));
            try {
                var cvEls = [].slice.call(document.querySelectorAll(cvSel));
                var cvIdx = 0;
                
                
                
                
                
                var cvStep = function() {
                    var w = [];
                    while (cvIdx < cvEls.length && w.length < 2) {
                        var el = cvEls[cvIdx++];
                        try {
                            if (getComputedStyle(el).contentVisibility === "auto") {
                                w.push(el);
                            }
                        } catch (e) {}
                    }
                    for (var wi = 0; wi < w.length; wi++) {
                        try {
                            w[wi].style.contentVisibility = "visible";
                        } catch (e) {}
                    }
                    if (cvIdx < cvEls.length) {
                        (window.requestAnimationFrame || setTimeout)(cvStep);
                    }
                };
                cvStep();
            } catch (e) {}
        };
        if ((typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0) > 0) {
            setTimeout(cvGo, 300);
        } else {
            [ "scroll", "touchstart", "keydown", "pointerdown" ].forEach((function(ev) {
                window.addEventListener(ev, cvGo, { passive: true });
            }));
        }
    })();
    function swapLate() {
        [].slice.call(document.querySelectorAll('[rel="wpc-late-stylesheet"],[type="wpc-late-stylesheet"]')).forEach((function(el) {
            if (el.id && el.id.indexOf("wpc-used-css") === 0 && el.media && window.matchMedia && !window.matchMedia(el.media).matches) {
                try {
                    var mqL = window.matchMedia(el.media);
                    var actL = function() {
                        if (mqL.matches && el.getAttribute("rel") !== "stylesheet") {
                            el.setAttribute("rel", "stylesheet");
                            el.setAttribute("type", "text/css");
                        }
                    };
                    mqL.addEventListener ? mqL.addEventListener("change", actL) : mqL.addListener(actL);
                } catch (e) {}
                return;
            }
            if (wpcRestoreGesture235()) {
                if (!swapLate.__q235) {
                    swapLate.__q235 = 1;
                    wpcOnGesture235(function() { swapLate.__q235 = 0; swapLate(); });
                }
                return;
            }
            if (el.tagName.toLowerCase() === "link") {
                el.addEventListener("error", (function() {
                    try {
                        var h = el.getAttribute("href") || "", ai = h.indexOf("/a:");
                        if (ai !== -1 && !el.__wpcFb) {
                            el.__wpcFb = 1;
                            var origin = h.substring(ai + 3);
                            if (origin.indexOf("http") === 0) {
                                var l2 = document.createElement("link");
                                l2.rel = "stylesheet";
                                l2.href = origin;
                                (document.head || document.documentElement).appendChild(l2);
                            }
                        }
                    } catch (e) {}
                }), {
                    once: true
                });
                
                
                if (!el.getAttribute("data-wpc-tm")) {
                    el.setAttribute("data-wpc-tm", el.media || "all");
                    el.media = "print";
                }
                el.setAttribute("rel", "stylesheet");
            }
            el.setAttribute("type", "text/css");
        }));
    }
    
    
    
    window.wpcIconFaces = function() {
        try {
            var ic = document.getElementById("wpc-icon-faces");
            if (!ic || ic.media === "all") {
                return;
            }
            ic.setAttribute("type", "text/css");
            ic.media = "all";
        } catch (e) {}
    };
    var lateCssDone = false, lateCssWaiters = [];
    function lateCssFinish() {
        if (lateCssDone) {
            return;
        }
        lateCssDone = true;
        try {
            document.querySelectorAll('style[data-wpc-hold-style]').forEach((function(s) {
                s.removeAttribute("data-wpc-hold-style");
                s.setAttribute("type", "text/css");
            }));
            document.querySelectorAll("link[data-wpc-tm]").forEach((function(l) {
                l.media = l.getAttribute("data-wpc-tm") || "all";
            }));
            var lf = document.getElementById("wpc-late-faces");
            if (lf && wpcRestoreGesture235()) {
                wpcOnGesture235(function() {
                    lf.setAttribute("type", "text/css");
                    lf.media = "all";
                });
                lf = null;
            }
            if (lf) {
                lf.setAttribute("type", "text/css");
                lf.media = "all";
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                try {
                    if (document.fonts && document.fonts.forEach) {
                        var lfTxt = null;
                        var lfTextSet = function() {
                            if (lfTxt) { return lfTxt; }
                            lfTxt = {};
                            try {
                                var lfT = ((document.body && document.body.textContent) || "").slice(0, 20000);
                                for (var lfTi = 0; lfTi < lfT.length; lfTi++) {
                                    var lfTc = lfT.charCodeAt(lfTi);
                                    if (lfTc >= 48 && !(lfTc >= 8192 && lfTc <= 8303) && !(lfTc >= 55296 && lfTc <= 57343)
                                        && lfTc !== 9676 && !(lfTc >= 65024 && lfTc <= 65039)) { lfTxt[lfTc] = 1; }
                                }
                            } catch (e) {}
                            return lfTxt;
                        };
                        var lfPick = function(range) {
                            if (!range || range === "U+0-10FFFF") { return 77; }
                            var lfSegs = range.split(",");
                            var lfSet = null;
                            for (var lfSi = 0; lfSi < lfSegs.length && lfSi < 32; lfSi++) {
                                var lfSm = lfSegs[lfSi].match(/U\+([0-9A-Fa-f?]+)(?:-([0-9A-Fa-f]+))?/i);
                                if (!lfSm) { continue; }
                                var lfLo, lfHi;
                                if (lfSm[1].indexOf("?") >= 0) {
                                    lfLo = parseInt(lfSm[1].replace(/\?/g, "0"), 16);
                                    lfHi = parseInt(lfSm[1].replace(/\?/g, "F"), 16);
                                } else {
                                    lfLo = parseInt(lfSm[1], 16);
                                    lfHi = lfSm[2] ? parseInt(lfSm[2], 16) : lfLo;
                                }
                                if (lfLo >= 57344 && (lfHi <= 63743 || lfLo >= 983040)) { return lfLo; }
                                lfSet = lfSet || lfTextSet();
                                for (var lfCk in lfSet) {
                                    lfCk = +lfCk;
                                    if (lfCk >= lfLo && lfCk <= lfHi) { return lfCk; }
                                }
                            }
                            return -1;
                        };
                        var lfUsed = null;
                        var lfUsedSet = function() {
                            if (lfUsed) { return lfUsed; }
                            lfUsed = {};
                            try {
                                var lfEls = document.querySelectorAll("body *");
                                for (var lfUi = 0; lfUi < lfEls.length && lfUi < 600; lfUi++) {
                                    var lfCs = getComputedStyle(lfEls[lfUi]);
                                    var lfStk = (lfCs.fontFamily || "").toLowerCase();
                                    var lfFam = lfStk.split(",")[0].split(String.fromCharCode(34)).join("").split(String.fromCharCode(39)).join("").trim();
                                    if (lfFam) { lfUsed[lfFam + "|" + lfCs.fontWeight + "|" + lfCs.fontStyle] = lfStk.indexOf(lfFam + " fallback") !== -1 ? 2 : 1; }
                                }
                            } catch (e) {}
                            return lfUsed;
                        };
                        var lfWNum = function(w) {
                            w = String(w || "400").toLowerCase();
                            if (w === "bold") { return 700; }
                            if (w === "normal") { return 400; }
                            return parseInt(w, 10) || 400;
                        };
                        var lfMatchUse = function(f) {
                            var lfU = lfUsedSet();
                            var lfFam = String(f.family || "").split(String.fromCharCode(34)).join("").split(String.fromCharCode(39)).join("").trim().toLowerCase();
                            var lfSp = String(f.weight || "400").toLowerCase().split(/\s+/);
                            var lfLoW = lfWNum(lfSp[0]);
                            var lfHiW = lfWNum(lfSp[lfSp.length - 1]);
                            for (var lfK in lfU) {
                                var lfP = lfK.split("|");
                                if (lfP[0] !== lfFam) { continue; }
                                if (lfP[2] !== (f.style || "normal")) { continue; }
                                var lfW = lfWNum(lfP[1]);
                                if (lfW >= lfLoW - 100 && lfW <= lfHiW + 100 && lfU[lfK] === 2) { return true; }
                            }
                            return false;
                        };
                        var lfQ = [];
                        var lfCand291 = [];
                        document.fonts.forEach(function(f) {
                            try {
                                if (f.status !== "unloaded") { return; }
                                if ((f.display || "") === "optional") { return; }
                                var lfCp = lfPick(f.unicodeRange || "");
                                if (lfCp === -1) { return; }
                                if (lfCp < 57344) { lfCand291.push(f); return; }
                                lfQ.push(f);
                            } catch (e) {}
                        });
                        var lfGo291 = function() {
                            if (lfQ.length) {
                                var lfJ = 0;
                                var lfStep = function() {
                                    var lfE = Math.min(lfJ + 8, lfQ.length);
                                    for (; lfJ < lfE; lfJ++) {
                                        try { lfQ[lfJ].load().catch(function() {}); } catch (e) {}
                                    }
                                    if (lfJ < lfQ.length) { setTimeout(lfStep, 0); }
                                };
                                if (wpcRestoreGesture235()) { wpcOnGesture235(function() { setTimeout(lfStep, 0); }); } else { setTimeout(lfStep, 0); }
                            }
                        };
                        if (lfCand291.length) {
                            var lfEls291 = document.querySelectorAll("body *");
                            var lfN291 = Math.min(lfEls291.length, 600);
                            var lfI291 = 0;
                            lfUsed = {};
                            var lfCz291 = function() {
                                try {
                                    var lfEnd291 = Math.min(lfI291 + 120, lfN291);
                                    for (; lfI291 < lfEnd291; lfI291++) {
                                        var lfCs = getComputedStyle(lfEls291[lfI291]);
                                        var lfStk = (lfCs.fontFamily || "").toLowerCase();
                                        var lfFam = lfStk.split(",")[0].split(String.fromCharCode(34)).join("").split(String.fromCharCode(39)).join("").trim();
                                        if (lfFam) { lfUsed[lfFam + "|" + lfCs.fontWeight + "|" + lfCs.fontStyle] = lfStk.indexOf(lfFam + " fallback") !== -1 ? 2 : 1; }
                                    }
                                } catch (e) { lfI291 = lfN291; }
                                if (lfI291 < lfN291) { setTimeout(lfCz291, 0); return; }
                                for (var lfCi291 = 0; lfCi291 < lfCand291.length; lfCi291++) {
                                    if (lfMatchUse(lfCand291[lfCi291])) { lfQ.push(lfCand291[lfCi291]); }
                                }
                                lfGo291();
                            };
                            setTimeout(lfCz291, 0);
                        } else { lfGo291(); }
                    }
                } catch (e) {}
                
                
                
                
                
            }
            
            
            if (window.__wpcEngaged) {
                window.wpcIconFaces();
            }
            var wpcLfArm241 = function() {
                document.querySelectorAll('link[data-wpc-lf]').forEach((function(l) {
                    if (!l.getAttribute("href") && l.getAttribute("data-wpc-lf-href")) {
                        l.setAttribute("href", l.getAttribute("data-wpc-lf-href"));
                    }
                    l.media = "all";
                }));
            };
            if (wpcRestoreGesture235()) { wpcOnGesture235(wpcLfArm241); } else { wpcLfArm241(); }
            document.querySelectorAll('link[data-wpc-ucss]').forEach((function(l) {
                if (l.media === "print") {
                    l.media = l.getAttribute("data-wpc-ucss") || "all";
                }
            }));
            
            
            
            
            
            
            try {
                var wpcUc = document.querySelectorAll("link[data-wpc-ucss],link[data-wpc-ucss-rest]");
                for (var wpcI = 0; wpcI < wpcUc.length; wpcI++) {
                    if (wpcUc[wpcI].getAttribute("href") && document.head) {
                        document.head.appendChild(wpcUc[wpcI]);
                    }
                }
            } catch (e) {}
            
            
            
            
            
            
            
            
            
            
            
            
            
            try {
                var wpcInv675 = [].slice.call(document.querySelectorAll(".elementor-invisible"));
                if (wpcInv675.length && window.IntersectionObserver) {
                    var wpcRevIo675 = new IntersectionObserver((function(ents) {
                        for (var qi = 0; qi < ents.length; qi++) {
                            var en = ents[qi];
                            if (en.isIntersecting || en.boundingClientRect.bottom < 0) {
                                try { en.target.classList.remove("elementor-invisible"); } catch (e) {}
                                wpcRevIo675.unobserve(en.target);
                            }
                        }
                    }), { rootMargin: "0px 0px 25% 0px" });
                    for (var wpcQi675 = 0; wpcQi675 < wpcInv675.length; wpcQi675++) {
                        wpcRevIo675.observe(wpcInv675[wpcQi675]);
                    }
                }
            } catch (e) {}
        } catch (e) {}
        lateCssWaiters.splice(0).forEach((function(f) {
            try {
                f();
            } catch (e) {}
        }));
        try {
            window.dispatchEvent(new CustomEvent("wpc-latecss-applied"));
        } catch (e) {}
    }
    function whenLateCss(cb) {
        if (lateCssDone) {
            try {
                cb();
            } catch (e) {}
            return;
        }
        lateCssWaiters.push(cb);
    }
    var lateSwapStarted = false;
    function swapLateBarrier() {
        if (lateSwapStarted) {
            return;
        }
        if (wpcRestoreGesture235()) {
            if (!swapLateBarrier.__q235) {
                swapLateBarrier.__q235 = 1;
                wpcOnGesture235(function() { swapLateBarrier.__q235 = 0; swapLateBarrier(); });
            }
            return;
        }
        lateSwapStarted = true;
        var cap = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.cssBarrier !== "undefined" ? +window.wpcDelayV3Cfg.cssBarrier : 2e3;
        var links = [].slice.call(document.querySelectorAll('link[rel="wpc-late-stylesheet"]'));
        try {
            swapLate();
        } catch (e) {}
        if (cap <= 0 || !links.length) {
            lateCssFinish();
            return;
        }
        var pending = links.length;
        var one = function() {
            if (--pending <= 0) {
                lateCssFinish();
            }
        };
        links.forEach((function(el) {
            el.addEventListener("load", one, {
                once: true
            });
            el.addEventListener("error", one, {
                once: true
            });
        }));
        setTimeout(lateCssFinish, cap);
    }
    (function() {
        var gs = [ "mousemove", "pointermove", "pointerdown", "wheel", "click", "keydown", "touchstart", "scroll" ];
        var gf = function(e) {
            if (e && e.isTrusted === false) {
                return;
            }
            gs.forEach((function(v) {
                document.removeEventListener(v, gf, {
                    passive: true
                });
            }));
            swapLateBarrier();
        };
        var sy = typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0;
        if (sy > 0) {
            swapLateBarrier();
            return;
        }
        gs.forEach((function(v) {
            document.addEventListener(v, gf, {
                passive: true
            });
        }));
    })();
    window.addEventListener("wpc-scripts-loaded", (function() {
        swapLateBarrier();
        whenLateCss((function() {
            tick();
            try {
                frames(true);
            } catch (e) {}
            animBooted = true;
        }));
    }), {
        once: true
    });
    try {
        window.wpcSwapLateBarrier = swapLateBarrier;
    } catch (e) {}
    var lb = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssBackstop !== "undefined" ? window.wpcDelayV3Cfg.lateCssBackstop : 3e4;
    if (lb > 0) {
        setTimeout((function() {
            try {
                swapLateBarrier();
            } catch (e) {}
        }), lb);
    }
    var lt = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssTimer !== "undefined" ? +window.wpcDelayV3Cfg.lateCssTimer : 2500;
    var ltCap = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssTimerCap !== "undefined" ? +window.wpcDelayV3Cfg.lateCssTimerCap : 8e3;
    
    
    
    
    
    
    
    if (lt > 0) {
        var ltLcp103 = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssLcp !== "undefined" ? +window.wpcDelayV3Cfg.lateCssLcp : 2e3;
        var ltHard103 = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssCap !== "undefined" ? +window.wpcDelayV3Cfg.lateCssCap : 7e3;
        if (ltLcp103 > 0 && window.PerformanceObserver) {
            try {
                var poLt103 = new PerformanceObserver(function (l) {
                    if (l.getEntries().length) {
                        try { poLt103.disconnect(); } catch (e) {}
                        setTimeout(function () { try { swapLateBarrier(); } catch (e) {} }, ltLcp103);
                    }
                });
                poLt103.observe({ type: 'largest-contentful-paint', buffered: true });
            } catch (e) {}
        }
        if (ltHard103 > 0) {
            setTimeout(function () { try { swapLateBarrier(); } catch (e) {} },
                Math.max(1000, ltHard103 - performance.now()));
        }
    }
    if (lt > 0) {
        var ltT0 = Date.now();
        var ltPoll = function() {
            var nav = null;
            try {
                nav = performance.getEntriesByType("navigation")[0];
            } catch (e) {}
            if (nav && nav.loadEventEnd > 0) {
                setTimeout((function() {
                    try {
                        swapLateBarrier();
                    } catch (e) {}
                }), lt);
                return;
            }
            if (Date.now() - ltT0 >= ltCap) {
                try {
                    swapLateBarrier();
                } catch (e) {}
                return;
            }
            setTimeout(ltPoll, 100);
        };
        setTimeout(ltPoll, 100);
    }
})();

(function() {
    "use strict";
    var cfg = window.wpcDelayV3Cfg || {};
    if (cfg.engagementSignals === 0 || cfg.engagementSignals === false || cfg.humanSignals === 0 || cfg.humanSignals === false) {
        return;
    }
    var fired = false;
    function unshield() {
        try {
            [].slice.call(document.querySelectorAll("iframe[data-wpc-pe]")).forEach((function(f) {
                f.style.pointerEvents = f.getAttribute("data-wpc-pe") === "1" ? "" : f.getAttribute("data-wpc-pe");
                f.removeAttribute("data-wpc-pe");
            }));
        } catch (e) {}
    }
    var wpcPins = [];
    function wpcPinHeights() {
        try {
            var els = [].slice.call(document.querySelectorAll(".elementor > section.elementor-top-section, .elementor > main.elementor-top-section"));
            var hs = els.map(function(el) {
                return el.getBoundingClientRect().height;
            });
            els.forEach(function(el, i) {
                if (hs[i] > 40) {
                    wpcPins.push([ el, el.style.minHeight ]);
                    el.style.minHeight = hs[i] + "px";
                }
            });
            var unpin = function() {
                (window.requestAnimationFrame || setTimeout)(function() {
                    wpcPins.splice(0).forEach(function(p) {
                        p[0].style.minHeight = p[1];
                    });
                });
            };
            
            var un1 = function() {
                window.removeEventListener("scroll", un1);
                unpin();
            };
            setTimeout(function() {
                window.addEventListener("scroll", un1, { passive: true, once: true });
            }, 2e3);
            setTimeout(un1, 3e4);
        } catch (e) {}
    }
    function engaged(soft) {
        window.__wpcEngaged = 1;
        try {
            window.wpcWarmDelayed && window.wpcWarmDelayed();
        } catch (e) {}
        try {
            window.wpcVideoRestore && window.wpcVideoRestore();
        } catch (e) {}
        try {
            window.wpcRestAttach && window.wpcRestAttach(false);
        } catch (e) {}
        try {
            window.wpcIconFaces && window.wpcIconFaces();
        } catch (e) {}
        if (fired) {
            return;
        }
        if (soft) {
            unshield();
            return;
        }
        fired = true;
        wpcPinHeights();
        requestAnimationFrame((function() {
            requestAnimationFrame((function() {
                try {
                    document.dispatchEvent(new Event("scroll"));
                } catch (e) {}
            }));
        }));
        unshield();
    }
    function shield() {
        if (fired) {
            return;
        }
        try {
            [].slice.call(document.querySelectorAll("iframe.wpc-iframe-delay, iframe[data-wpc-src]")).forEach((function(f) {
                if (f.hasAttribute("data-wpc-pe")) {
                    return;
                }
                f.setAttribute("data-wpc-pe", f.style.pointerEvents || "1");
                f.style.pointerEvents = "none";
            }));
        } catch (e) {}
    }
    shield();
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", shield);
    }
    window.addEventListener("wpc-scripts-loaded", (function() {
        fired = true;
        unshield();
    }), {
        once: true
    });
    window.addEventListener("blur", (function() {
        try {
            if (document.activeElement && document.activeElement.tagName === "IFRAME") {
                engaged();
            }
        } catch (e) {}
    }));
    var m0 = null, o0 = null;
    function sensorsOff() {
        try {
            window.removeEventListener("devicemotion", onMotion);
        } catch (e) {}
        try {
            window.removeEventListener("deviceorientation", onOrient);
        } catch (e) {}
    }
    function wrapDelta(a, b) {
        var d = Math.abs(a - b);
        return Math.min(d, 360 - d);
    }
    var onMotion = function(e) {
        try {
            var a = e.accelerationIncludingGravity || e.acceleration;
            if (!a) {
                return;
            }
            var v = [ a.x || 0, a.y || 0, a.z || 0 ];
            if (m0 === null) {
                m0 = v.slice();
                return;
            }
            var d = Math.abs(v[0] - m0[0]) + Math.abs(v[1] - m0[1]) + Math.abs(v[2] - m0[2]);
            m0 = [ m0[0] + (v[0] - m0[0]) * .02, m0[1] + (v[1] - m0[1]) * .02, m0[2] + (v[2] - m0[2]) * .02 ];
            if (d > .5) {
                sensorsOff();
                engaged();
            }
        } catch (x) {}
    };
    var onOrient = function(e) {
        try {
            if (e.alpha === null && e.beta === null && e.gamma === null) {
                return;
            }
            var v = [ e.alpha || 0, e.beta || 0, e.gamma || 0 ];
            if (o0 === null) {
                o0 = v.slice();
                return;
            }
            var d = wrapDelta(v[0], o0[0]) + Math.abs(v[1] - o0[1]) + Math.abs(v[2] - o0[2]);
            o0 = [ o0[0] + (v[0] - o0[0]) * .02, o0[1] + (v[1] - o0[1]) * .02, o0[2] + (v[2] - o0[2]) * .02 ];
            if (d > 2.5) {
                sensorsOff();
                engaged();
            }
        } catch (x) {}
    };
    window.addEventListener("wpc-scripts-loaded", sensorsOff, {
        once: true
    });
    function wpcPolAllows(f) {
        try {
            var p = document.permissionsPolicy || document.featurePolicy;
            return !p || typeof p.allowsFeature !== "function" || p.allowsFeature(f);
        } catch (e) {
            return true;
        }
    }
    try {
        if (wpcPolAllows("accelerometer")) {
            window.addEventListener("devicemotion", onMotion, {
                passive: true
            });
        }
    } catch (e) {}
    try {
        if (wpcPolAllows("accelerometer") && wpcPolAllows("gyroscope")) {
            window.addEventListener("deviceorientation", onOrient, {
                passive: true
            });
        }
    } catch (e) {}
    try {
        window.addEventListener("orientationchange", (function() {
            engaged();
        }), {
            passive: true
        });
    } catch (e) {}
    try {
        var wasAway = false;
        window.addEventListener("blur", (function() {
            wasAway = true;
        }), {
            passive: true
        });
        window.addEventListener("focus", (function() {
            if (wasAway) {
                engaged();
            }
        }), {
            passive: true
        });
    } catch (e) {}
    function afterFirstPaint(fn) {
        var done = false;
        var go = function() {
            if (done) {
                return;
            }
            done = true;
            setTimeout(fn, 200);
        };
        try {
            if (window.PerformanceObserver) {
                var po = new PerformanceObserver((function(list) {
                    if (list.getEntries().length) {
                        try {
                            po.disconnect();
                        } catch (e) {}
                        go();
                    }
                }));
                po.observe({
                    type: "largest-contentful-paint",
                    buffered: true
                });
            }
        } catch (e) {}
        var raf = window.requestAnimationFrame ? window.requestAnimationFrame.bind(window) : function(f) {
            setTimeout(f, 60);
        };
        raf((function() {
            raf((function() {
                setTimeout(go, 900);
            }));
        }));
    }
    try {
        var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType("navigation")[0] || {} : {};
        var eng = false;
        try {
            eng = sessionStorage.getItem("wpcEngaged") === "1";
        } catch (e) {}
        var mark = false;
        try {
            if (/(^#|[#&])wpch\b/.test(location.hash || "")) {
                mark = true;
                if (window.history && history.replaceState) {
                    var clean = (location.hash || "").replace(/(^#|[#&])wpch\b/, "").replace(/^#$/, "");
                    history.replaceState(null, "", location.pathname + location.search + (clean && clean !== "#" ? clean : ""));
                }
            }
        } catch (e) {}
        if (mark || eng || document.referrer && document.referrer.length > 0 || nav.type === "back_forward") {
            afterFirstPaint(function() {
                engaged(true);
            });
        }
    } catch (e) {}
    try {
        
        
        
        
        
        
        
        
        var wpcPtr185 = false;
        try {
            y.call(document, "pointermove", function(ev) { if (ev.isTrusted) { wpcPtr185 = true; } }, { once: !0, passive: !0, capture: !0 });
            y.call(document, "mousemove", function(ev) { if (ev.isTrusted) { wpcPtr185 = true; } }, { once: !0, passive: !0, capture: !0 });
        } catch (e) {}
        var hoverTries = 0;
        var hoverCheck = function() {
            if (fired) {
                return;
            }
            try {
                if (wpcPtr185 && document.querySelector(":hover")) {
                    engaged(true);
                    return;
                }
            } catch (e) {
                return;
            }
            hoverTries++;
            if (hoverTries < 4) {
                setTimeout(hoverCheck, hoverTries * 1e3);
            }
        };
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", (function() {
                setTimeout(hoverCheck, 250);
            }));
        } else {
            setTimeout(hoverCheck, 250);
        }
    } catch (e) {}
})();

addEventListener("wpc-scripts-loaded", (function() {
    setTimeout((function() {
        requestAnimationFrame((function() {
            requestAnimationFrame((function() {
                try {
                    window.wpcSafeResize365(function() { window.dispatchEvent(new Event("resize")); });
                } catch (e) {}
            }));
        }));
    }), 80);
}), {
    once: true
});

(function() {
    "use strict";
    
    
    function check(im) {
        try {
            if (!im || !im.naturalWidth || !im.naturalHeight) {
                return;
            }
            var aw = parseInt(im.getAttribute("width"), 10), ah = parseInt(im.getAttribute("height"), 10);
            if (!aw || !ah) {
                return;
            }
            var da = aw / ah, na = im.naturalWidth / im.naturalHeight;
            if (Math.abs(na - da) / da < .05) {
                return;
            }
            var p = im.parentElement;
            if (!p || p.tagName !== "PICTURE") {
                return;
            }
            var s;
            while ((s = p.getElementsByTagName("source")[0])) {
                s.parentNode.removeChild(s);
            }
        } catch (e) {}
    }
    function sweep() {
        try {
            [].slice.call(document.querySelectorAll("picture img")).forEach((function(im) {
                
                
                if (im.naturalWidth > 0) {
                    check(im);
                } else if (!im.__wpcRg) {
                    im.__wpcRg = 1;
                    im.addEventListener("load", (function() {
                        check(im);
                    }), {
                        once: true
                    });
                }
            }));
        } catch (e) {}
    }
    
    
    
    var rgN = 0;
    var rgTick = function() {
        sweep();
        if (++rgN < 40) {
            setTimeout(rgTick, rgN < 12 ? 150 : 600);
        }
    };
    setTimeout(rgTick, 100);
    window.addEventListener("wpc-scripts-loaded", sweep, {
        once: true
    });
})();

(function() {
    "use strict";
    
    
    function attach(l) {
        try {
            if (!l || l.getAttribute("href")) {
                return;
            }
            
            
            var u = l.getAttribute("data-wpc-rest");
            if (!u) {
                return;
            }
            l.setAttribute("media", l.getAttribute("data-wpc-ucss-rest") || l.getAttribute("data-wpc-ucss") || "all");
            
            
            
            
            try { if (document.head) { document.head.appendChild(l); } } catch (e) {}
            l.setAttribute("href", u);
            
            
            
            try {
                var g627 = document.getElementById("wpc-shape-fill-guard");
                if (g627) {
                    var drop627 = function() {
                        try { if (g627.parentNode) { g627.parentNode.removeChild(g627); } } catch (e) {}
                    };
                    l.addEventListener("load", drop627, { once: true });
                    setTimeout(drop627, 4000);
                }
            } catch (e) {}
        } catch (e) {}
    }
    function rest(all) {
        try {
            [].slice.call(document.querySelectorAll("link[data-wpc-rest]:not([href])")).forEach((function(l) {
                if (all) {
                    attach(l);
                    return;
                }
                var tm = l.getAttribute("data-wpc-ucss-rest") || l.getAttribute("data-wpc-ucss") || "all";
                var m = true;
                try {
                    m = !window.matchMedia || window.matchMedia(tm).matches;
                } catch (e) {}
                if (m) {
                    attach(l);
                }
            }));
        } catch (e) {}
    }
    window.wpcRestAttach = rest;
    
    
    
    
    
    (function() {
        var done628 = false;
        var drop628 = function() {
            if (done628) { return; }
            done628 = true;
            try {
                var g = document.getElementById("wpc-shape-fill-guard");
                if (g && g.parentNode) { g.parentNode.removeChild(g); }
            } catch (e) {}
        };
        var t628 = Date.now();
        var poll628 = function() {
            if (done628) { return; }
            var n = null;
            try { n = performance.getEntriesByType("navigation")[0]; } catch (e) {}
            if ((n && n.loadEventEnd > 0) || Date.now() - t628 > 10000) {
                setTimeout(drop628, 1500);
                return;
            }
            setTimeout(poll628, 250);
        };
        setTimeout(poll628, 250);
    })();
    
    
    
    
    
    
    
    
    
    (function() {
        var fired626 = false;
        var go626 = function() {
            if (fired626) { return; }
            fired626 = true;
            try { rest(false); } catch (e) {}
        };
        
        
        
        var loaded626 = function() {
            try {
                var n = performance.getEntriesByType && performance.getEntriesByType("navigation")[0];
                if (n && n.loadEventEnd > 0) { return true; }
                if (performance.timing && performance.timing.loadEventEnd > 0) { return true; }
            } catch (e) {}
            return false;
        };
        var t626 = Date.now();
        var tick626 = function() {
            if (fired626) { return; }
            if (loaded626()) {
                setTimeout(go626, 1200);
                return;
            }
            if (Date.now() - t626 > 8000) { go626(); return; }
            setTimeout(tick626, 250);
        };
        setTimeout(tick626, 250);
    })();
    window.addEventListener("wpc-scripts-loaded", (function() {
        rest(false);
    }), {
        once: true
    });
    window.addEventListener("scroll", (function() {
        rest(false);
    }), {
        once: true,
        passive: true,
        capture: true
    });
    
    
    
    
    
    
    [ "pointermove", "pointerover", "pointerdown", "keydown", "touchstart" ].forEach((function(ev) {
        window.addEventListener(ev, (function(e) {
            if (e && e.isTrusted === false) {
                return;
            }
            rest(false);
        }), {
            once: true,
            passive: true,
            capture: true
        });
    }));
    try {
        window.addEventListener("orientationchange", (function() {
            rest(true);
        }), {
            passive: true
        });
        var mqR = window.matchMedia ? window.matchMedia("(min-width: 768px)") : null;
        if (mqR && mqR.addEventListener) {
            mqR.addEventListener("change", (function() {
                rest(true);
            }));
        }
    } catch (e) {}
})();

(function() {
    "use strict";
    var human = false, queued = false;
    function restore() {
        if (!human) {
            queued = true;
            return;
        }
        try {
            [].slice.call(document.querySelectorAll("video.wpc-video-delay[data-wpc-src]")).forEach((function(v) {
                v.src = v.getAttribute("data-wpc-src");
                v.removeAttribute("data-wpc-src");
                v.classList.remove("wpc-video-delay");
                try {
                    v.load();
                } catch (e) {}
                if (v.hasAttribute("autoplay")) {
                    var p = v.play();
                    if (p && p.catch) {
                        p.catch((function() {}));
                    }
                }
            }));
        } catch (e) {}
    }
    var evs = ["pointerdown", "touchstart", "keydown", "wheel", "touchmove", "mousemove"];
    function go(e) {
        if (human || (e && e.isTrusted === false)) {
            return;
        }
        human = true;
        evs.forEach((function(n) {
            try {
                removeEventListener(n, go, true);
            } catch (x) {}
        }));
        if (queued) {
            queued = false;
            restore();
        }
    }
    evs.forEach((function(n) {
        addEventListener(n, go, {
            capture: true,
            passive: true
        });
    }));
    window.wpcVideoRestore = restore;
    window.addEventListener("wpc-scripts-loaded", restore, {
        once: true
    });
})();





(function() {
    "use strict";
    var cfg = window.wpcDelayV3Cfg || {};
    if (!cfg.report || !window.PerformanceObserver) {
        return;
    }
    var lcpUrl = "", sent = false, scrolledAtLcp = 0, synthViewport = 0;
    try {
        if (sessionStorage.getItem("wpcLcpMx") === "1") {
            sent = true;
        }
    } catch (e) {}
    
    
    var norm = function(u) {
        if (!u) {
            return "";
        }
        var s = String(u).split("?")[0].split("#")[0];
        s = s.substring(s.lastIndexOf("/") + 1);
        return s.replace(/\.[a-z0-9]+$/i, "").replace(/-\d+x\d+$/, "").toLowerCase();
    };
    try {
        var po = new PerformanceObserver((function(list) {
            var es = list.getEntries();
            for (var i = 0; i < es.length; i++) {
                if (es[i].url) {
                    lcpUrl = es[i].url;
                } else if (es[i].element && es[i].element.currentSrc) {
                    lcpUrl = es[i].element.currentSrc;
                }
                
                
                
                
                try {
                    if ((typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0) > 0) {
                        scrolledAtLcp = 1;
                    }
                    
                    
                    
                    
                    if ((window.innerHeight || 0) > 2400) {
                        synthViewport = 1;
                    }
                } catch (e) {}
            }
        }));
        po.observe({
            type: "largest-contentful-paint",
            buffered: true
        });
    } catch (e) {}
    var check = function() {
        if (sent || !lcpUrl) {
            return;
        }
        var pre = document.querySelectorAll('link[rel="preload"][as="image"]');
        if (!pre.length) {
            return;
        }
        var want = norm(lcpUrl), got = "", hit = false;
        for (var i = 0; i < pre.length; i++) {
            
            var mq = pre[i].getAttribute("media");
            if (mq) {
                try {
                    if (window.matchMedia && !window.matchMedia(mq).matches) {
                        continue;
                    }
                } catch (e) {}
            }
            var href = pre[i].getAttribute("href") || "";
            var iss = pre[i].getAttribute("imagesrcset") || "";
            if (!got) {
                got = norm(href) || norm(iss.split(",")[0]);
            }
            if (want && (norm(href) === want || (iss && iss.toLowerCase().indexOf(want) !== -1))) {
                hit = true;
                break;
            }
        }
        if (hit) {
            
            
            
            try {
                if (localStorage.getItem("wpcLcpOk") !== "1" && Math.random() < 0.01) {
                    localStorage.setItem("wpcLcpOk", "1");
                    var okd = new FormData;
                    okd.append("action", "wpc_delay_v3_report");
                    okd.append("payload", JSON.stringify({
                        u: location.pathname.slice(0, 120),
                        lcpok: 1
                    }));
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(cfg.report, okd);
                    }
                }
            } catch (e) {}
            return;
        }
        
        
        if (scrolledAtLcp || synthViewport) {
            return;
        }
        sent = true;
        try {
            sessionStorage.setItem("wpcLcpMx", "1");
        } catch (e) {}
        try {
            var fd = new FormData;
            fd.append("action", "wpc_delay_v3_report");
            fd.append("payload", JSON.stringify({
                u: location.pathname.slice(0, 120),
                lcpmx: 1,
                got: got.slice(0, 80),
                want: want.slice(0, 80)
            }));
            if (navigator.sendBeacon) {
                navigator.sendBeacon(cfg.report, fd);
            }
        } catch (e) {}
    };
    window.addEventListener("pagehide", check);
    document.addEventListener("visibilitychange", (function() {
        if (document.visibilityState === "hidden") {
            check();
        }
    }));
})();
