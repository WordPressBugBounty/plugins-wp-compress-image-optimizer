/*
 * The doctor's card in the Debug tab (templates/admin/partials/doctor.php). Nothing is asked for
 * until a button is pressed: the Debug tab renders on every settings page, and a report on every
 * page load would be a request nobody made. Every value is written with textContent, never as
 * HTML: a report carries URLs, receipts and option values from the site.
 */
(function () {
    'use strict';

    function start() {
        var root = document.getElementById('wpc-doctor');
        if (!root || root.getAttribute('data-wpc-doctor-ready') === '1') {
            return;
        }
        root.setAttribute('data-wpc-doctor-ready', '1');
        var status = root.querySelector('.wpc-doctor-status');
        var cards = root.querySelector('.wpc-doctor-cards');
        if (!status || !cards) {
            return;
        }

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) {
                node.className = className;
            }
            if (text !== undefined && text !== null) {
                node.textContent = String(text);
            }
            return node;
        }

        function show(value) {
            if (value === null || value === undefined) {
                return '-';
            }
            if (typeof value === 'boolean') {
                return value ? 'yes' : 'no';
            }
            if (typeof value === 'object') {
                return JSON.stringify(value, null, 2);
            }
            return String(value);
        }

        function stamp(t) {
            return t ? new Date(t * 1000).toISOString().replace('.000Z', 'Z') : '-';
        }

        function request(extra) {
            var body = new FormData();
            body.append('action', 'wpc_doctor');
            body.append('wps_ic_nonce', root.getAttribute('data-nonce') || '');
            var apikey = root.getAttribute('data-apikey') || '';
            if (apikey) {
                body.append('apikey', apikey);
            }
            var url = root.querySelector('.wpc-doctor-url');
            body.append('url', url ? url.value.trim() : '');
            var picked = [];
            root.querySelectorAll('.wpc-doctor-pick').forEach(function (box) {
                if (box.checked) {
                    picked.push(box.value);
                }
            });
            body.append('compartments', picked.join(','));
            body.append('hours', '24');
            Object.keys(extra).forEach(function (name) {
                body.append(name, extra[name]);
            });
            return fetch(root.getAttribute('data-ajaxurl'), {method: 'POST', credentials: 'same-origin', body: body})
                .then(function (response) {
                    return response.json();
                })
                .then(function (answer) {
                    if (!answer || !answer.success) {
                        var data = answer && answer.data;
                        throw new Error(data && data.msg ? data.msg : (typeof data === 'string' ? data : 'the site answered no report'));
                    }
                    return answer.data;
                });
        }

        function card(name, report) {
            var verdict = report.verdict || {};
            var box = el('div', 'wpc-doctor-card');
            var head = el('div', 'wpc-doctor-head');
            head.appendChild(el('strong', '', report.title || name));
            head.appendChild(el('span', 'wpc-doctor-level ' + (verdict.level || 'unknown'), (verdict.level || 'unknown').toUpperCase()));
            head.appendChild(el('span', '', verdict.line || ''));
            head.appendChild(el('span', 'wpc-doctor-code', '(' + (verdict.code || '') + ', ' + report.ms + ' ms)'));
            box.appendChild(head);
            (report.errors || []).forEach(function (error) {
                box.appendChild(el('div', '', 'error: ' + error));
            });
            var list = el('dl');
            (report.facts || []).forEach(function (fact) {
                list.appendChild(el('dt', '', fact.label));
                var value = el('dd');
                var shown = show(fact.value);
                value.appendChild(shown.indexOf('\n') >= 0 ? el('pre', '', shown) : el('span', '', shown));
                value.appendChild(el('div', 'wpc-doctor-source', fact.source));
                list.appendChild(value);
            });
            box.appendChild(list);
            if (report.artifacts && report.artifacts.length) {
                var files = el('details');
                files.appendChild(el('summary', '', 'files (' + report.artifacts.length + ')'));
                files.appendChild(el('pre', '', report.artifacts.map(function (a) {
                    return a.path + '  ' + a.bytes + ' B  ' + stamp(a.mtime);
                }).join('\n')));
                box.appendChild(files);
            }
            if (report.probe !== undefined) {
                var probe = el('details');
                probe.appendChild(el('summary', '', 'probe'));
                probe.appendChild(el('pre', '', show(report.probe)));
                box.appendChild(probe);
            }
            var receipts = el('details');
            receipts.appendChild(el('summary', '', 'receipts: ' + (report.receipt_count || 0) + ' in the window, last ' + (report.receipts || []).length));
            receipts.appendChild(el('pre', '', (report.receipts || []).map(function (entry) {
                return stamp(entry.t) + ' ' + entry.event + ' ' + (entry.key || '') + ' ' + JSON.stringify(entry.layers || {});
            }).join('\n')));
            box.appendChild(receipts);
            return box;
        }

        function render(report) {
            cards.textContent = '';
            var meta = report.meta || {};
            var journal = meta.journal || {};
            status.textContent = 'WP Compress ' + meta.plugin_version + '  url=' + meta.url + '  key=' + meta.key
                + '  journal: ' + (journal.lines || 0) + ' entries, ' + stamp(journal.first) + ' .. ' + stamp(journal.last)
                + (meta.probe ? '  (with probes)' : '') + '  ' + meta.ms + ' ms';
            (meta.errors || []).forEach(function (error) {
                cards.appendChild(el('div', '', 'error: ' + error));
            });
            Object.keys(report.compartments || {}).forEach(function (name) {
                cards.appendChild(card(name, report.compartments[name]));
            });
            var unclaimed = Object.keys(report.unclaimed || {});
            if (unclaimed.length) {
                cards.appendChild(el('div', 'wpc-doctor-source', 'events no compartment claims: ' + JSON.stringify(report.unclaimed)));
            }
        }

        function busy(on, what) {
            root.querySelectorAll('button').forEach(function (button) {
                button.disabled = on;
            });
            if (on) {
                status.textContent = what;
            }
        }

        root.querySelectorAll('.wpc-doctor-run').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                var probe = button.getAttribute('data-probe') === '1';
                busy(true, probe ? 'Reading, then fetching the page as a visitor...' : 'Reading...');
                request({probe: probe ? '1' : '0'}).then(function (report) {
                    busy(false);
                    render(report);
                }).catch(function (error) {
                    busy(false);
                    status.textContent = 'The doctor could not answer: ' + error.message;
                });
            });
        });

        var bundle = root.querySelector('.wpc-doctor-bundle');
        if (bundle) {
            bundle.addEventListener('click', function (event) {
                event.preventDefault();
                busy(true, 'Building the ticket bundle...');
                request({bundle: '1'}).then(function (report) {
                    busy(false);
                    var name = 'wpc-doctor-' + (root.getAttribute('data-host') || 'site') + '-'
                        + new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '') + '.json';
                    var link = el('a');
                    link.href = URL.createObjectURL(new Blob([JSON.stringify(report, null, 2)], {type: 'application/json'}));
                    link.download = name;
                    document.body.appendChild(link);
                    link.click();
                    setTimeout(function () {
                        URL.revokeObjectURL(link.href);
                        link.remove();
                    }, 1000);
                    render(report);
                    status.textContent += '  (saved as ' + name + ')';
                }).catch(function (error) {
                    busy(false);
                    status.textContent = 'The doctor could not answer: ' + error.message;
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
