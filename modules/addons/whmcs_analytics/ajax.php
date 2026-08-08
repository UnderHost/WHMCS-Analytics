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
        $labels = $series1 = $series2 = $series3 = [];
        foreach (_cpga_rows($ts) as $row) {
            $d = $row['dims'][0]; // YYYYMMDD
            $labels[]  = substr($d, 4, 2) . '/' . substr($d, 6, 2);
            $series1[] = (int) ($row['mets'][0] ?? 0);
            $series2[] = (int) ($row['mets'][1] ?? 0);
            $series3[] = (int) ($row['mets'][2] ?? 0);
        }
        echo json_encode([
            'type'   => 'graph',
            'kpis'   => [
                ['label' => 'Active Users',   'value' => (int) ($k[0]['value'] ?? 0)],
                ['label' => 'New Users',      'value' => (int) ($k[1]['value'] ?? 0)],
                ['label' => 'Sessions',       'value' => $sessions],
                ['label' => 'Page Views',     'value' => (int) ($k[3]['value'] ?? 0)],
                ['label' => 'Event Count',    'value' => (int) ($k[4]['value'] ?? 0)],
                ['label' => 'Avg Engagement', 'value' => $avgEng],
                ['label' => 'Bounce Rate',    'value' => round((float) ($k[6]['value'] ?? 0) * 100, 1) . '%'],
            ],
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Active Users', 'data' => $series1],
                ['label' => 'Sessions',     'data' => $series2],
                ['label' => 'Page Views',   'data' => $series3],
            ],
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
