jQuery(document).ready((function($) {
    if (typeof ajaxurl === "undefined") {
        var ajaxurl = wpc_ajaxVar.ajaxurl;
    }
    var $wpcHeader = $(".wpc-header");
    var $wpcPill = $(".wpc-save-pill");
    var wpcSaveActive = false;
    var wpcPillDocking = false;
    var wpcPillInterval = null;
    function isHeaderOutOfView() {
        if (!$wpcHeader.length) return false;
        var rect = $wpcHeader[0].getBoundingClientRect();
        return rect.top + rect.height * .5 < 0;
    }
    function checkPillFloat() {
        if (!wpcSaveActive || wpcPillDocking) return;
        var shouldFloat = isHeaderOutOfView();
        if (shouldFloat && !$wpcPill.hasClass("wpc-save-pill-floating")) {
            $wpcPill.addClass("wpc-save-pill-floating");
        } else if (!shouldFloat && $wpcPill.hasClass("wpc-save-pill-floating")) {
            wpcPillDocking = true;
            $wpcPill.addClass("wpc-save-pill-docking");
            setTimeout((function() {
                $wpcPill.removeClass("wpc-save-pill-floating wpc-save-pill-docking");
                $wpcPill.addClass("wpc-save-pill-landed");
                setTimeout((function() {
                    $wpcPill.removeClass("wpc-save-pill-landed");
                }), 600);
                wpcPillDocking = false;
            }), 350);
        }
    }
    function startPillWatch() {
        if (wpcPillInterval || window.innerWidth < 769) return;
        checkPillFloat();
        wpcPillInterval = setInterval(checkPillFloat, 150);
    }
    function stopPillWatch() {
        if (wpcPillInterval) {
            clearInterval(wpcPillInterval);
            wpcPillInterval = null;
        }
    }
    $(document).on("keydown", ".wpc-cf-connect-form", (function(e) {
        if (e.key === "Enter" && !$(e.target).is(".wpc-cf-zone-search")) {
            e.preventDefault();
            if ($("#wpc-cf-zone-list-holder").is(":visible") && $('input[name="wpc-cf-zone"]').val()) {
                $(".wpc-cf-token-connect").trigger("click");
            } else {
                $(".wpc-cf-token-check").trigger("click");
            }
        }
    }));
    $(document).on("keydown", ".wpc-cf-zone-search", (function(e) {
        var $dropdown = $(this).closest(".wpc-cf-zone-list-dropdown");
        var $items = $dropdown.find(".wpc-cf-zone-list-items > div:visible");
        var $active = $items.filter(".wpc-cf-zone-hover");
        var idx = $items.index($active);
        if (e.key === "ArrowDown") {
            e.preventDefault();
            $items.removeClass("wpc-cf-zone-hover");
            idx = idx < $items.length - 1 ? idx + 1 : 0;
            $items.eq(idx).addClass("wpc-cf-zone-hover");
            $items.eq(idx)[0].scrollIntoView({
                block: "nearest"
            });
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            $items.removeClass("wpc-cf-zone-hover");
            idx = idx > 0 ? idx - 1 : $items.length - 1;
            $items.eq(idx).addClass("wpc-cf-zone-hover");
            $items.eq(idx)[0].scrollIntoView({
                block: "nearest"
            });
        } else if (e.key === "Enter") {
            e.preventDefault();
            if ($active.length) {
                $active.trigger("click");
            }
        } else if (e.key === "Escape") {
            $dropdown.hide();
        }
    }));
    $(document).on("click", ".wpc-cf-zone-list-selected", (function(e) {
        e.stopPropagation();
        var $dropdown = $(this).siblings(".wpc-cf-zone-list-dropdown");
        var isOpening = !$dropdown.is(":visible");
        $dropdown.toggle();
        if (isOpening) {
            var $search = $dropdown.find(".wpc-cf-zone-search");
            $search.val("");
            $dropdown.find(".wpc-cf-zone-list-items > div").show();
            $dropdown.find(".wpc-cf-zone-no-results").hide();
        }
    }));
    $(document).on("input", ".wpc-cf-zone-search", (function() {
        var query = $(this).val().toLowerCase();
        var $dropdown = $(this).closest(".wpc-cf-zone-list-dropdown");
        var $items = $dropdown.find(".wpc-cf-zone-list-items > div");
        var visible = 0;
        $items.removeClass("wpc-cf-zone-hover");
        $items.each((function() {
            var match = $(this).text().toLowerCase().indexOf(query) > -1;
            $(this).toggle(match);
            if (match) visible++;
        }));
        $dropdown.find(".wpc-cf-zone-no-results").toggle(visible === 0);
    }));
    $(document).on("click", ".wpc-cf-zone-list-items > div", (function() {
        var selectedValue = $(this).data("selected-zone");
        var selectedID = $(this).data("selected-zone-id");
        $(".wpc-cf-zone-list-dropdown").hide();
        $('input[name="wpc-cf-zone"]').val(selectedID);
        $(".wpc-cf-zone-text").text(selectedValue);
        $(".wpc-cf-zone-list-selected").addClass("has-value");
        return false;
    }));
    $(document).on("click", (function(e) {
        if (!$(e.target).closest(".wpc-cf-zone-list").length) {
            $(".wpc-cf-zone-list-dropdown").hide();
        }
    }));
    $(document).on("click", ".wpc-cf-zone-list-dropdown", (function(e) {
        e.stopPropagation();
    }));
    $(".wpc-cf-token-check").on("click", (function(e) {
        e.preventDefault();
        var cFToken = $('input[name="wpc-cf-token"]').val();
        if (cFToken === "") {
            $(".wpc-cf-loader-error").html("Cloudflare API Error: Token field is empty.").show();
            console.error("Cloudflare API Error: Token field is empty.");
            return false;
        }
        $(".wpc-cf-token-hide-on-load").hide();
        $(".wpc-cf-loader").show();
        $(".wpc-cf-loader-error").hide();
        $.post(ajaxurl, {
            action: "wpc_ic_checkCFToken",
            token: cFToken,
            _nonce: Math.random().toString(36).substr(2, 9),
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            if (response.success) {
                $(".wpc-cf-zone-list-items", "#wpc-cf-zone-list-holder").html(response.data);
                $(".wpc-cf-loader").hide((function() {
                    $("#wpc-cf-zone-list-holder").show();
                }));
            } else {
                $(".wpc-cf-token-hide-on-load").show();
                $(".wpc-cf-loader").hide();
                $(".wpc-cf-loader-error").html(wpcCfErrPanel(response)).show();
            }
        })).fail((function(xhr) {
            $(".wpc-cf-token-hide-on-load").show();
            $(".wpc-cf-loader").hide();
            $(".wpc-cf-loader-error>span").html(wpcCfAjaxFailMsg(xhr));
            $(".wpc-cf-loader-error").show();
        }));
        return false;
    }));
    function wpcEsc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, (function(m) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;"
            }[m];
        }));
    }
    function wpcCfErrMsg(response, fallback) {
        var d = response && response.data;
        if (d && typeof d === "object" && d.msg) {
            return wpcEsc(d.msg);
        }
        if (typeof d === "string" && d !== "") {
            return wpcEsc(d);
        }
        return wpcEsc(fallback || "Something went wrong talking to Cloudflare — please try again.");
    }
    function wpcCfAjaxFailMsg(xhr) {
        var status = xhr && xhr.status ? xhr.status : 0;
        return wpcEsc("The site's admin-ajax request failed" + (status ? " (HTTP " + status + ")" : "") + " — a server/hosting issue (site under load or erroring), not your Cloudflare token. Try again in a minute.");
    }
    function wpcCfErrPanel(response, fallback) {
        var d = response && response.data && typeof response.data === "object" ? response.data : null;
        var p = d && d.privileges && d.privileges.tests ? d.privileges : (d && d.tests ? d : null);
        if (p && p.tests) {
            return "<span>Your token authenticated, but it is missing the permission(s) marked below. In Cloudflare go to My Profile → API Tokens → edit this token, add them, Save, then connect again.</span>" + wpcRenderCfPerms(p);
        }
        var full = d && d.msg ? String(d.msg) : (typeof (response && response.data) === "string" ? String(response.data) : "");
        if (!full) {
            return "<span>" + wpcEsc(fallback || "Something went wrong talking to Cloudflare — please try again.") + "</span>";
        }
        var cut = full.indexOf(". Check, in this order");
        var head = cut > 0 ? full.slice(0, cut + 1) : full;
        var rest = cut > 0 ? full.slice(cut + 2).trim() : "";
        return "<span>" + wpcEsc(head) + "</span>" + (rest ? '<details class="wpc-cf-err-more"><summary>What to check</summary><p>' + wpcEsc(rest) + "</p></details>" : "");
    }
    function wpcRenderCfPerms(p) {
        if (!p || !p.tests) return "";
        var req = {
            "Zone Read": 1,
            "Cache Purge": 1
        };
        var rows = "";
        for (var k in p.tests) {
            if (!p.tests.hasOwnProperty(k)) continue;
            var ok = String(p.tests[k]).indexOf("OK") === 0;
            rows += '<li class="' + (ok ? "wpc-cf-diag-ok" : "wpc-cf-diag-fail") + '">' + '<span class="wpc-cf-diag-icon">' + (ok ? "✓" : "✕") + "</span>" + '<span class="wpc-cf-diag-name">' + wpcEsc(k) + (req[k] ? " (required)" : "") + "</span>" + "</li>";
        }
        return rows ? '<ul class="wpc-cf-diag">' + rows + "</ul>" : "";
    }
    function wpcRenderCfReport(report) {
        if (!report || typeof report !== "object") return "";
        var labels = {
            bypass_rule: "CDN bypass rule",
            static_rule: "Static-asset cache rule",
            whitelist: "IP whitelist",
            cname: "CDN hostname (CNAME)",
            v2_sync: "Server sync"
        };
        var order = [ "bypass_rule", "static_rule", "whitelist", "cname", "v2_sync" ];
        var rows = "";
        order.forEach((function(k) {
            var c = report[k];
            if (!c) return;
            var ok = !!c.ok;
            var mode = c.mode || (ok ? "ok" : "failed");
            var icon, cls;
            if (mode === "pending" || mode === "scheduled") {
                icon = "⋯";
                cls = "wpc-cf-diag-pending";
            } else if (ok && mode === "ok") {
                icon = "✓";
                cls = "wpc-cf-diag-ok";
            } else {
                icon = "✕";
                cls = "wpc-cf-diag-fail";
            }
            rows += '<li class="' + cls + '">' + '<span class="wpc-cf-diag-icon">' + icon + "</span>" + '<span class="wpc-cf-diag-name">' + wpcEsc(labels[k] || k) + "</span>" + (c.detail ? '<span class="wpc-cf-diag-detail">' + wpcEsc(c.detail) + "</span>" : "") + "</li>";
        }));
        return rows ? '<ul class="wpc-cf-diag-list">' + rows + "</ul>" : "";
    }
    $(".wpc-cf-token-connect").on("click", (function(e) {
        e.preventDefault();
        $(".wpc-cf-loader-error").html("").hide();
        var cFToken = $('input[name="wpc-cf-token"]').val();
        var cFZone = $('input[name="wpc-cf-zone"]').val();
        if (cFToken === "") {
            $(".wpc-cf-loader-error").html("Cloudflare API Error: Token field is empty.").show();
            console.error("Cloudflare API Error: Token field is empty.");
            return;
        }
        if (cFZone === "") {
            $(".wpc-cf-loader-error").html("Cloudflare API Error: You haven't selected a zone.").show();
            console.error("Cloudflare API Error: You haven't selected a zone.");
            return;
        }
        $(".wpc-cf-token-hide-on-load").hide();
        $("#wpc-cf-zone-list-holder").hide();
        $(".wpc-cf-loader-zone").show();
        $.post(ajaxurl, {
            action: "wpc_ic_checkCFConnect",
            token: cFToken,
            zone: cFZone,
            _nonce: Math.random().toString(36).substr(2, 9),
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            if (response.success) {
                $.post(ajaxurl, {
                    action: "wpc_ic_setupCF",
                    token: cFToken,
                    zone: cFZone,
                    wps_ic_nonce: wpc_ajaxVar.nonce,
                    _nonce: Math.random().toString(36).substr(2, 9)
                }, (function() {
                    $(".wpc-cf-loader-zone").hide();
                    window.location.reload();
                })).fail((function() {
                    $(".wpc-cf-loader-zone").hide();
                    window.location.reload();
                }));
            } else {
                $(".wpc-cf-loader-zone").hide();
                $(".wpc-cf-insert-token-step").show();
                $(".wpc-cf-loader-error").html(wpcCfErrPanel(response, "Looks like your API Token does not have correct privileges or it's invalid")).show();
            }
        })).fail((function(xhr) {
            $(".wpc-cf-loader-zone").hide();
            $(".wpc-cf-insert-token-step").show();
            $(".wpc-cf-loader-error").html(wpcCfAjaxFailMsg(xhr)).show();
        }));
        return false;
    }));
    $(".wpc-cf-token-disconnect").on("click", (function(e) {
        $(".wpc-cf-token-hide-on-load").hide();
        $(".wpc-cf-token-connected").hide();
        $(".wpc-cf-loader-disconnecting").show();
        $(".wpc-cf-loader-error").hide();
        e.preventDefault();
        $.post(ajaxurl, {
            action: "wpc_ic_checkCFDisconnect",
            wps_ic_nonce: wpc_ajaxVar.nonce,
            _nonce: Math.random().toString(36).substr(2, 9)
        }, (function(response) {
            window.location.reload();
        }));
        return false;
    }));
    $(".wpc-cf-token-verify").on("click", (function(e) {
        $(".wpc-cf-token-hide-on-load").hide();
        $(".wpc-cf-token-connected").hide();
        $(".wpc-cf-loader-disconnecting").hide();
        $(".wpc-cf-loader-refreshing").show();
        $(".wpc-cf-loader-error").hide();
        e.preventDefault();
        $.post(ajaxurl, {
            action: "wpc_ic_refreshCFConnection",
            wps_ic_nonce: wpc_ajaxVar.nonce,
            timeout: 120,
            _nonce: Math.random().toString(36).substr(2, 9)
        }, (function() {
            window.location.reload();
        })).fail((function() {
            window.location.reload();
        }));
        return false;
    }));
    var wpcAdvInitialStates = {};
    var wpcAdvPendingChanges = {};
    $(".wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox").each((function() {
        var name = $(this).data("option-name") || $(this).attr("name");
        if (name) wpcAdvInitialStates[name] = $(this).prop("checked");
    }));
    $('input[type="hidden"][name^="options["]', ".wpc-settings-body").each((function() {
        var name = $(this).attr("name");
        if (name) wpcAdvInitialStates[name] = $(this).val();
    }));
    var $optSlider = $("#optimizationLevel");
    if ($optSlider.length) wpcAdvInitialStates["optimizationLevel"] = $optSlider.val();
    $('input[type="text"], input[type="number"]', ".wpc-settings-body").not(".wpc-cf-connect-wrapper *").each((function() {
        var name = $(this).attr("name") || $(this).attr("id");
        if (name) wpcAdvInitialStates[name] = $(this).val();
    }));
    var $lq = $("#localQualityLevel");
    if ($lq.length) wpcAdvInitialStates["localQualityLevel"] = $lq.val();
    var $lb = $("#localBackup");
    if ($lb.length) wpcAdvInitialStates["localBackup"] = $lb.val();
    $(document).on("input change", '.wpc-settings-body input[type="text"], .wpc-settings-body input[type="number"]', (function() {
        if ($(this).closest(".wpc-cf-connect-wrapper").length) return;
        window.checkUnsavedChanges();
    }));
    $(document).on("wpc-setting-changed", (function(e, name, isChecked) {
        if (wpcAdvInitialStates[name] !== undefined && isChecked !== wpcAdvInitialStates[name]) {
            wpcAdvPendingChanges[name] = isChecked ? "1" : "0";
        } else {
            delete wpcAdvPendingChanges[name];
        }
    }));
    $(".wpc-save-button").on("click", (function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $pill = $(".save-button");
        var btnOrigHTML = $btn.html();
        if ($btn.hasClass("saving")) return false;
        $btn.addClass("saving");
        $btn.addClass("wpc-saving").css("pointer-events", "none");
        $btn.html('<span class="wpc-save-pill-spinner"></span> ' + (wpc_ajaxVar.saving || "Saving..."));
        $(".wpc-settings-body").css("pointer-events", "none").css("opacity", "0.7");
        var changes = [];
        $(".wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox").each((function() {
            var name = $(this).data("option-name") || $(this).attr("name");
            var checked = $(this).prop("checked");
            if (name) {
                var ajaxName = name.replace(/^options\[/, "").replace(/\]/g, "").replace(/\[/g, ",");
                var initialValue = wpcAdvInitialStates[name];
                if (initialValue === undefined || initialValue !== checked) {
                    changes.push({
                        name: ajaxName,
                        value: checked ? "1" : "0",
                        checked: checked ? "true" : "false"
                    });
                }
            }
        }));
        $('input[type="hidden"][name^="options["]', ".wpc-settings-body").each((function() {
            var name = $(this).attr("name");
            var value = $(this).val();
            if (name) {
                var ajaxName = name.replace(/^options\[/, "").replace(/\]/g, "").replace(/\[/g, ",");
                var initialValue = wpcAdvInitialStates[name];
                if (initialValue === undefined || initialValue !== value) {
                    changes.push({
                        name: ajaxName,
                        value: value,
                        checked: "false"
                    });
                }
            }
        }));
        var optLevel = $("#optimizationLevel").val();
        var optLevelImg = $("#optimizationLevel_img").val();
        if (optLevel && wpcAdvInitialStates["optimizationLevel"] !== optLevel) {
            changes.push({
                name: "qualityLevel",
                value: optLevel,
                checked: "false"
            });
        }
        $('input[type="text"], input[type="number"]', ".wpc-settings-body").not(".wpc-cf-connect-wrapper *").each((function() {
            var name = $(this).attr("name");
            var value = $(this).val();
            if (name && value !== "") {
                var ajaxName = name.replace(/^options\[/, "").replace(/\]/g, "").replace(/\[/g, ",");
                var initialValue = wpcAdvInitialStates[name] || wpcAdvInitialStates[$(this).attr("id")];
                if (initialValue === undefined || initialValue !== value) {
                    changes.push({
                        name: ajaxName,
                        value: value,
                        checked: "false"
                    });
                }
            }
        }));
        var $localQuality = $("#localQualityLevel");
        if ($localQuality.length) {
            var localVal = $localQuality.val();
            if (wpcAdvInitialStates["localQualityLevel"] !== localVal) {
                var optMap = {
                    0: "none",
                    1: "lossless",
                    2: "intelligent",
                    3: "ultra"
                };
                changes.push({
                    name: "local_qualityLevel",
                    value: localVal,
                    checked: "false"
                });
                changes.push({
                    name: "local_optimization",
                    value: optMap[parseInt(localVal)] || "none",
                    checked: "false"
                });
            }
        }
        var $localBackup = $("#localBackup");
        if ($localBackup.length) {
            var bkVal = $localBackup.val();
            if (wpcAdvInitialStates["localBackup"] !== bkVal) {
                changes.push({
                    name: "backup",
                    value: bkVal,
                    checked: "false"
                });
            }
        }
        var hadError = false;
        if (changes.length === 0) {
            $btn.removeClass("wpc-saving saving").css("pointer-events", "");
            $btn.html(btnOrigHTML);
            $(".wpc-settings-body").css("pointer-events", "").css("opacity", "");
            $pill.fadeOut(300);
            return false;
        }
        var changeKeys = changes.map((function(c) {
            return c.name;
        }));
        var saveStartTime = Date.now();
        $.ajax({
            url: ajaxurl,
            type: "POST",
            timeout: 12e4,
            data: {
                action: "wps_ic_ajax_v2_checkbox_batch",
                changes: JSON.stringify(changes),
                wps_ic_nonce: wpc_ajaxVar.nonce,
                apikey: wpc_ajaxVar.apikey || ""
            },
            success: function() {
                onAllSaved();
            },
            error: function() {
                hadError = true;
                onAllSaved();
            }
        });
        function onAllSaved() {
            if (hadError) {
                $btn.removeClass("wpc-saving saving").css("pointer-events", "");
                $btn.html(btnOrigHTML);
                $(".wpc-settings-body").css("pointer-events", "").css("opacity", "");
                return;
            }
            wpcAdvPendingChanges = {};
            $(".wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox").each((function() {
                var name = $(this).data("option-name") || $(this).attr("name");
                if (name) wpcAdvInitialStates[name] = $(this).prop("checked");
            }));
            $('input[type="hidden"][name^="options["]', ".wpc-settings-body").each((function() {
                var name = $(this).attr("name");
                if (name) wpcAdvInitialStates[name] = $(this).val();
            }));
            $('input[type="text"], input[type="number"]', ".wpc-settings-body").not(".wpc-cf-connect-wrapper *").each((function() {
                var name = $(this).attr("name") || $(this).attr("id");
                if (name) wpcAdvInitialStates[name] = $(this).val();
            }));
            var $lqr = $("#localQualityLevel");
            if ($lqr.length) wpcAdvInitialStates["localQualityLevel"] = $lqr.val();
            var $lbr = $("#localBackup");
            if ($lbr.length) wpcAdvInitialStates["localBackup"] = $lbr.val();
            if ($optSlider.length) wpcAdvInitialStates["optimizationLevel"] = $optSlider.val();
            setSettingsState();
            var adminBarSettings = [ "options_status_hide_in_admin_bar", "options_status_show_admin_bar_title" ];
            changeKeys.forEach((function(name) {
                if (adminBarSettings.indexOf(name) !== -1) {
                    var $card = $('.wpc-ic-settings-v4-checkbox[name="' + name + '"]').closest(".wpc-box-for-checkbox");
                    if (!$card.find(".wpc-refresh-badge").length) {
                        $card.find(".wpc-checkbox-title-holder h4").append('<span class="wpc-refresh-badge">' + (wpc_ajaxVar.refreshRequired || "Refresh required") + "</span>");
                    }
                }
            }));
            $.post(ajaxurl, {
                action: "wps_ic_purge_after_save",
                wps_ic_nonce: wpc_ajaxVar.nonce,
                changed_keys: changeKeys
            });
            var elapsed = Date.now() - saveStartTime;
            var savingDelay = Math.max(0, 800 - elapsed);
            setTimeout((function() {
                $btn.removeClass("wpc-saving").addClass("wpc-saved");
                $btn.html('<span class="wpc-save-pill-check-ico"></span> ' + (wpc_ajaxVar.saved || "Saved") + "!");
                var cfReloadSettings = [ "cf,cdn", "cf,assets", "status,show_admin_bar_title", "wpc_nextgen", "wpc_delivery_override" ];
                var needsReload = changeKeys.some((function(k) {
                    return cfReloadSettings.indexOf(k) !== -1;
                }));
                if (needsReload) {
                    setTimeout((function() {
                        window.location.reload();
                    }), 1e3);
                    return;
                }
                setTimeout((function() {
                    $pill.css({
                        transition: "all 0.5s cubic-bezier(0.16, 1, 0.3, 1)",
                        opacity: "0",
                        transform: "translateY(-8px) scale(0.98)"
                    });
                    setTimeout((function() {
                        $pill.hide().css({
                            opacity: "",
                            transform: "",
                            transition: ""
                        });
                        $btn.removeClass("wpc-saved saving").css("pointer-events", "");
                        $btn.html(btnOrigHTML);
                        $(".wpc-settings-body").css("pointer-events", "").css("opacity", "");
                        wpcSaveActive = false;
                        stopPillWatch();
                        $wpcPill.removeClass("wpc-save-pill-floating");
                    }), 500);
                }), 1e3);
            }), savingDelay);
        }
        return false;
    }));
    window.hasUnsavedChanges = function() {
        var changed = false;
        $(".wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox").each((function() {
            var name = $(this).data("option-name") || $(this).attr("name");
            if (name && wpcAdvInitialStates[name] !== undefined && $(this).prop("checked") !== wpcAdvInitialStates[name]) {
                changed = true;
                return false;
            }
        }));
        if (changed) return true;
        $('input[type="hidden"][name^="options["]', ".wpc-settings-body").each((function() {
            var name = $(this).attr("name");
            if (name && wpcAdvInitialStates[name] !== undefined && $(this).val() !== wpcAdvInitialStates[name]) {
                changed = true;
                return false;
            }
        }));
        if (changed) return true;
        $('input[type="text"], input[type="number"]', ".wpc-settings-body").not(".wpc-cf-connect-wrapper *").each((function() {
            var name = $(this).attr("name") || $(this).attr("id");
            if (name && wpcAdvInitialStates[name] !== undefined && $(this).val() !== wpcAdvInitialStates[name]) {
                changed = true;
                return false;
            }
        }));
        if (changed) return true;
        if ($optSlider.length && wpcAdvInitialStates["optimizationLevel"] !== undefined && $optSlider.val() !== wpcAdvInitialStates["optimizationLevel"]) return true;
        var $lq = $("#localQualityLevel");
        if ($lq.length && wpcAdvInitialStates["localQualityLevel"] !== undefined && $lq.val() !== wpcAdvInitialStates["localQualityLevel"]) return true;
        var $lb = $("#localBackup");
        if ($lb.length && wpcAdvInitialStates["localBackup"] !== undefined && $lb.val() !== wpcAdvInitialStates["localBackup"]) return true;
        return false;
    };
    window.checkUnsavedChanges = function() {
        if (window.hasUnsavedChanges()) {
            wpcSaveActive = true;
            $(".save-button").fadeIn(400);
            startPillWatch();
        } else {
            wpcSaveActive = false;
            $(".save-button").fadeOut(300);
            stopPillWatch();
        }
    };
    window.showSaveButton = function() {
        wpcSaveActive = true;
        $(".save-button").fadeIn(500);
        startPillWatch();
        $('input[name="wpc_preset_mode"]').val("custom");
        $("a", ".wpc-dropdown-menu").removeClass("active");
        $("button", ".wpc-dropdown").html("Custom");
        $('a[data-value="custom"]', ".wpc-dropdown-menu").addClass("active");
        $.post(ajaxurl, {
            action: "wpc_ic_ajax_set_preset",
            wps_ic_nonce: wpc_ajaxVar.nonce,
            value: "custom"
        }, (function(response) {}));
    };
    function hideSaveButton() {
        wpcSaveActive = false;
        stopPillWatch();
        $wpcPill.removeClass("wpc-save-pill-floating");
        $(".save-button").fadeOut(500);
    }
    function updateSlider(input) {
        if (!input) return;
        var val = Number(input.value);
        var min = Number(input.min) || 1;
        var max = Number(input.max) || 3;
        var pct = (val - min) / (max - min) * 100;
        var container = input.closest(".wpc-range-slider");
        if (container) container.style.setProperty("--slider-pos", pct + "%");
        var texts = input.closest(".wpc-opt-level-slider") || input.closest(".wpc-slider");
        if (texts) {
            $(texts).find(".wpc-slider-text>div").removeClass("active");
            $(texts).find('.wpc-slider-text>div[data-value="' + val + '"]').addClass("active");
        }
    }
    (function() {
        var range = document.getElementById("optimizationLevel");
        if (range) {
            updateSlider(range);
            range.addEventListener("input", (function() {
                updateSlider(range);
            }));
        }
    })();
    $(".wpc-slider-text>div").on("click", (function(e) {
        e.preventDefault();
        var selectedValue = $(this).data("value");
        var $slider = $(this).closest(".wpc-opt-level-slider");
        var range = $slider.find('input[type="range"]')[0] || document.getElementById("optimizationLevel");
        if (range) {
            range.value = selectedValue;
            updateSlider(range);
            $(range).trigger("change");
        }
        var rangeMin = $(".wpc-range-slider>input", ".wpc-slider").attr("min");
        var rangeMax = $(".wpc-range-slider>input", ".wpc-slider").attr("max");
        const newValue = Number((selectedValue - rangeMin) * 100 / (rangeMax - rangeMin)), newPosition = 16 - newValue * .32;
        document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);
        $(".wpc-range-slider input").prop("value", selectedValue).attr("value", selectedValue);
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
        return false;
    }));
    $("button", ".wpc-dropdown").on("click", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var menu = $(this).siblings(".wpc-dropdown-menu");
        var isOpen = menu.hasClass("show");
        $(".wpc-dropdown-menu").removeClass("show");
        if (!isOpen) {
            menu.addClass("show");
        }
    }));
    $(document).on("click", (function(e) {
        if (!$(e.target).closest(".wpc-dropdown").length) {
            $(".wpc-dropdown-menu").removeClass("show");
        }
    }));
    $(".dropdown-item", ".wpc-dropdown-menu").on("click", (function(e) {
        e.preventDefault();
        var item = $(this);
        var value = $(this).data("value");
        var presetTitle = $(this).data("preset-title");
        $('input[name="wpc_preset_mode"]').val(value);
        $(".dropdown-item", ".wpc-dropdown-menu").removeClass("active");
        $(item).addClass("active");
        $(".wpc-dropdown-menu").removeClass("show");
        $(".wpc-dropdown>button").text(presetTitle);
        $.post(ajaxurl, {
            action: "wpc_ic_ajax_set_preset",
            value: value,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            var configuration = response.data;
            $.each(configuration, (function(index, element) {
                var iconCheckbox = false;
                var iconCheckboxParent = false;
                if (Object.keys(element).length > 1) {
                    $.each(element, (function(subindex, subelement) {
                        iconCheckbox = $('input[name="options[' + index + "][" + subindex + ']"]');
                        if (subelement == 1 || subelement == "1") {
                            $('input[name="options[' + index + "][" + subindex + ']"]').attr("checked", "checked").prop("checked", true);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.addClass("active");
                            }
                        } else {
                            $('input[name="options[' + index + "][" + subindex + ']"]').removeAttr("checked").prop("checked", false);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.removeClass("active");
                            }
                        }
                    }));
                } else {
                    if (index == "live-cdn") {
                        if (element == 1 || element == "1") {
                            $('input[name="options[' + index + ']"]').val("1");
                        } else {
                            $('input[name="options[' + index + ']"]').val("0");
                        }
                    } else {
                        iconCheckbox = $('input[name="options[' + index + ']"]');
                        if (element == 1 || element == "1") {
                            $('input[name="options[' + index + ']"]').attr("checked", "checked").prop("checked", true);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.addClass("active");
                            }
                        } else {
                            $('input[name="options[' + index + ']"]').removeAttr("checked").prop("checked", false);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.removeClass("active");
                            }
                        }
                    }
                }
            }));
            var rangeMin = $(".wpc-range-slider>input", ".wpc-slider").attr("min");
            var rangeMax = $(".wpc-range-slider>input", ".wpc-slider").attr("max");
            const newValue = Number((configuration.qualityLevel - rangeMin) * 100 / (rangeMax - rangeMin)), newPosition = 16 - newValue * .32;
            document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);
            $("#optimizationLevel").prop("value", configuration.qualityLevel).attr("value", configuration.qualityLevel);
            $(".save-button").fadeIn(500);
        }));
        return false;
    }));
    window.addEventListener("click", (function(e) {
        if (e.target.closest(".wpc-dropdown") === null) {}
    }));
    $(".wpc-tab-content-eu-routing, .wpc-auto-optimize-card").on("click", (function(e) {
        if ($(e.target).is("input, label, .wpc-switch, .wpc-switch-slider")) return;
        var $cb = $('input[type="checkbox"]', this);
        $cb.prop("checked", !$cb.prop("checked")).trigger("change");
    }));
    $(".wpc-box-for-checkbox").on("click", (function(e) {
        var box = $(this);
        var circle = $(".circle-check", box);
        var checkbox = $(".wpc-ic-settings-v4-checkbox", box);
        var connectedOption = $(checkbox).data("connected-slave-option");
        var outerParent = $(checkbox).parents(".wpc-tab-content-box");
        var id = $(outerParent).attr("id");
        if ($(box).hasClass("wpc-locked")) {
            return false;
        }
        var showPopup = $(checkbox).hasClass("wpc-show-popup");
        var popupID = $(checkbox).data("popup");
        var popupCustomButtons = $(checkbox).data("custom-buttons");
        var showConfirmButton = true;
        var popupClass = "";
        if (popupCustomButtons == true) {
            showConfirmButton = false;
            popupClass = "wpc-popup-custom-padding";
        }
        if ($(e.target).is("span")) {
            e.preventDefault();
        }
        var beforeValue = $(".wpc-ic-settings-v4-checkbox", box).attr("checked");
        if (beforeValue == "checked") {
            $(".wpc-ic-settings-v4-checkbox", box).removeAttr("checked").prop("checked", false);
            $(circle).removeClass("active");
        } else {
            if (showPopup && popupID != "") {
                var support_url = typeof whtlbl_vars !== "undefined" && whtlbl_vars.author_url ? whtlbl_vars.author_url : "https://wpcompress.com/support/";
                var compatPopups = [ "delay-js", "combine-js", "combine-css", "connectivity" ];
                var swalWidth = popupID === "hide_compress" ? 480 : compatPopups.indexOf(popupID) !== -1 ? 680 : 600;
                WPCSwal.fire({
                    title: "",
                    html: jQuery("#" + popupID + "-popup").html(),
                    width: swalWidth,
                    showCloseButton: true,
                    showCancelButton: false,
                    showConfirmButton: showConfirmButton,
                    allowOutsideClick: false,
                    customClass: {
                        container: "no-padding-popup-bottom-bg switch-legacy-popup " + popupClass,
                        popup: "popup-" + popupID
                    },
                    onOpen: () => {
                        if (popupID === "hide_compress") {
                            $(".swal2-popup").css("width", "480px");
                        }
                        if (!showConfirmButton) {
                            $(".wpc-popup-cancel").on("click", (function(e) {
                                e.preventDefault();
                                WPCSwal.clickCancel();
                                window.open(support_url, "_blank");
                                return false;
                            }));
                            $(".wpc-popup-confirm").on("click", (function(e) {
                                e.preventDefault();
                                WPCSwal.clickConfirm();
                                return false;
                            }));
                        }
                    }
                }).then((result => {
                    if (result.value) {
                        $(".wpc-ic-settings-v4-checkbox", box).attr("checked", "checked").prop("checked", true);
                        $(circle).addClass("active");
                        var newSettingsSate = getSettingsState();
                        if (didSettingsChanged(settingsState, newSettingsSate)) {
                            showSaveButton();
                        } else {
                            hideSaveButton();
                        }
                    } else {}
                }));
            } else {
                $(".wpc-ic-settings-v4-checkbox", box).attr("checked", "checked").prop("checked", true);
                $(circle).addClass("active");
            }
        }
        if ($('input[data-connected-option="' + connectedOption + '"]').length) {
            var slaveOption = $('input[data-connected-option="' + connectedOption + '"]');
            if (beforeValue == "checked") {
                $(slaveOption).removeAttr("checked").prop("checked", false);
            } else {
                $(slaveOption).attr("checked", "checked").prop("checked", true);
            }
        }
        checkIfAllSelected($(outerParent), "", "select-all-" + id);
        var newSettingsSate = getSettingsState();
        if ($(this).closest(".wp-compress-mu-content").length > 0) {
            return false;
        }
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(".wpc-input-holder>input,.wpc-input-holder>textarea").on("keyup", (function(e) {
        showSaveButton();
    }));
    $(".wpc-iconcheckbox").on("click", (function(e) {
        var box = $(this);
        if ($(e.target).is("span")) {
            e.preventDefault();
        }
        if ($(box).hasClass("wpc-locked-checkbox-container")) {
            return false;
        }
        var beforeValue = $(".wpc-ic-settings-v4-iconcheckbox", box).attr("checked");
        if (beforeValue == "checked") {
            $(".wpc-ic-settings-v4-iconcheckbox", box).removeAttr("checked").prop("checked", false);
            $(box).removeClass("active");
        } else {
            $(".wpc-ic-settings-v4-iconcheckbox", box).attr("checked", "checked").prop("checked", true);
            $(box).addClass("active");
        }
        var tab = $(box).parents(".wpc-tab-content");
        var tabID = $(tab).attr("id");
        if (tabID) {
            updateSelectAllButton(tabID);
        }
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(".wpc-dropdown-setting").on("change", (function(e) {
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(".wpc-preset-dropdown").on("change", (function(e) {
        var presetValue = $(this).val();
        $.post(ajaxurl, {
            action: "wpc_ic_ajax_set_preset",
            value: presetValue,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, (function(response) {
            $(".save-button").fadeIn(500);
            var configuration = response.data;
            $.each(configuration, (function(index, element) {
                var iconCheckbox = false;
                var iconCheckboxParent = false;
                if (Object.keys(element).length > 1) {
                    $.each(element, (function(subindex, subelement) {
                        iconCheckbox = $('input[name="options[' + index + "][" + subindex + ']"]');
                        if (subelement == 1 || subelement == "1") {
                            $('input[name="options[' + index + "][" + subindex + ']"]').attr("checked", "checked").prop("checked", true);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.addClass("active");
                            }
                        } else {
                            $('input[name="options[' + index + "][" + subindex + ']"]').removeAttr("checked").prop("checked", false);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.removeClass("active");
                            }
                        }
                    }));
                } else {
                    if (index == "live-cdn") {
                        if (element == 1 || element == "1") {
                            $('input[name="options[' + index + ']"]').val("1");
                        } else {
                            $('input[name="options[' + index + ']"]').val("0");
                        }
                    } else {
                        iconCheckbox = $('input[name="options[' + index + ']"]');
                        if (element == 1 || element == "1") {
                            $('input[name="options[' + index + ']"]').attr("checked", "checked").prop("checked", true);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.addClass("active");
                            }
                        } else {
                            $('input[name="options[' + index + ']"]').removeAttr("checked").prop("checked", false);
                            if ($(iconCheckbox).hasClass("wpc-ic-settings-v4-iconcheckbox")) {
                                iconCheckboxParent = $(iconCheckbox).parents(".wpc-iconcheckbox");
                                iconCheckboxParent.removeClass("active");
                            }
                        }
                    }
                }
            }));
        }));
    }));
    var settingsState = [];
    function setSettingsState() {
        var debug = [];
        settingsState = [];
        $('input[type="checkbox"],input[type="range"]', ".wpc-settings-body").each((function(i, item) {
            var checkbox = $(item);
            var state = 0;
            if (!$(checkbox).hasClass("wpc-checkbox-select-all") && !$(checkbox).hasClass("wpc-checkbox-connected-option")) {
                if (!$(item).is('input[type="range"]') && $(item).is('input[type="checkbox"]')) {
                    if ($(checkbox).is(":checked")) {
                        settingsState.push(1);
                    } else {
                        settingsState.push(0);
                    }
                } else {
                    debug.push([ $(item), state ]);
                    if ($(item).is('input[type="range"]')) {
                        state = $(item).attr("value");
                        state = parseInt(state);
                        settingsState.push(state);
                    }
                }
            }
        }));
        $('input[type="hidden"][name^="options["]', ".wpc-settings-body").each((function() {
            settingsState.push($(this).val());
        }));
    }
    function getSettingsState() {
        var debug = [];
        var getSettingsState = [];
        $('input[type="checkbox"],input[type="range"]', ".wpc-settings-body").each((function(i, item) {
            var checkbox = $(item);
            var state = 0;
            if (!$(checkbox).hasClass("wpc-checkbox-select-all") && !$(checkbox).hasClass("wpc-checkbox-connected-option")) {
                if (!$(item).is('input[type="range"]') && $(item).is('input[type="checkbox"]')) {
                    if ($(checkbox).is(":checked")) {
                        getSettingsState.push(1);
                    } else {
                        getSettingsState.push(0);
                    }
                } else {
                    debug.push([ $(item), state ]);
                    if ($(item).is('input[type="range"]')) {
                        state = $(item).attr("value");
                        state = parseInt(state);
                        getSettingsState.push(state);
                    }
                }
            }
        }));
        $('input[type="hidden"][name^="options["]', ".wpc-settings-body").each((function() {
            getSettingsState.push($(this).val());
        }));
        return getSettingsState;
    }
    setSettingsState();
    $(".wpc-eu-routing-checkbox").on("change", (function() {
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    function didSettingsChanged(o, n) {
        for (var i = 0; i < o.length; i++) {
            if (o[i] != n[i]) {
                return true;
            }
        }
        return false;
    }
    $("li>a", ".wpc-settings-tab-list").on("click", (function(e) {
        e.preventDefault();
        var link = $(this);
        if ($(link).hasClass("active")) {
            return;
        }
        if ($(link).hasClass("wpc-locked-checkbox")) {
            return false;
        }
        var data = $(link).data("tab");
        var currentActiveContent = $("div.active-tab", ".wpc-settings-tab-content");
        history.pushState({}, "", "#" + data);
        $(".wpc-settings-tab-list li>a.active").removeClass("active");
        $(link).addClass("active");
        $(".wpc-settings-tab-list li>a").removeClass("wpc-tooltip-flash");
        $(link).addClass("wpc-tooltip-flash");
        setTimeout((function() {
            $(link).removeClass("wpc-tooltip-flash");
        }), 3e3);
        $(".wpc-settings-tab-content-inner>div.wpc-tab-content").hide();
        $(".wpc-tab-content-box", "#" + data).each((function(i, item) {
            checkIfAllSelected($(item), data);
        }));
        $("div#" + data, ".wpc-settings-tab-content").addClass("active-tab").fadeIn(400, (function() {
            if (data === "dashboard" && window.wpcInitChart) {
                window.wpcInitChart(true);
            } else if (window.myLine) {
                window.myLine.resize();
            }
        }));
        $(currentActiveContent).removeClass("active-tab");
        var $wrapper = $(".wpc-advanced-settings-container-v4");
        if ($wrapper.hasClass("wpc-mobile-menu-open")) {
            $wrapper.removeClass("wpc-mobile-menu-open");
            $("#wpc-mobile-menu-toggle").attr("aria-expanded", "false");
            document.body.style.overflow = "";
        }
        var pluginTop = document.querySelector(".wpc-advanced-settings-container-v4");
        if (pluginTop) {
            pluginTop.scrollIntoView({
                behavior: "smooth"
            });
        }
        return false;
    }));
    var hash = window.location.hash;
    var targetTab = hash != "" ? hash.replace("#", "").split("&")[0].split("?")[0] : "dashboard";
    var $initLink = $('.wpc-settings-tab-list li>a[data-tab="' + targetTab + '"]');
    $initLink.addClass("active");
    $("div#" + targetTab, ".wpc-settings-tab-content").addClass("active-tab").show();
    history.replaceState({}, "", "#" + targetTab);
    if (window.wpcInitChart) {
        setTimeout(window.wpcInitChart, 250);
    }
    requestAnimationFrame((function() {
        $(".wpc-settings-body").addClass("wpc-tabs-ready");
    }));
    $(".wpc-ic-settings-v4-iconcheckbox").on("change", (function(e) {
        e.preventDefault();
        var allSelected = true;
        var tab = $(this).parents(".wpc-tab-content");
        var tabID = $(tab).attr("id");
        var parent = $(this).parents(".wpc-iconcheckbox");
        var beforeValue = $(this).attr("checked");
        if ($(this).hasClass("wpc-locked-checkbox")) {
            return false;
        }
        if (beforeValue == "checked") {
            $(".wpc-checkbox-select-all", tab).removeAttr("checked").prop("checked", false);
            $(this).removeAttr("checked").prop("checked", false);
            $(parent).removeClass("active");
            $('input[type="checkbox"]', "#" + tabID).each((function(i, item) {
                if (typeof $(item).data("for-div-id") == "undefined") {
                    if (!$(item).is(":checked")) {
                        allSelected = false;
                    }
                }
            }));
            if (allSelected) {
                $('input[data-for-div-id="' + tabID + '"]').removeAttr("checked").prop("checked", false);
            }
            updateSelectAllButton(tabID);
        } else {
            $(this).attr("checked", "checked").prop("checked", true);
            $(parent).addClass("active");
            $('input[type="checkbox"]', "#" + tabID).each((function(i, item) {
                if (typeof $(item).data("for-div-id") == "undefined") {
                    if (!$(item).is(":checked")) {
                        allSelected = false;
                    }
                }
            }));
            if (allSelected) {
                $('input[data-for-div-id="' + tabID + '"]').attr("checked", "checked").prop("checked", true);
            }
            updateSelectAllButton(tabID);
        }
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
        return false;
    }));
    $('input[type="checkbox"].wpc-ic-settings-v4-checkbox').on("change", (function() {
        var checkbox = $(this);
        var parent = $(checkbox).parents(".wpc-box-for-checkbox");
        var circle = $(".circle-check", parent);
        var beforeValue = $(checkbox).attr("checked");
        var showPopup = $(this).hasClass("wpc-show-popup");
        var popupID = $(this).data("popup");
        if ($(checkbox).hasClass("wpc-locked-checkbox")) {
            return false;
        }
        var connectedOption = $(checkbox).data("connected-slave-option");
        var outerParent = $(checkbox).parents(".wpc-tab-content-box");
        var id = $(outerParent).attr("id");
        var tabID = $(outerParent).attr("id");
        if (beforeValue == "checked") {
            $(circle).removeClass("active");
            $(this).removeAttr("checked").prop("checked", false);
            $(parent).removeClass("active");
        } else {
            $(circle).addClass("active");
            $(this).attr("checked", "checked").prop("checked", true);
            $(parent).addClass("active");
        }
        if ($('input[data-connected-option="' + connectedOption + '"]').length) {
            var slaveOption = $('input[data-connected-option="' + connectedOption + '"]');
            if (beforeValue == "checked") {
                $(slaveOption).removeAttr("checked").prop("checked", false);
            } else {
                $(slaveOption).attr("checked", "checked").prop("checked", true);
            }
        }
        checkIfAllSelected($(outerParent), "", "select-all-" + id);
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(".wpc-checkbox-connected-option").on("change", (function(e) {
        var beforeValue = $(this).attr("checked");
        var connectedOption = $(this).data("connected-option");
        var input = $('input[type="checkbox"].wpc-ic-settings-v4-checkbox#' + connectedOption);
        var parent = $(input).parents(".wpc-box-for-checkbox");
        var circle = $(".circle-check", parent);
        if (beforeValue == "checked") {
            $(this).removeAttr("checked");
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox#' + connectedOption).removeAttr("checked").prop("checked", false);
            $(circle).removeClass("active");
        } else {
            $(this).attr("checked", "checked");
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox#' + connectedOption).attr("checked", "checked").prop("checked", true);
            $(circle).addClass("active");
        }
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(document).on("click", ".wpc-select-all-btn", (function(e) {
        e.preventDefault();
        var $btn = $(this);
        var divID = $btn.data("for-div-id");
        var $checkbox = $btn.closest(".form-check").find(".wpc-checkbox-select-all");
        if ($checkbox.hasClass("wpc-locked-checkbox")) {
            return false;
        }
        var isChecked = $checkbox.attr("checked") === "checked";
        if (isChecked) {
            $(".wpc-iconcheckbox", "#" + divID).removeClass("active");
            $checkbox.removeAttr("checked").prop("checked", false);
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', "#" + divID).removeAttr("checked").prop("checked", false);
            $(".circle-check", "#" + divID).removeClass("active");
            $btn.text(wpc_ajaxVar.selectAll).removeClass("active");
        } else {
            $(".wpc-iconcheckbox", "#" + divID).addClass("active");
            $checkbox.attr("checked", "checked").prop("checked", true);
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', "#" + divID).attr("checked", "checked").prop("checked", true);
            $(".circle-check", "#" + divID).addClass("active");
            $btn.text(wpc_ajaxVar.deselectAll).addClass("active");
        }
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(".wpc-checkbox-select-all").on("change", (function(e) {
        var divID = $(this).data("for-div-id");
        if ($(this).hasClass("wpc-locked-checkbox")) return false;
        updateSelectAllButton(divID);
    }));
    function updateSelectAllButton(divID) {
        var allSelected = true;
        $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', "#" + divID).each((function() {
            if (!$(this).is(":checked")) {
                allSelected = false;
                return false;
            }
        }));
        var $btn = $('.wpc-select-all-btn[data-for-div-id="' + divID + '"]');
        var $checkbox = $('input.wpc-checkbox-select-all[data-for-div-id="' + divID + '"]');
        if (allSelected) {
            $checkbox.attr("checked", "checked").prop("checked", true);
            $btn.text(wpc_ajaxVar.deselectAll).addClass("active");
        } else {
            $checkbox.removeAttr("checked").prop("checked", false);
            $btn.text(wpc_ajaxVar.selectAll).removeClass("active");
        }
    }
    function checkIfAllSelected(div, divID, allCheck = "") {
        var allSelected = true;
        $('input[type="checkbox"]', div).each((function(i, item) {
            if (typeof $(item).data("for-div-id") == "undefined") {
                if ($(item).is(":checked") == false) {
                    allSelected = false;
                }
            }
        }));
        if (allCheck != "") {
            if (allSelected) {
                $("input#" + allCheck).attr("checked", "checked").prop("checked", true);
            } else {
                $("input#" + allCheck).removeAttr("checked").prop("checked", false);
            }
        } else {
            if (allSelected) {
                $("input.wpc-checkbox-select-all", div).attr("checked", "checked").prop("checked", true);
            } else {
                $("input.wpc-checkbox-select-all", div).removeAttr("checked").prop("checked", false);
            }
        }
        var sectionDivID = $(div).attr("id") || divID;
        if (sectionDivID) {
            updateSelectAllButton(sectionDivID);
        }
    }
    $(".wpc-select-all-btn").each((function() {
        var divID = $(this).data("for-div-id");
        if (divID) {
            updateSelectAllButton(divID);
        }
    }));
    $(document).on("click", ".wpc-cf-dropdown .wpc-cf-dropdown-toggle", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $dropdown = $(this).closest(".wpc-cf-dropdown");
        var isExpanded = $(this).attr("aria-expanded") === "true";
        $(".wpc-cf-dropdown").removeClass("show");
        $(".wpc-cf-dropdown .wpc-cf-dropdown-toggle").attr("aria-expanded", "false");
        $(".wpc-cf-dropdown-menu").removeClass("show");
        if (!isExpanded) {
            $dropdown.addClass("show");
            $(this).attr("aria-expanded", "true");
            $dropdown.find(".wpc-cf-dropdown-menu").addClass("show");
        }
    }));
    $(document).on("click", ".wpc-cf-select-button", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $dropdown = $(this).closest(".wpc-cf-select-dropdown");
        var isOpen = $dropdown.hasClass("is-open");
        $(".wpc-cf-select-dropdown").removeClass("is-open");
        $(".wpc-tab-content-box").removeClass("wpc-dropdown-lifted");
        if (!isOpen) {
            $dropdown.addClass("is-open");
            $dropdown.closest(".wpc-tab-content-box").addClass("wpc-dropdown-lifted");
        }
    }));
    $(document).on("click", ".wpc-cf-select-item", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $item = $(this);
        var $dropdown = $item.closest(".wpc-cf-select-dropdown");
        var $button = $dropdown.find(".wpc-cf-select-button");
        var $hiddenInput = $dropdown.closest(".wpc-box-check").find('input[type="hidden"]');
        var value = $item.data("value");
        var title = $item.data("preset-title");
        $hiddenInput.val(value);
        $button.find(".selected-text").text(title);
        $dropdown.find(".wpc-cf-select-item").removeClass("wpc-cf-active");
        $item.addClass("wpc-cf-active");
        $dropdown.removeClass("is-open");
        $dropdown.closest(".wpc-tab-content-box").removeClass("wpc-dropdown-lifted");
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(document).on("click", (function(e) {
        if (!$(e.target).closest(".wpc-cf-select-dropdown").length) {
            $(".wpc-cf-select-dropdown").removeClass("is-open");
            $(".wpc-tab-content-box").removeClass("wpc-dropdown-lifted");
        }
    }));
    $(document).on("click", ".wpc-font-dropdown .wpc-font-dropdown-toggle", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $dropdown = $(this).closest(".wpc-font-dropdown");
        var isExpanded = $(this).attr("aria-expanded") === "true";
        $(".wpc-font-dropdown").removeClass("show");
        $(".wpc-font-dropdown .wpc-font-dropdown-toggle").attr("aria-expanded", "false");
        $(".wpc-font-dropdown-menu").removeClass("show");
        if (!isExpanded) {
            $dropdown.addClass("show");
            $(this).attr("aria-expanded", "true");
            $dropdown.find(".wpc-font-dropdown-menu").addClass("show");
        }
    }));
    $(document).on("click", ".wpc-font-select-button", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $dropdown = $(this).closest(".wpc-font-select-dropdown");
        var $menu = $dropdown.find(".wpc-font-select-menu");
        var isOpen = $menu.is(":visible");
        $(".wpc-font-select-menu").hide();
        if (!isOpen) {
            $menu.show();
        }
    }));
    $(document).on("click", ".wpc-font-select-item", (function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $item = $(this);
        var $dropdown = $item.closest(".wpc-font-select-dropdown");
        var $button = $dropdown.find(".wpc-font-select-button");
        var $hiddenInput = $dropdown.closest(".wpc-box-check").find('input[type="hidden"]');
        var value = $item.data("value");
        var title = $item.data("preset-title");
        $hiddenInput.val(value);
        $button.text(title);
        $dropdown.find(".wpc-font-select-item").removeClass("wpc-font-active");
        $item.addClass("wpc-font-active");
        $dropdown.find(".wpc-font-select-menu").hide();
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    }));
    $(document).on("click", (function(e) {
        if (!$(e.target).closest(".wpc-font-select-dropdown").length) {
            $(".wpc-font-select-menu").hide();
        }
    }));
    function injectPerfGridEnhancements(container) {
        var infoSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>';
        $(container).find(".wpc-perf-grid > .wpc-box-for-checkbox, .wpc-perf-grid > .wpc-box-for-input").each((function() {
            var $p = $(this).find(".wpc-box-content > p");
            var $h4 = $(this).find(".wpc-checkbox-title-holder h4");
            if ($p.length && $h4.length && !$h4.find(".wpc-info-trigger").length) {
                var tooltipText = $p.text().trim();
                if (tooltipText) {
                    var trigger = $('<span class="wpc-info-trigger">' + infoSvg + '<span class="wpc-info-tooltip">' + $p.html() + "</span></span>");
                    $h4.append(trigger);
                    $p.remove();
                }
            }
        }));
        $(container).find(".wpc-perf-grid .wps-ic-configure-popup .wpc-gear-icon").each((function() {
            var $link = $(this).closest(".wps-ic-configure-popup");
            if (!$link.find(".wpc-configure-label").length) {
                $link.append('<span class="wpc-configure-label">Configure</span>');
            }
        }));
    }
    (function() {
        var container = document.getElementById("image-optimization-options");
        if (container) injectPerfGridEnhancements(container);
    })();
    (function() {
        var container = document.getElementById("scan-fonts");
        if (container) injectPerfGridEnhancements(container);
    })();
    (function() {
        var container = document.getElementById("ux-settings-options");
        if (container) injectPerfGridEnhancements(container);
    })();
    (function() {
        var container = document.getElementById("performance-tweaks-options");
        if (!container) return;
        $(container).find(".wpc-compact-rows .tab-title-checkbox").each((function() {
            if (!$(this).find(".wpc-section-counter").length) {
                $(this).append('<span class="wpc-section-counter"></span>');
            }
        }));
        function updatePerfCounters() {
            var totalActive = 0, totalAll = 0;
            $(container).find(".wpc-compact-rows").each((function() {
                var boxes = $(this).find('.wpc-switch input[type="checkbox"]');
                var checked = $(this).find('.wpc-switch input[type="checkbox"]:checked');
                var counter = $(this).find(".wpc-section-counter");
                if (counter.length) counter.text(checked.length + " / " + boxes.length);
                totalActive += checked.length;
                totalAll += boxes.length;
            }));
            var activeEl = document.getElementById("wpc-perf-active");
            var totalEl = document.getElementById("wpc-perf-total");
            if (activeEl) activeEl.textContent = totalActive;
            if (totalEl) totalEl.textContent = totalAll;
        }
        $(container).on("change", ".wpc-switch input", updatePerfCounters);
        setTimeout(updatePerfCounters, 500);
        $(container).on("click", ".wpc-compact-rows .wpc-box-for-checkbox, .wpc-compact-rows .wpc-box-for-input", (function(e) {
            if ($(e.target).closest(".wpc-switch, .wpc-box-check, .wps-ic-configure-popup, .wpc-box-button").length) return;
            $(this).toggleClass("wpc-row-expanded");
        }));
        injectPerfGridEnhancements(container);
        var toggleBtn = document.getElementById("wpc-toggle-perf-descriptions");
        if (toggleBtn) {
            toggleBtn.addEventListener("click", (function() {
                var parent = document.getElementById("performance-tweaks-options");
                parent.classList.toggle("wpc-show-descriptions");
                var showing = parent.classList.contains("wpc-show-descriptions");
                this.textContent = showing ? "Hide descriptions" : "Show descriptions";
                this.classList.toggle("active", showing);
            }));
        }
    })();
}));

(function($) {
    if (!$ || typeof ajaxurl === "undefined") return;
    function wpcCfPermGridInit() {
        var acc = document.getElementById("wpc-cfperm-accordion");
        if (!acc) return;
        var checking = false;
        function chips() {
            return [].slice.call(acc.querySelectorAll(".wpc-cfperm-status"));
        }
        function setChecking() {
            chips().forEach((function(el) {
                el.className = "wpc-cfperm-status is-checking";
                el.innerHTML = '<span class="wpc-cfperm-spinner"></span>Checking';
            }));
            var f = document.getElementById("wpc-cfperm-foot-text");
            if (f) f.textContent = "Verifying each permission against the live Cloudflare API…";
            var b = document.getElementById("wpc-cfperm-recheck");
            if (b) b.disabled = true;
        }
        function resolveRows(tests) {
            var reqMiss = 0, optMiss = 0, unknown = 0;
            var rows = chips();
            rows.forEach((function(el, i) {
                var res = tests && tests[el.getAttribute("data-perm")] ? String(tests[el.getAttribute("data-perm")]) : "";
                var ok = res.indexOf("OK") === 0;
                if (res === "") {
                    unknown++;
                } else if (!ok) {
                    if (el.getAttribute("data-required") === "1") {
                        reqMiss++;
                    } else {
                        optMiss++;
                    }
                }
                setTimeout((function() {
                    if (res === "") {
                        el.className = "wpc-cfperm-status is-unknown";
                        el.textContent = "Not checked";
                    } else if (ok) {
                        el.className = "wpc-cfperm-status is-ok";
                        el.textContent = "✓ Granted";
                    } else {
                        el.className = "wpc-cfperm-status is-fail";
                        el.textContent = "✕ Missing";
                    }
                    var row = el.closest ? el.closest(".wpc-cfperm-row") : null;
                    if (row) {
                        row.classList.toggle("is-missing", res !== "" && !ok && el.getAttribute("data-required") === "1");
                        row.classList.toggle("is-granted", ok);
                    }
                }), 80 * i);
            }));
            var pill = document.getElementById("wpc-cfperm-pill");
            if (pill) {
                setTimeout((function() {
                    var cls, txt;
                    if (reqMiss) {
                        cls = "is-fail";
                        txt = reqMiss + " required permission(s) missing";
                    } else if (optMiss) {
                        cls = "is-warn";
                        txt = "Required granted · " + optMiss + " optional missing";
                    } else if (unknown) {
                        cls = "is-unknown";
                        txt = "Not verified yet";
                    } else {
                        cls = "is-ok";
                        txt = "Valid";
                    }
                    pill.className = "wpc-cfperm-pill " + cls;
                    pill.textContent = txt;
                    acc.classList.remove("is-allgood", "has-issues", "show-granted", "show-rows");
                    if (!reqMiss && !optMiss && !unknown) {
                        acc.classList.add("is-allgood");
                    } else if (reqMiss || optMiss) {
                        acc.classList.add("has-issues");
                    }
                    wpcCfPermGrantedToggle();
                }), 80 * rows.length);
            }
        }
        function wpcCfPermGrantedToggle() {
            var btn = document.getElementById("wpc-cfperm-showgranted");
            if (!btn) return;
            var n = acc.querySelectorAll(".wpc-cfperm-row.is-granted").length;
            btn.textContent = acc.classList.contains("show-granted") ? "Hide granted permissions" : "Show " + n + " granted permission" + (n === 1 ? "" : "s");
        }
        function done(text) {
            var f = document.getElementById("wpc-cfperm-foot-text");
            if (f) f.textContent = text;
            var b = document.getElementById("wpc-cfperm-recheck");
            if (b) b.disabled = false;
            checking = false;
        }
        function runCheck(force) {
            if (checking) return;
            var last = parseInt(acc.getAttribute("data-checked") || "0", 10);
            if (!force && last && Date.now() / 1e3 - last < 600) return;
            checking = true;
            setChecking();
            $.post(ajaxurl, {
                action: "wpc_cf_check_permissions",
                wps_ic_nonce: wpc_ajaxVar.nonce
            }, (function(res) {
                if (res && res.success && res.data && res.data.privileges && res.data.privileges.tests) {
                    acc.setAttribute("data-checked", String(Math.floor(Date.now() / 1e3)));
                    resolveRows(res.data.privileges.tests);
                    var pv = res.data.purge_verified;
                    done("Verified just now via live Cloudflare API checks." + (pv && pv.t ? " · HTML purge verified working end-to-end (Cache-Tag)." : ""));
                } else {
                    resolveRows(null);
                    done(res && res.data && res.data.msg ? res.data.msg : "Could not verify permissions — try again in a minute.");
                }
            })).fail((function() {
                resolveRows(null);
                done("The verification request failed (network/server) — try again in a minute.");
            }));
        }
        acc.addEventListener("toggle", (function() {
            if (acc.open) runCheck(false);
        }));
        var btn = document.getElementById("wpc-cfperm-recheck");
        if (btn) btn.addEventListener("click", (function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!acc.open) {
                acc.open = true;
            }
            runCheck(true);
        }));
        var sa = document.getElementById("wpc-cfperm-showall");
        if (sa) sa.addEventListener("click", (function(e) {
            e.preventDefault();
            acc.classList.toggle("show-rows");
            var chev = sa.querySelector(".wpc-cfperm-chev");
            sa.childNodes[0].textContent = acc.classList.contains("show-rows") ? "Hide details " : "Show details ";
            if (chev && !sa.contains(chev)) {
                sa.appendChild(chev);
            }
        }));
        var sg = document.getElementById("wpc-cfperm-showgranted");
        if (sg) sg.addEventListener("click", (function(e) {
            e.preventDefault();
            acc.classList.toggle("show-granted");
            wpcCfPermGrantedToggle();
        }));
        wpcCfPermGrantedToggle();
        if (acc.open) runCheck(false);
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", wpcCfPermGridInit);
    } else {
        wpcCfPermGridInit();
    }
})(window.jQuery);