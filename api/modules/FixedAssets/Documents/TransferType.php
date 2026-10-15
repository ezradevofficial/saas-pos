<?php

namespace Modules\FixedAssets\Documents;

use Illuminate\Database\Eloquent\Model;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Transfer;

/** WF-01, FA-14: `fixed_assets.transfer`, approved at the place the asset is moving from. */
class TransferType extends AssetDocumentType
{
    public const KEY = 'fixed_assets.transfer';

    public function key(): string
    {
        return self::KEY;
    }

    protected function model(): string
    {
        return Transfer::class;
    }

    protected function kind(): string
    {
        return 'transfer';
    }

    public function documentLink(string $documentId): string
    {
        return '/fixed-assets/transfers/'.rawurlencode($documentId);
    }

    protected function locationOf(Model $document, Asset $asset): string
    {
        return (string) $document->from_location_id;
    }

    protected function branchOf(Model $document, Asset $asset): string
    {
        return (string) $document->from_branch_id;
    }
}
