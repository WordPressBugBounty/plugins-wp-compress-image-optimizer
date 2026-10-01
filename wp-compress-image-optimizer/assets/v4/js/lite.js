jQuery(document).ready((function($) {
    var ajaxurl = typeof wpc_ajaxVar !== "undefined" && wpc_ajaxVar.ajaxurl ? wpc_ajaxVar.ajaxurl : window.ajaxurl || "";
    $(".wpc-lite-toggle-advanced").on("click", (function(e) {
        e.preventDefault();
        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "wpsChangeGui",
                view: "advanced",
                nonce: wpc_ajaxVar.nonce
            },
            success: function(response) {
                window.location.reload();
            }
        });
        return false;
    }));
    // A locked toggle is a feature the plan does not include. Clicking it asks for the one thing
    // that unlocks it: an email, prefilled with the admin's own. wpcClaim comes from the settings
    // template: { email, nonce, pending, ajaxurl }.
    var claim = window.wpcClaim || {};
    var claimTimer = null;
    function claimEl(id) { return document.getElementById(id); }
    function closeClaim() { var m = claimEl("wpc-claim-modal"); if (m) { m.hidden = true; } }
    function claimSay(text, kind) { var s = claimEl("wpc-claim-state"); if (s) { s.textContent = text || ""; s.className = "wpc-claim-state" + (kind ? " is-" + kind : ""); s.hidden = !text; } }
    function schedulePoll(delay) {
        clearTimeout(claimTimer);
        claimTimer = setTimeout(pollClaim, delay);
    }
    function pollClaim() {
        $.ajax({ url: ajaxurl, type: "POST", data: { action: "wpc_claim_status", nonce: claim.nonce || wpc_ajaxVar.nonce } }).done(function(r) {
            var d = (r && r.data) ? r.data : {};
            if (d.state === "linked") { window.location.reload(); return; }
            if (d.state === "pending") {
                claimSay(d.msg, "pending");
                var n = (window.__wpcClaimPolls = (window.__wpcClaimPolls || 0) + 1);
                schedulePoll(n < 3 ? [2e4, 4e4, 8e4][n] : 3e5);
                return;
            }
            if (d.state === "expired" || d.state === "declined") { claimSay(d.msg, "warn"); var b = claimEl("wpc-claim-send"); if (b) { b.textContent = "Send a new one"; b.disabled = false; } }
        });
    }
    function openClaim(featureTitle) {
        var m = claimEl("wpc-claim-modal");
        if (!m) { return; }
        var title = claimEl("wpc-claim-title");
        if (title) { title.textContent = featureTitle ? "Turn on " + featureTitle : "Link this site"; }
        var b = claimEl("wpc-claim-send");
        if (b) { b.textContent = featureTitle ? "Turn on " + featureTitle : "Send link"; b.disabled = false; }
        var i = claimEl("wpc-claim-email");
        if (i && !i.value && claim.email) { i.value = claim.email; }
        claimSay("");
        if (claim.pending) { claimSay("Check " + claim.pending + " for a link from WP Compress.", "pending"); if (b) { b.textContent = "Send again"; } }
        m.hidden = false;
        if (i) { try { i.focus(); i.select(); } catch (z) {} }
    }
    function sendClaim() {
        var i = claimEl("wpc-claim-email"), b = claimEl("wpc-claim-send");
        var v = $.trim((i && i.value) || "");
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { claimSay("That doesn't look like an email.", "warn"); return; }
        if (b) { b.disabled = true; b.textContent = "Setting up…"; }
        claimSay("");
        $.ajax({ url: ajaxurl, type: "POST", timeout: 60000, data: { action: "wpc_claim", value: v, nonce: claim.nonce || wpc_ajaxVar.nonce } }).done(function(r) {
            var d = (r && r.data) ? r.data : {};
            if (r && r.success && d.state === "linked") { claimSay(d.msg || "Done. Reloading…", "ok"); window.location.reload(); return; }
            if (b) { b.disabled = false; b.textContent = d.state === "pending" ? "Send again" : "Turn on"; }
            claimSay(d.msg || "We could not reach WP Compress. Try again in a moment.", d.state === "pending" ? "pending" : "warn");
            if (d.state === "pending") { window.__wpcClaimPolls = 0; schedulePoll(2e4); }
        }).fail(function() {
            if (b) { b.disabled = false; b.textContent = "Turn on"; }
            claimSay("We could not reach WP Compress. Try again in a moment.", "warn");
        });
    }
    $(document).on("click", ".wpc-custom-btn.wpc-custom-btn-locked, .wpc-locked, a[href='#wpc-claim-resend'], .wpc-claim-open", function(e) {
        e.preventDefault();
        var lbl = $(this).find("[data-wpc-feature-title]").first();
        openClaim(lbl.length ? lbl.attr("data-wpc-feature-title") : ($(this).attr("data-wpc-feature-title") || ""));
        return false;
    });
    $(document).on("click", "#wpc-claim-send", function(e) { e.preventDefault(); sendClaim(); });
    $(document).on("click", "#wpc-claim-close, #wpc-claim-modal .wpc-claim-backdrop", function(e) { e.preventDefault(); closeClaim(); });
    $(document).on("keydown", "#wpc-claim-email", function(e) { if (e.key === "Enter") { e.preventDefault(); sendClaim(); } });
    if (claim.pending) { window.__wpcClaimPolls = 0; schedulePoll(2e4); }
    var initialStates = {};
    var pendingChanges = {};
    $(".wpc-box-for-checkbox-lite .wpc-ic-settings-v4-checkbox").each((function() {
        initialStates[$(this).attr("name")] = $(this).is(":checked");
    }));
    function checkForChanges() {
        var hasChanges = Object.keys(pendingChanges).length > 0;
        if (hasChanges) {
            $(".save-button").fadeIn(400);
        } else {
            $(".save-button").fadeOut(250);
        }
    }
    $(".wpc-box-for-checkbox-lite").on("click", (function(e) {
        e.preventDefault();
        if ($(this).hasClass("wpc-locked")) {
            return false;
        }
        var parent = $(this);
        var checkbox = $(".wpc-ic-settings-v4-checkbox", parent);
        var name = checkbox.attr("name");
        var wasChecked = checkbox.is(":checked");
        if (wasChecked) {
            checkbox.removeAttr("checked").prop("checked", false);
        } else {
            checkbox.attr("checked", "checked").prop("checked", true);
        }
        var nowChecked = checkbox.is(":checked");
        if (nowChecked !== initialStates[name]) {
            pendingChanges[name] = nowChecked ? "1" : "0";
        } else {
            delete pendingChanges[name];
        }
        checkForChanges();
        return false;
    }));
    $(".save-button-lite").on("click", (function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $pill = $(".save-button");
        var btnOrigHTML = $btn.html();
        var changeKeys = Object.keys(pendingChanges);
        if (changeKeys.length === 0) return false;
        $btn.addClass("wpc-saving").css("pointer-events", "none");
        $btn.html('<span class="wpc-save-pill-spinner"></span> ' + (wpc_ajaxVar.saving || "Saving..."));
        var hadError = false;
        // v7.21.149 — ONE request for the whole save. Firing wps_ic_ajax_checkbox per toggle
        // raced: every one of those requests reads the whole settings row, changes its own key
        // and writes the whole row back, so N parallel workers all start from the same pre-save
        // row and the last write wins — toggle five things, one is saved. The advanced screen
        // already moved to wps_ic_ajax_v2_checkbox_batch (one read/modify/write for all changes)
        var changes = changeKeys.map((function(settingName) {
            return {
                name: settingName.replace(/^options\[/, "").replace(/\]/g, "").replace(/\[/g, ","),
                value: pendingChanges[settingName],
                checked: pendingChanges[settingName] === "1" ? "true" : "false"
            };
        }));
        var purgeKeys = changes.map((function(c) {
            return c.name;
        }));
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
            success: function(response) {
                if (!response || response.success === false) hadError = true;
                onAllSaved();
            },
            error: function() {
                hadError = true;
                onAllSaved();
            }
        });
        function onAllSaved() {
            if (hadError) {
                $btn.removeClass("wpc-saving").css("pointer-events", "");
                $btn.html(btnOrigHTML);
                $pill.css("animation", "headShake 0.5s");
                setTimeout((function() {
                    $pill.css("animation", "");
                }), 600);
                return;
            }
            $btn.removeClass("wpc-saving").addClass("wpc-saved");
            $btn.html('<span class="wpc-save-pill-check-ico"></span> ' + (wpc_ajaxVar.saved || "Saved"));
            changeKeys.forEach((function(name) {
                initialStates[name] = pendingChanges[name] === "1";
            }));
            pendingChanges = {};
            $.post(ajaxurl, {
                action: "wps_ic_purge_after_save",
                wps_ic_nonce: wpc_ajaxVar.nonce,
                changed_keys: purgeKeys
            });
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
                    $btn.removeClass("wpc-saved").css("pointer-events", "");
                    $btn.html(btnOrigHTML);
                }), 500);
            }), 1e3);
        }
        return false;
    }));
    $(".wps-ic-initial-retest").on("click", (function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $boxContent = $btn.closest(".wpc-rounded-box").find(".wpc-box-content");
        $btn.addClass("wpc-v2-retest-loading");
        $btn.find("img").addClass("wpc-spin");
        $btn.css("pointer-events", "none");
        var $statsRow = $(".wpc-v2-stats-row").first();
        if ($statsRow.length) {
            var labels = [ "Page Size", "Requests", "Server Speed" ];
            var skeletonHtml = "";
            for (var i = 0; i < labels.length; i++) {
                skeletonHtml += '<div class="wpc-v2-stat-box wpc-v2-skeleton-box">' + '<span class="wpc-v2-stat-label">' + labels[i] + "</span>" + '<div class="wpc-v2-skeleton-value"><div class="loading-icon"><div class="inner"></div></div></div>' + '<div class="wpc-v2-skeleton-badge"><div class="wpc-ic-small-thick-placeholder" style="width:90px;"></div></div>' + '<div class="wpc-v2-stat-sep"></div>' + '<div class="wpc-v2-skeleton-before"><div class="wpc-ic-small-thick-placeholder" style="width:70px;"></div></div>' + "</div>";
            }
            $statsRow.addClass("wpc-v2-skeleton-row").html(skeletonHtml);
        }
        $boxContent.children().hide();
        $boxContent.find(".wpc-pagespeed-preparing").show();
        var $v2Card = $btn.closest(".wpc-v2-card");
        if ($v2Card.length) {
            $v2Card.find(".wpc-v2-scores, .wpc-v2-score-footer").hide();
            var $existingLoader = $v2Card.find(".wpc-v2-scores-loading");
            if ($existingLoader.length) {
                $existingLoader.show();
            } else {
                var barsSvg = '<svg width="135" height="140" viewBox="0 0 135 140" xmlns="http://www.w3.org/2000/svg" fill="#3990ef" style="width:40px;height:40px"><rect y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.5s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.5s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="30" y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.25s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.25s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="60" width="15" height="140" rx="6"><animate attributeName="height" begin="0s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="90" y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.25s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.25s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="120" y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.5s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.5s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect></svg>';
                $v2Card.find(".wpc-v2-card-header").after('<div class="wpc-v2-scores-loading">' + barsSvg + "<span>Analyzing performance...</span>" + "</div>");
            }
        }
        var isAgency = wpc_ajaxVar.apikey ? true : false;
        var resetAction = isAgency ? "wpc_agency_reset_test" : "wps_ic_resetTest";
        var pollAction = isAgency ? "wpc_agency_fetch_gps" : "wps_fetchInitialTest";
        var resetPayload = isAgency ? {
            action: resetAction,
            apikey: wpc_ajaxVar.apikey
        } : {
            action: resetAction,
            nonce: wpc_ajaxVar.nonce
        };
        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: resetPayload,
            success: function(response) {
                var retestPoll = setInterval((function() {
                    var pollPayload = isAgency ? {
                        action: pollAction,
                        apikey: wpc_ajaxVar.apikey
                    } : {
                        action: pollAction,
                        nonce: wpc_ajaxVar.nonce
                    };
                    $.post(ajaxurl, pollPayload, (function(res) {
                        if (res.success) {
                            clearInterval(retestPoll);
                            window.location.reload();
                        }
                    }));
                }), 5e3);
            }
        });
        return false;
    }));
    function countUp(element, target, duration) {
        var startTime = performance.now();
        function animate(currentTime) {
            var elapsed = currentTime - startTime;
            var progress = Math.min(elapsed / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = Math.round(eased * target);
            if (progress < 1) requestAnimationFrame(animate);
        }
        requestAnimationFrame(animate);
    }
    function initializeCircleProgressBars() {
        const circles = document.querySelectorAll(".page-stats-circle");
        circles.forEach((circle => {
            const progressBar = circle.querySelector(".circle-progress-bar-lite");
            const textElement = circle.querySelector(".stats-circle-text h5");
            const value = parseFloat(progressBar.getAttribute("data-value"));
            let gradient;
            let bgFill;
            if (value <= .55) {
                gradient = [ "#FF0000", "#FF6347" ];
                bgFill = "#FFE6E6";
            } else if (value <= .89) {
                gradient = [ "#FFD700", "#FFA500" ];
                bgFill = "#FEF7ED";
            } else if (value <= .89) {
                gradient = [ "#FFD700", "#FFA500" ];
                bgFill = "#FEF7ED";
            } else {
                gradient = [ "#22c55e", "#059669" ];
                bgFill = "#dcfce7";
            }
            $(progressBar).circleProgress({
                value: value,
                size: 100,
                thickness: 8,
                startAngle: -Math.PI / 2,
                lineCap: "round",
                fill: {
                    gradient: gradient
                },
                emptyFill: bgFill
            });
            countUp(textElement, Math.round(value * 100), 800);
        }));
    }
    initializeCircleProgressBars();
    function initializeV3CircleProgressBars() {
        var v3Rings = document.querySelectorAll(".wpc-v3-ring");
        if (!v3Rings.length) return;
        v3Rings.forEach((function(ring) {
            var progressBar = ring.querySelector(".circle-progress-bar-v3");
            if (!progressBar) return;
            var value = parseFloat(progressBar.getAttribute("data-value"));
            var isLarge = ring.classList.contains("wpc-v3-ring-lg");
            var size = 260;
            var gradient, bgFill;
            if (value <= .55) {
                gradient = [ "#ef4444", "#f87171" ];
                bgFill = "#fee2e2";
            } else if (value <= .89) {
                gradient = [ "#f59e0b", "#fbbf24" ];
                bgFill = "#fef3c7";
            } else {
                gradient = [ "#22c55e", "#059669" ];
                bgFill = "#dcfce7";
            }
            $(progressBar).circleProgress({
                value: value,
                size: size,
                thickness: isLarge ? 14 : 6,
                startAngle: -Math.PI / 2,
                lineCap: "round",
                fill: {
                    gradient: gradient
                },
                emptyFill: bgFill
            });
        }));
    }
    initializeV3CircleProgressBars();
    $(".wpc-cf-link").on("click", (function() {
        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "wpsChangeGui",
                view: "advanced",
                nonce: wpc_ajaxVar.nonce
            },
            success: function(response) {
                window.location.href = window.location.pathname + window.location.search + "#integrations";
                window.location.reload();
            }
        });
    }));
}));