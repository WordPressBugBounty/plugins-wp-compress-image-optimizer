jQuery(document).ready(function ($) {

    // v7.02 — Mirror to window so the global onbeforeunload handler below
    // can read it. Pre-fix: this var was IIFE-scoped, the global handler
    // threw "ReferenceError: allowRefresh is not defined" on every page
    // unload.
    var allowRefresh = true;
    window.allowRefresh = allowRefresh;
    // (v7.03.63) Counter-flow debug. Enable with ?wpc_counter_debug=1 on the media page (or
    // window.WPC_COUNTER_DEBUG=true in console) to trace the optimize → chip-climb → swap flow. Silent off.
    var WPC_CDBG = (typeof location !== 'undefined' && /[?&]wpc_counter_debug=1\b/.test(location.search))
                   || (typeof window !== 'undefined' && window.WPC_COUNTER_DEBUG === true);
    if (WPC_CDBG) console.log('[wpc-counter] debug ON');
    $('.ic-tooltip').tooltipster({
        maxWidth: '300',
        delay: 100,
        trigger: 'hover',
        theme: 'tooltipster-shadow',
        position: 'top',
    });

    /**
     * Media Library Button Group
     */
    $('body').on('click', '.wpc-show-btn-group', function (e) {
        e.preventDefault();

        var group = $(this).parent();

        if (!$(group).hasClass('visible')) {
            var hidden = $('.wpc-dropdown-item-hidden', group);

            $(group).addClass('visible');
            $(hidden).removeClass('wpc-dropdown-item-hidden');
            $('.wpc-show-btn-group>i', group).removeClass('icon-angle-down').addClass('icon-angle-up');
            $(hidden).addClass('wpc-dropdown-item-visible');
        } else {
            var hidden = $('.wpc-dropdown-item-visible', group);

            $(group).removeClass('visible');
            $(hidden).removeClass('wpc-dropdown-item-visible');
            $('.wpc-show-btn-group>i', group).addClass('icon-angle-down').removeClass('icon-angle-up');
            $(hidden).addClass('wpc-dropdown-item-hidden');
        }

        return false;
    });

    /**
     * 7.01.7 — Card swap with animated state transitions.
     * Detects state change (uncompressed → compressing → compressed → restoring → uncompressed)
     * and applies the matching entry animation. For Optimizing → Compressed (the hero moment),
     * inserts a checkmark that pops + slides out before the savings % rises in.
     * For savings-only changes (bg-swap), uses the title roll + pulse instead.
     */
    function wpcStateOf(classes) {
        if (!classes) return 'unknown';
        if (classes.indexOf('wpc-ml-card--compressed') >= 0) return 'compressed';
        if (classes.indexOf('is-compressing') >= 0)         return 'compressing';
        if (classes.indexOf('is-restoring') >= 0)            return 'restoring';
        // v7.02 — post-restore regen lock. Distinct state so the no-op
        // guard in wpcSwapCardWithAnimation treats regen-pending →
        // uncompressed as a state change and actually runs the swap
        // (otherwise both would be "uncompressed" and the swap would
        // skip, leaving the disabled button rendered forever).
        if (classes.indexOf('is-regen-pending') >= 0)        return 'regen-pending';
        if (classes.indexOf('wpc-ml-card--excluded') >= 0)   return 'excluded';
        if (classes.indexOf('wpc-ml-card--uncompressed') >= 0) return 'uncompressed';
        return 'unknown';
    }

    /**
     * 7.01.44 — World-class savings-% counter. rAF-driven tween from `fromPct`
     * to `toPct` over `duration` ms with easeOutExpo (decelerates dramatically
     * in the last 20% — gives the digits that "settle into place" feel). The
     * title element's text is replaced each frame with the eased value; at the
     * end we write `finalText` exactly so any non-numeric suffix (e.g. " Saved")
     * is preserved verbatim. The visual flourish (green glow + scale 1.04 pop)
     * runs in CSS via the .wpc-savings-counting class — see admin.media-library.less.
     */
    function wpcAnimateSavingsCounter($el, fromPct, toPct, finalText, duration, onComplete) {
        duration = duration || 900;
        var el = $el[0];
        if (!el) return;
        var startTime = null;
        function easeOutExpo(t) { return t === 1 ? 1 : 1 - Math.pow(2, -10 * t); }
        // 7.01.46 — Update the title's FIRST text node only, not the whole element.
        // The delta chip (.wpc-delta-chip) is appended as a sibling DOM node inside
        // the title; using $el.text() would destroy it every frame. Targeting just
        // the leading text node preserves the chip across the entire tween.
        function setLeadingText(text) {
            var first = el.firstChild;
            if (first && first.nodeType === 3) {       // TEXT_NODE
                first.nodeValue = text;
            } else {
                el.insertBefore(document.createTextNode(text), first || null);
            }
        }
        function tick(now) {
            if (startTime === null) startTime = now;
            var progress = Math.min((now - startTime) / duration, 1);
            var eased    = easeOutExpo(progress);
            var current  = fromPct + (toPct - fromPct) * eased;
            // Format with one decimal — matches PHP `number_format($x, 1)` in
            // media_library_live.class.php:854 so the digit count is stable.
            setLeadingText(current.toFixed(1) + '% Saved');
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                // Restore exact original text on completion. finalText comes from the
                // server-rendered new title, which is just text (no chip embedded in
                // the markup — chip is appended client-side). Safe to write as leading
                // text node, preserving any chip that's currently animating in.
                setLeadingText(finalText);
                if (typeof onComplete === 'function') onComplete();
            }
        }
        requestAnimationFrame(tick);
    }

    /**
     * v7.02 — Per-variant arrival badge. Identical visual to the legacy
     * heartbeat-driven chip (see line ~191) but invoked directly from the
     * 250 ms count-poller so every landing gets badged — not just the last
     * one to occupy the heartbeat transient before the next 3 s tick.
     *
     * Renders a "+JPEG Original"-style chip next to the .wpc-ml-title. Fades
     * out after 2.5 s. Idempotent per-call: the previous chip is removed
     * before the new one is appended so we never stack.
     */
    function wpcShowVariantBadge(imageID, fmt, size) {
        if (!fmt) return;
        var $card  = $('.wps-ic-media-actions-' + imageID).find('.wpc-ml-card').first();
        if (!$card.length) return;
        var $title = $card.find('.wpc-ml-title').first();
        if (!$title.length) return;

        var fmtUp    = String(fmt).toUpperCase();
        var fmtClass = 'wpc-delta-chip--' + fmtUp.toLowerCase();
        var safeFmt  = fmtUp.replace(/[^A-Z0-9]/g, '');
        var safeSize = String(size || '').replace(/[<>"']/g, '');
        var chipMarkup = '<span class="wpc-delta-chip-plus" aria-hidden="true">+</span>'
                       + '<span class="wpc-delta-chip-fmt">' + safeFmt + '</span>'
                       + '<span class="wpc-delta-chip-size">' + safeSize + '</span>';
        $card.find('.wpc-delta-chip').remove();
        var $chip = $('<span class="wpc-delta-chip ' + fmtClass + '" role="status" aria-live="polite">' + chipMarkup + '</span>');
        $title.append($chip);

        if (window['wpcChipFade_' + imageID])   clearTimeout(window['wpcChipFade_' + imageID]);
        if (window['wpcChipRemove_' + imageID]) clearTimeout(window['wpcChipRemove_' + imageID]);
        // v7.02 — World-class timing. Hold the chip for 3500ms (readable
        // without dragging on), fade-out 600ms. Paired with WPC_BADGE_SPACING_MS
        // (800ms) below so even tight bursts give each chip ~800ms of standalone
        // visibility before the next one replaces it, and the last chip in any
        // burst gets the full 3500ms hold.
        window['wpcChipFade_' + imageID]   = setTimeout(function () { $chip.addClass('wpc-delta-chip-fade'); }, 3500);
        window['wpcChipRemove_' + imageID] = setTimeout(function () { $chip.remove(); }, 4100);
    }

    /**
     * v7.02 — Paced badge queue. The encoder bunches the first 10 variants
     * into ~1.5s (T+2.0 → 3.5s); without queueing, badges overwrite each
     * other 4-5 times before any can be read. This enqueues each landing
     * and drains the queue at WPC_BADGE_SPACING_MS minimum spacing, so
     * every badge gets a readable on-screen lifetime regardless of how
     * tightly the encoder pushes callbacks.
     *
     * Chip count + headline % updates are decoupled from this queue — they
     * fire live in real time in the poller success handler. The queue
     * paces only the visual narration.
     */
    // v7.02 — Spacing tuned for ALL-variants chipping (was wins-only).
    // 24 variants × 600ms = ~14s burst window. Pairs with the 3500ms hold
    // so the LAST chip in a burst still gets its full lifetime. Each chip
    // gets at least 600ms of clear standalone visibility before the next
    // one replaces it.
    var WPC_BADGE_SPACING_MS = 600;
    function wpcEnqueueBadge(imageID, fmt, size) {
        var qKey = 'wpcBadgeQueue_' + imageID;
        var dKey = 'wpcBadgeDrainer_' + imageID;
        var lKey = 'wpcBadgeLast_' + imageID;

        if (!window[qKey]) window[qKey] = [];
        window[qKey].push({ fmt: fmt, size: size });

        if (window[dKey]) return; // drainer already running

        window[dKey] = setInterval(function () {
            var queue = window[qKey] || [];
            var last  = window[lKey] || 0;
            var now   = Date.now();

            if (queue.length === 0) {
                // Drained — stop after one spacing window of silence so a
                // late-arriving variant in the same compress reuses this
                // drainer without restart cost.
                if (now - last >= WPC_BADGE_SPACING_MS * 2) {
                    clearInterval(window[dKey]);
                    delete window[dKey];
                }
                return;
            }

            if (now - last < WPC_BADGE_SPACING_MS) return;

            var item = queue.shift();
            wpcShowVariantBadge(imageID, item.fmt, item.size);
            window[lKey] = now;
        }, 100);
    }

    /**
     * v7.02 — Milestone pip for count crossings (5, 10, 15, 20). Same chip
     * style as wpcShowVariantBadge but shows "+N variants" instead of a
     * format/size pair. Visually distinct from win pips via the JPEG fallback
     * class (neutral colour) so the user can tell win vs milestone apart.
     */
    function wpcShowMilestoneBadge(imageID, n) {
        if (!n) return;
        var $card  = $('.wps-ic-media-actions-' + imageID).find('.wpc-ml-card').first();
        if (!$card.length || !$card.hasClass('wpc-ml-card--compressed')) return;
        var $title = $card.find('.wpc-ml-title').first();
        if (!$title.length) return;

        var chipMarkup = '<span class="wpc-delta-chip-plus" aria-hidden="true">+</span>'
                       + '<span class="wpc-delta-chip-fmt">' + n + '</span>'
                       + '<span class="wpc-delta-chip-size">variants</span>';
        $title.find('.wpc-delta-chip').remove();
        var $chip = $('<span class="wpc-delta-chip wpc-delta-chip--milestone" role="status" aria-live="polite">' + chipMarkup + '</span>');
        $title.append($chip);

        if (window['wpcChipFade_' + imageID])   clearTimeout(window['wpcChipFade_' + imageID]);
        if (window['wpcChipRemove_' + imageID]) clearTimeout(window['wpcChipRemove_' + imageID]);
        window['wpcChipFade_' + imageID]   = setTimeout(function () { $chip.addClass('wpc-delta-chip-fade'); }, 2500);
        window['wpcChipRemove_' + imageID] = setTimeout(function () { $chip.remove(); }, 3200);
    }

    function wpcSwapCardWithAnimation(imageID, payload, hbStatus) {
        var $parent = $('.wps-ic-media-actions-' + imageID);
        if (!$parent.length) return;
        var $loading = $('.wps-ic-image-loading-' + imageID);

        // 7.01.7 — Heartbeat now passes {html, status}; legacy callers pass HTML string.
        var newHtml = (payload && typeof payload === 'object' && payload.html) ? payload.html : payload;
        var status  = hbStatus || (payload && typeof payload === 'object' && payload.status) || null;

        // Capture the state we're transitioning FROM before the swap
        var $oldCard = $parent.find('.wpc-ml-card').first();
        var oldState = wpcStateOf($oldCard.attr('class') || '');
        var oldTitle = $oldCard.find('.wpc-ml-title').text();

        // v7.02 — Pre-swap guards. We inspect the candidate HTML before
        // touching the DOM:
        //   (1) NO-OP — state and title both identical → skip swap entirely
        //       (preserves any in-flight chip / counter animation).
        //   (2) DOWNGRADE REFUSAL — once card is Compressed, refuse to swap
        //       it to ANY non-Compressed state. A stale heartbeat or a
        //       race where ic_compressing got temporarily cleared must not
        //       be allowed to drag a settled Compressed card back to
        //       Optimizing. Once compressed, the card stays compressed
        //       (only path back is an explicit Restore action which
        //       follows its own DOM path).
        //   (3) COMPRESSED-TO-COMPRESSED TITLE-ONLY UPDATE — when both old
        //       and new are Compressed and only the headline text differs
        //       (savings % climbed), mutate just the title's leading text
        //       node via setLeadingText-style write. Avoids the full
        //       $parent.html() swap that would destroy the chip. The chip
        //       lives as a sibling of the title's text node, so direct
        //       mutation preserves it.
        var tmpDoc = document.createElement('div');
        tmpDoc.innerHTML = newHtml;
        var $candidate = $(tmpDoc).find('.wpc-ml-card').first();
        if ($candidate.length) {
            var candState = wpcStateOf($candidate.attr('class') || '');
            var candTitle = $candidate.find('.wpc-ml-title').text();

            // (1) No-op
            if (oldState === candState && oldTitle === candTitle
                && oldState !== 'unknown' && oldTitle) {
                return;
            }

            // (2) Downgrade refusal — once card is Compressed, refuse any
            // non-Compressed swap. Click handlers that legitimately leave
            // Compressed (Restore) change the class to is-restoring BEFORE
            // calling here, so oldState is already 'restoring' by the time
            // we see it — guard doesn't trigger.
            if (oldState === 'compressed' && candState !== 'compressed') {
                return;
            }
            // (2b) Restoring → Compressed refusal. Once Restore has been
            // clicked, the card MUST NOT be dragged back to Compressed by a
            // stale heartbeat transient or a late wps_ic_heartbeat_ that was
            // set by the previous compress's Phase B callbacks. Symptom
            // without this guard: card briefly shows "Compressed · 0 · 0J 0W 0A"
            // (compressed card rendered while restoreV4 is mid-flight clearing
            // ic_local_variants). The restore-live AJAX response is the
            // authoritative signal — let it lead the next transition
            // (restoring → uncompressed or restoring → regen-pending).
            if (oldState === 'restoring' && candState === 'compressed') {
                return;
            }

            // (3) Compressed → Compressed title-only update
            if (oldState === 'compressed' && candState === 'compressed' && oldTitle !== candTitle) {
                var $titleEl = $oldCard.find('.wpc-ml-title').first();
                var titleNode = $titleEl[0];
                if (titleNode && titleNode.firstChild && titleNode.firstChild.nodeType === 3) {
                    var pctRegexIn = /(\d+(?:\.\d+)?)\s*%/;
                    var oldPctM_ = oldTitle && oldTitle.match(pctRegexIn);
                    var candPctM_ = candTitle && candTitle.match(pctRegexIn);
                    var oldP = oldPctM_ ? parseFloat(oldPctM_[1]) : 0;
                    var candP = candPctM_ ? parseFloat(candPctM_[1]) : 0;
                    // v7.02.10 — Window-state floor. Savings only climbs
                    // within a session; record max ever seen so we never
                    // display a lower value across the title-snap path or
                    // the structural-swap path below.
                    var savingsMaxKey = 'wpcSavingsMax_' + imageID;
                    var savingsMax = Math.max(window[savingsMaxKey] || 0, oldP, candP);
                    window[savingsMaxKey] = savingsMax;
                    if (candP > oldP + 0.05) {
                        $titleEl.addClass('wpc-savings-counting');
                        wpcAnimateSavingsCounter($titleEl, oldP, candP, candTitle, 700, function () {
                            $titleEl.removeClass('wpc-savings-counting');
                        });
                    } else if (candP + 0.05 < savingsMax) {
                        // v7.02.10 — Candidate is LOWER than the max we've
                        // ever shown (lost-update race in ic_savings recompute
                        // — see v2-client.php:merge_variants). Refuse the
                        // downgrade; keep current text. Server converges on
                        // the next tick once the journal-drain catches up.
                    } else {
                        // Same value (or close enough to be a rounding tick).
                        // Snap to candidate to pick up any non-pct title
                        // changes (e.g. wpc_no_improvement count revision).
                        titleNode.firstChild.nodeValue = candTitle;
                    }
                    return;
                }
                // Fallthrough: if the title node shape is unexpected, fall
                // through to the full swap (chip will be sacrificed, but
                // correctness wins over polish).
            }
        }

        // Swap HTML (only reached when we genuinely need a structural change)
        $loading.hide();
        $parent.html(newHtml).show();

        var $newCard = $parent.find('.wpc-ml-card').first();
        if (!$newCard.length) return;
        var newState = wpcStateOf($newCard.attr('class') || '');
        var newTitle = $newCard.find('.wpc-ml-title').text();

        var stateChanged = oldState !== 'unknown' && newState !== 'unknown' && oldState !== newState;
        var titleChanged = oldTitle && newTitle && oldTitle !== newTitle;

        // 7.01.44 — Detect a savings-% INCREASE so we can run the world-class
        // counter animation (digits tick up smoothly) instead of just a pulse.
        // Matches "X% Saved" or "X.Y% Saved" in either old or new title.
        var pctRegex   = /(\d+(?:\.\d+)?)\s*%/;
        var oldPctM    = oldTitle && oldTitle.match(pctRegex);
        var newPctM    = newTitle && newTitle.match(pctRegex);
        var oldPct     = oldPctM ? parseFloat(oldPctM[1]) : 0;
        var newPct     = newPctM ? parseFloat(newPctM[1]) : 0;
        var pctClimbed = (oldPct > 0 && newPct > oldPct && (newPct - oldPct) < 100);

        // 7.01.47 — State gate. Chip + counter only make sense on a compressed card.
        // Defends against any future server-side path that leaks an event field for
        // a non-compressed render (already gated at PHP writer + PHP handler; this is
        // the third defence layer).
        var isCompressed = $newCard.hasClass('wpc-ml-card--compressed');

        // v7.02.14 — Persistent savings floor. The title-only path above has
        // its own guard, but it ONLY fires when oldState==='compressed'.
        // During re-compress, the click handler strips the
        // .wpc-ml-card--compressed class BEFORE this swap runs (sets
        // is-compressing for the loading state) so oldState=='unknown' →
        // title-only path skipped → full swap renders the server's current
        // ic_savings, which can be transiently LOW during in-flight recompute
        // (user-visible 91% → 83% → 91% flicker). Track wpcSavingsMax_<id>
        // across ALL render paths so the displayed value never regresses.
        // Reset on Restore click (fresh image = fresh max). NOT reset on
        // Compress click (re-compress should hold the prior high while the
        // new run converges).
        if (isCompressed && newPct > 0) {
            var savingsMaxKey_full = 'wpcSavingsMax_' + imageID;
            var prevSavingsMax = window[savingsMaxKey_full] || 0;
            var newSavingsMax = Math.max(prevSavingsMax, newPct);
            window[savingsMaxKey_full] = newSavingsMax;
            if (newPct + 0.05 < newSavingsMax) {
                // Server's rendered savings is LOWER than tracked max.
                // Override the displayed text to show the max value.
                var $newTitleEl = $newCard.find('.wpc-ml-title').first();
                var newTitleNode = $newTitleEl[0];
                if (newTitleNode && newTitleNode.firstChild && newTitleNode.firstChild.nodeType === 3) {
                    newTitleNode.firstChild.nodeValue = newTitleNode.firstChild.nodeValue.replace(
                        pctRegex,
                        newSavingsMax.toFixed(1) + '%'
                    );
                    // Also update local newTitle so downstream calculations
                    // (animation triggers etc) see the corrected value.
                    newTitle = $newCard.find('.wpc-ml-title').text();
                    newPct = newSavingsMax;
                    pctClimbed = (oldPct > 0 && newPct > oldPct && (newPct - oldPct) < 100);
                }
            }
        }

        // 7.02 — Once ANY swap renders a Compressed card, kill the queueing→optimizing
        // crossfade timer + the get_card poller for this image. With the eager-flip
        // feature (wpc_v2_eager_compressed_flip option), the heartbeat can render
        // Compressed at T0+3s — but the loadingPhase timer fires at T0+2.5s and would
        // overwrite the title text with "Optimizing…" immediately after our swap.
        // Same for wpcWatchCard which polls every 5s and may re-render an intermediate
        // state. Cancelling here is idempotent and safe to call every tick.
        if (isCompressed) {
            if (window['wpcLoadingPhase_' + imageID]) {
                clearTimeout(window['wpcLoadingPhase_' + imageID]);
                delete window['wpcLoadingPhase_' + imageID];
            }
            if (typeof wpcCancelCardPoll === 'function') {
                wpcCancelCardPoll(imageID);
            }
        }

        // v7.02 — The legacy heartbeat-driven chip (every bg_variant_arrived
        // tick gets a "+FMT Size" pip) has been replaced by the wins-only
        // pip policy in the count-poller (see "Wins-only pip cadence" block
        // below the $.ajax success handler). Every landing whose savings %
        // exceeds the prior best gets a pip; non-best landings advance the
        // chip counter silently. Stripping the heartbeat-driven chip
        // prevents double-firing on the rare case where a heartbeat swap
        // and a count-poller pip would land in the same animation frame.

        if (stateChanged) {
            // Hero animation on state transition (e.g., Optimizing → Compressed):
            // card fades in, savings slides in from right, subtitle actions cascade.
            $newCard.addClass('wpc-state-entering');
            setTimeout(function () {
                $newCard.removeClass('wpc-state-entering');
            }, 800);
        } else if (isCompressed && pctClimbed) {
            // 7.01.44 — World-class number counter when savings % increases. Eases
            // from old → new value with rAF + easeOutExpo over ~900ms while a soft
            // green glow + tiny scale pop runs on the title. The card body is
            // height-locked + the delta chip is now an inline flex sibling (v7.01.47),
            // so no element shifts during the count.
            // 7.01.47 — Gated on isCompressed (the % regex alone could match unexpected
            // text in some i18n / theme scenarios; the state-class is authoritative).
            var $titleEl = $newCard.find('.wpc-ml-title').first();
            if ($titleEl.length) {
                $titleEl.addClass('wpc-savings-counting');
                wpcAnimateSavingsCounter($titleEl, oldPct, newPct, newTitle, 900, function () {
                    $titleEl.removeClass('wpc-savings-counting');
                });
            }
        } else if (titleChanged) {
            // 7.01.7 — Pulse for non-numeric title changes (state-name swaps, etc.)
            // bg-swap callbacks arrive 5-15× per compress; without this filter, the
            // card pulsed every 8s for 30-120s straight. Pulse on meaningful ticks
            // only — most bg-swaps refine non-best variants (silent disk update); the
            // few that beat the current best produce a single visible pulse.
            $newCard.addClass('wpc-bg-updated');
            setTimeout(function () { $newCard.removeClass('wpc-bg-updated'); }, 700);
        }

        // Clear any pending timeouts for this image
        if (window['wpcTimeout_' + imageID]) {
            clearTimeout(window['wpcTimeout_' + imageID]);
            delete window['wpcTimeout_' + imageID];
        }
        if (window['wpcLongTimeout_' + imageID]) {
            clearTimeout(window['wpcLongTimeout_' + imageID]);
            delete window['wpcLongTimeout_' + imageID];
        }
    }

    /**
     * Media Library - Heartbeat
     * Normal: 8s interval. After compress/restore: switches to 500ms for 60s,
     * then back to 8s.
     *
     * v7.02 Stage 2: heartbeat is now the SOLE chip + savings update path. The
     * per-image count poller (250ms admin-ajax per card) and per-image SSE
     * EventSource (1 FPM worker held per card for 60s) are both gone. One
     * heartbeat request returns chip/savings/recent[] for ALL active images.
     *
     * Active list mechanics:
     *   - wpcActiveImages[] tracks IDs of cards in active compress flow.
     *   - Add on compress click. Remove on settle (status=compressed + recent=0
     *     for 8 consecutive ticks) OR 60s safety timeout.
     *   - Sent to server as `active=[ids]` POST param. Server returns chip
     *     data per ID. Idempotent — duplicate IDs in list don't cause issues.
     */
    var wpcHBInterval = 8000;
    var wpcHBTimer = null;
    var wpcHBBurstTimeout = null;
    var wpcHBRunning = false;
    var wpcActiveImages = [];

    function wpcMarkActive(id) {
        id = parseInt(id, 10);
        if (!id) return;
        var wasIdle = wpcActiveImages.length === 0;
        if (wpcActiveImages.indexOf(id) === -1) wpcActiveImages.push(id);
        // Safety timeout — auto-remove after 90s in case settle-stop never fires
        // (e.g., user navigates away mid-compress and back later).
        var sk = 'wpcActiveTimeout_' + id;
        if (window[sk]) clearTimeout(window[sk]);
        window[sk] = setTimeout(function () {
            wpcMarkInactive(id);
            delete window[sk];
        }, 90000);
        // v7.04.9 — Burst heartbeat to 3s while images are actively
        // compressing/restoring. Snappier chip refresh during the window
        // that matters; falls back to 8s when nothing's in flight.
        if (wasIdle) wpcUpdateHeartbeatCadence();
    }

    function wpcMarkInactive(id) {
        id = parseInt(id, 10);
        if (!id) return;
        var i = wpcActiveImages.indexOf(id);
        if (i >= 0) wpcActiveImages.splice(i, 1);
        var sk = 'wpcActiveTimeout_' + id;
        if (window[sk]) { clearTimeout(window[sk]); delete window[sk]; }
        // v7.04.9 — Drop back to idle cadence when nothing left in flight.
        if (wpcActiveImages.length === 0) wpcUpdateHeartbeatCadence();
    }

    // v7.04.9 — Hybrid heartbeat cadence. Active (any image compressing or
    // restoring) → 3s for snappy chip updates. Idle → 8s to save FPM workers.
    // Trade-off: 2.67× more polls during the typical 15-30s active window
    // per image; back to baseline once done. Net cost on a busy admin
    // session: ~50 extra worker-seconds/hr. Tolerable for the UX win.
    function wpcUpdateHeartbeatCadence() {
        var target = wpcActiveImages.length > 0 ? 3000 : 8000;
        if (target === wpcHBInterval) return;
        wpcStartHeartbeat(target);
    }

    function wpcStartHeartbeat(interval) {
        if (wpcHBTimer) clearInterval(wpcHBTimer);
        wpcHBInterval = interval;
        // 7.01.7 — Wrap in anonymous fn so `heartbeat` is resolved at fire time, not at
        // setInterval-call time. The `var heartbeat = function() {...}` declaration below
        // is hoisted as undefined; passing `heartbeat` directly to setInterval would capture
        // undefined and silently never fire callbacks (the bug that broke live bg-swap pulses).
        wpcHBTimer = setInterval(function () { heartbeat(); }, interval);
    }

    // Start normal heartbeat
    wpcStartHeartbeat(8000);

    // v7.02.12 — Resume + burst-catch-up when user returns to a hidden tab.
    // Pairs with the document.hidden skip in heartbeat() + wpcWatchCard's
    // poll(). Without this, after a long hidden period the next tick fires
    // on the normal 8s cadence — could leave the UI stale for ~8s. Burst
    // gives an immediate catch-up tick.
    if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) heartbeatBurst();
        });
    }

    // 7.01.7 — Page-load cascade. Subtle 2px lift + opacity fade-in, staggered 60ms apart.
    // Last card lands at ~940ms (660ms delay + 280ms animation). Cards already mid-transition
    // skip the cascade. Uses ease-out-expo curve from the unified animation system.
    $('.wpc-ml-card').slice(0, 12).each(function (i) {
        var $card = $(this);
        if ($card.hasClass('is-compressing') || $card.hasClass('is-restoring')) return;
        $card.addClass('wpc-cascade-in wpc-cascade-' + i);
        setTimeout(function () {
            $card.removeClass('wpc-cascade-in wpc-cascade-' + i);
        }, 1200 + (i * 60));
    });

    // v7.02.14 — Initialize wpcSavingsMax_<id> from currently-rendered DOM for
    // each compressed card on the page. Without this, the first heartbeat tick
    // post-load sees window state = 0 and would accept any value (including
    // a transient drop). With this seed, the monotonic floor matches the
    // already-displayed value.
    $('.wpc-ml-card.wpc-ml-card--compressed').each(function () {
        var $card = $(this);
        var $actions = $card.closest('[class*="wps-ic-media-actions-"]');
        if (!$actions.length) return;
        var classes = $actions.attr('class') || '';
        var m = classes.match(/wps-ic-media-actions-(\d+)/);
        if (!m) return;
        var id = parseInt(m[1], 10);
        if (!id) return;
        var titleText = $card.find('.wpc-ml-title').first().text() || '';
        var pctM = titleText.match(/(\d+(?:\.\d+)?)\s*%/);
        if (!pctM) return;
        var pct = parseFloat(pctM[1]);
        if (pct > 0) {
            window['wpcSavingsMax_' + id] = Math.max(window['wpcSavingsMax_' + id] || 0, pct);
        }
    });

    var heartbeat = function () {
        if (wpcHBRunning) return; // Prevent overlapping requests
        // v7.02.12 — Skip when tab not visible. Compress + restore work
        // runs entirely server-side (Phase A POST + REST callbacks +
        // postmeta writes); heartbeat ONLY reads postmeta for UI updates.
        // Background-tab polling burns FPM workers for no user benefit.
        // When user returns, visibilitychange listener fires heartbeatBurst
        // and page-load-style state catches up.
        if (typeof document !== 'undefined' && document.hidden) { if (WPC_CDBG) console.log('[wpc-counter] HB skip (tab hidden)'); return; }
        wpcHBRunning = true;
        if (WPC_CDBG) console.log('[wpc-counter] HB tick active=[' + (wpcActiveImages || []).join(',') + ']');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 25000,
            // v7.02 — jQuery's default (traditional:false) serialises an
            // array as `active%5B%5D=104&active%5B%5D=113`, which PHP unpacks
            // into $_POST['active'] = ['104','113']. Do NOT set traditional:true
            // — that emits `active=104&active=113` and PHP only sees the LAST
            // value as a scalar, breaking the entire active-list path.
            data: {
                action: 'wps_ic_media_library_heartbeat',
                active: wpcActiveImages.slice() // snapshot — list may mutate during AJAX
            },
            success: function (response) {
                wpcHBRunning = false;
                if (WPC_CDBG) { var _hk = (response && response.data && response.data.html) ? Object.keys(response.data.html) : []; console.log('[wpc-counter] HB resp imgs=[' + _hk.join(',') + ']' + (response && response.success === true ? '' : ' (success!=true)')); }
                if (response && response.success === true && response.data && response.data.html) {
                    $.each(response.data.html, function (index, payload) {
                        if (!payload) return;
                        var imageID = parseInt(index, 10);
                        if (!imageID) return;

                        // (v7.03.64) Passive Smart-Delivery tracking. If the heartbeat surfaces an image
                        // that's actively OPTIMIZING (via the recently-changed transient path) and it isn't
                        // already tracked, register it so subsequent ticks poll its climbing chip{}. Makes the
                        // counter climb for background SD optimizes the page-load scan missed (started after
                        // load). Tab-active is already enforced (heartbeat skips when document.hidden), and
                        // wpcMarkActive's 90s safety-timeout + the settle-stop bound the polling.
                        if ((payload.status === 'optimizing' || payload.status === 'queueing')
                            && wpcActiveImages.indexOf(imageID) === -1) {
                            wpcMarkActive(imageID);
                            if (WPC_CDBG) console.log('[wpc-counter] passive-track img=' + imageID + ' (SD optimizing → auto-registered)');
                        }

                        // ── Card-swap path FIRST (SYNCHRONOUS). Must happen
                        //    before chip mutation so chip/badges land on the
                        //    new Compressed card, not the old Optimizing one.
                        //    Previous code used setTimeout(swap, 80*i) to
                        //    stagger multi-image swaps, but that caused the
                        //    synchronous chip update below to run on the
                        //    pre-swap (Optimizing) DOM — visible race the
                        //    user reported.
                        if (payload.html) {
                            var swappedKey = 'wpcCardSwapped_' + imageID;
                            if (payload.status === 'compressed' && !window[swappedKey]) {
                                window[swappedKey] = true;
                            }
                            wpcSwapCardWithAnimation(imageID, payload);
                        }

                        // ── Chip + savings + recent (now operates on the
                        //    post-swap DOM if a swap just fired).
                        wpcHeartbeatApplyChipData(imageID, payload);
                    });

                    $('.ic-tooltip').tooltipster({
                        maxWidth: '300',
                    });
                }
            },
            error: function () {
                wpcHBRunning = false;
            },
            complete: function () {
                wpcHBRunning = false;
            }
        });
    };

    // (v7.03.64) Smooth count-up tween for the variant-count chip — rAF-based, monotonic, cancels any
    // in-flight tween on the same element. Makes the climb readable even when variants land in a burst
    // (a single click-optimize) rather than trickling. Uses the rAF timestamp argument (no Date.now).
    function wpcAnimateCount($el, to) {
        to = parseInt(to, 10); if (isNaN(to)) return;
        var from = parseInt($el.text(), 10); if (isNaN(from)) from = 0;
        if (to <= from) { $el.text(String(to)); return; }
        var prevRAF = $el.data('wpcRAF'); if (prevRAF) cancelAnimationFrame(prevRAF);
        var dur = Math.min(900, 150 + (to - from) * 55), t0 = null;
        function step(ts) {
            if (t0 === null) t0 = ts;
            var p = Math.min((ts - t0) / dur, 1), e = 1 - Math.pow(1 - p, 3);
            $el.text(String(Math.round(from + (to - from) * e)));
            if (p < 1) { $el.data('wpcRAF', requestAnimationFrame(step)); } else { $el.removeData('wpcRAF'); }
        }
        $el.data('wpcRAF', requestAnimationFrame(step));
    }

    /**
     * v7.02 Stage 2 — Apply chip + savings + recent[] from a heartbeat payload
     * to one image's card. Idempotent: safe to call on every tick. Each block
     * is wrapped in defensive checks so a malformed payload can't break the UI.
     */
    function wpcHeartbeatApplyChipData(imageID, payload) {
        var $card = $('.wps-ic-media-actions-' + imageID).find('.wpc-ml-card').first();
        if (!$card.length) return;

        // 1) Chip count mutation (count · J · W · A)
        //
        // (v7.03.63) Runs for OPTIMIZING cards too — NOT gated on compressed. The is-compressing loading
        // card carries the SAME 5-child .wpc-variant-count-chip (media_library_live.class.php ~943:
        // count · 0J 0W 0A), so the old "compressed-only" early-return (now moved BELOW this block) froze
        // the live counter at 0 through the entire optimize — the reported "never counted up." The chip
        // count is monotonic and IS the live variant-landing tally, so climbing it on the loading card is
        // exactly the intended demo behaviour; the swap then carries the final number to the compressed card.
        //
        // v7.02.10 — Monotonic guard. Variants are write-once during a
        // compress session; the chip count and per-format counts should
        // ONLY GROW. Under heavy bulk drain the server-side merge race
        // (GET_LOCK timeout fallthrough at v2-client.php:merge_variants)
        // can transiently undercount in ic_local_variants until the
        // journal-drain heals the lost write. Without this guard the chip
        // would visibly flicker down (10 → 7 → 12 → 24). With this guard
        // it climbs cleanly to the final value because we remember the
        // max we've ever seen per image. Reset on:
        //   - Compress click (line ~1048 — wpcChipMax_* cleared)
        //   - Restore click (line ~846)
        //   - Page-load scan of is-compressing cards (line ~755)
        if (payload.chip) {
            var $chip = $card.find('.wpc-variant-count-chip');
            if ($chip.length) {
                var parts = $chip.children();
                if (parts.length >= 5) {
                    var maxKey = 'wpcChipMax_' + imageID;
                    var prev   = window[maxKey] || { count: 0, jpeg: 0, webp: 0, avif: 0 };
                    var next   = {
                        count: Math.max(prev.count, payload.chip.count || 0),
                        jpeg:  Math.max(prev.jpeg,  payload.chip.jpeg  || 0),
                        webp:  Math.max(prev.webp,  payload.chip.webp  || 0),
                        avif:  Math.max(prev.avif,  payload.chip.avif  || 0)
                    };
                    var grew = (next.count > prev.count);
                    window[maxKey] = next;
                    wpcAnimateCount($(parts[0]), next.count);
                    $(parts[2]).text(next.jpeg + 'J');
                    $(parts[3]).text(next.webp + 'W');
                    $(parts[4]).text(next.avif + 'A');

                    // (v7.03.65) Transient chip-reveal REMOVED — the variant chip is now hidden on BOTH the
                    // optimizing and compressed cards (per request; the "0 · 0J 0W 0A" read as empty). The
                    // count still updates the hidden chip element so the heartbeat-driven ?wpc_counter_debug
                    // log keeps working as the avif/variant-landing diagnostic (it caught img=17 climb
                    // J8 W7 A0 → A8 as the async avif landed). No visible chip in any state.
                    if (WPC_CDBG) console.log('[wpc-counter] chip img=' + imageID + ' -> ' + next.count + ' (J' + next.jpeg + ' W' + next.webp + ' A' + next.avif + ') ' + ($card.hasClass('wpc-ml-card--compressed') ? 'compressed' : 'optimizing') + (grew ? ' [grew]' : ''));
                } else if (WPC_CDBG) { console.log('[wpc-counter] chip img=' + imageID + ' SKIP parts=' + parts.length + ' (<5)'); }
            } else if (WPC_CDBG) { console.log('[wpc-counter] chip img=' + imageID + ' SKIP: no .wpc-variant-count-chip in ' + ($card.hasClass('wpc-ml-card--compressed') ? 'compressed' : 'optimizing') + ' card'); }
        } else if (WPC_CDBG) { console.log('[wpc-counter] img=' + imageID + ' payload has NO chip{} (status=' + (payload.status || '?') + ')'); }

        // v7.02 Stage 2 — Gate the REST (savings %, badges, recent[]) on the COMPRESSED visual state — those
        // belong on the flipped card. The chip COUNT above is intentionally EXEMPT so it climbs live during
        // optimize. recent[] cursor stays 0 until a compressed tick, so nothing is missed (replayed post-swap).
        if (!$card.hasClass('wpc-ml-card--compressed')) return;

        // 2) Savings % climb on the headline title
        //
        // v7.02.10 — Drop the decrease branch. Same race as chip count can
        // make ic_savings briefly recompute lower if a lost merge dropped
        // the best-variant. Savings only ever moves up within a session.
        // v7.02.14 — Also track wpcSavingsMax_<id> in window so the
        // structural-swap path (wpcSwapCardWithAnimation full-swap branch)
        // can use the same floor.
        if (payload.savings_pct > 0 && $card.hasClass('wpc-ml-card--compressed')) {
            var $title  = $card.find('.wpc-ml-title').first();
            var titleEl = $title[0];
            var leading = (titleEl && titleEl.firstChild && titleEl.firstChild.nodeType === 3)
                ? titleEl.firstChild.nodeValue
                : '';
            var prevM   = leading && leading.match(/(\d+(?:\.\d+)?)\s*%/);
            var prevPct = prevM ? parseFloat(prevM[1]) : 0;
            var targetPct = payload.savings_pct;
            var savingsMaxKey_hb = 'wpcSavingsMax_' + imageID;
            window[savingsMaxKey_hb] = Math.max(window[savingsMaxKey_hb] || 0, prevPct, targetPct);
            var finalText = targetPct.toFixed(1) + '% Saved';
            if (targetPct > prevPct + 0.05) {
                $title.addClass('wpc-savings-counting');
                wpcAnimateSavingsCounter($title, prevPct, targetPct, finalText, 700, function () {
                    $title.removeClass('wpc-savings-counting');
                });
            }
            // No else: refuse to write a lower savings value over a higher one.
        }

        // 3) Variant chips enqueue (per-arrival animation) via shared cursor.
        // Each variant fires exactly once across the entire compress session
        // because the cursor advances past its ts on first emit.
        if (Array.isArray(payload.recent) && payload.recent.length) {
            var sinceKey = 'wpcSince_' + imageID;
            var maxTs    = window[sinceKey] || 0;
            payload.recent.forEach(function (item) {
                if (!item || typeof item.ts !== 'number') return;
                if (item.ts <= (window[sinceKey] || 0)) return;
                if ((item.savings || 0) <= 0 && !item.is_parent) return;
                wpcEnqueueBadge(imageID, item.fmt, item.size);
                if (item.ts > maxTs) maxTs = item.ts;
            });
            window[sinceKey] = maxTs;
        }

        // 4) Settle-stop — once status=compressed AND recent[] is empty for
        // 8 consecutive heartbeats (~4s with 500ms cadence), mark the image
        // inactive so heartbeat stops including it in active-list. The card
        // stays compressed; we just stop computing per-tick chip data for it.
        var settleKey = 'wpcSettleHB_' + imageID;
        if (payload.status === 'compressed' && (!payload.recent || payload.recent.length === 0)) {
            window[settleKey] = (window[settleKey] || 0) + 1;
            if (window[settleKey] >= 8) {
                wpcMarkInactive(imageID);
                delete window[settleKey];
            }
        } else {
            window[settleKey] = 0;
        }
    }

    // Switch to fast polling for 60s after an action, then back to normal
    // v7.02 — Tightened burst interval from 3000ms → 500ms so the chip count
    // climbs ~1-2 per tick during the Phase B drain (encoder ships ~3 variants/s).
    // Trade-off: ~120 polls during the 60s burst window (vs 20 at 3s cadence).
    // Each poll is ~30ms server-side, so ~3.6 worker-seconds per compress.
    // 17× cheaper than SSE long-poll for the same near-instant feel, and
    // crucially: no long-lived PHP-FPM workers tied up — pool stays drainable
    // on shared hosting.
    function heartbeatBurst() {
        // v7.04.11 — Burst cadence tightened 500ms → 250ms so the first
        // visible "Compressed" state catches the curl_multi pull batch
        // mid-merge instead of after the full ~12-variant batch lands. May
        // not always succeed (pulls can be sub-250ms), but gives finer
        // sampling at the moment that matters. Drops back to the hybrid
        // 3s/8s cadence (set by wpcUpdateHeartbeatCadence on inactive)
        // after the 60s burst window.
        if (wpcHBInterval !== 250) {
            wpcStartHeartbeat(250);
        }
        // Reset the 60s countdown on each new action
        if (wpcHBBurstTimeout) clearTimeout(wpcHBBurstTimeout);
        wpcHBBurstTimeout = setTimeout(function () {
            // Drop to active or idle cadence based on whether anything's
            // still in flight (matches v7.04.9 hybrid behavior).
            var fallback = wpcActiveImages.length > 0 ? 3000 : 8000;
            wpcStartHeartbeat(fallback);
            wpcHBBurstTimeout = null;
        }, 60000);
        // Immediate poll
        heartbeat();
    }

    // 7.01.17 — Per-image card polling. Defense-in-depth for any AJAX response that
    // shows a still-pending state (is-restoring / is-compressing) — keeps polling the
    // server until the card transitions out of pending or the budget is exhausted.
    // Independent of the heartbeat transient mechanism: works even if the regen worker
    // never sets a heartbeat transient, or if the JS heartbeat happens to miss the
    // transient's 60s window.
    //
    // Behavior:
    //   - 5s polls, max 12 attempts (60s total budget)
    //   - First poll fires after the FIRST 5s (give server some time to progress)
    //   - Stops on (a) no-pending response, (b) budget exhausted, (c) explicit cancel
    //   - Coalesces: calling for the same imageID while a poller is running cancels
    //     the prior poller and starts fresh
    //   - Cancelled by any subsequent action (re-restore, re-compress) on same image
    var wpcCardPollers = {}; // imageID → timeout handle

    function wpcCancelCardPoll(imageID) {
        if (wpcCardPollers[imageID]) {
            clearTimeout(wpcCardPollers[imageID]);
            delete wpcCardPollers[imageID];
        }
    }

    function wpcWatchCard(imageID, opts) {
        opts = opts || {};
        var maxAttempts = opts.maxAttempts || 12;
        // v7.02.12 — Cadence bumped 5s → 12s. Restore + regen-pending
        // typically take 30-120s; polling every 5s = 6-24 hits, every
        // 12s = 3-10 hits. Saves a worker-hit per restore-poll cycle.
        // Trade-off: UI reactivation delayed up to 12s after completion.
        var interval = opts.interval || 12000;
        var attempts = 0;

        wpcCancelCardPoll(imageID); // coalesce — only one poller per image

        var poll = function () {
            // v7.02.12 — Same document.hidden guard as heartbeat. Restore
            // work continues server-side; no point polling for a UI no one
            // is looking at. Reschedule to retry after one interval.
            if (typeof document !== 'undefined' && document.hidden) {
                wpcCardPollers[imageID] = setTimeout(poll, interval);
                return;
            }
            if (attempts++ >= maxAttempts) {
                delete wpcCardPollers[imageID];
                return;
            }
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 15000,
                data: { action: 'wps_ic_get_card', attachment_id: imageID },
                success: function (response) {
                    if (!response || !response.success || !response.data) {
                        wpcCardPollers[imageID] = setTimeout(poll, interval);
                        return;
                    }
                    var data = response.data;
                    if (data.html) {
                        // Re-render only when the HTML signals a state change vs current
                        // DOM (avoid flickering on identical-payload polls).
                        var $existing = $('.wps-ic-media-actions-' + imageID + ' .wpc-ml-card').first();
                        var currentClasses = $existing.attr('class') || '';
                        var nextClassesMatch = data.html.match(/wpc-ml-card[^"']*/);
                        var nextClasses = nextClassesMatch ? nextClassesMatch[0] : '';
                        if (currentClasses !== nextClasses ||
                            $existing.find('.wpc-ml-title').text() !==
                            (data.html.match(/wpc-ml-title[^>]*>([^<]+)/) || [, ''])[1]) {
                            wpcSwapCardWithAnimation(imageID, { html: data.html, status: data.ic_status });
                        }
                    }
                    if (data.pending) {
                        wpcCardPollers[imageID] = setTimeout(poll, interval);
                    } else {
                        delete wpcCardPollers[imageID];
                    }
                },
                error: function () {
                    // Network blip — retry within budget
                    wpcCardPollers[imageID] = setTimeout(poll, interval);
                }
            });
        };
        wpcCardPollers[imageID] = setTimeout(poll, interval);
    }

    /**
     * On page load: pick up any image already mid-compress / mid-restore and
     * wire the live update plumbing for it. Covers three scenarios:
     *   1. User uploaded an image with on-upload → got queued → opened Media
     *      Library mid-compress. Card already renders is-compressing on the
     *      server (transient says queued/compressing).
     *   2. User refreshed during their own click-compress.
     *   3. User refreshed mid-regen-after-restore.
     *
     * For is-compressing: burst heartbeat AND mark the image as active so
     * chip count, savings counter, and variant-arrival badges fire live just
     * like on a click-compress. Without wpcMarkActive the heartbeat would
     * still swap the card on completion, but the user wouldn't see chips
     * climb or savings animate during the drain.
     *
     * For is-restoring / is-regen-pending: wpcWatchCard polls every 5s.
     */
    var $compressingCards = $('.wpc-ml-card.is-compressing');
    if ($compressingCards.length > 0) {
        $compressingCards.each(function () {
            var attEl = $(this).closest('[class*="wps-ic-media-actions-"]');
            var cls   = attEl.attr('class') || '';
            var m     = cls.match(/wps-ic-media-actions-(\d+)/);
            if (m) {
                var id = parseInt(m[1], 10);
                wpcMarkActive(id);
                // Reset per-image state so live updates start fresh (cursor,
                // settle counter, queue, single-fire swap flag). Mirrors what
                // the Compress click handler does.
                window['wpcCardSwapped_' + id]    = false;
                window['wpcSince_' + id]          = 0;
                window['wpcSettleHB_' + id]       = 0;
                window['wpcBest_' + id]           = 0;
                window['wpcBadgeQueue_' + id]     = [];
                window['wpcBadgeLast_'  + id]     = 0;
                window['wpcChipMax_' + id]        = { count: 0, jpeg: 0, webp: 0, avif: 0 };
            }
        });
        heartbeatBurst();
    }
    // (v7.03.70) Proactive Smart-Delivery watch — register the first ~12 not-yet-compressed cards so the
    // heartbeat polls them. When Smart Delivery optimizes one in the background while the library is open,
    // the card transitions live + the +WebP/+AVIF pips float up + savings counts up — instead of only after a
    // refresh. (.64's passive-track was REACTIVE — it only caught images the heartbeat already surfaced; this
    // watches the candidates up front.) Bounded: first 12 only; the heartbeat already skips when the tab is
    // hidden; and wpcMarkActive's 90s auto-deregister + the settle-stop keep idle cards from polling forever
    // (the .64 indexOf guard prevents re-registration, so that timeout actually fires). Tune the slice if poll
    // cost matters on very large libraries.
    $('.wpc-ml-card.wpc-ml-card--uncompressed').slice(0, 12).each(function () {
        var aEl = $(this).closest('[class*="wps-ic-media-actions-"]');
        var um  = (aEl.attr('class') || '').match(/wps-ic-media-actions-(\d+)/);
        if (um) wpcMarkActive(parseInt(um[1], 10));
    });
    $('.wpc-ml-card.is-restoring, .wpc-ml-card.is-regen-pending').each(function () {
        var attEl = $(this).closest('[class*="wps-ic-media-actions-"]');
        var cls   = attEl.attr('class') || '';
        var m     = cls.match(/wps-ic-media-actions-(\d+)/);
        if (m) wpcWatchCard(parseInt(m[1], 10), { maxAttempts: 18, interval: 12000 });
    });

    // 7.01.7 — Removed wpcCheckFallbackCompress (was: every 15s, find any
    // .is-compressing card and fire wps_ic_compress_live AJAX).
    // It was designed for 7.00.x loopback failures where the async REST endpoint
    // could fail to fire. The 7.01.1 blocking-AJAX architecture with retry-cron
    // cron makes this fallback redundant — and harmful: after a user clicks
    // compress, the fallback would fire a SECOND parallel AJAX 15s later,
    // causing the duplicate Phase A run that flipped the card back to "Optimizing"
    // mid-bg-swap. Heartbeat polling (every 8s) is the canonical recovery path.

    /**
     * Exclude/Include — toggle class, badge animates via CSS, body swaps with fade
     */
    $('body').on('click', '.wps-ic-exclude-live,.wps-ic-include-live', function (e) {
        e.preventDefault();
        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var action = $(button).data('action') || 'exclude';
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');
        var body = parent.find('.wpc-ml-body');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_exclude_live',
                do_action: action,
                attachment_id: attachment_id,
                nonce: wpc_ajaxVar.nonce
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    var newHtml = $(response.data.html);
                    var newBody = newHtml.find('.wpc-ml-body').html();
                    var isExcluded = newHtml.hasClass('is-excluded');

                    // Toggle state — CSS transitions handle badge + icon box
                    if (isExcluded) {
                        card.addClass('is-excluded wpc-ml-card--excluded').removeClass('wpc-ml-card--uncompressed');
                    } else {
                        card.removeClass('is-excluded wpc-ml-card--excluded').addClass('wpc-ml-card--uncompressed');
                        // Re-trigger bump animation on un-exclude
                        var iconBox = card.find('.wpc-ml-card-icon');
                        iconBox.css('animation', 'none');
                        iconBox[0].offsetHeight;
                        iconBox.css('animation', '');
                    }

                    // Swap body content with fade-in-up
                    body.html('<div class="fade-in-up">' + newBody + '</div>');
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                button.removeClass('wpc-action-pending');
            }
        });
    });

    /**
     * Restore Live — direct, no queue
     */
    $('body').on('click', '.wps-ic-restore-live', function (e) {

        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');

        // Toggle to restoring state — engine spins CCW
        card.removeClass('wpc-ml-card--compressed wpc-ml-card--uncompressed is-compressed is-compressing').addClass('is-restoring');
        card.find('.wpc-ml-body').html('<div class="fade-in-up"><div class="wpc-ml-title">' + (wpc_ajaxVar.statusRestoring || 'Restoring') + '...</div><div class="wpc-skeleton"><div class="wpc-skeleton-bar w-short"></div><div class="wpc-skeleton-bar w-long"></div></div></div>');
        // v7.02.10 — Reset monotonic chip max for this image so a subsequent
        // compress animates fresh 0 → N instead of jumping from the prior
        // session's high count.
        window['wpcChipMax_' + attachment_id] = { count: 0, jpeg: 0, webp: 0, avif: 0 };
        // v7.02.14 — Restore wipes the image's optimization state; reset
        // the savings floor so the next compress climbs cleanly from 0.
        // NOT reset on Compress click — re-compress should hold the prior
        // high while the new run converges (prevents 91% → 83% → 91%
        // flicker during in-flight ic_savings recompute).
        window['wpcSavingsMax_' + attachment_id] = 0;

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 90000,
            data: {
                action: 'wps_ic_restore_live',
                attachment_id: attachment_id,
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    // 7.01.7 — animated swap detects Restoring → Uncompressed transition
                    wpcSwapCardWithAnimation(attachment_id, response.data.html);
                    // 7.01.17 — If the response HTML still shows a pending state
                    // (e.g. regen still running on a slow host), poll until it
                    // clears. v7.02 — Post-restore eager flip lands an Uncompressed
                    // card with `.is-regen-pending` class while WP regenerates
                    // sub-sizes. wpcWatchCard polls every 5s until the class is
                    // removed (regen done) → re-renders the card → Compress
                    // becomes available. Budget bumped to 3 minutes for slow hosts.
                    if (response.data.html.indexOf('is-restoring') > -1 ||
                        response.data.html.indexOf('is-compressing') > -1 ||
                        response.data.html.indexOf('is-regen-pending') > -1) {
                        wpcWatchCard(attachment_id, { maxAttempts: 18, interval: 12000 });
                    }
                } else if (response.success && response.data && response.data.queued) {
                    // v7.02.14 — Async loopback dispatch happy path. The PHP
                    // handler dispatched restoreV4 to a separate FPM worker and
                    // returned without rendering compress_details (saves ~1.5-2s
                    // of click wall). The card was already painted with the
                    // is-restoring skeleton by THIS handler at line 992; leave
                    // it as-is and let wpcWatchCard poll for the ic_status
                    // transition when the async worker finishes restoreV4.
                    //
                    // DO NOT strip is-restoring — that's what caused the brief
                    // "looks like excluded card" flash users reported.
                    wpcWatchCard(attachment_id, { maxAttempts: 18, interval: 12000 });
                } else {
                    card.removeClass('is-restoring');
                    var msg = (response.data && response.data.msg) ? response.data.msg : '';
                    if (msg && $('#' + msg).length) {
                        WPCSwal.fire({
                            title: '',
                            html: $('#' + msg).html(),
                            width: 500,
                            showConfirmButton: false,
                            allowOutsideClick: true,
                            customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                        });
                    }
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                card.removeClass('is-restoring');
                button.removeClass('wpc-action-pending');
                heartbeatBurst();
                // 7.01.17 — On error/timeout, the server may still be working. Watch
                // the card so it picks up completion via the polling fallback.
                wpcWatchCard(attachment_id);
            }
        });
    });

    /**
     * Exclude Link
     * wps-ic-exclude-live-link
     */
    $('.wps-ic-exclude-live-link,.wps-ic-include-live-link').on('click', function (e) {
        e.preventDefault();

        var link = $(this);
        var action = $(link).data('action');
        var attachment_id = $(link).data('attachment_id');
        var loading = $('#wp-ic-image-loading-' + attachment_id);

        $(link).hide();
        $(loading).show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_exclude_live',
                attachment_id: attachment_id,
                do_action: action,
                nonce: wpc_ajaxVar.nonce
            },
            success: function (response) {

                if (action == 'exclude') {
                    // Show include
                    $('#wps-ic-exclude-live-link-' + attachment_id).hide();
                    $('#wps-ic-include-live-link-' + attachment_id).show();
                } else {
                    // Show exclude
                    $('#wps-ic-exclude-live-link-' + attachment_id).show();
                    $('#wps-ic-include-live-link-' + attachment_id).hide();
                }

                $(loading).hide();
            },
            error: function (xhr, ajaxOptions, thrownError) {
                console.log(xhr.status);
                console.log(xhr.responseText);
                console.log(thrownError);
            }
        });

    });

    /**
     * Compress Live
     */
    $('body').on('click', '.wps-ic-compress-live-no-credits', function (e) {
        e.preventDefault();

        allowRefresh = false;
        window.allowRefresh = false;
        var button = $(this);
        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        // Load Popup
        WPCSwal.fire({
            title: '',
            html: $('#no-credits-popup').html(),
            width: 600,
            showCancelButton: false,
            showConfirmButton: false,
            confirmButtonText: 'Okay, I Understand',
            allowOutsideClick: true,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6',
            },
            onOpen: function () {
            }
        });

        return false;
    });

    /**
     * Compress Live — fire and forget, uses queue
     */
    $('body').on('click', '.wps-ic-compress-live', function (e) {
        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');

        // 7.01.7 — Two-phase loading copy: "Queueing…" for first 2.5s while we
        // upload + service starts encoding, then "Optimizing…" once we're past the
        // queue handoff. Crossfade the text via opacity transition for a smooth swap.
        card.removeClass('wpc-ml-card--uncompressed wpc-ml-card--compressed is-restored is-restoring').addClass('is-compressing');
        // v7.02 — Include the variant-count chip in the initial card HTML so
        // SSE can update it from variant #1, instead of the chip first
        // appearing AFTER the eager-flip card-swap to Compressed (by which
        // time 8-15 variants already landed).
        card.find('.wpc-ml-body').html(
            '<div class="fade-in-up">' +
            '<div class="wpc-ml-title"><span class="wpc-loading-text">Queueing&hellip;</span></div>' +
            '<div class="wpc-skeleton"><div class="wpc-skeleton-bar w-long"></div><div class="wpc-skeleton-bar w-short"></div></div>' +
            '<div class="wpc-variant-count-chip-row" style="display:none;margin-top:6px;line-height:1;">' + // (v7.03.68) hidden on the JS-built on-click "Optimizing…" card too — matches the PHP page-load card (.65); the count still updates the hidden chip for the heartbeat/?wpc_counter_debug
                '<span class="wpc-variant-count-chip" style="display:inline-flex;align-items:center;gap:4px;' +
                'padding:2px 7px;border-radius:9px;background:rgba(120,120,140,0.12);' +
                'font-size:10px;font-weight:600;letter-spacing:.2px;color:#445;">' +
                '<span style="opacity:.95;">0</span>' +
                '<span style="opacity:.35;margin:0 1px;">·</span>' +
                '<span style="color:#888;">0J</span>' +
                '<span style="color:#0aa56b;">0W</span>' +
                '<span style="color:#7c4ddc;">0A</span>' +
                '</span></div>' +
            '</div>'
        );
        // After 2.5s, crossfade to "Optimizing…" if compress is still in flight
        var loadingPhaseTimer = setTimeout(function () {
            if (!card.hasClass('is-compressing')) return;
            var $loadingText = card.find('.wpc-loading-text');
            if (!$loadingText.length) return;
            $loadingText.css('opacity', 0);
            setTimeout(function () {
                $loadingText.text('Optimizing\u2026');
                $loadingText.css('opacity', 1);
            }, 200);
        }, 2500);
        window['wpcLoadingPhase_' + attachment_id] = loadingPhaseTimer;

        // v7.02 Stage 2 — Chip / savings / variant-chip updates now ride the
        // SHARED heartbeat instead of a per-image count poller + SSE stream.
        // One heartbeat request returns chip data for every active image.
        // Zero per-image FPM cost beyond the heartbeat we already pay.
        //
        // Per-click reset of in-memory state so a re-compress starts clean:
        window['wpcBest_' + attachment_id]       = 0;
        window['wpcBadgeQueue_' + attachment_id] = [];
        window['wpcBadgeLast_'  + attachment_id] = 0;
        // v7.02.10 — Reset monotonic chip max so a re-compress animates
        // 0 → N rather than starting from the previous session's high.
        window['wpcChipMax_' + attachment_id]    = { count: 0, jpeg: 0, webp: 0, avif: 0 };
        if (window['wpcBadgeDrainer_' + attachment_id]) {
            clearInterval(window['wpcBadgeDrainer_' + attachment_id]);
            delete window['wpcBadgeDrainer_' + attachment_id];
        }
        window['wpcCardSwapped_' + attachment_id] = false;
        window['wpcSince_' + attachment_id]       = 0;
        window['wpcSettleHB_' + attachment_id]    = 0;

        // Register with the active list — heartbeat will start sending chip
        // updates for this image on its next tick (within 500ms during burst).
        wpcMarkActive(attachment_id);
        // Ensure heartbeat is in burst mode so chip cadence is 500ms not 8s.
        heartbeatBurst();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            // 7.01.1 — 60s timeout accommodates blocking AJAX (~3-6s typical, up to ~15s worst case under service transient).
            // If exceeded, heartbeat polling kicks in as safety net (see error handler).
            timeout: 60000,
            data: {
                action: 'wps_ic_compress_live',
                bulk: false,
                attachment_id: attachment_id
            },
            success: function (response) {
                // 7.01.51 — Helper that only cancels the loading-phase timer when
                // we're ABOUT TO SWAP the card HTML (which would replace the loading
                // text element anyway). For paths where we're NOT swapping (retry
                // scheduled, queued, generic error fall-through), we let the timer
                // fire so the "Queueing… → Optimizing…" crossfade happens naturally
                // and the user sees the card making visible progress. Previous
                // behaviour cancelled unconditionally, leaving the card stuck on
                // "Queueing…" when AJAX returned fast with retry_scheduled (HTTP 0
                // service unreachable) until the eventual heartbeat hit.
                var cancelLoadingPhase = function () {
                    if (window['wpcLoadingPhase_' + attachment_id]) {
                        clearTimeout(window['wpcLoadingPhase_' + attachment_id]);
                        delete window['wpcLoadingPhase_' + attachment_id];
                    }
                };

                // 7.01.51 — Safety-net: if we're staying in is-compressing because
                // a retry is pending, force the loading text to "Optimizing…" within
                // 2.5s even if the original timer was already cancelled by a previous
                // code path. Keeps the user informed that work is still happening.
                var ensureOptimizingText = function () {
                    setTimeout(function () {
                        if (!card.hasClass('is-compressing')) return;
                        var $loadingText = card.find('.wpc-loading-text');
                        if (!$loadingText.length) return;
                        if ($loadingText.text().indexOf('Optimizing') !== -1) return;
                        $loadingText.css('opacity', 0);
                        setTimeout(function () {
                            $loadingText.text('Optimizing…');
                            $loadingText.css('opacity', 1);
                        }, 200);
                    }, 2500);
                };

                if (response.success && response.data && response.data.immediate && response.data.html) {
                    // 7.01.1 blocking AJAX happy path — final HTML already returned, swap directly.
                    // 7.01.7 — animated swap detects Optimizing → Compressed transition and fires
                    // the hero animation (savings rise + subtitle cascade).
                    cancelLoadingPhase();
                    wpcSwapCardWithAnimation(attachment_id, response.data.html);
                    // 7.01.17 — Compress kicks bg-swap callbacks that arrive over the next
                    // 30-120s and refine the savings %. The heartbeat handles those updates,
                    // but as belt-and-suspenders we also start a watch poller so the card
                    // stays current even if a heartbeat tick misses a transient window.
                    wpcWatchCard(attachment_id);
                } else if (response.success && response.data && response.data.retry_scheduled) {
                    // Transient 502 / HTTP 0 — singleCompressV4 scheduled a retry cron. UI keeps
                    // "is-compressing"; heartbeat catches the eventual success. Leave the
                    // loading-phase crossfade running so the user sees Queueing → Optimizing.
                    ensureOptimizingText();
                    heartbeatBurst();
                } else if (response.success && response.data && response.data.queued) {
                    // v7.02.13 — Async loopback dispatch happy path. Server
                    // dispatched Phase A to a separate FPM worker (frees the
                    // click worker in ~300ms instead of 9-14s of blocking
                    // wait). UI stays in is-compressing; heartbeat catches
                    // the state transition when the async worker finishes
                    // Phase A and sets ic_compressing.status to compressed.
                    ensureOptimizingText();
                    heartbeatBurst();
                } else if (response.success && response.data && response.data.html) {
                    // Other success variants with html payload
                    cancelLoadingPhase();
                    wpcSwapCardWithAnimation(attachment_id, response.data.html);
                } else if (!response.success && response.data && response.data.msg === 'no-credits') {
                    cancelLoadingPhase();
                    card.removeClass('is-compressing');
                    WPCSwal.fire({
                        title: '',
                        html: $('#no-credits-popup').html(),
                        width: 500,
                        showConfirmButton: false,
                        allowOutsideClick: true,
                        customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                    });
                } else if (!response.success && response.data && response.data.msg === 'file-already-compressed') {
                    // 7.01.51 — Image was already compressed before the click hit the server.
                    // Snap the card back to its real state via heartbeat instead of leaving
                    // it spinning on "Queueing…" indefinitely. Include the html field if PHP
                    // adds it later (graceful forward-compat).
                    cancelLoadingPhase();
                    if (response.data.html) {
                        wpcSwapCardWithAnimation(attachment_id, response.data.html);
                    } else {
                        // Force a fresh heartbeat tick by setting a one-shot transient hint
                        // via a no-op admin-ajax ping, then burst-poll.
                        card.removeClass('is-compressing');
                        heartbeatBurst();
                    }
                } else {
                    // Error path — rely on heartbeat to catch up if backend is still working.
                    // Let the loading phase advance so the user sees Optimizing instead of stuck-at-Queueing.
                    ensureOptimizingText();
                    heartbeatBurst();
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                // 7.01.51 — Network error or AJAX timeout. Let the loading phase
                // advance to "Optimizing…" so the user sees activity while the
                // heartbeat catches up. Previously cancelled the timer here too,
                // which produced stuck-at-Queueing on offline / firewall-blocked
                // sites.
                if (!card.hasClass('is-compressing')) {
                    // Card already swapped to a final state — clean up the timer.
                    if (window['wpcLoadingPhase_' + attachment_id]) {
                        clearTimeout(window['wpcLoadingPhase_' + attachment_id]);
                        delete window['wpcLoadingPhase_' + attachment_id];
                    }
                }
                heartbeatBurst();
                button.removeClass('wpc-action-pending');
            }
        });
    });


    // v7.02 — Bulletproof popup content swap. Tries multiple selectors in
    // succession; uses native DOM (innerHTML) for maximum reliability.
    //
    // Strategies (any one succeeds → return true):
    //   1. document.querySelector('.swal2-html-container') — works on every
    //      SwAL2 version (>=v8) and is the canonical way.
    //   2. WPCSwal.getHtmlContainer() — only on SwAL2 >=v9.
    //   3. jQuery selector — last-resort fallback.
    //
    // Logs to console on failure so the user can paste it back if needed.
    function wpcStatsSwap(html) {
        // Strategy 1 (primary): document.querySelector
        try {
            var nodes = document.querySelectorAll('.swal2-html-container');
            if (nodes && nodes.length) {
                nodes[nodes.length - 1].innerHTML = html;
                return true;
            }
        } catch (e) {}
        // Strategy 2: SwAL API
        try {
            if (typeof WPCSwal !== 'undefined' && WPCSwal && typeof WPCSwal.getHtmlContainer === 'function') {
                var node = WPCSwal.getHtmlContainer();
                if (node) { node.innerHTML = html; return true; }
            }
        } catch (e) {}
        // Strategy 3: jQuery
        try {
            var $h = $('.swal2-html-container');
            if ($h.length) {
                $h.last().html(html);
                return true;
            }
        } catch (e) {}
        return false;
    }

    // ─── Stats Detail Modal ─────────────────────────────────────
    $(document).on('click', '.wpc-stats-trigger', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var attachment_id = $btn.data('attachment_id');

        // v7.02 — Click guard: per-imageID time-based flag (800 ms) prevents
        // rapid double-clicks from firing two AJAX calls. Doesn't depend on
        // swal lifecycle hooks (some builds don't fire didClose reliably).
        var openFlag = 'wpcStatsModalOpen_' + attachment_id;
        if (window[openFlag]) return;
        window[openFlag] = true;
        setTimeout(function () { delete window[openFlag]; }, 800);

        // v7.02 Stage 2 — Per-image count-poller and SSE EventSource are
        // gone (heartbeat is now the sole chip/savings data source). No
        // FPM-worker triage needed at modal-open time. Drainers are pure
        // JS timers (no server hit), safe to leave running.

        // v7.02 — Loading state UX:
        //   1. Hide the sibling Restore button (display:none — gone from layout).
        //      User said "the restore is in the way" — gone during loading so
        //      Details can grow naturally to fit "Calculating…" without
        //      overlapping or shifting Restore.
        //   2. Replace Details content with [circle spinner] + "Calculating…".
        //      Spinner uses currentColor so it inherits the button's text color
        //      automatically (works in any theme — whitelabel, dark mode, etc.).
        //   3. Disable pointer events on Details so re-clicks during AJAX
        //      don't fire another request.
        //   4. On AJAX complete (success OR error): restore original button +
        //      show Restore again. Single atomic restore.
        if (!$btn.data('wpc-orig-html')) {
            $btn.data('wpc-orig-html', $btn.html());
        }
        // v7.02 — Per-image Restore hide. Two layers:
        //   (a) Inject a high-specificity CSS rule scoped to this image's
        //       container — survives card re-renders.
        //   (b) ALSO call $.hide() on whatever matches right now — covers the
        //       case where the CSS selector somehow doesn't match the actual
        //       DOM structure (extra wrapper, whitelabel rename, etc.).
        //
        // Console-log every step so we can diagnose if it still doesn't hide.
        var hideRuleId = 'wpc-stats-hide-restore-' + attachment_id;
        if (!document.getElementById(hideRuleId)) {
            var hideStyle = document.createElement('style');
            hideStyle.id = hideRuleId;
            // Extra-specific: body + container + descendant + ID-bracketed.
            // Multiple selectors increase chance of matching whatever DOM
            // shape Restore is actually in.
            hideStyle.textContent =
                'body .wps-ic-media-actions-' + attachment_id + ' .wps-ic-restore-live,' +
                'body .wps-ic-media-actions-' + attachment_id + ' a.wps-ic-restore-live,' +
                'body a.wps-ic-restore-live[data-attachment_id="' + attachment_id + '"]' +
                '{display:none !important;visibility:hidden !important;}';
            document.head.appendChild(hideStyle);
        }
        // Also do an immediate jQuery hide as a belt-and-suspenders fallback.
        // Finds any Restore button matching the image (regardless of DOM structure)
        // and hides it now. CSS rule above keeps it hidden across re-renders.
        var $immediateRestore = $('a.wps-ic-restore-live[data-attachment_id="' + attachment_id + '"]');
        $immediateRestore.hide();
        $btn.css({ cursor: 'default', 'pointer-events': 'none' });
        // v7.02 — Classic sequential-reveal: "" → "." → ".." → "..." → "" (cycle).
        //
        // Three plain inline spans, no wrapper, no margin/letter-spacing tricks.
        // The dots render at their NATURAL font character width — exactly like
        // typing "..." inline — so the gap between "Calculating" and the dots
        // is the font's normal kerning + the dots' natural spacing.
        //
        // Opacity-only animation = zero CLS (opacity is paint-only, the dot
        // character still occupies layout space when invisible).
        //
        // Cycle is 2s with 5 equal slots (400ms each):
        //   0-20%  : no dots         (Calculating)
        //   20-40% : 1 dot           (Calculating.)
        //   40-60% : 2 dots          (Calculating..)
        //   60-80% : 3 dots          (Calculating...)
        //   80-100%: no dots         (snap reset)
        //
        // Tight 0.1% transition between keyframes = snap-on/snap-off feel.
        // Three dots — each explicitly inline + letter-spacing:0 to override
        // any inherited letter-spacing from the button/admin theme that was
        // pushing dots apart. white-space:nowrap on the wrapper keeps the
        // "Calculating..." from breaking across lines on narrow viewports.
        $btn.html(
            '<span style="white-space:nowrap;letter-spacing:0;">' +
                'Calculating' +
                '<span class="wpc-calc-d1" style="letter-spacing:0;">.</span>' +
                '<span class="wpc-calc-d2" style="letter-spacing:0;">.</span>' +
                '<span class="wpc-calc-d3" style="letter-spacing:0;">.</span>' +
            '</span>'
        );
        // Inject keyframes once (idempotent — id-gated).
        if (!document.getElementById('wpc-stats-calc-dots-keyframes')) {
            var styleEl = document.createElement('style');
            styleEl.id = 'wpc-stats-calc-dots-keyframes';
            // v7.02 — Cycle: "." → ".." → "..." → "." → ... (always at least 1 dot).
            //   0-25%   : "."        d1=1, d2=0, d3=0
            //   25-50%  : ".."       d1=1, d2=1, d3=0
            //   50-75%  : "..."      d1=1, d2=1, d3=1
            //   75-100% : "."        d1=1, d2=0, d3=0  (back to 1)
            // d1 is permanently visible (no animation). d2 and d3 toggle on/off.
            // 1.2s total cycle (300ms per state) — snappy without being frantic.
            styleEl.textContent =
                '.wpc-calc-d1{opacity:1;}' +
                '@keyframes wpc-calc-d2{0%,24.9%{opacity:0;}25%,74.9%{opacity:1;}75%,100%{opacity:0;}}' +
                '@keyframes wpc-calc-d3{0%,49.9%{opacity:0;}50%,74.9%{opacity:1;}75%,100%{opacity:0;}}' +
                '.wpc-calc-d2{animation:wpc-calc-d2 1.2s infinite;}' +
                '.wpc-calc-d3{animation:wpc-calc-d3 1.2s infinite;}';
            document.head.appendChild(styleEl);
        }

        // v7.02 — Stale-card reconcile DEFERRED to after the modal renders.
        // Previously we fired wps_ic_get_card in parallel with the stats AJAX,
        // burning a second FPM worker. On saturated hosts this could push the
        // stats request past its timeout for no UX benefit (the card is behind
        // the modal anyway and isn't visible). Now it fires after the modal
        // has opened — see deferred call inside the stats success branch.

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            // v7.02 — Bumped 15s → 30s. Even with polling suspended, an FPM
            // pool that was fully saturated may still need a few seconds to
            // drain before the stats request gets a worker. 30s gives the
            // request room to actually complete instead of failing visibly.
            timeout: 30000,
            data: {
                action: 'wps_ic_image_stats',
                attachment_id: attachment_id,
                wps_ic_nonce: wpc_ajaxVar.nonce
            },
            complete: function () {
                // Restore Details button content + clear inline styles. Restore
                // button is handled separately in WPCSwal.fire's onClose so it
                // stays hidden as long as the popup is open.
                var origHtml = $btn.data('wpc-orig-html');
                if (origHtml) {
                    $btn.html(origHtml);
                    $btn.removeData('wpc-orig-html');
                }
                $btn.css({ cursor: '', 'pointer-events': '' });
            },
            error: function (xhr, status) {
                var $errBtn = $btn;
                WPCSwal.fire({
                    title: '',
                    html: '<div style="padding:24px;text-align:center;color:#64748b;font-size:13px;">' +
                          'Could not load optimization results (' + (status || 'error') + ', HTTP ' + (xhr && xhr.status ? xhr.status : '?') + '). Try again.' +
                          '</div>',
                    width: 480,
                    showConfirmButton: false,
                    showCloseButton: true,
                    allowOutsideClick: true,
                    customClass: { container: 'no-padding-popup-bottom-bg wpc-popup-v6' },
                    onClose: function () {
                        try {
                            var rule = document.getElementById('wpc-stats-hide-restore-' + attachment_id);
                            if (rule && rule.parentNode) rule.parentNode.removeChild(rule);
                        } catch (e) {}
                    }
                });
            },
            success: function (response) {
                if (response && response.success && response.data && response.data.html) {
                    // Capture btn reference for the onClose callback — `$btn` may
                    // be re-bound by jQuery internals between fire() and close.
                    var $closeBtn = $btn;
                    WPCSwal.fire({
                        title: '',
                        html: response.data.html,
                        width: 680,
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        customClass: {
                            container: 'no-padding-popup-bottom-bg wpc-popup-v6',
                            popup: 'wpc-stats-swal'
                        },
                        // v7.02 — Show Restore again when popup closes (not in
                        // the AJAX `complete:` handler — that fires the moment
                        // WPCSwal.fire returns, which is BEFORE the user sees
                        // the popup. Restoring there briefly flashes Restore on
                        // the card behind the open popup.
                        onClose: function () {
                            try {
                                var rule = document.getElementById('wpc-stats-hide-restore-' + attachment_id);
                                if (rule && rule.parentNode) rule.parentNode.removeChild(rule);
                            } catch (e) {}
                        }
                    });

                    // Fire entrance animations + slider init. Wrap in try/catch
                    // so any NPE here can't surface as a broken popup — the
                    // popup HTML is already on screen.
                    try { (function () {
                        // Staggered row entrance + bar fill animation
                            var rows = document.querySelectorAll('.wpc-row-enter');
                            rows.forEach(function (row, i) {
                                setTimeout(function () {
                                    row.classList.add('wpc-row-active');
                                    var bar = row.querySelector('.wpc-bar-fill');
                                    if (bar) {
                                        setTimeout(function () {
                                            bar.style.width = bar.getAttribute('data-target') + '%';
                                        }, 200);
                                    }
                                }, i * 30 + 100);
                            });

                            // Initialize before/after comparison slider
                            var wrap = document.querySelector('.wpc-compare-wrap');
                            if (!wrap) return;

                            var handle = wrap.querySelector('.wpc-compare-handle');
                            var before = wrap.querySelector('.wpc-compare-before');
                            var beforeImg = before.querySelector('img');
                            var dragging = false;

                            // Set before image width to match container
                            beforeImg.style.width = wrap.offsetWidth + 'px';

                            function updateSlider(x) {
                                var rect = wrap.getBoundingClientRect();
                                var pct = Math.max(0, Math.min(1, (x - rect.left) / rect.width));
                                handle.style.left = (pct * 100) + '%';
                                before.style.width = (pct * 100) + '%';
                            }

                            handle.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                dragging = true;
                            });
                            handle.addEventListener('touchstart', function () {
                                dragging = true;
                            }, {passive: true});

                            document.addEventListener('mousemove', function (e) {
                                if (dragging) updateSlider(e.clientX);
                            });
                            document.addEventListener('touchmove', function (e) {
                                if (dragging) updateSlider(e.touches[0].clientX);
                            }, {passive: true});

                            document.addEventListener('mouseup', function () { dragging = false; });
                            document.addEventListener('touchend', function () { dragging = false; });

                            // Click anywhere on image to reposition
                            wrap.addEventListener('click', function (e) {
                                updateSlider(e.clientX);
                            });
                    })(); } catch (animErr) {}
                } else {
                    // Server returned success:false. Show the error message.
                    var serverMsg = (response && response.data && response.data.msg)
                        || (response && typeof response.data === 'string' ? response.data : '')
                        || 'No optimization data available for this image.';
                    WPCSwal.fire({
                        title: '',
                        html: '<div style="padding:24px;text-align:center;color:#64748b;font-size:13px;">' +
                              String(serverMsg).replace(/[<>"]/g, '') +
                              '</div>',
                        width: 480,
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        customClass: { container: 'no-padding-popup-bottom-bg wpc-popup-v6' }
                    });
                }
            }
        });
    });

});

window.onbeforeunload = function () {
    // v7.02 — allowRefresh is declared inside the IIFE above (line 3) so it
    // isn't visible at the global scope where window.onbeforeunload runs.
    // Reference it via window.allowRefresh (the IIFE writes there) and guard
    // with typeof to avoid ReferenceError when undefined. Pre-fix: every page
    // unload threw "Uncaught ReferenceError: allowRefresh is not defined".
    if (typeof window.allowRefresh !== 'undefined' && window.allowRefresh === false) {
        return "Data will be lost if you leave the page, are you sure?";
    }
};