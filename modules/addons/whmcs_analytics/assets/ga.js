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
            }).then(function (r) { return r.json(); }).then(function (d) {
                show(loading, false);
                if (!d || d.error) { return fail(d && d.error ? d.error : 'No data.'); }
                if (d.type === 'graph') { renderGraph(d); }
                else if (d.type === 'realtime') { renderRealtime(d); }
                else if (d.type === 'geo') { renderGeo(d); }
                else { renderTable(d); }
                nudgeDashboard();
            }).catch(function (e) {
                show(loading, false);
                // Module unreachable on first load in embedded mode → hide the card.
                if (wasFirst && embedHost) { embedHost.style.display = 'none'; return; }
                fail('Request failed: ' + e.message);
            });
        }

        function fail(msg) { errBox.textContent = msg; show(errBox, true); }

        // When embedded as a WHMCS admin dashboard widget, the surrounding
        // masonry grid doesn't re-flow when our height changes on a tab switch,
        // so neighbouring widgets can overlap. Poke it to re-layout: fire a few
        // delayed window resizes (to catch async chart/map paint) and, if a
        // masonry/packery grid is present, ask it to lay out.
        function nudgeDashboard() {
            if (!embedHost) { return; } // only inside the dashboard-embedded widget
            [60, 350, 750].forEach(function (ms) {
                setTimeout(function () {
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
                }, ms);
            });
        }

        function renderKpis(list) {
            kpis.innerHTML = '';
            list.forEach(function (k) {
                var d = document.createElement('div');
                d.className = 'cpga-kpi';
                d.innerHTML = '<div class="v"></div><div class="l"></div>';
                d.querySelector('.v').textContent = (typeof k.value === 'number') ? k.value.toLocaleString() : k.value;
                d.querySelector('.l').textContent = k.label;
                kpis.appendChild(d);
            });
            show(kpis, true);
        }

        function renderGraph(d) {
            renderKpis(d.kpis || []);
            show(chartWrap, true);
            withChart(function () {
                if (chart) { chart.destroy(); }
                var palette = ['#5865F2', '#00D4FF', '#35ED7E'];
                chart = new Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: d.labels || [],
                        datasets: (d.datasets || []).map(function (ds, i) {
                            var c = palette[i % palette.length];
                            return {
                                label: ds.label, data: ds.data,
                                borderColor: c, backgroundColor: c + '22',
                                fill: true, tension: 0.35, borderWidth: 2,
                                pointRadius: 0, pointHoverRadius: 4
                            };
                        })
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
                            tooltip: {
                                usePointStyle: true, padding: 10, boxPadding: 4,
                                callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + (c.parsed.y || 0).toLocaleString(); } }
                            }
                        },
                        scales: {
                            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 11 } } },
                            y: { beginAtZero: true, ticks: { font: { size: 11 }, callback: function (v) { return (+v).toLocaleString(); } } }
                        }
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

        function withChart(cb) {
            if (window.Chart) { return cb(); }
            if (window.__cpgaChartLoading) { window.__cpgaChartLoading.push(cb); return; }
            window.__cpgaChartLoading = [cb];
            var s = document.createElement('script');
            s.src = window.cpgaChartSrc;
            s.onload = function () { (window.__cpgaChartLoading || []).forEach(function (fn) { fn(); }); window.__cpgaChartLoading = null; };
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
