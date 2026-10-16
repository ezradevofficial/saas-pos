<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Documents\Revaluations;

/** POST fixed-assets/assets/{id}/revaluations (FA-15, FA-16): `fixed_assets.revaluation.post`; `{kind: revaluation|impairment, revalued_on, value_minor (fair value or recoverable amount, base), reason}`. */
class StoreRevaluationRequest extends AssetRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(Revaluations::KINDS)],
            'revalued_on' => ['required', 'date_format:Y-m-d'],
            'value_minor' => ['required', 'integer', 'min:0', 'max:9000000000000000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.revaluation.post';
    }
}
