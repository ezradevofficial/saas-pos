<?php

namespace Modules\FixedAssets\Events;

/** FA-12, FA-17, FA-22: depreciation of a run (one per branch and cost centre) or of a disposal: Dr depreciation, Cr accumulated depreciation, per category. Source type `fixed_assets.depreciation`. */
final class DepreciationPosted extends FixedAssetPosting
{
    public const SOURCE = 'fixed_assets.depreciation';
}
