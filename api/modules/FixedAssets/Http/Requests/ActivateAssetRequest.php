<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/assets/{id}/activate (FA-05): `fixed_assets.asset.manage`; `{in_service_on?, residual_minor?}` the last changes before capitalising. */
class ActivateAssetRequest extends AssetRequest
{
    public function rules(): array
    {
        return [
            'in_service_on' => ['sometimes', 'date_format:Y-m-d'],
            'residual_minor' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }
}
