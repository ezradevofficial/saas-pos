<?php

namespace App\Core\Sync\Contracts;

use App\Core\Sync\DeviceScope;

/**
 * NFR-04: a small entity (staff, taxes, payment methods, currencies,
 * rates, settings) sent whole. Its cursor is a hash of the rows: an
 * unchanged set costs the device nothing, a changed one replaces what the
 * device holds (anything missing from it is deleted there). Suits sets
 * whose membership depends on several tables (who works at a location),
 * where per-row tombstones would be fragile.
 */
interface SnapshotSource extends SyncSource
{
    /**
     * Every row the device should hold, each with an `id`, in a stable
     * order (the hash depends on it).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(DeviceScope $scope): array;
}
