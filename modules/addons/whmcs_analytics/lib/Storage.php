<?php
/**
 * Storage facade — selects the Search Console history backend from the module
 * settings and delegates to the active driver. Three backends:
 *
 *   local  — the WHMCS MySQL database itself (zero setup; default)
 *   mysql  — an external MySQL server (host/port/name/user/pass)
 *   libsql — a remote libSQL / Turso / Bunny endpoint (URL + token)
 *
 * GA4 data is fetched live from Google and never uses this; only Search Console
 * history (weekly tracking, movers, opportunities) is stored.
 */

namespace WhmcsAnalytics;

use WhmcsAnalytics\Db\PdoMysqlDriver;
use WhmcsAnalytics\Db\LibSqlDriver;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/Db/Driver.php';
require_once __DIR__ . '/Db/PdoMysqlDriver.php';
require_once __DIR__ . '/Db/LibSqlDriver.php';

class Storage
{
    /** @var \WhmcsAnalytics\Db\Driver|null */
    protected static $driver = null;

    const BACKENDS = [
        'local'  => 'Local WHMCS database',
        'mysql'  => 'External MySQL database',
        'libsql' => 'libSQL / Turso (remote)',
    ];

    public static function backend()
    {
        $b = (string) Google::get('storage_backend', 'local');
        return isset(self::BACKENDS[$b]) ? $b : 'local';
    }

    public static function backendLabel($b = null)
    {
        $b = $b ?: self::backend();
        return self::BACKENDS[$b] ?? $b;
    }

    /** Build (once per request) the active driver from settings. */
    public static function driver()
    {
        if (self::$driver !== null) { return self::$driver; }
        $backend = self::backend();

        if ($backend === 'libsql') {
            self::$driver = new LibSqlDriver(
                (string) Google::get('libsql_url', ''),
                (string) Google::get('libsql_token', ''),
                (string) Google::get('libsql_ro_token', '')
            );
        } elseif ($backend === 'mysql') {
            self::$driver = new PdoMysqlDriver([
                'host'     => (string) Google::get('db_host', ''),
                'port'     => (int) (Google::get('db_port', 3306) ?: 3306),
                'database' => (string) Google::get('db_name', ''),
                'username' => (string) Google::get('db_user', ''),
                'password' => (string) Google::get('db_pass', ''),
            ]);
        } else { // local — reuse the WHMCS database credentials
            self::$driver = new PdoMysqlDriver(self::whmcsDbConfig());
        }
        return self::$driver;
    }

    /** Reset the cached driver (after settings change within one request). */
    public static function reset() { self::$driver = null; }

    /** WHMCS's own DB credentials, from the shared Capsule connection. */
    protected static function whmcsDbConfig()
    {
        try {
            $c = Capsule::connection()->getConfig();
        } catch (\Throwable $e) {
            $c = [];
        }
        return [
            'host'     => (string) ($c['host'] ?? '127.0.0.1'),
            'port'     => (int) ($c['port'] ?? 3306),
            'database' => (string) ($c['database'] ?? ''),
            'username' => (string) ($c['username'] ?? ''),
            'password' => (string) ($c['password'] ?? ''),
        ];
    }

    /* -------- delegated surface -------- */

    public static function dialect()          { return self::driver()->dialect(); }
    public static function isConfigured()      { return self::driver()->isConfigured(); }
    public static function unavailableReason() { return self::driver()->unavailableReason(); }
    public static function testConnection()    { return self::driver()->testConnection(); }

    public static function query($sql, array $params = [], $write = false) { return self::driver()->query($sql, $params, $write); }
    public static function execute($sql, array $params = [], $write = true) { return self::driver()->execute($sql, $params, $write); }
    public static function first($sql, array $params = [], $write = false) { return self::driver()->first($sql, $params, $write); }
    public static function scalar($sql, array $params = [], $write = false) { return self::driver()->scalar($sql, $params, $write); }
}
