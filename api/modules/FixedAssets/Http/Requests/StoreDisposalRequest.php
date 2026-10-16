<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Documents\Disposals;

/** POST fixed-assets/assets/{id}/disposals (FA-17): `fixed_assets.disposal.request` at the asset's location; `{kind: sale|scrap|write_off, disposal_date, proceeds_minor?, currency?, party_id?, reason}`. */
class StoreDisposalRequest extends AssetRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(Disposals::KINDS)],
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            'proceeds_minor' => ['sometimes', 'integer', 'min:0', 'max:9000000000000000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.disposal.request';
    }
}
