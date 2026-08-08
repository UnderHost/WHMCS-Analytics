/*! CustomerPanel Analytics widget behaviors (c) 2026 UnderHost.com */
(function () {
    'use strict';

    function init(root) {
        if (!root || root.__cpgaInit) { return; }
        root.__cpgaInit = true;

        var ajax = root.getAttribute('data-ajax');
        var token = root.getAttribute('data-token') || '';
        var tabs = root.querySelector('.cpga-tabs');
        var loading = root.querySelector('.cpga-loading');
        var kpis = root.querySelector('.cpga-kpis');
        var chartWrap = root.querySelector('.cpga-chart-wrap');
        var canvas = root.querySelector('.cpga-chart');
        var tableWrap = root.querySelector('.cpga-table-wrap');
        var errBox = root.querySelector('.cpga-error');
        var startEl = root.querySelector('.cpga-start');
        var endEl = root.querySelector('.cpga-end');
        var chart = null;
        var current = 'graph';
        var firstLoad = true;
        // When embedded directly in the dashboard (theme homepage), the widget
        // is wrapped in a `.cpga-embed` card. If the very first request can't
        // reach the module endpoint (e.g. the module isn't installed), hide the
        // whole card rather than showing a broken-request error to every admin.
        var embedHost = root.closest ? root.closest('.cpga-embed') : null;

        // default range: last 30 days
        var today = new Date();
        var prior = new Date(); prior.setDate(prior.getDate() - 29);
        endEl.value = fmt(today);
        startEl.value = fmt(prior);

        function fmt(d) {
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }
        function pad(n) { return (n < 10 ? '0' : '') + n; }

        function show(el, on) { if (el) { if (on) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); } } }

        function setActive(tab) {
            var links = tabs.querySelectorAll('a');
            for (var i = 0; i < links.length; i++) {
                links[i].classList.toggle('active', links[i].getAttribute('data-tab') === tab);
            }
        }

        var gscHost = null;
        function mountGsc() {
            // Hand the Search Console tab off to the richer sub-app.
            show(loading, false); show(kpis, false); show(chartWrap, false);
            show(tableWrap, false); show(errBox, false);
            if (!gscHost) {
                gscHost = document.createElement('div');
                gscHost.className = 'cpga-gsc-host';
                root.querySelector('.cpga-body').appendChild(gscHost);
            }
            gscHost.style.display = '';
            if (window.CpgaGsc) {
                window.CpgaGsc.mount(gscHost, { ajax: ajax, token: token, chartSrc: window.cpgaChartSrc });
            } else {
                gscHost.innerHTML = '<div class="cpga-error alert alert-danger">Search Console assets (gsc.js) not loaded.</div>';
            }
            nudgeDashboard();
        }

        var advisorHost = null;
        function mountAdvisor() {
            show(loading, false); show(kpis, false); show(chartWrap, false);
            show(tableWrap, false); show(errBox, false);
            if (!advisorHost) {
                advisorHost = document.createElement('div');
                advisorHost.className = 'cpga-advisor-host';
                root.querySelector('.cpga-body').appendChild(advisorHost);
            }
            advisorHost.style.display = '';
            advisorHost.innerHTML =
                '<div class="cpga-advisor"><div class="cpga-advisor-head">'
                + '<div><h4><i class="fas fa-robot"></i> AI SEO Advisor</h4>'
                + '<p>Get prioritized, specific SEO recommendations generated from your GA4 &amp; Search Console data.</p></div>'
                + '<button class="btn btn-primary cpga-advisor-go" type="button"><i class="fas fa-wand-magic-sparkles"></i> Get advice</button>'
                + '</div><div class="cpga-advisor-out"></div></div>';
            advisorHost.querySelector('.cpga-advisor-go').addEventListener('click', runAdvice);
            nudgeDashboard();
        }
        function runAdvice() {
            var out = advisorHost.querySelector('.cpga-advisor-out');
            var btn = advisorHost.querySelector('.cpga-advisor-go');
            btn.disabled = true;
            out.innerHTML = '<div class="cpga-advisor-loading"><i class="fas fa-circle-notch fa-spin"></i> '
                + 'Analyzing your data with the AI model… this can take 10–30 seconds.</div>';
            fetch(ajax, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ai=advise&token=' + encodeURIComponent(token)
            }).then(function (r) { return r.json(); }).then(function (d) {
                btn.disabled = false;
                if (!d || d.error) {
                    var cfg = (d && d.need_config)
                        ? '<p class="cpga-advisor-hint">Set it up under <strong>Settings &amp; connection → AI SEO Advisor</strong>.</p>' : '';
                    out.innerHTML = '<div class="cpga-error alert alert-danger">' + esc(d && d.error ? d.error : 'No response.') + '</div>' + cfg;
                    nudgeDashboard();
                    return;
                }
                out.innerHTML = '<div class="cpga-advisor-meta">' + esc(d.provider || '') + ' · ' + esc(d.model || '')
                    + (d.range ? ' · ' + esc(d.range) : '') + '</div>'
                    + '<div class="cpga-advisor-md">' + mdToHtml(d.advice || '') + '</div>';
                nudgeDashboard();
            }).catch(function (e) {
                btn.disabled = false;
                out.innerHTML = '<div class="cpga-error alert alert-danger">Request failed: ' + esc(e.message) + '</div>';
                nudgeDashboard();
            });
        }
        // Minimal, safe Markdown → HTML (escapes first, then a few inline/block rules).
        function mdToHtml(md) {
            var lines = String(md).replace(/\r/g, '').split('\n'), html = '', inList = false;
            function inline(s) {
                s = esc(s);
                s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
                s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
                s = s.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
                return s;
            }
            for (var i = 0; i < lines.length; i++) {
                var ln = lines[i], m;
                if (/^\s*[-*]\s+/.test(ln)) {
                    if (!inList) { html += '<ul>'; inList = true; }
                    html += '<li>' + inline(ln.replace(/^\s*[-*]\s+/, '')) + '</li>'; continue;
                }
                if (inList) { html += '</ul>'; inList = false; }
                if ((m = ln.match(/^\s*#{2,3}\s+(.*)/))) { html += '<h5>' + inline(m[1]) + '</h5>'; continue; }
                if ((m = ln.match(/^\s*#\s+(.*)/))) { html += '<h4>' + inline(m[1]) + '</h4>'; continue; }
                if (ln.trim() === '') { continue; }
                html += '<p>' + inline(ln) + '</p>';
            }
            if (inList) { html += '</ul>'; }
            return html;
        }

        function load(tab) {
            current = tab;
            var wasFirst = firstLoad;
            firstLoad = false;
            setActive(tab);
            if (gscHost) { gscHost.style.display = 'none'; }
            if (advisorHost && tab !== 'advisor') { advisorHost.style.display = 'none'; }

            if (tab === 'keywords') { mountGsc(); return; }
            if (tab === 'advisor') { mountAdvisor(); return; }

            show(loading, true); show(kpis, false); show(chartWrap, false);
            show(tableWrap, false); show(errBox, false);

            var body = 'tab=' + encodeURIComponent(tab)
                + '&start=' + encodeURIComponent(startEl.value)
                + '&end=' + encodeURIComponent(endEl.value)
                + '&token=' + encodeURIComponent(token);

            fetch(ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function (r) { return r.text(); }).then(function (txt) {
                show(loading, false);
                var d;
                try { d = JSON.parse(txt); }
                catch (e) { return fail('Server returned non-JSON (likely a PHP error). Response: ' + String(txt).slice(0, 400)); }
                if (!d || d.error) { return fail(d && d.error ? d.error : 'No data.'); }
                if (d.type === 'graph') { renderGraph(d); }
                else if (d.type === 'realtime') { renderRealtime(d); }
                else if (d.type === 'geo') { renderGeo(d); }
                else if (d.type === 'alerts') { renderAlerts(d); }
                else if (d.type === 'indexing') { renderIndexing(d); }
                else { renderTable(d); }
                nudgeDashboard();
            }).catch(function (e) {
                show(loading, false);
                fail('Request failed: ' + e.message);
            });
        }

        function fail(msg) { errBox.textContent = msg; show(errBox, true); }

        // When embedded as a WHMCS admin dashboard widget, the surrounding
        // masonry grid doesn't re-flow when our height changes on a tab switch,
        // so neighbouring widgets can overlap. Poke it to re-layout: fire a few
        // delayed window resizes (to catch async chart/map paint) and, if a
        // masonry/packery grid is present, ask it to lay out.
        function relayout() {
            try { window.dispatchEvent(new Event('resize')); } catch (e) {}
            if (window.jQuery) {
                try {
                    var $ = window.jQuery;
                    $('[data-masonry], .masonry, .grid, #widgets, .widgets').each(function () {
                        var $el = $(this);
                        if ($el.data('masonry')) { $el.masonry('layout'); }
                        else if (typeof $el.packery === 'function' && $el.data('packery')) { $el.packery('layout'); }
                    });
                } catch (e) {}
            }
        }
        function nudgeDashboard() {
            if (!embedHost) { return; } // only inside the dashboard-embedded widget
            [60, 350, 750].forEach(function (ms) { setTimeout(relayout, ms); });
        }
        // The Search Console sub-app (and charts/maps) grow the widget's height
        // asynchronously — well after the timed nudges above — so a fixed set of
        // timeouts can miss the final size and leave the masonry grid overlapping.
        // Observe our own height and re-lay-out the grid whenever it actually
        // changes (debounced). Only inside the dashboard-embedded widget.
        if (embedHost && window.ResizeObserver) {
            var _roTimer = null;
            new window.ResizeObserver(function () {
                clearTimeout(_roTimer);
                _roTimer = setTimeout(relayout, 120);
            }).observe(embedHost);
        }

        function renderKpis(list) {
            kpis.innerHTML = '';
            var traffic = [], business = [];
            list.forEach(function (k) { (k.group === 'business' ? business : traffic).push(k); });
            kpis.appendChild(kpiRow(traffic));
            if (business.length) {
                var sep = document.createElement('div');
                sep.className = 'cpga-kpi-label';
                sep.textContent = 'Business';
                kpis.appendChild(sep);
                kpis.appendChild(kpiRow(business, 'cpga-kpis-biz'));
            }
            show(kpis, true);
        }
        function kpiRow(items, extraClass) {
            var row = document.createElement('div');
            row.className = 'cpga-kpi-row' + (extraClass ? ' ' + extraClass : '');
            items.forEach(function (k) {
                var d = document.createElement('div');
                d.className = 'cpga-kpi';
                d.innerHTML = '<div class="v"></div><div class="l"></div>';
                d.querySelector('.v').textContent = (typeof k.value === 'number') ? k.value.toLocaleString() : k.value;
                d.querySelector('.l').textContent = k.label;
                row.appendChild(d);
            });
            return row;
        }

        function renderGraph(d) {
            renderKpis(d.kpis || []);
            show(chartWrap, true);
            var cur = d.currency || { prefix: '', suffix: '' };
            function money(v, dec) { return cur.prefix + (+v || 0).toLocaleString(undefined, { minimumFractionDigits: dec || 0, maximumFractionDigits: dec || 0 }) + cur.suffix; }
            withChart(function () {
                if (chart) { chart.destroy(); }
                var palette = ['#5865F2', '#00D4FF', '#35ED7E'];
                var moneyColor = '#f59e0b';
                var trafficIdx = 0, hasY1 = false;
                var datasets = [];
                (d.datasets || []).forEach(function (ds) {
                    var isMoney = ds.money || ds.axis === 'y1';
                    // Drop an all-zero revenue overlay so a no-revenue site shows a
                    // clean traffic graph instead of a flat line + a meaningless
                    // "$0/$1" right axis. (The KPI tiles still report Revenue: 0.)
                    if (isMoney && !(ds.data || []).some(function (v) { return (+v) > 0; })) { return; }
                    if (isMoney) { hasY1 = true; }
                    var c = isMoney ? moneyColor : palette[(trafficIdx++) % palette.length];
                    datasets.push({
                        label: ds.label, data: ds.data,
                        borderColor: c, backgroundColor: c + (isMoney ? '18' : '22'),
                        fill: !isMoney, tension: 0.35, borderWidth: 2,
                        borderDash: isMoney ? [5, 3] : [],
                        pointRadius: 0, pointHoverRadius: 4,
                        yAxisID: isMoney ? 'y1' : 'y',
                        __money: isMoney
                    });
                });
                var scales = {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { font: { size: 11 }, callback: function (v) { return (+v).toLocaleString(); } } }
                };
                if (hasY1) {
                    scales.y1 = {
                        position: 'right', beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { font: { size: 11 }, callback: function (v) { return money(v, 0); } }
                    };
                }
                var ChartLib = window.cpgaChartLib || window.Chart;
                chart = new ChartLib(canvas.getContext('2d'), {
                    type: 'line',
                    data: { labels: d.labels || [], datasets: datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
                            tooltip: {
                                usePointStyle: true, padding: 10, boxPadding: 4,
                                callbacks: {
                                    label: function (c) {
                                        var v = c.parsed.y || 0;
                                        return ' ' + c.dataset.label + ': ' + (c.dataset.__money ? money(v, 2) : v.toLocaleString());
                                    }
                                }
                            }
                        },
                        scales: scales
                    }
                });
            });
        }

        function renderGeo(d) {
        show(tableWrap, true);
        // Dispose any prior ECharts instance before we replace the container.
        var prev = tableWrap.querySelector('.cpga-geomap');
        if (prev && window.CpgaGeoMap) { window.CpgaGeoMap.dispose(prev); }
        var html = '<div class="cpga-geowrap"><div class="cpga-geomap"></div>'
            + '<div class="cpga-geohint">Shaded by ' + esc(d.valueLabel || 'value')
            + ' · hover for figures · scroll to zoom, drag to pan</div></div>';
        html += tableHtml(d.columns, d.rows);
        tableWrap.innerHTML = html;
        var mapEl = tableWrap.querySelector('.cpga-geomap');
        if (window.CpgaGeoMap && d.map && d.map.length) {
            window.CpgaGeoMap.render(mapEl, d.map, { valueLabel: d.valueLabel || 'Value' });
        } else if (mapEl) {
            mapEl.innerHTML = '<div class="cpga-geomap-empty">No country data to map for this range.</div>';
        }
    }

    function renderRealtime(d) {
            show(tableWrap, true);
            var html = '<div class="cpga-realtime-total">' + (d.total || 0).toLocaleString()
                + '<small>Active users right now</small></div>';
            html += tableHtml(d.columns, d.rows.map(function (r) { return [r.label, r.value.toLocaleString()]; }));
            tableWrap.innerHTML = html;
        }

        function renderTable(d) {
            show(tableWrap, true);
            tableWrap.innerHTML = tableHtml(d.columns, d.rows);
        }

        function renderAlerts(d) {
            show(tableWrap, true);
            var a = d.alerts || [];
            var html = '<div class="cpga-alerts">'
                + '<div class="cpga-alerts-head"><span><i class="fas fa-bell"></i> Alerts</span>'
                + '<small>Updated ' + esc(d.generated || '') + ' · last 7 &amp; 28 days</small></div>';
            if (!a.length) {
                html += '<div class="cpga-alert cpga-alert-good"><div class="ic"><i class="fas fa-circle-check"></i></div>'
                    + '<div class="bd"><div class="t">All clear</div>'
                    + '<div class="d">No traffic drops, ranking slides, or new opportunities detected right now.</div></div></div>';
            } else {
                a.forEach(function (x) {
                    html += '<div class="cpga-alert cpga-alert-' + esc(x.level) + '">'
                        + '<div class="ic"><i class="fas ' + esc(x.icon || 'fa-circle-info') + '"></i></div>'
                        + '<div class="bd"><div class="t">' + esc(x.title) + '</div>'
                        + '<div class="d">' + esc(x.detail) + '</div></div>'
                        + (x.change ? '<div class="ch">' + esc(x.change) + '</div>' : '')
                        + '</div>';
                });
            }
            tableWrap.innerHTML = html + '</div>';
        }

        function shortUrl(u, origin) {
            if (origin && u.indexOf(origin) === 0) { var p = u.slice(origin.length); return p === '' ? '/' : p; }
            return u.replace(/^https?:\/\//, '');
        }
        function shortCoverage(s) {
            var t = String(s).toLowerCase();
            if (t.indexOf('indexed') >= 0 && t.indexOf('not indexed') < 0) { return 'Indexed'; }
            if (t.indexOf('not indexed') >= 0) { return 'Not indexed'; }
            if (t.indexOf('unknown') >= 0) { return 'Unknown'; }
            if (t.indexOf('excluded') >= 0) { return 'Excluded'; }
            if (t.indexOf('error') >= 0) { return 'Error'; }
            return s.length > 22 ? s.slice(0, 20) + '…' : (s || 'Unknown');
        }
        function indexClass(res) {
            var v = (res.verdict || '').toUpperCase();
            if (v === 'PASS') { return 'idx-ok'; }
            if (v === 'FAIL') { return 'idx-bad'; }
            if (v === 'NEUTRAL' || v === 'PARTIAL') { return 'idx-warn'; }
            return 'idx-neutral';
        }
        function indexBadge(res) {
            var label = res.coverageState || res.verdict || 'Unknown';
            return '<span class="cpga-idx-badge ' + indexClass(res) + '" title="' + esc(label) + '">' + esc(shortCoverage(label)) + '</span>';
        }
        function indexDetail(url, res) {
            function row(k, v) { return v ? '<div class="cpga-idx-kv"><span>' + esc(k) + '</span><b>' + esc(v) + '</b></div>' : ''; }
            var crawl = res.lastCrawlTime ? new Date(res.lastCrawlTime).toLocaleString() : '';
            return '<div class="cpga-idx-detail ' + indexClass(res) + '">'
                + '<div class="cpga-idx-detail-head">' + indexBadge(res)
                + '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="cpga-idx-url-link">' + esc(url) + '</a></div>'
                + row('Coverage', res.coverageState)
                + row('Indexing allowed', res.indexingState)
                + row('Robots.txt', res.robotsTxtState)
                + row('Page fetch', res.pageFetchState)
                + row('Last crawled', crawl)
                + row('Google-selected canonical', res.googleCanonical)
                + row('Declared canonical', res.userCanonical)
                + row('Mobile usability', res.mobileVerdict)
                + (res.inspectionLink ? '<a class="cpga-idx-open" href="' + esc(res.inspectionLink) + '" target="_blank" rel="noopener">Open in Search Console <i class="fas fa-arrow-up-right-from-square"></i></a>' : '')
                + '</div>';
        }
        function inspect(url, statusCell, resultBox) {
            if (statusCell) { statusCell.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>'; }
            if (resultBox) { show(resultBox, true); resultBox.innerHTML = '<div class="cpga-idx-loading"><i class="fas fa-circle-notch fa-spin"></i> Inspecting…</div>'; }
            fetch(ajax, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'tab=indexing&url=' + encodeURIComponent(url) + '&token=' + encodeURIComponent(token)
            }).then(function (r) { return r.text(); }).then(function (txt) {
                var d;
                try { d = JSON.parse(txt); } catch (e) { d = { error: 'Server returned non-JSON.' }; }
                if (!d || d.error) {
                    var msg = d && d.error ? d.error : 'Failed.';
                    if (statusCell) { statusCell.innerHTML = '<span class="cpga-idx-badge idx-bad" title="' + esc(msg) + '">error</span>'; }
                    if (resultBox) { resultBox.innerHTML = '<div class="cpga-error alert alert-danger">' + esc(msg) + '</div>'; }
                    nudgeDashboard(); return;
                }
                var res = d.result || {};
                if (statusCell) { statusCell.innerHTML = indexBadge(res); }
                if (resultBox) { resultBox.innerHTML = indexDetail(d.url, res); }
                nudgeDashboard();
            }).catch(function (e) {
                if (statusCell) { statusCell.innerHTML = '<span class="cpga-idx-badge idx-bad">error</span>'; }
                if (resultBox) { resultBox.innerHTML = '<div class="cpga-error alert alert-danger">Request failed: ' + esc(e.message) + '</div>'; }
            });
        }
        function renderIndexing(d) {
            show(tableWrap, true);
            var rows = d.rows || [], origin = d.origin || '';
            var html = '<div class="cpga-indexing">'
                + '<div class="cpga-idx-tools">'
                + '<input type="text" class="cpga-idx-url form-control input-sm" placeholder="Inspect any URL on ' + esc(origin || 'your site') + '…">'
                + '<button class="btn btn-sm btn-default cpga-idx-go" type="button"><i class="fas fa-magnifying-glass"></i> Inspect</button>'
                + '</div>'
                + '<div class="cpga-idx-result" hidden></div>'
                + '<div class="cpga-idx-hint">Top pages by impressions (last 28 days). Click <strong>Inspect</strong> to check a page’s Google index status.</div>'
                + '<table class="cpga-table cpga-idx-table"><thead><tr><th>Page</th><th>Clicks</th><th>Impressions</th><th>Index status</th></tr></thead><tbody>';
            if (!rows.length) {
                html += '<tr><td colspan="4">No Search Console pages for this site yet.</td></tr>';
            }
            rows.forEach(function (r) {
                html += '<tr data-url="' + esc(r.url) + '">'
                    + '<td class="cpga-idx-page"><span title="' + esc(r.url) + '">' + esc(shortUrl(r.url, origin)) + '</span></td>'
                    + '<td>' + (r.clicks || 0).toLocaleString() + '</td>'
                    + '<td>' + (r.impressions || 0).toLocaleString() + '</td>'
                    + '<td class="cpga-idx-status"><button class="btn btn-xs btn-default cpga-idx-row" type="button">Inspect</button></td>'
                    + '</tr>';
            });
            tableWrap.innerHTML = html + '</tbody></table></div>';

            var box = tableWrap.querySelector('.cpga-indexing');
            box.querySelectorAll('.cpga-idx-row').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tr = btn.closest('tr');
                    inspect(tr.getAttribute('data-url'), btn.closest('td'), null);
                });
            });
            var resBox = box.querySelector('.cpga-idx-result');
            var urlInput = box.querySelector('.cpga-idx-url');
            box.querySelector('.cpga-idx-go').addEventListener('click', function () {
                var u = urlInput.value.trim();
                if (u) { inspect(u, null, resBox); }
            });
            urlInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); var u = urlInput.value.trim(); if (u) { inspect(u, null, resBox); } }
            });
        }

        function tableHtml(cols, rows) {
            var h = '<table class="cpga-table"><thead><tr>';
            (cols || []).forEach(function (c) { h += '<th>' + esc(c) + '</th>'; });
            h += '</tr></thead><tbody>';
            if (!rows || !rows.length) { h += '<tr><td colspan="' + (cols ? cols.length : 1) + '">No data for this range.</td></tr>'; }
            (rows || []).forEach(function (row) {
                h += '<tr>';
                row.forEach(function (cell) { h += '<td>' + esc(String(cell)) + '</td>'; });
                h += '</tr>';
            });
            return h + '</tbody></table>';
        }

        function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

        // Load OUR bundled Chart.js (v4) into a private reference. The WHMCS admin
        // already defines a different, older window.Chart (used by its System
        // Overview graph); building our v4-style config against that older version
        // throws (getBasePixel/"skip"). So we load our own copy, capture it as
        // window.cpgaChartLib, and immediately restore WHMCS's window.Chart so its
        // native charts keep working. Chart instances hold their own constructor
        // reference, so restoring the global is safe.
        function withChart(cb) {
            if (window.cpgaChartLib) { return cb(); }
            if (window.__cpgaChartLoading) { window.__cpgaChartLoading.push(cb); return; }
            window.__cpgaChartLoading = [cb];
            var prev = window.Chart;
            var s = document.createElement('script');
            s.src = window.cpgaChartSrc;
            s.onload = function () {
                window.cpgaChartLib = window.Chart;
                if (prev !== undefined) { window.Chart = prev; } // restore WHMCS's Chart.js
                (window.__cpgaChartLoading || []).forEach(function (fn) { fn(); });
                window.__cpgaChartLoading = null;
            };
            document.head.appendChild(s);
        }

        tabs.addEventListener('click', function (e) {
            var a = e.target.closest('a[data-tab]');
            if (!a) { return; }
            e.preventDefault();
            load(a.getAttribute('data-tab'));
        });
        root.querySelector('.cpga-apply').addEventListener('click', function () { load(current); });

        load('graph');
    }

    function boot() {
        var roots = document.querySelectorAll('.cpga');
        for (var i = 0; i < roots.length; i++) { init(roots[i]); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
