<?php

namespace App\Core\MasterData\Items;

use App\Core\MasterData\SharedRecordPolicy;

/**
 * RBAC-04, TEN-08 for items (MD-02): the rules of SharedRecordPolicy with
 * `core.item.*`. A cashier with `core.item.view` at a location sees the
 * group's shared items and those of their company when items are kept
 * per company; editing a company's item needs `core.item.edit` covering
 * the company. Images are changed with `core.item.edit`.
 */
class ItemPolicy extends SharedRecordPolicy
{
    public const PERMISSIONS = ['core.item.view', 'core.item.create', 'core.item.edit', 'core.item.archive'];

    protected function resource(): string
    {
        return 'core.item';
    }
}
