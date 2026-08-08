<?php
/**
 * libSQL storage driver — talks the libSQL HTTP "pipeline" protocol over cURL
 * (POST https://<host>/v2/pipeline). Dependency-free, no SDK. Works with Bunny
 * Database, Turso, or any self-hosted libSQL/sqld endpoint.
 *
 * Credentials come from the module settings (URL + full-access token + optional
 * read-only token). Reads use the read-only token when present; writes/DDL use
 * the full-access token. SQLite dialect.
 */

namespace WhmcsAnalytics\Db;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class LibSqlDriver implements Driver
{
    protected $url;
    protected $token;
    protected $roToken;

    public function __construct($url, $token, $roToken = '')
    {
        $this->url     = self::endpoint($url);
        $this->token   = (string) $token;
        $this->roToken = $roToken !== '' ? (string) $roToken : (string) $token;
    }

    public function dialect() { return 'libsql'; }

    public function isConfigured()
    {
        return $this->url !== '' && $this->token !== '' && function_exists('curl_init');
    }

    public function unavailableReason()
    {
        if ($this->url === '')   { return 'libSQL URL is not set.'; }
        if ($this->token === '') { return 'libSQL token is not set.'; }
        if (!function_exists('curl_init')) { return 'PHP cURL extension is not available.'; }
        return '';
    }

