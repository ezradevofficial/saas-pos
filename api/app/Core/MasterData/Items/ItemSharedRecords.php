<?php

namespace App\Core\MasterData\Items;

use App\Core\MasterData\Sharing\SharedRecords;

/**
 * TEN-08: items follow the `items` sharing mode. Their barcodes follow
 * the item's company (database trigger). Uniqueness of codes and barcodes
 * in the new scope is checked first by ItemCodesGuard.
 */
class ItemSharedRecords implements SharedRecords
{
    public function unassignedCount(): int
    {
        return Item::query()->whereNull('company_id')->count();
    }

    public function assignTo(string $companyId): array
    {
        $count = 0;

        Item::query()->whereNull('company_id')->lazyById()->each(function (Item $item) use ($companyId, &$count) {
            $item->company_id = $companyId;
            $item->save();
            $count++;
        });

        return ['assigned' => $count];
    }

    public function release(): array
    {
        $count = 0;

        Item::query()->whereNotNull('company_id')->lazyById()->each(function (Item $item) use (&$count) {
            $item->company_id = null;
            $item->save();
            $count++;
        });

        return ['released' => $count];
    }
}
