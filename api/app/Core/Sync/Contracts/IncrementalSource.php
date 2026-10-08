<?php

namespace App\Core\Sync\Contracts;

use App\Core\Sync\DeviceScope;
use Illuminate\Database\Query\Builder;

/**
 * NFR-04: an entity pulled in pages of changes since a cursor.
 *
 * The table needs `sync_xid` and `sync_seq` stamped by the `sync_stamp`
 * trigger, an index on (tenant_id, sync_xid, sync_seq), and, when rows can
 * leave a device's scope or be deleted, a trigger writing `sync_tombstones`
 * rows under key() (migration 2026_10_19_000100). Archived rows need
 * nothing more: rows() leaves them out and the device gets a tombstone.
 */
interface IncrementalSource extends SyncSource
{
    /** The table whose `sync_xid`/`sync_seq` mark changes. */
    public function table(): string;

    /**
     * Constrain $query (on table(), archived rows included) to the rows the
     * device may hold: its company's and the group's shared rows, and so on.
     */
    public function visible(Builder $query, DeviceScope $scope): Builder;

    /**
     * The payloads of those $ids the device should hold now (visible and
     * active), keyed by id. Any id left out becomes a tombstone. Load in a
     * fixed number of queries, whatever the count.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function rows(array $ids, DeviceScope $scope): array;
}
