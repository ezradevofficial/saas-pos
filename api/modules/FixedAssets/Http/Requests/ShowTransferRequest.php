<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/transfers/{id} (FA-14): `fixed_assets.asset.view` at the asset's location. */
class ShowTransferRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_transfer';
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
