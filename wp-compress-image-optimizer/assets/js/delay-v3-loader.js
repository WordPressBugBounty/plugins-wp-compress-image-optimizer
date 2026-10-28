!function() {
    "use strict";
    // v7.23.15 — CSS-ONLY STAND-DOWN. Critical CSS emits this loader with an EMPTY registry and
    // cssOnly=1 on renders where the delay engine did not run (delay off, per-page JS off, v3
    // forced off): nothing is delayed there and the site's own scripts must run 100% natively.
    // So the whole JS lane stands down — every trap below (addEventListener / dispatchEvent /
    // readyState / document.write, the jQuery ready trap and accessor, the Elementor boot-heal,
    // the hamburger responder, heavy-embed parking, park-escape) and the replay itself. The root
    // guard is re-issued here because it lives below this line. The CSS lane, late sheets,
    // wpc-bgl255 arming, engagement signals and the crit sweep are separate IIFEs and keep
    // running exactly as on a delay page. Kill of the whole feature is server-side
    // (wpc_css_only_loader); this flag is only ever set by that emitter.
    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssOnly === 1) {
        try {
            if (+window.wpcDelayV3Cfg.rootGuard !== 0) {
                var wpcRootGuardEl = document.createElement("style");
                wpcRootGuardEl.id = "wpc-root-guard";
                wpcRootGuardEl.textContent = 'html[style*="display: none"],html[style*="display:none"]{display:block!important}';
                (document.head || document.documentElement).appendChild(wpcRootGuardEl);
            }
        } catch (e) {}
        // The owned-class helper (wpc-bgl255 / wpc-css-live / wpc-js-live re-asserted against any
        // className clobber) is defined inside this IIFE in the shipped min; the return above
        // would skip it and fireGestureQueue would throw on the first gesture. Idempotent, so the later
        // definition is a no-op wherever it lives.
        window.wpcOwnedRootClasses = window.wpcOwnedRootClasses || {};
        window.wpcOwnRootClass = window.wpcOwnRootClass || function(c) {
            window.wpcOwnedRootClasses[c] = 1;
            try { document.documentElement.classList.add(c); } catch (e) {}
        };
        try {
            if (!window.wpcOwnedClassObserverInstalled) {
                window.wpcOwnedClassObserverInstalled = 1;
                new MutationObserver(function() {
                    var de = document.documentElement;
                    for (var c in window.wpcOwnedRootClasses) {
                        if (!de.classList.contains(c)) { de.classList.add(c); }
                    }
                }).observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
            }
        } catch (e) {}
        return;
    }
    /* ------------------------------------------------------------------------------------
     * PRE-RENAME NAME BRIDGE — one release cycle only, delete in 7.26.
     *
     * The versioned loader copy under uploads/wpc-assets/ is overwritten with the CURRENT
     * bytes on every upgrade (classes/js_delay_v3.class.php, the retro-heal): the contract is
     * what is stable, not the bytes. So a page a third-party cache, a CDN or a visitor's disk
     * still serves from before 7.24.10 pairs OLD markup with THIS loader — the head-top heavy
     * embed gate, the jQuery-defer marker script, the hamburger arm script and a wpcDelayV3Cfg
     * carrying the old key. Unbridged, that page's gate goes on pushing held iframe and script
     * srcs into window.__wpcEmbQ40 while this loader drains __wpcHeavyEmbedQueue: no embed is
     * ever released and the old queue stays populated for the life of the page.
     *
     * Each old name is republished as an accessor onto its new one, so the two are ONE
     * property rather than two copies that drift — old markup reading or writing the old name
     * reaches exactly what this loader reads and writes, in both directions and at any time.
     * A value the old markup set before this line ran is carried across first. An old name
     * that is still undefined stays undefined (the hamburger diagnostics accessor is the
     * responder's own liveness signal, and publishing it early would stand the old arm script
     * down on a page whose responder never armed).
     *
     * It sits below the CSS-only stand-down, which stays the first statement of this IIFE:
     * a page the delay engine never ran on carries none of the markup bridged here, so there
     * is nothing for it to reach, and everything that reads these names is below this line.
     *
     * Once every cache that can hold pre-7.24.10 HTML has cycled, delete this block, the two
     * data-wpc-tap34 reads in wpcConsumePreLoaderTap, and nothing else.
     * ---------------------------------------------------------------------------------- */
    (function wpcBridgePreRenameNames() {
        try {
            var bridge = function(oldName, newName) {
                var carried = window[oldName];
                try {
                    Object.defineProperty(window, oldName, {
                        configurable: true,
                        get: function() { return window[newName]; },
                        set: function(v) { window[newName] = v; }
                    });
                } catch (e) { return; }
                if (window[newName] === undefined && carried !== undefined) { window[newName] = carried; }
            };
            // The heavy-embed gate: its queue, its released flag, its flusher, and the parked
            // background restorer the old gate calls by name at the end of its own drain.
            bridge("__wpcEmbQ40", "__wpcHeavyEmbedQueue");
            bridge("__wpcEmbRel40", "__wpcHeavyEmbedsReleased");
            bridge("wpcEmbFlush40", "wpcFlushHeavyEmbeds");
            bridge("wpcBgAll41", "wpcRestoreAllParkedBackgrounds");
            // The jQuery-defer marker: old markup mints the object at head top, this loader
            // hangs its replay callbacks on it, and the old DOMContentLoaded handler fires them.
            bridge("wpcJqDef47", "wpcJqueryDeferMarker");
            // The hamburger responder's diagnostics accessor. The old arm script reads it to
            // decide the responder is live and stand its own capture listener down, so it must
            // stay undefined until the responder actually arms — which the accessor preserves.
            bridge("wpcHamState365", "wpcHamburgerState");
            // The Elementor-heal kill switch is one wpcDelayV3Cfg key, renamed in place.
            var cfg = window.wpcDelayV3Cfg;
            if (cfg && cfg.elementorHeal === undefined && cfg.ef364 !== undefined) {
                cfg.elementorHeal = cfg.ef364;
            }
        } catch (e) {}
    })();
    // The bodies of parked inline scripts live in a sidecar (wpcDelayV3Cfg.registryUrl), not in
    // the document. Fetched at low priority once the page has loaded, merged into the registry
    // entries marked ext before the replay starts; a gesture before the fetch settles waits for it.
    // If the sidecar cannot be read, the page itself is asked for the inline form, and each entry takes
    // the body its ext picks out: ext 2, the body of the script whose tag has the same id attribute
    // (a handle, which nonces and order do not change; entries with one id are paired in order); a
    // hash, the body with that hash (a script with no id); ext 1, written by an older release, the body
    // at the entry's own id. An entry left without a body stays parked (wpcRegistryBodiesUnmatched).
    var wpcRegistryBodies = function() {
        var p = null;
        var merge = function(b, f) {
            var r = window.wpcScriptRegistry, n = 0, u = 0, t = Object.create(null);
            if (!b || !Array.isArray(r)) return 0;
            for (var i = 0; i < r.length; i++) {
                var x = r[i];
                if (x && x.ext && x.id) {
                    var c = void 0, g = x.attributes && typeof x.attributes.id === "string" ? x.attributes.id : "";
                    if (!f) {
                        c = b[x.id];
                    } else if (x.ext === 1) {
                        c = f.d[x.id];
                    } else if (g) {
                        var j = t[g] || 0;
                        t[g] = j + 1;
                        c = f.t[g] && f.t[g][j];
                    } else if (typeof x.ext === "string") {
                        c = b[x.ext];
                    }
                    if (typeof c === "string") {
                        x.content = c;
                        delete x.ext;
                        n++;
                    } else {
                        u++;
                    }
                }
            }
            window.wpcRegistryBodiesUnmatched = u;
            return n;
        };
        var sidecar = function(u) {
            var key = /\/(r-[0-9a-f]{32})\.js(?:[?#]|$)/.exec(u);
            if (key) {
                return new Promise(function(ok, fail) {
                    var s = document.createElement("script");
                    s.src = u;
                    s.async = true;
                    s.setAttribute("data-wpc-registry", "1");
                    s.onload = function() {
                        var j = window.wpcRegistrySidecar && window.wpcRegistrySidecar[key[1]];
                        j && j.b ? ok(j.b) : fail(new Error("registry sidecar shape"));
                    };
                    s.onerror = function() { fail(new Error("registry sidecar load")); };
                    (document.head || document.documentElement).appendChild(s);
                });
            }
            return fetch(u, { credentials: "same-origin", priority: "low" }).then(function(res) {
                if (!res.ok) throw new Error("registry sidecar " + res.status);
                return res.json();
            }).then(function(j) {
                if (!j || !j.b) throw new Error("registry sidecar shape");
                return j.b;
            });
        };
        var page = function() {
            var u = String(location.href).split("#")[0];
            u += (u.indexOf("?") > -1 ? "&" : "?") + "wpc_registry_inline=1";
            return fetch(u, { credentials: "same-origin", cache: "no-store" }).then(function(res) {
                return res.text();
            }).then(function(t) {
                var m = /var wpcScriptRegistry=(\[[\s\S]*?\]);var wpcDelayV3Cfg=/.exec(t);
                if (!m) throw new Error("registry inline missing");
                var b = Object.create(null), d = Object.create(null), g = Object.create(null), a = JSON.parse(m[1]);
                for (var i = 0; i < a.length; i++) {
                    var e = a[i];
                    if (!e || typeof e.content !== "string") continue;
                    if (e.h) b[e.h] = e.content;
                    if (e.id) d[e.id] = e.content;
                    if (e.attributes && typeof e.attributes.id === "string" && e.attributes.id) (g[e.attributes.id] = g[e.attributes.id] || []).push(e.content);
                }
                return [b, { d: d, t: g }];
            });
        };
        var start = function() {
            if (p) return p;
            var cfg = window.wpcDelayV3Cfg, u = cfg && cfg.registryUrl;
            if (!u || typeof fetch !== "function") return (p = Promise.resolve(0));
            p = sidecar(u).then(merge, function() {
                return page().then(function(f) { return merge(f[0], f[1]); });
            }).then(function(n) {
                window.wpcRegistryBodiesMerged = n;
                return n;
            }).catch(function(err) {
                window.wpcRegistryBodiesMerged = 0;
                try { console.warn("[WPC] delayed inline scripts unavailable:", err && err.message); } catch (z) {}
                return 0;
            });
            return p;
        };
        try {
            if (window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.registryUrl) {
                var kick = function() { setTimeout(start, 0); };
                "complete" === document.readyState ? kick() : window.addEventListener("load", kick, { once: true });
            }
        } catch (z) {}
        return start;
    }();
    // A keyless Maps loader (key=&) can never initialize — 365KB of dead weight some
    // form plugins inject at runtime. Refuse the src at set-time; keyed Maps untouched.
    !function() {
        try {
            var mk = function(v) {
                return /maps\.googleapis\.com\/maps\/api\/js/.test(String(v)) && /[?&]key=(?:&|$)/.test(String(v));
            }, ce = document.createElement, sd = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, "src");
            // v7.21.186 — LANE-ESCAPE BELT. A kept/deferred script can inject at runtime a src the
            // registry already delays (Elementor's frontend pulls its own carousel engine the moment
            // it initializes widgets), bypassing the delayed copy and landing the widget-init task in
            // the trace. The registry is the verdict: until release, an injected script whose URL
            // pathname equals a registry entry's pathname parks; release re-sets the real src so the
            // injector's onload chain resolves normally. Kill: wpcDelayV3Cfg.parkEscape=0.
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
                    if (window.__wpcParkedSrcReleased || window.__wpcEarlyBuild) return false;
                    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.parkEscape === 0) return false;
                    var r = window.wpcScriptRegistry;
                    if (!Array.isArray(r) || !r.length) return false;
                    if (!window.__wpcParkedScriptPaths) {
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
                        window.__wpcParkedScriptPaths = s;
                        window.__wpcParkedSrcQueue = [];
                        window.wpcFlushParkedScriptSrcs = function() {
                            window.__wpcParkedSrcReleased = 1;
                            var q = window.__wpcParkedSrcQueue || [];
                            window.__wpcParkedSrcQueue = [];
                            for (var j = 0; j < q.length; j++) {
                                try { sd.set.call(q[j][0], q[j][1]); } catch (z3) {}
                            }
                        };
                    }
                    var p2 = pp(v);
                    if (p2 && p2 !== "/" && window.__wpcParkedScriptPaths[p2]) {
                        window.__wpcParkedSrcQueue.push([el, v]);
                        return true;
                    }
                } catch (z) {}
                return false;
            };
            var fd = (typeof HTMLIFrameElement !== "undefined") ? Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, "src") : null;
            var hvL = function() {
                var c = window.wpcDelayV3Cfg || {}, l = Array.isArray(c.heavyEmbeds) ? c.heavyEmbeds.slice() : [];
                l.push("youtube.com/iframe_api", "youtube.com/player_api", "player.vimeo.com/api/player.js", "fast.wistia.com/assets/external/", "fast.wistia.net/assets/external/");
                return l;
            };
            var hv = function(v) {
                try {
                    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.embedGate === 0) return false;
                    if (window.__wpcEngaged || window.__wpcHeavyEmbedsReleased) return false;
                    var s = String(v || "");
                    if (!s || s.indexOf("about:") === 0 || s.indexOf("data:") === 0 || s.indexOf("javascript:") === 0) return false;
                    var l = hvL();
                    for (var i = 0; i < l.length; i++) if (s.indexOf(l[i]) !== -1) return true;
                } catch (z) {}
                return false;
            };
            window.__wpcHeavyEmbedQueue = window.__wpcHeavyEmbedQueue || [];
            window.wpcFlushHeavyEmbeds = function() {
                window.__wpcHeavyEmbedsReleased = 1;
                var q = window.__wpcHeavyEmbedQueue || [];
                window.__wpcHeavyEmbedQueue = [];
                for (var j = 0; j < q.length; j++) {
                    try { q[j][0].call(q[j][1], q[j][2]); } catch (z3) {}
                }
                try { window.wpcRestoreAllParkedBackgrounds && window.wpcRestoreAllParkedBackgrounds(); } catch (z4) {}
            };
            var eg = function(el, v, setter) {
                if (!hv(v)) return false;
                window.__wpcHeavyEmbedQueue.push([setter, el, v]);
                try { el.setAttribute("data-wpc-embed-held", "1"); } catch (z) {}
                return true;
            };
            document.createElement = function(tag) {
                var el = ce.apply(document, arguments);
                var tl = String(tag).toLowerCase();
                if (sd && tl === "script") try {
                    Object.defineProperty(el, "src", {
                        configurable: true,
                        get: function() { return sd.get.call(el); },
                        set: function(v) { if (!mk(v) && !pk(el, v) && !eg(el, v, function(x) { sd.set.call(this, x); })) sd.set.call(el, v); }
                    });
                    var sa = el.setAttribute;
                    el.setAttribute = function(n, v) {
                        if (String(n).toLowerCase() === "src" && (mk(v) || pk(el, v) || eg(el, v, function(x) { sa.call(this, "src", x); }))) return;
                        return sa.apply(el, arguments);
                    };
                } catch (e) {}
                if (fd && tl === "iframe") try {
                    Object.defineProperty(el, "src", {
                        configurable: true,
                        get: function() { return fd.get.call(el); },
                        set: function(v) { if (!eg(el, v, function(x) { fd.set.call(this, x); })) fd.set.call(el, v); }
                    });
                    var fa = el.setAttribute;
                    el.setAttribute = function(n, v) {
                        if (String(n).toLowerCase() === "src" && eg(el, v, function(x) { fa.call(this, "src", x); })) return;
                        return fa.apply(el, arguments);
                    };
                } catch (e) {}
                return el;
            };
        } catch (e) {}
    }();
    // v7.21.108 — ROOT-HIDE GUARD. Google Translate's engine (gtranslate auto-switch)
    // sets display:none on <html> while it swaps text nodes and restores it ~84ms later.
    // Plugin-off that window sits before first content (invisible); with our early paint
    // it lands on a painted page = one full-white frame (falknerei: hide 49ms after the
    // first gesture, restore +84ms — the customer's "white flash / loads again"; also
    // fires hands-off when the chain loads naturally, the old 7285ms white). Author
    // !important beats non-important inline style, and the [style*=] scope keeps the
    // rule inert unless something inline-hides the root; both serializations covered.
    // Kill: wpcDelayV3Cfg.rootGuard=0 (cfg script precedes the loader tag).
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
        } catch (e) {}
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
    function wpcIsEmbed(e) {
        var t = e && e.tagName;
        return "IFRAME" === t || "FRAME" === t || "OBJECT" === t || "EMBED" === t;
    }
    function wpcSameOrigin(w) {
        if (!w || /^(about|javascript):/i.test(w)) return !0;
        try {
            return new URL(w, location.href).origin === location.origin;
        } catch (x) {
            return !1;
        }
    }
    function wpcLoaded(e) {
        var t = e.tagName;
        if ("IMG" === t) return !(!e.complete || !e.currentSrc && !e.getAttribute("src"));
        if (!wpcIsEmbed(e)) return !0;
        if (!1 === e.isConnected || e.hasAttribute("data-wpc-src")) return !1;
        var u = e.getAttribute("src") || "", w = e.src || e.data || "";
        if (wpcSameOrigin(w)) try {
            var d = e.contentDocument;
            if (d) return "complete" === d.readyState && !("about:blank" === d.URL && "" !== u && "about:blank" !== u && 0 !== u.indexOf("javascript:"));
        } catch (x) {}
        var m = wpcHeadLoads || wpcEmbedSeen;
        if (!m) return null;
        if (m.has(e)) return m.get(e) === w;
        if (wpcHeadLoads && !(wpcHeadBefore && wpcHeadBefore.has(e)) || !wpcEmbedAtBoot.has(e)) return !1;
        try {
            if (w && !wpcRtFull && !performance.getEntriesByName(w).length && !performance.getEntriesByName(w.split("#")[0]).length) return !1;
        } catch (x) {}
        return null;
    }
    // An image's load listener added before the release is native; one that throws before the release is called again at it.
    var wpcImgWraps = [];
    function wpcImgCap(r) {
        return "boolean" == typeof r ? r : !(!r || !r.capture);
    }
    function wpcImgNative(e) {
        var g = window.wpcDelayV3Cfg;
        return !c && "IMG" === e.tagName && !(g && 1 === +g.imgLoadHold);
    }
    function wpcImgAdd(e, t, r) {
        if (!t || "function" != typeof t && "function" != typeof t.handleEvent) return y.call(e, "load", t, r);
        var k = wpcImgCap(r), i;
        for (i = 0; i < wpcImgWraps.length; i++) if (wpcImgWraps[i].target === e && wpcImgWraps[i].listener === t && wpcImgWraps[i].cap === k && !(wpcImgWraps[i].opt && wpcImgWraps[i].opt.signal && wpcImgWraps[i].opt.signal.aborted)) return;
        var w = function(ev) {
            if (r && "object" == typeof r && r.once) wpcImgDrop(e, t, k);
            if (c) return "function" == typeof t ? t.call(this, ev) : t.handleEvent(ev);
            try {
                return "function" == typeof t ? t.call(this, ev) : t.handleEvent(ev);
            } catch (x) {
                wpcImgDrop(e, t, k);
                try {
                    f.call(e, "load", w, r);
                } catch (z) {}
                wpcImgRequeue(e, t, r, k);
            }
        };
        y.call(e, "load", w, r);
        wpcImgWraps.push({
            target: e,
            listener: t,
            cap: k,
            wrap: w,
            opt: r
        });
    }
    function wpcImgDrop(e, t, k) {
        for (var i = 0; i < wpcImgWraps.length; i++) if (wpcImgWraps[i].target === e && wpcImgWraps[i].listener === t && wpcImgWraps[i].cap === k) return wpcImgWraps.splice(i, 1)[0];
        return null;
    }
    function wpcImgRequeue(e, t, r, k) {
        var n = {
            target: e,
            listener: t,
            options: r,
            cap: k,
            fired: !0,
            held: !0
        }, g = r && "object" == typeof r ? r.signal : null;
        if (g) {
            if (g.aborted) return;
            y.call(g, "abort", function() {
                n.removed = !0;
            });
        }
        p.load.push(n);
    }
    function wpcElementLoadListener(e, t, r) {
        if (!e.nodeType) return y.call(e, "load", t, r);
        if (wpcImgNative(e)) return wpcImgAdd(e, t, r);
        var n = {
            target: e,
            listener: t,
            options: r,
            cap: "boolean" == typeof r ? r : !(!r || !r.capture),
            fired: !1,
            held: !c
        };
        y.call(e, "load", t, r), n.held && f.call(e, "load", t, r);
        var g = r && "object" == typeof r ? r.signal : null;
        if (g) {
            if (g.aborted) return;
            y.call(g, "abort", function() {
                n.removed = !0;
            });
        }
        y.call(e, "load", function() {
            n.fired = !0;
        }, {
            once: !0
        }), p.load.push(n);
    }
    function wpcFireLoad(t) {
        try {
            var r = new Event("load");
            try {
                Object.defineProperty(r, "target", {
                    value: t.target === window ? window : t.target,
                    writable: !1
                });
            } catch (e) {}
            "function" == typeof t.listener ? t.listener.call(t.target, r) : t.listener && "function" == typeof t.listener.handleEvent && t.listener.handleEvent(r);
        } catch (t) {
            e("load listener error:", t);
        }
    }
    function wpcElementReplay(t) {
        if (!t.removed) {
            var k = !(!t.options || !t.options.once), g = t.fired && !(wpcIsEmbed(t.target) && t.target.hasAttribute("data-wpc-src")) ? t.held : wpcLoaded(t.target), z = function() {
                if (k) try {
                    f.call(t.target, "load", t.listener, t.options);
                } catch (x) {}
                wpcFireLoad(t);
            };
            if (t.held && !(g && k)) try {
                y.call(t.target, "load", t.listener, t.options);
            } catch (x) {}
            null === g ? setTimeout(function() {
                t.removed || t.fired || z();
            }, 2e3) : g && (t.held ? wpcFireLoad(t) : z());
        }
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
    function wpcEarlySrc(n, u, s) {
        var prev = window.__wpcEarlyBuild;
        s.hasAttribute && s.hasAttribute("data-wpc-early") && (window.__wpcEarlyBuild = 1);
        try {
            n.src = u;
        } finally {
            window.__wpcEarlyBuild = prev;
        }
    }
    function wpcFbErr(s, p) {
        return function() {
            var o = wpcOriginOf(s.src);
            if (!o || s.getAttribute("data-wpc-fb")) return p();
            wpcZoneFail();
            // A FRESH element, never cloneNode: the platform copies the "already started" flag
            // onto a script clone, so a clone of a failed tag never fetches, never fires load or
            // error, and the awaited retry held the whole replay chain forever (namaum.com).
            var n = document.createElement("script"), at = s.attributes, al = at ? at.length : 0, fbFired = 0, fb = function() { if (!fbFired) { fbFired = 1; p(); } };
            for (var ai = 0; ai < al; ai++) { if (at[ai].name !== "src") { try { n.setAttribute(at[ai].name, at[ai].value); } catch (z) {} } }
            // async/defer are IDL properties here, not content attributes: a fresh element
            // defaults async=true, so the retry would be order-free without this.
            n.async = s.async, n.defer = s.defer,
            wpcEarlySrc(n, o, s), n.setAttribute("data-wpc-fb", "1"),
            y.call(n, "load", fb, { once: !0 }), y.call(n, "error", fb, { once: !0 }),
            setTimeout(fb, 30000),
            s.parentNode ? s.parentNode.replaceChild(n, s) : (document.head || document.documentElement).appendChild(n);
        };
    }
    var wpcEv = {
        on: 0,
        re: 0,
        n: 0,
        log: [],
        seen: new WeakMap,
        had: new WeakMap,
        jqAdd: 0,
        closed: 0
    };
    var wpcEvPending = [], wpcEvQueued = !1, wpcEvIds = new WeakMap, wpcEvSeq = 0, wpcEvUnwrap = [];
    var wpcNativeTimeout = window.setTimeout, wpcYieldChannel = null, wpcYieldQueue = [], wpcReadyDrain = null, wpcReadyIn = 0;
    function wpcReleaseOn(key) {
        var cfg = window.wpcDelayV3Cfg;
        return !!(cfg && +cfg[key] === 1);
    }
    function wpcGestureBeforeBoot() {
        if (wpcReleaseOn("releaseNeedsGesture")) return window.wpcGestureSeen === 1;
        return window.wpcGestureSeen === 1 || !!(document.documentElement.classList && document.documentElement.classList.contains("wpc-bgl255"));
    }
    function wpcTrustedCohort() {
        var cfg = window.wpcDelayV3Cfg;
        return !!cfg && +cfg.trusted === 1;
    }
    function wpcFineMoveOn() {
        if (!window.wpcDelayV3Cfg || 1 !== +window.wpcDelayV3Cfg.trustedFineMove || !wpcTrustedCohort()) return !1;
        try {
            return !!(window.matchMedia && window.matchMedia("(hover: hover) and (pointer: fine)").matches);
        } catch (z) {
            return !1;
        }
    }
    var wpcFineMoveFrom = null;
    function wpcFineMoveShort(ev) {
        if (!ev || ev.isTrusted !== !0 || !/^(?:mousemove|pointermove)$/.test(ev.type) || !wpcFineMoveOn()) return !1;
        var x = +ev.clientX || 0, y = +ev.clientY || 0;
        if (!wpcFineMoveFrom) {
            wpcFineMoveFrom = [ x, y ];
            return !0;
        }
        return Math.abs(x - wpcFineMoveFrom[0]) + Math.abs(y - wpcFineMoveFrom[1]) < 4;
    }
    function wpcHintEvent(ev) {
        if (ev && ev.isTrusted === !0 && /^(?:mousemove|pointermove)$/.test(ev.type) && wpcFineMoveOn()) return !1;
        return !!ev && wpcTrustedCohort() && /^(?:mousemove|mouseover|pointermove|scroll)$/.test(ev.type);
    }
    function wpcHardEvent(ev) {
        return !!ev && ev.isTrusted !== false && /^(?:pointerdown|touchstart|keydown|click|wheel)$/.test(ev.type);
    }
    function wpcHardStart() {
        return wpcTrustedCohort() ? window.wpcHardSeen === 1 : wpcGestureBeforeBoot() || window.wpcAfterHeroGesture === 1;
    }
    function wpcHardNote() {
        window.wpcHardSeen = 1;
        if (window.wpcFreshDue === 1) {
            window.wpcFreshDue = 0;
            try {
                localStorage.setItem("fresh", String(Date.now()));
            } catch (z) {}
        }
    }
    function wpcFreshWrite() {
        if (!wpcTrustedCohort() || window.wpcHardSeen === 1) {
            try {
                localStorage.setItem("fresh", String(Date.now()));
            } catch (z) {}
            return;
        }
        if (window.wpcFreshDue === 1) return;
        window.wpcFreshDue = 1;
        var types = [ "pointerdown", "touchstart", "keydown", "click", "wheel" ], on = function(ev) {
            if (!wpcHardEvent(ev)) return;
            for (var i = 0; i < types.length; i++) f.call(window, types[i], on, !0);
            wpcHardNote();
        };
        for (var i = 0; i < types.length; i++) y.call(window, types[i], on, {
            capture: !0,
            passive: !0
        });
    }
    function wpcYield(next) {
        try {
            if (window.scheduler && "function" == typeof window.scheduler.yield) {
                window.scheduler.yield().then(next, next);
                return;
            }
        } catch (z) {}
        try {
            if ("function" == typeof MessageChannel) {
                if (!wpcYieldChannel) {
                    wpcYieldChannel = new MessageChannel;
                    wpcYieldChannel.port1.onmessage = function() {
                        var f = wpcYieldQueue.shift();
                        f && f();
                    };
                }
                wpcYieldQueue.push(next);
                wpcYieldChannel.port2.postMessage(0);
                return;
            }
        } catch (z) {}
        wpcNativeTimeout.call(window, next, 0);
    }
    function wpcEach(list, fn, done) {
        if (!wpcReleaseOn("releaseYield")) {
            list.forEach(fn);
            done();
            return;
        }
        var items = list.slice(), at = 0;
        (function step() {
            if (at >= items.length) {
                done();
                return;
            }
            var item = items[at++];
            try {
                fn(item);
            } catch (x) {
                e("release step error:", x);
            }
            at < items.length ? wpcYield(step) : step();
        })();
    }
    var wpcNoTransitionEl = null, wpcNoTransitionBelt = null, wpcNoTransitionDue = 0, wpcNoTransitionEnded = 0;
    function wpcNoTransitionOn() {
        wpcNoTransitionDue = 0;
        if (wpcNoTransitionEl || !wpcReleaseOn("releaseNoTransition")) return;
        try {
            var st = document.createElement("style");
            st.id = "wpc-release-no-transition";
            st.textContent = "*,*::before,*::after{transition:none!important}";
            (document.head || document.documentElement).appendChild(st);
            wpcNoTransitionEl = st;
            wpcNoTransitionBelt = wpcNativeTimeout.call(window, wpcNoTransitionOff, 1e4);
        } catch (z) {}
        if (wpcNoTransitionEnded) {
            wpcNoTransitionEnded = 0;
            wpcNoTransitionEnd();
        }
    }
    function wpcNoTransitionOff() {
        var st = wpcNoTransitionEl;
        if (!st) return;
        wpcNoTransitionEl = null;
        wpcNoTransitionBelt && clearTimeout(wpcNoTransitionBelt);
        wpcNoTransitionBelt = null;
        try {
            st.parentNode && st.parentNode.removeChild(st);
        } catch (z) {}
    }
    function wpcNoTransitionEnd() {
        if (!wpcNoTransitionEl) {
            wpcNoTransitionDue && (wpcNoTransitionEnded = 1);
            return;
        }
        var gone = function() {
            wpcNoTransitionOff();
        };
        try {
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(function() {
                    window.requestAnimationFrame(gone);
                });
                return;
            }
        } catch (z) {}
        gone();
    }
    var o = 0, c = !1, i = !1, l = "loading", d = !1, s = !1, eventsReplayed = !1, u = [], p = {
        load: [],
        DOMContentLoaded: [],
        readystatechange: [],
        pageshow: [],
        visibilitychange: []
    }, y = EventTarget.prototype.addEventListener.__wpcCR || EventTarget.prototype.addEventListener, f = EventTarget.prototype.removeEventListener, h = EventTarget.prototype.dispatchEvent;
    var wpcHeadLoads = "function" == typeof WeakMap && window.wpcEmbedLoads instanceof WeakMap ? window.wpcEmbedLoads : null, wpcHeadBefore = wpcHeadLoads && "function" == typeof WeakSet && window.wpcEmbedBefore instanceof WeakSet ? window.wpcEmbedBefore : null, wpcEmbedSeen = "function" == typeof WeakMap && "function" == typeof WeakSet ? new WeakMap : null, wpcEmbedAtBoot = wpcEmbedSeen ? new WeakSet : null, wpcRtFull = !1;
    try {
        wpcEmbedSeen && (y.call(document, "load", function(e) {
            var t = e && e.target;
            wpcIsEmbed(t) && wpcEmbedSeen.set(t, t.src || t.data || "");
        }, !0), [].forEach.call(document.querySelectorAll("iframe,frame,object,embed"), function(e) {
            wpcEmbedAtBoot.add(e);
        }));
        wpcRtFull = performance.getEntriesByType("resource").length >= 250, y.call(performance, "resourcetimingbufferfull", function() {
            wpcRtFull = !0;
        });
    } catch (x) {}
    document.readyState;
    // v7.21.170 — NEVER REPORT A STAGE EARLIER THAN THE NATIVE ONE (customer-diagnosed,
    // twice, to the exact getter). .162's "truth until replay" INVERTED the race: the
    // moment interaction triggered the replay (c), the getter regressed a complete
    // document back to "loading" — so one-shot readyState gates (JetPlugins evaluates its
    // block condition once, never retries) now failed for every EARLY-interacting human
    // while zero-interaction robots passed 9/9. readyState is MONOTONIC in the platform;
    // ours is now too: report the native value, always. The staged l walk still drives
    // the synthetic readystatechange/DCL/load replay for captured listeners — the event
    // side needs no lie from the getter.
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
    // v7.22.62 — jQuery ready flushes at the DCL stage of the replay, BEFORE the load stage:
    // native order is DCL -> ready -> load. Flushing after the load hop meant a window "load"
    // listener registered inside a delayed script's ready callback bound natively after the
    // real load and never fired (Favorites: $(window).on('load', getFavorites) never ran, so
    // favorites_array never loaded, buttons never reflected state, the list page stayed empty).
    // Once flushed, v stays set so later ready callbacks run at once (native). cfg readyAtDcl=0 keeps
    // the old post-load position.
    function wpcReadyFlushAtDomContentLoaded() {
        return !(window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.readyAtDcl !== undefined && +window.wpcDelayV3Cfg.readyAtDcl === 0);
    }
    function wpcFlushJqueryReadyQueue(done) {
        if (!g || v) {
            done && done();
            return;
        }
        v = !0;
        var q = m.splice(0, m.length), fin = function() {
            if (g.Deferred && g.ready && g.ready.promise) try {
                var a = g.ready.promise();
                a && "function" == typeof a.resolveWith && a.resolveWith(document, [ g ]);
            } catch (t) {
                e("jQuery ready promise resolve error:", t);
            }
            wpcReplayJqueryReadyEvent();
            done && done();
        };
        if (!wpcReleaseOn("releaseYield")) {
            q.forEach((function(t) {
                try {
                    t(g);
                } catch (t) {
                    e("jQuery ready cb error:", t);
                }
            }));
            fin();
            return;
        }
        var at = 0;
        wpcReadyDrain = q;
        (function step() {
            if (at >= q.length) {
                wpcReadyDrain = null;
                fin();
                return;
            }
            var cb = q[at++];
            wpcReadyIn++;
            try {
                cb(g);
            } catch (t) {
                e("jQuery ready cb error:", t);
            }
            wpcReadyIn--;
            at < q.length ? wpcYield(step) : step();
        })();
    }
    function wpcMarkJqueryReadyHandlersRan(jq) {
        try {
            var ev = jq && jq._data ? jq._data(document, "events") : null, l = ev && ev.ready ? ev.ready : [];
            for (var i = 0; i < l.length; i++) {
                if (l[i]) { l[i].wpcReadyRan = 1; }
            }
        } catch (z) {}
    }
    function wpcArmJqueryReadyEventOnInstance(jq) {
        try {
            if (!jq || !jq.fn || !jq.fn.on || !jq._data || jq.wpcReadyEventArmed) { return; }
            jq.wpcReadyEventArmed = 1;
            if (jq.isReady && !(jq.readyWait > 0)) {
                wpcMarkJqueryReadyHandlersRan(jq);
                if (parseInt(jq.fn.jquery, 10) < 3 || jq.migrateVersion) { jq.wpcReadyEventFired = 1; }
            }
            var sentinel = function() {
                jq.wpcReadyEventFired = 1;
                wpcMarkJqueryReadyHandlersRan(jq);
            };
            sentinel.wpcIsReadySentinel = 1;
            jq(document).on("ready", sentinel);
        } catch (z) {}
    }
    function wpcReplayJqueryReadyEvent() {
        try {
            if (!g || !g._data || !g.event || !g.wpcReadyEventFired) { return; }
            var ev = g._data(document, "events"), l = ev && ev.ready ? ev.ready : [], due = !1;
            for (var i = 0; i < l.length; i++) {
                if (l[i] && !l[i].wpcReadyRan && !(l[i].handler && l[i].handler.wpcIsReadySentinel)) { due = !0; }
            }
            if (!due) { return; }
            var sp = g.event.special.ready || (g.event.special.ready = {}),
                had = Object.prototype.hasOwnProperty.call(sp, "handle"), prev = sp.handle;
            sp.handle = function(evt) {
                var h = evt && evt.handleObj;
                if (!h || h.wpcReadyRan || (h.handler && h.handler.wpcIsReadySentinel)) { return; }
                h.wpcReadyRan = 1;
                return h.handler.apply(this, arguments);
            };
            try {
                g(document).triggerHandler("ready");
            } finally {
                if (had) { sp.handle = prev; } else { delete sp.handle; }
            }
        } catch (t) {
            e("jQuery ready event replay error:", t);
        }
    }
    var wpcClickWide = "function" == typeof WeakSet && window.wpcClickBoundEls instanceof WeakSet;
    var wpcClickEls = "function" != typeof WeakSet ? null : wpcClickWide ? window.wpcClickBoundEls : new WeakSet;
    window.wpcClickBoundEls = wpcClickEls;
    window.wpcClickWide = wpcClickWide;
    function wpcClickNote(el) {
        if (wpcClickEls && el && 1 === el.nodeType) {
            try { wpcClickEls.add(el); } catch (z) {}
        }
    }
    if (window.WPC_STRICT_ORDER = !!window.WPC_STRICT_ORDER, function() {
        try {
            var e = document.write.bind(document);
            document.write = function() {
                return window.WPC_STRICT_ORDER = !0, e.apply(document, arguments);
            };
        } catch (e) {}
    }(), EventTarget.prototype.addEventListener = function(t, r, n) {
        wpcEarlyStep && wpcEarlyNote("listener");
        if ("click" === t && wpcEarlyLane && !wpcEv.jqAdd && this && 1 === this.nodeType && wpcEarlyLaneBinds()) try { wpcEarlyBoundEls.add(this); } catch (z) {}
        if (("load" === t || "error" === t) && a(this)) return y.call(this, t, r, n);
        if ("load" === t && !s && null != this && this !== window && this !== document) return wpcElementLoadListener(this, r, n);
        var o = !1;
        return ("DOMContentLoaded" !== t || d) && ("load" !== t || s) ? "readystatechange" === t && "complete" !== l ? o = !0 : ("pageshow" === t || "visibilitychange" === t) && !eventsReplayed && (o = !0) : o = !0,
        o && t in p ? (e("Intercepting event listener for:", t), void p[t].push({
            target: this,
            listener: r,
            options: n
        })) : (y.call(this, t, r, n), "click" === t && wpcClickNote(this), void ((wpcEv.on || wpcEv.re) && wpcEvOnAdd(this, t, r)));
    }, EventTarget.prototype.removeEventListener = function(t, r, n) {
        if ("load" === t && null != this && this !== window && this !== document) {
            var wpcW = wpcImgDrop(this, r, wpcImgCap(n));
            if (wpcW) try {
                f.call(this, "load", wpcW.wrap, wpcW.opt);
            } catch (z) {}
        }
        if ("load" === t && null != this && this !== window && this !== document) for (var o = "boolean" == typeof n ? n : !(!n || !n.capture), i = 0; i < p.load.length; i++) "held" in p.load[i] && p.load[i].target === this && p.load[i].listener === r && p.load[i].cap === o && (p.load[i].removed = !0);
        return f.call(this, t, r, n);
    }, EventTarget.prototype.dispatchEvent = function(t) {
        return "load" !== t.type && "error" !== t.type || !a(this) ? (-1 !== [ "load", "DOMContentLoaded", "readystatechange", "pageshow" ].indexOf(t.type) && (u.push({
            type: t.type,
            target: this,
            bubbles: !!t.bubbles,
            cancelable: !!t.cancelable,
            detail: t.detail || null
        }), e("Captured real event:", t.type, "on", this.constructor && this.constructor.name || "node")), 
        c || -1 === [ "load", "DOMContentLoaded", "readystatechange" ].indexOf(t.type) ? wpcEv.on ? wpcEvDispatch(this, t) : h.call(this, t) : (e("Suppressing event:", t.type), 
        !0)) : h.call(this, t);
    }, 1) {
        // NO READY TRAP AT BOOT. Before the replay trigger no delayed script can run, so every
        // jQuery ready call made until then comes from a script that is NOT delayed, and it
        // belongs at the native DOMContentLoaded. Trapping a jQuery that already exists at boot
        // (a blocking jQuery: the jQuery-defer lane stands down when jQuery is on the Delay JS
        // exclusions) held those callbacks until a gesture: on acrystalglass.com the pixfort
        // bundle (deferred by WordPress, not by us) runs `jQuery(document).ready(...)` to start
        // its reveal, the page was interaction-only, and the logo and menu stayed hidden for
        // good. The trap is installed at the replay trigger instead, which is what every page
        // whose jQuery is deferred already ran (jQuery did not exist at boot there).
    }
    window.wpcNativeAddEventListener = y;
    // v7.22.30 — THE READY TRAP MUST TRAP THE jQUERY THAT ARRIVES. It was installed once at
    // boot on window.jQuery; on every site where jQuery is deferred (.47 lane) jQuery did not
    // exist yet, the trap was never installed, and delayed scripts' ready callbacks ran the
    // instant each script executed instead of after the whole replay — register-then-init
    // phasing gone (lawyerscolumbusohio, Oxygen: pro-menu setup ran before AOS.init, AOS
    // scanned the sub-menus as animated-in, every dropdown open). Installed at the replay
    // trigger on whatever jQuery is live then (and by the jQuery setter once the replay has
    // started), never at boot; idempotent per instance.
    function wpcInstallJqueryReadyTrap() {
        var jq = window.jQuery;
        if (!jq || !jq.fn || !jq.fn.ready || jq.fn.__wpcJqReadyTrapInstalled) return;
        g = jq;
        jq.fn.__wpcJqReadyTrapInstalled = 1;
        jq.fn.ready = function(t) {
            if (c && v && wpcReadyDrain && !wpcReadyIn) {
                return wpcReadyDrain.push(t), this;
            }
            if (c && v) {
                try {
                    t(jq);
                } catch (x) {
                    e("Error in jQuery ready callback:", x);
                }
                return this;
            }
            if (wpcSidOf) {
                var wpcCs = document.currentScript, wpcCsId = wpcCs && wpcSidOf.get(wpcCs);
                if (wpcCs && wpcCs.getAttribute && wpcCs.getAttribute("data-wpc-jump") && "function" == typeof t) {
                    wpcNativeTimeout.call(window, (function() {
                        try {
                            t(jq);
                        } catch (x) {
                            e("Error in jQuery ready callback:", x);
                        }
                    }), 0);
                    return this;
                }
                wpcCsId && "function" == typeof t && wpcReadyFrom.set(t, wpcCsId);
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
    // v7.22.27 — KEPT SCRIPTS' READY CALLBACKS FLUSH AT THE GESTURE, IN ORDER. The trap exists
    // for DELAYED scripts (register-then-init phasing across the replay). Callbacks captured
    // BEFORE the replay starts come from kept/deferred scripts that natively ran at DCL —
    // Elementor's own `jQuery(() => elementorFrontend.init())` among them — and holding them
    // until the whole replay had run left no widget hook bus for ~450ms after the gesture
    // (columbus: mega-menu opened at 220px, UAEL width JS not yet bound). They now run the
    // instant the replay is triggered, in registration order (Elementor init in its natural
    // slot, after the kept listeners that attach to it); one that throws (an undeclared
    // runtime dependency still in the delayed lane) is re-queued for the end-of-replay flush.
    function wpcRunJqueryReadyAtReplayStart() {
        if (!g || !m.length) return;
        wpcMediaPull();
        var pre = m.splice(0, m.length), again = [];
        for (var i = 0; i < pre.length; i++) {
            try { pre[i](g); } catch (x) { again.push(pre[i]); e("ready cb deferred to replay end:", x); }
        }
        if (again.length) m = again.concat(m);
    }
    // v7.22.35 — KEPT SCRIPTS' DOMContentLoaded LISTENERS FLUSH AT THE GESTURE TOO (.27 for the
    // raw DCL listener). Bricks (kept, native defer) registers ONE DOMContentLoaded listener
    // that binds every menu/submenu handler; registered after boot it was captured and replayed
    // only at the END of the replay — 2–3s after the first hover on a page whose registry
    // carries a slow third party (aliiadventureshack: fareharbor). The hover that started the
    // replay found no listener, so the dropdown never opened (li never got .open; native opens
    // in 250ms). Listeners captured BEFORE the trigger come from kept/inline scripts that
    // natively ran at DCL, before any delayed script — so they run at the trigger, in order,
    // before the replay starts; one that throws (a delayed global it expects) is re-queued for
    // the end-of-replay pass. .171 still holds: while the document is natively parsing the
    // flush waits for the real DCL, never before it.
    function wpcFlushDomContentLoadedQueue() {
        try {
            if (!p.DOMContentLoaded.length) { return; }
            if (wpcRealRS() === "loading") {
                if (wpcFlushDomContentLoadedQueue.w) { return; }
                wpcFlushDomContentLoadedQueue.w = 1;
                y.call(document, "DOMContentLoaded", function() { wpcFlushDomContentLoadedQueue(); }, { once: !0 });
                return;
            }
            wpcMediaPull();
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
    function C(e, t, early) {
        early || wpcMediaPull();
        wpcSidOf && t && wpcSidOf.set(e, t);
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
    function S(a, early) {
        early || wpcMediaPull();
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
                    })), C(i, a.id, early), void (a.src || l());
                }
                if ("module" !== a.type) {
                    var d = E(a), s = d.el, u = d.inline, p = function() {
                        o++, c();
                    };
                    early && "function" == typeof WeakMap && (wpcEntryOf = wpcEntryOf || new WeakMap).set(s, a);
                    var tag = early ? wpcEarlyLaneAdd(a) : "";
                    if (u) {
                        // inline: land through the jQuery-capability gate, then resolve
                        return void wpcJqGate(s.text, (function(k) {
                            k ? s.text = "console.warn('[WPC] delayed inline script skipped: jQuery never became capable — '+" + JSON.stringify(String(a.id || "")) + ");" : tag && (s.text += "\n//# sourceURL=" + tag),
                            C(s, a.id, early), p();
                        }));
                    }
                    return early && (s.setAttribute("fetchpriority", "low"), s.setAttribute("data-wpc-early", "1")), y.call(s, "load", p, {
                        once: !0
                    }), y.call(s, "error", wpcFbErr(s, p), {
                        once: !0
                    }), void C(s, a.id, early);
                }
                var f = function(e) {
                    var n = document.createElement("script");
                    return n.type = "module", n.src = wpcJsSrc(t(e.src, !!e.encoded)), r(n, e.attributes), early && (n.setAttribute("fetchpriority", "low"), n.setAttribute("data-wpc-early", "1")), n;
                }(a), h = !1, g = function() {
                    h || (h = !0, o++, c());
                };
                y.call(f, "load", g, {
                    once: !0
                }), y.call(f, "error", wpcFbErr(f, g), {
                    once: !0
                }), C(f, a.id, early);
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
        // addEventListener stays patched until the pageshow stage: restoring it here opened a
        // window where a script landed BY the replay (readyState still "interactive") bound
        // window "load" natively — after the real load, outside p.load — and waited forever.
        // AutoScroll autoStart is exactly that shape: mounted marquee, frozen transform. The
        // patch's own d/s/l flags already stop capture per stage, so staying installed is safe.
        // v7.21.171 — EVENTS ARE MONOTONIC TOO (the getter's .170 rule, event edition):
        // an interaction-triggered replay fired the synthetic DCL while the document was
        // NATIVELY still loading; scripts executing after that stage but before real DCL
        // (deferred jQuery on a slow parse) registered listeners into a window nothing
        // would ever fire — jQuery.ready lost, JetPlugins.init never ran (optica load10:
        // bulkBlocksInit logged, init() absent). If the document is natively still
        // parsing, park the WHOLE event replay until the real DCL has passed; the
        // resource loading it follows is untouched.
        if (wpcRealRS() === "loading") {
            e("Native DCL pending - parking event replay until it fires");
            y.call(document, "DOMContentLoaded", function() { setTimeout(L, 0); }, { once: !0 });
            return;
        }
        var wpcStages = function() {
            setTimeout((function() {
                var afterDcl = function() {
                    var next = function() {
                        setTimeout((function() {
                            l = "complete";
                            var r = new Event("readystatechange");
                            h.call(document, r);
                            wpcEach(p.readystatechange, (function(t) {
                                try {
                                    t.listener.call(t.target, r);
                                } catch (t) {
                                    e("readystatechange (complete) error:", t);
                                }
                            }), (function() {
                                setTimeout((function() {
                                    if (s) { return; }
                                    s = !0;
                                    wpcEach(u.filter((function(e) {
                                        return "load" === e.type;
                                    })), (function(e) {
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
                                    }), (function() {
                                        // v7.10.648 — POST-PAINT YIELD between the element load-event dispatches
                                        // (whose handlers write DOM) and the window load-listener replay (whose
                                        // handlers read layout). Same task meant every read was a forced layout
                                        // charged to this loader (service trace: one of its two 71ms sites).
                                        // rAF→setTimeout(0) is the house post-paint hook; ordering within the
                                        // replay chain is unchanged — everything downstream rides the same hop.
                                        var wpcReplayLoadListenersAfterPaint = function() {
                                            wpcEach(p.load, (function(t) {
                                                try {
                                                    "held" in t ? wpcElementReplay(t) : wpcFireLoad(t);
                                                } catch (x) {
                                                    e("load listener error:", x);
                                                }
                                            }), (function() {
                                                setTimeout((function() {
                                                    EventTarget.prototype.addEventListener = y, EventTarget.prototype.removeEventListener = f;
                                                    wpcEvClose();
                                                    var r = new Event("pageshow");
                                                    h.call(window, r);
                                                    wpcEach(p.pageshow, (function(t) {
                                                        try {
                                                            t.listener.call(t.target, r);
                                                        } catch (t) {
                                                            e("pageshow listener error:", t);
                                                        }
                                                    }), (function() {
                                                        var n = new Event("visibilitychange");
                                                        h.call(document, n);
                                                        wpcEach(p.visibilitychange, (function(t) {
                                                            try {
                                                                t.listener.call(t.target, n);
                                                            } catch (t) {
                                                                e("visibilitychange listener error:", t);
                                                            }
                                                        }), (function() {
                                                            wpcFlushJqueryReadyQueue((function() {
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
                                                                window.wpcScriptsLoadedAt = performance.now();
                                                                var c = new CustomEvent("wpc-scripts-loaded", {
                                                                    detail: {
                                                                        totalScripts: Array.isArray(wpcScriptRegistry) ? wpcScriptRegistry.length : 0
                                                                    }
                                                                });
                                                                if (window.dispatchEvent(c), e("Dispatched wpc-scripts-loaded"), "undefined" != typeof elementorFrontend && elementorFrontend.elements && elementorFrontend.elements.$window) {
                                                                    window.wpcResizeWithMenusIsolated(function() { elementorFrontend.elements.$window.trigger("resize"); });
                                                                    var wpcLoopMo = new MutationObserver((function(e) {
                                                                        var t = !1;
                                                                        e.forEach((function(e) {
                                                                            e.addedNodes.forEach((function(e) {
                                                                                if (1 === e.nodeType) {
                                                                                    var r = jQuery(e).find(".wpc-delay-elementor").addBack(".wpc-delay-elementor");
                                                                                    r.length > 0 && (r.removeClass("wpc-delay-elementor"), t = !0);
                                                                                }
                                                                            }));
                                                                        }));
                                                                    }));
                                                                    jQuery(document).ready((function() {
                                                                        document.querySelectorAll(".elementor-loop-container").forEach((function(t) {
                                                                            wpcLoopMo.observe(t, {
                                                                                childList: !0,
                                                                                subtree: !0
                                                                            });
                                                                        }));
                                                                    }));
                                                                }
                                                                wpcNoTransitionEnd();
                                                            }));
                                                        }));
                                                    }));
                                                }), 20);
                                            }));
                                        };
                                        if (window.requestAnimationFrame) {
                                            requestAnimationFrame((function() {
                                                setTimeout(wpcReplayLoadListenersAfterPaint, 0);
                                            }));
                                        } else {
                                            setTimeout(wpcReplayLoadListenersAfterPaint, 0);
                                        }
                                    }));
                                }), 20);
                            }));
                        }), 20);
                    };
                    wpcReadyFlushAtDomContentLoaded() ? wpcFlushJqueryReadyQueue(next) : next();
                };
                if (d) {
                    afterDcl();
                    return;
                }
                d = !0;
                wpcEach(u.filter((function(e) {
                    return "DOMContentLoaded" === e.type;
                })), (function(e) {
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
                }), (function() {
                    wpcEach(p.DOMContentLoaded, (function(t) {
                        try {
                            t.listener.call(t.target, new Event("DOMContentLoaded"));
                        } catch (t) {
                            e("DOMContentLoaded listener error:", t);
                        }
                    }), afterDcl);
                }));
            }), 20);
        };
        if (e("Replaying captured events and restoring prototypes"), eventsReplayed = !0,
        EventTarget.prototype.dispatchEvent = h,
        "loading" === l) {
            l = "interactive";
            var r = new Event("readystatechange");
            h.call(document, r);
            wpcEach(p.readystatechange, (function(t) {
                try {
                    t.listener.call(t.target, r);
                } catch (t) {
                    e("readystatechange (interactive) error:", t);
                }
            }), wpcStages);
        } else {
            wpcStages();
        }
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
            var g = [ "mousemove", "pointermove", "pointerdown", "wheel", "click", "keydown", "touchstart", "scroll" ], f = function(ev) {
                if (ev && !1 === ev.isTrusted && wpcTrustedCohort()) return;
                if (wpcFineMoveShort(ev)) return;
                if (wpcHintEvent(ev)) return;
                if (window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r) {
                    window.wpcJqueryDeferMarker.cb2 = f;
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
    // v7.21.33 — LATE-LISTENER SNAPSHOT. When Elementor booted EAGER (promotion pulled
    // jQuery+Elementor into the eager bucket while third-party widget scripts stayed
    // delayed), its one-shot elementor/frontend/init fired at page load — before any lane
    // script could register a listener. Snapshot the handlers registered at lane start;
    // everything that appears after the replay is a listener that provably MISSED the
    // event and never ran (so re-invoking it cannot double-bind anything).
    function wpcSnapshotElementorInitHandlers() {
        try {
            window.wpcElementorBootedBeforeLoader = !!(window.jQuery && jQuery._data && window.elementorFrontend
                && elementorFrontend.elementsHandler && elementorFrontend.elementsHandler.runReadyTrigger);
            window.wpcElementorInitHandlersAtBoot = [];
            if (window.wpcElementorBootedBeforeLoader) {
                var ev = jQuery._data(window, "events");
                var l = ev && ev["elementor/frontend/init"] ? ev["elementor/frontend/init"] : [];
                for (var i = 0; i < l.length; i++) {
                    if (l[i] && l[i].handler) { window.wpcElementorInitHandlersAtBoot.push(l[i].handler); }
                }
            }
        } catch (z) {
            window.wpcElementorBootedBeforeLoader = false;
        }
    }
    // v7.21.364 — THE ONE-SHOT HAS ONE OWNER (staging.wpcompress.com hamburger fired 4x
    // per tap: open/close/open/close = menu dead). Three belts each re-delivered
    // elementor/frontend/init on their own guess at which listeners had already run:
    // .618 and .321 re-FIRED the event blanket-wide, so every already-run registrar ran
    // again and re-registered its element_ready hooks; .33 diffed against a LANE-START
    // snapshot, which cannot see a native fire that happens mid-replay AFTER a late
    // registrar registered. Four hook registrations -> one element_ready dispatch bound
    // four click handlers on the toggle. Traced live: FIRE#1 the .618 ladder, FIRE#2
    // wpcFireElementorInitOnce, four identical binds inside 3ms.
    // Ground truth about "who already ran" exists at exactly one moment: the dispatch
    // itself. jQuery dispatches over a COPY of the handler list, so the registered list
    // at fire time IS the ran-set. A sentinel listener — installed from inside jQuery's
    // own assignment via a window.jQuery accessor, provably before any later replayed
    // script can fire init — records that set. Every belt now routes through
    // wpcFireMissedElementorInitHandlers(), which invokes ONLY handlers in neither the ran-set nor the
    // healed-set, and records the frontend/element_ready/ types they register so callers
    // can runReadyTrigger just those widgets. Re-firing the event is banned outright.
    // v7.22.15 — A RESIZE MUST NOT BE MEASURED WITH A MENU OPEN. Elementor's sticky
    // re-measures its spacer on every resize; our replay-end resize (and the RUM lane's)
    // fired while a NATIVE-opened dropdown sat in flow inside the header, so the sticky
    // baked the open menu's height into its spacer (388px vs 76px) and the close left a
    // white band on top (James: "a bit of a white bg on top when closing", only after a
    // long dwell = only when the tap landed post-native-bind and replay finished during
    // the dwell). Every resize we dispatch now runs with open Elementor dropdown panels
    // height-isolated for the duration of the synchronous dispatch, then restored.
    var wpcResizeQueue = [], wpcMenusOpenAtStart = null;
    function wpcOpenMenus() {
        var open = [];
        try {
            var nodes = document.querySelectorAll('[aria-expanded="true"],.site-navigation-toggle-holder.elementor-active');
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.getAttribute("aria-expanded") === "true" && !el.matches("button,a,[role=button],summary")) continue;
                if (el.closest("header,nav,[role=navigation],.site-header,.elementor-widget-nav-menu")) open.push(el);
            }
        } catch (z) {}
        return open;
    }
    function wpcMenusSnapshot(none) {
        if (wpcMenusOpenAtStart === null && window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.menuResizeHold === 1) {
            if (none) {
                wpcMenusOpenAtStart = [];
                return;
            }
            var opened = wpcOpenMenus(), shim = window.wpcMenuLiveFired;
            wpcMenusOpenAtStart = shim && "function" == typeof shim.contains ? opened.filter((function(el) {
                return !(el === shim || el.contains(shim) || shim.contains(el));
            })) : opened;
        }
    }
    function wpcMenuOpenedSinceStart() {
        var now = wpcOpenMenus();
        for (var i = 0; i < now.length; i++) if (wpcMenusOpenAtStart.indexOf(now[i]) === -1) return true;
        return false;
    }
    function wpcResizeHeld(fire) {
        if (!window.wpcDelayV3Cfg || +window.wpcDelayV3Cfg.menuResizeHold !== 1 || wpcMenusOpenAtStart === null || !wpcMenuOpenedSinceStart()) return false;
        wpcResizeQueue.push(fire);
        if (wpcResizeQueue.length === 1) {
            var until = Date.now() + (+window.wpcDelayV3Cfg.menuResizeHoldMs > 0 ? +window.wpcDelayV3Cfg.menuResizeHoldMs : 6e4);
            var poll = function() {
                var capped = Date.now() >= until;
                if (!capped && wpcMenuOpenedSinceStart()) {
                    wpcNativeTimeout.call(window, poll, 400);
                    return;
                }
                var queued = wpcResizeQueue.splice(0);
                for (var i = 0; i < queued.length; i++) {
                    if (capped) wpcResizeNow(queued[i]); else window.wpcResizeWithMenusIsolated(queued[i]);
                }
            };
            wpcNativeTimeout.call(window, poll, 400);
        }
        return true;
    }
    window.wpcResizeWithMenusIsolated = function(fire) {
        if (wpcResizeHeld(fire)) return;
        wpcResizeNow(fire);
    };
    function wpcResizeNow(fire) {
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
    }
    // v7.22.03 — INTERIM HAMBURGER RESPONDER. Elementor Pro ships the nav-menu handler
    // as an async chunk (nav-menu.<hash>.bundle.min.js) that only starts loading once
    // replay reaches Pro's frontend — measured on staging: 2,975ms of dead taps between
    // the first gesture and a responsive menu. Until the real handler binds, the loader
    // answers the tap itself with the EXACT native mutations (measured on the
    // plugin-disabled page): toggle elementor-active + aria-expanded, container
    // aria-hidden, --menu-height set to scrollHeight. The moment real jQuery click
    // handlers exist on the toggle it stands down forever — closing its own open state
    // first, so the native handler starts from the consistent closed position. Zero
    // network, zero score cost; capture listener installed eagerly at boot.
    (function() {
        try {
            var hamburgerStoodDown = 0, hamburgerOwnedOpenToggle = null, hamburgerArmedSection = null, hamburgerQueuedToggle = null;
            var hamburgerPinProps = ["position", "top", "left", "width", "margin-top", "margin-bottom", "z-index"];
            var hamburgerPinOriginal = null, hamburgerPinValues = null, hamburgerPinObserver = null;
            var wpcHamPinPxOn = function() {
                return !(window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.elementorHealPxWidth !== undefined
                    && +window.wpcDelayV3Cfg.elementorHealPxWidth === 0);
            };
            var wpcHamPinRestore = function(el, props) {
                for (var i = 0; i < props.length; i++) {
                    var p = props[i];
                    if (!hamburgerPinValues || el.style.getPropertyValue(p) !== hamburgerPinValues[p]) { continue; }
                    if (hamburgerPinOriginal[p][0]) { el.style.setProperty(p, hamburgerPinOriginal[p][0], hamburgerPinOriginal[p][1]); }
                    else { el.style.removeProperty(p); }
                }
            };
            var wpcHamPinHandoff = function(sec) {
                try {
                    if (!hamburgerPinValues || !sec) { return; }
                    if (hamburgerPinObserver) { hamburgerPinObserver.disconnect(); hamburgerPinObserver = null; }
                    wpcHamPinRestore(sec, ["left", "z-index"]);
                    var jq = window.jQuery, backup = jq && typeof jq.data === "function" ? jq.data(sec, "css-backup-unsticky") : null, fixed = 0;
                    if (backup && typeof backup === "object") {
                        for (var p in backup) {
                            if (hamburgerPinValues.hasOwnProperty(p) && backup[p] === hamburgerPinValues[p]) { backup[p] = hamburgerPinOriginal[p][0]; fixed++; }
                        }
                    }
                    var id = sec.getAttribute("data-id"), spacers = document.querySelectorAll(".elementor-sticky__spacer"), n = 0;
                    for (var i = 0; i < spacers.length; i++) {
                        if (spacers[i] !== sec && (id ? spacers[i].getAttribute("data-id") === id : spacers[i] === sec.nextElementSibling)) {
                            wpcHamPinRestore(spacers[i], hamburgerPinProps); n++;
                        }
                    }
                    wpcHamburgerDebugLog("pin-handoff", "spacers=" + n + " backup=" + (backup ? fixed : -1));
                } catch (z) {}
            };
            var wpcHamPinArm = function(sec) {
                var rect = sec.getBoundingClientRect(), clientWidth = document.documentElement.clientWidth, i;
                var width = clientWidth > 0 && rect.width > clientWidth ? clientWidth : rect.width;
                var left = clientWidth > 0 ? Math.max(0, Math.min(rect.left, clientWidth - width)) : rect.left;
                hamburgerPinOriginal = {};
                for (i = 0; i < hamburgerPinProps.length; i++) {
                    hamburgerPinOriginal[hamburgerPinProps[i]] = [sec.style.getPropertyValue(hamburgerPinProps[i]), sec.style.getPropertyPriority(hamburgerPinProps[i])];
                }
                sec.style.setProperty("position", "fixed");
                sec.style.setProperty("top", "0px");
                sec.style.setProperty("left", left + "px");
                sec.style.setProperty("width", width + "px");
                sec.style.setProperty("margin-top", "0px");
                sec.style.setProperty("margin-bottom", "0px");
                sec.style.setProperty("z-index", "2000");
                hamburgerPinValues = {};
                for (i = 0; i < hamburgerPinProps.length; i++) {
                    hamburgerPinValues[hamburgerPinProps[i]] = sec.style.getPropertyValue(hamburgerPinProps[i]);
                }
                wpcHamburgerDebugLog("pin", "w=" + Math.round(width) + " l=" + Math.round(left) + " rw=" + Math.round(rect.width) + " cw=" + clientWidth);
                try {
                    if (typeof MutationObserver === "function") {
                        hamburgerPinObserver = new MutationObserver(function() {
                            try {
                                var armed = hamburgerArmedSection, next = armed && armed.nextElementSibling;
                                if (armed && ((" " + armed.className + " ").indexOf(" elementor-sticky--active ") !== -1
                                    || (next && (" " + next.className + " ").indexOf(" elementor-sticky__spacer ") !== -1))) { wpcHamPinHandoff(armed); }
                            } catch (z) {}
                        });
                        hamburgerPinObserver.observe(sec, { attributes: true, attributeFilter: ["class"] });
                        if (sec.parentNode) { hamburgerPinObserver.observe(sec.parentNode, { childList: true }); }
                    }
                } catch (z) {}
            };
            var wpcHamPinRelease = function(sec) {
                var nativeOwns = (" " + sec.className + " ").indexOf(" elementor-sticky--active ") !== -1;
                wpcHamburgerDebugLog("pin-release", nativeOwns ? "handoff" : "restore");
                if (nativeOwns) { wpcHamPinHandoff(sec); }
                else {
                    if (hamburgerPinObserver) { hamburgerPinObserver.disconnect(); hamburgerPinObserver = null; }
                    wpcHamPinRestore(sec, hamburgerPinProps);
                }
                hamburgerPinValues = null; hamburgerPinOriginal = null;
            };

            // v7.22.19 — NOTHING BEFORE THE PAGE'S OWN CSS. James: "works if I wait ~1s; a tap
            // right on refresh does the white collapse, or white while open". Before the
            // parked stylesheets restore, the header is UNSTYLED — fixing it puts a
            // viewport-tall white block over the page, and the panel has no styling to
            // measure. A tap before CSS-live is queued and performed the frame the parked
            // CSS is gone (or html.wpc-css-live lands); a second tap while queued cancels.
            var wpcHamburgerCssLive = function() {
                try {
                    if (document.documentElement.classList.contains("wpc-css-live")) { return true; }
                    return !document.querySelector('link[rel^="wpc-"], style[type^="wpc-"]');
                } catch (z) { return true; }
            };
            // v7.22.13 — DIAGNOSTICS BUILT IN. window.wpcHamburgerState() returns the responder's
            // own view (why it did or did not arm, what it measured, its event timeline); with
            // localStorage.wpcHamDebug=1 every decision also logs to the console as it happens.
            var hamburgerDebugRows = [], hamburgerArmReason = "";
            var wpcHamburgerDebugLog = function(ev, info) {
                try {
                    var row = [Math.round(performance.now()), ev, info || ""];
                    hamburgerDebugRows.push(row); if (hamburgerDebugRows.length > 60) { hamburgerDebugRows.shift(); }
                    var on = false; try { on = localStorage.getItem("wpcHamDebug") === "1"; } catch (z) {}
                    if (on) { console.log("[wpcHam]", row[0] + "ms", ev, info || ""); }
                } catch (z) {}
            };
            var wpcElPinNotReached = function(sec) {
                try {
                    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.elPinReachOff === 1) { return false; }
                    var top = sec.getBoundingClientRect().top;
                    if (!(top >= 1)) { return false; }
                    if (hamburgerArmReason !== "sticky-not-reached") { wpcHamburgerDebugLog("sticky-wait", "top=" + Math.round(top)); }
                    hamburgerArmReason = "sticky-not-reached";
                    return true;
                } catch (z) { return false; }
            };
            window.wpcHamburgerState = function() {
                try {
                    var tog = document.querySelector(".elementor-menu-toggle");
                    var dd = tog && tog.closest(".elementor-widget-nav-menu") ? tog.closest(".elementor-widget-nav-menu").querySelector(".elementor-nav-menu__container.elementor-nav-menu--dropdown") : null;
                    var sec = document.querySelector('[data-settings*="sticky"]');
                    var crit = document.getElementById("wpc-critical-css");
                    var r = dd ? dd.getBoundingClientRect() : null;
                    var jq = window.jQuery;
                    return {
                        standDown: !!hamburgerStoodDown, ownsOpen: hamburgerOwnedOpenToggle === tog && !!tog, armed: !!hamburgerArmedSection, why: hamburgerArmReason, queued: !!hamburgerQueuedToggle, cssLive: wpcHamburgerCssLive(),
                        toggle: tog ? { open: tog.classList.contains("elementor-active"), aria: tog.getAttribute("aria-expanded"),
                            handlers: jq && jq._data ? ((jq._data(tog, "events") || {}).click || []).length : -1 } : null,
                        panel: r ? { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), pos: getComputedStyle(dd).position, inline: String(dd.getAttribute("style") || "").slice(0, 160) } : null,
                        section: sec ? { pos: getComputedStyle(sec).position, active: /elementor-sticky--active/.test(sec.className), inline: String(sec.getAttribute("style") || "").slice(0, 120) } : null,
                        crit: crit ? { bytes: crit.textContent.length } : "NONE",
                        cfg: window.wpcDelayV3Cfg ? { timeout: window.wpcDelayV3Cfg.timeout, aggr: window.wpcDelayV3Cfg.aggr } : null,
                        log: hamburgerDebugRows.slice()
                    };
                } catch (z) { return { error: String(z) }; }
            };
            var wpcElStretchDir = function(tog) {
                try {
                    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.elStretchFitOff === 1) { return ""; }
                    var wrap = tog.closest(".elementor-widget-nav-menu");
                    if (!wrap || !wrap.classList.contains("elementor-nav-menu--stretch")) { return ""; }
                    var efc = window.elementorFrontendConfig;
                    var rtl = efc && typeof efc === "object" ? !!efc.is_rtl : String(document.documentElement.getAttribute("dir") || "").toLowerCase() === "rtl";
                    return rtl ? "right" : "left";
                } catch (z) { return ""; }
            };
            var wpcElStretchOurs = function(dd, dir) {
                var mine = dd.wpcElStretchWrote;
                if (mine === 0) { return false; }
                var w = dd.style.getPropertyValue("width"), side = dd.style.getPropertyValue(dir);
                if (mine ? (w === mine[0] && side === mine[1]) : (!w && !side)) { return true; }
                dd.wpcElStretchWrote = 0;
                wpcHamburgerDebugLog("stretch-foreign", "w=" + w + " " + dir + "=" + side);
                return false;
            };
            var wpcElStretchApply = function(dd, dir) {
                var containerWidth = document.documentElement.clientWidth;
                var rect = dd.getBoundingClientRect();
                var isFixed = getComputedStyle(dd).position === "fixed";
                var offset = isFixed ? 0 : rect.left + (window.pageXOffset || 0);
                if (!isFixed) {
                    if (dir === "right") { offset = containerWidth - (rect.width + offset); }
                    offset = -offset;
                }
                dd.style.setProperty("width", containerWidth + "px");
                dd.style.setProperty(dir, offset + "px");
                dd.wpcElStretchWrote = [dd.style.getPropertyValue("width"), dd.style.getPropertyValue(dir)];
                wpcHamburgerDebugLog("stretch", "w=" + containerWidth + " " + dir + "=" + dd.wpcElStretchWrote[1]);
            };
            var wpcSetHamburgerOpen = function(tog, dd, open) {
                tog.classList[open ? "add" : "remove"]("elementor-active");
                tog.setAttribute("aria-expanded", open ? "true" : "false");
                if (dd) {
                    dd.setAttribute("aria-hidden", open ? "false" : "true");
                    // close writes 0 EXACTLY like the native handler — removing the
                    // property lets height:var(--menu-height) fall back to auto on themes
                    // whose nav CSS is already live: a viewport-tall invisible white block
                    // that pushes the hero below the fold (James's screenshot).
                    dd.style.setProperty("--menu-height", open ? dd.scrollHeight + "px" : "0px");
                    if (!open) {
                        var closeStretchDir = wpcElStretchDir(tog), closeStretchOurs = closeStretchDir && wpcElStretchOurs(dd, closeStretchDir);
                        dd.style.removeProperty("position");
                        if (!closeStretchDir || closeStretchOurs) { dd.style.removeProperty("width"); }
                        if (closeStretchOurs) { dd.style.removeProperty(closeStretchDir); dd.wpcElStretchWrote = null; }
                        dd.style.removeProperty("top");
                        dd.style.removeProperty("margin-top");
                    }
                    // v7.22.07 — IDENTICAL TO DISABLED, structurally. On the disabled
                    // page the panel is plain in-flow; what stops it pushing the page is
                    // Elementor's sticky JS having made the header SECTION fixed
                    // (measured inline recipe: position:fixed; width:<vw>px; top:0;
                    // margins 0; z-index 2000). The .05/.06 attempts repositioned the
                    // PANEL with guessed/measured anchors and broke on real devices.
                    // The interim now replicates the page's OWN declared sticky — the
                    // ancestor carrying "sticky":"top" in data-settings — plus a spacer
                    // holding its flow height, and touches nothing else. Themes with no
                    // sticky declared push natively too: doing nothing IS parity there.
                    if (open) {
                        // v7.22.12 — ONE SETTLE-WATCH, NOTHING TRUSTED ONCE. Every earlier
                        // shape trusted a single early measurement forever and each one broke
                        // on James's device (.06 pinned a pre-CSS width, .10 pinned a narrow
                        // early-layout width, the sticky arm skipped a 0-height header and
                        // never retried = white collapse). While the menu is interim-open,
                        // every frame (~10s cap): (a) arm the declared sticky if not yet
                        // armed, (b) re-measure the panel's NATURAL width and re-pin the
                        // height-isolating absolute only when it changed, (c) re-write the
                        // height when scrollHeight changed. Stops the moment the menu closes.
                        var wpcArmStickyHeaderSection = function() {
                            if (hamburgerArmedSection) { return; }
                            var ancestorEl = tog, stickySection = null, dataSettings;
                            while (ancestorEl && ancestorEl !== document.body) {
                                dataSettings = ancestorEl.getAttribute && ancestorEl.getAttribute("data-settings");
                                if (dataSettings && dataSettings.indexOf('"sticky":"top"') !== -1) { stickySection = ancestorEl; break; }
                                ancestorEl = ancestorEl.parentElement;
                            }
                            if (stickySection && !wpcHamburgerCssLive()) { hamburgerArmReason = "css-not-live-waiting"; return; }
                            if (!stickySection) { hamburgerArmReason = "no-sticky-declared"; }
                            else if (getComputedStyle(stickySection).position === "fixed") { hamburgerArmReason = "native-already-fixed"; }
                            else if (!(stickySection.getBoundingClientRect().height > 0)) { hamburgerArmReason = "section-height-0-retrying"; }
                            else if (wpcElPinNotReached(stickySection)) { return; }
                            if (stickySection && getComputedStyle(stickySection).position !== "fixed"
                                && stickySection.getBoundingClientRect().height > 0) {
                                hamburgerArmReason = "armed"; wpcHamburgerDebugLog("sticky-armed", "h=" + Math.round(stickySection.getBoundingClientRect().height));
                                if (wpcHamPinPxOn()) { wpcHamPinArm(stickySection); } else {
                                    stickySection.style.setProperty("position", "fixed");
                                    stickySection.style.setProperty("top", "0px");
                                    stickySection.style.setProperty("left", "0px");
                                    stickySection.style.setProperty("width", "100%");
                                    stickySection.style.setProperty("margin-top", "0px");
                                    stickySection.style.setProperty("margin-bottom", "0px");
                                    stickySection.style.setProperty("z-index", "2000");
                                }
                                hamburgerArmedSection = stickySection;
                            }
                        };
                        var lastPanelWidth = -1, lastPanelHeight = -1, lastToggleBottom = -1;
                        var stretchDir = wpcElStretchDir(tog);
                        var wpcFitDropdownPanel = function() {
                            var stretchOurs = stretchDir && wpcElStretchOurs(dd, stretchDir);
                            dd.style.removeProperty("position");
                            if (!stretchDir || stretchOurs) { dd.style.removeProperty("width"); }
                            if (stretchOurs) { dd.style.removeProperty(stretchDir); dd.wpcElStretchWrote = null; }
                            dd.style.removeProperty("top");
                            dd.style.removeProperty("margin-top");
                            var panelWidth = Math.round(dd.getBoundingClientRect().width);
                            var panelHeight = dd.scrollHeight;
                            var toggleBottom = Math.round(tog.getBoundingClientRect().bottom);
                            if (panelWidth >= 10) {
                                // a flex container gives an abspos child the container's TOP as
                                // its static position, not "after the toggle": key the top on
                                // the toggle's bottom + the panel's own margin (native's rule),
                                // relative to the panel's containing block
                                var panelMarginTop = parseFloat(getComputedStyle(dd).marginTop) || 0;
                                var containingBlockRect = dd.offsetParent && dd.offsetParent.getBoundingClientRect ? dd.offsetParent.getBoundingClientRect() : null;
                                var containingBlockTop = containingBlockRect ? containingBlockRect.top : 0;
                                if (!stretchDir) { dd.style.setProperty("width", panelWidth + "px"); }
                                else if (stretchOurs) { wpcElStretchApply(dd, stretchDir); panelWidth = Math.round(dd.getBoundingClientRect().width); panelHeight = dd.scrollHeight; }
                                var panelTop = Math.round(toggleBottom + panelMarginTop - containingBlockTop);
                                dd.style.setProperty("top", panelTop + "px");
                                dd.style.setProperty("margin-top", "0px");
                                dd.style.setProperty("position", "absolute");
                                // FEEDBACK, not faith: a theme margin-top !important beats the
                                // inline 0 and lands the panel low; whatever the cause, measure
                                // where it actually landed and correct by the error once.
                                var landedRect = dd.getBoundingClientRect();
                                var landedTopError = Math.round(landedRect.top - (toggleBottom + panelMarginTop));
                                if (landedTopError) { dd.style.setProperty("top", (panelTop - landedTopError) + "px"); }
                                if (panelWidth !== lastPanelWidth || toggleBottom !== lastToggleBottom) { wpcHamburgerDebugLog("fit", "w=" + panelWidth + " top=" + panelTop + " err=" + landedTopError + " mt=" + panelMarginTop); }
                            }
                            if (panelHeight > 0 && panelHeight !== lastPanelHeight) {
                                dd.style.setProperty("--menu-height", panelHeight + "px");
                            }
                            lastPanelWidth = panelWidth; lastPanelHeight = panelHeight; lastToggleBottom = toggleBottom;
                        };
                        wpcArmStickyHeaderSection();
                        wpcFitDropdownPanel();
                        var remeasureFrames = 0;
                        var wpcRemeasureOpenMenuFrame = function() {
                            if (!tog.classList.contains("elementor-active")) { return; }
                            wpcArmStickyHeaderSection();
                            // cheap probe first: only unpin+re-measure when something moved
                            var probePanelWidth = Math.round(dd.getBoundingClientRect().width);
                            var probeToggleBottom = Math.round(tog.getBoundingClientRect().bottom);
                            if (probePanelWidth !== lastPanelWidth || probeToggleBottom !== lastToggleBottom || dd.scrollHeight !== lastPanelHeight || !hamburgerArmedSection) { wpcFitDropdownPanel(); }
                            if (remeasureFrames++ < 600 && typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcRemeasureOpenMenuFrame); }
                        };
                        if (typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcRemeasureOpenMenuFrame); }
                    } else if (hamburgerArmedSection) {
                        // v7.22.09/.10 — OWNERSHIP-AWARE RELEASE. Elementor's own sticky
                        // can initialize DURING our hold and overwrite these very inline
                        // props. Stripping then rips the fixed state out from under
                        // native = orphan spacer whitespace, and the next open lands on
                        // the corpse. If native took over, leave every property to it —
                        // and nothing more: the .09 re-measure nudge made native briefly
                        // unfix mid-frame (the split-second white cascade on close); with
                        // the panel height-isolated the measurement is honest from the
                        // start and no nudge is needed.
                        var nativeOwnsSticky = false;
                        try {
                            nativeOwnsSticky = (" " + hamburgerArmedSection.className + " ").indexOf(" elementor-sticky--active ") !== -1
                                || !!document.querySelector(".elementor-sticky__spacer");
                        } catch (z) {}
                        wpcHamburgerDebugLog("release", nativeOwnsSticky ? "native-owns-leave" : "restore-ours");
                        if (hamburgerPinValues) { wpcHamPinRelease(hamburgerArmedSection); } else if (!nativeOwnsSticky) {
                            hamburgerArmedSection.style.removeProperty("position");
                            hamburgerArmedSection.style.removeProperty("top");
                            hamburgerArmedSection.style.removeProperty("left");
                            hamburgerArmedSection.style.removeProperty("width");
                            hamburgerArmedSection.style.removeProperty("margin-top");
                            hamburgerArmedSection.style.removeProperty("margin-bottom");
                            hamburgerArmedSection.style.removeProperty("z-index");
                        }
                        hamburgerArmedSection = null;
                    }
                }
            };
            var wpcHamburgerClickHandler = function(ev) {
                try {
                    if (hamburgerStoodDown) { return; }
                    if (window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.elementorHeal !== undefined
                        && +window.wpcDelayV3Cfg.elementorHeal === 0) { wpcHamburgerDebugLog("kill-switch"); return; }
                    var tog = ev.target && ev.target.closest ? ev.target.closest(".elementor-menu-toggle") : null;
                    if (!tog) { return; }
                    var wrap = tog.closest(".elementor-widget-nav-menu");
                    var dd = wrap ? wrap.querySelector(".elementor-nav-menu__container.elementor-nav-menu--dropdown") : null;
                    var jq = window.jQuery;
                    if (jq && jq._data) {
                        var evd = jq._data(tog, "events");
                        if (evd && evd.click && evd.click.length) {
                            // the real handler owns it now — hand off from CLOSED
                            hamburgerStoodDown = 1; wpcHamburgerDebugLog("stand-down", "real handlers=" + evd.click.length + " ownedOpen=" + (hamburgerOwnedOpenToggle === tog));
                            document.removeEventListener("click", wpcHamburgerClickHandler, true);
                            if (hamburgerOwnedOpenToggle === tog && tog.classList.contains("elementor-active")) {
                                // we own an OPEN state: this tap means "close". Do the close
                                // ourselves and consume the click — letting the native handler
                                // also run would toggle from the closed state we just wrote
                                // and re-open the menu under the user's finger.
                                wpcSetHamburgerOpen(tog, dd, false);
                                hamburgerOwnedOpenToggle = null;
                                ev.preventDefault();
                                ev.stopPropagation();
                            }
                            return;
                        }
                    }
                    var open = !tog.classList.contains("elementor-active");
                    if (hamburgerQueuedToggle) {
                        // second tap while queued = the user changed their mind
                        hamburgerQueuedToggle = null; wpcHamburgerDebugLog("queue-cancel"); ev.preventDefault(); return;
                    }
                    if (open && !wpcHamburgerCssLive()) {
                        hamburgerQueuedToggle = tog; wpcHamburgerDebugLog("queue-open", "css not live");
                        var queuedFrames = 0;
                        var wpcAwaitCssLiveThenOpenMenu = function() {
                            if (hamburgerQueuedToggle !== tog) { return; }
                            if (wpcHamburgerCssLive() || queuedFrames > 600) {
                                hamburgerQueuedToggle = null;
                                if (hamburgerStoodDown) { return; }
                                wpcHamburgerDebugLog("queue-fire", queuedFrames + " frames");
                                wpcSetHamburgerOpen(tog, dd, true);
                                hamburgerOwnedOpenToggle = tog;
                                return;
                            }
                            queuedFrames++;
                            if (typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcAwaitCssLiveThenOpenMenu); }
                        };
                        if (typeof requestAnimationFrame === "function") { requestAnimationFrame(wpcAwaitCssLiveThenOpenMenu); }
                        ev.preventDefault();
                        return;
                    }
                    wpcHamburgerDebugLog(open ? "interim-open" : "interim-close", "dd=" + (dd ? 1 : 0));
                    wpcSetHamburgerOpen(tog, dd, open);
                    hamburgerOwnedOpenToggle = open ? tog : null;
                    ev.preventDefault();
                } catch (z) {}
            };
            document.addEventListener("click", wpcHamburgerClickHandler, true);
            // v7.22.34 — A TAP BEFORE THE LOADER IS A TAP, NOT A DEAD ONE. The head arm records a
            // pre-loader tap on the toggle as data-wpc-early-tap="<ms>:<index>" (a second tap clears
            // it). Consumed once at boot and fed to the responder's own path — queued to CSS-live
            // like any live tap — unless the intent expired: older than 1.5s, or the visitor has
            // scrolled since. An expired intent must never open a menu on its own.
            function wpcConsumePreLoaderTap() {
                try {
                    // data-wpc-tap34 is the pre-7.24.10 spelling of the same stamp, which HTML
                    // a third-party cache is still serving carries. Read both, clear both.
                    var el = document.documentElement,
                        v = el.getAttribute("data-wpc-early-tap") || el.getAttribute("data-wpc-tap34");
                    if (!v) { return; }
                    el.removeAttribute("data-wpc-early-tap");
                    el.removeAttribute("data-wpc-tap34");
                    var parts = String(v).split(":"), age = performance.now() - (+parts[0]), idx = +parts[1] || 0;
                    if (!(age >= 0 && age <= 1500) || (window.pageYOffset || 0) > 0) { wpcHamburgerDebugLog("pre-tap-dropped", Math.round(age) + "ms"); return; }
                    var tog = document.querySelectorAll(".elementor-menu-toggle")[idx] || document.querySelector(".elementor-menu-toggle");
                    if (!tog) { return; }
                    wpcHamburgerDebugLog("pre-tap-replay", Math.round(age) + "ms");
                    wpcHamburgerClickHandler({ target: tog, preventDefault: function() {}, stopPropagation: function() {} });
                } catch (z) {}
            }
            wpcConsumePreLoaderTap();
        } catch (z) {}
    })();
    function wpcElementorHealingDisabled() {
        return !!(window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.elementorHeal !== undefined
            && +window.wpcDelayV3Cfg.elementorHeal === 0);
    }
    function wpcArmElementorInitCapture() {
        try {
            if (wpcElementorHealingDisabled()) { return; }
            var reg = function(jq) {
                try {
                    if (!jq || !jq.fn || !jq.fn.on || !jq._data || jq.wpcInitCaptureArmed) { return; }
                    jq.wpcInitCaptureArmed = 1;
                    window.wpcElementorInitHandlersRan = window.wpcElementorInitHandlersRan || [];
                    window.wpcElementorInitHandlersMaybeMissed = window.wpcElementorInitHandlersMaybeMissed || [];
                    var grab = function(into) {
                        var ev = jq._data(window, "events");
                        var l = ev && ev["elementor/frontend/init"] ? ev["elementor/frontend/init"] : [];
                        for (var i = 0; i < l.length; i++) {
                            var h = l[i] && l[i].handler;
                            if (h && !h.wpcIsInitSentinel && into.indexOf(h) === -1) { into.push(h); }
                        }
                    };
                    // anything registered BEFORE the sentinel may already have run — if the
                    // fire is never observed, these are skipped rather than risked
                    grab(window.wpcElementorInitHandlersMaybeMissed);
                    var sent = function() {
                        window.wpcElementorInitSentinelFired = 1;
                        grab(window.wpcElementorInitHandlersRan);
                    };
                    sent.wpcIsInitSentinel = 1;
                    jq(window).on("elementor/frontend/init", sent);
                } catch (z) {}
            };
            wpcOnJqueryArrival("elementorInit", reg);
        } catch (z) {}
    }
    var wpcJqueryArrivalNames = [], wpcJqueryArrivalArms = [];
    function wpcOnJqueryArrival(name, arm) {
        try {
            if (wpcJqueryArrivalNames.indexOf(name) === -1) {
                wpcJqueryArrivalNames.push(name);
                wpcJqueryArrivalArms.push(arm);
            }
            if (window.jQuery) { arm(window.jQuery); }
            if (window.wpcJqueryAccessorTrapInstalled) { return; }
            var d = Object.getOwnPropertyDescriptor(window, "jQuery");
            if (!d || d.configurable) {
                window.wpcJqueryAccessorTrapInstalled = 1;
                var cur = window.jQuery;
                Object.defineProperty(window, "jQuery", {
                    configurable: true,
                    enumerable: true,
                    get: function() { return cur; },
                    set: function(v) {
                        cur = v;
                        for (var i = 0; i < wpcJqueryArrivalArms.length; i++) {
                            try { wpcJqueryArrivalArms[i](v); } catch (x) {}
                        }
                        try { if (c) { wpcInstallJqueryReadyTrap(); } } catch (x) {}
                    }
                });
            }
        } catch (z) {}
    }
    // v364 — OUR HEALS GET ONE DISPATCH DOOR. Two of our own dispatchers hit the same
    // widget (ladder + names-pass) and Elementor 3.3x's chunk system delivered a third,
    // native dispatch for the same late-registered handler (staging: binds 4 -> 3 until
    // this). The door: (a) one shared per-element stamp, (b) stand down entirely when a
    // NATIVE element_ready delivery is observed after our last registrar invocation —
    // modern Elementor replays queued elements itself once a handler registers, so if
    // that machinery is alive, healing on top of it is the double-bind.
    window.wpcWrapRunReadyTrigger = function() {
        try {
            var eh = window.elementorFrontend && elementorFrontend.elementsHandler;
            if (!eh || !eh.runReadyTrigger || eh.wpcReadyTriggerWrapped) { return; }
            eh.wpcReadyTriggerWrapped = 1;
            var orig = eh.runReadyTrigger;
            eh.runReadyTrigger = function(el) {
                if (!window.wpcInOwnReadyTrigger) { window.wpcNativeReadyTriggerAt = performance.now(); }
                return orig.apply(this, arguments);
            };
        } catch (z) {}
    };
    // verdict: 0 = dispatch now, -1 = stand down (Elementor's own replay is alive),
    // >0 = wait — we invoked registrars moments ago and modern Elementor replays
    // element_ready itself when the handler chunk resolves (staging: our dispatch at
    // 2494ms vs its replay at 2502ms — 8ms apart; a fixed settle is a race, an
    // observed-native rule is not). Legacy Elementor never replays, so a quiet 3s
    // after the invocation opens the door.
    window.wpcReadyTriggerVerdict = function() {
        try {
            window.wpcWrapRunReadyTrigger();
            if (window.wpcNativeReadyTriggerAt && window.wpcNativeReadyTriggerAt > (window.wpcRegistrarInvokedAt || 0)) { return -1; }
            if (window.wpcRegistrarInvokedAt) {
                var msSinceRegistrars = performance.now() - window.wpcRegistrarInvokedAt;
                if (msSinceRegistrars < 3000) { return Math.max(200, 3000 - msSinceRegistrars); }
            }
            return 0;
        } catch (z) { return 0; }
    };
    window.wpcRunReadyTriggerForElement = function(el) {
        try {
            if (wpcElementorHealingDisabled()) { return false; }
            window.wpcWrapRunReadyTrigger();
            if (!el || !window.elementorFrontend || !elementorFrontend.elementsHandler
                || !elementorFrontend.elementsHandler.runReadyTrigger) { return false; }
            if (el.getAttribute && el.getAttribute("data-wpc-ready-triggered") === "1") { return false; }
            if (window.wpcReadyTriggerVerdict() !== 0) { return false; }
            if (el.setAttribute) { el.setAttribute("data-wpc-ready-triggered", "1"); }
            window.wpcInOwnReadyTrigger = 1;
            try { elementorFrontend.elementsHandler.runReadyTrigger(el); } catch (z) {}
            window.wpcInOwnReadyTrigger = 0;
            return true;
        } catch (z) { window.wpcInOwnReadyTrigger = 0; return false; }
    };
    window.wpcFireMissedElementorInitHandlers = function() {
        var names = [];
        try {
            if (wpcElementorHealingDisabled()) { return names; }
            window.wpcWrapRunReadyTrigger();
            var jq = window.jQuery;
            if (!jq || !jq._data || !window.elementorFrontend || !elementorFrontend.hooks
                || !elementorFrontend.elementsHandler || !elementorFrontend.elementsHandler.runReadyTrigger) {
                return names;
            }
            window.wpcElementorInitHandlersRan = window.wpcElementorInitHandlersRan || [];
            window.wpcElementorInitHandlersMaybeMissed = window.wpcElementorInitHandlersMaybeMissed || [];
            var done = window.wpcElementorInitHandlersRan, maybe = window.wpcElementorInitHandlersMaybeMissed;
            var ev = jq._data(window, "events");
            var cur = ev && ev["elementor/frontend/init"] ? ev["elementor/frontend/init"].slice() : [];
            var late = [];
            for (var i = 0; i < cur.length; i++) {
                var h = cur[i] && cur[i].handler;
                if (!h || h.wpcIsInitSentinel || done.indexOf(h) !== -1) { continue; }
                // fire never observed by the sentinel: a pre-sentinel handler may have run
                // in a dispatch we could not see — never risk the double-bind
                if (!window.wpcElementorInitSentinelFired && maybe.indexOf(h) !== -1 && !wpcElementorProHandlerMissedInit(h)) { continue; }
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
            window.wpcRegistrarInvokedAt = performance.now();
            for (var j = 0; j < late.length; j++) {
                done.push(late[j]);
                try { late[j].call(window, evt); } catch (z) {}
            }
            elementorFrontend.hooks.addAction = oa;
        } catch (z) {}
        return names;
    };
    // v7.22.33 — THE ONE-SHOT IS MISSED WITHOUT ANY REPLAY, AND ONLY THE REPLAY HEALED IT.
    // jQuery (native defer) executes while readyState is already "interactive", so its ready
    // fires on a timer task that can land BETWEEN two deferred scripts: Elementor core inits
    // and fires elementor/frontend/init before Pro's frontend has registered for it (staging:
    // init 1510ms, Pro registers 1593ms — nav handler 0, sticky never, for the life of the
    // page). Our low fetchpriority on the family widens that gap. Every heal hung off the
    // replay, and a page whose gesture came before the loader had no replay. Now: the sentinel
    // arms at BOOT (a precise ran-set for every fire after boot, the accessor's trap install
    // still gated on the replay so kept jQuery keeps its native ready); a fire before boot is
    // marked, and Pro's own registrar is decidable by effect (elementorProFrontend.modules is
    // {} until it runs); diff-fire + the widget pass run once from the native load event
    // whenever no replay owns them.
    function wpcElementorProHandlerMissedInit(h) {
        try {
            return !!(window.wpcElementorInitFiredBeforeBoot && h && h.name === "bound onElementorFrontendInit"
                && window.elementorProFrontend && elementorProFrontend.modules
                && !Object.keys(elementorProFrontend.modules).length);
        } catch (z) { return false; }
    }
    function wpcMarkElementorPreBootFire() {
        try {
            if (window.wpcElementorInitFiredBeforeBoot === undefined && window.elementorFrontend
                && elementorFrontend.elements && elementorFrontend.elementsHandler) {
                window.wpcElementorInitFiredBeforeBoot = window.wpcElementorInitSentinelFired ? 0 : 1;
            }
        } catch (z) {}
    }
    function wpcRetriggerWidgetsByName(names) {
        var tries = 0;
        var go = function() {
            try {
                var wv = window.wpcReadyTriggerVerdict ? window.wpcReadyTriggerVerdict() : 0;
                if (wv === -1) { return; }
                if (wv > 0) { if (tries++ < 8) { setTimeout(go, wv); } return; }
                var seen = {};
                for (var k = 0; k < names.length; k++) {
                    var w = String(names[k]).split(".")[0];
                    if (!w || seen[w]) { continue; }
                    seen[w] = 1;
                    var els = document.querySelectorAll(".elementor-widget-" + w);
                    for (var m = 0; m < els.length; m++) {
                        try { window.wpcRunReadyTriggerForElement && window.wpcRunReadyTriggerForElement(els[m]); } catch (z) {}
                    }
                }
            } catch (z) {}
        };
        setTimeout(go, 300);
    }
    function wpcHealElementorAtBoot() {
        try {
            if (wpcElementorHealingDisabled()) { return; }
            wpcMarkElementorPreBootFire();
            wpcArmElementorInitCapture();
            var n = 0;
            var go = function() {
                try {
                    if (c) { return; }
                    wpcMarkElementorPreBootFire();
                    if (!window.elementorFrontend || !elementorFrontend.elements || !window.jQuery || !jQuery._data) {
                        if (n++ < 40) { setTimeout(go, 250); }
                        return;
                    }
                    var names = window.wpcFireMissedElementorInitHandlers ? window.wpcFireMissedElementorInitHandlers() : [];
                    if (names.length) { wpcRetriggerWidgetsByName(names); }
                } catch (z) {}
            };
            var start = function() { setTimeout(go, 250); };
            "complete" === document.readyState ? start() : y.call(window, "load", start, { once: true });
        } catch (z) {}
    }
    wpcOnJqueryArrival("readyEvent", wpcArmJqueryReadyEventOnInstance);
    wpcHealElementorAtBoot();
    var wpcPressNow = null, wpcMediaHeld = null, wpcMediaNow = 0;
    function wpcPressStart(t) {
        wpcPressNow = t;
        try {
            wpcEarlyTapStart(t, D);
        } finally {
            wpcPressNow = null;
        }
    }
    function wpcMediaPull() {
        var m = wpcMediaHeld;
        wpcMediaHeld = null;
        if (!m) return;
        wpcMediaNow++;
        try {
            m();
        } finally {
            wpcMediaNow--;
        }
    }
    var wpcPressLate = 0;
    function wpcPressLateRun(fn) {
        wpcPressLate++;
        try {
            fn();
        } finally {
            wpcPressLate--;
        }
    }
    function wpcMediaRelease() {
        wpcMediaHeld = function() {
            try {
                window.wpcFlushHeavyEmbeds && window.wpcFlushHeavyEmbeds();
            } catch (z) {}
            try {
                window.wpcRestoreHeldVideoSources && window.wpcRestoreHeldVideoSources();
            } catch (z) {}
        };
        if (wpcPressNow && !wpcPrePaint) {
            wpcAfterInput(wpcMediaPull, wpcPressNow, 1);
            return;
        }
        if (wpcPressLate && !wpcPrePaint) {
            wpcAfterPaint(wpcMediaPull);
            return;
        }
        wpcPaintFirst(wpcMediaPull) || wpcMediaPull();
    }
    function wpcNoTransitionRelease() {
        if (wpcPressNow && !wpcPrePaint && wpcReleaseOn("releaseNoTransition")) {
            wpcNoTransitionDue = 1;
            wpcAfterInput(wpcNoTransitionOn, wpcPressNow);
            return;
        }
        if (wpcPressLate && !wpcPrePaint && wpcReleaseOn("releaseNoTransition")) {
            wpcNoTransitionDue = 1;
            wpcAfterPaint(function() {
                wpcNoTransitionDue && wpcNoTransitionOn();
            });
            return;
        }
        wpcNoTransitionOn();
    }
    function D() {
        if (window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r) {
            window.wpcJqueryDeferMarker.cb = D;
            return;
        }
        // Held video sources go back BEFORE the first delayed script is appended, whichever
        // trigger got here (gesture, timer, scroll, wpcStartDelayed): a builder script that sizes
        // or reveals a video from its metadata must never find it empty. webdesign4u 2026-09-24:
        // the Divi 4 hero's source was held for a gesture while the 4 s timer replayed Divi, so
        // desktop kept the grey preload box forever and mobile sized the video from an empty
        // 300x150 box (half-covered hero), and the first video frame came ~6 s late.
        c ? e("Loading already started, ignoring duplicate call") : (O && (clearTimeout(O),
        O = null), c = !0, wpcEv.on && (wpcEv.re = 1), window.__wpcParkedSrcReleased = 1, wpcMediaRelease(), window.__wpcParkedSrcQueue && window.__wpcParkedSrcQueue.length && wpcMediaPull(), window.wpcFlushParkedScriptSrcs && window.wpcFlushParkedScriptSrcs(),
        e("Triggered resource loading"), wpcMenusSnapshot(), wpcNoTransitionRelease(), wpcSnapshotElementorInitHandlers(), wpcArmElementorInitCapture(), wpcArmJqueryReadyEventOnInstance(window.jQuery), wpcMarkJqueryReadyHandlersRan(window.jQuery), wpcInstallJqueryReadyTrap(), wpcFlushDomContentLoadedQueue(), wpcRunJqueryReadyAtReplayStart(), async function() {
            if (i) e("Already loading resources, ignoring duplicate call"); else {
                i = !0;
                try {
                    await wpcRegistryBodies();
                    wpcEarlyBusy && await Promise.race([ wpcEarlyBusy, wpcEarlyPause(4e3) ]);
                    wpcEv.on = 0;
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
                    for (var r = R(wpcScriptRegistry), n = 0; n < r.asyncFire.length; n++) wpcSidOf && (r.asyncFire[n].__wpcRan = 1), S(r.asyncFire[n]);
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
                        wpcJumpReq && await wpcJumpRun();
                        var d = r.classicBatches[l];
                        if (wpcSidOf) {
                            d = d.filter((function(x) {
                                return !x.__wpcJump;
                            }));
                            if (!d.length) continue;
                            d.forEach((function(x) {
                                x.__wpcRan = 1;
                            }));
                        }
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
    var wpcEvDeny = /^(?:load|DOMContentLoaded|readystatechange|pageshow|pagehide|visibilitychange|error|abort|scroll|resize|orientationchange|focus|blur|focusin|focusout|click|dblclick|mouse\w*|pointer\w*|touch\w*|key\w*|wheel|input|change|submit|reset|select|wpc-.*)$/i;
    function wpcEvRecord(el, ev, data, jq, only) {
        try {
            var type = String("string" == typeof ev ? ev : ev && ev.type || "").split(".")[0];
            if (!type || wpcEvDeny.test(type) || "ready" === type && el === document) return;
            if (!jq && ev.isTrusted !== !1) return;
            if (wpcEv.log.length >= 40) {
                wpcEarlyRc().cap++;
                return;
            }
            wpcEarlyRc().rec++;
            wpcEv.log.push({
                id: ++wpcEv.n,
                el: el,
                type: type,
                ev: jq ? null : ev,
                data: [].concat(null == data ? [] : data),
                bubbles: jq ? !only : !!ev.bubbles,
                att: el === window || el === document || !!el && 1 === el.nodeType && el.isConnected !== !1,
                jq: !!jq
            });
        } catch (z) {}
    }
    function wpcEvDispatch(el, ev) {
        var res = h.call(el, ev);
        wpcEv.on && wpcEvRecord(el, ev);
        return res;
    }
    function wpcEvKey(el) {
        var id = wpcEvIds.get(el);
        id || wpcEvIds.set(el, id = ++wpcEvSeq);
        return id;
    }
    function wpcEvDeliver(key, tag, rec, call) {
        var seen = wpcEv.seen.get(key);
        seen || wpcEv.seen.set(key, seen = {});
        if (seen[tag + rec.id]) return;
        seen[tag + rec.id] = 1;
        wpcEvPending.push({
            id: rec.id,
            n: ++wpcEvSeq,
            call: call
        });
        wpcEvQueued || (wpcEvQueued = !0, setTimeout(wpcEvFlush, 0));
    }
    function wpcEvFlush() {
        wpcEvQueued = !1;
        var batch = wpcEvPending.sort((function(a, b) {
            return a.id - b.id || a.n - b.n;
        }));
        wpcEvPending = [];
        batch.forEach((function(x) {
            try {
                x.call();
            } catch (z) {
                e("event redelivery error:", z);
            }
        }));
    }
    function wpcEvClone(ev, target, current) {
        var c;
        try {
            c = "detail" in ev ? new CustomEvent(ev.type, {
                detail: ev.detail,
                bubbles: !!ev.bubbles,
                cancelable: !!ev.cancelable
            }) : new Event(ev.type, {
                bubbles: !!ev.bubbles,
                cancelable: !!ev.cancelable
            });
            Object.defineProperty(c, "target", {
                value: target,
                configurable: !0
            });
            Object.defineProperty(c, "currentTarget", {
                value: current,
                configurable: !0
            });
            Object.defineProperty(c, "eventPhase", {
                value: target === current ? 2 : 3,
                configurable: !0
            });
        } catch (z) {
            return ev;
        }
        return c;
    }
    function wpcEvClose() {
        if (!wpcEv.on && !wpcEv.re && !wpcEvUnwrap.length) return;
        wpcEv.on = wpcEv.re = 0;
        wpcEv.closed = 1;
        wpcEv.log.length = 0;
        wpcEv.had = new WeakMap;
        wpcEv.seen = new WeakMap;
        wpcEvUnwrap.forEach((function(u) {
            try {
                u();
            } catch (z) {}
        }));
        wpcEvUnwrap = [];
    }
    function wpcEvOnAdd(el, type, fn) {
        try {
            if (wpcEv.jqAdd) return;
            if (!fn || "object" != typeof fn && "function" != typeof fn) return;
            var mine = wpcEv.had.get(fn), first = !0;
            mine || wpcEv.had.set(fn, mine = []);
            mine.forEach((function(m) {
                m.el === el && m.type === type && (first = !1);
            }));
            first && mine.push({
                el: el,
                type: type
            });
            if (!first || !wpcEv.re || !wpcEv.log.length) return;
            wpcEv.log.forEach((function(rec) {
                if (rec.jq || rec.type !== type) return;
                if (!(rec.el === el || rec.bubbles && (el === window ? rec.att : "function" == typeof el.contains && el.contains(rec.el)))) return;
                wpcEvDeliver(fn, "n" + wpcEvKey(el) + "|", rec, (function() {
                    var evt = wpcEvClone(rec.ev, rec.el, el);
                    "function" == typeof fn ? fn.call(el, evt) : fn.handleEvent && fn.handleEvent(evt);
                }));
            }));
        } catch (z) {}
    }
    function wpcEvJqAdd(jq, elem, types, handler, data, selector) {
        String(types).split(/\s+/).forEach((function(name) {
            var type = name.split(".")[0];
            if (!type) return;
            wpcEv.log.forEach((function(rec) {
                if (rec.type !== type) return;
                var at = elem;
                if (selector) {
                    if (!(rec.bubbles && rec.el !== elem && elem.contains && elem.contains(rec.el))) return;
                    for (at = rec.el; at && at !== elem; at = at.parentNode) {
                        if (1 === at.nodeType && at.matches && at.matches(selector)) break;
                    }
                    if (!at || at === elem) return;
                } else if (!(rec.el === elem || rec.bubbles && rec.el !== elem && elem.contains && elem.contains(rec.el))) return;
                wpcEvDeliver(handler, "j" + (selector || "") + "|" + wpcEvKey(elem) + "|", rec, (function() {
                    var evt;
                    try {
                        evt = jq.Event ? jq.Event(rec.jq ? type : wpcEvClone(rec.ev, rec.el, at)) : {
                            type: type
                        };
                    } catch (z) {
                        evt = {
                            type: type
                        };
                    }
                    evt.target = rec.el;
                    evt.currentTarget = at;
                    evt.data = data;
                    handler.apply(at, [ evt ].concat(rec.data));
                }));
            }));
        }));
    }
    function wpcEvArmJq(jq) {
        try {
            var ev = jq && jq.event;
            if (wpcEv.closed || !ev || ev.__wpcEv || "function" != typeof ev.trigger || "function" != typeof ev.add) return;
            ev.__wpcEv = 1;
            var trig = ev.trigger, add = ev.add, wrapTrig, wrapAdd, wrapReady;
            ev.trigger = wrapTrig = function(event, data, elem, only) {
                wpcEv.on && wpcEvRecord(elem || document, event, data, !0, only);
                return trig.apply(this, arguments);
            };
            ev.add = wrapAdd = function(elem, types, handler, data, selector) {
                wpcEarlyLearn && wpcEarlyLearn.push(wpcEarlyFrames(2));
                wpcEv.jqAdd++;
                try {
                    var res = add.apply(this, arguments);
                } finally {
                    wpcEv.jqAdd--;
                }
                wpcEarlyStep && wpcEarlyNote("jquery");
                !wpcEarlyLearn && wpcEarlyLane && wpcEarlyBoundJq.length < 60 && /(^|\s)click(?=\.|\s|$)/.test(String(types)) && (wpcEarlyPlumb || wpcEarlyLearnJq(jq), wpcEarlyLaneBinds()) && wpcEarlyBoundJq.push([ elem, "string" == typeof selector ? selector : "" ]);
                wpcEv.re && wpcEv.log.length && "function" == typeof handler && wpcEvJqAdd(jq, elem, types, handler, data, selector);
                return res;
            };
            var ready = jq.fn && jq.fn.ready;
            if ("function" == typeof ready) {
                jq.fn.ready = wrapReady = function() {
                    wpcEarlyStep && wpcEarlyNote("ready");
                    return ready.apply(this, arguments);
                };
            }
            wpcEvUnwrap.push((function() {
                ev.trigger === wrapTrig && (ev.trigger = trig);
                ev.add === wrapAdd && (ev.add = add);
                wrapReady && jq.fn.ready === wrapReady && (jq.fn.ready = ready);
                delete ev.__wpcEv;
            }));
        } catch (z) {}
    }
    var wpcEarlyBusy = null;
    var wpcEarlyStep = null, wpcEarlyWatched = [], wpcEntryOf = null;
    var wpcEarlyBoundEls = "function" == typeof WeakSet ? new WeakSet : null, wpcEarlyBoundJq = [], wpcEarlyLane = null, wpcEarlyPlumb = null, wpcEarlyLearn = null;
    function wpcEarlyFrames(skip) {
        var out = [], lim = Error.stackTraceLimit, raised = "number" == typeof lim;
        try {
            raised && (Error.stackTraceLimit = 60);
            var lines = String(new Error().stack).split("\n");
            for (var i = 0, m; i < lines.length; i++) (m = /([^\s()@]+:\d+:\d+)\)?\s*$/.exec(lines[i])) && out.push(m[1]);
        } catch (z) {} finally {
            if (raised) try { Error.stackTraceLimit = lim; } catch (z) {}
        }
        return out.slice(skip);
    }
    function wpcEarlyPath(url) {
        try {
            return new URL(url, location.href).pathname;
        } catch (z) {
            return url;
        }
    }
    function wpcEarlyLaneAdd(entry) {
        var ids = [], tag = "";
        if (entry.src) {
            ids.push(wpcEarlyPath(wpcJsDecode(entry.src, !!entry.encoded)));
        } else {
            tag = "wpc-early-" + String(entry.id || "").replace(/[^\w-]/g, "_") + ".js";
            ids.push(tag, wpcEarlyPath(tag));
        }
        wpcEarlyLane = (wpcEarlyLane || []).concat(ids);
        return tag;
    }
    function wpcEarlyIsLane(pos) {
        var url = pos.replace(/:\d+:\d+$/, "");
        return wpcEarlyLane.indexOf(url) !== -1 || wpcEarlyLane.indexOf(wpcEarlyPath(url)) !== -1;
    }
    function wpcEarlyLaneBinds() {
        var fr = wpcEarlyFrames(3), i = 0;
        while (i < fr.length && wpcEarlyPlumb && wpcEarlyPlumb[fr[i]] === 1) i++;
        return i < fr.length && wpcEarlyIsLane(fr[i]);
    }
    function wpcEarlyLearnJq(jq) {
        var plumb = {}, el = document.createElement("i"), noop = function() {}, tail = 0, forms = [ function(o) {
            tail = wpcEarlyFrames(1).length, o.on("wpcprobe", noop);
        }, function(o) {
            tail = wpcEarlyFrames(1).length, o.on("wpcprobe", "i", noop);
        }, function(o) {
            tail = wpcEarlyFrames(1).length, o.on({
                wpcprobe: noop
            });
        }, function(o) {
            tail = wpcEarlyFrames(1).length, o.one("wpcprobe", noop);
        }, function(o) {
            tail = wpcEarlyFrames(1).length, o.bind("wpcprobe", noop);
        }, function(o) {
            tail = wpcEarlyFrames(1).length, o.delegate("i", "wpcprobe", noop);
        }, function(o) {
            tail = wpcEarlyFrames(1).length, o.click(noop);
        } ];
        wpcEarlyPlumb = plumb;
        forms.forEach((function(form) {
            wpcEarlyLearn = [];
            try {
                form(jq(el));
            } catch (z) {}
            var got = wpcEarlyLearn;
            wpcEarlyLearn = null;
            got.forEach((function(fr) {
                fr.slice(0, fr.length - tail).forEach((function(pos) {
                    plumb[pos] = 1;
                }));
            }));
        }));
        try {
            jq(el).off();
        } catch (z) {}
    }
    function wpcEarlyRc() {
        return window.wpcEarlyReceipt = window.wpcEarlyReceipt || {
            ran: 0,
            back: 0,
            kept: 0,
            rec: 0,
            cap: 0
        };
    }
    function wpcEarlyNote(kind) {
        var st = wpcEarlyStep;
        if (!st || st.added || wpcEarlyLearn) return;
        try {
            var cs = document.currentScript;
            (cs && wpcEntryOf && wpcEntryOf.get(cs) === st.entry || st.file && String(new Error().stack).toLowerCase().indexOf(st.file) !== -1) && (st.added = kind);
        } catch (z) {}
    }
    function wpcEarlyWatch(on) {
        if (!on) {
            wpcEarlyWatched.forEach((function(w) {
                w[0][w[1]] === w[3] && (w[0][w[1]] = w[2]);
            }));
            wpcEarlyWatched = [];
            return;
        }
        if (wpcEarlyWatched.length) return;
        [ [ window, "setTimeout" ], [ window, "setInterval" ], [ window, "requestAnimationFrame" ], [ window, "requestIdleCallback" ], [ window.MutationObserver && MutationObserver.prototype, "observe" ], [ window.IntersectionObserver && IntersectionObserver.prototype, "observe" ], [ window.ResizeObserver && ResizeObserver.prototype, "observe" ] ].forEach((function(t) {
            var host = t[0], name = t[1], orig = host && host[name];
            if ("function" != typeof orig) return;
            var wrap = function() {
                wpcEarlyNote(name);
                return orig.apply(this, arguments);
            };
            host[name] = wrap;
            wpcEarlyWatched.push([ host, name, orig, wrap ]);
        }));
    }
    function wpcEarlyMark() {
        return {
            DOMContentLoaded: p.DOMContentLoaded.slice(),
            readystatechange: p.readystatechange.slice(),
            load: p.load.slice()
        };
    }
    function wpcEarlyOwnHandle(q, type) {
        try {
            var jq = window.jQuery;
            if (!jq) return !1;
            if ("function" != typeof jq._data) return "load" === type && (q.target === window || q.target === document);
            return "function" == typeof q.listener && [ jq._data(window, "handle"), jq._data(document, "handle") ].indexOf(q.listener) !== -1;
        } catch (z) {
            return !1;
        }
    }
    function wpcEarlyLoaded() {
        try {
            var nav = performance.getEntriesByType("navigation")[0];
            if (nav) return nav.loadEventEnd > 0;
        } catch (z) {}
        return wpcRealRS() === "complete";
    }
    function wpcEarlyCall(x) {
        try {
            if ("load" === x[0]) {
                wpcFireLoad(x[1]);
            } else if ("function" == typeof x[1].listener) {
                x[1].listener.call(x[1].target, new Event(x[0]));
            } else if (x[1].listener && "function" == typeof x[1].listener.handleEvent) {
                x[1].listener.handleEvent(new Event(x[0]));
            }
        } catch (z) {
            e("early listener deferred to replay end:", z);
            p[x[0]].push(x[1]);
        }
    }
    function wpcEarlyFlush(mark, jump) {
        if (c && !jump) return;
        var due = [], later = [];
        [ "DOMContentLoaded", "readystatechange", "load" ].forEach((function(type) {
            var keep = [];
            p[type].forEach((function(q) {
                mark[type].indexOf(q) === -1 && !("load" === type && "held" in q) && !wpcEarlyOwnHandle(q, type) ? ("load" === type && !wpcEarlyLoaded() ? later : due).push([ type, q ]) : keep.push(q);
            }));
            p[type] = keep;
        }));
        due.forEach(wpcEarlyCall);
        later.length && y.call(window, "load", (function() {
            setTimeout((function() {
                later.forEach(wpcEarlyCall);
            }), 0);
        }), {
            once: !0
        });
    }
    function wpcEarlyPause(ms) {
        return new Promise((function(done) {
            setTimeout(done, ms);
        }));
    }
    function wpcEarlyFile(entry) {
        try {
            return new URL(wpcJsDecode(entry.src, !!entry.encoded), location.href).pathname.split("/").pop().toLowerCase();
        } catch (z) {
            return "";
        }
    }
    function wpcEarlyBack(orig, entry) {
        var reg = window.wpcScriptRegistry;
        if (!Array.isArray(reg) || reg.indexOf(entry) !== -1) return;
        for (var oi = orig.indexOf(entry), at = 0, i = 0; i < reg.length; i++) orig.indexOf(reg[i]) < oi && at++;
        reg.splice(at, 0, entry);
    }
    async function wpcEarlyRun(ids) {
        await wpcRegistryBodies();
        var reg = window.wpcScriptRegistry, list = [];
        if (!Array.isArray(reg)) return;
        for (var i = 0; i < ids.length; i++) {
            var hit = null;
            for (var j = 0; j < reg.length; j++) {
                if (reg[j] && reg[j].id === ids[i]) {
                    hit = reg[j];
                    break;
                }
            }
            if (!hit || hit.ext || hit.io) return;
            list.push(hit);
        }
        var orig = reg.slice(), step = null, onErr = function(ev) {
            try {
                if (!step) return;
                var t = ev && ev.target;
                if (t && t !== window && "SCRIPT" === t.tagName) {
                    t.getAttribute("data-wpc-early") && (t.getAttribute("data-wpc-fb") || !wpcOriginOf(t.src)) && (step.threw = 1);
                    return;
                }
                if (!ev || "string" != typeof ev.message) return;
                var file = String(ev.filename || "").split(/[?#]/)[0].split("/").pop().toLowerCase();
                (step.inline ? step.live : file && file === step.file) && (step.threw = 1);
            } catch (z) {}
        };
        wpcEv.on = 1;
        y.call(window, "error", onErr, !0);
        try {
            for (var k = 0; k < list.length; k++) {
                if (c) return;
                var cur = window.wpcScriptRegistry, at = Array.isArray(cur) ? cur.indexOf(list[k]) : -1;
                if (at < 0) continue;
                cur.splice(at, 1);
                var mark = wpcEarlyMark(), pending;
                step = {
                    inline: !list[k].src,
                    live: !0,
                    file: wpcEarlyFile(list[k]),
                    threw: 0,
                    added: "",
                    entry: list[k]
                };
                wpcEarlyStep = step;
                wpcEarlyWatch(!0);
                wpcEarlyRc().ran++;
                window.__wpcEarlyBuild = list[k].src ? 1 : 0;
                try {
                    pending = S(list[k], !0);
                } finally {
                    window.__wpcEarlyBuild = 0;
                }
                await pending;
                step.live = !1;
                await wpcEarlyPause(20);
                wpcEarlyWatch(!1);
                wpcEarlyStep = null;
                if (step.threw) {
                    step.added ? wpcEarlyRc().kept++ : (wpcEarlyRc().back++, wpcEarlyBack(orig, list[k]));
                    return;
                }
                wpcEarlyFlush(mark);
            }
            return list.length;
        } finally {
            f.call(window, "error", onErr, !0);
            wpcEarlyWatch(!1);
            wpcEarlyStep = null;
            step = null;
        }
    }
    function wpcEarlyTapEntry(target) {
        var map = window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.menuEarlySel, reg = window.wpcScriptRegistry;
        if (!map || "object" != typeof map || !Array.isArray(reg) || !target || "function" != typeof target.closest) return null;
        for (var sel in map) {
            if (!Object.prototype.hasOwnProperty.call(map, sel) || !map[sel] || !Array.isArray(map[sel].i) || !map[sel].i.length) continue;
            try {
                if (!target.closest(sel)) continue;
            } catch (z) {
                continue;
            }
            var left = map[sel].i.filter((function(id) {
                return reg.some((function(r) {
                    return r && r.id === id;
                }));
            }));
            return {
                kind: map[sel].k,
                ran: !left.length && wpcEarlyRc().ran > 0,
                left: left
            };
        }
        return null;
    }
    function wpcEarlyBoundTap(target) {
        var ctl = null;
        try {
            ctl = target.closest("a,button,[role=button]");
        } catch (z) {}
        if (wpcEarlyBoundEls && (wpcEarlyBoundEls.has(target) || ctl && wpcEarlyBoundEls.has(ctl))) return !0;
        for (var i = 0; i < wpcEarlyBoundJq.length; i++) {
            var elem = wpcEarlyBoundJq[i][0], sel = wpcEarlyBoundJq[i][1];
            if (!elem) continue;
            try {
                if (!sel) {
                    if (elem === target || ctl && elem === ctl) return !0;
                } else {
                    var hit = target.closest(sel);
                    if (hit && elem.contains && elem.contains(hit)) return !0;
                }
            } catch (z) {}
        }
        return !1;
    }
    function wpcEarlyAnswered(target) {
        var hit = wpcEarlyTapEntry(target);
        return !!hit && hit.ran || !!target && "function" == typeof target.closest && wpcEarlyBoundTap(target);
    }
    function wpcLaneJqOpen() {
        var cfg = window.wpcDelayV3Cfg, jq = window.jQuery;
        return !!cfg && 1 === +cfg.tapAfterChain && 1 === +cfg.menuLive && 1 === +cfg.menuJq && "function" == typeof jq && !jq.readyWait;
    }
    function wpcEarlyAfterChain(max) {
        return new Promise((function(done) {
            var waited = 0, pace = window.wpcDelayV3Cfg && 1 === +window.wpcDelayV3Cfg.menuLive ? 10 : 50, step = function() {
                if (c) return done(!1);
                if (!(window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r) || wpcLaneJqOpen()) return done(!0);
                if ((waited += pace) > max) return done(!1);
                wpcNativeTimeout.call(window, step, pace);
            };
            step();
        }));
    }
    var wpcSidOf = function() {
        var cfg = window.wpcDelayV3Cfg;
        return !!cfg && 1 === +cfg.menuPreTap && !!cfg.menuEarlySel && "object" == typeof cfg.menuEarlySel && "function" == typeof WeakMap;
    }() ? new WeakMap : null, wpcReadyFrom = wpcSidOf ? new WeakMap : null, wpcJumpReq = null, wpcJumpUsed = 0;
    function wpcJumpEntry(id) {
        var reg = window.wpcScriptRegistry;
        if (Array.isArray(reg)) for (var j = 0; j < reg.length; j++) if (reg[j] && reg[j].id === id) return reg[j];
        return null;
    }
    function wpcJumpReady(q) {
        var mine = [];
        for (var k = m.length - 1; k >= 0; k--) {
            var id = "function" == typeof m[k] ? wpcReadyFrom.get(m[k]) : "";
            id && q.ids.indexOf(id) !== -1 && mine.unshift(m.splice(k, 1)[0]);
        }
        mine.forEach((function(fn) {
            try {
                fn(g || window.jQuery);
            } catch (x) {
                e("Error in jQuery ready callback:", x);
            }
        }));
        wpcEarlyRc().jumpCb = (wpcEarlyRc().jumpCb || 0) + mine.length;
        return mine.length;
    }
    function wpcJumpSettle(q) {
        var reg = window.wpcScriptRegistry, out = 0;
        q.ids.forEach((function(id) {
            var x = wpcJumpEntry(id);
            x && (x.__wpcRan || x.__wpcJump) && Array.isArray(reg) && (reg.splice(reg.indexOf(x), 1), out++);
        }));
        out && wpcEarlyRc().ran++;
    }
    function wpcJumpTap(ev) {
        try {
            if (wpcJumpUsed || !ev || !1 === ev.isTrusted || window.wpcScriptsLoadedAt || !c && wpcRealRS() !== "loading" && !(window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r)) return;
            var t = ev.target;
            if (!t || !t.isConnected || "function" != typeof t.closest || t.closest(".elementor-menu-toggle") || wpcMenuLiveTap(t)) return;
            var hit = wpcEarlyTapEntry(t);
            if (!hit || "menu_toggle" !== hit.kind || !hit.left.length) return;
            wpcJumpUsed = 1;
            wpcEarlyRc().jump = c ? "chain" : "loading";
            var q = wpcJumpReq = {
                t: t,
                ids: hit.left.slice(),
                y: window.pageYOffset || 0
            };
            c && (wpcJumpReady(q), wpcJumpSettle(q));
        } catch (z) {}
    }
    async function wpcJumpRun() {
        var q = wpcJumpReq, ran = 0;
        wpcJumpReq = null;
        if (!q || !q.t.isConnected || (window.pageYOffset || 0) !== q.y) return;
        try {
            for (var k = 0; k < q.ids.length; k++) {
                var x = wpcJumpEntry(q.ids[k]);
                if (!x || x.__wpcRan || x.__wpcJump || x.ext || x.io || n(x) || "text/javascript" !== (x.type || "text/javascript").toLowerCase()) continue;
                var y2 = {}, at = {}, file = x.src ? wpcEarlyFile(x) : "", live = !0, threw = 0, mark = wpcEarlyMark(), onErr = function(ev) {
                    if (!ev || "string" != typeof ev.message) return;
                    var got = String(ev.filename || "").split(/[?#]/)[0].split("/").pop().toLowerCase();
                    (file ? got && got === file : live) && (threw = 1);
                };
                for (var a in x) y2[a] = x[a];
                for (a in x.attributes || {}) at[a] = x.attributes[a];
                at["data-wpc-jump"] = "1";
                y2.attributes = at;
                x.__wpcJump = 1;
                y.call(window, "error", onErr, !0);
                try {
                    await S(y2);
                    live = !1;
                    await wpcEarlyPause(20);
                } finally {
                    f.call(window, "error", onErr, !0);
                }
                if (threw) {
                    delete x.__wpcJump;
                    wpcEarlyRc().jumpBack = 1;
                    return;
                }
                wpcEarlyFlush(mark, 1);
                ran++;
            }
            ran += wpcJumpReady(q);
            wpcJumpSettle(q);
            wpcEarlyRc().jumpRan = ran;
            ran && (window.pageYOffset || 0) === q.y && "function" == typeof window.wpcEarlyTapReplay && window.wpcEarlyTapReplay(q.t);
        } catch (z) {
            e("menu tap jump error:", z);
        }
    }
    function wpcEarlyTapStart(ev, start) {
        var held = null;
        try {
            var tapCfg = window.wpcDelayV3Cfg, after = !!tapCfg && 1 === +tapCfg.tapAfterChain, joins = after || !!tapCfg && 1 === +tapCfg.menuLive && 1 === +tapCfg.menuJq;
            var wpcPreTapMenu = !1;
            if (after && ev && "wpc-bgl255" === ev.type) {
                var seen = window.wpcPreTapEv || window.wpcPreBootClick && window.wpcPreBootClick.ev;
                if (seen && seen.target && seen.target.isConnected) ev = seen;
            } else if (ev && "wpc-bgl255" === ev.type && tapCfg && 1 === +tapCfg.menuPreTap) {
                var wpcPreBoot = window.wpcPreBootClick, wpcPreTap = window.wpcPreTapEv || (wpcPreBoot && wpcPreBoot.ev && performance.now() - wpcPreBoot.t <= 1500 && (window.pageYOffset || 0) === wpcPreBoot.y ? wpcPreBoot.ev : null), wpcPreHit = null;
                try {
                    wpcPreHit = wpcPreTap && wpcPreTap.target && wpcPreTap.target.isConnected && !(wpcPreTap.target.closest && wpcPreTap.target.closest(".elementor-menu-toggle")) && !wpcMenuLiveTap(wpcPreTap.target) ? wpcEarlyTapEntry(wpcPreTap.target) : null;
                } catch (z) {
                    wpcPreHit = null;
                }
                if (wpcPreHit && "menu_toggle" === wpcPreHit.kind && wpcPreHit.left.length) {
                    ev = wpcPreTap;
                    wpcPreTapMenu = !0;
                }
            }
            var flying = wpcEarlyBusy, shut = !!(window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r) && !wpcLaneJqOpen();
            if (!c && (!flying || tapCfg && (1 === +tapCfg.tapFirst || joins)) && ev && /^(?:pointerdown|touchstart|click)$/.test(ev.type) && wpcRealRS() !== "loading" && (!shut || after || wpcPreTapMenu)) {
                var hit = wpcEarlyTapEntry(ev.target);
                if (hit && "menu_toggle" === hit.kind && (hit.left.length || joins && flying)) {
                    wpcEarlyRc().tap = (wpcEarlyRc().tap || 0) + 1;
                    var fire = function() {
                        var busy = shut ? wpcEarlyBusy : flying, now = shut ? wpcEarlyTapEntry(ev.target) : hit;
                        if (!now || "menu_toggle" !== now.kind) return null;
                        if (!now.left.length && !busy) {
                            now.ran && "function" == typeof window.wpcEarlyTapReplay && window.wpcEarlyTapReplay(ev.target);
                            return null;
                        }
                        if (busy) {
                            return Promise.race([ busy.then((function() {
                                var again = wpcEarlyTapEntry(ev.target);
                                again && again.ran && "function" == typeof window.wpcEarlyTapReplay && window.wpcEarlyTapReplay(ev.target);
                            })), wpcEarlyPause(1500) ]);
                        }
                        wpcEarlyBusy = wpcEarlyRun(now.left.slice()).catch((function() {})).then((function(ran) {
                            wpcEarlyBusy = null;
                            ran && "function" == typeof window.wpcEarlyTapReplay && window.wpcEarlyTapReplay(ev.target);
                        }));
                        return Promise.race([ wpcEarlyBusy, wpcEarlyPause(1500) ]);
                    };
                    held = shut ? wpcEarlyAfterChain(8e3).then((function(open) {
                        return open ? fire() : null;
                    })) : fire();
                }
            }
        } catch (z) {
            held = null;
        }
        var go = function() {
            start();
        }, late = function() {
            wpcMenuLiveClose();
            wpcPressLateRun(start);
        }, first = !held && wpcTapFirst(ev);
        (held || first) && wpcReleaseOn("releaseNoTransition") && (wpcNoTransitionDue = 1);
        held ? held.then(late, late) : first ? wpcTapAfter(late) : go();
    }
    function wpcMenuLiveTap(t) {
        var cfg = window.wpcDelayV3Cfg, list = cfg && 1 === +cfg.menuLive ? cfg.menuToggles : null;
        if (!Array.isArray(list) || !t || "function" != typeof t.closest) return !1;
        try {
            if (t.closest(".elementor-menu-toggle")) return !1;
        } catch (z) {}
        for (var i = 0; i < list.length; i++) try {
            if (t.closest(list[i])) return !0;
        } catch (z) {}
        return !1;
    }
    function wpcTapFirst(ev) {
        var cfg = window.wpcDelayV3Cfg;
        if (!cfg || 1 !== +cfg.tapFirst || c || !ev || !1 === ev.isTrusted || !/^(?:pointerdown|touchstart|mousedown)$/.test(ev.type)) return !1;
        var target = ev.target;
        if (!target || "function" != typeof target.closest) return !1;
        if (window.wpcEarlyAnswered && window.wpcEarlyAnswered(target)) return !0;
        var list = cfg.tapNative;
        if (Array.isArray(list)) for (var i = 0; i < list.length; i++) try {
            if (target.closest(list[i])) return !0;
        } catch (z) {}
        return !1;
    }
    function wpcTapAfter(go, ms, frame) {
        var fired = 0, types = [ "click", "pointercancel", "touchcancel", "dragstart", "scroll", "wheel", "keydown", "contextmenu" ];
        var fire = function(direct) {
            if (fired) return;
            fired = 1;
            for (var i = 0; i < types.length; i++) f.call(document, types[i], fire, !0);
            if (frame) {
                if (direct === 1) {
                    go();
                    return;
                }
                var once = 0, fin = function() {
                    if (once) return;
                    once = 1;
                    go();
                };
                "function" == typeof window.requestAnimationFrame && window.requestAnimationFrame((function() {
                    wpcInFrameRun(fin);
                }));
                wpcNativeTimeout.call(window, fin, 120);
                return;
            }
            wpcNativeTimeout.call(window, go, 0);
        };
        for (var i = 0; i < types.length; i++) y.call(document, types[i], fire, {
            capture: !0,
            passive: !0
        });
        wpcNativeTimeout.call(window, (function() {
            fire(1);
        }), ms || 700);
    }
    var wpcTapHold = null;
    function wpcTapHoldable(ev) {
        var cfg = window.wpcDelayV3Cfg;
        if (c || !cfg || 1 !== +cfg.menuLive || !ev || !0 !== ev.isTrusted || !/^(?:pointerdown|touchstart)$/.test(ev.type)) return !1;
        var target = ev.target;
        if (!wpcMenuLiveTap(target)) return !1;
        if (wpcEarlyBusy) return !1;
        if (p.DOMContentLoaded && p.DOMContentLoaded.length) return !1;
        var entry = wpcEarlyTapEntry(target);
        return !(entry && "menu_toggle" === entry.kind && entry.left && entry.left.length);
    }
    function wpcMenuLiveClose() {
        if (window.wpcMenuLiveIq && "function" == typeof window.wpcMenuLiveChk) try {
            window.wpcMenuLiveChk();
        } catch (z) {}
    }
    function wpcTapRelease() {
        var hold = wpcTapHold;
        if (!hold) return;
        wpcMenuLiveClose();
        wpcTapHold = null;
        !c && Object.prototype.hasOwnProperty.call(hold.fns, "c") && wpcReleaseOn("releaseNoTransition") && (wpcNoTransitionDue = 1);
        wpcPrePaintRun((function() {
            for (var i = 0; i < hold.order.length; i++) try {
                hold.fns[hold.order[i]]();
            } catch (z) {}
        }), 0);
    }
    function wpcTapDefer(ev, key, fn) {
        if (!wpcTapHold) {
            if (!wpcTapHoldable(ev)) return !1;
            wpcTapHold = {
                fns: {},
                order: []
            };
            window.wpcDelayV3Cfg && 1 === +window.wpcDelayV3Cfg.menuSnapAtHold && wpcMenusSnapshot();
            wpcTapAfter(wpcTapRelease, 1500, !0);
        }
        if (!Object.prototype.hasOwnProperty.call(wpcTapHold.fns, key)) {
            wpcTapHold.order.push(key);
            wpcTapHold.fns[key] = fn;
        }
        return !0;
    }
    var wpcPrePaint = 0, wpcInFrame = 0, wpcPaintQ = null;
    function wpcInFrameRun(fn) {
        var was = wpcInFrame;
        wpcInFrame = 1;
        try {
            fn();
        } finally {
            wpcInFrame = was;
        }
    }
    function wpcAfterPaint(fn) {
        if (wpcPaintQ) {
            wpcPaintQ.push(fn);
            return;
        }
        var q = wpcPaintQ = [ fn ];
        var run = function() {
            if (wpcPaintQ !== q) return;
            wpcPaintQ = null;
            for (var i = 0; i < q.length; i++) try {
                q[i]();
            } catch (z) {}
        };
        if (wpcInFrame || "function" != typeof window.requestAnimationFrame) {
            wpcNativeTimeout.call(window, run, 0);
            return;
        }
        window.requestAnimationFrame((function() {
            wpcNativeTimeout.call(window, run, 0);
        }));
        wpcNativeTimeout.call(window, run, 250);
    }
    function wpcPaintFirst(fn) {
        if (!wpcPrePaint || wpcMediaNow) return !1;
        wpcAfterPaint(fn);
        return !0;
    }
    function wpcPrePaintRun(go, inFrame) {
        var run = function() {
            wpcPrePaint++;
            try {
                wpcNoTransitionDue && wpcNoTransitionOn();
                go();
            } finally {
                wpcPrePaint--;
            }
        };
        inFrame ? wpcInFrameRun(run) : run();
    }
    function wpcPressAfter(go, ms, late) {
        var fired = 0, framed = 0, now = [ "pointercancel", "touchcancel", "dragstart", "scroll", "wheel", "keydown", "contextmenu" ];
        var fire = function(inFrame) {
            if (fired) return;
            fired = 1;
            f.call(window, "click", frame, !1);
            f.call(window, "click", cap, !0);
            f.call(window, "pointerup", up, !0);
            for (var i = 0; i < now.length; i++) f.call(window, now[i], hit, !0);
            wpcMenuLiveClose();
            wpcPrePaintRun(late ? function() {
                wpcAfterPaint(go);
            } : go, inFrame === 1);
        };
        var frame = function() {
            if (framed || fired) return;
            framed = 1;
            "function" == typeof window.requestAnimationFrame ? window.requestAnimationFrame((function() {
                fire(1);
            })) : wpcNativeTimeout.call(window, fire, 0);
        };
        var hit = function() {
            fire();
        };
        var cap = function() {
            f.call(window, "click", cap, !0);
            frame();
        };
        var up = function() {
            f.call(window, "pointerup", up, !0);
            frame();
            wpcNativeTimeout.call(window, fire, 120);
        };
        y.call(window, "click", frame, {
            capture: !1,
            passive: !0
        });
        y.call(window, "click", cap, {
            capture: !0,
            passive: !0
        });
        y.call(window, "pointerup", up, {
            capture: !0,
            passive: !0
        });
        for (var i = 0; i < now.length; i++) y.call(window, now[i], hit, {
            capture: !0,
            passive: !0
        });
        wpcNativeTimeout.call(window, fire, ms || 1500);
    }
    function wpcAfterInput(fn, ev, late) {
        if (ev && !0 === ev.isTrusted && /^(?:pointerdown|touchstart)$/.test(ev.type)) {
            wpcPressAfter(fn, 1500, late);
            return;
        }
        if (late && ev && wpcPrePaint) {
            wpcAfterPaint(fn);
            return;
        }
        fn();
    }
    function wpcLcpRemaining(entry, ms) {
        if (!window.wpcAfterHero || !entry || typeof entry.startTime !== "number") return ms;
        return Math.max(0, ms - Math.max(0, performance.now() - entry.startTime));
    }
    function wpcEarlyArm() {
        var cfg = window.wpcDelayV3Cfg, ids = cfg && cfg.menuEarly, after = !!cfg && 1 === +cfg.tapAfterChain;
        if (!Array.isArray(ids) || !ids.length || c) return;
        if (after) try {
            var held = window.wpcScriptRegistry;
            if (Array.isArray(held)) for (var h = 0; h < held.length; h++) {
                var one = held[h], url = one && one.src && !one.io && !one.ext && ids.indexOf(one.id) !== -1 ? one.src : "";
                if (url && one.encoded) try { url = atob(url); } catch (z) { url = ""; }
                if (!url) continue;
                var pre = document.createElement("link");
                pre.rel = "preload";
                pre.as = "script";
                pre.href = wpcJsSrc(url);
                pre.setAttribute("fetchpriority", "low");
                (document.head || document.documentElement).appendChild(pre);
            }
        } catch (z) {}
        window.wpcEarlyAnswered = wpcEarlyAnswered;
        wpcOnJqueryArrival("earlyEvents", wpcEvArmJq);
        var fired = 0, seen = 0, lateWaited = 0, lateSheets = !!document.querySelector('link[rel="wpc-late-stylesheet"]'), go = function() {
            if (fired || c) return;
            fired = 1;
            var run = function() {
                if (c || wpcEarlyBusy) return;
                if (wpcRealRS() === "loading" || window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r && !wpcLaneJqOpen()) {
                    setTimeout(run, after ? 50 : 250);
                    return;
                }
                if (lateSheets && !window.wpcLateCssDone) {
                    if ((lateWaited += 100) <= 5e3) setTimeout(run, 100);
                    return;
                }
                wpcEarlyBusy = wpcEarlyRun(ids.slice()).catch((function() {})).then((function() {
                    wpcEarlyBusy = null;
                }));
            };
            "function" == typeof window.requestIdleCallback ? window.requestIdleCallback(run, {
                timeout: after ? 200 : 2e3
            }) : setTimeout(run, 0);
        }, earlyAt = typeof cfg.menuEarlyLcp !== "undefined", lcpDelay = earlyAt ? +cfg.menuEarlyLcp || 0 : typeof cfg.lateCssLcp !== "undefined" ? +cfg.lateCssLcp : 2e3;
        if ((earlyAt || lcpDelay > 0) && window.PerformanceObserver) try {
            var po = new PerformanceObserver((function(l) {
                if (l.getEntries().length && !seen) {
                    seen = 1;
                    try {
                        po.disconnect();
                    } catch (z) {}
                    setTimeout(go, wpcLcpRemaining(l.getEntries()[0], lcpDelay));
                }
            }));
            po.observe({
                type: "largest-contentful-paint",
                buffered: !0
            });
        } catch (z) {}
        var afterLoad = function() {
            setTimeout((function() {
                seen || go();
            }), earlyAt ? 0 : typeof cfg.lateCssTimer !== "undefined" ? +cfg.lateCssTimer : 2500);
        };
        "complete" === document.readyState ? afterLoad() : y.call(window, "load", afterLoad, {
            once: !0
        });
    }
    var wpcIdleDone = 0;
    function wpcIdleRelease() {
        var cfg = window.wpcDelayV3Cfg, ids = cfg && cfg.idleRelease;
        if (wpcIdleDone || c || !Array.isArray(ids) || !ids.length) return null;
        wpcIdleDone = 1;
        var work = new Promise((function(done) {
            var waits = 0, step = function() {
                if (c) return done();
                if (wpcEarlyBusy || wpcRealRS() === "loading" || window.wpcJqueryDeferMarker && !window.wpcJqueryDeferMarker.r) {
                    if (++waits > 30) return done();
                    wpcNativeTimeout.call(window, step, 100);
                    return;
                }
                var reg = window.wpcScriptRegistry, live = Array.isArray(reg) ? ids.filter((function(id) {
                    return reg.some((function(r) {
                        return r && r.id === id;
                    }));
                })) : [];
                if (!live.length) return done();
                wpcEarlyBusy = wpcEarlyRun(live).catch((function() {})).then((function() {
                    wpcEarlyBusy = null;
                    done();
                }));
            };
            step();
        }));
        return Promise.race([ work, wpcEarlyPause(1500) ]);
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
        // pageYOffset is 0 on an unscrolled page and 0 is falsy, so an `a || b || c || 0` chain
        // would evaluate EVERY branch in the common case — three layout-forcing reads where one
        // suffices, body.scrollTop the dearest. Read the modern property once.
        if (n = typeof window.pageYOffset === "number" ? window.pageYOffset : (document.documentElement || {}).scrollTop || 0,
        a = typeof window.pageXOffset === "number" ? window.pageXOffset : (document.documentElement || {}).scrollLeft || 0,
        (n > 0 || a > 0) && (!wpcTrustedCohort() || window.wpcHardSeen === 1)) return e("Page already scrolled; starting immediately"), T = !0,
        void D();
        var n, a, o = [ "mousemove", "mouseover", "pointermove", "pointerdown", "wheel", "click", "keydown", "touchstart", "scroll" ];
        function c(t, again) {
            if (t && t.isTrusted === false && window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.trusted === 1) return;
            if (wpcFineMoveShort(t)) return;
            if (wpcHintEvent(t)) {
                if (wpcFineMoveOn()) return;
                o.forEach((function(e) {
                    /^(?:mousemove|mouseover|pointermove|scroll)$/.test(e) && document.removeEventListener(e, c, {
                        passive: !0
                    });
                }));
                return;
            }
            wpcTrustedCohort() && wpcHardEvent(t) && wpcHardNote();
            if (!again && wpcTapDefer(t, "c", (function() {
                c(t, 1);
            }))) return;
            T || (T = !0, o.forEach((function(e) {
                document.removeEventListener(e, c, {
                    passive: !0
                });
            })), e("User interaction detected:", t.type), wpcMenusSnapshot(), wpcPressStart(t));
        }
        e("Waiting for user interaction to load resources"), o.forEach((function(e) {
            document.addEventListener(e, c, {
                passive: !0
            });
        })), function() {
            // v7.22.33 — A GESTURE BEFORE THE LOADER IS STILL A GESTURE, FOR JS TOO. .29 honoured
            // the head arm's html.wpc-bgl255 for the CSS lane only; the JS lane kept waiting for a
            // movement that had already happened (staging: tap at 0.7s, loader at 1.0s = CSS
            // restored, replay never started, wpc-scripts-loaded never fired, sticky never
            // healed — until the visitor's NEXT gesture). The registry is emitted at body end, so
            // a loader that booted mid-parse starts at DCL, never before.
            try {
                if (wpcHardStart()) {
                    if (window.wpcHardSeen === 1) wpcMenusSnapshot(1);
                    var b = function() { c({ type: "wpc-bgl255" }); };
                    "loading" === document.readyState ? document.addEventListener("DOMContentLoaded", b) : b();
                }
            } catch (x) {}
        }(), window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.startOnLoad === 1 && function() {
            var go = function() {
                setTimeout((function() {
                    T || I();
                    c({ type: "wpc-onload" });
                }), 0);
            };
            "complete" === document.readyState ? go() : y.call(window, "load", go, { once: !0 });
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
        wpcSidOf && [ "pointerdown", "touchstart" ].forEach((function(k) {
            y.call(document, k, wpcJumpTap, {
                capture: !0,
                passive: !0
            });
        }));
        window.wpcIdleRelease = wpcIdleRelease;
        window.wpcMenuLiveTap = wpcMenuLiveTap;
        window.wpcTapDefer = wpcTapDefer;
        window.wpcMenusSnapshot = wpcMenusSnapshot;
        window.wpcAfterInput = wpcAfterInput;
        window.wpcPaintFirst = wpcPaintFirst;
        window.wpcHardNote = wpcHardNote;
        window.wpcFreshWrite = wpcFreshWrite;
        wpcEarlyArm();
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
    var errs = [], t0 = 0, booted = false, wdSent = false, realInput = 0;
    function flagged(o) {
        if (+cfg.trusted === 1) {
            o.h = realInput ? 1 : 0;
            o.wd = navigator.webdriver === true ? 1 : 0;
            o.r = cfg.r || "";
        }
        return o;
    }
    if (+cfg.trusted === 1) {
        var realInputEvents = [ "pointerdown", "touchstart", "keydown", "click", "wheel" ];
        var realInputSeen = function(e) {
            if (!(e && e.isTrusted)) {
                return;
            }
            realInput = 1;
            realInputEvents.forEach((function(ev) {
                window.removeEventListener(ev, realInputSeen, true);
            }));
        };
        realInputEvents.forEach((function(ev) {
            window.addEventListener(ev, realInputSeen, {
                passive: true,
                capture: true
            });
        }));
    }
    window.addEventListener("wpc-scripts-loaded", (function() {
        booted = true;
        if (wdSent) {
            try {
                // Late-but-successful boot: RETRACT the strike (b:1) so a slow
                // network never demotes a healthy site.
                var rf = new FormData;
                rf.append("action", "wpc_delay_v3_report");
                rf.append("payload", JSON.stringify(flagged({
                    u: location.pathname.slice(0, 120),
                    s: cfg.rs || "",
                    b: 1
                })));
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
            fd.append("payload", JSON.stringify(flagged({
                u: location.pathname.slice(0, 120),
                s: cfg.rs || "",
                e: errs.slice(0, 3),
                b: 0,
                atf: atf,
                n: (window.wpcScriptRegistry || []).length
            })));
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
                fd.append("payload", JSON.stringify(flagged({
                    u: location.pathname.slice(0, 120),
                    s: cfg.rs || "",
                    e: errs,
                    d: t0 ? Date.now() - t0 : 0,
                    n: (window.wpcScriptRegistry || []).length
                })));
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
    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssOnly === 1) return; // v7.23.15 — cssOnly: no replay, nothing to respond to
    var done = false, pending = null;
    // v7.10.617 — pointer bookkeeping for the parked-hover replay below. Touch input
    // has no hover concept, so any touchstart stands the whole belt down.
    var lastPointer = null, touchSeen = false;
    try {
        document.addEventListener("mousemove", function(e) {
            lastPointer = { x: e.clientX, y: e.clientY };
        }, { capture: true, passive: true });
        document.addEventListener("touchstart", function() {
            touchSeen = true;
            lastPointer = null;
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
    var menuFix = !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.menuFix === 1);
    var clickSkip = !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.clickSkip === 1);
    var statelessToggleSelector = ".site-navigation-toggle, .oxy-menu-toggle";
    var menuToggleSelector = ".menu-item-has-children > a, li[aria-haspopup] > a, a[aria-haspopup], a[aria-expanded], .elementor-menu-toggle, [role=button][aria-expanded], button[aria-expanded], .menu-toggle, .navbar-toggler" + (menuFix ? ", " + statelessToggleSelector : "");
    var lightboxSelector = "a[data-elementor-open-lightbox=yes], a[data-elementor-open-lightbox=default], a[data-elementor-lightbox-slideshow], a.glightbox, a.fancybox, a.lightbox, a[data-fancybox]";
    var lightboxHold = !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.lightboxHold === 1);
    var heldActionSelector = "a.ajax_add_to_cart, a[href='#'], a[href^='#'][role=button]" + (lightboxHold ? ", " + lightboxSelector : "");
    var wpcClickAlreadyBound = function(el) {
        if (el.getAttribute("onclick")) { return true; }
        var jq = window.jQuery;
        if (!jq || typeof jq._data !== "function") { return false; }
        for (var n = el; n && n.nodeType === 1 || n === document; n = n === document ? null : (n.parentNode || document)) {
            var evs = null;
            try { evs = jq._data(n, "events"); } catch (e) {}
            var list = evs && evs.click;
            if (!list) { continue; }
            for (var h = 0; h < list.length; h++) {
                if (!list[h].selector) { if (n === el) { return true; } continue; }
                try {
                    if (clickSkip) { var c = el.closest(list[h].selector); if (c && n.contains(c)) { return true; } }
                    else if (el.matches(list[h].selector)) { return true; }
                } catch (e) { if (!clickSkip) { return true; } }
            }
        }
        return false;
    };
    var wpcClickServiced = function(t) {
        var rec = window.wpcClickBoundEls;
        if (rec && rec.has(t) || typeof t.onclick === "function" || wpcClickAlreadyBound(t)) { return true; }
        var ctl = null;
        try { ctl = t.closest("a,button,[role=button]"); } catch (e) {}
        if (ctl && ctl !== t && ctl !== document.body && ctl !== document.documentElement
            && (rec && rec.has(ctl) || typeof ctl.onclick === "function" || wpcClickAlreadyBound(ctl))) { return true; }
        if (!window.wpcClickWide) { return false; }
        for (var n = t.parentNode; n && n.nodeType === 1 && n !== document.body && n !== document.documentElement; n = n.parentNode) {
            if (rec && rec.has(n) || typeof n.onclick === "function" || wpcClickAlreadyBound(n)) { return true; }
        }
        return false;
    };
    // v7.10.616 — the replay's success observable. aria-expanded alone misses handlers that
    // toggle only classes, inline styles or hidden on the panel. JS-writable attributes ONLY:
    // computed styles are excluded because used-css media flips change them in exactly this
    // window with no handler involved.
    var scriptsLoadedDone = 0, lastReplayAt = 0;
    var wpcToggleStateSnapshot = function(t, withParent) {
        var s = (t.getAttribute("aria-expanded") || "") + "|" + (t.getAttribute("class") || "");
        if (withParent) {
            try { s += "|" + ((t.parentNode && t.parentNode.getAttribute && t.parentNode.getAttribute("class")) || ""); } catch (e) {}
        }
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
    var wpcReplayKey = function(p) {
        return p.a !== null
            ? (p.t.getAttribute("aria-expanded") || "") + "|" + (p.t.getAttribute("class") || "")
            : wpcToggleStateSnapshot(p.t, p.stateless);
    };
    var wpcEndReplay = function() {
        var p = pending;
        pending = null;
        if (p && typeof p.w === "function") { p.w(); }
    };
    var wpcReplayWatchTypes = ["pointerdown", "touchstart", "click", "keydown"];
    var wpcReplayWatch = function(p) {
        if (p.w !== undefined) { return; }
        var last = wpcReplayKey(p), mo = null, cap = 0;
        var end = function() {
            clearTimeout(cap);
            for (var i = 0; i < wpcReplayWatchTypes.length; i++) {
                document.removeEventListener(wpcReplayWatchTypes[i], human, true);
            }
            if (mo) { try { mo.disconnect(); } catch (e) {} }
            p.w = 0;
        };
        var human = function(ev) {
            if (pending !== p) { end(); return; }
            if (ev.isTrusted === true) { p.h = 1; }
        };
        var moved = function() {
            if (pending !== p) { end(); return; }
            var now = wpcReplayKey(p);
            if (now !== last) { last = now; p.m = 1; }
        };
        p.w = end;
        try {
            for (var i = 0; i < wpcReplayWatchTypes.length; i++) {
                document.addEventListener(wpcReplayWatchTypes[i], human, { capture: true, passive: true });
            }
            if (typeof MutationObserver === "function") {
                mo = new MutationObserver(moved);
                mo.observe(p.t, { attributes: true, attributeFilter: ["aria-expanded", "class"] });
                if (p.a === null) {
                    var wrap = p.stateless ? p.t.parentNode : null;
                    if (wrap && wrap.nodeType === 1) { mo.observe(wrap, { attributes: true, attributeFilter: ["class"] }); }
                    var cid = p.t.getAttribute("aria-controls"), panel = cid ? document.getElementById(cid) : p.t.nextElementSibling;
                    if (panel && panel.nodeType === 1) { mo.observe(panel, { attributes: true, attributeFilter: ["class", "style", "aria-hidden", "hidden"] }); }
                }
            }
        } catch (e) {}
        cap = setTimeout(end, 20000);
    };
    var wpcEarlySet = window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.menuEarly;
    if (Array.isArray(wpcEarlySet) && wpcEarlySet.length) window.wpcEarlyTapReplay = function(target) {
        var p = pending;
        if (done || !p || !p.t || !p.t.isConnected || !target || !(p.t === target || p.t.contains(target) || target.contains(p.t))) { return; }
        if (p.h || p.m) { return; }
        if (p.tog && wpcToggleStateSnapshot(p.t, p.stateless) !== p.s) {
            pending = null;
            return;
        }
        p.c = p.t.getAttribute("class") || "";
        if (p.tog) { wpcReplayWatch(p); p.dispatched = 1; lastReplayAt = Date.now(); } else { pending = null; }
        try {
            var ev = new MouseEvent("click", { bubbles: true, cancelable: true, view: window, clientX: p.x, clientY: p.y });
            ev.__wpcReplay = true;
            p.t.dispatchEvent(ev);
        } catch (e) {}
    };
    var wpcMenuLiveTap = function(t) {
        return "function" == typeof window.wpcMenuLiveTap && window.wpcMenuLiveTap(t);
    };
    var wpcHoldClick = (function(ev) {
        if (done || ev.__wpcReplay || ev.isTrusted === false) {
            return;
        }
        if (wpcMenuLiveTap(ev.target)) {
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
        try { tog = t.closest(menuToggleSelector); } catch (e) {}
        if (!tog && (clickSkip && wpcClickServiced(t) || window.wpcEarlyAnswered && window.wpcEarlyAnswered(t))) {
            return;
        }
        // v7.10.608 — Elementor writes aria-expanded="false" into the SERVER markup, so its
        // presence proves nothing about binding. Record the VALUE and decide later on whether it
        // CHANGED — an outcome test, not a capability test.
        var ariaExpandedAtClick = tog ? tog.getAttribute("aria-expanded") : null;
        var stateless = false;
        try { stateless = !!(menuFix && tog && ariaExpandedAtClick === null && tog.matches(statelessToggleSelector)); } catch (e) {}
        var act = null;
        if (!tog && !scriptsLoadedDone) {
            try { act = t.closest(heldActionSelector); } catch (e) {}
            if (!clickSkip && act && wpcClickAlreadyBound(act)) { act = null; }
        }
        if (!tog && !act && t.closest("a[href], input, textarea, select, label, [contenteditable]")) {
            return;
        }
        // A click on a form's submit button is never recorded for replay: the real click already
        // submitted the form (its default action, or the form script's own handler), so a replay
        // submits it a second time. Ticket 11945: SureForms' <button id="srfm-submit-btn"> has no
        // type (= submit); on a cold first visit the real click sent the form, the replay ~60 ms
        // after wpc-scripts-loaded sent it again with the already-used reCAPTCHA token, and Google
        // answered timeout-or-duplicate ("Google reCAPTCHA verification failed"). This relies on
        // the form's submit handler running at parse: SureForms is kept eager by the form-family
        // keep (js_delay_v3.class.php) because, delayed, the replay was the only thing that
        // delivered a v3 or captcha-less SureForms submit. The button's own type and form owner
        // decide (type="submit", missing or invalid; inside the form or tied to it by form="…");
        // type="button" and type="reset" keep the replay, as do toggles.
        var submitButton = tog || act ? null : t.closest("button");
        if (submitButton && submitButton.type === "submit" && submitButton.form) {
            return;
        }
        if (tog || act) {
            ev.preventDefault();
        }
        if (act) {
            setTimeout(function() {
                if (done || !pending || pending.t !== act) { return; }
                done = true;
                pending = null;
                if ((act.getAttribute("href") || "").charAt(0) === "#") { return; }
                if (lightboxHold) {
                    var lightbox = false;
                    try { lightbox = act.matches(lightboxSelector); } catch (e) {}
                    if (lightbox && !wpcClickAlreadyBound(act)) { return; }
                }
                try {
                    var late = new MouseEvent("click", { bubbles: true, cancelable: true, view: window, clientX: ev.clientX, clientY: ev.clientY });
                    late.__wpcReplay = true;
                    act.dispatchEvent(late);
                } catch (e) {}
            }, 12000);
        }
        pending = {
            t: tog || act || t,
            x: ev.clientX,
            y: ev.clientY,
            tog: tog ? 1 : 0,
            a: ariaExpandedAtClick,
            stateless: stateless,
            s: tog ? wpcToggleStateSnapshot(tog, stateless) : "",
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
            j: scriptsLoadedDone,
            href: tog ? tog.getAttribute("href") : ""
        };
    });
    document.addEventListener("click", wpcHoldClick, true);
    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.submitHold === 1) {
        var wpcSubmitHeld = [], wpcSubmitTimer = 0;
        var wpcSubmitRelease = function() {
            clearTimeout(wpcSubmitTimer);
            wpcSubmitTimer = 0;
            var list = wpcSubmitHeld.splice(0);
            for (var i = 0; i < list.length; i++) {
                var form = list[i].form, submitter = list[i].submitter;
                if (!form || !form.isConnected) { continue; }
                form.__wpcResubmit = 1;
                try {
                    var proto = typeof HTMLFormElement !== "undefined" ? HTMLFormElement.prototype : null;
                    var resubmit = proto && typeof proto.requestSubmit === "function" ? proto.requestSubmit : form.requestSubmit;
                    if (typeof resubmit === "function") {
                        try { resubmit.call(form, submitter || undefined); } catch (e) { resubmit.call(form); }
                    } else if (submitter && typeof submitter.click === "function") {
                        submitter.click();
                    } else {
                        (proto && typeof proto.submit === "function" ? proto.submit : form.submit).call(form);
                    }
                } catch (e) {}
                form.__wpcResubmit = 0;
            }
        };
        document.addEventListener("submit", function(ev) {
            var form = ev.target;
            if (!form || form.tagName !== "FORM" || ev.defaultPrevented || ev.isTrusted === false || form.__wpcResubmit || scriptsLoadedDone) { return; }
            if (String(form.getAttribute("method") || "").toLowerCase() !== "post") { return; }
            var registry = window.wpcScriptRegistry;
            if (!Array.isArray(registry) || !registry.length) { return; }
            ev.preventDefault();
            ev.stopImmediatePropagation();
            if (!wpcSubmitHeld.some(function(held) { return held.form === form; })) {
                wpcSubmitHeld.push({ form: form, submitter: ev.submitter || null });
            }
            if (!wpcSubmitTimer) { wpcSubmitTimer = setTimeout(wpcSubmitRelease, +window.wpcDelayV3Cfg.submitHoldMs > 0 ? +window.wpcDelayV3Cfg.submitHoldMs : 3000); }
            if (typeof window.wpcStartDelayed === "function") { window.wpcStartDelayed(); }
        }, true);
        window.addEventListener("wpc-scripts-loaded", wpcSubmitRelease);
    }
    function wpcPreClassKey(c) {
        return String(c || "").split(/\s+/).filter(function(k) { return k && !/^wpc-|^elementor-invisible$|^(?:no-)?js$|lazy|loaded|sticky|hover|focus|aos|animat|visible|in-?view|fade|ready|appear|reveal|scroll/i.test(k); }).sort().join(" ");
    }
    function wpcPreStyleKey(c) {
        var o = "", r = /(?:^|;)\s*(display|visibility)\s*:\s*([^;]*)/gi, x;
        while ((x = r.exec(c || ""))) { o += x[1].toLowerCase() + ":" + x[2].replace(/\s+/g, "").toLowerCase() + ";"; }
        return o;
    }
    function wpcPreSig(f) {
        return f.ae + "|" + wpcPreClassKey(f.cls) + (f.p ? "|" + wpcPreClassKey(f.p.c) + "|" + f.p.h + "|" + f.p.a + "|" + wpcPreStyleKey(f.p.s) : "");
    }
    function wpcPreLive(t) {
        var f = { ae: t.getAttribute("aria-expanded") || "", cls: t.getAttribute("class") || "", p: null }, c = t.getAttribute("aria-controls"), p = c ? document.getElementById(c) : t.nextElementSibling;
        if (p && p.nodeType === 1) { f.p = { c: p.getAttribute("class") || "", h: p.hidden ? "H" : "", a: p.getAttribute("aria-hidden") || "", s: p.getAttribute("style") || "" }; }
        return f;
    }
    function wpcPreRec(s) {
        var q = String(s).split("|");
        if (q.length === 2) { return { ae: q[0], cls: q[1], p: null }; }
        if (q.length === 5) { return { ae: q[0], cls: q[1], p: { c: q[2], h: /H$/.test(q[4]) ? "H" : "", a: q[4].replace(/H$/, ""), s: q[3] } }; }
        return null;
    }
    function wpcPreMoved(m) {
        try {
            for (var i = 0; m && i + 1 < m.length; i += 2) {
                var t = m[i], r = t && t.nodeType === 1 ? wpcPreRec(m[i + 1]) : null;
                if (!r) { continue; }
                var l = wpcPreLive(t);
                if (!l.p || !r.p) { r.p = l.p = null; }
                if (wpcPreSig(r) !== wpcPreSig(l)) { return true; }
            }
        } catch (e) {}
        return false;
    }
    try {
        var wpcPreClick = window.wpcPreBootClick;
        window.wpcPreBootClick = null;
        if (wpcPreClick && wpcPreClick.ev && wpcPreClick.ev.target && wpcPreClick.ev.target.isConnected && performance.now() - wpcPreClick.t <= (window.wpcDelayV3Cfg && 1 === +window.wpcDelayV3Cfg.tapAfterChain ? 8e3 : 1500)
            && (window.pageYOffset || 0) === wpcPreClick.y && !(wpcPreClick.ev.target.closest && wpcPreClick.ev.target.closest(".elementor-menu-toggle")) && !wpcMenuLiveTap(wpcPreClick.ev.target)) {
            window.wpcPreTapEv = wpcPreClick.ev;
            var wpcPreTog = wpcPreClick.ev.target.closest(menuToggleSelector), wpcPreServiced = false, wpcPreAt = wpcPreClick.m || [];
            for (var wpcPreI = 0; wpcPreTog && wpcPreI + 1 < wpcPreAt.length; wpcPreI += 2) {
                if (wpcPreAt[wpcPreI] === wpcPreTog) { wpcPreServiced = wpcPreAt[wpcPreI + 1] !== wpcToggleStateSnapshot(wpcPreTog, false); break; }
            }
            if (!wpcPreTog && wpcPreMoved(wpcPreAt)) { wpcPreServiced = true; }
            if (!wpcPreServiced) { wpcHoldClick(wpcPreClick.ev); }
        }
    } catch (e) {}
    window.addEventListener("wpc-scripts-loaded", (function() {
        scriptsLoadedDone = 1;
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
        // in 4). Every attempt is verified through wpcToggleStateSnapshot; an unchanged snapshot means
        // the click hit nothing bound — retry on the ladder. Snapshot movement at ANY point
        // means a handler acted — stop, never double-toggle.
        // v7.10.634 — denser mid-window rungs: Pro's nav chunk fetches ~90ms after the
        // gesture releases the lane and binds ~1.5-2.5s in; the old 1200->2500->4000 gaps
        // made the OPEN wait up to 2.5s past binding. Same verified-outcome semantics,
        // worst-case post-bind wait now <=800ms across the whole bind window.
        var replayRungDelays = [250, 450, 700, 1000, 1400, 1900, 2500, 3200];
        var wpcDispatchReplayClick = function() {
            wpcReplayWatch(pending);
            lastReplayAt = Date.now();
            pending.dispatched = 1;
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
        var scriptArrivalObserver = null;
        // i=1: entry outcome-check runs first, so once any rung's dispatch was serviced
        // every later chain halts at its check — single-threaded dispatch means a later
        // rung always observes an earlier rung's effect, never double-toggles.
        var wpcReplayRungOnScriptLoad = function() {
            // toggles only — the ladder was toggle-only by construction; a non-toggle
            // pending is fire()'s single dispatch, never ours to repeat.
            if (!pending || !pending.tog || !pending.t || !pending.t.isConnected) { return; }
            wpcVerifyReplayedToggle(1);
        };
        try {
            scriptArrivalObserver = new MutationObserver(function(ms) {
                if (!pending) { try { scriptArrivalObserver.disconnect(); } catch (e) {} return; }
                for (var a = 0; a < ms.length; a++) {
                    var ns = ms[a].addedNodes;
                    for (var b = 0; b < ns.length; b++) {
                        var n = ns[b];
                        if (n.tagName === "SCRIPT" && n.src) {
                            n.addEventListener("load", function() {
                                setTimeout(wpcReplayRungOnScriptLoad, 150);
                            }, { once: true });
                        }
                    }
                }
            });
            scriptArrivalObserver.observe(document.documentElement, { childList: true, subtree: true });
        } catch (e) {}
        var wpcVerifyReplayedToggle = function(i) {
            if (!pending || !pending.t || !pending.t.isConnected || pending.h || pending.m) {
                wpcEndReplay();
                return;
            }
            if (menuFix && lastReplayAt && Date.now() - lastReplayAt < 400) {
                setTimeout((function() {
                    wpcVerifyReplayedToggle(i);
                }), 420 - (Date.now() - lastReplayAt));
                return;
            }
            // i=0 is entry: the caller already decided a replay is owed (the bind signal
            // itself moves the snapshot, so an entry check would falsely read "handled").
            // v7.10.633 — for a toggle that SHIPPED an aria value, success is that VALUE
            // moving or the toggle's OWN class moving; the panel is bind-noise (SmartMenus
            // stamps the UL when it binds), so a panel-inclusive snapshot false-succeeded
            // when the bind landed inside a rung window — the ladder stopped one click
            // short and the tap was swallowed (hawkeye 2/6, timing-dependent).
            if (i > 0 || (menuFix && pending.dispatched)) {
                if (pending.a !== null) {
                    var ariaExpandedOnRung = pending.t.getAttribute("aria-expanded");
                    if ((ariaExpandedOnRung !== null && ariaExpandedOnRung !== pending.a)
                        || (pending.t.getAttribute("class") || "") !== pending.c) {
                        wpcEndReplay();
                        return;
                    }
                } else if (wpcToggleStateSnapshot(pending.t, pending.stateless) !== pending.s) {
                    wpcEndReplay();
                    return;
                }
            }
            if (i >= replayRungDelays.length) {
                wpcEndReplay();
                return;
            }
            // Re-baseline at dispatch time so each verify compares against the state the
            // click actually landed on.
            pending.s = wpcToggleStateSnapshot(pending.t, pending.stateless);
            pending.c = pending.t.getAttribute("class") || "";
            wpcDispatchReplayClick();
            setTimeout((function() {
                wpcVerifyReplayedToggle(i + 1);
            }), replayRungDelays[i]);
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
            if (pending.h || pending.m) {
                done = true;
                wpcEndReplay();
                return;
            }
            if (pending.stateless) {
                done = true;
                if (wpcToggleStateSnapshot(pending.t, true) !== pending.s) {
                    pending = null;
                    return;
                }
                wpcVerifyReplayedToggle(0);
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
                    wpcVerifyReplayedToggle(0);
                    return;
                }
            } else {
                // v7.10.633 — serviced is the OUTCOME, never panel decoration. Binding
                // itself stamps panel classes (SmartMenus decorates the UL), so the old
                // panel-inclusive snapshot read a mid-window bind as "serviced" and the
                // tap died with no ladder at all (hawkeye 2/6 opens). A shipped
                // aria-expanded is MAINTAINED by its handler: serviced = its VALUE moved
                // (bind rewrites "false" as "false"), or the toggle's OWN class moved.
                var ariaExpandedOnEntry = pending.t.getAttribute("aria-expanded");
                if ((ariaExpandedOnEntry !== null && ariaExpandedOnEntry !== pending.a)
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
                    wpcVerifyReplayedToggle(0);
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
        var navMenuRungDelays = [400, 1200, 2500, 5000, 9000];
        var wpcNavMenuInitRung = function(i) {
            var stop = false;
            try {
                // v7.21.364 — never race the native boot: our dispatch at 1583ms vs
                // Elementor's own handler-chunk dispatch at 2102ms double-bound the
                // toggle. A rung before replay-complete+settle re-schedules itself.
                try { window.wpcWrapRunReadyTrigger && window.wpcWrapRunReadyTrigger(); } catch (e0) {}
                var readyTriggerWait = window.wpcReadyTriggerVerdict ? window.wpcReadyTriggerVerdict() : 0;
                if (readyTriggerWait === -1) { return; }
                if (readyTriggerWait > 0
                    || !window.wpcScriptsLoadedAt || (performance.now() - window.wpcScriptsLoadedAt) < 1200) {
                    // BOUNDED: a replay that died before wpc-scripts-loaded must never
                    // leave this rung re-arming forever (pre-.364 the ladder always
                    // terminated). 30s from the first rung, then stop for good.
                    if (!window.wpcNavMenuLadderStartedAt) { window.wpcNavMenuLadderStartedAt = performance.now(); }
                    if (performance.now() - window.wpcNavMenuLadderStartedAt > 30000) { return; }
                    if (i + 1 < navMenuRungDelays.length) {
                        setTimeout((function() { wpcNavMenuInitRung(i + 1); }), navMenuRungDelays[i + 1] - navMenuRungDelays[i]);
                    } else {
                        setTimeout((function() { wpcNavMenuInitRung(i); }), 1500);
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
                    // v7.21.364 — was a blanket trigger: every already-run registrar re-ran
                    // and re-registered element_ready hooks (one of four toggle click binds)
                    try { window.wpcFireMissedElementorInitHandlers && window.wpcFireMissedElementorInitHandlers(); } catch (e) {}
                    setTimeout((function() {
                        try {
                            for (var k = 0; k < navs.length; k++) {
                                window.wpcRunReadyTriggerForElement && window.wpcRunReadyTriggerForElement(navs[k]);
                            }
                        } catch (e) {}
                    }), 300);
                }
            } catch (e) {
                stop = true;
            }
            if (!stop && i + 1 < navMenuRungDelays.length) {
                setTimeout((function() { wpcNavMenuInitRung(i + 1); }), navMenuRungDelays[i + 1] - navMenuRungDelays[i]);
            }
        };
        setTimeout((function() { wpcNavMenuInitRung(0); }), navMenuRungDelays[0]);

        // v7.21.33 — LATE-LISTENER INIT DIFF-REPLAY (generalizes .618 beyond nav-menu).
        // Field receipt: Elementor eager (promotion) + Essential Addons Simple Menu delayed
        // -> the widget registers elementor/frontend/init AFTER the one-shot fired, its
        // element_ready handlers never attach, the mobile hamburger is inert. Diff the
        // listeners on window against the lane-start snapshot: only the NEW ones missed
        // the event, so only they are invoked (no double-init of anything that already
        // ran). While they run, their frontend/element_ready registrations are recorded
        // and ONLY those widget types get runReadyTrigger — never the whole page.
        setTimeout((function() {
            try {
                // v7.21.364 — the lane-start snapshot could not see a native fire that
                // happened mid-replay after a late registrar registered, so this belt
                // re-invoked handlers that had already run (one of four toggle binds).
                // The shared diff-fire owns the ran-set now; this belt keeps only its
                // targeted runReadyTrigger of the widget types the late handlers register.
                var missedWidgetNames = window.wpcFireMissedElementorInitHandlers ? window.wpcFireMissedElementorInitHandlers() : [];
                if (!missedWidgetNames.length) { return; }
                // v364: invoking missed registrars is safe anytime (they never ran);
                // DISPATCHING element_ready is not — wait out the native boot first.
                var wpcWidgetDispatchDelay = function() {
                    return window.wpcScriptsLoadedAt ? Math.max(60, 1200 - (performance.now() - window.wpcScriptsLoadedAt)) : 1200;
                };
                var retriggerTries = 0;
                var wpcRetriggerMissedWidgets = (function() {
                    try {
                        var verdictWait = window.wpcReadyTriggerVerdict ? window.wpcReadyTriggerVerdict() : 0;
                        if (verdictWait === -1) { return; }
                        if (verdictWait > 0) {
                            if (retriggerTries++ < 8) { setTimeout(wpcRetriggerMissedWidgets, verdictWait); }
                            return;
                        }
                        var seenWidgetTypes = {};
                        for (var nameIndex = 0; nameIndex < missedWidgetNames.length; nameIndex++) {
                            var widgetType = String(missedWidgetNames[nameIndex]).split(".")[0];
                            if (!widgetType || seenWidgetTypes[widgetType]) { continue; }
                            seenWidgetTypes[widgetType] = 1;
                            var widgetEls = document.querySelectorAll(".elementor-widget-" + widgetType);
                            for (var elementIndex = 0; elementIndex < widgetEls.length; elementIndex++) {
                                try { window.wpcRunReadyTriggerForElement && window.wpcRunReadyTriggerForElement(widgetEls[elementIndex]); } catch (z) {}
                            }
                        }
                    } catch (z) {}
                });
                setTimeout(wpcRetriggerMissedWidgets, wpcWidgetDispatchDelay());
            } catch (z) {}
        }), 120);

        // v7.10.620 — NEVER-BLANK REVEAL for elementor-invisible. Receipt (staging
        // 2026-07-31): post-scroll, 22 elements stayed visibility:hidden — content
        // scrolled past while invisible. elementor-invisible is a deferral whose
        // undoer (waypoint/animation handler) provably does not run for a subset of
        // elements once lanes are delayed. Outcome law: an element in or above the
        // viewport that is still elementor-invisible ~600ms after entry gets revealed —
        // with its declared animation when parsable, plainly when not. Native reveals
        // that DO run win the race and leave nothing for this belt to do.
        var wpcRevealInvisibleOnViewportEntry = function() {
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
                        try { window.wpcPinVisible && window.wpcPinVisible(el, !!(anim && anim !== "none")); } catch (e) {}
                        // v7.21.335 — CONSUME THE ANIMATION WE JUST PLAYED (justmsp "slider
                        // double loads": idle>3s -> belt reveals -> gesture -> replay ->
                        // Elementor re-runs fadeInUp from invisible = second load). When WE
                        // are the revealer, strip the animation keys from data-settings so
                        // the late animator has nothing to replay; every other settings key
                        // (carousel geometry etc.) stays intact.
                        try { window.wpcScrubAnimationSettings && window.wpcScrubAnimationSettings(el); } catch (e) {}
                    } catch (e) {
                        try { el.classList.remove("elementor-invisible"); } catch (e2) {}
                        try { window.wpcPinVisible && window.wpcPinVisible(el, false); } catch (e2) {}
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
        setTimeout(wpcRevealInvisibleOnViewportEntry, 1200);

        // v7.10.826 — NEVER-BLANK REVEAL for Divi et-waypoint, the exact twin of the
        // elementor-invisible belt above. Divi hides scroll-animated elements with
        // .et-waypoint:not(.et_pb_counters){opacity:0} and its OWN delayed JS is the only
        // undoer (adds .et-animated). Receipt (clearconpools/gunite-concrete, 7.10.825):
        // every Divi frontend script in the delay manifest, blurb icons at opacity:0 with
        // no revealer until first interaction — and none after it when waypoint init
        // misses. Outcome law: an et-waypoint element in or above the viewport still
        // unrevealed ~600ms after entry gets Divi's own reveal class, so its native
        // et_pb_animation_* animation plays. Native reveals that DO run win the race.
        // v7.21.63 — DIVI 5 TWIN (underscore class): .et_animated{opacity:0;animation-fill-mode:
        // both!important} ships WITHOUT its animation-name class — Divi 5's own JS adds it at
        // waypoint, so with that JS delayed all 51 sections on 4bullmann sat invisible forever
        // (white page, "hero not loading", customer deactivated). Class-agnostic reveal: an
        // et_animated element whose computed animation-name is still "none" after the grace gets
        // inline opacity:1 (beats the class rule); an element Divi has already armed animates
        // natively and is left alone.
        var wpcRevealDiviWaypoints = function() {
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
        setTimeout(wpcRevealDiviWaypoints, 1200);

        var hoverRungDelays = [900, 1500, 2500];
        var wpcReopenHoveredSubmenuRung = function(i) {
            var stop = false;
            try {
                if (touchSeen || !lastPointer || !document.elementFromPoint) {
                    stop = true;
                } else {
                    var el = document.elementFromPoint(lastPointer.x, lastPointer.y);
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
                                    clientX: lastPointer.x + dx,
                                    clientY: lastPointer.y,
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
            if (!stop && i + 1 < hoverRungDelays.length) {
                setTimeout((function() { wpcReopenHoveredSubmenuRung(i + 1); }), hoverRungDelays[i + 1]);
            }
        };
        setTimeout((function() { wpcReopenHoveredSubmenuRung(0); }), hoverRungDelays[0]);
    }), {
        once: true
    });
})();

(function() {
    "use strict";
    // v7.23.15 — runs on BOTH lanes. On a cssOnly page the replay calls below are undefined and
    // skip; the CSS half (late-sheet release + synthetic scroll into the gesture arm) is exactly
    // what a delay page gives an evidenced visit — a stamped repeat visitor or a referred arrival
    // must not wait for a gesture just because the site has delay off.
    try {
        window.addEventListener("wpc-scripts-loaded", (function() {
            try {
                if (typeof window.wpcFreshWrite === "function") {
                    window.wpcFreshWrite();
                } else {
                    localStorage.setItem("fresh", String(Date.now()));
                }
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
        var visitEvidenced = false;
        try {
            var navigationEntry = performance.getEntriesByType("navigation")[0] || {};
            visitEvidenced = !!(document.referrer && document.referrer.length > 0)
                || navigationEntry.type === "back_forward"
                || /(^#|[#&])wpch\b/.test(location.hash || "");
        } catch (e) {}
        if ((wpcEng && Date.now() - wpcEng < 6048e5) || visitEvidenced) {
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
                                var own = new Event("scroll");
                                own.__wpcOwn = 1;
                                document.dispatchEvent(own);
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
    function wpcSwapNtState() {
        return window.wpcNt || (window.wpcNt = { g: 0, h: 0 });
    }
    function wpcSwapNtOn() {
        if (!window.wpcDelayV3Cfg || 1 !== +window.wpcDelayV3Cfg.swapNoTransition) return;
        var nt = wpcSwapNtState();
        nt.g++;
        if (nt.s) return;
        try {
            var st = document.createElement("style");
            st.id = "wpc-swap-no-transition";
            st.textContent = "html.wpc-nt *,html.wpc-nt *::before,html.wpc-nt *::after{transition:none!important}";
            document.documentElement.classList.add("wpc-nt");
            (document.head || document.documentElement).appendChild(st);
            nt.s = st;
            nt.t = setTimeout(wpcSwapNtOff, 4e3);
        } catch (z) {}
    }
    function wpcSwapNtOff() {
        var nt = window.wpcNt, st = nt && nt.s;
        if (!st) return;
        nt.s = null;
        nt.h = 0;
        nt.t && clearTimeout(nt.t);
        nt.t = 0;
        try {
            st.parentNode && st.parentNode.removeChild(st);
        } catch (z) {}
        try {
            document.documentElement.classList.remove("wpc-nt");
        } catch (z) {}
    }
    function wpcSwapNtEnd() {
        var nt = window.wpcNt;
        if (!nt || !nt.s) return;
        var gen = ++nt.g, gone = function() {
            gen === nt.g && !nt.h && wpcSwapNtOff();
        };
        try {
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(function() {
                    window.requestAnimationFrame(gone);
                });
                return;
            }
        } catch (z) {}
        setTimeout(gone, 100);
    }
    function wpcSwapNtHold(el) {
        if (!window.wpcDelayV3Cfg || 1 !== +window.wpcDelayV3Cfg.swapNoTransition) return;
        var nt = wpcSwapNtState(), done = function() {
            nt.h > 0 && nt.h--;
            wpcSwapNtEnd();
        };
        nt.h++;
        wpcSwapNtOn();
        el.addEventListener("load", done, { once: true });
        el.addEventListener("error", done, { once: true });
    }
    try {
        window.wpcDelayV3Cfg && 1 === +window.wpcDelayV3Cfg.swapNoTransition && (window.wpcNativeAddEventListener || EventTarget.prototype.addEventListener).call(document, "load", function(e) {
            var t = e && e.target;
            t && "LINK" === t.tagName && (t.hasAttribute("data-wpc-rest") || t.hasAttribute("data-wpc-ucss") || t.__wpcSwapOnLoad || t.hasAttribute("onload") && (/^stylesheet$/i.test(t.getAttribute("rel") || "") || /^preload$/i.test(t.getAttribute("rel") || "") && /^style$/i.test(t.getAttribute("as") || ""))) && (wpcSwapNtOn(), wpcSwapNtEnd());
        }, !0);
    } catch (z) {}
    function wpcCvRelease(el) {
        if (!el || !el.hasAttribute || !el.hasAttribute("data-wpc-cvg")) return;
        var g = el.__wpcCvG = (el.__wpcCvG || 0) + 1, gone = function() {
            g === el.__wpcCvG && el.removeAttribute("data-wpc-cvg");
        };
        try {
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(function() {
                    window.requestAnimationFrame(gone);
                });
                return;
            }
        } catch (z) {}
        setTimeout(gone, 100);
    }
    function wpcCvTagContainers() {
        var st = document.getElementById("wpc-cv-containers"), css = st && st.textContent ? String(st.textContent) : "";
        if (!css) return;
        var tag = function(sel) {
            try {
                [].slice.call(document.querySelectorAll(sel)).forEach(function(el) {
                    el.setAttribute("data-wpc-cv", "1");
                });
            } catch (z) {}
        };
        var scan = function(text, nested) {
            var at = 0;
            while (at < text.length) {
                var open = text.indexOf("{", at);
                if (open < 0) return;
                var head = text.slice(at, open).replace(/^\s+|\s+$/g, ""), depth = 1, end = open + 1;
                while (end < text.length && depth) {
                    var ch = text.charAt(end++);
                    ch === "{" ? depth++ : "}" === ch && depth--;
                }
                at = end;
                if (head.charAt(0) === "@") {
                    if (nested || !/^@media\s/i.test(head) || !window.matchMedia) continue;
                    var mq = false;
                    try {
                        mq = window.matchMedia(head.slice(6).replace(/^\s+/, "")).matches;
                    } catch (z) {}
                    mq && scan(text.slice(open + 1, end - 1), true);
                } else if (head) {
                    tag(head);
                }
            }
        };
        scan(css, false);
    }
    try {
        var wpcCvCfg = window.wpcDelayV3Cfg;
        if (!(wpcCvCfg && 1 === +wpcCvCfg.cvGuardOff) && "oncontentvisibilityautostatechange" in document && window.IntersectionObserver) {
            wpcCvTagContainers();
            var wpcCvEls = [].slice.call(document.querySelectorAll("[data-wpc-cv]"));
            if (wpcCvEls.length) {
                var wpcCvSt = document.createElement("style");
                wpcCvSt.id = "wpc-cv-no-transition";
                wpcCvSt.textContent = "[data-wpc-cvg],[data-wpc-cvg]::before,[data-wpc-cvg]::after,[data-wpc-cvg] *,[data-wpc-cvg] *::before,[data-wpc-cvg] *::after{transition:none!important}";
                (document.head || document.documentElement).appendChild(wpcCvSt);
                wpcCvEls.forEach(function(el) {
                    el.setAttribute("data-wpc-cvg", "1");
                });
                (window.wpcNativeAddEventListener || EventTarget.prototype.addEventListener).call(document, "contentvisibilityautostatechange", function(e) {
                    var t = e && e.target;
                    if (!t || !t.hasAttribute || !t.hasAttribute("data-wpc-cv")) return;
                    if (e.skipped) {
                        t.__wpcCvG = (t.__wpcCvG || 0) + 1;
                        t.setAttribute("data-wpc-cvg", "1");
                    } else {
                        wpcCvRelease(t);
                    }
                }, !0);
            }
        }
    } catch (z) {}
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
        if (window.wpcPaintFirst && window.wpcPaintFirst(wpcCritSweep)) return;
        // v7.21.105 — CRIT LEAVES ONLY BEHIND A GESTURE. (.108 correction: the 7285ms
        // white frame this gate was built for was Google Translate hiding <html> — see
        // the root-hide guard; instrumented replay shows crit removal painting clean.
        // The gate stays: removal after engagement is provably post-used-css, and
        // no gesture ever = crit stays — styled-with-duplication beats any repaint.)
        // Kill: wpcDelayV3Cfg.critSweepGesture=0 restores timer-fired sweeps.
        if (!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.critSweepGesture === 0) && !window.__wpcCritSweepGestureSeen) {
            if (!window.__wpcCritSweepGestureWindow) {
                window.__wpcCritSweepGestureWindow = 1;
                [ "pointerdown", "keydown", "touchstart", "wheel", "scroll" ].forEach(function (ev) {
                    window.addEventListener(ev, function (e) {
                        var run = function () {
                            window.__wpcCritSweepGestureSeen = 1;
                            try { wpcCritSweep(); } catch (x) {}
                        };
                        typeof window.wpcAfterInput === "function" ? window.wpcAfterInput(run, e, 1) : run();
                    }, { once: true, passive: true, capture: true });
                });
            }
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
                if (!sheetOk(fl[i]) || !wpcFlipLive(fl[i])) {
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
                    // The ATF subsets ride #wpc-font-faces, which this sweep never touches: they
                    // are registered with the render's face owner rather than minted into a
                    // block of their own. The only block still carrying the #wpc-font-subsets
                    // id is the crit-less carrier, which the sweep refuses to remove anyway.
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
                    wpcSwapNtOn();
                    c.remove();
                    wpcSwapNtEnd();
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
    // v7.21.238 — NEVER-BLANK IMAGE BELT. Parked imgs (src=data:svg + data-src) depend on
    // the lazy pixel executing; any script failure left placeholders forever (James: "even
    // no crit should not be missing images"). Second restorer, pixel-independent: first
    // gesture restores every parked img; a 5s timer restores the visible ones. Labs (no
    // gesture, pixel alive) see zero extra rows.
    function wpcRestoreParkedImages(all) {
        try {
            if (!all && window.__wpcPixelAlive) { return; }
            var parkedPicks = [];
            [].slice.call(document.querySelectorAll("img[data-src]")).forEach(function(im) {
                try {
                    if ((im.getAttribute("src") || "").indexOf("data:image/svg") !== 0) { return; }
                    if (!all) {
                        var r = im.getBoundingClientRect();
                        if (!(r.width > 0 && r.bottom >= -50 && r.top <= (window.innerHeight || 800) + 50 && getComputedStyle(im).display !== "none")) { return; }
                    }
                    parkedPicks.push(im);
                } catch (e) {}
            });
            parkedPicks.forEach(function(im) {
                try {
                    im.src = im.getAttribute("data-src");
                    if (im.getAttribute("data-srcset")) { im.srcset = im.getAttribute("data-srcset"); }
                    var pictureParent = im.parentNode;
                    if (pictureParent && pictureParent.tagName === "PICTURE") {
                        [].slice.call(pictureParent.querySelectorAll("source[data-srcset]")).forEach(function(sq) {
                            sq.setAttribute("srcset", sq.getAttribute("data-srcset"));
                            sq.removeAttribute("data-srcset");
                        });
                    }
                } catch (e) {}
            });
            // .297 — ORPHANED PARKED SOURCES: an earlier partial restore (img src flipped,
            // picture sources still parked) renders the placeholder via currentSrc even
            // though the img looks restored. All-mode sweeps every parked source whose img
            // is already live.
            if (all) {
                [].slice.call(document.querySelectorAll("picture source[data-srcset]")).forEach(function(sq) {
                    try {
                        // A data:-URI payload is a POISONED park (double-processed cache:
                        // the placeholder overwrote the ladder) — a flipped svg source
                        // shadows a perfectly good img src via currentSrc forever. Remove
                        // the source; the img wins. Real http payloads flip normally.
                        if ((sq.getAttribute("data-srcset") || "").indexOf("data:") === 0) {
                            sq.parentNode.removeChild(sq);
                            return;
                        }
                        var pictureImg = sq.parentNode ? sq.parentNode.querySelector("img") : null;
                        if (pictureImg && (pictureImg.getAttribute("src") || "").indexOf("data:image/svg") === 0) { return; }
                        sq.setAttribute("srcset", sq.getAttribute("data-srcset"));
                        sq.removeAttribute("data-srcset");
                    } catch (e) {}
                });
                [].slice.call(document.querySelectorAll('picture source[srcset^="data:"]')).forEach(function(sq) {
                    try { sq.parentNode.removeChild(sq); } catch (e) {}
                });
            }
        } catch (e) {}
        // .297 — THE BELT IS NO LONGER ONE-SHOT: a gesture during body streaming ran the
        // 400ms all-restore before most imgs existed (beucomply carousel: 4 of 12 parsed
        // at t=366ms, the rest orphaned forever — the 5s pass stands down while the pixel
        // is alive). Re-arm until a run happens on a complete document.
        if (all && document.readyState !== "complete") {
            setTimeout(function() { wpcRestoreParkedImages(true); }, 900);
        }
    }
    setTimeout(function() { wpcRestoreParkedImages(false); }, 5000);
    // v7.21.283 — ENTRANCE-ANIMATION NEVER-BLANK. .elementor-invisible ships
    // visibility:hidden and only Elementor's (delayed) JS reveals it; the server
    // integration covers the first five <div> forms only — staging: 27 invisibles
    // (sections/columns), six ATF headings hidden in EVERY mode, crit or not. Reveal
    // whatever is still concealed once the animator has had its chance: 3s no-gesture
    // (robots and idle humans see content), and post-gesture after the replay window
    // (Elementor animated what it could; the rest must not stay blank).
    // v7.21.342 — PIN WHAT WE REVEAL (service stack-trace, justmsp /houston/: loader
    // strips elementor-invisible at 245ms, Elementor's replayed handler runs fadeInUp
    // at 6014ms on cards visible for 6s = the "double load"; the .335 data-settings
    // scrub cannot reach a settings model jQuery cached before the scrub). A revealed
    // element gets inline opacity/visibility pins so no late handler can re-conceal
    // it, and animation:none once its entrance is settled (immediately on plain
    // reveals; after animationend/5s when the belt played the declared entrance).
    window.wpcPinVisible = window.wpcPinVisible || function(el, played) {
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
    window.wpcScrubAnimationSettings = window.wpcScrubAnimationSettings || function(el) {
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
            // v7.21.344 — REACH THE CACHED MODEL. Elementor handlers read
            // jQuery(el).data("settings") — a cached OBJECT that survives any attribute
            // rewrite (justmsp: replayed handler re-played fadeInUp from the cache after
            // the attribute was scrubbed). Mutate the cached object itself.
            if (window.jQuery) {
                var jd = window.jQuery(el).data("settings");
                if (jd && typeof jd === "object") { ks.forEach(function(k) { try { delete jd[k]; } catch (e2) {} }); }
            }
        } catch (e) {}
    };
    function wpcUnhideStuckInvisibleElements() {
        try {
            [].slice.call(document.querySelectorAll(".elementor-invisible")).forEach(function(el) {
                try {
                    if (getComputedStyle(el).visibility !== "hidden") { return; }
                    el.classList.remove("elementor-invisible");
                    el.style.visibility = "visible";
                    try { window.wpcPinVisible && window.wpcPinVisible(el, false); } catch (e3) {}
                    try { window.wpcScrubAnimationSettings(el); } catch (e3) {}
                } catch (e) {}
            });
        } catch (e) {}
    }
    setTimeout(wpcUnhideStuckInvisibleElements, 3000);
    // v7.21.299 — STRANDED-WIDGET BELT. Element Pack modules register their widget
    // handlers on jQuery's 'elementor/frontend/init' — an event Elementor fires ONCE,
    // and replay ordering can run the module after the firing: handler never registers,
    // element_ready never runs, the widget's .swiper never inits, and element-pack's
    // own [class*=" elementor-widget-bdt-"] .swiper:not(.swiper-initialized) conceal
    // holds FOREVER (beucomply Client Success Stories: a blue void where 10 reviews
    // live). Post-replay: re-fire init once (registers the late hooks), runReadyTrigger
    // each stranded widget; a beat later force-reveal anything still concealed so
    // content is NEVER missing. No-gesture lane gets the reveal-only pass (no network,
    // no jQuery churn — labs see readable content, not a void).
    var elementorInitRefired = false;
    var wpformsReadyRefired = false;
    function wpcReinitStrandedPlugins(revealOnly) {
        try {
            // .300 — same class, wpforms lane: field modules (phone/intl-tel) register on
            // the one-shot wpformsReady; a stranded phone field still carries its raw name
            // (no wpf-temp twin). Re-fire once — independent of the swiper lane below.
            if (!revealOnly && !wpformsReadyRefired && window.jQuery
                && document.querySelector(".wpforms-form input[type=tel]:not([name^='wpf-temp'])")) {
                wpformsReadyRefired = true;
                try { window.jQuery(document).trigger("wpformsReady"); } catch (e) {}
            }
            // .301 — third costume: free analytics mints per-form state BEFORE pro's
            // stateDefaults filter registers (jQuery-ready fires synchronously in replay,
            // the register-then-init phasing native defer provides is gone) — state lacks
            // .fields and every observer walk throws undefined['id']. Supply the shape
            // pro's addFieldsToState would have.
            try {
                if (!revealOnly && window.WPForms && window.WPForms.Analytics && window.WPForms.Analytics.getState) {
                    [].slice.call(document.querySelectorAll(".wpforms-form[data-formid]")).forEach(function(f) {
                        try {
                            var wpformsFormState = window.WPForms.Analytics.getState(parseInt(f.getAttribute("data-formid"), 10));
                            if (wpformsFormState && !wpformsFormState.fields) { wpformsFormState.fields = {}; }
                        } catch (e) {}
                    });
                }
            } catch (e) {}
            // v7.21.321 — GENERAL STRANDED-WIDGET HEAL (permanent, parity-preserving).
            // Every Elementor Pro interactive widget registers its handler on the one-shot
            // elementor/frontend/init; replay ordering can run the extension AFTER that fired,
            // so the handler never registers and the widget is dead — swipers (galleries/
            // carousels/media/video-sliders), Nested Tabs (videos-gallery "click below to play
            // in the top player"), legacy Tabs. .320 re-fired init ONLY when a stranded swiper
            // existed, so a swiper-less page with a stranded n-tabs/tabs never got the re-fire.
            // Now: re-fire init ONCE whenever Elementor is present (registers ALL late handlers),
            // then runReadyTrigger each UNINITIALIZED widget by its own reliable marker — so
            // already-initialized widgets are never re-triggered (no double-bound handlers) and
            // parity with the un-optimized site is exact. Video widgets are deliberately NOT
            // force-triggered: their autoplay/lazy materialization must stay native (no imposed
            // mute, no off-screen play) — they ride Elementor's own init once handlers register.
            var wpcElementorHandlerApiReady = function() {
                return !!(window.jQuery && window.elementorFrontend
                    && window.elementorFrontend.elementsHandler && window.elementorFrontend.elementsHandler.runReadyTrigger);
            };
            var wpcFireElementorInitOnce = function() {
                // v7.21.364 — was the second blanket re-fire of a one-shot (FIRE#2 in the
                // staging trace). The shared diff-fire is idempotent, so the once-guard is
                // belt-and-braces rather than the only thing preventing a triple-bind.
                if (elementorInitRefired || !wpcElementorHandlerApiReady()) { return; }
                elementorInitRefired = true;
                try { window.wpcFireMissedElementorInitHandlers && window.wpcFireMissedElementorInitHandlers(); } catch (e) {}
            };
            // Each family: an "uninitialized" selector + a per-widget confirm() so an already-
            // live widget self-excludes. The widget is stamped data-wpc-ready-triggered so it triggers
            // at most once, ever. Swiper self-excludes via .swiper-initialized (set synchronously
            // by runReadyTrigger); n-tabs/tabs confirm on their active-state marker.
            var widgetFamilies = [
                { sel: ".swiper:not(.swiper-initialized)", widget: ".elementor-widget", ok: function(w) { return true; } },
                { sel: ".elementor-widget-n-tabs:not([data-wpc-ready-triggered])", widget: ".elementor-widget-n-tabs", ok: function(w) { return !w.querySelector('[role="tab"][aria-selected="true"]'); } },
                { sel: ".elementor-widget-tabs:not([data-wpc-ready-triggered]) .elementor-tabs", widget: ".elementor-widget-tabs", ok: function(w) { return !w.querySelector(".elementor-tab-title.elementor-active"); } }
            ];
            var wpcRetriggerUninitializedWidgets = function() {
                if (!wpcElementorHandlerApiReady()) { return -1; }
                wpcFireElementorInitOnce();
                var n = 0;
                widgetFamilies.forEach(function(fam) {
                    [].slice.call(document.querySelectorAll(fam.sel)).forEach(function(el) {
                        try {
                            var w = el.closest ? el.closest(fam.widget) : null;
                            if (!w || w.getAttribute("data-wpc-ready-triggered") === "1" || !fam.ok(w)) { return; }
                            // v7.21.344 — a re-run of element_ready re-runs the ENTRANCE handler
                            // too: on justmsp the widget faded in twice (native play, then our
                            // re-trigger replayed fadeInUp on 1.5s-visible cards). Settle first:
                            // scrub the animation from attribute AND jQuery cache, strip the
                            // conceal class, pin visible with animation:none.
                            try {
                                if (w.classList.contains("elementor-invisible")) { w.classList.remove("elementor-invisible"); }
                                window.wpcScrubAnimationSettings && window.wpcScrubAnimationSettings(w);
                                window.wpcPinVisible && window.wpcPinVisible(w, false);
                            } catch (e4) {}
                            if (window.wpcRunReadyTriggerForElement && window.wpcRunReadyTriggerForElement(w)) { n++; }
                        } catch (e) {}
                    });
                });
                return n;
            };
            // v7.21.322 — MODULA PAIR HEAL (columbus /photo/ lightbox dead WITH plugin, fine
            // disabled). Modula core fires the one-shot jQuery event modula_api_after_init at
            // its ready-replay (synchronous under our replay); Modula PRO's document listener
            // — the one that constructs the lightbox + filters (config initLightbox =
            // 'modula_pro_init_lightbox' means core defers to pro) — registers only when
            // modula-pro.js lands moments later, so the event fires into the void and clicks
            // do nothing. Re-trigger per initialized gallery, gated on listener-present AND
            // no click delegation on the gallery element (provably missed); once pro
            // constructs, the click handler exists and the gate closes — idempotent without
            // any flag. Same register-then-init law as .301/.321, Modula costume.
            var wpcRetriggerModulaGalleries = function() {
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
            // v7.21.324 — DIVI GALLERY REVEAL (falknerei /tauben-und-kraehenproblem/ Bird Free
            // gallery: right column empty WITH plugin, fine disabled). Divi hides grid gallery
            // items by stylesheet default (.et_pb_gallery_grid .et_pb_gallery_item{display:none})
            // and ONLY its module init reveals the per-page set (inline display:block) + binds
            // pagination/lightbox. Under replay the gallery library registers after
            // et_pb_init_modules already ran — items stay hidden forever (register-then-init,
            // Divi costume; CDP receipt: identical matched rules both modes, only the inline
            // display differs). Gate = a grid gallery whose items are ALL display:none after
            // replay (provably missed); heal = one call to window.et_pb_init_modules() — Divi's
            // own publicly re-callable API (ajax loaders call it by design; live-proven: gallery
            // heals to native per_page reveal, menus not duplicated, zero errors).
            var diviModulesReinited = false;
            var wpcReinitDiviGalleries = function() {
                try {
                    if (diviModulesReinited || typeof window.et_pb_init_modules !== "function") { return; }
                    var gs = [].slice.call(document.querySelectorAll(".et_pb_gallery_grid"));
                    if (!gs.length) { return; }
                    var stranded = gs.some(function(g) {
                        var its = [].slice.call(g.querySelectorAll(".et_pb_gallery_item"));
                        return its.length > 0 && its.every(function(it) {
                            return getComputedStyle(it).display === "none";
                        });
                    });
                    if (!stranded) { return; }
                    diviModulesReinited = true;
                    window.et_pb_init_modules();
                } catch (e) {}
            };
            // v7.21.321 — POPUP BOOT-ORDER HEAL (columbusepoxyflooring: hidden popup played
            // YouTube AUDIO with no visible player; popup triggers dead on service pages).
            // Elementor Pro's popup module subscribes to TWO one-shot lifecycle moments in its
            // constructor — the documents-manager init-classes hook and the components:init
            // emitter. Under replay, Pro can boot AFTER elementorFrontend.init already fired
            // both: the popup document class never registers, popups stay attached in-flow as
            // 'base' documents (their autoplay video gets readied and plays, display:none and
            // all), and popup:open/off_canvas:* URL actions never land (clicks dead).
            // documentClasses.popup missing PROVES the whole Pro components:init wave missed
            // (same constructor registers both), so re-running each module's
            // onFrontendComponentsInit is a pure diff-replay of provably-missed callbacks —
            // urlActions.addAction overwrites by key, so it is idempotent by construction.
            // Then stuck popup docs are re-attached so Pro adopts them natively (detaches from
            // flow, killing the rogue player); popup content is only ever readied again by
            // Pro's own popup-open flow = exact parity with the un-optimized site.
            // v7.21.347 — E-GALLERY TERMINAL PAINT (columbus cleanroom: 6 items visible-empty
            // WITH plugin, painted with disableWPC). EGallery lazyload sets inline
            // background-image from data-thumbnail on Image.onload with NO onerror/retry;
            // under replay the per-item loading latch strands unpainted items (variant-
            // dependent; runReadyTrigger re-construct measured NOT to repaint). The belt is
            // the lib's own paint verbatim: fill only what is still empty — native wins
            // every race, pixel parity by construction.
            var wpcGalleryThumbObserver = null;
            var wpcPaintGalleryThumb = function(el) {
                try {
                    var bg = getComputedStyle(el).backgroundImage;
                    if (bg && bg !== "none") { return; }
                    var th = el.getAttribute("data-thumbnail");
                    if (!th || !/^https?:/i.test(th)) { return; }
                    el.style.backgroundImage = 'url("' + th.replace(/"/g, '%22') + '")';
                    el.classList.add("e-gallery-image-loaded");
                } catch (e) {}
            };
            var wpcFillUnpaintedGalleryThumbs = function() {
                try {
                    if (!wpcGalleryThumbObserver && typeof window.IntersectionObserver === "function") {
                        wpcGalleryThumbObserver = new IntersectionObserver(function(entries) {
                            entries.forEach(function(en) {
                                if (en.isIntersecting) {
                                    wpcGalleryThumbObserver.unobserve(en.target);
                                    wpcPaintGalleryThumb(en.target);
                                }
                            });
                        });
                    }
                    var vh = window.innerHeight || 800;
                    [].slice.call(document.querySelectorAll(".e-gallery-image[data-thumbnail]")).forEach(function(el) {
                        try {
                            var bg = getComputedStyle(el).backgroundImage;
                            if (bg && bg !== "none") { return; }
                            var r = el.getBoundingClientRect();
                            if (r.width > 0 && r.bottom >= 0 && r.top <= vh) { wpcPaintGalleryThumb(el); }
                            else if (wpcGalleryThumbObserver) { wpcGalleryThumbObserver.observe(el); }
                            else { wpcPaintGalleryThumb(el); }
                        } catch (e) {}
                    });
                } catch (e) {}
            };
            // v7.21.347b — YT IFRAME-API ONE-SHOT (columbus /videos-gallery/: Smash Balloon
            // top player never builds; clicks below swap nothing). Native order defines
            // window.onYouTubeIframeAPIReady BEFORE the API script loads; replay inverted it,
            // the API called a not-yet-existing global once and never again (YT.loaded=1,
            // callback defined, players empty — measured; calling it builds sby_player0).
            // Gate = provably missed: API ready + callback present + an sby player wrap
            // still EMPTY. One call, exactly what the API would have done; if it fired
            // naturally the wraps have children and the gate never opens.
            var youtubeApiRearmed = false;
            var youtubeApiTries = 0;
            var wpcRearmYoutubeApiReady = function() {
                try {
                    if (youtubeApiRearmed || youtubeApiTries++ > 15) { return; }
                    // the API lands late in replay: re-arm until it exists or tries run out
                    if (!window.YT || window.YT.loaded !== 1 || typeof window.onYouTubeIframeAPIReady !== "function") {
                        setTimeout(wpcRearmYoutubeApiReady, 800);
                        return;
                    }
                    var empty = [].slice.call(document.querySelectorAll(".sby_player_wrap")).some(function(w) { return !w.querySelector("iframe,video"); });
                    if (!empty) { return; }
                    youtubeApiRearmed = true;
                    window.onYouTubeIframeAPIReady();
                } catch (e) {}
            };
            var wpcRegisterPopupDocumentClass = function() {
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
            // Full lane: register late handlers unconditionally, then heal stranded widgets.
            if (!revealOnly) { wpcFireElementorInitOnce(); wpcRetriggerUninitializedWidgets(); wpcRegisterPopupDocumentClass(); wpcRetriggerModulaGalleries(); wpcReinitDiviGalleries(); }
            setTimeout(wpcFillUnpaintedGalleryThumbs, 1200);
            setTimeout(wpcRearmYoutubeApiReady, 1200);
            setTimeout(function() {
                if (!revealOnly) { wpcRetriggerUninitializedWidgets(); wpcRegisterPopupDocumentClass(); wpcRetriggerModulaGalleries(); wpcReinitDiviGalleries(); } // re-trigger any that still didn't take
                wpcFillUnpaintedGalleryThumbs();
                [].slice.call(document.querySelectorAll(".swiper:not(.swiper-initialized)")).forEach(function(sw) {
                    try {
                        var cs = getComputedStyle(sw);
                        if (cs.visibility === "hidden" || cs.opacity === "0") { sw.style.visibility = "visible"; sw.style.opacity = "1"; }
                    } catch (e) {}
                });
            }, 1600);
            setTimeout(function() {
                // v364 third pass: the door may hold both earlier passes while Elementor's
                // own late-registration replay gets its turn; on legacy (no replay ever)
                // the door opens 3s after the registrar invocation — this pass collects it.
                if (!revealOnly) { wpcRetriggerUninitializedWidgets(); }
            }, 6200);
        } catch (e) {}
    }
    // v7.21.312 — OWNED CLASSES ARE STATE, NOT ONE-TIME ADDS: a delayed theme script
    // replaying documentElement.className = 'js' (falknerei no-js swap) wiped
    // wpc-bgl255 the same tick the gesture armed it — every :where(html.wpc-bgl255)
    // bg park stayed dead (blank Divi loop cards). Every class the loader owns is
    // re-asserted by a class-attribute observer whenever any writer clobbers it.
    window.wpcOwnedRootClasses = window.wpcOwnedRootClasses || {};
    window.wpcOwnRootClass = window.wpcOwnRootClass || function(c) {
        window.wpcOwnedRootClasses[c] = 1;
        try { document.documentElement.classList.add(c); } catch (e) {}
    };
    try {
        if (!window.wpcOwnedClassObserverInstalled) {
            window.wpcOwnedClassObserverInstalled = 1;
            new MutationObserver(function() {
                var de = document.documentElement;
                for (var c in window.wpcOwnedRootClasses) {
                    if (!de.classList.contains(c)) { de.classList.add(c); }
                }
            }).observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
        }
    } catch (e) {}
    try {
        window.addEventListener("wpc-scripts-loaded", function() {
            // .303 — never yank an OPEN shim dropdown: stamping mid-hover killed the
            // :hover rule before smartmenus re-opens on the NEXT pointer event (open ->
            // blink closed -> reopen). Defer the stamp until the pointer leaves the menu.
            var wpcStampJsLiveAfterHover = function() {
                try {
                    if (document.querySelector(".elementor-nav-menu li.menu-item-has-children:hover")) {
                        setTimeout(wpcStampJsLiveAfterHover, 250);
                        return;
                    }
                    wpcOwnRootClass("wpc-js-live");
                } catch (e) {
                    try { wpcOwnRootClass("wpc-js-live"); } catch (x) {}
                }
            };
            wpcStampJsLiveAfterHover();
            setTimeout(function() { wpcReinitStrandedPlugins(false); }, 1200);
        });
    } catch (e) {}
    // v7.23.15 — cssOnly: nothing is delayed, the site's handlers are live from parse, so the
    // nav-hover shim (html:not(.wpc-js-live)) retires at boot instead of waiting for a replay
    // that will never come.
    if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssOnly === 1) { try { wpcOwnRootClass("wpc-js-live"); } catch (e) {} }
    setTimeout(function() { if (!gestureSeen) { wpcReinitStrandedPlugins(true); } }, 4000);
    var gestureSeen = false, gesturePending = false, gestureQueue = [];
    function wpcOnFirstGesture(f) {
        if (gestureSeen) { f(); return; }
        gestureQueue.push(f);
    }
    (function() {
        var gestureEvents = [ "pointerdown", "keydown", "touchstart", "scroll", "mousemove", "wheel", "click" ];
        var fireGestureQueue = function(ev, again) {
            if (gestureSeen || gesturePending) { return; }
            if (ev && ev.isTrusted === false && !ev.__wpcOwn && window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.trusted === 1) { return; }
            if (ev && typeof window.wpcMenusSnapshot === "function") { window.wpcMenusSnapshot(); }
            if (ev && window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.trusted === 1 && /^(?:pointerdown|touchstart|keydown|click|wheel)$/.test(ev.type) && ev.isTrusted !== false && typeof window.wpcHardNote === "function") { window.wpcHardNote(); }
            if (ev && !again && typeof window.wpcTapDefer === "function" && window.wpcTapDefer(ev, "g", function() { fireGestureQueue(ev, 1); })) { return; }
            gesturePending = true;
            gestureEvents.forEach(function(ev) { try { window.removeEventListener(ev, fireGestureQueue, true); } catch (e) {} });
            // v7.23.15 — cssOnly: the first gesture IS the engagement evidence. A delay page sets
            // __wpcEngaged when the gesture-triggered replay completes (wpc-scripts-loaded), which
            // never fires without a replay; without this the crit sweep's engagement gate would hold
            // the crit block forever on a direct desktop visit (no referrer, no link hover).
            // The repeat-visitor stamp is written at replay completion on a delay page; here the
            // first gesture is that moment, or the evidenced-visit kick above never fires again.
            if (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssOnly === 1) {
                window.__wpcEngaged = 1;
                try {
                    var wpcCssStamp = function() { localStorage.setItem("fresh", String(Date.now())); };
                    if (+window.wpcDelayV3Cfg.trusted !== 1 || (ev && ev.isTrusted !== false && /^(?:pointerdown|touchstart|keydown|click|wheel)$/.test(ev.type))) { wpcCssStamp(); }
                    else {
                        var wpcCssHard = [ "pointerdown", "touchstart", "keydown", "click", "wheel" ], wpcCssOn = function(h) {
                            if (h.isTrusted === false) { return; }
                            wpcCssHard.forEach(function(t) { window.removeEventListener(t, wpcCssOn, true); });
                            try { wpcCssStamp(); } catch (e) {}
                        };
                        wpcCssHard.forEach(function(t) { window.addEventListener(t, wpcCssOn, { passive: true, capture: true }); });
                    }
                } catch (e) {}
            }
            var flush = function() {
                gesturePending = false;
                if (gestureSeen) { return; }
                gestureSeen = true;
                wpcOwnRootClass("wpc-bgl255");
                gestureQueue.splice(0).forEach(function(f) { try { f(); } catch (e) {} });
                setTimeout(function() { wpcRestoreParkedImages(true); }, 400);
                setTimeout(wpcUnhideStuckInvisibleElements, 2500);
            };
            if (!again && typeof window.wpcAfterInput === "function") { window.wpcAfterInput(flush, ev); } else { flush(); }
        };
        gestureEvents.forEach(function(ev) { window.addEventListener(ev, fireGestureQueue, { passive: true, capture: true }); });
        // v7.22.29 — A GESTURE BEFORE THE LOADER IS STILL A GESTURE. The head arm records the
        // visitor's first movement as html.wpc-bgl255; a loader arriving after it (slow link,
        // 80KB body-end script) must honour it or the parked CSS waits for a movement that may
        // never come (lawyerscolumbusohio under throttle: css-live true, 8 sheets parked at 12s).
        if ((typeof window.pageYOffset === "number" ? window.pageYOffset : 0) > 0
            || (window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.releaseNeedsGesture === 1 ? window.wpcGestureSeen === 1 : window.wpcGestureSeen === 1 || document.documentElement.classList && document.documentElement.classList.contains("wpc-bgl255"))) { fireGestureQueue(); }
    })();
    // v7.22.29 — CSS-LIVE MEANS ALL CSS. The class is claimed by two writers: the used-css boot
    // (rest attached) and this loader (parked restored). Either alone let JS replay on a
    // half-styled document. The loader's two success paths now wait for the rest link to
    // carry its href (capped at 4s so nothing is withheld forever); the boot side waits for
    // the parked links to be gone.
    function wpcMarkCssLiveWhenRestAttached() {
        try {
            if (!document.querySelector('link[data-wpc-rest]:not([href])')) { wpcOwnRootClass("wpc-css-live"); return; }
            if (wpcMarkCssLiveWhenRestAttached.w) { return; }
            wpcMarkCssLiveWhenRestAttached.w = 1;
            var iv = setInterval(function() {
                if (!document.querySelector('link[data-wpc-rest]:not([href])')) { clearInterval(iv); wpcOwnRootClass("wpc-css-live"); }
            }, 60);
            setTimeout(function() { clearInterval(iv); wpcOwnRootClass("wpc-css-live"); }, 4000);
        } catch (e) { wpcOwnRootClass("wpc-css-live"); }
    }
    var wpcFlipLiveSeen = function(e) {
        var l = e && e.target;
        l && "LINK" === l.tagName && "print" !== l.getAttribute("media") && (l.__wpcLive = 1);
    };
    try {
        var wpcFlipLiveAdd = window.wpcNativeAddEventListener || EventTarget.prototype.addEventListener;
        wpcFlipLiveAdd.call(document, "load", wpcFlipLiveSeen, !0);
        wpcFlipLiveAdd.call(document, "error", wpcFlipLiveSeen, !0);
    } catch (z) {}
    function wpcFlipLive(l) {
        if (!l.hasAttribute("data-wpc-flip") || l.__wpcLive || window.wpcDelayV3Cfg && 1 === +window.wpcDelayV3Cfg.retireLiveOff) return !0;
        var mq = l.getAttribute("media") || "";
        if (mq && "all" !== mq && "print" !== mq) try {
            if (window.matchMedia && !window.matchMedia(mq).matches) return !0;
        } catch (z) {}
        var since = l.__wpcLiveSince || (l.__wpcLiveSince = Date.now());
        return Date.now() - since >= 4e3;
    }
    function wpcRestRetireReady() {
        var parked = [].slice.call(document.querySelectorAll('link[rel^="wpc-"],style[type^="wpc-"],style[type^="wpc/"],link[data-wpc-tm],link[data-wpc-lf]'));
        for (var i = 0; i < parked.length; i++) {
            var el = parked[i];
            if (el.hasAttribute("data-wpc-ucss") || el.hasAttribute("data-wpc-ucss-rest")) { continue; }
            if (el.tagName.toLowerCase() === "link" && /^wpc-/.test(el.getAttribute("rel") || "") === false) {
                if (el.hasAttribute("data-wpc-lf") && el.getAttribute("href") && el.getAttribute("media") !== "print") { continue; }
                if (el.hasAttribute("data-wpc-tm") && !el.hasAttribute("data-wpc-lf") && el.getAttribute("media") !== "print") { continue; }
            }
            return false;
        }
        var live = [].slice.call(document.querySelectorAll('link[rel="stylesheet"]'));
        for (var j = 0; j < live.length; j++) {
            var l = live[j];
            if (l.hasAttribute("data-wpc-ucss") || l.hasAttribute("data-wpc-ucss-rest") || !l.getAttribute("href")) { continue; }
            if (l.getAttribute("media") === "print" && l.hasAttribute("data-wpc-flip")) { return false; }
            if (!l.sheet && !l.__wpcLive) { return false; }
            if (!wpcFlipLive(l)) { return false; }
        }
        return true;
    }
    function wpcCritCopies() {
        if (!window.wpcDelayV3Cfg || +window.wpcDelayV3Cfg.critRetire !== 1) { return []; }
        if (document.querySelector("link[data-wpc-ucss]")) { return []; }
        return [].slice.call(document.querySelectorAll("style#wpc-critical-css,style#wpc-header-css-slice")).filter(function(s) {
            return !s.hasAttribute("data-wpc-retired");
        });
    }
    function wpcKeepFacesOfCopy(copy) {
        try {
            var faces = String(copy.textContent || "").match(/@font-face\s*\{[^}]*\}/gi);
            if (!faces || !faces.length) { return; }
            var carrier = document.getElementById("wpc-crit-faces");
            if (!carrier) {
                carrier = document.createElement("style");
                carrier.id = "wpc-crit-faces";
                (copy.parentNode || document.head).insertBefore(carrier, copy);
            }
            var held = String(carrier.textContent || "");
            var missing = faces.filter(function(f) { return held.indexOf(f) === -1; });
            if (missing.length) { carrier.textContent = held + missing.join(""); }
        } catch (e) {}
    }
    function wpcKeepSliderRulesOfCopy(copy) {
        try {
            var re = /:not\(\.n2-ss-loaded\)|ss3-force-full-width/;
            var collect = function(rules, depth) {
                var out = [];
                for (var i = 0; i < rules.length; i++) {
                    var r = rules[i];
                    if (r.selectorText) {
                        if (re.test(r.selectorText)) { out.push(r.cssText); }
                    } else if (r.cssRules && depth < 3) {
                        var inner = collect(r.cssRules, depth + 1), text = String(r.cssText || ""), at = text.indexOf("{");
                        if (inner.length && at > 0) { out.push(text.substring(0, at).replace(/\s+$/, "") + "{" + inner.join("") + "}"); }
                    }
                }
                return out;
            };
            var keep = collect((copy.sheet && copy.sheet.cssRules) || [], 0);
            if (!keep.length) { return; }
            var carrier = document.getElementById("wpc-crit-keep");
            if (!carrier) {
                carrier = document.createElement("style");
                carrier.id = "wpc-crit-keep";
                (copy.parentNode || document.head).insertBefore(carrier, copy);
            }
            var held = String(carrier.textContent || "");
            var missing = keep.filter(function(t) { return held.indexOf(t) === -1; });
            if (missing.length) { carrier.textContent = held + missing.join(""); }
        } catch (e) {}
    }
    function wpcRetireRestWhenOriginalsLive() {
        if (wpcRetireRestWhenOriginalsLive.armed) { return; }
        wpcRetireRestWhenOriginalsLive.armed = 1;
        var started = Date.now();
        var attempt = function() {
            try {
                var rest = [].slice.call(document.querySelectorAll("link[data-wpc-ucss-rest]")).filter(function(r) {
                    var q = r.getAttribute("data-wpc-ucss-rest") || "all";
                    if (q === "all" || !window.matchMedia) { return true; }
                    try { return window.matchMedia(q).matches; } catch (e) { return true; }
                });
                var copies = wpcCritCopies();
                if (!rest.length && !copies.length) { return true; }
                if (document.readyState !== "complete") { return false; }
                for (var k = 0; k < rest.length; k++) {
                    if (!rest[k].getAttribute("href") || rest[k].getAttribute("media") === "print" || !rest[k].sheet) { return false; }
                }
                if (!wpcRestRetireReady()) { return false; }
                if (copies.length && !document.documentElement.classList.contains("wpc-css-live")) { return false; }
                wpcSwapNtOn();
                try {
                    rest.forEach(function(r) { r.setAttribute("media", "not all"); r.setAttribute("data-wpc-rest-retired", "1"); });
                    wpcRestFocusGuardDrop();
                    copies.forEach(function(s) {
                        wpcKeepFacesOfCopy(s);
                        wpcKeepSliderRulesOfCopy(s);
                        s.setAttribute("media", "not all");
                        s.setAttribute("data-wpc-retired", "1");
                    });
                } finally {
                    wpcSwapNtEnd();
                }
                return true;
            } catch (e) { return true; }
        };
        if (attempt()) { return; }
        var iv = setInterval(function() {
            if (attempt()) { clearInterval(iv); return; }
            if (Date.now() - started > 3e4) { clearInterval(iv); wpcRetireRestWhenOriginalsLive.armed = 0; }
        }, 250);
    }
    window.wpcRetireRestWhenOriginalsLive = wpcRetireRestWhenOriginalsLive;
    // v7.21.282 — CAPTURED, NOT LIVE-QUERIED: the rest-boot consumes its link markers
    // after arming, so a live querySelector here went false post-load and the 2.5s
    // barrier timer restored the parked css + fonts with zero interaction (staging FL
    // 1.1s -> 3.2s). Presence at loader parse is the durable fact.
    // .286 — the links emit at BODY-END and the loader parses before them: parse-time
    // capture read false, and by the 2.5s barrier the rest-boot had consumed the
    // markers, so the live query was false too (staging mobile: cmb+fonts restored on
    // the timer, 276KB back on the lab wire). STICKY capture, re-evaluated at DCL
    // (after body parse, before the boot consumes) and on every gate call until true.
    var usedCssPresent = !!document.querySelector("link[data-wpc-ucss],link[data-wpc-ucss-rest]");
    function wpcMarkUsedCssPresent() {
        if (!usedCssPresent) {
            try { usedCssPresent = !!document.querySelector("link[data-wpc-ucss],link[data-wpc-ucss-rest]"); } catch (e) {}
        }
    }
    if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", wpcMarkUsedCssPresent); } else { wpcMarkUsedCssPresent(); }
    // v7.21.294 — NO-USED-CSS SITES RIDE THE SAME GATE. Without a used-css template the
    // restores used to run on their own short timers (the lab-visible tail); now they
    // queue for gesture like everywhere else, and this belt flushes the queue at 8s of
    // zero interaction so a never-gesturing visitor (bots, deep-links, idle reads) still
    // ends fully styled. Gesture always wins the race; used-css sites never reach here.
    // .295 — the 8s timer LANDED INSIDE THE LAB WINDOW (datafield GTmetrix: TTI 9.6s,
    // Fully Loaded 9.7s — the trace waited for the wave and TBT's window stretched with
    // it). No timer: a human cannot SEE below-fold content without a gesture (anchor
    // deep-links fire via the pageYOffset>0 boot), so the only gesture-less viewers are
    // labs — exactly who must never see the wave. The belt now flushes on visibility
    // loss instead: a reader who backgrounds the tab returns to a styled page. A timer
    // remains available as an explicit opt-in (noUcssBeltMs cfg), never a default.
    var noUsedCssFlushed = false;
    function wpcFlushGestureQueueWithoutUsedCss() {
        if (noUsedCssFlushed || gestureSeen || usedCssPresent) { return; }
        wpcMarkUsedCssPresent();
        if (usedCssPresent) { return; }
        noUsedCssFlushed = true;
        gestureSeen = true;
        wpcOwnRootClass("wpc-bgl255");
        gestureQueue.splice(0).forEach(function(f) { try { f(); } catch (e) {} });
        setTimeout(function() { wpcRestoreParkedImages(true); }, 400);
    }
    // Registered via the on-PROPERTY, not addEventListener: visibilitychange is one of
    // the four events our own AEL patch captures into the replay registry, so a patched
    // registration only fires post-gesture — the void, exactly when it's useless. The
    // property lane bypasses the patch; the page's own handler (rare) is chained.
    try {
        var previousVisibilityHandler = document.onvisibilitychange;
        document.onvisibilitychange = function(ev) {
            try { if (previousVisibilityHandler) { previousVisibilityHandler.call(this, ev); } } catch (e) {}
            if (document.visibilityState === "hidden") { wpcFlushGestureQueueWithoutUsedCss(); }
        };
    } catch (e) {}
    if (+((window.wpcDelayV3Cfg || {}).noUcssBeltMs) > 0) {
        setTimeout(wpcFlushGestureQueueWithoutUsedCss, +window.wpcDelayV3Cfg.noUcssBeltMs);
    }
    var cssIdleReleased = false;
    function wpcReleaseCssAtIdle() {
        if (cssIdleReleased || gestureSeen || !(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.cssReleaseIdle === 1)) { return; }
        cssIdleReleased = true;
        wpcOwnRootClass("wpc-bgl255");
        gestureQueue.splice(0).forEach(function(f) { try { f(); } catch (e) {} });
    }
    window.wpcReleaseCssAtIdle = wpcReleaseCssAtIdle;
    function wpcRestoreNeedsGesture() {
        wpcMarkUsedCssPresent();
        return !gestureSeen && !cssIdleReleased && (usedCssPresent || !noUsedCssFlushed)
            && !(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.restoreGesture === 0);
    }
    window.wpcRestoreNeedsGesture = wpcRestoreNeedsGesture;
    // v7.24.05 — A TRAILING INLINE CARRIER NEVER WAITS FOR A GESTURE. The gesture gate exists
    // to keep the parked sheets off the lab wire, and the atomic restore exists so no carrier
    // applies while a LATER sheet that overrides it is still parked. An inline block (or its
    // sidecar) with no parked sheet after it in document order is the last word in the cascade
    // either way; holding it only hides page-scoped rules the crit missed until the visitor
    // moves (wpcompress.com/secret-offer: .wlx .mps{display:none} lived only in the 86KB body
    // block, sidecar'd and parked, so the mobile plan row rendered above the fold as bare text
    // until the first mousemove). Released on the same LCP/interaction/3s path as the crit swap.
    //
    // A carrier is released early only when it carries an above-the-fold rule the crit lacks:
    // a rule whose selector matches an element in the viewport and is not among the crit's own
    // selectors (secret-offer's .wlx .mps). A carrier whose ATF rules the crit already has waits
    // with the parked sheets: webdesign4u.com.au (2026-09-28), the Divi module-design sidecar
    // (91 KB) and the Divi Toolbox sidecar were released at load for rules the crit carried, and
    // their Font Awesome rules pulled three FA woff2 files onto the load (39 requests / 2.8 MB
    // against 24 / 1.3 MB on 7.24.04). The test reads the carrier into a constructed sheet,
    // which is never adopted, so nothing it declares applies or fetches. Anything it cannot
    // answer (no crit tag, no constructable sheets, a failed read, an @import, too many rules)
    // releases as before, so the secret-offer fix can never regress.
    //
    // The question is "would this rule change what is painted before a gesture", so a rule
    // that needs a user action (:hover, :focus, :active, :visited, :focus-within,
    // :focus-visible) is skipped, not stripped and probed, and an element counts only when it
    // is rendered and visible. wpcompress.com/pricing (2026-09-29): the crit carries no
    // interaction states, so every .btn:hover rule for a visible button read as missing, and
    // the closed checkout modal (position:fixed, visibility:hidden, opacity:0; its ribbon
    // display:none, measured through its parent's box) read as in view; the 44 KB sidecar was
    // released at ~350 ms, its relayout repainted the fading consent card into a later LCP
    // candidate and pulled three CircularStd files onto the load: PSI mobile 87-93 against 100
    // on 7.24.04.
    var wpcTrailingCarrier = 0;
    function wpcCarrierRuleIsMissingAtf(sheet, critSelectors) {
        var vh = window.innerHeight || 0, vw = window.innerWidth || 0, seen = 0, t0 = Date.now();
        var isRendered = function(el) {
            if (typeof el.checkVisibility === "function") {
                return el.checkVisibility({ opacityProperty: true, visibilityProperty: true });
            }
            // visibility inherits, so the element's own value answers; display and opacity do
            // not, so every ancestor is read.
            var style = window.getComputedStyle(el);
            if (style.visibility === "hidden" || style.visibility === "collapse") { return false; }
            for (var node = el; node && node.nodeType === 1; node = node.parentElement) {
                var nodeStyle = window.getComputedStyle(node);
                if (nodeStyle.display === "none" || +nodeStyle.opacity === 0) { return false; }
            }
            return true;
        };
        var inView = function(el) {
            if (!isRendered(el)) { return false; }
            // A rendered element with an empty box (an icon or spacer the carrier sizes) is
            // placed by its parent; a hidden one has no place and never borrows one.
            var r = el.getBoundingClientRect();
            if (!r.width && !r.height && el.parentElement) { r = el.parentElement.getBoundingClientRect(); }
            return (r.width || r.height) && r.bottom > 0 && r.top < vh && r.right > 0 && r.left < vw;
        };
        var walk = function(rules) {
            for (var i = 0; i < rules.length; i++) {
                var rule = rules[i];
                if (++seen > 4000 || Date.now() - t0 > 150) { return true; }
                if (rule.cssRules && !rule.selectorText) {
                    if (rule.media && !window.matchMedia(rule.media.mediaText).matches) { continue; }
                    if (walk(rule.cssRules)) { return true; }
                    continue;
                }
                if (!rule.selectorText) { continue; }
                var parts = rule.selectorText.split(",");
                for (var p = 0; p < parts.length; p++) {
                    var selector = parts[p].trim().replace(/\s+/g, " ");
                    if (critSelectors[selector]) { continue; }
                    if (/:(hover|focus|active|visited|focus-within|focus-visible)\b/.test(selector)) { continue; }
                    var probe = selector.replace(/::?(before|after|placeholder|selection|marker|first-line|first-letter)\b/g, "");
                    var els;
                    try { els = document.querySelectorAll(probe || "*:not(*)"); } catch (e) { continue; }
                    for (var k = 0; k < els.length && k < 10; k++) {
                        if (inView(els[k])) { return true; }
                    }
                }
            }
            return false;
        };
        return walk(sheet.cssRules);
    }
    function wpcCarrierDecide(el, critSelectors, release) {
        try {
            if (!critSelectors || typeof CSSStyleSheet !== "function" || !CSSStyleSheet.prototype.replaceSync) { release(); return; }
            var judge = function(text) {
                try {
                    var sheet = new CSSStyleSheet();
                    sheet.replaceSync(String(text || ""));
                    if (/@import/i.test(text) || wpcCarrierRuleIsMissingAtf(sheet, critSelectors)) { release(); }
                } catch (e) { release(); }
            };
            if (el.tagName.toLowerCase() !== "link") { judge(el.textContent); return; }
            if (!window.fetch) { release(); return; }
            fetch(el.href, { credentials: "same-origin" }).then(function(r) {
                if (!r.ok) { throw new Error("status"); }
                return r.text();
            }).then(judge, release);
        } catch (e) { release(); }
    }
    function wpcCritSelectors() {
        try {
            var crit = document.getElementById("wpc-critical-css");
            if (!crit || !crit.sheet) { return null; }
            var out = {};
            var add = function(rules) {
                for (var i = 0; i < rules.length; i++) {
                    if (rules[i].cssRules && !rules[i].selectorText) { add(rules[i].cssRules); continue; }
                    if (!rules[i].selectorText) { continue; }
                    rules[i].selectorText.split(",").forEach(function(s) { out[s.trim().replace(/\s+/g, " ")] = 1; });
                }
            };
            add(crit.sheet.cssRules);
            return out;
        } catch (e) { return null; }
    }
    function wpcReleaseTrailingCarrier() {
        if (wpcTrailingCarrier) { return; }
        wpcTrailingCarrier = 1;
        try {
            var all = [].slice.call(document.querySelectorAll('link[rel="wpc-stylesheet"],link[rel="wpc-mobile-stylesheet"],link[rel="wpc-late-stylesheet"],link[data-wpc-tm][media="print"],style[type="wpc-stylesheet"],style[type="wpc-mobile-stylesheet"],style[type="wpc-late-stylesheet"]'));
            var i = all.length;
            while (i-- > 0) {
                if (all[i].tagName.toLowerCase() === "link" && all[i].getAttribute("data-wpc-sc") !== "1") { break; }
            }
            var critSelectors = wpcCritSelectors();
            for (var j = i + 1; j < all.length; j++) {
                (function(el) {
                    if (el.id && el.id.indexOf("wpc-") === 0) { return; }
                    if (el.hasAttribute("data-wpc-hold")) { return; }
                    wpcCarrierDecide(el, critSelectors, function() {
                        // A gesture may have restored it meanwhile; release only a still-parked carrier.
                        if (el.tagName.toLowerCase() === "link") {
                            if (/^wpc-/.test(el.getAttribute("rel") || "")) { el.__wpcSwapOnLoad = 1; wpcSwapNtHold(el); el.setAttribute("rel", "stylesheet"); wpcOwnRootClass("wpc-trail05"); }
                        } else if (/^wpc-/.test(el.getAttribute("type") || "")) {
                            wpcSwapNtOn(); el.setAttribute("type", "text/css"); wpcSwapNtEnd(); wpcOwnRootClass("wpc-trail05");
                        }
                    });
                })(all[j]);
            }
        } catch (e) {}
    }
    // A Google/Bunny Fonts sheet whose families' first-screen faces (cfg.gfFirst) are all inline in #wpc-font-faces waits with the parked sheets.
    var wpcGfInline = null;
    function wpcGfWeight(w) {
        w = String(w || "400").toLowerCase();
        if (w === "bold") { return 700; }
        if (w === "normal") { return 400; }
        return parseInt(w, 10) || 400;
    }
    function wpcGfName(f) {
        return String(f || "").replace(/["']/g, "").replace(/[-\s]+/g, " ").trim().toLowerCase();
    }
    function wpcGfFaceRows(css, late) {
        var re = /@font-face\s*\{([^{}]*)\}/gi, m;
        while ((m = re.exec(css))) {
            var body = m[1];
            if (!/url\(/i.test(body)) { continue; }
            var fam = wpcGfName((body.match(/font-family\s*:\s*([^;]+)/i) || [])[1]);
            if (!fam || / fallback$/.test(fam)) { continue; }
            var w = ((body.match(/font-weight\s*:\s*([^;]+)/i) || [])[1] || "400").trim().split(/\s+/);
            wpcGfInline.push([fam, wpcGfWeight(w[0]), wpcGfWeight(w[w.length - 1]), /font-style\s*:\s*(?:italic|oblique)/i.test(body) ? "italic" : "normal", late]);
        }
    }
    function wpcGfInlineFaces() {
        if (wpcGfInline) { return wpcGfInline; }
        wpcGfInline = [];
        try {
            var block = document.getElementById("wpc-font-faces");
            wpcGfFaceRows(block ? (block.textContent || "") : "", 0);
            var late = document.getElementById("wpc-late-faces");
            if (late && late.tagName === "STYLE" && (document.getElementById("wpc-cfg-lf") || document.getElementById("wpc-late-faces-flip"))) {
                wpcGfFaceRows(late.textContent || "", 1);
            }
        } catch (e) {}
        return wpcGfInline;
    }
    function wpcGfFamilies(href) {
        var q = String(href || "").split("#")[0], at = q.indexOf("?"), out = [];
        if (at < 0 || !/\/\/(?:fonts\.googleapis\.com\/css|fonts\.bunny\.net\/css)/i.test(q)) { return null; }
        try {
            q.substring(at + 1).split("&").forEach(function(part) {
                var kv = part.split("=");
                if (kv[0] !== "family" || !kv[1]) { return; }
                decodeURIComponent(kv[1].replace(/\+/g, " ")).split("|").forEach(function(f) {
                    f = wpcGfName(f.split(":")[0]);
                    if (f) { out.push(f); }
                });
            });
        } catch (e) { return null; }
        return out.length ? out : null;
    }
    function wpcGfHeld(href) {
        var cfg = window.wpcDelayV3Cfg;
        if (!cfg || +cfg.gfHold !== 1 || !cfg.gfFirst || !(cfg.gfFirst.length > 0)) { return false; }
        var fams = wpcGfFamilies(href);
        if (!fams) { return false; }
        var faces = wpcGfInlineFaces(), late = false;
        for (var i = 0; i < cfg.gfFirst.length; i++) {
            var t = String(cfg.gfFirst[i]).split("|"), fam = wpcGfName(t[0]), w = wpcGfWeight(t[1]), st = t[2] === "italic" ? "italic" : "normal", eager = false, byLate = false;
            if (fams.indexOf(fam) < 0) { continue; }
            for (var j = 0; j < faces.length; j++) {
                if (faces[j][0] === fam && faces[j][3] === st && w >= faces[j][1] && w <= faces[j][2]) {
                    if (faces[j][4] === 1) { byLate = true; } else { eager = true; }
                }
            }
            if (!eager && !byLate) { return false; }
            late = late || !eager;
        }
        return late ? 2 : true;
    }
    function wpcReleaseFontSheets() {
        try {
            var fontSheet = /\/\/(?:fonts\.googleapis\.com\/css|fonts\.bunny\.net\/css|use\.typekit\.net\/)|\/omgf\/|\/google-fonts\/css\//i;
            document.querySelectorAll('link[rel="wpc-stylesheet"],link[rel="wpc-mobile-stylesheet"]').forEach(function(l) {
                var href = l.getAttribute("href") || "";
                if (!fontSheet.test(href)) { return; }
                var held = wpcGfHeld(href);
                if (held) { l.setAttribute("data-wpc-gf-held", "1"); if (held === 2) { l.setAttribute("data-wpc-gf-late", "1"); } return; }
                l.setAttribute("rel", "stylesheet");
            });
        } catch (e) {}
    }
    function wpcSheetFallbackAt(el, l2) {
        l2.__wpcSwapOnLoad = 1;
        var p = el.parentNode;
        if (!p || el.hasAttribute("data-wpc-ucss") || el.hasAttribute("data-wpc-ucss-rest")) {
            (document.head || document.documentElement).appendChild(l2);
            return;
        }
        var m = el.__wpcRealMedia || el.getAttribute("data-wpc-tm") || el.getAttribute("media") || "";
        if (m === "print" && /media/.test(el.getAttribute("onload") || "")) {
            m = "";
        }
        if (m !== "" && m !== "all") {
            l2.setAttribute("media", m);
            if (m === "print") {
                l2.removeAttribute("data-wpc-flip");
            }
        }
        p.insertBefore(l2, el.nextSibling);
    }
    function swapStyles() {
        if (wpcRestoreNeedsGesture()) {
            wpcReleaseFontSheets();
            wpcReleaseTrailingCarrier();
            wpcOnFirstGesture(swapStyles);
            return;
        }
        // No subsets block has to be moved to the end of the body here to keep the restored
        // sheets from out-cascading it: the subsets are registered with the render's face owner
        // and ride #wpc-font-faces in the head, where every other first-paint face sits.
        var sel = '[rel="wpc-stylesheet"],[type="wpc-stylesheet"],[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]';
        var list = [].slice.call(document.querySelectorAll(sel));
        if (!list.length) {
            // Fully-absorbed pages have no deferred sheets left — the sweep must still arm.
            // .296 — THE OWED RELEASER: conceal-guards scope under html:not(.wpc-css-live);
            // on used-css pages the ucss-boot adds the class, but a no-used-css page had NO
            // adder — html:not() guards (#checkout overlay, mobile nav) concealed FOREVER
            // (wpcompress.com/upgrade: popup opened logically, visibility:hidden won). The
            // restore lane owns the release: nothing parked here = css already live.
            wpcMarkCssLiveWhenRestAttached();
            wpcRetireRestWhenOriginalsLive();
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
            (wpcHeld.length || wpcHeldStyles.length) && wpcSwapNtOn();
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
            wpcSwapNtEnd();
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
        var wpcInlineLive = 0;
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
                                wpcSheetFallbackAt(el, l2);
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
                        el.__wpcSwapOnLoad = 1;
                        wpcSwapNtHold(el);
                        el.setAttribute("rel", "stylesheet");
                    } else if (!el.__wpcMediaFlip) {
                        el.__wpcMediaFlip = 1;
                        var wpcRealMedia = el.getAttribute("media") || "all";
                        el.__wpcRealMedia = wpcRealMedia;
                        el.setAttribute("media", "print");
                        if (wpcAtomic) {
                            wpcHeld.push([ el, wpcRealMedia ]);
                        } else {
                            el.__wpcSwapOnLoad = 1;
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
                if (el.tagName.toLowerCase() !== "link") {
                    wpcSwapNtOn();
                    wpcInlineLive = 1;
                }
                el.setAttribute("type", "text/css");
                setTimeout((function() {
                    finish(false);
                }), 6e3);
            }));
        }));
        wpcInlineLive && wpcSwapNtEnd();
        Promise.all(ps).then((function() {
            // Unconditional and BEFORE the sweep gate: crit leaving and the real cascade arriving in
            // the same task is one recalc, and a sheet must never stay inert because the gate failed.
            wpcRestoreAll();
            wpcMarkCssLiveWhenRestAttached();
            wpcRetireRestWhenOriginalsLive();
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
    // Once the visitor has engaged, a parked frame on the heavy list is released within one viewport
    // of the screen. GoHighLevel form frames are released at the replay's end; with no
    // IntersectionObserver every heavy frame is.
    function holdsToViewport(el, u) {
        return !!window.IntersectionObserver && isHeavyEmbed(u) && !el.hasAttribute("data-wpc-form-frame");
    }
    function frameMargin() {
        return Math.max(400, window.innerHeight || 0);
    }
    function frameNear(el) {
        var r = el.getBoundingClientRect(), m = frameMargin(),
            vh = window.innerHeight || document.documentElement.clientHeight || 0, vw = window.innerWidth || document.documentElement.clientWidth || 0;
        return r.width > 0 && r.height > 0 && r.bottom > -m && r.top < vh + m && r.right > -m && r.left < vw + m;
    }
    var viewportIO = null;
    function viewportObserve(el) {
        if (!viewportIO) {
            viewportIO = new IntersectionObserver((function(entries) {
                entries.forEach((function(en) {
                    if (!en.isIntersecting) {
                        return;
                    }
                    var el = en.target, u = el.getAttribute("data-wpc-src");
                    viewportIO.unobserve(el);
                    if (u && u.trim()) {
                        restoreFrame(el, u.trim());
                    }
                }));
            }), {
                rootMargin: frameMargin() + "px"
            });
        }
        viewportIO.observe(el);
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
            var run = function() {
                var q = ambientQ.slice();
                ambientQ = [];
                q.forEach(function(fn) {
                    try {
                        fn();
                    } catch (x) {}
                });
            };
            typeof window.wpcAfterInput === "function" ? window.wpcAfterInput(run, e, 1) : run();
        }
        evs.forEach(function(n) {
            addEventListener(n, go, {
                capture: true,
                passive: true
            });
        });
    }
    // Once the delayed replay has started (D() sets __wpcParkedSrcReleased) an ambient video is
    // no longer held for a gesture: the replayed scripts may be the ones that size it.
    function restoreFrame(el, u) {
        if (window.wpcPaintFirst && window.wpcPaintFirst(function() { restoreFrame(el, u); })) return;
        if (isAmbientMedia(el) && !ambientHuman && !window.__wpcParkedSrcReleased) {
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
    // Called by D() at the start of the replay, before any delayed script is appended: every
    // media source still held (queued for a gesture, or parsed after the boot tick) and every
    // poster-lane video (video.wpc-video-delay) gets its source back now.
    window.wpcRestoreHeldVideoSources = function() {
        var q = ambientQ.slice();
        ambientQ = [];
        q.forEach(function(fn) {
            try {
                fn();
            } catch (x) {}
        });
        // Each restore is fenced: this runs inside D(), and a throw here must never cost the replay.
        [].slice.call(document.querySelectorAll("source.wpc-iframe-delay")).forEach((function(el) {
            try {
                var u = el.getAttribute("data-wpc-src");
                if (u && u.trim()) {
                    restoreFrame(el, u.trim());
                }
            } catch (x) {}
        }));
        try {
            window.wpcVideoRestore && window.wpcVideoRestore();
        } catch (x) {}
    };
    function frames(heavyOnly, viewportOnly) {
        [].slice.call(document.querySelectorAll(".wpc-iframe-delay")).forEach((function(el) {
            var u = el.getAttribute("data-wpc-src");
            if (!u || !u.trim()) {
                return;
            }
            u = u.trim();
            if (isHeavyEmbed(u) !== !!heavyOnly) {
                return;
            }
            if (holdsToViewport(el, u)) {
                if (frameNear(el)) {
                    restoreFrame(el, u);
                } else {
                    viewportObserve(el);
                }
                return;
            }
            if (!viewportOnly) {
                restoreFrame(el, u);
            }
        }));
    }
    window.wpcWakeViewportFrames = function() {
        frames(true, true);
    };
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
                    // v7.22.18 — A HEAVY EMBED NEEDS A HUMAN OR THE VIEWPORT, NOT A MARGIN.
                    // The 400px pre-scroll margin is right for a visitor approaching a
                    // booking widget; on a short page it also reaches a Maps iframe from
                    // the initial viewport, so the lab restored 463KB of Maps JS at ~5s
                    // with no gesture (sproduce: TBT + 16 requests every run). Heavy
                    // frames restore on IO only when actually inside the viewport, or
                    // once a human has engaged (scroll/tap sets __wpcEngaged — and that
                    // scroll re-fires this observer). Light frames keep the margin.
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
        atfPinStuck();
    }
    var atfPinQueued = false, atfPinWaits = 0;
    var atfPinSkip = "nav,[role=navigation],[role=menu],[role=menubar],[role=dialog],[role=tooltip],[role=tabpanel],dialog,[hidden],[inert],[aria-hidden=true],.elementor-invisible,.et-waypoint,.et_animated,.swiper,.swiper-container,.slick-slider,.owl-carousel,.splide,.flickity-enabled,.glide,.keen-slider,.tns-outer,.n2-section-smartslider,rs-module-wrap,rs-module,sr7-module,.rev_slider_wrapper,.elementor-slides-wrapper,.carousel,.metaslider,.soliloquy-container";
    function atfPinStuck() {
        if (atfPinQueued || gestureSeen || gesturePending || window.__wpcEngaged || animOff || document.readyState === "loading") {
            return;
        }
        var cfg = window.wpcDelayV3Cfg || {};
        if (+cfg.atfReveal === 0 || +cfg.cssOnly === 1 || !document.querySelector('script[type="text/placeholder"]')) {
            return;
        }
        atfPinQueued = true;
        var nav = null;
        try {
            nav = performance.getEntriesByType("navigation")[0];
        } catch (e) {}
        if (nav && !(nav.domContentLoadedEventEnd > 0) && atfPinWaits++ < 100) {
            setTimeout(function() {
                atfPinQueued = false;
                atfPinStuck();
            }, 50);
            return;
        }
        var go = function() {
            setTimeout(function() {
                atfPinQueued = false;
                if (gestureSeen || gesturePending || window.__wpcEngaged) {
                    return;
                }
                try {
                    atfPinScan();
                } catch (e) {}
            }, 0);
        };
        try {
            var io = new IntersectionObserver(function() {
                io.disconnect();
                go();
            });
            io.observe(document.body);
        } catch (e) {
            setTimeout(go, 50);
        }
    }
    function atfPinScan() {
        var vw = window.innerWidth || 0, vh = window.innerHeight || 0, body = document.body;
        if (!vw || !vh || !body) {
            return;
        }
        var gx = 24, gy = 16, cw = vw / gx, ch = vh / gy, shown = [], hidden = [], cands = [], budget = 4000, hiddenText = 0;
        var range = document.createRange ? document.createRange() : null;
        var mark = function(map, r) {
            var x0 = Math.max(0, Math.floor(r.left / cw)), x1 = Math.min(gx - 1, Math.floor((r.right - 1) / cw));
            var y0 = Math.max(0, Math.floor(r.top / ch)), y1 = Math.min(gy - 1, Math.floor((r.bottom - 1) / ch));
            for (var y = y0; y <= y1; y++) {
                for (var x = x0; x <= x1; x++) {
                    map[y * gx + x] = 1;
                }
            }
        };
        var walk = function(el, hid) {
            if (--budget < 0) {
                return;
            }
            var r = el.getBoundingClientRect();
            if (r.top >= vh || el.matches && el.matches(atfPinSkip)) {
                return;
            }
            var cs = getComputedStyle(el), at = -1, before = hiddenText;
            if (cs.display === "none" || cs.position === "fixed" || cs.position === "sticky") {
                return;
            }
            if (+cs.opacity === 0 && cs.display !== "contents") {
                var busy = false;
                try {
                    busy = !!(el.getAnimations && el.getAnimations().length);
                } catch (e) {}
                if (busy || cs.visibility === "hidden" || cs.position !== "static" && cs.position !== "relative" || !(r.width > 0 && r.height > 0 && r.bottom > 0)) {
                    return;
                }
                at = cands.push(el) - 1;
                hid = true;
            }
            for (var n = el.firstChild; n; n = n.nextSibling) {
                if (n.nodeType === 1) {
                    walk(n, hid);
                    continue;
                }
                if (n.nodeType !== 3 || !/\S\S/.test(n.nodeValue || "")) {
                    continue;
                }
                var tr = r;
                if (range) {
                    try {
                        range.selectNodeContents(n);
                        tr = range.getBoundingClientRect();
                    } catch (e) {}
                }
                if (tr.width < 1 || tr.height < 1 || tr.bottom <= 0 || tr.top >= vh || tr.right <= 0 || tr.left >= vw) {
                    continue;
                }
                if (hid) {
                    mark(hidden, tr);
                    hiddenText++;
                } else if (cs.visibility !== "hidden") {
                    mark(shown, tr);
                }
            }
            if (at !== -1 && hiddenText === before && !(el.tagName === "IMG" && el.complete && el.naturalWidth > 0)) {
                cands[at] = null;
            }
        };
        walk(body, false);
        var s = 0, h = 0;
        for (var i = 0; i < gx * gy; i++) {
            if (shown[i]) {
                s++;
            } else if (hidden[i]) {
                h++;
            }
        }
        if (h < 12 || h <= s) {
            return;
        }
        cands.forEach(function(el) {
            if (!el) {
                return;
            }
            try {
                var tv = el.style.getPropertyValue("transition"), tp = el.style.getPropertyPriority("transition"), tl = [];
                ["transition-property", "transition-duration", "transition-timing-function", "transition-delay", "transition-behavior"].forEach(function(n) {
                    var v = el.style.getPropertyValue(n);
                    if (v) { tl.push([n, v, el.style.getPropertyPriority(n)]); }
                });
                el.style.setProperty("transition", "none", "important");
                window.wpcPinVisible(el, false);
                var cs = getComputedStyle(el), m = /^matrix\(1, 0, 0, 1, 0, (-?[\d.]+)\)$/.exec(cs.transform);
                if (m && Math.abs(+m[1]) <= 200 && !parseFloat(cs.top) && !parseFloat(cs.bottom)) {
                    el.style.setProperty("transform", "none", "important");
                }
                el.setAttribute("data-wpc-atf-pin", "1");
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        try {
                            el.style.removeProperty("transition");
                            if (tv) {
                                el.style.setProperty("transition", tv, tp);
                            } else {
                                tl.forEach(function(x) { el.style.setProperty(x[0], x[1], x[2]); });
                            }
                        } catch (e2) {}
                    });
                });
            } catch (e) {}
        });
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
    var parkedBackgroundObserver = null;
    function wpcApplyParkedBackground(el) {
        var u = el.getAttribute("data-wpc-bg");
        if (!u) return;
        el.removeAttribute("data-wpc-bg");
        try { el.style.backgroundImage = 'url("' + u.replace(/"/g, "%22") + '")'; } catch (e) {}
    }
    function wpcIsPainted(e) {
        try { return e.getClientRects().length > 0 && getComputedStyle(e).visibility !== "hidden"; } catch (x) { return true; }
    }
    function wpcRestoreParkedBackgrounds(all) {
        var els = [].slice.call(document.querySelectorAll("[data-wpc-bg]"));
        if (!els.length) return;
        if (all || !window.IntersectionObserver) { els.forEach(wpcApplyParkedBackground); return; }
        if (!parkedBackgroundObserver) {
            parkedBackgroundObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(en) { if (en.isIntersecting && wpcIsPainted(en.target)) { parkedBackgroundObserver.unobserve(en.target); wpcApplyParkedBackground(en.target); } });
            }, { rootMargin: "0px" });
        }
        els.forEach(function(el) { parkedBackgroundObserver.observe(el); });
    }
    window.wpcRestoreVisibleBackgrounds = function() { wpcRestoreParkedBackgrounds(false); };
    window.wpcRestoreAllParkedBackgrounds = function() { wpcRestoreParkedBackgrounds(true); };
    var deferredStylesSwapped = false;
    function tick() {
        try {
            reveal();
            if (deferredStylesSwapped) {
                swapStyles();
            }
            frames(false);
            wpcRestoreParkedBackgrounds(false);
            framesIO();
        } catch (e) {}
    }
    tick();
    // v7.10.528 — the swap used to fire two frames after FIRST paint, which on a throttled
    // link is before LCP: 87 KiB of deferred CSS then competed with the LCP image for the same
    // pipe, and PSI measured exactly that as a 620 ms "resource load delay". Hold the swap until
    // the LCP element has actually painted. Three independent releases so the CSS can never be
    // stranded: the LCP entry, any user interaction, or a hard timeout.
    function wpcSwapDeferredStylesOnce() {
        if (deferredStylesSwapped) return;
        deferredStylesSwapped = true;
        try { swapStyles(); } catch (e) {}
    }
    try {
        var lcpSeen = false;
        if (window.PerformanceObserver) {
            try {
                var lcpObserver = new PerformanceObserver(function (l) {
                    if (l.getEntries().length) {
                        lcpSeen = true;
                        try { lcpObserver.disconnect(); } catch (e) {}
                        requestAnimationFrame(function () { wpcSwapDeferredStylesOnce(); });
                    }
                });
                lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
            } catch (e) {}
        }
        // Interaction always wins — a user who scrolls or taps must never wait on this.
        var swapPress = 0;
        var wpcSwapOnInput = function (e) {
            if (typeof window.wpcAfterInput !== "function" || !/^(?:pointerdown|touchstart)$/.test(e && e.type)) { wpcSwapDeferredStylesOnce(); return; }
            if (swapPress) return;
            swapPress = 1;
            window.wpcAfterInput(wpcSwapDeferredStylesOnce, e);
        };
        ['pointerdown','keydown','touchstart','scroll'].forEach(function (ev) {
            window.addEventListener(ev, wpcSwapOnInput, { once: true, passive: true });
        });
        // Backstop: no LCP entry (no PO support, or nothing qualifies) must still style the page.
        setTimeout(wpcSwapDeferredStylesOnce, (window.wpcDelayV3Cfg && window.wpcDelayV3Cfg.styleHoldMs) || 3000);
        requestAnimationFrame((function() {
            requestAnimationFrame((function() {
                if (!window.PerformanceObserver || lcpSeen) { wpcSwapDeferredStylesOnce(); }
            }));
        }));
    } catch (e) {
        deferredStylesSwapped = true;
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", tick);
    }
    setTimeout(tick, 1e3);
    setTimeout((function() {
        deferredStylesSwapped = true;
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
                            wpcCvRelease(ents[ci].target);
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
        var cvGo = function(e) {
            if (cvGone) return;
            cvGone = true;
            [ "scroll", "touchstart", "keydown", "pointerdown" ].forEach((function(ev) {
                window.removeEventListener(ev, cvGo, { passive: true });
            }));
            if (typeof window.wpcAfterInput === "function") { window.wpcAfterInput(cvRun, e, 1); } else { cvRun(); }
        };
        var cvRun = function() {
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
                        w.push(el);
                    }
                    for (var wi = 0; wi < w.length; wi++) {
                        try {
                            w[wi].style.contentVisibility = "visible";
                        } catch (e) {}
                        wpcCvRelease(w[wi]);
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
            if (wpcRestoreNeedsGesture()) {
                if (!swapLate.__gestureRetryQueued) {
                    swapLate.__gestureRetryQueued = 1;
                    wpcOnFirstGesture(function() { swapLate.__gestureRetryQueued = 0; swapLate(); });
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
                                wpcSheetFallbackAt(el, l2);
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
            } else {
                wpcSwapNtOn();
                wpcSwapNtEnd();
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
    function wpcFaceRanges(text) {
        var out = [], parts = String(text || "").split(",");
        for (var i = 0; i < parts.length && i < 64; i++) {
            var m = parts[i].match(/U\+([0-9A-Fa-f?]+)(?:-([0-9A-Fa-f]+))?/i);
            if (!m) { continue; }
            if (m[1].indexOf("?") >= 0) {
                out.push([parseInt(m[1].replace(/\?/g, "0"), 16), parseInt(m[1].replace(/\?/g, "F"), 16)]);
            } else {
                var lo = parseInt(m[1], 16);
                out.push([lo, m[2] ? parseInt(m[2], 16) : lo]);
            }
        }
        return out.length ? out : [[0, 1114111]];
    }
    function wpcFaceWeights(text) {
        var parts = String(text || "400").toLowerCase().trim().split(/\s+/), num = function(w) {
            return w === "bold" ? 700 : w === "normal" ? 400 : parseInt(w, 10) || 400;
        };
        return [num(parts[0]), num(parts[parts.length - 1])];
    }
    function wpcFamilyName(raw) {
        return String(raw || "").split(String.fromCharCode(34)).join("").split(String.fromCharCode(39)).join("").trim();
    }
    function wpcFaceInfo(rule) {
        var st = rule.style;
        return {
            family: wpcFamilyName(st.getPropertyValue("font-family")).toLowerCase(),
            style: String(st.getPropertyValue("font-style") || "normal").toLowerCase().trim(),
            weights: wpcFaceWeights(st.getPropertyValue("font-weight")),
            ranges: wpcFaceRanges(st.getPropertyValue("unicode-range"))
        };
    }
    function wpcFaceNear(a, b) {
        return a.family === b.family && a.style === b.style && a.weights[0] <= b.weights[1] && b.weights[0] <= a.weights[1];
    }
    function wpcFaceMeet(a, b) {
        for (var i = 0; i < a.ranges.length; i++) {
            for (var j = 0; j < b.ranges.length; j++) {
                if (a.ranges[i][0] <= b.ranges[j][1] && b.ranges[j][0] <= a.ranges[i][1]) { return true; }
            }
        }
        return false;
    }
    function wpcMakeFace(family, src, desc) {
        return new FontFace(wpcFamilyName(family), src, desc);
    }
    function wpcFaceOf(rule) {
        var st = rule.style, desc = {};
        ["style:font-style", "weight:font-weight", "unicodeRange:unicode-range", "display:font-display", "stretch:font-stretch"].forEach(function(pair) {
            var kv = pair.split(":"), val = st.getPropertyValue(kv[1]);
            if (val) { desc[kv[0]] = val; }
        });
        return wpcMakeFace(st.getPropertyValue("font-family"), st.getPropertyValue("src"), desc);
    }
    function wpcLateFacesHandover() {
        return !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.lateFacesHandover === 1);
    }
    function wpcLateFacesSplit(el) {
        var held = [], block = document.getElementById("wpc-font-faces"), served = [];
        var own = block && block.sheet && block.sheet.cssRules;
        if (!own || !el.sheet || !window.FontFace || !document.fonts || "function" != typeof document.fonts.add) { return held; }
        for (var i = 0; i < own.length; i++) {
            if (own[i].type === 5 && String(own[i].style.getPropertyValue("src")).indexOf("data:font") !== -1) { served.push(wpcFaceInfo(own[i])); }
        }
        if (!served.length) { return held; }
        var rules = el.sheet.cssRules, groups = {}, keys = [];
        for (var k = 0; k < rules.length; k++) {
            var rule = rules[k];
            if (rule.type !== 5) { continue; }
            var info = wpcFaceInfo(rule), near = false, meets = false;
            for (var j = 0; j < served.length; j++) {
                if (!wpcFaceNear(info, served[j])) { continue; }
                near = true;
                meets = meets || wpcFaceMeet(info, served[j]);
            }
            if (!near) { continue; }
            var key = info.style + "|" + info.weights.join("-");
            if (!groups[key]) { groups[key] = { required: [], lazy: [], at: [] }; keys.push(key); }
            groups[key].at.push(k);
            groups[key][meets ? "required" : "lazy"].push(rule);
        }
        var drop = [];
        for (var g = 0; g < keys.length; g++) {
            var grp = groups[keys[g]];
            if (!grp.required.length) { continue; }
            held.push({ required: grp.required.map(wpcFaceOf), lazy: grp.lazy.map(wpcFaceOf) });
            drop = drop.concat(grp.at);
        }
        drop.sort(function(x, y) { return y - x; }).forEach(function(at) { el.sheet.deleteRule(at); });
        return held;
    }
    function wpcLateFacesLoad(held) {
        held.forEach(function(grp) {
            var loads = grp.required.map(function(face) {
                try { return face.load(); } catch (x) { return Promise.reject(x); }
            });
            Promise.all(loads).then(function() {
                grp.required.concat(grp.lazy).forEach(function(face) {
                    document.fonts.add(face);
                });
            }, function() {});
        });
    }
    function wpcLateFacesOn(lf) {
        if (window.wpcPaintFirst && window.wpcPaintFirst(function() { wpcLateFacesOn(lf); })) return;
        var faces = [], typed = false;
        if (wpcLateFacesHandover() && lf.getAttribute("type") !== "text/css") {
            try {
                lf.media = "not all";
                lf.setAttribute("type", "text/css");
                typed = true;
                faces = wpcLateFacesSplit(lf);
            } catch (x) {}
        }
        typed || lf.setAttribute("type", "text/css");
        lf.media = "all";
        wpcLateFacesLoad(faces);
    }
    function wpcLateLinkOn(l) {
        var fresh = !l.getAttribute("href") && l.getAttribute("data-wpc-lf-href");
        if (fresh) { l.setAttribute("href", l.getAttribute("data-wpc-lf-href")); }
        if (!fresh || !wpcLateFacesHandover()) { l.media = "all"; return; }
        var opened = false, open = function() {
            if (opened) { return; }
            opened = true;
            var faces = [];
            try { faces = wpcLateFacesSplit(l); } catch (x) {}
            l.media = "all";
            wpcLateFacesLoad(faces);
        };
        l.addEventListener("load", open, { once: true });
        l.addEventListener("error", open, { once: true });
        setTimeout(open, 4000);
        l.media = "not all";
    }
    function wpcPageCodepoints() {
        var set = {};
        try {
            var text = ((document.body && document.body.textContent) || "").slice(0, 20000);
            for (var i = 0; i < text.length; i++) {
                var cp = text.charCodeAt(i);
                if (cp >= 48 && !(cp >= 8192 && cp <= 8303) && !(cp >= 55296 && cp <= 57343)
                    && cp !== 9676 && !(cp >= 65024 && cp <= 65039)) { set[cp] = 1; }
            }
        } catch (e) {}
        return set;
    }
    function wpcLateFacesBelowFold() {
        return !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.lateFacesBelowFold === 1);
    }
    function wpcRangeHasText(range, text) {
        var ranges = wpcFaceRanges(range);
        for (var cp in text) {
            for (var i = 0; i < ranges.length; i++) {
                if (+cp >= ranges[i][0] && +cp <= ranges[i][1]) { return true; }
            }
        }
        return false;
    }
    function wpcBelowFacesAsCss(entries) {
        var css = "";
        entries.forEach(function(e) {
            css += "@font-face{font-family:" + JSON.stringify(String(e.family)) + ";font-style:" + (e.style || "normal") + ";font-weight:" + (e.weight || "400")
                + (e.stretch ? ";font-stretch:" + e.stretch : "") + ";font-display:swap;src:" + e.src + (e.unicodeRange ? ";unicode-range:" + e.unicodeRange : "") + "}";
        });
        if (css) {
            var el = document.createElement("style");
            el.id = "wpc-below-faces-css";
            el.textContent = css;
            (document.head || document.documentElement).appendChild(el);
        }
    }
    var wpcBelowFacesDone = false;
    function wpcBelowFacesOn() {
        if (wpcBelowFacesDone) { return; }
        wpcBelowFacesDone = true;
        var data = document.getElementById("wpc-below-faces"), entries = [];
        try { entries = JSON.parse((data && data.textContent) || "[]") || []; } catch (e) {}
        if (!entries.length) { return; }
        if (!window.FontFace || !document.fonts || "function" != typeof document.fonts.add) {
            wpcBelowFacesAsCss(entries);
            return;
        }
        var text = wpcPageCodepoints(), groups = {}, keys = [];
        entries.forEach(function(e) {
            var face, desc = { style: e.style || "normal", weight: String(e.weight || "400"), display: "swap" };
            if (e.unicodeRange) { desc.unicodeRange = e.unicodeRange; }
            if (e.stretch) { desc.stretch = e.stretch; }
            try { face = wpcMakeFace(e.family, e.src, desc); } catch (x) { return; }
            var key = desc.style + "|" + desc.weight;
            if (!groups[key]) { groups[key] = { required: [], lazy: [] }; keys.push(key); }
            groups[key][wpcRangeHasText(e.unicodeRange, text) ? "required" : "lazy"].push(face);
        });
        wpcLateFacesLoad(keys.map(function(k) { return groups[k]; }));
    }
    if (wpcLateFacesBelowFold()) {
        if (document.readyState === "complete") { wpcBelowFacesOn(); } else { (window.wpcNativeAddEventListener || EventTarget.prototype.addEventListener).call(window, "load", wpcBelowFacesOn, { once: true }); }
    }
    function wpcRestSwapHold() {
        return !!(window.wpcDelayV3Cfg && +window.wpcDelayV3Cfg.restSwapHold === 1);
    }
    function wpcSheetFollows(l) {
        var all = document.querySelectorAll('link[rel*="stylesheet"],style');
        for (var i = 0; i < all.length; i++) {
            if (all[i] !== l && all[i].id !== "wpc-root-guard" && all[i].id !== "wpc-rest-focus-guard" && (l.compareDocumentPosition(all[i]) & 4)) { return true; }
        }
        return false;
    }
    function wpcRestAnchor(l) {
        if (!l || !l.hasAttribute("data-wpc-ucss-rest") || !document.head || !window.wpcDelayV3Cfg || window.wpcDelayV3Cfg.restAtCritOff === undefined || 0 !== +window.wpcDelayV3Cfg.restAtCritOff) { return null; }
        var a = document.head.querySelector("style#wpc-header-css-slice,style#wpc-critical-css");
        return a && a.parentNode === document.head ? a : null;
    }
    window.wpcRestAnchor = wpcRestAnchor;
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    function wpcRestFocusGuardDrop() {
        try {
            var g = document.getElementById("wpc-rest-focus-guard");
            if (g && g.parentNode) { g.parentNode.removeChild(g); }
        } catch (e) {}
    }
    function wpcRestFocusGuard() {
        try {
            if (!document.head || window.wpcDelayV3Cfg && 1 === +window.wpcDelayV3Cfg.restFocusGuardOff) { return; }
            var state = ":where(:focus,:hover:is(a,button,input,select,textarea,summary,label,[role=button],[role=link],[role=menuitem],[role=tab],[tabindex]))";
            var props = /^(color|background-color|background-image|border-(top|right|bottom|left)-color|outline-(color|style|width|offset)|box-shadow|text-decoration-(line|color|style|thickness)|text-shadow|opacity|fill|stroke|-webkit-text-fill-color)$/;
            var shorts = ["background", "border", "border-color", "border-top", "border-right", "border-bottom", "border-left", "outline", "text-decoration"];
            
            var split = function(t) {
                var out = [], depth = 0, q = "", from = 0;
                for (var i = 0; i < t.length; i++) {
                    var c = t.charAt(i);
                    if (c === "\\") { i++; continue; }
                    if (q) { if (c === q) { q = ""; } continue; }
                    if (c === '"' || c === "'") { q = c; } else if (c === "(" || c === "[") { depth++; } else if (c === ")" || c === "]") { depth--; } else if (c === "," && depth === 0) { out.push(t.substring(from, i)); from = i + 1; }
                }
                out.push(t.substring(from));
                return out;
            };
            
            
            var sel = function(t) {
                var depth = 0, q = "", cut = -1;
                for (var i = 0; i < t.length; i++) {
                    var c = t.charAt(i);
                    if (c === "\\") { i++; continue; }
                    if (q) { if (c === q) { q = ""; } continue; }
                    if (c === '"' || c === "'") { q = c; } else if (c === "(" || c === "[") { depth++; } else if (c === ")" || c === "]") { depth--; } else if (depth === 0) {
                        if (c === " " || c === ">" || c === "+" || c === "~") { cut = -1; } else if (c === ":" && cut < 0 && (t.charAt(i + 1) === ":" || /^:(before|after|first-line|first-letter)(?![\w-])/i.test(t.substring(i)))) { cut = i; }
                    }
                }
                return cut < 0 ? t + state : t.substring(0, cut) + state + t.substring(cut);
            };
            
            
            var decl = function(st) {
                var out = [];
                for (var i = 0; i < st.length; i++) {
                    var n = st[i], v = props.test(n) ? st.getPropertyValue(n) : "";
                    if (v) { out.push(n + ":" + v + (st.getPropertyPriority(n) ? "!important" : "")); }
                }
                for (var j = 0; j < shorts.length; j++) {
                    var sv = st.getPropertyValue(shorts[j]);
                    if (sv && /var\(/i.test(sv)) { out.push(shorts[j] + ":" + sv + (st.getPropertyPriority(shorts[j]) ? "!important" : "")); }
                }
                return out.join(";");
            };
            
            
            var walk = function(rules, depth, out) {
                for (var i = 0; i < rules.length; i++) {
                    var r = rules[i];
                    if (r.selectorText != null) {
                        if (r.cssRules && r.cssRules.length) { continue; }
                        var d = decl(r.style);
                        if (!d) { continue; }
                        var parts = split(String(r.selectorText)), g = [];
                        for (var k = 0; k < parts.length; k++) {
                            var s = parts[k].replace(/^\s+|\s+$/g, "");
                            if (s && s.indexOf("&") < 0) { g.push(sel(s)); }
                        }
                        if (g.length) { out.push(g.join(",") + "{" + d + "}"); }
                    } else if (r.cssRules && depth < 3) {
                        var pre = window.CSSMediaRule && r instanceof CSSMediaRule ? "@media " + r.media.mediaText : window.CSSSupportsRule && r instanceof CSSSupportsRule ? "@supports " + r.conditionText : "";
                        if (!pre) { continue; }
                        var inner = [];
                        walk(r.cssRules, depth + 1, inner);
                        if (inner.length) { out.push(pre + "{" + inner.join("") + "}"); }
                    }
                }
            };
            var crit = [].slice.call(document.querySelectorAll("style#wpc-header-css-slice,style#wpc-critical-css")).filter(function(c) {
                return c.sheet && !c.hasAttribute("data-wpc-retired") && c.getAttribute("media") !== "not all";
            });
            var rest = [].slice.call(document.querySelectorAll("link[data-wpc-ucss-rest]")).filter(function(l) {
                return l.getAttribute("href") && !l.hasAttribute("data-wpc-rest-retired") && l.getAttribute("media") !== "not all";
            });
            var last = null, i, j;
            for (i = 0; i < rest.length; i++) {
                for (j = 0; j < crit.length; j++) {
                    if (crit[j].compareDocumentPosition(rest[i]) & 4) { last = rest[i]; break; }
                }
            }
            if (!last) { wpcRestFocusGuardDrop(); return; }
            var g = document.getElementById("wpc-rest-focus-guard");
            if (!g) {
                var out = [];
                for (i = 0; i < crit.length; i++) {
                    try { walk(crit[i].sheet.cssRules, 0, out); } catch (e) {}
                }
                if (!out.length) { return; }
                g = document.createElement("style");
                g.id = "wpc-rest-focus-guard";
                g.textContent = out.join("");
            }
            if (last.nextSibling !== g) { last.parentNode.insertBefore(g, last.nextSibling); }
        } catch (e) {}
    }
    window.wpcRestFocusGuard = wpcRestFocusGuard;
    window.wpcRestFocusGuardDrop = wpcRestFocusGuardDrop;
    function wpcUsedCssLast(l, anchor) {
        var head = document.head;
        if (!l.sheet) { if (anchor) { head.insertBefore(l, anchor); } else { head.appendChild(l); } return; }
        if (l.hasAttribute("data-wpc-rest-retired") || !anchor && !wpcSheetFollows(l)) { return; }
        var next = l.cloneNode(false), settled = false;
        var drop = function() {
            if (settled) { return; }
            settled = true;
            try { if (next.parentNode) { next.parentNode.removeChild(next); } } catch (e) {}
        };
        next.addEventListener("load", function() {
            if (settled) { return; }
            var ok = !!next.sheet;
            if (ok) { try { ok = next.sheet.cssRules.length > 0; } catch (e) { ok = true; } }
            if (!ok) { drop(); return; }
            settled = true;
            try { if (l.parentNode) { l.parentNode.removeChild(l); } } catch (e) {}
        }, { once: true });
        next.addEventListener("error", drop, { once: true });
        setTimeout(function() { if (!next.sheet) { drop(); } }, 1e4);
        if (anchor) { head.insertBefore(next, anchor); } else { head.appendChild(next); }
    }
    var lateCssDone = false, lateCssWaiters = [];
    function lateCssFinish() {
        if (lateCssDone) {
            return;
        }
        lateCssDone = true;
        window.wpcLateCssDone = !0;
        try {
            var wpcLateHeld = document.querySelectorAll('style[data-wpc-hold-style]'), wpcLateTm = document.querySelectorAll("link[data-wpc-tm]");
            (wpcLateHeld.length || wpcLateTm.length) && wpcSwapNtOn();
            wpcLateHeld.forEach((function(s) {
                s.removeAttribute("data-wpc-hold-style");
                s.setAttribute("type", "text/css");
            }));
            wpcLateTm.forEach((function(l) {
                l.media = l.getAttribute("data-wpc-tm") || "all";
            }));
            wpcSwapNtEnd();
            var wpcLateFacesGo = function() {
            try {
            var lf = document.getElementById("wpc-late-faces");
            if (lf && wpcRestoreNeedsGesture()) {
                wpcOnFirstGesture(function() {
                    wpcLateFacesOn(lf);
                });
                lf = null;
            }
            if (lf) {
                wpcLateFacesOn(lf);
                // v7.20.03 — the flip alone is not service: the engine does not initiate loads
                // for faces already-painted text needs (dalton: w800 sat unloaded forever,
                // headline held the metric fallback). Nudge every declared face, sampling a
                // codepoint from its own unicode-range so ranged subsets match.
                // v7.21.205 — SCHEDULER PASS: the reveal's flips and the face activations
                // must never share a task (beucomply 876ms document task, Style&Layout
                // 673ms; harmonytree TBT 1217ms — the burst re-serialized as the lane
                // grew to 60+ faces). Reads are collected here (cheap, own sheet), the
                // queue is DEDUPED by spec+codepoint (one load per identical face spec —
                // the same-URL multi-fetch half), and loads fire 8 per task on setTimeout
                // yields, starting one tick AFTER the flip's recalc.
                // v7.21.207 — COVERAGE-DRIVEN nudge. Sampling a codepoint from each
                // face's OWN range commanded every slice of a Google-sliced family
                // (bestexteriorsinc: ~60 Poppins files, ~1MB — devanagari and latin-ext
                // the page never renders). A ranged face is nudged only when its range
                // intersects the document's actual text; PUA (icon) ranges keep the
                // old behavior (their glyphs live in CSS content:, never in text).
                // v7.21.210 — FONT-FACE-PRECISE nudge. fonts.load(shorthand, text) in
                // Chrome loads EVERY face matching the family/weight — the text argument
                // does not range-filter — so one spec pulled latin+devanagari+latin-ext
                // siblings together (bestexteriorsinc: 56/56 faces loaded for a page that
                // renders ONE Poppins combo). Walk document.fonts instead and call .load()
                // on exact FontFace objects: status unloaded, range intersects the page's
                // letters, style/weight actually rendered (sampled computed styles), PUA
                // exempt. The engine natively loads anything else it truly needs.
                try {
                    if (document.fonts && document.fonts.forEach) {
                        var lfTxt = null;
                        var lfTextSet = function() {
                            if (lfTxt) { return lfTxt; }
                            lfTxt = wpcPageCodepoints();
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
                        var lfSrcMap = null;
                        var lfNorm = function(v) {
                            return String(v || "").split(String.fromCharCode(34)).join("").split(String.fromCharCode(39)).join("").replace(/\s+/g, " ").trim().toLowerCase();
                        };
                        var lfFaceKey = function(fam, wt, st, ur, sr) {
                            var lfWt = lfNorm(wt);
                            var lfSt = lfNorm(sr);
                            return lfNorm(fam) + "|" + (lfWt === "" || lfWt === "normal" ? "400" : lfWt) + "|" + (lfNorm(st) || "normal") + "|" + (lfNorm(ur) || "u+0-10ffff").split(" ").join("") + "|" + (lfSt === "" || lfSt === "normal" ? "100%" : lfSt);
                        };
                        var lfKeyOf = function(f) {
                            return lfFaceKey(f.family, f.weight, f.style, f.unicodeRange, f.stretch);
                        };
                        var lfSrcUrls = function(f) {
                            if (!lfSrcMap) {
                                lfSrcMap = {};
                                try {
                                    var lfSheets = document.styleSheets;
                                    for (var lfSi = 0; lfSheets && lfSi < lfSheets.length; lfSi++) {
                                        var lfRules = null;
                                        try { lfRules = lfSheets[lfSi].cssRules; } catch (e) { continue; }
                                        for (var lfRi = 0; lfRules && lfRi < lfRules.length; lfRi++) {
                                            var lfR = lfRules[lfRi];
                                            if (!lfR || lfR.type !== 5 || !lfR.style) { continue; }
                                            var lfUm = String(lfR.style.getPropertyValue("src") || "").match(/url\(\s*(["']?)([^"')]+)\1\s*\)/i);
                                            if (!lfUm) { continue; }
                                            var lfUrl = lfUm[2];
                                            try { lfUrl = new URL(lfUrl, lfSheets[lfSi].href || document.baseURI).href; } catch (e) {}
                                            var lfKey = lfFaceKey(lfR.style.getPropertyValue("font-family"), lfR.style.getPropertyValue("font-weight"), lfR.style.getPropertyValue("font-style"), lfR.style.getPropertyValue("unicode-range"), lfR.style.getPropertyValue("font-stretch"));
                                            if (!lfSrcMap.hasOwnProperty(lfKey)) { lfSrcMap[lfKey] = []; }
                                            if (lfSrcMap[lfKey].indexOf(lfUrl) === -1) { lfSrcMap[lfKey].push(lfUrl); }
                                        }
                                    }
                                } catch (e) { lfSrcMap = {}; }
                            }
                            var lfFk = lfKeyOf(f);
                            return lfSrcMap.hasOwnProperty(lfFk) ? lfSrcMap[lfFk] : null;
                        };
                        var lfOncePerFile = function(list) {
                            var lfSeen = {};
                            var lfOut = [];
                            var lfAll = [];
                            var lfWin = {};
                            var lfKeys = [];
                            try {
                                document.fonts.forEach(function(g) {
                                    lfAll.push(g);
                                    try {
                                        if (g.status === "loaded" || g.status === "loading") {
                                            var lfGu = lfSrcUrls(g);
                                            for (var lfGi = 0; lfGu && lfGi < lfGu.length; lfGi++) { lfSeen[lfGu[lfGi]] = 1; }
                                        }
                                    } catch (e) {}
                                });
                            } catch (e) {}
                            for (var lfLi = 0; lfLi < list.length; lfLi++) {
                                lfKeys[lfLi] = null;
                                try {
                                    if (lfSrcUrls(list[lfLi])) {
                                        lfKeys[lfLi] = lfKeyOf(list[lfLi]);
                                        var lfAt = lfAll.indexOf(list[lfLi]);
                                        if (!lfWin.hasOwnProperty(lfKeys[lfLi]) || lfAt >= lfWin[lfKeys[lfLi]][1]) { lfWin[lfKeys[lfLi]] = [lfLi, lfAt]; }
                                    }
                                } catch (e) {}
                            }
                            for (var lfMi = 0; lfMi < list.length; lfMi++) {
                                if (lfKeys[lfMi] !== null) {
                                    if (lfWin[lfKeys[lfMi]][0] !== lfMi) { continue; }
                                    var lfUs = lfSrcMap[lfKeys[lfMi]];
                                    var lfHit = false;
                                    for (var lfUi = 0; lfUi < lfUs.length; lfUi++) { if (lfSeen[lfUs[lfUi]]) { lfHit = true; break; } }
                                    if (lfHit) { continue; }
                                    for (var lfUj = 0; lfUj < lfUs.length; lfUj++) { lfSeen[lfUs[lfUj]] = 1; }
                                }
                                lfOut.push(list[lfMi]);
                            }
                            return lfOut;
                        };
                        var lfQ = [];
                        var lfCandidates = [];
                        document.fonts.forEach(function(f) {
                            try {
                                if (f.status !== "unloaded") { return; }
                                if ((f.display || "") === "optional") { return; }
                                var lfCp = lfPick(f.unicodeRange || "");
                                if (lfCp === -1) { return; }
                                if (lfCp < 57344) { lfCandidates.push(f); return; }
                                lfQ.push(f);
                            } catch (e) {}
                        });
                        var loadQueuedLateFonts = function() {
                            if (lfQ.length) {
                                lfQ = lfOncePerFile(lfQ);
                                var lfJ = 0;
                                var lfStep = function() {
                                    var lfE = Math.min(lfJ + 8, lfQ.length);
                                    for (; lfJ < lfE; lfJ++) {
                                        try { lfQ[lfJ].load().catch(function() {}); } catch (e) {}
                                    }
                                    if (lfJ < lfQ.length) { setTimeout(lfStep, 0); }
                                };
                                if (wpcRestoreNeedsGesture()) { wpcOnFirstGesture(function() { setTimeout(lfStep, 0); }); } else { setTimeout(lfStep, 0); }
                            }
                        };
                        if (lfCandidates.length) {
                            var lfBodyEls = document.querySelectorAll("body *");
                            var lfScanLimit = Math.min(lfBodyEls.length, 600);
                            var lfScanIndex = 0;
                            lfUsed = {};
                            var scanComputedFontUsage = function() {
                                try {
                                    var lfBatchEnd = Math.min(lfScanIndex + 120, lfScanLimit);
                                    for (; lfScanIndex < lfBatchEnd; lfScanIndex++) {
                                        var lfCs = getComputedStyle(lfBodyEls[lfScanIndex]);
                                        var lfStk = (lfCs.fontFamily || "").toLowerCase();
                                        var lfFam = lfStk.split(",")[0].split(String.fromCharCode(34)).join("").split(String.fromCharCode(39)).join("").trim();
                                        if (lfFam) { lfUsed[lfFam + "|" + lfCs.fontWeight + "|" + lfCs.fontStyle] = lfStk.indexOf(lfFam + " fallback") !== -1 ? 2 : 1; }
                                    }
                                } catch (e) { lfScanIndex = lfScanLimit; }
                                if (lfScanIndex < lfScanLimit) { setTimeout(scanComputedFontUsage, 0); return; }
                                for (var lfCandIndex = 0; lfCandIndex < lfCandidates.length; lfCandIndex++) {
                                    if (lfMatchUse(lfCandidates[lfCandIndex])) { lfQ.push(lfCandidates[lfCandIndex]); }
                                }
                                loadQueuedLateFonts();
                            };
                            setTimeout(scanComputedFontUsage, 0);
                        } else { loadQueuedLateFonts(); }
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
            var wpcAttachLateFontLinks = function() {
                document.querySelectorAll('link[data-wpc-lf]').forEach(wpcLateLinkOn);
            };
            var wpcFaceBlock = document.getElementById("wpc-font-faces");
            if (wpcRestoreNeedsGesture() && wpcFaceBlock && (wpcFaceBlock.textContent || "").indexOf("data:font") !== -1) { wpcOnFirstGesture(wpcAttachLateFontLinks); } else { wpcAttachLateFontLinks(); }
            } catch (e) {}
            };
            window.wpcPaintFirst && window.wpcPaintFirst(wpcLateFacesGo) || wpcLateFacesGo();
            document.querySelectorAll('link[data-wpc-ucss]').forEach((function(l) {
                if (l.media === "print") {
                    wpcSwapNtOn();
                    l.media = l.getAttribute("data-wpc-ucss") || "all";
                }
            }));
            wpcSwapNtEnd();
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
                        wpcSwapNtOn();
                        var wpcRa = wpcRestAnchor(wpcUc[wpcI]);
                        if (wpcRa && (wpcUc[wpcI].compareDocumentPosition(wpcRa) & 4)) { continue; }
                        if (wpcRestSwapHold()) { wpcUsedCssLast(wpcUc[wpcI], wpcRa); } else if (wpcRa) { document.head.insertBefore(wpcUc[wpcI], wpcRa); } else { document.head.appendChild(wpcUc[wpcI]); }
                    }
                }
                wpcRestFocusGuard();
                wpcSwapNtEnd();
            } catch (e) {}
            // v7.10.675 — reveal in-viewport .elementor-invisible via IntersectionObserver, not
            // a synchronous rect scan. The old post-paint scan read getBoundingClientRect().top
            // on EVERY .elementor-invisible element; each read forces layout of a DISTINCT
            // content-visibility:auto section, so the "one clean layout covers all reads"
            // assumption never held — a ~47-section page spent ~106ms in one post-FCP task
            // (PSI "Forced reflow" / TBT 109), profiled on the flagship (§9). IO runs the same
            // in-viewport test off the main thread (zero synchronous layout) and is the proven
            // reveal path (wpcRevealInvisibleOnViewportEntry): its single initial notification is exactly what reveal
            // needs — the "no second notification" caveat only bites uses that want a later one.
            // This tail is the reveal path on lab/no-gesture loads (wpcRevealInvisibleOnViewportEntry arms only after
            // wpc-scripts-loaded), so it must never block: IO satisfies both. rootMargin bottom
            // 25% == the old innerHeight*1.25 window; scroll now reveals below-fold too (a strict
            // never-blank gain, matching wpcRevealInvisibleOnViewportEntry).
            try {
                var invisibleEls = [].slice.call(document.querySelectorAll(".elementor-invisible"));
                if (invisibleEls.length && window.IntersectionObserver) {
                    var revealObserver = new IntersectionObserver((function(ents) {
                        for (var qi = 0; qi < ents.length; qi++) {
                            var en = ents[qi];
                            if (en.isIntersecting || en.boundingClientRect.bottom < 0) {
                                try { en.target.classList.remove("elementor-invisible"); } catch (e) {}
                                revealObserver.unobserve(en.target);
                            }
                        }
                    }), { rootMargin: "0px 0px 25% 0px" });
                    for (var invisibleIndex = 0; invisibleIndex < invisibleEls.length; invisibleIndex++) {
                        revealObserver.observe(invisibleEls[invisibleIndex]);
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
        if (wpcRestoreNeedsGesture()) {
            if (!swapLateBarrier.__gestureRetryQueued) {
                swapLateBarrier.__gestureRetryQueued = 1;
                wpcOnFirstGesture(function() { swapLateBarrier.__gestureRetryQueued = 0; swapLateBarrier(); });
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
            typeof window.wpcAfterInput === "function" ? window.wpcAfterInput(swapLateBarrier, e) : swapLateBarrier();
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
    function wpcLcpRemaining(entry, ms) {
        if (!window.wpcAfterHero || !entry || typeof entry.startTime !== "number") return ms;
        return Math.max(0, ms - Math.max(0, performance.now() - entry.startTime));
    }
    var lt = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssTimer !== "undefined" ? +window.wpcDelayV3Cfg.lateCssTimer : 2500;
    var ltCap = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssTimerCap !== "undefined" ? +window.wpcDelayV3Cfg.lateCssTimerCap : 8e3;
    // v7.21.103 — CONVERGENCE IS PAINT-KEYED, NEVER NETWORK-TAIL-KEYED. loadEventEnd is a
    // long-tail clock: on 3G the load event fires at ~11s and the late sheets landed at 14s
    // (falknerei: the page was visibly wrong until its trio arrived). Two additional
    // releases, both idempotent through lateSwapStarted: LCP + lateCssLcp (default 2000ms —
    // the .714 clearance, so the fetch never re-enters PSI's simulated LCP dependency
    // chain) and a hard wall-clock cap from navigation start (lateCssCap, default 7000ms).
    // Fast loads keep today's behaviour; slow loads converge bounded instead of unbounded.
    if (lt > 0) {
        var ltLcpDelayMs = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssLcp !== "undefined" ? +window.wpcDelayV3Cfg.lateCssLcp : 2e3;
        var ltHardCapMs = window.wpcDelayV3Cfg && typeof window.wpcDelayV3Cfg.lateCssCap !== "undefined" ? +window.wpcDelayV3Cfg.lateCssCap : 7e3;
        if (ltLcpDelayMs > 0 && window.PerformanceObserver) {
            try {
                var ltLcpObserver = new PerformanceObserver(function (l) {
                    if (l.getEntries().length) {
                        try { ltLcpObserver.disconnect(); } catch (e) {}
                        setTimeout(function () { try { swapLateBarrier(); } catch (e) {} }, wpcLcpRemaining(l.getEntries()[0], ltLcpDelayMs));
                    }
                });
                ltLcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
            } catch (e) {}
        }
        if (ltHardCapMs > 0) {
            setTimeout(function () { try { swapLateBarrier(); } catch (e) {} },
                Math.max(1000, ltHardCapMs - performance.now()));
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
            window.wpcFlushHeavyEmbeds && window.wpcFlushHeavyEmbeds();
        } catch (e) {}
        try {
            window.wpcRestoreAllParkedBackgrounds && window.wpcRestoreAllParkedBackgrounds();
        } catch (e) {}
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
        try {
            window.wpcWakeViewportFrames && window.wpcWakeViewportFrames();
        } catch (e) {}
        wpcPinHeights();
        requestAnimationFrame((function() {
            requestAnimationFrame((function() {
                try {
                    var own = new Event("scroll");
                    own.__wpcOwn = 1;
                    document.dispatchEvent(own);
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
        // v7.21.185 — A PARKED CURSOR IS NOT A HOVER. Headless Chrome (every desktop
        // Lighthouse/PSI run) leaves the cursor at 0,0 where :hover matches the element
        // under it without any human present — this belt released the ENTIRE delay
        // registry at ~1.2s into every desktop lab trace (beucomply: 41 scripts, swiper's
        // 805ms init task, TBT 560ms attributed to scripts the lane had correctly
        // delayed). Hover counts as engagement only after a TRUSTED pointer event has
        // been observed; registered via the ORIGINAL addEventListener so our own patch
        // cannot capture it.
        var trustedPointerSeen = false;
        try {
            y.call(document, "pointermove", function(ev) { if (ev.isTrusted) { trustedPointerSeen = true; } }, { once: !0, passive: !0, capture: !0 });
            y.call(document, "mousemove", function(ev) { if (ev.isTrusted) { trustedPointerSeen = true; } }, { once: !0, passive: !0, capture: !0 });
        } catch (e) {}
        var hoverTries = 0;
        var hoverCheck = function() {
            if (fired) {
                return;
            }
            try {
                if (trustedPointerSeen && document.querySelector(":hover")) {
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
                    window.wpcResizeWithMenusIsolated(function() { window.dispatchEvent(new Event("resize")); });
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
            try {
                if (document.head) {
                    var anchor = window.wpcRestAnchor ? window.wpcRestAnchor(l) : null;
                    if (!anchor) { document.head.appendChild(l); } else if (!(l.compareDocumentPosition(anchor) & 4)) { document.head.insertBefore(l, anchor); }
                }
            } catch (e) {}
            l.setAttribute("href", u);
            try { window.wpcRestFocusGuard && window.wpcRestFocusGuard(); } catch (e) {}

            // v7.10.627 — the never-black shape guard exists only to cover the window
            // before real fill rules land. Retire it once they have, so it can never
            // outlive its purpose (a deferral whose remover must actually run).
            try {
                var shapeGuard = document.getElementById("wpc-shape-fill-guard");
                if (shapeGuard) {
                    var dropShapeGuardOnRestLoad = function() {
                        try { if (shapeGuard.parentNode) { shapeGuard.parentNode.removeChild(shapeGuard); } } catch (e) {}
                    };
                    l.addEventListener("load", dropShapeGuardOnRestLoad, { once: true });
                    setTimeout(dropShapeGuardOnRestLoad, 4000);
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
        var shapeGuardDropped = false;
        var dropShapeGuardAfterPageLoad = function() {
            if (shapeGuardDropped) { return; }
            shapeGuardDropped = true;
            try {
                var g = document.getElementById("wpc-shape-fill-guard");
                if (g && g.parentNode) { g.parentNode.removeChild(g); }
            } catch (e) {}
        };
        var shapeGuardPollStartedAt = Date.now();
        var pollLoadThenDropShapeGuard = function() {
            if (shapeGuardDropped) { return; }
            var n = null;
            try { n = performance.getEntriesByType("navigation")[0]; } catch (e) {}
            if ((n && n.loadEventEnd > 0) || Date.now() - shapeGuardPollStartedAt > 10000) {
                setTimeout(dropShapeGuardAfterPageLoad, 1500);
                return;
            }
            setTimeout(pollLoadThenDropShapeGuard, 250);
        };
        setTimeout(pollLoadThenDropShapeGuard, 250);
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
        var restAttached = false;
        var attachRestSheetsOnce = function() {
            if (restAttached) { return; }
            restAttached = true;
            var attach = function() {
                try { rest(false); } catch (e) {}
                try { window.wpcReleaseCssAtIdle && window.wpcReleaseCssAtIdle(); } catch (e) {}
            };
            var held = null;
            try { held = window.wpcIdleRelease ? window.wpcIdleRelease() : null; } catch (e) {}
            if (held) { held.then(attach, attach); } else { attach(); }
        };
        // document.readyState is SHADOWED by this loader (held at "loading" until the lane
        // releases), so a readyState gate here can never fire — proven in test before ship.
        // Navigation Timing is the real, unshadowed load signal.
        var navigationLoadFinished = function() {
            try {
                var n = performance.getEntriesByType && performance.getEntriesByType("navigation")[0];
                if (n && n.loadEventEnd > 0) { return true; }
                if (performance.timing && performance.timing.loadEventEnd > 0) { return true; }
            } catch (e) {}
            return false;
        };
        var restPollStartedAt = Date.now();
        var pollLoadThenAttachRest = function() {
            if (restAttached) { return; }
            if (navigationLoadFinished()) {
                setTimeout(attachRestSheetsOnce, 1200);
                return;
            }
            if (Date.now() - restPollStartedAt > 8000) { attachRestSheetsOnce(); return; }
            setTimeout(pollLoadThenAttachRest, 250);
        };
        setTimeout(pollLoadThenAttachRest, 250);
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
    var restIntent = [ "pointermove", "pointerover", "pointerdown", "keydown", "touchstart" ], restIntentGone = 0;
    var restOnIntent = function(e) {
        if (restIntentGone || e && e.isTrusted === false) {
            return;
        }
        if (e && /^pointer(?:over|move)$/.test(e.type) && e.pointerType === "touch") {
            return;
        }
        restIntentGone = 1;
        for (var i = 0; i < restIntent.length; i++) {
            try { window.removeEventListener(restIntent[i], restOnIntent, true); } catch (x) {}
        }
        if (typeof window.wpcAfterInput === "function") {
            window.wpcAfterInput(function() { rest(false); }, e);
        } else {
            rest(false);
        }
    };
    restIntent.forEach((function(ev) {
        window.addEventListener(ev, restOnIntent, {
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
    // Held for a gesture only until the delayed replay starts (D() sets __wpcParkedSrcReleased
    // and calls wpcRestoreHeldVideoSources, which lands here): replayed scripts must find the
    // video with its source.
    function restore() {
        if (!human && !window.__wpcParkedSrcReleased) {
            queued = true;
            return;
        }
        if (window.wpcPaintFirst && window.wpcPaintFirst(restore)) return;
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
            typeof window.wpcAfterInput === "function" ? window.wpcAfterInput(restore, e, 1) : restore();
        }
    }
    evs.forEach((function(n) {
        addEventListener(n, go, {
            capture: true,
            passive: true
        });
    }));
    window.wpcVideoRestore = restore;
    // A replay that started before this block ran (an already-scrolled page starts it at boot)
    // found no wpcVideoRestore to call.
    if (window.__wpcParkedSrcReleased) {
        restore();
    }
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
        window.addEventListener("scroll", (function() {
            scrolledAtLcp = 1;
        }), { passive: true, once: true, capture: true });
    } catch (e) {}
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
                        s: cfg.rs || "",
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
                s: cfg.rs || "",
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
