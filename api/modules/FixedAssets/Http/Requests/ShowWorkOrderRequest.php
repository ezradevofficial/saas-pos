<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/work-orders/{id} (FA-19): `fixed_assets.maintenance.view` at the asset's location. */
class ShowWorkOrderRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_work_order';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.maintenance.view';
    }

    public function rules(): array
    {
        return [];
    }
}
