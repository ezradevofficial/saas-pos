<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/companies/{company}/settings (FA-01): `fixed_assets.asset.view` at the company. */
class ShowSettingsRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.asset.view';
    }

    public function rules(): array
    {
        return [];
    }
}
