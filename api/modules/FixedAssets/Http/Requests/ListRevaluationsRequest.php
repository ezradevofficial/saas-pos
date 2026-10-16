<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/revaluations (FA-15, FA-16, FA-26): `fixed_assets.asset.view`; revaluations and impairments of the assets the reader sees. */
class ListRevaluationsRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    protected function statuses(): array
    {
        return [];
    }
}
