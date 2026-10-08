<?php

namespace App\Core\MasterData\Items;

use App\Core\MasterData\SharedRecordPolicy;

/**
 * RBAC-04, TEN-08 for item categories (MD-02): the rules of
 * SharedRecordPolicy with `core.item_category.*`.
 */
class ItemCategoryPolicy extends SharedRecordPolicy
{
    public const PERMISSIONS = ['core.item_category.view', 'core.item_category.create', 'core.item_category.edit', 'core.item_category.archive'];

    protected function resource(): string
    {
        return 'core.item_category';
    }
}
