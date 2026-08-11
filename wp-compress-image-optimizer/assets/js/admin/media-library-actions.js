jQuery(document).ready(function ($) {

    
    
    
    
    var allowRefresh = true;
    window.allowRefresh = allowRefresh;
    
    
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

    






    function wpcStateOf(classes) {
        if (!classes) return 'unknown';
        if (classes.indexOf('wpc-ml-card--compressed') >= 0) return 'compressed';
        if (classes.indexOf('is-compressing') >= 0)         return 'compressing';
        if (classes.indexOf('is-restoring') >= 0)            return 'restoring';
        
        
        
        
        
        if (classes.indexOf('is-regen-pending') >= 0)        return 'regen-pending';
        if (classes.indexOf('wpc-ml-card--excluded') >= 0)   return 'excluded';
        if (classes.indexOf('wpc-ml-card--uncompressed') >= 0) return 'uncompressed';
        return 'unknown';
    }

    








    function wpcAnimateSavingsCounter($el, fromPct, toPct, finalText, duration, onComplete) {
        duration = duration || 900;
        var el = $el[0];
        if (!el) return;
        var startTime = null;
        function easeOutExpo(t) { return t === 1 ? 1 : 1 - Math.pow(2, -10 * t); }
        
        
        
        
        function setLeadingText(text) {
            var first = el.firstChild;
            if (first && first.nodeType === 3) {       
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
            
            
            setLeadingText(current.toFixed(1) + '% Saved');
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                
                
                
                
                setLeadingText(finalText);
                if (typeof onComplete === 'function') onComplete();
            }
        }
        requestAnimationFrame(tick);
    }

    









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
        
        
        
        
        
        window['wpcChipFade_' + imageID]   = setTimeout(function () { $chip.addClass('wpc-delta-chip-fade'); }, 3500);
        window['wpcChipRemove_' + imageID] = setTimeout(function () { $chip.remove(); }, 4100);
    }

    











    
    
    
    
    
    var WPC_BADGE_SPACING_MS = 600;
    function wpcEnqueueBadge(imageID, fmt, size) {
        var qKey = 'wpcBadgeQueue_' + imageID;
        var dKey = 'wpcBadgeDrainer_' + imageID;
        var lKey = 'wpcBadgeLast_' + imageID;

        if (!window[qKey]) window[qKey] = [];
        window[qKey].push({ fmt: fmt, size: size });

        if (window[dKey]) return; 

        window[dKey] = setInterval(function () {
            var queue = window[qKey] || [];
            var last  = window[lKey] || 0;
            var now   = Date.now();

            if (queue.length === 0) {
                
                
                
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

        
        var newHtml = (payload && typeof payload === 'object' && payload.html) ? payload.html : payload;
        var status  = hbStatus || (payload && typeof payload === 'object' && payload.status) || null;

        
        var $oldCard = $parent.find('.wpc-ml-card').first();
        var oldState = wpcStateOf($oldCard.attr('class') || '');
        var oldTitle = $oldCard.find('.wpc-ml-title').text();

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        var tmpDoc = document.createElement('div');
        tmpDoc.innerHTML = newHtml;
        var $candidate = $(tmpDoc).find('.wpc-ml-card').first();
        if ($candidate.length) {
            var candState = wpcStateOf($candidate.attr('class') || '');
            var candTitle = $candidate.find('.wpc-ml-title').text();

            
            if (oldState === candState && oldTitle === candTitle
                && oldState !== 'unknown' && oldTitle) {
                return;
            }

            
            
            
            
            
            if (oldState === 'compressed' && candState !== 'compressed') {
                return;
            }
            
            
            
            
            
            
            
            
            
            if (oldState === 'restoring' && candState === 'compressed') {
                return;
            }

            
            if (oldState === 'compressed' && candState === 'compressed' && oldTitle !== candTitle) {
                var $titleEl = $oldCard.find('.wpc-ml-title').first();
                var titleNode = $titleEl[0];
                if (titleNode && titleNode.firstChild && titleNode.firstChild.nodeType === 3) {
                    var pctRegexIn = /(\d+(?:\.\d+)?)\s*%/;
                    var oldPctM_ = oldTitle && oldTitle.match(pctRegexIn);
                    var candPctM_ = candTitle && candTitle.match(pctRegexIn);
                    var oldP = oldPctM_ ? parseFloat(oldPctM_[1]) : 0;
                    var candP = candPctM_ ? parseFloat(candPctM_[1]) : 0;
                    
                    
                    
                    
                    var savingsMaxKey = 'wpcSavingsMax_' + imageID;
                    var savingsMax = Math.max(window[savingsMaxKey] || 0, oldP, candP);
                    window[savingsMaxKey] = savingsMax;
                    if (candP > oldP + 0.05) {
                        $titleEl.addClass('wpc-savings-counting');
                        wpcAnimateSavingsCounter($titleEl, oldP, candP, candTitle, 700, function () {
                            $titleEl.removeClass('wpc-savings-counting');
                        });
                    } else if (candP + 0.05 < savingsMax) {
                        
                        
                        
                        
                        
                    } else {
                        
                        
                        
                        titleNode.firstChild.nodeValue = candTitle;
                    }
                    return;
                }
                
                
                
            }
        }

        
        $loading.hide();
        $parent.html(newHtml).show();

        var $newCard = $parent.find('.wpc-ml-card').first();
        if (!$newCard.length) return;
        var newState = wpcStateOf($newCard.attr('class') || '');
        var newTitle = $newCard.find('.wpc-ml-title').text();

        var stateChanged = oldState !== 'unknown' && newState !== 'unknown' && oldState !== newState;
        var titleChanged = oldTitle && newTitle && oldTitle !== newTitle;

        
        
        
        var pctRegex   = /(\d+(?:\.\d+)?)\s*%/;
        var oldPctM    = oldTitle && oldTitle.match(pctRegex);
        var newPctM    = newTitle && newTitle.match(pctRegex);
        var oldPct     = oldPctM ? parseFloat(oldPctM[1]) : 0;
        var newPct     = newPctM ? parseFloat(newPctM[1]) : 0;
        var pctClimbed = (oldPct > 0 && newPct > oldPct && (newPct - oldPct) < 100);

        
        
        
        
        var isCompressed = $newCard.hasClass('wpc-ml-card--compressed');

        
        
        
        
        
        
        
        
        
        
        
        
        if (isCompressed && newPct > 0) {
            var savingsMaxKey_full = 'wpcSavingsMax_' + imageID;
            var prevSavingsMax = window[savingsMaxKey_full] || 0;
            var newSavingsMax = Math.max(prevSavingsMax, newPct);
            window[savingsMaxKey_full] = newSavingsMax;
            if (newPct + 0.05 < newSavingsMax) {
                
                
                var $newTitleEl = $newCard.find('.wpc-ml-title').first();
                var newTitleNode = $newTitleEl[0];
                if (newTitleNode && newTitleNode.firstChild && newTitleNode.firstChild.nodeType === 3) {
                    newTitleNode.firstChild.nodeValue = newTitleNode.firstChild.nodeValue.replace(
                        pctRegex,
                        newSavingsMax.toFixed(1) + '%'
                    );
                    
                    
                    newTitle = $newCard.find('.wpc-ml-title').text();
                    newPct = newSavingsMax;
                    pctClimbed = (oldPct > 0 && newPct > oldPct && (newPct - oldPct) < 100);
                }
            }
        }

        
        
        
        
        
        
        
        if (isCompressed) {
            if (window['wpcLoadingPhase_' + imageID]) {
                clearTimeout(window['wpcLoadingPhase_' + imageID]);
                delete window['wpcLoadingPhase_' + imageID];
            }
            if (typeof wpcCancelCardPoll === 'function') {
                wpcCancelCardPoll(imageID);
            }
        }

        
        
        
        
        
        
        
        

        if (stateChanged) {
            
            
            $newCard.addClass('wpc-state-entering');
            setTimeout(function () {
                $newCard.removeClass('wpc-state-entering');
            }, 800);
        } else if (isCompressed && pctClimbed) {
            
            
            
            
            
            
            
            var $titleEl = $newCard.find('.wpc-ml-title').first();
            if ($titleEl.length) {
                $titleEl.addClass('wpc-savings-counting');
                wpcAnimateSavingsCounter($titleEl, oldPct, newPct, newTitle, 900, function () {
                    $titleEl.removeClass('wpc-savings-counting');
                });
            }
        } else if (titleChanged) {
            
            
            
            
            
            $newCard.addClass('wpc-bg-updated');
            setTimeout(function () { $newCard.removeClass('wpc-bg-updated'); }, 700);
        }

        
        if (window['wpcTimeout_' + imageID]) {
            clearTimeout(window['wpcTimeout_' + imageID]);
            delete window['wpcTimeout_' + imageID];
        }
        if (window['wpcLongTimeout_' + imageID]) {
            clearTimeout(window['wpcLongTimeout_' + imageID]);
            delete window['wpcLongTimeout_' + imageID];
        }
    }

    
















    var wpcHBInterval = 8000;
    var wpcHBTimer = null;
    var wpcHBErr = 0;
    var wpcHBBurstTimeout = null;
    var wpcHBRunning = false;
    var wpcActiveImages = [];

    function wpcMarkActive(id) {
        id = parseInt(id, 10);
        if (!id) return;
        var wasIdle = wpcActiveImages.length === 0;
        if (wpcActiveImages.indexOf(id) === -1) wpcActiveImages.push(id);
        
        
        var sk = 'wpcActiveTimeout_' + id;
        if (window[sk]) clearTimeout(window[sk]);
        window[sk] = setTimeout(function () {
            wpcMarkInactive(id);
            delete window[sk];
        }, 90000);
        
        
        
        if (wasIdle) wpcUpdateHeartbeatCadence();
    }

    function wpcMarkInactive(id) {
        id = parseInt(id, 10);
        if (!id) return;
        var i = wpcActiveImages.indexOf(id);
        if (i >= 0) wpcActiveImages.splice(i, 1);
        var sk = 'wpcActiveTimeout_' + id;
        if (window[sk]) { clearTimeout(window[sk]); delete window[sk]; }
        
        if (wpcActiveImages.length === 0) wpcUpdateHeartbeatCadence();
    }

    
    
    
    
    
    function wpcUpdateHeartbeatCadence() {
        var target = wpcActiveImages.length > 0 ? 3000 : 8000;
        if (target === wpcHBInterval) return;
        wpcStartHeartbeat(target);
    }

    function wpcStartHeartbeat(interval) {
        if (wpcHBTimer) clearInterval(wpcHBTimer);
        wpcHBInterval = interval;
        
        
        
        
        wpcHBTimer = setInterval(function () { heartbeat(); }, interval);
    }

    
    wpcStartHeartbeat(8000);

    
    
    
    
    
    if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) heartbeatBurst();
        });
    }

    
    
    
    $('.wpc-ml-card').slice(0, 12).each(function (i) {
        var $card = $(this);
        if ($card.hasClass('is-compressing') || $card.hasClass('is-restoring')) return;
        $card.addClass('wpc-cascade-in wpc-cascade-' + i);
        setTimeout(function () {
            $card.removeClass('wpc-cascade-in wpc-cascade-' + i);
        }, 1200 + (i * 60));
    });

    
    
    
    
    
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
        if (wpcHBRunning) return; 
        
        
        
        
        
        
        if (typeof document !== 'undefined' && document.hidden) { if (WPC_CDBG) console.log('[wpc-counter] HB skip (tab hidden)'); return; }
        wpcHBRunning = true;
        if (WPC_CDBG) console.log('[wpc-counter] HB tick active=[' + (wpcActiveImages || []).join(',') + ']');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 25000,
            
            
            
            
            
            data: {
                action: 'wps_ic_media_library_heartbeat',
                active: wpcActiveImages.slice() 
            },
            success: function (response) {
                wpcHBRunning = false;
                wpcHBErr = 0;
                if (WPC_CDBG) { var _hk = (response && response.data && response.data.html) ? Object.keys(response.data.html) : []; console.log('[wpc-counter] HB resp imgs=[' + _hk.join(',') + ']' + (response && response.success === true ? '' : ' (success!=true)')); }
                if (response && response.success === true && response.data && response.data.html) {
                    $.each(response.data.html, function (index, payload) {
                        if (!payload) return;
                        var imageID = parseInt(index, 10);
                        if (!imageID) return;

                        
                        
                        
                        
                        
                        
                        if ((payload.status === 'optimizing' || payload.status === 'queueing')
                            && wpcActiveImages.indexOf(imageID) === -1) {
                            wpcMarkActive(imageID);
                            if (WPC_CDBG) console.log('[wpc-counter] passive-track img=' + imageID + ' (SD optimizing → auto-registered)');
                        }

                        
                        
                        
                        
                        
                        
                        
                        
                        if (payload.html) {
                            var swappedKey = 'wpcCardSwapped_' + imageID;
                            if (payload.status === 'compressed' && !window[swappedKey]) {
                                window[swappedKey] = true;
                            }
                            wpcSwapCardWithAnimation(imageID, payload);
                        }

                        
                        
                        wpcHeartbeatApplyChipData(imageID, payload);
                    });

                    $('.ic-tooltip').tooltipster({
                        maxWidth: '300',
                    });
                }
            },
            error: function (xhr) {
                wpcHBRunning = false;
                var s = (xhr && xhr.status) || 0;
                if (s === 400 || s === 401 || s === 403 || s === 404 || s === 410) { if (wpcHBTimer) clearInterval(wpcHBTimer); return; }
                if (++wpcHBErr >= 10 && wpcHBTimer) { clearInterval(wpcHBTimer); }
            },
            complete: function () {
                wpcHBRunning = false;
            }
        });
    };

    
    
    
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

    




    function wpcHeartbeatApplyChipData(imageID, payload) {
        var $card = $('.wps-ic-media-actions-' + imageID).find('.wpc-ml-card').first();
        if (!$card.length) return;

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
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

                    
                    
                    
                    
                    
                    if (WPC_CDBG) console.log('[wpc-counter] chip img=' + imageID + ' -> ' + next.count + ' (J' + next.jpeg + ' W' + next.webp + ' A' + next.avif + ') ' + ($card.hasClass('wpc-ml-card--compressed') ? 'compressed' : 'optimizing') + (grew ? ' [grew]' : ''));
                } else if (WPC_CDBG) { console.log('[wpc-counter] chip img=' + imageID + ' SKIP parts=' + parts.length + ' (<5)'); }
            } else if (WPC_CDBG) { console.log('[wpc-counter] chip img=' + imageID + ' SKIP: no .wpc-variant-count-chip in ' + ($card.hasClass('wpc-ml-card--compressed') ? 'compressed' : 'optimizing') + ' card'); }
        } else if (WPC_CDBG) { console.log('[wpc-counter] img=' + imageID + ' payload has NO chip{} (status=' + (payload.status || '?') + ')'); }

        
        
        
        if (!$card.hasClass('wpc-ml-card--compressed')) return;

        
        
        
        
        
        
        
        
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
            
        }

        
        
        
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

    
    
    
    
    
    
    
    
    function heartbeatBurst() {
        
        
        
        
        
        
        
        if (wpcHBInterval !== 250) {
            wpcStartHeartbeat(250);
        }
        
        if (wpcHBBurstTimeout) clearTimeout(wpcHBBurstTimeout);
        wpcHBBurstTimeout = setTimeout(function () {
            
            
            var fallback = wpcActiveImages.length > 0 ? 3000 : 8000;
            wpcStartHeartbeat(fallback);
            wpcHBBurstTimeout = null;
        }, 60000);
        
        heartbeat();
    }

    
    
    
    
    
    
    
    
    
    
    
    
    
    
    var wpcCardPollers = {}; 

    function wpcCancelCardPoll(imageID) {
        if (wpcCardPollers[imageID]) {
            clearTimeout(wpcCardPollers[imageID]);
            delete wpcCardPollers[imageID];
        }
    }

    function wpcWatchCard(imageID, opts) {
        opts = opts || {};
        var maxAttempts = opts.maxAttempts || 12;
        
        
        
        
        var interval = opts.interval || 12000;
        var attempts = 0;

        wpcCancelCardPoll(imageID); 

        var poll = function () {
            
            
            
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
                    
                    wpcCardPollers[imageID] = setTimeout(poll, interval);
                }
            });
        };
        wpcCardPollers[imageID] = setTimeout(poll, interval);
    }

    
















    var $compressingCards = $('.wpc-ml-card.is-compressing');
    if ($compressingCards.length > 0) {
        $compressingCards.each(function () {
            var attEl = $(this).closest('[class*="wps-ic-media-actions-"]');
            var cls   = attEl.attr('class') || '';
            var m     = cls.match(/wps-ic-media-actions-(\d+)/);
            if (m) {
                var id = parseInt(m[1], 10);
                wpcMarkActive(id);
                
                
                
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

    
    
    
    
    
    
    
    

    


    $('body').on('click', '.wpc-purge-variants-link', function (e) {
        e.preventDefault();
        var link = $(this);
        if (link.hasClass('wpc-action-pending')) return;
        if (!window.confirm('Remove the locally generated AVIF/WebP variant files for this image? They regenerate on demand.')) return;
        link.addClass('wpc-action-pending').text('Purging…');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 30000,
            data: {
                action: 'wps_ic_purge_local_variants',
                attachment_id: link.data('attachment_id'),
                nonce: wpc_ajaxVar.nonce
            },
            complete: function (xhr) {
                var r = {};
                try { r = JSON.parse(xhr.responseText || '{}'); } catch (err) {}
                link.removeClass('wpc-action-pending');
                if (r && r.success) {
                    link.text('Purged (' + ((r.data && r.data.removed) || 0) + ' files)');
                    setTimeout(function () { link.text('Purge Variants'); }, 4000);
                } else {
                    link.text('Purge failed');
                    setTimeout(function () { link.text('Purge Variants'); }, 4000);
                }
            }
        });
    });

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

                    
                    if (isExcluded) {
                        card.addClass('is-excluded wpc-ml-card--excluded').removeClass('wpc-ml-card--uncompressed');
                    } else {
                        card.removeClass('is-excluded wpc-ml-card--excluded').addClass('wpc-ml-card--uncompressed');
                        
                        var iconBox = card.find('.wpc-ml-card-icon');
                        iconBox.css('animation', 'none');
                        iconBox[0].offsetHeight;
                        iconBox.css('animation', '');
                    }

                    
                    body.html('<div class="fade-in-up">' + newBody + '</div>');
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                button.removeClass('wpc-action-pending');
            }
        });
    });

    


    $('body').on('click', '.wps-ic-restore-live', function (e) {

        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');

        
        card.removeClass('wpc-ml-card--compressed wpc-ml-card--uncompressed is-compressed is-compressing').addClass('is-restoring');
        card.find('.wpc-ml-body').html('<div class="fade-in-up"><div class="wpc-ml-title">' + (wpc_ajaxVar.statusRestoring || 'Restoring') + '...</div><div class="wpc-skeleton"><div class="wpc-skeleton-bar w-short"></div><div class="wpc-skeleton-bar w-long"></div></div></div>');
        
        
        
        window['wpcChipMax_' + attachment_id] = { count: 0, jpeg: 0, webp: 0, avif: 0 };
        
        
        
        
        
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
                    
                    wpcSwapCardWithAnimation(attachment_id, response.data.html);
                    
                    
                    
                    
                    
                    
                    
                    if (response.data.html.indexOf('is-restoring') > -1 ||
                        response.data.html.indexOf('is-compressing') > -1 ||
                        response.data.html.indexOf('is-regen-pending') > -1) {
                        wpcWatchCard(attachment_id, { maxAttempts: 18, interval: 12000 });
                    }
                } else if (response.success && response.data && response.data.queued) {
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
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
                
                
                wpcWatchCard(attachment_id);
            }
        });
    });

    



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
                    
                    $('#wps-ic-exclude-live-link-' + attachment_id).hide();
                    $('#wps-ic-include-live-link-' + attachment_id).show();
                } else {
                    
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

    


    $('body').on('click', '.wps-ic-compress-live-no-credits', function (e) {
        e.preventDefault();

        allowRefresh = false;
        window.allowRefresh = false;
        var button = $(this);
        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        
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

    


    $('body').on('click', '.wps-ic-compress-live', function (e) {
        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');

        
        
        
        card.removeClass('wpc-ml-card--uncompressed wpc-ml-card--compressed is-restored is-restoring').addClass('is-compressing');
        
        
        
        
        card.find('.wpc-ml-body').html(
            '<div class="fade-in-up">' +
            '<div class="wpc-ml-title"><span class="wpc-loading-text">Queueing&hellip;</span></div>' +
            '<div class="wpc-skeleton"><div class="wpc-skeleton-bar w-long"></div><div class="wpc-skeleton-bar w-short"></div></div>' +
            '<div class="wpc-variant-count-chip-row" style="display:none;margin-top:6px;line-height:1;">' + 
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

        
        
        
        
        
        
        window['wpcBest_' + attachment_id]       = 0;
        window['wpcBadgeQueue_' + attachment_id] = [];
        window['wpcBadgeLast_'  + attachment_id] = 0;
        
        
        window['wpcChipMax_' + attachment_id]    = { count: 0, jpeg: 0, webp: 0, avif: 0 };
        if (window['wpcBadgeDrainer_' + attachment_id]) {
            clearInterval(window['wpcBadgeDrainer_' + attachment_id]);
            delete window['wpcBadgeDrainer_' + attachment_id];
        }
        window['wpcCardSwapped_' + attachment_id] = false;
        window['wpcSince_' + attachment_id]       = 0;
        window['wpcSettleHB_' + attachment_id]    = 0;

        
        
        wpcMarkActive(attachment_id);
        
        heartbeatBurst();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            
            
            timeout: 60000,
            data: {
                action: 'wps_ic_compress_live',
                bulk: false,
                attachment_id: attachment_id
            },
            success: function (response) {
                
                
                
                
                
                
                
                
                
                var cancelLoadingPhase = function () {
                    if (window['wpcLoadingPhase_' + attachment_id]) {
                        clearTimeout(window['wpcLoadingPhase_' + attachment_id]);
                        delete window['wpcLoadingPhase_' + attachment_id];
                    }
                };

                
                
                
                
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
                    
                    
                    
                    cancelLoadingPhase();
                    wpcSwapCardWithAnimation(attachment_id, response.data.html);
                    
                    
                    
                    
                    wpcWatchCard(attachment_id);
                } else if (response.success && response.data && response.data.retry_scheduled) {
                    
                    
                    
                    ensureOptimizingText();
                    heartbeatBurst();
                } else if (response.success && response.data && response.data.queued) {
                    
                    
                    
                    
                    
                    
                    ensureOptimizingText();
                    heartbeatBurst();
                } else if (response.success && response.data && response.data.html) {
                    
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
                    
                    
                    
                    
                    cancelLoadingPhase();
                    if (response.data.html) {
                        wpcSwapCardWithAnimation(attachment_id, response.data.html);
                    } else {
                        
                        
                        card.removeClass('is-compressing');
                        heartbeatBurst();
                    }
                } else {
                    
                    
                    ensureOptimizingText();
                    heartbeatBurst();
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                
                
                
                
                
                if (!card.hasClass('is-compressing')) {
                    
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


    
    
    
    
    
    
    
    
    
    
    function wpcStatsSwap(html) {
        
        try {
            var nodes = document.querySelectorAll('.swal2-html-container');
            if (nodes && nodes.length) {
                nodes[nodes.length - 1].innerHTML = html;
                return true;
            }
        } catch (e) {}
        
        try {
            if (typeof WPCSwal !== 'undefined' && WPCSwal && typeof WPCSwal.getHtmlContainer === 'function') {
                var node = WPCSwal.getHtmlContainer();
                if (node) { node.innerHTML = html; return true; }
            }
        } catch (e) {}
        
        try {
            var $h = $('.swal2-html-container');
            if ($h.length) {
                $h.last().html(html);
                return true;
            }
        } catch (e) {}
        return false;
    }

    
    $(document).on('click', '.wpc-stats-trigger', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var attachment_id = $btn.data('attachment_id');

        
        
        
        var openFlag = 'wpcStatsModalOpen_' + attachment_id;
        if (window[openFlag]) return;
        window[openFlag] = true;
        setTimeout(function () { delete window[openFlag]; }, 800);

        
        
        
        

        
        
        
        
        
        
        
        
        
        
        
        
        if (!$btn.data('wpc-orig-html')) {
            $btn.data('wpc-orig-html', $btn.html());
        }
        
        
        
        
        
        
        
        
        var hideRuleId = 'wpc-stats-hide-restore-' + attachment_id;
        if (!document.getElementById(hideRuleId)) {
            var hideStyle = document.createElement('style');
            hideStyle.id = hideRuleId;
            
            
            
            hideStyle.textContent =
                'body .wps-ic-media-actions-' + attachment_id + ' .wps-ic-restore-live,' +
                'body .wps-ic-media-actions-' + attachment_id + ' a.wps-ic-restore-live,' +
                'body a.wps-ic-restore-live[data-attachment_id="' + attachment_id + '"]' +
                '{display:none !important;visibility:hidden !important;}';
            document.head.appendChild(hideStyle);
        }
        
        
        
        var $immediateRestore = $('a.wps-ic-restore-live[data-attachment_id="' + attachment_id + '"]');
        $immediateRestore.hide();
        $btn.css({ cursor: 'default', 'pointer-events': 'none' });
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $btn.html(
            '<span style="white-space:nowrap;letter-spacing:0;">' +
                'Calculating' +
                '<span class="wpc-calc-d1" style="letter-spacing:0;">.</span>' +
                '<span class="wpc-calc-d2" style="letter-spacing:0;">.</span>' +
                '<span class="wpc-calc-d3" style="letter-spacing:0;">.</span>' +
            '</span>'
        );
        
        if (!document.getElementById('wpc-stats-calc-dots-keyframes')) {
            var styleEl = document.createElement('style');
            styleEl.id = 'wpc-stats-calc-dots-keyframes';
            
            
            
            
            
            
            
            styleEl.textContent =
                '.wpc-calc-d1{opacity:1;}' +
                '@keyframes wpc-calc-d2{0%,24.9%{opacity:0;}25%,74.9%{opacity:1;}75%,100%{opacity:0;}}' +
                '@keyframes wpc-calc-d3{0%,49.9%{opacity:0;}50%,74.9%{opacity:1;}75%,100%{opacity:0;}}' +
                '.wpc-calc-d2{animation:wpc-calc-d2 1.2s infinite;}' +
                '.wpc-calc-d3{animation:wpc-calc-d3 1.2s infinite;}';
            document.head.appendChild(styleEl);
        }

        
        
        
        
        
        

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            
            
            
            
            timeout: 30000,
            data: {
                action: 'wps_ic_image_stats',
                attachment_id: attachment_id,
                wps_ic_nonce: wpc_ajaxVar.nonce
            },
            complete: function () {
                
                
                
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
                        
                        
                        
                        
                        
                        onClose: function () {
                            try {
                                var rule = document.getElementById('wpc-stats-hide-restore-' + attachment_id);
                                if (rule && rule.parentNode) rule.parentNode.removeChild(rule);
                            } catch (e) {}
                        }
                    });

                    
                    
                    
                    try { (function () {
                        
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

                            
                            var wrap = document.querySelector('.wpc-compare-wrap');
                            if (!wrap) return;

                            var handle = wrap.querySelector('.wpc-compare-handle');
                            var before = wrap.querySelector('.wpc-compare-before');
                            var beforeImg = before.querySelector('img');
                            var dragging = false;

                            
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

                            
                            wrap.addEventListener('click', function (e) {
                                updateSlider(e.clientX);
                            });
                    })(); } catch (animErr) {}
                } else {
                    
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
    
    
    
    
    
    if (typeof window.allowRefresh !== 'undefined' && window.allowRefresh === false) {
        return "Data will be lost if you leave the page, are you sure?";
    }
};