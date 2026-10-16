<?php

namespace Modules\FixedAssets\Http\Requests;

use Modules\FixedAssets\Maintenance\Maintenance;

/** GET fixed-assets/work-orders (FA-19): `fixed_assets.maintenance.view`; work orders of the assets the reader may maintain. */
class ListWorkOrdersRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.maintenance.view';
    }

    protected function statuses(): array
    {
        return Maintenance::STATUSES;
    }
}
