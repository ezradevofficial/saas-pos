<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fixed-assets/runs/preview (FA-12): `fixed_assets.depreciation.post` at the company; `?company=&period=Y-m`. Saves nothing. */
class PreviewRunRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.depreciation.post';
    }

    public function rules(): array
    {
        return [
            'company' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'period' => ['required', 'date_format:Y-m'],
        ];
    }
}
