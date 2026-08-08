<?php
/**
 * WHMCS Analytics — WHMCS addon module.
 * Provides the Google (GA4) OAuth connection + property selection used by the
 * WHMCS Google Analytics dashboard widget.
 *
 * (c) 2026 UnderHost.com — original work.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/Google.php';
require_once __DIR__ . '/lib/Storage.php';
require_once __DIR__ . '/lib/GscStore.php';
require_once __DIR__ . '/lib/AiAdvisor.php';

use WhmcsAnalytics\Google;
use WhmcsAnalytics\Storage;
use WhmcsAnalytics\GscStore;
use WhmcsAnalytics\AiAdvisor;

function whmcs_analytics_config()
{
    return [
        'name'        => 'WHMCS Analytics',
        'description' => 'Google Analytics 4 + Search Console on your WHMCS admin dashboard: live GA4 reports, a world-map heatmap, and Search Console keyword tracking with pluggable history storage (local DB, external MySQL, or libSQL/Turso).',
        'version'     => '2.1.0',
        'author'      => 'UnderHost',
        'language'    => 'english',
        'fields'      => [
            'client_id' => [
                'FriendlyName' => 'Google OAuth Client ID',
                'Type'         => 'text',
                'Size'         => '70',
                'Description'  =>
                    'Paste the OAuth <b>Client ID</b> from the '
                    . '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> '
                    . '(APIs &amp; Services → Credentials → <b>Create credentials → OAuth client ID</b>, type <b>Web application</b>).<br>'
                    . '<b>First:</b> in the '
                    . '<a href="https://console.cloud.google.com/apis/library" target="_blank" rel="noopener">API Library</a> '
                    . 'enable the <b>Google Analytics Data API</b> and the <b>Search Console API</b> for the same project.',
            ],
            'client_secret' => [
                'FriendlyName' => 'Google OAuth Client Secret',
                'Type'         => 'password',
                'Size'         => '50',
                'Description'  =>
                    'The OAuth <b>Client secret</b> for the client above.<br>'
                    . '<b>After you Save Changes:</b> open '
                    . '<a href="addonmodules.php?module=whmcs_analytics&amp;view=guide">WHMCS Analytics → Setup guide</a> '
                    . 'for the full step-by-step, then '
                    . '<a href="addonmodules.php?module=whmcs_analytics&amp;view=settings">Settings &amp; connection</a> '
                    . 'to copy the required <b>redirect URI</b> into Google, connect your account, and choose your GA4 property + Search Console site.',
            ],
        ],
    ];
}

function whmcs_analytics_activate()
{
    try {
        Google::installTable();
        return ['status' => 'success', 'description' => 'WHMCS Analytics activated. Open the module to connect Google Analytics.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function whmcs_analytics_deactivate()
{
    // Keep the settings table on deactivate so a reactivate keeps the connection.
    return ['status' => 'success', 'description' => 'Deactivated. Connection settings are retained; use "Disconnect" first to fully remove them.'];
}

/**
 * Admin area output: the settings/connection UI + OAuth callback handling.
 */
