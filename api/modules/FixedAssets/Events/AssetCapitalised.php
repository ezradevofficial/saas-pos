<?php

namespace Modules\FixedAssets\Events;

/** FA-05, FA-22: an asset was activated (capitalised): Dr its cost, Cr purchases (from Procurement) or the purchases clearing account. Source type `fixed_assets.capitalisation`. */
final class AssetCapitalised extends FixedAssetPosting
{
    public const SOURCE = 'fixed_assets.capitalisation';
}
