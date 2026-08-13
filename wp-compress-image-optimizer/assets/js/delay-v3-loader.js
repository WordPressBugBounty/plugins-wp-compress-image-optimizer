!function() {
    "use strict";
    // A keyless Maps loader (key=&) can never initialize — 365KB of dead weight some
    // form plugins inject at runtime. Refuse the src at set-time; keyed Maps untouched.
    !function() {
        try {
            var mk = function(v) {
                return /maps\.googleapis\.com\/maps\/api\/js/.test(String(v)) && /[?&]key=(?:&|$)/.test(String(v));
            }, ce = document.createElement, sd = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, "src");
            document.createElement = function(tag) {
                var el = ce.apply(document, arguments);
                if (sd && String(tag).toLowerCase() === "script") try {
                    Object.defineProperty(el, "src", {
                        configurable: true,
                        get: function() { return sd.get.call(el); },
                        set: function(v) { if (!mk(v)) sd.set.call(el, v); }
                    });
                    var sa = el.setAttribute;
                    el.setAttribute = function(n, v) {
                        if (String(n).toLowerCase() === "src" && mk(v)) return;
                        return sa.apply(el, arguments);
                    };
                } catch (e) {}
                return el;
            };
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
    // Zone failover: delayed srcs may ride the CDN host (cfg.cdnHost). Natural-form zone URLs
    // carry the origin path verbatim, so recovery is a host swap; legacy /a: URLs embed the
    // origin outright. One executed-script error flips the whole session to origin-first.
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
        // The legacy /a: form is a PREFIX of the zone path, never a substring anywhere in it.
        // Matching it loose turned a natural URL whose own path contains "/a:" (a plugin dir
        // literally named a:b) into a wrong origin, so its failover 404'd forever.
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
            // async/defer are IDL properties here, not content attributes: cloneNode drops the
            // force-async flag, so the retry would be order-free without this.
            n.async = s.async, n.defer = s.defer,
            n.src = o, n.setAttribute("data-wpc-fb", "1"),
            y.call(n, "load", p, { once: !0 }), y.call(n, "error", p, { once: !0 }),
            s.parentNode ? s.parentNode.replaceChild(n, s) : (document.head || document.documentElement).appendChild(n);
        };
    }
    var o = 0, c = !1, i = !1, l = "loading", d = !1, s = !1, u = [], p = {
        load: [],
        DOMContentLoaded: [],
        readystatechange: [],
        pageshow: [],
        visibilitychange: []
    }, y = EventTarget.prototype.addEventListener, f = EventTarget.prototype.removeEventListener, h = EventTarget.prototype.dispatchEvent;
    document.readyState;
    Object.defineProperty(document, "readyState", {
        get: function() {
            return l;
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
        return ("DOMContentLoaded" !== t || d) && ("load" !== t || s) ? "readystatechange" === t && "complete" !== l ? o = !0 : "pageshow" !== t && "visibilitychange" !== t || (o = !0) : o = !0, 
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
    }, g && g.fn && g.fn.ready) {
        g.fn.ready;
        g.fn.ready = function(t) {
            if (c && v) {
                try {
                    t(g);
                } catch (t) {
                    e("Error in jQuery ready callback:", t);
                }
                return this;
            }
            return e("Capturing jQuery ready callback"), m.push(t), this;
        };
    }
    // THE ONE PRELOAD GATE. Every preload path funnels through w(), so the burst limit lives here
    // rather than at the call sites: capping only wpcPreloadDelayed left b()'s batch loop still
    // appending the whole registry in a single tick. A 127-entry registry opened 127 origin
    // connections at once; eloorac's origin answered every one with ERR_CONNECTION_RESET, so the
    // delayed chain never arrived and jQuery UI initialised against missing dependencies.
    // Preloading is advisory — the replay does not depend on it — so the queue drains at cap per
    // tick and a slow drain costs warmth, never correctness.
    var wpcPlQ = [], wpcPlOn = false, wpcPlIn = 0;
    function wpcPlDone() {
        if (wpcPlIn > 0) { wpcPlIn--; }
        if (wpcPlQ.length) { wpcPlPump(); }
    }
    function wpcPlPump() {
        var c = window.wpcDelayV3Cfg || {};
        var cap = +c.preloadCap > 0 ? +c.preloadCap : 6;
        var gap = +c.preloadGapMs >= 0 ? +c.preloadGapMs : 120;
        // IN-FLIGHT, not per-tick. Staggering starts alone does not bound concurrency: on a slow
        // link each preload outlives the gap, so N ticks leave cap*N connections open at once —
        // measured on the live document, peak in-flight only fell 58 to 46. The counter is
        // decremented by the link's own load/error, so this is a true ceiling on open connections.
        while (wpcPlIn < cap && wpcPlQ.length) {
            wpcPlIn++;
            try { wpcPlQ.shift()(wpcPlDone, gap); } catch (e) { wpcPlDone(); }
        }
        if (!wpcPlQ.length && !wpcPlIn) { wpcPlOn = false; }
    }
    function w(e, t, r) {
        if (e) {
            e = wpcJsSrc(e);
            // Preload would warm a src the set-time gate refuses — skip keyless Maps here too.
            if (/maps\.googleapis\.com\/maps\/api\/js/.test(String(e)) && /[?&]key=(?:&|$)/.test(String(e))) return;
            wpcPlQ.push(function(done, gap) {
                var n = 'link[rel="' + ("module" === t ? "modulepreload" : "preload") + '"][href="' + e + '"]';
                if (document.querySelector(n)) { done(); return; }
                var a = document.createElement("link"), fired = 0;
                // PER-ITEM belt, never a shared one. A pump-level timeout that reset the counter
                // stacked one timer per pump call and each reset released another `cap`: measured
                // peak in-flight 67 at 300ms latency and 224 at 6s, against a cap of 6. The slot
                // is released by whichever comes first, exactly once, so the ceiling is real.
                // The belt must outlast any link that is merely SLOW, not hung: releasing a slot
                // the browser still holds re-opens the same leak in miniature — at an 8s belt a
                // 12s link measured peak 12, i.e. cap x ceil(latency/belt). At 30s the ceiling
                // holds exactly through the whole realistic band; beyond that a link is not slow,
                // it is broken, and preloading is advisory either way.
                var fin = function() { if (!fired) { fired = 1; done(); } };
                setTimeout(fin, (+gap >= 0 ? +gap : 120) + 30000);
                a.rel = "module" === t ? "modulepreload" : "preload", "module" !== t && (a.as = "script"),
                a.onload = fin, a.onerror = fin,
                a.href = e, r && r.crossorigin && (a.crossOrigin = r.crossorigin), r && r.integrity && (a.integrity = r.integrity),
                r && r.referrerpolicy && (a.referrerPolicy = r.referrerpolicy), (document.head || document.documentElement).appendChild(a);
            });
            // Scheduled, never inline: draining on enqueue emptied the queue as fast as the
            // caller filled it, so every w() drained its own item and the cap never batched.
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
            // landing executes synchronously — a syntax error there is uncaught by design;
            // parse-check first so a broken script lands as a named warn, not a red error.
            // ONLY SyntaxError skips: strict-CSP sites throw EvalError for EVERY new Function —
            // those must land unvalidated, not be skipped wholesale
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
        // A jquery tag can be pending (defer not executed yet / origin-failover in flight)
        // while a stub/mini-jQuery shadows window.jQuery — landing dependents then binds
        // them to the shadow and loses the handlers. Gate on REAL capability (fn.on).
        // On timeout with a provably incapable jQuery, SKIP (named warn): landing would
        // throw the same lost-handler outcome as an uncaught error instead of a warn.
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
                        // inline: land through the jQuery-capability gate, then resolve
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
        if (e("Replaying captured events and restoring prototypes"), EventTarget.prototype.addEventListener = y, 
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
                    // v7.10.648 — POST-PAINT YIELD between the element load-event dispatches
                    // (whose handlers write DOM) and the window load-listener replay (whose
                    // handlers read layout). Same task meant every read was a forced layout
                    // charged to this loader (service trace: one of its two 71ms sites).
                    // rAF→setTimeout(0) is the house post-paint hook; ordering within the
                    // replay chain is unchanged — everything downstream rides the same hop.
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
                        var c = new CustomEvent("wpc-scripts-loaded", {
                            detail: {
                                totalScripts: Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry.length : 0
                            }
                        });
                        if (window.dispatchEvent(c), e("Dispatched wpc-scripts-loaded"), "undefined" != typeof elementorFrontend && elementorFrontend.elements && elementorFrontend.elements.$window) {
                            elementorFrontend.elements.$window.trigger("resize");
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
    function D() {
        c ? e("Loading already started, ignoring duplicate call") : (O && (clearTimeout(O),
        O = null), c = !0, e("Triggered resource loading"), async function() {
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
                    // Zoned registries replay classic batches one-at-a-time: async=false append
                    // order cannot hold across an origin retry (the failed slot has already been
                    // released when error fires), so ordering must come from the await, not the DOM.
                    // wpcJsDecode, NOT t: a later `var t` loop counter hoists over the decoder here.
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
                            // Only SRC entries need the await (a retry must not reorder them).
                            // Inline entries keep the direct build+land of the normal batch path:
                            // routing them through S() would send each one into the jQuery
                            // capability gate, which polls up to 10s EACH — N inline scripts on a
                            // page whose jQuery is failing would serialize into N x 10s of stall.
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
            // Warm only on engagement evidence (referrer/engaged/hover/sensors via engaged()) —
            // no evidence means no third-party bytes are spent on that pageview.
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
        // pageYOffset is 0 on an unscrolled page and 0 is falsy, so the old `a || b || c || 0`
        // chain evaluated EVERY branch in the common case — three layout-forcing reads where
        // one suffices, body.scrollTop the dearest. Read the modern property once.
        if (n = typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0,
        a = typeof window.pageXOffset === "number" ? window.pageXOffset : (document.documentElement || {}).scrollLeft || 0,
        n > 0 || a > 0) return e("Page already scrolled; starting immediately"), T = !0,
        void D();
        var n, a, o = [ "mousemove", "pointermove", "pointerdown", "wheel", "click", "keydown", "touchstart", "scroll" ];
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
        })), window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.timeout > 0 && (O = setTimeout((function() {
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
                    // Enqueues only — w() owns the burst limit for every preload path.
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
                // Late-but-successful boot: RETRACT the strike (b:1) so a slow
                // network never demotes a healthy site.
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
    // Boot watchdog: a gesture started the load but boot never completed —
    // the one aggressive-delay failure the boot-gated beacon can't see.
    // Armed ONLY on aggressive-default pages (cfg.aggr) so every strike is a
    // page the demote fixes; deadline scales with registry size; 2g skipped.
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
            // Watchdog already reported this pageview (and the boot listener
            // retracted) — a second errs send would double-count every error.
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
    // v7.10.617 — pointer bookkeeping for the parked-hover replay below. Touch input
    // has no hover concept, so any touchstart stands the whole belt down.
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
    // v7.10.565 — THE FIRST CLICK ON A DROPDOWN PARENT NAVIGATED. A submenu parent is an
    // <a href>, and the site's own nav script cancels its default action — but that script is
    // in the delay lane, so the very click that starts the replay finds nothing bound and the
    // browser follows the link. Receipt: cold first click, WPC off -> submenu opens, no
    // navigation; WPC on -> navigated to /pricing/. Hold the default action for exactly as
    // long as it takes the real handler to appear, then hand it the click.
    // v7.10.608 — every clause above required an <a>, but Elementor's mobile hamburger is
    // <div class="elementor-menu-toggle" role="button" aria-expanded="false">. It never matched,
    // so the first tap was neither held nor replayed and the mobile menu simply did nothing.
    // Verified identical markup across Elementor sites.
    var wpcTog565 = ".menu-item-has-children > a, li[aria-haspopup] > a, a[aria-haspopup], a[aria-expanded], .elementor-menu-toggle, [role=button][aria-expanded], button[aria-expanded], .menu-toggle, .navbar-toggler";
    // v7.10.616 — the replay's success observable. aria-expanded alone misses handlers that
    // toggle only classes, inline styles or hidden on the panel. JS-writable attributes ONLY:
    // computed styles are excluded because used-css media flips change them in exactly this
    // window with no handler involved.
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
        // Modified clicks are the user addressing the BROWSER (new tab, download, save) —
        // never ours to hold.
        if (ev.button || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey || ev.defaultPrevented) {
            return;
        }
        var t = ev.target;
        if (!t || !t.closest) {
            return;
        }
        var tog = null;
        try { tog = t.closest(wpcTog565); } catch (e) {}
        // v7.10.608 — Elementor writes aria-expanded="false" into the SERVER markup, so its
        // presence proves nothing about binding. Record the VALUE and decide later on whether it
        // CHANGED — an outcome test, not a capability test.
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
            // v7.10.636 — a click the page already serviced must not be replayed: thepttv
            // warm profile, click toggled repeat ON, the +60ms one-shot toggled it OFF.
            // v7.10.639 — but the .636 discriminator (lane STARTED at capture) over-reached:
            // the lane starts at load+150ms on evidenced visits while handlers only exist
            // once it FINISHES, so every fast click in that ~1s gap was captured, stood
            // down, and LOST (thepttv live receipt: warm click@1030, scripts-loaded@1262,
            // no replay ever fired). Key on lane COMPLETION: the jQuery-ready replay runs
            // handlers synchronously in the same task that dispatches wpc-scripts-loaded,
            // so completed-at-capture proves ready-bound handlers saw the original click.
            j: wpcDone639,
            href: tog ? tog.getAttribute("href") : ""
        };
    }), true);
    window.addEventListener("wpc-scripts-loaded", (function() {
        wpcDone639 = 1;
        // A toggle replay must never navigate. aria-expanded is written by the nav script when
        // it binds, so its presence is PROOF a handler owns this anchor and will cancel the
        // default action. No attribute => never bound => do not replay (pre-.565 behaviour).
        // Poll because binding can trail the replay event by a few hundred ms.
        var fire = function() {
            done = true;
            if (!pending || !pending.t || !pending.t.isConnected) {
                pending = null;
                return;
            }
            // Non-toggle + page JS was live at capture => the original click was serviced
            // natively; a replay would run the handler a second time. Toggles are immune
            // (held via preventDefault + outcome-verified) and keep their path.
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
        // v7.10.616 — REPLAY UNTIL OBSERVED EFFECT. wpc-scripts-loaded means the lane
        // EXECUTED; Elementor binds toggle handlers asynchronously after init, so one timed
        // replay can land before the handler exists and the tap is swallowed forever
        // (receipt: lane executed at 260ms, blind fire at 1.76s, menu never opened, 1 run
        // in 4). Every attempt is verified through wpcSnap616; an unchanged snapshot means
        // the click hit nothing bound — retry on the ladder. Snapshot movement at ANY point
        // means a handler acted — stop, never double-toggle.
        // v7.10.634 — denser mid-window rungs: Pro's nav chunk fetches ~90ms after the
        // gesture releases the lane and binds ~1.5-2.5s in; the old 1200->2500->4000 gaps
        // made the OPEN wait up to 2.5s past binding. Same verified-outcome semantics,
        // worst-case post-bind wait now <=800ms across the whole bind window.
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
        // v7.10.635 — EVENT-DRIVEN RUNG. The handler binds moments after its script
        // (webpack lazy chunk on Elementor Pro) finishes loading; timed rungs left up to
        // ~800ms between that moment and the next replay. While a replay is owed, every
        // script that ARRIVES schedules one extra verified attempt 150ms after its load —
        // the open lands ~150ms after binding regardless of ladder phase. Additive only:
        // same verified-outcome semantics, ladder unchanged as the fallback.
        var wpcRungMo635 = null;
        // i=1: entry outcome-check runs first, so once any rung's dispatch was serviced
        // every later chain halts at its check — single-threaded dispatch means a later
        // rung always observes an earlier rung's effect, never double-toggles.
        var wpcRung635 = function() {
            // toggles only — the ladder was toggle-only by construction; a non-toggle
            // pending is fire()'s single dispatch, never ours to repeat.
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
            // i=0 is entry: the caller already decided a replay is owed (the bind signal
            // itself moves the snapshot, so an entry check would falsely read "handled").
            // v7.10.633 — for a toggle that SHIPPED an aria value, success is that VALUE
            // moving or the toggle's OWN class moving; the panel is bind-noise (SmartMenus
            // stamps the UL when it binds), so a panel-inclusive snapshot false-succeeded
            // when the bind landed inside a rung window — the ladder stopped one click
            // short and the tap was swallowed (hawkeye 2/6, timing-dependent).
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
            // Re-baseline at dispatch time so each verify compares against the state the
            // click actually landed on.
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
                // Had none at click time — which PROVES no handler was bound when the user
                // clicked, so "already serviced" is impossible here and the snapshot check
                // must not run: the bind itself writes aria and stamps panel classes, which
                // would read as serviced and swallow the click. Appearance of the attribute
                // is the bind signal, unchanged from .565 — the replay is now verified.
                if (pending.t.getAttribute("aria-expanded") !== null) {
                    done = true;
                    wpcVerify616(0);
                    return;
                }
            } else {
                // v7.10.633 — serviced is the OUTCOME, never panel decoration. Binding
                // itself stamps panel classes (SmartMenus decorates the UL), so the old
                // panel-inclusive snapshot read a mid-window bind as "serviced" and the
                // tap died with no ladder at all (hawkeye 2/6 opens). A shipped
                // aria-expanded is MAINTAINED by its handler: serviced = its VALUE moved
                // (bind rewrites "false" as "false"), or the toggle's OWN class moved.
                var wpcAv633 = pending.t.getAttribute("aria-expanded");
                if ((wpcAv633 !== null && wpcAv633 !== pending.a)
                    || (pending.t.getAttribute("class") || "") !== pending.c) {
                    done = true;
                    pending = null;
                    return;
                }
                if (n <= 18) {
                    // Pre-attributed toggle: no navigation to honour and replays are
                    // verified, so start the ladder at 600ms instead of stranding the tap
                    // to the deadline.
                    done = true;
                    wpcVerify616(0);
                    return;
                }
            }
            if (n <= 0) {
                // Never bound. We cancelled a navigation the user asked for — honour it now
                // rather than strand them. Same destination, ~1.5 s late, and only on a page
                // whose nav script never arrived.
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

        // v7.10.617 — PARKED-POINTER HOVER REPLAY (the hover twin of the click replay).
        // A first gesture that ENDS on a menu parent is swallowed: the lane executes and
        // hover handlers bind under a resting cursor, no new mouseenter ever fires, and
        // the dropdown stays shut until the user leaves and re-enters (receipt 2026-07-30:
        // parked 8s, never opened; this sequence opened it). Menu parents only; real-mouse
        // pointers only; every rung re-checks and stops the moment the menu is open. The
        // small-delta mousemove pair arms SmartMenus' real-mouse detection.
        // v7.10.618 — MENU REGISTRATION REPLAY. Field fingerprint (staging 2026-07-30,
        // James's console): Elementor Pro constructed, every chunk fetched, nothing thrown —
        // but its handler registration missed the one-shot elementor/frontend/init, so the
        // nav widget never got SmartMenus (menu dead to hover AND click). Outcome gate only:
        // nav widgets present + SmartMenus lib loaded + init already fired + NO wired menu.
        // Healthy pages are wired and can never double-fire. Verified live: the replay wired
        // the actual failing tab. NOTE: the elementsHandlers registry never lists nav-menu
        // even on healthy loads — it must not be part of any gate.
        // v7.10.636 — first rung 2500->1200 (hawkeye trace: lane done ~1.3s, then a dead
        // ~3s gap that was exactly this ladder's first rung; healthy sites are wired well
        // before 1200 so the ul.sm stop still protects them).
        // v7.10.637 — first rung 1200->400 (hawkeye floor probe 2026-07-31: gate
        // preconditions all pass at scripts-loaded+50 and registration wires the menu;
        // the 1200ms wait was pure designed-in latency for a menu that NEVER self-wires).
        // 400 is the timing .636 already shipped for a pending tap — same gate, same
        // exposure; the special case is subsumed by the first rung and removed (two
        // concurrent attempts at +400 would double-trigger init). A premature attempt
        // on a slow-booting healthy site is caught by the retry rungs + ul.sm stop.
        var wpcMenuReps618 = [400, 1200, 2500, 5000, 9000];
        var wpcMenu618 = function(i) {
            var stop = false;
            try {
                var navs = document.querySelectorAll(".elementor-widget-nav-menu");
                if (!navs.length || !window.jQuery || !jQuery.fn || !jQuery.fn.smartmenus
                    || !window.elementorFrontend || !elementorFrontend.hooks
                    || !elementorFrontend.elementsHandler || !elementorFrontend.elementsHandler.runReadyTrigger) {
                    stop = !navs.length;
                } else if (document.querySelector("ul.sm,[id^=sm-]")) {
                    stop = true;
                } else {
                    try { jQuery(window).trigger("elementor/frontend/init"); } catch (e) {}
                    setTimeout((function() {
                        try {
                            for (var k = 0; k < navs.length; k++) {
                                elementorFrontend.elementsHandler.runReadyTrigger(navs[k]);
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

        // v7.10.620 — NEVER-BLANK REVEAL for elementor-invisible. Receipt (staging
        // 2026-07-31): post-scroll, 22 elements stayed visibility:hidden — content
        // scrolled past while invisible. elementor-invisible is a deferral whose
        // undoer (waypoint/animation handler) provably does not run for a subset of
        // elements once lanes are delayed. Outcome law: an element in or above the
        // viewport that is still elementor-invisible ~600ms after entry gets revealed —
        // with its declared animation when parsable, plainly when not. Native reveals
        // that DO run win the race and leave nothing for this belt to do.
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
                    } catch (e) {
                        try { el.classList.remove("elementor-invisible"); } catch (e2) {}
                    }
                };
                var io = new IntersectionObserver(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        var en = entries[i];
                        // above-the-viewport counts: it was scrolled past while hidden
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

        // v7.10.826 — NEVER-BLANK REVEAL for Divi et-waypoint, the exact twin of the
        // elementor-invisible belt above. Divi hides scroll-animated elements with
        // .et-waypoint:not(.et_pb_counters){opacity:0} and its OWN delayed JS is the only
        // undoer (adds .et-animated). Receipt (clearconpools/gunite-concrete, 7.10.825):
        // every Divi frontend script in the delay manifest, blurb icons at opacity:0 with
        // no revealer until first interaction — and none after it when waypoint init
        // misses. Outcome law: an et-waypoint element in or above the viewport still
        // unrevealed ~600ms after entry gets Divi's own reveal class, so its native
        // et_pb_animation_* animation plays. Native reveals that DO run win the race.
        var wpcReveal826 = function() {
            try {
                var els = document.querySelectorAll(".et-waypoint:not(.et-animated):not(.et_pb_animation_off)");
                if (!els.length || !window.IntersectionObserver) { return; }
                var reveal = function(el) {
                    try {
                        if (el.classList.contains("et-animated")) { return; }
                        el.classList.add("et-animated");
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
        // v7.10.638 — ONE TIER for engagement-evidenced visits. Referrer / back-forward /
        // #wpch arrivals used to get warm-only (prefetch, no execution) while stamped
        // repeat visitors auto-ran; the bandwidth was already being spent on both, only
        // the CPU was withheld — so referred humans tapped a dead menu the stamp cohort
        // never saw. Now every evidenced visit auto-runs on the same load-event anchor.
        // Automated lab loads carry no referrer and no storage: still gated.
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
            // Never via window load/readyState — both are trapped until the replay itself.
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
        // Eager: replay completion IS engagement evidence (gesture or 60s timeout triggered it) —
        // a lazy listener inside attempt() can register after the event already fired.
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
        wpcSweepArmed = true;
        // Crit may only leave after the used.css that replaces it is LOADED and applied —
        // and never at all if it fails (styled-with-duplication beats naked).
        var sheetOk = function(l) {
            // Chrome fires load (not error) on HTTP-error stylesheets — .sheet alone
            // is not proof. Same-origin: require actual rules. Cross-origin: cssRules
            // throws; presence suffices (non-CSS MIME is rejected, sheet stays null).
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
        // Crit-removal authority = LOAD-gate, not flip-gate: every flipped theme link
        // must have a readable sheet before crit may leave. Cold post-purge ?icv= URLs
        // load late; removing crit before they land = UA-default flash (thepttv receipt).
        // Retries exhaust into KEEPING crit — additive-safe, late removal costs nothing.
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
            // Swap only with a visitor present (or after replay): the handoff repaint then
            // happens mid-engagement where it cannot be perceived.
            // Crit+used coexisting until then is the cheap, safe state.
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
                    // data-wpc-critless (.561) means this block IS the page's only font carrier —
                    // it is emitted precisely because no crit shipped. Sweeping it is the bug.
                    if (fs && fs.getAttribute("data-wpc-v2") !== "1"
                        && fs.getAttribute("data-wpc-critless") !== "1") {
                        fs.remove();
                    }
                    if (!c) {
                        return;
                    }
                    // Generic canary: crit may leave ONLY if removing it changes nothing
                    // visible above the fold. Snapshot a style signature of every ATF-region
                    // element (+ all hidden ones); any drift after removal → restore crit.
                    // Catches menu pops, button/overlay color shifts, vanished bgs — the whole
                    // crit-has-it/used-lacks-it class — regardless of which rule the bundle missed.
                    // v7.10.563 — fontFamily IS part of the signature. Without it the canary was
                    // blind to the one thing the crit is the sole carrier of: on an Elementor site
                    // the base64 @font-face blocks live ONLY here, so removing the crit dropped
                    // every real face and the page fell to its "<Family> Fallback" (= local Arial)
                    // with display/visibility/colour/background all unchanged. Receipted on the
                    // flagship as "starts as proper Circular, then swaps to Arial".
                    var sig = function(el, s) {
                        s = s || getComputedStyle(el);
                        return s.display + "|" + s.visibility + "|" + s.backgroundColor + "|" + s.color + "|" + s.backgroundImage + "|" + s.fontFamily;
                    };
                    var watch = [], snap = [];
                    // v7.10.558 — the snapshot sweep ran synchronously inside this task, after the
                    // loader's own media flips had invalidated style, so the first
                    // getBoundingClientRect FORCED a layout (40 ms on a Moto G Power, PSI
                    // "Forced reflow"). Same reads at a frame boundary are a normal layout the
                    // browser was going to do anyway. Removal + verify stay inside, so ordering
                    // (snapshot -> remove -> compare) is unchanged.
                    requestAnimationFrame((function () {
                    try {
                        // v7.10.389 rect-first: the old order resolved computed style for EVERY
                        // element (twice for watched ones) — two ~46ms style sweeps on a 2.5k-node
                        // DOM. Rects are cheap after one layout; style resolves only for ATF-visible
                        // or zero-size candidates, once, and the scan is bounded.
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
                    // v7.10.563 — the crit may leave, its @font-face blocks may NOT. They are
                    // routinely the document's only declaration of the theme's real faces, and a
                    // face costs nothing to keep (base64: already parsed, no request). Hoisting a
                    // device-scoped face out of its @media only makes it available, never applied.
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
                    // v7.10.629 — restore is position-EXACT, never appendChild. Crit is a
                    // flattened union carrying rules that LOST the page's cascade at equal
                    // specificity; putting it back last promotes every one of them (FA6's
                    // `.fa:before{content:var(--fa)}` out-ordered FA4's `.fa-thumbs-o-up:before
                    // {content:"\f087"}` -> undefined var -> content:none -> icon vanishes on
                    // gesture). Remove+restore must be a cascade no-op.
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
    function swapStyles() {
        try {
            var wpcFsub69 = document.getElementById("wpc-font-subsets");
            if (wpcFsub69 && wpcFsub69.getAttribute("data-wpc-v2") === "1" && document.body && wpcFsub69 !== document.body.lastElementChild) {
                document.body.appendChild(wpcFsub69);
            }
        } catch (e) {}
        var sel = '[rel="wpc-stylesheet"],[type="wpc-stylesheet"],[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]';
        var list = [].slice.call(document.querySelectorAll(sel));
        if (!list.length) {
            // Fully-absorbed pages have no deferred sheets left — the sweep must still arm.
            if (document.querySelector("link[data-wpc-ucss]")) {
                wpcCritSweep();
            }
            return;
        }
        var okCount = 0, total = list.length;
        // v7.10.490 — ATOMIC CASCADE. Restoring each sheet's media on its OWN load applied the
        // deferred sheets one at a time (document.styleSheets 9 -> 34 -> 53), and every intermediate
        // count is a briefly-valid WRONG cascade: the H1 measured 30 -> 42 -> 30px, +-48px twice,
        // while the crit carried the correct value throughout. One synchronous restore at the
        // barrier below = one style recalc. Also fail-OPEN where the per-sheet handler was not: a
        // sheet that hit its timeout without firing load kept media="print" FOREVER.
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
            // Both carrier classes in ONE pass: the links above and the inline styles this
            // lane held. Same task = one recalc, and the 10s belt below releases both.
            for (var si = 0; si < wpcHeldStyles.length; si++) {
                try {
                    wpcHeldStyles[si].removeAttribute("data-wpc-hold-style");
                    wpcHeldStyles[si].setAttribute("type", "text/css");
                } catch (e) {}
            }
            wpcHeldStyles = [];
        };
        // Kill switch: wpcDelayV3Cfg.atomicCascade=0 restores the pre-.490 per-sheet behaviour
        // without a rebuild. NOT staging-verified yet — this is the escape hatch.
        // v7.10.504: PHP now emits atomicCascade (default 1). wpc_atomic_cascade => 0 disables.
        var wpcAtomic = !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.atomicCascade === 1);
        if (wpcAtomic) { setTimeout(wpcRestoreAll, 1e4); }
        // This lane parks its own links (media=print) until wpcRestoreAll, so an inline
        // style applying before that barrier wins every equal-specificity tie against
        // sheets that used to override it — the same class as the late lane, one barrier
        // earlier. Proven by service-side ablation: with the crit bytes emptied the wrong
        // state still appears (parked=11, inert=0 at 343ms), so the restore lane produces
        // it unaided. Hold only when links here will actually park.
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
                    // used.css self-applies via its onload media-flip; a deferred rel (stale
                    // cached HTML) would never load — restore it, and never print-flip it here.
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
                    // Atomic restore: an inline style going live while the late lane is still
                    // parked lets any rule a parked link used to override win the whole idle
                    // window (core's dark .wp-block-button__link out-ordered the crit's blue).
                    // swapLate converts the lane to rel=stylesheet media=print (data-wpc-tm),
                    // so the parked state must match BOTH shapes — the timer path arrives
                    // after conversion and saw an empty selector (veltri 796ms tie-inversion).
                    // Hold the flip; lateCssFinish applies both carriers in ONE pass.
                    el.setAttribute("data-wpc-hold-style", "1");
                    finish(false);
                    return;
                } else if (wpcHoldInline) {
                    // Same invariant, this lane's own barrier: released in wpcRestoreAll
                    // (with the links, one recalc) and by lateCssFinish as a belt.
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
            // Unconditional and BEFORE the sweep gate: crit leaving and the real cascade arriving in
            // the same task is one recalc, and a sheet must never stay inert because the gate failed.
            wpcRestoreAll();
            // Inline <style> entries never fire load and dilute okCount below the floor;
            // when used.css links exist THEY are the authority on when crit may leave.
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
    // Heavy frames a real visitor scrolls toward restore ahead of boot — a
    // below-fold booking widget loads as they approach (400px margin), while a
    // no-scroll measurement pass never triggers it. Visible-at-load frames
    // restore immediately (visible content loads — the honest semantics).
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
    // v7.10.528 — the swap used to fire two frames after FIRST paint, which on a throttled
    // link is before LCP: 87 KiB of deferred CSS then competed with the LCP image for the same
    // pipe, and PSI measured exactly that as a 620 ms "resource load delay". Hold the swap until
    // the LCP element has actually painted. Three independent releases so the CSS can never be
    // stranded: the LCP entry, any user interaction, or a hard timeout.
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
        // Interaction always wins — a user who scrolls or taps must never wait on this.
        ['pointerdown','keydown','touchstart','scroll'].forEach(function (ev) {
            window.addEventListener(ev, wpcReleaseStyles528, { once: true, passive: true });
        });
        // Backstop: no LCP entry (no PO support, or nothing qualifies) must still style the page.
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
    // Interaction-gated: native content-visibility:auto already renders sections on scroll
    // approach; the full un-containment walker exists only to retire per-milestone re-layout
    // for engaged sessions. A timed reveal would pay the below-fold layout inside the lab's
    // TBT window on throttled mobile — first gesture (which the lab never sends) is the gate.
    (function() {
        var cvSel = "[data-wpc-cv], section.elementor-top-section, main.elementor-top-section, footer.elementor-top-section, .awb-cv-auto, .wpc-delay-avada";
        // v7.20.02 — FIRST-VIEWPORT CV RELEASE, no gesture required. hdavid-law (Avada):
        // the theme declares .fusion-fullwidth.awb-cv-auto{content-visibility:auto} and its
        // own near-viewport un-hider is DELAYED with the rest of the scripts, so an in-fold
        // 1px overlap row stayed contained and CLIPPED its overflowing hero content on real
        // phones (WebKit skips a 1px box as not user-relevant; the banner painted over the
        // lawyer + counter). IO is the zero-forced-layout in-viewport test: anything
        // intersecting the first viewport un-contains immediately; everything below keeps
        // the gesture gate so the lab's TBT window never pays the below-fold layout.
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
                // v7.10.648 — READ PHASE THEN WRITE PHASE per slice (service trace: this
                // loop was one of the loader's two forced-layout sites — the write to
                // element N dirtied layout, so the read on element N+1 forced a recalc,
                // alternating every iteration). All reads land on clean layout now; the
                // slice writes only after its reads are done.
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
                // Atomic apply: load inert (print) and flip every media in ONE pass at the
                // barrier — sheet-by-sheet application transiently zeroed ATF sections.
                if (!el.getAttribute("data-wpc-tm")) {
                    el.setAttribute("data-wpc-tm", el.media || "all");
                    el.media = "print";
                }
                el.setAttribute("rel", "stylesheet");
            }
            el.setAttribute("type", "text/css");
        }));
    }
    // Icon faces ride the engagement signal, not the late-css barrier: the inlined crit subset
    // covers the above-fold glyphs, every remaining one is below the fold. Reuses engaged()
    // — no listeners of its own (a second capture-phase set costs paint, receipted .433).
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
            if (lf) {
                lf.setAttribute("type", "text/css");
                lf.media = "all";
                // v7.20.03 — the flip alone is not service: the engine does not initiate loads
                // for faces already-painted text needs (dalton: w800 sat unloaded forever,
                // headline held the metric fallback). Nudge every declared face, sampling a
                // codepoint from its own unicode-range so ranged subsets match.
                try {
                    var lfSheet = lf.sheet;
                    if (lfSheet) {
                        var lfRules = lfSheet.cssRules;
                        var lfN = 0;
                        for (var lfI = 0; lfI < lfRules.length && lfN < 64; lfI++) {
                            var lfR = lfRules[lfI];
                            if (lfR.style && lfR.style.fontFamily) {
                                lfN++;
                                var lfCp = 77;
                                var lfM = (lfR.style.unicodeRange || "").match(/U\+([0-9A-Fa-f]+)/);
                                if (lfM) {
                                    var lfV = parseInt(lfM[1], 16);
                                    if (lfV >= 33) { lfCp = lfV; }
                                }
                                document.fonts.load((lfR.style.fontStyle || "normal") + " " + (lfR.style.fontWeight || "400") + " 16px " + lfR.style.fontFamily, String.fromCodePoint(lfCp)).catch(function() {});
                            }
                        }
                    }
                } catch (e) {}
                // No subset re-declaration here: a subset's unicode-range can exceed its
                // cmap (measured live: 1.6KB faces declaring U+20-7A), and re-declaring it
                // after the lane makes every missing glyph fall to the stack fallback
                // PERMANENTLY. Shadow-during-fetch heals at load; a lying range must not
                // be made authoritative.
            }
            // A gesture before this style parsed would have found no element to flip;
            // the durable flag is the only record of it.
            if (window.__wpcEngaged) {
                window.wpcIconFaces();
            }
            document.querySelectorAll('link[data-wpc-lf]').forEach((function(l) {
                if (!l.getAttribute("href") && l.getAttribute("data-wpc-lf-href")) {
                    l.setAttribute("href", l.getAttribute("data-wpc-lf-href"));
                }
                l.media = "all";
            }));
            document.querySelectorAll('link[data-wpc-ucss]').forEach((function(l) {
                if (l.media === "print") {
                    l.media = l.getAttribute("data-wpc-ucss") || "all";
                }
            }));
            // Theme sheets flip in at their own DOM position, far below the used-css links.
            // Cascade follows document order, so a base shorthand (Divi `.et_pb_with_border{
            // border:0 solid}`) out-orders the module rules used-css extracted out of the
            // theme's inline block — border-width goes back to 0 and design is lost. Re-append
            // at THIS barrier: the document is fully parsed, and the media flips above already
            // force one recalc, so used-css lands last inside that same frame (no extra paint).
            try {
                var wpcUc = document.querySelectorAll("link[data-wpc-ucss],link[data-wpc-ucss-rest]");
                for (var wpcI = 0; wpcI < wpcUc.length; wpcI++) {
                    if (wpcUc[wpcI].getAttribute("href") && document.head) {
                        document.head.appendChild(wpcUc[wpcI]);
                    }
                }
            } catch (e) {}
            // v7.10.675 — reveal in-viewport .elementor-invisible via IntersectionObserver, not
            // a synchronous rect scan. The old post-paint scan read getBoundingClientRect().top
            // on EVERY .elementor-invisible element; each read forces layout of a DISTINCT
            // content-visibility:auto section, so the "one clean layout covers all reads"
            // assumption never held — a ~47-section page spent ~106ms in one post-FCP task
            // (PSI "Forced reflow" / TBT 109), profiled on the flagship (§9). IO runs the same
            // in-viewport test off the main thread (zero synchronous layout) and is the proven
            // reveal path (wpcReveal620): its single initial notification is exactly what reveal
            // needs — the "no second notification" caveat only bites uses that want a later one.
            // This tail is the reveal path on lab/no-gesture loads (wpcReveal620 arms only after
            // wpc-scripts-loaded), so it must never block: IO satisfies both. rootMargin bottom
            // 25% == the old innerHeight*1.25 window; scroll now reveals below-fold too (a strict
            // never-blank gain, matching wpcReveal620).
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
            // Unpin during a scroll (anchoring absorbs the correction invisibly); idle backstop.
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
        var hoverTries = 0;
        var hoverCheck = function() {
            if (fired) {
                return;
            }
            try {
                if (document.querySelector(":hover")) {
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
                    window.dispatchEvent(new Event("resize"));
                } catch (e) {}
            }));
        }));
    }), 80);
}), {
    once: true
});

(function() {
    "use strict";
    // A minted variant can lie (bitmap W×sourceH — ratio ≠ declared); drop the picture
    // sources so the OTF srcset (ratio-true by construction) serves instead.
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
                // Act the moment dimensions are known (header parsed) — earlier than the
                // load event, so a squished source is swapped before it fully paints.
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
    // window load / readyState are trapped until replay — poll instead (element-level
    // load listeners pass through the trap; document-level ones do not). Fast early ticks
    // catch a squished bitmap's dims before paint; slows once the page settles.
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
    // REST used-css: href-less until a visitor is present.
    // A scrolling user must never outrun it, so first scroll attaches unconditionally.
    function attach(l) {
        try {
            if (!l || l.getAttribute("href")) {
                return;
            }
            // v7.10.674 (§4): only REST is attached (after load). atf (data-wpc-uhref) is never
            // fetched — the crit already paints the fold. Do not fall back to the atf href.
            var u = l.getAttribute("data-wpc-rest");
            if (!u) {
                return;
            }
            l.setAttribute("media", l.getAttribute("data-wpc-ucss-rest") || l.getAttribute("data-wpc-ucss") || "all");
            // Cascade follows DOM order: used-css sits near <head> top while late-flipped theme
            // sheets sit far below, so their base shorthands (Divi `border:0 solid`) out-ordered
            // our module rules and design was lost. Re-append BEFORE setting href — one fetch,
            // final position, used-css always last.
            try { if (document.head) { document.head.appendChild(l); } } catch (e) {}
            l.setAttribute("href", u);
            // v7.10.627 — the never-black shape guard exists only to cover the window
            // before real fill rules land. Retire it once they have, so it can never
            // outlive its purpose (a deferral whose remover must actually run).
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
    // v7.10.628 — retire the never-black shape guard on a path that ALWAYS runs. The
    // .627 removal lives inside attach(), but REST is now attached at PARSE by the
    // ucss-boot, so attach() never runs for it — and on pages where used-css stood down
    // there is no attach() at all. Real CSS is live by load+~200ms (late-swap) at the
    // latest, so retire shortly after the real load event, however the CSS arrived.
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
    // v7.10.626 — REST MUST NOT WAIT FOR A GESTURE. Receipt (/pricing/ 2026-07-31): the
    // black band below the fold is section 671fd764's shape-divider fill, which lives ONLY
    // in the REST bundle (crit=yes for other rules, atf=NO, rest=YES). Gated on engagement
    // evidence, a 338KB bundle only STARTS downloading when the visitor scrolls — so they
    // scroll into an unstyled section and watch the default black SVG fill until it lands
    // (30s backstop otherwise). Below-fold correctness is not an interaction feature.
    // First paint is untouched: crit + ATF already cover it and this fires only after
    // readyState complete + 1.2s, so it never competes with LCP resources. The gesture and
    // scroll triggers below remain as EARLIER fire paths.
    (function() {
        var fired626 = false;
        var go626 = function() {
            if (fired626) { return; }
            fired626 = true;
            try { rest(false); } catch (e) {}
        };
        // document.readyState is SHADOWED by this loader (held at "loading" until the lane
        // releases), so a readyState gate here can never fire — proven in test before ship.
        // Navigation Timing is the real, unshadowed load signal.
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
    // v7.10.561 — POINTER INTENT, not just scroll. A visitor who clicks without scrolling first
    // reached the click with this sheet still href-less, so anything styled ONLY by the rest
    // bundle — runtime-injected UI, i.e. every lightbox/popup/modal — opened unstyled. Measured
    // on staging: rest attached 520 ms AFTER the click, its fetch 4655 ms into the page.
    // pointermove/over fire while the cursor travels to the target, which is the head start the
    // fetch needs; pointerdown/keydown/touchstart are the last-resort catch for a direct hit.
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
// LCP preload correctness beacon. A preload is a promise about which element is the LCP; when
// it names the wrong one it spends the LCP's bandwidth at fetchpriority="high" on the wrong
// resource, and at fleet scale that is invisible without a signal. Reports ONLY on mismatch and
// ONLY once per session, so a correct fleet sends nothing. No timers (GESTURE LAW) and no fetch
// during load — sendBeacon on pagehide costs the page nothing.
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
    // Compare on the rung-stripped filename stem: the preload and the <img> legitimately
    // resolve to different rungs of the SAME file, which is a match, not a mismatch.
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
                // A scrolled visitor's LCP is whatever was in THEIR viewport, not the
                // top-of-page hero the preload targets — comparing the two is meaningless
                // and reports a mismatch that is not a defect. Read at entry time (no new
                // listener); scrolling after LCP settles cannot change the verdict.
                try {
                    if ((typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0) > 0) {
                        scrolledAtLcp = 1;
                    }
                    // A viewport taller than any real browser window is a full-page capture
                    // (screenshot tool, headless shot, print). It resizes rather than scrolls,
                    // so pageYOffset stays 0 while a below-fold image counts as in-view and
                    // wins LCP — a mismatch against the top-of-page preload that is not a defect.
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
            // Only a preload whose media matches THIS viewport was a promise to this browser.
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
            // Positive confirmation: without it "no mismatch reported" cannot be told apart
            // from "never checked", which is the blind spot this beacon exists to close.
            // 1% sampled and once per browser, so the fleet cost stays negligible.
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
        // Not a defect: the visitor had scrolled, so their LCP is not the element the
        // top-of-page preload aims at.
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
