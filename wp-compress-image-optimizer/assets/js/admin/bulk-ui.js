/**
 * WP Compress v7.02 — Bulk Compress UI renderer (shared between media-library-bulk.js
 * and check-bulk-running.js). Reads the v2 heartbeat payload and updates the bulk
 * modal: rolling-tally counters, "now processing" titles, and the scrolling
 * completion list (per-image savings rows with a brief flash on insert).
 *
 * IIFE on window.WPCBulk. No bundler required. Enqueue BEFORE the two consumer
 * files so the global is defined when their poll callbacks fire.
 */
(function (window) {
    'use strict';

    var seenCompletedIds = {};
    // v7.02 — variant stream cursor + dedupe set. The scrolling feed under the
    // summary card is per-VARIANT (one row per AVIF/WebP/JPEG landing) so a
    // 10-image bulk feels like 100 rows of progress rather than 10.
    var lastVariantMs = 0;
    var seenVariantKeys = {};
    var VARIANT_STREAM_CAP = 12;  // max DOM rows kept in the feed

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

    // ────────────────────────────────────────────────────────────────────
    //  v7.03 — Smooth number count-up.
    //  Same pattern as wpcAnimateSavingsCounter in media-library-actions.js
    //  but generalised — used to tick the 4 summary stats on the top card
    //  (originals / variants / bytes saved / avg %) so numbers feel alive
    //  rather than snapping on each 1 s heartbeat.
    // ────────────────────────────────────────────────────────────────────
    var prevTally = { counter: 0, variants: 0, bytesSaved: 0, pct: 0, total: 0 };

    // v7.03 — Expose prevTally for the Stop confirmation modal (needs current
    // processed / total counts to render an accurate "X of Y kept" message).
    function getPrevTally() { return prevTally; }

    // v7.03 — Breakdown ribbon helper. Computes processing rate + ETA from
    // elapsed time since first-data-arrived, formats human-friendly bytes
    // and durations, and writes the four ribbon fields via tickNum so the
    // numbers climb smoothly between heartbeats.
    var _ribbonStartedAt = null;
    var _ribbonFirstProcessed = 0;
    // v7.04.68 — Middle ribbon slot rotates through "cool fact" labels
    // derived from live data. Each fact is just a (value, label) string
    // pair recomputed on every heartbeat; the displayed fact rotates every
    // FACT_ROTATE_MS via a separate interval so the user gets a slideshow
    // of service-benefit framings instead of one static metric.
    //
    // Truth bar: each fact must be computable from live data, no fluff.
    //   - variants:   straight server count
    //   - per-views:  bytes_saved × 10,000 (a "1K visits × 10 pages" projection)
    //   - CO₂:        bytes_saved × ~0.5 g CO₂ per MB (Sustainable Web Design;
    //                 0.81 kg/kWh grid × 0.06 kWh/GB)
    //   - %-reduced:  taken straight from server savings_pct
    var FACT_ROTATE_MS = 5000;
    var _factIndex = 0;
    var _factTimer = null;
    var _factsCache = [];

    // v7.04.68 — Live-data fact set. Each fact is real (from bytes_saved /
    // variants / savings_pct) but framed in different units so the user
    // sees a slideshow of "what this actually means for my site". No fluff.
    // v7.04.68 — Build sentence-form facts so the ribbon reads as a single
    // statement rather than X · Y · Z columns. Each entry carries its
    // own context inline.
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

        // v7.04.68 — Approximation words ("approx.", "about", "up to")
        // signal that projections are estimates, not exact figures. Honest
        // framing for facts that scale from real saved-bytes to projected
        // traffic / annual / CO₂ numbers.

        if (bs > 0) {
            // Anchor: this one IS exact — no approximation needed
            sentences.push("You've saved " + humanBytes(bs) + " of bandwidth so far");
            // Projection: monthly bandwidth scale
            sentences.push("Approx. " + humanBytes(bs * 10000) + " saved every month at 10,000 visits");
        }

        if (bsMB >= 0.15) {
            // Mobile load-time gain — estimated from 4G median
            sentences.push("Up to " + _formatSecondsHuman(bsMB / 1.5) + " faster page loads on mobile 4G");
        }

        if (bsMB > 0) {
            // Annual CO₂ at 100K visits — projection
            var kgPerYear = (bsMB * 100000 * 0.5 * 12) / 1000;
            sentences.push("About " + _formatCO2Kg(kgPerYear) + " of CO₂ avoided each year at 100,000 visits");
        }

        if (pct > 0) {
            // Real ratio from server — exact
            sentences.push("Your pages are now " + pct.toFixed(1) + "% lighter — Google's Core Web Vitals love this");
        }

        if (v > 0) {
            // Real count from server — exact
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

        // Compute ETA for the "X left" sentence (same math as the old column).
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
                    // v7.04.68 — Two-phase motion: fade-up-out the old text
                    // first, swap content while invisible, then fade-up-in
                    // the new one from below. Gives the ribbon a rolling
                    // reel feel instead of a hard text swap.
                    sentence.classList.add('is-fact-out');
                    setTimeout(function () {
                        _renderCurrentFact(r);
                        // Switch to incoming state: from-below-invisible
                        sentence.classList.remove('is-fact-out');
                        sentence.classList.add('is-fact-in');
                        // Next frame, drop the incoming class → CSS transition
                        // rolls it up into its natural position.
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
        // Use the LIVE displayed value as the starting point (tracked on
        // the element itself) so back-to-back heartbeat ticks chain smoothly
        // — without this, a new tick at heartbeat T+1s would jump the
        // display to the PREVIOUS tick's TARGET (not the actual displayed
        // value, which was mid-interpolation), causing visible jumps.
        var from = (el._wpcVal != null) ? el._wpcVal : fallbackFrom;
        if (from === to || !window.requestAnimationFrame) {
            el._wpcVal = to;
            el.textContent = formatter(to);
            return;
        }
        // Cancel any prior animation on this element via a generation token —
        // older RAF callbacks see a mismatched gen and abort, preventing
        // concurrent writes to textContent from competing animations.
        el._wpcGen = (el._wpcGen || 0) + 1;
        var myGen = el._wpcGen;
        var start = null;
        function ease(t) { return 1 - Math.pow(1 - t, 3); } // ease-out cubic
        function step(ts) {
            if (el._wpcGen !== myGen) return; // superseded
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

    // ────────────────────────────────────────────────────────────────────
    //  v7.03 — "+ AVIF Scaled" chip landing in the summary card.
    //  Mirrors wpcShowVariantBadge / wpcEnqueueBadge in
    //  media-library-actions.js so the bulk top card and the ML per-image
    //  card share the same chip vocabulary. Paced at DELTA_CHIP_SPACING
    //  so a tight burst of 8+ variants doesn't overwrite each other —
    //  each chip gets ≥0.75 s of readable on-screen time.
    // ────────────────────────────────────────────────────────────────────
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
        // v7.04.68 — Land the rising "+ FMT Size" chip INSIDE the headline
        // row, immediately to the right of "65.0% Saved". The headline is
        // `display: flex; align-items: baseline; flex-wrap: wrap` so the
        // chip docks at the end of the row naturally. Falls back to the
        // summary-status block when the hero card is hidden.
        //
        // v7.04.71 follow-up — Scope to live surface. Same root cause as
        // the renderHero selector fix at line ~593: the SKELETON's
        // .wpc-bulk-hero-headline appears first in DOM, so unscoped
        // `$sel('.wpc-bulk-hero-headline')` would always match it. Then
        // its offsetParent check returns null (skeleton parent hidden
        // post-first-data) → forced fallback to summary-status →
        // chip lands at the bottom card, never on the hero.
        // Symptom user reported: "delta chips are not coming on hero".
        // Revert: change back to `$sel('.wpc-bulk-hero-headline')`.
        var heroHeadline = $sel('.wpc-bulk-v2-surface .wpc-bulk-hero-headline');
        var host;
        if (heroHeadline && heroHeadline.offsetParent !== null) {
            host = heroHeadline;
        } else {
            host = $sel('.wpc-bulk-summary-status');
        }
        if (!host) return;
        // Remove any existing chip to avoid stacking
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

    // ────────────────────────────────────────────────────────────────────
    //  v7.03 — Feed-row scroll-up cascade
    //
    //  Replaces the old "insert all, stagger via CSS index" approach with a
    //  paced one-at-a-time cascade that mirrors the reference live-feed:
    //
    //    1. New row prepends to .wpc-bulk-feed-inner with opacity 0
    //    2. Inner is transform:translateY(-itemH) without transition
    //    3. Force reflow
    //    4. Last row (if at cap) fades to opacity 0 (0.5 s)
    //    5. Inner transitions back to translateY(0) — slides all rows up
    //    6. New row opacity → 1, fresh-{fmt} class added for color tint
    //    7. After 650 ms: remove last row, reset transform, schedule
    //       fresh class removal at 1 s
    //
    //  Paced with the chip queue (750 ms) so chip + row land in sync.
    //  Each row commit fires the matching format chip via _showDeltaChip
    //  so the user sees ONE moment per variant: chip + row + savings bar fill.
    // ────────────────────────────────────────────────────────────────────
    var FEED_VISIBLE_CAP = 8;          // max visible rows in the mask
    var FEED_ROW_SPACING_MS = 750;     // matches DELTA_CHIP_SPACING_MS
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

        // Insert at top hidden, so we can measure height + run the slide
        item.row.style.opacity = '0';
        inner.insertBefore(item.row, inner.firstChild);

        // Measure: row height + 0 gap (rows are flush in the bulk feed)
        var itemH = item.row.offsetHeight;

        // Step 1 — push everything up off-screen by itemH so the new row
        // sits where the old top row used to be (no perceived shift)
        inner.style.transition = 'none';
        inner.style.transform = 'translateY(-' + itemH + 'px)';
        // Force layout flush so the next transition actually animates
        void inner.offsetHeight;

        // Step 2 — fade out the last row if we're at or above the cap
        var willRemoveLast = inner.children.length > FEED_VISIBLE_CAP;
        var lastRow = willRemoveLast ? inner.children[inner.children.length - 1] : null;
        if (lastRow) {
            lastRow.style.transition = 'opacity 0.5s ease';
            lastRow.style.opacity = '0';
        }

        // Step 3 — slide everything back to 0 (this animates the visible
        // scroll-up) + fade the new row in at top with a tiny delay so the
        // motion reads before the opacity catches up
        inner.style.transition = 'transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)';
        inner.style.transform = 'translateY(0)';
        item.row.style.transition = 'opacity 0.5s ease 0.1s';
        item.row.style.opacity = '1';

        // Step 4 — fresh tint matching the format
        var freshClass = 'is-fresh-' + item.fmt;
        item.row.classList.add(freshClass);

        // Step 5 — set the savings bar target NOW (CSS transition fills it)
        var bar = item.row.querySelector('.wpc-bar-fill');
        if (bar && bar.dataset.targetPct) {
            requestAnimationFrame(function () {
                bar.style.width = bar.dataset.targetPct + '%';
            });
        }

        // Step 6 — fire the matching chip on the hero card so the user
        // sees ONE coherent moment (row appears + chip lands together)
        _showDeltaChip(item.fmt, item.size);

        // v7.03.3 — Stats + hero render moved EAGERLY to renderVariantStream
        // so the counter doesn't lag behind the cascade. Keep this slot for
        // future cascade-paced visual effects (letter pulse already fires
        // from _showDeltaChip above).

        // Step 7 — settle: 650 ms later, remove the faded-out tail + reset
        // the inner transform so the layout is back to baseline for the
        // next cycle
        setTimeout(function () {
            if (lastRow && lastRow.parentNode) lastRow.parentNode.removeChild(lastRow);
            inner.style.transition = 'none';
            inner.style.transform = '';

            // Remove fresh tint 1 s after row landed (after the settle)
            setTimeout(function () {
                if (item.row && item.row.classList) item.row.classList.remove(freshClass);
            }, 350);
        }, 650);
    }

    // ────────────────────────────────────────────────────────────────────
    //  v7.03 — HERO CARD (per-image ML library treatment at the top)
    //
    //  Mirrors the ML compressed card (.wpc-ml-card--compressed in
    //  assets/css/admin.media-library.less). Shows the most recently
    //  active image as a hero: thumb on the left, "X% Saved" headline
    //  + variant count chip (24 · 8J 8W 8A) on the right. Format chips
    //  land in the headline as variants arrive.
    //
    //  Per-image stats are accumulated client-side from new_variants[]
    //  across heartbeats (the heartbeat doesn't return per-image
    //  breakdowns server-side). Persisted variants only — pending
    //  announces don't count toward the per-image tally.
    // ────────────────────────────────────────────────────────────────────
    var imageStats = {};         // imageId → { thumb, title, jpeg, webp, avif, png, total, savedBytes, origBytes, lastMs }
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
        // v7.03.3 — Latched MAX of per-variant savings %. Mirrors the
        // per-image media-library card's ic_savings behavior: the headline
        // is the best variant we achieved, not a byte-weighted average. AVIF
        // Original typically wins (~85-90%); JPEG thumbnails win small. The
        // byte-weighted average let the headline drop when low-savings
        // variants landed after high ones, which read as "compression got
        // worse" to the user. Max-climb fixes that.
        var vPct = Number(v.pct) || 0;
        if (vPct > s.maxPct) s.maxPct = vPct;
    }

    function _pickHeroImageId(newVariants) {
        // v7.04.23 — Hero = currently-active image per server. Old logic
        // picked the image of the newest variant in new_variants stream;
        // sequential bulk could be on image 5 but a late AVIF of image 4
        // would arrive in new_variants → hero flipped back to image 4 →
        // user sees "wrong image" displayed. Server's active array is
        // the authoritative "what's being processed right now" signal.
        if (Array.isArray(_lastActiveServer) && _lastActiveServer.length > 0) {
            // v7.04.50 — Pick the active entry with the MOST variants (= the
            // image currently being processed). Pre-fix used the LAST entry
            // in array order, which for sequential bulks (process order 18 →
            // 17 → 16 → 15 → 12) meant hero locked to image 12 — the LAST
            // to be processed — for ~80% of the run.
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
            // v7.04.55 — If the best active entry has NON-ZERO variants, use
            // it. If ALL active are at 0 (image just dispatched, Phase A
            // still in flight), prefer the previously-completed image so the
            // hero displays its real data instead of flashing 0J 0W 0A 0%
            // during the dispatch gap. The transition reads as a natural
            // "previous image stays on screen" until the new one has data.
            if (heroEntry && heroEntry.id && heroSum > 0) return heroEntry.id;
        }
        // v7.04.40 — Active empty → prefer newest completed entry (server
        // already sorted newest-first at array_reverse). Completed entries
        // carry chip counts since v7.04.38, so the hero renders truthful
        // 8J/8W/8A even after the bulk advances past this image. Falling
        // through to the delta-stream "newest variant" path under-counts
        // because the accumulator only sees variants that arrived in the
        // current poll, not the full historical set.
        if (Array.isArray(_lastCompletedServer) && _lastCompletedServer.length > 0) {
            var newestCompleted = _lastCompletedServer[0]; // newest-first
            if (newestCompleted && newestCompleted.id) return newestCompleted.id;
        }
        // Last-resort: newest variant in the delta stream.
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

    // Pulse a per-format letter in the variant chip when its count climbs.
    function _pulseFmtLetter(field) {
        var el = $sel('[data-field="' + field + '"]');
        if (!el) return;
        el.classList.remove('wpc-vc-bump');
        // Force reflow so the class re-add re-triggers the animation
        void el.offsetWidth;
        el.classList.add('wpc-vc-bump');
        setTimeout(function () { if (el && el.classList) el.classList.remove('wpc-vc-bump'); }, 700);
    }

    function renderHero(newVariants) {
        var newHeroId = _pickHeroImageId(newVariants);
        if (newHeroId == null) return;

        // v7.04.71 — Scope to the LIVE surface. The skeleton wrap
        // (`.bulk-preparing-optimize`) contains a `.wpc-bulk-hero
        // .wpc-prep-skel-card` placeholder which appears earlier in DOM.
        // `document.querySelector('.wpc-bulk-hero')` would match THAT
        // skeleton first — and since the skeleton hero has inline
        // `style="display:flex"` (not 'none'), the reveal branch at
        // line ~595 would never fire. Net effect: live hero stays
        // permanently `display: none` even after first-heartbeat data
        // arrives. Symptom: "now processing" + feed table render but
        // the hero card (big % saved + delta chip + filename) is
        // missing above the summary card. Scoping the selector to
        // `.wpc-bulk-v2-surface` skips the skeleton match. Revert:
        // change selector back to '.wpc-bulk-hero'.
        var hero = $sel('.wpc-bulk-v2-surface .wpc-bulk-hero');
        if (!hero) return;

        // First reveal — slide in with spring entrance
        if (hero.style.display === 'none') {
            hero.style.display = '';
            hero.classList.add('wpc-bulk-hero-enter');
            setTimeout(function () { hero.classList.remove('wpc-bulk-hero-enter'); }, 800);
        }

        // v7.04.13 — Prefer server-side active counts over the delta-stream
        // accumulator. The accumulator can miss variants if since_ms cursor
        // races merges or batch variants share bg_upgraded_ms — caused the
        // bulk hero to stick at "1 · 0J 1W 0A" even when ic_local_variants
        // had all 24. Active entries now carry authoritative {count, jpeg,
        // webp, avif, savings_pct} read directly from postmeta.
        var serverStats = null;
        if (Array.isArray(_lastActiveServer)) {
            for (var ai = 0; ai < _lastActiveServer.length; ai++) {
                if (_lastActiveServer[ai] && _lastActiveServer[ai].id === newHeroId) {
                    serverStats = _lastActiveServer[ai];
                    break;
                }
            }
        }
        // v7.04.49 — Unconditional diagnostic. Logs every renderHero call
        // so we can see if it's firing at all + what IDs it's seeing.
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
        // v7.04.40 — When hero is in completed bucket (e.g., last image
        // early-advanced or whole bulk done), pull server's chip counts
        // from completed[] which carries the same {count, jpeg, webp,
        // avif} shape since v7.04.38. Without this, hero falls back to
        // the JS imageStats accumulator which under-counts (delta stream
        // can miss entries) → user saw "13 · 2J 3W 8A" instead of the
        // truthful "24 · 8J 8W 8A" the DB actually held.
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
            // Ensure entry exists so subsequent pulse logic works.
            if (!stats) {
                stats = imageStats[newHeroId] = {
                    thumb: serverStats.thumb || '', title: serverStats.title || '',
                    jpeg: 0, webp: 0, avif: 0, png: 0,
                    total: 0, savedBytes: 0, origBytes: 0, lastMs: 0, maxPct: 0
                };
            }
            // v7.04.52 — Server (ML's wpc_compute_heartbeat_payload) is the
            // sole source of truth. Don't climb-only — that traps the chip
            // at the accumulator's max even when server reports lower (e.g.,
            // re-bulk where ic_local_variants was repopulated). Overwrite
            // unconditionally so the chip always matches what ML would
            // display for this image at this moment.
            stats.jpeg  = Number(serverStats.jpeg)  || 0;
            stats.webp  = Number(serverStats.webp)  || 0;
            stats.avif  = Number(serverStats.avif)  || 0;
            stats.total = Number(serverStats.count) || 0;
            var srvPct = Number(serverStats.savings_pct) || 0;
            if (srvPct > stats.maxPct) stats.maxPct = srvPct;
            // Diagnostic: gated by localStorage flag.
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
        // v7.03.3 — MAX-of-variants, climb-only. See _bumpImageStats note.
        var pct = stats.maxPct;

        var thumbEl = hero.querySelector('[data-field="hero-thumb"]');
        var nameEl  = hero.querySelector('[data-field="hero-filename"]');
        var pctEl   = hero.querySelector('[data-field="hero-pct"]');

        // Hero image changed — crossfade thumb + slide filename + flash card
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

            // Reset count-up from 0 so the new image's % counts UP, not from
            // the previous image's value. Force _wpcVal reset on the pct
            // element so tickNum starts fresh.
            heroImageId = newHeroId;
            prevHeroPct = 0;
            prevHeroPerFmt = { jpeg: 0, webp: 0, avif: 0, png: 0 };
            if (pctEl) pctEl._wpcVal = 0;
        }

        // Smooth headline count-up — pulls live `_wpcVal` from the element
        // for fluid chaining across heartbeats.
        if (pctEl) {
            _tickNum(pctEl, prevHeroPct, pct, function (v) {
                return v.toFixed(1) + '%';
            });
        }
        prevHeroPct = pct;

        // Variant count chip + per-format letter pulse on increment
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

    /**
     * Render the cumulative tally: counter (X / Y), progress bar, savings.
     * Writes into BOTH the new wpc-bulk-* selectors AND the legacy stat boxes
     * that already exist in templates/admin/bulk.php so we don't lose info
     * if a customer's theme hides one or the other.
     */
    function renderTally(d) {
        if (!d) return;

        var total = Number(d.total) || 0;
        var processed = Number(d.processed) || 0;
        var variants = Number(d.variants_total) || 0;
        var pct = total > 0 ? (100 * processed / total) : 0;

        // Pre-stage stats so when we DO reveal the v2 surface the numbers
        // are already correct — no "0 / X" flash.
        // v7.03 — Count-up animation per stat (matches ML card UX): each
        // value eases from its previous tick to the new value over 700 ms
        // so the user sees the numbers "live". Bytes use humanBytes for
        // unit transitions; pct uses 1-decimal float; counter uses
        // "X / TOTAL" format.
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

        // v7.03 — Gate the preparing → running transition on first data.
        // First variants take 5–10 s to encode + announce; if we reveal
        // the v2 surface on the first heartbeat (zero data), the user sees
        // "0 / 124 · 0 variants · 0 B saved" for that whole window — feels
        // broken. Instead we keep the preparing skeleton up until at least
        // one variant has landed, then transition with real data in view.
        //
        // v7.03 fix (2026-05-25) — Tightened gate. Was `processed >= 1 ||
        // variants >= 1`. Problem: `variants_total` can be non-zero on the
        // FIRST heartbeat if the server's session transient survived a prior
        // run (we've seen this end-to-end). That tripped firstDataArrived
        // immediately, hid the prep, but no per-image data has actually
        // committed yet — so the surface revealed with empty content,
        // reading as a "blank middle page" for ~3-4 s until real Phase A
        // variants caught up. Now we require BOTH:
        //   - processed >= 1 OR there's at least one active in-flight image
        //     OR there's been a fresh new_variant landing in this poll
        // …which only fires when work is genuinely flowing in THIS session.
        // v7.03.2 (2026-05-25 #4) — Gate removed. We tried "wait for first
        // variant" three times and every gate variation produced a blank-
        // screen failure mode (stale variants_total tripping it; hasActive
        // tripping it on Phase A dispatch; opacity fades stacking on the
        // reveal; the prep-hide / surface-show order racing). Pragmatic
        // simplification: ALWAYS render the v2 surface, ALWAYS hide the
        // prep skeleton — the surface's own empty-state copy ("Encoding
        // variants — first results in ~5 seconds…") IS the loading UI.
        // One state, no transition, no blank possible.

        // v7.03 — Update breakdown ribbon. Computes processing rate +
        // ETA from elapsed time since first-data-arrived. Saved bytes
        // uses tickNum so it climbs smoothly with the other stats.
        _updateBreakdownRibbon(processed, total, bytesSaved, variants, pctVal);

        // v7.04.68 — Keep the skeleton visible until the FIRST VARIANT actually
        // lands (not just on first heartbeat with total>0). Pre-fix: the skeleton
        // hid as soon as renderTally ran with any data — even at 0/5 variants
        // (~5s of "empty" processing card while encoder warmed up). Now: only
        // swap when variants_total > 0 OR a new_variants payload is non-empty.
        // Until that moment, the skeleton's structure-mirroring shimmer keeps
        // the page lively + the user sees what the populated card will look like.
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

    /**
     * Render the "Now processing:" line. Server returns up to 3 active titles.
     * When active is empty BUT bulk is still running (queue has items in flight),
     * show a friendly "Loading next batch…" so the UI doesn't look dead between
     * server-side drain iterations.
     *
     * v7.03 — Persist last-seen active titles across the ~8s drain gap so the
     * user doesn't see "Loading next batch…" flash every 8 s mid-bulk. The
     * gap is a server architecture artifact (loopback chain wall budget);
     * showing the previous batch's titles is more honest than "loading" when
     * variants are still landing for that batch.
     */
    // v7.04.13 — Last-seen `active` array from server heartbeat, with full
    // per-format counts attached. renderHero reads this as authoritative truth
    // instead of relying on the delta-stream accumulator (which can miss
    // entries when batch variants share bg_upgraded_ms).
    var _lastActiveServer = null;
    // v7.04.40 — Cached completed[] entries (with chip counts as of
    // v7.04.38). Used by renderHero when the hero image is in the
    // completed bucket (no longer in _lastActiveServer).
    var _lastCompletedServer = null;
    function setLastCompletedServer(completed) {
        if (Array.isArray(completed)) _lastCompletedServer = completed;
    }
    var _lastActiveTitlesText = null;
    function renderActiveTitles(active, hint, upNext) {
        // Capture the latest active array (with server counts) for renderHero.
        if (Array.isArray(active)) _lastActiveServer = active;
        var text;
        if (active && active.length) {
            // v7.04.68 — Show ONLY the first active name + "+N more". Two-name
            // version still overran on long filenames; single-name + counter
            // is the cleanest signal of "this is being processed now".
            var first = active[0];
            text = first.title || ('Image #' + first.id);
            var rest = active.length - 1;
            if (rest > 0) text += '  +' + rest + ' more';
            _lastActiveTitlesText = text;
        } else if (hint === 'finalizing') {
            text = 'Finalizing variants…';
        } else if (hint === 'loading' && Array.isArray(upNext) && upNext.length) {
            // Same single-name cap for the up-next preview.
            var firstU = upNext[0];
            text = 'Up next: ' + (firstU.title || ('Image #' + firstU.id));
            var restN = upNext.length - 1;
            if (restN > 0) text += '  +' + restN + ' more';
        } else if (hint === 'loading' && _lastActiveTitlesText) {
            // Carryover fallback — keep the last batch's titles visible.
            text = _lastActiveTitlesText;
        } else if (hint === 'loading') {
            // True initial state — never had active titles yet.
            text = 'Loading next batch…';
        } else {
            text = _lastActiveTitlesText || '—';
        }
        setText('.wpc-bulk-active-titles', text);
    }

    /**
     * Render the active "Now Processing" thumbnails. Up to 3 stacked tiles
     * showing the parent thumb so the user sees what's being worked on.
     * When active is empty but bulk is still running, render a single pulsing
     * skeleton tile so the area never looks dead.
     */
    // v7.03 — Persist last-seen active + render up-next cascade during drain gaps.
    //
    // Precedence:
    //   1. active.length > 0     → render active thumbs (current pattern)
    //   2. up_next.length > 0    → render queue preview with cascading opacity
    //                               (0.9 / 0.6 / 0.35) — "what's coming next"
    //   3. last-seen active      → fallback (carries over from previous batch)
    //   4. pulsing skeleton      → true initial state, never had active yet
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

        // Cheap diff: build a key set, only rebuild if it changed
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
                    // Cascading: 0.92, 0.62, 0.35 — clearly readable as a queue
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
            // Pulsing skeleton placeholder — only used when we have NEITHER
            // active NOR upcoming queue thumbs (true pre-first-batch state).
            var skel = document.createElement('div');
            skel.className = 'wpc-bulk-active-thumb is-skeleton';
            frag.appendChild(skel);
        }
        holder.innerHTML = '';
        holder.appendChild(frag);
    }

    // Also update renderActiveTitles to show "Up next: A, B, C" with the
    // upcoming filenames when active is empty during the drain gap.
    function _injectUpNextIntoTitles(upNext, currentText) {
        // currentText comes from renderActiveTitles below; keep as-is unless
        // we're in the explicit up-next state.
        if (!Array.isArray(upNext) || !upNext.length) return currentText;
        var names = upNext.slice(0, 3).map(function (u) { return u.title || ('Image #' + u.id); });
        return 'Up next: ' + names.join(', ');
    }

    /**
     * Update the variant cursor from a heartbeat response. Callers use this
     * to know what since_ms to send on the next poll.
     */
    function getLastVariantMs() { return lastVariantMs; }

    /**
     * Render the per-VARIANT live feed. `new_variants` is newest-first from
     * the server (sorted by bg_upgraded_ms desc). We dedupe by `id-key`,
     * prepend rows with a thumb + format pill + savings, and cap the DOM
     * to VARIANT_STREAM_CAP rows so the feed stays bounded under load.
     */
    function _variantPillClass(fmt) {
        return (
            fmt === 'avif' ? 'is-avif' :
            fmt === 'webp' ? 'is-webp' :
            (fmt === 'jpg' || fmt === 'jpeg') ? 'is-jpeg' :
            fmt === 'png' ? 'is-png' :
            'is-other'
        );
    }

    /**
     * v7.02 Lite — render per-variant feed. Handles three transitions:
     *   1. New pending pill (announce arrived first, no DOM row yet) → insert
     *      with .is-pending-announce class + pulse animation
     *   2. New persisted variant (bytes batch arrived first OR was the only
     *      signal we got — no preceding announce) → insert as normal row
     *   3. Persisted upgrade (pending row already in DOM, batch just landed) →
     *      update existing row in place: remove pending classes, refresh
     *      savings numbers, brief confirm-flash. Same DOM row, no jank.
     *
     * Server marks pending variants with `pending: true` (and optionally
     * `noImprovement: true` if encoder declared source_already_optimal).
     * seenVariantKeys[key] now stores either 'pending' or 'persisted' so we
     * know whether the next arrival is a new variant or an upgrade.
     */
    // v7.03 — ML-modal-matching badge classes. Keep the source-of-truth in
    // assets/css/admin.media-library.less:1497 — these strings just have to
    // match the .wpc-fmt-* class hooks defined there.
    function _variantBadgeClass(fmt) {
        if (fmt === 'avif') return 'wpc-fmt-avif';
        if (fmt === 'webp') return 'wpc-fmt-webp';
        if (fmt === 'jpg' || fmt === 'jpeg') return 'wpc-fmt-jpeg';
        if (fmt === 'png') return 'wpc-fmt-png';
        return 'wpc-fmt-jpeg';
    }

    function renderVariantStream(newVariants) {
        if (!Array.isArray(newVariants) || !newVariants.length) return;
        // v7.03 — Target the new inner wrapper. Mask wrapper is .wpc-bulk-
        // completion-list; transform target is .wpc-bulk-feed-inner.
        var inner = $sel('.wpc-bulk-feed-inner');
        if (!inner) return;
        var list = inner; // alias for upgrade-path queries below

        // Pre-sort oldest-first so consecutive enqueues end up newest-on-top
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
            var seenState = seenVariantKeys[key]; // undefined | 'pending' | 'persisted'

            var ms = Number(v.ms) || 0;
            if (ms > lastVariantMs) lastVariantMs = ms;

            // Upgrade path: pending → persisted. Update existing row in place.
            if (!isPending && seenState === 'pending') {
                seenVariantKeys[key] = 'persisted';
                _bumpImageStats(v);  // v7.03 — count the upgrade toward per-image hero stats
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

            // Skip dupes — already seen at the same or higher state
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

            // v7.03 — Three savings display modes:
            //   • Pending no-improvement (encoder said source_already_optimal
            //     during announce, before bytes batch landed) → "Source kept"
            //   • Persisted with <1% AND <1 KB saved (encoder finished the
            //     pass but couldn't improve on source — common for already-
            //     compressed Unsplash-style photos) → "Optimal" badge.
            //     Showing "0%" + empty bar here misleads users into thinking
            //     compression failed; the badge communicates "we tried, your
            //     image was already as small as we can make it."
            //   • Real savings → percentage + animated bar.
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
                // Bar fill starts at 0; we set the target width AFTER insert
                // (next animation frame) so the CSS width transition actually
                // runs. The transition-delay in _bulk.less staggers per row.
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

            // v7.03 — Enqueue row+chip+hero-bump cascade. _commitFeedRow now
            // bumps stats + updates hero per row commit (synced with cascade
            // pace, NOT per-heartbeat) so the hero variant chip climbs in
            // sync with the rows landing.
            //
            // Skip the cascade for pending entries (announce-only) — they're
            // not real bytes yet; the upgrade branch above handles them.
            if (!isPending) {
                // v7.03.3 — Bump imageStats + render hero EAGERLY here, not
                // in _commitFeedRow. The cascade renders rows at 750ms each;
                // a burst of 24 variants arriving in one heartbeat would
                // otherwise leave the counter lagging by ~18s. User saw
                // "1J 2W 8A" while the actual ic_local_variants had 24
                // entries — pure UI undercount. Letter-pulse + format chip
                // still fire from _commitFeedRow (cascade-paced visuals).
                _bumpImageStats(v);
                renderHero([v]);
                _enqueueFeedRow(row, fmt, v.size_label, v);
                addedAny = true;
            } else {
                // Pending announce: insert directly (skip cascade) so the
                // user sees the pulse/opacity while waiting for bytes.
                row.style.opacity = '0.7';
                list.insertBefore(row, list.firstChild);
                // Cap pending DOM size
                while (list.children.length > VARIANT_STREAM_CAP) {
                    list.removeChild(list.lastChild);
                }
            }
        }

        // v7.03 — Hero is now updated per-cascade-commit inside
        // _commitFeedRow, NOT here. Per-heartbeat renderHero would jump the
        // hero count chip ahead of the cascade by several seconds during
        // bulk-heavy moments. Cascade pace = single source of truth.
    }

    /**
     * Wipe the seen-set + DOM list so a fresh bulk run starts clean.
     */
    function resetCompletionList() {
        seenCompletedIds = {};
        seenVariantKeys = {};
        lastVariantMs = 0;
        // v7.03 — Clear the inner (transform target), not the mask wrapper.
        // Also drain any in-flight queued cascade items so a fresh bulk
        // doesn't see ghost rows from the previous run.
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
        // Reset hero card stats so a fresh bulk run starts clean.
        resetHero();
        // v7.04.71 — Scope to live surface (same reason as renderHero — see
        // comment there). The skeleton's wpc-bulk-hero wins document-order
        // wise so unscoped selector hides the WRONG hero on reset.
        var hero = $sel('.wpc-bulk-v2-surface .wpc-bulk-hero');
        if (hero) hero.style.display = 'none';

        // v7.03 — Reset breakdown ribbon (clock + first-processed baseline)
        // so rate/ETA recompute from scratch for the next bulk.
        _resetBreakdownRibbon();
    }

    // Kept for back-compat with older poller code: now a thin pass-through to
    // the variant stream (server still sends `completed[]` but the visible
    // feed is per-variant). Tally count is still driven by `processed` field.
    function renderCompletionList(/* completed */) { /* no-op */ }

    /**
     * Fire the final reveal. Adds a class consumers can hook for animation;
     * the existing post-bulk getBulkStats AJAX in media-library-bulk.js renders
     * the summary card. v7.03 — Now ALSO switches the v2 surface to the
     * completed view via compressCompleted(); the legacy `.bulk-finished`
     * injection is bypassed because we render the celebration inline.
     */
    function renderFinalReveal(d) {
        var modal = $sel('.bulk-area-inner');
        if (modal && modal.classList) modal.classList.add('wpc-bulk-done');
        compressCompleted(d);
    }

    /**
     * v7.03 — Compress completion view. Replaces the legacy `.bulk-finished`
     * HTML injection with an inline data-view switch + final-stats fill.
     * Mirrors the restore 3-view completion pattern (confetti burst + drawn-
     * check + final stats + primary CTA). Hides the top-right Return button
     * (the completed view has its own CTA — single-action focus).
     */
    function compressCompleted(d) {
        d = d || {};
        var surface = $sel('.wpc-bulk-v2-surface');
        if (!surface) return;
        if (surface.style.display === 'none') surface.style.display = '';

        // Switch data-view: processing → completed
        var views = surface.querySelectorAll('.wpc-bulk-view');
        for (var i = 0; i < views.length; i++) {
            var v = views[i];
            if (v.getAttribute('data-view') === 'completed') v.classList.add('is-active');
            else v.classList.remove('is-active');
        }

        // Fill final stats. Heartbeat payload carries everything we need —
        // no second AJAX needed (the legacy getBulkStats round-trip is dead).
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

        // v7.04.68 — Keep the top-right Return-to-Dashboard visible. The
        // in-card CTA is now hidden via LESS (display: none on
        // .wpc-bulk-complete-cta) so we don't have two duplicate buttons.

        // Hide the Stop button (bulk is done — no stopping anything).
        var stopBtn = document.querySelector('.wps-ic-stop-bulk-compress');
        if (stopBtn) stopBtn.style.display = 'none';
    }

    // ═════════════════════════════════════════════════════════════════════
    //  WPCRestore — v7.02 world-class restore UX
    //  Field-level updates on a persistent card. Three views (preparing /
    //  processing / completed). Drives crossfade thumb + slide filename +
    //  ETA + recent strip + drawn-check on completion.
    //
    //  Markup is rendered server-side in templates/admin/bulk.php under
    //  .wpc-restore-surface. JS just toggles view + updates [data-field]
    //  attributes — CSS transitions do the rest.
    // ═════════════════════════════════════════════════════════════════════
    var _restoreState = {
        currentImageId: null,         // tracks the active image's id for crossfade detection
        currentImageStartedAt: null,  // wall-clock ms when current image started (for live elapsed clock)
        seenRecentIds: {},            // dedupes the recent-restored strip
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

    // v7.02 — Tight ETA. "1:30" / "45s" / "1h12m". The "left" label is
    // rendered by the markup (.wpc-restore-eta-label); this function returns
    // just the value digits so the layout stays compact.
    function _restoreFormatEta(seconds) {
        if (seconds == null || seconds <= 0) return '—';
        seconds = Math.max(0, Math.round(seconds));
        if (seconds < 60) return seconds + 's';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        if (m < 60) {
            // mm:ss with zero-padded seconds, e.g. "1:30", "12:05"
            return m + ':' + (s < 10 ? '0' + s : s);
        }
        var h = Math.floor(m / 60);
        var mm = m % 60;
        return h + 'h' + (mm > 0 ? mm + 'm' : '');
    }

    // v7.02 — Format a duration as compact "8.4s" / "1m 20s" / "2m". For the
    // "avg X/image" sub-line + the "file elapsed" sub-row.
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

        // v7.03 — Gate the preparing → processing view switch on first
        // real data. Until at least one image has been restored OR the
        // drain has picked up a current image, stay in the "Preparing
        // images…" view so the user doesn't see "0 of N" + a blank thumb
        // crossfading during the first ~2 s of nothing happening.
        var count = Number(d.finished) || 0;
        var hasCurrent = d.current && d.current.id;
        if (count < 1 && !hasCurrent) return;

        _restoreSwitchView('processing');

        // ── Counter + percentage (v7.03 — count-up tweens) ────────────
        // Replaces snap textContent writes with the shared _tickNum helper
        // so the restore stats feel as alive as the compress hero stats.
        var total = Number(d.total) || 0;
        var pct   = Number(d.progress) || 0;
        var bytesRestored = Number(d.bytes_restored) || 0;
        var etaSeconds    = Number(d.eta_seconds) || 0;

        var countEl  = surface.querySelector('[data-field="count"]');
        var totalEl  = surface.querySelector('[data-field="total"]');
        var pctTxtEl = surface.querySelector('[data-field="pct"]');
        if (countEl)  _tickNum(countEl,  count, count, function (v) { return Math.floor(v).toLocaleString(); });
        if (totalEl)  _restoreSetField('total', total); // total doesn't change, snap is fine
        if (pctTxtEl) _tickNum(pctTxtEl, pct, pct, function (v) { return Math.round(v) + '%'; });

        // ── Progress bar fill + label rider ───────────────────────────
        var bar    = surface.querySelector('[data-field="bar"]');
        if (bar) bar.style.width = pct + '%';
        if (pctTxtEl) pctTxtEl.style.left = pct + '%';

        // ── ETA + avg/image (snap for now — ETA jumps non-monotonically) ─
        _restoreSetField('eta', _restoreFormatEta(etaSeconds));
        var avg = d.avg_seconds_per_image;
        _restoreSetField('avg', avg != null ? 'avg ' + _restoreFormatDuration(avg) + '/image' : '');

        // ── Bytes restored — count-up tween via _tickNum so the disk-
        // reclaimed number visibly climbs instead of jumping
        var bytesEl = surface.querySelector('[data-field="bytes_restored"]');
        if (bytesEl) {
            _tickNum(bytesEl, bytesRestored, bytesRestored, function (v) {
                var human = _humanBytesLocal(v);
                return human + ' restored';
            });
        }

        // ── Current image — only animate if the id actually changed ──
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

        // ── File-elapsed clock — ticks every poll so the sub-row stays live
        var liveElapsed = _restoreState.currentImageStartedAt
            ? Math.max(0, (Date.now() - _restoreState.currentImageStartedAt) / 1000)
            : fileElapsed;
        _restoreSetField('file_elapsed', liveElapsed > 0
            ? _restoreFormatDuration(liveElapsed)
            : '—');

        // ── Recently-restored feed — table-row design matching compress ─
        // v7.04.68 — Was chip-bubble "Just restored / Up next" pair. Now the
        // same table-row layout as compress's "Recently Optimized" feed so
        // the two bulk flows feel visually consistent. Each row: thumb +
        // filename, source chip (Local/Cloud/Auto), restored bytes, status.
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
                // Cap to 8 visible — keeps the panel a stable height.
                var rows = feedInner.querySelectorAll('.wpc-bulk-completion-row--restore');
                while (rows.length > 8) {
                    rows[rows.length - 1].remove();
                    rows = feedInner.querySelectorAll('.wpc-bulk-completion-row--restore');
                }
            }
        }
    }

    // Local byte-formatter for restore (independent of compress's humanBytes
    // to keep this scoped to restore-only code paths).
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
        // Subtitle's "successfully restored all <N> images" copy.
        _restoreSetField('final-count', count);

        // v7.04.68 — Two-stat layout: Originals (count) + Total Bytes (size).
        // Was 3-stat (Reclaimed/Time/Avg). Dropped time-focused stats and
        // changed "Reclaimed" → "Total Bytes" since restore adds bytes back
        // (originals are heavier than compressed variants) — "reclaimed"
        // framing was misleading.
        var bytesRestored = Number(d.bytes_restored) || 0;
        _restoreSetField('final-count-stat', count.toLocaleString());
        _restoreSetField('final-restored',   _humanBytesLocal(bytesRestored));

        // v7.04.68 — Keep the top-right Return-to-Dashboard visible. The
        // in-card CTA is now hidden via LESS (display: none on
        // .wpc-restore-complete-cta) so we don't have two duplicates.

        // Hide the Stop button — restore is done.
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
        renderCompletionList: renderCompletionList,    // no-op (kept for back-compat)
        renderVariantStream: renderVariantStream,
        getLastVariantMs: getLastVariantMs,
        setLastCompletedServer: setLastCompletedServer,
        resetCompletionList: resetCompletionList,
        renderFinalReveal: renderFinalReveal,
        // v7.03 — Hero card (per-image ML treatment at the top)
        renderHero: renderHero,
        resetHero: resetHero,
        // v7.03 — Compress completion view (data-view machine, replaces
        // the legacy .bulk-finished HTML injection)
        compressCompleted: compressCompleted,
        // v7.03 — Stats accessor for the Stop confirmation modal
        getPrevTally: getPrevTally,
        // Restore module (3-view state machine with field-level updates)
        restorePreparing: restorePreparing,
        restoreProcessing: restoreProcessing,
        restoreCompleted: restoreCompleted,
        restoreReset: restoreReset
    });
})(window);
