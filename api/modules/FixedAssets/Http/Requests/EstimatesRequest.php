<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Depreciation\AssetBook;

/** POST fixed-assets/assets/{id}/estimates (FA-11): `fixed_assets.asset.manage`; `{method?, useful_life_months?, residual_minor?, declining_rate?, total_units?, reason}`. */
class EstimatesRequest extends AssetRequest
{
    public function rules(): array
    {
        return [
            'method' => ['sometimes', 'string', Rule::in(AssetBook::METHODS)],
            'useful_life_months' => ['sometimes', 'nullable', 'integer', 'between:1,1200'],
            'residual_minor' => ['sometimes', 'integer', 'min:0'],
            'declining_rate' => ['sometimes', 'nullable', 'decimal:0,4', 'gt:0', 'max:100'],
            'total_units' => ['sometimes', 'nullable', 'decimal:0,6', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }
}
