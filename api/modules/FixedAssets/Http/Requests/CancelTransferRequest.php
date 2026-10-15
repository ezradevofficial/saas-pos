<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/transfers/{id}/cancel (FA-14): `fixed_assets.transfer.request` at the asset's location; `{reason}`. */
class CancelTransferRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_transfer';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.asset.view';
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.transfer.request';
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
