<?php
/**
 * Storage driver interface for the Search Console history store.
 *
 * Two dialects implement this: PdoMysqlDriver (WHMCS DB / external MySQL) and
 * LibSqlDriver (Bunny / Turso / any libSQL over HTTP). GA4 data is fetched live
 * from Google and never touches storage — only Search Console history does.
 *
 * All SQL in the store uses SQLite-style double-quoted identifiers ("col"); the
 * MySQL driver enables ANSI_QUOTES so the same identifiers work there too.
 * Dialect-specific pieces (schema DDL, upsert conflict clauses, the week-bucket
 * expression) are owned by the driver so the store stays dialect-agnostic.
 */

namespace WhmcsAnalytics\Db;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

interface Driver
{
    /** 'mysql' | 'libsql' — informational; the store rarely needs it. */
    public function dialect();

    /** True when the driver has everything it needs to connect. */
    public function isConfigured();

    /** Human-readable reason the driver is unusable, or '' when fine. */
    public function unavailableReason();

    /** Attempt a live connection. @return array{ok:bool,message:string} */
    public function testConnection();

    /* -------- generic query surface (params are ? placeholders) -------- */

    /** Run one statement, return rows as associative arrays. */
    public function query($sql, array $params = [], $write = false);

    /** Run one statement, return affected row count. */
    public function execute($sql, array $params = [], $write = true);

    /** First row (assoc) or null. */
    public function first($sql, array $params = [], $write = false);

    /** Scalar of the first column of the first row, or null. */
    public function scalar($sql, array $params = [], $write = false);

    /* -------- dialect-specific helpers used by the store -------- */

    /** Create all history tables/indexes (idempotent). */
    public function createSchema(array $dimensions);

    /**
     * Chunked "replace on key conflict" upsert.
     * @param string   $table
     * @param string[] $cols     ordered column names (unquoted)
     * @param string[] $keyCols  primary-key columns (subset of $cols)
     * @param array[]  $rows     each a positional array matching $cols
     * @return int rows processed
     */
    public function upsertRows($table, array $cols, array $keyCols, array $rows);

    /**
     * ON-CONFLICT/ON-DUPLICATE suffix that merges by MIN()/MAX() — used to
     * fold per-query first/last-seen and best/worst-position meta.
     * @param string[] $minCols columns kept as the minimum of old vs new
     * @param string[] $maxCols columns kept as the maximum of old vs new
     */
    public function mergeSuffix($table, array $keyCols, array $minCols, array $maxCols);

    /** Expression that buckets column $dateCol into a "YYYY-WW" week label. */
    public function weekExpr($dateCol);
}
