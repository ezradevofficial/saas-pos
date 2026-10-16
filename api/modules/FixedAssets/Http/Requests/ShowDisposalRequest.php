<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/disposals/{id} (FA-17): `fixed_assets.asset.view` at the asset's location. */
class ShowDisposalRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_disposal';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.asset.view';
    }

    public function rules(): array
    {
        return [];
    }
}
