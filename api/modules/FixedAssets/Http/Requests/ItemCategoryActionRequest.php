<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/item-categories/{id}/archive (FA-21): `fixed_assets.asset.manage` at its company. */
class ItemCategoryActionRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_item_category';
    }

    protected function viewPermission(): ?string
    {
        return null;
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }

    public function rules(): array
    {
        return [];
    }
}
