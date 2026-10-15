<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/assets/{id}/readings (FA-18): `fixed_assets.asset.manage`; `{read_on, reading, note?}`. */
class StoreReadingRequest extends AssetRequest
{
    public function rules(): array
    {
        return [
            'read_on' => ['required', 'date_format:Y-m-d'],
            'reading' => ['required', 'decimal:0,6', 'min:0', 'max:99999999999999'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }
}
