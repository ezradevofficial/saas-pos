<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/assets/{id}/work-orders (FA-19): `fixed_assets.maintenance.manage`; `{title, description?, schedule_id?, due_on?, assigned_to?, vendor_id?, status?, completed_on?, reading?, cost_minor?, currency?}`. */
class StoreWorkOrderRequest extends AssetRequest
{
    public function rules(): array
    {
        return ScheduleRules::order(required: true);
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.maintenance.manage';
    }
}
