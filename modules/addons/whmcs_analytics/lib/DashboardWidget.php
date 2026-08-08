<?php
/**
 * WHMCS Analytics — admin dashboard widget (Google Analytics 4).
 * Renders the tabbed analytics UI; data is loaded via the module's ajax.php.
 * (c) 2026 UnderHost.com — original work.
 */

use WHMCS\Module\AbstractWidget;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// Defensive: if the widget class is already declared (e.g. a stale copy left in
// modules/widgets/ from an older version), do not redeclare it — that would be a
// fatal error that white-screens the admin. Bail out of this include instead.
if (class_exists('WhmcsAnalyticsWidget', false)) {
    return;
}

require_once __DIR__ . '/Google.php';

class WhmcsAnalyticsWidget extends AbstractWidget
{
    protected $title = 'Google Analytics';
    protected $description = 'Google Analytics 4 overview for your WHMCS site.';
    protected $weight = 60;
    protected $columns = 3;
    protected $cache = false;
    protected $requiredPermission = 'Main Homepage';

    public function getData()
    {
        $mod = Capsule::table('tbladdonmodules')
            ->where('module', 'whmcs_analytics')
            ->pluck('value', 'setting');

        $hasTable = Capsule::schema()->hasTable(\WhmcsAnalytics\Google::TABLE);
        if ($hasTable && \WhmcsAnalytics\Google::authType() === 'service_account') {
            $configured = \WhmcsAnalytics\Google::serviceAccountKey() !== null;
        } else {
            $configured = !empty($mod['client_id']) && !empty($mod['client_secret']);
        }
        $connected = false;
        $property  = '';
        if ($hasTable && $configured) {
            $connected = \WhmcsAnalytics\Google::isConnected();
            $property  = \WhmcsAnalytics\Google::get('property_name', '');
        }

        return [
            'configured' => $configured,
            'connected'  => $connected && !empty(\WhmcsAnalytics\Google::get('property_id')),
            'property'   => $property,
        ];
    }

    public function generateOutput($data)
    {
        $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
        $assets    = $systemUrl . '/modules/addons/whmcs_analytics/assets';
        $ajax      = $systemUrl . '/modules/addons/whmcs_analytics/ajax.php';
        $moduleCfg = $systemUrl . '/' . ltrim(\WHMCS\Admin\AdminServiceProvider::getAdminRouteBase() ?? 'admin', '/');

        if (empty($data['configured'])) {
            return '<div class="cpga-empty">Google Analytics is not configured yet. '
                . 'Enter your Google OAuth credentials and connect in <strong>Addons → WHMCS Analytics</strong>.</div>';
        }
        if (empty($data['connected'])) {
            return '<div class="cpga-empty">Not connected to a GA4 property yet. '
                . 'Open <strong>Addons → WHMCS Analytics</strong> to connect Google and choose a property.</div>';
        }

        $tabs = [
            'graph'             => 'Graph',
            'realtime'          => 'Real Time',
            'pages'             => 'Pages',
            'countries'         => 'Countries',
            'browsers'          => 'Browsers',
            'languages'         => 'Languages',
            'operating_systems' => 'Operating Systems',
            'devices'           => 'Devices',
            'screen_resolution' => 'Screen Resolution',
            'source'            => 'Source',
            'keywords'          => 'Search Console',
            'indexing'          => 'Indexing',
            'alerts'            => 'Alerts',
            'advisor'           => 'SEO Advisor',
        ];

        ob_start(); ?>
<div class="cpga cpga-embed" data-ajax="<?= htmlspecialchars($ajax) ?>" data-token="<?= htmlspecialchars(generate_token('plain')) ?>">
    <div class="cpga-head">
        <div class="cpga-property"><i class="fas fa-chart-line"></i> <span class="cpga-prop-name"><?= htmlspecialchars($data['property'] ?: 'Analytics') ?></span></div>
        <div class="cpga-range">
            <input type="date" class="cpga-start">
            <span>→</span>
            <input type="date" class="cpga-end">
            <button class="cpga-apply" type="button">Apply</button>
        </div>
    </div>
    <ul class="cpga-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <li><a href="#" data-tab="<?= $key ?>"<?= $key === 'graph' ? ' class="active"' : '' ?>><?= htmlspecialchars($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="cpga-body">
        <div class="cpga-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading…</div>
        <div class="cpga-kpis" hidden></div>
        <div class="cpga-chart-wrap" hidden><canvas class="cpga-chart" height="90"></canvas></div>
        <div class="cpga-table-wrap" hidden></div>
        <div class="cpga-error alert alert-danger" hidden></div>
    </div>
    <div class="cpga-foot"><a href="https://underhost.com" target="_blank" rel="noopener"><i class="fas fa-bolt"></i> Powered by <strong>UnderHost</strong></a></div>
</div>
<link rel="stylesheet" href="<?= htmlspecialchars($assets) ?>/ga.css?v=2.2.7">
<link rel="stylesheet" href="<?= htmlspecialchars($assets) ?>/gsc.css?v=2.2.7">
<script>
window.cpgaChartSrc = "<?= htmlspecialchars($assets) ?>/chart.umd.min.js";
window.cpgaEChartsSrc = "<?= htmlspecialchars($assets) ?>/echarts.min.js";
window.cpgaWorldGeoUrl = "<?= htmlspecialchars($assets) ?>/world.geo.json?v=2.2.7";
</script>
<script src="<?= htmlspecialchars($assets) ?>/geomap.js?v=2.2.7"></script>
<script src="<?= htmlspecialchars($assets) ?>/gsc.js?v=2.2.7"></script>
<script src="<?= htmlspecialchars($assets) ?>/ga.js?v=2.2.7"></script>
        <?php
        return ob_get_clean();
    }
}
