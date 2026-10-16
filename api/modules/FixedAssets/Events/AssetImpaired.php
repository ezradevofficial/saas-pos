<?php

namespace Modules\FixedAssets\Events;

/** FA-16, FA-22: an asset impaired: the loss and the surplus used against accumulated depreciation. Source type `fixed_assets.impairment`. */
final class AssetImpaired extends FixedAssetPosting
{
    public const SOURCE = 'fixed_assets.impairment';
}
