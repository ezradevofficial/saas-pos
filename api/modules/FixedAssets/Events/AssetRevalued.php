<?php

namespace Modules\FixedAssets\Events;

/** FA-15, FA-22: an asset revalued (elimination method): accumulated depreciation and impairment against cost, the surplus, gains and losses. Source type `fixed_assets.revaluation`. */
final class AssetRevalued extends FixedAssetPosting
{
    public const SOURCE = 'fixed_assets.revaluation';
}
