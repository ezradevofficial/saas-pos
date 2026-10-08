<?php

namespace App\Core\MasterData\Items;

use App\Core\MasterData\Sharing\SharedRecords;

/**
 * TEN-08: item categories follow the `items` sharing mode, so a category
 * and its items always share one scope. Counted with items in the switch
 * result (assigned, released).
 */
class ItemCategorySharedRecords implements SharedRecords
{
    public function unassignedCount(): int
    {
        return ItemCategory::query()->whereNull('company_id')->count();
    }

    public function assignTo(string $companyId): array
    {
        $count = 0;

        ItemCategory::query()->whereNull('company_id')->lazyById()->each(function (ItemCategory $category) use ($companyId, &$count) {
            $category->company_id = $companyId;
            $category->save();
            $count++;
        });

        return ['assigned' => $count];
    }

    public function release(): array
    {
        $count = 0;

        ItemCategory::query()->whereNotNull('company_id')->lazyById()->each(function (ItemCategory $category) use (&$count) {
            $category->company_id = null;
            $category->save();
            $count++;
        });

        return ['released' => $count];
    }
}
