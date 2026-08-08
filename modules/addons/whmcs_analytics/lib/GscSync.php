<?php
/**
 * WHMCS Analytics — Search Console → Bunny ingestion.
 *
 * Pulls Search Console daily data from Google and upserts it into the Bunny
 * (libSQL) historical store. Two phases per run:
 *   A) Incremental: re-fetch a trailing window (last N days) so fresh/partial
 *      data finalizes, and new days are captured.
 *   B) Backfill: walk backward one chunk (≈1 month) per invocation until the
 *      configured history window (default 12 months) is filled. Resumable via
 *      the gsc_sync_state.backfill_cursor.
 *
 * Time-boxed so it never blocks a web request or overruns cron. Each dimension
 * fetch is guarded independently so one failure can't abort the whole sync.
 *
 * (c) 2026 UnderHost.com — original work.
 */

namespace WhmcsAnalytics;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class GscSync
{
    /**
     * Dimensions we store daily by requesting [date, <dim>] together.
     * NOTE: searchAppearance is intentionally excluded — Google rejects grouping
     * it with any other dimension ("Cannot group by search appearance dimension
     * together with another dimension"), so it is fetched per-day separately.
     */
    const DIM_MAP = [
        'query'   => 'query',
        'page'    => 'page',
        'country' => 'country',
        'device'  => 'device',
    ];

    const TRAILING_DAYS   = 5;   // re-fetch window each run (finalize fresh data)
    const CHUNK_DAYS      = 30;  // backfill chunk size
    const DEFAULT_MONTHS  = 12;  // history window

    /**
     * Run a sync pass.
     *
     * @param array $opts  maxSeconds (time budget), maxChunks (backfill chunks
     *                     this run), months (history window override)
     * @return array status
     */
    public static function run($clientId, $clientSecret, array $opts = [])
    {
        $started    = microtime(true);
        $maxSeconds = (float) ($opts['maxSeconds'] ?? 40);
        $maxChunks  = (int) ($opts['maxChunks'] ?? 1);
        $months     = (int) ($opts['months'] ?? (int) Google::get('backfill_months', self::DEFAULT_MONTHS));
        if ($months < 1) { $months = self::DEFAULT_MONTHS; }

        $status = ['ok' => false, 'errors' => [], 'upserts' => 0, 'phases' => []];

        if (!Storage::isConfigured()) {
            $status['errors'][] = 'History storage is not configured: ' . Storage::unavailableReason();
            return $status;
        }
        if (!Google::isConnected()) {
            $status['errors'][] = 'Not connected to Google.';
            return $status;
        }
        $site = Google::get('sc_site');
        if (!$site) {
            $status['errors'][] = 'No Search Console site selected in the module settings.';
            return $status;
        }

        try {
            GscStore::ensureSchema();
        } catch (\Throwable $e) {
            $status['errors'][] = 'Schema init failed: ' . $e->getMessage();
            return $status;
        }

        $today      = date('Y-m-d');
        $targetStart = date('Y-m-d', strtotime("-{$months} months", strtotime($today)));
        $state      = GscStore::getState($site) ?: [];

        /* ---- Phase A: incremental trailing window ---- */
        $incrStart = date('Y-m-d', strtotime('-' . self::TRAILING_DAYS . ' days', strtotime($today)));
        $a = self::ingestRange($clientId, $clientSecret, $incrStart, $today);
        $status['upserts'] += $a['upserts'];
        $status['errors']   = array_merge($status['errors'], $a['errors']);
        $status['phases'][] = ['phase' => 'incremental', 'from' => $incrStart, 'to' => $today, 'rows' => $a['upserts']];

        $lastDate = $today;

        /* ---- Phase B: backfill chunks (walking backward) ---- */
        // cursor = earliest date NOT yet backfilled (exclusive upper bound of next chunk)
        $cursor = $state['backfill_cursor'] ?? date('Y-m-d', strtotime('-1 day', strtotime($incrStart)));
        $chunksDone = 0;
        while (
            $chunksDone < $maxChunks
            && strcmp($cursor, $targetStart) > 0
            && (microtime(true) - $started) < $maxSeconds
        ) {
            $chunkEnd   = $cursor;
            $chunkStart = date('Y-m-d', strtotime('-' . (self::CHUNK_DAYS - 1) . ' days', strtotime($chunkEnd)));
            if (strcmp($chunkStart, $targetStart) < 0) {
                $chunkStart = $targetStart;
            }
            $b = self::ingestRange($clientId, $clientSecret, $chunkStart, $chunkEnd);
            $status['upserts'] += $b['upserts'];
            $status['errors']   = array_merge($status['errors'], $b['errors']);
            $status['phases'][] = ['phase' => 'backfill', 'from' => $chunkStart, 'to' => $chunkEnd, 'rows' => $b['upserts']];

            // Move cursor to the day before this chunk's start.
            $cursor = date('Y-m-d', strtotime('-1 day', strtotime($chunkStart)));
            $chunksDone++;
            if (strcmp($chunkStart, $targetStart) <= 0) {
                $cursor = $targetStart; // reached the window start → done
                break;
            }
        }

        // Persist state.
        GscStore::saveState($site, [
            'last_date'       => $lastDate,
            'backfill_cursor' => $cursor,
            'backfill_start'  => $targetStart,
        ]);

        $status['ok']              = empty($status['errors']);
        $status['backfill_done']   = (strcmp($cursor, $targetStart) <= 0);
        $status['backfill_cursor'] = $cursor;
        $status['target_start']    = $targetStart;
        $status['coverage']        = self::safeCoverage();
        $status['elapsed']         = round(microtime(true) - $started, 1);
        return $status;
    }

