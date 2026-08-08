<?php
/**
 * WHMCS Analytics — authenticated JSON data endpoint for the widget.
 * Bootstraps WHMCS, requires an authenticated admin, and returns GA4 data.
 * Read-only. (c) 2026 UnderHost.com
 */

use WhmcsAnalytics\Google;
use WHMCS\Database\Capsule;

// Bootstrap WHMCS (this file lives at modules/addons/whmcs_analytics/ajax.php)
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/Google.php';
require_once __DIR__ . '/lib/Storage.php';
require_once __DIR__ . '/lib/GscStore.php';
require_once __DIR__ . '/lib/GscSync.php';
require_once __DIR__ . '/lib/GscApi.php';
require_once __DIR__ . '/lib/AiAdvisor.php';

header('Content-Type: application/json; charset=utf-8');
// This is a JSON endpoint: never let a PHP notice/warning/deprecation print into
// the body (it corrupts the JSON and blanks the dashboard). Errors still log.
ini_set('display_errors', '0');

// --- Auth: must be a logged-in admin -------------------------------------
try {
    $currentUser = new \WHMCS\Authentication\CurrentUser();
    if (!$currentUser->isAuthenticatedAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized.']);
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Auth check failed.']);
    exit;
}

// --- Module credentials --------------------------------------------------
$mod = Capsule::table('tbladdonmodules')
    ->where('module', 'whmcs_analytics')
    ->pluck('value', 'setting');
$clientId     = trim($mod['client_id'] ?? '');
$clientSecret = trim($mod['client_secret'] ?? '');

if (Google::authType() !== 'service_account' && (!$clientId || !$clientSecret)) {
    echo json_encode(['error' => 'Module not configured (missing OAuth credentials).']);
    exit;
}
if (!Google::isConnected()) {
    echo json_encode(['error' => 'Not connected to Google. Connect in the module settings.']);
    exit;
}

// --- Search Console routing ----------------------------------------------
// The expanded Search Console UI uses a `gsc` action and reads from the chosen
// history storage backend (except keyword-detail breakdowns, fetched live).
// Handled here and exits; GA4 tabs below are unaffected.
if (isset($_REQUEST['gsc']) && $_REQUEST['gsc'] !== '') {
    \WhmcsAnalytics\GscApi::handle($clientId, $clientSecret);
    exit; // GscApi::handle always exits, but be explicit.
}

// --- AI SEO Advisor routing ----------------------------------------------
// `ai=advise` builds a GA4 + Search Console snapshot and asks the admin's
// configured LLM for recommendations. Consent-gated inside AiAdvisor.
if (isset($_REQUEST['ai']) && $_REQUEST['ai'] !== '') {
    @set_time_limit(120); // GA4/GSC snapshot + one LLM round-trip
    echo json_encode(\WhmcsAnalytics\AiAdvisor::advise($clientId, $clientSecret));
    exit;
}

// --- Params --------------------------------------------------------------
$tab   = preg_replace('/[^a-z_]/', '', $_REQUEST['tab'] ?? 'graph');
$start = _cpga_date($_REQUEST['start'] ?? '', '30daysAgo');
$end   = _cpga_date($_REQUEST['end'] ?? '', 'today');

function _cpga_date($v, $default)
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
        return $v;
    }
    return $default;
}

function _cpga_rows($resp, $dimIndex = 0)
{
    $out = [];
    foreach (($resp['rows'] ?? []) as $r) {
        $dims = array_map(function ($d) { return $d['value'] ?? ''; }, $r['dimensionValues'] ?? []);
        $mets = array_map(function ($m) { return $m['value'] ?? ''; }, $r['metricValues'] ?? []);
        $out[] = ['dims' => $dims, 'mets' => $mets];
    }
    return $out;
}

/**
 * WHMCS business metrics per day (YYYYMMDD-keyed) for [$start,$end], to overlay
 * on the GA4 traffic graph: orders placed, income received, and new signups.
 * Income mirrors WHMCS's own transactions ledger (tblaccounts) in the default
 * currency. Returns ok=false (and empty maps) if anything is unavailable.
 */
