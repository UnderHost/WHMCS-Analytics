<?php
/**
 * Google Search Console historical store — backend-agnostic.
 *
 * Owns the schema and all read/write access for stored Search Console data:
 *   - per-dimension daily tables (query / page / country / device / appearance)
 *   - a daily totals table
 *   - a per-query meta table (first/last seen, best/worst position)
 *   - a per-site sync-state table
 *
 * The active backend (Local WHMCS DB / external MySQL / libSQL) is chosen in the
 * module settings; see Storage + the Db\* drivers. SQL uses SQLite-style
 * "double-quoted" identifiers, which the MySQL driver enables via ANSI_QUOTES.
 * Dialect-specific pieces (schema DDL, upsert clauses, week bucket) come from the
 * driver, so this class stays portable.
 *
 * Weekly aggregation and week-over-week / period-over-period comparisons are
 * computed from the daily tables at read time. Average position is always
 * impression-weighted:  SUM(position*impressions) / SUM(impressions).
 *
 * Position semantics: a LOWER number is better. Improvement is measured as
 *   position_change = previous_position - current_position   (positive = better)
 */

namespace WhmcsAnalytics;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class GscStore
{
    /** dimension key => [table, column] */
    const DIMENSIONS = [
        'query'      => ['gsc_query_daily', 'query'],
        'page'       => ['gsc_page_daily', 'page'],
        'country'    => ['gsc_country_daily', 'country'],
        'device'     => ['gsc_device_daily', 'device'],
        'appearance' => ['gsc_appearance_daily', 'appearance'],
    ];

    /* ------------------------------------------------------------------ */
    /* Schema                                                              */
    /* ------------------------------------------------------------------ */

    public static function ensureSchema()
    {
        Storage::driver()->createSchema(self::DIMENSIONS);
    }

    public static function dim($dim)
    {
        if (!isset(self::DIMENSIONS[$dim])) {
            throw new \InvalidArgumentException('Unknown Search Console dimension: ' . $dim);
        }
        return self::DIMENSIONS[$dim];
    }

    /* ------------------------------------------------------------------ */
    /* Writes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Upsert daily rows for a dimension.
     * @param array $rows list of [date, key, clicks, impressions, position]
     */
    public static function upsertDaily($dim, array $rows)
    {
        if (!$rows) { return 0; }
        [$table, $col] = self::dim($dim);
        return Storage::driver()->upsertRows(
            $table,
            ['d', $col, 'clicks', 'impressions', 'position'],
            ['d', $col],
            $rows
        );
    }

    /**
     * Upsert daily totals rows.
     * @param array $rows list of [date, clicks, impressions, position]
     */
    public static function upsertTotals(array $rows)
    {
        if (!$rows) { return 0; }
        return Storage::driver()->upsertRows(
            'gsc_totals_daily',
            ['d', 'clicks', 'impressions', 'position'],
            ['d'],
            $rows
        );
    }

    /** Recompute per-query meta for queries touched in [start,end] (merge min/max). */
    public static function refreshQueryMeta($start, $end)
    {
        $suffix = Storage::driver()->mergeSuffix(
            'gsc_query_meta',
            ['query'],
            ['first_seen', 'best_position'],   // kept as the minimum
            ['last_seen', 'worst_position']    // kept as the maximum
        );
        $sql = "INSERT INTO gsc_query_meta (\"query\", first_seen, last_seen, best_position, worst_position)
                SELECT \"query\", MIN(d), MAX(d), MIN(position), MAX(position)
                FROM gsc_query_daily
                WHERE d >= ? AND d <= ? AND impressions > 0
                GROUP BY \"query\"
                {$suffix}";
        Storage::execute($sql, [$start, $end], true);
    }

    /* ------------------------------------------------------------------ */
    /* Sync state                                                          */
    /* ------------------------------------------------------------------ */

    public static function getState($site)
    {
        return Storage::first('SELECT * FROM gsc_sync_state WHERE site = ?', [$site]);
    }

    public static function saveState($site, array $fields)
    {
        $cols = ['last_date', 'backfill_cursor', 'backfill_start'];
        $existing = self::getState($site) ?: [];
        $vals = [];
        foreach ($cols as $c) {
            $vals[$c] = array_key_exists($c, $fields) ? $fields[$c] : ($existing[$c] ?? null);
        }
        Storage::driver()->upsertRows(
            'gsc_sync_state',
            ['site', 'last_date', 'backfill_cursor', 'backfill_start', 'updated_at'],
            ['site'],
            [[$site, $vals['last_date'], $vals['backfill_cursor'], $vals['backfill_start'], gmdate('Y-m-d H:i:s')]]
        );
    }