    /**
     * Fetch every stored dimension for [start,end] from Google and upsert.
     * Each dimension is independent; failures are collected, not fatal.
     */
    protected static function ingestRange($clientId, $clientSecret, $start, $end)
    {
        $upserts = 0;
        $errors  = [];

        // Totals (date only)
        try {
            $res = Google::searchAnalyticsAll($clientId, $clientSecret, [
                'startDate'  => $start,
                'endDate'    => $end,
                'dimensions' => ['date'],
                'dataState'  => 'all',
            ]);
            if (isset($res['error'])) {
                $errors[] = 'totals: ' . Google::errText($res);
            } else {
                $rows = [];
                foreach (($res['rows'] ?? []) as $r) {
                    $d = $r['keys'][0] ?? '';
                    if ($d === '') { continue; }
                    $rows[] = [$d, (int) round($r['clicks'] ?? 0), (int) round($r['impressions'] ?? 0), (float) ($r['position'] ?? 0)];
                }
                $upserts += GscStore::upsertTotals($rows);
            }
        } catch (\Throwable $e) {
            $errors[] = 'totals: ' . $e->getMessage();
        }

        // Per-dimension (date + dimension)
        foreach (self::DIM_MAP as $gscDim => $storeKey) {
            try {
                $res = Google::searchAnalyticsAll($clientId, $clientSecret, [
                    'startDate'  => $start,
                    'endDate'    => $end,
                    'dimensions' => ['date', $gscDim],
                    'dataState'  => 'all',
                ]);
                if (isset($res['error'])) {
                    $errors[] = $storeKey . ': ' . Google::errText($res);
                    continue;
                }
                $rows = [];
                foreach (($res['rows'] ?? []) as $r) {
                    $d   = $r['keys'][0] ?? '';
                    $key = $r['keys'][1] ?? '';
                    if ($d === '' || $key === '') { continue; }
                    $rows[] = [
                        $d,
                        $key,
                        (int) round($r['clicks'] ?? 0),
                        (int) round($r['impressions'] ?? 0),
                        (float) ($r['position'] ?? 0),
                    ];
                }
                $upserts += GscStore::upsertDaily($storeKey, $rows);
            } catch (\Throwable $e) {
                $errors[] = $storeKey . ': ' . $e->getMessage();
            }
        }

        // searchAppearance can't be grouped with the date dimension, so fetch it
        // one day at a time (each request returns that day's appearance rows).
        try {
            $rows = [];
            $d = $start;
            $stop = false;
            while (!$stop && strcmp($d, $end) <= 0) {
                $res = Google::searchAnalyticsAll($clientId, $clientSecret, [
                    'startDate'  => $d,
                    'endDate'    => $d,
                    'dimensions' => ['searchAppearance'],
                    'dataState'  => 'all',
                ]);
                if (isset($res['error'])) {
                    // If the site doesn't support it, bail after one attempt.
                    $errors[] = 'appearance: ' . Google::errText($res);
                    $stop = true;
                    break;
                }
                foreach (($res['rows'] ?? []) as $r) {
                    $key = $r['keys'][0] ?? '';
                    if ($key === '') { continue; }
                    $rows[] = [
                        $d,
                        $key,
                        (int) round($r['clicks'] ?? 0),
                        (int) round($r['impressions'] ?? 0),
                        (float) ($r['position'] ?? 0),
                    ];
                }
                $d = date('Y-m-d', strtotime('+1 day', strtotime($d)));
            }
            $upserts += GscStore::upsertDaily('appearance', $rows);
        } catch (\Throwable $e) {
            $errors[] = 'appearance: ' . $e->getMessage();
        }

        // Refresh per-query meta for the window.
        try {
            GscStore::refreshQueryMeta($start, $end);
        } catch (\Throwable $e) {
            $errors[] = 'meta: ' . $e->getMessage();
        }

        return ['upserts' => $upserts, 'errors' => $errors];
    }

    protected static function safeCoverage()
    {
        try {
            return GscStore::coverage();
        } catch (\Throwable $e) {
            return ['min_date' => null, 'max_date' => null, 'rows' => 0];
        }
    }
}
