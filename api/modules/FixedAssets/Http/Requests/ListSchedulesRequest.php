<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/schedules (FA-19): `fixed_assets.maintenance.view`; schedules of the assets the reader may maintain (`?status=active|archived`). */
class ListSchedulesRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.maintenance.view';
    }

    protected function statuses(): array
    {
        return ['active', 'archived'];
    }
}
