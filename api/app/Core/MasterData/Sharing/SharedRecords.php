<?php

namespace App\Core\MasterData\Sharing;

/**
 * TEN-08: the records of one master data type that follow its sharing
 * mode (parties for customers, tax categories for items ...). Registered
 * with MasterDataSharing::records(); called inside the switch transaction,
 * after the new mode is stored. Changes go through Eloquent, so each
 * record's change is audited (MD-07). Archived records count too: a
 * switch never orphans one.
 */
interface SharedRecords
{
    /** Records with no company that a switch to per_company must assign. */
    public function unassignedCount(): int;

    /**
     * Assign every record with no company to $companyId. Returns counts by
     * name, summed into the switch result and its audit entry: at least
     * `assigned`, plus what else changed (e.g. `price_lists_cleared`).
     *
     * @return array<string, int>
     */
    public function assignTo(string $companyId): array;

    /**
     * Clear the company of every record now shared. Returns counts by name,
     * at least `released`.
     *
     * @return array<string, int>
     */
    public function release(): array;
}