    public function testConnection()
    {
        $r = $this->unavailableReason();
        if ($r !== '') { return ['ok' => false, 'message' => $r]; }
        try {
            $this->query('SELECT 1');
            return ['ok' => true, 'message' => 'Connected to libSQL endpoint.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Query surface                                                       */
    /* ------------------------------------------------------------------ */

    public function query($sql, array $params = [], $write = false)
    {
        $res = $this->pipeline([$this->executeReq($sql, $params)], $write);
        return $this->rowsFrom($res, 0);
    }

    public function execute($sql, array $params = [], $write = true)
    {
        $res = $this->pipeline([$this->executeReq($sql, $params)], $write);
        $r = $res[0]['response']['result'] ?? [];
        return (int) ($r['affected_row_count'] ?? 0);
    }

    public function batch(array $statements, $write = true)
    {
        if (!$statements) { return 0; }
        $requests = [$this->executeReq('BEGIN', [])];
        foreach ($statements as $st) {
            if (isset($st['sql'])) { $requests[] = $this->executeReq($st['sql'], $st['params'] ?? []); }
            else { $requests[] = $this->executeReq($st[0], $st[1] ?? []); }
        }
        $requests[] = $this->executeReq('COMMIT', []);
        $this->pipeline($requests, $write);
        return count($statements);
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

    /* ------------------------------------------------------------------ */
    /* Dialect helpers                                                     */
    /* ------------------------------------------------------------------ */

    public function createSchema(array $dimensions)
    {
        $stmts = [];
        foreach ($dimensions as $def) {
            [$table, $col] = $def;
            $stmts[] = ['sql' =>
                "CREATE TABLE IF NOT EXISTS {$table} (
                    d TEXT NOT NULL,
                    \"{$col}\" TEXT NOT NULL,
                    clicks INTEGER NOT NULL DEFAULT 0,
                    impressions INTEGER NOT NULL DEFAULT 0,
                    position REAL NOT NULL DEFAULT 0,
                    PRIMARY KEY (d, \"{$col}\")
                )"];
            $stmts[] = ['sql' => "CREATE INDEX IF NOT EXISTS ix_{$table}_key ON {$table} (\"{$col}\")"];
            $stmts[] = ['sql' => "CREATE INDEX IF NOT EXISTS ix_{$table}_d ON {$table} (d)"];
        }
        $stmts[] = ['sql' =>
            "CREATE TABLE IF NOT EXISTS gsc_totals_daily (
                d TEXT PRIMARY KEY,
                clicks INTEGER NOT NULL DEFAULT 0,
                impressions INTEGER NOT NULL DEFAULT 0,
                position REAL NOT NULL DEFAULT 0
            )"];
        $stmts[] = ['sql' =>
            "CREATE TABLE IF NOT EXISTS gsc_query_meta (
                \"query\" TEXT PRIMARY KEY,
                first_seen TEXT, last_seen TEXT,
                best_position REAL, worst_position REAL
            )"];
        $stmts[] = ['sql' =>
            "CREATE TABLE IF NOT EXISTS gsc_sync_state (
                site TEXT PRIMARY KEY,
                last_date TEXT, backfill_cursor TEXT, backfill_start TEXT, updated_at TEXT
            )"];
        $this->batch($stmts, true);
    }

    public function upsertRows($table, array $cols, array $keyCols, array $rows)
    {
        if (!$rows) { return 0; }
        $width   = count($cols);
        $colsSql = '(' . implode(',', array_map([$this, 'q'], $cols)) . ')';
        $keySql  = implode(',', array_map([$this, 'q'], $keyCols));
        $setCols = array_values(array_diff($cols, $keyCols));
        $setSql  = implode(',', array_map(function ($c) { return $this->q($c) . '=excluded.' . $this->q($c); }, $setCols));
        $suffix  = "ON CONFLICT({$keySql}) DO UPDATE SET {$setSql}";

        $statements = [];
        foreach (array_chunk($rows, 150) as $chunk) {
            $ph = []; $params = [];
            foreach ($chunk as $r) {
                $ph[] = '(' . implode(',', array_fill(0, $width, '?')) . ')';
                for ($i = 0; $i < $width; $i++) { $params[] = $r[$i] ?? null; }
            }
            $statements[] = [
                'sql'    => "INSERT INTO {$table} {$colsSql} VALUES " . implode(',', $ph) . ' ' . $suffix,
                'params' => $params,
            ];
        }
        $this->batch($statements, true);
        return count($rows);
    }

    public function mergeSuffix($table, array $keyCols, array $minCols, array $maxCols)
    {
        $keySql = implode(',', array_map([$this, 'q'], $keyCols));
        $sets = [];
        foreach ($minCols as $c) { $sets[] = $this->q($c) . "=MIN({$table}." . $this->q($c) . ',excluded.' . $this->q($c) . ')'; }
        foreach ($maxCols as $c) { $sets[] = $this->q($c) . "=MAX({$table}." . $this->q($c) . ',excluded.' . $this->q($c) . ')'; }
        return "ON CONFLICT({$keySql}) DO UPDATE SET " . implode(',', $sets);
    }

    public function weekExpr($dateCol)
    {
        return "strftime('%Y-%W', {$dateCol})";
    }

    protected function q($id) { return '"' . str_replace('"', '""', $id) . '"'; }

    /* ------------------------------------------------------------------ */
    /* Protocol plumbing                                                   */
    /* ------------------------------------------------------------------ */

    protected function executeReq($sql, array $params)
    {
        return ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => array_map([self::class, 'arg'], $params)]];
    }

    protected static function arg($v)
    {
        if ($v === null)     { return ['type' => 'null']; }
        if (is_bool($v))     { return ['type' => 'integer', 'value' => $v ? '1' : '0']; }
        if (is_int($v))      { return ['type' => 'integer', 'value' => (string) $v]; }
        if (is_float($v))    { return ['type' => 'float', 'value' => $v]; }
        return ['type' => 'text', 'value' => (string) $v];
    }

    protected function pipeline(array $requests, $write)
    {
        if ($this->url === '') { throw new \RuntimeException('libSQL is not configured (missing URL).'); }
        $token = $write ? $this->token : $this->roToken;
        if ($token === '') { throw new \RuntimeException('libSQL token is not configured.'); }

        $requests[] = ['type' => 'close'];
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['requests' => $requests]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException('libSQL request failed: ' . $err); }
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) { throw new \RuntimeException('libSQL returned a non-JSON response (HTTP ' . $code . ').'); }
        if ($code >= 400) {
            $msg = $data['error'] ?? ('HTTP ' . $code);
            throw new \RuntimeException('libSQL error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $results = $data['results'] ?? [];
        foreach ($results as $r) {
            if (($r['type'] ?? '') === 'error') {
                throw new \RuntimeException('libSQL SQL error: ' . ($r['error']['message'] ?? 'unknown'));
            }
        }
        return $results;
    }

    protected function rowsFrom(array $results, $index)
    {
        $result = $results[$index]['response']['result'] ?? null;
        if (!$result) { return []; }
        $cols = array_map(function ($c) { return $c['name'] ?? ''; }, $result['cols'] ?? []);
        $out  = [];
        foreach (($result['rows'] ?? []) as $row) {
            $assoc = [];
            foreach ($row as $i => $cell) { $assoc[$cols[$i] ?? $i] = self::cellValue($cell); }
            $out[] = $assoc;
        }
        return $out;
    }

    protected static function cellValue($cell)
    {
        switch ($cell['type'] ?? 'null') {
            case 'null':    return null;
            case 'integer': return (int) ($cell['value'] ?? 0);
            case 'float':   return (float) ($cell['value'] ?? 0);
            case 'blob':    return base64_decode($cell['base64'] ?? '');
            default:        return $cell['value'] ?? '';
        }
    }

    /** Normalize a libsql://host URL into the https pipeline endpoint. */
    protected static function endpoint($url)
    {
        $url = trim((string) $url);
        if ($url === '') { return ''; }
        $url = preg_replace('#^libsql://#i', 'https://', $url);
        $url = preg_replace('#^wss://#i', 'https://', $url);
        $url = preg_replace('#^ws://#i', 'http://', $url);
        if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . $url; }
        $url = rtrim($url, '/');
        if (substr($url, -12) !== '/v2/pipeline') { $url .= '/v2/pipeline'; }
        return $url;
    }
}
