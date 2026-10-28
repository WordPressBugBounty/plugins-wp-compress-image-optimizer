jQuery(document).ready((function($) {
    function wpcBarNotice(msg, type) {
        try {
            type = type || "info";
            var host = document.getElementById("wpbody-content");
            var div = document.createElement("div");
            div.className = "notice notice-" + type + " is-dismissible";
            var key = "wpc-n-" + msg.length + "-" + msg.charCodeAt(0) + "-" + msg.charCodeAt(msg.length - 1);
            var prev = document.querySelector('[data-wpc-notice="' + key + '"]');
            if (prev && prev.parentNode) {
                prev.parentNode.removeChild(prev);
            }
            div.setAttribute("data-wpc-notice", key);
            var colors = {
                success: "#00a32a",
                error: "#d63638",
                warning: "#dba617",
                info: "#72aee6"
            };
            if (!host) {
                div.style.cssText = "position:fixed;top:32px;left:0;right:0;z-index:999999;margin:0;background:#fff;" + "box-shadow:0 2px 8px rgba(0,0,0,.15);padding:1px 38px 1px 12px;";
            }
            div.style.borderLeft = "4px solid " + (colors[type] || colors.info);
            var pEl = document.createElement("p");
            pEl.textContent = msg;
            pEl.style.cssText = "margin:8px 0;font-size:13px;line-height:1.5;";
            div.appendChild(pEl);
            var b = document.createElement("button");
            b.type = "button";
            b.setAttribute("aria-label", "Dismiss");
            b.style.cssText = "position:absolute;top:6px;right:6px;border:0;background:none;cursor:pointer;font-size:16px;color:#787c82;";
            b.innerHTML = "✕";
            div.style.position = host ? "relative" : "fixed";
            b.onclick = function() {
                if (div.parentNode) {
                    div.parentNode.removeChild(div);
                }
            };
            div.appendChild(b);
            if (host) {
                host.insertBefore(div, host.firstChild);
                document.documentElement.scrollTop = 0;
            } else {
                document.body.appendChild(div);
            }
            if (type !== "error") {
                setTimeout((function() {
                    if (div.parentNode) {
                        div.parentNode.removeChild(div);
                    }
                }), 9e3);
            }
        } catch (e) {}
    }
    // v7.10.641 — the busy indicator may only ever touch the top-level .ab-item's OWN
    // content. WP core binds hoverintent on every li.menupop element at page load, and
    // the old whole-LI innerHTML swap captured the momentary "hover" class with it — so
    // one click left the rebuilt Advanced flyout either permanently dead (bindings gone)
    // or permanently open (frozen hover class), until a full page reload.
    function wpcBarBusy(label) {
        var a = document.querySelector("#wp-admin-bar-wp-compress > .ab-item");
        if (!a) {
            return function() {};
        }
        var orig = a.innerHTML;
        a.innerHTML = '<span class="wp-compress-admin-bar-icon"></span><span class="wpc-admin-bar-title" style="padding-right:6px;">' + label + "</span>";
        try {
            $("#wp-admin-bar-wp-compress").removeClass("hover").find("li.menupop").removeClass("hover");
        } catch (e) {}
        var done = false;
        return function() {
            if (!done) {
                done = true;
                a.innerHTML = orig;
            }
        };
    }
    function wpcBarTip(item) {
        var a = item.children("a, .ab-item").first();
        var base = a.attr("data-wpc-tip");
        if (base === undefined) {
            base = String(a.attr("title") || "").split(" · ")[0];
            a.attr("data-wpc-tip", base);
        }
        return {
            a: a,
            base: base
        };
    }
    function wpcBarStamp(item, label, wait) {
        try {
            var t = wpcBarTip(item);
            label = label || "Purged just now";
            t.a.attr("title", wait && wait > 0 ? label : t.base + " · " + label);
            if (wait && wait > 0) {
                item.addClass("wpc-bar-disabled");
                t.a.attr("aria-disabled", "true");
            }
        } catch (e) {}
    }
    function wpcBarRebuilding(item, label) {
        try {
            var t = wpcBarTip(item);
            item.addClass("wpc-bar-disabled");
            t.a.attr("aria-disabled", "true").attr("title", "Rebuilding…");
            setTimeout((function() {
                item.removeClass("wpc-bar-disabled");
                t.a.removeAttr("aria-disabled");
                t.a.attr("title", label ? t.base + " · " + label : t.base);
            }), 18e4);
        } catch (e) {}
    }
    function wpcBarConfirm(item, onConfirm) {
        var data = item.find(".wpc-bar-confirm");
        if (!data.length) {
            onConfirm();
            return;
        }
        var opener = document.activeElement;
        var overlay = document.createElement("div");
        overlay.className = "wpc-bar-modal";
        var box = document.createElement("div");
        box.className = "wpc-bar-modal-box";
        box.setAttribute("role", "dialog");
        box.setAttribute("aria-modal", "true");
        box.setAttribute("aria-labelledby", "wpc-bar-modal-title");
        box.setAttribute("aria-describedby", "wpc-bar-modal-body");
        var title = document.createElement("h2");
        title.className = "wpc-bar-modal-title";
        title.id = "wpc-bar-modal-title";
        title.textContent = data.attr("data-title");
        var body = document.createElement("p");
        body.className = "wpc-bar-modal-body";
        body.id = "wpc-bar-modal-body";
        body.textContent = data.attr("data-body");
        var actions = document.createElement("div");
        actions.className = "wpc-bar-modal-actions";
        var cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "wpc-bar-modal-cancel";
        cancel.textContent = data.attr("data-cancel");
        var ok = document.createElement("button");
        ok.type = "button";
        ok.className = "wpc-bar-modal-ok";
        ok.textContent = data.attr("data-ok");
        actions.appendChild(cancel);
        actions.appendChild(ok);
        box.appendChild(title);
        box.appendChild(body);
        box.appendChild(actions);
        overlay.appendChild(box);
        function close() {
            document.removeEventListener("keydown", onKey, true);
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            try {
                if (opener && opener.focus) {
                    opener.focus();
                }
            } catch (e) {}
        }
        function onKey(ev) {
            if (ev.key === "Escape") {
                ev.preventDefault();
                ev.stopPropagation();
                close();
            } else if (ev.key === "Tab") {
                ev.preventDefault();
                (document.activeElement === ok ? cancel : ok).focus();
            } else if (ev.key === "Enter" && document.activeElement !== cancel) {
                ev.preventDefault();
                ev.stopPropagation();
                close();
                onConfirm();
            }
        }
        cancel.onclick = close;
        ok.onclick = function() {
            close();
            onConfirm();
        };
        overlay.onclick = function(ev) {
            if (ev.target === overlay) {
                close();
            }
        };
        document.addEventListener("keydown", onKey, true);
        document.body.appendChild(overlay);
        ok.focus();
    }
    $("body").on("click", ".wp-compress-bar-generate-critical-css>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Generating Critical...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_generate_critical_css",
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            // What the service answered, for whoever debugs the click; the person sees what they
            // always saw.
            if (response && response.success && response.data && window.console && console.info) {
                console.info("WPC Generate Critical:", response.data.status, response.data.type, response.data);
            }
            if (!response.success) {
                wpcBarNotice("Generate Critical CSS failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Generate Critical CSS request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    // v7.10.725 — per-page lane. The URL rides a data attribute the server stamped
    // (front end: the viewed page; wp-admin editor: the edited post's permalink), with a
    // front-end location.href fallback. The server re-validates same-host + page-shape.
    function wpcPageUrl(el) {
        var u = $(el).closest("li").find("[data-wpc-url]").attr("data-wpc-url");
        if (u) {
            return u;
        }
        return document.body.className.indexOf("wp-admin") !== -1 ? "" : String(location.href);
    }
    function wpcErrText(response, d) {
        if (d && d.message) { return d.message; }
        if (response && response.data && response.data.message) { return response.data.message; }
        if (response && typeof response.data === "string" && response.data) { return response.data; }
        return "unexpected reply";
    }
    $("body").on("click", ".wp-compress-bar-refresh-page>a", (function(e) {
        e.preventDefault();
        var url = wpcPageUrl(this);
        if (!url) {
            wpcBarNotice("Could not work out which page to purge.", "error");
            return false;
        }
        var done = wpcBarBusy("Purging page...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_purge_html",
            page_url: url,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            var d = (response && response.data) ? response.data : {};
            if (response && response.success) {
                if (window.console && console.info) {
                    console.info("WPC Clear page:", d.situation, d.layers);
                }
                wpcBarNotice(d.message || "This page's cache was purged.",
                    d.situation === "just-refreshed" ? "info" : "success");
            } else {
                wpcBarNotice("Purging the page failed: " + wpcErrText(response, d), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Purging the page failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-purge-html-cache>a", (function(e) {
        e.preventDefault();
        var item = $(this).closest("li");
        var done = wpcBarBusy("Purging page cache...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_purge_html",
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            if (response.success) {
                var layers = response.data && response.data.layers ? response.data.layers : [];
                if (window.console && console.info) {
                    console.info("WPC Purge HTML layers:", layers, response.data && response.data.log);
                }
                var fails = layers.filter((function(l) {
                    return String(l).indexOf("FAIL") !== -1;
                }));
                if (fails.length) {
                    wpcBarNotice("Page cache: a cache layer FAILED — " + fails.join(", ") + " (details in browser console)", "error");
                } else {
                    wpcBarNotice("Page cache purged. Pages rebuild on their next visit.", "success");
                }
                wpcBarStamp(item, response.data && response.data.label, 0);
                if ($("#optimizationTable").find("div").length > 0) {
                    location.reload();
                }
            } else {
                wpcBarNotice("Purging the page cache failed: " + wpcErrText(response), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Page cache request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    function wpcCfDebug(mode, label) {
        var done = wpcBarBusy(label + "…");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wpc_cf_doctor",
            mode: mode,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(res) {
            done();
            var d = res && res.data ? res.data : {};
            if (window.console) {
                console.log("WPC CF " + mode + ":", d);
            }
            var msg = d.verdict || d.msg || "Done.";
            if (mode === "doctor") {
                msg += "\n\nBefore: " + JSON.stringify(d.probe_before) + "\nPurge: " + (d.plain_purge || "?") + "\nAfter: " + JSON.stringify(d.probe_after) + "\n\nRules: " + (typeof d.rules_snapshot === "string" ? d.rules_snapshot : (d.rules_snapshot || []).map((function(r) {
                    return r.ref + " key=" + JSON.stringify(r.cache_key) + " ttl=" + JSON.stringify(r.edge_ttl);
                })).join("\n")) + "\n\n(Full detail in the browser console)";
            }
            wpcBarNotice("Cloudflare " + label + ": " + msg.split("\n")[0] + " (full report in browser console)", res && res.success ? "info" : "error");
        })).fail((function(x) {
            done();
            wpcBarNotice("Cloudflare " + label + " request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
    }
    $("body").on("click", ".wp-compress-bar-cf-doctor>a", (function(e) {
        e.preventDefault();
        wpcCfDebug("doctor", "Doctor");
        return false;
    }));
    // "Clear cache" is a container, not an action.
    $("body").on("click", ".wp-compress-bar-clear-menu>a", (function(e) {
        e.preventDefault();
    }));
    $("body").on("click", ".wp-compress-bar-purge-critical-css>a", (function(e) {
        e.preventDefault();
        var item = $(this).closest("li");
        if (item.hasClass("wpc-bar-disabled")) {
            return false;
        }
        wpcBarConfirm(item, (function() {
            var done = wpcBarBusy("Rebuilding critical CSS...");
            $.post(wpc_ajaxVar.ajaxurl, {
                action: "wps_ic_purge_critical_css",
                wps_ic_nonce: wpc_ajaxVar.nonce
            }, (function(response) {
                done();
                if (response.success) {
                    var mode = response.data && response.data.mode ? String(response.data.mode) : "";
                    if (window.console && console.info) {
                        console.info("WPC Purge Critical:", mode || "ok");
                    }
                    wpcBarNotice("Critical CSS is rebuilding. Each page keeps its current version until the new one lands.", "success");
                    wpcBarRebuilding(item, response.data && response.data.label);
                } else {
                    wpcBarNotice("Rebuilding critical CSS failed: " + wpcErrText(response), "error");
                }
            })).fail((function(x) {
                done();
                wpcBarNotice("Critical CSS request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
            }));
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-clear-cache>a", (function(e) {
        e.preventDefault();
        var item = $(this).closest("li");
        if (item.hasClass("wpc-bar-disabled")) {
            return false;
        }
        wpcBarConfirm(item, (function() {
            var done = wpcBarBusy("Purging the CDN...");
            $.post(wpc_ajaxVar.ajaxurl, {
                action: "wps_ic_purge_cdn",
                wps_ic_nonce: wpc_ajaxVar.nonce
            }, (function(response) {
                done();
                if (response.success) {
                    wpcBarNotice("CDN purged. Files are fetched again from your site as they are requested.", "success");
                    wpcBarStamp(item, response.data && response.data.label, response.data && response.data.retry_in);
                } else {
                    var d = response && response.data;
                    if (d && d.throttled) {
                        wpcBarStamp(item, d.label, d.retry_in);
                    }
                    wpcBarNotice("Purging the CDN failed: " + wpcErrText(response), "error");
                }
            })).fail((function(x) {
                done();
                wpcBarNotice("CDN request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
            }));
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-preload-cache>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Preloading page...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_preload_page", wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            if (!response.success) {
                wpcBarNotice("Preload failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Preload request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
}));
