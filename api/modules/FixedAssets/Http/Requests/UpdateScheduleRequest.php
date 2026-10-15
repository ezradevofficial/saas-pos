<?php

namespace Modules\FixedAssets\Http\Requests;

/** PATCH fixed-assets/schedules/{id} (FA-19): `fixed_assets.maintenance.manage` at the asset's location; the schedule's fields, each optional. */
class UpdateScheduleRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_schedule';
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
        return ScheduleRules::rules(required: false);
    }
}