function whmcs_analytics_output($vars)
{
    $clientId     = trim($vars['client_id'] ?? '');
    $clientSecret = trim($vars['client_secret'] ?? '');
    $moduleLink   = $vars['modulelink']; // addonmodules.php?module=whmcs_analytics
    $systemUrl    = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $adminBase    = defined('WHMCS\\Admin\\AdminServiceProvider') ? '' : '';
    // Redirect URI must exactly match the one registered in Google Cloud.
    // The redirect URI must be IDENTICAL in the auth request and the token
    // exchange, and must match what's registered in Google Cloud — including the
    // &oauth=callback marker. Build it once and reuse it everywhere.
    $redirectUri  = _cpga_current_module_url();
    $callbackUri  = $redirectUri . '&oauth=callback';

    $notice = '';
    $error  = '';

    // ---- OAuth callback ----
    if (isset($_GET['oauth']) && $_GET['oauth'] === 'callback' && isset($_GET['code'])) {
        if (($_GET['state'] ?? '') !== _cpga_state()) {
            $error = 'Invalid OAuth state. Please try connecting again.';
        } else {
            try {
                $res = Google::exchangeCode($clientId, $clientSecret, $callbackUri, $_GET['code']);
                if (Google::isConnected()) {
                    $notice = 'Connected to Google successfully. Now choose a GA4 property below.';
                } else {
                    $error = 'Google did not return a refresh token: ' . Google::errText($res)
                        . ' — remove the app under your Google Account → Security → Third-party access, then reconnect.';
                }
            } catch (\Throwable $e) {
                $error = 'Token exchange failed: ' . $e->getMessage();
            }
        }
    }

    // ---- Actions (POST) ----
    if (($_POST['cpga_action'] ?? '') !== '') {
        $action = $_POST['cpga_action'];
        try {
            if ($action === 'disconnect') {
                Google::disconnect();
                $notice = 'Disconnected from Google.';
            } elseif ($action === 'connect_sa') {
                $email = Google::connectServiceAccount((string) ($_POST['sa_key'] ?? ''));
                $notice = 'Connected via service account (' . $email . '). Now choose your GA4 property below.';
            } elseif ($action === 'save_property') {
                $pid = preg_replace('/[^0-9]/', '', $_POST['property_id'] ?? '');
                Google::set('property_id', $pid);
                Google::set('property_name', $_POST['property_name'] ?? $pid);
                $notice = 'GA4 property saved. The dashboard widget will now use it.';
            } elseif ($action === 'save_sc_site') {
                Google::set('sc_site', trim($_POST['sc_site'] ?? ''));
                $notice = 'Search Console site saved. The widget\'s Search Console tab will use it.';
            } elseif ($action === 'save_thresholds') {
                foreach ([
                    'backfill_months', 'move_threshold', 'opp_ctr_min_impr', 'opp_ctr_threshold',
                    'opp_pageone_min_impr', 'opp_decline_min_impr', 'opp_decline_places',
                ] as $keyName) {
                    if (isset($_POST[$keyName])) {
                        Google::set($keyName, preg_replace('/[^0-9.]/', '', (string) $_POST[$keyName]));
                    }
                }
                $notice = 'Search Console settings saved.';
            } elseif ($action === 'save_storage') {
                $backend = preg_replace('/[^a-z]/', '', $_POST['storage_backend'] ?? 'local');
                if (!in_array($backend, ['local', 'mysql', 'libsql'], true)) { $backend = 'local'; }
                Google::set('storage_backend', $backend);
                if ($backend === 'mysql') {
                    Google::set('db_host', trim($_POST['db_host'] ?? ''));
                    Google::set('db_port', preg_replace('/[^0-9]/', '', $_POST['db_port'] ?? '3306') ?: '3306');
                    Google::set('db_name', trim($_POST['db_name'] ?? ''));
                    Google::set('db_user', trim($_POST['db_user'] ?? ''));
                    if (($_POST['db_pass'] ?? '') !== '') { Google::set('db_pass', (string) $_POST['db_pass']); }
                } elseif ($backend === 'libsql') {
                    Google::set('libsql_url', trim($_POST['libsql_url'] ?? ''));
                    if (($_POST['libsql_token'] ?? '') !== '') { Google::set('libsql_token', (string) $_POST['libsql_token']); }
                    if (($_POST['libsql_ro_token'] ?? '') !== '') { Google::set('libsql_ro_token', (string) $_POST['libsql_ro_token']); }
                }
                Storage::reset();
                try {
                    if (Storage::isConfigured()) { GscStore::ensureSchema(); }
                } catch (\Throwable $e) {
                    $error = 'Saved, but initializing the schema failed: ' . $e->getMessage();
                }
                if (!$error) { $notice = 'History storage saved (' . Storage::backendLabel($backend) . ').'; }
            } elseif ($action === 'test_storage') {
                Storage::reset();
                $res = Storage::testConnection();
                if (!empty($res['ok'])) { $notice = 'Storage connection OK — ' . $res['message']; }
                else { $error = 'Storage connection failed — ' . $res['message']; }
            } elseif ($action === 'save_ai') {
                $prov = preg_replace('/[^a-z]/', '', $_POST['ai_provider'] ?? 'openai');
                if (!array_key_exists($prov, AiAdvisor::PROVIDERS)) { $prov = 'openai'; }
                Google::set('ai_provider', $prov);
                Google::set('ai_model', trim($_POST['ai_model'] ?? ''));
                if (($_POST['ai_api_key'] ?? '') !== '') { Google::set('ai_api_key', (string) $_POST['ai_api_key']); }
                Google::set('ai_consent', !empty($_POST['ai_consent']) ? '1' : '');
                $notice = 'AI SEO Advisor settings saved.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // Which view: dashboard, settings/connection, or the setup guide.
    $view = in_array($_GET['view'] ?? '', ['settings', 'guide'], true) ? $_GET['view'] : 'dashboard';
    $connectedReady = Google::isConfigured($clientId, $clientSecret)
        && Google::isConnected() && Google::get('property_id');

    ob_start();
    echo '<div class="cpga-admin">';

    if ($notice) { echo '<div class="alert alert-success">' . htmlspecialchars($notice) . '</div>'; }
    if ($error)  { echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>'; }

    // The Setup guide is always available (even before connecting).
    if ($view === 'guide') {
        echo _cpga_admin_nav($moduleLink, $view, $connectedReady);
        echo _cpga_guide_html($callbackUri, $systemUrl);
        echo '</div>';
        echo ob_get_clean();
        return;
    }

    // Top nav: Dashboard | Settings | Setup guide (Dashboard once connected).
    echo _cpga_admin_nav($moduleLink, $view, $connectedReady);
    if ($connectedReady) {
        if ($view === 'dashboard') {
            echo _cpga_dashboard_html($systemUrl);
            echo '</div>'; // .cpga-admin
            // Dashboard uses the full page width (unlike the narrow settings panels).
            echo '<style>.cpga-admin{max-width:none}.cpga-admin .cpga-embed{max-width:none;width:100%}</style>';
            echo ob_get_clean();
            return;
        }
    }

    $oauthConfigured = Google::isConfigured($clientId, $clientSecret);
    $connected       = Google::isConnected();
    $authType        = Google::authType();

    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Google connection</strong></div><div class="panel-body">';

    if ($connected) {
        // ---- Connected (either OAuth or service account): status + selectors ----
        if ($authType === 'service_account') {
            echo '<p><span class="label label-success">Connected — service account</span> <code style="font-size:11.5px">' . htmlspecialchars(Google::serviceAccountEmail()) . '</code></p>';
        } else {
            echo '<p><span class="label label-success">Connected — OAuth</span></p>';
        }
        echo '<form method="post" style="display:inline"><input type="hidden" name="cpga_action" value="disconnect"><button class="btn btn-default btn-sm" type="submit">Disconnect</button></form>';

        // GA4 property selector
        echo '<hr><h4>GA4 Property</h4>';
        try {
            $props   = Google::listProperties($clientId, $clientSecret);
            $current = Google::get('property_id');
            if (!$props) {
                echo '<div class="alert alert-warning">No GA4 properties found for the connection. '
                    . ($authType === 'service_account'
                        ? 'Add the service-account email as a <b>Viewer</b> on your GA4 property (Admin → Property Access Management), then reload.'
                        : 'Make sure the account has access to a GA4 property.') . '</div>';
            } else {
                echo '<form method="post" class="form-inline"><input type="hidden" name="cpga_action" value="save_property">';
                echo '<select name="property_id" class="form-control" onchange="this.form.property_name.value=this.options[this.selectedIndex].text">';
                foreach ($props as $p) {
                    $sel = ($p['id'] === $current) ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($p['id']) . '"' . $sel . '>' . htmlspecialchars($p['name']) . '</option>';
                }
                echo '</select> <input type="hidden" name="property_name" value="">';
                echo ' <button class="btn btn-primary" type="submit">Save property</button></form>';
                if ($current) {
                    echo '<p class="text-muted" style="margin-top:8px">Active property: <code>' . htmlspecialchars(Google::get('property_name', $current)) . '</code></p>';
                }
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">Could not list properties: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // Search Console site selector
        echo '<hr><h4>Search Console site <small class="text-muted">(optional — for the Keywords/Search Console tab)</small></h4>';
        try {
            $sites   = Google::listSites($clientId, $clientSecret);
            $curSite = Google::get('sc_site');
            if (!$sites) {
                echo '<div class="alert alert-warning">No Search Console sites found. '
                    . ($authType === 'service_account'
                        ? 'Add the service-account email as a user on your site in Search Console (Settings → Users and permissions), then reload.'
                        : 'Add and verify a site in Google Search Console, then reload.') . '</div>';
            } else {
                echo '<form method="post" class="form-inline"><input type="hidden" name="cpga_action" value="save_sc_site">';
                echo '<select name="sc_site" class="form-control"><option value="">— None —</option>';
                foreach ($sites as $s) {
                    $sel = ($s['url'] === $curSite) ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($s['url']) . '"' . $sel . '>' . htmlspecialchars($s['url']) . '</option>';
                }
                echo '</select> <button class="btn btn-primary" type="submit">Save site</button></form>';
                if ($curSite) {
                    echo '<p class="text-muted" style="margin-top:8px">Active site: <code>' . htmlspecialchars($curSite) . '</code></p>';
                }
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-warning">Could not list Search Console sites: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        // ---- Not connected: choose a connection method ----
        $method = in_array($_GET['method'] ?? '', ['oauth', 'sa'], true) ? $_GET['method'] : 'oauth';
        echo '<ul class="nav nav-pills" style="margin-bottom:14px">'
            . '<li' . ($method === 'oauth' ? ' class="active"' : '') . '><a href="' . htmlspecialchars($moduleLink . '&view=settings&method=oauth') . '"><i class="fab fa-google"></i> OAuth (Connect with Google)</a></li> '
            . '<li' . ($method === 'sa' ? ' class="active"' : '') . '><a href="' . htmlspecialchars($moduleLink . '&view=settings&method=sa') . '"><i class="fas fa-key"></i> Service account <small>(no consent screen)</small></a></li>'
            . '</ul>';

        if ($method === 'sa') {
            echo '<p class="text-muted">Recommended for a server: <b>no OAuth consent screen, no app verification, and tokens don\'t expire</b>. '
                . 'You create a Google <b>service account</b>, download its <b>JSON key</b>, and share your GA4 property + Search Console site with the service-account email.</p>';
            echo '<div class="alert alert-info" style="font-size:12.5px"><b>One-time setup:</b><ol style="margin:6px 0 0;padding-left:18px">'
                . '<li>In the <a href="https://console.cloud.google.com/apis/library" target="_blank" rel="noopener">API Library</a>, enable the <b>Google Analytics Data API</b> and <b>Search Console API</b>.</li>'
                . '<li>In <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">IAM &amp; Admin → Service Accounts</a>, create a service account, then <b>Keys → Add key → Create new key → JSON</b>.</li>'
                . '<li>In GA4: <b>Admin → Property Access Management</b> → add the service-account email as <b>Viewer</b>.</li>'
                . '<li>In Search Console: <b>Settings → Users and permissions</b> → add the same email.</li>'
                . '<li>Paste the JSON key below and connect.</li></ol></div>';
            echo '<form method="post"><input type="hidden" name="cpga_action" value="connect_sa">';
            echo '<textarea name="sa_key" class="form-control" rows="7" placeholder="Paste the entire service-account JSON key here…" style="font-family:monospace;font-size:11.5px" required></textarea>';
            echo '<div style="margin-top:10px"><button class="btn btn-primary" type="submit"><i class="fas fa-key"></i> Connect with service account</button></div>';
            echo '</form>';
        } elseif (!$oauthConfigured) {
            echo '<div class="alert alert-warning"><strong>Set up required.</strong> Enter your Google OAuth <em>Client ID</em> and <em>Client Secret</em> in <b>Configure</b> (Configuration → Addon Modules → WHMCS Analytics → Configure), then reload — or use the <b>Service account</b> tab above, which needs no OAuth client.</div>';
        } else {
            echo '<div style="background:var(--cp-surface-2,#f7f9fc);border:1px solid #e3e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">';
            echo '<div style="font-weight:700;margin-bottom:4px"><i class="fas fa-link"></i> Your Authorized redirect URI</div>';
            echo '<p style="margin:0 0 8px">When you <b>Create OAuth client ID</b> (type <b>Web application</b>) in Google Cloud: paste the URL below into <b>Authorized redirect URIs</b>. Leave <b>Authorized JavaScript origins</b> empty.</p>';
            echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
            echo '<input type="text" readonly id="cpgaRedirectUri" value="' . htmlspecialchars($callbackUri) . '" onclick="this.select()" style="flex:1;min-width:320px;font-family:monospace;font-size:12px;padding:7px 9px;border:1px solid #d5deea;border-radius:6px;background:#fff">';
            echo '<button type="button" class="btn btn-default btn-sm cpga-copy-uri"><i class="fas fa-copy"></i> Copy</button>';
            echo '</div></div>';
            echo '<script>(function(){var b=document.querySelector(".cpga-copy-uri");if(b){b.addEventListener("click",function(){var i=document.getElementById("cpgaRedirectUri");i.focus();i.select();try{if(navigator.clipboard){navigator.clipboard.writeText(i.value);}else{document.execCommand("copy");}}catch(e){}b.textContent="Copied";});}})();</script>';
            $url = Google::authUrl($clientId, $callbackUri, _cpga_state());
            echo '<a class="btn btn-primary" href="' . htmlspecialchars($url) . '"><i class="fab fa-google"></i> Connect with Google</a>';
        }
        echo '<p style="margin:12px 0 0;font-size:11.5px;color:var(--cp-text-soft,#5b6b84)">New to this? Follow the <a href="' . htmlspecialchars($moduleLink . '&view=guide') . '">Setup guide</a> tab.</p>';
    }

    echo '</div></div>';

    // --- Search Console history storage --------------------------------
    $sBackend = Storage::backend();
    $hasTok   = (string) Google::get('libsql_token', '') !== '';
    $hasPass  = (string) Google::get('db_pass', '') !== '';
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Search Console history storage</strong></div><div class="panel-body">';
    echo '<p class="text-muted">Where the plugin stores Search Console history (weekly tracking, movers, opportunities). '
        . 'GA4 data is always fetched live from Google and needs no storage.</p>';

    if (Storage::isConfigured()) {
        echo '<p><span class="label label-success">' . htmlspecialchars(Storage::backendLabel()) . '</span></p>';
        try {
            GscStore::ensureSchema();
            $cov = GscStore::coverage();
            if (!empty($cov['max_date'])) {
                echo '<p class="text-muted">Stored history: <code>' . htmlspecialchars($cov['min_date']) . '</code> → <code>'
                    . htmlspecialchars($cov['max_date']) . '</code> (' . number_format($cov['rows']) . ' query-day rows).</p>';
            } else {
                echo '<p class="text-muted">No Search Console data stored yet. It syncs on the daily WHMCS cron, '
                    . 'or click <strong>Sync</strong> on the dashboard Search Console tab.</p>';
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">Storage check failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        echo '<div class="alert alert-warning">' . htmlspecialchars(Storage::unavailableReason() ?: 'Storage is not configured.') . '</div>';
    }

    echo '<form method="post" style="max-width:640px"><input type="hidden" name="cpga_action" value="save_storage">';
    echo '<div class="form-group"><label>Storage backend</label> <select name="storage_backend" class="form-control" id="cpgaStoreBackend" onchange="cpgaStoreToggle(this.value)">';
    foreach (Storage::BACKENDS as $bk => $lbl) {
        echo '<option value="' . $bk . '"' . ($bk === $sBackend ? ' selected' : '') . '>' . htmlspecialchars($lbl) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="cpga-store-fields" data-b="local"><p class="text-muted">Uses the WHMCS database — tables are created automatically. Nothing to configure.</p></div>';
    echo '<div class="cpga-store-fields" data-b="mysql">'
        . _cpga_field('Host', 'db_host', htmlspecialchars((string) Google::get('db_host', '')), 'e.g. 127.0.0.1')
        . _cpga_field('Port', 'db_port', htmlspecialchars((string) (Google::get('db_port', '3306') ?: '3306')), '3306')
        . _cpga_field('Database name', 'db_name', htmlspecialchars((string) Google::get('db_name', '')), '')
        . _cpga_field('Username', 'db_user', htmlspecialchars((string) Google::get('db_user', '')), '')
        . _cpga_field('Password', 'db_pass', '', $hasPass ? 'saved — leave blank to keep' : '', 'password')
        . '</div>';
    echo '<div class="cpga-store-fields" data-b="libsql">'
        . _cpga_field('libSQL URL', 'libsql_url', htmlspecialchars((string) Google::get('libsql_url', '')), 'libsql://your-db.turso.io or https://…')
        . _cpga_field('Auth token (full access)', 'libsql_token', '', $hasTok ? 'saved — leave blank to keep' : 'Bearer token', 'password')
        . _cpga_field('Read-only token (optional)', 'libsql_ro_token', '', 'optional', 'password')
        . '</div>';
    echo '<div style="margin-top:10px"><button class="btn btn-primary" type="submit">Save storage</button></div>';
    echo '</form>';
    echo '<form method="post" style="margin-top:8px"><input type="hidden" name="cpga_action" value="test_storage">'
        . '<button class="btn btn-default btn-sm" type="submit"><i class="fas fa-plug"></i> Test connection</button>'
        . ' <span class="text-muted">Tests the currently saved backend.</span></form>';
    echo '<script>function cpgaStoreToggle(v){var n=document.querySelectorAll(".cpga-store-fields");for(var i=0;i<n.length;i++){n[i].style.display=n[i].getAttribute("data-b")===v?"":"none";}}cpgaStoreToggle(document.getElementById("cpgaStoreBackend").value);</script>';

    // Thresholds / settings form.
    $g = function ($k, $d) {
        $v = Google::get($k, $d);
        return htmlspecialchars($v === null || $v === '' ? $d : $v);
    };
    echo '<hr><h4>Tracking &amp; opportunity settings</h4>';
    echo '<form method="post" class="form-horizontal" style="max-width:640px"><input type="hidden" name="cpga_action" value="save_thresholds">';
    $settingRows = [
        ['backfill_months', 'History to keep/backfill (months)', '12'],
        ['move_threshold', 'Movement threshold (positions)', '1'],
        ['opp_ctr_min_impr', 'CTR opportunity — min impressions', '100'],
        ['opp_ctr_threshold', 'CTR opportunity — CTR cutoff % (blank = site avg)', ''],
        ['opp_pageone_min_impr', 'Page-one opportunity — min impressions', '50'],
        ['opp_decline_min_impr', 'Declining — min impressions', '50'],
        ['opp_decline_places', 'Declining — min positions dropped', '3'],
    ];
    foreach ($settingRows as $r) {
        echo '<div class="form-group"><label class="col-sm-7 control-label" style="text-align:left;font-weight:normal">'
            . htmlspecialchars($r[1]) . '</label><div class="col-sm-3"><input type="text" class="form-control input-sm" name="'
            . $r[0] . '" value="' . $g($r[0], $r[2]) . '" placeholder="' . htmlspecialchars($r[2]) . '"></div></div>';
    }
    echo '<div class="form-group"><div class="col-sm-offset-7 col-sm-3"><button class="btn btn-primary btn-sm" type="submit">Save settings</button></div></div>';
    echo '</form>';
    echo '<p class="text-muted" style="margin-top:8px">The expanded Search Console dashboard (sorting, filtering, weekly position tracking, movers, opportunities, and per-keyword history charts) lives on the admin dashboard&rsquo;s <strong>Google Analytics → Search Console</strong> tab.</p>';
    echo '</div></div>';

    // --- AI SEO Advisor --------------------------------------------------
    $aiProv   = AiAdvisor::provider();
    $aiHasKey = AiAdvisor::hasKey();
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>AI SEO Advisor</strong></div><div class="panel-body">';
    echo '<p class="text-muted">Bring your own LLM key to get prioritized SEO recommendations from your GA4 + Search Console data, on the dashboard&rsquo;s <strong>SEO Advisor</strong> tab.</p>';
    echo '<div class="alert alert-info" style="margin-bottom:12px"><i class="fas fa-user-shield"></i> <strong>Privacy:</strong> when you request advice, an <em>aggregate</em> snapshot (top queries, pages, countries and KPIs — no visitor personal data) is sent to the provider you choose. Nothing is sent until an admin clicks &ldquo;Get advice&rdquo;.</div>';
    echo '<form method="post" style="max-width:640px"><input type="hidden" name="cpga_action" value="save_ai">';
    echo '<div class="form-group"><label style="display:block;font-weight:600">Provider</label> <select name="ai_provider" class="form-control" style="max-width:300px">';
    foreach (AiAdvisor::PROVIDERS as $pk => $pl) {
        echo '<option value="' . $pk . '"' . ($pk === $aiProv ? ' selected' : '') . '>' . htmlspecialchars($pl) . '</option>';
    }
    echo '</select></div>';
    echo _cpga_field('API key', 'ai_api_key', '', $aiHasKey ? 'saved — leave blank to keep' : 'API key for the selected provider', 'password');
    echo '<p class="text-muted" style="margin-top:-6px;font-size:11.5px">Get a key from: '
        . '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI</a> · '
        . '<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google Gemini (AI Studio)</a> · '
        . '<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">Anthropic</a> · '
        . '<a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener">DeepSeek</a>. '
        . 'The Gemini key is separate from the Google Analytics OAuth connection above.</p>';
    echo _cpga_field('Model (optional)', 'ai_model', htmlspecialchars((string) Google::get('ai_model', '')),
        'defaults: OpenAI ' . AiAdvisor::DEFAULT_MODEL['openai'] . ', Gemini ' . AiAdvisor::DEFAULT_MODEL['gemini']
        . ', Anthropic ' . AiAdvisor::DEFAULT_MODEL['anthropic'] . ', DeepSeek ' . AiAdvisor::DEFAULT_MODEL['deepseek']);
    echo '<div class="checkbox"><label><input type="checkbox" name="ai_consent" value="1"' . (AiAdvisor::consented() ? ' checked' : '')
        . '> I understand an aggregate analytics snapshot will be sent to the selected AI provider when advice is requested.</label></div>';
    echo '<div style="margin-top:10px"><button class="btn btn-primary" type="submit">Save AI settings</button></div>';
    echo '</form></div></div>';

    echo _cpga_underhost_banner();

    echo '</div>';

    echo '<style>.cpga-admin code{word-break:break-all}.cpga-admin .panel{max-width:900px}</style>';

    echo ob_get_clean();
}

/* -------- helpers -------- */

/** One Bootstrap form-group row (label + input) for the storage settings. */
function _cpga_field($label, $name, $value, $placeholder = '', $type = 'text')
{
    return '<div class="form-group"><label style="display:block;font-weight:600">' . htmlspecialchars($label) . '</label>'
        . '<input type="' . $type . '" class="form-control" name="' . $name . '" value="' . $value . '" placeholder="'
        . htmlspecialchars($placeholder) . '" style="max-width:420px"'
        . ($type === 'password' ? ' autocomplete="new-password"' : '') . '></div>';
}

/**
 * UnderHost promo banner. Shown only on the admin settings + setup-guide screens
 * — never on the analytics dashboard, tabs, or the homepage widget.
 */
function _cpga_underhost_banner()
{
    return '<div style="margin:22px 0 4px;text-align:center">'
        . '<div style="font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#8a97ac;margin-bottom:6px">Powered by</div>'
        . '<div style="display:inline-block;max-width:100%;overflow-x:auto">'
        . '<div style="position:relative;display:inline-block;width:600px;height:300px;overflow:hidden;border-radius:10px">'
        . '<iframe src="https://cdn.underhost.com/brands/banners/underhost-600-300-email-promo.html" width="600" height="300" '
        . 'scrolling="no" frameborder="0" style="display:block;border:none;pointer-events:none"></iframe>'
        . '<a href="https://underhost.com" target="_blank" rel="noopener" '
        . 'style="position:absolute;inset:0;z-index:10" aria-label="UnderHost Hosting"></a>'
        . '</div></div></div>';
}

/** Admin top nav (tabs). Dashboard is shown only once fully connected. */
function _cpga_admin_nav($moduleLink, $view, $connectedReady)
{
    $tab = function ($v, $icon, $label) use ($moduleLink, $view) {
        $href = htmlspecialchars($moduleLink . ($v === 'dashboard' ? '' : '&view=' . $v));
        $act  = $view === $v ? ' class="active"' : '';
        return '<li' . $act . '><a href="' . $href . '"><i class="fas ' . $icon . '"></i> ' . $label . '</a></li>';
    };
    $html = '<ul class="nav nav-tabs cpga-admin-nav" style="margin-bottom:16px">';
    if ($connectedReady) { $html .= $tab('dashboard', 'fa-chart-line', 'Dashboard'); }
    $html .= $tab('settings', 'fa-cog', 'Settings &amp; connection');
    $html .= $tab('guide', 'fa-circle-question', 'Setup guide');
    return $html . '</ul>';
}

/** Static setup guide shown on its own tab (works before connecting). */
function _cpga_guide_html($callbackUri, $systemUrl)
{
    $uri = htmlspecialchars($callbackUri);
    $h = '<div class="panel panel-default" style="max-width:900px"><div class="panel-body cpga-guide">';
    $h .= '<h3 style="margin-top:0">Setup guide</h3>';
    $h .= '<p class="text-muted">WHMCS Analytics shows Google Analytics 4 and Search Console data on your admin dashboard. '
        . 'GA4 data is fetched live from Google; Search Console history is stored in the backend you choose below. '
        . 'No theme edits are required.</p>';

    $step = function ($n, $title, $body) {
        return '<div style="display:flex;gap:12px;margin:14px 0">'
            . '<div style="flex:0 0 30px;height:30px;border-radius:50%;background:#5865F2;color:#fff;font-weight:700;'
            . 'display:flex;align-items:center;justify-content:center">' . $n . '</div>'
            . '<div style="flex:1"><h4 style="margin:2px 0 4px">' . $title . '</h4>'
            . '<div class="text-muted">' . $body . '</div></div></div>';
    };

    $h .= $step(1, 'Create Google OAuth credentials',
        'In the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> '
        . '&rarr; APIs &amp; Services &rarr; Credentials, create an <strong>OAuth client ID</strong> of type <em>Web application</em>. '
        . 'First, in the <a href="https://console.cloud.google.com/apis/library" target="_blank" rel="noopener">API Library</a> '
        . 'enable the <strong>Google Analytics Data API</strong> and the <strong>Search Console API</strong> for the project. '
        . 'Add this exact <strong>Authorized redirect URI</strong> to the OAuth client (and leave <em>Authorized JavaScript origins</em> empty):<br><code>' . $uri . '</code>');

    $h .= $step(2, 'Enter your Client ID &amp; Secret',
        'Go to <strong>Configuration &rarr; System Settings &rarr; Addon Modules</strong>, find <em>WHMCS Analytics</em>, click '
        . '<strong>Configure</strong>, paste the OAuth <em>Client ID</em> and <em>Client Secret</em>, tick the admin roles that may '
        . 'access it, and save. Enable/disable and API credentials always live on that Configure screen.');

    $h .= $step(3, 'Connect Google &amp; pick your data',
        'Open the <strong>Settings &amp; connection</strong> tab here, click <strong>Connect with Google</strong>, then choose your '
        . '<strong>GA4 property</strong> and (optionally) your <strong>Search Console site</strong>.<br>'
        . '<strong>Prefer no OAuth?</strong> On that tab switch to the <strong>Service account</strong> method — no consent screen, '
        . 'no app to publish, and the connection never expires. You paste a service-account JSON key and share your GA4 property + '
        . 'Search Console site with the service-account email (steps are shown on-screen). Steps 1–2 above are not needed for that path.');

    $h .= $step(4, 'Choose where history is stored',
        'On the same tab, pick a <strong>history storage</strong> backend: <strong>Local WHMCS database</strong> (default, zero setup), '
        . '<strong>External MySQL</strong>, or <strong>libSQL / Turso</strong>. Click <strong>Test connection</strong> to verify, then Save. '
        . 'Only Search Console history uses this — GA4 needs no storage.');

    $h .= $step(5, 'Add the dashboard widget',
        'On the WHMCS admin <strong>Home</strong> page, use the widget menu (top-right &ldquo;Manage Widgets&rdquo;) to enable '
        . '<strong>Google Analytics</strong>. You can also always open this addon&rsquo;s <strong>Dashboard</strong> tab. '
        . 'No <code>homepage.tpl</code> edit is needed.');

    $h .= $step(6, 'Let it backfill (Search Console)',
        'Search Console history fills in automatically on the daily WHMCS cron, or click <strong>Sync</strong> on the dashboard&rsquo;s '
        . 'Search Console tab to start it now. A full 12-month backfill completes over a few daily runs.');

    $h .= '<div class="alert alert-warning" style="margin-top:6px">'
        . '<strong>Getting &ldquo;Error 403: access_denied&rdquo; when you click Connect?</strong> Your OAuth app is in '
        . '<em>Testing</em> mode, so only listed testers can use it. In Google Cloud &rarr; '
        . '<a href="https://console.cloud.google.com/auth/audience" target="_blank" rel="noopener">Google Auth Platform &rarr; Audience</a>, '
        . 'either add your Google account under <strong>Test users</strong>, or click <strong>Publish app</strong> to move it to '
        . '<strong>Production</strong>. Publishing is recommended: apps left in Testing have refresh tokens that '
        . '<strong>expire after 7 days</strong>, which would break the daily sync. At the &ldquo;unverified app&rdquo; screen, '
        . 'click <strong>Advanced &rarr; Go to &hellip; (unsafe)</strong> to continue with your own app.</div>';
    $h .= '</div></div>';
    $h .= _cpga_underhost_banner();
    return $h;
}

/**
 * Full analytics dashboard markup + asset tags, for the dedicated addon page.
 * Mirrors the dashboard widget / theme homepage embed so all three share the
 * same behaviour and JS. Wrapped in `.cpga-embed` for the full-width layout.
 */
function _cpga_dashboard_html($systemUrl)
{
    $assets = htmlspecialchars($systemUrl . '/modules/addons/whmcs_analytics/assets');
    $ajax   = htmlspecialchars($systemUrl . '/modules/addons/whmcs_analytics/ajax.php');
    $token  = htmlspecialchars(generate_token('plain'));
    $ver    = '2.1.0';

    $tabs = [
        'graph' => 'Graph', 'realtime' => 'Real Time', 'pages' => 'Pages',
        'countries' => 'Countries', 'browsers' => 'Browsers', 'languages' => 'Languages',
        'operating_systems' => 'Operating Systems', 'devices' => 'Devices',
        'screen_resolution' => 'Screen Resolution', 'source' => 'Source', 'keywords' => 'Search Console',
        'advisor' => 'SEO Advisor',
    ];
    $tabsHtml = '';
    foreach ($tabs as $key => $label) {
        $tabsHtml .= '<li><a href="#" data-tab="' . $key . '"' . ($key === 'graph' ? ' class="active"' : '') . '>'
            . htmlspecialchars($label) . '</a></li>';
    }

    return '<div class="panel panel-default cpga-embed"><div class="panel-body">'
        . '<div class="cpga" data-ajax="' . $ajax . '" data-token="' . $token . '">'
        . '<div class="cpga-head">'
        . '<div class="cpga-property"><i class="fas fa-chart-line"></i> Website analytics</div>'
        . '<div class="cpga-range">'
        . '<input type="date" class="cpga-start form-control input-sm"><span>&rarr;</span>'
        . '<input type="date" class="cpga-end form-control input-sm">'
        . '<button class="btn btn-sm btn-default cpga-apply" type="button">Apply</button>'
        . '</div></div>'
        . '<ul class="cpga-tabs">' . $tabsHtml . '</ul>'
        . '<div class="cpga-body">'
        . '<div class="cpga-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading&hellip;</div>'
        . '<div class="cpga-kpis" hidden></div>'
        . '<div class="cpga-chart-wrap" hidden><canvas class="cpga-chart" height="90"></canvas></div>'
        . '<div class="cpga-table-wrap" hidden></div>'
        . '<div class="cpga-error alert alert-danger" hidden></div>'
        . '</div>'
        . '<div class="cpga-foot"><a href="https://underhost.com" target="_blank" rel="noopener"><i class="fas fa-bolt"></i> Powered by <strong>UnderHost</strong></a></div>'
        . '</div></div></div>'
        . '<link rel="stylesheet" href="' . $assets . '/ga.css?v=' . $ver . '">'
        . '<link rel="stylesheet" href="' . $assets . '/gsc.css?v=' . $ver . '">'
        . '<script>'
        . 'window.cpgaChartSrc = "' . $assets . '/chart.umd.min.js";'
        . 'window.cpgaEChartsSrc = "' . $assets . '/echarts.min.js";'
        . 'window.cpgaWorldGeoUrl = "' . $assets . '/world.geo.json?v=' . $ver . '";'
        . '</script>'
        . '<script src="' . $assets . '/geomap.js?v=' . $ver . '"></script>'
        . '<script src="' . $assets . '/gsc.js?v=' . $ver . '"></script>'
        . '<script src="' . $assets . '/ga.js?v=' . $ver . '"></script>';
}

function _cpga_state()
{
    // Tie the OAuth state to the admin session for CSRF protection.
    if (empty($_SESSION['cpga_state'])) {
        $_SESSION['cpga_state'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['cpga_state'];
}

/** Absolute URL of this module page (without query beyond module=...). */
function _cpga_current_module_url()
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    return $scheme . '://' . $host . $path . '?module=whmcs_analytics';
}

function _cpga_admin_url($moduleLink)
{
    return $moduleLink;
}