function _wa_business_daily($start, $end)
{
    $out = ['ok' => false, 'orders' => [], 'income' => [], 'signups' => [], 'currency' => ['prefix' => '$', 'suffix' => '']];
    try {
        $from = $start . ' 00:00:00';
        $to   = $end . ' 23:59:59';

        foreach (Capsule::table('tblorders')->whereBetween('date', [$from, $to])
                    ->selectRaw("DATE_FORMAT(date,'%Y%m%d') AS d, COUNT(*) AS n")->groupBy('d')->get() as $r) {
            $out['orders'][$r->d] = (int) $r->n;
        }
        foreach (Capsule::table('tblaccounts')->whereBetween('date', [$from, $to])
                    ->selectRaw("DATE_FORMAT(date,'%Y%m%d') AS d, SUM(amountin - amountout) AS inc")->groupBy('d')->get() as $r) {
            $out['income'][$r->d] = (float) $r->inc;
        }
        foreach (Capsule::table('tblclients')->whereBetween('datecreated', [$from, $to])
                    ->selectRaw("DATE_FORMAT(datecreated,'%Y%m%d') AS d, COUNT(*) AS n")->groupBy('d')->get() as $r) {
            $out['signups'][$r->d] = (int) $r->n;
        }
        $c = Capsule::table('tblcurrencies')->where('default', 1)->first();
        if ($c) {
            $out['currency'] = ['prefix' => (string) ($c->prefix ?? '$'), 'suffix' => (string) ($c->suffix ?? '')];
        }
        $out['ok'] = true;
    } catch (\Throwable $e) {
        $out['ok'] = false;
    }
    return $out;
}

/** Absolute origin for the stored Search Console site (handles sc-domain: and URL-prefix). */
function _wa_site_origin()
{
    $site = \WhmcsAnalytics\Google::get('sc_site', '');
    if (strpos($site, 'sc-domain:') === 0) {
        return 'https://' . substr($site, strlen('sc-domain:'));
    }
    return rtrim($site, '/');
}