    /** Earliest and latest stored dates + row count (for status display). */
    public static function coverage()
    {
        $row = Storage::first('SELECT MIN(d) AS min_d, MAX(d) AS max_d, COUNT(*) AS n FROM gsc_query_daily');
        return [
            'min_date' => $row['min_d'] ?? null,
            'max_date' => $row['max_d'] ?? null,
            'rows'     => (int) ($row['n'] ?? 0),
        ];
    }

    public static function hasData()
    {
        try {
            return (int) Storage::scalar('SELECT COUNT(*) FROM gsc_query_daily') > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reads: comparison view                                              */
    /* ------------------------------------------------------------------ */

    /** SQL fragment: impression-weighted average position expression. */
    protected static function weightedPos()
    {
        return 'CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE NULL END';
    }

    /**
     * Full comparison of a dimension between the current and previous periods.
     * Returns joined rows (top $limit by current impressions) with raw
     * cur_/prev_ metrics; callers compute deltas/movement/sort in PHP.
     *
     * Uses a portable UNION-of-keys + LEFT JOIN (works on both MySQL and libSQL;
     * MySQL has no FULL OUTER JOIN).
     *
     * @return array rows: k, cur_clicks, cur_impr, cur_pos, prev_clicks, prev_impr, prev_pos, in_cur, in_prev
     */
    public static function compareAll($dim, $curStart, $curEnd, $prevStart, $prevEnd, $limit = 5000)
    {
        [$table, $col] = self::dim($dim);
        $wp = self::weightedPos();
        $sql = "WITH cur AS (
                    SELECT \"{$col}\" AS k, SUM(clicks) c, SUM(impressions) i, {$wp} p
                    FROM {$table} WHERE d >= ? AND d <= ? GROUP BY \"{$col}\"
                ), prev AS (
                    SELECT \"{$col}\" AS k, SUM(clicks) c, SUM(impressions) i, {$wp} p
                    FROM {$table} WHERE d >= ? AND d <= ? GROUP BY \"{$col}\"
                ), ks AS (
                    SELECT k FROM cur UNION SELECT k FROM prev
                )
                SELECT ks.k AS k,
                       COALESCE(cur.c, 0) AS cur_clicks, COALESCE(cur.i, 0) AS cur_impr, cur.p AS cur_pos,
                       COALESCE(prev.c, 0) AS prev_clicks, COALESCE(prev.i, 0) AS prev_impr, prev.p AS prev_pos,
                       CASE WHEN cur.k IS NULL THEN 0 ELSE 1 END AS in_cur,
                       CASE WHEN prev.k IS NULL THEN 0 ELSE 1 END AS in_prev
                FROM ks
                LEFT JOIN cur ON cur.k = ks.k
                LEFT JOIN prev ON prev.k = ks.k
                ORDER BY COALESCE(cur.i, 0) DESC, COALESCE(prev.i, 0) DESC
                LIMIT ?";
        return Storage::query($sql, [$curStart, $curEnd, $prevStart, $prevEnd, (int) $limit]);
    }

    /**
     * Exact movement + position-bucket summary counts computed server-side over
     * ALL rows of the dimension (not just the table page).
     */
    public static function summary($dim, $curStart, $curEnd, $prevStart, $prevEnd, $moveThreshold = 1.0)
    {
        [$table, $col] = self::dim($dim);
        $wp = self::weightedPos();
        $t = (float) $moveThreshold;
        $sql = "WITH cur AS (
                    SELECT \"{$col}\" AS k, SUM(impressions) i, {$wp} p
                    FROM {$table} WHERE d >= ? AND d <= ? GROUP BY \"{$col}\"
                ), prev AS (
                    SELECT \"{$col}\" AS k, {$wp} p
                    FROM {$table} WHERE d >= ? AND d <= ? GROUP BY \"{$col}\"
                ), ks AS (
                    SELECT k FROM cur UNION SELECT k FROM prev
                ), j AS (
                    SELECT ks.k AS k, cur.i AS cur_i, cur.p AS cur_p, prev.p AS prev_p,
                           CASE WHEN cur.k IS NULL THEN 0 ELSE 1 END AS in_cur,
                           CASE WHEN prev.k IS NULL THEN 0 ELSE 1 END AS in_prev
                    FROM ks
                    LEFT JOIN cur ON cur.k = ks.k
                    LEFT JOIN prev ON prev.k = ks.k
                )
                SELECT
                    SUM(in_cur) AS total_cur,
                    SUM(CASE WHEN in_cur=1 AND in_prev=1 AND (prev_p - cur_p) >= {$t} THEN 1 ELSE 0 END) AS improved,
                    SUM(CASE WHEN in_cur=1 AND in_prev=1 AND (cur_p - prev_p) >= {$t} THEN 1 ELSE 0 END) AS declined,
                    SUM(CASE WHEN in_cur=1 AND in_prev=1 AND ABS(prev_p - cur_p) < {$t} THEN 1 ELSE 0 END) AS unchanged,
                    SUM(CASE WHEN in_cur=1 AND in_prev=0 THEN 1 ELSE 0 END) AS new_kw,
                    SUM(CASE WHEN in_prev=1 AND in_cur=0 THEN 1 ELSE 0 END) AS lost_kw,
                    SUM(CASE WHEN in_cur=1 AND cur_p > 0 AND cur_p <= 3  THEN 1 ELSE 0 END) AS p1_3,
                    SUM(CASE WHEN in_cur=1 AND cur_p > 3 AND cur_p <= 10 THEN 1 ELSE 0 END) AS p4_10,
                    SUM(CASE WHEN in_cur=1 AND cur_p > 10 AND cur_p <= 20 THEN 1 ELSE 0 END) AS p11_20,
                    SUM(CASE WHEN in_cur=1 AND cur_p > 20 AND cur_p <= 50 THEN 1 ELSE 0 END) AS p21_50,
                    SUM(CASE WHEN in_cur=1 AND cur_p > 50 THEN 1 ELSE 0 END) AS p50p
                FROM j";
        $row = Storage::first($sql, [$curStart, $curEnd, $prevStart, $prevEnd]);
        return array_map('intval', $row ?: []);
    }

