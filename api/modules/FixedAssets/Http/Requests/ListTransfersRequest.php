<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/transfers (FA-14, FA-26): `fixed_assets.asset.view`; transfers of the assets the reader sees. */
class ListTransfersRequest extends ListRequest
{
    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    protected function statuses(): array
    {
        return ['pending', 'applied', 'rejected', 'cancelled'];
    }
}
