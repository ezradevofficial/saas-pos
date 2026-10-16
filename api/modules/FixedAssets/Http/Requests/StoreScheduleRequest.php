<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/assets/{id}/schedules (FA-19): `fixed_assets.maintenance.manage`; `{name, basis: time|usage, interval_days?, interval_units?, warning_days?, last_done_on?, last_done_reading?}`. */
class StoreScheduleRequest extends AssetRequest
{
    public function rules(): array
    {
        return ScheduleRules::rules(required: true);
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.maintenance.manage';
    }
}
