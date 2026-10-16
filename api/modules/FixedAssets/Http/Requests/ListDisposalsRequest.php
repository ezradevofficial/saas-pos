<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/disposals (FA-17, FA-26): `fixed_assets.asset.view`; disposals of the assets the reader sees. */
class ListDisposalsRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    protected function statuses(): array
    {
        return ['pending', 'approved', 'posted', 'rejected', 'cancelled'];
    }
}
