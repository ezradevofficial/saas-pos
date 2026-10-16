<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/assets/{id} and its schedule, history, readings and documents (FA-26): `fixed_assets.asset.view` at the asset's location. */
class ShowAssetRequest extends AssetRequest
{
    public function rules(): array
    {
        return [];
    }
}
