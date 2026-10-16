<?php

namespace Modules\FixedAssets\Events;

/** FA-13, FA-22: a run's journal reversed (the depreciation rule with its sides swapped). Source type `fixed_assets.depreciation`. */
final class DepreciationReversed extends FixedAssetPosting
{
    public const SOURCE = 'fixed_assets.depreciation';
}
