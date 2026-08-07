<?php
/**
 * MySQL storage driver (PDO) — backs the "Local WHMCS database" and
 * "External MySQL" options. Opens its own PDO connection (isolated from WHMCS's
 * shared Capsule connection) and enables ANSI_QUOTES so the store's SQLite-style
 * "double-quoted" identifiers work unchanged. MySQL dialect for DDL + upserts.
 *
 * @param array $cfg host, port, database, username, password[, label]
 */

namespace WhmcsAnalytics\Db;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class PdoMysqlDriver implements Driver
{
    /** @var array */
    protected $cfg;
    /** @var \PDO|null */
    protected $pdo = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + ['host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''];
    }

    public function dialect() { return 'mysql'; }

    public function isConfigured()
    {
        return $this->cfg['host'] !== '' && $this->cfg['database'] !== ''
            && $this->cfg['username'] !== '' && extension_loaded('pdo_mysql');
    }

    public function unavailableReason()
    {
        if (!extension_loaded('pdo_mysql')) { return 'PHP pdo_mysql extension is not available.'; }
        if ($this->cfg['host'] === '')     { return 'Database host is not set.'; }
        if ($this->cfg['database'] === '') { return 'Database name is not set.'; }
        if ($this->cfg['username'] === '') { return 'Database username is not set.'; }
        return '';
    }

    public function testConnection()
    {
        $r = $this->unavailableReason();
        if ($r !== '') { return ['ok' => false, 'message' => $r]; }
        try {
            $this->pdo();
            $v = $this->scalar('SELECT VERSION()');
            return ['ok' => true, 'message' => 'Connected to MySQL ' . $v . '.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    protected function pdo()
    {
        if ($this->pdo instanceof \PDO) { return $this->pdo; }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->cfg['host'], (int) $this->cfg['port'], $this->cfg['database']);
        $pdo = new \PDO($dsn, $this->cfg['username'], $this->cfg['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // ANSI_QUOTES lets "identifier" quoting match the libSQL/SQLite dialect.
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        $this->pdo = $pdo;
        return $pdo;
    }

    /* -------- query surface -------- */

    public function query($sql, array $params = [], $write = false)
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll();
    }

    public function execute($sql, array $params = [], $write = true)
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute(array_values($params));
        return $st->rowCount();
    }

    public function first($sql, array $params = [], $write = false)
    {
        $rows = $this->query($sql, $params, $write);
        return $rows[0] ?? null;
    }

    public function scalar($sql, array $params = [], $write = false)
    {
        $row = $this->first($sql, $params, $write);
        if (!$row) { return null; }
        return reset($row);
    }

    /* -------- dialect helpers -------- */

    public function createSchema(array $dimensions)
    {
        $pdo = $this->pdo();
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC';
        foreach ($dimensions as $def) {
            [$table, $col] = $def;
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$table} (
                    d VARCHAR(10) NOT NULL,
                    \"{$col}\" VARCHAR(512) NOT NULL,
                    clicks INT NOT NULL DEFAULT 0,
                    impressions INT NOT NULL DEFAULT 0,
                    position DOUBLE NOT NULL DEFAULT 0,
                    PRIMARY KEY (d, \"{$col}\"),
                    KEY ix_{$table}_key (\"{$col}\"),
                    KEY ix_{$table}_d (d)
                ) {$opts}"
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gsc_totals_daily (
                d VARCHAR(10) NOT NULL,
                clicks INT NOT NULL DEFAULT 0,
                impressions INT NOT NULL DEFAULT 0,
                position DOUBLE NOT NULL DEFAULT 0,
                PRIMARY KEY (d)
            ) {$opts}");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gsc_query_meta (
                \"query\" VARCHAR(512) NOT NULL,
                first_seen VARCHAR(10), last_seen VARCHAR(10),
                best_position DOUBLE, worst_position DOUBLE,
                PRIMARY KEY (\"query\")
            ) {$opts}");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gsc_sync_state (
                site VARCHAR(255) NOT NULL,
                last_date VARCHAR(10), backfill_cursor VARCHAR(10),
                backfill_start VARCHAR(10), updated_at VARCHAR(19),
                PRIMARY KEY (site)
            ) {$opts}");
    }

    public function upsertRows($table, array $cols, array $keyCols, array $rows)
    {
        if (!$rows) { return 0; }
        $width   = count($cols);
        $colsSql = '(' . implode(',', array_map([$this, 'q'], $cols)) . ')';
        $setCols = array_values(array_diff($cols, $keyCols));
        $setSql  = implode(',', array_map(function ($c) { return $this->q($c) . '=VALUES(' . $this->q($c) . ')'; }, $setCols));
        $suffix  = "ON DUPLICATE KEY UPDATE {$setSql}";

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($rows, 150) as $chunk) {
                $ph = []; $params = [];
                foreach ($chunk as $r) {
                    $ph[] = '(' . implode(',', array_fill(0, $width, '?')) . ')';
                    for ($i = 0; $i < $width; $i++) { $params[] = $r[$i] ?? null; }
                }
                $st = $pdo->prepare("INSERT INTO {$table} {$colsSql} VALUES " . implode(',', $ph) . ' ' . $suffix);
                $st->execute($params);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return count($rows);
    }

    public function mergeSuffix($table, array $keyCols, array $minCols, array $maxCols)
    {
        $sets = [];
        foreach ($minCols as $c) { $sets[] = $this->q($c) . '=LEAST(' . $this->q($c) . ',VALUES(' . $this->q($c) . '))'; }
        foreach ($maxCols as $c) { $sets[] = $this->q($c) . '=GREATEST(' . $this->q($c) . ',VALUES(' . $this->q($c) . '))'; }
        return 'ON DUPLICATE KEY UPDATE ' . implode(',', $sets);
    }

    public function weekExpr($dateCol)
    {
        return "DATE_FORMAT({$dateCol}, '%Y-%u')";
    }

    protected function q($id) { return '"' . str_replace('"', '""', $id) . '"'; }
}
