<?php

namespace App\Core\Sync;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NFR-04, ADR 004: how far device sync is held back. Pulls hand out only
 * changes of transactions older than the oldest one still running anywhere
 * on the cluster (pg_snapshot_xmin), so a long or forgotten transaction
 * delays every device (never loses a row: rows are never skipped).
 *
 * - `xid_lag`: transaction ids between that horizon and the next id
 *   (pg_snapshot_xmax); small when nothing is held back.
 * - `oldest_transaction_seconds`: age of the oldest transaction holding an
 *   id among the sessions this role can see (its own; other roles' are
 *   hidden without pg_read_all_stats), and of prepared transactions.
 * - `prepared_transactions`: should be 0 (max_prepared_transactions = 0).
 *
 * Shown on GET /up (header `X-Sync-Lag-Seconds`) and by `sync:lag`;
 * logged as a warning (at most once a minute) above
 * `sync.lag_warning_seconds`.
 */
class SyncLag
{
    /** @return array{xid_lag: int, oldest_transaction_seconds: float, prepared_transactions: int} */
    public function measure(): array
    {
        $db = DB::connection(TenantContext::CONNECTION);

        $horizon = $db->selectOne('select pg_snapshot_xmax(s)::text::bigint - pg_snapshot_xmin(s)::text::bigint as lag from pg_current_snapshot() s');
        $oldest = $db->selectOne(<<<'SQL'
            select coalesce(max(age), 0) as seconds from (
                select extract(epoch from now() - xact_start) as age from pg_stat_activity
                where backend_xid is not null and pid <> pg_backend_pid()
                union all
                select extract(epoch from now() - prepared) from pg_prepared_xacts
            ) t
            SQL);
        $prepared = $db->selectOne('select count(*) as n from pg_prepared_xacts');

        return [
            'xid_lag' => (int) $horizon->lag,
            'oldest_transaction_seconds' => round((float) $oldest->seconds, 3),
            'prepared_transactions' => (int) $prepared->n,
        ];
    }

    /** measure(), logging a warning above the threshold; null when the database cannot say. */
    public function check(): ?array
    {
        try {
            $lag = $this->measure();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($this->lagging($lag) && Cache::add('sync-lag-warned', true, 60)) {
            Log::warning('Device sync is held back by a long transaction (ADR 004).', $lag);
        }

        return $lag;
    }

    /** @param array{oldest_transaction_seconds: float, prepared_transactions: int} $lag */
    public function lagging(array $lag): bool
    {
        return $lag['oldest_transaction_seconds'] > (int) config('sync.lag_warning_seconds', 120) || $lag['prepared_transactions'] > 0;
    }
}
