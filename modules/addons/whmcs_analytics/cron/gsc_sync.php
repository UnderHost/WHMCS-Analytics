<?php
/**
 * WHMCS Analytics — standalone Search Console sync (CLI).
 *
 * Optional: run from system cron for a faster / more aggressive backfill than
 * the once-a-day WHMCS DailyCronJob hook. Example (every 30 min):
 *
 *   * /30 * * * *  php /path/to/whmcs/modules/addons/whmcs_analytics/cron/gsc_sync.php >> /var/log/cpga_sync.log 2>&1
 *
 * CLI-only. Refuses to run over the web.
 *
 * (c) 2026 UnderHost.com — original work.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

// Bootstrap WHMCS (this file is at modules/addons/whmcs_analytics/cron/).
require __DIR__ . '/../../../../init.php';

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../lib/Google.php';
require_once __DIR__ . '/../lib/Storage.php';
require_once __DIR__ . '/../lib/GscStore.php';
require_once __DIR__ . '/../lib/GscSync.php';

$mod = Capsule::table('tbladdonmodules')
    ->where('module', 'whmcs_analytics')
    ->pluck('value', 'setting');
$clientId     = trim($mod['client_id'] ?? '');
$clientSecret = trim($mod['client_secret'] ?? '');

if (!$clientId || !$clientSecret) {
    fwrite(STDERR, "Module not configured (missing OAuth credentials).\n");
    exit(1);
}

// Loop backfill chunks until done or a wall-clock budget is hit.
$deadline = time() + 240; // 4 minutes max per invocation
do {
    $status = \WhmcsAnalytics\GscSync::run($clientId, $clientSecret, [
        'maxSeconds' => 90,
        'maxChunks'  => 4,
    ]);
    $cov = $status['coverage'] ?? [];
    printf(
        "[%s] upserts=%d errors=%d backfill_done=%s coverage=%s..%s (%d rows) elapsed=%ss\n",
        date('c'),
        $status['upserts'] ?? 0,
        count($status['errors'] ?? []),
        !empty($status['backfill_done']) ? 'yes' : 'no',
        $cov['min_date'] ?? '-',
        $cov['max_date'] ?? '-',
        $cov['rows'] ?? 0,
        $status['elapsed'] ?? 0
    );
    foreach (($status['errors'] ?? []) as $err) {
        fwrite(STDERR, "  ! $err\n");
    }
} while (empty($status['backfill_done']) && time() < $deadline);

exit(empty($status['errors']) ? 0 : 2);
