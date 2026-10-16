<?php

namespace Modules\FixedAssets\Http\Requests;

/** PATCH fixed-assets/work-orders/{id} (FA-19): `fixed_assets.maintenance.manage` at the asset's location; the work order's fields, each optional. */
class UpdateWorkOrderRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_work_order';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.maintenance.view';
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.maintenance.manage';
    }

    public function rules(): array
    {
        return ScheduleRules::order(required: false);
    }
}