try {
    $dateRange = [['startDate' => $start, 'endDate' => $end]];

    if ($tab === 'realtime') {
        $rt = Google::runRealtime($clientId, $clientSecret, [
            'dimensions' => [['name' => 'country']],
            'metrics'    => [['name' => 'activeUsers']],
            'limit'      => 20,
        ]);
        $total = 0;
        $rows  = [];
        foreach (_cpga_rows($rt) as $row) {
            $total += (int) ($row['mets'][0] ?? 0);
            $rows[] = ['label' => $row['dims'][0] ?: '(unknown)', 'value' => (int) $row['mets'][0]];
        }
        echo json_encode([
            'type'    => 'realtime',
            'total'   => $total,
            'columns' => ['Country', 'Active users'],
            'rows'    => $rows,
        ]);
        exit;
    }

    if ($tab === 'graph') {
        // KPI totals
        $kpi = Google::runReport($clientId, $clientSecret, [
            'dateRanges' => $dateRange,
            'metrics'    => array_map(function ($m) { return ['name' => $m]; }, [
                'activeUsers', 'newUsers', 'sessions', 'screenPageViews',
                'eventCount', 'userEngagementDuration', 'bounceRate',
            ]),
        ]);
        $k = $kpi['rows'][0]['metricValues'] ?? [];
        $engSecs = (int) ($k[5]['value'] ?? 0);
        $sessions = (int) ($k[2]['value'] ?? 0);
        $avgEng   = $sessions > 0 ? gmdate('H:i:s', (int) round($engSecs / max($sessions, 1))) : '00:00:00';

        // Time series by date
        $ts = Google::runReport($clientId, $clientSecret, [
            'dateRanges' => $dateRange,
            'dimensions' => [['name' => 'date']],
            'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
            'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
            'limit'      => 400,
        ]);
        $labels = $dates = $series1 = $series2 = $series3 = [];
        foreach (_cpga_rows($ts) as $row) {
            $d = $row['dims'][0]; // YYYYMMDD
            $dates[]   = $d;
            $labels[]  = substr($d, 4, 2) . '/' . substr($d, 6, 2);
            $series1[] = (int) ($row['mets'][0] ?? 0);
            $series2[] = (int) ($row['mets'][1] ?? 0);
            $series3[] = (int) ($row['mets'][2] ?? 0);
        }

        // WHMCS business overlay (orders / revenue / signups), aligned to $dates.
        $realStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_REQUEST['start'] ?? '')) ? $_REQUEST['start'] : date('Y-m-d', strtotime('-29 days'));
        $realEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_REQUEST['end'] ?? '')) ? $_REQUEST['end'] : date('Y-m-d');
        $biz = _wa_business_daily($realStart, $realEnd);
        $incomeArr = [];
        $totOrders = $totSignups = 0; $totIncome = 0.0;
        foreach ($dates as $d) {
            $incomeArr[] = round($biz['income'][$d] ?? 0.0, 2);
            $totOrders  += $biz['orders'][$d] ?? 0;
            $totIncome  += $biz['income'][$d] ?? 0.0;
            $totSignups += $biz['signups'][$d] ?? 0;
        }
        $cur   = $biz['currency'];
        $money = $cur['prefix'] . number_format($totIncome, 2) . $cur['suffix'];
        $conv  = $sessions > 0 ? round($totOrders / $sessions * 100, 2) . '%' : '0%';

        $kpis = [
            ['label' => 'Active Users', 'value' => (int) ($k[0]['value'] ?? 0)],
            ['label' => 'New Users',    'value' => (int) ($k[1]['value'] ?? 0)],
            ['label' => 'Sessions',     'value' => $sessions],
            ['label' => 'Page Views',   'value' => (int) ($k[3]['value'] ?? 0)],
            ['label' => 'Bounce Rate',  'value' => round((float) ($k[6]['value'] ?? 0) * 100, 1) . '%'],
        ];
        $datasets = [
            ['label' => 'Active Users', 'data' => $series1],
            ['label' => 'Sessions',     'data' => $series2],
            ['label' => 'Page Views',   'data' => $series3],
        ];
        if ($biz['ok']) {
            $kpis[] = ['label' => 'Orders',     'value' => $totOrders,  'group' => 'business'];
            $kpis[] = ['label' => 'Revenue',    'value' => $money,      'group' => 'business'];
            $kpis[] = ['label' => 'Signups',    'value' => $totSignups, 'group' => 'business'];
            $kpis[] = ['label' => 'Conv. Rate', 'value' => $conv,       'group' => 'business'];
            $datasets[] = ['label' => 'Revenue', 'data' => $incomeArr, 'axis' => 'y1', 'money' => true];
        } else {
            $kpis[] = ['label' => 'Event Count',    'value' => (int) ($k[4]['value'] ?? 0)];
            $kpis[] = ['label' => 'Avg Engagement', 'value' => $avgEng];
        }

        echo json_encode([
            'type'     => 'graph',
            'kpis'     => $kpis,
            'labels'   => $labels,
            'datasets' => $datasets,
            'currency' => $cur,
        ]);
        exit;
    }

    if ($tab === 'alerts') {
        @set_time_limit(60);
        $alerts = [];
        $pct = function ($cur, $prev) { return $prev > 0 ? ($cur - $prev) / $prev * 100.0 : ($cur > 0 ? 100.0 : 0.0); };
        $thr = (int) Google::get('alert_traffic_pct', 20);
        if ($thr < 1) { $thr = 20; }
        $fmtPct = function ($v) { return ($v >= 0 ? '+' : '−') . number_format(abs($v), 0) . '%'; };
        $mk = function ($level, $icon, $title, $detail, $change = null) {
            return ['level' => $level, 'icon' => $icon, 'title' => $title, 'detail' => $detail, 'change' => $change];
        };

        // GA4 traffic totals for a window (relative tokens; ends yesterday to skip partial today).
        $ga = function ($s, $e) use ($clientId, $clientSecret) {
            $r = Google::runReport($clientId, $clientSecret, [
                'dateRanges' => [['startDate' => $s, 'endDate' => $e]],
                'metrics'    => array_map(function ($m) { return ['name' => $m]; }, ['sessions', 'activeUsers', 'screenPageViews', 'bounceRate']),
            ]);
            $m = $r['rows'][0]['metricValues'] ?? [];
            return [
                'sessions' => (int) ($m[0]['value'] ?? 0),
                'users'    => (int) ($m[1]['value'] ?? 0),
                'views'    => (int) ($m[2]['value'] ?? 0),
                'bounce'   => (float) ($m[3]['value'] ?? 0) * 100.0,
            ];
        };
        $cur7 = $ga('7daysAgo', '1daysAgo');   $prev7 = $ga('14daysAgo', '8daysAgo');
        $cur28 = $ga('28daysAgo', '1daysAgo'); $prev28 = $ga('56daysAgo', '29daysAgo');

        // --- Weekly traffic ---
        if ($cur7['sessions'] > 0 || $prev7['sessions'] > 0) {
            $d = $pct($cur7['sessions'], $prev7['sessions']);
            if ($d <= -$thr) {
                $alerts[] = $mk('warning', 'fa-arrow-trend-down', 'Weekly traffic is down',
                    'Sessions fell to ' . number_format($cur7['sessions']) . ' in the last 7 days, from ' . number_format($prev7['sessions']) . ' the week before.', $fmtPct($d));
            } elseif ($d >= $thr) {
                $alerts[] = $mk('good', 'fa-arrow-trend-up', 'Weekly traffic is up',
                    'Sessions rose to ' . number_format($cur7['sessions']) . ' in the last 7 days, from ' . number_format($prev7['sessions']) . ' the week before.', $fmtPct($d));
            }
        }
        $db = $cur7['bounce'] - $prev7['bounce'];
        if ($db >= 5) {
            $alerts[] = $mk('warning', 'fa-person-walking-arrow-right', 'Bounce rate worsened',
                'Bounce rate rose to ' . number_format($cur7['bounce'], 1) . '% — up ' . number_format($db, 1) . ' points versus the previous week.', '+' . number_format($db, 1) . 'pt');
        }
        // --- 28-day trend ---
        if ($cur28['sessions'] > 0 || $prev28['sessions'] > 0) {
            $d28 = $pct($cur28['sessions'], $prev28['sessions']);
            if ($d28 <= -$thr) {
                $alerts[] = $mk('warning', 'fa-chart-line', '28-day traffic trending down',
                    'Sessions over the last 28 days are down vs the prior 28 days (' . number_format($cur28['sessions']) . ' vs ' . number_format($prev28['sessions']) . ').', $fmtPct($d28));
            } elseif ($d28 >= $thr) {
                $alerts[] = $mk('good', 'fa-chart-line', '28-day traffic trending up',
                    'Sessions over the last 28 days are up vs the prior 28 days (' . number_format($cur28['sessions']) . ' vs ' . number_format($prev28['sessions']) . ').', $fmtPct($d28));
            }
        }

        // --- Search Console (only when history is stored) ---
        try {
            if (\WhmcsAnalytics\Storage::isConfigured()) {
                \WhmcsAnalytics\GscStore::ensureSchema();
                if (\WhmcsAnalytics\GscStore::hasData()) {
                    $cs = date('Y-m-d', strtotime('-28 days')); $ce = date('Y-m-d', strtotime('-1 day'));
                    $ps = date('Y-m-d', strtotime('-56 days')); $pe = date('Y-m-d', strtotime('-29 days'));
                    $ct = \WhmcsAnalytics\GscStore::periodTotals($cs, $ce);
                    $pt = \WhmcsAnalytics\GscStore::periodTotals($ps, $pe);
                    if ($ct['clicks'] > 0 || $pt['clicks'] > 0) {
                        $dc = $pct($ct['clicks'], $pt['clicks']);
                        if ($dc <= -$thr) {
                            $alerts[] = $mk('warning', 'fa-magnifying-glass-minus', 'Search clicks are down',
                                'Google search clicks fell to ' . number_format($ct['clicks']) . ' (28 days), from ' . number_format($pt['clicks']) . '.', $fmtPct($dc));
                        } elseif ($dc >= $thr) {
                            $alerts[] = $mk('good', 'fa-magnifying-glass-plus', 'Search clicks are up',
                                'Google search clicks rose to ' . number_format($ct['clicks']) . ' (28 days), from ' . number_format($pt['clicks']) . '.', $fmtPct($dc));
                        }
                    }
                    // compareAll returns raw columns; derive the position change (dP;
                    // + = improved), impressions, and presence like GscApi::records() does.
                    $norm = [];
                    foreach (\WhmcsAnalytics\GscStore::compareAll('query', $cs, $ce, $ps, $pe, 5000) as $r) {
                        $cp = ($r['cur_pos'] ?? null) !== null ? (float) $r['cur_pos'] : null;
                        $pp = ($r['prev_pos'] ?? null) !== null ? (float) $r['prev_pos'] : null;
                        $norm[] = [
                            'k'  => $r['k'],
                            'ci' => (int) ($r['cur_impr'] ?? 0),
                            'cp' => $cp,
                            'pp' => $pp,
                            'dP' => ($cp !== null && $pp !== null) ? ($pp - $cp) : null,
                            'mv' => (int) ($r['in_cur'] ?? 0) === 0 ? 'lost' : ((int) ($r['in_prev'] ?? 0) === 0 ? 'new' : 'both'),
                        ];
                    }
                    $dec = array_values(array_filter($norm, function ($r) {
                        return $r['dP'] !== null && $r['dP'] <= -3 && $r['ci'] >= 50 && $r['mv'] !== 'new' && $r['mv'] !== 'lost';
                    }));
                    usort($dec, function ($a, $b) { return $a['dP'] <=> $b['dP']; });
                    foreach (array_slice($dec, 0, 3) as $r) {
                        $alerts[] = $mk('warning', 'fa-arrow-down-wide-short', 'Ranking drop: “' . $r['k'] . '”',
                            'Average position slipped from ' . round($r['pp'], 1) . ' to ' . round($r['cp'], 1) . ' with ' . number_format($r['ci']) . ' impressions.',
                            '−' . number_format(abs($r['dP']), 1));
                    }
                    $opp = array_values(array_filter($norm, function ($r) {
                        return $r['cp'] !== null && $r['cp'] > 10 && $r['cp'] <= 20 && $r['ci'] >= 50;
                    }));
                    usort($opp, function ($a, $b) { return $b['ci'] <=> $a['ci']; });
                    foreach (array_slice($opp, 0, 3) as $r) {
                        $alerts[] = $mk('info', 'fa-bullseye', 'Opportunity: “' . $r['k'] . '” is on page 2',
                            'Ranks ' . round($r['cp'], 1) . ' with ' . number_format($r['ci']) . ' impressions — a small push could reach page 1.');
                    }
                }
            }
        } catch (\Throwable $e) { /* GSC alerts are best-effort */ }

        // --- WHMCS revenue (last 7 days vs prior 7) ---
        try {
            $biz = _wa_business_daily(date('Y-m-d', strtotime('-13 days')), date('Y-m-d'));
            if ($biz['ok']) {
                $cut = date('Ymd', strtotime('-6 days'));
                $lo  = date('Ymd', strtotime('-13 days'));
                $curR = $prevR = 0.0;
                foreach ($biz['income'] as $k => $v) {
                    if ($k >= $cut) { $curR += $v; } elseif ($k >= $lo) { $prevR += $v; }
                }
                if ($curR > 0 || $prevR > 0) {
                    $cur = $biz['currency'];
                    $money = function ($v) use ($cur) { return $cur['prefix'] . number_format($v, 2) . $cur['suffix']; };
                    $dr = $pct($curR, $prevR);
                    if ($dr <= -$thr) {
                        $alerts[] = $mk('warning', 'fa-money-bill-trend-up', 'Revenue is down this week',
                            'Payments received fell to ' . $money($curR) . ' in the last 7 days, from ' . $money($prevR) . ' the week before.', $fmtPct($dr));
                    } elseif ($dr >= $thr) {
                        $alerts[] = $mk('good', 'fa-money-bill-trend-up', 'Revenue is up this week',
                            'Payments received rose to ' . $money($curR) . ' in the last 7 days, from ' . $money($prevR) . ' the week before.', $fmtPct($dr));
                    }
                }
            }
        } catch (\Throwable $e) { /* business alerts are best-effort */ }

        $rank = ['critical' => 0, 'warning' => 1, 'good' => 2, 'info' => 3];
        usort($alerts, function ($a, $b) use ($rank) { return ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9); });

        echo json_encode([
            'type'      => 'alerts',
            'generated' => date('M j, Y g:i a'),
            'alerts'    => $alerts,
        ]);
        exit;
    }

    if ($tab === 'indexing') {
        @set_time_limit(60);
        $site = Google::get('sc_site', '');
        if (!$site) {
            echo json_encode(['error' => 'Select a Search Console site in Settings & connection first.']);
            exit;
        }

        // Single-URL inspection (triggered per row / manual box).
        $inspectUrl = trim($_REQUEST['url'] ?? '');
        if ($inspectUrl !== '') {
            if (!preg_match('#^https?://#i', $inspectUrl)) {
                $inspectUrl = _wa_site_origin() . '/' . ltrim($inspectUrl, '/');
            }
            $res = Google::urlInspect($clientId, $clientSecret, $inspectUrl);
            if (isset($res['error'])) {
                echo json_encode(['error' => Google::errText($res)]);
                exit;
            }
            $r   = $res['inspectionResult'] ?? [];
            $idx = $r['indexStatusResult'] ?? [];
            $mob = $r['mobileUsabilityResult'] ?? [];
            echo json_encode([
                'type'   => 'indexing',
                'mode'   => 'inspect',
                'url'    => $inspectUrl,
                'result' => [
                    'verdict'         => $idx['verdict'] ?? 'VERDICT_UNSPECIFIED',
                    'coverageState'   => $idx['coverageState'] ?? '',
                    'robotsTxtState'  => $idx['robotsTxtState'] ?? '',
                    'indexingState'   => $idx['indexingState'] ?? '',
                    'pageFetchState'  => $idx['pageFetchState'] ?? '',
                    'lastCrawlTime'   => $idx['lastCrawlTime'] ?? '',
                    'googleCanonical' => $idx['googleCanonical'] ?? '',
                    'userCanonical'   => $idx['userCanonical'] ?? '',
                    'crawledAs'       => $idx['crawledAs'] ?? '',
                    'mobileVerdict'   => $mob['verdict'] ?? '',
                    'inspectionLink'  => $r['inspectionResultLink'] ?? '',
                ],
            ]);
            exit;
        }

        // List mode: top pages by impressions (28 days) for the admin to inspect.
        $resp = Google::searchAnalytics($clientId, $clientSecret, [
            'startDate'  => date('Y-m-d', strtotime('-28 days')),
            'endDate'    => date('Y-m-d', strtotime('-1 day')),
            'dimensions' => ['page'],
            'rowLimit'   => 100,
        ]);
        if (isset($resp['error'])) {
            echo json_encode(['error' => Google::errText($resp)]);
            exit;
        }
        $rows = [];
        foreach (($resp['rows'] ?? []) as $r) {
            $rows[] = [
                'url'         => $r['keys'][0] ?? '',
                'clicks'      => (int) ($r['clicks'] ?? 0),
                'impressions' => (int) ($r['impressions'] ?? 0),
            ];
        }
        usort($rows, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });
        echo json_encode([
            'type'   => 'indexing',
            'mode'   => 'list',
            'site'   => $site,
            'origin' => _wa_site_origin(),
            'rows'   => array_slice($rows, 0, 25),
        ]);
        exit;
    }

    if ($tab === 'keywords') {
        // Search Console needs real YYYY-MM-DD dates (no relative tokens).
        $scStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_REQUEST['start'] ?? '')) ? $_REQUEST['start'] : date('Y-m-d', strtotime('-30 days'));
        $scEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_REQUEST['end'] ?? '')) ? $_REQUEST['end'] : date('Y-m-d');
        $resp = Google::searchAnalytics($clientId, $clientSecret, [
            'startDate'  => $scStart,
            'endDate'    => $scEnd,
            'dimensions' => ['query'],
            'rowLimit'   => 50,
            'orderBy'    => [['field' => 'clicks', 'descending' => true]],
        ]);
        if (isset($resp['error'])) {
            echo json_encode(['error' => Google::errText($resp)]);
            exit;
        }
        $rows = [];
        foreach (($resp['rows'] ?? []) as $r) {
            $rows[] = [
                $r['keys'][0] ?? '(unknown)',
                number_format((int) ($r['clicks'] ?? 0)),
                number_format((int) ($r['impressions'] ?? 0)),
                round(((float) ($r['ctr'] ?? 0)) * 100, 1) . '%',
                round((float) ($r['position'] ?? 0), 1),
            ];
        }
        echo json_encode([
            'type'    => 'table',
            'columns' => ['Query', 'Clicks', 'Impressions', 'CTR', 'Avg Position'],
            'rows'    => $rows,
        ]);
        exit;
    }

    if ($tab === 'countries') {
        // Countries power a world-map heatmap as well as the table, so we pull
        // the ISO country code (countryId → alpha-2) alongside the display name.
        $resp = Google::runReport($clientId, $clientSecret, [
            'dateRanges' => $dateRange,
            'dimensions' => [['name' => 'country'], ['name' => 'countryId']],
            'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions']],
            'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
            'limit'      => 250,
        ]);
        if (isset($resp['error'])) {
            echo json_encode(['error' => Google::errText($resp)]);
            exit;
        }
        $rows = $mapRows = [];
        foreach (_cpga_rows($resp) as $row) {
            $name  = $row['dims'][0] ?: '(not set)';
            $code  = strtoupper($row['dims'][1] ?? '');
            $users = (int) ($row['mets'][0] ?? 0);
            $sess  = (int) ($row['mets'][1] ?? 0);
            $rows[] = [$name, number_format($users), number_format($sess)];
            if ($code !== '' && $code !== '(NOT SET)') {
                $mapRows[] = ['code' => $code, 'name' => $name, 'value' => $users];
            }
        }
        echo json_encode([
            'type'       => 'geo',
            'columns'    => ['Country', 'Users', 'Sessions'],
            'rows'       => $rows,
            'map'        => $mapRows,
            'valueLabel' => 'Users',
        ]);
        exit;
    }

    // Table tabs: dimension -> metric(s)
    $map = [
        'pages'             => ['dim' => 'pagePath',         'mets' => ['screenPageViews', 'activeUsers'], 'cols' => ['Page', 'Views', 'Users']],
        'browsers'         => ['dim' => 'browser',           'mets' => ['activeUsers', 'sessions'],        'cols' => ['Browser', 'Users', 'Sessions']],
        'languages'        => ['dim' => 'language',          'mets' => ['activeUsers'],                    'cols' => ['Language', 'Users']],
        'operating_systems'=> ['dim' => 'operatingSystem',   'mets' => ['activeUsers'],                    'cols' => ['Operating System', 'Users']],
        'devices'          => ['dim' => 'deviceCategory',    'mets' => ['activeUsers', 'sessions'],        'cols' => ['Device', 'Users', 'Sessions']],
        'screen_resolution'=> ['dim' => 'screenResolution',  'mets' => ['activeUsers'],                    'cols' => ['Screen Resolution', 'Users']],
        'source'           => ['dim' => 'sessionSourceMedium','mets' => ['sessions', 'activeUsers'],       'cols' => ['Source / Medium', 'Sessions', 'Users']],
    ];
    if (!isset($map[$tab])) {
        echo json_encode(['error' => 'Unknown tab.']);
        exit;
    }
    $cfg  = $map[$tab];
    $resp = Google::runReport($clientId, $clientSecret, [
        'dateRanges' => $dateRange,
        'dimensions' => [['name' => $cfg['dim']]],
        'metrics'    => array_map(function ($m) { return ['name' => $m]; }, $cfg['mets']),
        'orderBys'   => [['metric' => ['metricName' => $cfg['mets'][0]], 'desc' => true]],
        'limit'      => 50,
    ]);
    if (isset($resp['error'])) {
        echo json_encode(['error' => Google::errText($resp)]);
        exit;
    }
    $rows = [];
    foreach (_cpga_rows($resp) as $row) {
        $cells = [$row['dims'][0] ?: '(not set)'];
        foreach ($row['mets'] as $m) {
            $cells[] = number_format((float) $m);
        }
        $rows[] = $cells;
    }
    echo json_encode(['type' => 'table', 'columns' => $cfg['cols'], 'rows' => $rows]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
