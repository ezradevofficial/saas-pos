<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/runs/{id} (FA-12): `fixed_assets.depreciation.view` at its company. */
class ShowRunRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_run';
    }

    protected function viewPermission(): ?string
    {
        return null;
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.depreciation.view';
    }

    public function rules(): array
    {
        return [];
    }
}
