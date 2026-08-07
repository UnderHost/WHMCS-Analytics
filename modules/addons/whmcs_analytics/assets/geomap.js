/*! CustomerPanel Analytics — ECharts world-map (choropleth) helper (c) 2026 UnderHost.com
 *
 * Renders a heat-shaded world map for any country dataset. Country rows may be
 * keyed by ISO alpha-2 (GA4 `countryId`), ISO alpha-3 (Search Console), or an
 * English country name — all are resolved to the ISO-3 keys used by the bundled
 * world.geo.json. ECharts and the GeoJSON are loaded lazily and only once.
 *
 *   window.cpgaEChartsSrc  — URL of the local echarts.min.js
 *   window.cpgaWorldGeoUrl — URL of the local world.geo.json
 *
 *   CpgaGeoMap.render(container, rows, opts)
 *     rows: [{ code, name, value }]   opts: { valueLabel, dark }
 */
(function () {
    'use strict';

    // ISO 3166-1 alpha-2 → alpha-3 (map keys are alpha-3).
    var ISO2_ISO3 = {
        AF: 'AFG', AX: 'ALA', AL: 'ALB', DZ: 'DZA', AS: 'ASM', AD: 'AND', AO: 'AGO', AI: 'AIA',
        AQ: 'ATA', AG: 'ATG', AR: 'ARG', AM: 'ARM', AW: 'ABW', AU: 'AUS', AT: 'AUT', AZ: 'AZE',
        BS: 'BHS', BH: 'BHR', BD: 'BGD', BB: 'BRB', BY: 'BLR', BE: 'BEL', BZ: 'BLZ', BJ: 'BEN',
        BM: 'BMU', BT: 'BTN', BO: 'BOL', BQ: 'BES', BA: 'BIH', BW: 'BWA', BV: 'BVT', BR: 'BRA',
        IO: 'IOT', BN: 'BRN', BG: 'BGR', BF: 'BFA', BI: 'BDI', CV: 'CPV', KH: 'KHM', CM: 'CMR',
        CA: 'CAN', KY: 'CYM', CF: 'CAF', TD: 'TCD', CL: 'CHL', CN: 'CHN', CX: 'CXR', CC: 'CCK',
        CO: 'COL', KM: 'COM', CG: 'COG', CD: 'COD', CK: 'COK', CR: 'CRI', CI: 'CIV', HR: 'HRV',
        CU: 'CUB', CW: 'CUW', CY: 'CYP', CZ: 'CZE', DK: 'DNK', DJ: 'DJI', DM: 'DMA', DO: 'DOM',
        EC: 'ECU', EG: 'EGY', SV: 'SLV', GQ: 'GNQ', ER: 'ERI', EE: 'EST', SZ: 'SWZ', ET: 'ETH',
        FK: 'FLK', FO: 'FRO', FJ: 'FJI', FI: 'FIN', FR: 'FRA', GF: 'GUF', PF: 'PYF', TF: 'ATF',
        GA: 'GAB', GM: 'GMB', GE: 'GEO', DE: 'DEU', GH: 'GHA', GI: 'GIB', GR: 'GRC', GL: 'GRL',
        GD: 'GRD', GP: 'GLP', GU: 'GUM', GT: 'GTM', GG: 'GGY', GN: 'GIN', GW: 'GNB', GY: 'GUY',
        HT: 'HTI', HM: 'HMD', VA: 'VAT', HN: 'HND', HK: 'HKG', HU: 'HUN', IS: 'ISL', IN: 'IND',
        ID: 'IDN', IR: 'IRN', IQ: 'IRQ', IE: 'IRL', IM: 'IMN', IL: 'ISR', IT: 'ITA', JM: 'JAM',
        JP: 'JPN', JE: 'JEY', JO: 'JOR', KZ: 'KAZ', KE: 'KEN', KI: 'KIR', KP: 'PRK', KR: 'KOR',
        KW: 'KWT', KG: 'KGZ', LA: 'LAO', LV: 'LVA', LB: 'LBN', LS: 'LSO', LR: 'LBR', LY: 'LBY',
        LI: 'LIE', LT: 'LTU', LU: 'LUX', MO: 'MAC', MG: 'MDG', MW: 'MWI', MY: 'MYS', MV: 'MDV',
        ML: 'MLI', MT: 'MLT', MH: 'MHL', MQ: 'MTQ', MR: 'MRT', MU: 'MUS', YT: 'MYT', MX: 'MEX',
        FM: 'FSM', MD: 'MDA', MC: 'MCO', MN: 'MNG', ME: 'MNE', MS: 'MSR', MA: 'MAR', MZ: 'MOZ',
        MM: 'MMR', NA: 'NAM', NR: 'NRU', NP: 'NPL', NL: 'NLD', NC: 'NCL', NZ: 'NZL', NI: 'NIC',
        NE: 'NER', NG: 'NGA', NU: 'NIU', NF: 'NFK', MK: 'MKD', MP: 'MNP', NO: 'NOR', OM: 'OMN',
        PK: 'PAK', PW: 'PLW', PS: 'PSE', PA: 'PAN', PG: 'PNG', PY: 'PRY', PE: 'PER', PH: 'PHL',
        PN: 'PCN', PL: 'POL', PT: 'PRT', PR: 'PRI', QA: 'QAT', RE: 'REU', RO: 'ROU', RU: 'RUS',
        RW: 'RWA', BL: 'BLM', SH: 'SHN', KN: 'KNA', LC: 'LCA', MF: 'MAF', PM: 'SPM', VC: 'VCT',
        WS: 'WSM', SM: 'SMR', ST: 'STP', SA: 'SAU', SN: 'SEN', RS: 'SRB', SC: 'SYC', SL: 'SLE',
        SG: 'SGP', SX: 'SXM', SK: 'SVK', SI: 'SVN', SB: 'SLB', SO: 'SOM', ZA: 'ZAF', GS: 'SGS',
        SS: 'SSD', ES: 'ESP', LK: 'LKA', SD: 'SDN', SR: 'SUR', SJ: 'SJM', SE: 'SWE', CH: 'CHE',
        SY: 'SYR', TW: 'TWN', TJ: 'TJK', TZ: 'TZA', TH: 'THA', TL: 'TLS', TG: 'TGO', TK: 'TKL',
        TO: 'TON', TT: 'TTO', TN: 'TUN', TR: 'TUR', TM: 'TKM', TC: 'TCA', TV: 'TUV', UG: 'UGA',
        UA: 'UKR', AE: 'ARE', GB: 'GBR', US: 'USA', UM: 'UMI', UY: 'URY', UZ: 'UZB', VU: 'VUT',
        VE: 'VEN', VN: 'VNM', VG: 'VGB', VI: 'VIR', WF: 'WLF', EH: 'ESH', YE: 'YEM', ZM: 'ZMB',
        ZW: 'ZWE', XK: 'KOS'
    };

    var state = {
        echartsLoad: null,   // pending Promise for the library
        mapReady: false,
        mapLoad: null,       // pending Promise for the GeoJSON registration
        iso3Name: {},        // ISO3 -> canonical English name (from GeoJSON)
        nameIso3: {}         // normalised-name -> ISO3 (from GeoJSON)
    };

    function normName(s) { return String(s || '').toLowerCase().replace(/[^a-z]/g, ''); }

    // A few common GA4 country names that differ from the GeoJSON's names.
    var NAME_ALIASES = {
        unitedstates: 'USA', unitedstatesofamerica: 'USA', usa: 'USA',
        unitedkingdom: 'GBR', uk: 'GBR', greatbritain: 'GBR',
        russia: 'RUS', russianfederation: 'RUS',
        southkorea: 'KOR', republicofkorea: 'KOR', korea: 'KOR',
        northkorea: 'PRK',
        czechia: 'CZE', czechrepublic: 'CZE',
        tanzania: 'TZA', unitedrepublicoftanzania: 'TZA',
        venezuela: 'VEN', bolivia: 'BOL', iran: 'IRN', syria: 'SYR', laos: 'LAO',
        vietnam: 'VNM', brunei: 'BRN', moldova: 'MDA',
        democraticrepublicofthecongo: 'COD', congokinshasa: 'COD',
        republicofthecongo: 'COG', congobrazzaville: 'COG', congo: 'COG',
        ivorycoast: 'CIV', cotedivoire: 'CIV',
        myanmarburma: 'MMR', burma: 'MMR',
        macedonia: 'MKD', northmacedonia: 'MKD',
        swaziland: 'SWZ', eswatini: 'SWZ',
        capeverde: 'CPV', eastimor: 'TLS', timorleste: 'TLS'
    };

    function loadECharts() {
        if (window.echarts) { return Promise.resolve(); }
        if (state.echartsLoad) { return state.echartsLoad; }
        state.echartsLoad = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = window.cpgaEChartsSrc;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('Failed to load ECharts.')); };
            document.head.appendChild(s);
        });
        return state.echartsLoad;
    }

    function loadMap() {
        if (state.mapReady) { return Promise.resolve(); }
        if (state.mapLoad) { return state.mapLoad; }
        state.mapLoad = fetch(window.cpgaWorldGeoUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (geo) {
                (geo.features || []).forEach(function (f) {
                    var iso3 = f.id || (f.properties && f.properties.iso3);
                    var name = f.properties && f.properties.name;
                    if (!f.properties) { f.properties = {}; }
                    f.properties.iso3 = iso3;               // used as ECharts nameProperty
                    if (iso3 && name) {
                        state.iso3Name[iso3] = name;
                        state.nameIso3[normName(name)] = iso3;
                    }
                });
                window.echarts.registerMap('cpga_world', { geoJSON: geo });
                state.mapReady = true;
            });
        return state.mapLoad;
    }

    function resolveIso3(code, name) {
        var c = String(code || '').trim().toUpperCase();
        if (c.length === 3 && state.iso3Name[c]) { return c; }
        if (c.length === 2 && ISO2_ISO3[c]) { return ISO2_ISO3[c]; }
        var key = normName(name || code);
        if (NAME_ALIASES[key]) { return NAME_ALIASES[key]; }
        if (state.nameIso3[key]) { return state.nameIso3[key]; }
        return null;
    }

    // Detect a dark host so the map blends with the admin theme.
    function detectDark(el, override) {
        if (override === true || override === false) { return override; }
        try {
            var node = el;
            while (node && node !== document.documentElement) {
                var bg = getComputedStyle(node).backgroundColor;
                var m = bg && bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                if (m) {
                    var a = bg.match(/rgba\([^)]+,\s*([\d.]+)\)/);
                    if (!a || parseFloat(a[1]) > 0) {
                        var lum = (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]);
                        return lum < 128;
                    }
                }
                node = node.parentElement;
            }
        } catch (e) {}
        return false;
    }

    function fmtInt(n) { return (typeof n === 'number' ? n : 0).toLocaleString(); }

    // `scaledMax` drives the colour ramp (values are sqrt-scaled to spread out a
    // long tail); `rawMax` is the real maximum shown on the legend/tooltip.
    function buildOption(data, scaledMax, rawMax, dark, valueLabel) {
        var land = dark ? '#2c3446' : '#e9edf5';
        var border = dark ? '#1b2130' : '#c9d3e4';
        var text = dark ? '#c7d0e0' : '#41506b';
        // Saturated, clearly-dark ramps so even mid-tier countries read strongly.
        var ramp = dark
            ? ['#39406b', '#4450b0', '#5865F2', '#8b9bff', '#c2ccff']
            : ['#c5cfff', '#8b9bff', '#5865F2', '#3a3fbf', '#211f7a'];
        return {
            tooltip: {
                trigger: 'item',
                formatter: function (p) {
                    var name = (p.data && p.data.label) || p.name;
                    if (!p.data || p.data.raw === undefined || p.data.raw === null || isNaN(p.data.raw)) {
                        return '<b>' + name + '</b><br>No data';
                    }
                    return '<b>' + name + '</b><br>' + (valueLabel || 'Value') + ': ' + fmtInt(p.data.raw);
                }
            },
            visualMap: {
                type: 'continuous', min: 0, max: scaledMax, calculable: true,
                left: 12, bottom: 12, orient: 'vertical',
                text: [fmtInt(rawMax), '0'], textStyle: { color: text, fontSize: 11 },
                formatter: function (v) { return fmtInt(Math.round(v * v)); },
                inRange: { color: ramp }
            },
            series: [{
                type: 'map', map: 'cpga_world', roam: true, nameProperty: 'iso3',
                scaleLimit: { min: 1, max: 6 },
                emphasis: { label: { show: false }, itemStyle: { areaColor: dark ? '#6b78ff' : '#3b4fc0' } },
                select: { disabled: true },
                itemStyle: { areaColor: land, borderColor: border, borderWidth: 0.5 },
                data: data
            }]
        };
    }

    var CpgaGeoMap = {
        render: function (container, rows, opts) {
            if (!container) { return; }
            opts = opts || {};
            container.__cpgaGeoToken = (container.__cpgaGeoToken || 0) + 1;
            var token = container.__cpgaGeoToken;
            loadECharts().then(loadMap).then(function () {
                if (container.__cpgaGeoToken !== token) { return; } // superseded
                var agg = {};
                (rows || []).forEach(function (r) {
                    var iso3 = resolveIso3(r.code, r.name);
                    if (!iso3) { return; }
                    var v = +r.value || 0;
                    if (!agg[iso3]) { agg[iso3] = { name: iso3, value: 0, label: state.iso3Name[iso3] || r.name || iso3 }; }
                    agg[iso3].value += v;
                });
                // Colour by sqrt(value) so a single dominant country doesn't wash
                // out everyone else; keep the raw figure for legend + tooltip.
                var data = Object.keys(agg).map(function (k) {
                    var raw = agg[k].value;
                    return { name: k, value: Math.sqrt(raw), raw: raw, label: agg[k].label };
                });
                var rawMax = data.reduce(function (m, d) { return Math.max(m, d.raw); }, 0) || 1;
                var scaledMax = Math.sqrt(rawMax) || 1;
                var dark = detectDark(container, opts.dark);

                var chart = window.echarts.getInstanceByDom(container);
                if (!chart) { chart = window.echarts.init(container, null, { renderer: 'canvas' }); }
                chart.setOption(buildOption(data, scaledMax, rawMax, dark, opts.valueLabel), true);
                container.__cpgaChart = chart;

                if (!container.__cpgaResizeBound) {
                    container.__cpgaResizeBound = true;
                    var ro = window.ResizeObserver ? new ResizeObserver(function () {
                        if (container.__cpgaChart) { container.__cpgaChart.resize(); }
                    }) : null;
                    if (ro) { ro.observe(container); }
                    window.addEventListener('resize', function () {
                        if (container.__cpgaChart) { container.__cpgaChart.resize(); }
                    });
                }
                // If the container had zero size at init (hidden tab), resize once visible.
                setTimeout(function () { if (container.__cpgaChart) { container.__cpgaChart.resize(); } }, 60);
            }).catch(function (e) {
                if (container.__cpgaGeoToken !== token) { return; }
                container.innerHTML = '<div class="cpga-geomap-err">Map unavailable: ' + (e && e.message ? e.message : 'load error') + '</div>';
            });
        },
        dispose: function (container) {
            if (container && container.__cpgaChart) {
                try { container.__cpgaChart.dispose(); } catch (e) {}
                container.__cpgaChart = null;
            }
        }
    };

    window.CpgaGeoMap = CpgaGeoMap;
})();
