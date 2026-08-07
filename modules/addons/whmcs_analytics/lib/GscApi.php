<?php
/**
 * WHMCS Analytics — Search Console read/API layer (Bunny-backed).
 *
 * Handles the dashboard's Search Console AJAX actions. All reads come from the
 * Bunny (libSQL) historical store, so the UI never re-requests history from
 * Google. The keyword-detail breakdowns (pages/countries/devices for one query)
 * are fetched live from Google on demand, since they are cheap and per-keyword.
 *
 * Row payloads use compact keys (documented in gsc.js):
 *   k   dimension value        mv   movement (improved|declined|unchanged|new|lost)
 *   cc  current clicks         pc   previous clicks
 *   ci  current impressions    pi   previous impressions
 *   ct  current CTR (0..1)     pt   previous CTR
 *   cp  current position       pp   previous position
 *   dP  position change (prev - cur; + = better)
 *   dC  click change           dI  impression change    dT  CTR change
 *
 * (c) 2026 UnderHost.com — original work.
 */

namespace WhmcsAnalytics;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class GscApi
{
    /** Entry point: dispatch a `gsc` action, echo JSON, exit. */
    public static function handle($clientId, $clientSecret)
    {
        $action = preg_replace('/[^a-z_]/', '', $_REQUEST['gsc'] ?? '');

        try {
            if (!Storage::isConfigured() && $action !== 'status') {
                self::json(['error' => 'History storage is not configured. ' . Storage::unavailableReason()]);
            }
            switch ($action) {
                case 'status':        self::json(self::status()); break;
                case 'sync':          self::json(self::sync($clientId, $clientSecret)); break;
                case 'view':          self::json(self::view()); break;
                case 'summary':       self::json(self::summary()); break;
                case 'movers':        self::json(self::movers()); break;
                case 'opportunities': self::json(self::opportunities()); break;
                case 'detail':        self::json(self::detail($clientId, $clientSecret)); break;
                default:              self::json(['error' => 'Unknown Search Console action.']);
            }
        } catch (\Throwable $e) {
            self::json(['error' => $e->getMessage()]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Actions                                                             */
    /* ------------------------------------------------------------------ */

    protected static function status()
    {
        $configured = Storage::isConfigured();
        $out = [
            'storage_configured' => $configured,
            'backend'            => Storage::backend(),
            'backend_label'      => Storage::backendLabel(),
            'site'               => Google::get('sc_site', ''),
            'has_data'           => false,
            'coverage'           => ['min_date' => null, 'max_date' => null, 'rows' => 0],
            'sync'               => null,
        ];
        if (!$configured) {
            $out['reason'] = Storage::unavailableReason();
            return $out;
        }
        try {
            GscStore::ensureSchema();
            $out['has_data'] = GscStore::hasData();
            $out['coverage'] = GscStore::coverage();
            $site = Google::get('sc_site', '');
            if ($site) {
                $out['sync'] = GscStore::getState($site);
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    protected static function sync($clientId, $clientSecret)
    {
        // Web-triggered: keep it short so the request never times out; the
        // front-end loops until backfill_done.
        return GscSync::run($clientId, $clientSecret, [
            'maxSeconds' => 25,
            'maxChunks'  => 1,
        ]);
    }

    protected static function view()
    {
        [$curStart, $curEnd, $prevStart, $prevEnd] = self::ranges();
        $dim = self::dimParam();

        // "date" view = daily totals time series (no period comparison).
        if ($dim === 'date') {
            $rows = Storage::query(
                'SELECT d, clicks, impressions, position FROM gsc_totals_daily WHERE d >= ? AND d <= ? ORDER BY d ASC',
                [$curStart, $curEnd]
            );
            $out = [];
            foreach ($rows as $r) {
                $impr = (int) $r['impressions'];
                $out[] = [
                    'k'  => $r['d'],
                    'cc' => (int) $r['clicks'],
                    'ci' => $impr,
                    'ct' => $impr > 0 ? ((int) $r['clicks']) / $impr : 0,
                    'cp' => $r['position'] !== null ? round((float) $r['position'], 2) : null,
                ];
            }
            return [
                'dim'   => 'date',
                'rows'  => $out,
                'total' => count($out),
                'range' => ['cur' => [$curStart, $curEnd], 'prev' => [$prevStart, $prevEnd]],
            ];
        }

        $records = self::records($dim, $curStart, $curEnd, $prevStart, $prevEnd);
        $records = self::applyFilters($records);
        $total   = count($records);
        self::sortRecords($records, $_REQUEST['sort'] ?? 'impressions', ($_REQUEST['dir'] ?? 'desc'));

        $perPage = max(1, min(200, (int) ($_REQUEST['perPage'] ?? 50)));
        $offset  = max(0, (int) ($_REQUEST['offset'] ?? 0));
        $page    = array_slice($records, $offset, $perPage);

        return [
            'dim'     => $dim,
            'rows'    => $page,
            'total'   => $total,
            'offset'  => $offset,
            'perPage' => $perPage,
            'range'   => ['cur' => [$curStart, $curEnd], 'prev' => [$prevStart, $prevEnd]],
        ];
    }

    protected static function summary()
    {
        [$curStart, $curEnd, $prevStart, $prevEnd] = self::ranges();
        $dim = self::dimParam();
        if ($dim === 'date') { $dim = 'query'; }
        $t = self::moveThreshold();
        $sum = GscStore::summary($dim, $curStart, $curEnd, $prevStart, $prevEnd, $t);
        $curTotals  = GscStore::periodTotals($curStart, $curEnd);
        $prevTotals = GscStore::periodTotals($prevStart, $prevEnd);
        return [
            'dim'        => $dim,
            'summary'    => $sum,
            'totals'     => ['cur' => $curTotals, 'prev' => $prevTotals],
            'range'      => ['cur' => [$curStart, $curEnd], 'prev' => [$prevStart, $prevEnd]],
            'threshold'  => $t,
        ];
    }

    protected static function movers()
    {
        [$curStart, $curEnd, $prevStart, $prevEnd] = self::ranges();
        $recs   = self::records('query', $curStart, $curEnd, $prevStart, $prevEnd);
        $ctrAvg = GscStore::periodTotals($curStart, $curEnd)['ctr'];
        $N = 10;

        $both = array_values(array_filter($recs, function ($r) { return $r['mv'] !== 'new' && $r['mv'] !== 'lost' && $r['dP'] !== null; }));
        $cur  = array_values(array_filter($recs, function ($r) { return $r['mv'] !== 'lost'; }));

        $byImprovement = $both; usort($byImprovement, function ($a, $b) { return $b['dP'] <=> $a['dP']; });
        $byDecline     = $both; usort($byDecline, function ($a, $b) { return $a['dP'] <=> $b['dP']; });
        $imprGain      = $cur;  usort($imprGain, function ($a, $b) { return $b['dI'] <=> $a['dI']; });
        $imprLoss      = $recs; usort($imprLoss, function ($a, $b) { return $a['dI'] <=> $b['dI']; });

        $highImprLowCtr = array_values(array_filter($cur, function ($r) use ($ctrAvg) {
            return $r['ci'] >= 100 && $r['cp'] !== null && $r['ct'] < $ctrAvg;
        }));
        usort($highImprLowCtr, function ($a, $b) { return $b['ci'] <=> $a['ci']; });

        $closeToPageOne = array_values(array_filter($cur, function ($r) {
            return $r['cp'] !== null && $r['cp'] > 10 && $r['cp'] <= 20;
        }));
        usort($closeToPageOne, function ($a, $b) { return $b['ci'] <=> $a['ci']; });

        return [
            'ctr_avg' => $ctrAvg,
            'movers'  => [
                'improvements'      => array_slice($byImprovement, 0, $N),
                'declines'          => array_slice($byDecline, 0, $N),
                'impressions_gained'=> array_slice($imprGain, 0, $N),
                'impressions_lost'  => array_slice($imprLoss, 0, $N),
                'high_impr_low_ctr' => array_slice($highImprLowCtr, 0, $N),
                'close_to_page_one' => array_slice($closeToPageOne, 0, $N),
            ],
            'range' => ['cur' => [$curStart, $curEnd], 'prev' => [$prevStart, $prevEnd]],
        ];
    }

    protected static function opportunities()
    {
        [$curStart, $curEnd, $prevStart, $prevEnd] = self::ranges();
        $recs   = self::records('query', $curStart, $curEnd, $prevStart, $prevEnd);
        $ctrAvg = GscStore::periodTotals($curStart, $curEnd)['ctr'];

        // Configurable thresholds.
        $ctrMinImpr   = (int) Google::get('opp_ctr_min_impr', 100);
        $poMinImpr    = (int) Google::get('opp_pageone_min_impr', 50);
        $decMinImpr   = (int) Google::get('opp_decline_min_impr', 50);
        $decPlaces    = (float) Google::get('opp_decline_places', 3);
        $ctrThreshold = Google::get('opp_ctr_threshold', '');
        $ctrCut       = ($ctrThreshold !== '' && $ctrThreshold !== null) ? ((float) $ctrThreshold) / 100.0 : $ctrAvg;

        $ctrOpp = array_values(array_filter($recs, function ($r) use ($ctrMinImpr, $ctrCut) {
            return $r['ci'] >= $ctrMinImpr && $r['cp'] !== null && $r['cp'] >= 1 && $r['cp'] <= 10 && $r['ct'] < $ctrCut;
        }));
        usort($ctrOpp, function ($a, $b) { return $b['ci'] <=> $a['ci']; });

        $pageOne = array_values(array_filter($recs, function ($r) use ($poMinImpr) {
            return $r['ci'] >= $poMinImpr && $r['cp'] !== null && $r['cp'] > 10 && $r['cp'] <= 20;
        }));
        usort($pageOne, function ($a, $b) { return $b['ci'] <=> $a['ci']; });

        $declining = array_values(array_filter($recs, function ($r) use ($decMinImpr, $decPlaces) {
            return $r['mv'] !== 'new' && $r['mv'] !== 'lost' && $r['dP'] !== null
                && $r['dP'] <= -$decPlaces && $r['ci'] >= $decMinImpr;
        }));
        usort($declining, function ($a, $b) { return $a['dP'] <=> $b['dP']; });

        $emerging = array_values(array_filter($recs, function ($r) {
            $imprUp = ($r['pi'] > 0) ? (($r['dI'] / max(1, $r['pi'])) >= 0.5) : ($r['ci'] >= 20);
            $improved = ($r['mv'] === 'new') || ($r['dP'] !== null && $r['dP'] > 0);
            return $r['mv'] !== 'lost' && $imprUp && $improved && $r['ci'] >= 10;
        }));
        usort($emerging, function ($a, $b) { return $b['dI'] <=> $a['dI']; });

        $N = 15;
        return [
            'ctr_avg'    => $ctrAvg,
            'thresholds' => [
                'ctr_min_impr'    => $ctrMinImpr,
                'pageone_min_impr'=> $poMinImpr,
                'decline_min_impr'=> $decMinImpr,
                'decline_places'  => $decPlaces,
                'ctr_cut'         => $ctrCut,
            ],
            'groups' => [
                'ctr_opportunity' => array_slice($ctrOpp, 0, $N),
                'page_one'        => array_slice($pageOne, 0, $N),
                'declining'       => array_slice($declining, 0, $N),
                'emerging'        => array_slice($emerging, 0, $N),
            ],
            'range' => ['cur' => [$curStart, $curEnd], 'prev' => [$prevStart, $prevEnd]],
        ];
    }

    protected static function detail($clientId, $clientSecret)
    {
        $query = (string) ($_REQUEST['query'] ?? '');
        if ($query === '') {
            return ['error' => 'No query specified.'];
        }
        [$curStart, $curEnd, $prevStart, $prevEnd] = self::ranges();

        $weekly = GscStore::queryWeekly($query, null, null, 26);
        $meta   = GscStore::queryMeta($query);

        // Current vs previous *week* from the weekly series.
        $n    = count($weekly);
        $cw   = $n >= 1 ? $weekly[$n - 1] : null;
        $pw   = $n >= 2 ? $weekly[$n - 2] : null;
        $kpi  = self::weekKpis($cw, $pw);

        // Volatility = std-dev of weekly positions.
        $positions = [];
        foreach ($weekly as $w) { if ($w['position'] !== null) { $positions[] = (float) $w['position']; } }
        $volatility = self::stddev($positions);

        // Best-performing week (lowest position, then most clicks).
        $bestWeek = null;
        foreach ($weekly as $w) {
            if ($w['position'] === null) { continue; }
            if ($bestWeek === null || $w['position'] < $bestWeek['position']
                || ($w['position'] == $bestWeek['position'] && $w['clicks'] > $bestWeek['clicks'])) {
                $bestWeek = $w;
            }
        }

        // Live breakdowns for this keyword (top 10 each) over the current range.
        $breakdowns = [
            'pages'     => self::keywordBreakdown($clientId, $clientSecret, $query, 'page', $curStart, $curEnd),
            'countries' => self::keywordBreakdown($clientId, $clientSecret, $query, 'country', $curStart, $curEnd),
            'devices'   => self::keywordBreakdown($clientId, $clientSecret, $query, 'device', $curStart, $curEnd),
        ];

        return [
            'query'      => $query,
            'weekly'     => $weekly,
            'kpi'        => $kpi,
            'meta'       => $meta,
            'volatility' => round($volatility, 2),
            'best_week'  => $bestWeek,
            'breakdowns' => $breakdowns,
            'range'      => ['cur' => [$curStart, $curEnd], 'prev' => [$prevStart, $prevEnd]],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Record building / filtering / sorting                               */
    /* ------------------------------------------------------------------ */

    /** Build computed comparison records for a dimension. */
    protected static function records($dim, $curStart, $curEnd, $prevStart, $prevEnd)
    {
        $raw = GscStore::compareAll($dim, $curStart, $curEnd, $prevStart, $prevEnd, 5000);
        $t   = self::moveThreshold();
        $out = [];
        foreach ($raw as $r) {
            $inCur  = (int) $r['in_cur'] === 1;
            $inPrev = (int) $r['in_prev'] === 1;
            $cc = (int) $r['cur_clicks'];  $ci = (int) $r['cur_impr'];
            $pc = (int) $r['prev_clicks']; $pi = (int) $r['prev_impr'];
            $cp = $r['cur_pos'] !== null ? round((float) $r['cur_pos'], 2) : null;
            $pp = $r['prev_pos'] !== null ? round((float) $r['prev_pos'], 2) : null;
            $ct = $ci > 0 ? $cc / $ci : 0.0;
            $pt = $pi > 0 ? $pc / $pi : 0.0;

            $dP = ($inCur && $inPrev && $cp !== null && $pp !== null) ? round($pp - $cp, 2) : null;

            if ($inCur && !$inPrev)      { $mv = 'new'; }
            elseif (!$inCur && $inPrev)  { $mv = 'lost'; }
            elseif ($dP !== null && $dP >= $t)  { $mv = 'improved'; }
            elseif ($dP !== null && $dP <= -$t) { $mv = 'declined'; }
            else                         { $mv = 'unchanged'; }

            $out[] = [
                'k'  => $r['k'],
                'cc' => $cc, 'ci' => $ci, 'ct' => round($ct, 4), 'cp' => $cp,
                'pc' => $pc, 'pi' => $pi, 'pt' => round($pt, 4), 'pp' => $pp,
                'dP' => $dP,
                'dC' => $cc - $pc,
                'dI' => $ci - $pi,
                'dT' => round($ct - $pt, 4),
                'mv' => $mv,
            ];
        }
        return $out;
    }

    protected static function applyFilters(array $records)
    {
        $q        = trim((string) ($_REQUEST['q'] ?? ''));
        $qLower   = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
        $minImpr  = (int) ($_REQUEST['minImpr'] ?? 0);
        $minClk   = (int) ($_REQUEST['minClicks'] ?? 0);
        $posMin   = ($_REQUEST['posMin'] ?? '') !== '' ? (float) $_REQUEST['posMin'] : null;
        $posMax   = ($_REQUEST['posMax'] ?? '') !== '' ? (float) $_REQUEST['posMax'] : null;
        $movement = preg_replace('/[^a-z]/', '', $_REQUEST['movement'] ?? 'any');

        return array_values(array_filter($records, function ($r) use ($q, $qLower, $minImpr, $minClk, $posMin, $posMax, $movement) {
            if ($q !== '') {
                $k = function_exists('mb_strtolower') ? mb_strtolower((string) $r['k']) : strtolower((string) $r['k']);
                if (strpos($k, $qLower) === false) { return false; }
            }
            if ($minImpr > 0 && $r['ci'] < $minImpr) { return false; }
            if ($minClk > 0 && $r['cc'] < $minClk) { return false; }
            if ($posMin !== null || $posMax !== null) {
                if ($r['cp'] === null) { return false; }
                if ($posMin !== null && $r['cp'] < $posMin) { return false; }
                if ($posMax !== null && $r['cp'] > $posMax) { return false; }
            }
            if ($movement !== 'any' && $movement !== '') {
                if ($r['mv'] !== $movement) { return false; }
            }
            return true;
        }));
    }

    protected static function sortRecords(array &$records, $sort, $dir)
    {
        $map = [
            'clicks'      => 'cc',
            'impressions' => 'ci',
            'ctr'         => 'ct',
            'position'    => 'cp',
            'posChange'   => 'dP',
            'clickChange' => 'dC',
            'imprChange'  => 'dI',
            'ctrChange'   => 'dT',
        ];
        $key  = $map[$sort] ?? 'ci';
        $desc = (strtolower($dir) !== 'asc');

        usort($records, function ($a, $b) use ($key, $desc) {
            $av = $a[$key]; $bv = $b[$key];
            // Nulls always sort to the bottom regardless of direction.
            $an = ($av === null); $bn = ($bv === null);
            if ($an && $bn) { return 0; }
            if ($an) { return 1; }
            if ($bn) { return -1; }
            if ($av == $bv) { return 0; }
            $cmp = ($av < $bv) ? -1 : 1;
            return $desc ? -$cmp : $cmp;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Detail helpers                                                      */
    /* ------------------------------------------------------------------ */

    protected static function weekKpis($cur, $prev)
    {
        $cp = $cur ? $cur['position'] : null;
        $pp = $prev ? $prev['position'] : null;
        $cc = $cur ? (int) $cur['clicks'] : 0;
        $pc = $prev ? (int) $prev['clicks'] : 0;
        $ci = $cur ? (int) $cur['impressions'] : 0;
        $pi = $prev ? (int) $prev['impressions'] : 0;
        $ct = $cur ? (float) $cur['ctr'] : 0.0;
        $pt = $prev ? (float) $prev['ctr'] : 0.0;
        return [
            'cur_pos' => $cp, 'prev_pos' => $pp,
            'pos_change' => ($cp !== null && $pp !== null) ? round($pp - $cp, 2) : null,
            'cur_clicks' => $cc, 'prev_clicks' => $pc, 'click_change' => $cc - $pc,
            'cur_impr' => $ci, 'prev_impr' => $pi, 'impr_change' => $ci - $pi,
            'cur_ctr' => round($ct, 4), 'prev_ctr' => round($pt, 4), 'ctr_change' => round($ct - $pt, 4),
        ];
    }

    protected static function keywordBreakdown($clientId, $clientSecret, $query, $dim, $start, $end)
    {
        try {
            $res = Google::searchAnalytics($clientId, $clientSecret, [
                'startDate'  => $start,
                'endDate'    => $end,
                'dimensions' => [$dim],
                'rowLimit'   => 10,
                'dimensionFilterGroups' => [[
                    'filters' => [[
                        'dimension'  => 'query',
                        'operator'   => 'equals',
                        'expression' => $query,
                    ]],
                ]],
            ]);
            if (isset($res['error'])) {
                return [];
            }
            $out = [];
            foreach (($res['rows'] ?? []) as $r) {
                $impr = (int) round($r['impressions'] ?? 0);
                $out[] = [
                    'k'  => $r['keys'][0] ?? '',
                    'cc' => (int) round($r['clicks'] ?? 0),
                    'ci' => $impr,
                    'ct' => round((float) ($r['ctr'] ?? 0), 4),
                    'cp' => round((float) ($r['position'] ?? 0), 2),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function stddev(array $vals)
    {
        $n = count($vals);
        if ($n < 2) { return 0.0; }
        $mean = array_sum($vals) / $n;
        $sum = 0.0;
        foreach ($vals as $v) { $sum += ($v - $mean) ** 2; }
        return sqrt($sum / $n);
    }

    /* ------------------------------------------------------------------ */
    /* Request parsing                                                     */
    /* ------------------------------------------------------------------ */

    protected static function dimParam()
    {
        $dim = preg_replace('/[^a-z]/', '', $_REQUEST['dim'] ?? 'query');
        $allowed = ['query', 'page', 'country', 'device', 'appearance', 'date'];
        return in_array($dim, $allowed, true) ? $dim : 'query';
    }

    protected static function moveThreshold()
    {
        $t = (float) Google::get('move_threshold', 1.0);
        return $t > 0 ? $t : 1.0;
    }

    /** Resolve [curStart, curEnd, prevStart, prevEnd] from the request. */
    protected static function ranges()
    {
        $curEnd   = self::date($_REQUEST['end'] ?? '', date('Y-m-d', strtotime('-3 days')));
        $curStart = self::date($_REQUEST['start'] ?? '', date('Y-m-d', strtotime('-30 days', strtotime($curEnd))));
        if (strcmp($curStart, $curEnd) > 0) { [$curStart, $curEnd] = [$curEnd, $curStart]; }

        // Comparison: explicit, else previous period of equal length.
        $len = (int) ((strtotime($curEnd) - strtotime($curStart)) / 86400) + 1;
        $defPrevEnd   = date('Y-m-d', strtotime('-1 day', strtotime($curStart)));
        $defPrevStart = date('Y-m-d', strtotime('-' . ($len - 1) . ' days', strtotime($defPrevEnd)));
        $prevEnd   = self::date($_REQUEST['cmpEnd'] ?? '', $defPrevEnd);
        $prevStart = self::date($_REQUEST['cmpStart'] ?? '', $defPrevStart);
        if (strcmp($prevStart, $prevEnd) > 0) { [$prevStart, $prevEnd] = [$prevEnd, $prevStart]; }

        return [$curStart, $curEnd, $prevStart, $prevEnd];
    }

    protected static function date($v, $default)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : $default;
    }

    protected static function json($data)
    {
        echo json_encode($data);
        exit;
    }
}
