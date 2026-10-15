<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fixed-assets/item-categories (FA-21): `fixed_assets.asset.manage` at the company; `{company_id, item_id, category_id}` flags a non-stock item as an asset. */
class StoreItemCategoryRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.asset.manage';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')],
            'category_id' => ['required', 'uuid', Rule::exists('fa_categories', 'id')],
        ];
    }
}
