(function ($) {
    'use strict';

    var SHOWN = 24;
    var CASCADE_MS = 650;
    var CASCADE_GAP_MS = 100;
    var CASCADE_MAX = 5;
    var CASCADE_EASE = 'cubic-bezier(0.16, 1, 0.3, 1)';

    function reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function fmt(n) {
        try { return Number(n).toLocaleString(); } catch (e) { return String(n); }
    }

    function tween($el, to, suffix) {
        var from = parseInt($el.attr('data-count-to'), 10) || 0;
        $el.attr('data-count-to', to);
        if (from === to || reduced() || !window.requestAnimationFrame || document.hidden) {
            $el.text(fmt(to) + suffix);
            return;
        }
        var start = null;
        var duration = 700;
        var done = false;
        function finish() { done = true; $el.text(fmt(to) + suffix); }
        function step(ts) {
            if (done) { return; }
            if (document.hidden) { finish(); return; }
            if (start === null) { start = ts; }
            var p = Math.min(1, (ts - start) / duration);
            if (p < 1) {
                $el.text(fmt(Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)))) + suffix);
                window.requestAnimationFrame(step);
            } else {
                finish();
            }
        }
        window.requestAnimationFrame(step);
        setTimeout(finish, duration + 300);
    }

    function countUp($page) {
        var seen = false;
        try { seen = window.sessionStorage.getItem('wpc_sd_counted') === '1'; } catch (e) {}
        if (seen || reduced() || !window.requestAnimationFrame || document.hidden) { return; }
        try { window.sessionStorage.setItem('wpc_sd_counted', '1'); } catch (e) {}
        $page.find('.wpc-sd-num[data-count-to]').each(function () {
            var $el = $(this);
            var target = parseInt($el.attr('data-count-to'), 10) || 0;
            var suffix = $el.attr('data-suffix') || '';
            if (target < 1) { return; }
            $el.attr('data-count-to', 0).text(fmt(0) + suffix);
            setTimeout(function () { tween($el, target, suffix); }, 250);
        });
    }

    function ago(seconds) {
        var d = Math.max(0, seconds);
        if (d < 45) { return 'just now'; }
        if (d < 3600) { return Math.max(1, Math.round(d / 60)) + ' min ago'; }
        if (d < 86400) { return Math.round(d / 3600) + ' h ago'; }
        return Math.round(d / 86400) + ' d ago';
    }

    var HERO_FALLBACK = '<span class="wpc-sd-hero-fallback" aria-hidden="true"><svg class="wpc-sd-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></span>';

    function heroBroken(img) {
        var $thumb = $(img).closest('.wpc-sd-hero-thumb');
        if (!$thumb.length || $thumb.hasClass('is-broken')) { return; }
        $thumb.addClass('is-broken');
        if (!$thumb.children('.wpc-sd-hero-fallback').length) { $thumb.append(HERO_FALLBACK); }
    }

    function heroCheck($scope) {
        $scope.find('.wpc-sd-hero-thumb img').each(function () {
            if (this.complete && this.naturalWidth === 0 && this.getAttribute('src')) { heroBroken(this); }
        });
    }

    function skeletonHero() {
        return $('<div class="wpc-sd-hero wpc-sd-hero--skel" aria-hidden="true"><div class="wpc-sd-hero-thumb"><span class="wpc-sd-skel-fill"></span></div><div class="wpc-sd-hero-copy"><div class="wpc-sd-big"><span class="wpc-sd-skel-bar wpc-sd-skel-headline"></span><span class="wpc-sd-skel-bar wpc-sd-skel-sub"></span></div><div class="wpc-sd-chips"><span class="wpc-sd-skel-bar wpc-sd-skel-chip"></span><span class="wpc-sd-skel-bar wpc-sd-skel-chip"></span><span class="wpc-sd-skel-bar wpc-sd-skel-chip wpc-sd-skel-chip--wide"></span></div></div></div>');
    }

    function normalize($list, today, visible, expanded) {
        $list.children('.wpc-sd-day').remove();
        var $items = $list.children('.wpc-sd-row, .wpc-sd-skel-row');
        $items.slice(SHOWN).remove();
        var prevDay = null;
        var at = 0;
        $list.children('.wpc-sd-row, .wpc-sd-skel-row').each(function () {
            var $r = $(this);
            var more = !expanded && at >= visible;
            at++;
            $r.toggleClass('is-more', more);
            if (!$r.hasClass('wpc-sd-row')) { return; }
            var day = $r.attr('data-day') || '';
            if (prevDay === null || day !== prevDay) {
                if (prevDay !== null || day !== today) {
                    $('<div class="wpc-sd-day"></div>').attr('data-day', day).toggleClass('is-more', more).text($r.attr('data-dl') || '').insertBefore($r);
                }
                prevDay = day;
            }
        });
        return at;
    }

    function initPage($page) {
        var clockSkew = (parseInt($page.attr('data-now'), 10) || 0) - Math.floor(new Date().getTime() / 1000);
        var newest = parseInt($page.attr('data-newest'), 10) || 0;
        var $list = $page.find('#wpc-sd-rows');
        var $slot = $page.find('.wpc-sd-hero-slot');
        var today = $page.attr('data-today') || '';
        var visible = parseInt($page.attr('data-visible'), 10) || 12;
        var expanded = false;
        var $more = $page.find('[data-wpc-more]');
        var $foot = $page.find('.wpc-sd-table-foot');

        function layout() {
            var n = normalize($list, today, visible, expanded);
            $more.prop('hidden', expanded || n <= visible);
        }

        $more.on('click', function () {
            expanded = true;
            layout();
        });

        countUp($page);

        function markLoaded(img) {
            $(img).addClass('is-loaded').closest('.wpc-sd-thumb').addClass('is-loaded');
        }

        $page[0].addEventListener('load', function (e) {
            if (e.target && e.target.tagName === 'IMG' && $(e.target).closest('.wpc-sd-thumb').length) { markLoaded(e.target); }
        }, true);
        $page[0].addEventListener('error', function (e) {
            if (e.target && e.target.tagName === 'IMG' && $(e.target).closest('.wpc-sd-thumb').length) { $(e.target).closest('.wpc-sd-thumb').addClass('is-loaded'); }
            if (e.target && e.target.tagName === 'IMG') { heroBroken(e.target); }
        }, true);
        heroCheck($page);
        $page.find('.wpc-sd-thumb img').each(function () {
            if (this.complete && this.naturalWidth > 0) { markLoaded(this); }
        });

        function refreshAgo() {
            var now = Math.floor(new Date().getTime() / 1000) + clockSkew;
            $page.find('.wpc-sd-row').each(function () {
                var t = parseInt($(this).attr('data-t'), 10) || 0;
                if (t) { $(this).find('[data-wpc-ago]').text(ago(now - t)); }
            });
        }
        setInterval(refreshAgo, 30000);

        function setTile(key, value, suffix) {
            var $v = $page.find('.wpc-stat-value[data-stat="' + key + '"]');
            if (!$v.length) { return; }
            var $tile = $v.closest('.wpc-sd-tile--skel');
            if ($tile.length) {
                $tile.removeClass('wpc-sd-tile--skel').find('.wpc-stat-label').text($tile.find('.wpc-stat-label').attr('data-label') || '');
            }
            if (value === null || typeof value === 'undefined') {
                $v.text('—');
                return;
            }
            var $n = $v.children('.wpc-sd-num');
            if (!$n.length) {
                $v.empty();
                $n = $('<span class="wpc-sd-num"></span>').attr('data-count-to', 0).attr('data-suffix', suffix).appendTo($v);
            }
            tween($n, parseInt(value, 10) || 0, suffix);
        }

        function applyTotals(t) {
            if (!t || t.stale) { return; }
            setTile('images', t.images, '');
            setTile('sizes', t.sizes, '');
            setTile('smaller', t.smaller, '%');
            var $restore = $page.find('.wpc-sd-restore-link');
            if ($restore.length && !(parseInt($restore.attr('data-wpc-restore-count'), 10) > 0) && t.images > 0) {
                $restore.attr('data-wpc-restore-count', t.images);
            }
        }

        function applyHero(html) {
            if (!html) { return; }
            var $new = $(html);
            var $cur = $slot.children('.wpc-sd-hero');
            if ($cur.length && $cur.attr('data-n') && $cur.attr('data-n') === $new.attr('data-n')) { return; }
            $slot.empty().append($new);
            heroCheck($slot);
        }

        function leaveFirstRun() {
            if ($page.attr('data-wpc-first') !== '1') { return; }
            $page.removeAttr('data-wpc-first');
            $slot.empty().append(skeletonHero());
            $page.find('.wpc-sd-benefits--lead').removeClass('wpc-sd-benefits--lead').addClass('wpc-sd-benefits--compact');
            $list.children('[data-skel]').remove();
            $foot.prop('hidden', false);
            var $pill = $page.find('.wpc-sd-pill--ready');
            $pill.removeClass('wpc-sd-pill--ready').find('.wpc-sd-pill-label').text($pill.attr('data-live') || '');
            var $say = $page.find('.wpc-sd-strip-text[data-live]');
            $say.text($say.attr('data-live') || '');
            $page.find('.wpc-sd-accent').addClass('wpc-sd-accent--live');
        }

        function dropSame($row) {
            var n = $row.attr('data-n');
            $list.children('.wpc-sd-row').filter(function () { return $(this).attr('data-n') === n; }).remove();
        }

        function zeroBars($row) {
            $row.find('.wpc-bar-fill').each(function () {
                this.setAttribute('data-target', this.style.width || '0');
                this.style.width = '0';
            });
        }

        function fillBars($row) {
            $row.find('.wpc-bar-fill[data-target]').each(function () {
                var bar = this;
                window.requestAnimationFrame(function () {
                    bar.style.width = bar.getAttribute('data-target');
                    bar.removeAttribute('data-target');
                });
            });
        }

        function cascade($row, done) {
            var inner = $list[0];
            var mask = inner.parentNode;
            var h0 = mask.offsetHeight;
            var before = inner.offsetHeight;
            var $shown = $list.children('.wpc-sd-row, .wpc-sd-skel-row').not('.is-more');
            var $leaving = !expanded && $shown.length >= visible ? $shown.last() : $();
            dropSame($row);
            $row.css('opacity', 0);
            zeroBars($row);
            $list.prepend($row);
            layout();
            $leaving.addClass('is-leaving');
            var shift = Math.max(0, inner.offsetHeight - before);
            var leaveH = $leaving.length ? $leaving[0].offsetHeight : 0;
            mask.style.transition = 'none';
            mask.style.height = h0 + 'px';
            inner.style.transition = 'none';
            inner.style.transform = 'translateY(-' + shift + 'px)';
            void inner.offsetHeight;
            if ($leaving.length) {
                $leaving[0].style.transition = 'opacity 0.5s ease';
                $leaving[0].style.opacity = '0';
            }
            inner.style.transition = 'transform 0.6s ' + CASCADE_EASE;
            inner.style.transform = 'translateY(0)';
            mask.style.transition = 'height 0.6s ' + CASCADE_EASE;
            mask.style.height = Math.max(0, h0 + shift - leaveH) + 'px';
            $row[0].style.transition = 'opacity 0.5s ease 0.1s';
            $row[0].style.opacity = '1';
            fillBars($row);
            setTimeout(function () {
                $leaving.removeClass('is-leaving').css({ opacity: '', transition: '' });
                $row.css({ opacity: '', transition: '' });
                inner.style.transition = '';
                inner.style.transform = '';
                mask.style.transition = '';
                mask.style.height = '';
                layout();
                done();
            }, CASCADE_MS);
        }

        var cascadeQ = [];
        var cascadeBusy = false;

        function drain() {
            if (cascadeBusy || !cascadeQ.length) { return; }
            cascadeBusy = true;
            cascade(cascadeQ.shift(), function () {
                setTimeout(function () {
                    cascadeBusy = false;
                    drain();
                }, CASCADE_GAP_MS);
            });
        }

        function addRows(rows) {
            if (!rows || !rows.length) { return; }
            leaveFirstRun();
            var animate = !reduced() && !document.hidden && !!window.requestAnimationFrame;
            var instant = animate ? Math.max(0, rows.length - CASCADE_MAX) : rows.length;
            $.each(rows, function (i, html) {
                var $row = $(html);
                if (i < instant) {
                    dropSame($row);
                    $list.prepend($row);
                } else {
                    cascadeQ.push($row);
                }
            });
            layout();
            drain();
        }

        var $how = $page.find('.wpc-sd-how-btn');
        var $modal = $page.find('.wpc-sd-modal');
        var $dialog = $modal.find('.wpc-sd-modal-dialog');
        function setHow(open) {
            if (open === !$modal.prop('hidden')) { return; }
            $how.attr('aria-expanded', open ? 'true' : 'false');
            $modal.prop('hidden', !open);
            if (open) {
                $dialog.trigger('focus');
            } else {
                $how.trigger('focus');
            }
        }
        $how.on('click', function () { setHow(true); });
        $modal.on('click', '[data-wpc-how-close]', function () { setHow(false); });
        $(document).on('keydown', function (e) {
            if ($modal.prop('hidden')) { return; }
            if (e.key === 'Escape' || e.key === 'Esc') {
                setHow(false);
                return;
            }
            if (e.key === 'Tab') {
                var $items = $dialog.find('button, a[href]').filter(':visible');
                var first = $items[0];
                var last = $items[$items.length - 1];
                if (!$items.length) { e.preventDefault(); return; }
                if (e.shiftKey && (document.activeElement === first || document.activeElement === $dialog[0])) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        $(document).on('heartbeat-send', function (e, data) {
            data.wpc_sd_since = newest;
            data.wpc_sd_hero = $slot.children('.wpc-sd-hero').not('.wpc-sd-hero--skel, .wpc-sd-hero--first').length ? 1 : 0;
        });

        $(document).on('heartbeat-tick', function (e, data) {
            var sd = data && data.wpc_sd;
            if (!sd) { return; }
            addRows(sd.rows);
            applyHero(sd.hero);
            if (sd.totals) { applyTotals(sd.totals); }
            newest = parseInt(sd.newest, 10) || newest;
        });

        if (window.wp && window.wp.heartbeat && $page.attr('data-wpc-sd') === 'active') {
            window.wp.heartbeat.interval(15);
        }
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    window.WPCRestoreAll = {
        pause: false,
        confirm: function (count) {
            var $link = $('[data-wpc-restore-count]').first();
            var n = $link.length ? (parseInt($link.attr('data-wpc-restore-count'), 10) || 0) : (parseInt(count, 10) || 0);
            var hasPause = $link.length && $link.attr('data-wpc-restore-sd') === '1';
            var self = this;
            var hintOn = 'Originals stay until you resume it on this page.';
            var hintOff = 'Optimized again on their next view, starting in 5 minutes.';
            var html = '<div class="wpc-restore-all">' +
                '<p>Deletes the optimized files for <strong>' + esc(fmt(n)) + ' ' + (n === 1 ? 'image' : 'images') + '</strong> from your server and the CDN.</p>' +
                '<p>Visitors get your original files.</p>' +
                '<p class="wpc-restore-all-note">You can do this once every 24 hours.</p>';
            if (hasPause) {
                html += '<label class="wpc-restore-all-pause"><input type="checkbox" id="wpc-sd-pause-box" checked> <span>Pause Smart Delivery</span></label>' +
                    '<p class="wpc-restore-all-hint" id="wpc-sd-pause-hint">' + hintOn + '</p>';
            }
            html += '</div>';
            self.pause = false;
            return WPCSwal.fire({
                title: 'Restore all images?',
                html: html,
                width: 480,
                showCancelButton: true,
                confirmButtonText: 'Restore ' + fmt(n) + ' ' + (n === 1 ? 'image' : 'images'),
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                buttonsStyling: false,
                customClass: {
                    container: 'wpc-popup-v6 wpc-confirm-popup',
                    confirmButton: 'wpc-confirm-btn wpc-confirm-btn--restore',
                    cancelButton: 'wpc-confirm-btn wpc-confirm-btn--ghost'
                },
                onOpen: bindPause,
                preConfirm: function () {
                    var box = document.getElementById('wpc-sd-pause-box');
                    self.pause = !!(hasPause && box && box.checked);
                    return true;
                }
            });

            function bindPause() {
                var box = document.getElementById('wpc-sd-pause-box');
                var hint = document.getElementById('wpc-sd-pause-hint');
                if (!box || !hint || box.getAttribute('data-bound')) { return; }
                box.setAttribute('data-bound', '1');
                box.addEventListener('change', function () {
                    hint.textContent = box.checked ? hintOn : hintOff;
                });
            }
        }
    };

    $(function () {
        var $page = $('.wpc-sd-page');
        if ($page.length) { initPage($page); }

        $(document).on('click', '.wpc-sd-restore-link', function (e) {
            e.preventDefault();
            $('.button-start-bulk-restore').not('.wpc-sd-restore-link').first().trigger('click');
        });
    });
})(jQuery);
