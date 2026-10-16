<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/runs (FA-12): `fixed_assets.depreciation.view` at the companies where it is held. */
class ListRunsRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.depreciation.view';
    }

    protected function statuses(): array
    {
        return ['posted', 'reversed'];
    }
}
