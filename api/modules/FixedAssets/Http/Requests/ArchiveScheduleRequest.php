<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/schedules/{id}/archive (FA-19): `fixed_assets.maintenance.manage` at the asset's location. */
class ArchiveScheduleRequest extends RecordRequest
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
        return [];
    }
}
