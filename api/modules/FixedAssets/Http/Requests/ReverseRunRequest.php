<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/runs/{id}/reverse (FA-13): `fixed_assets.depreciation.post` at its company; `{reason}`. */
class ReverseRunRequest extends RecordRequest
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
        return 'fixed_assets.depreciation.post';
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