    /** Site-wide totals for a period (for CTR average / opportunity baselines). */
    public static function periodTotals($start, $end)
    {
        $wp = self::weightedPos();
        $row = Storage::first(
            "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions, {$wp} AS position
             FROM gsc_totals_daily WHERE d >= ? AND d <= ?",
            [$start, $end]
        );
        $clicks = (int) ($row['clicks'] ?? 0);
        $impr   = (int) ($row['impressions'] ?? 0);
        return [
            'clicks'      => $clicks,
            'impressions' => $impr,
            'position'    => ($row['position'] ?? null) !== null ? (float) $row['position'] : null,
            'ctr'         => $impr > 0 ? $clicks / $impr : 0.0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Reads: keyword detail (weekly history)                              */
    /* ------------------------------------------------------------------ */

    /**
     * Weekly history for a single query, oldest → newest.
     * @return array rows: week (YYYY-WW), week_start, clicks, impressions, ctr, position
     */
    public static function queryWeekly($query, $start = null, $end = null, $limitWeeks = 26)
    {
        $wp   = self::weightedPos();
        $week = Storage::driver()->weekExpr('d');
        $where = "\"query\" = ?";
        $params = [$query];
        if ($start) { $where .= ' AND d >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND d <= ?'; $params[] = $end; }
        $sql = "SELECT {$week} AS week, MIN(d) AS week_start,
                       SUM(clicks) AS clicks, SUM(impressions) AS impressions, {$wp} AS position
                FROM gsc_query_daily
                WHERE {$where}
                GROUP BY {$week}
                ORDER BY week_start DESC
                LIMIT ?";
        $params[] = (int) $limitWeeks;
        $rows = Storage::query($sql, $params);
        // return oldest → newest
        $rows = array_reverse($rows);
        foreach ($rows as &$r) {
            $impr = (int) $r['impressions'];
            $r['clicks']      = (int) $r['clicks'];
            $r['impressions'] = $impr;
            $r['ctr']         = $impr > 0 ? ((int) $r['clicks']) / $impr : 0.0;
            $r['position']    = ($r['position'] ?? null) !== null ? round((float) $r['position'], 2) : null;
        }
        return $rows;
    }

    public static function queryMeta($query)
    {
        return Storage::first('SELECT * FROM gsc_query_meta WHERE "query" = ?', [$query]);
    }
}
