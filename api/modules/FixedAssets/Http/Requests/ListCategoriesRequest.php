<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/categories (FA-02): `fixed_assets.asset.view`; categories of the companies the reader works in (`?status=active|archived|all`). */
class ListCategoriesRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    protected function statuses(): array
    {
        return ['active', 'archived'];
    }
}
