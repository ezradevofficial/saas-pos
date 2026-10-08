<?php

namespace App\Core\MasterData\Parties;

use App\Core\MasterData\SharedRecordPolicy;

/**
 * RBAC-04, TEN-08 for parties (ADR 006, "Shared master data"): the rules
 * of SharedRecordPolicy with `core.party.*`. A cashier with
 * `core.party.view` at a location sees the group's shared customers; a
 * branch user reads and adds the company's suppliers but does not change
 * them.
 */
class PartyPolicy extends SharedRecordPolicy
{
    public const PERMISSIONS = ['core.party.view', 'core.party.create', 'core.party.edit', 'core.party.archive'];

    protected function resource(): string
    {
        return 'core.party';
    }
}
