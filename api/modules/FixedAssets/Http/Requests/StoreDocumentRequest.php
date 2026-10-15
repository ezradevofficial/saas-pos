<?php

namespace Modules\FixedAssets\Http\Requests;

use Modules\FixedAssets\Assets\Documents;

/** POST fixed-assets/assets/{id}/documents (FA-06): `fixed_assets.asset.manage`; a multipart `file` of the core types, up to 10 MB. */
class StoreDocumentRequest extends AssetRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.Documents::MAX_KB]];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }
}
