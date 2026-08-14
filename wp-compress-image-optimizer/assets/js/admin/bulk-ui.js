








(function (window) {
    'use strict';

    var seenCompletedIds = {};
    
    
    
    var lastVariantMs = 0;
    var seenVariantKeys = {};
    var VARIANT_STREAM_CAP = 12;  

    function humanBytes(b) {
        b = Number(b);
        if (!isFinite(b) || b < 0) return '0 B';
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1024 * 1024 * 1024) return (b / 1024 / 1024).toFixed(1) + ' MB';
        return (b / 1024 / 1024 / 1024).toFixed(2) + ' GB';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function $sel(sel) { return document.querySelector(sel); }
    function setText(sel, v) { var el = $sel(sel); if (el) el.textContent = v; }
    function setHtml(sel, v) { var el = $sel(sel); if (el) el.innerHTML = v; }

    
    
    
    
    
    
    
    var prevTally = { counter: 0, variants: 0, bytesSaved: 0, pct: 0, total: 0 };

    
    
    function getPrevTally() { return prevTally; }

    
    
    
    
    var _ribbonStartedAt = null;
    var _ribbonFirstProcessed = 0;
    
    
    
    
    
    
    
    
    
    
    
    
    var FACT_ROTATE_MS = 5000;
    var _factIndex = 0;
    var _factTimer = null;
    var _factsCache = [];

    
    
    
    
    
    
    function _formatSecondsHuman(s) {
        if (s >= 60) return Math.floor(s / 60) + 'm ' + Math.round(s % 60) + 's';
        if (s >= 10) return Math.round(s) + 's';
        return s.toFixed(1) + 's';
    }
    function _formatCO2Kg(kg) {
        if (kg >= 1000) return (kg / 1000).toFixed(1) + ' t';
        if (kg >= 10)   return Math.round(kg) + ' kg';
        return kg.toFixed(1) + ' kg';
    }

    function _buildLiveFacts(processed, total, bytesSaved, variants, savingsPct, etaSec) {
        var bs   = Math.max(0, Number(bytesSaved) || 0);
        var bsMB = bs / (1024 * 1024);
        var v    = Math.floor(Number(variants) || 0);
        var pct  = Number(savingsPct) || 0;
        var sentences = [];

        
        
        
        

        if (bs > 0) {
            
            sentences.push("You've saved " + humanBytes(bs) + " of bandwidth so far");
            
            sentences.push("Approx. " + humanBytes(bs * 10000) + " saved every month at 10,000 visits");
        }

        if (bsMB >= 0.15) {
            
            sentences.push("Up to " + _formatSecondsHuman(bsMB / 1.5) + " faster page loads on mobile 4G");
        }

        if (bsMB > 0) {
            
            var kgPerYear = (bsMB * 100000 * 0.5 * 12) / 1000;
            sentences.push("About " + _formatCO2Kg(kgPerYear) + " of CO₂ avoided each year at 100,000 visits");
        }

        if (pct > 0) {
            
            sentences.push("Your pages are now " + pct.toFixed(1) + "% lighter — Google's Core Web Vitals love this");
        }

        if (v > 0) {
            
            sentences.push("Crafted " + v.toLocaleString() + " modern variants (AVIF + WebP + JPEG)");
        }

        if (etaSec && etaSec > 0) {
            sentences.push("About " + _formatEta(etaSec) + " left in this run");
        }

        return sentences;
    }

    function _renderCurrentFact(ribbon) {
        if (!_factsCache.length) return;
        var el = ribbon.querySelector('[data-field="ribbon-sentence"]');
        if (el) el.textContent = _factsCache[_factIndex % _factsCache.length];
    }

    function _updateBreakdownRibbon(processed, total, bytesSaved, variants, savingsPct) {
        var ribbon = $sel('.wpc-bulk-ribbon');
        if (!ribbon) return;
        if (ribbon.style.display === 'none') ribbon.style.display = '';

        if (_ribbonStartedAt === null && processed > 0) {
            _ribbonStartedAt = Date.now();
            _ribbonFirstProcessed = processed;
        }

        
        var etaSec = 0;
        if (_ribbonStartedAt) {
            var elapsedS = (Date.now() - _ribbonStartedAt) / 1000;
            var delta    = processed - _ribbonFirstProcessed;
            if (elapsedS >= 2 && delta >= 1) {
                var rate = delta / elapsedS;
                var remaining = Math.max(0, total - processed);
                etaSec = rate > 0 ? remaining / rate : 0;
            }
        }

        _factsCache = _buildLiveFacts(processed, total, bytesSaved, variants, savingsPct, etaSec);
        _renderCurrentFact(ribbon);

        if (!_factTimer && _factsCache.length > 1) {
            _factTimer = setInterval(function () {
                _factIndex = (_factIndex + 1) % Math.max(1, _factsCache.length);
                var r = $sel('.wpc-bulk-ribbon');
                if (!r) return;
                var sentence = r.querySelector('[data-field="ribbon-sentence"]');
                if (sentence) {
                    
                    
                    
                    
                    sentence.classList.add('is-fact-out');
                    setTimeout(function () {
                        _renderCurrentFact(r);
                        
                        sentence.classList.remove('is-fact-out');
                        sentence.classList.add('is-fact-in');
                        
                        
                        requestAnimationFrame(function () {
                            requestAnimationFrame(function () {
                                sentence.classList.remove('is-fact-in');
                            });
                        });
                    }, 320);
                } else {
                    _renderCurrentFact(r);
                }
            }, FACT_ROTATE_MS);
        }
    }

    function _formatEta(sec) {
        if (!isFinite(sec) || sec <= 0) return '—';
        sec = Math.round(sec);
        if (sec < 60) return sec + 's';
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        if (m < 60) return m + ':' + (s < 10 ? '0' + s : s);
        var h = Math.floor(m / 60);
        var mm = m % 60;
        return h + 'h ' + (mm > 0 ? mm + 'm' : '');
    }

    function _resetBreakdownRibbon() {
        _ribbonStartedAt = null;
        _ribbonFirstProcessed = 0;
        _factsCache = [];
        _factIndex = 0;
        if (_factTimer) { clearInterval(_factTimer); _factTimer = null; }
        var ribbon = $sel('.wpc-bulk-ribbon');
        if (ribbon) ribbon.style.display = 'none';
    }
    var ANIM_DUR_MS = 700;

    function _tickNum(el, fallbackFrom, to, formatter) {
        if (!el) return;
        
        
        
        
        
        var from = (el._wpcVal != null) ? el._wpcVal : fallbackFrom;
        if (from === to || !window.requestAnimationFrame) {
            el._wpcVal = to;
            el.textContent = formatter(to);
            return;
        }
        
        
        
        el._wpcGen = (el._wpcGen || 0) + 1;
        var myGen = el._wpcGen;
        var start = null;
        function ease(t) { return 1 - Math.pow(1 - t, 3); } 
        function step(ts) {
            if (el._wpcGen !== myGen) return; 
            if (start === null) start = ts;
            var p = Math.min(1, (ts - start) / ANIM_DUR_MS);
            var v = from + (to - from) * ease(p);
            el._wpcVal = v;
            el.textContent = formatter(v);
            if (p < 1) requestAnimationFrame(step);
            else { el._wpcVal = to; el.textContent = formatter(to); }
        }
        requestAnimationFrame(step);
    }

    
    
    
    
    
    
    
    
    var DELTA_CHIP_SPACING_MS = 750;
    var DELTA_CHIP_HOLD_MS    = 2800;
    var deltaChipQ = [];
    var deltaChipDrainer = null;
    var lastDeltaChipShownAt = 0;

    function _deltaFmtKey(fmt) {
        fmt = String(fmt || '').toLowerCase();
        if (fmt === 'avif') return 'avif';
        if (fmt === 'webp') return 'webp';
        if (fmt === 'png')  return 'png';
        return 'jpeg';
    }

    function _showDeltaChip(fmt, size) {
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        var heroHeadline = $sel('.wpc-bulk-v2-surface .wpc-bulk-hero-headline');
        var host;
        if (heroHeadline && heroHeadline.offsetParent !== null) {
            host = heroHeadline;
        } else {
            host = $sel('.wpc-bulk-summary-status');
        }
        if (!host) return;
        
        var existing = host.querySelector('.wpc-bulk-delta-chip');
        if (existing) existing.remove();

        var key = _deltaFmtKey(fmt);
        var fmtLabel = key === 'jpeg' ? 'JPEG' : key.toUpperCase();
        var sizeLabel = String(size || '').replace(/_/g, ' ');
        if (sizeLabel) sizeLabel = sizeLabel.charAt(0).toUpperCase() + sizeLabel.slice(1);

        var chip = document.createElement('span');
        chip.className = 'wpc-bulk-delta-chip is-' + key;
        chip.setAttribute('role', 'status');
        chip.setAttribute('aria-live', 'polite');
        chip.innerHTML =
            '<span class="wpc-delta-plus" aria-hidden="true">+</span>' +
            '<span class="wpc-delta-fmt">' + escapeHtml(fmtLabel) + '</span>' +
            '<span class="wpc-delta-size">' + escapeHtml(sizeLabel) + '</span>';
        host.appendChild(chip);

        setTimeout(function () {
            if (chip && chip.classList) chip.classList.add('is-fading');
        }, DELTA_CHIP_HOLD_MS);
        setTimeout(function () {
            if (chip && chip.parentNode) chip.parentNode.removeChild(chip);
        }, DELTA_CHIP_HOLD_MS + 700);
    }

    function _enqueueDeltaChip(fmt, size) {
        deltaChipQ.push({ fmt: fmt, size: size });
        if (deltaChipDrainer) return;
        deltaChipDrainer = setInterval(function () {
            var now = Date.now();
            if (deltaChipQ.length === 0) {
                if (now - lastDeltaChipShownAt >= DELTA_CHIP_SPACING_MS * 2) {
                    clearInterval(deltaChipDrainer);
                    deltaChipDrainer = null;
                }
                return;
            }
            if (now - lastDeltaChipShownAt < DELTA_CHIP_SPACING_MS) return;
            var item = deltaChipQ.shift();
            _showDeltaChip(item.fmt, item.size);
            lastDeltaChipShownAt = now;
        }, 100);
    }

    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    var FEED_VISIBLE_CAP = 8;          
    var FEED_ROW_SPACING_MS = 750;     
    var feedRowQ = [];
    var feedRowDrainer = null;
    var lastFeedRowAt = 0;

    function _enqueueFeedRow(row, fmt, sizeLabel, variantData) {
        feedRowQ.push({ row: row, fmt: fmt, size: sizeLabel, v: variantData });
        if (feedRowDrainer) return;
        feedRowDrainer = setInterval(function () {
            var now = Date.now();
            if (feedRowQ.length === 0) {
                if (now - lastFeedRowAt >= FEED_ROW_SPACING_MS * 2) {
                    clearInterval(feedRowDrainer);
                    feedRowDrainer = null;
                }
                return;
            }
            if (now - lastFeedRowAt < FEED_ROW_SPACING_MS) return;
            var item = feedRowQ.shift();
            _commitFeedRow(item);
            lastFeedRowAt = now;
        }, 80);
    }

    function _commitFeedRow(item) {
        var inner = $sel('.wpc-bulk-feed-inner');
        if (!inner || !item.row) return;

        
        item.row.style.opacity = '0';
        inner.insertBefore(item.row, inner.firstChild);

        
        var itemH = item.row.offsetHeight;

        
        
        inner.style.transition = 'none';
        inner.style.transform = 'translateY(-' + itemH + 'px)';
        
        void inner.offsetHeight;

        
        var willRemoveLast = inner.children.length > FEED_VISIBLE_CAP;
        var lastRow = willRemoveLast ? inner.children[inner.children.length - 1] : null;
        if (lastRow) {
            lastRow.style.transition = 'opacity 0.5s ease';
            lastRow.style.opacity = '0';
        }

        
        
        
        inner.style.transition = 'transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)';
        inner.style.transform = 'translateY(0)';
        item.row.style.transition = 'opacity 0.5s ease 0.1s';
        item.row.style.opacity = '1';

        
        var freshClass = 'is-fresh-' + item.fmt;
        item.row.classList.add(freshClass);

        
        var bar = item.row.querySelector('.wpc-bar-fill');
        if (bar && bar.dataset.targetPct) {
            requestAnimationFrame(function () {
                bar.style.width = bar.dataset.targetPct + '%';
            });
        }

        
        
        _showDeltaChip(item.fmt, item.size);

        
        
        
        

        
        
        
        setTimeout(function () {
            if (lastRow && lastRow.parentNode) lastRow.parentNode.removeChild(lastRow);
            inner.style.transition = 'none';
            inner.style.transform = '';

            
            setTimeout(function () {
                if (item.row && item.row.classList) item.row.classList.remove(freshClass);
            }, 350);
        }, 650);
    }

    
    
    
    
    
    
    
    
    
    
    
    
    
    
    var imageStats = {};         
    var heroImageId = null;
    var prevHeroPct = 0;
    var prevHeroPerFmt = { jpeg: 0, webp: 0, avif: 0, png: 0 };

    function _bumpImageStats(v) {
        if (!v || v.id == null || v.pending) return;
        var id = v.id;
        if (!imageStats[id]) {
            imageStats[id] = {
                thumb: '', title: '',
                jpeg: 0, webp: 0, avif: 0, png: 0,
                total: 0, savedBytes: 0, origBytes: 0, lastMs: 0,
                maxPct: 0
            };
        }
        var s = imageStats[id];
        if (v.thumb) s.thumb = v.thumb;
        if (v.title) s.title = v.title;
        s.lastMs = Math.max(s.lastMs, Number(v.ms) || 0);
        var fmt = String(v.format || '').toLowerCase();
        if (fmt === 'avif')      s.avif++;
        else if (fmt === 'webp') s.webp++;
        else if (fmt === 'png')  s.png++;
        else                     s.jpeg++;
        s.total++;
        var saved = Number(v.saved) || 0;
        var bytes = Number(v.bytes) || 0;
        s.savedBytes += saved;
        s.origBytes  += (saved + bytes);
        
        
        
        
        
        
        
        var vPct = Number(v.pct) || 0;
        if (vPct > s.maxPct) s.maxPct = vPct;
    }

    function _pickHeroImageId(newVariants) {
        
        
        
        
        
        
        if (Array.isArray(_lastActiveServer) && _lastActiveServer.length > 0) {
            
            
            
            
            
            var heroEntry = null;
            var heroSum = -1;
            for (var i = 0; i < _lastActiveServer.length; i++) {
                var e = _lastActiveServer[i];
                if (!e || e.id == null) continue;
                var s = (Number(e.jpeg) || 0) + (Number(e.webp) || 0) + (Number(e.avif) || 0);
                if (s > heroSum) {
                    heroSum = s;
                    heroEntry = e;
                }
            }
            
            
            
            
            
            
            if (heroEntry && heroEntry.id && heroSum > 0) return heroEntry.id;
        }
        
        
        
        
        
        
        
        if (Array.isArray(_lastCompletedServer) && _lastCompletedServer.length > 0) {
            var newestCompleted = _lastCompletedServer[0]; 
            if (newestCompleted && newestCompleted.id) return newestCompleted.id;
        }
        
        var newest = null;
        if (Array.isArray(newVariants)) {
            for (var i = 0; i < newVariants.length; i++) {
                var v = newVariants[i];
                if (!v || v.pending) continue;
                if (!newest || (Number(v.ms) || 0) > (Number(newest.ms) || 0)) newest = v;
            }
        }
        return newest ? newest.id : heroImageId;
    }

    
    function _pulseFmtLetter(field) {
        var el = $sel('[data-field="' + field + '"]');
        if (!el) return;
        el.classList.remove('wpc-vc-bump');
        
        void el.offsetWidth;
        el.classList.add('wpc-vc-bump');
        setTimeout(function () { if (el && el.classList) el.classList.remove('wpc-vc-bump'); }, 700);
    }

    function renderHero(newVariants) {
        var newHeroId = _pickHeroImageId(newVariants);
        if (newHeroId == null) return;

        
        
        
        
        
        
        
        
        
        
        
        
        
        var hero = $sel('.wpc-bulk-v2-surface .wpc-bulk-hero');
        if (!hero) return;

        
        if (hero.style.display === 'none') {
            hero.style.display = '';
            hero.classList.add('wpc-bulk-hero-enter');
            setTimeout(function () { hero.classList.remove('wpc-bulk-hero-enter'); }, 800);
        }

        
        
        
        
        
        
        var serverStats = null;
        if (Array.isArray(_lastActiveServer)) {
            for (var ai = 0; ai < _lastActiveServer.length; ai++) {
                if (_lastActiveServer[ai] && _lastActiveServer[ai].id === newHeroId) {
                    serverStats = _lastActiveServer[ai];
                    break;
                }
            }
        }
        
        
        if (window.localStorage && localStorage.getItem('wpc_bulk_debug') === '1') {
            try {
                console.log('[renderHero ENTRY]', {
                    newHeroId: newHeroId,
                    newHeroId_type: typeof newHeroId,
                    activeServer_ids: Array.isArray(_lastActiveServer)
                        ? _lastActiveServer.map(function(e){ return e ? {id: e.id, id_type: typeof e.id, jpeg: e.jpeg, webp: e.webp, avif: e.avif, count: e.count, savings_pct: e.savings_pct} : null; })
                        : 'not-array',
                    completedServer_ids: Array.isArray(_lastCompletedServer)
                        ? _lastCompletedServer.map(function(e){ return e ? {id: e.id, jpeg: e.jpeg, webp: e.webp, avif: e.avif, count: e.count} : null; })
                        : 'not-array',
                    serverStats_found: serverStats !== null,
                    serverStats_source: serverStats ? (function(){
                        for (var i=0; i<_lastActiveServer.length; i++) {
                            if (_lastActiveServer[i] === serverStats) return 'active';
                        }
                        return 'completed';
                    })() : 'none'
                });
            } catch (e) {}
        }
        
        
        
        
        
        
        
        if (!serverStats && Array.isArray(_lastCompletedServer)) {
            for (var ci = 0; ci < _lastCompletedServer.length; ci++) {
                if (_lastCompletedServer[ci] && _lastCompletedServer[ci].id === newHeroId) {
                    serverStats = _lastCompletedServer[ci];
                    break;
                }
            }
        }
        var stats = imageStats[newHeroId];
        if (serverStats) {
            
            if (!stats) {
                stats = imageStats[newHeroId] = {
                    thumb: serverStats.thumb || '', title: serverStats.title || '',
                    jpeg: 0, webp: 0, avif: 0, png: 0,
                    total: 0, savedBytes: 0, origBytes: 0, lastMs: 0, maxPct: 0
                };
            }
            
            
            
            
            
            
            stats.jpeg  = Number(serverStats.jpeg)  || 0;
            stats.webp  = Number(serverStats.webp)  || 0;
            stats.avif  = Number(serverStats.avif)  || 0;
            stats.total = Number(serverStats.count) || 0;
            var srvPct = Number(serverStats.savings_pct) || 0;
            if (srvPct > stats.maxPct) stats.maxPct = srvPct;
            
            if (window.localStorage && localStorage.getItem('wpc_bulk_debug') === '1') {
                try {
                    console.log('[renderHero]', {
                        newHeroId: newHeroId,
                        serverStats_jpeg: serverStats.jpeg,
                        serverStats_webp: serverStats.webp,
                        serverStats_avif: serverStats.avif,
                        serverStats_count: serverStats.count,
                        serverStats_pct: serverStats.savings_pct,
                        stats_after: { jpeg: stats.jpeg, webp: stats.webp, avif: stats.avif, total: stats.total, maxPct: stats.maxPct }
                    });
                } catch (e) {}
            }
        }
        if (!stats) return;
        
        var pct = stats.maxPct;

        var thumbEl = hero.querySelector('[data-field="hero-thumb"]');
        var nameEl  = hero.querySelector('[data-field="hero-filename"]');
        var pctEl   = hero.querySelector('[data-field="hero-pct"]');

        
        if (newHeroId !== heroImageId) {
            if (nameEl) nameEl.classList.add('is-changing');
            if (thumbEl) thumbEl.classList.add('is-loading');
            hero.classList.add('wpc-bulk-hero-pulse');
            setTimeout(function () { hero.classList.remove('wpc-bulk-hero-pulse'); }, 900);

            setTimeout(function () {
                if (nameEl) {
                    nameEl.textContent = stats.title || ('Image #' + newHeroId);
                    nameEl.classList.remove('is-changing');
                }
                if (thumbEl && stats.thumb) {
                    var img = new Image();
                    var apply = function () {
                        thumbEl.style.backgroundImage = "url('" + stats.thumb.replace(/'/g, "\\'") + "')";
                        thumbEl.classList.remove('is-loading');
                    };
                    img.onload = apply;
                    img.onerror = apply;
                    img.src = stats.thumb;
                }
            }, 260);

            
            
            
            heroImageId = newHeroId;
            prevHeroPct = 0;
            prevHeroPerFmt = { jpeg: 0, webp: 0, avif: 0, png: 0 };
            if (pctEl) pctEl._wpcVal = 0;
        }

        
        
        if (pctEl) {
            _tickNum(pctEl, prevHeroPct, pct, function (v) {
                return v.toFixed(1) + '%';
            });
        }
        prevHeroPct = pct;

        
        setText('[data-field="hero-count"]', String(stats.total));
        setText('[data-field="hero-jpeg"]',  stats.jpeg + 'J');
        setText('[data-field="hero-webp"]',  stats.webp + 'W');
        setText('[data-field="hero-avif"]',  stats.avif + 'A');

        if (stats.jpeg > prevHeroPerFmt.jpeg) _pulseFmtLetter('hero-jpeg');
        if (stats.webp > prevHeroPerFmt.webp) _pulseFmtLetter('hero-webp');
        if (stats.avif > prevHeroPerFmt.avif) _pulseFmtLetter('hero-avif');
        prevHeroPerFmt = { jpeg: stats.jpeg, webp: stats.webp, avif: stats.avif, png: stats.png };
    }

    function resetHero() {
        imageStats = {};
        heroImageId = null;
        prevHeroPct = 0;
        prevHeroPerFmt = { jpeg: 0, webp: 0, avif: 0, png: 0 };
    }

    





    function renderTally(d) {
        if (!d) return;

        var total = Number(d.total) || 0;
        var processed = Number(d.processed) || 0;
        var variants = Number(d.variants_total) || 0;
        var pct = total > 0 ? (100 * processed / total) : 0;

        
        
        
        
        
        
        
        var bytesSaved = Number(d.bytes_saved) || 0;
        var pctVal     = Number(d.savings_pct) || 0;

        _tickNum($sel('.wpc-bulk-counter'), prevTally.counter, processed, function (v) {
            return Math.floor(v) + ' / ' + total;
        });
        _tickNum($sel('.wpc-bulk-variants'), prevTally.variants, variants, function (v) {
            return Math.floor(v).toLocaleString();
        });
        _tickNum($sel('.wpc-bulk-savings-bytes'), prevTally.bytesSaved, bytesSaved, function (v) {
            return humanBytes(v);
        });
        _tickNum($sel('.wpc-bulk-savings-pct'), prevTally.pct, pctVal, function (v) {
            return v.toFixed(1) + '%';
        });

        prevTally.counter    = processed;
        prevTally.variants   = variants;
        prevTally.bytesSaved = bytesSaved;
        prevTally.pct        = pctVal;
        prevTally.total      = total;

        var newBar = $sel('.wpc-bulk-summary-progress-fill');
        if (newBar) newBar.style.width = pct + '%';

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        

        
        
        
        _updateBreakdownRibbon(processed, total, bytesSaved, variants, pctVal);

        
        
        
        
        
        
        
        var hasVariants = (Number(d.variants_total) || 0) > 0
                       || (Array.isArray(d.new_variants) && d.new_variants.length > 0)
                       || (Array.isArray(d.completed) && d.completed.length > 0);
        if (hasVariants) {
            var prepSkel = $sel('.bulk-preparing-optimize');
            if (prepSkel) prepSkel.style.display = 'none';
            var legacyBox = $sel('.bulk-compress-status-progress');
            if (legacyBox) legacyBox.style.display = 'none';
            var prepBox = $sel('.bulk-compress-status-progress-prepare');
            if (prepBox) prepBox.style.display = 'none';
            var legacyTitle = $sel('.bulk-process-file-name');
            if (legacyTitle) legacyTitle.style.display = 'none';
            var legacyBar = $sel('.bulk-status-progress-bar');
            if (legacyBar) legacyBar.style.display = 'none';

            var surface = $sel('.wpc-bulk-v2-surface');
            if (surface && surface.style.display === 'none') {
                surface.style.display = '';
                surface.classList.add('wpc-bulk-surface-enter');
                setTimeout(function () { surface.classList.remove('wpc-bulk-surface-enter'); }, 700);
            }
        }
    }

    











    
    
    
    
    var _lastActiveServer = null;
    
    
    
    var _lastCompletedServer = null;
    function setLastCompletedServer(completed) {
        if (Array.isArray(completed)) _lastCompletedServer = completed;
    }
    var _lastActiveTitlesText = null;
    function renderActiveTitles(active, hint, upNext) {
        
        if (Array.isArray(active)) _lastActiveServer = active;
        var text;
        if (active && active.length) {
            
            
            
            var first = active[0];
            text = first.title || ('Image #' + first.id);
            var rest = active.length - 1;
            if (rest > 0) text += '  +' + rest + ' more';
            _lastActiveTitlesText = text;
        } else if (hint === 'finalizing') {
            text = 'Finalizing variants…';
        } else if (hint === 'loading' && Array.isArray(upNext) && upNext.length) {
            
            var firstU = upNext[0];
            text = 'Up next: ' + (firstU.title || ('Image #' + firstU.id));
            var restN = upNext.length - 1;
            if (restN > 0) text += '  +' + restN + ' more';
        } else if (hint === 'loading' && _lastActiveTitlesText) {
            
            text = _lastActiveTitlesText;
        } else if (hint === 'loading') {
            
            text = 'Loading next batch…';
        } else {
            text = _lastActiveTitlesText || '—';
        }
        setText('.wpc-bulk-active-titles', text);
    }

    





    
    
    
    
    
    
    
    
    var _lastActiveThumbsSet = null;
    function renderActiveThumbs(active, hint, upNext) {
        var holder = $sel('.wpc-bulk-active-thumbs');
        if (!holder) return;
        active = active || [];
        upNext = upNext || [];

        var displayMode = 'active';
        var items = active;
        if (!active.length && hint === 'loading' && upNext.length) {
            displayMode = 'upnext';
            items = upNext.slice(0, 3);
        } else if (!active.length && hint === 'loading' && _lastActiveThumbsSet) {
            items = _lastActiveThumbsSet;
        }

        
        var keyParts = items.map(function (a) { return a.id; });
        keyParts.push('h:' + (hint || '') + ':m:' + displayMode);
        var newKey = keyParts.join(',');
        if (holder.getAttribute('data-key') === newKey) return;
        holder.setAttribute('data-key', newKey);

        var frag = document.createDocumentFragment();
        if (items.length) {
            for (var i = 0; i < items.length && i < 3; i++) {
                var a = items[i];
                var tile = document.createElement('div');
                tile.className = 'wpc-bulk-active-thumb' +
                    (displayMode === 'upnext' ? ' is-upnext' : '');
                tile.title = a.title || ('Image #' + a.id);
                if (displayMode === 'upnext') {
                    
                    var op = [0.92, 0.62, 0.35][i] || 0.35;
                    tile.style.opacity = String(op);
                }
                if (a.thumb) {
                    tile.style.backgroundImage = 'url("' + String(a.thumb).replace(/"/g, '\\"') + '")';
                } else {
                    tile.classList.add('is-empty');
                }
                frag.appendChild(tile);
            }
            if (displayMode === 'active') _lastActiveThumbsSet = active.slice(0, 3);
        } else if (hint === 'loading' || hint === 'finalizing') {
            
            
            var skel = document.createElement('div');
            skel.className = 'wpc-bulk-active-thumb is-skeleton';
            frag.appendChild(skel);
        }
        holder.innerHTML = '';
        holder.appendChild(frag);
    }

    
    
    function _injectUpNextIntoTitles(upNext, currentText) {
        
        
        if (!Array.isArray(upNext) || !upNext.length) return currentText;
        var names = upNext.slice(0, 3).map(function (u) { return u.title || ('Image #' + u.id); });
        return 'Up next: ' + names.join(', ');
    }

    



    function getLastVariantMs() { return lastVariantMs; }

    





    function _variantPillClass(fmt) {
        return (
            fmt === 'avif' ? 'is-avif' :
            fmt === 'webp' ? 'is-webp' :
            (fmt === 'jpg' || fmt === 'jpeg') ? 'is-jpeg' :
            fmt === 'png' ? 'is-png' :
            'is-other'
        );
    }

    














    
    
    
    function _variantBadgeClass(fmt) {
        if (fmt === 'avif') return 'wpc-fmt-avif';
        if (fmt === 'webp') return 'wpc-fmt-webp';
        if (fmt === 'jpg' || fmt === 'jpeg') return 'wpc-fmt-jpeg';
        if (fmt === 'png') return 'wpc-fmt-png';
        return 'wpc-fmt-jpeg';
    }

    function renderVariantStream(newVariants) {
        if (!Array.isArray(newVariants) || !newVariants.length) return;
        
        
        var inner = $sel('.wpc-bulk-feed-inner');
        if (!inner) return;
        var list = inner; 

        
        var sorted = newVariants.slice().sort(function (a, b) {
            return (Number(a.ms) || 0) - (Number(b.ms) || 0);
        });

        var addedAny = false;

        for (var i = 0; i < sorted.length; i++) {
            var v = sorted[i];
            if (!v) continue;
            var key = String(v.id) + '|' + String(v.key);
            var isPending = !!v.pending;
            var isNoImprovement = !!v.noImprovement;
            var seenState = seenVariantKeys[key]; 

            var ms = Number(v.ms) || 0;
            if (ms > lastVariantMs) lastVariantMs = ms;

            
            if (!isPending && seenState === 'pending') {
                seenVariantKeys[key] = 'persisted';
                _bumpImageStats(v);  
                var existing = list.querySelector(
                    '[data-variant-key="' + key.replace(/"/g, '\\"') + '"]'
                );
                if (existing) {
                    existing.classList.remove('is-pending-announce');
                    existing.classList.remove('is-no-improvement');
                    existing.classList.add('is-persist-confirm');

                    var savedFinal = Number(v.saved) || 0;
                    var bytesFinal = Number(v.bytes) || 0;
                    var pctFinal = Number(v.pct) || 0;
                    var origFinal = savedFinal + bytesFinal;

                    var origCell = existing.querySelector('.wpc-bulk-cell-orig');
                    var optCell  = existing.querySelector('.wpc-bulk-cell-opt');
                    var pctCell  = existing.querySelector('.wpc-savings-pct');
                    var barFill  = existing.querySelector('.wpc-bar-fill');
                    if (origCell) origCell.textContent = humanBytes(origFinal);
                    if (optCell)  optCell.textContent  = humanBytes(bytesFinal);
                    if (pctCell)  pctCell.textContent  = pctFinal + '%';
                    if (barFill)  barFill.style.width  = pctFinal + '%';

                    (function (r) {
                        setTimeout(function () {
                            if (r && r.classList) r.classList.remove('is-persist-confirm');
                        }, 700);
                    })(existing);
                }
                continue;
            }

            
            if (seenState === 'persisted') continue;
            if (seenState === 'pending' && isPending) continue;

            seenVariantKeys[key] = isPending ? 'pending' : 'persisted';

            var fmt = String(v.format || '').toLowerCase();
            var fmtClass = _variantBadgeClass(fmt);
            var fmtLabel = fmt ? fmt.toUpperCase() : 'IMG';
            var saved = Number(v.saved) || 0;
            var bytes = Number(v.bytes) || 0;
            var origBytes = saved + bytes;
            var pct = Number(v.pct) || 0;
            var title = v.title ? String(v.title) : ('Image #' + v.id);

            var rowClasses = ['wpc-bulk-completion-row'];
            if (isPending) rowClasses.push('is-pending-announce');
            if (isPending && isNoImprovement) rowClasses.push('is-no-improvement');

            var row = document.createElement('div');
            row.className = rowClasses.join(' ');
            row.setAttribute('data-variant-key', key);

            var thumbHtml = v.thumb
                ? '<div class="wpc-bulk-thumb" style="background-image:url(\'' +
                  String(v.thumb).replace(/'/g, "\\'") + '\')"></div>'
                : '<div class="wpc-bulk-thumb is-empty"></div>';

            
            
            
            
            
            
            
            
            
            
            var savingsBlock;
            var noImprovementPersisted = !isPending && pct < 1 && saved < 1024;
            if (isPending && isNoImprovement) {
                savingsBlock =
                    '<div class="wpc-bulk-cell-savings">' +
                        '<span class="wpc-savings-optimal" title="Encoder confirmed the source is already at its optimal size.">Source kept</span>' +
                    '</div>';
            } else if (noImprovementPersisted) {
                savingsBlock =
                    '<div class="wpc-bulk-cell-savings">' +
                        '<span class="wpc-savings-optimal" title="Source was already at its optimal size — no further compression possible.">Optimal</span>' +
                    '</div>';
            } else {
                
                
                
                savingsBlock =
                    '<div class="wpc-bulk-cell-savings">' +
                        '<span class="wpc-savings-pct">' + pct + '%</span>' +
                        '<div class="wpc-bar-track">' +
                            '<div class="wpc-bar-fill" data-target-pct="' + pct + '" style="width:0%"></div>' +
                        '</div>' +
                    '</div>';
            }

            row.innerHTML =
                '<div class="wpc-bulk-cell-image">' +
                    thumbHtml +
                    '<span class="wpc-bulk-name">' + escapeHtml(title) + '</span>' +
                '</div>' +
                '<div><span class="wpc-format-badge ' + fmtClass + '">' + escapeHtml(fmtLabel) + '</span></div>' +
                '<div class="wpc-bulk-cell-orig">' + humanBytes(origBytes) + '</div>' +
                '<div class="wpc-bulk-cell-opt">' + humanBytes(bytes) + '</div>' +
                savingsBlock;

            
            
            
            
            
            
            
            if (!isPending) {
                
                
                
                
                
                
                
                _bumpImageStats(v);
                renderHero([v]);
                _enqueueFeedRow(row, fmt, v.size_label, v);
                addedAny = true;
            } else {
                
                
                row.style.opacity = '0.7';
                list.insertBefore(row, list.firstChild);
                
                while (list.children.length > VARIANT_STREAM_CAP) {
                    list.removeChild(list.lastChild);
                }
            }
        }

        
        
        
        
    }

    


    function resetCompletionList() {
        seenCompletedIds = {};
        seenVariantKeys = {};
        lastVariantMs = 0;
        
        
        
        var inner = $sel('.wpc-bulk-feed-inner');
        if (inner) {
            inner.innerHTML = '';
            inner.style.transition = 'none';
            inner.style.transform = '';
        }
        feedRowQ.length = 0;
        if (feedRowDrainer) { clearInterval(feedRowDrainer); feedRowDrainer = null; }
        deltaChipQ.length = 0;
        if (deltaChipDrainer) { clearInterval(deltaChipDrainer); deltaChipDrainer = null; }

        var thumbs = $sel('.wpc-bulk-active-thumbs');
        if (thumbs) {
            thumbs.innerHTML = '';
            thumbs.removeAttribute('data-key');
        }
        
        resetHero();
        
        
        
        var hero = $sel('.wpc-bulk-v2-surface .wpc-bulk-hero');
        if (hero) hero.style.display = 'none';

        
        
        _resetBreakdownRibbon();
    }

    
    
    
    function renderCompletionList() {  }

    






    function renderFinalReveal(d) {
        var modal = $sel('.bulk-area-inner');
        if (modal && modal.classList) modal.classList.add('wpc-bulk-done');
        compressCompleted(d);
    }

    






    function compressCompleted(d) {
        d = d || {};
        var surface = $sel('.wpc-bulk-v2-surface');
        if (!surface) return;
        if (surface.style.display === 'none') surface.style.display = '';

        
        var views = surface.querySelectorAll('.wpc-bulk-view');
        for (var i = 0; i < views.length; i++) {
            var v = views[i];
            if (v.getAttribute('data-view') === 'completed') v.classList.add('is-active');
            else v.classList.remove('is-active');
        }

        
        
        var processed = Number(d.processed) || prevTally.counter || 0;
        var variants  = Number(d.variants_total) || prevTally.variants || 0;
        var bytes     = Number(d.bytes_saved) || prevTally.bytesSaved || 0;
        var pct       = Number(d.savings_pct) || prevTally.pct || 0;

        function setField(field, val) {
            var nodes = surface.querySelectorAll('[data-field="' + field + '"]');
            for (var i = 0; i < nodes.length; i++) nodes[i].textContent = val;
        }
        setField('final-count',    processed.toLocaleString());
        setField('final-variants', variants.toLocaleString());
        setField('final-saved',    humanBytes(bytes));
        setField('final-pct',      pct.toFixed(1) + '%');

        
        
        

        
        var stopBtn = document.querySelector('.wps-ic-stop-bulk-compress');
        if (stopBtn) stopBtn.style.display = 'none';
    }

    
    
    
    
    
    
    
    
    
    
    var _restoreState = {
        currentImageId: null,         
        currentImageStartedAt: null,  
        seenRecentIds: {},            
        revealedSurface: false
    };

    function _restoreEl() { return $sel('.wpc-restore-surface'); }

    function _restoreSwitchView(name) {
        var surface = _restoreEl();
        if (!surface) return;
        var views = surface.querySelectorAll('.wpc-restore-view');
        for (var i = 0; i < views.length; i++) {
            var v = views[i];
            if (v.getAttribute('data-view') === name) {
                v.classList.add('is-active');
            } else {
                v.classList.remove('is-active');
            }
        }
    }

    function _restoreSetField(field, value) {
        var surface = _restoreEl();
        if (!surface) return;
        var nodes = surface.querySelectorAll('[data-field="' + field + '"]');
        for (var i = 0; i < nodes.length; i++) nodes[i].textContent = value;
    }

    
    
    
    function _restoreFormatEta(seconds) {
        if (seconds == null || seconds <= 0) return '—';
        seconds = Math.max(0, Math.round(seconds));
        if (seconds < 60) return seconds + 's';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        if (m < 60) {
            
            return m + ':' + (s < 10 ? '0' + s : s);
        }
        var h = Math.floor(m / 60);
        var mm = m % 60;
        return h + 'h' + (mm > 0 ? mm + 'm' : '');
    }

    
    
    function _restoreFormatDuration(seconds) {
        if (seconds == null || seconds < 0) return '—';
        if (seconds < 10)  return Number(seconds).toFixed(1) + 's';
        if (seconds < 60)  return Math.round(seconds) + 's';
        var m = Math.floor(seconds / 60);
        var s = Math.round(seconds % 60);
        if (m < 60) return m + 'm' + (s > 0 ? ' ' + s + 's' : '');
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    }

    function restorePreparing(message) {
        var surface = _restoreEl();
        if (!surface) return;
        if (surface.style.display === 'none') surface.style.display = '';
        _restoreSwitchView('preparing');
        if (message) {
            var sub = surface.querySelector('.wpc-restore-prep-subtitle');
            if (sub) sub.textContent = message;
        }
    }

    function restoreProcessing(d) {
        var surface = _restoreEl();
        if (!surface) return;
        if (surface.style.display === 'none') surface.style.display = '';

        
        
        
        
        
        var count = Number(d.finished) || 0;
        var hasCurrent = d.current && d.current.id;
        if (count < 1 && !hasCurrent) return;

        _restoreSwitchView('processing');

        
        
        
        var total = Number(d.total) || 0;
        var pct   = Number(d.progress) || 0;
        var bytesRestored = Number(d.bytes_restored) || 0;
        var etaSeconds    = Number(d.eta_seconds) || 0;

        var countEl  = surface.querySelector('[data-field="count"]');
        var totalEl  = surface.querySelector('[data-field="total"]');
        var pctTxtEl = surface.querySelector('[data-field="pct"]');
        if (countEl)  _tickNum(countEl,  count, count, function (v) { return Math.floor(v).toLocaleString(); });
        if (totalEl)  _restoreSetField('total', total); 
        if (pctTxtEl) _tickNum(pctTxtEl, pct, pct, function (v) { return Math.round(v) + '%'; });

        
        var bar    = surface.querySelector('[data-field="bar"]');
        if (bar) bar.style.width = pct + '%';
        if (pctTxtEl) pctTxtEl.style.left = pct + '%';

        
        _restoreSetField('eta', _restoreFormatEta(etaSeconds));
        var avg = d.avg_seconds_per_image;
        _restoreSetField('avg', avg != null ? 'avg ' + _restoreFormatDuration(avg) + '/image' : '');

        
        
        var bytesEl = surface.querySelector('[data-field="bytes_restored"]');
        if (bytesEl) {
            _tickNum(bytesEl, bytesRestored, bytesRestored, function (v) {
                var human = _humanBytesLocal(v);
                return human + ' restored';
            });
        }

        
        var cur = d.current || {};
        var fileElapsed = Number(d.file_elapsed_seconds) || 0;
        if (cur.id && cur.id !== _restoreState.currentImageId) {
            _restoreState.currentImageId = cur.id;
            _restoreState.currentImageStartedAt = Date.now() - fileElapsed * 1000;

            var nameEl  = surface.querySelector('[data-field="name"]');
            var sizeEl  = surface.querySelector('[data-field="size"]');
            var thumbEl = surface.querySelector('[data-field="thumb"]');
            if (nameEl) nameEl.classList.add('is-animating');
            if (thumbEl) thumbEl.classList.remove('is-loaded');

            setTimeout(function () {
                if (nameEl) {
                    nameEl.textContent = cur.name || ('Image #' + cur.id);
                    nameEl.classList.remove('is-animating');
                }
                if (sizeEl) sizeEl.textContent = cur.size_h || '—';
                if (thumbEl && cur.url) {
                    var img = new Image();
                    img.onload = function () {
                        thumbEl.style.backgroundImage = 'url("' + cur.url.replace(/"/g, '\\"') + '")';
                        thumbEl.classList.add('is-loaded');
                    };
                    img.onerror = function () {
                        thumbEl.style.backgroundImage = 'url("' + cur.url.replace(/"/g, '\\"') + '")';
                        thumbEl.classList.add('is-loaded');
                    };
                    img.src = cur.url;
                }
            }, 280);
        }

        
        var liveElapsed = _restoreState.currentImageStartedAt
            ? Math.max(0, (Date.now() - _restoreState.currentImageStartedAt) / 1000)
            : fileElapsed;
        _restoreSetField('file_elapsed', liveElapsed > 0
            ? _restoreFormatDuration(liveElapsed)
            : '—');

        
        
        
        
        
        if (Array.isArray(d.recent)) {
            var feedInner = surface.querySelector('[data-field="recent"]');
            if (feedInner) {
                for (var j = d.recent.length - 1; j >= 0; j--) {
                    var r = d.recent[j];
                    if (!r || r.id == null) continue;
                    var rkey = String(r.id);
                    if (_restoreState.seenRecentIds[rkey]) continue;
                    _restoreState.seenRecentIds[rkey] = true;

                    var rowName  = r.name || ('Image #' + r.id);
                    var rowBytes = r.bytes_h || (r.bytes ? _humanBytesLocal(r.bytes) : '—');
                    var rowSrc   = (r.source || 'auto').toLowerCase();
                    var srcLabel = rowSrc === 'cloud' ? 'Cloud'
                                 : rowSrc === 'local' ? 'Local'
                                 : 'Auto';

                    var row = document.createElement('div');
                    row.className = 'wpc-bulk-completion-row wpc-bulk-completion-row--restore is-persist-confirm';
                    row.setAttribute('data-restore-id', rkey);

                    var thumbHtml = r.thumb
                        ? '<div class="wpc-bulk-thumb" style="background-image:url(\'' + String(r.thumb).replace(/'/g, "\\'") + '\')"></div>'
                        : '<div class="wpc-bulk-thumb is-empty"></div>';

                    row.innerHTML =
                        '<div class="wpc-bulk-cell-image">' +
                            thumbHtml +
                            '<div class="wpc-bulk-cell-image-name" title="' + rowName.replace(/"/g, '&quot;') + '">' + rowName + '</div>' +
                        '</div>' +
                        '<div class="wpc-bulk-cell-format">' +
                            '<span class="wpc-restore-source-chip wpc-restore-source-chip--' + rowSrc + '">' + srcLabel + '</span>' +
                        '</div>' +
                        '<div class="wpc-bulk-cell-orig">' + rowBytes + '</div>' +
                        '<div class="wpc-bulk-cell-status">' +
                            '<span class="wpc-restore-status-chip">' +
                                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>' +
                                'Restored' +
                            '</span>' +
                        '</div>';

                    if (feedInner.firstChild) {
                        feedInner.insertBefore(row, feedInner.firstChild);
                    } else {
                        feedInner.appendChild(row);
                    }

                    (function (rr) {
                        setTimeout(function () {
                            if (rr && rr.classList) rr.classList.remove('is-persist-confirm');
                        }, 700);
                    })(row);
                }
                
                var rows = feedInner.querySelectorAll('.wpc-bulk-completion-row--restore');
                while (rows.length > 8) {
                    rows[rows.length - 1].remove();
                    rows = feedInner.querySelectorAll('.wpc-bulk-completion-row--restore');
                }
            }
        }
    }

    
    
    function _humanBytesLocal(bytes) {
        if (!bytes || bytes < 0) return '0 B';
        var u = ['B','KB','MB','GB','TB'], i = 0;
        var v = Number(bytes);
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return (v < 10 ? v.toFixed(1) : Math.round(v)) + ' ' + u[i];
    }

    function restoreCompleted(d) {
        var surface = _restoreEl();
        if (!surface) return;
        if (surface.style.display === 'none') surface.style.display = '';

        var count = Number(d.finished) || Number(d.total) || 0;
        
        _restoreSetField('final-count', count);

        
        
        
        
        
        var bytesRestored = Number(d.bytes_restored) || 0;
        _restoreSetField('final-count-stat', count.toLocaleString());
        _restoreSetField('final-restored',   _humanBytesLocal(bytesRestored));

        
        
        

        
        var stopBtn = document.querySelector('.wps-ic-stop-bulk-restore');
        if (stopBtn) stopBtn.style.display = 'none';

        _restoreSwitchView('completed');
    }

    function restoreReset() {
        _restoreState.currentImageId = null;
        _restoreState.currentImageStartedAt = null;
        _restoreState.seenRecentIds = {};
        _restoreState.revealedSurface = false;
        var surface = _restoreEl();
        if (!surface) return;
        var listEl = surface.querySelector('[data-field="recent"]');
        if (listEl) listEl.innerHTML = '';
        var upListEl = surface.querySelector('[data-field="up_next"]');
        if (upListEl) upListEl.innerHTML = '';
        var thumbEl = surface.querySelector('[data-field="thumb"]');
        if (thumbEl) {
            thumbEl.style.backgroundImage = '';
            thumbEl.classList.remove('is-loaded');
        }
        var nameEl = surface.querySelector('[data-field="name"]');
        if (nameEl) nameEl.classList.remove('is-animating');
    }

    window.WPCBulk = Object.assign(window.WPCBulk || {}, {
        humanBytes: humanBytes,
        renderTally: renderTally,
        renderActiveTitles: renderActiveTitles,
        renderActiveThumbs: renderActiveThumbs,
        renderCompletionList: renderCompletionList,    
        renderVariantStream: renderVariantStream,
        getLastVariantMs: getLastVariantMs,
        setLastCompletedServer: setLastCompletedServer,
        resetCompletionList: resetCompletionList,
        renderFinalReveal: renderFinalReveal,
        
        renderHero: renderHero,
        resetHero: resetHero,
        
        
        compressCompleted: compressCompleted,
        
        getPrevTally: getPrevTally,
        
        restorePreparing: restorePreparing,
        restoreProcessing: restoreProcessing,
        restoreCompleted: restoreCompleted,
        restoreReset: restoreReset
    });
})(window);
