<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fixed-assets/categories (FA-02): `fixed_assets.category.manage` at the company; `{company_id, code, name, method, useful_life_months?, residual_percent?, declining_rate?, tax_class?}`. */
class StoreCategoryRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.category.manage';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ...CategoryRules::rules(required: true),
        ];
    }
}
