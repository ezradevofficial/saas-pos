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

    /** Assign every record with no company to $companyId; returns how many. */
    public function assignTo(string $companyId): int;

    /** Clear the company of every record now shared; returns how many. */
    public function release(): int;
}
