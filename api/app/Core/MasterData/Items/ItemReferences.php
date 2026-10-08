<?php

namespace App\Core\MasterData\Items;

use App\Core\MasterData\Taxes\TaxCategory;
use Illuminate\Validation\ValidationException;

/**
 * MD-02: the rows an item points to (category, tax category, base and
 * other units) are re-read under the item writer's transaction with
 * `FOR SHARE` and must be active and, for categories, in the item's scope.
 * Archiving a unit or category locks its row `FOR UPDATE` before checking
 * active items, so the two never interleave: either the archive sees the
 * item, or the item writer sees the archive (422). Lock order: the items
 * sharing lock, then the item row, then these rows.
 */
class ItemReferences
{
    /**
     * @param  list<string>  $otherUomIds
     *
     * @throws ValidationException
     */
    public function assertActive(?string $companyId, ?string $categoryId, ?string $taxCategoryId, string $baseUomId, array $otherUomIds): void
    {
        $errors = [];

        if ($categoryId !== null) {
            $category = ItemCategory::query()->whereKey($categoryId)->sharedLock()->first();

            if ($category === null || $category->isArchived() || $category->company_id !== $companyId) {
                $errors['category_id'] = [__('core.item.category_other_scope')];
            }
        }

        if ($taxCategoryId !== null) {
            $taxCategory = TaxCategory::query()->whereKey($taxCategoryId)->sharedLock()->first();

            if ($taxCategory === null || $taxCategory->isArchived() || $taxCategory->company_id !== $companyId) {
                $errors['tax_category_id'] = [__('core.item.tax_category_other_scope')];
            }
        }

        $ids = array_values(array_unique([$baseUomId, ...$otherUomIds]));
        $active = Uom::query()->whereKey($ids)->orderBy('id')->sharedLock()->get()->reject(fn (Uom $uom) => $uom->isArchived())->modelKeys();

        if (! in_array($baseUomId, $active, true)) {
            $errors['base_uom_id'] = [__('core.item.uom_archived')];
        }

        if (array_diff($otherUomIds, $active) !== []) {
            $errors['uoms'] = [__('core.item.uom_archived')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function assertItem(Item $item): void
    {
        $this->assertActive(
            $item->company_id,
            $item->category_id,
            $item->tax_category_id,
            $item->base_uom_id,
            ItemUom::query()->where('item_id', $item->id)->pluck('uom_id')->all(),
        );
    }
}
