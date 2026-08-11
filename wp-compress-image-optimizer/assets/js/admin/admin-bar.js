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
    $("body").on("click", ".wp-compress-view-as-visitor>a", (function(e) {
        e.preventDefault();
        var url = new URL(window.location.href);
        url.searchParams.set("wpc_visitor_mode", "true");
        url = url.toString();
        window.open(url);
    }));
    $("body").on("click", ".wp-compress-bar-generate-critical-css>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Generating Critical...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_generate_critical_css",
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            if (!response.success) {
                wpcBarNotice("Generate Critical CSS failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Generate Critical CSS request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    
    
    
    function wpcPageUrl(el) {
        var u = $(el).closest("li").find("[data-wpc-url]").attr("data-wpc-url");
        if (u) {
            return u;
        }
        return document.body.className.indexOf("wp-admin") !== -1 ? "" : String(location.href);
    }
    function wpcErrText(response, d) {
        if (d && d.message) { return d.message; }
        if (response && typeof response.data === "string" && response.data) { return response.data; }
        return "unexpected reply";
    }
    $("body").on("click", ".wp-compress-bar-status>a", (function(e) {
        e.preventDefault();
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-refresh-page>a", (function(e) {
        e.preventDefault();
        var url = wpcPageUrl(this);
        if (!url) {
            wpcBarNotice("Could not work out which page to refresh.", "error");
            return false;
        }
        var done = wpcBarBusy("Refreshing page...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_purge_html",
            page_url: url,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            var d = (response && response.data) ? response.data : {};
            if (response && response.success) {
                if (window.console && console.info) {
                    console.info("WPC Refresh page:", d.situation, d.layers);
                }
                wpcBarNotice(d.message || "This page's cache was refreshed.",
                    d.situation === "just-refreshed" ? "info" : "success");
            } else {
                wpcBarNotice("Refresh failed: " + wpcErrText(response, d), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Refresh request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-rebuild-page>a", (function(e) {
        e.preventDefault();
        var li = $(this).closest("li");
        if (li.hasClass("wpc-bar-inflight")) {
            wpcBarNotice("A fresh version of this page is already being generated — it applies automatically when it lands.", "info");
            return false;
        }
        var url = wpcPageUrl(this);
        if (!url) {
            wpcBarNotice("Could not work out which page to rebuild.", "error");
            return false;
        }
        var done = wpcBarBusy("Rebuilding page...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_rebuild_optimizations",
            page_url: url,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            var d = (response && response.data) ? response.data : {};
            if (response && response.success) {
                if (window.console && console.info) {
                    console.info("WPC Rebuild page:", d.situation, d.did);
                }
                wpcBarNotice(d.message || "Rebuilding this page.",
                    d.situation === "rebuilding-page" ? "success" : "info");
                if (d.situation === "rebuilding-page" || d.situation === "already-regenerating") {
                    li.addClass("wpc-bar-inflight");
                }
            } else {
                wpcBarNotice("Rebuild failed: " + wpcErrText(response, d), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Rebuild request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-purge-html-cache>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Purging cache...");
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
                    wpcBarNotice("Purge HTML: a cache layer FAILED — " + fails.join(", ") + " (details in browser console)", "error");
                }
                if ($("#optimizationTable").find("div").length > 0) {
                    location.reload();
                }
            } else {
                wpcBarNotice("Purge HTML failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Purge HTML request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
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
    
    $("body").on("click", ".wp-compress-bar-advanced>a", (function(e) {
        e.preventDefault();
    }));
    
    
    $("body").on("click", ".wp-compress-bar-rebuild>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Rebuilding...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_rebuild_optimizations",
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            var d = (response && response.data) ? response.data : {};
            if (response && response.success) {
                if (window.console && console.info) {
                    console.info("WPC Rebuild:", d.situation, d.did);
                }
                wpcBarNotice(d.message || "Rebuild started.",
                    d.situation === "already-regenerating" ? "info" : "success");
            } else {
                wpcBarNotice("Rebuild failed: " + ((d && d.message) || "unexpected reply"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Rebuild request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
    }));
    $("body").on("click", ".wp-compress-bar-remove-critical-css>a", (function(e) {
        e.preventDefault();
        if (!window.confirm("Reset Critical CSS?\n\nThis marks every critical artifact stale and DROPS CACHED PAGES so they re-render. Pages render with full theme CSS (correct but slower) until fresh versions land. Images and the CDN are not touched.\n\nMost of the time \"Rebuild Optimizations\" is what you want instead.")) {
            return false;
        }
        var done = wpcBarBusy("Removing...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_purge_critical_css",
            hard_purge: "1",
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            if (response.success) {
                wpcBarNotice("Critical CSS fully removed — pages render with full theme CSS until fresh versions land automatically.", "success");
            } else {
                wpcBarNotice("Remove Critical CSS failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Remove Critical CSS request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-purge-critical-css>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Purging cache...");
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
                if (mode.indexOf("hard-deleted") === 0) {
                    wpcBarNotice("Critical CSS fully removed — pages render with full theme CSS until fresh versions land automatically.", "success");
                } else if (mode.indexOf("stale-marked") === 0) {
                    wpcBarNotice("Critical CSS marked for regeneration — the current version keeps serving until the new one lands automatically.", "success");
                } else if (mode.indexOf("nothing-to-mark") === 0) {
                    wpcBarNotice("No Critical CSS on disk yet — regeneration dispatched; fresh versions land automatically.", "success");
                }
            } else {
                wpcBarNotice("Purge Critical CSS failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Purge Critical CSS request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-pull-latest>a", (function(e) {
        e.preventDefault();
        var done = wpcBarBusy("Pulling latest...");
        $.get(wpc_ajaxVar.ajaxurl, {
            action: "wpc_crit_selftest",
            refetch: "resync"
        }, (function(response) {
            done();
            var r = response;
            if (typeof r === "string") { try { r = JSON.parse(r); } catch (x) { r = {}; } }
            if (r && r.refetch) {
                if (window.console && console.info) { console.info("WPC Pull Latest:", r); }
                wpcBarNotice("Full resync started (" + r.refetch.join(", ") + "). A fresh generation is being fetched now; everything else lands automatically over the next ~5 minutes — pages keep serving throughout.", "success");
            } else {
                wpcBarNotice("Pull Latest failed: " + ((r && r.error) || "unexpected reply") + " — is Critical CSS enabled?", "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Pull Latest request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
        }));
        return false;
    }));
    $("body").on("click", ".wp-compress-bar-clear-cache>a", (function(e) {
        e.preventDefault();
        if (!window.confirm("Purge CDN Images?\n\nEvery optimized image is re-fetched from origin — this spends origin bandwidth and is rarely needed. Only use it if IMAGES are wrong, not CSS or HTML.")) {
            return false;
        }
        var done = wpcBarBusy("Purging CDN...");
        $.post(wpc_ajaxVar.ajaxurl, {
            action: "wps_ic_purge_cdn",
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            done();
            if (response.success) {
                wpcBarNotice("CDN cache purged — optimized images re-fetch from origin as they are requested.", "success");
            } else {
                wpcBarNotice("Purge CDN failed: " + (response.data || "unknown error"), "error");
            }
        })).fail((function(x) {
            done();
            wpcBarNotice("Purge CDN request failed (HTTP " + (x && x.status) + ") — server/hosting issue.", "error");
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
