<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/assets/{id}/cancel (FA-04): `fixed_assets.asset.manage`; `{reason}`; a draft only. */
class CancelAssetRequest extends AssetRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }
}
