<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/disposals/{id}/post (FA-17): `fixed_assets.disposal.request` at the asset's location; posts an approved disposal that could not post when approved. */
class PostDisposalRequest extends RecordRequest
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
        return [];
    }
}
