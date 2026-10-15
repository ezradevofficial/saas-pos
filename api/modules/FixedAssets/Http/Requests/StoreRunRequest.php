<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fixed-assets/runs (FA-12): `fixed_assets.depreciation.post` at the company; `{company_id, period: Y-m}`. */
class StoreRunRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.depreciation.post';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'period' => ['required', 'date_format:Y-m'],
        ];
    }
}
