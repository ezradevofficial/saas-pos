<?php

namespace App\Core\Sync\Contracts;

/**
 * NFR-04: one kind of data a POS device keeps offline (an "entity"). Core
 * and modules register sources with SyncSources; a source of a module the
 * tenant has not activated is never served (RBAC-08).
 *
 * Implement IncrementalSource (large tables, pulled by cursor with
 * tombstones) or SnapshotSource (small sets, sent whole when they change).
 */
interface SyncSource
{
    public const INCREMENTAL = 'incremental';

    public const SNAPSHOT = 'snapshot';

    /** The entity name devices use (`items`, `staff`, `pos_number_ranges`): [a-z][a-z0-9_]*. */
    public function key(): string;

    /** `core`, or the module the entity belongs to (ModuleRegistry). */
    public function module(): string;

    /**
     * The payload's shape. Raise it when fields change meaning or rows
     * already on devices must be sent again: devices holding an older
     * cursor start the entity from scratch (`reset`).
     */
    public function version(): int;
}
