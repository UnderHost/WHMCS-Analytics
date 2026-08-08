<?php
/**
 * WHMCS Analytics — WHMCS hooks.
 *
 * Auto-loaded by WHMCS for the whmcs_analytics addon. Registers the admin
 * dashboard widget and runs the daily Search Console sync as part of the WHMCS
 * cron. Safe no-op when history storage isn't configured or Google isn't connected.
 *
 * (c) 2026 UnderHost.com — original work.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Google.php';
require_once __DIR__ . '/lib/Storage.php';
require_once __DIR__ . '/lib/GscStore.php';
require_once __DIR__ . '/lib/GscSync.php';

/**
 * Daily: incremental refresh + one backfill chunk. Over successive days this
 * fills the full history window; thereafter it just keeps recent data current.
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        if (!\WhmcsAnalytics\Storage::isConfigured() || !\WhmcsAnalytics\Google::isConnected()) {
            return;
        }
        $mod = Capsule::table('tbladdonmodules')
            ->where('module', 'whmcs_analytics')
            ->pluck('value', 'setting');
        $clientId     = trim($mod['client_id'] ?? '');
        $clientSecret = trim($mod['client_secret'] ?? '');
        if (\WhmcsAnalytics\Google::authType() !== 'service_account' && (!$clientId || !$clientSecret)) {
            return;
        }

        // Generous budget in cron (no gateway timeout); a few backfill chunks
        // per day so a 12-month backfill completes within a few runs.
        $status = \WhmcsAnalytics\GscSync::run($clientId, $clientSecret, [
            'maxSeconds' => 110,
            'maxChunks'  => 4,
        ]);

        if (!empty($status['errors'])) {
            logActivity('WHMCS Analytics GSC sync: ' . implode(' | ', array_slice($status['errors'], 0, 5)));
        }
    } catch (\Throwable $e) {
        logActivity('WHMCS Analytics GSC sync fatal: ' . $e->getMessage());
    }
});

// ---- Admin dashboard widget -------------------------------------------------
// Register the widget the documented way, via the AdminHomeWidgets hook. This
// is what makes it appear in the admin Home "widgets" list; relying on the
// modules/widgets/ filename auto-discovery is unreliable for module-provided
// widgets, so the class lives in lib/ and is registered explicitly here.
require_once __DIR__ . '/lib/DashboardWidget.php';
add_hook('AdminHomeWidgets', 1, function () {
    return new \WhmcsAnalyticsWidget();
});
