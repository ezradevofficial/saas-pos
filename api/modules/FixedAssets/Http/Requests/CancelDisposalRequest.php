<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/disposals/{id}/cancel (FA-17): `fixed_assets.disposal.request` at the asset's location; `{reason}`. */
class CancelDisposalRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_disposal';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.asset.view';
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.disposal.request';
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
