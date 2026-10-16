<?php

namespace Modules\FixedAssets\Events;

/** FA-17, FA-22: an asset sold, scrapped or written off: cost and accumulated depreciation out, proceeds, gain or loss, book value, the surplus released. Source type `fixed_assets.disposal`. */
final class AssetDisposed extends FixedAssetPosting
{
    public const SOURCE = 'fixed_assets.disposal';
}
