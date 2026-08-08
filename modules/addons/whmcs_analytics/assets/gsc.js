/*! CustomerPanel Analytics — Search Console sub-app (c) 2026 UnderHost.com */
(function () {
    'use strict';

    var VIEWS = [
        ['query', 'Queries'], ['page', 'Pages'], ['country', 'Countries'],
        ['device', 'Devices'], ['date', 'Dates'], ['appearance', 'Search appearance']
    ];
    var KEY_LABEL = {
        query: 'Query', page: 'Page', country: 'Country', device: 'Device',
        date: 'Date', appearance: 'Search appearance'
    };

    /* ---------------- date helpers ---------------- */
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toDate(s) { var p = String(s).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
    function fmt(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
    function addDays(s, n) { var d = toDate(s); d.setUTCDate(d.getUTCDate() + n); return fmt(d); }
    function addMonths(s, n) { var d = toDate(s); d.setUTCMonth(d.getUTCMonth() + n); return fmt(d); }
    function daysBetween(a, b) { return Math.round((toDate(b) - toDate(a)) / 86400000) + 1; }
    function todayMinus(n) { var d = new Date(); d.setUTCDate(d.getUTCDate() - n); return fmt(d); }

    /* ---------------- formatting ---------------- */
    function num(n) { return (typeof n === 'number' ? n : 0).toLocaleString(); }
    function pct(x) { return ((x || 0) * 100).toFixed(1) + '%'; }
    function pos(x) { return (x === null || x === undefined) ? '—' : (+x).toFixed(1); }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    // Delta chip. kind: 'count' | 'ctr' | 'pos'. For pos, value is position change (prev-cur; + = better).
    function delta(v, kind) {
        if (v === null || v === undefined) { return ''; }
        var good, txt, arrow;
        if (kind === 'ctr') { good = v > 0; txt = (v >= 0 ? '+' : '−') + Math.abs(v * 100).toFixed(1) + 'pt'; }
        else if (kind === 'pos') { good = v > 0; txt = (v >= 0 ? '+' : '−') + Math.abs(v).toFixed(1); }
        else { good = v > 0; txt = (v >= 0 ? '+' : '−') + num(Math.abs(v)); }
        var cls = Math.abs(v) < 1e-9 ? 'flat' : (good ? 'up' : 'down');
        arrow = Math.abs(v) < 1e-9 ? 'fa-minus' : (good ? 'fa-arrow-up' : 'fa-arrow-down');
        return '<span class="gsc-delta ' + cls + '"><i class="fas ' + arrow + '" aria-hidden="true"></i> ' + txt + '</span>';
    }
    function movementBadge(mv, dP) {
        var map = {
            improved: ['fa-arrow-up', 'Improved'], declined: ['fa-arrow-down', 'Declined'],
            unchanged: ['fa-minus', 'No change'], new: ['fa-star', 'New'], lost: ['fa-ghost', 'Lost']
        };
        var m = map[mv] || map.unchanged;
        var extra = (mv === 'improved' || mv === 'declined') && dP !== null
            ? ' ' + (dP >= 0 ? '+' : '−') + Math.abs(dP).toFixed(1) : '';
        return '<span class="gsc-mv ' + mv + '"><i class="fas ' + m[0] + '" aria-hidden="true"></i>' + m[1] + extra + '</span>';
    }

    /* ---------------- module ---------------- */
    var CpgaGsc = {
        mount: function (host, ctx) {
            if (host.__gscApp) { host.__gscApp.refreshStatus(); return; }
            host.__gscApp = new App(host, ctx);
        }
    };

    function App(host, ctx) {
        this.host = host;
        this.ctx = ctx;
        this.charts = [];
        this.state = {
            dim: 'query', mode: 'table',
            preset: 'last28', start: '', end: '', anchorEnd: todayMinus(3),
            cmp: 'period', cmpStart: '', cmpEnd: '',
            sort: 'impressions', dir: 'desc',
            filters: { q: '', minImpr: '', minClicks: '', posMin: '', posMax: '', movement: 'any' },
            offset: 0, perPage: 50
        };
        this.build();
        this.refreshStatus();
    }

    App.prototype.api = function (action, extra) {
        var body = 'gsc=' + encodeURIComponent(action) + '&token=' + encodeURIComponent(this.ctx.token || '');
        Object.keys(extra || {}).forEach(function (k) {
            if (extra[k] !== undefined && extra[k] !== null && extra[k] !== '') {
                body += '&' + k + '=' + encodeURIComponent(extra[k]);
            }
        });
        return fetch(this.ctx.ajax, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        }).then(function (r) { return r.json(); });
    };

    App.prototype.ranges = function () {
        var s = this.state, end, start;
        if (s.preset === 'custom') { end = s.end || s.anchorEnd; start = s.start || addDays(end, -27); }
        else {
            end = s.anchorEnd;
            var map = { last7: 7, last28: 28, last3mo: null, last6mo: null, last12mo: null };
            if (s.preset === 'last3mo') { start = addDays(addMonths(end, -3), 1); }
            else if (s.preset === 'last6mo') { start = addDays(addMonths(end, -6), 1); }
            else if (s.preset === 'last12mo') { start = addDays(addMonths(end, -12), 1); }
            else { start = addDays(end, -(map[s.preset] - 1)); }
        }
        var cs, ce;
        if (s.cmp === 'custom') { cs = s.cmpStart; ce = s.cmpEnd; }
        else if (s.cmp === 'week') { cs = addDays(start, -7); ce = addDays(end, -7); }
        else if (s.cmp === 'month') { cs = addMonths(start, -1); ce = addMonths(end, -1); }
        else if (s.cmp === 'year') { cs = addMonths(start, -12); ce = addMonths(end, -12); }
        else { var len = daysBetween(start, end); ce = addDays(start, -1); cs = addDays(ce, -(len - 1)); }
        return { start: start, end: end, cmpStart: cs, cmpEnd: ce };
    };

    App.prototype.rangeParams = function () {
        var r = this.ranges();
        return { start: r.start, end: r.end, cmpStart: r.cmpStart, cmpEnd: r.cmpEnd, dim: this.state.dim };
    };

    /* ---------------- build shell ---------------- */
    App.prototype.build = function () {
        var self = this;
        var viewBtns = VIEWS.map(function (v) {
            return '<button data-view="' + v[0] + '"' + (v[0] === 'query' ? ' class="active"' : '') + '>' + v[1] + '</button>';
        }).join('');

        this.host.innerHTML =
            '<div class="cpga-gsc">' +
            '<div class="gsc-toolbar">' +
                '<div class="gsc-views">' + viewBtns + '</div>' +
                '<div class="gsc-control"><label>Range</label><select class="gsc-preset">' +
                    '<option value="last7">Last 7 days</option>' +
                    '<option value="last28" selected>Last 28 days</option>' +
                    '<option value="last3mo">Last 3 months</option>' +
                    '<option value="last6mo">Last 6 months</option>' +
                    '<option value="last12mo">Last 12 months</option>' +
                    '<option value="custom">Custom…</option>' +
                '</select>' +
                '<input type="date" class="gsc-start" hidden><input type="date" class="gsc-end" hidden></div>' +
                '<div class="gsc-control"><label>Compare</label><select class="gsc-cmp">' +
                    '<option value="period">Previous period</option>' +
                    '<option value="week">Previous week</option>' +
                    '<option value="month">Previous month</option>' +
                    '<option value="year">Previous year</option>' +
                    '<option value="custom">Custom…</option>' +
                '</select>' +
                '<input type="date" class="gsc-cmpstart" hidden><input type="date" class="gsc-cmpend" hidden></div>' +
                '<button class="gsc-btn gsc-filter-toggle" type="button"><i class="fas fa-filter"></i> Filters</button>' +
                '<div class="gsc-spacer"></div>' +
                '<div class="gsc-cmplabel"></div>' +
                '<div class="gsc-sync-state"></div>' +
                '<button class="gsc-btn gsc-sync" type="button"><i class="fas fa-rotate"></i> Sync</button>' +
            '</div>' +
            '<div class="gsc-cmpdates" style="font-size:11.5px;color:var(--cp-text-soft,#5b6b84);margin:-6px 0 10px"></div>' +
            '<div class="gsc-filters">' +
                field('Search text', '<input type="text" class="f-q" placeholder="keyword or URL contains…">') +
                field('Sort by', '<select class="f-sort">' +
                    opt('impressions', 'Impressions') + opt('clicks', 'Clicks') + opt('ctr', 'CTR') +
                    opt('position', 'Position') + opt('posChange', 'Position change') +
                    opt('clickChange', 'Click change') + opt('imprChange', 'Impression change') +
                    opt('ctrChange', 'CTR change') + '</select>') +
                field('Direction', '<select class="f-dir">' + opt('desc', 'Descending') + opt('asc', 'Ascending') + '</select>') +
                fieldNum('Min impressions', '<input type="number" min="0" class="f-minImpr">') +
                fieldNum('Min clicks', '<input type="number" min="0" class="f-minClicks">') +
                fieldNum('Position ≥', '<input type="number" min="1" step="0.1" class="f-posMin">') +
                fieldNum('Position ≤', '<input type="number" min="1" step="0.1" class="f-posMax">') +
                field('Movement', '<select class="f-movement">' +
                    opt('any', 'Any') + opt('improved', 'Improved') + opt('declined', 'Declined') +
                    opt('unchanged', 'Unchanged') + opt('new', 'New keyword') + opt('lost', 'Lost keyword') + '</select>') +
            '</div>' +
            '<div class="gsc-modenav">' +
                '<button data-mode="table" class="active">Explorer</button>' +
                '<button data-mode="movers">Top Movers</button>' +
                '<button data-mode="opportunities">Opportunities</button>' +
            '</div>' +
            '<div class="gsc-body"></div>' +
            '</div>';

        function field(label, inner) { return '<div class="gsc-field"><label>' + label + '</label>' + inner + '</div>'; }
        function fieldNum(label, inner) { return '<div class="gsc-field num"><label>' + label + '</label>' + inner + '</div>'; }
        function opt(v, l) { return '<option value="' + v + '">' + l + '</option>'; }

        var q = this.host.querySelector.bind(this.host);
        this.el = {
            body: q('.gsc-body'), cmplabel: q('.gsc-cmplabel'), cmpdates: q('.gsc-cmpdates'),
            syncState: q('.gsc-sync-state'), filters: q('.gsc-filters'),
            preset: q('.gsc-preset'), start: q('.gsc-start'), end: q('.gsc-end'),
            cmp: q('.gsc-cmp'), cmpstart: q('.gsc-cmpstart'), cmpend: q('.gsc-cmpend')
        };

        // events
        this.host.querySelectorAll('.gsc-views button').forEach(function (b) {
            b.addEventListener('click', function () {
                self.host.querySelectorAll('.gsc-views button').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                self.state.dim = b.getAttribute('data-view');
                self.state.offset = 0;
                self.render();
            });
        });
        this.host.querySelectorAll('.gsc-modenav button').forEach(function (b) {
            b.addEventListener('click', function () {
                self.host.querySelectorAll('.gsc-modenav button').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                self.state.mode = b.getAttribute('data-mode');
                self.render();
            });
        });
        this.el.preset.addEventListener('change', function () {
            self.state.preset = this.value;
            var custom = this.value === 'custom';
            self.el.start.hidden = !custom; self.el.end.hidden = !custom;
            if (custom && !self.el.end.value) { self.el.end.value = self.state.anchorEnd; self.el.start.value = addDays(self.state.anchorEnd, -27); }
            self.state.start = self.el.start.value; self.state.end = self.el.end.value;
            self.state.offset = 0; self.render();
        });
        this.el.cmp.addEventListener('change', function () {
            self.state.cmp = this.value;
            var custom = this.value === 'custom';
            self.el.cmpstart.hidden = !custom; self.el.cmpend.hidden = !custom;
            self.state.offset = 0; self.render();
        });
        [['start', 'start'], ['end', 'end'], ['cmpstart', 'cmpStart'], ['cmpend', 'cmpEnd']].forEach(function (p) {
            self.el[p[0]].addEventListener('change', function () { self.state[p[1]] = this.value; self.state.offset = 0; self.render(); });
        });
        q('.gsc-filter-toggle').addEventListener('click', function () { self.el.filters.classList.toggle('open'); });
        q('.gsc-sync').addEventListener('click', function () { self.startSync(this); });

        // filter inputs
        var fq = q('.f-q');
        fq.addEventListener('input', debounce(function () { self.state.filters.q = fq.value; self.state.offset = 0; self.loadTable(); }, 350));
        [['f-minImpr', 'minImpr'], ['f-minClicks', 'minClicks'], ['f-posMin', 'posMin'], ['f-posMax', 'posMax']].forEach(function (p) {
            var elx = q('.' + p[0]);
            elx.addEventListener('input', debounce(function () { self.state.filters[p[1]] = elx.value; self.state.offset = 0; self.loadTable(); }, 350));
        });
        q('.f-movement').addEventListener('change', function () { self.state.filters.movement = this.value; self.state.offset = 0; self.loadTable(); });
        q('.f-sort').addEventListener('change', function () { self.state.sort = this.value; self.loadTable(); });
        q('.f-dir').addEventListener('change', function () { self.state.dir = this.value; self.loadTable(); });
    };

    /* ---------------- status / sync ---------------- */
    App.prototype.refreshStatus = function () {
        var self = this;
        this.api('status', {}).then(function (d) {
            if (d && d.coverage && d.coverage.max_date) { self.state.anchorEnd = d.coverage.max_date; }
            self.renderSyncState(d);
            self.render();
        }).catch(function () { self.render(); });
    };
    App.prototype.renderSyncState = function (d) {
        if (!d) { this.el.syncState.textContent = ''; return; }
        if (!d.storage_configured) {
            this.el.syncState.innerHTML = '<span title="' + esc(d.reason || '') + '"><i class="fas fa-database"></i> Storage not configured</span>';
            return;
        }
        var cov = d.coverage || {};
        var txt = cov.max_date ? ('Data ' + cov.min_date + ' → ' + cov.max_date) : 'No data yet — click Sync';
        var pctDone = '';
        if (d.sync && d.sync.backfill_cursor && d.sync.backfill_start) {
            var total = Math.max(1, daysBetween(d.sync.backfill_start, cov.max_date || d.sync.backfill_start));
            var done = Math.max(0, daysBetween(cov.min_date || cov.max_date || d.sync.backfill_start, cov.max_date || d.sync.backfill_start));
            var p = Math.min(100, Math.round(done / total * 100));
            if (d.sync.backfill_cursor > d.sync.backfill_start) {
                pctDone = ' <span class="bar"><i style="width:' + p + '%"></i></span> ' + p + '% history';
            }
        }
        this.el.syncState.innerHTML = '<span>' + esc(txt) + '</span>' + pctDone;
    };
    App.prototype.startSync = function (btn) {
        var self = this;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Syncing…';
        function step() {
            self.api('sync', {}).then(function (d) {
                self.api('status', {}).then(function (st) {
                    self.renderSyncState(st);
                    if (st && st.coverage && st.coverage.max_date) { self.state.anchorEnd = st.coverage.max_date; }
                });
                if (d && !d.backfill_done && (!d.errors || !d.errors.length)) {
                    step(); // continue backfill
                } else {
                    btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> Sync';
                    if (d && d.errors && d.errors.length) { alert('Sync finished with issues:\n' + d.errors.slice(0, 5).join('\n')); }
                    self.render();
                }
            }).catch(function (e) {
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> Sync';
                alert('Sync failed: ' + e.message);
            });
        }
        step();
    };

    /* ---------------- render dispatch ---------------- */
    App.prototype.render = function () {
        var r = this.ranges();
        this.el.cmplabel.innerHTML = 'Compared: <b>' + r.start + ' → ' + r.end + '</b> vs <b>' + r.cmpStart + ' → ' + r.cmpEnd + '</b>';
        this.el.cmpdates.textContent = '';
        var showModeNav = this.state.dim !== 'date';
        this.host.querySelector('.gsc-modenav').style.display = showModeNav ? '' : 'none';
        var mode = showModeNav ? this.state.mode : 'table';
        if (mode === 'movers') { this.loadMovers(); }
        else if (mode === 'opportunities') { this.loadOpportunities(); }
        else { this.loadTable(); this.loadSummary(); }
    };

    App.prototype.loadSummary = function () {
        var self = this;
        if (this.state.dim === 'date') { return; }
        this.api('summary', this.rangeParams()).then(function (d) {
            if (!d || d.error) { return; }
            self.renderSummary(d);
        });
    };
    App.prototype.renderSummary = function (d) {
        var s = d.summary || {}, host = this.el.body.querySelector('.gsc-explorer .gsc-cards');
        if (!host) { return; }
        function card(v, l, cls) { return '<div class="gsc-card ' + (cls || '') + '"><div class="v">' + v + '</div><div class="l">' + l + '</div></div>'; }
        host.innerHTML =
            card(num(s.total_cur), 'Tracked') +
            card(num(s.improved), 'Improved', 'up') +
            card(num(s.declined), 'Declined', 'down') +
            card(num(s.unchanged), 'Unchanged', 'flat') +
            card(num(s.new_kw), 'New', 'up') +
            card(num(s.lost_kw), 'Lost', 'down') +
            card(num(s.p1_3), 'Pos 1–3') +
            card(num(s.p4_10), 'Pos 4–10') +
            card(num(s.p11_20), 'Pos 11–20') +
            card(num(s.p21_50), 'Pos 21–50') +
            card(num(s.p50p), 'Pos 50+');
    };

    /* ---------------- table ---------------- */
    App.prototype.loadTable = function () {
        var self = this, s = this.state;
        var params = this.rangeParams();
        params.sort = s.sort; params.dir = s.dir; params.offset = s.offset; params.perPage = s.perPage;
        params.q = s.filters.q; params.minImpr = s.filters.minImpr; params.minClicks = s.filters.minClicks;
        params.posMin = s.filters.posMin; params.posMax = s.filters.posMax; params.movement = s.filters.movement;

        this.ensureTableSkeleton(this.state.dim);
        var wrap = this.el.body.querySelector('.gsc-explorer .gsc-tablewrap');
        if (wrap) { wrap.style.opacity = '.5'; }
        this.api('view', params).then(function (d) {
            if (!d || d.error) { self.tableError(d && d.error ? d.error : 'No data.'); return; }
            self.renderTable(d);
        }).catch(function (e) { self.tableError('Request failed: ' + e.message); });
    };
    // Build (or rebuild, when the dimension changed or coming from another mode)
    // the explorer container. Keyed by data-dim so pagination/sort reuse it.
    App.prototype.ensureTableSkeleton = function (dim) {
        var ex = this.el.body.querySelector('.gsc-explorer');
        if (ex && ex.getAttribute('data-dim') === dim) { return; }
        // Tearing down a previous explorer: dispose any map instance first.
        var oldMap = this.el.body.querySelector('.gsc-geomap');
        if (oldMap && window.CpgaGeoMap) { window.CpgaGeoMap.dispose(oldMap); }
        var cards = (dim !== 'date') ? '<div class="gsc-cards"></div>' : '';
        var geo = (dim === 'country')
            ? '<div class="gsc-geowrap"><div class="gsc-geomap"></div><div class="gsc-geohint">Shaded by impressions · hover for figures · scroll to zoom, drag to pan</div></div>'
            : '';
        this.el.body.innerHTML = '<div class="gsc-explorer" data-dim="' + dim + '">' + cards + geo +
            '<div class="gsc-tablewrap"><div class="gsc-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading…</div></div>' +
            '<div class="gsc-pager"></div></div>';
    };
    App.prototype.tableError = function (msg) {
        var w = this.el.body.querySelector('.gsc-explorer .gsc-tablewrap');
        if (w) { w.style.opacity = '1'; w.innerHTML = '<div class="gsc-error alert alert-danger">' + esc(msg) + '</div>'; }
    };
    App.prototype.renderTable = function (d) {
        var self = this, s = this.state, dim = d.dim, rows = d.rows || [];
        var wrap = this.el.body.querySelector('.gsc-explorer .gsc-tablewrap');
        var pager = this.el.body.querySelector('.gsc-explorer .gsc-pager');
        wrap.style.opacity = '1';

        if (dim === 'date') {
            var h = '<table class="gsc-table"><thead><tr><th>Date</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
            if (!rows.length) { h += '<tr><td colspan="5">No data for this range.</td></tr>'; }
            rows.forEach(function (r) {
                h += '<tr><td>' + esc(r.k) + '</td><td>' + num(r.cc) + '</td><td>' + num(r.ci) + '</td><td>' + pct(r.ct) + '</td><td>' + pos(r.cp) + '</td></tr>';
            });
            wrap.innerHTML = h + '</tbody></table>';
            pager.innerHTML = '';
            return;
        }

        var cols = [
            { key: null, sort: null, label: KEY_LABEL[dim] || 'Key' },
            { sort: 'clicks', label: 'Clicks' },
            { sort: 'impressions', label: 'Impressions' },
            { sort: 'ctr', label: 'CTR' },
            { sort: 'position', label: 'Position' },
            { sort: 'posChange', label: 'Movement' }
        ];
        var head = cols.map(function (c) {
            if (!c.sort) { return '<th>' + esc(c.label) + '</th>'; }
            var cls = 'sortable';
            if (s.sort === c.sort) { cls += (s.dir === 'asc' ? ' sort-asc' : ' sort-desc'); }
            return '<th class="' + cls + '" data-sort="' + c.sort + '">' + esc(c.label) + '</th>';
        }).join('');

        var body = '';
        if (!rows.length) { body = '<tr><td colspan="6">No rows match these filters.</td></tr>'; }
        rows.forEach(function (r) {
            var clickable = (dim === 'query') ? ' class="clickable" data-q="' + esc(r.k) + '"' : '';
            body += '<tr' + clickable + '>' +
                '<td><span class="gsc-key">' + esc(r.k) + '</span></td>' +
                '<td>' + num(r.cc) + ' ' + delta(r.dC, 'count') + '</td>' +
                '<td>' + num(r.ci) + ' ' + delta(r.dI, 'count') + '</td>' +
                '<td>' + pct(r.ct) + ' ' + delta(r.dT, 'ctr') + '</td>' +
                '<td>' + pos(r.cp) + ' ' + delta(r.dP, 'pos') + '</td>' +
                '<td style="text-align:left">' + movementBadge(r.mv, r.dP) + '</td>' +
                '</tr>';
        });
        wrap.innerHTML = '<table class="gsc-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';

        // header sort
        wrap.querySelectorAll('th[data-sort]').forEach(function (th) {
            th.addEventListener('click', function () {
                var k = th.getAttribute('data-sort');
                if (s.sort === k) { s.dir = (s.dir === 'asc' ? 'desc' : 'asc'); }
                else { s.sort = k; s.dir = (k === 'position') ? 'asc' : 'desc'; }
                self.syncSortControls();
                s.offset = 0; self.loadTable();
            });
        });
        // row → detail
        if (dim === 'query') {
            wrap.querySelectorAll('tr.clickable').forEach(function (tr) {
                tr.addEventListener('click', function () { self.openDetail(tr.getAttribute('data-q')); });
            });
        }

        // pager
        var from = d.total ? d.offset + 1 : 0, to = Math.min(d.offset + d.perPage, d.total);
        pager.innerHTML = '<span>Showing ' + from + '–' + to + ' of ' + num(d.total) + '</span>' +
            '<span class="pg"><button class="gsc-btn gsc-prev"' + (d.offset <= 0 ? ' disabled' : '') + '>Prev</button>' +
            '<button class="gsc-btn gsc-next"' + (to >= d.total ? ' disabled' : '') + '>Next</button></span>';
        var prev = pager.querySelector('.gsc-prev'), next = pager.querySelector('.gsc-next');
        if (prev) { prev.addEventListener('click', function () { s.offset = Math.max(0, s.offset - s.perPage); self.loadTable(); }); }
        if (next) { next.addEventListener('click', function () { s.offset = s.offset + s.perPage; self.loadTable(); }); }

        // World-map heatmap for the country dimension (built from the first page,
        // which — sorted by impressions — already carries the heaviest countries).
        if (dim === 'country' && s.offset === 0 && window.CpgaGeoMap) {
            var mapEl = this.el.body.querySelector('.gsc-geomap');
            if (mapEl) {
                var mrows = rows.map(function (r) { return { code: r.k, value: r.ci }; });
                if (mrows.length) { window.CpgaGeoMap.render(mapEl, mrows, { valueLabel: 'Impressions' }); }
                else { mapEl.innerHTML = '<div class="cpga-geomap-empty">No country data to map.</div>'; }
            }
        }
    };
    App.prototype.syncSortControls = function () {
        var fs = this.host.querySelector('.f-sort'), fd = this.host.querySelector('.f-dir');
        if (fs) { fs.value = this.state.sort; } if (fd) { fd.value = this.state.dir; }
    };

    /* ---------------- movers ---------------- */
    App.prototype.loadMovers = function () {
        var self = this;
        this.el.body.innerHTML = '<div class="gsc-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading movers…</div>';
        this.api('movers', this.rangeParams()).then(function (d) {
            if (!d || d.error) { self.el.body.innerHTML = '<div class="gsc-error alert alert-danger">' + esc(d && d.error ? d.error : 'No data.') + '</div>'; return; }
            var m = d.movers;
            self.el.body.innerHTML = '<div class="gsc-grid">' +
                moverPanel('Largest improvements', 'fa-arrow-up', m.improvements, 'pos') +
                moverPanel('Largest declines', 'fa-arrow-down', m.declines, 'pos') +
                moverPanel('Gaining impressions', 'fa-chart-line', m.impressions_gained, 'impr') +
                moverPanel('Losing impressions', 'fa-chart-line', m.impressions_lost, 'impr') +
                moverPanel('High impressions, low CTR', 'fa-crosshairs', m.high_impr_low_ctr, 'ctr', d.ctr_avg) +
                moverPanel('Close to page one (11–20)', 'fa-flag-checkered', m.close_to_page_one, 'near') +
                '</div>';
            self.wireMoverRows();
        });
    };
    function moverPanel(title, icon, rows, kind, ctrAvg) {
        var extra = kind === 'ctr' ? ' <small>site avg ' + pct(ctrAvg) + '</small>' : '';
        var h = '<div class="gsc-panel"><h4><i class="fas ' + icon + '"></i> ' + title + extra + '</h4><div class="gsc-tablewrap"><table class="gsc-table"><tbody>';
        if (!rows || !rows.length) { h += '<tr><td>No keywords.</td></tr>'; }
        (rows || []).forEach(function (r) {
            var metric;
            if (kind === 'pos') { metric = movementBadge(r.mv, r.dP); }
            else if (kind === 'impr') { metric = num(r.ci) + ' ' + delta(r.dI, 'count'); }
            else if (kind === 'ctr') { metric = pct(r.ct) + ' · pos ' + pos(r.cp); }
            else { metric = 'pos ' + pos(r.cp) + ' · ' + num(r.ci) + ' impr'; }
            h += '<tr class="clickable" data-q="' + esc(r.k) + '"><td><span class="gsc-key">' + esc(r.k) + '</span></td><td style="text-align:right">' + metric + '</td></tr>';
        });
        return h + '</tbody></table></div></div>';
    }
    App.prototype.wireMoverRows = function () {
        var self = this;
        this.el.body.querySelectorAll('tr.clickable').forEach(function (tr) {
            tr.addEventListener('click', function () { self.openDetail(tr.getAttribute('data-q')); });
        });
    };

    /* ---------------- opportunities ---------------- */
    App.prototype.loadOpportunities = function () {
        var self = this;
        this.el.body.innerHTML = '<div class="gsc-loading"><i class="fas fa-circle-notch fa-spin"></i> Finding opportunities…</div>';
        this.api('opportunities', this.rangeParams()).then(function (d) {
            if (!d || d.error) { self.el.body.innerHTML = '<div class="gsc-error alert alert-danger">' + esc(d && d.error ? d.error : 'No data.') + '</div>'; return; }
            var g = d.groups, th = d.thresholds || {};
            self.el.body.innerHTML = '<div class="gsc-grid">' +
                oppPanel('CTR opportunity', 'fa-hand-pointer', g.ctr_opportunity, 'Pos 1–10, ≥' + th.ctr_min_impr + ' impr, CTR below ' + pct(th.ctr_cut)) +
                oppPanel('Page-one opportunity', 'fa-flag', g.page_one, 'Pos 11–20, ≥' + th.pageone_min_impr + ' impr') +
                oppPanel('Declining keywords', 'fa-arrow-trend-down', g.declining, 'Dropped ≥' + th.decline_places + ' places, ≥' + th.decline_min_impr + ' impr') +
                oppPanel('Emerging keywords', 'fa-seedling', g.emerging, 'Impressions up & position improving') +
                '</div>';
            self.wireMoverRows();
        });
    };
    function oppPanel(title, icon, rows, subtitle) {
        var h = '<div class="gsc-panel"><h4><i class="fas ' + icon + '"></i> ' + title + ' <small>' + esc(subtitle) + '</small></h4><div class="gsc-tablewrap"><table class="gsc-table"><thead><tr><th>Query</th><th>Impr</th><th>CTR</th><th>Pos</th></tr></thead><tbody>';
        if (!rows || !rows.length) { h += '<tr><td colspan="4">None found.</td></tr>'; }
        (rows || []).forEach(function (r) {
            h += '<tr class="clickable" data-q="' + esc(r.k) + '"><td><span class="gsc-key">' + esc(r.k) + '</span></td>' +
                '<td>' + num(r.ci) + '</td><td>' + pct(r.ct) + '</td><td>' + pos(r.cp) + ' ' + delta(r.dP, 'pos') + '</td></tr>';
        });
        return h + '</tbody></table></div></div>';
    }

    /* ---------------- keyword detail drawer ---------------- */
    App.prototype.openDetail = function (query) {
        var self = this;
        var back = document.createElement('div');
        back.className = 'gsc-drawer-back';
        back.innerHTML = '<div class="gsc-drawer"><button class="close" title="Close">&times;</button>' +
            '<div class="gsc-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading keyword…</div></div>';
        document.body.appendChild(back);
        var drawer = back.querySelector('.gsc-drawer');
        function close() { self.destroyCharts(); back.remove(); }
        back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
        back.querySelector('.close').addEventListener('click', close);
        document.addEventListener('keydown', function esc2(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc2); } });

        var p = this.rangeParams(); p.query = query;
        this.api('detail', p).then(function (d) {
            if (!d || d.error) { drawer.innerHTML = '<button class="close">&times;</button><div class="gsc-error alert alert-danger">' + esc(d && d.error ? d.error : 'No data.') + '</div>'; drawer.querySelector('.close').addEventListener('click', close); return; }
            self.renderDetail(drawer, d, close);
        }).catch(function (e) { drawer.innerHTML = '<div class="gsc-error alert alert-danger">Failed: ' + esc(e.message) + '</div>'; });
    };
    App.prototype.renderDetail = function (drawer, d, close) {
        var self = this, k = d.kpi || {}, meta = d.meta || {}, weekly = d.weekly || [];
        function kcard(v, l, cls) { return '<div class="gsc-card ' + (cls || '') + '"><div class="v">' + v + '</div><div class="l">' + l + '</div></div>'; }
        var posCls = k.pos_change === null ? 'flat' : (k.pos_change > 0 ? 'up' : (k.pos_change < 0 ? 'down' : 'flat'));

        drawer.innerHTML =
            '<button class="close" title="Close">&times;</button>' +
            '<h3>' + esc(d.query) + '</h3>' +
            '<div class="sub">Weekly tracking · current week vs previous week · range ' + esc(d.range.cur[0]) + ' → ' + esc(d.range.cur[1]) + '</div>' +
            '<div class="gsc-kpirow">' +
                kcard(pos(k.cur_pos) + ' ' + (k.pos_change !== null ? '<span class="gsc-delta ' + posCls + '">' + (k.pos_change >= 0 ? '+' : '−') + Math.abs(k.pos_change).toFixed(1) + '</span>' : ''), 'Avg position', posCls) +
                kcard(num(k.cur_clicks) + ' ' + delta(k.click_change, 'count'), 'Clicks') +
                kcard(num(k.cur_impr) + ' ' + delta(k.impr_change, 'count'), 'Impressions') +
                kcard(pct(k.cur_ctr) + ' ' + delta(k.ctr_change, 'ctr'), 'CTR') +
            '</div>' +
            '<table class="gsc-metatable"><tbody>' +
                metaRow('Best position', pos(meta.best_position)) +
                metaRow('Worst position', pos(meta.worst_position)) +
                metaRow('First seen', esc(meta.first_seen || '—')) +
                metaRow('Last seen', esc(meta.last_seen || '—')) +
                metaRow('Ranking volatility (σ of weekly position)', (d.volatility || 0).toFixed(2)) +
                metaRow('Best week', d.best_week ? (esc(d.best_week.week_start) + ' · pos ' + pos(d.best_week.position)) : '—') +
            '</tbody></table>' +
            '<div class="gsc-chartgrid">' +
                chartBox('Position over time', 'cPos') +
                chartBox('Clicks over time', 'cClk') +
                chartBox('Impressions over time', 'cImp') +
                chartBox('CTR over time', 'cCtr') +
            '</div>' +
            breakdownPanel('Landing pages', d.breakdowns.pages) +
            breakdownPanel('Countries', d.breakdowns.countries) +
            breakdownPanel('Devices', d.breakdowns.devices);

        drawer.querySelector('.close').addEventListener('click', close);

        function metaRow(l, v) { return '<tr><td>' + l + '</td><td>' + v + '</td></tr>'; }
        function chartBox(title, id) { return '<div class="gsc-chartbox"><h5>' + title + '</h5><div class="cwrap"><canvas data-c="' + id + '"></canvas></div></div>'; }
        function breakdownPanel(title, rows) {
            var h = '<div class="gsc-panel" style="margin-bottom:14px"><h4>' + title + '</h4><div class="gsc-tablewrap"><table class="gsc-table"><thead><tr><th>' + title + '</th><th>Clicks</th><th>Impr</th><th>CTR</th><th>Pos</th></tr></thead><tbody>';
            if (!rows || !rows.length) { h += '<tr><td colspan="5">No data.</td></tr>'; }
            (rows || []).forEach(function (r) {
                h += '<tr><td>' + esc(r.k) + '</td><td>' + num(r.cc) + '</td><td>' + num(r.ci) + '</td><td>' + pct(r.ct) + '</td><td>' + pos(r.cp) + '</td></tr>';
            });
            return h + '</tbody></table></div></div>';
        }

        // charts
        var labels = weekly.map(function (w) { return w.week_start; });
        this.withChart(function () {
            self.mkChart(drawer, 'cPos', labels, weekly.map(function (w) { return w.position; }), '#5865F2', true, 'Position');
            self.mkChart(drawer, 'cClk', labels, weekly.map(function (w) { return w.clicks; }), '#35ED7E', false, 'Clicks');
            self.mkChart(drawer, 'cImp', labels, weekly.map(function (w) { return w.impressions; }), '#00D4FF', false, 'Impressions');
            self.mkChart(drawer, 'cCtr', labels, weekly.map(function (w) { return +(w.ctr * 100).toFixed(2); }), '#EC48BD', false, 'CTR', '%');
        });
    };
    App.prototype.mkChart = function (drawer, id, labels, data, color, reverse, label, suffix) {
        var canvas = drawer.querySelector('canvas[data-c="' + id + '"]');
        if (!canvas || !window.Chart) { return; }
        suffix = suffix || '';
        var ch = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: [{ label: label || '', data: data, borderColor: color, backgroundColor: color + '22', fill: true, tension: 0.3, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, spanGaps: true }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false, padding: 8,
                        callbacks: {
                            label: function (c) {
                                var v = c.parsed.y;
                                return (c.dataset.label ? c.dataset.label + ': ' : '') + (v === null || v === undefined ? '—' : v.toLocaleString() + suffix);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 6, font: { size: 10 } } },
                    y: { reverse: !!reverse, ticks: { font: { size: 10 } } }
                }
            }
        });
        this.charts.push(ch);
    };
    App.prototype.destroyCharts = function () { this.charts.forEach(function (c) { try { c.destroy(); } catch (e) {} }); this.charts = []; };
    App.prototype.withChart = function (cb) {
        if (window.Chart) { return cb(); }
        if (window.__cpgaChartLoading) { window.__cpgaChartLoading.push(cb); return; }
        window.__cpgaChartLoading = [cb];
        var s = document.createElement('script');
        s.src = this.ctx.chartSrc;
        s.onload = function () { (window.__cpgaChartLoading || []).forEach(function (f) { f(); }); window.__cpgaChartLoading = null; };
        document.head.appendChild(s);
    };

    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

    window.CpgaGsc = CpgaGsc;
})();
