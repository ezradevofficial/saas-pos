<?php

namespace App\Core\Sync;

use App\Core\Http\ApiException;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\Contracts\SyncSource;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NFR-04: one pull of a device: for each entity asked for, what changed
 * since the device's cursor.
 *
 * Incremental entities. Changes are the entity's rows (archived ones
 * included) whose (sync_xid, sync_seq) is past the cursor, merged with
 * its tombstones past the cursor, in that order, at most $limit. Only
 * changes of transactions below the horizon are handed out: the oldest
 * transaction id still running when the pull starts (pg_snapshot_xmin).
 * Every transaction below it has committed or rolled back, and no later
 * transaction can get an id below it, so nothing ever lands behind a
 * cursor: no gaps, whatever the commit order or clock. Rows written by a
 * transaction still running wait for the next pull. (In the `testing`
 * environment only, the pull's own transaction counts as committed for
 * itself: tests read inside their wrapping transaction.)
 *
 * What a changed id becomes is decided by its state now: the payload when
 * the device should hold it (visible and active), else a tombstone. A row
 * that changes again later shows up again later, so the device always
 * ends with the latest state. Ids repeat across pages only when the row
 * changed again in between.
 *
 * Snapshot entities are sent whole when their hash differs from the
 * device's cursor, and as `replace: true`: the device deletes what is not
 * in them.
 */
class SyncPuller
{
    public function __construct(
        private readonly SyncSources $sources,
        private readonly SnapshotCache $cache,
    ) {}

    /**
     * @param  list<string>  $keys  entities available to the tenant (validated)
     * @param  array<string, string>  $cursors
     * @return array<string, array<string, mixed>>
     */
    public function pull(DeviceScope $scope, array $keys, array $cursors, int $limit): array
    {
        $available = $this->sources->available();
        $horizon = $this->horizon();
        $result = [];

        foreach ($keys as $key) {
            $source = $available[$key] ?? throw new ApiException(422, 'unknown_entity', __('core.sync.unknown_entity', ['entity' => $key]));
            $cursor = isset($cursors[$key]) && $cursors[$key] !== '' ? SyncCursor::decode($key, $cursors[$key]) : null;

            $result[$key] = $source instanceof IncrementalSource
                ? $this->incremental($source, $scope, $cursor, $limit, $horizon)
                : $this->snapshot($source, $scope, $cursor);
        }

        return $result;
    }

    /**
     * @param  array{xmin: int, own: ?int}  $horizon
     * @return array<string, mixed>
     */
    private function incremental(IncrementalSource $source, DeviceScope $scope, ?SyncCursor $cursor, int $limit, array $horizon): array
    {
        $key = $source->key();
        if ($cursor !== null && $cursor->kind !== 'i') {
            throw SyncCursor::invalid($key);
        }

        $reset = $cursor !== null && $cursor->version !== $source->version();

        $from = ($cursor === null || $reset) ? SyncCursor::start($source->version()) : $cursor;
        $db = DB::connection(TenantContext::CONNECTION);
        $table = $source->table();

        $rows = $this->page(
            $source->visible($db->table($table), $scope)->select(["{$table}.id", "{$table}.sync_xid", "{$table}.sync_seq"]),
            $table, $from, $horizon, $limit,
        );

        $tombstones = $this->page(
            $db->table('sync_tombstones')
                ->where('sync_tombstones.entity', $key)
                ->where(fn (Builder $q) => $q->whereNull('sync_tombstones.company_id')->orWhere('sync_tombstones.company_id', $scope->companyId()))
                ->select(['sync_tombstones.record_id as id', 'sync_tombstones.sync_xid', 'sync_tombstones.sync_seq']),
            'sync_tombstones', $from, $horizon, $limit,
        );

        $changes = [...$rows, ...$tombstones];
        usort($changes, fn (object $a, object $b) => [(int) $a->sync_xid, (int) $a->sync_seq] <=> [(int) $b->sync_xid, (int) $b->sync_seq]);

        $hasMore = count($changes) > $limit;
        $changes = array_slice($changes, 0, $limit);
        $last = end($changes);
        $next = $last === false ? $from : SyncCursor::at($source->version(), (int) $last->sync_xid, (int) $last->sync_seq);

        $ids = array_values(array_unique(array_map(fn (object $change) => (string) $change->id, $changes)));
        $payloads = $ids === [] ? [] : $source->rows($ids, $scope);

        $upserts = [];
        $removed = [];

        foreach ($ids as $id) {
            if (isset($payloads[$id])) {
                $upserts[] = $payloads[$id];
            } else {
                $removed[] = $id;
            }
        }

        return [
            'mode' => SyncSource::INCREMENTAL,
            'reset' => $reset,
            'replace' => false,
            'upserts' => $upserts,
            'tombstones' => $removed,
            'cursor' => $next->encode(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Changes of $table after $from and below the horizon, at most
     * $limit + 1, in cursor order.
     *
     * @param  array{xmin: int, own: ?int}  $horizon
     * @return list<object>
     */
    private function page(Builder $query, string $table, SyncCursor $from, array $horizon, int $limit): array
    {
        return $query
            ->whereRaw("({$table}.sync_xid, {$table}.sync_seq) > (?, ?)", [$from->xid, $from->seq])
            ->where(fn (Builder $q) => $q
                ->where("{$table}.sync_xid", '<', $horizon['xmin'])
                ->when($horizon['own'] !== null, fn (Builder $q) => $q->orWhere("{$table}.sync_xid", $horizon['own'])))
            ->orderBy("{$table}.sync_xid")
            ->orderBy("{$table}.sync_seq")
            ->limit($limit + 1)
            ->get()
            ->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(SnapshotSource $source, DeviceScope $scope, ?SyncCursor $cursor): array
    {
        if ($cursor !== null && $cursor->kind !== 's') {
            throw SyncCursor::invalid($source->key());
        }

        $rows = $this->cache->rows($source, $scope, fn () => $source->rows($scope));
        $hash = hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $reset = $cursor !== null && $cursor->version !== $source->version();
        $unchanged = $cursor !== null && ! $reset && hash_equals($cursor->hash, $hash);

        return [
            'mode' => SyncSource::SNAPSHOT,
            'reset' => $reset,
            'replace' => ! $unchanged,
            'upserts' => $unchanged ? [] : $rows,
            'tombstones' => [],
            'cursor' => SyncCursor::snapshot($source->version(), $hash)->encode(),
            'has_more' => false,
        ];
    }

    /**
     * The oldest transaction id still running, and this transaction's own
     * id when it has one.
     *
     * @return array{xmin: int, own: ?int}
     */
    private function horizon(): array
    {
        $row = DB::connection(TenantContext::CONNECTION)->selectOne(
            'select pg_snapshot_xmin(pg_current_snapshot())::text::bigint as xmin, pg_current_xact_id_if_assigned()::text::bigint as own',
        );

        // Only tests read inside a writing transaction (RefreshDatabase); in
        // production a pull never writes before reading, and counting its own
        // uncommitted id would break the horizon's guarantee.
        $own = app()->environment('testing') && $row->own !== null ? (int) $row->own : null;

        return ['xmin' => (int) $row->xmin, 'own' => $own];
    }
}
