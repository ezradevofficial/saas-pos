<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/item-categories (FA-21): `fixed_assets.asset.view`; items flagged as assets in the companies the reader works in. */
class ListItemCategoriesRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    protected function statuses(): array
    {
        return [];
    }
}
